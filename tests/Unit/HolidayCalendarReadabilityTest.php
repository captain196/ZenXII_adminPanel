<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ACAD-D17 / ACAD-D18 — "not a holiday" must mean not a holiday.
 *
 * STUDENT AND STAFF CALENDARS ARE DIFFERENT, AND THE SYSTEM ALREADY KNOWS IT.
 * calendarEvents carries `appliesTo` (['students'] | ['staff'] | [] = both), and
 * the two attendance surfaces resolve it separately and deliberately:
 *
 *   students -> Attendance::_resolve_non_working_days() asks
 *               holidays_in_month($y, $m, 'students')
 *   staff    -> Staff_attendance_* ask with 'staff', plus department and
 *               exceptStaff scoping, which the student side must never apply
 *
 * so a Teacher Training Day never becomes a student holiday, and a student
 * vacation never silently pays staff. That separation is sound and is NOT what
 * this test is about — it is pinned here only so a later change cannot quietly
 * collapse the two audiences back together.
 *
 * WHAT WAS BROKEN IS THE READ UNDERNEATH BOTH.
 *
 * Holiday_service::_query_firestore() read the calendar with
 * `$this->fs->schoolWhere('calendarEvents', [])`. That returns [] for a missing
 * index, a network failure, a 403 and a 429 alike, and none of them throw — so
 * every holiday silently vanished and the catch that records the failure never
 * fired. `$_loadedOk` was then set TRUE on line "success", and with it:
 *
 *     public function load_ok(): bool { ...; return $this->_loadedOk === true; }
 *
 * load_ok() exists for exactly one purpose. Staff_attendance_finaliser:
 *
 *     // FAIL CLOSED (watertight 2026-07-20): if the holiday calendar could not
 *     // be READ ... do NOT persist any auto-absent this run — otherwise a real
 *     // holiday would be baked into payroll as Absent and never self-correct.
 *     if (!$this->ci->holiday_service->load_ok()) { ...skip run... }
 *
 * The guard was correctly written and simply never told. On a transient read
 * failure it saw true, ran against an empty calendar, and auto-marked a real
 * holiday Absent — into payroll, by its own author's description permanently.
 *
 * The fix is one line of read plumbing: schoolWhereOrFail() makes the catch
 * reachable, so _loadedOk becomes truthful and the existing guard works as
 * designed. No payroll logic changed.
 *
 * ACAD-D18 is the student half. Attendance::save's holiday gate is also read
 * negatively and also opened silently. Its behaviour is deliberately NOT
 * changed — the house rule for live marking is that a calendar failure must
 * never break the school day — but the uncertainty is now logged instead of
 * being invisible. Whether live marking should instead be denied is an owner
 * policy decision and is left to the owner.
 */
final class HolidayCalendarReadabilityTest extends TestCase
{
    private function file(string $rel): string
    {
        $p = __DIR__ . '/../../' . $rel;
        $this->assertFileExists($p);
        return (string) file_get_contents($p);
    }

    private function codeOnly(string $src): string
    {
        $s = preg_replace('~/\*.*?\*/~s', '', $src);
        return (string) preg_replace('~^\s*//.*$~m', '', (string) $s);
    }

    // -- the audience separation (pinned, not changed) --------------------

    public function test_student_attendance_asks_for_the_student_audience(): void
    {
        $c = $this->codeOnly($this->file('application/controllers/Attendance.php'));

        $this->assertMatchesRegularExpression(
            "~holidays_in_month\(\\\$year, \\\$monthNum, 'students'\)~", $c,
            'student attendance must ask for the STUDENT audience, or it inherits '
            . "staff-only events (a Teacher Training Day) as student holidays");

        $this->assertMatchesRegularExpression(
            "~special_working_days_in_month\(\\\$year, \\\$monthNum, 'students'\)~", $c,
            'a student makeup day must be resolved for students too, or it stays '
            . 'shaded as a holiday and cannot be marked');
    }

    public function test_the_two_audiences_remain_distinct_values(): void
    {
        $cal = $this->codeOnly($this->file('application/libraries/Calendar_service.php'));

        $this->assertMatchesRegularExpression(
            "~VALID_APPLIES_TO = \['students',\s*'staff'\]~", $cal,
            'the applicability axis must keep both audiences; collapsing it would '
            . 'make every staff event a student holiday and vice versa');
    }

    /** Staff-only scoping must not leak into the student resolution. */
    public function test_student_resolution_passes_no_department_or_staff_scope(): void
    {
        $c = $this->codeOnly($this->file('application/controllers/Attendance.php'));

        $this->assertDoesNotMatchRegularExpression(
            "~holidays_in_month\([^)]*'students'\s*,\s*\\\$~", $c,
            'department / exceptStaff scoping is a staff-only concept — passing it '
            . 'on the student side would filter student holidays by staff department');
    }

