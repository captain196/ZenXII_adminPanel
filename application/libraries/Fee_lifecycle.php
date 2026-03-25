<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_lifecycle — Handles all fee lifecycle events triggered by student
 * management operations (admission, promotion, module enrolment, etc.).
 *
 * Called from: Sis.php, Transport.php, Hostel.php, Classes.php
 *
 * Firebase paths used:
 *   Schools/{school}/{session}/Accounts/Fees/                     (fee config)
 *   Schools/{school}/{session}/Accounts/Fees/Classes Fees/{c}/{s}/ (fee structure)
 *   Schools/{school}/{session}/Accounts/Pending_fees/{studentId}  (pending)
 *   Schools/{school}/{session}/Fees/Demands/{studentId}/          (demands)
 *   Schools/{school}/{session}/Fees/Archived_Demands/{studentId}/ (archive)
 *   Schools/{school}/{session}/Fees/Student_Fee_Items/{studentId}/ (modules)
 *   Users/Parents/{parentDbKey}/{studentId}/Fees Record/          (payment history)
 */
class Fee_lifecycle
{
    private $firebase;
    private $schoolName;
    private $sessionYear;
    private $adminId;

    /** @var Fee_firestore_sync|null Firestore sync library */
    private $fsSync;

    /** Frequency → number of instalments per academic year */
    private const FREQUENCY_MAP = [
        'Monthly'   => 12,
        'Quarterly' => 4,
        'Annual'    => 1,
        'One-time'  => 1,
    ];

    /** Academic year starts in April (month 4) for Indian schools */
    private const ACADEMIC_START_MONTH = 4;

    // ─── Initialisation ──────────────────────────────────────────────

    /**
     * Initialise the library with Firebase and context.
     *
     * @param  object $firebase     Loaded Firebase library instance
     * @param  string $schoolName   School identifier in the DB tree
     * @param  string $sessionYear  E.g. "2025-2026"
     * @param  string $adminId      ID of the admin performing the action
     * @return self
     */
    public function init($firebase, string $schoolName, string $sessionYear, string $adminId = 'system'): self
    {
        $this->firebase    = $firebase;
        $this->schoolName  = $schoolName;
        $this->sessionYear = $sessionYear;
        $this->adminId     = $adminId;

        // Load Firestore sync library for dual-write
        $CI =& get_instance();
        if (!isset($CI->fsSync)) {
            $CI->load->library('Fee_firestore_sync', null, 'fsSync');
            $CI->fsSync->init($firebase, $schoolName, $sessionYear);
        }
        $this->fsSync = $CI->fsSync;

        return $this;
    }

    // ─── Path helpers ────────────────────────────────────────────────

    /** Base school-session path. */
    private function _schoolBase(): string
    {
        return "Schools/{$this->schoolName}/{$this->sessionYear}";
    }

    /** Fees module root. */
    private function _feesBase(): string
    {
        return $this->_schoolBase() . '/Fees';
    }

    /** Accounts / Fees config root (fee structures, sibling discount etc.). */
    private function _accountsFeesBase(): string
    {
        return $this->_schoolBase() . '/Accounts/Fees';
    }

    /** Fee structure for a class + section. */
    private function _feeStructurePath(string $class, string $section): string
    {
        return $this->_accountsFeesBase() . "/Classes Fees/{$class}/{$section}";
    }

    /** Demands node for a student. */
    private function _demandsPath(string $studentId): string
    {
        return $this->_feesBase() . "/Demands/{$studentId}";
    }

    /** Pending fees node for a student. */
    private function _pendingFeesPath(string $studentId): string
    {
        return $this->_schoolBase() . "/Accounts/Pending_fees/{$studentId}";
    }

    /** Student fee items (Transport / Hostel modules). */
    private function _studentFeeItemsPath(string $studentId): string
    {
        return $this->_feesBase() . "/Student_Fee_Items/{$studentId}";
    }

    /** Archived demands for a student in a specific class. */
    private function _archivedDemandsPath(string $studentId, string $class = ''): string
    {
        $base = $this->_feesBase() . "/Archived_Demands/{$studentId}";
        return $class !== '' ? "{$base}/{$class}" : $base;
    }

    // ═════════════════════════════════════════════════════════════════
    //  1. assignInitialFees
    // ═════════════════════════════════════════════════════════════════

