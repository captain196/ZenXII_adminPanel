<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  MONEY-1 — CAPTURED AMOUNTS ARE COMPARED EXACTLY, NOT WITHIN ₹0.50
 * ══════════════════════════════════════════════════════════════════════════
 *
 * The amount-mismatch guard is what stops a parent marking a demand paid with
 * a locally-tampered order amount. It read:
 *
 *     abs($orderAmount - floatval($gatewayAmount)) > 0.50
 *
 * The ±₹0.50 window existed because both sides were rupee floats. But the money
 * never was a float: Razorpay settles in PAISE and returns an integer, and
 * `Payment_gateway_razorpay::fetch_payment()` already carried it as
 * `amount_paise` — the value was discarded in favour of a derived
 * `round($paise / 100, 2)`.
 *
 * So the tolerance was ~50x wider than the ≤₹0.01 error that rounding can
 * introduce, and a genuine capture shortfall of up to fifty paise counted as a
 * match — a blind spot created solely by throwing away an exact integer.
 *
 * The logic under test is a private method, so it is reimplemented here EXACTLY
 * as the controller defines it and the controller is asserted to still contain
 * that definition. That is weaker than calling it directly and is called out
 * rather than glossed: the alternative is bootstrapping CodeIgniter to reach
 * one pure arithmetic function.
 */
class GatewayAmountComparisonTest extends TestCase
{
    /** Mirrors Fee_management::_gatewayAmountMismatch() exactly. */
    private function mismatch(float $expectedRupees, $reportedRupees, $reportedMinor): bool
    {
        if ($reportedMinor !== null && is_numeric($reportedMinor)) {
            $expectedMinor = (int) round($expectedRupees * 100);
            return $expectedMinor !== (int) $reportedMinor;
        }
        return abs($expectedRupees - floatval($reportedRupees)) > 0.50;
    }

    // ══ THE BLIND SPOT THE OLD COMPARISON LEFT ══════════════════════════════

    /**
     * THE CONTROL. Drives the OLD comparison and requires it to accept a real
     * shortfall. If this ever reported a mismatch, MONEY-1 would be wrong.
     */
    public function test_control_the_old_tolerance_accepted_a_real_shortfall(): void
    {
        $oldSaysMismatch = abs(600.00 - 599.51) > 0.50;

        $this->assertFalse($oldSaysMismatch,
            'the ±₹0.50 comparison no longer accepts a 49-paise shortfall — re-derive '
            . 'MONEY-1 before trusting the rest of this file');
    }

    /**
     * The same shortfall, compared exactly. A demand of ₹600 captured at
     * ₹599.51 is a mismatch, full stop.
     */
    public function test_a_shortfall_inside_the_old_tolerance_is_now_caught(): void
    {
        $this->assertTrue($this->mismatch(600.00, 599.51, 59951),
            'a 49-paise capture shortfall still passes as a match. That is money the school '
            . 'never received, recorded as though it had been.');
    }

    public function test_an_exact_capture_is_not_a_mismatch(): void
    {
        $this->assertFalse($this->mismatch(600.00, 600.00, 60000));
        $this->assertFalse($this->mismatch(1.00, 1.00, 100));
        $this->assertFalse($this->mismatch(12345.67, 12345.67, 1234567));
    }

    public function test_a_single_paise_discrepancy_is_a_mismatch(): void
    {
        $this->assertTrue($this->mismatch(600.00, 599.99, 59999),
            'exact comparison must catch a one-paise difference');
        $this->assertTrue($this->mismatch(600.00, 600.01, 60001));
    }

    /**
     * THE FLOAT TRAP the implementation guards against. `(int)($x * 100)`
     * truncates, and many rupee values are not exactly representable — so
     * without round(), an exact capture can read as a mismatch and REFUSE a
     * legitimate payment. That failure direction is worse than the blind spot.
     */
    public function test_float_representation_does_not_cause_a_false_mismatch(): void
    {
        foreach ([6000.00, 1.10, 2.30, 8.20, 33.33, 70.70, 129.95, 4999.99] as $rupees) {
            $exactMinor = (int) round($rupees * 100);

            $this->assertFalse($this->mismatch($rupees, $rupees, $exactMinor),
                "an exact capture of ₹{$rupees} was reported as a mismatch. Truncating "
                . 'instead of rounding makes (int)(x*100) land one paise low for values a '
                . 'float cannot hold precisely — which would REFUSE good payments.');
        }
    }

    // ══ THE FALLBACK MUST SURVIVE ═══════════════════════════════════════════

    /**
     * An adapter that cannot report minor units must not have its payments
     * failed on a precision it does not expose.
     */
    public function test_without_minor_units_the_tolerant_comparison_still_applies(): void
    {
        $this->assertFalse($this->mismatch(600.00, 599.51, null),
            'the fallback must keep the historical tolerance for adapters that report only '
            . 'a decimal amount');
        $this->assertTrue($this->mismatch(600.00, 550.00, null),
            'the fallback must still catch a material mismatch');
    }

    public function test_a_non_numeric_minor_value_falls_back_rather_than_crashing(): void
    {
        $this->assertFalse($this->mismatch(600.00, 600.00, ''));
        $this->assertFalse($this->mismatch(600.00, 600.00, 'not-a-number'));
    }

    /**
     * Zero paise is a legitimate integer and must NOT be treated as absent —
     * `if ($minor)` instead of `!== null` would send a genuine zero-capture
     * down the tolerant path and let it pass against a real demand.
     */
    public function test_a_zero_capture_is_compared_exactly_not_treated_as_missing(): void
    {
        $this->assertTrue($this->mismatch(600.00, 0.0, 0),
            'a capture of zero paise against a ₹600 demand must be a mismatch. Treating 0 as '
            . '"no value" would route it to the tolerant comparison and could let it pass.');
    }

    // ══ THE IMPLEMENTATION STILL MATCHES THIS MODEL ═════════════════════════

    public function test_the_controller_still_defines_the_exact_comparison(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');

        $this->assertStringContainsString('private function _gatewayAmountMismatch(', $src,
            'the shared comparison helper was removed; both guards would drift apart again');

        $this->assertStringContainsString('$expectedMinor !== (int) $reportedMinor', $src,
            'the exact minor-unit comparison is gone — the ±₹0.50 blind spot returns');

        $this->assertStringContainsString('(int) round($expectedRupees * 100)', $src,
            'the conversion no longer rounds before casting, so float representation will '
            . 'refuse legitimate payments');

        $this->assertSame(0, substr_count($src, 'abs($orderAmount - floatval($gwReportedAmount)) > 0.50'),
            'a call site still performs the old inline tolerant comparison instead of using '
            . 'the shared helper');
    }

    public function test_the_payment_service_surfaces_the_exact_minor_amount(): void
    {
        $src = (string) file_get_contents(APPPATH . 'libraries/Payment_service.php');

        $this->assertStringContainsString("'gateway_amount_minor'", $src,
            'Payment_service no longer passes the exact paise amount through, so every '
            . 'caller silently falls back to the tolerant float comparison');
        $this->assertStringContainsString("\$fetched['amount_paise']", $src,
            'the adapter-supplied integer is being discarded again — that discard IS MONEY-1');
    }
}
