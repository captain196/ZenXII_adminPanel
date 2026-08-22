<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_gps_cron — F13.1 M2 stale live-GPS session sweeper.
 *
 * A driver device that crashes, is force-stopped, or runs out of battery never
 * closes its tracking session. Without a sweep the hot doc survives with
 * `live_tracking_enabled = true`, and a parent's map shows a frozen bus
 * indefinitely. `completeTrip` / `cancelTrip` handle the normal path; this
 * handles the abnormal one.
 *
 * A SEPARATE controller rather than an extension of `Transport_expiry_cron`:
 * that file is frozen F1–F12.1 code, and the D-2 exception covers only
 * `startTrip` / `completeTrip` / `cancelTrip`. Adding a new controller keeps
 * the sweeper fully additive.
 *
 * Authority is the TRIP, never the position — a session is stale when its trip
 * is no longer InProgress, or when no fix has arrived for
 * `Transport_gps_service::STALE_SESSION_AFTER_S`. Judging from the trip means a
 * bus genuinely parked on a live trip is never swept out from under its driver.
 *
 * CLI-only, no HTTP surface. Per-tenant invocation, matching the F1 cron pattern:
 *
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_gps_cron run
 *
 * Add `--dry-run` (or DRY_RUN=1) to report what WOULD be closed and close
 * nothing — the repo convention for scripts that delete.
 *
 * Suggested schedule: every 15 minutes. The sweep is a single bounded query
 * plus one point-read per open session, so it is cheap; there are only ever as
 * many hot docs as there are buses.
 *
 * Exit codes:
 *   0 — swept successfully (even if nothing was stale)
 *   1 — env vars missing / init failed
 *   2 — sweep threw an unrecoverable error
 */
class Transport_gps_cron extends CI_Controller
{
    /** Upper bound on sessions examined per run — a bus fleet, not a scan. */
    private const SCAN_LIMIT = 200;

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Transport_gps_cron is CLI-only.', 403);
        }
        $this->load->library('firestore_service', null, 'fs');
        $this->load->library('transport_gps_service', null, 'gps');
    }

    public function run(): void
    {
        $schoolId    = (string) (getenv('SCHOOL_ID')    ?: '');
        $sessionYear = (string) (getenv('SESSION_YEAR') ?: '');
        if ($schoolId === '' || $sessionYear === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID and SESSION_YEAR environment variables are required.\n");
            exit(1);
        }

        // ── LIFECYCLE GUARD — SAFETY CLASS (deliberately permissive) ────
        // class = safety. This sweep CLOSES STALE live-GPS sessions; without
        // it a parent's map shows a frozen bus. Blocking it would degrade a
        // safety surface for a commercial reason, which the design forbids:
        // a bus with children aboard is never a billing lever. The call is
        // made explicitly, rather than omitted, so that the guard-coverage
        // test can prove this job was CLASSIFIED rather than FORGOTTEN.
        // JOB_SAFETY is permitted in every commercial state; the only states
        // that stop it are CLOSED/ARCHIVED, where no trip can exist at all.
        $this->load->helper('lifecycle_guard');
        lifecycle_guard_or_skip($this->firebase, $schoolId, Lifecycle_resolver::JOB_SAFETY, 'transport_gps_cron');

        $argv   = (array) ($_SERVER['argv'] ?? []);
        $dryRun = in_array('--dry-run', $argv, true) || (string) getenv('DRY_RUN') === '1';

        echo "Transport_gps_cron — stale live-GPS session sweep\n";
        echo "  tenant={$schoolId} session={$sessionYear}"
           . ($dryRun ? "  [DRY RUN — nothing will be closed]\n" : "\n");
        echo str_repeat('-', 66) . "\n";

        try {
            $this->fs->init($schoolId, $sessionYear);
            $this->gps->init($this->fs, $schoolId, 'gps_cron');

            if ($dryRun) {
                $report = $this->_dryRun();
                echo "  scanned={$report['scanned']}  would_close={$report['would_close']}  keep={$report['kept']}\n";
                foreach ($report['details'] as $d) { echo "    • {$d}\n"; }
            } else {
                $r = $this->gps->sweepStaleSessions(self::SCAN_LIMIT);
                echo "  scanned={$r['scanned']}  closed={$r['closed']}  kept={$r['kept']}\n";
                foreach ($r['details'] as $d) { echo "    • {$d}\n"; }
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'ERROR: sweep failed — ' . $e->getMessage() . "\n");
            exit(2);
        }

        echo str_repeat('-', 66) . "\n";
        echo "done\n";
        exit(0);
    }

    /**
     * Report-only pass. Deliberately reimplements the staleness decision here
     * rather than adding a `$dryRun` flag to the service: a delete path that
     * can be told not to delete is one boolean away from deleting when it
     * should not.
     *
     * @return array{scanned:int, would_close:int, kept:int, details:array<int,string>}
     */
    private function _dryRun(): array
    {
        $rows = (array) $this->fs->schoolWhere(
            Transport_gps_service::COL_LIVE_POSITIONS, [], null, 'ASC', self::SCAN_LIMIT
        );
        $now = time();
        $would = 0; $kept = 0; $details = [];

        foreach ($rows as $row) {
            $d   = (isset($row['data']) && is_array($row['data'])) ? $row['data'] : $row;
            $vid = trim((string) ($d['vehicleId'] ?? ''));
            $tid = trim((string) ($d['tripId'] ?? ''));
            if ($vid === '') { continue; }

            $reason = '';
            if ($tid === '') {
                $reason = 'no tripId on the session doc';
            } else {
                $trip = null;
                try { $trip = $this->fs->getEntity('tripLogs', $tid); } catch (\Throwable $_) {}
                if (!is_array($trip)) {
                    $reason = 'trip missing';
                } elseif ((string) ($trip['status'] ?? '') !== 'InProgress') {
                    $reason = 'trip status=' . (string) ($trip['status'] ?? '?');
                }
            }
            if ($reason === '') {
                $rec = (string) ($d['recorded_at'] ?? '');
                $ts  = $rec !== '' ? strtotime($rec) : false;
                if ($ts === false) { $ts = strtotime((string) ($d['session_started_at'] ?? '')) ?: 0; }
                if ($ts > 0 && ($now - $ts) > Transport_gps_service::STALE_SESSION_AFTER_S) {
                    $reason = 'no fix for ' . (int) round(($now - $ts) / 60) . ' min';
                }
            }

            if ($reason === '') { $kept++; continue; }
            $would++;
            $details[] = "{$vid} (trip {$tid}): {$reason}";
        }
        return ['scanned' => count($rows), 'would_close' => $would, 'kept' => $kept, 'details' => $details];
    }
}
