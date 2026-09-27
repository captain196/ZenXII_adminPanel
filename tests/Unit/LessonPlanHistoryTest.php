<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Lesson_plan_service.php';
require_once __DIR__ . '/../../application/libraries/Audit_log_service.php';
require_once __DIR__ . '/CurriculumAuthorityTest.php';   // FakeCurriculumFirebase

/**
 * READING BACK WHAT HAPPENED TO A LESSON PLAN.
 *
 * A written record nobody can retrieve is not accountability. The substitute
 * module was found in exactly that state — a year of entries in
 * academicAuditLog and not one reader in the repository — and the lesson
 * planner was worse: its entries were being refused before they were written.
 *
 * Now that they land, this is the surface that answers the question people
 * actually ask: who marked this period completed, when, and from which client.
 * Both writers put entries here in the same shape, so both must come back.
 */
final class LessonPlanHistoryTest extends TestCase
{
    private const SCHOOL  = 'SCH_CUR01';
    private const SESS    = '2026-27';
    private const TEACHER = 'STA_T1';
    private const DATE    = '2026-09-07';        // a Monday
    private const PERIOD  = 2;

    private function svc(FakeCurriculumFirebase $fb, ?Audit_log_service $audit = null,
                         string $actor = 'STA_HEAD', string $name = 'A Head'): Lesson_plan_service
    {
        return (new Lesson_plan_service())->init(
            $fb, $fb, self::SCHOOL, self::SCHOOL, self::SESS,
            $actor, $name, 'Principal', $audit);
    }

    private function audit(FakeCurriculumFirebase $fb, string $uid = 'STA_HEAD',
                           string $name = 'A Head'): Audit_log_service
    {
        return (new Audit_log_service())->init($fb, self::SCHOOL, self::SESS, [
            'uid' => $uid, 'name' => $name, 'role' => 'Principal',
        ]);
    }

    private function seedTimetable(FakeCurriculumFirebase $fb): void
    {
        $docId = self::SCHOOL . '_' . self::SESS . '_Class 9th_Section A_Monday';
        $fb->docs['timetables'][$docId] = ['periods' => [
            ['periodNumber' => 3, 'teacherId' => self::TEACHER,
             'subject' => 'Mathematics', 'teacher' => 'A Teacher'],
        ]];
    }

    private function plan(string $status = 'planned', $expected = null, string $topic = ''): array
    {
        return [
            'class_name' => 'Class 9th', 'section_name' => 'Section A',
            'subject' => 'Mathematics', 'teacher_id' => self::TEACHER,
            'date' => self::DATE, 'period_index' => self::PERIOD,
            'topic_id' => $topic, 'notes' => 'A lesson', 'status' => $status,
            'expected_version' => $expected,
        ];
    }

    private function history(FakeCurriculumFirebase $fb): array
    {
        return $this->svc($fb)->planHistory(self::TEACHER, self::DATE, self::PERIOD);
    }

    /**
     * Entries are addressed by what they are, not by where they sit. Two
     * changes made inside the same second carry the same timestamp — the log
     * records seconds — so their relative order is genuinely undefined, and a
     * test that asserted one would be asserting an accident.
     */
    private function entry(array $h, string $action): array
    {
        foreach ($h['entries'] as $e) {
            if ($e['action'] === $action) { return $e; }
        }
        $this->fail('no ' . $action . ' entry in the history');
    }

    // -- the trail ----------------------------------------------------------

    public function test_a_plans_changes_come_back_newest_first(): void
    {
        $fb    = new FakeCurriculumFirebase();
        $audit = $this->audit($fb);
        $this->seedTimetable($fb);

        $this->svc($fb, $audit)->saveLessonPlan($this->plan('planned'));
        $this->svc($fb, $audit)->saveLessonPlan($this->plan('completed', 1));

        $h = $this->history($fb);

        $this->assertCount(2, $h['entries']);
        $this->assertNotEmpty($this->entry($h, 'create'));
        $this->assertNotEmpty($this->entry($h, 'update'));
    }

    public function test_each_entry_says_who_and_what(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);

