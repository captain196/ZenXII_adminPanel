<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
require_once __DIR__ . '/../../application/helpers/effective_dating_helper.php';
require_once __DIR__ . '/../../application/libraries/Working_calendar_service.php';
require_once __DIR__ . '/../../application/libraries/Holiday_service.php';

/**
 * Workforce Policy Programme Phase D (2026-08-18) — School Calendar
 * applicability + the canonical date-resolution precedence.
 *
 * THE PRECEDENCE MODEL UNDER TEST (most specific wins):
 *   1. Special Working Day  -> WORKING, overrides everything below
 *   2. Weekly Off / Nth-weekday Off (Work Schedule)  -> NON-WORKING
 *   3. Holiday (School Calendar)                      -> NON-WORKING
 *   4. Half Day (School Calendar)                      -> WORKING, informational
 *   5. default                                          -> WORKING
 *
 * exceptStaff is a filter applied BEFORE matching, not a precedence tier —
 * on any event type, "who this event does not apply to". departments/
 * exceptStaff are staff-only concepts, never consulted for audience=students.
 *
 * Calendar-level Half Day is a DECLARED FACT only in this phase — it counts
 * toward workingCount exactly like a normal working day (no Payroll
 * arithmetic change), distinct from Attendance's own hours-based half-day
 * mark ('M') and from Leave's half-day-leave concept, neither of which this
 * phase touches.
 */
class CalendarApplicabilityPhaseDTest extends TestCase
{
    // ── Holiday_service — filtering engine (via Reflection on a fake map) ──

    private function svc(): \Holiday_service
    {
        return new \Holiday_service();
    }

    private function matchName(\Holiday_service $svc, array $map, string $date, string $audience, string $department = '', string $staffId = ''): ?string
    {
        $ref = new \ReflectionClass($svc);
        $m = $ref->getMethod('_matches_on');
        $m->setAccessible(true);
        return $m->invoke($svc, $map, $date, $audience, $department, $staffId);
    }

    public function test_department_scoped_event_only_matches_its_department(): void
    {
        $svc = $this->svc();
        $map = ['2026-10-20' => [
            ['name' => 'Teacher Training Day', 'appliesTo' => ['staff'], 'departments' => ['Teaching'], 'exceptStaff' => []],
        ]];
        $this->assertSame('Teacher Training Day', $this->matchName($svc, $map, '2026-10-20', 'staff', 'Teaching'));
        $this->assertNull($this->matchName($svc, $map, '2026-10-20', 'staff', 'Security'));
    }

    public function test_students_vs_staff_appliesto_is_a_hard_filter(): void
    {
        $svc = $this->svc();
        $map = ['2026-10-20' => [
            ['name' => 'Teacher Training Day', 'appliesTo' => ['staff'], 'departments' => [], 'exceptStaff' => []],
        ]];
        $this->assertNull($this->matchName($svc, $map, '2026-10-20', 'students'),
            'A staff-only event must never apply to students — the Teacher Training Day scenario.');
    }

    public function test_empty_appliesto_means_students_and_staff_both(): void
    {
        $svc = $this->svc();
        $map = ['2026-10-25' => [
            ['name' => 'Diwali', 'appliesTo' => [], 'departments' => [], 'exceptStaff' => []],
        ]];
        $this->assertSame('Diwali', $this->matchName($svc, $map, '2026-10-25', 'students'));
        $this->assertSame('Diwali', $this->matchName($svc, $map, '2026-10-25', 'staff', 'AnyDept'));
    }

    public function test_empty_departments_means_every_department(): void
    {
        $svc = $this->svc();
        $map = ['2026-10-25' => [
            ['name' => 'School Holiday', 'appliesTo' => ['staff'], 'departments' => [], 'exceptStaff' => []],
        ]];
        $this->assertSame('School Holiday', $this->matchName($svc, $map, '2026-10-25', 'staff', 'Security'));
    }

    public function test_except_staff_excludes_regardless_of_audience_department_match(): void
    {
        // Essential-staff opt-in on a holiday.
        $svc = $this->svc();
        $map = ['2026-10-25' => [
            ['name' => 'General Holiday', 'appliesTo' => [], 'departments' => [], 'exceptStaff' => ['STA0099']],
        ]];
        $this->assertSame('General Holiday', $this->matchName($svc, $map, '2026-10-25', 'staff', 'Any', 'STA0001'));
        $this->assertNull($this->matchName($svc, $map, '2026-10-25', 'staff', 'Any', 'STA0099'));
    }

    public function test_except_staff_symmetric_on_special_working_day(): void
    {
        // One person stays off even though the SWD applies to the department.
        $svc = $this->svc();
        $map = ['2026-10-25' => [
            ['name' => 'Makeup Saturday', 'appliesTo' => ['staff'], 'departments' => ['Support'], 'exceptStaff' => ['STA0050']],
        ]];
        $this->assertSame('Makeup Saturday', $this->matchName($svc, $map, '2026-10-25', 'staff', 'Support', 'STA0001'));
        $this->assertNull($this->matchName($svc, $map, '2026-10-25', 'staff', 'Support', 'STA0050'));
    }

    public function test_department_and_except_staff_ignored_for_student_audience(): void
    {
        // Even if a staff-scoped filter is present, a students-applicable
        // event must not be narrowed by fields that only mean something for
        // staff — departments/exceptStaff are staff-only concepts.
        $svc = $this->svc();
        $map = ['2026-10-25' => [
            ['name' => 'Both', 'appliesTo' => ['students', 'staff'], 'departments' => ['Teaching'], 'exceptStaff' => ['STU0001']],
        ]];
        $this->assertSame('Both', $this->matchName($svc, $map, '2026-10-25', 'students'));
    }

