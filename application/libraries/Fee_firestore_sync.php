<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_firestore_sync — Firestore-first writer for fee data.
 *
 * Per the system Firestore-first contract (memory/firestore_first_migration.md)
 * Firestore is the canonical store for app-visible fee data. RTDB is mirrored
 * by the controllers AFTER a successful Firestore write.
 *
 * Strictness tiers used by callers:
 *   - Tier A (admin ops: structure, demand generation, recalculation, refund
 *     reversal): caller checks the bool return; if FALSE the caller MUST
 *     abort and NOT touch RTDB.
 *   - Tier B (live payments: submit_fees, online webhook): caller checks the
 *     bool return; if FALSE the caller MUST call queueForRetry() and continue
 *     with the RTDB write so the payment is never lost.
 *
 * Firestore collections synced (must match Android Constants.Firestore.*):
 *   - feeStructures   (class/section fee breakdown)
 *   - feeDemands      (monthly fee demands per student)
 *   - feeDefaulters   (defaulter flags per student)
 *   - feeReceipts     (payment receipts per student)
 *
 * Document ID patterns match what the Android apps expect:
 *   feeStructures:  {schoolCode}_{session}_{className}_{section}
 *   feeDemands:     {schoolCode}_{session}_{studentId}_{month}
 *   feeDefaulters:  {schoolCode}_{session}_{studentId}
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
    // Session A (pure-Firestore submit_fees) additions:
    private const COL_FEE_LOCKS       = 'feeLocks';
    private const COL_FEE_IDEMPOTENCY = 'feeIdempotency';
    private const COL_FEE_RECEIPT_ALLOC = 'feeReceiptAllocations';
    private const COL_FEE_PENDING     = 'feePendingWrites';

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
     * Tier A: callers MUST call this BEFORE the RTDB write and abort
     * the operation if it returns false.
     *
     * @param string $className  e.g. "Class 8th"
     * @param string $section    e.g. "Section A"
     * @param array  $feeChart   The full fee chart: ['April' => ['Tuition' => 500, ...], 'Yearly Fees' => [...]]
     * @return bool true if Firestore write succeeded
     */
    public function syncFeeStructure(string $className, string $section, array $feeChart): bool
    {
        if (!$this->ready) {
            log_message('error', 'Fee_firestore_sync::syncFeeStructure() — not initialized');
            return false;
        }

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

            $ok = $this->firebase->firestoreSet(self::COL_FEE_STRUCTURES, $docId, $doc);
            if (!$ok) {
                log_message('error', "Fee_firestore_sync::syncFeeStructure() — firestoreSet returned false [{$docId}]");
            }
            return (bool) $ok;

        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_sync::syncFeeStructure() failed [{$className}/{$section}]: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Sync fee structure by reading it from RTDB first.
     * Used by the bulk migrator (existing data already in RTDB).
     */
    public function syncFeeStructureFromRTDB(string $className, string $section): bool
    {
        if (!$this->ready) return false;

        try {
            $path = "Schools/{$this->schoolCode}/{$this->session}/Accounts/Fees/Classes Fees/{$className}/{$section}";
            $feeChart = $this->firebase->get($path);
            if (is_array($feeChart) && !empty($feeChart)) {
                return $this->syncFeeStructure($className, $section, $feeChart);
            }
            return true; // nothing to sync is not a failure
        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_sync::syncFeeStructureFromRTDB() failed: " . $e->getMessage());
            return false;
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
    ): bool {
        if (!$this->ready) {
            log_message('error', 'Fee_firestore_sync::syncDemandsForMonth() — not initialized');
            return false;
        }

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

            if (empty($monthDemands)) return true; // nothing to sync is not a failure

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

            $ok = $this->firebase->firestoreSet(self::COL_FEE_DEMANDS, $docId, $doc);
            if (!$ok) {
                log_message('error', "Fee_firestore_sync::syncDemandsForMonth() — firestoreSet returned false [{$docId}]");
            }
            return (bool) $ok;

        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_sync::syncDemandsForMonth() failed [{$studentId}/{$monthName}]: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Sync all demands for a student by reading from RTDB and grouping by month.
     * Returns false if ANY month sync fails.
     */
    public function syncAllDemandsForStudent(
        string $studentId,
        string $studentName,
        string $className,
        string $section
    ): bool {
        if (!$this->ready) return false;

        try {
            $path = "Schools/{$this->schoolCode}/{$this->session}/Fees/Demands/{$studentId}";
            $allDemands = $this->firebase->get($path);
            if (!is_array($allDemands) || empty($allDemands)) return true;

            // Group by month
            $byMonth = [];
            foreach ($allDemands as $did => $d) {
                if (!is_array($d)) continue;
                $period = $d['period'] ?? '';
                $monthName = explode(' ', $period)[0] ?? '';
                if ($monthName === '') continue;
                $byMonth[$monthName][$did] = $d;
            }

            $allOk = true;
            foreach ($byMonth as $monthName => $monthDemands) {
                $ok = $this->syncDemandsForMonth(
                    $studentId, $studentName, $className, $section,
                    $monthName, $allDemands // pass all demands, filter happens inside
                );
                if (!$ok) $allOk = false;
            }
            return $allOk;
        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_sync::syncAllDemandsForStudent() failed [{$studentId}]: " . $e->getMessage());
            return false;
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
    ): bool {
        if (!$this->ready) {
            log_message('error', 'Fee_firestore_sync::syncDefaulterStatus() — not initialized');
            return false;
        }

        try {
            // Canonical doc-ID format: {schoolId}_{session}_{studentId}
            // (matches Fee_dues_check::getDues() which is the authoritative reader).
            $docId = "{$this->schoolCode}_{$this->session}_{$studentId}";

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

            // Dedupe months: Fee_defaulter_check::isDefaulter() emits one
            // entry per *demand* (Tuition / Computer / Library), so a
            // single unpaid month appears 3 times in $unpaidMonths.
            // Apps showing "Months pending: October, October, October,
            // November, November, November…" looks broken. Collapse to
            // unique-month list while preserving original order.
            $doc = [
                'schoolId'       => $this->schoolCode,
                'session'        => $this->session,
                'studentId'      => $studentId,
                'studentName'    => $studentName,
                'className'      => $rawClass,
                'section'        => $rawSection,
                'totalDues'      => round(floatval($status['total_dues'] ?? 0), 2),
                'unpaidMonths'   => array_values(array_unique(array_filter($unpaidMonths))),
                'overdueMonths'  => array_values(array_unique(array_filter($overdueMonths))),
                'examBlocked'    => (bool)($status['exam_blocked'] ?? false),
                'resultWithheld' => (bool)($status['result_withheld'] ?? false),
                'lastPaymentDate' => $status['last_payment_date'] ?? '',
                'flaggedAt'      => $status['updated_at'] ?? date('c'),
            ];

            // If not a defaulter, remove the document instead of keeping stale data
            if (!($status['is_defaulter'] ?? false)) {
                $ok = $this->firebase->firestoreDelete(self::COL_FEE_DEFAULTERS, $docId);
            } else {
                $ok = $this->firebase->firestoreSet(self::COL_FEE_DEFAULTERS, $docId, $doc);
            }
            if (!$ok) {
                log_message('error', "Fee_firestore_sync::syncDefaulterStatus() — write returned false [{$docId}]");
            }
            return (bool) $ok;

        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_sync::syncDefaulterStatus() failed [{$studentId}]: " . $e->getMessage());
            return false;
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
    ): bool {
        if (!$this->ready) {
            log_message('error', 'Fee_firestore_sync::syncReceipt() — not initialized');
            return false;
        }

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

            $ok = $this->firebase->firestoreSet(self::COL_FEE_RECEIPTS, $docId, $doc);
            if (!$ok) {
                log_message('error', "Fee_firestore_sync::syncReceipt() — firestoreSet returned false [{$docId}]");
            }
            return (bool) $ok;

        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_sync::syncReceipt() failed [{$receiptKey}]: " . $e->getMessage());
            return false;
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

                if ($this->syncDefaulterStatus($studentId, $status, $studentName, $className, $section)) {
                    $synced++;
                }
            }
        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_sync::syncAllDefaulters() failed: " . $e->getMessage());
        }

        return $synced;
    }

    /**
     * Sync all demands for every student in the school.
     * Walks Schools/{school}/{session}/Fees/Demands/{studentId}/* and emits one
     * Firestore feeDemands doc per student/month. Idempotent — re-runnable.
     */
    public function syncAllDemandsForAllStudents(): int
    {
        if (!$this->ready) return 0;

        $synced = 0;
        try {
            $basePath = "Schools/{$this->schoolCode}/{$this->session}/Fees/Demands";
            $studentIds = $this->firebase->shallow_get($basePath);
            if (!is_array($studentIds)) return 0;

            foreach ($studentIds as $studentId) {
                $allDemands = $this->firebase->get("{$basePath}/{$studentId}");
                if (!is_array($allDemands) || empty($allDemands)) continue;

                // Group demands by month and resolve student name/class from the first row
                $first       = reset($allDemands);
                $studentName = is_array($first) ? ($first['student_name'] ?? $studentId) : $studentId;
                $className   = is_array($first) ? ($first['class'] ?? '') : '';
                $section     = is_array($first) ? ($first['section'] ?? '') : '';

                $monthsSeen = [];
                foreach ($allDemands as $d) {
                    if (!is_array($d)) continue;
                    $month = explode(' ', $d['period'] ?? '')[0] ?? '';
                    if ($month !== '') $monthsSeen[$month] = true;
                }

                foreach (array_keys($monthsSeen) as $month) {
                    if ($this->syncDemandsForMonth(
                        $studentId, $studentName, $className, $section,
                        $month, $allDemands
                    )) {
                        $synced++;
                    }
                }
            }
        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_sync::syncAllDemandsForAllStudents() failed: " . $e->getMessage());
        }

        return $synced;
    }

    /**
     * Sync all receipt allocations from RTDB to Firestore.
     * Walks Schools/{school}/{session}/Fees/Receipt_Allocations/* and emits one
     * Firestore feeReceipts doc per receipt. Idempotent — re-runnable.
     */
    public function syncAllReceipts(): int
    {
        if (!$this->ready) return 0;

        $synced = 0;
        try {
            $basePath = "Schools/{$this->schoolCode}/{$this->session}/Fees/Receipt_Allocations";
            $receiptKeys = $this->firebase->shallow_get($basePath);
            if (!is_array($receiptKeys)) return 0;

            foreach ($receiptKeys as $receiptKey) {
                $alloc = $this->firebase->get("{$basePath}/{$receiptKey}");
                if (!is_array($alloc)) continue;

                $receiptNo   = $alloc['receipt_no']   ?? str_replace(['F', 'R'], '', $receiptKey);
                $studentId   = $alloc['student_id']   ?? '';
                $studentName = $alloc['student_name'] ?? $studentId;
                $className   = $alloc['class']        ?? '';
                $section     = $alloc['section']      ?? '';
                $amount      = floatval($alloc['total_amount'] ?? 0);
                $discount    = floatval($alloc['discount']     ?? 0);
                $fine        = floatval($alloc['fine']         ?? 0);
                $paymentMode = $alloc['payment_mode'] ?? '';
                $allocations = $alloc['allocations']  ?? [];
                $createdBy   = $alloc['created_by']   ?? 'system';

                // Derive month list from allocations
                $months = [];
                if (is_array($allocations)) {
                    foreach ($allocations as $a) {
                        if (!is_array($a)) continue;
                        $m = explode(' ', $a['period'] ?? '')[0] ?? '';
                        if ($m !== '' && !in_array($m, $months, true)) $months[] = $m;
                    }
                }

                if ($studentId === '') continue;

                if ($this->syncReceipt(
                    $receiptKey, (string)$receiptNo, $studentId, $studentName,
                    $className, $section, $amount, $discount, $fine,
                    $paymentMode, $months, is_array($allocations) ? $allocations : [],
                    $createdBy, ''
                )) {
                    $synced++;
                }
            }
        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_sync::syncAllReceipts() failed: " . $e->getMessage());
        }

        return $synced;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  6. RETRY QUEUE (Tier B fallback)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Queue a failed Firestore write for later retry.
     *
     * Tier B callers (live payments) use this when a sync method returns false:
     * the RTDB write still proceeds (so the payment is recorded) and a queue
     * entry is written to RTDB at:
     *   Schools/{school}/{session}/Fees/Firestore_Sync_Queue/{auto_id}
     *
     * A reconciliation job (or the next firestore_bulk_sync run) drains this
     * queue and re-attempts the writes.
     *
     * @param string $kind     One of: feeStructure, feeDemands, feeDefaulter, feeReceipt
     * @param array  $payload  Arguments needed to replay the sync call
     */
    public function queueForRetry(string $kind, array $payload): void
    {
        if (!$this->ready) return;

        try {
            $entry = [
                'kind'        => $kind,
                'payload'     => $payload,
                'queued_at'   => date('c'),
                'retry_count' => 0,
                'school_code' => $this->schoolCode,
                'session'     => $this->session,
            ];
            $queueId = 'Q_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
            $this->firebase->firestoreSet('feeSyncRetryQueue', "{$this->schoolCode}_{$queueId}", $entry);
        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_sync::queueForRetry({$kind}) failed: " . $e->getMessage());
        }
    }

    /**
     * Drain the retry queue. Returns ['attempted' => N, 'succeeded' => N].
     * Call from firestore_bulk_sync or a cron job.
     */
    public function drainRetryQueue(int $maxEntries = 200): array
    {
        $result = ['attempted' => 0, 'succeeded' => 0];
        if (!$this->ready) return $result;

        try {
            $path = "Schools/{$this->schoolCode}/{$this->session}/Fees/Firestore_Sync_Queue";
            $queue = $this->firebase->get($path);
            if (!is_array($queue)) return $result;

            $count = 0;
            foreach ($queue as $entryId => $entry) {
                if ($count >= $maxEntries) break;
                if (!is_array($entry)) continue;

                $kind    = $entry['kind']    ?? '';
                $payload = $entry['payload'] ?? [];
                if ($kind === '' || !is_array($payload)) continue;

                $result['attempted']++;
                $count++;
                $ok = false;

                try {
                    switch ($kind) {
                        case 'feeStructure':
                            $ok = $this->syncFeeStructure(
                                (string)($payload['className'] ?? ''),
                                (string)($payload['section']   ?? ''),
                                (array) ($payload['feeChart']  ?? [])
                            );
                            break;
                        case 'feeDemands':
                            $ok = $this->syncDemandsForMonth(
                                (string)($payload['studentId']   ?? ''),
                                (string)($payload['studentName'] ?? ''),
                                (string)($payload['className']   ?? ''),
                                (string)($payload['section']     ?? ''),
                                (string)($payload['monthName']   ?? ''),
                                (array) ($payload['demands']     ?? [])
                            );
                            break;
                        case 'feeDefaulter':
                            $ok = $this->syncDefaulterStatus(
                                (string)($payload['studentId']   ?? ''),
                                (array) ($payload['status']      ?? []),
                                (string)($payload['studentName'] ?? ''),
                                (string)($payload['className']   ?? ''),
                                (string)($payload['section']     ?? '')
                            );
                            break;
                        case 'feeReceipt':
                            $ok = $this->syncReceipt(
                                (string)($payload['receiptKey']  ?? ''),
                                (string)($payload['receiptNo']   ?? ''),
                                (string)($payload['studentId']   ?? ''),
                                (string)($payload['studentName'] ?? ''),
                                (string)($payload['className']   ?? ''),
                                (string)($payload['section']     ?? ''),
                                (float) ($payload['amount']      ?? 0),
                                (float) ($payload['discount']    ?? 0),
                                (float) ($payload['fine']        ?? 0),
                                (string)($payload['paymentMode'] ?? ''),
                                (array) ($payload['months']      ?? []),
                                (array) ($payload['allocations'] ?? []),
                                (string)($payload['collectedBy'] ?? 'system'),
                                (string)($payload['remarks']     ?? '')
                            );
                            break;
                    }
                } catch (\Exception $e) {
                    log_message('error', "Fee_firestore_sync::drainRetryQueue({$kind}) replay failed: " . $e->getMessage());
                    $ok = false;
                }

                if ($ok) {
                    $result['succeeded']++;
                    try { $this->firebase->delete($path, $entryId); } catch (\Exception $e) {}
                } else {
                    // Bump retry count so we can detect poison entries later
                    try {
                        $this->firebase->update("{$path}/{$entryId}", [
                            'retry_count'  => intval($entry['retry_count'] ?? 0) + 1,
                            'last_attempt' => date('c'),
                        ]);
                    } catch (\Exception $e) {}
                }
            }
        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_sync::drainRetryQueue() failed: " . $e->getMessage());
        }

        return $result;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  BULK BACKFILL — Phase 2a
    //
    //  One-shot readers that walk existing RTDB data and write the
    //  corresponding Firestore docs for the Phase 1 collections. Safe to
    //  re-run: every backfill is idempotent because each per-record
    //  sync*() is merge-based. Admins trigger via
    //  Fee_management::reconcile_to_firestore.
    // ═══════════════════════════════════════════════════════════════════

    /** Back-fill all scholarship awards from RTDB. */
    public function syncAllScholarshipAwards(): int
    {
        if (!$this->ready) return 0;
        $synced = 0;
        try {
            $path = "Schools/{$this->schoolCode}/{$this->session}/Accounts/Fees/Scholarship Awards";
            $all = $this->firebase->get($path);
            if (!is_array($all)) return 0;
            foreach ($all as $awardId => $award) {
                if (!is_array($award)) continue;
                $ok = $this->syncScholarshipAward((string) $awardId, [
                    'scholarshipId'   => $award['scholarship_id']   ?? '',
                    'scholarshipName' => $award['scholarship_name'] ?? '',
                    'studentId'       => $award['student_id']       ?? '',
                    'studentName'     => $award['student_name']     ?? '',
                    'className'       => $award['class']            ?? '',
                    'section'         => $award['section']          ?? '',
                    'amount'          => $award['amount']           ?? 0,
                    'awardedDate'     => $award['awarded_date']     ?? '',
                    'awardedBy'       => $award['awarded_by']       ?? '',
                ]);
                if ($ok && (($award['status'] ?? 'active') === 'revoked')) {
                    $this->syncScholarshipRevoke((string) $awardId, $award['revoked_by'] ?? '');
                }
                if ($ok) $synced++;
            }
        } catch (\Exception $e) {
            log_message('error', 'syncAllScholarshipAwards failed: ' . $e->getMessage());
        }
        return $synced;
    }

    /** Back-fill all refund records from RTDB. */
    public function syncAllRefunds(): int
    {
        if (!$this->ready) return 0;
        $synced = 0;
        try {
            $path = "Schools/{$this->schoolCode}/{$this->session}/Accounts/Fees/Refunds";
            $all = $this->firebase->get($path);
            if (!is_array($all)) return 0;
            foreach ($all as $refId => $refund) {
                if (!is_array($refund)) continue;
                if ($this->syncRefund((string) $refId, $refund)) $synced++;
            }
        } catch (\Exception $e) {
            log_message('error', 'syncAllRefunds failed: ' . $e->getMessage());
        }
        return $synced;
    }

    // syncAllAdvanceBalances removed in Phase 9 (wallet subsystem gone).

    /** Back-fill cross-session carry-forward records from RTDB. */
    public function syncAllCarryForward(): int
    {
        if (!$this->ready) return 0;
        $synced = 0;
        try {
            $path = "Schools/{$this->schoolCode}/{$this->session}/Fees/Carry_Forward";
            $all = $this->firebase->get($path);
            if (!is_array($all)) return 0;
            foreach ($all as $studentId => $rec) {
                if (!is_array($rec)) continue;
                if ($this->syncCarryForward((string) $studentId, [
                    'previousSession' => $rec['previous_session'] ?? '',
                    'toSession'       => $this->session,
                    'totalDues'       => $rec['total_dues']       ?? 0,
                    'unpaidDetails'   => $rec['unpaid_details']   ?? [],
                    'carriedAt'       => $rec['carried_at']       ?? '',
                    'studentName'     => $rec['student_name']     ?? '',
                ])) $synced++;
            }
        } catch (\Exception $e) {
            log_message('error', 'syncAllCarryForward failed: ' . $e->getMessage());
        }
        return $synced;
    }

    /** Back-fill the receipt dedup index from RTDB. */
    public function syncAllReceiptIndex(): int
    {
        if (!$this->ready) return 0;
        $synced = 0;
        try {
            $path = "Schools/{$this->schoolCode}/{$this->session}/Accounts/Receipt_Index";
            $all = $this->firebase->get($path);
            if (!is_array($all)) return 0;
            foreach ($all as $receiptNo => $rec) {
                if (!is_array($rec)) continue;
                // Normalise RTDB snake_case → camelCase on the way out.
                $ok = $this->syncReceiptIndex((string) $receiptNo, [
                    'reserved'    => !empty($rec['reserved']),
                    'reservedAt'  => $rec['reserved_at'] ?? '',
                    'date'        => $rec['date']        ?? '',
                    'userId'      => $rec['user_id']     ?? '',
                    'className'   => $rec['class']       ?? '',
                    'section'     => $rec['section']     ?? '',
                    'amount'      => $rec['amount']      ?? 0,
                    'txnId'       => $rec['txn_id']      ?? '',
                    'orderId'     => $rec['order_id']    ?? '',
                    'paymentId'   => $rec['payment_id']  ?? '',
                ]);
                if ($ok) $synced++;
            }
        } catch (\Exception $e) {
            log_message('error', 'syncAllReceiptIndex failed: ' . $e->getMessage());
        }
        return $synced;
    }

    /** Back-fill the receipt sequence counter from RTDB. */
    public function syncReceiptCounterFromRTDB(): bool
    {
        if (!$this->ready) return false;
        try {
            $path = "Schools/{$this->schoolCode}/{$this->session}/Accounts/Fees/Counters/receipt_seq";
            $val = $this->firebase->get($path);
            return $this->syncReceiptCounter((int) ($val ?? 0));
        } catch (\Exception $e) {
            log_message('error', 'syncReceiptCounterFromRTDB failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Back-fill discounts + per-student monthFee flags for every student.
     *
     * Walks Schools/{school}/{session}/Class X/Section Y/Students/{id} which
     * is expensive for large schools — caller should run it off-hours or
     * split by class. Returns [discountsSynced, monthFeesSynced].
     */
    public function syncAllDiscountsAndMonthFees(): array
    {
        if (!$this->ready) return [0, 0];
        $discounts = 0;
        $months    = 0;
        try {
            $sessionRoot = "Schools/{$this->schoolCode}/{$this->session}";
            $allKeys     = $this->firebase->shallow_get($sessionRoot);
            if (!is_array($allKeys)) return [0, 0];

            foreach ($allKeys as $classKey) {
                if (strpos($classKey, 'Class ') !== 0) continue;
                $sections = $this->firebase->shallow_get("{$sessionRoot}/{$classKey}");
                if (!is_array($sections)) continue;
                foreach ($sections as $sectionKey) {
                    if (strpos($sectionKey, 'Section ') !== 0) continue;
                    $students = $this->firebase->shallow_get("{$sessionRoot}/{$classKey}/{$sectionKey}/Students");
                    if (!is_array($students)) continue;
                    foreach ($students as $studentId) {
                        if (!is_string($studentId) || $studentId === '') continue;

                        $base = "{$sessionRoot}/{$classKey}/{$sectionKey}/Students/{$studentId}";

                        // Discounts
                        $disc = $this->firebase->get("{$base}/Discount");
                        if (is_array($disc)) {
                            $applied = is_array($disc['Applied'] ?? null) ? array_values($disc['Applied']) : [];
                            $scholarships = [];
                            if (is_array($disc['Scholarships'] ?? null)) {
                                foreach ($disc['Scholarships'] as $awardId => $s) {
                                    if (is_array($s)) $scholarships[$awardId] = $s;
                                }
                            }
                            if ($this->syncDiscount($studentId, [
                                'onDemandDiscount'    => (float) ($disc['OnDemandDiscount']    ?? 0),
                                'scholarshipDiscount' => (float) ($disc['ScholarshipDiscount'] ?? 0),
                                'totalDiscount'       => (float) ($disc['totalDiscount']       ?? 0),
                                'lastPolicyId'        => (string) ($disc['last_policy_id']     ?? ''),
                                'lastPolicyName'      => (string) ($disc['last_policy_name']   ?? ''),
                                'appliedAt'           => (string) ($disc['applied_at']         ?? ''),
                                // Applied history is stored as a keyed list in RTDB; emit each entry.
                                'applied'             => $applied,
                                'scholarships'        => $scholarships,
                            ], [
                                'className' => $classKey,
                                'section'   => $sectionKey,
                            ])) $discounts++;
                        }

                        // Month-fee flags
                        $mf = $this->firebase->get("{$base}/Month Fee");
                        if (is_array($mf) && !empty($mf)) {
                            if ($this->syncStudentMonthFees($studentId, $mf)) $months++;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'syncAllDiscountsAndMonthFees failed: ' . $e->getMessage());
        }
        return [$discounts, $months];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  DISCOUNT & SCHOLARSHIP SYNC (Phase 1.1 — Firestore-only migration)
    //
    //  Collections:
    //    - studentDiscounts    per-student summary + history
    //        Doc ID: {schoolCode}_{studentId}
    //        Fields: onDemandDiscount, scholarshipDiscount, totalDiscount,
    //                lastPolicyId, lastPolicyName, appliedAt,
    //                applied[] (history), scholarships[] (active awards),
    //                updatedAt
    //    - scholarshipAwards   per-award canonical record
    //        Doc ID: {schoolCode}_{awardId}
    //        Fields: scholarshipId, scholarshipName, studentId, studentName,
    //                className, section, amount, awardedDate, awardedBy,
    //                status ("active" | "revoked"), revokedAt, revokedBy,
    //                updatedAt
    //
    //  Tier B (best-effort): callers check bool return and queueForRetry on
    //  failure so the RTDB write still happens. Mirrors the defaulter/receipt
    //  sync pattern.
    // ═══════════════════════════════════════════════════════════════════

    private const COL_STUDENT_DISCOUNTS   = 'studentDiscounts';
    private const COL_SCHOLARSHIP_AWARDS  = 'scholarshipAwards';
    private const COL_FEE_REFUNDS         = 'feeRefunds';
    private const COL_STUDENTS            = 'students';
    private const COL_ADVANCE_BALANCES    = 'studentAdvanceBalances';
    private const COL_CARRY_FORWARD       = 'feeCarryForward';
    private const COL_FEE_COUNTERS        = 'feeCounters';
    private const COL_FEE_RECEIPT_INDEX   = 'feeReceiptIndex';

    /**
     * Sync a student's discount summary to Firestore.
     *
     * The admin panel applies discounts through several paths (single OnDemand,
     * bulk policy, scholarship award / revoke). Each path computes the new
     * totals in-memory and calls here with the same shape so the Firestore
     * doc always reflects the authoritative state.
     *
     * @param string $studentId
     * @param array  $summary {
     *     onDemandDiscount   : float,
     *     scholarshipDiscount: float,
     *     totalDiscount      : float,
     *     lastPolicyId       : string (optional),
     *     lastPolicyName     : string (optional),
     *     appliedAt          : string (optional, ISO-8601),
     *     applied            : array  (optional — single history entry to append)
     *     scholarships       : array  (optional — per-award entries to merge)
     * }
     * @param array $context {studentName?, className?, section?}
     */
    public function syncDiscount(string $studentId, array $summary, array $context = []): bool
    {
        if (!$this->ready) {
            log_message('error', 'Fee_firestore_sync::syncDiscount() — not initialized');
            return false;
        }
        if ($studentId === '') {
            log_message('error', 'Fee_firestore_sync::syncDiscount() — empty studentId');
            return false;
        }

        try {
            $docId = "{$this->schoolCode}_{$studentId}";

            // Read existing doc so we append history rather than overwrite it.
            $existing = [];
            try {
                $existing = $this->firebase->firestoreGet(self::COL_STUDENT_DISCOUNTS, $docId);
                if (!is_array($existing)) $existing = [];
            } catch (\Exception $_) { /* treat as new doc */ }

            // Merge applied history. Accepts:
            //   - single entry (associative array — per-event writer)
            //   - list of entries (numeric-keyed — backfill)
            $appliedHistory = is_array($existing['applied'] ?? null)
                ? $existing['applied']
                : [];
            if (!empty($summary['applied']) && is_array($summary['applied'])) {
                $new = $summary['applied'];
                // Treat as list when keys are all numeric and entries are arrays.
                $isList = array_keys($new) === range(0, count($new) - 1)
                    && !empty($new)
                    && is_array(reset($new));
                if ($isList) {
                    foreach ($new as $entry) {
                        if (is_array($entry)) $appliedHistory[] = $entry;
                    }
                } else {
                    $appliedHistory[] = $new;
                }
            }

            // Merge scholarships map (per-award), indexed by award id.
            $scholarships = is_array($existing['scholarships'] ?? null)
                ? $existing['scholarships']
                : [];
            if (!empty($summary['scholarships']) && is_array($summary['scholarships'])) {
                foreach ($summary['scholarships'] as $awardId => $entry) {
                    if (is_array($entry)) {
                        $scholarships[$awardId] = $entry;
                    }
                }
            }
            // Caller can explicitly remove an award (on revoke) by passing
            // scholarshipsRemove=[awardId, ...]
            if (!empty($summary['scholarshipsRemove']) && is_array($summary['scholarshipsRemove'])) {
                foreach ($summary['scholarshipsRemove'] as $awardId) {
                    unset($scholarships[$awardId]);
                }
            }

            $doc = [
                'schoolId'            => $this->schoolCode,
                'session'             => $this->session,
                'studentId'           => $studentId,
                'studentName'         => (string) ($context['studentName'] ?? $existing['studentName'] ?? ''),
                'className'           => (string) ($context['className']   ?? $existing['className']   ?? ''),
                'section'             => (string) ($context['section']     ?? $existing['section']     ?? ''),
                'onDemandDiscount'    => (float)  ($summary['onDemandDiscount']    ?? $existing['onDemandDiscount']    ?? 0),
                'scholarshipDiscount' => (float)  ($summary['scholarshipDiscount'] ?? $existing['scholarshipDiscount'] ?? 0),
                'totalDiscount'       => (float)  ($summary['totalDiscount']       ?? $existing['totalDiscount']       ?? 0),
                'lastPolicyId'        => (string) ($summary['lastPolicyId']        ?? $existing['lastPolicyId']        ?? ''),
                'lastPolicyName'      => (string) ($summary['lastPolicyName']      ?? $existing['lastPolicyName']      ?? ''),
                'appliedAt'           => (string) ($summary['appliedAt']           ?? $existing['appliedAt']           ?? ''),
                'applied'             => $appliedHistory,
                'scholarships'        => $scholarships,
                'updatedAt'           => date('c'),
            ];

            $ok = $this->firebase->firestoreSet(self::COL_STUDENT_DISCOUNTS, $docId, $doc);
            if (!$ok) {
                log_message('error', "Fee_firestore_sync::syncDiscount() — firestoreSet returned false [{$docId}]");
            }
            return (bool) $ok;

        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_sync::syncDiscount() failed [{$studentId}]: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Sync a scholarship award (issue) to Firestore as a canonical record.
     *
     * @param string $awardId
     * @param array  $award {
     *     scholarshipId, scholarshipName, studentId, studentName,
     *     className, section, amount, awardedDate, awardedBy
     * }
     */
    public function syncScholarshipAward(string $awardId, array $award): bool
    {
        if (!$this->ready) {
            log_message('error', 'Fee_firestore_sync::syncScholarshipAward() — not initialized');
            return false;
        }
        if ($awardId === '') {
            log_message('error', 'Fee_firestore_sync::syncScholarshipAward() — empty awardId');
            return false;
        }

        try {
            $docId = "{$this->schoolCode}_{$awardId}";
            $doc = [
                'schoolId'         => $this->schoolCode,
                'session'          => $this->session,
                'awardId'          => $awardId,
                'scholarshipId'    => (string) ($award['scholarshipId']    ?? ''),
                'scholarshipName'  => (string) ($award['scholarshipName']  ?? ''),
                'studentId'        => (string) ($award['studentId']        ?? ''),
                'studentName'      => (string) ($award['studentName']      ?? ''),
                'className'        => (string) ($award['className']        ?? ''),
                'section'          => (string) ($award['section']          ?? ''),
                'amount'           => (float)  ($award['amount']           ?? 0),
                'awardedDate'      => (string) ($award['awardedDate']      ?? date('c')),
                'awardedBy'        => (string) ($award['awardedBy']        ?? ''),
                'status'           => 'active',
                'updatedAt'        => date('c'),
            ];
            return (bool) $this->firebase->firestoreSet(self::COL_SCHOLARSHIP_AWARDS, $docId, $doc);
        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_sync::syncScholarshipAward() failed [{$awardId}]: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mirror the receipt-number counter to Firestore.
     *
     * RTDB remains authoritative for atomic increments in Phase 1. This
     * mirror exists so Firestore-backed admin views and the future
     * Phase 3 flip (Firestore becomes authoritative via
     * FieldValue.increment transaction) have the current value to start
     * from without a backfill.
     *
     * Doc ID: {schoolCode}_receipt_seq (per session = one counter per school,
     * same as RTDB behaviour; the session boundary is signalled by resetting
     * this counter during year rollover).
     *
     * @param int $nextValue The value written to RTDB after increment.
     */
    public function syncReceiptCounter(int $nextValue): bool
    {
        if (!$this->ready) return false;
        try {
            $docId = "{$this->schoolCode}_receipt_seq";
            return (bool) $this->firebase->firestoreSet(self::COL_FEE_COUNTERS, $docId, [
                'schoolId'  => $this->schoolCode,
                'session'   => $this->session,
                'kind'      => 'receipt_seq',
                'value'     => $nextValue,
                'updatedAt' => date('c'),
            ]);
        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_sync::syncReceiptCounter() failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mirror an entry in the receipt dedup index to Firestore.
     *
     * Doc ID: {schoolCode}_{session}_{receiptNo}
     * Fields:  reservation fields on creation; full voucher metadata on finalize.
     *
     * Called twice per receipt — once on reservation, once on finalize — and
     * must merge so the reservation timestamp isn't lost when finalize writes.
     *
     * @param string $receiptNo
     * @param array  $data   Fields to merge (reservation or finalize payload)
     */
    public function syncReceiptIndex(string $receiptNo, array $data): bool
    {
        if (!$this->ready) return false;
        if ($receiptNo === '') return false;
        try {
            $docId = "{$this->schoolCode}_{$this->session}_{$receiptNo}";

            $existing = [];
            try {
                $existing = $this->firebase->firestoreGet(self::COL_FEE_RECEIPT_INDEX, $docId);
                if (!is_array($existing)) $existing = [];
            } catch (\Exception $_) {}

            $doc = array_merge($existing, [
                'schoolId'   => $this->schoolCode,
                'session'    => $this->session,
                'receiptNo'  => $receiptNo,
                'updatedAt'  => date('c'),
            ], $data);
            return (bool) $this->firebase->firestoreSet(self::COL_FEE_RECEIPT_INDEX, $docId, $doc);
        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_sync::syncReceiptIndex() failed [{$receiptNo}]: " . $e->getMessage());
            return false;
        }
    }

    // syncAdvanceBalance removed in Phase 9 (wallet subsystem gone).

    /**
     * Sync a cross-session carry-forward record to Firestore.
     *
     * Collection: `feeCarryForward`
     * Doc ID:     {schoolCode}_{toSession}_{studentId}
     *
     * Written once per student per year-rollover. `$this->session` is assumed
     * to be the NEW (target) session at call time — if called before session
     * switch, pass $toSession in $data['toSession'].
     *
     * @param string $studentId
     * @param array  $data { previousSession/toSession, totalDues, unpaidDetails, carriedAt, studentName? }
     */
    public function syncCarryForward(string $studentId, array $data): bool
    {
        if (!$this->ready) {
            log_message('error', 'Fee_firestore_sync::syncCarryForward() — not initialized');
            return false;
        }
        if ($studentId === '') return false;

        try {
            $toSession = (string) ($data['toSession'] ?? $this->session);
            $docId = "{$this->schoolCode}_{$toSession}_{$studentId}";
            $doc = [
                'schoolId'         => $this->schoolCode,
                'toSession'        => $toSession,
                'previousSession'  => (string) ($data['previousSession'] ?? $data['previous_session'] ?? ''),
                'studentId'        => $studentId,
                'studentName'      => (string) ($data['studentName']     ?? $data['student_name']      ?? ''),
                'totalDues'        => (float)  ($data['totalDues']       ?? $data['total_dues']        ?? 0),
                'unpaidDetails'    => is_array($data['unpaidDetails'] ?? $data['unpaid_details'] ?? null)
                                        ? ($data['unpaidDetails'] ?? $data['unpaid_details'])
                                        : [],
                'carriedAt'        => (string) ($data['carriedAt']       ?? $data['carried_at']        ?? date('c')),
                'updatedAt'        => date('c'),
            ];
            return (bool) $this->firebase->firestoreSet(self::COL_CARRY_FORWARD, $docId, $doc);
        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_sync::syncCarryForward() failed [{$studentId}]: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update a student's month-fee paid flag in Firestore.
     *
     * Writes `students/{schoolCode}_{studentId}.monthFee.{monthName}` = 0|1.
     * The `monthFee` map is the canonical per-month paid/unpaid marker used
     * by dashboards and the defaulter engine. Admission initialises the map
     * with all twelve months at 0; this method toggles individual months.
     *
     * @param string $studentId
     * @param string $monthName   Full month name ("April"…"March")
     * @param int    $paidFlag    1 = paid, 0 = unpaid
     */
    public function syncStudentMonthFee(string $studentId, string $monthName, int $paidFlag): bool
    {
        return $this->syncStudentMonthFees($studentId, [$monthName => $paidFlag]);
    }

    /**
     * Bulk-update many months for one student in a single Firestore write.
     *
     * Strategy: read the existing `monthFee` map, merge in the updates, write
     * the full map back via `firestoreSet(..., merge=true)`. Avoids dot-path
     * field paths (which the REST client's updateMask treats as literal
     * field names when containing non-identifier characters), so nested
     * writes actually land on the map instead of creating top-level fields.
     *
     * @param string $studentId
     * @param array  $monthFlags  ['April' => 1, 'May' => 0, ...]
     */
    public function syncStudentMonthFees(string $studentId, array $monthFlags): bool
    {
        if (!$this->ready) {
            log_message('error', 'Fee_firestore_sync::syncStudentMonthFees() — not initialized');
            return false;
        }
        if ($studentId === '' || empty($monthFlags)) return false;

        try {
            $docId = "{$this->schoolCode}_{$studentId}";

            $existing = [];
            try {
                $existing = $this->firebase->firestoreGet(self::COL_STUDENTS, $docId);
                if (!is_array($existing)) $existing = [];
            } catch (\Exception $_) {}

            $map = is_array($existing['monthFee'] ?? null) ? $existing['monthFee'] : [];

            $changed = false;
            foreach ($monthFlags as $month => $flag) {
                $m = trim((string) $month);
                if ($m === '') continue;
                $val = ((int) $flag) === 1 ? 1 : 0;
                if (($map[$m] ?? null) !== $val) {
                    $map[$m] = $val;
                    $changed = true;
                }
            }
            if (!$changed) return true; // nothing to write

            return (bool) $this->firebase->firestoreSet(self::COL_STUDENTS, $docId, [
                'monthFee'  => $map,
                'updatedAt' => date('c'),
            ], /* merge */ true);
        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_sync::syncStudentMonthFees() failed [{$studentId}]: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Sync a refund record to Firestore.
     *
     * Doc id: {schoolCode}_{refId}. Merges with any existing doc so status
     * updates (pending → approved → processed / rejected) don't wipe fields
     * written on earlier calls. Pass the full field set on create; pass only
     * changed fields on status updates.
     *
     * @param string $refId     Refund identifier (uniqid from controller)
     * @param array  $refund    Field map; snake_case keys mirror the RTDB shape
     *                          and are normalised to camelCase on the Firestore
     *                          side so Android readers stay uniform.
     */
    public function syncRefund(string $refId, array $refund): bool
    {
        if (!$this->ready) {
            log_message('error', 'Fee_firestore_sync::syncRefund() — not initialized');
            return false;
        }
        if ($refId === '') return false;

        try {
            $docId = "{$this->schoolCode}_{$refId}";

            // Merge with existing so partial updates don't clobber.
            $existing = [];
            try {
                $existing = $this->firebase->firestoreGet(self::COL_FEE_REFUNDS, $docId);
                if (!is_array($existing)) $existing = [];
            } catch (\Exception $_) {}

            // Normalise incoming keys (support both snake_case and camelCase).
            $pick = function(string $camel, string $snake, $default = null) use ($refund, $existing) {
                if (array_key_exists($camel, $refund)) return $refund[$camel];
                if (array_key_exists($snake, $refund)) return $refund[$snake];
                if (array_key_exists($camel, $existing)) return $existing[$camel];
                if (array_key_exists($snake, $existing)) return $existing[$snake];
                return $default;
            };

            $doc = [
                'schoolId'       => $this->schoolCode,
                'session'        => $this->session,
                'refundId'       => $refId,
                'studentId'      => (string) $pick('studentId',     'student_id',     ''),
                'studentName'    => (string) $pick('studentName',   'student_name',   ''),
                'className'      => (string) $pick('className',     'class',          ''),
                'section'        => (string) $pick('section',       'section',        ''),
                'amount'         => (float)  $pick('amount',        'amount',         0),
                'feeTitle'       => (string) $pick('feeTitle',      'fee_title',      ''),
                'receiptNo'      => (string) $pick('receiptNo',     'receipt_no',     ''),
                'reason'         => (string) $pick('reason',        'reason',         ''),
                'status'         => (string) $pick('status',        'status',         'pending'),
                'requestedDate'  => (string) $pick('requestedDate', 'requested_date', ''),
                'reviewedDate'   => (string) $pick('reviewedDate',  'reviewed_date',  ''),
                'processedDate'  => (string) $pick('processedDate', 'processed_date', ''),
                'reviewedBy'     => (string) $pick('reviewedBy',    'reviewed_by',    ''),
                'processedBy'    => (string) $pick('processedBy',   'processed_by',   ''),
                'refundMode'     => (string) $pick('refundMode',    'refund_mode',    ''),
                'remarks'        => (string) $pick('remarks',       'remarks',        ''),
                'updatedAt'      => date('c'),
            ];
            return (bool) $this->firebase->firestoreSet(self::COL_FEE_REFUNDS, $docId, $doc);
        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_sync::syncRefund() failed [{$refId}]: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mark an existing scholarship award record as revoked in Firestore.
     */
    public function syncScholarshipRevoke(string $awardId, string $revokedBy = ''): bool
    {
        if (!$this->ready) {
            log_message('error', 'Fee_firestore_sync::syncScholarshipRevoke() — not initialized');
            return false;
        }
        if ($awardId === '') return false;

        try {
            $docId = "{$this->schoolCode}_{$awardId}";

            // Merge with the existing record so we don't wipe the award fields.
            $existing = [];
            try {
                $existing = $this->firebase->firestoreGet(self::COL_SCHOLARSHIP_AWARDS, $docId);
                if (!is_array($existing)) $existing = [];
            } catch (\Exception $_) {}

            $doc = array_merge($existing, [
                'status'     => 'revoked',
                'revokedAt'  => date('c'),
                'revokedBy'  => $revokedBy,
                'updatedAt'  => date('c'),
            ]);
            return (bool) $this->firebase->firestoreSet(self::COL_SCHOLARSHIP_AWARDS, $docId, $doc);
        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_sync::syncScholarshipRevoke() failed [{$awardId}]: " . $e->getMessage());
            return false;
        }
    }
}
