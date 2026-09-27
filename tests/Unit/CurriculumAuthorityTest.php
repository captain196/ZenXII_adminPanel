<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Curriculum_service.php';

/**
 * WHO MAY CHANGE A SUBJECT'S CURRICULUM.
 *
 * The syllabus for a class-and-subject is edited by the people who teach it, and
 * the gate that decides so reads the subject assignments — the authority the
 * module before this one exists to maintain. Three write paths depend on it:
 * saving the plan, marking a topic's progress, and deleting a topic.
 *
 * Until now none of that had ever been run by a test. The gate reached the
 * assignment service through the CI singleton, so outside a real request it
 * always denied, and every attempt to exercise it silently proved nothing.
 */
final class CurriculumAuthorityTest extends TestCase
{
    private const SCHOOL = 'SCH_CUR01';
    private const SESS   = '2026-27';
    private const CS     = 'Class 9th/Section A';

    private function svc(FakeCurriculumFirebase $fb, string $role, string $adminId,
                         $sas = null): Curriculum_service
    {
        $svc = (new Curriculum_service())->init(
            $fb, $fb, self::SCHOOL, self::SCHOOL, self::SESS,
            $adminId, 'The Actor', $role, null);
        return $svc->withAssignmentService($sas ?? new FakeAssignmentLookup());
    }

    private function topics(): array
    {
        return [['title' => 'Fractions', 'chapter' => '1', 'est_periods' => 4,
                 'status' => 'not_started', 'sort_order' => 0]];
    }

    // -- the people who run the school ------------------------------------

    public function test_a_principal_may_edit_any_subject(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $res = $this->svc($fb, 'Principal', 'STA_HEAD')
            ->saveCurriculum(self::CS, 'Mathematics', $this->topics(), null);

        $this->assertNotEmpty($res);
        $this->assertNotEmpty($fb->docs['curriculum'] ?? []);
    }

    // -- the teacher who actually teaches it -------------------------------

    public function test_the_assigned_teacher_may_edit_their_own_subject(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $sas = new FakeAssignmentLookup([
            ['teacherId' => 'STA_T1', 'subjectName' => 'Mathematics', 'subjectCode' => 'MATH'],
        ]);

        $res = $this->svc($fb, 'Teacher', 'STA_T1', $sas)
            ->saveCurriculum(self::CS, 'Mathematics', $this->topics(), null);

        $this->assertNotEmpty($res);
        $this->assertSame('Class 9th', $sas->askedClass,
            'the gate looks up the class and section from the composite key');
        $this->assertSame('Section A', $sas->askedSection);
    }

    /** The subject code is accepted as well as the name. */
    public function test_the_subject_may_be_named_by_its_code(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $sas = new FakeAssignmentLookup([
            ['teacherId' => 'STA_T1', 'subjectName' => 'Mathematics', 'subjectCode' => 'MATH'],
        ]);

        $this->assertNotEmpty(
            $this->svc($fb, 'Teacher', 'STA_T1', $sas)
                ->saveCurriculum(self::CS, 'MATH', $this->topics(), null));
    }

    // -- and everybody else ------------------------------------------------

    public function test_a_teacher_of_another_subject_is_refused(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $sas = new FakeAssignmentLookup([
            ['teacherId' => 'STA_T1', 'subjectName' => 'Science', 'subjectCode' => 'SCI'],
        ]);

        try {
            $this->svc($fb, 'Teacher', 'STA_T1', $sas)
                ->saveCurriculum(self::CS, 'Mathematics', $this->topics(), null);
            $this->fail('a teacher may not rewrite a syllabus they do not teach');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~not authorized~i', $e->getMessage());
        }

        $this->assertSame([], $fb->docs['curriculum'] ?? []);
    }

    public function test_a_teacher_of_another_class_is_refused(): void
    {
        $fb  = new FakeCurriculumFirebase();
        // Assigned to Mathematics, but in a different class entirely.
        $sas = new FakeAssignmentLookup([]);

        try {
            $this->svc($fb, 'Teacher', 'STA_T1', $sas)
                ->saveCurriculum(self::CS, 'Mathematics', $this->topics(), null);
            $this->fail('an assignment elsewhere is not an assignment here');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~not authorized~i', $e->getMessage());
        }
    }

    public function test_an_actor_with_no_identity_is_refused(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $sas = new FakeAssignmentLookup([
            ['teacherId' => '', 'subjectName' => 'Mathematics', 'subjectCode' => 'MATH'],
        ]);

        try {
            $this->svc($fb, 'Teacher', '', $sas)
                ->saveCurriculum(self::CS, 'Mathematics', $this->topics(), null);
            $this->fail('an empty actor id must never match an empty teacherId');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~not authorized~i', $e->getMessage());
        }
    }

