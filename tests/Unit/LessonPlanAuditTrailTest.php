<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Lesson_plan_service.php';
require_once __DIR__ . '/../../application/libraries/Audit_log_service.php';
require_once __DIR__ . '/CurriculumAuthorityTest.php';   // FakeCurriculumFirebase

/**
 * WHO CHANGED A LESSON PLAN, AND WHETHER ANYONE CAN FIND OUT.
 *
 * Lesson_plan_service calls the platform audit service on every save and
 * delete, and discards the return value like every other caller. log()
 * validates the entity type against an allowlist and, when it is not on it,
 * returns false and writes nothing.
 *
 * 'lessonPlan' was never added. So every lesson plan ever created or edited
 * through the admin panel produced a call that was refused in silence, while
 * the Teacher app — which writes its own audit document directly — recorded
 * its own. The two clients disagreed about whether the work was being logged
 * at all, and the admin one was wrong.
 *
 * The Substitute module found this exact failure on substitutePolicy. It is
 * worth running rather than reading, because reading is what missed it twice.
 */
final class LessonPlanAuditTrailTest extends TestCase
{
    private const SCHOOL  = 'SCH_CUR01';
    private const SESS    = '2026-27';
    private const TEACHER = 'STA_T1';
    private const DATE    = '2026-09-07';        // a Monday
    private const PERIOD  = 2;

    private function audit(FakeCurriculumFirebase $fb): Audit_log_service
    {
        return (new Audit_log_service())->init($fb, self::SCHOOL, self::SESS, [
            'uid' => 'STA_HEAD', 'name' => 'A Head', 'role' => 'Principal',
        ]);
    }

    private function svc(FakeCurriculumFirebase $fb, ?Audit_log_service $audit): Lesson_plan_service
    {
        return (new Lesson_plan_service())->init(
            $fb, $fb, self::SCHOOL, self::SCHOOL, self::SESS,
            'STA_HEAD', 'A Head', 'Principal', $audit);
    }

    private function seedTimetable(FakeCurriculumFirebase $fb): void
    {
        $docId = self::SCHOOL . '_' . self::SESS . '_Class 9th_Section A_Monday';
        $fb->docs['timetables'][$docId] = ['periods' => [
            ['periodNumber' => 3, 'teacherId' => self::TEACHER,
             'subject' => 'Mathematics', 'teacher' => 'A Teacher'],
        ]];
    }

    private function plan(string $status = 'planned', $expected = null): array
    {
        return [
            'class_name' => 'Class 9th', 'section_name' => 'Section A',
            'subject' => 'Mathematics', 'teacher_id' => self::TEACHER,
            'date' => self::DATE, 'period_index' => self::PERIOD,
            'topic_id' => '', 'notes' => 'A lesson', 'status' => $status,
            'expected_version' => $expected,
        ];
    }

    private function entries(FakeCurriculumFirebase $fb): array
    {
        return array_values($fb->docs['academicAuditLog'] ?? []);
    }

    // -- the entries that were being thrown away ----------------------------

    /** THE DEFECT: a create that produced no record at all. */
    public function test_creating_a_lesson_plan_is_recorded(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);

        $this->svc($fb, $this->audit($fb))->saveLessonPlan($this->plan());

        $e = $this->entries($fb);
        $this->assertCount(1, $e,
            'the entry was accepted by the audit service, not silently refused');
        $this->assertSame('create', $e[0]['action']);
        $this->assertSame('lessonPlan', $e[0]['entityType']);
        $this->assertSame('STA_HEAD', $e[0]['actor']['uid'],
            'the person who made the change, not the teacher it was made for');
    }

    public function test_editing_a_plan_records_what_it_was(): void
    {
        $fb    = new FakeCurriculumFirebase();
        $audit = $this->audit($fb);
        $this->seedTimetable($fb);

        $this->svc($fb, $audit)->saveLessonPlan($this->plan('planned'));
        $this->svc($fb, $audit)->saveLessonPlan($this->plan('completed', 1));

        $e = $this->entries($fb);
        $this->assertCount(2, $e);
        $this->assertSame('update', $e[1]['action']);
        $this->assertSame('planned',   $e[1]['before']['status']);
        $this->assertSame('completed', $e[1]['after']['status']);
        $this->assertSame(1, $e[1]['before']['version']);
        $this->assertSame(2, $e[1]['after']['version']);
    }

    public function test_the_record_says_which_class_period_and_day(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);

        $this->svc($fb, $this->audit($fb))->saveLessonPlan($this->plan());

        $m = $this->entries($fb)[0]['metadata'];
        $this->assertSame('Class 9th/Section A', $m['classSection']);
        $this->assertSame('Mathematics', $m['subject']);
        $this->assertSame(self::DATE, $m['date']);
        $this->assertSame(self::PERIOD, $m['periodIndex']);
    }

    public function test_every_entry_carries_the_school_and_session(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        $this->svc($fb, $this->audit($fb))->saveLessonPlan($this->plan());

        foreach ($this->entries($fb) as $row) {
            $this->assertSame(self::SCHOOL, $row['schoolId']);
            $this->assertSame(self::SESS,   $row['session']);
        }
    }

    // -- the audit must never cost the work ---------------------------------

    public function test_an_audit_failure_does_not_cost_the_plan(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        $audit = new class extends Audit_log_service {
            public function log(string $a, string $b, string $c, ?array $d = null,
                                ?array $e = null, array $f = [], ?array $g = null): bool
            {
                throw new \RuntimeException('audit sink unavailable');
            }
        };
        $audit->init($fb, self::SCHOOL, self::SESS, []);

        $this->svc($fb, $audit)->saveLessonPlan($this->plan());

        $this->assertNotEmpty($fb->docs['lessonPlans'] ?? [],
            'a teacher does not lose their plan because the log was unwell');
    }

    /**
     * And the refusal that started all this must be impossible to reintroduce
     * quietly: an entity type this service uses has to be one the audit
     * service accepts.
     */
    public function test_the_entity_type_this_service_uses_is_on_the_allowlist(): void
    {
        $this->assertContains('lessonPlan', Audit_log_service::ENTITY_TYPES,
            'log() returns false for an unlisted entityType and no caller looks');
    }
}
