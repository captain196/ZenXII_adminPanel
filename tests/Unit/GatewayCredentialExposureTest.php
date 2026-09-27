<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  GW-SEC1 — THE GATEWAY PAGE RENDERED LIVE MERCHANT SECRETS
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `Fee_management::gateway()` read the tenant's gateway configuration, computed
 * `api_secret_masked` and `webhook_secret_masked` … and then passed the config
 * through with the RAW secrets still in it. The view bound its inputs to the
 * raw fields:
 *
 *     input type="password" name="api_secret"     value = $config['api_secret']
 *     input type="text"     name="webhook_secret" value = $config['webhook_secret']
 *
 * `type="password"` obscures a value on screen. It does not obscure it in the
 * page source, so View Source or DevTools showed both. The webhook secret was
 * not even obscured on screen.
 *
 * ── WHY THIS REACHES THE MONEY PATH ───────────────────────────────────────
 * The webhook secret is the HMAC key `payment_webhook` verifies incoming
 * gateway callbacks against. Whoever holds it can forge a validly-signed
 * webhook and have fees marked paid that were never paid — the same outcome as
 * GW-1, reached by a different door.
 *
 * ── AND THE PAGE WAS OPEN TOO WIDELY ──────────────────────────────────────
 * It was guarded by VIEW_ROLES, which includes **Teacher** and Accountant.
 * Payment credentials are not fee data; they are the keys to the school's
 * merchant account, and belong behind the roles that may CHANGE them —
 * save_gateway_config() already required ADMIN_ROLES to write what this page
 * would freely display.
 *
 * ── THE MECHANISM ALREADY EXISTED ─────────────────────────────────────────
 * Nothing new was invented. `get_gateway_config()` already returned masked
 * copies, and `save_gateway_config()` already preserves the stored secret when
 * a masked value (matching `^\*+`) is posted back. The page simply never used
 * either. Both halves are pinned below, because the fix depends on them.
 */
class GatewayCredentialExposureTest extends TestCase
{
    private static string $ctrl = '';
    private static string $view = '';

    public static function setUpBeforeClass(): void
    {
        self::$ctrl = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');
        self::$view = (string) file_get_contents(APPPATH . 'views/fee_management/gateway.php');
    }

    /**
     * A method body, bounded at the NEXT method rather than by a guessed size.
     *
     * Fixed-size windows have bitten this file twice: once overshooting into
     * online_payments() (whose VIEW_ROLES guard is legitimate), and once
     * UNDERSHOOTING save_gateway_config so the audit call fell outside the
     * window and the assertion failed against a method that was already
     * correct. Both directions produce a wrong answer confidently.
     */
    private function methodBody(string $signature): string
    {
        $at = strpos(self::$ctrl, $signature);
        $this->assertNotFalse($at, "method not found: {$signature}");

        $end = preg_match('/\n    (?:public|private|protected) function /', self::$ctrl, $m,
            PREG_OFFSET_CAPTURE, $at + 20) === 1
            ? $m[0][1]
            : strlen(self::$ctrl);

        return substr(self::$ctrl, $at, $end - $at);
    }

    /** The gateway() action body, comments stripped. */
    private function gatewayAction(): string
    {
        $at = strpos(self::$ctrl, 'public function gateway()');
        $this->assertNotFalse($at, 'the gateway configuration page action was renamed');

        // Bound at the NEXT method. A fixed-size window overshot into
        // online_payments(), which legitimately uses VIEW_ROLES — so the RBAC
        // assertion below was reading a neighbouring method's guard and failing
        // for the wrong reason.
        $end = preg_match('/\n    (?:public|private|protected) function /', self::$ctrl, $m,
            PREG_OFFSET_CAPTURE, $at + 20) === 1
            ? $m[0][1]
            : strlen(self::$ctrl);

        $body = substr(self::$ctrl, $at, $end - $at);
        $body = preg_replace('!/\*.*?\*/!s', '', $body);
        $body = preg_replace('!^\s*//.*$!m', '', $body);
        return (string) $body;
    }

    public function test_control_the_stripper_keeps_the_action_code(): void
    {
        $body = $this->gatewayAction();

        $this->assertStringContainsString('_require_role', $body,
            'the comment stripper removed executable code');
        $this->assertStringNotContainsString('THE GATEWAY PAGE LEAKED', $body,
            'comments are not being stripped, so assertions would match the prose that '
            . 'explains the fix rather than the fix');
    }

