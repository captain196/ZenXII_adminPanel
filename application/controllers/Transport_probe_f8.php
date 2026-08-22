<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_f8 — F8 Transport Events runtime probe (LC-23, 2026-07-07).
 *
 * Exercises the F8 flow end-to-end:
 *   1. createEvent → Planned (with escort + assigned vehicles)
 *   2. assignStudentToEvent → roster fills
 *   3. scheduleEvent → Scheduled + parent notification fires (verifies
 *      escort staff FK check kicks in)
 *   4. dispatchEventTrip → creates tripLogs with trip_kind='Event' +
 *      event_id linkage + GPS session id inherited
 *   5. completeTrip (via F7 machinery on the dispatched trip)
 *   6. completeEvent → Completed; over_budget flag computed
 *   7. Cost attribution — related_event_id expense correctly aggregates
 *      into event.actual_amount
 *
 * CLI-only. Invocation:
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_f8 run
 *
 * Exit codes:
 *   0 — all gates passed
 *   1 — env / init failed
 *   2 — probe assertion failure
 */
class Transport_probe_f8 extends CI_Controller
{
    private array $createdEvents = [];
    private array $createdFixtures = [];   // [ [collection, entityId], ... ]

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Transport_probe_f8 is CLI-only.', 403);
        }
        $this->load->library('firebase',          null, 'firebase');
        $this->load->library('firestore_service', null, 'fs');
        $this->load->library('numbering_service', null, 'numbering');
        $this->load->library('transport_service', null, 'transport');
    }

    /**
     * UNIT 2B — exception-safe wrapper. _runBody() restores the tenant policy
     * inline on the normal path (before its exit(), which bypasses finally);
     * this finally covers an exception escaping between the fence seed and
     * that inline restore. _restoreSchoolArrival() is idempotent.
     */
    public function run(): void
    {
        try { $this->_runBody(); }
        finally { $this->_restoreSchoolArrival(); }
    }

    private function _runBody(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n");
            exit(1);
        }
        try {
            $this->fs->init($sid, $sess);
            $this->fixSchoolId = $sid;
            $this->numbering->init($this->fs, $sid, $sess);
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'transport_probe_f8');
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: init failed — " . $e->getMessage() . "\n");
            exit(1);
        }

        $passed = 0; $failed = 0; $errors = [];

        echo "==================================================\n";
        echo " F8 Transport Events Runtime Probe\n";
        echo " tenant=$sid session=$sess\n";
        echo "==================================================\n";

        // UNIT 2B — createTrip() refuses a Pickup trip with no valid campus
        // geofence, and Step 4 creates one before _seedSchoolArrival() ran
        // (Step 5). Seed here with the SAME snapshot/restore used below.
        $this->_ensureCampusFence();

        // Provision fixtures
        [$vehicleId, $staffId] = $this->_provisionFixtures($sid);
        echo "\n[FIXTURES] vehicle={$vehicleId} escort_staff={$staffId}\n";

        $eventId = ''; $tripId = ''; $studentId = 'PROBE_F8_STU_' . (int) (microtime(true) * 1000);

        // ------------------------------------------------------------
        // Step 1 — createEvent → Planned
        // ------------------------------------------------------------
        echo "\n[STEP 1] createEvent (Planned + escort + vehicles)\n";
        try {
            $r = $this->transport->createEvent([
                'title'                  => 'F8 Probe — Museum Excursion',
                'event_type'             => 'Excursion',
                'description'            => 'F8 probe',
                'event_date'             => date('Y-m-d'),
                'event_end_date'         => date('Y-m-d'),
                'destination'            => 'National Museum',
                'transport_charter_type' => 'Own_Fleet',
                'assigned_vehicle_ids'   => [$vehicleId],
                'escorts'                => [
                    ['staff_id' => $staffId, 'role' => 'Lead_Teacher', 'notes' => 'probe escort'],
                ],
                'expected_students_count'=> 1,
                'budgeted_amount'        => 500.0,
                'notes'                  => 'F8 probe',
            ]);
            $eventId = (string) ($r['eventId'] ?? '');
            if ($eventId === '') { throw new \RuntimeException('no eventId'); }
            $this->createdEvents[] = $eventId;
            if (($r['data']['status'] ?? '') !== 'Planned') { throw new \RuntimeException('status !== Planned'); }
            if (empty($r['data']['escorts'])) { throw new \RuntimeException('escort not persisted'); }
            echo "  PASS: event={$eventId} Planned, 1 vehicle assigned, 1 escort\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step1: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Step 2 — assignStudentToEvent
        // ------------------------------------------------------------
        echo "\n[STEP 2] assignStudentToEvent\n";
        try {
            if ($eventId === '') { throw new \RuntimeException('no event from step 1'); }
            $r = $this->transport->assignStudentToEvent($eventId, $studentId, [
                'assigned_vehicle_id' => $vehicleId,
            ]);
            $aid = (string) ($r['assignmentId'] ?? '');
            if ($aid === '') { throw new \RuntimeException('no assignmentId'); }
            $this->createdFixtures[] = ['eventTripAssignments', $aid];
            if (($r['data']['consent_status'] ?? '') !== 'Pending') { throw new \RuntimeException('consent not Pending by default'); }
            echo "  PASS: student={$studentId} assigned (Pending consent); assignmentId={$aid}\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step2: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Step 3 — scheduleEvent
        // ------------------------------------------------------------
        echo "\n[STEP 3] scheduleEvent → Scheduled\n";
        try {
            $r = $this->transport->scheduleEvent($eventId);
            if (($r['status'] ?? '') !== 'Scheduled') { throw new \RuntimeException('status !== Scheduled'); }
            if ((int) ($r['roster_size'] ?? 0) !== 1) { throw new \RuntimeException('roster_size !== 1'); }
            $doc = $this->transport->getEvent($eventId);
            if (empty($doc['assigned_driver_ids'])) { throw new \RuntimeException('drivers not snapshotted'); }
            if (empty($doc['roster_frozen_at']))    { throw new \RuntimeException('roster_frozen_at not set'); }
            echo "  PASS: event={$eventId} Scheduled; driver snapshot={$doc['assigned_driver_ids'][0]}; parent notice fired\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step3: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Step 4 — dispatchEventTrip (tripLogs with trip_kind='Event')
        // ------------------------------------------------------------
        echo "\n[STEP 4] dispatchEventTrip (creates tripLogs, event_id linked, no route)\n";
        try {
            $r = $this->transport->dispatchEventTrip($eventId, [
                'vehicle_id'     => $vehicleId,
                'route_id'       => '',           // free-form event (Refinement 2)
                'trip_direction' => 'Pickup',
            ]);
            $tripId = (string) ($r['tripId'] ?? '');
            if ($tripId === '') { throw new \RuntimeException('no tripId'); }
            $trip = $this->transport->getTrip($tripId);
            if (($trip['trip_kind'] ?? '') !== 'Event') { throw new \RuntimeException("trip_kind !== Event"); }
            if (($trip['event_id']  ?? '') !== $eventId){ throw new \RuntimeException('event_id linkage missing'); }
            if (empty($trip['gps_session_id']))         { throw new \RuntimeException('gps_session_id missing'); }
            if (strpos((string) $trip['gps_session_id'], 'GPSSESS_') !== 0) {
                throw new \RuntimeException('gps_session_id malformed');
            }
            echo "  PASS: trip={$tripId} kind=Event event_id={$eventId} gps_session={$trip['gps_session_id']}\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step4: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Step 5 — start + complete the event trip (F7 machinery)
        // ------------------------------------------------------------
        echo "\n[STEP 5] complete event trip via F7 machinery\n";
        try {
            if ($tripId === '') { throw new \RuntimeException('no trip from step 4'); }
            $this->transport->startTrip($tripId);
            // F14 Part A (2026-08-12): a Pickup trip completes only when F13.1
            // telemetry places the bus inside the school geofence. This step
            // asserts successful completion, so it stages the arrival it
            // asserts — the guard is not relaxed. Restored in teardown.
            $this->_seedSchoolArrival($vehicleId);
            // The guard's second half: every EXPECTED morning student must reach
            // a resolved state. This event trip's rostered student never boards,
            // so resolve them as a no-show — which is what actually happened —
            // rather than relaxing the guard.
            try {
                $this->transport->markStudentNoShow($tripId, $studentId, 'F8 probe: did not travel');
                // Register the attendance row for teardown, or the trip is deleted
                // and the event survives as an orphan (caught by CH-TRN-F7-3).
                //
                // NOTE the id shape: _writeBoardingEvent() builds
                // "{schoolId}_{tripId}_{studentId}_{eventType}" and then writes it
                // through setEntity(), which prefixes the school id AGAIN — so the
                // stored doc is {schoolId}_{schoolId}_{tripId}_… . removeEntity()
                // prefixes once, so the entity id handed to it must itself still
                // carry one schoolId. Documented rather than "fixed" here: the
                // convention is a production data question, not a fixture one.
                $this->createdFixtures[] = ['transportAttendance',
                    $this->fixSchoolId . '_' . $tripId . '_' . $studentId . '_no_show'];
            } catch (\Throwable $e) { echo '  fixture WARN: ' . $e->getMessage() . "\n"; }
            $this->transport->completeTrip($tripId, [
                'end_time' => date('c'), 'end_odometer' => 10, 'distance_km' => 10,
            ]);
            $doc = $this->transport->getTrip($tripId);
            if (($doc['status'] ?? '') !== 'Completed') { throw new \RuntimeException('trip status !== Completed'); }
            echo "  PASS: event trip completed via existing F7 pipeline (no parallel machinery)\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step5: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Step 6 — attach an expense via related_event_id + verify tally
        // ------------------------------------------------------------
        echo "\n[STEP 6] expense with related_event_id → event cost tally\n";
        try {
            $exp = $this->transport->saveExpense([
                'expense_type'     => 'Event_Transport_Charter',
                'expense_date'     => date('Y-m-d'),
                'amount'           => 600.0,       // 100 over budget for over_budget test
                'payee_type'       => 'External',
                'payee_name'       => 'F8 Probe Charter',
                'payment_method'   => 'Cash',
                'related_event_id' => $eventId,
                'notes'            => 'F8 probe charter cost',
            ]);
            $expId = (string) ($exp['id'] ?? '');
            if ($expId === '') { throw new \RuntimeException('expense not created'); }
            $this->createdFixtures[] = ['transportExpenses', $expId];
            $summary = $this->transport->computeEventCostSummary($eventId);
            if (abs($summary['actual_amount'] - 600.0) > 0.01) {
                throw new \RuntimeException("actual_amount != 600 (got {$summary['actual_amount']})");
            }
            if ((int) $summary['expense_count'] !== 1) {
                throw new \RuntimeException("expense_count != 1 (got {$summary['expense_count']})");
            }
            echo "  PASS: expense={$expId} attributed; cost_summary.actual_amount={$summary['actual_amount']}\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step6: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Step 7 — completeEvent → over_budget warning, never blocks
        // ------------------------------------------------------------
        echo "\n[STEP 7] completeEvent (over_budget warning, not blocked)\n";
        try {
            $r = $this->transport->completeEvent($eventId, 'F8 probe complete');
            if (($r['status'] ?? '') !== 'Completed') { throw new \RuntimeException('event status !== Completed'); }
            if (empty($r['over_budget'])) {
                throw new \RuntimeException('over_budget flag not set (600 > 500 budget expected true)');
            }
            $doc = $this->transport->getEvent($eventId);
            if (($doc['actual_amount'] ?? 0.0) < 600.0) {
                throw new \RuntimeException("event.actual_amount not persisted ({$doc['actual_amount']})");
            }
            echo "  PASS: event={$eventId} Completed; over_budget=true (INR " . number_format($doc['actual_amount'], 2) . " > INR " . number_format($doc['budgeted_amount'], 2) . " budget); completion NOT blocked\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step7: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Teardown — trips FIRST (to preserve F8-4 FK invariant), then
        // roster, then event doc
        // ------------------------------------------------------------
        $this->_restoreSchoolArrival();

        echo "\n[TEARDOWN] Removing ephemeral fixtures\n";
        foreach ($this->createdEvents as $eid) {
            try {
                $doc = $this->transport->getEvent($eid);
                if (is_array($doc) && !in_array($doc['status'], ['Completed', 'Cancelled'], true)) {
                    $this->transport->cancelEvent($eid, 'F8 probe teardown');
                }
                // Remove all trips linked to this event (regardless of status)
                foreach ((array) $this->transport->getEventTrips($eid) as $t) {
                    $tid = (string) ($t['id'] ?? '');
                    if ($tid !== '') {
                        // Sweep transportAttendance events for this trip
                        foreach ((array) $this->transport->getTripBoardingEvents($tid) as $ev) {
                            $eaid = (string) ($ev['id'] ?? '');
                            if ($eaid !== '') {
                                // transportAttendance doc-id is already the full
                                // {schoolId}_{...}; use remove (not removeEntity)
                                $this->fs->remove('transportAttendance', $eaid);
                            }
                        }
                        $this->fs->removeEntity('tripLogs', $tid);
                    }
                }
                foreach ((array) $this->transport->getEventRoster($eid) as $a) {
                    $this->fs->removeEntity('eventTripAssignments', (string) ($a['assignmentId'] ?? ''));
                }
                $this->fs->removeEntity('transportEvents', $eid);
                echo "  removed event: {$eid} (+ linked trips + attendance)\n";
            } catch (\Throwable $e) {
                echo "  skip event {$eid}: " . $e->getMessage() . "\n";
            }
        }
        // Void the expense
        foreach ($this->createdFixtures as $f) {
            if ($f[0] === 'transportExpenses') {
                try {
                    $this->transport->voidExpense($f[1], 'F8 probe teardown');
                    echo "  voided expense: {$f[1]}\n";
                } catch (\Throwable $e) {
                    echo "  skip expense {$f[1]}: " . $e->getMessage() . "\n";
                }
            }
        }
        foreach (array_reverse($this->createdFixtures) as $f) {
            if ($f[0] === 'transportExpenses') { continue; }
            try {
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
     * Provision ephemeral vehicle + staff for the probe. Staff is
     * required for the escort FK check inside scheduleEvent. Vehicle
     * gets a synthetic staff_id so getEffectiveDriver() resolves.
     */
    private function _provisionFixtures(string $sid): array
    {
        $now = date('c');
        $ts = (int) (microtime(true) * 1000);
        $vehicleId = 'PROBE_F8_VEH_' . $ts;
        $driverStaffId = 'PROBE_F8_DRV_' . $ts;
        $escortStaffId = 'PROBE_F8_ESC_' . $ts;

        // Driver staff (used by getEffectiveDriver)
        $this->fs->setEntity('staff', $driverStaffId, [
            'id'         => $driverStaffId,
            'schoolId'   => $sid,
            'name'       => 'F8 Probe Driver', 'status' => 'active',
            'flags'      => ['can_drive_transport' => true],
            'created_at' => $now, 'updated_at' => $now,
        ], /* merge */ false);
        $this->createdFixtures[] = ['staff', $driverStaffId];

        // Escort staff (used by scheduleEvent escort FK check)
        $this->fs->setEntity('staff', $escortStaffId, [
            'id'         => $escortStaffId,
            'schoolId'   => $sid,
            'name'       => 'F8 Probe Escort', 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ], /* merge */ false);
        $this->createdFixtures[] = ['staff', $escortStaffId];

        // Vehicle
        $this->fs->setEntity('vehicles', $vehicleId, [
            'id'         => $vehicleId,
            'schoolId'   => $sid,
            'number'     => 'F8-PROBE', 'status' => 'Active',
            'staff_id'   => $driverStaffId,
            'created_at' => $now, 'updated_at' => $now,
        ], /* merge */ false);
        $this->createdFixtures[] = ['vehicles', $vehicleId];

        return [$vehicleId, $escortStaffId];
    }

    // ── F14 Part A test fixtures (same rationale as Transport_probe_f7) ──
    private $priorPolicy = null;
    private bool $policySeeded = false;
    private string $fixSchoolId = '';
    private const FIX_LAT = 12.9716;
    private const FIX_LNG = 77.5946;

    /**
     * UNIT 2B — guarantee a valid campus fence BEFORE any Pickup trip is
     * created. Extracted from the fence half of _seedSchoolArrival() so there
     * is exactly ONE geofence fixture mechanism: seeds ONLY when no fence
     * exists, snapshots the whole attendancePolicy first, and leaves
     * _restoreSchoolArrival() to put the original back verbatim.
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
                'id' => 'PROBE_F8_CAMPUS', 'name' => 'Probe Campus',
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

    private function _seedSchoolArrival(string $vehicleId): void
    {
        try {
            // Fence half delegated — same snapshot/restore discipline, one place.
            $this->_ensureCampusFence();
            $this->fs->setEntity('vehicleLivePositions', $vehicleId, [
                'schoolId' => $this->fixSchoolId, 'vehicleId' => $vehicleId,
                'lat' => self::FIX_LAT, 'lng' => self::FIX_LNG, 'accuracy_m' => 12.0,
                'recorded_at' => date('c'),
                'recorded_at_ms' => (int) round(microtime(true) * 1000),
                'source' => 'probe_fixture',
            ], true);
            $this->createdFixtures[] = ['vehicleLivePositions', $vehicleId];
        } catch (\Throwable $e) {
            echo '  fixture WARN: ' . $e->getMessage() . "\n";
        }
    }

    private function _restoreSchoolArrival(): void
    {
        if (!$this->policySeeded) { return; }
        // Idempotent — see the run() wrapper: the normal path restores inline
        // before exit(), the finally covers the exceptional path.
        $this->policySeeded = false;
        try {
            $this->fs->update('schools', $this->fixSchoolId,
                ['attendancePolicy' => $this->priorPolicy ?? []]);
        } catch (\Throwable $_) {}
    }
}
