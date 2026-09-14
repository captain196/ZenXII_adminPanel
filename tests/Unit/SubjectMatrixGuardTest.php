<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Subject_matrix_guard.php';

/**
 * REFUSING A CLASS-MATRIX SAVE THAT ARRIVED INCOMPLETE.
 *
 * The matrix screen sends the whole class in one request and the save
 * reconciles: anything stored that the request does not mention is deleted.
 * That is how removing a subject works, and it is also why an incomplete
 * request is indistinguishable from a deliberate mass removal.
 *
 * firestore.rules grants marks and homework writes straight off these rows, so
 * a row deleted here is a teacher losing access to a class they still teach.
 */
final class SubjectMatrixGuardTest extends TestCase
{
    private function desired(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) { $out[$id] = ['subjectCode' => 'X']; }
        return $out;
    }

    // -- ordinary saves are not obstructed ---------------------------------

    public function test_a_normal_save_is_allowed(): void
    {
        $this->assertNull(Subject_matrix_guard::refusalReason(
            $this->desired(['a', 'b']),
            ['Section A', 'Section B'],
            ['Section A', 'Section B']));
    }

    /** A class with nothing stored yet cannot lose anything. */
    public function test_a_brand_new_class_is_allowed_even_with_nothing_sent(): void
    {
        $this->assertNull(Subject_matrix_guard::refusalReason([], [], []));
        $this->assertNull(Subject_matrix_guard::refusalReason(
            $this->desired(['a']), [], ['Section A']));
    }

    /** Deliberately clearing a class-wide-only class is still possible. */
    public function test_clearing_a_class_that_has_only_class_wide_rows_is_allowed(): void
    {
        $this->assertNull(Subject_matrix_guard::refusalReason(
            $this->desired(['a']), ['', ''], []),
            'a class with no per-section rows loses nothing by sending no sections');
    }

    // -- the empty request -------------------------------------------------

    public function test_an_empty_request_against_a_populated_class_is_refused(): void
    {
        $why = Subject_matrix_guard::refusalReason([], ['Section A'], ['Section A']);

        $this->assertNotNull($why);
        $this->assertMatchesRegularExpression('~no subjects~i', $why);
        $this->assertMatchesRegularExpression('~reload~i', $why,
            'the operator is told what to do, not merely that something is wrong');
    }

    // -- the subtler one ---------------------------------------------------

    /**
     * THE DEFECT this guard exists for. The request is not empty — the
     * class-wide subjects are all present — but it lists no sections, so every
     * per-section subject builds no rows and the reconciliation removes every
     * section teacher in the class.
     *
     * This is precisely the shape a failed sections read upstream produces: the
     * screen draws a grid with no section columns, and saving it revokes the lot.
     */
    public function test_a_request_with_no_sections_against_per_section_rows_is_refused(): void
    {
        $why = Subject_matrix_guard::refusalReason(
            $this->desired(['classwide_math']),      // not empty
            ['', 'Section A', 'Section B'],          // the class HAS section rows
            []                                       // …but none were sent
        );

        $this->assertNotNull($why,
            'a non-empty request can still be a request that lost its sections');
        $this->assertMatchesRegularExpression('~section~i', $why);
        $this->assertMatchesRegularExpression('~nothing was changed~i', $why);
    }

    public function test_the_same_request_is_allowed_once_the_sections_are_present(): void
    {
        $this->assertNull(Subject_matrix_guard::refusalReason(
            $this->desired(['classwide_math']),
            ['', 'Section A', 'Section B'],
            ['Section A', 'Section B']));
    }

    /** Sections that are only whitespace are not sections. */
    public function test_blank_section_labels_do_not_count_as_per_section_rows(): void
    {
        $this->assertNull(Subject_matrix_guard::refusalReason(
            $this->desired(['a']), ['', '   ', ''], []));
    }

    // -- what gets removed -------------------------------------------------

    public function test_stale_ids_are_the_rows_the_request_no_longer_mentions(): void
    {
        $stale = Subject_matrix_guard::staleIds(
            ['keep', 'drop_one', 'drop_two'],
            $this->desired(['keep']));

        sort($stale);
        $this->assertSame(['drop_one', 'drop_two'], $stale);
    }

    public function test_nothing_is_stale_when_the_request_mentions_everything(): void
    {
        $this->assertSame([], Subject_matrix_guard::staleIds(
            ['a', 'b'], $this->desired(['a', 'b'])));
    }

    public function test_a_class_with_nothing_stored_has_nothing_to_remove(): void
    {
        $this->assertSame([], Subject_matrix_guard::staleIds([], $this->desired(['a'])));
    }
}
