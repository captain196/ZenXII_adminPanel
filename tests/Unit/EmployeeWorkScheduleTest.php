<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
require_once __DIR__ . '/../../application/libraries/Working_calendar_service.php';
require_once __DIR__ . '/../../application/libraries/Policy_assignment_service.php';
require_once __DIR__ . '/../../application/libraries/Work_schedule_service.php';

/**
 * N-31 … N-33 — employee-specific work schedules.
 *
 * WORKFORCE POLICY RECONCILIATION (2026-08-18): `resolveEmployeeCalendar()`
 * now delegates to `Policy_assignment_service` (the canonical engine also
 * used by Staff Attendance and the Work Schedules admin surface) instead of
 * its own `versions[]`-based selection — closing the gap where Payroll was
 * the one consumer left on the pre-Phase-0 resolver, expecting a
 * `workSchedules[].versions[]` shape the live admin UI never writes (every
 * schedule created through the shipped screen has only a flat `weeklyOffs`
 * field). See `Work_schedule_service.php`'s class docblock for the full
 * history.
 *
 * This entire suite is rewritten for the new contract:
 *   - Schedules are flat (`{scheduleId, name, weeklyOffs}`), no `versions[]`.
 *   - Assignments are passed to `resolveEmployeeCalendar()` as the FULL
 *     tenant list (any attachment level), not pre-filtered to one employee —
 *     each record self-identifies via `attachmentType`/`attachmentId`, and
 *     `department` is now a resolver input.
 * Every BEHAVIORAL guarantee the original suite proved (two employees /
 * different calendars, overlap refusal, block-state fidelity, frozen
 * identity, historical-period stability) is preserved here against the new
 * fixture shape — this is a superseded-fixture rewrite, not a weakened test.
 *
 * THE DECISIVE ASSERTION
 * ----------------------
 * Two employees, ONE school, ONE payroll month, DIFFERENT working-day counts
 * and different masks — resolved by the same engine with no code difference.
 * A tenant-wide calendar reused for every employee is precisely what this
 * suite exists to forbid.
 *
 * Reference month: MAY 2026 — 31 days, starts Friday.
 *   Sundays   : 3, 10, 17, 24, 31
 *   Saturdays : 2 (1st), 9 (2nd), 16 (3rd), 23 (4th), 30 (5th)
 */
class EmployeeWorkScheduleTest extends TestCase
{
    private const Y = 2026;
    private const M = 5;
    private const DIM = 31;

    private function window(): array
    {
        return array_fill_keys(range(1, self::DIM), true);
    }

    /** Teaching = Sat+Sun off · Admin = Sun off · Support = Sun + 2nd/4th Sat off. */
    private function policy(): array
    {
        return ['workSchedules' => [
            'teaching' => ['scheduleId' => 'teaching', 'name' => 'Teaching Staff',
                'weeklyOffs' => ['Sat', 'Sun']],
            'admin' => ['scheduleId' => 'admin', 'name' => 'Administration',
                'weeklyOffs' => ['Sun']],
            'support' => ['scheduleId' => 'support', 'name' => 'Support Staff',
                'weeklyOffs' => ['Sun'],
                'nthWeekdayOffs' => [['day' => 'Sat', 'weeks' => [2, 4]]]],
        ]];
    }

    /** A staff-level assignment for the given employee. */
    private function assign(string $scheduleId, string $staffId = 'STA0001',
                             string $from = '2026-01-01', ?string $to = null): array
    {
        return [[
            'attachmentType' => 'staff', 'attachmentId' => $staffId,
            'policyId' => $scheduleId, 'effectiveFrom' => $from, 'effectiveTo' => $to ?? '',
        ]];
    }

    /** A department-level assignment, inherited by everyone in that department. */
    private function assignDept(string $scheduleId, string $department,
                                 string $from = '2026-01-01', ?string $to = null): array
    {
        return [[
            'attachmentType' => 'department', 'attachmentId' => $department,
            'policyId' => $scheduleId, 'effectiveFrom' => $from, 'effectiveTo' => $to ?? '',
        ]];
    }

    private function resolve(array $assignments, string $staffId = 'STA0001',
                             ?array $policy = null, ?array $window = null, array $holidays = [],
                             string $department = ''): array
    {
        return \Work_schedule_service::resolveEmployeeCalendar(
            $policy ?? $this->policy(), $assignments, $staffId,
            self::Y, self::M, $holidays, $window ?? $this->window(), $department
        );
    }

