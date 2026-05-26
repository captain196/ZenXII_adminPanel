<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * BUG-023 regression — Parent's submitHomework MUST validate
 * `text` (≤10000 bytes), `files` (≤10 attachments), and
 * `studentName` (≤200 bytes) before the Firestore transaction.
 *
 * Mirror of admin BUG-013 + Teacher BUG-020 (both verified).
 * Completes the cross-system input-length validation pattern across
 * all 3 systems.
 *
 * Discovered: cycle 5 of session 6 (DETECTION_REPORT)
 * Fixed:      session 9 cycle 2 (FIX_PATCH)
 * Quality bar: FINAL_BLUEPRINT.md §5 D4; OWASP A03; cross-system precedent
 */
class ParentSubmitHomeworkInputLengthTest extends TestCase
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
     * Three caps present: text 10000, files 10, studentName 200.
     */
    public function test_bug023_three_caps_present(): void
    {
        $this->assertStringContainsString(
            'if (text.toByteArray().size > 10000)',
            $this->src,
            "BUG-023 regression: text cap (10000) removed"
        );
        $this->assertStringContainsString(
            'if (files.size > 10)',
            $this->src,
            "BUG-023 regression: files attachment-count cap (10) removed"
        );
        $this->assertStringContainsString(
            'if (studentName.toByteArray().size > 200)',
            $this->src,
            "BUG-023 regression: studentName cap (200) removed"
        );
    }

    /**
     * Each cap returns Result.failure with IllegalArgumentException + actionable message.
     */
    public function test_bug023_caps_return_actionable_failure(): void
    {
        $this->assertStringContainsString(
            'Result.failure(IllegalArgumentException("Submission text exceeds 10000 characters."))',
            $this->src,
            "BUG-023 regression: text cap no longer returns IllegalArgumentException"
        );
        $this->assertStringContainsString(
            'Result.failure(IllegalArgumentException("Submission cannot have more than 10 attachments."))',
            $this->src,
            "BUG-023 regression: files cap no longer returns IllegalArgumentException"
        );
        $this->assertStringContainsString(
            'Result.failure(IllegalArgumentException("Student name exceeds 200 characters."))',
            $this->src,
            "BUG-023 regression: studentName cap no longer returns IllegalArgumentException"
        );
    }

    /**
     * BUG-023 marker comment present.
     */
    public function test_bug023_marker_present(): void
    {
        $this->assertStringContainsString(
            '// BUG-023 — input-boundary length validation',
            $this->src,
            "BUG-023 regression: BUG-023 marker comment removed"
        );
    }
}
