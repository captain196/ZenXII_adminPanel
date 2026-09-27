<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Payroll attendance-snapshot encoding (ZenX HRMS W3.2.1).
 *
 * WHY A HELPER RATHER THAN METHODS ON Hr
 * --------------------------------------
 * These two functions decide whether a finalized payslip can be reproduced, so
 * they need real unit tests over the awkward cases — rehire, half-day LWP,
 * mid-month exit. `Hr.php` extends `MY_Controller` and cannot be loaded in the
 * PHPUnit bootstrap, so logic that lives only inside it is reachable by
 * source-literal assertions and nothing stronger. The same reasoning already
 * moved `hr_next_id()` out of `Hr::_next_id()` in W1.3, and this follows it.
 *
 * ENCODING
 * --------
 * Both produce positional strings indexed day-1 = offset-0, matching the
 * `dayWise` convention the attendance system already uses. That is deliberate:
 * the snapshot introduces no second encoding, and an operator who can read a
 * dayWise string can read these.
 *
 * These functions describe attendance; they do not compute it. The
 * authoritative Attendance system remains the only place attendance is decided.
 */

if (!function_exists('payroll_window_mask')) {
    /**
     * Employment-window mask.
     *
     *   '1' inside the employment window (countable)
     *   '0' pre-join or post-exit — the calculation skips these entirely
     *
     * A per-day mask rather than the `startDay`/`endDay` pair already on the
     * slip. That pair can only describe ONE contiguous window, and a rehire
     * inside a single month is not contiguous — reproducing from the pair would
     * quietly pay a rehired employee across the gap they were not employed.
     *
     * @param array $inWindow  day-of-month => truthy, as the calculation uses it
     */
    function payroll_window_mask(array $inWindow, int $daysInMonth): string
    {
        $mask = '';
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $mask .= isset($inWindow[$d]) ? '1' : '0';
        }
        return $mask;
    }
}

if (!function_exists('payroll_lwp_days_from_mask')) {
    /**
     * Decode an `lwp_mask` back into the day numbers it marks (W3.2.3).
     *
     * The inverse of `payroll_lwp_mask()`, and the reason the mask was worth
     * storing: the LOP review can now say "days 12, 13 and 27" instead of
     * "3 LOP days", which is the difference between an operator being able to
     * check a deduction and having to take it on trust.
     *
     * Returns ['full' => int[], 'half' => int[]]. An empty or absent mask
     * yields two empty lists — the honest answer for a slip generated before
     * the snapshot existed. It never guesses a day list from anything else.
     */
    function payroll_lwp_days_from_mask(?string $mask): array
    {
        $full = []; $half = [];
        if (!is_string($mask) || $mask === '') return ['full' => $full, 'half' => $half];

        for ($i = 0, $n = strlen($mask); $i < $n; $i++) {
            if ($mask[$i] === 'F')     $full[] = $i + 1;
            elseif ($mask[$i] === 'H') $half[] = $i + 1;
        }
        return ['full' => $full, 'half' => $half];
    }
}

if (!function_exists('payroll_lwp_mask')) {
    /**
     * Leave-without-pay mask.
     *
     *   '-' no LWP   'F' full-day LWP   'H' half-day LWP
     *
     * The half/full distinction is load-bearing, not cosmetic. The calculation
     * treats a full-day LWP as fully covering an 'A' mark (adds 0 absent) but a
     * half-day LWP as covering only half (adds 0.5). Collapsing both to a
     * boolean would leave the snapshot unable to reproduce the absent count
     * sitting next to it.
     *
     * @param array $lwpSet day-of-month => true (full) | 0.5 (half)
     */
    function payroll_lwp_mask(array $lwpSet, int $daysInMonth): string
    {
        $mask = '';
        for ($d = 1; $d <= $daysInMonth; $d++) {
            if (!isset($lwpSet[$d])) { $mask .= '-'; continue; }
            $mask .= ($lwpSet[$d] === 0.5) ? 'H' : 'F';
        }
        return $mask;
    }
}
