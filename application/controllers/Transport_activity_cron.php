<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_activity_cron — F4 Driver Activity Summary (2026-07-07).
 *
 * Nightly scheduled task that iterates every driver-flagged staff at the
 * given tenant and computes their driverActivitySummary for the current
 * calendar month. Per Refinement (F4 #4): nightly cron + admin
 * on-demand rebuild button.
 *
 * CLI-only. Invocation:
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_activity_cron run
 *
 * Exit codes:
 *   0 — computed (even if 0 drivers found)
 *   1 — env / init failed
 *   2 — unrecoverable compute error
 */
class Transport_activity_cron extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Transport_activity_cron is CLI-only.', 403);
        }
        $this->load->library('firestore_service', null, 'fs');
        $this->load->library('numbering_service', null, 'numbering');
        $this->load->library('transport_service', null, 'transport');
    }

    public function run(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR env vars required.\n");
            exit(1);
        }

        // ── LIFECYCLE GUARD ─────────────────────────────────────────────
        // class = operational (NOT safety). This job sweeps activity records;
        // it does not run trips, GPS or SOS, so pausing it on a suspended or
        // closed tenant removes no safety capability. Live-trip, GPS and SOS
        // paths are classified JOB_SAFETY and are never gated.
        $this->load->helper('lifecycle_guard');
        lifecycle_guard_or_skip($this->firebase, $sid, Lifecycle_resolver::JOB_OPERATIONAL, 'transport_activity_cron');

        try {
            $this->fs->init($sid, $sess);
            $this->numbering->init($this->fs, $sid, $sess);
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'transport_activity_cron');
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: init failed — " . $e->getMessage() . "\n");
            exit(1);
        }

        $started = microtime(true);
        try {
            $r = $this->transport->rebuildActivitySummary('', date('Y-m'));
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: rebuild threw — " . $e->getMessage() . "\n");
            exit(2);
        }
        $duration = (int) round((microtime(true) - $started) * 1000);
        echo sprintf(
            "transport_activity_cron tenant=%s session=%s year_month=%s "
            . "rebuilt_count=%d duration_ms=%d\n",
            $sid, $sess, $r['year_month'], (int) $r['rebuilt_count'], $duration
        );
        exit(0);
    }
}
