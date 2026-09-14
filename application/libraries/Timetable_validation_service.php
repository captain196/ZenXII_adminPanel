<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * TIMETABLE VALIDATION — pure, no I/O.
 *
 * Answers one question about a candidate timetable: what is WRONG with it, and
 * why. It reads nothing and writes nothing, so it can be reasoned about and
 * tested exhaustively; the store decides when to run it and what to record.
 *
 * ── HARD vs SOFT ────────────────────────────────────────────────────────
 *
 * A HARD violation is something that cannot be true of a real school day. A
 * teacher cannot stand in two rooms at 09:00. A section cannot hold two
 * different lessons in one slot. These are not preferences, and no school's
 * policy makes them acceptable, so they are decided here and they BLOCK
 * publishing.
 *
 * A SOFT violation is something a school might dislike — gaps, a subject twice
 * in one day, a heavy afternoon for one teacher. Whether those matter, and how
 * much, is school policy. This class DETECTS and EXPLAINS them and refuses to
 * rank them: assigning weights would be inventing the customer's preferences.
 * Soft violations therefore never block publishing.
 *
 * That split is why there is no single "score" here. aSc and Untis both expose a
 * penalty-point total, but each ships a weight table its customers tune. ZenX
 * has no such table and no evidence for one, so `score` is deliberately null and
 * says why, rather than being a fabricated number an administrator would trust.
 *
 * ── FINGERPRINTS ────────────────────────────────────────────────────────
 *
 * A verdict is only true of the arrangement that produced it. Manual edits mark
 * a version's verdict stale, but that marking is a best-effort write that can
 * fail. So a verdict also carries a fingerprint of exactly what was validated,
 * and the publish gate recomputes it. A verdict that cannot be shown to describe
 * the current content is not trusted, whatever its stale flag says.
 */
class Timetable_validation_service
{
    const HARD = 'hard';
    const SOFT = 'soft';

    // Hard — physically or structurally impossible.
    const H_TEACHER_DOUBLE_BOOKED  = 'teacher_double_booked';
    const H_SLOT_DUPLICATED        = 'slot_duplicated';
    const H_SUBJECT_WITHOUT_TEACHER = 'subject_without_teacher';
    const H_DAY_DUPLICATED         = 'day_duplicated';
    const H_SECTION_HAS_NO_DAYS    = 'section_has_no_days';

    // Soft — a school MIGHT dislike these. Detected, explained, never ranked.
    const S_UNALLOCATED_PERIOD     = 'unallocated_period';
    const S_SUBJECT_TWICE_IN_A_DAY = 'subject_twice_in_a_day';
    const S_MISSING_DAY_FOR_SECTION = 'missing_day_for_section';
    const S_TEACHER_LONG_STRETCH   = 'teacher_long_stretch';

    /** A stretch this long is REPORTED. It is not a threshold for refusal. */
    const LONG_STRETCH = 4;

