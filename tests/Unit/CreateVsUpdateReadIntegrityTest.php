<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ACAD-D17 — a failed single-doc read must not be mistaken for "no such record".
 *
 * The single-document twin of ACAD-D12. FirestoreRestClient::_getDocumentUncached()
 * returned null for BOTH cases:
 *
 *     if ($r['code'] === 404) return null;      // genuinely absent
 *     if ($r['code'] !== 200) { log(...); return null; }   // read FAILED
 *
 * Three Academic services decide CREATE vs UPDATE from exactly that null:
 *
 *     try { $existing = firestoreGet(COLLECTION, $fsDocId); }
 *     catch (\Throwable $e) {}                  // never fires: get() does not throw
 *     $currVersion = is_array($existing) ? (int)($existing['version'] ?? 0) : 0;
 *     if (!is_array($existing)) { $data['createdAt'] = $now; ... }
 *     $this->_audit(is_array($existing) ? 'update' : 'create', ...);
 *
 * So one transient failure on an EDIT silently:
 *   - resets `version` to 1;
 *   - overwrites createdAt / createdByUid, destroying provenance;
 *   - writes an audit entry saying **create** for what was an update.
 *
 * The audit consequence is the serious one. The audit trail exists to answer
 * "who changed this and when"; after this it answers wrongly, and nothing about
 * the record shows that it was ever edited.
 *
 * All three writes use merge = true, so no field is wholesale destroyed —
 * verified, not assumed. The damage is to version, provenance and the audit
 * trail, not to the payload.
 *
 * The fix mirrors queryOrFail(): a fail-loud getter where null means ABSENT and
 * only absent. 404 still returns null, because that is the honest null.
 */
final class CreateVsUpdateReadIntegrityTest extends TestCase
{
    /** Services whose save() decides create-vs-update from a single-doc read. */
    private const DECIDERS = [
        'Substitute_service.php',
        'Calendar_service.php',
        'Curriculum_service.php',
    ];

    private function lib(string $f): string
    {
        $p = __DIR__ . '/../../application/libraries/' . $f;
        $this->assertFileExists($p);
        return (string) file_get_contents($p);
    }

    /** Comments naming a call must not satisfy assertions about that call. */
    private function code(string $f): string
    {
        $s = preg_replace('~/\*.*?\*/~s', '', $this->lib($f));
        return (string) preg_replace('~^\s*//.*$~m', '', (string) $s);
    }

    // -- the transport -----------------------------------------------------

    /** 404 must stay a null — that is the one honest null. */
    public function test_a_genuine_404_still_returns_null(): void
    {
        $c = $this->code('Firestore_rest_client.php');

        $i = strpos($c, 'private function _getDocumentUncached');
        $this->assertNotFalse($i, '_getDocumentUncached is gone');
        $fn = substr($c, $i, 1400);

        $this->assertMatchesRegularExpression(
            "~if \(\\\$r\['code'\] === 404\) return null;~", $fn,
            'an absent document must still be reported as null, or every caller '
            . 'that legitimately creates a new record starts throwing');
    }

    /** Every other non-200 must be distinguishable. */
    public function test_a_failed_read_can_be_made_to_throw(): void
    {
        $c = $this->code('Firestore_rest_client.php');

        $i = strpos($c, 'private function _getDocumentUncached');
        $this->assertNotFalse($i);
        $fn = substr($c, $i, 1600);

        $this->assertStringContainsString('bool $failLoud', $fn,
            'the failure branch cannot be distinguished without an opt-in flag');

        $t = strpos($fn, 'throw new \RuntimeException');
        $this->assertNotFalse($t, 'a failed read still returns null unconditionally');

        // The throw must sit inside the non-200 branch, before its return.
        $branch = strpos($fn, "if (\$r['code'] !== 200)");
        $this->assertNotFalse($branch, 'the non-200 branch is gone');
        $this->assertGreaterThan($branch, $t,
            'the throw must be inside the failure branch, not before it');
    }

