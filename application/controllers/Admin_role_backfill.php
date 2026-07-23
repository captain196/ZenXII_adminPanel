<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Admin_role_backfill — brings each tenant's schools.staffRoles[ROLE_ADMIN] up to
 * the FULL RBAC_MODULES set (permissions + permissionLevels all 'manage').
 *
 * WHY: the Admin -> staff demotion removes 'Admin' from RBAC_BYPASS_ROLES, after
 * which an Admin's access resolves from ROLE_ADMIN in the catalogue instead of the
 * bypass short-circuit. Existing tenants seeded ROLE_ADMIN with an older 18-module
 * list (missing Red Flags + the Operations children), so without this backfill a
 * demoted Admin would silently lose those modules. This is STEP 1 of the demotion
 * sequence — it MUST run (and verify) BEFORE 'Admin' is removed from the bypass
 * list. It is otherwise inert (only widens ROLE_ADMIN, never restricts).
 *
 * SAFETY: DRY-RUN by default; `commit` to write. Idempotent (skips tenants whose
 * ROLE_ADMIN already has all modules at manage). Only the ROLE_ADMIN entry is
 * touched. COMMIT writes a rollback log; `restore` reverts.
 *
 * USAGE:
 *   php index.php admin_role_backfill run                    # dry-run, all
 *   php index.php admin_role_backfill run SCH_ABC123         # dry-run, one
 *   php index.php admin_role_backfill run all commit         # execute
 *   php index.php admin_role_backfill restore <rollback.jsonl>
 */
class Admin_role_backfill extends CI_Controller
{
    private const ROLE = 'ROLE_ADMIN';

