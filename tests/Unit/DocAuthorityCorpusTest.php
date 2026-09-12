<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The compliance corpus, pinned against the primary texts it claims to transcribe.
 *
 * Six research streams read the statutes, the state rules and the board bye-laws in
 * September 2026. Four corrections landed in this corpus, and each one is a claim a
 * school would otherwise have been shown as law. This class exists so none of them
 * is reverted by someone who has not read why.
 *
 * blueprints/certificates/issuance/research/central-and-boards.md §C, §F.1
 */
final class DocAuthorityCorpusTest extends TestCase
{
    private static string $js;

    public static function setUpBeforeClass(): void
    {
        $js = @file_get_contents(__DIR__ . '/../../assets/js/doctemplates/designer.js');
        self::assertNotFalse($js, 'designer.js unreadable');
        self::$js = (string) $js;
    }

    /** The CBSE authority's own block. */
    private function cbseTc(): string
    {
        return $this->authority('cbse');
    }

    /**
     * One authority's own block, bounded by the next one.
     *
     * A fixed-size window is wrong twice over here: there are TWO id:"cbse"
     * entries — one in PROFILES, one in AUTHORITIES — so the anchor must carry
     * the tier; and the corpus is growing (seven authorities and counting), so a
     * fixed length silently spills into the next entry and asserts against the
     * wrong law.
     */
    private function authority(string $id): string
    {
        $at = strpos(self::$js, 'id:"' . $id . '", tier:');
        $this->assertNotFalse($at, "the '$id' AUTHORITY is gone (PROFILES may hold a same-named entry)");
        $next = strpos(self::$js, 'tier:', $at + 20);
        $end  = $next === false ? $at + 4000 : $next;
        return substr(self::$js, $at, $end - $at);
    }

    /**
     * CBSE's Annexure-I field 2 IS the Mother's Name.
     *
     * This test previously asserted the opposite, and its own failure message
     * carried the false claim: "Annexure-I field 2 is Father's/Guardian's Name
     * and the format has no mother's-name field." That was taken from a summary
     * of a superseded form. Read verbatim from CBSE's Examination Bye-Laws PDF
     * (2013 edn., PDF p.197 = printed p.91): "1. Name of Pupil  2. Mother's
     * Name  3. Fathers/Guardian's Name".
     *
     * Keeping the test but inverting it, because a test that locks in a wrong
     * reading of an authority is worse than no test — it defends the error.
     */
    public function test_the_cbse_tc_requires_the_mothers_name_field_two(): void
    {
        $this->assertStringContainsString('student.motherName', $this->cbseTc(),
            "Annexure-I field 2 is the Mother's Name (Bye-Laws 2013 edn., p.91). "
            . 'Dropping it renders a TC short of a field CBSE mandates.');
        $this->assertStringContainsString('student.fatherName', $this->cbseTc(),
            "Field 3 is the Father's/Guardian's Name — both are required, in that order.");
    }

    /**
     * The 04.02.2020 SOP, I(b) and I(e): the letterhead must carry "AFFILIATED TO
     * CENTRAL BOARD OF SECONDARY EDUCATION / AFFILIATION NO. ____" below the school's
     * name and address, and the same inside the seal on a prescribed format. It was
     * mandatory and missing from the list entirely.
     */
    public function test_the_cbse_tc_requires_the_affiliation_number(): void
    {
        $at = strpos($this->cbseTc(), 'requiredKeys:[');
        $keys = substr($this->cbseTc(), $at, 700);
        $this->assertStringContainsString('school.affiliationNo', $keys,
            'The affiliation number is mandatory on the letterhead and in the seal per the '
            . '04.02.2020 SOP, and is not in requiredKeys.');
    }

    /**
     * Superseded by five circulars, 26.11.2014 to 31.10.2025: "there is no need of
     * countersignature of any transfer certificate."
     *
     * The Annexure-I footnote survives unrewritten — which is exactly why schools
     * still send TCs to Regional Offices — so the corpus must say the requirement is
     * dead rather than simply dropping the line and losing the explanation.
     */
    public function test_countersignature_is_recorded_as_superseded_not_as_required(): void
    {
        $block = $this->cbseTc();
        $this->assertMatchesRegularExpression(
            '/COUNTERSIGNATURE IS NO LONGER REQUIRED/',
            $block,
            'The corpus must state that countersignature is superseded. Five CBSE circulars '
            . 'abolished it; the bye-law text was never rewritten.'
        );
        $this->assertStringContainsString('31.10.2025', $block,
            'Cite the most recent circular — it exists because schools kept complying with the dead rule.');
        $this->assertDoesNotMatchRegularExpression(
            '/additionally needs a countersignature/',
            $block,
            'The old constraint is back, presented as a live requirement.'
        );
    }

    /** The post-issuance obligation the corpus had no shape for. */
    public function test_the_website_upload_obligation_is_recorded(): void
    {
        $this->assertMatchesRegularExpression('/uploaded to the school\S{0,3}s own website/u', $this->cbseTc(),
            'CBSE has required upload of the issued TC to the school website in 2014, 2018, 2020 and '
            . 'again on 31.10.2025. We produce a PDF and publish nothing — the corpus should at least say so.');
    }

    /** Citing "the Bye-Laws" without the edition hides that circulars override them. */
    public function test_the_bye_law_edition_is_recorded(): void
    {
        $this->assertStringContainsString('2013 edn', $this->cbseTc(),
            'Cite the edition actually read. The corpus previously claimed the 1995 edition '
            . 'updated to December 2004; the format was in fact read from the 2013 edition, '
            . 'pp. 91-93. Circulars from 2014-2025 still override it without being folded in, '
            . 'which is why the edition has to be named at all.');
        $this->assertStringContainsString('25.01.2012', $this->cbseTc(),
            'Field 6 ("or OBC") was amended 25.01.2012 and approved 02.02.2012. An undated '
            . 'citation hides which recension of the form we transcribed.');
    }

    /**
     * s.5(3) says the head teacher "shall immediately issue the transfer certificate"
     * and attaches no condition. It does NOT contain the words "cannot be withheld for
     * any reason, including unpaid fees" — that is construction plus case law.
     *
     * The rule is sound. Presenting it as statutory wording was not.
     */
    public function test_the_rte_no_dues_rule_is_not_presented_as_a_quotation(): void
    {
        $block = $this->authority('rte');

        $this->assertStringNotContainsString(
            'It cannot be withheld for any reason, including unpaid fees.',
            $block,
            'This sentence reads as though the Act says it. The Act says "shall immediately issue" '
            . 'and nothing further.'
        );
        $this->assertStringContainsString('shall immediately issue the transfer certificate', $block,
            'Quote what the section actually says.');
        $this->assertStringContainsString('unenforceable', $block,
            'No enabling rule was repealed — Kerala KER Ch.VI r.17(2) stands unamended. The honest '
            . 'statement is that the rule exists and is unenforceable, not that it was struck down.');
    }

    /** s.5 sits in the elementary chapter; the limit belongs on the record. */
    public function test_the_elementary_scope_limit_is_stated(): void
    {
        $block = $this->authority('rte');
        $this->assertMatchesRegularExpression('/class VIII/', $block,
            's.5 reaches a child only through class VIII, and no equivalent central protection for '
            . 'IX-XII was found. A product that implies otherwise overstates the protection.');
    }
}