    /**
     * Read the fee structure for a class/section and create demands +
     * pending-fee entries for a student.
     *
     * @param  string $studentId    Student UID
     * @param  string $class        Class name (e.g. "10")
     * @param  string $section      Section name (e.g. "A")
     * @param  string $parentDbKey  Parent node key (for cross-ref; optional)
     * @return array  List of assigned fee-head names, or empty on failure
     */
    public function assignInitialFees(string $studentId, string $class, string $section, string $parentDbKey = ''): array
    {
        $assigned = [];

        try {
            // ── Read fee structure ──
            $structurePath = $this->_feeStructurePath($class, $section);
            $feeHeads = $this->firebase->get($structurePath);

            if (empty($feeHeads) || !is_array($feeHeads)) {
                log_message('info', "Fee_lifecycle::assignInitialFees — No fee structure found at [{$structurePath}] for student [{$studentId}]");
                return [];
            }

            $now = date('c');

            foreach ($feeHeads as $feeKey => $feeHead) {
                if (!is_array($feeHead)) {
                    continue;
                }

                $headName   = $feeHead['name']      ?? $feeKey;
                $amount     = floatval($feeHead['amount'] ?? 0);
                $frequency  = $feeHead['frequency'] ?? 'Annual';
                $dueDay     = intval($feeHead['due_day'] ?? 1);

                if ($amount <= 0) {
                    continue;
                }

                // ── Create demand ──
                $demandData = [
                    'fee_key'       => $feeKey,
                    'fee_head'      => $headName,
                    'amount'        => $amount,
                    'frequency'     => $frequency,
                    'class'         => $class,
                    'section'       => $section,
                    'status'        => 'active',
                    'created_at'    => $now,
                    'created_by'    => $this->adminId,
                    'parent_db_key' => $parentDbKey,
                ];

                $demandId = $this->firebase->push($this->_demandsPath($studentId), $demandData);

                if (empty($demandId)) {
                    log_message('error', "Fee_lifecycle::assignInitialFees — Failed to push demand for [{$headName}] student [{$studentId}]");
                    continue;
                }

                // ── Create pending-fee entries based on frequency ──
                $this->_createPendingEntries(
                    $studentId,
                    $demandId,
                    $headName,
                    $amount,
                    $frequency,
                    $dueDay,
                    $now
                );

                $assigned[] = $headName;
            }

            // ── Log the operation ──
            $this->_log('initial_fees_assigned', $studentId, [
                'class'          => $class,
                'section'        => $section,
                'fee_heads'      => $assigned,
                'count'          => count($assigned),
                'parent_db_key'  => $parentDbKey,
            ]);

            // ── Sync new demands to Firestore for mobile apps ──
            if ($this->fsSync !== null && !empty($assigned)) {
                try {
                    // Resolve student name from parent node (best-effort)
                    $studentName = '';
                    if ($parentDbKey !== '') {
                        $parentData = $this->firebase->get("Users/Parents/{$parentDbKey}/{$studentId}/Name");
                        $studentName = is_string($parentData) ? $parentData : '';
                    }
                    $this->fsSync->syncAllDemandsForStudent(
                        $studentId, $studentName, $class, $section
                    );
                } catch (\Exception $fsE) {
                    log_message('error', "Fee_lifecycle::assignInitialFees Firestore sync failed [{$studentId}]: " . $fsE->getMessage());
                }
            }

        } catch (\Exception $e) {
            log_message('error', 'Fee_lifecycle::assignInitialFees failed: ' . $e->getMessage());
        }

        return $assigned;
    }

    // ═════════════════════════════════════════════════════════════════
    //  2. reassignFeesOnPromotion
    // ═════════════════════════════════════════════════════════════════

    /**
     * Archive old-class fees and assign new-class fees on promotion.
     *
     * @return array  Summary with keys: archived_count, new_fee_heads, payments_preserved
     */
    public function reassignFeesOnPromotion(
        string $studentId,
        string $fromClass,
        string $fromSection,
        string $toClass,
        string $toSection,
        string $parentDbKey = ''
    ): array {
        $summary = [
            'archived_count'     => 0,
            'new_fee_heads'      => [],
            'payments_preserved' => 0,
        ];

        try {
            $now = date('c');

            // ── 1. Read existing demands ──
            $oldDemands = $this->firebase->get($this->_demandsPath($studentId));

            if (!empty($oldDemands) && is_array($oldDemands)) {
                // ── 2. Archive old demands ──
                $archivePath = $this->_archivedDemandsPath($studentId, $fromClass);
                $archivePayload = [
                    'demands'      => $oldDemands,
                    'archived_at'  => $now,
                    'archived_by'  => $this->adminId,
                    'from_class'   => $fromClass,
                    'from_section' => $fromSection,
                    'reason'       => 'promotion',
                ];
                $this->firebase->set($archivePath, $archivePayload);
                $summary['archived_count'] = count($oldDemands);

                // ── 3. Mark old pending fees as Promoted_Out ──
                $pendingFees = $this->firebase->get($this->_pendingFeesPath($studentId));
                if (!empty($pendingFees) && is_array($pendingFees)) {
                    $paymentsPreserved = 0;
                    foreach ($pendingFees as $pfKey => $pf) {
                        if (!is_array($pf)) {
                            continue;
                        }
                        $paidAmount = floatval($pf['paid'] ?? 0);
                        if ($paidAmount > 0) {
                            $paymentsPreserved++;
                        }
                        $this->firebase->update(
                            $this->_pendingFeesPath($studentId) . "/{$pfKey}",
                            [
                                'status'          => 'Promoted_Out',
                                'promoted_out_at' => $now,
                                'promoted_to'     => "{$toClass}/{$toSection}",
                            ]
                        );
                    }
                    $summary['payments_preserved'] = $paymentsPreserved;
                }
            }

            // ── 4. Clear live demands (archived copy exists) ──
            $this->firebase->delete($this->_demandsPath($studentId));

            // ── 5. Assign new class fees ──
            $summary['new_fee_heads'] = $this->assignInitialFees(
                $studentId,
                $toClass,
                $toSection,
                $parentDbKey
            );

            // ── Log ──
            $this->_log('fees_reassigned_promotion', $studentId, [
                'from'    => "{$fromClass}/{$fromSection}",
                'to'      => "{$toClass}/{$toSection}",
                'summary' => $summary,
            ]);

        } catch (\Exception $e) {
            log_message('error', 'Fee_lifecycle::reassignFeesOnPromotion failed: ' . $e->getMessage());
        }

        return $summary;
    }

