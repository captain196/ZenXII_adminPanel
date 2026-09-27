<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Subject_assignment_service.php';
require_once __DIR__ . '/SubjectAssignmentSaveReachesFirestoreTest.php';   // FakeAssignmentFs

/**
 * A SCHOOL THAT STARTS WITH NOTHING.
 *
 * ZenX onboards new schools, so the module has to work from an empty store: no
 * assignments, no serialisation documents, no legacy rows to normalise against,
 * no migration flag, and nobody to prepare the database by hand.
 *
 * This walks the whole lifecycle in order — staff, first assignment, edit,
 * replacement, removal — and checks at each step what the consumers downstream
 * would actually see, because that is where the authority lands: firestore.rules
 * reconstructs the document id to authorize a marks write, and MY_Controller
 * builds a teacher's data scope out of these same rows.
 */
final class SubjectAssignmentCleanSchoolTest extends TestCase
{
    private const SCHOOL = 'SCH_NEW01';
    private const SESS   = '2026-27';

    private FakeAssignmentFs $fs;
    private Subject_assignment_service $svc;

    protected function setUp(): void
    {
        // Nothing at all: no assignments, no sections, no ledgers.
        $this->fs  = new FakeAssignmentFs(self::SCHOOL, self::SESS);
        $this->svc = (new Subject_assignment_service())
            ->init($this->fs, self::SCHOOL, self::SESS);
    }

    private function hire(string $id, string $name, array $subjects): void
    {
        $this->fs->docs['staff'][self::SCHOOL . '_' . $id] = [
            'schoolId' => self::SCHOOL, 'staffId' => $id, 'Name' => $name,
            'status' => 'Active', 'staff_roles' => ['ROLE_TEACHER'],
            'teaching_subjects' => $subjects,
        ];
    }

    private function subject(string $code, string $name, string $teacher,
                             bool $ct = false): array
    {
        return ['code' => $code, 'name' => $name, 'category' => 'Core',
                'periods_week' => 5, 'teacher_id' => $teacher,
                'teacher_name' => 'ignored — the staff record decides',
                'is_class_teacher' => $ct];
    }

    /** The document id firestore.rules reconstructs for a marks write. */
    private function rulesPath(string $class, string $section, string $code): string
    {
        return self::SCHOOL . '_' . self::SESS . '_'
             . str_replace(' ', '_', $class) . '_'
             . str_replace(' ', '_', $section === '' ? '_ALL_' : $section) . '_' . $code;
    }

    // -- the whole lifecycle ------------------------------------------------

    public function test_a_brand_new_school_can_assign_edit_and_remove(): void
    {
        // ── nothing exists ────────────────────────────────────────────────
        $this->assertSame([], $this->svc->getAllForSession());
        $this->assertSame([], $this->svc->getAssignmentsForClass('Class 6th', 'Section A'));

        // ── a teacher is hired ────────────────────────────────────────────
        $this->hire('STA_A', 'Anita Rao', ['Mathematics']);

        // ── the first assignment the school ever makes ────────────────────
        $res = $this->svc->saveClassAssignments('Class 6th', 'Section A', [
            $this->subject('MATH', 'Mathematics', 'STA_A', true),
        ]);

        $this->assertSame([], $res['errors'], 'a clean school needs no preparation');
        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['saved']);

        // It is filed where firestore.rules will look for it.
        $this->assertArrayHasKey(
            $this->rulesPath('Class 6th', 'Section A', 'MATH'),
            $this->fs->docs['subjectAssignments'],
            'a marks write is authorized by reconstructing this exact id');

        // And the name stored is the school's, not the request's.
        $row = $this->fs->docs['subjectAssignments'][
            $this->rulesPath('Class 6th', 'Section A', 'MATH')];
        $this->assertSame('Anita Rao', $row['teacherName']);
        $this->assertSame('Class 6th', $row['className']);
        $this->assertSame('Section A', $row['section']);
        $this->assertSame(self::SESS, $row['session']);
        $this->assertSame(self::SCHOOL, $row['schoolId']);

        // ── the teacher's data scope now includes that class ──────────────
        $mine = $this->svc->getAssignmentsForTeacher('STA_A');
        $this->assertCount(1, $mine);
        $this->assertSame('Class 6th', $mine[0]['className']);

        // ── a second teacher and subject are added ────────────────────────
        $this->hire('STA_B', 'Bhavna Iyer', ['Science']);
        $res = $this->svc->saveClassAssignments('Class 6th', 'Section A', [
            $this->subject('MATH', 'Mathematics', 'STA_A', true),
            $this->subject('SCI', 'Science', 'STA_B'),
        ]);
        $this->assertSame(2, $res['saved']);
        $this->assertCount(2, $this->fs->docs['subjectAssignments']);

