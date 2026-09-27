<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  W-1 — AN ADVERTISED URL MUST CORRESPOND TO A REAL ENDPOINT
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE DEFECT ───────────────────────────────────────────────────────────
 * `test_gateway_connection()` reported the webhook URL as
 *
 *     base_url('fee_management/webhook')
 *
 * There is no route for that path and no `Fee_management::webhook()` method.
 * An operator copying it into the Razorpay dashboard registered a **404**.
 *
 * ── WHY IT SURVIVED ──────────────────────────────────────────────────────
 * Nothing visibly broke. The CALLBACK path is authoritative for settlement, so
 * payments still completed; only webhook confirmation silently never worked.
 * A misconfiguration with no symptom is the expensive kind, and no test
 * anywhere asserted that a URL ZenX *hands to an operator* actually resolves.
 *
 * ── WHAT THIS TEST ASSERTS, AND WHY NOT A STRING MATCH ───────────────────
 * Asserting the literal `'fee_management/payment_webhook'` would be brittle
 * and — worse — would not catch the real defect class. If the route were
 * renamed tomorrow, a string test would still pass while the advertised URL
 * went dead again.
 *
 * So this proves CORRESPONDENCE across three artifacts:
 *
 *   Fee_management::WEBHOOK_PATH   (what the operator is told)
 *        ↓ must appear as a route in
 *   application/config/routes.php  (what the framework will dispatch)
 *        ↓ must name a controller + method that
 *   actually exist as a public method
 *
 * Any one of the three drifting breaks the chain and fails this test.
 */
class GatewayWebhookUrlCorrespondenceTest extends TestCase
{
    private static string $ctrl   = '';
    private static string $routes = '';

    public static function setUpBeforeClass(): void
    {
        self::$ctrl   = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');
        self::$routes = (string) file_get_contents(APPPATH . 'config/routes.php');
    }

    /** The advertised path, read from the constant the controller actually uses. */
    private function advertisedPath(): string
    {
        if (!preg_match("/const\s+WEBHOOK_PATH\s*=\s*'([^']+)'/", self::$ctrl, $m)) {
            $this->fail('Fee_management::WEBHOOK_PATH is gone. The advertised webhook URL is '
                . 'no longer defined in one place, so it can drift from the real route again.');
        }
        return $m[1];
    }

    // ══ THE CHAIN ════════════════════════════════════════════════════════

    /** 1 · The diagnostic must build its URL from the constant, not a literal. */
    public function test_the_diagnostic_builds_the_url_from_the_shared_constant(): void
    {
        $code = $this->executableController();

        $this->assertStringContainsString('base_url(self::WEBHOOK_PATH)', $code,
            'test_gateway_connection() no longer builds the webhook URL from '
            . 'WEBHOOK_PATH. A hand-written literal here is exactly how it came to '
            . 'advertise a path that does not exist.');

        $this->assertStringNotContainsString("base_url('fee_management/webhook')", $code,
            'the dead `fee_management/webhook` URL is back. Registering it in the provider '
            . 'dashboard produces a 404, and because the callback path is authoritative '
            . 'nothing will look broken.');
    }

    /**
     * 1b · PAY-OPS-4 — THE ORIGIN MATTERS AS MUCH AS THE PATH.
     *
     * W-1 proved the PATH resolves. It could not catch the other half: the
     * URL was built with `base_url()`, which reflects whatever host the admin
     * is browsing from. An operator configuring the gateway from
     * `localhost:8080`, a LAN IP, or the ngrok tunnel used while developing
     * this feature copied THAT origin into the provider dashboard, where it
     * stays for months. A tunnel URL is the worst case: it works when
     * registered and dies silently later, after which payments capture and no
     * receipt is ever issued — the exact symptom W-1 was written about,
     * reintroduced through the origin instead of the path.
     *
     * ── WHY assertStringNotContainsString ALONE WOULD BE A NO-OP ─────────
     * `public_base_url(self::WEBHOOK_PATH)` CONTAINS the substring
     * `base_url(self::WEBHOOK_PATH)`. Test 1 above therefore keeps passing
     * whichever helper is used, which is precisely why this second assertion
     * exists and why it is anchored on a preceding character rather than on
     * the bare substring.
     */
    public function test_the_advertised_url_uses_the_canonical_public_origin(): void
    {
        $code = $this->executableController();

        $this->assertStringContainsString('public_base_url(self::WEBHOOK_PATH)', $code,
            'the webhook URL handed to the operator is no longer built from '
            . 'public_base_url(). Built from base_url() it carries the CURRENT REQUEST '
            . 'host — localhost, a LAN IP, or a dev tunnel — into the provider dashboard.');

        // A bare base_url(self::WEBHOOK_PATH) is one NOT preceded by `public_`.
        $this->assertDoesNotMatchRegularExpression(
            '/(?<!public_)base_url\(self::WEBHOOK_PATH\)/', $code,
            'the webhook URL is being built from the request host again. That origin '
            . 'is whatever machine the admin happens to be on, and it becomes the '
            . 'permanent address the payment provider calls.');

        $this->assertStringContainsString("\$this->load->helper('public_url')", $code,
            'public_base_url() is used without loading the public_url helper — the '
            . 'helper is NOT autoloaded (see application/config/autoload.php), so this '
            . 'is a fatal "call to undefined function" on the gateway screen.');
    }

