<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Workforce Policy Programme Phase D — Student Attendance Special Working
 * Day wiring (2026-08-18). Phase D's own report disclosed this as a known
 * gap: Holiday_service already resolved SWD for the 'students' audience
 * (Calendar/Academic UI could declare one), but the student-side write
 * choke point (_resolve_non_working_days()) never consulted it, so a
 * calendar-declared makeup day stayed force-stamped 'H' by
 * enforce_holidays_on_string() and stayed locked client-side. This closes
 * that gap using the SAME precedence already proven for staff
 * (Working_calendar_service / CalendarApplicabilityPhaseDTest): Special
 * Working Day overrides Sunday/holiday.
 *
 * Explicitly NOT in scope here (unchanged, still staff-only): department /
 * exceptStaff filtering. Student Attendance must stay its own domain and
 * never inherit Staff Work Schedule or staff-specific rules — every student-
 * side call site passes audience='students' and no department/staffId, so
 * Holiday_service's _matches_on() never applies the staff-only filters
 * (proven generically by CalendarApplicabilityPhaseDTest::
 * test_department_and_except_staff_ignored_for_student_audience()).
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller code; the view is HTML+JS — same comment-stripping convention
 * as StaffAttendanceGridPhaseDTest uses for staff.php).
 */
class StudentAttendanceSpecialWorkingDayTest extends TestCase
{
    private string $attSrc;
    private string $viewSrc;

    protected function setUp(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../application/controllers/Attendance.php');
        $this->assertNotFalse($raw);
        $out = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        $this->attSrc = $out;

        $this->viewSrc = file_get_contents(__DIR__ . '/../../application/views/attendance/student.php');
        $this->assertNotFalse($this->viewSrc);
    }

    private function extractFunctionBody(string $src, int $functionKeywordOffset): string
    {
        $openBrace = strpos($src, '{', $functionKeywordOffset);
        $this->assertNotFalse($openBrace);
        $depth = 0;
        $len = strlen($src);
        for ($i = $openBrace; $i < $len; $i++) {
            if ($src[$i] === '{') $depth++;
            elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) return substr($src, $openBrace, $i - $openBrace + 1);
            }
        }
        $this->fail('Unbalanced braces while extracting function body.');
    }

    private function functionBody(string $needle): string
    {
        $start = strpos($this->attSrc, $needle);
        $this->assertNotFalse($start, "Expected to find: {$needle}");
        return $this->extractFunctionBody($this->attSrc, $start);
    }

    // ── Backend: the single write choke point ──────────────────────────────

    public function test_resolve_non_working_days_consults_swd_for_students(): void
    {
        $body = $this->functionBody('private function _resolve_non_working_days(');
        $this->assertStringContainsString(
            "\$this->holiday_service->special_working_days_in_month(\$year, \$monthNum, 'students');",
            $body,
            'Must resolve Special Working Days with the explicit students audience — never staff/department-filtered.'
        );
    }

    public function test_resolve_non_working_days_unsets_swd_days_from_the_non_working_map(): void
    {
        $body = $this->functionBody('private function _resolve_non_working_days(');
        $this->assertStringContainsString('foreach ($swdDayMap as $day => $name) {', $body);
        $this->assertStringContainsString('unset($nonWorking[(int) $day]);', $body);
        // Must come AFTER building the Sunday+holiday map, i.e. SWD overrides it.
        $buildPos  = strpos($body, 'nw_days_from_holidays(');
        $unsetPos  = strpos($body, 'unset($nonWorking');
        $this->assertLessThan($unsetPos, $buildPos,
            'SWD must be applied after (and so override) the Sunday/holiday map, matching the staff-side precedence.');
    }

    public function test_resolve_non_working_days_fails_safe_on_swd_lookup_error(): void
    {
        // $swdDayMap must be initialised to [] BEFORE the try block, so a
        // thrown exception degrades to "no SWD" (unchanged, pre-existing
        // Sunday/holiday behaviour) rather than an unset-variable notice.
        $body = $this->functionBody('private function _resolve_non_working_days(');
        $swdInitPos = strpos($body, '$swdDayMap = [];');
        $tryPos     = strpos($body, 'try {');
        $this->assertNotFalse($swdInitPos);
        $this->assertLessThan($tryPos, $swdInitPos);
    }

    public function test_get_special_working_days_for_month_uses_students_audience(): void
    {
        $body = $this->functionBody('private function _get_special_working_days_for_month(');
        $this->assertStringContainsString(
            "\$this->holiday_service->special_working_days_in_month(\$year, \$monthNum, 'students');",
            $body
        );
    }

    public function test_fetch_student_attendance_exposes_special_working_days_on_both_branches(): void
    {
        // Empty-roster early-return branch AND the normal populated-roster
        // branch both need the field — a teacher opening an empty section on
        // an SWD date must see the same unlock as a populated one.
        $this->assertSame(
            2,
            substr_count($this->attSrc, "'specialWorkingDays' => \$this->_get_special_working_days_for_month(\$month, \$year),"),
            'Both fetch_student_attendance() response branches must expose specialWorkingDays.'
        );
    }

    public function test_student_side_never_passes_department_or_staff_id(): void
    {
        // Student Attendance must stay its own domain — never resolve SWD
        // with staff-only department/exceptStaff scoping.
        $this->assertStringNotContainsString(
            "special_working_days_in_month(\$year, \$monthNum, 'staff'",
            $this->attSrc
        );
    }

    // ── Frontend: per-day unlock ─────────────────────────────────────────

    private function viewCode(): string
    {
        $v = $this->viewSrc;
        $v = preg_replace('/<!--.*?-->/s', '', $v);
        $v = preg_replace('~/\*.*?\*/~s', '', $v);
        $v = preg_replace('~^\s*//.*$~m', '', $v);
        return (string) $v;
    }

    public function test_state_has_special_working_days_map(): void
    {
        $code = $this->viewCode();
        $this->assertStringContainsString('specialWorkingDays: {}', $code);
    }

    public function test_load_populates_special_working_days(): void
    {
        $code = $this->viewCode();
        $this->assertStringContainsString('state.specialWorkingDays = res.specialWorkingDays', $code);
    }

    public function test_date_lock_reason_checks_swd_before_sunday_and_holiday_and_unlocks(): void
    {
        $code = $this->viewCode();
        $fnStart = strpos($code, 'function dateLockReason(day) {');
        $this->assertNotFalse($fnStart);
        $fnBody = substr($code, $fnStart, 700);
        $swdPos     = strpos($fnBody, 'state.specialWorkingDays');
        $sundayPos  = strpos($fnBody, "return 'sunday'");
        $holidayPos = strpos($fnBody, "return 'holiday'");
        $this->assertNotFalse($swdPos);
        $this->assertLessThan($sundayPos, $swdPos);
        $this->assertLessThan($holidayPos, $swdPos);
        $this->assertMatchesRegularExpression(
            '/if \(state\.specialWorkingDays && state\.specialWorkingDays\[day\] !== undefined\) return null;/',
            $fnBody
        );
    }

    public function test_grid_shading_treats_swd_as_working_not_sunday_or_holiday(): void
    {
        $code = $this->viewCode();
        // Both the header loop and the body-cell loop must check swdSet
        // first and skip the sun/hol classes when it applies.
        $this->assertSame(2, substr_count($code, 'if (swdSet[d] !== undefined) {') + substr_count($code, 'if (swdSet[day] !== undefined) {'));
        $this->assertStringContainsString("cls += ' sa-col-swd';", $code);
        $this->assertStringContainsString("tdCls += ' sa-col-swd';", $code);
    }
}
