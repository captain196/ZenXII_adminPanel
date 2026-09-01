<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Admission_no_service — THE single sanctioned write/read path for
 * `admissionNoIndex`.
 *
 * Phase 10.2 (2026-08-13). A deliberate structural SIBLING of
 * Sr_no_service.php and Apaar_service.php — one service per identity
 * domain, never merged, exactly as those two already are relative to each
 * other. Admission Number is its own identity domain per the locked model:
 *
 *   Student ID       — GLOBAL, permanent internal identity (Id_generator)
 *   SRNO             — SCHOOL-SCOPED register number      (Sr_no_service)
 *   Roll Number      — CLASS+SECTION scoped academic number (plain field)
 *   Admission Number — SYSTEM-GENERATED ADMISSION-EVENT id (this class)
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  WHAT THIS CLASS EXISTS TO ENFORCE
 * ─────────────────────────────────────────────────────────────────────────
 *  • Admission Number is ALWAYS server-generated. There is no method here
 *    that accepts an operator-supplied value — by construction, not by a
 *    validation check. A caller cannot pass one in even if it wanted to.
 *  • It is INDEPENDENT of class, section, SRNO and Roll Number. None of
 *    those appear in this file's signature or its generated value. This is
 *    the explicit correction of the Phase 10.1 design, which wrongly
 *    embedded class + SRNO.
 *  • It identifies an ADMISSION EVENT, not a person: it is frozen for the
 *    life of one admission record (promotion never touches it) and a
 *    re-admission deliberately receives a BRAND-NEW one.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  FORMAT — "YYYY/TTTTTT"   e.g. 2026/7K4P92
 * ─────────────────────────────────────────────────────────────────────────
 *  YYYY   — the admission year, supplied by the caller from authoritative
 *           server state (the session year for ordinary admission). Human-
 *           meaningful, sortable, and the ONE component an operator would
 *           reasonably expect to recognise on a printed certificate.
 *  TTTTTT — 6 characters of CSPRNG randomness over a 30-symbol
 *           unambiguous alphabet (no 0/O, no 1/I/L, no U — Crockford-style,
 *           so a number read aloud or copied off a printed certificate
 *           cannot be mis-transcribed). 30^6 = 729,000,000 possibilities
 *           per school-year.
 *
 *  Why random rather than sequential: a sequential admission number leaks
 *  the school's exact intake count and ordering to anyone holding two
 *  certificates, and would need its own counter competing with SRNO's.
 *  SRNO already IS the school's sequential register number — duplicating
 *  that role here would make the two identifiers redundant, which is
 *  exactly what this phase is correcting.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  COLLISION SAFETY — atomic claim, NOT read-then-write
 * ─────────────────────────────────────────────────────────────────────────
 *  `firestoreCreate` (create-if-not-exists) is the same atomic collision
 *  primitive Id_generator, Sr_no_service and Apaar_service already rely on.
 *  A candidate is generated and IMMEDIATELY claimed; a 409-equivalent
 *  (create returns false) is the collision signal, which simply produces
 *  another candidate. There is NO "read to check if it exists, then write"
 *  anywhere — that pattern is both slower AND racy, and is explicitly not
 *  used here.
 *
 *  COST: expected exactly ONE Firestore write, ZERO reads. With 30^6
 *  candidates, a school admitting even 10,000 students in one year has an
 *  expected retry rate far below 0.01% per allocation — a retry costs one
 *  additional write, never a read.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  SCOPE — SCHOOL-SCOPED, matching SRNO and APAAR exactly
 * ─────────────────────────────────────────────────────────────────────────
 *  Doc id is `{schoolId}_{year}_{token}` (via Firestore_service::docId()),
 *  so School A and School B may both legitimately hold 2026/7K4P92 without
 *  colliding — the identical doc-id-prefixing isolation model srNoIndex and
 *  apaarIndex already use. No global uniqueness is claimed or needed: an
 *  Admission Number is only ever meaningful within the issuing school.
 *
 *  Note the doc id uses '_' where the display value uses '/', because a
 *  Firestore document id may not contain a forward slash. The stored
 *  `admissionNo` FIELD carries the canonical display form.
 */
class Admission_no_service
{
    /** @var object|null */ private $firebase;
    /** @var object|null */ private $fs;
    private string $schoolId = '';
    private string $actorId  = '';

    public const COL          = 'admissionNoIndex';
    public const STUDENTS_COL = 'students';
    public const MAX_ATTEMPTS = 8;

    /** Token length and alphabet — see class docblock for the rationale. */
    public const TOKEN_LEN = 6;
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ'; // 30 symbols, no 0/O/1/I/L/U

