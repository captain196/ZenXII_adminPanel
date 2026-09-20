<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A certificate must be able to prove what it said when it was issued.
 *
 * `print_tc()` re-resolves from the live student and school documents on every
 * print, so a 2026 TC reprinted in 2029 renders 2029's data. Nothing recorded
 * the original. These tests pin the digest that fixes it.
 */
final class IssuedDocumentRecordTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('BASEPATH')) { define('BASEPATH', 1); }
        require_once __DIR__ . '/../../application/libraries/Issued_document_record.php';
    }

    private function student(array $over = []): array
    {
        return array_merge([
            'Name' => 'Aarav Sharma', 'Father Name' => 'R. Sharma',
            'Mother Name' => 'S. Sharma', 'DOB' => '2009-07-14',
            'Class' => 'X', 'Section' => 'B', 'Admission Date' => '2015-04-02',
            'Nickname' => 'not printed on the TC',
        ], $over);
    }
    private function school(array $over = []): array
    {
        return array_merge([
            'name' => 'Adarsh Public School', 'affiliationBoard' => 'CBSE',
            'affiliationNo' => '1234567', 'schoolCode' => '4739',
            'tcCounter' => 91, // school bookkeeping, not a TC fact
        ], $over);
    }
    private function tc(array $over = []): array
    {
        return array_merge([
            'tc_no' => 'ADA/2026/0091', 'issued_date' => '2026-09-20',
            'issued_by' => 'Admin', 'reason' => 'Relocation',
            'status' => 'active', 'student_name' => 'Aarav Sharma',
        ], $over);
    }

    public function test_it_captures_the_fields_the_certificate_prints(): void
    {
        $s = \Issued_document_record::snapshot($this->student(), $this->school(), $this->tc());
        foreach (['Name', 'Father Name', 'Mother Name', 'DOB', 'Class'] as $f) {
            $this->assertArrayHasKey($f, $s['student'], "'$f' prints on the TC and must be captured.");
        }
        $this->assertSame('1234567', $s['school']['affiliationNo'],
            'The affiliation number prints and asserts authority; it must be captured as issued.');
    }

    /** A field the view never renders is not part of what the certificate said. */
    public function test_it_ignores_data_the_certificate_does_not_print(): void
    {
        $s = \Issued_document_record::snapshot($this->student(), $this->school(), $this->tc());
        $this->assertArrayNotHasKey('Nickname', $s['student']);
        $this->assertArrayNotHasKey('tcCounter', $s['school']);
    }

    /**
     * Cancelling a TC does not change the document it was. If `status` were in
     * the digest, every lifecycle change would look like tampering.
     */
    public function test_the_lifecycle_does_not_move_the_digest(): void
    {
        $issued    = \Issued_document_record::snapshot($this->student(), $this->school(), $this->tc());
        $cancelled = \Issued_document_record::snapshot(
            $this->student(), $this->school(), $this->tc(['status' => 'cancelled'])
        );
        $this->assertSame(
            \Issued_document_record::digest($issued),
            \Issued_document_record::digest($cancelled),
            'A cancelled TC is the same document it was when issued.'
        );
    }

    /** PHP key order follows insertion; the digest must not. */
    public function test_the_digest_is_order_independent(): void
    {
        $a = \Issued_document_record::snapshot(
            ['Name' => 'A', 'DOB' => '2009-01-01'], $this->school(), $this->tc());
        $b = \Issued_document_record::snapshot(
            ['DOB' => '2009-01-01', 'Name' => 'A'], $this->school(), $this->tc());
        $this->assertSame(
            \Issued_document_record::digest($a), \Issued_document_record::digest($b),
            'Gathering the same facts in a different order produced a different digest, so every '
            . 'reprint check would report a false mismatch.'
        );
    }

    /** BSA s.63's Schedule asks for "hash value AND algorithm". */
    public function test_the_digest_carries_its_algorithm(): void
    {
        $d = \Issued_document_record::digest(
            \Issued_document_record::snapshot($this->student(), $this->school(), $this->tc()));
        $this->assertStringStartsWith('sha256:', $d,
            'A bare hex string answers half of "hash value and algorithm".');
        $this->assertSame(71, strlen($d));
    }

    /** The whole point: a changed date of birth must be detectable on reprint. */
    public function test_a_later_correction_is_detected(): void
    {
        $atIssue = \Issued_document_record::snapshot($this->student(), $this->school(), $this->tc());
        $digest  = \Issued_document_record::digest($atIssue);

        $this->assertTrue(\Issued_document_record::matches($atIssue, $digest));

        $reprint = \Issued_document_record::snapshot(
            $this->student(['DOB' => '2009-07-15']), $this->school(), $this->tc());
        $this->assertFalse(\Issued_document_record::matches($reprint, $digest),
            'A date of birth corrected after issue reprinted silently — and s.94(2) ranks this '
            . 'document above the municipal birth certificate for determining a child’s age.');
    }

    /** The digest must actually be computed and stored where a TC is issued. */
    public function test_issue_tc_records_the_digest(): void
    {
        $php = (string) file_get_contents(__DIR__ . '/../../application/controllers/Sis.php');
        $at  = strpos($php, 'public function issue_tc');
        $this->assertNotFalse($at);
        $body = substr($php, $at, 6000);

        $this->assertStringContainsString('Issued_document_record::snapshot(', $body,
            'issue_tc no longer captures what the certificate said, so a reprint cannot be '
            . 'checked against the original.');
        $this->assertStringContainsString("\$tcData['content_digest']", $body,
            'The digest is not stored on the issuance record.');
    }

    /**
     * tcIndex accumulates every TC a school has ever issued onto ONE document.
     * A full snapshot per certificate walks it into the 1MB Firestore cap —
     * BUG-029's failure mode. The digest goes there; the snapshot does not.
     */
    public function test_the_school_index_carries_the_digest_but_not_the_snapshot(): void
    {
        $php = (string) file_get_contents(__DIR__ . '/../../application/controllers/Sis.php');
        $at  = strpos($php, "\$tcIndex[\$tcKey] =");
        $this->assertNotFalse($at, 'tcIndex assignment not found.');
        $line = substr($php, $at, 120);

        $this->assertStringNotContainsString('content_snapshot', $line,
            'The full content snapshot is being written into tcIndex, which accumulates on the '
            . 'school document and will hit the 1MB cap.');
    }

    /** An affiliation number corrected later must be detectable too. */
    public function test_a_changed_affiliation_number_is_detected(): void
    {
        $atIssue = \Issued_document_record::snapshot($this->student(), $this->school(), $this->tc());
        $digest  = \Issued_document_record::digest($atIssue);
        $reprint = \Issued_document_record::snapshot(
            $this->student(), $this->school(['affiliationNo' => '7654321']), $this->tc());
        $this->assertFalse(\Issued_document_record::matches($reprint, $digest));
    }
}
