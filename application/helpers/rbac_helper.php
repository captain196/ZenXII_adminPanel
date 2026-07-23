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

if (!function_exists('rbac_modules_list')) {
    /**
     * Canonical RBAC module catalogue — SINGLE SOURCE loaded from
     * functions/rbac_modules.json, the SAME file the staffCapabilities Cloud
     * Function reads, so the PHP resolver and the CF caps mirror can never drift
     * (Device Management / Message Monitor were lost to exactly that drift once).
     * Static-cached (one read per request). If the file is unreadable it logs
     * LOUDLY and falls back to the inline emergency list so RBAC_MODULES is never
     * empty — an empty catalogue would lock every restricted role out of every
     * module. The fallback is emergency-only; the JSON is authoritative.
     */
    function rbac_modules_list(): array
    {
        static $mods = null;
        if ($mods !== null) { return $mods; }
        $file = __DIR__ . '/../../functions/rbac_modules.json';
        $json = @json_decode((string) @file_get_contents($file), true);
        if (is_array($json) && !empty($json['modules']) && is_array($json['modules'])) {
            return $mods = array_values(array_map('strval', $json['modules']));
        }
        if (function_exists('log_message')) {
            log_message('error', 'RBAC: functions/rbac_modules.json unreadable — using inline emergency fallback (fix the file).');
        }
        return $mods = [
            'SIS','Fees','Accounting','Attendance','Examinations','Results',
            'LMS','Certificates','HR','Events','Communication','Operations',
            'Library','Transport','Hostel','Inventory','Assets',
            'Academic','Reports','Configuration','Admin Users','Stories','Homework',
            'Red Flags','Device Management','Message Monitor',
        ];
    }
}

/**
 * Canonical list of all permission module keys — SINGLE SOURCE
 * (functions/rbac_modules.json, shared byte-for-byte with the staffCapabilities CF).
 * RBAC_MODULE_META below stays PHP-only (the CF needs the module LIST, not the
 * group/surface UI hints) but its keys MUST match this list.
 */
define('RBAC_MODULES', rbac_modules_list());

/**
 * Presentation metadata for each RBAC module, consumed by the Unified Staff
 * Access UI (Staff_access): the picker/matrix GROUP a module sits under, and the
 * SURFACE it appears on — 'app' (staff app only), 'web' (admin panel only), or
 * 'both'. Surface is UI/UX guidance for where the module shows; enforcement stays
 * at the rules/controller layer. Keys MUST stay in sync with RBAC_MODULES above.
 */
define('RBAC_MODULE_META', [
    // module          => [group,             surface]
    'Stories'          => ['Engagement',      'both'],
    'Events'           => ['Engagement',      'both'],
    'Communication'    => ['Engagement',      'both'],
    'Homework'         => ['Teaching',        'both'],
    'Attendance'       => ['Teaching',        'both'],
    'Examinations'     => ['Teaching',        'both'],
    'Results'          => ['Teaching',        'both'],
    'Academic'         => ['Teaching',        'both'],
    'Red Flags'        => ['Welfare',         'both'],
    // M7: SIS / Fees / Library are 'both' — the Staff app ships real screens for
    // them (its ROUTE_MODULE gates on these keys), so surfacing them as web-only
    // hid an app screen the app actually enforces. Group is unchanged; only the
    // surface flips web → both.
    'SIS'              => ['Students & Fees',  'both'],
    'Fees'             => ['Students & Fees',  'both'],
    'Accounting'       => ['Students & Fees',  'web'],
    'LMS'              => ['Students & Fees',  'web'],
    'Certificates'     => ['Students & Fees',  'web'],
    'HR'               => ['Workforce',        'web'],
    'Operations'       => ['Operations',       'web'],
    'Library'          => ['Operations',       'both'],
    'Transport'        => ['Operations',       'web'],
    'Hostel'           => ['Operations',       'web'],
    'Inventory'        => ['Operations',       'web'],
    'Assets'           => ['Operations',       'web'],
    'Reports'          => ['System',           'web'],
    'Configuration'    => ['System',           'web'],
    'Admin Users'      => ['System',           'web'],
    'Device Management'=> ['System',           'web'],
    'Message Monitor'  => ['System',           'web'],
]);

