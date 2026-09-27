<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Lesson_plan_service.php';
require_once __DIR__ . '/../../application/libraries/Subject_assignment_service.php';
require_once __DIR__ . '/CurriculumAuthorityTest.php';   // FakeCurriculumFirebase

/**
 * WHO MAY PLAN A PERIOD, AND WHAT HAPPENS WHEN WE CANNOT TELL.
 *
 * A teacher may write plans for the periods they teach; the office may write
 * for anybody. The gate reads the subject assignments to decide, and a read can
 * fail — at which point there are two honest answers and one dishonest one.
 * The dishonest one was in place: a failed lookup returned false, and false
 * here means "Not authorized: lesson plans can only be saved for your assigned
 * teacher×subject×class." That sentence is about the teacher. It was being
 * produced by an outage.
 *
 * Denying is right when we know. Denying because we could not look is an
 * accusation, and it arrives while somebody is trying to do their job.
 */
final class LessonPlanAuthorityTest extends TestCase
{
    private const SCHOOL  = 'SCH_CUR01';
    private const SESS    = '2026-27';
    private const TEACHER = 'STA_T1';
    private const DATE    = '2026-09-07';        // a Monday
    private const PERIOD  = 2;

    /** A service acting as $role/$actor, with the assignment lookup available. */
    /**
     * The REAL assignment service, wired to the same store — injected
     * rather than looked up, because the gate used to reach it through
     * the CI singleton and outside a request that hands back whatever is
     * registered. Every previous attempt to exercise this gate proved
     * something about the locator instead.
     */
    private function svc(FakeCurriculumFirebase $fb, string $role, string $actor): Lesson_plan_service
    {
        $sas = (new Subject_assignment_service())
            ->init($fb, $fb, self::SCHOOL, self::SCHOOL, self::SESS);

        return (new Lesson_plan_service())->init(
            $fb, $fb, self::SCHOOL, self::SCHOOL, self::SESS,
            $actor, 'The Actor', $role, null)
            ->withAssignmentService($sas);
    }

    private function seedTimetable(FakeCurriculumFirebase $fb): void
    {
        $docId = self::SCHOOL . '_' . self::SESS . '_Class 9th_Section A_Monday';
        $fb->docs['timetables'][$docId] = ['periods' => [
            ['periodNumber' => 3, 'teacherId' => self::TEACHER,
             'subject' => 'Mathematics', 'teacher' => 'A Teacher'],
        ]];
    }

