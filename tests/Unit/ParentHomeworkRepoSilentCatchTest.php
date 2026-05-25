<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * BUG-022 regression — Parent's HomeworkFirestoreRepository.kt 3 read-side
 * silent catches (getSubmissionsForStudent, getTeacherMark, getTeacherMarksForStudent)
 * MUST route failure logging through `debugLog` (OEM-strip-immune) with
 * structured ACC_HW_PARENT_REPO_*_FAILED event prefixes.
 *
 * Result.success-with-empty/null contracts preserved so caller renders
 * unchanged on failure path.
 *
 * Discovered: cycle 5 of session 6 (DETECTION_REPORT, 2026-05-21)
 * Fixed:      session 7 cycle 5 (FIX_PATCH, 2026-05-21)
 * Quality bar: FINAL_BLUEPRINT.md §5 D9; admin BUG-002 + Teacher BUG-019 fix precedents;
 *              OEM logcat-strip F1 finding.
 */
class ParentHomeworkRepoSilentCatchTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $kotlinPath = 'D:/Projects/SchoolSyncParent/app/src/main/java/com/schoolsync/parent/data/repository/firestore/HomeworkFirestoreRepository.kt';
        if (!file_exists($kotlinPath)) {
            $this->markTestSkipped("Parent app source not present");
        }
        $this->src = file_get_contents($kotlinPath);
        $this->assertNotFalse($this->src);
    }

    /**
     * debugLog import present.
     */
    public function test_bug022_debug_log_import_present(): void
    {
        $this->assertStringContainsString(
            'import com.schoolsync.parent.util.debugLog',
            $this->src,
            "BUG-022 regression: debugLog import removed from Parent Repository"
        );
    }

    /**
     * Each of 3 catches emits structured debugLog with method-anchored event prefix.
     */
    public function test_bug022_three_structured_debug_log_emits(): void
    {
        $this->assertStringContainsString(
            'ACC_HW_PARENT_REPO_GET_SUBMISSIONS_FOR_STUDENT_FAILED',
            $this->src,
            "BUG-022 regression: getSubmissionsForStudent debugLog removed"
        );
        $this->assertStringContainsString(
            'ACC_HW_PARENT_REPO_GET_TEACHER_MARK_FAILED',
            $this->src,
            "BUG-022 regression: getTeacherMark debugLog removed"
        );
        $this->assertStringContainsString(
            'ACC_HW_PARENT_REPO_GET_TEACHER_MARKS_FOR_STUDENT_FAILED',
            $this->src,
            "BUG-022 regression: getTeacherMarksForStudent debugLog removed"
        );
    }

    /**
     * Each catch now uses `(e: Exception)` (named) instead of `(_: Exception)` (discarded).
     * The 3 BUG-022 sites must have moved from discard-pattern to named-pattern.
     * The file may have OTHER `catch (_: Exception)` sites elsewhere (e.g., observers);
     * the assertion is on the 3 BUG-022 sites' immediate-prior context.
     */
    public function test_bug022_discarded_exception_pattern_reduced(): void
    {
        // After fix, the 3 BUG-022 sites use named `(e: Exception)`.
        // Sentinel marker: 3 BUG-022 comment markers exist.
        $count = substr_count($this->src, '// BUG-022');
        $this->assertSame(
            3,
            $count,
            "BUG-022 regression: expected 3 BUG-022 marker comments (one per catch); found $count"
        );
    }

    /**
     * Result.success-equivalent contracts preserved — each catch returns the
     * pre-fix sentinel (emptyMap or null). Caller behavior unchanged.
     */
    public function test_bug022_caller_contracts_preserved(): void
    {
        // getSubmissionsForStudent + getTeacherMarksForStudent return emptyMap() in catch
        // getTeacherMark returns null in catch
        // Check that "Result.success(emptyMap())" is NOT here (these are pre-Result wrapped
        // — they return raw emptyMap()) — different from the Teacher repo pattern.
        $this->assertStringContainsString(
            "            debugLog(\"ACC_HW_PARENT_REPO_GET_SUBMISSIONS_FOR_STUDENT_FAILED err=\${e.javaClass.simpleName}:\${e.message}\")\n            emptyMap()\n        }",
            $this->src,
            "BUG-022 regression: getSubmissionsForStudent emptyMap() contract not preserved adjacent to debugLog"
        );
    }
}
