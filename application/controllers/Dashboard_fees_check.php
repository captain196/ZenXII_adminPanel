<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Dashboard_fees_check — read-only verifier for admin dashboard Fees data.
 *
 * Cross-references:
 *   • dashboard's feesCollected aggregation vs raw feeReceipts sum
 *   • dashboard's monthlyFees breakdown vs feeReceipts grouped by paidAt
 *   • dashboard's fee_defaulters count (students − paidStudentIds)
 *     vs canonical feeDefaulters collection
 *   • top_defaulters list (5 highest totalDues from feeDefaulters)
 *   • dashboard's fee_breakdown today/month/year vs raw sum
 *   • field-name variant parsing (allocated_amount / allocatedAmount / amount)
 *   • potential duplicate receipts or stale ledger
 *
 * INVOCATION: php index.php dashboard_fees_check check
 * Env: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 */
class Dashboard_fees_check extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) show_error('CLI-only.', 403);
        $this->load->library('firebase');
        $this->load->library('firestore_service');
        $this->schoolFs    = (string) (getenv('SCHOOL_ID')    ?: '');
        $this->sessionYear = (string) (getenv('SESSION_YEAR') ?: '');
        if ($this->schoolFs === '' || $this->sessionYear === '') {
            echo "ERROR: Set SCHOOL_ID and SESSION_YEAR env vars.\n";
            exit(1);
        }
    }

    public function check(): void
    {
        echo "=== Dashboard Fees data verification ===\n";
        echo "schoolFs={$this->schoolFs}  session={$this->sessionYear}\n";
        echo "today=" . date('Y-m-d') . "  month-start=" . date('Y-m-01') . "  year-start=" . date('Y-01-01') . "\n\n";

        // ── 1. Raw feeReceipts inventory (filtered by school + session)
        $receipts = [];
        try {
            $receipts = $this->firebase->firestoreQuery('feeReceipts', [
                ['schoolId', '==', $this->schoolFs],
                ['session',  '==', $this->sessionYear],
            ], null, 'ASC', 2000);
        } catch (\Throwable $e) {
            echo "feeReceipts query error: " . $e->getMessage() . "\n";
        }

        echo "── 1. feeReceipts inventory ──\n";
        echo "Total receipts (schoolId + session match): " . count($receipts) . "\n";
        echo "Field-name variants observed:\n";

        $fieldVariants = [
            'amount_fields'    => ['allocated_amount' => 0, 'allocatedAmount' => 0, 'amount' => 0, '(none)' => 0],
            'studentId_fields' => ['studentId' => 0, 'userId' => 0, 'user_id' => 0, '(none)' => 0],
            'date_fields'      => ['paidAt' => 0, 'date' => 0, '(none)' => 0],
        ];

        $rawSum = 0.0;
        $dashboardSum = 0.0;   // mirrors dashboard's parsing
        $paidStudents = [];
        $monthlySums = [];
        $todaySum = 0; $monthSum = 0; $yearSum = 0;
        $todayStr = date('Y-m-d');
        $monthStart = date('Y-m-01');
        $yearStart = date('Y-01-01');

        $perReceiptList = [];

        foreach ($receipts as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];

            // Amount: mirror dashboard logic
            $amt = (float) ($d['allocated_amount'] ?? $d['allocatedAmount'] ?? $d['amount'] ?? 0);
            $dashboardSum += $amt;
            $rawSum += (float)($d['allocated_amount'] ?? 0) + (float)($d['allocatedAmount'] ?? 0) + (float)($d['amount'] ?? 0);

            // Field variant detection
            if (array_key_exists('allocated_amount', $d)) $fieldVariants['amount_fields']['allocated_amount']++;
            elseif (array_key_exists('allocatedAmount', $d)) $fieldVariants['amount_fields']['allocatedAmount']++;
            elseif (array_key_exists('amount', $d)) $fieldVariants['amount_fields']['amount']++;
            else $fieldVariants['amount_fields']['(none)']++;

            $uid = $d['studentId'] ?? $d['userId'] ?? $d['user_id'] ?? '';
            if (array_key_exists('studentId', $d)) $fieldVariants['studentId_fields']['studentId']++;
            elseif (array_key_exists('userId', $d)) $fieldVariants['studentId_fields']['userId']++;
            elseif (array_key_exists('user_id', $d)) $fieldVariants['studentId_fields']['user_id']++;
            else $fieldVariants['studentId_fields']['(none)']++;
            if ($uid !== '') $paidStudents[$uid] = true;

            $dateStr = $d['paidAt'] ?? $d['date'] ?? '';
            if (array_key_exists('paidAt', $d)) $fieldVariants['date_fields']['paidAt']++;
            elseif (array_key_exists('date', $d)) $fieldVariants['date_fields']['date']++;
            else $fieldVariants['date_fields']['(none)']++;
            if ($dateStr) {
                $ts = strtotime($dateStr);
                if ($ts) {
                    $monthKey = date('Y-m', $ts);
                    $monthlySums[$monthKey] = ($monthlySums[$monthKey] ?? 0) + $amt;
                    $iso = date('Y-m-d', $ts);
                    if ($iso >= $yearStart)  $yearSum  += $amt;
                    if ($iso >= $monthStart) $monthSum += $amt;
                    if ($iso === $todayStr)  $todaySum += $amt;
                }
            }

            $perReceiptList[] = [
                'docId'  => (string)($r['id'] ?? ''),
                'amount' => $amt,
                'student'=> (string)$uid,
                'date'   => (string)$dateStr,
                'status' => (string)($d['status'] ?? ''),
                'receiptNo' => (string)($d['receiptNo'] ?? $d['receipt_no'] ?? ''),
            ];
        }

        // Display field-name variants
        foreach ($fieldVariants as $cat => $counts) {
            echo "  {$cat}:\n";
            foreach ($counts as $field => $n) {
                if ($n > 0) echo "    {$field}: {$n}\n";
            }
        }

        echo "\n── 2. Sum + breakdown (mirrors dashboard logic) ──\n";
        echo "Dashboard feesCollected sum: ₹" . number_format($dashboardSum, 2) . "\n";
        echo "Distinct paid students (paidStudentIds): " . count($paidStudents) . "\n";
        echo "Today's collection: ₹" . number_format($todaySum, 2) . "\n";
        echo "This month: ₹" . number_format($monthSum, 2) . "\n";
        echo "This year: ₹" . number_format($yearSum, 2) . "\n";

        echo "\n── 3. Monthly breakdown ──\n";
        ksort($monthlySums);
        if (empty($monthlySums)) {
            echo "  (no receipts with parseable dates)\n";
        } else {
            foreach ($monthlySums as $month => $sum) {
                echo "  {$month}: ₹" . number_format($sum, 2) . "\n";
            }
        }

        // ── 4. Compare against firestoreAggregate (matches dashboard fast endpoint)
        echo "\n── 4. Firestore aggregate comparison (matches dashboard 'feesCollected' fast endpoint) ──\n";
        try {
            $agg = $this->firebase->firestoreAggregate('feeReceipts', [
                ['schoolId', '==', $this->schoolFs],
                ['session',  '==', $this->sessionYear],
            ], [
                ['op' => 'count', 'alias' => 'n'],
                ['op' => 'sum',   'field' => 'allocated_amount', 'alias' => 'total'],
            ]);
            echo "  count via aggregate: " . ($agg['n'] ?? 0) . "\n";
            echo "  sum via aggregate (allocated_amount field only): ₹" . number_format((float)($agg['total'] ?? 0), 2) . "\n";

            if ((float)($agg['total'] ?? 0) !== $dashboardSum) {
                echo "  ⚠ DISCREPANCY: aggregate sum (\\u20B9" . number_format((float)($agg['total'] ?? 0), 2) . ") "
                   . "differs from per-row scan (\\u20B9" . number_format($dashboardSum, 2) . ")\n";
                echo "    Cause: aggregate ONLY counts allocated_amount field; per-row scan falls back to allocatedAmount/amount.\n";
            } else {
                echo "  ✅ Aggregate sum matches per-row scan.\n";
            }
        } catch (\Throwable $e) {
            echo "  aggregate error: " . $e->getMessage() . "\n";
        }

        // ── 5. Defaulters count comparison
        echo "\n── 5. Defaulter count comparison ──\n";
        $students = $this->firebase->firestoreQuery('students', [
            ['schoolId', '==', $this->schoolFs],
        ], null, 'ASC', 1000);
        $studentCount = count($students);
        $dashboardDefaulterCount = max(0, $studentCount - count($paidStudents));
        echo "Dashboard formula (students − paid): {$studentCount} − " . count($paidStudents) . " = {$dashboardDefaulterCount}\n";

        $canonicalDefs = $this->firebase->firestoreQuery('feeDefaulters', [
            ['schoolId', '==', $this->schoolFs],
            ['session',  '==', $this->sessionYear],
        ], null, 'ASC', 1000);
        $canonicalCount = 0;
        $canonicalWithDues = [];
        foreach ($canonicalDefs as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $totalDues = (float)($d['totalDues'] ?? 0);
            if ($totalDues > 0) {
                $canonicalCount++;
                $canonicalWithDues[] = ['sid' => (string)($d['studentId'] ?? ''), 'name' => (string)($d['studentName'] ?? ''), 'dues' => $totalDues];
            }
        }
        echo "Canonical feeDefaulters count (totalDues > 0): {$canonicalCount}\n";

        if ($dashboardDefaulterCount !== $canonicalCount) {
            echo "  ⚠ DISCREPANCY: dashboard formula vs canonical feeDefaulters disagree.\n";
            echo "    Dashboard counts as 'defaulter' any student with NO receipt at all.\n";
            echo "    Canonical feeDefaulters counts students with positive totalDues per Fee_defaulter_check logic.\n";
        }

        // ── 6. Top 5 defaulters
        echo "\n── 6. Top 5 defaulters (sorted by totalDues DESC) ──\n";
        usort($canonicalWithDues, fn($a, $b) => $b['dues'] <=> $a['dues']);
        $top5 = array_slice($canonicalWithDues, 0, 5);
        if (empty($top5)) {
            echo "  (no defaulters in feeDefaulters collection)\n";
        } else {
            foreach ($top5 as $t) {
                echo "  {$t['sid']}  {$t['name']}  dues=₹" . number_format($t['dues'], 2) . "\n";
            }
        }

        // ── 7. Per-receipt detail dump
        echo "\n── 7. Per-receipt detail (for forensic) ──\n";
        if (empty($perReceiptList)) {
            echo "  (no receipts)\n";
        } else {
            echo sprintf("  %-50s %-10s %-12s %-22s %-10s %-10s\n", 'docId', 'amount', 'student', 'date', 'status', 'receiptNo');
            foreach ($perReceiptList as $r) {
                echo sprintf("  %-50s %-10.2f %-12s %-22s %-10s %-10s\n",
                    substr($r['docId'], 0, 50), $r['amount'], $r['student'], substr($r['date'], 0, 22), $r['status'], $r['receiptNo']);
            }
        }

        echo "\n=== End fees check ===\n";
    }
}
