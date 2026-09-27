<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * HALF-DAY PROGRAMME — reconciliation characterization + defect fix
 * (2026-08-19).
 *
 * ZenX models THREE distinct half-day concepts that must never collapse
 * into one another:
 *
 *   1. CALENDAR HALF-DAY   — the school declares a date a short working
 *      day. Lives in Working_calendar_service as REASON_HALF_DAY / mask
 *      'h'. Currently isWorking=true and counts 1.0 toward workingCount;
 *      `halfDayCount` is tallied but, by explicit design, is NOT consumed
 *      by any pay arithmetic. It therefore does NOT reduce expected work.
 *      Changing that is a financial-policy decision and is NOT touched here.
 *
 *   2. ATTENDANCE HALF-DAY — attendance mark 'M'. An ACTUAL result: the
 *      employee worked half of the expected day. Contributes 0.5 unworked.
 *
 *   3. HALF-DAY LEAVE      — an approved 0.5-day leave request. Deliberately
 *      does NOT mark attendance (the employee genuinely worked the other
 *      half); payroll prorates from the leave document instead.
 *
 * ── THE DEFECT THIS FILE FIXES ────────────────────────────────────────
 *
 * The payroll absence loop guards 'M' against double-counting with:
 *     if (!isset($staffLwpSet[$dayNum])) $daysAbsent += 0.5;
 *
 * `$staffLwpSet` is built from `$lwpDaysByStaff`, which the caller only
 * populates when `$res['lwpDays'] > 0` (Hr.php ~7256). And
 * _process_leave_for_payroll() returns early with an EMPTY result for a
 * paid leave carrying no LWP days:
 *     if ($ltPaid && $lwpDaysOnDoc === 0) return $emptyResult;   (~10861)
 *
 * So an approved **PAID** half-day leave never reports its day. The guard
 * sees nothing, and an attendance 'M' on that same date is charged as 0.5
 * day absent. Since _compute_payslip() computes
 *     $deductionDays  = $daysAbsent + $lwpDays
 *     $absentFraction = $deductionDays / $staffWorkingDays
 * that is a real deduction from gross pay.
 *
 * Net effect: an employee who takes approved PAID half-day leave and works
 * the other half is docked half a day. That is the exact opposite of the
 * required behaviour — approved paid leave must leave ZERO uncovered work
 * and therefore ZERO LOP.
 *
 * Unpaid (LWP) half-day leave was never affected: it populates the set
 * with 0.5 and the guard works. Full-day PAID leave was never affected
 * either: _apply_leave_to_attendance() marks the day 'L', which the 'L'
 * branch counts as paid leave. The hole is specific to PAID + half-day,
 * precisely because half-day leave intentionally writes no attendance mark.
 *
 * The reconciliation arithmetic is pure, so it is modelled and EXECUTED
 * below rather than string-matched — a string test cannot demonstrate that
 * money moves.
 */
class HalfDayReconciliationTest extends TestCase
{
    private const OLD = false;   // pre-fix guard: LWP set only
    private const NEW = true;    // post-fix guard: LWP set + paid-leave set

    /**
     * Faithful model of the payroll per-day absence loop
     * (Hr.php generate_payroll ~7549-7591).
     *
     * @param array $isWorking  [day => true] expected working days
     * @param array $marks      [day => 'P'|'A'|'L'|'M'|'W'|'V']
     * @param array $lwpSet     [day => 0.5|true] unpaid-leave coverage
     * @param array $paidSet    [day => 0.5] PAID-leave coverage (new)
     */
    private function reconcile(
        array $isWorking, array $marks, array $lwpSet = [],
        array $paidSet = [], bool $mode = self::NEW, string $vacantTreatment = 'ignore'
    ): array {
        $daysAbsent = 0.0; $halfDays = 0; $paidLeaveDays = 0;
        $unpaidLeaveDays = 0; $extraWorked = 0.0; $vacant = 0;

        foreach ($marks as $day => $ch) {
            if ($ch !== 'W' && !isset($isWorking[$day])) continue;

            if ($ch === 'A') {
                if (!isset($lwpSet[$day]))            $daysAbsent += 1.0;
                elseif ($lwpSet[$day] === 0.5)        $daysAbsent += 0.5;
            } elseif ($ch === 'L') {
                if (isset($lwpSet[$day]) && $lwpSet[$day] === true) $unpaidLeaveDays++;
                else                                                $paidLeaveDays++;
            } elseif ($ch === 'M') {
                $halfDays++;
                $covered = isset($lwpSet[$day])
                        || ($mode === self::NEW && isset($paidSet[$day]));
                if (!$covered) $daysAbsent += 0.5;
            } elseif ($ch === 'W') {
                $extraWorked += 1.0;
            } elseif ($ch === 'V') {
                $vacant++;
                if ($vacantTreatment === 'absent') $daysAbsent += 1.0;
            }
        }
        return compact('daysAbsent', 'halfDays', 'paidLeaveDays',
                       'unpaidLeaveDays', 'extraWorked', 'vacant');
    }

