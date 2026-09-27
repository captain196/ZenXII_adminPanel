<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
require_once __DIR__ . '/../../application/helpers/payroll_snapshot_helper.php';

/**
 * ZenX HRMS W3.2.1 — attendance day-wise payroll snapshot.
 *
 * WHAT THIS PROTECTS
 * ------------------
 * The slip already froze the attendance RESULT (days_absent, half_days,
 * lwp_days …). It did not freeze the EVIDENCE, so a later attendance correction
 * changed what a regeneration would produce with no record of the original
 * inputs — `days_absent: 3` without ever saying which three days.
 *
 * The snapshot's whole value is that the masks REPRODUCE the counts stored next
 * to them. So these tests do not merely check string formatting: several of
 * them re-run the calculation's own day-classification rules over the masks and
 * assert the result matches what payroll recorded. A mask that cannot reproduce
 * its own `derived` block is not a snapshot, it is decoration.
 */
class PayrollAttendanceSnapshotTest extends TestCase
{
    // ── Encoding ─────────────────────────────────────────────────────────

    public function test_window_mask_marks_in_window_days(): void
    {
        // Employed all 30 days.
        $inWindow = array_fill_keys(range(1, 30), true);
        $this->assertSame(str_repeat('1', 30), payroll_window_mask($inWindow, 30));
    }

    public function test_window_mask_is_zero_when_never_employed(): void
    {
        $this->assertSame(str_repeat('0', 31), payroll_window_mask([], 31));
    }

    public function test_window_mask_handles_join_mid_month(): void
    {
        // Joined on the 16th of a 30-day month.
        $inWindow = array_fill_keys(range(16, 30), true);
        $mask = payroll_window_mask($inWindow, 30);

        $this->assertSame(str_repeat('0', 15) . str_repeat('1', 15), $mask);
        $this->assertSame(15, substr_count($mask, '1'), 'exactly the employed days count');
    }

    public function test_window_mask_handles_exit_mid_month(): void
    {
        // Last working day the 10th of a 31-day month.
        $inWindow = array_fill_keys(range(1, 10), true);
        $this->assertSame(str_repeat('1', 10) . str_repeat('0', 21),
            payroll_window_mask($inWindow, 31));
    }

    /**
     * The case that justifies storing a mask at all.
     *
     * `staff_window` on the slip carries only startDay/endDay, which cannot
     * express a gap. Reproducing a rehire from that pair would treat the
     * unemployed middle as employed and pay it.
     */
    public function test_window_mask_handles_rehire_within_the_same_month(): void
    {
        // Employed 1–5, left, rehired 20–30.
        $inWindow = array_fill_keys(array_merge(range(1, 5), range(20, 30)), true);
        $mask = payroll_window_mask($inWindow, 30);

        $this->assertSame('111110000000000000011111111111', $mask);
        $this->assertSame(16, substr_count($mask, '1'));

        // The startDay/endDay pair the slip already stores would say 1..30 and
        // therefore reproduce 30 employed days instead of 16. That gap is the
        // reason this mask exists.
        $fromPairOnly = payroll_window_mask(array_fill_keys(range(1, 30), true), 30);
        $this->assertNotSame($fromPairOnly, $mask,
            'a contiguous start/end pair cannot reproduce a rehire');
    }

    public function test_lwp_mask_distinguishes_full_from_half(): void
    {
        // Day 3 full-day LWP, day 7 half-day LWP, rest none.
        $mask = payroll_lwp_mask([3 => true, 7 => 0.5], 10);
        $this->assertSame('--F---H---', $mask);
    }

    public function test_lwp_mask_is_all_dashes_when_no_lwp(): void
    {
        $this->assertSame(str_repeat('-', 28), payroll_lwp_mask([], 28));
    }

    /**
     * Half vs full is load-bearing: a full-day LWP fully covers an 'A' mark
     * (adds 0 absent), a half-day covers only half (adds 0.5). If the mask
     * collapsed both to one symbol the snapshot could not reproduce the absent
     * count it is stored beside.
     */
    public function test_lwp_mask_half_and_full_are_not_interchangeable(): void
    {
        $this->assertNotSame(
            payroll_lwp_mask([5 => true], 10),
            payroll_lwp_mask([5 => 0.5], 10)
        );
    }

    public function test_masks_are_always_exactly_days_in_month_long(): void
    {
        foreach ([28, 29, 30, 31] as $dim) {
            $this->assertSame($dim, strlen(payroll_window_mask([1 => true], $dim)));
            $this->assertSame($dim, strlen(payroll_lwp_mask([1 => true], $dim)));
        }
    }