    // ══ RBAC ════════════════════════════════════════════════════════════════

    /**
     * WOULD HAVE FAILED BEFORE: the guard was VIEW_ROLES.
     */
    public function test_the_gateway_page_is_restricted_to_administrators(): void
    {
        $body = $this->gatewayAction();

        $this->assertStringNotContainsString('self::VIEW_ROLES', $body,
            'the gateway configuration page is readable by VIEW_ROLES, which includes '
            . 'Teacher and Accountant. These are the credentials to the school\'s merchant '
            . 'account, not fee data.');

        $this->assertStringContainsString('self::ADMIN_ROLES', $body,
            'the page must require the same roles that are allowed to CHANGE the gateway '
            . 'configuration — it made no sense for viewing to be broader than writing');
    }

    /**
     * The write path must not have been loosened to match. Pinned so a future
     * "make these consistent" change tightens rather than relaxes.
     */
    public function test_saving_the_gateway_configuration_still_requires_administrators(): void
    {
        $at = strpos(self::$ctrl, 'function save_gateway_config');
        $this->assertNotFalse($at, 'save_gateway_config was renamed');

        $this->assertStringContainsString('ADMIN_ROLES', substr(self::$ctrl, $at, 800),
            'writing the gateway configuration no longer requires ADMIN_ROLES');
    }

    // ══ THE CONTROLLER MUST REPLACE, NOT MERELY ACCOMPANY ═══════════════════

    /**
     * The original code ADDED masked fields beside the raw ones. Adding is not
     * masking — the raw value still travelled to the view.
     */
    public function test_the_raw_secrets_are_replaced_before_reaching_the_view(): void
    {
        $body = $this->gatewayAction();

        $this->assertMatchesRegularExpression(
            '/\$config\[\$__secretField\]\s*=\s*\$__masked/',
            $body,
            'the raw secret is no longer REPLACED in the config passed to the view. '
            . 'Computing a masked copy beside it is not masking: the raw value still '
            . 'reaches the template, which is exactly how GW-SEC1 happened.'
        );

        $this->assertStringContainsString("'api_secret', 'webhook_secret'", $body,
            'both secrets must be masked. The webhook secret is the more dangerous one — '
            . 'it is the HMAC key that authenticates incoming payment webhooks.');
    }

    // ══ THE VIEW MUST BE INCAPABLE OF RENDERING A RAW SECRET ════════════════

    /**
     * Defence in depth. The controller masking is the primary fix; binding the
     * template to the *_masked fields means a regression there cannot leak.
     */
    public function test_the_view_binds_only_to_masked_fields(): void
    {
        preg_match_all('/name="(api_secret|webhook_secret)"[^>]*value="([^"]*)"/', self::$view, $m, PREG_SET_ORDER);

        $this->assertNotEmpty($m, 'the gateway credential inputs were renamed or removed');

        foreach ($m as $input) {
            [$whole, $field, $value] = $input;

            $this->assertStringContainsString($field . '_masked', $value,
                "the '{$field}' input binds to something other than its _masked variant. "
                . 'Binding to the raw field would re-leak the secret into the page source '
                . 'the moment the controller-side masking regressed.');

            $this->assertDoesNotMatchRegularExpression(
                '/\$config\[\'' . $field . '\'\]/',
                $value,
                "the '{$field}' input still reads the raw config field"
            );
        }
    }

    /**
     * `type="password"` does not hide a value from the page source — but
     * rendering a credential as `type="text"` does not even hide it on screen,
     * which is what the webhook secret did.
     */
    public function test_no_credential_input_is_rendered_as_plain_text(): void
    {
        preg_match_all('/<input([^>]*name="(?:api_secret|webhook_secret)"[^>]*)>/', self::$view, $m);

        $this->assertNotEmpty($m[1]);

        foreach ($m[1] as $attrs) {
            $this->assertStringContainsString('type="password"', $attrs,
                'a gateway credential input is not type="password". That does not make the '
                . 'value safe — it is still in the page source — but rendering a secret as '
                . 'plain text displays it on screen to anyone looking at the monitor.');
        }
    }

    // ══ THE ROUND-TRIP THE FIX DEPENDS ON ═══════════════════════════════════

    // ══ GW-SEC2 · WEBHOOK AUTHENTICATION KEYED ON THE PROVIDER ══════════════

