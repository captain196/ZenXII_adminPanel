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
    // Operations sub-modules — separately grantable so a Librarian can be given
    // just 'Library', a Transport Manager just 'Transport', etc.
    'Library','Transport','Hostel','Inventory','Assets',
    'Academic','Reports','Configuration','Admin Users','Stories','Homework',
    // Red Flags — student discipline/behaviour flags. Grantable so Principal /
    // Vice Principal / Academic Coordinator / Teacher roles can be given access
    // (scoped to their own classes by Red_flags::_teacher_can_access). Was
    // previously unregistered, so only the 3 bypass roles could reach it.
    'Red Flags',
]);

/**
 * Parent → child permission tree. A child is accessible when the role has the
 * child module directly OR holds the parent "umbrella" (which grants all its
 * children — this is what keeps every existing 'Operations' account working).
 * Conversely an umbrella parent is considered "present" (its menu group shows)
 * when the role holds the parent itself or ANY of its children.
 */
define('RBAC_MODULE_CHILDREN', [
    'Operations' => ['Library', 'Transport', 'Hostel', 'Inventory', 'Assets'],
]);

/**
 * Roles that bypass all permission checks (automatic full access).
 */
define('RBAC_BYPASS_ROLES', ['Super Admin', 'School Super Admin', 'Admin']);

/**
 * Graded access levels (monotonic: manage ⊇ edit ⊇ view). A role grants a level
 * PER MODULE via the parallel `permissionLevels` map on the role; the flat
 * `permissions[]` list still governs module PRESENCE (unchanged). A held module
 * with no explicit level defaults to 'manage' — so before any level backfill the
 * system behaves exactly as the legacy all-or-nothing model.
 */
define('RBAC_LEVELS', ['view' => 1, 'edit' => 2, 'manage' => 3]);

/**
 * Ordinal rank of a level string (unknown → 'view' floor).
 */
function rbac_level_rank(string $level): int
{
    return RBAC_LEVELS[$level] ?? RBAC_LEVELS['view'];
}

/**
 * The level at which the caller holds $module, or NULL if not held at all.
 * Encapsulates the same presence + umbrella-inheritance logic as has_permission,
 * plus the level lookup. A held-but-unleveled module defaults to 'manage'
 * (legacy-equivalent). Umbrella-inherited access carries the parent's/child's
 * level (default 'manage').
 */
function rbac_granted_level(string $module, array $permissions, array $levels): ?string
{
    // Direct grant.
    if (in_array($module, $permissions, true)) {
        return $levels[$module] ?? 'manage';
    }
    // Child inherits from its umbrella parent (e.g. 'Operations' grants 'Library').
    foreach (RBAC_MODULE_CHILDREN as $parent => $children) {
        if (in_array($module, $children, true) && in_array($parent, $permissions, true)) {
            return $levels[$parent] ?? 'manage';
        }
    }
    // Umbrella parent is "present" when any child is held — carry the strongest
    // child level so the parent group renders and enforces sanely.
    if (isset(RBAC_MODULE_CHILDREN[$module])) {
        $best = null;
        foreach (RBAC_MODULE_CHILDREN[$module] as $child) {
            if (in_array($child, $permissions, true)) {
                $lvl = $levels[$child] ?? 'manage';
                if ($best === null || rbac_level_rank($lvl) > rbac_level_rank($best)) {
                    $best = $lvl;
                }
            }
        }
        return $best; // NULL if no child held
    }
    return null;
}

// ─── Core functions ──────────────────────────────────────────────────────────

/**
 * Check if the current user has permission for a module at (at least) a level.
 *
 * @param  string $module  One of RBAC_MODULES (e.g. 'Fees', 'HR')
 * @param  string $level   Required level: 'view' | 'edit' | 'manage' (default 'view').
 *                         Monotonic — a 'manage' grant satisfies an 'edit'/'view'
 *                         requirement. Omitting the level = a presence/view check,
 *                         which (with the manage default for unleveled grants) is
 *                         byte-for-byte the legacy behaviour.
 * @return bool
 */
function has_permission(string $module, string $level = 'view'): bool
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

    $levels = $CI->session->userdata('rbac_levels');
    if (!is_array($levels)) {
        $levels = []; // legacy session (pre-levels): every held module → 'manage'
    }

    // Resolve the level at which the module is held (NULL = not held), applying
    // the same direct + umbrella-inheritance rules as before.
    $granted = rbac_granted_level($module, $permissions, $levels);
    if ($granted === null) {
        return false;
    }

    return rbac_level_rank($granted) >= rbac_level_rank($level);
}

/**
 * Require permission for a module — aborts with 403 if denied.
 *
 * Call at the top of controller methods to enforce access control.
 *
 * @param  string $module  One of RBAC_MODULES
 * @param  string $action  Optional human-readable label for logging
 * @param  string $level   Required level: 'view' | 'edit' | 'manage' (default 'view').
 * @return void
 */
