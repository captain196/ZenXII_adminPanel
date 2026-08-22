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
        $this->load->library('transport_compliance_service', null, 'cmp');
    }

    public function run(): void
    {
        $schoolId    = (string) (getenv('SCHOOL_ID')    ?: '');
        $sessionYear = (string) (getenv('SESSION_YEAR') ?: '');
        if ($schoolId === '' || $sessionYear === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID and SESSION_YEAR env vars are required.\n");
            exit(1);
        }

        // ── LIFECYCLE GUARD ─────────────────────────────────────────────
        // class = notification. Document-expiry reminders are outbound
        // messages to school staff; they stop for a suspended or closed
        // tenant and continue through CLOSING (a closing school still has
        // vehicles on the road with documents that must stay valid).
        $this->load->helper('lifecycle_guard');
        lifecycle_guard_or_skip($this->firebase, $schoolId, Lifecycle_resolver::JOB_NOTIFICATION, 'transport_expiry_cron');

        try {
            $this->fs->init($schoolId, $sessionYear);
            $this->numbering->init($this->fs, $schoolId, $sessionYear);
            $this->transport->init($this->fs, $this->numbering, $schoolId, $sessionYear, 'transport_expiry_cron');
            $this->cmp->init($this->fs, $schoolId, 'transport_expiry_cron');
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

        // F4 (2026-07-07) — Refinement #7: extend the same nightly cron to
        // auto-resolve temporary driver assignments whose effective_to has
        // passed. Keeps ops surface small — one nightly cron file handles
        // both fleet expiries + driver-assignment expiries.
        $tempResolved = 0;
        try {
            $tr = $this->transport->autoResolveExpiredTempAssignments();
            $tempResolved = (int) ($tr['resolved'] ?? 0);
        } catch (\Throwable $e) {
            log_message('error', 'transport_expiry_cron auto-resolve temp assignments failed: ' . $e->getMessage());
        }

        // F12.1 M5 (2026-08-07) — nightly CBSE compliance re-evaluation, folded
        // into this same cron rather than added as a new controller (spec
        // §10-M5, and the F4 precedent directly above: one nightly Transport
        // cron, not three).
        //
        // Why it belongs here: several compliance rules are `document_expiry`
        // evaluations over the very fields this cron already scans, so posture
        // must be recomputed on the same clock — otherwise a certificate that
        // lapsed overnight shows in the expiry dashboard while the compliance
        // tier still reads Compliant until someone clicks Re-evaluate.
        //
        // Non-fatal by design, exactly like the F4 block: a compliance failure
        // must never stop the fleet-expiry cron from reporting, and
        // rebuildFleetPosture() is itself per-vehicle isolated internally.
        $cmpOk = 0; $cmpTotal = 0; $cmpFailed = 0;
        try {
            $cr        = $this->cmp->rebuildFleetPosture(/* evaluate */ true);
            $cmpTotal  = (int) ($cr['total'] ?? 0);
            $cmpOk     = (int) ($cr['ok'] ?? 0);
            $cmpFailed = count((array) ($cr['failed'] ?? []));
            if ($cmpFailed > 0) {
                log_message('error', "transport_expiry_cron compliance rebuild: {$cmpFailed} vehicle(s) failed — "
                    . json_encode(array_slice((array) $cr['failed'], 0, 5)));
            }
        } catch (\Throwable $e) {
            log_message('error', 'transport_expiry_cron compliance rebuild failed: ' . $e->getMessage());
        }

        $duration = (int) round((microtime(true) - $started) * 1000);
        echo sprintf(
            "transport_expiry_cron tenant=%s session=%s "
            . "expiring_7d=%d expiring_15d=%d expiring_30d=%d "
            . "temp_assignments_auto_resolved=%d "
            . "compliance_evaluated=%d/%d compliance_failed=%d duration_ms=%d\n",
            $schoolId,
            $sessionYear,
            (int) ($summary['count_7_days']  ?? 0),
            (int) ($summary['count_15_days'] ?? 0),
            (int) ($summary['count_30_days'] ?? 0),
            $tempResolved,
            $cmpOk,
            $cmpTotal,
            $cmpFailed,
            $duration
        );
        exit(0);
    }
}
