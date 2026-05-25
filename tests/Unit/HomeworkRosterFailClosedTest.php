<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * BUG-003 regression — when the roster lookup inside create_homework
 * raises a Firestore exception, the controller MUST fail-closed (HTTP 503
 * with actionable partial-state messaging) rather than silently persisting
 * a homework doc with `totalStudents=0` (misleading denormalized baseline).
 *
 * Discovered: cycle 1 (DETECTION_REPORT, 2026-05-21)
 * Fixed:      session 3 cycle 4 (FIX_PATCH, 2026-05-21) — option (a) fail-closed
 * Quality bar: FINAL_BLUEPRINT.md §5 D2 (no silent imputation); STACK_INVARIANTS §6
 */
class HomeworkRosterFailClosedTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(
            __DIR__ . '/../../application/controllers/Homework.php'
        );
        $this->assertNotFalse($this->src);
    }

    /**
     * Post-fix, the catch block must (a) emit a structured log_message
     * identifying the failed sectionKey + already-created context, and
     * (b) issue json_error 503 with an actionable operator message + return.
     */
    public function test_bug003_catch_fails_closed_with_503(): void
    {
        $this->assertStringContainsString(
            "Homework::create_homework — roster lookup failed for sectionKey=",
            $this->src,
            "BUG-003 regression: structured log_message lost from roster catch"
        );
        $this->assertStringContainsString(
            "'Roster lookup failed for section \"' . \$secFull . '\". '",
            $this->src,
            "BUG-003 regression: actionable json_error message lost from roster catch"
        );
        $this->assertStringContainsString(
            "503\n",
            $this->src,
            "BUG-003 regression: HTTP 503 status no longer issued on roster failure"
        );
    }

    /**
     * The pre-fix `// leave totalStudents = 0` comment MUST be gone.
     * If it reappears, the silent-fallback regression has returned.
     */
    public function test_bug003_pre_fix_silent_comment_gone(): void
    {
        $this->assertStringNotContainsString(
            "// leave totalStudents = 0",
            $this->src,
            "BUG-003 regression: pre-fix silent `// leave totalStudents = 0` comment reappeared"
        );
    }

    /**
     * The catch must report the count of already-created sections so the
     * operator can identify the partial state and retry only the remainder.
     */
    public function test_bug003_partial_state_count_surfaced(): void
    {
        $this->assertStringContainsString(
            "count(\$createdIds) . ' of ' . count(\$sections)",
            $this->src,
            "BUG-003 regression: partial-state count messaging removed from the 503 response"
        );
    }
}