        // ── the science teacher is replaced ───────────────────────────────
        $this->hire('STA_C', 'Chandra Menon', ['Science']);
        $this->svc->saveClassAssignments('Class 6th', 'Section A', [
            $this->subject('MATH', 'Mathematics', 'STA_A', true),
            $this->subject('SCI', 'Science', 'STA_C'),
        ]);

        $this->assertSame([], $this->svc->getAssignmentsForTeacher('STA_B'),
            'the replaced teacher keeps no authority over the class');
        $this->assertCount(1, $this->svc->getAssignmentsForTeacher('STA_C'));

        // ── science is dropped from the section entirely ──────────────────
        $this->svc->saveClassAssignments('Class 6th', 'Section A', [
            $this->subject('MATH', 'Mathematics', 'STA_A', true),
        ]);

        $this->assertCount(1, $this->fs->docs['subjectAssignments'],
            'the removed subject leaves no row behind to authorize anybody');
        $this->assertSame([], $this->svc->getAssignmentsForTeacher('STA_C'));
    }

    /** No migration flag, no seeded serialisation document, no manual step. */
    public function test_the_first_ever_save_needs_no_pre_existing_ledger(): void
    {
        $this->hire('STA_A', 'Anita Rao', ['Mathematics']);

        $this->assertSame([], $this->fs->docs['subjectAssignmentSections'] ?? [],
            'a new school has no serialisation documents');

        $res = $this->svc->saveClassAssignments('Class 6th', 'Section A', [
            $this->subject('MATH', 'Mathematics', 'STA_A'),
        ]);

        $this->assertTrue($res['success']);
        $this->assertNotEmpty($this->fs->docs['subjectAssignmentSections'],
            'and the first save creates the one it needs');
    }

    /** A school that types the short class name gets the same canonical row. */
    public function test_a_short_class_label_still_lands_canonically(): void
    {
        $this->hire('STA_A', 'Anita Rao', ['Mathematics']);

        $this->svc->saveClassAssignments('6', 'A', [
            $this->subject('MATH', 'Mathematics', 'STA_A'),
        ]);

        $this->assertArrayHasKey(
            $this->rulesPath('Class 6th', 'Section A', 'MATH'),
            $this->fs->docs['subjectAssignments']);
    }

    /** A class-wide assignment uses the placeholder the rules also check. */
    public function test_a_class_wide_assignment_uses_the_all_placeholder(): void
    {
        $this->hire('STA_A', 'Anita Rao', ['Mathematics']);

        $this->svc->saveClassAssignments('Class 6th', '', [
            $this->subject('MATH', 'Mathematics', 'STA_A'),
        ]);

        $this->assertArrayHasKey(
            $this->rulesPath('Class 6th', '', 'MATH'),
            $this->fs->docs['subjectAssignments']);
    }

    // -- the boundaries hold from the very first row ------------------------

    public function test_another_session_sees_none_of_it(): void
    {
        $this->hire('STA_A', 'Anita Rao', ['Mathematics']);
        $this->svc->saveClassAssignments('Class 6th', 'Section A', [
            $this->subject('MATH', 'Mathematics', 'STA_A'),
        ]);

        $next = (new Subject_assignment_service())
            ->init($this->fs, self::SCHOOL, '2027-28');

        $this->assertSame([], $next->getAssignmentsForClass('Class 6th', 'Section A'),
            "last year's assignments are not this year's authority");
        $this->assertSame([], $next->getAssignmentsForTeacher('STA_A'));
    }

    public function test_another_school_sees_none_of_it(): void
    {
        $this->hire('STA_A', 'Anita Rao', ['Mathematics']);
        $this->svc->saveClassAssignments('Class 6th', 'Section A', [
            $this->subject('MATH', 'Mathematics', 'STA_A'),
        ]);

        // Another tenant reads the SAME datastore through its own client, which
        // is scoped to its own school — that is what schoolWhere() does in
        // production. Reusing this school's client would test nothing.
        $theirs = new FakeAssignmentFs('SCH_OTHER', self::SESS);
        $theirs->docs =& $this->fs->docs;
        $other = (new Subject_assignment_service())
            ->init($theirs, 'SCH_OTHER', self::SESS);

        $this->assertSame([], $other->getAssignmentsForClass('Class 6th', 'Section A'));
        $this->assertSame([], $other->getAssignmentsForTeacher('STA_A'));
    }

    /** A teacher nobody hired cannot be given a class, even on day one. */
    public function test_a_teacher_who_was_never_hired_is_refused(): void
    {
        $res = $this->svc->saveClassAssignments('Class 6th', 'Section A', [
            $this->subject('MATH', 'Mathematics', 'STA_GHOST'),
        ]);

        $this->assertFalse($res['success']);
        $this->assertSame([], $this->fs->docs['subjectAssignments'] ?? []);
    }
}
