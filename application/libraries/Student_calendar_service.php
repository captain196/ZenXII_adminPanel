<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Student_calendar_service — THE canonical STUDENT academic-calendar authority.
 *
 * THE QUESTION THIS ANSWERS
 * -------------------------
 * "What does date X mean for STUDENTS in school Y, in session Z?"
 *
 * It answers three things that ZenX previously could not express at all, and
 * that are genuinely independent of one another:
 *
 *   open        the school is operating on this date
 *   instruction regular teaching is expected on this date
 *   attendance  student attendance may be recorded for this date
 *
 * They are not the same question. A school can be OPEN for an exam with no
 * regular INSTRUCTION. Collapsing them into one boolean is exactly what the
 * legacy `date('w') === 0` gate did, and why an exam-only day or a training day
 * could not be modelled.
 *
 * WHY THIS IS NOT THE STAFF AUTHORITY — AND MUST NEVER BECOME IT
 * -------------------------------------------------------------
 * `Working_calendar_service` + `Policy_assignment_service` answer a DIFFERENT
 * question: "is THIS EMPLOYEE expected to work?" — per employee, with
 * department rules, per-staff overrides, leave overlays and payroll
 * consequences. This service answers a SCHOOL-level question about students.
 *
 * The two must never merge:
 *   - A teacher training day closes the school to students while staff work.
 *   - A staff member on a flexible schedule does not make it a school holiday.
 *
 * So this service:
 *   - reads NOTHING owned by the workforce domain,
 *   - has NO runtime dependency on Working_calendar_service,
 *   - and never decides whether a staff member was required to work.
 *
 * DELIBERATE DUPLICATION, PROVEN NOT TO DRIFT
 * -------------------------------------------
 * The nth-weekday predicate below duplicates ~15 lines of pure date arithmetic
 * that `Working_calendar_service::isNthWeekdayOff()` also implements. That is
 * intentional. Calling the staff service would mean a payroll-motivated edit to
 * that file could silently move STUDENT attendance — the precise cross-domain
 * coupling the architecture forbids. The cost of duplication is drift, and drift
 * is paid for by `StudentCalendarNthWeekdayAgreementTest`, which asserts both
 * implementations agree across an exhaustive date sweep. Duplicated maths that
 * is proven identical is safer than a shared authority that is not.
 *
 * `ed_select_for()` IS reused, because effective dating is a domain-neutral
 * repository primitive already shared by compensation and workforce alike, and
 * CLAUDE.md requires one effective-dating implementation rather than two.
 *
 * PURE. NO I/O.
 * -------------
 * Every method is static and side-effect free. Exceptions (holidays, special
 * working days, half days) are PASSED IN by the caller, which obtains them from
 * `Holiday_service` — still the single canonical reader of `calendarEvents`.
 * This service does not query Firestore and must never learn to: duplicating
 * that read would create the second calendar reader the architecture avoids.
 *
 * HISTORICAL INVARIANCE
 * ---------------------
 * The weekly pattern is a LIST of effective-dated versions resolved by date, and
 * versions are append-only. Changing the pattern in March therefore cannot
 * change what January resolved to — the same property TT-C8 established for
 * timetables, obtained the same way. `validateVersionAgainst()` refuses a new
 * version that would land on or before an already-settled date.
 *
 * NO DEFAULT CALENDAR. DELIBERATELY.
 * ----------------------------------
 * An unconfigured tenant resolves to `configured => false` with open/instruction/
 * attendance set to NULL — not false, and not a guessed Sunday rule. NULL forces
 * the caller to apply an explicit, owner-chosen policy instead of inheriting a
 * developer's assumption. The historical rule is recorded in
 * `legacyStudentPattern()` as documentation and as a migration seed; it is never
 * consulted during resolution.
 */