    // ── THE MANDATORY TEST (§6, §10.3) ───────────────────────────────────

    public function test_two_employees_same_school_same_month_different_calendars(): void
    {
        $teacher = $this->resolve($this->assign('teaching', 'STA0001'), 'STA0001');
        $admin   = $this->resolve($this->assign('admin', 'STA0002'),    'STA0002');

        $this->assertFalse($teacher['blocked']);
        $this->assertFalse($admin['blocked']);

        // 31 − 5 Sundays − 5 Saturdays = 21   vs   31 − 5 Sundays = 26
        $this->assertSame(21, $teacher['workingCount']);
        $this->assertSame(26, $admin['workingCount']);

        $this->assertNotSame($teacher['workingMask'], $admin['workingMask'],
            'the two employees must not share a calendar');
        $this->assertSame('teaching', $teacher['scheduleId']);
        $this->assertSame('admin',    $admin['scheduleId']);

        // Day 2 is a Saturday: off for teaching, working for admin.
        $this->assertFalse(isset($teacher['isWorking'][2]));
        $this->assertTrue(isset($admin['isWorking'][2]));
    }

    public function test_three_schedules_in_one_run_each_resolve_independently(): void
    {
        $counts = [
            'teaching' => $this->resolve($this->assign('teaching', 'A'), 'A')['workingCount'],
            'admin'    => $this->resolve($this->assign('admin',    'B'), 'B')['workingCount'],
            'support'  => $this->resolve($this->assign('support',  'C'), 'C')['workingCount'],
        ];
        $this->assertSame(['teaching' => 21, 'admin' => 26, 'support' => 24], $counts);
        $this->assertCount(3, array_unique($counts));
    }

    public function test_department_level_assignment_covers_every_employee_in_it(): void
    {
        // A single department attachment, no per-staff record at all — this is
        // the case the pre-reconciliation resolver could never see, because it
        // read assignments pre-grouped by staffId and silently dropped every
        // department-level record.
        $r1 = $this->resolve($this->assignDept('teaching', 'Academic'), 'STA0001', null, null, [], 'Academic');
        $r2 = $this->resolve($this->assignDept('teaching', 'Academic'), 'STA0002', null, null, [], 'Academic');
        $this->assertFalse($r1['blocked']);
        $this->assertFalse($r2['blocked']);
        $this->assertSame(21, $r1['workingCount']);
        $this->assertSame($r1['workingMask'], $r2['workingMask'],
            'both employees inherit the same department schedule');
    }

    public function test_staff_level_assignment_overrides_department_level(): void
    {
        // Department says Admin (Sun off, 26 working days); this one employee
        // has a personal override to Teaching (Sat+Sun off, 21).
        $assignments = array_merge(
            $this->assignDept('admin', 'Academic'),
            $this->assign('teaching', 'STA0001')
        );
        $r = $this->resolve($assignments, 'STA0001', null, null, [], 'Academic');
        $this->assertFalse($r['blocked']);
        $this->assertSame('teaching', $r['scheduleId'],
            'staff-level assignment must win over the department default');
        $this->assertSame(21, $r['workingCount']);
    }

    /** The support schedule reproduces the PR0003 evidence month exactly. */
    public function test_support_schedule_matches_the_PR0003_calendar(): void
    {
        $r = $this->resolve($this->assign('support', 'STA0001'));
        $this->assertSame(24, $r['workingCount']);
        $this->assertFalse(isset($r['isWorking'][9]));    // 2nd Saturday
        $this->assertFalse(isset($r['isWorking'][23]));   // 4th Saturday
        $this->assertTrue(isset($r['isWorking'][2]));     // 1st Saturday works
    }

    // ── Mid-year reassignment (§4) ───────────────────────────────────────

