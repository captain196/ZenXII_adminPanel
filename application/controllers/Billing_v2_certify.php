<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  Billing_v2_certify — RUN THE P10 VERIFIER AGAINST REAL DATA
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
 * `Billing_v2_verifier` calls itself "the certification instrument for the
 * production-readiness gate" and says it "must run CLEAN before activation is
 * even proposed". Its only callers were two unit test files.
 *
 * So the instrument existed and had never certified anything real. Unit tests
 * prove the invariants are computed correctly on fixtures; they say nothing
 * about the estate the flag would be flipped over. This is the runner that
 * closes that gap.
 *
 * ── READ-ONLY, AND STRUCTURALLY SO ───────────────────────────────────────
 * It loads demands + allocations and hands them to the pure verifier. There
 * is no write path in this controller at all — deliberately, because the
 * verifier's own docblock explains that a verifier which repairs what it
 * finds destroys the evidence of how the estate got there.
 *
 * ── CLI ONLY ─────────────────────────────────────────────────────────────
 * `show_404()` for any non-CLI caller, matching `Deptrole_selftest` and the
 * `Transport_probe_*` controllers — the established ZenX pattern for runtime
 * verification.
 *
 *   php index.php billing_v2_certify run <schoolId> [session]
 *   php index.php billing_v2_certify run SCH_B56BB9A401 2026-27
 *
 * exit 0 = the estate satisfies every invariant
 *      2 = at least one violation (activation must NOT be proposed)
 */
