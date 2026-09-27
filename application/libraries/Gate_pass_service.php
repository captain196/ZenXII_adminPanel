<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Gate_pass_service — student/staff same-day early-leave & late-entry, plus
 * the unified "who's on campus" muster query. Part of Campus Access
 * Management (MVP, 2026-08-09), sibling to Visitor_service (visitor-specific
 * logic stays there, untouched — purpose-specific approval policies are
 * NOT unified across the two; see class doc below).
 *
 * SCOPE DISCIPLINE (explicit operator instruction, 2026-08-09):
 *   - Dual approval (class teacher + parent) applies ONLY to STUDENT
 *     early-leave/exit. It is NOT a universal policy.
 *   - Staff early-leave uses a single admin approval — staff are adults;
 *     the teacher+parent model doesn't apply to them.
 *   - Late-entry (student or staff) needs NO approval — it's a same-day
 *     arrival record, not an authorization decision (matches the research
 *     finding that gate-lateness and classroom attendance are two parallel
 *     signals, never auto-merged).
 *   - Visitor/delivery/vendor/appointment approval policy is UNCHANGED —
 *     lives entirely in Visitor_service, this class never touches it.
 *
 * DOES NOT MODIFY Attendance.php / api_punch — that's shared, production
 * code requiring separate authorization (see spec §20). This is an
 * additive, standalone ledger.
 *
 * COLLECTIONS:
 *   campusAccessRequests — one doc per early-leave/late-entry action
 *   campusAccessEvents   — unified ledger (also written by Visitor_service
 *                           for visitor check-in/out — same collection,
 *                           same idempotent-entity-id discipline, see
 *                           Visitor_service::_syncCampusAccessEvent())
 */
class Gate_pass_service
{
    const COL_REQUESTS = 'campusAccessRequests';
    const COL_EVENTS    = 'campusAccessEvents';

    const TYPE_EARLY_LEAVE = 'early_leave';
    const TYPE_LATE_ENTRY  = 'late_entry';

    const PERSON_STUDENT = 'student';
    const PERSON_STAFF   = 'staff';

    const STATUS_PENDING  = 'pending_approval';
    const STATUS_APPROVED = 'approved';
    const STATUS_DENIED   = 'denied';
    const STATUS_RELEASED = 'released';
    const STATUS_LOGGED   = 'logged'; // late-entry, no approval gate

    const LIST_SCAN_LIMIT = 500;

    /**
     * F-02 (2026-08-09 correction pass): gate-pass release has NO scan/QR
     * hardware path in this MVP (that's visitor-checkout only, see
     * Visitor_service::ALLOWED_VERIFICATION_METHODS) — so the ONLY value a
     * caller may claim here is 'manual'. rfid/biometric/cardTap are refused
     * until the corresponding trusted hardware integration actually exists;
     * accepting them today would let a client silently claim a security
     * property ("scanned by a badge reader") the system never verified.
     */
    const ALLOWED_VERIFICATION_METHODS = ['manual'];

    const PARENT_CONSENT_METHODS = ['phone', 'in_person'];

    /** @var Firestore_service */
    private $fs;
    /** @var Numbering_service */
    private $numbering;
    private $schoolId = '';
    private $sessionYear = '';
    private $adminId = 'system';
    private $adminName = 'System';
    private $ready = false;

    public function init($fs, $numbering, string $schoolId, string $sessionYear = '', string $adminId = 'system', string $adminName = ''): self
    {
        $this->fs = $fs;
        $this->numbering = $numbering;
        $this->schoolId = $schoolId;
        $this->sessionYear = $sessionYear;
        $this->adminId = $adminId !== '' ? $adminId : 'system';
        $this->adminName = $adminName !== '' ? $adminName : $this->adminId;
        $this->ready = true;
        return $this;
    }

