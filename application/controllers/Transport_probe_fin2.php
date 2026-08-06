<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_fin2 — FIN-2 rollover-cancel category-case probe.
 *
 * createModuleFee stores category lowercase ('transport'). cancelSessionDemands
 * (rollover) must match it case-insensitively — a case-sensitive 'Transport'
 * predicate matches zero rows → old-session demands linger as phantom dues.
 *
 *   G1 unpaid transport demand → cancelSessionDemands('Transport') cancels it
 *   G2 PAID demand            → cancelSessionDemands skips it (never destroyed)
 *
 * Self-teardown. CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_fin2 run
 */
class Transport_probe_fin2 extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) { show_error('Transport_probe_fin2 is CLI-only.', 403); }
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
            $this->feeLifecycle->init($this->firebase, $sid, $sess, 'probe_fin2');
        } catch (\Throwable $e) { fwrite(STDERR, "ERROR: init — " . $e->getMessage() . "\n"); exit(1); }

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        $ts  = (int) (microtime(true) * 1000);
        $stu = "PROBE_FIN2_STU_{$ts}";
        $this->fs->setEntity('students', $stu, ['name'=>'FIN2 Probe','className'=>'Class 8th','section'=>'A'], false);
        $read = fn(string $did): array => (array) ($this->firebase->firestoreGet('feeDemands', $did) ?: []);
        $did  = '';

        echo "==================================================\n";
        echo " FIN-2 Rollover-Cancel Category-Case Probe\n";
        echo " tenant={$sid} session={$sess}\n";
        echo "==================================================\n";

        try {
            echo "\n[G1] unpaid transport demand → cancelSessionDemands('Transport') cancels it\n";
            $did = (string) $this->feeLifecycle->createModuleFee($stu, 'Transport', ['amount'=>2000,'fee_head'=>'Transport Fee','frequency'=>'Monthly']);
            $cat = (string) ($read($did)['category'] ?? '');
            $n   = (int) $this->feeLifecycle->cancelSessionDemands($stu, $sess, 'Transport');
            $st  = (string) ($read($did)['status'] ?? '');
            ($cat === 'transport' && $n >= 1 && $st === 'cancelled')
                ? $pass("stored category='transport'; cancel matched {$n} row(s); demand now '{$st}'")
                : $fail("category='{$cat}' cancelled={$n} status='{$st}' (expected transport / >=1 / cancelled)");

            echo "\n[G2] PAID demand → cancelSessionDemands SKIPS it (payment never destroyed)\n";
            $this->feeLifecycle->createModuleFee($stu, 'Transport', ['amount'=>2000,'fee_head'=>'Transport Fee','frequency'=>'Monthly']);
            $this->firebase->firestoreSet('feeDemands', $did, ['paidAmount'=>2000,'balance'=>0,'status'=>'paid'], true);
            $this->feeLifecycle->cancelSessionDemands($stu, $sess, 'Transport');
            $st2 = (string) ($read($did)['status'] ?? '');
            ($st2 === 'paid')
                ? $pass("paid demand left untouched (status still 'paid')")
                : $fail("paid demand was altered — status='{$st2}' (expected paid)");
        } catch (\Throwable $e) {
            $fail("exception: " . $e->getMessage());
        } finally {
            echo "\n[TEARDOWN]\n";
            if ($did !== '') { try { $this->firebase->firestoreDelete('feeDemands', $did); } catch (\Throwable $e) {} }
            try { $this->fs->removeEntity('students', $stu); } catch (\Throwable $e) {}
            echo "  cleaned\n";
        }

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }
}
