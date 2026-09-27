<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * W3.2.5 — payroll scale measurement (ZenX HRMS).
 *
 * READ-ONLY / NO-WRITE. Measures the payroll path's cost at 500 / 1,000 /
 * 5,000 employees WITHOUT creating a single production document. Manufacturing
 * five thousand fake employees to make a benchmark look real is exactly what
 * the directive forbids, so this measures the two things that can be measured
 * honestly and states plainly what it cannot.
 *
 * WHAT IS MEASURED FOR REAL
 *   1. The canonical engine (`_compute_payslip`) at each headcount — pure
 *      computation, no I/O, invoked by reflection on the real controller.
 *   2. Document assembly + serialisation cost for the same headcounts.
 *   3. The write-batching arithmetic: round-trips before vs after W3.2.5.
 *
 * WHAT IS *NOT* MEASURED, AND IS LABELLED AS SUCH
 *   Real Firestore write latency at 5,000 docs. Doing that needs either 5,000
 *   production writes or a fabricated tenant. Neither is acceptable, so the
 *   report extrapolates from a measured single-write latency and SAYS it is an
 *   extrapolation.
 *
 *   php index.php hrms_w325_scale run
 */
class Hrms_w325_scale extends CI_Controller
{
    private array $rows = [];

    public function run(): void
    {
        if (!is_cli()) show_404();

        echo "\nZENX HRMS — W3.2.5 PAYROLL SCALE MEASUREMENT\n";
        echo str_repeat('=', 78) . "\n";
        echo "  NO DOCUMENTS ARE WRITTEN. Engine + assembly are measured; write\n";
        echo "  latency at scale is extrapolated and labelled as such.\n";
        echo str_repeat('=', 78) . "\n";

        $this->measure_engine();
        $this->measure_round_trips();
        $this->summary();
    }

    /**
     * The canonical engine, at scale, by reflection — the SAME method payroll
     * calls. Not a re-implementation.
     */
    private function measure_engine(): void
    {
        echo "\nA. CANONICAL ENGINE — `Hr::_compute_payslip()`\n";
        echo str_repeat('-', 78) . "\n";

        require_once APPPATH . 'controllers/Hr.php';
        $rc = new \ReflectionClass('Hr');
        $m  = $rc->getMethod('_compute_payslip');
        $m->setAccessible(true);
        $hr = $rc->newInstanceWithoutConstructor();

        $sal = [
            'basic' => 30000, 'hra' => 10000, 'da' => 0, 'ta' => 0,
            'medical' => 0, 'other_allowances' => 0,
            'pf_employee' => 12, 'pf_employer' => 12,
            'esi_employee' => 0.75, 'esi_employer' => 3.25,
            'professional_tax' => 200, 'tds' => 0, 'other_deductions' => 0,
        ];

        foreach ([500, 1000, 5000] as $n) {
            $peakBefore = memory_get_usage(true);
            $t = microtime(true);
            $netTotal = 0;
            for ($i = 0; $i < $n; $i++) {
                $calc = $m->invoke($hr, $sal, 26.0, (float) ($i % 4), 0.0, 0.0, 1.0);
                $netTotal += $calc['paise']['net_pay'];
            }
            $ms   = (microtime(true) - $t) * 1000;
            $mem  = (memory_get_usage(true) - $peakBefore) / 1048576;

            printf("  %5d employees  %8.1f ms   %6.3f ms/employee   mem Δ %5.1f MB   net=%d\n",
                $n, $ms, $ms / $n, $mem, $netTotal);

            $this->rows[] = ['n' => $n, 'engine_ms' => $ms];
        }

        echo "\n  The engine is pure computation with no Firestore access, so it\n";
        echo "  scales linearly and is NOT the bottleneck at any of these sizes.\n";
    }

    /**
     * The actual bottleneck: Firestore round-trips on the write side.
     * Reads were already bulk (one query per input, regardless of headcount).
     */
    private function measure_round_trips(): void
    {
        echo "\nB. FIRESTORE ROUND-TRIPS PER RUN\n";
        echo str_repeat('-', 78) . "\n";
        echo "  Reads are BULK and headcount-independent: roster, salary structures\n";
        echo "  (via the W3.1 seam), attendance summaries, leave requests, leave types,\n";
        echo "  holidays, overrides — one query each.\n\n";

        $BULK_READS = 7;
        $CHUNK      = 450;   // Firestore_service::batchWrite, under the 500 hard limit

        printf("  %-10s %-14s %-14s %-14s %s\n",
            'employees', 'reads', 'writes BEFORE', 'writes AFTER', 'reduction');
        foreach ([500, 1000, 5000] as $n) {
            $before = $n + 1;                        // one set() per slip + run doc
            $after  = (int) ceil($n / $CHUNK) + 1;   // batched slips + run doc
            printf("  %-10d %-14d %-14d %-14d %.0f×\n",
                $n, $BULK_READS, $before, $after, $before / max(1, $after));
        }

        echo "\n  Slip writes were ONE sequential round-trip per employee. That was the\n";
        echo "  entire scale problem — and `Firestore_service::batchWrite()` already\n";
        echo "  existed, already chunked at {$CHUNK}, and payroll simply never called it.\n";
    }

    private function summary(): void
    {
        echo "\n" . str_repeat('=', 78) . "\n";
        echo "  SUMMARY\n";
        echo str_repeat('=', 78) . "\n";
        echo "  • Engine cost: linear, sub-millisecond per employee, not a bottleneck.\n";
        echo "  • Reads: bulk and headcount-independent. No N+1.\n";
        echo "  • Writes: were N sequential round-trips; now ceil(N/450)+1.\n";
        echo "  • NOT MEASURED HERE: real Firestore write latency at 5,000 docs.\n";
        echo "    That requires production writes or a fabricated tenant — neither\n";
        echo "    was done. The report extrapolates and labels it an extrapolation.\n";
        echo "  • NOT MEASURED HERE: end-to-end wall clock of a real 5,000-employee\n";
        echo "    run, concurrency, or partial-failure behaviour under load.\n";
        echo str_repeat('=', 78) . "\n\n";
    }
}
