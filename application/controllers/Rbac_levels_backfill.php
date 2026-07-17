<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Rbac_levels_backfill — one-off CLI that writes the PARALLEL graded-level map
 * (`permissionLevels`) onto every role in schools.staffRoles.
 *
 * CONTEXT: Phase A2 added a graded model — has_permission($module,$level) reads a
 * per-role `permissionLevels: {module: level}` map alongside the untouched flat
 * `permissions[]` presence list. A module held WITHOUT an explicit level defaults
 * to 'manage' (legacy-equivalent). This backfill replaces that blanket-manage
 * default with an INTENTIONAL per-role level so that, once Phase B enforcement
 * passes real levels, a view-only role can no longer perform manage actions.
 *
 * WHY NOT BLANKET 'manage': the audit (UNIFIED_RBAC_AUDIT) showed a flat manage
 * backfill is a privilege-escalation bug — e.g. a Teacher who merely holds
 * 'Stories'/'Red Flags'/'Certificates' would gain delete/moderate/issue power the
 * legacy name-gates deny them. The level signal lives only in each controller's
 * name constants, so there is NO single source of truth to read off — every level
 * here is a POLICY CHOICE. Hence: an EXPLICIT curated table for the known system
 * roles, a conservative tier heuristic for custom roles (flagged for review), and
 * DRY-RUN by default so a human signs off per tenant before any write.
 *
 * SAFETY MODEL:
 *   - DRY-RUN by default; pass `commit` to write. Prints the full proposed
 *     module→level per role so it can be eyeballed first.
 *   - IDEMPOTENT: a role whose permissionLevels already equals the computed map
 *     is skipped. Re-running changes nothing.
 *   - ADDITIVE & REVERSIBLE: only the `permissionLevels` field is written;
 *     `permissions[]` and all identity/HR fields are left byte-for-byte. COMMIT
 *     writes a rollback log (prior staffRoles map per school).
 *   - Optional school filter to run one tenant at a time.
 *   - Custom / unrecognised roles get a conservative tier heuristic and are
 *     tagged [heuristic] in the report — review before relying on them.
 *
 * USAGE (from project root):
 *   php index.php rbac_levels_backfill run                    # dry-run, all schools
 *   php index.php rbac_levels_backfill run SCH_ABC123         # dry-run, one school
 *   php index.php rbac_levels_backfill run SCH_ABC123 commit  # execute, one school
 *   php index.php rbac_levels_backfill run all commit         # execute, all schools
 *   php index.php rbac_levels_backfill restore <rollback.jsonl>
 */
class Rbac_levels_backfill extends CI_Controller
{
    /** @var bool */
    private $commit = false;

    /** @var string 'all' or a specific schoolId */
    private $onlySchool = 'all';

    /**
     * Curated level policy for the KNOWN system roles (Staff::DEFAULT_STAFF_ROLES).
     * Keyed by ROLE_* id. Each entry: '*' = default level for any held module,
     * plus per-module overrides. Grounded in the audit tier→level mapping:
     *   - operator/finance/mark tiers → 'edit' (keep Accountants collecting fees,
     *     Teachers entering marks) — NOT 'manage'.
     *   - destructive / config / moderation / publish → 'manage' (admin tiers only).
     *   - read/oversight surfaces (Reports, consumed Communication, Certificates
     *     for name-blocked roles, Stories for non-moderators) → 'view'.
     * A module a role holds that is not overridden takes the '*' default.
     */
    private const SYSTEM_ROLE_POLICY = [
        'ROLE_ADMIN'                => ['*' => 'manage'],
        'ROLE_PRINCIPAL'            => ['*' => 'manage', 'Reports' => 'view', 'Configuration' => 'view'],
        'ROLE_VICE_PRINCIPAL'       => ['*' => 'manage', 'Reports' => 'view'],
        'ROLE_ACADEMIC_COORDINATOR' => ['*' => 'manage', 'SIS' => 'edit', 'LMS' => 'edit', 'Reports' => 'view', 'Stories' => 'edit'],
        'ROLE_HR_MANAGER'           => ['*' => 'manage', 'Attendance' => 'edit', 'Reports' => 'view'],
        'ROLE_ACCOUNTANT'           => ['*' => 'edit',   'Reports' => 'view'],
        'ROLE_FRONT_OFFICE'         => ['*' => 'edit',   'Certificates' => 'view', 'Stories' => 'view'],
        'ROLE_CLASS_TEACHER'        => ['*' => 'edit',   'SIS' => 'view', 'Stories' => 'view', 'Reports' => 'view', 'Events' => 'view'],
        'ROLE_TEACHER'              => ['*' => 'edit',   'Stories' => 'view', 'Communication' => 'view'],
        'ROLE_LIBRARIAN'            => ['*' => 'manage'],
        'ROLE_TRANSPORT_MANAGER'    => ['*' => 'manage', 'Reports' => 'view'],
        'ROLE_HOSTEL_WARDEN'        => ['*' => 'manage'],
        // ROLE_STAFF and the job roles hold no modules → nothing to level.
    ];

