<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Subject_matrix_guard.php';
require_once __DIR__ . '/../../application/libraries/Subject_assignment_service.php';
require_once __DIR__ . '/SubjectAssignmentSaveReachesFirestoreTest.php';   // FakeAssignmentFs

/**
 * TWO SCREENS, ONE AUTHORITY.
 *
 * Subject assignments can be written from two places: the per-class editor,
 * which goes through Subject_assignment_service and replaces ONE section, and
 * the class matrix, which reconciles a WHOLE class in the controller.
 *
 * Serialising only the first is worse than serialising neither, because it
 * looks safe. A matrix save landing in the middle of a section save was
 * invisible to both, and firestore.rules grants marks writes straight off these
 * rows — so a teacher removed on one screen could survive on the other with
 * their access intact.
 *
 * Both surfaces now take a token per section, on the same documents. These
 * tests run the actual batch through a client that honours preconditions the
 * way Firestore does, rather than reading the controller and hoping.
 */
final class SubjectMatrixInterlockTest extends TestCase
{
    private const SCHOOL = 'SCH_SA01';
    private const SESS   = '2026-27';

    private function svc(FakeAssignmentFs $fs): Subject_assignment_service
    {
        return (new Subject_assignment_service())->init($fs, self::SCHOOL, self::SESS);
    }

    private function fs(): FakeAssignmentFs
    {
        $fs = new FakeAssignmentFs(self::SCHOOL, self::SESS);
        foreach ([['STA_T1', ['Mathematics', 'MATH']],
                  ['STA_T2', ['Science', 'SCI']]] as [$id, $subs]) {
            $fs->docs['staff'][self::SCHOOL . '_' . $id] = [
                'schoolId' => self::SCHOOL, 'staffId' => $id, 'Name' => 'T ' . $id,
                'teaching_subjects' => $subs,
            ];
        }
        return $fs;
    }

    /** The tokens a whole-class matrix save would take, for these sections. */
    private function tokens(FakeAssignmentFs $fs, Subject_assignment_service $svc,
                            string $class, array $sections): array
    {
        $out = [];

        // Mirrors the controller: EVERY serialisation document for this class,
        // not only the sections the grid happens to list. A whole-class save
        // revokes rows in sections its grid never mentioned.
        foreach ($fs->whereOrFail('subjectAssignmentSections', [
            ['schoolId',  '==', self::SCHOOL],
            ['session',   '==', self::SESS],
            ['className', '==', $class],
        ]) as $row) {
            $out[(string) $row['id']] = (string) ($row['data']['__updateTime'] ?? '');
        }

        foreach (array_unique(array_merge($sections, [''])) as $sec) {
            $lid = $svc->sectionLedgerId($class, (string) $sec);
            if (array_key_exists($lid, $out)) { continue; }
            $doc = $fs->get('subjectAssignmentSections', $lid);
            $out[$lid] = is_array($doc) ? (string) ($doc['__updateTime'] ?? '') : '';
        }
        return $out;
    }

    /**
     * Run a matrix-style reconciliation exactly as the controller does —
     * including the coverage check, not merely the commit. A helper that skips
     * the decision under test proves nothing about it.
     *
     * @param string[] $currentSections sections of the rows stored right now
     */
    private function commitMatrix(FakeAssignmentFs $fs, array $stale, array $desired,
                                  array $tokens, array $currentSections = []): array
    {
        $svc = $this->svc($fs);

        // Any section present in the current rows that no token covers appeared
        // between the two reads. If its serialisation document now exists, that
        // section changed underneath this request.
        $unguarded = Subject_matrix_guard::unguardedSections(
            $currentSections,
            fn(string $sec): string => $svc->sectionLedgerId('Class 8th', $sec),
            $tokens
        );
        foreach ($unguarded as $sec) {
            $lid = $svc->sectionLedgerId('Class 8th', $sec);
            if (is_array($fs->get('subjectAssignmentSections', $lid))) {
                return ['status' => 'PRECONDITION_CONFLICT'];
            }
            $tokens[$lid] = '';
        }

        $ops = Subject_matrix_guard::commitOps($stale, $desired, $tokens, [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'className' => 'Class 8th', 'updatedAt' => '2026-08-31T00:00:00+05:30',
        ]);
        return $fs->raw_client()->commitBatchStatus($ops);
    }

