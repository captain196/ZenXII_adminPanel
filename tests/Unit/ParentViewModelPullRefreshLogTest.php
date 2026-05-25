<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * BUG-025 regression — Parent's HomeworkViewModel.kt pullRefresh catch
 * MUST route failure logging through `debugLog` (OEM-strip-immune via
 * cache/debug.log fallback) rather than `android.util.Log.w`. Direct
 * mobile-side analog of Teacher BUG-019 (Teacher repo getTeacherMarks)
 * and Parent BUG-022 (Parent repo 3 silent catches) — same anti-pattern
 * now closed across all 3 mobile repo + VM layers.
 *
 * Discovered: session 9 cycle 5 (DETECTION_REPORT)
 * Fixed:      session 9 cycle 6 (FIX_PATCH)
 * Quality bar: F1 finding from homework_attachment_phase1_complete.md;
 *              BUG-019 / BUG-022 fix precedents; FINAL_BLUEPRINT.md §5 D9
 */
class ParentViewModelPullRefreshLogTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $kotlinPath = 'D:/Projects/SchoolSyncParent/app/src/main/java/com/schoolsync/parent/ui/homework/HomeworkViewModel.kt';
        if (!file_exists($kotlinPath)) {
            $this->markTestSkipped("Parent app source not present");
        }
        $this->src = file_get_contents($kotlinPath);
        $this->assertNotFalse($this->src);
    }

    /**
     * debugLog import added.
     */
    public function test_bug025_debug_log_import_present(): void
    {
        $this->assertStringContainsString(
            'import com.schoolsync.parent.util.debugLog',
            $this->src,
            "BUG-025 regression: debugLog import removed from Parent VM"
        );
    }

    /**
     * Structured ACC_HW_PARENT_VM_PULL_REFRESH_FAILED emit in pullRefresh catch.
     */
    public function test_bug025_structured_emit_in_pull_refresh_catch(): void
    {
        $this->assertStringContainsString(
            'debugLog("ACC_HW_PARENT_VM_PULL_REFRESH_FAILED',
            $this->src,
            "BUG-025 regression: structured emit removed from pullRefresh catch"
        );
    }

    /**
     * Pre-fix Log.w("HomeworkVM", "pullRefresh failed", e) gone.
     */
    public function test_bug025_pre_fix_log_w_gone(): void
    {
        $this->assertStringNotContainsString(
            'Log.w("HomeworkVM", "pullRefresh failed", e)',
            $this->src,
            "BUG-025 regression: pre-fix Log.w call reappeared in pullRefresh catch"
        );
    }
}