    // ═════════════════════════════════════════════════════════════════
    //  3. proRateFees
    // ═════════════════════════════════════════════════════════════════

    /**
     * Pro-rate pending fees based on a student's join date.
     *
     * For an April–March academic year, if a student joins in October,
     * only Oct–March should be billed.  Annual and quarterly fees are
     * reduced proportionally.
     *
     * @param  string $studentId  Student UID
     * @param  string $joinDate   ISO date or any strtotime-parseable string
     * @param  string $feeType    Fee-head name to filter, or 'all'
     * @return array  Keyed by pending-fee key → pro-rated amount
     */
    public function proRateFees(string $studentId, string $joinDate, string $feeType = 'all'): array
    {
        $proRated = [];

        try {
            $joinTs = strtotime($joinDate);
            if ($joinTs === false) {
                log_message('error', "Fee_lifecycle::proRateFees — Invalid joinDate [{$joinDate}]");
                return [];
            }

            $joinMonth = (int) date('n', $joinTs); // 1-12
            $joinYear  = (int) date('Y', $joinTs);

            // Total months in the academic year (April = month 1 of year, March = month 12)
            $totalAcademicMonths  = 12;
            $remainingMonths      = $this->_remainingAcademicMonths($joinMonth);

            if ($remainingMonths >= $totalAcademicMonths) {
                // Joined at or before the academic start — no pro-ration needed
                return [];
            }

            $ratio = $remainingMonths / $totalAcademicMonths;

            // ── Read pending fees ──
            $pendingFees = $this->firebase->get($this->_pendingFeesPath($studentId));
            if (empty($pendingFees) || !is_array($pendingFees)) {
                return [];
            }

            // ── Also read demands for frequency lookup ──
            $demands = $this->firebase->get($this->_demandsPath($studentId));
            $demandIndex = [];
            if (!empty($demands) && is_array($demands)) {
                foreach ($demands as $dKey => $d) {
                    if (is_array($d)) {
                        $demandIndex[$dKey] = $d;
                    }
                }
            }

            foreach ($pendingFees as $pfKey => $pf) {
                if (!is_array($pf)) {
                    continue;
                }

                $status = $pf['status'] ?? '';
                if ($status === 'Paid' || $status === 'Frozen' || $status === 'Promoted_Out' || $status === 'Cancelled') {
                    continue;
                }

                $feeHead = $pf['fee_head'] ?? '';
                if ($feeType !== 'all' && $feeHead !== $feeType) {
                    continue;
                }

                // Determine frequency from linked demand
                $demandId  = $pf['demand_id'] ?? '';
                $frequency = 'Monthly';
                if (isset($demandIndex[$demandId])) {
                    $frequency = $demandIndex[$demandId]['frequency'] ?? 'Monthly';
                }

                $originalAmount = floatval($pf['amount'] ?? 0);

                // Monthly entries: remove months before join; no pro-rating of individual amounts
                if ($frequency === 'Monthly') {
                    $dueDate = $pf['due_date'] ?? '';
                    if ($dueDate !== '') {
                        $dueTs = strtotime($dueDate);
                        if ($dueTs !== false && $dueTs < $joinTs) {
                            // Month falls before join — zero it out
                            $this->firebase->update(
                                $this->_pendingFeesPath($studentId) . "/{$pfKey}",
                                [
                                    'amount'      => 0,
                                    'status'      => 'ProRated_Removed',
                                    'pro_rated'   => true,
                                    'pro_rated_at' => date('c'),
                                    'original_amount' => $originalAmount,
                                ]
                            );
                            $proRated[$pfKey] = 0;
                            continue;
                        }
                    }
                    // Due date is on or after join — keep as-is
                    continue;
                }

                // Annual: waive if join month is after April (the annual billing month), otherwise keep full amount
                if ($frequency === 'Annual') {
                    // Annual fee is billed in April (month 4). If student joins after April, waive it.
                    if ($joinMonth > self::ACADEMIC_START_MONTH) {
                        $this->firebase->update(
                            $this->_pendingFeesPath($studentId) . "/{$pfKey}",
                            [
                                'amount'          => 0,
                                'status'          => 'ProRated_Removed',
                                'pro_rated'       => true,
                                'pro_rated_at'    => date('c'),
                                'original_amount' => $originalAmount,
                                'pro_rate_reason' => 'Joined after annual billing month (April)',
                            ]
                        );
                        $proRated[$pfKey] = 0;
                    }
                    // If joined in or before April, keep full amount (no entry in $proRated)
                    continue;
                }

                // Quarterly: only bill from the quarter the student joins in, forward
                // Q1=Apr-Jun (months 4,5,6), Q2=Jul-Sep (7,8,9), Q3=Oct-Dec (10,11,12), Q4=Jan-Mar (1,2,3)
                if ($frequency === 'Quarterly') {
                    $dueDate = $pf['due_date'] ?? '';
                    if ($dueDate !== '') {
                        $dueTs = strtotime($dueDate);
                        if ($dueTs !== false) {
                            $dueMonth = (int) date('n', $dueTs);
                            // Determine which quarter this instalment belongs to
                            // Quarter start months: Q1=4, Q2=7, Q3=10, Q4=1
                            // A quarter covers 3 months starting from its start month
                            // If the join month is after the end of this quarter, waive it
                            $quarterEndMonth = $dueMonth + 2;
                            // Handle year wrap (e.g., Q4 starts Jan=1, ends Mar=3)
                            if ($quarterEndMonth > 12) {
                                $quarterEndMonth -= 12;
                            }

                            // Convert to academic position for comparison
                            $joinAcademicPos = $joinMonth - self::ACADEMIC_START_MONTH + 1;
                            if ($joinAcademicPos <= 0) $joinAcademicPos += 12;

                            $dueAcademicPos = $dueMonth - self::ACADEMIC_START_MONTH + 1;
                            if ($dueAcademicPos <= 0) $dueAcademicPos += 12;

                            $quarterEndAcademicPos = $dueAcademicPos + 2;

                            // If student joins after this quarter ends, waive it
                            if ($joinAcademicPos > $quarterEndAcademicPos) {
                                $this->firebase->update(
                                    $this->_pendingFeesPath($studentId) . "/{$pfKey}",
                                    [
                                        'amount'          => 0,
                                        'status'          => 'ProRated_Removed',
                                        'pro_rated'       => true,
                                        'pro_rated_at'    => date('c'),
                                        'original_amount' => $originalAmount,
                                        'pro_rate_reason' => 'Joined after this quarter ended',
                                    ]
                                );
                                $proRated[$pfKey] = 0;
                                continue;
                            }
                        }
                    }
                    // Quarter is applicable — keep full amount
                    continue;
                }

                // One-time: reduce proportionally
                if ($frequency === 'One-time') {
                    $adjusted = round($originalAmount * $ratio, 2);
                    $this->firebase->update(
                        $this->_pendingFeesPath($studentId) . "/{$pfKey}",
                        [
                            'amount'          => $adjusted,
                            'pro_rated'       => true,
                            'pro_rated_at'    => date('c'),
                            'original_amount' => $originalAmount,
                            'pro_rate_ratio'  => $ratio,
                        ]
                    );
                    $proRated[$pfKey] = $adjusted;
                }
            }

            // ── Log ──
            $this->_log('fees_pro_rated', $studentId, [
                'join_date'        => $joinDate,
                'remaining_months' => $remainingMonths,
                'ratio'            => $ratio,
                'adjusted_count'   => count($proRated),
                'fee_type'         => $feeType,
            ]);

        } catch (\Exception $e) {
            log_message('error', 'Fee_lifecycle::proRateFees failed: ' . $e->getMessage());
        }

        return $proRated;
    }