    // -- all three write paths are gated, not just the first ---------------

    public function test_marking_a_topic_is_gated_too(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $sas = new FakeAssignmentLookup([]);

        try {
            $this->svc($fb, 'Teacher', 'STA_T1', $sas)
                ->updateTopicStatus(self::CS, 'Mathematics', 'T1', 0, 'completed', null);
            $this->fail('progress is part of the syllabus, not a free-for-all');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~not authorized~i', $e->getMessage());
        }
    }

    public function test_deleting_a_topic_is_gated_too(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $sas = new FakeAssignmentLookup([]);

        try {
            $this->svc($fb, 'Teacher', 'STA_T1', $sas)
                ->deleteTopic(self::CS, 'Mathematics', 'T1', 0, null);
            $this->fail('deleting somebody else\'s topic is the worst of the three');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~not authorized~i', $e->getMessage());
        }
    }

    // -- a lookup that could not run ---------------------------------------

    /**
     * THE DEFECT. A failed assignment lookup returned false, and the caller's
     * message says "no matching subject assignment" — a statement about this
     * person's timetable, and untrue. It sends a teacher to argue with the
     * office about an assignment they already have.
     */
    public function test_an_unreadable_lookup_is_not_a_missing_assignment(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $sas = new FakeAssignmentLookup([]);
        $sas->fail = true;

        try {
            $this->svc($fb, 'Teacher', 'STA_T1', $sas)
                ->saveCurriculum(self::CS, 'Mathematics', $this->topics(), null);
            $this->fail('an unreadable lookup must still refuse');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~could not be read~i', $e->getMessage());
            $this->assertDoesNotMatchRegularExpression(
                '~no matching subject assignment~i', $e->getMessage(),
                'the teacher is not the problem and must not be told they are');
        }

        $this->assertSame([], $fb->docs['curriculum'] ?? [],
            'and nothing was written');
    }

    /** The refusal holds even for a lookup failure — it never falls open. */
    public function test_an_unreadable_lookup_never_permits_the_write(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $sas = new FakeAssignmentLookup([
            ['teacherId' => 'STA_T1', 'subjectName' => 'Mathematics', 'subjectCode' => 'MATH'],
        ]);
        $sas->fail = true;

        $this->expectException(Service_exception::class);
        $this->svc($fb, 'Teacher', 'STA_T1', $sas)
            ->saveCurriculum(self::CS, 'Mathematics', $this->topics(), null);
    }
}

/** Stands in for Subject_assignment_service, which the gate consults. */
class FakeAssignmentLookup
{
    public array $rows;
    public bool $fail = false;
    public string $askedClass = '';
    public string $askedSection = '';

    public function __construct(array $rows = []) { $this->rows = $rows; }

    public function isReady(): bool { return true; }

    public function getAssignmentsForClass(string $class, string $section = ''): array
    {
        $this->askedClass = $class;
        $this->askedSection = $section;
        if ($this->fail) {
            throw new \RuntimeException('QUERY_FAILED:subjectAssignments:UNAVAILABLE');
        }
        return $this->rows;
    }
}

/**
 * A Firebase stand-in with the methods Curriculum_service actually calls, each
 * behaving as the real one does: firestoreGet returns null on failure as it
 * does for a missing document, firestoreGetOrFail throws, and commitBatch is
 * all-or-nothing.
 */
class FakeCurriculumFirebase
{
    public array $docs = [];
    public bool $failReads = false;
    /** Collections whose reads throw — one part of Firestore being unwell. */
    public array $failCollections = [];
    /** Sub-document ids that cannot be read, to model a partial fetch. */
    public array $unreadableTopics = [];
    /**
     * Individual QUERIES that fail, matched on a condition they carry — e.g.
     * [['status', 'skipped']] fails only the skipped-lesson query. Two
     * queries against one collection cannot be told apart by collection, and
     * a guard on the second will happily cover for a missing guard on the
     * first if the test cannot separate them.
     */
    public array $failConditions = [];
    /**
     * Fail any query that does NOT constrain these fields — the way to reach
     * the broader of two queries over one collection (a class-wide assignment
     * lookup carries no section filter, its per-section sibling does).
     */
    public array $failWhenUnconstrained = [];
    /** Every query issued: shape matters as much as result on this store. */
    public array $queries = [];
    public array $commits = [];
    /** Per-document update tokens, the way Firestore keeps updateTime. */
    public array $updateTimes = [];
    private int $clock = 0;

    public function firestoreGet($collection, $docId)
    {
        if ($this->failReads || in_array($collection, $this->failCollections, true)) {
            return null;                       // the silent shape: null on failure
        }
        if (in_array($docId, $this->unreadableTopics, true)) { return null; }
        return $this->_read($collection, $docId);
    }

