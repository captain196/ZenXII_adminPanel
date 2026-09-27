<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', 'x');
if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

require_once APPPATH . 'libraries/Payment_gateway_selector.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  PAY-OPS-4 · THE PROVIDER-API TEST SEAM MUST NOT BE A PRODUCTION HOLE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── WHY THE SEAM EXISTS ──────────────────────────────────────────────────
 * The webhook now settles a payment only when the PROVIDER's own record says
 * `captured`. Proving the refusal half of that end-to-end needs a provider
 * that can be made to say something else — and the mock adapter answers
 * `captured` unconditionally, by design. `Payment_gateway_razorpay` has always
 * accepted an `api_base` override in its constructor; nothing ever supplied
 * one, so the branch was unreachable outside unit tests.
 *
 * ── WHY IT IS DANGEROUS ──────────────────────────────────────────────────
 * `api_base` is read from a FIRESTORE DOCUMENT. Honoured unconditionally, it
 * would let anyone who can write that document point ZenX's payment
 * confirmation at a server they control and have it answer "captured" for
 * money nobody paid — strictly worse than the bug the seam helps fix. A test
 * seam that widens the money path in production is not a test seam.
 *
 * ── THE THREE CONSTRAINTS, PINNED ────────────────────────────────────────
 *   1. honoured ONLY in a simulator environment
 *   2. loopback destinations ONLY, even there
 *   3. the environment list is DERIVED from Payment_gateway_selector, never a
 *      second copy — three lists that must agree by discipline is exactly how
 *      PA-UI-1 happened
 *
 * The predicate is reproduced here and the controller is asserted to still
 * contain it verbatim, because `_init_payment_service()` is a private method
 * on a CI3 controller that cannot be instantiated outside the framework. That
 * is weaker than executing it and is stated rather than glossed.
 */
final class ProviderApiBaseSeamTest extends TestCase
{
    private static string $src = '';

    public static function setUpBeforeClass(): void
    {
        self::$src = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');
    }

    /** The controller's own loopback predicate, verbatim. */
    private function loopbackOnly(string $apiBase): bool
    {
        return (bool) preg_match(
            '~^https?://(127\.0\.0\.1|localhost|\[?::1\]?)(:\d+)?(/|$)~i', $apiBase);
    }

    public function test_the_controller_still_contains_this_exact_predicate(): void
    {
        $this->assertStringContainsString(
            "preg_match('~^https?://(127\\.0\\.0\\.1|localhost|\\[?::1\\]?)(:\\d+)?(/|$)~i', \$__apiBase)",
            self::$src,
            'the api_base loopback predicate changed and this test now mirrors code that no '
            . 'longer exists. Update both together or the seam is unguarded.');
    }

    // ══ CONSTRAINT 2 · LOOPBACK ONLY ════════════════════════════════════════

    /**
     * @dataProvider destinations
     */
    public function test_only_loopback_destinations_are_accepted(string $base, bool $allowed): void
    {
        $this->assertSame($allowed, $this->loopbackOnly($base),
            $allowed
                ? "a legitimate loopback stub [{$base}] was refused, so the refusal half of "
                  . 'the webhook confirmation cannot be proven end to end'
                : "[{$base}] was accepted as a provider API base. A non-loopback override "
                  . 'lets whoever controls that host manufacture a captured payment.');
    }

    public function destinations(): array
    {
        return [
            'loopback ip'        => ['http://127.0.0.1:8123/v1', true],
            'loopback name'      => ['http://localhost:8123/v1', true],
            'loopback v6'        => ['http://[::1]:8123/v1',     true],
            'loopback no port'   => ['http://127.0.0.1/v1',      true],
            'loopback bare'      => ['http://127.0.0.1',         true],
            'real razorpay'      => ['https://api.razorpay.com/v1', false],
            'attacker host'      => ['https://evil.example.com/v1', false],
            // The classic bypass: a hostname that merely BEGINS with the
            // loopback literal. `(:\d+)?(/|$)` is what refuses it.
            'prefix bypass'      => ['http://127.0.0.1.evil.com/v1', false],
            'localhost prefix'   => ['http://localhost.evil.com/v1', false],
            'userinfo bypass'    => ['http://127.0.0.1@evil.com/v1', false],
            'not http'           => ['file:///etc/passwd', false],
            'empty'              => ['', false],
        ];
    }

    // ══ CONSTRAINT 1 · SIMULATOR ENVIRONMENTS ONLY ══════════════════════════

    public function test_production_is_not_a_simulator_environment(): void
    {
        $this->assertNotContains('production', Payment_gateway_selector::SIMULATOR_ENVIRONMENTS,
            'production has been added to SIMULATOR_ENVIRONMENTS. That single change makes '
            . 'the api_base override live, and with it the forgeable mock adapter.');
        $this->assertSame(['development', 'testing'], Payment_gateway_selector::SIMULATOR_ENVIRONMENTS);
    }

    public function test_the_override_is_gated_on_the_environment(): void
    {
        $this->assertStringContainsString(
            "Payment_gateway_selector::SIMULATOR_ENVIRONMENTS, true)", self::$src,
            'the api_base override is no longer gated on the environment, or the gate now '
            . 'uses its own environment list instead of the selector\'s.');
    }

    // ══ CONSTRAINT 3 · NO SECOND ENVIRONMENT LIST ═══════════════════════════

    public function test_the_controller_does_not_hardcode_its_own_environment_list(): void
    {
        $seam  = strpos(self::$src, '$__apiBase = trim(');
        $this->assertNotFalse($seam, 'the api_base seam has been removed or renamed');
        $block = substr(self::$src, $seam, 900);

        $this->assertStringNotContainsString("['development', 'testing']", $block,
            'the seam carries its own copy of the simulator-environment list. A second list '
            . 'that must agree by discipline is how the provider tiles and the server '
            . 'allowlist drifted apart for years (PA-UI-1).');
    }

    /** Silence would be the wrong failure mode for a widened money path. */
    public function test_using_the_seam_is_logged(): void
    {
        $seam  = strpos(self::$src, '$__apiBase = trim(');
        $block = substr(self::$src, $seam, 1400);

        $this->assertStringContainsString('OVERRIDDEN to a loopback stub', $block,
            'engaging the provider-API override is no longer logged. A confirmation path '
            . 'pointed somewhere other than the real provider must say so.');
        $this->assertStringContainsString('REFUSED', $block,
            'a refused override is silent, so a misconfigured stub looks like a working one.');
    }

    /**
     * The default must remain the real API. If `api_base` is absent the adapter
     * uses its own hardcoded constant — production behaviour byte-identical to
     * before this seam existed.
     */
    public function test_the_adapter_default_is_still_the_real_razorpay_api(): void
    {
        $adapter = (string) file_get_contents(APPPATH . 'libraries/Payment_gateway_razorpay.php');
        $this->assertStringContainsString(
            "private \$apiBase = 'https://api.razorpay.com/v1';", $adapter,
            'the adapter no longer defaults to the real Razorpay API.');
    }
}
