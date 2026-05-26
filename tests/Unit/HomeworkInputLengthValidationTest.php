<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * BUG-013 regression — create_homework and update_homework MUST validate
 * the byte-length of title (200), subject (100), description (10000)
 * before the Firestore write. Without these caps, an oversize payload
 * would either (a) cause Firestore DocTooLarge and bubble a 500 past
 * `json_error`, or (b) succeed and persist a multi-megabyte field.
 *
 * Note: strlen() is byte-count, which is the right measure for the
 * Firestore 1MB doc-size cap. User-facing message says "characters"
 * for clarity; the conservative-bound caveat is documented.
 *
 * Discovered: cycle 4 (DETECTION_REPORT, 2026-05-21)
 * Fixed:      session 5 cycle 3 (FIX_PATCH, 2026-05-21)
 * Quality bar: FINAL_BLUEPRINT.md §5 D4 (input validated at trust boundaries);
 *              OWASP A03 (Injection) baseline; Firestore 1MB doc-size cap
 */
class HomeworkInputLengthValidationTest extends TestCase
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
     * create_homework: title 200, subject 100, description 10000 caps.
     */
    public function test_bug013_create_homework_length_caps_present(): void
    {
        $this->assertStringContainsString(
            "if (strlen(\$title)       > 200)   \$this->json_error('Title exceeds 200 characters.', 400);",
            $this->src,
            "BUG-013 regression: create_homework title cap missing"
        );
        $this->assertStringContainsString(
            "if (strlen(\$subject)     > 100)   \$this->json_error('Subject exceeds 100 characters.', 400);",
            $this->src,
            "BUG-013 regression: create_homework subject cap missing"
        );
        $this->assertStringContainsString(
            "if (strlen(\$description) > 10000) \$this->json_error('Description exceeds 10000 characters.', 400);",
            $this->src,
            "BUG-013 regression: create_homework description cap missing"
        );
    }

    /**
     * update_homework: same caps, in conditional-update form.
     */
    public function test_bug013_update_homework_length_caps_present(): void
    {
        $this->assertStringContainsString(
            "if (\$title !== '' && strlen(\$title) > 200) \$this->json_error('Title exceeds 200 characters.', 400);",
            $this->src,
            "BUG-013 regression: update_homework title cap missing"
        );
        $this->assertStringContainsString(
            "if (\$desc !== '' && strlen(\$desc) > 10000) \$this->json_error('Description exceeds 10000 characters.', 400);",
            $this->src,
            "BUG-013 regression: update_homework description cap missing"
        );
        $this->assertStringContainsString(
            "if (\$subj !== '' && strlen(\$subj) > 100) \$this->json_error('Subject exceeds 100 characters.', 400);",
            $this->src,
            "BUG-013 regression: update_homework subject cap missing"
        );
    }

    /**
     * Exactly 6 BUG-013 marker comments — 3 in create, 3 in update.
     */
    public function test_bug013_exactly_six_markers(): void
    {
        $count = substr_count($this->src, "// BUG-013");
        $this->assertSame(
            6,
            $count,
            "BUG-013 regression: expected 6 BUG-013 marker comments; found $count"
        );
    }
}
