<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Timetable_plan_service.php';

/**
 * The real transition, tested as the real transition.
 *
 * ── THE SCENARIO ────────────────────────────────────────────────────────
 *
 * A live tenant on 2026-09-12:
 *
 *   "Timetable from 30 Aug"  published, effective 2026-08-30 → 2026-09-12
 *   "Timetable from 13 Sep"  published, effective 2026-09-13 → open-ended
 *
 * The second was published ON the 12th, to start on the 13th. That is the
 * ordinary way a school changes its timetable, and it is the case where every
 * date rule in the system has to hold at once: the old one must keep applying
 * until midnight, the new one must not leak out early, and the changeover must
 * survive a projection that runs late, twice, or not at all.
 *
 * These are the pure resolver and the pure date predicate — the same two the
 * screen, the publish path and the cron all defer to. Testing the rules here
 * rather than through a controller is deliberate: it is the only place they
 * exist once.
 */
final class TimetableEffectiveDateBoundaryTest extends TestCase
{
    private const OLD = 'CAND_OLD_30AUG';
    private const NEW = 'CAND_NEW_13SEP';

    /** The two published versions exactly as the tenant holds them. */
    private function versions(): array
    {
        return [
            ['versionId' => self::OLD, 'planId' => 'REGULAR', 'status' => 'published',
             'effectiveFrom' => '2026-08-30', 'effectiveTo' => '2026-09-12'],
            ['versionId' => self::NEW, 'planId' => 'REGULAR', 'status' => 'published',
             'effectiveFrom' => '2026-09-13', 'effectiveTo' => ''],
            // Present, and never live. A school builds proposals in the open.
            ['versionId' => 'PROPOSAL_5', 'planId' => 'REGULAR', 'status' => 'candidate',
             'effectiveFrom' => '2026-09-12', 'effectiveTo' => ''],
            ['versionId' => 'DRAFT_1', 'planId' => 'REGULAR', 'status' => 'draft',
             'effectiveFrom' => '2026-09-12', 'effectiveTo' => ''],
        ];
    }

    private function resolvedOn(string $date): string
    {
        $r = Timetable_plan_service::resolveForDate($this->versions(), $date);
        $this->assertSame(Timetable_plan_service::RESOLVED, $r['status'],
            "the timetable in force on {$date} could not be resolved: "
            . (string) ($r['explain'] ?? ''));
        return (string) ($r['version']['versionId'] ?? '');
    }

    // ── the changeover ──────────────────────────────────────────────────

    public function test_on_the_last_day_the_old_timetable_still_applies(): void
    {
        $this->assertSame(self::OLD, $this->resolvedOn('2026-09-12'),
            'the old timetable stops applying on its own last day — a school that '
            . 'set an end date of the 12th means the 12th is included');
    }

    public function test_on_the_first_day_the_new_timetable_applies(): void
    {
        $this->assertSame(self::NEW, $this->resolvedOn('2026-09-13'));
    }

    /**
     * PUBLISHING IS NOT EXPOSING. The new version exists, is published, and is
     * visible in the office from the moment it is published — and must not
     * reach a single teacher until the 13th.
     */
    public function test_publishing_on_the_12th_does_not_expose_the_13th_timetable(): void
    {
        $new = $this->versions()[1];

        $this->assertFalse(Timetable_plan_service::coversDate($new, '2026-09-12'),
            'the timetable that starts on the 13th is treated as in force on the '
            . '12th, so publishing it would show every teacher tomorrow\'s '
            . 'timetable today');
        $this->assertTrue(Timetable_plan_service::coversDate($new, '2026-09-13'));
    }

    /** Unfinished work is never live, whatever its dates say. */
    public function test_a_draft_or_proposal_never_applies(): void
    {
        foreach ([$this->versions()[2], $this->versions()[3]] as $v) {
            $this->assertFalse(
                Timetable_plan_service::coversDate($v, '2026-09-12'),
                (string) $v['status'] . ' ' . (string) $v['versionId']
                . ' is treated as in force, so a school building a proposal would '
                . 'push it to every teacher');
        }
    }

    /** A version whose end date has passed is over, and stays over. */
    public function test_a_superseded_timetable_does_not_come_back(): void
    {
        $old = $this->versions()[0];
        $this->assertFalse(Timetable_plan_service::coversDate($old, '2026-09-13'),
            'the ended timetable applies again the day after it ended');
    }

    // ── what a late, repeated or missed run must do ─────────────────────

    /**
     * DELAYED RECOVERY. The whole reason a missed night is survivable: the
     * projection asks "what applies on this date", never "what changed since
     * last time". Running late on the 13th therefore materialises the 13th's
     * timetable — not the 12th's, and not a backlog of both.
     */
    public function test_a_run_that_happens_late_on_the_13th_still_materialises_the_13th(): void
    {
        $this->assertSame(self::NEW, $this->resolvedOn('2026-09-13'),
            'a run at 09:00 on the 13th resolves something other than the '
            . 'timetable in force on the 13th, so a missed midnight would not '
            . 'self-repair');
    }

