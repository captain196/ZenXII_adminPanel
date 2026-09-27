<?php
/**
 * TWO ADMINISTRATORS, ONE SUBSTITUTE.
 *
 * saveSubstitute() checks for clashes thoroughly — a teacher already teaching
 * their own class, a teacher already covering that period, a period already
 * covered for that absence — and every one of those checks runs against a
 * snapshot read at the start of the request.
 *
 * The write that follows is unconditional. So two administrators arranging
 * cover for the same date at the same time both read a world in which the
 * teacher is free, both pass every check, and both write. The result is one
 * teacher booked to be in two classrooms at once, and the class that loses is
 * simply unattended.
 *
 * These tests reproduce that interleaving before it is fixed, and then hold the
 * fix in place.
 */

use PHPUnit\Framework\TestCase;

// CI libraries are not autoloaded; the suite requires them where it uses them.
require_once __DIR__ . '/../../application/libraries/Substitute_service.php';

/**
 * A Firestore stand-in that can be told to serve a STALE view of the store —
 * which is exactly what the loser of a race sees. Its request began before the
 * winner's write landed, so EVERY read it makes, not merely the one for the
 * covers, still shows the earlier world. Commits are still judged against the
 * real, current store, the way Firestore judges a precondition.
 */
class FakeSubstituteFirestore
{
    public array $docs = [];        // collection => docId => data
    public array $commits = [];     // batch ops it was asked to commit

    /** When set, every fail-loud read throws, the way a real one can. */
    public bool $failReads = false;

    /** Collections whose reads throw — one part of Firestore being unwell. */
    public array $failCollections = [];

    /** Frozen snapshot of the whole store, served to every read while set. */
    public ?array $stale = null;

    private int $clock = 100;

    public function firestoreGetOrFail($collection, $docId)
    {
        if ($this->failReads || in_array($collection, $this->failCollections, true)) {
            throw new \RuntimeException('GET_FAILED:' . $collection . ':UNAVAILABLE');
        }
        $src = $this->stale ?? $this->docs;
        return $src[$collection][$docId] ?? null;
    }

    public function firestoreQueryOrFail($collection, $conditions = [], $orderBy = null,
                                         $dir = 'ASC', $limit = null)
    {
        if ($this->failReads || in_array($collection, $this->failCollections, true)) {
            throw new \RuntimeException('QUERY_FAILED:' . $collection . ':UNAVAILABLE');
        }
        $src = ($this->stale ?? $this->docs)[$collection] ?? [];

        $rows = [];
        foreach ($src as $doc) {
            $ok = true;
            foreach ($conditions as $c) {
                [$f, $op, $v] = $c;
                $have = $doc[$f] ?? null;
                $pass = true;
                switch ($op) {
                    case '==': $pass = ($have === $v); break;
                    case '>=': $pass = ($have !== null && $have >= $v); break;
                    case '<=': $pass = ($have !== null && $have <= $v); break;
                    case '>':  $pass = ($have !== null && $have >  $v); break;
                    case '<':  $pass = ($have !== null && $have <  $v); break;
                }
                if (!$pass) { $ok = false; break; }
            }
            if ($ok) { $rows[] = $doc; }
        }

        // Ordering happens BEFORE the limit, which is the whole point of
        // asking the database for an order rather than sorting what came back.
        if ($orderBy !== null) {
            usort($rows, static function ($x, $y) use ($orderBy, $dir) {
                $c = strcmp((string) ($x[$orderBy] ?? ''), (string) ($y[$orderBy] ?? ''));
                return strtoupper((string) $dir) === 'DESC' ? -$c : $c;
            });
        }

        return $limit !== null ? array_slice($rows, 0, $limit) : $rows;
    }

    public function firestoreSet($collection, $docId, $data, $merge = false)
    {
        $prev = $this->docs[$collection][$docId] ?? [];
        $data['__updateTime'] = (string) (++$this->clock);
        $this->docs[$collection][$docId] = $merge ? array_merge($prev, $data) : $data;
        return true;
    }

    /**
     * Put real staff records behind the ids a test uses.
     *
     * saveSubstitute() establishes that everybody it names actually works at
     * this school before it stores anything, so a fixture that invents ids is
     * now refused — correctly. Existing records are left alone, so a test that
     * cares about somebody's subjects or designation still wins.
     */
    public function ensureStaff(string $school, array $ids): void
    {
        foreach ($ids as $id) {
            $key = $school . '_' . $id;
            if (isset($this->docs['staff'][$key])) { continue; }
            $this->docs['staff'][$key] = [
                'schoolId' => $school, 'staffId' => $id,
                'Name' => 'Staff ' . $id, 'status' => 'Active',
                'staff_roles' => ['ROLE_TEACHER'],
            ];
        }
    }

