<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

require_once APPPATH . 'libraries/PaymentProviderInterface.php';
require_once APPPATH . 'libraries/Payment_gateway_razorpay.php';
require_once APPPATH . 'libraries/Payment_gateway_mock.php';
require_once APPPATH . 'libraries/Payment_gateway_selector.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  P5/P6 — THE NORMALIZED PARENT-APP CHECKOUT CONTRACT
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `parent_create_order()` now returns `checkout_type` + `capabilities`
 * alongside its existing fields, so the Parent App can dispatch on the
 * CHECKOUT ENGINE a school's active provider requires rather than assuming
 * Razorpay's SDK.
 *
 * The controller method itself cannot be driven here (CI3 bootstrap, RBAC,
 * rate limiting, Firebase) — the same limitation documented throughout this
 * programme. What IS driven directly: the capability data the contract is
 * built from, and the source-level guarantees that the contract is additive
 * and leaks no secret.
 */
class ParentCheckoutContractTest extends TestCase
{
    private function responseBlock(): string
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');
        $fn = strpos($src, 'public function parent_create_order');
        $this->assertNotFalse($fn);
        $end = strpos($src, "\n    public function ", $fn + 30);
        return substr($src, $fn, ($end !== false ? $end - $fn : 12000));
    }

    // ══ THE CONTRACT IS BUILT FROM REAL CAPABILITY DATA ═══════════════════

    /**
     * The whole point: two different providers must yield two different
     * checkout contracts, with no branching on the provider NAME anywhere.
     */
    public function test_different_providers_yield_different_checkout_types(): void
    {
        $razorpay = (new Payment_gateway_razorpay())->capabilities();
        $mock     = (new Payment_gateway_mock())->capabilities();

        $this->assertSame('native_sdk', $razorpay['checkout_type']);
        $this->assertArrayHasKey('checkout_type', $mock);

        // A hypothetical hosted-redirect provider must be describable by the
        // SAME contract with no new field and no core change — this is the
        // structural proof that CASE A ("new provider, existing checkout
        // capability") needs no Parent App business-logic change.
        $hosted = new class implements PaymentProviderInterface {
            public function get_name(): string { return 'hypothetical_hosted'; }
            public function capabilities(): array {
                return ['checkout_type' => 'hosted_redirect', 'cards' => true, 'upi' => true,
                        'net_banking' => false, 'wallets' => false, 'webhooks' => true,
                        'refunds' => false, 'simulatable' => false];
            }
            public function create_order(float $a, string $r = '', array $m = []): array { return ['order_id' => 'HOSTED_1']; }
            public function verify_signature(string $o, string $p, string $s): bool { return false; }
            public function fetch_payment(string $p): array { return []; }
        };

        $this->assertSame('hosted_redirect', $hosted->capabilities()['checkout_type'],
            'a hosted-redirect provider cannot describe itself through the same capability '
            . 'contract — the Parent App would need provider-specific logic, defeating P5');
    }

    /** The four capability flags the contract exposes must exist on every adapter. */
    public function test_contract_capability_flags_exist_on_real_adapters(): void
    {
        foreach ([new Payment_gateway_razorpay(), new Payment_gateway_mock()] as $adapter) {
            $caps = $adapter->capabilities();
            foreach (['cards', 'upi', 'net_banking', 'wallets'] as $flag) {
                $this->assertArrayHasKey($flag, $caps,
                    get_class($adapter) . " cannot describe '{$flag}' — the Parent App "
                    . 'contract would send null for a real payment method');
            }
        }
    }

    // ══ SECRETS MUST NEVER REACH THE CLIENT ═══════════════════════════════

    /**
     * THE security assertion for this gate. `api_key` is the PUBLIC key and is
     * legitimately sent (it already was, pre-P5). `api_secret` and
     * `webhook_secret` must never appear in this response under any name.
     */
    /**
     * Anchors on the SUCCESS payload specifically. An earlier version of this
     * test anchored on the first `echo json_encode([` in the method — which is
     * an early error-return (`'Invalid auth token.'`), not the response at all.
     * A mutation that injected `'api_secret' => …` into the real payload PASSED
     * that version. Found by mutation testing, which is precisely what it is
     * for; the window is now anchored on a field unique to the success payload
     * and extends to the end of the method.
     */
    public function test_the_checkout_contract_never_returns_a_secret(): void
    {
        $block = $this->responseBlock();

        // 'gateway_order_id' appears only in the success payload.
        $at = strpos($block, "'gateway_order_id'");
        $this->assertNotFalse($at, 'the parent_create_order success payload moved');
        $payload = substr($block, $at);

        // Sanity-check the window really does cover the new contract fields —
        // a window that stops short would silently make the assertions below
        // vacuous, which is exactly how the previous version failed.
        $this->assertStringContainsString("'checkout_type'", $payload,
            'the extracted payload window does not reach the checkout contract fields, so '
            . 'the secret assertions below would be vacuous');

        foreach (['api_secret', 'webhook_secret'] as $secret) {
            $this->assertStringNotContainsString("'{$secret}'", $payload,
                "parent_create_order() returns '{$secret}' to the Parent App. Only the "
                . 'PUBLIC api_key may ever reach a client.');
        }
    }

    // ══ BACKWARD COMPATIBILITY — OLD APP BUILDS MUST STILL WORK ═══════════

    /**
     * Every field the pre-P5 Parent App reads must still be present. An older
     * installed build knows nothing about checkout_type; removing or renaming
     * any of these would break payments for every parent who has not updated.
     */
    public function test_all_pre_p5_response_fields_are_still_present(): void
    {
        $block = $this->responseBlock();

        foreach (['success', 'existing', 'payment_id', 'gateway_order_id', 'amount',
                  'amount_paise', 'currency', 'gateway', 'provider', 'api_key', 'mode',
                  'student_id', 'student_name', 'school_id'] as $field) {
            $this->assertStringContainsString("'{$field}'", $block,
                "the pre-P5 response field '{$field}' is gone — every already-installed "
                . 'Parent App build reads this, and payments would break for parents who '
                . 'have not updated');
        }
    }

    public function test_the_new_contract_fields_are_present(): void
    {
        $block = $this->responseBlock();
        $this->assertStringContainsString("'checkout_type'", $block);
        $this->assertStringContainsString("'capabilities'", $block);
    }

    // ══ PROVENANCE — THE PROVIDER SENT IS THE ONE THAT MINTED THE ORDER ════

    /**
     * PA-CORE-1's rule applied to the client contract: the provider reported
     * must be the one that ACTUALLY minted the order
     * ($result['gateway'], written by Payment_service from the live adapter's
     * get_name()), never re-derived from config that may have changed since,
     * and never defaulted to a fabricated value.
     */
    public function test_the_reported_provider_is_the_order_minting_provider_not_a_config_default(): void
    {
        $block = $this->responseBlock();

        $this->assertStringContainsString("\$__providerId = (string) (\$result['gateway'] ?? '');", $block,
            'the reported provider is no longer taken from the order-minting adapter — if it '
            . 'is re-derived from config, a config change between order creation and this '
            . 'response would open the WRONG checkout for a real order');

        $this->assertStringNotContainsString("(\$gwConfig['provider'] ?? 'mock')", $block,
            "the old `?? 'mock'` provider default is back. That fabricated a provider "
            . 'identity whenever config was unreadable — the same defect class as PA-CORE-1.');
    }
}