    // ═════════════════════════════════════════════════════════════════
    //  4. calculateSiblingDiscount
    // ═════════════════════════════════════════════════════════════════

    /**
     * Check if the student has an active sibling in the same school
     * and return the applicable discount.
     *
     * @param  string $parentDbKey  Parent node key under Users/Parents/
     * @param  string $studentId    The student we are checking for
     * @param  string $schoolCode   School code to match against children
     * @return array  {has_sibling, sibling_ids, discount_percentage, discount_amount}
     */
    public function calculateSiblingDiscount(string $parentDbKey, string $studentId, string $schoolCode): array
    {
        $result = [
            'has_sibling'         => false,
            'sibling_ids'         => [],
            'discount_percentage' => 0,
            'discount_amount'     => 0,
        ];

        try {
            if ($parentDbKey === '') {
                return $result;
            }

            // ── Read all children under this parent ──
            $parentData = $this->firebase->get("Users/Parents/{$parentDbKey}");

            if (empty($parentData) || !is_array($parentData)) {
                return $result;
            }

            $siblingIds = [];

            foreach ($parentData as $childKey => $childData) {
                // Skip non-child nodes (parent metadata like 'name', 'phone', etc.)
                if (!is_array($childData) || $childKey === $studentId) {
                    continue;
                }

                // Check if this child is active in the same school
                $childSchool = $childData['school_code'] ?? ($childData['school'] ?? '');
                $childStatus = $childData['status'] ?? 'active';

                if ($childSchool === $schoolCode && strtolower($childStatus) === 'active') {
                    $siblingIds[] = $childKey;
                }
            }

            if (empty($siblingIds)) {
                return $result;
            }

            $result['has_sibling'] = true;
            $result['sibling_ids'] = $siblingIds;

            // ── Read discount policy ──
            $discountPath = $this->_accountsFeesBase() . '/Sibling_Discount';
            $policy = $this->firebase->get($discountPath);

            if (!empty($policy) && is_array($policy)) {
                $result['discount_percentage'] = floatval($policy['percentage'] ?? 0);
                $result['discount_amount']     = floatval($policy['flat_amount'] ?? 0);

                // Some schools use tiered discounts based on sibling count
                $siblingCount = count($siblingIds);
                if (isset($policy['tiers']) && is_array($policy['tiers'])) {
                    foreach ($policy['tiers'] as $tier) {
                        if (!is_array($tier)) {
                            continue;
                        }
                        $minSiblings = intval($tier['min_siblings'] ?? 1);
                        if ($siblingCount >= $minSiblings) {
                            $result['discount_percentage'] = floatval($tier['percentage'] ?? $result['discount_percentage']);
                            $result['discount_amount']     = floatval($tier['flat_amount'] ?? $result['discount_amount']);
                        }
                    }
                }
            }

        } catch (\Exception $e) {
            log_message('error', 'Fee_lifecycle::calculateSiblingDiscount failed: ' . $e->getMessage());
        }

        return $result;
    }

