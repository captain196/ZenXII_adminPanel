<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Student_calendar_service.php';

/**
 * Student_calendar_reader — the thin I/O layer over the student academic calendar.
 *
 * `Student_calendar_service` is deliberately pure: it takes a version and a set
 * of per-date exceptions and returns a classification. Something has to fetch
 * the version list and drive it across a month. That is this class, and it is
 * the ONLY thing that reads student calendar configuration.
 *
 * WHERE THE CONFIGURATION LIVES
 * -----------------------------
 *   attendanceSettings/{schoolId}.studentCalendar.versions[]
 *
 * Deliberately the SAME document that already holds `working_days`. Three
 * reasons: it is already school-scoped and already the attendance settings
 * home; a single read answers both "what is the calendar" and "what did the
 * school think it configured" (see divergence() below); and writes there
 * already use merge=true, so adding a key cannot disturb a neighbour.
 *
 * BEHAVIOUR NEUTRALITY IS THE WHOLE POINT (Decision 1, option C-prime)
 * -------------------------------------------------------------------
 * A tenant that has configured nothing resolves through
 * `Student_calendar_service::legacyStudentPattern()` — Sunday closed, nothing
 * else — which reproduces `attendance_helper::nw_days_from_holidays()` EXACTLY.
 * Deploying this changes no school's behaviour on day one.
 *
 * The difference from before is honesty, not behaviour: the rule is now a
 * named, inspectable, effective-dated policy object rather than a literal
 * buried in a helper, and `source` reports which of the two produced the answer.
 *
 * WHY THE STALE `working_days` IS NOT TRUSTED
 * -------------------------------------------
 * `attendanceSettings.working_days` has a UI, a save endpoint and a health
 * check, and NOTHING has ever read it to decide a working day (CAL-D10). A
 * school may well have set "Mon-Fri" years ago and been silently running a
 * six-day week ever since. Honouring it now would retroactively change what
 * every Saturday means.
 *
 * So it is treated as EVIDENCE, never as truth: divergence() reports the
 * mismatch so an administrator can be shown it and choose an effective date.
 * Suggestion -> review -> school decision -> calendar authority. Identical to
 * the rule the programme applies to government holiday lists.
 *
 * PER-DAY EFFECTIVE DATING — THE HISTORICAL GUARANTEE
 * ---------------------------------------------------
 * resolveMonth() selects the version in force ON EACH DATE, not one version for
 * the whole month and not the version in force today. A month in the past
 * therefore resolves against the policy that was live then, even after the
 * school changes its week. This is what stops a future policy change from
 * reinterpreting historical attendance.
 *
 * STUDENTS ONLY. Every Holiday_service call made on behalf of this class passes
 * the 'students' audience, and department/exceptStaff scoping — a staff-only
 * concept — is never applied. This class must never read a work schedule.
 */
class Student_calendar_reader
{
    const SETTINGS_COLLECTION = 'attendanceSettings';
    const CONFIG_KEY          = 'studentCalendar';

    /** Where a resolved answer came from. */
    const SOURCE_CONFIGURED = 'configured';
    const SOURCE_LEGACY     = 'legacy';
    const SOURCE_UNREADABLE = 'unreadable';

    /** @var object|null Firestore_service (school-scoped) */
    private $fs = null;
    private string $schoolId = '';
    private string $session  = '';

    private bool  $loaded    = false;
    private bool  $loadOk    = false;
    private array $versions  = [];
    private array $workingDays = [];

    public function init($fs, string $schoolId, string $session): self
    {
        $this->fs       = $fs;
        $this->schoolId = $schoolId;
        $this->session  = $session;
        $this->loaded   = false;
        $this->loadOk   = false;
        $this->versions = [];
        $this->workingDays = [];
        return $this;
    }

    /**
     * Was the configuration actually readable this request?
     *
     * Mirrors Holiday_service::load_ok(). A caller that reasons negatively over
     * the calendar needs to tell "this school configured nothing" apart from
     * "I could not look" — the two must never collapse into one answer.
     */
    public function load_ok(): bool
    {
        $this->_load();
        return $this->loadOk;
    }

    /** Has the school configured a student calendar at all? */
    public function isConfigured(): bool
    {
        $this->_load();
        return !empty($this->versions);
    }

    /** The stored version list, newest last. Never null. */
    public function versions(): array
    {
        $this->_load();
        return $this->versions;
    }

