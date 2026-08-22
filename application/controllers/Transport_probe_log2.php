<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_log2 — suspended route must not be dispatchable (LOG-2).
 *
 * saveRouteSuspension sets routes.active_suspension_ref but leaves
 * status='Active'. The assignment + route-change paths already gate on
 * active_suspension_ref, but createTrip (dispatch) only checked status ===
 * 'Active' → a suspended route was still dispatchable and students got boarded
 * on an operationally-suspended route. Fix adds the suspension gate to
 * createTrip (inside the route-provided block, so route-less Event trips are
 * unaffected).
 *
 *   G1 createTrip on a route with active_suspension_ref throws "active
 *      suspension" (dispatch blocked)
 *   G2 the guard is suspension-specific: with active_suspension_ref='' the
 *      route gate passes (createTrip advances past it and fails LATER, not on
 *      a suspension error) — proves the guard is not a blanket block
 *
 * Self-teardown. CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_log2 run
 */
class Transport_probe_log2 extends CI_Controller
{
    private array $fixtures = [];

    // ── UNIT 2B campus-geofence fixture (same mechanism as Transport_probe_f7,
    //    _f8, _f13_2 and _f14_ov: seed-only-when-absent, snapshot, restore
    //    verbatim). Identical semantics — deliberately not a second variant.
    private $priorPolicy = null;
    private bool $policySeeded = false;
    private string $fixSchoolId = '';
    private const FIX_LAT = 12.9716;
    private const FIX_LNG = 77.5946;

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) { show_error('CLI-only.', 403); }
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
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'transport_probe_log2');
        } catch (\Throwable $e) { fwrite(STDERR, "ERROR: init — " . $e->getMessage() . "\n"); exit(1); }

        // UNIT 2B — this suite asserts that createTrip() REFUSES a Pickup trip
        // on a suspended route. Without a campus fence the refusal could come
        // from the geofence guard instead, i.e. the right verdict for the wrong
        // reason. Seeding the fence keeps the assertion honest. Restored below.
        $this->fixSchoolId = $sid;
        $this->_ensureCampusFence();

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        $ts  = (int) (microtime(true) * 1000);
        $vid = "PROBE_LOG2_VEH_{$ts}";
        $rid = "PROBE_LOG2_RT_{$ts}";
        $now = date('c');
        $trip = ['vehicle_id' => $vid, 'route_id' => $rid, 'trip_date' => date('Y-m-d'), 'trip_direction' => 'Pickup', 'trip_kind' => 'Regular'];

        echo "==================================================\n";
        echo " LOG-2 suspended-route dispatch guard probe\n";
        echo " tenant={$sid} session={$sess}\n";
        echo "==================================================\n";

        try {
            $this->fs->setEntity('vehicles', $vid, ['id'=>$vid,'schoolId'=>$sid,'number'=>'LOG2','status'=>'Active','lifecycle_status'=>'Active','capacity'=>40,'created_at'=>$now], false);
            $this->fixtures[] = ['vehicles', $vid];

            echo "\n[G1] dispatch on a SUSPENDED route is blocked\n";
            $this->fs->setEntity('routes', $rid, ['id'=>$rid,'schoolId'=>$sid,'name'=>'LOG2 Route','status'=>'Active','vehicle_id'=>$vid,'active_suspension_ref'=>"SUS_PROBE_{$ts}",'created_at'=>$now], false);
            $this->fixtures[] = ['routes', $rid];
            try {
                $this->transport->createTrip($trip);
                $fail("createTrip on a suspended route SUCCEEDED (dispatch path open)");
            } catch (\Throwable $e) {
                (stripos($e->getMessage(), 'active suspension') !== false)
                    ? $pass("dispatch blocked with suspension error: " . $e->getMessage())
                    : $fail("blocked but not on suspension (got: " . $e->getMessage() . ")");
            }

            echo "\n[G2] guard is suspension-specific (not a blanket block)\n";
            // clear the suspension ref; the route gate now passes and createTrip
            // must fail LATER (driver/stops), never on a suspension error.
            $this->fs->setEntity('routes', $rid, ['active_suspension_ref' => ''], true);
            try {
                $this->transport->createTrip($trip);
                // If it somehow succeeds, that's fine too — the suspension gate let it through.
                $pass("route gate passed with no suspension (trip created)");
            } catch (\Throwable $e) {
                (stripos($e->getMessage(), 'active suspension') === false)
                    ? $pass("route gate passed; failed later on a non-suspension reason: " . $e->getMessage())
                    : $fail("still blocked on suspension after clearing the ref: " . $e->getMessage());
            }
        } catch (\Throwable $e) {
            $fail("unexpected exception: " . $e->getMessage());
        } finally {
            echo "\n[TEARDOWN]\n";
            $this->_restoreCampusFence();
            foreach ($this->fixtures as [$col, $id]) { try { $this->fs->removeEntity($col, $id); } catch (\Throwable $e) {} }
            echo "  cleaned\n";
        }

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }

    /**
     * UNIT 2B — guarantee a valid campus fence BEFORE any Pickup trip is
     * created. Same mechanism as Transport_probe_f7/_f8/_f13_2/_f14_ov: seeds
     * ONLY when no fence exists, snapshots the whole attendancePolicy first,
     * and _restoreCampusFence() puts the original back verbatim. A tenant that
     * already has a fence is preserved, never overwritten.
     */
    private function _ensureCampusFence(): void
    {
        if ($this->policySeeded) { return; }
        try {
            $school = $this->fs->get('schools', $this->fixSchoolId);
            $school = is_array($school) ? ($school['data'] ?? $school) : [];
            $pol    = (array) ($school['attendancePolicy'] ?? []);
            if ((array) (($pol['gps'] ?? [])['geofences'] ?? []) !== []) {
                echo "  fixture: campus fence already present — preserved, not overwritten\n";
                return;
            }
            $this->priorPolicy = $pol;
            $gps = (array) ($pol['gps'] ?? []);
            $gps['geofences'] = [[
                'id' => 'PROBE_LOG2_CAMPUS', 'name' => 'Probe Campus',
                'centerLat' => self::FIX_LAT, 'centerLng' => self::FIX_LNG, 'radius' => 300,
            ]];
            if (!isset($gps['maxAccuracyMeters'])) { $gps['maxAccuracyMeters'] = 100; }
            $pol['gps'] = $gps;
            $this->fs->update('schools', $this->fixSchoolId, ['attendancePolicy' => $pol]);
            $this->policySeeded = true;
            echo "  fixture: temporary campus fence seeded (restored at teardown)\n";
        } catch (\Throwable $e) {
            echo '  fixture WARN (_ensureCampusFence): ' . $e->getMessage() . "\n";
        }
    }

    private function _restoreCampusFence(): void
    {
        if (!$this->policySeeded) { return; }
        $this->policySeeded = false;
        try {
            $this->fs->update('schools', $this->fixSchoolId,
                ['attendancePolicy' => $this->priorPolicy ?? []]);
            echo "  restored attendancePolicy\n";
        } catch (\Throwable $e) {
            echo '  RESTORE FAILED (attendancePolicy) — ' . $e->getMessage() . "\n";
        }
    }
}
