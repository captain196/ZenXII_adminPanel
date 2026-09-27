<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * REC-D7 — mutating requests gave no feedback, module-wide.
 *
 * Reported twice by the operator (Add Applicant, then the ATS stage buttons):
 * the request succeeded but the button showed no sign of life. An inventory
 * found ~646 POST call sites across the views, roughly 389 with no progress
 * state, no in-flight guard and no failure handler.
 *
 * Editing 389 call sites by hand is how a view gets corrupted, so the
 * behaviour is bound ONCE at the transport layer by assets/js/zx_busy.js,
 * loaded from the shared footer. Disabling the control is what actually
 * prevents the duplicate-record bug; the spinner is what the user sees.
 *
 * The invariants below are the ones that make a global interceptor safe to
 * run on every screen. Each corresponds to a way it could do harm.
 */
final class GlobalBusyStateNetTest extends TestCase
{
    private string $js;

    protected function setUp(): void
    {
        $p = __DIR__ . '/../../assets/js/zx_busy.js';
        $this->assertFileExists($p, 'the global busy-state net is missing');
        $this->js = (string) file_get_contents($p);
    }

    /** It has to actually be on the page, and after jQuery. */
    public function test_it_is_loaded_from_the_shared_footer_after_jquery(): void
    {
        $f = (string) file_get_contents(__DIR__ . '/../../application/views/include/footer.php');

        $jq = strpos($f, 'jquery/dist/jquery.min.js');
        $zx = strpos($f, 'assets/js/zx_busy.js');

        $this->assertNotFalse($jq, 'jQuery is no longer loaded in the footer');
        $this->assertNotFalse($zx, 'zx_busy.js is not loaded from the shared footer');
        $this->assertLessThan($zx, $jq, 'zx_busy.js must load AFTER jQuery');
    }

    /**
     * HARM 1 — stealing a control from a page that manages it itself.
     *
     * jQuery runs a request's own .always() BEFORE the global ajaxComplete.
     * If the net also managed a control that saveJob() had already disabled,
     * it would restore the snapshot taken mid-spin, leaving the button
     * disabled and spinning permanently.
     */
    public function test_it_never_manages_an_already_disabled_control(): void
    {
        $i = strpos($this->js, 'function busy(el)');
        $this->assertNotFalse($i);
        $fn = substr($this->js, $i, (int) strpos($this->js, "\n    }", $i) - $i);

        $this->assertMatchesRegularExpression(
            '~if\s*\(\s*el\.disabled\s*\)\s*return null;~', $fn,
            'a control the page already disabled must be left alone');

        // Re-entry must count, not re-snapshot.
        $this->assertMatchesRegularExpression(
            '~if\s*\(\s*el\.__zxCount\s*\)\s*\{\s*el\.__zxCount\+\+;\s*return el;~', $fn,
            'a second concurrent request must increment the counter, not re-snapshot');
    }

    /** HARM 2 — a bug here must never disable a control forever. */
    public function test_every_busy_state_has_a_hard_timeout(): void
    {
        $this->assertMatchesRegularExpression('~MAX_BUSY\s*=\s*\d+~', $this->js);
        $this->assertStringContainsString('setTimeout(function () { el.__zxCount = 1; free(el); }, MAX_BUSY)',
            $this->js, 'without a ceiling a lost response leaves the button dead');
    }

    /**
     * HARM 3 — doubling every validation message.
     *
     * MY_Controller::json_error() answers HTTP 400 with a real
     * {status,message} body that the module already displays. The net must
     * stay silent for those and speak only for what nobody handles: CI's
     * blank 403, a PHP fatal, a dead connection.
     */
    public function test_it_stays_silent_when_the_page_has_its_own_message(): void
    {
        $this->assertStringContainsString('function handledByPage(xhr)', $this->js);
        $this->assertMatchesRegularExpression(
            '~&&\s*!handledByPage\(xhr\)~', $this->js,
            'the jQuery path must suppress its notice when a JSON body came back');
        $this->assertMatchesRegularExpression(
            "~ct\.indexOf\('json'\)\s*===\s*-1~", $this->js,
            'the fetch path must suppress its notice for JSON responses');
    }

    /** HARM 4 — guessing another module's toast() signature. */
    public function test_it_does_not_call_the_pages_toast(): void
    {
        // Strip comments first — the file's own notes explain WHY it avoids
        // toast(), and prose must not be mistaken for a call.
        $code = preg_replace('~/\*.*?\*/~s', '', $this->js);
        $code = (string) preg_replace('~^\s*//.*$~m', '', (string) $code);

        $this->assertDoesNotMatchRegularExpression(
            '~(?<![A-Za-z_.])toast\s*\(~', $code,
            'toast() differs per module (toast(m,\'error\') vs toast(m,false)) — calling it is a guess');
    }

    /** HARM 5 — spinning a poller's request, or a GET. */
    public function test_only_mutating_requests_are_intercepted(): void
    {
        $this->assertStringContainsString('function isMutating(method)', $this->js);
        $this->assertMatchesRegularExpression(
            "~method !== 'GET' && method !== 'HEAD'~", $this->js);

        // A POST with no recent click belongs to a poller, not a button.
        $this->assertMatchesRegularExpression('~TRIGGER_WINDOW\s*=\s*\d+~', $this->js);
        $this->assertMatchesRegularExpression(
            '~Date\.now\(\) - triggerAt > TRIGGER_WINDOW~', $this->js);
    }

    /**
     * HARM 6 — spinning the wrong button.
     *
     * `await zxConfirm()` then POST is a common shape. The dialog's own
     * Confirm button is removed from the DOM the moment it resolves, so if it
     * became the trigger there would be nothing left to spin.
     */
    public function test_a_themed_dialog_does_not_steal_the_trigger(): void
    {
        $this->assertMatchesRegularExpression(
            "~closest\('\.zx-dlg-bg'\)\)\s*\{\s*triggerAt = Date\.now\(\); return;~", $this->js,
            'clicking Confirm inside a themed dialog must not reassign the trigger');
    }

    /** A detached control must not be touched. */
    public function test_it_skips_controls_removed_from_the_document(): void
    {
        $this->assertMatchesRegularExpression(
            '~if\s*\(!d\.contains\(trigger\)\)\s*return null;~', $this->js);
    }

    /** Screens must be able to opt a control out. */
    public function test_controls_can_opt_out(): void
    {
        $this->assertStringContainsString("hasAttribute('data-zx-nobusy')", $this->js);
    }
}
