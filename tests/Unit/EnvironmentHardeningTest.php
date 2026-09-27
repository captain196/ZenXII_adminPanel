<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', 'x');
if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

require_once APPPATH . 'libraries/Payment_gateway_selector.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  PAY-OPS-7 · PRODUCTION WOULD HAVE RUN AS "development"
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE DEFECT ───────────────────────────────────────────────────────────
 * `index.php` resolves the environment as:
 *
 *     define('ENVIRONMENT', isset($_SERVER['CI_ENV']) ? $_SERVER['CI_ENV'] : 'development');
 *
 * and `CI_ENV` is set NOWHERE in this repository — not in `.htaccess`, not in
 * `DEPLOY_RUNBOOK.md`, not in `PATH_A_US_SERVER_RUNBOOK.md`. Unless the
 * production vhost sets it (a file that is deliberately not in git), the live
 * server evaluates `ENVIRONMENT === 'development'`.
 *
 * Every money-path protection that keys on the environment then inverts on the
 * live server. `SIMULATOR_ENVIRONMENTS` contains 'development', so
 * `Payment_gateway_mock` — which validates signatures against
 * `mock_secret_key_for_dev`, a constant in this repository, and returns
 * `mock_skip_amount_check` to disable amount enforcement — becomes a
 * SELECTABLE PROVIDER IN PRODUCTION. GW-1's entire argument rests on this one
 * value being true.
 *
 * ── SEVERITY, STATED HONESTLY ────────────────────────────────────────────
 * LATENT, not live: a tenant must also carry `provider: 'mock'`, and the
 * census found none. But `save_gateway_config()` validates against this same
 * list, so on a box running as 'development' an admin could save it — and then
 * collect real fees through an adapter anyone with this source can forge.
 *
 * ── WHY THE FIX IS HERE AND NOT IN index.php ─────────────────────────────
 * That default is also what makes local development work. Flipping it would
 * change error reporting and config resolution for the entire application, for
 * every developer, to fix a money-path problem — far too broad. Instead the
 * payment layer refuses to BELIEVE a simulator environment unless something
 * corroborates it.
 */
final class EnvironmentHardeningTest extends TestCase
{
    private function harden(string $env, bool $explicit, string $host): string
    {
        return Payment_gateway_selector::harden_environment($env, $explicit, $host);
    }

    // ══ THE DEFECT ITSELF ═══════════════════════════════════════════════════

    /**
     * THE CASE THAT WAS BROKEN.
     *
     * A public host claiming to be 'development' purely because nobody set
     * CI_ENV. This is the live server as it stands today.
     */
    public function test_a_public_host_claiming_development_is_treated_as_production(): void
    {
        $this->assertSame('production',
            $this->harden('development', false, 'www.zenxii.com'),
            'the live server, running as "development" only because CI_ENV is unset, would '
            . 'still unlock the forgeable mock adapter for fee collection');

        $this->assertSame('production', $this->harden('testing', false, 'www.zenxii.com'));
        $this->assertSame('production', $this->harden('development', false, 'zenxii.com'));
        $this->assertSame('production', $this->harden('development', false, '3.138.59.194'));
    }

    // ══ WHAT MUST KEEP WORKING ══════════════════════════════════════════════

    /**
     * Genuine local development must be untouched. A developer running
     * `php -S localhost:8080` sets no CI_ENV today and must keep the simulator.
     */
    public function test_genuine_local_development_is_preserved(): void
    {
        foreach ([
            'localhost', 'localhost:8080', '127.0.0.1', '127.0.0.1:8099',
            '[::1]', '[::1]:8080', '::1',
        ] as $host) {
            $this->assertSame('development', $this->harden('development', false, $host),
                "[{$host}] local development lost the simulator — a developer running on "
                . 'loopback without CI_ENV is exactly the case the default exists for');
        }
    }

