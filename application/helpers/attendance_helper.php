<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Attendance Helper — Central attendance engine.
 *
 * IMMUTABILITY RULE: Read path NEVER mutates data.
 *   - Holidays are enforced ONLY during SAVE (write path).
 *   - parse_attendance_string() reads exactly what's stored.
 *   - Summaries are computed from the stored string as-is.
 *
 * CONSISTENCY RULE: the canonical per-month summary is the Firestore
 *   `attendanceSummary` collection, converged by _syncDailyToFirestore →
 *   _applyDayToSummary / _syncStudentSummaryToFirestore on every write path.
 */

// ═══════════════════════════════════════════════════════════════════
//  HOLIDAY DETECTION (shared by save path + summary computation)
// ═══════════════════════════════════════════════════════════════════

/**
 * PURE: non-working days for a month = Sundays + injected holiday day-map.
 *
 * HC-3 (holiday convergence): this is the canonical, I/O-free utility. The
 * CALLER resolves holidays via Holiday_service (the single canonical source)
 * and injects them as [int dayOfMonth => name]; this function only adds the
 * Sunday computation and merges. No Firestore, no RTDB, no service lookup —
 * keeping attendance_helper a pure utility.
 *
 * @param array $holidayDayMap [int dayOfMonth => name] from Holiday_service::holidays_in_month()
 * @return array Map of day_number => reason (Sundays + holidays)
 */
function nw_days_from_holidays(array $holidayDayMap, int $monthNum, int $year): array
{
    $nonWorking = [];
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);
    for ($d = 1; $d <= $daysInMonth; $d++) {
        if ((int) date('w', mktime(0, 0, 0, $monthNum, $d, $year)) === 0) {
            $nonWorking[$d] = 'Sunday';
        }
    }
    foreach ($holidayDayMap as $day => $name) {
        $day = (int) $day;
        if ($day >= 1 && $day <= $daysInMonth) {
            $nonWorking[$day] = (is_string($name) && $name !== '') ? $name : 'Holiday';
        }
    }
    return $nonWorking;
}

/* HC-5: the legacy RTDB holiday readers get_non_working_days() and
   is_non_working_day() were REMOVED here. They read the retired RTDB stores
   (Config/Attendance/holidays, Events/Holidays/{year}) and were superseded by
   the pure nw_days_from_holidays() — fed by Holiday_service, the canonical
   reader over calendarEvents. Proven dead (no callers anywhere) before removal. */

// ═══════════════════════════════════════════════════════════════════
//  WRITE PATH: Holiday enforcement on attendance strings
// ═══════════════════════════════════════════════════════════════════

/**
 * Enforce holidays on an attendance string BEFORE saving.
 * This is the ONLY place holidays mutate data.
 */
function enforce_holidays_on_string(string $attStr, int $daysInMonth, array $nonWorking): string
{
    $attStr = str_pad($attStr, $daysInMonth, 'V');
    foreach ($nonWorking as $dayNum => $reason) {
        if ($dayNum >= 1 && $dayNum <= $daysInMonth) {
            $attStr[$dayNum - 1] = 'H';
        }
    }
    return $attStr;
}

// ═══════════════════════════════════════════════════════════════════
//  READ PATH: Parse exactly what's stored (IMMUTABLE)
// ═══════════════════════════════════════════════════════════════════

/**
 * Parse an attendance string into counts. Reads data AS-IS — no mutation.
 * Holiday marks ('H') were already stamped during save.
 *
 * @param  string $attStr       e.g. "PPAHLLHVPPAT"
 * @param  int    $daysInMonth  Calendar days
 * @param  bool   $includeLeaveInPercent  If true, L counts as present for %
 * @return array  {present, absent, leave, holiday, late, vacant, total, working, percent, complete}
 */
