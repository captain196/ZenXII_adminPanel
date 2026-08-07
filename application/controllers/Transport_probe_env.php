<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_env — nested-envelope systemic-cleanup probe (representative).
 *
 * Validates the rollup of the schoolWhere {id,data} unwrap fixes via the
 * rebuildCapacityCache BULK path — which read $r['vehicle_id'] flat off the
 * nested route row and therefore wrote max_capacity=0 for every route. After
 * the unwrap fix the bulk rebuild reads the vehicle correctly.
 *
 *   seed vehicle(capacity=40) + route(vehicle_id) + 3 active studentRoutes
 *   G1 rebuildCapacityCache('') (BULK) → this route's cache max_capacity=40
 *      (was 0 pre-fix) and current_count=3 (recomputed from source)
 *
 * Note: bulk rebuild recomputes ALL routes' capacity caches from source — a
 * legitimate, idempotent operation. Self-teardown of probe fixtures. CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_env run
 */
class Transport_probe_env extends CI_Controller
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
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'transport_probe_env');
        } catch (\Throwable $e) { fwrite(STDERR, "ERROR: init — " . $e->getMessage() . "\n"); exit(1); }

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        $ts  = (int) (microtime(true) * 1000);
        $vid = "PROBE_ENV_VEH_{$ts}";
        $rid = "PROBE_ENV_RT_{$ts}";
        $now = date('c');

        echo "==================================================\n";
        echo " ENV nested-envelope cleanup probe (bulk capacity)\n";
        echo " tenant={$sid}\n";
        echo "==================================================\n";

        try {
            $this->fs->setEntity('vehicles', $vid, ['id'=>$vid,'schoolId'=>$sid,'number'=>'ENV','status'=>'Active','capacity'=>40,'created_at'=>$now], false);
            $this->fixtures[] = ['vehicles', $vid];
            $this->fs->setEntity('routes', $rid, ['id'=>$rid,'schoolId'=>$sid,'name'=>'ENV Route','status'=>'Active','vehicle_id'=>$vid,'created_at'=>$now], false);
            $this->fixtures[] = ['routes', $rid];
            foreach (['A','B','C'] as $s) {
                $srid = "PROBE_ENV_SR{$s}_{$ts}";
                $this->fs->setEntity('studentRoutes', $srid, ['schoolId'=>$sid,'studentId'=>"PROBE_ENV_STU{$s}_{$ts}",'route_id'=>$rid,'session'=>$sess,'status'=>'Active','created_at'=>$now], false);
                $this->fixtures[] = ['studentRoutes', $srid];
            }
            $this->fixtures[] = ['busCapacityCache', "CAPCACHE_{$rid}"];

            echo "\n[G1] rebuildCapacityCache('') BULK → route cache max_capacity=40, current_count=3\n";
            $this->transport->rebuildCapacityCache('');   // bulk path (schoolWhere routes → nested rows)
            $cache = (array) ($this->fs->getEntity('busCapacityCache', "CAPCACHE_{$rid}") ?: []);
            $mx = (int) ($cache['max_capacity'] ?? -1); $cc = (int) ($cache['current_count'] ?? -1);
            ($mx === 40 && $cc === 3)
                ? $pass("bulk rebuild read vehicle via unwrap: max_capacity={$mx}, current_count={$cc} (was max=0 pre-fix)")
                : $fail("max_capacity={$mx} current_count={$cc} (expected 40 / 3)");
        } catch (\Throwable $e) {
            $fail("exception: " . $e->getMessage());
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