    public function firestoreDelete($collection, $docId)
    {
        unset($this->docs[$collection][$docId]);
        return true;
    }

    /** Honours updateTime and exists preconditions, the way Firestore does. */
    public function firestoreCommitBatchStatus(array $ops)
    {
        foreach ($ops as $op) {
            $this->commits[] = $op;
            $coll  = $op['collection'];
            $docId = $op['docId'];
            $pre   = $op['precondition'] ?? null;
            $have  = $this->docs[$coll][$docId] ?? null;

            if (is_array($pre) && array_key_exists('updateTime', $pre)) {
                if (!is_array($have)
                    || (string) ($have['__updateTime'] ?? '') !== (string) $pre['updateTime']) {
                    return ['status' => 'PRECONDITION_CONFLICT'];
                }
            } elseif (is_array($pre) && array_key_exists('exists', $pre)
                      && $pre['exists'] === false && is_array($have)) {
                return ['status' => 'PRECONDITION_CONFLICT'];
            }
        }

        foreach ($ops as $op) {
            $prev = $this->docs[$op['collection']][$op['docId']] ?? [];
            $data = $op['data'];
            $data['__updateTime'] = (string) (++$this->clock);
            $this->docs[$op['collection']][$op['docId']] =
                !empty($op['merge']) ? array_merge($prev, $data) : $data;
        }
        return ['status' => 'OK'];
    }
}

/**
 * The real Audit_log_service, minus Firestore.
 *
 * It keeps the two validations that matter, because both REJECT SILENTLY in
 * production — log() returns false and the caller never looks. An entry with
 * an unregistered entityType is not written, and nothing on screen says so.
 */
class FakeAuditLog
{
    public array $entries = [];
    public array $rejected = [];

    /** Mirrors Audit_log_service::ACTIONS / ENTITY_TYPES. */
    public array $actions = ['create', 'update', 'delete', 'status_change',
                             'rollover', 'generation', 'role_change'];
    public array $entityTypes = [];

    public function __construct()
    {
        // Read from the real service so the double cannot drift from it.
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Audit_log_service.php');
        if (preg_match('~const ENTITY_TYPES\s*=\s*\[(.*?)\];~s', $src, $m)) {
            preg_match_all("~'([a-zA-Z]+)'~", $m[1], $t);
            $this->entityTypes = $t[1];
        }
    }

    public function log($action, $entityType, $entityId, $before = null,
                        $after = null, $metadata = [], $actor = null)
    {
        if (!in_array($action, $this->actions, true)
            || !in_array($entityType, $this->entityTypes, true)
            || $entityId === '') {
            $this->rejected[] = ['action' => $action, 'entityType' => $entityType];
            return false;
        }
        $this->entries[] = [
            'action' => $action, 'entityType' => $entityType,
            'entityId' => $entityId, 'before' => $before, 'after' => $after,
        ];
        return true;
    }
}

final class SubstituteConcurrentAssignmentTest extends TestCase
{
    private const SCHOOL = 'SCH_SUB01';
    private const SESS   = '2026-27';
    private const DATE   = '2026-09-07';          // a Monday
    private const DAY    = 'Monday';

    private function svc(FakeSubstituteFirestore $fb): Substitute_service
    {
        return (new Substitute_service())->init(
            $fb, null, self::SCHOOL, self::SESS, 'STA_ADMIN', 'R Rao');
    }

