<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

require_once APPPATH . 'libraries/Payment_gateway_selector.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  GW-SEL-1 — WHICH ADAPTER MAY COLLECT. DRIVEN, NOT READ.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Every assertion here EXECUTES the decision. The previous coverage of this
 * switch was `assertStringContainsString` against the controller source, which
 * cannot distinguish "the guard is present" from "the guard is reachable and
 * correct" — and this is the switch that decides whether real money is
 * collected through an adapter that signs with a constant published in this
 * repository.
 *
 * ── WHY THE STAKES ARE WHAT THEY ARE ──────────────────────────────────────
 * `Payment_gateway_mock` signs with 'mock_secret_key_for_dev' and returns
 * `mock_skip_amount_check`, disabling amount enforcement. A payment "collected"
 * through it mints a receipt, clears the demand and posts a journal for money
 * that never arrived.
 *
 * The rule it replaced was `if razorpay … else MOCK`. A read-only census on
 * 2026-08-24 found ONE gateway document across NINE tenants — so under that
 * rule, the other eight would each have collected through the mock the moment
 * a parent paid.
 */
class PaymentGatewaySelectionTest extends TestCase
{
    private const PROD = 'production';
    private const DEV  = 'development';

    private function razorpayConfig(array $overrides = []): array
    {
        return array_merge([
            'provider'   => 'razorpay',
            'mode'       => 'test',
            'active'     => true,
            'api_key'    => 'rzp_test_ABCDEFGHIJKLMN',
            'api_secret' => 'someSecretValue00000000',
        ], $overrides);
    }

    // ══ (A) A VALID RAZORPAY CONFIG SELECTS RAZORPAY — AND ONLY RAZORPAY ════

    public function test_a_valid_razorpay_config_selects_razorpay_in_production(): void
    {
        $this->assertSame(
            Payment_gateway_selector::RAZORPAY,
            Payment_gateway_selector::select($this->razorpayConfig(), self::PROD),
            'a correctly configured Razorpay tenant cannot collect — this would take every '
            . 'paying school offline'
        );
    }

    /** A live-mode configuration must behave identically. */
    public function test_a_live_mode_razorpay_config_selects_razorpay(): void
    {
        $cfg = $this->razorpayConfig(['mode' => 'live', 'api_key' => 'rzp_live_ABCDEFGHIJKL']);

        $this->assertSame(Payment_gateway_selector::RAZORPAY,
            Payment_gateway_selector::select($cfg, self::PROD));
    }

    /**
     * Razorpay must never be displaced by the simulator, even where the
     * simulator is permitted. A developer running locally against a real
     * tenant config must still hit the real adapter.
     */
    public function test_razorpay_is_selected_even_where_the_mock_would_be_allowed(): void
    {
        $this->assertSame(
            Payment_gateway_selector::RAZORPAY,
            Payment_gateway_selector::select($this->razorpayConfig(), self::DEV),
            'a development environment silently downgrades a real Razorpay configuration '
            . 'to the simulator'
        );
    }

    /** Stored values are not always tidy; casing and padding must not matter. */
    public function test_provider_matching_tolerates_casing_and_whitespace(): void
    {
        foreach (['Razorpay', ' razorpay ', 'RAZORPAY'] as $variant) {
            $this->assertSame(
                Payment_gateway_selector::RAZORPAY,
                Payment_gateway_selector::select(
                    $this->razorpayConfig(['provider' => $variant]), self::PROD
                ),
                "provider [{$variant}] strands a correctly configured tenant"
            );
        }
    }

    // ══ (B) NO SILENT MOCK IN A PRODUCTION MONEY PATH ═══════════════════════

    /**
     * THE HEADLINE. Eight of nine tenants are in exactly this state.
     */
    public function test_a_missing_gateway_document_never_selects_the_mock(): void
    {
        foreach ([null, false, '', 0, []] as $absent) {
            $selected = Payment_gateway_selector::select($absent, self::PROD);

            $this->assertNotSame(Payment_gateway_selector::MOCK, $selected,
                'a tenant with NO gateway configuration is served by the forgeable mock. '
                . 'It signs with a constant published in this repository and disables '
                . 'amount enforcement, so it mints receipts for money that never arrived.');
            $this->assertSame(Payment_gateway_selector::UNAVAILABLE, $selected,
                'an absent configuration must refuse to collect, not guess');
        }
    }

    /** An unsupported or misspelled provider must refuse, not degrade. */
    public function test_an_unrecognised_provider_refuses_in_production(): void
    {
        foreach (['stripe', 'payu', 'razorpayy', 'RazorPay2', 'null', ' '] as $provider) {
            $this->assertSame(
                Payment_gateway_selector::UNAVAILABLE,
                Payment_gateway_selector::select(['provider' => $provider], self::PROD),
                "provider [{$provider}] resolves to a collecting adapter"
            );
        }
    }

    public function test_an_unset_provider_refuses_in_production(): void
    {
        $this->assertSame(
            Payment_gateway_selector::UNAVAILABLE,
            Payment_gateway_selector::select(['mode' => 'test', 'active' => true], self::PROD),
            'a gateway document that exists but names no provider still collects'
        );
    }

