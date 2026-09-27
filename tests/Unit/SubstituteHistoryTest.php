<?php
/**
 * "WHO CANCELLED THIS COVER?"
 *
 * An administrator standing in front of a teacher who turned up to a lesson that
 * was not theirs has to be able to answer that. Substitute_service has always
 * written audit entries — create, update, status change, delete — into
 * `academicAuditLog` through the platform's Audit_log_service.
 *
 * Two things were wrong with that.
 *
 * First, the service validates entityType against an allowlist and REJECTS
 * SILENTLY: log() returns false, nobody checks the return, and the entry simply
 * does not exist. `substitutePolicy` was never on that list, so every change to
 * a school's cover rules went unrecorded.
 *
 * Second, `academicAuditLog` had no reader anywhere in the repository. The
 * entries were being written where nothing could ever show them, which answers
 * the question no better than not writing them at all.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Substitute_service.php';
require_once __DIR__ . '/SubstituteConcurrentAssignmentTest.php';   // fakes

final class SubstituteHistoryTest extends TestCase
{
    private const SCHOOL = 'SCH_SUB01';
    private const SESS   = '2026-27';
    private const DATE   = '2026-09-07';          // a Monday
    private const DAY    = 'Monday';

    private FakeAuditLog $audit;

    protected function setUp(): void
    {
        $this->audit = new FakeAuditLog();
    }

    private function svc(FakeSubstituteFirestore $fb): Substitute_service
    {
        return (new Substitute_service())->init(
            $fb, null, self::SCHOOL, self::SESS, 'STA_ADMIN', 'R Rao', $this->audit);
    }

    private function timetable(FakeSubstituteFirestore $fb): void
    {
        $fb->docs['timetables'][self::SCHOOL . '_tt1'] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS, 'day' => self::DAY,
            'className' => 'Class 9th', 'section' => 'Section A',
            'periods' => [
                ['periodNumber' => 1, 'subject' => 'Maths', 'teacherId' => 'STA_ABSENT'],
                ['periodNumber' => 3, 'subject' => 'Maths', 'teacherId' => 'STA_ABSENT'],
            ],
        ];
        $fb->ensureStaff(self::SCHOOL, ['STA_ABSENT', 'STA_COVER']);
    }

    private function save(FakeSubstituteFirestore $fb, int $period = 3, string $id = ''): array
    {
        return $this->svc($fb)->saveSubstitute($id, self::DATE, 'STA_ABSENT', 'A Absent', [[
            'periodNumber' => $period, 'subject' => 'Maths',
            'className' => 'Class 9th', 'section' => 'Section A',
            'substituteTeacherId' => 'STA_COVER', 'substituteTeacherName' => 'Cover Teacher',
        ]], 'sick');
    }

    private function actions(): array
    {
        return array_column($this->audit->entries, 'action');
    }

    // -- every change to a cover is recorded ------------------------------

    public function test_arranging_changing_and_removing_cover_are_all_recorded(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);

        $r = $this->save($fb);
        $this->save($fb, 1, $r['id']);
        $this->svc($fb)->updateSubstituteStatus($r['id'], 'cancelled');
        $this->svc($fb)->deleteSubstitute($r['id']);

        $this->assertSame(['create', 'update', 'status_change', 'delete'], $this->actions());
        $this->assertSame([], $this->audit->rejected,
            'an entry the platform refuses is an entry that does not exist');
    }

    /**
     * THE DEFECT. Audit_log_service checks entityType against an allowlist and
     * returns false when it does not match. A school changing its cover limits
     * left no trace of who did it, or of what the limits used to be.
     */
    public function test_changing_the_cover_policy_is_recorded(): void
    {
        $fb = new FakeSubstituteFirestore();

        $this->svc($fb)->savePolicy(['maxCoverPeriodsPerDay' => 2]);

        $this->assertSame([], $this->audit->rejected,
            'the platform refused the entry, so nobody can say who changed the rules');
        $this->assertSame(['update'], $this->actions());
        $this->assertSame('substitutePolicy', $this->audit->entries[0]['entityType']);
    }

    /** The trace has to carry the numbers, not merely the fact of a change. */
    public function test_the_policy_trace_carries_the_old_and_new_limits(): void
    {
        $fb = new FakeSubstituteFirestore();
        $svc = $this->svc($fb);

        $svc->savePolicy(['maxCoverPeriodsPerDay' => 2]);
        $svc->savePolicy(['maxCoverPeriodsPerDay' => 5]);

        $second = $this->audit->entries[1];
        $this->assertSame(2, $second['before']['maxCoverPeriodsPerDay']);
        $this->assertSame(5, $second['after']['maxCoverPeriodsPerDay']);
    }

    /**
     * The mechanism that would have caught the defect above. log() returns
     * false when it refuses an entry; if nobody looks at that return and
     * nobody logs it, a whole class of changes goes unrecorded in silence —
     * which is exactly how the policy entries were lost.
     */
    public function test_a_refused_audit_entry_is_recorded_in_the_log(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);

        // An entity type the platform does not accept.
        $this->audit->entityTypes = ['curriculum'];

        $GLOBALS['__test_log_capture'] = [];
        $this->save($fb);
        $lines = $GLOBALS['__test_log_capture'];
        unset($GLOBALS['__test_log_capture']);

        $found = false;
        foreach ($lines as $l) {
            if (strpos($l['msg'], 'audit entry REFUSED') !== false) { $found = true; }
        }
        $this->assertTrue($found,
            'a change that nothing recorded must at least be shouted about');
    }

    // -- reading the history back -----------------------------------------

    /** One cover's history, newest first, in words an administrator can use. */
    public function test_the_history_of_one_cover_can_be_read_back(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->seedLog($fb, 'SUB_1', [
            ['create',        '2026-09-01T09:00:00+05:30', 'A Admin', null,
             ['status' => 'assigned', 'date' => self::DATE, 'assignmentsCount' => 2]],
            ['status_change', '2026-09-02T11:00:00+05:30', 'B Admin',
             ['status' => 'assigned'], ['status' => 'cancelled']],
        ]);

        $h = $this->svc($fb)->coverHistory('SUB_1');

        $this->assertCount(2, $h['entries']);
        $this->assertSame('B Admin', $h['entries'][0]['by'], 'newest first');
        $this->assertMatchesRegularExpression(
            '~from assigned to cancelled~i', $h['entries'][0]['what'],
            '"Marked cancelled" answers nothing; what it replaced is the fact '
            . 'somebody needs');
        $this->assertMatchesRegularExpression('~arranged~i', $h['entries'][1]['what']);
    }

    /** History survives the cover: a removed cover is exactly what gets asked about. */
    public function test_a_removed_cover_still_has_a_history(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->seedLog($fb, 'SUB_GONE', [
            ['create', '2026-09-01T09:00:00+05:30', 'A Admin', null,
             ['status' => 'assigned', 'date' => self::DATE, 'assignmentsCount' => 1]],
            ['delete', '2026-09-03T09:00:00+05:30', 'C Admin',
             ['status' => 'assigned', 'date' => self::DATE], null],
        ]);

        $h = $this->svc($fb)->coverHistory('SUB_GONE');

        $this->assertCount(2, $h['entries']);
        $this->assertMatchesRegularExpression('~removed~i', $h['entries'][0]['what']);
    }

    /**
     * The audit log holds every Academic entity. An entry about something
     * else that happens to share an id is not this cover's history.
     */
    public function test_history_of_a_different_kind_of_thing_is_not_shown(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->seedLog($fb, 'SUB_1', [
            ['create', '2026-09-01T09:00:00+05:30', 'A Admin', null,
             ['status' => 'assigned', 'date' => self::DATE, 'assignmentsCount' => 1]],
        ]);
        $fb->docs['academicAuditLog'][self::SCHOOL . '_other'] = [
            'logId' => 'other', 'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'ts' => '2026-09-05T09:00:00+05:30', 'action' => 'update',
            'entityType' => 'timetable', 'entityId' => 'SUB_1',
            'actor' => ['uid' => 'X', 'name' => 'Timetable Admin', 'role' => 'Admin'],
            'before' => null, 'after' => null,
        ];

        $h = $this->svc($fb)->coverHistory('SUB_1');

        $this->assertCount(1, $h['entries']);
        $this->assertSame('A Admin', $h['entries'][0]['by']);
    }

    public function test_another_session_s_history_is_not_shown(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->seedLog($fb, 'SUB_1', [
            ['create', '2026-09-01T09:00:00+05:30', 'A Admin', null,
             ['status' => 'assigned', 'date' => self::DATE, 'assignmentsCount' => 1]],
        ]);
        $this->seedLog($fb, 'SUB_1', [
            ['create', '2025-09-01T09:00:00+05:30', 'Old Admin', null,
             ['status' => 'assigned', 'date' => '2025-09-07', 'assignmentsCount' => 1]],
        ], '2025-26', 'old');

        $h = $this->svc($fb)->coverHistory('SUB_1');

        $this->assertCount(1, $h['entries']);
        $this->assertSame('A Admin', $h['entries'][0]['by']);
    }

    /** No history is an ordinary answer; an unreadable history is not. */
    public function test_a_cover_with_no_recorded_history_says_so_plainly(): void
    {
        $fb = new FakeSubstituteFirestore();

        $h = $this->svc($fb)->coverHistory('SUB_1');

        $this->assertSame([], $h['entries']);
    }

    public function test_an_unreadable_history_is_not_an_empty_history(): void
    {
        $fb = new FakeSubstituteFirestore();
        $fb->failCollections = ['academicAuditLog'];

        try {
            $this->svc($fb)->coverHistory('SUB_1');
            $this->fail('a failed read must not be presented as "nothing ever happened"');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~try again~i', $e->getMessage());
        }
    }

    /** An entry with no recorded actor does not invent one, or print an id. */
    public function test_an_entry_with_no_actor_name_is_not_shown_as_an_id(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->seedLog($fb, 'SUB_1', [
            ['create', '2026-09-01T09:00:00+05:30', '', null,
             ['status' => 'assigned', 'date' => self::DATE, 'assignmentsCount' => 1]],
        ]);

        $h = $this->svc($fb)->coverHistory('SUB_1');

        $this->assertNotSame('STA_ADMIN', $h['entries'][0]['by']);
        $this->assertNotSame('', $h['entries'][0]['by']);
    }

    // -- helper -----------------------------------------------------------

    /** @param array<int,array> $rows [action, ts, actorName, before, after] */
    private function seedLog(FakeSubstituteFirestore $fb, string $entityId,
                             array $rows, string $session = self::SESS,
                             string $prefix = 'log'): void
    {
        foreach ($rows as $i => [$action, $ts, $actor, $before, $after]) {
            $fb->docs['academicAuditLog'][self::SCHOOL . '_' . $prefix . $i . $entityId] = [
                'logId' => $prefix . $i, 'schoolId' => self::SCHOOL, 'session' => $session,
                'ts' => $ts, 'action' => $action,
                'entityType' => 'substitute', 'entityId' => $entityId,
                'actor' => ['uid' => 'STA_ADMIN', 'name' => $actor, 'role' => 'Admin'],
                'before' => $before, 'after' => $after,
            ];
        }
    }
}
