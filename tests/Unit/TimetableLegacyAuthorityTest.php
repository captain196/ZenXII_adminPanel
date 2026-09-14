<?php
/**
 * WHO IS ALLOWED TO WRITE THE COMPATIBILITY COLLECTION.
 *
 * `timetables` is no longer canonical. The authority is
 * timetableVersions/timetableVersionDays, and `timetables` is a projection of
 * whichever version is published.
 *
 * An existing guard proves the canonical LAYER only touches it to project. That
 * guard reads one file. This one looks across the whole application, because a
 * second authority does not have to live in the canonical layer to do damage —
 * the one that existed was a CLI repair tool nobody had re-examined since the
 * architecture changed.
 */

use PHPUnit\Framework\TestCase;

final class TimetableLegacyAuthorityTest extends TestCase
{
    private function appFiles(): array
    {
        $root = realpath(__DIR__ . '/../../application');
        $out  = [];
        $it   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') { continue; }
            $path = str_replace('\\', '/', $file->getPathname());
            if (strpos($path, '/cache/') !== false) { continue; }
            $out[$path] = (string) file_get_contents($path);
        }
        return $out;
    }

    /** Source with comments stripped: a comment naming a write is not a write. */
    private function code(string $src): string
    {
        $src = (string) preg_replace('~/\*.*?\*/~s', '', $src);
        return (string) preg_replace('~^\s*//.*$~m', '', $src);
    }

    /**
     * The complete set of files that may write the compatibility collection.
     *
     * Pinned deliberately: adding a writer must be a decision somebody makes on
     * purpose, not something that arrives with a bug fix.
     */
    public function test_the_set_of_files_that_write_the_legacy_collection_is_pinned(): void
    {
        $writers = [];
        foreach ($this->appFiles() as $path => $src) {
            $code = $this->code($src);
            // Any Firestore mutation naming the legacy collection, whether by
            // string literal or through one of the class constants for it.
            if (preg_match('~firestore(Set|Update|Delete)\s*\(\s*'
                . "(['\"]timetables['\"]|self::COLLECTION_LEGACY_TT|self::COLLECTION_TT"
                . '|self::TIMETABLES|Firestore_helper::TIMETABLES)~', $code)) {
                $writers[] = basename($path);
            }
        }
        sort($writers);

        $this->assertSame(
            [
                // The projection: the one legitimate writer.
                'Timetable_plan_store.php',
                // The retired legacy generator. Its write statement still exists
                // but cannot execute — see the next test.
                'Timetable_service.php',
                // The legacy repair tool, which now skips projected documents.
                'Timetables_reconcile.php',
            ],
            $writers,
            "only these files may name a write to `timetables`. A new writer here "
            . "is a second source of truth for what teachers see."
        );
    }

    /**
     * The retired generator's write statement is unreachable.
     *
     * Presence is not authority: every method in the legacy generator that can
     * still name a write to `timetables` refuses unconditionally before
     * reaching it, so the statement below is dead code kept for readability of
     * what was retired. This test fails the moment the refusal is moved,
     * weakened or made conditional.
     */
    public function test_the_retired_generators_legacy_write_cannot_execute(): void
    {
        $code = $this->code((string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_service.php'));

        // Split into methods and examine only those that write the collection.
        $parts = preg_split('~\n    (?=(?:public|private|protected) function )~', $code);
        $checked = 0;

        foreach ($parts as $part) {
            $writeAt = strpos($part, 'firestoreSet(self::COLLECTION_TT');
            if ($writeAt === false) { continue; }
            $checked++;

            preg_match('~function (\w+)~', $part, $nm);
            $name = $nm[1] ?? '(unknown)';

            $refuseAt = strpos($part, '_refuseLegacyWrite(');
            $this->assertNotFalse($refuseAt,
                "{$name}() writes the legacy collection with no refusal at all");
            $this->assertLessThan($writeAt, $refuseAt,
                "{$name}() must refuse BEFORE it writes; after the write the "
                . 'refusal is decoration');
        }

        $this->assertGreaterThan(0, $checked,
            'premise: the retired generator still contains a legacy write to guard');
    }

    /** And the refusal must be unconditional, not behind a flag or a branch. */
    public function test_the_legacy_refusal_is_unconditional(): void
    {
        $code = $this->code((string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_service.php'));

        preg_match('~private function _refuseLegacyWrite\(.*?\n    \}~s', $code, $m);
        $this->assertNotEmpty($m, 'the refusal helper must exist');

        $body   = $m[0];
        $throwAt = strpos($body, 'throw ');
        $this->assertNotFalse($throwAt, 'the refusal must throw');

        // Logging before the throw is fine. A BRANCH is not: anything that can
        // decide not to throw is a way for the retired writer to become
        // authoritative again.
        $before = substr($body, 0, $throwAt);
        foreach (['if (', 'if(', 'switch', 'return', '?:', '??'] as $branch) {
            $this->assertStringNotContainsString($branch, $before,
                "the refusal must be unconditional; '{$branch}' in front of the "
                . 'throw means some caller can still reach the legacy write');
        }
    }

    /**
     * The legacy repair tool must never touch a document the projection owns.
     *
     * Repairing a projected document changes what teachers see with no version
     * recording that it happened, bypasses validation and publishing entirely,
     * and is erased by the next projection run — so the drift it "fixed"
     * returns while the compatibility collection has meanwhile disagreed with
     * the canonical version it claims to copy.
     */
    public function test_the_legacy_repair_tool_skips_projected_documents(): void
    {
        $code = $this->code((string) file_get_contents(
            __DIR__ . '/../../application/controllers/Timetables_reconcile.php'));

        $guard = strpos($code, "projectedFromVersion");
        $stage = strpos($code, '$docUpdates[$docId]');

        $this->assertNotFalse($guard, 'the projection-ownership guard must exist');
        $this->assertNotFalse($stage, 'premise: the tool stages document updates');
        $this->assertLessThan($stage, $guard,
            'the guard must run BEFORE a projected document can be staged for '
            . 'writing — after it, the document is already scheduled to be '
            . 'overwritten');

        // And it must skip, not merely notice.
        $this->assertMatchesRegularExpression(
            '~projectedFromVersion.*?\n.*?\n.*?continue;~s', $code,
            'noticing a projected document is not enough; it must be skipped');
    }

    /** A repair that was refused must be reported, never silently dropped. */
    public function test_a_skipped_document_is_reported_to_the_operator(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Timetables_reconcile.php');

        $this->assertStringContainsString('were NOT touched', $src,
            'the operator must be told the tool declined to repair something');
        $this->assertStringContainsString('publish a new version', $src,
            'and told what the canonical repair actually is');
    }

    /**
     * The tool cannot run from the web, and cannot write without being asked
     * twice. Unchanged by this work, pinned because it is what makes a
     * legacy writer tolerable at all.
     */
    public function test_the_legacy_repair_tool_stays_cli_only_and_dry_run_by_default(): void
    {
        $code = $this->code((string) file_get_contents(
            __DIR__ . '/../../application/controllers/Timetables_reconcile.php'));

        $this->assertStringContainsString('is_cli_request()', $code,
            'a legacy writer must not be reachable over the web');
        $this->assertMatchesRegularExpression(
            "~apply\s*=\s*\(string\) \(getenv\('APPLY'\)[^;]*===\s*'1'~", $code,
            'writing must require APPLY=1, so the default run mutates nothing');
    }

    /**
     * Nothing reconstructs canonical state from the compatibility collection.
     *
     * Reading `timetables` to rebuild a version would make the projection an
     * input to its own source, and the two would drift with no way to say which
     * was right.
     */
    public function test_nothing_writes_canonical_collections_from_legacy_data(): void
    {
        foreach ($this->appFiles() as $path => $src) {
            if (strpos($path, 'Timetable_plan_store.php') !== false) { continue; }
            $code = $this->code($src);

            $writesCanonical = preg_match('~firestore(Set|Update|Delete)\s*\(\s*'
                . "['\"]timetableVersion(s|Days)['\"]~", $code);

            $this->assertSame(0, $writesCanonical,
                basename($path) . ' writes a canonical timetable collection '
                . 'directly. Only the plan store may do that, or the version '
                . 'lifecycle stops being the authority.');
        }
    }
}
