<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Workforce Policy Programme Phase F (2026-08-18) — Leave calendar-
 * awareness. Before this phase, `Hr::apply_leave()` deducted the RAW
 * calendar-day span for a full-day leave request (`$to->diff($from)->days
 * + 1`), so a 5-day request spanning a weekend + a declared holiday
 * consumed 5 full balance days instead of the true working-day subset —
 * confirmed by the Phase F research trace: leave day-counting had zero
 * Holiday_service/Work_schedule_service/Policy_assignment_service
 * awareness anywhere in Hr.php's leave code.
 *
 * This closes that gap using the SAME canonical resolver Payroll's LOP
 * engine already uses (`Work_schedule_service::resolveEmployeeCalendar`),
 * so leave-balance deduction and LOP can never disagree about "what counts
 * as a working day". `decide_leave()` reads `$request['days']` verbatim
 * (frozen at apply time) — confirmed by the research trace — so this single
 * choke point is sufficient; no change to decide_leave()/cancel_leave()/the
 * Payroll LOP formula was needed or made.
 *
 * Half-day leave is explicitly NOT touched — stays a fixed 0.5, per the
 * separately-authorized Calendar-Half-Day / Attendance-Half-Day / Half-Day-
 * Leave three-way boundary from the School Calendar phase.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller code — see StaffCreationAuditMetadataTest's docblock).
 */
class LeaveCalendarAwarenessTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
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
        $this->src = $out;
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
        $start = strpos($this->src, $needle);
        $this->assertNotFalse($start, "Expected to find: {$needle}");
        return $this->extractFunctionBody($this->src, $start);
    }

    // ── The new resolver helper ─────────────────────────────────────────

    public function test_count_chargeable_leave_days_delegates_to_work_schedule_service(): void
    {
        $body = $this->functionBody('private function _count_chargeable_leave_days(');
        $this->assertStringContainsString('Work_schedule_service::resolveEmployeeCalendar(', $body);
        $this->assertStringContainsString("require_once APPPATH . 'libraries/Work_schedule_service.php';", $body);
    }

    public function test_count_chargeable_leave_days_uses_staff_audience_with_department_and_staff_id(): void
    {
        $body = $this->functionBody('private function _count_chargeable_leave_days(');
        $this->assertStringContainsString("holidays_in_month(\$year, \$monthNum, 'staff', \$department, \$staffId)", $body);
        $this->assertStringContainsString("special_working_days_in_month(\$year, \$monthNum, 'staff', \$department, \$staffId)", $body);
        $this->assertStringContainsString("half_days_in_month(\$year, \$monthNum, 'staff', \$department, \$staffId)", $body);
    }

    public function test_count_chargeable_leave_days_fails_open_to_raw_span_when_unresolvable(): void
    {
        $body = $this->functionBody('private function _count_chargeable_leave_days(');
        // Unavailable holiday_service -> raw span, calendarAware=false.
        $this->assertStringContainsString(
            "return ['days' => \$rawSpan, 'calendarAware' => false, 'blockReason' => 'Holiday service unavailable'];",
            $body
        );
        // Blocked calendar (no/ambiguous work-schedule assignment) -> raw span, calendarAware=false.
        $this->assertStringContainsString("if (!empty(\$cal['blocked'])) {", $body);
        $this->assertStringContainsString(
            "return ['days' => \$rawSpan, 'calendarAware' => false, 'blockReason' => (string) (\$cal['blockReason'] ?? '')];",
            $body
        );
    }

    public function test_count_chargeable_leave_days_sums_isworking_flags_not_calendar_span(): void
    {
        // Trail 2 Priority 2 (2026-08-19) split this into two paths — see
        // LeaveDepartmentEffectiveDatingTest for the dedicated coverage of
        // that split. The common (single-department) path was extracted
        // verbatim into _chargeable_days_single_department() and still uses
        // the original $d/$startDay/$endDay loop variables; the outer
        // function keeps the isWorking-sum contract for its rarer per-day
        // (transfer-crosses-the-span) fallback path, using $day/$cursor.
        // Calendar Half-Day S2 (authorized 2026-08-19): the single-department
        // path no longer adds a flat 1.0 per working day. Leave consumption is
        // measured in EXPECTED-WORK units, so a day the calendar declared a
        // half-day consumes only the half-day weight — you cannot be absent
        // from work that was never expected. The contract this test exists to
        // protect ("sum isWorking, not the raw calendar span") is unchanged
        // and now strictly more precise; only the per-day amount is weighted.
        // With the feature flag OFF the weight is 1.0, so the summed value is
        // identical to before. See CalendarHalfDayMatrixTest scenario T.
        $singleDeptBody = $this->functionBody('private function _chargeable_days_single_department(');
        $this->assertStringContainsString("if (empty(\$cal['isWorking'][\$d])) continue;", $singleDeptBody,
            'must still gate on isWorking rather than counting raw calendar days');
        $this->assertStringContainsString('? $hdWeight : 1.0;', $singleDeptBody,
            'a declared half-day consumes the half-day weight, every other working day 1.0');
        $this->assertStringContainsString("return ['days' => \$chargeable, 'calendarAware' => true, 'blockReason' => ''];", $singleDeptBody);

        $body = $this->functionBody('private function _count_chargeable_leave_days(');
        $this->assertStringContainsString("if (!empty(\$cal['isWorking'][\$day])) \$chargeable += 1.0;", $body);
        $this->assertStringContainsString("return ['days' => \$chargeable, 'calendarAware' => true, 'blockReason' => ''];", $body);
        $this->assertStringContainsString('return $this->_chargeable_days_single_department(', $body,
            'the common no-transfer-in-span case must dispatch to the unchanged original algorithm.');
    }

    // ── apply_leave() wiring ─────────────────────────────────────────────

    public function test_apply_leave_only_recomputes_days_for_full_day_requests(): void
    {
        $body = $this->functionBody('public function apply_leave(');
        $this->assertStringContainsString('if (!$halfDay) {', $body);
        $this->assertStringContainsString(
            '$chargeable = $this->_count_chargeable_leave_days($staffId, $department, $fromDate, $toDate);',
            $body
        );
        $this->assertStringContainsString("\$days = \$chargeable['days'];", $body);
        $this->assertStringContainsString("\$calendarAware = \$chargeable['calendarAware'];", $body);
    }

    public function test_apply_leave_half_day_still_fixed_at_point_five_untouched(): void
    {
        $body = $this->functionBody('public function apply_leave(');
        $this->assertStringContainsString('$days = $halfDay ? 0.5 : (float) $spanDays;', $body);
    }

    public function test_apply_leave_department_read_before_chargeable_day_call(): void
    {
        $body = $this->functionBody('public function apply_leave(');
        $deptPos  = strpos($body, "\$department = (string) (\$staffProfile['Department'] ?? \$staffProfile['department'] ?? '');");
        $chargePos = strpos($body, '$chargeable = $this->_count_chargeable_leave_days(');
        $this->assertNotFalse($deptPos);
        $this->assertNotFalse($chargePos);
        $this->assertLessThan($chargePos, $deptPos, 'department must be resolved from the staff profile before it is used to scope the calendar resolution.');
    }

    public function test_apply_leave_persists_calendar_transparency_fields(): void
    {
        $body = $this->functionBody('public function apply_leave(');
        $this->assertStringContainsString("'calendar_days'       => (float) \$spanDays,", $body);
        $this->assertStringContainsString("'calendar_aware'      => \$calendarAware,", $body);
    }

    public function test_apply_leave_max_span_guard_still_uses_raw_calendar_span(): void
    {
        // #12 guard against unbounded ranges must still gate on the RAW span,
        // not the (potentially smaller) chargeable count — a 400-calendar-day
        // request must still be rejected even if most days are non-working.
        $body = $this->functionBody('public function apply_leave(');
        $guardPos = strpos($body, 'if ($spanDays > self::LEAVE_MAX_SPAN_DAYS) {');
        $chargePos = strpos($body, '$chargeable = $this->_count_chargeable_leave_days(');
        $this->assertNotFalse($guardPos);
        $this->assertNotFalse($chargePos);
        $this->assertLessThan($chargePos, $guardPos, 'the span cap must be checked before the (more expensive) calendar resolution runs.');
    }

    // ── decide_leave() must stay untouched — reads the frozen value ──────

    public function test_decide_leave_reads_days_verbatim_not_recomputed(): void
    {
        $body = $this->functionBody('public function decide_leave(');
        $this->assertStringContainsString(
            "\$days     = (float) (\$request['days'] ?? \$request['numberOfDays'] ?? 0);",
            $body
        );
        $this->assertStringNotContainsString('_count_chargeable_leave_days', $body,
            'decide_leave() must consume the value frozen at apply time, never recompute it — confirmed by the Phase F research trace.');
    }

    // ── Payroll's LOP formula must be untouched by this phase ────────────

    public function test_compute_payslip_lop_formula_unchanged(): void
    {
        $body = $this->functionBody('private function _compute_payslip(');
        $this->assertStringContainsString('$deductionDays = $daysAbsent + $lwpDays;', $body);
    }
}