function require_permission(string $module, string $action = '', string $level = 'view'): void
{
    if (has_permission($module, $level)) {
        return;
    }

    $CI =& get_instance();
    $role     = $CI->session->userdata('admin_role') ?? '';
    $admin_id = $CI->session->userdata('admin_id') ?? '';
    $school   = $CI->session->userdata('school_id') ?? '';
    $label    = $action ? " ({$action})" : '';

    log_message('error',
        "RBAC denied: module=[{$module}] level=[{$level}] role=[{$role}] admin=[{$admin_id}]"
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
 * Load role permissions from Firestore for the given school and role.
 *
 * Reads `schools/{schoolId}.roles[role].permissions` — the same document
 * AdminUsers::save_role / delete_role / _seed_default_roles write to.
 *
 * Called at login time and by MY_Controller for refresh.
 * Returns array of module strings, or empty array on failure.
 *
 * @param  object $firebase   Legacy RTDB library handle. Unused — kept for
 *                            signature compatibility with existing callers.
 * @param  string $school_id  Firestore `schools` document id (SCH_XXXXXX).
 *                            Param name `school_name` historical; value is the Firestore key.
 * @param  string $role       Role name as stored in admin record / claim.
 * @return array
 */
function load_role_permissions($firebase, string $school_id, string $role): array
{
    return resolve_role_access($firebase, $school_id, $role)['modules'];
}

/**
 * Resolve the effective ACCESS for one role OR the UNION of several roles
 * (a staff member's `staff_roles[]`). Returns both the flat module presence list
 * (backward-compatible with load_role_permissions) AND the per-module level map:
 *
 *   ['modules' => ['Fees','HR',...], 'levels' => ['Fees'=>'edit','HR'=>'manage',...]]
 *
 * Union semantics: a module is present if ANY role grants it; its level is the
 * MAX across granting roles. A granting role with no `permissionLevels` entry for
 * a held module contributes 'manage' (legacy-equivalent) — so before any level
 * backfill this yields the same all-or-nothing access as the flat model.
 *
 * @param  object       $firebase  Unused; kept for signature parity.
 * @param  string       $school_id Firestore `schools` doc id (SCH_XXXXXX).
 * @param  string|array $roles     A single role label/ROLE_* id, or an array of them.
 * @return array{modules: string[], levels: array<string,string>}
 */
function resolve_role_access($firebase, string $school_id, $roles): array
{
    $roleList = is_array($roles) ? $roles : [$roles];
    $roleList = array_values(array_filter(array_map(function ($r) { return (string) $r; }, $roleList),
        function ($r) { return $r !== ''; }));

    // Any bypass role in the set ⇒ full access (all modules at 'manage').
    foreach ($roleList as $r) {
        if (in_array($r, RBAC_BYPASS_ROLES, true)) {
            return ['modules' => RBAC_MODULES, 'levels' => array_fill_keys(RBAC_MODULES, 'manage')];
        }
    }

    if (empty($school_id) || empty($roleList)) {
        return ['modules' => [], 'levels' => []];
    }

    $modules = []; // module => true (presence set, order-preserving via keys)
    $levels  = []; // module => max level string

    try {
        $CI =& get_instance();
        if (!isset($CI->fs)) {
            log_message('error', 'RBAC resolve_role_access: fs library not loaded');
            return ['modules' => [], 'levels' => []];
        }

        $schoolDoc  = $CI->fs->get('schools', $school_id);
        $staffRoles = (is_array($schoolDoc) && is_array($schoolDoc['staffRoles'] ?? null)) ? $schoolDoc['staffRoles'] : [];
        $legacyRbac = (is_array($schoolDoc) && is_array($schoolDoc['roles'] ?? null))      ? $schoolDoc['roles']      : [];

        foreach ($roleList as $role) {
            $matched = false;

            // PRIMARY: unified catalogue, matched by label OR ROLE_* id.
            foreach ($staffRoles as $rid => $r) {
                if (!is_array($r)) continue;
                if ((string) ($r['label'] ?? '') === $role || (string) $rid === $role) {
                    $matched = true;
                    $perms  = is_array($r['permissions'] ?? null)
                              ? array_values(array_intersect($r['permissions'], RBAC_MODULES)) : [];
                    $rlvls  = is_array($r['permissionLevels'] ?? null) ? $r['permissionLevels'] : [];
                    foreach ($perms as $m) {
                        $modules[$m] = true;
                        $lvl = (isset($rlvls[$m]) && isset(RBAC_LEVELS[$rlvls[$m]])) ? $rlvls[$m] : 'manage';
                        if (!isset($levels[$m]) || rbac_level_rank($lvl) > rbac_level_rank($levels[$m])) {
                            $levels[$m] = $lvl;
                        }
                    }
                    break; // this role resolved
                }
            }
            if ($matched) continue;

            // FALLBACK: legacy schools.roles[name] (read-only) for un-folded tenants.
            $rd = $legacyRbac[$role] ?? null;
            if (is_array($rd) && is_array($rd['permissions'] ?? null)) {
                foreach (array_intersect($rd['permissions'], RBAC_MODULES) as $m) {
                    $modules[$m] = true;
                    if (!isset($levels[$m])) $levels[$m] = 'manage'; // legacy has no level signal
                }
            }
        }
    } catch (Exception $e) {
        log_message('error', 'RBAC resolve_role_access failed: ' . $e->getMessage());
        return ['modules' => [], 'levels' => []];
    }

    return ['modules' => array_keys($modules), 'levels' => $levels];
}
