<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_probe_alloc — Fees stabilization probe for allocationsForStudent (FEE-ENV).
 *
 * The method read each allocation's docId from the data dict ($d['id'], always
 * empty) instead of the schoolWhere envelope ($r['id']), so it returned [] →
 * the refund R.6 stale-allocation guard never fired → a refund could reverse a
 * superseded allocation and corrupt a newer receipt. This validates the fix:
 *
 *   seed 2 active allocations (R1,R2) + 1 reversed (R3) for a student
 *   G1 allocationsForStudent returns exactly the 2 non-reversed (was 0 pre-fix)
 *   G2 each carries _docId = "{schoolId}_{session}_{receiptKey}" (the real
 *      Firestore doc-id the guard compares to $myDocId) — not empty
 *   G3 the reversed allocation (R3) is excluded
 *
 * Self-teardown. CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php fee_probe_alloc run
 */
class Fee_probe_alloc extends CI_Controller
{
    private const COL = 'feeReceiptAllocations';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) { show_error('CLI-only.', 403); }
        $this->load->library('firebase',           null, 'firebase');
        $this->load->library('firestore_service',  null, 'fs');
        $this->load->library('fee_firestore_txn',   null, 'fsTxn');
    }

    public function run(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '2026-27');
        if ($sid === '') { fwrite(STDERR, "ERROR: SCHOOL_ID required.\n"); exit(1); }
        try {
            $this->fs->init($sid, $sess);
            $this->fsTxn->init($this->firebase, $this->fs, $sid, $sess);
        } catch (\Throwable $e) { fwrite(STDERR, "ERROR: init — " . $e->getMessage() . "\n"); exit(1); }

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        $ts  = (int) (microtime(true) * 1000);
        $stu = "PROBE_ALLOC_STU_{$ts}";
        $keys = [];
        $mk = fn(string $k) => "{$sid}_{$sess}_{$k}";   // the stored doc-id

        echo "==================================================\n";
        echo " FEE-ENV allocationsForStudent envelope-docId probe\n";
        echo " tenant={$sid} session={$sess}\n";
        echo "==================================================\n";

        try {
            $seed = [
                ["RCPT_ENV1_{$ts}", 'RN1', 'active'],
                ["RCPT_ENV2_{$ts}", 'RN2', 'active'],
                ["RCPT_ENV3_{$ts}", 'RN3', 'reversed'],   // must be excluded
            ];
            foreach ($seed as [$k, $rn, $st]) {
                $this->fsTxn->writeReceiptAllocation($k, [
                    'studentId'   => $stu,
                    'receiptNo'   => $rn,
                    'status'      => $st,
                    'allocations' => [['demand_id' => 'DEM_ENV_ALLOC', 'allocated' => 1000]],
                ]);
                $keys[] = $k;
            }

            echo "\n[G1/G3] returns exactly the 2 non-reversed allocations\n";
            $out = (array) $this->fsTxn->allocationsForStudent($stu);
            $mine = array_values(array_filter($out, fn($a) => str_starts_with((string)($a['_docId'] ?? ''), $mk('RCPT_ENV'))));
            count($mine) === 2
                ? $pass("2 non-reversed allocations returned (was [] pre-fix); reversed R3 excluded")
                : $fail("got " . count($mine) . " (expected 2): " . json_encode(array_map(fn($a)=>$a['_docId']??'?', $mine)));

            echo "\n[G2] each _docId is the real envelope doc-id (guard-comparable)\n";
            $expected = [$mk("RCPT_ENV1_{$ts}"), $mk("RCPT_ENV2_{$ts}")];
            $got = array_map(fn($a) => (string)($a['_docId'] ?? ''), $mine);
            sort($got); sort($expected);
            ($got === $expected)
                ? $pass("_docId values match {schoolId}_{session}_{receiptKey}: " . implode(', ', $got))
                : $fail("_docId mismatch — got " . json_encode($got) . " expected " . json_encode($expected));
        } catch (\Throwable $e) {
            $fail("exception: " . $e->getMessage());
        } finally {
            echo "\n[TEARDOWN]\n";
            foreach ($keys as $k) { try { $this->firebase->firestoreDelete(self::COL, $mk($k)); } catch (\Throwable $e) {} }
            echo "  cleaned\n";
        }

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }
}
