<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Staff_credential_cleanup — one-shot scrubber for audit finding C1.
 *
 * The staff Firestore doc (`staff/{schoolId}_{staffId}`) is same-school readable
 * (parents + students included, see firestore.rules `/staff`), yet historical
 * writes stored bcrypt password hashes in `Password` and `Credentials.Password`.
 * Nothing reads these back (Firebase Auth is the sole password authority), so
 * they are pure credential leak. The WRITE paths that produced them are fixed
 * (Staff.php strips them / no longer mirrors them); this CLI removes them from
 * EXISTING docs across every tenant.
 *
 * Sets the two fields to null (value gone → nothing sensitive left to read).
 * Idempotent, dry-run by default, writes a rollback log on commit.
 *
 *   php index.php staff_credential_cleanup run              # dry-run, all tenants
 *   php index.php staff_credential_cleanup run commit       # write
 *
 * NOTE: this scrubs ONLY the credential hashes. The wider PII split
 * (panNumber/aadharNumber/salaryDetails/bankDetails → server-only staffPrivate)
 * is a SEPARATE step — those fields still have live server-side readers
 * (Hr.php / teacher_profile.php / edit_staff.php) that must be migrated first.
 */
class Staff_credential_cleanup extends CI_Controller
{
    private $commit = false;
    private $stats  = ['scanned' => 0, 'scrubbed' => 0, 'clean' => 0, 'failed' => 0];
    private $log    = [];

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
        $this->_out('== Staff credential-hash scrub (audit C1) ==');
        $this->_out('mode : ' . ($this->commit ? 'COMMIT (writing)' : 'DRY-RUN (no writes)'));
        $this->_out('');

        $rollback = null;
        if ($this->commit) {
            $path = FCPATH . 'staff_credential_cleanup_rollback_' . date('Ymd_His') . '.jsonl';
            $rollback = @fopen($path, 'w');
            $this->_out('rollback log: ' . $path . "\n");
        }

        try { $rows = $this->fs->where('staff', []); }
        catch (\Throwable $e) { $this->_out('list staff failed: ' . $e->getMessage()); if ($rollback) fclose($rollback); return; }

        foreach ($rows as $row) {
            $docId = (string) ($row['id'] ?? '');
            $data  = is_array($row['data'] ?? null) ? $row['data'] : [];
            if ($docId === '') continue;
            $this->stats['scanned']++;

            $hasPw   = array_key_exists('Password', $data) && $data['Password'] !== null;
            $hasCred = array_key_exists('Credentials', $data) && $data['Credentials'] !== null;
            if (!$hasPw && !$hasCred) { $this->stats['clean']++; continue; }

            $this->stats['scrubbed']++;
            $this->log[] = "SCRUB {$docId}" . ($hasPw ? ' Password' : '') . ($hasCred ? ' Credentials' : '');

            if ($this->commit) {
                if ($rollback) {
                    fwrite($rollback, json_encode(['docId' => $docId, 'before' => [
                        'Password'    => $data['Password']    ?? null,
                        'Credentials' => $data['Credentials'] ?? null,
                    ]]) . "\n");
                }
                $ok = $this->fs->update('staff', $docId, ['Password' => null, 'Credentials' => null]);
                if (!$ok) { $this->stats['failed']++; $this->_out('  [FAILED] ' . $docId); }
            }
        }
        if ($rollback) fclose($rollback);

        $this->_out('── changes ──');
        foreach (($this->log ?: ['  (none — no staff doc carries a credential hash)']) as $l) $this->_out('  ' . $l);
        $this->_out('');
        $this->_out('── summary ──');
        foreach ($this->stats as $k => $v) $this->_out(sprintf('  %-10s %d', $k, $v));
        $this->_out('');
        $this->_out($this->commit
            ? 'Done. Existing staff docs scrubbed of credential hashes.'
            : "Dry-run only. Re-run with `commit`:\n  php index.php staff_credential_cleanup run commit");
        $this->_out('');
    }

    private function _out(string $line): void { fwrite(STDOUT, $line . "\n"); }
}