function parse_attendance_string(string $attStr, int $daysInMonth, bool $includeLeaveInPercent = false): array
{
    $c = ['P' => 0, 'A' => 0, 'L' => 0, 'H' => 0, 'T' => 0, 'V' => 0];
    $attStr = str_pad($attStr, $daysInMonth, 'V');

    for ($i = 0; $i < $daysInMonth; $i++) {
        $m = strtoupper($attStr[$i] ?? 'V');
        if (isset($c[$m])) $c[$m]++;
        else $c['V']++;
    }

    // Working days = total - holidays (V is "not yet marked", not a day off)
    $working = max(1, $daysInMonth - $c['H']);

    // Percentage denominator: days that have a definitive status (P, T, A, and optionally L)
    $attended = $c['P'] + $c['T'];
    if ($includeLeaveInPercent) $attended += $c['L'];
    $denom = $attended + $c['A'];
    $pct = ($denom > 0) ? round(($attended / $denom) * 100, 1) : 100.0;

    return [
        'present'  => $c['P'],
        'absent'   => $c['A'],
        'leave'    => $c['L'],
        'holiday'  => $c['H'],
        'late'     => $c['T'],
        'vacant'   => $c['V'],
        'total'    => $daysInMonth,
        'working'  => $working - $c['V'], // effective working = total - holidays - unmarked
        'percent'  => $pct,
        'complete' => ($c['V'] === 0),     // true if every day is marked
    ];
}

// ═══════════════════════════════════════════════════════════════════
//  CENTRALIZED SUMMARY UPDATE — RETIRED (Component 4)
//  update_student_att_summary() wrote a stranded RTDB summary node
//  ({studentBase}/AttendanceSummary) that only the (dead-gated) Fees
//  helpers read. The canonical per-month summary is now `attendanceSummary`
//  (Firestore), written by _syncDailyToFirestore → _applyDayToSummary /
//  _syncStudentSummaryToFirestore on every live write path. Helper removed.
// ═══════════════════════════════════════════════════════════════════

// R5: update_staff_att_summary() removed — orphaned by R4 (approve_attendance_request
// staff branches migrated to Firestore). The canonical staff-attendance summary store
// is Firestore `staffAttendanceSummary` written by `_syncStaffSummaryToFirestore` /
// `Staff_attendance_writer`. The retired helper wrote a stranded RTDB mirror.

// ═══════════════════════════════════════════════════════════════════
//  POLICY CONFIG
// ═══════════════════════════════════════════════════════════════════

/**
 * Read AttendanceRules/include_leave_in_percent config.
 * @return bool  true = leave days count as present for percentage
 */
function _att_policy_include_leave($firebase, string $school): bool
{
    static $cache = [];
    if (isset($cache[$school])) return $cache[$school];
    try {
        $rules = $firebase->get("Schools/{$school}/Config/AttendanceRules");
        $cache[$school] = !empty($rules['include_leave_in_percent']);
    } catch (\Exception $e) {
        $cache[$school] = false;
    }
    return $cache[$school];
}

// ═══════════════════════════════════════════════════════════════════
//  HIGH-LEVEL GETTERS (cache-first, immutable reads)
// ═══════════════════════════════════════════════════════════════════

/**
 * Get student attendance data for the full academic session. Cache-first.
 */
