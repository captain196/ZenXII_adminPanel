<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Staff_access — the Unified Staff Access UI (matches the "ZenXii · Unified Staff
 * Access" prototype). A new, additive panel section; existing Staff / Admin Users
 * pages are untouched. Three tabs:
 *   1. Departments & Roles — the shared role catalogue (schools.staffRoles)
 *   2. Role Editor         — per-module levels (None/View/Edit/Manage) on a role
 *   3. Staff Access        — per-staff roles + extra/deny + effective access [Phase 2]
 *
 * Backend it wires to (all pre-existing from the RBAC work):
 *   - schools.staffRoles     : the catalogue (label/category/tier/desc/permissions/permissionLevels)
 *   - RBAC_MODULES/_META     : the 24 modules + group + surface (app/web/both)
 *   - deptrole_helper        : derive_role_id(), set_role_access()
 *   - resolve_role_access()  : the union resolver (effective access — Phase 2)
 *
 * Access: gated on the 'Admin Users' module (managing roles/access is admin territory).
 */
class Staff_access extends MY_Controller
{
    /** Bypass + panel-manager roles that may reach this section. */
    private const ADMIN_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal'];

    /** Fixed role-classification set — kept in sync with Org::ROLE_CATEGORIES. */
    private const ROLE_CATEGORIES = ['Teaching', 'Non-Teaching', 'Administrative', 'Support'];

    public function __construct()
    {
        parent::__construct();
        $this->load->helper('rbac');
        $this->load->helper('deptrole');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  PAGE
    // ─────────────────────────────────────────────────────────────────────

    public function index(): void
    {
        $this->_require_role(self::ADMIN_ROLES, 'staff_access', 'Admin Users', 'view');
        $this->load->view('include/header');
        $this->load->view('staff_access/index');
        $this->load->view('include/footer');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  READ — the whole catalogue in the prototype's shape
    // ─────────────────────────────────────────────────────────────────────

    public function get_catalogue(): void
    {
        $this->_require_role(self::ADMIN_ROLES, 'staff_access_catalogue', 'Admin Users', 'view');
        header('Content-Type: application/json');

        // Seeds role.department (= category) + the department set on first use, and
        // rebuilds each department's role_ids (Import compat). Persist only the seed.
        [$school, $departments, $staffRoles, $seeded] = $this->_dept_state();
        if ($seeded) {
            $this->fs->update('schools', $this->school_id, ['departments' => $departments, 'staffRoles' => $staffRoles]);
        }

        $roles = [];
        foreach ($staffRoles as $rid => $r) {
            if (!is_array($r)) continue;
            $roles[] = $this->_role_out((string) $rid, $r);
        }
        usort($roles, static function ($a, $b) {
            return ($a['sort_order'] <=> $b['sort_order']) ?: strcasecmp($a['label'], $b['label']);
        });

        $modules = [];
        foreach (RBAC_MODULES as $m) {
            $meta = defined('RBAC_MODULE_META') && isset(RBAC_MODULE_META[$m]) ? RBAC_MODULE_META[$m] : ['Other', 'web'];
            $modules[] = ['name' => $m, 'group' => $meta[0], 'surface' => $meta[1]];
        }

        // Configurable DEPARTMENTS — the single grouping (Tab 1) + staff placement (onboard).
        $depts = [];
        foreach ($departments as $did => $d) { if (is_array($d)) $depts[] = $this->_dept_out((string) $did, $d); }
        usort($depts, static fn($a, $b) => ($a['sort_order'] <=> $b['sort_order']) ?: strcasecmp($a['name'], $b['name']));

        $this->json_success([
            'roles'       => $roles,
            'modules'     => $modules,
            'levels'      => ['none', 'view', 'edit', 'manage'],
            'ownership'   => ['Own Payslip', 'My Attendance', 'My Leave', 'Profile'],
            'departments' => $depts,                                                 // {id,name,description,sort_order,role_ids}
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  WRITE — create / update a role's metadata
    // ─────────────────────────────────────────────────────────────────────

    public function save_role(): void
    {
        $this->_require_role(self::ADMIN_ROLES, 'staff_access_save_role', 'Admin Users', 'manage');
        header('Content-Type: application/json');

        $rid   = trim((string) ($this->input->post('role_id', true) ?? ''));
        $label = trim((string) ($this->input->post('label', true) ?? ''));
        $dept  = trim((string) ($this->input->post('department', true) ?? ''));   // configurable dept NAME
        $tier  = (int) ($this->input->post('tier', true) ?? 5);
        $desc  = trim((string) ($this->input->post('description', true) ?? ''));

        if ($label === '')                 { $this->json_error('Role name is required.'); return; }
        if (mb_strlen($label) > 60)        { $this->json_error('Role name must be 60 characters or less.'); return; }
        $tier = max(1, min(9, $tier));

        [$school, $departments, $staffRoles] = $this->_dept_state();
        if ($dept === '') {   // default to the first existing department
            $dept = 'Administrative';
            foreach ($departments as $d) { if (is_array($d) && trim((string) ($d['name'] ?? '')) !== '') { $dept = (string) $d['name']; break; } }
        }

        // SECURITY (mirror Org::save_role): a CUSTOM role must never carry a bypass
        // label — authorization matches the role LABEL against RBAC_BYPASS_ROLES, so a
        // role named "Super Admin" / "Admin" would silently resolve to a full-access
        // account when assigned (onboard_staff also copies the label into the claim).
        // Genuine system roles are exempt (and are already blocked from rename below).
        $isSystemTarget = ($rid !== '' && isset($staffRoles[$rid]) && !empty($staffRoles[$rid]['is_system']));
        if (!$isSystemTarget && in_array(mb_strtolower($label), ['super admin', 'school super admin', 'admin'], true)) {
            $this->json_error("The name '{$label}' is reserved and can't be used for a custom role.");
            return;
        }

        // SECURITY (audit C2 remainder): a non-god caller may only create/edit a role
        // strictly less privileged than their own (blocks self-widening + editing an
        // admin/higher-tier role via the identity facet).
        if ($err = $this->_role_ceiling_block($staffRoles, $rid, $tier)) { $this->json_error($err, 403); return; }

        if ($rid === '') {
            // CREATE — derive a fresh ROLE_* id from the label.
            $rid = derive_role_id($label, $staffRoles);
            if (isset($staffRoles[$rid])) { $this->json_error('A role with that name already exists.'); return; }
            $staffRoles[$rid] = [
                'label'            => $label,
                'description'      => $desc,
                'category'         => 'Administrative',   // legacy field; grouping is by department
                'department'       => $dept,
                'tier'             => $tier,
                'is_system'        => false,
                'sort_order'       => 100 + count($staffRoles),
                'permissions'      => [],
                'permissionLevels' => (object) [],
            ];
        } else {
            if (!isset($staffRoles[$rid]) || !is_array($staffRoles[$rid])) { $this->json_error('Role not found.'); return; }
            if (!empty($staffRoles[$rid]['is_system'])) {
                $this->json_error('System roles cannot be renamed. Edit their access levels instead.', 403);
                return;
            }
            $staffRoles[$rid]['label']       = $label;
            $staffRoles[$rid]['description'] = $desc;
            $staffRoles[$rid]['department']  = $dept;
            $staffRoles[$rid]['tier']        = $tier;
        }

        // Ensure a department exists for $dept + resync every department's role_ids.
        $this->_seed_departments($departments, $staffRoles);
        // M5: this path is a whole-map read-modify-write by necessity — a CREATE adds
        // a new staffRoles key and _seed_departments/_rebuild_role_ids resync role_ids
        // across EVERY department, so a field-scoped nested PATCH can't express it
        // cleanly. Left as-is; the residual last-writer-wins race between two admins
        // saving role metadata / departments concurrently is accepted (rare, and the
        // per-role access matrix — the hot path — is field-scoped in set_role_level /
        // save_role_access above).
        if (!$this->fs->update('schools', $this->school_id, ['staffRoles' => $staffRoles, 'departments' => $departments])) {
            $this->json_error('Could not save the role. Please try again.', 500);
            return;
        }
        log_audit('Staff_access', 'save_role', $rid, "Saved role '{$label}' in {$dept}");
        $this->json_success(['role' => $this->_role_out($rid, $staffRoles[$rid])]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  WRITE — set ONE module's level on a role (the matrix segmented control)
    // ─────────────────────────────────────────────────────────────────────

    public function set_role_level(): void
    {
        $this->_require_role(self::ADMIN_ROLES, 'staff_access_set_level', 'Admin Users', 'manage');
        header('Content-Type: application/json');

        $rid    = trim((string) ($this->input->post('role_id', true) ?? ''));
        $module = trim((string) ($this->input->post('module', true) ?? ''));
        $level  = trim((string) ($this->input->post('level', true) ?? ''));

        if ($rid === '' || $module === '')              { $this->json_error('Role and module are required.'); return; }
        if (!in_array($module, RBAC_MODULES, true))     { $this->json_error('Unknown module.'); return; }
        if (!in_array($level, ['none', 'view', 'edit', 'manage'], true)) { $this->json_error('Invalid level.'); return; }

        $school     = $this->fs->get('schools', $this->school_id);
        $staffRoles = is_array($school['staffRoles'] ?? null) ? $school['staffRoles'] : [];
        if (!isset($staffRoles[$rid]) || !is_array($staffRoles[$rid])) { $this->json_error('Role not found.'); return; }
        // SECURITY (audit C2 remainder): non-gods may not widen an admin/held/≥-tier role.
        if ($err = $this->_role_ceiling_block($staffRoles, $rid)) { $this->json_error($err, 403); return; }
        // Guard the baseline role: it is deliberately app-only view; block escalation here.
        if ($rid === 'ROLE_BASELINE_APP') {
            $this->json_error('The App Baseline role is fixed (Stories/Events/Communication at view). Manage it via a dedicated role instead.', 403);
            return;
        }

        $perms  = array_values(array_filter((array) ($staffRoles[$rid]['permissions'] ?? [])));
        $levels = is_array($staffRoles[$rid]['permissionLevels'] ?? null) ? $staffRoles[$rid]['permissionLevels'] : [];

        if ($level === 'none') {
            $perms = array_values(array_diff($perms, [$module]));
            unset($levels[$module]);
        } else {
            if (!in_array($module, $perms, true)) $perms[] = $module;
            $levels[$module] = $level;
        }

        // Reuse set_role_access so permissions are whitelisted to RBAC_MODULES and
        // levels are cleaned to granted modules (single source of truth).
        $res = set_role_access($staffRoles, $rid, $perms, RBAC_MODULES, $levels);
        if ($res === null) { $this->json_error('Could not update the role.', 500); return; }

        // M5: field-scoped write instead of rewriting the whole staffRoles map.
        // set_role_access only mutates THIS role's permissions/permissionLevels, so
        // we PATCH just those two nested paths (Firestore updateMask.fieldPaths,
        // cleanly supported by Firestore_service::update → updateDocument). A
        // concurrent admin editing a DIFFERENT role is no longer clobbered; only
        // two admins editing the SAME role race, which is inherent last-writer-wins.
        // (permissionLevels cast to object so an empty map stays a map, not [].)
        $wr = $res['rid'];
        if (!$this->fs->update('schools', $this->school_id, [
            'staffRoles.' . $wr . '.permissions'      => $res['staffRoles'][$wr]['permissions'],
            'staffRoles.' . $wr . '.permissionLevels' => (object) ($res['staffRoles'][$wr]['permissionLevels'] ?? []),
        ])) {
            $this->json_error('Could not save the change. Please try again.', 500);
            return;
        }
        log_audit('Staff_access', 'set_role_level', $rid, "{$module} → {$level}");
        $this->json_success(['role' => $this->_role_out($rid, $res['staffRoles'][$rid])]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  WRITE — save ALL of a role's module levels at once (matrix "Save changes")
    // ─────────────────────────────────────────────────────────────────────

    public function save_role_access(): void
    {
        $this->_require_role(self::ADMIN_ROLES, 'staff_access_save_access', 'Admin Users', 'manage');
        header('Content-Type: application/json');

        $rid   = trim((string) ($this->input->post('role_id', true) ?? ''));
        $lvRaw = (string) ($this->input->post('levels', true) ?? '');   // JSON { module: level }
        if ($rid === '') { $this->json_error('Role is required.'); return; }
        $levelsIn = json_decode($lvRaw, true);
        if (!is_array($levelsIn)) { $this->json_error('Invalid levels payload.'); return; }

        $school     = $this->fs->get('schools', $this->school_id);
        $staffRoles = is_array($school['staffRoles'] ?? null) ? $school['staffRoles'] : [];
        if (!isset($staffRoles[$rid]) || !is_array($staffRoles[$rid])) { $this->json_error('Role not found.'); return; }
        // SECURITY (audit C2 remainder): non-gods may not widen an admin/held/≥-tier role.
        if ($err = $this->_role_ceiling_block($staffRoles, $rid)) { $this->json_error($err, 403); return; }
        if ($rid === 'ROLE_BASELINE_APP') { $this->json_error('The App Baseline role is fixed.', 403); return; }

        // Whitelist: only real modules at a real level; 'none'/omitted = not granted.
        $perms = []; $levels = [];
        foreach ($levelsIn as $m => $l) {
            $m = (string) $m; $l = (string) $l;
            if (!in_array($m, RBAC_MODULES, true)) continue;
            if (!in_array($l, ['view', 'edit', 'manage'], true)) continue;
            $perms[] = $m; $levels[$m] = $l;
        }
        $res = set_role_access($staffRoles, $rid, $perms, RBAC_MODULES, $levels);
        if ($res === null) { $this->json_error('Could not update the role.', 500); return; }
        // M5: field-scoped write (see set_role_level) — PATCH only this role's two
        // access sub-fields instead of the whole staffRoles map, so a concurrent
        // edit to another role isn't lost. Same-role edits remain last-writer-wins.
        $wr = $res['rid'];
        if (!$this->fs->update('schools', $this->school_id, [
            'staffRoles.' . $wr . '.permissions'      => $res['staffRoles'][$wr]['permissions'],
            'staffRoles.' . $wr . '.permissionLevels' => (object) ($res['staffRoles'][$wr]['permissionLevels'] ?? []),
        ])) {
            $this->json_error('Could not save. Please try again.', 500); return;
        }
        log_audit('Staff_access', 'save_role_access', $rid, count($perms) . ' modules set');
        $this->json_success(['role' => $this->_role_out($rid, $res['staffRoles'][$rid])]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  WRITE — delete a custom role
    // ─────────────────────────────────────────────────────────────────────

    public function delete_role(): void
    {
        $this->_require_role(self::ADMIN_ROLES, 'staff_access_delete_role', 'Admin Users', 'manage');
        header('Content-Type: application/json');

        $rid = trim((string) ($this->input->post('role_id', true) ?? ''));
        if ($rid === '') { $this->json_error('Role id is required.'); return; }

        $school     = $this->fs->get('schools', $this->school_id);
        $staffRoles = is_array($school['staffRoles'] ?? null) ? $school['staffRoles'] : [];
        if (!isset($staffRoles[$rid])) { $this->json_error('Role not found.'); return; }
        if (!empty($staffRoles[$rid]['is_system'])) { $this->json_error('System roles cannot be deleted.', 403); return; }

        // Refuse if any staff still holds it — the admin must reassign first.
        // FAIL-SAFE: if the array-contains index is absent, fall back to a full
        // staff scan; if even that fails, refuse rather than risk orphaning holders.
        $holders = 0;
        try {
            $holders = count($this->fs->where(
                'staff',
                [['schoolId', '==', $this->school_id], ['staff_roles', 'array-contains', $rid]],
                null, 'ASC', 50
            ));
        } catch (\Throwable $e) {
            try {
                foreach ($this->fs->where('staff', [['schoolId', '==', $this->school_id]], null, 'ASC', 5000) as $doc) {
                    $d = $doc['data'] ?? $doc;
                    if (is_array($d) && in_array($rid, $this->_staff_role_ids($d), true)) $holders++;
                }
            } catch (\Throwable $e2) {
                $this->json_error('Could not verify role usage right now. Please try again.', 503);
                return;
            }
        }
        if ($holders > 0) {
            $this->json_error("Can't delete — {$holders} staff member(s) still hold this role. Remove it from them first.", 409);
            return;
        }

        $label = (string) ($staffRoles[$rid]['label'] ?? $rid);
        unset($staffRoles[$rid]);
        $departments = is_array($school['departments'] ?? null) ? $school['departments'] : [];
        $this->_rebuild_role_ids($departments, $staffRoles);   // drop the role from its dept
        if (!$this->fs->update('schools', $this->school_id, ['staffRoles' => $staffRoles, 'departments' => $departments])) {
            $this->json_error('Could not delete the role. Please try again.', 500);
            return;
        }
        log_audit('Staff_access', 'delete_role', $rid, "Deleted role '{$label}'");
        $this->json_success(['deleted' => $rid]);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  TAB 3 — STAFF ACCESS
    // ═════════════════════════════════════════════════════════════════════

    /** Directory: every staff member with roles + an effective-module count. */
    public function get_staff_directory(): void
    {
        $this->_require_role(self::ADMIN_ROLES, 'staff_access_dir', 'Admin Users', 'view');
        header('Content-Type: application/json');

        $school     = $this->fs->get('schools', $this->school_id);
        $staffRoles = is_array($school['staffRoles'] ?? null) ? $school['staffRoles'] : [];

        $rows = [];
        try {
            foreach ($this->fs->where('staff', [['schoolId', '==', $this->school_id]], null, 'ASC', 2000) as $doc) {
                $d = $doc['data'] ?? $doc; if (!is_array($d)) continue;
                $id = (string) ($d['staffId'] ?? ($doc['id'] ?? ''));
                if ($id === '') continue;
                $eff = $this->_effective_inmem($staffRoles, $d);   // no extra reads
                $rows[] = [
                    'id'         => $id,
                    'name'       => (string) ($d['Name'] ?? $d['name'] ?? $id),
                    'department' => (string) ($d['Department'] ?? $d['department'] ?? ''),
                    'roles'      => $this->_staff_role_ids($d),
                    'primary'    => (string) ($d['primary_role'] ?? ''),
                    'status'     => strtolower((string) ($d['Status'] ?? $d['status'] ?? 'Active')),
                    'modules'    => $eff['modules'],
                ];
            }
        } catch (\Throwable $e) {
            log_message('error', 'Staff_access::get_staff_directory — ' . $e->getMessage());
        }
        usort($rows, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
        $this->json_success(['staff' => $rows]);
    }

    /** One staff member: roles, primary, extra, deny + AUTHORITATIVE effective access. */
    public function get_staff_detail(string $staffId = ''): void
    {
        $this->_require_role(self::ADMIN_ROLES, 'staff_access_detail', 'Admin Users', 'view');
        header('Content-Type: application/json');
        $staffId = $this->_safe_id($staffId);
        if ($staffId === '') { $this->json_error('Staff id required.'); return; }

        $staff = $this->fs->getEntity('staff', $staffId);
        if (!is_array($staff) || empty($staff)) { $this->json_error('Staff member not found.', 404); return; }

        $eff = resolve_effective_access($this->firebase, $this->school_id, $staff); // authoritative
        $this->json_success([
            'staff' => [
                'id'         => $staffId,
                'name'       => (string) ($staff['Name'] ?? $staff['name'] ?? $staffId),
                'department' => (string) ($staff['Department'] ?? $staff['department'] ?? ''),
                'status'     => strtolower((string) ($staff['Status'] ?? $staff['status'] ?? 'Active')),
            ],
            'roles'     => $this->_staff_role_ids($staff),
            'primary'   => (string) ($staff['primary_role'] ?? ''),
            'extra'     => (object) (is_array($staff['extra'] ?? null) ? $staff['extra'] : []),
            'deny'      => (object) (is_array($staff['deny']  ?? null) ? $staff['deny']  : []),
            'effective' => ['modules' => $eff['modules'], 'levels' => (object) $eff['levels']],
        ]);
    }

    /** Add or remove a role on a staff member. */
    public function toggle_staff_role(): void
    {
        $this->_require_role(self::ADMIN_ROLES, 'staff_access_toggle_role', 'Admin Users', 'manage');
        header('Content-Type: application/json');
        $staffId = $this->_safe_id((string) $this->input->post('staff_id', true));
        $rid     = trim((string) ($this->input->post('role_id', true) ?? ''));
        $on      = $this->input->post('on', true) === '1';
        if ($staffId === '' || $rid === '') { $this->json_error('Staff and role required.'); return; }

        $staff = $this->fs->getEntity('staff', $staffId);
        if (!is_array($staff) || empty($staff)) { $this->json_error('Staff member not found.', 404); return; }

        // SECURITY (audit C2): block self-escalation + over-ceiling role grants.
        // Only enforced when ADDING a role ($on); removal stays open so a mistaken
        // grant is always reversible by any admin.
        if ($on) {
            if ($err = $this->_escalation_block($staffId, $rid)) { $this->json_error($err, 403); return; }
        }

        // Guard: the auto-assigned baseline can't be removed.
        if ($rid === 'ROLE_BASELINE_APP' && !$on) { $this->json_error('The App Baseline role is automatic and cannot be removed.', 403); return; }

        // SECURITY (mirror onboard_staff / save_role): only a REAL catalogue role may
        // be assigned. Without this, an arbitrary $rid (e.g. the raw label "Super
        // Admin") would be written into staff_roles[], and the resolver's bypass-label
        // match (RBAC_BYPASS_ROLES, both PHP and the caps CF) would silently grant full
        // access — a privilege-escalation path the role-CREATION guard blocked but this
        // assignment path did not. Removal is exempt so stale/invalid ids stay cleanable.
        if ($on && $rid !== 'ROLE_BASELINE_APP') {
            ensure_unified_roles($this->fs, $this->school_id);
            $school     = $this->fs->get('schools', $this->school_id);
            $staffRoles = is_array($school['staffRoles'] ?? null) ? $school['staffRoles'] : [];
            if (!isset($staffRoles[$rid]) || !is_array($staffRoles[$rid])) {
                $this->json_error('Unknown role. Assign a role that exists in the catalogue.');
                return;
            }
        }

        $roles = $this->_staff_role_ids($staff);
        if ($on) { if (!in_array($rid, $roles, true)) $roles[] = $rid; }
        else     { $roles = array_values(array_diff($roles, [$rid])); }

        $update = ['staff_roles' => $roles];
        // If we removed the primary, reassign it to the first remaining non-baseline
        // role (matches the prototype), else clear it.
        if (!$on && (string) ($staff['primary_role'] ?? '') === $rid) {
            $next = '';
            foreach ($roles as $r) { if ($r !== 'ROLE_BASELINE_APP') { $next = $r; break; } }
            $update['primary_role'] = $next;
        }
        if (!$this->_save_staff($staffId, $update)) { $this->json_error('Could not save. Try again.', 500); return; }
        // If removing the current primary reassigned it, propagate the new primary
        // label to the Firebase claim (profile / login / rules role follow it).
        if (!empty($update['primary_role'])) { $this->_remint_primary_role_claim($staffId, (string) $update['primary_role']); }
        log_audit('Staff_access', 'toggle_staff_role', $staffId, ($on ? '+' : '-') . $rid);
        $this->_emit_detail($staffId);
    }

    /** Set the primary role (must be one the staff already holds). */
    public function set_primary_role(): void
    {
        $this->_require_role(self::ADMIN_ROLES, 'staff_access_primary', 'Admin Users', 'manage');
        header('Content-Type: application/json');
        $staffId = $this->_safe_id((string) $this->input->post('staff_id', true));
        $rid     = trim((string) ($this->input->post('role_id', true) ?? ''));
        if ($staffId === '' || $rid === '') { $this->json_error('Staff and role required.'); return; }

        $staff = $this->fs->getEntity('staff', $staffId);
        if (!is_array($staff) || empty($staff)) { $this->json_error('Staff member not found.', 404); return; }
        if (!in_array($rid, $this->_staff_role_ids($staff), true)) { $this->json_error('Assign the role before making it primary.'); return; }

        if (!$this->_save_staff($staffId, ['primary_role' => $rid])) { $this->json_error('Could not save. Try again.', 500); return; }
        // The primary role IS the claim role — propagate the new label to the Firebase
        // claim so profile / login / Firestore-rules role follow it (next login).
        $this->_remint_primary_role_claim($staffId, $rid);
        log_audit('Staff_access', 'set_primary_role', $staffId, $rid);
        $this->_emit_detail($staffId);
    }

    /** Add a per-person EXTRA grant (module + level + reason). */
    public function add_extra(): void
    {
        $this->_require_role(self::ADMIN_ROLES, 'staff_access_add_extra', 'Admin Users', 'manage');
        header('Content-Type: application/json');
        $staffId = $this->_safe_id((string) $this->input->post('staff_id', true));
        $module  = trim((string) ($this->input->post('module', true) ?? ''));
        $level   = trim((string) ($this->input->post('level', true) ?? 'view'));
        $reason  = trim((string) ($this->input->post('reason', true) ?? ''));
        if ($staffId === '' || $module === '')          { $this->json_error('Staff and module required.'); return; }
        if (!in_array($module, RBAC_MODULES, true))     { $this->json_error('Unknown module.'); return; }
        if (!in_array($level, ['view', 'edit', 'manage'], true)) $level = 'view';

        $staff = $this->fs->getEntity('staff', $staffId);
        if (!is_array($staff) || empty($staff)) { $this->json_error('Staff member not found.', 404); return; }
        // SECURITY (audit C2): an admin cannot grant an extra module to THEMSELF
        // (self-escalation). Granting others is unchanged.
        if ($err = $this->_escalation_block($staffId)) { $this->json_error($err, 403); return; }
        // A prohibition would override the grant anyway — refuse rather than store a dead grant.
        $deny = is_array($staff['deny'] ?? null) ? $staff['deny'] : [];
        if (isset($deny[$module])) { $this->json_error("'{$module}' is prohibited for this person. Remove the prohibition first.", 409); return; }
        $extra = is_array($staff['extra'] ?? null) ? $staff['extra'] : [];
        $extra[$module] = ['level' => $level, 'by' => (string) $this->admin_id, 'at' => date('Y-m-d'), 'reason' => $reason];

        if (!$this->_save_staff($staffId, ['extra' => $extra])) { $this->json_error('Could not save. Try again.', 500); return; }
        log_audit('Staff_access', 'add_extra', $staffId, "{$module}={$level}");
        $this->_emit_detail($staffId);
    }

    public function remove_extra(): void
    {
        $this->_require_role(self::ADMIN_ROLES, 'staff_access_rm_extra', 'Admin Users', 'manage');
        header('Content-Type: application/json');
        $staffId = $this->_safe_id((string) $this->input->post('staff_id', true));
        $module  = trim((string) ($this->input->post('module', true) ?? ''));
        if ($staffId === '' || $module === '') { $this->json_error('Staff and module required.'); return; }
        $staff = $this->fs->getEntity('staff', $staffId);
        if (!is_array($staff) || empty($staff)) { $this->json_error('Staff member not found.', 404); return; }
        $extra = is_array($staff['extra'] ?? null) ? $staff['extra'] : [];
        unset($extra[$module]);
        if (!$this->_save_staff($staffId, ['extra' => $extra])) { $this->json_error('Could not save. Try again.', 500); return; }
        log_audit('Staff_access', 'remove_extra', $staffId, $module);
        $this->_emit_detail($staffId);
    }

    /** Add a per-person PROHIBIT (absolute deny, wins over roles). */
    public function add_deny(): void
    {
        $this->_require_role(self::ADMIN_ROLES, 'staff_access_add_deny', 'Admin Users', 'manage');
        header('Content-Type: application/json');
        $staffId = $this->_safe_id((string) $this->input->post('staff_id', true));
        $module  = trim((string) ($this->input->post('module', true) ?? ''));
        $reason  = trim((string) ($this->input->post('reason', true) ?? ''));
        if ($staffId === '' || $module === '')      { $this->json_error('Staff and module required.'); return; }
        if (!in_array($module, RBAC_MODULES, true)) { $this->json_error('Unknown module.'); return; }

        $staff = $this->fs->getEntity('staff', $staffId);
        if (!is_array($staff) || empty($staff)) { $this->json_error('Staff member not found.', 404); return; }
        $deny = is_array($staff['deny'] ?? null) ? $staff['deny'] : [];
        $deny[$module] = ['by' => (string) $this->admin_id, 'at' => date('Y-m-d'), 'reason' => $reason];
        $patch = ['deny' => $deny];
        // A prohibition supersedes any extra grant — clear the now-dead grant too.
        $extra = is_array($staff['extra'] ?? null) ? $staff['extra'] : [];
        if (isset($extra[$module])) { unset($extra[$module]); $patch['extra'] = $extra; }
        if (!$this->_save_staff($staffId, $patch)) { $this->json_error('Could not save. Try again.', 500); return; }
        log_audit('Staff_access', 'add_deny', $staffId, $module);
        $this->_emit_detail($staffId);
    }

    public function remove_deny(): void
    {
        $this->_require_role(self::ADMIN_ROLES, 'staff_access_rm_deny', 'Admin Users', 'manage');
        header('Content-Type: application/json');
        $staffId = $this->_safe_id((string) $this->input->post('staff_id', true));
        $module  = trim((string) ($this->input->post('module', true) ?? ''));
        if ($staffId === '' || $module === '') { $this->json_error('Staff and module required.'); return; }
        $staff = $this->fs->getEntity('staff', $staffId);
        if (!is_array($staff) || empty($staff)) { $this->json_error('Staff member not found.', 404); return; }
        $deny = is_array($staff['deny'] ?? null) ? $staff['deny'] : [];
        unset($deny[$module]);
        if (!$this->_save_staff($staffId, ['deny' => $deny])) { $this->json_error('Could not save. Try again.', 500); return; }
        log_audit('Staff_access', 'remove_deny', $staffId, $module);
        $this->_emit_detail($staffId);
    }

    /**
     * Onboard a new staff member (Tab 3 "Onboard staff"). Creates a real record:
     * mints an STA id, a Firebase Auth login (temp password + mustChangePassword),
     * canonical claims, and a staff doc with [ROLE_BASELINE_APP, chosen role].
     */
    public function onboard_staff(): void
    {
        $this->_require_role(self::ADMIN_ROLES, 'staff_access_onboard', 'Admin Users', 'manage');
        header('Content-Type: application/json');

        $name = trim((string) ($this->input->post('name', true) ?? ''));
        $dept = trim((string) ($this->input->post('department', true) ?? ''));
        $rid  = trim((string) ($this->input->post('role_id', true) ?? ''));
        if ($name === '')          { $this->json_error('Full name is required.'); return; }
        if (mb_strlen($name) > 100) { $this->json_error('Name must be 100 characters or less.'); return; }

        ensure_unified_roles($this->fs, $this->school_id);
        $school     = $this->fs->get('schools', $this->school_id);
        $staffRoles = is_array($school['staffRoles'] ?? null) ? $school['staffRoles'] : [];
        // Resolve the chosen role: must be a real, non-auto catalogue role.
        $roleLabel = '';
        if ($rid !== '') {
            if (!isset($staffRoles[$rid]) || !is_array($staffRoles[$rid])) { $this->json_error('Unknown role.'); return; }
            $roleLabel = (string) ($staffRoles[$rid]['label'] ?? $rid);
        }

        $this->load->library('id_generator');
        $sta = $this->id_generator->generate('STA');
        if ($sta === null) { $this->json_error('Could not allocate a staff ID. Please retry.'); return; }
        $seq = (int) preg_replace('/^[A-Z]+/', '', $sta);

        // Firebase Auth login (temp password; person resets on first login).
        $tmp = 'Aa1' . bin2hex(random_bytes(9));
        try {
            $created = $this->firebase->createFirebaseUser(Firebase::authEmail($sta), $tmp, ['uid' => $sta, 'displayName' => $name]);
        } catch (\Throwable $e) { $created = null; }
        if (!$created) { $this->id_generator->releaseClaim('STA', $seq); $this->json_error('Could not create the login account. Please retry.', 500); return; }

        // Atomicity: this sits between a created Auth user and the staff-doc write. If
        // it throws uncaught, we'd orphan the login + burn the STA id. Compensate.
        try {
            $this->firebase->setCanonicalClaims($sta, [
                'role'          => $roleLabel !== '' ? $roleLabel : 'Staff',
                'role_fallback' => 'Staff',
                'school_id'     => $this->school_id,
                'school_code'   => $this->school_code,
                'parent_db_key' => $this->parent_db_key,
                'extra'         => ['must_change_password' => true],
            ]);
        } catch (\Throwable $e) {
            try { $this->firebase->deleteFirebaseUser($sta); } catch (\Throwable $e2) {}
            $this->id_generator->releaseClaim('STA', $seq);
            log_message('error', 'Staff_access::onboard_staff setCanonicalClaims — ' . $e->getMessage());
            $this->json_error('Could not finalize the login account. Please retry.', 500);
            return;
        }

        $roles = array_values(array_unique(array_filter(['ROLE_BASELINE_APP', $rid])));
        $written = false;
        try {
            $this->fs->saveStaff($sta, ['Name' => $name, 'Role' => $roleLabel, 'Department' => $dept, 'Status' => 'Active']);
            $written = $this->fs->set('staff', $this->fs->docId($sta), [
                'staff_roles'        => $roles,
                'primary_role'       => $rid,
                'department'         => $dept,
                'mustChangePassword' => true,
                'created_by'         => (string) $this->admin_id,
                'createdAt'          => date('c'),
            ], true);
        } catch (\Throwable $e) { $written = false; }
        if ($written === false) {
            // M6: full, idempotent compensation. `saveStaff` (write #1) may already
            // have created staff/{schoolId}_{sta} before the second `set` failed, so
            // deleting only the Auth user + claim would orphan that doc. Delete the
            // staff doc too (remove is idempotent — a no-op if it was never written).
            try { $this->fs->remove('staff', $this->fs->docId($sta)); } catch (\Throwable $e) {}
            try { $this->firebase->deleteFirebaseUser($sta); } catch (\Throwable $e) {}
            $this->id_generator->releaseClaim('STA', $seq);
            $this->json_error('Could not save the staff record. Please retry.', 500);
            return;
        }
        log_audit('Staff_access', 'onboard_staff', $sta, "Onboarded '{$name}' as {$roleLabel}");
        $this->json_success(['staff_id' => $sta]);
    }

    // ── Tab-3 internals ──────────────────────────────────────────────────

    /**
     * SECURITY (audit C2) — anti privilege-escalation guard for the per-person
     * access mutation endpoints (toggle_staff_role / add_extra). Returns an error
     * string to reject with, or null if allowed. Two rules, both skipped for the
     * god roles (Super / School Super Admin), who are already unrestricted:
     *
     *   1. SELF-EDIT BLOCK — a caller may never modify their OWN access record.
     *      Without this, anyone holding 'Admin Users' manage could assign THEMSELF
     *      ROLE_ADMIN (a real catalogue role, so the existing catalogue check
     *      passes) → caps admin:true → rules isAdmin() → full god-mode. Admins
     *      manage OTHERS; self-changes go through a higher authority.
     *   2. PRIVILEGE CEILING — when assigning a role, its tier may not be higher
     *      (numerically lower) than the caller's own role tier, so an admin can't
     *      hand a peer a more-privileged role than they themselves hold. Reserved
     *      bypass labels are refused outright. Mirrors AdminUsers::_role_assignment_error.
     *
     * @param string $rid  Optional catalogue role id being assigned (ceiling check).
     */
    private function _escalation_block(string $staffId, string $rid = ''): ?string
    {
        $callerRole = (string) ($this->admin_role ?? '');
        if (strcasecmp($callerRole, 'Super Admin') === 0
            || strcasecmp($callerRole, 'School Super Admin') === 0) {
            return null; // god roles unrestricted
        }

        // (1) Self-edit block — compare against the caller's own staff/login id.
        if ($staffId !== '' && (string) $this->admin_id !== ''
            && $staffId === (string) $this->admin_id) {
            return 'You cannot change your own access. Ask another administrator.';
        }

        // (2) Privilege ceiling on the role being assigned.
        if ($rid !== '') {
            $school     = $this->fs->get('schools', $this->school_id);
            $staffRoles = (is_array($school) && is_array($school['staffRoles'] ?? null)) ? $school['staffRoles'] : [];
            $roleEntry  = is_array($staffRoles[$rid] ?? null) ? $staffRoles[$rid] : null;

            // Never assignable from here: reserved bypass labels.
            $roleLabel  = strtolower((string) ($roleEntry['label'] ?? ''));
            if (in_array($roleLabel, ['super admin', 'school super admin'], true)) {
                return "The role '{$roleEntry['label']}' cannot be assigned from here.";
            }

            $roleTier   = $roleEntry ? (int) ($roleEntry['tier'] ?? 7) : 7;
            $callerTier = 7;
            foreach ($staffRoles as $rEntry) {
                if (is_array($rEntry) && strcasecmp((string) ($rEntry['label'] ?? ''), $callerRole) === 0) {
                    $callerTier = (int) ($rEntry['tier'] ?? 7);
                    break;
                }
            }
            if ($roleTier < $callerTier) {
                return 'You cannot assign a role with higher privileges than your own.';
            }
        }
        return null;
    }

    /**
     * SECURITY (audit C2 remainder) — ceiling guard for ROLE-CONTENT editing
     * (save_role / save_role_access / set_role_level). The person-assignment guard
     * (_escalation_block) stops self-ASSIGNING a high role; this stops the other
     * self-escalation route: WIDENING a role's own modules/levels. Returns an error
     * string to reject with, or null if allowed. God roles (Super / School Super
     * Admin) are exempt. Three layered checks for a non-god caller:
     *   (a) never edit the Administrator / bypass-label roles;
     *   (b) never edit a role the caller THEMSELVES holds (direct self-widening);
     *   (c) tier ceiling — when the caller's tier is RELIABLY known in the catalogue,
     *       may only touch roles STRICTLY less privileged (higher tier number) than
     *       their own, and (create/retier) may not set a role at/above their tier.
     *       Skipped when the caller's tier can't be resolved, so an unmatched claim
     *       label can't over-block legitimate edits.
     *
     * @param array       $staffRoles the already-loaded catalogue (no extra read)
     * @param string      $rid        target role id ('' on create)
     * @param int|null    $newTier    submitted tier on create/retier
     */
    private function _role_ceiling_block(array $staffRoles, string $rid, ?int $newTier = null): ?string
    {
        // Delegates to the shared rbac_role_edit_error() (rbac_helper) so Staff_access,
        // Org and AdminUsers all enforce ONE identical role-editing ceiling — no drift.
        return rbac_role_edit_error($staffRoles, $rid, $newTier);
    }

    /**
     * Propagate a staff member's PRIMARY role to their Firebase custom claim — the
     * `role` LABEL that drives their profile display (top-right), admin-panel login
     * resolution, and the Firestore rules `token.role` gates. Without this, changing
     * a role in Staff & Roles updated only the staff doc + caps mirror, so the
     * member's profile and rules-role stayed on the OLD label.
     *
     * setCanonicalClaims() REPLACES the whole claim set, so we rebuild the FULL
     * canonical context — PRESERVING staffId (owner-filters + staffCapabilities
     * lookup) and any pending must_change_password — and change only the role label
     * + tier. Best-effort; takes effect on the member's NEXT login / token refresh
     * (custom claims never mutate an already-issued token — so a logged-in user sees
     * the new role after re-login).
     */
    private function _remint_primary_role_claim(string $staffId, string $rid): void
    {
        if ($staffId === '' || $rid === '' || $rid === 'ROLE_BASELINE_APP') { return; }
        try {
            $school     = $this->fs->get('schools', $this->school_id);
            $staffRoles = (is_array($school) && is_array($school['staffRoles'] ?? null)) ? $school['staffRoles'] : [];
            $entry = is_array($staffRoles[$rid] ?? null) ? $staffRoles[$rid] : null;
            $label = (string) ($entry['label'] ?? '');
            if ($label === '') { return; } // unknown role → never blank an existing claim

            $extra = [];
            $mcp = $this->firebase->getCustomClaim($staffId, 'must_change_password', null);
            if ($mcp !== null) { $extra['must_change_password'] = $mcp; }

            $this->firebase->setCanonicalClaims($staffId, [
                'role'          => $label,
                'role_label'    => $label,
                'school_id'     => $this->school_id,
                'school_code'   => $this->school_code,
                'parent_db_key' => $this->parent_db_key,
                'staff_id'      => $staffId,                       // PRESERVE — critical
                'role_tier'     => (string) ($entry['tier'] ?? ''),
                'extra'         => $extra,
            ]);
            log_audit('Staff_access', 'remint_role_claim', $staffId, "primary role → {$label}");
        } catch (\Throwable $e) {
            log_message('error', 'Staff_access::_remint_primary_role_claim failed: ' . $e->getMessage());
        }
    }

    /** Persist a partial staff update; the CF onStaffRolesChanged rebuilds caps. */
    private function _save_staff(string $staffId, array $patch): bool
    {
        return $this->fs->updateEntity('staff', $staffId, $patch);
    }

    /** Re-read + emit the fresh detail payload (so the UI reflects the write). */
    private function _emit_detail(string $staffId): void
    {
        $staff = $this->fs->getEntity('staff', $staffId) ?: [];
        $eff   = resolve_effective_access($this->firebase, $this->school_id, is_array($staff) ? $staff : []);
        $this->json_success([
            'roles'     => $this->_staff_role_ids($staff),
            'primary'   => (string) ($staff['primary_role'] ?? ''),
            'extra'     => (object) (is_array($staff['extra'] ?? null) ? $staff['extra'] : []),
            'deny'      => (object) (is_array($staff['deny']  ?? null) ? $staff['deny']  : []),
            'effective' => ['modules' => $eff['modules'], 'levels' => (object) $eff['levels']],
        ]);
    }

    /** Union of a staff doc's role-id fields. */
    private function _staff_role_ids(array $staff): array
    {
        foreach (['staff_roles', 'staffRoles', 'roles', 'role_ids'] as $k) {
            if (is_array($staff[$k] ?? null)) return array_values(array_filter(array_map('strval', $staff[$k])));
        }
        return [];
    }

    /** Effective access computed from an ALREADY-LOADED catalogue (no extra reads) — for the directory. */
    private function _effective_inmem(array $staffRoles, array $staff): array
    {
        $roles   = $this->_staff_role_ids($staff);
        if (!empty($staff['primary_role'])) $roles[] = (string) $staff['primary_role'];
        $modules = [];
        $levels  = [];
        foreach (array_unique($roles) as $ref) {
            // bypass label ⇒ everything
            if (in_array($ref, RBAC_BYPASS_ROLES, true)) {
                return ['modules' => RBAC_MODULES, 'levels' => array_fill_keys(RBAC_MODULES, 'manage')];
            }
            $r = $staffRoles[$ref] ?? null;
            if (!$r) { foreach ($staffRoles as $rid => $x) { if (is_array($x) && (string) ($x['label'] ?? '') === $ref) { $r = $x; break; } } }
            if (!is_array($r)) continue;
            $lv = is_array($r['permissionLevels'] ?? null) ? $r['permissionLevels'] : [];
            foreach (array_intersect((array) ($r['permissions'] ?? []), RBAC_MODULES) as $m) {
                $modules[$m] = true;
                $cand = isset(RBAC_LEVELS[$lv[$m] ?? '']) ? $lv[$m] : 'manage';
                $levels[$m] = isset($levels[$m]) ? rbac_max_level($levels[$m], $cand) : $cand;
            }
        }
        $extra = is_array($staff['extra'] ?? null) ? $staff['extra'] : [];
        $deny  = is_array($staff['deny']  ?? null) ? $staff['deny']  : [];
        return apply_access_overrides(['modules' => array_keys($modules), 'levels' => $levels], $extra, $deny);
    }

    /** Sanitise a staff id from input. */
    private function _safe_id(string $id): string
    {
        return preg_match('/^[A-Za-z0-9_]{1,40}$/', $id) ? $id : '';
    }

    // ═════════════════════════════════════════════════════════════════════
    //  DEPARTMENTS (configurable org units — the role grouping + staff placement)
    //  Stored in schools.departments (shared with HR/Import); role↔dept is the
    //  role's `department` NAME. role_ids[] kept in sync by name for Import.
    // ═════════════════════════════════════════════════════════════════════

    /** Create or rename a department. */
    public function save_department(): void
    {
        $this->_require_role(self::ADMIN_ROLES, 'staff_access_save_dept', 'Admin Users', 'manage');
        header('Content-Type: application/json');
        $did  = trim((string) ($this->input->post('dept_id', true) ?? ''));
        $name = trim((string) ($this->input->post('name', true) ?? ''));
        $desc = trim((string) ($this->input->post('description', true) ?? ''));
        if ($name === '')          { $this->json_error('Department name is required.'); return; }
        if (mb_strlen($name) > 60) { $this->json_error('Name must be 60 characters or less.'); return; }

        [$school, $departments, $staffRoles] = $this->_dept_state();
        // Duplicate-name guard (excluding self).
        foreach ($departments as $id => $d) {
            if ($id !== $did && dept_key($d['name'] ?? '') === dept_key($name)) { $this->json_error('A department with that name already exists.'); return; }
        }

        if ($did === '') {
            // CREATE — new DEPT_ id (distinct from HR's numeric counter → no clash).
            $maxSeq = 0;
            foreach (array_keys($departments) as $id) { if (preg_match('/(\d+)$/', $id, $m)) $maxSeq = max($maxSeq, (int) $m[1]); }
            $did = sprintf('DEPT_%04d', $maxSeq + 1);
            $departments[$did] = ['name' => $name, 'description' => $desc, 'status' => 'Active',
                'sort_order' => count($departments) + 1, 'role_ids' => [], 'created_at' => date('c')];
        } else {
            if (!isset($departments[$did]) || !is_array($departments[$did])) { $this->json_error('Department not found.'); return; }
            $oldName = (string) ($departments[$did]['name'] ?? '');
            $departments[$did]['name']        = $name;
            $departments[$did]['description']  = $desc;
            $departments[$did]['updated_at']   = date('c');
            // RENAME cascade: repoint every role + staff that referenced the old name.
            if (dept_key($oldName) !== dept_key($name)) {
                foreach ($staffRoles as $rid => $r) {
                    if (is_array($r) && dept_key($r['department'] ?? '') === dept_key($oldName)) $staffRoles[$rid]['department'] = $name;
                }
                $this->_cascade_staff_department($oldName, $name);
            }
        }
        $this->_rebuild_role_ids($departments, $staffRoles);
        if (!$this->fs->update('schools', $this->school_id, ['departments' => $departments, 'staffRoles' => $staffRoles])) {
            $this->json_error('Could not save the department. Please try again.', 500); return;
        }
        log_audit('Staff_access', 'save_department', $did, "Saved department '{$name}'");
        $this->json_success(['department' => $this->_dept_out($did, $departments[$did])]);
    }

    /** Delete a department. Blocked while roles are assigned to it. */
    public function delete_department(): void
    {
        $this->_require_role(self::ADMIN_ROLES, 'staff_access_del_dept', 'Admin Users', 'manage');
        header('Content-Type: application/json');
        $did = trim((string) ($this->input->post('dept_id', true) ?? ''));
        if ($did === '') { $this->json_error('Department id required.'); return; }

        [$school, $departments, $staffRoles] = $this->_dept_state();
        if (!isset($departments[$did])) { $this->json_error('Department not found.'); return; }
        $name = (string) ($departments[$did]['name'] ?? '');
        if (!empty($departments[$did]['is_system'])) { $this->json_error('System departments cannot be deleted.', 403); return; }

        $held = 0;
        foreach ($staffRoles as $r) { if (is_array($r) && dept_key($r['department'] ?? '') === dept_key($name)) $held++; }
        if ($held > 0) { $this->json_error("Can't delete — {$held} role(s) are in this department. Move them first.", 409); return; }

        unset($departments[$did]);
        $this->_rebuild_role_ids($departments, $staffRoles);
        if (!$this->fs->update('schools', $this->school_id, ['departments' => $departments])) {
            $this->json_error('Could not delete the department.', 500); return;
        }
        log_audit('Staff_access', 'delete_department', $did, "Deleted department '{$name}'");
        $this->json_success(['deleted' => $did]);
    }

    /** Reorder departments (array of dept ids in the new order). */
    public function reorder_departments(): void
    {
        $this->_require_role(self::ADMIN_ROLES, 'staff_access_reorder_dept', 'Admin Users', 'manage');
        header('Content-Type: application/json');
        $order = json_decode((string) ($this->input->post('order', true) ?? ''), true);
        if (!is_array($order)) { $this->json_error('Invalid order.'); return; }
        [$school, $departments] = $this->_dept_state();
        $i = 0;
        foreach ($order as $did) { if (isset($departments[$did])) { $departments[$did]['sort_order'] = ++$i; } }
        if (!$this->fs->update('schools', $this->school_id, ['departments' => $departments])) {
            $this->json_error('Could not save the new order. Please try again.', 500);
            return;
        }
        log_audit('Staff_access', 'reorder_departments', '', 'Reordered departments');
        $this->json_success(['ok' => true]);
    }

    /**
     * Move a role into a different department (drag-and-drop in Tab 1). Sets
     * role.department and rebuilds every department's role_ids[]. Passing an empty
     * department unassigns the role. Returns the updated role + fresh department set.
     */
    public function move_role(): void
    {
        $this->_require_role(self::ADMIN_ROLES, 'staff_access_move_role', 'Admin Users', 'manage');
        header('Content-Type: application/json');
        $rid  = trim((string) ($this->input->post('role_id', true) ?? ''));
        $dept = trim((string) ($this->input->post('department', true) ?? ''));
        if ($rid === '') { $this->json_error('Role id is required.'); return; }

        [$school, $departments, $staffRoles] = $this->_dept_state();
        if (!isset($staffRoles[$rid]) || !is_array($staffRoles[$rid])) { $this->json_error('Role not found.'); return; }

        // Resolve to the canonical department name (case-insensitive). Empty = unassign.
        if ($dept !== '') {
            $match = null;
            foreach ($departments as $d) { if (dept_key($d['name'] ?? '') === dept_key($dept)) { $match = (string) $d['name']; break; } }
            if ($match === null) { $this->json_error('Target department not found.'); return; }
            $dept = $match;
        }

        if (dept_key((string) ($staffRoles[$rid]['department'] ?? '')) !== dept_key($dept)) {
            $staffRoles[$rid]['department'] = $dept;
            $this->_rebuild_role_ids($departments, $staffRoles);
            if (!$this->fs->update('schools', $this->school_id, ['departments' => $departments, 'staffRoles' => $staffRoles])) {
                $this->json_error('Could not move the role. Please try again.', 500); return;
            }
            log_audit('Staff_access', 'move_role', $rid, "Moved role to '" . ($dept ?: 'Unassigned') . "'");
        }

        $out = [];
        foreach ($departments as $id => $d) { $out[] = $this->_dept_out($id, $d); }
        usort($out, static fn($a, $b) => ($a['sort_order'] <=> $b['sort_order']) ?: strcasecmp($a['name'], $b['name']));
        $this->json_success(['role' => $this->_role_out($rid, $staffRoles[$rid]), 'departments' => $out]);
    }

    // ── Department internals ─────────────────────────────────────────────

    /** Read school + departments + staffRoles, seeding role.department + dept set on first use. */
    private function _dept_state(): array
    {
        ensure_unified_roles($this->fs, $this->school_id);
        $school      = $this->fs->get('schools', $this->school_id);
        $staffRoles  = is_array($school['staffRoles'] ?? null) ? $school['staffRoles'] : [];
        $departments = is_array($school['departments'] ?? null) ? $school['departments'] : [];
        $seeded = $this->_seed_departments($departments, $staffRoles);
        return [$school, $departments, $staffRoles, $seeded];
    }

    /** Seed role.department (= category) + ensure a department exists per value.
     *  Mutates by ref; returns TRUE if a structural change was made (→ persist). */
    private function _seed_departments(array &$departments, array &$staffRoles): bool
    {
        $changed = false;
        foreach ($staffRoles as $rid => $r) {
            if (!is_array($r)) continue;
            if (!isset($r['department']) || trim((string) $r['department']) === '') {
                $staffRoles[$rid]['department'] = trim((string) ($r['category'] ?? '')) ?: 'Administrative';
                $changed = true;
            }
        }
        $have = [];
        foreach ($departments as $d) { $have[dept_key($d['name'] ?? '')] = true; }
        $maxSeq = 0;
        foreach (array_keys($departments) as $id) { if (preg_match('/(\d+)$/', $id, $m)) $maxSeq = max($maxSeq, (int) $m[1]); }
        $seen = [];
        foreach ($staffRoles as $r) { if (is_array($r)) { $nm = trim((string) ($r['department'] ?? '')); if ($nm !== '') $seen[dept_key($nm)] = $nm; } }
        foreach ($seen as $k => $nm) {
            if (isset($have[$k])) continue;
            $maxSeq++;
            $departments[sprintf('DEPT_%04d', $maxSeq)] = ['name' => $nm, 'description' => '', 'status' => 'Active',
                'sort_order' => $maxSeq, 'role_ids' => [], 'is_system' => $this->_is_system_dept_name($nm)];
            $have[$k] = true;
            $changed = true;
        }
        // Backfill is_system on the canonical category departments (protected from
        // deletion, like system roles). Additive only — never clears the flag.
        foreach ($departments as $did => $d) {
            if (empty($d['is_system']) && $this->_is_system_dept_name((string) ($d['name'] ?? ''))) {
                $departments[$did]['is_system'] = true;
                $changed = true;
            }
        }
        $this->_rebuild_role_ids($departments, $staffRoles);
        return $changed;
    }

    /** A department is "system" (undeletable) when its name is a canonical category. */
    private function _is_system_dept_name(string $name): bool
    {
        $k = dept_key($name);
        foreach (self::ROLE_CATEGORIES as $c) { if (dept_key($c) === $k) return true; }
        return false;
    }

    /**
     * Rebuild each department's role_ids[] from role.department (for Import). Mutates by ref.
     *
     * M5 NOTE: every dept mutation endpoint (save_department / delete_department /
     * reorder_departments / move_role / delete_role) calls this and then persists the
     * WHOLE `departments` (and often `staffRoles`) map — a read-modify-write. Because
     * role_ids is recomputed across ALL departments here, a field-scoped nested PATCH
     * can't safely replace it, so these paths keep the whole-map write and thus a
     * last-writer-wins race between concurrent admins. Accepted as best-effort: dept
     * edits are infrequent and admin-only. Only the per-role access matrix writes
     * (set_role_level / save_role_access) were converted to field-scoped writes.
     */
    private function _rebuild_role_ids(array &$departments, array $staffRoles): void
    {
        foreach ($departments as $did => $d) {
            $nm = dept_key($d['name'] ?? '');
            $rids = [];
            foreach ($staffRoles as $rid => $r) { if (is_array($r) && dept_key($r['department'] ?? '') === $nm) $rids[] = (string) $rid; }
            $departments[$did]['role_ids'] = $rids;
        }
    }

    /** Repoint staff.department on a department rename (best-effort scan). */
    private function _cascade_staff_department(string $oldName, string $newName): void
    {
        if (dept_key($oldName) === dept_key($newName)) return;
        try {
            foreach ($this->fs->where('staff', [['schoolId', '==', $this->school_id]], null, 'ASC', 5000) as $doc) {
                $d = $doc['data'] ?? $doc; if (!is_array($d)) continue;
                if (dept_key($d['department'] ?? $d['Department'] ?? '') === dept_key($oldName)) {
                    $id = (string) ($d['staffId'] ?? ($doc['id'] ?? ''));
                    if ($id !== '') $this->fs->updateEntity('staff', $id, ['department' => $newName]);
                }
            }
        } catch (\Throwable $e) { log_message('error', 'Staff_access dept rename cascade: ' . $e->getMessage()); }
    }

    private function _dept_out(string $did, array $d): array
    {
        return [
            'id'          => $did,
            'name'        => (string) ($d['name'] ?? $did),
            'description' => (string) ($d['description'] ?? ''),
            'sort_order'  => (int) ($d['sort_order'] ?? 99),
            'is_system'   => !empty($d['is_system']),
            'role_ids'    => is_array($d['role_ids'] ?? null) ? array_values($d['role_ids']) : [],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Normalise a staffRoles entry into the UI's role shape.
    // ─────────────────────────────────────────────────────────────────────

    private function _role_out(string $rid, array $r): array
    {
        return [
            'id'               => $rid,
            'label'            => (string) ($r['label'] ?? $rid),
            'category'         => (string) ($r['category'] ?? 'Other'),
            'department'       => (string) ($r['department'] ?? $r['category'] ?? ''),
            'tier'             => (int) ($r['tier'] ?? 5),
            'description'      => (string) ($r['description'] ?? ''),
            'is_system'        => !empty($r['is_system']),
            'is_global'        => !empty($r['is_global']),
            'auto_assign'      => !empty($r['auto_assign']),
            'custom'           => empty($r['is_system']),
            'sort_order'       => (int) ($r['sort_order'] ?? 99),
            'permissions'      => array_values(array_filter((array) ($r['permissions'] ?? []))),
            'permissionLevels' => (object) (is_array($r['permissionLevels'] ?? null) ? $r['permissionLevels'] : []),
        ];
    }
}
