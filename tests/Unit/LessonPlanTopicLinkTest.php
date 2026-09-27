<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Lesson_plan_service.php';
require_once __DIR__ . '/CurriculumAuthorityTest.php';   // FakeCurriculumFirebase

/**
 * WHAT A LESSON PLAN SAYS WHEN THE THINGS IT DEPENDS ON CANNOT BE READ.
 *
 * A plan is validated against three documents it does not own: the timetable
 * cell it occupies, the curriculum topic it names, and any plan already stored
 * in that slot. All three were read silently, so a failure came back looking
 * exactly like an absence — and each absence has a confident message attached
 * to it.
 *
 *   the timetable read fails  ->  "No timetable period found. Set the timetable
 *                                  cell first."
 *   the topic read fails      ->  "Invalid topic_id: topic not found in
 *                                  curriculum."
 *   the existing-plan read fails -> the slot is treated as empty and the stored
 *                                  plan is overwritten from version 1.
 *
 * The first two send a teacher to repair data that was never broken. The third
 * loses somebody's work. This is the same class of defect the curriculum read
 * path carried, arriving here through the documents curriculum owns.
 */
final class LessonPlanTopicLinkTest extends TestCase
{
    private const SCHOOL  = 'SCH_CUR01';
    private const SESS    = '2026-27';
    private const CLS     = 'Class 9th';
    private const SEC     = 'Section A';
    private const SUB     = 'Mathematics';
    private const TEACHER = 'STA_T1';
    private const DATE    = '2026-09-07';        // a Monday
    private const PERIOD  = 2;                   // 0-based; periodNumber 3

    private function svc(FakeCurriculumFirebase $fb): Lesson_plan_service
    {
        return (new Lesson_plan_service())->init(
            $fb, $fb, self::SCHOOL, self::SCHOOL, self::SESS,
            'STA_HEAD', 'A Head', 'Principal', null);
    }

    private function currDocId(): string
    {
        return self::SCHOOL . '_' . self::SESS . '_Class_9th_Section_A_Mathematics';
    }

    private function topicColl(): string
    {
        return 'curriculum/' . $this->currDocId() . '/topics';
    }

    private function planDocId(): string
    {
        return self::SCHOOL . '_' . self::SESS . '_' . self::TEACHER . '_'
             . self::DATE . '_P' . self::PERIOD;
    }

    /** The timetable cell the plan occupies — present unless a test says not. */
    private function seedTimetable(FakeCurriculumFirebase $fb): void
    {
        $docId = self::SCHOOL . '_' . self::SESS . '_Class 9th_Section A_Monday';
        $fb->docs['timetables'][$docId] = ['periods' => [
            ['periodNumber' => 3, 'teacherId' => self::TEACHER,
             'subject' => self::SUB, 'teacher' => 'A Teacher'],
        ]];
    }

    private function seedTopic(FakeCurriculumFirebase $fb, string $topicId): void
    {
        $fb->docs[$this->topicColl()][$topicId] = [
            'topicId' => $topicId, 'parentDocId' => $this->currDocId(),
            'schoolId' => self::SCHOOL, 'title' => 'Fractions',
            'status' => 'not_started', 'sortOrder' => 0,
        ];
    }

    private function plan(string $topicId = '', $expectedVersion = null): array
    {
        return [
            'class_name' => self::CLS, 'section_name' => self::SEC,
            'subject' => self::SUB, 'teacher_id' => self::TEACHER,
            'date' => self::DATE, 'period_index' => self::PERIOD,
            'topic_id' => $topicId, 'notes' => 'Introduce fractions',
            'status' => 'planned', 'expected_version' => $expectedVersion,
        ];
    }

    // -- the ordinary cases -------------------------------------------------

    public function test_a_plan_naming_a_real_topic_saves_and_snapshots_its_title(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        $this->seedTopic($fb, 'T1');

        $this->svc($fb)->saveLessonPlan($this->plan('T1'));

        $stored = $fb->docs['lessonPlans'][$this->planDocId()];
        $this->assertSame('T1', $stored['topicId']);
        $this->assertSame('Fractions', $stored['topicTitle'],
            'the title is denormalised so later reads do not need the topic');
    }

    public function test_a_plan_with_no_topic_is_perfectly_valid(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);

        $this->svc($fb)->saveLessonPlan($this->plan(''));

