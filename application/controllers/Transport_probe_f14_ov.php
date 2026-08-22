<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_f14_ov — F14 Phase 2A gates OV-1 … OV-14.
 *
 * The admin completion override exists for ONE deadlock: the bus has genuinely
 * reached school but GPS cannot prove it. These gates exist to prove it excuses
 * nothing else — above all that it does NOT complete a trip away from a child
 * who is unaccounted for or still aboard (OV-7 / OV-8).
 *
 * Those two are the gates that matter. `school_not_reached` returns EARLY from
 * completionBlockers(), so an override implemented by ignoring that verdict
 * would have silently skipped both student-safety checks as well. OV-7 and OV-8
 * are what stop that implementation from ever being mistaken for this one.
 *
 * Real Firestore, real fixtures in the test tenant only, removed afterwards.
 * The campus policy is snapshotted and restored verbatim.
 *
 *   SCHOOL_ID=SCH_202512 SESSION_YEAR=2026-27 php index.php transport_probe_f14_ov run
 */
class Transport_probe_f14_ov extends CI_Controller
{
    /** Fixtures are written; refuse to run anywhere but the test tenant. */
    private const ALLOWED_TENANT = 'SCH_202512';

    public $fs = null;
    public $numbering = null;
    public $transport = null;
    public $qratt = null;

    private int $pass = 0;
    private int $fail = 0;
    private array $failures = [];
    private array $fixtures = [];
    private string $tag = '';
    private string $sid = '';
    private string $sess = '';

    private $priorPolicy = null;
    private bool $policySeeded = false;
    private const FIX_LAT = 12.9716;
    private const FIX_LNG = 77.5946;

    public function __construct()
    {
        parent::__construct();
        if (!is_cli()) { show_404(); }
    }

    private function ok(string $id, string $w): void { $this->pass++; printf("  PASS  %-6s %s\n", $id, $w); }
    private function bad(string $id, string $w, string $d): void
    {
        $this->fail++; $this->failures[] = "{$id} — {$w}: {$d}";
        printf("  FAIL  %-6s %s\n          → %s\n", $id, $w, $d);
    }
    private function eq(string $id, string $w, $exp, $got): void
    {
        if ($exp === $got) { $this->ok($id, $w); return; }
        $this->bad($id, $w, 'expected ' . var_export($exp, true) . ', got ' . var_export($got, true));
    }
    private function truthy(string $id, string $w, $v, string $d = ''): void
    {
        if ($v) { $this->ok($id, $w); return; }
        $this->bad($id, $w, $d !== '' ? $d : 'falsy');
    }

