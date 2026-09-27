<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Carry-forward must mean the same thing in BOTH engines.
 *
 * DEFECT (found 2026-08-20 by behavioural audit of the leave entitlement paths):
 * ZenX has two carry-forward implementations and they disagreed on the meaning
 * of `max_carry = 0`.
 *
 *   preview_leave_year_closure()  →  $eligibleCarry = min($unused, $maxCarry)
 *                                    0 means carry ZERO days.
 *
 *   allocate_leave_balances()     →  ($maxCarry > 0) ? min($prev,$maxCarry) : $prev
 *                                    0 means carry EVERYTHING.
 *
 * One tenant configuration therefore produced opposite entitlement depending on
 * which engine ran: the closure forfeited the entire balance, the reconciler
 * carried all of it forward. The UI field is labelled plainly "Max Carry Days"
 * with no "0 = unlimited" affordance, and the closure path is the newer,
 * effective-dated engine — so 0 means zero, and the reconciler was the outlier.
 *
 * BLAST RADIUS MEASURED BEFORE FIXING: a read-only census across every tenant
 * found 13 leave types, 1 with carry_forward enabled, and NONE with
 * max_carry = 0. The contradiction was latent, not live, so unifying changes no
 * employee's balance today. It closes the trap before the first tenant sets 0
 * and silently receives unlimited carry-forward.
 *
 * These tests execute the arithmetic of both engines and assert they agree.
 */
final class LeaveCarryForwardSemanticsTest extends TestCase
{
    /** The year-closure engine, transcribed from preview_leave_year_closure(). */
    private function closureCarry(float $unused, bool $carryForward, float $maxCarry): float
    {
        return $carryForward ? min($unused, $maxCarry) : 0.0;
    }

    /** The reconciler, transcribed from allocate_leave_balances() as fixed. */
    private function reconcilerCarry(float $prevBal, bool $carryForward, float $maxCarry)
    {
        if (!$carryForward) return 0;
        $pb      = $prevBal;
        $prev    = ($pb == floor($pb)) ? (int) $pb : $pb;
        $mc      = $maxCarry;
        $cap     = ($mc == floor($mc)) ? (int) $mc : $mc;
        $carried = min($prev, $cap);
        return ($carried < 0) ? 0 : $carried;
    }

    // ── THE DEFECT: max_carry = 0 ────────────────────────────────────────

    public function test_zero_cap_means_zero_days_in_both_engines(): void
    {
        $this->assertSame(0.0, $this->closureCarry(12.0, true, 0.0));
        $this->assertSame(0,   $this->reconcilerCarry(12.0, true, 0.0),
            'a zero cap must forfeit, not carry the whole balance');
    }

    public function test_engines_agree_on_a_zero_cap(): void
    {
        $this->assertEqualsWithDelta(
            $this->closureCarry(12.0, true, 0.0),
            (float) $this->reconcilerCarry(12.0, true, 0.0),
            0.0001,
            'the two carry-forward engines must not disagree'
        );
    }

    // ── Ordinary caps ────────────────────────────────────────────────────

    public function test_cap_below_balance_limits_the_carry(): void
    {
        $this->assertSame(5.0, $this->closureCarry(12.0, true, 5.0));
        $this->assertSame(5,   $this->reconcilerCarry(12.0, true, 5.0));
    }

    public function test_cap_above_balance_carries_only_what_exists(): void
    {
        $this->assertSame(3.0, $this->closureCarry(3.0, true, 30.0));
        $this->assertSame(3,   $this->reconcilerCarry(3.0, true, 30.0));
    }

    public function test_carry_forward_disabled_carries_nothing(): void
    {
        $this->assertSame(0.0, $this->closureCarry(12.0, false, 30.0));
        $this->assertSame(0,   $this->reconcilerCarry(12.0, false, 30.0));
    }

    // ── Fractions ────────────────────────────────────────────────────────

    public function test_fractional_balance_survives_the_carry(): void
    {
        // A half-day leave leaves a .5 closing balance.
        $this->assertSame(5.5, $this->closureCarry(5.5, true, 10.0));
        $this->assertSame(5.5, $this->reconcilerCarry(5.5, true, 10.0));
    }

    public function test_fractional_cap_is_not_truncated(): void
    {
        // The old `(int) $lt['max_carry']` turned a 7.5-day cap into 7.
        $this->assertSame(7.5, $this->closureCarry(12.0, true, 7.5));
        $this->assertSame(7.5, $this->reconcilerCarry(12.0, true, 7.5),
            'a fractional cap must not be truncated by an int cast');
    }

    // ── Type preservation ────────────────────────────────────────────────

    public function test_whole_numbers_stay_integers(): void
    {
        $carried = $this->reconcilerCarry(12.0, true, 5.0);
        $this->assertSame(5, $carried);
        $this->assertIsInt($carried, 'untouched tenants must keep integer balances');
    }

    // ── Guard rails ──────────────────────────────────────────────────────

    public function test_negative_balance_never_carries_forward(): void
    {
        $this->assertSame(0, $this->reconcilerCarry(-3.0, true, 10.0));
    }

    /**
     * Exhaustive agreement across the realistic configuration space. If the two
     * engines ever diverge again for any of these, this fails.
     */
    public function test_engines_agree_across_the_configuration_space(): void
    {
        foreach ([0.0, 0.5, 3.0, 5.5, 12.0, 30.0] as $bal) {
            foreach ([0.0, 0.5, 5.0, 7.5, 30.0] as $cap) {
                $this->assertEqualsWithDelta(
                    $this->closureCarry($bal, true, $cap),
                    (float) $this->reconcilerCarry($bal, true, $cap),
                    0.0001,
                    "engines disagree at balance={$bal} cap={$cap}"
                );
            }
        }
    }

    // ── Source pins ──────────────────────────────────────────────────────

    public function test_reconciler_no_longer_treats_zero_as_unlimited(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($src);

        $this->assertStringNotContainsString(
            '$carried = ($maxCarry > 0) ? min($prevBal, $maxCarry) : $prevBal;',
            $src,
            'the "0 means unlimited" branch must not come back'
        );
        $this->assertStringContainsString('$carried  = min($prevBal, $maxCarry);', $src);
    }

    public function test_reconciler_cap_is_fraction_safe(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($src);

        $this->assertStringNotContainsString("\$maxCarry = (int) (\$lt['max_carry'] ?? 0);", $src);
        $this->assertStringContainsString("\$mc       = (float) (\$lt['max_carry'] ?? 0);", $src);
    }

    /** The closure engine's own expression must stay as the reference. */
    public function test_closure_engine_reference_expression_unchanged(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($src);

        $this->assertStringContainsString(
            '$eligibleCarry = $carryForward ? min($unused, $maxCarry) : 0.0;',
            $src
        );
    }
}