        $this->assertArrayHasKey($this->planDocId(), $fb->docs['lessonPlans']);
    }

    public function test_a_topic_belonging_to_another_curriculum_is_refused(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        $fb->docs[$this->topicColl()]['T1'] = [
            'topicId' => 'T1', 'parentDocId' => 'SOME_OTHER_CURRICULUM',
            'schoolId' => self::SCHOOL, 'title' => 'Borrowed',
        ];

        try {
            $this->svc($fb)->saveLessonPlan($this->plan('T1'));
            $this->fail('a topic must belong to the class and subject it is planned for');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~does not belong~i', $e->getMessage());
        }
    }

    // -- the topic lookup that could not run --------------------------------

    /**
     * THE DEFECT. A failed read told the teacher their topic does not exist —
     * a statement about the syllabus, made at the one moment the syllabus
     * cannot be seen at all.
     */
    public function test_an_unreadable_topic_is_not_a_missing_topic(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        $this->seedTopic($fb, 'T1');
        $fb->unreadableTopics = ['T1'];

        try {
            $this->svc($fb)->saveLessonPlan($this->plan('T1'));
            $this->fail('an unreadable topic must not be accepted or denied blindly');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~could not be read|try again~i',
                $e->getMessage());
            $this->assertDoesNotMatchRegularExpression('~not found in curriculum~i',
                $e->getMessage(),
                'the teacher is told what happened, not something untrue about '
                . 'their syllabus');
        }

        $this->assertSame([], $fb->docs['lessonPlans'] ?? [],
            'and nothing was written against a topic nobody could read');
    }

    /**
     * The plan was written for a topic since removed from the syllabus. The
     * form still shows it selected, so the save has to say what to do about it
     * rather than only that the id is invalid.
     */
    public function test_a_removed_topic_tells_the_teacher_how_to_proceed(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        // No topic seeded: it was deleted from the curriculum.

        try {
            $this->svc($fb)->saveLessonPlan($this->plan('T_GONE'));
            $this->fail('a plan may not point at a topic that is no longer taught');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~no longer in the curriculum~i',
                $e->getMessage());
            $this->assertMatchesRegularExpression('~choose another topic~i',
                $e->getMessage(),
                'the teacher needs the next step, not just the diagnosis');
        }
    }

    /** Clearing the topic is the way out, and it works. */
    public function test_clearing_the_topic_lets_the_plan_be_saved(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);

        $this->svc($fb)->saveLessonPlan($this->plan(''));

        $this->assertSame('', $fb->docs['lessonPlans'][$this->planDocId()]['topicId']);
    }

    // -- the timetable that could not be read -------------------------------

    public function test_a_genuinely_empty_slot_still_says_set_the_timetable(): void
    {
        $fb = new FakeCurriculumFirebase();   // no timetable document at all

        try {
            $this->svc($fb)->saveLessonPlan($this->plan(''));
            $this->fail('a plan needs a period to sit in');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~set the timetable~i', $e->getMessage());
        }
    }

    /**
     * THE DEFECT. Same message, but the timetable is fine — it simply could not
     * be fetched. A teacher sent to "set the timetable cell first" during an
     * outage either edits a timetable that needs no editing, or concludes the
     * planner is lying to them.
     */
    public function test_an_unreadable_timetable_is_not_an_empty_timetable(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        $fb->failCollections = ['timetables'];

        try {
            $this->svc($fb)->saveLessonPlan($this->plan(''));
            $this->fail('an unreadable timetable must not be reported as an unset one');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~could not be read|try again~i',
                $e->getMessage());
            $this->assertDoesNotMatchRegularExpression('~set the timetable~i',
                $e->getMessage(),
                'nobody should be sent to repair data that is not broken');
        }
    }

    // -- the plan already in the slot ---------------------------------------

    /**
     * THE DEFECT, and the one that costs work. The existing plan is read to
     * establish the version an edit is racing against. That read was silent, so
     * a failure looked like an empty slot: the concurrency check compared
     * against version 0, the save was treated as a creation, and a colleague's
     * plan was overwritten wholesale — notes, status and all.
     */
    public function test_an_unreadable_existing_plan_does_not_become_a_fresh_one(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        $fb->docs['lessonPlans'][$this->planDocId()] = [
            'planId' => $this->planDocId(), 'version' => 4,
            'notes' => 'Six weeks of work', 'status' => 'completed',
            'teacherId' => self::TEACHER,
        ];
        $fb->failCollections = ['lessonPlans'];

        try {
            $this->svc($fb)->saveLessonPlan($this->plan(''));
            $this->fail('a plan that cannot be read cannot be safely replaced');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~could not be read|try again~i',
                $e->getMessage());
        }

        $fb->failCollections = [];
        $stored = $fb->docs['lessonPlans'][$this->planDocId()];
        $this->assertSame('Six weeks of work', $stored['notes'],
            'the plan that was already there is still there');
        $this->assertSame(4, $stored['version']);
    }

    /** A slot that is genuinely empty is still a creation. */
    public function test_an_empty_slot_is_created_at_version_one(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);

        $this->svc($fb)->saveLessonPlan($this->plan(''));

        $stored = $fb->docs['lessonPlans'][$this->planDocId()];
        $this->assertSame(1, $stored['version']);
        $this->assertNotEmpty($stored['createdAt']);
    }

    /** And a genuine edit still advances the version it was given. */
    public function test_an_edit_advances_the_stored_version(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        $fb->docs['lessonPlans'][$this->planDocId()] = [
            'planId' => $this->planDocId(), 'version' => 4,
            'notes' => 'Earlier', 'status' => 'planned', 'teacherId' => self::TEACHER,
        ];

        $this->svc($fb)->saveLessonPlan($this->plan('', 4));

        $this->assertSame(5, $fb->docs['lessonPlans'][$this->planDocId()]['version']);
    }

    public function test_a_stale_version_is_still_refused_as_a_conflict(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedTimetable($fb);
        $fb->docs['lessonPlans'][$this->planDocId()] = [
            'planId' => $this->planDocId(), 'version' => 4, 'teacherId' => self::TEACHER,
        ];

        try {
            $this->svc($fb)->saveLessonPlan($this->plan('', 2));
            $this->fail('an edit built on v2 must not land on v4');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~modified by another user~i',
                $e->getMessage());
        }
    }
}
