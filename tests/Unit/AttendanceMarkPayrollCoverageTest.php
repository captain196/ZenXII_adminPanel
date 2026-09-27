<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Every attendance mark must have a decided payroll disposition.
 *
 * Staff attendance is stored as a dayWise string, one character per day. The
 * writer's vocabulary is declared in Staff_attendance.php as the tally array
 * `$c = ['P' => 0, 'A' => 0, ...]`. The payroll engine consumes the same string
 * character by character.
 *
 * The failure mode this guards is silent and expensive: someone adds a tenth
 * mark to the attendance side, payroll has no branch for it, and the day falls
 * through the day-loop as "no pay effect". An unpaid mark would then be paid in
 * full, in every tenant, with nothing in any log.
 *
 * AUDITED 2026-08-21 — coverage is COMPLETE. All nine marks are accounted for:
 *
 *   CHARGEABLE (payroll branches on them)
 *     A  absent            — charged for the day's expected work, less coverage
 *     V  vacant/unmarked   — the unmarked fill; a half-day leave writes no mark
 *                            and lands here, hence the coverage term
 *     M  half-day          — half of THAT DAY'S expectation (Calendar Half-Day)
 *     L  leave             — tallied; paid/LWP split decided by the leave engine
 *     W  worked on a rest day — the deliberate exception to the non-working-day
 *                            gate; it MEANS extra work and must stay credit-side
 *
 *   NO PAY EFFECT (documented in the day loop, deliberately unbranched)
 *     P  present
 *     H  holiday           — derived by _staff_working_days() from the calendar;
 *     O  weekly-off        — branching here would DOUBLE-COUNT the non-working day
 *     T  late check-in     — lateness is not automatic LOP
 *
 * This is a completeness test, not a structural one: it reads the writer's
 * vocabulary out of the shipped source and asserts that each mark it can emit
 * has an explicit disposition on the payroll side. Adding a mark to one file
 * without deciding the other fails it.
 */
final class AttendanceMarkPayrollCoverageTest extends TestCase
{
    /** Marks payroll branches on, with the sign of their effect. */
    private const CHARGEABLE = ['A', 'V', 'M', 'L'];
    private const CREDIT     = ['W'];
    private const NO_EFFECT  = ['P', 'H', 'O', 'T'];

    private string $att;
    private string $hr;

    protected function setUp(): void
    {
        $att = file_get_contents(__DIR__ . '/../../application/controllers/Staff_attendance.php');
        $hr  = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($att);
        $this->assertNotFalse($hr);
        $this->att = $att;
        $this->hr  = $hr;
    }

    /**
     * The writer's vocabulary, read out of the shipped tally array rather than
     * hardcoded here — so a new mark shows up in this test automatically.
     */
    private function writerVocabulary(): array
    {
        $ok = preg_match(
            "/\\\$c = \[\s*('[A-Z]'\s*=>\s*0\s*,?\s*)+\]/",
            $this->att,
            $m
        );
        $this->assertSame(1, $ok, 'staff attendance tally array not found — re-read the writer');

        preg_match_all("/'([A-Z])'\s*=>\s*0/", $m[0], $marks);
        $v = array_values(array_unique($marks[1]));
        sort($v);
        return $v;
    }

    // ── The completeness invariant ───────────────────────────────────────

    public function test_every_writable_mark_has_a_payroll_disposition(): void
    {
        $decided = array_merge(self::CHARGEABLE, self::CREDIT, self::NO_EFFECT);

        foreach ($this->writerVocabulary() as $mark) {
            $this->assertContains(
                $mark,
                $decided,
                "attendance can write '{$mark}' but payroll has no decided disposition for it. "
                . 'Decide whether it is chargeable, credit-side or no-effect BEFORE shipping it — '
                . 'an undecided mark falls through the day loop and is silently paid in full.'
            );
        }
    }

    /** And nothing is decided that the writer cannot actually produce. */
    public function test_no_disposition_refers_to_a_mark_that_cannot_be_written(): void
    {
        $vocab = $this->writerVocabulary();

        foreach (array_merge(self::CHARGEABLE, self::CREDIT, self::NO_EFFECT) as $mark) {
            $this->assertContains($mark, $vocab,
                "'{$mark}' has a payroll disposition but the writer can no longer emit it");
        }
    }

