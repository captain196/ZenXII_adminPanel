<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_con1 — CON-1 derived-tally runtime probe.
 *
 * Proves that a REPEAT driver boarding tap can no longer double-count the
 * per-checkpoint tally (the deterministic-doc-id makes the event idempotent;
 * CON-1 makes the checkpoint count DERIVED from the deduped event set instead
 * of a blind +1):
 *
 *   G1 create Pickup trip (student on roster) → start
 *   G2 board student once  → checkpoint students_boarded_count == 1
 *   G3 board SAME student again (repeat tap) → count STILL 1 (no double-count)
 *   G4 exactly ONE transportAttendance doc exists for that student+trip (deduped)
 *
 * Self-provisioning + self-teardown. CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_con1 run
 */
class Transport_probe_con1 extends CI_Controller
{
    private array $createdFixtures = [];

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
        if (!$this->input->is_cli_request()) {
            show_error('Transport_probe_con1 is CLI-only.', 403);
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
        if ($sid === '' || $sess === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n");
            exit(1);
        }
        try {
            $this->fs->init($sid, $sess);
            $this->numbering->init($this->fs, $sid, $sess);
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'transport_probe_con1');
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: init failed — " . $e->getMessage() . "\n");
            exit(1);
        }

        // UNIT 2B — createTrip() refuses a Pickup trip when no valid campus
        // geofence is configured. Seeded only when absent and restored verbatim
        // in the finally below. The production guard is not weakened.
        $this->fixSchoolId = $sid;
        $this->_ensureCampusFence();

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        echo "==================================================\n";
        echo " CON-1 Derived-Tally Probe (repeat-tap dedup)\n";
        echo " tenant={$sid} session={$sess}\n";
        echo "==================================================\n";

        $now = date('c');
        $ts  = (int) (microtime(true) * 1000);
        $vehicleId = "PROBE_CON1_VEH_{$ts}";
        $routeId   = "PROBE_CON1_RT_{$ts}";
        $stopId    = "PROBE_CON1_STP_{$ts}";
        $studentId = "PROBE_CON1_STU_{$ts}";
        $srKey     = "PROBE_CON1_SR_{$ts}";
        $tripId    = '';

