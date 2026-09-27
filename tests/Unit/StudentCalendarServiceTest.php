<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once APPPATH . 'libraries/Student_calendar_service.php';

/**
 * THE STUDENT ACADEMIC CALENDAR AUTHORITY.
 *
 * These tests exist to prove five properties, not to exercise happy paths:
 *
 *   1. Every legitimate school week a real tenant might run is expressible.
 *   2. Changing the pattern CANNOT change what a past date meant.
 *   3. Precedence is deterministic and matches observed repository behaviour.
 *   4. An unconfigured tenant gets NULL, never an invented calendar.
 *   5. A half day is a TEACHING day, not a closure.
 */
final class StudentCalendarServiceTest extends TestCase
{
    private function v(array $weeklyOffs, array $nth = [], string $from = '2026-04-01'): array
    {
        return ['effectiveFrom' => $from, 'weeklyOffs' => $weeklyOffs, 'nthWeekdayOffs' => $nth];
    }

    private function attends(array $version, string $date, array $exc = [], array $sess = []): ?bool
    {
        return Student_calendar_service::resolveForDate($version, $date, $exc, $sess)['attendance'];
    }

    // ── 1. SCHOOL WEEK PATTERNS ──────────────────────────────────────────

    /** Sunday 2026-06-07 … Saturday 2026-06-13 — a full known week. */
    public function test_six_day_week_sunday_only_closure(): void
    {
        $v = $this->v(['Sun']);
        $this->assertFalse($this->attends($v, '2026-06-07'), 'Sunday must be closed');
        foreach (['2026-06-08','2026-06-09','2026-06-10','2026-06-11','2026-06-12','2026-06-13'] as $d) {
            $this->assertTrue($this->attends($v, $d), "$d must be a teaching day in a 6-day week");
        }
    }

    public function test_five_day_week_saturday_and_sunday_closed(): void
    {
        $v = $this->v(['Sat', 'Sun']);
        $this->assertFalse($this->attends($v, '2026-06-07'), 'Sunday closed');
        $this->assertFalse($this->attends($v, '2026-06-13'), 'Saturday closed');
        $this->assertTrue($this->attends($v, '2026-06-08'), 'Monday open');
        $this->assertTrue($this->attends($v, '2026-06-12'), 'Friday open');
    }

    public function test_four_day_week_is_expressible(): void
    {
        $v = $this->v(['Fri', 'Sat', 'Sun']);
        $this->assertFalse($this->attends($v, '2026-06-12'), 'Friday closed');
        $this->assertTrue($this->attends($v, '2026-06-11'), 'Thursday open');
    }

    /**
     * 2nd and 4th Saturday off — the pattern that motivated nth-weekday support
     * and that a flat weekday list cannot express.
     *
     * June 2026 Saturdays: 6th(1st) 13th(2nd) 20th(3rd) 27th(4th).
     */
    public function test_second_and_fourth_saturday_closure(): void
    {
        $v = $this->v(['Sun'], [['day' => 'Sat', 'weeks' => [2, 4]]]);
        $this->assertTrue($this->attends($v, '2026-06-06'),  '1st Saturday works');
        $this->assertFalse($this->attends($v, '2026-06-13'), '2nd Saturday off');
        $this->assertTrue($this->attends($v, '2026-06-20'),  '3rd Saturday works');
        $this->assertFalse($this->attends($v, '2026-06-27'), '4th Saturday off');
    }

    public function test_alternate_saturday_via_first_third_fifth(): void
    {
        $v = $this->v(['Sun'], [['day' => 'Sat', 'weeks' => [1, 3, 5]]]);
        $this->assertFalse($this->attends($v, '2026-06-06'));
        $this->assertTrue($this->attends($v, '2026-06-13'));
        $this->assertFalse($this->attends($v, '2026-06-20'));
    }

    /** A 5th occurrence exists in some months and must be addressable. */
    public function test_fifth_occurrence_is_reachable(): void
    {
        // 2026-05-30 is the 5th Saturday of May 2026.
        $this->assertSame(5, Student_calendar_service::occurrenceInMonth('2026-05-30'));
        $v = $this->v(['Sun'], [['day' => 'Sat', 'weeks' => [5]]]);
        $this->assertFalse($this->attends($v, '2026-05-30'));
        $this->assertTrue($this->attends($v, '2026-05-23'), '4th Saturday unaffected');
    }

    // ── 2. HISTORICAL INVARIANCE — the property that matters most ────────

