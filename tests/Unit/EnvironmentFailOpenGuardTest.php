<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  THE ENVIRONMENT GUARD FAMILY — FAIL CLOSED, NOT OPEN
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `index.php` resolves the environment as:
 *
 *     define('ENVIRONMENT', isset($_SERVER['CI_ENV']) ? $_SERVER['CI_ENV'] : 'development');
 *
 * so ENVIRONMENT is only 'production' when `SetEnv CI_ENV production` is present
 * in the production Apache config — a file deliberately NOT in git and therefore
 * unverifiable from the repository. Unset, mistyped, or lost during a vhost
 * change ⇒ ENVIRONMENT silently reads 'development' ON THE LIVE SERVER.
 *
 * Four separate guards were written as `ENVIRONMENT === 'production'`, which
 * means "block only if we are certain this is production". Each therefore
 * degraded to permissive exactly when configuration was missing:
 *
 *   FEE-S1     Fees::_reject_in_production        → receipt deletion live
 *   GW-1b      Admission_public mock gateway      → admission fees faked, on a
 *                                                   PUBLIC unauthenticated form
 *   SEC-CFG-1  config.php encryption key          → published key protects sessions
 *   SEC-CFG-1  auth_api.php internal secret       → published secret on the auth channel
 *
 * All four are now written as "permit only if we are certain this is NOT
 * production", so an unset or unexpected value denies.
 *
 * ── WHAT THIS DOES NOT FIX ────────────────────────────────────────────────
 * Honestly stated because it matters: inverting the comparison does NOT rescue
 * the two config guards when CI_ENV is simply absent, because the value then
 * reads 'development' and still passes. The code cannot detect production when
 * the only signal is the very variable that is missing. Those two therefore also
 * log loudly, converting a silent weak-secret condition into an observable one.
 * The real fix is operational and is recorded as SEC-CFG-1.
 */
class EnvironmentFailOpenGuardTest extends TestCase
{
    /**
     * The dangerous idiom. Any executable occurrence is a guard that opens when
     * configuration is missing.
     *
     * Comments are stripped first — several of these files now *describe* the
     * old idiom in the note explaining why it was changed, and a naive grep
     * would match the explanation and fail forever.
     */
    private function executableSource(string $relPath): string
    {
        $src = (string) file_get_contents(APPPATH . $relPath);

        // Strip // line comments and /* */ blocks so prose cannot trip the scan.
        $src = preg_replace('!/\*.*?\*/!s', '', $src);
        $src = preg_replace('!^\s*//.*$!m', '', $src);
        $src = preg_replace('!^\s*\*.*$!m', '', $src);

        return (string) $src;
    }

    /** @return string[] */
    private function guardedFiles(): array
    {
        return [
            'controllers/Fees.php',
            'controllers/Admission_public.php',
            'config/config.php',
            'config/auth_api.php',
        ];
    }

    // ══ CONTROL ═════════════════════════════════════════════════════════════

    /**
     * Without this, a passing run could mean "no bad idiom" OR "the comment
     * stripper ate the whole file".
     */
    public function test_control_the_stripper_keeps_executable_code(): void
    {
        $src = $this->executableSource('controllers/Fees.php');

        $this->assertNotEmpty($src);
        $this->assertStringContainsString('class Fees', $src,
            'the comment stripper removed executable code — every scan below would pass '
            . 'vacuously');
        $this->assertStringNotContainsString('It previously blocked only when', $src,
            'the stripper is not removing comments, so the scans will match the prose that '
            . 'explains the old idiom and fail forever');
    }

    // ══ THE INVARIANT ═══════════════════════════════════════════════════════

    /**
     * WOULD HAVE FAILED BEFORE: all four files contained the idiom.
     */
    public function test_no_security_guard_opens_when_the_environment_is_unknown(): void
    {
        $offenders = [];

        foreach ($this->guardedFiles() as $file) {
            $src = $this->executableSource($file);
            if (preg_match("/ENVIRONMENT\s*===\s*'production'/", $src)) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders,
            'these files gate a security decision on ENVIRONMENT === \'production\': '
            . implode(', ', $offenders) . '. ENVIRONMENT defaults to "development" when '
            . 'CI_ENV is unset, so the guard OPENS precisely when configuration is missing. '
            . 'Invert it: permit only an explicitly non-production environment.');
    }

    public function test_each_guard_permits_only_an_explicit_development_environment(): void
    {
        foreach ($this->guardedFiles() as $file) {
            $src = $this->executableSource($file);

            $this->assertMatchesRegularExpression(
                "/ENVIRONMENT\s*!==\s*'development'|in_array\(\s*ENVIRONMENT,\s*\['development',\s*'testing'\]/",
                $src,
                "{$file} no longer expresses its environment guard as an allowlist of "
                . 'non-production environments. An unset or unexpected CI_ENV must DENY.'
            );
        }
    }

