<?php
defined('BASEPATH') or exit('No direct script access allowed');

/*
 * Self-contained dependency: the pure geofence math helper. Required
 * directly (not via CI's helper loader) so this library is usable in
 * isolated unit tests without bootstrapping the framework.
 */
if (!function_exists('gf_haversine')) {
    require_once __DIR__ . '/../helpers/geofence_helper.php';
}

/**
 * Attendance_policy — pure business-rule engine for the Attendance Policy
 * Framework (Phase 2). GPS is the first concrete method; biometric / QR /
 * RFID / face / manual plug into the SAME evaluate() pipeline later.
 *
 * STRICT BOUNDARIES (by design)
 * -----------------------------
 *   - NO Firestore. NO RTDB. NO HTTP. NO CodeIgniter session/auth.
 *   - NO database reads or writes. NO get_instance() for I/O.
 *   - NO knowledge of Android / UI / persistence.
 *   - Every input (policy, coordinates, server time, current day state,
 *     holiday flag, shift) is PASSED IN. The library only evaluates and
 *     returns a structured decision. Persistence + orchestration stay in
 *     the controller; the canonical write stays in Staff_attendance_writer.
 *
 * This keeps the engine deterministic, unit-testable, and free of any
 * infrastructure coupling — Firestore remains the sole source of truth,
 * owned by the controller layer, never by this class.
 *
 * MAIN API
 * --------
 *   evaluate(array $policy, array $request, array $context): array
 *
 * The returned decision tells the controller exactly what to do:
 *   [
 *     'allowed'        => bool,    // may this punch be recorded at all?
 *     'reason'         => string,  // '' when allowed; else a stable reason code
 *     'mark'           => ?string, // 'P' | 'T' for a status-setting IN; null otherwise
 *     'setsStatus'     => bool,    // true ONLY when the controller should call
 *                                  //   Staff_attendance_writer->markSingleDay()
 *     'lateMinutes'    => int,     // minutes past the late threshold (0 when on time)
 *     'distanceMeters' => ?float,  // haversine distance (for audit); null if uncomputable
 *     'shiftId'        => string,  // resolved shift (Phase 1: 'default')
 *     'direction'      => string,  // 'in' | 'out'
 *     'method'         => string,  // 'gps'
 *   ]
 *
 * Stable reason codes (consumed by controller → HTTP code + audit):
 *   invalid_coords, invalid_direction, method_disabled, gps_disabled,
 *   mock_location, poor_accuracy, outside_geofence, on_leave, on_holiday,
 *   too_early, window_closed, already_checked_in (allowed but no status set).
 */
class Attendance_policy
{
    /** Allowed dayWise status chars (mirrors Staff_attendance_writer::ALLOWED_STATUS). */
    const STATUS_PRESENT = 'P';
    const STATUS_LATE    = 'T';
    const STATUS_LEAVE   = 'L';
    const STATUS_HOLIDAY = 'H';
    const STATUS_ABSENT  = 'A';
    const STATUS_VACANT  = 'V';
    const STATUS_HALF     = 'M';   // half-day (worked ≥ halfDayHours but < fullDayHours)
    const STATUS_WEEKLYOFF = 'O';  // scheduled weekly-off day
    const STATUS_EXTRA    = 'W';   // worked on a rest day (weekly-off / holiday) → extra pay

    /** Server defaults applied when a policy omits a field (fail-safe, conservative). */
    const DEFAULT_MAX_ACCURACY_M   = 100.0;
    const DEFAULT_GRACE_MIN        = 0;
    const DEFAULT_LATE_THRESHOLD   = '09:00';
    const DEFAULT_EARLIEST_CHECKIN = '00:00';
    const DEFAULT_LATEST_CHECKIN   = '23:59';
    const DEFAULT_FULL_DAY_HOURS   = 8.0;
    const DEFAULT_HALF_DAY_HOURS   = 4.0;
    const DEFAULT_BREAK_MIN        = 0;

