<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Executes Hr::_leave_coverage_for_day() — the single rule that decides how much
 * of a day's expected work approved leave already covers.
 *
 * DEFECT IT CLOSES (2026-08-19). The payroll absence loop had three branches
 * applying leave coverage three different ways:
 *   • 'A' honoured the LWP set but ignored PAID coverage;
 *   • 'M' honoured both, all-or-nothing;
 *   • 'V' honoured NEITHER — and 'V' is the UNMARKED-day fill
 *     (Staff_attendance_writer initialises a month as str_repeat('V', …)).
 *
 * A half-day leave deliberately writes no attendance mark, so the covered day
 * stays 'V'. With vacant treatment 'absent' the employee was charged the whole
 * day's expectation despite approved leave, and a full-day UNPAID leave was
 * charged twice — once as lwpDays by the leave engine and again as absence.
 * Both are over-deductions: the employee is underpaid.
 *
 * LOCKED RULE (ratified by the operator): a half-day leave on a Calendar
 * Half-Day covers the whole 0.5 expectation → 0 uncovered → 0 LOP → FULL pay,
 * and consumes 0.5 leave balance. The Attendance-'M' 0.25 symmetry is
 * deliberately NOT applied to leave: an attendance half-day and a half-day
 * leave are different business facts.
 */
