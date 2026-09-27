<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
require_once __DIR__ . '/../../application/libraries/Staff_employment_history_service.php';

/**
 * Phase 2C — Reporting Manager. Behavioral tests against a lightweight
 * Firestore_service fake, extending Phase 1's FakeEmploymentHistoryFs
 * pattern (StaffEmploymentHistoryPhase1Test.php) with a get() surface for
 * validateManagerAssignment()'s staff-doc lookups.
 */
class FakeReportingManagerFs
{
    public array $batches = [];
    public array $commitOutcomes = [true];
    private int $commitCalls = 0;
    public array $openRowsByAttribute = [];

    /** @var array<string,array> docId => staff doc data (['status'=>..,'reportingManagerId'=>..]) */
    public array $staffDocs = [];

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

    public function get(string $collection, string $docId)
    {
        if ($collection !== 'staff') return null;
        return $this->staffDocs[$docId] ?? null;
    }

    public function commitCallCount(): int
    {
        return $this->commitCalls;
    }
}

class StaffReportingManagerPhase2CTest extends TestCase
{
    private const SCHOOL = 'SCH_TEST01';

    private function svc(FakeReportingManagerFs $fake, string $schoolId = self::SCHOOL, string $actor = 'ADM0001'): \Staff_employment_history_service
    {
        $svc = new \Staff_employment_history_service();
        return $svc->init($fake, $schoolId, $actor);
    }

    private function seedStaff(FakeReportingManagerFs $fake, string $staffId, string $status, string $reportingManagerId = ''): void
    {
        $fake->staffDocs[self::SCHOOL . '_' . $staffId] = ['status' => $status, 'reportingManagerId' => $reportingManagerId];
    }

    // ── Attribute vocabulary ─────────────────────────────────────────────

    public function test_reporting_manager_id_is_in_attributes_vocabulary(): void
    {
        $this->assertContains('reportingManagerId', \Staff_employment_history_service::ATTRIBUTES);
    }

    // ── Identity / tenancy ───────────────────────────────────────────────

    public function test_same_school_active_manager_accepted(): void
    {
        $fake = new FakeReportingManagerFs();
        $this->seedStaff($fake, 'STA0017', 'Active');
        $res = $this->svc($fake)->validateManagerAssignment('STA0042', 'STA0017');
        $this->assertTrue($res['ok']);
        $this->assertSame('valid', $res['reason']);
    }

    public function test_cross_school_manager_rejected(): void
    {
        // Manager only exists under a DIFFERENT school's prefix — this service
        // is initialized for SCHOOL, so the lookup for STA0017 under SCHOOL's
        // prefix simply finds nothing (the doc lives at OTHER_SCHOOL_STA0017).
        $fake = new FakeReportingManagerFs();
        $fake->staffDocs['SCH_OTHER99_STA0017'] = ['status' => 'Active', 'reportingManagerId' => ''];
        $res = $this->svc($fake)->validateManagerAssignment('STA0042', 'STA0017');
        $this->assertFalse($res['ok']);
        $this->assertSame('manager_not_found', $res['reason']);
    }

    public function test_nonexistent_manager_rejected(): void
    {
        $fake = new FakeReportingManagerFs();
        $res = $this->svc($fake)->validateManagerAssignment('STA0042', 'STA9999');
        $this->assertFalse($res['ok']);
        $this->assertSame('manager_not_found', $res['reason']);
    }

    // ── Eligibility ──────────────────────────────────────────────────────

    public function test_active_manager_accepted(): void
    {
        $fake = new FakeReportingManagerFs();
        $this->seedStaff($fake, 'STA0017', 'Active');
        $this->assertTrue($this->svc($fake)->validateManagerAssignment('STA0042', 'STA0017')['ok']);
    }

    public function test_inactive_manager_rejected_as_new_assignment(): void
    {
        $fake = new FakeReportingManagerFs();
        $this->seedStaff($fake, 'STA0017', 'Inactive');
        $res = $this->svc($fake)->validateManagerAssignment('STA0042', 'STA0017');
        $this->assertFalse($res['ok']);
        $this->assertSame('manager_inactive', $res['reason']);
    }

