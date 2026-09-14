<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once APPPATH . 'libraries/Student_calendar_service.php';
require_once APPPATH . 'libraries/Student_calendar_reader.php';

/**
 * CAL-P3 — THE TIMETABLE IS THE NORMAL SCHEDULE; THE CALENDAR SAYS WHETHER IT
 * OPERATES ON A DATE.
 *
 * The repository already enforces this separation in its data model, which is
 * why nothing here mutates timetable data. `timetables` documents are keyed
 * `{school}_{session}_{sectionKey}_{WEEKDAY}` — there is no per-date timetable
 * document. Suppressing "the timetable on this holiday" by touching stored data
 * would remove that subject from EVERY Monday, not from one Monday.
 *
 * So the operational answer is composed where a DATE becomes a weekday, and the
 * published timetable is left exactly as it is. These tests pin both halves:
 * the resolver answers correctly for a single date, and the integration is
 * advisory and writes nothing.
 */
final class TimetableCalendarOperationalTest extends TestCase
{
    private const SCHOOL = 'SCH_A';

    private function reader(array $doc = [], bool $throw = false): Student_calendar_reader
    {
        // FakeCalendarFs is defined by StudentCalendarLegacyEquivalenceTest.
        require_once __DIR__ . '/StudentCalendarLegacyEquivalenceTest.php';
        $fs = new FakeCalendarFs();
        $fs->docs['attendanceSettings/' . self::SCHOOL] = $doc;
        $fs->throw = $throw;
        return (new Student_calendar_reader())->init($fs, self::SCHOOL, '2026-2027');
    }

    private function week(array $offs, string $from = '2026-04-01'): array
    {
        return ['studentCalendar' => ['versions' => [
            ['effectiveFrom' => $from, 'weeklyOffs' => $offs],
        ]]];
    }

    // ── THE SINGLE-DATE RESOLVER ─────────────────────────────────────────

    public function test_a_normal_teaching_day_is_operational(): void
    {
        // 2026-06-10 is a Wednesday.
        $r = $this->reader($this->week(['Sun']))->resolveDate('2026-06-10');
        $this->assertTrue($r['instruction']);
        $this->assertSame(Student_calendar_service::REASON_WORKING, $r['reason']);
    }

    public function test_a_declared_holiday_suppresses_instruction(): void
    {
        $r = $this->reader($this->week(['Sun']))
            ->resolveDate('2026-06-10', ['holiday' => true]);
        $this->assertFalse($r['instruction']);
        $this->assertSame(Student_calendar_service::REASON_HOLIDAY, $r['reason']);
    }

    public function test_a_weekly_closure_suppresses_instruction(): void
    {
        // 2026-06-13 is a Saturday.
        $this->assertFalse(
            $this->reader($this->week(['Sat', 'Sun']))->resolveDate('2026-06-13')['instruction']);
        $this->assertTrue(
            $this->reader($this->week(['Sun']))->resolveDate('2026-06-13')['instruction'],
            'a six-day school still teaches on Saturday');
    }

    public function test_an_nth_weekday_closure_suppresses_instruction(): void
    {
        $doc = ['studentCalendar' => ['versions' => [[
            'effectiveFrom' => '2026-04-01',
            'weeklyOffs' => ['Sun'],
            'nthWeekdayOffs' => [['day' => 'Sat', 'weeks' => [2, 4]]],
        ]]]];
        $r = $this->reader($doc);
        $this->assertTrue($r->resolveDate('2026-06-06')['instruction'],  '1st Saturday teaches');
        $this->assertFalse($r->resolveDate('2026-06-13')['instruction'], '2nd Saturday closed');
    }

    /** A declared makeup day re-opens instruction on a day that would be closed. */
    public function test_a_special_working_day_restores_instruction(): void
    {
        // 2026-06-07 is a Sunday.
        $r = $this->reader($this->week(['Sun']))
            ->resolveDate('2026-06-07', ['specialWorkingDay' => true]);
        $this->assertTrue($r['instruction'],
            'a declared makeup day runs lessons, so cover on it is real');
    }

    /** A half day still teaches — it is a modifier, not a closure. */
    public function test_a_half_day_still_has_instruction(): void
    {
        $r = $this->reader($this->week(['Sun']))
            ->resolveDate('2026-06-10', ['halfDay' => true]);
        $this->assertTrue($r['instruction']);
        $this->assertTrue($r['halfDay']);
    }

