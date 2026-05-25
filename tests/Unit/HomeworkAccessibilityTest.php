<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * BUG-005 regression — the 3 non-button click handlers in the homework view
 * (recent-homework card, overdue-alert card, homework-list table row) must
 * carry role="button" + tabindex="0" so keyboard-only users can navigate
 * into the homework detail. A document-level keydown delegate converts
 * Enter/Space presses into click events for those role="button" elements.
 *
 * Static-source assertion (view is a PHP+HTML+JS template — no DOM available
 * in unit-test mode). Fails when a future refactor reintroduces an
 * unannotated `<div onclick>` or `<tr onclick>` template for the 3 sites,
 * or removes the keydown delegate.
 *
 * Discovered: cycle 2 (DETECTION_REPORT, 2026-05-21)
 * Fixed:      session 2 cycle 2 (FIX_PATCH, 2026-05-21)
 * Quality bar: FINAL_BLUEPRINT.md §5 D6 (accessibility); WCAG 2.1 SC 2.1.1
 */
class HomeworkAccessibilityTest extends TestCase
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
     * Each of the 3 BUG-005 sites must emit role="button" tabindex="0"
     * INSIDE the same template string that already contained the onclick
     * handler. Asserted by substring presence — the 3 onclick template
     * fragments are unique enough that role+tabindex coupled with them
     * pins the assertion to the right sites.
     */
    public function test_bug005_three_sites_have_button_role_and_tabindex(): void
    {
        // Recent-homework card (dashboard panel)
        $this->assertStringContainsString(
            "'<div class=\"hw-alert-item\" role=\"button\" tabindex=\"0\" onclick=\"HW.detail.open(",
            $this->src,
            "BUG-005 regression: recent-homework card lost role=\"button\" tabindex=\"0\""
        );
        // Overdue-alert card (dashboard panel)
        $this->assertStringContainsString(
            "'<div class=\"hw-alert-item\" role=\"button\" tabindex=\"0\" onclick=\"HW.detail.open(",
            $this->src,
            "BUG-005 regression: overdue-alert card lost role=\"button\" tabindex=\"0\""
        );
        // Homework-list table row
        $this->assertStringContainsString(
            "'<tr role=\"button\" tabindex=\"0\" onclick=\"HW.detail.open(",
            $this->src,
            "BUG-005 regression: homework-list <tr> lost role=\"button\" tabindex=\"0\""
        );
    }

    /**
     * The document-level keydown delegate must exist so role="button"
     * elements respond to Enter/Space keypresses. Without it, the
     * tabindex="0" + role="button" annotations are visible to screen
     * readers but the keyboard activation contract is broken.
     */
    public function test_bug005_keydown_delegate_present(): void
    {
        $this->assertStringContainsString(
            "document.addEventListener('keydown'",
            $this->src,
            "BUG-005 regression: document-level keydown listener removed"
        );
        $this->assertStringContainsString(
            "e.target.getAttribute('role') === 'button'",
            $this->src,
            "BUG-005 regression: keydown delegate no longer gates on role=\"button\""
        );
        $this->assertStringContainsString(
            "e.target.tagName !== 'BUTTON'",
            $this->src,
            "BUG-005 regression: keydown delegate no longer excludes native <button> elements (would double-fire)"
        );
    }

    /**
     * No naked `<div onclick="HW.detail.open` or `<tr onclick="HW.detail.open`
     * may remain — those are the exact pre-fix patterns and their reappearance
     * indicates a regression at one of the 3 sites.
     */
    public function test_bug005_no_naked_div_or_tr_onclick_for_detail_open(): void
    {
        $this->assertStringNotContainsString(
            "'<div class=\"hw-alert-item\" onclick=\"HW.detail.open(",
            $this->src,
            "BUG-005 regression: pre-fix <div class=\"hw-alert-item\" onclick> pattern reappeared"
        );
        $this->assertStringNotContainsString(
            "'<tr onclick=\"HW.detail.open(",
            $this->src,
            "BUG-005 regression: pre-fix <tr onclick> pattern reappeared"
        );
    }
}