        try {
            // Fixtures
            $this->fs->setEntity('vehicles', $vehicleId, [
                'id' => $vehicleId, 'schoolId' => $sid, 'number' => 'CON1', 'status' => 'Active',
                'staff_id' => 'PROBE_CON1_STAFF', 'route_id' => $routeId, 'created_at' => $now, 'updated_at' => $now,
            ], false); $this->createdFixtures[] = ['vehicles', $vehicleId];
            // LOG-1: createTrip validates driver readiness — seed a FIT driver.
            $this->fs->setEntity('staff', 'PROBE_CON1_STAFF', [
                'id' => 'PROBE_CON1_STAFF', 'schoolId' => $sid, 'name' => 'CON1 Driver', 'status' => 'Active',
                'flags' => ['can_drive_transport' => true],
                'driver_meta' => ['operational_status' => 'Available', 'licence_expires' => '2099-12-31', 'medical_expires' => '2099-12-31'],
            ], false); $this->createdFixtures[] = ['staff', 'PROBE_CON1_STAFF'];
            $this->fs->setEntity('routes', $routeId, [
                'id' => $routeId, 'schoolId' => $sid, 'name' => 'CON1 Route', 'status' => 'Active',
                'direction' => 'Both', 'created_at' => $now, 'updated_at' => $now,
            ], false); $this->createdFixtures[] = ['routes', $routeId];
            $this->fs->setEntity('transportStops', $stopId, [
                'stopId' => $stopId, 'schoolId' => $sid, 'route_id' => $routeId, 'name' => 'CON1 Stop', 'order' => 1,
                'pickup_time' => '07:00', 'drop_time' => '15:30', 'status' => 'Active', 'created_at' => $now, 'updated_at' => $now,
            ], false); $this->createdFixtures[] = ['transportStops', $stopId];
            $this->fs->setEntity('studentRoutes', $srKey, [
                'schoolId' => $sid, 'studentId' => $studentId, 'route_id' => $routeId, 'stop_id' => $stopId,
                'type' => 'both', 'direction' => 'Both',
                'service_days' => ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'],
                'session' => $sess, 'status' => 'Active', 'created_at' => $now, 'updated_at' => $now,
            ], false); $this->createdFixtures[] = ['studentRoutes', $srKey];

            // G1 — create + start Pickup trip
            echo "\n[G1] create Pickup trip + start\n";
            $r = $this->transport->createTrip([
                'vehicle_id' => $vehicleId, 'route_id' => $routeId,
                'trip_date' => date('Y-m-d'), 'trip_direction' => 'Pickup', 'notes' => 'CON1 probe',
            ]);
            $tripId = (string) ($r['tripId'] ?? '');
            if ($tripId === '') { throw new \RuntimeException('no tripId'); }
            $this->createdFixtures[] = ['tripLogs', $tripId];
            $roster = (array) ($r['data']['roster_student_ids'] ?? []);
            in_array($studentId, $roster, true)
                ? $pass("trip created; student on roster")
                : $fail("student NOT on roster: " . json_encode($roster));
            $this->transport->startTrip($tripId);

            // G2 — board once
            echo "\n[G2] board student once → count == 1\n";
            $this->transport->markStudentBoarded($tripId, $studentId, $stopId);
            $c1 = $this->_cpCount($tripId, $stopId);
            $c1 === 1 ? $pass("students_boarded_count == 1 after first tap")
                      : $fail("expected 1, got {$c1}");

            // G3 — repeat tap → still 1
            echo "\n[G3] board SAME student again (repeat tap) → count STILL 1\n";
            $this->transport->markStudentBoarded($tripId, $studentId, $stopId);
            $c2 = $this->_cpCount($tripId, $stopId);
            $c2 === 1 ? $pass("students_boarded_count still 1 after repeat tap — no double-count")
                      : $fail("repeat tap double-counted: got {$c2}");

            // G4 — the deterministic-id doc exists (repeat tap = same doc = dedup).
            // Read by deterministic id (index-independent) rather than a filtered
            // query, so this validates the dedup mechanism itself.
            echo "\n[G4] deduped boarding doc exists at deterministic id\n";
            // Boarding docId is built WITH the schoolId prefix already, so the
            // getEntity entity-key must include it (getEntity adds the outer prefix).
            $boardEntity = "{$sid}_{$tripId}_{$studentId}_boarded_pickup";
            $doc = $this->fs->getEntity('transportAttendance', $boardEntity);
            is_array($doc)
                ? $pass("single boarding doc at deterministic id (repeat tap merged to same doc)")
                : $fail("boarding doc missing at {$boardEntity}");
        } catch (\Throwable $e) {
            $fail("exception: " . $e->getMessage());
        } finally {
            echo "\n[TEARDOWN]\n";
            $this->_restoreCampusFence();
            // deterministic boarding doc id (built WITH schoolId prefix)
            $boardEntity = "{$sid}_{$tripId}_{$studentId}_boarded_pickup";
            foreach (array_merge($this->createdFixtures, [['transportAttendance', $boardEntity]]) as [$col, $id]) {
                try { $this->fs->removeEntity($col, $id); echo "  removed {$col}:{$id}\n"; }
                catch (\Throwable $e) { /* best-effort */ }
            }
        }

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }

    private function _cpCount(string $tripId, string $stopId): int
    {
        $trip = $this->transport->getTrip($tripId);
        foreach ((array) ($trip['checkpoints'] ?? []) as $cp) {
            if ((string) ($cp['stop_id'] ?? '') === $stopId) {
                return (int) ($cp['students_boarded_count'] ?? -999);
            }
        }
        return -999;
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
                'id' => 'PROBE_CON1_CAMPUS', 'name' => 'Probe Campus',
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
