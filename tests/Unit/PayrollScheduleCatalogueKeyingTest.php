<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * REGRESSION — payroll's work-schedule catalogue must be keyed by scheduleId.
 *
 * DEFECT (found by real UAT against the designated test school, 2026-08-19):
 *
 *   Work_schedules::save_schedule() STORES the catalogue as a list —
 *   `'attendancePolicy.workSchedules' => array_values($list)`.
 *   Policy_assignment_service::resolve() LOOKS A POLICY UP as
 *   `$policies[$policyId]`.
 *
 *   Work_schedule_service::resolveEmployeeCalendar() — the payroll path — passed
 *   the stored list straight through, so `isset($policies['WS_X'])` was never
 *   true for a list keyed 0,1,2. Every employee failed closed with
 *   `policy_missing` -> BLOCK_SCHEDULE_INVALID -> generate_payroll `continue`d the
 *   entire roster -> "No staff with salary structures found in the roster."
 *
 *   Every OTHER consumer of the same catalogue already re-keyed it
 *   (Attendance::_staff_grid_schedule, Staff_attendance, Staff_attendance_finaliser,
 *   Work_schedules). Payroll was the only one that did not — which is why staff
 *   attendance worked while payroll could not resolve a schedule at all.
 *
 * These tests EXECUTE the shipped static method. No Firestore, no mocks: the
 * inputs are the exact shapes the writer produces.
 */
final class PayrollScheduleCatalogueKeyingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once APPPATH . 'libraries/Work_schedule_service.php';
    }

    /** A Mon–Sat schedule, in the shape save_schedule() writes. */
    private function schedule(string $id = 'WS_TEST'): array
    {
        return [
            'scheduleId'     => $id,
            'name'           => 'Standard Week',
            'weeklyOffs'     => ['Sun'],
            'nthWeekdayOffs' => [],
            'version'        => 1,
            'effectiveFrom'  => '2020-01-01',
            'status'         => 'active',
        ];
    }

    private function assignment(string $staffId, string $scheduleId = 'WS_TEST'): array
    {
        // Field names are the ones Policy_assignment_service::assignmentsAtLevel()
        // actually matches on — attachmentType/attachmentId, not level/staffId.
        return [
            'assignmentId'   => 'WSA_1',
            'attachmentType' => Policy_assignment_service::LEVEL_STAFF,
            'attachmentId'   => $staffId,
            'staffId'        => $staffId,
            'policyId'       => $scheduleId,
            'scheduleId'     => $scheduleId,
            'effectiveFrom'  => '2020-01-01',
            'status'         => 'active',
        ];
    }

    /** Full-month employment window for April 2026 (30 days). */
    private function window(): array
    {
        $w = [];
        for ($d = 1; $d <= 30; $d++) $w[$d] = true;
        return $w;
    }

    private function resolve(array $catalogue): array
    {
        return Work_schedule_service::resolveEmployeeCalendar(
            ['workSchedules' => $catalogue],
            [$this->assignment('STA0001')],
            'STA0001',
            2026, 4,
            [], $this->window(), 'Any Department'
        );
    }

    /**
     * THE DEFECT ITSELF: the catalogue exactly as save_schedule() persists it.
     * Before the fix this returned blocked=true / policy_missing.
     */
    public function test_list_shaped_catalogue_resolves(): void
    {
        $r = $this->resolve(array_values([$this->schedule()]));

        $this->assertFalse($r['blocked'], 'stored list-shaped catalogue must resolve; got: ' . ($r['blockReason'] ?? ''));
        $this->assertSame('', (string) ($r['blockCode'] ?? ''));
        $this->assertSame('WS_TEST', $r['scheduleId']);
    }

    /** April 2026 has 4 Sundays, so a Mon–Sat week is 26 working days. */
    public function test_resolved_calendar_is_arithmetically_correct(): void
    {
        $r = $this->resolve(array_values([$this->schedule()]));

        $this->assertSame(26, $r['workingCount'], 'April 2026 minus 4 Sundays');
        $this->assertIsInt($r['workingCount'], 'no half-day weight in play — must stay an int');
    }

    /** Re-keying is idempotent: an already-keyed catalogue still resolves. */
    public function test_already_keyed_catalogue_still_resolves(): void
    {
        $r = $this->resolve(['WS_TEST' => $this->schedule()]);

        $this->assertFalse($r['blocked']);
        $this->assertSame('WS_TEST', $r['scheduleId']);
    }

    /** The correct schedule is picked out of a multi-entry catalogue. */
    public function test_picks_the_assigned_schedule_from_several(): void
    {
        $r = $this->resolve(array_values([
            $this->schedule('WS_OTHER_A'),
            $this->schedule('WS_TEST'),
            $this->schedule('WS_OTHER_B'),
        ]));

        $this->assertFalse($r['blocked']);
        $this->assertSame('WS_TEST', $r['scheduleId'], 'must not resolve by list position');
    }

    /**
     * FAIL-CLOSED PRESERVED: the fix must not turn a genuinely missing policy
     * into a silent pass. An assignment pointing at an absent schedule still blocks.
     */
    public function test_assignment_to_absent_schedule_still_blocks(): void
    {
        $r = Work_schedule_service::resolveEmployeeCalendar(
            ['workSchedules' => array_values([$this->schedule('WS_TEST')])],
            [$this->assignment('STA0001', 'WS_DOES_NOT_EXIST')],
            'STA0001',
            2026, 4,
            [], $this->window(), 'Any Department'
        );

        $this->assertTrue($r['blocked'], 'a dangling assignment must still fail closed');
    }

    /** An empty catalogue keeps its own distinct block code, not policy_missing. */
    public function test_empty_catalogue_still_reports_no_schedules(): void
    {
        $r = $this->resolve([]);

        $this->assertTrue($r['blocked']);
        $this->assertSame(Work_schedule_service::BLOCK_NO_SCHEDULES, $r['blockCode']);
    }

    /** Malformed rows are skipped without derailing the rest of the catalogue. */
    public function test_malformed_catalogue_rows_are_skipped(): void
    {
        $r = $this->resolve([
            'not-an-array',
            ['name' => 'no scheduleId'],
            $this->schedule(),
        ]);

        $this->assertFalse($r['blocked']);
        $this->assertSame('WS_TEST', $r['scheduleId']);
    }

    /**
     * schedulesFrom() is now the single canonical keying point. Its docblock
     * always promised [scheduleId => schedule]; the implementation used to
     * return the stored list. These pin the contract at the source rather than
     * at each caller, which is what stopped one consumer from getting it wrong.
     */
    public function test_schedules_from_returns_a_map_keyed_by_schedule_id(): void
    {
        $out = Work_schedule_service::schedulesFrom([
            'workSchedules' => array_values([$this->schedule('WS_A'), $this->schedule('WS_B')]),
        ]);

        $this->assertSame(['WS_A', 'WS_B'], array_keys($out), 'keys must be scheduleIds, not 0/1');
        $this->assertSame('WS_A', $out['WS_A']['scheduleId']);
    }

    public function test_schedules_from_drops_rows_without_a_usable_id(): void
    {
        $out = Work_schedule_service::schedulesFrom([
            'workSchedules' => ['not-an-array', ['name' => 'no id'], ['scheduleId' => ''], $this->schedule('WS_A')],
        ]);

        $this->assertSame(['WS_A'], array_keys($out));
        $this->assertArrayNotHasKey('', $out, 'an empty key could shadow a real lookup');
    }

    public function test_schedules_from_tolerates_absent_or_malformed_policy(): void
    {
        $this->assertSame([], Work_schedule_service::schedulesFrom(null));
        $this->assertSame([], Work_schedule_service::schedulesFrom([]));
        $this->assertSame([], Work_schedule_service::schedulesFrom(['workSchedules' => 'nonsense']));
    }

    /**
     * Iterating consumers must be unaffected by the keying: they read VALUES.
     * Attendance, Staff_attendance and the finaliser all foreach this result.
     */
    public function test_iterating_consumers_still_see_the_same_values(): void
    {
        $list = array_values([$this->schedule('WS_A'), $this->schedule('WS_B')]);
        $out  = Work_schedule_service::schedulesFrom(['workSchedules' => $list]);

        $this->assertSame($list, array_values($out), 'values and their order must be unchanged');
    }

    /**
     * SOURCE CONTRACT: the writer really does store a list. If this ever changes
     * to a map the re-key stays harmless, but the mismatch this test guards
     * against would no longer be the live shape — so pin what ships today.
     */
    public function test_writer_still_persists_the_catalogue_as_a_list(): void
    {
        $src = file_get_contents(APPPATH . 'controllers/Work_schedules.php');

        $this->assertStringContainsString(
            "'attendancePolicy.workSchedules' => array_values(\$list)",
            $src,
            'save_schedule() is expected to persist a re-indexed list'
        );
    }

    /**
     * SOURCE CONTRACT: the resolver really does look up by key. Together with the
     * test above, these two pin the exact mismatch that caused the outage.
     */
    public function test_resolver_still_looks_policies_up_by_key(): void
    {
        $src = file_get_contents(APPPATH . 'libraries/Policy_assignment_service.php');

        $this->assertStringContainsString('!isset($policies[$policyId])', $src);
    }
}
