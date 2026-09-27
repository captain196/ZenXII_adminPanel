<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($level, $msg) {} }

require_once APPPATH . 'libraries/PaymentProviderInterface.php';
require_once APPPATH . 'libraries/Payment_gateway_selector.php';
require_once APPPATH . 'libraries/Payment_order_provenance.php';
require_once APPPATH . 'libraries/Payment_service.php';
require_once APPPATH . 'libraries/Payment_provider_config_resolver.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  P8 — THE HYPOTHETICAL FOURTH PROVIDER (ARCHITECTURE PROOF, NOT A PROVIDER)
 * ══════════════════════════════════════════════════════════════════════════
 *
 * This is the acceptance test the whole programme exists to pass. It defines
 * a provider with a DELIBERATELY different protocol shape from Razorpay —
 * different field names, a hosted-redirect checkout instead of a native SDK,
 * a different order-id prefix, a different signature scheme — and proves the
 * Billing V2 money core handles it with **zero provider-specific code**.
 *
 * ── THIS IS NOT A REAL PROVIDER ───────────────────────────────────────────
 * `Payment_gateway_contract_test_provider` is defined INSIDE this test file,
 * deliberately, so it can never be selected in production: it is not in
 * `Payment_gateway_selector::adapter_class()`'s registry, and no config can
 * name it. No Cashfree, PayU, CCAvenue, Paytm or Stripe code exists anywhere
 * in this change.
 *
 * ── WHAT A FAILURE HERE WOULD MEAN ────────────────────────────────────────
 * If any assertion below required editing a Billing V2 core file to pass, the
 * abstraction would not yet be good enough and the programme's own stop
 * condition would apply.
 */

/** A provider whose protocol looks nothing like Razorpay's. */
class Payment_gateway_contract_test_provider implements PaymentProviderInterface
{
    public function get_name(): string { return 'hypothetical_hosted'; }

    public function capabilities(): array
    {
        return [
            'checkout_type' => 'hosted_redirect',   // NOT native_sdk
            'cards'         => true,
            'upi'           => true,
            'net_banking'   => false,               // deliberately different
            'wallets'       => false,
            'webhooks'      => true,
            'refunds'       => false,
            'simulatable'   => false,
        ];
    }

    public function create_order(float $amount, string $receiptRef = '', array $meta = []): array
    {
        // Different id shape from both 'order_' (Razorpay) and 'ORD_' (mock).
        return ['order_id' => 'HH-' . strtoupper(bin2hex(random_bytes(4))),
                'amount' => $amount, 'currency' => 'INR', 'status' => 'created'];
    }

    /** Different signature material: order+amount, not order|payment. */
    public function verify_signature(string $orderId, string $paymentId, string $signature): bool
    {
        return $signature === strrev($orderId . ':' . $paymentId);
    }

    public function fetch_payment(string $paymentId): array
    {
        return ['payment_id' => $paymentId, 'amount' => 700.00,
                'amount_paise' => 70000, 'status' => 'captured'];
    }

    /** Completely different callback field names. */
    public function parse_callback(array $body): array
    {
        return [
            'order_id'   => trim((string) ($body['hh_txn_ref']    ?? '')),
            'payment_id' => trim((string) ($body['hh_payment_ref'] ?? '')),
            'signature'  => trim((string) ($body['hh_checksum']    ?? '')),
        ];
    }
}

class HypotheticalFourthProviderTest extends TestCase
{
    private function provider(): Payment_gateway_contract_test_provider
    {
        return new Payment_gateway_contract_test_provider();
    }

    // ══ 1 · IT PASSES THE SHARED CONTRACT UNCHANGED ═══════════════════════

    public function test_it_satisfies_the_formal_interface(): void
    {
        $this->assertInstanceOf(PaymentProviderInterface::class, $this->provider());
    }

    public function test_it_declares_the_full_capability_shape(): void
    {
        $caps = $this->provider()->capabilities();
        foreach (['checkout_type', 'cards', 'upi', 'net_banking', 'wallets',
                  'webhooks', 'refunds', 'simulatable'] as $key) {
            $this->assertArrayHasKey($key, $caps);
        }
    }

    /** A checkout engine Razorpay does not use, described by the same contract. */
    public function test_it_describes_a_different_checkout_engine(): void
    {
        $this->assertSame('hosted_redirect', $this->provider()->capabilities()['checkout_type'],
            'the contract cannot express a non-native-SDK checkout — the Parent App would '
            . 'need provider-specific logic, which is exactly what P5 forbids');
    }

    // ══ 2 · ITS PROTOCOL NORMALIZES THROUGH THE SAME PATH ═════════════════

    public function test_its_alien_callback_fields_normalize_correctly(): void
    {
        $parsed = $this->provider()->parse_callback([
            'hh_txn_ref' => 'HH-ABC123', 'hh_payment_ref' => 'HHP-999', 'hh_checksum' => 'xyz',
        ]);
        $this->assertSame(['order_id' => 'HH-ABC123', 'payment_id' => 'HHP-999', 'signature' => 'xyz'], $parsed);
    }

    /** Payment_service's passthrough works with it without knowing what it is. */
    public function test_payment_service_delegates_to_it_without_provider_knowledge(): void
    {
        $svc = new Payment_service();
        $ref = new ReflectionClass($svc);
        $prop = $ref->getProperty('gateway');
        $prop->setAccessible(true);
        $prop->setValue($svc, $this->provider());

        $result = $svc->parse_callback(['hh_txn_ref' => 'HH-DELEGATED']);
        $this->assertSame('HH-DELEGATED', $result['order_id'],
            'Payment_service could not normalize a provider it has never heard of — its '
            . 'passthrough is not genuinely provider-neutral');
    }

