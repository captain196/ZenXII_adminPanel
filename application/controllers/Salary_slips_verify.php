<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Salary_slips_verify — hr_payroll Tier 1.2 + 1.3 + 1.4 batch verifier.
 *
 * READ-ONLY CLI tool. Covers three schema-side checks in one sweep:
 *
 *   T1.2: salarySlips canonical schema per [[hr_payroll_canonical_schema]]
 *         - type == "payslip"
 *         - camelCase canon: staffName, staffId, runId, monthKey, month, year,
 *           netPayable, grossEarnings, totalDeductions, workingDays, presentDays,
 *           daysAbsent, lwpDays, paidLeaveDays, status
 *         - earnings/deductions sub-maps with camelCase fields
 *         - snake_case siblings dual-emitted (net_pay, staff_name, working_days, ...)
 *
 *   T1.3: salarySlipRun docs (type == "run") have required `type` + `runId` fields
 *         (per memory bug fix — get_payroll_runs filters on type=='run')
 *
 *   T1.4: Statutory deduction integrity per slip — pfEmployee, esiEmployee,
 *         professionalTax, tds, otherDeductions all present + non-negative;
 *         totalDeductions equals sum of components (within float tolerance)
 *         and netPayable = grossEarnings - totalDeductions (within tolerance)
 *
 * INVOCATION:
 *   php index.php salary_slips_verify verify
 *   Env required: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 *
 * Mutates nothing. Idempotent. Safe to run during live traffic.
 */
