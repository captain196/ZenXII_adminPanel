<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
require_once __DIR__ . '/../../application/libraries/Staff_employment_history_service.php';

/**
 * Employment History Phase 1 (Position/Department/EmploymentType only).
 *
 * Behavioral tests against a lightweight Firestore_service fake — unlike the
 * source-regression convention used for Staff.php itself (Firestore_service
 * has no real behavioral mock), Staff_employment_history_service's only
 * external dependency is the small commitBatch()/schoolWhere() surface, so a
 * fake that implements exactly those two methods lets these tests assert
 * real op-building/retry behavior rather than string-matching source.
 */
class FakeEmploymentHistoryFs
{
    /** @var array<int, array> Every commitBatch() call's ops, in order. */
    public array $batches = [];
    /** @var array<int,bool> Per-call outcome; last value repeats once exhausted. */
    public array $commitOutcomes = [true];
    private int $commitCalls = 0;

    /** @var array Rows schoolWhere() should return, keyed by "attribute" for convenience in tests. */
    public array $openRowsByAttribute = [];

    public function commitBatch(array $ops): bool
    {
        $this->batches[] = $ops;
        $i = $this->commitCalls;
        $this->commitCalls++;
        return $this->commitOutcomes[$i] ?? end($this->commitOutcomes);
    }

    public function schoolWhere(string $collection, array $conditions = [], ?string $orderBy = null, string $direction = 'ASC', ?int $limit = null): array
    {
        $attr = null;
        foreach ($conditions as $c) {
            if (($c[0] ?? '') === 'attribute') { $attr = $c[2]; break; }
        }
        return $this->openRowsByAttribute[$attr] ?? [];
    }

    public function commitCallCount(): int
    {
        return $this->commitCalls;
    }
}

class StaffEmploymentHistoryPhase1Test extends TestCase
{
    private function svc(FakeEmploymentHistoryFs $fake, string $schoolId = 'SCH_TEST01', string $actor = 'ADM0001'): \Staff_employment_history_service
    {
        $svc = new \Staff_employment_history_service();
        return $svc->init($fake, $schoolId, $actor);
    }

    private function opsByCollection(array $ops, string $collection): array
    {
        return array_values(array_filter($ops, fn($o) => ($o['collection'] ?? '') === $collection));
    }

    // ── JOINED event creation ───────────────────────────────────────────

    public function test_joined_creates_one_event_and_three_timeline_entries_in_one_batch(): void
    {
        $fake = new FakeEmploymentHistoryFs();
        $res  = $this->svc($fake)->recordJoined('STA0099', [
            'position'       => 'Teacher',
            'department'     => 'Mathematics',
            'employmentType' => 'Full-Time',
        ]);

        $this->assertTrue($res['ok']);
        $this->assertCount(1, $fake->batches, 'JOINED must be exactly one commitBatch call.');
        $ops = $fake->batches[0];
        // Phase 2C added reportingManagerId and Phase 3B.1 added
        // employmentStatus (Staff_employment_history_service::ATTRIBUTES is
        // now 5 entries) — recordJoined() always snapshots every vocabulary
        // attribute, so a call passing only the original 3 keys now still
        // produces 1 event + 5 timeline entries (the unset ones default to
        // '' / 'Active' per JOINED's own logic), not 1+3. This is the
        // expected, authorized effect of extending the vocabulary each time,
        // not a regression.
        $this->assertCount(6, $ops, 'Expected 1 event + 5 timeline entries (Phase 1\'s 3 + Phase 2C\'s reportingManagerId + Phase 3B.1\'s employmentStatus).');

        $eventOps = $this->opsByCollection($ops, \Staff_employment_history_service::EVENT_COL);
        $this->assertCount(1, $eventOps, 'Exactly ONE JOINED event, not three.');
        $this->assertSame('JOINED', $eventOps[0]['data']['eventType']);
        $this->assertSame(['exists' => false], $eventOps[0]['precondition'], 'JOINED event must be create-if-not-exists.');
    }

    public function test_joined_day_one_snapshot_captures_all_three_attributes(): void
    {
        $fake = new FakeEmploymentHistoryFs();
        $this->svc($fake)->recordJoined('STA0099', [
            'position'       => 'Teacher',
            'department'     => 'Mathematics',
            'employmentType' => 'Full-Time',
        ]);
        $eventOps = $this->opsByCollection($fake->batches[0], \Staff_employment_history_service::EVENT_COL);
        $meta = $eventOps[0]['data']['metadata'];
        $this->assertSame('Teacher', $meta['position']);
        $this->assertSame('Mathematics', $meta['department']);
        $this->assertSame('Full-Time', $meta['employmentType']);
    }

