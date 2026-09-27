<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * CONC-D1 — replacing a section's assignments is not atomic.
 *
 * saveClassAssignments() does:
 *
 *     _deleteClassAssignments($class, $section);   // N individual removes
 *     foreach ($subjects as $sub) { $this->fs->set(...); }   // N individual sets
 *
 * Between the delete and the last set, the section has NO assignments — and
 * if the request dies mid-loop (timeout, fatal, dropped connection) the rows
 * are gone permanently. That is data LOSS, not merely a race: the deletes
 * already committed and nothing rewrites them.
 *
 * Two admins saving the same section concurrently interleave the same way:
 * A-delete, B-delete, A-set, B-set. Deterministic doc ids make the sets
 * idempotent upserts, so the failure mode is missing rows rather than
 * duplicated authority — but a subject silently vanishing from a section is
 * exactly as damaging in an academic system, because attendance and marks
 * authorization both resolve through these rows.
 *
 * The repository already has the primitive: Firestore_service::commitBatch()
 * maps to the REST :commit endpoint and is used by Hr.php. Note that
 * batchRemove() is NOT atomic — it is a loop of single removes — so it is not
 * a substitute.
 *
 * The fix collects the deletes and the sets into ONE ops array and commits
 * them together, falling back to the previous per-document path when the
 * client cannot batch.
 */
final class SubjectAssignmentAtomicSaveTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $p = __DIR__ . '/../../application/libraries/Subject_assignment_service.php';
        $this->assertFileExists($p);
        $this->src = (string) file_get_contents($p);
    }

    /** DEFECT CONC-D1 — the replace must go through a single batch commit. */
    public function test_the_save_commits_deletes_and_writes_atomically(): void
    {
        $this->assertStringContainsString('commitBatch', $this->src,
            "saveClassAssignments() still deletes then writes document-by-document.\n"
            . "A failure between the two leaves the section with its assignments\n"
            . "deleted and not rewritten — permanent loss of the rows that\n"
            . "attendance and marks authorization resolve through.");
    }

    /** The ops must carry the shape commitBatch actually consumes. */
    public function test_the_batch_ops_use_the_documented_contract(): void
    {
        $this->assertMatchesRegularExpression("~'op'\s*=>\s*'delete'~", $this->src,
            'deletes must be part of the batch, not issued separately');
        $this->assertMatchesRegularExpression("~'op'\s*=>\s*'set'~", $this->src,
            'writes must be part of the batch');

        foreach (["'collection'", "'docId'", "'data'"] as $key) {
            $this->assertStringContainsString($key . ' =>', $this->src,
                "commitBatch ops require the {$key} key");
        }
    }

    /**
     * batchRemove() is a LOOP, not a transaction. Using it here would look
     * like a fix while changing nothing.
     */
    public function test_it_does_not_mistake_batchremove_for_atomicity(): void
    {
        // Strip comments — the file explains WHY it avoids batchRemove(), and
        // that prose must not be mistaken for a call.
        $code = (string) preg_replace('~/\*.*?\*/~s', '', $this->src);
        $code = (string) preg_replace('~^\s*//.*$~m', '', $code);

        $this->assertStringNotContainsString('batchRemove(', $code,
            'batchRemove() iterates single removes — it provides no atomicity');
    }

    /**
     * A client that cannot batch must still save. Losing the fallback would
     * break assignment saving outright rather than merely leaving it
     * non-atomic.
     */
    public function test_a_non_batching_client_still_falls_back(): void
    {
        $this->assertMatchesRegularExpression(
            "~method_exists\([^,]+,\s*'commitBatch'\)~", $this->src,
            'the batch path must be guarded so a client without commitBatch still works');

        $this->assertStringContainsString('_deleteClassAssignments', $this->src,
            'the per-document fallback path must be retained');
    }

    /** The delete set must still be scoped to this class/section/session only. */
    public function test_the_batch_deletes_stay_scoped(): void
    {
        $i = strpos($this->src, 'private function _collectExistingDocIds');
        if ($i === false) {
            $i = strpos($this->src, 'private function _deleteClassAssignments');
        }
        $this->assertNotFalse($i, 'the scoped-delete helper is gone');
        $fn = substr($this->src, $i, 1400);

        foreach (["'session'", "'className'", "'section'"] as $pred) {
            $this->assertStringContainsString($pred, $fn,
                "the delete set must remain filtered by {$pred}");
        }
    }
}