class Salary_slips_verify extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    private const FLOAT_TOLERANCE = 0.51;   // ½-rupee rounding tolerance

    /** Required top-level camelCase canonical fields for payslip docs. */
    private const PAYSLIP_CAMEL_FIELDS = [
        'staffId', 'staffName', 'runId', 'schoolId', 'type',
        'month', 'monthKey', 'year',
        'netPayable', 'grossEarnings', 'totalDeductions',
        'workingDays', 'presentDays', 'daysAbsent', 'lwpDays', 'paidLeaveDays',
        'status',
    ];

    /** Snake_case siblings expected to be dual-emitted alongside camelCase. */
    private const PAYSLIP_SNAKE_SIBLINGS = [
        'net_pay', 'staff_name', 'working_days', 'days_worked', 'days_absent',
        'lwp_days', 'paid_leave_days', 'total_deductions', 'gross',
    ];

    /** earnings sub-map expected fields. */
    private const EARNINGS_SUB_FIELDS = ['basic', 'hra', 'da', 'ta', 'medical', 'otherAllowances'];

    /** deductions sub-map expected fields. */
    private const DEDUCTIONS_SUB_FIELDS = [
        'pfEmployee', 'esiEmployee', 'professionalTax', 'tds', 'otherDeductions',
    ];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Salary_slips_verify is CLI-only.', 403);
        }
        $this->load->library('firebase');
        $this->load->library('firestore_service');

        $this->schoolFs    = (string) (getenv('SCHOOL_ID')    ?: '');
        $this->sessionYear = (string) (getenv('SESSION_YEAR') ?: '');
        if ($this->schoolFs === '' || $this->sessionYear === '') {
            echo "ERROR: Set SCHOOL_ID and SESSION_YEAR environment variables.\n";
            exit(1);
        }
    }

    /** CLI: php index.php salary_slips_verify inspect_unknown
     *  Read-only forensic dump of any salarySlips docs whose `type` field
     *  is neither "payslip" nor "run". Used to classify residue/draft/preview
     *  artifacts before any backfill or cleanup decision.
     */
    public function inspect_unknown(): void
    {
        echo "=== salarySlips unknown-type forensic inspection ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo str_repeat('-', 64) . "\n\n";

        try {
            $rows = $this->firebase->firestoreQuery('salarySlips', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
        } catch (\Throwable $e) {
            echo "ERROR: " . $e->getMessage() . "\n";
            return;
        }

        $unknownDocs = [];
        foreach ($rows as $r) {
            $data = is_array($r['data'] ?? null) ? $r['data'] : [];
            $type = (string)($data['type'] ?? '');
            if ($type !== 'payslip' && $type !== 'run') {
                $unknownDocs[] = $r;
            }
        }
        echo "Unknown-type doc count: " . count($unknownDocs) . " (of " . count($rows) . " total)\n\n";
        if (count($unknownDocs) === 0) {
            echo "No unknown-type docs to inspect.\n";
            return;
        }

        foreach ($unknownDocs as $idx => $r) {
            $data  = is_array($r['data'] ?? null) ? $r['data'] : [];
            $docId = (string)($r['id'] ?? '');
            $type  = $data['type'] ?? '(absent)';
            $status = $data['status'] ?? '(absent)';
            $staffId = $data['staffId'] ?? '(absent)';
            $staffName = $data['staffName'] ?? $data['staff_name'] ?? '(absent)';
            $runId = $data['runId'] ?? '(absent)';
            $month = $data['month'] ?? '(absent)';
            $monthKey = $data['monthKey'] ?? '(absent)';
            $createdAt = $data['createdAt'] ?? $data['created_at'] ?? '(absent)';
            $updatedAt = $data['updatedAt'] ?? $data['updated_at'] ?? '(absent)';
            $netPayable = $data['netPayable'] ?? $data['net_pay'] ?? '(absent)';

            echo "[" . ($idx + 1) . "] docId={$docId}\n";
            echo "    type             = " . var_export($type, true) . "\n";
            echo "    status           = " . var_export($status, true) . "\n";
            echo "    staffId          = " . var_export($staffId, true) . "\n";
            echo "    staffName        = " . var_export($staffName, true) . "\n";
            echo "    runId            = " . var_export($runId, true) . "\n";
            echo "    month / monthKey = " . var_export($month, true) . " / " . var_export($monthKey, true) . "\n";
            echo "    createdAt        = " . var_export($createdAt, true) . "\n";
            echo "    updatedAt        = " . var_export($updatedAt, true) . "\n";
            echo "    netPayable       = " . var_export($netPayable, true) . "\n";
            echo "    top-level fields : " . implode(', ', array_keys($data)) . "\n";
            echo "\n";
        }

        echo "=== End inspection ===\n";
    }

    /** CLI: php index.php salary_slips_verify verify */
    public function verify(): void
    {
        echo "=== hr_payroll Tier 1.2 + 1.3 + 1.4 salarySlips Schema Verification ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo str_repeat('-', 64) . "\n\n";

        try {
            $rows = $this->firebase->firestoreQuery('salarySlips', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
        } catch (\Throwable $e) {
            echo "ERROR loading salarySlips: " . $e->getMessage() . "\n";
            return;
        }

        $total = count($rows);
        echo "Total salarySlips docs: {$total}\n\n";

        if ($total === 0) {
            echo "=== T1.2 + 1.3 + 1.4 TRIVIAL PASS — no salarySlips data to verify ===\n";
            echo "Verdict: ✅ NORMAL (vacuous truth — no payroll runs executed in this scope).\n";
            echo "When a payroll run executes, re-run this verifier.\n";
            return;
        }

        // Separate buckets per doc type
        $payslips = [];
        $runs     = [];
        $unknownType = [];

        foreach ($rows as $r) {
            $data = is_array($r['data'] ?? null) ? $r['data'] : [];
            $type = (string)($data['type'] ?? '');
            if ($type === 'payslip') $payslips[] = $r;
            elseif ($type === 'run') $runs[] = $r;
            else $unknownType[] = $r;
        }

        printf("Doc-type distribution: payslip=%d  run=%d  unknown=%d\n\n",
            count($payslips), count($runs), count($unknownType));

        // ── T1.3: run docs MUST have type + runId ──────────────────────────
        echo "─── T1.3: salarySlipRun docs ───\n";
        $runMissingType  = 0;
        $runMissingRunId = 0;
        foreach ($rows as $r) {
            $data = is_array($r['data'] ?? null) ? $r['data'] : [];
            $docId = (string)($r['id'] ?? '');
            // Per memory: the bug fix added `type: "run"` requirement. If doc-id
            // starts with "{schoolId}_RUN_" it's expected to be a run doc.
            $looksLikeRun = (strpos($docId, "{$this->schoolFs}_RUN_") === 0);
            if ($looksLikeRun) {
                if (($data['type'] ?? '') !== 'run') $runMissingType++;
                if (empty($data['runId']))            $runMissingRunId++;
            }
        }
        echo "  Detected run-id-pattern docs: " . (count($runs) + $runMissingType) . "\n";
        echo "  Missing type=='run' on run-pattern docs: {$runMissingType}\n";
        echo "  Missing runId field on run-pattern docs: {$runMissingRunId}\n";

        // ── T1.2: payslip canonical schema ─────────────────────────────────
        echo "\n─── T1.2: payslip canonical schema ───\n";
        $payslipCamelMissing   = []; // doc => list of missing camel fields
        $payslipSnakeMissing   = []; // doc => list of missing snake siblings
        $payslipEarningsMissing = []; // doc => list of missing sub-fields
        $payslipDeductionsMissing = [];

        foreach ($payslips as $p) {
            $data = is_array($p['data'] ?? null) ? $p['data'] : [];
            $docId = (string)($p['id'] ?? '');
            $missingCamel = [];
            foreach (self::PAYSLIP_CAMEL_FIELDS as $f) {
                if (!array_key_exists($f, $data) || $data[$f] === null || $data[$f] === '') {
                    $missingCamel[] = $f;
                }
            }
            $missingSnake = [];
            foreach (self::PAYSLIP_SNAKE_SIBLINGS as $f) {
                if (!array_key_exists($f, $data)) $missingSnake[] = $f;
            }
            $earnings = is_array($data['earnings'] ?? null) ? $data['earnings'] : [];
            $missingEarnings = [];
            foreach (self::EARNINGS_SUB_FIELDS as $f) {
                if (!array_key_exists($f, $earnings)) $missingEarnings[] = $f;
            }
            $deductions = is_array($data['deductions'] ?? null) ? $data['deductions'] : [];
            $missingDeductions = [];
            foreach (self::DEDUCTIONS_SUB_FIELDS as $f) {
                if (!array_key_exists($f, $deductions)) $missingDeductions[] = $f;
            }
            if (!empty($missingCamel))      $payslipCamelMissing[$docId] = $missingCamel;
            if (!empty($missingSnake))      $payslipSnakeMissing[$docId] = $missingSnake;
            if (!empty($missingEarnings))   $payslipEarningsMissing[$docId] = $missingEarnings;
            if (!empty($missingDeductions)) $payslipDeductionsMissing[$docId] = $missingDeductions;
        }

        echo "  payslip docs scanned: " . count($payslips) . "\n";
        echo "  missing camelCase fields: " . count($payslipCamelMissing) . " docs affected\n";
        foreach ($payslipCamelMissing as $id => $miss) echo "    - {$id}: " . implode(',', $miss) . "\n";
        echo "  missing snake_case siblings: " . count($payslipSnakeMissing) . " docs affected\n";
        foreach ($payslipSnakeMissing as $id => $miss) echo "    - {$id}: " . implode(',', $miss) . "\n";
        echo "  missing earnings sub-fields: " . count($payslipEarningsMissing) . " docs affected\n";
        foreach ($payslipEarningsMissing as $id => $miss) echo "    - {$id}: " . implode(',', $miss) . "\n";
        echo "  missing deductions sub-fields: " . count($payslipDeductionsMissing) . " docs affected\n";
        foreach ($payslipDeductionsMissing as $id => $miss) echo "    - {$id}: " . implode(',', $miss) . "\n";

        // ── T1.4: statutory deduction integrity ────────────────────────────
        echo "\n─── T1.4: statutory deduction integrity ───\n";
        $negativeDeductions = [];
        $negativeNetPayable = [];
        $totalDeductionsMismatch = [];
        $netPayableMismatch = [];

        foreach ($payslips as $p) {
            $data = is_array($p['data'] ?? null) ? $p['data'] : [];
            $docId = (string)($p['id'] ?? '');
            $deductions = is_array($data['deductions'] ?? null) ? $data['deductions'] : [];

            // Negative deduction check
            foreach (self::DEDUCTIONS_SUB_FIELDS as $f) {
                $v = $deductions[$f] ?? null;
                if (is_numeric($v) && (float)$v < 0) {
                    $negativeDeductions[] = "{$docId} — deductions.{$f}=" . $v;
                }
            }

            $netPayable = (float)($data['netPayable'] ?? 0);
            $grossEarnings = (float)($data['grossEarnings'] ?? 0);
            $totalDeductions = (float)($data['totalDeductions'] ?? 0);

            // Negative net pay
            if ($netPayable < 0) {
                $negativeNetPayable[] = "{$docId} — netPayable=" . $netPayable;
            }

            // totalDeductions ≈ Σ deductions sub-fields
            if (!empty($deductions)) {
                $sumDeductions = 0.0;
                foreach (self::DEDUCTIONS_SUB_FIELDS as $f) {
                    if (is_numeric($deductions[$f] ?? null)) {
                        $sumDeductions += (float)$deductions[$f];
                    }
                }
                if (abs($sumDeductions - $totalDeductions) > self::FLOAT_TOLERANCE) {
                    $totalDeductionsMismatch[] = "{$docId} — totalDeductions={$totalDeductions} vs Σdeductions=" . round($sumDeductions, 2);
                }
            }

            // netPayable ≈ grossEarnings - totalDeductions
            $expectedNet = $grossEarnings - $totalDeductions;
            if (abs($expectedNet - $netPayable) > self::FLOAT_TOLERANCE && ($grossEarnings > 0 || $totalDeductions > 0)) {
                $netPayableMismatch[] = "{$docId} — netPayable={$netPayable} vs gross-deductions=" . round($expectedNet, 2);
            }
        }

        echo "  Negative deduction values: " . count($negativeDeductions) . "\n";
        foreach ($negativeDeductions as $row) echo "    - {$row}\n";
        echo "  Negative netPayable: " . count($negativeNetPayable) . "\n";
        foreach ($negativeNetPayable as $row) echo "    - {$row}\n";
        echo "  totalDeductions sum mismatch: " . count($totalDeductionsMismatch) . "\n";
        foreach ($totalDeductionsMismatch as $row) echo "    - {$row}\n";
        echo "  netPayable arithmetic mismatch: " . count($netPayableMismatch) . "\n";
        foreach ($netPayableMismatch as $row) echo "    - {$row}\n";

        // ── Consolidated verdict ───────────────────────────────────────────
        $t13_drift = $runMissingType + $runMissingRunId;
        $t12_drift = count($payslipCamelMissing) + count($payslipEarningsMissing) + count($payslipDeductionsMissing);
        $t12_snake_drift = count($payslipSnakeMissing);
        $t14_critical = count($negativeNetPayable) + count($negativeDeductions);
        $t14_arith = count($totalDeductionsMismatch) + count($netPayableMismatch);

        echo "\n─── Consolidated verdict ───\n";
        echo "T1.2 (canonical schema):\n";
        if ($t12_drift === 0 && $t12_snake_drift === 0) {
            echo "  ✅ NORMAL — all " . count($payslips) . " payslips fully canonical (camelCase + snake_case dual-emit)\n";
        } elseif ($t12_drift === 0) {
            echo "  ⚠ WATCH — camelCase canon clean; {$t12_snake_drift} docs missing snake_case siblings (dual-emit gap)\n";
        } else {
            echo "  ⚠ INVESTIGATE — {$t12_drift} docs missing camelCase canonical fields; {$t12_snake_drift} missing snake siblings\n";
        }

        echo "T1.3 (run doc fields):\n";
        if ($t13_drift === 0) {
            echo "  ✅ NORMAL — all run docs have type='run' + runId\n";
        } else {
            echo "  🛑 FREEZE_REQUIRED — {$t13_drift} run docs missing required fields (per memory bug fix get_payroll_runs filters on type=='run')\n";
        }

        echo "T1.4 (statutory deduction integrity):\n";
        if ($t14_critical === 0 && $t14_arith === 0) {
            echo "  ✅ NORMAL — all deductions non-negative; net pay = gross - deductions within tolerance\n";
        } elseif ($t14_critical > 0) {
            echo "  🛑 FREEZE_REQUIRED — {$t14_critical} negative-value violations (negative deduction or negative netPayable)\n";
        } else {
            echo "  ⚠ INVESTIGATE — {$t14_arith} arithmetic-coherence mismatches (sums don't reconcile within ±" . self::FLOAT_TOLERANCE . ")\n";
        }

        echo "\n=== End verification ===\n";
    }
}
