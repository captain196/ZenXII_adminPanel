<?php
/**
 * TIMETABLE METRICS — the facts, and the honesty of the facts.
 *
 * Two properties matter more than any individual count:
 *
 *   - a measurement that was never taken must say so, never report a zero that
 *     reads as "nothing wrong";
 *   - a distance must see a slot that exists in one timetable and not the
 *     other, or a timetable missing a whole day looks identical to one that
 *     has it.
 */

use PHPUnit\Framework\TestCase;

final class TimetableMetricsTest extends TestCase
{
    /** @param array<int,array{0:string,1:string}|null> $slots subject/teacher per period */
    private function day(string $section, string $dayName, array $slots): array
    {
        $periods = [];
        foreach ($slots as $i => $s) {
            $periods[] = [
                'periodNumber' => $i + 1,
                'subject'      => $s === null ? '' : $s[0],
                'teacher'      => $s === null ? '' : strtoupper($s[1]),
                'teacherId'    => $s === null ? '' : $s[1],
                'startTime'    => '09:00AM', 'endTime' => '09:45AM',
            ];
        }
        return ['classSection' => $section, 'day' => $dayName, 'periods' => $periods];
    }

    // ── free periods: where they fall is the point ───────────────────────

    public function test_a_gap_mid_day_is_counted_apart_from_an_early_finish(): void
    {
        $days = [
            // taught, FREE, taught, then free to the end of the day
            $this->day('9/A', 'Monday', [['Maths','t1'], null, ['Hindi','t2'], null, null]),
        ];
        $m = Timetable_metrics::from($days);

        $this->assertSame(5, $m['cells']);
        $this->assertSame(2, $m['filled']);
        $this->assertSame(3, $m['free']);
        $this->assertSame(1, $m['freeInterior'], 'a class with nothing to do mid-morning');
        $this->assertSame(2, $m['freeTrailing'], 'a school that simply finishes early');
    }

    // ── curriculum shortfall ─────────────────────────────────────────────

    /**
     * THE HONESTY PROPERTY. With no curriculum target supplied, shortfall is
     * UNKNOWN. Reporting `total => 0` as though it were measured would let a
     * comparison screen show a timetable missing half its lessons as complete.
     */
    public function test_an_unmeasured_curriculum_is_reported_as_unknown(): void
    {
        $m = Timetable_metrics::from([$this->day('9/A', 'Monday', [['Maths','t1']])]);

        $this->assertFalse($m['shortfall']['known'],
            'no target was supplied, so nothing about the curriculum was measured');
        $this->assertSame(0, $m['shortfall']['total']);
        $this->assertSame([], $m['shortfall']['bySubject']);
    }

    public function test_a_shortfall_names_the_subject_and_the_size_of_the_gap(): void
    {
        $days = [
            $this->day('9/A', 'Monday',  [['Maths','t1'], ['Hindi','t2']]),
            $this->day('9/A', 'Tuesday', [['Maths','t1'], null]),
        ];
        $m = Timetable_metrics::from($days, ['9/A' => ['Maths' => 2, 'Hindi' => 4]]);

        $this->assertTrue($m['shortfall']['known']);
        $this->assertSame(3, $m['shortfall']['total'], 'Hindi is three periods short');
        $this->assertCount(1, $m['shortfall']['bySubject'],
            'Maths met its target and must not be listed');

        $row = $m['shortfall']['bySubject'][0];
        $this->assertSame('Hindi', $row['subject']);
        $this->assertSame(4, $row['asked']);
        $this->assertSame(1, $row['scheduled']);
        $this->assertSame(3, $row['short']);
    }

    public function test_a_subject_taught_more_than_asked_is_not_a_shortfall(): void
    {
        $days = [$this->day('9/A', 'Monday', [['Maths','t1'], ['Maths','t1'], ['Maths','t1']])];
        $m = Timetable_metrics::from($days, ['9/A' => ['Maths' => 1]]);

        $this->assertSame(0, $m['shortfall']['total'],
            'more teaching than asked for is not a gap in the curriculum');
    }

    // ── teaching load, stretch and gaps ──────────────────────────────────

    public function test_teaching_load_and_its_spread(): void
    {
        $days = [
            $this->day('9/A', 'Monday', [['Maths','t1'], ['Maths','t1'], ['Hindi','t2']]),
            $this->day('9/B', 'Monday', [['Maths','t1'], null, null]),
        ];
        $m = Timetable_metrics::from($days);

        $this->assertSame(2, $m['teacherLoad']['teachers']);
        $this->assertSame(1, $m['teacherLoad']['min']);
        $this->assertSame(3, $m['teacherLoad']['max']);
        $this->assertSame(2, $m['teacherLoad']['spread']);
        $this->assertSame(3, $m['teacherLoad']['byTeacher']['t1']);
    }