    /** 2 · The advertised path must exist as a route. */
    public function test_the_advertised_path_has_a_route(): void
    {
        $path = $this->advertisedPath();

        $this->assertMatchesRegularExpression(
            '/\$route\[\s*[\'"]' . preg_quote($path, '/') . '[\'"]\s*\]/',
            self::$routes,
            "the advertised webhook path '{$path}' has NO route in routes.php. Every "
            . 'webhook the provider sends would 404.'
        );
    }

    /** 3 · That route must name a controller method that actually exists. */
    public function test_the_route_maps_to_a_method_that_exists(): void
    {
        $path = $this->advertisedPath();

        $this->assertMatchesRegularExpression(
            '/\$route\[\s*[\'"]' . preg_quote($path, '/') . '[\'"]\s*\]\s*=\s*[\'"]([^\'"]+)[\'"]/',
            self::$routes,
            'the route exists but its target could not be parsed'
        );
        preg_match(
            '/\$route\[\s*[\'"]' . preg_quote($path, '/') . '[\'"]\s*\]\s*=\s*[\'"]([^\'"]+)[\'"]/',
            self::$routes, $m
        );

        [$controller, $method] = array_pad(explode('/', $m[1], 2), 2, '');

        $this->assertSame('Fee_management', $controller,
            "the webhook route points at controller '{$controller}', but this test only "
            . 'knows how to verify Fee_management. Update the test deliberately.');

        $this->assertMatchesRegularExpression(
            '/public\s+function\s+' . preg_quote($method, '/') . '\s*\(/',
            self::$ctrl,
            "routes.php maps the advertised webhook path to {$controller}::{$method}(), "
            . 'but no such public method exists. The endpoint is unreachable.'
        );
    }

    /**
     * 4 · CI3 falls back to controller/method routing, so a path with no
     * explicit route can still resolve IF the method exists. Prove the
     * advertised path is not relying on that accident — and prove the OLD
     * path could not have resolved that way either, which is why it 404'd.
     */
    public function test_the_dead_path_has_neither_a_route_nor_a_method(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            "/\\\$route\[\s*['\"]fee_management\/webhook['\"]\s*\]/",
            self::$routes,
            'a route for the previously-dead path now exists — if that is deliberate, the '
            . 'diagnostic and this test must be updated together, not silently');

