<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * BUG-018 regression — Teacher's closeHomework MUST capture prevStatus,
 * short-circuit on already-closed (idempotent), and emit structured
 * debugLog events for before/after forensic visibility. Mobile analog
 * of admin BUG-008 (closed-verified).
 *
 * The TOCTOU window between the new getDocument read and the
 * updateDocument write remains — that's BUG-009-equivalent territory
 * (mobile-side; admin's BUG-009 is oos-blocked on Firebase.php
 * transaction primitives). BUG-018 narrows the symptom by collapsing
 * idempotent re-closes; full TOCTOU closure requires a separate fix.
 *
 * Discovered: cycle 4 of session 6 (DETECTION_REPORT, 2026-05-21)
 * Fixed:      session 7 cycle 3 (FIX_PATCH, 2026-05-21)
 * Quality bar: STACK_INVARIANTS §13; admin BUG-008 closed-verified precedent;
 *              FINAL_BLUEPRINT.md §11 ("every state-changing action audited")
 */
class TeacherCloseHomeworkAuditTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $kotlinPath = 'D:/Projects/SchoolSyncTeacher/app/src/main/java/com/schoolsync/teacher/data/repository/firestore/HomeworkFirestoreRepository.kt';
        if (!file_exists($kotlinPath)) {
            $this->markTestSkipped("Teacher app source not present");
        }
        $this->src = file_get_contents($kotlinPath);
        $this->assertNotFalse($this->src);
    }

    /**
     * prevStatus capture via firestoreService.getDocument before the write.
     */
    public function test_bug018_prev_status_captured_before_write(): void
    {
        $this->assertStringContainsString(
            'val existingSnap = firestoreService.getDocument(',
            $this->src,
            "BUG-018 regression: prevStatus capture (existingSnap) removed from closeHomework"
        );
        $this->assertStringContainsString(
            'val prevStatus = existingSnap?.getString("status")',
            $this->src,
            "BUG-018 regression: prevStatus variable assignment removed"
        );
    }

    /**
     * Idempotent short-circuit: skip the write when status is already "closed".
     */
    public function test_bug018_idempotent_short_circuit_present(): void
    {
        $this->assertStringContainsString(
            'if (prevStatus.lowercase() == "closed") {',
            $this->src,
            "BUG-018 regression: idempotent short-circuit guard removed"
        );
        $this->assertStringContainsString(
            'ACC_HW_CLOSE_NOOP',
            $this->src,
            "BUG-018 regression: ACC_HW_CLOSE_NOOP signal removed (operator can no longer distinguish re-clicks)"
        );
    }

    /**
     * Structured debugLog emits on both success and failure paths.
     */
    public function test_bug018_structured_debug_log_emits(): void
    {
        $this->assertStringContainsString(
            'ACC_HW_CLOSE_OK',
            $this->src,
            "BUG-018 regression: ACC_HW_CLOSE_OK success-path signal removed"
        );
        $this->assertStringContainsString(
            'ACC_HW_CLOSE_FAILED',
            $this->src,
            "BUG-018 regression: ACC_HW_CLOSE_FAILED failure-path signal removed"
        );
        // Before/after marker in the success log line
        $this->assertStringContainsString(
            'prevStatus=$prevStatus newStatus=closed',
            $this->src,
            "BUG-018 regression: before/after status marker removed from success log"
        );
    }
}