if (!function_exists('rbac_module_surface')) {
    /** Surface a module appears on: 'app' | 'web' | 'both' (default 'web'). */
    function rbac_module_surface(string $module): string
    {
        $meta = defined('RBAC_MODULE_META') ? RBAC_MODULE_META : [];
        return isset($meta[$module][1]) ? (string) $meta[$module][1] : 'web';
    }
}

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
 *
 * FOLD (step 6, point-of-no-return): 'Admin' REMOVED. Admins are staff now — an
 * 'Admin'-role account resolves its access from the catalogue's ROLE_ADMIN entry
 * (backfilled to all RBAC_MODULES at 'manage' by Admin_role_backfill) instead of
 * this hardcoded short-circuit. Only the two School/Super Admin god roles remain
 * true bypasses. PREREQ before this flips in prod: Admin_role_backfill committed +
 * run for every tenant (else a bare ROLE_ADMIN would under-grant a demoted admin).
 */
define('RBAC_BYPASS_ROLES', ['Super Admin', 'School Super Admin']);

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
/**
 * Redirect target for a full-page RBAC denial — the professional "Access
 * restricted" screen (Admin::access_denied) instead of a silent bounce to the
 * dashboard. Carries the module + required level so the screen can tell the
 * user exactly what they need. Module-less (legacy name-gate) calls get the
 * generic screen. Used by both shared denial paths and the per-controller
 * _deny_access() helpers.
 */
/**
 * True if the current user HOLDS the given module in their resolved graded
 * permission map (at ANY level), regardless of the level required. Used by the
 * gate to decide when the graded map is authoritative: a user who holds a module
 * but at an insufficient level must be DENIED, not re-granted by a legacy
 * role-name allow-list. Bypass roles (Super / School Super Admin) hold every
 * module. Reads only the session map materialised by MY_Controller each request.
 */
function rbac_user_holds_module(string $module): bool
{
    $CI =& get_instance();
    $role = (string) ($CI->session->userdata('admin_role') ?? '');
    if (strcasecmp($role, 'Super Admin') === 0 || strcasecmp($role, 'School Super Admin') === 0) {
        return true;
    }
    $perms  = $CI->session->userdata('rbac_permissions');
    $levels = $CI->session->userdata('rbac_levels');
    if (!is_array($perms))  { $perms  = []; }
    if (!is_array($levels)) { $levels = []; }
    return rbac_granted_level($module, $perms, $levels) !== null;
}

/**
 * Redirect target for a full-page RBAC denial — the professional "Access
 * restricted" screen (Admin::access_denied) instead of a silent bounce to the
 * dashboard.
 */
function rbac_denied_url(?string $module = null, string $level = 'view'): string
{
    $params = [];
    if ($module !== null && $module !== '') {
        $params['module'] = $module;
        $params['need']   = $level;
    }
    return 'admin/access_denied' . ($params ? '?' . http_build_query($params) : '');
}

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
            'status'     => 'error',
            'message'    => 'You don’t have access to the ' . $module . ' module.',
            'csrf_token' => $CI->security->get_csrf_hash(),
        ]);
        exit;
    }

    // Full-page: professional "Access restricted" screen (Admin::access_denied)
    // instead of a silent bounce to the dashboard.
    redirect(rbac_denied_url($module, $level));
}