    // ═════════════════════════════════════════════════════════════════
    //  5. freezeFeesOnSoftDelete
    // ═════════════════════════════════════════════════════════════════

    /**
     * Freeze all fee demands and pending entries for a deactivated student.
     * Does NOT delete any records — only marks them as frozen.
     *
     * @param  string $studentId
     * @return bool   True if freeze was applied, false on failure
     */
    public function freezeFeesOnSoftDelete(string $studentId): bool
    {
        try {
            $now = date('c');

            // ── Freeze demands meta ──
            $metaPath = $this->_demandsPath($studentId) . '/meta';
            $this->firebase->set($metaPath, [
                'frozen'    => true,
                'frozen_at' => $now,
                'frozen_by' => $this->adminId,
                'reason'    => 'student_deactivated',
            ]);

            // ── Mark each pending fee as Frozen ──
            $pendingFees = $this->firebase->get($this->_pendingFeesPath($studentId));

            if (!empty($pendingFees) && is_array($pendingFees)) {
                foreach ($pendingFees as $pfKey => $pf) {
                    if (!is_array($pf)) {
                        continue;
                    }
                    $currentStatus = $pf['status'] ?? '';
                    // Do not overwrite terminal statuses
                    if (in_array($currentStatus, ['Paid', 'Cancelled'], true)) {
                        continue;
                    }
                    $this->firebase->update(
                        $this->_pendingFeesPath($studentId) . "/{$pfKey}",
                        [
                            'status'            => 'Frozen',
                            'frozen_at'         => $now,
                            'previous_status'   => $currentStatus,
                        ]
                    );
                }
            }

            // ── Log ──
            $this->_log('fees_frozen', $studentId, [
                'reason' => 'student_deactivated',
            ]);

            return true;

        } catch (\Exception $e) {
            log_message('error', 'Fee_lifecycle::freezeFeesOnSoftDelete failed: ' . $e->getMessage());
            return false;
        }
    }

    // ═════════════════════════════════════════════════════════════════
    //  6. createModuleFee
    // ═════════════════════════════════════════════════════════════════

    /**
     * Create a fee entry for a module (Transport or Hostel).
     *
     * @param  string $studentId    Student UID
     * @param  string $moduleType   'Transport' or 'Hostel'
     * @param  array  $feeData      Expected keys: amount, frequency, description, route/hostel_name, etc.
     * @return string|null          Demand ID on success, null on failure
     */
    public function createModuleFee(string $studentId, string $moduleType, array $feeData): ?string
    {
        try {
            $allowedModules = ['Transport', 'Hostel'];
            if (!in_array($moduleType, $allowedModules, true)) {
                log_message('error', "Fee_lifecycle::createModuleFee — Invalid moduleType [{$moduleType}]");
                return null;
            }

            $now       = date('c');
            $amount    = floatval($feeData['amount'] ?? 0);
            $frequency = $feeData['frequency'] ?? 'Monthly';
            $headName  = $feeData['description'] ?? "{$moduleType} Fee";

            if ($amount <= 0) {
                log_message('error', "Fee_lifecycle::createModuleFee — Amount must be > 0 for student [{$studentId}]");
                return null;
            }

            // ── Write to Student_Fee_Items ──
            $itemPath = $this->_studentFeeItemsPath($studentId) . "/{$moduleType}";
            $itemPayload = array_merge($feeData, [
                'module_type' => $moduleType,
                'amount'      => $amount,
                'frequency'   => $frequency,
                'created_at'  => $now,
                'created_by'  => $this->adminId,
                'status'      => 'active',
            ]);
            $this->firebase->set($itemPath, $itemPayload);

            // ── Create demand ──
            $demandData = [
                'fee_head'    => $headName,
                'module_type' => $moduleType,
                'amount'      => $amount,
                'frequency'   => $frequency,
                'status'      => 'active',
                'created_at'  => $now,
                'created_by'  => $this->adminId,
            ];
            $demandId = $this->firebase->push($this->_demandsPath($studentId), $demandData);

            if (empty($demandId)) {
                log_message('error', "Fee_lifecycle::createModuleFee — Failed to create demand for student [{$studentId}] module [{$moduleType}]");
                return null;
            }

            // ── Create pending entries for the module fee ──
            $this->_createPendingEntries(
                $studentId,
                $demandId,
                $headName,
                $amount,
                $frequency,
                1,
                $now
            );

            // ── Log ──
            $this->_log('module_fee_created', $studentId, [
                'module_type' => $moduleType,
                'amount'      => $amount,
                'frequency'   => $frequency,
                'demand_id'   => $demandId,
            ]);

            return $demandId;

        } catch (\Exception $e) {
            log_message('error', 'Fee_lifecycle::createModuleFee failed: ' . $e->getMessage());
            return null;
        }
    }