function get_student_attendance_percent(
    $firebase, string $studentBase, string $school,
    string $fromMonth, int $fromYear,
    string $toMonth, int $toYear
): array {
    $monthMap = [
        'April' => 4, 'May' => 5, 'June' => 6, 'July' => 7, 'August' => 8,
        'September' => 9, 'October' => 10, 'November' => 11, 'December' => 12,
        'January' => 1, 'February' => 2, 'March' => 3,
    ];
    $academicOrder = array_keys($monthMap);

    $totals = ['present' => 0, 'absent' => 0, 'leave' => 0, 'late' => 0, 'holiday' => 0, 'working' => 0, 'vacant' => 0];
    $detail = [];
    $allComplete = true;
    $includeLeave = _att_policy_include_leave($firebase, $school);

    foreach ($academicOrder as $mn) {
        $num = $monthMap[$mn];
        $yr = in_array($mn, ['January','February','March']) ? $toYear : $fromYear;
        $attKey = "{$mn} {$yr}";
        $dim = cal_days_in_month(CAL_GREGORIAN, $num, $yr);

        // Try cache first
        $cached = $firebase->get("{$studentBase}/AttendanceSummary/{$attKey}");
        if (is_array($cached) && isset($cached['present'])) {
            $p = $cached;
        } else {
            $attStr = $firebase->get("{$studentBase}/Attendance/{$attKey}");
            $attStr = is_string($attStr) ? $attStr : '';
            if ($attStr === '') { $detail[$mn] = null; continue; }
            $p = parse_attendance_string($attStr, $dim, $includeLeave);
        }

        foreach (['present','absent','leave','late','holiday','working','vacant'] as $k) {
            $totals[$k] += (int)($p[$k] ?? 0);
        }
        if (!($p['complete'] ?? ($p['vacant'] ?? 0) === 0)) $allComplete = false;
        $detail[$mn] = $p;
    }

    $attended = $totals['present'] + $totals['late'];
    if ($includeLeave) $attended += $totals['leave'];
    $denom = $attended + $totals['absent'];
    $pct = ($denom > 0) ? round(($attended / $denom) * 100, 1) : 100.0;

    return array_merge($totals, [
        'percent'  => $pct,
        'complete' => $allComplete,
        'detail'   => $detail,
    ]);
}

/**
 * Get absent days for a student in a specific month. Cache-first.
 */
function get_absent_days($firebase, string $studentBase, string $school, string $monthName, int $year): int
{
    $monthMap = [
        'January'=>1,'February'=>2,'March'=>3,'April'=>4,'May'=>5,'June'=>6,
        'July'=>7,'August'=>8,'September'=>9,'October'=>10,'November'=>11,'December'=>12,
    ];
    $num = $monthMap[$monthName] ?? 0;
    if ($num === 0) return 0;
    $attKey = "{$monthName} {$year}";

    $cached = $firebase->get("{$studentBase}/AttendanceSummary/{$attKey}");
    if (is_array($cached) && isset($cached['absent'])) return (int)$cached['absent'];

    $dim = cal_days_in_month(CAL_GREGORIAN, $num, $year);
    $attStr = $firebase->get("{$studentBase}/Attendance/{$attKey}");
    return parse_attendance_string(is_string($attStr) ? $attStr : '', $dim)['absent'];
}

/**
 * P2 — Firestore-native student attendance % across an academic range.
 * Reads the canonical per-month `attendanceSummary` docs
 * (docId `{schoolId}_{studentId}_{YYYY-MM}`) — ZERO RTDB. Aggregates the
 * stored counts and returns the SAME shape as get_student_attendance_percent()
 * so the report-card call site is a drop-in replacement.
 *
 * @param object $fs           Firestore_service, already init()'d for the school
 * @param string $schoolId
 * @param string $studentId
 * @param string $fromMonth    academic start month, e.g. 'April'
 * @param int    $fromYear
 * @param string $toMonth      academic end month, e.g. 'March'
 * @param int    $toYear
 * @param bool   $includeLeave count approved leave as attended?
 * @return array ['percent','present','absent','leave','late','holiday','working','detail']
 */