    /**
     * Both webhook endpoints required a secret and a signature only when
     * `$gwMode === 'live'`. But mode is not what makes a webhook worth
     * authenticating — the presence of a REAL provider is. And an unconfigured
     * tenant resolves mode to `''`:
     *
     *     $gwMode = is_array($gwConfig) ? ($gwConfig['mode'] ?? '') : '';
     *
     * which is not 'live', so neither guard fired and the HMAC check was
     * skipped entirely — an unsigned POST was accepted.
     *
     * The money impact was blocked downstream (GW-1's refusing adapter, plus
     * the separate payment-signature check derived from api_secret), so this was
     * defence in depth rather than an open door. That is not a reason to leave
     * an authentication check keyed on the wrong condition.
     *
     * WOULD HAVE FAILED BEFORE: both files contained the mode-keyed guards.
     */
    public function test_no_webhook_gates_its_signature_check_on_the_mode(): void
    {
        foreach (['controllers/Fee_management.php', 'controllers/Admission_public.php'] as $file) {
            $src = (string) file_get_contents(APPPATH . $file);
            $src = preg_replace('!/\*.*?\*/!s', '', $src);
            $src = preg_replace('!^\s*//.*$!m', '', $src);

            $this->assertSame(0, preg_match("/\\\$gwMode === 'live' && \\\$webhook/", (string) $src),
                "{$file} still requires a webhook secret/signature only in LIVE mode. An "
                . "unconfigured tenant has mode '', so the guard does not fire and the HMAC "
                . 'check is skipped — the endpoint accepts unsigned webhooks.');
        }
    }

    public function test_a_real_provider_always_requires_a_secret_and_a_signature(): void
    {
        foreach (['controllers/Fee_management.php', 'controllers/Admission_public.php'] as $file) {
            $src = (string) file_get_contents(APPPATH . $file);

            $this->assertMatchesRegularExpression(
                "/\\\$realProvider\s*=\s*\(\\\$gwProvider\s*!==\s*''\s*&&\s*\\\$gwProvider\s*!==\s*'mock'\)/",
                $src,
                "{$file} does not derive whether a REAL provider is configured. The webhook "
                . 'authentication requirement must follow the provider, not the mode — a '
                . 'Razorpay test-mode integration has a webhook secret too.'
            );

            $this->assertStringContainsString('$realProvider && $webhookSecret === \'\'', $src,
                "{$file} no longer refuses when a real provider has no webhook secret");
            $this->assertStringContainsString('$realProvider && $webhookSig === \'\'', $src,
                "{$file} no longer refuses an unsigned webhook for a real provider");
        }
    }

    // ══ GW-SEC3 · CREDENTIAL CHANGES MUST BE AUDITED ════════════════════════

    /**
     * `save_gateway_config` wrote the tenant's payment credentials and returned
     * success with NO audit record and NO log line. A change to the keys of a
     * school's merchant account left nothing recording who made it or when.
     *
     * The risk is concrete: an account with ADMIN_ROLES that swapped
     * api_key/api_secret for its own Razorpay account would redirect every
     * subsequent parent payment, and detection would depend on someone noticing
     * the money stopped arriving.
     *
     * WOULD HAVE FAILED BEFORE: the handler contained no audit call at all.
     */
    public function test_a_gateway_configuration_change_is_audited(): void
    {
        $body = $this->methodBody('function save_gateway_config');

        $this->assertStringContainsString("feeAudit->log('gateway_config_changed'", $body,
            'a change to the tenant payment credentials is written with no audit record. '
            . 'Redirecting every future parent payment would leave no trace of who changed '
            . 'what, or when.');

        $this->assertStringContainsString("'changed_fields'", $body,
            'the audit must record WHICH fields moved — "the gateway config was saved" does '
            . 'not distinguish a mode toggle from a merchant-account swap');
    }

    /**
     * The audit must record FINGERPRINTS, never values. An audit trail is read
     * far more widely than a credentials screen, so writing secrets here would
     * recreate GW-SEC1 somewhere harder to notice.
     */
    public function test_the_audit_records_fingerprints_not_secret_values(): void
    {
        $at = strpos(self::$ctrl, "feeAudit->log('gateway_config_changed'");
        $this->assertNotFalse($at);
        $payload = substr(self::$ctrl, $at, 900);

        foreach (['$apiSecret,', '$webhookSecret,', '$apiKey,'] as $rawVar) {
            $this->assertStringNotContainsString($rawVar, $payload,
                "the gateway audit payload passes {$rawVar} directly. An audit log is copied "
                . 'and read far more widely than a credentials screen — a secret written here '
                . 'is GW-SEC1 again, in a place nobody thinks to check.');
        }

        $this->assertStringContainsString('$__fp(', $payload,
            'the audit no longer passes secrets through a fingerprint helper');
    }

