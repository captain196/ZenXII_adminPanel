<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_lifecycle — handles fee lifecycle events triggered by student
 * management (admission, promotion, module enrolment, soft-delete).
 *
 * Phase 5 — Firestore-only. Every data path has been moved off
 * Realtime Database onto the canonical Firestore collections that
 * admin + parent + teacher apps already read. Public API is unchanged
 * so existing callers (Sis, Hostel, Transport, Classes) don't have to
 * know the backing store moved.
 *
 * Firestore collections used:
 *   feeStructures     — class/section fee chart (read-only here)
 *   feeDemands        — canonical demand docs (write — all lifecycle events)
 *   students          — parent/student profile lookups
 *   studentDiscounts  — sibling-discount state
 *   fee_audit_logs    — audit trail for every lifecycle event
 *
 * Demand status vocabulary used by this library:
 *   unpaid   — generated, nothing paid yet       (assignInitialFees, createModuleFee)
 *   partial  — paid partially                    (not written here; payment flow sets it)
 *   paid     — fully paid                         (not written here)
 *   archived — superseded by a later class/section (reassignFeesOnPromotion)
 *   frozen   — student soft-deleted, do not collect (freezeFeesOnSoftDelete)
 *   reversed — admission reversed                 (reverseAdmissionFees)
 */
class Fee_lifecycle
{
    /** @var object */  private $firebase;
    /** @var object */  private $fs;           // Firestore_service shim
    /** @var object */  private $fsTxn;        // Fee_firestore_txn
    /** @var object|null */ private $fsSync;  // Fee_firestore_sync (defaulter)
    /** @var object|null */ private $auditLogger; // Fee_audit_logger

    private string $schoolName;
    private string $sessionYear;
    private string $adminId;

    /** Frequency → number of instalments per academic year */
    private const FREQUENCY_MAP = [
        'Monthly'   => 12,
        'Quarterly' => 4,
        'Annual'    => 1,
        'One-time'  => 1,
    ];

    private const ACADEMIC_START_MONTH = 4;

    // ─── Initialisation ──────────────────────────────────────────────

    public function init($firebase, string $schoolName, string $sessionYear, string $adminId = 'system'): self
    {
        $this->firebase    = $firebase;
        $this->schoolName  = $schoolName;
        $this->sessionYear = $sessionYear;
        $this->adminId     = $adminId;

        $CI =& get_instance();

        // Firestore_service (for .get / .exists / .getEntity)
        if (!isset($CI->fs) || !is_object($CI->fs)) {
            $CI->load->library('firestore_service', null, 'fs');
        }
        $this->fs = $CI->fs;

        // Fee_firestore_txn — canonical writeDemand / updateDemand / readFeeStructure.
        if (!isset($CI->fsTxn) || !is_object($CI->fsTxn)) {
            $CI->load->library('Fee_firestore_txn', null, 'fsTxn');
            $CI->fsTxn->init($firebase, $this->fs, $schoolName, $sessionYear);
        }
        $this->fsTxn = $CI->fsTxn;

        // Fee_firestore_sync — defaulter recompute hooks.
        if (!isset($CI->fsSync) || !is_object($CI->fsSync)) {
            $CI->load->library('Fee_firestore_sync', null, 'fsSync');
            $CI->fsSync->init($firebase, $schoolName, $sessionYear);
        }
        $this->fsSync = $CI->fsSync;

        // Audit logger — best-effort; library failure never fails a
        // lifecycle event, per Fee_audit_logger contract.
        try {
            require_once APPPATH . 'libraries/Fee_audit_logger.php';
            $this->auditLogger = new Fee_audit_logger($firebase, $schoolName, $sessionYear);
        } catch (\Throwable $_) { $this->auditLogger = null; }

        return $this;
    }

    // ─── Small helpers (Firestore-aware) ─────────────────────────────

