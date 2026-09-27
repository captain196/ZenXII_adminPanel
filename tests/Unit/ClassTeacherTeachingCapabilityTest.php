<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * CT-D1 — a Class Teacher cannot be given teaching subjects, yet is offered
 * for subject assignment. The product instructs an action it forbids.
 *
 * THE LOOP (all four steps proven from the repository):
 *
 *  1. The role catalogue declares Class Teacher a teaching role — TWICE:
 *         ROLE_CLASS_TEACHER: category 'Teaching', flags ['can_teach', …]
 *     identical to ROLE_TEACHER.
 *
 *  2. The staff UI hides the Teaching Subjects picker unless staff_roles
 *     contains ROLE_TEACHER *specifically* (new_staff.php + edit_staff.php).
 *     So a Class Teacher's teaching_subjects can never be populated.
 *
 *  3. The Academic assignment picker still OFFERS them: with Designation left
 *     blank, Staff.php sets Position to the role LABEL ("Class Teacher"), and
 *     Academic::get_all_teachers matches on
 *         strpos(strtolower($position), 'teacher') !== false.
 *
 *  4. The assignment write then REFUSES, because
 *     Subject_assignment_service::teacherCanTeach() returns false on empty
 *     teaching_subjects — with the message
 *         "…is not authorized to teach X. Add this subject to their
 *          teaching_subjects in Staff profile first."
 *     which is exactly the screen step 2 hides.
 *
 * `can_teach` currently has NO consumer anywhere in the codebase — it is
 * declared intent with zero enforcement. The fix gives it one.
 *
 * PREDICATE: a role can teach when flags.can_teach is set OR its category is
 * 'Teaching'. The category arm matters because custom roles are created
 * without flags (Staff_access::save_role writes no 'flags' key).
 */
final class ClassTeacherTeachingCapabilityTest extends TestCase
{
    /** Views that gate the Teaching Subjects picker. */
    private const GATED_VIEWS = ['new_staff.php', 'edit_staff.php'];

    private function view(string $rel): string
    {
        $p = __DIR__ . '/../../application/views/' . $rel;
        $this->assertFileExists($p);
        return (string) file_get_contents($p);
    }

    /** The catalogue's own declaration — the ground truth for intent. */
    public function test_both_teaching_roles_declare_the_same_capability(): void
    {
        $s = (string) file_get_contents(__DIR__ . '/../../application/controllers/Staff.php');

        foreach (['ROLE_TEACHER', 'ROLE_CLASS_TEACHER'] as $rid) {
            $this->assertMatchesRegularExpression(
                "~'{$rid}'\s*=>\s*\[[^\]]*'category'\s*=>\s*'Teaching'~", $s,
                "{$rid} is no longer categorised Teaching — CT-D1's premise changed");
            $this->assertMatchesRegularExpression(
                "~'{$rid}'\s*=>\s*\[[^\]]*'can_teach'~", $s,
                "{$rid} no longer declares can_teach — CT-D1's premise changed");
        }
    }

    /**
     * DEFECT CT-D1 — the picker must not be gated on one hardcoded role id.
     */
    public function test_the_subjects_picker_is_not_gated_on_role_teacher_alone(): void
    {
        $offenders = [];

        foreach (self::GATED_VIEWS as $rel) {
            $src = $this->view($rel);
            $i = strpos($src, 'function isTeacherSelected');
            if ($i === false) continue;
            $fn = substr($src, $i, 700);

            $mentionsTeacher = strpos($fn, 'ROLE_TEACHER') !== false;
            $capabilityAware = strpos($fn, 'can_teach') !== false
                            || strpos($fn, "'Teaching'") !== false
                            || strpos($fn, '"Teaching"') !== false;

            if ($mentionsTeacher && !$capabilityAware) {
                $offenders[] = $rel;
            }
        }

        $this->assertSame([], $offenders,
            "These screens hide Teaching Subjects unless the role is literally\n"
            . "ROLE_TEACHER, so a Class Teacher — which the catalogue marks\n"
            . "category 'Teaching' AND flags can_teach — can never be given\n"
            . "teaching_subjects. They are still offered in the assignment picker\n"
            . "(Position falls back to the role label 'Class Teacher', which the\n"
            . "designation regex matches), and the write then refuses with\n"
            . "\"Add this subject to their teaching_subjects in Staff profile first\"\n"
            . "— the very screen these gate. Offenders:\n  "
            . implode("\n  ", $offenders));
    }

    /** The predicate must cover custom roles, which carry no flags. */
    public function test_the_predicate_also_accepts_the_teaching_category(): void
    {
        foreach (self::GATED_VIEWS as $rel) {
            $src = $this->view($rel);

            $i = strpos($src, 'function roleCanTeach');
            $this->assertNotFalse($i,
                "{$rel}: no shared roleCanTeach() predicate — the capability test "
                . 'must live in one named place, not be inlined per call site');
            $fn = substr($src, $i, 700);

            $this->assertStringContainsString('can_teach', $fn,
                "{$rel}: the predicate must consult the catalogue's can_teach flag");

            $this->assertMatchesRegularExpression(
                '~[\'"]Teaching[\'"]~', $fn,
                "{$rel}: Staff_access::save_role writes no 'flags' key, so a "
                . 'flags-only predicate would exclude every custom role');
        }
    }

    /** Non-teaching roles must stay excluded — this must not open the gate to all. */
    public function test_non_teaching_roles_remain_excluded(): void
    {
        $s = (string) file_get_contents(__DIR__ . '/../../application/controllers/Staff.php');

        foreach (['ROLE_LIBRARIAN' => 'Non-Teaching',
                  'ROLE_ACCOUNTANT' => 'Administrative',
                  'ROLE_DRIVER'     => 'Support'] as $rid => $cat) {
            $this->assertMatchesRegularExpression(
                "~'{$rid}'\s*=>\s*\[[^\]]*'category'\s*=>\s*'{$cat}'~", $s,
                "{$rid} changed category — it could now wrongly pass the teaching predicate");
        }
    }

    /**
     * The downstream gate that makes this a hard block rather than cosmetics.
     */
    public function test_the_assignment_write_still_requires_teaching_subjects(): void
    {
        $s = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Subject_assignment_service.php');

        $this->assertStringContainsString('teachingSubjects', $s);
        $this->assertStringContainsString(
            'Add this subject to their teaching_subjects in Staff profile first.', $s,
            'the rejection message that closes the loop is gone — re-verify CT-D1');
    }
}