    public function test_the_three_dispositions_are_disjoint(): void
    {
        $this->assertSame([], array_intersect(self::CHARGEABLE, self::NO_EFFECT));
        $this->assertSame([], array_intersect(self::CHARGEABLE, self::CREDIT));
        $this->assertSame([], array_intersect(self::CREDIT, self::NO_EFFECT));
    }

    public function test_the_vocabulary_is_the_nine_marks_this_analysis_covered(): void
    {
        $this->assertSame(
            ['A', 'H', 'L', 'M', 'O', 'P', 'T', 'V', 'W'],
            $this->writerVocabulary(),
            'the mark vocabulary changed — re-run the payroll disposition analysis'
        );
    }

    // ── Each chargeable mark really is branched in BOTH engines ──────────

    /**
     * Generation and regeneration must agree. A mark branched in one engine but
     * not the other means a regenerated payslip differs from the original.
     */
    public function test_chargeable_marks_are_branched_in_both_payroll_engines(): void
    {
        foreach (self::CHARGEABLE as $mark) {
            $this->assertGreaterThanOrEqual(
                2,
                substr_count($this->hr, "\$ch === '{$mark}'"),
                "'{$mark}' must be branched in BOTH generate and regenerate, or a regenerated "
                . 'payslip will differ from the one originally generated'
            );
        }
    }

    public function test_worked_on_a_rest_day_is_branched_in_both_engines(): void
    {
        $this->assertGreaterThanOrEqual(2, substr_count($this->hr, "\$ch === 'W'"));
    }

    // ── The no-effect set is DOCUMENTED, not accidental ──────────────────

    /**
     * The distinction matters: an unbranched mark is only safe when someone
     * decided it should be. This pins the comment that records that decision.
     */
    public function test_the_no_effect_marks_are_explicitly_documented(): void
    {
        $this->assertStringContainsString(
            "// 'P' present, 'H' holiday, 'T' late, 'O' weekly-off → no pay",
            $this->hr,
            'the no-pay-effect decision must stay written down, not inferred from absence'
        );
    }

    /** H and O must not be branched — that would double-count the calendar. */
    public function test_holiday_and_weekly_off_are_not_branched_in_payroll(): void
    {
        foreach (['H', 'O'] as $mark) {
            $this->assertSame(
                0,
                substr_count($this->hr, "\$ch === '{$mark}'"),
                "branching on '{$mark}' double-counts a non-working day already derived by "
                . '_staff_working_days() from the holiday calendar and weekly-off policy'
            );
        }
    }

    /** Lateness is not automatic LOP. */
    public function test_late_is_not_charged(): void
    {
        $this->assertSame(0, substr_count($this->hr, "\$ch === 'T'"),
            'making lateness chargeable is a business-policy decision, not a code fix');
    }

    // ── The non-working-day gate and its one exception ───────────────────

    /**
     * N-23: an 'A' on a Sunday was charged as absence. The gate now suppresses
     * non-working days, with 'W' deliberately exempt because it MEANS work done
     * on a scheduled non-working day.
     */
    public function test_the_non_working_day_gate_keeps_its_worked_exception(): void
    {
        $this->assertStringContainsString(
            "'W' is the deliberate exception",
            $this->hr,
            'suppressing W in the non-working-day gate destroys extra-worked pay'
        );
    }

    /**
     * Release large source strings between tests.
     *
     * PHPUnit keeps every TestCase instance alive for the duration of the run,
     * so a multi-hundred-KB source held in a property stays resident once per
     * test method. Measured: a source-reading class costs ~18 MB against a
     * 512 MB limit, and the suite was running at 506 MB. Clearing the reference
     * frees the string while leaving the (now tiny) instance for reporting.
     */
    protected function tearDown(): void
    {
        foreach (get_object_vars($this) as $prop => $val) {
            if (is_string($val) && strlen($val) > 50000) $this->{$prop} = '';
        }
    }
}