    private function _demandId(string $studentId, string $periodKey, string $feeHeadId, string $fallbackName = ''): string
    {
        $sid = preg_replace('/[^A-Za-z0-9]+/', '_', trim($studentId));
        $sid = trim((string) $sid, '_');
        $ym  = str_replace('-', '', $periodKey);
        $key = trim($feeHeadId);
        if ($key === '') {
            $key = strtoupper(trim($fallbackName));
            $key = preg_replace('/[^A-Z0-9]+/', '_', $key);
            $key = trim((string) $key, '_');
        }
        return "DEM_{$sid}_{$ym}_{$key}";
    }

    /** Student profile from Firestore. */
    private function _studentDoc(string $studentId): ?array
    {
        try {
            $d = $this->firebase->firestoreGet('students', "{$this->schoolName}_{$studentId}");
            return is_array($d) ? $d : null;
        } catch (\Throwable $_) { return null; }
    }

    /**
     * All current demands for a student in this session, keyed by
     * demandId. Thin wrapper around Fee_firestore_txn::demandsForStudent
     * so callers below stay readable.
     */
    private function _demandsFor(string $studentId): array
    {
        try {
            return $this->fsTxn->demandsForStudent($studentId);
        } catch (\Throwable $_) { return []; }
    }

    // ═════════════════════════════════════════════════════════════════
    //  1. assignInitialFees
    // ═════════════════════════════════════════════════════════════════

    /**
     * Create fresh demand docs for a newly-admitted student from their
     * class+section fee structure. Idempotent by virtue of deterministic
     * demand IDs — re-running against the same student is a no-op.
     *
     * @return array  List of fee-head names that were generated.
     */
    public function assignInitialFees(
        string $studentId,
        string $class,
        string $section,
        string $parentDbKey = ''
    ): array {
        $assigned = [];
        try {
            $chart    = $this->fsTxn->readFeeStructure($class, $section);
            $headIds  = $this->fsTxn->readFeeHeadIds($class, $section);
            if (empty($chart)) {
                log_message('info', "Fee_lifecycle::assignInitialFees — no Firestore feeStructure for [{$class}/{$section}] student [{$studentId}]");
                return [];
            }

            $stu = $this->_studentDoc($studentId);
            $studentName = (string) ($stu['name'] ?? $stu['studentName'] ?? $studentId);

            $now = date('c');
            // The demand generator in Phase 2.5 iterates chart months and
            // writes one demand per (student, month, feeHead). We reuse
            // that exact contract so every app already subscribed to
            // feeDemands starts seeing this student's demands instantly.
            $months = ['April','May','June','July','August','September',
                       'October','November','December','January','February','March'];
            foreach ($chart as $monthOrYearly => $heads) {
                if (!is_array($heads) || empty($heads)) continue;
                foreach ($heads as $headName => $amount) {
                    $amt = (float) $amount;
                    if ($amt <= 0) continue;

                    $isYearly  = ($monthOrYearly === 'Yearly Fees');
                    $headId    = (string) ($headIds[$headName] ?? '');

                    // Build one demand per academic month for monthly
                    // heads; a single April bucket for yearly heads to
                    // match the Phase 2.5 generator.
                    $targetMonths = $isYearly ? ['April'] : [$monthOrYearly];
                    if ($isYearly && $monthOrYearly !== 'Yearly Fees') continue;

                    foreach ($targetMonths as $m) {
                        $year       = in_array($m, ['January','February','March'], true)
                                    ? ((int) substr($this->sessionYear, 0, 4) + 1)
                                    : (int) substr($this->sessionYear, 0, 4);
                        $periodKey  = sprintf('%04d-%02d', $year, $this->_monthNum($m));
                        $periodLbl  = $isYearly
                            ? "Yearly Fees {$this->sessionYear}"
                            : "{$m} {$year}";
                        $demandId   = $this->_demandId($studentId, $periodKey, $headId, $headName);

                        $this->fsTxn->writeDemand($demandId, [
                            'studentId'    => $studentId,
                            'studentName'  => $studentName,
                            'className'    => $class,
                            'section'      => $section,
                            'feeHead'      => $headName,
                            'feeHeadId'    => $headId,
                            'frequency'    => $isYearly ? 'yearly' : 'monthly',
                            'period'       => $periodLbl,
                            'periodKey'    => $periodKey,
                            'grossAmount'  => $amt,
                            'netAmount'    => $amt,
                            'paidAmount'   => 0.0,
                            'balance'      => $amt,
                            'status'       => 'unpaid',
                            'createdAt'    => $now,
                            'createdBy'    => $this->adminId,
                        ]);
                    }
                    if (!in_array($headName, $assigned, true)) $assigned[] = $headName;
                }
            }

            $this->_log('initial_fees_assigned', $studentId, [
                'class'         => $class,
                'section'       => $section,
                'fee_heads'     => $assigned,
                'count'         => count($assigned),
                'parent_db_key' => $parentDbKey,
            ]);

        } catch (\Throwable $e) {
            log_message('error', "Fee_lifecycle::assignInitialFees failed for [{$studentId}]: " . $e->getMessage());
        }
        return $assigned;
    }

