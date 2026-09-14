<?php

use PHPUnit\Framework\TestCase;

/**
 * A stale timetable must be DISCOVERABLE, not merely discoverable-in-principle.
 *
 * ── THE PRODUCTION RISK ─────────────────────────────────────────────────
 *
 * Publishing is instant for the canonical resolver and invisible to the
 * Teacher and Parent apps, which read the legacy `timetables` projection. That
 * projection is written only by a CLI cron, and this repository owns no
 * scheduler — every ZenX cron is driven by the operator's own crontab.
 *
 * So the realistic production failure is not dramatic: a school publishes a
 * correct timetable, nobody runs the cron, and every teacher keeps seeing the
 * previous one. Indefinitely. With no error anywhere. That is exactly how it
 * was found on 2026-09-12, where the only symptom was a teacher missing from a
 * workload table.
 *
 * The scheduler itself is an operator boundary and cannot be closed here. What
 * CAN be closed — and is, by this check — is the silence around it. Health
 * Check is the repository's own diagnostic framework, so this uses it rather
 * than inventing a surface.
 */
final class TimetableStalenessHealthCheckTest extends TestCase
{
    private static string $src = '';

    public static function setUpBeforeClass(): void
    {
        self::$src = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Health_check.php');
    }

    /** The check's own body, bounded by the next check in the array. */
    private function check(): string
    {
        $i = strpos(self::$src, "'name' => 'Teachers See The Published Timetable'");
        $this->assertNotFalse($i,
            'the staleness check is gone — a school could publish a timetable that '
            . 'never reaches a single teacher, with nothing reporting it');
        $next = strpos(self::$src, "'name' => ", $i + 40);
        return substr(self::$src, $i, ($next !== false ? $next - $i : 4000));
    }

    public function test_it_is_registered_in_the_academic_module(): void
    {
        $acad = strpos(self::$src, 'private function _checks_academic');
        $this->assertNotFalse($acad, '_checks_academic is gone');
        $check = strpos(self::$src, "'name' => 'Teachers See The Published Timetable'");
        $this->assertGreaterThan($acad, $check,
            'the check is not inside the academic module, so run_all would not reach it');
    }

    /** It must ask the shared resolver, not re-decide which timetable applies. */
    public function test_it_uses_the_canonical_resolver_and_dry_run(): void
    {
        $c = $this->check();

        $this->assertStringContainsString('resolveForDate(', $c,
            'it does not ask the resolver, so it would be a second answer to '
            . '"which timetable is in force"');
        $this->assertStringContainsString('projectPublishedVersion(', $c,
            'it does not compare against what the apps actually read');
        $this->assertStringContainsString('// dry run', $c,
            'the projection call is not marked as a dry run');
    }

    /**
     * THE LINE THAT MATTERS. A health check that could apply the projection
     * would move the APPLY=1 seam into a diagnostic screen.
     */
    public function test_it_never_applies_the_projection(): void
    {
        $c = $this->check();

        // Two arguments only; $apply defaults to false.
        $this->assertMatchesRegularExpression(
            '~projectPublishedVersion\(\s*\(string\)\s*\(\$res\[\x27version\x27\]\[\x27planId\x27\][^,]*,\s*\$versionId\s*\)~s',
            $c,
            'the projection call passes an explicit apply flag — a diagnostic must '
            . 'only ever dry-run');
    }

    // ── it fails CLOSED: silence must never read as health ──────────────

    /**
     * A stale mirror is a FAILURE, not a warning. It means every teacher is
     * looking at the wrong timetable.
     */
    public function test_a_stale_mirror_is_reported_as_a_failure(): void
    {
        $c = $this->check();

        // The verdict must be the EQUIVALENCE result, not a pending count and
        // not "the projection command completed" — a run from the wrong version
        // completes just as successfully as one from the right version.
        // (Rewritten 2026-09-12 when a live tenant was found holding documents
        // that matched no published version at all; the count said "18 pending",
        // the same thing it says when the apps are one lesson behind.)
        $this->assertStringContainsString("\$dx['inSync']", $c,
            'the check no longer decides from the equivalence diagnosis, so it '
            . 'could report teachers are in sync from a count that happens to be '
            . 'zero');

        // The failure branch reports the diagnosis, which names WHAT is wrong.
        $this->assertStringContainsString("\$dx['summary']", $c,
            'staleness is not reported in terms of what teachers actually see — '
            . 'a class page missing entirely and one a day behind read the same');

        $i = strpos($c, "\$dx['summary']");
        $before = substr($c, max(0, $i - 300), 300);
        $this->assertStringContainsString('_f(', $before,
            'a stale mirror is not reported as a failure, so a health run would '
            . 'pass while every teacher sees the wrong timetable');
    }

