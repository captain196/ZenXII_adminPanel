<?php
defined('BASEPATH') or exit('No direct script access allowed');
/**
 * Rbac_transport_impact — READ-ONLY. Which roles keep/lose Transport access
 * once the legacy role-name fallback is retired.
 * Performs NO writes.  php index.php rbac_transport_impact run SCH_XXXX
 */
class Rbac_transport_impact extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) { show_error('CLI-only.', 403); }
        $this->load->library('firestore_service', null, 'fs');
        $this->load->helper('rbac');
    }
    public function run(string $schoolId = ''): void
    {
        if ($schoolId === '') { fwrite(STDERR, "usage: run <SCHOOL_ID>\n"); exit(1); }
        $this->fs->init($schoolId, (string) (getenv('SESSION_YEAR') ?: '2026-27'));
        $school = $this->fs->get('schools', $schoolId);
        $roles  = is_array($school['staffRoles'] ?? null) ? $school['staffRoles'] : [];
        $bypass = defined('RBAC_BYPASS_ROLES') ? RBAC_BYPASS_ROLES : ['Super Admin','School Super Admin'];

        printf("%-26s %-9s %-9s %-8s %s\n", 'ROLE LABEL', 'Transport', 'Operatns', 'LEVEL', 'AFTER RETIREMENT');
        echo str_repeat('-', 92) . "\n";
        $keep = $lose = 0;
        foreach ($roles as $rid => $r) {
            if (!is_array($r)) { continue; }
            $label = (string) ($r['label'] ?? $rid);
            $perms = (array) ($r['permissions'] ?? []);
            $lv    = is_array($r['permissionLevels'] ?? null) ? $r['permissionLevels'] : [];
            $hasT  = in_array('Transport', $perms, true);
            $hasO  = in_array('Operations', $perms, true);
            $level = $hasT ? ($lv['Transport'] ?? 'manage') : ($hasO ? ($lv['Operations'] ?? 'manage') : '-');
            if (in_array($label, $bypass, true)) { $verdict = 'KEEPS (bypass role)'; $keep++; }
            elseif ($hasT || $hasO)              { $verdict = 'KEEPS (graded @ ' . $level . ')'; $keep++; }
            else                                 { $verdict = '*** LOSES ACCESS ***'; $lose++; }
            printf("%-26s %-9s %-9s %-8s %s\n", substr($label,0,26),
                $hasT?'yes':'no', $hasO?'yes':'no', $level, $verdict);
        }
        echo str_repeat('-', 92) . "\n";
        echo "  roles keeping Transport access : {$keep}\n";
        echo "  roles LOSING Transport access  : {$lose}\n";
        echo "  (remediation is a DATA grant — add Transport or Operations to the role — not code)\n";
        exit(0);
    }
}
