<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Subject_assignment_service.php';
require_once __DIR__ . '/SubjectAssignmentSaveReachesFirestoreTest.php';   // FakeAssignmentFs

/**
 * COPYING ONE CLASS'S SUBJECTS ONTO ANOTHER.
 *
 * The copy screen reads a source class, rebuilds the subject list, and saves it
 * to a destination. Three things were wrong with the round trip.
 *
 * It read the source by whatever label the screen sent, while rows are stored
 * under the canonical one — so copying from "8" found nothing and reported that
 * the source class had no assignments.
 *
 * It saved to the destination the same way, filing rows under a label that
 * neither the delete set nor firestore.rules would look for.
 *
 * And it returned success unconditionally: it never looked at the result, and
 * read a `count` key the service does not return, so the number it displayed
 * was the number ATTEMPTED. Every refusal — a teacher not recorded for the
 * subject, an unreadable delete set, a failed commit — was reported to the
 * operator as a successful copy.
 */
final class SubjectAssignmentCopyIntegrityTest extends TestCase
{
    private const SCHOOL = 'SCH_SA01';
    private const SESS   = '2026-27';
    private const CTRL   = __DIR__ . '/../../application/controllers/Academic.php';

    private function svc(FakeAssignmentFs $fs): Subject_assignment_service
    {
        return (new Subject_assignment_service())->init($fs, self::SCHOOL, self::SESS);
    }

    private function fsWithSource(): FakeAssignmentFs
    {
        $fs = new FakeAssignmentFs(self::SCHOOL, self::SESS);
        $fs->docs['staff'][self::SCHOOL . '_STA_T1'] = [
            'schoolId' => self::SCHOOL, 'staffId' => 'STA_T1', 'Name' => 'Anita Rao',
            'teaching_subjects' => ['Mathematics', 'MATH'],
        ];
        // A source class, stored the way the save stores it: canonical labels.
        $fs->docs['subjectAssignments'][self::SCHOOL . '_' . self::SESS . '_Class_8th__ALL__MATH'] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'className' => 'Class 8th', 'section' => '',
            'subjectCode' => 'MATH', 'subjectName' => 'Mathematics',
            'periodsPerWeek' => 6, 'teacherId' => 'STA_T1', 'teacherName' => 'Anita Rao',
        ];
        return $fs;
    }

    /** Comments naming a call must not satisfy assertions about that call. */
    private function ctrl(): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents(self::CTRL)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { continue; }
                $out .= $t[1];
            } else { $out .= $t; }
        }
        return $out;
    }

    private function copyFn(): string
    {
        $code = $this->ctrl();
        $i = strpos($code, 'function copy_subject_assignments(');
        $this->assertNotFalse($i, 'copy_subject_assignments() is gone');
        // Wide enough to reach the return statement at the end of the method.
        return substr($code, $i, 3200);
    }

    // -- reading the source ------------------------------------------------

    /** THE DEFECT: the source was read by a label its rows do not carry. */
    public function test_a_source_class_is_found_however_the_screen_spells_it(): void
    {
        $fs = $this->fsWithSource();

        foreach (['Class 8th', '8', '8th'] as $spelling) {
            $found = $this->svc($fs)->getAssignmentsForClass($spelling, '');
            $this->assertCount(1, $found,
                "a source addressed as '{$spelling}' reported no assignments at all");
            $this->assertSame('MATH', $found[0]['subjectCode']);
        }
    }

    /** The destination is filed where the rules will look for it. */
    public function test_the_destination_is_filed_canonically(): void
    {
        $fs = $this->fsWithSource();
        $src = $this->svc($fs)->getAssignmentsForClass('8', '');

        $subjects = array_map(static fn($a) => [
            'code' => $a['subjectCode'], 'name' => $a['subjectName'],
            'periods_week' => $a['periodsPerWeek'],
            'teacher_id' => $a['teacherId'], 'teacher_name' => $a['teacherName'],
        ], $src);

        $this->svc($fs)->saveClassAssignments('9', '', $subjects);

        $this->assertArrayHasKey(
            self::SCHOOL . '_' . self::SESS . '_Class_9th__ALL__MATH',
            $fs->docs['subjectAssignments'],
            'the copied row is filed under the label firestore.rules reconstructs');
    }

    // -- reporting the result ----------------------------------------------

    /**
     * The controller must look at what the save returned. A refusal reported as
     * a successful copy is the phantom-success pattern this codebase already
     * guards against on the client — the server should not be the one lying.
     */
    public function test_the_copy_endpoint_checks_whether_the_save_succeeded(): void
    {
        $fn = $this->copyFn();

        $this->assertMatchesRegularExpression(
            "~empty\(\\\$result\['success'\]\)~", $fn,
            'the copy never inspects the result and reports success regardless');
        $this->assertStringContainsString('json_error', $fn,
            'a refused copy has no way to say so');
    }

    /** The number shown must be the number SAVED, not the number attempted. */
    public function test_the_copy_reports_the_number_actually_saved(): void
    {
        $fn = $this->copyFn();

        $this->assertMatchesRegularExpression(
            "~\\\$count\s*=\s*\(int\)\s*\(\\\$result\['saved'\]~", $fn,
            "the count came from \$result['count'], a key the service does not "
            . 'return, so it always fell back to the number attempted');
        $this->assertStringNotContainsString("\$result['count']", $fn,
            'the non-existent count key is still being read');
    }

    /** A warning the save raised must reach the person who did the copy. */
    public function test_warnings_from_the_save_are_passed_on(): void
    {
        $this->assertMatchesRegularExpression(
            "~'warnings'\s*=>\s*\\\$result\['warnings'\]~", $this->copyFn(),
            'the save can warn that it replaced an existing class teacher, and '
            . 'nobody was told');
    }

    /**
     * The save refusing is not hypothetical: a teacher whose staff record does
     * not list the subject is refused, and copying moves teachers between
     * classes precisely where that is most likely.
     */
    public function test_a_save_that_refuses_returns_no_success(): void
    {
        $fs = $this->fsWithSource();
        // The teacher does not teach Science.
        $res = $this->svc($fs)->saveClassAssignments('9', '', [
            ['code' => 'SCI', 'name' => 'Science',
             'teacher_id' => 'STA_T1', 'teacher_name' => 'Anita Rao'],
        ]);

        $this->assertFalse($res['success']);
        $this->assertNotEmpty($res['errors']);
        $this->assertSame(0, $res['saved']);
    }
}
