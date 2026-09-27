<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__); // satisfy the file's direct-access guard
require_once __DIR__ . '/../../application/libraries/Leave_accrual_service.php';

/**
 * Leave-accrual divergence fix (2026-09-19).
 *
 * The monthly-accrual engine credited a fixed `round(($daysPerYear/12)*2)/2`
 * every month, which summed to 12× that and did NOT equal the school's
 * configured annual: 20 days/yr accrued only 18 (a 2-day LOSS) and 15/yr
 * accrued 18 (a 3-day GIFT). `Hr::_accrual_installment()` now distributes the
 * exact annual across the accrual window, keeping 0.5-day granularity and
 * trueing the final month up so any full window telescopes to the annual.
 *
 * These tests EXECUTE the real shipped helper (a pure static) through the
 * PayrollEngine reflection harness — not a transcription of its arithmetic.
 *
 * LATENT: LEAVE_ACCRUAL_ENABLED is off in every tenant, so this corrects a
 * dormant engine and moves no employee's balance. Wiring accrued-to-date into
 * apply_leave, and the per-school accrual toggle, are held for owner sign-off.
 */
final class LeaveAccrualDivergenceTest extends TestCase
{
    /** @return float sum of the per-month installments across a full window */
    private function windowSum(float $daysPerYear, int $window): float
    {
        $sum = 0.0;
        for ($k = 1; $k <= $window; $k++) {
            $sum += (float) Leave_accrual_service::installment($daysPerYear, $k, $window);
        }
        return round($sum, 9);
    }

    public function test_full_year_sums_to_exactly_the_configured_annual(): void
    {
        foreach ([6.0, 12.0, 15.0, 18.0, 20.0, 21.0, 24.0, 30.0] as $dpy) {
            $this->assertEqualsWithDelta(
                $dpy, $this->windowSum($dpy, 12), 1e-9,
                "a full 12-month accrual of {$dpy}/yr must sum to exactly {$dpy}");
        }
    }

    public function test_the_headline_divergence_is_gone(): void
    {
        // The two cases the audit named: both used to land on 18.
        $this->assertEqualsWithDelta(20.0, $this->windowSum(20.0, 12), 1e-9,
            '20/yr must now accrue 20, not the old 18 (a recovered 2-day loss)');
        $this->assertEqualsWithDelta(15.0, $this->windowSum(15.0, 12), 1e-9,
            '15/yr must now accrue 15, not the old 18 (the phantom 3-day gift is gone)');
    }

    public function test_installments_are_half_day_granular_for_integer_annuals(): void
    {
        foreach ([12.0, 15.0, 20.0, 21.0, 24.0] as $dpy) {
            for ($k = 1; $k <= 12; $k++) {
                $amt = (float) Leave_accrual_service::installment($dpy, $k, 12);
                $twice = $amt * 2;
                $this->assertEqualsWithDelta(round($twice), $twice, 1e-9,
                    "installment {$k} of {$dpy}/yr must be a 0.5-day multiple (got {$amt})");
            }
        }
    }

    public function test_no_negative_installment_and_cumulative_is_monotonic(): void
    {
        foreach ([12.0, 15.0, 20.0, 24.0] as $dpy) {
            $cum = 0.0;
            for ($k = 1; $k <= 12; $k++) {
                $amt = (float) Leave_accrual_service::installment($dpy, $k, 12);
                $this->assertGreaterThanOrEqual(-1e-9, $amt,
                    "no month may deduct (dpy {$dpy}, month {$k})");
                $cum += $amt;
                $this->assertLessThanOrEqual($dpy + 1e-9, $cum,
                    "cumulative may never exceed the annual (dpy {$dpy}, month {$k})");
            }
        }
    }

    public function test_mid_year_window_still_reaches_the_annual_by_its_final_month(): void
    {
        // A policy version effective in March gives a 10-month window (Mar–Dec).
        $this->assertEqualsWithDelta(20.0, $this->windowSum(20.0, 10), 1e-9,
            'a 10-month window must still accrue the full annual by December');
        // A single-month window credits the whole annual at once (degenerate but exact).
        $this->assertEqualsWithDelta(18.0, $this->windowSum(18.0, 1), 1e-9,
            'a 1-month window credits the full annual');
    }

    public function test_out_of_range_ordinals_credit_nothing(): void
    {
        $this->assertSame(0.0, (float) Leave_accrual_service::installment(20.0, 0, 12),
            'ordinal 0 credits nothing');
        $this->assertSame(0.0, (float) Leave_accrual_service::installment(20.0, 13, 12),
            'an ordinal past the window credits nothing');
        $this->assertSame(0.0, (float) Leave_accrual_service::installment(20.0, -3, 12),
            'a negative ordinal credits nothing');
    }
}