    // ── Self-reference ───────────────────────────────────────────────────

    public function test_employee_cannot_report_to_self(): void
    {
        $fake = new FakeReportingManagerFs();
        $res = $this->svc($fake)->validateManagerAssignment('STA0042', 'STA0042');
        $this->assertFalse($res['ok']);
        $this->assertSame('self_reference', $res['reason']);
    }

    public function test_clearing_the_relationship_is_always_valid(): void
    {
        $fake = new FakeReportingManagerFs();
        $res = $this->svc($fake)->validateManagerAssignment('STA0042', '');
        $this->assertTrue($res['ok']);
        $this->assertSame('cleared', $res['reason']);
    }

    // ── Cycle detection ──────────────────────────────────────────────────

    public function test_direct_cycle_rejected(): void
    {
        // Rahul(STA0042) -> Amit(STA0017) -> Rahul  (Amit already reports to Rahul)
        $fake = new FakeReportingManagerFs();
        $this->seedStaff($fake, 'STA0017', 'Active', 'STA0042');
        $res = $this->svc($fake)->validateManagerAssignment('STA0042', 'STA0017');
        $this->assertFalse($res['ok']);
        $this->assertSame('circular_reference', $res['reason']);
    }

    public function test_two_level_cycle_rejected(): void
    {
        // Rahul -> Amit -> Priya -> Rahul
        $fake = new FakeReportingManagerFs();
        $this->seedStaff($fake, 'STA0017', 'Active', 'STA0025'); // Amit -> Priya
        $this->seedStaff($fake, 'STA0025', 'Active', 'STA0042'); // Priya -> Rahul
        $res = $this->svc($fake)->validateManagerAssignment('STA0042', 'STA0017');
        $this->assertFalse($res['ok']);
        $this->assertSame('circular_reference', $res['reason']);
    }

    public function test_multi_level_cycle_rejected(): void
    {
        // A -> B -> C -> D -> A (assigning A to report to B closes the loop)
        $fake = new FakeReportingManagerFs();
        $this->seedStaff($fake, 'STA_B', 'Active', 'STA_C');
        $this->seedStaff($fake, 'STA_C', 'Active', 'STA_D');
        $this->seedStaff($fake, 'STA_D', 'Active', 'STA_A');
        $res = $this->svc($fake)->validateManagerAssignment('STA_A', 'STA_B');
        $this->assertFalse($res['ok']);
        $this->assertSame('circular_reference', $res['reason']);
    }

    public function test_maximum_depth_boundary(): void
    {
        // Build a chain of exactly REPORTING_MANAGER_MAX_CHAIN_DEPTH links with
        // NO cycle — must be accepted (the walk terminates within budget).
        $fake = new FakeReportingManagerFs();
        $depth = \Staff_employment_history_service::REPORTING_MANAGER_MAX_CHAIN_DEPTH;
        $prev = '';
        for ($i = 0; $i < $depth; $i++) {
            $id = 'STA_L' . $i;
            $this->seedStaff($fake, $id, 'Active', $prev);
            $prev = $id;
        }
        // Employee reports to the top of this (depth-1)-long chain — well within budget.
        $res = $this->svc($fake)->validateManagerAssignment('STA_EMPLOYEE', 'STA_L' . ($depth - 1));
        $this->assertTrue($res['ok'], 'A long-but-acyclic chain within the bound must be accepted.');
    }

    public function test_broken_chain_handled_without_blocking_new_assignment(): void
    {
        // Candidate manager's own manager reference points at a doc that
        // doesn't exist — must NOT block; treated as end of chain.
        $fake = new FakeReportingManagerFs();
        $this->seedStaff($fake, 'STA0017', 'Active', 'STA_GHOST'); // STA_GHOST never seeded
        $res = $this->svc($fake)->validateManagerAssignment('STA0042', 'STA0017');
        $this->assertTrue($res['ok'], 'A broken (unresolvable) link elsewhere in the chain must not block an otherwise valid new assignment.');
    }

