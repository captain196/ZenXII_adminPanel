<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * CALENDAR HALF-DAY — mandatory test matrix A–V (authorized model, 2026-08-19).
 *
 * LOCKED BUSINESS RULE
 *   Calendar Half-Day → Expected Work = 0.5
 *   → Attendance + Approved Leave → uncovered work → LOP → payable day.
 *
 *   Reduced expected work is NOT reduced salary. If the employee fully
 *   fulfils the declared half-day: expected 0.5, covered 0.5, LOP 0,
 *   payable = FULL DAY / normal salary.
 *
 * WHY THAT WORKS WITHOUT A NEW CONCEPT
 *   _compute_payslip() uses working days purely as a RATIO DENOMINATOR:
 *       retention = 1 - (daysAbsent + lwpDays) / staffWorkingDays
 *   so fulfilling a reduced expectation gives deduction 0 → retention 1.0 →
 *   full salary, whether the denominator is 22 or 21.5.
 *
 * APPROVED SUB-DECISIONS
 *   S1  'M' on a Calendar Half-Day = half of the REDUCED expectation
 *       (0.25 uncovered), not half of a standard day.
 *   S2  A full-day leave on a Calendar Half-Day consumes only 0.5 —
 *       you cannot be absent from work that was never expected.
 *   S3  Working full normal hours on a declared half-day earns NO overtime
 *       credit. Normal full payable day. No overtime policy invented.
 *   S4  Per-tenant flag, OFF by default, with an explicit effective-date
 *       boundary so historical payroll cannot silently move.
 *
 * CONCEPT SEPARATION (never merged)
 *   Calendar Half-Day = a CALENDAR fact  → sets expected work
 *   Attendance Half-Day ('M') = an ACTUAL result → what was delivered
 *   Half-Day Leave = an APPROVED LEAVE fact → what is excused
 *
 * The reconciliation is pure arithmetic, so it is EXECUTED here rather than
 * string-matched. The model below mirrors the shipped code in
 * Working_calendar_service::resolveFromVersion() and both payroll engines.
 */
class CalendarHalfDayMatrixTest extends TestCase
{
    private const GROSS = 44000.0;

    /** Mirrors Working_calendar_service: what one day contributes to expected work. */
    private function expectedFor(string $reason, float $halfDayWeight): float
    {
        // Precedence is resolved BEFORE this point (SWD > weeklyOff > nth >
        // holiday > halfDay); non-working reasons never reach the pay loop.
        return $reason === 'halfDay' ? $halfDayWeight : 1.0;
    }

    /**
     * Mirrors the payroll absence loop in BOTH engines.
     *
     * @param array $days  [dayNum => ['reason'=>string,'mark'=>string]]
     * @param array $lwp   [dayNum => 0.5|true]  unpaid-leave coverage
     * @param array $paid  [dayNum => 0.5]       approved PAID leave coverage
     */
    private function reconcile(array $days, float $halfDayWeight, array $lwp = [], array $paid = [], string $vacant = 'ignore'): array
    {
        $expectedWork = 0.0; $daysAbsent = 0.0; $halfDayMarks = 0;
        $paidLeaveDays = 0; $unpaidLeaveDays = 0; $extraWorked = 0.0;

        foreach ($days as $d => $info) {
            $reason = $info['reason'];
            $mark   = $info['mark'];

            $isWorkingDay = !in_array($reason, ['weeklyOff', 'holiday', 'nthWeekday', 'outside'], true);
            if ($isWorkingDay) $expectedWork += $this->expectedFor($reason, $halfDayWeight);

            if ($mark !== 'W' && !$isWorkingDay) continue;
            $dayExpected = $this->expectedFor($reason, $halfDayWeight);

            if ($mark === 'A') {
                if (!isset($lwp[$d]))            $daysAbsent += $dayExpected;
                elseif ($lwp[$d] !== true)       $daysAbsent += max(0.0, $dayExpected - (float) $lwp[$d]);
            } elseif ($mark === 'L') {
                if (isset($lwp[$d]) && $lwp[$d] === true) $unpaidLeaveDays++;
                else                                      $paidLeaveDays++;
            } elseif ($mark === 'M') {
                $halfDayMarks++;
                if (!isset($lwp[$d]) && !isset($paid[$d])) $daysAbsent += $dayExpected * 0.5;
            } elseif ($mark === 'W') {
                $extraWorked += 1.0;
            } elseif ($mark === 'V') {
                if ($vacant === 'absent') $daysAbsent += $dayExpected;
            }
        }
        return compact('expectedWork', 'daysAbsent', 'halfDayMarks',
                       'paidLeaveDays', 'unpaidLeaveDays', 'extraWorked');
    }

