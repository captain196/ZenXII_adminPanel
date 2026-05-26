<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * BUG-019 regression — Teacher's HomeworkFirestoreRepository.kt
 * getTeacherMarksForHomework MUST route failure logging through `debugLog`
 * (OEM-strip-immune cache-file sink) rather than `android.util.Log.w`
 * (vulnerable to OxygenOS/ColorOS/MIUI logcat stripping per the F1 lesson
 * in `homework_attachment_phase1_complete.md`).
 *
 * Result.success(emptyMap()) contract preserved so callers don't break.
 *
 * Discovered: cycle 4 of session 6 (DETECTION_REPORT, 2026-05-21)
 * Fixed:      session 7 cycle 1 (FIX_PATCH, 2026-05-21)
 * Quality bar: FINAL_BLUEPRINT.md §5 D9 (observability); admin BUG-002 fix
 *              precedent; OEM logcat-strip F1 finding.
 *
 * Note: this PHP test reads a Kotlin file. The static-source assertion
 * pattern is language-agnostic — file_get_contents + substring matching
 * works for any text-based source. Test runs under the project's
 * existing PHPUnit setup (tests/bootstrap.php) without Kotlin tooling.
 */
class TeacherHomeworkRepoSilentCatchTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $kotlinPath = 'D:/Projects/SchoolSyncTeacher/app/src/main/java/com/schoolsync/teacher/data/repository/firestore/HomeworkFirestoreRepository.kt';
        if (!file_exists($kotlinPath)) {
            $this->markTestSkipped("Teacher app source not present at $kotlinPath");
        }
        $this->src = file_get_contents($kotlinPath);
        $this->assertNotFalse($this->src);
    }

    /**
     * The debugLog import must be present.
     */
    public function test_bug019_debug_log_import_present(): void
    {
        $this->assertStringContainsString(
            'import com.schoolsync.teacher.util.debugLog',
            $this->src,
            "BUG-019 regression: debugLog import removed from Teacher Repository"
        );
    }

    /**
     * The getTeacherMarksForHomework catch must emit a structured debugLog
     * with the ACC_HW_REPO_GET_TEACHER_MARKS_FAILED event prefix.
     */
    public function test_bug019_structured_debug_log_emit_in_catch(): void
    {
        $this->assertStringContainsString(
            'debugLog("ACC_HW_REPO_GET_TEACHER_MARKS_FAILED hwId=',
            $this->src,
            "BUG-019 regression: structured debugLog emit no longer in getTeacherMarksForHomework catch"
        );
    }

    /**
     * The OEM-vulnerable Log.w line must be GONE from this function.
     * Defense-in-depth: ensures the fix wasn't applied alongside a
     * leftover Log.w call.
     */
    public function test_bug019_pre_fix_log_w_form_absent(): void
    {
        $this->assertStringNotContainsString(
            'android.util.Log.w("HomeworkRepo", "getTeacherMarksForHomework failed:',
            $this->src,
            "BUG-019 regression: pre-fix android.util.Log.w call reappeared"
        );
    }

    /**
     * The Result.success(emptyMap()) contract must STILL be in the same
     * catch block — operators chose to preserve best-effort behavior;
     * only the observability path was upgraded.
     */
    public function test_bug019_result_success_contract_preserved(): void
    {
        $this->assertStringContainsString(
            "Result.success(emptyMap())  // best-effort — don't block submissions screen",
            $this->src,
            "BUG-019 regression: Result.success(emptyMap()) contract changed inadvertently"
        );
    }
}
