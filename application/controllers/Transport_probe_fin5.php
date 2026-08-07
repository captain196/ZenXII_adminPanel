<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_fin5 — non-halting expense auto-post (FIN-5).
 *
 * Operations_accounting::create_journal calls json_error()+exit on a
 * missing/inactive/group account — an UNCATCHABLE halt that killed the whole
 * web request and left the expense stuck 'pending'. Fix: postExpense
 * pre-validates the ledger + counter accounts READ-ONLY; a bad account now
 * degrades to a null return (seam records 'pending', reconciler retries)
 * instead of ever reaching the halting engine path.
 *
 *   G1 _accountPostable: active→true, group→false, inactive→false, missing→false
 *   G2 postExpense with a MISSING ledger account → returns null gracefully
 *      (process continues to the assert — no json_error()+exit halt)
 *
 * Self-teardown. CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_fin5 run
 */
class Transport_probe_fin5 extends CI_Controller
{
    private const COL = 'chartOfAccounts';
    private array $fixtures = [];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) { show_error('CLI-only.', 403); }
        $this->load->library('firebase', null, 'firebase');
    }

    public function run(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') { fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n"); exit(1); }

        if (!class_exists('PayrollAccountingService')) {
            require_once APPPATH . 'services/PayrollAccountingService.php';
        }
        $svc = new \PayrollAccountingService();
        $svc->init($this->firebase, $sid, $sess, 'probe_fin5', $this);

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        $ts = (int) (microtime(true) * 1000);
        $acActive   = "PROBE_FIN5_ACTIVE_{$ts}";
        $acGroup    = "PROBE_FIN5_GROUP_{$ts}";
        $acInactive = "PROBE_FIN5_INACTIVE_{$ts}";
        $acMissing  = "PROBE_FIN5_MISSING_{$ts}";
        $mk = fn(string $c) => "{$sid}_{$c}";

        echo "==================================================\n";
        echo " FIN-5 non-halting expense auto-post probe\n";
        echo " tenant={$sid} session={$sess}\n";
        echo "==================================================\n";

        try {
            $this->firebase->firestoreSet(self::COL, $mk($acActive),   ['code'=>$acActive,'status'=>'active','is_group'=>false], false);
            $this->firebase->firestoreSet(self::COL, $mk($acGroup),    ['code'=>$acGroup,'status'=>'active','is_group'=>true], false);
            $this->firebase->firestoreSet(self::COL, $mk($acInactive), ['code'=>$acInactive,'status'=>'inactive','is_group'=>false], false);
            $this->fixtures = [$mk($acActive), $mk($acGroup), $mk($acInactive)];

            $rm = new \ReflectionMethod($svc, '_accountPostable');
            $rm->setAccessible(true);
            $postable = fn(string $c) => (bool) $rm->invoke($svc, $c);

            echo "\n[G1] _accountPostable classifies accounts correctly\n";
            $ok = $postable($acActive) === true
                && $postable($acGroup) === false
                && $postable($acInactive) === false
                && $postable($acMissing) === false;
            $ok ? $pass("active=true, group=false, inactive=false, missing=false")
                : $fail("classification wrong: active=" . var_export($postable($acActive), true)
                        . " group=" . var_export($postable($acGroup), true)
                        . " inactive=" . var_export($postable($acInactive), true)
                        . " missing=" . var_export($postable($acMissing), true));

            echo "\n[G2] postExpense with a MISSING ledger account → null (no halt)\n";
            $ref = $svc->postExpense([
                'source_doc_id'  => "PROBE_FIN5_EXP_{$ts}",
                'ledger_code'    => $acMissing,          // not in CoA → must short-circuit
                'total_amount'   => 1234.00,
                'expense_date'   => date('Y-m-d'),
                'payment_method' => 'Cash',
                'expense_type'   => 'Toll_Fee',
                'payee_name'     => 'Probe',
                'schoolId'       => $sid,
            ]);
            // Reaching this line at all proves no json_error()+exit fired.
            ($ref === null)
                ? $pass("postExpense returned null gracefully (request would record 'pending', not die)")
                : $fail("expected null, got: " . var_export($ref, true));
        } catch (\Throwable $e) {
            $fail("unexpected exception: " . $e->getMessage());
        } finally {
            echo "\n[TEARDOWN]\n";
            foreach ($this->fixtures as $docId) { try { $this->firebase->firestoreDelete(self::COL, $docId); } catch (\Throwable $e) {} }
            echo "  cleaned\n";
        }

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }

    // PayrollAccountingService::init wants a CI with json_error(); provide a
    // throwing stub so that IF the pre-check ever failed and the engine tried to
    // halt, the probe would surface it as an exception instead of exiting.
    public function json_error($msg = '')
    {
        throw new \RuntimeException('json_error() reached (FIN-5 halt path): ' . (is_string($msg) ? $msg : json_encode($msg)));
    }
}
