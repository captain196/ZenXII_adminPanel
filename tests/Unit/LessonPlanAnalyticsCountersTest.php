<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Lesson_plan_service.php';
require_once __DIR__ . '/CurriculumAuthorityTest.php';   // FakeCurriculumFirebase

/**
 * THE TWO SUMMARY DOCUMENTS A LESSON PLAN SAVE MAINTAINS.
 *
 * subjectPlanProgress counts a subject's plans by status; dailyTeacherMonitoring
 * counts a teacher's day. Both are kept by read-modify-write: read the totals,
 * adjust one or two of them, write the lot back merged. The update is
 * deliberately best-effort — an analytics failure must never cost a teacher
 * their plan — and a nightly rebuild script exists to correct drift.
 *
 * But the read was silent, and the code that follows treats a null as "no
 * counters yet" and starts from zero. So a momentary read failure did not skip
 * the update; it wrote a full set of counters reading 1, replacing a term's
 * accumulated totals with a number that looks deliberate. Drift of one is what
 * the nightly rebuild is for. Resetting the document is not drift.
 *
 * Skipping the write is strictly better than performing it: it leaves the
 * counter one behind for a day, which is exactly the case the rebuild exists to
 * absorb.
 */
final class LessonPlanAnalyticsCountersTest extends TestCase
{
    private const SCHOOL  = 'SCH_CUR01';
    private const SESS    = '2026-27';
    private const TEACHER = 'STA_T1';
    private const DATE    = '2026-09-07';        // a Monday
    private const PERIOD  = 2;

    private function svc(FakeCurriculumFirebase $fb): Lesson_plan_service
    {
        return (new Lesson_plan_service())->init(
            $fb, $fb, self::SCHOOL, self::SCHOOL, self::SESS,
            'STA_HEAD', 'A Head', 'Principal', null);
    }

    private function progId(): string
    {
        return self::SCHOOL . '_' . self::SESS . '_Class_9th_Section_A_Mathematics';
    }

    private function monId(): string
    {
        return self::SCHOOL . '_' . self::SESS . '_' . self::DATE . '_' . self::TEACHER;
    }

    private function seedTimetable(FakeCurriculumFirebase $fb): void
    {
        $docId = self::SCHOOL . '_' . self::SESS . '_Class 9th_Section A_Monday';
        $fb->docs['timetables'][$docId] = ['periods' => [
            ['periodNumber' => 3, 'teacherId' => self::TEACHER,
             'subject' => 'Mathematics', 'teacher' => 'A Teacher'],
        ]];
    }

    /** A term already counted: 40 plans for the subject, 30 for the day. */
    private function seedCounters(FakeCurriculumFirebase $fb): void
    {
        $fb->docs['subjectPlanProgress'][$this->progId()] = [
            'totalPlans' => 40, 'plannedCount' => 10, 'completedCount' => 30,
            'skippedCount' => 0, 'rescheduledCount' => 0, 'percentComplete' => 75.0,
        ];
        $fb->docs['dailyTeacherMonitoring'][$this->monId()] = [
            'plansSaved' => 30, 'plannedCount' => 8, 'completedCount' => 22,
            'skippedCount' => 0, 'rescheduledCount' => 0,
        ];
    }

    private function plan(string $status = 'planned'): array
    {
        return [
            'class_name' => 'Class 9th', 'section_name' => 'Section A',
            'subject' => 'Mathematics', 'teacher_id' => self::TEACHER,
            'date' => self::DATE, 'period_index' => self::PERIOD,
            'topic_id' => '', 'notes' => 'A lesson', 'status' => $status,
            'expected_version' => null,
        ];
    }

    // -- the arithmetic when everything is readable -------------------------

    public function test_a_new_plan_adds_one_to_each_summary(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        $this->seedCounters($fb);

        $this->svc($fb)->saveLessonPlan($this->plan('planned'));

        $prog = $fb->docs['subjectPlanProgress'][$this->progId()];
        $this->assertSame(41, $prog['totalPlans']);
        $this->assertSame(11, $prog['plannedCount']);
        $this->assertSame(30, $prog['completedCount'], 'untouched statuses stay put');

        $mon = $fb->docs['dailyTeacherMonitoring'][$this->monId()];
        $this->assertSame(31, $mon['plansSaved']);
    }

    public function test_the_first_plan_of_a_subject_starts_the_counters_at_one(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);

        $this->svc($fb)->saveLessonPlan($this->plan('planned'));

        $prog = $fb->docs['subjectPlanProgress'][$this->progId()];
        $this->assertSame(1, $prog['totalPlans']);
        $this->assertSame(1, $prog['plannedCount']);
    }

    // -- the read that failed -----------------------------------------------

    /**
     * THE DEFECT. A term's totals replaced by a set reading 1, from a read
     * failure that nothing reported.
     */
    public function test_an_unreadable_subject_summary_is_left_alone(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        $this->seedCounters($fb);
        $fb->failCollections = ['subjectPlanProgress'];

        $this->svc($fb)->saveLessonPlan($this->plan('planned'));

        $fb->failCollections = [];
        $prog = $fb->docs['subjectPlanProgress'][$this->progId()];
        $this->assertSame(40, $prog['totalPlans'],
            'a term of counting is not replaced by a number derived from nothing');
        $this->assertSame(30, $prog['completedCount']);
    }

    public function test_an_unreadable_daily_summary_is_left_alone(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        $this->seedCounters($fb);
        $fb->failCollections = ['dailyTeacherMonitoring'];

        $this->svc($fb)->saveLessonPlan($this->plan('planned'));

        $fb->failCollections = [];
        $this->assertSame(30,
            $fb->docs['dailyTeacherMonitoring'][$this->monId()]['plansSaved']);
    }

    /** One summary failing must not stop the other being maintained. */
    public function test_one_unreadable_summary_does_not_hold_up_the_other(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        $this->seedCounters($fb);
        $fb->failCollections = ['subjectPlanProgress'];

        $this->svc($fb)->saveLessonPlan($this->plan('planned'));

        $fb->failCollections = [];
        $this->assertSame(31,
            $fb->docs['dailyTeacherMonitoring'][$this->monId()]['plansSaved'],
            'the daily count was readable and was kept up to date');
    }

    /** And the teacher's plan is saved either way — analytics never blocks. */
    public function test_the_plan_itself_is_saved_even_when_both_summaries_fail(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        $this->seedCounters($fb);
        $fb->failCollections = ['subjectPlanProgress', 'dailyTeacherMonitoring'];

        $this->svc($fb)->saveLessonPlan($this->plan('planned'));

        $fb->failCollections = [];
        $planId = self::SCHOOL . '_' . self::SESS . '_' . self::TEACHER . '_'
                . self::DATE . '_P' . self::PERIOD;
        $this->assertArrayHasKey($planId, $fb->docs['lessonPlans'],
            'a summary nobody can read is not a reason to lose a lesson plan');
    }
}
