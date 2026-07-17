<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Staff_roles_backfill — one-off CLI that ensures every staff record carries a
 * `staff_roles[]` (the union input the unified resolver reads) and the global
 * auto-assigned ROLE_BASELINE_APP.
 *
 * WHY: the graded/union model resolves a staff member's access from the union of
 * `staff.staff_roles[]`. Legacy staff docs predate that field (they carry only a
 * free-text Position), so without a backfill their app/panel access resolves to
 * nothing once enforcement lands. This:
 *   1. Adds ROLE_BASELINE_APP to every (non-SSA) staff member.
 *   2. Fills a missing/empty staff_roles[] by inferring from Position via the
 *      canonical POSITION_ROLE_MAP; an UNMAPPED position falls back to ROLE_STAFF
 *      (view-only, no modules) and is tagged [inferred] — deliberately NOT
 *      ROLE_TEACHER, so the backfill never silently over-grants.
 *   3. Sets primary_role if absent (first non-baseline role).
 *
 * SSA (School Super Admin) god-accounts are SKIPPED entirely — they are not app
 * users and must not receive the baseline (see the SSA-isolation decision).
 *
 * SAFETY MODEL:
 *   - DRY-RUN by default; pass `commit` to write. Prints every proposed change.
 *   - IDEMPOTENT: a staff doc already holding baseline + a non-empty staff_roles[]
 *     (with primary_role set) is skipped.
 *   - ADDITIVE per doc: only staff_roles / primary_role are written; every other
 *     field is untouched. COMMIT writes a rollback log.
 *   - [inferred] rows (Position had no clean mapping) are flagged for review —
 *     bump genuine teachers to ROLE_TEACHER before/after commit as needed.
 *   - Optional school filter.
 *
 * USAGE (from project root):
 *   php index.php staff_roles_backfill run                    # dry-run, all schools
 *   php index.php staff_roles_backfill run SCH_ABC123         # dry-run, one school
 *   php index.php staff_roles_backfill run SCH_ABC123 commit  # execute, one school
 *   php index.php staff_roles_backfill run all commit         # execute, all schools
 *   php index.php staff_roles_backfill restore <rollback.jsonl>
 */
class Staff_roles_backfill extends CI_Controller
{
    private const BASELINE_ROLE = 'ROLE_BASELINE_APP';
    private const DEFAULT_ROLE  = 'ROLE_STAFF'; // conservative fallback (no modules)

    /** @var bool */
    private $commit = false;
    /** @var string */
    private $onlySchool = 'all';