function get_student_attendance_percent_fs(
    $fs, string $schoolId, string $studentId,
    string $fromMonth, int $fromYear, string $toMonth, int $toYear,
    bool $includeLeave = false
): array {
    $monthMap = [
        'April'=>4,'May'=>5,'June'=>6,'July'=>7,'August'=>8,'September'=>9,
        'October'=>10,'November'=>11,'December'=>12,'January'=>1,'February'=>2,'March'=>3,
    ];
    $totals = ['present'=>0,'absent'=>0,'leave'=>0,'late'=>0,'holiday'=>0,'working'=>0,'vacant'=>0];
    $detail = [];

    foreach ($monthMap as $mn => $num) {
        $yr = in_array($mn, ['January','February','March'], true) ? $toYear : $fromYear;
        $monthKey = sprintf('%04d-%02d', $yr, $num);
        $doc = null;
        try { $doc = $fs->get('attendanceSummary', $fs->docId2($studentId, $monthKey)); }
        catch (\Throwable $e) { $doc = null; }

        if (!is_array($doc) || empty($doc)) { $detail[$mn] = null; continue; }

        $present = (int) ($doc['present'] ?? 0);
        $absent  = (int) ($doc['absent']  ?? 0);
        $leave   = (int) ($doc['leave']   ?? 0);
        $late    = (int) ($doc['tardy']   ?? 0);
        $holiday = (int) ($doc['holiday'] ?? 0);
        $working = $present + $absent + $leave + $late;

        $totals['present'] += $present; $totals['absent']  += $absent;  $totals['leave']  += $leave;
        $totals['late']    += $late;    $totals['holiday'] += $holiday; $totals['working'] += $working;
        $detail[$mn] = ['present'=>$present,'absent'=>$absent,'leave'=>$leave,'late'=>$late,'holiday'=>$holiday,'working'=>$working];
    }

    $attended = $totals['present'] + $totals['late'];
    if ($includeLeave) $attended += $totals['leave'];
    $denom = $attended + $totals['absent'];
    $pct = ($denom > 0) ? round(($attended / $denom) * 100, 1) : 100.0;

    return [
        'percent' => $pct,
        'present' => $totals['present'],
        'absent'  => $totals['absent'],
        'leave'   => $totals['leave'],
        'late'    => $totals['late'],
        'holiday' => $totals['holiday'],
        'working' => $totals['working'],
        'detail'  => $detail,
    ];
}

// R5: get_staff_attendance_summary() removed — vestigial dead code (zero callers
// since creation, audit-confirmed). Staff summaries are read directly from Firestore
// `staffAttendanceSummary` by canonical workflows.

// ═══════════════════════════════════════════════════════════════════
//  COMPLETION CHECK (Task 5)
// ═══════════════════════════════════════════════════════════════════

/**
 * Check if student's attendance is complete (no vacant days) for a month.
 * Returns [complete => bool, vacant_days => int, warning => string]
 */
function check_attendance_complete($firebase, string $studentBase, string $monthName, int $year): array
{
    $monthMap = [
        'January'=>1,'February'=>2,'March'=>3,'April'=>4,'May'=>5,'June'=>6,
        'July'=>7,'August'=>8,'September'=>9,'October'=>10,'November'=>11,'December'=>12,
    ];
    $num = $monthMap[$monthName] ?? 0;
    if ($num === 0) return ['complete' => true, 'vacant_days' => 0, 'warning' => ''];

    $attKey = "{$monthName} {$year}";
    $cached = $firebase->get("{$studentBase}/AttendanceSummary/{$attKey}");

    if (is_array($cached) && isset($cached['vacant'])) {
        $v = (int)$cached['vacant'];
    } else {
        $dim = cal_days_in_month(CAL_GREGORIAN, $num, $year);
        $attStr = $firebase->get("{$studentBase}/Attendance/{$attKey}");
        $p = parse_attendance_string(is_string($attStr) ? $attStr : '', $dim);
        $v = $p['vacant'];
    }

    return [
        'complete'    => ($v === 0),
        'vacant_days' => $v,
        'warning'     => $v > 0 ? "Attendance incomplete: {$v} day(s) not marked for {$monthName}" : '',
    ];
}

// ═══════════════════════════════════════════════════════════════════
//  DATE GOVERNANCE
// ═══════════════════════════════════════════════════════════════════

/**
 * Check if a date is in the future.
 * @param  int $day      Day of month (1-31)
 * @param  int $monthNum Month number (1-12)
 * @param  int $year     Year (e.g. 2026)
 * @return bool
 */
