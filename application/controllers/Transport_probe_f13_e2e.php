<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_f13_e2e — F13.1 end-to-end lifecycle + GPS validation.
 *
 * Drives the REAL canonical lifecycle (`Transport_service::startTrip` /
 * `completeTrip` / `cancelTrip`) against ephemeral fixtures and asserts the
 * GPS session behaviour at every step:
 *
 *   Start Trip          → kill switch armed, hot doc created, viewers resolved
 *   Live position       → accepted; stale / out-of-order / mock rejected
 *   Complete Trip       → kill switch disarmed, hot doc gone
 *   Cancel Trip         → same teardown on the abnormal path
 *
 * This is the same code path `transport_driver_api` invokes for the driver
 * app, so validating here validates the driver flow's server half exactly.
 *
 * Fixture discipline: every document created is registered for teardown and
 * removed in a `finally`, including the GPS session rows — the F12.1 probe
 * that left 114 orphaned compliance rows is the reason this is explicit.
 *
 * CLI-only:
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 php index.php transport_probe_f13_e2e run
 */
class Transport_probe_f13_e2e extends CI_Controller
{
    private int $pass = 0;
    private int $fail = 0;
    private array $failures = [];
    /** @var array<int,array{0:string,1:string}> */
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
    }

    private function ok(string $id, string $w): void { $this->pass++; printf("  PASS  %-5s %s\n", $id, $w); }
    private function bad(string $id, string $w, string $d): void
    {
        $this->fail++; $this->failures[] = "{$id} — {$w}: {$d}";
        printf("  FAIL  %-5s %s\n          → %s\n", $id, $w, $d);
    }
    private function eq(string $id, $exp, $act, string $w): void
    {
        $exp === $act ? $this->ok($id, $w)
            : $this->bad($id, $w, 'expected ' . var_export($exp, true) . ', got ' . var_export($act, true));
    }
    private function truthy(string $id, bool $c, string $w, string $d = ''): void
    {
        $c ? $this->ok($id, $w) : $this->bad($id, $w, $d !== '' ? $d : 'condition false');
    }

    public function run(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') { fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n"); exit(1); }

        echo "==================================================\n";
        echo " F13.1 E2E — canonical lifecycle + GPS session\n";
        echo " tenant={$sid} session={$sess}\n";
        echo "==================================================\n";

        $this->load->library('firestore_service', null, 'fs');
        $this->fs->init($sid, $sess);
        $this->load->library('numbering_service', null, 'numbering');
        $this->numbering->init($this->fs, $sid, $sess);
        $this->load->library('transport_service', null, 'transport');
        $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'PROBE_F13_E2E');
        $this->load->library('transport_gps_service', null, 'gps');
        $this->gps->init($this->fs, $sid, 'PROBE_F13_E2E');

        $this->fixSchoolId = $sid;

        $tripId = ''; $tripId2 = ''; $vehicleId = '';
        try {
            // UNIT 2B — createTrip() refuses a Pickup trip when no valid campus
            // geofence is configured. Step A creates one, so the fence must
            // exist first or the suite depends on a fence left behind by an
            // earlier run. Seeded only when absent, snapshotted, and restored
            // verbatim in the finally below. The guard is not weakened.
            $this->_ensureCampusFence();
            [$vehicleId, $routeId] = $this->provision($sid, $sess);

            // ── A. Create → Ready ────────────────────────────────────────
            echo "\n[A] createTrip → Ready\n";
            $r = $this->transport->createTrip([
                'vehicle_id' => $vehicleId, 'route_id' => $routeId,
                'trip_date' => date('Y-m-d'), 'trip_direction' => 'Pickup',
            ]);
            $tripId = (string) ($r['tripId'] ?? '');
            $this->fixtures[] = ['tripLogs', $tripId];
            $this->truthy('A1', $tripId !== '', "trip created ({$tripId})");

            $trip = $this->fs->getEntity('tripLogs', $tripId);
            $this->eq('A2', false, $trip['live_tracking_enabled'] ?? null, 'tracking is OFF while merely Ready');
            $this->truthy('A3', trim((string) ($trip['gps_session_id'] ?? '')) !== '',
                'gps_session_id minted at create (' . ($trip['gps_session_id'] ?? '') . ')');
            $this->truthy('A4', $this->gps->getLivePosition($vehicleId) === null,
                'no hot doc yet — a Ready trip is not tracked');

            // ── B. Start → armed + session open ──────────────────────────
            echo "\n[B] startTrip → kill switch ARMED + session open\n";
            $this->transport->startTrip($tripId);
            $trip = $this->fs->getEntity('tripLogs', $tripId);
            $this->eq('B1', 'InProgress', $trip['status'] ?? null, 'trip is InProgress');
            $this->eq('B2', true, $trip['live_tracking_enabled'] ?? null, 'kill switch ARMED by the canonical lifecycle');
            $this->truthy('B3', trim((string) ($trip['gps_start_at'] ?? '')) !== '', 'gps_start_at stamped');

            $hot = $this->gps->getLivePosition($vehicleId);
            $this->truthy('B4', is_array($hot), 'hot doc created SERVER-SIDE (D-1)');
            $this->fixtures[] = [Transport_gps_service::COL_LIVE_POSITIONS, $this->gps->livePositionId($vehicleId)];
            $this->eq('B5', $tripId, $hot['tripId'] ?? null, 'hot doc bound to the trip');
            $this->eq('B6', true, $hot['live_tracking_enabled'] ?? null, 'hot doc carries the armed switch');
            $this->eq('B7', 0, $hot['recorded_at_ms'] ?? null, 'ordering key seeded at 0');
            $this->truthy('B8', array_key_exists('authorized_viewer_uids', $hot),
                'viewer allowlist present (' . count((array) ($hot['authorized_viewer_uids'] ?? [])) . ' uid(s))');
            $this->truthy('B9', array_key_exists('lat', $hot) && $hot['lat'] === null,
                'no position asserted until a real fix');

            // ── C. Live position updates ─────────────────────────────────
            echo "\n[C] live position updates + ordering guard\n";
            $now   = time();
            $nowMs = $now * 1000;
            $fix = function (int $ms, array $over = []) {
                return $over + [
                    'lat' => 12.9716, 'lng' => 77.5946, 'speed_kmph' => 32.0,
                    'accuracy_m' => 12.0, 'is_mock' => false, 'recorded_at_ms' => $ms,
                ];
            };

            $v = $this->gps->acceptFix($hot, $fix($nowMs), $now);
            $this->eq('C1', true, $v['ok'], 'a clean first fix is ACCEPTED');

            // Apply it the way the driver app would (field-level update).
            $this->fs->setEntity(
                Transport_gps_service::COL_LIVE_POSITIONS,
                $this->gps->livePositionId($vehicleId),
                ['lat' => 12.9716, 'lng' => 77.5946, 'speed_kmph' => 32.0, 'accuracy_m' => 12.0,
                 'recorded_at' => date('c', $now), 'recorded_at_ms' => $nowMs, 'is_mock' => false],
                /* merge */ true
            );
            $hot2 = $this->gps->getLivePosition($vehicleId);
            $this->eq('C2', $nowMs, $hot2['recorded_at_ms'] ?? null, 'position persisted to the hot doc');
            $this->eq('C3', 12.9716, (float) ($hot2['lat'] ?? 0), 'latitude round-trips');

            $this->eq('C4', 'out_of_order',
                $this->gps->acceptFix($hot2, $fix($nowMs - 30000), $now)['reason'],
                'an OLDER fix is REJECTED against the stored position');
            $this->eq('C5', true,
                $this->gps->acceptFix($hot2, $fix($nowMs + 20000), $now + 20)['ok'],
                'a NEWER fix is accepted');
            $this->eq('C6', 'mock_location',
                $this->gps->acceptFix($hot2, $fix($nowMs + 20000, ['is_mock' => true]), $now + 20)['reason'],
                'a MOCK fix is rejected even though it is newer');
            $this->eq('C7', 'poor_accuracy',
                $this->gps->acceptFix($hot2, $fix($nowMs + 20000, ['accuracy_m' => 400.0]), $now + 20)['reason'],
                'a low-quality fix is rejected');
            $this->eq('C8', 'fix_too_old',
                $this->gps->acceptFix(null, $fix($nowMs - 900000), $now)['reason'],
                'a 15-minute-old queued fix never becomes "current"');

            // ── D. Complete → disarmed + torn down ───────────────────────
            echo "\n[D] completeTrip → kill switch DISARMED + session torn down\n";
            foreach ((array) ($trip['checkpoints'] ?? []) as $cp) {
                $this->transport->markCheckpointReached($tripId, (string) $cp['stop_id']);
            }
            $this->transport->completeTrip($tripId, ['end_odometer' => 120, 'distance_km' => 20]);

            $trip = $this->fs->getEntity('tripLogs', $tripId);
            $this->eq('D1', 'Completed', $trip['status'] ?? null, 'trip is Completed');
            $this->eq('D2', false, $trip['live_tracking_enabled'] ?? null,
                'kill switch DISARMED — Firestore now refuses every further driver write');
            $this->truthy('D3', trim((string) ($trip['gps_end_at'] ?? '')) !== '', 'gps_end_at stamped');
            $this->truthy('D4', $this->gps->getLivePosition($vehicleId) === null,
                'hot doc DELETED — no parent can see a position once the children are off the bus');

            // ── E. Cancel path tears down too ────────────────────────────
            echo "\n[E] cancelTrip → same teardown on the abnormal path\n";
            $r2 = $this->transport->createTrip([
                'vehicle_id' => $vehicleId, 'route_id' => $routeId,
                'trip_date' => date('Y-m-d'), 'trip_direction' => 'Drop',
            ]);
            $tripId2 = (string) ($r2['tripId'] ?? '');
            $this->fixtures[] = ['tripLogs', $tripId2];
            $this->transport->startTrip($tripId2);
            $this->truthy('E1', $this->gps->getLivePosition($vehicleId) !== null, 'session opened for the second trip');

            $this->transport->cancelTrip($tripId2, 'F13 e2e probe teardown');
            $t2 = $this->fs->getEntity('tripLogs', $tripId2);
            $this->eq('E2', 'Cancelled', $t2['status'] ?? null, 'trip is Cancelled');
            $this->eq('E3', false, $t2['live_tracking_enabled'] ?? null, 'kill switch disarmed on cancel');
            $this->truthy('E4', $this->gps->getLivePosition($vehicleId) === null, 'hot doc removed on cancel');
        } catch (\Throwable $e) {
            $this->bad('EXC', 'unhandled exception', $e->getMessage());
        } finally {
            echo "\n[TEARDOWN]\n";
            $this->_restoreCampusFence();
            // GPS rows first — the class of residue that produced 114 orphans.
            if ($vehicleId !== '') { try { $this->gps->closeSession($vehicleId); } catch (\Throwable $_) {} }
            $removed = 0;
            foreach (array_reverse($this->fixtures) as [$col, $docId]) {
                if ($docId === '') { continue; }
                try { $this->fs->removeEntity($col, $docId); $removed++; } catch (\Throwable $_) {}
            }
            echo "  removed {$removed} fixture doc(s)\n";
            $left = $vehicleId !== '' ? $this->gps->getLivePosition($vehicleId) : null;
            echo '  live-position residue: ' . ($left === null ? "none\n" : "PRESENT (manual cleanup needed)\n");
        }

        echo "\n==================================================\n";
        echo " RESULT: pass={$this->pass} fail={$this->fail}\n";
        if ($this->failures !== []) {
            echo "\n DEFECTS:\n";
            foreach ($this->failures as $f) { echo "   • {$f}\n"; }
        }
        echo "==================================================\n";
        exit($this->fail === 0 ? 0 : 2);
    }

    /** Ephemeral fixtures, mirroring transport_probe_f7's pattern. */
    private function provision(string $sid, string $sess): array
    {
        $now = date('c');
        $t   = (int) (microtime(true) * 1000);
        $vehicleId = "PROBE_F13E_VEH_{$t}";
        $routeId   = "PROBE_F13E_RT_{$t}";
        $stopId    = "PROBE_F13E_STP_{$t}";
        $staffId   = "PROBE_F13E_STAFF_{$t}";

        $this->fs->setEntity('staff', $staffId, [
            'id' => $staffId, 'schoolId' => $sid, 'name' => 'F13 E2E Driver', 'status' => 'Active',
            'flags' => ['can_drive_transport' => true],
            'driver_meta' => ['operational_status' => 'Available',
                              'licence_expires' => '2099-12-31', 'medical_expires' => '2099-12-31'],
        ], false);
        $this->fixtures[] = ['staff', $staffId];

        $this->fs->setEntity('vehicles', $vehicleId, [
            'id' => $vehicleId, 'schoolId' => $sid, 'number' => 'F13E-PROBE', 'status' => 'Active',
            'staff_id' => $staffId, 'route_id' => $routeId, 'created_at' => $now, 'updated_at' => $now,
        ], false);
        $this->fixtures[] = ['vehicles', $vehicleId];

        $this->fs->setEntity('routes', $routeId, [
            'id' => $routeId, 'schoolId' => $sid, 'name' => 'F13 E2E Route',
            'status' => 'Active', 'direction' => 'Both', 'created_at' => $now, 'updated_at' => $now,
        ], false);
        $this->fixtures[] = ['routes', $routeId];

        $this->fs->setEntity('transportStops', $stopId, [
            'stopId' => $stopId, 'schoolId' => $sid, 'route_id' => $routeId,
            'name' => 'F13 E2E Stop', 'order' => 1,
            'pickup_time' => '07:00', 'drop_time' => '15:30',
            'latitude' => 12.9716, 'longitude' => 77.5946,
            'status' => 'Active', 'created_at' => $now, 'updated_at' => $now,
        ], false);
        $this->fixtures[] = ['transportStops', $stopId];

        return [$vehicleId, $routeId];
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
                'id' => 'PROBE_F13E2E_CAMPUS', 'name' => 'Probe Campus',
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
