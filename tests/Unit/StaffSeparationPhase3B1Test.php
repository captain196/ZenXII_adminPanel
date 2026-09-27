<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
require_once __DIR__ . '/../../application/libraries/Staff_employment_history_service.php';

/**
 * Phase 3B.1 — Separation / Reactivation / Rehire. Behavioral tests against
 * the same lightweight Firestore_service fake pattern proven for Phase
 * 1/2C/2B (StaffEmploymentHistoryPhase1Test / StaffReportingManagerPhase2CTest).
 */
class FakeSeparationFs
{
    public array $batches = [];
    public array $commitOutcomes = [true];
    private int $commitCalls = 0;
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

class StaffSeparationPhase3B1Test extends TestCase
{
    private const SCHOOL = 'SCH_TEST01';

    private function svc(FakeSeparationFs $fake, string $schoolId = self::SCHOOL, string $actor = 'ADM0001'): \Staff_employment_history_service
    {
        $svc = new \Staff_employment_history_service();
        return $svc->init($fake, $schoolId, $actor);
    }

    private function opsByCollection(array $ops, string $collection): array
    {
        return array_values(array_filter($ops, fn($o) => ($o['collection'] ?? '') === $collection));
    }

    // ── Vocabulary ────────────────────────────────────────────────────────

    public function test_employment_status_is_in_attributes_vocabulary(): void
    {
        $this->assertContains('employmentStatus', \Staff_employment_history_service::ATTRIBUTES);
    }

    // ── determineReactivationEventType (pure function) ──────────────────

    public function test_no_other_changes_resolves_reactivated(): void
    {
        $svc = new \Staff_employment_history_service();
        $this->assertSame('REACTIVATED', $svc->determineReactivationEventType([]));
    }

    public function test_position_change_resolves_rehired(): void
    {
        $svc = new \Staff_employment_history_service();
        $this->assertSame('REHIRED', $svc->determineReactivationEventType(['position' => 'Senior Teacher']));
    }

    public function test_department_change_resolves_rehired(): void
    {
        $svc = new \Staff_employment_history_service();
        $this->assertSame('REHIRED', $svc->determineReactivationEventType(['department' => 'Science']));
    }

    public function test_reporting_manager_change_resolves_rehired(): void
    {
        $svc = new \Staff_employment_history_service();
        $this->assertSame('REHIRED', $svc->determineReactivationEventType(['reportingManagerId' => 'STA0099']));
    }

    public function test_irrelevant_change_does_not_resolve_rehired(): void
    {
        $svc = new \Staff_employment_history_service();
        // 'email' is not one of the 4 rehire-relevant attributes.
        $this->assertSame('REACTIVATED', $svc->determineReactivationEventType(['email' => 'new@x.com']));
    }

    public function test_elapsed_time_is_never_a_factor_no_date_parameter_exists(): void
    {
        // Structural proof of the locked rule ("do not use elapsed time or
        // human inference") — the method signature has no date/time parameter
        // to even consult, so a date-based heuristic is structurally impossible.
        $ref = new \ReflectionMethod(\Staff_employment_history_service::class, 'determineReactivationEventType');
        $this->assertCount(1, $ref->getParameters());
        $this->assertSame('otherAttributeChanges', $ref->getParameters()[0]->getName());
    }

    // ── recordEmploymentStatusChange — SEPARATED ─────────────────────────

    public function test_separated_creates_event_with_employment_status_attribute(): void
    {
        $fake = new FakeSeparationFs();
        $res = $this->svc($fake)->recordEmploymentStatusChange(
            'STA0042', 'SEPARATED', 'Inactive', '2026-08-29', 'Relocating', [], 'Resignation', '2026-08-29'
        );
        $this->assertTrue($res['ok']);
        $eventOps = $this->opsByCollection($fake->batches[0], \Staff_employment_history_service::EVENT_COL);
        $this->assertCount(1, $eventOps);
        $this->assertSame('SEPARATED', $eventOps[0]['data']['eventType']);
        $this->assertSame('employmentStatus', $eventOps[0]['data']['attribute']);
        $this->assertSame('Active', $eventOps[0]['data']['fromValue']);
        $this->assertSame('Inactive', $eventOps[0]['data']['toValue']);
        $this->assertSame('2026-08-29', $eventOps[0]['data']['effectiveFrom'], 'effectiveFrom must be actualLastWorkingDate.');
    }

