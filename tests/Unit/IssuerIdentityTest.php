<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Issuer identity — the cases here are the ones already in production.
 *
 * Every malformed value tested below was read out of live Firestore on
 * 2026-09-08. None is hypothetical.
 *
 * qa/certificates/_live-state.md L31, L32
 */
final class IssuerIdentityTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('BASEPATH')) {
            define('BASEPATH', __DIR__);
        }
        require_once __DIR__ . '/../../application/libraries/Issuer_identity.php';
    }

    private function claimed(array $over = []): array
    {
        return array_merge([
            'affiliationBoard'  => 'CBSE',
            'affiliationNo'     => '1234567',
            'registeredName'    => 'Vikrant Public School',
            'headOfInstitution' => 'Vikrant Sharma',
        ], $over);
    }

    /* ── the values already in the data ─────────────────────────────── */

    /** Harshit Public School's stored affiliation number. */
    public function test_the_junk_number_already_in_production_is_refused(): void
    {
        $r = Issuer_identity::validate([
            'affiliationBoard' => 'CBSE',
            'affiliationNo'    => '6564643131685.16463168',
        ]);
        $this->assertArrayHasKey('affiliationNo', $r['errors']);
        $this->assertArrayNotHasKey('affiliationNo', $r['fields'],
            'A value that failed validation must not be written back.');
    }

    /**
     * RVS Science & Sports Academy's stored value: eleven digits in the
     * affiliation field. The form invites this by labelling one input
     * "Affiliation / DISE No.", so the message names the mistake rather than
     * saying "invalid".
     */
    public function test_a_udise_code_in_the_affiliation_field_is_named_not_just_rejected(): void
    {
        $r = Issuer_identity::validate([
            'affiliationBoard' => 'CBSE',
            'affiliationNo'    => '09310113101',
        ]);
        $this->assertStringContainsString('UDISE', $r['errors']['affiliationNo']);
        $this->assertStringContainsString('belongs in the UDISE field', $r['errors']['affiliationNo']);
    }

    /** Vikrant Public School's stored value — 8 digits where CBSE uses 7. */
    public function test_a_near_miss_is_still_a_miss(): void
    {
        $r = Issuer_identity::validate(['affiliationBoard' => 'CBSE', 'affiliationNo' => '41298123']);
        $this->assertArrayHasKey('affiliationNo', $r['errors']);
        $this->assertStringContainsString('7 digits', $r['errors']['affiliationNo']);
    }

    /* ── the number cannot be judged without its board ──────────────── */

    public function test_a_number_without_a_board_cannot_be_checked_at_all(): void
    {
        $r = Issuer_identity::validate(['affiliationNo' => '1234567']);
        $this->assertSame('Choose a board first — the format depends on it.', $r['errors']['affiliationNo']);
    }

    public function test_the_same_number_can_be_valid_for_one_board_and_not_another(): void
    {
        $ok  = Issuer_identity::validate(['affiliationBoard' => 'NIOS', 'affiliationNo' => '1234567']);
        $bad = Issuer_identity::validate(['affiliationBoard' => 'CISCE', 'affiliationNo' => '1234567']);
        $this->assertSame([], $ok['errors']);
        $this->assertArrayHasKey('affiliationNo', $bad['errors']);
    }

    public function test_an_unaffiliated_school_has_no_affiliation_number(): void
    {
        $r = Issuer_identity::validate(['affiliationBoard' => 'UNAFFILIATED', 'affiliationNo' => '1234567']);
        $this->assertArrayHasKey('affiliationNo', $r['errors']);
    }

    public function test_udise_is_checked_on_its_own_terms(): void
    {
        $this->assertSame([], Issuer_identity::validate(['udiseCode' => '09310113101'])['errors']);
        $this->assertArrayHasKey('udiseCode', Issuer_identity::validate(['udiseCode' => '093101'])['errors']);
    }

    /* ── the ladder ─────────────────────────────────────────────────── */

    public function test_a_school_with_nothing_recorded_is_level_zero(): void
    {
        $this->assertSame(Issuer_identity::UNRECORDED, Issuer_identity::levelOf([]));
    }

    /**
     * A partial claim is level 0, not "nearly claimed" — a certificate carrying
     * half an identity is not half-valid.
     */
    public function test_a_partial_claim_is_level_zero_not_almost_claimed(): void
    {
        foreach (['affiliationNo', 'registeredName', 'headOfInstitution'] as $missing) {
            $id = $this->claimed(); unset($id[$missing]);
            $this->assertSame(Issuer_identity::UNRECORDED, Issuer_identity::levelOf($id),
                "missing $missing should not still count as claimed");
        }
    }

    public function test_a_malformed_number_never_reaches_claimed(): void
    {
        $this->assertSame(Issuer_identity::UNRECORDED,
            Issuer_identity::levelOf($this->claimed(['affiliationNo' => '6564643131685.16463168'])));
    }

    public function test_a_complete_claim_is_level_one(): void
    {
        $this->assertSame(Issuer_identity::CLAIMED, Issuer_identity::levelOf($this->claimed()));
    }

    public function test_an_instrument_on_file_is_level_two(): void
    {
        $id = $this->claimed(['verification' => ['evidencePath' => 'schools/X/identity/aff.pdf']]);
        $this->assertSame(Issuer_identity::EVIDENCED, Issuer_identity::levelOf($id));
    }

    public function test_a_named_check_on_a_named_date_is_level_three(): void
    {
        $id = $this->claimed(['verification' => [
            'evidencePath' => 'schools/X/identity/aff.pdf',
            'verifiedBy'   => 'STA0025',
            'verifiedOn'   => gmdate('Y-m-d'),
        ]]);
        $this->assertSame(Issuer_identity::VERIFIED, Issuer_identity::levelOf($id));
    }

    /** A check with no checker is not a check. */
    public function test_a_date_without_a_checker_is_not_verification(): void
    {
        $id = $this->claimed(['verification' => [
            'evidencePath' => 'p.pdf', 'verifiedOn' => gmdate('Y-m-d'),
        ]]);
        $this->assertSame(Issuer_identity::EVIDENCED, Issuer_identity::levelOf($id));
    }

    /**
     * The half that stops a badge outliving the fact it describes.
     */
    public function test_an_expired_verification_falls_back_rather_than_staying_green(): void
    {
        $id = $this->claimed([
            'reviewMonths' => 12,
            'verification' => [
                'evidencePath' => 'p.pdf',
                'verifiedBy'   => 'STA0025',
                'verifiedOn'   => gmdate('Y-m-d', strtotime('-3 years')),
            ],
        ]);
        $this->assertSame(Issuer_identity::EVIDENCED, Issuer_identity::levelOf($id),
            'A three-year-old check under a 12-month interval must not still read as verified.');
    }

    public function test_the_review_interval_is_the_schools_own(): void
    {
        $v = ['verifiedOn' => gmdate('Y-m-d', strtotime('-18 months')), 'verifiedBy' => 'X', 'evidencePath' => 'p'];
        $this->assertTrue(Issuer_identity::isStale($v, 12));
        $this->assertFalse(Issuer_identity::isStale($v, 36));
    }

    /* ── the gate ───────────────────────────────────────────────────── */

    public function test_issuance_needs_an_instrument_not_merely_a_claim(): void
    {
        $this->assertFalse(Issuer_identity::mayIssue([])['allowed']);
        $this->assertFalse(Issuer_identity::mayIssue($this->claimed())['allowed']);
        $this->assertTrue(Issuer_identity::mayIssue(
            $this->claimed(['verification' => ['evidencePath' => 'p.pdf']])
        )['allowed']);
    }

    public function test_a_refusal_says_what_is_missing(): void
    {
        $this->assertStringContainsString('no recorded issuer identity', Issuer_identity::mayIssue([])['reason']);
        $this->assertStringContainsString('no instrument is on file',
            Issuer_identity::mayIssue($this->claimed())['reason']);
    }

    /* ── the compliance board is never guessed ──────────────────────── */

    public function test_an_unrecorded_board_resolves_to_nothing_not_to_cbse(): void
    {
        $this->assertSame('', Issuer_identity::complianceBoard([]));
        $this->assertSame('', Issuer_identity::complianceBoard(['affiliationBoard' => '']));
        $this->assertSame('', Issuer_identity::complianceBoard(['affiliationBoard' => 'Something Else']));
    }

    /**
     * board_config belongs to the exam and grading module — it carries
     * grading_pattern and passing_marks — and must never stand in as an
     * affiliation claim.
     */
    public function test_the_exam_board_config_is_not_an_affiliation_claim(): void
    {
        $school = ['board_config' => ['type' => 'CBSE', 'grading_pattern' => 'marks']];
        $this->assertSame('', Issuer_identity::complianceBoard($school));
    }

    public function test_a_recorded_board_resolves(): void
    {
        $this->assertSame('CBSE', Issuer_identity::complianceBoard(['affiliationBoard' => 'cbse']));
        $this->assertSame('CBSE', Issuer_identity::complianceBoard(['board' => 'CBSE']));
    }

    /* ── honesty about the rules themselves ─────────────────────────── */

    /**
     * These formats are working shapes, not transcriptions. If one is ever
     * verified against the board's own documentation, flip its flag — and this
     * test will tell you the claim in the docblock needs updating too.
     */
    public function test_no_board_format_claims_to_be_verified(): void
    {
        foreach (Issuer_identity::boardCatalogue() as $b) {
            $this->assertFalse($b['verified'],
                "'{$b['key']}' now claims a verified format — update the class docblock, which "
                . 'says all of them are working shapes rather than transcribed regulation.');
        }
    }

    public function test_the_catalogue_carries_what_the_client_needs(): void
    {
        $c = Issuer_identity::boardCatalogue();
        $this->assertCount(5, $c);
        foreach ($c as $b) {
            foreach (['key','label','hint','needsNo','verified'] as $k) {
                $this->assertArrayHasKey($k, $b);
            }
        }
    }

    public function test_a_recognition_order_is_stored_apart_from_the_affiliation(): void
    {
        $r = Issuer_identity::validate([
            'affiliationBoard'  => 'STATE',
            'affiliationNo'     => 'DPI/2019/4471',
            'recognitionOrder'  => ['number' => 'RO-8821', 'date' => '2019-06-14', 'authority' => 'DPI Madhya Pradesh'],
        ]);
        $this->assertSame([], $r['errors']);
        $this->assertSame('RO-8821', $r['fields']['recognitionOrder']['number']);
        $this->assertSame('DPI/2019/4471', $r['fields']['affiliationNo']);
    }

    public function test_a_malformed_date_is_refused_rather_than_stored(): void
    {
        $r = Issuer_identity::validate(['recognitionOrder' => ['date' => '2019-02-31']]);
        $this->assertArrayHasKey('recognitionOrder.date', $r['errors']);
    }
}