    /**
     * Evaluate a punch request against the school's attendance policy.
     * Pure function — no side effects.
     *
     * @param array $policy  schools/{id}.attendancePolicy map (passed in by controller)
     * @param array $request ['method','direction','lat','lng','accuracy','mock']
     * @param array $context ['serverTime'(Y-m-d H:i:s, school tz),'shiftId',
     *                         'currentDayMark'(P/A/L/H/T/V),'isHoliday'(bool)]
     * @return array decision (see class docblock)
     */
    public function evaluate(array $policy, array $request, array $context): array
    {
        $direction = (string) ($request['direction'] ?? 'in');
        $method    = (string) ($request['method'] ?? 'gps');
        $shiftId   = (string) ($context['shiftId'] ?? 'default');

        // Base decision skeleton — every early-return reuses this via _reject/_allow.
        $base = [
            'allowed'        => false,
            'reason'         => '',
            'mark'           => null,
            'setsStatus'     => false,
            'lateMinutes'    => 0,
            'distanceMeters' => null,
            'shiftId'        => $shiftId,
            'direction'      => $direction,
            'method'         => $method,
        ];

        // ── Gate 0: structural validity ──────────────────────────────
        if ($direction !== 'in' && $direction !== 'out') {
            return $this->_reject($base, 'invalid_direction');
        }
        $lat = $request['lat'] ?? null;
        $lng = $request['lng'] ?? null;
        if (!gf_valid_coord($lat, $lng)) {
            return $this->_reject($base, 'invalid_coords');
        }
        $lat = (float) $lat;
        $lng = (float) $lng;

        // ── Gate 1: method enabled for this school ───────────────────
        $enabled = isset($policy['enabledMethods']) && is_array($policy['enabledMethods'])
            ? $policy['enabledMethods'] : [];
        if (!in_array($method, $enabled, true)) {
            return $this->_reject($base, 'method_disabled');
        }

        // ── Gate 2: geofence configured + active ─────────────────────
        $gps      = is_array($policy['gps'] ?? null) ? $policy['gps'] : [];
        $geofence = is_array($gps['geofence'] ?? null) ? $gps['geofence'] : [];
        $active   = !empty($geofence['active']);
        $centerLat = $geofence['centerLat'] ?? null;
        $centerLng = $geofence['centerLng'] ?? null;
        $radius    = (float) ($geofence['radius'] ?? 0);
        if (!$active || !gf_valid_coord($centerLat, $centerLng) || $radius <= 0) {
            return $this->_reject($base, 'gps_disabled');
        }

        // Distance is computed once and carried into every subsequent
        // outcome (including rejections) for the audit trail.
        $distance = gf_haversine($lat, $lng, (float) $centerLat, (float) $centerLng);
        $base['distanceMeters'] = is_finite($distance) ? round($distance, 1) : null;

        // ── Gate 3: mock-location rejection ──────────────────────────
        $allowMock = !empty($gps['allowMockLocation']);
        if (!empty($request['mock']) && !$allowMock) {
            return $this->_reject($base, 'mock_location');
        }

        // ── Gate 4: GPS accuracy ceiling ─────────────────────────────
        $maxAccuracy = (float) ($gps['maxAccuracyMeters'] ?? self::DEFAULT_MAX_ACCURACY_M);
        $accuracy    = (float) ($request['accuracy'] ?? -1.0);
        if (!gf_accuracy_acceptable($accuracy, $maxAccuracy)) {
            return $this->_reject($base, 'poor_accuracy');
        }

        // ── Gate 5: geofence membership ──────────────────────────────
        $tolerance = (float) ($gps['boundaryToleranceMeters'] ?? 0.0);
        if (!gf_within_radius($distance, $radius, $tolerance)) {
            return $this->_reject($base, 'outside_geofence');
        }

        // ── Gate 6: precedence — approved leave wins over any punch ──
        $currentMark = strtoupper((string) ($context['currentDayMark'] ?? self::STATUS_VACANT));
        if ($currentMark === self::STATUS_LEAVE) {
            return $this->_reject($base, 'on_leave');
        }

        // ── Gate 6b: rest day (weekly-off / holiday) → extra work ────
        // A rest day is not a normal attendance day. If the school allows
        // working on rest days, an on-campus punch is recorded as EXTRA (W)
        // — payroll pays it at the configured multiplier. Otherwise reject.
        $isHoliday   = !empty($context['isHoliday'])   || $currentMark === self::STATUS_HOLIDAY;
        $isWeeklyOff = !empty($context['isWeeklyOff']) || $currentMark === self::STATUS_WEEKLYOFF;
        if ($isHoliday || $isWeeklyOff) {
            if (empty($policy['allowWorkOnOff'])) {
                return $this->_reject($base, $isHoliday ? 'on_holiday' : 'on_weekly_off');
            }
            if ($currentMark === self::STATUS_EXTRA) {            // idempotent re-punch
                $d = $this->_allow($base, null, false);
                $d['reason'] = 'already_checked_in';
                return $d;
            }
            if ($direction === 'out') {                          // rest-day check-out: audit only
                return $this->_allow($base, null, false);
            }
            return $this->_allow($base, self::STATUS_EXTRA, true); // rest-day check-in stamps W
        }

        // ── Gate 7+: normal-day window + classification ──────────────
        $windows = $this->_resolve_windows($policy, $shiftId);
        $nowMin  = $this->_now_minutes($context);
        if ($nowMin === null) {
            // Without a usable server time we cannot classify safely → fail closed.
            return $this->_reject($base, 'window_closed');
        }

        if ($direction === 'out') {
            return $this->_evaluate_checkout($base, $windows, $nowMin, $context);
        }
        return $this->_evaluate_checkin($base, $windows, $nowMin, $currentMark);
    }

