<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_log1 — LOG-1 driver-readiness-at-dispatch probe.
 *
 * Proves an unfit driver can never be dispatched onto a trip:
 *
 *   G1 FIT driver            → createTrip SUCCEEDS
 *   G2 expired licence       → createTrip REJECTED
 *   G3 operational On_Leave   → createTrip REJECTED
 *   G4 HR Inactive           → createTrip REJECTED
 *   G5 can_drive_transport off→ createTrip REJECTED
 *   G6 fit at create, On_Leave by start → startTrip REJECTED (day-of re-check)
 *   G7 restored fit          → startTrip SUCCEEDS (positive control)
 *
 * Self-teardown. CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_log1 run
 */
class Transport_probe_log1 extends CI_Controller
{
    private array $fixtures = [];
    private array $trips = [];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Transport_probe_log1 is CLI-only.', 403);
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
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'transport_probe_log1');
        } catch (\Throwable $e) { fwrite(STDERR, "ERROR: init failed — " . $e->getMessage() . "\n"); exit(1); }

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        $ts  = (int) (microtime(true) * 1000);
        $vid = "PROBE_LOG1_VEH_{$ts}";
        $rid = "PROBE_LOG1_RT_{$ts}";
        $stp = "PROBE_LOG1_STP_{$ts}";
        $drv = "PROBE_LOG1_DRV_{$ts}";
        $stu = "PROBE_LOG1_STU_{$ts}";
        $srk = "PROBE_LOG1_SR_{$ts}";
        $now = date('c');

        // Set the driver to a known fitness state (full overwrite each time).
        $setDriver = function(array $o) use ($drv, $sid) {
            $doc = [
                'id' => $drv, 'schoolId' => $sid, 'name' => 'LOG1 Driver',
                'status' => $o['status'] ?? 'Active',
                'flags' => ['can_drive_transport' => $o['can_drive'] ?? true],
                'driver_meta' => [
                    'operational_status' => $o['opStatus'] ?? 'Available',
                    'licence_expires'    => $o['licence']  ?? '2099-12-31',
                    'medical_expires'    => '2099-12-31',
                ],
            ];
            $this->fs->setEntity('staff', $drv, $doc, false);
        };
        $create = function() use ($vid, $rid) {
            return $this->transport->createTrip(['vehicle_id'=>$vid,'route_id'=>$rid,'trip_date'=>date('Y-m-d'),'trip_direction'=>'Pickup','notes'=>'LOG1']);
        };
        $expectReject = function(string $label, callable $fn) use ($pass, $fail) {
            try { $fn(); $fail("{$label}: NOT rejected (unfit driver was dispatched!)"); }
            catch (\Throwable $e) { $pass("{$label}: rejected — " . substr($e->getMessage(), 0, 70)); }
        };

        echo "==================================================\n";
        echo " LOG-1 Driver-Readiness-at-Dispatch Probe\n";
        echo " tenant={$sid}\n";
        echo "==================================================\n";

        try {
            // Fixtures
            $this->fs->setEntity('vehicles', $vid, ['id'=>$vid,'schoolId'=>$sid,'number'=>'LOG1','status'=>'Active','staff_id'=>$drv,'route_id'=>$rid,'created_at'=>$now], false);
            $this->fixtures[] = ['vehicles', $vid];
            $this->fs->setEntity('routes', $rid, ['id'=>$rid,'schoolId'=>$sid,'name'=>'LOG1 Route','status'=>'Active','direction'=>'Both','created_at'=>$now], false);
            $this->fixtures[] = ['routes', $rid];
            $this->fs->setEntity('transportStops', $stp, ['stopId'=>$stp,'schoolId'=>$sid,'route_id'=>$rid,'name'=>'LOG1 Stop','order'=>1,'pickup_time'=>'07:00','status'=>'Active','created_at'=>$now], false);
            $this->fixtures[] = ['transportStops', $stp];
            $this->fs->setEntity('studentRoutes', $srk, ['schoolId'=>$sid,'studentId'=>$stu,'route_id'=>$rid,'stop_id'=>$stp,'type'=>'both','direction'=>'Both','service_days'=>['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'],'session'=>$sess,'status'=>'Active','created_at'=>$now], false);
            $this->fixtures[] = ['studentRoutes', $srk];

            echo "\n[G1] FIT driver → createTrip SUCCEEDS\n";
            $setDriver([]);
            $r = $create(); $tid = (string)($r['tripId'] ?? '');
            if ($tid !== '') { $this->trips[] = $tid; }
            ($tid !== '' && (string)($r['data']['status'] ?? '') === 'Ready')
                ? $pass("fit driver dispatched; trip {$tid} Ready")
                : $fail("fit driver could not create trip: " . json_encode($r));

            echo "\n[G2] expired licence → createTrip REJECTED\n";
            $setDriver(['licence'=>'2020-01-01']); $expectReject('G2 expired licence', $create);

            echo "\n[G3] operational_status On_Leave → createTrip REJECTED\n";
            $setDriver(['opStatus'=>'On_Leave']); $expectReject('G3 On_Leave', $create);

            echo "\n[G4] HR Inactive → createTrip REJECTED\n";
            $setDriver(['status'=>'Inactive']); $expectReject('G4 HR Inactive', $create);

            echo "\n[G5] can_drive_transport off → createTrip REJECTED\n";
            $setDriver(['can_drive'=>false]); $expectReject('G5 no can_drive flag', $create);

            echo "\n[G6] fit at create, On_Leave by start → startTrip REJECTED (day-of re-check)\n";
            $setDriver(['opStatus'=>'On_Leave']);
            $expectReject('G6 startTrip day-of', function() use ($tid) { $this->transport->startTrip($tid); });

            echo "\n[G7] restored fit → startTrip SUCCEEDS (positive control)\n";
            $setDriver([]);
            try {
                $s = $this->transport->startTrip($tid);
                ((string)($s['status'] ?? '') === 'InProgress')
                    ? $pass("fit driver → startTrip InProgress")
                    : $fail("startTrip did not reach InProgress: " . json_encode($s));
            } catch (\Throwable $e) { $fail("fit startTrip threw: " . $e->getMessage()); }
        } catch (\Throwable $e) {
            $fail("setup exception: " . $e->getMessage());
        } finally {
            echo "\n[TEARDOWN]\n";
            foreach ($this->trips as $t) { try { $this->fs->removeEntity('tripLogs', $t); } catch (\Throwable $e) {} }
            foreach (array_merge($this->fixtures, [['staff', $drv]]) as [$col, $id]) {
                try { $this->fs->removeEntity($col, $id); } catch (\Throwable $e) {}
            }
            echo "  fixtures removed\n";
        }

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }
}
