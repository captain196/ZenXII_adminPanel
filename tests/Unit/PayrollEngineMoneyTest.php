<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/PayrollEngine.php';

/**
 * Paise-exact money behaviour, executed against the SHIPPED payroll engine.
 *
 * Every other money suite in this repository mirrors `_compute_payslip()`'s
 * arithmetic inside the test file. A mirror only proves the transcription is
 * self-consistent: change the engine and the mirror still passes. This suite
 * runs the real `Hr::_compute_payslip()` — the single engine both
 * `generate_payroll()` and `regenerate_staff_payroll()` call — via
 * tests/Support/PayrollEngine.php.
 *
 * The contract being asserted, read from the implementation:
 *
 *   deductionDays  = daysAbsent + lwpDays          (each clamped to workingDays)
 *   absentFraction = deductionDays / workingDays
 *   retention      = 1 - absentFraction
 *   every component: _applyRatio(_toPaise(component), retention)
 *   gross          = INTEGER SUM of those paise, with NO further rounding
 *
 * That integer sum is the whole point: it is what guarantees
 * `Σ earnings == gross` exactly, for every input, instead of six independently
 * rounded rupee figures that fail to add up.
 *
 * Expected values below were derived by hand from that contract before being
 * run, not copied out of the engine's output.
 */
final class PayrollEngineMoneyTest extends TestCase
{
    protected function setUp(): void
    {
        if (!PayrollEngine::available()) {
            $this->markTestSkipped('payroll engine could not be loaded on this machine');
        }
    }

    // ── The paise invariant ──────────────────────────────────────────────

    /**
     * THE core money guarantee. If the components are ever rounded
     * individually to rupees before summing, this breaks for some input.
     */
    public function test_earnings_always_sum_exactly_to_gross(): void
    {
        $sal = PayrollEngine::salary();

        // Deliberately awkward denominators and fractions.
        foreach ([26.0, 30.0, 24.0, 22.5, 19.0, 31.0] as $wd) {
            foreach ([0.0, 0.5, 1.0, 1.25, 3.0, 7.5] as $absent) {
                if ($absent > $wd) continue;

                $r   = PayrollEngine::compute($sal, $wd, $absent, 0.0);
                $sum = PayrollEngine::earningsSum($r);

                $this->assertEqualsWithDelta(
                    (float) $r['gross'],
                    $sum,
                    0.0001,
                    "Σ earnings must equal gross exactly (wd={$wd}, absent={$absent})"
                );
            }
        }
    }

    public function test_full_attendance_pays_the_whole_structure(): void
    {
        $r = PayrollEngine::compute(PayrollEngine::salary(), 26.0);

        $this->assertEqualsWithDelta(40000.0, (float) $r['gross'], 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $r['absent_fraction'], 0.0001);
        $this->assertEqualsWithDelta(26.0, (float) $r['days_worked'], 0.0001);
    }

    /**
     * Hand-derived: retention = 25/26.
     *   basic    2000000p × 25/26 = 1923077 (round 1923076.92)
     *   hra       800000p × 25/26 =  769231
     *   da        500000p × 25/26 =  480769
     *   ta        200000p × 25/26 =  192308
     *   medical   100000p × 25/26 =   96154
     *   other     400000p × 25/26 =  384615
     *   gross                     = 3846154p = ₹38,461.54
     */
    public function test_one_absent_day_reduces_pay_to_the_exact_paise(): void
    {
        $r = PayrollEngine::compute(PayrollEngine::salary(), 26.0, 1.0, 0.0);

        $this->assertEqualsWithDelta(38461.54, (float) $r['gross'], 0.0001);
        $this->assertEqualsWithDelta(19230.77, (float) $r['basic'], 0.0001);
        $this->assertEqualsWithDelta(25.0, (float) $r['days_worked'], 0.0001);
    }

    // ── Fractional days survive ──────────────────────────────────────────

    public function test_a_half_day_absence_is_not_rounded_to_a_whole_day(): void
    {
        $r = PayrollEngine::compute(PayrollEngine::salary(), 26.0, 0.5, 0.0);

        $this->assertEqualsWithDelta(39230.77, (float) $r['gross'], 0.0001);
        $this->assertEqualsWithDelta(25.5, (float) $r['days_worked'], 0.0001,
            'a fractional day must survive into the day accounting');
    }

