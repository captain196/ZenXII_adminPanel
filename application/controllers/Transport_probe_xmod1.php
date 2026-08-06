<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_xmod1 — vehicle currentRouteId denormalisation (XMOD-1).
 *
 * No PHP writer ever set vehicles.currentRouteId, yet the Teacher app reads it
 * for the DriverHome route badge and the driver SOS route context — so SOS was
 * raised with an empty route. Fix: startTrip denormalises the in-progress
 * route onto the vehicle; completeTrip/cancelTrip clear it (guarded so a
 * concurrent trip's route is never wiped).
 *
 *   G1 startTrip sets vehicles.currentRouteId to the trip's route (real
 *      lifecycle; trip seeded with driver_staff_id='' to skip readiness)
 *   G2 completeTrip clears it back to ''
 *   G3 the clear is GUARDED: it does not wipe a different trip's route
 *
 * Self-teardown. CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_xmod1 run
 */
class Transport_probe_xmod1 extends CI_Controller
{
    private array $fixtures = [];

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
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'transport_probe_xmod1');
        } catch (\Throwable $e) { fwrite(STDERR, "ERROR: init — " . $e->getMessage() . "\n"); exit(1); }

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        $ts  = (int) (microtime(true) * 1000);
        $vid = "PROBE_XM_VEH_{$ts}";
        $rid = "PROBE_XM_RT_{$ts}";
        $tid = "PROBE_XM_TRP_{$ts}";
        $now = date('c');
        $veh = fn() => (array) ($this->fs->getEntity('vehicles', $vid) ?: []);

        echo "==================================================\n";
        echo " XMOD-1 vehicle currentRouteId denorm probe\n";
        echo " tenant={$sid} session={$sess}\n";
        echo "==================================================\n";

        try {
            $this->fs->setEntity('vehicles', $vid, ['id'=>$vid,'schoolId'=>$sid,'number'=>'XM','status'=>'Active','lifecycle_status'=>'Active','capacity'=>40,'currentRouteId'=>'','created_at'=>$now], false);
            $this->fixtures[] = ['vehicles', $vid];
            // Ready trip with NO driver (driver_staff_id='') → startTrip skips
            // readiness; empty checkpoints → completeTrip's Pending-gate is vacuous.
            $this->fs->setEntity('tripLogs', $tid, [
                'id'=>$tid,'schoolId'=>$sid,'session'=>$sess,'status'=>'Ready',
                'vehicleId'=>$vid,'route_id'=>$rid,'driver_staff_id'=>'',
                'trip_date'=>date('Y-m-d'),'trip_direction'=>'Pickup','trip_kind'=>'Regular',
                'checkpoints'=>[],'roster_size'=>0,'created_at'=>$now,
            ], false);
            $this->fixtures[] = ['tripLogs', $tid];

            echo "\n[G1] startTrip sets vehicle.currentRouteId to the trip route\n";
            $this->transport->startTrip($tid);
            ((string) ($veh()['currentRouteId'] ?? '') === $rid)
                ? $pass("vehicle.currentRouteId = {$rid} after start (was empty pre-fix)")
                : $fail("currentRouteId=" . ($veh()['currentRouteId'] ?? '(null)') . " (expected {$rid})");

            echo "\n[G2] completeTrip clears vehicle.currentRouteId\n";
            $this->transport->completeTrip($tid, ['end_odometer' => 0]);
            ((string) ($veh()['currentRouteId'] ?? 'X') === '')
                ? $pass("vehicle.currentRouteId cleared to '' after completion")
                : $fail("currentRouteId=" . ($veh()['currentRouteId'] ?? '(null)') . " (expected empty)");

            echo "\n[G3] clear is GUARDED — a different trip's route is not wiped\n";
            $this->fs->setEntity('vehicles', $vid, ['currentRouteId' => 'RT_OTHER_ACTIVE'], true);
            $rm = new \ReflectionMethod($this->transport, '_clearVehicleCurrentRoute');
            $rm->setAccessible(true);
            $rm->invoke($this->transport, $vid, $rid);   // clear for OUR route; vehicle holds a different one
            ((string) ($veh()['currentRouteId'] ?? '') === 'RT_OTHER_ACTIVE')
                ? $pass("guard held: RT_OTHER_ACTIVE preserved (clear only fires for the matching route)")
                : $fail("guard failed: currentRouteId=" . ($veh()['currentRouteId'] ?? '(null)'));
        } catch (\Throwable $e) {
            $fail("unexpected exception: " . $e->getMessage());
        } finally {
            echo "\n[TEARDOWN]\n";
            foreach ($this->fixtures as [$col, $id]) { try { $this->fs->removeEntity($col, $id); } catch (\Throwable $e) {} }
            echo "  cleaned\n";
        }

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }
}