    /** The stale, never-enforced `working_days` setting. Evidence only. */
    public function configuredWorkingDays(): array
    {
        $this->_load();
        return $this->workingDays;
    }

    /**
     * The version in force on a date, or the explicit legacy pattern.
     *
     * Returns [$version, $source]. The legacy pattern is returned as a real,
     * dated version object rather than as a silent special case, so every
     * downstream answer can name the policy that produced it.
     */
    public function versionFor(string $date): array
    {
        $this->_load();

        $v = Student_calendar_service::effectiveVersion($this->versions, $date);
        if ($v !== null) {
            return [$v, self::SOURCE_CONFIGURED];
        }

        // Behaviour-neutral fallback. Dated from the epoch so it covers every
        // historical date a school could ask about, and so a configured version
        // starting later always wins on and after its own effectiveFrom.
        return [
            Student_calendar_service::legacyStudentPattern('1970-01-01'),
            $this->loadOk ? self::SOURCE_LEGACY : self::SOURCE_UNREADABLE,
        ];
    }

    /**
     * Resolve a whole month into the `[day => reason]` non-working map that
     * student attendance already consumes.
     *
     * The output shape is deliberately identical to
     * `nw_days_from_holidays()` + special-working-day subtraction, so this can
     * replace that pipeline without any caller changing shape.
     *
     * @param array $holidayMap [dayOfMonth => name] from Holiday_service ('students')
     * @param array $swdMap     [dayOfMonth => name] special working days ('students')
     * @param array $sessionWin optional ['start' => ISO, 'end' => ISO]
     *
     * @return array{nonWorking: array<int,string>, source: string,
     *               versions: array<int,string>, configured: bool}
     */
    public function resolveMonth(
        int $year,
        int $monthNum,
        array $holidayMap,
        array $swdMap,
        array $sessionWin = []
    ): array {
        $this->_load();

        $daysInMonth = (int) cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);
        $nonWorking  = [];
        $seen        = [];
        $source      = $this->isConfigured() ? self::SOURCE_CONFIGURED
                     : ($this->loadOk ? self::SOURCE_LEGACY : self::SOURCE_UNREADABLE);

        for ($d = 1; $d <= $daysInMonth; $d++) {
            $date = sprintf('%04d-%02d-%02d', $year, $monthNum, $d);

            // PER-DAY selection — the historical guarantee. A month spanning a
            // policy change resolves each of its days against the policy that
            // was actually in force on that day.
            [$version, $vSource] = $this->versionFor($date);
            $seen[(string) ($version['effectiveFrom'] ?? '')] = true;

            $r = Student_calendar_service::resolveForDate(
                $version,
                $date,
                [
                    'holiday'           => isset($holidayMap[$d]),
                    'specialWorkingDay' => isset($swdMap[$d]),
                ],
                $sessionWin
            );

            // NULL means the resolver refused to answer (unconfigured, or an
            // unusable input). Treat it as "not a declared non-working day" —
            // which is exactly what the legacy pipeline did — rather than
            // inventing a closure. The `source` field carries the uncertainty.
            if ($r['attendance'] === false) {
                $nonWorking[$d] = $this->_label($r, $holidayMap, $d, $date);
            }
        }

