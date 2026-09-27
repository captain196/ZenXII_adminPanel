<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

require_once APPPATH . 'libraries/PaymentProviderInterface.php';
require_once APPPATH . 'libraries/Payment_gateway_razorpay.php';
require_once APPPATH . 'libraries/Payment_gateway_selector.php';
require_once APPPATH . 'libraries/Payment_service.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  P2 — CALLBACK/WEBHOOK FIELD PARSING MOVED INTO THE ADAPTER
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `parent_verify_payment()` and `payment_webhook()` each duplicated the same
 * razorpay_* → generic field normalization inline. Extracted to
 * `Payment_gateway_razorpay::parse_callback()`, reached through
 * `Payment_service::parse_callback()` (a thin, duck-typed passthrough) or,
 * at the two controller sites that run before Payment_service exists yet,
 * through `Payment_gateway_selector::adapter_class()` — the SAME registry
 * lookup used everywhere else, not a second authority.
 *
 * `parent_verify_payment()` was deliberately NOT wired to call the adapter —
 * doing so would cost a second Firestore read of the gateway config, since
 * that site runs before _init_payment_service() reads it. Its inline parse
 * is unchanged and is covered by its own (pre-existing) call-site behaviour;
 * this file proves the EXTRACTED pieces work, and that `payment_webhook()` —
 * which already reads the config for its IP-allowlist check, so calling the
 * adapter costs nothing extra — actually uses them.
 */
class ProviderProtocolParsingTest extends TestCase
{
    // ══ THE ADAPTER METHOD ══════════════════════════════════════════════

    public function test_razorpay_parses_its_own_prefixed_fields(): void
    {
        $adapter = new Payment_gateway_razorpay();
        $result = $adapter->parse_callback([
            'razorpay_order_id'   => ' order_ABC ',
            'razorpay_payment_id' => ' pay_XYZ ',
            'razorpay_signature'  => ' sig123 ',
        ]);

        $this->assertSame(['order_id' => 'order_ABC', 'payment_id' => 'pay_XYZ', 'signature' => 'sig123'], $result);
    }

    public function test_razorpay_falls_back_to_generic_field_names(): void
    {
        $adapter = new Payment_gateway_razorpay();
        $result = $adapter->parse_callback([
            'order_id'   => 'order_GENERIC',
            'payment_id' => 'pay_GENERIC',
            'signature'  => 'sig_GENERIC',
        ]);

        $this->assertSame('order_GENERIC', $result['order_id']);
        $this->assertSame('pay_GENERIC', $result['payment_id']);
        $this->assertSame('sig_GENERIC', $result['signature']);
    }

    /**
     * The documented, deliberate strengthening: the webhook site never
     * trimmed before P2; the callback site always did. Unifying on "always
     * trim" can only ever ACCEPT a value the webhook path previously
     * rejected for incidental whitespace — never reject one it used to
     * accept.
     */
    public function test_parse_callback_trims_whitespace_a_documented_strengthening(): void
    {
        $adapter = new Payment_gateway_razorpay();
        $result = $adapter->parse_callback(['razorpay_order_id' => "  order_1  \n"]);
        $this->assertSame('order_1', $result['order_id']);
    }

    public function test_missing_fields_resolve_to_empty_string_not_null_or_missing_key(): void
    {
        $adapter = new Payment_gateway_razorpay();
        $result = $adapter->parse_callback([]);
        $this->assertSame(['order_id' => '', 'payment_id' => '', 'signature' => ''], $result);
    }

    // ══ THE Payment_service PASSTHROUGH ═════════════════════════════════

    private function serviceWithGateway(object $gateway): Payment_service
    {
        $svc = new Payment_service();
        $ref = new ReflectionClass($svc);
        $prop = $ref->getProperty('gateway');
        $prop->setAccessible(true);
        $prop->setValue($svc, $gateway);
        return $svc;
    }

    public function test_payment_service_delegates_to_the_adapters_own_parser(): void
    {
        $svc = $this->serviceWithGateway(new Payment_gateway_razorpay());
        $result = $svc->parse_callback(['razorpay_order_id' => 'order_DELEGATED']);
        $this->assertSame('order_DELEGATED', $result['order_id']);
    }

    /**
     * An adapter WITHOUT parse_callback() (mock, unavailable, or any future
     * adapter not yet extracted) must not fatal — the generic fallback keeps
     * the service usable.
     */
    public function test_payment_service_falls_back_generically_for_an_adapter_without_the_method(): void
    {
        $bareAdapter = new class {
            public function get_name(): string { return 'bare'; }
        };
        $svc = $this->serviceWithGateway($bareAdapter);
        $result = $svc->parse_callback(['gateway_order_id' => 'order_FALLBACK']);
        $this->assertSame('order_FALLBACK', $result['order_id']);
    }

