<?php

use PHPUnit\Framework\TestCase;

/**
 * APPLY=1 may materialise TODAY and nothing else.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────
 *
 * The legacy document id is `{school}_{session}_{sectionKey}_{WEEKDAY}`. There
 * is no effective-date dimension in it, so that collection holds exactly one
 * timetable: the one teachers see NOW.
 *
 * `FOR_DATE` was read straight from the environment with no validation and no
 * relationship to APPLY. So:
 *
 *   FOR_DATE=<past>   APPLY=1  → every teacher shown a timetable that has ended
 *   FOR_DATE=<future> APPLY=1  → every teacher shown one that has not started
 *
 * Both exited 0, wrote documents indistinguishable from a correct projection,
 * and left the canonical screens — the only place that would reveal it —
 * completely unaffected. Found by execution 2026-09-12 while auditing the
 * scenarios the suite did not cover.
 *
 * The sibling operation already holds this line: supersedeVersion() refuses a
 * past last-effective-date because "ending it on a past date would change which
 * timetable applied on days that have already happened". Same family, same
 * answer.
 *
 * FOR_DATE remains fully available for DRY RUNS — "what would the 13th
 * project?" is a real question that writes nothing.
 */
final class TimetableProjectionDateGuardTest extends TestCase
{
    private static string $cron = '';

    public static function setUpBeforeClass(): void
    {
        self::$cron = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Timetable_projection_cron.php');
    }

    /** The guard body, bounded so neighbouring code cannot satisfy an assertion. */
    private function guard(): string
    {
        $i = strpos(self::$cron, '$today = date(');
        $this->assertNotFalse($i,
            'the APPLY/FOR_DATE guard is gone — a past or future FOR_DATE with '
            . 'APPLY=1 would again materialise a timetable that is not in force');
        return substr(self::$cron, $i, 1400);
    }

    // ── the guard itself ────────────────────────────────────────────────

    public function test_apply_is_refused_for_any_date_that_is_not_today(): void
    {
        $g = $this->guard();

        $this->assertStringContainsString('$this->apply && $this->forDate !== $today', $g,
            'the guard does not compare FOR_DATE against today, so a non-current '
            . 'date can still be materialised');

        $this->assertStringContainsString('exit(2)', $g,
            'the guard does not exit non-zero, so a scheduler would record the run as a success');
    }

    /**
     * It must compare against the SERVER date, the same clock every other dated
     * decision here uses. A UTC-derived "today" would refuse a legitimate run,
     * or admit an illegitimate one, for the hours the two disagree.
     */
    public function test_today_comes_from_the_server_clock(): void
    {
        $g = $this->guard();
        $this->assertMatchesRegularExpression('/\$today\s*=\s*date\(\x27Y-m-d\x27\)/', $g,
            'today is not taken from the server clock');
        $this->assertStringNotContainsString('gmdate', $g,
            'today is taken from UTC, which disagrees with the server date for part of every day');
    }

    /** A refusal that does not say which way it was wrong is hard to act on. */
    public function test_the_refusal_distinguishes_a_past_date_from_a_future_one(): void
    {
        $g = $this->guard();
        $this->assertStringContainsString('already over', $g,
            'a past FOR_DATE is not explained as such');
        $this->assertStringContainsString('not yet in force', $g,
            'a future FOR_DATE is not explained as such');
    }

    // ── counter-tests: the guard must not over-reach ────────────────────

    /**
     * COUNTER-TEST. A dry run is the whole point of FOR_DATE and writes nothing.
     * A guard that blocked it would remove the only safe way to ask "what would
     * the 13th project?" — and would look identical in a green suite.
     */
    public function test_a_dry_run_with_any_date_is_still_allowed(): void
    {
        $g = $this->guard();

        // The condition must be gated on apply. Without that conjunct the same
        // refusal fires for dry runs too.
        $this->assertStringContainsString('$this->apply &&', $g,
            'the guard is not conditional on APPLY, so it would refuse dry runs as well');

        $this->assertStringContainsString('Re-run without APPLY', $g,
            'the refusal does not tell the operator that a dry run is available');
    }

    /**
     * COUNTER-TEST. The ordinary scheduled run passes no FOR_DATE at all, so it
     * defaults to today and must not be refused — that is the run the whole
     * component exists for.
     */
    public function test_the_default_run_targets_today_and_is_not_refused(): void
    {
        $this->assertMatchesRegularExpression(
            '/forDate\s*=\s*\(string\)\s*\(getenv\(\x27FOR_DATE\x27\)\s*\?:\s*\x27\x27\)\s*\?:\s*date\(\x27Y-m-d\x27\)/',
            self::$cron,
            'FOR_DATE no longer defaults to today, so the scheduled run would be refused');
    }

    // ── the reason the rule exists, pinned ──────────────────────────────

    /**
     * The justification is the legacy id shape. If a date ever enters that id,
     * this whole restriction should be revisited rather than left in place by
     * inertia — so the test asserts the premise, not just the rule.
     */
    public function test_the_legacy_document_id_still_has_no_date_dimension(): void
    {
        $svc = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_projection_service.php');

        $this->assertStringContainsString(
            '$docId   = "{$schoolId}_{$session}_{$safeKey}_{$day}";', $svc,
            'the legacy document id changed — if it now carries an effective date, '
            . 'the APPLY/FOR_DATE restriction should be re-derived rather than kept');

        // $day is the weekday, which is what makes the collection single-valued.
        $this->assertStringContainsString("\$day        = (string) (\$d['day'] ?? '');", $svc,
            'the id no longer keys on the weekday');
    }

    /** The documented contract must match the code, or operators trust the wrong one. */
    public function test_the_documented_contract_matches_the_code(): void
    {
        $this->assertStringContainsString('DRY RUN ONLY', self::$cron,
            'the FOR_DATE docblock still offers a capability the code refuses');
    }
}
