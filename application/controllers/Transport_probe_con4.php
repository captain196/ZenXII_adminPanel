<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_con4 — CON-4 rollover fee-failure recoverability probe.
 *
 * Uses a synthetic session pair so no real data is touched. Proves:
 *   G1 rolloverSession actually PROCESSES students (chunk now unwraps nested
 *      schoolWhere rows — previously it read $row['studentId'] at the envelope
 *      level and processed NOBODY)
 *   G2 a student whose fee is created ends with fee_demand_ref = a real demand
 *      id (flip parks 'pending' up front, success overwrites it) — never a stale ref
 *   G3 a student whose fee CANNOT be created (monthly_fee=0 → createModuleFee
 *      returns null) is left fee_demand_ref='pending' — recoverable by
 *      reconcilePendingFees, not silently drained
 *
 * Self-teardown. CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_con4 run
 */
class Transport_probe_con4 extends CI_Controller
{
    private array $cleanup = [];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) { show_error('CLI-only.', 403); }
        $this->load->library('firebase',           null, 'firebase');
        $this->load->library('firestore_service',  null, 'fs');
        $this->load->library('numbering_service',  null, 'numbering');
        $this->load->library('transport_service',  null, 'transport');
        $this->load->library('transport_notifier', null, 'transportNotifier');
        $this->load->library('fee_lifecycle',      null, 'feeLifecycle');
    }

    public function run(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '2026-27');
        if ($sid === '') { fwrite(STDERR, "ERROR: SCHOOL_ID required.\n"); exit(1); }
        $from = '1999-00'; $to = '2000-01';   // synthetic — no real studentRoutes use these
        try {
            $this->fs->init($sid, $sess);
            $this->numbering->init($this->fs, $sid, $sess);
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'transport_probe_con4');
            $this->transportNotifier->init($this->fs, $this->numbering, $this->transport, $sid, $sess, 'transport_probe_con4');
            $this->feeLifecycle->init($this->firebase, $sid, $sess, 'probe_con4');
        } catch (\Throwable $e) { fwrite(STDERR, "ERROR: init — " . $e->getMessage() . "\n"); exit(1); }

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        $ts  = (int) (microtime(true) * 1000);
        $stuA = "PROBE_CON4_A_{$ts}";   // fee will be created
        $stuB = "PROBE_CON4_B_{$ts}";   // monthly_fee=0 → fee create fails → pending
        $rid  = "PROBE_CON4_RT_{$ts}";
        $now  = date('c');
        $readSR = fn(string $id): array => (array) ($this->firebase->firestoreGet('studentRoutes', "{$sid}_{$id}") ?: []);

        echo "==================================================\n";
        echo " CON-4 Rollover Fee-Failure Recoverability Probe\n";
        echo " tenant={$sid}  {$from} -> {$to}\n";
        echo "==================================================\n";

        try {
            foreach ([[$stuA, 2000.0], [$stuB, 0.0]] as [$stu, $fee]) {
                $this->fs->setEntity('students', $stu, ['name'=>'CON4 '.$stu,'className'=>'Class 8th','section'=>'A'], false);
                $this->cleanup[] = ['students', $stu];
                // studentRoutes doc-id MUST be the studentId (how the flip addresses it).
                $this->fs->setEntity('studentRoutes', $stu, [
                    'studentId'=>$stu,'schoolId'=>$sid,'session'=>$from,'route_id'=>$rid,'route_name'=>'CON4 Route',
                    'monthly_fee'=>$fee,'status'=>'Active','fee_demand_ref'=>'STALE_'.$stu,'created_at'=>$now,
                ], false);
                $this->cleanup[] = ['studentRoutes', $stu];
                $this->cleanup[] = ['routeAssignments', "{$stu}__{$from}"];   // rollover archive
            }
            $this->cleanup[] = ['rolloverCheckpoint', "{$sid}_ROLLOVER_{$from}_{$to}"];

            echo "\n[G1] rolloverSession processes the students (chunk unwraps nested rows)\n";
            $guard = 0; $res = ['status'=>'in_progress'];
            do { $res = $this->transport->rolloverSession($from, $to); $guard++; }
            while (($res['status'] ?? '') === 'in_progress' && $guard < 20);
            ((int)($res['processed'] ?? 0) >= 2)
                ? $pass("rollover processed=" . (int)($res['processed'] ?? 0) . " (>=2) — chunk no longer skips everyone")
                : $fail("rollover processed=" . (int)($res['processed'] ?? 0) . " (expected >=2) — chunk still skipping");

            echo "\n[G2] fee-created student → real demand ref (never stale)\n";
            $a = $readSR($stuA);
            $refA = (string)($a['fee_demand_ref'] ?? '');
            if (strpos($refA, 'DEM_') === 0) { $this->cleanup[] = ['__demand__', $refA]; }
            ((string)($a['session'] ?? '') === $to && strpos($refA, 'DEM_') === 0)
                ? $pass("A: session={$to}, fee_demand_ref='{$refA}' (real demand)")
                : $fail("A: session='" . ($a['session'] ?? '') . "' fee_demand_ref='{$refA}' (expected {$to} + DEM_*)");

            echo "\n[G3] fee-failed student (fee=0) → fee_demand_ref='pending' (recoverable)\n";
            $b = $readSR($stuB);
            ((string)($b['session'] ?? '') === $to && (string)($b['fee_demand_ref'] ?? '') === 'pending')
                ? $pass("B: session={$to}, fee_demand_ref='pending' — parked for reconcilePendingFees, not silently drained")
                : $fail("B: session='" . ($b['session'] ?? '') . "' fee_demand_ref='" . ($b['fee_demand_ref'] ?? '') . "' (expected {$to} + pending)");
        } catch (\Throwable $e) {
            $fail("exception: " . $e->getMessage());
        } finally {
            echo "\n[TEARDOWN]\n";
            foreach ($this->cleanup as [$col, $id]) {
                try {
                    if ($col === '__demand__') { $this->firebase->firestoreDelete('feeDemands', $id); }
                    else { $this->fs->removeEntity($col, $id); }
                } catch (\Throwable $e) {}
            }
            echo "  cleaned\n";
        }

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }
}