    /** Canonical display format: YYYY/TTTTTT (4 digits, slash, 6 symbols). */
    private const FORMAT_RE = '/^\d{4}\/[23456789ABCDEFGHJKMNPQRSTVWXYZ]{6}$/';

    public function init($firebase, $fs, string $schoolId, string $actorId): self
    {
        $this->firebase = $firebase;
        $this->fs       = $fs;
        $this->schoolId = $schoolId;
        $this->actorId  = $actorId !== '' ? $actorId : 'SYSTEM';
        return $this;
    }

    /**
     * Validate a canonical Admission Number's SHAPE. Pure, no I/O.
     * Used by tests and by any future display/parse code — never used to
     * accept an operator-supplied value (no such path exists).
     */
    public static function isValidFormat(string $admissionNo): bool
    {
        return (bool) preg_match(self::FORMAT_RE, trim($admissionNo));
    }

    /**
     * Generate one candidate token. CSPRNG (random_int) — not mt_rand,
     * because a predictable admission number on a printed certificate is a
     * (mild) forgery aid, and random_int costs nothing here.
     */
    private function _token(): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::TOKEN_LEN; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }
        return $out;
    }

    /**
     * ALLOCATE — the ONLY way an Admission Number is ever created.
     *
     * There is deliberately no `claim($operatorSuppliedValue, ...)` method
     * on this class, unlike Sr_no_service — because SRNO has a legitimate
     * historical-import case where a school supplies its real existing
     * register number, whereas an Admission Number is a ZenX-generated
     * event id that has no pre-existing real-world counterpart to preserve.
     *
     * @param string $schoolId       server-derived tenant (never client input)
     * @param string $studentId      the student this admission event belongs to
     * @param string $admissionYear  4-digit year, caller-supplied from
     *                               authoritative server state (session year)
     * @return array{ok:bool,reason?:string,admissionNo?:string,attempts?:int}
     */
    public function allocate(string $schoolId, string $studentId, string $admissionYear): array
    {
        if ($schoolId === '')  return ['ok' => false, 'reason' => 'missing_school_id'];
        if ($studentId === '') return ['ok' => false, 'reason' => 'missing_student_id'];
        if (!preg_match('/^\d{4}$/', $admissionYear)) {
            return ['ok' => false, 'reason' => 'invalid_admission_year'];
        }
        if ($this->fs === null || $this->firebase === null) {
            return ['ok' => false, 'reason' => 'not_initialized'];
        }

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $token       = $this->_token();
            $displayVal  = "{$admissionYear}/{$token}";
            // Firestore doc ids may not contain '/', so the id uses '_'.
            $docId       = $this->fs->docId("{$admissionYear}_{$token}");
            $now         = date('c');

            $created = (bool) $this->firebase->firestoreCreate(self::COL, $docId, [
                'admissionNo'   => $displayVal,
                'admissionYear' => $admissionYear,
                'studentId'     => $studentId,
                'schoolId'      => $schoolId,
                'claimedAt'     => $now,
                'claimedBy'     => $this->actorId,
            ]);

            if ($created) {
                log_audit('SIS', 'admission_no_allocated', $studentId,
                    "Admission Number {$displayVal} allocated", ['admissionNo' => $displayVal]);
                return ['ok' => true, 'admissionNo' => $displayVal, 'attempts' => $attempt];
            }
            // Collision (create-if-not-exists returned false) — the whole
            // point of the atomic primitive. Loop and try another token.
            log_message('info', "Admission_no_service: candidate {$displayVal} already claimed in {$schoolId}, retrying");
        }

        log_message('error', "Admission_no_service::allocate exhausted " . self::MAX_ATTEMPTS . " attempts school={$schoolId} year={$admissionYear}");
        return ['ok' => false, 'reason' => 'contention_exhausted', 'attempts' => self::MAX_ATTEMPTS];
    }

    /**
     * RESOLVE — Admission Number -> owning Student ID. Exact
     * deterministic-doc-id fetch, never a query; server-side only (the
     * index is deny-all to clients, same as srNoIndex/apaarIndex).
     *
     * @return array{ok:bool,reason?:string,studentId?:string}
     */
    public function resolve(string $admissionNo): array
    {
        $val = trim($admissionNo);
        if (!self::isValidFormat($val)) return ['ok' => false, 'reason' => 'invalid_format'];
        if ($this->fs === null) return ['ok' => false, 'reason' => 'not_initialized'];

        $docId = $this->fs->docId(str_replace('/', '_', $val));
        $doc   = $this->fs->get(self::COL, $docId);
        if (!is_array($doc) || empty($doc['studentId'])) {
            return ['ok' => false, 'reason' => 'not_found'];
        }
        return ['ok' => true, 'studentId' => (string) $doc['studentId']];
    }
}