    /** Payment_service must still be provider-neutral after this addition. */
    public function test_payment_service_still_has_zero_executable_provider_tokens(): void
    {
        $src = (string) file_get_contents(APPPATH . 'libraries/Payment_service.php');
        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) { if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $code .= $t[1]; }
            else $code .= $t;
        }
        $this->assertSame(0, preg_match_all('/razorpay|rzp_test_|rzp_live_|paytm|stripe/i', $code),
            'Payment_service::parse_callback() reintroduced a provider name — the passthrough '
            . 'must stay duck-typed, exactly like get_name()/fetch_payment() already are');
    }

    // ══ THE REGISTRY LOOKUP ══════════════════════════════════════════════

    public function test_adapter_class_resolves_known_providers(): void
    {
        $this->assertSame('Payment_gateway_razorpay', Payment_gateway_selector::adapter_class('razorpay'));
        $this->assertSame('Payment_gateway_mock', Payment_gateway_selector::adapter_class('mock'));
    }

    public function test_adapter_class_is_case_insensitive(): void
    {
        $this->assertSame('Payment_gateway_razorpay', Payment_gateway_selector::adapter_class('RAZORPAY'));
        $this->assertSame('Payment_gateway_razorpay', Payment_gateway_selector::adapter_class(' Razorpay '));
    }

    public function test_adapter_class_refuses_unknown_providers(): void
    {
        $this->assertNull(Payment_gateway_selector::adapter_class('cashfree'));
        $this->assertNull(Payment_gateway_selector::adapter_class(''));
        $this->assertNull(Payment_gateway_selector::adapter_class('unavailable'));
    }

    // ══ THE CONTROLLER SITES ACTUALLY ROUTE THROUGH THIS ═══════════════════

    /**
     * `payment_webhook()` cannot be driven directly in this suite without
     * bootstrapping the full CI3 controller (RBAC, rate limiting, IP
     * filtering, HMAC) — the same limitation `GatewayFallbackSafetyTest`
     * documents. Rather than accept a source-only proxy for that (which a
     * mutation test proved CANNOT distinguish reachable from dead code — an
     * earlier version of this test kept passing after the controller's `if`
     * was mutated to `if (false)`), the resolve-and-parse decision itself was
     * pulled out of the controller into
     * `Payment_gateway_selector::parse_provider_callback()` — a pure
     * function with no CI3 dependency, driven directly below. This test now
     * only proves the controller calls that ONE function; the function's
     * OWN correctness is proven by the mutation-tested cases that follow.
     */
    public function test_payment_webhook_routes_parsing_through_the_shared_function(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');
        $fn = strpos($src, 'public function payment_webhook');
        $this->assertNotFalse($fn);
        $end = strpos($src, "\n    public function ", $fn + 30);
        $body = substr($src, $fn, ($end !== false ? $end - $fn : 9000));

        $this->assertStringContainsString('Payment_gateway_selector::parse_provider_callback(', $body,
            'payment_webhook() no longer calls the shared resolve-and-parse function');
        $this->assertStringNotContainsString('razorpay_order_id', $body,
            'payment_webhook() has a razorpay-specific field name back in its own body — '
            . 'the extraction was reverted at this call site');
    }

    // ══ THE PURE resolve-and-parse FUNCTION — fully behaviourally driven ═══

    public function test_parse_provider_callback_routes_through_the_correct_adapter(): void
    {
        $result = Payment_gateway_selector::parse_provider_callback(
            ['provider' => 'razorpay'],
            ['razorpay_order_id' => 'order_ROUTED', 'razorpay_payment_id' => 'pay_ROUTED', 'razorpay_signature' => 'sig_ROUTED']
        );
        $this->assertSame(['order_id' => 'order_ROUTED', 'payment_id' => 'pay_ROUTED', 'signature' => 'sig_ROUTED'], $result);
    }

    public function test_parse_provider_callback_falls_back_for_an_unknown_provider(): void
    {
        $result = Payment_gateway_selector::parse_provider_callback(
            ['provider' => 'cashfree'],
            ['gateway_order_id' => 'order_FALLBACK']
        );
        $this->assertSame('order_FALLBACK', $result['order_id']);
    }

    public function test_parse_provider_callback_falls_back_for_malformed_config(): void
    {
        $result = Payment_gateway_selector::parse_provider_callback(
            'not-an-array',
            ['gateway_order_id' => 'order_MALFORMED']
        );
        $this->assertSame('order_MALFORMED', $result['order_id']);
    }

    /**
     * Explicitly documents WHY parent_verify_payment was left as inline parse
     * — this is a source-anchored record of a deliberate decision, not an
     * oversight, so a future pass does not "fix" it into an extra read
     * without re-deriving the same tradeoff.
     */
    public function test_parent_verify_payment_documents_why_it_was_not_extracted(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');
        $fn = strpos($src, 'public function parent_verify_payment');
        $body = substr($src, $fn, 2000);

        $this->assertStringContainsString('NOT routed through the adapter/registry here', $body,
            'the rationale for leaving this site as inline parse is gone — either it was '
            . 'routed through the registry (re-derive the double-read tradeoff first) or the '
            . 'explanation was deleted, leaving the divergence between the two sites unexplained');
    }
}
