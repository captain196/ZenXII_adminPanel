<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', __DIR__ . '/../../system/');

require_once APPPATH . 'libraries/Payment_order_provenance.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  GW-ORD-1 (ADMISSION) — THE SAME PROTECTION, PROVEN, NOT ASSUMED
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `Admission_public::initiate_payment` reuses a pending `admissionPayments`
 * row on status + amount + a 15-minute window. It did not check which gateway
 * minted the id it returned — the identical defect found in the fees path.
 *
 * The window bounded the damage (a mismatched order expires rather than
 * poisoning the combination permanently) but did not remove it. Wrong for at
 * most fifteen minutes is still wrong, and an applicant paying an admission fee
 * sees the same indistinguishable "payment failed".
 *
 * Both paths now share ONE predicate. Two separately-written rules is precisely
 * how the same defect came to exist in two places, so the shared definition is
 * driven here and its adoption by the admission controller is asserted
 * separately — a shared rule nothing calls protects nothing.
 */
class AdmissionOrderProvenanceTest extends TestCase
{
    // ══ THE SHARED PREDICATE, EXECUTED ══════════════════════════════════════

    public function test_control_a_matching_gateway_is_reusable(): void
    {
        $this->assertTrue(
            Payment_order_provenance::matchesActiveGateway('razorpay', 'razorpay'),
            'a genuine in-flight order is no longer reusable — admission would mint a '
            . 'duplicate order for money already in flight'
        );
    }

    public function test_a_mock_order_is_not_reusable_against_razorpay(): void
    {
        $this->assertFalse(
            Payment_order_provenance::matchesActiveGateway('mock', 'razorpay'),
            'a mock-minted admission order would be handed to the real Razorpay checkout, '
            . 'which refuses an id it never issued the moment the sheet opens'
        );
    }

    public function test_the_rule_is_symmetric_not_a_special_case_against_mock(): void
    {
        $this->assertFalse(
            Payment_order_provenance::matchesActiveGateway('razorpay', 'mock'),
            'the rule only rejects the mock by name rather than comparing provenance, so '
            . 'the next adapter added reintroduces the defect'
        );
    }

    // ══ EVERY UNCERTAINTY FAILS CLOSED ══════════════════════════════════════

    public function test_unrecorded_provenance_is_not_reusable(): void
    {
        foreach (['', '   ', null, false] as $missing) {
            $this->assertFalse(
                Payment_order_provenance::matchesActiveGateway($missing, 'razorpay'),
                'an order whose minting gateway was never recorded is reused anyway — its '
                . 'provenance is unknown, so it cannot be shown to be payable'
            );
        }
    }

    public function test_a_non_scalar_stored_gateway_is_not_reusable(): void
    {
        $this->assertFalse(
            Payment_order_provenance::matchesActiveGateway(['razorpay'], 'razorpay'),
            'a malformed stored value is treated as a gateway name. Casting an array to '
            . 'string also raises a PHP error inside the money path.'
        );
        $this->assertFalse(
            Payment_order_provenance::matchesActiveGateway(new stdClass(), 'razorpay')
        );
    }

    /**
     * An unknown ACTIVE gateway proves nothing about the stored one. Reusing on
     * a blank comparison would make every order reusable the moment adapter
     * naming broke.
     */
    public function test_an_empty_active_gateway_makes_nothing_reusable(): void
    {
        $this->assertFalse(Payment_order_provenance::matchesActiveGateway('razorpay', ''));
        $this->assertFalse(Payment_order_provenance::matchesActiveGateway('razorpay', '   '));
        $this->assertFalse(Payment_order_provenance::matchesActiveGateway('', ''));
    }

    // ══ IT MUST NOT OVER-REJECT AND CAUSE DOUBLE CHARGES ════════════════════

    public function test_casing_and_padding_do_not_strand_a_live_order(): void
    {
        foreach ([['Razorpay', 'razorpay'], ['RAZORPAY', 'Razorpay'], [' razorpay ', 'razorpay']] as [$a, $b]) {
            $this->assertTrue(
                Payment_order_provenance::matchesActiveGateway($a, $b),
                "[{$a}] vs [{$b}] discards a valid in-flight order, creating a duplicate "
                . 'charge for a payment already in progress'
            );
        }
    }

    // ══ THE ADMISSION PATH MUST ACTUALLY USE IT ═════════════════════════════

    /**
     * A shared predicate that admission does not call would leave the defect
     * exactly where it was while looking fixed.
     */
    public function test_admission_reuse_is_gated_on_the_shared_predicate(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Admission_public.php');

        $at = strpos($src, "De-dup (#11)");
        $this->assertNotFalse($at, 'the admission de-dup block is gone');
        $block = substr($src, $at, 3200);

        $this->assertStringContainsString(
            'Payment_order_provenance::matchesActiveGateway(', $block,
            'admission reuses a pending order without checking which gateway minted it — '
            . 'the fees defect, unfixed, in the admission path'
        );

        $this->assertStringContainsString('$sameGateway', $block,
            'the provenance verdict is computed but not applied to the reuse condition');

        // The verdict must gate the reuse, not merely be computed near it.
        $ifAt   = strpos($block, 'if (is_array($existing)');
        $gateAt = strpos($block, '&& $sameGateway');
        $this->assertNotFalse($ifAt, 'the reuse condition is gone');
        $this->assertNotFalse($gateAt,
            'the reuse condition no longer includes the gateway check, so a mismatched '
            . 'order is returned to the applicant regardless');
        $this->assertGreaterThan($ifAt, $gateAt);
    }

    /** The 15-minute window bounded the old damage; it must survive. */
    public function test_the_freshness_window_is_still_enforced(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Admission_public.php');
        $at  = strpos($src, "De-dup (#11)");
        $block = substr($src, $at, 3200);

        $this->assertStringContainsString('time() - 900', $block,
            'the 15-minute freshness window was dropped while adding the gateway check. '
            . 'It is what stops an indefinitely-old pending order being resurrected.');
    }

    /** Amount matching must survive too — it is the other half of dedupe. */
    public function test_the_amount_predicate_is_still_enforced(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Admission_public.php');
        $at  = strpos($src, "De-dup (#11)");
        $block = substr($src, $at, 3200);

        $this->assertStringContainsString("abs((float) (\$existing['amount'] ?? 0) - \$amount) < 0.01", $block,
            'the amount predicate was lost, so an order for a different amount could be '
            . 'reused');
        $this->assertStringContainsString("['status'] ?? '') === 'created'", $block,
            'the status predicate was lost, so a completed or failed payment could be reused');
    }

    // ══ THE FEES PATH USES THE SAME DEFINITION ══════════════════════════════

    public function test_the_fees_path_uses_the_same_shared_predicate(): void
    {
        $src = (string) file_get_contents(APPPATH . 'libraries/Payment_service.php');

        $this->assertStringContainsString(
            'Payment_order_provenance::matchesActiveGateway(', $src,
            'the fees path has drifted back to its own copy of the rule. Two '
            . 'separately-written predicates is how this defect came to exist in both '
            . 'paths in the first place.');
    }
}