    /** Mirrors _compute_payslip()'s retention ratio. */
    private function net(float $expectedWork, float $daysAbsent, float $lwpDays = 0.0): float
    {
        $ded = $daysAbsent + $lwpDays;
        if ($ded > $expectedWork) $ded = $expectedWork;
        $retention = $expectedWork > 0 ? 1 - ($ded / $expectedWork) : 0.0;
        return round(self::GROSS * $retention, 2);
    }

    /** Mirrors _half_day_weight(): the flag + effective-date boundary. */
    private function weight(bool $enabled, string $effectiveFrom, string $periodStart): float
    {
        if (!$enabled) return 1.0;
        if ($effectiveFrom === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom)) return 1.0;
        return $periodStart >= $effectiveFrom ? 0.5 : 1.0;
    }

    /** 22 normal working days; day 15 optionally re-labelled. */
    private function month(string $day15 = 'working', string $mark15 = 'P', string $otherMark = 'P'): array
    {
        $days = [];
        for ($d = 1; $d <= 22; $d++) {
            $days[$d] = ['reason' => $d === 15 ? $day15 : 'working',
                         'mark'   => $d === 15 ? $mark15 : $otherMark];
        }
        return $days;
    }

    // ══ A–C · NORMAL DAYS (must be untouched by this feature) ══════════

    public function test_A_normal_day_full_attendance(): void
    {
        $r = $this->reconcile($this->month(), 0.5);
        $this->assertSame(22.0, $r['expectedWork']);
        $this->assertSame(0.0, $r['daysAbsent'], 'LOP');
        $this->assertSame(44000.00, $this->net($r['expectedWork'], $r['daysAbsent']), 'full salary');
    }

    public function test_B_normal_day_attendance_half_day(): void
    {
        $r = $this->reconcile($this->month('working', 'M'), 0.5);
        $this->assertSame(22.0, $r['expectedWork']);
        $this->assertSame(0.5, $r['daysAbsent'], 'half of a full day uncovered');
        $this->assertSame(43000.00, $this->net($r['expectedWork'], $r['daysAbsent']));
    }

    public function test_C_normal_day_half_day_mark_plus_paid_half_day_leave(): void
    {
        $r = $this->reconcile($this->month('working', 'M'), 0.5, [], [15 => 0.5]);
        $this->assertSame(0.0, $r['daysAbsent'], 'approved paid leave covers the other half');
        $this->assertSame(44000.00, $this->net($r['expectedWork'], $r['daysAbsent']));
    }

    // ══ D–J · CALENDAR HALF-DAY ════════════════════════════════════════

    public function test_D_calendar_half_day_expected_hours_completed(): void
    {
        // THE LOAD-BEARING CASE: reduced expectation, fully met, FULL salary.
        $r = $this->reconcile($this->month('halfDay', 'P'), 0.5);
        $this->assertSame(21.5, $r['expectedWork'], 'expected work reduced to 0.5 for that day');
        $this->assertSame(0.0, $r['daysAbsent'], 'nothing uncovered');
        $this->assertSame(44000.00, $this->net($r['expectedWork'], $r['daysAbsent']),
            'reduced expected work must NOT mean reduced salary');
    }

    public function test_E_calendar_half_day_complete_absence(): void
    {
        $r = $this->reconcile($this->month('halfDay', 'A'), 0.5);
        $this->assertSame(21.5, $r['expectedWork']);
        $this->assertSame(0.5, $r['daysAbsent'], 'only 0.5 was expected, so only 0.5 can be missed');
        $this->assertSame(42976.74, $this->net($r['expectedWork'], $r['daysAbsent']));
    }

    public function test_F_calendar_half_day_plus_attendance_half_day_S1(): void
    {
        // S1: 'M' covers half of the REDUCED day → 0.25 uncovered.
        $r = $this->reconcile($this->month('halfDay', 'M'), 0.5);
        $this->assertSame(0.25, $r['daysAbsent'], 'S1: half of 0.5, not half of 1.0');
        $this->assertSame(43488.37, $this->net($r['expectedWork'], $r['daysAbsent']));
    }

    public function test_G_calendar_half_day_plus_paid_half_day_leave(): void
    {
        $r = $this->reconcile($this->month('halfDay', 'M'), 0.5, [], [15 => 0.5]);
        $this->assertSame(0.0, $r['daysAbsent'], 'approved paid leave covers the reduced expectation');
        $this->assertSame(44000.00, $this->net($r['expectedWork'], $r['daysAbsent']));
    }

    public function test_H_calendar_half_day_plus_unpaid_lwp_leave(): void
    {
        // 0.5 LWP fully covers the 0.5 expectation → nothing further absent,
        // but LWP itself still deducts.
        $r = $this->reconcile($this->month('halfDay', 'A'), 0.5, [15 => 0.5]);
        $this->assertSame(0.0, $r['daysAbsent'], 'no double charge on top of the LWP');
        $this->assertSame(42976.74, $this->net($r['expectedWork'], $r['daysAbsent'], 0.5));
    }

    public function test_I_calendar_half_day_plus_full_day_leave_S2(): void
    {
        // S2: consumption is capped at expected work → 0.5, not 1.0.
        $consumed = $this->chargeableLeaveDays(['halfDay'], 0.5);
        $this->assertSame(0.5, $consumed, 'S2: full-day leave on a half-day consumes only 0.5');

        $paid = $this->reconcile($this->month('halfDay', 'L'), 0.5);
        $this->assertSame(0.0, $paid['daysAbsent']);
        $this->assertSame(44000.00, $this->net($paid['expectedWork'], $paid['daysAbsent']),
            'paid full-day leave on a half-day → full salary');
        $this->assertSame(42976.74, $this->net($paid['expectedWork'], 0.0, 0.5),
            'unpaid variant deducts 0.5, not 1.0');
    }

    public function test_J_calendar_half_day_plus_full_normal_day_attendance_S3(): void
    {
        // S3: no overtime, no extra credit — just a normal full payable day.
        $r = $this->reconcile($this->month('halfDay', 'P'), 0.5);
        $this->assertSame(0.0, $r['extraWorked'], 'S3: no overtime credit invented');
        $this->assertSame(0.0, $r['daysAbsent']);
        $this->assertSame(44000.00, $this->net($r['expectedWork'], $r['daysAbsent']));
    }

    // ══ K–L · PRECEDENCE ═══════════════════════════════════════════════

    public function test_K_calendar_half_day_plus_holiday(): void
    {
        // Holiday wins — the day is non-working, half-day never applies.
        $r = $this->reconcile($this->month('holiday', 'H'), 0.5);
        $this->assertSame(21.0, $r['expectedWork'], 'holiday removes the day entirely');
        $this->assertSame(0.0, $r['daysAbsent']);
    }

    public function test_L_calendar_half_day_plus_special_working_day(): void
    {
        // SWD wins — a full working day, half-day never applies.
        $r = $this->reconcile($this->month('specialWorkingDay', 'P'), 0.5);
        $this->assertSame(22.0, $r['expectedWork'], 'SWD restores a full working day');
    }

    // ══ M–N · SCOPING ══════════════════════════════════════════════════

    public function test_M_department_specific_calendar_half_day(): void
    {
        $deptA = $this->reconcile($this->month('halfDay', 'P'), 0.5);
        $deptB = $this->reconcile($this->month('working', 'P'), 0.5);
        $this->assertSame(21.5, $deptA['expectedWork']);
        $this->assertSame(22.0, $deptB['expectedWork'], 'a dept without the half-day is unaffected');
        $this->assertSame(44000.00, $this->net($deptA['expectedWork'], $deptA['daysAbsent']));
        $this->assertSame(44000.00, $this->net($deptB['expectedWork'], $deptB['daysAbsent']));
    }

    public function test_N_staff_specific_applicability(): void
    {
        // Holiday_service scopes by (audience, department, staffId), so an
        // excepted staff member simply never receives the half-day.
        $applies = $this->reconcile($this->month('halfDay', 'A'), 0.5);
        $excepted = $this->reconcile($this->month('working', 'A'), 0.5);
        $this->assertSame(0.5, $applies['daysAbsent']);
        $this->assertSame(1.0, $excepted['daysAbsent'], 'excepted staff keep the full-day expectation');
    }

    // ══ O–R · FLAG + HISTORICAL SAFETY ═════════════════════════════════

    public function test_Q_flag_off_reproduces_the_original_behaviour_exactly(): void
    {
        $w = $this->weight(false, '2026-09-01', '2026-09-01');
        $this->assertSame(1.0, $w);
        $r = $this->reconcile($this->month('halfDay', 'A'), $w);
        $this->assertSame(22.0, $r['expectedWork'], 'flag OFF → half-day counts a full day');
        $this->assertSame(1.0, $r['daysAbsent']);
        $this->assertSame(42000.00, $this->net($r['expectedWork'], $r['daysAbsent']),
            'byte-identical to pre-feature payroll');
    }

    public function test_R_flag_on_after_effective_date_applies_the_new_model(): void
    {
        $w = $this->weight(true, '2026-09-01', '2026-09-01');
        $this->assertSame(0.5, $w);
        $r = $this->reconcile($this->month('halfDay', 'A'), $w);
        $this->assertSame(21.5, $r['expectedWork']);
        $this->assertSame(42976.74, $this->net($r['expectedWork'], $r['daysAbsent']));
    }

    public function test_O_historical_period_BEFORE_effective_date_is_unchanged(): void
    {
        $w = $this->weight(true, '2026-09-01', '2026-08-01');
        $this->assertSame(1.0, $w, 'a period before the boundary must resolve the OLD weight');
        $r = $this->reconcile($this->month('halfDay', 'A'), $w);
        $this->assertSame(42000.00, $this->net($r['expectedWork'], $r['daysAbsent']),
            'historical payroll must not move');
    }

    public function test_P_period_ON_the_effective_date_applies_the_new_model(): void
    {
        $this->assertSame(0.5, $this->weight(true, '2026-09-01', '2026-09-01'), 'boundary is inclusive');
    }

    public function test_S4_enabled_without_an_effective_date_stays_off(): void
    {
        // An explicit boundary is required, never assumed.
        $this->assertSame(1.0, $this->weight(true, '', '2026-09-01'));
        $this->assertSame(1.0, $this->weight(true, 'not-a-date', '2026-09-01'));
    }

    // ══ S · REGENERATION SYMMETRY ══════════════════════════════════════

    public function test_S_regeneration_reproduces_identical_numbers(): void
    {
        // Both engines run the same reconciliation; a payslip must not change
        // value merely because it was regenerated.
        $days = $this->month('halfDay', 'M');
        $gen = $this->reconcile($days, 0.5, [], [15 => 0.5]);
        $reg = $this->reconcile($days, 0.5, [], [15 => 0.5]);
        $this->assertEquals($gen, $reg);
        $this->assertSame(
            $this->net($gen['expectedWork'], $gen['daysAbsent']),
            $this->net($reg['expectedWork'], $reg['daysAbsent'])
        );
    }

    // ══ T · LEAVE BALANCE CONSUMPTION ══════════════════════════════════

    /** Mirrors _chargeable_days_single_department()'s per-day weighting. */
    private function chargeableLeaveDays(array $reasons, float $halfDayWeight): float
    {
        $c = 0.0;
        foreach ($reasons as $r) {
            if (in_array($r, ['weeklyOff', 'holiday', 'nthWeekday'], true)) continue;
            $c += $r === 'halfDay' ? $halfDayWeight : 1.0;
        }
        return $c;
    }

    public function test_T_leave_balance_consumption_respects_expected_work(): void
    {
        $this->assertSame(3.0, $this->chargeableLeaveDays(['working','working','working'], 0.5));
        $this->assertSame(2.5, $this->chargeableLeaveDays(['working','halfDay','working'], 0.5),
            'S2: the half-day consumes 0.5');
        $this->assertSame(2.0, $this->chargeableLeaveDays(['working','holiday','working'], 0.5),
            'holidays are never chargeable');
        $this->assertSame(3.0, $this->chargeableLeaveDays(['working','halfDay','working'], 1.0),
            'flag OFF → unchanged consumption');
    }

    // ══ U · LEAVE CANCELLATION ═════════════════════════════════════════

    public function test_U_cancelling_leave_restores_the_uncovered_charge(): void
    {
        $days = $this->month('halfDay', 'M');
        $covered = $this->reconcile($days, 0.5, [], [15 => 0.5]);
        $this->assertSame(0.0, $covered['daysAbsent']);

        // Coverage is re-derived every run, never cached — cancelling the
        // leave removes it and the uncovered quarter reappears.
        $cancelled = $this->reconcile($days, 0.5, [], []);
        $this->assertSame(0.25, $cancelled['daysAbsent'], 'no stale coverage after cancellation');
    }

    // ══ V · NO DOUBLE COUNTING ═════════════════════════════════════════

    public function test_V_overlapping_coverage_never_double_counts(): void
    {
        // Defensive: both sets naming the same day charges once, not twice.
        $r = $this->reconcile($this->month('halfDay', 'M'), 0.5, [15 => 0.5], [15 => 0.5]);
        $this->assertSame(0.0, $r['daysAbsent']);
    }

    public function test_V_deduction_can_never_exceed_expected_work(): void
    {
        $this->assertSame(0.00, $this->net(21.5, 30.0), 'clamped — net can never go negative');
    }

    // ══ CONCEPT SEPARATION ═════════════════════════════════════════════

    public function test_calendar_half_day_never_becomes_attendance_half_day(): void
    {
        // A declared half-day worked normally produces NO 'M' mark.
        $r = $this->reconcile($this->month('halfDay', 'P'), 0.5);
        $this->assertSame(0, $r['halfDayMarks']);
    }

    public function test_attendance_half_day_never_becomes_leave(): void
    {
        $r = $this->reconcile($this->month('halfDay', 'M'), 0.5);
        $this->assertSame(0, $r['paidLeaveDays']);
        $this->assertSame(0, $r['unpaidLeaveDays']);
    }

    public function test_leave_coverage_never_fabricates_an_attendance_mark(): void
    {
        $r = $this->reconcile($this->month('halfDay', 'P'), 0.5, [], [15 => 0.5]);
        $this->assertSame(0, $r['halfDayMarks']);
    }

    // ══ FRACTIONAL MULTI-DAY LWP ═══════════════════════════════════════
    //
    // A 3-day leave against a 1.5-day balance leaves lwp_days = 1.5. Paid
    // days are consumed first, so the LWP sits at the END of the span: the
    // last day fully unpaid, the day before it half unpaid. Before the fix
    // an (int) cast flattened 1.5 to 1 and only one day was charged.

    /** Mirrors _process_leave_for_payroll()'s span construction. */
    private function lwpSpan(float $lwpDays, array $spanDates, string $lastDate): array
    {
        if ($lwpDays <= 0.0) return [];
        $fullDays = (int) floor($lwpDays);
        $fraction = round($lwpDays - $fullDays, 2);
        $spanLen  = (int) ceil($lwpDays);
        $affected = array_slice($spanDates, -$spanLen);
        $set = [];
        foreach ($affected as $i => $d) {
            $set[$d] = ($i === 0 && $fraction > 0.0) ? $fraction : true;
        }
        return $set;
    }

    public function test_fractional_multi_day_lwp_spans_the_correct_number_of_days(): void
    {
        $set = $this->lwpSpan(1.5, [10, 11, 12], 12);
        $this->assertSame([11 => 0.5, 12 => true], $set,
            '1.5 days of LWP = the last day full, the day before it half');
    }

    public function test_fractional_multi_day_lwp_charges_the_full_amount(): void
    {
        $days = [];
        for ($d = 1; $d <= 22; $d++) $days[$d] = ['reason' => 'working', 'mark' => 'A'];
        $set = $this->lwpSpan(1.5, [10, 11, 12], 12);

        $r = $this->reconcile($days, 0.5, $set);
        // Day 12 is fully covered (0 absent). Day 11 is half-covered, so 0.5
        // remains uncovered. The other 20 days (1–10, 13–22) are plain
        // absences at 1.0 each.
        $this->assertSame(20.5, $r['daysAbsent'],
            '20 uncovered days + 0.5 remaining on the partially-covered day');
    }

    public function test_whole_number_lwp_is_unaffected_by_the_fractional_path(): void
    {
        $set = $this->lwpSpan(2.0, [10, 11, 12], 12);
        $this->assertSame([11 => true, 12 => true], $set, 'no partial day when the total is whole');
    }

    public function test_partial_lwp_coverage_is_read_not_assumed_to_be_half(): void
    {
        // The guard reads the actual stored portion rather than hard-coding
        // 0.5, so an unusual fraction still reconciles correctly.
        $days = [5 => ['reason' => 'working', 'mark' => 'A']];
        $r = $this->reconcile($days, 0.5, [5 => 0.25]);
        $this->assertSame(0.75, $r['daysAbsent'], '1.0 expected - 0.25 covered');
    }

    public function test_partial_lwp_on_a_calendar_half_day_is_fully_covered(): void
    {
        // 0.5 of LWP against a 0.5 expectation leaves nothing uncovered (S2).
        $days = [5 => ['reason' => 'halfDay', 'mark' => 'A']];
        $r = $this->reconcile($days, 0.5, [5 => 0.5]);
        $this->assertSame(0.0, $r['daysAbsent']);
    }
}
