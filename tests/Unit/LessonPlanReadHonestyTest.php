<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Lesson_plan_service.php';
require_once __DIR__ . '/CurriculumAuthorityTest.php';   // FakeCurriculumFirebase

/**
 * A DAY WITH NO PLANS, AND A DAY THAT COULD NOT BE READ.
 *
 * The planner's three display reads all answered a failure the same way they
 * answer an absence: null, or an empty list. On this screen that is not a
 * cosmetic difference. The day view is what somebody looks at before writing a
 * plan; the monthly view is what a head of department reads to decide whether a
 * teacher is planning their lessons at all.
 *
 * An empty day says "nothing has been planned here". During an outage that
 * sentence is false, and it is false in the direction that invites somebody to
 * act — to re-enter a plan that exists, or to raise a teacher's missing
 * planning with them.
 *
 * The queries here are deliberately tolerant in one respect and must stay so:
 * they fetch by teacher and filter school, session and date in PHP, so a plan
 * belonging to another tenant is dropped silently and correctly. Filtering is
 * not failing.
 */
final class LessonPlanReadHonestyTest extends TestCase
{
    private const SCHOOL  = 'SCH_CUR01';
    private const SESS    = '2026-27';
    private const TEACHER = 'STA_T1';
    private const DATE    = '2026-09-07';        // a Monday

    private function svc(FakeCurriculumFirebase $fb, string $school = self::SCHOOL,
                         string $session = self::SESS): Lesson_plan_service
    {
        return (new Lesson_plan_service())->init(
            $fb, $fb, $school, $school, $session,
            'STA_HEAD', 'A Head', 'Principal', null);
    }

    private function seedTimetable(FakeCurriculumFirebase $fb): void
    {
        $docId = self::SCHOOL . '_' . self::SESS . '_Class 9th_Section A_Monday';
        $fb->docs['timetables'][$docId] = ['periods' => [
            ['periodNumber' => 1, 'teacherId' => self::TEACHER, 'subject' => 'Mathematics'],
            ['periodNumber' => 3, 'teacherId' => self::TEACHER, 'subject' => 'Mathematics'],
        ]];
    }

    private function seedPlan(FakeCurriculumFirebase $fb, int $periodIndex,
                              string $date = self::DATE, string $school = self::SCHOOL,
                              string $session = self::SESS): void
    {
        $id = $school . '_' . $session . '_' . self::TEACHER . '_' . $date . '_P' . $periodIndex;
        $fb->docs['lessonPlans'][$id] = [
            'planId' => $id, 'schoolId' => $school, 'session' => $session,
            'teacherId' => self::TEACHER, 'date' => $date,
            'periodIndex' => $periodIndex, 'periodNumber' => $periodIndex + 1,
            'classSection' => 'Class 9th/Section A', 'subject' => 'Mathematics',
            'status' => 'planned', 'notes' => 'Plan P' . $periodIndex, 'version' => 1,
        ];
    }

    // -- one period ---------------------------------------------------------

    public function test_a_period_with_no_plan_reads_as_no_plan(): void
    {
        $fb = new FakeCurriculumFirebase();

        $this->assertNull($this->svc($fb)->getLessonPlan(self::TEACHER, self::DATE, 2));
    }

    public function test_a_period_with_a_plan_returns_it(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedPlan($fb, 2);

        $plan = $this->svc($fb)->getLessonPlan(self::TEACHER, self::DATE, 2);
        $this->assertSame('Plan P2', $plan['notes']);
    }

    /** THE DEFECT: an unreadable plan came back as an empty period. */
    public function test_a_period_that_could_not_be_read_is_not_an_empty_period(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedPlan($fb, 2);
        $fb->failCollections = ['lessonPlans'];

        try {
            $this->svc($fb)->getLessonPlan(self::TEACHER, self::DATE, 2);
            $this->fail('a failed read must not be reported as "no plan"');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~could not be read|try again~i',
                $e->getMessage());
        }
    }

    // -- a day --------------------------------------------------------------

    public function test_a_day_returns_its_plans_in_period_order(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedPlan($fb, 2);
        $this->seedPlan($fb, 0);

        $day = $this->svc($fb)->getDailyPlan(self::TEACHER, self::DATE);

        $this->assertSame([0, 2], array_column($day, 'periodIndex'));
    }

    public function test_a_day_with_nothing_planned_is_empty(): void
    {
        $fb = new FakeCurriculumFirebase();

        $this->assertSame([], $this->svc($fb)->getDailyPlan(self::TEACHER, self::DATE));
    }

    /**
     * THE DEFECT. This is the read the day view is drawn from: a failure drew
     * an empty timetable of unplanned periods.
     */
    public function test_a_day_that_could_not_be_read_is_not_an_empty_day(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedPlan($fb, 2);
        $fb->failCollections = ['lessonPlans'];

        try {
            $this->svc($fb)->getDailyPlan(self::TEACHER, self::DATE);
            $this->fail('a failed query must not be drawn as a day with no plans');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~could not be read|try again~i',
                $e->getMessage());
        }
    }

    /** Filtering another tenant's row out is not a failure. */
    public function test_another_schools_plan_is_filtered_out_silently(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedPlan($fb, 2);
        $this->seedPlan($fb, 4, self::DATE, 'SCH_OTHER');

        $day = $this->svc($fb)->getDailyPlan(self::TEACHER, self::DATE);

        $this->assertSame([2], array_column($day, 'periodIndex'),
            "the other school's plan is not this school's business");
    }

    public function test_another_sessions_plan_is_filtered_out_silently(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedPlan($fb, 2);
        $this->seedPlan($fb, 4, self::DATE, self::SCHOOL, '2025-26');

        $this->assertSame([2],
            array_column($this->svc($fb)->getDailyPlan(self::TEACHER, self::DATE), 'periodIndex'));
    }

    // -- a month ------------------------------------------------------------

    public function test_a_month_totals_what_is_there(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedPlan($fb, 0, '2026-09-07');
        $this->seedPlan($fb, 1, '2026-09-08');
        $this->seedPlan($fb, 2, '2026-10-01');       // next month

        $res = $this->svc($fb)->getMonthlyPlan(self::TEACHER, 2026, 9);

        $this->assertSame(2, $res['totals']['total']);
        $this->assertArrayHasKey('2026-09-07', $res['days']);
        $this->assertArrayNotHasKey('2026-10-01', $res['days']);
    }

    /**
     * THE DEFECT, and the one with a person on the end of it. A failed query
     * produced a month of zeroes — which is exactly what a teacher who has
     * planned nothing looks like.
     */
    public function test_a_month_that_could_not_be_read_is_not_a_month_of_zeroes(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedPlan($fb, 0, '2026-09-07');
        $fb->failCollections = ['lessonPlans'];

        try {
            $this->svc($fb)->getMonthlyPlan(self::TEACHER, 2026, 9);
            $this->fail('a failed query must not be reported as a teacher who planned nothing');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~could not be read|try again~i',
                $e->getMessage());
        }
    }

    public function test_a_month_with_nothing_planned_is_genuinely_empty(): void
    {
        $fb = new FakeCurriculumFirebase();

        $res = $this->svc($fb)->getMonthlyPlan(self::TEACHER, 2026, 9);

        $this->assertSame(0, $res['totals']['total']);
        $this->assertSame([], $res['days']);
    }
}