    /**
     * THE DIMENSION THAT MATTERS. On an exam-only day the school is open and
     * attendance is taken, but no ordinary period runs — so there is nothing to
     * cover. Keying off `attendance` would get this exactly backwards.
     */
    public function test_an_exam_only_day_has_no_instruction_but_keeps_attendance(): void
    {
        $r = $this->reader($this->week(['Sun']))
            ->resolveDate('2026-06-10', ['examOnly' => true]);
        $this->assertFalse($r['instruction'], 'no ordinary lesson runs, so no cover is needed');
        $this->assertTrue($r['attendance'],  'but students are still marked');
        $this->assertTrue($r['open']);
    }

    public function test_a_date_outside_the_session_has_no_instruction(): void
    {
        $r = $this->reader($this->week(['Sun']))
            ->resolveDate('2026-03-31', [], ['start' => '2026-04-01', 'end' => '2027-03-31']);
        $this->assertFalse($r['instruction']);
    }

    // ── EFFECTIVE DATING AND HISTORY ─────────────────────────────────────

    public function test_a_future_policy_does_not_change_a_past_date(): void
    {
        $before = $this->reader($this->week(['Sun'], '2026-01-01'));
        $after  = $this->reader(['studentCalendar' => ['versions' => [
            ['effectiveFrom' => '2026-01-01', 'weeklyOffs' => ['Sun']],
            ['effectiveFrom' => '2027-01-01', 'weeklyOffs' => ['Sat', 'Sun']],
        ]]]);

        // 2026-06-13 is a Saturday, before the switch.
        $this->assertSame(
            $before->resolveDate('2026-06-13')['instruction'],
            $after->resolveDate('2026-06-13')['instruction'],
            'adding a 2027 five-day week must not retroactively cancel a 2026 Saturday');
        $this->assertTrue($after->resolveDate('2026-06-13')['instruction']);
        $this->assertFalse($after->resolveDate('2027-01-02')['instruction'],
            'and it must apply once its date arrives');
    }

    // ── UNCONFIGURED AND FAILURE ─────────────────────────────────────────

    public function test_an_unconfigured_tenant_still_answers_from_the_legacy_rule(): void
    {
        $r = $this->reader([]);
        $this->assertTrue($r->resolveDate('2026-06-10')['instruction'], 'Wednesday teaches');
        $this->assertFalse($r->resolveDate('2026-06-07')['instruction'], 'Sunday does not');
        $this->assertSame(Student_calendar_reader::SOURCE_LEGACY,
            $r->resolveDate('2026-06-10')['source'],
            'and it must SAY it is the legacy rule, not claim to be configured');
    }

    public function test_a_settings_read_failure_is_reported_as_unreadable(): void
    {
        $r = $this->reader([], true)->resolveDate('2026-06-10');
        $this->assertSame(Student_calendar_reader::SOURCE_UNREADABLE, $r['source'],
            '"could not check" must be distinguishable from "all clear"');
    }

    // ── TIMETABLE DATA IS NEVER TOUCHED ──────────────────────────────────

