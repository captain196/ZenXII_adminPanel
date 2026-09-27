<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Curriculum_service.php';
require_once __DIR__ . '/../../application/libraries/Lesson_plan_service.php';
require_once __DIR__ . '/../../application/libraries/Analytics_service.php';
require_once __DIR__ . '/../../application/libraries/Audit_log_service.php';
require_once __DIR__ . '/../../application/libraries/Subject_assignment_service.php';
require_once __DIR__ . '/CurriculumAuthorityTest.php';   // fakes

/**
 * THE WHOLE TRAIL, WALKED ONCE.
 *
 * Every other test in this module examines one joint. This one walks the
 * journey a school actually takes, with the real services wired to one store:
 *
 *   subject assignment → curriculum topic → timetable period → lesson plan
 *   → concurrent edit → audit history → analytics counters → what a parent
 *   reads → and what happens when the syllabus moves underneath it.
 *
 * It exists because each joint can be correct while the chain is not: the
 * identifiers have to agree across five services, and the only way to know
 * they do is to carry one lesson from one end to the other.
 */
final class LessonPlannerWorkflowTest extends TestCase
{
    private const SCHOOL  = 'SCH_WF01';
    private const OTHER   = 'SCH_WF02';
    private const SESS    = '2026-27';
    private const CLS     = 'Class 9th';
    private const SEC     = 'Section A';
    private const CS      = 'Class 9th/Section A';
    private const SUB     = 'Mathematics';
    private const TEACHER = 'STA_T1';
    private const DATE    = '2026-09-07';        // a Monday
    private const PERIOD  = 2;

    private FakeCurriculumFirebase $fb;

    protected function setUp(): void
    {
        $this->fb = new FakeCurriculumFirebase();
    }

    // ── the services, all pointed at the one store ──────────────────────

    private function audit(string $uid, string $name, string $role): Audit_log_service
    {
        return (new Audit_log_service())->init($this->fb, self::SCHOOL, self::SESS,
            ['uid' => $uid, 'name' => $name, 'role' => $role]);
    }

    private function assignments(string $school = self::SCHOOL): Subject_assignment_service
    {
        return (new Subject_assignment_service())
            ->init($this->fb, $this->fb, $school, $school, self::SESS);
    }

    private function curriculum(string $uid = 'STA_HEAD', string $role = 'Principal'): Curriculum_service
    {
        return (new Curriculum_service())
            ->init($this->fb, $this->fb, self::SCHOOL, self::SCHOOL, self::SESS,
                   $uid, 'The Actor', $role, $this->audit($uid, 'The Actor', $role))
            ->withAssignmentService($this->assignments());
    }

    private function planner(string $uid, string $name, string $role,
                             string $school = self::SCHOOL): Lesson_plan_service
    {
        return (new Lesson_plan_service())
            ->init($this->fb, $this->fb, $school, $school, self::SESS,
                   $uid, $name, $role, $this->audit($uid, $name, $role))
            ->withAssignmentService($this->assignments($school));
    }

    private function analytics(string $school = self::SCHOOL): Analytics_service
    {
        return (new Analytics_service())->init($this->fb, $this->fb, $school, self::SESS);
    }

    // ── the school's starting state ─────────────────────────────────────

    /** Stage 1: the teacher is assigned this subject in this section. */
    private function assignTeacher(string $school = self::SCHOOL): void
    {
        $id = $school . '_' . self::SESS . '_Class_9th_Section_A_MATH';
        $this->fb->docs['subjectAssignments'][$id] = [
            'schoolId' => $school, 'session' => self::SESS,
            'className' => self::CLS, 'section' => self::SEC,
            'subjectName' => self::SUB, 'subjectCode' => 'MATH',
            'teacherId' => self::TEACHER, 'teacherName' => 'A Teacher',
            'archived' => false,
        ];
    }

    /** Stage 3: the published timetable gives them this period. */
    private function publishTimetable(string $school = self::SCHOOL): void
    {
        $docId = $school . '_' . self::SESS . '_Class 9th_Section A_Monday';
        $this->fb->docs['timetables'][$docId] = [
            'schoolId' => $school, 'session' => self::SESS, 'day' => 'Monday',
            'periods' => [
                ['periodNumber' => 3, 'teacherId' => self::TEACHER,
                 'subject' => self::SUB, 'teacher' => 'A Teacher'],
            ],
        ];
    }

    private function plan(array $over = []): array
    {
        return array_merge([
            'class_name' => self::CLS, 'section_name' => self::SEC,
            'subject' => self::SUB, 'teacher_id' => self::TEACHER,
            'date' => self::DATE, 'period_index' => self::PERIOD,
            'topic_id' => '', 'notes' => 'Introduce fractions',
            'status' => 'planned', 'expected_version' => null,
        ], $over);
    }

    private function planDocId(string $school = self::SCHOOL): string
    {
        return $school . '_' . self::SESS . '_' . self::TEACHER . '_'
             . self::DATE . '_P' . self::PERIOD;
    }

