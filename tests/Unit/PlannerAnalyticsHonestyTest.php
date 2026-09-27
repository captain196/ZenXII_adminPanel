<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Analytics_service.php';
require_once __DIR__ . '/CurriculumAuthorityTest.php';   // FakeCurriculumFirebase

/**
 * THE NUMBERS SOMEBODY ACTS ON.
 *
 * These five reads back the oversight screens and the parent app: which
 * teachers have not planned today, which lessons were skipped, how far a class
 * has got through its syllabus, and what a child is being taught this morning.
 *
 * Every one of them answered a failed query with an empty result, and every one
 * of those empties reads as a statement about the school:
 *
 *   the counters query fails  ->  every teacher shows 0 plans saved against
 *                                 their scheduled periods, sorted worst first.
 *                                 A failed read manufactures an accusation.
 *   the timetable query fails ->  nobody is expected to have planned anything,
 *                                 so everybody looks fine. The same failure in
 *                                 the other direction.
 *   the delays query fails    ->  "no skipped lessons".
 *   the progress query fails  ->  a parent sees no progress at all.
 *   the lessons query fails   ->  a parent sees a day with no lessons.
 *
 * The tenant filters these reads apply in PHP are a different thing: dropping
 * another school's row is filtering, and must stay silent.
 */
final class PlannerAnalyticsHonestyTest extends TestCase
{
    private const SCHOOL = 'SCH_CUR01';
    private const SESS   = '2026-27';
    private const DATE   = '2026-09-07';        // a Monday

    private function svc(FakeCurriculumFirebase $fb): Analytics_service
    {
        return (new Analytics_service())->init($fb, $fb, self::SCHOOL, self::SESS);
    }

