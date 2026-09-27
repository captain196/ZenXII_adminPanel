<?php
/**
 * WHEN THE TIMETABLE MOVES UNDER A COVER THAT IS ALREADY ARRANGED.
 *
 * A cover is agreed against a particular timetable: Mrs Rao is away Monday, she
 * teaches Maths to 9-A in period 3, so somebody covers Maths, 9-A, period 3.
 * Publishing a new timetable rewrites the day the projection describes, and
 * nothing in that path looks at the covers already arranged for dates it
 * affects. The cover keeps its old period, class and subject, and says nothing.
 *
 * What SHOULD happen to such a cover is a decision for the school — cancel it,
 * re-arrange it, or leave it alone. What must not happen is for the screen to
 * keep presenting it as though the timetable still agreed with it. This is a
 * detection contract, not a repair: nothing here changes anybody's cover.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Substitute_service.php';
require_once __DIR__ . '/SubstituteConcurrentAssignmentTest.php';   // FakeSubstituteFirestore

final class SubstituteTimetableDriftTest extends TestCase
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

    /** The timetable as it stands now, for the one class-section in play. */
    private function timetable(FakeSubstituteFirestore $fb, array $periods): void
    {
        $fb->docs['timetables'][self::SCHOOL . '_tt1'] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS, 'day' => self::DAY,
            'className' => 'Class 9th', 'section' => 'Section A',
            'periods' => $periods,
        ];
    }

    private function cover(FakeSubstituteFirestore $fb, string $subject = 'Maths'): void
    {
        $fb->docs['substitutes'][self::SCHOOL . '_SUB_ONE'] = [
            'id' => 'SUB_ONE', 'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'date' => self::DATE, 'status' => 'assigned',
            'absentTeacherId' => 'STA_ABSENT', 'absentTeacherName' => 'A Absent',
            'assignments' => [[
                'periodNumber' => 3, 'subject' => $subject,
                'className' => 'Class 9th', 'section' => 'Section A',
                'substituteTeacherId' => 'STA_COVER',
                'substituteTeacherName' => 'Cover Teacher',
            ]],
        ];
    }

    private function firstAssignment(array $res): array
    {
        $this->assertNotEmpty($res['substitutes'], 'the cover is listed at all');
        $a = $res['substitutes'][0]['assignments'][0] ?? null;
        $this->assertIsArray($a, 'the cover carries its assignment');
        return $a;
    }

    // -- the timetable still agrees ---------------------------------------

    public function test_a_cover_the_timetable_still_agrees_with_is_not_flagged(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb, [
            ['periodNumber' => 3, 'subject' => 'Maths', 'teacherId' => 'STA_ABSENT'],
        ]);
        $this->cover($fb);

        $a = $this->firstAssignment($this->svc($fb)->getSubstitutes(self::DATE));

        $this->assertFalse($a['timetableChanged'] ?? true);
        $this->assertSame('', $a['timetableNote'] ?? 'x');
    }

    // -- the period is no longer that teacher's ---------------------------

    /**
     * The republished timetable gives period 3 to somebody else. The absent
     * teacher was not going to be in that room anyway, so the cover is now
     * arranging for a class that does not need it — while the period the
     * teacher DID move to has nobody.
     */
    public function test_a_cover_whose_period_changed_hands_is_flagged(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb, [
            ['periodNumber' => 3, 'subject' => 'Maths', 'teacherId' => 'STA_SOMEONE_ELSE'],
        ]);
        $this->cover($fb);

        $a = $this->firstAssignment($this->svc($fb)->getSubstitutes(self::DATE));

        $this->assertTrue($a['timetableChanged'] ?? false,
            'the cover no longer matches the timetable it was arranged against');
        $this->assertMatchesRegularExpression('~timetable~i', $a['timetableNote'] ?? '');
    }

    /** The period was removed from the day altogether. */
    public function test_a_cover_for_a_period_that_no_longer_exists_is_flagged(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb, [
            ['periodNumber' => 1, 'subject' => 'Maths', 'teacherId' => 'STA_ABSENT'],
        ]);
        $this->cover($fb);

        $a = $this->firstAssignment($this->svc($fb)->getSubstitutes(self::DATE));

        $this->assertTrue($a['timetableChanged'] ?? false);
    }

    /** The period is still theirs, but it is a different lesson now. */
    public function test_a_cover_whose_subject_changed_is_flagged(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb, [
            ['periodNumber' => 3, 'subject' => 'Science', 'teacherId' => 'STA_ABSENT'],
        ]);
        $this->cover($fb, 'Maths');

        $a = $this->firstAssignment($this->svc($fb)->getSubstitutes(self::DATE));

        $this->assertTrue($a['timetableChanged'] ?? false);
        $this->assertMatchesRegularExpression('~Science~', $a['timetableNote'] ?? '',
            'the note says what the period is now, not merely that it changed');
    }

    // -- nothing is repaired ----------------------------------------------

    /**
     * Detection only. Re-pointing somebody's cover at a period they never
     * agreed to cover would be worse than leaving it visibly wrong.
     */
    public function test_a_drifted_cover_is_not_rewritten(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb, [
            ['periodNumber' => 3, 'subject' => 'Science', 'teacherId' => 'STA_SOMEONE_ELSE'],
        ]);
        $this->cover($fb);
        $before = $fb->docs['substitutes'][self::SCHOOL . '_SUB_ONE'];

        $this->svc($fb)->getSubstitutes(self::DATE);

        $this->assertSame($before, $fb->docs['substitutes'][self::SCHOOL . '_SUB_ONE'],
            'looking at a day may not change what is arranged on it');
    }

    // -- the check must not invent an answer ------------------------------

    /**
     * If the timetable cannot be read, EVERY cover would look as though its
     * period had vanished. Flagging them all would send an administrator to
     * re-arrange cover that is perfectly correct.
     */
    public function test_an_unreadable_timetable_flags_nothing(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb, [
            ['periodNumber' => 3, 'subject' => 'Maths', 'teacherId' => 'STA_ABSENT'],
        ]);
        $this->cover($fb);
        $fb->failCollections = ['timetables'];

        $res = $this->svc($fb)->getSubstitutes(self::DATE);
        $a = $this->firstAssignment($res);

        $this->assertArrayNotHasKey('timetableChanged', $a,
            'a timetable nobody could read is not evidence either way, so the '
            . 'cover carries no claim at all — not a claim that it is fine');
        $this->assertFalse($res['timetableChecked'] ?? true,
            'and the screen is told the check did not run');
    }

    /**
     * A scan that hit its bound has not read the day either. The class whose
     * row fell off the end would look as though its periods had vanished,
     * and every cover on it would be flagged for re-arranging.
     */
    public function test_a_day_grid_that_hit_its_bound_flags_nothing(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb, [
            ['periodNumber' => 3, 'subject' => 'Maths', 'teacherId' => 'STA_ABSENT'],
        ]);
        // More class-sections on this day than one scan can see.
        for ($i = 0; $i <= Substitute_service::BUSY_SCAN_LIMIT; $i++) {
            $fb->docs['timetables'][self::SCHOOL . '_tt_pad' . $i] = [
                'schoolId' => self::SCHOOL, 'session' => self::SESS, 'day' => self::DAY,
                'className' => 'Class ' . $i, 'section' => 'Section A', 'periods' => [],
            ];
        }
        $this->cover($fb);

        $res = $this->svc($fb)->getSubstitutes(self::DATE);

        $this->assertFalse($res['timetableChecked'] ?? true,
            'a day only half read is not a day that was checked');
        $this->assertArrayNotHasKey('timetableChanged',
            $this->firstAssignment($res),
            'and no cover carries a claim drawn from half a day');
    }

    /**
     * The check needs one day's timetable, so it only runs for a single day.
     * A range covers many weekdays and cannot be judged from one read.
     */
    public function test_a_date_range_does_not_pretend_to_have_checked(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb, [
            ['periodNumber' => 3, 'subject' => 'Science', 'teacherId' => 'STA_SOMEONE_ELSE'],
        ]);
        $this->cover($fb);

        $res = $this->svc($fb)->getSubstitutes('', '2026-09-01', '2026-09-30');

        $this->assertFalse($res['timetableChecked'] ?? true);
        $a = $this->firstAssignment($res);
        $this->assertArrayNotHasKey('timetableChanged', $a,
            'an unchecked cover carries no claim about the timetable');
    }
}
