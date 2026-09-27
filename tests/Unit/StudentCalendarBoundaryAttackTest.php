<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once APPPATH . 'libraries/Student_calendar_service.php';
require_once APPPATH . 'libraries/Student_calendar_reader.php';

/**
 * ADVERSARIAL BOUNDARY ATTACKS.
 *
 * Written as if reviewing somebody else's resolver and trying to break it. The
 * existing suites cover the invariants the author thought about; this one goes
 * after the arithmetic they probably did not:
 *
 *   - leap years, and the century rule that catches naive implementations
 *   - month ends of every length
 *   - year boundaries, where an effective date and a calendar year disagree
 *   - the 29th, 30th and 31st, where nth-weekday occurrence maths overflows
 *   - dates far outside any plausible session
 *
 * A green result here is only worth something because each test was checked to
 * be capable of failing — several were written against a deliberately wrong
 * expectation first, and only corrected once they had failed for the right
 * reason.
 */
final class StudentCalendarBoundaryAttackTest extends TestCase
{
    private function v(array $offs, array $nth = [], string $from = '2000-01-01'): array
    {
        return ['effectiveFrom' => $from, 'weeklyOffs' => $offs, 'nthWeekdayOffs' => $nth];
    }

    private function attends(array $v, string $d): ?bool
    {
        return Student_calendar_service::resolveForDate($v, $d)['attendance'];
    }

    // ── LEAP YEARS ───────────────────────────────────────────────────────

    public function test_29_february_in_a_leap_year_is_a_real_date(): void
    {
        $this->assertTrue(Student_calendar_service::isIsoDate('2024-02-29'));
        // 2024-02-29 is a Thursday.
        $this->assertSame('Thu', Student_calendar_service::dayToken('2024-02-29'));
        $this->assertTrue($this->attends($this->v(['Sun']), '2024-02-29'));
    }

    public function test_29_february_in_a_non_leap_year_is_refused(): void
    {
        $this->assertFalse(Student_calendar_service::isIsoDate('2025-02-29'));
        $this->assertNull($this->attends($this->v(['Sun']), '2025-02-29'),
            'an impossible date must not resolve to an answer');
    }

    /**
     * The century rule. 1900 was NOT a leap year; 2000 WAS. An implementation
     * using a bare `% 4` gets both wrong.
     */
    public function test_the_century_leap_rule_is_handled(): void
    {
        $this->assertFalse(Student_calendar_service::isIsoDate('1900-02-29'),
            '1900 is divisible by 100 but not 400 — not a leap year');
        $this->assertTrue(Student_calendar_service::isIsoDate('2000-02-29'),
            '2000 is divisible by 400 — a leap year');
    }

    /** A leap day must not shift the weekday of the dates around it. */
    public function test_a_leap_day_does_not_shift_neighbouring_weekdays(): void
    {
        $this->assertSame('Wed', Student_calendar_service::dayToken('2024-02-28'));
        $this->assertSame('Thu', Student_calendar_service::dayToken('2024-02-29'));
        $this->assertSame('Fri', Student_calendar_service::dayToken('2024-03-01'));
    }

    // ── MONTH ENDS ───────────────────────────────────────────────────────

    /** Every month length: 28, 29, 30, 31. */
    public function test_the_last_day_of_every_month_length_resolves(): void
    {
        foreach (['2025-02-28', '2024-02-29', '2026-04-30', '2026-01-31'] as $d) {
            $this->assertTrue(Student_calendar_service::isIsoDate($d), "{$d} must be valid");
            $this->assertNotNull($this->attends($this->v(['Sun']), $d),
                "{$d} must produce an answer");
        }
        foreach (['2026-04-31', '2026-02-30', '2026-06-31'] as $bad) {
            $this->assertFalse(Student_calendar_service::isIsoDate($bad), "{$bad} does not exist");
        }
    }

    /**
     * Occurrence is ceil(day/7), so the 29th–31st are always occurrence 5.
     * A rule for "5th Saturday" must fire only in months that HAVE one.
     */
    public function test_the_fifth_occurrence_only_exists_in_long_enough_months(): void
    {
        $this->assertSame(5, Student_calendar_service::occurrenceInMonth('2026-01-29'));
        $this->assertSame(5, Student_calendar_service::occurrenceInMonth('2026-01-31'));
        $this->assertSame(4, Student_calendar_service::occurrenceInMonth('2026-01-28'));

        $v = $this->v(['Sun'], [['day' => 'Sat', 'weeks' => [5]]]);
        // 2026-05-30 IS a 5th Saturday; 2026-06-27 is only the 4th.
        $this->assertFalse($this->attends($v, '2026-05-30'));
        $this->assertTrue($this->attends($v, '2026-06-27'));
    }

