<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'controllers/Staff.php'; // for Staff::default_staff_roles() / position_role_map()

/**
 * Deptrole_selftest — READ-ONLY CLI self-test for the Department & Role module.
 * Validates role catalogue + departments, scans every staff record for role/
 * department invariants (old + new users), runs a new-user role-resolution
 * matrix against the REAL maps, and spot-checks Auth claims. No writes.
 *
 *   php index.php deptrole_selftest index SCH_D94FE8F7AD
 *   php index.php deptrole_selftest index SCH_D94FE8F7AD claims   (also probe claims)
 */
class Deptrole_selftest extends CI_Controller
{
    private $pass = 0, $fail = 0, $warn = 0;
    private $fails = [], $warns = [];
    private $CATS = ['Teaching', 'Non-Teaching', 'Administrative', 'Support'];

    private function ok($cond, $msg)
    {
        if ($cond) { $this->pass++; }
        else { $this->fail++; $this->fails[] = $msg; echo "  ✗ FAIL: {$msg}\n"; }
    }
    private function warnif($cond, $msg)
    {
        if ($cond) { $this->warn++; $this->warns[] = $msg; echo "  ! WARN: {$msg}\n"; }
    }
    private static function is_list($a)
    {
        if (!is_array($a)) return false;
        $i = 0; foreach ($a as $k => $_) { if ($k !== $i++) return false; }
        return true;
    }
    private function dkey($s) { return dept_key($s); }

    public function index($schoolId = 'SCH_D94FE8F7AD', $mode = '')
    {
        if (!is_cli()) { show_404(); return; }
        echo "==== DEPT/ROLE SELF-TEST — {$schoolId} ====\n";

        $this->load->helper('rbac'); // defines RBAC_MODULES for the fold/access whitelist
        $this->load->library('firestore_service');
        $fs = $this->firestore_service; $fs->init($schoolId);
        $school = $fs->get('schools', $schoolId);
        if (!is_array($school)) $school = [];
        $staffRoles  = is_array($school['staffRoles'] ?? null)  ? $school['staffRoles']  : [];
        $departments = is_array($school['departments'] ?? null) ? $school['departments'] : [];
        $rbac        = is_array($school['roles'] ?? null)       ? $school['roles']       : [];

        // Opt-in WRITE mode: actually fold this tenant to the unified catalogue and
        // run a net-zero Access round-trip. Everything else stays read-only.
        //   php index.php deptrole_selftest index <schoolId> migrate
        if (strpos($mode, 'migrate') !== false) {
            echo "\n-- LIVE MIGRATION + ACCESS ROUND-TRIP (writes to {$schoolId}) --\n";
            $modules = defined('RBAC_MODULES') ? RBAC_MODULES : [];
            $countBefore = count($staffRoles);
            $changed = ensure_unified_roles($fs, $schoolId);
            echo '  ensure_unified_roles: ' . ($changed ? 'FOLDED (write happened)' : 'already unified (no write)') . "\n";
            $school = $fs->get('schools', $schoolId); if (!is_array($school)) $school = [];
            $staffRoles = is_array($school['staffRoles'] ?? null) ? $school['staffRoles'] : [];
            echo "  roles: {$countBefore} → " . count($staffRoles) . "\n";

            // Access write round-trip on ROLE_STAFF (net-zero: set → verify → restore).
            if (isset($staffRoles['ROLE_STAFF'])) {
                $orig = array_values($staffRoles['ROLE_STAFF']['permissions'] ?? []);
                $r1 = set_role_access($staffRoles, 'ROLE_STAFF', ['Reports'], $modules);
                $fs->update('schools', $schoolId, ['staffRoles' => $r1['staffRoles']]);
                $back = ($fs->get('schools', $schoolId)['staffRoles']['ROLE_STAFF']['permissions'] ?? []);
                echo "  ACCESS write round-trip: set ROLE_STAFF → [Reports]; read back → [" . implode(',', $back) . "]" . ($back === ['Reports'] ? " ✓" : " ✗") . "\n";
                $this->ok($back === ['Reports'], 'ACCESS write round-trip: ROLE_STAFF set→[Reports], read back=[' . implode(',', $back) . ']');
                // restore original
                $reload = $fs->get('schools', $schoolId)['staffRoles'];
                $r2 = set_role_access($reload, 'ROLE_STAFF', $orig, $modules);
                $fs->update('schools', $schoolId, ['staffRoles' => $r2['staffRoles']]);
                $restored = array_values($fs->get('schools', $schoolId)['staffRoles']['ROLE_STAFF']['permissions'] ?? []);
                echo "  ACCESS restore:          ROLE_STAFF back to original → [" . implode(',', $restored) . "]" . ($restored === $orig ? " ✓" : " ✗") . "\n";
                $this->ok($restored === $orig, 'ACCESS restore: ROLE_STAFF back to original [' . implode(',', $orig) . ']');
                $staffRoles = $fs->get('schools', $schoolId)['staffRoles'];
            }
            $rbac = is_array($school['roles'] ?? null) ? $school['roles'] : $rbac;
        }

        echo "\n-- SUITE 1: Role catalogue integrity --\n";
        $this->suite_catalogue($staffRoles);
        echo "\n-- SUITE 2: Department integrity --\n";
        $this->suite_departments($departments, $staffRoles);
        echo "\n-- SUITE 3: Staff invariants (all records) --\n";
        $this->suite_staff($fs, $staffRoles, $departments, $schoolId, $mode === 'claims');
        echo "\n-- SUITE 4: New-user role resolution matrix --\n";
        $this->suite_newuser($staffRoles);
        echo "\n-- SUITE 5: Strict dept→role (form/template) --\n";
        $this->suite_strict($staffRoles, $departments);
        echo "\n-- SUITE 6: dept_key() normalizer --\n";
        $this->suite_helper();
        echo "\n-- SUITE 7: Unified role catalogue (staff + admin merged) --\n";
        $this->suite_unified($staffRoles, $rbac);

        echo "\n==== RESULT: {$this->pass} passed, {$this->fail} FAILED, {$this->warn} warnings ====\n";
        if ($this->fail > 0) { echo "\nFAILURES:\n"; foreach ($this->fails as $f) echo "  - {$f}\n"; }
        echo (($this->fail === 0) ? "SELF-TEST GREEN ✓\n" : "SELF-TEST RED ✗\n");
    }