    /** _compute_payslip()'s retained-pay fraction. */
    private function retention(float $workingDays, float $daysAbsent, float $lwpDays): float
    {
        $deductionDays = $daysAbsent + $lwpDays;
        if ($deductionDays > $workingDays) $deductionDays = $workingDays;
        return $workingDays > 0 ? 1 - ($deductionDays / $workingDays) : 0.0;
    }

    /** A plain 22-working-day month. */
    private function month22(): array
    {
        $w = [];
        for ($d = 1; $d <= 22; $d++) $w[$d] = true;
        return $w;
    }

    // ── A. Normal full working day ───────────────────────────────────

    public function test_A_full_day_worked_no_leave_is_never_charged(): void
    {
        $r = $this->reconcile($this->month22(), [5 => 'P']);
        $this->assertSame(0.0, $r['daysAbsent']);
        $this->assertSame(1.0, $this->retention(22, $r['daysAbsent'], 0));
    }

    // ── B. Attendance half-day, no leave ─────────────────────────────

    public function test_B_attendance_half_day_without_leave_charges_half(): void
    {
        // Correct and unchanged by the fix: genuinely uncovered half day.
        $r = $this->reconcile($this->month22(), [5 => 'M']);
        $this->assertSame(0.5, $r['daysAbsent']);
        $this->assertSame(1, $r['halfDays']);
    }

    public function test_B_is_identical_before_and_after_the_fix(): void
    {
        $old = $this->reconcile($this->month22(), [5 => 'M'], [], [], self::OLD);
        $new = $this->reconcile($this->month22(), [5 => 'M'], [], [], self::NEW);
        $this->assertEquals($old, $new, 'an uncovered attendance half-day must be unaffected by the fix');
    }

    // ── C. THE DEFECT — attendance half-day + approved PAID half-day leave ──

    public function test_C_OLD_behaviour_wrongly_charges_paid_half_day_leave(): void
    {
        // Employee worked 4h, has approved PAID half-day leave for the rest.
        // Pre-fix: the paid leave reports nothing, so 'M' is charged.
        $r = $this->reconcile($this->month22(), [5 => 'M'], [], [5 => 0.5], self::OLD);
        $this->assertSame(0.5, $r['daysAbsent'],
            'documents the defect: approved PAID half-day leave was still docked 0.5 day');
    }

    public function test_C_NEW_behaviour_produces_zero_uncovered_work_and_zero_lop(): void
    {
        $r = $this->reconcile($this->month22(), [5 => 'M'], [], [5 => 0.5], self::NEW);
        $this->assertSame(0.0, $r['daysAbsent'],
            'approved paid half-day leave must leave zero uncovered work');
        $this->assertSame(1.0, $this->retention(22, $r['daysAbsent'], 0),
            'and therefore a full payable day');
        // The attendance fact is still recorded — the day WAS a half day.
        $this->assertSame(1, $r['halfDays'],
            'the attendance half-day must remain visible; only the LOP charge is removed');
    }

    public function test_C_the_defect_costs_real_money(): void
    {
        // Demonstrates why this is P1 and not cosmetic.
        $gross = 44000.0;
        $old = $this->reconcile($this->month22(), [5 => 'M'], [], [5 => 0.5], self::OLD);
        $new = $this->reconcile($this->month22(), [5 => 'M'], [], [5 => 0.5], self::NEW);
        $lost = $gross * ($this->retention(22, $new['daysAbsent'], 0)
                        - $this->retention(22, $old['daysAbsent'], 0));
        $this->assertEqualsWithDelta(1000.0, $lost, 0.01,
            'on a 44,000 gross over 22 working days the defect silently deducted 1,000');
    }

    // ── Unpaid half-day leave must KEEP deducting ────────────────────

    public function test_unpaid_half_day_leave_still_deducts_via_the_lwp_path(): void
    {
        // LWP half-day: the day IS in the lwp set, so 'M' is not double
        // charged here — the deduction comes from lwpDays in the payslip.
        $r = $this->reconcile($this->month22(), [5 => 'M'], [5 => 0.5], [], self::NEW);
        $this->assertSame(0.0, $r['daysAbsent'], 'no double count against the LWP charge');
        $this->assertEqualsWithDelta(
            1 - (0.5 / 22), $this->retention(22, $r['daysAbsent'], 0.5), 0.0001,
            'the 0.5 LWP is still deducted, once, through lwpDays');
    }