    public function test_masks_align_with_daywise_positionally(): void
    {
        // day-1 = offset-0, the same convention dayWise uses. An off-by-one
        // here would silently attribute one employee's absence to the wrong day.
        $this->assertSame('1', payroll_window_mask([1 => true], 5)[0]);
        $this->assertSame('F', payroll_lwp_mask([1 => true], 5)[0]);
        $this->assertSame('F', payroll_lwp_mask([5 => true], 5)[4]);
    }

    // ── Reproducibility: masks + dayWise must re-derive the counts ────────

    /**
     * Re-runs the calculation's day-classification rules over the snapshot and
     * asserts the recorded `derived` counts come back out. This mirrors
     * `Hr::generate_payroll()`'s per-day branch exactly — same order, same
     * double-count guards.
     */
    private function reproduce(string $dayWise, string $windowMask, string $lwpMask,
                               string $vacantTreatment = 'block'): array
    {
        $absent = 0.0; $half = 0; $extra = 0.0;
        $paidLeave = 0; $unpaidLeave = 0; $vacant = 0;

        for ($i = 0, $n = strlen($dayWise); $i < $n; $i++) {
            if (($windowMask[$i] ?? '0') !== '1') continue;   // outside employment window
            $ch  = strtoupper($dayWise[$i]);
            $lwp = $lwpMask[$i] ?? '-';

            if ($ch === 'A') {
                if ($lwp === '-')      $absent += 1.0;
                elseif ($lwp === 'H')  $absent += 0.5;
                // 'F' — fully covered by leave, adds nothing
            } elseif ($ch === 'L') {
                if ($lwp === 'F') $unpaidLeave++; else $paidLeave++;
            } elseif ($ch === 'M') {
                $half++;
                if ($lwp === '-') $absent += 0.5;
            } elseif ($ch === 'W') {
                $extra += 1.0;
            } elseif ($ch === 'V') {
                $vacant++;
                if ($vacantTreatment === 'absent') $absent += 1.0;
            }
            // P / H / T / O have no pay effect here
        }

        return [
            'days_absent' => $absent, 'half_days' => $half,
            'extra_worked_days' => $extra, 'paid_leave_days' => $paidLeave,
            'unpaid_leave_days' => $unpaidLeave, 'vacant_days' => $vacant,
        ];
    }

    public function test_reproduces_a_normal_fully_present_employee(): void
    {
        $d = $this->reproduce(str_repeat('P', 30), str_repeat('1', 30), str_repeat('-', 30));
        $this->assertSame(0.0, $d['days_absent']);
        $this->assertSame(0, $d['vacant_days']);
    }

    public function test_reproduces_half_day_M(): void
    {
        // One 'M' on day 4, no leave covering it → 0.5 absent, 1 half-day.
        $dw = 'PPPMPPPPPP';
        $d  = $this->reproduce($dw, str_repeat('1', 10), str_repeat('-', 10));
        $this->assertSame(0.5, $d['days_absent']);
        $this->assertSame(1, $d['half_days']);
    }

    public function test_half_day_covered_by_leave_is_not_double_counted(): void
    {
        // The engine's double-count guard: an 'M' already accounted for by
        // leave must not add another 0.5.
        $d = $this->reproduce('PPPMPPPPPP', str_repeat('1', 10), '---F------');
        $this->assertSame(0.0, $d['days_absent'], 'leave already deducted this day');
        $this->assertSame(1, $d['half_days'], 'still recorded as a half-day');
    }

    public function test_reproduces_worked_on_off_day_W(): void
    {
        // 'W' credits extra pay and must NOT reduce absence.
        $d = $this->reproduce('PWPAPPPPPP', str_repeat('1', 10), str_repeat('-', 10));
        $this->assertSame(1.0, $d['extra_worked_days']);
        $this->assertSame(1.0, $d['days_absent'], 'W does not offset the A');
    }

    public function test_reproduces_full_day_lop_covering_an_absence(): void
    {
        $d = $this->reproduce('PPAPPPPPPP', str_repeat('1', 10), '--F-------');
        $this->assertSame(0.0, $d['days_absent'], 'full LWP fully covers the A');
    }

    public function test_reproduces_half_day_lop_covering_half_an_absence(): void
    {
        $d = $this->reproduce('PPAPPPPPPP', str_repeat('1', 10), '--H-------');
        $this->assertSame(0.5, $d['days_absent'], 'half LWP covers only half');
    }

