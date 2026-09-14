<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ACAD-D6 / ACAD-D7 — editing ONE timetable cell can silently destroy the rest
 * of that day.
 *
 * savePeriod() does not patch a single cell. It REBUILDS the whole day document:
 *
 *     for ($p = 0; $p < max($maxP, $periodIdx + 1); $p++) {
 *         if ($p === $periodIdx)               { …the new cell… }
 *         elseif (isset($existingPeriods[$p])) { $periodDocs[] = $existingPeriods[$p]; }
 *         else                                  { $periodDocs[] = ['subject' => '', …]; }
 *     }
 *
 * Both inputs to that loop are read behind silent catches:
 *
 *   ACAD-D6 — the existing day-doc read. On failure $existingPeriods is [],
 *             so every period except the edited one is rewritten BLANK. One
 *             cell edit wipes the section's whole day.
 *
 *   ACAD-D7 — the timetable-settings read. On failure $maxP falls back to 8,
 *             so a school running 9 or 10 periods a day has its trailing
 *             periods dropped from the rebuilt document entirely.
 *
 * Neither reports anything. The save returns success and the data is gone.
 *
 * A read whose result decides what to PRESERVE cannot fail silently: if we
 * cannot see what is already there, we must not overwrite it. Same principle
 * already applied at the conflict-evidence fetch (ACAD-D5) and in
 * Curriculum_service::_canEdit().
 */
final class TimetableRebuildPreservesDayTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $p = __DIR__ . '/../../application/libraries/Timetable_service.php';
        $this->assertFileExists($p);
        $this->src = (string) file_get_contents($p);
    }

    private function method(string $name): string
    {
        $i = strpos($this->src, "function {$name}(");
        $this->assertNotFalse($i, "{$name}() is gone");
        $end = strpos($this->src, "\n    public function ", $i + 10);
        if ($end === false) $end = strpos($this->src, "\n    private function ", $i + 10);
        $this->assertNotFalse($end, "could not bound {$name}()");
        return substr($this->src, $i, $end - $i);
    }

    /** Comments naming a call must not satisfy assertions about that call. */
    private function codeOnly(string $name): string
    {
        $s = preg_replace('~/\*.*?\*/~s', '', $this->method($name));
        return (string) preg_replace('~^\s*//.*$~m', '', (string) $s);
    }

    /** Premise: the day really is rebuilt, not patched. */
    public function test_save_period_rebuilds_the_whole_day(): void
    {
        $fn = $this->codeOnly('savePeriod');

        $this->assertMatchesRegularExpression('~\$periodDocs\s*=\s*\[\];~', $fn,
            'savePeriod no longer rebuilds the day document — re-evaluate this defect');
        $this->assertStringContainsString('existingPeriods', $fn,
            'the preservation source is gone');
    }

    /**
     * DEFECT ACAD-D6 — the read that decides what to preserve must not be
     * swallowed. An empty result is indistinguishable from "the day is empty".
     */
    public function test_the_existing_day_read_does_not_fail_silently(): void
    {
        $fn = $this->codeOnly('savePeriod');

        $i = strpos($fn, 'existingPeriods');
        $this->assertNotFalse($i);
        $region = substr($fn, max(0, $i - 1200), 2400);

        $this->assertDoesNotMatchRegularExpression(
            '~catch\s*\([^)]*\)\s*\{\s*\}~', $region,
            "The read whose result decides which periods to PRESERVE is swallowed.\n"
            . "On failure every period except the edited one is rewritten blank —\n"
            . 'one cell edit wipes the section\'s whole day, and the save reports success.');
    }

    /**
     * DEFECT ACAD-D7 — a defaulted period count silently truncates longer days.
     */
    public function test_the_period_count_is_not_silently_defaulted(): void
    {
        $fn = $this->codeOnly('savePeriod');

        $this->assertDoesNotMatchRegularExpression(
            '~\$maxP\s*=\s*\(int\)\s*\([^;]*\?\?\s*8\s*\)\s*;~', $fn,
            "maxP falls back to 8 when the settings read fails, so a school running\n"
            . "9 or 10 periods a day loses its trailing periods from the rebuilt\n"
            . 'document. The count must come from settings or the save must refuse.');
    }

    /**
     * Same shape in the bulk writer: it reads the existing doc to bump the
     * version stamp the integrity verifier depends on, and to keep period
     * times the payload omitted.
     */
    public function test_the_bulk_writer_does_not_fail_silently(): void
    {
        $fn = $this->codeOnly('saveSectionTimetable');

        $this->assertDoesNotMatchRegularExpression(
            '~catch\s*\([^)]*\)\s*\{\s*\}~', $fn,
            'a failed read resets the version stamp to 0 and wipes preserved '
            . 'period times, while reporting success');
    }

    /** Failing closed is only useful if the reason is recoverable from logs. */
    public function test_the_preservation_failures_are_logged(): void
    {
        // EVERY catch that refuses must also log. Requiring only one logged
        // catch per method let a mutant that silenced one of them survive,
        // because a sibling catch still satisfied the assertion.
        foreach (['savePeriod', 'saveSectionTimetable'] as $m) {
            $code = $this->codeOnly($m);

            // Anchor on the fix markers themselves. Splitting on `catch (` mis-
            // attributed the hard teacher-conflict throw — which sits in ordinary
            // code between catches and legitimately has no log — to the preceding
            // catch, and brace matching by regex mis-slices multi-line bodies.
            $raw = $this->method($m);
            preg_match_all('~ACAD-D[567]~', $raw, $markers, PREG_OFFSET_CAPTURE);

            $this->assertNotEmpty($markers[0], "{$m}: no hardened handler found to check");

            $checked = 0;
            foreach ($markers[0] as $k => [$_, $at]) {
                $window = substr($raw, $at, 1400);

                $t = strpos($window, 'throw new Service_exception');
                $this->assertTrue($t !== false, "{$m}: handler #{$k} does not refuse");

                // Only EXCEPTION handlers must log. A validation guard (missing
                // configuration) is a user-fixable state, not a system failure —
                // logging it as an error would be noise. Presence of the caught
                // exception is what distinguishes the two.
                if (strpos(substr($window, 0, $t), '$e->getMessage()') === false) {
                    continue;
                }

                $checked++;
                $l = strpos($window, 'log_message');
                $this->assertTrue($l !== false && $l < $t,
                    "{$m}: handler #{$k} throws without logging first — a refusal "
                    . 'that cannot be diagnosed is its own trap');
            }

            $this->assertGreaterThan(0, $checked,
                "{$m}: no exception handler found to check");
        }
    }
}
