<?php
/**
 * DECIDING WHAT TO DO ABOUT A COVER THE TIMETABLE MOVED UNDER.
 *
 * Detection alone is not a workflow. A cover flagged as drifted stays flagged
 * for ever: there is no way to say "I have looked at this, it is fine" — so the
 * warning becomes furniture, and the next real one is invisible among them.
 *
 * The three outcomes an administrator actually has are cancel it, re-arrange it,
 * or keep it. The first two already exist. This is the third, and it is
 * deliberately the only new one: what SHOULD happen to a drifted cover depends
 * on the class, the teacher and the day, so the decision stays with the person
 * who knows those things. Nothing here decides anything on their behalf.
 *
 * Keeping it records WHAT WAS KEPT — the state of the timetable at the moment
 * the person looked. If the timetable moves again, that is a different fact and
 * the cover is flagged again.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Substitute_service.php';
require_once __DIR__ . '/SubstituteConcurrentAssignmentTest.php';

final class SubstituteDriftDecisionTest extends TestCase
{
    private const SCHOOL = 'SCH_SUB01';
    private const SESS   = '2026-27';
    private const DATE   = '2026-09-07';          // a Monday
    private const DAY    = 'Monday';

    private FakeAuditLog $audit;

    protected function setUp(): void
    {
        $this->audit = new FakeAuditLog();
    }

    private function svc(FakeSubstituteFirestore $fb): Substitute_service
    {
        return (new Substitute_service())->init(
            $fb, null, self::SCHOOL, self::SESS, 'STA_ADMIN', 'R Rao', $this->audit);
    }

    private function timetable(FakeSubstituteFirestore $fb, array $periods): void
    {
        $fb->docs['timetables'][self::SCHOOL . '_tt1'] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS, 'day' => self::DAY,
            'className' => 'Class 9th', 'section' => 'Section A',
            'periods' => $periods,
        ];
    }

    private function cover(FakeSubstituteFirestore $fb, array $extra = []): void
    {
        $fb->docs['substitutes'][self::SCHOOL . '_SUB_ONE'] = $extra + [
            'id' => 'SUB_ONE', 'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'date' => self::DATE, 'status' => 'assigned', 'version' => 1,
            'absentTeacherId' => 'STA_ABSENT', 'absentTeacherName' => 'A Absent',
            'assignments' => [[
                'periodNumber' => 3, 'subject' => 'Maths',
                'className' => 'Class 9th', 'section' => 'Section A',
                'substituteTeacherId' => 'STA_COVER',
                'substituteTeacherName' => 'Cover Teacher',
            ]],
            '__updateTime' => '50',
        ];
    }

    /** The timetable as republished: period 3 is now Science, another teacher. */
    private function drifted(FakeSubstituteFirestore $fb): void
    {
        $this->timetable($fb, [
            ['periodNumber' => 3, 'subject' => 'Science', 'teacherId' => 'STA_SOMEONE'],
        ]);
    }

    private function firstAssignment(FakeSubstituteFirestore $fb): array
    {
        $res = $this->svc($fb)->getSubstitutes(self::DATE);
        return $res['substitutes'][0]['assignments'][0];
    }

    // -- what the administrator is shown -----------------------------------

    /**
     * A note saying "this changed" is not enough to act on. The decision needs
     * both halves side by side: what the cover was agreed for, and what the
     * timetable says now.
     */
    public function test_the_row_shows_what_was_agreed_and_what_it_is_now(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->drifted($fb);
        $this->cover($fb);

        $a = $this->firstAssignment($fb);

        $this->assertTrue($a['timetableChanged']);
        $this->assertSame('Maths', $a['agreedSubject'],
            'what this cover was arranged for');
        $this->assertSame('Science', $a['currentSubject'],
            'what the timetable says now');
        $this->assertFalse($a['currentlyTheAbsentTeacher'],
            'and whether the absent teacher is even due in this period any more');
    }

    // -- keeping it --------------------------------------------------------

    public function test_a_cover_can_be_kept_as_it_is(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->drifted($fb);
        $this->cover($fb);

        $res = $this->svc($fb)->acknowledgeDrift('SUB_ONE');

        $this->assertSame('SUB_ONE', $res['id']);
        $this->assertNotSame('', $res['acknowledgedAt']);

        $a = $this->firstAssignment($fb);
        $this->assertFalse($a['timetableChanged'],
            'it has been looked at and kept, so it stops being flagged');
        $this->assertSame('R Rao', $a['keptBy'],
            'and the row says who decided that');
    }

    /** Keeping it changes nothing about the cover itself. */
    public function test_keeping_a_cover_does_not_alter_the_cover(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->drifted($fb);
        $this->cover($fb);
        $before = $fb->docs['substitutes'][self::SCHOOL . '_SUB_ONE']['assignments'];

        $this->svc($fb)->acknowledgeDrift('SUB_ONE');

        $this->assertSame($before,
            $fb->docs['substitutes'][self::SCHOOL . '_SUB_ONE']['assignments'],
            'the periods, the class and the teacher are all untouched');
    }

    /**
     * THE POINT OF RECORDING WHAT WAS KEPT. Keeping a cover against one change
     * must not silently absorb the next one — that would turn an acknowledgement
     * into a permanent blindfold.
     */
    public function test_a_further_change_flags_the_cover_again(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->drifted($fb);
        $this->cover($fb);
        $this->svc($fb)->acknowledgeDrift('SUB_ONE');
        $this->assertFalse($this->firstAssignment($fb)['timetableChanged']);

        // The timetable moves AGAIN — a different fact entirely.
        $this->timetable($fb, [
            ['periodNumber' => 3, 'subject' => 'History', 'teacherId' => 'STA_THIRD'],
        ]);

        $a = $this->firstAssignment($fb);
        $this->assertTrue($a['timetableChanged'],
            'a new change has not been looked at by anybody');
        // The note leads with the most consequential fact — the period changed
        // hands, so the absent teacher was never due in it — and the lesson it
        // became is carried in its own field for the row to show beside it.
        $this->assertSame('History', $a['currentSubject']);
        $this->assertSame('Maths', $a['agreedSubject']);
    }

    /** Restoring the timetable is also a change, and un-hides nothing wrongly. */
    public function test_a_cover_that_stops_drifting_is_simply_not_flagged(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->drifted($fb);
        $this->cover($fb);
        $this->svc($fb)->acknowledgeDrift('SUB_ONE');

        $this->timetable($fb, [
            ['periodNumber' => 3, 'subject' => 'Maths', 'teacherId' => 'STA_ABSENT'],
        ]);

        $this->assertFalse($this->firstAssignment($fb)['timetableChanged']);
    }

    /**
     * A subject change with the SAME teacher still puts a different lesson
     * in front of the class. Keeping one must not absorb the next.
     */
    public function test_a_later_subject_change_flags_the_cover_again(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb, [
            ['periodNumber' => 3, 'subject' => 'Science', 'teacherId' => 'STA_ABSENT'],
        ]);
        $this->cover($fb);
        $this->svc($fb)->acknowledgeDrift('SUB_ONE');
        $this->assertFalse($this->firstAssignment($fb)['timetableChanged']);

        // Same teacher, different lesson again.
        $this->timetable($fb, [
            ['periodNumber' => 3, 'subject' => 'Geography', 'teacherId' => 'STA_ABSENT'],
        ]);

        $a = $this->firstAssignment($fb);
        $this->assertTrue($a['timetableChanged'],
            'the lesson changed again and nobody has looked at that');
        $this->assertSame('Geography', $a['currentSubject']);
    }

    /**
     * A period that disappears entirely after a cover was kept is the most
     * consequential change of all: there is no lesson to cover.
     */
    public function test_a_period_vanishing_later_flags_the_cover_again(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->drifted($fb);
        $this->cover($fb);
        $this->svc($fb)->acknowledgeDrift('SUB_ONE');
        $this->assertFalse($this->firstAssignment($fb)['timetableChanged']);

        // The period is gone from the day altogether.
        $this->timetable($fb, [
            ['periodNumber' => 1, 'subject' => 'Maths', 'teacherId' => 'STA_ABSENT'],
        ]);

        $a = $this->firstAssignment($fb);
        $this->assertTrue($a['timetableChanged'],
            'there is no longer a lesson here at all');
        $this->assertSame('', $a['currentSubject']);
    }

    // -- what may not be kept ----------------------------------------------

    public function test_a_cover_that_has_not_drifted_cannot_be_kept(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb, [
            ['periodNumber' => 3, 'subject' => 'Maths', 'teacherId' => 'STA_ABSENT'],
        ]);
        $this->cover($fb);

        try {
            $this->svc($fb)->acknowledgeDrift('SUB_ONE');
            $this->fail('there is nothing here to decide about');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~still agrees|has not changed~i',
                $e->getMessage());
        }
    }

    /**
     * A timetable nobody could read is not a timetable that agrees. Keeping a
     * cover against a state we could not establish would record a decision
     * nobody actually made.
     */
    public function test_a_cover_cannot_be_kept_against_an_unreadable_timetable(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->drifted($fb);
        $this->cover($fb);
        $fb->failCollections = ['timetables'];

        try {
            $this->svc($fb)->acknowledgeDrift('SUB_ONE');
            $this->fail('there is no established state to keep it against');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~try again~i', $e->getMessage());
        }

        $fb->failCollections = [];
        $this->assertArrayNotHasKey('driftAcknowledged',
            $fb->docs['substitutes'][self::SCHOOL . '_SUB_ONE']);
    }

    public function test_another_session_s_cover_cannot_be_kept(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->drifted($fb);
        $this->cover($fb, ['session' => '2025-26']);

        try {
            $this->svc($fb)->acknowledgeDrift('SUB_ONE');
            $this->fail("another session's cover is not this session's business");
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~not found~i', $e->getMessage());
        }
    }

    // -- and it is recorded -------------------------------------------------

    public function test_keeping_a_cover_is_recorded_against_it(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->drifted($fb);
        $this->cover($fb);

        $this->svc($fb)->acknowledgeDrift('SUB_ONE');

        $this->assertSame([], $this->audit->rejected);
        $entry = end($this->audit->entries);
        $this->assertSame('substitute', $entry['entityType']);
        $this->assertSame('SUB_ONE', $entry['entityId']);
        $this->assertNotEmpty($entry['after'],
            'a decision to keep a cover is a decision somebody made');
    }
}
