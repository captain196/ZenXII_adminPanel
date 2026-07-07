<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_expiry_cron — Nightly scheduled task for Fleet Management.
 *
 * F1 LC-14 (2026-07-07). Scans every tenant's vehicles + vehicle documents
 * for expiry within 30 days, buckets into 7 / 15 / 30 day windows, writes
 * the `vehicleExpiryDashboardCache` cache doc, and emits Communication
 * notices for 7-day-window items (Admin + Transport Manager audience).
 *
 * CLI-only, no HTTP surface. Invocation:
 *
 *   Single tenant:
 *     SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *       php index.php transport_expiry_cron run
 *
 *   All tenants (uses Superadmin-managed tenant list, out of F1 scope —
 *   the operator drives per-tenant scheduling until multi-tenant cron
 *   convergence is done):
 *     Not implemented in F1. Per-tenant invocation is the F1 pattern.
 *
 * Exit codes:
 *   0 — computed successfully (even if 0 expiries found)
 *   1 — env vars missing / init failed
 *   2 — compute threw an unrecoverable error
 */
class Transport_expiry_cron extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Transport_expiry_cron is CLI-only.', 403);
        }
        $this->load->library('firestore_service', null, 'fs');
        $this->load->library('numbering_service', null, 'numbering');
        $this->load->library('transport_service', null, 'transport');
    }

    public function run(): void
    {
        $schoolId    = (string) (getenv('SCHOOL_ID')    ?: '');
        $sessionYear = (string) (getenv('SESSION_YEAR') ?: '');
        if ($schoolId === '' || $sessionYear === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID and SESSION_YEAR env vars are required.\n");
            exit(1);
        }
        try {
            $this->fs->init($schoolId, $sessionYear);
            $this->numbering->init($this->fs, $schoolId, $sessionYear);
            $this->transport->init($this->fs, $this->numbering, $schoolId, $sessionYear, 'transport_expiry_cron');
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: init failed — " . $e->getMessage() . "\n");
            exit(1);
        }

        $started = microtime(true);
        try {
            $summary = $this->transport->computeFleetExpiryDashboard();
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: computeFleetExpiryDashboard threw — " . $e->getMessage() . "\n");
            exit(2);
        }
        $duration = (int) round((microtime(true) - $started) * 1000);
        echo sprintf(
            "transport_expiry_cron tenant=%s session=%s "
            . "expiring_7d=%d expiring_15d=%d expiring_30d=%d duration_ms=%d\n",
            $schoolId,
            $sessionYear,
            (int) ($summary['count_7_days']  ?? 0),
            (int) ($summary['count_15_days'] ?? 0),
            (int) ($summary['count_30_days'] ?? 0),
            $duration
        );
        exit(0);
    }
}