    /** The sections of every assignment row stored right now. */
    private function currentSections(FakeAssignmentFs $fs): array
    {
        $out = [];
        foreach ($fs->docs['subjectAssignments'] ?? [] as $d) {
            $out[] = (string) ($d['section'] ?? '');
        }
        return $out;
    }

    private function doc(string $section, string $code, string $teacher): array
    {
        return [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'className' => 'Class 8th', 'section' => $section,
            'subjectCode' => $code, 'subjectName' => $code,
            'teacherId' => $teacher, 'teacherName' => 'T ' . $teacher,
        ];
    }

    // -- the batch itself ---------------------------------------------------

    public function test_the_batch_carries_every_revocation_grant_and_token(): void
    {
        $ops = Subject_matrix_guard::commitOps(
            ['stale_one', 'stale_two'],
            ['new_one' => $this->doc('Section A', 'MATH', 'STA_T1')],
            ['ledger_a' => '101', 'ledger_all' => ''],
            ['schoolId' => self::SCHOOL]
        );

        $kinds = array_count_values(array_column($ops, 'op'));
        $this->assertSame(2, $kinds['delete'], 'both revocations are in the batch');
        $this->assertSame(3, $kinds['set'], 'the grant and both tokens are too');

        $byId = [];
        foreach ($ops as $o) { $byId[$o['docId']] = $o; }

        $this->assertSame(['updateTime' => '101'], $byId['ledger_a']['precondition'],
            'a ledger that exists is expected not to have moved');
        $this->assertSame(['exists' => false], $byId['ledger_all']['precondition'],
            'a ledger that does not exist yet is expected to still be absent');
        $this->assertArrayNotHasKey('precondition', $byId['new_one'],
            'the assignment rows themselves are unconditional — the tokens are the '
            . 'thing being raced on');
    }

    /**
     * Revocation and grant land together. A window in which a teacher had been
     * removed but their replacement was not yet written is a lesson with nobody
     * authorized to enter its marks.
     */
    public function test_a_revocation_and_its_replacement_land_together(): void
    {
        $fs = $this->fs();
        $fs->docs['subjectAssignments']['old'] = $this->doc('Section A', 'MATH', 'STA_T1');

        $res = $this->commitMatrix($fs, ['old'],
            ['new' => $this->doc('Section A', 'MATH', 'STA_T2')], ['ledger_a' => '']);

        $this->assertSame('SUCCESS', $res['status']);
        $this->assertArrayNotHasKey('old', $fs->docs['subjectAssignments']);
        $this->assertSame('STA_T2', $fs->docs['subjectAssignments']['new']['teacherId']);
    }

    // -- the interlock ------------------------------------------------------

    /**
     * THE GAP. A per-section save through the service moves that section's
     * token; a matrix save holding the old token must be refused rather than
     * reconciling a class it never saw.
     */
    public function test_a_matrix_save_is_refused_after_a_section_save(): void
    {
        $fs  = $this->fs();
        $svc = $this->svc($fs);

        // The matrix screen loads and takes its tokens.
        $tokens = $this->tokens($fs, $svc, 'Class 8th', ['Section A']);

        // Meanwhile somebody saves Section A through the per-class editor.
        $svc->saveClassAssignments('Class 8th', 'Section A', [
            ['code' => 'SCI', 'name' => 'Science',
             'teacher_id' => 'STA_T2', 'teacher_name' => 'T STA_T2'],
        ]);

        // The matrix now tries to reconcile the whole class from its old view.
        $res = $this->commitMatrix($fs, [],
            ['m1' => $this->doc('Section A', 'MATH', 'STA_T1')], $tokens);

        $this->assertSame('PRECONDITION_CONFLICT', $res['status'],
            'the matrix would have written a section it had not seen changed');
        $this->assertArrayNotHasKey('m1', $fs->docs['subjectAssignments'],
            'and nothing of its grid was written');
    }

