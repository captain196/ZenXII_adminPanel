<?php

use PHPUnit\Framework\TestCase;

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  GW-TEST-1 — THE "TEST CONNECTION" BUTTON DID NOTHING
 * ══════════════════════════════════════════════════════════════════════════
 *
 * The gateway page has always shown a "Test Connection" button. It was wired to
 *
 *     showToast('Connection test not implemented yet', 'info');
 *
 * Honest — it did not fake a pass — but it left the one question an operator
 * actually has unanswerable: **are the keys I saved correct?**
 *
 * That is not academic. A wrong secret fails at ORDER CREATION, and reaches a
 * parent as a generic payment failure that looks identical to a bank decline.
 * The two have completely different remedies — regenerate keys vs call the bank
 * — and nothing in the panel could tell them apart.
 *
 * Found while diagnosing a real failed payment, where exactly that ambiguity
 * cost a round trip.
 */
class GatewayConnectionTestTest extends TestCase
{
    private static string $ctrl = '';
    private static string $view = '';

    public static function setUpBeforeClass(): void
    {
        self::$ctrl = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');
        self::$view = (string) file_get_contents(APPPATH . 'views/fee_management/gateway.php');
    }

    /**
     * The provider PROTOCOL moved to Payment_gateway_razorpay (2026-08-24) —
     * see the adapter's test_connection(). Tests that assert protocol
     * behaviour now drive that method directly, which is strictly stronger
     * than the controller source scan they replaced. Tests that assert the
     * CONTROLLER's own responsibilities (RBAC, wiring, webhook readiness)
     * still read the endpoint.
     */
    private function adapterSource(): string
    {
        return (string) file_get_contents(APPPATH . 'libraries/Payment_gateway_razorpay.php');
    }

    private function adapter(array $params = []): Payment_gateway_razorpay
    {
        require_once APPPATH . 'libraries/PaymentProviderInterface.php';
        require_once APPPATH . 'libraries/Payment_gateway_razorpay.php';
        return new Payment_gateway_razorpay(array_merge(
            ['api_key' => 'rzp_test_ABC', 'api_secret' => 's'], $params));
    }

    private function endpoint(): string
    {
        $at = strpos(self::$ctrl, 'public function test_gateway_connection');
        $this->assertNotFalse($at, 'the connection test endpoint is gone');
        $end = strpos(self::$ctrl, "\n    public function ", $at + 30);
        return substr(self::$ctrl, $at, ($end !== false ? $end - $at : 9000));
    }

    // ══ REACHABILITY ════════════════════════════════════════════════════════

    /** Menu + route + controller — the rule this codebase learned the hard way. */
    public function test_the_button_reaches_a_real_endpoint(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'config/routes.php');
        $this->assertStringContainsString("\$route['fee_management/test_gateway_connection']", $routes,
            'the route is missing, so the button would 404');

