<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

require_once APPPATH . 'libraries/Payment_gateway_selector.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  PA-DUP-1 — FEES AND ADMISSION MUST CONVERGE ON ONE PROVIDER AUTHORITY
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Before this fix, Admission_public::_load_payment_gateway() reimplemented
 * provider selection inline, independently of Fee_management's
 * Payment_gateway_selector — and the two had ALREADY drifted: Admission
 * honoured `active: false`, Fees ignored the field entirely until GW-SEL-1.
 * Two implementations of one money-safety rule is exactly the shape that
 * drifts silently.
 *
 * ── WHAT THIS PROVES ─────────────────────────────────────────────────────
 * `Payment_gateway_selector::select()` is pure and provider-neutral (proven
 * separately by `GatewayFallbackSafetyTest`). What this file proves is
 * narrower and specific to the convergence: that Admission_public's source
 * now calls THAT SAME function rather than reimplementing the decision, and
 * that the specific case which drifted before (`active: false`) resolves
 * identically through the one shared authority for a battery of
 * configurations — not merely that each side happens to refuse.
 */
class SingleProviderAuthorityParityTest extends TestCase
{
    // ══ SOURCE PROOF — admission calls the shared selector, not its own logic ══

    public function test_admission_calls_the_shared_selector(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Admission_public.php');
        $fn = strpos($src, 'private function _load_payment_gateway');
        $this->assertNotFalse($fn);
        $end = strpos($src, "\n    private function ", $fn + 30);
        $body = substr($src, $fn, ($end !== false ? $end - $fn : 6000));

        $this->assertStringContainsString('Payment_gateway_selector::select(', $body,
            'Admission_public no longer delegates provider selection to the shared '
            . 'selector — PA-DUP-1 has regressed into a second independent authority');

        $this->assertStringNotContainsString(
            "\$isActive = is_array(\$gwConfig) ? !isset(\$gwConfig['active'])",
            $body,
            'the old inline active-flag reimplementation is back'
        );
        $this->assertStringNotContainsString(
            "\$provider = (is_array(\$gwConfig) && \$isActive) ? (string) (\$gwConfig['provider'] ?? 'mock') : 'mock';",
            $body,
            'the old inline provider reimplementation is back'
        );
    }

    /** The environment gate must not be duplicated a second time in Admission. */
    public function test_admission_no_longer_has_its_own_environment_gate(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Admission_public.php');
        $fn = strpos($src, 'private function _load_payment_gateway');
        $end = strpos($src, "\n    private function ", $fn + 30);
        $body = substr($src, $fn, ($end !== false ? $end - $fn : 6000));

        $this->assertStringNotContainsString(
            "!in_array(ENVIRONMENT, ['development', 'testing'], true)",
            $body,
            'Admission still evaluates its own copy of the simulator-environment gate — '
            . 'this is the exact duplication PA-DUP-1 exists to remove; it belongs solely '
            . 'in Payment_gateway_selector::SIMULATOR_ENVIRONMENTS now'
        );
    }

    // ══ BEHAVIOURAL PARITY — a battery both sides must resolve identically ═══

    /**
     * The same matrix of configurations that (a) drove GatewayFallbackSafetyTest
     * for Fees, and (b) covers the exact case that historically drifted
     * (`active: false`). Every row's expectation is what the SHARED selector
     * returns — both callers inherit it by construction once §1 above holds,
     * but this pins the actual verdicts so a change to the selector itself
     * cannot silently flip admission's behaviour unnoticed.
     */
    public function parityMatrixProvider(): array
    {
        return [
            'valid razorpay, production env' => [
                ['provider' => 'razorpay', 'active' => true], 'production', Payment_gateway_selector::RAZORPAY,
            ],
            'valid razorpay, active absent (defaults true)' => [
                ['provider' => 'razorpay'], 'production', Payment_gateway_selector::RAZORPAY,
            ],
            'explicitly inactive — the case that historically drifted' => [
                ['provider' => 'razorpay', 'active' => false], 'production', Payment_gateway_selector::UNAVAILABLE,
            ],
            'no config at all' => [
                null, 'production', Payment_gateway_selector::UNAVAILABLE,
            ],
            'unknown provider string' => [
                ['provider' => 'stripe', 'active' => true], 'production', Payment_gateway_selector::UNAVAILABLE,
            ],
            'mock requested, production env' => [
                ['provider' => 'mock', 'active' => true], 'production', Payment_gateway_selector::UNAVAILABLE,
            ],
            'mock requested, testing env' => [
                ['provider' => 'mock', 'active' => true], 'testing', Payment_gateway_selector::MOCK,
            ],
            'mock requested, development env' => [
                ['provider' => 'mock', 'active' => true], 'development', Payment_gateway_selector::MOCK,
            ],
            'non-array config (malformed read)' => [
                'not-an-array', 'production', Payment_gateway_selector::UNAVAILABLE,
            ],
        ];
    }

    /**
     * @dataProvider parityMatrixProvider
     */
    public function test_selector_resolves_the_shared_matrix_consistently($config, $env, $expected): void
    {
        $this->assertSame($expected, Payment_gateway_selector::select($config, $env),
            'the shared selector\'s verdict for this configuration changed — both Fees and '
            . 'Admission inherit this behaviour, so a regression here is a regression in both '
            . 'money paths at once');
    }
}
