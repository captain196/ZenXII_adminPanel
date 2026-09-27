<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Rbac_levels_inspect — READ-ONLY diagnostic (2026-08-07).
 *
 * Dumps the RAW `schools/{schoolId}.staffRoles` sub-document exactly as stored in
 * Firestore, so the presence or absence of `permissionLevels` can be verified
 * against the source of truth rather than a transformed API view
 * (`Staff_access::get_catalogue` runs every role through `_role_out()`).
 *
 * Performs NO writes of any kind. Safe to delete once the question is settled.
 *
 *   php index.php rbac_levels_inspect run SCH_D94FE8F7AD
 *   php index.php rbac_levels_inspect run SCH_D94FE8F7AD ROLE_TRANSPORT_MANAGER   # full JSON of one role
 */
class Rbac_levels_inspect extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) { show_error('CLI-only.', 403); }
        $this->load->library('firestore_service', null, 'fs');
    }

    public function run(string $schoolId = '', string $roleId = ''): void
    {
        if ($schoolId === '') { fwrite(STDERR, "usage: rbac_levels_inspect run <SCHOOL_ID> [ROLE_ID]\n"); exit(1); }
        $this->fs->init($schoolId, (string) (getenv('SESSION_YEAR') ?: '2026-27'));

        $school = $this->fs->get('schools', $schoolId);
        if (!is_array($school)) { fwrite(STDERR, "school not found: {$schoolId}\n"); exit(1); }

        $roles = is_array($school['staffRoles'] ?? null) ? $school['staffRoles'] : [];
        echo "RAW schools/{$schoolId}.staffRoles — " . count($roles) . " roles\n";
        echo str_repeat('=', 78) . "\n";

        if ($roleId !== '') {
            echo json_encode($roles[$roleId] ?? ['(role not present)'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            exit(0);
        }

        $noKey = 0; $emptyMap = 0; $populated = 0;
        foreach ($roles as $rid => $r) {
            if (!is_array($r)) { continue; }
            $perms = (array) ($r['permissions'] ?? []);
            $hasKey = array_key_exists('permissionLevels', $r);
            $lv = is_array($r['permissionLevels'] ?? null) ? $r['permissionLevels'] : [];

            if (!$hasKey)          { $state = 'KEY ABSENT   '; $noKey++; }
            elseif (count($lv)===0){ $state = 'EMPTY MAP    '; $emptyMap++; }
            else                   { $state = 'POPULATED    '; $populated++; }

            printf("  %-28s %s modules=%-3d levels=%-3d %s\n",
                $rid, $state, count($perms), count($lv),
                count($perms) > 0 && count($lv) === 0 ? '  <-- holds modules, NO levels => all resolve manage' : '');
        }

        echo str_repeat('-', 78) . "\n";
        echo "  permissionLevels key ABSENT : {$noKey}\n";
        echo "  permissionLevels EMPTY map  : {$emptyMap}\n";
        echo "  permissionLevels POPULATED  : {$populated}\n";
        exit(0);
    }
}
