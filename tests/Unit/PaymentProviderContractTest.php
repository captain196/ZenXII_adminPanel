<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($level, $msg) {} }

require_once APPPATH . 'libraries/PaymentProviderInterface.php';
require_once APPPATH . 'libraries/Payment_gateway_razorpay.php';
require_once APPPATH . 'libraries/Payment_gateway_mock.php';
require_once APPPATH . 'libraries/Payment_gateway_unavailable.php';
require_once APPPATH . 'libraries/Payment_gateway_cashfree.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  P1 — THE SHARED PROVIDER CONTRACT SUITE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Every real, mock, and refusal adapter must pass THIS SAME suite — that is
 * the whole point of formalizing the contract. A future fourth adapter that
 * passes this file has proven the properties Billing V2 actually depends on;
 * one that fails it has found a real gap before it ever reaches
 * Payment_service.
 *
 * ── WHAT IS AND ISN'T TESTED HERE ─────────────────────────────────────────
 * `create_order()` and `fetch_payment()` on the REAL Razorpay adapter make
 * network calls (`curl_init` against api.razorpay.com) — never invoked here.
 * Razorpay is exercised only through what is genuinely local: `get_name()`,
 * `capabilities()`, and `verify_signature()` (pure HMAC, no network). The
 * mock and unavailable adapters ARE fully network-free and are exercised
 * completely, including `create_order()`/`fetch_payment()`.
 *
 * This is a deliberate, named limitation, not an oversight: a test suite
 * that hit the real Razorpay API on every run would be flaky, slow, and
 * would leak test orders into a real merchant account — exactly the kind of
 * residue GW-TEST-1's own design explicitly avoided.
 */
class PaymentProviderContractTest extends TestCase
{
    private function adapters(): array
    {
        return [
            'razorpay'    => new Payment_gateway_razorpay(['api_key' => 'rzp_test_contract', 'api_secret' => 'contract_secret']),
            'mock'        => new Payment_gateway_mock(),
            'unavailable' => new Payment_gateway_unavailable(),
            // P17: the second real adapter must pass the SAME suite unchanged.
            'cashfree'    => new Payment_gateway_cashfree(['api_key' => 'cf_contract', 'api_secret' => 'cf_secret']),
        ];
    }

    // ══ 1 · EVERY ADAPTER GENUINELY SATISFIES THE INTERFACE ═════════════════

    /**
     * php -l only checks syntax — it does NOT verify an `implements` clause is
     * actually satisfiable (a missing/mismatched method is a runtime fatal,
     * not a parse error). This is the real proof.
     */
    public function test_every_adapter_is_instanceof_the_interface(): void
    {
        foreach ($this->adapters() as $name => $adapter) {
            $this->assertInstanceOf(PaymentProviderInterface::class, $adapter,
                "{$name} does not satisfy PaymentProviderInterface");
        }
    }

    /** Interface method count is exactly what the docblock claims — no drift either way. */
    public function test_interface_declares_exactly_the_documented_methods(): void
    {
        $methods = get_class_methods(PaymentProviderInterface::class);
        sort($methods);
        $this->assertSame(
            ['capabilities', 'create_order', 'fetch_payment', 'get_name', 'verify_signature'],
            $methods
        );
    }

    /**
     * `simulate_payment` must NOT be part of the formal contract — Razorpay
     * cannot honestly implement it (throws by design). A future real adapter
     * must not be forced to pretend it can simulate itself.
     */
    public function test_simulate_payment_is_not_part_of_the_interface(): void
    {
        $this->assertNotContains('simulate_payment', get_class_methods(PaymentProviderInterface::class),
            'simulate_payment() was added to the formal contract — this forces every future '
            . 'real provider adapter to implement an operation only a simulator can honestly '
            . 'support (Razorpay already throws on it)');
    }

    // ══ 2 · IDENTITY ══════════════════════════════════════════════════════

    public function test_provider_identity_is_stable_and_non_empty(): void
    {
        foreach ($this->adapters() as $name => $adapter) {
            $id = $adapter->get_name();
            $this->assertNotSame('', $id, "{$name}->get_name() is empty");
            $this->assertSame($id, $adapter->get_name(), "{$name}->get_name() is not stable across calls");
        }
    }

    /**
     * EVERY identity must be distinct — GW-ORD-1 depends entirely on this.
     * Two adapters sharing a name would make provenance comparison meaningless,
     * and cross-provider order reuse would silently become possible.
     */
    public function test_all_provider_identities_are_distinct(): void
    {
        $ids = array_map(fn($a) => $a->get_name(), $this->adapters());
        $this->assertSame(array_values(array_unique($ids)), array_values($ids),
            'two adapters report the same provider identity');
    }