    /**
     * Validate a candidate's day documents.
     *
     * @param array $days rows as stored: classSection, day, periods[]
     * @return array {
     *   hard: list of violations, soft: list of violations,
     *   hardCount, softCount, publishable: bool,
     *   score: null, scoreReason: string,
     *   fingerprint: string, explain: list of one-line sentences
     * }
     */
    public static function validate($days): array
    {
        if (!is_array($days)) {
            // Not "no violations". We were handed something we cannot read, and
            // reporting a clean verdict for it is the exact failure this
            // programme exists to remove.
            return self::result([[
                'code' => 'unreadable_input', 'severity' => self::HARD,
                'message' => 'The timetable content could not be read, so it cannot be validated.',
                'where' => [],
            ]], [], '');
        }

        $hard = [];
        $soft = [];

        // ── index the content deterministically ──────────────────────────
        $seenDay   = [];   // section => day => count
        $slot      = [];   // section => day => periodNumber => count
        $teacher   = [];   // teacherId => day => periodNumber => [sections]
        $subjectOf = [];   // section => day => [subjects]
        $teacherRow = [];  // teacherId => day => [periodNumber => true]
        $sections  = [];
        $allDays   = [];

        foreach ($days as $d) {
            if (!is_array($d)) continue;
            $sec = (string) ($d['classSection'] ?? '');
            $day = (string) ($d['day'] ?? '');
            if ($sec === '' || $day === '') continue;

            $sections[$sec] = true;
            $allDays[$day]  = true;
            $seenDay[$sec][$day] = ($seenDay[$sec][$day] ?? 0) + 1;

            $periods = is_array($d['periods'] ?? null) ? $d['periods'] : [];
            foreach ($periods as $i => $p) {
                if (!is_array($p)) continue;
                $pn  = (int) ($p['periodNumber'] ?? ($i + 1));
                $sub = trim((string) ($p['subject'] ?? ''));
                $tid = trim((string) ($p['teacherId'] ?? ''));

                $slot[$sec][$day][$pn] = ($slot[$sec][$day][$pn] ?? 0) + 1;

                if ($sub === '' && $tid === '') {
                    $soft[] = [
                        'code' => self::S_UNALLOCATED_PERIOD, 'severity' => self::SOFT,
                        'message' => "{$sec} has nothing scheduled on {$day} in period {$pn}.",
                        'where' => ['section' => $sec, 'day' => $day, 'period' => $pn],
                    ];
                    continue;
                }

                if ($sub !== '' && $tid === '') {
                    // The Teacher app resolves a schedule by teacherId. A lesson
                    // with no teacher is invisible to every teacher, so it would
                    // publish a period nobody is told to attend.
                    $hard[] = [
                        'code' => self::H_SUBJECT_WITHOUT_TEACHER, 'severity' => self::HARD,
                        'message' => "{$sec} has {$sub} on {$day} in period {$pn} with no teacher assigned.",
                        'where' => ['section' => $sec, 'day' => $day, 'period' => $pn, 'subject' => $sub],
                    ];
                }

                if ($tid !== '') {
                    $teacher[$tid][$day][$pn][] = $sec;
                    $teacherRow[$tid][$day][$pn] = true;
                }
                if ($sub !== '') {
                    $subjectOf[$sec][$day][] = $sub;
                }
            }
        }

        // ── H: a section's day stored twice ──────────────────────────────
        foreach ($seenDay as $sec => $dayCounts) {
            foreach ($dayCounts as $day => $n) {
                if ($n > 1) {
                    $hard[] = [
                        'code' => self::H_DAY_DUPLICATED, 'severity' => self::HARD,
                        'message' => "{$sec} has {$n} separate timetables stored for {$day}.",
                        'where' => ['section' => $sec, 'day' => $day],
                    ];
                }
            }
        }

        // ── H: two lessons in one slot for one section ───────────────────
        foreach ($slot as $sec => $byDay) {
            foreach ($byDay as $day => $byPeriod) {
                foreach ($byPeriod as $pn => $n) {
                    if ($n > 1) {
                        $hard[] = [
                            'code' => self::H_SLOT_DUPLICATED, 'severity' => self::HARD,
                            'message' => "{$sec} has {$n} lessons in the same slot on {$day}, period {$pn}.",
                            'where' => ['section' => $sec, 'day' => $day, 'period' => $pn],
                        ];
                    }
                }
            }
        }

        // ── H: one teacher, two places, one moment ───────────────────────
        foreach ($teacher as $tid => $byDay) {
            foreach ($byDay as $day => $byPeriod) {
                foreach ($byPeriod as $pn => $secs) {
                    $distinct = array_values(array_unique($secs));
                    if (count($distinct) > 1) {
                        sort($distinct);
                        $hard[] = [
                            'code' => self::H_TEACHER_DOUBLE_BOOKED, 'severity' => self::HARD,
                            'message' => "Teacher {$tid} is timetabled in " . count($distinct)
                                . " sections at once on {$day}, period {$pn} ("
                                . implode(', ', $distinct) . ').',
                            'where' => ['teacherId' => $tid, 'day' => $day,
                                        'period' => $pn, 'sections' => $distinct],
                        ];
                    }
                }
            }
        }

        // ── H: a section with no days at all ─────────────────────────────
        foreach (array_keys($sections) as $sec) {
            if (empty($seenDay[$sec])) {
                $hard[] = [
                    'code' => self::H_SECTION_HAS_NO_DAYS, 'severity' => self::HARD,
                    'message' => "{$sec} has no timetable at all.",
                    'where' => ['section' => $sec],
                ];
            }
        }

        // ── S: a section missing a day that other sections have ──────────
        //
        // Inferred from the content itself, not from a working-days setting, so
        // this reports rather than refuses: a school may legitimately run one
        // section on a different pattern.
        $dayList = array_keys($allDays);
        sort($dayList);
        foreach (array_keys($sections) as $sec) {
            foreach ($dayList as $day) {
                if (empty($seenDay[$sec][$day])) {
                    $soft[] = [
                        'code' => self::S_MISSING_DAY_FOR_SECTION, 'severity' => self::SOFT,
                        'message' => "{$sec} has no timetable for {$day}, but other sections do.",
                        'where' => ['section' => $sec, 'day' => $day],
                    ];
                }
            }
        }

        // ── S: the same subject twice in one day ─────────────────────────
        foreach ($subjectOf as $sec => $byDay) {
            foreach ($byDay as $day => $subs) {
                $counts = array_count_values($subs);
                ksort($counts);
                foreach ($counts as $sub => $n) {
                    if ($n > 1) {
                        $soft[] = [
                            'code' => self::S_SUBJECT_TWICE_IN_A_DAY, 'severity' => self::SOFT,
                            'message' => "{$sec} has {$sub} {$n} times on {$day}.",
                            'where' => ['section' => $sec, 'day' => $day,
                                        'subject' => $sub, 'count' => $n],
                        ];
                    }
                }
            }
        }

        // ── S: a long unbroken teaching stretch ──────────────────────────
        foreach ($teacherRow as $tid => $byDay) {
            foreach ($byDay as $day => $periodsMap) {
                $pns = array_map('intval', array_keys($periodsMap));
                sort($pns);
                $run = 1; $best = 1; $startOfBest = $pns[0] ?? 0;
                for ($i = 1; $i < count($pns); $i++) {
                    if ($pns[$i] === $pns[$i - 1] + 1) {
                        $run++;
                        if ($run > $best) { $best = $run; $startOfBest = $pns[$i] - $run + 1; }
                    } else {
                        $run = 1;
                    }
                }
                if ($best >= self::LONG_STRETCH) {
                    $soft[] = [
                        'code' => self::S_TEACHER_LONG_STRETCH, 'severity' => self::SOFT,
                        'message' => "Teacher {$tid} teaches {$best} periods without a break on "
                            . "{$day} (from period {$startOfBest}).",
                        'where' => ['teacherId' => $tid, 'day' => $day,
                                    'length' => $best, 'from' => $startOfBest],
                    ];
                }
            }
        }

        return self::result($hard, $soft, self::fingerprint($days));
    }

