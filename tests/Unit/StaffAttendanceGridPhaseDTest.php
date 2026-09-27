<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Workforce Policy Programme Phase D (2026-08-18) — staff attendance grid
 * view, updated from the flat school-wide holiday map (Phase B/N-35) to
 * per-staff, department/exception-filtered holidays, plus Special Working
 * Day support (unlocks a cell rather than locking it).
 *
 * Source-regression tests (view is HTML+JS — same comment-stripping
 * convention as WorkSchedulesSurfaceTest uses for Work_schedules.php's view).
 */
class StaffAttendanceGridPhaseDTest extends TestCase
{
    private string $viewSrc;

    protected function setUp(): void
    {
        $this->viewSrc = file_get_contents(__DIR__ . '/../../application/views/attendance/staff.php');
        $this->assertNotFalse($this->viewSrc);
    }

    private function viewCode(): string
    {
        $v = $this->viewSrc;
        $v = preg_replace('/<!--.*?-->/s', '', $v);
        $v = preg_replace('~/\*.*?\*/~s', '', $v);
        $v = preg_replace('~^\s*//.*$~m', '', $v);
        return (string) $v;
    }

    public function test_state_no_longer_has_a_flat_holidays_map(): void
    {
        $code = $this->viewCode();
        $this->assertStringNotContainsString('holidays: {}', $code);
        $this->assertStringContainsString('holidayDays: {}', $code);
        $this->assertStringContainsString('specialWorkingDays: {}', $code);
    }

    public function test_load_populates_per_staff_calendar_fields(): void
    {
        $code = $this->viewCode();
        $this->assertStringContainsString('state.holidayDays = res.holidayDays', $code);
        $this->assertStringContainsString('state.specialWorkingDays = res.specialWorkingDays', $code);
        $this->assertStringNotContainsString('res.holidays', $code);
    }

    public function test_date_lock_reason_checks_swd_before_weekly_off_and_holiday(): void
    {
        $code = $this->viewCode();
        $fnStart = strpos($code, 'function dateLockReason(staffId, day)');
        $this->assertNotFalse($fnStart);
        $fnBody = substr($code, $fnStart, 900);
        $swdPos = strpos($fnBody, 'specialWorkingDays');
        $weeklyOffPos = strpos($fnBody, "return 'weeklyoff'");
        $holidayPos = strpos($fnBody, "return 'holiday'");
        $this->assertNotFalse($swdPos);
        $this->assertLessThan($weeklyOffPos, $swdPos);
        $this->assertLessThan($holidayPos, $swdPos);
    }

    public function test_special_working_day_unlocks_rather_than_locks(): void
    {
        $code = $this->viewCode();
        $fnStart = strpos($code, 'function dateLockReason(staffId, day)');
        $fnBody = substr($code, $fnStart, 900);
        // The SWD branch must return null (unlocked), not a lock reason string.
        $this->assertMatchesRegularExpression(
            '/if \(swd && swd\[day\] !== undefined\) return null;/', $fnBody);
    }

    public function test_header_no_longer_shades_a_shared_holiday_column(): void
    {
        $code = $this->viewCode();
        $headStart = strpos($code, 'function renderGrid()');
        $bodyMarkerPos = strpos($code, '/* Body */', $headStart);
        $header = substr($code, $headStart, $bodyMarkerPos - $headStart);
        $this->assertStringNotContainsString('holidaySet', $header);
    }

    public function test_body_holiday_and_swd_shading_resolved_per_staff(): void
    {
        $code = $this->viewCode();
        $this->assertStringContainsString('state.holidayDays && state.holidayDays[s.id]', $code);
        $this->assertStringContainsString('state.specialWorkingDays && state.specialWorkingDays[s.id]', $code);
        $this->assertStringContainsString('att-col-swd', $code);
    }

    public function test_swd_shading_takes_precedence_over_off_day_shading_in_render(): void
    {
        $code = $this->viewCode();
        $isSwdPos = strpos($code, 'var isSwd = swd[day] !== undefined;');
        $ifSwdPos = strpos($code, 'if (isSwd) {', $isSwdPos);
        $blockedWhyPos = strpos($code, '} else if (blockedWhy) {', $ifSwdPos);
        $this->assertNotFalse($isSwdPos);
        $this->assertNotFalse($ifSwdPos);
        $this->assertNotFalse($blockedWhyPos);
        $this->assertLessThan($blockedWhyPos, $ifSwdPos,
            'SWD shading must be checked first, before the blocked-row/weekly-off/holiday shading branches.');
    }
}