    public function test_employee_schedule_change_resolves_per_period(): void
    {
        $assignments = [
            ['attachmentType' => 'staff', 'attachmentId' => 'STA0001', 'policyId' => 'teaching',
             'effectiveFrom' => '2026-01-01', 'effectiveTo' => '2026-06-30'],
            ['attachmentType' => 'staff', 'attachmentId' => 'STA0001', 'policyId' => 'admin',
             'effectiveFrom' => '2026-07-01', 'effectiveTo' => ''],
        ];

        // May → teaching
        $may = $this->resolve($assignments);
        $this->assertSame('teaching', $may['scheduleId']);
        $this->assertSame(21, $may['workingCount']);

        // August → admin
        $aug = \Work_schedule_service::resolveEmployeeCalendar(
            $this->policy(), $assignments, 'STA0001', 2026, 8, [],
            array_fill_keys(range(1, 31), true));
        $this->assertSame('admin', $aug['scheduleId']);
    }

    public function test_adding_a_future_assignment_does_not_change_a_closed_period(): void
    {
        $before = $this->resolve([
            ['attachmentType' => 'staff', 'attachmentId' => 'STA0001', 'policyId' => 'teaching',
             'effectiveFrom' => '2026-01-01', 'effectiveTo' => '2026-06-30'],
        ]);
        $after = $this->resolve([
            ['attachmentType' => 'staff', 'attachmentId' => 'STA0001', 'policyId' => 'teaching',
             'effectiveFrom' => '2026-01-01', 'effectiveTo' => '2026-06-30'],
            ['attachmentType' => 'staff', 'attachmentId' => 'STA0001', 'policyId' => 'admin',
             'effectiveFrom' => '2026-07-01', 'effectiveTo' => ''],
        ]);

        $this->assertSame($before['workingCount'], $after['workingCount']);
        $this->assertSame($before['workingMask'],  $after['workingMask']);
        $this->assertSame($before['scheduleId'],   $after['scheduleId']);
    }

    // ── The block states ──────────────────────────────────────────────────

    public function test_A_no_schedules_defined_blocks(): void
    {
        $r = $this->resolve($this->assign('teaching', 'STA0001'), 'STA0001', []);
        $this->assertTrue($r['blocked']);
        $this->assertSame(\Work_schedule_service::BLOCK_NO_SCHEDULES, $r['blockCode']);
    }

    public function test_B_employee_with_no_assignment_blocks(): void
    {
        $r = $this->resolve([]);
        $this->assertTrue($r['blocked']);
        $this->assertSame(\Work_schedule_service::BLOCK_NO_ASSIGNMENT, $r['blockCode']);
    }

    public function test_C_expired_assignment_blocks_with_its_own_reason(): void
    {
        $r = $this->resolve([
            ['attachmentType' => 'staff', 'attachmentId' => 'STA0001', 'policyId' => 'teaching',
             'effectiveFrom' => '2025-01-01', 'effectiveTo' => '2025-12-31'],
        ]);
        $this->assertTrue($r['blocked']);
        $this->assertSame(\Work_schedule_service::BLOCK_ASSIGNMENT_EXPIRED, $r['blockCode']);
    }

    public function test_D_overlapping_assignments_are_refused_not_guessed(): void
    {
        $r = $this->resolve([
            ['attachmentType' => 'staff', 'attachmentId' => 'STA0001', 'policyId' => 'teaching',
             'effectiveFrom' => '2026-01-01', 'effectiveTo' => ''],
            ['attachmentType' => 'staff', 'attachmentId' => 'STA0001', 'policyId' => 'admin',
             'effectiveFrom' => '2026-02-01', 'effectiveTo' => ''],
        ]);
        $this->assertTrue($r['blocked']);
        $this->assertSame(\Work_schedule_service::BLOCK_AMBIGUOUS, $r['blockCode']);
        // Resolving by read order would make a closed period reprice on re-read.
        $this->assertSame(0, $r['workingCount']);
    }

    public function test_E_assignment_to_a_missing_schedule_blocks(): void
    {
        $r = $this->resolve($this->assign('does_not_exist', 'STA0001'));
        $this->assertTrue($r['blocked']);
        $this->assertSame(\Work_schedule_service::BLOCK_SCHEDULE_INVALID, $r['blockCode']);
    }

    /**
     * Originally "schedule with no effective version blocks" — schedules no
     * longer carry `versions[]` at all (effective dating moved to the
     * ASSIGNMENT), so that specific block condition cannot occur anymore.
     * Retired rather than force-fitted; BLOCK_SCHEDULE_INVALID's remaining
     * trigger (assignment points at a missing schedule) is covered by
     * test_E_assignment_to_a_missing_schedule_blocks above.
     */

