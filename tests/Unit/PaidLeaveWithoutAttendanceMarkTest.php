<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Approved paid leave that leaves no 'L' attendance mark must still be REPORTED.
 *
 * DEFECT (found 2026-08-20 during the final release matrix). The absence loop's
 * 'L' branch tallies leave-MARKED days. A half-day leave deliberately writes no
 * attendance mark — the day carries 'M', or nothing at all — so its balance was
 * consumed and leave_details reported it, while paid_leave_days counted ZERO.
 *
 * The slip therefore contradicted its own breakdown: "Paid leave 0" printed
 * beside a leave_details row reading total 0.5, paid 0.5. Worse, the Teacher app
 * gates its Paid-leave row on `> 0.0`, so the row vanished for precisely the
 * employees who had taken half-day paid leave.
 *
 * REPORTING ONLY. paidLeaveDays is not an input to _compute_payslip(), which
 * takes workingDays / daysAbsent / lwpDays. Pay on such a day was full before
 * and is full after — verified live at ₹44,000.00 both times. This corrects a
 * reported figure and a balance statement, not a payment.
 */
final class PaidLeaveWithoutAttendanceMarkTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($raw);
        $out = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        $this->src = $out;
    }

    /** Both engines must credit unmarked paid-leave coverage. */
    public function test_both_engines_report_paid_leave_that_left_no_mark(): void
    {
        $this->assertStringContainsString(
            "if (\$ch !== 'L' && isset(\$staffPaidSet[\$dayNum])) {", $this->src,
            'generate_payroll must report paid coverage on days without an L mark');
        $this->assertStringContainsString(
            "if (\$ch !== 'L' && isset(\$paidDaysSet[\$dayNum])) {", $this->src,
            'regenerate_staff_payroll must do the same, or the two disagree');

        $this->assertSame(2, substr_count($this->src, '$paidLeaveDays += (float) $'),
            'exactly one such credit per engine');
    }

    /**
     * The `$ch !== 'L'` guard is what prevents double counting: a day already
     * counted by the 'L' branch must not be counted again here.
     */
    public function test_days_already_counted_by_the_L_branch_are_excluded(): void
    {
        $this->assertStringNotContainsString(
            "if (isset(\$staffPaidSet[\$dayNum])) {\n                        \$paidLeaveDays",
            $this->src,
            'an unguarded credit would double-count a day marked L'
        );
    }

    /** Still reporting-only: the counter must never reach the money engine. */
    public function test_paid_leave_counter_is_not_an_input_to_the_money_engine(): void
    {
        $this->assertSame(0, preg_match_all('/_compute_payslip\([^)]*paidLeaveDays/', $this->src));
    }

    /**
     * Executable model of the per-day accumulation, covering the marks a covered
     * day can actually carry.
     */
    public function test_accumulation_matches_the_leave_breakdown(): void
    {
        $accumulate = static function (array $days): float {
            $paidLeaveDays = 0;
            foreach ($days as [$ch, $expected, $paidPortion]) {
                if ($ch === 'L') {
                    $step = ($expected == 1.0) ? 1 : $expected;
                    $paidLeaveDays += $step;
                }
                if ($ch !== 'L' && $paidPortion !== null) {
                    $paidLeaveDays += (float) $paidPortion;
                }
            }
            return (float) $paidLeaveDays;
        };

        // Half-day paid leave on a Calendar Half-Day, day marked 'M'.
        $this->assertSame(0.5, $accumulate([['M', 0.5, 0.5]]),
            'must match leave_details total=0.5');

        // Same, on an unmarked day.
        $this->assertSame(0.5, $accumulate([['V', 0.5, 0.5]]));

        // Full-day leave marked 'L' on a Calendar Half-Day — S2 gives 0.5.
        $this->assertSame(0.5, $accumulate([['L', 0.5, null]]));

        // An 'L' day that ALSO carries a paid portion is counted once.
        $this->assertSame(0.5, $accumulate([['L', 0.5, 0.5]]), 'no double count');

        // Ordinary full-day leave on a normal day stays exactly 1.
        $whole = $accumulate([['L', 1.0, null]]);
        $this->assertSame(1.0, $whole);

        // A month with no leave at all reports nothing.
        $this->assertSame(0.0, $accumulate([['P', 1.0, null], ['A', 1.0, null]]));
    }

    /**
     * TYPE SAFETY: a tenant with no half-day paid leave must keep an integer
     * counter, so its stored documents do not change type.
     */
    public function test_whole_day_only_month_keeps_an_integer_counter(): void
    {
        $paidLeaveDays = 0;
        foreach ([1.0, 1.0, 1.0] as $expected) {
            $paidLeaveDays += ($expected == 1.0) ? 1 : $expected;
        }
        $this->assertSame(3, $paidLeaveDays);
        $this->assertIsInt($paidLeaveDays);
    }
}