// REMOVED (supersession cleanup, 2026-07-22): load_role_permissions() was a thin
// wrapper over resolve_role_access(...)['modules'] with ZERO live callers — the
// unified resolver pipeline (resolve_effective_access → resolve_role_access →
// apply_access_overrides) is the single access-resolution path now.

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
                // INSTRUMENTATION (supersession Tier-2 precondition): this fallback is
                // the SOLE permission source for un-folded tenants — it may be deleted
                // ONLY once this line logs ZERO hits fleet-wide (every school folded via
                // ensure_unified_roles). Grep `RBAC_LEGACY_FALLBACK_HIT` to measure.
                log_message('info', "RBAC_LEGACY_FALLBACK_HIT school=[{$school_id}] role=[{$role}]");
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

/**
 * Apply a staff member's PER-PERSON overrides on top of their role-resolved access.
 * This is the exception path (Unified Staff Access UI, tab 3):
 *   - `extra`  = { module => {level, ...} }  — grant a module (max with role level)
 *   - `deny`   = { module => {...} } | [module,...] — absolute prohibit, wins over all
 * Deny is applied LAST so it always beats a role grant or an extra grant.
 *
 * @param array{modules: string[], levels: array<string,string>} $access role-resolved
 * @param array $extra  per-person grants
 * @param array $deny   per-person prohibitions
 * @return array{modules: string[], levels: array<string,string>}
 */
function apply_access_overrides(array $access, array $extra, array $deny): array
{
    $modules = [];
    foreach (($access['modules'] ?? []) as $m) { $modules[$m] = true; }
    $levels = is_array($access['levels'] ?? null) ? $access['levels'] : [];

    // Extra grants — add module, raise level (never lower).
    foreach ($extra as $m => $meta) {
        if (!in_array($m, RBAC_MODULES, true)) continue;
        $lvl = is_array($meta) ? (string) ($meta['level'] ?? 'view') : (string) $meta;
        if (!isset(RBAC_LEVELS[$lvl])) $lvl = 'view';
        $modules[$m] = true;
        $levels[$m]  = isset($levels[$m]) ? rbac_max_level($levels[$m], $lvl) : $lvl;
    }

    // Denies — absolute removal (accept object-keyed OR flat-array shapes).
    $denyModules = [];
    if (is_array($deny)) {
        foreach ($deny as $k => $v) {
            $denyModules[] = is_int($k) ? (string) $v : (string) $k;
        }
    }
    foreach ($denyModules as $m) { unset($modules[$m], $levels[$m]); }

    return ['modules' => array_keys($modules), 'levels' => $levels];
}

if (!function_exists('rbac_max_level')) {
    /** Higher of two level strings by RBAC_LEVELS rank (unknown → 'view'). */
    function rbac_max_level(string $a, string $b): string
    {
        return (rbac_level_rank($a) >= rbac_level_rank($b)) ? $a : $b;
    }
}

/**
 * Full effective access for a staff doc: role union THEN per-person overrides.
 * @return array{modules: string[], levels: array<string,string>}
 */
function resolve_effective_access($firebase, string $school_id, array $staff): array
{
    $roles = [];
    foreach (['staff_roles', 'staffRoles', 'roles', 'role_ids'] as $k) {
        if (is_array($staff[$k] ?? null)) { $roles = array_map('strval', $staff[$k]); break; }
    }
    if (!empty($staff['primary_role'])) $roles[] = (string) $staff['primary_role'];
    if (!empty($staff['role']))         $roles[] = (string) $staff['role']; // label fallback
    $roles = array_values(array_unique(array_filter($roles)));

    $access = resolve_role_access($firebase, $school_id, $roles);
    $extra  = is_array($staff['extra'] ?? null) ? $staff['extra'] : [];
    $deny   = is_array($staff['deny']  ?? null) ? $staff['deny']  : [];
    return apply_access_overrides($access, $extra, $deny);
}