    /**
     * Convenience accessor: is a method enabled for this school's policy?
     * Pure; lets the controller short-circuit before building a full request.
     */
    public function is_method_enabled(array $policy, string $method): bool
    {
        $enabled = isset($policy['enabledMethods']) && is_array($policy['enabledMethods'])
            ? $policy['enabledMethods'] : [];
        return in_array($method, $enabled, true);
    }

    /* ─── private: check-in / check-out classification ───────────────── */

    private function _evaluate_checkin(array $base, array $w, int $nowMin, string $currentMark): array
    {
        // First-IN-of-day-wins: if today already carries a present/late
        // mark, this is a re-punch. Allowed (for check-in-time history) but
        // it does NOT re-set status — the controller treats it idempotently.
        if (in_array($currentMark, [self::STATUS_PRESENT, self::STATUS_LATE, self::STATUS_HALF, self::STATUS_EXTRA], true)) {
            $d = $this->_allow($base, null, false);
            $d['reason'] = 'already_checked_in';
            return $d;
        }

        // Precedence ladder: a deliberate Absent mark (admin/system authority)
        // outranks an automated GPS punch. Do NOT silently flip 'A' → present;
        // the staff must use the correction/regularization flow instead.
        if ($currentMark === self::STATUS_ABSENT) {
            return $this->_reject($base, 'already_marked');
        }

        // Optional hard latest-check-in cutoff — arriving after it means the day
        // cannot be marked present (empty in the shift = no cutoff).
        $latest = ($w['latestCheckIn'] !== null) ? $this->_to_minutes($w['latestCheckIn']) : null;
        if ($latest !== null && $nowMin > $latest) {
            return $this->_reject($base, 'window_closed');
        }

        // On-time vs Late — driven by the Work Schedule's shiftStart + grace
        // (single source; no separate late-threshold / earliest-check-in gate).
        $start = $this->_to_minutes($w['shiftStart']);
        $grace = (int) $w['gracePeriodMin'];
        if ($nowMin <= $start + $grace) {
            return $this->_allow($base, self::STATUS_PRESENT, true); // P, lateMinutes 0
        }
        $d = $this->_allow($base, self::STATUS_LATE, true);
        $d['lateMinutes'] = max(0, $nowMin - $start);
        return $d;
    }

    private function _evaluate_checkout(array $base, array $w, int $nowMin, array $context): array
    {
        // No check-out time gating — you may clock out at any time; the hours
        // worked decide the day (this is what makes early half-day checkouts work).

        // Worked-hours classification — ONLY when a schedule (full/half hours)
        // is configured AND today's check-in time is known. Downgrades the day
        // to Half-day (M) or short-day → Absent (A). A full day keeps the P/T
        // already set at check-in (audit-only here). Without a schedule the
        // check-out stays audit-only (legacy behaviour, fully back-compatible).
        $checkInMin = $context['checkInMinutes'] ?? null;
        $full = (int) ($w['fullDayMinutes'] ?? 0);
        $half = (int) ($w['halfDayMinutes'] ?? 0);
        if (!empty($w['hoursEnabled']) && is_int($checkInMin) && $full > 0 && $half > 0 && $nowMin > $checkInMin) {
            $worked = $nowMin - $checkInMin - (int) ($w['breakMinutes'] ?? 0);
            if ($worked < 0) $worked = 0;
            if ($worked >= $full) {
                return $this->_allow($base, null, false);              // full day — keep P/T
            }
            if ($worked >= $half) {
                $d = $this->_allow($base, self::STATUS_HALF, true);    // half-day
                $d['workedMinutes'] = $worked;
                return $d;
            }
            $d = $this->_allow($base, self::STATUS_ABSENT, true);      // below half → absent
            $d['workedMinutes'] = $worked;
            return $d;
        }
        return $this->_allow($base, null, false); // allowed, audit-only
    }

