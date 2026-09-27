<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once APPPATH . 'libraries/Payment_gateway_unavailable.php';
require_once APPPATH . 'libraries/Payment_gateway_mock.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  GW-1 — AN UNCONFIGURED GATEWAY MUST REFUSE, NOT SIMULATE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `Fee_management::_init_payment_service()` resolved the gateway adapter as:
 *
 *     razorpay        → Payment_gateway_razorpay
 *     EVERYTHING ELSE → Payment_gateway_mock
 *
 * and "everything else" was reachable two ways. The config screen accepted
 * 'stripe' and 'paytm', neither of which has an adapter — and, far more
 * commonly, the `$provider` default is 'mock' for any tenant that has never
 * configured a gateway at all.
 *
 * The mock is NOT a neutral stand-in. The tests below drive the REAL mock class
 * to establish exactly what that fallback conceded:
 *
 *   1. its signature check passes for a signature ANY reader of this repository
 *      can compute — the secret is a constant in the source;
 *   2. it sets `mock_skip_amount_check`, which switches OFF amount enforcement
 *      in Payment_service;
 *   3. it always reports 'captured'.
 *
 * Combined: an authenticated parent on a tenant with no gateway configured
 * could mint a receipt, clear a demand and post an accounting journal without
 * paying anything. The school's books would show income that never arrived.
 *
 * The admission module already refused the mock outside development/testing
 * (Admission_public.php:897, :1126). Fees never adopted it.
 *
 * These tests exercise the actual adapter objects — not source text — so they
 * fail if the refusing adapter ever softens.
 */
class GatewayFallbackSafetyTest extends TestCase
{
    // ══ WHAT THE OLD FALLBACK ACTUALLY CONCEDED ═════════════════════════════

    /**
     * THE CONTROL, and the justification for the whole fix. If the mock ever
     * stopped accepting a source-derived signature, the severity of GW-1 would
     * be different and this file should be re-derived rather than trusted.
     *
     * Deliberately recomputes the signature the way an attacker would: from the
     * constant visible in Payment_gateway_mock.php.
     */
    public function test_control_the_mock_accepts_a_signature_computed_from_public_source(): void
    {
        $mock = new Payment_gateway_mock();

        $orderId   = 'order_ATTACK';
        $paymentId = 'pay_ATTACK';
        // Lifted straight from the mock's own source — that is the point.
        $forged = hash_hmac('sha256', $orderId . '|' . $paymentId, 'mock_secret_key_for_dev');

        $this->assertTrue(
            $mock->verify_signature($orderId, $paymentId, $forged),
            'the mock no longer accepts a signature derived from the hardcoded secret. '
            . 'GW-1 rested on this being forgeable — re-derive the finding before relying '
            . 'on the rest of this file.'
        );
    }

    /**
     * The second half of what the fallback conceded: amount enforcement off.
     */
    public function test_control_the_mock_disables_amount_enforcement(): void
    {
        $fetched = (new Payment_gateway_mock())->fetch_payment('pay_X');

        $this->assertArrayHasKey('mock_skip_amount_check', $fetched);
        $this->assertTrue((bool) $fetched['mock_skip_amount_check'],
            'Payment_service reads this sentinel to SKIP amount validation. With it set '
            . 'on a live tenant, a payment of any amount clears a demand of any size.');
        $this->assertSame('captured', $fetched['status'],
            'the mock reports every payment as captured');
    }

    // ══ THE REFUSING ADAPTER ════════════════════════════════════════════════

    /**
     * The single most important assertion here. This is the check a forged
     * callback must pass, and the adapter installed for an unconfigured tenant
     * must never pass it — for ANY input, including one computed from the
     * mock's secret.
     */
    public function test_the_refusing_adapter_never_verifies_a_signature(): void
    {
        $gw = new Payment_gateway_unavailable();

        $forged = hash_hmac('sha256', 'order_A|pay_A', 'mock_secret_key_for_dev');

        $this->assertFalse($gw->verify_signature('order_A', 'pay_A', $forged),
            'the refusing adapter accepted a forged signature — an unconfigured tenant '
            . 'would be back to free fee clearing');
        $this->assertFalse($gw->verify_signature('order_A', 'pay_A', ''));
        $this->assertFalse($gw->verify_signature('', '', 'anything'));
        $this->assertFalse($gw->verify_signature('order_A', 'pay_A', str_repeat('f', 64)));
    }