    public function test_a_quarter_day_is_representable(): void
    {
        $full = PayrollEngine::compute(PayrollEngine::salary(), 26.0);
        $q    = PayrollEngine::compute(PayrollEngine::salary(), 26.0, 0.25, 0.0);

        $this->assertLessThan((float) $full['gross'], (float) $q['gross'],
            'a quarter-day absence must cost something');
        $this->assertEqualsWithDelta(25.75, (float) $q['days_worked'], 0.0001);
    }

    // ── Absence vs LWP ───────────────────────────────────────────────────

    /** Both reduce earned wages identically; only LWP reports a deduction. */
    public function test_absence_and_lwp_reduce_pay_identically(): void
    {
        $a = PayrollEngine::compute(PayrollEngine::salary(), 26.0, 1.0, 0.0);
        $l = PayrollEngine::compute(PayrollEngine::salary(), 26.0, 0.0, 1.0);

        $this->assertEqualsWithDelta((float) $a['gross'], (float) $l['gross'], 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $a['lwp_deduction'], 0.0001);
        $this->assertGreaterThan(0.0, (float) $l['lwp_deduction'],
            'LWP reports its own deduction line; plain absence does not');
    }

    public function test_absence_and_lwp_accumulate(): void
    {
        $r = PayrollEngine::compute(PayrollEngine::salary(), 26.0, 1.0, 1.0);

        $this->assertEqualsWithDelta(2.0 / 26.0, (float) $r['absent_fraction'], 0.0001);
        $this->assertEqualsWithDelta(24.0, (float) $r['days_worked'], 0.0001);
    }

    /** Paid leave is simply not passed as absence — it must not reduce pay. */
    public function test_paid_leave_does_not_reduce_pay(): void
    {
        $r = PayrollEngine::compute(PayrollEngine::salary(), 26.0, 0.0, 0.0);

        $this->assertEqualsWithDelta(40000.0, (float) $r['gross'], 0.0001);
    }

    // ── Clamping: pay can never go negative ──────────────────────────────

    public function test_absence_beyond_the_period_clamps_to_zero_pay(): void
    {
        $r = PayrollEngine::compute(PayrollEngine::salary(), 26.0, 40.0, 0.0);

        $this->assertEqualsWithDelta(0.0, (float) $r['gross'], 0.0001,
            'gross must floor at zero, never invert into a negative payslip');
        $this->assertEqualsWithDelta(1.0, (float) $r['absent_fraction'], 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $r['days_worked'], 0.0001);
    }

    public function test_absence_plus_lwp_beyond_the_period_also_clamps(): void
    {
        $r = PayrollEngine::compute(PayrollEngine::salary(), 26.0, 20.0, 20.0);

        $this->assertEqualsWithDelta(0.0, (float) $r['gross'], 0.0001);
        $this->assertGreaterThanOrEqual(0.0, (float) $r['days_worked']);
    }

    // ── The locked rule: reduced expected work is not reduced salary ─────

    /**
     * Zero working days yields FULL pay, not zero.
     *
     * This is not an accident of the division guard — it is the Calendar
     * Half-Day programme's locked business rule: "reduced expected work must
     * never automatically mean reduced salary." An employee whose calendar
     * expected nothing of them has not failed to work.
     */
    public function test_zero_working_days_pays_in_full(): void
    {
        $r = PayrollEngine::compute(PayrollEngine::salary(), 0.0, 0.0, 0.0);

        $this->assertEqualsWithDelta(40000.0, (float) $r['gross'], 0.0001,
            'reduced expected work must never automatically mean reduced salary');
        $this->assertEqualsWithDelta(0.0, (float) $r['absent_fraction'], 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $r['daily_salary'], 0.0001,
            'the displayed daily rate is undefined with no working days');
    }

    // ── Determinism: generation == regeneration ──────────────────────────

    /**
     * Both payroll paths call this one function. Identical inputs must yield a
     * byte-identical result, or a regenerated payslip could differ from the
     * originally generated one for no reason.
     */
    public function test_the_engine_is_deterministic(): void
    {
        $sal = PayrollEngine::salary();

        $a = PayrollEngine::compute($sal, 26.0, 1.5, 0.5, 1.0, 1.0);
        $b = PayrollEngine::compute($sal, 26.0, 1.5, 0.5, 1.0, 1.0);

        $this->assertSame($a, $b, 'same inputs must produce the same payslip, exactly');
    }

    // ── Extra worked days ────────────────────────────────────────────────