    /* ─── private: helpers ───────────────────────────────────────────── */

    private function _resolve_windows(array $policy, string $shiftId): array
    {
        $shifts = is_array($policy['shifts'] ?? null) ? $policy['shifts'] : [];
        $shift  = is_array($shifts[$shiftId] ?? null) ? $shifts[$shiftId]
                : (is_array($shifts['default'] ?? null) ? $shifts['default'] : []);
        $w     = is_array($shift['windows']  ?? null) ? $shift['windows']  : [];
        $sched = is_array($shift['schedule'] ?? null) ? $shift['schedule'] : [];

        // Work Schedule is the single source of truth; fall back to legacy
        // windows (older policies) so nothing breaks pre-migration.
        $shiftStart = (string) ($sched['shiftStart']    ?? $w['lateThreshold']  ?? self::DEFAULT_LATE_THRESHOLD);
        $grace      = (int)    ($sched['graceMinutes']  ?? $w['gracePeriodMin'] ?? self::DEFAULT_GRACE_MIN);
        $latestRaw  = (string) ($sched['latestCheckIn'] ?? $w['latestCheckIn']  ?? '');

        return [
            'shiftStart'      => $shiftStart,
            'gracePeriodMin'  => $grace,
            // Optional hard cutoff; '' or the sentinel 23:59 both mean "no cutoff".
            'latestCheckIn'   => ($latestRaw !== '' && $latestRaw !== '23:59') ? $latestRaw : null,
            // Work-schedule (hours model). hoursEnabled gates half-day/absent
            // classification so schools without a schedule keep legacy behaviour.
            'hoursEnabled'    => !empty($sched),
            'fullDayMinutes'  => (int) round(((float) ($sched['fullDayHours'] ?? self::DEFAULT_FULL_DAY_HOURS)) * 60),
            'halfDayMinutes'  => (int) round(((float) ($sched['halfDayHours'] ?? self::DEFAULT_HALF_DAY_HOURS)) * 60),
            'breakMinutes'    => (int) ($sched['breakMinutes'] ?? self::DEFAULT_BREAK_MIN),
        ];
    }

    /** Minutes since midnight for an 'HH:MM' string; clamps malformed input to 0. */
    private function _to_minutes(?string $hhmm): int
    {
        if (!is_string($hhmm) || !preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) {
            return 0;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h > 23) $h = 23;
        if ($min > 59) $min = 59;
        return $h * 60 + $min;
    }

    /**
     * Minutes-since-midnight from the passed server time. Accepts either a
     * 'Y-m-d H:i:s'/'H:i' string or a 'serverTimeHHMM' shortcut. The caller
     * is responsible for having already resolved the school timezone — this
     * library never calls date()/time() itself (keeps it deterministic).
     *
     * @return int|null minutes, or null if no usable time was supplied
     */
    private function _now_minutes(array $context): ?int
    {
        if (isset($context['serverTimeHHMM']) && is_string($context['serverTimeHHMM'])) {
            return $this->_to_minutes($context['serverTimeHHMM']);
        }
        $st = $context['serverTime'] ?? null;
        if (is_string($st) && preg_match('/(\d{1,2}):(\d{2})/', $st, $m)) {
            return $this->_to_minutes($m[1] . ':' . $m[2]);
        }
        return null;
    }

    private function _reject(array $base, string $reason): array
    {
        $base['allowed']    = false;
        $base['reason']     = $reason;
        $base['mark']       = null;
        $base['setsStatus'] = false;
        return $base;
    }

    private function _allow(array $base, ?string $mark, bool $setsStatus): array
    {
        $base['allowed']    = true;
        $base['reason']     = '';
        $base['mark']       = $mark;
        $base['setsStatus'] = $setsStatus;
        return $base;
    }
}
