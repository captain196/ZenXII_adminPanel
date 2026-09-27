<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Firestore_service::getOrFail() must actually FAIL LOUD on a transient read
 * error — its entire reason to exist.
 *
 * ── THE BUG ──────────────────────────────────────────────────────────────
 *
 * getOrFail's docblock promises it "distinguishes the ERROR path by throwing",
 * so a fail-closed caller (the attendance lock gate `_get_lock($failClosed=true)`)
 * can treat a read error as LOCKED and refuse a write onto a finalized day. But
 * the implementation called `$this->client->getDocument()`, which SWALLOWS a
 * transient failure to null (returns null for both a 404 and a 429/500/network
 * error). So getOrFail never threw on a transient error, the caller's try/catch
 * was dead code, and the lock gate fell through to "not locked" — fail OPEN.
 *
 * THE FIX: call `getDocumentOrFail()`, which returns null ONLY for a genuine 404
 * and THROWS on a transient/non-404 failure.
 *
 * Source-assertion (matches the offline test convention in this suite).
 */
final class FirestoreServiceGetOrFailFailsLoudTest extends TestCase
{
    private static string $method = '';

    public static function setUpBeforeClass(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Firestore_service.php');
        $i = strpos($src, 'function getOrFail');
        self::assertNotFalse($i, 'getOrFail is gone from Firestore_service');
        $end = strpos($src, "\n    }", $i);
        self::$method = substr($src, $i, ($end !== false ? $end - $i + 6 : 900));
    }

    private function code(string $s): string
    {
        $out = '';
        foreach (token_get_all("<?php\n" . $s) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        return (string) preg_replace('/\s+/', ' ', $out);
    }

    public function test_getOrFail_uses_the_throwing_primitive(): void
    {
        $this->assertStringContainsString(
            'getDocumentOrFail(', $this->code(self::$method),
            'getOrFail must call getDocumentOrFail so a transient error throws');
    }

    public function test_getOrFail_does_not_use_the_swallowing_get(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '~->getDocument\(~', $this->code(self::$method),
            'getOrFail must NOT call the swallowing getDocument(), which returns '
            . 'null on a transient error and silently defeats fail-closed callers');
    }
}
