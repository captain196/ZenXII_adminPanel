<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/PayrollEngine.php';

/**
 * The money "mirrors" in this suite are approximations, not the engine.
 *
 * FOUND 2026-08-21 while converting mirror-based money tests to run against the
 * real engine.
 *
 * Several money suites compute their expected values with a transcription of
 * the retention ratio, e.g. CalendarHalfDayMatrixTest:
 *
 *     /** Mirrors _compute_payslip()'s retention ratio. *\/
 *     return round(self::GROSS * $retention, 2);
 *
 * That is ONE float multiplication against a single gross, then one rounding.
 * The shipped engine does something materially different: it converts EVERY
 * component to integer paise, applies the ratio to each with exactly one
 * rounding, and then INTEGER-SUMS them.
 *
 * The two disagree by a paise on ordinary inputs. Measured, not theorised:
 *
 *     structure ₹40,000, 30 working days, 7 absent
 *         mirror  30,666.67
 *         engine  30,666.66
 *
 *     structure ₹40,000, 22 working days, 3 absent
 *         mirror  34,545.45
 *         engine  34,545.46
 *
 * THE ENGINE IS THE CORRECT ONE. Its gross is by construction the sum of the
 * component figures it also returns — the lines printed on a payslip always add
 * up to the gross printed on that payslip. The mirror's value is a rounding of
 * the ideal product, which for these inputs is NOT equal to the sum of the
 * rounded lines. A payslip showing the mirror's gross would not reconcile
 * against its own earnings breakdown.
 *
 * So this is NOT a production defect — it is a TEST-FIDELITY defect. A suite
 * that asserts mirror-derived values can pass while the engine returns a
 * different number, and a new expectation derived from a mirror can be written
 * wrong from the start.
 *
 * The existing mirror suites are deliberately NOT rewritten here. Their
 * scenarios encode the locked Calendar Half-Day business rules (S1-S4, §23) and
 * assert DAY accounting — expected work, coverage, uncovered work — which the
 * mirror models faithfully. It is only the rupee figures that drift. Rewriting
 * them wholesale would risk changing what those locked rules assert, for a
 * ±0.01 that their assertions do not currently turn on.
 *
 * What this suite does instead is fix the boundary: prove the divergence is
 * real, prove which side is authoritative, and require that any EXACT money
 * expectation comes from the engine via tests/Support/PayrollEngine.php.
 */
final class PayrollMirrorFidelityTest extends TestCase
{
    /** ₹40,000, the same round structure the mirror suites assume. */
    private const GROSS = 40000.0;

    protected function setUp(): void
    {
        if (!PayrollEngine::available()) {
            $this->markTestSkipped('payroll engine could not be loaded on this machine');
        }
    }

    /** The transcription used by the mirror suites. */
    private function mirror(float $gross, float $workingDays, float $absent): float
    {
        $ded = $absent > $workingDays ? $workingDays : $absent;
        $retention = $workingDays > 0 ? 1 - ($ded / $workingDays) : 0.0;
        return round($gross * $retention, 2);
    }

    private function engine(float $workingDays, float $absent): float
    {
        return (float) PayrollEngine::compute(PayrollEngine::salary(), $workingDays, $absent, 0.0)['gross'];
    }

    // ── The divergence is real ───────────────────────────────────────────

    /**
     * Measured cases. If these ever start agreeing, the engine's per-component
     * paise arithmetic has been replaced by a single multiplication and the
     * `Σ lines == gross` guarantee is gone.
     */
    public function test_the_mirror_and_the_engine_disagree_by_a_paise(): void
    {
        foreach ([[30.0, 7.0], [22.0, 3.0]] as [$wd, $absent]) {
            $mirror = $this->mirror(self::GROSS, $wd, $absent);
            $engine = $this->engine($wd, $absent);

            $this->assertNotEqualsWithDelta(
                $mirror,
                $engine,
                0.0001,
                "wd={$wd} absent={$absent}: the mirror is expected to differ here"
            );
            $this->assertEqualsWithDelta(0.01, abs($engine - $mirror), 0.0001,
                'the drift is one paise, in either direction');
        }
    }

    /** On many inputs they DO agree — which is why this went unnoticed. */
    public function test_they_agree_on_many_inputs(): void
    {
        foreach ([[26.0, 1.0], [26.0, 0.0], [22.0, 11.0]] as [$wd, $absent]) {
            $this->assertEqualsWithDelta(
                $this->mirror(self::GROSS, $wd, $absent),
                $this->engine($wd, $absent),
                0.0001,
                "wd={$wd} absent={$absent} happens to agree"
            );
        }
    }

