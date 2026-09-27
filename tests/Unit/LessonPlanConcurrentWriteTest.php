<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Lesson_plan_service.php';
require_once __DIR__ . '/CurriculumAuthorityTest.php';   // FakeCurriculumFirebase

/**
 * TWO PEOPLE SAVING THE SAME PERIOD.
 *
 * A lesson plan is one document per (teacher, date, period), so the head of
 * department marking a period completed and the teacher rewriting its notes
 * are two writes to the same document. The save takes an expected_version and
 * compares it against the stored one — in PHP, some way before the write.
 *
 * The Teacher app does the same job inside a Firestore transaction, so ITS
 * writes are safe against this. The admin panel's are not, and they are racing
 * each other and the app across the same document: both read version 3, both
 * pass the check, both write version 4, and the second silently replaces work
 * the first person was just told had been saved.
 *
 * A version compared in PHP is a claim about a moment that has passed. The
 * condition has to travel with the write.
 */
final class LessonPlanConcurrentWriteTest extends TestCase
{
    private const SCHOOL  = 'SCH_CUR01';
    private const SESS    = '2026-27';
    private const TEACHER = 'STA_T1';
    private const DATE    = '2026-09-07';        // a Monday
    private const PERIOD  = 2;

    private function svc(FakeCurriculumFirebase $fb, string $actor = 'STA_HEAD'): Lesson_plan_service
    {
        return (new Lesson_plan_service())->init(
            $fb, $fb, self::SCHOOL, self::SCHOOL, self::SESS,
            $actor, 'The Actor', 'Principal', null);
    }

    private function planDocId(): string
    {
        return self::SCHOOL . '_' . self::SESS . '_' . self::TEACHER . '_'
             . self::DATE . '_P' . self::PERIOD;
    }

    private function seedTimetable(FakeCurriculumFirebase $fb): void
    {
        $docId = self::SCHOOL . '_' . self::SESS . '_Class 9th_Section A_Monday';
        $fb->docs['timetables'][$docId] = ['periods' => [
            ['periodNumber' => 3, 'teacherId' => self::TEACHER,
             'subject' => 'Mathematics', 'teacher' => 'A Teacher'],
        ]];
    }

    private function plan(string $notes, string $status = 'planned', $expected = null): array
    {
        return [
            'class_name' => 'Class 9th', 'section_name' => 'Section A',
            'subject' => 'Mathematics', 'teacher_id' => self::TEACHER,
            'date' => self::DATE, 'period_index' => self::PERIOD,
            'topic_id' => '', 'notes' => $notes, 'status' => $status,
            'expected_version' => $expected,
        ];
    }

    private function stored(FakeCurriculumFirebase $fb): array
    {
        return $fb->docs['lessonPlans'][$this->planDocId()];
    }

    // -- the race -----------------------------------------------------------

    /**
     * THE DEFECT. The teacher's save has read version 1 and decided what to
     * write. The head of department's save lands in that window.
     */
    public function test_a_save_decided_before_anothers_landed_is_refused(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        $this->svc($fb)->saveLessonPlan($this->plan('First draft'));

        $landed = false;
        $fb->beforeCommit = function () use ($fb, &$landed) {
            $this->svc($fb, 'STA_HEAD')
                ->saveLessonPlan($this->plan('Head of department review', 'completed', 1));
            $landed = true;
        };

        $refused = null;
        try {
            $this->svc($fb, self::TEACHER)
                ->saveLessonPlan($this->plan('Teacher rewrite', 'planned', 1));
        } catch (Service_exception $e) { $refused = $e->getMessage(); }

        $this->assertTrue($landed,
            'the window never opened — this test would prove nothing about the guard');
        $this->assertNotNull($refused, 'the racing save was allowed to land');
        $this->assertMatchesRegularExpression('~another user|reload~i', $refused);

        $stored = $this->stored($fb);
        $this->assertSame('Head of department review', $stored['notes'],
            'the write that actually landed is the one that is there');
        $this->assertSame('completed', $stored['status']);
        $this->assertSame(2, $stored['version'], 'the version moved exactly once');
    }

    /**
     * Two people creating the same period at once — neither has a version to
     * compare, so nothing but the write itself can settle it.
     */
    public function test_two_simultaneous_creations_do_not_overwrite_each_other(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);

        $landed = false;
        $fb->beforeCommit = function () use ($fb, &$landed) {
            $this->svc($fb)->saveLessonPlan($this->plan('Written first'));
            $landed = true;
        };

        $refused = null;
        try {
            $this->svc($fb)->saveLessonPlan($this->plan('Written second'));
        } catch (Service_exception $e) { $refused = $e->getMessage(); }

        $this->assertTrue($landed, 'the window never opened');
        $this->assertNotNull($refused, 'the second creation flattened the first');
        $this->assertMatchesRegularExpression('~another user|reload~i', $refused);

        $this->assertSame('Written first', $this->stored($fb)['notes']);
        $this->assertSame(1, $this->stored($fb)['version']);
    }

    /**
     * A save that carries no expected_version at all must still not silently
     * flatten a plan that arrived in the meantime. The version check cannot
     * help here — it is skipped — so the guard is the only thing left.
     */
    public function test_a_save_without_a_version_still_cannot_flatten_a_new_plan(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);

        $landed = false;
        $fb->beforeCommit = function () use ($fb, &$landed) {
            $this->svc($fb)->saveLessonPlan($this->plan('Somebody else got here first'));
            $landed = true;
        };

        $refused = null;
        try {
            $this->svc($fb)->saveLessonPlan($this->plan('Blind write', 'planned', null));
        } catch (Service_exception $e) { $refused = $e->getMessage(); }

        $this->assertTrue($landed, 'the window never opened');
        $this->assertNotNull($refused, 'a blind save overwrote a plan it never saw');
        $this->assertMatchesRegularExpression('~another user|reload~i', $refused);

        $this->assertSame('Somebody else got here first', $this->stored($fb)['notes']);
    }

    // -- what must still work -----------------------------------------------

    public function test_consecutive_saves_from_a_reloaded_plan_all_land(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);

        $this->svc($fb)->saveLessonPlan($this->plan('v1'));
        for ($v = 1; $v <= 4; $v++) {
            $this->svc($fb)->saveLessonPlan($this->plan('v' . ($v + 1), 'planned', $v));
        }

        $this->assertSame(5, $this->stored($fb)['version']);
        $this->assertSame('v5', $this->stored($fb)['notes']);
    }

    public function test_a_stale_version_is_still_refused_before_any_write(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        $this->svc($fb)->saveLessonPlan($this->plan('original'));
        $this->svc($fb)->saveLessonPlan($this->plan('second', 'planned', 1));

        try {
            $this->svc($fb)->saveLessonPlan($this->plan('built on v1', 'planned', 1));
            $this->fail('expected a conflict');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~modified by another user~i', $e->getMessage());
        }

        $this->assertSame('second', $this->stored($fb)['notes']);
    }

    /** The creation record must survive an edit, guard or no guard. */
    public function test_an_edit_keeps_the_original_creation_record(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        $this->svc($fb, 'STA_FIRST')->saveLessonPlan($this->plan('original'));
        $createdAt = $this->stored($fb)['createdAt'];

        $this->svc($fb, 'STA_SECOND')->saveLessonPlan($this->plan('edited', 'planned', 1));

        $this->assertSame($createdAt, $this->stored($fb)['createdAt']);
        $this->assertSame('STA_FIRST',  $this->stored($fb)['createdByUid']);
        $this->assertSame('STA_SECOND', $this->stored($fb)['updatedByUid']);
    }
}
