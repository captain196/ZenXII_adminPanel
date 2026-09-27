<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Campus_access_cron — scheduled sweep for Campus Access Management (MVP).
 * Renamed from Visitor_cron.php 2026-08-09 (module identity rename).
 *
 * Three jobs, one cron, per the Transport_expiry_cron precedent (one
 * periodic controller per module rather than one-controller-per-job):
 *   1. Auto-approve pending_approval VISITOR passes idle past 10 minutes
 *      (never for a watchlist `block` hit — always needs a human override).
 *      Gate-pass (student/staff) requests are NEVER auto-approved — that's
 *      a deliberate, separate policy (2026-08-09) — so there is no
 *      equivalent sweep for campusAccessRequests.
 *   2. Flag on-campus visitors past their expected duration as `overstay`
 *      and fire the Ops bell notice once per pass.
 *   3. Reconcile any visitPasses whose campusAccessEvents write-through
 *      failed (campusEventSyncedIn/Out left false) — idempotent, safe to
 *      run every tick even when there's nothing to fix.
 *
 * CLI-only, no HTTP surface, per-tenant invocation (multi-tenant cron
 * convergence is out of MVP scope, matching Transport_expiry_cron):
 *
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php campus_access_cron run
 *
 * Suggested schedule: every 5 minutes (the auto-approve window is only
 * 10 minutes; a coarser cron would blow past it).
 *
 * Exit codes: 0 = ran (even if nothing to do); 1 = env vars missing / init
 * failed; 2 = a sweep threw an unrecoverable error.
 */
class Campus_access_cron extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Campus_access_cron is CLI-only.', 403);
        }
        $this->load->library('firestore_service', null, 'fs');
        $this->load->library('numbering_service', null, 'numbering');
        $this->load->library('visitor_service', null, 'visitor');
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
        // class = operational. Visitor/gate sweeps are day-to-day school
        // operations: they stop when the tenant is suspended or the school
        // is closed, and continue through CLOSING (the school is still open
        // and still receiving visitors until its effective closure date).
        $this->load->helper('lifecycle_guard');
        lifecycle_guard_or_skip($this->firebase, $schoolId, Lifecycle_resolver::JOB_OPERATIONAL, 'campus_access_cron');

        try {
            $this->fs->init($schoolId, $sessionYear);
            $this->numbering->init($this->fs, $schoolId, $sessionYear);
            $this->visitor->init($this->fs, $this->numbering, $schoolId, $sessionYear, 'campus_access_cron');
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: init failed — " . $e->getMessage() . "\n");
            exit(1);
        }

        $started = microtime(true);
        $autoApproved = [];
        $overstay = [];
        $reconciled = [];
        try {
            $autoApproved = $this->visitor->sweepAutoApprove();
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: sweepAutoApprove threw — " . $e->getMessage() . "\n");
            exit(2);
        }
        try {
            $overstay = $this->visitor->sweepOverstay();
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: sweepOverstay threw — " . $e->getMessage() . "\n");
            exit(2);
        }
        try {
            $reconciled = $this->visitor->reconcileCampusEvents();
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: reconcileCampusEvents threw — " . $e->getMessage() . "\n");
            exit(2);
        }

        $duration = (int) round((microtime(true) - $started) * 1000);
        echo sprintf(
            "campus_access_cron tenant=%s session=%s auto_approved=%d overstay_flagged=%d ledger_reconciled=%d duration_ms=%d\n",
            $schoolId, $sessionYear, count($autoApproved), count($overstay), count($reconciled), $duration
        );
        exit(0);
    }
}