    /**
     * THE CORE SAFETY PROPERTY.
     *
     * A school runs Mon–Fri, then switches to Mon–Sat from 2027-01-01. Every
     * date BEFORE the switch must resolve exactly as it did before the new
     * version existed — checked over 300 CONSECUTIVE dates, not samples.
     */
    public function test_adding_a_future_version_changes_no_past_date(): void
    {
        $before = [$this->v(['Sat', 'Sun'], [], '2026-04-01')];
        $after  = array_merge($before, [$this->v(['Sun'], [], '2027-01-01')]);

        $changed = [];
        $d = new DateTimeImmutable('2026-04-01');
        for ($i = 0; $i < 300; $i++) {
            $date = $d->modify("+{$i} day")->format('Y-m-d');
            if ($date >= '2027-01-01') continue;   // only the protected past

            $a = Student_calendar_service::resolveForDate(
                Student_calendar_service::effectiveVersion($before, $date), $date);
            $b = Student_calendar_service::resolveForDate(
                Student_calendar_service::effectiveVersion($after, $date), $date);

            if ($a['attendance'] !== $b['attendance'] || $a['reason'] !== $b['reason']) {
                $changed[] = $date;
            }
        }

        $this->assertSame([], $changed,
            'Adding a future school week silently changed what these past dates meant: '
            . implode(', ', array_slice($changed, 0, 10)));
    }

    /** And the new version DOES take effect once its date arrives. */
    public function test_the_new_version_applies_from_its_effective_date(): void
    {
        $versions = [
            $this->v(['Sat', 'Sun'], [], '2026-04-01'),
            $this->v(['Sun'],        [], '2027-01-01'),
        ];
        // 2026-12-26 and 2027-01-02 are both Saturdays.
        $this->assertFalse($this->attends(
            Student_calendar_service::effectiveVersion($versions, '2026-12-26'), '2026-12-26'),
            'Saturday before the switch is closed');
        $this->assertTrue($this->attends(
            Student_calendar_service::effectiveVersion($versions, '2027-01-02'), '2027-01-02'),
            'Saturday after the switch is a teaching day');
    }

    public function test_effective_date_boundary_is_inclusive(): void
    {
        $versions = [$this->v(['Sun'], [], '2026-04-01'), $this->v(['Sat','Sun'], [], '2026-06-13')];
        // 2026-06-13 is a Saturday and is the effectiveFrom itself.
        $this->assertFalse($this->attends(
            Student_calendar_service::effectiveVersion($versions, '2026-06-13'), '2026-06-13'),
            'the effectiveFrom date itself must use the NEW version');
        $this->assertTrue($this->attends(
            Student_calendar_service::effectiveVersion($versions, '2026-06-06'), '2026-06-06'),
            'the Saturday before still uses the old version');
    }

    public function test_repeated_edits_resolve_to_the_latest_applicable(): void
    {
        $versions = [
            $this->v(['Sun'],               [], '2026-04-01'),
            $this->v(['Sat','Sun'],         [], '2026-07-01'),
            $this->v(['Fri','Sat','Sun'],   [], '2026-09-01'),
        ];
        $this->assertTrue($this->attends(
            Student_calendar_service::effectiveVersion($versions, '2026-06-12'), '2026-06-12'));
        $this->assertTrue($this->attends(
            Student_calendar_service::effectiveVersion($versions, '2026-08-14'), '2026-08-14'));
        $this->assertFalse($this->attends(
            Student_calendar_service::effectiveVersion($versions, '2026-09-04'), '2026-09-04'),
            'the third version closes Fridays');
    }

    // ── 3. PRECEDENCE ────────────────────────────────────────────────────

    public function test_special_working_day_beats_a_weekly_off(): void
    {
        $r = Student_calendar_service::resolveForDate(
            $this->v(['Sun']), '2026-06-07', ['specialWorkingDay' => true]);
        $this->assertTrue($r['attendance'], 'a declared makeup Sunday must be markable');
        $this->assertSame(Student_calendar_service::REASON_SPECIAL_WORKING, $r['reason']);
    }

    public function test_special_working_day_beats_a_holiday(): void
    {
        $r = Student_calendar_service::resolveForDate(
            $this->v(['Sun']), '2026-06-10',
            ['holiday' => true, 'specialWorkingDay' => true]);
        $this->assertTrue($r['attendance'],
            'SWD must override a holiday — this mirrors _resolve_non_working_days(), '
            . 'which subtracts special working days from the holiday union');
    }

    public function test_holiday_beats_a_normal_teaching_day(): void
    {
        $r = Student_calendar_service::resolveForDate(
            $this->v(['Sun']), '2026-06-10', ['holiday' => true]);
        $this->assertFalse($r['open']);
        $this->assertFalse($r['attendance']);
        $this->assertSame(Student_calendar_service::REASON_HOLIDAY, $r['reason']);
    }

    public function test_holiday_falling_on_a_weekly_off_stays_closed(): void
    {
        $r = Student_calendar_service::resolveForDate(
            $this->v(['Sun']), '2026-06-07', ['holiday' => true]);
        $this->assertFalse($r['attendance'], 'still closed, no double-negative');
    }

