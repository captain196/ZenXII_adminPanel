<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Trail 2 (Work Schedule → Calendar → Attendance → Leave/LOP → Payroll),
 * Phase D, Finding #1, 2026-08-18.
 *
 * The ONE read-only "live view" overlay: takes a raw, possibly-unfinalized
 * `dayWise` string and rewrites past unmarked ('V') days to their resolved
 * state (weekly-off 'O', holiday 'H', or auto-absent 'A' when the tenant's
 * autoAbsent policy is on) — mirroring exactly what the nightly finaliser
 * will eventually persist. Today and future days are left untouched.
 *
 * Extracted verbatim from Staff_attendance::_overlay_daywise() (the staff
 * self-view's own overlay), which is now a thin wrapper around this class.
 * Attendance::_fetch_staff_attendance_fs() (the admin grid) now calls the
 * SAME method, so the two surfaces can no longer disagree about a date's
 * pre-finalization state — there is exactly one place this decision is
 * made. Nothing here recomputes what IS a weekly-off/holiday/SWD; that
 * remains Holiday_service's job. This class only decides how to REWRITE an
 * unmarked character once the caller already knows the day's calendar
 * facts.
 */
class Staff_attendance_overlay
{
    /**
     * @param Holiday_service $holidayService Already init()'d for this school/session.
     * @param string $dayWise         Raw dayWise string, one char per day of the month.
     * @param string $monthKey        'YYYY-MM'
     * @param bool   $autoAbsent      schools/{id}.attendancePolicy.autoAbsent (default true upstream)
     * @param string $todayISO        'YYYY-MM-DD' — days on/after this are left untouched
     * @param array  $nonWorkingDays  [day(int) => true] — THIS employee's resolved weekly-offs
     * @param string $staffId         For Holiday_service's per-staff exception lookup
     * @param string $department      For Holiday_service's department-scoped lookup
     * @return string The same-length dayWise string, past 'V'/'A' days rewritten where applicable.
     */
    public static function apply(
        Holiday_service $holidayService,
        string $dayWise,
        string $monthKey,
        bool $autoAbsent,
        string $todayISO,
        array $nonWorkingDays,
        string $staffId,
        string $department
    ): string {
        if ($dayWise === '') return $dayWise;

        $len = strlen($dayWise);
        for ($d = 1; $d <= $len; $d++) {
            $ch = strtoupper($dayWise[$d - 1]);
            // Only unmarked ('V') and previously auto-absented ('A') days are
            // eligible. Processing 'A' lets a holiday added to the Academic
            // Calendar AFTER a day was auto-absented retroactively show as 'H'.
            if ($ch !== 'V' && $ch !== 'A') continue;
            $dateISO = sprintf('%s-%02d', $monthKey, $d);
            if ($dateISO >= $todayISO) continue; // today + future untouched

            // Fail closed on a per-date lookup error: leave the day as-is
            // rather than flipping a possible holiday to Absent.
            try {
                $isHol = $holidayService->is_holiday($dateISO, 'staff', $department, $staffId);
                $isSwd = $holidayService->is_special_working_day($dateISO, 'staff', $department, $staffId);
            } catch (\Throwable $e) {
                continue;
            }

            if ($isSwd) {
                // Special Working Day overrides holiday/weekly-off: an
                // unmarked past day here is a genuine miss, not a rest day.
                if ($ch === 'V' && $autoAbsent) $dayWise[$d - 1] = 'A';
            } elseif ($isHol) {
                $dayWise[$d - 1] = 'H'; // V→H or retroactive A→H
            } elseif ($ch === 'V') {
                if (isset($nonWorkingDays[$d])) {
                    $dayWise[$d - 1] = 'O';
                } elseif ($autoAbsent) {
                    $dayWise[$d - 1] = 'A';
                }
                // else: autoAbsent OFF → leave the day Vacant (V)
            }
            // $ch === 'A' and not a holiday → leave the genuine Absent untouched.
        }
        return $dayWise;
    }
}
