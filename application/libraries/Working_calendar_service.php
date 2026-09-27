<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Working_calendar_service — THE canonical working-week authority (N-23/P-021).
 *
 * PRODUCT PRINCIPLE THIS ENFORCES
 * -------------------------------
 * The school/company decides how its working week operates. ZenX stores that
 * policy, resolves the applicable version for a period, and every consumer —
 * Attendance, Payroll, reporting — reads the SAME per-day answer. Payroll never
 * decides which weekdays are working.
 *
 * NO DEFAULT CALENDAR. DELIBERATELY.
 * ----------------------------------
 * A tenant with no configured policy resolves to `configured => false`, and
 * payroll must BLOCK. The legacy engine happened to assume "Sunday + 2nd/4th
 * Saturday", but that was an implementation accident, not a product decision,
 * and silently keeping it would:
 *   - impose one country's work week on every tenant,
 *   - hide the fact that nobody has configured anything,
 *   - and quietly price payroll against a calendar no administrator chose.
 *
 * So the legacy rule appears NOWHERE in this file as a fallback. It is recorded
 * only in `legacyRuleDescription()` — as documentation of how historical slips
 * were calculated — and that method is never consulted during resolution.
 *
 * WHY nth-WEEKDAY PATTERNS EXIST HERE
 * -----------------------------------
 * A flat weekday list cannot express "2nd and 4th Saturday off", which is a
 * normal Indian school week. Without it the policy model would be strictly less
 * expressive than the rule it replaces, so tenants could not describe their real
 * calendar. That is why N-27 is part of this fix rather than deferred.
 *
 * EFFECTIVE DATING (N-29)
 * -----------------------
 * Policy is a LIST of effective-dated versions, resolved with the same
 * `ed_select_for()` the compensation layer uses — one effective-dating
 * implementation, not two. Editing today's policy therefore cannot change what
 * a closed period resolves to.
 *
 * TENANT SCOPE
 * ------------
 * School-level, because per-employee policy assignment does not exist in ZenX
 * today (matrix P-017 = MISSING). `resolveForMonth()` takes the resolved policy
 * document, so introducing per-employee policy later means changing WHO supplies
 * that document — not rebuilding payroll.
 */
class Working_calendar_service
{
    /** Day-of-week tokens, matching `date('D')` and the existing `weeklyOffs`. */
    const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    /** Per-day classification reasons. Weekly-off and holiday stay DISTINCT (§9). */
    const REASON_WORKING       = 'working';
    const REASON_WEEKLY_OFF    = 'weeklyOff';
    const REASON_NTH_WEEKDAY   = 'nthWeekdayOff';
    const REASON_HOLIDAY       = 'holiday';
    const REASON_OUTSIDE       = 'outsideWindow';
    /** Workforce Policy Programme Phase D (2026-08-18). */
    const REASON_SPECIAL_WORKING_DAY = 'specialWorkingDay';
    const REASON_HALF_DAY            = 'halfDay';

    /** `working_mask` alphabet — positional, day-1 = offset-0, like `day_wise`. */
    const MASK_WORKING = '1';
    const MASK_OFF     = '0';
    const MASK_OUTSIDE = '-';
    /** Phase D — informational only; still counts toward workingCount exactly
     *  like MASK_WORKING (see resolveFromVersion() docblock) so no existing
     *  Payroll arithmetic changes. */
    const MASK_HALF = 'h';

    // ─────────────────────────────────────────────────────────────────────
    //  Policy selection
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Pick the policy version in force on `$date`.
     *
     * Accepts the whole `attendancePolicy` map. Returns null when the tenant has
     * configured nothing — the caller MUST treat that as a block, never as
     * "no offs" and never as a default calendar.
     */
    public static function effectiveVersion(?array $attendancePolicy, string $date): ?array
    {
        if (!is_array($attendancePolicy)) return null;

        $ww = $attendancePolicy['workingWeek'] ?? null;
        if (!is_array($ww)) return null;

        return self::effectiveVersionFrom($ww['versions'] ?? null, $date);
    }

    /**
     * Pick the version in force on `$date` from a bare version list (N-31).
     *
     * The core selection, independent of WHERE the list is stored. A work
     * schedule owns its own list, so the employee-level resolver passes that
     * list here — the calendar maths is identical, only the owner differs.
     */
    public static function effectiveVersionFrom(?array $versions, string $date): ?array
    {
        if (!is_array($versions) || empty($versions)) return null;

        require_once APPPATH . 'helpers/effective_dating_helper.php';
        $v = ed_select_for($versions, $date);

        // A version must actually describe a working week. An empty descriptor
        // is treated as unconfigured rather than as "every day is working" —
        // the latter would silently pay a full month to an absent employee.
        if (!is_array($v)) return null;
        if (!self::describesAWorkingWeek($v)) return null;

        return $v;
    }