    /**
     * Assert completeTrip() refuses, and that it refuses for the EXPECTED
     * reason. A gate that accepted any refusal would pass even when the trip
     * was blocked by something unrelated.
     */
    private function refuses(string $id, string $w, string $needle, callable $fn): void
    {
        try {
            $fn();
            $this->bad($id, $w, 'completion was ALLOWED — no exception thrown');
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, $needle) !== false) { $this->ok($id, $w); return; }
            $this->bad($id, $w, "refused, but for the wrong reason: {$msg}");
        }
    }

    public function run(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n"); exit(1);
        }
        if ($sid !== self::ALLOWED_TENANT) {
            fwrite(STDERR, "REFUSED: this probe writes fixtures and runs only on "
                . self::ALLOWED_TENANT . " (got {$sid}).\n");
            exit(1);
        }
        $this->sid  = $sid;
        $this->sess = $sess;
        $this->tag  = (string) (int) (microtime(true) * 1000);

        echo "==================================================\n";
        echo " F14 Phase 2A — completion override   tenant={$sid}\n";
        echo "==================================================\n";

        $this->load->library('firestore_service', null, 'fs');
        $this->fs->init($sid, $sess);
        $this->load->library('numbering_service', null, 'numbering');
        $this->numbering->init($this->fs, $sid, $sess);
        $this->load->library('transport_service', null, 'transport');
        $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'PROBE_F14OV_ADMIN');
        $this->load->library('transport_qr_attendance_service', null, 'qratt');
        $this->qratt->init($this->fs, $this->transport, $sid, $sess, 'PROBE_F14OV_ADMIN');

        try {
            // UNIT 2B — createTrip() refuses a Pickup trip when no valid campus
            // geofence is configured, and _makeTrip() creates Pickup trips long
            // before _seedArrival() ran. Seeding here — with the SAME
            // snapshot/restore used below — makes the suite independently
            // runnable on a tenant with no fence. The guard is not weakened.
            $this->_ensureCampusFence();
            $this->structural();
            $this->behavioural($sid, $sess);
        } catch (\Throwable $e) {
            $this->bad('EXC', 'unhandled exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        } finally {
            echo "\n[TEARDOWN]\n";
            $this->_restorePolicy();
            $removed = 0;
            foreach (array_reverse($this->fixtures) as [$col, $docId]) {
                if ($docId === '') { continue; }
                try { $this->fs->removeEntity($col, $docId); $removed++; } catch (\Throwable $_) {}
            }
            echo "  removed {$removed} fixture doc(s); campus policy restored="
                . ($this->policySeeded ? 'yes' : 'not-modified') . "\n";
        }

        echo "\n==================================================\n";
        echo " RESULT: pass={$this->pass} fail={$this->fail}\n";
        if ($this->failures !== []) {
            echo "\n DEFECTS:\n";
            foreach ($this->failures as $f) { echo "   • {$f}\n"; }
        }
        echo "==================================================\n";
        if ($this->fail > 0) { exit(1); }
    }

    // ─── Structural gates ────────────────────────────────────────────────

    private function structural(): void
    {
        echo "\n[OV structural] authorization, provenance, no back doors\n";
        $svc  = file_get_contents(APPPATH . 'libraries/Transport_service.php');
        $ctl  = file_get_contents(APPPATH . 'controllers/Transport_trip.php');

        // OV-1 — the endpoint must demand `manage`. Ordinary completion is
        // `edit`, so an edit-level operator must not reach the override, and a
        // driver (who holds no Transport grant at all) certainly must not.
        $this->truthy('OV-1', 'override endpoint requires Transport · manage',
            preg_match("/function override_complete\(\)[^}]*?require_permission\(\s*'Transport',\s*'override_complete_trip',\s*'manage'\s*\)/s", $ctl) === 1,
            'the endpoint is missing, or does not gate on manage');

        // OV-1b — and it must not be reachable at the ordinary `edit` grade.
        $this->truthy('OV-1b', 'override is a strictly higher grade than ordinary completion',
            preg_match("/function complete\(\)[^}]*?require_permission\(\s*'Transport',\s*'trip_complete',\s*'edit'\s*\)/s", $ctl) === 1
            && strpos($ctl, "'override_complete_trip', 'manage'") !== false);

        // OV-14 — no generic force-complete mechanism, and the override cannot
        // be smuggled through the client-populated $input array.
        $this->truthy('OV-14', 'no generic forceCompleteTrip() exists anywhere',
            strpos($svc, 'forceCompleteTrip') === false
            && strpos($ctl, 'forceCompleteTrip') === false);
        $this->truthy('OV-14b', 'override is a separate argument, not an $input key',
            preg_match('/function completeTrip\(string \$tripId, array \$input = \[\], array \$override = \[\]\)/', $svc) === 1,
            'override travels inside $input — a client could set it on the ordinary endpoint');
        $this->truthy('OV-14c', 'override-shaped keys are stripped from client $input',
            preg_match("/unset\(\\\$input\['_override'\][^;]*completion_override/s", $svc) === 1);
        // Needle updated 2026-08-13 (Batch 0): the source moved from
        // session('user_id') — which Admin_login NEVER sets, so override_by
        // recorded the literal fallback instead of an administrator — to the
        // canonical $this->admin_id. The assertion is unchanged and stronger:
        // provenance must come from the authenticated session, never the body.
        $this->truthy('OV-14d', 'provenance comes from the session, never the request body',
            preg_match("/'by'\s*=>\s*\(string\) \(\\\$this->admin_id/", $ctl) === 1
            && strpos($ctl, "userdata('user_id')") === false,
            'override_by is taken from POST, or still reads the unset user_id key');

        // OV-10b — canonical audit path, distinct action, no parallel mechanism.
        $this->truthy('OV-10b', "audit uses emitAudit with a distinct 'completed_override' action",
            strpos($svc, "\$ovActive ? 'completed_override' : 'completed'") !== false);

        // The structural half of OV-5/OV-6: the override must be routed into the
        // blocker evaluation, not applied by discarding its verdict afterwards.
        $this->truthy('OV-6b', 'override is passed INTO the arrival guard',
            strpos($svc, '_assertSchoolArrivalBeforeComplete($tripId, $ovActive)') !== false);
        $qsrc = file_get_contents(APPPATH . 'libraries/Transport_qr_attendance_service.php');
        $this->truthy('OV-7b', 'override skips ONLY the geofence block, then falls through',
            strpos($qsrc, 'if (!$skipArrivalCheck) {') !== false
            && preg_match('/skipArrivalCheck.*?unresolved_students.*?still_on_board/s', $qsrc) === 1,
            'the student-safety blockers are not evaluated after an override');

        // OV-9b — provenance fields are written INSIDE the CAS mutator.
        $posMut = strpos($svc, "\$summary['completion_override'] = true;");
        $posRet = strpos($svc, 'return $summary;', (int) $posMut);
        $this->truthy('OV-9b', 'override fields written inside the CAS mutator (atomic)',
            $posMut !== false && $posRet !== false && $posMut < $posRet
            && strpos($svc, 'casUpdateEntity(self::COL_TRIP_LOGS') < $posMut,
            'override metadata is written outside the compare-and-set transition');
    }

    // ─── Behavioural gates (real trip, real Firestore) ───────────────────

    private function behavioural(string $sid, string $sess): void
    {
        echo "\n[OV behavioural] real Pickup trip, GPS deliberately absent\n";
        $today = date('Y-m-d');

        // Trip 1 — a live Pickup trip with ONE rostered student and NO GPS fix.
        // The assignment MUST exist before createTrip(): the roster is snapshotted
        // into roster_student_ids at creation, so assigning afterwards yields an
        // EMPTY roster — and an empty roster has nothing unresolved, which would
        // let OV-7/OV-8 "pass" while proving nothing at all.
        $stu = 'PROBE_F14OV_STU_' . $this->tag;
        [$veh, $route, $trip, $stop] = $this->_makeTrip('A', 'Pickup', $stu);

        // OV-0 — precondition. Never let the safety gates below run against an
        // empty roster; if this fails, OV-7/OV-8 prove nothing and must not be
        // read as green.
        $rosterIds = (array) ($this->_trip($trip)['roster_student_ids'] ?? []);
        $this->truthy('OV-0', 'PRECONDITION: the trip roster actually contains the student',
            in_array($stu, array_map('strval', $rosterIds), true),
            'roster_student_ids = ' . json_encode($rosterIds)
            . ' — OV-7/OV-8 below would be vacuous, treat them as INVALID');

        // Checkpoints must be terminal, or the pre-existing Pending guard — not
        // the arrival guard — would be what refuses. Skipping isolates the gate.
        $this->transport->markCheckpointSkipped($trip, $stop, 'PROBE_F14OV isolate arrival guard');

        // OV-5 — GPS cannot prove arrival ⇒ ORDINARY completion is refused.
        $this->refuses('OV-5', 'no GPS proof ⇒ ordinary completion refused',
            'Arrival at school cannot be confirmed',
            fn() => $this->transport->completeTrip($trip, ['end_odometer' => 5]));

        // OV-2 — a blank reason is not an override.
        $this->refuses('OV-2', 'override with a blank reason is refused',
            'written reason', fn() => $this->transport->completeTrip($trip, ['end_odometer' => 5], ['reason' => '   ']));
        $this->refuses('OV-2b', 'override with a missing reason key is refused',
            'written reason', fn() => $this->transport->completeTrip($trip, ['end_odometer' => 5], ['by' => 'X']));

        // OV-7 — THE gate. The override excuses the missing GPS, and the trip is
        // STILL refused because a child is unaccounted for.
        $this->refuses('OV-7', 'override does NOT bypass unresolved students',
            'not accounted for',
            fn() => $this->transport->completeTrip($trip, ['end_odometer' => 5],
                ['reason' => 'PROBE_F14OV bus at school, GPS dead']));

        // OV-8 — board the child, so they are aboard and not yet alighted. The
        // override must still refuse.
        $this->transport->markBoardedAtStop($trip, $stu, $stop, 'PROBE_F14OV');
        // Register the attendance event for teardown. These are NOT created by
        // setEntity here, so they are not auto-tracked, and CH-TRN-F7-3 (orphan
        // attendance FK) legitimately flags them once the trip is removed.
        // Stored ids carry the deliberate doubled school prefix, so the entity id
        // handed to removeEntity retains exactly ONE — it re-adds the other.
        $this->_trackEvent($trip, $stu, 'boarded_at_stop');
        $this->refuses('OV-8', 'override does NOT bypass a student still on board',
            'still marked on board',
            fn() => $this->transport->completeTrip($trip, ['end_odometer' => 5],
                ['reason' => 'PROBE_F14OV bus at school, GPS dead']));

        // OV-6 — account for the child, and only now does the override complete
        // the trip despite the total absence of GPS proof.
        $this->transport->markAlightedAtSchool($trip, $stu, 'PROBE_F14OV');
        $this->_trackEvent($trip, $stu, 'alighted_at_school');
        $res = [];
        try {
            $res = $this->transport->completeTrip($trip, ['end_odometer' => 5],
                ['reason' => 'PROBE_F14OV bus at school, driver phone GPS dead']);
            $this->eq('OV-6', 'override completes the trip despite GPS failure', 'Completed', (string) ($res['status'] ?? ''));
        } catch (\Throwable $e) {
            $this->bad('OV-6', 'override completes the trip despite GPS failure', $e->getMessage());
        }

        // OV-9 — all four provenance fields persisted on the stored document.
        $doc = $this->_trip($trip);
        $this->eq('OV-9',  'completion_override persisted as true', true, (bool) ($doc['completion_override'] ?? false));
        $this->truthy('OV-9c', 'override_reason persisted verbatim',
            strpos((string) ($doc['override_reason'] ?? ''), 'driver phone GPS dead') !== false,
            'stored reason: ' . (string) ($doc['override_reason'] ?? '(none)'));
        $this->truthy('OV-9d', 'override_by persisted', trim((string) ($doc['override_by'] ?? '')) !== '');
        $this->truthy('OV-9e', 'override_at persisted', trim((string) ($doc['override_at'] ?? '')) !== '');
        $this->eq('OV-9f', 'trip really is Completed', 'Completed', (string) ($doc['status'] ?? ''));

        // OV-10 — audited as an override, not as an ordinary completion.
        $this->truthy('OV-10', "audit row written with action 'completed_override'",
            $this->_auditHas($trip, 'completed_override'),
            'no completed_override audit row found for ' . $trip);

        // OV-11 — a retry must not complete twice.
        $this->refuses('OV-11', 'retrying the override does not double-complete',
            'not InProgress',
            fn() => $this->transport->completeTrip($trip, ['end_odometer' => 5],
                ['reason' => 'PROBE_F14OV retry']));

        // OV-4 — invalid state generally (a Ready trip was never started).
        [, , $ready] = $this->_makeTrip('R', 'Pickup');
        $this->refuses('OV-4', 'override refused on a trip that is not InProgress',
            'not InProgress',
            fn() => $this->transport->completeTrip($ready, ['end_odometer' => 1],
                ['reason' => 'PROBE_F14OV wrong state']));

        // OV-Q — a Pending checkpoint must still block, override or not. The
        // trips above deliberately skip their checkpoints to isolate the arrival
        // guard, so this is the one case that proves the pre-existing Refinement-4
        // guard was not weakened by the override path.
        [, , $pTrip] = $this->_makeTrip('P', 'Pickup');
        $this->refuses('OV-Q', 'override does NOT bypass a Pending checkpoint',
            'still Pending',
            fn() => $this->transport->completeTrip($pTrip, ['end_odometer' => 5],
                ['reason' => 'PROBE_F14OV pending checkpoint']));

        // OV-3 — the override is meaningless on the afternoon leg and must be
        // refused rather than stamping a misleading marker.
        [$dVeh, , $dTrip, $dStop] = $this->_makeTrip('D', 'Drop');
        $this->transport->markCheckpointSkipped($dTrip, $dStop, 'PROBE_F14OV');
        $this->refuses('OV-3', 'override refused on a Drop trip (Pickup-only)',
            'Pickup trips only',
            fn() => $this->transport->completeTrip($dTrip, ['end_odometer' => 5],
                ['reason' => 'PROBE_F14OV wrong leg']));
        // …and that same Drop trip still completes NORMALLY, proving OV-3 did not
        // break the afternoon leg.
        try {
            $r = $this->transport->completeTrip($dTrip, ['end_odometer' => 5]);
            $this->eq('OV-3b', 'the Drop trip still completes normally', 'Completed', (string) ($r['status'] ?? ''));
        } catch (\Throwable $e) {
            $this->bad('OV-3b', 'the Drop trip still completes normally', $e->getMessage());
        }

        // OV-12 — ordinary completion is UNCHANGED: with a real geofence and a
        // real in-fence fix, and no override, it succeeds and is NOT marked as
        // overridden.
        [$nVeh, , $nTrip, $nStop] = $this->_makeTrip('N', 'Pickup');
        $this->transport->markCheckpointSkipped($nTrip, $nStop, 'PROBE_F14OV');
        $this->_seedArrival($nVeh);
        try {
            $r = $this->transport->completeTrip($nTrip, ['end_odometer' => 7]);
            $this->eq('OV-12', 'GPS-verified completion still succeeds without an override',
                'Completed', (string) ($r['status'] ?? ''));
        } catch (\Throwable $e) {
            $this->bad('OV-12', 'GPS-verified completion still succeeds without an override', $e->getMessage());
        }
        $nDoc = $this->_trip($nTrip);
        $this->eq('OV-12b', 'an ordinary completion carries NO override marker',
            false, (bool) ($nDoc['completion_override'] ?? false));
        $this->eq('OV-12c', 'an ordinary completion is audited as ordinary',
            false, $this->_auditHas($nTrip, 'completed_override'));

        // OV-13 — the marker actually reaches the Trip Monitor payload, or the
        // UI badge would silently never render.
        $rows = (array) $this->transport->listTrips(['date' => $today]);
        $seen = null;
        foreach ($rows as $t) {
            if ((string) ($t['id'] ?? '') === $trip) { $seen = $t; break; }
        }
        $this->truthy('OV-13', 'overridden trip appears in the Trip Monitor list payload', is_array($seen),
            'the completed override trip was absent from listTrips()');
        if (is_array($seen)) {
            $this->eq('OV-13b', 'list payload exposes completion_override to the UI',
                true, (bool) ($seen['completion_override'] ?? false));
            $this->truthy('OV-13c', 'list payload exposes the reason for the UI badge',
                trim((string) ($seen['override_reason'] ?? '')) !== '');
        }

        // OV-13d — the live fleet map lifecycle is untouched: a completed trip
        // (overridden or not) must NOT be resurrected onto the live fleet.
        $view = file_get_contents(APPPATH . 'views/transport/index.php');
        $this->truthy('OV-13d', 'override surfaced in the Trip Monitor, not the live fleet',
            strpos($view, 'OVERRIDDEN') !== false
            && strpos(file_get_contents(APPPATH . 'views/transport/fleet_live.php'), 'completion_override') === false,
            'completed-trip override metadata leaked into the live fleet surface');
    }

    // ─── Fixtures ────────────────────────────────────────────────────────

    /** @return array{0:string,1:string,2:string,3:string} veh, route, trip, stop */
    private function _makeTrip(string $tag, string $direction, string $assignStudent = ''): array
    {
        $now = date('c');
        $t   = $this->tag . '_' . $tag;
        $veh   = "PROBE_F14OV_{$t}_VEH";
        $route = "PROBE_F14OV_{$t}_RT";
        $staff = "PROBE_F14OV_{$t}_STA";
        $stop  = "PROBE_F14OV_{$t}_STP";

        $this->fs->setEntity('staff', $staff, [
            'id' => $staff, 'schoolId' => $this->sid, 'name' => "OV Driver {$tag}",
            'status' => 'Active', 'phone' => '9000000000',
            'flags' => ['can_drive_transport' => true],
            'driver_meta' => ['operational_status' => 'Available',
                              'licence_expires' => '2099-12-31', 'medical_expires' => '2099-12-31'],
        ], false);
        $this->fixtures[] = ['staff', $staff];

        $this->fs->setEntity('vehicles', $veh, [
            'id' => $veh, 'schoolId' => $this->sid, 'number' => "OV-{$tag}", 'status' => 'Active',
            'staff_id' => $staff, 'route_id' => $route, 'created_at' => $now, 'updated_at' => $now,
        ], false);
        $this->fixtures[] = ['vehicles', $veh];

        $this->fs->setEntity('routes', $route, [
            'id' => $route, 'schoolId' => $this->sid, 'name' => "OV Route {$tag}",
            'status' => 'Active', 'direction' => 'Both', 'created_at' => $now, 'updated_at' => $now,
        ], false);
        $this->fixtures[] = ['routes', $route];

        $this->fs->setEntity('transportStops', $stop, [
            'stopId' => $stop, 'schoolId' => $this->sid, 'route_id' => $route,
            'name' => "OV Stop {$tag}", 'order' => 1,
            'pickup_time' => '06:30', 'drop_time' => '15:30',
            'latitude' => self::FIX_LAT + 0.05, 'longitude' => self::FIX_LNG + 0.05,
            'status' => 'Active', 'created_at' => $now, 'updated_at' => $now,
        ], false);
        $this->fixtures[] = ['transportStops', $stop];

        // Assign BEFORE createTrip — the roster is snapshotted at creation.
        if ($assignStudent !== '') {
            $this->_assign($assignStudent, $route, $stop, $this->sid, $this->sess);
        }

        $r = $this->transport->createTrip([
            'vehicle_id' => $veh, 'route_id' => $route,
            'trip_date' => date('Y-m-d'), 'trip_direction' => $direction,
        ]);
        $trip = (string) ($r['tripId'] ?? '');
        $this->fixtures[] = ['tripLogs', $trip];
        // 'R' is deliberately left in Ready state for OV-4.
        if ($tag !== 'R') { $this->transport->startTrip($trip); }

        return [$veh, $route, $trip, $stop];
    }

    private function _assign(string $stu, string $route, string $stop, string $sid, string $sess): void
    {
        $id = 'PROBE_F14OV_SR_' . $this->tag;
        $this->fs->setEntity('studentRoutes', $id, [
            'id' => $id, 'schoolId' => $sid, 'session' => $sess, 'studentId' => $stu,
            'route_id' => $route, 'stop_id' => $stop,
            'status' => 'Active', 'type' => 'both',
            'service_days' => [substr(date('l'), 0, 3)],
            'created_at' => date('c'), 'updated_at' => date('c'),
        ], false);
        $this->fixtures[] = ['studentRoutes', $id];
    }

    /**
     * Register a boarding event for teardown. See the doubled-prefix warning in
     * Transport_service::_writeBoardingEvent — the convention is deliberate and
     * is NOT to be "fixed"; this simply mirrors it so cleanup targets the right
     * document.
     */
    private function _trackEvent(string $tripId, string $studentId, string $event): void
    {
        $this->fixtures[] = ['transportAttendance',
            $this->sid . '_' . $tripId . '_' . $studentId . '_' . $event];
    }

    private function _trip(string $tripId): array
    {
        $d = $this->fs->getEntity('tripLogs', $tripId);
        if (!is_array($d)) { return []; }
        // getEntity() returns FLAT fields; guard anyway in case a caller changes.
        if (isset($d['data']) && is_array($d['data']) && !isset($d['status'])) { $d = $d['data']; }
        return $d;
    }

    private function _auditHas(string $tripId, string $action): bool
    {
        try {
            $rows = (array) $this->fs->schoolWhere('transportAuditLog',
                [['entity_id', '==', $tripId], ['action', '==', $action]], null, 'ASC', 20);
        } catch (\Throwable $e) {
            // Fall back to an unfiltered scan rather than reporting a false
            // negative because a composite index is missing.
            try {
                $rows = (array) $this->fs->schoolWhere('transportAuditLog',
                    [['entity_id', '==', $tripId]], null, 'ASC', 50);
            } catch (\Throwable $_) { return false; }
            foreach ($rows as $r) {
                $d = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
                if ((string) ($d['action'] ?? '') === $action) { return true; }
            }
            return false;
        }
        return $rows !== [];
    }

    /**
     * UNIT 2B — guarantee a valid campus fence BEFORE any Pickup trip is
     * created. Behaviourally identical to the fence half of _seedArrival()
     * that it was extracted from: seeds ONLY when no fence exists, snapshots
     * the whole attendancePolicy first, and leaves _restorePolicy() to put the
     * original back verbatim. A tenant that already has a fence is preserved.
     */
    private function _ensureCampusFence(): void
    {
        if ($this->policySeeded) { return; }
        try {
            $school = $this->fs->get('schools', $this->sid);
            $school = is_array($school) ? ($school['data'] ?? $school) : [];
            $pol    = (array) ($school['attendancePolicy'] ?? []);
            if ((array) (($pol['gps'] ?? [])['geofences'] ?? []) !== []) {
                echo "  fixture: campus fence already present — preserved, not overwritten\n";
                return;
            }
            $this->priorPolicy = $pol;
            $gps = (array) ($pol['gps'] ?? []);
            $gps['geofences'] = [[
                'id' => 'PROBE_F14OV_CAMPUS', 'name' => 'Probe Campus',
                'centerLat' => self::FIX_LAT, 'centerLng' => self::FIX_LNG, 'radius' => 300,
            ]];
            if (!isset($gps['maxAccuracyMeters'])) { $gps['maxAccuracyMeters'] = 100; }
            $pol['gps'] = $gps;
            $this->fs->update('schools', $this->sid, ['attendancePolicy' => $pol]);
            $this->policySeeded = true;
            echo "  fixture: temporary campus fence seeded (restored at teardown)\n";
        } catch (\Throwable $e) {
            echo '  fixture WARN (_ensureCampusFence): ' . $e->getMessage() . "\n";
        }
    }

    private function _seedArrival(string $vehicleId): void
    {
        try {
            // Fence half delegated — same snapshot/restore discipline, one place.
            $this->_ensureCampusFence();
            $this->fs->setEntity('vehicleLivePositions', $vehicleId, [
                'schoolId' => $this->sid, 'vehicleId' => $vehicleId,
                'lat' => self::FIX_LAT, 'lng' => self::FIX_LNG, 'accuracy_m' => 12.0,
                'recorded_at' => date('c'),
                'recorded_at_ms' => (int) round(microtime(true) * 1000),
                'source' => 'probe_fixture',
            ], true);
            $this->fixtures[] = ['vehicleLivePositions', $vehicleId];
        } catch (\Throwable $e) {
            echo '  fixture WARN: ' . $e->getMessage() . "\n";
        }
    }

    private function _restorePolicy(): void
    {
        if (!$this->policySeeded) { return; }
        try {
            $this->fs->update('schools', $this->sid,
                ['attendancePolicy' => $this->priorPolicy ?? []]);
        } catch (\Throwable $_) {}
    }
}
