<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__ . '/../../system/');
if (!defined('APPPATH'))  define('APPPATH',  __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../../application/libraries/Timetable_projection_service.php';
require_once __DIR__ . '/../../application/libraries/Rollover_projection_guard.php';

/**
 * THE TWO REMAINING WRITERS MUST AGREE ON WHAT "DERIVED" MEANS.
 *
 * After gate B, exactly two things can still write a document in `timetables`
 * that the projection also claims:
 *
 *   • the session rollover (Rollover_projection_guard), which refuses to carry
 *     last year's timetable over a derived document;
 *   • Timetables_reconcile, which refuses to repair one in place.
 *
 * Each answers the same question — "did the projection author this?" — and
 * they used to answer it with two separately written expressions. One trimmed
 * the field and one did not, so a document holding "   " was protected from
 * the rollover and repaired by the reconciler. A document one guard protects
 * and the other does not is worse than no guard at all, because the protection
 * is what everyone reasons about.
 *
 * There is now one predicate, and this test is what holds the second
 * implementation to it. It is deliberately an EQUIVALENCE test across a table
 * of real and adversarial documents, not a check that one calls the other:
 * either may be reimplemented, and this still fails the moment they disagree.
 */
final class DerivedDocumentAgreementTest extends TestCase
{
    /** Documents that are, and are not, canonical-derived. */
    public function documents(): array
    {
        $base = [
            'schoolId' => 'SCH_A', 'session' => '2026-27',
            'sectionKey' => 'Class 9th/Section A', 'day' => 'Monday',
            'periods' => [],
        ];
        return [
            'no provenance field at all'      => [$base, false],
            'a real version id'               => [$base + ['projectedFromVersion' => 'V1'], true],
            'an empty string'                 => [$base + ['projectedFromVersion' => ''], false],
            'whitespace only'                 => [$base + ['projectedFromVersion' => '   '], false],
            'a tab'                           => [$base + ['projectedFromVersion' => "\t"], false],
            'a newline'                       => [$base + ['projectedFromVersion' => "\n"], false],
            'explicit null'                   => [$base + ['projectedFromVersion' => null], false],
            'boolean false'                   => [$base + ['projectedFromVersion' => false], false],
            // "0" is a strange version id, but it is a PRESENT one, and the
            // two possible errors here are not symmetric: reading a present
            // stamp as absent lets the rollover overwrite canonical state,
            // while reading it as present only makes a tool decline to touch
            // one document. Both implementations therefore treat any non-blank
            // value as a stamp, and both fail closed.
            'integer zero'                    => [$base + ['projectedFromVersion' => 0], true],
            'the string zero'                 => [$base + ['projectedFromVersion' => '0'], true],
            'a padded version id'             => [$base + ['projectedFromVersion' => ' V1 '], true],
            'plan set but version blank'      => [$base + ['projectedFromPlan' => 'P1',
                                                           'projectedFromVersion' => ''], false],
            'carried AND projected'           => [$base + ['projectedFromVersion' => 'V2',
                                                           'phase3RolloverRev' => 9], true],
        ];
    }

    /**
     * @dataProvider documents
     */
    public function test_both_writers_reach_the_same_verdict($doc, bool $isDerived): void
    {
        $service = Timetable_projection_service::isDerived($doc);

        // The rollover expresses the same judgement as a decision. Its
        // "already rolled" and "write" outcomes both mean "not derived"; only
        // SKIP_PROJECTED means derived. A revision far above anything the
        // document carries keeps the rollover-marker branch out of the way for
        // the documents that do not set it.
        $rollover = Rollover_projection_guard::decide($doc, PHP_INT_MAX, false)
                  === Rollover_projection_guard::SKIP_PROJECTED;

        $this->assertSame($isDerived, $service,
            'the shared predicate disagrees with the table');
        $this->assertSame($service, $rollover,
            'the session rollover and the projection service must classify this '
            . 'document identically, or one of them will overwrite what the '
            . 'other is protecting');
    }

    /**
     * Provenance outranks the rollover's own marker in BOTH.
     *
     * A document carried by a previous rollover and later projected over holds
     * both fields. If the two disagreed about which wins, the rollover would
     * report "already rolled, mine, done" about a document that is canonical
     * state — the same refusal for the wrong reason, which is how a real
     * regression later gets waved through.
     */
    public function test_provenance_outranks_the_rollover_marker_in_both(): void
    {
        $doc = [
            'schoolId' => 'SCH_A', 'session' => '2026-27',
            'projectedFromVersion' => 'V3', 'phase3RolloverRev' => 1,
            'carriedFromSession' => '2025-26',
        ];

        $this->assertTrue(Timetable_projection_service::isDerived($doc));
        $this->assertSame(
            Rollover_projection_guard::SKIP_PROJECTED,
            Rollover_projection_guard::decide($doc, 1, false));
    }

    /** Neither is fooled by a non-array. */
    public function test_a_non_document_is_not_derived(): void
    {
        foreach ([null, 'V1', 42, false] as $notADoc) {
            $this->assertFalse(Timetable_projection_service::isDerived($notADoc));
            $this->assertNotSame(
                Rollover_projection_guard::SKIP_PROJECTED,
                Rollover_projection_guard::decide($notADoc, 1, false));
        }
    }

    /**
     * The reconciler consults the shared predicate rather than keeping a
     * third copy of the rule.
     *
     * This is the one structural assertion in the file, and it is here because
     * the equivalence above can only hold two implementations to each other —
     * it cannot see a third one being written inside a CLI controller that no
     * unit test instantiates.
     */
    public function test_the_reconciler_uses_the_shared_predicate(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Timetables_reconcile.php');
        $code = (string) preg_replace('~^\s*//.*$~m', '',
                (string) preg_replace('~/\*.*?\*/~s', '', $src));

        $this->assertStringContainsString(
            'Timetable_projection_service::isDerived($d)', $code,
            'the reconciler asks the shared predicate');
        $this->assertDoesNotMatchRegularExpression(
            "~if\s*\(\s*\(string\)\s*\(\\\$d\['projectedFromVersion'\]~", $code,
            'and does not keep its own untrimmed copy of the rule beside it');
    }
}
