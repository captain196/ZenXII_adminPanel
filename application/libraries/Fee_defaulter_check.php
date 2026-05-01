<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_defaulter_check — Fee defaulter status, exam eligibility,
 * and comprehensive clearance calculations for TC issuance.
 *
 * Firebase paths (relative to school base):
 *   Fees/Defaulters/{studentId}/
 *   Fees/Clearance/{studentId}/
 *   Accounts/Pending_fees/{studentId}
 *   Fees/Demands/{studentId}/
 *   Fees/Student_Fee_Items/{studentId}/
 *   Fees/Audit_Logs/
 *   Operations/Library/Issues/{studentId}
 *   Operations/Library/Fines/{studentId}
 *   Accounts/Fees/Defaulter_Policy/
 */
class Fee_defaulter_check
{
    private $firebase;
    private $schoolName;
    private $sessionYear;

    /** @var Fee_firestore_sync|null Firestore sync library (injected post-init) */
    private $fsSync;

    /**
     * Initialise the library with a Firebase instance and school context.
     *
     * @param  object $firebase     The Firebase library instance
     * @param  string $schoolName   e.g. 'SpringDale'
     * @param  string $sessionYear  e.g. '2025-2026'
     * @return self
     */
    public function init($firebase, string $schoolName, string $sessionYear): self
    {
        $this->firebase    = $firebase;
        $this->schoolName  = $schoolName;
        $this->sessionYear = $sessionYear;

        // Load Firestore sync library for dual-write
        $CI =& get_instance();
        if (!isset($CI->fsSync)) {
            $CI->load->library('Fee_firestore_sync', null, 'fsSync');
            $CI->fsSync->init($firebase, $schoolName, $sessionYear);
        }
        $this->fsSync = $CI->fsSync;

        return $this;
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    /**
     * Base Firebase path for the current school + session.
     */
    private function _schoolBase(): string
    {
        return "Schools/{$this->schoolName}/{$this->sessionYear}";
    }

    /**
     * Safe float cast — handles null, empty string, non-numeric values.
     */
    private function _toFloat($value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  1. isDefaulter
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Determine whether a student is a fee defaulter.
     *
     * A student is flagged as a defaulter when the total outstanding
     * dues are > 0 AND at least one month carries an "Overdue" status.
     *
     * @param  string $studentId
     * @return array  {is_defaulter, total_dues, unpaid_months, overdue_months, last_payment_date}
     */
    public function isDefaulter(string $studentId): array
    {
        $result = [
            'is_defaulter'     => false,
            'total_dues'       => 0.0,
            'unpaid_months'    => [],
            'overdue_months'   => [],
            'last_payment_date' => '',
        ];

        try {
            // Firestore-only computation. The earlier RTDB Pending_fees node
            // is no longer maintained by parent-app payment flows; reading
            // it would return empty after a parent payment and incorrectly
            // report total_dues=0 / is_defaulter=false. Sourcing from
            // feeDemands keeps Admin / Parent / Teacher all in sync.
            $rows = $this->firebase->firestoreQuery('feeDemands', [
                ['schoolId',  '==', $this->schoolName],
                ['session',   '==', $this->sessionYear],
                ['studentId', '==', $studentId],
            ]);

            if (!is_array($rows) || empty($rows)) {
                return $result;
            }

            // Compute "overdue" cutoff once: any unpaid demand whose period/month
            // is before the current calendar month is treated as overdue.
            // Tied directly to wall-clock; no settings doc lookup needed for
            // the basic flag (admin uses the dueDay setting in the report UI).
            $todayYM = (int) date('Ym');

            $totalDues       = 0.0;
            $unpaidMonths    = [];
            $overdueMonths   = [];
            $lastPaymentDate = '';

            foreach ($rows as $row) {
                $d = is_array($row['data'] ?? null) ? $row['data'] : $row;
                if (!is_array($d)) continue;

                $balance = $this->_toFloat($d['balance'] ?? 0);
                if ($balance <= 0.0) {
                    if (!empty($d['updatedAt']) && (string)$d['updatedAt'] > $lastPaymentDate) {
                        $lastPaymentDate = (string) $d['updatedAt'];
                    }
                    continue;
                }

                $monthLabel = (string) ($d['period'] ?? $d['month'] ?? '');
                $totalDues += $balance;

                $monthInfo = [
                    'month'  => $monthLabel,
                    'amount' => $balance,
                    'status' => (string) ($d['status'] ?? 'unpaid'),
                    'fee_heads' => is_array($d['feeItems'] ?? null) ? $d['feeItems'] : [],
                ];
                $unpaidMonths[] = $monthInfo;

                $demandYM = $this->_periodToYearMonth($monthLabel);
                if ($demandYM > 0 && $demandYM < $todayYM) {
                    $overdueMonths[] = $monthInfo;
                }
            }

            $result['total_dues']        = round($totalDues, 2);
            $result['unpaid_months']     = $unpaidMonths;
            $result['overdue_months']    = $overdueMonths;
            $result['last_payment_date'] = $lastPaymentDate;
            // Tag as defaulter if any unpaid balance exists. The previous
            // rule additionally required at least one overdue month, but
            // that hides freshly-due demands (current month) from the
            // banner — apps want to know about ALL pending dues.
            $result['is_defaulter']      = $totalDues > 0;

        } catch (\Exception $e) {
            log_message('error', "Fee_defaulter_check::isDefaulter failed for student [{$studentId}]: " . $e->getMessage());
        }

        return $result;
    }

    /**
     * Convert a demand period label ("April 2026", "April-2026", "2026-04",
     * "Apr 2026") into a YYYYMM integer for cheap month comparison.
     * Returns 0 when the label can't be parsed.
     */
    private function _periodToYearMonth(string $label): int
    {
        $label = trim($label);
        if ($label === '') return 0;
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $label, $m)) {
            return (int) ($m[1] . str_pad($m[2], 2, '0', STR_PAD_LEFT));
        }
        $ts = strtotime("01 {$label}");
        if ($ts === false) {
            $ts = strtotime($label);
        }
        return $ts ? (int) date('Ym', $ts) : 0;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  2. updateDefaulterStatus
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Evaluate and persist the defaulter status for a student.
     *
     * Writes to Fees/Defaulters/{studentId}/ and returns the written data.
     *
     * @param  string $studentId
     * @param  array  $additionalFlags  Optional admin overrides merged into the record
     * @return array  The status record written to Firebase
     */
    public function updateDefaulterStatus(string $studentId, array $additionalFlags = []): array
    {
        $status = [
            'is_defaulter'    => false,
            'total_dues'      => 0.0,
            'unpaid_months'   => [],
            'overdue_months'  => [],
            'exam_blocked'    => false,
            'result_withheld' => false,
            'updated_at'      => date('c'),
        ];

        try {
            // ── Core defaulter check ────────────────────────────────
            $defaulterInfo = $this->isDefaulter($studentId);

            $status['is_defaulter']   = $defaulterInfo['is_defaulter'];
            $status['total_dues']     = $defaulterInfo['total_dues'];
            $status['unpaid_months']  = $defaulterInfo['unpaid_months'];
            $status['overdue_months'] = $defaulterInfo['overdue_months'];

            // ── Exam-blocked: any unpaid fee head contains "exam" ───
            $status['exam_blocked'] = $this->_hasUnpaidExamFee($defaulterInfo['unpaid_months']);

            // ── Result-withheld: defaulter AND dues exceed threshold ─
            $status['result_withheld'] = $this->_shouldWithholdResult(
                $defaulterInfo['is_defaulter'],
                $defaulterInfo['total_dues']
            );

            // ── Merge any admin-supplied overrides ──────────────────
            if (!empty($additionalFlags)) {
                $status = array_merge($status, $additionalFlags);
                // Ensure timestamp is always current
                $status['updated_at'] = date('c');
            }

            // Phase 5 — Firestore is the SOLE source of truth for
            // defaulter status. The legacy RTDB Defaulters/{studentId}
            // write is removed; every reader (admin panel, parent app,
            // teacher app) already consumes feeDefaulters/{…}.

            // ── Sync to Firestore for mobile apps ──
            if ($this->fsSync !== null) {
                try {
                    $sName    = (string) ($additionalFlags['student_name'] ?? '');
                    $sClass   = (string) ($additionalFlags['class'] ?? '');
                    $sSection = (string) ($additionalFlags['section'] ?? '');

                    if ($sName === '' || $sClass === '') {
                        // Resolve from Firestore `students` collection (RTDB
                        // demands node is no longer maintained for parent-app
                        // payments — would return null and leave the
                        // defaulter doc with empty student metadata).
                        try {
                            $stuDoc = $this->firebase->firestoreGet(
                                'students',
                                "{$this->schoolName}_{$studentId}"
                            );
                            if (is_array($stuDoc)) {
                                if ($sName === '')    $sName    = (string) ($stuDoc['name']      ?? $stuDoc['studentName'] ?? '');
                                if ($sClass === '')   $sClass   = (string) ($stuDoc['className'] ?? '');
                                if ($sSection === '') $sSection = (string) ($stuDoc['section']   ?? '');
                            }
                        } catch (\Exception $_) { /* metadata is best-effort */ }
                    }

                    $this->fsSync->syncDefaulterStatus(
                        $studentId, $status, $sName, $sClass, $sSection
                    );
                } catch (\Exception $fsE) {
                    log_message('error', "Fee_defaulter_check: Firestore sync failed [{$studentId}]: " . $fsE->getMessage());
                }
            }

        } catch (\Exception $e) {
            log_message('error', "Fee_defaulter_check::updateDefaulterStatus failed for student [{$studentId}]: " . $e->getMessage());
        }

        return $status;
    }

    /**
     * Check whether any unpaid month's fee heads include an exam-related fee.
     */
    private function _hasUnpaidExamFee(array $unpaidMonths): bool
    {
        foreach ($unpaidMonths as $monthInfo) {
            // Check the month name itself
            if ($this->_isExamRelated($monthInfo['month'] ?? '')) {
                return true;
            }

            // Check individual fee heads within the month
            if (isset($monthInfo['fee_heads']) && is_array($monthInfo['fee_heads'])) {
                foreach ($monthInfo['fee_heads'] as $head => $detail) {
                    $headName = is_string($head) ? $head : ($detail['name'] ?? '');
                    if ($this->_isExamRelated($headName)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Test whether a string relates to an examination fee.
     */
    private function _isExamRelated(string $name): bool
    {
        $lower = strtolower(trim($name));
        return (strpos($lower, 'exam') !== false || strpos($lower, 'examination') !== false);
    }

    /**
     * Determine if results should be withheld based on the school's policy threshold.
     */
    private function _shouldWithholdResult(bool $isDefaulter, float $totalDues): bool
    {
        if (!$isDefaulter) {
            return false;
        }

        try {
            // Phase 5 — Firestore feeSettings/{schoolId}_defaulter_policy
            // replaces RTDB .../Defaulter_Policy. A missing doc keeps
            // the same default (threshold 0 = withhold on any dues).
            $policy = $this->firebase->firestoreGet(
                'feeSettings',
                "{$this->schoolName}_defaulter_policy"
            );
            $threshold = is_array($policy)
                ? $this->_toFloat($policy['result_withhold_threshold'] ?? 0)
                : 0.0;
        } catch (\Exception $e) {
            log_message('error', "Fee_defaulter_check: could not read withhold threshold: " . $e->getMessage());
            $threshold = 0.0;
        }

        // threshold 0 (or missing) → withhold for any outstanding amount
        if ($threshold <= 0) {
            return $totalDues > 0;
        }

        return $totalDues > $threshold;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  3. checkExamEligibility
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Determine whether a student is eligible to sit an exam.
     *
     * Checks both the specific exam fee and the general defaulter status.
     *
     * @param  string $studentId
     * @param  string $examName   Optional exam identifier to match against fee heads
     * @return array  {eligible, reason, dues, exam_fee_paid}
     */
    public function checkExamEligibility(string $studentId, string $examName = ''): array
    {
        $result = [
            'eligible'      => false,
            'reason'        => '',
            'dues'          => 0.0,
            'exam_fee_paid' => true,
        ];

        try {
            $defaulterInfo = $this->isDefaulter($studentId);
            $result['dues'] = $defaulterInfo['total_dues'];

            // ── Check if specific exam fee is paid ──────────────────
            $examFeePaid = $this->_isExamFeePaid($defaulterInfo['unpaid_months'], $examName);
            $result['exam_fee_paid'] = $examFeePaid;

            if (!$examFeePaid) {
                $result['eligible'] = false;
                $result['reason']   = 'Exam fee not paid';
                return $result;
            }

            // ── Check general defaulter status ──────────────────────
            if ($defaulterInfo['is_defaulter']) {
                $result['eligible'] = false;
                $result['reason']   = 'Outstanding dues of Rs. ' . number_format($defaulterInfo['total_dues'], 2);
                return $result;
            }

            // All clear
            $result['eligible'] = true;
            $result['reason']   = '';

        } catch (\Exception $e) {
            log_message('error', "Fee_defaulter_check::checkExamEligibility failed for student [{$studentId}]: " . $e->getMessage());
            $result['eligible'] = false;
            $result['reason']   = 'Unable to verify fee status';
        }

        return $result;
    }

    /**
     * Determine whether the exam fee is paid (i.e. NOT present in unpaid months).
     *
     * If $examName is empty, checks for any exam-related unpaid head.
     * If $examName is provided, matches specifically against that name.
     */
    private function _isExamFeePaid(array $unpaidMonths, string $examName): bool
    {
        foreach ($unpaidMonths as $monthInfo) {
            // Check month-level match
            if ($this->_matchesExam($monthInfo['month'] ?? '', $examName)) {
                return false; // exam fee found in unpaid → NOT paid
            }

            // Check fee heads within each month
            if (isset($monthInfo['fee_heads']) && is_array($monthInfo['fee_heads'])) {
                foreach ($monthInfo['fee_heads'] as $head => $detail) {
                    $headName = is_string($head) ? $head : ($detail['name'] ?? '');
                    if ($this->_matchesExam($headName, $examName)) {
                        return false;
                    }
                }
            }
        }

        // No unpaid exam fee found → it is paid (or there is no exam fee to begin with)
        return true;
    }

    /**
     * Check if a fee name matches the requested exam.
     *
     * - If $examName is empty, any exam-related fee is a match.
     * - If $examName is provided, it must appear within the fee name
     *   (case-insensitive) OR the fee must be generically exam-related.
     */
    private function _matchesExam(string $feeName, string $examName): bool
    {
        if (!$this->_isExamRelated($feeName)) {
            return false;
        }

        if ($examName === '') {
            return true; // any exam-related head matches
        }

        // Specific exam name match
        return (stripos($feeName, $examName) !== false);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  4. calculateClearanceStatus
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Comprehensive clearance check across Fees, Library, Hostel, and Transport.
     *
     * Designed for TC (Transfer Certificate) issuance — every module must
     * report "clear" before a student can be released.
     *
     * @param  string $studentId
     * @param  string $parentDbKey  The admin/staff ID performing the check
     * @return array  Full clearance record (also written to Firebase)
     */
    public function calculateClearanceStatus(string $studentId, string $parentDbKey = ''): array
    {
        $clearance = [
            'fees_clear'               => true,
            'fees_dues'                => 0.0,
            'library_clear'            => true,
            'library_dues'             => 0.0,
            'library_unreturned_books' => 0,
            'hostel_clear'             => true,
            'hostel_dues'              => 0.0,
            'transport_clear'          => true,
            'transport_dues'           => 0.0,
            'all_clear'                => true,
            'total_dues'               => 0.0,
            'checked_at'               => date('c'),
            'checked_by'               => $parentDbKey,
        ];

        try {
            // ── (a) Fees ────────────────────────────────────────────
            $this->_checkFeesClearance($studentId, $clearance);

            // ── (b) Library ─────────────────────────────────────────
            $this->_checkLibraryClearance($studentId, $clearance);

            // ── (c) Hostel ──────────────────────────────────────────
            $this->_checkHostelClearance($studentId, $clearance);

            // ── (d) Transport ───────────────────────────────────────
            $this->_checkTransportClearance($studentId, $clearance);

            // ── Overall ─────────────────────────────────────────────
            $clearance['total_dues'] = round(
                $clearance['fees_dues']
                + $clearance['library_dues']
                + $clearance['hostel_dues']
                + $clearance['transport_dues'],
                2
            );

            $clearance['all_clear'] = (
                $clearance['fees_clear']
                && $clearance['library_clear']
                && $clearance['hostel_clear']
                && $clearance['transport_clear']
            );

            // Phase 5 — clearance status persisted to Firestore
            // studentClearance/{schoolId}_{studentId} so parent + teacher
            // apps read the same doc the admin just wrote.
            try {
                $this->firebase->firestoreSet(
                    'studentClearance',
                    "{$this->schoolName}_{$studentId}",
                    array_merge($clearance, [
                        'schoolId'  => $this->schoolName,
                        'session'   => $this->sessionYear,
                        'studentId' => $studentId,
                        'updatedAt' => date('c'),
                    ])
                );
            } catch (\Exception $fsE) {
                log_message('error', "Fee_defaulter_check: Firestore clearance write failed [{$studentId}]: " . $fsE->getMessage());
            }

        } catch (\Exception $e) {
            log_message('error', "Fee_defaulter_check::calculateClearanceStatus failed for student [{$studentId}]: " . $e->getMessage());
        }

        return $clearance;
    }

    /**
     * Fees sub-check: sums pending fees.
     */
    private function _checkFeesClearance(string $studentId, array &$clearance): void
    {
        try {
            // Phase 5 — read from Firestore feeDefaulters which IS the
            // canonical "how much does this student owe" source; the old
            // RTDB Accounts/Pending_fees tree is frozen.
            $doc = $this->firebase->firestoreGet(
                'feeDefaulters',
                "{$this->schoolName}_{$this->sessionYear}_{$studentId}"
            );
            $totalDues = is_array($doc) ? $this->_toFloat($doc['totalDues'] ?? 0) : 0.0;
            $clearance['fees_dues']  = round($totalDues, 2);
            $clearance['fees_clear'] = ($totalDues <= 0);
        } catch (\Exception $e) {
            log_message('error', "Fee_defaulter_check::_checkFeesClearance failed for student [{$studentId}]: " . $e->getMessage());
            // On error, conservatively mark as NOT clear
            $clearance['fees_clear'] = false;
        }
    }

    /**
     * Library sub-check: unreturned books + unpaid fines.
     */
    private function _checkLibraryClearance(string $studentId, array &$clearance): void
    {
        try {
            // Phase 5 — unreturned books from Firestore libraryIssues.
            $issues = $this->firebase->firestoreQuery('libraryIssues', [
                ['schoolId',  '==', $this->schoolName],
                ['studentId', '==', $studentId],
            ]);
            $unreturnedCount = 0;
            foreach ((array) $issues as $row) {
                $d = $row['data'] ?? $row;
                if (!is_array($d)) continue;
                $returned = $d['returned'] ?? false;
                $status   = strtolower(trim((string) ($d['status'] ?? '')));
                if ($returned === false && $status !== 'returned') $unreturnedCount++;
            }

            // Phase 5 — library fines from Firestore libraryFines.
            $fines = $this->firebase->firestoreQuery('libraryFines', [
                ['schoolId',  '==', $this->schoolName],
                ['studentId', '==', $studentId],
            ]);
            $libraryDues = 0.0;
            foreach ((array) $fines as $row) {
                $d = $row['data'] ?? $row;
                if (!is_array($d)) continue;
                $paid = strtolower(trim((string) ($d['status'] ?? '')));
                if ($paid !== 'paid') $libraryDues += $this->_toFloat($d['amount'] ?? 0);
            }

            $clearance['library_unreturned_books'] = $unreturnedCount;
            $clearance['library_dues']             = round($libraryDues, 2);
            $clearance['library_clear']            = ($unreturnedCount === 0 && $libraryDues <= 0);

        } catch (\Exception $e) {
            log_message('error', "Fee_defaulter_check::_checkLibraryClearance failed for student [{$studentId}]: " . $e->getMessage());
            // Module may not exist — treat as clear per spec
            $clearance['library_clear']            = true;
            $clearance['library_dues']             = 0.0;
            $clearance['library_unreturned_books'] = 0;
        }
    }

    /**
     * Hostel sub-check: active hostel allocation with unpaid balance.
     */
    private function _checkHostelClearance(string $studentId, array &$clearance): void
    {
        try {
            // Phase 5 — hostel-fee items live in Firestore feeDemands
            // (category=Hostel) via the Student_Fee_Items migration.
            // Reading all demands and filtering by category is a small
            // N, so we do it in-memory.
            $records = $this->firebase->firestoreQuery('feeDemands', [
                ['schoolId',  '==', $this->schoolName],
                ['studentId', '==', $studentId],
                ['category',  '==', 'Hostel'],
            ]);
            $hostelDues = 0.0;
            foreach ((array) $records as $row) {
                $d = $row['data'] ?? $row;
                if (!is_array($d)) continue;
                $status  = strtolower(trim((string) ($d['status'] ?? '')));
                if ($status === 'paid') continue;
                $balance = $this->_toFloat($d['balance'] ?? 0);
                if ($balance > 0) $hostelDues += $balance;
            }

            $clearance['hostel_dues']  = round($hostelDues, 2);
            $clearance['hostel_clear'] = ($hostelDues <= 0);

        } catch (\Exception $e) {
            log_message('error', "Fee_defaulter_check::_checkHostelClearance failed for student [{$studentId}]: " . $e->getMessage());
            // Module may not exist — treat as clear per spec
            $clearance['hostel_clear'] = true;
            $clearance['hostel_dues']  = 0.0;
        }
    }

    /**
     * Transport sub-check: active transport allocation with unpaid balance.
     */
    private function _checkTransportClearance(string $studentId, array &$clearance): void
    {
        try {
            // Phase 5 — transport-fee items live in Firestore feeDemands
            // with category=Transport (same shape as hostel).
            $records = $this->firebase->firestoreQuery('feeDemands', [
                ['schoolId',  '==', $this->schoolName],
                ['studentId', '==', $studentId],
                ['category',  '==', 'Transport'],
            ]);
            $transportDues = 0.0;
            foreach ((array) $records as $row) {
                $d = $row['data'] ?? $row;
                if (!is_array($d)) continue;
                $status  = strtolower(trim((string) ($d['status'] ?? '')));
                if ($status === 'paid') continue;
                $balance = $this->_toFloat($d['balance'] ?? 0);
                if ($balance > 0) $transportDues += $balance;
            }

            $clearance['transport_dues']  = round($transportDues, 2);
            $clearance['transport_clear'] = ($transportDues <= 0);

        } catch (\Exception $e) {
            log_message('error', "Fee_defaulter_check::_checkTransportClearance failed for student [{$studentId}]: " . $e->getMessage());
            // Module may not exist — treat as clear per spec
            $clearance['transport_clear'] = true;
            $clearance['transport_dues']  = 0.0;
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  5. clearDefaulterOverride
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Admin override — lift a specific enforcement flag without clearing the underlying dues.
     *
     * Supported override types:
     *   'exam_block'       — sets exam_blocked to false
     *   'result_withhold'  — sets result_withheld to false
     *   'all'              — clears both flags
     *
     * The override is audit-logged to Fees/Audit_Logs/.
     *
     * @param  string $studentId
     * @param  string $overrideType  One of: 'exam_block', 'result_withhold', 'all'
     * @param  string $adminId       Admin performing the override
     * @param  string $reason        Free-text justification
     * @return bool   True if successfully applied
     */
    public function clearDefaulterOverride(string $studentId, string $overrideType, string $adminId, string $reason): bool
    {
        $validTypes = ['exam_block', 'result_withhold', 'all'];
        if (!in_array($overrideType, $validTypes, true)) {
            log_message('error', "Fee_defaulter_check::clearDefaulterOverride invalid type [{$overrideType}] for student [{$studentId}]");
            return false;
        }

        try {
            // Build the update payload based on override type
            $updates = [];
            switch ($overrideType) {
                case 'exam_block':
                    $updates['examBlocked'] = false;
                    break;
                case 'result_withhold':
                    $updates['resultWithheld'] = false;
                    break;
                case 'all':
                    $updates['examBlocked']    = false;
                    $updates['resultWithheld'] = false;
                    break;
            }
            $updates['lastOverrideAt'] = date('c');
            $updates['lastOverrideBy'] = $adminId;

            // Phase 5 — write the override onto the Firestore
            // feeDefaulters doc (canonical) via merge so we don't
            // clobber totalDues / unpaidMonths.
            $writeResult = $this->firebase->firestoreSet(
                'feeDefaulters',
                "{$this->schoolName}_{$this->sessionYear}_{$studentId}",
                $updates,
                /* merge */ true
            );

            if ($writeResult === false) {
                log_message('error', "Fee_defaulter_check::clearDefaulterOverride Firestore update failed for student [{$studentId}]");
                return false;
            }

            // Phase 5 — audit entry lands in Firestore fee_audit_logs.
            try {
                require_once APPPATH . 'libraries/Fee_audit_logger.php';
                $logger = new Fee_audit_logger(
                    $this->firebase, $this->schoolName, $this->sessionYear
                );
                $logger->record(
                    'update', 'defaulter', $studentId,
                    /* before */ [],
                    $updates,
                    $adminId,
                    ['source' => 'defaulter_override', 'reason' => $reason]
                );
            } catch (\Throwable $_) { /* never fail override on audit */ }

            log_message('info', "Fee_defaulter_check: override [{$overrideType}] applied for student [{$studentId}] by admin [{$adminId}]");

            return true;

        } catch (\Exception $e) {
            log_message('error', "Fee_defaulter_check::clearDefaulterOverride failed for student [{$studentId}]: " . $e->getMessage());
            return false;
        }
    }
}
