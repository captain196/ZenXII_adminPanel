<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * N-19 — real Firestore write-latency benchmark (ZenX HRMS W3.2.5).
 *
 * WHY THIS EXISTS
 * ---------------
 * `Hrms_w325_scale` proved the ROUND-TRIP COUNTS exactly: 5,001 single writes
 * become 13 batched ones at 5,000 employees. What it could not prove is what a
 * round trip actually COSTS, so the wall-clock figures in the W3.2.5 report are
 * an extrapolation from an assumed 100–200 ms. This harness replaces that
 * assumption with a measurement.
 *
 * SAFETY — why it is dry-run by default
 * -------------------------------------
 * Measuring real latency requires real writes. This does NOT fabricate
 * employees, salary structures or payslips: it writes throwaway documents to a
 * dedicated `_benchmarkScratch` collection that nothing reads, measures, and
 * deletes them again in the same run.
 *
 *   - DRY RUN (default): writes nothing, prints exactly what it would do.
 *   - --commit: performs the measurement, then removes every document it wrote.
 *
 * It never touches `salarySlips`, `staff`, `payrollOverrides` or any collection
 * payroll reads. A crash mid-run leaves at most SAMPLE_SIZE scratch documents,
 * all sharing a single identifiable prefix, in a collection no code queries.
 *
 *   php index.php hrms_w325_latency run
 *   php index.php hrms_w325_latency run --commit
 */
class Hrms_w325_latency extends CI_Controller
{
    /** Deliberately small. Latency per round trip is what is being measured;
     *  a large sample would add production write volume without adding signal. */
    private const SAMPLE_SIZE = 20;
    private const COLLECTION  = '_benchmarkScratch';

    public function run(): void
    {
        if (!is_cli()) show_404();

        $commit = in_array('--commit', $_SERVER['argv'] ?? [], true);

        echo "\nN-19 — FIRESTORE WRITE-LATENCY BENCHMARK\n";
        echo str_repeat('=', 78) . "\n";
        echo '  MODE: ' . ($commit ? "COMMIT (real writes, auto-cleaned)" : 'DRY RUN (nothing written)') . "\n";
        echo '  Collection: ' . self::COLLECTION . "  (read by nothing)\n";
        echo '  Sample size: ' . self::SAMPLE_SIZE . " documents\n";
        echo str_repeat('=', 78) . "\n";

        if (!$commit) {
            echo "\n  DRY RUN — no document was written.\n\n";
            echo "  A real measurement needs " . self::SAMPLE_SIZE . " throwaway writes to\n";
            echo "  '" . self::COLLECTION . "', which are deleted in the same run. Nothing in\n";
            echo "  payroll, staff, accounting or compensation is touched.\n\n";
            echo "  Until this is run with --commit, the wall-clock figures in the\n";
            echo "  W3.2.5 report remain an EXTRAPOLATION from an assumed round-trip\n";
            echo "  cost, and N-19 stays MISSING.\n\n";
            echo "  Authorized command:  php index.php hrms_w325_latency run --commit\n\n";
            return;
        }

        $this->load->library('firestore_service');
        $fs = $this->firestore_service;

        $payload = $this->samplePayload();
        $ids     = [];
        for ($i = 0; $i < self::SAMPLE_SIZE; $i++) {
            $ids[] = 'BENCH_' . getmypid() . '_' . $i;
        }

        // ── A. Sequential single writes — the pre-W3.2.5 pattern ──
        $t = microtime(true);
        $okSingle = 0;
        foreach ($ids as $id) {
            if ($fs->set(self::COLLECTION, $id, $payload, false)) $okSingle++;
        }
        $singleMs = (microtime(true) - $t) * 1000;

        // ── B. One batched write — the W3.2.5 pattern ──
        $docs = [];
        foreach ($ids as $id) $docs[$id . '_B'] = $payload;

        $t = microtime(true);
        $res = $fs->batchWrite(self::COLLECTION, $docs, false);
        $batchMs = (microtime(true) - $t) * 1000;
        $okBatch = count(array_filter($res));

        // ── Cleanup — always, in the same run ──
        $cleanup = array_merge($ids, array_keys($docs));
        $removed = 0;
        try {
            $removed = (int) $fs->batchRemove(self::COLLECTION, $cleanup);
        } catch (\Exception $e) {
            log_message('error', 'N-19 cleanup failed: ' . $e->getMessage());
        }

        $this->report($singleMs, $okSingle, $batchMs, $okBatch, $removed, count($cleanup));
    }

    /** Roughly payslip-shaped, so byte size is representative. */
    private function samplePayload(): array
    {
        return [
            'benchmark' => true, 'createdAt' => date('c'),
            'staff_name' => 'BENCHMARK SCRATCH', 'basic' => 30000.0, 'hra' => 10000.0,
            'gross' => 38500.0, 'net_pay' => 34591.25, 'total_deductions' => 3908.75,
            'working_days' => 26, 'days_worked' => 24,
            '_note' => 'N-19 latency benchmark. Deleted by the same run. Read by nothing.',
        ];
    }

    private function report(float $singleMs, int $okSingle, float $batchMs, int $okBatch,
                            int $removed, int $expected): void
    {
        $n = self::SAMPLE_SIZE;
        $perSingle = $singleMs / max(1, $n);

        echo "\nMEASURED\n" . str_repeat('-', 78) . "\n";
        printf("  %d sequential set()   %9.1f ms total   %7.2f ms per round trip   ok=%d\n",
            $n, $singleMs, $perSingle, $okSingle);
        printf("  1 batchWrite(%d docs) %9.1f ms total   %7.2f ms per document     ok=%d\n",
            $n, $batchMs, $batchMs / max(1, $n), $okBatch);
        printf("  speedup on the same %d documents: %.1f×\n", $n, $singleMs / max(0.001, $batchMs));

        echo "\nCLEANUP\n" . str_repeat('-', 78) . "\n";
        printf("  removed %d of %d scratch documents%s\n", $removed, $expected,
            $removed === $expected ? '' : '   ⚠ MANUAL CLEANUP MAY BE NEEDED');

        // Project onto the EXACT round-trip counts already proven by
        // Hrms_w325_scale. The counts are exact; only the per-trip cost was
        // previously assumed, and it is now measured.
        echo "\nPROJECTION onto measured round-trip counts\n" . str_repeat('-', 78) . "\n";
        printf("  %-10s %-22s %-22s %s\n", 'employees', 'BEFORE (N+1 writes)', 'AFTER (ceil(N/450)+1)', 'saved');
        foreach ([500, 1000, 5000] as $emp) {
            $before = ($emp + 1) * $perSingle / 1000;
            $trips  = (int) ceil($emp / 450) + 1;
            $after  = $trips * ($batchMs / 1000);   // one batch ≈ one round trip
            printf("  %-10d %-22s %-22s %.1f s\n", $emp,
                sprintf('%.1f s', $before), sprintf('%.1f s (%d trips)', $after, $trips),
                $before - $after);
        }

        echo "\n  Per-trip cost is now MEASURED, not assumed. The projection is still a\n";
        echo "  projection: it assumes latency stays flat as document count grows, and\n";
        echo "  a real 5,000-employee run was NOT performed.\n";
        echo str_repeat('=', 78) . "\n\n";
    }
}
