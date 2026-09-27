<?php
/**
 * CHANGING AND REMOVING A COVER THAT IS ALREADY RECORDED.
 *
 * saveSubstitute() is careful: it reads the timetable, it reads the covers
 * already arranged, and it refuses anything that would put one teacher in two
 * rooms at once. The two operations that come AFTER a cover is recorded —
 * changing its status and removing it — share none of that care, and they are
 * reached from the same screen by the same people.
 *
 * These tests describe what those two operations must not be allowed to do.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Substitute_service.php';
require_once __DIR__ . '/SubstituteConcurrentAssignmentTest.php';   // FakeSubstituteFirestore

final class SubstituteRecordBoundaryTest extends TestCase
{
    private const SCHOOL = 'SCH_SUB01';
    private const SESS   = '2026-27';
    private const DATE   = '2026-09-07';          // a Monday

    private function svc(FakeSubstituteFirestore $fb, string $session = self::SESS): Substitute_service
    {
        return (new Substitute_service())->init(
            $fb, null, self::SCHOOL, $session, 'STA_ADMIN', 'R Rao');
    }

    /** A cover already stored, in whatever session and status the test needs. */
    private function seedCover(FakeSubstituteFirestore $fb, string $id,
                               string $session, string $status,
                               string $subId = 'STA_COVER', int $period = 3): void
    {
        $fb->docs['substitutes'][self::SCHOOL . '_' . $id] = [
            'id' => $id, 'schoolId' => self::SCHOOL, 'session' => $session,
            'date' => self::DATE, 'status' => $status,
            'absentTeacherId' => 'STA_ABSENT', 'absentTeacherName' => 'A Absent',
            'assignments' => [[
                'periodNumber' => $period, 'subject' => 'Maths',
                'className' => 'Class 9th', 'section' => 'Section A',
                'substituteTeacherId' => $subId, 'substituteTeacherName' => 'Cover Teacher',
            ]],
            'version' => 1, '__updateTime' => '50',
        ];
    }

    // -- a cancelled cover must not be revived on top of its replacement --

    /**
     * Cancelling frees the period: saveSubstitute() skips cancelled records when
     * it looks for clashes, so the replacement cover is arranged legitimately.
     * Putting the cancelled one back therefore books the same teacher twice, and
     * the status change asks nobody's permission.
     */
    public function test_a_cancelled_cover_cannot_be_revived_over_its_replacement(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->seedCover($fb, 'SUB_OLD', self::SESS, 'cancelled');
        $this->seedCover($fb, 'SUB_NEW', self::SESS, 'assigned');   // same teacher, same period
        $svc = $this->svc($fb);

        try {
            $svc->updateSubstituteStatus('SUB_OLD', 'assigned');
            $this->fail('reviving a cancelled cover must not double-book the teacher');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~already covering~i', $e->getMessage());
        }

        $this->assertSame('cancelled',
            $fb->docs['substitutes'][self::SCHOOL . '_SUB_OLD']['status'],
            'the cover stays cancelled when reviving it was refused');
    }

    /** Cancelling is always allowed: it can never create a clash. */
    public function test_cancelling_a_cover_is_always_allowed(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->seedCover($fb, 'SUB_ONE', self::SESS, 'assigned');

        $res = $this->svc($fb)->updateSubstituteStatus('SUB_ONE', 'cancelled');

        $this->assertSame('cancelled', $res['newStatus']);
        $this->assertSame('cancelled',
            $fb->docs['substitutes'][self::SCHOOL . '_SUB_ONE']['status']);
    }

    /**
     * Releasing a period can never create a clash, so nothing may stand in
     * its way — not even a clash that already exists in the data. An
     * administrator told "cannot cancel: that teacher is double-booked"
     * is trapped: cancelling is precisely how they would fix it.
     */
    public function test_cancelling_is_allowed_even_where_a_clash_already_exists(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->seedCover($fb, 'SUB_ONE', self::SESS, 'assigned');
        $this->seedCover($fb, 'SUB_TWO', self::SESS, 'assigned');  // same teacher, same period

        $res = $this->svc($fb)->updateSubstituteStatus('SUB_ONE', 'cancelled');

        $this->assertSame('cancelled', $res['newStatus']);
        $this->assertSame([], $fb->docs['substituteCoverDays'] ?? [],
            'releasing a period does not queue behind the day it releases');
    }

    /** Reviving a cover whose period nobody took in the meantime is fine. */
    public function test_a_cancelled_cover_may_be_revived_when_its_period_is_free(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->seedCover($fb, 'SUB_OLD', self::SESS, 'cancelled');

        $res = $this->svc($fb)->updateSubstituteStatus('SUB_OLD', 'assigned');

        $this->assertSame('assigned', $res['newStatus']);
        $this->assertSame('assigned',
            $fb->docs['substitutes'][self::SCHOOL . '_SUB_OLD']['status']);
    }

    // -- the session boundary --------------------------------------------

    /**
     * The document id carries the school but NOT the session, so one session's
     * covers are reachable from any other session of the same school.
     */
    public function test_a_cover_from_another_session_cannot_have_its_status_changed(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->seedCover($fb, 'SUB_LAST', '2025-26', 'assigned');

        try {
            $this->svc($fb)->updateSubstituteStatus('SUB_LAST', 'cancelled');
            $this->fail("another session's cover must not be reachable");
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~not found|no longer~i', $e->getMessage());
        }

        $this->assertSame('assigned',
            $fb->docs['substitutes'][self::SCHOOL . '_SUB_LAST']['status'],
            "last session's record is untouched");
    }

    public function test_a_cover_from_another_session_cannot_be_deleted(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->seedCover($fb, 'SUB_LAST', '2025-26', 'assigned');

        try {
            $this->svc($fb)->deleteSubstitute('SUB_LAST');
            $this->fail("another session's cover must not be removable");
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~not found|no longer~i', $e->getMessage());
        }

        $this->assertArrayHasKey(self::SCHOOL . '_SUB_LAST', $fb->docs['substitutes'],
            "last session's record still exists");
    }

    // -- a record that is not there ---------------------------------------

    /**
     * The status write is a MERGE, so an unknown id does not fail: it creates a
     * document holding a status and nothing else — no date, no teacher, no
     * periods — which then appears in the day's covers as an empty row.
     */
    public function test_a_status_change_on_an_unknown_cover_creates_nothing(): void
    {
        $fb = new FakeSubstituteFirestore();

        try {
            $this->svc($fb)->updateSubstituteStatus('SUB_GHOST', 'completed');
            $this->fail('an unknown cover must not be silently created');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~not found|no longer~i', $e->getMessage());
        }

        $this->assertSame([], $fb->docs['substitutes'] ?? [],
            'no document may be conjured by a status change');
    }

    /**
     * A read that FAILS is not proof the cover is absent. Deciding from it would
     * refuse a legitimate change or, worse, treat an existing record as new and
     * reset its version and provenance.
     */
    public function test_an_unreadable_cover_is_not_treated_as_missing(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->seedCover($fb, 'SUB_ONE', self::SESS, 'assigned');
        $fb->failReads = true;

        try {
            $this->svc($fb)->updateSubstituteStatus('SUB_ONE', 'cancelled');
            $this->fail('an unreadable cover must not be written blind');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~try again~i', $e->getMessage(),
                'the operator is told to retry, not that the cover is gone');
        }

        $fb->failReads = false;
        $this->assertSame('assigned',
            $fb->docs['substitutes'][self::SCHOOL . '_SUB_ONE']['status'],
            'nothing was written on a failed read');
    }

    public function test_an_unreadable_cover_is_not_deleted_blind(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->seedCover($fb, 'SUB_ONE', self::SESS, 'assigned');
        $fb->failReads = true;

        try {
            $this->svc($fb)->deleteSubstitute('SUB_ONE');
            $this->fail('an unreadable cover must not be removed blind');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~try again~i', $e->getMessage());
        }

        $fb->failReads = false;
        $this->assertArrayHasKey(self::SCHOOL . '_SUB_ONE', $fb->docs['substitutes'],
            'the cover is still there after a failed read');
    }
}