    public function test_joined_timeline_entries_all_reference_the_same_event(): void
    {
        $fake = new FakeEmploymentHistoryFs();
        $this->svc($fake)->recordJoined('STA0099', ['position' => 'Teacher', 'department' => 'Mathematics', 'employmentType' => 'Full-Time']);
        $eventOps    = $this->opsByCollection($fake->batches[0], \Staff_employment_history_service::EVENT_COL);
        $timelineOps = $this->opsByCollection($fake->batches[0], \Staff_employment_history_service::TIMELINE_COL);
        $eventId = $eventOps[0]['docId'];

        // 5, not 3, since Phase 2C added reportingManagerId and Phase 3B.1
        // added employmentStatus (see the sibling count-6-ops assertion above).
        $this->assertCount(5, $timelineOps);
        $attrs = [];
        foreach ($timelineOps as $op) {
            $this->assertSame($eventId, $op['data']['eventRef'], 'Every timeline entry must point back to the ONE JOINED event.');
            $this->assertNull($op['data']['effectiveTo'], 'A freshly-opened entry must have effectiveTo == null.');
            $this->assertSame(['exists' => false], $op['precondition']);
            $attrs[] = $op['data']['attribute'];
        }
        sort($attrs);
        $this->assertSame(['department', 'employmentStatus', 'employmentType', 'position', 'reportingManagerId'], $attrs);
    }

    public function test_joined_docids_and_data_are_tenant_scoped(): void
    {
        $fake = new FakeEmploymentHistoryFs();
        $this->svc($fake, 'SCH_ABC123')->recordJoined('STA0007', ['position' => 'Clerk', 'department' => '', 'employmentType' => '']);
        foreach ($fake->batches[0] as $op) {
            $this->assertStringStartsWith('SCH_ABC123_', $op['docId']);
            $this->assertSame('SCH_ABC123', $op['data']['schoolId']);
        }
    }

    public function test_joined_blank_attribute_still_gets_an_explicit_entry(): void
    {
        $fake = new FakeEmploymentHistoryFs();
        $this->svc($fake)->recordJoined('STA0099', ['position' => 'Clerk', 'department' => '', 'employmentType' => '']);
        $timelineOps = $this->opsByCollection($fake->batches[0], \Staff_employment_history_service::TIMELINE_COL);
        $byAttr = [];
        foreach ($timelineOps as $op) $byAttr[$op['data']['attribute']] = $op['data']['value'];
        $this->assertSame('', $byAttr['department'], 'A blank day-one value is still an explicit entry, not a skipped one.');
    }

    public function test_joined_batch_failure_is_reported_not_thrown(): void
    {
        $fake = new FakeEmploymentHistoryFs();
        $fake->commitOutcomes = [false];
        $res = $this->svc($fake)->recordJoined('STA0099', ['position' => 'Teacher', 'department' => 'Math', 'employmentType' => 'Full-Time']);
        $this->assertFalse($res['ok']);
        $this->assertSame('commit_failed', $res['reason']);
    }

    // ── recordChanges — single/multi attribute, atomicity ───────────────

    public function test_single_attribute_change_closes_old_and_opens_new_in_one_batch(): void
    {
        $fake = new FakeEmploymentHistoryFs();
        $fake->openRowsByAttribute['department'] = [
            ['id' => 'SCH_TEST01_STA0099_department_20260101000000_abc123', 'data' => ['value' => 'Mathematics', '__updateTime' => '2026-08-01T00:00:00Z']],
        ];
        $res = $this->svc($fake)->recordChanges('STA0099', ['department' => 'Academics']);

        $this->assertTrue($res['ok']);
        $this->assertCount(1, $fake->batches, 'One attribute change = one commitBatch call.');
        $ops = $fake->batches[0];
        $this->assertCount(3, $ops, 'Expected: event create + close-old + open-new.');

        $eventOps = $this->opsByCollection($ops, \Staff_employment_history_service::EVENT_COL);
        $this->assertSame('DEPARTMENT_CHANGED', $eventOps[0]['data']['eventType']);
        $this->assertSame('Mathematics', $eventOps[0]['data']['fromValue']);
        $this->assertSame('Academics', $eventOps[0]['data']['toValue']);

        $timelineOps = $this->opsByCollection($ops, \Staff_employment_history_service::TIMELINE_COL);
        $this->assertCount(2, $timelineOps);
        $closeOp = null; $openOp = null;
        foreach ($timelineOps as $op) {
            if ($op['docId'] === 'SCH_TEST01_STA0099_department_20260101000000_abc123') $closeOp = $op;
            else $openOp = $op;
        }
        $this->assertNotNull($closeOp, 'Must close the pre-existing open entry by its exact doc id.');
        $this->assertArrayHasKey('updateTime', $closeOp['precondition'], 'Closing an entry must be CAS-protected.');
        $this->assertSame('2026-08-01T00:00:00Z', $closeOp['precondition']['updateTime']);
        $this->assertNotNull($openOp);
        $this->assertSame('Academics', $openOp['data']['value']);
        $this->assertNull($openOp['data']['effectiveTo']);
    }