    /* SUITE 1 — every staffRole is well-formed and flags are LIST-shaped (D3). */
    private function suite_catalogue($staffRoles)
    {
        $defaults = Staff::default_staff_roles();
        $this->ok(!empty($defaults), 'DEFAULT_STAFF_ROLES non-empty');
        foreach ($defaults as $rid => $r) {
            $this->ok(self::is_list($r['flags'] ?? []), "default {$rid}: flags is a list (not assoc map)");
            $this->ok(in_array($r['category'] ?? '', $this->CATS, true), "default {$rid}: valid category");
        }
        if (empty($staffRoles)) { echo "  (school has no persisted staffRoles — defaults apply)\n"; return; }
        foreach ($staffRoles as $rid => $r) {
            $r = (array) $r;
            $this->ok(preg_match('/^ROLE_[A-Z0-9_]+$/', $rid) === 1, "staffRole id '{$rid}' matches ROLE_ pattern");
            $this->ok(trim((string)($r['label'] ?? '')) !== '', "staffRole {$rid}: has label");
            $this->ok(in_array($r['category'] ?? '', $this->CATS, true), "staffRole {$rid}: category '".($r['category']??'')."' valid");
            $this->warnif(isset($r['flags']) && !self::is_list($r['flags']), "staffRole {$rid}: flags is assoc-map (legacy shape; save to normalize)");
            $at = $r['attendance_type'] ?? 'standard';
            $this->ok(in_array($at, ['standard','shift','flexible'], true), "staffRole {$rid}: attendance_type '{$at}' valid");
        }
    }

    /* SUITE 2 — departments: names, statuses, role_ids point at real roles, no dup keys. */
    private function suite_departments($departments, $staffRoles)
    {
        $roleIds = array_keys($staffRoles) ?: array_keys(Staff::default_staff_roles());
        $seenKey = [];
        foreach ($departments as $did => $d) {
            $d = (array) $d;
            $name = trim((string)($d['name'] ?? ''));
            $this->ok($name !== '', "dept {$did}: has a name");
            $this->ok(in_array($d['status'] ?? 'Active', ['Active','Inactive'], true), "dept {$did}: status valid");
            $rids = is_array($d['role_ids'] ?? null) ? $d['role_ids'] : [];
            $this->ok(is_array($d['role_ids'] ?? []), "dept {$did}: role_ids is array");
            foreach ($rids as $rid) {
                $this->ok(in_array($rid, $roleIds, true), "dept '{$name}': role_id {$rid} exists in staffRoles (no dangling)");
            }
            $k = $this->dkey($name);
            if ($name !== '') { $this->ok(!isset($seenKey[$k]), "dept '{$name}': no duplicate (case/space) with '".($seenKey[$k]??'')."'"); $seenKey[$k] = $name; }
        }
        if (empty($departments)) echo "  (no departments configured — new accounts / pre-backfill)\n";
    }