    /**
     * A stable fingerprint of exactly what was validated.
     *
     * Order-independent, so a re-read that returns the same content in a
     * different order does not look like a change. Content-dependent, so any
     * edit does.
     */
    public static function fingerprint($days): string
    {
        if (!is_array($days)) return '';

        $parts = [];
        foreach ($days as $d) {
            if (!is_array($d)) continue;
            $periods = is_array($d['periods'] ?? null) ? $d['periods'] : [];
            $cells = [];
            foreach ($periods as $i => $p) {
                if (!is_array($p)) continue;
                $cells[] = ((int) ($p['periodNumber'] ?? ($i + 1)))
                    . ':' . trim((string) ($p['subject'] ?? ''))
                    . ':' . trim((string) ($p['teacherId'] ?? ''))
                    . ':' . trim((string) ($p['room'] ?? ''))
                    . ':' . trim((string) ($p['type'] ?? ''));
            }
            sort($cells);
            $parts[] = (string) ($d['classSection'] ?? '') . '|'
                     . (string) ($d['day'] ?? '') . '|' . implode(',', $cells);
        }
        sort($parts);
        return hash('sha256', implode("\n", $parts));
    }

    /** True only if this verdict describes exactly this content. */
    public static function verdictDescribes($verdict, $days): bool
    {
        if (!is_array($verdict)) return false;
        $recorded = (string) ($verdict['fingerprint'] ?? '');
        if ($recorded === '') return false;
        return hash_equals($recorded, self::fingerprint($days));
    }

    private static function result(array $hard, array $soft, string $fingerprint): array
    {
        $explain = [];
        foreach ($hard as $v) $explain[] = 'MUST FIX — ' . $v['message'];
        foreach ($soft as $v) $explain[] = 'Consider — ' . $v['message'];

        return [
            'hard'        => $hard,
            'soft'        => $soft,
            'hardCount'   => count($hard),
            'softCount'   => count($soft),
            'publishable' => count($hard) === 0,
            // Deliberately not a number. See the class docblock: ranking soft
            // violations requires weights that are the school's to choose.
            'score'       => null,
            'scoreReason' => 'Soft constraints are reported but not ranked: a combined '
                           . 'score needs weightings that have not been decided.',
            'fingerprint' => $fingerprint,
            'explain'     => $explain,
        ];
    }
}