    /** Does this version actually say anything about the working week? */
    public static function describesAWorkingWeek(array $v): bool
    {
        $weekly = is_array($v['weeklyOffs'] ?? null) ? $v['weeklyOffs'] : [];
        $nth    = is_array($v['nthWeekdayOffs'] ?? null) ? $v['nthWeekdayOffs'] : [];
        return !empty($weekly) || !empty($nth);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Resolution
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The per-day working calendar for one employee in one month.
     *
     * @param array|null  $attendancePolicy  schools/{id}.attendancePolicy
     * @param array       $holidays          [dayOfMonth => name], from Holiday_service
     * @param array       $inWindow          [dayOfMonth => true] employment window
     *
     * @return array{
     *   configured:bool, blocked:bool, blockReason:string,
     *   isWorking:array<int,bool>, reason:array<int,string>,
     *   workingCount:int, workingMask:string,
     *   policyVersion:mixed, policyEffectiveFrom:string, policySource:string
     * }
     */
    public static function resolveForMonth(
        ?array $attendancePolicy,
        int $year,
        int $monthNum,
        array $holidays,
        array $inWindow,
        array $specialWorkingDays = [],
        array $halfDays = [],
        float $halfDayWeight = 1.0
    ): array {
        $daysInMonth = (int) date('t', mktime(0, 0, 0, $monthNum, 1, $year));
        $periodStart = sprintf('%04d-%02d-01', $year, $monthNum);

        return self::resolveFromVersion(
            self::effectiveVersion($attendancePolicy, $periodStart),
            $year, $monthNum, $holidays, $inWindow, $specialWorkingDays, $halfDays,
            $halfDayWeight
        );
    }

    /**
     * The calendar maths, given an already-selected policy version (N-31).
     *
     * Split out so the EMPLOYEE-level resolver can select a version from that
     * employee's assigned work schedule and reuse this identically. One
     * implementation of the calendar; the caller decides whose it is.
     *
     * PHASE D (2026-08-18) PRECEDENCE — most specific wins, checked in this
     * exact order per day:
     *   1. Special Working Day  → WORKING, overrides every check below —
     *      a deliberate administrative override, the strongest statement
     *      the calendar can make about a date.
     *   2. Weekly Off / Nth-weekday Off (Work Schedule)   → NON-WORKING
     *   3. Holiday (School Calendar)                       → NON-WORKING
     *   4. Half Day (School Calendar)                       → WORKING, but
     *      flagged informationally — only reached if the day wasn't already
     *      fully non-working above, since shortening an already-off day has
     *      no meaning. Counts toward workingCount exactly like a normal
     *      working day (see MASK_HALF docblock) — Phase D deliberately does
     *      NOT change Payroll's day-count arithmetic; a future, separately
     *      authorized phase decides how a half day affects pay.
     *   5. (default) → WORKING
     *
     * `$specialWorkingDays`/`$halfDays` are OPTIONAL and default empty, so
     * every pre-Phase-D caller (nothing passes them yet outside the new
     * Phase D consumers) gets byte-identical output to before.
     *
     * @param array|null $v Resolved version, or null when nothing applies.
     * @param array $specialWorkingDays [dayOfMonth => name], from Holiday_service::special_working_days_in_month()
     * @param array $halfDays           [dayOfMonth => name], from Holiday_service::half_days_in_month()
     *
     * @param float $halfDayWeight  What ONE Calendar Half-Day contributes to
     *   expected work. DEFAULTS TO 1.0, which is byte-identical to the
     *   pre-2026-08-19 behaviour — a declared half-day counted as a full
     *   working day and the half-ness was purely informational.
     *
     *   Callers pass 0.5 only when the tenant has explicitly enabled the
     *   Calendar Half-Day pay model AND the period is on/after their chosen
     *   effective date. Keeping the default at 1.0 is what guarantees that a
     *   tenant with the feature OFF — and every historical run — cannot
     *   change value.
     *
     *   Note this changes EXPECTED WORK only. It is deliberately NOT a
     *   salary fraction: _compute_payslip() uses working days purely as a
     *   ratio denominator, so an employee who fulfils the reduced
     *   expectation still retains 1.0 of pay.
     */
    public static function resolveFromVersion(
        ?array $v,
        int $year,
        int $monthNum,
        array $holidays,
        array $inWindow,
        array $specialWorkingDays = [],
        array $halfDays = [],
        float $halfDayWeight = 1.0
    ): array {
        $daysInMonth = (int) date('t', mktime(0, 0, 0, $monthNum, 1, $year));

        if ($v === null) {
            // BLOCKED — and no calendar is invented. Every day is 'outside' so a
            // caller that ignores `blocked` still cannot silently compute pay.
            return [
                'configured'   => false,
                'blocked'      => true,
                'blockReason'  => 'Working-week policy is not configured for this school. '
                    . 'Configure the Attendance Working-Week Policy before generating payroll.',
                'isWorking'    => [],
                'reason'       => [],
                'workingCount' => 0,
                'workingMask'  => str_repeat(self::MASK_OUTSIDE, $daysInMonth),
                'halfDayCount'           => 0,
                'specialWorkingDayCount' => 0,
                'policyVersion'       => null,
                'policyEffectiveFrom' => '',
                'policySource'        => 'unconfigured',
            ];
        }

        $weeklyOffs = array_values(array_intersect(
            self::DAYS, is_array($v['weeklyOffs'] ?? null) ? $v['weeklyOffs'] : []
        ));
        $nthOffs = is_array($v['nthWeekdayOffs'] ?? null) ? $v['nthWeekdayOffs'] : [];

        $isWorking = [];
        $reason    = [];
        $mask      = '';
        // Stays an INT unless a fractional half-day weight is actually in
        // play. "Flag OFF is byte-identical" has to include the TYPE, not just
        // the number: callers and tests legitimately compare workingCount with
        // ===, and silently promoting 26 to 26.0 for every tenant would be a
        // contract change for schools that never enabled the feature.
        $count     = 0;

        // Occurrence counter per weekday, so "2nd Saturday" is the 2nd Saturday
        // OF THE MONTH regardless of where the employment window starts.
        $seen = array_fill_keys(self::DAYS, 0);

        for ($d = 1; $d <= $daysInMonth; $d++) {
            $dowIdx = (int) date('w', mktime(0, 0, 0, $monthNum, $d, $year));
            $dow    = self::DAYS[$dowIdx];
            $seen[$dow]++;

            if (!isset($inWindow[$d])) {
                $reason[$d] = self::REASON_OUTSIDE;
                $mask .= self::MASK_OUTSIDE;
                continue;
            }

            // Phase D, tier 1 — Special Working Day overrides everything else
            // for this date; checked before weekly-off/nth/holiday, not after.
            if (isset($specialWorkingDays[$d])) {
                $reason[$d] = self::REASON_SPECIAL_WORKING_DAY;
                $isWorking[$d] = true;
                $mask .= self::MASK_WORKING;
                $count++;
                continue;
            }

            $r = self::REASON_WORKING;

            if (in_array($dow, $weeklyOffs, true)) {
                $r = self::REASON_WEEKLY_OFF;
            } elseif (self::isNthWeekdayOff($nthOffs, $dow, $seen[$dow])) {
                $r = self::REASON_NTH_WEEKDAY;
            } elseif (isset($holidays[$d])) {
                // Holiday is checked LAST (of the non-working checks) and kept
                // as its own reason so an auditor can tell a holiday from a
                // weekly off (§9).
                $r = self::REASON_HOLIDAY;
            } elseif (isset($halfDays[$d])) {
                // Phase D, tier 4 — only reached when the day isn't already
                // fully non-working above; shortening an off day has no
                // meaning. Still a WORKING day for workingCount (see docblock).
                $r = self::REASON_HALF_DAY;
            }

            $reason[$d] = $r;
            if ($r === self::REASON_WORKING) {
                $isWorking[$d] = true;
                $mask .= self::MASK_WORKING;
                $count++;
            } elseif ($r === self::REASON_HALF_DAY) {
                // Still a WORKING day (isWorking/mask unchanged), but it
                // contributes $halfDayWeight to EXPECTED WORK. Default 1.0
                // reproduces the original behaviour exactly.
                $isWorking[$d] = true;
                $mask .= self::MASK_HALF;
                // Add an INT when the weight is the default 1.0 so the
                // accumulator (and therefore workingCount) keeps its original
                // integer type for every tenant that has not enabled the
                // Calendar Half-Day pay model. Only a genuinely fractional
                // weight promotes it to float.
                $count += ($halfDayWeight == 1.0) ? 1 : $halfDayWeight;
            } else {
                $mask .= self::MASK_OFF;
            }
        }

        // Phase D — informational tallies, not consumed by any pay arithmetic
        // yet. Derived from $reason, not tracked separately, so they can never
        // drift out of sync with the mask/isWorking above.
        $halfDayCount = 0; $specialWorkingDayCount = 0;
        foreach ($reason as $rr) {
            if ($rr === self::REASON_HALF_DAY) $halfDayCount++;
            elseif ($rr === self::REASON_SPECIAL_WORKING_DAY) $specialWorkingDayCount++;
        }

        return [
            'configured'   => true,
            'blocked'      => false,
            'blockReason'  => '',
            'isWorking'    => $isWorking,
            'reason'       => $reason,
            'workingCount' => $count,
            'workingMask'  => $mask,
            'halfDayCount'            => $halfDayCount,
            'specialWorkingDayCount'  => $specialWorkingDayCount,
            'policyVersion'       => $v['version'] ?? null,
            'policyEffectiveFrom' => (string) ($v['effectiveFrom'] ?? ''),
            'policySource'        => 'configured',
        ];
    }

    /**
     * Is this the Nth occurrence of a weekday that the policy marks off?
     *
     * `nthWeekdayOffs` shape: `[['day' => 'Sat', 'weeks' => [2, 4]], …]`
     * Extensible by data alone — a tenant needing 1st/3rd/5th Saturday changes
     * configuration, not code, which is the whole point of N-27.
     */
    public static function isNthWeekdayOff(array $nthOffs, string $dow, int $occurrence): bool
    {
        foreach ($nthOffs as $rule) {
            if (!is_array($rule)) continue;
            if ((string) ($rule['day'] ?? '') !== $dow) continue;
            $weeks = is_array($rule['weeks'] ?? null) ? $rule['weeks'] : [];
            foreach ($weeks as $w) {
                if ((int) $w === $occurrence) return true;
            }
        }
        return false;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Validation (for the admin configuration surface)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Validate one policy version before it is stored.
     *
     * A policy that marks EVERY day off is rejected: it is never a real working
     * week, and it would silently zero every payslip.
     */
    public static function validateVersion(array $in): array
    {
        $errors = [];

        $from = trim((string) ($in['effectiveFrom'] ?? ''));
        require_once APPPATH . 'helpers/effective_dating_helper.php';
        if ($from === '' || !ed_is_iso_date($from)) {
            $errors[] = 'An effective-from date (YYYY-MM-DD) is required.';
        }

        $weekly = is_array($in['weeklyOffs'] ?? null) ? $in['weeklyOffs'] : [];
        foreach ($weekly as $d) {
            if (!in_array($d, self::DAYS, true)) {
                $errors[] = "Unknown weekly-off day '{$d}'.";
            }
        }

        $nth = is_array($in['nthWeekdayOffs'] ?? null) ? $in['nthWeekdayOffs'] : [];
        foreach ($nth as $rule) {
            if (!is_array($rule)) { $errors[] = 'Malformed nth-weekday rule.'; continue; }
            if (!in_array((string) ($rule['day'] ?? ''), self::DAYS, true)) {
                $errors[] = "Unknown nth-weekday day '" . (string) ($rule['day'] ?? '') . "'.";
            }
            $weeks = is_array($rule['weeks'] ?? null) ? $rule['weeks'] : [];
            if (empty($weeks)) $errors[] = 'An nth-weekday rule needs at least one week number.';
            foreach ($weeks as $w) {
                if (!is_numeric($w) || (int) $w < 1 || (int) $w > 5) {
                    $errors[] = 'Week numbers must be between 1 and 5.';
                }
            }
        }

        if (empty($weekly) && empty($nth)) {
            $errors[] = 'A working-week policy must define at least one weekly-off day or pattern. '
                . 'A policy with no non-working days would treat every calendar day as payable.';
        }

        if (count($weekly) >= 7) {
            $errors[] = 'Every day of the week is marked off — that is not a working week.';
        }

        return ['ok' => empty($errors), 'errors' => $errors];
    }

    /**
     * How legacy payroll used to compute the calendar, BEFORE this service.
     *
     * DOCUMENTATION ONLY. Never consulted during resolution, never used as a
     * fallback. It exists so an auditor reading a pre-fix payslip can understand
     * which calendar produced it, and so migration tooling can explain what a
     * tenant's historical slips assumed.
     */
    public static function legacyRuleDescription(): string
    {
        return 'Legacy payroll (before the working-week policy existed) hardcoded: '
            . 'Sunday off, plus the 2nd and 4th Saturday off, plus the holiday calendar. '
            . 'This was an implementation assumption, NOT a configured tenant policy, and it is '
            . 'never applied as a default by Working_calendar_service.';
    }
}
