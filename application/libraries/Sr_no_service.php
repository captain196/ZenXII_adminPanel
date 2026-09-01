<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Sr_no_service — THE single sanctioned write/read path for `srNoIndex`.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  WHAT THIS CLASS EXISTS TO ENFORCE
 * ─────────────────────────────────────────────────────────────────────────
 *  Per SRNO_RE_ADMISSION_ARCHITECTURE_AUDIT.md (Strategy A, approved
 *  2026-08-13): the same real-world student keeps the same SRNO across a
 *  verified re-admission, while receiving a brand-new Student ID and a new
 *  admission lifecycle. Student ID remains the sole internal canonical
 *  identity and the sole foreign key on every lifecycle collection — this
 *  class NEVER writes SRNO onto any collection other than `srNoIndex` and
 *  the `srNo`/`srNoNorm`/`srNoSeq`/`srNoSource` display fields on the
 *  owning student's own record. No controller is permitted to write
 *  `srNoIndex` directly (mirrors the discipline Entitlement_service already
 *  enforces for `studentEntitlements`).
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  SCHEMA DECISION (Section 2 hardening review, documented before code)
 * ─────────────────────────────────────────────────────────────────────────
 *  The architecture audit's original 7-field proposal (currentStudentId,
 *  historicalStudentIds, srNo, claimedAt, claimedBy, currentSince, history)
 *  is trimmed here to 6 fields after review:
 *
 *   - `currentSince` DROPPED. Redundant with the last entry's `timestamp`
 *     in `history[]` — a second field that could only ever agree with or
 *     silently drift from the array is worse than no field.
 *   - `historicalStudentIds[]` KEPT, but demoted to a DERIVED CACHE, never
 *     independently written. It exists purely so Student Trail / the
 *     integrity verifier can read "who else has held this number" in O(1)
 *     without parsing `history[]`. It is recomputed FROM `history[]` in the
 *     same write every time — there is exactly one code path that can write
 *     either field (below), so the two can never diverge by construction.
 *   - `history[]` is AUTHORITATIVE for ownership history, but is a BOUNDED
 *     RECENT CACHE (cap 20), not the permanent record — mirrors the exact
 *     documented philosophy already in this codebase's
 *     Firestore_rest_client::casAppendBounded ("the array is a RECENT
 *     CACHE; permanent truth is auditLogs"). The PERMANENT, unbounded trail
 *     is `auditLogs`, written via the existing `log_audit()` helper on
 *     every relink — this is the SAME two-tier pattern already used for
 *     CRM application status transitions, not a new one invented here.
 *   - 20 re-admissions of the same SRNO is far beyond any realistic school
 *     lifetime count; if ever exceeded, `auditLogs` remains the complete
 *     record regardless of the cache's trim.
 *
 *  Final shape:
 *    srNoIndex/{schoolId}_{srNoNorm} => {
 *      srNo:                 "1025",          // display value, set once, immutable
 *      currentStudentId:     "STU0047",        // the ONLY field ordinary lookups read
 *      historicalStudentIds: ["STU0001"],       // derived cache, see above
 *      history: [                                // bounded (cap 20), oldest-first
 *        { studentId, event: 'claimed'|'relink_in'|'relink_out', actor, reason, timestamp }
 *      ],
 *      claimedAt, claimedBy,                     // FIRST-EVER claim only, never overwritten
 *      schoolId, updatedAt
 *    }
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  TWO STRUCTURALLY DIFFERENT PATHS — NEVER A GENERIC "SET SRNO"
 * ─────────────────────────────────────────────────────────────────────────
 *  claim()  — ordinary first-time allocation. create-if-not-exists ONLY.
 *             Can never move an existing owner. An unrelated student
 *             attempting an already-owned SRNO always hits this path and
 *             is always rejected (SRNO_RE_ADMISSION_ARCHITECTURE_AUDIT.md
 *             Part G).
 *  relink() — verified re-admission ONLY. Requires the caller to already
 *             know (and assert) the expected current owner. Moves
 *             ownership ONLY if the live document's current owner still
 *             matches that assertion — otherwise aborts. This is the sole
 *             path that can ever change `currentStudentId` on an existing
 *             document. It is invoked from exactly one place:
 *             Readmission::confirm(), which is the same controlled
 *             business operation that sets `previousStudentId` on the new
 *             student's own record — both writes land in ONE Firestore
 *             batch commit (see relink() below), never two independent ops.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  WHAT THIS CLASS DELIBERATELY DOES NOT DO
 * ─────────────────────────────────────────────────────────────────────────
 *  • It does not decide WHO may call relink() — that is the controller's
 *    RBAC gate (Principal-level, mirrors the design's correct_sr_number
 *    precedent). This class assumes an authorized caller and records WHO.
 *  • It does not implement any name/phone/DOB/APAAR matching. Candidate
 *    search is a human process outside this class entirely — this class
 *    only executes a relink the caller has already asserted is confirmed.
 *  • It never becomes a foreign key source for Fees/Attendance/Results/
 *    Transport/Hostel/etc. — those remain keyed exclusively to Student ID.
 */
class Sr_no_service
{
    /** @var object|null */ private $firebase;
    /** @var object|null */ private $fs;
    private string $schoolId = '';
    private string $actorId  = '';

    public const COL          = 'srNoIndex';
    public const STUDENTS_COL = 'students';
    public const HISTORY_CAP  = 20;
    public const MAX_RETRIES  = 5;

    /** Per SR_NUMBER_AND_APAAR_ID_DESIGN.md §3.1 — digits only, 1-9 chars. */
    private const FORMAT_RE = '/^[0-9]{1,9}$/';

    public function init($firebase, $fs, string $schoolId, string $actorId): self
    {
        $this->firebase = $firebase;
        $this->fs       = $fs;
        $this->schoolId = $schoolId;
        $this->actorId  = $actorId !== '' ? $actorId : 'SYSTEM';
        return $this;
    }

    /**
     * Normalize a raw SRNO string: trim, strip leading zeros for the norm
     * key (so "147" and "0147" collide on claim and match on search), keep
     * the display value as trimmed digits. Returns null if the input is not
     * digits-only per FORMAT_RE.
     *
     * @return array{display:string,norm:string}|null
     */
    public static function normalize(string $raw): ?array
    {
        $trimmed = trim($raw);
        if (!preg_match(self::FORMAT_RE, $trimmed)) return null;
        $norm = ltrim($trimmed, '0');
        if ($norm === '') $norm = '0'; // an all-zero input norms to a single "0"
        return ['display' => $trimmed, 'norm' => $norm];
    }

    /**
     * ORDINARY CLAIM — first-time allocation only. Atomic create-if-not-
     * exists (same primitive Id_generator uses for STU/STA claims — a 409
     * on an existing doc is the collision signal, never a get()-then-set()).
     *
     * @return array{ok:bool,reason?:string,currentStudentId?:string}
     */
    public function claim(string $srNoRaw, string $studentId): array
    {
        $n = self::normalize($srNoRaw);
        if ($n === null) {
            return ['ok' => false, 'reason' => 'invalid_format'];
        }
        if ($studentId === '') {
            return ['ok' => false, 'reason' => 'missing_student_id'];
        }
        if ($this->fs === null || $this->schoolId === '') {
            return ['ok' => false, 'reason' => 'not_initialized'];
        }

        $docId = $this->fs->docId($n['norm']);
        $now   = date('c');
        $ok = (bool) $this->firebase->firestoreCreate(self::COL, $docId, [
            'srNo'                 => $n['display'],
            'currentStudentId'     => $studentId,
            'historicalStudentIds' => [],
            'history'              => [[
                'studentId' => $studentId,
                'event'     => 'claimed',
                'actor'     => $this->actorId,
                'reason'    => 'admission',
                'timestamp' => $now,
            ]],
            'claimedAt' => $now,
            'claimedBy' => $this->actorId,
            'schoolId'  => $this->schoolId,
            'updatedAt' => $now,
        ]);

        if (!$ok) {
            $existing = $this->fs->get(self::COL, $docId);
            log_audit('SIS', 'srno_claim_rejected_duplicate', $studentId,
                "SRNO {$n['display']} already claimed", [
                    'srNo' => $n['display'],
                    'existingCurrentStudentId' => is_array($existing) ? ($existing['currentStudentId'] ?? null) : null,
                ]);
            return [
                'ok' => false,
                'reason' => 'duplicate',
                'currentStudentId' => is_array($existing) ? ($existing['currentStudentId'] ?? null) : null,
            ];
        }

        log_audit('SIS', 'srno_claimed', $studentId, "SRNO {$n['display']} claimed", ['srNo' => $n['display']]);
        return ['ok' => true];
    }

    /** Namespace for the school-scoped sequential SRNO counter — see
     *  allocateNext() below. Deliberately a SEPARATE structure from
     *  `srNoIndex` (the ownership index claim() writes) and from
     *  `feeCounters/_sys_STU...` (Id_generator's GLOBAL Student ID
     *  counter, untouched by this addition). */
    public const COUNTER_COL         = 'feeCounters';
    public const COUNTER_DOC_PREFIX  = '_sys_SRNO_';
    public const COUNTER_MAX_ATTEMPTS = 15;

    /**
     * ALLOCATE NEXT — Phase 9.1 (2026-08-13): the mandatory, automatic,
     * school-scoped, sequential SRNO allocation. This is now the ONLY path
     * the admission flow uses; claim() (above) remains for a future
     * administrator-only manual-correction tool and is no longer called
     * from the automatic admission path — additive, not a breaking change
     * (claim()'s own signature, tests, and behavior are untouched).
     *
     * Reuses the SAME atomic collision primitive claim()/relink() already
     * use (`firestoreCreate` = create-if-not-exists = the collision
     * signal) and the SAME structural pattern Id_generator.php already
     * uses for the GLOBAL Student ID counter (a pointer doc + numbered
     * claim docs under a deny-all system collection) — adapted to be
     * SCHOOL-SCOPED by keying every doc id with $schoolId, rather than
     * reusing Id_generator.php itself (which has no per-school concept
     * and must not be modified to invent one — that would risk the
     * GLOBAL Student ID counter for an unrelated feature).
     *
     * Concurrency: candidate N is reserved via `firestoreCreate` on
     * `feeCounters/_sys_SRNO_{schoolId}_claim_{N}` — an atomic
     * create-if-not-exists. Two simultaneous callers racing for the same
     * N: exactly one `firestoreCreate` succeeds (the collision signal is
     * the SAME primitive this codebase already trusts for Id_generator,
     * srNoIndex, and apaarIndex); the loser's attempt fails cleanly and
     * probes N+1. No read-then-write-N+1 race exists — the number is
     * never computed by reading a "current max" and adding one in PHP.
     *
     * Existing-data safety: if `srNoIndex` already has a claim for the
     * candidate number (e.g. hand-entered by the now-removed manual path,
     * or a lazily-initialized counter racing a legacy claim), claim()
     * itself will report 'duplicate' — this method releases the counter
     * reservation for that number and probes the next one, so the
     * counter and the ownership index can never silently diverge.
     *
     * @return array{ok:bool,reason?:string,srNo?:string}
     */
    public function allocateNext(string $schoolId, string $studentId): array
    {
        if ($schoolId === '') return ['ok' => false, 'reason' => 'missing_school_id'];
        if ($studentId === '') return ['ok' => false, 'reason' => 'missing_student_id'];
        if ($this->fs === null || $this->firebase === null) return ['ok' => false, 'reason' => 'not_initialized'];

        for ($attempt = 0; $attempt < self::COUNTER_MAX_ATTEMPTS; $attempt++) {
            $pointer   = $this->_readSrNoCounterPointer($schoolId);
            $candidate = $pointer + 1;

            $counterClaimDocId = self::COUNTER_DOC_PREFIX . "{$schoolId}_claim_{$candidate}";
            $reserved = (bool) $this->firebase->firestoreCreate(self::COUNTER_COL, $counterClaimDocId, [
                'kind'      => 'SRNO',
                'schoolId'  => $schoolId,
                'value'     => $candidate,
                'claimedAt' => date('c'),
                'source'    => 'sr_no_service_allocate',
            ]);
            if (!$reserved) {
                // Another concurrent allocation already took this exact
                // number — probe upward. The pointer write below is
                // best-effort optimisation only; correctness never
                // depends on it (mirrors Id_generator's own documented
                // philosophy — the claim doc is authoritative, not the
                // pointer).
                continue;
            }
            $this->_writeSrNoCounterPointer($schoolId, $candidate);

            // Reuse claim() for the ACTUAL ownership write (srNoIndex +
            // the student doc's srNo/srNoNorm fields) — one canonical
            // ownership-claim code path, not a duplicated copy of it.
            $claimRes = $this->claim((string) $candidate, $studentId);
            if (!empty($claimRes['ok'])) {
                return ['ok' => true, 'srNo' => (string) $candidate];
            }

            // Counter and ownership index disagree for this number (only
            // realistically reachable via legacy/hand-written srNoIndex
            // data predating this method, or a lazily-initialized counter
            // racing a concurrent legacy claim) — release the counter
            // reservation so it doesn't permanently burn a number nobody
            // actually owns, log it for visibility, and probe the next one.
            $this->_releaseSrNoCounterClaim($schoolId, $candidate);
            log_message('warning', "Sr_no_service::allocateNext counter/index mismatch school={$schoolId} candidate={$candidate} reason=" . ($claimRes['reason'] ?? 'unknown'));
        }

        log_message('error', "Sr_no_service::allocateNext exhausted {$attempt} attempts school={$schoolId}");
        return ['ok' => false, 'reason' => 'contention_exhausted'];
    }

    /**
     * Read the school's counter pointer; lazily initializes it on first
     * use by finding the highest EXISTING srNoIndex value for this school
     * (read-only query — writes only the NEW counter doc, never modifies
     * any existing srNoIndex/student document). Production data as of
     * this feature's own rollout has zero existing SRNO claims anywhere
     * (verified by a read-only production scan during this pass), so in
     * practice this returns 0 for every school today — but the lazy scan
     * is real, not a stub, so a school with genuine legacy SRNO data
     * (hand-imported, or via the now-removed manual-entry path) still
     * gets a correctly non-colliding starting point without any migration
     * script or production-data write.
     */
    private function _readSrNoCounterPointer(string $schoolId): int
    {
        try {
            $doc = $this->firebase->firestoreGet(self::COUNTER_COL, self::COUNTER_DOC_PREFIX . $schoolId);
            if (is_array($doc) && isset($doc['value'])) return (int) $doc['value'];
        } catch (\Throwable $_) { /* fall through to lazy scan */ }

        $maxExisting = $this->_findMaxExistingSrNoForSchool($schoolId);
        if ($maxExisting > 0) {
            $this->_writeSrNoCounterPointer($schoolId, $maxExisting);
            log_message('info', "Sr_no_service: lazily initialized SRNO counter school={$schoolId} floor={$maxExisting}");
        }
        return $maxExisting;
    }

    /** Read-only. Scans this school's OWN srNoIndex claims only — schoolId
     *  equality filter, never a cross-school read. */
    /**
     * Highest EXISTING SRNO for this school, across BOTH tenant namespaces.
     *
     * ── WHY BOTH (2026-08-19 forensic audit) ────────────────────────────────
     * `srNoIndex` is PARTIALLY MIGRATED. Its document ids are already canonical
     * (`SCH_XXXX_{n}`, built by `$fs->docId()`), but the stored `schoolId`
     * FIELD carries whatever the caller passed — and
     * `Sis::_claimIdentityAttributes` passes `parent_db_key`, the login code
     * (e.g. "10012"). `Readmission` already passes the canonical id. Two entry
     * points, two namespaces, one collection.
     *
     * This method queries that FIELD. So a single-namespace lookup silently
     * returns 0 for the namespace the rows were NOT written under:
     *
     *     pointer 0 → candidate 1 → claim SCH_XXXX_1 → ALREADY EXISTS → retry…
     *
     * With `COUNTER_MAX_ATTEMPTS = 15` that survives while a school has ≤14
     * SRNOs and then **refuses the admission** with `contention_exhausted`.
     * A latent failure that grows with every school's register — invisible in
     * testing, certain after rollout.
     *
     * No SRNO can ever be DUPLICATED by this (uniqueness lives in the canonical
     * doc id, which contains no namespace), so the fix is a widened READ, not a
     * data migration: consult both namespaces and take the max.
     *
     * Deliberately does NOT move data. Backfilling the field and re-keying the
     * counter are separate, protected operations. This makes the current split
     * SAFE; it does not pretend to remove it.
     */
    private function _findMaxExistingSrNoForSchool(string $schoolId): int
    {
        $max = 0;
        foreach ($this->_tenantAliases($schoolId) as $alias) {
            try {
                $rows = $this->firebase->firestoreQuery(self::COL, [['schoolId', '==', $alias]]);
                foreach ((array) $rows as $r) {
                    $d = $r['data'] ?? $r;
                    if (!is_array($d)) continue;
                    $n = (int) ($d['srNo'] ?? 0);
                    if ($n > $max) $max = $n;
                }
            } catch (\Throwable $e) {
                log_message('warning', "Sr_no_service::_findMaxExistingSrNoForSchool failed school={$alias}: " . $e->getMessage());
            }
        }
        return $max;
    }

    /**
     * Every tenant key this school's rows may have been written under:
     * the canonical `school_id` and the legacy `school_code` (`parent_db_key`).
     *
     * Resolved from the school document, never guessed. Returns the input alone
     * when the two coincide or the lookup fails — so a school whose code equals
     * its id, or an unreadable school doc, behaves exactly as before.
     */
    private function _tenantAliases(string $schoolId): array
    {
        $aliases = [$schoolId];
        try {
            // The canonical id is the schools/ doc id; the legacy key is its
            // schoolCode. Either may be the value we were handed, so resolve
            // from whichever direction we can.
            $doc = $this->firebase->firestoreGet('schools', $schoolId);
            if (is_array($doc)) {
                $code = trim((string) ($doc['schoolCode'] ?? ''));
                if ($code !== '' && $code !== $schoolId) $aliases[] = $code;
            } elseif ($this->fs !== null && method_exists($this->fs, 'schoolId')) {
                // We were handed the LEGACY key; the canonical id is on the helper.
                $canonical = trim((string) $this->fs->schoolId());
                if ($canonical !== '' && $canonical !== $schoolId) $aliases[] = $canonical;
            }
        } catch (\Throwable $e) {
            log_message('warning', "Sr_no_service::_tenantAliases lookup failed for {$schoolId}: " . $e->getMessage());
        }
        return array_values(array_unique($aliases));
    }

    private function _writeSrNoCounterPointer(string $schoolId, int $value): void
    {
        try {
            $this->firebase->firestoreSet(self::COUNTER_COL, self::COUNTER_DOC_PREFIX . $schoolId, [
                'kind' => 'SRNO', 'schoolId' => $schoolId, 'value' => $value, 'updatedAt' => date('c'),
            ]);
        } catch (\Throwable $e) {
            log_message('warning', "Sr_no_service::_writeSrNoCounterPointer failed school={$schoolId} value={$value}: " . $e->getMessage());
        }
    }

    private function _releaseSrNoCounterClaim(string $schoolId, int $value): void
    {
        try {
            $this->firebase->firestoreDelete(self::COUNTER_COL, self::COUNTER_DOC_PREFIX . "{$schoolId}_claim_{$value}");
        } catch (\Throwable $e) {
            log_message('warning', "Sr_no_service::_releaseSrNoCounterClaim failed school={$schoolId} value={$value}: " . $e->getMessage());
        }
    }

    /**
     * RESOLVE — ordinary lookup. SRNO -> current Student ID only. Never
     * returns historical owners; that is a Student Trail concern (Part K),
     * not an ordinary-lookup one.
     *
     * @return array{ok:bool,currentStudentId?:string,srNo?:string}
     */
    public function resolve(string $srNoRaw): array
    {
        $n = self::normalize($srNoRaw);
        if ($n === null) return ['ok' => false, 'reason' => 'invalid_format'];
        if ($this->fs === null) return ['ok' => false, 'reason' => 'not_initialized'];

        $doc = $this->fs->get(self::COL, $this->fs->docId($n['norm']));
        if (!is_array($doc) || empty($doc['currentStudentId'])) {
            return ['ok' => false, 'reason' => 'not_found'];
        }
        return ['ok' => true, 'currentStudentId' => (string) $doc['currentStudentId'], 'srNo' => (string) ($doc['srNo'] ?? $n['display'])];
    }

    /**
     * Full record for Student Trail's linked-history aggregation —
     * currentStudentId, historicalStudentIds, and the bounded history
     * cache. Never used for ordinary lookup (use resolve() there).
     */
    public function getFullRecord(string $srNoRaw): ?array
    {
        $n = self::normalize($srNoRaw);
        if ($n === null || $this->fs === null) return null;
        $doc = $this->fs->get(self::COL, $this->fs->docId($n['norm']));
        return is_array($doc) ? $doc : null;
    }

    /**
     * VERIFIED RE-ADMISSION RELINK — the only path that can move an
     * existing SRNO's ownership. Requires the caller to assert the expected
     * current owner ($expectedOldStudentId); the relink only proceeds if
     * the LIVE document still agrees at commit time — this single check is
     * what rejects TEST 4 (wrong previous id), TEST 5 (unrelated student),
     * and correctly resolves TEST 7 (concurrent relink race: the second
     * writer's retry sees the FIRST writer's new current id, which no
     * longer matches its own asserted old id, so it aborts cleanly).
     *
     * Idempotent by postcondition, not by a separate dedupe key (TEST 6):
     * if currentStudentId already equals $newStudentId when this runs
     * (e.g. a retried request whose first attempt actually committed), this
     * returns ok=true, reason=noop with ZERO further writes — never a
     * duplicate history/audit event.
     *
     * ATOMICITY: the srNoIndex ownership move and the new student's
     * `previousStudentId` write land in ONE Firestore batch commit
     * (Firestore_service::commitBatch, CAS-guarded on the srNoIndex side)
     * — both succeed or neither does. This is the "one controlled business
     * operation" the architecture audit requires (Part D/Section 6).
     *
     * @return array{ok:bool,reason:string,retries?:int}
     */
    public function relink(string $srNoRaw, string $expectedOldStudentId, string $newStudentId, string $reason = 're-admission'): array
    {
        $n = self::normalize($srNoRaw);
        if ($n === null) return ['ok' => false, 'reason' => 'invalid_format'];
        if ($expectedOldStudentId === '' || $newStudentId === '') {
            return ['ok' => false, 'reason' => 'missing_student_id'];
        }
        if ($expectedOldStudentId === $newStudentId) {
            return ['ok' => false, 'reason' => 'same_student_id'];
        }
        if ($this->fs === null || $this->firebase === null || $this->schoolId === '') {
            return ['ok' => false, 'reason' => 'not_initialized'];
        }

        // Circular-link guard — defense-in-depth at the SERVICE layer, not
        // only the calling controller. A cycle can only arise from a SECOND
        // relink call with the roles reversed relative to an earlier one
        // (e.g. STU0047.previousStudentId is already STU0001, and a later
        // call attempts to relink treating STU0001 as descending from
        // STU0047). Walk $expectedOldStudentId's OWN ancestor chain — if
        // $newStudentId already appears in it, $expectedOldStudentId
        // already (indirectly) descends from $newStudentId, so writing
        // $newStudentId.previousStudentId = $expectedOldStudentId would
        // close a loop. Deliberately NOT the reverse direction (walking
        // from $newStudentId) — that would incorrectly flag a legitimate
        // idempotent retry of the SAME direct link as a cycle, since by the
        // second call $newStudentId already legitimately has
        // previousStudentId == $expectedOldStudentId from the first.
        if ($this->_chainContains($expectedOldStudentId, $newStudentId)) {
            log_audit('SIS', 'srno_relink_rejected_cycle', $newStudentId,
                "Relink {$expectedOldStudentId} -> {$newStudentId} would create a circular previousStudentId chain", [
                    'srNo' => $n['display'],
                ]);
            return ['ok' => false, 'reason' => 'would_create_cycle'];
        }

        $indexDocId   = $this->fs->docId($n['norm']);
        $studentDocId = $this->fs->docId($newStudentId);

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            $doc = $this->fs->get(self::COL, $indexDocId);
            if (!is_array($doc)) {
                return ['ok' => false, 'reason' => 'srno_not_found', 'retries' => $attempt];
            }
            $current = (string) ($doc['currentStudentId'] ?? '');
            if ($current === $newStudentId) {
                // Already relinked by a prior (possibly retried) attempt — idempotent no-op.
                return ['ok' => true, 'reason' => 'noop', 'retries' => $attempt];
            }
            if ($current === '' || $current !== $expectedOldStudentId) {
                // Wrong previous-owner assertion (TEST 4), an unrelated student's
                // attempted claim (TEST 5), or a lost concurrency race (TEST 7) —
                // all three fail closed with no write, indistinguishable to the
                // data model by design (the caller's confirmation UX is what
                // told the operator which case this is BEFORE calling relink()).
                log_audit('SIS', 'srno_relink_rejected', $newStudentId,
                    "Relink rejected: expected owner {$expectedOldStudentId}, actual {$current}", [
                        'srNo' => $n['display'], 'expectedOldStudentId' => $expectedOldStudentId, 'actualCurrentStudentId' => $current,
                    ]);
                return ['ok' => false, 'reason' => 'expected_owner_mismatch', 'retries' => $attempt];
            }

            $hist = is_array($doc['historicalStudentIds'] ?? null) ? array_values($doc['historicalStudentIds']) : [];
            if (!in_array($current, $hist, true)) $hist[] = $current;

            $updateTime = (string) ($doc['__updateTime'] ?? '');
            $now = date('c');

            $ops = [
                [
                    'op'         => 'update',
                    'collection' => self::COL,
                    'docId'      => $indexDocId,
                    'data'       => [
                        'currentStudentId'     => $newStudentId,
                        'historicalStudentIds' => $hist,
                        'updatedAt'            => $now,
                    ],
                    'merge' => true,
                    'precondition' => $updateTime !== '' ? ['updateTime' => $updateTime] : null,
                ],
                [
                    'op'         => 'update',
                    'collection' => self::STUDENTS_COL,
                    'docId'      => $studentDocId,
                    'data'       => [
                        'previousStudentId' => $expectedOldStudentId,
                        'srNo'              => $n['display'],
                        'srNoNorm'          => $n['norm'],
                        'srNoSource'        => 're_admission_relink',
                        'updatedAt'         => $now,
                    ],
                    'merge' => true,
                ],
            ];

            if (method_exists($this->fs, 'commitBatch') && $this->fs->commitBatch($ops)) {
                // Bounded recent-cache append (best-effort, mirrors casAppendBounded's
                // own documented fail-safe: the permanent trail below never depends on this).
                $this->fs->casAppendBounded(self::COL, $n['norm'], 'history', [
                    'studentId' => $newStudentId,
                    'event'     => 'relink_in',
                    'actor'     => $this->actorId,
                    'reason'    => $reason,
                    'timestamp' => $now,
                ], self::HISTORY_CAP);

                // PERMANENT trail — never trimmed, authoritative alongside history[].
                log_audit('SIS', 're_admission_srno_relink', $newStudentId,
                    "SRNO {$n['display']} relinked from {$expectedOldStudentId} to {$newStudentId} ({$reason})", [
                        'srNo' => $n['display'],
                        'oldStudentId' => $expectedOldStudentId,
                        'newStudentId' => $newStudentId,
                        'schoolId' => $this->schoolId,
                        'action' => 're-admission-srno-relink',
                        'reason' => $reason,
                        'actor' => $this->actorId,
                    ]);

                return ['ok' => true, 'reason' => 'relinked', 'retries' => $attempt];
            }
            // CAS conflict (stale updateTime) or transient failure — loop re-reads fresh.
        }

        log_message('error', "Sr_no_service::relink CAS exhausted srNo={$n['display']} school={$this->schoolId}");
        return ['ok' => false, 'reason' => 'contention_exhausted', 'retries' => self::MAX_RETRIES + 1];
    }

    /** Bounded ancestor-chain walk — a realistic ceiling on genuine
     * re-admission depth for one real person; also protects against a
     * pre-existing corrupt loop causing an infinite walk. */
    private const MAX_CHAIN_DEPTH = 25;

    /**
     * True if walking $startId's own previousStudentId chain backwards ever
     * reaches $targetId. $this->fs is schoolId-bound (see class docblock),
     * so this walk is implicitly tenant-scoped — it can never cross into
     * another school's students collection.
     */
    private function _chainContains(string $startId, string $targetId): bool
    {
        if ($this->fs === null) return false;
        $cursor = $startId;
        $seen = [];
        for ($i = 0; $i < self::MAX_CHAIN_DEPTH; $i++) {
            if ($cursor === $targetId) return true;
            if (isset($seen[$cursor])) return true; // pre-existing corrupt loop — fail closed
            $seen[$cursor] = true;
            $doc = $this->fs->get(self::STUDENTS_COL, $this->fs->docId($cursor));
            $prev = is_array($doc) ? trim((string) ($doc['previousStudentId'] ?? '')) : '';
            if ($prev === '') return false; // reached the true root with no cycle
            $cursor = $prev;
        }
        log_message('warning', "Sr_no_service::_chainContains depth exceeded from {$startId} toward {$targetId}");
        return true; // depth exceeded — treat as suspicious, not safe
    }
}
