<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_mtonboard — multi-tenant isolation of the onboard metric (B1).
 *
 * Item-2 changed getLiveOperationsSnapshot to bucket onboard by the BARE
 * canonical trip id. Because the global counter can mint the SAME bare id
 * (e.g. TRP000015) in two schools, this proves the bare id is only an in-memory
 * join key between two ALREADY school-scoped datasets — never an unscoped query:
 *
 *   Seed School A: 1 InProgress trip (bare id X) + 3 boarded.
 *   Seed School B: 1 InProgress trip with the SAME bare id X + 5 boarded.
 *   G1 A's snapshot onboard delta == 3 (NOT 8 — never sees B's events)
 *   G2 B's snapshot onboard delta == 5 (NOT 8 — never sees A's events)
 *   G3 the two trip docs are physically distinct ({A}_X vs {B}_X)
 *
 * Self-teardown. CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_mtonboard run
 */
class Transport_probe_mtonboard extends CI_Controller
{
    private array $fixtures = [];   // [schoolId, collection, entityId]

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) { show_error('CLI-only.', 403); }
        $this->load->library('firebase',                    null, 'firebase');
        $this->load->library('firestore_service',           null, 'fs');
        $this->load->library('numbering_service',           null, 'numbering');
        $this->load->library('transport_service',           null, 'transport');
        $this->load->library('transport_analytics_service', null, 'analytics');
    }

    public function run(): void
    {
        $sA   = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sA === '' || $sess === '') { fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n"); exit(1); }
        $ts    = (int) (microtime(true) * 1000);
        $sB    = "SCH_PROBE_MT_B_{$ts}";               // second (fake) tenant
        $tid   = "MT_TRP_{$ts}";                        // SAME bare trip id in BOTH schools
        $today = date('Y-m-d');
        $now   = date('c');

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        // Init the whole stack for a given tenant (as a real request would).
        $useTenant = function (string $sid) use ($sess) {
            $this->fs->init($sid, $sess);
            $this->numbering->init($this->fs, $sid, $sess);
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'probe_mt');
            $this->analytics->init($this->fs, $this->transport, $sid, $sess);
        };
        $onboard = fn(): int => (int) (((array) $this->analytics->getLiveOperationsSnapshot())['students_currently_onboard'] ?? 0);
        $seedTrip = function (string $sid) use ($tid, $today, $now) {
            $this->fs->setEntity('tripLogs', $tid, [
                'id'=>$tid,'schoolId'=>$sid,'session'=>getenv('SESSION_YEAR'),'status'=>'InProgress',
                'date'=>$today,'trip_date'=>$today,'trip_direction'=>'Pickup','created_at'=>$now,
            ], false);
            $this->fixtures[] = [$sid, 'tripLogs', $tid];
        };
        $seedBoarded = function (string $sid, int $n) use ($tid, $today, $now) {
            for ($i = 0; $i < $n; $i++) {
                $did = "MT_ATT_{$sid}_{$i}";
                $this->fs->setEntity('transportAttendance', $did, [
                    'id'=>$did,'schoolId'=>$sid,'tripId'=>$tid,   // BARE id, same string in both schools
                    'studentId'=>"MT_STU_{$sid}_{$i}",'event_type'=>'boarded_pickup','trip_date'=>$today,'event_at'=>$now,
                ], false);
                $this->fixtures[] = [$sid, 'transportAttendance', $did];
            }
        };

        echo "==================================================\n";
        echo " B1 multi-tenant onboard isolation probe\n";
        echo " schoolA={$sA}  schoolB={$sB}  shared bare tripId={$tid}\n";
        echo "==================================================\n";

        try {
            // Baselines (A may have live data; B is empty).
            $useTenant($sA); $baseA = $onboard();
            $useTenant($sB); $baseB = $onboard();

            // Seed A: trip X + 3 boarded.
            $useTenant($sA); $seedTrip($sA); $seedBoarded($sA, 3);
            // Seed B: trip with the SAME bare id X + 5 boarded.
            $useTenant($sB); $seedTrip($sB); $seedBoarded($sB, 5);

            echo "\n[G1] School A snapshot sees only A's 3 (not 8)\n";
            $useTenant($sA); $dA = $onboard() - $baseA;
            ($dA === 3) ? $pass("A onboard delta = 3 (isolated from B's 5 despite identical bare tripId)")
                        : $fail("A onboard delta = {$dA} (expected 3; 8 would prove cross-tenant bleed)");

            echo "\n[G2] School B snapshot sees only B's 5 (not 8)\n";
            $useTenant($sB); $dB = $onboard() - $baseB;
            ($dB === 5) ? $pass("B onboard delta = 5 (isolated from A's 3)")
                        : $fail("B onboard delta = {$dB} (expected 5)");

            echo "\n[G3] the two trip docs are physically distinct ({A}_X vs {B}_X)\n";
            $this->fs->init($sA, $sess); $docA = $this->fs->getEntity('tripLogs', $tid);
            $this->fs->init($sB, $sess); $docB = $this->fs->getEntity('tripLogs', $tid);
            (is_array($docA) && is_array($docB)
                && (string)($docA['schoolId'] ?? '') === $sA
                && (string)($docB['schoolId'] ?? '') === $sB)
                ? $pass("same bare id resolves to two distinct school-scoped docs (schoolId A vs B)")
                : $fail("doc resolution wrong: A.schoolId=" . ($docA['schoolId'] ?? '?') . " B.schoolId=" . ($docB['schoolId'] ?? '?'));
        } catch (\Throwable $e) {
            $fail("unexpected exception: " . $e->getMessage());
        } finally {
            echo "\n[TEARDOWN]\n";
            foreach ($this->fixtures as [$sid, $col, $id]) {
                try { $this->fs->init($sid, $sess); $this->fs->removeEntity($col, $id); } catch (\Throwable $e) {}
            }
            $this->fs->init($sA, $sess);
            echo "  cleaned " . count($this->fixtures) . " docs across both tenants\n";
        }

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }
}