    /** February in a non-leap year has exactly four of each weekday. */
    public function test_february_never_has_a_fifth_occurrence_in_a_common_year(): void
    {
        $this->assertSame(4, Student_calendar_service::occurrenceInMonth('2025-02-28'),
            '28 / 7 = 4 exactly — there is no fifth anything');
    }

    // ── YEAR BOUNDARIES ──────────────────────────────────────────────────

    public function test_a_version_effective_on_new_years_day_applies_that_day(): void
    {
        $versions = [
            ['effectiveFrom' => '2026-04-01', 'weeklyOffs' => ['Sun']],
            ['effectiveFrom' => '2027-01-01', 'weeklyOffs' => ['Sat', 'Sun']],
        ];
        // 2026-12-31 Thu, 2027-01-01 Fri, 2027-01-02 Sat.
        $this->assertTrue($this->attends(
            Student_calendar_service::effectiveVersion($versions, '2026-12-31'), '2026-12-31'));
        $this->assertTrue($this->attends(
            Student_calendar_service::effectiveVersion($versions, '2027-01-01'), '2027-01-01'));
        $this->assertFalse($this->attends(
            Student_calendar_service::effectiveVersion($versions, '2027-01-02'), '2027-01-02'),
            'the first Saturday of the new policy must close');
    }

    /** String comparison of ISO dates must order correctly across a year end. */
    public function test_iso_date_ordering_holds_across_a_year_boundary(): void
    {
        $versions = [
            ['effectiveFrom' => '2026-12-30', 'weeklyOffs' => ['Sun']],
            ['effectiveFrom' => '2027-01-05', 'weeklyOffs' => ['Sat', 'Sun']],
        ];
        $v = Student_calendar_service::effectiveVersion($versions, '2027-01-03');
        $this->assertSame('2026-12-30', $v['effectiveFrom'],
            'the December version must still be the one in force on 3 January');
    }

    // ── SESSION BOUNDARY — WHY IT STAYS UNWIRED ──────────────────────────

    /**
     * `Leave_year_service` IS the canonical session-boundary authority, and it
     * works: `boundsForSession('2026-27')` returns 2026-04-01..2027-03-31.
     *
     * It is nonetheless NOT wired into attendance resolution, and this test
     * exists so the reason survives longer than the memory of it.
     *
     * Passing the session window makes every date outside the CURRENT session
     * resolve as non-teaching. For a school in 2026-27 that turns November 2025
     * from five non-teaching days into THIRTY — every historical month of every
     * previous session becomes a total closure. Any report or register view
     * spanning a prior session would silently change meaning.
     *
     * That is a behaviour change, not a fix. The authority is named, consumed
     * for the advisory readiness window, and deliberately kept out of resolution.
     */
    public function test_wiring_the_session_window_would_rewrite_historical_months(): void
    {
        require_once APPPATH . 'libraries/Leave_year_service.php';

        $bounds = \Leave_year_service::boundsForSession('2026-27');
        $this->assertSame('2026-04-01', $bounds['startDate'],
            'the authority must still resolve the real ZenX session format');
        $this->assertSame('2027-03-31', $bounds['endDate']);

        $window  = ['start' => $bounds['startDate'], 'end' => $bounds['endDate']];
        $version = $this->v(['Sun'], [], '2025-01-01');

        $countOff = function (string $ym, int $days, array $win) use ($version): int {
            $n = 0;
            for ($d = 1; $d <= $days; $d++) {
                $date = $ym . '-' . str_pad((string) $d, 2, '0', STR_PAD_LEFT);
                if (Student_calendar_service::resolveForDate($version, $date, [], $win)['attendance'] === false) {
                    $n++;
                }
            }
            return $n;
        };

        // A month INSIDE the session is unaffected either way.
        $this->assertSame($countOff('2026-06', 30, []), $countOff('2026-06', 30, $window),
            'a month inside the session must resolve identically with or without the window');

        // A month OUTSIDE it is not.
        $today = $countOff('2025-11', 30, []);
        $wired = $countOff('2025-11', 30, $window);
        $this->assertSame(5, $today, 'today: Sundays only');
        $this->assertSame(30, $wired, 'wired: the entire month becomes a closure');
        $this->assertNotSame($today, $wired,
            'THIS is why REASON_OUTSIDE_SESSION stays unwired — it would rewrite '
            . 'every historical month of every previous session');
    }