    /** And the other way round: a section save after a matrix save. */
    public function test_a_section_save_is_refused_after_a_matrix_save(): void
    {
        $fs  = $this->fs();
        $svc = $this->svc($fs);

        // The per-class editor opens Section A and reads its token.
        $before = $fs->docs;

        // The matrix saves the whole class first.
        $tokens = $this->tokens($fs, $svc, 'Class 8th', ['Section A']);
        $this->assertSame('SUCCESS', $this->commitMatrix($fs, [],
            ['m1' => $this->doc('Section A', 'MATH', 'STA_T1')], $tokens)['status']);

        // The editor's request began earlier and still sees the old world.
        $fs->stale = $before;
        $res = $svc->saveClassAssignments('Class 8th', 'Section A', [
            ['code' => 'SCI', 'name' => 'Science',
             'teacher_id' => 'STA_T2', 'teacher_name' => 'T STA_T2'],
        ]);
        $fs->stale = null;

        $this->assertFalse($res['success'],
            'the editor would have replaced a section the matrix had just rewritten');
        $this->assertMatchesRegularExpression(
            '~somebody else changed~i', implode(' ', $res['errors']));
    }

    /**
     * Two administrators on DIFFERENT sections are not in conflict. A guard that
     * serialises the whole school would be worse than the problem.
     */
    public function test_different_sections_do_not_block_each_other(): void
    {
        $fs  = $this->fs();
        $svc = $this->svc($fs);

        // A matrix save that only claims Section B.
        $tokens = $this->tokens($fs, $svc, 'Class 8th', ['Section B']);

        // A section save on Section A lands in between.
        $svc->saveClassAssignments('Class 8th', 'Section A', [
            ['code' => 'SCI', 'name' => 'Science',
             'teacher_id' => 'STA_T2', 'teacher_name' => 'T STA_T2'],
        ]);

        $res = $this->commitMatrix($fs, [],
            ['m1' => $this->doc('Section B', 'MATH', 'STA_T1')], $tokens,
            $this->currentSections($fs));

        // Section A gained rows AND a serialisation document after this grid took
        // its tokens. The matrix reconciles the whole class, so those rows are in
        // its revocation set — with nothing guarding the decision. Refusing is the
        // only safe answer: the alternative is revoking a teacher's access to a
        // section this grid never even displayed.
        $this->assertSame('PRECONDITION_CONFLICT', $res['status'],
            'a section that appeared while the grid was open must not be revoked '
            . 'by it');
        $this->assertArrayNotHasKey('m1', $fs->docs['subjectAssignments']);
    }

    /** Two matrix saves of the same class interlock with each other. */
    public function test_two_matrix_saves_of_one_class_interlock(): void
    {
        $fs  = $this->fs();
        $svc = $this->svc($fs);

        $tokensA = $this->tokens($fs, $svc, 'Class 8th', ['Section A']);
        $tokensB = $this->tokens($fs, $svc, 'Class 8th', ['Section A']);

        $this->assertSame('SUCCESS', $this->commitMatrix($fs, [],
            ['a1' => $this->doc('Section A', 'MATH', 'STA_T1')], $tokensA)['status']);

        $res = $this->commitMatrix($fs, [],
            ['b1' => $this->doc('Section A', 'SCI', 'STA_T2')], $tokensB);

        $this->assertSame('PRECONDITION_CONFLICT', $res['status']);
        $this->assertArrayNotHasKey('b1', $fs->docs['subjectAssignments']);
    }

    /** A class nobody has touched yet saves cleanly. */
    public function test_a_first_ever_save_for_a_class_succeeds(): void
    {
        $fs  = $this->fs();
        $svc = $this->svc($fs);

        $tokens = $this->tokens($fs, $svc, 'Class 8th', ['Section A']);
        $res = $this->commitMatrix($fs, [],
            ['m1' => $this->doc('Section A', 'MATH', 'STA_T1')], $tokens);

        $this->assertSame('SUCCESS', $res['status']);
        $this->assertArrayHasKey('m1', $fs->docs['subjectAssignments']);
    }

    /** Both surfaces must name the same document for the same section. */
    public function test_both_surfaces_use_the_same_serialisation_document(): void
    {
        $svc = $this->svc($this->fs());

        // The editor passes canonical labels; the matrix passes its firebase key,
        // which for a canonical class is the same string.
        $this->assertSame(
            $svc->sectionLedgerId('Class 8th', 'Section A'),
            $svc->sectionLedgerId('Class 8th', 'Section A'),
            'if these ever diverge the two screens stop interlocking silently');

        $this->assertSame(self::SCHOOL . '_' . self::SESS . '_Class_8th_Section_A',
            $svc->sectionLedgerId('Class 8th', 'Section A'));
        $this->assertSame(self::SCHOOL . '_' . self::SESS . '_Class_8th__ALL_',
            $svc->sectionLedgerId('Class 8th', ''),
            'the class-wide slot both surfaces share');
    }
}