    // ═════════════════════════════════════════════════════════════════
    //  2. reassignFeesOnPromotion
    // ═════════════════════════════════════════════════════════════════

    /**
     * Student moved class/section. Archive everything from the old
     * class and regenerate from the new class's structure. Paid and
     * partial demands are LEFT in place under their original class so
     * payment history stays intact; only unpaid demands from the old
     * class are archived.
     */
    public function reassignFeesOnPromotion(
        string $studentId,
        string $oldClass,
        string $oldSection,
        string $newClass,
        string $newSection,
        string $parentDbKey = ''
    ): array {
        $archived = 0; $preserved = 0;
        try {
            foreach ($this->_demandsFor($studentId) as $did => $d) {
                $st = (string) ($d['status'] ?? 'unpaid');
                // Only archive UNPAID demands; paid/partial demands
                // represent real payment history.
                if ($st !== 'unpaid') { $preserved++; continue; }
                // Skip demands that already belong to the target class.
                if (((string) ($d['className'] ?? '')) === $newClass &&
                    ((string) ($d['section']   ?? '')) === $newSection) continue;

                $this->fsTxn->updateDemand($did, [
                    'status'        => 'archived',
                    'archivedAt'    => date('c'),
                    'archivedBy'    => $this->adminId,
                    'archivedFrom'  => "{$oldClass}/{$oldSection}",
                ]);
                $archived++;
            }

            // Generate the new class's structure.
            $this->assignInitialFees($studentId, $newClass, $newSection, $parentDbKey);

            $this->_log('promotion_reassigned', $studentId, [
                'old_class'  => $oldClass, 'old_section' => $oldSection,
                'new_class'  => $newClass, 'new_section' => $newSection,
                'archived'   => $archived, 'preserved'   => $preserved,
            ]);

            // Defaulter recompute — old dues may resolve, new dues appear.
            try {
                if ($this->fsSync !== null) {
                    $this->fsSync->syncDefaulterStatus(
                        $studentId,
                        ['total_dues' => 0, 'is_defaulter' => false], // placeholder; a full recompute lives in Fee_defaulter_check
                        '', $newClass, $newSection
                    );
                }
            } catch (\Throwable $_) {}

        } catch (\Throwable $e) {
            log_message('error', "Fee_lifecycle::reassignFeesOnPromotion failed [{$studentId}]: " . $e->getMessage());
        }
        return ['archived' => $archived, 'preserved' => $preserved];
    }

    // ═════════════════════════════════════════════════════════════════
    //  3. proRateFees
    // ═════════════════════════════════════════════════════════════════