    // ══ 3 · CAPABILITIES ══════════════════════════════════════════════════

    private const REQUIRED_CAPABILITY_KEYS = [
        'checkout_type', 'cards', 'upi', 'net_banking', 'wallets',
        'webhooks', 'refunds', 'simulatable',
    ];

    public function test_every_adapter_declares_the_full_capability_shape(): void
    {
        foreach ($this->adapters() as $name => $adapter) {
            $caps = $adapter->capabilities();
            $this->assertIsArray($caps, "{$name}->capabilities() did not return an array");
            foreach (self::REQUIRED_CAPABILITY_KEYS as $key) {
                $this->assertArrayHasKey($key, $caps,
                    "{$name}->capabilities() is missing '{$key}' — an unsupported capability "
                    . 'must be explicitly false/null, never simply absent, or a consumer cannot '
                    . 'tell "unsupported" from "the adapter forgot to declare it"');
            }
        }
    }

    /**
     * No adapter in this codebase implements refunds (PA-CON-2, confirmed by
     * the architecture audit's executable-token census). Every adapter must
     * say so explicitly.
     */
    public function test_no_adapter_claims_refund_capability(): void
    {
        foreach ($this->adapters() as $name => $adapter) {
            $this->assertFalse($adapter->capabilities()['refunds'],
                "{$name} claims refund capability, but no adapter implements a refund method — "
                . 'this would be a promise the Parent App or a future registry would act on '
                . 'with nothing behind it');
        }
    }

    /** Only the mock may claim simulation — this is the capability-gating proof itself. */
    public function test_only_the_mock_declares_itself_simulatable(): void
    {
        $adapters = $this->adapters();
        $this->assertTrue($adapters['mock']->capabilities()['simulatable']);
        $this->assertFalse($adapters['razorpay']->capabilities()['simulatable'],
            'Razorpay claims simulation capability while its simulate_payment() throws — '
            . 'this is exactly the false promise capability-gating exists to prevent');
        $this->assertFalse($adapters['unavailable']->capabilities()['simulatable']);
        $this->assertFalse($adapters['cashfree']->capabilities()['simulatable'],
            'Cashfree is a real provider and must never claim simulation capability');
    }

    /** The refusal sentinel must claim NOTHING — a single true would be a broken promise. */
    public function test_unavailable_declares_every_capability_false(): void
    {
        $caps = $this->adapters()['unavailable']->capabilities();

        // checkout_type is string|null, not boolean — its "nothing claimed"
        // value is null, checked on its own rather than forced through assertFalse.
        $this->assertNull($caps['checkout_type'],
            'Payment_gateway_unavailable declares a checkout_type — a refusal sentinel has '
            . 'no checkout mechanism to describe');

        foreach (self::REQUIRED_CAPABILITY_KEYS as $key) {
            if ($key === 'checkout_type') continue;
            $this->assertFalse($caps[$key],
                "Payment_gateway_unavailable claims '{$key}' capability — this class exists "
                . 'specifically to promise nothing');
        }
    }

    // ══ 4 · SIGNATURE VERIFICATION (pure, no network) ════════════════════

    public function test_razorpay_accepts_a_correctly_computed_signature(): void
    {
        $adapter = new Payment_gateway_razorpay(['api_key' => 'k', 'api_secret' => 'contract_secret']);
        $orderId = 'order_CONTRACT1'; $paymentId = 'pay_CONTRACT1';
        $signature = hash_hmac('sha256', $orderId . '|' . $paymentId, 'contract_secret');

        $this->assertTrue($adapter->verify_signature($orderId, $paymentId, $signature));
    }

    /**
     * THE CRITICAL NEGATIVE CASE. If a tampered signature were ever accepted,
     * every downstream money-safety guarantee in Payment_service is void —
     * verify_signature() is the single gate a forged callback must pass.
     */
    public function test_razorpay_rejects_a_tampered_signature(): void
    {
        $adapter = new Payment_gateway_razorpay(['api_key' => 'k', 'api_secret' => 'contract_secret']);
        $orderId = 'order_CONTRACT2'; $paymentId = 'pay_CONTRACT2';
        $wrongSignature = hash_hmac('sha256', $orderId . '|' . $paymentId, 'WRONG_SECRET');

        $this->assertFalse($adapter->verify_signature($orderId, $paymentId, $wrongSignature));
    }

    public function test_unavailable_never_verifies_any_signature(): void
    {
        $adapter = $this->adapters()['unavailable'];
        // Even a signature that would be trivially correct if the secret were
        // known must still be refused — there is no secret this class holds.
        $this->assertFalse($adapter->verify_signature('order_X', 'pay_X', 'anything-at-all'));
    }