class Student_calendar_service
{
    /** Day tokens — `date('D')`, matching the workforce vocabulary so one
     *  operator reading both screens sees the same words. */
    const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    /** Display names. The legacy pipeline labelled a closed Sunday 'Sunday',
     *  and existing UI copy reads that label, so the vocabulary is preserved
     *  rather than replaced with a token nobody outside the code recognises. */
    const DAYS_LONG = [
        'Sun' => 'Sunday',    'Mon' => 'Monday', 'Tue' => 'Tuesday',
        'Wed' => 'Wednesday', 'Thu' => 'Thursday',
        'Fri' => 'Friday',    'Sat' => 'Saturday',
    ];

    /** Per-date classification. Deliberately mirrors Working_calendar_service's
     *  REASON_* names where the concept is the same — same word, same meaning,
     *  different authority. */
    const REASON_WORKING         = 'working';
    const REASON_WEEKLY_OFF      = 'weeklyOff';
    const REASON_NTH_WEEKDAY     = 'nthWeekdayOff';
    const REASON_HOLIDAY         = 'holiday';
    const REASON_SPECIAL_WORKING = 'specialWorkingDay';
    const REASON_OUTSIDE_SESSION = 'outsideSession';
    const REASON_EXAM_ONLY       = 'examOnly';
    const REASON_UNCONFIGURED    = 'unconfigured';

    /**
     * Precedence, HIGHEST first. Derived from observed repository behaviour
     * (Attendance::_resolve_non_working_days subtracts special working days
     * from the union of Sundays and holidays), not chosen by preference.
     *
     * A half day is NOT in this list: it is a MODIFIER on a day that is already
     * working, never a closure. Working_calendar_service reaches the same
     * conclusion independently — its MASK_HALF "counts toward workingCount
     * exactly like MASK_WORKING".
     */
    const PRECEDENCE = [
        self::REASON_OUTSIDE_SESSION,
        self::REASON_SPECIAL_WORKING,
        self::REASON_HOLIDAY,
        self::REASON_EXAM_ONLY,
        self::REASON_NTH_WEEKDAY,
        self::REASON_WEEKLY_OFF,
        self::REASON_WORKING,
    ];

    // ─────────────────────────────────────────────────────────────────────
    //  Version selection
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The pattern version in force on `$date`.
     *
     * Returns null when the tenant has configured nothing. The caller MUST treat
     * null as "unknown", never as "no offs" and never as a default calendar.
     */
    public static function effectiveVersion(?array $versions, string $date): ?array
    {
        if (!is_array($versions) || empty($versions) || !self::isIsoDate($date)) {
            return null;
        }

        require_once APPPATH . 'helpers/effective_dating_helper.php';
        $v = ed_select_for($versions, $date);

        // A version must actually describe a week. An empty descriptor is
        // treated as unconfigured rather than as "every day is a teaching day",
        // which would silently mark absences on days the school was shut.
        if (!is_array($v) || !self::describesAWeek($v)) return null;

        return $v;
    }