    /** Every block must produce an empty calendar — never a partial guess. */
    public function test_every_block_state_yields_no_calendar(): void
    {
        foreach ([[], $this->assign('missing', 'STA0001')] as $a) {
            $r = $this->resolve($a);
            $this->assertSame(0, $r['workingCount']);
            $this->assertSame([], $r['isWorking']);
            $this->assertStringNotContainsString('1', $r['workingMask']);
        }
    }

    // ── Identity frozen into evidence (§3, §8) ───────────────────────────

    public function test_resolution_carries_the_identity_needed_to_reproduce_it(): void
    {
        $r = $this->resolve($this->assign('support', 'STA0001', '2026-04-01'));

        $this->assertSame('support', $r['scheduleId']);
        $this->assertSame('Support Staff', $r['scheduleName']);
        // Schedules carry no version identity anymore (moved to the
        // assignment) — assignmentEffectiveFrom is the frozen-evidence field
        // that matters now.
        $this->assertNull($r['policyVersion']);
        $this->assertSame('', $r['policyEffectiveFrom']);
        $this->assertSame('2026-04-01', $r['assignmentEffectiveFrom']);
        $this->assertSame('STA0001', $r['staffId']);
        $this->assertSame(self::DIM, strlen($r['workingMask']));
    }

    // ── Holidays, window, rehire ─────────────────────────────────────────

    public function test_holidays_remain_distinct_from_weekly_offs(): void
    {
        $r = $this->resolve($this->assign('admin', 'STA0001'), 'STA0001', null, null, [15 => 'Founders Day']);
        $this->assertSame(\Working_calendar_service::REASON_HOLIDAY, $r['reason'][15]);
        $this->assertSame(\Working_calendar_service::REASON_WEEKLY_OFF, $r['reason'][3]);
        $this->assertSame(25, $r['workingCount']);
    }

    public function test_rehire_with_a_different_schedule(): void
    {
        // Left in teaching, returned on the 20th as admin.
        $assignments = [
            ['attachmentType' => 'staff', 'attachmentId' => 'STA0001', 'policyId' => 'teaching',
             'effectiveFrom' => '2026-01-01', 'effectiveTo' => '2026-04-30'],
            ['attachmentType' => 'staff', 'attachmentId' => 'STA0001', 'policyId' => 'admin',
             'effectiveFrom' => '2026-05-20', 'effectiveTo' => ''],
        ];
        $window = array_fill_keys(range(20, 31), true);

        // The period starts 1 May — no assignment covers it, so BLOCKED rather
        // than silently priced against the lapsed teaching schedule.
        $r = $this->resolve($assignments, 'STA0001', null, $window);
        $this->assertTrue($r['blocked']);
        $this->assertSame(\Work_schedule_service::BLOCK_ASSIGNMENT_EXPIRED, $r['blockCode']);
    }

    public function test_employment_window_still_limits_the_calendar(): void
    {
        $r = $this->resolve($this->assign('admin', 'STA0001'), 'STA0001', null,
            array_fill_keys(range(16, 31), true));
        $this->assertSame(\Working_calendar_service::REASON_OUTSIDE, $r['reason'][1]);
        $this->assertSame(13, $r['workingCount']);   // 16..31 minus Sundays 17, 24, 31
    }

    // ── Assignment validation (write-time, unaffected by the resolver convergence) ──

    public function test_overlapping_assignment_is_rejected_at_write_time(): void
    {
        $svc = new \Work_schedule_service();
        $r = $svc->validateAssignment(
            ['staffId' => 'STA0001', 'scheduleId' => 'admin', 'effectiveFrom' => '2026-03-01'],
            [['effectiveFrom' => '2026-01-01', 'effectiveTo' => null]]
        );
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('overlaps', implode(' ', $r['errors']));
    }

    public function test_assignment_validation_requires_employee_schedule_and_date(): void
    {
        $svc = new \Work_schedule_service();
        foreach ([
            ['scheduleId' => 'admin', 'effectiveFrom' => '2026-01-01'],
            ['staffId' => 'STA0001', 'effectiveFrom' => '2026-01-01'],
            ['staffId' => 'STA0001', 'scheduleId' => 'admin'],
            ['staffId' => 'STA0001', 'scheduleId' => 'admin', 'effectiveFrom' => '01-01-2026'],
            ['staffId' => 'STA0001', 'scheduleId' => 'admin', 'effectiveFrom' => '2026-06-01',
             'effectiveTo' => '2026-01-01'],
        ] as $i => $bad) {
            $this->assertFalse($svc->validateAssignment($bad)['ok'], "case #{$i}");
        }
    }

