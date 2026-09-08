<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The compliance basis must come from the school's record, never from a fixture.
 *
 * `board` and `state` decide which statutory authorities apply and which documents
 * a school is offered. Hydration used to fall back to `S.school`, which starts as
 * SCHOOL_DEFAULT — the offline fixture: *Delhi Public School, Ranchi · CBSE ·
 * Jharkhand*.
 *
 * So a school that had never recorded a board was told its Transfer Certificate
 * satisfied CBSE Examination Bye-Laws, Annexure-I and its nineteen required fields.
 * Observed live on SCH_B56BB9A401: no `affiliationBoard`, no `board`, server
 * correctly sent `""`, hub showed CBSE. Six of the nine schools in this project
 * have no recorded board.
 *
 * The old reasoning was that blanking a value "would silently empty the compliance
 * basis instead of showing that it is unknown". The intent was right and the effect
 * its opposite: leaving the demo default is not showing the board is unknown, it is
 * stating a confident wrong answer — which is what the compliance panel warns about
 * two screens away, that inventing a plausible requirement "would assert wrong law
 * confidently, which is worse than enforcing nothing".
 *
 * Verified after the fix: board reads "(not recorded)", CBSE is no longer applied,
 * required fields drop 19 → 0, and RTE (national) still applies as it should.
 *
 * qa/certificates/_live-state.md L31
 */
final class DocIssuerBasisTest extends TestCase
{
    private static string $js;

    public static function setUpBeforeClass(): void
    {
        $js = @file_get_contents(__DIR__ . '/../../assets/js/doctemplates/designer.js');
        self::assertNotFalse($js, 'designer.js unreadable');
        self::$js = (string) $js;
    }

    private function hydrateMerge(): string
    {
        $at = strpos(self::$js, 'const meta=await srv.types();');
        $this->assertNotFalse($at, 'the school metadata read is gone');
        return substr(self::$js, $at, 2600);
    }

    /** A successful lookup that returns no board means the school has none. */
    public function test_an_absent_board_is_not_filled_in_from_the_fixture(): void
    {
        $this->assertMatchesRegularExpression(
            '/board\s*:\s*sc\.board\s*\|\|\s*""/',
            $this->hydrateMerge(),
            'board falls back to S.school, which starts as SCHOOL_DEFAULT — so a school with '
            . 'no recorded board is told CBSE Annexure-I applies to its certificates.'
        );
    }

    /** Same for state, which gates which documents are offered at all. */
    public function test_an_absent_state_is_not_filled_in_from_the_fixture(): void
    {
        $this->assertMatchesRegularExpression(
            '/state\s*:\s*sc\.state\s*\|\|\s*""/',
            $this->hydrateMerge(),
            'state falls back to the fixture (Jharkhand), so a school with no recorded state '
            . 'is offered another state\'s statutory catalogue.'
        );
    }

    /**
     * The fixture must still exist — the offline harness drives from it — so this
     * pins the danger rather than the constant.
     */
    public function test_the_fixture_is_still_a_fixture_and_still_says_cbse(): void
    {
        $this->assertStringContainsString('const SCHOOL_DEFAULT', self::$js,
            'SCHOOL_DEFAULT is gone — if the offline fixture was removed, this test should be '
            . 'rewritten rather than deleted.');
        $this->assertMatchesRegularExpression('/SCHOOL_DEFAULT\s*=\s*\{[^}]*board\s*:\s*"CBSE"/', self::$js,
            'The fixture no longer asserts CBSE. That is fine, but the two tests above exist '
            . 'because it did — re-read them before assuming the risk is gone.');
    }

    /**
     * A FAILED lookup is different: there the absence is our ignorance, not the
     * school's record, so the old values stay and the failure is made visible.
     */
    public function test_a_failed_lookup_still_raises_a_visible_error(): void
    {
        $this->assertStringContainsString(
            "could not confirm your school's board and state",
            self::$js,
            'A failed metadata read must stay loud — silently keeping the previous board is '
            . 'only acceptable while the user is told the lookup failed.'
        );
    }

    /** An unrecorded state must read as unrecorded, not as an empty sentence. */
    public function test_an_unrecorded_state_is_named_rather_than_left_blank(): void
    {
        $this->assertStringContainsString(
            "this school's state is not recorded",
            self::$js,
            'With no state the unavailable-type card read "…this school is in " and stopped. '
            . 'Naming the gap is the honest half of not guessing at it.'
        );
    }
}