    // ═════════════════════════════════════════════════════════════════
    //  7. reverseAdmissionFees
    // ═════════════════════════════════════════════════════════════════

    /**
     * Cancel all fee demands for a student (e.g. admission reversal).
     * Zeros out pending fees but preserves records for audit.
     *
     * @param  string $studentId
     * @return int    Count of reversed demands
     */
    public function reverseAdmissionFees(string $studentId): int
    {
        $reversedCount = 0;

        try {
            $now = date('c');

            // ── Mark all demands as cancelled ──
            $demands = $this->firebase->get($this->_demandsPath($studentId));

            if (!empty($demands) && is_array($demands)) {
                foreach ($demands as $dKey => $demand) {
                    // Skip the 'meta' node if present
                    if ($dKey === 'meta' || !is_array($demand)) {
                        continue;
                    }
                    $this->firebase->update(
                        $this->_demandsPath($studentId) . "/{$dKey}",
                        [
                            'status'       => 'cancelled',
                            'cancelled_at' => $now,
                            'cancelled_by' => $this->adminId,
                            'cancel_reason' => 'admission_reversal',
                        ]
                    );
                    $reversedCount++;
                }
            }

            // ── Zero out pending fees ──
            $pendingFees = $this->firebase->get($this->_pendingFeesPath($studentId));

            if (!empty($pendingFees) && is_array($pendingFees)) {
                foreach ($pendingFees as $pfKey => $pf) {
                    if (!is_array($pf)) {
                        continue;
                    }
                    $this->firebase->update(
                        $this->_pendingFeesPath($studentId) . "/{$pfKey}",
                        [
                            'amount'          => 0,
                            'status'          => 'Cancelled',
                            'cancelled_at'    => $now,
                            'original_amount' => floatval($pf['amount'] ?? 0),
                            'cancel_reason'   => 'admission_reversal',
                        ]
                    );
                }
            }

            // ── Log ──
            $this->_log('admission_fees_reversed', $studentId, [
                'reversed_demand_count' => $reversedCount,
            ]);

        } catch (\Exception $e) {
            log_message('error', 'Fee_lifecycle::reverseAdmissionFees failed: ' . $e->getMessage());
        }

        return $reversedCount;
    }

    // ═════════════════════════════════════════════════════════════════
    //  8. consolidateFeeRecords
    // ═════════════════════════════════════════════════════════════════

