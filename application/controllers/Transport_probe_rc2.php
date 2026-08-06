<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_rc2 — RC-2 fallback-hardening runtime probe.
 *
 * Proves that listTrips returns the TRUE date-DESC top-N even when the
 * [schoolId, date] composite index is not (yet) deployed — i.e. the hardened
 * query() fallback (drop orderBy AND limit → fetch all → client-sort → slice)
 * yields the correct order, not an arbitrary limited set.
 *
 *   G1 seed 3 tripLogs with out-of-order dates
 *   G2 listTrips([]) returns them in date DESC order (correct top-N)
 *   G3 listTrips(['route_id'=>R]) returns only that route, date DESC
 *
 * Self-teardown. CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_rc2 run
 */
class Transport_probe_rc2 extends CI_Controller
{
    private const COL = 'tripLogs';
    private array $ids = [];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Transport_probe_rc2 is CLI-only.', 403);
        }
        $this->load->library('firebase',          null, 'firebase');
        $this->load->library('firestore_service', null, 'fs');
        $this->load->library('numbering_service', null, 'numbering');
        $this->load->library('transport_service', null, 'transport');
    }

    public function run(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') { fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n"); exit(1); }
        try {
            $this->fs->init($sid, $sess);
            $this->numbering->init($this->fs, $sid, $sess);
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'transport_probe_rc2');
        } catch (\Throwable $e) { fwrite(STDERR, "ERROR: init failed — " . $e->getMessage() . "\n"); exit(1); }

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        $ts = (int) (microtime(true) * 1000);
        $rA = "PROBE_RC2_RTA_{$ts}";
        // (id, date, route) — deliberately OUT of date order
        $seed = [
            ["PROBE_RC2_T1_{$ts}", '2026-01-05', $rA],
            ["PROBE_RC2_T2_{$ts}", '2026-06-20', $rA],
            ["PROBE_RC2_T3_{$ts}", '2026-03-12', 'PROBE_RC2_RTB'],
        ];

        echo "==================================================\n";
        echo " RC-2 Fallback-Hardening Probe (listTrips order)\n";
        echo " tenant={$sid}\n";
        echo "==================================================\n";

        try {
            echo "\n[G1] seed 3 tripLogs with out-of-order dates\n";
            foreach ($seed as [$id, $date, $route]) {
                $this->fs->setEntity(self::COL, $id, [
                    'id' => $id, 'date' => $date, 'trip_date' => $date, 'route_id' => $route,
                    'status' => 'Ready', 'trip_direction' => 'Pickup', 'created_at' => date('c'),
                ], false);
                $this->ids[] = $id;
            }
            $pass("seeded " . count($seed) . " trips");

            echo "\n[G2] listTrips([]) → date DESC (correct top-N via hardened fallback)\n";
            $rows = $this->transport->listTrips([]);
            $mine = array_values(array_filter($rows, fn($r) => str_starts_with((string) ($r['id'] ?? $r['tripId'] ?? ''), "PROBE_RC2_T")));
            $dates = array_map(fn($r) => (string) ($r['date'] ?? ''), $mine);
            $sorted = $dates; rsort($sorted);
            (count($mine) === 3 && $dates === $sorted && $dates[0] === '2026-06-20')
                ? $pass("3 trips in date DESC order: " . implode(' > ', $dates))
                : $fail("order wrong or count!=3: " . json_encode($dates));

            echo "\n[G3] listTrips(route filter) → only that route, date DESC\n";
            $rowsR = $this->transport->listTrips(['route_id' => $rA]);
            $mineR = array_values(array_filter($rowsR, fn($r) => str_starts_with((string) ($r['id'] ?? $r['tripId'] ?? ''), "PROBE_RC2_T")));
            $datesR = array_map(fn($r) => (string) ($r['date'] ?? ''), $mineR);
            $okRoute = count($mineR) === 2 && $datesR === ['2026-06-20', '2026-01-05'];
            $okRoute ? $pass("route-filtered trips date DESC: " . implode(' > ', $datesR))
                     : $fail("route filter wrong: " . json_encode($datesR));
        } catch (\Throwable $e) {
            $fail("exception: " . $e->getMessage());
        } finally {
            echo "\n[TEARDOWN]\n";
            foreach ($this->ids as $id) {
                try { $this->fs->removeEntity(self::COL, $id); echo "  removed {$id}\n"; } catch (\Throwable $e) {}
            }
        }

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }
}