    /** The assignment the gate looks for, in the shape the service reads. */
    private function seedAssignment(FakeCurriculumFirebase $fb, string $teacherId,
                                    string $subject = 'Mathematics'): void
    {
        $id = self::SCHOOL . '_' . self::SESS . '_Class_9th_Section_A_' . $subject;
        $fb->docs['subjectAssignments'][$id] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'className' => 'Class 9th', 'section' => 'Section A',
            'subjectName' => $subject, 'subjectCode' => strtoupper(substr($subject, 0, 4)),
            'teacherId' => $teacherId, 'archived' => false,
        ];
    }

    private function plan(string $teacherId = self::TEACHER): array
    {
        return [
            'class_name' => 'Class 9th', 'section_name' => 'Section A',
            'subject' => 'Mathematics', 'teacher_id' => $teacherId,
            'date' => self::DATE, 'period_index' => self::PERIOD,
            'topic_id' => '', 'notes' => 'A lesson', 'status' => 'planned',
            'expected_version' => null,
        ];
    }

    // -- the office ---------------------------------------------------------

    public function test_an_admin_may_plan_for_any_teacher(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);

        $this->svc($fb, 'Principal', 'STA_HEAD')->saveLessonPlan($this->plan());

        $this->assertNotEmpty($fb->docs['lessonPlans'] ?? []);
    }

    // -- a teacher ----------------------------------------------------------

    public function test_a_teacher_may_plan_their_own_period(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        $this->seedAssignment($fb, self::TEACHER);

        $this->svc($fb, 'Teacher', self::TEACHER)->saveLessonPlan($this->plan());

        $this->assertNotEmpty($fb->docs['lessonPlans'] ?? []);
    }

    public function test_a_teacher_may_not_plan_in_another_teachers_name(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        $this->seedAssignment($fb, self::TEACHER);

        try {
            $this->svc($fb, 'Teacher', 'STA_OTHER')->saveLessonPlan($this->plan());
            $this->fail('a teacher may only plan their own periods');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~not authorized~i', $e->getMessage());
        }

        $this->assertSame([], $fb->docs['lessonPlans'] ?? []);
    }

    public function test_a_teacher_with_no_assignment_here_is_refused(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        // Assigned to Science, planning Mathematics.
        $this->seedAssignment($fb, self::TEACHER, 'Science');

        try {
            $this->svc($fb, 'Teacher', self::TEACHER)->saveLessonPlan($this->plan());
            $this->fail('an assignment elsewhere is not an assignment here');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~not authorized~i', $e->getMessage());
        }
    }

    // -- the lookup that could not run --------------------------------------

    /**
     * THE DEFECT. The assignment read fails and the teacher is told they are
     * not authorized for their own class.
     */
    public function test_an_unreadable_assignment_is_not_a_refusal(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        $this->seedAssignment($fb, self::TEACHER);
        $fb->failCollections = ['subjectAssignments'];

        try {
            $this->svc($fb, 'Teacher', self::TEACHER)->saveLessonPlan($this->plan());
            $this->fail('a plan must not be saved on an authority nobody could check');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~could not be read|try again~i',
                $e->getMessage());
            $this->assertDoesNotMatchRegularExpression('~not authorized~i', $e->getMessage(),
                'the teacher is told what happened, not accused of overreach');
        }

        $this->assertSame([], $fb->docs['lessonPlans'] ?? [],
            'and nothing was written on an authority nobody could establish');
    }

    /**
     * The gate asks TWO questions of the assignment store: is this teacher
     * assigned to this section, and are they assigned to the class as a whole.
     * Failing the whole collection makes both throw, so a guard on one covers
     * for a missing guard on the other — a mutation battery found exactly that
     * hiding here. They are failed one at a time below.
     */
    public function test_an_unreadable_section_assignment_alone_is_reported(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        $this->seedAssignment($fb, self::TEACHER);
        $fb->failConditions = [['section', 'Section A']];   // the per-section query only

        try {
            $this->svc($fb, 'Teacher', self::TEACHER)->saveLessonPlan($this->plan());
            $this->fail('the section assignment could not be read and nobody was told');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~could not be read~i', $e->getMessage());
        }

        $fb->failConditions = [];
        $this->assertSame([], $fb->docs['lessonPlans'] ?? []);
    }

    public function test_an_unreadable_class_wide_assignment_alone_is_reported(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        $this->seedAssignment($fb, self::TEACHER);
        // The class-wide lookup is the one with no section filter.
        $fb->failWhenUnconstrained = ['section'];

        try {
            $this->svc($fb, 'Teacher', self::TEACHER)->saveLessonPlan($this->plan());
            $this->fail('the class-wide assignment could not be read and nobody was told');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~could not be read~i', $e->getMessage());
        }

        $fb->failWhenUnconstrained = [];
        $this->assertSame([], $fb->docs['lessonPlans'] ?? []);
    }

    /** An admin does not need the assignment read at all, so an outage there
     *  must not block the office. */
    public function test_an_admin_is_unaffected_by_an_unreadable_assignment(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        $fb->failCollections = ['subjectAssignments'];

        $this->svc($fb, 'Principal', 'STA_HEAD')->saveLessonPlan($this->plan());

        $this->assertNotEmpty($fb->docs['lessonPlans'] ?? []);
    }
}