    /** Default behaviour must be unchanged for the many existing callers. */
    public function test_the_plain_getter_still_returns_null_on_failure(): void
    {
        $c = $this->code('Firestore_rest_client.php');

        $this->assertMatchesRegularExpression(
            '~_getDocumentUncached\(string \$collection, string \$docId, bool \$failLoud = false\)~', $c,
            'failLoud must default to false; making every caller throw would be a '
            . 'far larger change than the defect being fixed');
    }

    public function test_a_fail_loud_getter_exists_at_both_layers(): void
    {
        $rest = $this->code('Firestore_rest_client.php');
        $fb   = $this->code('Firebase.php');

        $this->assertMatchesRegularExpression(
            '~public function getDocumentOrFail\(.*?\)\s*:\s*\?array\s*\{\s*return \$this->_getDocumentUncached\([^;]*,\s*true\s*\);~s',
            $rest,
            'getDocumentOrFail must delegate with failLoud = true');

        $this->assertStringContainsString('public function firestoreGetOrFail(', $fb);

        // Bound the search to THIS method. A lazy .*? across the whole file
        // happily runs on into firestoreQueryOrFail's null-client throw and
        // reports a guard that is no longer here.
        $i = strpos($fb, 'public function firestoreGetOrFail(');
        $this->assertNotFalse($i);
        $end  = strpos($fb, "\n    public function ", $i + 10);
        $body = $end === false ? substr($fb, $i) : substr($fb, $i, $end - $i);

        $this->assertStringContainsString('firestoreDb === null', $body,
            'firestoreGetOrFail must still test for an uninitialised client');
        $this->assertStringContainsString('throw new \RuntimeException', $body,
            'an uninitialised client is a failed read too, not an absent document');
    }

    /**
     * The fail-loud path must bypass the read cache. A cached null would let one
     * failure keep answering "absent" for the rest of the request.
     */
    public function test_the_fail_loud_read_bypasses_the_read_cache(): void
    {
        $rest = $this->code('Firestore_rest_client.php');

        $i = strpos($rest, 'public function getDocumentOrFail');
        $this->assertNotFalse($i);
        $fn = substr($rest, $i, 400);

        $this->assertStringContainsString('_getDocumentUncached', $fn,
            'getDocumentOrFail must go to the uncached path');
        $this->assertStringNotContainsString('_readCache', $fn,
            'a failed read must never be cached, nor served from cache');
    }

    // -- the deciders ------------------------------------------------------

    public function test_every_create_vs_update_decider_reads_fail_loud(): void
    {
        foreach (self::DECIDERS as $f) {
            $code = $this->code($f);

            $this->assertStringContainsString('firestoreGetOrFail', $code,
                "{$f}: still decides create-vs-update from a null that could mean "
                . 'either "absent" or "the read failed"');
        }
    }

    public function test_no_decider_swallows_its_read(): void
    {
        foreach (self::DECIDERS as $f) {
            $code = $this->code($f);

            $i = strpos($code, 'firestoreGetOrFail');
            $this->assertNotFalse($i, "{$f}: no fail-loud read");
            $after = substr($code, $i, 900);

            $this->assertDoesNotMatchRegularExpression(
                '~catch\s*\([^)]*\)\s*\{\s*\}~', $after,
                "{$f}: the fail-loud read sits in an empty catch, restoring exactly "
                . 'the silent behaviour it was meant to remove');

            $this->assertStringContainsString('throw new Service_exception', $after,
                "{$f}: a save whose prior state is unknown must refuse, not guess "
                . 'that the record is new');
        }
    }

    /** The premise: these services really do brand the audit from that read. */
    public function test_the_audit_action_really_is_chosen_from_that_read(): void
    {
        $hits = 0;
        foreach (self::DECIDERS as $f) {
            $code = $this->code($f);
            if (preg_match("~_audit\(\s*\n?\s*is_array\(\\\$existing\) \? 'update' : 'create'~", $code)
                || preg_match("~is_array\(\\\$existing\) \? 'update' : 'create'~", $code)
                || preg_match("~is_array\(\\\$current\) \? 'update' : 'create'~", $code)) {
                $hits++;
            }
        }

        $this->assertGreaterThan(0, $hits,
            'if no service brands its audit entry from this read any more, the '
            . 'audit-corruption argument above should be revisited');
    }
}
