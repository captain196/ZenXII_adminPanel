<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_geofence_fixture — Unit 2B-5D.
 *
 * Runtime-exercises the ONE path never yet executed: a tenant with NO campus
 * geofence. It temporarily removes the fence (explicitly authorised, guarded
 * tenant only), proves the production guard blocks Pickup creation, proves the
 * canonical fixture then satisfies the prerequisite, and proves the COMPLETE
 * original attendancePolicy is restored verbatim.
 *
 * SAFETY DESIGN
 *   • SCH_202512 only — hard refusal otherwise.
 *   • The complete attendancePolicy is snapshotted AND fingerprinted before any
 *     write, and restored in a finally block so an abort still restores.
 *   • Only `attendancePolicy` is ever written. No student, route, stop, vehicle,
 *     staff, Accounting, SRNO or RBAC record is touched.
 *   • The production guard is NOT weakened, bypassed, flagged or mocked — the
 *     fixture satisfies it honestly, which is the whole point.
 *
 *   SCHOOL_ID=SCH_202512 SESSION_YEAR=2026-27 php index.php transport_probe_geofence_fixture run
 */
class Transport_probe_geofence_fixture extends CI_Controller
{
    private const ALLOWED_TENANT = 'SCH_202512';
    private const MARK = 'PROBE_GFFIX';
    private const LAT = 12.9716;
    private const LNG = 77.5946;

    public $fs = null; public $numbering = null; public $transport = null; public $qratt = null;
    private int $pass = 0; private int $fail = 0; private array $failures = [];
    private array $fixtures = [];
    private string $tag = ''; private string $sid = ''; private string $sess = '';

    /** Complete original policy + fingerprint, captured before ANY write. */
    private $originalPolicy = null;
    private string $originalPrint = '';
    private bool $policyMutated = false;

    public function __construct() { parent::__construct(); if (!is_cli()) { show_404(); } }

    private function ok(string $i, string $w): void { $this->pass++; printf("  PASS  %-7s %s\n", $i, $w); }
    private function bad(string $i, string $w, string $d): void
    { $this->fail++; $this->failures[] = "{$i} — {$w}: {$d}"; printf("  FAIL  %-7s %s\n          → %s\n", $i, $w, $d); }
    private function eq(string $i, string $w, $e, $g): void
    { $e === $g ? $this->ok($i, $w) : $this->bad($i, $w, 'expected ' . var_export($e, true) . ', got ' . var_export($g, true)); }
    private function truthy(string $i, string $w, $v, string $d = ''): void
    { $v ? $this->ok($i, $w) : $this->bad($i, $w, $d !== '' ? $d : 'falsy'); }

