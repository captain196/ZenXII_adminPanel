<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ATT-D2 — "Checked out." when there was never a check-in.
 *
 * Found by calling the real punch API as a real employee (STA0110) in this
 * order, against the live tenant:
 *
 *   out  (no prior check-in) -> {"message":"Checked out."}   <- the defect
 *   in                       -> "Checked in (late)."
 *   in   (again)             -> "Already checked in."        <- correctly refused
 *   in   (new punch id)      -> "Already checked in."        <- correctly refused
 *
 * Accepting the orphan punch is DELIBERATE and is left alone: Attendance_policy
 * ::_evaluate_checkout() treats a check-out with no hours schedule as audit-only,
 * documented as "legacy behaviour, fully back-compatible", and the punch is
 * recorded in attendancePunches for the audit trail.
 *
 * What was wrong is what the employee was told. That punch sets no mark, writes
 * no summary and books no worked hours — yet the API answered "Checked out.",
 * which the device renders as success. The employee concludes their day is on
 * file when nothing was recorded.
 *
 * The distinction this guards: ACCEPTING the punch is fine; CLAIMING a check-out
 * happened is not.
 */
final class PunchMessageTruthfulnessTest extends TestCase
{
    private static string $ctrl   = '';
    private static string $policy = '';

    public static function setUpBeforeClass(): void
    {
        $root = __DIR__ . '/../../application/';
        self::$ctrl   = (string) file_get_contents($root . 'controllers/Staff_attendance.php');
        self::$policy = (string) file_get_contents($root . 'libraries/Attendance_policy.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$ctrl = self::$policy = '';
    }

    private function messageBuilder(): string
    {
        $i = strpos(self::$ctrl, 'private function _punch_message');
        $this->assertNotFalse($i, '_punch_message() has moved or been renamed');

        $j = strpos(self::$ctrl, "\n    /**", (int) $i);
        return substr(self::$ctrl, (int) $i, ($j === false ? 1400 : (int) $j - (int) $i));
    }

    /** The premise: the engine really does allow a check-out with no check-in. */
    public function test_the_policy_still_allows_an_orphan_checkout(): void
    {
        $this->assertStringContainsString('_evaluate_checkout', self::$policy);
        $this->assertStringContainsString('No check-out TIME gate', self::$policy,
            'if a gate was added, this message branch may be unreachable — re-check');

        // The hours classification is skipped when the check-in time is unknown.
        $this->assertStringContainsString("\$checkInMin = \$context['checkInMinutes'] ?? null;", self::$policy);
    }

    /** DEFECT ATT-D2: the message must not claim a check-out that did not happen. */
    public function test_an_orphan_checkout_is_not_reported_as_checked_out(): void
    {
        $body = $this->messageBuilder();

        $this->assertStringContainsString('$checkInMinutes', $body,
            'the message cannot be truthful without knowing whether a check-in exists');

        $this->assertStringContainsString('if ($checkInMinutes === null)', $body,
            'the no-check-in case must be handled before any "Checked out" wording');

        $this->assertStringContainsString('nothing to check out of', $body);

        // The guard must come BEFORE the mark-based branches, or the M/A cases
        // would answer first and re-introduce the false claim.
        $guard = strpos($body, 'if ($checkInMinutes === null)');
        $mHalf = strpos($body, "\$mark === 'M'");
        $this->assertNotFalse($guard);
        $this->assertNotFalse($mHalf);
        $this->assertLessThan($mHalf, $guard,
            'the no-check-in guard must precede the half-day/absent wording');
    }

    /** The honest path tells the employee what to do next. */
    public function test_the_message_points_at_the_remedy(): void
    {
        $this->assertStringContainsString('regularize', strtolower($this->messageBuilder()),
            'an employee whose day was not recorded needs to know how to fix it');
    }

    /** The genuine check-out wording is untouched — verified live on 2026-08-26. */
    public function test_a_real_checkout_still_reads_correctly(): void
    {
        $body = $this->messageBuilder();

        $this->assertStringContainsString('Checked out — half day recorded.', $body);
        $this->assertStringContainsString('Checked out — below minimum hours; marked absent.', $body);
        $this->assertStringContainsString("return 'Checked out.';", $body);
    }

    /** The caller must actually pass the value, or the guard never fires. */
    public function test_the_call_site_supplies_the_checkin_state(): void
    {
        $this->assertStringContainsString(
            '$this->_punch_message($decision, $direction, $checkInMinutes)', self::$ctrl,
            'passing nothing leaves the parameter null for check-INs too');

        // And that variable is genuinely resolved for check-outs.
        $this->assertStringContainsString("\$checkInMinutes = (\$direction === 'out')", self::$ctrl);
    }

    /**
     * Recorded, not fixed: a refused duplicate punch is stored with
     * outcome='accepted' AND rejectionReason='already_checked_in'.
     *
     * Observed live — two duplicate check-ins were written exactly that way.
     * It corrupts nothing: the only consumer, _today_checkin_minutes(), takes
     * the EARLIEST accepted 'in', so a duplicate cannot shift the check-in time
     * or inflate a count. But the ledger contradicts itself, and an audit that
     * counts outcome='accepted' would count refusals as punches.
     */
    public function test_the_outcome_and_rejection_reason_can_disagree(): void
    {
        $this->assertStringContainsString(
            "'outcome'         => !empty(\$decision['allowed']) ? 'accepted' : 'rejected',", self::$ctrl);
        $this->assertStringContainsString("'rejectionReason' => (string) (\$decision['reason'] ?? ''),", self::$ctrl);

        // The consumer that makes this harmless today.
        $this->assertStringContainsString('if ($best === null || $min < $best) $best = $min;', self::$ctrl,
            'earliest-IN-wins is what stops a duplicate from moving the check-in time');

        $this->markTestIncomplete(
            'ATT-D3 (RECORDED, NOT FIXED): attendancePunches rows can carry '
            . "outcome='accepted' together with rejectionReason='already_checked_in'. "
            . 'Harmless today because _today_checkin_minutes() takes the earliest '
            . 'accepted IN, so a duplicate cannot change the recorded check-in. '
            . 'It is still a self-contradictory audit row, and any future report '
            . 'that counts outcome=accepted would count refusals as real punches. '
            . 'Renaming the field or setting outcome=no_op changes the shape of an '
            . 'audit ledger that already holds live rows, so it is a data decision '
            . 'rather than a code fix.'
        );
    }
}
