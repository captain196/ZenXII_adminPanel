<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Student_relationship_service — VERIFIED relationships declared at admission.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  WHY THIS EXISTS
 * ─────────────────────────────────────────────────────────────────────────
 *  The admission form has long offered two relationship declarations:
 *
 *      [ ] A brother or sister already studies at this school   (+ free text)
 *      [ ] A parent or guardian is employed by this school
 *
 *  Neither did anything. The sibling box captured a free-text "name /
 *  admission number" that NO code read; the employment box captured nothing at
 *  all. On the direct-admission path both were posted and discarded — an
 *  admitted student carried no field matching either. On the online path they
 *  were stored on the application and then only pre-selected a dropdown.
 *
 *  So staff ticked a box, typed a name, saw "Student admitted successfully",
 *  and nothing existed afterwards. That is the exact shape of defect this
 *  programme exists to remove: a control that reports success and produces no
 *  record.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  WHAT A "VERIFIED" RELATIONSHIP MEANS HERE
 * ─────────────────────────────────────────────────────────────────────────
 *  A relationship is only written when the OTHER party was resolved to a real,
 *  same-tenant record by its canonical identifier:
 *
 *    sibling     -> SR Number, through MY_Controller::_resolveStudentRef(),
 *                   which delegates to Sr_no_service scoped to $school_id.
 *                   SR No is what a parent can actually recite; admission
 *                   numbers are not (operator requirement, 2026-08-30).
 *    staff_ward  -> Staff ID, resolved against this school's `staff`
 *                   collection, and the employee must be ACTIVE.
 *
 *  The caller passes the identifier the operator typed, never a client-side
 *  id. Everything is re-resolved server-side, so a tampered hidden field
 *  cannot fabricate a link to another tenant's student.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  THE FINANCIAL BOUNDARY — READ THIS BEFORE EXTENDING
 * ─────────────────────────────────────────────────────────────────────────
 *      VERIFIED RELATIONSHIP  !=  AUTOMATIC FINANCIAL BENEFIT
 *
 *  This service NEVER creates an entitlement, a concession, a discount or a
 *  demand. It records who is related to whom, verified and audited. Any money
 *  that follows must go through the canonical concession path
 *  (Entitlement_service, maker-checker, allocation ledger), where it carries a
 *  reason, an approver and an allocation — the things invariant I2 exists to
 *  guarantee.
 *
 *  ZenX has NO ratified automatic sibling or staff-ward discount policy. Two
 *  DISAGREEING sibling definitions already exist in the codebase and neither is
 *  authoritative:
 *    • Fee_lifecycle::calculateSiblingDiscount() groups by `parentDbKey` — and
 *      is DEAD CODE, zero callers.
 *    • Fee_management's bulk concession groups by FATHER-NAME STRING EQUALITY,
 *      which merges unrelated families who share a common name and splits real
 *      siblings whose records spell the father differently.
 *  This collection is intended to become the canonical third answer that
 *  retires both — but making it drive money is a pricing decision that has not
 *  been taken, so it is deliberately not wired to any generator.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  IDEMPOTENCY AND SHAPE
 * ─────────────────────────────────────────────────────────────────────────
 *  Document id is deterministic: {studentId}_{kind}_{relatedId} (the helper
 *  prefixes {schoolId}_ itself, per the RC-3 convention). Re-submitting the
 *  same admission, or linking the same sibling twice, collides on one document
 *  instead of creating a second — duplicate prevention is structural, not a
 *  check that can be raced.
 *
 *  A sibling link is symmetric and BOTH directions are written, so a reader can
 *  answer "who are this student's siblings?" with a single equality query
 *  rather than an OR across two fields. Both rows are deterministic, so the
 *  pair converges no matter which sibling is admitted first.
 */
class Student_relationship_service
{
    private const COL = 'studentRelationships';

    /** The only relationship kinds this service will write. */
    public const KIND_SIBLING    = 'sibling';
    public const KIND_STAFF_WARD = 'staff_ward';
    private const KINDS = [self::KIND_SIBLING, self::KIND_STAFF_WARD];

