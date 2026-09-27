<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ATS-D2 — a hire made through ATS never shows its credentials.
 *
 * new_staff's submit handler branches on the hidden ats_application_id. The
 * ATS branch fires ats/finalize_hire, raises a toast, schedules
 *
 *     setTimeout(function(){ window.location.href = '<ats>'; }, 1600)
 *
 * and then `return`s — BEFORE the line that shows the credential modal:
 *
 *     return;                        // ATS branch exits
 *   }
 *   showStaffConfirmation(res);      // never reached on that path
 *
 * A direct create falls through and shows Staff ID + generated password. An
 * ATS hire shows neither, and navigates away in 1.6s. The password is in the
 * response (`default_password`) the whole time — it is simply discarded.
 *
 * That matters operationally: the generated password is a ONE-TIME reveal.
 * Losing it forces an admin password reset for every ATS hire, which is the
 * majority path once recruitment is in use.
 *
 * The modal must also appear when finalize_hire FAILS — the staff record and
 * its Auth account exist regardless of whether the ATS bookkeeping succeeded,
 * so the admin still needs the credentials.
 */
final class AtsHireCredentialHandoffTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $p = __DIR__ . '/../../application/views/new_staff.php';
        $this->assertFileExists($p);
        $this->src = (string) file_get_contents($p);
    }

    /** The ATS branch of the submit success handler. */
    private function atsBranch(): string
    {
        $i = strpos($this->src, "ATS integration: finalize hire");
        $this->assertNotFalse($i, 'the ATS convert-to-staff branch is gone');

        // Bound at the branch's own `return;`, NOT at the first
        // showStaffConfirmation( — once the fix lands, that call sits INSIDE
        // the branch and would truncate it before the error arm.
        $end = strpos($this->src, 'return;', $i);
        $this->assertNotFalse($end, 'the ATS branch no longer returns');
        return substr($this->src, $i, $end - $i);
    }

    /** Premise: the server really does return the one-time password. */
    public function test_the_response_carries_the_generated_password(): void
    {
        $this->assertStringContainsString('res.default_password', $this->src,
            'the credential modal no longer reads default_password');
    }

    /**
     * DEFECT ATS-D2 — the ATS path must reach the credential modal.
     */
    public function test_the_ats_path_shows_the_credentials(): void
    {
        $branch = $this->atsBranch();

        // BOTH arms must hand over. Asserting "appears somewhere in the branch"
        // let a mutant that deleted the success-arm call survive, because the
        // error arm's call still satisfied it. Count instead — slicing the arms
        // by marker proved brittle against the real formatting.
        // Strip JS line comments first — the explanatory comments in this
        // branch NAME showStaffConfirmation(), and counting prose let a mutant
        // that deleted a real call survive.
        $code = (string) preg_replace('~^\s*//.*$~m', '', $branch);
        $calls = preg_match_all('~showStaffConfirmation\(res~', $code);

        $this->assertGreaterThanOrEqual(2, $calls,
            "Both finalize_hire arms must reach showStaffConfirmation(res).\n"
            . "The ATS branch used to return before it, so a hire made through\n"
            . "recruitment never revealed its Staff ID or one-time password —\n"
            . "while a direct create did. Found {$calls} call(s).");
    }

    /** The credentials are needed even if the ATS bookkeeping failed. */
    public function test_credentials_are_shown_even_when_finalize_hire_fails(): void
    {
        $branch = $this->atsBranch();

        $i = strpos($branch, 'error:');
        $this->assertNotFalse($i, 'the finalize_hire error handler is gone');
        $errorArm = substr($branch, $i);

        $this->assertStringContainsString('showStaffConfirmation(', $errorArm,
            'the staff record and its Auth account exist even when finalize_hire '
            . 'fails, so the admin still needs the credentials');
    }

    /**
     * A timed redirect must not race the modal away. The redirect belongs to
     * the modal's Done action, not to a timer.
     */
    public function test_no_timed_redirect_races_the_modal(): void
    {
        $branch = $this->atsBranch();

        // NB: `[^)]*` would stop at the ')' in `function()` and never match the
        // real code — a silently passing test. Match across it instead.
        $this->assertDoesNotMatchRegularExpression(
            '~setTimeout\([\s\S]{0,120}?window\.location\.href~', $branch,
            'a timed redirect closes the window on the credentials before the '
            . 'admin can copy them');
    }

    /** Done must honour where the ATS flow needs to return to. */
    public function test_the_modal_can_redirect_where_the_caller_asks(): void
    {
        $i = strpos($this->src, 'function closeStaffConfirm');
        $this->assertNotFalse($i);
        $fn = substr($this->src, $i, 500);

        $this->assertMatchesRegularExpression(
            '~window\.location\.href~', $fn,
            'closeStaffConfirm() only reloads, so the ATS flow cannot return to '
            . 'the pipeline after the admin dismisses the modal');
    }

    /**
     * The note should tell the truth: staff creation sets
     * must_change_password=true, and the Teacher app routes to a
     * force-change screen on that claim.
     */
    public function test_the_modal_states_the_first_login_password_change(): void
    {
        $i = strpos($this->src, 'function showStaffConfirmation');
        $this->assertNotFalse($i);
        // The note sits near the end of the builder — window must cover it.
        $end = strpos($this->src, 'function closeStaffConfirm', $i);
        $this->assertNotFalse($end);
        $fn = substr($this->src, $i, $end - $i);

        // Strip markup first — the phrasing wraps "password" in <strong>, so a
        // character-class window would never cross the tag.
        $text = preg_replace('~<[^>]*>~', '', $fn);
        $text = preg_replace("~['+\\s]+~", ' ', (string) $text);

        $this->assertMatchesRegularExpression(
            '~(?i)(set a new|change|reset)\s+password~', (string) $text,
            'the handoff modal should tell the admin the staff member will be '
            . 'asked to set a new password on first login (must_change_password)');
    }
}
