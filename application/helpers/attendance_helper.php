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
 * CONSISTENCY RULE: update_student_att_summary() is the SINGLE function
 *   to call whenever attendance data changes (save, leave apply, leave cancel).
 */

// ═══════════════════════════════════════════════════════════════════
//  HOLIDAY DETECTION (shared by save path + summary computation)
// ═══════════════════════════════════════════════════════════════════

/**
 * Get all non-working days for a month (Sundays + configured holidays).
 * @return array  Map of day_number => reason
 */
function get_non_working_days($firebase, string $school, int $monthNum, int $year): array
{
    $nonWorking = [];
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);

    // Sundays
    for ($d = 1; $d <= $daysInMonth; $d++) {
        if ((int)date('w', mktime(0, 0, 0, $monthNum, $d, $year)) === 0) {
            $nonWorking[$d] = 'Sunday';
        }
    }

    // Events/Holidays/{year}
    try {
        $evtH = $firebase->get("Schools/{$school}/Events/Holidays/{$year}");
        if (is_array($evtH)) {
            foreach ($evtH as $h) {
                $hDate = $h['date'] ?? '';
                if (!$hDate) continue;
                $ts = strtotime($hDate);
                if ($ts && (int)date('n', $ts) === $monthNum && (int)date('Y', $ts) === $year) {
                    $nonWorking[(int)date('j', $ts)] = $h['name'] ?? 'Holiday';
                }
            }
        }
    } catch (\Exception $e) {}

    // Config/Attendance/holidays
    try {
        $cfgH = $firebase->get("Schools/{$school}/Config/Attendance/holidays");
        if (is_array($cfgH)) {
            foreach ($cfgH as $date => $name) {
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
                    if ((int)$m[1] === $year && (int)$m[2] === $monthNum) {
                        $nonWorking[(int)$m[3]] = is_string($name) ? $name : 'Holiday';
                    }
                }
            }
        }
    } catch (\Exception $e) {}

    return $nonWorking;
}

function is_non_working_day($firebase, string $school, int $day, int $monthNum, int $year): bool
{
    return isset(get_non_working_days($firebase, $school, $monthNum, $year)[$day]);
}

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
//  CENTRALIZED SUMMARY UPDATE (Task 2)
//  Single function to call from: save, leave apply, leave cancel
// ═══════════════════════════════════════════════════════════════════

/**
 * Recompute + write student attendance summary for a month.
 * Call this after ANY change to attendance data (save, leave, cancel).
 *
 * @return array The computed summary
 */
function update_student_att_summary(
    $firebase, string $studentBase, string $school,
    string $attKey, int $monthNum, int $year
): array {
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);

    // Read the stored string (immutable source of truth)
    $attStr = $firebase->get("{$studentBase}/Attendance/{$attKey}");
    $attStr = is_string($attStr) ? $attStr : '';

    // Read policy config
    $includeLeave = _att_policy_include_leave($firebase, $school);

    $summary = parse_attendance_string($attStr, $daysInMonth, $includeLeave);
    $summary['updated_at'] = date('c');

    try {
        $firebase->set("{$studentBase}/AttendanceSummary/{$attKey}", $summary);
    } catch (\Exception $e) {
        log_message('error', 'update_student_att_summary failed: ' . $e->getMessage());
    }
    return $summary;
}

/**
 * Recompute + write staff attendance summary for a month.
 */
function update_staff_att_summary(
    $firebase, string $school, string $session,
    string $staffId, string $attKey, int $monthNum, int $year
): array {
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);

    $attStr = $firebase->get("Schools/{$school}/{$session}/Staff_Attendance/{$attKey}/{$staffId}");
    $attStr = is_string($attStr) ? $attStr : '';

    $summary = parse_attendance_string($attStr, $daysInMonth, false);
    $summary['updated_at'] = date('c');

    try {
        $firebase->set("Schools/{$school}/{$session}/Staff_Attendance/Summary/{$staffId}/{$attKey}", $summary);
    } catch (\Exception $e) {
        log_message('error', 'update_staff_att_summary failed: ' . $e->getMessage());
    }
    return $summary;
}

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
 * Get staff attendance summary. Cache-first.
 */
function get_staff_attendance_summary(
    $firebase, string $school, string $session,
    string $staffId, string $monthName, int $year
): array {
    $monthMap = [
        'January'=>1,'February'=>2,'March'=>3,'April'=>4,'May'=>5,'June'=>6,
        'July'=>7,'August'=>8,'September'=>9,'October'=>10,'November'=>11,'December'=>12,
    ];
    $num = $monthMap[$monthName] ?? 0;
    if ($num === 0) return parse_attendance_string('', 30);

    $attKey = "{$monthName} {$year}";
    $cached = $firebase->get("Schools/{$school}/{$session}/Staff_Attendance/Summary/{$staffId}/{$attKey}");
    if (is_array($cached) && isset($cached['present'])) return $cached;

    $dim = cal_days_in_month(CAL_GREGORIAN, $num, $year);
    $attStr = $firebase->get("Schools/{$school}/{$session}/Staff_Attendance/{$attKey}/{$staffId}");
    return parse_attendance_string(is_string($attStr) ? $attStr : '', $dim);
}

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
