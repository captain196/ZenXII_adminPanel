<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * BUG-020 regression — Teacher's createHomework MUST validate byte-length
 * of title (200), subject (100), description (10000) before the Firestore
 * write. Without these caps, an oversize payload would either trip
 * Firestore DocTooLarge (resurfacing as Result.failure with generic
 * exception) or succeed and persist a multi-megabyte field.
 *
 * Mirror of admin BUG-013 + Parent BUG-023 (BUG-023 still open).
 *
 * Discovered: cycle 4 of session 6 (DETECTION_REPORT)
 * Fixed:      session 8 cycle 6 (FIX_PATCH)
 * Quality bar: FINAL_BLUEPRINT.md §5 D4; OWASP A03; admin BUG-013 fix precedent
 */
class TeacherCreateHomeworkInputLengthTest extends TestCase
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
     * All 3 length caps present with correct thresholds and Result.failure return.
     */
    public function test_bug020_three_length_caps_present(): void
    {
        $this->assertStringContainsString(
            'if (title.toByteArray().size > 200)',
            $this->src,
            "BUG-020 regression: title cap (200) removed"
        );
        $this->assertStringContainsString(
            'if (subject.toByteArray().size > 100)',
            $this->src,
            "BUG-020 regression: subject cap (100) removed"
        );
        $this->assertStringContainsString(
            'if (description.toByteArray().size > 10000)',
            $this->src,
            "BUG-020 regression: description cap (10000) removed"
        );
    }

    /**
     * Each cap returns Result.failure with IllegalArgumentException and
     * actionable message.
     */
    public function test_bug020_caps_return_actionable_failure(): void
    {
        $this->assertStringContainsString(
            'Result.failure(IllegalArgumentException("Title exceeds 200 characters."))',
            $this->src,
            "BUG-020 regression: title cap no longer returns IllegalArgumentException"
        );
        $this->assertStringContainsString(
            'Result.failure(IllegalArgumentException("Subject exceeds 100 characters."))',
            $this->src,
            "BUG-020 regression: subject cap no longer returns IllegalArgumentException"
        );
        $this->assertStringContainsString(
            'Result.failure(IllegalArgumentException("Description exceeds 10000 characters."))',
            $this->src,
            "BUG-020 regression: description cap no longer returns IllegalArgumentException"
        );
    }

    /**
     * BUG-020 marker present at insertion point.
     */
    public function test_bug020_marker_present(): void
    {
        $this->assertStringContainsString(
            '// BUG-020 — input-boundary length validation',
            $this->src,
            "BUG-020 regression: BUG-020 marker comment removed"
        );
    }
}
