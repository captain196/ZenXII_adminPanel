<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Every Transfer Certificate this system printed asserted a false affiliation.
 *
 * `$school_profile` is the raw `schools/{id}` Firestore document, which is
 * camelCase. The view read snake_case, so six of eight lookups missed silently —
 * the same key-shape drift CLAUDE.md documents for auth claims, where one side is
 * snake, one camel, and the failure is a blank rather than an error.
 *
 * The board then fell back to the literal `'C.B.S.E'`, and `", New Delhi"` was
 * hardcoded into the line. So the output read
 *
 *     Affiliated to C.B.S.E, New Delhi
 *
 * for every school, in every state, under every board — while the affiliation
 * number, which the school HAD recorded, never printed at all because the view
 * looked for `affiliation_no` and the document holds `affiliationNo`.
 *
 * That is a false statutory claim on a document a family carries to the next
 * school, and which the receiving school relies on.
 *
 * qa/certificates/_live-state.md L33
 */
final class TcPrintAffiliationTest extends TestCase
{
    private static string $view;

    public static function setUpBeforeClass(): void
    {
        $v = @file_get_contents(__DIR__ . '/../../application/views/sis/tc_print.php');
        self::assertNotFalse($v, 'tc_print.php unreadable');
        self::$view = (string) $v;
    }

    /** The city of the affiliating body is not something this view knows. */
    public function test_no_city_is_asserted_in_the_affiliation_line(): void
    {
        $markup = preg_replace('#/\*.*?\*/#s', '', self::$view);   // strip the explanatory comment
        $this->assertStringNotContainsString('New Delhi', $markup,
            '"New Delhi" is hardcoded again. It was printed on every certificate regardless of '
            . "board or state, including state-board schools that have no connection to Delhi.");
    }

    /**
     * A certificate that asserts an affiliation the school does not hold is worse
     * than one that asserts none.
     */
    public function test_the_board_does_not_fall_back_to_a_literal(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            "/\\\$schoolBoard\s*=\s*[^;]*\?\?\s*'C\.?B\.?S\.?E/i",
            self::$view,
            'The board defaults to a literal again, so a school that never recorded one prints a '
            . 'false affiliation.'
        );
    }

    /** Every key must exist on the document the controller actually passes. */
    public function test_every_key_read_exists_on_the_school_document(): void
    {
        /* The controller passes fs->get('schools', id) verbatim — camelCase. */
        $known = [
            'name', 'schoolName', 'address', 'street', 'city', 'state', 'pincode',
            'phone', 'email', 'website', 'logoUrl', 'affiliationNo', 'affiliationBoard',
            'schoolCode', 'principal', 'establishedYear',
        ];
        preg_match_all("/\\\$sp\['([A-Za-z_]+)'\]/", self::$view, $m);
        $unknown = array_values(array_diff(array_unique($m[1]), $known));

        $this->assertSame([], $unknown,
            'These keys are read off the school document but it does not carry them, so they '
            . 'resolve to null and fall back silently: ' . implode(', ', $unknown));
    }

    /** The affiliation number is mandatory for CBSE and was never printing. */
    public function test_the_affiliation_number_is_read_from_the_right_key(): void
    {
        $this->assertStringContainsString("\$sp['affiliationNo']", self::$view,
            'The number is read from a key the document does not have, so it never prints — '
            . 'while CBSE SOP 04.02.2020 I(b) makes it mandatory on the letterhead.');
    }

    /**
     * For a CBSE school the wording is prescribed, not ours to choose:
     * "AFFILIATED TO CENTRAL BOARD OF SECONDARY EDUCATION / AFFILIATION NO. ____"
     */
    public function test_a_cbse_school_gets_the_prescribed_wording(): void
    {
        $this->assertStringContainsString('AFFILIATED TO CENTRAL BOARD OF SECONDARY EDUCATION', self::$view);
        $this->assertStringContainsString('AFFILIATION NO.', self::$view);
    }
}
