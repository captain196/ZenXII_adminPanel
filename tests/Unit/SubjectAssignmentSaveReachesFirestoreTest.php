<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * CAN A SUBJECT ASSIGNMENT ACTUALLY BE SAVED?
 *
 * The ACAD-D14 hardening moved three reads on the save path onto the fail-loud
 * client — `$this->firebase->firestoreQueryOrFail(...)`. The dependency was
 * never wired: `$this->firebase` is not declared as a property and `init()`
 * assigns it nowhere. Both of its accepted signatures skip it — the legacy
 * five-argument form destructures `[$fs, , $schoolId, , $session]`, stepping
 * over the very argument that carries the Firebase client.
 *
 * So every one of those three calls is a method call on null. Both of them sit
 * inside `catch (\Throwable)` blocks that were written to abort the save when
 * the existing rows cannot be read — and an Error IS a Throwable. The save
 * therefore aborts every single time, on both the batch path and the fallback,
 * and tells the operator their assignments "could not be read".
 *
 * The existing tests for this service read the SOURCE and assert that certain
 * strings appear in it. Source-shape tests cannot see an unwired dependency,
 * which is exactly how this survived.
 */
final class SubjectAssignmentSaveReachesFirestoreTest extends TestCase
{
    private const SCHOOL = 'SCH_SA01';
    private const SESS   = '2026-27';

    private function svc(FakeAssignmentFs $fs): Subject_assignment_service
    {
        require_once __DIR__ . '/../../application/libraries/Subject_assignment_service.php';
        return (new Subject_assignment_service())->init($fs, self::SCHOOL, self::SESS);
    }

    private function subjects(): array
    {
        return [[
            'code' => 'MATH', 'name' => 'Mathematics', 'category' => 'Core',
            'periods_week' => 6, 'teacher_id' => 'STA_T1',
            'teacher_name' => 'Anita Rao', 'is_class_teacher' => true,
        ]];
    }

    private function fsWithTeacher(): FakeAssignmentFs
    {
        $fs = new FakeAssignmentFs(self::SCHOOL, self::SESS);
        $fs->docs['staff'][self::SCHOOL . '_STA_T1'] = [
            'schoolId' => self::SCHOOL, 'staffId' => 'STA_T1', 'Name' => 'Anita Rao',
            'teaching_subjects' => ['Mathematics', 'MATH'],
        ];
        return $fs;
    }

    // -- the defect --------------------------------------------------------

    /**
     * THE DEFECT. Nothing about this save is unusual: a real teacher, a subject
     * they are recorded as teaching, an empty section with nothing to replace.
     */
    public function test_a_straightforward_save_actually_saves(): void
    {
        $fs  = $this->fsWithTeacher();
        $res = $this->svc($fs)->saveClassAssignments('Class 8th', 'Section A', $this->subjects());

        $this->assertSame([], $res['errors'],
            'the save reported errors on a request with nothing wrong with it');
        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['saved']);