    // ── the journey ─────────────────────────────────────────────────────

    public function test_a_lesson_travels_the_whole_trail(): void
    {
        // 1. assignment
        $this->assignTeacher();

        // 2. the syllabus, written by the head of department
        $saved = $this->curriculum()->saveCurriculum(self::CS, self::SUB, [
            ['title' => 'Fractions', 'chapter' => '1', 'est_periods' => 4],
            ['title' => 'Decimals',  'chapter' => '2', 'est_periods' => 3],
        ], null);
        $topicId = $saved['topics'][0]['topicId'];
        $this->assertNotSame('', $topicId);

        // 3. the published timetable
        $this->publishTimetable();

        // 4. the teacher plans their own period against a real topic
        $this->planner(self::TEACHER, 'A Teacher', 'Teacher')
            ->saveLessonPlan($this->plan(['topic_id' => $topicId]));

        $stored = $this->fb->docs['lessonPlans'][$this->planDocId()];
        $this->assertSame($topicId, $stored['topicId']);
        $this->assertSame('Fractions', $stored['topicTitle'],
            'the title is taken from the topic, not from the request');
        $this->assertSame(1, $stored['version']);
        $this->assertSame(self::TEACHER, $stored['teacherId']);

        // 5. the office marks it taught — a legitimate second writer
        $this->planner('STA_HEAD', 'A Head', 'Principal')
            ->saveLessonPlan($this->plan([
                'topic_id' => $topicId, 'status' => 'completed', 'expected_version' => 1,
            ]));
        $this->assertSame('completed',
            $this->fb->docs['lessonPlans'][$this->planDocId()]['status']);

        // 6. a third save built on the version they had already been given is
        //    refused at the write, not merely compared in PHP
        $landed = false;
        $this->fb->beforeCommit = function () use ($topicId, &$landed) {
            $this->planner('STA_HEAD', 'A Head', 'Principal')
                ->saveLessonPlan($this->plan([
                    'topic_id' => $topicId, 'notes' => 'Head got there first',
                    'status' => 'completed', 'expected_version' => 2,
                ]));
            $landed = true;
        };
        $refused = null;
        try {
            $this->planner(self::TEACHER, 'A Teacher', 'Teacher')
                ->saveLessonPlan($this->plan([
                    'topic_id' => $topicId, 'notes' => 'Teacher rewrite',
                    'status' => 'completed', 'expected_version' => 2,
                ]));
        } catch (Service_exception $e) { $refused = $e->getMessage(); }

        $this->assertTrue($landed, 'the racing write actually landed in the window');
        $this->assertNotNull($refused, 'the stale save was allowed through');
        $this->assertSame('Head got there first',
            $this->fb->docs['lessonPlans'][$this->planDocId()]['notes']);

        // 7. the history answers who did what, from which client
        $history = $this->planner('STA_HEAD', 'A Head', 'Principal')
            ->planHistory(self::TEACHER, self::DATE, self::PERIOD);
        $this->assertCount(3, $history['entries'],
            'the create and both edits are all recorded');
        $actions = array_column($history['entries'], 'action');
        $this->assertContains('create', $actions);
        $this->assertContains('update', $actions);
        foreach ($history['entries'] as $e) {
            $this->assertNotSame('', $e['by']);
            $this->assertSame('Admin panel', $e['source']);
        }

        // 8. the counters the school and the parents read
        $progress = $this->analytics()->getSubjectProgress(self::CS);
        $this->assertCount(1, $progress);
        $this->assertSame(1, $progress[0]['totalPlans']);
        $this->assertSame(1, $progress[0]['completedCount'],
            'the lesson the office marked taught is counted as taught');
        $this->assertSame(100.0, $progress[0]['percentComplete']);

        $monitoring = $this->analytics()->getDailyMonitoring(self::DATE);
        $this->assertSame(1, $monitoring['rows'][0]['plansSaved']);
        $this->assertSame(1, $monitoring['rows'][0]['expectedSlots'],
            'one scheduled period, one plan — nothing missing');
        $this->assertSame(0, $monitoring['rows'][0]['missingCount']);

        // 9. what a parent is shown for that day
        $parentDay = $this->analytics()->getParentDailyLessons(self::CS, self::DATE);
        $this->assertCount(1, $parentDay['lessons']);
        $this->assertSame('Fractions', $parentDay['lessons'][0]['topicTitle']);

        // 10. the day and month a teacher sees
        $day = $this->planner(self::TEACHER, 'A Teacher', 'Teacher')
            ->getDailyPlan(self::TEACHER, self::DATE);
        $this->assertCount(1, $day);
        $month = $this->planner(self::TEACHER, 'A Teacher', 'Teacher')
            ->getMonthlyPlan(self::TEACHER, 2026, 9);
        $this->assertSame(1, $month['totals']['total']);
        $this->assertFalse($month['truncated']);
    }

