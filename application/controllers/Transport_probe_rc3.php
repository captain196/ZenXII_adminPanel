<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_rc3 — RC-3 docId double-prefix fix probe (FMES / VEXPCACHE).
 *
 * Proves the writer and the bare-id analytics readers now resolve to the SAME
 * canonical {schoolId}_<id> doc (was: writer double-prefixed → analytics bare
 * read missed → PERF-5 read-0):
 *
 *   G1 computeFleetMonthlyExpenseSummary() writes FMES; a BARE getEntity
 *      (the analytics-path read) now HITS it, and summaryId is bare
 *   G2 bare setEntity ↔ bare getEntity align (canonical single prefix)
 *   G3 the OLD pattern (school-prefixed id write) is NOT found by a bare read
 *      — demonstrating exactly the double-prefix mismatch the fix removes
 *
 * Uses a synthetic future month + synthetic cache ids so it never disturbs the
 * tenant's real caches. Self-teardown. CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_rc3 run
 */
class Transport_probe_rc3 extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Transport_probe_rc3 is CLI-only.', 403);
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
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'transport_probe_rc3');
        } catch (\Throwable $e) { fwrite(STDERR, "ERROR: init failed — " . $e->getMessage() . "\n"); exit(1); }

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        $ts  = (int) (microtime(true) * 1000);
        $ym  = '2099-01';                         // synthetic month — no real expenses
        $fmesId  = "FMES__{$ym}";
        $vexpBare = "PROBE_VEXP_{$ts}";
        $vexpPrefixed = "{$sid}_PROBE_VEXP2_{$ts}";

        echo "==================================================\n";
        echo " RC-3 docId Double-Prefix Probe (FMES / VEXPCACHE)\n";
        echo " tenant={$sid}\n";
        echo "==================================================\n";

        try {
            echo "\n[G1] FMES writer → bare analytics-path read HITS canonical doc\n";
            $this->transport->computeFleetMonthlyExpenseSummary($ym);
            $doc = $this->fs->getEntity('fleetMonthlyExpenseSummary', $fmesId);   // bare read (analytics path)
            (is_array($doc) && (string) ($doc['summaryId'] ?? '') === $fmesId && (string) ($doc['period_year_month'] ?? '') === $ym)
                ? $pass("FMES written by computeFleetMonthlyExpenseSummary is read by bare getEntity; summaryId='{$fmesId}'")
                : $fail("bare FMES read missed or wrong: " . json_encode(is_array($doc) ? array_intersect_key($doc, ['summaryId'=>1,'period_year_month'=>1]) : $doc));

            echo "\n[G2] bare setEntity ↔ bare getEntity align (canonical single prefix)\n";
            $this->fs->setEntity('vehicleExpiryDashboardCache', $vexpBare, ['probe' => true, 'ts' => $ts], false);
            $g2 = $this->fs->getEntity('vehicleExpiryDashboardCache', $vexpBare);
            is_array($g2) ? $pass("bare write is found by bare read")
                          : $fail("bare read missed a bare write");

            echo "\n[G3] OLD pattern (school-prefixed write) is NOT found by a bare read (the bug)\n";
            $this->fs->setEntity('vehicleExpiryDashboardCache', $vexpPrefixed, ['probe' => true], false);  // → {sid}_{sid}_PROBE_VEXP2
            $g3 = $this->fs->getEntity('vehicleExpiryDashboardCache', "PROBE_VEXP2_{$ts}");                 // bare → {sid}_PROBE_VEXP2
            (!is_array($g3))
                ? $pass("school-prefixed write is invisible to a bare read — this is exactly the PERF-5 mismatch the fix eliminates")
                : $fail("unexpected: bare read found the double-prefixed doc");
        } catch (\Throwable $e) {
            $fail("exception: " . $e->getMessage());
        } finally {
            echo "\n[TEARDOWN]\n";
            foreach ([
                ['fleetMonthlyExpenseSummary', $fmesId],
                ['vehicleExpiryDashboardCache', $vexpBare],
                ['vehicleExpiryDashboardCache', $vexpPrefixed],
            ] as [$col, $id]) {
                try { $this->fs->removeEntity($col, $id); echo "  removed {$col}:{$id}\n"; } catch (\Throwable $e) {}
            }
        }

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }
}