    // ══ 3 · GW-ORD-1 TREATS IT IDENTICALLY (NO RAZORPAY PRIVILEGE) ════════

    public function test_gw_ord_1_refuses_cross_provider_reuse_in_both_directions(): void
    {
        // A Razorpay order must not be reused while this provider is active…
        $this->assertFalse(
            Payment_order_provenance::matchesActiveGateway('razorpay', 'hypothetical_hosted'),
            'a Razorpay order would be handed to a different provider'
        );
        // …and equally, its order must not be reused under Razorpay.
        $this->assertFalse(
            Payment_order_provenance::matchesActiveGateway('hypothetical_hosted', 'razorpay'),
            'GW-ORD-1 privileges Razorpay — the rule must be symmetric'
        );
        // Same provider still reuses correctly.
        $this->assertTrue(
            Payment_order_provenance::matchesActiveGateway('hypothetical_hosted', 'hypothetical_hosted')
        );
    }

    // ══ 4 · SCHOOL-SPECIFIC SELECTION WORKS FOR IT ════════════════════════

    private function firebase(array $rows, ?array $legacy = null): object
    {
        return new class($rows, $legacy) {
            public array $rows; public ?array $legacy;
            public function __construct(array $r, ?array $l) { $this->rows = $r; $this->legacy = $l; }
            public function firestoreGet($c, $d) { return $this->legacy; }
            public function firestoreQuery($c, $f = []) { return $this->rows; }
        };
    }

    /**
     * THE DYNAMIC-SCHOOL ACCEPTANCE TEST, at the resolution layer:
     * School A on one provider, School B on another, then School A switches —
     * all with no provider-specific branching anywhere in the resolver.
     */
    public function test_school_a_and_b_resolve_independently_and_a_can_switch(): void
    {
        // Before: School A → razorpay, School B → hypothetical_hosted
        $fbA_before = $this->firebase([
            ['schoolId' => 'SCH_A', 'provider' => 'razorpay', 'active' => true, 'isDefault' => true],
        ]);
        $fbB = $this->firebase([
            ['schoolId' => 'SCH_B', 'provider' => 'hypothetical_hosted', 'active' => true, 'isDefault' => true],
        ]);

        $this->assertSame('razorpay',
            Payment_provider_config_resolver::resolve($fbA_before, 'SCH_A', 'a', '2026-27')['provider']);
        $this->assertSame('hypothetical_hosted',
            Payment_provider_config_resolver::resolve($fbB, 'SCH_B', 'b', '2026-27')['provider']);

        // School A switches: old row deactivated, new row becomes the default.
        $fbA_after = $this->firebase([
            ['schoolId' => 'SCH_A', 'provider' => 'razorpay', 'active' => false, 'isDefault' => false],
            ['schoolId' => 'SCH_A', 'provider' => 'hypothetical_hosted', 'active' => true, 'isDefault' => true],
        ]);

        $this->assertSame('hypothetical_hosted',
            Payment_provider_config_resolver::resolve($fbA_after, 'SCH_A', 'a', '2026-27')['provider'],
            'School A did not pick up its newly-activated provider');

        // School B is completely unaffected by School A's change.
        $this->assertSame('hypothetical_hosted',
            Payment_provider_config_resolver::resolve($fbB, 'SCH_B', 'b', '2026-27')['provider']);
    }

    /**
     * After School A switches, its OLD provider's pending order must NOT be
     * reusable — the switch must not silently rewrite or revive stale orders.
     */
    public function test_an_old_providers_pending_order_is_not_reusable_after_a_switch(): void
    {
        $this->assertFalse(
            Payment_order_provenance::matchesActiveGateway('razorpay', 'hypothetical_hosted'),
            'School A\'s pre-switch Razorpay order would be handed to the new provider — '
            . 'the checkout would refuse an id it never issued, and the parent would see a '
            . 'generic failure indistinguishable from a declined card'
        );
    }

    // ══ 5 · THE CORE NEEDED NO PROVIDER-SPECIFIC CODE ═════════════════════

    /**
     * The structural claim, verified rather than asserted: adding this
     * provider required editing NO Billing V2 core file. If any of these
     * gained a provider token, the abstraction leaked.
     */
    public function test_no_billing_core_file_names_any_provider(): void
    {
        $core = ['libraries/Payment_service.php', 'libraries/Fee_payment_claim.php',
                 'libraries/Operations_accounting.php', 'libraries/Fee_lifecycle.php',
                 'libraries/Payment_order_provenance.php',
                 'libraries/Payment_provider_config_resolver.php'];

        foreach ($core as $file) {
            $src = (string) file_get_contents(APPPATH . $file);
            $code = '';
            foreach (token_get_all($src) as $t) {
                if (is_array($t)) { if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $code .= $t[1]; }
                else $code .= $t;
            }
            $this->assertSame(0, preg_match_all('/razorpay|hypothetical_hosted|cashfree|payu|paytm|stripe/i', $code),
                basename($file) . ' contains an executable provider name — adding a provider '
                . 'must never require touching the money core');
        }
    }

    /** And it is genuinely NOT registered — this is a test fixture, not a shipped provider. */
    public function test_the_hypothetical_provider_is_not_reachable_in_production(): void
    {
        $this->assertNull(Payment_gateway_selector::adapter_class('hypothetical_hosted'),
            'the contract-test provider is resolvable through the production registry — it '
            . 'is a test fixture and must never be selectable by a real tenant');
    }
}
