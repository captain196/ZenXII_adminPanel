<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * RBAC Helper — Role-Based Access Control for School ERP
 *
 * Provides reusable permission checks against session-cached role permissions.
 *
 * Usage in controllers (that extend MY_Controller):
 *   require_permission('Fees');          // blocks with 403 if denied
 *   has_permission('HR');                // returns bool
 *
 * Permissions are cached in session at login (Admin_login.php) and refreshed
 * by MY_Controller. The 'Admin' role gets automatic full access (backward compat).
 */

// ─── Module constants ────────────────────────────────────────────────────────

/**
 * Canonical list of all permission module keys.
 * Must stay in sync with AdminUsers::AVAILABLE_MODULES.
 */
define('RBAC_MODULES', [
    'SIS','Fees','Accounting','Attendance','Examinations','Results',
    'LMS','Certificates','HR','Events','Communication','Operations',
    'Academic','Reports','Configuration','Admin Users','Stories',
]);

/**
 * Roles that bypass all permission checks (automatic full access).
 */
define('RBAC_BYPASS_ROLES', ['Super Admin', 'School Super Admin', 'Admin']);

// ─── Core functions ──────────────────────────────────────────────────────────

/**
 * Check if the current user has permission for a module.
 *
 * @param  string $module  One of RBAC_MODULES (e.g. 'Fees', 'HR')
 * @return bool
 */
function has_permission(string $module): bool
{
    $CI =& get_instance();
    $role = $CI->session->userdata('admin_role') ?? '';

    // Bypass roles get full access
    if (in_array($role, RBAC_BYPASS_ROLES, true)) {
        return true;
    }

    $permissions = $CI->session->userdata('rbac_permissions');

    // No permissions cached = no access (except bypass roles above)
    if (!is_array($permissions)) {
        return false;
    }

    return in_array($module, $permissions, true);
}

/**
 * Require permission for a module — aborts with 403 if denied.
 *
 * Call at the top of controller methods to enforce access control.
 *
 * @param  string $module  One of RBAC_MODULES
 * @param  string $action  Optional human-readable label for logging
 * @return void
 */
function require_permission(string $module, string $action = ''): void
{
    if (has_permission($module)) {
        return;
    }

    $CI =& get_instance();
    $role     = $CI->session->userdata('admin_role') ?? '';
    $admin_id = $CI->session->userdata('admin_id') ?? '';
    $school   = $CI->session->userdata('school_id') ?? '';
    $label    = $action ? " ({$action})" : '';

    log_message('error',
        "RBAC denied: module=[{$module}] role=[{$role}] admin=[{$admin_id}]"
        . " school=[{$school}]{$label}"
    );

    if ($CI->input->is_ajax_request()) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode([
            'status'  => 'error',
            'message' => 'You do not have permission to access this module.',
        ]);
        exit;
    }

    // Redirect to dashboard instead of showing a harsh 403 error page
    $CI->session->set_flashdata('error', 'You do not have access to that module.');
    redirect('admin/index');
}

/**
 * Load role permissions from Firebase for the given school and role.
 *
 * Called at login time and by MY_Controller for refresh.
 * Returns array of module strings, or empty array on failure.
 *
 * @param  object $firebase  Firebase library instance
 * @param  string $school_name  School identifier (SCH_XXXXXX or legacy name)
 * @param  string $role  Role name as stored in admin record
 * @return array
 */
function load_role_permissions($firebase, string $school_name, string $role): array
{
    // Bypass roles don't need to load — they have full access
    if (in_array($role, RBAC_BYPASS_ROLES, true)) {
        return RBAC_MODULES; // return all for sidebar rendering
    }

    if (empty($school_name) || empty($role)) {
        return [];
    }

    try {
        $role_safe = preg_replace('/[^A-Za-z0-9_ \-]/', '', $role);
        $role_data = $firebase->get("Schools/{$school_name}/Roles/{$role_safe}");

        if (is_array($role_data) && isset($role_data['permissions']) && is_array($role_data['permissions'])) {
            // Whitelist against known modules
            return array_values(array_intersect($role_data['permissions'], RBAC_MODULES));
        }
    } catch (Exception $e) {
        log_message('error', 'RBAC load_role_permissions failed: ' . $e->getMessage());
    }

    return [];
}
