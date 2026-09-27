<?php
/**
 * THE SENDING SIDE.
 *
 * Substitute_service decides who is affected; the controller sends. This test
 * covers the join between them, which no unit test reaches: a service that
 * correctly reports "these three teachers just lost their cover" is worth
 * nothing if nobody calls emit_push().
 *
 * MARK_REGISTRY in functions/index.js is the only push dispatcher in the system,
 * so a mark that is emitted but not registered is a message that is never sent.
 */

use PHPUnit\Framework\TestCase;

final class SubstituteCoverNotificationWiringTest extends TestCase
{
    private const CTRL      = __DIR__ . '/../../application/controllers/Academic.php';
    private const FUNCTIONS = __DIR__ . '/../../functions/index.js';

    /** Comments naming a call must not satisfy an assertion about that call. */
    private function code(): string
    {
        $src = (string) file_get_contents(self::CTRL);
        $out = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { continue; }
                $out .= $t[1];
            } else { $out .= $t; }
        }
        return $out;
    }

    public function test_the_cancellation_mark_is_registered_with_the_dispatcher(): void
    {
        $js = (string) file_get_contents(self::FUNCTIONS);

        $this->assertMatchesRegularExpression(
            '~SUBSTITUTE_CANCELLED:\s*\{~', $js,
            'MARK_REGISTRY is the only push dispatcher; a mark that is emitted but '
            . 'not registered is a message nobody receives');

        $this->assertMatchesRegularExpression(
            "~SUBSTITUTE_CANCELLED:[^\n]*idField:\s*'recipientStaffIds'~", $js,
            'the cancellation is addressed to staff, like the assignment it undoes');
    }

    public function test_both_directions_are_actually_emitted(): void
    {
        $code = $this->code();

        $this->assertMatchesRegularExpression(
            "~emit_push\(\s*\\\$mark~", $code,
            'one sender covers both directions, so they cannot drift apart');

        $this->assertMatchesRegularExpression(
            "~'SUBSTITUTE_CANCELLED'\s*:\s*'SUBSTITUTE_ASSIGNED'~", $code,
            'the withdrawn flag chooses between the two marks');
    }

    /**
     * The audience must come from the service's answer about what was saved,
     * never from the submitted form. Notifying from the form tells people they
     * are covering periods the save may have dropped, and tells nobody at all
     * when an edit moves a period away from them.
     */
    public function test_the_audience_comes_from_what_was_saved(): void
    {
        $code = $this->code();

        $this->assertMatchesRegularExpression(
            "~_notify_cover\(\s*\\\$res\['substituteTeacherIds'\]~", $code,
            'the newly-covering list comes from the saved record');

        $this->assertMatchesRegularExpression(
            "~_notify_cover\(\s*\\\$res\['removedTeacherIds'\]~", $code,
            'and so does the list of people whose period was taken away');

        $this->assertSame(0,
            preg_match_all("~\\\$staffIds\[\] = \\\$sid~", $code),
            'the old path that collected recipients out of the POST body is gone');
    }

    public function test_cancelling_and_deleting_both_tell_the_teacher(): void
    {
        $code = $this->code();

        foreach (['update_substitute', 'delete_substitute'] as $fn) {
            $i = strpos($code, 'function ' . $fn . '(');
            $this->assertNotFalse($i, "{$fn} is missing");

            $body = substr($code, $i, 900);
            $this->assertStringContainsString('_notify_cover', $body,
                "{$fn} changes somebody's day without telling them");

            // The call has to be REACHED. A guard that can never be true
            // leaves the call in the source and the teacher uninformed.
            $this->assertDoesNotMatchRegularExpression('~if\s*\(\s*false\s*\)~', $body,
                "{$fn} has a notification that can never fire");
        }

        $i = strpos($code, 'function update_substitute(');
        $body = substr($code, $i, 900);
        $this->assertMatchesRegularExpression(
            "~coverWithdrawn.*coverRestored~s", $body,
            'a cancellation and a restoration are both changes to somebody\'s '
            . 'day, and both have to reach them');
    }

    /**
     * A failed push must never turn a committed cover into an error on screen.
     * The cover is already saved by the time anybody is notified.
     */
    public function test_a_failed_push_cannot_fail_the_operation(): void
    {
        $code = $this->code();

        $i = strpos($code, 'function _notify_cover(');
        $this->assertNotFalse($i);
        $body = substr($code, $i, 1600);

        $this->assertMatchesRegularExpression('~catch\s*\(\\\\Throwable~', $body,
            'the notifier swallows and logs; the save it reports on is done');
        $this->assertStringContainsString('log_message', $body,
            'and a message nobody received is at least recorded');
    }

    /**
     * "You are covering" and "that cover is cancelled" for the same record must
     * not look like repeats of each other to the de-duplicator.
     */
    public function test_the_two_directions_do_not_deduplicate_against_each_other(): void
    {
        $code = $this->code();

        $i = strpos($code, 'function _notify_cover(');
        $body = substr($code, $i, 1600);

        $this->assertMatchesRegularExpression(
            "~\\\$withdrawn \? 'substitute_cancel_' : 'substitute_assign_'"
            . "|'substitute_' \. \(\\\$withdrawn \? 'cancel_' : 'assign_'\)~", $body,
            'the dedupe key has to carry the direction');
    }
}