if (!function_exists('rbac_role_edit_error')) {
    /**
     * SECURITY (audit C2 remainder) — SHARED ceiling guard for ROLE-CONTENT editing
     * across EVERY surface that writes the role catalogue: Staff_access (Role Editor),
     * Org (Departments & Roles), and AdminUsers (Access tab). The person-assignment
     * guard stops self-ASSIGNING a high role; this stops the other self-escalation
     * route — WIDENING a role's own modules/levels/tier. For a non-god caller it
     * rejects: (a) editing the Administrator / bypass-label roles; (b) editing a role
     * the caller THEMSELVES holds; (c) editing/creating a role at or above their own
     * tier (only when their tier is reliably resolved, so an unmatched claim label
     * can't over-block). God roles (Super / School Super Admin) are exempt.
     *
     * Reads caller identity (admin_role / admin_id) + fs from the active controller
     * via get_instance(). Returns an error string to reject with, or null if allowed.
     *
     * @param array    $staffRoles the loaded catalogue (schools.staffRoles)
     * @param string   $roleRef    target role — ROLE_* id OR label ('' on create)
     * @param int|null $newTier    submitted tier on create/retier
     */
    function rbac_role_edit_error(array $staffRoles, string $roleRef, ?int $newTier = null): ?string
    {
        $CI =& get_instance();
        $callerRole = (string) (($CI->admin_role ?? null) ?: $CI->session->userdata('admin_role') ?: '');
        if (strcasecmp($callerRole, 'Super Admin') === 0
            || strcasecmp($callerRole, 'School Super Admin') === 0) {
            return null; // god roles unrestricted
        }

        // Resolve the target role (accept a ROLE_* id OR a label) → [rid, entry].
        $rid = ''; $entry = null;
        if ($roleRef !== '') {
            if (is_array($staffRoles[$roleRef] ?? null)) {
                $rid = $roleRef; $entry = $staffRoles[$roleRef];
            } else {
                foreach ($staffRoles as $k => $e) {
                    if (is_array($e) && strcasecmp((string) ($e['label'] ?? ''), $roleRef) === 0) {
                        $rid = (string) $k; $entry = $e; break;
                    }
                }
            }
        }

        // (a) Administrator / bypass roles are god-only.
        if ($rid === 'ROLE_ADMIN'
            || (is_array($entry) && in_array(strtolower((string) ($entry['label'] ?? '')),
                    ['super admin', 'school super admin', 'admin'], true))) {
            return 'Only a School Super Admin can edit administrator roles.';
        }

        // (b) Cannot edit a role the caller themselves holds (self-widening).
        if ($rid !== '') {
            $held = [];
            try {
                if (isset($CI->fs) && !empty($CI->admin_id)) {
                    $me = $CI->fs->getEntity('staff', $CI->admin_id);
                    if (is_array($me)) {
                        foreach (['staff_roles', 'staffRoles', 'roles', 'role_ids'] as $kk) {
                            if (is_array($me[$kk] ?? null)) { $held = array_map('strval', $me[$kk]); break; }
                        }
                    }
                }
            } catch (\Throwable $e) { /* best-effort; (a)/(c) still apply */ }
            foreach ($staffRoles as $k => $e) {
                if (is_array($e) && strcasecmp((string) ($e['label'] ?? ''), $callerRole) === 0) {
                    $held[] = (string) $k; break;
                }
            }
            if (in_array($rid, $held, true)) {
                return 'You cannot edit a role you currently hold. Ask another administrator.';
            }
        }

        // (c) Tier ceiling — only when the caller's tier is reliably known.
        $callerTier = null;
        foreach ($staffRoles as $e) {
            if (is_array($e) && strcasecmp((string) ($e['label'] ?? ''), $callerRole) === 0) {
                $callerTier = (int) ($e['tier'] ?? 5); break;
            }
        }
        if ($callerTier !== null) {
            if (is_array($entry) && (int) ($entry['tier'] ?? 7) <= $callerTier) {
                return 'You can only edit roles less privileged than your own.';
            }
            if ($newTier !== null && $newTier <= $callerTier) {
                return 'You cannot create or set a role at your own privilege level or higher.';
            }
        }
        return null;
    }
}
