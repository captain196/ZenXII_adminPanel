<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_con3 — CON-3 capacity-cache concurrency runtime probe.
 *
 * Validates, against LIVE Firestore, the atomic check-and-increment mechanism
 * that Transport_service::_reserveCapacitySeat / _bumpCapacityCache rely on
 * (Firestore_service::casUpdateEntity with an updateTime CAS precondition):
 *
 *   G1 seed a synthetic busCapacityCache doc (current=1, max=2)
 *   G2 reserve #1 → succeeds, count=2 (atomic +1)
 *   G3 reserve #2 → ABORTS (cur>=max); count STILL 2 — overfill prevented
 *      (this is the TOCTOU the finding is about: check + increment are one step)
 *   G4 release (-1) → count=1 (atomic decrement)
 *   G5 floor — two more -1 → count floors at 0, never negative
 *
 * Self-provisioning + self-teardown (portable across tenants). CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_con3 run
 */
class Transport_probe_con3 extends CI_Controller
{
    private const COL = 'busCapacityCache';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Transport_probe_con3 is CLI-only.', 403);
        }
        $this->load->library('firebase',          null, 'firebase');
        $this->load->library('firestore_service', null, 'fs');
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
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: init failed — " . $e->getMessage() . "\n");
            exit(1);
        }

        $entityId = 'PROBE_CON3_' . (int) (microtime(true) * 1000);
        $passed = 0; $failed = 0;
        $pass = function (string $msg) use (&$passed) { echo "  PASS: {$msg}\n"; $passed++; };
        $fail = function (string $msg) use (&$failed) { echo "  FAIL: {$msg}\n"; $failed++; };

        // The exact mutator _reserveCapacitySeat uses (atomic check + increment).
        $full = false;
        $reserve = function (array $cache) use (&$full) {
            $max = (int) ($cache['max_capacity']  ?? 0);
            $cur = (int) ($cache['current_count'] ?? 0);
            if ($max > 0 && $cur >= $max) { $full = true; return null; }
            return ['current_count' => $cur + 1, 'last_updated_at' => date('c')];
        };
        $release = function (array $cache) {
            $cur = (int) ($cache['current_count'] ?? 0);
            return ['current_count' => max(0, $cur - 1), 'last_updated_at' => date('c')];
        };
        $count = function () use ($entityId): int {
            $d = $this->fs->getEntity(self::COL, $entityId);
            return is_array($d) ? (int) ($d['current_count'] ?? -999) : -999;
        };

        echo "==================================================\n";
        echo " CON-3 Capacity Cache Concurrency Probe\n";
        echo " tenant={$sid} doc={$entityId}\n";
        echo "==================================================\n";

        try {
            // G1 — seed
            echo "\n[G1] seed cache doc current=1 max=2\n";
            $this->fs->setEntity(self::COL, $entityId, ['current_count' => 1, 'max_capacity' => 2, 'route_id' => 'PROBE'], true);
            $count() === 1 ? $pass("seeded current=1") : $fail("seed current=" . $count());

            // G2 — reserve #1 (1 -> 2)
            echo "\n[G2] reserve #1 — atomic +1\n";
            $full = false;
            $r1 = $this->fs->casUpdateEntity(self::COL, $entityId, $reserve);
            (!empty($r1['applied']) && $count() === 2)
                ? $pass("reserve #1 applied, count=2")
                : $fail("reserve #1 reason=" . ($r1['reason'] ?? '?') . " count=" . $count());

            // G3 — reserve #2 must ABORT (overfill prevented); count stays 2
            echo "\n[G3] reserve #2 — must abort at capacity (no overfill)\n";
            $full = false;
            $r2 = $this->fs->casUpdateEntity(self::COL, $entityId, $reserve);
            (($r2['reason'] ?? '') === 'aborted' && $full === true && $count() === 2)
                ? $pass("reserve #2 aborted at 2/2; count NOT incremented — overfill prevented")
                : $fail("reserve #2 reason=" . ($r2['reason'] ?? '?') . " full=" . var_export($full, true) . " count=" . $count());

            // G4 — release (2 -> 1)
            echo "\n[G4] release — atomic -1\n";
            $r3 = $this->fs->casUpdateEntity(self::COL, $entityId, $release);
            (!empty($r3['applied']) && $count() === 1)
                ? $pass("release applied, count=1")
                : $fail("release reason=" . ($r3['reason'] ?? '?') . " count=" . $count());

            // G5 — floor at 0
            echo "\n[G5] floor — two more releases must not go negative\n";
            $this->fs->casUpdateEntity(self::COL, $entityId, $release);
            $this->fs->casUpdateEntity(self::COL, $entityId, $release);
            $count() === 0
                ? $pass("count floored at 0 (never negative)")
                : $fail("floor count=" . $count());
        } finally {
            echo "\n[TEARDOWN] removing probe fixture\n";
            try { $this->fs->removeEntity(self::COL, $entityId); echo "  removed {$entityId}\n"; }
            catch (\Throwable $e) { echo "  teardown warn: " . $e->getMessage() . "\n"; }
        }

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }
}