    /**
     * The label format is load-bearing. ZenX sessions are `YYYY-YY`
     * ("2026-27"), validated as such by School_config and Session_lifecycle.
     * A four-digit-year label does NOT parse, which is exactly why the readiness
     * check keeps a fallback rather than assuming bounds always resolve.
     */
    public function test_the_authority_only_accepts_the_real_zenx_session_format(): void
    {
        require_once APPPATH . 'libraries/Leave_year_service.php';

        $this->assertNotNull(\Leave_year_service::boundsForSession('2025-26'));
        $this->assertNull(\Leave_year_service::boundsForSession('2026-2027'),
            'a four-digit second year is not the ZenX format and must not be guessed at');
        $this->assertNull(\Leave_year_service::boundsForSession(''));

        // A tenant that starts its year in January is honoured, not overridden.
        $jan = \Leave_year_service::boundsForSession('2026-27', [], 1);
        $this->assertSame('2026-01-01', $jan['startDate'],
            'the April convention is a configurable DEFAULT, not a hardcoded rule');
    }

    // ── FAR-OUT AND MALFORMED INPUT ──────────────────────────────────────

    /**
     * Written expecting BOTH ends to answer, and it failed — correctly.
     *
     * A date BEFORE a version's effectiveFrom is not "unknown arithmetic", it is
     * a date that version does not govern, and SCS-3 makes the resolver say so
     * instead of applying a policy retroactively. The expectation was wrong, not
     * the code; recording that here because a green test written after the fact
     * would hide which of the two was actually being asserted.
     */
    public function test_a_date_before_the_version_is_ungoverned_not_guessed(): void
    {
        $v = $this->v(['Sun'], [], '2000-01-01');

        $this->assertNull($this->attends($v, '1970-01-01'),
            'a version effective from 2000 must not reach back into 1970');
        $this->assertTrue($this->attends($v, '2099-12-31'),
            'but it governs indefinitely forward until superseded');

        // And a version that DOES cover 1970 answers normally.
        $this->assertTrue($this->attends($this->v(['Sun'], [], '1970-01-01'), '1970-01-01'));
    }

    /** Shape-valid but calendar-invalid input must never slip through. */
    public function test_shape_valid_but_impossible_dates_are_refused(): void
    {
        foreach (['2026-00-10', '2026-13-01', '2026-01-00', '2026-01-32'] as $bad) {
            $this->assertFalse(Student_calendar_service::isIsoDate($bad), "{$bad}");
            $this->assertNull($this->attends($this->v(['Sun']), $bad), "{$bad}");
        }
    }

    /**
     * A whole year, swept. Exactly one weekday token per date, and the
     * occurrence never leaves 1–5 — the two assumptions the nth-weekday rules
     * rest on.
     */
    public function test_a_full_leap_year_sweep_holds_both_invariants(): void
    {
        $bad = [];
        $d = new DateTimeImmutable('2024-01-01');
        for ($i = 0; $i < 366; $i++) {
            $date = $d->modify("+{$i} day")->format('Y-m-d');

            $tok = Student_calendar_service::dayToken($date);
            if (!in_array($tok, Student_calendar_service::DAYS, true)) {
                $bad[] = "{$date}: bad token {$tok}";
            }
            $occ = Student_calendar_service::occurrenceInMonth($date);
            if ($occ < 1 || $occ > 5) $bad[] = "{$date}: occurrence {$occ}";

            // And the resolver must always commit to an answer.
            if ($this->attends($this->v(['Sun']), $date) === null) {
                $bad[] = "{$date}: no answer";
            }
        }
        $this->assertSame([], $bad, implode('; ', array_slice($bad, 0, 8)));
    }

    /**
     * The weekday token must agree with PHP's own calendar across a long sweep.
     * dayToken() builds its own mktime() call, so a drift here would silently
     * shift every weekly closure by a day.
     */
    public function test_day_token_agrees_with_php_date_over_five_years(): void
    {
        $bad = [];
        $d = new DateTimeImmutable('2024-01-01');
        for ($i = 0; $i < 1826; $i++) {
            $dt   = $d->modify("+{$i} day");
            $date = $dt->format('Y-m-d');
            $expected = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][(int) $dt->format('w')];
            if (Student_calendar_service::dayToken($date) !== $expected) {
                $bad[] = $date;
            }
        }
        $this->assertSame([], $bad,
            'weekday drift on: ' . implode(', ', array_slice($bad, 0, 10)));
    }
}