    /**
     * Student joined mid-year → reduce remaining demands to reflect the
     * months remaining in the academic year. Prorates netAmount +
     * balance on unpaid/partial demands whose period is >= joinDate.
     */
    public function proRateFees(string $studentId, string $joinDate, string $feeType = 'all'): array
    {
        $adjusted = 0; $skipped = 0;
        try {
            $joinTs = strtotime($joinDate) ?: time();
            $joinMonth = (int) date('n', $joinTs);
            $remaining = $this->_remainingAcademicMonths($joinMonth);
            if ($remaining <= 0 || $remaining >= 12) {
                return ['adjusted' => 0, 'skipped' => 0];
            }
            $factor = $remaining / 12.0;

            foreach ($this->_demandsFor($studentId) as $did => $d) {
                $st = (string) ($d['status'] ?? 'unpaid');
                if ($st === 'paid' || $st === 'archived') { $skipped++; continue; }
                $freq = strtolower((string) ($d['frequency'] ?? 'monthly'));
                // Monthly demands are already per-month — no prorate. Annual
                // / Yearly demands are the ones that need scaling.
                if ($freq !== 'annual' && $freq !== 'yearly') { $skipped++; continue; }
                // Optional filter: proRate only a single fee type.
                if ($feeType !== 'all' && (string) ($d['category'] ?? $d['feeHead'] ?? '') !== $feeType) {
                    $skipped++; continue;
                }
                $grossAmt  = (float) ($d['grossAmount'] ?? $d['netAmount'] ?? 0);
                $newNet    = round($grossAmt * $factor, 2);
                $newBal    = round($newNet - (float) ($d['paidAmount'] ?? 0), 2);
                if ($newBal < 0) $newBal = 0;
                $this->fsTxn->updateDemand($did, [
                    'netAmount' => $newNet,
                    'balance'   => $newBal,
                    'prorated'  => true,
                    'prorateFactor' => $factor,
                ]);
                $adjusted++;
            }

            $this->_log('fees_prorated', $studentId, [
                'join_date' => $joinDate, 'fee_type' => $feeType,
                'factor'    => $factor,   'remaining_months' => $remaining,
                'adjusted'  => $adjusted, 'skipped' => $skipped,
            ]);
        } catch (\Throwable $e) {
            log_message('error', "Fee_lifecycle::proRateFees failed [{$studentId}]: " . $e->getMessage());
        }
        return ['adjusted' => $adjusted, 'skipped' => $skipped];
    }

    // ═════════════════════════════════════════════════════════════════
    //  4. calculateSiblingDiscount
    // ═════════════════════════════════════════════════════════════════

    /**
     * Sibling-discount lookup. Reads studentDiscounts docs for siblings
     * sharing the same parentDbKey to decide what discount to grant the
     * new sibling. Returns an advisory struct — actual discount doc
     * update happens elsewhere.
     */
    public function calculateSiblingDiscount(
        string $parentDbKey,
        string $studentId,
        string $schoolCode
    ): array {
        $siblings = [];
        try {
            // Phase 5 — students are keyed per-school in Firestore. A
            // parent's children are discoverable via the `parentDbKey`
            // field on each student doc.
            $rows = $this->firebase->firestoreQuery('students', [
                ['schoolId',    '==', $this->schoolName],
                ['parentDbKey', '==', $parentDbKey],
            ]);
            foreach ((array) $rows as $r) {
                $d = $r['data'] ?? $r;
                if (!is_array($d)) continue;
                $sid = (string) ($d['studentId'] ?? $d['userId'] ?? ($r['id'] ?? ''));
                if ($sid === '' || $sid === $studentId) continue;
                $siblings[] = $sid;
            }

            // Read the school's sibling-discount policy from Firestore
            // feeSettings (replaces the legacy RTDB policy path).
            $policy = $this->firebase->firestoreGet('feeSettings', "{$this->schoolName}_sibling_policy");
            $policyPct = is_array($policy) ? (float) ($policy['secondChildDiscountPercent'] ?? 0) : 0.0;

            return [
                'sibling_count'   => count($siblings),
                'sibling_ids'     => $siblings,
                'discount_pct'    => $policyPct,
                'applies'         => count($siblings) >= 1 && $policyPct > 0,
            ];
        } catch (\Throwable $e) {
            log_message('error', "Fee_lifecycle::calculateSiblingDiscount failed [{$studentId}]: " . $e->getMessage());
            return ['sibling_count' => 0, 'sibling_ids' => [], 'discount_pct' => 0.0, 'applies' => false];
        }
    }

    // ═════════════════════════════════════════════════════════════════
    //  5. freezeFeesOnSoftDelete
    // ═════════════════════════════════════════════════════════════════

