<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_perf3 — live-ops onboard count is O(1) queries (PERF-3).
 *
 * getLiveOperationsSnapshot() issued ONE transportAttendance query per
 * InProgress trip (N+1) to compute students_currently_onboard, even though the
 * data is already in the single [trip_date==today] scan used for drops/no-shows.
 * Fix buckets that single scan in-memory. This validates the metric is still
 * correct after the refactor:
 *
 *   seed 2 InProgress trips (T1: 3 boarded/1 dropped, T2: 2 boarded) + 1
 *   Completed trip (T3: 4 boarded/4 dropped) + 2 no_show events, all today.
 *   G1 students_currently_onboard == 4   (InProgress only: (3-1)+(2-0))
 *   G2 students_dropped_today   == 5     (T1's 1 + T3's 4, all trips)
 *   G3 no_shows_today           == 2
 *   G4 trips_in_progress        == 2
 *
 * Attendance tripId is seeded in the REAL production format — the BARE
 * canonical trip id (matching _writeBoardingEvent + the Teacher app), which the
 * snapshot now buckets against the trip doc's bare `id`. (The earlier version
 * seeded the prefixed Firestore docId, a test-only format that hid the
 * onboard-always-0 id-form mismatch.) Self-teardown. CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_perf3 run
 */
class Transport_probe_perf3 extends CI_Controller
{
    private array $fixtures = [];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) { show_error('CLI-only.', 403); }
        $this->load->library('firebase',                   null, 'firebase');
        $this->load->library('firestore_service',          null, 'fs');
        $this->load->library('numbering_service',          null, 'numbering');
        $this->load->library('transport_service',          null, 'transport');
        $this->load->library('transport_analytics_service', null, 'analytics');
    }

    public function run(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') { fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n"); exit(1); }
        try {
            $this->fs->init($sid, $sess);
            $this->numbering->init($this->fs, $sid, $sess);
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'transport_probe_perf3');
            $this->analytics->init($this->fs, $this->transport, $sid, $sess);
        } catch (\Throwable $e) { fwrite(STDERR, "ERROR: init — " . $e->getMessage() . "\n"); exit(1); }

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        $ts    = (int) (microtime(true) * 1000);
        $today = date('Y-m-d');
        $now   = date('c');
        // Unique marker so we only assert deltas over any live tenant data.
        $tag   = "PROBE_P3_{$ts}";

        echo "==================================================\n";
        echo " PERF-3 live-ops onboard single-scan probe\n";
        echo " tenant={$sid} today={$today}\n";
        echo "==================================================\n";

        try {
            // Baseline snapshot (tenant may already have live trips today) —
            // we assert DELTAS so the probe is isolated from tenant state.
            $base = (array) $this->analytics->getLiveOperationsSnapshot();
            $b = fn(string $k) => (int) ($base[$k] ?? 0);

            // Seed trips (date==today — the field the snapshot filters on).
            $trips = [
                ['A', 'InProgress'],
                ['B', 'InProgress'],
                ['C', 'Completed'],
            ];
            foreach ($trips as [$k, $st]) {
                $tid = "{$tag}_TRP_{$k}";
                $this->fs->setEntity('tripLogs', $tid, [
                    'id'=>$tid,'schoolId'=>$sid,'session'=>$sess,'status'=>$st,
                    'date'=>$today,'trip_date'=>$today,'trip_direction'=>'Pickup','created_at'=>$now,
                ], false);
                $this->fixtures[] = ['tripLogs', $tid];
            }
            // Production writes transportAttendance.tripId as the BARE canonical
            // trip id (_writeBoardingEvent + Teacher app), NOT the prefixed
            // Firestore docId. Seed it that way so the probe validates the REAL
            // production format — the earlier version seeded the prefixed envelope
            // id, which masked the always-0 onboard bug.
            $bareTid = fn(string $k) => "{$tag}_TRP_{$k}";
            $seedEvt = function (string $k, string $et, int $n) use ($sid, $sess, $today, $now, $bareTid, $tag) {
                for ($i = 0; $i < $n; $i++) {
                    $did = "{$tag}_ATT_{$k}_{$et}_{$i}";
                    $this->fs->setEntity('transportAttendance', $did, [
                        'id'=>$did,'schoolId'=>$sid,'session'=>$sess,'tripId'=>$bareTid($k),   // BARE — matches production
                        'studentId'=>"{$tag}_STU_{$k}_{$i}",'event_type'=>$et,'trip_date'=>$today,'event_at'=>$now,
                    ], false);
                    $this->fixtures[] = ['transportAttendance', $did];
                }
            };
            $seedEvt('A', 'boarded_pickup', 3); $seedEvt('A', 'dropped_home', 1);   // onboard 2
            $seedEvt('B', 'boarded_pickup', 2);                                     // onboard 2
            $seedEvt('C', 'boarded_pickup', 4); $seedEvt('C', 'dropped_home', 4);   // completed → not onboard; 4 drops
            $seedEvt('A', 'no_show', 1); $seedEvt('B', 'no_show', 1);               // 2 no-shows

            // Re-init clears the per-instance memo so the second call re-scans.
            $this->analytics->init($this->fs, $this->transport, $sid, $sess);
            $snap = (array) $this->analytics->getLiveOperationsSnapshot();
            $d = fn(string $k) => (int) ($snap[$k] ?? 0) - $b($k);

            echo "\n[G1] students_currently_onboard delta = 4 (InProgress cohort only)\n";
            ($d('students_currently_onboard') === 4)
                ? $pass("onboard +4 = (3-1)+(2-0); Completed trip excluded")
                : $fail("onboard delta = " . $d('students_currently_onboard') . " (expected 4)");

            echo "\n[G2] students_dropped_today delta = 5 (all trips: T1 1 + T3 4)\n";
            ($d('students_dropped_today') === 5)
                ? $pass("dropped_today +5")
                : $fail("dropped_today delta = " . $d('students_dropped_today') . " (expected 5)");

            echo "\n[G3] no_shows_today delta = 2\n";
            ($d('no_shows_today') === 2)
                ? $pass("no_shows_today +2")
                : $fail("no_shows_today delta = " . $d('no_shows_today') . " (expected 2)");

            echo "\n[G4] trips_in_progress delta = 2\n";
            ($d('trips_in_progress') === 2)
                ? $pass("trips_in_progress +2")
                : $fail("trips_in_progress delta = " . $d('trips_in_progress') . " (expected 2)");
        } catch (\Throwable $e) {
            $fail("unexpected exception: " . $e->getMessage());
        } finally {
            echo "\n[TEARDOWN]\n";
            foreach ($this->fixtures as [$col, $id]) { try { $this->fs->removeEntity($col, $id); } catch (\Throwable $e) {} }
            echo "  cleaned " . count($this->fixtures) . " docs\n";
        }

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }
}