    private $commit = false;
    private $onlySchool = 'all';
    private $stats = ['schools' => 0, 'updated' => 0, 'unchanged' => 0, 'no_admin_role' => 0];
    private $changeLog = [];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) { show_error('CLI-only.', 403); exit(1); }
        $this->load->library('firebase');
        $this->load->library('firestore_service', null, 'fs');
        $this->load->helper('rbac'); // RBAC_MODULES / RBAC_LEVELS
    }

    public function run($school = 'all', $mode = 'dry-run')
    {
        foreach ([$school, $mode] as $a) { if ($a === 'commit') $this->commit = true; }
        if (!in_array($school, ['all', 'commit', 'dry-run', ''], true)) $this->onlySchool = $school;

        $modules = defined('RBAC_MODULES') ? RBAC_MODULES : [];
        $levels  = array_fill_keys($modules, 'manage');
        if (empty($modules)) { $this->_out('RBAC_MODULES not defined — abort.'); return; }

        $this->_out('');
        $this->_out('== ROLE_ADMIN full-modules backfill (demotion prereq) ==');
        $this->_out('mode   : ' . ($this->commit ? 'COMMIT (writing)' : 'DRY-RUN (no writes)'));
        $this->_out('school : ' . $this->onlySchool);
        $this->_out('target : ' . count($modules) . ' modules, all at manage');
        $this->_out('');

        $rollback = null;
        if ($this->commit) {
            $path = FCPATH . 'admin_role_backfill_rollback_' . date('Ymd_His') . '.jsonl';
            $rollback = @fopen($path, 'w');
            $this->_out('rollback log: ' . $path . "\n");
        }

        try { $schools = $this->fs->where('schools', []); }
        catch (\Throwable $e) { $this->_out('list schools failed: ' . $e->getMessage()); if ($rollback) fclose($rollback); return; }

        foreach ($schools as $row) {
            $sid = (string) ($row['id'] ?? '');
            $doc = is_array($row['data'] ?? null) ? $row['data'] : [];
            if ($sid === '') continue;
            if ($this->onlySchool !== 'all' && $sid !== $this->onlySchool) continue;

            $this->stats['schools']++;
            $staffRoles = is_array($doc['staffRoles'] ?? null) ? $doc['staffRoles'] : [];
            if (!isset($staffRoles[self::ROLE]) || !is_array($staffRoles[self::ROLE])) {
                $this->stats['no_admin_role']++;
                $this->changeLog[] = "SKIP  {$sid} — no " . self::ROLE . " (run ensure_unified_roles first)";
                continue;
            }

            $curPerms = is_array($staffRoles[self::ROLE]['permissions'] ?? null) ? $staffRoles[self::ROLE]['permissions'] : [];
            $curLvls  = is_array($staffRoles[self::ROLE]['permissionLevels'] ?? null) ? $staffRoles[self::ROLE]['permissionLevels'] : [];

            $permsOk = (count(array_diff($modules, $curPerms)) === 0);
            $lvlsOk  = true;
            foreach ($modules as $m) { if (($curLvls[$m] ?? '') !== 'manage') { $lvlsOk = false; break; } }
            if ($permsOk && $lvlsOk) { $this->stats['unchanged']++; continue; }

            $missing = array_values(array_diff($modules, $curPerms));
            $this->stats['updated']++;
            $this->changeLog[] = "UPDATE {$sid} — +[" . implode(',', $missing) . "] → all " . count($modules) . " modules @ manage";

            if ($this->commit) {
                if ($rollback) {
                    fwrite($rollback, json_encode(['schoolId' => $sid, 'before' => [
                        'permissions' => $curPerms, 'permissionLevels' => $curLvls,
                    ]]) . "\n");
                }
                $staffRoles[self::ROLE]['permissions'] = array_values($modules);
                $staffRoles[self::ROLE]['permissionLevels'] = $levels;
                $ok = $this->fs->update('schools', $sid, ['staffRoles' => $staffRoles]);
                if (!$ok) $this->_out('  [FAILED] ' . $sid);
            }
        }
        if ($rollback) fclose($rollback);

        $this->_out('── changes ──');
        foreach (($this->changeLog ?: ['  (none — every ROLE_ADMIN already complete)']) as $l) $this->_out('  ' . $l);
        $this->_out('');
        $this->_out('── summary ──');
        foreach ($this->stats as $k => $v) $this->_out(sprintf('  %-14s %d', $k, $v));
        $this->_out('');
        $this->_out($this->commit
            ? 'Done. STEP 1 complete — safe to proceed with the rest of the demotion sequence.'
            : "Dry-run only. Re-run with `commit`:\n  php index.php admin_role_backfill run " . $this->onlySchool . " commit");
        $this->_out('');
    }

    public function restore($file = '')
    {
        if ($file === '') { $this->_out('Pass the rollback .jsonl filename.'); return; }
        $path = (strpos($file, '/') === 0) ? $file : FCPATH . $file;
        if (!is_file($path)) { $this->_out('Not found: ' . $path); return; }
        $fh = fopen($path, 'r'); $n = 0;
        while (($line = fgets($fh)) !== false) {
            $line = trim($line); if ($line === '') continue;
            $rec = json_decode($line, true);
            $sid = (string) ($rec['schoolId'] ?? '');
            $before = is_array($rec['before'] ?? null) ? $rec['before'] : [];
            if ($sid === '' || empty($before)) continue;
            $school = $this->fs->get('schools', $sid);
            $staffRoles = is_array($school['staffRoles'] ?? null) ? $school['staffRoles'] : [];
            if (isset($staffRoles[self::ROLE])) {
                $staffRoles[self::ROLE]['permissions'] = $before['permissions'] ?? [];
                $staffRoles[self::ROLE]['permissionLevels'] = $before['permissionLevels'] ?? [];
                $ok = $this->fs->update('schools', $sid, ['staffRoles' => $staffRoles]);
                $this->_out(($ok ? '  [restored] ' : '  [FAILED] ') . $sid);
                $n++;
            }
        }
        fclose($fh);
        $this->_out("Done. {$n} restored.");
    }

    private function _out(string $line): void { fwrite(STDOUT, $line . "\n"); }
}