    public function freezeFeesOnSoftDelete(string $studentId): bool
    {
        $frozen = 0;
        try {
            foreach ($this->_demandsFor($studentId) as $did => $d) {
                $st = (string) ($d['status'] ?? 'unpaid');
                // Leave paid/archived alone; we only freeze collectable demands.
                if (in_array($st, ['paid', 'archived', 'frozen'], true)) continue;
                $this->fsTxn->updateDemand($did, [
                    'status'    => 'frozen',
                    'frozenAt'  => date('c'),
                    'frozenBy'  => $this->adminId,
                ]);
                $frozen++;
            }
            $this->_log('fees_frozen', $studentId, ['demands_frozen' => $frozen]);
            return true;
        } catch (\Throwable $e) {
            log_message('error', "Fee_lifecycle::freezeFeesOnSoftDelete failed [{$studentId}]: " . $e->getMessage());
            return false;
        }
    }

    // ═════════════════════════════════════════════════════════════════
    //  6. createModuleFee  (Hostel / Transport / Activity …)
    // ═════════════════════════════════════════════════════════════════

    /**
     * Write a one-off module-fee demand — used when a student opts into
     * Transport or Hostel mid-year and a new demand has to be created.
     * The demand is categorised so clearance checks + reports can group
     * it correctly.
     */
    public function createModuleFee(string $studentId, string $moduleType, array $feeData): ?string
    {
        try {
            $stu = $this->_studentDoc($studentId);
            if ($stu === null) return null;

            $headName   = (string) ($feeData['fee_head']  ?? $feeData['feeHead'] ?? "{$moduleType} Fee");
            $amount     = (float)  ($feeData['amount']    ?? 0);
            $frequency  = strtolower((string) ($feeData['frequency'] ?? 'Monthly'));
            if ($amount <= 0) return null;

            $year       = (int) substr($this->sessionYear, 0, 4);
            $periodKey  = sprintf('%04d-%02d', $year, self::ACADEMIC_START_MONTH);
            $demandId   = $this->_demandId($studentId, $periodKey, '', $headName . '_' . $moduleType);
            $periodLbl  = "April {$year}";

            $this->fsTxn->writeDemand($demandId, [
                'studentId'   => $studentId,
                'studentName' => (string) ($stu['name'] ?? $stu['studentName'] ?? ''),
                'className'   => (string) ($stu['className'] ?? ''),
                'section'     => (string) ($stu['section']   ?? ''),
                'feeHead'     => $headName,
                'category'    => $moduleType,
                'frequency'   => $frequency,
                'period'      => $periodLbl,
                'periodKey'   => $periodKey,
                'grossAmount' => $amount,
                'netAmount'   => $amount,
                'paidAmount'  => 0.0,
                'balance'     => $amount,
                'status'      => 'unpaid',
                'createdAt'   => date('c'),
                'createdBy'   => $this->adminId,
            ]);

            $this->_log('module_fee_created', $studentId, [
                'module' => $moduleType, 'head' => $headName, 'amount' => $amount,
                'demand_id' => $demandId,
            ]);
            return $demandId;
        } catch (\Throwable $e) {
            log_message('error', "Fee_lifecycle::createModuleFee failed [{$studentId}/{$moduleType}]: " . $e->getMessage());
            return null;
        }
    }

    // ═════════════════════════════════════════════════════════════════
    //  7. reverseAdmissionFees
    // ═════════════════════════════════════════════════════════════════

    public function reverseAdmissionFees(string $studentId): int
    {
        $reversed = 0;
        try {
            foreach ($this->_demandsFor($studentId) as $did => $d) {
                $st = (string) ($d['status'] ?? 'unpaid');
                if (in_array($st, ['paid','archived','reversed','frozen'], true)) continue;
                $this->fsTxn->updateDemand($did, [
                    'status'      => 'reversed',
                    'reversedAt'  => date('c'),
                    'reversedBy'  => $this->adminId,
                    'balance'     => 0,
                ]);
                $reversed++;
            }
            $this->_log('admission_reversed', $studentId, ['demands_reversed' => $reversed]);
        } catch (\Throwable $e) {
            log_message('error', "Fee_lifecycle::reverseAdmissionFees failed [{$studentId}]: " . $e->getMessage());
        }
        return $reversed;
    }

