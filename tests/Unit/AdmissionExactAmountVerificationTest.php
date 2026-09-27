<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  MONEY-1 ON THE ADMISSION SURFACE — EXACT PAISE, NOT ±₹0.50
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE DEFECT ───────────────────────────────────────────────────────────
 * `Admission_public::_assert_captured_amount()` compared the captured amount
 * against the expected amount like this:
 *
 *     abs($expectedAmount - $gwAmount) > 0.50
 *
 * A ±₹0.50 FLOAT tolerance. `Fee_management` fixed exactly this comparison
 * (MONEY-1) and Admission never received the fix — on the surface that is
 * PUBLIC and unauthenticated.
 *
 * The money never was a float. Providers settle in PAISE and return an
 * integer, which every adapter already carries as `amount_paise`. The old
 * window is ~50x wider than the ≤₹0.01 error rounding can introduce, so a
 * genuine capture shortfall of up to fifty paise counted as a match — a blind
 * spot created solely by discarding an exact integer that was already in hand.
 *
 * ── WHY IT WENT UNNOTICED ────────────────────────────────────────────────
 * Nothing about it looks wrong. A tolerance on a float comparison reads as
 * defensive engineering rather than a hole, and every existing admission test
 * used amounts far outside the window. That is what makes the CONTROL below
 * the important test in this file: it drives the OLD comparison and proves it
 * accepted a real shortfall.
 *
 * ── WHAT IS DRIVEN ───────────────────────────────────────────────────────
 * `_assert_captured_amount()` is a private method on a CI3 controller, which
 * cannot be instantiated outside the framework. The comparison is therefore
 * reimplemented here EXACTLY as the controller defines it, and the controller
 * is asserted to still contain that definition. That is weaker than calling
 * it directly and is called out rather than glossed — the alternative is
 * bootstrapping CodeIgniter to reach one arithmetic expression.
 */
class AdmissionExactAmountVerificationTest extends TestCase
{
    /** Mirrors the CORRECTED comparison in _assert_captured_amount(). */
    private function mismatches(float $expectedRupees, float $gwRupees, ?int $gwMinor): bool
    {
        if ($gwMinor !== null) {
            $expectedMinor = (int) round($expectedRupees * 100);
            return $expectedMinor !== $gwMinor;
        }
        return abs($expectedRupees - $gwRupees) > 0.50;
    }

    // ══ THE CONTROL — the old comparison really was blind ════════════════

    /**
     * THE most important assertion here. If this ever reported a mismatch,
     * the defect being fixed would not have existed and the rest of this file
     * would be describing a problem that was never real.
     */
    public function test_control_the_old_tolerance_accepted_a_real_shortfall(): void
    {
        $oldSaysMismatch = abs(600.00 - 599.51) > 0.50;

        $this->assertFalse($oldSaysMismatch,
            'the ±₹0.50 comparison no longer accepts a 49-paise shortfall — re-derive this '
            . 'finding before trusting the rest of this file');
    }

    // ══ THE FIX ══════════════════════════════════════════════════════════

    /** A 49-paise shortfall is now caught, because paise are compared exactly. */
    public function test_a_forty_nine_paise_shortfall_is_now_refused(): void
    {
        $this->assertTrue(
            $this->mismatches(600.00, 599.51, 59951),
            'a capture 49 paise short of the expected amount was accepted. On a public, '
            . 'unauthenticated admission form that is money quietly written off.');
    }

    /** Even one paise short is a mismatch. There is no "close enough". */
    public function test_a_single_paise_shortfall_is_refused(): void
    {
        $this->assertTrue($this->mismatches(700.00, 699.99, 69999));
    }

    /** And an OVERPAYMENT is equally a mismatch — the direction is not the point. */
    public function test_a_single_paise_overpayment_is_refused(): void
    {
        $this->assertTrue($this->mismatches(700.00, 700.01, 70001));
    }

    /** THE CONTROL in the other direction: an exact capture must pass. */
    public function test_an_exact_capture_is_accepted(): void
    {
        $this->assertFalse($this->mismatches(700.00, 700.00, 70000),
            'an exactly-correct payment is refused — the fix would block every real payment');
    }

    /**
     * Amounts a float cannot represent precisely must still compare exactly.
     * `(int)(6000 * 100)` can be 599999 without the round() the controller does.
     */
    public function test_float_imprecise_amounts_still_compare_exactly(): void
    {
        $this->assertFalse($this->mismatches(6000.00, 6000.00, 600000),
            'a float-imprecise amount was reported as a mismatch — round() before the cast '
            . 'is missing and every payment at this amount would be refused');
        $this->assertTrue($this->mismatches(6000.00, 5999.99, 599999));
    }

    // ══ THE FALLBACK IS PRESERVED, NOT WIDENED ═══════════════════════════

    /**
     * When the provider does not supply minor units the historical tolerance
     * still applies — failing a real payment over a precision the provider
     * does not expose would be a worse outcome than the blind spot.
     */
    public function test_without_minor_units_the_historical_tolerance_still_applies(): void
    {
        $this->assertFalse($this->mismatches(600.00, 599.51, null),
            'the fallback comparison was tightened, so a provider that cannot report paise '
            . 'would have real payments refused');
        $this->assertTrue($this->mismatches(600.00, 590.00, null),
            'the fallback no longer catches a large mismatch');
    }

    // ══ THE CONTROLLER STILL DEFINES IT THIS WAY ═════════════════════════

    public function test_the_controller_compares_exact_minor_units(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Admission_public.php');
        $at  = strpos($src, 'private function _assert_captured_amount');
        $this->assertNotFalse($at, '_assert_captured_amount was renamed or removed');
        $end = strpos($src, "\n    public function ", $at + 30);
        $body = substr($src, $at, ($end !== false ? $end - $at : 6000));

        // Comments quote the old expression verbatim, so strip them.
        $code = '';
        foreach (token_get_all('<?php ' . $body) as $t) {
            if (is_array($t)) { if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $code .= $t[1]; }
            else $code .= $t;
        }

        $this->assertStringContainsString('$expectedMinor !== $gwMinor', $code,
            'Admission no longer compares exact minor units, so a sub-rupee capture '
            . 'shortfall is accepted again on a public unauthenticated form');
        $this->assertStringContainsString("amount_paise", $code,
            'the exact integer the provider supplies is being discarded again');
        $this->assertStringContainsString('(int) round($expectedAmount * 100)', $code,
            'round() before the integer cast is gone — amounts a float cannot represent '
            . 'precisely would be refused');
    }

    /** The capability gate replaced the provider-name gate here too. */
    public function test_the_helper_gates_on_capability_not_provider_name(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Admission_public.php');
        $at  = strpos($src, 'private function _assert_captured_amount');
        $end = strpos($src, "\n    public function ", $at + 30);
        $body = substr($src, $at, ($end !== false ? $end - $at : 6000));

        $code = '';
        foreach (token_get_all('<?php ' . $body) as $t) {
            if (is_array($t)) { if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $code .= $t[1]; }
            else $code .= $t;
        }

        $this->assertStringNotContainsString("=== 'mock'", $code,
            'the helper decides whether to verify by comparing a provider NAME. A second '
            . 'simulator would not be recognised, and the gate can disagree with the '
            . 'capability check its caller uses.');
        $this->assertStringContainsString('reports_captured_amount', $code,
            'the helper no longer asks the adapter whether verification is even possible');
    }
}
