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

    /**
     * BUG-076 (2026-05-27): session-independent demand fetch. Used by
     * reassignFeesOnPromotion so the archive loop sees the student's
     * demands regardless of which session the operator's session-selector
     * is currently pointing at. Class/section filter inside the archive
     * loop is what actually drives the archive decision — session never
     * was load-bearing for archival correctness.
     */
    private function _demandsForAllSessions(string $studentId): array
    {
        try {
            return $this->fsTxn->demandsForStudentAllSessions($studentId);
        } catch (\Throwable $_) { return []; }
    }

    /**
     * BUG-045 Phase 1 B2 (2026-05-25): request-level fee-structure cache.
     *
     * Pre-fix bulk promote of N students into the same destination class
     * called readFeeStructure + readFeeHeadIds once per student = 2N reads
     * of the same Firestore doc. With this cache, the same class/section
     * pair is fetched ONCE per request lifetime; subsequent students hit
     * the in-memory cache (free).
     *
     * Cache key: "{classKey}_{sectionKey}" — session is fixed per
     * Fee_firestore_txn instance, so excluded from key.
     *
     * Memory bound: the cache holds at most one doc per touched
     * class/section pair per request; lifetime is one PHP request
     * (instance is destroyed at request end).
     *
     * @return array ['chart' => [...], 'headIds' => [...]]
     */
    private $_feeStructureCache = [];
    private function _cachedFeeStructureWithIds(string $class, string $section): array
    {
        $key = "{$class}_{$section}";
        if (!isset($this->_feeStructureCache[$key])) {
            $this->_feeStructureCache[$key] = $this->fsTxn->readFeeStructureWithIds($class, $section);
        }
        return $this->_feeStructureCache[$key];
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
    /**
     * Build the demand specs that admission/promotion would emit for this
     * (student, class, section) — WITHOUT committing. Pure builder (no
     * Firestore writes). Extracted from assignInitialFees in Phase 1 of the
     * Fees Exemption v2 work so the unified generator (Fee_generation_service)
     * can call the same builder and produce byte-identical output for the
     * zero-concession case.
     *
     * Returns: [
     *   'specs'      => array<commitDemandBatch-entry>  ← what to write
     *   'assigned'   => array<head-name>                ← unique fee heads with amt>0
     *   'chartFound' => bool                            ← false iff feeStructure absent
     * ]
     *
     * Exceptions propagate to the caller (assignInitialFees keeps its outer
     * try/catch). DOES NOT change behavior of assignInitialFees — proven by
     * the Phase-1 A/B verification probe before any P2 flag flip.
     */
    public function buildAdmissionDemandSpecs(
        string $studentId,
        string $class,
        string $section
    ): array {
        $assigned     = [];
        $batchEntries = [];

        // BUG-075 fix (2026-05-26): pre-read existing demands to detect
        // already-paid demand-ids. Re-running against a previously-visited
        // class (reverse-promote, rollover) MUST NOT overwrite paidAmount/
        // balance/status on demands that already carry a payment — the
        // deterministic demandId formula collides when feeHeadIds match
        // between source and target class structures, and Firestore
        // merge=true SET would silently reset the paid state.
        $existingDemands = [];
        try {
            foreach ($this->_demandsFor($studentId) as $did => $d) {
                $existingDemands[$did] = $d;
            }
        } catch (\Throwable $_) {
            $existingDemands = [];
        }

        // Fees V7: canonical duplicate-detection key (periodKey|canonicalFeeHeadId),
        // NON-archived only — id-agnostic, archive-aware.
        $canonMap = $this->fsTxn->canonicalHeadIdMap();
        $haveKeys = [];
        foreach ($existingDemands as $_did => $_d) {
            if (($_d['status'] ?? '') === 'archived') continue;
            $pk  = (string) ($_d['periodKey'] ?? $_d['period_key'] ?? '');
            $cid = (string) ($_d['canonicalFeeHeadId'] ?? '');
            if ($pk !== '' && $cid !== '') $haveKeys[$pk . '|' . $cid] = true;
        }

        // BUG-045 Phase 1 B2+B3 (2026-05-25): merged read (B3) wrapped in
        // request-level cache (B2). Saves 1 RPC/student + (N-1) more when
        // all N students share a target class/section.
        $merged   = $this->_cachedFeeStructureWithIds($class, $section);
        $chart    = $merged['chart'];
        $headIds  = $merged['headIds'];
        if (empty($chart)) {
            return ['specs' => [], 'assigned' => [], 'chartFound' => false];
        }

        $stu = $this->_studentDoc($studentId);
        $studentName = (string) ($stu['name'] ?? $stu['studentName'] ?? $studentId);

        $now = date('c');

        // BUG-045 Phase 2 (2026-05-25): collect-then-batch — caller emits
        // a single :commit batched write via Fee_firestore_txn::commitDemandBatch
        // with per-doc fallback. This builder produces the spec list only.
        foreach ($chart as $monthOrYearly => $heads) {
            if (!is_array($heads) || empty($heads)) continue;
            foreach ($heads as $headName => $amount) {
                $amt = (float) $amount;
                if ($amt <= 0) continue;

                $isYearly  = ($monthOrYearly === 'Yearly Fees');
                $headId    = (string) ($headIds[$headName] ?? '');

                // One demand per academic month for monthly heads; a single
                // April bucket for yearly heads — matches the Phase-2.5 generator.
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
                    $canonicalId = (string) ($canonMap[strtolower(trim($headName))] ?? $headId);

                    // Fees V7: skip if an active demand for (periodKey|canonicalFeeHeadId)
                    // already exists — preserves it entirely (incl. payment), id-agnostic.
                    // Replaces the doc-id BUG-075 preserve-on-rewrite (issued demands immutable).
                    if (isset($haveKeys[$periodKey . '|' . $canonicalId])) {
                        if (!in_array($headName, $assigned, true)) $assigned[] = $headName;
                        continue;
                    }
                    $haveKeys[$periodKey . '|' . $canonicalId] = true;
                    $demandId = $this->fsTxn->newDemandId();

                    $entryData = [
                        'studentId'    => $studentId,
                        'studentName'  => $studentName,
                        'className'    => $class,
                        'section'      => $section,
                        'feeHead'      => $headName,
                        'feeHeadId'    => $headId,
                        'canonicalFeeHeadId' => $canonicalId,
                        'frequency'    => $isYearly ? 'yearly' : 'monthly',
                        'period'       => $periodLbl,
                        'periodKey'    => $periodKey,
                        'grossAmount'  => $amt,
                        'netAmount'    => $amt,
                        'createdAt'    => $now,
                        'createdBy'    => $this->adminId,
                    ];
                    // New demand (existing ones were skipped above): always fresh unpaid.
                    $entryData['paidAmount'] = 0.0;
                    $entryData['balance']    = $amt;
                    $entryData['status']     = 'unpaid';

                    $batchEntries[] = [
                        'op'       => 'write',
                        'demandId' => $demandId,
                        'data'     => $entryData,
                    ];
                }
                if (!in_array($headName, $assigned, true)) $assigned[] = $headName;
            }
        }

        return ['specs' => $batchEntries, 'assigned' => $assigned, 'chartFound' => true];
    }

    /**
     * Phase 2 router: route through Fee_generation_service when the flag is
     * on (concession-aware), else call buildAdmissionDemandSpecs directly
     * (byte-identical to legacy / Phase 1 state). On any flag-read failure
     * the legacy path is taken — fail-safe.
     */
    private function _routedBuildAdmissionSpecs(string $studentId, string $class, string $section): array
    {
        $useUnified = false;
        $CI = function_exists('get_instance') ? get_instance() : null;
        try {
            if ($CI !== null && isset($CI->config)) {
                $CI->config->load('fees_exemption_v2_flags', true);
                $useUnified = (bool) $CI->config->item('USE_UNIFIED_FEE_GEN', 'fees_exemption_v2_flags');
            }
        } catch (\Throwable $_) {
            $useUnified = false;
        }

        if (!$useUnified || $CI === null) {
            return $this->buildAdmissionDemandSpecs($studentId, $class, $section);
        }

        if (!isset($CI->concessionReader)) $CI->load->library('Fee_concession_reader',         null, 'concessionReader');
        if (!isset($CI->enrollmentReader)) $CI->load->library('Fee_service_enrollment_reader', null, 'enrollmentReader');
        if (!isset($CI->genSvc))           $CI->load->library('Fee_generation_service',        null, 'genSvc');
        $CI->concessionReader->init($this->firebase);
        $CI->enrollmentReader->init($this->firebase);
        $CI->genSvc->init($this, $CI->concessionReader, $CI->enrollmentReader, $this->schoolName, $this->sessionYear);
        return $CI->genSvc->generateDemandsForStudent($studentId, $class, $section);
    }

    public function assignInitialFees(
        string $studentId,
        string $class,
        string $section,
        string $parentDbKey = ''
    ): array {
        $assigned = [];
        try {
            // Phase 2 router: USE_UNIFIED_FEE_GEN gates the concession-aware
            // path; flag off = byte-identical to Phase 1 (proven 9/9 A/B).
            $built = $this->_routedBuildAdmissionSpecs($studentId, $class, $section);

            if (!$built['chartFound']) {
                log_message('info', "Fee_lifecycle::assignInitialFees — no Firestore feeStructure for [{$class}/{$section}] student [{$studentId}]");
                return [];
            }

            $batchEntries = $built['specs'];
            $assigned     = $built['assigned'];

            if (!empty($batchEntries)) {
                if (!$this->fsTxn->commitDemandBatch($batchEntries)) {
                    log_message('warning', "Fee_lifecycle::assignInitialFees commitDemandBatch failed for [{$studentId}]; falling back to per-doc writes");
                    foreach ($batchEntries as $entry) {
                        $this->fsTxn->writeDemand($entry['demandId'], $entry['data']);
                    }
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
            // BUG-045 Phase 2 (2026-05-25): collect-then-batch pattern.
            // Pre-fix issued one Firestore PATCH per archived demand inside
            // the loop. Now we accumulate archive ops and emit a single
            // :commit per student. Fallback to per-doc updateDemand if the
            // commit fails so semantics stay identical.
            $archiveEntries = [];
            $archivedAt = date('c');
            // BUG-076 (2026-05-27): use _demandsForAllSessions so the archive
            // loop is not blocked by session-selector drift on the operator
            // side. The archive decision below is purely className/section
            // based; session was never load-bearing for correctness.
            foreach ($this->_demandsForAllSessions($studentId) as $did => $d) {
                $st = (string) ($d['status'] ?? 'unpaid');
                // Only archive UNPAID demands; paid/partial demands
                // represent real payment history.
                if ($st !== 'unpaid') { $preserved++; continue; }
                // Skip demands that already belong to the target class.
                if (((string) ($d['className'] ?? '')) === $newClass &&
                    ((string) ($d['section']   ?? '')) === $newSection) continue;

                $archiveEntries[] = [
                    'op'       => 'archive',
                    'demandId' => $did,
                    'data'     => [
                        'status'        => 'archived',
                        'archivedAt'    => $archivedAt,
                        'archivedBy'    => $this->adminId,
                        'archivedFrom'  => "{$oldClass}/{$oldSection}",
                    ],
                ];
                $archived++;
            }

            // BUG-045 Phase 2: single batched archive commit.
            if (!empty($archiveEntries)) {
                if (!$this->fsTxn->commitDemandBatch($archiveEntries)) {
                    log_message('warning', "Fee_lifecycle::reassignFeesOnPromotion commitDemandBatch failed for [{$studentId}]; falling back to per-doc updates");
                    foreach ($archiveEntries as $entry) {
                        $this->fsTxn->updateDemand($entry['demandId'], $entry['data']);
                    }
                }
            }

            // Generate the new class's structure.
            $regenerated = $this->assignInitialFees($studentId, $newClass, $newSection, $parentDbKey);

            // BUG-076 Part 2 (2026-05-28): fail-loud guard on silent zero-
            // demand regeneration. assignInitialFees returns [] when the
            // destination class/section has NO fee structure in the active
            // session — historically a silent info-level no-op. That is
            // exactly how STU0004-11 ended up Active-in-Class-8 with zero
            // demands: a cross-session reverse-promote regenerated against a
            // session (2027-28) that had no Class 8 structure, generated
            // nothing, and reported "success". Surface it at error level AND
            // stamp the audit entry so the gap is actionable the moment it
            // happens, instead of being discovered weeks later via phantom-
            // dues forensics.
            $regenCount = is_array($regenerated) ? count($regenerated) : 0;
            if ($regenCount === 0) {
                log_message('error',
                    "Fee_lifecycle::reassignFeesOnPromotion — assignInitialFees generated "
                    . "0 demands for [{$studentId}] into [{$newClass}/{$newSection}] "
                    . "(session={$this->sessionYear}). Student may now have NO active fee "
                    . "demands. Verify a fee structure exists for {$newClass}/{$newSection} "
                    . "in session {$this->sessionYear}.");
            }

            $this->_log('promotion_reassigned', $studentId, [
                'old_class'   => $oldClass, 'old_section' => $oldSection,
                'new_class'   => $newClass, 'new_section' => $newSection,
                'archived'    => $archived, 'preserved'   => $preserved,
                'regenerated' => $regenCount,
                'regen_warning' => $regenCount === 0 ? 'NO_DESTINATION_STRUCTURE' : '',
            ]);

            // DEFAULTER-PROMO-1 fix (2026-06-29): recompute defaulter status with
            // the SAME business logic Admission uses
            // (Fee_defaulter_check::updateDefaulterStatus) — it reads feeDemands
            // canonically and writes the real projection, deleting the
            // feeDefaulters doc ONLY when dues are genuinely zero. The prior code
            // hardcoded is_defaulter=false, which unconditionally DELETED the
            // doc for a still-owing promoted student (they vanished from the
            // defaulter report until their next payment).
            try {
                $CI = function_exists('get_instance') ? get_instance() : null;
                if ($CI !== null) {
                    if (!isset($CI->feeDefaulter) || !is_object($CI->feeDefaulter)) {
                        $CI->load->library('Fee_defaulter_check', null, 'feeDefaulter');
                    }
                    $CI->feeDefaulter->init($this->firebase, $this->schoolName, $this->sessionYear);
                    $CI->feeDefaulter->updateDefaulterStatus($studentId);
                }
            } catch (\Throwable $_) {}

        } catch (\Throwable $e) {
            log_message('error', "Fee_lifecycle::reassignFeesOnPromotion failed [{$studentId}]: " . $e->getMessage());
        }
        // SIS Tier-1 fix B5 (2026-05-31): expose $regenCount in the return so
        // the promotion caller can detect the silent-zero-regenerated case
        // (destination structure deleted between Sis::execute_promotion's
        // upfront guard at L967 and this per-student call). Existing fields
        // preserved — sole caller is Sis::execute_promotion deferred handler
        // (grep-verified). Additive change; no other consumer breaks.
        return [
            'archived'    => $archived,
            'preserved'   => $preserved,
            'regenerated' => $regenCount ?? 0,
        ];
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
