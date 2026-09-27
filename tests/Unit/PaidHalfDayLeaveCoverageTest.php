<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * HALF-DAY PROGRAMME — source regression for the PAID half-day coverage fix
 * (2026-08-19).
 *
 * Behavioural proof of the defect and the fix lives in
 * HalfDayReconciliationTest (executable). This file pins the wiring in the
 * controller, and — critically — pins it in BOTH payroll engines.
 *
 * That duplication is the standing architectural risk: generate_payroll()
 * and regenerate_staff_payroll() are two hand-synchronised implementations
 * of the same computation, and the code only *comments* the coupling
 * ("MUST mirror generate_payroll()'s branch exactly; regenerate is
 * contractually required to produce identical numbers"). Nothing enforced
 * it. A fix applied to one engine and not the other would mean a payslip
 * silently changes value when it is regenerated. These tests enforce it.
 */
class PaidHalfDayLeaveCoverageTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($raw);
        $out = '';
        foreach (token_get_all($raw) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
                $out .= $t[1];
            } else { $out .= $t; }
        }
        $this->src = $out;
    }

    private function extractFunctionBody(string $src, int $offset): string
    {
        $open = strpos($src, '{', $offset);
        $this->assertNotFalse($open);
        $depth = 0; $len = strlen($src);
        for ($i = $open; $i < $len; $i++) {
            if ($src[$i] === '{') $depth++;
            elseif ($src[$i] === '}') { $depth--; if ($depth === 0) return substr($src, $open, $i - $open + 1); }
        }
        $this->fail('Unbalanced braces.');
    }

    private function fn(string $name): string
    {
        $start = strpos($this->src, 'function ' . $name . '(');
        $this->assertNotFalse($start, "{$name}() must exist.");
        return $this->extractFunctionBody($this->src, $start);
    }

    // ── The leave processor reports paid half-day coverage ───────────

    public function test_processor_reports_a_paid_day_set_for_paid_half_day_leave(): void
    {
        $body = $this->fn('_process_leave_for_payroll');
        // Widened from `=== 0` to `<= 0.0` when $lwpDaysOnDoc became a float
        // (it was an (int) cast, which flattened a real 0.5-day LWP to 0 and
        // then SATISFIED the strict `=== 0` — silently skipping the
        // deduction). The condition still means "no LWP at all".
        $this->assertStringContainsString(
            "if (\$isHalfDay && \$ltPaid && \$lwpDaysOnDoc <= 0.0) {", $body,
            'paid half-day leave must be handled BEFORE the CR-1 early return, or it reports nothing.');
        $this->assertStringContainsString("'paidDaySet'  => [(int) date('j', strtotime(\$lrFrom)) => 0.5],", $body);
    }

    public function test_paid_coverage_never_adds_a_deduction(): void
    {
        $body = $this->fn('_process_leave_for_payroll');
        // The paid branch must carry zero LWP — coverage, not a charge.
        $this->assertStringContainsString("'lwpDays'     => 0.0,", $body);
    }

    public function test_every_return_path_declares_paid_day_set(): void
    {
        // A missing key would make the caller's !empty() check silently
        // skip coverage for that path.
        $body = $this->fn('_process_leave_for_payroll');
        $this->assertGreaterThanOrEqual(4, substr_count($body, "'paidDaySet'"),
            'emptyResult + paid-half-day + lwp-half-day + full-day branches must all declare paidDaySet.');
    }

    public function test_cr1_early_return_is_preserved_for_non_half_day_paid_leave(): void
    {
        // Full-day paid leave must still short-circuit — it is handled by
        // the attendance 'L' mark, not by a paid day set.
        $body = $this->fn('_process_leave_for_payroll');
        $this->assertStringContainsString("if (\$ltPaid && \$lwpDaysOnDoc <= 0.0) return \$emptyResult;", $body);
    }

    public function test_lwp_half_day_branch_is_unchanged(): void
    {
        $body = $this->fn('_process_leave_for_payroll');
        $this->assertStringContainsString("'lwpDays'     => 0.5,", $body);
        $this->assertStringContainsString("'lwpDaySet'   => [\$dn => 0.5],", $body);
    }

    // ── Engine 1: generate_payroll ───────────────────────────────────

    public function test_generate_builds_and_consumes_the_paid_day_set(): void
    {
        $body = $this->fn('generate_payroll');
        $this->assertStringContainsString('$paidDaysByStaff  = [];', $body);
        $this->assertStringContainsString("if (!empty(\$res['paidDaySet'])) {", $body);
        $this->assertStringContainsString('$staffPaidSet    = isset($paidDaysByStaff[$staffId])', $body);

        // CONSOLIDATED 2026-08-19. The paid set used to be consumed by an
        // all-or-nothing guard on the 'M' branch alone. It now feeds the shared
        // _leave_coverage_for_day() rule that 'A', 'V' and 'M' all use, because
        // 'V' — the UNMARKED fill, and therefore the branch a half-day leave
        // actually lands in — honoured no coverage at all. Same property, one
        // implementation.
        $this->assertStringContainsString('$staffPaidSet[$dayNum] ?? null', $body,
            'the paid set must feed the shared coverage rule');
        $this->assertStringContainsString('self::_leave_coverage_for_day(', $body);
    }

    // ── Engine 2: regenerate_staff_payroll ───────────────────────────

    public function test_regenerate_builds_and_consumes_the_paid_day_set(): void
    {
        $body = $this->fn('regenerate_staff_payroll');
        $this->assertStringContainsString('$paidDaysSet  = [];', $body);
        $this->assertStringContainsString("if (!empty(\$res['paidDaySet'])) {", $body);
        // See the note on the generate_payroll() counterpart above.
        $this->assertStringContainsString('$paidDaysSet[$dayNum] ?? null', $body);
        $this->assertStringContainsString('self::_leave_coverage_for_day(', $body);
    }

    /**
     * The invariant that matters most: a payslip must not change value
     * merely because it was regenerated. Both engines must guard 'M'
     * against BOTH sets.
     */
    public function test_both_engines_guard_the_half_day_mark_identically(): void
    {
        $gen = $this->fn('generate_payroll');
        $reg = $this->fn('regenerate_staff_payroll');

        // Both engines must derive coverage from the SAME shared rule and
        // subtract it from the half that 'M' leaves uncovered. Previously each
        // carried its own all-or-nothing guard; identical behaviour is now
        // structurally guaranteed rather than maintained in parallel.
        foreach (['generate_payroll' => $gen, 'regenerate_staff_payroll' => $reg] as $name => $body) {
            $this->assertStringContainsString('self::_leave_coverage_for_day(', $body,
                "{$name}() must use the shared coverage rule");
            $this->assertStringContainsString(
                '$daysAbsent += max(0.0, ($dayExpected * 0.5) - $dayCoverage);', $body,
                "{$name}() must subtract coverage from the uncovered half"
            );
        }
    }

    /**
     * 'V' is the UNMARKED-day fill, and a half-day leave deliberately writes no
     * attendance mark — so a covered day lands here, not on 'A' or 'M'. This
     * branch honoured NO coverage, which charged approved leave in full and
     * charged a full-day unpaid leave TWICE (once as lwpDays by the leave
     * engine, again as absence here). Both engines must now honour coverage.
     */
    public function test_both_engines_honour_coverage_on_unmarked_days(): void
    {
        foreach (['generate_payroll', 'regenerate_staff_payroll'] as $fn) {
            $body = $this->fn($fn);

            $this->assertStringContainsString(
                '$daysAbsent += max(0.0, $dayExpected - $dayCoverage);', $body,
                "{$fn}() must subtract approved-leave coverage on unmarked days"
            );
            $this->assertStringNotContainsString(
                "if (\$vacantTreatment === 'absent') \$daysAbsent += \$dayExpected;", $body,
                'the unguarded vacant charge must not come back'
            );
        }
    }

    public function test_neither_engine_still_has_an_unguarded_half_day_charge(): void
    {
        // The exact pre-fix shape must be gone from both.
        foreach (['generate_payroll', 'regenerate_staff_payroll'] as $fn) {
            $body = $this->fn($fn);
            $this->assertStringNotContainsString(
                "\$halfDays++;\n                        if (!isset(\$staffLwpSet[\$dayNum])) {", $body);
        }
    }

    // ── Concept separation is preserved ──────────────────────────────

    public function test_calendar_half_day_pay_arithmetic_was_not_touched(): void
    {
        // Calendar Half-Day remains a declared calendar fact with no pay
        // effect — that change is a financial-policy decision and is NOT
        // part of this fix.
        $cal = file_get_contents(__DIR__ . '/../../application/libraries/Working_calendar_service.php');
        $this->assertStringContainsString('REASON_HALF_DAY', $cal);
        $this->assertStringContainsString('$isWorking[$d] = true;', $cal);
        $this->assertStringContainsString('halfDayCount', $cal);
    }

    public function test_half_day_leave_still_does_not_mark_attendance(): void
    {
        // The three concepts stay separate: leave coverage must not
        // fabricate an attendance mark.
        $body = $this->fn('decide_leave');
        $this->assertStringContainsString('!$isHalfDay', $body,
            'half-day leave must continue to skip the attendance write.');
    }

    public function test_payslip_deduction_formula_is_unchanged(): void
    {
        $body = $this->fn('_compute_payslip');
        $this->assertStringContainsString('$deductionDays = $daysAbsent + $lwpDays;', $body);
        $this->assertStringContainsString('if ($deductionDays > $staffWorkingDays) $deductionDays = $staffWorkingDays;', $body);
    }
}
