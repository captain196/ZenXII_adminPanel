<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Ops_perms_backfill — one-off CLI that migrates EXISTING role records from the
 * old coarse 'Operations' permission to the new granular Operations sub-modules
 * (Library / Transport / Hostel / Inventory / Assets).
 *
 * WHY: 'Operations' used to be a single permission — granting it opened Library,
 * Transport, Hostel, Inventory and Assets all at once. It is now an umbrella over
 * five separately-grantable children (see rbac_helper.php RBAC_MODULE_CHILDREN).
 * New tenants seed granular roles; this script re-scopes roles already stored in
 * schools.staffRoles (and the legacy schools.roles map) so a live Librarian ends
 * up with just 'Library', a Transport Manager with 'Transport', etc.
 *
 * SAFETY MODEL — conservative by design:
 *   - DRY-RUN by default. Pass `commit` to actually write.
 *   - A role is downgraded ONLY when it points at EXACTLY ONE ops department,
 *     inferred from its can_manage_* flags and its id/label. If a role touches
 *     zero or multiple ops departments (e.g. a general "Operations Manager", or
 *     Admin), its 'Operations' umbrella is LEFT UNTOUCHED — full access preserved.
 *   - IDEMPOTENT: a role without 'Operations' in its permissions is skipped, so
 *     re-running changes nothing.
 *   - Umbrella inheritance means even un-migrated roles keep working unchanged;
 *     this only TIGHTENS the specific single-department roles.
 *   - Optional school filter to run one tenant at a time.
 *   - COMMIT writes a rollback log (per school: the old staffRoles/roles maps).
 *
 * USAGE (from project root):
 *   php index.php ops_perms_backfill run                    # dry-run, all schools
 *   php index.php ops_perms_backfill run all commit         # execute, all schools
 *   php index.php ops_perms_backfill run SCH_ABC123         # dry-run, one school
 *   php index.php ops_perms_backfill run SCH_ABC123 commit  # execute, one school
 */
class Ops_perms_backfill extends CI_Controller
{
    /** @var bool */
    private $commit = false;

    /** @var string 'all' or a specific schoolId */
    private $onlySchool = 'all';

    /** can_manage_* flag → Operations child module. */
    private const DEPT_BY_FLAG = [
        'can_manage_library'   => 'Library',
        'can_manage_transport' => 'Transport',
        'can_manage_hostel'    => 'Hostel',
        'can_manage_inventory' => 'Inventory',
        'can_manage_assets'    => 'Assets',
    ];

    /** child module → substrings that (in a role id/label) imply that department. */
    private const NAME_HINTS = [
        'Library'   => ['librar'],
        'Transport' => ['transport'],
        'Hostel'    => ['hostel', 'warden'],
        'Inventory' => ['inventory', 'stock', 'store'],
        'Assets'    => ['asset'],
    ];

    /** @var array<string,int> */
    private $stats = ['schools' => 0, 'roles_seen' => 0, 'downgraded' => 0, 'kept_umbrella' => 0];