    /** Domain flag → module(s) that role clearly OWNS (⇒ manage) for custom roles. */
    private const DOMAIN_BY_FLAG = [
        'can_manage_library'   => ['Library'],
        'can_manage_transport' => ['Transport'],
        'can_manage_hostel'    => ['Hostel'],
        'can_manage_inventory' => ['Inventory'],
        'can_manage_assets'    => ['Assets'],
        'can_handle_fees'      => ['Fees', 'Accounting'],
    ];

    /** Modules that read like oversight/consumption ⇒ 'view' in the heuristic. */
    private const HEURISTIC_VIEW_MODULES = ['Reports', 'Stories', 'Communication', 'Certificates', 'Events'];

    /** @var array<string,int> */
    private $stats = ['schools' => 0, 'roles_seen' => 0, 'roles_leveled' => 0, 'heuristic_roles' => 0, 'unchanged' => 0];

    /** @var string[] */
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
        // RBAC_LEVELS lives in the rbac helper.
        $this->load->helper('rbac');
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
        $this->_out('== RBAC graded-level backfill (permissionLevels) ==');
        $this->_out('mode   : ' . ($this->commit ? 'COMMIT (writing)' : 'DRY-RUN (no writes)'));
        $this->_out('school : ' . $this->onlySchool);
        $this->_out('');

        $rollback = null;
        if ($this->commit) {
            $path = FCPATH . 'rbac_levels_backfill_rollback_' . date('Ymd_His') . '.jsonl';
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

            $staffRoles = is_array($doc['staffRoles'] ?? null) ? $doc['staffRoles'] : [];
            if (empty($staffRoles)) continue;

            $this->stats['schools']++;
            [$newMap, $touched] = $this->_process_roles($sid, $staffRoles);
            if (!$touched) continue;

            if ($this->commit) {
                if ($rollback) {
                    fwrite($rollback, json_encode(['schoolId' => $sid, 'before' => ['staffRoles' => $staffRoles]]) . "\n");
                }
                $ok = $this->fs->update('schools', $sid, ['staffRoles' => $newMap]);
                $this->_out(($ok ? '  [written] ' : '  [FAILED]  ') . $sid);
                if (!$ok) $this->_out('    !! update() returned false — rollback line was logged.');
            } else {
                $this->_out('  [would write] ' . $sid);
            }
        }

        if ($rollback) fclose($rollback);