    /**
     * A credential change is rare, high-impact and externally consequential —
     * the profile that should raise an alert rather than sit in a trail nobody
     * reads until money is missing.
     */
    public function test_a_gateway_change_raises_an_alert(): void
    {
        $audit = (string) file_get_contents(APPPATH . 'libraries/Fee_audit.php');

        $at = strpos($audit, 'ALERT_EVENTS = [');
        $this->assertNotFalse($at, 'the alert-event list was renamed');

        $this->assertStringContainsString("'gateway_config_changed'", substr($audit, $at, 900),
            'a payment-credential change does not raise an alert. It redirects where every '
            . 'future parent payment lands — that is not a quiet bookkeeping event.');
    }

    /**
     * An audit failure must not turn a saved configuration into an error
     * response — the config is already durable by then. And it must catch
     * \Throwable, because a missing collaborator raises an Error (FEE-W1).
     */
    public function test_an_audit_failure_cannot_fail_a_saved_configuration(): void
    {
        $at = strpos(self::$ctrl, "feeAudit->log('gateway_config_changed'");
        $after = substr(self::$ctrl, $at, 1200);

        $this->assertMatchesRegularExpression('/catch \(\\\\Throwable/', $after,
            'the gateway audit is guarded by catch(\\Exception) or not at all. A missing '
            . 'collaborator raises an Error, which \\Exception does not catch — and the '
            . 'configuration is already written by this point, so the request would fail '
            . 'after a successful save.');
    }

    // ══ LOGGING ═════════════════════════════════════════════════════════════

    /**
     * A secret in a log file is as exposed as one in a page — more so, because
     * logs get copied, shipped and shared. Every existing message references a
     * secret's ABSENCE; none interpolates its value. Pinned so that stays true.
     */
    public function test_no_log_message_interpolates_a_gateway_secret(): void
    {
        foreach (['controllers/Fee_management.php', 'controllers/Admission_public.php'] as $file) {
            $src = (string) file_get_contents(APPPATH . $file);

            preg_match_all('/log_message\(\s*[\'"][a-z]+[\'"]\s*,\s*(.+?)\);/s', $src, $m);

            foreach ($m[1] as $message) {
                foreach (['$webhookSecret', '$apiSecret', "['webhook_secret']", "['api_secret']"] as $needle) {
                    $this->assertStringNotContainsString($needle, $message,
                        "a log message in {$file} interpolates a gateway secret. Logs are "
                        . 'copied, shipped and shared far more freely than a credentials '
                        . 'screen — this is a wider exposure than GW-SEC1, not a narrower one.');
                }
            }
        }
    }

    /**
     * Masking the display is only safe because a masked value posted back is
     * PRESERVED rather than saved. If that check were removed, opening the page
     * and pressing Save would overwrite the real secret with asterisks and
     * silently break payment verification for the whole tenant.
     */
    public function test_a_masked_value_posted_back_preserves_the_stored_secret(): void
    {
        $at = strpos(self::$ctrl, 'function save_gateway_config');
        $this->assertNotFalse($at, 'save_gateway_config was renamed or removed');

        // Bound on the NEXT method rather than a fixed character count. A fixed
        // window silently shrinks the assertion's reach every time the method
        // grows — this test failed exactly that way once, reporting a missing
        // security guard that was present the whole time, 1156 characters past
        // the old 4000-char cutoff.
        $end  = strpos(self::$ctrl, "\n    public function ", $at + 30);
        $body = substr(self::$ctrl, $at, ($end !== false ? $end - $at : 20000));

        foreach (['apiSecret', 'webhookSecret'] as $var) {
            $this->assertMatchesRegularExpression(
                '/preg_match\(\'\/\^\\\\\*\+\/\',\s*\$' . $var . '\)/',
                $body,
                "save_gateway_config no longer preserves the existing value when \${$var} is "
                . 'posted back masked. With the display masked, saving the form would then '
                . 'write asterisks over the real credential and break payment verification '
                . 'for that tenant.'
            );
        }
    }
}
