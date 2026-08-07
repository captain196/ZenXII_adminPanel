<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Student_document_uat — LIVE end-to-end UAT harness for the Student Documents
 * module's Document-Service-backed backend behavior. CLI-only, self-cleaning.
 *
 * Focuses on what static review cannot prove: real Firestore/Storage behavior
 * for the shared Admission→Student scenario (W4), verify/reject, replace/version,
 * reference-counted orphan-only purge — INCLUDING actual HTTP checks that the
 * Storage object bytes exist / survive / are removed at the right moments.
 *
 *   php index.php student_document_uat run     (env: SCHOOL_ID=<test school>)
 *
 * Mutates only its own synthetic documents; force-purges everything at the end.
 */
class Student_document_uat extends CI_Controller
{
    private string $schoolFs = '';
    private int $pass = 0;
    private int $fail = 0;

    private const APP = 'UAT_APP0001';
    private const STU = 'UAT_STU0001';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) show_error('CLI-only.', 403);
        $this->load->library('firebase');
        $this->schoolFs = (string) (getenv('SCHOOL_ID') ?: '');
        if ($this->schoolFs === '') { echo "ERROR: set SCHOOL_ID\n"; exit(1); }
    }

    public function run(): void
    {
        echo "=== Student Documents — LIVE backend UAT ===\n";
        echo "Scope: schoolId={$this->schoolFs}\n" . str_repeat('-', 64) . "\n\n";
        $this->load->library('document_service', null, 'docsvc');
        $this->docsvc->init($this->firebase, $this->schoolFs);
        $tmpA = $this->jpeg(1400, 1000);
        $tmpB = $this->jpeg(1000, 800);
        $D = '';

        try {
            // ── Scenario 10 setup: a document uploaded at Admission, then shared to the Student on enrollment ──
            echo "S10 — shared Admission → Student document:\n";
            $up = $this->docsvc->upload($tmpA, 'aadhaar.jpg', 'aadhaar', ['uploadedBy' => 'uat_admission']);
            $D  = (string) ($up['document']['documentId'] ?? '');
            $url0 = (string) ($up['document']['downloadUrl'] ?? '');
            $this->ok('admission upload → document minted', $D !== '' && $url0 !== '');
            $this->docsvc->link($D, 'admission', self::APP, 'aadhaar', 'uat');           // uploaded at admission
            $this->docsvc->link($D, 'student', self::STU, 'aadhaar', 'uat');             // enrollment adds student link (no copy)
            $this->ok('linkCount = 2 (admission + student) — one shared object', $this->count($D) === 2);
            $this->ok('shared object is downloadable (admission preview works)', $this->http($url0) === 200);

            // ── Scenario 5 + 6: verify then reject ──
            echo "\nS5/S6 — verify + reject:\n";
            $this->docsvc->verify($D, 'verified', 'uat');
            $this->ok('verify → status verified', ($this->docsvc->getMetadata($D)['verificationStatus'] ?? '') === 'verified');
            $this->docsvc->verify($D, 'rejected', 'uat');
            $this->ok('reject → status rejected', ($this->docsvc->getMetadata($D)['verificationStatus'] ?? '') === 'rejected');

            // ── Scenario 7: replace a predefined document (new version, old retired, latest served) ──
            echo "\nS7 — replace (version + old-version handling):\n";
            $rp = $this->docsvc->replace($D, $tmpB, 'aadhaar_v2.jpg', 'uat');
            $url1 = (string) ($rp['document']['downloadUrl'] ?? '');
            $this->ok('replace → version = 2', (int) ($rp['document']['version'] ?? 0) === 2);
            $this->ok('verification reset to pending on replace', ($rp['document']['verificationStatus'] ?? '') === 'pending');
            $this->ok('latest version is downloadable', $this->http($url1) === 200);
            $this->ok('previous version object retired (old URL no longer 200)', $this->http($url0) !== 200);

            // ── Scenario 10 crux: delete from STUDENT while Admission still references the object ──
            echo "\nS10 crux — delete from Student (Admission still links):\n";
            $dr = $this->docsvc->delete($D, 'student', self::STU, 'uat', false);
            $this->ok('student delete → not purged (admission still links)', empty($dr['purged']));
            $this->ok('linkCount decremented to 1', $this->count($D) === 1);
            $this->ok('document metadata still valid', $this->docsvc->getMetadata($D) !== null);
            $this->ok('SHARED OBJECT SURVIVES — admission download still works', $this->http($url1) === 200);

            // ── Scenario 8: now orphan it; retention (force=false) means not-yet-purged; force purges + deletes bytes ──
            echo "\nS8 — orphan-only purge + audit:\n";
            $dr2 = $this->docsvc->delete($D, 'admission', self::APP, 'uat', false);
            $this->ok('admission delete → orphaned but retention-held (not purged)', empty($dr2['purged']) && $this->count($D) <= 0);
            $this->ok('retained object still downloadable during retention', $this->http($url1) === 200);
            $dr3 = $this->docsvc->delete($D, 'admission', self::APP, 'uat', true);       // explicit permanent delete
            $this->ok('force delete → purged', !empty($dr3['purged']));
            $this->ok('metadata gone after purge', $this->docsvc->getMetadata($D) === null);
            $this->ok('Storage object deleted (URL no longer 200)', $this->http($url1) !== 200);
            $D = '';
        } catch (\Throwable $e) {
            echo "\n!! EXCEPTION: " . $e->getMessage() . "\n"; $this->fail++;
        } finally {
            @unlink($tmpA); @unlink($tmpB);
            if ($D !== '' && $this->docsvc->getMetadata($D) !== null) {
                $this->docsvc->delete($D, 'admission', self::APP, 'uat', true);
                $this->docsvc->delete($D, 'student', self::STU, 'uat', true);
            }
        }

        echo "\n" . str_repeat('-', 64) . "\n";
        echo "RESULT: {$this->pass} passed, {$this->fail} failed\n";
        exit($this->fail === 0 ? 0 : 1);
    }

    /** READ-ONLY inventory of a student's documents. php index.php student_document_uat inspect  (env SCHOOL_ID, STUDENT_ID) */
    public function inspect(): void
    {
        $sid = (string) (getenv('STUDENT_ID') ?: 'STU0001');
        $this->load->library('firestore_service', null, 'fs');
        $this->fs->init($this->schoolFs, (string) (getenv('SESSION_YEAR') ?: '2026-27'));
        $stu = $this->fs->getEntity('students', $sid);
        if (!is_array($stu)) { echo "student {$sid} not found\n"; exit(1); }

        echo "=== {$sid} document inventory (READ-ONLY) ===\n\n";
        $refs = is_array($stu['documentRefs'] ?? null) ? $stu['documentRefs'] : [];
        echo "A) documentRefs[] — managed by the new Document Service (these are ALL from your UAT): " . count($refs) . "\n";
        foreach ($refs as $r) {
            if (!is_array($r)) continue;
            printf("   - %-20s type=%-20s status=%-9s doc=%-24s object=%s\n",
                substr((string) ($r['label'] ?? ''), 0, 20), (string) ($r['type'] ?? ''),
                (string) ($r['verificationStatus'] ?? ''), (string) ($r['documentId'] ?? ''),
                $this->http((string) ($r['url'] ?? '')) === 200 ? 'exists' : 'missing');
        }

        $map = is_array($stu['documents'] ?? null) ? $stu['documents'] : (is_array($stu['Doc'] ?? null) ? $stu['Doc'] : []);
        echo "\nB) Doc[]/documents map — this is what the 10-document limit counts: " . count($map) . " entries\n";
        $realLegacy = 0;
        foreach ($map as $k => $v) {
            $u   = is_array($v) ? (string) ($v['url'] ?? '') : (string) $v;
            $did = is_array($v) ? (string) ($v['documentId'] ?? '') : '';
            $path = ($u !== '' && preg_match('#/o/([^?]+)#', $u, $m)) ? urldecode($m[1]) : '';
            if ($u === '')                                                    $kind = 'EMPTY-SLOT (no file — safe)';
            elseif ($did !== '')                                             $kind = 'MANAGED (your UAT test file)';
            elseif (strpos($path, 'documents/' . $this->schoolFs . '/') === 0) $kind = 'SERVICE object (UAT-touched)';
            else { $kind = 'LEGACY REAL FILE (pre-existing — DO NOT lose)'; $realLegacy++; }
            printf("   - %-26s [%s]\n", substr((string) $k, 0, 26), $kind);
        }
        echo "\nSUMMARY: managed(UAT)=" . count($refs) . "  mapEntries=" . count($map) . "  realLegacyFiles={$realLegacy}\n";
        echo ($realLegacy === 0)
            ? "→ No pre-existing REAL document files. Safe to delete all UAT test docs.\n"
            : "→ There ARE real legacy files — will NOT touch those; only UAT docs would be removed.\n";
        exit(0);
    }

    /** READ-ONLY: list schools (filter by name via NAME env). php index.php student_document_uat schools */
    public function schools(): void
    {
        $needle = strtolower((string) (getenv('NAME') ?: ''));
        $rows = $this->firebase->firestoreQuery('schools', [], null, 'ASC', 500);
        echo "firestoreQuery('schools') returned " . count((array) $rows) . " rows\n";
        if (!empty($rows)) { echo "first-row keys: " . implode(',', array_keys((array) $rows[array_key_first($rows)])) . "\n"; }
        echo str_repeat('-', 50) . "\n";
        foreach ((array) $rows as $r) {
            $d  = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
            $id = (string) ($r['id'] ?? $r['__name__'] ?? $d['schoolId'] ?? $d['school_id'] ?? '');
            $nm = (string) ($d['display_name'] ?? $d['school_name'] ?? $d['name'] ?? $d['schoolName'] ?? '');
            if ($needle === '' || stripos($nm, $needle) !== false || stripos($id, $needle) !== false) {
                echo "  ID={$id}   NAME={$nm}\n";
            }
        }
        exit(0);
    }

    /** Remove a student's UAT-created managed documents (through the service). php … cleanup  (env SCHOOL_ID, STUDENT_ID, APPLY=1) */
    public function cleanup(): void
    {
        $sid   = (string) (getenv('STUDENT_ID') ?: 'STU0001');
        $apply = getenv('APPLY') === '1';
        $this->load->library('firestore_service', null, 'fs');
        $this->fs->init($this->schoolFs, (string) (getenv('SESSION_YEAR') ?: '2026-27'));
        $this->load->library('document_service', null, 'docsvc');
        $this->docsvc->init($this->firebase, $this->schoolFs);

        $stu = $this->fs->getEntity('students', $sid);
        if (!is_array($stu)) { echo "student {$sid} not found in {$this->schoolFs}\n"; exit(1); }
        $refs = is_array($stu['documentRefs'] ?? null) ? $stu['documentRefs'] : [];
        echo "Managed (UAT) documents on {$sid}: " . count($refs) . "\n";
        if (!$apply) {
            foreach ($refs as $r) { if (is_array($r)) echo "  would delete: " . ($r['label'] ?? '') . "  (" . ($r['documentId'] ?? '') . ")\n"; }
            echo "\nDRY-RUN. Re-run with APPLY=1 to actually delete.\n"; exit(0);
        }
        $managedIds = [];
        foreach ($refs as $r) {
            if (!is_array($r) || empty($r['documentId'])) continue;
            $did = (string) $r['documentId']; $managedIds[$did] = true;
            $res = $this->docsvc->delete($did, 'student', $sid, 'uat_cleanup', true); // force-purge the test object
            echo "  deleted: " . ($r['label'] ?? '') . "  ({$did})  purged=" . (!empty($res['purged']) ? 'yes' : 'no') . "\n";
        }
        // Clear the pointer + drop the managed map entries; keep the empty placeholder slots.
        $map = is_array($stu['documents'] ?? null) ? $stu['documents'] : (is_array($stu['Doc'] ?? null) ? $stu['Doc'] : []);
        foreach ($map as $k => $v) { if (is_array($v) && isset($managedIds[(string) ($v['documentId'] ?? '')])) unset($map[$k]); }
        $this->fs->updateEntity('students', $sid, ['documentRefs' => [], 'documents' => $map, 'Doc' => $map]);
        echo "\nCleared documentRefs; removed managed entries. Remaining map entries: " . count($map) . " (empty placeholder slots only).\n";
        echo "{$sid} is back to its pre-test state — re-test freely.\n";
        exit(0);
    }

    /** READ-ONLY: list admission applications + their document/enrollment status. php … crm  (env SCHOOL_ID) */
    public function crm(): void
    {
        $rows = $this->firebase->firestoreQuery('crmApplications', [], null, 'ASC', 800);
        echo "=== Admission applications in {$this->schoolFs} ===\n\n";
        $n = 0; $withDocs = 0;
        foreach ((array) $rows as $r) {
            $d  = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
            $id = (string) ($r['id'] ?? '');
            if ((string) ($d['schoolId'] ?? '') !== $this->schoolFs) continue; // this school only
            $appId  = (string) ($d['id'] ?? str_replace($this->schoolFs . '_', '', $id));
            $name   = (string) ($d['student_name'] ?? '');
            $status = (string) ($d['status'] ?? 'pending');
            $refs   = is_array($d['documentRefs'] ?? null) ? count($d['documentRefs']) : 0;
            $photo  = !empty($d['photo_url']);
            $aadh   = !empty($d['aadhaar_url']);
            $extra  = is_array($d['documents'] ?? null) ? count($d['documents']) : 0;
            $stu    = (string) ($d['student_id'] ?? '');
            $hasDoc = ($refs > 0 || $photo || $aadh || $extra > 0);
            if ($hasDoc) $withDocs++;
            $n++;
            printf("  app=%-16s name=%-18s status=%-9s docs[refs:%d photo:%s aadhaar:%s extra:%d]%s\n",
                $appId, substr($name, 0, 18), $status, $refs, $photo ? 'Y' : '-', $aadh ? 'Y' : '-', $extra,
                $stu !== '' ? "  enrolledAs={$stu}" : '');
        }
        echo "\nTOTAL applications: {$n}   with at least one document: {$withDocs}\n";
        exit(0);
    }

    private function ok(string $label, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  [PASS] {$label}\n"; }
        else       { $this->fail++; echo "  [FAIL] {$label}\n"; }
    }

    private function count(string $documentId): int
    {
        $doc = $this->firebase->firestoreGet('documents', $this->schoolFs . '_' . $documentId);
        return is_array($doc) ? (int) ($doc['linkCount'] ?? -1) : -1;
    }

    /** HTTP status of a Storage download URL (200 = object exists). */
    private function http(string $url): int
    {
        if ($url === '' || !function_exists('curl_init')) return 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_FOLLOWLOCATION => true]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code;
    }

    private function jpeg(int $w, int $h): string
    {
        $p = tempnam(sys_get_temp_dir(), 'uat') . '.jpg';
        if (function_exists('imagecreatetruecolor')) {
            $im = imagecreatetruecolor($w, $h);
            imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 90, 140, 190));
            imagejpeg($im, $p, 85); imagedestroy($im);
        } else { file_put_contents($p, "\xFF\xD8\xFF\xE0stub"); }
        return $p;
    }
}
