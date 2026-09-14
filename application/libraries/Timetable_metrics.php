<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * TIMETABLE METRICS — the facts about a timetable, with no opinion about them.
 *
 * ── WHY THIS IS SEPARATE FROM VALIDATION ────────────────────────────────
 *
 * Timetable_validation_service answers "what is WRONG with this timetable" and
 * refuses to publish when the answer is a hard violation. This answers a
 * different question: "what is this timetable LIKE" — how the teaching is
 * spread, how much of the curriculum arrived, where the free periods fall.
 *
 * Those are not violations. A timetable with an uneven teacher load is not
 * broken, and one with every teacher on exactly 18 periods is not correct.
 * They are characteristics an administrator compares candidates on.
 *
 * ── WHY NOTHING HERE IS SCORED ──────────────────────────────────────────
 *
 * Every number below is a COUNT of something a school can see for itself. None
 * of them is multiplied by a weight, and they are never combined into a single
 * figure of merit, because deciding that a teacher's gap costs more than an
 * unspread subject is the school's judgement and has not been made. When a
 * scoring policy is approved, it consumes these measurements — it does not
 * replace them.
 *
 * ── WHY IT IS PURE ──────────────────────────────────────────────────────
 *
 * No I/O, no Firestore, no CI dependencies. It takes day documents in the shape
 * they are stored and returns arrays, so it can be driven directly in a test
 * and reused on days that are in memory during generation or read back later.
 */
class Timetable_metrics
{
    /**
     * A timetable's measurable characteristics.
     *
     * @param array $days     day documents: classSection, day, periods[]
     * @param array $expected optional curriculum target, as
     *                        [classSection => [subject => periodsPerWeek]]
     * @return array
     */
    public static function from($days, array $expected = []): array
    {
        if (!is_array($days)) { $days = []; }

        $cells = 0; $filled = 0;
        $freeInterior = 0; $freeTrailing = 0;
        $scheduled = [];          // section => subject => count
        $teacherLoad = [];        // teacherId => periods
        $teacherName = [];
        $byTeacherDay = [];       // teacherId => day => [periodIndex => true]
        $sections = [];

        foreach ($days as $d) {
            if (!is_array($d)) { continue; }
            $sec = (string) ($d['classSection'] ?? '');
            $day = (string) ($d['day'] ?? '');
            if ($sec === '') { continue; }
            $sections[$sec] = true;

            $periods = is_array($d['periods'] ?? null) ? $d['periods'] : [];
            $n = count($periods);

            // A free period at the END of a day is a school finishing early.
            // One in the MIDDLE is a class with nothing to do. Counting them
            // together would hide the only difference that matters.
            $lastFilled = -1;
            foreach ($periods as $i => $p) {
                if (is_array($p) && trim((string) ($p['subject'] ?? '')) !== '') {
                    $lastFilled = $i;
                }
            }

            for ($i = 0; $i < $n; $i++) {
                $cells++;
                $p    = is_array($periods[$i] ?? null) ? $periods[$i] : [];
                $subj = trim((string) ($p['subject'] ?? ''));
                $tid  = trim((string) ($p['teacherId'] ?? ''));

                if ($subj === '') {
                    if ($i < $lastFilled) { $freeInterior++; } else { $freeTrailing++; }
                    continue;
                }

                $filled++;
                $scheduled[$sec][$subj] = ($scheduled[$sec][$subj] ?? 0) + 1;

                if ($tid !== '') {
                    $teacherLoad[$tid] = ($teacherLoad[$tid] ?? 0) + 1;
                    $byTeacherDay[$tid][$day][$i] = true;
                    $nm = trim((string) ($p['teacher'] ?? ''));
                    if ($nm !== '' && !isset($teacherName[$tid])) { $teacherName[$tid] = $nm; }
                }
            }
        }

        // ── curriculum shortfall ─────────────────────────────────────────
        // Only computed where a target was supplied. A missing target is NOT
        // reported as "nothing asked for" — it is reported as not known, so a
        // caller cannot mistake an unmeasured timetable for a complete one.
        $short = ['known' => !empty($expected), 'total' => 0, 'bySubject' => []];
        if (!empty($expected)) {
            foreach ($expected as $sec => $wanted) {
                if (!is_array($wanted)) { continue; }
                foreach ($wanted as $subj => $asked) {
                    $asked = (int) $asked;
                    if ($asked <= 0) { continue; }
                    $got = (int) ($scheduled[$sec][$subj] ?? 0);
                    if ($got < $asked) {
                        $short['total'] += ($asked - $got);
                        $short['bySubject'][] = [
                            'section' => $sec, 'subject' => (string) $subj,
                            'asked' => $asked, 'scheduled' => $got,
                            'short' => $asked - $got,
                        ];
                    }
                }
            }
            usort($short['bySubject'], static function ($a, $b) {
                if ($a['short'] !== $b['short']) { return $b['short'] - $a['short']; }
                return strcmp($a['section'] . $a['subject'], $b['section'] . $b['subject']);
            });
        }

        // ── teaching load ────────────────────────────────────────────────
        $loads = array_values($teacherLoad);
        sort($loads);
        $load = [
            'teachers' => count($loads),
            'min'      => $loads ? $loads[0] : 0,
            'max'      => $loads ? $loads[count($loads) - 1] : 0,
            'spread'   => $loads ? ($loads[count($loads) - 1] - $loads[0]) : 0,
            'byTeacher' => $teacherLoad,
            'names'     => $teacherName,
        ];

        // ── longest run without a break, and gaps between classes ────────
        $longest = 0; $gaps = 0;
        foreach ($byTeacherDay as $tid => $days2) {
            foreach ($days2 as $day => $slots) {
                $idx = array_keys($slots);
                sort($idx);
                $run = 1;
                for ($i = 1; $i < count($idx); $i++) {
                    if ($idx[$i] === $idx[$i - 1] + 1) {
                        $run++;
                    } else {
                        $gaps += ($idx[$i] - $idx[$i - 1] - 1);
                        $run = 1;
                    }
                    if ($run > $longest) { $longest = $run; }
                }
                if (count($idx) === 1 && $longest < 1) { $longest = 1; }
                if ($run > $longest) { $longest = $run; }
            }
        }

        return [
            'sections'       => count($sections),
            'cells'          => $cells,
            'filled'         => $filled,
            'free'           => $cells - $filled,
            'freeInterior'   => $freeInterior,
            'freeTrailing'   => $freeTrailing,
            'shortfall'      => $short,
            'teacherLoad'    => $load,
            'longestStretch' => $longest,
            'teacherGaps'    => $gaps,
        ];
    }

