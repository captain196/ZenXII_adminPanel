<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Subject_assignment_service.php';
require_once __DIR__ . '/SubjectAssignmentSaveReachesFirestoreTest.php';   // FakeAssignmentFs

/**
 * THE CLASS LABEL A ROW IS FILED UNDER.
 *
 * Two things are built from the class and section a caller passes in: the
 * document id, and the delete set that decides which rows this save replaces.
 * The document BODY, however, is written with the canonical labels — the save
 * normalises "8" to "Class 8th" before storing it.
 *
 * When the caller passes something non-canonical those three disagree, and each
 * disagreement costs something real:
 *
 *   The delete set is queried by className, so it looks for "8" and finds rows
 *   filed as "Class 8th". Nothing is deleted; the new rows are written under
 *   different ids; the section ends up with two sets of assignments and every
 *   teacher who was supposed to lose access keeps it.
 *
 *   The document id is what firestore.rules reconstructs when it authorizes a
 *   marks or homework write. The Teacher app builds that hint from the class
 *   name it holds — the canonical one — so a row filed under "8" is invisible
 *   to the lookup and a properly assigned teacher is refused.
 *
 * Academic::save_subject_assignments normalises before calling in, with a
 * comment explaining exactly this. Academic::copy_subject_assignments does not.
 * The guard belongs in the service, where every caller gets it.
 */
final class SubjectAssignmentClassKeyIntegrityTest extends TestCase
{
    private const SCHOOL = 'SCH_SA01';
    private const SESS   = '2026-27';

    private function svc(FakeAssignmentFs $fs): Subject_assignment_service
    {
        return (new Subject_assignment_service())->init($fs, self::SCHOOL, self::SESS);
    }

    private function fsWithTeachers(): FakeAssignmentFs
    {
        $fs = new FakeAssignmentFs(self::SCHOOL, self::SESS);
        foreach ([['STA_T1', 'Anita Rao', ['Mathematics', 'MATH']],
                  ['STA_T2', 'B Iyer',    ['Science', 'SCI']]] as [$id, $name, $subs]) {
            $fs->docs['staff'][self::SCHOOL . '_' . $id] = [
                'schoolId' => self::SCHOOL, 'staffId' => $id, 'Name' => $name,
                'teaching_subjects' => $subs,
            ];
        }
        return $fs;
    }

    private function maths(): array
    {
        return [['code' => 'MATH', 'name' => 'Mathematics',
                 'teacher_id' => 'STA_T1', 'teacher_name' => 'Anita Rao']];
    }

    private function science(): array
    {
        return [['code' => 'SCI', 'name' => 'Science',
                 'teacher_id' => 'STA_T2', 'teacher_name' => 'B Iyer']];
    }

    // -- the id a row is filed under ---------------------------------------

    /**
     * THE DEFECT. The row's body says "Class 8th"; its id says "8". The rules
     * look it up by the canonical name and miss.
     */
    public function test_a_row_is_filed_under_the_same_label_it_carries(): void
    {
        $fs = $this->fsWithTeachers();
        $this->svc($fs)->saveClassAssignments('8', 'A', $this->maths());

        $this->assertCount(1, $fs->docs['subjectAssignments']);
        $id  = array_key_first($fs->docs['subjectAssignments']);
        $doc = $fs->docs['subjectAssignments'][$id];

        $expected = self::SCHOOL . '_' . self::SESS . '_'
            . str_replace(' ', '_', $doc['className']) . '_'
            . str_replace(' ', '_', $doc['section']) . '_MATH';

        $this->assertSame($expected, $id,
            'the document id is built from the labels the document itself carries, '
            . 'because that is what firestore.rules reconstructs to authorize a '
            . 'marks write');
    }

    /**
     * The same section addressed two ways is ONE section. The second save must
     * replace the first, not sit alongside it.
     */
    public function test_the_same_section_spelled_differently_is_still_replaced(): void
    {
        $fs  = $this->fsWithTeachers();
        $svc = $this->svc($fs);

        $svc->saveClassAssignments('Class 8th', 'Section A', $this->maths());
        $this->assertCount(1, $fs->docs['subjectAssignments']);

        // Same section, addressed the short way — as the copy screen does.
        $svc->saveClassAssignments('8', 'A', $this->science());

        $this->assertCount(1, $fs->docs['subjectAssignments'],
            'Mathematics still authorizes its teacher for a section that no longer '
            . 'lists it');
        $doc = reset($fs->docs['subjectAssignments']);
        $this->assertSame('SCI', $doc['subjectCode']);
    }

    /** A caller that already passes canonical labels is unaffected. */
    public function test_canonical_labels_are_left_exactly_as_they_are(): void
    {
        $fs = $this->fsWithTeachers();
        $this->svc($fs)->saveClassAssignments('Class 8th', 'Section A', $this->maths());

        $id = array_key_first($fs->docs['subjectAssignments']);
        $this->assertSame(
            self::SCHOOL . '_' . self::SESS . '_Class_8th_Section_A_MATH', $id);
    }