    public function test_inactive_intermediate_manager_does_not_block_new_assignment(): void
    {
        // Chain: candidate(Active) -> intermediate(Inactive) -> top(Active).
        // Topology has no cycle, so this must be accepted even though an
        // Inactive staff member sits mid-chain — eligibility applies only to
        // the immediate new candidate (already checked Active above).
        $fake = new FakeReportingManagerFs();
        $this->seedStaff($fake, 'STA0017', 'Active', 'STA_MID');
        $this->seedStaff($fake, 'STA_MID', 'Inactive', 'STA_TOP');
        $this->seedStaff($fake, 'STA_TOP', 'Active', '');
        $res = $this->svc($fake)->validateManagerAssignment('STA0042', 'STA0017');
        $this->assertTrue($res['ok'], 'Cycle detection is topology-only — an inactive staff member elsewhere in the chain must not block.');
    }

    // ── Employment History integration ──────────────────────────────────

    public function test_joined_with_manager_records_reporting_manager_id_entry(): void
    {
        $fake = new FakeReportingManagerFs();
        $res = $this->svc($fake)->recordJoined('STA0042', [
            'position' => 'Teacher', 'department' => 'Math', 'employmentType' => 'Full-Time',
            'reportingManagerId' => 'STA0017',
        ]);
        $this->assertTrue($res['ok']);
        $ops = $fake->batches[0];
        $timelineOps = array_values(array_filter($ops, fn($o) => $o['collection'] === \Staff_employment_history_service::TIMELINE_COL));
        $this->assertCount(5, $timelineOps, 'JOINED must now produce 5 timeline entries (3 Phase-1 + reportingManagerId + Phase 3B.1\'s employmentStatus).');
        $rmOp = null;
        foreach ($timelineOps as $op) if ($op['data']['attribute'] === 'reportingManagerId') $rmOp = $op;
        $this->assertNotNull($rmOp);
        $this->assertSame('STA0017', $rmOp['data']['value']);
    }

    public function test_manager_assignment_creates_reporting_manager_changed_event(): void
    {
        $fake = new FakeReportingManagerFs();
        $res = $this->svc($fake)->recordChanges('STA0042', ['reportingManagerId' => 'STA0017']);
        $this->assertTrue($res['ok']);
        $eventOps = array_values(array_filter($fake->batches[0], fn($o) => $o['collection'] === \Staff_employment_history_service::EVENT_COL));
        $this->assertCount(1, $eventOps);
        $this->assertSame('REPORTING_MANAGER_CHANGED', $eventOps[0]['data']['eventType'], 'Event type must be REPORTING_MANAGER_CHANGED, not REPORTING_MANAGER_ID_CHANGED.');
        $this->assertSame('STA0017', $eventOps[0]['data']['toValue']);
    }

    public function test_manager_reassignment_closes_old_timeline_and_opens_new(): void
    {
        // Rahul -> Amit, now reassigned to Priya.
        $fake = new FakeReportingManagerFs();
        $fake->openRowsByAttribute['reportingManagerId'] = [
            ['id' => 'SCH_TEST01_STA0042_reportingManagerId_evt_amit', 'data' => ['value' => 'STA0017', '__updateTime' => '2026-01-01T00:00:00Z']],
        ];
        $res = $this->svc($fake)->recordChanges('STA0042', ['reportingManagerId' => 'STA0025']);
        $this->assertTrue($res['ok']);
        $timelineOps = array_values(array_filter($fake->batches[0], fn($o) => $o['collection'] === \Staff_employment_history_service::TIMELINE_COL));
        $this->assertCount(2, $timelineOps);
        $closeOp = null; $openOp = null;
        foreach ($timelineOps as $op) {
            if ($op['docId'] === 'SCH_TEST01_STA0042_reportingManagerId_evt_amit') $closeOp = $op; else $openOp = $op;
        }
        $this->assertNotNull($closeOp);
        $this->assertArrayHasKey('updateTime', $closeOp['precondition']);
        $this->assertSame('STA0025', $openOp['data']['value']);
        $this->assertNull($openOp['data']['effectiveTo']);
    }

