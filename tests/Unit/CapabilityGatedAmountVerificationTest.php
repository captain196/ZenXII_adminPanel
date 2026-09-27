<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');
if (!function_exists('log_message')) { function log_message($level, $msg) {} }

require_once APPPATH . 'libraries/PaymentProviderInterface.php';
require_once APPPATH . 'libraries/Payment_gateway_razorpay.php';
require_once APPPATH . 'libraries/Payment_gateway_mock.php';
require_once APPPATH . 'libraries/Payment_gateway_unavailable.php';
require_once APPPATH . 'libraries/Payment_gateway_cashfree.php';
require_once APPPATH . 'libraries/Payment_service.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  AMOUNT VERIFICATION IS GATED ON CAPABILITY, NEVER ON PROVIDER NAME
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE DEFECT THIS CLOSES ───────────────────────────────────────────────
 * `Admission_public::payment_callback` re-verified the captured amount only
 * when:
 *
 *     $gw['name'] === 'razorpay'
 *
 * An ALLOWLIST OF ONE. The moment a second provider is enabled for any school,
 * that condition is false and the entire capture/amount re-verification is
 * SKIPPED — on a PUBLIC, unauthenticated admission form. A signed-but-
 * uncaptured or wrong-amount payment would be accepted.
 *
 * `Fee_management` hit exactly this defect and fixed it (GW-2) by inverting to
 * a denylist. Admission never received the same fix. This file proves the
 * corrected rule and pins it for both surfaces.
 *
 * ── THE RIGHT QUESTION ───────────────────────────────────────────────────
 * Never "is this Razorpay?" but "can this adapter tell me what was actually
 * captured?" — which only the adapter can answer, and now does, via
 * `capabilities()['reports_captured_amount']`.
 *
 * Crucially this distinguishes three states, where the old code had two:
 *   true  → compare the amount (verification is possible)
 *   false → nothing to compare (a simulator has no real captured amount)
 *   null  → the adapter did not say; fall back rather than assume
 */
class CapabilityGatedAmountVerificationTest extends TestCase
{
    // ══ EVERY ADAPTER DECLARES THE CAPABILITY HONESTLY ═══════════════════

    public function test_every_adapter_declares_whether_it_reports_a_captured_amount(): void
    {
        $adapters = [
            'razorpay'    => new Payment_gateway_razorpay(['api_key' => 'k', 'api_secret' => 's']),
            'mock'        => new Payment_gateway_mock(),
            'unavailable' => new Payment_gateway_unavailable(),
            'cashfree'    => new Payment_gateway_cashfree(['api_key' => 'k', 'api_secret' => 's']),
        ];

        foreach ($adapters as $name => $a) {
            $this->assertArrayHasKey('reports_captured_amount', $a->capabilities(),
                "{$name} does not declare reports_captured_amount. Without it the core "
                . 'cannot distinguish "this provider has no amount to compare" from '
                . '"the comparison was skipped" — which is how the allowlist-of-one '
                . 'defect stayed invisible.');
        }
    }

    /**
     * The declarations must match reality. A simulator that claimed it reports
     * a real captured amount would re-enable a comparison against a fabricated
     * zero — worse than not comparing.
     */
    public function test_the_declarations_match_what_each_adapter_actually_does(): void
    {
        $this->assertTrue(
            (new Payment_gateway_razorpay(['api_key' => 'k', 'api_secret' => 's']))
                ->capabilities()['reports_captured_amount'],
            'Razorpay fetch_payment() returns amount_paise from the provider\'s own record; '
            . 'declaring false would disable MONEY-1 for the one certified provider');

        $this->assertFalse(
            (new Payment_gateway_mock())->capabilities()['reports_captured_amount'],
            'the mock claims a real captured amount, but fetch_payment() returns '
            . 'amount_paise=0 with mock_skip_amount_check — the core would compare against '
            . 'a fabricated zero');

        $this->assertFalse(
            (new Payment_gateway_unavailable())->capabilities()['reports_captured_amount'],
            'the refusal sentinel claims a capability; it promises nothing by design');

        $this->assertTrue(
            (new Payment_gateway_cashfree(['api_key' => 'k', 'api_secret' => 's']))
                ->capabilities()['reports_captured_amount'],
            'Cashfree fetch_payment() returns payment_amount for the SUCCESS attempt');
    }

