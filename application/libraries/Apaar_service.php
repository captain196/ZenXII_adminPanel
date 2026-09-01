<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Apaar_service — THE single sanctioned write/read path for `apaarIndex`.
 *
 * Per SR_NUMBER_AND_APAAR_ID_DESIGN.md §4/§6 and the Phase 9 implementation
 * directive (2026-08-13): APAAR is a SEPARATE identity domain from SRNO —
 * this class is a deliberate structural sibling of Sr_no_service.php, not a
 * merge into it. It never writes SRNO fields, and Sr_no_service never writes
 * `apaarId`/`apaarIndex`.
 *
 * Canonical shape:
 *   apaarIndex/{schoolId}_{apaarId} => {
 *     apaarId:           "123456789012",   // normalized 12-digit value, immutable
 *     currentStudentId:  "STU0047",         // the ONLY field ordinary lookups read
 *     historicalStudentIds: ["STU0001"],    // derived cache, same pattern as srNoIndex
 *     history: [ { studentId, event, actor, reason, timestamp } ],  // bounded, cap 20
 *     claimedAt, claimedBy, schoolId, updatedAt
 *   }
 *
 * students/{studentId}.apaarId — the display/lookup value, dual-written on
 * claim and on relink, mirroring the srNo/srNoNorm pattern minus the
 * leading-zero norm split (APAAR is a fixed 12-digit value — no ambiguity
 * between "9" and "09" the way a variable-length SRNO has).
 *
 * TWO STRUCTURALLY DIFFERENT PATHS — mirrors Sr_no_service exactly:
 *   claim()  — first-time allocation. create-if-not-exists ONLY.
 *   relink() — verified re-admission ONLY, per the LOCKED policy that APAAR
 *              follows the PERSON: the same APAAR moves from the old
 *              Student ID to the new one, atomically, requiring the caller
 *              to assert the expected current owner (mirrors relink()'s own
 *              CAS-guarded, idempotent-by-postcondition contract).
 *
 * WHAT THIS CLASS DELIBERATELY DOES NOT DO:
 *  • It does not decide WHO may call relink() — that is the controller's
 *    RBAC gate (Readmission::confirm(), Principal-level, unchanged).
 *  • It never becomes a foreign key source for Fees/Attendance/Results/
 *    Transport/Hostel/etc. — those remain keyed exclusively to Student ID.
 *  • It never exposes a client-readable index or a query-based search —
 *    every read is an exact deterministic-doc-id fetch, server-side only.
 */
class Apaar_service
{
    /** @var object|null */ private $firebase;
    /** @var object|null */ private $fs;
    private string $schoolId = '';
    private string $actorId  = '';

    public const COL          = 'apaarIndex';
    public const STUDENTS_COL = 'students';
    public const HISTORY_CAP  = 20;
    public const MAX_RETRIES  = 5;

    /** Per SR_NUMBER_AND_APAAR_ID_DESIGN.md §4 — exactly 12 digits after
     *  stripping spaces/hyphens. No checksum, no check-digit rule. */
    private const FORMAT_RE = '/^[0-9]{12}$/';

    public function init($firebase, $fs, string $schoolId, string $actorId): self
    {
        $this->firebase = $firebase;
        $this->fs       = $fs;
        $this->schoolId = $schoolId;
        $this->actorId  = $actorId !== '' ? $actorId : 'SYSTEM';
        return $this;
    }

    /**
     * Normalize a raw APAAR string: strip spaces/hyphens, then require
     * exactly 12 digits. Returns null (not a "best effort" transform) if the
     * result isn't exactly 12 digits — invalid input must never silently
     * become valid data.
     */
    public static function normalize(string $raw): ?string
    {
        $stripped = preg_replace('/[\s\-]+/', '', trim($raw)) ?? '';
        if (!preg_match(self::FORMAT_RE, $stripped)) return null;
        return $stripped;
    }

