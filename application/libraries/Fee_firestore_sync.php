<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_firestore_sync — Dual-writes fee data from RTDB to Firestore.
 *
 * The admin panel is the source of truth and writes to RTDB. This library
 * mirrors critical fee data to Firestore (named database 'schoolsync')
 * so the Parent and Teacher Android apps can read it in real-time.
 *
 * Firestore collections synced:
 *   - feeStructures   (class/section fee breakdown)
 *   - feeDemands      (monthly fee demands per student)
 *   - feeDefaulters   (defaulter flags per student)
 *   - feeReceipts     (payment receipts per student)
 *
 * All Firestore writes are best-effort: if a sync fails, the RTDB write
 * still succeeds and data will be corrected on the next sync or manual
 * reconciliation.
 *
 * Document ID patterns match what the Android apps expect:
 *   feeStructures:  {schoolCode}_{session}_{className}_{section}
 *   feeDemands:     {schoolCode}_{session}_{studentId}_{month}
 *   feeDefaulters:  {schoolCode}_{studentId}
 *   feeReceipts:    {schoolCode}_{receiptKey}
 */
class Fee_firestore_sync
{
    /** @var object Firebase library instance */
    private $firebase;

    /** @var string School identifier (RTDB key, e.g. SCH_XXXXXX) */
    private $schoolCode;

    /** @var string Academic session (e.g. "2025-2026") */
    private $session;

    /** @var bool Whether initialization succeeded */
    private $ready = false;

    // Firestore collection names — must match Android Constants.Firestore.*
    private const COL_FEE_STRUCTURES  = 'feeStructures';
    private const COL_FEE_DEMANDS     = 'feeDemands';
    private const COL_FEE_DEFAULTERS  = 'feeDefaulters';
    private const COL_FEE_RECEIPTS    = 'feeReceipts';

    // ─── Prefix helpers ────────────────────────────────────────────
    // All databases (RTDB, Firestore, MongoDB) use the SAME format:
    //   "Class 8th" / "Section A" — always with prefix, space, no underscore.
    // These helpers ensure the prefix is always present.

    /**
     * Ensure "Class " prefix: "8th" → "Class 8th", "Class 8th" → "Class 8th"
     */
    private static function _ensureClassPrefix(string $className): string
    {
        $trimmed = trim($className);
        if (stripos($trimmed, 'Class ') === 0) {
            return $trimmed;
        }
        return "Class {$trimmed}";
    }

    /**
     * Ensure "Section " prefix: "A" → "Section A", "Section A" → "Section A"
     */
    private static function _ensureSectionPrefix(string $section): string
    {
        $trimmed = trim($section);
        if (stripos($trimmed, 'Section ') === 0) {
            return $trimmed;
        }
        return "Section {$trimmed}";
    }

