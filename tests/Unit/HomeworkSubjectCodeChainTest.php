<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * HW-D1 phase 1 — carry the canonical subject CODE to homework creation.
 *
 * Homework authorization cannot reach parity with marks until the write
 * carries a subjectCode. marksAssignmentOk() resolves the deterministic
 * assignment doc id
 *
 *     subjectAssignments/{school}_{session}_{class}_{section}_{SUBJECTCODE}
 *
 * and asserts teacherId == auth.uid, archived != true, and matching
 * school/class/section. Homework wrote only `subject`, the human NAME, which
 * cannot address that document.
 *
 * The data was already on the device: SubjectAssignmentDoc carries
 * subjectCode, but the UI model dropped it
 * (ClassAssignment.subject = subjectName). Crucially the homework subject
 * picker is NOT free text — updateSubjectsForClass() builds it from
 * allAssignments filtered by class+section — so the code is recoverable by
 * matching back to the assignment row that produced the chosen option.
 *
 * PHASE 1 (this) is additive only: the field is emitted, nothing consumes it,
 * and it is blank on paths that cannot resolve it. PHASE 2 tightens the
 * firestore rule and MUST NOT ship until this build has rolled out, or
 * handsets on the old build lose the ability to set homework.
 */
final class HomeworkSubjectCodeChainTest extends TestCase
{
    private const APP = 'D:/Projects/SchoolSyncTeacher/app/src/main/java/com/schoolsync/teacher';

    private function src(string $rel): string
    {
        $p = self::APP . '/' . $rel;
        if (!is_file($p)) {
            $this->markTestSkipped('Teacher app source not present on this machine');
        }
        return (string) file_get_contents($p);
    }

    /** The UI model must no longer discard the code. */
    public function test_the_class_assignment_model_carries_the_subject_code(): void
    {
        $s = $this->src('data/model/ClassAssignment.kt');
        $this->assertMatchesRegularExpression(
            '~val subjectCode: String = ""~', $s,
            'ClassAssignment dropped subjectCode, so homework can only ever know the subject NAME');
    }

    /** Every construction site must populate it, not just one. */
    public function test_every_assignment_mapping_populates_the_code(): void
    {
        $s = $this->src('data/repository/TeacherRepository.kt');

        $named = preg_match_all('~subject = (?:doc|obj)\.subjectName\.ifBlank~', $s);
        $coded = preg_match_all('~subjectCode = (?:doc|obj)\.subjectCode,~', $s);

        $this->assertGreaterThan(0, $named, 'no ClassAssignment construction sites found');
        $this->assertSame($named, $coded,
            "{$named} mapping site(s) set the subject name but only {$coded} set the code — "
            . 'a path that drops it silently produces unauthorizable homework');
    }

    /** The write must actually emit it. */
    public function test_the_create_payload_emits_the_subject_code(): void
    {
        $s = $this->src('data/repository/firestore/HomeworkFirestoreRepository.kt');

        $this->assertStringContainsString('"subjectCode" to subjectCode,', $s,
            'the homework create payload does not carry subjectCode');

        // Defaulted so unrelated call sites (owned by another programme) still compile.
        $this->assertMatchesRegularExpression(
            '~subjectCode: String = ""~', $s,
            'the parameter must default, or call sites outside this programme break');
    }

    /**
     * The code must be RESOLVED from the assignment row, never invented. A
     * fabricated code would address a non-existent assignment doc and produce
     * false authorization once phase 2 lands.
     */
    public function test_the_code_is_resolved_from_the_matching_assignment(): void
    {
        $s = $this->src('ui/homework/HomeworkTeacherViewModel.kt');

        $this->assertStringContainsString('allAssignments.firstOrNull', $s,
            'subjectCode must be looked up against the real assignment list');

        foreach (['it.className == classSection.className',
                  'it.section == classSection.section',
                  'it.subject == form.subject.trim()'] as $pred) {
            $this->assertStringContainsString($pred, $s,
                "the lookup must match on {$pred} — a looser match could pick another row");
        }

        // Fail closed, never guess.
        $this->assertMatchesRegularExpression(
            '~\}\?\.subjectCode \?: ""~', $s,
            'an unresolved subject must yield "" so authorization fails closed');
    }

    /**
     * PHASE 2 GUARD. The rule must NOT require subjectCode yet — doing so
     * before this build rolls out would deny homework creation from every
     * handset still running the previous release.
     */
    public function test_the_rule_does_not_require_subject_code_yet(): void
    {
        $p = __DIR__ . '/../../firebase-rules/firestore.rules';
        $this->assertFileExists($p);
        $rules = (string) file_get_contents($p);

        $i = strpos($rules, 'match /homework/{docId}');
        $this->assertNotFalse($i);
        $block = substr($rules, $i, 900);

        $this->assertStringNotContainsString('subjectCode', $block,
            "The homework rule now references subjectCode. That is PHASE 2 and must\n"
            . "not ship until the Teacher-app build carrying HW-D1 phase 1 has rolled\n"
            . "out — otherwise older handsets cannot create homework at all.\n"
            . 'Confirm the rollout, then delete this test.');
    }
}