        $this->_out('');
        $this->_out('── proposed levels ──');
        if (empty($this->changeLog)) {
            $this->_out('  (none — every role already leveled)');
        } else {
            foreach ($this->changeLog as $l) $this->_out('  ' . $l);
        }
        $this->_out('');
        $this->_out('── summary ──');
        foreach ($this->stats as $k => $v) $this->_out(sprintf('  %-16s %d', $k, $v));
        $this->_out('');
        if (!$this->commit) {
            $this->_out('Dry-run only. Review the [heuristic] roles above, then re-run with `commit`:');
            $this->_out('  php index.php rbac_levels_backfill run ' . $this->onlySchool . ' commit');
        } else {
            $this->_out('Done. Levels take effect on the user\'s next page load (or re-login).');
        }
        $this->_out('');
    }

    /**
     * Restore staffRoles maps from a rollback log written by a prior commit.
     * USAGE: php index.php rbac_levels_backfill restore rbac_levels_backfill_rollback_XXXX.jsonl
     */
    public function restore($file = '')
    {
        if ($file === '') { $this->_out('Pass the rollback .jsonl filename.'); return; }
        $path = (strpos($file, '/') === 0) ? $file : FCPATH . $file;
        if (!is_file($path)) { $this->_out('Not found: ' . $path); return; }

        $this->_out('== Restoring staffRoles from ' . $path . ' ==');
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
            $this->_out(($ok ? '  [restored] ' : '  [FAILED]  ') . $sid);
            $n++;
        }
        fclose($fh);
        $this->_out("Done. {$n} school(s) restored.");
    }

    /**
     * Compute permissionLevels for each role in one school's staffRoles map.
     * @return array{0: array, 1: bool}  [new map, whether anything changed]
     */
    private function _process_roles(string $sid, array $staffRoles): array
    {
        $touched = false;
        foreach ($staffRoles as $rid => $r) {
            if (!is_array($r)) continue;
            $this->stats['roles_seen']++;

            $perms = is_array($r['permissions'] ?? null) ? array_values($r['permissions']) : [];
            if (empty($perms)) continue; // no modules → no levels

            [$levels, $heuristic] = $this->_levels_for_role((string) $rid, $r, $perms);

            // Idempotent: skip if the stored map already matches.
            $existing = is_array($r['permissionLevels'] ?? null) ? $r['permissionLevels'] : null;
            if ($existing !== null && $this->_levels_equal($existing, $levels)) {
                $this->stats['unchanged']++;
                continue;
            }

            $staffRoles[$rid]['permissionLevels'] = $levels;
            $touched = true;
            $this->stats['roles_leveled']++;
            if ($heuristic) $this->stats['heuristic_roles']++;

            $label = (string) ($r['label'] ?? $rid);
            $tag   = $heuristic ? ' [heuristic — REVIEW]' : '';
            $pairs = [];
            foreach ($levels as $m => $lv) $pairs[] = "{$m}:{$lv}";
            $this->changeLog[] = "{$sid}/{$rid} \"{$label}\"{$tag} → " . implode(' ', $pairs);
        }
        return [$staffRoles, $touched];
    }

    /**
     * Level map for one role. Uses the curated system policy when the ROLE_* id is
     * known; otherwise a conservative tier heuristic (flagged).
     * @return array{0: array<string,string>, 1: bool}  [module=>level, isHeuristic]
     */
    private function _levels_for_role(string $rid, array $r, array $perms): array
    {
        if (isset(self::SYSTEM_ROLE_POLICY[$rid])) {
            $policy  = self::SYSTEM_ROLE_POLICY[$rid];
            $default = $policy['*'] ?? 'manage';
            $levels  = [];
            foreach ($perms as $m) {
                $lv = $policy[$m] ?? $default;
                $levels[$m] = $this->_valid_level($lv);
            }
            return [$levels, false];
        }

        // Heuristic for custom / unrecognised roles.
        $tier  = (int) ($r['tier'] ?? 7);
        $flags = (array) ($r['flags'] ?? []);
        $domain = [];
        foreach ($flags as $fl) {
            foreach (self::DOMAIN_BY_FLAG[$fl] ?? [] as $m) $domain[$m] = true;
        }

        $levels = [];
        foreach ($perms as $m) {
            if (isset($domain[$m])) {                 // clearly owns this domain
                $levels[$m] = 'manage';
            } elseif ($tier <= 3) {                   // senior admin tiers
                $levels[$m] = 'manage';
            } elseif ($tier === 4) {                  // operator/teaching tier
                $levels[$m] = in_array($m, self::HEURISTIC_VIEW_MODULES, true) ? 'view' : 'edit';
            } else {                                  // support / low tier
                $levels[$m] = 'view';
            }
        }
        return [$levels, true];
    }

    private function _valid_level(string $lv): string
    {
        $valid = defined('RBAC_LEVELS') ? RBAC_LEVELS : ['view' => 1, 'edit' => 2, 'manage' => 3];
        return isset($valid[$lv]) ? $lv : 'manage';
    }

    private function _levels_equal(array $a, array $b): bool
    {
        if (count($a) !== count($b)) return false;
        foreach ($b as $k => $v) {
            if (!array_key_exists($k, $a) || $a[$k] !== $v) return false;
        }
        return true;
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
