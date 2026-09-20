<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * What a certificate SAID when it was issued.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------
 * The issuance record written by `Sis::issue_tc()` holds the TC number, the
 * dates, who issued it, the reason and destination, and the student's name,
 * class and section. It does NOT hold the facts that print on the certificate:
 * not the date of birth, not the parents' names, not the admission number, not
 * the affiliation number.
 *
 * And `Sis::print_tc()` re-resolves everything from the live student and school
 * documents on every print. So a Transfer Certificate issued in 2026 and
 * reprinted in 2029 renders 2029's data — and nothing anywhere records what the
 * 2026 original said. If a date of birth is corrected in between, the reprint
 * silently disagrees with the certificate the family is holding, and neither
 * copy can be shown to be the issued one.
 *
 * That matters more than ordinary drift, for two established reasons:
 *
 *   - **JJ Act 2015 s.94(2) ranks a school's date-of-birth certificate in
 *     clause (i), ABOVE the municipal birth certificate in clause (ii)**, for
 *     determining a child's age — a determination that decides whether a person
 *     is tried as a child or as an adult.
 *   - **BSA 2023 s.63** requires, for an electronic record to be admissible, a
 *     Schedule certificate disclosing the record's **hash value and algorithm**.
 *     A hash of a template proof authenticates a design; it says nothing about
 *     the document a court is holding.
 *
 * So this class captures the content as issued, and a digest of it.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT DELIBERATELY DOES NOT DO
 * ---------------------------------------------------------------------------
 * It does not hash a rendered PDF. Two prints of the same TC can differ —
 * that is the re-resolution above — so no single PDF is "the" record, and a
 * PDF digest would be authoritative over nothing. The stable thing is the
 * CONTENT as issued, which is what a reprint can be checked against.
 *
 * It does not sign anything. A digest proves a document has not changed; it
 * does not prove who issued it. Signing is a separate regime (IT Act s.3A/s.5)
 * with its own requirements, and conflating the two would be the kind of
 * reassuring mistake this module exists to avoid.
 */
class Issued_document_record
{
    /** Bumped when the captured field set changes, so old digests stay readable. */
    const SNAPSHOT_VERSION = 1;

    /**
     * The fields a Transfer Certificate actually prints.
     *
     * Transcribed from `views/sis/tc_print.php`, not assembled from what a TC
     * usually shows. A field the view does not render is not part of what the
     * certificate said, and including it would make the digest change for
     * reasons the document never reflected.
     */
    const TC_STUDENT_FIELDS = [
        'Name', 'Father Name', 'Mother Name', 'Guardian Name', 'DOB', 'Gender',
        'Nationality', 'Religion', 'Caste', 'Category', 'Class', 'Section',
        'Roll No', 'Admission Class', 'Adm Class', 'Admission Date', 'Address',
        'PEN', 'Pen No', 'Subjects',
    ];

    /** The school facts that print — including the ones that assert authority. */
    const TC_SCHOOL_FIELDS = [
        'name', 'schoolName', 'address', 'street', 'phone',
        'affiliationBoard', 'affiliationNo', 'schoolCode',
    ];

    /**
     * Capture what this certificate says, as issued.
     *
     * @param array $student the student document
     * @param array $school  the school document
     * @param array $tc      the issuance record (`$tcData`)
     */
    public static function snapshot(array $student, array $school, array $tc): array
    {
        $out = [
            'v'       => self::SNAPSHOT_VERSION,
            'docType' => 'transfer_certificate',
            'student' => [],
            'school'  => [],
            'tc'      => [],
        ];

        foreach (self::TC_STUDENT_FIELDS as $f) {
            if (array_key_exists($f, $student) && $student[$f] !== null && $student[$f] !== '') {
                $out['student'][$f] = is_array($student[$f]) ? $student[$f] : (string) $student[$f];
            }
        }
        foreach (self::TC_SCHOOL_FIELDS as $f) {
            if (array_key_exists($f, $school) && $school[$f] !== null && $school[$f] !== '') {
                $out['school'][$f] = (string) $school[$f];
            }
        }
        /* The issuance facts, minus `status` — a TC that is later cancelled is
           the same document it was when issued, and its digest must not move
           because its lifecycle did. */
        foreach ($tc as $k => $v) {
            if ($k === 'status' || $v === null || $v === '') {
                continue;
            }
            $out['tc'][$k] = is_array($v) ? $v : (string) $v;
        }

        return $out;
    }

    /**
     * Order-independent serialization, so the same content hashes the same way.
     *
     * PHP array key order follows insertion, so two runs that gather the same
     * facts in a different order would otherwise produce different digests and
     * every reprint check would report a false mismatch.
     */
    public static function canonical($v): string
    {
        if (is_array($v)) {
            $isList = array_keys($v) === range(0, count($v) - 1);
            if (!$isList) {
                ksort($v);
            }
            $parts = [];
            foreach ($v as $k => $x) {
                $parts[] = ($isList ? '' : json_encode((string) $k) . ':') . self::canonical($x);
            }
            return ($isList ? '[' : '{') . implode(',', $parts) . ($isList ? ']' : '}');
        }
        if (is_bool($v))  { return $v ? 'true' : 'false'; }
        if ($v === null)  { return 'null'; }
        return json_encode((string) $v, JSON_UNESCAPED_UNICODE);
    }

    /**
     * The digest, carrying its algorithm.
     *
     * The algorithm travels with the digest because BSA s.63's Schedule asks
     * for "hash value and algorithm" — a bare hex string answers half of it.
     * The prefix also matches what the Document Engine already stores.
     */
    public static function digest(array $snapshot): string
    {
        return 'sha256:' . hash('sha256', self::canonical($snapshot));
    }

    /** Does a certificate rendered now still say what it said when issued? */
    public static function matches(array $snapshot, string $digest): bool
    {
        return hash_equals($digest, self::digest($snapshot));
    }
}
