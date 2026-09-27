<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__ . '/../../system/');
if (!defined('APPPATH'))  define('APPPATH',  __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../../application/libraries/Rollover_projection_guard.php';

/**
 * TT-D3 — THE SETTINGS CARRY HAD THE BUG THE TIMETABLE CARRY WAS FIXED FOR.
 *
 * Gate A closed a swallowed destination read on the rollover's `timetables`
 * carry: a failed read left no marker, "no marker" read as "not yet rolled",
 * and the tool overwrote a document it had never seen. The `timetableSettings`
 * carry twenty lines above it had exactly the same shape and was not touched:
 *
 *     try {
 *         $src = $fs->getDocument('timetableSettings', $srcId);
 *         $dst = $fs->getDocument('timetableSettings', $dstId);
 *     } catch (\Throwable $e) { $src = null; $dst = null; }
 *
 * One try, both documents. A failure on EITHER read produced two nulls, so:
 *
 *   • a failed DESTINATION read skipped the already-rolled check and wrote,
 *     replacing this year's periods-per-day and bell times with last year's —
 *     and settings are what the whole timetable is drawn against;
 *   • a failed SOURCE read printed "no source doc — skip", reporting a failure
 *     as an absence and leaving the operator with a clean-looking run.
 *
 * The fix routes the destination decision through the same guard the timetable
 * carry uses, which is also why this file asserts against that guard's
 * behaviour rather than against the script's text: the rule now has one
 * implementation and one place to test it.
 */
final class RolloverSettingsReadHonestyTest extends TestCase
{
    private const REV = 1;

    private function script(): string
    {
        return (string) file_get_contents(
            __DIR__ . '/../../scripts/rollover_academic_session.php');
    }

    /** Comments describing a hazard are not the hazard. */
    private function code(): string
    {
        $src = $this->script();
        $src = (string) preg_replace('~/\*.*?\*/~s', '', $src);
        return (string) preg_replace('~^\s*//.*$~m', '', $src);
    }

    // ── the behaviour the settings carry now inherits ────────────────────

    public function test_an_unreadable_settings_destination_is_not_written_over(): void
    {
        $this->assertSame(
            Rollover_projection_guard::SKIP_UNREADABLE,
            Rollover_projection_guard::decide(null, self::REV, true),
            'not knowing what is in the destination is not permission to '
            . 'replace it');
    }

    public function test_a_settings_destination_already_rolled_is_skipped(): void
    {
        $this->assertSame(
            Rollover_projection_guard::SKIP_ALREADY_ROLLED,
            Rollover_projection_guard::decide(
                ['schoolId' => 'SCH_A', 'session' => '2026-27',
                 'periodsPerDay' => 8, 'phase3RolloverRev' => self::REV],
                self::REV, false));
    }

    public function test_an_absent_settings_destination_is_written(): void
    {
        $this->assertSame(Rollover_projection_guard::WRITE,
            Rollover_projection_guard::decide(null, self::REV, false),
            'the ordinary case must still work — a guard that refuses '
            . 'everything is not a guard');
    }

    // ── the script actually routes through it ────────────────────────────

    /**
     * The two reads must be SEPARATE, or one failure still speaks for both.
     *
     * This is the structural half, and it is here because the shape of the
     * try/catch is the defect — no behavioural test of the guard can see a
     * caller that reads both documents inside one try and then discards which
     * of them failed.
     */
    public function test_the_source_and_destination_reads_are_separated(): void
    {
        $code = $this->code();

        // The precise shape of the defect: ONE catch speaking for BOTH reads.
        $this->assertDoesNotMatchRegularExpression(
            '~catch\s*\(\\\\Throwable\s*\$e\)\s*\{\s*\$src\s*=\s*null;\s*'
            . '\$dst\s*=\s*null;\s*\}~',
            $code,
            'a single catch that nulls both documents makes one failure claim '
            . 'that BOTH are absent — which is how a failed destination read '
            . 'became an overwrite');

        $this->assertMatchesRegularExpression(
            '~catch \(\\\\Throwable \$e\) \{ \$src = null; \$srcFailed = true; \}~',
            $code, 'a failed source read is recorded as a failure');
        $this->assertMatchesRegularExpression(
            '~catch \(\\\\Throwable \$e\) \{ \$dst = null; \$dstFailed = true; \}~',
            $code, 'and so is a failed destination read');
    }

    public function test_the_settings_carry_asks_the_shared_guard(): void
    {
        $this->assertMatchesRegularExpression(
            '~\$settingsDecision\s*=\s*Rollover_projection_guard::decide\(\s*'
            . '\$dst,\s*PHASE3_ROLLOVER_REV,\s*\$dstFailed\s*\)~s',
            $this->code(),
            'the settings carry uses the same decision as the timetable carry, '
            . 'so the two cannot drift apart again');
    }

    /**
     * A failed read must move a FAILURE counter, not a tidy "skipped" one.
     *
     * An operator reads the summary line, and "skipped" is what a rollover
     * says about work it correctly decided not to do. Work it could not even
     * look at has to be countable separately or the run reports success.
     */
    public function test_an_unreadable_settings_destination_counts_as_a_failure(): void
    {
        $this->assertMatchesRegularExpression(
            '~\$bucket\s*=\s*\(\$settingsDecision\s*===\s*'
            . 'Rollover_projection_guard::SKIP_UNREADABLE\)\s*\?\s*'
            . "'failed'\s*:\s*'skipped'~s",
            $this->code());
    }

    /**
     * TT-D6 — THE GUARD HAS TO BE ABLE TO HEAR THAT A READ FAILED.
     *
     * Every carry in this tool read its destination with `getDocument()`,
     * which returns null for any failure that is not a 404. The catch could
     * therefore never fire, `$readFailed` was permanently false, and the
     * guard's unreadable-destination arm — proven as a pure function, held by
     * its own tests, its mutants killed — was unreachable from the only
     * caller that matters. The guard said the right thing to nobody.
     *
     * `getDocumentOrFail()` is the read that distinguishes "nothing is there"
     * from "I could not look". Its own docblock says to use it "wherever null
     * would be read as absent and drive a create-vs-update decision", which is
     * precisely what each of these four sites does.
     *
     * It matters for a second reason on `timetableSettings`: that collection
     * is on the cross-request file-backed config cache (300s), so
     * `getDocument()` could answer this decision from a document state up to
     * five minutes old written by an unrelated process. getDocumentOrFail
     * bypasses both caches.
     */
    public function test_every_rollover_destination_read_is_fail_loud(): void
    {
        $code = $this->code();

        preg_match_all('~\$fs->getDocument\(\s*\'([a-zA-Z]+)\'~', $code, $m);
        $this->assertSame([], $m[1],
            'a swallowing read cannot tell the guard that it failed, so the '
            . 'guard is handed "absent" and answers "write" — over a document '
            . 'nobody could see. Collections still using it: '
            . implode(', ', $m[1]));

        preg_match_all('~\$fs->getDocumentOrFail\(\s*\'([a-zA-Z]+)\'~', $code, $ok);
        sort($ok[1]);
        $this->assertSame(
            ['curriculum', 'subjectAssignments', 'timetableSettings',
             'timetableSettings', 'timetables'],
            $ok[1],
            'all four carries — and both settings reads — decide from a live, '
            . 'fail-loud read');
    }

    /**
     * And each of them must route the answer through the shared guard rather
     * than re-testing the revision marker inline, which is how the settings
     * carry came to be missing the protection the timetable carry had.
     */
    public function test_every_carry_decides_through_the_shared_guard(): void
    {
        $code = $this->code();

        $this->assertSame(4,
            preg_match_all('~Rollover_projection_guard::decide\(~', $code),
            'timetables, timetableSettings, subjectAssignments and curriculum');

        $this->assertDoesNotMatchRegularExpression(
            '~if \(is_array\(\$existing\) && \(int\)\(\$existing\[\'phase3RolloverRev\'\]~',
            $code,
            'no carry keeps its own inline copy of the already-rolled test '
            . 'beside the shared one');
    }

    public function test_an_unreadable_source_is_not_reported_as_a_missing_source(): void
    {
        $code = $this->code();

        $this->assertMatchesRegularExpression(
            '~if \(\$srcFailed\) \{.*?\$stats\[\'timetableSettings\'\]\[\'failed\'\]\+\+;~s',
            $code,
            '"no source doc" is a statement about the data; a read that threw '
            . 'is a statement about the run, and they must not share a line');

        // And the absence branch is still reachable — the fix must not have
        // turned a legitimate "nothing to carry" into an error.
        $this->assertMatchesRegularExpression(
            '~\} elseif \(!is_array\(\$src\)\) \{~', $code);
    }
}
