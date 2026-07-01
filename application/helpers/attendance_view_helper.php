<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * attendance_view_helper — PURE domain-data assembly for attendance read APIs.
 *
 * These functions transform raw Firestore documents into structured DOMAIN
 * data for the staff-attendance read endpoints (e.g. Staff_attendance::me).
 * They contain NO presentation/rendering logic, NO I/O, NO RTDB — each client
 * (Teacher app, Employee app, HR portal, Admin portal, Payroll, analytics)
 * renders the returned structures however it needs.
 *
 * Keeping these here (not in the controller) makes the API response contract
 * deterministic and unit-testable, while the controller stays orchestration-only.
 */

if (!function_exists('av_build_history')) {
    /**
     * Build a last-N-days attendance history from per-month dayWise strings.
     *
     * @param array  $monthDayWise ['YYYY-MM' => dayWiseString]  (P/A/L/H/T/V per day)
     * @param string $todayISO     YYYY-MM-DD (inclusive end of the window)
     * @param int    $limit        number of days (default 30)
     * @return array  ordered oldest→newest list of ['date'=>YYYY-MM-DD,'status'=>char]
     */
    function av_build_history(array $monthDayWise, string $todayISO, int $limit = 30): array
    {
        if ($limit < 1) return [];
        try { $cur = new DateTime($todayISO); }
        catch (\Throwable $e) { return []; }

        $cur->modify('-' . ($limit - 1) . ' days');
        $out = [];
        for ($i = 0; $i < $limit; $i++) {
            $d   = $cur->format('Y-m-d');
            $mk  = substr($d, 0, 7);
            $day = (int) $cur->format('j');
            $dw  = (string) ($monthDayWise[$mk] ?? '');
            $status = (strlen($dw) >= $day) ? strtoupper($dw[$day - 1]) : 'V';
            $out[] = ['date' => $d, 'status' => $status];
            $cur->modify('+1 day');
        }
        return $out;
    }
}

if (!function_exists('av_today_times')) {
    /**
     * Derive today's check-in / check-out timestamps from accepted punch docs.
     * check-in = earliest accepted IN; check-out = latest accepted OUT.
     *
     * @param array $punches list of attendancePunches docs (already today-scoped)
     * @return array ['checkInAt'=>?string, 'checkOutAt'=>?string]  (ISO-8601 or null)
     */
    function av_today_times(array $punches): array
    {
        $inAt = null; $outAt = null;
        foreach ($punches as $p) {
            if (!is_array($p)) continue;
            if (($p['outcome'] ?? '') !== 'accepted') continue;
            // Prefer the canonical server ISO timestamp.
            $ts = (string) ($p['createdAt'] ?? $p['serverTimestamp'] ?? $p['serverTime'] ?? '');
            if ($ts === '') continue;
            $dir = (string) ($p['direction'] ?? 'in');
            if ($dir === 'in') {
                if ($inAt === null || $ts < $inAt) $inAt = $ts;
            } elseif ($dir === 'out') {
                if ($outAt === null || $ts > $outAt) $outAt = $ts;
            }
        }
        return ['checkInAt' => $inAt, 'checkOutAt' => $outAt];
    }
}