        return [
            'nonWorking' => $nonWorking,
            'source'     => $source,
            'versions'   => array_values(array_filter(array_keys($seen), fn($k) => $k !== '')),
            'configured' => $this->isConfigured(),
        ];
    }

    /**
     * What does ONE date mean for students?
     *
     * The single-date companion to resolveMonth(). Consumers that ask an
     * operational question about a specific day — "is instruction running on the
     * 14th?" — should not have to resolve a whole month and then index into it,
     * because doing so invites each of them to re-derive the answer slightly
     * differently and become authority number seven.
     *
     * Exceptions are PASSED IN, exactly as elsewhere: this class reads settings
     * and nothing else, and Holiday_service remains the only reader of
     * `calendarEvents`.
     *
     * @param array $exceptions holiday / specialWorkingDay / halfDay / examOnly
     * @return array the resolver's own result, plus `source`
     */
    public function resolveDate(string $date, array $exceptions = [], array $session = []): array
    {
        $this->_load();

        [$version, $vSource] = $this->versionFor($date);
        $r = Student_calendar_service::resolveForDate($version, $date, $exceptions, $session);
        $r['source'] = $this->isConfigured() ? self::SOURCE_CONFIGURED
                     : ($this->loadOk ? self::SOURCE_LEGACY : self::SOURCE_UNREADABLE);
        return $r;
    }

    /**
     * CAL-D10 — does the school's saved `working_days` disagree with the
     * calendar that is actually running?
     *
     * Read-only. Changes nothing, applies nothing. Its entire job is to let an
     * administrator be told the truth: "you saved Mon-Fri, attendance has been
     * running a six-day week, choose a date to apply it from."
     *
     * @return array|null null when there is nothing to report
     */
    public function divergence(): ?array
    {
        $this->_load();
        if (!$this->loadOk) return null;

        $saved = $this->workingDays;
        if (empty($saved) || !is_array($saved)) return null;

        // `working_days` lists the days the school says it WORKS; the calendar
        // stores the days it is CLOSED. Compare on the closed set.
        $savedTokens = [];
        foreach ($saved as $d) {
            $t = ucfirst(strtolower(substr((string) $d, 0, 3)));
            if (in_array($t, Student_calendar_service::DAYS, true)) $savedTokens[] = $t;
        }
        if (empty($savedTokens)) return null;

        $savedOffs = array_values(array_diff(Student_calendar_service::DAYS, $savedTokens));

        [$version, $source] = $this->versionFor(date('Y-m-d'));
        $activeOffs = is_array($version['weeklyOffs'] ?? null)
            ? array_values($version['weeklyOffs']) : [];

        sort($savedOffs);
        sort($activeOffs);
        if ($savedOffs === $activeOffs) return null;

        return [
            'savedWorkingDays'   => $savedTokens,
            'savedClosedDays'    => $savedOffs,
            'activeClosedDays'   => $activeOffs,
            'activeSource'       => $source,
            'activeEffectiveFrom'=> (string) ($version['effectiveFrom'] ?? ''),
            // Deliberately NOT applied. The school decides, and picks the date.
            'requiresDecision'   => true,
            'message'            => 'Your saved attendance settings list a '
                . count($savedTokens) . '-day school week, but student attendance is '
                . 'currently running with ' . (empty($activeOffs) ? 'no weekly closure'
                    : implode(' and ', $activeOffs) . ' closed')
                . '. This setting has never been applied to attendance. Choose the date '
                . 'from which the new school week should take effect — past attendance '
                . 'will not be changed.',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────

    /** Best label for a non-working day, preserving today's wording. */
    private function _label(array $r, array $holidayMap, int $day, string $date): string
    {
        if ($r['reason'] === Student_calendar_service::REASON_HOLIDAY) {
            $n = $holidayMap[$day] ?? '';
            return (is_string($n) && $n !== '') ? $n : 'Holiday';
        }
        if ($r['reason'] === Student_calendar_service::REASON_OUTSIDE_SESSION) {
            return 'Outside session';
        }
        // Weekly / nth-weekday closure. The legacy pipeline labelled a closed
        // Sunday exactly 'Sunday' and existing UI copy reads that, so the day
        // name is preserved rather than replaced with an internal token.
        $tok = Student_calendar_service::dayToken($date);
        return Student_calendar_service::DAYS_LONG[$tok] ?? 'Weekly off';
    }

    private function _load(): void
    {
        if ($this->loaded) return;
        $this->loaded = true;

        if ($this->fs === null || $this->schoolId === '') {
            $this->loadOk = false;
            return;
        }

        try {
            $doc = $this->fs->get(self::SETTINGS_COLLECTION, $this->schoolId);
            $doc = is_array($doc) ? $doc : [];

            $cal = $doc[self::CONFIG_KEY] ?? null;
            $this->versions = (is_array($cal) && is_array($cal['versions'] ?? null))
                ? array_values($cal['versions']) : [];

            $this->workingDays = is_array($doc['working_days'] ?? null)
                ? array_values($doc['working_days']) : [];

            $this->loadOk = true;
        } catch (\Throwable $e) {
            // Report the failure; do NOT invent a calendar. Callers that need to
            // fail closed read load_ok(); callers that must keep the school day
            // running fall through to the legacy pattern, which is what they
            // were already doing before this class existed.
            if (function_exists('log_message')) {
                log_message('error', 'Student_calendar_reader: settings unreadable for '
                    . $this->schoolId . ' — ' . $e->getMessage());
            }
            $this->versions    = [];
            $this->workingDays = [];
            $this->loadOk      = false;
        }
    }
}