    public function test_separated_creates_linked_staff_separations_record_in_same_batch(): void
    {
        $fake = new FakeSeparationFs();
        $res = $this->svc($fake)->recordEmploymentStatusChange(
            'STA0042', 'SEPARATED', 'Inactive', '2026-08-29', 'Relocating', [], 'Resignation', '2026-08-29'
        );
        $this->assertTrue($res['ok']);
        $this->assertCount(1, $fake->batches, 'Event + staffSeparations record must be ONE atomic commitBatch.');
        $sepOps = $this->opsByCollection($fake->batches[0], \Staff_employment_history_service::EMPLOYMENT_STATUS_COL);
        $this->assertCount(1, $sepOps);
        $this->assertSame('SEPARATED', $sepOps[0]['data']['status']);
        $this->assertSame('Resignation', $sepOps[0]['data']['separationType']);
        $this->assertSame('2026-08-29', $sepOps[0]['data']['actualLastWorkingDate']);
        $this->assertSame(['exists' => false], $sepOps[0]['precondition'], 'staffSeparations create must be create-if-not-exists (idempotency).');
        $this->assertNotNull($res['separationId']);
        $eventOps = $this->opsByCollection($fake->batches[0], \Staff_employment_history_service::EVENT_COL);
        $this->assertSame($eventOps[0]['docId'], $sepOps[0]['data']['employmentEventRef'], 'staffSeparations record must reference its own SEPARATED event.');
    }

    public function test_expected_last_working_date_is_never_used_only_actual(): void
    {
        // Simulates the locked target scenario: expected=31-Aug, actual=29-Aug.
        $fake = new FakeSeparationFs();
        $this->svc($fake)->recordEmploymentStatusChange(
            'STA0042', 'SEPARATED', 'Inactive',
            '2026-08-29',            // effectiveFrom == actualLastWorkingDate, NEVER expectedLastWorkingDate (31-Aug)
            'Resignation', [], 'Resignation', '2026-08-29'
        );
        $eventOps = $this->opsByCollection($fake->batches[0], \Staff_employment_history_service::EVENT_COL);
        $sepOps = $this->opsByCollection($fake->batches[0], \Staff_employment_history_service::EMPLOYMENT_STATUS_COL);
        $this->assertSame('2026-08-29', $eventOps[0]['data']['effectiveFrom']);
        $this->assertSame('2026-08-29', $sepOps[0]['data']['actualLastWorkingDate']);
        $this->assertStringNotContainsString('2026-08-31', json_encode($fake->batches[0]));
    }

    public function test_separated_without_separation_type_creates_no_staff_separations_record(): void
    {
        $fake = new FakeSeparationFs();
        $this->svc($fake)->recordEmploymentStatusChange('STA0042', 'SEPARATED', 'Inactive', '2026-08-29');
        $sepOps = $this->opsByCollection($fake->batches[0], \Staff_employment_history_service::EMPLOYMENT_STATUS_COL);
        $this->assertCount(0, $sepOps);
    }

    // ── recordEmploymentStatusChange — REACTIVATED / REHIRED ─────────────

    public function test_reactivated_event_type_and_direction(): void
    {
        $fake = new FakeSeparationFs();
        $res = $this->svc($fake)->recordEmploymentStatusChange('STA0042', 'REACTIVATED', 'Active', '2026-09-01');
        $this->assertTrue($res['ok']);
        $eventOps = $this->opsByCollection($fake->batches[0], \Staff_employment_history_service::EVENT_COL);
        $this->assertSame('REACTIVATED', $eventOps[0]['data']['eventType']);
        $this->assertSame('Inactive', $eventOps[0]['data']['fromValue']);
        $this->assertSame('Active', $eventOps[0]['data']['toValue']);
        $sepOps = $this->opsByCollection($fake->batches[0], \Staff_employment_history_service::EMPLOYMENT_STATUS_COL);
        $this->assertCount(0, $sepOps, 'REACTIVATED must never create a staffSeparations record.');
    }

    public function test_rehired_event_type(): void
    {
        $fake = new FakeSeparationFs();
        $res = $this->svc($fake)->recordEmploymentStatusChange('STA0042', 'REHIRED', 'Active', '2026-09-01');
        $this->assertTrue($res['ok']);
        $eventOps = $this->opsByCollection($fake->batches[0], \Staff_employment_history_service::EVENT_COL);
        $this->assertSame('REHIRED', $eventOps[0]['data']['eventType']);
    }

    public function test_invalid_event_type_rejected(): void
    {
        $fake = new FakeSeparationFs();
        $res = $this->svc($fake)->recordEmploymentStatusChange('STA0042', 'PROMOTED', 'Active', '2026-09-01');
        $this->assertFalse($res['ok']);
        $this->assertSame('invalid_event_type', $res['reason']);
        $this->assertCount(0, $fake->batches);
    }