    /** One class-section's day, so the busy-map scan has something real to read. */
    private function seedTimetable(FakeSubstituteFirestore $fb): void
    {
        $fb->docs['timetables'][self::SCHOOL . '_tt1'] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS, 'day' => self::DAY,
            'className' => 'Class 9th', 'section' => 'Section A',
            'periods' => [
                ['periodNumber' => 1, 'subject' => 'Maths', 'teacherId' => 'STA_ABSENT'],
                ['periodNumber' => 3, 'subject' => 'Maths', 'teacherId' => 'STA_ABSENT'],
            ],
        ];
        $fb->ensureStaff(self::SCHOOL, ['STA_ABSENT', 'STA_OTHER', 'STA_COVER']);
    }

    private function assignment(string $subId, int $period, string $class): array
    {
        return [[
            'periodNumber'          => $period,
            'subject'               => 'Maths',
            'className'             => $class,
            'section'               => 'Section A',
            'substituteTeacherId'   => $subId,
            'substituteTeacherName' => 'Cover Teacher',
        ]];
    }

    /** Every (period, substitute) pair booked across all stored records. */
    private function bookings(FakeSubstituteFirestore $fb): array
    {
        $out = [];
        foreach ($fb->docs['substitutes'] ?? [] as $doc) {
            foreach ($doc['assignments'] ?? [] as $a) {
                $out[] = ((int) ($a['periodNumber'] ?? 0)) . '|'
                       . (string) ($a['substituteTeacherId'] ?? '');
            }
        }
        sort($out);
        return $out;
    }

    // ── the check itself works, against a current view ───────────────────

    public function test_a_second_booking_of_the_same_teacher_is_refused_normally(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->seedTimetable($fb);
        $svc = $this->svc($fb);

        $svc->saveSubstitute('', self::DATE, 'STA_ABSENT', 'A Absent',
            $this->assignment('STA_COVER', 3, 'Class 9th'), 'sick');

        // A different absence, same date, same period, same cover teacher.
        try {
            $svc->saveSubstitute('', self::DATE, 'STA_OTHER', 'O Other',
                $this->assignment('STA_COVER', 3, 'Class 10th'), 'sick');
            $this->fail('the same teacher must not cover two classes at once');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~already covering~i', $e->getMessage());
        }

        $this->assertSame(['3|STA_COVER'], $this->bookings($fb),
            'exactly one booking may exist');
    }

    // ── the race ─────────────────────────────────────────────────────────

    /**
     * THE DEFECT. Both administrators read the covers BEFORE either wrote, so
     * both see the teacher as free. The checks pass twice and, with an
     * unconditional write, both records are stored — one teacher, two
     * classrooms, same period.
     */
    public function test_two_administrators_cannot_both_book_the_same_teacher(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->seedTimetable($fb);
        $svc = $this->svc($fb);

        // The world both administrators had open on screen.
        $emptyWorld = $fb->docs;

        // Administrator A saves first.
        $svc->saveSubstitute('', self::DATE, 'STA_ABSENT', 'A Absent',
            $this->assignment('STA_COVER', 3, 'Class 9th'), 'sick');

        // Administrator B's request began BEFORE that write landed, so every
        // read it makes still sees the empty world.
        $fb->stale = $emptyWorld;

        try {
            $svc->saveSubstitute('', self::DATE, 'STA_OTHER', 'O Other',
                $this->assignment('STA_COVER', 3, 'Class 10th'), 'sick');
            $this->fail('a save built on a stale view of the covers must be refused');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression(
                '~cover for this date changed|try again~i', $e->getMessage(),
                'the operator must be told to look again, in words they can act on');
        }

        $fb->stale = null;
        $this->assertSame(['3|STA_COVER'], $this->bookings($fb),
            'the first booking stands and the second never happened — one '
            . 'teacher cannot be in two classrooms');
    }

    /**
     * The serialisation point is what makes the write conditional. If it
     * cannot be read, the save must stop: writing anyway is the
     * unconditional write this whole mechanism exists to prevent.
     */
    public function test_a_save_stops_when_the_cover_day_cannot_be_read(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->seedTimetable($fb);
        $fb->failCollections = ['substituteCoverDays'];

        try {
            $this->svc($fb)->saveSubstitute('', self::DATE, 'STA_ABSENT', 'A Absent',
                $this->assignment('STA_COVER', 3, 'Class 9th'), 'sick');
            $this->fail('a save must not proceed without its serialisation point');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~try again~i', $e->getMessage());
        }

        $this->assertSame([], $fb->docs['substitutes'] ?? [],
            'no cover may be recorded when it could not be made conditional');
    }

    /**
     * Cover for a DIFFERENT date must not be blocked: two administrators
     * working on different days are not in conflict, and serialising them would
     * make the protection heavier than the problem.
     */
    public function test_cover_on_a_different_date_is_not_blocked(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->seedTimetable($fb);
        $svc = $this->svc($fb);

        $svc->saveSubstitute('', self::DATE, 'STA_ABSENT', 'A Absent',
            $this->assignment('STA_COVER', 3, 'Class 9th'), 'sick');

        // A different date, still stale on its own ledger (there is none yet).
        $res = $svc->saveSubstitute('', '2026-09-14', 'STA_ABSENT', 'A Absent',
            $this->assignment('STA_COVER', 3, 'Class 9th'), 'sick');

        $this->assertNotEmpty($res, 'a different date must save normally');
        $this->assertCount(2, $fb->docs['substitutes'] ?? [],
            'both dates hold their own cover');
    }
}