    /**
     * Initialise with Firebase instance and school context.
     */
    public function init($firebase, string $schoolCode, string $session): self
    {
        $this->firebase   = $firebase;
        $this->schoolCode = $schoolCode;
        $this->session    = $session;
        $this->ready      = ($firebase !== null && $schoolCode !== '' && $session !== '');

        if (!$this->ready) {
            log_message('error', 'Fee_firestore_sync::init() — missing required params');
        }

        return $this;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  1. FEE STRUCTURE SYNC
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Sync fee structure for a class/section to Firestore.
     *
     * Call after save_updated_fees() in Fees.php writes to RTDB.
     *
     * @param string $className  e.g. "Class 8th"
     * @param string $section    e.g. "Section A"
     * @param array  $feeChart   The full fee chart: ['April' => ['Tuition' => 500, ...], 'Yearly Fees' => [...]]
     */
    public function syncFeeStructure(string $className, string $section, array $feeChart): void
    {
        if (!$this->ready) return;

        try {
            // Ensure "Class " / "Section " prefixes for consistent format across all databases
            $rawClass   = self::_ensureClassPrefix($className);
            $rawSection = self::_ensureSectionPrefix($section);

            $docId = "{$this->schoolCode}_{$this->session}_{$rawClass}_{$rawSection}";

            // Build feeHeads array from the chart
            $feeHeads = [];
            $totalMonthly = 0.0;
            $totalAnnual  = 0.0;
            $seenHeads    = []; // avoid duplicate heads

            // Monthly fees (take April as representative)
            $monthlyFees = $feeChart['April'] ?? [];
            if (is_array($monthlyFees)) {
                foreach ($monthlyFees as $name => $amount) {
                    $amt = floatval($amount);
                    if (!isset($seenHeads[$name])) {
                        $feeHeads[] = [
                            'name'      => $name,
                            'amount'    => $amt,
                            'frequency' => 'monthly',
                        ];
                        $seenHeads[$name] = true;
                    }
                    $totalMonthly += $amt;
                }
            }

            // Yearly fees
            $yearlyFees = $feeChart['Yearly Fees'] ?? [];
            if (is_array($yearlyFees)) {
                foreach ($yearlyFees as $name => $amount) {
                    $amt = floatval($amount);
                    if (!isset($seenHeads[$name])) {
                        $feeHeads[] = [
                            'name'      => $name,
                            'amount'    => $amt,
                            'frequency' => 'annual',
                        ];
                        $seenHeads[$name] = true;
                    }
                    $totalAnnual += $amt;
                }
            }

            $doc = [
                'schoolId'        => $this->schoolCode,
                'session'         => $this->session,
                'className'       => $rawClass,
                'section'         => $rawSection,
                'feeHeads'        => $feeHeads,
                'totalMonthlyFee' => round($totalMonthly, 2),
                'totalAnnualFee'  => round($totalAnnual, 2),
                'updatedAt'       => date('c'),
            ];

            $this->firebase->firestoreSet(self::COL_FEE_STRUCTURES, $docId, $doc);

        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_sync::syncFeeStructure() failed [{$className}/{$section}]: " . $e->getMessage());
        }
    }

    /**
     * Sync fee structure by reading it from RTDB first.
     * Convenience method when you don't have the fee chart in memory.
     */
    public function syncFeeStructureFromRTDB(string $className, string $section): void
    {
        if (!$this->ready) return;

        try {
            $path = "Schools/{$this->schoolCode}/{$this->session}/Accounts/Fees/Classes Fees/{$className}/{$section}";
            $feeChart = $this->firebase->get($path);
            if (is_array($feeChart) && !empty($feeChart)) {
                $this->syncFeeStructure($className, $section, $feeChart);
            }
        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_sync::syncFeeStructureFromRTDB() failed: " . $e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  2. FEE DEMAND SYNC
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Sync all demands for a student+month to a single Firestore document.
     *
     * RTDB stores individual demands per fee-head. The Android app expects
     * one Firestore doc per student+month with all fee heads aggregated
     * into a feeItems map.
     *
     * Call after _generateDemandsForMonth() or submit_fees() updates demands.
     *
     * @param string $studentId
     * @param string $studentName
     * @param string $className
     * @param string $section
     * @param string $monthName    e.g. "April"
     * @param array  $demands      Array of demand data for this student
     *                              (all demands, this method filters by month)
     */
    public function syncDemandsForMonth(
        string $studentId,
        string $studentName,
        string $className,
        string $section,
        string $monthName,
        array  $demands
    ): void {
        if (!$this->ready) return;

        try {
            // Filter demands for this month
            $monthDemands = [];
            foreach ($demands as $did => $d) {
                if (!is_array($d)) continue;
                $period = $d['period'] ?? '';
                // Period format: "April 2025" — extract month name
                $periodMonth = explode(' ', $period)[0] ?? '';
                if ($periodMonth === $monthName) {
                    $monthDemands[$did] = $d;
                }
            }

            if (empty($monthDemands)) return;

            // Aggregate into single Firestore document
            $feeItems       = [];
            $grossAmount    = 0.0;
            $discountAmount = 0.0;
            $netAmount      = 0.0;
            $paidAmount     = 0.0;
            $allPaid        = true;
            $anyPartial     = false;
            $firstDemandId  = '';
            $createdAt      = '';

            foreach ($monthDemands as $did => $d) {
                $head = $d['fee_head'] ?? 'Unknown';
                $feeItems[$head] = round(floatval($d['net_amount'] ?? 0), 2);

                $grossAmount    += floatval($d['original_amount'] ?? 0);
                $discountAmount += floatval($d['discount_amount'] ?? 0);
                $netAmount      += floatval($d['net_amount'] ?? 0);
                $paidAmount     += floatval($d['paid_amount'] ?? 0);

                $st = $d['status'] ?? 'unpaid';
                if ($st !== 'paid') $allPaid = false;
                if ($st === 'partial') $anyPartial = true;

                if ($firstDemandId === '') {
                    $firstDemandId = $d['demand_id'] ?? $did;
                    $createdAt     = $d['created_at'] ?? date('c');
                }
            }

            // Determine aggregate status
            $status = 'unpaid';
            if ($allPaid) {
                $status = 'paid';
            } elseif ($anyPartial || $paidAmount > 0) {
                $status = 'partial';
            }

            // Check if overdue
            $firstDemand = reset($monthDemands);
            if ($status !== 'paid' && isset($firstDemand['due_date'])) {
                $dueTs = strtotime($firstDemand['due_date']);
                if ($dueTs !== false && $dueTs < time()) {
                    $status = 'overdue';
                }
            }

            $docId = "{$this->schoolCode}_{$this->session}_{$studentId}_{$monthName}";

            // Ensure prefixes for consistent format across all databases
            $rawClass   = self::_ensureClassPrefix($className);
            $rawSection = self::_ensureSectionPrefix($section);

            $doc = [
                'schoolId'       => $this->schoolCode,
                'session'        => $this->session,
                'studentId'      => $studentId,
                'studentName'    => $studentName,
                'className'      => $rawClass,
                'section'        => $rawSection,
                'sectionKey'     => "{$rawClass}/{$rawSection}",
                'month'          => $monthName,
                'demandId'       => $firstDemandId,
                'feeItems'       => $feeItems,
                'grossAmount'    => round($grossAmount, 2),
                'discountAmount' => round($discountAmount, 2),
                'fineAmount'     => 0.0,
                'netAmount'      => round($netAmount, 2),
                'paidAmount'     => round($paidAmount, 2),
                'status'         => $status,
                'createdAt'      => $createdAt,
                'updatedAt'      => date('c'),
            ];

            $this->firebase->firestoreSet(self::COL_FEE_DEMANDS, $docId, $doc);

        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_sync::syncDemandsForMonth() failed [{$studentId}/{$monthName}]: " . $e->getMessage());
        }
    }

    /**
     * Sync all demands for a student by reading from RTDB and grouping by month.
     * Convenience method — reads all demands from RTDB and syncs each month.
     */
    public function syncAllDemandsForStudent(
        string $studentId,
        string $studentName,
        string $className,
        string $section
    ): void {
        if (!$this->ready) return;

        try {
            $path = "Schools/{$this->schoolCode}/{$this->session}/Fees/Demands/{$studentId}";
            $allDemands = $this->firebase->get($path);
            if (!is_array($allDemands) || empty($allDemands)) return;

            // Group by month
            $byMonth = [];
            foreach ($allDemands as $did => $d) {
                if (!is_array($d)) continue;
                $period = $d['period'] ?? '';
                $monthName = explode(' ', $period)[0] ?? '';
                if ($monthName === '') continue;
                $byMonth[$monthName][$did] = $d;
            }

            foreach ($byMonth as $monthName => $monthDemands) {
                $this->syncDemandsForMonth(
                    $studentId, $studentName, $className, $section,
                    $monthName, $allDemands // pass all demands, filter happens inside
                );
            }
        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_sync::syncAllDemandsForStudent() failed [{$studentId}]: " . $e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  3. DEFAULTER STATUS SYNC
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Sync defaulter status for a student to Firestore.
     *
     * Call after Fee_defaulter_check::updateDefaulterStatus() writes to RTDB.
     *
     * @param string $studentId
     * @param array  $status      The defaulter status array from updateDefaulterStatus()
     * @param string $studentName Student display name
     * @param string $className   Class name
     * @param string $section     Section name
     */
    public function syncDefaulterStatus(
        string $studentId,
        array  $status,
        string $studentName = '',
        string $className = '',
        string $section = ''
    ): void {
        if (!$this->ready) return;

        try {
            $docId = "{$this->schoolCode}_{$studentId}";

            // Map RTDB status fields to Firestore FeeDefaulterDoc fields
            $unpaidMonths  = [];
            $overdueMonths = [];

            // Extract month names from the unpaid/overdue arrays
            foreach (($status['unpaid_months'] ?? []) as $m) {
                if (is_array($m)) {
                    $unpaidMonths[] = $m['month'] ?? $m['period'] ?? '';
                } elseif (is_string($m)) {
                    $unpaidMonths[] = $m;
                }
            }
            foreach (($status['overdue_months'] ?? []) as $m) {
                if (is_array($m)) {
                    $overdueMonths[] = $m['month'] ?? $m['period'] ?? '';
                } elseif (is_string($m)) {
                    $overdueMonths[] = $m;
                }
            }

            // Ensure prefixes for consistent format across all databases
            $rawClass   = self::_ensureClassPrefix($className);
            $rawSection = self::_ensureSectionPrefix($section);

            $doc = [
                'schoolId'       => $this->schoolCode,
                'session'        => $this->session,
                'studentId'      => $studentId,
                'studentName'    => $studentName,
                'className'      => $rawClass,
                'section'        => $rawSection,
                'totalDues'      => round(floatval($status['total_dues'] ?? 0), 2),
                'unpaidMonths'   => array_values(array_filter($unpaidMonths)),
                'overdueMonths'  => array_values(array_filter($overdueMonths)),
                'examBlocked'    => (bool)($status['exam_blocked'] ?? false),
                'resultWithheld' => (bool)($status['result_withheld'] ?? false),
                'lastPaymentDate' => $status['last_payment_date'] ?? '',
                'flaggedAt'      => $status['updated_at'] ?? date('c'),
            ];

            // If not a defaulter, remove the document instead of keeping stale data
            if (!($status['is_defaulter'] ?? false)) {
                $this->firebase->firestoreDelete(self::COL_FEE_DEFAULTERS, $docId);
            } else {
                $this->firebase->firestoreSet(self::COL_FEE_DEFAULTERS, $docId, $doc);
            }

        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_sync::syncDefaulterStatus() failed [{$studentId}]: " . $e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  4. FEE RECEIPT SYNC
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Sync a fee payment receipt to Firestore.
     *
     * Call after submit_fees() completes the payment in RTDB.
     *
     * @param string $receiptKey    Receipt key (e.g. "R000123" or "F123")
     * @param string $receiptNo     Human-readable receipt number
     * @param string $studentId     Student ID
     * @param string $studentName   Student name
     * @param string $className     Class
     * @param string $section       Section
     * @param float  $amount        Total fee amount collected
     * @param float  $discount      Discount applied
     * @param float  $fine          Fine amount
     * @param string $paymentMode   "Cash", "Online", "Cheque" etc.
     * @param array  $months        Selected months paid for
     * @param array  $allocations   Demand allocations (fee_head => amount breakdown)
     * @param string $collectedBy   Admin who collected
     * @param string $remarks       Payment reference/remarks
     */
    public function syncReceipt(
        string $receiptKey,
        string $receiptNo,
        string $studentId,
        string $studentName,
        string $className,
        string $section,
        float  $amount,
        float  $discount,
        float  $fine,
        string $paymentMode,
        array  $months,
        array  $allocations,
        string $collectedBy,
        string $remarks = ''
    ): void {
        if (!$this->ready) return;

        try {
            $docId = "{$this->schoolCode}_{$receiptKey}";

            // Build feeBreakdown from allocations
            $feeBreakdown = [];
            foreach ($allocations as $alloc) {
                $head = $alloc['fee_head'] ?? 'Unknown';
                $amt  = floatval($alloc['amount'] ?? 0);
                if (isset($feeBreakdown[$head])) {
                    $feeBreakdown[$head] += $amt;
                } else {
                    $feeBreakdown[$head] = $amt;
                }
            }
            // Round all values
            foreach ($feeBreakdown as $h => $v) {
                $feeBreakdown[$h] = round($v, 2);
            }

            // Ensure prefixes for consistent format across all databases
            $rawClass   = self::_ensureClassPrefix($className);
            $rawSection = self::_ensureSectionPrefix($section);

            $doc = [
                'schoolId'     => $this->schoolCode,
                'session'      => $this->session,
                'receiptNo'    => $receiptNo,
                'studentId'    => $studentId,
                'studentName'  => $studentName,
                'className'    => $rawClass,
                'section'      => $rawSection,
                'amount'       => round($amount - $discount + $fine, 2),
                'paymentMode'  => $paymentMode,
                'feeMonths'    => array_values($months),
                'feeBreakdown' => $feeBreakdown,
                'remarks'      => $remarks,
                'collectedBy'  => $collectedBy,
                'createdAt'    => date('c'),
            ];

            $this->firebase->firestoreSet(self::COL_FEE_RECEIPTS, $docId, $doc);

        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_sync::syncReceipt() failed [{$receiptKey}]: " . $e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  5. BULK SYNC (for initial migration / reconciliation)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Sync all fee structures for all classes in the school.
     * Reads from RTDB and writes to Firestore for each class/section.
     */
    public function syncAllFeeStructures(): int
    {
        if (!$this->ready) return 0;

        $synced = 0;
        try {
            $basePath = "Schools/{$this->schoolCode}/{$this->session}/Accounts/Fees/Classes Fees";
            $classes = $this->firebase->shallow_get($basePath);

            foreach ($classes as $className) {
                $sections = $this->firebase->shallow_get("{$basePath}/{$className}");
                foreach ($sections as $section) {
                    $this->syncFeeStructureFromRTDB($className, $section);
                    $synced++;
                }
            }
        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_sync::syncAllFeeStructures() failed: " . $e->getMessage());
        }

        return $synced;
    }

    /**
     * Sync all defaulter records from RTDB to Firestore.
     */
    public function syncAllDefaulters(): int
    {
        if (!$this->ready) return 0;

        $synced = 0;
        try {
            $basePath = "Schools/{$this->schoolCode}/{$this->session}/Fees/Defaulters";
            $studentIds = $this->firebase->shallow_get($basePath);

            foreach ($studentIds as $studentId) {
                $status = $this->firebase->get("{$basePath}/{$studentId}");
                if (!is_array($status)) continue;

                // Try to resolve student name/class from the parent node
                $studentName = $status['student_name'] ?? '';
                $className   = $status['class'] ?? '';
                $section     = $status['section'] ?? '';

                $this->syncDefaulterStatus($studentId, $status, $studentName, $className, $section);
                $synced++;
            }
        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_sync::syncAllDefaulters() failed: " . $e->getMessage());
        }

        return $synced;
    }
}