function att_is_future_date(int $day, int $monthNum, int $year): bool
{
    $target = mktime(0, 0, 0, $monthNum, $day, $year);
    $today  = mktime(0, 0, 0, (int)date('n'), (int)date('j'), (int)date('Y'));
    return ($target > $today);
}

/**
 * Check if a date is in the past (before today).
 */
function att_is_past_date(int $day, int $monthNum, int $year): bool
{
    $target = mktime(0, 0, 0, $monthNum, $day, $year);
    $today  = mktime(0, 0, 0, (int)date('n'), (int)date('j'), (int)date('Y'));
    return ($target < $today);
}

/**
 * Check if a past date is within the allowed edit window.
 * @param  int $day        Day of month
 * @param  int $monthNum   Month number
 * @param  int $year       Year
 * @param  int $limitDays  Config: allow_past_edit_days (0 = no limit)
 * @return bool  true = within limit, false = too old
 */
function att_is_past_within_limit(int $day, int $monthNum, int $year, int $limitDays): bool
{
    if ($limitDays <= 0) return true; // 0 = no limit
    $target = new DateTime("{$year}-{$monthNum}-{$day}");
    $today  = new DateTime('today');
    return ($today->diff($target)->days <= $limitDays);
}

/**
 * Validate a bulk attendance string for date governance.
 * Returns: ['ok' => true] or ['ok' => false, 'error' => '...', 'needs_approval' => bool, 'past_days' => [...]]
 *
 * @param  string $attStr      The attendance string
 * @param  int    $daysInMonth Calendar days
 * @param  int    $monthNum    Month number
 * @param  int    $year        Year
 * @param  int    $pastLimit   Config: allow_past_edit_days (0 = unlimited)
 * @param  bool   $requireApproval  Config: require_approval_for_backdated
 */
function att_validate_date_governance(
    string $attStr, int $daysInMonth, int $monthNum, int $year,
    int $pastLimit = 0, bool $requireApproval = true
): array {
    $todayDay = (int)date('j');
    $todayMonth = (int)date('n');
    $todayYear = (int)date('Y');
    $todayTs = mktime(0, 0, 0, $todayMonth, $todayDay, $todayYear);

    $hasFuture = false;
    $pastDays = [];

    for ($d = 1; $d <= $daysInMonth; $d++) {
        $mark = strtoupper($attStr[$d - 1] ?? 'V');
        if ($mark === 'V' || $mark === 'H') continue; // unmarked or holiday — skip

        $dayTs = mktime(0, 0, 0, $monthNum, $d, $year);

        if ($dayTs > $todayTs) {
            $hasFuture = true;
            break;
        }

        if ($dayTs < $todayTs) {
            $pastDays[] = $d;
            // Check edit limit
            if ($pastLimit > 0) {
                $diff = (int)(($todayTs - $dayTs) / 86400);
                if ($diff > $pastLimit) {
                    return [
                        'ok'    => false,
                        'error' => "Cannot edit attendance older than {$pastLimit} days (day {$d} is {$diff} days ago).",
                        'needs_approval' => false,
                    ];
                }
            }
        }
    }

    if ($hasFuture) {
        return [
            'ok'    => false,
            'error' => 'Attendance contains future dates which are not allowed.',
            'needs_approval' => false,
        ];
    }

    if (!empty($pastDays) && $requireApproval) {
        return [
            'ok'             => false,
            'needs_approval' => true,
            'past_days'      => $pastDays,
            'error'          => 'Backdated attendance requires admin approval.',
        ];
    }

    return ['ok' => true];
}

// ═══════════════════════════════════════════════════════════════════
//  DEDUP KEY
// ═══════════════════════════════════════════════════════════════════

function att_event_dedup_key(string $studentId, string $date, string $mark): string
{
    return md5("{$studentId}|{$date}|{$mark}");
}