    public function test_a_valid_non_overlapping_assignment_is_accepted(): void
    {
        $svc = new \Work_schedule_service();
        $r = $svc->validateAssignment(
            ['staffId' => 'STA0001', 'scheduleId' => 'admin', 'effectiveFrom' => '2026-07-01'],
            [['effectiveFrom' => '2026-01-01', 'effectiveTo' => '2026-06-30']]
        );
        $this->assertTrue($r['ok'], implode(' ', $r['errors']));
    }

    // ── Architecture guards ──────────────────────────────────────────────

    public function test_the_schedule_service_computes_no_calendar_itself(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Work_schedule_service.php');
        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
                $code .= $t[1];
            } else { $code .= $t; }
        }
        // It decides WHOSE calendar, never HOW one is computed.
        foreach (["date('w'", 'satCount', 'Sunday', 'nthWeekdayOffs['] as $calendarLogic) {
            $this->assertStringNotContainsString($calendarLogic, $code,
                "schedule resolution must delegate calendar maths, found '{$calendarLogic}'");
        }
        $this->assertStringContainsString('Working_calendar_service::resolveFromVersion', $code);
    }

    /** New guard: the resolver must delegate WHOSE-schedule resolution too, not just calendar maths. */
    public function test_the_schedule_service_delegates_assignment_resolution(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Work_schedule_service.php');
        $this->assertStringContainsString('Policy_assignment_service::resolve', $src);
    }

    // ── Payroll wiring (§ implementation order 1–5) ──────────────────────

    public function test_payroll_resolves_the_calendar_per_employee(): void
    {
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');

        $this->assertStringContainsString('Work_schedule_service::resolveEmployeeCalendar', $hr,
            'payroll must resolve each employee\'s own calendar');
        $this->assertStringNotContainsString('Working_calendar_service::resolveForMonth', $hr,
            'payroll must NOT fall back to a tenant-wide calendar for everyone');
    }

    public function test_both_payroll_writers_pass_the_staff_id(): void
    {
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertSame(2, substr_count($hr,
            '_staff_working_days($monthNum, (int) $year, $holidays, $jd, $xd, $staffId, $department)'),
            'generate and regenerate must both resolve per employee, with department for the new resolver');
    }

    /**
     * Originally asserted `assignmentsForRun()` — superseded by the resolver
     * convergence: staffId-grouping silently dropped every department-level
     * assignment (a department attachment has no staffId field at all), so
     * Payroll now reads the flat, all-levels list via `allAssignmentsForRun()`.
     */
    public function test_assignments_are_loaded_once_per_run_not_per_employee(): void
    {
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        // Memoised bulk read — a per-employee query would be an N+1 across the roster.
        $this->assertStringContainsString('function _schedule_assignments', $hr);
        $this->assertStringContainsString('allAssignmentsForRun()', $hr);
        $this->assertStringNotContainsString('->assignmentsFor($staffId)', $hr,
            'payroll must not read assignments one employee at a time');
    }

    public function test_both_payroll_writers_enforce_the_schedule_block(): void
    {
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertSame(2, substr_count($hr, "!empty(\$swd['calBlocked'])"),
            'generate skips the employee; regenerate errors — both must gate');
    }

    public function test_both_snapshots_freeze_the_schedule_identity(): void
    {
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        foreach (["'schedule_id'", "'schedule_name'", "'assignment_effective_from'",
                  "'working_mask'", "'policy_version'"] as $field) {
            $this->assertSame(2, substr_count($hr, $field),
                "{$field} must be frozen by BOTH slip writers — regeneration merges over the "
                . 'old slip, so an un-restated field would survive describing the previous calendar');
        }
    }

    public function test_assignments_are_read_in_bulk_for_a_run(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Work_schedule_service.php');
        $this->assertStringContainsString('function allAssignmentsForRun', $src,
            'a per-employee read would be an N+1 across the roster');
    }
}
