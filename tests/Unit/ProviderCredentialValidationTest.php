<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');

require_once APPPATH . 'libraries/PaymentProviderInterface.php';
require_once APPPATH . 'libraries/Payment_gateway_razorpay.php';
require_once APPPATH . 'libraries/Payment_gateway_selector.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  CREDENTIAL VALIDATION BELONGS TO THE ADAPTER, NOT THE CORE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `save_gateway_config()` carried Razorpay's key-prefix rules inline, behind
 * `if ($provider === 'razorpay')`. Two defects in one line:
 *
 *   1. Provider protocol knowledge in the Billing V2 core.
 *   2. An ALLOWLIST OF ONE — every other provider's credentials would be
 *      saved with no validation at all. The same shape that silently disabled
 *      amount verification in Admission_public.
 *
 * ── WHY THE RULE MATTERS AT ALL ──────────────────────────────────────────
 * A live key saved with mode='test' does not merely mislabel anything: it
 * silently disables HMAC enforcement on the webhook handler, so anyone who
 * knows the endpoint URL can post a spoofed payload. This is a security
 * control, not a tidiness check — which is why it must run for whichever
 * provider is configured, not for one named provider.
 */
class ProviderCredentialValidationTest extends TestCase
{
    private function adapter(): Payment_gateway_razorpay
    {
        return new Payment_gateway_razorpay(['api_key' => 'k', 'api_secret' => 's']);
    }

    // ══ THE RULE ITSELF, DRIVEN DIRECTLY ══════════════════════════════════

    public function test_a_live_key_in_test_mode_is_refused(): void
    {
        $v = $this->adapter()->validate_credentials([
            'api_key' => 'rzp_live_ABC123', 'mode' => 'test',
        ]);

        $this->assertFalse($v['ok'],
            'a rzp_live_* key was accepted with mode=test. That combination silently '
            . 'disables webhook HMAC enforcement, so a spoofed payload from anyone who '
            . 'knows the endpoint URL would be accepted.');
        $this->assertStringContainsString('mode=live', $v['error'],
            'the refusal does not tell the operator what to actually change');
    }

    public function test_a_test_key_in_live_mode_is_refused(): void
    {
        $v = $this->adapter()->validate_credentials([
            'api_key' => 'rzp_test_ABC123', 'mode' => 'live',
        ]);
        $this->assertFalse($v['ok']);
        $this->assertStringContainsString('mode=test', $v['error']);
    }

    /** THE CONTROLS — matching pairs must pass, or the rule is unusable. */
    public function test_matching_key_and_mode_are_accepted(): void
    {
        $this->assertTrue($this->adapter()->validate_credentials(
            ['api_key' => 'rzp_live_ABC', 'mode' => 'live'])['ok']);
        $this->assertTrue($this->adapter()->validate_credentials(
            ['api_key' => 'rzp_test_ABC', 'mode' => 'test'])['ok']);
    }

    /**
     * An empty key has nothing to contradict yet — refusing it here would
     * block the ordinary "save the mode first" flow. Absence is not a
     * mismatch.
     */
    public function test_an_empty_key_is_not_treated_as_a_mismatch(): void
    {
        $this->assertTrue($this->adapter()->validate_credentials(
            ['api_key' => '', 'mode' => 'live'])['ok']);
    }

    /**
     * An unrecognised prefix passes. Razorpay's docs lock only these two
     * shapes; refusing anything else would lock out a legacy or partner key
     * format we have simply never seen. Documented as a deliberate choice
     * rather than an oversight.
     */
    public function test_an_unrecognised_key_format_is_not_refused(): void
    {
        $this->assertTrue($this->adapter()->validate_credentials(
            ['api_key' => 'legacy_partner_key_9911', 'mode' => 'live'])['ok'],
            'an unrecognised key format was refused — this locks out legacy/partner key '
            . 'shapes that Razorpay has not documented a prefix for');
    }

    // ══ THE CORE ASKS THE ADAPTER, RATHER THAN KNOWING THE RULE ══════════

    public function test_the_core_no_longer_hardcodes_razorpay_key_rules(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');
        $at  = strpos($src, 'function save_gateway_config');
        $end = strpos($src, "\n    public function ", $at + 30);
        $code = substr($src, $at, ($end !== false ? $end - $at : 20000));

        // Strip comments — the fix is documented with a comment that quotes
        // the old defect, and a raw scan would match the explanation.
        $exec = '';
        foreach (token_get_all('<?php ' . $code) as $t) {
            if (is_array($t)) { if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $exec .= $t[1]; }
            else $exec .= $t;
        }

        $this->assertStringNotContainsString("\$provider === 'razorpay'", $exec,
            'save_gateway_config gates credential validation on a provider NAME again — '
            . 'an allowlist of one, so every other provider saves with no validation');
        $this->assertStringNotContainsString('rzp_live_', $exec,
            "Razorpay's key-prefix rules are back in the core; they belong to the adapter");

        $this->assertStringContainsString('validate_credentials(', $exec,
            'the core no longer asks the configured adapter to validate its own '
            . 'credentials, so a provider-specific security rule runs for nobody');
    }

    /**
     * The seam must be duck-typed, not a required interface method: an adapter
     * with no provider-specific credential rule is a legitimate state, and
     * forcing every adapter to implement one would repeat the
     * `simulate_payment()` mistake the P1 gate deliberately avoided.
     */
    public function test_an_adapter_without_the_method_is_not_a_failure(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');
        $this->assertStringContainsString("method_exists(\$__adapterClass, 'validate_credentials')", $src,
            'the credential-validation call is not guarded by method_exists, so an adapter '
            . 'that legitimately has no provider-specific rule would fatal on save');
    }

    /** An unknown provider resolves to no adapter and must not fatal. */
    public function test_an_unknown_provider_resolves_to_no_adapter(): void
    {
        $this->assertNull(Payment_gateway_selector::adapter_class('not_a_provider'));
    }
}
