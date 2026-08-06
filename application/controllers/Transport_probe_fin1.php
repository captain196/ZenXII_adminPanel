<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_fin1 — FIN-1 paid-demand preservation probe.
 *
 * Proves Fee_lifecycle::createModuleFee never destroys a demand's payment when
 * re-invoked on the same demandId (route change / edit / rollover / reconcile).
 * Invariant: an existing paidAmount is preserved; balance = max(0, amount −
 * paidAmount); status derived; a brand-new demand is unpaid.
 *
 *   S1 unpaid                         S5 route change after partial pay
 *   S2 partially paid (preserved)     S6 route change after full pay
 *   S3 fully paid (preserved)         S7 session rollover (new demand, old paid untouched)
 *   S4 route change before payment    S8 repeated execution (idempotent)
 *
 * Self-teardown. CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_fin1 run
 */
class Transport_probe_fin1 extends CI_Controller
{
    private array $demandIds = [];
    private string $studentId = '';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Transport_probe_fin1 is CLI-only.', 403);
        }
        $this->load->library('firebase',          null, 'firebase');
        $this->load->library('firestore_service', null, 'fs');
        $this->load->library('fee_lifecycle',     null, 'feeLifecycle');
    }

    public function run(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '2026-27');
        if ($sid === '') { fwrite(STDERR, "ERROR: SCHOOL_ID required.\n"); exit(1); }
        try {
            $this->fs->init($sid, $sess);
            $this->feeLifecycle->init($this->firebase, $sid, $sess, 'probe_fin1');
        } catch (\Throwable $e) { fwrite(STDERR, "ERROR: init failed — " . $e->getMessage() . "\n"); exit(1); }

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        $ts  = (int) (microtime(true) * 1000);
        $this->studentId = "PROBE_FIN1_STU_{$ts}";
        $this->fs->setEntity('students', $this->studentId, ['name'=>'FIN1 Probe','className'=>'Class 8th','section'=>'A'], false);

        // helpers ---------------------------------------------------------
        $create = fn(float $amt): string => (string) $this->feeLifecycle->createModuleFee($this->studentId, 'Transport', ['amount'=>$amt,'fee_head'=>'Transport Fee','frequency'=>'Monthly']);
        $read   = fn(string $did): array => (array) ($this->firebase->firestoreGet('feeDemands', $did) ?: []);
        // Force a demand into a known payment pre-state (simulates FeeCollectionService).
        $seedState = function(string $did, float $paid, float $bal, string $st) {
            $this->firebase->firestoreSet('feeDemands', $did, ['paidAmount'=>$paid,'balance'=>$bal,'status'=>$st], true);
        };
        $check = function(string $label, array $d, float $paid, float $bal, string $st) use ($pass, $fail) {
            $ap = round((float)($d['paidAmount'] ?? -1),2); $ab = round((float)($d['balance'] ?? -1),2); $as = (string)($d['status'] ?? '?');
            ($ap === round($paid,2) && $ab === round($bal,2) && $as === $st)
                ? $pass("{$label}: paidAmount={$ap} balance={$ab} status={$as}")
                : $fail("{$label}: got paid={$ap} bal={$ab} status={$as} — expected paid={$paid} bal={$bal} status={$st}");
        };

        echo "==================================================\n";
        echo " FIN-1 Paid-Demand Preservation Probe\n";
        echo " tenant={$sid} session={$sess} student={$this->studentId}\n";
        echo "==================================================\n";

        try {
            echo "\n[S1] create unpaid @2000\n";
            $did = $create(2000); $this->demandIds[] = $did;
            $check('S1 unpaid', $read($did), 0, 2000, 'unpaid');

            echo "\n[S2] partially paid (800) → re-create @2000 preserves payment\n";
            $seedState($did, 800, 1200, 'partial'); $create(2000);
            $check('S2 partial preserved', $read($did), 800, 1200, 'partial');

            echo "\n[S3] fully paid (2000) → re-create @2000 preserves payment\n";
            $seedState($did, 2000, 0, 'paid'); $create(2000);
            $check('S3 paid preserved', $read($did), 2000, 0, 'paid');

            echo "\n[S4] route change BEFORE payment (unpaid) → @3000 unpaid\n";
            $seedState($did, 0, 2000, 'unpaid'); $create(3000);
            $check('S4 route-change unpaid', $read($did), 0, 3000, 'unpaid');

            echo "\n[S5] route change AFTER partial pay (800) → @3000, balance 2200, partial\n";
            $seedState($did, 800, 1200, 'partial'); $create(3000);
            $check('S5 route-change partial', $read($did), 800, 2200, 'partial');

            echo "\n[S6] route change AFTER full pay (2000) → @3000, balance 1000, now partial\n";
            $seedState($did, 2000, 0, 'paid'); $create(3000);
            $check('S6 route-change full→partial', $read($did), 2000, 1000, 'partial');

            echo "\n[S8] repeated execution (idempotent) on partial → still 800 paid, one demand\n";
            $seedState($did, 800, 2200, 'partial'); $create(3000); $create(3000); $create(3000);
            $check('S8 idempotent partial', $read($did), 800, 2200, 'partial');

            echo "\n[S7] session rollover — new-session demand is separate; old PAID demand untouched\n";
            $seedState($did, 3000, 0, 'paid');                 // old-session demand fully paid
            $this->feeLifecycle->init($this->firebase, $sid, '2027-28', 'probe_fin1'); // roll to next session
            $did2 = $create(2500); $this->demandIds[] = $did2;
            $sameDoc = ($did2 === $did);
            $oldAfter = $read($did);
            (!$sameDoc && round((float)($oldAfter['paidAmount'] ?? -1),2) === 3000.0 && (string)($oldAfter['status'] ?? '') === 'paid')
                ? $pass("rollover created a SEPARATE demand ({$did2}); old paid demand untouched (paidAmount=3000, paid)")
                : $fail("rollover unsafe: sameDoc=" . var_export($sameDoc,true) . " oldPaid=" . ($oldAfter['paidAmount'] ?? '?') . " oldStatus=" . ($oldAfter['status'] ?? '?'));
            $check('S7 new-session demand unpaid', $read($did2), 0, 2500, 'unpaid');
        } catch (\Throwable $e) {
            $fail("exception: " . $e->getMessage());
        } finally {
            echo "\n[TEARDOWN]\n";
            foreach (array_unique($this->demandIds) as $d) {
                try { $this->firebase->firestoreDelete('feeDemands', $d); echo "  removed feeDemands:{$d}\n"; } catch (\Throwable $e) {}
            }
            try { $this->fs->removeEntity('students', $this->studentId); echo "  removed students:{$this->studentId}\n"; } catch (\Throwable $e) {}
        }

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }
}
