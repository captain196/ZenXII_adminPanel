<?php
/**
 * THE GUARD THAT MUST NEVER LET A WRITE PROBE REACH PRODUCTION.
 *
 * ── THE INCIDENT THIS EXISTS FOR (2026-08-31) ─────────────────────────────
 * `Lifecycle_probe::_assertEmulator()` checked that `FIRESTORE_EMULATOR_HOST`
 * was set and loopback-shaped, then declared the run sandboxed.
 *
 * It never asked whether the CLIENT honoured it. The emulator seam in
 * `Firestore_rest_client` was present only in the working tree and had never
 * been committed, so a clean checkout of the committed state had no
 * `emulatorHost()` at all and silently used its default,
 * `https://firestore.googleapis.com`.
 *
 * The variable said emulator. The client went to the live project. A
 * write-capable lifecycle harness then created 85 documents in the real
 * `graderadmin` database — three fabricated tenants, four students, a
 * 65-account chart of accounts — while reporting itself safe.
 *
 * ── THE RULE ──────────────────────────────────────────────────────────────
 * An environment variable states an INTENTION. Only the client can state its
 * DESTINATION. Anything that gates writes must ask the client, and must fail
 * closed when it cannot get an answer — because "I could not prove this is the
 * emulator" and "this is production" have identical consequences before the
 * first write.
 */

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once APPPATH . 'libraries/Firestore_rest_client.php';

final class EmulatorWriteGuardTest extends TestCase
{
    private const PROBE  = APPPATH . 'controllers/Lifecycle_probe.php';
    private const CLIENT = APPPATH . 'libraries/Firestore_rest_client.php';

    private string $saved = '';

    protected function setUp(): void    { $this->saved = (string) getenv('FIRESTORE_EMULATOR_HOST'); }
    protected function tearDown(): void
    {
        if ($this->saved === '') putenv('FIRESTORE_EMULATOR_HOST');
        else putenv('FIRESTORE_EMULATOR_HOST=' . $this->saved);
    }

    /** A client instance without touching credentials — only routing is under test. */
    private function client(): FirestoreRestClient
    {
        $r = new ReflectionClass(FirestoreRestClient::class);
        return $r->newInstanceWithoutConstructor();
    }

    private function root(?string $host): string
    {
        if ($host === null) putenv('FIRESTORE_EMULATOR_HOST');
        else putenv('FIRESTORE_EMULATOR_HOST=' . $host);
        return $this->client()->resolvedApiRoot();
    }

    private function isEmu(?string $host): bool
    {
        if ($host === null) putenv('FIRESTORE_EMULATOR_HOST');
        else putenv('FIRESTORE_EMULATOR_HOST=' . $host);
        return $this->client()->isEmulatorTarget();
    }

    // ══ THE CLIENT MUST TELL THE TRUTH ABOUT ITS DESTINATION ═══════════════

    public function test_a_correct_emulator_host_resolves_to_loopback(): void
    {
        $this->assertSame('http://127.0.0.1:8092', $this->root('127.0.0.1:8092'));
        $this->assertTrue($this->isEmu('127.0.0.1:8092'));
        $this->assertTrue($this->isEmu('localhost:8080'));
    }

    /** No variable = production. This is the state a clean checkout is in. */
    public function test_an_unset_variable_resolves_to_production(): void
    {
        $this->assertSame('https://firestore.googleapis.com', $this->root(null));
        $this->assertFalse($this->isEmu(null),
            'with no emulator configured the client targets the live project, and a '
            . 'write-capable probe must never run against it');
    }

    /** A malformed value must NOT be quietly treated as an emulator. */
    public function test_a_malformed_host_falls_back_to_production_and_is_refused(): void
    {
        foreach (['not-a-host', 'http://', '127.0.0.1', ':8092', '127.0.0.1:99999999'] as $bad) {
            $this->assertFalse($this->isEmu($bad),
                "'{$bad}' is not a usable emulator host; treating it as one would send "
                . 'writes to production');
        }
    }

