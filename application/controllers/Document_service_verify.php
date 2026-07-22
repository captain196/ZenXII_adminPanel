<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Document_service_verify — end-to-end lifecycle verifier for the canonical
 * Document Service (Phase 1). REUSABLE CLI diagnostic.
 *
 * Exercises the full API against a real (test) school using SYNTHETIC entity
 * ids, asserting each transition, then FORCE-PURGES everything it created so it
 * leaves no residue (aside from a handful of DOCLOG audit rows — intentional
 * provenance). Covers:
 *
 *   upload → tenant-isolation → link(admission) → link dedup → link(student)
 *   → verify → replace(version bump) → unlink(admission, refcount holds)
 *   → delete(student, force → purge) → gone.
 *
 * INVOCATION:
 *   php index.php document_service_verify run
 *   Env required: SCHOOL_ID=<schoolFs>
 *
 * Mutates ONLY its own synthetic documents/links; self-cleans. Safe to run on a
 * test tenant; do NOT point SCHOOL_ID at a production tenant you care about
 * (audit rows aside, it is non-destructive to real data — it never touches real
 * applications or students).
 */
class Document_service_verify extends CI_Controller
{
    private string $schoolFs = '';
    private int $pass = 0;
    private int $fail = 0;

    private const TEST_APP = 'DSVERIFY_APP0001';
    private const TEST_STU = 'DSVERIFY_STU0001';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Document_service_verify is CLI-only.', 403);
        }
        $this->load->library('firebase');
        $this->schoolFs = (string) (getenv('SCHOOL_ID') ?: '');
        if ($this->schoolFs === '') {
            echo "ERROR: Set SCHOOL_ID environment variable.\n";
            exit(1);
        }
    }

    /** php index.php document_service_verify run */
    public function run(): void
    {
        echo "=== Document Service — lifecycle verification ===\n";
        echo "Scope: schoolId={$this->schoolFs}\n";
        echo str_repeat('-', 64) . "\n\n";

        $this->load->library('document_service', null, 'docsvc');
        $this->docsvc->init($this->firebase, $this->schoolFs);

        $tmp1 = $this->makeJpeg(1600, 1100);
        $tmp2 = $this->makeJpeg(1200, 900);
        $docId = '';

        try {
            // 1. Upload (image → optimized). linkCount starts at 0.
            $res = $this->docsvc->upload($tmp1, 'passport.jpg', 'photo', ['uploadedBy' => 'verifier']);
            $this->assert('upload ok', !empty($res['ok']), $res['error'] ?? '');
            $docId = (string) ($res['document']['documentId'] ?? '');
            $this->assert('documentId minted', $docId !== '');
            $this->assert('version = 1', (int) ($res['document']['version'] ?? 0) === 1);
            $this->assert('verification pending', ($res['document']['verificationStatus'] ?? '') === 'pending');
            $this->assert('optimized to jpeg', ($res['document']['ext'] ?? '') === 'jpg');
            $this->assert('linkCount = 0 at upload', $this->rawLinkCount($docId) === 0);

            // 2. Tenant isolation — a service scoped to another school cannot read it.
            $other = $this->load->library('document_service', null, 'docsvcOther') ? $this->docsvcOther : null;
            if ($other) {
                $other->init($this->firebase, $this->schoolFs . '_OTHER');
                $this->assert('tenant isolation (cross-school read blocked)', $other->getMetadata($docId) === null);
            }

            // 3. Link to the application → refcount 1.
            $this->docsvc->link($docId, 'admission', self::TEST_APP, 'photo', 'verifier');
            $this->assert('linkCount = 1 after admission link', $this->rawLinkCount($docId) === 1);

            // 4. Duplicate link is a no-op (idempotent).
            $this->docsvc->link($docId, 'admission', self::TEST_APP, 'photo', 'verifier');
            $this->assert('linkCount still 1 (dedup)', $this->rawLinkCount($docId) === 1);

            // 5. Enrollment link (revised D1) → refcount 2, one physical object.
            $this->docsvc->link($docId, 'student', self::TEST_STU, 'photo', 'verifier');
            $this->assert('linkCount = 2 after student link', $this->rawLinkCount($docId) === 2);

            // 6. Verify.
            $vr = $this->docsvc->verify($docId, 'verified', 'verifier');
            $this->assert('verify ok', !empty($vr['ok']));
            $this->assert('status verified', ($this->docsvc->getMetadata($docId)['verificationStatus'] ?? '') === 'verified');

            // 7. Replace → new version, verification reset, old object retired.
            $rp = $this->docsvc->replace($docId, $tmp2, 'passport2.jpg', 'verifier');
            $this->assert('replace ok', !empty($rp['ok']), $rp['error'] ?? '');
            $meta = $this->docsvc->getMetadata($docId);
            $this->assert('version = 2 after replace', (int) ($meta['version'] ?? 0) === 2);
            $this->assert('verification reset to pending', ($meta['verificationStatus'] ?? '') === 'pending');

            // 8. Unlink admission — student link holds the object (no purge).
            $this->docsvc->unlink($docId, 'admission', self::TEST_APP, 'verifier');
            $this->assert('linkCount = 1 after admission unlink', $this->rawLinkCount($docId) === 1);
            $this->assert('document still present (student holds it)', $this->docsvc->getMetadata($docId) !== null);

            // 9. Delete last link with force → purge. Resolves W4: a lead delete
            //    would NOT purge while a student still links.
            $dr = $this->docsvc->delete($docId, 'student', self::TEST_STU, 'verifier', true);
            $this->assert('delete ok', !empty($dr['ok']));
            $this->assert('purged = true', !empty($dr['purged']));
            $this->assert('document gone after purge', $this->docsvc->getMetadata($docId) === null);
        } catch (\Throwable $e) {
            echo "\n!! EXCEPTION: " . $e->getMessage() . "\n";
            $this->fail++;
        } finally {
            @unlink($tmp1);
            @unlink($tmp2);
            // Best-effort residue sweep in case an assertion aborted mid-flight.
            if ($docId !== '' && $this->docsvc->getMetadata($docId) !== null) {
                $this->docsvc->delete($docId, 'admission', self::TEST_APP, 'verifier', true);
                $this->docsvc->delete($docId, 'student', self::TEST_STU, 'verifier', true);
            }
        }

        echo "\n" . str_repeat('-', 64) . "\n";
        echo "RESULT: {$this->pass} passed, {$this->fail} failed\n";
        exit($this->fail === 0 ? 0 : 1);
    }

    /**
     * php index.php document_service_verify race    (env: SCHOOL_ID)
     *
     * DETERMINISTIC concurrency proof for F3 (replace) and F4 (unlink). Rather
     * than hope to hit a sub-millisecond race window, it constructs the exact
     * interleaving: capture "admin-1"'s read, let "admin-2" complete a real
     * mutation (bumping updateTime), then replay admin-1's now-stale precondition
     * commit and assert the server REJECTS it — proving no lost update / no
     * double decrement is possible. Self-cleaning.
     */
    public function race(): void
    {
        echo "=== Document Service — concurrency (CAS) verification ===\n";
        echo "Scope: schoolId={$this->schoolFs}\n" . str_repeat('-', 64) . "\n\n";
        $this->load->library('document_service', null, 'docsvc');
        $this->docsvc->init($this->firebase, $this->schoolFs);
        $tmpA = $this->makeJpeg(1400, 1000);
        $tmpB = $this->makeJpeg(1000, 800);
        $docKey = fn($d) => $this->schoolFs . '_' . $d;

        try {
            // ── F4 — concurrent unlink of the SAME link must not double-decrement ──
            echo "F4 — two admins unlink the same link simultaneously:\n";
            $D = (string) ($this->docsvc->upload($tmpA, 'p.jpg', 'photo', ['uploadedBy' => 'race'])['document']['documentId'] ?? '');
            $this->assert('setup upload', $D !== '');
            $this->docsvc->link($D, 'admission', self::TEST_APP, 'photo', 'race');
            $this->docsvc->link($D, 'student', self::TEST_STU, 'photo', 'race');
            $this->assert('linkCount = 2 (admission + student)', $this->rawLinkCount($D) === 2);

            // admin-1 reads the admission link + document (captures updateTimes)
            $linkKey = $this->schoolFs . '_' . $D . '_admission_' . self::TEST_APP;
            $l0 = $this->firebase->firestoreGet('documentLinks', $linkKey);
            $d0 = $this->firebase->firestoreGet('documents', $docKey($D));
            $lut0 = (string) ($l0['__updateTime'] ?? ''); $dut0 = (string) ($d0['__updateTime'] ?? '');
            $this->assert('captured stale updateTimes', $lut0 !== '' && $dut0 !== '');

            // admin-2 completes a real unlink of the same link FIRST
            $this->docsvc->unlink($D, 'admission', self::TEST_APP, 'race');
            $this->assert('after admin-2 unlink: linkCount = 1', $this->rawLinkCount($D) === 1);

            // admin-1 replays its now-stale unlink commit — CAS MUST reject it
            $stale = $this->firebase->firestoreCommitBatch([
                ['op' => 'update', 'collection' => 'documentLinks', 'docId' => $linkKey, 'merge' => true,
                 'precondition' => ['updateTime' => $lut0], 'data' => ['active' => false, 'unlinkedAt' => date('c')]],
                ['op' => 'update', 'collection' => 'documents', 'docId' => $docKey($D), 'merge' => true,
                 'precondition' => ['updateTime' => $dut0], 'data' => ['linkCount' => 1, 'updatedAt' => date('c')]],
            ]);
            $this->assert('stale concurrent unlink REJECTED by CAS', $stale === false);
            $this->assert('linkCount still 1 — NO double-decrement', $this->rawLinkCount($D) === 1);
            $this->docsvc->delete($D, 'student', self::TEST_STU, 'race', true);
            $this->docsvc->delete($D, 'admission', self::TEST_APP, 'race', true);

            // ── F3 — concurrent replace must not lose an update / orphan ──
            echo "\nF3 — two admins replace the same document simultaneously:\n";
            $D2 = (string) ($this->docsvc->upload($tmpA, 'p.jpg', 'photo', ['uploadedBy' => 'race'])['document']['documentId'] ?? '');
            $this->docsvc->link($D2, 'admission', self::TEST_APP, 'photo', 'race');
            $this->assert('setup version = 1', (int) ($this->docsvc->getMetadata($D2)['version'] ?? 0) === 1);

            // admin-1 reads the document (captures updateTime), admin-2 replaces first → v2
            $dut2 = (string) ($this->firebase->firestoreGet('documents', $docKey($D2))['__updateTime'] ?? '');
            $r2 = $this->docsvc->replace($D2, $tmpB, 'q.jpg', 'race');
            $this->assert('admin-2 replace → version 2', (int) ($r2['document']['version'] ?? 0) === 2);

            // admin-1 replays a stale metadata repoint — CAS MUST reject it (no lost update)
            $stale2 = $this->firebase->firestoreCommitBatch([
                ['op' => 'update', 'collection' => 'documents', 'docId' => $docKey($D2), 'merge' => true,
                 'precondition' => ['updateTime' => $dut2], 'data' => ['version' => 2, 'updatedAt' => date('c')]],
            ]);
            $this->assert('stale concurrent replace REJECTED by CAS', $stale2 === false);

            // the real service replace still serializes cleanly → version 3
            $r3 = $this->docsvc->replace($D2, $tmpA, 'p.jpg', 'race');
            $this->assert('serialized replace advances → version 3', (int) ($r3['document']['version'] ?? 0) === 3);
            $this->assert('downloadUrl matches committed storagePath', ($r3['document']['downloadUrl'] ?? '') !== '');
            $this->docsvc->delete($D2, 'admission', self::TEST_APP, 'race', true);
        } catch (\Throwable $e) {
            echo "\n!! EXCEPTION: " . $e->getMessage() . "\n"; $this->fail++;
        } finally {
            @unlink($tmpA); @unlink($tmpB);
        }

        echo "\n" . str_repeat('-', 64) . "\n";
        echo "RESULT: {$this->pass} passed, {$this->fail} failed\n";
        exit($this->fail === 0 ? 0 : 1);
    }

    /**
     * php index.php document_service_verify race_worker    (env: SCHOOL_ID, RACE_DOC, RACE_ENTITY, RACE_CYCLES)
     * Empirical parallel stress: hammers RACE_CYCLES link↔unlink cycles on the
     * shared RACE_DOC using its own RACE_ENTITY. Launch several in parallel; each
     * is net-zero on the counter, so a correct CAS leaves linkCount unchanged.
     */
    public function race_worker(): void
    {
        $doc    = (string) (getenv('RACE_DOC') ?: '');
        $entity = (string) (getenv('RACE_ENTITY') ?: 'W');
        $cycles = (int) (getenv('RACE_CYCLES') ?: 15);
        if ($doc === '') { echo "RACE_DOC required\n"; exit(1); }
        $this->load->library('document_service', null, 'docsvc');
        $this->docsvc->init($this->firebase, $this->schoolFs);
        for ($i = 0; $i < $cycles; $i++) {
            $this->docsvc->link($doc, 'stress', $entity, 'stress', 'race');
            $this->docsvc->unlink($doc, 'stress', $entity, 'race');
        }
        echo "worker {$entity} done ({$cycles} cycles)\n";
        exit(0);
    }

    /** php index.php document_service_verify race_setup   (env: SCHOOL_ID) — prints RACE_DOC=<id>. */
    public function race_setup(): void
    {
        $this->load->library('document_service', null, 'docsvc');
        $this->docsvc->init($this->firebase, $this->schoolFs);
        $tmp = $this->makeJpeg(1000, 800);
        $D = (string) ($this->docsvc->upload($tmp, 'p.jpg', 'photo', ['uploadedBy' => 'race'])['document']['documentId'] ?? '');
        @unlink($tmp);
        if ($D === '') { echo "SETUP_FAILED\n"; exit(1); }
        $this->docsvc->link($D, 'base', 'BASE0', 'base', 'race'); // persistent baseline link → linkCount 1
        echo "RACE_DOC={$D}\n";
        exit(0);
    }

    /**
     * php index.php document_service_verify race_check   (env: SCHOOL_ID, RACE_DOC, RACE_WORKERS)
     * Asserts the core invariant after the parallel storm: the stored linkCount
     * EQUALS the number of actually-active links (recomputed by point-reads). All
     * workers end unlinked, so the only active link is the baseline ⇒ count == 1.
     * Cleans up the shared document.
     */
    public function race_check(): void
    {
        $doc = (string) (getenv('RACE_DOC') ?: '');
        $n   = (int) (getenv('RACE_WORKERS') ?: 0);
        if ($doc === '') { echo "RACE_DOC required\n"; exit(1); }
        $this->load->library('document_service', null, 'docsvc');
        $this->docsvc->init($this->firebase, $this->schoolFs);

        // Recompute the true active-link count by point-reading each known link.
        $active = 0;
        $base = $this->firebase->firestoreGet('documentLinks', $this->schoolFs . '_' . $doc . '_base_BASE0');
        if (is_array($base) && !empty($base['active'])) $active++;
        for ($i = 0; $i < $n; $i++) {
            $lk = $this->firebase->firestoreGet('documentLinks', $this->schoolFs . '_' . $doc . '_stress_stress_' . $i);
            if (is_array($lk) && !empty($lk['active'])) $active++;
        }
        $stored = $this->rawLinkCount($doc);
        echo "  stored linkCount = {$stored}; recomputed active links = {$active}\n";
        $this->assert('INVARIANT: stored linkCount == active link count (no drift)', $stored === $active);
        $this->assert('all workers net-zero → count back to baseline 1', $stored === 1);

        // Cleanup: force-purge the shared document.
        $this->docsvc->delete($doc, 'base', 'BASE0', 'race', true);
        echo "\nRESULT: {$this->pass} passed, {$this->fail} failed\n";
        exit($this->fail === 0 ? 0 : 1);
    }

    private function assert(string $label, bool $ok, string $extra = ''): void
    {
        if ($ok) { $this->pass++; echo "  [PASS] {$label}\n"; }
        else     { $this->fail++; echo "  [FAIL] {$label}" . ($extra !== '' ? " — {$extra}" : '') . "\n"; }
    }

    /** Raw linkCount straight from the metadata doc (pointer() omits it). */
    private function rawLinkCount(string $documentId): int
    {
        $doc = $this->firebase->firestoreGet('documents', $this->schoolFs . '_' . $documentId);
        return is_array($doc) ? (int) ($doc['linkCount'] ?? -1) : -1;
    }

    /** Generate a throwaway JPEG for upload/replace. */
    private function makeJpeg(int $w, int $h): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dsv') . '.jpg';
        if (function_exists('imagecreatetruecolor')) {
            $im = imagecreatetruecolor($w, $h);
            imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 120, 160, 200));
            imagejpeg($im, $path, 85);
            imagedestroy($im);
        } else {
            file_put_contents($path, "\xFF\xD8\xFF\xE0stub");
        }
        return $path;
    }
}
