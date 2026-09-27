<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * S2 — a leave day consumes the day's EXPECTED work, not a flat 1.
 *
 * AUTHORIZED RULE: "Full-day leave on a Calendar Half-Day consumes only 0.5
 * leave day." A Calendar Half-Day expects 0.5 day of work, so a leave covering
 * it can only consume 0.5.
 *
 * DEFECT (found by real UAT, 2026-08-19): both payroll engines incremented the
 * leave counters with a bare `++`, i.e. a flat 1 regardless of the day's
 * expected work. Observed live: an approved full-day paid leave on the Calendar
 * Half-Day produced paid_leave_days = 1.0 where S2 requires 0.5 — telling the
 * employee a whole leave day had been consumed for half a day of expected work,
 * and disagreeing with the leave engine's own chargeable-days weighting.
 *
 * MONEY IS NOT AFFECTED, deliberately: neither counter is an input to
 * _compute_payslip() (which takes workingDays / daysAbsent / lwpDays only).
 * Pay on such a day was full before and is full after — verified live at
 * net 44000.00 both times. This corrects a REPORTED figure and a leave-balance
 * statement, not a payment.
 *
 * Comments are stripped before assertion so this docblock cannot satisfy a test.
 */
final class CalendarHalfDayLeaveConsumptionTest extends TestCase
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

    /** Neither engine may use the unweighted increment any more. */
    public function test_no_engine_uses_a_flat_leave_increment(): void
    {
        $this->assertSame(0, substr_count($this->src, '$paidLeaveDays++'),
            'a flat ++ ignores the day expectation and violates S2');
        $this->assertSame(0, substr_count($this->src, '$unpaidLeaveDays++'));
    }

    /** Both engines — generation and regeneration — weight by expected work. */
    public function test_both_engines_weight_leave_by_expected_work(): void
    {
        $this->assertSame(2, substr_count($this->src, '$paidLeaveDays += $step;'),
            'generate_payroll and regenerate_staff_payroll must both weight');
        $this->assertSame(2, substr_count($this->src, '$unpaidLeaveDays += $step;'));
        $this->assertSame(2, substr_count($this->src, '$step = ($dayExpected == 1.0) ? 1 : $dayExpected;'));
    }

    /**
     * TYPE SAFETY: on a normal day the step must be the literal int 1, never
     * 1.0. `+= 1.0` would promote the stored counter to a float for EVERY
     * tenant, so a flag-OFF run would stop being byte-identical. Same precedent
     * as Working_calendar_service::resolveFromVersion()'s workingCount.
     */
    public function test_normal_day_step_is_an_int_literal(): void
    {
        $this->assertStringContainsString('($dayExpected == 1.0) ? 1 : $dayExpected', $this->src);
        $this->assertStringNotContainsString('($dayExpected == 1.0) ? 1.0 : $dayExpected', $this->src);
    }

    /**
     * The counters must stay OUT of the money engine. If either is ever passed
     * to _compute_payslip(), this weighting stops being reporting-only and the
     * change above would start moving pay.
     */
    public function test_leave_counters_are_never_inputs_to_the_money_engine(): void
    {
        $this->assertSame(0, preg_match_all('/_compute_payslip\([^)]*paidLeaveDays/', $this->src));
        $this->assertSame(0, preg_match_all('/_compute_payslip\([^)]*unpaidLeaveDays/', $this->src));
    }

    /**
     * The expectation the step is derived from must still come from the calendar
     * reason, in both engines — otherwise a half-day would be indistinguishable
     * from a normal day and the weighting would silently become a no-op.
     */
    public function test_day_expectation_still_derives_from_the_calendar_reason(): void
    {
        $this->assertSame(
            2,
            substr_count($this->src, "\\Working_calendar_service::REASON_HALF_DAY)\n                        ? \$halfDayWeight : 1.0;")
            + substr_count($this->src, "\\Working_calendar_service::REASON_HALF_DAY)\n                    ? \$halfDayWeight : 1.0;"),
            'both engines must derive $dayExpected from the half-day calendar reason'
        );
    }
}
