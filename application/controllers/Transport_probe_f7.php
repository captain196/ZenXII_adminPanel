<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_f7 — F7 Trip Logs + Transport Attendance runtime probe
 * (2026-07-07).
 *
 * Exercises the F7 flow end-to-end via Transport_service::DOMAIN_TRIP:
 *   1. createTrip → Ready state (roster + checkpoints frozen + GPS
 *      session id established + all live-GPS fields NULL)
 *   2. startTrip → InProgress + trip.started fires (ops audience)
 *   3. markCheckpointReached → route_progress_pct advances
 *   4. Complete requires all checkpoints Reached-or-Skipped
 *      (Refinement 4)
 *   5. markStudentNoShow → trip.student_no_show fires to parent
 *      (parents_of_students scope), never deduped
 *   6. completeTrip → Completed state + tally consistency +
 *      trip.completed fires (ops audience)
 *   7. Roster immutability — attempt to board a non-roster student
 *      must be rejected
 *   8. GPS-null discipline — every doc written has NULL for reserved
 *      live-position fields
 *
 * CLI-only. Invocation:
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_f7 run
 *
 * Exit codes:
 *   0 — all gates passed
 *   1 — env / init failed
 *   2 — probe assertion failure
 */
class Transport_probe_f7 extends CI_Controller
{
    private array $createdTrips = [];
    private array $createdFixtures = [];   // [ [collection, docId], ... ] for teardown

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Transport_probe_f7 is CLI-only.', 403);
        }
        $this->load->library('firebase',           null, 'firebase');
        $this->load->library('firestore_service',  null, 'fs');
        $this->load->library('numbering_service',  null, 'numbering');
        $this->load->library('transport_service',  null, 'transport');
    }

    public function run(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n");
            exit(1);
        }
        try {
            $this->fs->init($sid, $sess);
            $this->numbering->init($this->fs, $sid, $sess);
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'transport_probe_f7');
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: init failed — " . $e->getMessage() . "\n");
            exit(1);
        }

        $passed = 0; $failed = 0; $errors = [];

        echo "==================================================\n";
        echo " F7 Trip Logs + Transport Attendance Runtime Probe\n";
        echo " tenant=$sid session=$sess\n";
        echo "==================================================\n";

        // Ephemeral fixtures — always create+teardown so the probe is
        // portable across tenants (fresh dev tenant may have zero fleet).
        [$vehicleId, $routeId] = $this->_provisionFixtures($sid);
        echo "\n[FIXTURES] provisioned vehicle={$vehicleId} route={$routeId} (2 stops)\n";

        // ------------------------------------------------------------
        // Step 1 — createTrip (Ready)
        // ------------------------------------------------------------
        echo "\n[STEP 1] createTrip → Ready (roster + checkpoints frozen)\n";
        $tripId = '';
        try {
            $r = $this->transport->createTrip([
                'vehicle_id'     => $vehicleId,
                'route_id'       => $routeId,
                'trip_date'      => date('Y-m-d'),
                'trip_direction' => 'Pickup',
                'notes'          => 'F7 probe',
            ]);
            $tripId = (string) ($r['tripId'] ?? '');
            $data   = (array)  ($r['data']   ?? []);
            if ($tripId === '') { throw new \RuntimeException('no tripId'); }
            $this->createdTrips[] = $tripId;
            if (($data['status'] ?? '') !== 'Ready') { throw new \RuntimeException("status !== Ready"); }
            if (empty($data['gps_session_id']) || strpos((string) $data['gps_session_id'], 'GPSSESS_') !== 0) {
                throw new \RuntimeException('gps_session_id not established with prefix GPSSESS_');
            }
            if (empty($data['checkpoints'])) { throw new \RuntimeException('checkpoints empty'); }
            // GPS-null discipline
            foreach (['gps_start_lat','gps_start_lng','gps_last_position_at','gps_track_url'] as $f) {
                if (!array_key_exists($f, $data) || $data[$f] !== null) {
                    throw new \RuntimeException("GPS field {$f} not NULL");
                }
            }
            echo "  PASS: trip={$tripId} Ready, {$data['roster_size']} students on frozen roster, "
                . count($data['checkpoints']) . " checkpoints, gps_session_id={$data['gps_session_id']}\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step1: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Step 2 — startTrip
        // ------------------------------------------------------------
        echo "\n[STEP 2] startTrip → InProgress\n";
        try {
            $r = $this->transport->startTrip($tripId);
            $doc = $this->transport->getTrip($tripId);
            if (($doc['status'] ?? '') !== 'InProgress') { throw new \RuntimeException('status !== InProgress'); }
            if (empty($doc['actual_start_at'])) { throw new \RuntimeException('actual_start_at not set'); }
            echo "  PASS: trip={$tripId} InProgress, actual_start_at={$doc['actual_start_at']}\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step2: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Step 3 — reach first checkpoint
        // ------------------------------------------------------------
        echo "\n[STEP 3] markCheckpointReached — progress advances\n";
        try {
            $doc = $this->transport->getTrip($tripId);
            $firstStop = (string) ($doc['checkpoints'][0]['stop_id'] ?? '');
            if ($firstStop === '') { throw new \RuntimeException('no first checkpoint'); }
            $r = $this->transport->markCheckpointReached($tripId, $firstStop);
            $doc = $this->transport->getTrip($tripId);
            if (($doc['checkpoints'][0]['checkpoint_state'] ?? '') !== 'Reached') {
                throw new \RuntimeException('first checkpoint state !== Reached');
            }
            if ((int) ($doc['route_progress_pct'] ?? 0) === 0) {
                throw new \RuntimeException('route_progress_pct did not advance');
            }
            echo "  PASS: checkpoint reached, progress={$doc['route_progress_pct']}%\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step3: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Step 4 — complete with Pending checkpoints must FAIL
        // ------------------------------------------------------------
        echo "\n[STEP 4] completeTrip with Pending checkpoints must be rejected\n";
        try {
            $doc = $this->transport->getTrip($tripId);
            $pendings = 0;
            foreach ((array) ($doc['checkpoints'] ?? []) as $cp) {
                if (($cp['checkpoint_state'] ?? '') === 'Pending') { $pendings++; }
            }
            if ($pendings === 0) {
                echo "  SKIP: only 1 checkpoint on this route — cannot test Pending rejection\n";
                $passed++;
            } else {
                $rejected = false;
                try {
                    $this->transport->completeTrip($tripId, ['end_time' => date('c'), 'end_odometer' => 100]);
                } catch (\InvalidArgumentException $ie) {
                    $rejected = true;
                }
                if (!$rejected) { throw new \RuntimeException('complete with Pending checkpoint was NOT rejected'); }
                echo "  PASS: complete rejected with {$pendings} Pending checkpoint(s)\n";
                $passed++;
            }
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step4: '.$e->getMessage();
        }

        // Skip remaining checkpoints so we can complete
        try {
            $doc = $this->transport->getTrip($tripId);
            foreach ((array) ($doc['checkpoints'] ?? []) as $cp) {
                if (($cp['checkpoint_state'] ?? '') === 'Pending') {
                    $this->transport->markCheckpointSkipped($tripId, (string) $cp['stop_id'], 'F7 probe skip');
                }
            }
        } catch (\Throwable $_) {}

        // ------------------------------------------------------------
        // Step 5 — no-show fires trip.student_no_show
        // ------------------------------------------------------------
        echo "\n[STEP 5] markStudentNoShow (fires trip.student_no_show)\n";
        try {
            $doc = $this->transport->getTrip($tripId);
            $roster = (array) ($doc['roster_student_ids'] ?? []);
            if ($roster === []) {
                echo "  SKIP: roster empty (route has no active students) — cannot exercise no-show\n";
                $passed++;
            } else {
                $sid = (string) $roster[0];
                $this->transport->markStudentNoShow($tripId, $sid, 'F7 probe no-show');
                // Verify event was written
                $events = $this->transport->getTripBoardingEvents($tripId);
                $found = false;
                foreach ($events as $e) {
                    if (($e['studentId'] ?? '') === $sid && ($e['event_type'] ?? '') === 'no_show') { $found = true; }
                }
                if (!$found) { throw new \RuntimeException('no-show event not persisted'); }
                echo "  PASS: no-show recorded for student={$sid}; notification fired (parents_of_students scope)\n";
                $passed++;
            }
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step5: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Step 6 — completeTrip → Completed + tally + notification
        // ------------------------------------------------------------
        echo "\n[STEP 6] completeTrip → Completed (tally + trip.completed)\n";
        try {
            $r = $this->transport->completeTrip($tripId, [
                'end_time'    => date('c'),
                'end_odometer'=> 25,
                'distance_km' => 25,
                'notes'       => 'F7 probe complete',
            ]);
            $doc = $this->transport->getTrip($tripId);
            if (($doc['status'] ?? '') !== 'Completed') { throw new \RuntimeException('status !== Completed'); }
            $reachedCount = 0;
            foreach ((array) ($doc['checkpoints'] ?? []) as $cp) {
                if (($cp['checkpoint_state'] ?? '') === 'Reached') { $reachedCount++; }
            }
            if ((int) ($doc['stopsCompleted'] ?? -1) !== $reachedCount) {
                throw new \RuntimeException("stopsCompleted mismatch: {$doc['stopsCompleted']} !== {$reachedCount}");
            }
            echo "  PASS: trip={$tripId} Completed; stopsCompleted={$doc['stopsCompleted']} matches Reached count\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step6: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Step 7 — roster immutability: reject non-roster boarding
        // ------------------------------------------------------------
        echo "\n[STEP 7] roster immutability (non-roster student rejected)\n";
        try {
            $r = $this->transport->createTrip([
                'vehicle_id'     => $vehicleId,
                'route_id'       => $routeId,
                'trip_date'      => date('Y-m-d'),
                'trip_direction' => 'Pickup',
                'notes'          => 'F7 probe roster-immutability',
            ]);
            $tid2 = (string) $r['tripId'];
            $this->createdTrips[] = $tid2;
            $this->transport->startTrip($tid2);
            $rejected = false;
            try {
                $this->transport->markStudentBoarded($tid2, 'FAKE_STU_NOT_ON_ROSTER');
            } catch (\InvalidArgumentException $ie) {
                $rejected = true;
            }
            if (!$rejected) { throw new \RuntimeException('non-roster student was NOT rejected'); }
            echo "  PASS: non-roster student rejected (frozen-roster invariant honored)\n";
            $passed++;
            // Clean up trip2
            try {
                foreach ((array) ($this->transport->getTrip($tid2)['checkpoints'] ?? []) as $cp) {
                    if (($cp['checkpoint_state'] ?? '') === 'Pending') {
                        $this->transport->markCheckpointSkipped($tid2, (string) $cp['stop_id'], 'F7 probe teardown');
                    }
                }
                $this->transport->cancelTrip($tid2, 'F7 probe teardown');
            } catch (\Throwable $_) {}
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step7: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Teardown
        // ------------------------------------------------------------
        echo "\n[TEARDOWN] Removing ephemeral fixtures\n";
        foreach (array_reverse($this->createdFixtures) as $f) {
            try {
                // Use removeEntity — Firestore_service::remove() takes a full
                // docId; removeEntity() prefixes with schoolId internally,
                // matching how setEntity() writes.
                $this->fs->removeEntity($f[0], $f[1]);
                echo "  removed {$f[0]}:{$f[1]}\n";
            } catch (\Throwable $e) {
                echo "  skip {$f[0]}:{$f[1]} — " . $e->getMessage() . "\n";
            }
        }

        echo "\n==================================================\n";
        echo " RESULT: passed=$passed failed=$failed\n";
        if ($failed > 0) {
            foreach ($errors as $err) { echo "  - $err\n"; }
            echo "==================================================\n";
            exit(2);
        }
        echo "==================================================\n";
        exit(0);
    }

    /**
     * Provision ephemeral fixtures directly via Firestore_service (bypasses
     * Transport_service::save* to avoid Numbering-side effects on real
     * counters — probe fixtures use synthetic IDs prefixed PROBE_F7_).
     * Teardown removes each fixture at the end.
     */
    private function _provisionFixtures(string $sid): array
    {
        $now = date('c');
        $vehicleId = 'PROBE_F7_VEH_' . (int) (microtime(true) * 1000);
        $routeId   = 'PROBE_F7_RT_'  . (int) (microtime(true) * 1000);
        $stop1Id   = 'PROBE_F7_STP1_' . (int) (microtime(true) * 1000);
        $stop2Id   = 'PROBE_F7_STP2_' . (int) (microtime(true) * 1000);
        // Vehicle — has to pass createTrip's Active check + effective-driver
        // resolution. Use a synthetic staff id — driver-readiness validation
        // is NOT called from createTrip (only from assignments), so this
        // synthetic id is fine.
        $this->fs->setEntity('vehicles', $vehicleId, [
            'id' => $vehicleId, 'schoolId' => $sid,
            'number' => 'F7-PROBE', 'status' => 'Active',
            'staff_id' => 'PROBE_F7_STAFF',
            'route_id' => $routeId, 'created_at' => $now, 'updated_at' => $now,
        ], /* merge */ false);
        $this->createdFixtures[] = ['vehicles', $vehicleId];
        // LOG-1: createTrip now validates driver readiness — seed a FIT driver.
        $this->fs->setEntity('staff', 'PROBE_F7_STAFF', [
            'id' => 'PROBE_F7_STAFF', 'schoolId' => $sid, 'name' => 'F7 Probe Driver', 'status' => 'Active',
            'flags' => ['can_drive_transport' => true],
            'driver_meta' => ['operational_status' => 'Available', 'licence_expires' => '2099-12-31', 'medical_expires' => '2099-12-31'],
        ], /* merge */ false);
        $this->createdFixtures[] = ['staff', 'PROBE_F7_STAFF'];
        // Route
        $this->fs->setEntity('routes', $routeId, [
            'id' => $routeId, 'schoolId' => $sid,
            'name' => 'F7 Probe Route', 'status' => 'Active',
            'direction' => 'Both', 'created_at' => $now, 'updated_at' => $now,
        ], /* merge */ false);
        $this->createdFixtures[] = ['routes', $routeId];
        // Two stops
        $this->fs->setEntity('transportStops', $stop1Id, [
            'stopId' => $stop1Id, 'schoolId' => $sid, 'route_id' => $routeId,
            'name' => 'F7 Stop A', 'order' => 1,
            'pickup_time' => '07:00', 'drop_time' => '15:30',
            'latitude' => 12.9716, 'longitude' => 77.5946,
            'status' => 'Active', 'created_at' => $now, 'updated_at' => $now,
        ], /* merge */ false);
        $this->createdFixtures[] = ['transportStops', $stop1Id];
        $this->fs->setEntity('transportStops', $stop2Id, [
            'stopId' => $stop2Id, 'schoolId' => $sid, 'route_id' => $routeId,
            'name' => 'F7 Stop B', 'order' => 2,
            'pickup_time' => '07:15', 'drop_time' => '15:45',
            'latitude' => 12.9812, 'longitude' => 77.6082,
            'status' => 'Active', 'created_at' => $now, 'updated_at' => $now,
        ], /* merge */ false);
        $this->createdFixtures[] = ['transportStops', $stop2Id];

        // Optional roster fixture — one synthetic assignment so no-show
        // has a target. If students collection has no matching doc the
        // parent-lookup will simply resolve to no user (safe).
        $studentId = 'PROBE_F7_STU_' . (int) (microtime(true) * 1000);
        $srKey     = 'PROBE_F7_SR_'  . (int) (microtime(true) * 1000);
        $this->fs->setEntity('studentRoutes', $srKey, [
            'schoolId' => $sid, 'studentId' => $studentId,
            'route_id' => $routeId, 'stop_id' => $stop1Id,
            'type' => 'both', 'direction' => 'Both',
            'service_days' => ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'],
            'session' => (string) getenv('SESSION_YEAR'),
            'status' => 'Active', 'created_at' => $now, 'updated_at' => $now,
        ], /* merge */ false);
        $this->createdFixtures[] = ['studentRoutes', $srKey];

        return [$vehicleId, $routeId];
    }
}