    /**
     * It must NOT carry the sentinel that switches amount checking off.
     * Inheriting the mock's shape here would have preserved the worst half of
     * the defect while looking like a fix.
     */
    public function test_the_refusing_adapter_does_not_disable_amount_enforcement(): void
    {
        $fetched = (new Payment_gateway_unavailable())->fetch_payment('pay_X');

        $this->assertArrayNotHasKey('mock_skip_amount_check', $fetched,
            'the refusing adapter carries the mock sentinel, so Payment_service would skip '
            . 'amount validation for an unconfigured tenant — the more dangerous half of GW-1');
        $this->assertNotSame('captured', $fetched['status'],
            'an unconfigured gateway must never report a captured payment');
    }

    public function test_the_refusing_adapter_cannot_create_a_usable_order(): void
    {
        $order = (new Payment_gateway_unavailable())->create_order(5000.0, 'REF1');

        $this->assertFalse($order['success'] ?? true);
        $this->assertSame('', $order['order_id'] ?? 'x',
            'a well-formed order id would let the Parent app open a checkout that can '
            . 'never be honoured');
    }

    public function test_the_refusing_adapter_cannot_simulate_a_successful_payment(): void
    {
        $sim = (new Payment_gateway_unavailable())->simulate_payment('order_A', 100.0);

        $this->assertSame('failed', $sim['status'],
            'a simulated payment reported success — a test harness pointed at a '
            . 'misconfigured tenant would report a passing payment flow');
        $this->assertSame('', $sim['signature'],
            'a signature was issued that could later be replayed against verify_signature');
    }

    /**
     * "not configured" and "deliberately simulated" are different operational
     * states. Conflating them is how the fallback stayed invisible.
     */
    public function test_the_refusing_adapter_is_distinguishable_from_the_mock(): void
    {
        $this->assertSame('unavailable', (new Payment_gateway_unavailable())->get_name());
        $this->assertNotSame(
            (new Payment_gateway_mock())->get_name(),
            (new Payment_gateway_unavailable())->get_name()
        );
    }

    /**
     * Interface parity — Payment_service calls exactly these five. A missing one
     * raises an Error, not an Exception (FEE-W1), and would kill the request
     * rather than refuse the payment.
     */
    public function test_the_refusing_adapter_implements_the_whole_adapter_surface(): void
    {
        foreach (['create_order', 'verify_signature', 'fetch_payment', 'simulate_payment', 'get_name'] as $m) {
            $this->assertTrue(method_exists('Payment_gateway_unavailable', $m),
                "the refusing adapter is missing {$m}(). Payment_service calls it, and a "
                . 'missing method raises an Error that catch(\\Exception) will not catch — '
                . 'turning a safe refusal into a fatal.');
        }
    }

    // ══ SELECTION AND CONFIGURATION ═════════════════════════════════════════

    /**
     * WOULD HAVE FAILED BEFORE: the else-branch loaded the mock unconditionally.
     *
     * ── GW-SEL-1, 2026-08-24 ──────────────────────────────────────────────
     * This test used to string-match the controller source, because a CI
     * controller cannot be instantiated and the decision was inline. That
     * proved the code LOOKED right, never that it BEHAVED right.
     *
     * The decision now lives in `Payment_gateway_selector` and is executed
     * directly by `PaymentGatewaySelectionTest`. What remains here is the
     * assertion that the controller actually DELEGATES to it — a selector that
     * nothing calls would be a perfect, inert guard. The coverage is strictly
     * wider than before, not narrower: the environment gate and the
     * unresolved-provider rule are now driven, not read.
     */
    public function test_the_controller_delegates_selection_to_the_tested_selector(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');

        $fn = strpos($src, 'private function _init_payment_service');
        $this->assertNotFalse($fn);
        $end  = strpos($src, "\n    private function ", $fn + 30);
        $body = substr($src, $fn, ($end !== false ? $end - $fn : 6000));

        $this->assertStringContainsString('Payment_gateway_selector::select(', $body,
            'the money path no longer asks the selector which adapter may collect, so the '
            . 'behavioural guarantees proven in PaymentGatewaySelectionTest no longer apply '
            . 'to production');

        $this->assertStringContainsString('Payment_gateway_unavailable', $body,
            'the gateway selection no longer falls back to a refusing adapter. An '
            . 'unsupported provider — or a tenant with no gateway configured at all — '
            . 'would again be served by the forgeable mock.');

        // The mock may only be reached through the selector's own gate.
        $this->assertStringContainsString('Payment_gateway_selector::MOCK', $body,
            'the mock branch is keyed on something other than the selector verdict, so it '
            . 'can be entered without the environment gate');
        $this->assertStringNotContainsString("\$provider === 'mock'", $body,
            'the controller decides the mock branch itself again, bypassing the selector');
    }