    public function test_outside_the_session_window_overrides_everything(): void
    {
        $r = Student_calendar_service::resolveForDate(
            $this->v(['Sun']), '2026-03-31',
            ['specialWorkingDay' => true],
            ['start' => '2026-04-01', 'end' => '2027-03-31']);
        $this->assertFalse($r['attendance'],
            'a date outside the academic session cannot be a teaching day even if '
            . 'someone declared a special working day on it');
        $this->assertSame(Student_calendar_service::REASON_OUTSIDE_SESSION, $r['reason']);
    }

    public function test_precedence_order_is_documented_and_complete(): void
    {
        // Every reason the resolver can emit must appear in PRECEDENCE, or the
        // documented precedence is a lie.
        $emitted = [
            Student_calendar_service::REASON_WORKING,
            Student_calendar_service::REASON_WEEKLY_OFF,
            Student_calendar_service::REASON_NTH_WEEKDAY,
            Student_calendar_service::REASON_HOLIDAY,
            Student_calendar_service::REASON_SPECIAL_WORKING,
            Student_calendar_service::REASON_OUTSIDE_SESSION,
            Student_calendar_service::REASON_EXAM_ONLY,
        ];
        foreach ($emitted as $r) {
            $this->assertContains($r, Student_calendar_service::PRECEDENCE,
                "reason '{$r}' is emitted but missing from PRECEDENCE");
        }
    }

    // ── 4. THE THREE FLAGS ARE INDEPENDENT ───────────────────────────────

    public function test_exam_only_day_is_open_without_instruction(): void
    {
        $r = Student_calendar_service::resolveForDate(
            $this->v(['Sun']), '2026-06-10', ['examOnly' => true]);
        $this->assertTrue($r['open'],         'the school is open for the exam');
        $this->assertFalse($r['instruction'], 'but regular teaching is not running');
        $this->assertTrue($r['attendance'],   'and students are still marked present');
    }

    public function test_a_half_day_is_a_teaching_day_not_a_closure(): void
    {
        $r = Student_calendar_service::resolveForDate(
            $this->v(['Sun']), '2026-06-10', ['halfDay' => true]);
        $this->assertTrue($r['attendance'],
            'a half day must remain markable — Working_calendar_service reaches the '
            . 'same conclusion independently, its MASK_HALF counting toward workingCount');
        $this->assertTrue($r['instruction']);
        $this->assertTrue($r['halfDay'], 'but it must be REPORTED as a half day');
    }

    public function test_a_holiday_is_never_reported_as_a_half_day(): void
    {
        $r = Student_calendar_service::resolveForDate(
            $this->v(['Sun']), '2026-06-10', ['holiday' => true, 'halfDay' => true]);
        $this->assertFalse($r['halfDay'], 'a closed school has no half day');
    }

    // ── 5. UNCONFIGURED ⇒ NULL, NEVER A GUESS ────────────────────────────

    public function test_unconfigured_tenant_returns_null_not_false(): void
    {
        $r = Student_calendar_service::resolveForDate(null, '2026-06-10');
        $this->assertNull($r['attendance'],
            'NULL forces the caller to apply an explicit owner-chosen policy. '
            . 'Returning false would invent a closure; true would invent a teaching day.');
        $this->assertNull($r['open']);
        $this->assertNull($r['instruction']);
        $this->assertFalse($r['configured']);
        $this->assertSame(Student_calendar_service::REASON_UNCONFIGURED, $r['reason']);
    }

    public function test_an_empty_version_list_is_unconfigured(): void
    {
        $this->assertNull(Student_calendar_service::effectiveVersion([], '2026-06-10'));
        $this->assertNull(Student_calendar_service::effectiveVersion(null, '2026-06-10'));
    }

    public function test_a_version_describing_no_week_is_unconfigured(): void
    {
        $empty = [['effectiveFrom' => '2026-04-01', 'weeklyOffs' => [], 'nthWeekdayOffs' => []]];
        $this->assertNull(Student_calendar_service::effectiveVersion($empty, '2026-06-10'),
            'an empty descriptor must not be read as "every day is a teaching day"');
    }

    public function test_a_date_before_every_version_is_unconfigured(): void
    {
        $versions = [$this->v(['Sun'], [], '2026-04-01')];
        $this->assertNull(Student_calendar_service::effectiveVersion($versions, '2026-03-31'));
    }

    public function test_the_legacy_pattern_is_documentation_not_a_fallback(): void
    {
        $r = Student_calendar_service::resolveForDate(null, '2026-06-07');
        $this->assertNull($r['attendance'],
            'legacyStudentPattern() must never be silently applied during resolution');

        $legacy = Student_calendar_service::legacyStudentPattern('2026-04-01');
        $this->assertSame(['Sun'], $legacy['weeklyOffs'],
            'but it must faithfully describe the old hardcoded rule for migration');
    }

