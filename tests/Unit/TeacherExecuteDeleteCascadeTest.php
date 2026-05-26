<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * BUG-017-amended regression — Teacher's executeDelete cascade must surface
 * per-doc delete failures via structured debugLog AND track them in
 * counters that flow into the user-facing success message.
 *
 * Deferred aspects (NOT closed by this fix; documented for follow-up):
 *  - Atomicity: cascade is not transaction-wrapped
 *  - Pagination: getSubmissions returns up to its internal limit (no
 *    explicit pagination in the cascade driver)
 *
 * Discovered: cycle 4 of session 6 (DETECTION_REPORT, amended cycle 6)
 * Fixed:      session 8 cycle 2 (FIX_PATCH per approved FIX_PLAN)
 * Quality bar: FINAL_BLUEPRINT.md §5 D2 + D9
 */
class TeacherExecuteDeleteCascadeTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $kotlinPath = 'D:/Projects/SchoolSyncTeacher/app/src/main/java/com/schoolsync/teacher/ui/homework/HomeworkTeacherViewModel.kt';
        if (!file_exists($kotlinPath)) {
            $this->markTestSkipped("Teacher app source not present");
        }
        $this->src = file_get_contents($kotlinPath);
        $this->assertNotFalse($this->src);
    }

    /**
     * Both cascade catches must emit structured debugLog events.
     */
    public function test_bug017_cascade_catches_emit_structured_log(): void
    {
        $this->assertStringContainsString(
            'ACC_HW_DELETE_CASCADE_SUB_FAILED',
            $this->src,
            "BUG-017 regression: submission cascade catch no longer emits ACC_HW_DELETE_CASCADE_SUB_FAILED"
        );
        $this->assertStringContainsString(
            'ACC_HW_DELETE_CASCADE_MARK_FAILED',
            $this->src,
            "BUG-017 regression: teacherMark cascade catch no longer emits ACC_HW_DELETE_CASCADE_MARK_FAILED"
        );
    }

    /**
     * Per-doc failure counters must be present and incremented in each catch.
     */
    public function test_bug017_failure_counters_present_and_incremented(): void
    {
        $this->assertStringContainsString(
            'var failedSubDeletes = 0',
            $this->src,
            "BUG-017 regression: failedSubDeletes counter removed"
        );
        $this->assertStringContainsString(
            'var failedMarkDeletes = 0',
            $this->src,
            "BUG-017 regression: failedMarkDeletes counter removed"
        );
        $this->assertStringContainsString(
            'failedSubDeletes++',
            $this->src,
            "BUG-017 regression: failedSubDeletes increment removed from cascade catch"
        );
        $this->assertStringContainsString(
            'failedMarkDeletes++',
            $this->src,
            "BUG-017 regression: failedMarkDeletes increment removed from cascade catch"
        );
    }

    /**
     * Success message must include the partial-failure suffix.
     */
    public function test_bug017_success_message_reports_partial_failures(): void
    {
        $this->assertStringContainsString(
            'if (failedSubDeletes > 0) append(", $failedSubDeletes failed")',
            $this->src,
            "BUG-017 regression: success message no longer reports submission cascade failures"
        );
        $this->assertStringContainsString(
            'if (failedMarkDeletes > 0) append(", $failedMarkDeletes failed")',
            $this->src,
            "BUG-017 regression: success message no longer reports teacherMark cascade failures"
        );
    }

    /**
     * Pre-fix discarded-exception form must be gone from the 2 cascade sites.
     */
    public function test_bug017_pre_fix_discarded_exception_gone(): void
    {
        $this->assertStringNotContainsString(
            'try { fs.collection("submissions").document(sub.id).delete().await() } catch (_: Exception) {}',
            $this->src,
            "BUG-017 regression: pre-fix silent submission cascade catch reappeared"
        );
        $this->assertStringNotContainsString(
            'try { m.reference.delete().await() } catch (_: Exception) {}',
            $this->src,
            "BUG-017 regression: pre-fix silent teacherMark cascade catch reappeared"
        );
    }
}