    /**
     * The whole point of the design. If any of this ever starts writing
     * timetable data, the separation has collapsed and a holiday would begin
     * editing the school's normal schedule.
     */
    public function test_the_calendar_integration_writes_no_timetable_data(): void
    {
        $sources = [
            APPPATH . 'libraries/Student_calendar_reader.php',
            APPPATH . 'libraries/Student_calendar_service.php',
        ];
        foreach ($sources as $file) {
            $code = preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($file));
            $code = (string) preg_replace('~//[^\n]*~', '', (string) $code);

            foreach (['timetables', 'timetableVersions', 'timetableVersionDays',
                      'Timetable_plan_store', 'Timetable_projection'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code,
                    basename($file) . " references '{$forbidden}'. The calendar answers "
                    . 'whether the schedule operates; it must never edit the schedule.');
            }
        }
    }

    /**
     * And the projection stays calendar-blind, deliberately. It builds one
     * document per WEEKDAY; making it calendar-aware would mean projecting per
     * date, which is a different data model and a TT-C8 change.
     */
    public function test_the_projection_remains_keyed_by_weekday_not_by_date(): void
    {
        $proj = (string) file_get_contents(APPPATH . 'libraries/Timetable_projection_service.php');
        $this->assertStringContainsString('_{$day}"', $proj,
            'the projection doc id must stay weekday-keyed — a per-date projection '
            . 'would be a timetable semantic change, not a calendar integration');
        $this->assertStringNotContainsString('Student_calendar', $proj,
            'the projection must not consult the calendar; suppression belongs at the '
            . 'consumer, where a date is turned into a weekday');
    }

    // ── THE CONSUMER INTEGRATION IS ADVISORY ─────────────────────────────

    public function test_the_cover_screen_reports_the_calendar_without_blocking(): void
    {
        $code = (string) file_get_contents(APPPATH . 'controllers/Academic.php');
        $stripped = preg_replace('~/\*.*?\*/~s', '', $code);
        $stripped = (string) preg_replace('~//[^\n]*~', '', (string) $stripped);

        $this->assertStringContainsString('_student_calendar_for_date', $stripped,
            'the cover screen must be told what the date means');
        // Counted on the RESPONSE wiring specifically. A bare count of the helper
        // went stale the moment the CAL-P3B save gate became a third caller —
        // and a stale count fails for the wrong reason instead of catching a
        // response that lost its calendar block.
        $this->assertSame(2, substr_count($stripped, "'calendar'     => \$this->_student_calendar_for_date(\$dateStr)"),
            'both responses — the empty-periods branch and the main one — must carry it');

        // It must not have become a gate.
        $this->assertSame(0, preg_match_all(
            '~json_error\([^)]*(holiday|no classes are scheduled|non-instruction)~i', $stripped),
            'reporting the calendar must not turn into refusing the request. Blocking '
            . "cover is the Substitute programme's semantics to change, not this one's.");
    }

    // ── CAL-P3B — WARN, THEN CONFIRM, BEFORE COVER ON A CLOSURE ──────────

    /**
     * Saving a cover NOTIFIES the covering teacher, so the gate has to sit
     * before the write, not after it.
     */
    public function test_saving_cover_is_gated_before_the_service_is_called(): void
    {
        $code = (string) file_get_contents(APPPATH . 'controllers/Academic.php');
        $stripped = (string) preg_replace('~/\*.*?\*/~s', '', $code);
        $stripped = (string) preg_replace('~//[^\n]*~', '', $stripped);

        // The CALL, not the name. Anchoring on the bare identifier found the
        // method DEFINITION, which sits before saveSubstitute() whether or not
        // the gate is actually invoked — so a mutant that removed the call
        // survived. Fifth substring collision in this programme.
        $gatePos = strpos($stripped, '$gate = $this->_non_instruction_cover_gate(');
        $savePos = strpos($stripped, 'saveSubstitute(');
        $this->assertNotFalse($gatePos, 'the gate must actually be CALLED, not merely defined');
        $this->assertNotFalse($savePos);
        $this->assertLessThan($savePos, $gatePos,
            'the calendar gate must run BEFORE saveSubstitute() — a cover that has '
            . 'already been written has already notified somebody');
    }

    /** Confirmation, not refusal: a special session on a closure day is real. */
    public function test_the_gate_confirms_rather_than_refuses(): void
    {
        $code = (string) file_get_contents(APPPATH . 'controllers/Academic.php');
        $this->assertStringContainsString('confirm_non_instruction', $code,
            'the operator must be able to proceed deliberately — a revision class '
            . 'before a board exam is a legitimate cover on a closure day');
        $this->assertStringContainsString('409', $code,
            'the refusal must be distinguishable from a validation error');
    }

    /**
     * FAIL-OPEN, DELIBERATELY. Only a definite `false` gates. An unconfigured
     * tenant or an unreadable calendar (both null) must pass straight through —
     * a calendar outage must not stop a school arranging cover for a lesson that
     * is genuinely running.
     */
    public function test_only_a_definite_non_instruction_answer_gates(): void
    {
        $code = (string) file_get_contents(APPPATH . 'controllers/Academic.php');
        $this->assertStringContainsString(
            "if ((\$cal['instruction'] ?? null) !== false) return null;", $code,
            'null (unconfigured, or unreadable) must NOT gate — only a definite false');
    }

    /** The confirmed decision must leave a trace. */
    public function test_a_confirmed_override_is_recorded(): void
    {
        $code = (string) file_get_contents(APPPATH . 'controllers/Academic.php');
        $this->assertStringContainsString('cover confirmed on a non-instruction date', $code,
            'an override must be visible afterwards, not indistinguishable from an '
            . 'ordinary cover');
    }

    /** The client must never set the confirmation flag by itself. */
    public function test_the_client_only_confirms_after_asking_a_human(): void
    {
        $v = (string) file_get_contents(APPPATH . 'views/academic/index.php');
        $this->assertStringContainsString('window.confirm(', $v,
            'the retry must be gated on a human answering');
        $this->assertStringContainsString('if(confirmed) payload.confirm_non_instruction', $v,
            'the flag must be conditional on that answer, never sent by default');
        $this->assertStringContainsString('_sendCover(false)', $v,
            'the first attempt must be unconfirmed, so the server gets to object');
    }

    /**
     * The calendar loading skeleton must cover the area it stands in for.
     *
     * `acShim()` inserts ONE wrapper element into its target. `#calGrid` is
     * itself `repeat(7,1fr)`, so that wrapper became a single grid ITEM and the
     * skeleton rendered at one-seventh width, hugging the left edge while the
     * real calendar it was standing in for spans the full row.
     */
    public function test_the_loading_skeleton_spans_its_grid_container(): void
    {
        $v = (string) file_get_contents(APPPATH . 'views/academic/index.php');

        $this->assertMatchesRegularExpression('~\.ac-shim\{[^}]*grid-column:1/-1~', $v,
            'a shim dropped into a grid container must span every column, or it '
            . 'renders as a narrow strip instead of a placeholder');
        $this->assertMatchesRegularExpression('~\.ac-cal-grid\{[^}]*grid-template-columns:repeat\(7,1fr\)~', $v,
            'this assertion only means anything while the target really is a '
            . '7-column grid — pinned so the pair cannot drift apart');
    }

    /**
     * THE DEFECT `php -l` CANNOT SEE.
     *
     * Writing this block through a PHP patch script turned `'\n\n'` into a REAL
     * newline inside a JavaScript string literal — an unterminated string, and a
     * syntax error that would have broken the whole script block. `php -l` passed
     * happily, because it does not parse the JavaScript it is emitting.
     *
     * Pinned as the two-character escape.
     */
    public function test_the_confirm_dialog_uses_escaped_newlines_not_real_ones(): void
    {
        $v = (string) file_get_contents(APPPATH . 'views/academic/index.php');

        $this->assertStringContainsString("'\\n\\nArrange cover anyway?'", $v,
            'the newline must be the two-character escape sequence');

        // Assert the BROKEN form is absent, rather than scanning every
        // window.confirm() in the file — the first attempt at this regex matched
        // other, legitimate confirm() calls elsewhere in a 5,000-line view and
        // failed against correct code.
        $this->assertStringNotContainsString("d.message+'\n", $v,
            'a raw newline inside a single-quoted JS literal is an unterminated '
            . 'string — a syntax error that php -l cannot detect');
        $this->assertStringNotContainsString("d.message + '\n", $v);
    }

    /** The Substitute service itself must stay untouched by this programme. */
    public function test_the_substitute_service_write_path_is_not_modified(): void
    {
        $svc = (string) file_get_contents(APPPATH . 'libraries/Substitute_service.php');
        foreach (['Student_calendar', 'confirm_non_instruction', '_student_calendar_for_date'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $svc,
                "Substitute_service references '{$forbidden}'. The Substitute programme "
                . 'owns what a cover is and how it is written; the Calendar only tells '
                . 'the operator what the date means beforehand.');
        }
    }

    public function test_the_cover_screen_uses_instruction_and_the_student_audience(): void
    {
        $code = (string) file_get_contents(APPPATH . 'controllers/Academic.php');
        $this->assertStringContainsString("holidays_in_month(\$y, \$m, 'students')", $code,
            'a staff-only closure is exactly when cover IS needed — the student '
            . 'audience must be explicit');
        $this->assertStringContainsString("\$out['instruction'] = \$r['instruction']", $code,
            'cover depends on whether a LESSON runs, not on whether a register is taken');
    }
}
