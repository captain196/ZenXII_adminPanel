<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Staff_pii_migrate — one-shot PII splitter for audit finding C1 (durable half).
 *
 * The staff Firestore doc (`staff/{schoolId}_{staffId}`) is same-school readable
 * (parents + students included, see firestore.rules `/staff`). It historically
 * stored a sensitive PII cluster inline:
 *
 *     panNumber, aadharNumber, pfNumber, esiNumber, salaryDetails, bankDetails
 *
 * The live WRITE paths are already fixed (Staff.php routes this cluster to the
 * server-only `staffPrivate/{schoolId}_{staffId}` mirror and nulls it on the
 * readable doc). This CLI does the same for EXISTING docs across every tenant:
 * copy the cluster to staffPrivate (same doc id), then null it on staff.
 *
 * The staffPrivate collection is denied to ALL clients by firestore.rules; only
 * the service account (this CLI + the panel) can read/write it. HR payslip /
 * payroll and the edit form merge the mirror back on read, so nothing that
 * displays salary/bank/PAN is affected.
 *
 * Idempotent (a doc whose cluster is already null/absent is skipped), dry-run by
 * default, writes a rollback log (before-values) on commit.
 *
 *   php index.php staff_pii_migrate run              # dry-run, all tenants
 *   php index.php staff_pii_migrate run commit       # write
 *
 * Rollback: each committed change appends a JSONL line {docId, before:{...}} to
 * FCPATH/staff_pii_migrate_rollback_<ts>.jsonl. To undo, re-`set` each field
 * back onto `staff/{docId}` and remove `staffPrivate/{docId}`.
 */
class Staff_pii_migrate extends CI_Controller
{
    /** Sensitive cluster — MUST match Staff.php / Hr.php PII_KEYS. */
    private const PII_KEYS = ['panNumber', 'aadharNumber', 'pfNumber', 'esiNumber', 'salaryDetails', 'bankDetails'];

    private $commit = false;
    private $stats  = ['scanned' => 0, 'migrated' => 0, 'clean' => 0, 'failed' => 0];
    private $changeLog = [];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) { show_error('CLI-only.', 403); exit(1); }
        $this->load->library('firebase');
        $this->load->library('firestore_service', null, 'fs');
    }

    public function run($mode = 'dry-run')
    {
        if ($mode === 'commit') $this->commit = true;

        $this->_out('');
        $this->_out('== Staff PII split — staff → staffPrivate (audit C1) ==');
        $this->_out('mode : ' . ($this->commit ? 'COMMIT (writing)' : 'DRY-RUN (no writes)'));
        $this->_out('keys : ' . implode(', ', self::PII_KEYS));
        $this->_out('');

        $rollback = null;
        if ($this->commit) {
            $path = FCPATH . 'staff_pii_migrate_rollback_' . date('Ymd_His') . '.jsonl';
            $rollback = @fopen($path, 'w');
            $this->_out('rollback log: ' . $path . "\n");
        }

        // Cross-tenant sweep — every staff doc, all schools.
        try { $rows = $this->fs->where('staff', []); }
        catch (\Throwable $e) { $this->_out('list staff failed: ' . $e->getMessage()); if ($rollback) fclose($rollback); return; }

        foreach ($rows as $row) {
            $docId = (string) ($row['id'] ?? '');
            $data  = is_array($row['data'] ?? null) ? $row['data'] : [];
            if ($docId === '') continue;
            $this->stats['scanned']++;

            // Extract the cluster values still present-and-non-null on the staff doc.
            $moving = [];
            foreach (self::PII_KEYS as $k) {
                if (array_key_exists($k, $data) && $data[$k] !== null) {
                    $moving[$k] = $data[$k];
                }
            }
            if (empty($moving)) { $this->stats['clean']++; continue; }

            $this->stats['migrated']++;
            $this->changeLog[] = "MOVE {$docId}  [" . implode(',', array_keys($moving)) . ']';

            if (!$this->commit) continue;

            // Rollback capture (before-values).
            if ($rollback) {
                fwrite($rollback, json_encode(['docId' => $docId, 'before' => $moving]) . "\n");
            }

            // 1) Copy cluster to the server-only mirror (same doc id). Carry
            //    schoolId + staffId so the panel's bulk merge can key on them.
            $privateDoc = $moving;
            $privateDoc['schoolId']  = $data['schoolId'] ?? $this->_school_from_docid($docId);
            $privateDoc['staffId']   = $data['staffId'] ?? $data['userId'] ?? $this->_staff_from_docid($docId);
            $privateDoc['updatedAt'] = date('c');
            $okWrite = $this->fs->set('staffPrivate', $docId, $privateDoc, true);
            if (!$okWrite) { $this->stats['failed']++; $this->_out('  [FAILED write staffPrivate] ' . $docId); continue; }

            // 2) Null the cluster on the readable staff doc (merge update).
            $nulls = [];
            foreach (array_keys($moving) as $k) { $nulls[$k] = null; }
            $okNull = $this->fs->update('staff', $docId, $nulls);
            if (!$okNull) { $this->stats['failed']++; $this->_out('  [FAILED null staff] ' . $docId . ' (staffPrivate written — re-run safe)'); }
        }
        if ($rollback) fclose($rollback);

        $this->_out('── changes ──');
        foreach (($this->changeLog ?: ['  (none — every staff doc is already split)']) as $l) $this->_out('  ' . $l);
        $this->_out('');
        $this->_out('── summary ──');
        foreach ($this->stats as $k => $v) $this->_out(sprintf('  %-9s %d', $k, $v));
        $this->_out('');
        $this->_out($this->commit
            ? 'Done. Existing staff docs split; PII now lives on staffPrivate.'
            : "Dry-run only. Re-run with `commit`:\n  php index.php staff_pii_migrate run commit");
        $this->_out('');
    }

    /** {schoolId}_{staffId} → schoolId (SCH_XXXXXX prefix). */
    private function _school_from_docid(string $docId): string
    {
        // schoolIds look like SCH_123456; the staffId tail is STA0001 etc.
        if (preg_match('/^(SCH_[0-9A-Za-z]+)_/', $docId, $m)) return $m[1];
        $pos = strpos($docId, '_');
        return $pos === false ? '' : substr($docId, 0, $pos);
    }

    /** {schoolId}_{staffId} → staffId (tail after the school prefix). */
    private function _staff_from_docid(string $docId): string
    {
        if (preg_match('/^SCH_[0-9A-Za-z]+_(.+)$/', $docId, $m)) return $m[1];
        $pos = strrpos($docId, '_');
        return $pos === false ? $docId : substr($docId, $pos + 1);
    }

    private function _out(string $line): void { fwrite(STDOUT, $line . "\n"); }
}
