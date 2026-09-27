<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Subject_assignment_service.php';
require_once __DIR__ . '/SubjectAssignmentSaveReachesFirestoreTest.php';   // FakeAssignmentFs

/**
 * TWO ADMINISTRATORS, ONE SECTION.
 *
 * Saving a section's subjects is a REPLACE: the rows that exist are collected
 * into a delete set, the new rows are collected into a write set, and the two
 * are committed together. CONC-D1 made that single commit atomic, so a crash
 * can no longer leave a section with its assignments deleted and not rewritten.
 *
 * What it cannot do is notice that somebody else replaced the same section in
 * between. The delete set is read at the start of the request and describes the
 * world as it was then. If another administrator commits first, their rows are
 * not in this request's delete set — so this "replace" quietly becomes an ADD,
 * and the section ends up carrying subjects nobody chose.
 *
 * That is not a cosmetic merge. firestore.rules authorizes a marks or homework
 * write by reading these rows directly, so a row that survives a replace is a
 * teacher who kept access to a class they were removed from. It is the same
 * revocation failure ACAD-D14 aborts the save to prevent — arriving by a
 * different route.
 */
final class SubjectAssignmentConcurrentReplaceTest extends TestCase
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
                  ['STA_T2', 'B Iyer',    ['Science', 'SCI']],
                  ['STA_T3', 'C Menon',   ['History', 'HIST']]] as [$id, $name, $subs]) {
            $fs->docs['staff'][self::SCHOOL . '_' . $id] = [
                'schoolId' => self::SCHOOL, 'staffId' => $id, 'Name' => $name,
                'teaching_subjects' => $subs,
            ];
        }
        return $fs;
    }

    private function subject(string $code, string $name, string $teacher): array
    {
        return ['code' => $code, 'name' => $name,
                'teacher_id' => $teacher, 'teacher_name' => 'T ' . $teacher];
    }

    private function codes(FakeAssignmentFs $fs): array
    {
        $out = [];
        foreach ($fs->docs['subjectAssignments'] ?? [] as $d) {
            $out[] = $d['subjectCode'];
        }
        sort($out);
        return $out;
    }

    // -- the ordinary case still works -------------------------------------

    public function test_a_replace_with_nobody_else_editing_replaces(): void
    {
        $fs  = $this->fsWithTeachers();
        $svc = $this->svc($fs);

        $svc->saveClassAssignments('Class 8th', 'Section A',
            [$this->subject('MATH', 'Mathematics', 'STA_T1')]);
        $svc->saveClassAssignments('Class 8th', 'Section A',
            [$this->subject('SCI', 'Science', 'STA_T2')]);

        $this->assertSame(['SCI'], $this->codes($fs));
    }

    // -- the race ----------------------------------------------------------

    /**
     * THE DEFECT. Administrator B opened the section before A saved, so every
     * read B makes describes the world without A's row in it.
     */
    public function test_a_replace_built_on_a_stale_view_is_refused(): void
    {
        $fs  = $this->fsWithTeachers();
        $svc = $this->svc($fs);

        // The world both administrators opened.
        $before = $fs->docs;

        // A saves first: the section becomes Mathematics.
        $svc->saveClassAssignments('Class 8th', 'Section A',
            [$this->subject('MATH', 'Mathematics', 'STA_T1')]);

        // B's request began earlier, so its reads still see the empty section.
        $fs->stale = $before;

        $res = $svc->saveClassAssignments('Class 8th', 'Section A',
            [$this->subject('SCI', 'Science', 'STA_T2')]);

        $fs->stale = null;

        $this->assertFalse($res['success'],
            'B replaced a section it had not seen, so its "replace" silently '
            . 'became an add');
        $this->assertNotEmpty($res['errors']);
        // Specific to the conflict: the generic "could not save" message also
        // contains the word "changed", so matching that alone would pass even
        // if the conflict were never recognised as one.
        $this->assertMatchesRegularExpression(
            '~somebody else changed~i', implode(' ', $res['errors']),
            'the operator has to be told WHY, in words they can act on');
        $this->assertMatchesRegularExpression(
            '~reload~i', implode(' ', $res['errors']),
            'and what to do about it');

        $this->assertSame(['MATH'], $this->codes($fs),
            "A's save stands and B's never happened — a section must not end up "
            . 'carrying subjects nobody chose');
    }

    /**
     * A second save that HAS seen the first one is an ordinary edit and must go
     * through. The guard must not turn every sequential edit into a conflict.
     */
    public function test_a_replace_that_has_seen_the_current_state_goes_through(): void
    {
        $fs  = $this->fsWithTeachers();
        $svc = $this->svc($fs);

        $svc->saveClassAssignments('Class 8th', 'Section A',
            [$this->subject('MATH', 'Mathematics', 'STA_T1')]);
        $res = $svc->saveClassAssignments('Class 8th', 'Section A',
            [$this->subject('SCI', 'Science', 'STA_T2')]);

        $this->assertTrue($res['success']);
        $this->assertSame(['SCI'], $this->codes($fs));
    }

    /** A different section is a different thing, and must not be blocked. */
    public function test_another_section_is_not_blocked_by_this_one(): void
    {
        $fs  = $this->fsWithTeachers();
        $svc = $this->svc($fs);

        $before = $fs->docs;
        $svc->saveClassAssignments('Class 8th', 'Section A',
            [$this->subject('MATH', 'Mathematics', 'STA_T1')]);

        // Stale for the section that changed — but B is editing Section B.
        $fs->stale = $before;
        $res = $svc->saveClassAssignments('Class 8th', 'Section B',
            [$this->subject('SCI', 'Science', 'STA_T2')]);
        $fs->stale = null;

        $this->assertTrue($res['success'],
            'two administrators working on different sections are not in conflict');
        $this->assertSame(['MATH', 'SCI'], $this->codes($fs));
    }

    /** And a different class likewise. */
    public function test_another_class_is_not_blocked_by_this_one(): void
    {
        $fs  = $this->fsWithTeachers();
        $svc = $this->svc($fs);

        $before = $fs->docs;
        $svc->saveClassAssignments('Class 8th', 'Section A',
            [$this->subject('MATH', 'Mathematics', 'STA_T1')]);

        $fs->stale = $before;
        $res = $svc->saveClassAssignments('Class 9th', 'Section A',
            [$this->subject('SCI', 'Science', 'STA_T2')]);
        $fs->stale = null;

        $this->assertTrue($res['success']);
    }
}