    private function flat($r): array
    {
        if (!is_array($r)) { return []; }
        return (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
    }

    /** Read the COMPLETE attendancePolicy as stored. */
    private function policy(): array
    {
        $s = $this->flat($this->fs->get('schools', $this->sid));
        return (array) ($s['attendancePolicy'] ?? []);
    }

    /** Order-insensitive deterministic fingerprint of the policy. */
    private function fingerprint(array $p): string
    {
        $norm = static function (&$a) use (&$norm) {
            if (!is_array($a)) { return; }
            ksort($a);
            foreach ($a as &$v) { if (is_array($v)) { $norm($v); } }
        };
        $norm($p);
        return md5((string) json_encode($p));
    }

    public function run(): void
    {
        $this->sid  = (string) (getenv('SCHOOL_ID') ?: '');
        $this->sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($this->sid === '' || $this->sess === '') { fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n"); exit(1); }
        if ($this->sid !== self::ALLOWED_TENANT) {
            fwrite(STDERR, "REFUSED: mutates attendancePolicy; runs only on " . self::ALLOWED_TENANT . ".\n"); exit(1);
        }
        $this->tag = (string) (int) (microtime(true) * 1000);

        echo "==================================================\n";
        echo " Unit 2B-5D — no-fence seed/restore proof  tenant={$this->sid}\n";
        echo "==================================================\n";

        $this->load->library('firestore_service', null, 'fs');   $this->fs->init($this->sid, $this->sess);
        $this->load->library('numbering_service', null, 'numbering'); $this->numbering->init($this->fs, $this->sid, $this->sess);
        $this->load->library('transport_service', null, 'transport');
        $this->transport->init($this->fs, $this->numbering, $this->sid, $this->sess, self::MARK);
        $this->load->library('transport_qr_attendance_service', null, 'qratt');
        $this->qratt->init($this->fs, $this->transport, $this->sid, $this->sess, self::MARK);

        try { $this->gates(); }
        catch (\Throwable $e) { $this->bad('EXC', 'unhandled', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()); }
        finally { $this->teardown(); }

        echo "\n==================================================\n";
        echo " RESULT: pass={$this->pass} fail={$this->fail}\n";
        if ($this->failures !== []) { echo "\n DEFECTS:\n"; foreach ($this->failures as $f) { echo "   • {$f}\n"; } }
        echo "==================================================\n";
        if ($this->fail > 0) { exit(1); }
    }

    private function gates(): void
    {
        // ── A–D: snapshot + fingerprint BEFORE any write ─────────────────
        $this->originalPolicy = $this->policy();
        $this->originalPrint  = $this->fingerprint($this->originalPolicy);
        $hadFence = (array) (((array) ($this->originalPolicy['gps'] ?? []))['geofences'] ?? []) !== [];
        echo "\n  BEFORE fingerprint = {$this->originalPrint}\n";
        echo "  BEFORE has fence   = " . ($hadFence ? 'YES' : 'no') . "\n";
        $this->truthy('GF-A', 'complete original attendancePolicy snapshotted', $this->originalPolicy !== null);
        $this->truthy('GF-D', 'tenant currently HAS a campus fence (precondition for this test)', $hadFence,
            'tenant has no fence — CASE B cannot be distinguished from the status quo');
        $this->eq('GF-D2', 'campusGeofenceConfigured() agrees a fence exists', true,
            $this->qratt->campusGeofenceConfigured());

        // Fixtures used by both cases.
        [$veh, $route, $stop] = $this->seedFleet();

        // ── CASE A — fence present: creation succeeds ────────────────────
        echo "\n[CASE A] tenant HAS a valid fence\n";
        $tripA = '';
        try {
            $r = $this->transport->createTrip(['vehicle_id' => $veh, 'route_id' => $route,
                'trip_date' => date('Y-m-d'), 'trip_direction' => 'Pickup']);
            $tripA = (string) ($r['tripId'] ?? '');
            if ($tripA !== '') { $this->fixtures[] = ['tripLogs', $tripA]; }
            $this->truthy('GF-A1', 'CASE A: Pickup creation ALLOWED with a valid fence', $tripA !== '');
        } catch (\Throwable $e) { $this->bad('GF-A1', 'CASE A: Pickup creation allowed', $e->getMessage()); }

        // ── E–F: temporarily remove ONLY the geofences ───────────────────
        echo "\n[CASE B] tenant has NO valid fence\n";
        $stripped = $this->originalPolicy;
        $gps = (array) ($stripped['gps'] ?? []);
        $gps['geofences'] = [];
        $stripped['gps'] = $gps;
        $this->fs->update('schools', $this->sid, ['attendancePolicy' => $stripped]);
        $this->policyMutated = true;
        $this->eq('GF-E', 'geofence removed — campusGeofenceConfigured() now FALSE', false,
            $this->qratt->campusGeofenceConfigured());

        // ── PRODUCTION GUARD, runtime-exercised for the first time ───────
        $blocked = false; $msg = '';
        try {
            $this->transport->createTrip(['vehicle_id' => $veh, 'route_id' => $route,
                'trip_date' => date('Y-m-d'), 'trip_direction' => 'Pickup']);
        } catch (\Throwable $e) { $blocked = true; $msg = $e->getMessage(); }
        $this->truthy('GF-B1', 'PRODUCTION GUARD: Pickup creation BLOCKED with no fence', $blocked,
            'a Pickup trip was created without a campus geofence');
        $this->truthy('GF-B2', 'block message names the campus geofence',
            stripos($msg, 'geofence') !== false, "message was: {$msg}");

        // Drop must remain unaffected by the prerequisite.
        $tripD = '';
        try {
            $r = $this->transport->createTrip(['vehicle_id' => $veh, 'route_id' => $route,
                'trip_date' => date('Y-m-d'), 'trip_direction' => 'Drop']);
            $tripD = (string) ($r['tripId'] ?? '');
            if ($tripD !== '') { $this->fixtures[] = ['tripLogs', $tripD]; }
        } catch (\Throwable $e) { $this->bad('GF-B3', 'Drop unaffected by the prerequisite', $e->getMessage()); }
        $this->truthy('GF-B3', 'Drop trip still ALLOWED with no fence (unaffected)', $tripD !== '');

        // ── G–J: the canonical fixture seeds, then creation succeeds ─────
        $this->ensureCampusFence();
        $this->eq('GF-G', 'fixture seeded a fence — campusGeofenceConfigured() now TRUE', true,
            $this->qratt->campusGeofenceConfigured());
        $tripB = '';
        try {
            $r = $this->transport->createTrip(['vehicle_id' => $veh, 'route_id' => $route,
                'trip_date' => date('Y-m-d'), 'trip_direction' => 'Pickup']);
            $tripB = (string) ($r['tripId'] ?? '');
            if ($tripB !== '') { $this->fixtures[] = ['tripLogs', $tripB]; }
        } catch (\Throwable $e) { $this->bad('GF-J', 'CASE B: Pickup allowed after the fixture seeds', $e->getMessage()); }
        $this->truthy('GF-J', 'CASE B: Pickup creation ALLOWED once the fixture seeded the fence', $tripB !== '');
    }

    /** The canonical fixture pattern — seeds ONLY when no valid fence exists. */
    private function ensureCampusFence(): void
    {
        $pol = $this->policy();
        if ((array) (((array) ($pol['gps'] ?? []))['geofences'] ?? []) !== []) {
            echo "  fixture: fence already present — preserved, not overwritten\n"; return;
        }
        $gps = (array) ($pol['gps'] ?? []);
        $gps['geofences'] = [[
            'id' => self::MARK . '_CAMPUS', 'name' => 'GF Fixture Campus',
            'centerLat' => self::LAT, 'centerLng' => self::LNG, 'radius' => 300,
        ]];
        if (!isset($gps['maxAccuracyMeters'])) { $gps['maxAccuracyMeters'] = 100; }
        $pol['gps'] = $gps;
        $this->fs->update('schools', $this->sid, ['attendancePolicy' => $pol]);
        $this->policyMutated = true;
        echo "  fixture: temporary campus fence seeded\n";
    }

    /** @return array{0:string,1:string,2:string} */
    private function seedFleet(): array
    {
        $now = date('c');
        $staff = self::MARK . '_STA_' . $this->tag;
        $veh   = self::MARK . '_VEH_' . $this->tag;
        $route = self::MARK . '_RT_'  . $this->tag;
        $stop  = self::MARK . '_STP_' . $this->tag;

        $this->fs->setEntity('staff', $staff, ['id' => $staff, 'schoolId' => $this->sid,
            'name' => 'GF Fixture Driver', 'status' => 'Active', 'phone' => '9000000000',
            'flags' => ['can_drive_transport' => true],
            'driver_meta' => ['operational_status' => 'Available',
                              'licence_expires' => '2099-12-31', 'medical_expires' => '2099-12-31'],
        ], false); $this->fixtures[] = ['staff', $staff];

        $this->fs->setEntity('vehicles', $veh, ['id' => $veh, 'schoolId' => $this->sid,
            'number' => 'GF-' . $this->tag, 'status' => 'Active', 'staff_id' => $staff,
            'route_id' => $route, 'created_at' => $now, 'updated_at' => $now], false);
        $this->fixtures[] = ['vehicles', $veh];

        $this->fs->setEntity('routes', $route, ['id' => $route, 'schoolId' => $this->sid,
            'name' => 'GF Fixture Route', 'status' => 'Active', 'direction' => 'Both',
            'created_at' => $now, 'updated_at' => $now], false);
        $this->fixtures[] = ['routes', $route];

        $this->fs->setEntity('transportStops', $stop, ['stopId' => $stop, 'schoolId' => $this->sid,
            'route_id' => $route, 'name' => 'GF Fixture Stop', 'order' => 1,
            'pickup_time' => '06:30', 'drop_time' => '15:30',
            'latitude' => self::LAT + 0.05, 'longitude' => self::LNG + 0.05,
            'status' => 'Active', 'created_at' => $now, 'updated_at' => $now], false);
        $this->fixtures[] = ['transportStops', $stop];

        return [$veh, $route, $stop];
    }

    /** K–N: remove probe records, restore the COMPLETE policy, prove equality. */
    private function teardown(): void
    {
        echo "\n[TEARDOWN]\n";
        $removed = 0; $failed = 0;
        foreach (array_reverse($this->fixtures) as [$col, $id]) {
            if ($id === '') { continue; }
            try { $this->fs->removeEntity($col, $id); $removed++; }
            catch (\Throwable $e) { $failed++; echo "  FAILED remove {$col}:{$id} — " . $e->getMessage() . "\n"; }
        }
        echo "  probe records : " . count($this->fixtures) . "  removed: {$removed}"
           . ($failed ? " (failed {$failed})" : '') . "\n";

        if ($this->policyMutated && is_array($this->originalPolicy)) {
            try {
                $this->fs->update('schools', $this->sid, ['attendancePolicy' => $this->originalPolicy]);
                $after = $this->fingerprint($this->policy());
                echo "  AFTER fingerprint = {$after}\n";
                if ($after === $this->originalPrint) {
                    $this->ok('GF-N', 'RESTORED: attendancePolicy fingerprint identical to before');
                } else {
                    $this->bad('GF-N', 'attendancePolicy restored verbatim',
                        "fingerprint drift — before={$this->originalPrint} after={$after}");
                }
            } catch (\Throwable $e) {
                $this->bad('GF-N', 'attendancePolicy restored verbatim', 'RESTORE FAILED: ' . $e->getMessage());
            }
        } else {
            echo "  attendancePolicy: never mutated — nothing to restore\n";
        }
    }
}