    /** @var array<string,int> */
    private $stats = ['staff_seen' => 0, 'updated' => 0, 'inferred' => 0, 'skipped_ssa' => 0, 'baseline_seeded' => 0, 'unchanged' => 0];
    /** @var string[] */
    private $changeLog = [];
    /** @var array<string,string> POSITION keyword → ROLE_* (from Staff) */
    private $positionMap = [];
    /** @var array<string,bool> schools whose staffRoles baseline seed was checked */
    private $seededSchools = [];
    /** @var array|null the ROLE_BASELINE_APP definition (from Staff defaults) */
    private $baselineDef = null;

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('This backfill is CLI-only.', 403);
            exit(1);
        }
        $this->load->library('firebase');
        $this->load->library('firestore_service', null, 'fs');
        // Canonical Position→role map, straight from the Staff controller.
        if (!class_exists('Staff', false)) { @require_once APPPATH . 'controllers/Staff.php'; }
        $this->positionMap = (class_exists('Staff', false) && method_exists('Staff', 'position_role_map'))
            ? Staff::position_role_map() : [];
        // The canonical baseline-role definition — seeded into each tenant's
        // staffRoles catalogue so staff_roles=[ROLE_BASELINE_APP] actually resolves.
        $this->baselineDef = (class_exists('Staff', false) && method_exists('Staff', 'default_staff_roles'))
            ? (Staff::default_staff_roles()[self::BASELINE_ROLE] ?? null) : null;
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
        $this->_out('== Staff staff_roles[] + baseline backfill ==');
        $this->_out('mode   : ' . ($this->commit ? 'COMMIT (writing)' : 'DRY-RUN (no writes)'));
        $this->_out('school : ' . $this->onlySchool);
        $this->_out('');

        $rollback = null;
        if ($this->commit) {
            $path = FCPATH . 'staff_roles_backfill_rollback_' . date('Ymd_His') . '.jsonl';
            $rollback = @fopen($path, 'w');
            $this->_out('rollback log: ' . $path);
            $this->_out('');
        }

        $staff = $this->_load_staff();
        if (empty($staff)) {
            $this->_out('No staff resolved. Aborting.');
            if ($rollback) fclose($rollback);
            return;
        }

        foreach ($staff as $row) {
            $docId = (string) ($row['id'] ?? '');
            $d     = is_array($row['data'] ?? null) ? $row['data'] : [];
            if ($docId === '') continue;

            $sid = (string) ($d['schoolId'] ?? $d['school_id'] ?? '');
            if ($this->onlySchool !== 'all' && $sid !== $this->onlySchool) continue;

            // Ensure this tenant's staffRoles catalogue actually defines the
            // baseline role BEFORE we hand it to staff — else staff_roles resolves
            // to nothing on existing tenants that predate the seed.
            if ($sid !== '' && !isset($this->seededSchools[$sid])) {
                $this->_ensure_baseline($sid, $rollback);
                $this->seededSchools[$sid] = true;
            }

            $this->stats['staff_seen']++;

            // Skip SSA / super-admin god-accounts — not app users, no baseline.
            $role = (string) ($d['role'] ?? $d['Position'] ?? '');
            if ($this->_is_ssa($docId, $role)) {
                $this->stats['skipped_ssa']++;
                continue;
            }

            $existing = is_array($d['staff_roles'] ?? null) ? array_values(array_filter(array_map('strval', $d['staff_roles']))) : [];
            $inferred = false;

            if (empty($existing)) {
                $existing = $this->_infer_roles((string) ($d['Position'] ?? $d['position'] ?? $d['designation'] ?? ''));
                $inferred = true;
            }

            // Ensure baseline is present (first).
            $newRoles = $existing;
            if (!in_array(self::BASELINE_ROLE, $newRoles, true)) {
                array_unshift($newRoles, self::BASELINE_ROLE);
            }

            // primary_role: keep if valid & non-baseline; else first non-baseline.
            $primary = (string) ($d['primary_role'] ?? '');
            if ($primary === '' || $primary === self::BASELINE_ROLE || !in_array($primary, $newRoles, true)) {
                $primary = '';
                foreach ($newRoles as $r) { if ($r !== self::BASELINE_ROLE) { $primary = $r; break; } }
                if ($primary === '') $primary = self::DEFAULT_ROLE;
            }

            $curPrimary = (string) ($d['primary_role'] ?? '');
            $origRoles  = is_array($d['staff_roles'] ?? null)
                ? array_values(array_map('strval', $d['staff_roles'])) : [];
            $changed = ($newRoles !== $origRoles) || ($primary !== $curPrimary);

            if (!$changed) { $this->stats['unchanged']++; continue; }

            $this->stats['updated']++;
            if ($inferred) $this->stats['inferred']++;
            $name = (string) ($d['name'] ?? $d['Name'] ?? $docId);
            $tag  = $inferred ? ' [inferred]' : '';
            $this->changeLog[] = "{$docId} \"{$name}\"{$tag} → staff_roles=[" . implode(',', $newRoles) . "] primary={$primary}";

            if ($this->commit) {
                if ($rollback) {
                    fwrite($rollback, json_encode([
                        'type'    => 'staff',
                        'docId'   => $docId,
                        'before'  => ['staff_roles' => ($d['staff_roles'] ?? null), 'primary_role' => ($d['primary_role'] ?? null)],
                    ]) . "\n");
                }
                $ok = $this->fs->update('staff', $docId, ['staff_roles' => $newRoles, 'primary_role' => $primary]);
                if (!$ok) $this->_out('  [FAILED] ' . $docId);
            }
        }

        if ($rollback) fclose($rollback);

        $this->_out('── proposed changes ──');
        if (empty($this->changeLog)) {
            $this->_out('  (none — every staff already has baseline + staff_roles)');
        } else {
            foreach ($this->changeLog as $l) $this->_out('  ' . $l);
        }
        $this->_out('');
        $this->_out('── summary ──');
        foreach ($this->stats as $k => $v) $this->_out(sprintf('  %-14s %d', $k, $v));
        $this->_out('');
        if (!$this->commit) {
            $this->_out('Dry-run only. Review [inferred] rows (Position had no clean role match),');
            $this->_out('then re-run with `commit`:');
            $this->_out('  php index.php staff_roles_backfill run ' . $this->onlySchool . ' commit');
        } else {
            $this->_out('Done. Access resolves from the union on the user\'s next login.');
        }
        $this->_out('');
    }

    public function restore($file = '')
    {
        if ($file === '') { $this->_out('Pass the rollback .jsonl filename.'); return; }
        $path = (strpos($file, '/') === 0) ? $file : FCPATH . $file;
        if (!is_file($path)) { $this->_out('Not found: ' . $path); return; }
        $this->_out('== Restoring staff_roles from ' . $path . ' ==');
        $fh = fopen($path, 'r'); $n = 0;
        while (($line = fgets($fh)) !== false) {
            $line = trim($line); if ($line === '') continue;
            $rec = json_decode($line, true);
            $type = (string) ($rec['type'] ?? 'staff');
            $before = is_array($rec['before'] ?? null) ? $rec['before'] : [];
            if (empty($before)) continue;
            if ($type === 'school') {
                $sid = (string) ($rec['schoolId'] ?? '');
                if ($sid === '') continue;
                $ok = $this->fs->update('schools', $sid, $before);
                $this->_out(($ok ? '  [restored school] ' : '  [FAILED] ') . $sid);
            } else {
                $docId = (string) ($rec['docId'] ?? '');
                if ($docId === '') continue;
                // Null fields mean "was absent" — restore by writing them back as-is.
                $ok = $this->fs->update('staff', $docId, $before);
                $this->_out(($ok ? '  [restored staff] ' : '  [FAILED] ') . $docId);
            }
            $n++;
        }
        fclose($fh);
        $this->_out("Done. {$n} staff restored.");
    }

    /** Infer role ids from a free-text Position (conservative). */
    private function _infer_roles(string $position): array
    {
        $pos = strtolower(trim($position));
        if ($pos !== '') {
            foreach ($this->positionMap as $keyword => $roleId) {
                if (strpos($pos, $keyword) !== false) return [$roleId];
            }
        }
        return [self::DEFAULT_ROLE]; // unmapped/empty → view-only Staff (flagged)
    }

    /**
     * Seed ROLE_BASELINE_APP into a tenant's staffRoles catalogue if absent, so a
     * staff member holding it actually resolves the baseline modules. Idempotent
     * and additive — only ever ADDS the role, never touches existing ones.
     */
    private function _ensure_baseline(string $sid, $rollback): void
    {
        if ($this->baselineDef === null) return;
        try {
            $school = $this->fs->get('schools', $sid);
        } catch (\Throwable $e) {
            return;
        }
        $staffRoles = is_array($school['staffRoles'] ?? null) ? $school['staffRoles'] : [];
        if (isset($staffRoles[self::BASELINE_ROLE])) return; // already present

        $this->stats['baseline_seeded']++;
        $this->changeLog[] = "SEED  {$sid} → staffRoles[+" . self::BASELINE_ROLE . "]";
        if ($this->commit) {
            if ($rollback) {
                fwrite($rollback, json_encode([
                    'type'     => 'school',
                    'schoolId' => $sid,
                    'before'   => ['staffRoles' => $staffRoles],
                ]) . "\n");
            }
            $staffRoles[self::BASELINE_ROLE] = $this->baselineDef;
            $this->fs->update('schools', $sid, ['staffRoles' => $staffRoles]);
        }
    }

    private function _is_ssa(string $docId, string $role): bool
    {
        if (stripos($role, 'super admin') !== false) return true;               // "School Super Admin" / "Super Admin"
        if (preg_match('/_(SSA|SUP|SA)\d/i', $docId)) return true;              // id suffix like {sid}_SSA0001
        return false;
    }

    /** All staff docs as [['id'=>..., 'data'=>...], ...]. */
    private function _load_staff(): array
    {
        try {
            return $this->fs->where('staff', []);
        } catch (\Throwable $e) {
            $this->_out('Failed to list staff: ' . $e->getMessage());
            return [];
        }
    }

    private function _out(string $line): void
    {
        fwrite(STDOUT, $line . "\n");
    }
}