    // ── 6. VALIDATION AND HISTORICAL PROTECTION ──────────────────────────

    public function test_a_week_with_no_offs_is_refused(): void
    {
        $r = Student_calendar_service::validateVersion(
            ['effectiveFrom' => '2026-04-01', 'weeklyOffs' => [], 'nthWeekdayOffs' => []]);
        $this->assertFalse($r['ok']);
    }

    public function test_every_day_off_is_refused(): void
    {
        $r = Student_calendar_service::validateVersion($this->v(
            ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']));
        $this->assertFalse($r['ok']);
    }

    public function test_bad_day_tokens_and_week_numbers_are_refused(): void
    {
        $this->assertFalse(Student_calendar_service::validateVersion(
            $this->v(['Sunday']))['ok'], 'must use Sun, not Sunday');
        $this->assertFalse(Student_calendar_service::validateVersion(
            $this->v(['Sun'], [['day' => 'Sat', 'weeks' => [6]]]))['ok'], 'week 6 does not exist');
        $this->assertFalse(Student_calendar_service::validateVersion(
            $this->v(['Sun'], [['day' => 'Sat', 'weeks' => []]]))['ok'], 'needs a week');
    }

    public function test_a_missing_or_malformed_effective_date_is_refused(): void
    {
        $this->assertFalse(Student_calendar_service::validateVersion(
            ['weeklyOffs' => ['Sun']])['ok']);
        $this->assertFalse(Student_calendar_service::validateVersion(
            ['effectiveFrom' => '01-04-2026', 'weeklyOffs' => ['Sun']])['ok']);
    }

    /** THE RETROACTIVE GATE — the write-side half of historical invariance. */
    public function test_a_version_landing_on_settled_attendance_is_refused(): void
    {
        $existing = [$this->v(['Sun'], [], '2026-04-01')];

        $r = Student_calendar_service::validateVersionAgainst(
            $this->v(['Sat','Sun'], [], '2026-06-01'), $existing, '2026-08-31');
        $this->assertFalse($r['ok'],
            'a school week effective before settled attendance would rewrite history');
        $this->assertStringContainsString('already recorded', implode(' ', $r['errors']));

        $ok = Student_calendar_service::validateVersionAgainst(
            $this->v(['Sat','Sun'], [], '2026-09-01'), $existing, '2026-08-31');
        $this->assertTrue($ok['ok'], 'the day after settled attendance is allowed');
    }

    public function test_the_settled_boundary_is_inclusive(): void
    {
        $existing = [$this->v(['Sun'], [], '2026-04-01')];
        $r = Student_calendar_service::validateVersionAgainst(
            $this->v(['Sat','Sun'], [], '2026-08-31'), $existing, '2026-08-31');
        $this->assertFalse($r['ok'], 'the settled date ITSELF must be protected');
    }

    public function test_first_time_setup_is_not_blocked_by_the_retroactive_gate(): void
    {
        $r = Student_calendar_service::validateVersionAgainst(
            $this->v(['Sun'], [], '2026-04-01'), [], '');
        $this->assertTrue($r['ok'], 'a tenant with no settled attendance can set any start date');
    }

    public function test_two_versions_on_the_same_date_are_refused(): void
    {
        $existing = [$this->v(['Sun'], [], '2026-04-01')];
        $r = Student_calendar_service::validateVersionAgainst(
            $this->v(['Sat','Sun'], [], '2026-04-01'), $existing, '');
        $this->assertFalse($r['ok'],
            'duplicate effective dates make resolution depend on array order');
    }

    // ── 7. INPUT ROBUSTNESS ──────────────────────────────────────────────

    public function test_invalid_dates_are_refused_not_guessed(): void
    {
        foreach (['2026-02-30', 'not-a-date', '2026-13-01', ''] as $bad) {
            $r = Student_calendar_service::resolveForDate($this->v(['Sun']), $bad);
            $this->assertNull($r['attendance'], "'{$bad}' must not resolve to an answer");
        }
    }

    public function test_day_token_and_occurrence_are_correct(): void
    {
        $this->assertSame('Sun', Student_calendar_service::dayToken('2026-06-07'));
        $this->assertSame('Sat', Student_calendar_service::dayToken('2026-06-13'));
        $this->assertSame(1, Student_calendar_service::occurrenceInMonth('2026-06-06'));
        $this->assertSame(2, Student_calendar_service::occurrenceInMonth('2026-06-13'));
        $this->assertSame(1, Student_calendar_service::occurrenceInMonth('2026-06-07'));
    }
}
