<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

require_once APPPATH . 'libraries/Payment_gateway_selector.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  PA-ENV-1 — EXHAUSTIVE ENVIRONMENT × CONFIG MATRIX FOR THE SELECTOR
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `index.php` resolves ENVIRONMENT as:
 *
 *     define('ENVIRONMENT', isset($_SERVER['CI_ENV']) ? $_SERVER['CI_ENV'] : 'development');
 *
 * so an unset `CI_ENV` — and the production vhost is deliberately not in git —
 * makes `ENVIRONMENT` read `'development'`, the PERMISSIVE value. That is a
 * platform-wide resolution the directive for this gate explicitly forbids
 * changing here ("a separate impact report" is required first) — this file
 * does not touch `index.php` or its default.
 *
 * What CAN be proven at this gate, and what this file proves: given only the
 * STRING `Payment_gateway_selector::select()` actually receives, is every
 * value other than the two explicit simulator strings — including one that
 * merely LOOKS like an unset/misconfigured value (empty, whitespace, wrong
 * case, an unrelated environment name, a value with stray whitespace from a
 * copy-pasted Apache directive) — refused for the mock adapter? The selector
 * cannot distinguish "CI_ENV was genuinely set to development" from "CI_ENV
 * was never set and index.php defaulted" — both arrive as the identical
 * string `'development'`. That residual ambiguity is real, is stated here
 * rather than hidden, and is exactly what a future index.php-level decision
 * (recorded as an open decision in the architecture audit) would close. It is
 * NOT closable from inside the selector alone.
 */
class GatewaySelectorEnvironmentMatrixTest extends TestCase
{
    private const REAL   = ['provider' => 'razorpay', 'active' => true, 'api_key' => 'rzp_test_x', 'api_secret' => 's'];
    private const MOCK   = ['provider' => 'mock',     'active' => true];
    private const OFF    = ['provider' => 'razorpay', 'active' => false];
    private const UNK    = ['provider' => 'cashfree', 'active' => true];

    /**
     * The full cross product: every config shape × every environment value
     * the directive explicitly names, plus the "looks-like-unset" variants
     * that matter because index.php's default makes them indistinguishable
     * from a genuine developer machine at the string level.
     */
    public function matrixProvider(): array
    {
        $envs = [
            'unset (represented as empty string)' => '',
            'empty with whitespace'               => '   ',
            'unknown environment name'            => 'staging',
            'production'                          => 'production',
            'development'                         => 'development',
            'testing'                             => 'testing',
            'wrong case DEVELOPMENT'               => 'DEVELOPMENT',
            'wrong case Testing'                   => 'Testing',
            'development with stray whitespace'    => 'development ',
            'null coerced to string'               => (string) null,
        ];

        $configs = [
            'real provider (razorpay)'    => self::REAL,
            'mock provider'               => self::MOCK,
            'inactive real provider'      => self::OFF,
            'unknown provider'            => self::UNK,
            'no config'                   => null,
        ];

        $cases = [];
        foreach ($configs as $configName => $config) {
            foreach ($envs as $envName => $env) {
                $expected = $this->expectedFor($config, $env);
                $cases["{$configName} × {$envName}"] = [$config, $env, $expected];
            }
        }
        return $cases;
    }

    /** The one and only definition of "correct", independent of the selector's own code. */
    private function expectedFor($config, string $env): string
    {
        if (!is_array($config)) return Payment_gateway_selector::UNAVAILABLE;
        if (array_key_exists('active', $config) && empty($config['active'])) {
            return Payment_gateway_selector::UNAVAILABLE;
        }
        $provider = strtolower(trim((string) ($config['provider'] ?? '')));
        if ($provider === 'razorpay') return Payment_gateway_selector::RAZORPAY;
        // Mock requires the environment to be EXACTLY (case-sensitive, no
        // trimming) 'development' or 'testing' — anything else, including a
        // value that merely resembles one, must refuse.
        if ($provider === 'mock' && in_array($env, ['development', 'testing'], true)) {
            return Payment_gateway_selector::MOCK;
        }
        return Payment_gateway_selector::UNAVAILABLE;
    }

    /**
     * @dataProvider matrixProvider
     */
    public function test_matrix_cell($config, string $env, string $expected): void
    {
        $this->assertSame($expected, Payment_gateway_selector::select($config, $env));
    }

    // ══ THE SPECIFIC PA-ENV-1 CASES, NAMED EXPLICITLY (not buried in the matrix) ══

    /**
     * The exact residual: index.php's default value, applied to a mock
     * config. This is NOT a bug in the selector — 'development' genuinely IS
     * a simulator environment and mock genuinely should work on a real
     * developer's machine. It is included so the behaviour is pinned and
     * visible, not so it can be "fixed" here.
     */
    public function test_the_index_php_default_value_permits_mock_by_design(): void
    {
        $this->assertSame(Payment_gateway_selector::MOCK,
            Payment_gateway_selector::select(self::MOCK, 'development'),
            'this is intentional — see the class docblock. Closing the ambiguity between '
            . '"genuinely development" and "CI_ENV unset" requires an index.php-level '
            . 'change, out of scope for this gate.');
    }

    /** But a REAL provider is never gated by environment at all — proven explicitly. */
    public function test_a_real_provider_is_available_regardless_of_environment(): void
    {
        foreach (['', 'production', 'development', 'testing', 'staging', 'DEVELOPMENT'] as $env) {
            $this->assertSame(Payment_gateway_selector::RAZORPAY,
                Payment_gateway_selector::select(self::REAL, $env),
                "a real, active, correctly-configured Razorpay gateway was refused under "
                . "environment '{$env}' — the environment gate must only ever restrict the "
                . "MOCK, never a real provider");
        }
    }

    /** Malformed configuration cannot activate mock under ANY environment string. */
    public function test_malformed_configuration_cannot_activate_mock_under_any_environment(): void
    {
        $malformed = [null, 'not-an-array', 42, false, ['provider' => ['nested' => 'array']]];
        $envs      = ['', 'development', 'testing', 'production', 'staging'];

        foreach ($malformed as $config) {
            foreach ($envs as $env) {
                $this->assertSame(Payment_gateway_selector::UNAVAILABLE,
                    Payment_gateway_selector::select($config, $env),
                    'malformed config ' . var_export($config, true) . " under env '{$env}' "
                    . 'did not refuse — malformed configuration must never activate any adapter');
            }
        }
    }
}