    /** Does this version say anything at all about the school week? */
    public static function describesAWeek(array $v): bool
    {
        $weekly = is_array($v['weeklyOffs'] ?? null) ? $v['weeklyOffs'] : [];
        $nth    = is_array($v['nthWeekdayOffs'] ?? null) ? $v['nthWeekdayOffs'] : [];
        return !empty($weekly) || !empty($nth);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Resolution
    // ─────────────────────────────────────────────────────────────────────

    /**
     * What does `$date` mean for students?
     *
     * @param array|null $version    from effectiveVersion(); null ⇒ unconfigured
     * @param string     $date       YYYY-MM-DD
     * @param array      $exceptions per-date flags the CALLER read from
     *                               Holiday_service, any of:
     *                               holiday, specialWorkingDay, halfDay, examOnly
     * @param array      $session    optional ['start' => ISO, 'end' => ISO]
     *
     * @return array open/instruction/attendance are bool, or NULL when
     *               unconfigured — the caller then applies an explicit policy.
     */
    public static function resolveForDate(
        ?array $version,
        string $date,
        array $exceptions = [],
        array $session = []
    ): array {
        if (!self::isIsoDate($date)) {
            return self::result(null, null, null, false, self::REASON_UNCONFIGURED, false, '',
                'invalid_date');
        }

        $halfDay = !empty($exceptions['halfDay']);

        // SCS-3. A version carrying an effectiveTo that has already passed does
        // NOT apply, even though a caller handed it to us. effectiveVersion()
        // filters these out via ed_select_for(), but resolveForDate() is public
        // and a caller can assemble a version by other means (a preview screen,
        // a migration, a test). Trusting the input made an expired week pattern
        // resolve as a normal teaching day. The resolver re-checks rather than
        // relying on every caller having used the front door.
        if ($version !== null) {
            $vFrom = (string) ($version['effectiveFrom'] ?? '');
            $vTo   = (string) ($version['effectiveTo'] ?? '');
            if (($vFrom !== '' && self::isIsoDate($vFrom) && $date < $vFrom)
                || ($vTo !== '' && self::isIsoDate($vTo) && $date > $vTo)) {
                $version = null;
            }
        }

        // SCS-2. A session window that is present but unparseable must not be
        // silently ignored. Ignoring it let a date years outside the session
        // resolve as an ordinary teaching day. An unusable window is reported as
        // unconfigured, so the caller applies an explicit policy instead of
        // inheriting a silent pass.
        $start = (string) ($session['start'] ?? '');
        $end   = (string) ($session['end'] ?? '');
        if (($start !== '' && !self::isIsoDate($start))
            || ($end !== '' && !self::isIsoDate($end))) {
            return self::result(null, null, null, $halfDay,
                self::REASON_UNCONFIGURED, false, '', 'invalid_session_window');
        }

        // 1. Outside the academic session. Checked early and independently of the
        //    week pattern: a date outside the session is meaningless for students
        //    whether or not a pattern exists.
        //
        //    SCS-1. `configured` here reports whether a WEEK PATTERN is in force,
        //    not whether a session window was supplied. Hardcoding it true told an
        //    unconfigured tenant it was configured — and `configured` is precisely
        //    the field a caller reads to decide whether to apply the owner's
        //    unconfigured-tenant policy. It must never overstate.
        if (($start !== '' && $date < $start) || ($end !== '' && $date > $end)) {
            return self::result(false, false, false, false,
                self::REASON_OUTSIDE_SESSION,
                $version !== null,
                (string) ($version['effectiveFrom'] ?? ''),
                'session');
        }

        // 2. Unconfigured. NULL, not false — see the class docblock. Exceptions
        //    are still reported so a caller can explain itself, but no
        //    open/instruction/attendance answer is invented.
        if ($version === null) {
            return self::result(null, null, null, $halfDay,
                self::REASON_UNCONFIGURED, false, '', 'unconfigured');
        }

        $from = (string) ($version['effectiveFrom'] ?? '');

        // 3. Special working day — overrides BOTH holiday and weekly off. A
        //    declared makeup day is a normal, markable teaching day; that is the
        //    entire point of declaring it.
        if (!empty($exceptions['specialWorkingDay'])) {
            return self::result(true, true, true, $halfDay,
                self::REASON_SPECIAL_WORKING, true, $from, 'exception');
        }

        // 4. Holiday — school shut.
        if (!empty($exceptions['holiday'])) {
            return self::result(false, false, false, false,
                self::REASON_HOLIDAY, true, $from, 'exception');
        }

        // 5. Exam-only — the case the single legacy boolean could not express:
        //    the school is OPEN and attendance IS taken, but regular teaching is
        //    not running, so the timetable should not be projected as normal.
        if (!empty($exceptions['examOnly'])) {
            return self::result(true, false, true, $halfDay,
                self::REASON_EXAM_ONLY, true, $from, 'exception');
        }

        // 6. The weekly pattern.
        $dow        = self::dayToken($date);
        $occurrence = self::occurrenceInMonth($date);

        $nth = is_array($version['nthWeekdayOffs'] ?? null) ? $version['nthWeekdayOffs'] : [];
        if (self::isNthWeekdayOff($nth, $dow, $occurrence)) {
            return self::result(false, false, false, false,
                self::REASON_NTH_WEEKDAY, true, $from, 'pattern');
        }

        $weekly = is_array($version['weeklyOffs'] ?? null) ? $version['weeklyOffs'] : [];
        if (in_array($dow, $weekly, true)) {
            return self::result(false, false, false, false,
                self::REASON_WEEKLY_OFF, true, $from, 'pattern');
        }

        // 7. A normal teaching day.
        return self::result(true, true, true, $halfDay,
            self::REASON_WORKING, true, $from, 'pattern');
    }

    /**
     * Is `$dow` the `$occurrence`-th such weekday of its month, per the rules?
     *
     * Duplicated from the workforce implementation on purpose — see the class
     * docblock. Kept byte-for-byte behaviourally identical and pinned by an
     * agreement test.
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
    //  Validation
    // ─────────────────────────────────────────────────────────────────────

    /** Shape validation for a single proposed version. */
    public static function validateVersion(array $in): array
    {
        $errors = [];

        require_once APPPATH . 'helpers/effective_dating_helper.php';
        $from = trim((string) ($in['effectiveFrom'] ?? ''));
        if ($from === '' || !ed_is_iso_date($from)) {
            $errors[] = 'An effective-from date (YYYY-MM-DD) is required.';
        }

        $weekly = is_array($in['weeklyOffs'] ?? null) ? $in['weeklyOffs'] : [];
        foreach ($weekly as $d) {
            if (!in_array($d, self::DAYS, true)) {
                $errors[] = "Unknown weekly-off day '" . (string) $d . "'.";
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
            $errors[] = 'A school week must define at least one non-teaching day or pattern. '
                . 'A week with none would treat every calendar day as a teaching day.';
        }

        if (count(array_unique(array_map('strval', $weekly))) >= 7) {
            $errors[] = 'Every day of the week is marked non-teaching — that is not a school week.';
        }

        return ['ok' => empty($errors), 'errors' => $errors];
    }

    /**
     * Validate a proposed version AGAINST the existing list — the historical
     * safety gate.
     *
     * Two refusals, both structural rather than advisory:
     *
     *   1. RETROACTIVE. A version taking effect on or before `$settledThrough`
     *      would change what an already-recorded date meant. Attendance
     *      percentages computed yesterday would silently differ today. Refused.
     *
     *   2. AMBIGUOUS. Two versions sharing an effectiveFrom make resolution
     *      depend on array order. ed_find_overlaps() is the same detector the
     *      compensation layer uses.
     *
     * @param string $settledThrough ISO date through which attendance is settled;
     *                               '' disables the retroactive check (first setup).
     */
    public static function validateVersionAgainst(
        array $proposed,
        array $existing,
        string $settledThrough = ''
    ): array {
        $base = self::validateVersion($proposed);
        $errors = $base['errors'];

        $from = trim((string) ($proposed['effectiveFrom'] ?? ''));

        if ($from !== '' && self::isIsoDate($from)
            && $settledThrough !== '' && self::isIsoDate($settledThrough)
            && $from <= $settledThrough) {
            $errors[] = 'This school week would take effect on ' . $from
                . ', on or before ' . $settledThrough
                . ', where student attendance is already recorded. Changing it would '
                . 'alter what those dates meant. Choose a later effective date.';
        }

        if ($from !== '' && self::isIsoDate($from)) {
            foreach ($existing as $e) {
                if ((string) ($e['effectiveFrom'] ?? '') === $from) {
                    $errors[] = 'Another school week already takes effect on ' . $from . '.';
                    break;
                }
            }
        }

        // SCS-4. The STORED list must itself be unambiguous. Only comparing the
        // proposal against `$existing` let pre-existing duplicates stay invisible
        // for ever — and on equal effectiveFrom dates ed_select_for() keeps
        // whichever it happened to see first, so a duplicate silently makes
        // resolution depend on array order. Surfacing it here means the next
        // person to edit the calendar is told the data is already ambiguous,
        // rather than saving on top of it.
        $seen = [];
        foreach ($existing as $e) {
            $ef = (string) ($e['effectiveFrom'] ?? '');
            if ($ef === '') continue;
            if (isset($seen[$ef])) {
                $errors[] = 'The saved school weeks are ambiguous: two already take '
                    . 'effect on ' . $ef . '. Resolution for that date depends on '
                    . 'storage order and must be corrected before saving another.';
            }
            $seen[$ef] = true;
        }

        // ed_find_overlaps() is the right detector ONLY for explicitly bounded
        // record sets. Its rule is "an open-ended predecessor swallows everything
        // that follows it", which is correct for compensation assignments — each
        // carries a real effectiveTo — and WRONG here.
        //
        // A school-week list is append-only and open-ended by design: every
        // version runs until the next one starts, and ed_select_for() resolves it
        // by taking the latest effectiveFrom on or before the date. An open-ended
        // predecessor is therefore the normal, expected shape, not a conflict.
        // Running the detector unconditionally refused every second version a
        // tenant ever added — caught by
        // StudentCalendarServiceTest::test_a_version_landing_on_settled_attendance_is_refused.
        //
        // For this shape the only real ambiguity is a duplicate effectiveFrom,
        // checked above: on equal dates ed_select_for() keeps whichever it saw
        // first, so resolution would depend on array order. If a version ever
        // does carry an explicit effectiveTo, gaps and true overlaps become
        // possible again and the detector is applied.
        $merged  = array_merge(array_values($existing), [$proposed]);
        $bounded = false;
        foreach ($merged as $r) {
            if (($r['effectiveTo'] ?? null) !== null && (string) $r['effectiveTo'] !== '') {
                $bounded = true;
                break;
            }
        }
        if ($bounded) {
            require_once APPPATH . 'helpers/effective_dating_helper.php';
            foreach (ed_find_overlaps($merged, 'school week') as $msg) {
                $errors[] = $msg;
            }
        }

        return ['ok' => empty($errors), 'errors' => $errors];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Migration documentation
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The rule student attendance used BEFORE this service: Sunday off, nothing
     * else — `attendance_helper.php::nw_days_from_holidays()`.
     *
     * DOCUMENTATION AND MIGRATION SEED ONLY. Never consulted during resolution,
     * never applied as a fallback. It exists so an auditor reading a historical
     * register can see which rule produced it, and so a migration can offer a
     * tenant their existing behaviour as an explicit, dated starting point that
     * an administrator confirms rather than inherits.
     */
    public static function legacyStudentPattern(string $effectiveFrom): array
    {
        return [
            'effectiveFrom'  => $effectiveFrom,
            'weeklyOffs'     => ['Sun'],
            'nthWeekdayOffs' => [],
            'label'          => 'Sunday closed (pre-configuration behaviour)',
            'source'         => 'legacy_migration',
        ];
    }

    public static function legacyRuleDescription(): string
    {
        return 'Before the student academic calendar existed, every school was assumed '
            . 'to close on Sundays only. Saturdays were always teaching days and no '
            . 'other weekly pattern could be expressed. Declared holidays and special '
            . 'working days applied on top, exactly as they do now.';
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Pure date helpers
    // ─────────────────────────────────────────────────────────────────────

    public static function isIsoDate(string $d): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
        [$y, $m, $day] = array_map('intval', explode('-', $d));
        return checkdate($m, $day, $y);
    }

    /** 'Sun'…'Sat' for an ISO date. */
    public static function dayToken(string $date): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        return self::DAYS[(int) date('w', mktime(0, 0, 0, $m, $d, $y))];
    }

    /** Which occurrence of its weekday this date is within its month (1–5). */
    public static function occurrenceInMonth(string $date): int
    {
        $d = (int) substr($date, 8, 2);
        return (int) ceil($d / 7);
    }

    private static function result(
        ?bool $open, ?bool $instruction, ?bool $attendance, bool $halfDay,
        string $reason, bool $configured, string $effectiveFrom, string $source
    ): array {
        return [
            'open'                => $open,
            'instruction'         => $instruction,
            'attendance'          => $attendance,
            'halfDay'             => $halfDay,
            'reason'              => $reason,
            'configured'          => $configured,
            'policyEffectiveFrom' => $effectiveFrom,
            'source'              => $source,
        ];
    }
}