    // ══ 5 · ORDER CREATION — NEVER A FABRICATED REFERENCE ═══════════════════

    /**
     * The refusal sentinel must not hand back anything that LOOKS like a real
     * order id — an empty string is unambiguous; a plausible-looking fake id
     * is exactly the GW-MOCK-1 failure mode (a fabricated order handed to a
     * real checkout) in miniature.
     */
    public function test_unavailable_create_order_returns_no_usable_order_reference(): void
    {
        $result = $this->adapters()['unavailable']->create_order(700.0, 'receipt-1', []);
        $this->assertSame('', $result['order_id'] ?? 'MISSING',
            'Payment_gateway_unavailable::create_order() returned a non-empty order_id — a '
            . 'Parent App checkout could open against an order that was never actually created');
    }

    public function test_mock_create_order_returns_a_real_looking_but_clearly_mock_prefixed_id(): void
    {
        $result = $this->adapters()['mock']->create_order(700.0, 'receipt-1', []);
        $this->assertStringStartsWith('ORD_', $result['order_id'] ?? '',
            'the mock order id no longer carries its distinguishing ORD_ prefix — GW-ORD-1 '
            . 'depends on mock and real provider ids being visually and structurally distinct');
    }

    // ══ 6 · PAYMENT FACTS NORMALIZE CORRECTLY ════════════════════════════

    public function test_unavailable_fetch_payment_reports_zero_amount_not_null_or_missing(): void
    {
        $result = $this->adapters()['unavailable']->fetch_payment('pay_X');
        $this->assertSame(0, $result['amount_paise'] ?? -1,
            'a missing/negative amount_paise here would fail to trip amount-mismatch '
            . 'enforcement in Payment_service — it must be an explicit, comparable zero');
        $this->assertNotSame('captured', $result['status'] ?? '',
            'Payment_gateway_unavailable must never report a captured status');
    }

    // ══ 7 · STRUCTURAL — NO ADAPTER TOUCHES FIRESTORE OR MONEY STATE ═══════

    /**
     * None of the three adapter constructors accept a Firestore dependency —
     * structurally provable, not merely observed: `Payment_gateway_razorpay`
     * takes only api_key/api_secret/api_base; `Payment_gateway_mock` and
     * `Payment_gateway_unavailable` take no constructor arguments at all. A
     * class with no injected Firestore handle cannot write to Firestore.
     */
    /**
     * Comment-stripped before scanning — matches the standard used throughout
     * the provider-agnostic architecture audit. The class docblocks here
     * legitimately DISCUSS receipts/allocations/journals in prose (explaining
     * why the refusal exists); scanning raw source would false-positive on
     * that explanatory text, which is the opposite of what this test should
     * catch. Only executable references count.
     */
    private function executableSource(string $file): string
    {
        $src = (string) file_get_contents(APPPATH . "libraries/{$file}.php");
        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $code .= $t[1];
            } else {
                $code .= $t;
            }
        }
        return $code;
    }

    public function test_no_adapter_file_references_firestore_or_receipt_allocation_state(): void
    {
        $forbidden = ['firestoreSet(', 'firestoreGet(', 'firestoreQuery(', 'firestoreCommit',
                      '->fs->', 'receipt_key', 'allocat', 'journal', 'bankLedgerCode'];

        foreach (['Payment_gateway_razorpay', 'Payment_gateway_mock', 'Payment_gateway_unavailable'] as $class) {
            $code = $this->executableSource($class);
            foreach ($forbidden as $token) {
                $this->assertStringNotContainsStringIgnoringCase($token, $code,
                    "{$class}.php's EXECUTABLE code references '{$token}' — an adapter must "
                    . 'never touch Firestore or ZenX money-record state directly; only '
                    . 'Payment_service, Fee_payment_claim and Operations_accounting may');
            }
        }
    }

    /** None of the three constructors accept anything Firestore-shaped. */
    public function test_no_adapter_constructor_accepts_a_firestore_handle(): void
    {
        foreach (['Payment_gateway_razorpay', 'Payment_gateway_mock', 'Payment_gateway_unavailable'] as $class) {
            $ref = new ReflectionClass($class);
            if (!$ref->hasMethod('__construct')) continue;
            $ctor = $ref->getMethod('__construct');
            foreach ($ctor->getParameters() as $param) {
                $type = $param->getType();
                $typeName = $type ? (string) $type : '';
                $this->assertStringNotContainsStringIgnoringCase('firebase', $typeName,
                    "{$class}'s constructor accepts a Firebase-typed parameter");
                $this->assertStringNotContainsStringIgnoringCase('firestore', $typeName,
                    "{$class}'s constructor accepts a Firestore-typed parameter");
            }
        }
    }
}