    /** @var object|null Firestore_helper */
    private $fs;
    /** @var object|null raw firebase client, for the staff lookup */
    private $firebase;

    private string $schoolId    = '';
    private string $sessionYear = '';
    private string $actorId     = '';
    private bool   $ready       = false;

    public function init($fs, $firebase, string $schoolId, string $sessionYear, string $actorId): self
    {
        $this->fs          = $fs;
        $this->firebase    = $firebase;
        $this->schoolId    = trim($schoolId);
        $this->sessionYear = trim($sessionYear);
        $this->actorId     = trim($actorId) !== '' ? trim($actorId) : 'system';
        $this->ready       = ($this->fs !== null && $this->schoolId !== '');
        return $this;
    }

    private function assertReady(): void
    {
        if (!$this->ready) {
            throw new \RuntimeException('Student_relationship_service not initialised.');
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    //  LOOKUPS — resolve-and-show, so staff confirm a PERSON, not a number
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Resolve a staff member by Staff ID within this tenant.
     *
     * Deliberately an EXACT id lookup rather than a name search: a name search
     * invites "there are three people called Sharma, pick one", which is how an
     * unverified relationship gets recorded as a verified one.
     *
     * @return array{ok:bool, staffId?:string, name?:string, error?:string}
     */
    public function lookupStaff(string $staffIdRaw): array
    {
        $this->assertReady();
        $staffId = trim($staffIdRaw);
        if ($staffId === '') {
            return ['ok' => false, 'error' => 'Staff ID is required.'];
        }

        $doc = $this->fs->get('staff', $this->schoolId . '_' . $staffId);
        if (!is_array($doc) || $doc === []) {
            return ['ok' => false, 'error' => "Staff ID '{$staffId}' not found in this school."];
        }

        // Tenant belt-and-braces: the id already carries the prefix, but a
        // stored schoolId that disagrees means the document is not ours.
        if ((string) ($doc['schoolId'] ?? '') !== $this->schoolId) {
            log_message('error', "REL_STAFF_CROSS_TENANT school=[{$this->schoolId}] staff=[{$staffId}]");
            return ['ok' => false, 'error' => "Staff ID '{$staffId}' not found in this school."];
        }

        // An employee who has left cannot be the basis of an employment claim.
        $status = strtolower(trim((string) ($doc['status'] ?? $doc['Status'] ?? 'active')));
        if ($status !== '' && $status !== 'active') {
            return ['ok' => false,
                    'error' => "Staff ID '{$staffId}' is not an active employee (status: {$status})."];
        }

        return [
            'ok'      => true,
            'staffId' => $staffId,
            'name'    => trim((string) ($doc['name'] ?? $doc['Name'] ?? $staffId)),
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    //  WRITES
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Record a VERIFIED sibling link, both directions.
     *
     * @param array $sibling the resolved counterpart: studentId, name, srNo
     * @return array{ok:bool, relationshipId?:string, error?:string}
     */
    public function linkSibling(string $studentId, array $sibling, string $source = 'admission'): array
    {
        $this->assertReady();
        $studentId  = trim($studentId);
        $siblingId  = trim((string) ($sibling['studentId'] ?? ''));

        if ($studentId === '' || $siblingId === '') {
            return ['ok' => false, 'error' => 'Both students are required to record a sibling link.'];
        }
        // A student is not their own sibling. Without this, an operator who
        // types the SR No of the child being admitted creates a self-loop that
        // every later sibling count would treat as a second child.
        if ($studentId === $siblingId) {
            return ['ok' => false, 'error' => 'A student cannot be recorded as their own sibling.'];
        }

        $id = $this->write($studentId, self::KIND_SIBLING, $siblingId, [
            'relatedType'  => 'student',
            'relatedName'  => trim((string) ($sibling['name'] ?? '')),
            'relatedSrNo'  => trim((string) ($sibling['srNo'] ?? '')),
            'verifiedVia'  => 'srNo',
        ], $source);

        // The mirror. Written second and best-effort: if it fails the forward
        // link still stands and the pair is repairable, whereas failing the
        // whole operation would lose a verification the operator already did.
        try {
            $this->write($siblingId, self::KIND_SIBLING, $studentId, [
                'relatedType'  => 'student',
                'relatedName'  => '',
                'relatedSrNo'  => '',
                'verifiedVia'  => 'srNo',
                'mirrorOf'     => $id,
            ], $source);
        } catch (\Throwable $e) {
            log_message('error', "REL_SIBLING_MIRROR_FAILED {$studentId}<->{$siblingId}: " . $e->getMessage());
        }

        return ['ok' => true, 'relationshipId' => $id];
    }

    /**
     * Record a VERIFIED parent/guardian-is-an-employee link.
     * One direction only: the claim belongs to the student's record.
     *
     * @return array{ok:bool, relationshipId?:string, error?:string}
     */
    public function linkStaffWard(string $studentId, array $staff, string $source = 'admission'): array
    {
        $this->assertReady();
        $studentId = trim($studentId);
        $staffId   = trim((string) ($staff['staffId'] ?? ''));

        if ($studentId === '' || $staffId === '') {
            return ['ok' => false, 'error' => 'Student and staff member are both required.'];
        }

        $id = $this->write($studentId, self::KIND_STAFF_WARD, $staffId, [
            'relatedType' => 'staff',
            'relatedName' => trim((string) ($staff['name'] ?? '')),
            'verifiedVia' => 'staffId',
        ], $source);

        return ['ok' => true, 'relationshipId' => $id];
    }

    /**
     * The single write. Deterministic id ⇒ re-linking updates one document
     * rather than creating a second.
     */
    private function write(string $studentId, string $kind, string $relatedId, array $extra, string $source): string
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException("Unknown relationship kind: {$kind}");
        }
        $docId = "{$studentId}_{$kind}_{$relatedId}";
        $now   = date('c');

        $existing = $this->fs->get(self::COL, $this->schoolId . '_' . $docId);
        $isNew    = !is_array($existing) || $existing === [];

        $this->fs->set(self::COL, $this->schoolId . '_' . $docId, array_merge([
            'schoolId'     => $this->schoolId,
            'session'      => $this->sessionYear,
            'studentId'    => $studentId,
            'kind'         => $kind,
            'relatedId'    => $relatedId,
            'status'       => 'active',
            'source'       => $source,
            // The audit trail. A relationship that nobody can be asked about
            // is not verified, whatever the flag says.
            'verifiedBy'   => $this->actorId,
            'verifiedAt'   => $isNew ? $now : (string) ($existing['verifiedAt'] ?? $now),
            'updatedAt'    => $now,
            // Stated explicitly on every row so that anyone reading this
            // collection later — including a future sibling-discount feature —
            // sees that no money was implied by its existence.
            'financialEffect' => 'none',
        ], $extra), /* merge */ true);

        return $docId;
    }

    // ══════════════════════════════════════════════════════════════════════
    //  READS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Every active relationship recorded for one student, this tenant only.
     * @return array<int, array>
     */
    public function forStudent(string $studentId, string $kind = ''): array
    {
        $this->assertReady();
        $studentId = trim($studentId);
        if ($studentId === '') return [];

        $conds = [['studentId', '=', $studentId]];
        if ($kind !== '') $conds[] = ['kind', '=', $kind];

        // schoolWhere(), not querySchool(): $this->fs here is Firestore_service,
        // which has no querySchool. The first version called it, so this read
        // ALWAYS threw and forStudent() never returned a relationship — a defect
        // the earlier phase missed because its tests exercised only writes and
        // lookups. Caught the moment a benefit evaluation actually read them.
        $rows = (array) $this->fs->schoolWhere(self::COL, $conds);

        $out = [];
        foreach ($rows as $r) {
            $d = $r['data'] ?? $r;
            if (!is_array($d)) continue;
            if ((string) ($d['status'] ?? 'active') !== 'active') continue;
            $out[] = $d;
        }
        return $out;
    }
}
