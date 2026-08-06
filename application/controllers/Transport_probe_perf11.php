<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_perf11 — fleet expense trend single-query (PERF-11).
 *
 * getFleetExpenseTrend did 24 sequential point-reads (one getEntity per month).
 * Fix: ONE `period_year_month IN [months]` query (chunked at 30) with a
 * per-month fallback if the composite index is missing. Result must be
 * identical either way: every month in order, seeded values matched, gaps
 * zero-filled.
 *
 *   seed summaries for the last 3 months (current, -1, -2); leave -3 absent
 *   G1 getFleetExpenseTrend(4) returns 4 rows in chronological order
 *   G2 seeded months carry the seeded totals; the absent month is zero-filled
 *
 * Self-teardown. CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_perf11 run
 */
class Transport_probe_perf11 extends CI_Controller
{
    private const COL = 'fleetMonthlyExpenseSummary';
    private array $fixtures = [];

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
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') { fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n"); exit(1); }
        try {
            $this->fs->init($sid, $sess);
            $this->numbering->init($this->fs, $sid, $sess);
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'transport_probe_perf11');
            $this->analytics->init($this->fs, $this->transport, $sid, $sess);
        } catch (\Throwable $e) { fwrite(STDERR, "ERROR: init — " . $e->getMessage() . "\n"); exit(1); }

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        $ym0 = date('Y-m');
        $ym1 = date('Y-m', strtotime('-1 months'));
        $ym2 = date('Y-m', strtotime('-2 months'));
        $ym3 = date('Y-m', strtotime('-3 months'));  // intentionally absent

        echo "==================================================\n";
        echo " PERF-11 fleet expense trend single-query probe\n";
        echo " tenant={$sid}\n";
        echo "==================================================\n";

        try {
            $seed = [$ym0 => 111.0, $ym1 => 222.0, $ym2 => 333.0];
            foreach ($seed as $ym => $amt) {
                $this->fs->setEntity(self::COL, "FMES__{$ym}", [
                    'period_year_month' => $ym,
                    'schoolId'          => $sid,
                    'total_amount'      => $amt,
                    'expenses_count'    => (int) ($amt / 111),
                ], false);
                $this->fixtures[] = "FMES__{$ym}";
            }

            $trend = (array) $this->analytics->getFleetExpenseTrend(4);
            $byYm = [];
            foreach ($trend as $row) { $byYm[(string) ($row['period_year_month'] ?? '')] = $row; }

            echo "\n[G1] 4 rows in chronological order (oldest → newest)\n";
            $order = array_map(fn($r) => (string) $r['period_year_month'], $trend);
            (count($trend) === 4 && $order === [$ym3, $ym2, $ym1, $ym0])
                ? $pass("order = " . implode(', ', $order))
                : $fail("got " . count($trend) . " rows, order " . json_encode($order) . " (expected {$ym3},{$ym2},{$ym1},{$ym0})");

            echo "\n[G2] seeded totals matched; absent month zero-filled\n";
            $ok = (float) ($byYm[$ym0]['total_amount'] ?? -1) === 111.0
                && (float) ($byYm[$ym1]['total_amount'] ?? -1) === 222.0
                && (float) ($byYm[$ym2]['total_amount'] ?? -1) === 333.0
                && (float) ($byYm[$ym3]['total_amount'] ?? -1) === 0.0;
            $ok ? $pass("{$ym0}=111, {$ym1}=222, {$ym2}=333, {$ym3}=0 (zero-filled)")
                : $fail("values wrong: " . json_encode(array_map(fn($y) => $byYm[$y]['total_amount'] ?? null, [$ym0,$ym1,$ym2,$ym3])));
        } catch (\Throwable $e) {
            $fail("unexpected exception: " . $e->getMessage());
        } finally {
            echo "\n[TEARDOWN]\n";
            foreach ($this->fixtures as $id) { try { $this->fs->removeEntity(self::COL, $id); } catch (\Throwable $e) {} }
            echo "  cleaned\n";
        }

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }
}
