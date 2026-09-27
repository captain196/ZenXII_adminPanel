<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Subject_assignment_service.php';
require_once __DIR__ . '/SubjectAssignmentSaveReachesFirestoreTest.php';   // FakeAssignmentFs

/**
 * WHAT EVERYBODY DOWNSTREAM NEEDS FROM AN ASSIGNMENT ROW.
 *
 * These rows are the authority for who may teach, mark and plan for a class, and
 * five separate consumers read them by five different routes:
 *
 *   firestore.rules      reconstructs the DOCUMENT ID to authorize a marks or
 *                        homework write. It is the only consumer that depends on
 *                        the id rather than the body, which is why a row filed
 *                        under a non-canonical label was invisible to it while
 *                        every screen still showed it correctly.
 *   Timetable_service    keys getAllForSession() by the STORED className and
 *                        matches a section row or a class-wide one.
 *   MY_Controller        builds a teacher's data scope from className + section.
 *   Academic (matrix)    groups by subjectCode and reads isClassTeacher.
 *   Lesson/Curriculum    read by class and section through the same service.
 *
 * A field renamed or a label left un-normalised breaks one of them silently, so
 * the shape is asserted here in one place, from a real save.
 */
final class SubjectAssignmentConsumerContractTest extends TestCase
{
    private const SCHOOL = 'SCH_SA01';
    private const SESS   = '2026-27';

    private FakeAssignmentFs $fs;
    private Subject_assignment_service $svc;

    protected function setUp(): void
    {
        $this->fs = new FakeAssignmentFs(self::SCHOOL, self::SESS);
        $this->fs->docs['staff'][self::SCHOOL . '_STA_A'] = [
            'schoolId' => self::SCHOOL, 'staffId' => 'STA_A', 'Name' => 'Anita Rao',
            'status' => 'Active', 'teaching_subjects' => ['Mathematics'],
        ];
        $this->svc = (new Subject_assignment_service())
            ->init($this->fs, self::SCHOOL, self::SESS);

        // One real save, addressed the short way — as the copy screen does.
        $this->svc->saveClassAssignments('8', 'A', [[
            'code' => 'MATH', 'name' => 'Mathematics', 'category' => 'Core',
            'periods_week' => 6, 'teacher_id' => 'STA_A',
            'teacher_name' => 'whatever the request said', 'is_class_teacher' => true,
        ]]);
    }

    private function row(): array
    {
        $this->assertCount(1, $this->fs->docs['subjectAssignments']);
        return reset($this->fs->docs['subjectAssignments']);
    }

    // -- firestore.rules ----------------------------------------------------

    /**
     * The only consumer that reads the ID. It rebuilds the path from the class
     * and section it was given on the write it is authorizing — the canonical
     * labels — so the row has to be filed under those.
     */
    public function test_the_document_id_is_what_the_rules_reconstruct(): void
    {
        $id = array_key_first($this->fs->docs['subjectAssignments']);

        $this->assertSame(
            self::SCHOOL . '_' . self::SESS . '_Class_8th_Section_A_MATH', $id,
            'firestore.rules builds this exact path to authorize a marks write; a '
            . 'row it cannot find refuses a teacher who is properly assigned');
    }

    /** And the rules cross-check the body against the write's own values. */
    public function test_the_body_matches_what_the_rules_cross_check(): void
    {
        $r = $this->row();

        foreach (['schoolId' => self::SCHOOL, 'className' => 'Class 8th',
                  'section' => 'Section A', 'teacherId' => 'STA_A'] as $field => $want) {
            $this->assertSame($want, $r[$field],
                "firestore.rules compares {$field} on the fetched row against the "
                . 'write it is authorizing — a mismatch denies a legitimate teacher');
        }
        $this->assertArrayNotHasKey('archived', $r,
            'the rules treat a missing archived flag as false, which is correct '
            . 'for a live row');
    }

    // -- Timetable_service --------------------------------------------------

    public function test_the_timetable_finds_it_by_the_stored_class_name(): void
    {
        $byClass = $this->svc->getAllForSession();

        $this->assertArrayHasKey('Class 8th', $byClass,
            'Timetable_service indexes this map by the className on the row and '
            . 'looks it up with the className from the sections collection');
        $this->assertSame('Section A', $byClass['Class 8th'][0]['section']);
        $this->assertSame(6, $byClass['Class 8th'][0]['periodsPerWeek'],
            'a row with no periodsPerWeek is skipped by the generator entirely');
    }

    // -- teacher scoping ----------------------------------------------------

    public function test_a_teachers_scope_is_built_from_class_and_section(): void
    {
        $mine = $this->svc->getAssignmentsForTeacher('STA_A');

        $this->assertCount(1, $mine);
        $this->assertSame('Class 8th', $mine[0]['className']);
        $this->assertSame('Section A', $mine[0]['section']);
        $this->assertSame('Mathematics', $mine[0]['subjectName'],
            'MY_Controller keys the scope on class, section AND subject name');
        $this->assertSame('MATH', $mine[0]['subjectCode'],
            'and on the code, because either may be what a screen has');
    }

    // -- the matrix screen --------------------------------------------------

    public function test_the_matrix_can_group_and_flag_from_the_row(): void
    {
        $r = $this->row();

        $this->assertSame('MATH', $r['subjectCode']);
        $this->assertSame('Mathematics', $r['subjectName']);
        $this->assertSame('Core', $r['category']);
        $this->assertTrue($r['isClassTeacher']);
        $this->assertSame('Class 8th/Section A', $r['sectionKey'],
            'the composite key the apps use to address a section');
    }

    /** The name shown everywhere is the school's record, not the request's. */
    public function test_the_displayed_teacher_name_comes_from_the_staff_record(): void
    {
        $this->assertSame('Anita Rao', $this->row()['teacherName']);
    }

    // -- the boundaries -----------------------------------------------------

    public function test_every_row_carries_its_school_and_session(): void
    {
        $r = $this->row();

        $this->assertSame(self::SCHOOL, $r['schoolId'],
            'every consumer filters on this; a row without it belongs to nobody');
        $this->assertSame(self::SESS, $r['session'],
            'and on this, or last year\'s authority leaks into this year');
    }

    /** The ordering fields the class lists sort by. */
    public function test_the_row_carries_the_fields_screens_sort_by(): void
    {
        $r = $this->row();

        $this->assertArrayHasKey('classOrder', $r);
        $this->assertArrayHasKey('sectionCode', $r);
        $this->assertSame('A', $r['sectionCode']);
    }
}