    private function seedMonitoring(FakeCurriculumFirebase $fb, string $teacherId, int $saved): void
    {
        $id = self::SCHOOL . '_' . self::SESS . '_' . self::DATE . '_' . $teacherId;
        $fb->docs['dailyTeacherMonitoring'][$id] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS, 'date' => self::DATE,
            'teacherId' => $teacherId, 'teacherName' => 'Teacher ' . $teacherId,
            'plansSaved' => $saved, 'plannedCount' => $saved,
            'completedCount' => 0, 'skippedCount' => 0, 'rescheduledCount' => 0,
        ];
    }

    private function seedTimetable(FakeCurriculumFirebase $fb, string $teacherId, int $periods): void
    {
        $id = self::SCHOOL . '_' . self::SESS . '_' . $teacherId . '_Monday';
        $rows = [];
        for ($i = 1; $i <= $periods; $i++) {
            $rows[] = ['periodNumber' => $i, 'teacherId' => $teacherId, 'subject' => 'Mathematics'];
        }
        $fb->docs['timetables'][$id] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS, 'day' => 'Monday',
            'periods' => $rows,
        ];
    }

    private function seedPlan(FakeCurriculumFirebase $fb, string $id, array $over = []): void
    {
        $fb->docs['lessonPlans'][$id] = array_merge([
            'planId' => $id, 'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'classSection' => 'Class 9th/Section A', 'subject' => 'Mathematics',
            'teacherId' => 'STA_T1', 'teacherName' => 'A Teacher',
            'date' => '2026-09-01', 'periodNumber' => 1, 'topicTitle' => 'Fractions',
            'status' => 'skipped',
        ], $over);
    }

    // ── who has not planned today ──────────────────────────────────────

    public function test_daily_monitoring_counts_plans_against_scheduled_periods(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedMonitoring($fb, 'STA_T1', 2);
        $this->seedTimetable($fb, 'STA_T1', 5);

        $res = $this->svc($fb)->getDailyMonitoring(self::DATE);

        $this->assertSame(1, count($res['rows']));
        $this->assertSame(2, $res['rows'][0]['plansSaved']);
        $this->assertSame(5, $res['rows'][0]['expectedSlots']);
        $this->assertSame(3, $res['rows'][0]['missingCount']);
    }

    /**
     * THE DEFECT. Counters unreadable, timetable fine: every teacher is
     * reported as having planned nothing, and the list is sorted to put them
     * at the top.
     */
    public function test_unreadable_counters_do_not_accuse_every_teacher(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedMonitoring($fb, 'STA_T1', 5);
        $this->seedTimetable($fb, 'STA_T1', 5);
        $fb->failCollections = ['dailyTeacherMonitoring'];

        try {
            $this->svc($fb)->getDailyMonitoring(self::DATE);
            $this->fail('a failed counter read must not be reported as nobody planning');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~could not be read|try again~i',
                $e->getMessage());
        }
    }

    /** And the mirror image: nobody is expected to plan, so everybody is fine. */
    public function test_an_unreadable_timetable_does_not_clear_everybody(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedMonitoring($fb, 'STA_T1', 0);
        $this->seedTimetable($fb, 'STA_T1', 5);
        $fb->failCollections = ['timetables'];

        try {
            $this->svc($fb)->getDailyMonitoring(self::DATE);
            $this->fail('a failed timetable read must not be reported as nothing expected');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~could not be read|try again~i',
                $e->getMessage());
        }
    }

    // ── delays ──────────────────────────────────────────────────────────

    public function test_delays_lists_skipped_and_overdue_lessons(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedPlan($fb, 'P1', ['status' => 'skipped']);
        $this->seedPlan($fb, 'P2', ['status' => 'planned', 'date' => '2020-01-01']);
        $this->seedPlan($fb, 'P3', ['status' => 'planned', 'date' => '2999-01-01']);

        $res = $this->svc($fb)->getDelays();

        $this->assertCount(1, $res['skipped']);
        $this->assertCount(1, $res['unfinished'], 'a future plan is not overdue');
    }

    /** THE DEFECT: "no delays" is exactly what a healthy school looks like. */
    public function test_an_unreadable_delay_query_is_not_an_absence_of_delays(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedPlan($fb, 'P1', ['status' => 'skipped']);
        $fb->failCollections = ['lessonPlans'];

        try {
            $this->svc($fb)->getDelays();
            $this->fail('a failed query must not be reported as nothing being wrong');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~could not be read|try again~i',
                $e->getMessage());
        }
    }

    /**
     * Two queries answer this screen — skipped lessons and overdue ones —
     * against the same collection. A mutation battery found that a guard on
     * the second was covering for the first: failing the whole collection
     * made both throw, so removing one guard changed nothing a test could
     * see. They are failed one at a time here.
     */
    public function test_an_unreadable_skipped_query_alone_is_still_reported(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedPlan($fb, 'P1', ['status' => 'skipped']);
        $fb->failConditions = [['status', 'skipped']];

        try {
            $this->svc($fb)->getDelays();
            $this->fail('the skipped-lesson query failed and nobody was told');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~skipped~i', $e->getMessage(),
                'the message names which half could not be read');
        }
    }

    public function test_an_unreadable_overdue_query_alone_is_still_reported(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedPlan($fb, 'P1', ['status' => 'skipped']);
        $fb->failConditions = [['status', 'planned']];

        try {
            $this->svc($fb)->getDelays();
            $this->fail('the overdue query failed and nobody was told');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~overdue~i', $e->getMessage());
        }
    }

    public function test_another_schools_delayed_plan_is_filtered_out_silently(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedPlan($fb, 'P1', ['status' => 'skipped']);
        $this->seedPlan($fb, 'P2', ['status' => 'skipped', 'schoolId' => 'SCH_OTHER']);

        $this->assertCount(1, $this->svc($fb)->getDelays()['skipped'],
            "another school's skipped lesson is not this school's delay");
    }

    // ── syllabus progress ───────────────────────────────────────────────

    public function test_syllabus_progress_reports_the_stored_counters(): void
    {
        $fb = new FakeCurriculumFirebase();
        $fb->docs['curriculum']['C1'] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'classSection' => 'Class 9th/Section A', 'subject' => 'Mathematics',
            'totalTopics' => 10, 'completedTopics' => 4, 'percentComplete' => 40.0,
        ];

        $rows = $this->svc($fb)->getSyllabusProgress();

        $this->assertCount(1, $rows);
        $this->assertSame(40.0, $rows[0]['percentComplete']);
    }

    public function test_an_unreadable_syllabus_is_not_a_school_with_no_curriculum(): void
    {
        $fb = new FakeCurriculumFirebase();
        $fb->docs['curriculum']['C1'] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'classSection' => 'Class 9th/Section A', 'subject' => 'Mathematics',
            'totalTopics' => 10, 'completedTopics' => 4, 'percentComplete' => 40.0,
        ];
        $fb->failCollections = ['curriculum'];

        try {
            $this->svc($fb)->getSyllabusProgress();
            $this->fail('expected a refusal');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~could not be read|try again~i',
                $e->getMessage());
        }
    }

    // ── what a parent sees ──────────────────────────────────────────────

    public function test_a_parent_sees_the_days_lessons(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedPlan($fb, 'P1', ['date' => self::DATE, 'status' => 'completed']);

        $res = $this->svc($fb)->getParentDailyLessons('Class 9th/Section A', self::DATE);

        $this->assertCount(1, $res['lessons']);
    }

    /** THE DEFECT, on a parent's phone: a day that reads as no lessons taught. */
    public function test_a_parent_is_not_shown_an_empty_day_because_a_read_failed(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedPlan($fb, 'P1', ['date' => self::DATE]);
        $fb->failCollections = ['lessonPlans'];

        try {
            $this->svc($fb)->getParentDailyLessons('Class 9th/Section A', self::DATE);
            $this->fail('expected a refusal');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~could not be read|try again~i',
                $e->getMessage());
        }
    }

    public function test_subject_progress_reports_the_stored_counters(): void
    {
        $fb = new FakeCurriculumFirebase();
        $fb->docs['subjectPlanProgress']['S1'] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'classSection' => 'Class 9th/Section A', 'subject' => 'Mathematics',
            'totalPlans' => 20, 'completedCount' => 12, 'percentComplete' => 60.0,
        ];

        $rows = $this->svc($fb)->getSubjectProgress('Class 9th/Section A');

        $this->assertCount(1, $rows);
        $this->assertSame(60.0, $rows[0]['percentComplete']);
    }

    public function test_a_parent_is_not_shown_zero_progress_because_a_read_failed(): void
    {
        $fb = new FakeCurriculumFirebase();
        $fb->docs['subjectPlanProgress']['S1'] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'classSection' => 'Class 9th/Section A', 'subject' => 'Mathematics',
            'totalPlans' => 20, 'completedCount' => 12, 'percentComplete' => 60.0,
        ];
        $fb->failCollections = ['subjectPlanProgress'];

        try {
            $this->svc($fb)->getParentSubjectProgress('Class 9th/Section A');
            $this->fail('expected a refusal');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~could not be read|try again~i',
                $e->getMessage());
        }
    }

    /** A class with genuinely nothing recorded still reads as empty. */
    public function test_a_class_with_no_plans_yet_is_genuinely_empty(): void
    {
        $fb = new FakeCurriculumFirebase();

        $this->assertSame([], $this->svc($fb)->getSubjectProgress('Class 9th/Section A'));
        $this->assertSame([], $this->svc($fb)->getDelays()['skipped']);
        $this->assertSame([],
            $this->svc($fb)->getParentDailyLessons('Class 9th/Section A', self::DATE)['lessons']);
    }
}