    /** @var string[] human-readable change lines for the report */
    private $changeLog = [];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('This backfill is CLI-only.', 403);
            exit(1);
        }
        $this->load->library('firebase');
        $this->load->library('firestore_service', null, 'fs');
    }

    public function run($school = 'all', $mode = 'dry-run')
    {
        foreach ([$school, $mode] as $arg) {
            if ($arg === 'commit') $this->commit = true;
        }
        if (!in_array($school, ['all', 'commit', 'dry-run', ''], true)) {
            $this->onlySchool = $school;
        }

        $this->_out('');
        $this->_out('== Operations permission backfill ==');
        $this->_out('mode   : ' . ($this->commit ? 'COMMIT (writing)' : 'DRY-RUN (no writes)'));
        $this->_out('school : ' . $this->onlySchool);
        $this->_out('');

        $rollback = null;
        if ($this->commit) {
            $path = FCPATH . 'ops_perms_backfill_rollback_' . date('Ymd_His') . '.jsonl';
            $rollback = @fopen($path, 'w');
            $this->_out('rollback log: ' . $path);
            $this->_out('');
        }

        $schools = $this->_load_schools();
        if (empty($schools)) {
            $this->_out('No schools resolved. Aborting.');
            if ($rollback) fclose($rollback);
            return;
        }

        foreach ($schools as $row) {
            $sid = (string) ($row['id'] ?? '');
            $doc = is_array($row['data'] ?? null) ? $row['data'] : [];
            if ($sid === '') continue;
            if ($this->onlySchool !== 'all' && $sid !== $this->onlySchool) continue;

            $this->stats['schools']++;
            $update  = [];
            $rbFields = [];

            foreach (['staffRoles', 'roles'] as $field) {
                $map = is_array($doc[$field] ?? null) ? $doc[$field] : [];
                if (empty($map)) continue;
                [$newMap, $touched] = $this->_process_role_map($sid, $field, $map);
                if ($touched) {
                    $update[$field]   = $newMap;
                    $rbFields[$field] = $map;   // original, for rollback
                }
            }

            if (empty($update)) continue;

            if ($this->commit) {
                if ($rollback) {
                    fwrite($rollback, json_encode(['schoolId' => $sid, 'before' => $rbFields]) . "\n");
                }
                $ok = $this->fs->update('schools', $sid, $update);
                $this->_out(($ok ? '  [written] ' : '  [FAILED]  ') . $sid . ' — ' . implode(', ', array_keys($update)));
                if (!$ok) $this->_out('    !! update() returned false — investigate; rollback line was logged.');
            } else {
                $this->_out('  [would write] ' . $sid . ' — ' . implode(', ', array_keys($update)));
            }
        }

        if ($rollback) fclose($rollback);

        $this->_out('');
        $this->_out('── changes ──');
        if (empty($this->changeLog)) {
            $this->_out('  (none — nothing to migrate)');
        } else {
            foreach ($this->changeLog as $l) $this->_out('  ' . $l);
        }
        $this->_out('');
        $this->_out('── summary ──');
        foreach ($this->stats as $k => $v) $this->_out(sprintf('  %-14s %d', $k, $v));
        $this->_out('');
        if (!$this->commit) {
            $this->_out('Dry-run only. Re-run with `commit` to write, e.g.:');
            $this->_out('  php index.php ops_perms_backfill run ' . ($this->onlySchool) . ' commit');
        } else {
            $this->_out('Done. Affected users get the tightened access on their next page load');
            $this->_out('(re-login if a session looks stale).');
        }
        $this->_out('');
    }

    /**
     * Restore role maps from a rollback log written by a prior commit.
     * USAGE: php index.php ops_perms_backfill restore ops_perms_backfill_rollback_XXXX.jsonl
     */
    public function restore($file = '')
    {
        if ($file === '') { $this->_out('Pass the rollback .jsonl filename.'); return; }
        $path = (strpos($file, '/') === 0) ? $file : FCPATH . $file;
        if (!is_file($path)) { $this->_out('Not found: ' . $path); return; }

        $this->_out('== Restoring from ' . $path . ' ==');
        $fh = fopen($path, 'r');
        $n = 0;
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') continue;
            $rec = json_decode($line, true);
            $sid = (string) ($rec['schoolId'] ?? '');
            $before = is_array($rec['before'] ?? null) ? $rec['before'] : [];
            if ($sid === '' || empty($before)) continue;
            $ok = $this->fs->update('schools', $sid, $before);
            $this->_out(($ok ? '  [restored] ' : '  [FAILED]  ') . $sid . ' — ' . implode(', ', array_keys($before)));
            $n++;
        }
        fclose($fh);
        $this->_out("Done. {$n} school(s) restored.");
    }

    /**
     * Walk one role map, downgrading each single-department 'Operations' role.
     * @return array{0: array, 1: bool}  [new map, whether anything changed]
     */
    private function _process_role_map(string $sid, string $field, array $map): array
    {
        $touched = false;
        foreach ($map as $rid => $r) {
            if (!is_array($r)) continue;
            $this->stats['roles_seen']++;

            $perms = is_array($r['permissions'] ?? null) ? array_values($r['permissions']) : [];
            if (!in_array('Operations', $perms, true)) continue;   // nothing to migrate

            $depts = $this->_depts_for_role((string) $rid, $r);
            $label = (string) ($r['label'] ?? $rid);

            if (count($depts) !== 1) {
                $this->stats['kept_umbrella']++;
                $why = empty($depts) ? 'no specific ops department' : ('spans ' . implode('+', $depts));
                $this->changeLog[] = "KEEP  {$sid}/{$field}/{$rid} \"{$label}\" — {$why}; umbrella 'Operations' left intact";
                continue;
            }

            $child = $depts[0];
            $new = [];
            foreach ($perms as $p) {
                $p = ($p === 'Operations') ? $child : $p;
                if (!in_array($p, $new, true)) $new[] = $p;
            }
            if ($new === $perms) continue;

            $r['permissions'] = $new;
            $map[$rid] = $r;
            $touched = true;
            $this->stats['downgraded']++;
            $this->changeLog[] = "MIGRATE {$sid}/{$field}/{$rid} \"{$label}\" — [" .
                implode(',', $perms) . "] → [" . implode(',', $new) . "]";
        }
        return [$map, $touched];
    }

    /** Which ops departments a role points at, from flags + id/label hints. */
    private function _depts_for_role(string $rid, array $r): array
    {
        $set = [];
        foreach ((array) ($r['flags'] ?? []) as $fl) {
            if (isset(self::DEPT_BY_FLAG[$fl])) $set[self::DEPT_BY_FLAG[$fl]] = true;
        }
        $hay = strtolower($rid . ' ' . (string) ($r['label'] ?? ''));
        foreach (self::NAME_HINTS as $dept => $words) {
            foreach ($words as $w) {
                if (strpos($hay, $w) !== false) { $set[$dept] = true; break; }
            }
        }
        return array_keys($set);
    }

    /** All school docs as [['id'=>..., 'data'=>...], ...]. */
    private function _load_schools(): array
    {
        try {
            return $this->fs->where('schools', []);
        } catch (\Throwable $e) {
            $this->_out('Failed to list schools: ' . $e->getMessage());
            return [];
        }
    }

    private function _out(string $line): void
    {
        fwrite(STDOUT, $line . "\n");
    }
}
