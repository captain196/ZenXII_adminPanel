<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Attendance merge helper — the pure, side-effect-free decision at the heart
 * of the bulk grid save (Attendance::save_student_attendance / ATT-CAS-1).
 *
 * Kept as a free function (no Firestore, no locks, no holidays) so the merge
 * SEMANTICS can be executed and asserted directly, instead of only checked by
 * source-regression. Lock enforcement and holiday re-stamping are the caller's
 * responsibility (they need Firestore / calendar I/O).
 */

if (!function_exists('att_merge_month_marks')) {
    /**
     * Merge a client-submitted month string onto the current server string.
     *
     * Rules (deliberate, see ATT-CAS-1):
     *   - A 'V' (unmarked) position in the client string DEFERS to the current
     *     server value — a mark made on some other day after the admin loaded
     *     the grid is preserved, never clobbered.
     *   - A real mark (anything other than 'V') is authoritative for its day
     *     (last-writer-wins for that single day).
     *   - Only positions that actually change are reported, so the caller can
     *     do the minimum per-day convergence + lock checks.
     *
     * Both strings are truncated/padded to exactly $daysInMonth ('V' pad).
     *
     * @return array{merged:string, changed:int[]}  changed = 1-based day numbers
     */
    function att_merge_month_marks(string $curStr, string $clientStr, int $daysInMonth): array
    {
        if ($daysInMonth < 1) {
            return ['merged' => '', 'changed' => []];
        }
        $curStr    = str_pad(substr($curStr, 0, $daysInMonth), $daysInMonth, 'V');
        $clientStr = str_pad(substr($clientStr, 0, $daysInMonth), $daysInMonth, 'V');

        $merged  = $curStr;
        $changed = [];
        for ($d = 0; $d < $daysInMonth; $d++) {
            $cm = $clientStr[$d];
            // 'V' (unmarked) and 'H' (calendar-derived holiday) both DEFER to the
            // server value. 'H' is never a teacher-assignable status — accepting a
            // client 'H' would let a full-month grid re-save silently revert a real
            // server mark (e.g. an approved correction that placed 'P' on a
            // declared holiday). Genuine holidays are re-stamped afterwards by the
            // caller's enforce_holidays_on_string(), so deferring here is safe.
            if ($cm === 'V' || $cm === 'H') continue;   // keep server value
            if ($cm === $curStr[$d])        continue;   // no change on this day
            $merged[$d] = $cm;
            $changed[]  = $d + 1;
        }
        return ['merged' => $merged, 'changed' => $changed];
    }
}
