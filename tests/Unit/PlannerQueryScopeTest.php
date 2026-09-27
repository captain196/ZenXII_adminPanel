<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Lesson_plan_service.php';
require_once __DIR__ . '/../../application/libraries/Analytics_service.php';
require_once __DIR__ . '/CurriculumAuthorityTest.php';   // FakeCurriculumFirebase

/**
 * WHAT THE QUERY ASKS FOR, AND WHAT IT CAN THEREFORE MISS.
 *
 * Three of these reads narrow by one field and then filter the rest in PHP:
 * the day and month views ask for a teacher's plans, and the delays screen asks
 * for every plan in the DATABASE with status 'skipped'. Both then drop the rows
 * belonging to other schools and sessions.
 *
 * Filtering after a cap is not filtering. The cap applies to the rows the
 * server returned, so in a database holding many schools the 500 most recent
 * skipped lessons can be entirely somebody else's, and this school is shown a
 * clean sheet it did not earn. The same shape truncates a teacher's year: six
 * periods a day over a full session is well past a thousand plans, and the
 * monthly totals are then computed from whichever thousand came back.
 *
 * Neither is a hypothetical. Both are what the code does with a store that has
 * more rows in it than the cap.
 */
final class PlannerQueryScopeTest extends TestCase
{
    private const SCHOOL = 'SCH_CUR01';
    private const OTHER  = 'SCH_OTHER';
    private const SESS   = '2026-27';
    private const TEACH  = 'STA_T1';

    private function plans(FakeCurriculumFirebase $fb): Lesson_plan_service
    {
        return (new Lesson_plan_service())->init(
            $fb, $fb, self::SCHOOL, self::SCHOOL, self::SESS,
            'STA_HEAD', 'A Head', 'Principal', null);
    }

    private function analytics(FakeCurriculumFirebase $fb): Analytics_service
    {
        return (new Analytics_service())->init($fb, $fb, self::SCHOOL, self::SESS);
    }

    private function seedPlan(FakeCurriculumFirebase $fb, string $id, array $over): void
    {
        $fb->docs['lessonPlans'][$id] = array_merge([
            'planId' => $id, 'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'classSection' => 'Class 9th/Section A', 'subject' => 'Mathematics',
            'teacherId' => self::TEACH, 'teacherName' => 'A Teacher',
            'date' => '2026-09-07', 'periodIndex' => 0, 'periodNumber' => 1,
            'status' => 'planned', 'notes' => '', 'version' => 1,
        ], $over);
    }