    /**
     * The critical one: even NAMING the mock must not select it off a real
     * deployment. Configuration is not authorisation.
     */
    public function test_an_explicitly_configured_mock_still_refuses_in_production(): void
    {
        $this->assertSame(
            Payment_gateway_selector::UNAVAILABLE,
            Payment_gateway_selector::select(['provider' => 'mock', 'active' => true], self::PROD),
            'a tenant can opt into the forgeable mock on a production deployment simply by '
            . 'storing provider=mock — a Firestore write would become a licence to mint '
            . 'receipts for money that never arrived'
        );
    }

    /** Any environment that is not explicitly a simulator environment. */
    public function test_the_mock_refuses_in_every_non_simulator_environment(): void
    {
        foreach (['production', 'staging', 'prod', '', 'Development', 'TESTING'] as $env) {
            $this->assertSame(
                Payment_gateway_selector::UNAVAILABLE,
                Payment_gateway_selector::select(['provider' => 'mock'], $env),
                "environment [{$env}] permits the forgeable mock. The comparison is "
                . 'strict and lowercase by design — a casing variant must not open the gate.'
            );
        }
    }

    // ══ THE CONTROL — THE MOCK MUST STILL BE USABLE WHERE IT IS MEANT TO BE ═

    /**
     * Without this, every "refuses" assertion above would pass on a selector
     * that simply always returns UNAVAILABLE.
     */
    public function test_control_the_mock_is_selectable_in_development_and_testing(): void
    {
        foreach (Payment_gateway_selector::SIMULATOR_ENVIRONMENTS as $env) {
            $this->assertSame(
                Payment_gateway_selector::MOCK,
                Payment_gateway_selector::select(['provider' => 'mock'], $env),
                "the mock is unreachable in [{$env}], which breaks local development and "
                . 'the test suite itself'
            );
        }
    }

    // ══ AN EXPLICITLY DISABLED GATEWAY MUST NOT COLLECT ═════════════════════

    /**
     * Fees ignored `active` entirely, while Admission has always honoured it.
     * A school that deliberately switched its gateway off still collected fees
     * through it. Same rule now, deliberately mirroring Admission rather than
     * inventing a second one.
     */
    public function test_an_inactive_gateway_refuses_even_with_valid_credentials(): void
    {
        foreach ([false, 0, '', '0', null] as $inactive) {
            $this->assertSame(
                Payment_gateway_selector::UNAVAILABLE,
                Payment_gateway_selector::select(
                    $this->razorpayConfig(['active' => $inactive]), self::PROD
                ),
                'a gateway explicitly marked inactive still collects money'
            );
        }
    }

    /**
     * Absent MUST mean active — the opposite reading would strand every tenant
     * whose document predates the flag. Mirrors Admission_public exactly.
     */
    public function test_an_absent_active_flag_means_active(): void
    {
        $cfg = $this->razorpayConfig();
        unset($cfg['active']);

        $this->assertSame(
            Payment_gateway_selector::RAZORPAY,
            Payment_gateway_selector::select($cfg, self::PROD),
            'treating an absent `active` flag as disabled would take every tenant whose '
            . 'gateway document predates the flag offline'
        );
    }

    // ══ MISSING CREDENTIALS MUST NOT DEGRADE TO A SIMULATOR ═════════════════

    /**
     * Razorpay is still selected; the adapter then refuses at
     * `_assert_credentials()`. What matters is that it does NOT become the mock.
     */
    public function test_razorpay_with_missing_credentials_does_not_become_the_mock(): void
    {
        foreach ([['api_key' => ''], ['api_secret' => ''], ['api_key' => '', 'api_secret' => '']] as $broken) {
            $selected = Payment_gateway_selector::select(
                $this->razorpayConfig($broken), self::PROD
            );

            $this->assertNotSame(Payment_gateway_selector::MOCK, $selected,
                'a Razorpay tenant with broken credentials is downgraded to the forgeable '
                . 'mock, which would collect successfully where the real gateway refused');
        }
    }

    // ══ THE VERDICT IS TOTAL ════════════════════════════════════════════════

    /** No input may produce anything outside the three known verdicts. */
    public function test_every_verdict_is_one_of_the_three_known_values(): void
    {
        $allowed = [
            Payment_gateway_selector::RAZORPAY,
            Payment_gateway_selector::MOCK,
            Payment_gateway_selector::UNAVAILABLE,
        ];

        $inputs = [
            null, [], ['provider' => 'razorpay'], ['provider' => 'mock'],
            ['provider' => ['nested' => 'array']], ['provider' => 123],
            ['active' => false], ['provider' => 'razorpay', 'active' => false],
        ];

        foreach ($inputs as $i => $cfg) {
            foreach ([self::PROD, self::DEV, ''] as $env) {
                $this->assertContains(
                    Payment_gateway_selector::select($cfg, $env), $allowed,
                    "input #{$i} in env [{$env}] produced an unknown verdict; the caller's "
                    . 'if/elseif would fall through to null and the refusal would be '
                    . 'accidental rather than designed'
                );
            }
        }
    }

    public function test_refuses_agrees_with_the_verdict(): void
    {
        $this->assertTrue(Payment_gateway_selector::refuses(
            Payment_gateway_selector::select(null, self::PROD)));
        $this->assertFalse(Payment_gateway_selector::refuses(
            Payment_gateway_selector::select($this->razorpayConfig(), self::PROD)));
    }
}
