<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Department / staff-role normalization helpers.
 *
 * Staff docs carry the department NAME (free text), and counts, delete-guards
 * and dept↔staff joins all key on that name. To stay robust to case/whitespace
 * drift between a staff doc's Department and the department doc's `name`
 * (e.g. Excel-imported "Science " vs legacy "science" vs "Science"), match on
 * this canonical key everywhere instead of raw/exact comparison.
 */
if (!function_exists('dept_key')) {
    function dept_key($name): string
    {
        $n = trim((string) $name);
        return function_exists('mb_strtolower') ? mb_strtolower($n, 'UTF-8') : strtolower($n);
    }
}

if (!function_exists('derive_role_id')) {
    /**
     * Turn a human role NAME into a stable, collision-safe ROLE_* id so users
     * never type an internal key — they type "Counsellor", we make ROLE_COUNSELLOR.
     * Non-alphanumerics → underscore, uppercased, capped at 40 chars, and deduped
     * against $existing (an id-keyed map) with a numeric suffix.
     */
    function derive_role_id(string $label, array $existing = []): string
    {
        $slug = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $label));
        $slug = trim($slug, '_');
        $id   = ($slug === '') ? 'ROLE_CUSTOM' : 'ROLE_' . $slug;
        if (strlen($id) > 40) { $id = rtrim(substr($id, 0, 40), '_'); }
        $base = $id;
        $n = 2;
        while (isset($existing[$id])) { $id = $base . '_' . $n; $n++; }
        return $id;
    }
}

if (!function_exists('set_role_access')) {
    /**
     * Set the ACCESS (module permissions) facet of ONE unified role, matched by
     * its ROLE_* id OR its label. Pure/testable: takes the staffRoles map, returns
     *   ['staffRoles'=>updated map, 'rid'=>ROLE_* id, 'label'=>..., 'permissions'=>clean]
     * or NULL if the role isn't found. Only the `permissions` field changes — the
     * role's identity/HR fields are untouched. Permissions are whitelisted to
     * $modules (so a stray/sentinel value like '_none_' is dropped → cleared).
     */
    function set_role_access(array $staffRoles, string $roleRef, array $permissions, array $modules): ?array
    {
        $rid = isset($staffRoles[$roleRef]) && is_array($staffRoles[$roleRef]) ? $roleRef : null;
        if ($rid === null) {
            foreach ($staffRoles as $k => $r) {
                if (is_array($r) && (string) ($r['label'] ?? '') === $roleRef) { $rid = $k; break; }
            }
        }
        if ($rid === null || !is_array($staffRoles[$rid])) return null;

        $clean = array_values(array_intersect($permissions, $modules));
        $staffRoles[$rid]['permissions'] = $clean;
        return [
            'staffRoles'  => $staffRoles,
            'rid'         => $rid,
            'label'       => (string) ($staffRoles[$rid]['label'] ?? $rid),
            'permissions' => $clean,
        ];
    }
}