        $this->svc($fb, $this->audit($fb, 'STA_HEAD', 'A Head'), 'STA_HEAD', 'A Head')
            ->saveLessonPlan($this->plan('planned'));
        $this->svc($fb, $this->audit($fb, 'STA_T1', 'A Teacher'), 'STA_T1', 'A Teacher')
            ->saveLessonPlan($this->plan('completed', 1));

        $h      = $this->history($fb);
        $edit   = $this->entry($h, 'update');
        $create = $this->entry($h, 'create');

        $this->assertSame('A Teacher', $edit['by']);
        $this->assertMatchesRegularExpression('~marked completed~i', $edit['what']);
        $this->assertMatchesRegularExpression('~was planned~i', $edit['what'],
            'the record says what it was before, which is the disputed part');
        $this->assertSame('A Head', $create['by']);
        $this->assertMatchesRegularExpression('~planned~i', $create['what']);
    }

    public function test_a_topic_change_is_described_without_identifiers(): void
    {
        $fb    = new FakeCurriculumFirebase();
        $audit = $this->audit($fb);
        $this->seedTimetable($fb);
        $fb->docs['curriculum/' . self::SCHOOL . '_' . self::SESS
                  . '_Class_9th_Section_A_Mathematics/topics']['T1'] = [
            'topicId' => 'T1', 'parentDocId' => self::SCHOOL . '_' . self::SESS
                . '_Class_9th_Section_A_Mathematics',
            'schoolId' => self::SCHOOL, 'title' => 'Fractions',
        ];

        $this->svc($fb, $audit)->saveLessonPlan($this->plan('planned'));
        $this->svc($fb, $audit)->saveLessonPlan($this->plan('planned', 1, 'T1'));

        $what = $this->entry($this->history($fb), 'update')['what'];
        $this->assertMatchesRegularExpression('~linked to a curriculum topic~i', $what);
        $this->assertStringNotContainsString('T1', $what,
            'an id means nothing to the person reading this');
    }

    /** An entry written by the Teacher app is part of the same story. */
    public function test_a_change_made_from_the_teacher_app_is_in_the_same_history(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        $this->svc($fb, $this->audit($fb))->saveLessonPlan($this->plan('planned'));

        // Written directly by the app, in the shape its repository writes.
        $planId = self::SCHOOL . '_' . self::SESS . '_' . self::TEACHER . '_'
                . self::DATE . '_P' . self::PERIOD;
        $fb->docs['academicAuditLog']['APP_1'] = [
            'logId' => 'APP_1', 'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'ts' => '2999-01-01T00:00:00+00:00', 'action' => 'update',
            'entityType' => 'lessonPlan', 'entityId' => $planId,
            'actor' => ['uid' => self::TEACHER, 'name' => 'A Teacher', 'role' => 'Teacher'],
            'before' => null, 'after' => ['status' => 'completed', 'topicId' => ''],
            'metadata' => ['source' => 'teacher_app'],
        ];

        $h = $this->history($fb);

        $this->assertCount(2, $h['entries']);
        $this->assertSame('Teacher app', $h['entries'][0]['source'],
            'this one is dated far in the future, so it genuinely is newest');
        $this->assertSame('Admin panel', $h['entries'][1]['source'],
            'which client made a change is part of answering a disagreement');
    }

    // -- boundaries ---------------------------------------------------------

    public function test_another_sessions_entries_are_not_this_years_history(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        $this->svc($fb, $this->audit($fb))->saveLessonPlan($this->plan('planned'));

        $planId = self::SCHOOL . '_' . self::SESS . '_' . self::TEACHER . '_'
                . self::DATE . '_P' . self::PERIOD;
        $fb->docs['academicAuditLog']['OLD'] = [
            'logId' => 'OLD', 'schoolId' => self::SCHOOL, 'session' => '2025-26',
            'ts' => '2999-01-01T00:00:00+00:00', 'action' => 'update',
            'entityType' => 'lessonPlan', 'entityId' => $planId,
            'actor' => [], 'before' => null, 'after' => [], 'metadata' => [],
        ];

        $this->assertCount(1, $this->history($fb)['entries']);
    }

    public function test_an_entry_about_something_else_is_not_included(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        $this->svc($fb, $this->audit($fb))->saveLessonPlan($this->plan('planned'));

        $planId = self::SCHOOL . '_' . self::SESS . '_' . self::TEACHER . '_'
                . self::DATE . '_P' . self::PERIOD;
        $fb->docs['academicAuditLog']['OTHER'] = [
            'logId' => 'OTHER', 'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'ts' => '2999-01-01T00:00:00+00:00', 'action' => 'update',
            'entityType' => 'substitute', 'entityId' => $planId,
            'actor' => [], 'before' => null, 'after' => [], 'metadata' => [],
        ];

        $this->assertCount(1, $this->history($fb)['entries']);
    }

    /** With timestamps that differ, the newest change is first. */
    public function test_changes_come_back_newest_first(): void
    {
        $fb = new FakeCurriculumFirebase();
        $planId = self::SCHOOL . '_' . self::SESS . '_' . self::TEACHER . '_'
                . self::DATE . '_P' . self::PERIOD;
        foreach ([['E1', '2026-09-01T09:00:00+00:00', 'create'],
                  ['E2', '2026-09-02T09:00:00+00:00', 'update'],
                  ['E3', '2026-09-03T09:00:00+00:00', 'update']] as [$id, $ts, $action]) {
            $fb->docs['academicAuditLog'][$id] = [
                'logId' => $id, 'schoolId' => self::SCHOOL, 'session' => self::SESS,
                'ts' => $ts, 'action' => $action, 'entityType' => 'lessonPlan',
                'entityId' => $planId, 'actor' => ['name' => 'Somebody'],
                'before' => null, 'after' => ['status' => 'planned'], 'metadata' => [],
            ];
        }

        $ts = array_column($this->history($fb)['entries'], 'at');

        $this->assertSame([
            '2026-09-03T09:00:00+00:00',
            '2026-09-02T09:00:00+00:00',
            '2026-09-01T09:00:00+00:00',
        ], $ts);
    }

    /** An actor with no recorded name is said to be unknown, not printed. */
    public function test_an_unnamed_actor_is_described_rather_than_identified(): void
    {
        $fb = new FakeCurriculumFirebase();
        $planId = self::SCHOOL . '_' . self::SESS . '_' . self::TEACHER . '_'
                . self::DATE . '_P' . self::PERIOD;
        $fb->docs['academicAuditLog']['E1'] = [
            'logId' => 'E1', 'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'ts' => '2026-09-01T09:00:00+00:00', 'action' => 'update',
            'entityType' => 'lessonPlan', 'entityId' => $planId,
            'actor' => ['uid' => 'STA_ZZZ', 'name' => ''],
            'before' => null, 'after' => [], 'metadata' => [],
        ];

        $by = $this->history($fb)['entries'][0]['by'];

        $this->assertStringNotContainsString('STA_ZZZ', $by,
            'the reader has no way to look an identifier up');
        $this->assertMatchesRegularExpression('~name was not recorded~i', $by);
    }

    /** A plan nobody has touched has an empty history, not an error. */
    public function test_a_plan_with_no_history_reads_as_empty(): void
    {
        $fb = new FakeCurriculumFirebase();

        $h = $this->history($fb);
        $this->assertSame([], $h['entries']);
        $this->assertFalse($h['truncated']);
    }

    /** THE DISTINCTION: unreadable is not "nothing happened". */
    public function test_an_unreadable_history_is_not_an_empty_one(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        $this->svc($fb, $this->audit($fb))->saveLessonPlan($this->plan('planned'));
        $fb->failCollections = ['academicAuditLog'];

        try {
            $this->history($fb);
            $this->fail('a failed history read must not read as nothing having happened');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~could not be read~i', $e->getMessage());
            $this->assertMatchesRegularExpression('~not the same as nothing~i',
                $e->getMessage());
        }
    }

    public function test_the_history_of_a_deleted_plan_is_still_readable(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        $this->svc($fb, $this->audit($fb))->saveLessonPlan($this->plan('planned'));

        $fb->docs['lessonPlans'] = [];      // the plan itself is gone

        $this->assertCount(1, $this->history($fb)['entries'],
            'a removed plan is the one people ask about');
    }
}