    // ── Working_calendar_service — the full precedence engine ─────────────

    private function version(array $weeklyOffs = ['Sun']): array
    {
        return ['weeklyOffs' => $weeklyOffs, 'version' => 1, 'effectiveFrom' => '2026-01-01'];
    }

    public function test_special_working_day_overrides_weekly_off(): void
    {
        $inWindow = array_fill_keys(range(1, 31), true);
        // Aug 2026, day 2 = Sunday.
        $r = \Working_calendar_service::resolveFromVersion(
            $this->version(['Sun']), 2026, 8, [], $inWindow, [2 => 'Makeup Sunday'], []);
        $this->assertSame(\Working_calendar_service::REASON_SPECIAL_WORKING_DAY, $r['reason'][2]);
        $this->assertTrue($r['isWorking'][2]);
        $this->assertSame(\Working_calendar_service::MASK_WORKING, $r['workingMask'][1]);
    }

    public function test_special_working_day_overrides_holiday(): void
    {
        $inWindow = array_fill_keys(range(1, 31), true);
        $r = \Working_calendar_service::resolveFromVersion(
            $this->version(['Sun']), 2026, 8, [15 => 'Independence Day'], $inWindow, [15 => 'Makeup'], []);
        $this->assertSame(\Working_calendar_service::REASON_SPECIAL_WORKING_DAY, $r['reason'][15]);
        $this->assertTrue($r['isWorking'][15]);
    }

    public function test_holiday_beats_half_day_when_both_apply(): void
    {
        // A "half day" declared on top of an already-full-holiday date is moot.
        $inWindow = array_fill_keys(range(1, 31), true);
        $r = \Working_calendar_service::resolveFromVersion(
            $this->version(['Sun']), 2026, 8, [15 => 'Independence Day'], $inWindow, [], [15 => 'Half Day (moot)']);
        $this->assertSame(\Working_calendar_service::REASON_HOLIDAY, $r['reason'][15]);
    }

    public function test_weekly_off_beats_half_day_when_both_apply(): void
    {
        $inWindow = array_fill_keys(range(1, 31), true);
        // Day 2 = Sunday, also (mistakenly) declared half-day.
        $r = \Working_calendar_service::resolveFromVersion(
            $this->version(['Sun']), 2026, 8, [], $inWindow, [], [2 => 'Half Day (moot)']);
        $this->assertSame(\Working_calendar_service::REASON_WEEKLY_OFF, $r['reason'][2]);
    }

    public function test_half_day_applies_to_an_otherwise_normal_working_day(): void
    {
        $inWindow = array_fill_keys(range(1, 31), true);
        // Day 20 (Thursday) is otherwise a normal working day.
        $r = \Working_calendar_service::resolveFromVersion(
            $this->version(['Sun']), 2026, 8, [], $inWindow, [], [20 => 'Founders Half Day']);
        $this->assertSame(\Working_calendar_service::REASON_HALF_DAY, $r['reason'][20]);
        $this->assertTrue($r['isWorking'][20],
            'Half day counts toward workingCount exactly like a normal working day — Phase D makes no Payroll arithmetic change.');
        $this->assertSame(\Working_calendar_service::MASK_HALF, $r['workingMask'][19]);
        $this->assertSame(1, $r['halfDayCount']);
    }

    public function test_weekly_off_and_holiday_overlap_unchanged_weekly_off_wins_the_label(): void
    {
        // Pre-Phase-D behavior, re-asserted: unaffected by this phase's additions.
        $inWindow = array_fill_keys(range(1, 31), true);
        $r = \Working_calendar_service::resolveFromVersion(
            $this->version(['Sun']), 2026, 8, [2 => 'Coincidental Holiday'], $inWindow);
        $this->assertSame(\Working_calendar_service::REASON_WEEKLY_OFF, $r['reason'][2]);
    }

    public function test_backward_compatible_when_swd_and_half_day_omitted(): void
    {
        $inWindow = array_fill_keys(range(1, 31), true);
        $withDefaults = \Working_calendar_service::resolveFromVersion($this->version(['Sun']), 2026, 8, [15 => 'H'], $inWindow);
        $withEmpty    = \Working_calendar_service::resolveFromVersion($this->version(['Sun']), 2026, 8, [15 => 'H'], $inWindow, [], []);
        $this->assertSame($withDefaults, $withEmpty);
    }

    public function test_working_count_arithmetic_identical_to_pre_phase_d_when_nothing_declared(): void
    {
        $inWindow = array_fill_keys(range(1, 31), true);
        $r = \Working_calendar_service::resolveFromVersion($this->version(['Sun']), 2026, 8, [], $inWindow);
        // Aug 2026 has 5 Sundays (2,9,16,23,30) -> 26 working days.
        $this->assertSame(26, $r['workingCount']);
        $this->assertSame(0, $r['halfDayCount']);
        $this->assertSame(0, $r['specialWorkingDayCount']);
    }

    // ── Architecture guard ─────────────────────────────────────────────────

    public function test_calendar_service_never_calls_policy_assignment_service(): void
    {
        // Calendar events are filtered by Holiday_service (a filter, not a
        // resolver) — must never be routed through Policy_assignment_service,
        // which stays exclusively responsible for Work Schedule assignment.
        $src = (string) file_get_contents(__DIR__ . '/../../application/libraries/Calendar_service.php');
        $this->assertStringNotContainsString('Policy_assignment_service', $src);
        $holSrc = (string) file_get_contents(__DIR__ . '/../../application/libraries/Holiday_service.php');
        $this->assertStringNotContainsString('Policy_assignment_service', $holSrc);
    }
}