    public function firestoreGetOrFail($collection, $docId)
    {
        if ($this->failReads || in_array($collection, $this->failCollections, true)) {
            throw new \RuntimeException('GET_FAILED:' . $collection . ':UNAVAILABLE');
        }
        // A document listed as unreadable cannot be read by EITHER surface.
        // The silent one reports that as null, the fail-loud one as a throw —
        // which is the whole difference between them.
        if (in_array($docId, $this->unreadableTopics, true)) {
            throw new \RuntimeException('GET_FAILED:' . $collection . ':UNAVAILABLE');
        }
        return $this->_read($collection, $docId);
    }

    /**
     * A document as a caller sees it: its fields plus the update token the
     * real client surfaces as __updateTime, which is what a compare-and-set
     * write is built from.
     */
    private function _read($collection, $docId): ?array
    {
        $doc = $this->docs[$collection][$docId] ?? null;
        if (!is_array($doc)) { return null; }
        $ut = $this->updateTimes[$collection][$docId] ?? '';
        return $ut === '' ? $doc : $doc + ['__updateTime' => $ut];
    }

    public function firestoreSet($collection, $docId, $data, $merge = false)
    {
        // A plain set is a persistence boundary too — Lesson_plan_service
        // writes through this one, so the window has to open here as well.
        $this->_window();
        $prev = $this->docs[$collection][$docId] ?? [];
        unset($data['__updateTime']);
        $this->docs[$collection][$docId] = $merge ? array_merge($prev, $data) : $data;
        $this->updateTimes[$collection][$docId] = 'ut' . (++$this->clock);
        return true;
    }

    public function firestoreDelete($collection, $docId)
    {
        unset($this->docs[$collection][$docId], $this->updateTimes[$collection][$docId]);
        return true;
    }

    /** All-or-nothing, like the REST :commit endpoint. */
    public function firestoreCommitBatch(array $ops)
    {
        $this->_window();
        $this->commits[] = $ops;
        foreach ($ops as $op) {
            $kind = $op['op'] ?? 'set';
            if ($kind === 'delete') {
                $this->firestoreDelete($op['collection'], $op['docId']);
            } else {
                $this->firestoreSet($op['collection'], $op['docId'],
                    $op['data'], !empty($op['merge']));
            }
        }
        return true;
    }

    /**
     * Runs once, immediately before the next commit — the window between a
     * service reading a document and writing back what it decided from it.
     * A race that is only ever tested by calling twice in sequence is not a
     * race: the second call re-reads and sees the first. This is where the
     * other editor actually lands.
     */
    public $beforeCommit = null;

    private function _window(): void
    {
        $hook = $this->beforeCommit;
        if ($hook === null) { return; }
        $this->beforeCommit = null;      // the window opens once
        $hook();
    }

    /**
     * The commit the real client makes when any op carries a precondition:
     * the server checks every one of them BEFORE applying anything, and
     * refuses the whole batch if one does not hold. That refusal is the only
     * thing standing between two people editing one syllabus, so the fake
     * enforces it rather than accepting the field and ignoring it.
     */
    public function firestoreCommitBatchStatus(array $ops): array
    {
        $this->_window();
        foreach ($ops as $op) {
            $pre = $op['precondition'] ?? null;
            if (!is_array($pre)) { continue; }
            $coll = $op['collection']; $id = $op['docId'];
            $exists = isset($this->docs[$coll][$id]);
            if (array_key_exists('exists', $pre)) {
                if ($exists !== (bool) $pre['exists']) {
                    return ['status' => 'PRECONDITION_CONFLICT', 'code' => 409,
                            'message' => 'document exists'];
                }
            }
            if (isset($pre['updateTime'])) {
                $have = $this->updateTimes[$coll][$id] ?? '';
                if (!$exists || $have !== $pre['updateTime']) {
                    return ['status' => 'PRECONDITION_CONFLICT', 'code' => 400,
                            'message' => 'stale updateTime'];
                }
            }
        }
        $this->firestoreCommitBatch($ops);
        return ['status' => 'SUCCESS', 'code' => 200, 'message' => ''];
    }

    /**
     * A collection query, in both the shapes the real client offers: the
     * silent one that answers a failure with an empty list exactly as it
     * answers "nothing matched", and the fail-loud one that throws. Which
     * of the two a service reaches for is the whole question on a screen
     * that draws an empty list as "nothing has been planned".
     */
    public function firestoreQuery($collection, array $conditions = [],
                                   $orderBy = null, $direction = 'ASC',
                                   $limit = null, $startAfter = null): array
    {
        if ($this->failReads || in_array($collection, $this->failCollections, true)
            || $this->_conditionFails($conditions)) {
            return [];                     // the silent shape
        }
        return $this->_match($collection, $conditions, $orderBy, $direction, $limit);
    }