    public function test_manager_removal_records_change_to_empty_value(): void
    {
        $fake = new FakeReportingManagerFs();
        $fake->openRowsByAttribute['reportingManagerId'] = [
            ['id' => 'SCH_TEST01_STA0042_reportingManagerId_evt_amit', 'data' => ['value' => 'STA0017', '__updateTime' => '2026-01-01T00:00:00Z']],
        ];
        $res = $this->svc($fake)->recordChanges('STA0042', ['reportingManagerId' => '']);
        $this->assertTrue($res['ok']);
        $eventOps = array_values(array_filter($fake->batches[0], fn($o) => $o['collection'] === \Staff_employment_history_service::EVENT_COL));
        $this->assertSame('STA0017', $eventOps[0]['data']['fromValue']);
        $this->assertSame('', $eventOps[0]['data']['toValue']);
    }

    public function test_no_false_event_when_manager_unchanged(): void
    {
        $fake = new FakeReportingManagerFs();
        $fake->openRowsByAttribute['reportingManagerId'] = [
            ['id' => 'SCH_TEST01_STA0042_reportingManagerId_evt_amit', 'data' => ['value' => 'STA0017', '__updateTime' => '2026-01-01T00:00:00Z']],
        ];
        $res = $this->svc($fake)->recordChanges('STA0042', ['reportingManagerId' => 'STA0017']);
        $this->assertTrue($res['ok']);
        $this->assertSame('noop', $res['reason']);
        $this->assertCount(0, $fake->batches, 'Submitting the SAME manager value must never fire a commitBatch.');
    }

    public function test_effective_from_applied_to_reporting_manager_change(): void
    {
        $fake = new FakeReportingManagerFs();
        $this->svc($fake)->recordChanges('STA0042', ['reportingManagerId' => 'STA0017'], '2026-06-01', 'Transfer');
        $eventOps = array_values(array_filter($fake->batches[0], fn($o) => $o['collection'] === \Staff_employment_history_service::EVENT_COL));
        $this->assertSame('2026-06-01', $eventOps[0]['data']['effectiveFrom']);
        $this->assertSame('Transfer', $eventOps[0]['data']['reason']);
    }

    public function test_reporting_manager_event_is_create_if_not_exists_never_update(): void
    {
        $fake = new FakeReportingManagerFs();
        $this->svc($fake)->recordChanges('STA0042', ['reportingManagerId' => 'STA0017']);
        $eventOps = array_values(array_filter($fake->batches[0], fn($o) => $o['collection'] === \Staff_employment_history_service::EVENT_COL));
        $this->assertSame(['exists' => false], $eventOps[0]['precondition'], 'Immutable append-only — same guarantee as every other Phase 1 attribute.');
    }

    // ── Position/Department/EmploymentType independence ──────────────────

    public function test_reporting_manager_change_alone_does_not_touch_other_attributes(): void
    {
        $fake = new FakeReportingManagerFs();
        $this->svc($fake)->recordChanges('STA0042', ['reportingManagerId' => 'STA0017']);
        $eventOps = array_values(array_filter($fake->batches[0], fn($o) => $o['collection'] === \Staff_employment_history_service::EVENT_COL));
        $this->assertCount(1, $eventOps);
        $this->assertSame('reportingManagerId', $eventOps[0]['data']['attribute']);
    }

    public function test_position_and_reporting_manager_change_together_stay_independent_events(): void
    {
        $fake = new FakeReportingManagerFs();
        $res = $this->svc($fake)->recordChanges('STA0042', ['position' => 'Senior Teacher', 'reportingManagerId' => 'STA0017']);
        $this->assertTrue($res['ok']);
        $this->assertCount(1, $fake->batches, 'Both changes still commit together as one batch.');
        $eventOps = array_values(array_filter($fake->batches[0], fn($o) => $o['collection'] === \Staff_employment_history_service::EVENT_COL));
        $types = array_map(fn($o) => $o['data']['eventType'], $eventOps);
        sort($types);
        $this->assertSame(['POSITION_CHANGED', 'REPORTING_MANAGER_CHANGED'], $types, 'No combined PROMOTION_WITH_MANAGER_CHANGE event type — two independent attribute events.');
    }
}
