<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * TEMPORARY RBAC DIAGNOSTIC — delete after use.
 * Visit /rbac_debug while logged in AS THE TEACHER to see exactly what level
 * Attendance resolves to for that session, and whether the role matched the
 * staffRoles catalogue or fell back to legacy schools.roles.
 */
class Rbac_debug extends MY_Controller
{
    public function index()
    {
        header('Content-Type: application/json');

        $role   = (string) ($this->admin_role ?? '');
        $perms  = $this->session->userdata('rbac_permissions');
        $levels = $this->session->userdata('rbac_levels');
        if (!is_array($perms))  $perms  = [];
        if (!is_array($levels)) $levels = [];

        // What the catalogue actually stores for this role.
        $schoolDoc  = $this->fs->get('schools', $this->school_name);
        $staffRoles = (is_array($schoolDoc) && is_array($schoolDoc['staffRoles'] ?? null)) ? $schoolDoc['staffRoles'] : [];
        $legacy     = (is_array($schoolDoc) && is_array($schoolDoc['roles'] ?? null))      ? $schoolDoc['roles']      : [];

        $matchedRid = null; $matchedEntry = null;
        foreach ($staffRoles as $rid => $r) {
            if (!is_array($r)) continue;
            if ((string) ($r['label'] ?? '') === $role || (string) $rid === $role) {
                $matchedRid = $rid;
                $matchedEntry = [
                    'label'            => $r['label'] ?? null,
                    'has_Attendance'   => in_array('Attendance', (array) ($r['permissions'] ?? []), true),
                    'permissionLevels_Attendance' => ($r['permissionLevels']['Attendance'] ?? '(none → defaults manage)'),
                ];
                break;
            }
        }

        echo json_encode([
            'admin_role'            => $role,
            'is_bypass'             => in_array($role, RBAC_BYPASS_ROLES, true),
            'school_firebase_key'   => $this->school_name,
            'admin_id'              => $this->admin_id,
            'session_rbac_levels_Attendance' => $levels['Attendance'] ?? '(not in session → would default manage)',
            'rbac_granted_level_Attendance'  => rbac_granted_level('Attendance', $perms, $levels),
            'has_permission_Attendance' => [
                'view'   => has_permission('Attendance', 'view'),
                'edit'   => has_permission('Attendance', 'edit'),
                'manage' => has_permission('Attendance', 'manage'),
            ],
            'catalogue_match' => [
                'matched_staffRoles_rid' => $matchedRid,
                'matched_entry'          => $matchedEntry,
                'staffRoles_labels'      => array_map(function ($r) { return is_array($r) ? ($r['label'] ?? null) : null; }, $staffRoles),
                'legacy_roles_has_this_role' => isset($legacy[$role]),
            ],
            'session_rbac_permissions' => $perms,
            'session_rbac_levels'      => $levels,
        ], JSON_PRETTY_PRINT);
    }
}
