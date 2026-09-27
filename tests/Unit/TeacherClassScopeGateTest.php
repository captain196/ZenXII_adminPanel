<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ACAD-D1 — the class/section scope gate fails OPEN for every role except the
 * one literally labelled "Teacher".
 *
 * MY_Controller::_teacher_can_access() is the ONLY thing standing between a
 * classroom-scoped account and every other class in the school. Student
 * attendance cannot be written by any client (firestore.rules:703 —
 * `allow write: if false`), so this PHP gate is the whole boundary.
 *
 * It opens with:
 *
 *     if (($this->admin_role ?? '') !== 'Teacher') return true;
 *
 * `admin_role` is the role LABEL (Admin_login.php:364 — $displayRole), so the
 * assignment check binds ONLY the shipped role whose label is exactly
 * "Teacher". ROLE_CLASS_TEACHER ships with label "Class Teacher", holds the
 * Attendance module, and IS enumerated in Attendance::MARK_ROLES — so it
 * reaches attendance/save and then skips the scope check entirely.
 *
 * Net effect: a Class Teacher can mark, lock and correct attendance for ANY
 * class/section in their school, not just their own.
 *
 * This is the legacy name-gate hazard CLAUDE.md warns about, in its dangerous
 * direction: the gate does not merely block custom roles, it FAILS OPEN for
 * them.
 *
 * Scope note: school-wide roles (Admin, Principal, Vice Principal, Academic
 * Coordinator, Super Admin) are INTENDED to be unrestricted. This test pins
 * only the classroom-scoped roles.
 */
final class TeacherClassScopeGateTest extends TestCase
{
    /** Roles whose authority is limited to the classes they are assigned. */
    private const CLASSROOM_SCOPED = ['Teacher', 'Class Teacher'];

    private string $gate;

    protected function setUp(): void
    {
        $p = __DIR__ . '/../../application/core/MY_Controller.php';
        $this->assertFileExists($p);
        $src = (string) file_get_contents($p);

        $i = strpos($src, 'protected function _teacher_can_access(');
        $this->assertNotFalse($i, '_teacher_can_access() is gone');
        $end = strpos($src, "\n    protected function ", $i + 10);
        $this->assertNotFalse($end);
        $this->gate = substr($src, $i, $end - $i);
    }

    /**
     * DEFECT ACAD-D1 — the gate must not be a single equality against one
     * hardcoded label. Every classroom-scoped role has to be constrained.
     */
    public function test_the_gate_constrains_every_classroom_scoped_role(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            "~!==\s*'Teacher'\s*\)\s*return true;~", $this->gate,
            "The gate short-circuits for every role except the literal label 'Teacher'.\n"
            . "ROLE_CLASS_TEACHER ships as 'Class Teacher', holds Attendance, and is in\n"
            . "Attendance::MARK_ROLES — so it reaches attendance/save and skips the\n"
            . "assignment check, gaining school-wide attendance authority.");

        foreach (self::CLASSROOM_SCOPED as $label) {
            $this->assertStringContainsString(
                "'" . $label . "'", $this->gate,
                "the gate does not recognise the classroom-scoped role '{$label}'");
        }
    }

    /** The gate must still short-circuit for school-wide roles. */
    public function test_school_wide_roles_are_still_unrestricted(): void
    {
        $this->assertMatchesRegularExpression(
            '~return true;~', $this->gate,
            'the gate must still allow school-wide roles through');

        // It must consult the assignment map for the roles it does constrain.
        $this->assertStringContainsString('_get_teacher_assignments()', $this->gate);
        $this->assertStringContainsString('return false;', $this->gate,
            'a constrained role with no matching assignment must be refused');
    }

    /**
     * Every enforcement site must actually call the gate. A site that forgets
     * it is an independent hole, regardless of how correct the gate is.
     */
    public function test_every_attendance_mutation_calls_the_gate(): void
    {
        $a = (string) file_get_contents(__DIR__ . '/../../application/controllers/Attendance.php');

        $calls = preg_match_all('~_teacher_can_access\(~', $a);
        $this->assertGreaterThanOrEqual(5, $calls,
            'attendance mutation sites lost their scope check');
    }

    /**
     * The label the gate keys on comes from the role catalogue, so the
     * catalogue is what makes this list correct. Pin it: if ROLE_CLASS_TEACHER
     * is ever relabelled, this test tells you the gate must be updated too.
     */
    public function test_the_shipped_labels_still_match_what_the_gate_keys_on(): void
    {
        $s = (string) file_get_contents(__DIR__ . '/../../application/controllers/Staff.php');

        $this->assertMatchesRegularExpression(
            "~'ROLE_TEACHER'\s*=>\s*\['label'\s*=>\s*'Teacher'~", $s,
            'ROLE_TEACHER label changed — the scope gate keys on it');
        $this->assertMatchesRegularExpression(
            "~'ROLE_CLASS_TEACHER'\s*=>\s*\['label'\s*=>\s*'Class Teacher'~", $s,
            'ROLE_CLASS_TEACHER label changed — the scope gate keys on it');
    }

    /**
     * Class Teacher really is reachable at the mutation endpoint — otherwise
     * the bypass would be theoretical.
     */
    public function test_class_teacher_can_reach_the_mutation_endpoint(): void
    {
        $a = (string) file_get_contents(__DIR__ . '/../../application/controllers/Attendance.php');

        $this->assertMatchesRegularExpression(
            "~const MARK_ROLES\s*=\s*\[[^\]]*'Class Teacher'~", $a,
            'if Class Teacher were not in MARK_ROLES the bypass would be unreachable');
    }
}