    // ── Which side is authoritative ──────────────────────────────────────

    /**
     * THE DECIDING PROPERTY. The engine's gross always equals the sum of the
     * component figures it returns; the mirror's value need not. A payslip must
     * reconcile against its own earnings breakdown.
     */
    public function test_only_the_engine_guarantees_that_the_payslip_lines_add_up(): void
    {
        $r      = PayrollEngine::compute(PayrollEngine::salary(), 30.0, 7.0, 0.0);
        $lines  = PayrollEngine::earningsSum($r);
        $mirror = $this->mirror(self::GROSS, 30.0, 7.0);

        $this->assertEqualsWithDelta($lines, (float) $r['gross'], 0.0001,
            'the engine gross IS the sum of the printed lines');

        $this->assertNotEqualsWithDelta($lines, $mirror, 0.0001,
            'the mirror gross would not reconcile against the same breakdown');
    }

    public function test_the_engine_reconciles_across_a_wide_input_range(): void
    {
        foreach ([22.0, 24.0, 26.0, 28.0, 30.0, 31.0] as $wd) {
            foreach ([0.0, 0.5, 1.0, 2.5, 7.0, 11.0] as $absent) {
                if ($absent > $wd) continue;

                $r = PayrollEngine::compute(PayrollEngine::salary(), $wd, $absent, 0.0);

                $this->assertEqualsWithDelta(
                    PayrollEngine::earningsSum($r),
                    (float) $r['gross'],
                    0.0001,
                    "lines must sum to gross (wd={$wd}, absent={$absent})"
                );
            }
        }
    }

    // ── The rule this establishes ────────────────────────────────────────

    /**
     * Any suite asserting an EXACT rupee figure must obtain it from the engine.
     * This is enforced by example rather than by policing other files: the
     * harness exists, is loadable, and returns authoritative figures.
     */
    public function test_the_engine_harness_is_available_for_exact_expectations(): void
    {
        $this->assertTrue(PayrollEngine::available());

        $r = PayrollEngine::compute(PayrollEngine::salary(), 26.0, 1.0, 0.0);

        $this->assertEqualsWithDelta(38461.54, (float) $r['gross'], 0.0001,
            'an exact expectation, taken from the engine rather than a transcription');
    }

    /**
     * The mirror remains sound for what those suites actually turn on: DAY
     * accounting. Expected work, coverage and uncovered work are exact
     * fractions, not money, so no paise rounding is involved.
     */
    public function test_day_accounting_is_unaffected_by_the_rounding_difference(): void
    {
        $r = PayrollEngine::compute(PayrollEngine::salary(), 22.0, 3.0, 0.0);

        $this->assertEqualsWithDelta(19.0, (float) $r['days_worked'], 0.0001);
        $this->assertEqualsWithDelta(3.0 / 22.0, (float) $r['absent_fraction'], 0.0001,
            'the locked Calendar Half-Day rules assert day accounting, which is exact');
    }

    // ── Guard the primitives the guarantee rests on ──────────────────────

    public function test_the_paise_primitives_round_exactly_once(): void
    {
        // 23/30 of ₹1,000.00 is ₹766.666… — one rounding gives 76667 paise.
        $this->assertSame(76667, PayrollEngine::primitive('_applyRatio', 100000, 23 / 30));

        // Exact inputs must pass through untouched.
        $this->assertSame(50000, PayrollEngine::primitive('_applyRatio', 100000, 0.5));
        $this->assertSame(100000, PayrollEngine::primitive('_applyRatio', 100000, 1.0));
    }

    /**
     * The per-component approach is what produces the divergence, so pin the
     * mechanism rather than only the symptom: summing individually-rounded
     * components is NOT the same as rounding the summed ideal.
     */
    public function test_summing_rounded_components_differs_from_rounding_the_sum(): void
    {
        $ratio      = 23 / 30;
        $components = [2000000, 800000, 500000, 200000, 100000, 400000];  // paise

        $sumOfRounded = 0;
        $idealSum     = 0.0;
        foreach ($components as $p) {
            $sumOfRounded += (int) round($p * $ratio);
            $idealSum     += $p * $ratio;
        }
        $roundedSum = (int) round($idealSum);

        $this->assertNotSame($sumOfRounded, $roundedSum,
            'this inequality IS the divergence — and the engine deliberately takes the left side, '
            . 'because that is what the payslip lines actually total');

        $this->assertSame(1, abs($sumOfRounded - $roundedSum),
            'the gap is a single paise');
    }
}