    /**
     * The syllabus moves underneath a lesson that has already been taught.
     * The record of the teaching must survive it, and nothing may be quietly
     * reinterpreted into a different lesson.
     */
    public function test_the_syllabus_moving_does_not_rewrite_a_taught_lesson(): void
    {
        $this->assignTeacher();
        $this->publishTimetable();

        $saved = $this->curriculum()->saveCurriculum(self::CS, self::SUB, [
            ['title' => 'Fractions', 'chapter' => '1', 'est_periods' => 4],
            ['title' => 'Decimals',  'chapter' => '2', 'est_periods' => 3],
        ], null);
        [$fractions, $decimals] = array_column($saved['topics'], 'topicId');

        $this->planner(self::TEACHER, 'A Teacher', 'Teacher')
            ->saveLessonPlan($this->plan(['topic_id' => $fractions, 'status' => 'completed']));

        // The head of department removes the topic that lesson was taught from.
        $this->curriculum()->deleteTopic(self::CS, self::SUB, $fractions, 0, 1);

        // The plan is untouched: it still says what was taught, under the name
        // it was taught by.
        $stored = $this->fb->docs['lessonPlans'][$this->planDocId()];
        $this->assertSame($fractions, $stored['topicId']);
        $this->assertSame('Fractions', $stored['topicTitle'],
            'the record of a taught lesson is not rewritten by a later edit '
            . 'to the syllabus');
        $this->assertSame('completed', $stored['status']);

        // The surviving topic kept its own identity — the plan cannot have been
        // silently re-pointed at it.
        $after = $this->curriculum()->getCurriculum(self::CS, self::SUB);
        $this->assertSame([$decimals], array_column($after['topics'], 'topicId'));

        // And editing that plan now is refused with the reason and a next step,
        // rather than saved against a topic that no longer exists.
        try {
            $this->planner(self::TEACHER, 'A Teacher', 'Teacher')
                ->saveLessonPlan($this->plan([
                    'topic_id' => $fractions, 'notes' => 'a later edit',
                    'expected_version' => 1,
                ]));
            $this->fail('a plan may not be saved against a topic that is gone');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~no longer in the curriculum~i',
                $e->getMessage());
            $this->assertMatchesRegularExpression('~choose another topic~i',
                $e->getMessage());
        }
    }

    /** A teacher who loses the assignment can no longer plan it; the record stays. */
    public function test_losing_the_assignment_stops_new_planning_not_the_record(): void
    {
        $this->assignTeacher();
        $this->publishTimetable();
        $this->planner(self::TEACHER, 'A Teacher', 'Teacher')->saveLessonPlan($this->plan());

        // The assignment is archived — the admin deactivation cascade's shape.
        $id = self::SCHOOL . '_' . self::SESS . '_Class_9th_Section_A_MATH';
        $this->fb->docs['subjectAssignments'][$id]['archived'] = true;

        try {
            $this->planner(self::TEACHER, 'A Teacher', 'Teacher')
                ->saveLessonPlan($this->plan(['notes' => 'after losing it',
                                              'expected_version' => 1]));
            $this->fail('an archived assignment is not an assignment');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~not authorized~i', $e->getMessage());
        }

        $this->assertSame('Introduce fractions',
            $this->fb->docs['lessonPlans'][$this->planDocId()]['notes'],
            'what was already taught is still recorded');

        // The office can still work on it.
        $this->planner('STA_HEAD', 'A Head', 'Principal')
            ->saveLessonPlan($this->plan(['notes' => 'corrected by the office',
                                          'expected_version' => 1]));
        $this->assertSame('corrected by the office',
            $this->fb->docs['lessonPlans'][$this->planDocId()]['notes']);
    }

    /** Nothing in the trail leaks across schools. */
    public function test_the_whole_trail_is_tenant_scoped(): void
    {
        $this->assignTeacher();
        $this->publishTimetable();
        $this->assignTeacher(self::OTHER);
        $this->publishTimetable(self::OTHER);

        $this->planner(self::TEACHER, 'A Teacher', 'Teacher')->saveLessonPlan($this->plan());
        $this->planner(self::TEACHER, 'A Teacher', 'Teacher', self::OTHER)
            ->saveLessonPlan($this->plan(['notes' => 'the other school']));

        // Each school sees exactly one plan: its own.
        $mine   = $this->planner('STA_HEAD', 'A Head', 'Principal')
            ->getDailyPlan(self::TEACHER, self::DATE);
        $theirs = $this->planner('STA_HEAD', 'A Head', 'Principal', self::OTHER)
            ->getDailyPlan(self::TEACHER, self::DATE);

        $this->assertCount(1, $mine);
        $this->assertCount(1, $theirs);
        $this->assertSame('Introduce fractions', $mine[0]['notes']);
        $this->assertSame('the other school', $theirs[0]['notes']);

        // And neither school's history contains the other's entries.
        $h = $this->planner('STA_HEAD', 'A Head', 'Principal')
            ->planHistory(self::TEACHER, self::DATE, self::PERIOD);
        $this->assertCount(1, $h['entries']);
    }
}