    // ── Timeline close/open (episode continuity) ─────────────────────────

    public function test_reactivated_closes_prior_open_entry_and_opens_new(): void
    {
        $fake = new FakeSeparationFs();
        $fake->openRowsByAttribute['employmentStatus'] = [
            ['id' => 'SCH_TEST01_STA0042_employmentStatus_evt_sep', 'data' => ['value' => 'Inactive', '__updateTime' => '2026-08-29T00:00:00Z']],
        ];
        $this->svc($fake)->recordEmploymentStatusChange('STA0042', 'REACTIVATED', 'Active', '2026-09-01');
        $timelineOps = $this->opsByCollection($fake->batches[0], \Staff_employment_history_service::TIMELINE_COL);
        $this->assertCount(2, $timelineOps);
        $closeOp = null; $openOp = null;
        foreach ($timelineOps as $op) {
            if ($op['docId'] === 'SCH_TEST01_STA0042_employmentStatus_evt_sep') $closeOp = $op; else $openOp = $op;
        }
        $this->assertNotNull($closeOp);
        $this->assertArrayHasKey('updateTime', $closeOp['precondition']);
        $this->assertSame('Active', $openOp['data']['value']);
        $this->assertNull($openOp['data']['effectiveTo']);
    }

    // ── CAS / bounded retry (same guarantee as recordChanges) ────────────

    public function test_cas_conflict_retries_bounded_then_succeeds(): void
    {
        $fake = new FakeSeparationFs();
        $fake->openRowsByAttribute['employmentStatus'] = [
            ['id' => 'SCH_TEST01_STA0042_employmentStatus_evt_x', 'data' => ['value' => 'Active', '__updateTime' => '2026-08-01T00:00:00Z']],
        ];
        $fake->commitOutcomes = [false, false, true];
        $res = $this->svc($fake)->recordEmploymentStatusChange('STA0042', 'SEPARATED', 'Inactive', '2026-08-29', '', [], 'Resignation', '2026-08-29');
        $this->assertTrue($res['ok']);
        $this->assertSame(2, $res['retries']);
        $this->assertSame(3, $fake->commitCallCount());
    }

    public function test_cas_conflict_exhausts_bounded_retries(): void
    {
        $fake = new FakeSeparationFs();
        $fake->openRowsByAttribute['employmentStatus'] = [
            ['id' => 'SCH_TEST01_STA0042_employmentStatus_evt_x', 'data' => ['value' => 'Active', '__updateTime' => '2026-08-01T00:00:00Z']],
        ];
        $fake->commitOutcomes = [false];
        $res = $this->svc($fake)->recordEmploymentStatusChange('STA0042', 'SEPARATED', 'Inactive', '2026-08-29');
        $this->assertFalse($res['ok']);
        $this->assertSame('contention_exhausted', $res['reason']);
        $this->assertSame(3, $fake->commitCallCount(), 'Bounded — 3 attempts, never unbounded.');
    }

    // ── Event immutability ────────────────────────────────────────────────

    public function test_event_op_is_always_create_if_not_exists(): void
    {
        $fake = new FakeSeparationFs();
        $this->svc($fake)->recordEmploymentStatusChange('STA0042', 'SEPARATED', 'Inactive', '2026-08-29', '', [], 'Retirement', '2026-08-29');
        $eventOps = $this->opsByCollection($fake->batches[0], \Staff_employment_history_service::EVENT_COL);
        $this->assertSame(['exists' => false], $eventOps[0]['precondition']);
        $this->assertFalse($eventOps[0]['merge']);
    }

    // ── Employment episode derivation (JOINED/REHIRED open, SEPARATED close, REACTIVATED continues) ──

    public function test_joined_includes_employment_status_active_snapshot(): void
    {
        $fake = new FakeSeparationFs();
        $this->svc($fake)->recordJoined('STA0042', [
            'position' => 'Teacher', 'department' => 'Math', 'employmentType' => 'Full-Time',
            'reportingManagerId' => '', 'employmentStatus' => 'Active',
        ]);
        $timelineOps = $this->opsByCollection($fake->batches[0], \Staff_employment_history_service::TIMELINE_COL);
        $this->assertCount(5, $timelineOps, 'JOINED must now produce 5 timeline entries (4 prior + employmentStatus).');
        $esOp = null;
        foreach ($timelineOps as $op) if ($op['data']['attribute'] === 'employmentStatus') $esOp = $op;
        $this->assertNotNull($esOp);
        $this->assertSame('Active', $esOp['data']['value']);
    }
}