    // ══ GW-1b · THE PUBLIC ADMISSION PATH ═══════════════════════════════════

    /**
     * The admission mock adapter fakes a successful payment and marks an
     * application paid with no money collected — and unlike the fees path this
     * one is reachable from a PUBLIC, unauthenticated form.
     *
     * PA-DUP-1 (2026-08-24): this guard used to be an inline
     * `!in_array(ENVIRONMENT, ['development','testing'], true)` check living
     * directly in Admission_public.php, independently of Fees' equivalent
     * guard — and the two authorities had already drifted once (Admission
     * honoured `active: false`; Fees did not, until GW-SEL-1). The
     * environment allowlist now lives ONCE, in
     * `Payment_gateway_selector::SIMULATOR_ENVIRONMENTS`, and BOTH Fees and
     * Admission consume its verdict rather than each re-deriving it.
     *
     * So this test's job changes shape without weakening: instead of finding
     * the allowlist regex textually adjacent to the mock load, it proves (a)
     * admission defers to the shared selector before ever reaching the mock
     * load, and (b) a refusal (UNAVAILABLE) still throws before the mock
     * could load. The allowlist's actual fail-closed BEHAVIOUR — mock
     * permitted only in development/testing, refused otherwise — is proven
     * directly, with real inputs, by
     * `SingleProviderAuthorityParityTest::test_selector_resolves_the_shared_matrix_consistently`,
     * which is the stronger form of evidence this file's own header asks for.
     */
    public function test_the_admission_mock_gateway_is_unreachable_outside_development(): void
    {
        $src = $this->executableSource('controllers/Admission_public.php');

        $load = strpos($src, "load->library('Payment_gateway_mock')");
        $this->assertNotFalse($load, 'the admission mock adapter load was renamed');

        $before = substr($src, 0, $load);

        $selectAt = strrpos($before, 'Payment_gateway_selector::select(');
        $this->assertNotFalse($selectAt,
            'the admission mock adapter no longer resolves through the shared selector — '
            . 'either the old inline environment check is back, or the mock is reachable '
            . 'with no gate at all');

        $mockCheckAt = strrpos($before, 'Payment_gateway_selector::MOCK');
        $this->assertNotFalse($mockCheckAt,
            'the mock load is not conditioned on the selector returning MOCK');
        $this->assertGreaterThan($selectAt, $mockCheckAt,
            'the MOCK check appears before the selector is even called');

        $guard = strrpos($before, 'RuntimeException');
        $this->assertNotFalse($guard,
            'nothing throws for the UNAVAILABLE case before the admission mock adapter is '
            . 'loaded — a misconfigured tenant would fake admission-fee payments on a '
            . 'public form');
    }

    // ══ SEC-CFG-1 · THE COMMITTED FALLBACK SECRETS ══════════════════════════

    /**
     * Inverting the comparison does not rescue these two when CI_ENV is simply
     * absent. The fallback must therefore be OBSERVABLE — a silent weak secret
     * is the dangerous condition.
     */
    public function test_a_fallback_secret_is_logged_loudly_rather_than_used_silently(): void
    {
        foreach (['config/config.php', 'config/auth_api.php'] as $file) {
            $src = $this->executableSource($file);

            $this->assertStringContainsString('SEC-CFG-1', $src,
                "{$file} no longer emits the fallback-secret warning. The published "
                . 'development secret would then be used silently on a host where CI_ENV '
                . 'is missing, with nothing in the logs to reveal it.');
            $this->assertStringContainsString('error_log(', $src,
                "{$file} must use error_log(), not log_message() — CI's logger does not "
                . 'exist yet at config load time, so log_message() would emit nothing');
        }
    }

    /**
     * The committed fallbacks are the actual hazard. Pinned so their removal —
     * the real fix — is a deliberate, visible change rather than a silent one,
     * and so nobody adds a THIRD one.
     */
    public function test_the_known_committed_fallback_secrets_are_still_only_these_two(): void
    {
        $enc  = $this->executableSource('config/config.php');
        $auth = $this->executableSource('config/auth_api.php');

        $this->assertStringContainsString("'CHANGE_ME_IN_ENV'", $enc,
            'the encryption-key fallback changed. If it was REMOVED, that is the correct '
            . 'fix — update this test deliberately. If it was replaced with another '
            . 'hardcoded key, that is the same defect wearing a different value.');

        $this->assertMatchesRegularExpression('/\$_internalKey \?: \'[0-9a-f]{64}\'/', $auth,
            'the internal auth secret fallback changed. Removing it is the correct fix; '
            . 'swapping it for another committed constant is not.');
    }
}
