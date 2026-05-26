<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * BUG-006 regression — the Create Homework submit handler must lock the
 * button (disabled + spinner swap) while the ajaxPost is in flight, and
 * restore it in the response callback. Pattern mirrors HW.edit.save earlier
 * in the same view file (lines ~1487-1502). Without the guard, a slow
 * network + rapid double-click writes duplicate homework documents — each
 * with a unique entropy-suffixed hwId so the server-side dedup doesn't
 * catch the duplication.
 *
 * Discovered: cycle 2 (DETECTION_REPORT, 2026-05-21)
 * Fixed:      session 2 cycle 4 (FIX_PATCH, 2026-05-21)
 * Quality bar: FINAL_BLUEPRINT.md §5 D5 (double-submit prevented; buttons
 *              disabled during pending ops); §D2 (no silent data multiplication)
 */
class HomeworkDoubleSubmitTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(
            __DIR__ . '/../../application/views/homework/index.php'
        );
        $this->assertNotFalse($this->src);
    }

    /**
     * The Create button must carry id="createSubmitBtn" so the handler can
     * address it. Without an id, the disable/spinner pattern has nothing
     * to lock.
     */
    public function test_bug006_create_button_has_id(): void
    {
        $this->assertStringContainsString(
            'id="createSubmitBtn"',
            $this->src,
            "BUG-006 regression: Create Homework button lost its id=\"createSubmitBtn\""
        );
        $this->assertStringContainsString(
            'id="createSubmitBtn" class="hw-btn hw-btn-primary" onclick="HW.create.submit()"',
            $this->src,
            "BUG-006 regression: createSubmitBtn id no longer paired with onclick=\"HW.create.submit()\" — refactor may have unwired the lock target"
        );
    }

    /**
     * HW.create.submit must address the button by id, set disabled=true
     * and swap innerHTML to the spinner ("Creating…"), define a restore()
     * closure, and call restore() inside the ajaxPost callback.
     */
    public function test_bug006_submit_handler_locks_button_and_restores(): void
    {
        // Button addressed by id
        $this->assertStringContainsString(
            "document.getElementById('createSubmitBtn')",
            $this->src,
            "BUG-006 regression: submit handler no longer fetches createSubmitBtn"
        );
        // Disable + spinner swap
        $this->assertStringContainsString(
            "btn.disabled = true;",
            $this->src,
            "BUG-006 regression: submit handler no longer disables the button"
        );
        $this->assertStringContainsString(
            "<i class=\"fa fa-spinner fa-spin\"></i> Creating…",
            $this->src,
            "BUG-006 regression: submit handler no longer swaps to the Creating… spinner"
        );
        // Restore closure
        $this->assertStringContainsString(
            "var restore = function() {",
            $this->src,
            "BUG-006 regression: submit handler no longer defines restore() closure"
        );
        // Restore called inside the ajaxPost callback
        // (assertion: 'restore();' appears AFTER the create_homework ajaxPost begin)
        $postOffset = strpos($this->src, "ajaxPost('homework/create_homework'");
        $this->assertNotFalse(
            $postOffset,
            "BUG-006 regression: HW.create.submit no longer calls ajaxPost('homework/create_homework')"
        );
        $afterPost = substr($this->src, $postOffset);
        $this->assertStringContainsString(
            "restore();",
            $afterPost,
            "BUG-006 regression: restore() not called inside the create_homework ajaxPost callback — button will stay locked on response"
        );
    }
}