    public function test_multiple_attribute_changes_commit_together_as_one_batch(): void
    {
        $fake = new FakeEmploymentHistoryFs();
        $res = $this->svc($fake)->recordChanges('STA0099', [
            'department'     => 'Academics',
            'employmentType' => 'Part-Time',
        ]);
        $this->assertTrue($res['ok']);
        $this->assertCount(1, $fake->batches, 'Both attribute changes must land in ONE commitBatch, not two.');
        $eventOps = $this->opsByCollection($fake->batches[0], \Staff_employment_history_service::EVENT_COL);
        $this->assertCount(2, $eventOps);
        $types = array_map(fn($o) => $o['data']['eventType'], $eventOps);
        sort($types);
        $this->assertSame(['DEPARTMENT_CHANGED', 'EMPLOYMENT_TYPE_CHANGED'], $types);
    }

    public function test_position_change_event_type_naming(): void
    {
        $fake = new FakeEmploymentHistoryFs();
        $this->svc($fake)->recordChanges('STA0099', ['position' => 'Senior Teacher']);
        $eventOps = $this->opsByCollection($fake->batches[0], \Staff_employment_history_service::EVENT_COL);
        $this->assertSame('POSITION_CHANGED', $eventOps[0]['data']['eventType']);
    }

    // ── Legacy staff without historical metadata ────────────────────────

    public function test_no_prior_open_entry_creates_new_entry_without_fabricating_a_close(): void
    {
        $fake = new FakeEmploymentHistoryFs();
        // No openRowsByAttribute configured — mirrors a legacy staff record
        // created before this phase, with no JOINED event and no prior entry.
        $res = $this->svc($fake)->recordChanges('STA0001LEGACY', ['department' => 'Science']);

        $this->assertTrue($res['ok']);
        $ops = $fake->batches[0];
        $this->assertCount(2, $ops, 'No prior entry to close — expected only event create + open-new, never a fabricated close op.');
        $eventOps = $this->opsByCollection($ops, \Staff_employment_history_service::EVENT_COL);
        $this->assertSame('', $eventOps[0]['data']['fromValue'], 'Unknown prior value must stay empty, never guessed.');
    }

    // ── Idempotency / duplicate submit ──────────────────────────────────

    public function test_no_op_when_new_value_matches_currently_open_entry(): void
    {
        $fake = new FakeEmploymentHistoryFs();
        $fake->openRowsByAttribute['department'] = [
            ['id' => 'SCH_TEST01_STA0099_department_x', 'data' => ['value' => 'Academics', '__updateTime' => '2026-08-01T00:00:00Z']],
        ];
        $res = $this->svc($fake)->recordChanges('STA0099', ['department' => 'Academics']);
        $this->assertTrue($res['ok']);
        $this->assertSame('noop', $res['reason']);
        $this->assertCount(0, $fake->batches, 'A duplicate submit with an already-applied value must never call commitBatch.');
    }

    public function test_empty_changes_array_is_a_noop(): void
    {
        $fake = new FakeEmploymentHistoryFs();
        $res = $this->svc($fake)->recordChanges('STA0099', []);
        $this->assertTrue($res['ok']);
        $this->assertSame('noop', $res['reason']);
        $this->assertCount(0, $fake->batches);
    }

    public function test_unknown_attribute_is_ignored_not_recorded(): void
    {
        $fake = new FakeEmploymentHistoryFs();
        $res = $this->svc($fake)->recordChanges('STA0099', ['designation' => 'Senior Teacher', 'salaryGrade' => 'G5']);
        $this->assertTrue($res['ok']);
        $this->assertSame('noop', $res['reason']);
        $this->assertCount(0, $fake->batches, 'Only the Phase-1 vocabulary (position/department/employmentType) may ever reach a commitBatch call.');
    }

    // ── CAS conflict / bounded retry ─────────────────────────────────────

    public function test_cas_conflict_retries_bounded_then_succeeds(): void
    {
        $fake = new FakeEmploymentHistoryFs();
        $fake->openRowsByAttribute['department'] = [
            ['id' => 'SCH_TEST01_STA0099_department_x', 'data' => ['value' => 'Mathematics', '__updateTime' => '2026-08-01T00:00:00Z']],
        ];
        $fake->commitOutcomes = [false, false, true]; // conflict, conflict, success
        $res = $this->svc($fake)->recordChanges('STA0099', ['department' => 'Academics']);

        $this->assertTrue($res['ok']);
        $this->assertSame(2, $res['retries'], 'Succeeded on the 3rd attempt (0-indexed retries=2).');
        $this->assertSame(3, $fake->commitCallCount());
    }

