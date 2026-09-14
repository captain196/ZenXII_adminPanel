<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A FAILED read must never be cached as "the document is absent".
 *
 * ── THE DEFECT (found 2026-09-13) ────────────────────────────────────────
 *
 * FirestoreRestClient::getDocument() returns null for BOTH a genuine 404 and
 * any other non-200 (a 500, a 429, a network blip): _getDocumentUncached()
 * logs the error and `return null`. getDocument() then wrote that null into the
 * cross-request config cache — which holds schoolControl and schools for FIVE
 * MINUTES.
 *
 * So a single transient error on schoolControl pinned "this tenant does not
 * exist" for the whole TTL. During that window:
 *
 *   • Lifecycle_resolver read null → commercial state 'unknown';
 *   • the platform timetable scheduler skipped the tenant (TENANT_STATE_UNKNOWN);
 *   • a fresh School-Super-Admin login was refused with
 *     "Subscription record not found";
 *   • diagnostics reading through getDocument() reported the school as MISSING.
 *
 * All of that for a school (SCH_B56BB9A401) that has been lifecycle-active since
 * 2026-08-29. The provisioning was correct; a cached error-null made it look
 * broken. This is the same swallowed-failure class NegativeGuardFailLoudTest
 * fixed for query(): a failed read is not an empty result.
 *
 * THE FIX: getDocument() reads through the fail-loud path, which returns null
 * ONLY for a real 404 and throws on every other non-200. A genuine absence is
 * still cached; a failed read is caught, returned as null for the historic
 * contract, and neither memoized nor written to the config cache — so the next
 * read retries live.
 *
 * Source-assertion, matching the file's own test style: FirestoreRestClient
 * cannot be instantiated without a service account and a network.
 */
final class ConfigCacheErrorNullTest extends TestCase
{
    private static string $src = '';   // comment-stripped code

    public static function setUpBeforeClass(): void
    {
        $raw = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Firestore_rest_client.php');
        // Strip comments so a comment mentioning a call is never read as the
        // call — the recurring source-scan trap. Whitespace is collapsed to
        // single spaces so brace/return matches do not depend on layout.
        $code = '';
        foreach (token_get_all($raw) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { $code .= ' '; continue; }
                $code .= $t[1];
            } else { $code .= $t; }
        }
        self::$src = (string) preg_replace('~\s+~', ' ', $code);
    }

    /** The body of getDocument(), to the next method. */
    private function getDocumentBody(): string
    {
        $i = strpos(self::$src, 'public function getDocument(');
        $this->assertNotFalse($i, 'getDocument() is gone');
        $next = strpos(self::$src, 'public function getDocumentOrFail', $i + 20);
        return substr(self::$src, $i, ($next !== false ? $next - $i : 4000));
    }

    /** THE FIX: the read that feeds the cache is the fail-loud one. */
    public function test_the_cached_read_uses_the_fail_loud_path(): void
    {
        $body = $this->getDocumentBody();

        $this->assertMatchesRegularExpression(
            '~\$val\s*=\s*\$this->_getDocumentUncached\(\s*\$collection,\s*\$docId,\s*true\s*\)~',
            $body,
            'getDocument() no longer reads through the fail-loud path, so a '
            . 'non-200 error again returns a null that is indistinguishable from '
            . 'a genuine 404 and gets cached as "absent"');
    }

    /** A failed read is caught and returned WITHOUT reaching the cache writes. */
    public function test_a_failed_read_is_caught_before_any_cache_write(): void
    {
        $body = $this->getDocumentBody();

        // The catch must return before the memo/config-cache writes.
        $catch  = strpos($body, 'catch (\Throwable $e)');
        $this->assertNotFalse($catch, 'the failed read is not caught at all');

        $catchReturn = strpos($body, 'return null;', $catch);
        $memoWrite   = strpos($body, '$this->_readCache[$ck] = $val;');
        $cfgWrite    = strpos($body, '_configCacheWrite(');

        $this->assertNotFalse($catchReturn, 'the catch does not return');
        $this->assertNotFalse($memoWrite);
        $this->assertNotFalse($cfgWrite);

        $this->assertLessThan($memoWrite, $catchReturn,
            'a caught failure falls through to the request memo write — the '
            . 'error-null is still cached for the request');
        $this->assertLessThan($cfgWrite, $catchReturn,
            'a caught failure falls through to the config-cache write — the '
            . 'error-null is still pinned for the full TTL, which is the defect');
    }

    /** Only ONE value is ever written to the caches, and it is the success value. */
    public function test_only_the_successful_value_reaches_the_cache(): void
    {
        $body = $this->getDocumentBody();

        // The plain (swallowing) uncached read must not be what feeds the cache.
        $this->assertDoesNotMatchRegularExpression(
            '~_configCacheWrite\([^)]*\b_getDocumentUncached\(\$collection,\s*\$docId\)~',
            $body,
            'the config cache is fed directly from the swallowing read');

        // The config-cache write is reached only after the try succeeded.
        $try   = strpos($body, 'try {');
        $cfg   = strpos($body, '_configCacheWrite(');
        $this->assertNotFalse($try);
        $this->assertLessThan($cfg, $try,
            'the config-cache write is not guarded by the try/catch, so a failed '
            . 'read can still reach it');
    }

    /**
     * The fail-loud sibling still exists and still returns null only for a 404
     * — the property this fix leans on.
     */
    public function test_the_fail_loud_read_distinguishes_404_from_error(): void
    {
        $this->assertStringContainsString(
            'private function _getDocumentUncached(string $collection, string $docId, bool $failLoud = false)',
            self::$src, 'the fail-loud seam is gone');

        $i = strpos(self::$src, 'private function _getDocumentUncached');
        $fn = substr(self::$src, $i, 900);

        $this->assertMatchesRegularExpression('~if \(\$r\[.code.\] === 404\) return null;~', $fn,
            'a genuine 404 must still be the only null returned to the cache');
        $this->assertMatchesRegularExpression('~if \(\$failLoud\)[\s\S]{0,200}throw new \\\\RuntimeException~', $fn,
            'a non-404 error must throw under failLoud, or the cached path cannot '
            . 'tell a failure from an absence');
    }

    /**
     * The genuine-absence path is preserved: create-vs-update callers rely on a
     * real 404 being cached, so this fix must not stop caching true absences.
     */
    public function test_a_genuine_absence_is_still_cached(): void
    {
        $body = $this->getDocumentBody();
        // On the success path (try did not throw), $val — which is null for a
        // real 404 — is written to both caches.
        $this->assertMatchesRegularExpression(
            '~\}\s*catch \(\\\\Throwable \$e\) \{\s*return null;\s*\}\s*'
            . '\$this->_readCache\[\$ck\] = \$val;~',
            $body,
            'after a successful (non-throwing) read, the value — including a '
            . 'genuine-404 null — must still populate the cache');
    }
}