    /**
     * Ambiguous or unreadable means we cannot say teachers are seeing the right
     * thing — and saying nothing would read as saying they are.
     */
    public function test_an_undeterminable_state_is_a_failure_not_a_pass(): void
    {
        $c = $this->check();

        $this->assertStringContainsString('cannot be confirmed', $c,
            'an undeterminable resolution does not report itself');

        // Anchored on a token that is CONTIGUOUS in the source: the sentence
        // itself is split across a concatenation, so searching for the rendered
        // phrase found nothing and the test failed for its own formatting.
        $i = strpos($c, 'cannot be confirmed');
        $this->assertNotFalse($i);
        $window = substr($c, max(0, $i - 320), 360);
        $this->assertStringContainsString('_f(', $window,
            'an unresolvable timetable is not a failure, so the check would stay '
            . 'silent about a state it cannot vouch for');
    }

    /**
     * A read that threw is not evidence of health either.
     *
     * The first version of this test asserted only that the two catch blocks
     * and their messages EXISTED — so downgrading a thrown read from _f() to
     * _p() survived mutation. Asserting a message exists proves nothing about
     * what the check then reports; each message must be shown to be a FAILURE.
     */
    public function test_a_thrown_read_is_a_failure(): void
    {
        $c = $this->check();

        $this->assertSame(2, substr_count($c, 'catch (\Throwable $e)'),
            'both the resolve and the comparison must fail closed');

        foreach ([
            'Could not determine which timetable is in force',
            'could not be compared',
        ] as $message) {
            $i = strpos($c, $message);
            $this->assertNotFalse($i, "the '{$message}' path is gone");

            // The _f( must be the call this message is an argument to, so look
            // back only as far as the start of that statement.
            $before = substr($c, max(0, $i - 120), min($i, 120));
            $this->assertStringContainsString('_f(', $before,
                "a thrown read is reported via something other than _f(), so a "
                . "health run would pass while the timetable state is unknown "
                . "('{$message}')");
            $this->assertStringNotContainsString('_p(', $before,
                "a thrown read is reported as a PASS ('{$message}')");
        }
    }

    // ── counter-test: it must not cry wolf ──────────────────────────────

    /**
     * COUNTER-TEST. A school with no timetable published for today is not
     * broken — many schools are mid-setup. Reporting that as a failure would
     * make the check noise, and noisy checks get ignored.
     */
    public function test_no_published_timetable_is_a_pass_not_a_failure(): void
    {
        $c = $this->check();

        $i = strpos($c, 'No timetable is published for');
        $this->assertNotFalse($i, 'the no-timetable case is not handled');
        $window = substr($c, max(0, $i - 160), 200);
        $this->assertStringContainsString('_p(', $window,
            'a school with nothing published yet is reported as a failure, which '
            . 'would make this check noise during setup');
    }

    /** The remedy must be exact and tenant-bound, or it cannot be acted on. */
    public function test_the_failure_carries_the_exact_command(): void
    {
        $c = $this->check();

        // The in-app remedy comes FIRST, because a school office can act on it
        // and cannot act on a shell command (operator ruling 2026-09-12).
        $this->assertStringContainsString('Send to teachers and parents now', $c,
            'the failure does not name the action a school can actually take, '
            . 'leaving a command as the only remedy for a reader who cannot run one');

        // And a note about the PLATFORM scheduler, which distinguishes "this
        // school is behind" from "the nightly job never ran" — different faults
        // with different owners, identical symptoms from inside one school.
        $this->assertStringContainsString('_timetable_scheduler_note()', $c,
            'the failure does not distinguish a stale school from a scheduler '
            . 'that never ran; the second affects EVERY school and pressing the '
            . 'send button would fix this one only until tomorrow');

        $note = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Health_check.php');
        $i = strpos($note, 'private function _timetable_scheduler_note');
        $this->assertNotFalse($i, 'the scheduler note is gone');
        $body = substr($note, $i, 3000);

        $this->assertStringContainsString('--job=timetable', $body,
            'the note does not name the scheduled job an operator should check');
        $this->assertStringContainsString('EVERY school', $body,
            'the note does not say that a missing scheduler affects the whole '
            . 'platform, so it reads as this school\'s problem');

        // The remedy must NOT be per-school any more: that was the architecture
        // this correction removed. A tenant-specific cron line is a per-customer
        // deployment task, which does not scale past the first school.
        $this->assertStringNotContainsString('--schoolId=', $body,
            'the health check still tells an operator to install a per-school '
            . 'scheduler entry; the platform job takes no tenant arguments');

        // It must not promise automation that may not be installed.
        $this->assertStringNotContainsString('refreshed by a scheduled task', $c,
            'the check tells the school a scheduled task will fix this; nothing '
            . 'in this repository installs one, so that may simply be untrue');
    }

    /** Tenant identity comes from the server context, never a request value. */
    public function test_the_tenant_is_server_derived(): void
    {
        $c = $this->check();
        $this->assertStringContainsString('(string) $this->school_id', $c);
        $this->assertStringNotContainsString("input->post(", $c);
        $this->assertStringNotContainsString("input->get(", $c);
    }
}