    /** A class-wide save keeps the _ALL_ placeholder the rules also look up. */
    public function test_a_class_wide_save_still_uses_the_all_placeholder(): void
    {
        $fs = $this->fsWithTeachers();
        $this->svc($fs)->saveClassAssignments('8', '', $this->maths());

        $id = array_key_first($fs->docs['subjectAssignments']);
        $this->assertStringContainsString('__ALL__', $id,
            'firestore.rules checks a class-wide path built with _ALL_');
        $this->assertStringContainsString('Class_8th', $id);
    }

    // -- the class-teacher warning ------------------------------------------

    /**
     * The warning about replacing an existing class teacher is informational —
     * the save replaces the whole section either way. But a warning that was
     * not shown because the read failed is indistinguishable from one that had
     * nothing to say, and the operator is the person who would have noticed.
     */
    public function test_an_unreadable_class_teacher_check_says_so(): void
    {
        $fs = $this->fsWithTeachers();
        $fs->docs['subjectAssignments'][self::SCHOOL . '_' . self::SESS . '_Class_8th_Section_A_SCI'] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'className' => 'Class 8th', 'section' => 'Section A',
            'subjectCode' => 'SCI', 'teacherId' => 'STA_T2', 'isClassTeacher' => true,
        ];
        $fs->failQueries = true;

        $res = $this->svc($fs)->saveClassAssignments('Class 8th', 'Section A', [
            ['code' => 'MATH', 'name' => 'Mathematics', 'teacher_id' => 'STA_T1',
             'teacher_name' => 'Anita Rao', 'is_class_teacher' => true],
        ]);

        // The save is aborted anyway by the fail-loud delete-set read, which is
        // the stronger protection. What must not happen is a silent claim that
        // there was no existing class teacher.
        $this->assertNotEmpty($res['errors']);
        $fs->failQueries = false;
    }

    /**
     * A staff record that could not be READ is not a teacher who lacks the
     * subject. Both used to refuse with the same message, which told the
     * operator to go and edit that teacher's profile — wrong advice, about a
     * colleague who may be perfectly qualified, at the one moment the system
     * cannot tell.
     */
    public function test_an_unreadable_staff_record_is_not_an_unqualified_teacher(): void
    {
        $fs = $this->fsWithTeachers();
        $fs->failStaffReads = true;

        $res = $this->svc($fs)->saveClassAssignments('Class 8th', 'Section A', $this->maths());

        $this->assertFalse($res['success'], 'the save still refuses — it cannot check');
        $msg = implode(' ', $res['errors']);
        $this->assertMatchesRegularExpression('~could not be read~i', $msg);
        $this->assertDoesNotMatchRegularExpression('~not authorized~i', $msg,
            'the teacher is not the problem and must not be blamed for it');
        $this->assertSame([], $fs->docs['subjectAssignments'] ?? []);
    }

    /**
     * teacherCanTeach() is a PUBLIC contract — Academic::save_class_matrix calls
     * it directly for every cell of the grid. It has to distinguish "this teacher
     * does not teach the subject" from "we could not find out", or the matrix
     * screen blames a qualified colleague for an outage.
     */
    public function test_the_public_check_throws_when_the_record_cannot_be_read(): void
    {
        $fs = $this->fsWithTeachers();
        $fs->failStaffReads = true;

        $this->expectException(\RuntimeException::class);
        $this->svc($fs)->teacherCanTeach('STA_T1', 'MATH', 'Mathematics');
    }

    /** And it answers plainly when the record IS readable. */
    public function test_the_public_check_answers_when_the_record_is_readable(): void
    {
        $fs  = $this->fsWithTeachers();
        $svc = $this->svc($fs);

        $this->assertTrue($svc->teacherCanTeach('STA_T1', 'MATH', 'Mathematics'));
        $this->assertFalse($svc->teacherCanTeach('STA_T1', 'SCI', 'Science'));
        $this->assertFalse($svc->teacherCanTeach('STA_NOBODY', 'MATH', 'Mathematics'),
            'a record that is genuinely absent is a plain no');
    }
    /** A teacher who genuinely lacks the subject is still named as such. */
    public function test_a_teacher_without_the_subject_is_still_refused(): void
    {
        $fs = $this->fsWithTeachers();

        $res = $this->svc($fs)->saveClassAssignments('Class 8th', 'Section A', [
            ['code' => 'SCI', 'name' => 'Science',
             'teacher_id' => 'STA_T1', 'teacher_name' => 'Anita Rao'],
        ]);

        $this->assertFalse($res['success']);
        $this->assertMatchesRegularExpression('~not authorized~i', implode(' ', $res['errors']));
    }

    public function test_an_existing_class_teacher_is_named_in_a_warning(): void
    {
        $fs = $this->fsWithTeachers();
        $fs->docs['subjectAssignments'][self::SCHOOL . '_' . self::SESS . '_Class_8th_Section_A_SCI'] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'className' => 'Class 8th', 'section' => 'Section A',
            'subjectCode' => 'SCI', 'teacherId' => 'STA_T2', 'isClassTeacher' => true,
        ];

        $res = $this->svc($fs)->saveClassAssignments('Class 8th', 'Section A', [
            ['code' => 'MATH', 'name' => 'Mathematics', 'teacher_id' => 'STA_T1',
             'teacher_name' => 'Anita Rao', 'is_class_teacher' => true],
        ]);

        $this->assertNotEmpty($res['warnings']);
        $this->assertStringContainsString('STA_T2', implode(' ', $res['warnings']));
    }
}
