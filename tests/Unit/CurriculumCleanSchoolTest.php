<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Curriculum_service.php';
require_once __DIR__ . '/CurriculumAuthorityTest.php';   // fakes

/**
 * A SCHOOL THAT HAS NEVER USED THIS SCREEN BEFORE.
 *
 * Everything here starts from a genuinely empty store — no curriculum document,
 * no topics subcollection, no counters, nothing to migrate. That is the state
 * every new tenant is in on day one, and the state most easily broken by code
 * written against a store that already has data in it.
 *
 * The second thing this file checks is that a syllabus belongs to exactly one
 * school and one session. The document id carries both, but an id scheme is a
 * convention until something proves the reads and writes actually honour it.
 */
final class CurriculumCleanSchoolTest extends TestCase
{
    private const SESS = '2026-27';
    private const CS   = 'Class 9th/Section A';
    private const SUB  = 'Mathematics';

    private function svc(FakeCurriculumFirebase $fb, string $school,
                         string $session = self::SESS): Curriculum_service
    {
        return (new Curriculum_service())
            ->init($fb, $fb, $school, $school, $session,
                   'STA_HEAD', 'A Head', 'Principal', null)
            ->withAssignmentService(new FakeAssignmentLookup());
    }

    private function topics(): array
    {
        return [
            ['title' => 'Fractions', 'chapter' => '1', 'est_periods' => 4],
            ['title' => 'Decimals',  'chapter' => '2', 'est_periods' => 3],
        ];
    }

    // -- day one -------------------------------------------------------------

    public function test_an_empty_store_reads_as_an_empty_syllabus_not_an_error(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $res = $this->svc($fb, 'SCH_NEW001')->getCurriculum(self::CS, self::SUB);

        $this->assertSame([], $res['topics']);
        $this->assertSame(0, $res['totalTopics']);
        $this->assertSame(0, $res['version'], 'the first write will be version 1');
        $this->assertFalse($res['incomplete'],
            'nothing failed to load — there was nothing to load');
    }

    public function test_the_first_syllabus_a_school_ever_writes_lands_complete(): void
    {
        $fb = new FakeCurriculumFirebase();

        $res = $this->svc($fb, 'SCH_NEW001')
            ->saveCurriculum(self::CS, self::SUB, $this->topics(), null);

        $this->assertCount(2, $res['topics']);
        $this->assertSame(1, $res['version']);

        $docId = 'SCH_NEW001_' . self::SESS . '_Class_9th_Section_A_Mathematics';
        $parent = $fb->docs['curriculum'][$docId];
        $this->assertSame('subcollection', $parent['topicsModel'],
            'a new school starts on the document-per-topic model, not the legacy array');
        $this->assertCount(2, $parent['topicIds']);
        $this->assertSame(2, $parent['totalTopics']);
        $this->assertSame(0, $parent['completedTopics']);
        $this->assertCount(2, $fb->docs['curriculum/' . $docId . '/topics']);
    }

    public function test_every_topic_a_new_school_writes_carries_its_own_school_id(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->svc($fb, 'SCH_NEW001')->saveCurriculum(self::CS, self::SUB, $this->topics(), null);

        $docId = 'SCH_NEW001_' . self::SESS . '_Class_9th_Section_A_Mathematics';
        foreach ($fb->docs['curriculum/' . $docId . '/topics'] as $t) {
            $this->assertSame('SCH_NEW001', $t['schoolId'],
                'the security rules read schoolId off the topic itself — a topic '
                . 'without it is a topic nobody can read');
            $this->assertSame($docId, $t['parentDocId']);
        }
    }

    public function test_the_whole_first_day_works_end_to_end(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $svc = fn() => $this->svc($fb, 'SCH_NEW001');

        $svc()->saveCurriculum(self::CS, self::SUB, $this->topics(), null);

        $view = $svc()->getCurriculum(self::CS, self::SUB);
        $ids  = array_column($view['topics'], 'topicId');

        $svc()->updateTopicStatus(self::CS, self::SUB, $ids[0], 0, 'completed', 1);
        $after = $svc()->getCurriculum(self::CS, self::SUB);

        $this->assertSame(1, $after['completedTopics']);
        $this->assertSame(50.0, $after['percentComplete']);

        $svc()->deleteTopic(self::CS, self::SUB, $ids[1], 1, 2);
        $end = $svc()->getCurriculum(self::CS, self::SUB);

        $this->assertCount(1, $end['topics']);
        $this->assertSame(1, $end['totalTopics']);
        $this->assertSame(100.0, $end['percentComplete'],
            'one topic, taught');
    }

    // -- one school's syllabus is not another's ------------------------------

    public function test_two_schools_writing_the_same_class_do_not_meet(): void
    {
        $fb = new FakeCurriculumFirebase();

        $this->svc($fb, 'SCH_AAA')->saveCurriculum(self::CS, self::SUB, [
            ['title' => 'A school topic', 'chapter' => '1', 'est_periods' => 1],
        ], null);
        $this->svc($fb, 'SCH_BBB')->saveCurriculum(self::CS, self::SUB, [
            ['title' => 'B school topic', 'chapter' => '1', 'est_periods' => 1],
            ['title' => 'B school extra', 'chapter' => '2', 'est_periods' => 1],
        ], null);

        $a = $this->svc($fb, 'SCH_AAA')->getCurriculum(self::CS, self::SUB);
        $b = $this->svc($fb, 'SCH_BBB')->getCurriculum(self::CS, self::SUB);

        $this->assertSame(['A school topic'], array_column($a['topics'], 'title'));
        $this->assertSame(['B school topic', 'B school extra'],
            array_column($b['topics'], 'title'));
    }

    public function test_a_new_session_starts_its_own_syllabus(): void
    {
        $fb = new FakeCurriculumFirebase();

        $this->svc($fb, 'SCH_AAA', '2025-26')->saveCurriculum(self::CS, self::SUB, [
            ['title' => 'Last year', 'chapter' => '1', 'est_periods' => 1],
        ], null);

        $now = $this->svc($fb, 'SCH_AAA', '2026-27')->getCurriculum(self::CS, self::SUB);

        $this->assertSame([], $now['topics'],
            "last year's syllabus is not this year's — the new session starts clean");
        $this->assertSame(0, $now['version']);
    }

    /** And a school cannot reach into another's syllabus by naming its topic. */
    public function test_a_topic_id_from_another_school_is_not_reachable(): void
    {
        $fb = new FakeCurriculumFirebase();

        $a = $this->svc($fb, 'SCH_AAA')->saveCurriculum(self::CS, self::SUB, [
            ['title' => 'A school topic', 'chapter' => '1', 'est_periods' => 1],
        ], null);
        $foreignId = $a['topics'][0]['topicId'];

        $this->svc($fb, 'SCH_BBB')->saveCurriculum(self::CS, self::SUB, [
            ['title' => 'B school topic', 'chapter' => '1', 'est_periods' => 1],
        ], null);

        try {
            $this->svc($fb, 'SCH_BBB')
                ->updateTopicStatus(self::CS, self::SUB, $foreignId, 0, 'completed', 1);
            $this->fail('a topic id from another school must not be addressable');
        } catch (Service_exception $e) {
            $this->addToAssertionCount(1);
        }

        $aDocId = 'SCH_AAA_' . self::SESS . '_Class_9th_Section_A_Mathematics';
        $this->assertSame('not_started',
            $fb->docs['curriculum/' . $aDocId . '/topics'][$foreignId]['status'],
            "the other school's topic is untouched");
    }
}