    public function test_working_a_rest_day_increases_pay(): void
    {
        $base  = PayrollEngine::compute(PayrollEngine::salary(), 26.0);
        $extra = PayrollEngine::compute(PayrollEngine::salary(), 26.0, 0.0, 0.0, 1.0, 1.0);

        $this->assertGreaterThan((float) $base['gross'], (float) $extra['gross'],
            "the 'W' mark exists specifically to trigger extra pay");
        $this->assertEqualsWithDelta(1.0, (float) $extra['extra_worked_days'], 0.0001);
    }

    /** The PF base stays basic-only — extra pay must not inflate it. */
    public function test_extra_worked_pay_does_not_inflate_the_basic(): void
    {
        $base  = PayrollEngine::compute(PayrollEngine::salary(), 26.0);
        $extra = PayrollEngine::compute(PayrollEngine::salary(), 26.0, 0.0, 0.0, 2.0, 1.0);

        $this->assertEqualsWithDelta((float) $base['basic'], (float) $extra['basic'], 0.0001,
            'effective basic is the PF base and must not move with extra-worked pay');
    }

    public function test_the_extra_rate_is_a_multiplier(): void
    {
        $atPar   = PayrollEngine::compute(PayrollEngine::salary(), 26.0, 0.0, 0.0, 1.0, 1.0);
        $doubled = PayrollEngine::compute(PayrollEngine::salary(), 26.0, 0.0, 0.0, 1.0, 2.0);

        $this->assertGreaterThan((float) $atPar['extra_worked_pay'], (float) $doubled['extra_worked_pay']);
    }

    // ── The money primitives ─────────────────────────────────────────────

    public function test_rupees_convert_to_exact_paise(): void
    {
        $this->assertSame(2000000, PayrollEngine::primitive('_toPaise', 20000));
        $this->assertSame(50, PayrollEngine::primitive('_toPaise', 0.5));
        $this->assertSame(1, PayrollEngine::primitive('_toPaise', 0.01));
        $this->assertSame(0, PayrollEngine::primitive('_toPaise', 0));
    }

    /** Exactly ONE rounding, applied to the integer paise base. */
    public function test_apply_ratio_rounds_exactly_once(): void
    {
        $this->assertSame(1923077, PayrollEngine::primitive('_applyRatio', 2000000, 25 / 26));
        $this->assertSame(0, PayrollEngine::primitive('_applyRatio', 2000000, 0.0));
        $this->assertSame(0, PayrollEngine::primitive('_applyRatio', 0, 0.5));
        $this->assertSame(2000000, PayrollEngine::primitive('_applyRatio', 2000000, 1.0));
    }

    /**
     * Paise-exactness is not cosmetic. A structure whose thirds do not divide
     * evenly must still add up, where independently rounded rupee figures
     * would drift.
     */
    public function test_an_awkward_structure_still_adds_up(): void
    {
        $sal = [
            'basic'            => 14333.33,
            'hra'              => 7166.67,
            'da'               => 3583.33,
            'ta'               => 1791.67,
            'medical'          => 895.83,
            'other_allowances' => 447.92,
        ];

        $r   = PayrollEngine::compute($sal, 30.0, 7.0, 0.0);
        $sum = PayrollEngine::earningsSum($r);

        $this->assertEqualsWithDelta((float) $r['gross'], $sum, 0.0001,
            'awkward thirds are exactly where per-component rounding drifts');
    }

    // ── Structure edge cases ─────────────────────────────────────────────

    public function test_a_missing_component_is_treated_as_zero(): void
    {
        $r = PayrollEngine::compute(['basic' => 10000], 26.0);

        $this->assertEqualsWithDelta(10000.0, (float) $r['gross'], 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $r['hra'], 0.0001);
    }

    public function test_an_empty_structure_yields_zero_not_an_error(): void
    {
        $r = PayrollEngine::compute([], 26.0, 1.0, 0.0);

        $this->assertEqualsWithDelta(0.0, (float) $r['gross'], 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $r['daily_salary'], 0.0001);
    }

    /** The displayed daily rate is basic-only and independent of absence. */
    public function test_the_displayed_daily_rate_does_not_move_with_absence(): void
    {
        $a = PayrollEngine::compute(PayrollEngine::salary(), 26.0, 0.0, 0.0);
        $b = PayrollEngine::compute(PayrollEngine::salary(), 26.0, 5.0, 0.0);

        $this->assertEqualsWithDelta(769.23, (float) $a['daily_salary'], 0.0001);
        $this->assertEqualsWithDelta((float) $a['daily_salary'], (float) $b['daily_salary'], 0.0001,
            'the daily rate is a property of the structure, not of attendance');
    }
}