    private function _assertReady(): void
    {
        if (!$this->ready) {
            throw new \LogicException('Gate_pass_service::init() must be called before use.');
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  LOOKUPS
    // ══════════════════════════════════════════════════════════════════

    /**
     * F-10 (2026-08-09 correction pass): the raw bounded scan measured
     * ~1.6-2s per keystroke against a real tenant. Reuses the SAME 5-minute
     * per-tenant file-cache idiom already shipped for this exact problem in
     * Transport_service::searchStudents() (NF-1, 2026-08-04) — not a new
     * generic cache abstraction, just the established pattern applied here.
     * Cache holds the school's full lightweight index; substring filtering
     * still happens in PHP per request, so a stale cache entry can only
     * ever affect THIS school's own search results (cache key is
     * schoolId-scoped) — no cross-tenant leakage risk.
     */
    public function searchStudents(string $q, int $limit = 20): array
    {
        $this->_assertReady();
        $q = strtolower(trim($q));
        $index = $this->_cachedStudentIndex();
        $out = [];
        foreach ($index as $d) {
            if ($q !== '' && stripos($d['name'], $q) === false) continue;
            $out[] = $d;
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    private function _cachedStudentIndex(): array
    {
        $cacheKey = 'cam_students_' . md5($this->schoolId);
        $CI = &get_instance();
        $index = false;
        try {
            $CI->load->driver('cache', ['adapter' => 'file']);
            $index = $CI->cache->get($cacheKey);
        } catch (\Throwable $_) { $index = false; }

        if (is_array($index)) return $index;

        $index = [];
        $rows = $this->fs->schoolWhere('students', [], 'name', 'ASC', self::LIST_SCAN_LIMIT);
        foreach ($rows as $row) {
            $d = (array) ($row['data'] ?? []);
            $name = (string) ($d['name'] ?? '');
            if ($name === '') continue;
            $index[] = [
                'id' => (string) ($d['id'] ?? $this->_stripPrefix((string) ($row['id'] ?? ''))),
                'name' => $name,
                'className' => (string) ($d['className'] ?? ''),
                'section' => (string) ($d['section'] ?? ''),
                'userId' => (string) ($d['userId'] ?? ''),
            ];
        }
        try { $CI->cache->save($cacheKey, $index, 300); } catch (\Throwable $_) {}
        return $index;
    }

    private function _stripPrefix(string $docId): string
    {
        $prefix = $this->schoolId . '_';
        return strpos($docId, $prefix) === 0 ? substr($docId, strlen($prefix)) : $docId;
    }

    /**
     * F-08 (2026-08-09 correction pass): a gate-pass request used to accept
     * any personId string with no existence check — an arbitrary/typo'd id
     * would silently create a request for a "person" that isn't a real,
     * same-school student/staff record. getEntity() is already tenant-scoped
     * (deterministic {schoolId}_{id} docId), so a hit here also proves the
     * record belongs to THIS school, not just that some id string exists.
     */
    private function _resolvePerson(string $personType, string $personId): ?array
    {
        if ($personId === '') return null;
        $collection = $personType === self::PERSON_STUDENT ? 'students' : 'staff';
        try {
            $doc = $this->fs->getEntity($collection, $personId);
        } catch (\Throwable $e) {
            return null;
        }
        return is_array($doc) ? $doc : null;
    }

    /**
     * F-01 Part A (2026-08-09 correction pass): reuse the CANONICAL
     * student→class→teacher relationship (sections.classTeacherId, the same
     * field Subject_assignment_service cross-writes from the per-assignment
     * isClassTeacher flag, and the same lookup shape Ptm.php already relies
     * on) instead of trusting a client-submitted teacherStaffId outright.
     * Deliberately a single bounded schoolId== scan + in-PHP match — NOT a
     * 3-field compound Firestore query — so this needs no new composite
     * index (mirrors Ptm::_prefetch_sections's own reasoning).
     */
    private function _resolveClassTeacher(string $className, string $section): string
    {
        if ($className === '' || $section === '') return '';
        $norm = \Entity_firestore_sync::normalizeClassSection($className, $section);
        $wantClass = $norm['className'] !== '' ? $norm['className'] : $className;
        $wantSection = $norm['section'] !== '' ? $norm['section'] : $section;
        try {
            $rows = $this->fs->schoolWhere('sections', [], null, 'ASC', self::LIST_SCAN_LIMIT);
        } catch (\Throwable $e) {
            return '';
        }
        foreach ($rows as $row) {
            $d = (array) ($row['data'] ?? []);
            $c = (string) ($d['className'] ?? '');
            $s = (string) ($d['section'] ?? '');
            if ($c === $wantClass && $s === $wantSection) {
                return trim((string) ($d['classTeacherId'] ?? ''));
            }
        }
        return '';
    }

    private function _maskContact(string $contact): string
    {
        $digits = preg_replace('/\D+/', '', $contact) ?? '';
        if ($digits === '') return '';
        $tail = substr($digits, -3);
        return str_repeat('x', max(0, strlen($digits) - 3)) . $tail;
    }

    // ══════════════════════════════════════════════════════════════════
    //  EARLY LEAVE (approval-gated) — dual for students, single for staff
    // ══════════════════════════════════════════════════════════════════

    public function createEarlyLeaveRequest(array $input): array
    {
        $this->_assertReady();
        $personType = (string) ($input['personType'] ?? '');
        if (!in_array($personType, [self::PERSON_STUDENT, self::PERSON_STAFF], true)) {
            throw new \InvalidArgumentException('personType must be student or staff.');
        }
        $personId = trim((string) ($input['personId'] ?? ''));
        $reason = trim((string) ($input['reason'] ?? ''));
        $requestedTime = trim((string) ($input['requestedTime'] ?? ''));
        if ($personId === '' || $reason === '') {
            throw new \InvalidArgumentException('Person and reason are required.');
        }

        // F-08: reject a request for a personId that isn't a real, same-school record.
        $personRecord = $this->_resolvePerson($personType, $personId);
        if ($personRecord === null) {
            throw new \InvalidArgumentException(ucfirst($personType) . ' not found in this school.');
        }

        $resolvedName = trim((string) ($personRecord['name'] ?? ''));
        $personName = $resolvedName !== '' ? $resolvedName : trim((string) ($input['personName'] ?? ''));
        $id = $this->numbering->next('cam_gatepass', ['claimedBy' => $this->adminId, 'claimedFor' => 'GatePass:createEarlyLeaveRequest']);
        $now = date('c');

        if ($personType === self::PERSON_STUDENT) {
            // Dual approval — teacher AND parent, NEVER auto-approved. Parent
            // approval at MVP is a manually-recorded staff confirmation
            // (phone/in-person), not a Parent-app digital action — that's
            // Phase 2 (would require Kotlin app work, out of scope here).
            $approvals = [
                'teacher' => ['required' => true, 'status' => 'pending', 'by' => null, 'byName' => null, 'at' => null, 'note' => null],
                'parent'  => [
                    'required' => true, 'status' => 'pending', 'by' => null, 'byName' => null, 'at' => null, 'note' => null,
                    // WHO GAVE CONSENT (guardian) vs WHO RECORDED IT (by/byName
                    // above, the acting front-desk/admin staff member) — F-01
                    // Part B, kept distinct so the record never falsely reads
                    // as if the staff member WAS the parent.
                    'guardianName' => null, 'relationship' => null, 'contactMasked' => null,
                    'consentMethod' => null, 'consentAt' => null,
                ],
            ];
            // F-01 Part A: prefer the CANONICAL sections.classTeacherId
            // relationship over a client-submitted id. Client input is only
            // a fallback for the (real, expected) case where a section has
            // no class teacher configured yet — never silently discarded,
            // just demoted to 'manual' and still identity-checked at
            // approval time in recordApproval().
            $canonicalTeacherId = $this->_resolveClassTeacher(
                (string) ($personRecord['className'] ?? ''),
                (string) ($personRecord['section'] ?? '')
            );
            if ($canonicalTeacherId !== '') {
                $teacherStaffId = $canonicalTeacherId;
                $teacherSource = 'canonical';
            } else {
                $manualTeacherId = trim((string) ($input['teacherStaffId'] ?? ''));
                $manualTeacherRecord = $manualTeacherId !== '' ? $this->_resolvePerson(self::PERSON_STAFF, $manualTeacherId) : null;
                $teacherStaffId = $manualTeacherRecord !== null ? $manualTeacherId : '';
                $teacherSource = $teacherStaffId !== '' ? 'manual' : 'unresolved';
            }
        } else {
            // Staff early-leave — single admin/manager approval, no teacher/parent chain.
            $approvals = [
                'admin' => ['required' => true, 'status' => 'pending', 'by' => null, 'byName' => null, 'at' => null, 'note' => null],
            ];
            $teacherStaffId = '';
            $teacherSource = 'n/a';
        }

        $request = [
            'id' => $id,
            'personType' => $personType,
            'personId' => $personId,
            'personNameSnapshot' => $personName,
            'type' => self::TYPE_EARLY_LEAVE,
            'reason' => $reason,
            'requestedTime' => $requestedTime,
            'requestedBy' => $this->adminId,
            'teacherStaffId' => $teacherStaffId,
            'teacherSource' => $teacherSource, // 'canonical' | 'manual' | 'unresolved' | 'n/a' — audit trail for F-01 Part A
            'approvalPolicy' => $personType === self::PERSON_STUDENT ? 'dual_teacher_parent' : 'single_admin',
            'approvals' => $approvals,
            'status' => self::STATUS_PENDING,
            'releasedBy' => null, 'releasedAt' => null,
            'verificationMethod' => null, 'cardTapId' => null,
            'linkedEventId' => null,
            'createdBy' => $this->adminId,
        ];
        $this->fs->setEntity(self::COL_REQUESTS, $id, $request, false);

        if ($personType === self::PERSON_STUDENT && $teacherStaffId !== '') {
            $this->_emitPush('CAMPUS_ACCESS_APPROVAL_NEEDED', 'caapproval_' . $id, [
                'staffId' => $teacherStaffId,
                'pushType' => 'campus_access_approval_needed',
                'title' => 'Early-Leave Approval Needed',
                'body' => sprintf('%s — approval requested for early leave (%s).', $personName, $reason),
                'requestId' => $id,
            ]);
        }
        if ($personType === self::PERSON_STAFF) {
            $this->_fanoutOpsNotice(
                'staff_exit_request', 'normal', 'Staff Early-Leave Request',
                sprintf('%s requested early leave — %s.', $personName, $reason),
                ['requestId' => $id]
            );
        }

        return $this->fs->getEntity(self::COL_REQUESTS, $id) ?? $request;
    }

    /**
     * Record one explicit human approval/denial for one approval slot.
     * $role: 'teacher' | 'parent' | 'admin'. Never auto-fills — every call
     * is a real, logged human decision (2026-08-09 instruction: dual
     * approval must never degrade into an auto-approve for a student exit).
     *
     * F-01 (2026-08-09 correction pass) — identity binding, per slot:
     *   - teacher: the acting account must BE the request's resolved
     *     teacherStaffId, OR hold manage-level Campus Access as an explicit,
     *     reason-logged delegate. Without this, ANY edit-level holder could
     *     approve on behalf of a teacher who never saw the request — that
     *     was the exact gap.
     *   - admin (staff early-leave): the acting account must hold
     *     manage-level Campus Access — not just any edit-level holder —
     *     since this is the ONLY approval gate for a staff member's exit.
     *   - parent: unchanged front-desk phone/in-person consent proxy (no
     *     Parent-app login exists at MVP) — but an 'approved' decision now
     *     requires guardian identity fields ($consent), so the record shows
     *     WHO GAVE consent (guardian) distinct from WHO RECORDED it (by/byName,
     *     the acting staff member — never conflated with the guardian).
     *
     * $consent (only consulted for role='parent'|'teacher'):
     *   parent:  guardianName, relationship, consentMethod ('phone'|'in_person'), contact (optional, masked before storage)
     *   teacher (delegate path only): delegateReason
     */
    public function recordApproval(string $requestId, string $role, string $decision, string $note = '', array $consent = []): array
    {
        $this->_assertReady();
        if (!in_array($decision, ['approved', 'denied'], true)) {
            throw new \InvalidArgumentException('decision must be approved or denied.');
        }
        // BUG-053 fix (2026-08-10): this initial read is used ONLY for
        // identity/permission/input validation that does not depend on
        // concurrently-mutable state — teacherStaffId is fixed at request
        // creation, and permission checks depend on the acting session, not
        // the doc. The approvals-map read-modify-write itself happens fresh
        // inside the casUpdateEntity() mutator below, so two concurrent
        // decisions (e.g. teacher + parent, or a stale approval racing a
        // denial) can never silently clobber each other.
        $req = $this->_requireRequest($requestId);
        $approvalsPreview = (array) ($req['approvals'] ?? []);
        if (!isset($approvalsPreview[$role])) {
            throw new \InvalidArgumentException("This request has no '{$role}' approval slot.");
        }

        $isManageLevel = function_exists('has_permission') && has_permission('Campus Access', 'manage');
        $delegation = null;

        if ($role === 'teacher') {
            $boundTeacherId = trim((string) ($req['teacherStaffId'] ?? ''));
            $isBoundTeacher = $boundTeacherId !== '' && hash_equals($boundTeacherId, $this->adminId);
            if (!$isBoundTeacher) {
                if (!$isManageLevel) {
                    throw new \InvalidArgumentException($boundTeacherId === ''
                        ? 'No class teacher is bound to this request; a manage-level delegate approval (with a reason) is required.'
                        : 'You are not the class teacher bound to this request; a manage-level delegate approval (with a reason) is required.');
                }
                $delegateReason = trim((string) ($consent['delegateReason'] ?? ''));
                if ($delegateReason === '') {
                    throw new \InvalidArgumentException('A delegate approval requires a reason (audit trail).');
                }
                $delegation = [
                    'actingStaffId' => $this->adminId, 'actingStaffName' => $this->adminName,
                    'originalTeacherStaffId' => $boundTeacherId !== '' ? $boundTeacherId : null,
                    'reason' => $delegateReason, 'at' => date('c'),
                ];
            }
        } elseif ($role === 'admin') {
            if (!$isManageLevel) {
                throw new \InvalidArgumentException('Only a manage-level Campus Access holder may record the staff early-leave approval.');
            }
        }
        // role === 'parent': no actor-identity restriction — the front-desk
        // phone/in-person consent proxy model is explicitly unchanged.

        $now = date('c');
        $guardianFields = [];
        if ($role === 'parent' && $decision === 'approved') {
            $guardianName = trim((string) ($consent['guardianName'] ?? ''));
            $relationship = trim((string) ($consent['relationship'] ?? ''));
            $consentMethod = trim((string) ($consent['consentMethod'] ?? ''));
            if ($guardianName === '' || $relationship === '' || $consentMethod === '') {
                throw new \InvalidArgumentException('Guardian name, relationship, and consent method are required to record a parent approval.');
            }
            if (!in_array($consentMethod, self::PARENT_CONSENT_METHODS, true)) {
                throw new \InvalidArgumentException('consentMethod must be phone or in_person.');
            }
            $contact = trim((string) ($consent['contact'] ?? ''));
            $guardianFields = [
                'guardianName' => $guardianName, 'relationship' => $relationship,
                'consentMethod' => $consentMethod, 'consentAt' => $now,
                'contactMasked' => $contact !== '' ? $this->_maskContact($contact) : null,
            ];
        }

        // BUG-053 fix: concurrency-safe read-modify-write via the same
        // optimistic-CAS primitive (currentDocument.updateTime) BUG-028 used
        // for stream-mutation endpoints — NOT a new framework. The mutator
        // re-reads and re-decides from FRESH state on every retry, so a
        // concurrent decision can't be lost and a denial can't be reverted
        // by a stale-read approval landing after it.
        $adminId = $this->adminId;
        $adminName = $this->adminName;
        $res = $this->fs->casUpdateEntity(self::COL_REQUESTS, $requestId, function (array $doc) use (
            $role, $decision, $note, $delegation, $guardianFields, $now, $adminId, $adminName
        ) {
            if (($doc['status'] ?? '') !== self::STATUS_PENDING) {
                return null; // aborted — no longer pending (already decided or released)
            }
            $approvals = (array) ($doc['approvals'] ?? []);
            if (!isset($approvals[$role])) {
                return null; // aborted — slot vanished (defensive; shouldn't happen)
            }
            $slot = array_merge($approvals[$role], [
                'status' => $decision, 'by' => $adminId, 'byName' => $adminName, 'at' => $now, 'note' => $note,
            ]);
            if ($delegation !== null) {
                $slot['delegatedApproval'] = $delegation;
            }
            foreach ($guardianFields as $k => $v) {
                $slot[$k] = $v;
            }
            $approvals[$role] = $slot;

            $overall = self::STATUS_PENDING;
            $anyDenied = false;
            $allApproved = true;
            foreach ($approvals as $a) {
                if (!($a['required'] ?? true)) continue;
                if (($a['status'] ?? 'pending') === 'denied') { $anyDenied = true; }
                if (($a['status'] ?? 'pending') !== 'approved') { $allApproved = false; }
            }
            if ($anyDenied) $overall = self::STATUS_DENIED;
            elseif ($allApproved) $overall = self::STATUS_APPROVED;

            return ['approvals' => $approvals, 'status' => $overall];
        });

        if (!$res['ok'] && $res['reason'] === 'aborted') {
            throw new \InvalidArgumentException('This request is no longer pending approval.');
        }
        if (!$res['ok']) {
            throw new \RuntimeException('Could not record the approval decision — please retry.');
        }

        return $this->fs->getEntity(self::COL_REQUESTS, $requestId) ?? $req;
    }

    /**
     * Physical gate release — a separate step from approval (matches every
     * vendor pattern in the research: approval and physical release are
     * never the same click). MVP verification is manual; verificationMethod/
     * cardTapId are accepted-but-optional so RFID/QR integration later needs
     * no schema change (just start populating them from a real reader).
     */
    public function releaseGatePass(string $requestId, string $verificationMethod = 'manual', string $cardTapId = ''): array
    {
        $this->_assertReady();
        $req = $this->_requireRequest($requestId);
        if ($req['status'] !== self::STATUS_APPROVED) {
            throw new \InvalidArgumentException('Only an approved request can be released at the gate.');
        }
        // F-02: reject any claimed method this MVP can't actually verify —
        // no RFID/biometric/card-tap reader exists for gate-pass release yet.
        if (!in_array($verificationMethod, self::ALLOWED_VERIFICATION_METHODS, true)) {
            throw new \InvalidArgumentException("Unsupported verification method '{$verificationMethod}' — gate-pass release only supports manual verification in this MVP.");
        }
        $now = date('c');
        $eventId = $this->_writeEvent(
            (string) $req['personType'], (string) $req['personId'], (string) ($req['personNameSnapshot'] ?? ''),
            'out', 'gate_pass_request', $requestId, $verificationMethod, $cardTapId
        );
        $this->fs->updateEntity(self::COL_REQUESTS, $requestId, [
            'status' => self::STATUS_RELEASED, 'releasedBy' => $this->adminId, 'releasedAt' => $now,
            'verificationMethod' => $verificationMethod, 'cardTapId' => $cardTapId !== '' ? $cardTapId : null,
            'linkedEventId' => $eventId,
        ]);

        if ((string) $req['personType'] === self::PERSON_STUDENT) {
            $this->_notifyParentInformational(
                (string) $req['personId'],
                'Early Leave Confirmed',
                sprintf('%s has left campus (early leave, approved).', (string) ($req['personNameSnapshot'] ?? 'Your child'))
            );
        }
        return $this->fs->getEntity(self::COL_REQUESTS, $requestId) ?? $req;
    }

    // ══════════════════════════════════════════════════════════════════
    //  LATE ENTRY (no approval gate — a record, not an authorization)
    // ══════════════════════════════════════════════════════════════════

    public function logLateEntry(array $input): array
    {
        $this->_assertReady();
        $personType = (string) ($input['personType'] ?? '');
        if (!in_array($personType, [self::PERSON_STUDENT, self::PERSON_STAFF], true)) {
            throw new \InvalidArgumentException('personType must be student or staff.');
        }
        $personId = trim((string) ($input['personId'] ?? ''));
        if ($personId === '') {
            throw new \InvalidArgumentException('Person is required.');
        }
        // F-08: same existence/tenant check as the early-leave path.
        $personRecord = $this->_resolvePerson($personType, $personId);
        if ($personRecord === null) {
            throw new \InvalidArgumentException(ucfirst($personType) . ' not found in this school.');
        }
        $resolvedName = trim((string) ($personRecord['name'] ?? ''));
        $personName = $resolvedName !== '' ? $resolvedName : trim((string) ($input['personName'] ?? ''));
        $reason = trim((string) ($input['reason'] ?? ''));

        $id = $this->numbering->next('cam_gatepass', ['claimedBy' => $this->adminId, 'claimedFor' => 'GatePass:logLateEntry']);
        $eventId = $this->_writeEvent($personType, $personId, $personName, 'in', 'gate_pass_request', $id, 'manual', '');

        $request = [
            'id' => $id, 'personType' => $personType, 'personId' => $personId, 'personNameSnapshot' => $personName,
            'type' => self::TYPE_LATE_ENTRY, 'reason' => $reason, 'requestedTime' => date('c'),
            'requestedBy' => $this->adminId, 'approvalPolicy' => 'none', 'approvals' => [],
            'status' => self::STATUS_LOGGED,
            'releasedBy' => $this->adminId, 'releasedAt' => date('c'),
            'verificationMethod' => 'manual', 'cardTapId' => null, 'linkedEventId' => $eventId,
            'createdBy' => $this->adminId,
        ];
        $this->fs->setEntity(self::COL_REQUESTS, $id, $request, false);
        return $this->fs->getEntity(self::COL_REQUESTS, $id) ?? $request;
    }

    private function _requireRequest(string $id): array
    {
        $req = $this->fs->getEntity(self::COL_REQUESTS, $id);
        if ($req === null) {
            throw new \InvalidArgumentException("Gate pass request {$id} not found.");
        }
        return $req;
    }

    // ══════════════════════════════════════════════════════════════════
    //  QUERIES
    // ══════════════════════════════════════════════════════════════════

    public function listPending(int $limit = 200): array
    {
        $this->_assertReady();
        // 2026-08-10: was ordering by 'createdAt', a field that is never
        // actually written on this collection (createEarlyLeaveRequest()/
        // logLateEntry() only set 'createdBy' — see listToday()'s comment
        // for the full story). 'updatedAt' is the field Firestore_service::
        // setEntity() unconditionally stamps on every write, including the
        // initial create, so it's reliably present on every request doc,
        // past and future, with no backfill needed.
        return $this->fs->schoolWhere(self::COL_REQUESTS, [['status', '==', self::STATUS_PENDING]], 'updatedAt', 'ASC', min($limit, self::LIST_SCAN_LIMIT));
    }

    /**
     * The front-desk gate-pass board: today's activity PLUS any request
     * still open regardless of when it was created.
     *
     * 2026-08-10, two corrections in sequence:
     *   1. This originally had NO date filter at all despite its name and
     *      the "Today's gate passes" UI label — it returned the most
     *      recent 500 requests ever, full stop, so stale requests from
     *      days/weeks earlier (including old probe/test data) cluttered a
     *      screen that promised "today".
     *   2. Bounding strictly to today then hid the opposite failure mode —
     *      a request raised yesterday and never approved, or approved but
     *      never released, dropped off the board with no way to act on it
     *      from here. A gate-pass request is a live worklist item, not a
     *      pure activity log — status matters more than creation date for
     *      what NEEDS to stay visible.
     *   3. Both (1) and (2) filtered/ordered on 'createdAt' — a field this
     *      collection never actually writes (createEarlyLeaveRequest()/
     *      logLateEntry() only set 'createdBy', not 'createdAt'). A range
     *      filter on a field that doesn't exist on any document matches
     *      NOTHING, which is exactly why the board came up empty even
     *      moments after creating a request. Switched to 'updatedAt' —
     *      unconditionally stamped by Firestore_service::setEntity() on
     *      every write including the initial create, so it's reliably
     *      present on every request doc, past and future, no backfill
     *      needed. As a side effect this also means "today" now includes
     *      anything touched today (e.g. approved today though raised
     *      yesterday) — a reasonable reading of "today's activity" for a
     *      worklist, not just "created today".
     * Two bounded queries, merged + deduped in PHP (Firestore doesn't
     * support an OR across different fields the way this wrapper is used
     * elsewhere) — same field the orderBy already needed, no new composite
     * index beyond what listPending()/this already required.
     *
     *   4. (2026-08-10, same day) Root cause is deeper than a missing index.
     *      Direct isolated testing showed ANY inequality condition
     *      (updatedAt >= X, alone, with nothing else) on this collection
     *      returns an empty result set every time, while equality/'in'
     *      conditions plus an orderBy on the SAME field work fine. Tested
     *      the identical inequality shape against a completely different
     *      collection (visitPasses.checkInAt >= X) — also empty. That rules
     *      out an index problem specific to this collection/field; it's a
     *      platform-level issue in how Firestore_service's underlying REST
     *      client handles inequality filters, not something to fix inside
     *      one module's library file. Flagged separately; NOT touched here.
     *      Worked around by never sending an inequality condition to
     *      Firestore at all: fetch a bounded, ordered set with equality-only
     *      conditions (or none), then do both the date-boundary check and
     *      the status narrowing in PHP.
     *
     * @param string $statusFilter '' (all) or one of the STATUS_* constants.
     *   Filtering to an OPEN status (pending_approval/approved) narrows the
     *   backlog query to just that status. Filtering to a TERMINAL status
     *   (denied/released/logged) skips the backlog query entirely — a
     *   resolved item from a prior day isn't "backlog needing action", so
     *   only today's occurrences of that status are shown, consistent with
     *   the board's own "today + still-open" design.
     */
    public function listToday(int $limit = 500, string $statusFilter = ''): array
    {
        $this->_assertReady();
        $scanLimit = min($limit, self::LIST_SCAN_LIMIT);
        $startOfToday = date('Y-m-d\T00:00:00P');
        $startOfTomorrow = date('Y-m-d\T00:00:00P', strtotime('+1 day'));

        // No inequality condition sent to Firestore at all — see (4) above.
        // Equality/orderBy/limit only; the date boundary is checked in PHP.
        $recentRows = $this->fs->schoolWhere(self::COL_REQUESTS, [], 'updatedAt', 'DESC', $scanLimit);
        $todayRows = array_values(array_filter($recentRows, function ($row) use ($startOfToday, $startOfTomorrow) {
            $updatedAt = (string) ($row['data']['updatedAt'] ?? '');
            return $updatedAt >= $startOfToday && $updatedAt < $startOfTomorrow;
        }));
        if ($statusFilter !== '') {
            $todayRows = array_values(array_filter($todayRows, function ($row) use ($statusFilter) {
                return ($row['data']['status'] ?? '') === $statusFilter;
            }));
        }

        $openStatuses = [self::STATUS_PENDING, self::STATUS_APPROVED];
        if ($statusFilter !== '' && !in_array($statusFilter, $openStatuses, true)) {
            $openRows = []; // filtering to a terminal status — the open-backlog query doesn't apply
        } else {
            $openCondition = ['status', 'in', $statusFilter !== '' ? [$statusFilter] : $openStatuses];
            $openRows = $this->fs->schoolWhere(self::COL_REQUESTS, [$openCondition], 'updatedAt', 'DESC', $scanLimit);
        }

        $merged = [];
        foreach (array_merge($openRows, $todayRows) as $row) {
            $id = (string) ($row['id'] ?? ($row['data']['id'] ?? ''));
            if ($id === '' || isset($merged[$id])) continue;
            $merged[$id] = $row;
        }
        $rows = array_values($merged);
        usort($rows, function ($a, $b) {
            $ad = (string) ($a['data']['updatedAt'] ?? '');
            $bd = (string) ($b['data']['updatedAt'] ?? '');
            return strcmp($bd, $ad); // DESC, newest first
        });
        return array_slice($rows, 0, $scanLimit);
    }

    /**
     * Unified "who's on campus now" — bounded scan of campusAccessEvents,
     * reduced in PHP to each person's LATEST event (last-direction-wins).
     * MVP scope: real-time on-campus counts by personType. Full expected-
     * roster reconciliation (enrolled count vs. present vs. unaccounted)
     * needs a verified enrollment-count source this pass didn't build —
     * documented gap, not silently faked.
     */
    public function muster(int $scanLimit = 2000): array
    {
        $this->_assertReady();
        $rows = $this->fs->schoolWhere(self::COL_EVENTS, [], 'timestamp', 'DESC', min($scanLimit, 2000));
        $latestByPerson = [];
        foreach ($rows as $row) {
            $d = (array) ($row['data'] ?? []);
            $personType = (string) ($d['personType'] ?? '');
            $personId = (string) ($d['studentId'] ?? $d['staffId'] ?? $d['visitorId'] ?? '');
            if ($personType === '' || $personId === '') continue;
            $key = $personType . ':' . $personId;
            if (isset($latestByPerson[$key])) continue; // already saw a more recent event for this person
            $latestByPerson[$key] = $d;
        }
        $onCampus = ['student' => [], 'staff' => [], 'visitor' => []];
        foreach ($latestByPerson as $d) {
            if (($d['direction'] ?? '') !== 'in') continue;
            $type = (string) ($d['personType'] ?? '');
            if (!isset($onCampus[$type])) continue;
            $onCampus[$type][] = [
                'id' => (string) ($d['studentId'] ?? $d['staffId'] ?? $d['visitorId'] ?? ''),
                'name' => (string) ($d['personNameSnapshot'] ?? ''),
                'since' => (string) ($d['timestamp'] ?? ''),
            ];
        }
        return [
            'counts' => [
                'student' => count($onCampus['student']),
                'staff' => count($onCampus['staff']),
                'visitor' => count($onCampus['visitor']),
            ],
            'onCampus' => $onCampus,
            'scanned' => count($rows),
            'generatedAt' => date('c'),
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  UNIFIED LEDGER WRITE (idempotent — deterministic entity id)
    // ══════════════════════════════════════════════════════════════════

    private function _writeEvent(string $personType, string $personId, string $personName, string $direction, string $sourceType, string $sourceId, string $verificationMethod, string $cardTapId): string
    {
        $entityId = 'GP_' . $sourceId . '_' . $direction; // deterministic -> retry-safe, never duplicates
        $data = [
            'id' => $entityId, 'personType' => $personType, 'personNameSnapshot' => $personName,
            'direction' => $direction, 'gateId' => '', 'timestamp' => date('c'),
            'verificationMethod' => $verificationMethod, 'cardTapId' => $cardTapId !== '' ? $cardTapId : null,
            'authorizedBy' => $this->adminId, 'sourceType' => $sourceType, 'linkedRequestId' => $sourceId,
        ];
        if ($personType === self::PERSON_STUDENT) $data['studentId'] = $personId;
        if ($personType === self::PERSON_STAFF) $data['staffId'] = $personId;
        $this->fs->setEntity(self::COL_EVENTS, $entityId, $data, false);
        return $entityId;
    }

    // ══════════════════════════════════════════════════════════════════
    //  NOTIFICATIONS
    // ══════════════════════════════════════════════════════════════════

    /** Canonical parent per-user contract (Attendance.php's notifDocId pattern) — informational only, not an approval action. */
    private function _notifyParentInformational(string $studentId, string $title, string $body): void
    {
        try {
            $student = $this->fs->getEntity('students', $studentId);
            $userId = (string) ($student['userId'] ?? '');
            if ($userId === '') return;
            $notifDocId = $this->schoolId . '_GP_' . $studentId . '_' . date('YmdHis');
            $this->fs->set('notifications', $notifDocId, [
                'schoolId' => $this->schoolId, 'userId' => $userId, 'type' => 'campus_access',
                'title' => $title, 'body' => $body, 'priority' => 'normal', 'read' => false,
                'createdAt' => date('c'), 'data' => ['studentId' => $studentId],
            ], false);
        } catch (\Throwable $e) {
            log_message('error', 'Gate_pass_service::_notifyParentInformational failed: ' . $e->getMessage());
        }
    }

    private function _emitPush(string $mark, string $dedupeKey, array $fields): void
    {
        try {
            $reqId = $this->fs->docId($dedupeKey);
            $payload = array_merge([
                'schoolId' => $this->schoolId, 'mark' => $mark, 'status' => 'pending',
                'markedBy' => $this->adminName, 'createdAt' => date('c'),
            ], $fields);
            $this->fs->set('pushRequests', $reqId, $payload, false);
        } catch (\Throwable $e) {
            log_message('error', 'Gate_pass_service::_emitPush failed: ' . $e->getMessage());
        }
    }

    /** Same canonical Ops/Staff bell shape as Visitor_service::_fanoutOpsNotice — duplicated intentionally (small, no shared base class yet). */
    private function _fanoutOpsNotice(string $category, string $priority, string $title, string $body, array $ctx): void
    {
        try {
            $now = date('c');
            $rawId = 'CAM_' . strtoupper($category) . '_' . date('YmdHis') . '_' . substr(md5(json_encode($ctx)), 0, 6);
            $notice = [
                'id' => $rawId, 'title' => $title, 'body' => $body, 'description' => $body,
                'author' => 'Campus Access (System)', 'authorId' => $this->adminId, 'authorRole' => 'system',
                'category' => 'Campus Access', 'priority' => $priority,
                'targetType' => 'role', 'targetClasses' => [], 'targetRoles' => ['Admin', 'Front Office'],
                'status' => 'sent', 'sentAt' => $now, 'createdAt' => $now,
                'timestamp' => $now, 'timestampMs' => (int) round(microtime(true) * 1000),
                'module' => 'Campus Access', 'camCategory' => $category, 'camContext' => $ctx,
            ];
            $this->fs->setEntity('notices', $rawId, $notice, false);
            foreach (['Admin', 'Front Office'] as $role) {
                $recipientId = $rawId . '_' . preg_replace('/\s+/', '', $role);
                $this->fs->setEntity('noticeRecipients', $recipientId, [
                    'noticeId' => $rawId, 'recipientType' => 'role', 'recipientKey' => $role,
                    'read' => false, 'createdAt' => $now,
                ], false);
            }
        } catch (\Throwable $e) {
            log_message('error', 'Gate_pass_service::_fanoutOpsNotice failed: ' . $e->getMessage());
        }
    }
}
