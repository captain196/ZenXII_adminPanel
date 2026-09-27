<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Workforce Policy Programme Phase D (2026-08-18) — consumer wiring.
 * Confirms every Holiday_service/Working_calendar_service consumer was
 * actually updated to the new audience/department/staffId-aware calls,
 * confirms the staff/student boundary is preserved, and confirms nothing
 * touches Payroll's salary-calculation arithmetic, Leave, or Graded RBAC.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller code — see StaffCreationAuditMetadataTest's docblock).
 */
class WorkforcePolicyPhaseDConsumersTest extends TestCase
{
    private function codeOnly(string $path): string
    {
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw, "Could not read {$path}");
        $out = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        return $out;
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

    // ── Payroll (Hr.php / Work_schedule_service.php) ──────────────────────

    /**
     * The Phase D per-staff/per-department calendar filtering MOVED on
     * 2026-08-22 (N-40) — it did not change.
     *
     * `_staff_working_days()` now splits the employment window into consecutive
     * same-department segments and calls `_resolve_segment_calendar()` once per
     * segment, so payroll resolves department per day like Leave and Attendance
     * already did. The filtering itself is byte-identical; it simply runs once
     * per segment instead of once per month.
     *
     * This test therefore scans the new home. The invariant is unchanged and
     * arguably stronger: each segment is filtered for ITS OWN department rather
     * than the whole month inheriting the first one.
     */
    public function test_hr_staff_working_days_resolves_per_staff_filtered_calendar(): void
    {
        $src = $this->codeOnly(__DIR__ . '/../../application/controllers/Hr.php');

        $start = strpos($src, 'function _resolve_segment_calendar(');
        $this->assertNotFalse($start, 'the extracted per-segment calendar resolver must exist');
        $body = $this->extractFunctionBody($src, $start);

        $this->assertStringContainsString("holidays_in_month(\$year, \$monthNum, 'staff', \$department, \$staffId)", $body);
        $this->assertStringContainsString("special_working_days_in_month(\$year, \$monthNum, 'staff', \$department, \$staffId)", $body);
        $this->assertStringContainsString("half_days_in_month(\$year, \$monthNum, 'staff', \$department, \$staffId)", $body);
        $this->assertStringContainsString('$staffHolidays, $window, $department,', $body);
    }

    /** And the caller must actually segment, rather than resolving once. */
    public function test_hr_staff_working_days_segments_the_month_by_department(): void
    {
        $src = $this->codeOnly(__DIR__ . '/../../application/controllers/Hr.php');
        $start = strpos($src, 'function _staff_working_days(');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($src, $start);

        $this->assertStringContainsString('_department_segments($staffId, $year, $monthNum', $body,
            'the month must be split by department, not resolved once at period start');
        $this->assertStringContainsString('_resolve_segment_calendar(', $body);
    }