    /**
     * Merge fee records from one student into another (e.g. duplicate
     * student cleanup or parent transfer).
     *
     * @param  string $sourceStudentId    Student whose records are being moved
     * @param  string $targetStudentId    Student receiving the records
     * @param  string $sourceParentDbKey  Source parent key
     * @param  string $targetParentDbKey  Target parent key
     * @return array  {demands_merged, payments_merged, duplicates_skipped, source_archived}
     */
    public function consolidateFeeRecords(
        string $sourceStudentId,
        string $targetStudentId,
        string $sourceParentDbKey,
        string $targetParentDbKey
    ): array {
        $summary = [
            'demands_merged'     => 0,
            'payments_merged'    => 0,
            'duplicates_skipped' => 0,
            'source_archived'    => false,
        ];

        try {
            $now = date('c');

            // ── 1. Read source demands ──
            $sourceDemands = $this->firebase->get($this->_demandsPath($sourceStudentId));
            $targetDemands = $this->firebase->get($this->_demandsPath($targetStudentId));

            // Build a signature set for target demands to detect duplicates
            $targetSignatures = [];
            if (!empty($targetDemands) && is_array($targetDemands)) {
                foreach ($targetDemands as $td) {
                    if (is_array($td)) {
                        $sig = $this->_demandSignature($td);
                        $targetSignatures[$sig] = true;
                    }
                }
            }

            // ── 2. Merge source demands into target ──
            if (!empty($sourceDemands) && is_array($sourceDemands)) {
                foreach ($sourceDemands as $sdKey => $sd) {
                    if ($sdKey === 'meta' || !is_array($sd)) {
                        continue;
                    }

                    $sig = $this->_demandSignature($sd);
                    if (isset($targetSignatures[$sig])) {
                        $summary['duplicates_skipped']++;
                        continue;
                    }

                    // Tag with merge provenance
                    $sd['merged_from']       = $sourceStudentId;
                    $sd['original_demand_id'] = $sdKey;
                    $sd['merged_at']         = $now;
                    $sd['merged_by']         = $this->adminId;

                    $this->firebase->push($this->_demandsPath($targetStudentId), $sd);
                    $summary['demands_merged']++;
                }
            }

            // ── 3. Merge pending fees ──
            $sourcePending = $this->firebase->get($this->_pendingFeesPath($sourceStudentId));
            if (!empty($sourcePending) && is_array($sourcePending)) {
                foreach ($sourcePending as $spKey => $sp) {
                    if (!is_array($sp)) {
                        continue;
                    }
                    $sp['merged_from']      = $sourceStudentId;
                    $sp['original_pf_key']  = $spKey;
                    $sp['merged_at']        = $now;
                    $this->firebase->push($this->_pendingFeesPath($targetStudentId), $sp);
                }
            }

            // ── 4. Merge payment history ──
            if ($sourceParentDbKey !== '' && $targetParentDbKey !== '') {
                $sourcePaymentsPath = "Users/Parents/{$sourceParentDbKey}/{$sourceStudentId}/Fees Record";
                $targetPaymentsPath = "Users/Parents/{$targetParentDbKey}/{$targetStudentId}/Fees Record";

                $sourcePayments = $this->firebase->get($sourcePaymentsPath);

                if (!empty($sourcePayments) && is_array($sourcePayments)) {
                    // Read existing target payments for duplicate detection
                    $existingTargetPayments = $this->firebase->get($targetPaymentsPath);
                    $existingReceipts = [];
                    if (!empty($existingTargetPayments) && is_array($existingTargetPayments)) {
                        foreach ($existingTargetPayments as $etp) {
                            if (is_array($etp) && isset($etp['receipt_no'])) {
                                $existingReceipts[$etp['receipt_no']] = true;
                            }
                        }
                    }

                    foreach ($sourcePayments as $payKey => $payment) {
                        if (!is_array($payment)) {
                            continue;
                        }

                        // Skip duplicate receipts
                        $receiptNo = $payment['receipt_no'] ?? '';
                        if ($receiptNo !== '' && isset($existingReceipts[$receiptNo])) {
                            $summary['duplicates_skipped']++;
                            continue;
                        }

                        $payment['merged_from']     = $sourceStudentId;
                        $payment['original_key']    = $payKey;
                        $payment['merged_at']       = $now;

                        $this->firebase->push($targetPaymentsPath, $payment);
                        $summary['payments_merged']++;
                    }
                }
            }

            // ── 5. Archive source records (do not delete) ──
            $archivePayload = [
                'demands'         => $sourceDemands,
                'pending_fees'    => $sourcePending ?? null,
                'archived_at'     => $now,
                'archived_by'     => $this->adminId,
                'reason'          => 'consolidated_into_' . $targetStudentId,
                'target_student'  => $targetStudentId,
            ];
            $this->firebase->set(
                $this->_archivedDemandsPath($sourceStudentId) . '/consolidated',
                $archivePayload
            );

            // Clear live source records after archiving
            $this->firebase->delete($this->_demandsPath($sourceStudentId));
            $this->firebase->delete($this->_pendingFeesPath($sourceStudentId));
            $summary['source_archived'] = true;

            // ── Log ──
            $this->_log('fee_records_consolidated', $targetStudentId, [
                'source_student' => $sourceStudentId,
                'summary'        => $summary,
            ]);

        } catch (\Exception $e) {
            log_message('error', 'Fee_lifecycle::consolidateFeeRecords failed: ' . $e->getMessage());
        }

        return $summary;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Private helpers
    // ═════════════════════════════════════════════════════════════════

    /**
     * Create pending-fee entries based on frequency.
     *
     * Monthly  → 12 entries (one per academic month)
     * Quarterly → 4 entries
     * Annual   → 1 entry
     * One-time → 1 entry
     */
    private function _createPendingEntries(
        string $studentId,
        string $demandId,
        string $feeHead,
        float  $totalAmount,
        string $frequency,
        int    $dueDay,
        string $createdAt
    ): void {
        $instalments = self::FREQUENCY_MAP[$frequency] ?? 1;
        $perInstalment = round($totalAmount / $instalments, 2);

        // Fix rounding: put the remainder on the last instalment
        $remainder = round($totalAmount - ($perInstalment * $instalments), 2);

        $academicMonths = $this->_academicMonthSequence();

        for ($i = 0; $i < $instalments; $i++) {
            $amount = $perInstalment;
            if ($i === $instalments - 1) {
                $amount = round($amount + $remainder, 2);
            }

            $dueDate = $this->_dueDate($frequency, $i, $dueDay, $academicMonths);

            $entry = [
                'demand_id'  => $demandId,
                'fee_head'   => $feeHead,
                'amount'     => $amount,
                'paid'       => 0,
                'balance'    => $amount,
                'status'     => 'Unpaid',
                'due_date'   => $dueDate,
                'frequency'  => $frequency,
                'instalment' => $i + 1,
                'of_total'   => $instalments,
                'created_at' => $createdAt,
            ];

            $this->firebase->push($this->_pendingFeesPath($studentId), $entry);
        }
    }

    /**
     * Return the 12-month academic calendar sequence starting from April.
     * Each element is [year, month] where month is 1-12.
     *
     * Uses the session year string (e.g. "2025-2026") to derive the
     * calendar years.
     *
     * @return array  [[year, month], ...]  — 12 elements
     */
    private function _academicMonthSequence(): array
    {
        $parts     = explode('-', $this->sessionYear);
        $startYear = intval($parts[0] ?? date('Y'));
        $endYear   = intval($parts[1] ?? ($startYear + 1));

        $months = [];
        // April (4) → December (12) of start year
        for ($m = self::ACADEMIC_START_MONTH; $m <= 12; $m++) {
            $months[] = [$startYear, $m];
        }
        // January (1) → March (3) of end year
        for ($m = 1; $m < self::ACADEMIC_START_MONTH; $m++) {
            $months[] = [$endYear, $m];
        }

        return $months;
    }

    /**
     * Calculate the due date for an instalment.
     *
     * @param  string $frequency
     * @param  int    $index         0-based instalment index
     * @param  int    $dueDay        Day of month (1-28)
     * @param  array  $academicMonths From _academicMonthSequence()
     * @return string ISO date (Y-m-d)
     */
    private function _dueDate(string $frequency, int $index, int $dueDay, array $academicMonths): string
    {
        $dueDay = max(1, min(28, $dueDay)); // clamp to 1-28 to avoid month-overflow issues

        switch ($frequency) {
            case 'Monthly':
                // One entry per academic month
                if (isset($academicMonths[$index])) {
                    [$y, $m] = $academicMonths[$index];
                    return sprintf('%04d-%02d-%02d', $y, $m, $dueDay);
                }
                break;

            case 'Quarterly':
                // Quarters start at months 0, 3, 6, 9 of the academic year
                $monthIndex = $index * 3;
                if (isset($academicMonths[$monthIndex])) {
                    [$y, $m] = $academicMonths[$monthIndex];
                    return sprintf('%04d-%02d-%02d', $y, $m, $dueDay);
                }
                break;

            case 'Annual':
            case 'One-time':
            default:
                // Due at start of academic year
                if (isset($academicMonths[0])) {
                    [$y, $m] = $academicMonths[0];
                    return sprintf('%04d-%02d-%02d', $y, $m, $dueDay);
                }
                break;
        }

        // Fallback
        return date('Y-m-d');
    }

    /**
     * Calculate remaining academic months from a given calendar month.
     *
     * Academic year: April (4) through March (3).
     * If join month is April → 12 remaining.
     * If join month is October (10) → 6 remaining (Oct–Mar).
     * If join month is January (1) → 3 remaining (Jan–Mar).
     */
    private function _remainingAcademicMonths(int $joinMonth): int
    {
        // Map calendar month to academic position (April=1, May=2, ..., March=12)
        $academicPosition = $joinMonth - self::ACADEMIC_START_MONTH + 1;
        if ($academicPosition <= 0) {
            $academicPosition += 12;
        }

        // Remaining = total (12) minus how many have already passed
        return 12 - $academicPosition + 1;
    }

    /**
     * Build a simple signature string for a demand to detect duplicates.
     */
    private function _demandSignature(array $demand): string
    {
        $head   = $demand['fee_head']  ?? '';
        $amount = $demand['amount']    ?? 0;
        $freq   = $demand['frequency'] ?? '';
        $class  = $demand['class']     ?? '';
        $module = $demand['module_type'] ?? '';
        return "{$head}|{$amount}|{$freq}|{$class}|{$module}";
    }

    /**
     * Write a lifecycle log entry to the Audit_Logs node.
     */
    private function _log(string $event, string $studentId, array $data = []): void
    {
        try {
            $logEntry = [
                'event'        => $event,
                'student_id'   => $studentId,
                'performed_by' => $this->adminId,
                'timestamp'    => date('c'),
                'school'       => $this->schoolName,
                'session'      => $this->sessionYear,
                'data'         => $data,
            ];

            $this->firebase->push($this->_feesBase() . '/Audit_Logs', $logEntry);

            log_message('info', "Fee_lifecycle: {$event} | student={$studentId} | " . json_encode($data));

        } catch (\Exception $e) {
            // Logging failure must not break the main operation
            log_message('error', 'Fee_lifecycle::_log failed: ' . $e->getMessage());
        }
    }
}