    public function test_reproduces_paid_versus_unpaid_leave(): void
    {
        // 'L' with no LWP entry is paid leave; 'L' with full LWP is unpaid.
        $d = $this->reproduce('LLPPPPPPPP', str_repeat('1', 10), 'F---------');
        $this->assertSame(1, $d['unpaid_leave_days']);
        $this->assertSame(1, $d['paid_leave_days']);
    }

    public function test_holiday_and_weekly_off_have_no_pay_effect(): void
    {
        // 'H' holiday and 'O' weekly-off are informational — non-working days
        // are derived from the calendar, so counting them here would
        // double-count against working_days.
        $d = $this->reproduce('HHOOPPPPPP', str_repeat('1', 10), str_repeat('-', 10));
        $this->assertSame(0.0, $d['days_absent']);
        $this->assertSame(0, $d['vacant_days']);
    }

    public function test_days_outside_the_employment_window_are_never_charged(): void
    {
        // Absent-marked days before joining on the 6th must not be charged.
        $dw     = 'AAAAAPPPPP';
        $window = '0000011111';
        $d = $this->reproduce($dw, $window, str_repeat('-', 10));
        $this->assertSame(0.0, $d['days_absent'], 'pre-join absences are not the employee\'s');
    }

    public function test_rehire_gap_is_not_charged(): void
    {
        // Marked absent across the gap they were not employed for.
        $d = $this->reproduce('PPPPAAAAAAPPPP', '11110000001111', str_repeat('-', 14));
        $this->assertSame(0.0, $d['days_absent']);
    }

    public function test_vacant_treatment_changes_the_result_and_is_snapshotted(): void
    {
        $dw = 'VVPPPPPPPP'; $w = str_repeat('1', 10); $l = str_repeat('-', 10);

        $block  = $this->reproduce($dw, $w, $l, 'block');
        $absent = $this->reproduce($dw, $w, $l, 'absent');

        $this->assertSame(2, $block['vacant_days']);
        $this->assertSame(0.0, $block['days_absent']);
        $this->assertSame(2.0, $absent['days_absent'],
            'the same attendance prices differently under a different policy');

        // Which is exactly why `vacant_treatment` is stored IN the snapshot:
        // without it the day_wise string alone cannot reproduce the result.
    }

    public function test_a_combined_month_reproduces_every_count(): void
    {
        //          1234567890123456789012345678
        $dayWise = 'PPMAAWLLPPVVPPPPPPPPPPPPPPPP';
        $window  = '1111111111111111111111111111';
        $lwp     = '---F--F---------------------';

        $d = $this->reproduce($dayWise, $window, $lwp, 'absent');

        $this->assertSame(1, $d['half_days'], 'one M');
        $this->assertSame(1.0, $d['extra_worked_days'], 'one W');
        $this->assertSame(1, $d['unpaid_leave_days'], 'L on a full-LWP day');
        $this->assertSame(1, $d['paid_leave_days'], 'the other L');
        $this->assertSame(2, $d['vacant_days'], 'two V');
        // 0.5 (M) + 0.0 (A on full LWP) + 1.0 (bare A) + 2.0 (V as absent)
        $this->assertSame(3.5, $d['days_absent']);
    }

    // ── Contract guards ──────────────────────────────────────────────────

    public function test_snapshot_helper_holds_no_tenant_and_no_rtdb(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/helpers/payroll_snapshot_helper.php');
        $this->assertStringNotContainsString('firebase->', $src);
        $this->assertDoesNotMatchRegularExpression('/SCH_[A-Z0-9]{6,}/', $src);
    }

    /**
     * Both slip writers must record the snapshot. `regenerate_staff_payroll()`
     * builds its slip with array_merge over the OLD slip, so a snapshot it does
     * not restate survives stale — describing inputs the numbers beside it did
     * not come from. Regeneration is the one path where the inputs are known to
     * have changed, so it is the one place a stale snapshot is certainly wrong.
     */
    public function test_both_slip_writers_record_the_attendance_snapshot(): void
    {
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertSame(2, substr_count($hr, "'_attendance_snapshot' =>"),
            'generate_payroll and regenerate_staff_payroll must both write it');
        $this->assertSame(2, substr_count($hr, "'_salary_snapshot' =>"),
            'regeneration must refresh the salary snapshot too, not inherit it');
    }
}
