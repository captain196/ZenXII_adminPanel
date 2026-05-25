<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * BUG-024 regression — Teacher's HomeworkTeacherViewModel.kt loadStudentsForClass
 * silent catch (originally at line 204) MUST emit a structured debugLog event.
 *
 * Scope refinement (session 8 cycle 4):
 *  - BUG-024 originally cited 2 sites outside the cascade: lines 204 + 357.
 *  - On verification, line 357 was found to be an intentional defensive
 *    ContentResolver query fallback (OS metadata API; "keep defaults" comment).
 *    NOT a Firestore observability gap. Scope-removed.
 *  - Line 204 (loadStudentsForClass) IS a Firestore observability gap and
 *    is the surface of this fix.
 *  - The 2 cascade-internal sites that BUG-024 also originally cited (lines
 *    586 + 601) were closed by BUG-017-amended (verified session 8 cycle 3).
 *
 * Discovered: cycle 6 of session 6 (DETECTION_REPORT)
 * Fixed:      session 8 cycle 4 (FIX_PATCH, scope-refined)
 * Quality bar: FINAL_BLUEPRINT.md §5 D9
 */
class TeacherViewModelSilentCatchTest extends TestCase
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
     * loadStudentsForClass catch emits structured ACC_HW_VM_LOAD_STUDENTS_FAILED.
     */
    public function test_bug024_load_students_for_class_catch_emits_structured_log(): void
    {
        $this->assertStringContainsString(
            'ACC_HW_VM_LOAD_STUDENTS_FAILED',
            $this->src,
            "BUG-024 regression: loadStudentsForClass debugLog removed"
        );
        $this->assertStringContainsString(
            'class=${classSection.className} section=${classSection.section}',
            $this->src,
            "BUG-024 regression: forensic class/section context removed from loadStudentsForClass emit"
        );
    }

    /**
     * Pre-fix empty-body silent catch must be gone for the loadStudentsForClass site.
     */
    public function test_bug024_pre_fix_empty_catch_gone_at_load_students(): void
    {
        // Pre-fix specifically: `} catch (_: Exception) { }` with empty braces,
        // at the loadStudentsForClass site immediately after the _uiState.update block.
        $preFix = "result.getOrNull()?.let { students ->\n                    _uiState.update { it.copy(students = students) }\n                }\n            } catch (_: Exception) { }";
        $this->assertStringNotContainsString(
            $preFix,
            $this->src,
            "BUG-024 regression: pre-fix empty-body silent catch at loadStudentsForClass reappeared"
        );
    }

    /**
     * Defensive ContentResolver fallback at line ~357 is EXPLICITLY out of
     * BUG-024 scope (intentional OS-metadata default). Assert the comment
     * remains intact to document the intentionality.
     */
    public function test_bug024_content_resolver_defensive_fallback_preserved(): void
    {
        $this->assertStringContainsString(
            '} catch (_: Exception) { /* keep defaults */ }',
            $this->src,
            "BUG-024 regression check: the defensive ContentResolver fallback comment was inadvertently changed — the `/* keep defaults */` site is intentional and out of scope"
        );
    }
}