        $this->assertStringNotContainsString('Connection test not implemented yet', self::$view,
            'the button is a stub again — it cannot answer whether the saved keys work');
        $this->assertStringContainsString("BASE + 'fee_management/test_gateway_connection'", self::$view,
            'the button no longer calls the endpoint');
    }

    // ══ IT MUST NOT LEAK THE SECRET ═════════════════════════════════════════

    /**
     * GW-SEC1 removed secrets from this page's responses. A test that echoed
     * the credential back to prove it read it would undo that entirely.
     */
    public function test_the_secret_is_never_returned(): void
    {
        $body = $this->endpoint();

        $at = strpos($body, 'json_success(');
        $this->assertNotFalse($at, 'the success response is gone');
        $success = substr($body, $at, 400);

        foreach (['api_secret', '$secret', 'api_key', '$key'] as $leak) {
            $this->assertStringNotContainsString($leak, $success,
                "the connection test returns '{$leak}' to the browser. GW-SEC1 removed "
                . "credentials from this page's responses; a diagnostic must not put them back.");
        }
    }

    // ══ IT MUST DISTINGUISH THE FAILURE MODES ═══════════════════════════════

    /**
     * The whole value of the test is telling these apart. Collapsing them into
     * "test failed" would reproduce the ambiguity it exists to remove.
     */
    public function test_transport_failure_is_not_reported_as_bad_credentials(): void
    {
        $body = $this->endpoint();

        // BEHAVIOURAL: point the adapter at an unroutable host and observe
        // what it actually returns. This replaced a controller source scan.
        $probe = $this->adapter(['api_base' => 'https://127.0.0.1:9'])
            ->test_connection(['api_key' => 'rzp_test_ABC', 'api_secret' => 's', 'mode' => 'test']);

        $this->assertFalse($probe['ok']);
        $this->assertSame('transport', $probe['kind'],
            'a network failure was classified as something other than a transport problem');
        $this->assertStringContainsString('not a credential problem', $probe['error'],
            'a network or TLS failure is reported as a credential failure, which sends an '
            . 'operator to regenerate perfectly good keys');
    }

    /** A 401 must say what a 401 actually means for Razorpay. */
    public function test_a_401_explains_the_stale_secret(): void
    {
        $body = $this->endpoint();

        $body = $this->adapterSource();   // protocol lives on the adapter now
        $this->assertStringContainsString('if ($http === 401) {', $body,
            'a 401 is no longer distinguished from any other non-2xx');
        $this->assertStringContainsString('shows a secret only once', $body,
            'the 401 message does not explain WHY the secret is likely stale, which is the '
            . 'single most common cause and the only actionable part');
    }

    /** A mode/key mismatch must be caught before the network call. */
    public function test_a_mode_key_mismatch_is_refused_before_calling_out(): void
    {
        $body = $this->endpoint();

        // BEHAVIOURAL ORDERING PROOF, replacing a text-position comparison.
        // The host is unroutable: if the mode/key check ran AFTER the network
        // call, this would come back 'transport'. Getting 'config' proves the
        // check short-circuits before any request is made.
        $probe = $this->adapter(['api_base' => 'https://127.0.0.1:9'])->test_connection([
            'api_key' => 'rzp_live_ABC', 'api_secret' => 's', 'mode' => 'test',
        ]);

        $this->assertFalse($probe['ok'], 'a live key in test mode was accepted');
        $this->assertSame('config', $probe['kind'],
            'the mode/key check runs after the network call, so a misconfiguration that '
            . 'disables webhook HMAC enforcement would be reported as a working gateway');
        $this->assertStringContainsString('disables webhook signature checking', $probe['error'],
            'the mismatch message no longer states the security consequence');
    }

    // ══ WEBHOOK READINESS IS A SEPARATE ANSWER ══════════════════════════════

    /**
     * Working credentials do not mean a payment can be verified end to end.
     * An unreachable webhook URL or an unset webhook secret both leave the
     * webhook path untested, and an operator needs to know that BEFORE
     * concluding a green test means everything works.
     */
    public function test_it_reports_webhook_readiness_separately_from_credentials(): void
    {
        $body = $this->endpoint();

        $this->assertStringContainsString('No webhook secret is set', $body,
            'an unset webhook secret is not reported. The HMAC check is skipped entirely in '
            . 'that state, so a webhook cannot be proven to have come from the gateway.');
        // Provider-neutral wording since 2026-08-24: this warning is about the
        // webhook URL being unroutable, which is true of ANY provider. Naming
        // Razorpay in it would be one more place a second provider is wrong.
        $this->assertStringContainsString('the provider cannot reach', $body,
            'a localhost webhook URL is not reported, so an operator would expect webhook '
            . 'delivery that can never arrive');

        // Warnings must not be dressed up as failures — the keys DO work.
        $this->assertStringContainsString("'warnings' => \$warnings,", $body,
            'warnings are no longer separated from the pass/fail result');
    }

    // ══ RBAC ════════════════════════════════════════════════════════════════

    /** Exercising merchant credentials belongs with the roles that may change them. */
    public function test_the_endpoint_is_admin_gated(): void
    {
        $body = $this->endpoint();

        $this->assertStringContainsString(
            "\$this->_require_role(self::ADMIN_ROLES, 'test_gateway_connection', 'Fees', 'manage');",
            $body,
            'the connection test is not gated to ADMIN_ROLES. gateway() and '
            . 'save_gateway_config() both are — a credential probe must not be reachable by '
            . 'a role that cannot see the credentials.');
    }

    // ══ THE PANEL MUST FAIL CLOSED ══════════════════════════════════════════

    /**
     * XHR delivers 403 and 500 to `onload` exactly like a 2xx. A credential
     * test that reports success on a denied request is worse than no test.
     */
    public function test_the_panel_checks_the_http_status_as_well_as_the_body(): void
    {
        $at = strpos(self::$view, 'window.testConnection');
        $this->assertNotFalse($at);
        $body = substr(self::$view, $at, 2400);

        $this->assertStringContainsString('xhr.status < 200 || xhr.status >= 300', $body,
            'the panel does not gate on the HTTP status, so a 403 would be parsed as a '
            . 'successful credential test');
    }

    /**
     * It must not create anything. A "test" button that quietly accumulates
     * orders in a merchant account is one people stop pressing.
     */
    public function test_the_probe_creates_nothing(): void
    {
        $body = $this->endpoint();

        $body = $this->adapterSource();   // the probe lives on the adapter now
        $this->assertStringContainsString('/payments?count=1', $body,
            'the probe no longer uses a cheap read');
        $this->assertStringNotContainsString('/v1/orders', $body,
            'the connection test creates an ORDER. That is a real object in the merchant '
            . 'account, and a diagnostic must not leave residue behind.');
    }

    // ══ A DIAGNOSTIC THAT MIS-DESCRIBES BEHAVIOUR IS ITSELF A DEFECT ════════

    /**
     * GW-TEST-2. The first version of this warning said an empty webhook secret
     * meant "the HMAC check is skipped entirely". That is false wherever it
     * matters: webhook() checks the PROVIDER first, and while a real provider
     * is configured an empty secret is rejected with HTTP 500 long before the
     * skip branch is reachable. Webhooks are REFUSED, not trusted unverified.
     *
     * The two readings send an operator in opposite directions — one is an
     * urgent security hole, the other is a dead delivery path — so this wording
     * is load-bearing, not cosmetic.
     */
    public function test_the_webhook_warning_matches_what_the_endpoint_actually_does(): void
    {
        $body = $this->endpoint();

        $this->assertStringNotContainsString('HMAC check is skipped entirely', $body,
            'the warning claims webhooks are accepted without signature verification. The '
            . 'endpoint rejects them with HTTP 500 instead — this wording reports a security '
            . 'hole that does not exist and hides the real consequence.');

        $this->assertStringContainsString('refuses every delivery', $body,
            'the warning no longer states that deliveries are refused');

        // And the behaviour it describes must still BE the behaviour.
        $wh = strpos(self::$ctrl, 'webhook_secret is not set');
        $this->assertNotFalse($wh,
            'the fail-closed guard this warning describes is gone, so the warning is now '
            . 'describing behaviour the controller no longer has');
        $guard = substr(self::$ctrl, max(0, $wh - 500), 1200);
        $this->assertStringContainsString('http_response_code(500)', $guard,
            'an empty webhook secret no longer rejects the delivery, so the warning text '
            . 'has silently become wrong');
    }

    /**
     * The callback path is signed with api_secret, the webhook path with
     * webhook_secret. Conflating them tells an operator a payment cannot
     * complete when it perfectly well can.
     */
    public function test_it_distinguishes_the_two_secrets(): void
    {
        $this->assertStringContainsString('callback path', $this->endpoint(),
            'the warning omits that payments still complete via the callback, which is the '
            . 'actionable half of the answer');
    }
}
