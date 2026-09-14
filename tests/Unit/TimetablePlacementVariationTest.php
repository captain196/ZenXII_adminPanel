<?php
/**
 * THE PLACEMENT ENGINE: ordering, difficulty, and seeded variation.
 *
 * Three separate claims are proved here, because they were three separate
 * problems:
 *
 *   1. The folded score `dayCount * 100 + period` was an ORDERING, not a
 *      weight — and it silently inverted once a day had 100 periods.
 *   2. Which subject gets placed first must not depend on it being called
 *      "Mathematics" in English.
 *   3. A seed changes which of several EQUALLY GOOD placements is used, and
 *      nothing else. Hard constraints hold under every seed.
 */

use PHPUnit\Framework\TestCase;

final class TimetablePlacementVariationTest extends TestCase
{
    /** Drive the private placer directly. */
    private function place(array $sections, array $assignments, array $days,
                           int $periodsPerDay, int $seed = 0): array
    {
        $svc = new Timetable_service();
        $m   = new ReflectionMethod(Timetable_service::class, '_placeGrid');
        $m->setAccessible(true);
        return $m->invoke($svc, $sections, $assignments, $days, $periodsPerDay, $seed);
    }

    private function sections(int $n = 2): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = ['className' => "Class {$i}", 'section' => 'Section A',
                      'key' => "Class {$i}/Section A"];
        }
        return $out;
    }

    /**
     * Deliberately NOT English. Under the old hardcoded list every one of these
     * sorted to the tail and was the first to lose its periods.
     */
    private function assignments(int $sectionCount = 2): array
    {
        $subjects = [
            ['संस्कृत',        'STA01', 'A Sharma', 6],
            ['Social Science', 'STA02', 'B Khan',   5],
            ['Maths',          'STA03', 'C Rao',    5],
            ['Kannada',        'STA04', 'D Iyer',   4],
        ];
        $out = [];
        for ($i = 1; $i <= $sectionCount; $i++) {
            $rows = [];
            foreach ($subjects as [$sub, $tid, $name, $ppw]) {
                $rows[] = ['subject' => $sub, 'code' => substr($sub, 0, 2),
                           'teacherId' => $tid, 'teacherName' => $name,
                           'periodsPerWeek' => $ppw];
            }
            $out["Class {$i}/Section A"] = $rows;
        }
        return $out;
    }

    private function days(): array
    {
        return ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    }

    /** Flatten a grid into "section|day|period" => "subject|teacher". */
    private function cells(array $grid): array
    {
        $out = [];
        foreach ($grid as $sk => $byDay) {
            foreach ($byDay as $day => $periods) {
                foreach ($periods as $p => $cell) {
                    $out["{$sk}|{$day}|{$p}"] = $cell === null
                        ? '' : $cell['subject'] . '|' . $cell['teacherId'];
                }
            }
        }
        ksort($out);
        return $out;
    }

    // ── 1. the folded score was an ordering ──────────────────────────────

    /**
     * For every period index a real school can have, comparing
     * `dayCount * 100 + period` gives exactly the same answer as comparing
     * dayCount first and period second. The multiplier never expressed a
     * trade-off; it encoded a priority.
     */
    public function test_the_folded_score_was_only_ever_a_lexicographic_ordering(): void
    {
        $disagreements = 0;
        for ($ca = 0; $ca <= 6; $ca++) {
            for ($pa = 0; $pa < 100; $pa++) {
                for ($cb = 0; $cb <= 6; $cb++) {
                    for ($pb = 0; $pb < 100; $pb += 7) {
                        $folded = ($ca * 100 + $pa) <=> ($cb * 100 + $pb);
                        $lex    = $ca !== $cb ? ($ca <=> $cb) : ($pa <=> $pb);
                        if ($folded !== $lex) { $disagreements++; }
                    }
                }
            }
        }
        $this->assertSame(0, $disagreements,
            'below 100 periods a day the two forms are the same ordering, which '
            . 'is why replacing one with the other changes no timetable');
    }

    /**
     * And here is the defect the folding hid: at 100 periods in a day the
     * period index reaches into the dayCount rank and inverts the priority.
     * Absurd for a school, but it is the reason a folded score is a latent bug
     * rather than a harmless shorthand.
     */
    public function test_the_folded_score_inverted_once_a_day_had_a_hundred_periods(): void
    {
        // A: subject already on this day once, first period.  B: not yet on
        // this day, but period 100. Spreading should win — B should be better.
        $folded = (1 * 100 + 0) <=> (0 * 100 + 100);
        $lex    = 1 <=> 0;

        $this->assertSame(0, $folded, 'folded: the two become indistinguishable');
        $this->assertSame(1, $lex, 'stated: spreading still wins, as intended');
        $this->assertNotSame($lex, $folded,
            'the folded form loses the priority it existed to express');
    }

    // ── 2. difficulty is measured, not named ─────────────────────────────

    /**
     * No subject is privileged for being called "Mathematics". Ordering follows
     * the busiest teacher, which is arithmetic on the school's own data.
     */
    public function test_placement_order_does_not_depend_on_english_subject_names(): void
    {
        $svc = new Timetable_service();
        $m   = new ReflectionMethod(Timetable_service::class, '_difficultyOrder');
        $m->setAccessible(true);

        $load = $m->invoke($svc, $this->assignments(2));

        // Two sections x 6 periods a week for संस्कृत's teacher.
        $this->assertSame(12, $load['STA01'], 'load is summed across every section');
        $this->assertSame(10, $load['STA02']);
        $this->assertSame(8,  $load['STA04']);

        // The busiest teacher's subject is placed first — and it is the one
        // with a non-English name, which the old list would have sorted last.
        $grid  = $this->place($this->sections(2), $this->assignments(2), $this->days(), 6);
        $first = $grid['Class 1/Section A']['Monday'][0];
        $this->assertSame('संस्कृत', $first['subject'],
            'the hardest assignment to place goes first, whatever it is called');
    }

    /** The old list is gone from the source, not merely bypassed. */
    public function test_the_hardcoded_english_subject_list_is_gone(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_service.php');
        $code = (string) preg_replace('~/\*.*?\*/~s', '', $src);
        $code = (string) preg_replace('~^\s*//.*$~m', '', $code);

        $this->assertStringNotContainsString('$hardSubjects', $code);
        $this->assertSame(0, preg_match_all(
            "~\['Mathematics','Math','Science'~", $code),
            'a hardcoded subject-name priority list must not exist');
    }

    // ── 3. seeded variation ──────────────────────────────────────────────

    /**
     * Teacher load is the PRIMARY difficulty signal, ahead of how many periods
     * a subject wants.
     *
     * The two disagree here on purpose: Music wants only 2 periods a week, but
     * its teacher is spread across four sections and has 8 periods to fit
     * somewhere, so Music has the fewest free slots left and must be placed
     * first. History wants 6 periods but its teacher teaches nothing else.
     *
     * Without this case the ordering test passes even when teacher load is
     * ignored entirely, because the busiest teacher also happened to have the
     * most periods.
     */
    public function test_a_busy_teacher_outranks_a_subject_that_wants_more_periods(): void
    {
        $sections = [];
        $assign   = [];
        for ($i = 1; $i <= 4; $i++) {
            $key = "Class {$i}/Section A";
            $sections[] = ['className' => "Class {$i}", 'section' => 'Section A', 'key' => $key];
            $assign[$key] = [
                // 2 a week here, but the same teacher in all four sections = 8.
                ['subject' => 'Music', 'code' => 'MU', 'teacherId' => 'BUSY',
                 'teacherName' => 'Busy Teacher', 'periodsPerWeek' => 2],
                // 6 a week, but this teacher does nothing else anywhere.
                ['subject' => 'History', 'code' => 'HI', 'teacherId' => "SOLO{$i}",
                 'teacherName' => "Solo {$i}", 'periodsPerWeek' => 6],
            ];
        }

        $svc = new Timetable_service();
        $m   = new ReflectionMethod(Timetable_service::class, '_difficultyOrder');
        $m->setAccessible(true);
        $load = $m->invoke($svc, $assign);

        $this->assertSame(8, $load['BUSY'], 'summed across every section');
        $this->assertSame(6, $load['SOLO1']);

        $grid  = $this->place($sections, $assign, $this->days(), 6, 0);
        $first = $grid['Class 1/Section A']['Monday'][0];

        $this->assertSame('Music', $first['subject'],
            'the assignment with the fewest free slots left goes first, even '
            . 'though it wants fewer periods than the one it displaced');
    }

    public function test_seed_zero_is_deterministic(): void
    {
        $a = $this->cells($this->place($this->sections(), $this->assignments(), $this->days(), 6, 0));
        $b = $this->cells($this->place($this->sections(), $this->assignments(), $this->days(), 6, 0));
        $this->assertSame($a, $b, 'no seed means no variation at all');
    }

    public function test_the_same_seed_reproduces_the_same_timetable(): void
    {
        $a = $this->cells($this->place($this->sections(), $this->assignments(), $this->days(), 6, 4242));
        $b = $this->cells($this->place($this->sections(), $this->assignments(), $this->days(), 6, 4242));
        $this->assertSame($a, $b,
            'a run must be reproducible from its seed, or a candidate that '
            . 'behaves oddly can never be re-created to diagnose it');
    }

    public function test_different_seeds_produce_genuinely_different_timetables(): void
    {
        $base = $this->cells($this->place($this->sections(), $this->assignments(), $this->days(), 6, 0));

        $differentSeeds = 0;
        foreach ([1, 7, 99, 12345, 777777] as $seed) {
            $other = $this->cells($this->place($this->sections(), $this->assignments(), $this->days(), 6, $seed));
            $diff  = 0;
            foreach ($base as $k => $v) { if (($other[$k] ?? '') !== $v) { $diff++; } }
            if ($diff > 0) { $differentSeeds++; }
        }

        $this->assertSame(5, $differentSeeds,
            'every seed must yield a timetable that actually differs, or '
            . '"multiple candidates" is five copies with different ids');
    }

    /**
     * The safety property. Variation chooses between EQUALLY GOOD placements;
     * it must never buy difference by breaking a rule.
     */
    public function test_no_seed_ever_double_books_a_teacher_or_a_slot(): void
    {
        foreach ([0, 1, 2, 3, 5, 8, 13, 21, 34, 55, 9999] as $seed) {
            $grid = $this->place($this->sections(3), $this->assignments(3), $this->days(), 6, $seed);

            $teacherAt = [];
            foreach ($grid as $sk => $byDay) {
                foreach ($byDay as $day => $periods) {
                    $this->assertCount(6, $periods, "seed {$seed}: the week keeps its shape");
                    foreach ($periods as $p => $cell) {
                        if ($cell === null) { continue; }
                        $key = $cell['teacherId'] . '|' . $day . '|' . $p;
                        $this->assertArrayNotHasKey($key, $teacherAt,
                            "seed {$seed}: {$cell['teacherId']} is in two rooms at once "
                            . "on {$day} period {$p}");
                        $teacherAt[$key] = $sk;
                    }
                }
            }
        }
    }

    /** Variation must not quietly cost the school teaching time. */
    public function test_variation_does_not_systematically_lose_placements(): void
    {
        $count = function (int $seed): int {
            $grid = $this->place($this->sections(2), $this->assignments(2), $this->days(), 6, $seed);
            $n = 0;
            foreach ($grid as $byDay) {
                foreach ($byDay as $periods) {
                    foreach ($periods as $cell) { if ($cell !== null) { $n++; } }
                }
            }
            return $n;
        };

        // 2 sections x (6+5+5+4) = 40 periods asked for; 2 x 36 = 72 slots.
        $baseline = $count(0);
        $this->assertSame(40, $baseline, 'premise: the unseeded run places everything');

        foreach ([1, 7, 99, 12345, 777777, 31337] as $seed) {
            $this->assertSame($baseline, $count($seed),
                "seed {$seed} placed a different number of periods — variation "
                . 'must reorder a timetable, not shrink it');
        }
    }
}