    // ══ Payment_service SURFACES IT, SO NO CALLER NEEDS A NAME LIST ═══════

    private function serviceWith(object $gateway, array $order): Payment_service
    {
        $svc = new Payment_service();
        $fb = new class($order) {
            public array $order;
            public function __construct(array $o) { $this->order = $o; }
            public function firestoreGet($c, $d) { return $this->order; }
            public function firestoreSet($c, $d, $data) { return true; }
        };
        $ref = new ReflectionClass($svc);
        foreach (['firebase' => $fb, 'gateway' => $gateway, 'schoolId' => 'SCH_X',
                  'session' => '2026-27', 'adminId' => 'A'] as $p => $v) {
            $prop = $ref->getProperty($p); $prop->setAccessible(true); $prop->setValue($svc, $v);
        }
        return $svc;
    }

    /** An adapter that reports amounts surfaces `true`. */
    public function test_verify_payment_surfaces_the_capability_as_true(): void
    {
        $gw = new class {
            public function get_name(): string { return 'reporting_provider'; }
            public function capabilities(): array { return ['reports_captured_amount' => true]; }
            public function verify_signature(string $o, string $p, string $s): bool { return true; }
            public function fetch_payment(string $p): array {
                return ['amount' => 700.00, 'amount_paise' => 70000, 'status' => 'captured'];
            }
        };
        $r = $this->serviceWith($gw, ['status' => 'created'])->verify_payment('o', 'p', 's');
        $this->assertTrue($r['gateway_reports_amount']);
    }

    /** A simulator surfaces `false` — an explicit "nothing to compare". */
    public function test_verify_payment_surfaces_the_capability_as_false_for_a_simulator(): void
    {
        $gw = new class {
            public function get_name(): string { return 'sim'; }
            public function capabilities(): array { return ['reports_captured_amount' => false]; }
            public function verify_signature(string $o, string $p, string $s): bool { return true; }
            public function fetch_payment(string $p): array { return ['mock_skip_amount_check' => true]; }
        };
        $r = $this->serviceWith($gw, ['status' => 'created'])->verify_payment('o', 'p', 's');
        $this->assertFalse($r['gateway_reports_amount']);
    }

    /**
     * An adapter with no capabilities() at all surfaces NULL — "did not say".
     * The caller must be able to fall back rather than assume either way;
     * treating null as false would silence a real provider, and treating it as
     * true would alert on every simulator.
     */
    public function test_an_adapter_without_capabilities_surfaces_null_not_a_guess(): void
    {
        $gw = new class {
            public function get_name(): string { return 'legacy'; }
            public function verify_signature(string $o, string $p, string $s): bool { return true; }
            public function fetch_payment(string $p): array { return []; }
        };
        $r = $this->serviceWith($gw, ['status' => 'created'])->verify_payment('o', 'p', 's');
        $this->assertNull($r['gateway_reports_amount'],
            'an adapter that never declared capabilities was reported as a definite '
            . 'true/false — "did not say" must remain distinguishable from "said no"');
    }

    // ══ NEITHER SURFACE GATES ON A PROVIDER NAME ANY MORE ════════════════

    /**
     * The regression guard for the original defect. `Admission_public` must
     * not decide amount verification by comparing a provider name.
     */
    public function test_admission_no_longer_gates_amount_verification_on_a_provider_name(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Admission_public.php');
        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) { if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $code .= $t[1]; }
            else $code .= $t;
        }

        $this->assertStringNotContainsString("\$gw['name'] === 'razorpay'", $code,
            'Admission_public gates capture/amount re-verification on a provider NAME again. '
            . 'That is an allowlist of one: enabling a second provider silently skips the '
            . 'check entirely, on a public unauthenticated form.');

        $this->assertStringContainsString('reports_captured_amount', $code,
            'Admission_public no longer consults the adapter capability, so it has no way '
            . 'to know whether amount verification is even possible');
    }

    /** And the capability must actually gate the branch, not merely be read. */
    public function test_admission_uses_the_capability_to_gate_the_branch(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Admission_public.php');
        $this->assertStringContainsString('if ($verified && $__canReportAmount) {', $src,
            'the capability is computed but does not gate the re-verification branch');
    }
}