    /**
     * ORDINARY CLAIM — first-time allocation only. Atomic create-if-not-
     * exists, same primitive Sr_no_service::claim() uses.
     *
     * @return array{ok:bool,reason?:string,currentStudentId?:string}
     */
    public function claim(string $apaarRaw, string $studentId): array
    {
        $norm = self::normalize($apaarRaw);
        if ($norm === null) {
            return ['ok' => false, 'reason' => 'invalid_format'];
        }
        if ($studentId === '') {
            return ['ok' => false, 'reason' => 'missing_student_id'];
        }
        if ($this->fs === null || $this->schoolId === '') {
            return ['ok' => false, 'reason' => 'not_initialized'];
        }

        $docId = $this->fs->docId($norm);
        $now   = date('c');
        $ok = (bool) $this->firebase->firestoreCreate(self::COL, $docId, [
            'apaarId'              => $norm,
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
            log_audit('SIS', 'apaar_claim_rejected_duplicate', $studentId,
                'APAAR ID already claimed in this school', [
                    'existingCurrentStudentId' => is_array($existing) ? ($existing['currentStudentId'] ?? null) : null,
                ]);
            return [
                'ok' => false,
                'reason' => 'duplicate',
                'currentStudentId' => is_array($existing) ? ($existing['currentStudentId'] ?? null) : null,
            ];
        }

        log_audit('SIS', 'apaar_claimed', $studentId, 'APAAR ID claimed', []);
        return ['ok' => true];
    }

    /**
     * RESOLVE — ordinary lookup. APAAR -> current Student ID only. Exact
     * deterministic-doc-id fetch — never a query, never exposed as a
     * client-readable index (Firestore rule denies all direct client
     * access; only this server-side service ever reads apaarIndex).
     *
     * @return array{ok:bool,currentStudentId?:string}
     */
    public function resolve(string $apaarRaw): array
    {
        $norm = self::normalize($apaarRaw);
        if ($norm === null) return ['ok' => false, 'reason' => 'invalid_format'];
        if ($this->fs === null) return ['ok' => false, 'reason' => 'not_initialized'];

        $doc = $this->fs->get(self::COL, $this->fs->docId($norm));
        if (!is_array($doc) || empty($doc['currentStudentId'])) {
            return ['ok' => false, 'reason' => 'not_found'];
        }
        return ['ok' => true, 'currentStudentId' => (string) $doc['currentStudentId']];
    }

    /**
     * VERIFIED RE-ADMISSION RELINK — the only path that can move an
     * existing APAAR's ownership. Mirrors Sr_no_service::relink() exactly:
     * CAS-guarded, idempotent-by-postcondition, cycle-guarded, atomic
     * 2-document batch (apaarIndex + the new student's own apaarId field).
     *
     * Per the LOCKED Part 8 policy: APAAR follows the person. The caller
     * (Readmission::confirm()) invokes this ONLY when the old student
     * record has a non-empty apaarId — APAAR remains optional, so a
     * re-admission with no prior APAAR simply never calls this at all.
     *
     * @return array{ok:bool,reason:string,retries?:int}
     */
    public function relink(string $apaarRaw, string $expectedOldStudentId, string $newStudentId, string $reason = 're-admission'): array
    {
        $norm = self::normalize($apaarRaw);
        if ($norm === null) return ['ok' => false, 'reason' => 'invalid_format'];
        if ($expectedOldStudentId === '' || $newStudentId === '') {
            return ['ok' => false, 'reason' => 'missing_student_id'];
        }
        if ($expectedOldStudentId === $newStudentId) {
            return ['ok' => false, 'reason' => 'same_student_id'];
        }
        if ($this->fs === null || $this->firebase === null || $this->schoolId === '') {
            return ['ok' => false, 'reason' => 'not_initialized'];
        }

        // Circular-link guard — same defense-in-depth Sr_no_service::relink()
        // applies, kept independent here (this class must be independently
        // correct, not merely "safe because SRNO's relink already ran
        // first" — see class docblock / Part 5's testability requirement).
        if ($this->_chainContains($expectedOldStudentId, $newStudentId)) {
            log_audit('SIS', 'apaar_relink_rejected_cycle', $newStudentId,
                "APAAR relink {$expectedOldStudentId} -> {$newStudentId} would create a circular previousStudentId chain", []);
            return ['ok' => false, 'reason' => 'would_create_cycle'];
        }

        $indexDocId   = $this->fs->docId($norm);
        $studentDocId = $this->fs->docId($newStudentId);

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            $doc = $this->fs->get(self::COL, $indexDocId);
            if (!is_array($doc)) {
                return ['ok' => false, 'reason' => 'apaar_not_found', 'retries' => $attempt];
            }
            $current = (string) ($doc['currentStudentId'] ?? '');
            if ($current === $newStudentId) {
                // Already relinked by a prior (possibly retried) attempt — idempotent no-op.
                return ['ok' => true, 'reason' => 'noop', 'retries' => $attempt];
            }
            if ($current === '' || $current !== $expectedOldStudentId) {
                log_audit('SIS', 'apaar_relink_rejected', $newStudentId,
                    "APAAR relink rejected: expected owner {$expectedOldStudentId}, actual {$current}", [
                        'expectedOldStudentId' => $expectedOldStudentId, 'actualCurrentStudentId' => $current,
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
                        'apaarId'    => $norm,
                        'updatedAt' => $now,
                    ],
                    'merge' => true,
                ],
            ];

            if (method_exists($this->fs, 'commitBatch') && $this->fs->commitBatch($ops)) {
                $this->fs->casAppendBounded(self::COL, $norm, 'history', [
                    'studentId' => $newStudentId,
                    'event'     => 'relink_in',
                    'actor'     => $this->actorId,
                    'reason'    => $reason,
                    'timestamp' => $now,
                ], self::HISTORY_CAP);

                log_audit('SIS', 're_admission_apaar_relink', $newStudentId,
                    "APAAR relinked from {$expectedOldStudentId} to {$newStudentId} ({$reason})", [
                        'oldStudentId' => $expectedOldStudentId,
                        'newStudentId' => $newStudentId,
                        'schoolId' => $this->schoolId,
                        'action' => 're-admission-apaar-relink',
                        'reason' => $reason,
                        'actor' => $this->actorId,
                    ]);

                return ['ok' => true, 'reason' => 'relinked', 'retries' => $attempt];
            }
            // CAS conflict (stale updateTime) or transient failure — loop re-reads fresh.
        }

        log_message('error', "Apaar_service::relink CAS exhausted school={$this->schoolId}");
        return ['ok' => false, 'reason' => 'contention_exhausted', 'retries' => self::MAX_RETRIES + 1];
    }

    /** Same bounded ceiling Sr_no_service uses — see that class for rationale. */
    private const MAX_CHAIN_DEPTH = 25;

    /**
     * True if walking $startId's own previousStudentId chain backwards ever
     * reaches $targetId. $this->fs is schoolId-bound, so this walk is
     * implicitly tenant-scoped.
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
        log_message('warning', "Apaar_service::_chainContains depth exceeded from {$startId} toward {$targetId}");
        return true; // depth exceeded — treat as suspicious, not safe
    }
}
