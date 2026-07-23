<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Org — "Departments & Roles" setup section.
 *
 * A single Setup screen that ties the org structure together:
 *   • Departments  (org units — stored at schools/{id}.departments)
 *   • Staff Roles  (job functions — stored at schools/{id}.staffRoles)
 *   • the link between them: each department carries `role_ids[]` = the staff
 *     roles allowed within it ("role is a subset of department").
 *
 * Design decisions (see the Department↔Role integration write-up):
 *   - Departments are WRITTEN via the existing hr/save_department + hr/delete_department
 *     endpoints (single writer → no drift). This page reads them here and posts
 *     role_ids to hr/save_department.
 *   - Staff roles are written here (save_role/delete_role) — this is now the SOLE
 *     writer of schools.staffRoles. The old twin Staff::save_staff_role /
 *     delete_staff_role were retired (2026-07-07) to a 410 stub to end the
 *     two-writer drift; Staff::get_staff_roles (read) still feeds the staff forms.
 *   - The whole page is HR-permissioned so it works as one coherent screen.
 *   - Access roles (RBAC) are surfaced here on the "Access Roles" tab (single
 *     editing surface) but still stored/served by AdminUsers::*_role endpoints;
 *     the Admin Users page keeps ASSIGNING roles to admins, not defining them.
 */