    public function test_the_longest_stretch_without_a_break(): void
    {
        $days = [
            // t1 teaches periods 1,2,3 then 5 — a run of three, then a gap.
            $this->day('9/A', 'Monday',
                [['Maths','t1'], ['Maths','t1'], ['Maths','t1'], null, ['Maths','t1']]),
        ];
        $m = Timetable_metrics::from($days);

        $this->assertSame(3, $m['longestStretch']);
        $this->assertSame(1, $m['teacherGaps'], 'one free period between two classes');
    }

    /** A stretch is per teacher per DAY — it must not run across a day boundary. */
    public function test_a_stretch_does_not_run_across_two_days(): void
    {
        $days = [
            $this->day('9/A', 'Monday',  [['Maths','t1'], ['Maths','t1']]),
            $this->day('9/A', 'Tuesday', [['Maths','t1'], ['Maths','t1']]),
        ];
        $this->assertSame(2, Timetable_metrics::from($days)['longestStretch'],
            'two on Monday and two on Tuesday is a run of two, not four');
    }

    // ── distance ─────────────────────────────────────────────────────────

    public function test_an_identical_timetable_has_zero_distance(): void
    {
        $a = [$this->day('9/A', 'Monday', [['Maths','t1'], ['Hindi','t2']])];
        $d = Timetable_metrics::distance($a, $a);

        $this->assertSame(0, $d['differing']);
        $this->assertTrue($d['identical']);
        $this->assertSame(0.0, $d['ratio']);
        $this->assertSame(Timetable_metrics::signature($a), Timetable_metrics::signature($a));
    }

    public function test_distance_counts_the_slots_that_are_taught_differently(): void
    {
        $a = [$this->day('9/A', 'Monday', [['Maths','t1'], ['Hindi','t2'], ['Art','t3']])];
        $b = [$this->day('9/A', 'Monday', [['Hindi','t2'], ['Maths','t1'], ['Art','t3']])];

        $d = Timetable_metrics::distance($a, $b);
        $this->assertSame(2, $d['differing'], 'two slots swapped, the third unchanged');
        $this->assertSame(3, $d['cells']);
        $this->assertFalse($d['identical']);
        $this->assertNotSame(Timetable_metrics::signature($a), Timetable_metrics::signature($b));
    }

    /**
     * THE ASYMMETRY PROPERTY. A slot present in one timetable and absent from
     * the other is a difference. Comparing only the first timetable's slots
     * would make one that is missing a whole day look identical to one that
     * has it — the worst possible false negative for a duplicate check.
     */
    public function test_a_slot_missing_from_one_timetable_still_counts(): void
    {
        $a = [$this->day('9/A', 'Monday', [['Maths','t1']])];
        $b = [
            $this->day('9/A', 'Monday',  [['Maths','t1']]),
            $this->day('9/A', 'Tuesday', [['Hindi','t2'], ['Art','t3']]),
        ];

        $d = Timetable_metrics::distance($a, $b);
        $this->assertSame(2, $d['differing'],
            'the whole extra day must register as a difference');
        $this->assertSame(3, $d['cells'], 'the union of both timetables is measured');
        $this->assertFalse($d['identical']);

        // And it must be symmetric: order of comparison cannot change the answer.
        $this->assertSame($d, Timetable_metrics::distance($b, $a));
    }

    /** A tiny difference must not round away to "identical". */
    public function test_one_changed_period_in_a_large_week_is_not_reported_as_zero(): void
    {
        $mk = function (string $lastSubject) {
            $days = [];
            foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $dn) {
                $slots = array_fill(0, 6, ['Maths', 't1']);
                if ($dn === 'Saturday') { $slots[5] = [$lastSubject, 't1']; }
                $days[] = $this->day('9/A', $dn, $slots);
            }
            return $days;
        };

        $d = Timetable_metrics::distance($mk('Maths'), $mk('Hindi'));
        $this->assertSame(1, $d['differing']);
        $this->assertSame(36, $d['cells']);
        $this->assertGreaterThan(0, $d['ratio'],
            '1 in 36 must not round to zero and read as the same timetable');
        $this->assertFalse($d['identical']);
    }

    public function test_unreadable_input_measures_nothing_rather_than_guessing(): void
    {
        $m = Timetable_metrics::from('not a timetable');
        $this->assertSame(0, $m['cells']);
        $this->assertSame(0, $m['sections']);
        $this->assertFalse($m['shortfall']['known']);
    }
}