final class LeaveCoverageRuleTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists('Hr', false)) {
            // The controller cannot be loaded inside the suite (other tests
            // parse the same file), so exercise the rule through an exact
            // transcription, and pin that transcription against the shipped
            // source in the final test below.
        }
    }

    /** Transcription of the shipped rule — kept honest by the source pin below. */
    private function coverage(float $dayExpected, $lwpEntry, $paidEntry): float
    {
        $cov = 0.0;
        if ($lwpEntry !== null) {
            $cov += ($lwpEntry === true) ? $dayExpected : (float) $lwpEntry;
        }
        if ($paidEntry !== null) {
            $cov += (float) $paidEntry;
        }
        if ($cov < 0.0) $cov = 0.0;
        return ($cov > $dayExpected) ? $dayExpected : $cov;
    }

    /** Absence a branch would charge, given the mark's own uncovered share. */
    private function charged(float $uncoveredShare, float $coverage): float
    {
        return max(0.0, $uncoveredShare - $coverage);
    }

    // ── No leave: unchanged behaviour ────────────────────────────────────

    public function test_no_leave_leaves_the_full_expectation_chargeable(): void
    {
        $this->assertSame(0.0, $this->coverage(1.0, null, null));
        $this->assertSame(1.0, $this->charged(1.0, $this->coverage(1.0, null, null)));
        $this->assertSame(0.5, $this->charged(0.5, $this->coverage(0.5, null, null)));
    }

    // ── THE LOCKED RULE ──────────────────────────────────────────────────

    public function test_half_day_leave_on_a_calendar_half_day_covers_it_entirely(): void
    {
        // Expected 0.5, approved half-day leave coverage 0.5.
        $cov = $this->coverage(0.5, null, 0.5);

        $this->assertSame(0.5, $cov, 'coverage caps at the expectation, never above');
        $this->assertSame(0.0, $this->charged(0.5, $cov), 'uncovered work must be 0 → LOP 0 → FULL pay');
    }

    public function test_half_day_leave_on_a_normal_day_covers_exactly_half(): void
    {
        $cov = $this->coverage(1.0, null, 0.5);

        $this->assertSame(0.5, $cov);
        $this->assertSame(0.5, $this->charged(1.0, $cov), 'the other half remains chargeable');
    }

    /**
     * The 'M' symmetry must NOT leak into leave. A half-day leave covers 0.5 of
     * the day, not "half of whatever was expected" — that rule belongs to the
     * attendance mark alone.
     */
    public function test_leave_coverage_is_not_rescaled_by_the_day_expectation(): void
    {
        $this->assertSame(0.5, $this->coverage(0.5, null, 0.5),
            'a half-day leave on a Calendar Half-Day still covers 0.5, not 0.25');
    }

    // ── The 'V' unmarked-day cases that were being over-charged ──────────

    public function test_full_day_unpaid_leave_on_an_unmarked_day_is_not_charged_twice(): void
    {
        // The leave engine already charged this day as lwpDays; the absence
        // loop must add nothing on top.
        $cov = $this->coverage(1.0, true, null);

        $this->assertSame(1.0, $cov);
        $this->assertSame(0.0, $this->charged(1.0, $cov), 'double deduction');
    }

    public function test_full_day_unpaid_leave_on_a_calendar_half_day(): void
    {
        $cov = $this->coverage(0.5, true, null);

        $this->assertSame(0.5, $cov, 'a whole-day LWP covers the whole reduced expectation');
        $this->assertSame(0.0, $this->charged(0.5, $cov));
    }

    public function test_partial_lwp_leaves_only_the_remainder_chargeable(): void
    {
        // A fractional multi-day LWP makes the earliest day partial.
        $cov = $this->coverage(1.0, 0.5, null);

        $this->assertSame(0.5, $cov);
        $this->assertSame(0.5, $this->charged(1.0, $cov));
    }

    // ── Attendance 'M' interaction ───────────────────────────────────────

    public function test_attendance_half_day_with_paid_leave_nets_to_zero(): void
    {
        // 'M' leaves half the expectation uncovered; approved paid leave covers 0.5.
        $this->assertSame(0.0, $this->charged(1.0 * 0.5, $this->coverage(1.0, null, 0.5)));
        // …and on a Calendar Half-Day, where 'M' leaves only 0.25 uncovered.
        $this->assertSame(0.0, $this->charged(0.5 * 0.5, $this->coverage(0.5, null, 0.5)));
    }

    /**
     * The one behaviour that genuinely changed: the old guard skipped the day
     * whenever ANY leave existed. A partial portion smaller than the half that
     * 'M' leaves uncovered now correctly leaves the remainder chargeable.
     */
    public function test_partial_coverage_smaller_than_the_missing_half_still_charges(): void
    {
        $cov = $this->coverage(1.0, 0.25, null);

        $this->assertSame(0.25, $cov);
        $this->assertSame(0.25, $this->charged(0.5, $cov),
            'worked half + leave 0.25 = 0.75 covered of 1.0 expected → 0.25 chargeable');
    }

    // ── Guard rails ──────────────────────────────────────────────────────

    public function test_coverage_can_never_exceed_the_days_expectation(): void
    {
        // Overlapping leave records must not manufacture credit.
        $this->assertSame(1.0, $this->coverage(1.0, true, 0.5));
        $this->assertSame(0.5, $this->coverage(0.5, true, 0.5));
        $this->assertSame(0.0, $this->charged(1.0, $this->coverage(1.0, true, 0.5)));
    }

    public function test_negative_input_cannot_increase_the_charge(): void
    {
        $this->assertSame(0.0, $this->coverage(1.0, -1.0, null));
        $this->assertSame(1.0, $this->charged(1.0, $this->coverage(1.0, -1.0, null)));
    }

    /**
     * SOURCE PIN: the transcription above must match the shipped rule, and both
     * engines must use it. Without this the tests could pass against a
     * transcription that no longer resembles production.
     */
    public function test_shipped_rule_matches_this_transcription(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($src);

        foreach ([
            'public static function _leave_coverage_for_day(float $dayExpected, $lwpEntry, $paidEntry): float',
            '$cov += ($lwpEntry === true) ? $dayExpected : (float) $lwpEntry;',
            '$cov += (float) $paidEntry;',
            'return ($cov > $dayExpected) ? $dayExpected : $cov;',
        ] as $line) {
            $this->assertStringContainsString($line, $src);
        }

        // Used by both engines: two call sites, no per-branch copies.
        $this->assertSame(2, substr_count($src, 'self::_leave_coverage_for_day('),
            'generation and regeneration must both use the shared rule, exactly once each');
    }
}
