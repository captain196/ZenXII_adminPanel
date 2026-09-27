<?php
/**
 * WHAT THE SCREEN IS TOLD ABOUT A DAY.
 *
 * The list of covers is not a report anybody reads for interest: it is what an
 * administrator looks at before deciding who covers the next absence. So the
 * two ways it can be wrong both end in the same place — cover arranged twice,
 * or a class left with nobody.
 *
 *   1. A read that FAILED must not be presented as a day with no cover.
 *   2. A range of dates must be asked of the database, not sliced out of an
 *      arbitrary hundred documents after the fact.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Substitute_service.php';
require_once __DIR__ . '/SubstituteConcurrentAssignmentTest.php';   // FakeSubstituteFirestore

final class SubstituteDayViewTest extends TestCase
{
    private const SCHOOL = 'SCH_SUB01';
    private const SESS   = '2026-27';

    private function svc(FakeSubstituteFirestore $fb): Substitute_service
    {
        return (new Substitute_service())->init(
            $fb, null, self::SCHOOL, self::SESS, 'STA_ADMIN', 'R Rao');
    }

    private function seed(FakeSubstituteFirestore $fb, string $id, string $date,
                          string $session = self::SESS): void
    {
        $fb->docs['substitutes'][self::SCHOOL . '_' . $id] = [
            'id' => $id, 'schoolId' => self::SCHOOL, 'session' => $session,
            'date' => $date, 'status' => 'assigned',
            'absentTeacherId' => 'STA_ABSENT', 'absentTeacherName' => 'A Absent',
            'assignments' => [[
                'periodNumber' => 3, 'subject' => 'Maths',
                'className' => 'Class 9th', 'section' => 'Section A',
                'substituteTeacherId' => 'STA_COVER',
                'substituteTeacherName' => 'Cover Teacher',
            ]],
        ];
    }

    // -- a failed read is not an empty day --------------------------------

    /**
     * An administrator shown an empty day arranges cover for a period that is
     * already covered. Before the write learned to refuse that, it was a
     * double-booking; now it is a refusal they cannot explain. Either way the
     * screen told them something untrue.
     */
    public function test_a_day_that_could_not_be_read_is_not_shown_as_empty(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->seed($fb, 'SUB_ONE', '2026-09-07');
        $fb->failReads = true;

        try {
            $this->svc($fb)->getSubstitutes('2026-09-07');
            $this->fail('a failed read must not be presented as a day with no cover');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~try again~i', $e->getMessage());
        }
    }

    public function test_a_day_with_no_cover_is_still_an_ordinary_empty_day(): void
    {
        $fb = new FakeSubstituteFirestore();

        $res = $this->svc($fb)->getSubstitutes('2026-09-07');

        $this->assertSame([], $res['substitutes'], 'genuinely nothing arranged');
        $this->assertFalse($res['truncated'] ?? true, 'and the list is complete');
    }

    // -- the session boundary on the way out ------------------------------

    public function test_another_session_s_covers_are_not_listed(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->seed($fb, 'SUB_NOW',  '2026-09-07');
        $this->seed($fb, 'SUB_LAST', '2026-09-07', '2025-26');

        $res = $this->svc($fb)->getSubstitutes('2026-09-07');

        $this->assertCount(1, $res['substitutes']);
        $this->assertSame('SUB_NOW', $res['substitutes'][0]['id']);
    }

    // -- a range of dates -------------------------------------------------

    /**
     * THE DEFECT. The range used to be applied in PHP to whatever hundred
     * documents came back first, so a school with more covers than that got a
     * report over the wrong hundred — commonly an empty one, for a week in
     * which cover had certainly been arranged.
     */
    public function test_a_date_range_finds_covers_beyond_the_first_page(): void
    {
        $fb = new FakeSubstituteFirestore();

        // A busy session: 150 covers spread across the year...
        for ($i = 0; $i < 150; $i++) {
            $this->seed($fb, 'SUB_OLD_' . $i,
                (new DateTime('2026-04-06'))->modify("+{$i} days")->format('Y-m-d'));
        }
        // ...and the week actually being asked about, latest in the year.
        $this->seed($fb, 'SUB_WANTED', '2027-01-11');

        $res = $this->svc($fb)->getSubstitutes('', '2027-01-05', '2027-01-15');

        $ids = array_column($res['substitutes'], 'id');
        $this->assertSame(['SUB_WANTED'], $ids,
            'the week asked for is the week returned');
    }

    public function test_a_date_range_excludes_what_falls_outside_it(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->seed($fb, 'SUB_BEFORE', '2026-09-01');
        $this->seed($fb, 'SUB_INSIDE', '2026-09-08');
        $this->seed($fb, 'SUB_AFTER',  '2026-09-20');

        $res = $this->svc($fb)->getSubstitutes('', '2026-09-05', '2026-09-12');

        $this->assertSame(['SUB_INSIDE'], array_column($res['substitutes'], 'id'));
    }

    // -- a list that had to be shortened says so --------------------------

    /**
     * Shortening the list is acceptable; shortening it silently is not. The
     * write path refuses outright when it cannot see every cover, because it is
     * deciding. This one is only showing, so it shows what it has and says the
     * list is not all of it.
     */
    public function test_a_shortened_list_says_that_it_was_shortened(): void
    {
        $fb = new FakeSubstituteFirestore();
        for ($i = 0; $i < 260; $i++) {
            $this->seed($fb, 'SUB_' . $i,
                (new DateTime('2026-04-06'))->modify("+{$i} days")->format('Y-m-d'));
        }

        $res = $this->svc($fb)->getSubstitutes();

        $this->assertTrue($res['truncated'], 'the operator is told the list is partial');
        $this->assertNotEmpty($res['notice'] ?? '', 'and told it in words, not a flag');
        $this->assertLessThanOrEqual(200, count($res['substitutes']));
        $this->assertGreaterThan(0, count($res['substitutes']),
            'a shortened list still shows what it has');
    }
}