    /** An operator who declares the environment is believed, wherever they are. */
    public function test_an_explicit_ci_env_is_trusted(): void
    {
        $this->assertSame('development', $this->harden('development', true, 'staging.zenxii.com'),
            'an explicitly declared CI_ENV must be honoured — that is the operator stating '
            . 'the fact rather than a default standing in for one');
        $this->assertSame('testing', $this->harden('testing', true, 'ci.internal'));
    }

    /** CLI has no host; a CLI run is never a public request. */
    public function test_cli_runs_keep_their_claimed_environment(): void
    {
        $this->assertSame('development', $this->harden('development', false, ''),
            'CLI tooling and crons on the box itself lost the simulator');
    }

    // ══ IT MAY ONLY EVER TIGHTEN ════════════════════════════════════════════

    /**
     * The hardening must never make an answer MORE permissive. A non-simulator
     * environment passes through untouched, whatever the host or the flag.
     */
    public function test_non_simulator_environments_pass_through_unchanged(): void
    {
        foreach (['production', 'staging', 'prod', 'anything-else', ''] as $env) {
            foreach ([true, false] as $explicit) {
                foreach (['localhost', 'www.zenxii.com', ''] as $host) {
                    $this->assertSame($env, $this->harden($env, $explicit, $host),
                        "[{$env}] was rewritten — hardening may only ever tighten, and it "
                        . 'must never invent a simulator environment that was not claimed');
                }
            }
        }
    }

    /**
     * A hostname that merely CONTAINS a loopback literal must not pass. This is
     * the classic allowlist bypass and the reason the comparison is exact
     * rather than a substring test.
     */
    public function test_lookalike_hosts_do_not_pass_as_loopback(): void
    {
        foreach ([
            'localhost.evil.com', 'notlocalhost', '127.0.0.1.evil.com',
            'evil.com/localhost', 'localhost.attacker.io', '0.0.0.0',
            '10.0.0.5', '192.168.1.7',
        ] as $host) {
            $this->assertSame('production', $this->harden('development', false, $host),
                "[{$host}] was accepted as loopback, unlocking the forgeable mock adapter");
        }
    }

    // ══ THE CALL SITES MUST USE IT ══════════════════════════════════════════

    /**
     * A hardener nothing calls protects nothing. Every payment-path site that
     * decides on the environment must go through the wrapper — the raw
     * `defined('ENVIRONMENT') ? ENVIRONMENT : 'production'` idiom is exactly
     * what carried the defect.
     */
    public function test_no_payment_call_site_reads_the_raw_environment(): void
    {
        foreach ([
            'controllers/Fee_management.php',
            'controllers/Admission_public.php',
        ] as $rel) {
            $src = (string) file_get_contents(APPPATH . $rel);
            $this->assertStringNotContainsString(
                "defined('ENVIRONMENT') ? ENVIRONMENT : 'production'", $src,
                "{$rel} still reads ENVIRONMENT directly for a payment decision. On a "
                . 'production box with CI_ENV unset that evaluates to "development" and '
                . 'unlocks the simulator.');
            $this->assertStringContainsString(
                'Payment_gateway_selector::effective_environment()', $src,
                "{$rel} no longer uses the hardened resolver");
        }
    }

    /** The wrapper must gather all three inputs, or the decision is blind. */
    public function test_the_wrapper_gathers_the_corroborating_signals(): void
    {
        $src = (string) file_get_contents(APPPATH . 'libraries/Payment_gateway_selector.php');
        $fn  = strpos($src, 'public static function effective_environment()');
        $this->assertNotFalse($fn);
        $body = substr($src, $fn, 600);

        $this->assertStringContainsString("\$_SERVER['CI_ENV']", $body,
            'the wrapper no longer checks whether CI_ENV was explicitly set, so a defaulted '
            . '"development" is indistinguishable from a declared one');
        $this->assertStringContainsString("\$_SERVER['HTTP_HOST']", $body,
            'the wrapper no longer considers the request host');
    }

    /** production must never be a simulator environment. */
    public function test_production_is_not_a_simulator_environment(): void
    {
        $this->assertSame(['development', 'testing'],
            Payment_gateway_selector::SIMULATOR_ENVIRONMENTS);
    }
}
