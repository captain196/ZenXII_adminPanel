<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once APPPATH . 'helpers/attendance_helper.php';

/**
 * CAL-5E — EDITING ONE DAY MUST NOT REWRITE UNRELATED HISTORICAL DAYS.
 *
 * `enforce_holidays_on_string()` stamps the WHOLE month. On the single-day edit
 * path that meant: declare a holiday for a past date, then touch any day in that
 * month, and a real 'P' recorded months ago silently became 'H'.
 *
 * Note what this defect is NOT. The function only ever ADDS 'H' — it never
 * removes one — so it was never an erasure vector, and effective dating does not
 * fix it either: effective dating protects the WEEKLY PATTERN, while
 * calendarEvents holidays carry no versions at all, so declaring one today
 * genuinely does change what a past date resolves to.
 *
 * The protection is therefore stated on the RECORD rather than on the rule: an
 * explicit human mark is evidence somebody was actually there, and a save about
 * a different day must not overwrite it.
 */
final class AttendanceHistoricalRestampTest extends TestCase
{
    private const DIM = 30;

    private function str(array $overrides): string
    {
        $s = str_repeat('V', self::DIM);
        foreach ($overrides as $day => $ch) $s[$day - 1] = $ch;
        return $s;
    }

    // ── THE DEFECT ───────────────────────────────────────────────────────

    public function test_a_newly_declared_past_holiday_does_not_erase_a_recorded_present(): void
    {
        // Day 12 was marked P months ago. A holiday has since been declared on it.
        $stored = $this->str([12 => 'P', 20 => 'P']);
        $nonWorking = [12 => 'Founders Day'];

        // The admin edits day 20 — nothing to do with day 12.
        $stored[19] = 'A';

        $out = enforce_holidays_on_string($stored, self::DIM, $nonWorking, true);

        $this->assertSame('P', $out[11],
            'day 12 carried a real attendance mark and was not the day being edited; '
            . 'a save about day 20 must not silently convert it to a holiday');
        $this->assertSame('A', $out[19], 'the edited day keeps its new mark');
    }

    /** Every explicit mark is protected, not just P. */
    public function test_all_explicit_marks_are_protected(): void
    {
        foreach (['P', 'A', 'L', 'T'] as $mark) {
            $stored = $this->str([5 => $mark]);
            $out = enforce_holidays_on_string($stored, self::DIM, [5 => 'Holiday'], true);
            $this->assertSame($mark, $out[4],
                "an explicit '{$mark}' must survive a re-stamp about another day");
        }
    }

    /** Unmarked days must still fill in — genuine holidays keep working. */
    public function test_unmarked_days_still_receive_the_holiday_stamp(): void
    {
        $stored = $this->str([12 => 'P']);
        $out = enforce_holidays_on_string($stored, self::DIM, [12 => 'H', 13 => 'H'], true);

        $this->assertSame('P', $out[11], 'the marked day is protected');
        $this->assertSame('H', $out[12],
            "day 13 was never marked ('V'), so a declared holiday must still fill it in");
    }

    /** An already-stamped H stays H. */
    public function test_an_existing_holiday_stamp_is_idempotent(): void
    {
        $stored = $this->str([7 => 'H']);
        $out = enforce_holidays_on_string($stored, self::DIM, [7 => 'Sunday'], true);
        $this->assertSame('H', $out[6]);
    }

    // ── THE BULK PATH IS DELIBERATELY UNCHANGED ──────────────────────────

    /**
     * The whole-month save passes false: there the administrator is submitting
     * the entire month on purpose, so stamping is the intent rather than a side
     * effect. Proving the default is unchanged protects every existing caller.
     */
    public function test_the_default_behaviour_is_byte_identical_to_before(): void
    {
        $stored = $this->str([12 => 'P', 20 => 'A', 25 => 'L']);
        $nonWorking = [12 => 'Holiday', 13 => 'Sunday', 25 => 'Holiday'];

        $out = enforce_holidays_on_string($stored, self::DIM, $nonWorking);

        $this->assertSame('H', $out[11], 'default still re-stamps unconditionally');
        $this->assertSame('H', $out[12]);
        $this->assertSame('H', $out[24]);
        $this->assertSame('A', $out[19], 'a day outside the non-working set is untouched');
    }

    public function test_explicit_opt_out_matches_the_default(): void
    {
        $stored = $this->str([12 => 'P']);
        $this->assertSame(
            enforce_holidays_on_string($stored, self::DIM, [12 => 'H']),
            enforce_holidays_on_string($stored, self::DIM, [12 => 'H'], false)
        );
    }

    // ── SHAPE AND EDGE CASES ─────────────────────────────────────────────

    public function test_short_strings_are_padded_and_out_of_range_days_ignored(): void
    {
        $out = enforce_holidays_on_string('PP', self::DIM, [0 => 'x', 31 => 'x', 2 => 'H'], true);
        $this->assertSame(self::DIM, strlen($out), 'padded to the month length');
        $this->assertSame('P', $out[1], 'day 2 held an explicit mark and is protected');
        $this->assertSame('V', $out[5], 'untouched days stay vacant');
    }

    public function test_an_empty_non_working_map_changes_nothing(): void
    {
        $stored = $this->str([3 => 'P', 9 => 'A']);
        $this->assertSame($stored, enforce_holidays_on_string($stored, self::DIM, [], true));
    }

    /**
     * Repeating the same save must converge — a save that keeps changing the
     * record on every retry is its own kind of history rewrite.
     */
    public function test_repeated_application_is_idempotent(): void
    {
        $stored = $this->str([12 => 'P', 13 => 'V']);
        $nonWorking = [12 => 'Holiday', 13 => 'Sunday'];

        $once  = enforce_holidays_on_string($stored, self::DIM, $nonWorking, true);
        $twice = enforce_holidays_on_string($once,   self::DIM, $nonWorking, true);
        $thrice= enforce_holidays_on_string($twice,  self::DIM, $nonWorking, true);

        $this->assertSame($once, $twice, 'a retry must not drift the record');
        $this->assertSame($twice, $thrice);
    }
}