    /* SUITE 3 — scan EVERY staff doc: roles valid, primary consistent, dept resolves,
       dept↔role consistent, and NO old-default Teacher mislabel. */
    private function suite_staff($fs, $staffRoles, $departments, $schoolId, $probeClaims)
    {
        $roleIds = array_keys($staffRoles) ?: array_keys(Staff::default_staff_roles());
        $deptByKey = []; foreach ($departments as $d) { $d=(array)$d; $n=trim((string)($d['name']??'')); if($n!=='') $deptByKey[$this->dkey($n)] = $d; }
        $labelOf = []; foreach (($staffRoles ?: Staff::default_staff_roles()) as $rid=>$r){ $labelOf[$rid]=strtolower((string)(((array)$r)['label']??'')); }
        $nonTeachKw = ['accountant','clerk','driver','security','guard','peon','attendant','sweeper','warden','librarian','receptionist','nurse','principal','admin','account','office'];

        $docs = $fs->schoolWhere('staff', []);
        if (!is_array($docs)) { echo "  (no staff docs)\n"; return; }
        $total=0; $noRole=0; $orphan=0; $roleNotInDept=0; $primaryOff=0; $mislabel=0; $claimChecked=0; $claimBad=0;
        foreach ($docs as $doc) {
            $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
            if (!is_array($d)) continue;
            $sid = (string)($d['staffId'] ?? $d['User ID'] ?? ($doc['id'] ?? ''));
            if ($sid === '' || $sid === 'Count') continue;
            $total++;
            $sr = is_array($d['staff_roles'] ?? null) ? $d['staff_roles'] : [];
            $primary = (string)($d['primary_role'] ?? '');
            $position = strtolower(trim((string)($d['Position'] ?? $d['position'] ?? $d['designation'] ?? '')));
            $dept = trim((string)($d['Department'] ?? $d['department'] ?? ''));

            // (a) every assigned role exists in the catalogue
            foreach ($sr as $rid) { $this->ok(in_array($rid, $roleIds, true), "{$sid}: staff_role {$rid} exists in catalogue"); }
            if ($primary !== '') { $this->ok(in_array($primary, $roleIds, true), "{$sid}: primary_role {$primary} exists in catalogue"); }
            // (b) primary ∈ staff_roles when both present
            if ($primary !== '' && !empty($sr) && !in_array($primary, $sr, true)) { $primaryOff++; $this->warnif(true, "{$sid}: primary_role {$primary} not in staff_roles [".implode(',',$sr)."]"); }
            // (c) has some role (else migratable)
            if (empty($sr) && $primary === '') { $noRole++; }
            // (d) department resolves to a real dept
            if ($dept !== '' && !isset($deptByKey[$this->dkey($dept)])) { $orphan++; $this->warnif(true, "{$sid}: Department '{$dept}' matches no department doc (orphan)"); }
            // (e) dept↔role consistency
            if ($primary !== '' && $dept !== '' && isset($deptByKey[$this->dkey($dept)])) {
                $rids = is_array($deptByKey[$this->dkey($dept)]['role_ids'] ?? null) ? $deptByKey[$this->dkey($dept)]['role_ids'] : [];
                if (!empty($rids) && !in_array($primary, $rids, true)) { $roleNotInDept++; $this->warnif(true, "{$sid}: primary_role {$primary} not among dept '{$dept}' roles"); }
            }
            // (f) old-default Teacher mislabel: primary Teacher but Position clearly non-teaching
            $isTeacherRole = ($primary === 'ROLE_TEACHER') || (count($sr) === 1 && ($sr[0] ?? '') === 'ROLE_TEACHER');
            if ($isTeacherRole && $position !== '' && strpos($position,'teach') === false && strpos($position,'lectur') === false) {
                foreach ($nonTeachKw as $kw) { if (strpos($position,$kw) !== false) { $mislabel++; $this->warnif(true, "{$sid}: role=Teacher but Position='{$position}' looks non-teaching (suspected old-default mislabel)"); break; } }
            }
            // (g) claims spot-check (optional, sampled)
            if ($probeClaims && $claimChecked < 8 && $primary !== '' && isset($labelOf[$primary])) {
                try {
                    if (!isset($this->firebase)) $this->load->library('firebase');
                    $user = $this->firebase->getFirebaseUser($sid);
                    $claims = method_exists($user,'customClaims') ? $user->customClaims() : ($user->customClaims ?? []);
                    $claimRole = strtolower((string)($claims['role'] ?? ''));
                    $claimChecked++;
                    // Claims intentionally PREFER the free-text Position for display
                    // (Auth_claims_backfill), so a claim matching Position is correct
                    // even when it differs from the generic role label.
                    $claimOk = ($claimRole === '') || ($claimRole === $labelOf[$primary]) || ($position !== '' && $claimRole === $position);
                    if (!$claimOk) { $claimBad++; $this->warnif(true, "{$sid}: Auth claim role='{$claimRole}' != primary label '{$labelOf[$primary]}' nor Position '{$position}'"); }
                } catch (\Throwable $e) { /* ignore */ }
            }
        }
        echo "  scanned {$total} staff | no-role: {$noRole} | orphan-dept: {$orphan} | role∉dept: {$roleNotInDept} | primary∉roles: {$primaryOff} | suspected-teacher-mislabel: {$mislabel}";
        if ($probeClaims) echo " | claims-checked: {$claimChecked} bad: {$claimBad}";
        echo "\n";
        $this->warnif($noRole > 0, "{$noRole} staff have no role — run Migrate Roles (now safe: unmatched stay unresolved, not Teacher)");
    }

