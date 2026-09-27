<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ACAD-U1 — the class-teacher hint names a prerequisite that does not exist yet.
 *
 * The class-teacher pool is derived, correctly, from teachers already assigned
 * to subjects in that section:
 *
 *     _teachersInSection(sec)  →  walks _matrix.subjects, collecting
 *                                 class_wide_teacher_id / section_teachers[sec]
 *
 * That constraint is sound — it enforces that a class teacher actually teaches
 * in the section they own, which is the same principle that makes
 * ROLE_CLASS_TEACHER a teaching role (CT-D1).
 *
 * The wording is what fails. With ZERO subjects the pool is empty and the UI
 * says "assign a teacher below first" — but there is no teacher control below
 * to use, because teacher pickers only exist per subject row. The operator is
 * told to do something the screen cannot yet offer. The real first step is
 * ADD A SUBJECT.
 *
 * Two distinct empty states therefore need two distinct messages:
 *   no subjects at all      → add a subject first
 *   subjects but unstaffed  → assign a teacher to one of them
 */
final class AcademicClassTeacherHintTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $p = __DIR__ . '/../../application/views/academic/index.php';
        $this->assertFileExists($p);
        $this->src = (string) file_get_contents($p);
    }

    /** The derivation this hint depends on must still be the pool source. */
    public function test_the_pool_is_still_derived_from_assigned_subjects(): void
    {
        $this->assertStringContainsString('_teachersInSection', $this->src,
            'the class-teacher pool derivation is gone — the hint logic no longer applies');
    }

    /**
     * DEFECT ACAD-U1 — the zero-subject case must not tell the operator to
     * assign a teacher "below", where no teacher control exists.
     */
    public function test_the_hint_distinguishes_no_subjects_from_no_teacher(): void
    {
        // Skip the CSS rule near the top of the file — anchor on the emitter.
        $i = strpos($this->src, 'sa-ct-hint', strpos($this->src, '_ctStripHtml'));
        $this->assertNotFalse($i, 'the class-teacher hint is gone');

        // Look at the statement that emits the hint, plus a little context.
        $region = substr($this->src, max(0, $i - 400), 900);

        // The pool guard itself must remain — the hint only makes sense when
        // there is genuinely nobody eligible.
        $this->assertMatchesRegularExpression(
            '~pool\.length\s*===\s*0~', $region,
            'the hint must still be gated on an empty eligible-teacher pool');

        $this->assertMatchesRegularExpression(
            '~subjects.{0,80}?length~s', $region,
            "The hint is emitted purely on an empty teacher pool, so a class with\n"
            . "NO SUBJECTS is told to 'assign a teacher below first' — but teacher\n"
            . "pickers only exist per subject row, so there is nothing below to use.\n"
            . 'Branch on whether any subject exists.');
    }

    /** The zero-subject message must name the real first step. */
    public function test_the_zero_subject_message_names_adding_a_subject(): void
    {
        // Skip the CSS rule near the top of the file — anchor on the emitter.
        $i = strpos($this->src, 'sa-ct-hint', strpos($this->src, '_ctStripHtml'));
        $this->assertNotFalse($i);
        $region = substr($this->src, max(0, $i - 400), 900);

        $this->assertMatchesRegularExpression(
            '~(?i)add\s+a\s+subject~', $region,
            'the zero-subject hint should point at adding a subject, which is the '
            . 'actual first step and matches the catalog empty state below it');
    }
}