    /** A REMOTE host is not a sandbox, however emulator-shaped it looks. */
    public function test_a_non_loopback_host_is_not_an_emulator(): void
    {
        foreach (['10.0.0.5:8092', 'firestore.example.com:8092', '192.168.1.10:8080'] as $remote) {
            $this->assertFalse($this->isEmu($remote),
                "'{$remote}' is off-box: a harness pointed there is not sandboxed");
        }
    }

    /** Production must never be mistaken for an emulator, whatever the variable says. */
    public function test_production_is_never_classified_as_an_emulator(): void
    {
        $this->assertStringContainsString('firestore.googleapis.com', $this->root(null));
        $this->assertFalse($this->isEmu(null));
    }

    /**
     * The check is an ALLOW-LIST. A deny-list would pass for any hostname
     * nobody thought to ban — and the incident was caused by exactly that
     * shape of reasoning ("the variable looks fine, so we are safe").
     */
    public function test_the_target_check_proves_loopback_rather_than_merely_excluding_production(): void
    {
        $src = (string) file_get_contents(self::CLIENT);
        $at  = strpos($src, 'function isEmulatorTarget');
        $this->assertNotFalse($at, 'the client must be able to state its destination');
        $body = substr($src, $at, 700);

        $this->assertStringContainsString("['127.0.0.1', 'localhost', '::1']", $body,
            'loopback must be proven from an allow-list');
        $this->assertStringContainsString("strpos(\$root, 'http://') !== 0", $body,
            'production is https; anything not plain http is not a local emulator');
    }

    // ══ THE PROBE MUST ASK THE CLIENT, NOT THE ENVIRONMENT ═════════════════

    public function test_the_probe_verifies_the_resolved_destination(): void
    {
        $src = (string) file_get_contents(self::PROBE);

        $this->assertStringContainsString('resolvedApiRoot()', $src,
            'the guard must ask the CLIENT where it will talk — a variable states an '
            . 'intention, only the client states a destination');
        $this->assertStringContainsString('isEmulatorTarget', $src);
        $this->assertStringContainsString("stripos(\$root, 'firestore.googleapis.com') !== false", $src,
            'and must explicitly refuse the production endpoint');
    }

    /**
     * THE EXACT INCIDENT CONDITION: the variable is set correctly, but the
     * client build cannot honour it. Reproduced live against HEAD's seam-less
     * client, which refused with "cannot report its destination".
     */
    public function test_a_client_that_cannot_report_its_destination_is_refused(): void
    {
        $src = (string) file_get_contents(self::PROBE);

        $this->assertStringContainsString("method_exists('FirestoreRestClient', 'isEmulatorTarget')", $src,
            'a build predating the target check is the 2026-08-31 condition and must be '
            . 'refused, not assumed safe');
        $this->assertStringContainsString('cannot report its destination', $src);
    }

    /** Every uncertainty must fail CLOSED, before the first write. */
    public function test_every_failure_path_refuses_rather_than_continues(): void
    {
        $src = (string) file_get_contents(self::PROBE);

        foreach (['Firestore_rest_client.php not found',
                  'cannot report its destination',
                  'could not construct the Firestore client'] as $path) {
            $this->assertStringContainsString($path, $src,
                "'{$path}' must be an explicit refusal — silence would let a write proceed");
        }
        $this->assertStringContainsString('private function _refuse(', $src,
            'one refusal path, so no caller can accidentally continue past a failed check');
        $this->assertStringContainsString('exit(3);', $src,
            'refusal must terminate the process, not return');
    }

    /**
     * The seam must be COMMITTED. Its absence from committed state is the root
     * cause: a clean checkout could not route to an emulator even when asked.
     */
    public function test_the_emulator_seam_exists_in_the_client(): void
    {
        $src = (string) file_get_contents(self::CLIENT);
        $this->assertStringContainsString('function emulatorHost', $src);
        $this->assertStringContainsString('FIRESTORE_EMULATOR_HOST', $src,
            'the client itself must honour the variable; a harness cannot route on its behalf');
    }
}