        $this->assertNotEmpty($fs->docs['subjectAssignments'] ?? [],
            'the assignment reached Firestore');
    }

    /** The fallback path — a client that cannot batch — must save too. */
    public function test_a_client_that_cannot_batch_still_saves(): void
    {
        $fs = $this->fsWithTeacher();
        $fs->canBatch = false;

        $res = $this->svc($fs)->saveClassAssignments('Class 8th', 'Section A', $this->subjects());

        $this->assertSame([], $res['errors']);
        $this->assertSame(1, $res['saved']);
        $this->assertNotEmpty($fs->docs['subjectAssignments'] ?? []);
    }

    /** Replacing an existing set must remove the rows it replaces. */
    public function test_re_saving_removes_the_rows_it_replaces(): void
    {
        $fs = $this->fsWithTeacher();
        $fs->docs['staff'][self::SCHOOL . '_STA_T2'] = [
            'schoolId' => self::SCHOOL, 'staffId' => 'STA_T2', 'Name' => 'B Iyer',
            'teaching_subjects' => ['Science', 'SCI'],
        ];

        $svc = $this->svc($fs);
        $svc->saveClassAssignments('Class 8th', 'Section A', [
            ['code' => 'MATH', 'name' => 'Mathematics', 'teacher_id' => 'STA_T1',
             'teacher_name' => 'Anita Rao'],
            ['code' => 'SCI', 'name' => 'Science', 'teacher_id' => 'STA_T2',
             'teacher_name' => 'B Iyer'],
        ]);
        $this->assertCount(2, $fs->docs['subjectAssignments']);

        // Re-save with Science dropped: that teacher must lose the row.
        $svc->saveClassAssignments('Class 8th', 'Section A', [
            ['code' => 'MATH', 'name' => 'Mathematics', 'teacher_id' => 'STA_T1',
             'teacher_name' => 'Anita Rao'],
        ]);

        $codes = array_values(array_map(
            static fn($d) => $d['subjectCode'], $fs->docs['subjectAssignments']));
        $this->assertSame(['MATH'], $codes,
            'the dropped subject still authorizes its teacher for marks and attendance');
    }

    /**
     * A read that genuinely fails must still abort the save. The wiring fix
     * must not turn the ACAD-D14 protection back off.
     */
    public function test_an_unreadable_delete_set_still_aborts_the_save(): void
    {
        $fs = $this->fsWithTeacher();
        $fs->failQueries = true;

        $res = $this->svc($fs)->saveClassAssignments('Class 8th', 'Section A', $this->subjects());

        $this->assertNotEmpty($res['errors'],
            'saving on top of rows we could not read leaves removed teachers assigned');
        $this->assertFalse($res['success']);
        $this->assertSame([], $fs->docs['subjectAssignments'] ?? [],
            'and nothing was written');
    }
}

/**
 * A Firestore_service stand-in.
 *
 * Only the methods this service actually calls, and each behaves the way the
 * real one does: schoolWhere/sessionWhere return [] on failure rather than
 * throwing, remove() returns a bool, and the raw client offers commitBatch.
 */
class FakeAssignmentFs
{
    public array $docs = [];
    public bool $canBatch = true;
    public bool $failQueries = false;
    /** Staff reads alone can be made to fail, without the queries. */
    public bool $failStaffReads = false;
    public array $committed = [];

    /**
     * A frozen view of the whole store, served to every READ while set —
     * what the loser of a race sees, because its request began before the
     * winner's write landed. Commits are still judged against the real store,
     * the way Firestore judges a precondition.
     */
    public ?array $stale = null;

    /** Server-assigned update times, bumped on every write. */
    public array $updateTimes = [];
    private int $clock = 100;

    private string $schoolId;
    private string $session;

    public function __construct(string $schoolId, string $session)
    {
        $this->schoolId = $schoolId;
        $this->session  = $session;
    }

    public function docId(string $entityId): string
    {
        return $this->schoolId . '_' . $entityId;
    }

    public function getEntity(string $collection, string $entityId): ?array
    {
        $src = $this->stale ?? $this->docs;
        return $src[$collection][$this->docId($entityId)] ?? null;
    }

    public function get(string $collection, string $docId): ?array
    {
        $src = $this->stale ?? $this->docs;
        $doc = $src[$collection][$docId] ?? null;
        if ($doc === null) { return null; }
        $doc['__updateTime'] = (string) ($this->updateTimes[$collection][$docId] ?? '');
        return $doc;
    }

    /** Fail-loud single read, mirroring Firestore_service::getOrFail(). */
    public function getOrFail(string $collection, string $docId): ?array
    {
        if ($this->failQueries) {
            throw new \RuntimeException('GET_FAILED:' . $collection . ':UNAVAILABLE');
        }
        return $this->get($collection, $docId);
    }

    public function set(string $collection, string $docId, array $data, bool $merge = false): bool
    {
        $prev = $this->docs[$collection][$docId] ?? [];
        $this->docs[$collection][$docId] = $merge ? array_merge($prev, $data) : $data;
        $this->updateTimes[$collection][$docId] = (string) (++$this->clock);
        return true;
    }