    public function test_cas_conflict_exhausts_bounded_retries_and_reports_conflict(): void
    {
        $fake = new FakeEmploymentHistoryFs();
        $fake->openRowsByAttribute['department'] = [
            ['id' => 'SCH_TEST01_STA0099_department_x', 'data' => ['value' => 'Mathematics', '__updateTime' => '2026-08-01T00:00:00Z']],
        ];
        $fake->commitOutcomes = [false]; // every attempt conflicts
        $res = $this->svc($fake)->recordChanges('STA0099', ['department' => 'Academics']);

        $this->assertFalse($res['ok']);
        $this->assertSame('contention_exhausted', $res['reason']);
        // MAX_RETRIES = 2 -> attempts 0,1,2 = 3 total commitBatch calls, then stop.
        $this->assertSame(3, $fake->commitCallCount(), 'Retry loop must be bounded, never unbounded.');
    }

    public function test_conflict_retry_rereads_fresh_state_each_attempt(): void
    {
        // First read sees Mathematics still open; simulate a second admin's
        // write having landed between attempt 1 and attempt 2 by changing what
        // schoolWhere returns. The retry must use the FRESH read, not a cached one.
        $fake = new class extends FakeEmploymentHistoryFs {
            public int $reads = 0;
            public function schoolWhere(string $collection, array $conditions = [], ?string $orderBy = null, string $direction = 'ASC', ?int $limit = null): array
            {
                $this->reads++;
                $updateTime = $this->reads === 1 ? '2026-08-01T00:00:00Z' : '2026-08-01T00:05:00Z';
                return [['id' => 'SCH_TEST01_STA0099_department_x', 'data' => ['value' => 'Mathematics', '__updateTime' => $updateTime]]];
            }
        };
        $fake->commitOutcomes = [false, true];
        $this->svc($fake)->recordChanges('STA0099', ['department' => 'Academics']);

        $this->assertSame(2, $fake->reads, 'Each attempt must re-read the open entry rather than reusing a stale precondition.');
        $secondAttemptCloseOp = null;
        foreach ($fake->batches[1] as $op) {
            if (($op['collection'] ?? '') === \Staff_employment_history_service::TIMELINE_COL
                && $op['docId'] === 'SCH_TEST01_STA0099_department_x') {
                $secondAttemptCloseOp = $op;
            }
        }
        $this->assertNotNull($secondAttemptCloseOp);
        $this->assertSame('2026-08-01T00:05:00Z', $secondAttemptCloseOp['precondition']['updateTime'], 'The retry must use the freshly re-read updateTime, not the first attempt\'s stale one.');
    }

    // ── Backdated effectiveFrom ──────────────────────────────────────────

    public function test_backdated_effective_from_applied_to_both_close_and_open(): void
    {
        $fake = new FakeEmploymentHistoryFs();
        $fake->openRowsByAttribute['department'] = [
            ['id' => 'SCH_TEST01_STA0099_department_x', 'data' => ['value' => 'Mathematics', '__updateTime' => '2026-08-01T00:00:00Z']],
        ];
        $this->svc($fake)->recordChanges('STA0099', ['department' => 'Academics'], '2026-04-01', 'HR correction');
        $ops = $fake->batches[0];
        $eventOps = $this->opsByCollection($ops, \Staff_employment_history_service::EVENT_COL);
        $this->assertSame('2026-04-01', $eventOps[0]['data']['effectiveFrom']);
        $this->assertSame('HR correction', $eventOps[0]['data']['reason']);

        foreach ($this->opsByCollection($ops, \Staff_employment_history_service::TIMELINE_COL) as $op) {
            if ($op['docId'] === 'SCH_TEST01_STA0099_department_x') {
                $this->assertSame('2026-04-01', $op['data']['effectiveTo'], 'The closed entry\'s effectiveTo must match the backdated date, not today.');
            } else {
                $this->assertSame('2026-04-01', $op['data']['effectiveFrom']);
            }
        }
    }

    // ── Immutability of the event log ───────────────────────────────────

    public function test_every_event_op_is_create_if_not_exists_never_update(): void
    {
        $fake = new FakeEmploymentHistoryFs();
        $this->svc($fake)->recordChanges('STA0099', ['department' => 'Academics', 'position' => 'Senior Teacher']);
        foreach ($this->opsByCollection($fake->batches[0], \Staff_employment_history_service::EVENT_COL) as $op) {
            $this->assertSame(['exists' => false], $op['precondition'], 'staffEmploymentEvents ops must always be create-if-not-exists — never an update.');
            $this->assertFalse($op['merge'], 'An event write is never a merge onto a prior event.');
        }
    }
}