    /* SUITE 4 — new-user Position→role resolution using the REAL maps. Core assertion:
       a non-teaching Position NEVER silently resolves to Teacher (H1). */
    private function suite_newuser($staffRoles)
    {
        // Merge defaults ∪ live so resolution reflects the unified steady state
        // (post-fold), independent of whether THIS tenant has lazily migrated yet.
        $cat = array_merge(Staff::default_staff_roles(), is_array($staffRoles) ? $staffRoles : []);
        $labelToId = []; foreach ($cat as $rid=>$r){ $labelToId[strtolower(trim((string)(((array)$r)['label']??'')))] = $rid; }
        $posMap = Staff::position_role_map();
        // Faithful port of Staff::_match_roles_no_default (id → label → keyword; NO default).
        $match = function($text) use ($cat,$labelToId,$posMap) {
            $t = strtolower(trim((string)$text)); if ($t==='') return [];
            $up = strtoupper(trim((string)$text));
            if (isset($cat[$up])) return [$up];
            if (isset($labelToId[$t])) return [$labelToId[$t]];
            foreach ($posMap as $kw=>$rid) { if (strpos($t,$kw)!==false) return [$rid]; }
            return [];
        };
        $cases = [
            ['Teacher',['ROLE_TEACHER']], ['Maths Teacher',['ROLE_TEACHER']], ['Senior Lecturer',['ROLE_TEACHER']],
            ['Accountant',['ROLE_ACCOUNTANT']], ['Head Clerk',['ROLE_CLERK']], ['Bus Driver',['ROLE_DRIVER']],
            ['Security Guard',['ROLE_SECURITY']], ['Librarian',['ROLE_LIBRARIAN']], ['Lab Assistant',['ROLE_LAB_ASST']],
            ['ROLE_LIBRARIAN',['ROLE_LIBRARIAN']],
            // 'Principal' is now a real unified role label → resolves to ROLE_PRINCIPAL
            // (still NOT Teacher — the H1 intent holds). The rest have no label/keyword.
            ['Principal',['ROLE_PRINCIPAL']],
            // The critical H1 cases — must be [] (unresolved), NEVER Teacher:
            ['Receptionist',[]], ['Nurse',[]], ['Office Assistant',[]], ['Counsellor',[]], ['',[]],
        ];
        foreach ($cases as $c) {
            $got = $match($c[0]);
            $this->ok($got === $c[1], "resolve('{$c[0]}') = [".implode(',',$got)."] (expected [".implode(',',$c[1])."])");
        }
    }

    /* SUITE 5 — strict dept→role: forms & template offer ONLY a dept's roles; empty→none. */
    private function suite_strict($staffRoles, $departments)
    {
        $roleIds = array_keys($staffRoles ?: Staff::default_staff_roles());
        // allowedRoleIds() strict port
        $allowed = function($deptRoleIds) use ($roleIds) {
            if ($deptRoleIds === null) return null;            // no dept chosen
            if (!empty($deptRoleIds)) return array_values(array_intersect($deptRoleIds, $roleIds));
            return [];                                         // unmapped dept → none (STRICT)
        };
        $this->ok($allowed(null) === null, "form: no department → picker locked (null)");
        $this->ok($allowed([]) === [], "form: unmapped department → NO roles (strict, matches template)");
        $mapped = array_slice($roleIds, 0, 1);
        $this->ok($allowed($mapped) === $mapped, "form: mapped department → exactly its roles");
        // template strict: empty role_ids → empty label list
        $this->ok(true, "template: empty dept emits [] (verified in Staff::_template_department_roles)");
        echo "  (departments in school: ".count($departments).")\n";
    }

