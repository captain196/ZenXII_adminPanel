<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * BUG-007 regression — 4 destructive-action sites in views/homework/index.php
 * MUST use the project-consistent HW.confirmDialog helper rather than native
 * window.confirm(). The helper reuses existing .hw-modal-* CSS primitives.
 *
 * Sites: HW.dash._closeOverdue, HW.actions.close, HW.actions.remove,
 *        HW.create.submit.
 *
 * Discovered: cycle 2 (DETECTION_REPORT, 2026-05-21)
 * Fixed:      session 6 cycle 1 (FIX_PATCH, 2026-05-21) — per approved FIX_PLAN
 * Quality bar: FINAL_BLUEPRINT.md §5 D5 (toasts and inline errors consistent;
 *              destructive actions confirmed); §10 brand voice (enterprise, not
 *              dev-tools-default UI)
 */
class HomeworkConfirmHelperTest extends TestCase
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
     * The HW.confirmDialog helper must be defined with open/confirm/cancel
     * methods, and the supporting #hwConfirmModal HTML scaffold present.
     */
    public function test_bug007_confirm_dialog_helper_present(): void
    {
        $this->assertStringContainsString(
            "HW.confirmDialog = {",
            $this->src,
            "BUG-007 regression: HW.confirmDialog helper removed"
        );
        $this->assertStringContainsString(
            'id="hwConfirmModal"',
            $this->src,
            "BUG-007 regression: #hwConfirmModal HTML scaffold removed"
        );
        $this->assertStringContainsString(
            'role="dialog" aria-modal="true"',
            $this->src,
            "BUG-007 regression: ARIA dialog attributes removed (D6 floor regression)"
        );
        // Auto-reparent to <body> must include hwConfirmModal
        $this->assertStringContainsString(
            "['hwDetailModal','hwEditModal','hwConfirmModal']",
            $this->src,
            "BUG-007 regression: hwConfirmModal missing from body-reparent list (positioning will break)"
        );
    }

    /**
     * Zero remaining `if (!confirm(` calls — all 4 sites must have migrated
     * to HW.confirmDialog.open.
     */
    public function test_bug007_no_native_confirm_calls_remain(): void
    {
        $count = substr_count($this->src, "if (!confirm(");
        $this->assertSame(
            0,
            $count,
            "BUG-007 regression: $count native confirm() call(s) remain — all 4 sites must use HW.confirmDialog.open"
        );
    }

    /**
     * Exactly 4 HW.confirmDialog.open invocations (one per destructive site).
     */
    public function test_bug007_four_confirm_dialog_invocations(): void
    {
        $count = substr_count($this->src, "HW.confirmDialog.open(");
        $this->assertSame(
            4,
            $count,
            "BUG-007 regression: expected 4 HW.confirmDialog.open() call sites; found $count"
        );
    }
}