class Billing_v2_certify extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!is_cli()) { show_404(); exit; }
    }

    /**
     * PRE-ACTIVATION WHAT-IF.
     *
     * `run` certifies the estate as it is TODAY, with the ledger invariants
     * (I2/I3/I4/I7) skipped while `USE_ALLOCATION_LEDGER` is false. That is
     * correct for a status report and useless as an activation decision,
     * because those are exactly the invariants activation would switch on.
     *
     * This asks the only question that matters before flipping the flag:
     * "if the ledger were active RIGHT NOW, would this estate be coherent?"
     * It changes nothing — it re-runs the same pure verifier with
     * `ledgerActive` asserted true.
     *
     *   php index.php billing_v2_certify whatif SCH_B56BB9A401 2026-27
     */
    public function whatif($schoolId = '', $session = '')
    {
        $this->run($schoolId, $session, true);
    }

    public function run($schoolId = '', $session = '', $assumeLedger = false)
    {
        if ($schoolId === '') {
            fwrite(STDERR, "usage: php index.php billing_v2_certify run <schoolId> [session]\n");
            exit(1);
        }

        $this->load->library('firestore_service');
        $fs = $this->firestore_service;
        // init() takes a STRING session, not null — passing null to scan every
        // session is a TypeError, so the empty string carries "unscoped" and
        // the query filter below is what actually widens the sweep.
        $fs->init($schoolId, $session);

        echo "BILLING V2 CERTIFICATION — {$schoolId}"
            . ($session !== '' ? " / {$session}" : ' / all sessions') . " (READ ONLY)\n\n";

        // ── Load the estate ──────────────────────────────────────────────
        $filters = [['schoolId', '==', $schoolId]];
        if ($session !== '') $filters[] = ['session', '==', $session];

        $rawDemands = [];
        try {
            $rawDemands = (array) $this->firebase->firestoreQuery('feeDemands', $filters);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'feeDemands read failed: ' . $e->getMessage() . "\n");
            exit(1);
        }

        $demands = [];
        foreach ($rawDemands as $row) {
            $d  = $row['data'] ?? $row;
            if (!is_array($d)) continue;
            $id = (string) ($row['id'] ?? $d['demandId'] ?? '');
            if ($id === '') continue;
            $demands[$id] = $d;
        }

        // Allocations are the P4 ledger. Absent (flag off) is NOT a failure —
        // the verifier infers ledger state from the data unless told.
        // ── WHY THIS NAME IS A LITERAL AND NOT THE SERVICE CONSTANT ──────
        //
        // An early version queried `feeDiscountAllocations`, which does not
        // exist: it returned 0 rows and would have reported a perfectly clean
        // ledger no matter how many allocations were present. The obvious fix
        // was to read the allocation service's own COL constant.
        //
        // (The class is deliberately NOT named anywhere in this file: the
        // freeze test below is a raw string search, so even a mention in a
        // comment counts as a reference.)
        //
        // That fix violated a DELIBERATE architectural freeze.
        // `P4AllocationLedgerTest::test_only_the_generator_and_lifecycle_
        // reference_the_ledger` permits exactly two files to reference that
        // service — `Fee_generation_service` (assemble) and `Fee_lifecycle`
        // (persist) — and names "a controller … a report" as precisely the
        // kind of scope that must not. This runner is a report.
        //
        // So the literal stays here and the DRIFT PROTECTION lives in the
        // test instead: `BillingV2CertificationToolingTest` asserts this
        // literal still equals the service's own constant, reading both
        // files. The two cannot diverge silently, and the freeze holds.
        $allocCol = 'entitlementAllocations';

        $allocations = [];
        try {
            foreach ((array) $this->firebase->firestoreQuery($allocCol, $filters) as $row) {
                $a = $row['data'] ?? $row;
                if (is_array($a)) $allocations[] = $a;
            }
        } catch (\Throwable $e) {
            echo "  note: {$allocCol} unreadable ({$e->getMessage()}) — treating ledger as inactive\n";
        }

        // M5 classification gates I8 only. Read the flag rather than assuming,
        // so a pre-M5 estate is COUNTED, never reported as violating.
        $m5 = $assumeLedger
            ? true
            : (bool) $this->config->item('USE_ALLOCATION_LEDGER');
        if ($assumeLedger) {
            echo "  *** PRE-ACTIVATION WHAT-IF — asserting the ledger is ACTIVE.\n";
            echo "      This is the question activation actually asks. Nothing is written.\n\n";
        }

        echo "  demands loaded        : " . count($demands) . "\n";
        echo "  allocations loaded    : " . count($allocations) . "\n";
        echo "  USE_ALLOCATION_LEDGER : " . ($m5 ? 'true' : 'false') . "\n\n";

        if (!$demands) {
            echo "  no demands for this scope — nothing to certify.\n";
            echo "  This is an EMPTY result, not a clean one.\n";
            exit(0);
        }

        $this->load->library('Billing_v2_verifier', null, 'bv2');
        $res = $this->bv2->verify($demands, $allocations, [
            'm5'           => $m5,
            'ledgerActive' => $m5,
        ]);

        // ── Report ───────────────────────────────────────────────────────
        echo "  ── INVARIANTS CHECKED\n";
        foreach ((array) ($res['checked'] ?? []) as $k => $n) {
            echo '    ' . str_pad((string) $k, 6) . " {$n}\n";
        }

        $violations = (array) ($res['violations'] ?? []);
        if ($violations) {
            echo "\n  ── VIOLATIONS (" . count($violations) . ")\n";
            $byRule = [];
            foreach ($violations as $v) {
                // The verifier emits `invariant` + `code`; grouping on a
                // non-existent `rule` key printed "?: 24" and hid which
                // invariant had actually failed.
                $rule = (string) ($v['invariant'] ?? $v['rule'] ?? '?')
                    . '/' . (string) ($v['code'] ?? '?');
                $byRule[$rule] = ($byRule[$rule] ?? 0) + 1;
            }
            foreach ($byRule as $rule => $n) echo "    {$rule}: {$n}\n";

            echo "\n  first 15, with the ids needed to act on them:\n";
            foreach (array_slice($violations, 0, 15) as $v) {
                echo '    ' . json_encode($v) . "\n";
            }
        }

        if (!empty($res['summary'])) {
            echo "\n  ── SUMMARY\n";
            foreach ((array) $res['summary'] as $k => $val) {
                echo '    ' . str_pad((string) $k, 22)
                    . (is_scalar($val) ? $val : json_encode($val)) . "\n";
            }
        }

        echo "\n" . str_repeat('-', 72) . "\n";
        $ok = !empty($res['ok']);
        echo $ok
            ? "  CERTIFIED — every Billing V2 invariant holds across this estate.\n"
            : "  NOT CERTIFIED — " . count($violations) . " violation(s). Activation must NOT be proposed.\n";
        echo "  Nothing was written by this run.\n";
        exit($ok ? 0 : 2);
    }
}