    public function remove(string $collection, string $docId): bool
    {
        unset($this->docs[$collection][$docId]);
        return true;
    }

    /** The silent read: [] for a failure exactly as for "nothing there". */
    public function schoolWhere(string $collection, array $conditions = []): array
    {
        if ($this->failQueries) { return []; }
        return $this->rows($collection, array_merge(
            [['schoolId', '==', $this->schoolId]], $conditions));
    }

    public function sessionWhere(string $collection, array $conditions = []): array
    {
        if ($this->failQueries) { return []; }
        return $this->rows($collection, array_merge([
            ['schoolId', '==', $this->schoolId],
            ['session',  '==', $this->session],
        ], $conditions));
    }

    /** The fail-loud read. */
    public function whereOrFail(string $collection, array $conditions = [],
                                         $orderBy = null, string $dir = 'ASC', $limit = null): array
    {
        if ($this->failQueries) {
            throw new \RuntimeException('QUERY_FAILED:' . $collection . ':UNAVAILABLE');
        }
        return $this->rows($collection, $conditions);
    }

    public function raw_client()
    {
        return $this->canBatch ? new FakeAssignmentRawClient($this) : null;
    }

    /** @return array<int,array{id:string,data:array}> */
    private function rows(string $collection, array $conditions): array
    {
        $src = $this->stale ?? $this->docs;
        $out = [];
        foreach ($src[$collection] ?? [] as $id => $doc) {
            $ok = true;
            foreach ($conditions as [$f, $op, $v]) {
                if ($op !== '==') { continue; }
                if (($doc[$f] ?? null) !== $v) { $ok = false; break; }
            }
            if ($ok) { $out[] = ['id' => $id, 'data' => $doc]; }
        }
        return $out;
    }
}

class FakeAssignmentRawClient
{
    private FakeAssignmentFs $fs;

    public function __construct(FakeAssignmentFs $fs) { $this->fs = $fs; }

    /**
     * Fail-loud single read: null is a genuinely absent document, a failure
     * throws. The service uses this to tell "this teacher does not teach the
     * subject" from "we could not find out".
     */
    public function getDocumentOrFail(string $collection, string $docId): ?array
    {
        if ($this->fs->failStaffReads && $collection === 'staff') {
            throw new \RuntimeException('GET_FAILED:staff:UNAVAILABLE');
        }
        return $this->fs->get($collection, $docId);
    }

    /** Atomic: every op lands, or the call reports failure and none do. */
    public function commitBatch(array $ops): bool
    {
        return ($this->commitBatchStatus($ops)['status'] ?? '') === 'SUCCESS';
    }

    /**
     * The status-returning commit, honouring currentDocument preconditions
     * the way Firestore does: every precondition is checked BEFORE anything
     * is written, and one failure means none of the ops land.
     */
    public function commitBatchStatus(array $ops): array
    {
        foreach ($ops as $op) {
            $pre = $op['precondition'] ?? null;
            if (!is_array($pre)) { continue; }

            $coll = $op['collection']; $id = $op['docId'];
            $have = $this->fs->docs[$coll][$id] ?? null;
            $time = (string) ($this->fs->updateTimes[$coll][$id] ?? '');

            if (array_key_exists('updateTime', $pre)
                && ($have === null || $time !== (string) $pre['updateTime'])) {
                return ['status' => 'PRECONDITION_CONFLICT'];
            }
            if (array_key_exists('exists', $pre)
                && $pre['exists'] === false && $have !== null) {
                return ['status' => 'PRECONDITION_CONFLICT'];
            }
        }

        $this->fs->committed[] = $ops;
        foreach ($ops as $op) {
            if (($op['op'] ?? '') === 'delete') {
                $this->fs->remove($op['collection'], $op['docId']);
            } else {
                $this->fs->set($op['collection'], $op['docId'], $op['data'],
                    !empty($op['merge']));
            }
        }
        return ['status' => 'SUCCESS'];
    }
}