    public function firestoreQueryOrFail($collection, array $conditions = [],
                                         $orderBy = null, $direction = 'ASC',
                                         $limit = null, $startAfter = null): array
    {
        if ($this->failReads || in_array($collection, $this->failCollections, true)
            || $this->_conditionFails($conditions)) {
            throw new \RuntimeException('QUERY_FAILED:' . $collection . ':UNAVAILABLE');
        }
        return $this->_match($collection, $conditions, $orderBy, $direction, $limit);
    }

    private function _conditionFails(array $conditions): bool
    {
        foreach ($this->failConditions as [$field, $value]) {
            foreach ($conditions as $c) {
                if (($c[0] ?? null) === $field && ($c[2] ?? null) === $value) { return true; }
            }
        }
        foreach ($this->failWhenUnconstrained as $field) {
            $has = false;
            foreach ($conditions as $c) {
                if (($c[0] ?? null) === $field) { $has = true; break; }
            }
            if (!$has) { return true; }
        }
        return false;
    }

    /** Equality/inequality matching over the stored documents. */
    private function _match($collection, array $conditions, $orderBy,
                            $direction, $limit): array
    {
        $this->queries[] = [
            'collection' => $collection,
            'conditions' => $conditions,
            'orderBy'    => $orderBy,
            'limit'      => $limit,
        ];
        $rows = [];
        foreach (($this->docs[$collection] ?? []) as $id => $doc) {
            $keep = true;
            foreach ($conditions as $cond) {
                [$field, $op, $want] = $cond;
                $have = $doc[$field] ?? null;
                $ok = true;
                switch ($op) {
                    case '==': $ok = ($have == $want); break;
                    case '!=': $ok = ($have != $want); break;
                    case '>':  $ok = ($have >  $want); break;
                    case '>=': $ok = ($have >= $want); break;
                    case '<':  $ok = ($have <  $want); break;
                    case '<=': $ok = ($have <= $want); break;
                    case 'in': $ok = is_array($want) && in_array($have, $want, true); break;
                }
                if (!$ok) { $keep = false; break; }
            }
            if ($keep) { $rows[] = $doc; }
        }
        if ($orderBy !== null) {
            usort($rows, function ($a, $b) use ($orderBy, $direction) {
                $cmp = ($a[$orderBy] ?? null) <=> ($b[$orderBy] ?? null);
                return strtoupper((string) $direction) === 'DESC' ? -$cmp : $cmp;
            });
        }
        if ($limit !== null && $limit > 0) { $rows = array_slice($rows, 0, (int) $limit); }
        return $rows;
    }

    /** The Firestore_service surface, for services handed this as $fs. */
    public function sessionWhere($collection, array $extra = [], $orderBy = null,
                                 $direction = 'ASC', $limit = null): array
    {
        return $this->_rows($this->firestoreQuery($collection, $extra, $orderBy,
                                                  $direction, $limit));
    }

    public function schoolWhere($collection, array $extra = [], $orderBy = null,
                                $direction = 'ASC', $limit = null): array
    {
        return $this->_rows($this->firestoreQuery($collection, $extra, $orderBy,
                                                  $direction, $limit));
    }

    public function where($collection, array $conditions = [], $orderBy = null,
                          $direction = 'ASC', $limit = null): array
    {
        return $this->_rows($this->firestoreQuery($collection, $conditions, $orderBy,
                                                  $direction, $limit));
    }

    public function whereOrFail($collection, array $conditions = [], $orderBy = null,
                                $direction = 'ASC', $limit = null): array
    {
        return $this->_rows($this->firestoreQueryOrFail($collection, $conditions,
                                                        $orderBy, $direction, $limit));
    }

    public function schoolWhereOrFail($collection, array $extra = [], $orderBy = null,
                                      $direction = 'ASC', $limit = null): array
    {
        return $this->_rows($this->firestoreQueryOrFail($collection, $extra, $orderBy,
                                                        $direction, $limit));
    }

    /**
     * Firestore_service hands its callers [{id, data}] rows; the Firebase
     * library hands back flat documents. Services read one or the other, and
     * a fake that blurs the two proves nothing about either — a caller that
     * reads $r['data'] would silently see every row as empty.
     */
    private function _rows(array $docs): array
    {
        $out = [];
        foreach ($docs as $d) {
            $out[] = ['id' => (string) ($d['planId'] ?? $d['logId'] ?? ''), 'data' => $d];
        }
        return $out;
    }

    /** No parallel reader, so the service falls back to one get per topic. */
    public function getFirestoreDb() { return null; }
}