    /**
     * The placement of every slot, as a flat map.
     *
     * The key is (section, day, period) and the value is what is taught there.
     * Two timetables are the same exactly when these maps are equal, which is
     * what makes both the signature and the distance below meaningful.
     */
    public static function placements($days): array
    {
        $out = [];
        if (!is_array($days)) { return $out; }
        foreach ($days as $d) {
            if (!is_array($d)) { continue; }
            $sec = (string) ($d['classSection'] ?? '');
            $day = (string) ($d['day'] ?? '');
            if ($sec === '') { continue; }
            $periods = is_array($d['periods'] ?? null) ? $d['periods'] : [];
            foreach ($periods as $i => $p) {
                $p = is_array($p) ? $p : [];
                $out["{$sec}|{$day}|{$i}"] =
                    trim((string) ($p['subject'] ?? '')) . '|' .
                    trim((string) ($p['teacherId'] ?? ''));
            }
        }
        ksort($out);
        return $out;
    }

    /** A stable hash of a timetable's placements. Equal hash = same timetable. */
    public static function signature($days): string
    {
        $p = self::placements($days);
        return hash('sha256', json_encode($p, JSON_UNESCAPED_UNICODE));
    }

    /**
     * HAMMING DISTANCE between two timetables: how many slots are taught
     * differently.
     *
     * ── WHY HAMMING ─────────────────────────────────────────────────────
     *
     * The diversity literature measures how different two solutions are with a
     * distance function over their assignments, and for scheduling the standard
     * choice is Hamming distance — the count of positions whose assignment
     * differs. Work specifically on school schedules finds Jaccard similarity
     * marginally more accurate but Hamming distance considerably faster to
     * evaluate, and speed matters here because every candidate is compared with
     * every other one.
     *
     * It is also the metric a person can check: "these two timetables differ in
     * 34 of 216 periods" is a sentence an administrator can act on, which a
     * cosine similarity is not.
     *
     * A slot present in one timetable and absent from the other counts as a
     * difference — otherwise a timetable missing a whole day would look
     * identical to one that has it.
     */
    public static function distance($daysA, $daysB): array
    {
        $a = self::placements($daysA);
        $b = self::placements($daysB);

        $keys = array_keys($a + $b);
        $differing = 0;
        foreach ($keys as $k) {
            if (($a[$k] ?? null) !== ($b[$k] ?? null)) { $differing++; }
        }
        $total = count($keys);

        return [
            'differing' => $differing,
            'cells'     => $total,
            // Reported to four places so a very small difference does not round
            // to zero and read as "identical".
            'ratio'     => $total > 0 ? round($differing / $total, 4) : 0.0,
            'identical' => $differing === 0,
        ];
    }
}