    // ══ GW-2 · AN UNKNOWN PROVIDER MUST BE LOUD, NOT SILENT ═════════════════

    /**
     * When a gateway cannot report the amount it captured, the payment proceeds
     * on signature validity alone. That fail-open is deliberate — but it must be
     * REPORTED, or an amount-verification outage is invisible.
     *
     * The check was `$gatewayName === 'razorpay'`: an allowlist of one. Its
     * stated purpose was keeping mock-mode quiet, but it also silenced every
     * provider that is not Razorpay — so the day a second adapter is added, an
     * outage on it emits no telemetry and no log. The unknown case is exactly
     * the one that should be loud.
     *
     * WOULD HAVE FAILED BEFORE: both sites read `=== 'razorpay'`.
     */
    public function test_amount_verification_gaps_are_reported_for_every_provider_but_simulators(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');

        $this->assertSame(0, preg_match_all("/if \(\\\$gatewayName(?:Parent)? === 'razorpay'\)/", $src),
            'the amount-verification telemetry is still gated by an allowlist of one '
            . 'provider. A future adapter would fail open SILENTLY — no telemetry, no log — '
            . 'so an amount-verification outage on it would be invisible.');

        $this->assertSame(2, substr_count($src, '!in_array($gatewayName, self::AMOUNTLESS_GATEWAYS, true)')
                             + substr_count($src, '!in_array($gatewayNameParent, self::AMOUNTLESS_GATEWAYS, true)'),
            'both amount-verification sites (the parent path and the shared core) must use '
            . 'the denylist. One site left on the old check reintroduces the blind spot on '
            . 'whichever path it guards.');
    }

    /**
     * The denylist must contain ONLY adapters that structurally cannot capture.
     * Adding a real provider here would silence exactly the signal that matters.
     */
    public function test_only_non_capturing_adapters_are_exempt_from_telemetry(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');

        $at = strpos($src, 'AMOUNTLESS_GATEWAYS = [');
        $this->assertNotFalse($at, 'the AMOUNTLESS_GATEWAYS denylist was renamed or removed');
        $list = substr($src, $at, 100);

        foreach (['razorpay', 'cashfree', 'payu', 'easebuzz'] as $realProvider) {
            $this->assertStringNotContainsString($realProvider, $list,
                "'{$realProvider}' is a REAL gateway that performs real captures. Listing it "
                . 'as amountless suppresses the telemetry for a genuine amount-verification '
                . 'failure on live money.');
        }

        $this->assertStringContainsString('mock', $list,
            'the simulator must stay exempt, or every dev run emits security telemetry');
    }

    /**
     * The front door: a provider with no adapter must not be savable.
     * WOULD HAVE FAILED BEFORE: the list was ['razorpay','stripe','paytm'].
     */
    /**
     * BEHAVIOURAL since 2026-08-24. This previously scraped a hand-maintained
     * `$validProviders = [...]` literal out of the controller. That literal is
     * gone: the allowlist is DERIVED from the provider catalogue, so the real
     * value can be asked for instead of parsed — and a source scan could never
     * distinguish a live list from a commented-out one anyway.
     *
     * The invariant is unchanged and is now enforced more strongly: every
     * selectable provider must resolve to a real adapter class.
     */
    public function test_the_config_screen_only_accepts_providers_that_have_an_adapter(): void
    {
        require_once APPPATH . 'libraries/Payment_gateway_selector.php';

        foreach (['production', 'development'] as $env) {
            $selectable = Payment_gateway_selector::selectable_provider_ids($env);

            $this->assertNotContains('stripe', $selectable,
                "'stripe' is accepted by the config screen but no adapter implements it, so "
                . 'saving it routes live fee collection to a fallback adapter');
            $this->assertNotContains('paytm', $selectable,
                "'paytm' is accepted by the config screen but no adapter implements it");

            // The general form, not just the two historical offenders: EVERY
            // selectable provider must resolve to a loadable adapter class.
            foreach ($selectable as $providerId) {
                $class = Payment_gateway_selector::adapter_class($providerId);
                $this->assertNotNull($class,
                    "provider '{$providerId}' is selectable in {$env} but resolves to no "
                    . 'adapter — saving it would leave the money path with nothing to run');
                $this->assertTrue(class_exists($class) || file_exists(APPPATH . "libraries/{$class}.php"),
                    "provider '{$providerId}' names adapter '{$class}', which does not exist");
            }
        }

        $this->assertContains('razorpay',
            Payment_gateway_selector::selectable_provider_ids('production'),
            'the only production-certified provider must remain selectable');
    }
}
