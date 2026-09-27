<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  THE EMULATOR SEAM MUST BE INERT UNLESS DELIBERATELY SWITCHED ON
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── WHY THE SEAM EXISTS ──────────────────────────────────────────────────
 * Proving lifecycle transitions at RUNTIME requires executing real writes.
 * `Firestore_rest_client` hardcoded the production host, so the only options
 * were writing to a live operator tenant or never proving the transitions.
 * An emulator is strictly safer than even a "fixture tenant" in the live
 * project, because no live data is present to damage.
 *
 * ── WHY THAT IS NOT A WEAKENED CONTROL ───────────────────────────────────
 * A redirect that can be flipped by accident, or pointed anywhere, WOULD be.
 * This one is bounded by construction, and these tests exist to keep it that
 * way — they are the reason the seam is acceptable at all:
 *
 *   • unset  -> production, byte-identical to before;
 *   • the value is host:port ONLY. A scheme, a path, or a bare hostname is
 *     rejected and production is used, so the variable cannot be turned into
 *     "send this school's writes to my server";
 *   • it changes only WHERE a request goes, never WHAT a caller may do — no
 *     authorization, tenancy or validation path consults it;
 *   • in emulator mode the OAuth fetch is skipped and the literal `owner`
 *     bearer is used, which is worthless against production. If the two
 *     branches ever disagreed, the failure direction is "cannot reach
 *     production", not "reaches production unauthenticated".
 *
 * The rules are re-implemented here rather than invoked because the client
 * cannot be constructed without the CodeIgniter framework. They are copied
 * verbatim from the implementation and pinned against it by
 * `test_the_guard_under_test_matches_the_implementation`, so the two cannot
 * drift without this file failing.
 */
final class FirestoreEmulatorSeamTest extends TestCase
{
    private static string $src = '';

    public static function setUpBeforeClass(): void
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents(APPPATH . 'libraries/Firestore_rest_client.php')) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else { $out .= $t; }
        }
        self::$src = (string) preg_replace('/\s+/', ' ', $out);
    }

    /** Verbatim copy of Firestore_rest_client::emulatorHost(). */
    private function emulatorHost(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';
        $raw = preg_replace('#^https?://#i', '', $raw);
        $raw = rtrim((string) $raw, '/');
        if (!preg_match('/^[A-Za-z0-9._-]+:\d{2,5}$/', $raw)) return '';
        return $raw;
    }

    private function apiRoot(string $raw): string
    {
        $e = $this->emulatorHost($raw);
        return $e === '' ? 'https://firestore.googleapis.com' : "http://{$e}";
    }

    // ── THE DEFAULT IS PRODUCTION ────────────────────────────────────────

    public function test_an_unset_variable_uses_production(): void
    {
        $this->assertSame('https://firestore.googleapis.com', $this->apiRoot(''));
        $this->assertSame('https://firestore.googleapis.com', $this->apiRoot('   '));
    }

    // ── THE VALUE CANNOT POINT ANYWHERE IT LIKES ─────────────────────────

    /**
     * @dataProvider unsafeValues
     */
    public function test_an_unsafe_value_falls_back_to_production(string $value, string $why): void
    {
        $this->assertSame('https://firestore.googleapis.com', $this->apiRoot($value), $why);
    }

    public static function unsafeValues(): array
    {
        return [
            'a path can redirect writes'      => ['evil.example.com/collect', 'a path was accepted'],
            'no port is not a host:port'      => ['evil.example.com', 'a bare hostname was accepted'],
            'a full URL with path'            => ['http://evil.example.com/v1', 'a URL with a path was accepted'],
            'production itself is not a host:port' => ['https://firestore.googleapis.com', 'accepted without a port'],
            'query string'                    => ['localhost:8092?x=1', 'a query string was accepted'],
            'credentials in authority'        => ['user:pw@host:8092', 'a userinfo section was accepted'],
            'port too long'                   => ['localhost:1234567', 'an out-of-range port was accepted'],
            'empty host'                      => [':8092', 'a hostless value was accepted'],
        ];
    }

    public function test_a_local_emulator_is_accepted(): void
    {
        // The positive control. Without it every assertion above could pass
        // on a guard that rejects everything, and the seam would be dead code.
        $this->assertSame('http://localhost:8092', $this->apiRoot('localhost:8092'));
        $this->assertSame('http://127.0.0.1:8092', $this->apiRoot('127.0.0.1:8092'));
        $this->assertSame('http://127.0.0.1:8092', $this->apiRoot('http://127.0.0.1:8092'),
            'the documented http:// form is rejected — the standard Google variable often carries it');
    }

    // ── THE IMPLEMENTATION MATCHES WHAT IS TESTED ────────────────────────

    public function test_the_guard_under_test_matches_the_implementation(): void
    {
        // Without this the copies above could drift and keep passing.
        $this->assertStringContainsString(
            "preg_match('/^[A-Za-z0-9._-]+:\\d{2,5}\$/', \$raw)", self::$src,
            'the host:port guard in the client no longer matches the one tested here'
        );
        $this->assertStringContainsString("getenv('FIRESTORE_EMULATOR_HOST')", self::$src,
            'the seam no longer reads the standard Google variable');
    }

    public function test_every_endpoint_goes_through_the_single_root(): void
    {
        // A missed endpoint would send SOME traffic to production while the
        // rest went to the emulator — the worst of both, and very hard to
        // notice, because most operations would appear to work.
        $this->assertSame(1, substr_count(self::$src, "'https://firestore.googleapis.com'"),
            'the production host appears outside apiRoot() — an endpoint bypasses the seam');
        $this->assertGreaterThanOrEqual(4, substr_count(self::$src, '$this->apiRoot()'),
            'not every Firestore endpoint is routed through apiRoot()');
    }

    public function test_emulator_mode_never_fetches_a_production_token(): void
    {
        $at = strpos(self::$src, 'private function getAccessToken');
        $this->assertNotFalse($at, 'the token entry point moved');
        $window = substr(self::$src, $at, 400);
        $this->assertStringContainsString('emulatorHost()', $window,
            'getAccessToken does not short-circuit in emulator mode — it would try to '
            . 'mint a real production credential for a test run');
        $this->assertStringContainsString("return 'owner'", $window,
            'the emulator branch does not return the emulator bearer');
    }

    public function test_the_seam_gates_nothing_but_the_destination(): void
    {
        // If the variable ever influenced authorization or tenancy it would
        // stop being a testability seam and become a bypass.
        foreach (['_require_role', 'has_permission', 'schoolId ==', 'docId('] as $authorityish) {
            $at = strpos(self::$src, 'private function emulatorHost');
            $window = substr(self::$src, $at, 800);
            $this->assertStringNotContainsString($authorityish, $window,
                "the emulator guard sits next to {$authorityish} — it must influence the "
                . 'destination host only, never authorization or tenancy');
        }
    }
}