if (!function_exists('ensure_unified_roles')) {
    /**
     * Idempotent per-school upgrade to the SINGLE unified role catalogue.
     *
     * One role entity (schools.staffRoles[ROLE_*]) now serves both staff (HR
     * attrs) and admins (RBAC permissions[]). This:
     *   1. Seeds any missing unified default role (adds ROLE_ADMIN … with perms).
     *   2. Backfills permissions/tier/sort_order onto pre-merge staff roles.
     *   3. Folds the legacy RBAC map (schools.roles[name]) into the unified
     *      catalogue BY LABEL — admins' claims carry the label, so after this an
     *      admin's permissions resolve straight from staffRoles. Custom RBAC roles
     *      get their own ROLE_* entry (label = the old name) so nobody is dropped.
     *
     * Safe to run on every load — writes only when something actually changed.
     * The legacy schools.roles map is left untouched (load_role_permissions keeps
     * it as a read-only fallback for any not-yet-folded tenant).
     *
     * @return bool  TRUE if a write occurred.
     */
    function ensure_unified_roles($fs, string $school_id): bool
    {
        if (empty($fs) || $school_id === '') return false;
        try {
            $school = $fs->get('schools', $school_id);
        } catch (\Throwable $e) {
            log_message('error', 'ensure_unified_roles read failed: ' . $e->getMessage());
            return false;
        }
        if (!is_array($school)) return false;

        $staffRoles = is_array($school['staffRoles'] ?? null) ? $school['staffRoles'] : [];
        $legacyRbac = is_array($school['roles'] ?? null)      ? $school['roles']      : [];
        $modules    = defined('RBAC_MODULES') ? RBAC_MODULES : [];
        $changed    = false;

        // Canonical defaults live on the Staff controller (single source).
        if (!class_exists('Staff', false)) {
            @require_once APPPATH . 'controllers/Staff.php';
        }
        $defaults = (class_exists('Staff', false) && method_exists('Staff', 'default_staff_roles'))
            ? Staff::default_staff_roles() : [];

        // 1 + 2 — seed missing defaults; backfill RBAC fields on existing roles.
        foreach ($defaults as $rid => $def) {
            if (!isset($staffRoles[$rid]) || !is_array($staffRoles[$rid])) {
                $staffRoles[$rid] = $def;
                $changed = true;
                continue;
            }
            foreach (['permissions', 'tier', 'sort_order', 'description'] as $f) {
                if (!isset($staffRoles[$rid][$f]) && isset($def[$f])) {
                    $staffRoles[$rid][$f] = $def[$f];
                    $changed = true;
                }
            }
        }

        // 3 — fold legacy RBAC roles by label.
        $byLabel = [];
        foreach ($staffRoles as $rid => $r) {
            if (is_array($r) && isset($r['label'])) $byLabel[(string) $r['label']] = $rid;
        }
        foreach ($legacyRbac as $name => $rbac) {
            if (!is_array($rbac)) continue;
            $name  = (string) $name;
            $perms = is_array($rbac['permissions'] ?? null)
                ? array_values(array_intersect($rbac['permissions'], $modules)) : [];

            if (isset($byLabel[$name])) {
                $rid = $byLabel[$name];
                if (empty($staffRoles[$rid]['permissions']) && !empty($perms)) {
                    $staffRoles[$rid]['permissions'] = $perms;
                    $changed = true;
                }
                foreach (['tier', 'sort_order'] as $f) {
                    if (!isset($staffRoles[$rid][$f]) && isset($rbac[$f])) {
                        $staffRoles[$rid][$f] = $rbac[$f];
                        $changed = true;
                    }
                }
            } else {
                $rid = 'ROLE_' . strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', trim($name)));
                $rid = rtrim($rid, '_');
                if ($rid === 'ROLE' || $rid === 'ROLE_') $rid = 'ROLE_CUSTOM_' . substr(md5($name), 0, 6);
                if (isset($staffRoles[$rid]))            $rid .= '_' . substr(md5($name), 0, 4);
                $staffRoles[$rid] = [
                    'label'           => $name,
                    'description'     => (string) ($rbac['description'] ?? ''),
                    'category'        => 'Administrative',
                    'flags'           => [],
                    'attendance_type' => 'standard',
                    'is_system'       => !empty($rbac['is_system']),
                    'tier'            => (int) ($rbac['tier'] ?? 7),
                    'sort_order'      => (int) ($rbac['sort_order'] ?? 100),
                    'permissions'     => $perms,
                ];
                $byLabel[$name] = $rid;
                $changed = true;
            }
        }

        if ($changed) {
            try {
                $fs->set('schools', $school_id, ['staffRoles' => $staffRoles], true);
            } catch (\Throwable $e) {
                log_message('error', 'ensure_unified_roles write failed: ' . $e->getMessage());
                return false;
            }
        }
        return $changed;
    }
}
