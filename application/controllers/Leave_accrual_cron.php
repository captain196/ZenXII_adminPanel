<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Leave_accrual_cron — PLATFORM monthly leave accrual for the whole fleet.
 *
 * One CLI invocation processes EVERY eligible tenant. Session-less (extends
 * CI_Controller, not MY_Controller), so it runs where the panel's session-auth
 * spine cannot — the same reason Timetable_projection_cron / Fees_late_fee_cron
 * are CI_Controllers. It reuses the SINGLE accrual authority
 * (Leave_accrual_service) that the HR web path also uses — no duplicated rules.
 *
 *   php index.php leave_accrual_cron run_all
 *   THROUGH_MONTH=2026-09 php index.php leave_accrual_cron run_all
 *
 * Wired into the platform scheduler as job 'leave_accrual' in
 * scripts/run_scheduled.php, which records each run to scheduled_job_runs/{runId}
 * and alerts on a non-zero exit.
 *
 * TENANT-SAFE: each tenant runs under its OWN fs.init() context inside its OWN
 * try/catch, so one broken/slow school never stops the rest and no write can
 * reach another tenant's balances. Tenant discovery reuses the canonical, shared
 * Timetable_projection_cron::tenantFromSchoolDoc (SCH_ id shape, status,
 * adminDisabled-as-map, currentSession) — never a name blacklist.
 * IDEMPOTENT: the engine's lastAccrualMonth marker means a second run in the
 * same month credits nothing — safe to run daily.
 * lifecycle_guard_check (NOT _or_skip, which exit()s and would strand every
 * later tenant) gates suspended/closing/closed schools.
 *
 * Exit: 0 when every eligible tenant was processed or correctly skipped; 1 if
 * any tenant threw (the rest still ran); 2 if the tenant list could not be read.
 */
class Leave_accrual_cron extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Leave_accrual_cron is CLI-only.', 403);
        }
        $this->load->library('firestore_service', null, 'fs');
    }

    public function run_all(): void
    {
        $throughMonth = trim((string) getenv('THROUGH_MONTH'));
        if (!preg_match('/^\d{4}-\d{2}$/', $throughMonth)) $throughMonth = date('Y-m');

        require_once APPPATH . 'controllers/Timetable_projection_cron.php';
        require_once APPPATH . 'libraries/Leave_accrual_service.php';
        $this->load->helper('lifecycle_guard');

        echo "Leave accrual — ALL ELIGIBLE TENANTS (through {$throughMonth})\n\n";

        try {
            $rows = $this->firebase->firestoreQueryOrFail('schools', [], null, 'ASC', 5000);
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: could not list tenants — " . $e->getMessage() . "\n");
            exit(2);
        }

        $t = ['tenants' => 0, 'accrued' => 0, 'off' => 0, 'skipped' => 0, 'failed' => 0, 'credits' => 0];
        foreach ((array) $rows as $row) {
            $id = (string) ($row['id'] ?? '');
            $d  = is_array($row['data'] ?? null) ? $row['data'] : $row;

            $verdict = \Timetable_projection_cron::tenantFromSchoolDoc($id, $d);
            if ($verdict['eligible'] !== true) {
                if ($verdict['reason'] !== 'not_a_tenant_document') $t['skipped']++;
                continue;
            }
            $schoolId = $verdict['schoolId'];
            $session  = $verdict['session'];
            $t['tenants']++;

            try {
                $gate = lifecycle_guard_check($this->firebase, $schoolId, \Lifecycle_resolver::JOB_OPERATIONAL);
                if (empty($gate['allowed'])) {
                    $t['skipped']++;
                    echo "  SKIP    {$schoolId}  lifecycle: " . (string) ($gate['code'] ?? 'not allowed') . "\n";
                    continue;
                }

                // Per-tenant context: a FRESH service bound to THIS tenant's fs.
                $this->fs->init($schoolId, $session);
                $svc = (new Leave_accrual_service())->init($this->firebase, $this->fs, $schoolId, $session);

                if (!$svc->schoolAccrualEnabled()) { $t['off']++; continue; }

                $r = $svc->accrueSchool($throughMonth);
                $t['accrued']++;
                $t['credits'] += (int) ($r['creditsIssued'] ?? 0);
                echo "  ACCRUE  {$schoolId}  {$r['staffProcessed']} staff, {$r['creditsIssued']} credit(s)\n";
            } catch (\Throwable $e) {
                $t['failed']++;
                log_message('error', "leave_accrual_cron {$schoolId}: " . $e->getMessage());
                fwrite(STDERR, "  FAILED  {$schoolId}  " . $e->getMessage() . "\n");
            }
        }

        printf("\ntenants=%d accrued=%d off=%d skipped=%d FAILED=%d credits=%d\n",
            $t['tenants'], $t['accrued'], $t['off'], $t['skipped'], $t['failed'], $t['credits']);
        exit($t['failed'] > 0 ? 1 : 0);
    }
}