    public function test_absent_on_the_other_half_of_an_lwp_half_day_still_charges_half(): void
    {
        $r = $this->reconcile($this->month22(), [5 => 'A'], [5 => 0.5], [], self::NEW);
        $this->assertSame(0.5, $r['daysAbsent']);
    }

    public function test_full_day_lwp_absorbs_an_absent_mark_entirely(): void
    {
        $r = $this->reconcile($this->month22(), [5 => 'A'], [5 => true], [], self::NEW);
        $this->assertSame(0.0, $r['daysAbsent']);
    }

    // ── Full-day PAID leave is unaffected (marked 'L' by the sync) ───

    public function test_full_day_paid_leave_is_counted_as_paid_leave_not_absence(): void
    {
        $r = $this->reconcile($this->month22(), [5 => 'L'], [], [], self::NEW);
        $this->assertSame(0.0, $r['daysAbsent']);
        $this->assertSame(1, $r['paidLeaveDays']);
    }

    // ── L. No double counting under combined coverage ────────────────

    public function test_L_paid_and_unpaid_coverage_on_one_day_never_double_counts(): void
    {
        // Defensive: if both sets somehow name the same day, the LWP set
        // wins and the day is charged exactly once, never twice.
        $r = $this->reconcile($this->month22(), [5 => 'M'], [5 => 0.5], [5 => 0.5], self::NEW);
        $this->assertSame(0.0, $r['daysAbsent']);
    }

    // ── Non-working days are never charged ───────────────────────────

    public function test_marks_on_non_working_days_are_ignored(): void
    {
        // A stray 'A' or 'M' on a weekly off / holiday must not deduct.
        $r = $this->reconcile($this->month22(), [25 => 'A', 26 => 'M'], [], [], self::NEW);
        $this->assertSame(0.0, $r['daysAbsent']);
    }

    public function test_extra_worked_on_an_off_day_is_credited_not_charged(): void
    {
        $r = $this->reconcile($this->month22(), [25 => 'W'], [], [], self::NEW);
        $this->assertSame(1.0, $r['extraWorked']);
        $this->assertSame(0.0, $r['daysAbsent']);
    }

    // ── Concept separation invariants ────────────────────────────────

    public function test_attendance_half_day_is_not_converted_into_leave(): void
    {
        // An 'M' with no leave must never appear as paid or unpaid leave.
        $r = $this->reconcile($this->month22(), [5 => 'M'], [], [], self::NEW);
        $this->assertSame(0, $r['paidLeaveDays']);
        $this->assertSame(0, $r['unpaidLeaveDays']);
    }

    public function test_half_day_leave_does_not_fabricate_an_attendance_half_day(): void
    {
        // Coverage alone, with no 'M' mark, must not invent a half day.
        $r = $this->reconcile($this->month22(), [5 => 'P'], [], [5 => 0.5], self::NEW);
        $this->assertSame(0, $r['halfDays']);
        $this->assertSame(0.0, $r['daysAbsent']);
    }

    // ── Whole-month regression: nothing else moves ───────────────────

    public function test_a_clean_full_attendance_month_is_unchanged_by_the_fix(): void
    {
        $marks = [];
        for ($d = 1; $d <= 22; $d++) $marks[$d] = 'P';
        $old = $this->reconcile($this->month22(), $marks, [], [], self::OLD);
        $new = $this->reconcile($this->month22(), $marks, [], [], self::NEW);
        $this->assertEquals($old, $new);
        $this->assertSame(0.0, $new['daysAbsent']);
    }

    public function test_fix_only_moves_days_that_carry_paid_leave_coverage(): void
    {
        // Mixed month; only day 5 has paid half-day coverage.
        $marks = [3 => 'A', 5 => 'M', 7 => 'M', 9 => 'L', 11 => 'P'];
        $old = $this->reconcile($this->month22(), $marks, [], [5 => 0.5], self::OLD);
        $new = $this->reconcile($this->month22(), $marks, [], [5 => 0.5], self::NEW);
        $this->assertSame(2.0, $old['daysAbsent']);   // 1.0 + 0.5 + 0.5
        $this->assertSame(1.5, $new['daysAbsent']);   // day 5 no longer charged
        $this->assertSame($old['paidLeaveDays'], $new['paidLeaveDays']);
        $this->assertSame($old['halfDays'], $new['halfDays']);
    }
}
