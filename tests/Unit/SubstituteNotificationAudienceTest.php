<?php
/**
 * WHO HAS TO BE TOLD.
 *
 * A teacher is sent a notification the moment they are given cover. Nothing was
 * ever sent when that cover was taken away — cancelled, deleted, or quietly
 * reassigned to somebody else by an edit. So the last thing the teacher heard
 * was "you are covering 9-A period 3", and they either turn up to a lesson that
 * is not theirs or, worse, miss the duty they were moved to.
 *
 * This is not a policy a school gets to switch off. Telling somebody their duty
 * has changed is the minimum owed to a person whose day you have rearranged.
 *
 * The service decides WHO is affected, because only the service knows what was
 * actually persisted; the controller does the sending. Notifying from the
 * submitted form instead means notifying people the save may have dropped.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Substitute_service.php';
require_once __DIR__ . '/SubstituteConcurrentAssignmentTest.php';   // FakeSubstituteFirestore

final class SubstituteNotificationAudienceTest extends TestCase
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

    private function timetable(FakeSubstituteFirestore $fb): void
    {
        $periods = [];
        for ($p = 1; $p <= 6; $p++) {
            $periods[] = ['periodNumber' => $p, 'subject' => 'Maths',
                          'teacherId' => 'STA_ABSENT'];
        }
        $fb->docs['timetables'][self::SCHOOL . '_tt1'] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS, 'day' => self::DAY,
            'className' => 'Class 9th', 'section' => 'Section A', 'periods' => $periods,
        ];
        $fb->ensureStaff(self::SCHOOL, ['STA_ABSENT', 'STA_A', 'STA_B', 'STA_C',
            'STA_GHOST']);
    }

    /** @param array<int,string> $byPeriod period => substitute teacher id */
    private function save(FakeSubstituteFirestore $fb, array $byPeriod,
                          string $id = ''): array
    {
        $assignments = [];
        foreach ($byPeriod as $period => $subId) {
            $assignments[] = [
                'periodNumber' => $period, 'subject' => 'Maths',
                'className' => 'Class 9th', 'section' => 'Section A',
                'substituteTeacherId' => $subId,
                'substituteTeacherName' => 'Teacher ' . $subId,
            ];
        }
        return $this->svc($fb)->saveSubstitute($id, self::DATE, 'STA_ABSENT',
            'A Absent', $assignments, 'sick');
    }

    // -- being given cover ------------------------------------------------

    public function test_a_save_reports_who_is_now_covering(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);

        $res = $this->save($fb, [1 => 'STA_A', 2 => 'STA_B']);

        sort($res['substituteTeacherIds']);
        $this->assertSame(['STA_A', 'STA_B'], $res['substituteTeacherIds']);
        $this->assertSame(self::DATE, $res['date']);
        $this->assertSame([], $res['removedTeacherIds']);
    }

    /**
     * The list comes from what was SAVED, not from what was submitted. An
     * assignment the service drops — no period, no teacher — must not produce a
     * notification telling somebody they are covering a lesson that was never
     * recorded.
     */
    public function test_it_reports_only_the_assignments_that_were_kept(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);

        $res = $this->svc($fb)->saveSubstitute('', self::DATE, 'STA_ABSENT', 'A Absent', [
            ['periodNumber' => 1, 'subject' => 'Maths', 'className' => 'Class 9th',
             'section' => 'Section A', 'substituteTeacherId' => 'STA_A',
             'substituteTeacherName' => 'Teacher A'],
            // No period number: silently dropped by the save.
            ['periodNumber' => 0, 'subject' => 'Maths', 'className' => 'Class 9th',
             'section' => 'Section A', 'substituteTeacherId' => 'STA_GHOST',
             'substituteTeacherName' => 'Teacher Ghost'],
        ], 'sick');

        $this->assertSame(['STA_A'], $res['substituteTeacherIds'],
            'nobody is told they are covering a period that was not saved');
    }

    // -- having cover taken away ------------------------------------------

    /**
     * THE GAP. An edit that moves period 1 from one teacher to another leaves
     * the first teacher believing they are still covering it.
     */
    public function test_an_edit_reports_whoever_was_dropped(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);

        $first = $this->save($fb, [1 => 'STA_A', 2 => 'STA_B']);
        $res   = $this->save($fb, [1 => 'STA_C', 2 => 'STA_B'], $first['id']);

        $this->assertSame(['STA_A'], $res['removedTeacherIds'],
            'the teacher whose period was taken away has to be told');
        sort($res['substituteTeacherIds']);
        $this->assertSame(['STA_B', 'STA_C'], $res['substituteTeacherIds']);
    }

    /** Somebody who keeps a period, having lost another, has not been dropped. */
    public function test_a_teacher_who_still_has_a_period_is_not_reported_as_dropped(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);

        $first = $this->save($fb, [1 => 'STA_A', 2 => 'STA_A']);
        $res   = $this->save($fb, [1 => 'STA_A'], $first['id']);

        $this->assertSame([], $res['removedTeacherIds']);
    }

    public function test_cancelling_reports_everybody_who_was_covering(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $first = $this->save($fb, [1 => 'STA_A', 2 => 'STA_B']);

        $res = $this->svc($fb)->updateSubstituteStatus($first['id'], 'cancelled');

        sort($res['affectedTeacherIds']);
        $this->assertSame(['STA_A', 'STA_B'], $res['affectedTeacherIds']);
        $this->assertSame(self::DATE, $res['date']);
        $this->assertTrue($res['coverWithdrawn'],
            'a cancellation takes the duty away, and the service says so');
    }

    public function test_deleting_reports_everybody_who_was_covering(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $first = $this->save($fb, [1 => 'STA_A']);

        $res = $this->svc($fb)->deleteSubstitute($first['id']);

        $this->assertSame(['STA_A'], $res['affectedTeacherIds']);
        $this->assertSame(self::DATE, $res['date']);
        $this->assertTrue($res['coverWithdrawn']);
    }

    /** Marking cover completed takes nothing away, so nobody needs telling. */
    public function test_completing_withdraws_nothing(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $first = $this->save($fb, [1 => 'STA_A']);

        $res = $this->svc($fb)->updateSubstituteStatus($first['id'], 'completed');

        $this->assertFalse($res['coverWithdrawn']);
    }

    /** Reviving cover gives the duty back, so those teachers are told again. */
    public function test_reviving_reports_who_is_covering_again(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $first = $this->save($fb, [1 => 'STA_A']);
        $this->svc($fb)->updateSubstituteStatus($first['id'], 'cancelled');

        $res = $this->svc($fb)->updateSubstituteStatus($first['id'], 'assigned');

        $this->assertSame(['STA_A'], $res['affectedTeacherIds']);
        $this->assertFalse($res['coverWithdrawn']);
        $this->assertTrue($res['coverRestored']);
    }
}