    public function test_hr_never_uses_students_audience(): void
    {
        // Payroll is a staff-only domain; catches an accidental copy-paste
        // of the wrong audience literal.
        $src = $this->codeOnly(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertStringNotContainsString("'students'", $src);
    }

    public function test_work_schedule_service_threads_swd_and_half_days_through_unchanged(): void
    {
        $src = $this->codeOnly(__DIR__ . '/../../application/libraries/Work_schedule_service.php');
        $this->assertStringContainsString('array $specialWorkingDays = []', $src);
        $this->assertStringContainsString('array $halfDays = []', $src);
        $this->assertStringContainsString('$schedule, $year, $monthNum, $holidays, $inWindow, $specialWorkingDays, $halfDays', $src);
    }

    public function test_no_payroll_salary_calculation_touched(): void
    {
        // _compute_payslip / retention-fraction arithmetic must be absent
        // from every file this phase touched outside Hr.php itself, and
        // even within Hr.php, the calendar-resolution function added this
        // phase must not reference the payslip money computation.
        $src = $this->codeOnly(__DIR__ . '/../../application/controllers/Hr.php');
        $start = strpos($src, 'function _staff_working_days(');
        $body = $this->extractFunctionBody($src, $start);
        $this->assertStringNotContainsString('_compute_payslip', $body);
        $this->assertStringNotContainsString('retention', $body);
        $this->assertStringNotContainsString('daysWorked', $body);
    }

    // ── Staff Attendance punch path ────────────────────────────────────────

    public function test_punch_path_resolves_department_scoped_holiday_and_swd(): void
    {
        $src = $this->codeOnly(__DIR__ . '/../../application/controllers/Staff_attendance.php');
        $start = strpos($src, 'public function punch(');
        if ($start === false) $start = strpos($src, 'function punch(');
        $this->assertNotFalse($start, 'punch() must exist.');
        $body = $this->extractFunctionBody($src, $start);
        $this->assertStringContainsString("is_holiday(\$dateISO, 'staff', \$calDept, \$staffId)", $body);
        $this->assertStringContainsString("is_special_working_day(\$dateISO, 'staff', \$calDept, \$staffId)", $body);
        $this->assertStringContainsString("'isSpecialWorkingDay'", $body);
    }

    public function test_resolve_employee_calendar_exposes_department_for_calendar_filtering(): void
    {
        $src = $this->codeOnly(__DIR__ . '/../../application/controllers/Staff_attendance.php');
        $start = strpos($src, 'function _resolve_employee_calendar(');
        $body = $this->extractFunctionBody($src, $start);
        // Every return point (cached-context block/success, fresh-resolve
        // block/success, and the catch-all error branch) must expose
        // 'department' => ... in its returned array — none may omit it.
        $this->assertSame(4, substr_count($body, "'department' =>"));
    }

    public function test_overlay_daywise_is_special_working_day_aware(): void
    {
        // Trail 2 Phase D, Finding #1 (2026-08-18): the overlay logic was
        // extracted into the shared Staff_attendance_overlay class (reused
        // by the admin grid, so it can no longer disagree with this
        // self-view) — Staff_attendance::_overlay_daywise() is now a thin
        // wrapper. The SWD-awareness itself is unchanged, just relocated.
        $wrapperSrc = $this->codeOnly(__DIR__ . '/../../application/controllers/Staff_attendance.php');
        $wrapperStart = strpos($wrapperSrc, 'function _overlay_daywise(');
        $wrapperBody = $this->extractFunctionBody($wrapperSrc, $wrapperStart);
        $this->assertStringContainsString('Staff_attendance_overlay::apply(', $wrapperBody);

        $sharedSrc = $this->codeOnly(__DIR__ . '/../../application/libraries/Staff_attendance_overlay.php');
        $sharedStart = strpos($sharedSrc, 'function apply(');
        $sharedBody = $this->extractFunctionBody($sharedSrc, $sharedStart);
        $this->assertStringContainsString('is_special_working_day(', $sharedBody);
        $this->assertStringContainsString('if ($isSwd) {', $sharedBody);
    }

    // ── Attendance_policy — the SWD gate ───────────────────────────────────

    public function test_attendance_policy_swd_gate_precedes_rest_day_gate(): void
    {
        $src = $this->codeOnly(__DIR__ . '/../../application/libraries/Attendance_policy.php');
        $start = strpos($src, 'function evaluate(');
        $body = $this->extractFunctionBody($src, $start);
        $swdPos = strpos($body, "context['isSpecialWorkingDay']");
        // Gate 6b's own code (comments are stripped by codeOnly()): the
        // isHoliday/isWeeklyOff rest-day check.
        $gate6bPos = strpos($body, "\$isHoliday   = !empty(\$context['isHoliday'])");
        $this->assertNotFalse($swdPos);
        $this->assertNotFalse($gate6bPos);
        $this->assertLessThan($gate6bPos, $swdPos,
            'A Special Working Day must be checked BEFORE the rest-day (weekly-off/holiday) gate, so it never takes the W extra-pay path.');
    }

    public function test_special_working_day_classifies_via_normal_checkin_checkout_not_extra(): void
    {
        $src = $this->codeOnly(__DIR__ . '/../../application/libraries/Attendance_policy.php');
        $start = strpos($src, 'function evaluate(');
        $body = $this->extractFunctionBody($src, $start);
        $swdBlockStart = strpos($body, "context['isSpecialWorkingDay']");
        $swdBlockEnd = strpos($body, "\$isHoliday   = !empty(\$context['isHoliday'])", $swdBlockStart);
        $swdBlock = substr($body, $swdBlockStart, $swdBlockEnd - $swdBlockStart);
        $this->assertStringContainsString('_evaluate_checkin', $swdBlock);
        $this->assertStringContainsString('_evaluate_checkout', $swdBlock);
        $this->assertStringNotContainsString('STATUS_EXTRA', $swdBlock,
            'A Special Working Day punch must classify as a normal day (P/T/M), never the rest-day EXTRA (W) path.');
    }

    // ── Finaliser ────────────────────────────────────────────────────────

    public function test_finaliser_overlay_checks_swd_before_holiday(): void
    {
        $src = $this->codeOnly(__DIR__ . '/../../application/libraries/Staff_attendance_finaliser.php');
        $start = strpos($src, 'function _overlay(');
        $body = $this->extractFunctionBody($src, $start);
        $swdPos = strpos($body, 'if ($isSwd) {');
        $holPos = strpos($body, 'elseif ($isHol) {');
        $this->assertNotFalse($swdPos);
        $this->assertNotFalse($holPos);
        $this->assertLessThan($holPos, $swdPos);
    }

    public function test_finaliser_non_working_days_excludes_swd_overridden_days(): void
    {
        $src = $this->codeOnly(__DIR__ . '/../../application/libraries/Staff_attendance_finaliser.php');
        $start = strpos($src, 'function _nonWorkingDays(');
        $body = $this->extractFunctionBody($src, $start);
        $this->assertStringContainsString('is_special_working_day(', $body);
    }

    public function test_finaliser_holiday_check_is_department_scoped(): void
    {
        $src = $this->codeOnly(__DIR__ . '/../../application/libraries/Staff_attendance_finaliser.php');
        $start = strpos($src, 'function _overlay(');
        $body = $this->extractFunctionBody($src, $start);
        $this->assertStringContainsString("is_holiday(\$dateISO, 'staff', \$department, \$staffId)", $body);
    }

    // ── Attendance.php — staff grid vs student endpoints boundary ─────────

    public function test_staff_grid_uses_per_staff_department_scoped_calendar(): void
    {
        $src = $this->codeOnly(__DIR__ . '/../../application/controllers/Attendance.php');
        $start = strpos($src, 'function _staff_grid_schedule(');
        $body = $this->extractFunctionBody($src, $start);
        $this->assertStringContainsString("special_working_days_in_month(", $body);
        $this->assertStringContainsString("holidays_in_month(", $body);
        // Trail 2 Priority 1 (2026-08-18): the month-batch holiday/SWD calls
        // now resolve department AS OF the period start via
        // Staff_employment_history_service::resolveAttributeAsOf(), instead
        // of the live/cached $department value — see
        // AttendanceDepartmentEffectiveDatingTest for the dedicated coverage
        // of that fix. The variable is still per-staff-department-scoped,
        // just renamed and resolved as-of-date rather than read live.
        $this->assertStringContainsString("'staff', \$periodStartDept, \$staffId", $body);
        $this->assertStringContainsString('if (isset($swdMonth[$d])) continue;', $body,
            'A Special Working Day must remove that date from nonWorkingDays, not just be reported separately.');
    }

    public function test_student_endpoints_pass_explicit_students_audience(): void
    {
        $src = $this->codeOnly(__DIR__ . '/../../application/controllers/Attendance.php');
        foreach (['_resolve_non_working_days(', '_get_holidays_for_month('] as $fn) {
            $start = strpos($src, 'function ' . $fn);
            $this->assertNotFalse($start, "{$fn} must exist.");
            $body = $this->extractFunctionBody($src, $start);
            $this->assertStringContainsString("'students'", $body,
                "{$fn} must pass the explicit student audience so a staff-only calendar event is never inherited.");
        }
    }

    public function test_student_holiday_helpers_never_pass_department_or_staff_filters(): void
    {
        // Department/exceptStaff are staff-only concepts — the student
        // helpers must not attempt to use them.
        $src = $this->codeOnly(__DIR__ . '/../../application/controllers/Attendance.php');
        foreach (['_resolve_non_working_days(', '_get_holidays_for_month('] as $fn) {
            $start = strpos($src, 'function ' . $fn);
            $body = $this->extractFunctionBody($src, $start);
            $this->assertStringNotContainsString('$department', $body);
        }
    }

    public function test_staff_attendance_remains_separate_from_student_attendance(): void
    {
        // The staff grid function must never touch the student-side
        // collections/helpers, and vice versa.
        $src = $this->codeOnly(__DIR__ . '/../../application/controllers/Attendance.php');
        $start = strpos($src, 'function _staff_grid_schedule(');
        $body = $this->extractFunctionBody($src, $start);
        $this->assertStringNotContainsString('nw_days_from_holidays', $body);
        $this->assertStringNotContainsString("'students'", $body);
    }

    // ── Calendar admin surface (Calendar_service / Academic.php) ──────────

    public function test_calendar_service_backward_compatible_defaults(): void
    {
        $src = $this->codeOnly(__DIR__ . '/../../application/libraries/Calendar_service.php');
        $start = strpos($src, 'function saveEvent(');
        $body = $this->extractFunctionBody($src, $start);
        // Absent fields must decode to [] (no restriction), never a
        // non-empty default the way visibleTo gets one.
        $this->assertStringNotContainsString("if (!is_array(\$appliesTo) || empty(\$appliesTo)) \$appliesTo = [", $body);
    }

    public function test_academic_controller_passes_through_new_fields_without_forcing_them(): void
    {
        $src = $this->codeOnly(__DIR__ . '/../../application/controllers/Academic.php');
        $start = strpos($src, 'function save_event(');
        $body = $this->extractFunctionBody($src, $start);
        $this->assertStringContainsString("\$this->input->post('appliesTo')", $body);
        $this->assertStringContainsString("\$this->input->post('departments')", $body);
        $this->assertStringContainsString("\$this->input->post('exceptStaff')", $body);
    }

    // ── No RBAC, no RTDB, no new competing resolver ────────────────────────

    public function test_no_rbac_changes_anywhere_touched_this_phase(): void
    {
        $files = [
            'application/libraries/Calendar_service.php',
            'application/libraries/Holiday_service.php',
            'application/libraries/Working_calendar_service.php',
            'application/libraries/Work_schedule_service.php',
            'application/libraries/Attendance_policy.php',
            'application/libraries/Staff_attendance_finaliser.php',
        ];
        foreach ($files as $rel) {
            $src = $this->codeOnly(__DIR__ . '/../../' . $rel);
            $this->assertStringNotContainsString('token.role', $src, $rel);
            $this->assertStringNotContainsString("_require_role", $src, $rel . ' (libraries never gate RBAC directly)');
        }
    }

    public function test_no_rtdb_introduced(): void
    {
        $files = [
            'application/libraries/Calendar_service.php',
            'application/libraries/Holiday_service.php',
            'application/libraries/Working_calendar_service.php',
        ];
        foreach ($files as $rel) {
            $src = $this->codeOnly(__DIR__ . '/../../' . $rel);
            foreach (['getDatabase', 'firebaseio', 'database()->'] as $needle) {
                $this->assertStringNotContainsString($needle, $src, $rel);
            }
        }
    }

    public function test_no_second_calendar_resolution_engine(): void
    {
        // Working_calendar_service must remain the ONLY place per-day
        // weekly-off/holiday/SWD/half-day arithmetic happens.
        $src = $this->codeOnly(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertStringNotContainsString("date('w'", $src);
    }
}