        $this->assertDoesNotMatchRegularExpression(
            '/public\s+function\s+webhook\s*\(/',
            self::$ctrl,
            'a Fee_management::webhook() method now exists. Two webhook endpoints is a '
            . 'money-path ambiguity: verify which one the provider is registered against.'
        );
    }

    // ══ THE CONTROL ══════════════════════════════════════════════════════

    /**
     * Proves the assertions above can actually FAIL. Without this, a broken
     * regex would make every check above vacuously true.
     */
    public function test_control_the_route_assertion_rejects_a_nonexistent_path(): void
    {
        $bogus = 'fee_management/definitely_not_a_route_' . substr(md5('x'), 0, 6);

        $this->assertDoesNotMatchRegularExpression(
            '/\$route\[\s*[\'"]' . preg_quote($bogus, '/') . '[\'"]\s*\]/',
            self::$routes,
            'the route-matching regex matches a path that certainly does not exist, so '
            . 'the assertions in this file prove nothing');

        // …and the positive half: the real path DOES match the same regex.
        $this->assertMatchesRegularExpression(
            '/\$route\[\s*[\'"]' . preg_quote($this->advertisedPath(), '/') . '[\'"]\s*\]/',
            self::$routes,
            'the same regex cannot find the real route — it is broken in the other '
            . 'direction and would never catch a regression');
    }

    // ══ THE VIEW IS THE PRIMARY AFFORDANCE ══════════════════════════════

    /**
     * The gateway page shows a readonly "Webhook URL" box with a Copy button.
     * That — not the diagnostic warning — is what an operator actually copies
     * into the provider dashboard, so it is the MOST important instance of
     * W-1, and it carried the same dead path. Found by the repo-wide census,
     * not by the original investigation.
     */
    public function test_the_gateway_view_does_not_render_the_dead_path(): void
    {
        $code = $this->executableView();

        $this->assertStringNotContainsString("base_url('fee_management/webhook')", $code,
            'the gateway view renders the dead webhook path in the readonly field the '
            . 'operator copies from. This is the primary way a wrong URL reaches the '
            . 'provider dashboard.');

        $this->assertStringContainsString('$webhook_path', $code,
            'the view no longer takes the webhook URL from the controller-supplied path, '
            . 'so it can drift from the real route again');
    }

    /** The controller must actually supply it, or the view falls back blindly. */
    public function test_the_controller_supplies_the_webhook_path_to_the_view(): void
    {
        $this->assertStringContainsString(
            "\$data['webhook_path'] = self::WEBHOOK_PATH;",
            $this->executableController(),
            'gateway() no longer passes WEBHOOK_PATH to the view'
        );
    }

    // ══ W-7 · NO CALLBACK URL IS ADVERTISED ══════════════════════════════

    /**
     * The same Integration Info card advertised a **second** dead URL:
     * `fee_management/payment_callback`, which has no route and no method.
     *
     * It was removed rather than re-pointed, because this integration has no
     * server callback URL at all — proven from executable code:
     *
     *   • `create_order()` posts no `callback_url` to Razorpay
     *   • the Parent App sets no Checkout `redirect` option
     *   • `MainActivity` implements `PaymentResultListener`, so the result
     *     returns in-process via `onPaymentSuccess`, never over HTTP
     *
     * Razorpay's own docs place `callback_url` with WebView and redirect
     * flows. It becomes relevant only if a `webview` / `hosted_redirect`
     * provider is certified — neither engine ships today.
     */
    public function test_the_view_advertises_no_dead_callback_url(): void
    {
        $code = $this->executableView();

        $this->assertStringNotContainsString('fee_management/payment_callback', $code,
            'the gateway view advertises a callback URL again. There is no route and no '
            . 'method for it, so an operator would register a second dead endpoint.');

        $this->assertStringNotContainsString('fmCallbackUrl', $code,
            'the Callback URL field is back. If a webview/hosted_redirect provider is now '
            . 'certified, restore it pointing at a route that EXISTS and update this test '
            . 'deliberately.');
    }

    /**
     * The removal must be justified by the architecture, not merely done:
     * ZenX must still send no `callback_url` when creating the order. If it
     * ever did, a callback URL WOULD be required and this removal would
     * become wrong.
     */
    public function test_the_adapter_still_sends_no_callback_url_to_the_provider(): void
    {
        $adapter = (string) file_get_contents(APPPATH . 'libraries/Payment_gateway_razorpay.php');
        $code = '';
        foreach (token_get_all($adapter) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $code .= $t[1];
            } else {
                $code .= $t;
            }
        }

        $this->assertStringNotContainsString('callback_url', $code,
            'the Razorpay adapter now sends a callback_url when creating the order. That '
            . 'switches Checkout into redirect mode, which DOES require a server callback '
            . 'endpoint — and none exists. Either revert, or build the endpoint and '
            . 'restore the UI field together.');
    }

    /** Comment-stripped view — the fix's note quotes the old defect. */
    private function executableView(): string
    {
        $view = (string) file_get_contents(APPPATH . 'views/fee_management/gateway.php');
        $code = '';
        foreach (token_get_all($view) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $code .= $t[1];
            } else {
                $code .= $t;
            }
        }
        return $code;
    }

    // ══ THE WARNING TEXT MUST NOT CONTAIN A RAW ESCAPE ═══════════════════

    /**
     * A separate small defect fixed alongside W-1: the warning contained a
     * literal `\x27` (from a PHP single-quoted string, where that escape is
     * NOT interpreted), so an operator would read "the provider\x27s
     * dashboard". Operator-facing copy is part of the diagnostic's job.
     */
    public function test_the_operator_warning_contains_no_raw_escape_sequence(): void
    {
        $code = $this->executableController();

        $this->assertStringNotContainsString('x27', $code,
            'a literal \\x27 escape survives in operator-facing text. In a PHP '
            . 'single-quoted string that is not an apostrophe — it renders verbatim.');
    }

    /** Comment-stripped controller: the fix's comments quote the old defect. */
    private function executableController(): string
    {
        $code = '';
        foreach (token_get_all(self::$ctrl) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $code .= $t[1];
            } else {
                $code .= $t;
            }
        }
        return $code;
    }
}