    // -- ACAD-D17: the read under both -----------------------------------

    public function test_the_calendar_read_is_fail_loud(): void
    {
        $h = $this->codeOnly($this->file('application/libraries/Holiday_service.php'));

        $this->assertStringContainsString("schoolWhereOrFail('calendarEvents'", $h,
            'the calendar read must distinguish a failed read from an empty '
            . 'calendar, or load_ok() cannot tell the truth');

        $this->assertStringNotContainsString("schoolWhere('calendarEvents'", $h,
            'the silent read is back');
    }

    /** The failure flag must still be set, and the punch path must still fail open. */
    public function test_a_failed_read_is_recorded_but_does_not_break_punches(): void
    {
        $h = $this->codeOnly($this->file('application/libraries/Holiday_service.php'));

        $i = strpos($h, 'catch');
        $this->assertNotFalse($i);

        $this->assertMatchesRegularExpression(
            '~\$this->_loadedOk\s*=\s*false;~', $h,
            'the read failure must be recorded, or load_ok() reports success');

        $this->assertMatchesRegularExpression(
            '~return \[\[\], \[\], \[\]\];~', $h,
            'the catch must still return empty maps so a calendar failure cannot '
            . 'break a punch — fail open for the flow, fail loud for the flag');
    }

    /** whereOrFail must exist and must not have changed where(). */
    public function test_the_fail_loud_helper_exists_without_changing_where(): void
    {
        $fs = $this->codeOnly($this->file('application/libraries/Firestore_service.php'));

        $this->assertStringContainsString('public function whereOrFail(', $fs);
        $this->assertStringContainsString('public function schoolWhereOrFail(', $fs);

        $i = strpos($fs, 'public function where(');
        $this->assertNotFalse($i, 'where() is gone');
        $this->assertStringContainsString('return [];', substr($fs, $i, 700),
            'where() must keep returning [] — hundreds of callers read that as '
            . '"no rows"');
    }

    /** The guard this all exists to serve must still be in place. */
    public function test_the_payroll_finaliser_still_fails_closed_on_load_ok(): void
    {
        $f = $this->codeOnly($this->file('application/libraries/Staff_attendance_finaliser.php'));

        $this->assertMatchesRegularExpression(
            '~if \(!\$this->ci->holiday_service->load_ok\(\)\)~', $f,
            'the fail-closed payroll guard is gone — without it an unreadable '
            . 'calendar bakes a real holiday into payroll as Absent');
    }

    // -- ACAD-D18: the student write gate --------------------------------

    public function test_the_student_holiday_gate_reports_when_it_could_not_evaluate(): void
    {
        $c = $this->codeOnly($this->file('application/controllers/Attendance.php'));

        // Asserting the helper merely EXISTS is not enough — leaving the method
        // defined while the gate hard-codes $holidayCheckOk = true keeps the file
        // matching and the uncertainty invisible again. Pin the call site.
        $this->assertMatchesRegularExpression(
            '~\$holidayCheckOk\s*=\s*\$this->_holiday_calendar_readable\(\);~', $c,
            'the holiday write gate must actually ASK whether the calendar was '
            . 'readable, not assume it was');

        $this->assertMatchesRegularExpression(
            '~load_ok\(\)~', $c,
            'readability must come from load_ok(), not from an empty map');

        $i = strpos($c, 'Cannot mark attendance on a declared holiday');
        $this->assertNotFalse($i, 'the holiday gate is gone');

        $this->assertMatchesRegularExpression(
            '~if \(!\$holidayCheckOk\)~', substr($c, $i, 700),
            'an unevaluated holiday gate must be logged; silently allowing the '
            . 'mark is what let attendance be recorded on a real holiday');
    }

    /**
     * The gate's BEHAVIOUR is deliberately unchanged pending an owner decision.
     * Pin that, so flipping it to deny is a conscious act rather than a drift.
     */
    public function test_live_marking_is_still_allowed_when_the_calendar_is_unreadable(): void
    {
        $c = $this->codeOnly($this->file('application/controllers/Attendance.php'));

        $i = strpos($c, 'Cannot mark attendance on a declared holiday');
        $this->assertNotFalse($i);
        $region = substr($c, max(0, $i - 400), 900);

        $this->assertDoesNotMatchRegularExpression(
            '~if \(!\$holidayCheckOk\)\s*\{\s*return \$this->json_error~', $region,
            'live marking now DENIES on an unreadable calendar. That may well be '
            . 'the right policy, but it blocks a teacher from marking a genuine '
            . 'working day and contradicts the house rule that a calendar failure '
            . 'must never break the school day — it needs an explicit owner '
            . 'decision, not a silent change.');
    }
}