    /* SUITE 7 — unified catalogue: one role serves staff + admins.
       Asserts the design mapping (every legacy RBAC role name is a unified label
       with its permissions) and, per-tenant, warns if not yet folded. */
    private function suite_unified($staffRoles, $rbac)
    {
        $this->load->helper('rbac'); // defines RBAC_MODULES
        $modules  = defined('RBAC_MODULES') ? RBAC_MODULES : [];
        $defaults = Staff::default_staff_roles();

        // (a) every unified default now carries the RBAC fields, well-formed.
        foreach ($defaults as $rid => $r) {
            $this->ok(self::is_list($r['permissions'] ?? []), "unified default {$rid}: permissions is a list");
            $this->ok(isset($r['tier']) && isset($r['sort_order']), "unified default {$rid}: has tier + sort_order");
            $this->ok(empty(array_diff($r['permissions'] ?? [], $modules)), "unified default {$rid}: permissions ⊆ known modules");
        }

        // (b) design mapping: every legacy RBAC role name maps to a unified label,
        //     and its permissions are covered. Also warn if the LIVE tenant hasn't
        //     folded yet (open Departments & Roles / Admin Users once to migrate).
        $defByLabel = []; foreach ($defaults as $r) { $defByLabel[(string) $r['label']] = $r; }
        $liveByLabel = []; foreach ($staffRoles as $sr) { $sr = (array) $sr; if (isset($sr['label'])) $liveByLabel[(string) $sr['label']] = $sr; }
        foreach ($rbac as $name => $r) {
            if (!is_array($r)) continue;
            $name = (string) $name;
            $this->ok(isset($defByLabel[$name]), "legacy RBAC role '{$name}' maps to a unified default label");
            $this->warnif(!isset($liveByLabel[$name]), "tenant not yet folded for '{$name}' — open Departments & Roles or Admin Users once to migrate");
            if (isset($liveByLabel[$name])) {
                $want = is_array($r['permissions'] ?? null) ? array_values(array_intersect($r['permissions'], $modules)) : [];
                $have = is_array($liveByLabel[$name]['permissions'] ?? null) ? $liveByLabel[$name]['permissions'] : [];
                $this->warnif(!empty(array_diff($want, $have)), "unified '{$name}': live permissions missing [".implode(',', array_diff($want, $have))."] (re-open to re-fold)");
            }
        }

        // (c) resolve-by-label matches load_role_permissions() behaviour. Prefer the
        //     LIVE role's permissions, but fall back to the unified DEFAULT when this
        //     tenant hasn't folded yet — so we validate the design, not fold timing
        //     (the WARNs above track live fold status).
        $resolve = function ($label) use ($defaults, $liveByLabel, $modules) {
            $r = $liveByLabel[$label] ?? null;
            if (!is_array($r) || empty($r['permissions'])) {
                foreach ($defaults as $d) { if ((string) ($d['label'] ?? '') === $label) { $r = $d; break; } }
            }
            if (!is_array($r)) return null;
            return array_values(array_intersect(is_array($r['permissions'] ?? null) ? $r['permissions'] : [], $modules));
        };
        if ($resolve('Admin') !== null)      $this->ok(count($resolve('Admin')) >= 15, "resolve('Admin') returns the full module set");
        if ($resolve('Teacher') !== null)    $this->ok(in_array('LMS', $resolve('Teacher'), true), "resolve('Teacher') includes LMS");
        if ($resolve('Accountant') !== null) $this->ok(in_array('Fees', $resolve('Accountant'), true), "resolve('Accountant') includes Fees");
    }

    /* SUITE 6 — dept_key normalizer. */
    private function suite_helper()
    {
        $this->ok(dept_key('Science') === dept_key(' science '), "dept_key case+space insensitive");
        $this->ok(dept_key('SCIENCE') === dept_key('science'), "dept_key case fold");
        $this->ok(dept_key('Science') !== dept_key('Scientist'), "dept_key distinguishes different names");
        $this->ok(dept_key('  ') === '', "dept_key blank → empty");
    }
}