    // ═════════════════════════════════════════════════════════════════
    //  8. consolidateFeeRecords
    // ═════════════════════════════════════════════════════════════════

    /**
     * Merge two student IDs (typically after a duplicate-admission
     * resolution). Source demands are migrated onto the target student
     * by rewriting their docId; source's demand docs are then archived.
     */
    public function consolidateFeeRecords(
        string $sourceStudentId,
        string $targetStudentId,
        bool   $deleteSource = true
    ): array {
        $moved = 0; $skipped = 0;
        try {
            foreach ($this->_demandsFor($sourceStudentId) as $srcId => $d) {
                // Rewrite to a target-scoped demandId so re-runs are idempotent.
                $periodKey = (string) ($d['periodKey'] ?? $d['period_key'] ?? '');
                $feeHeadId = (string) ($d['feeHeadId'] ?? '');
                $feeHead   = (string) ($d['feeHead']   ?? '');
                if ($periodKey === '' || ($feeHead === '' && $feeHeadId === '')) {
                    $skipped++; continue;
                }
                $targetDemandId = $this->_demandId($targetStudentId, $periodKey, $feeHeadId, $feeHead);
                // Write the target-scoped demand (merge=true on Phase 2.5 writeDemand).
                $this->fsTxn->writeDemand($targetDemandId, array_merge($d, [
                    'studentId'   => $targetStudentId,
                    'mergedFrom'  => $sourceStudentId,
                    'mergedAt'    => date('c'),
                ]));
                if ($deleteSource) {
                    // Mark the source demand archived so history is preserved.
                    $this->fsTxn->updateDemand($srcId, [
                        'status'     => 'archived',
                        'archivedAt' => date('c'),
                        'archivedBy' => $this->adminId,
                        'archiveReason' => "merged into {$targetStudentId}",
                    ]);
                }
                $moved++;
            }
            $this->_log('fees_consolidated', $targetStudentId, [
                'source_student' => $sourceStudentId,
                'moved'          => $moved,
                'skipped'        => $skipped,
            ]);
        } catch (\Throwable $e) {
            log_message('error', "Fee_lifecycle::consolidateFeeRecords failed [{$sourceStudentId}→{$targetStudentId}]: " . $e->getMessage());
        }
        return ['moved' => $moved, 'skipped' => $skipped];
    }

    // ═════════════════════════════════════════════════════════════════
    //  Private helpers
    // ═════════════════════════════════════════════════════════════════

    private function _remainingAcademicMonths(int $joinMonth): int
    {
        // Academic year starts in April (month 4), ends in March.
        if ($joinMonth < self::ACADEMIC_START_MONTH) {
            // Jan / Feb / Mar — last quarter of the academic year.
            return self::ACADEMIC_START_MONTH - $joinMonth;
        }
        // Apr … Dec — months remaining through March.
        return 12 - ($joinMonth - self::ACADEMIC_START_MONTH);
    }

    private function _monthNum(string $m): int
    {
        static $map = [
            'April'=>4,'May'=>5,'June'=>6,'July'=>7,'August'=>8,'September'=>9,
            'October'=>10,'November'=>11,'December'=>12,'January'=>1,'February'=>2,'March'=>3,
        ];
        return $map[$m] ?? 1;
    }

    /**
     * Audit log — every mutating lifecycle event lands in fee_audit_logs
     * so the admin dashboard + regulator can reconstruct who did what.
     */
    private function _log(string $event, string $studentId, array $data = []): void
    {
        if ($this->auditLogger === null) return;
        try {
            $this->auditLogger->record(
                'update', 'demand', $studentId,
                /* before */ [],
                /* after  */ array_merge(['event' => $event], $data),
                $this->adminId,
                ['source' => 'fee_lifecycle', 'reason' => $event]
            );
        } catch (\Throwable $_) { /* never fail the caller on audit */ }
    }
}
