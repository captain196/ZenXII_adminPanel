<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_capnorm — RC-3 CAPCACHE normalization probe.
 *
 * Proves the busCapacityCache convention change is a self-healing, no-migration
 * transition that cannot affect business data:
 *
 *   setup  seed a route (cap 5) + 2 active studentRoutes (true occupancy = 2),
 *          and a STALE legacy doc at the old double-prefixed id (count = 99)
 *   G1     rebuildCapacityCache() writes the CANONICAL {schoolId}_CAPCACHE_{rid}
 *          doc, read by a BARE getEntity, with count recomputed from the
 *          authoritative studentRoutes source (2, not the stale 99) + cap 5
 *   G2     the stale legacy double-prefixed doc is PURGED (no orphan → no
 *          CH-TRN-F3-5 drift, no cache inconsistency)
 *
 * Self-teardown. CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_capnorm run
 */
class Transport_probe_capnorm extends CI_Controller
{
    private const COL = 'busCapacityCache';
    private array $fixtures = [];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Transport_probe_capnorm is CLI-only.', 403);
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
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'transport_probe_capnorm');
        } catch (\Throwable $e) { fwrite(STDERR, "ERROR: init failed — " . $e->getMessage() . "\n"); exit(1); }

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        $ts  = (int) (microtime(true) * 1000);
        $rid = "PROBE_CAP_RT_{$ts}";
        $vid = "PROBE_CAP_VEH_{$ts}";
        $now = date('c');

        echo "==================================================\n";
        echo " RC-3 CAPCACHE Normalization Probe\n";
        echo " tenant={$sid}\n";
        echo "==================================================\n";

        try {
            echo "\n[SETUP] route (cap 5) + 2 active studentRoutes + STALE legacy cache (99)\n";
            $this->fs->setEntity('vehicles', $vid, ['id'=>$vid,'schoolId'=>$sid,'capacity'=>5,'status'=>'Active','created_at'=>$now], false);
            $this->fixtures[] = ['vehicles', $vid];
            $this->fs->setEntity('routes', $rid, ['id'=>$rid,'schoolId'=>$sid,'name'=>'Cap Norm Route','status'=>'Active','vehicle_id'=>$vid,'created_at'=>$now], false);
            $this->fixtures[] = ['routes', $rid];
            foreach (['A','B'] as $s) {
                $srid = "PROBE_CAP_SR{$s}_{$ts}";
                $this->fs->setEntity('studentRoutes', $srid, ['schoolId'=>$sid,'studentId'=>"PROBE_CAP_STU{$s}_{$ts}",'route_id'=>$rid,'session'=>$sess,'status'=>'Active','created_at'=>$now], false);
                $this->fixtures[] = ['studentRoutes', $srid];
            }
            // STALE legacy doc at the OLD double-prefixed id: pass "{sid}_CAPCACHE_{rid}"
            // to setEntity → it prefixes again → {sid}_{sid}_CAPCACHE_{rid}.
            $legacyEntity = "{$sid}_CAPCACHE_{$rid}";
            $this->fs->setEntity(self::COL, $legacyEntity, ['route_id'=>$rid,'session'=>$sess,'current_count'=>99,'max_capacity'=>5,'schoolId'=>$sid], false);
            echo "  seeded legacy stale doc (count=99) + true occupancy=2\n";

            echo "\n[G1] rebuildCapacityCache → canonical doc, count recomputed from source (2), cap 5\n";
            $this->transport->rebuildCapacityCache($rid);
            $canon = $this->fs->getEntity(self::COL, "CAPCACHE_{$rid}");   // bare → {sid}_CAPCACHE_{rid}
            $this->fixtures[] = [self::COL, "CAPCACHE_{$rid}"];
            (is_array($canon) && (int)($canon['current_count'] ?? -1) === 2 && (int)($canon['max_capacity'] ?? -1) === 5)
                ? $pass("canonical CAPCACHE doc: current_count=2 (from studentRoutes source), max_capacity=5")
                : $fail("canonical wrong/missing: " . json_encode(is_array($canon) ? ['c'=>$canon['current_count']??null,'m'=>$canon['max_capacity']??null] : $canon));

            echo "\n[G2] stale legacy double-prefixed doc PURGED (no orphan)\n";
            $legacy = $this->fs->getEntity(self::COL, $legacyEntity);   // → {sid}_{sid}_CAPCACHE_{rid}
            (!is_array($legacy))
                ? $pass("legacy stale doc removed by self-healing purge — no orphan, no drift")
                : $fail("legacy doc still present (count=" . ($legacy['current_count'] ?? '?') . ")");
        } catch (\Throwable $e) {
            $fail("exception: " . $e->getMessage());
        } finally {
            echo "\n[TEARDOWN]\n";
            foreach ($this->fixtures as [$col, $id]) {
                try { $this->fs->removeEntity($col, $id); echo "  removed {$col}:{$id}\n"; } catch (\Throwable $e) {}
            }
        }

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }
}
