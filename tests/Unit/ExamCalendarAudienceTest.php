<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * CAL-D9 — WHICH CALENDAR THE DATESHEET BUILDER CONSULTS.
 *
 * The defect had two halves, and fixing only the famous one would have been
 * cosmetic:
 *
 *   AUDIENCE. `all_holiday_dates()` defaults to the 'staff' audience, so the
 *   builder avoided STAFF-only closures while cheerfully proposing exams on
 *   STUDENT-only closures — the days with no candidates in the building.
 *
 *   WEEKLY PATTERN. The view hardcoded `weekend: [0]`, so a five-day school was
 *   offered Saturdays. That was a SIXTH student-weekly authority, in JavaScript,
 *   which the PHP-only authority census could not see.
 *
 * Both are advisory: there is no server-side exam-date validation anywhere in
 * this module, so this shapes what Auto-sequence proposes and never blocks a
 * date an administrator chooses deliberately. That is the correct shape —
 * scheduling an exam on a non-working day is normal practice, handled as
 * invigilator workload rather than as an impossibility.
 *
 * Source-level assertions, because booting CodeIgniter is not available here.
 */
final class ExamCalendarAudienceTest extends TestCase
{
    private static string $ctrl = '';
    private static string $view = '';

    public static function setUpBeforeClass(): void
    {
        self::$ctrl = (string) file_get_contents(APPPATH . 'controllers/Exam.php');
        self::$view = (string) file_get_contents(APPPATH . 'views/exam/create.php');
    }

    private function code(): string
    {
        $c = preg_replace('~/\*.*?\*/~s', '', self::$ctrl);
        return (string) preg_replace('~//[^\n]*~', '', (string) $c);
    }

    // ── AUDIENCE ─────────────────────────────────────────────────────────

    /** The defect itself: a holiday read with no audience silently means staff. */
    public function test_no_holiday_read_omits_its_audience(): void
    {
        $code = $this->code();

        $this->assertSame(0, preg_match_all('~all_holiday_dates\(\s*\)~', $code),
            "all_holiday_dates() with no argument defaults to the 'staff' audience. "
            . 'On an exam surface that silently excludes student-only closures.');

        $this->assertSame(0, preg_match_all('~holidays_in_month\(\s*\$\w+,\s*\$\w+\s*\)~', $code),
            'holidays_in_month() with no audience has the same default');
    }

    public function test_the_student_audience_is_consulted(): void
    {
        $code = $this->code();
        $this->assertStringContainsString("'students'", $code,
            'an exam needs students present, so student closures must be consulted');
        $this->assertStringContainsString('student_calendar_reader', $code,
            'the canonical student calendar — not a second holiday query — is the authority '
            . 'for whether the school teaches on a date');
    }

    /**
     * And the staff side is still consulted, deliberately. An exam needs two
     * populations at once: candidates to sit it and invigilators to run it.
     */
    public function test_the_staff_audience_is_still_consulted(): void
    {
        $this->assertStringContainsString("all_holiday_dates('staff')", $this->code(),
            'dropping the staff side would propose papers on days with nobody to invigilate');
    }

    // ── DOMAIN SEPARATION ────────────────────────────────────────────────

    /**
     * Reading both AUDIENCES of the shared calendarEvents ledger is not merging
     * the domains. Merging would be reaching into the staff WORK-SCHEDULE
     * authority, which decides working time per employee.
     */
    public function test_the_exam_surface_never_reads_the_staff_work_schedule_authority(): void
    {
        $code = $this->code();
        foreach ([
            'Working_calendar_service', 'Policy_assignment_service',
            'Work_schedule_service', 'staffScheduleAssignments', 'attendancePolicy',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code,
                "Exam.php references '{$forbidden}'. Institutional holidays are shared; "
                . 'the authority that decides an employee\'s working time is not.');
        }
    }

    // ── WEEKLY PATTERN ───────────────────────────────────────────────────

    public function test_the_view_no_longer_hardcodes_sunday_as_the_weekend(): void
    {
        $this->assertStringNotContainsString('weekend: [0] }', self::$view,
            'a hardcoded Sunday cannot express a five-day school, and made the builder '
            . 'offer Saturdays to schools that are closed on them');
        $this->assertStringContainsString('$weekendDays ?? [0]', self::$view,
            'the weekly pattern must come from the server');
    }

    public function test_both_render_paths_supply_the_weekend_variable(): void
    {
        $code = $this->code();
        $this->assertSame(2, substr_count($code, "'weekendDays'"),
            'create() and edit() both render exam/create; a path that omits the variable '
            . 'silently falls back to the hardcoded Sunday');
        $this->assertSame(2, substr_count($code, '$examCal[\'dates\']'));
    }

    /**
     * The fallback is the point. If the calendar cannot be read, the builder must
     * degrade to the OLD behaviour (Sunday-only), never to an empty skip set —
     * which would propose exams on every day of the week.
     */
    public function test_an_unreadable_calendar_degrades_to_the_legacy_rule(): void
    {
        $code = $this->code();
        // BOTH render paths, counted. `assertStringContainsString` proved only
        // that ONE path had the fallback, so a mutant that broke the other
        // survived — the second path would have degraded to no skipping at all.
        $this->assertSame(2, substr_count($code, "\$examCal['calendarOk'] ? [] : [0]"),
            'create() AND edit() must both fall back to Sunday-only when the calendar '
            . 'is unreadable; a path without it degrades to no skipping at all');
        $this->assertStringContainsString('load_ok()', $code,
            'the readability signal must be consulted, not assumed');
    }

    // ── ADVISORY, NOT ENFORCEMENT ────────────────────────────────────────

    /**
     * Pinning the blast radius. If someone later adds server-side rejection of
     * exam dates, this test fails and the decision gets made deliberately —
     * because refusing a date is a product policy, not an implementation detail.
     */
    public function test_the_calendar_does_not_block_an_exam_date(): void
    {
        $code = $this->code();
        $this->assertSame(0, preg_match_all(
            '~(json_error|show_error)\([^)]*(holiday|non-working|not a working day)~i', $code),
            'exam dates must not be REFUSED on calendar grounds. Scheduling an exam on a '
            . 'non-working day is legitimate practice — it is an invigilator-workload '
            . 'matter. Blocking it is a product decision nobody has taken.');
    }

    // ── SHAPE ────────────────────────────────────────────────────────────

    public function test_the_resolver_returns_dates_and_a_readability_flag(): void
    {
        $code = $this->code();
        $this->assertStringContainsString('private function _non_scheduling_dates()', $code);
        $this->assertMatchesRegularExpression("~return \['dates' => \\\$out, 'calendarOk' => \\\$calendarOk\]~", $code,
            'callers need to tell "no closures" apart from "could not look"');
    }

    public function test_special_working_days_are_passed_so_a_declared_makeup_day_reopens(): void
    {
        // Asserted as the EXACT call, not the bare name: a mutant that renamed the
        // method to `special_working_days_in_month_OFF` still contained the name as
        // a substring and survived. Substring assertions on identifiers are worth
        // almost nothing — this is the second time that has bitten here.
        $this->assertMatchesRegularExpression(
            "~special_working_days_in_month\(\\\$y, \\\$m, 'students'\)~", $this->code(),
            'a declared makeup day must become schedulable again, not stay skipped');
    }

    public function test_the_legacy_entry_point_still_exists_for_its_callers(): void
    {
        $this->assertStringContainsString('private function _holiday_dates()', $this->code(),
            'kept as a thin delegate so no caller breaks');
    }
}
