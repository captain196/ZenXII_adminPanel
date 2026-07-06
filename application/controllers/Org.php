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
 *   - Staff roles are written here (save_role/delete_role). Staff::save_staff_role
 *     is the legacy twin (no UI) — keep the two in sync if either changes.
 *   - The whole page is HR-permissioned so it works as one coherent screen.
 *   - Access role (RBAC) is intentionally NOT touched — that stays in Admin Users.
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
        $this->_require_role(self::VIEW_ROLES);
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
        $this->_require_role(self::VIEW_ROLES, 'get_data');

        $school = $this->fs->get('schools', $this->school_id);
        if (!is_array($school)) $school = [];

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
                    $dept = trim((string) ($d['Department'] ?? $d['department'] ?? ''));
                    if ($dept !== '') $countByDept[$dept] = ($countByDept[$dept] ?? 0) + 1;
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
            $d['staff_count'] = $countByDept[$d['name'] ?? ''] ?? 0;
            $departments[] = $d;
        }

        $this->json_success([
            'departments' => $departments,
            'roles'       => $roles,
        ]);
    }

    /**
     * POST /org/save_role — create or update a staff role.
     * Params: role_id, label, category, attendance_type, flags[] (optional).
     * System roles: only their flags can change (label/category are locked).
     */
    public function save_role()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_role');

        $roleId   = trim((string) $this->input->post('role_id', TRUE));
        $label    = trim((string) $this->input->post('label', TRUE));
        $category = trim((string) $this->input->post('category', TRUE));

        if ($roleId === '' || $label === '') {
            return $this->json_error('Role ID and label are required.');
        }
        if (!preg_match('/^ROLE_[A-Z0-9_]+$/', $roleId)) {
            return $this->json_error('Role ID must be ROLE_ followed by UPPERCASE letters, digits or underscores (e.g. ROLE_COUNSELLOR).');
        }
        if (!in_array($category, self::ROLE_CATEGORIES, true)) {
            return $this->json_error('Category must be one of: ' . implode(', ', self::ROLE_CATEGORIES));
        }

        $school   = $this->fs->get('schools', $this->school_id);
        $allRoles = is_array($school['staffRoles'] ?? null) ? $school['staffRoles'] : [];
        $existing = is_array($allRoles[$roleId] ?? null) ? $allRoles[$roleId] : null;

        $flagsRaw = $this->input->post('flags');
        $flags = is_array($flagsRaw) ? array_values(array_filter(array_map('strval', $flagsRaw))) : [];

        if ($existing !== null && !empty($existing['is_system'])) {
            // System role — only flags are editable; keep label/category/type.
            $existing['flags'] = $flags;
            $allRoles[$roleId] = $existing;
            $this->fs->update('schools', $this->school_id, ['staffRoles' => $allRoles]);
            return $this->json_success(['message' => "System role '{$existing['label']}' updated.", 'role_id' => $roleId]);
        }

        $attendanceType = trim((string) $this->input->post('attendance_type', TRUE)) ?: 'standard';
        if (!in_array($attendanceType, ['standard', 'shift', 'flexible'], true)) {
            $attendanceType = 'standard';
        }

        $allRoles[$roleId] = [
            'label'           => $label,
            'category'        => $category,
            'flags'           => $flags,
            'attendance_type' => $attendanceType,
            'is_system'       => false,
        ];
        $this->fs->update('schools', $this->school_id, ['staffRoles' => $allRoles]);

        $this->json_success(['message' => "Staff role '{$label}' saved.", 'role_id' => $roleId]);
    }

    /**
     * POST /org/delete_role — delete a custom (non-system) staff role.
     * Also scrubs the role from every department's role_ids so no department
     * points at a role that no longer exists.
     * Params: role_id.
     */
    public function delete_role()
    {
        $this->_require_role(self::MANAGE_ROLES, 'delete_role');

        $roleId = trim((string) $this->input->post('role_id', TRUE));
        if ($roleId === '') return $this->json_error('role_id is required.');

        $school   = $this->fs->get('schools', $this->school_id);
        $allRoles = is_array($school['staffRoles'] ?? null) ? $school['staffRoles'] : [];
        $existing = is_array($allRoles[$roleId] ?? null) ? $allRoles[$roleId] : null;

        if ($existing === null) return $this->json_error('Role not found.');
        if (!empty($existing['is_system'])) return $this->json_error('System roles cannot be deleted (they can be hidden by removing them from every department).');

        // Block deletion if any staff member currently holds this role.
        $inUse = 0;
        try {
            $staffDocs = $this->fs->schoolWhere('staff', []);
            if (is_array($staffDocs)) {
                foreach ($staffDocs as $doc) {
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $rs = is_array($d['staff_roles'] ?? null) ? $d['staff_roles'] : [];
                    if (in_array($roleId, $rs, true)) { $inUse++; }
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
}