    /** And a run that never happened leaves the 14th resolving to the same thing. */
    public function test_a_completely_missed_day_does_not_change_what_applies_next(): void
    {
        $this->assertSame(self::NEW, $this->resolvedOn('2026-09-14'));
        $this->assertSame(self::NEW, $this->resolvedOn('2026-10-01'));
    }

    /**
     * REPEATED RUNS. Resolution is a pure function of the versions and the date,
     * so asking twice cannot answer differently. Idempotency of the WRITE is
     * proven separately (plan() reports unchanged documents and does not rewrite
     * them); this is idempotency of the DECISION.
     */
    public function test_asking_twice_on_the_same_day_gives_the_same_answer(): void
    {
        $this->assertSame($this->resolvedOn('2026-09-13'), $this->resolvedOn('2026-09-13'));
    }

    // ── the two directions a wrong date could break it ──────────────────

    /**
     * BACKWARD. The guard that prevents this lives in Timetable_projection_cron:
     * APPLY is refused for any date but today. Without it, a stray
     * FOR_DATE=2026-09-12 run on the 13th would roll every app back to
     * yesterday's timetable — and because the legacy collection has no date
     * dimension, nothing would record that it had happened.
     */
    public function test_the_cron_refuses_to_apply_a_past_date(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Timetable_projection_cron.php');

        $this->assertMatchesRegularExpression(
            '~if\s*\(\s*\$this->apply\s*&&\s*\$this->forDate\s*!==\s*\$today\s*\)~', $src,
            'APPLY is no longer restricted to today, so FOR_DATE could roll every '
            . 'app back to a previous timetable or push a future one out early');
        // The refusal must END the run, not merely print. The window spans the
        // explanation the operator is given, which is deliberately long.
        $this->assertMatchesRegularExpression(
            '~\$this->forDate\s*!==\s*\$today\s*\)\s*\{[\s\S]{0,1500}?exit\(2\)~', $src,
            'the mismatch is reported but not refused — execution continues to '
            . 'the projection');
    }

    /**
     * FORWARD. The same single guard covers it — which is the point of writing
     * it as "must equal today" rather than as two comparisons. A future date is
     * refused for the same reason a past one is.
     */
    public function test_the_same_guard_refuses_a_future_date(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Timetable_projection_cron.php');

        // One guard, not two: a pair of bounds could be relaxed on one side.
        $this->assertSame(1, preg_match_all('~\$this->forDate\s*!==\s*\$today~', $src),
            'the today-only rule is expressed more than once, so the past and '
            . 'future cases can drift apart');

        // No CONDITION may test forDate with a one-sided bound, which would
        // admit the other side. Ordering comparisons are fine outside a
        // condition — the refusal message uses one to choose between "already
        // over" and "not yet in force", and that wording is not a guard.
        $this->assertSame(0,
            preg_match_all('~(?:if|elseif|while)\s*\([^)]*\$this->forDate\s*[<>]~', $src),
            'a one-sided comparison on FOR_DATE is being used as a condition, '
            . 'which admits the other direction');
    }

    /** The admin-panel action carries the identical rule. */
    public function test_the_send_button_refuses_any_date_but_today(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Timetable_lifecycle.php');

        $this->assertMatchesRegularExpression('~\$asked\s*!==\s*\$today~', $src,
            'the manual send accepts a date other than today, so the recovery '
            . 'action could do what the cron is forbidden to do');
    }

    // ── ambiguity is refused, never guessed ─────────────────────────────

    /**
     * If two published versions ever covered the same day, the legacy
     * collection could not represent both — it holds ONE document per weekday.
     * Publishing refuses to create this state; the resolver refuses to pick a
     * winner if it somehow exists.
     */
    public function test_two_timetables_on_one_day_is_refused_not_guessed(): void
    {
        $overlapping = [
            ['versionId' => 'A', 'planId' => 'REGULAR', 'status' => 'published',
             'effectiveFrom' => '2026-09-01', 'effectiveTo' => ''],
            ['versionId' => 'B', 'planId' => 'REGULAR', 'status' => 'published',
             'effectiveFrom' => '2026-09-10', 'effectiveTo' => ''],
        ];

        $r = Timetable_plan_service::resolveForDate($overlapping, '2026-09-13');
        $this->assertSame(Timetable_plan_service::AMBIGUOUS, $r['status'],
            'the resolver picks one of two live timetables instead of refusing, '
            . 'so which timetable a school is running would depend on ordering');

        $d = Timetable_plan_service::projectionDecision($r);
        $this->assertSame('refuse', $d['action']);
        $this->assertSame(2, $d['exitCode'],
            'an ambiguous resolution must exit non-zero so the scheduled run '
            . 'raises an alert rather than passing quietly');
    }

    /** "Nothing applies" is a complete answer, and not a failure. */
    public function test_a_day_no_timetable_covers_is_a_clean_no_op(): void
    {
        $r = Timetable_plan_service::resolveForDate($this->versions(), '2026-08-01');
        $this->assertSame(Timetable_plan_service::NONE, $r['status']);

        $d = Timetable_plan_service::projectionDecision($r);
        $this->assertSame('skip', $d['action'],
            'a date before the school had any timetable must not clear what the '
            . 'apps hold');
        $this->assertSame(0, $d['exitCode'],
            'a day with no timetable is not an error and must not page anyone');
    }
}