    /** A neighbouring school with a great many skipped lessons. */
    private function seedNoisyNeighbour(FakeCurriculumFirebase $fb, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->seedPlan($fb, 'OTHER_' . $i, [
                'schoolId' => self::OTHER, 'session' => self::SESS,
                'teacherId' => 'STA_X' . $i, 'status' => 'skipped',
                'date' => '2026-09-' . str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT),
            ]);
        }
    }

    // -- the delays screen ---------------------------------------------------

    /**
     * THE DEFECT. The query asks only for status == 'skipped', so a database
     * holding a larger school fills the cap before this school's row is
     * reached — and the screen says there are no delays.
     */
    public function test_a_busier_school_cannot_hide_this_schools_delays(): void
    {
        $fb = new FakeCurriculumFirebase();
        // More than the scan limit, so the cap is genuinely reached: with fewer
        // rows the PHP-side filter would tidy up after an unscoped query and
        // the test would pass against the code it exists to fail.
        $this->seedNoisyNeighbour($fb, 1100);
        $this->seedPlan($fb, 'MINE', ['status' => 'skipped', 'date' => '2026-09-02']);

        $res = $this->analytics($fb)->getDelays();

        $this->assertCount(1, $res['skipped'],
            "this school's skipped lesson must not be crowded out by another's");
        $this->assertSame('MINE', $res['skipped'][0]['planId']);
    }

    public function test_the_same_holds_for_overdue_lessons(): void
    {
        $fb = new FakeCurriculumFirebase();
        for ($i = 0; $i < 1100; $i++) {          // past the scan limit
            $this->seedPlan($fb, 'OTHER_' . $i, [
                'schoolId' => self::OTHER, 'teacherId' => 'STA_X' . $i,
                'status' => 'planned', 'date' => '2020-01-01',
            ]);
        }
        $this->seedPlan($fb, 'MINE', ['status' => 'planned', 'date' => '2020-02-02']);

        $res = $this->analytics($fb)->getDelays();

        $this->assertCount(1, $res['unfinished']);
        $this->assertSame('MINE', $res['unfinished'][0]['planId']);
    }

    /** And when this school's own delays exceed what is shown, it says so. */
    public function test_a_truncated_delay_list_says_it_is_truncated(): void
    {
        $fb = new FakeCurriculumFirebase();
        for ($i = 0; $i < 260; $i++) {
            $this->seedPlan($fb, 'MINE_' . $i, [
                'status' => 'skipped',
                'date' => '2026-09-' . str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT),
                'teacherId' => 'STA_T' . $i,
            ]);
        }

        $res = $this->analytics($fb)->getDelays();

        $this->assertCount(200, $res['skipped'], 'the list is capped for the screen');
        $this->assertTrue($res['truncated'],
            'a capped list that is presented as the delays must say it is not all of them');
        $this->assertNotSame('', $res['notice']);
    }

    public function test_an_uncapped_delay_list_is_not_marked_truncated(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedPlan($fb, 'MINE', ['status' => 'skipped']);

        $res = $this->analytics($fb)->getDelays();

        $this->assertFalse($res['truncated']);
        $this->assertSame('', $res['notice']);
    }

    /** Most recent first: the screen is read top-down by somebody in a hurry. */
    public function test_delays_come_back_newest_first(): void
    {
        $fb = new FakeCurriculumFirebase();
        foreach (['2026-09-01', '2026-09-15', '2026-09-08'] as $i => $date) {
            $this->seedPlan($fb, 'S' . $i, ['status' => 'skipped', 'date' => $date]);
        }
        foreach (['2020-01-01', '2020-03-03', '2020-02-02'] as $i => $date) {
            $this->seedPlan($fb, 'U' . $i, ['status' => 'planned', 'date' => $date]);
        }

        $res = $this->analytics($fb)->getDelays();

        $this->assertSame(['2026-09-15', '2026-09-08', '2026-09-01'],
            array_column($res['skipped'], 'date'));
        $this->assertSame(['2020-03-03', '2020-02-02', '2020-01-01'],
            array_column($res['unfinished'], 'date'));
    }

    /**
     * WHY THE ORDERING WAS DROPPED, AND WHY IT MUST STAY DROPPED.
     *
     * Firestore serves several equality filters from its single-field
     * indexes, but an equality filter combined with an orderBy on a DIFFERENT
     * field needs a composite index. firestore.indexes.json defines none for
     * lessonPlans — the Parent app already carries a FAILED_PRECONDITION
     * fallback on this very collection with the comment "Composite-index
     * missing" — so the old query (status == skipped, ordered by date) could
     * not run at all. It failed, the failure was swallowed, and the screen
     * reported no delays. Forever.
     *
     * The shape is therefore the contract: equality filters only, no
     * ordering, sorted here. Reintroducing an orderBy would put the screen
     * back on an index that does not exist.
     */
    public function test_the_planner_queries_need_no_composite_index(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedPlan($fb, 'P1', ['status' => 'skipped']);

        $this->analytics($fb)->getDelays();
        $this->plans($fb)->getDailyPlan(self::TEACH, '2026-09-07');
        $this->plans($fb)->getMonthlyPlan(self::TEACH, 2026, 9);

        $planQueries = array_filter($fb->queries,
            static fn($q) => $q['collection'] === 'lessonPlans');
        $this->assertGreaterThanOrEqual(4, count($planQueries),
            'the four reads under test all went to the store');

        foreach ($planQueries as $q) {
            $this->assertNull($q['orderBy'],
                'an orderBy on this collection needs an index nobody has created');
            foreach ($q['conditions'] as $c) {
                $this->assertSame('==', $c[1],
                    'a range filter would need a composite index too');
            }
            $fields = array_column($q['conditions'], 0);
            $this->assertContains('schoolId', $fields,
                'and every one of them is scoped to a school');
            $this->assertContains('session', $fields);
        }
    }

    // -- a teacher's day and month -------------------------------------------

    /**
     * THE DEFECT, on the monthly view: the query narrows by teacher alone, so
     * a full session of plans overruns the cap and the totals are computed
     * from whichever rows came back.
     */
    public function test_a_full_session_of_plans_does_not_truncate_a_month(): void
    {
        $fb = new FakeCurriculumFirebase();
        // A year of teaching: six periods a day across the session.
        $n = 0;
        // Eight months of it, so the session genuinely exceeds the old
        // thousand-row cap — with fewer than that the test would pass
        // against the very code it is meant to fail.
        foreach (['2026-04', '2026-05', '2026-06', '2026-07',
                  '2026-08', '2026-09', '2026-10', '2026-11'] as $month) {
            for ($d = 1; $d <= 28; $d++) {
                for ($p = 0; $p < 6; $p++) {
                    $date = $month . '-' . str_pad((string) $d, 2, '0', STR_PAD_LEFT);
                    $this->seedPlan($fb, 'P' . (++$n), [
                        'date' => $date, 'periodIndex' => $p, 'status' => 'completed',
                    ]);
                }
            }
        }
        $this->assertGreaterThan(1000, $n,
            'the store holds more than the old cap, or this proves nothing');

        $res = $this->plans($fb)->getMonthlyPlan(self::TEACH, 2026, 9);

        $this->assertSame(168, $res['totals']['total'],
            'every plan in the month is counted — 28 days of six periods');
        $this->assertSame(168, $res['totals']['completed']);
        $this->assertFalse($res['truncated'] ?? false);
    }

    /** Another school's teacher with the same id cannot crowd the day view. */
    public function test_another_schools_rows_do_not_crowd_the_day_view(): void
    {
        $fb = new FakeCurriculumFirebase();
        for ($i = 0; $i < 1200; $i++) {
            $this->seedPlan($fb, 'OTHER_' . $i, [
                'schoolId' => self::OTHER, 'date' => '2026-09-07',
                'periodIndex' => $i % 8,
            ]);
        }
        $this->seedPlan($fb, 'MINE', ['date' => '2026-09-07', 'periodIndex' => 3]);

        $day = $this->plans($fb)->getDailyPlan(self::TEACH, '2026-09-07');

        $this->assertCount(1, $day);
        $this->assertSame('MINE', $day[0]['planId']);
    }

    /** A month that really is beyond the cap must say so rather than under-count. */
    public function test_a_month_beyond_the_cap_is_reported_as_incomplete(): void
    {
        $fb = new FakeCurriculumFirebase();
        for ($i = 0; $i < 2100; $i++) {
            $this->seedPlan($fb, 'P' . $i, [
                'date' => '2026-09-' . str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT),
                'periodIndex' => $i % 10,
            ]);
        }

        $res = $this->plans($fb)->getMonthlyPlan(self::TEACH, 2026, 9);

        $this->assertTrue($res['truncated'],
            'a month built from a capped read must not present its totals as complete');
        $this->assertNotSame('', $res['notice']);
    }
}