class Org extends MY_Controller
{
    /** Coarse allow-list for mutating org structure. */
    private const MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'HR Manager'];
    private const VIEW_ROLES   = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'HR Manager', 'Academic Coordinator'];

    /** Valid staff-role categories (mirrors Staff.php). */
    private const ROLE_CATEGORIES = ['Teaching', 'Non-Teaching', 'Administrative', 'Support'];

    public function __construct()
    {
        parent::__construct();
        require_permission('HR');
    }

    /** The Departments & Roles management page. */
    public function index()
    {
        $this->_require_role(self::VIEW_ROLES, '', 'HR', 'view');
        $this->load->view('include/header');
        $this->load->view('org/index');
        $this->load->view('include/footer');
    }

    /**
     * GET /org/get_data — departments (with role_ids + live staff counts) and
     * the full staff-role catalogue, in one payload for the page.
     */
    public function get_data()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_data', 'HR', 'view');

        $school = $this->fs->get('schools', $this->school_id);
        if (!is_array($school)) $school = [];

        // Upgrade this school to the SINGLE unified role catalogue: seed default
        // roles AND fold any legacy RBAC roles (schools.roles) into staffRoles so
        // one role carries both HR attrs and admin permissions. Idempotent — only
        // writes when something changed. Re-read once if it migrated.
        if (ensure_unified_roles($this->fs, $this->school_id)) {
            $school = $this->fs->get('schools', $this->school_id);
            if (!is_array($school)) $school = [];
        }

        // Staff roles (map keyed by ROLE_*)
        $rolesRaw = is_array($school['staffRoles'] ?? null) ? $school['staffRoles'] : [];
        $roles = [];
        foreach ($rolesRaw as $rid => $r) {
            $r = (array) $r;
            $r['id'] = $rid;
            $roles[] = $r;
        }

        // Live staff-count per department name (so the UI can warn before
        // removing a role that people are actually assigned under).
        $countByDept = [];
        try {
            $staffDocs = $this->fs->schoolWhere('staff', []);
            if (is_array($staffDocs)) {
                foreach ($staffDocs as $doc) {
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $dept = $d['Department'] ?? $d['department'] ?? '';
                    // Key by normalized name so counts join a dept doc "Science"
                    // to staff bucketed as "science"/"Science " (matches the
                    // delete-guard normalization → UI and server agree).
                    $k = dept_key($dept);
                    if ($k !== '') $countByDept[$k] = ($countByDept[$k] ?? 0) + 1;
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'Org get_data staff count failed: ' . $e->getMessage());
        }

        // Departments (map keyed by DEPT_*)
        $deptsRaw = is_array($school['departments'] ?? null) ? $school['departments'] : [];
        $departments = [];
        foreach ($deptsRaw as $did => $d) {
            $d = (array) $d;
            $d['id']          = $did;
            $d['role_ids']    = is_array($d['role_ids'] ?? null) ? array_values($d['role_ids']) : [];
            $d['staff_count'] = $countByDept[dept_key($d['name'] ?? '')] ?? 0;
            $departments[] = $d;
        }

        // Old accounts: if the new map is empty, count legacy RTDB departments so
        // the UI can offer a one-click import. We do NOT auto-migrate on a read —
        // the write stays behind the MANAGE_ROLES backfill endpoint.
        $legacyAvailable = 0;
        if (empty($departments)) {
            try {
                $legacy = $this->firebase->get("Schools/{$this->school_name}/HR/Departments");
                if (is_array($legacy)) {
                    foreach ($legacy as $lid => $ld) {
                        $nm = is_string($ld) ? $ld : (is_array($ld) ? (string) ($ld['name'] ?? (is_string($lid) ? $lid : '')) : '');
                        if (trim($nm) !== '') $legacyAvailable++;
                    }
                }
            } catch (\Throwable $e) { /* best-effort */ }
        }

        $this->json_success([
            'departments'      => $departments,
            'roles'            => $roles,
            'legacy_available' => $legacyAvailable,
            'modules'          => defined('RBAC_MODULES') ? RBAC_MODULES : [],
        ]);
    }

    /**
     * POST /org/save_role — create or update a staff role.
     * Params: role_id, label, category, attendance_type, flags[] (optional).
     * System roles: only their flags can change (label/category are locked).
     */
    public function save_role()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_role', 'HR', 'edit');

        $roleId   = trim((string) $this->input->post('role_id', TRUE));
        $label    = trim((string) $this->input->post('label', TRUE));
        $category = trim((string) $this->input->post('category', TRUE));

        if ($label === '') {
            return $this->json_error('Please enter a role name.');
        }
        if (!in_array($category, self::ROLE_CATEGORIES, true)) {
            return $this->json_error('Category must be one of: ' . implode(', ', self::ROLE_CATEGORIES));
        }

        $school   = $this->fs->get('schools', $this->school_id);
        $allRoles = is_array($school['staffRoles'] ?? null) ? $school['staffRoles'] : [];

        // New role (empty role_id) → derive a stable ROLE_* reference from the
        // human name. Users never type the id; the app owns it end-to-end and
        // keeps it unique within this school. An id is only sent when *editing*.
        if ($roleId === '') {
            $roleId = $this->_generate_role_id($label, $allRoles);
        }
        if (!preg_match('/^ROLE_[A-Z0-9_]+$/', $roleId)) {
            return $this->json_error('Could not build a valid reference ID from that name — please include some letters or digits.');
        }

        $existing = is_array($allRoles[$roleId] ?? null) ? $allRoles[$roleId] : null;

        // SECURITY (audit C2 remainder): shared role-editing ceiling — a non-god may
        // only edit roles less privileged than their own (blocks self-widening +
        // editing admin/higher roles via this HR-gated duplicate write path). Same
        // guard as Staff_access / AdminUsers.
        if ($err = rbac_role_edit_error($allRoles, $roleId)) { return $this->json_error($err); }

        $flagsRaw = $this->input->post('flags');
        $flags = is_array($flagsRaw) ? array_values(array_filter(array_map('strval', $flagsRaw))) : [];

        // Module permissions (admin/RBAC side of the unified role). Only overwrite
        // when the editor actually submitted the picker (permissions_present=1) so
        // a caller that omits the field never silently wipes a role's access.
        $permsRaw     = $this->input->post('permissions');
        $permsPresent = $this->input->post('permissions_present') !== null;
        $modules      = defined('RBAC_MODULES') ? RBAC_MODULES : [];
        $permissions  = is_array($permsRaw) ? array_values(array_intersect($permsRaw, $modules)) : [];

        if ($existing !== null && !empty($existing['is_system'])) {
            // System role — flags + module permissions are editable; label,
            // category and attendance_type stay locked to the canonical defaults.
            $existing['flags'] = $flags;
            if ($permsPresent) $existing['permissions'] = $permissions;
            $allRoles[$roleId] = $existing;
            $this->fs->update('schools', $this->school_id, ['staffRoles' => $allRoles]);
            return $this->json_success(['message' => "System role '{$existing['label']}' updated.", 'role_id' => $roleId]);
        }

        // SECURITY: never let a custom (non-system) role be minted with a label
        // that grants RBAC bypass. Authorization is decided by matching the role
        // LABEL against RBAC_BYPASS_ROLES, so a role literally named "Super Admin"
        // / "School Super Admin" / "Admin" would silently become a full-access
        // account when assigned. The genuine seeded roles hit the is_system branch
        // above; anything reaching here is user-created and must be blocked.
        if (in_array(strtolower($label), ['super admin', 'school super admin', 'admin'], true)) {
            return $this->json_error("The name '{$label}' is reserved and can't be used for a custom role.");
        }

        $attendanceType = trim((string) $this->input->post('attendance_type', TRUE)) ?: 'standard';
        if (!in_array($attendanceType, ['standard', 'shift', 'flexible'], true)) {
            $attendanceType = 'standard';
        }

        $entry = array_merge(is_array($existing) ? $existing : [], [
            'label'           => $label,
            'category'        => $category,
            'flags'           => $flags,
            'attendance_type' => $attendanceType,
            'is_system'       => false,
        ]);
        if ($permsPresent)                    $entry['permissions'] = $permissions;
        elseif (!isset($entry['permissions'])) $entry['permissions'] = [];
        if (!isset($entry['tier']))            $entry['tier']        = 7;
        if (!isset($entry['sort_order']))      $entry['sort_order']  = 100;
        $allRoles[$roleId] = $entry;
        $this->fs->update('schools', $this->school_id, ['staffRoles' => $allRoles]);

        $this->json_success(['message' => "Staff role '{$label}' saved.", 'role_id' => $roleId]);
    }

    /**
     * Derive a stable, unique ROLE_* reference id from a human role name.
     * e.g. "Vice Principal" → ROLE_VICE_PRINCIPAL; a name that collides gets the
     * smallest free numeric suffix (…_2, …_3). Non-latin names fall back to a
     * short random token so an id is always producible.
     */
    private function _generate_role_id(string $label, array $allRoles): string
    {
        $base = strtoupper($label);
        $base = preg_replace('/[^A-Z0-9]+/', '_', $base);
        $base = trim((string) $base, '_');
        if ($base === '') {
            $base = 'CUSTOM_' . strtoupper(substr(md5($label . uniqid('', true)), 0, 6));
        }
        $id = 'ROLE_' . $base;
        if (!isset($allRoles[$id])) return $id;
        $n = 2;
        while (isset($allRoles['ROLE_' . $base . '_' . $n])) $n++;
        return 'ROLE_' . $base . '_' . $n;
    }

    /**
     * POST /org/delete_role — delete a custom (non-system) staff role.
     * Also scrubs the role from every department's role_ids so no department
     * points at a role that no longer exists.
     * Params: role_id.
     */
    public function delete_role()
    {
        $this->_require_role(self::MANAGE_ROLES, 'delete_role', 'HR', 'manage');

        $roleId = trim((string) $this->input->post('role_id', TRUE));
        if ($roleId === '') return $this->json_error('role_id is required.');

        $school   = $this->fs->get('schools', $this->school_id);
        $allRoles = is_array($school['staffRoles'] ?? null) ? $school['staffRoles'] : [];
        $existing = is_array($allRoles[$roleId] ?? null) ? $allRoles[$roleId] : null;

        if ($existing === null) return $this->json_error('Role not found.');
        if (!empty($existing['is_system'])) return $this->json_error('System roles cannot be deleted (they can be hidden by removing them from every department).');
        // SECURITY (audit C2 remainder): a non-god may not delete an admin / held /
        // ≥-own-tier role (same shared ceiling as editing).
        if ($err = rbac_role_edit_error($allRoles, $roleId)) { return $this->json_error($err); }

        // Block deletion if any staff member currently holds this role. Legacy
        // staff may hold it via `primary_role` or only via the free-text
        // `Position` (before role migration), so check all three shapes — not
        // just the canonical staff_roles[] array.
        $roleLabel = strtolower(trim((string) ($existing['label'] ?? '')));
        $inUse = 0;
        try {
            $staffDocs = $this->fs->schoolWhere('staff', []);
            if (is_array($staffDocs)) {
                foreach ($staffDocs as $doc) {
                    $d  = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $rs = is_array($d['staff_roles'] ?? null) ? $d['staff_roles'] : [];
                    $holds = in_array($roleId, $rs, true)
                        || (string) ($d['primary_role'] ?? '') === $roleId;
                    if (!$holds && $roleLabel !== '') {
                        $pos = strtolower(trim((string) ($d['Position'] ?? $d['position'] ?? $d['designation'] ?? '')));
                        // Whole-word match so "Admin" doesn't match "Administrator"
                        // / "Lab" doesn't match "Laboratory" (fail-safe direction,
                        // but avoid over-blocking legit deletes).
                        if ($pos !== '' && preg_match('/\b' . preg_quote($roleLabel, '/') . '\b/u', $pos)) $holds = true;
                    }
                    if ($holds) { $inUse++; }
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'Org delete_role in-use check failed: ' . $e->getMessage());
        }
        if ($inUse > 0) {
            return $this->json_error("Cannot delete — {$inUse} staff member(s) still have this role. Reassign them first.");
        }

        unset($allRoles[$roleId]);

        // Scrub from department mappings.
        $depts = is_array($school['departments'] ?? null) ? $school['departments'] : [];
        $changed = false;
        foreach ($depts as $did => $d) {
            $d = (array) $d;
            if (is_array($d['role_ids'] ?? null) && in_array($roleId, $d['role_ids'], true)) {
                $d['role_ids'] = array_values(array_diff($d['role_ids'], [$roleId]));
                $depts[$did] = $d;
                $changed = true;
            }
        }

        $update = ['staffRoles' => $allRoles];
        if ($changed) $update['departments'] = $depts;
        $this->fs->update('schools', $this->school_id, $update);

        $this->json_success(['message' => 'Staff role deleted.']);
    }

    /**
     * POST/GET /org/backfill_legacy_departments — one-time, idempotent safety net
     * for OLD accounts whose departments still live only at the retired RTDB path
     * (Schools/{school}/HR/Departments). If the new schools/{id}.departments map is
     * empty but legacy departments exist, copy them into the new map (role_ids left
     * empty for the admin to assign in Departments & Roles). Safe to re-run.
     */
    public function backfill_legacy_departments()
    {
        $this->_require_role(self::MANAGE_ROLES, 'backfill_legacy_departments', 'HR', 'edit');

        $school = $this->fs->get('schools', $this->school_id);
        if (!is_array($school)) {
            // Fail CLOSED: a transient read failure must NOT look like "empty" and
            // let the wholesale departments overwrite below wipe live data.
            return $this->json_error('Could not read the school record — please try again in a moment.');
        }
        $current = is_array($school['departments'] ?? null) ? $school['departments'] : [];
        if (!empty($current)) {
            return $this->json_success(['migrated' => 0, 'message' => 'Already has ' . count($current) . ' department(s) in the new module — nothing to backfill.']);
        }

        $legacy = [];
        try {
            $legacy = $this->firebase->get("Schools/{$this->school_name}/HR/Departments");
        } catch (\Throwable $e) {
            log_message('error', 'Org backfill legacy read failed: ' . $e->getMessage());
        }
        if (!is_array($legacy) || empty($legacy)) {
            return $this->json_success(['migrated' => 0, 'message' => 'No legacy departments found — nothing to backfill.']);
        }

        $now = date('c');
        $map = [];
        $i   = 0;
        foreach ($legacy as $lid => $ld) {
            // Legacy could store a plain "index → name" list, not just {name:…}.
            $origName = is_string($ld) ? $ld : null;
            $ld   = (array) $ld;
            $name = trim((string) ($ld['name'] ?? $origName ?? (is_string($lid) ? $lid : '')));
            if ($name === '') continue;
            $i++;
            $id = sprintf('DEPT_%04d', $i);
            $map[$id] = [
                'name'          => $name,
                'status'        => trim((string) ($ld['status'] ?? 'Active')) ?: 'Active',
                'head_staff_id' => (string) ($ld['head_staff_id'] ?? ''),
                'description'   => (string) ($ld['description'] ?? ''),
                'role_ids'      => [],
                'created_at'    => (string) ($ld['created_at'] ?? $now),
                'updated_at'    => $now,
            ];
        }

        if (empty($map)) {
            return $this->json_success(['migrated' => 0, 'message' => 'Legacy departments had no usable names — nothing to backfill.']);
        }

        $this->fs->update('schools', $this->school_id, ['departments' => $map]);

        // Advance the HR department-id counter (on the *_profile doc, consumed by
        // Hr::save_department::_next_id) to match the backfilled DEPT_000N ids —
        // otherwise the next "Add Department" reuses DEPT_0001 and overwrites a
        // backfilled department, orphaning its staff.
        try {
            $this->fs->update('schools', $this->fs->docId('profile'), ['hrCounters.Department' => count($map)]);
        } catch (\Throwable $e) {
            log_message('error', 'Org backfill counter bump failed: ' . $e->getMessage());
        }

        $this->json_success(['migrated' => count($map), 'message' => 'Backfilled ' . count($map) . ' department(s) into Departments & Roles. Assign roles to each in the new module.']);
    }
}
