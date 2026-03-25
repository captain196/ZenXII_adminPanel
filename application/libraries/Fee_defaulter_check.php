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
            $path        = "{$this->_schoolBase()}/Accounts/Pending_fees/{$studentId}";
            $pendingFees = $this->firebase->get($path);

            if (!is_array($pendingFees) || empty($pendingFees)) {
                return $result;
            }

            $totalDues       = 0.0;
            $unpaidMonths    = [];
            $overdueMonths   = [];
            $lastPaymentDate = '';

            foreach ($pendingFees as $month => $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $status = strtolower(trim($entry['status'] ?? 'pending'));
                $amount = $this->_toFloat($entry['amount'] ?? 0);

                if ($status === 'pending' || $status === 'overdue') {
                    $totalDues += $amount;

                    $monthInfo = [
                        'month'    => $month,
                        'amount'   => $amount,
                        'status'   => $entry['status'] ?? '',
                        'due_date' => $entry['due_date'] ?? '',
                    ];

                    // Include fee heads if available
                    if (isset($entry['fee_heads']) && is_array($entry['fee_heads'])) {
                        $monthInfo['fee_heads'] = $entry['fee_heads'];
                    }

                    $unpaidMonths[] = $monthInfo;

                    if ($status === 'overdue') {
                        $overdueMonths[] = $monthInfo;
                    }
                }

                // Track the most recent payment date across all entries
                if (!empty($entry['last_payment_date'])) {
                    if ($lastPaymentDate === '' || $entry['last_payment_date'] > $lastPaymentDate) {
                        $lastPaymentDate = $entry['last_payment_date'];
                    }
                }
            }

            $result['total_dues']        = round($totalDues, 2);
            $result['unpaid_months']     = $unpaidMonths;
            $result['overdue_months']    = $overdueMonths;
            $result['last_payment_date'] = $lastPaymentDate;
            $result['is_defaulter']      = ($totalDues > 0 && count($overdueMonths) > 0);

        } catch (\Exception $e) {
            log_message('error', "Fee_defaulter_check::isDefaulter failed for student [{$studentId}]: " . $e->getMessage());
        }

        return $result;
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

            // ── Persist to Firebase ─────────────────────────────────
            $path = "{$this->_schoolBase()}/Fees/Defaulters/{$studentId}";
            $this->firebase->set($path, $status);

            // ── Sync to Firestore for mobile apps ──
            if ($this->fsSync !== null) {
                try {
                    // Resolve student name/class/section from demands (best-effort)
                    $sName = $status['student_name'] ?? '';
                    $sClass = $status['class'] ?? '';
                    $sSection = $status['section'] ?? '';
                    if ($sName === '' || $sClass === '') {
                        $demandsPath = "{$this->_schoolBase()}/Fees/Demands/{$studentId}";
                        $demands = $this->firebase->get($demandsPath);
                        if (is_array($demands)) {
                            $firstDemand = reset($demands);
                            if (is_array($firstDemand)) {
                                if ($sName === '') $sName = $firstDemand['student_name'] ?? '';
                                if ($sClass === '') $sClass = $firstDemand['class'] ?? '';
                                if ($sSection === '') $sSection = $firstDemand['section'] ?? '';
                            }
                        }
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
            $policyPath = "{$this->_schoolBase()}/Accounts/Fees/Defaulter_Policy/result_withhold_threshold";
            $threshold  = $this->firebase->get($policyPath);
            $threshold  = $this->_toFloat($threshold);
            // Default threshold = 0 means ANY dues triggers withholding
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

            // ── Persist to Firebase ─────────────────────────────────
            $path = "{$this->_schoolBase()}/Fees/Clearance/{$studentId}";
            $this->firebase->set($path, $clearance);

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
            $path        = "{$this->_schoolBase()}/Accounts/Pending_fees/{$studentId}";
            $pendingFees = $this->firebase->get($path);

            if (!is_array($pendingFees) || empty($pendingFees)) {
                return; // No pending fees → clear
            }

            $totalDues = 0.0;
            foreach ($pendingFees as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $status = strtolower(trim($entry['status'] ?? 'pending'));
                if ($status === 'pending' || $status === 'overdue') {
                    $totalDues += $this->_toFloat($entry['amount'] ?? 0);
                }
            }

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
            // Unreturned books
            $issuesPath = "{$this->_schoolBase()}/Operations/Library/Issues/{$studentId}";
            $issues     = $this->firebase->get($issuesPath);

            $unreturnedCount = 0;
            if (is_array($issues) && !empty($issues)) {
                foreach ($issues as $issue) {
                    if (!is_array($issue)) {
                        continue;
                    }
                    $returned = $issue['returned'] ?? false;
                    $status   = strtolower(trim($issue['status'] ?? ''));
                    // Count as unreturned if not explicitly marked returned
                    if ($returned === false && $status !== 'returned') {
                        $unreturnedCount++;
                    }
                }
            }

            // Library fines
            $finesPath = "{$this->_schoolBase()}/Operations/Library/Fines/{$studentId}";
            $fines     = $this->firebase->get($finesPath);

            $libraryDues = 0.0;
            if (is_array($fines) && !empty($fines)) {
                foreach ($fines as $fine) {
                    if (!is_array($fine)) {
                        continue;
                    }
                    $paid = strtolower(trim($fine['status'] ?? ''));
                    if ($paid !== 'paid') {
                        $libraryDues += $this->_toFloat($fine['amount'] ?? 0);
                    }
                }
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
            $path   = "{$this->_schoolBase()}/Fees/Student_Fee_Items/{$studentId}/Hostel";
            $hostel = $this->firebase->get($path);

            if (!is_array($hostel) || empty($hostel)) {
                return; // No hostel data → clear
            }

            $hostelDues = 0.0;

            // Handle both single-record and multi-record structures
            $records = isset($hostel['status']) ? [$hostel] : $hostel;

            foreach ($records as $record) {
                if (!is_array($record)) {
                    continue;
                }
                $status = strtolower(trim($record['status'] ?? ''));
                if ($status === 'active' || $status === 'pending') {
                    $total = $this->_toFloat($record['total'] ?? $record['amount'] ?? 0);
                    $paid  = $this->_toFloat($record['paid'] ?? 0);
                    $balance = $total - $paid;
                    if ($balance > 0) {
                        $hostelDues += $balance;
                    }
                }
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
            $path      = "{$this->_schoolBase()}/Fees/Student_Fee_Items/{$studentId}/Transport";
            $transport = $this->firebase->get($path);

            if (!is_array($transport) || empty($transport)) {
                return; // No transport data → clear
            }

            $transportDues = 0.0;

            // Handle both single-record and multi-record structures
            $records = isset($transport['status']) ? [$transport] : $transport;

            foreach ($records as $record) {
                if (!is_array($record)) {
                    continue;
                }
                $status = strtolower(trim($record['status'] ?? ''));
                if ($status === 'active' || $status === 'pending') {
                    $total = $this->_toFloat($record['total'] ?? $record['amount'] ?? 0);
                    $paid  = $this->_toFloat($record['paid'] ?? 0);
                    $balance = $total - $paid;
                    if ($balance > 0) {
                        $transportDues += $balance;
                    }
                }
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
            $defaulterPath = "{$this->_schoolBase()}/Fees/Defaulters/{$studentId}";

            // Build the update payload based on override type
            $updates = [];
            switch ($overrideType) {
                case 'exam_block':
                    $updates['exam_blocked'] = false;
                    break;
                case 'result_withhold':
                    $updates['result_withheld'] = false;
                    break;
                case 'all':
                    $updates['exam_blocked']    = false;
                    $updates['result_withheld'] = false;
                    break;
            }

            $updates['last_override_at'] = date('c');
            $updates['last_override_by'] = $adminId;

            // Apply the override to the Defaulters node
            $writeResult = $this->firebase->update($defaulterPath, $updates);

            if ($writeResult === false) {
                log_message('error', "Fee_defaulter_check::clearDefaulterOverride Firebase update failed for student [{$studentId}]");
                return false;
            }

            // ── Audit log ───────────────────────────────────────────
            $auditEntry = [
                'action'        => 'defaulter_override',
                'override_type' => $overrideType,
                'student_id'    => $studentId,
                'admin_id'      => $adminId,
                'reason'        => $reason,
                'flags_cleared' => $updates,
                'timestamp'     => date('c'),
            ];

            $auditPath = "{$this->_schoolBase()}/Fees/Audit_Logs";
            $this->firebase->push($auditPath, $auditEntry);

            log_message('info', "Fee_defaulter_check: override [{$overrideType}] applied for student [{$studentId}] by admin [{$adminId}]");

            return true;

        } catch (\Exception $e) {
            log_message('error', "Fee_defaulter_check::clearDefaulterOverride failed for student [{$studentId}]: " . $e->getMessage());
            return false;
        }
    }
}
