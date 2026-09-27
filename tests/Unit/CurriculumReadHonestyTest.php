<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Curriculum_service.php';
require_once __DIR__ . '/CurriculumAuthorityTest.php';   // FakeCurriculumFirebase, FakeAssignmentLookup

/**
 * WHAT THE SYLLABUS SCREEN IS TOLD.
 *
 * A curriculum is a parent document holding the counters and a list of topic
 * ids, plus one document per topic in a subcollection. Reading it means one read
 * for the parent and one per topic, and every one of those can fail on its own.
 *
 * The failures matter differently here than on most screens. A teacher looks at
 * this to see what they have covered and what is left; a head of department
 * looks at the percentage to decide whether a class is behind. A topic that
 * silently drops out of the list is a lesson nobody teaches, and a percentage
 * computed over the survivors is a number that says the class is further ahead
 * than it is.
 */
final class CurriculumReadHonestyTest extends TestCase
{
    private const SCHOOL = 'SCH_CUR01';
    private const SESS   = '2026-27';
    private const CS     = 'Class 9th/Section A';
    private const SUB    = 'Mathematics';

    private function svc(FakeCurriculumFirebase $fb): Curriculum_service
    {
        return (new Curriculum_service())
            ->init($fb, $fb, self::SCHOOL, self::SCHOOL, self::SESS,
                   'STA_HEAD', 'A Head', 'Principal', null)
            ->withAssignmentService(new FakeAssignmentLookup());
    }

    private function docId(): string
    {
        return self::SCHOOL . '_' . self::SESS . '_Class_9th_Section_A_Mathematics';
    }

    /** A curriculum in the subcollection model, with three topics. */
    private function seed(FakeCurriculumFirebase $fb, int $completed = 1): void
    {
        $id = $this->docId();
        $fb->docs['curriculum'][$id] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'classSection' => 'Class 9th/Section A', 'subject' => self::SUB,
            'topicsModel' => 'subcollection',
            'topicIds' => ['T1', 'T2', 'T3'],
            'totalTopics' => 3, 'completedTopics' => $completed,
            'percentComplete' => round($completed / 3 * 1000) / 10,
            'version' => 4,
        ];
        foreach ([['T1', 'Fractions', 'completed'],
                  ['T2', 'Decimals', 'not_started'],
                  ['T3', 'Algebra', 'not_started']] as $i => [$tid, $title, $status]) {
            $fb->docs['curriculum/' . $id . '/topics'][$tid] = [
                'topicId' => $tid, 'title' => $title, 'chapter' => (string) ($i + 1),
                'estPeriods' => 4, 'status' => $status, 'sortOrder' => $i,
                'version' => 1,
            ];
        }
    }

    // -- the ordinary read --------------------------------------------------

    public function test_a_curriculum_reads_back_with_all_its_topics(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seed($fb);

        $res = $this->svc($fb)->getCurriculum(self::CS, self::SUB);

        $this->assertCount(3, $res['topics']);
        $this->assertSame('Fractions', $res['topics'][0]['title']);
        $this->assertSame('completed', $res['topics'][0]['status']);
        $this->assertSame(4, $res['version'] ?? null,
            'the version the screen must send back on its next write');
    }

    public function test_a_class_with_no_curriculum_yet_reads_as_empty(): void
    {
        $fb = new FakeCurriculumFirebase();

        $res = $this->svc($fb)->getCurriculum(self::CS, self::SUB);

        $this->assertSame([], $res['topics']);
    }

    /**
     * THE DEFECT, one level up. The syllabus document itself failed to read,
     * and the screen showed "No topics yet. Click Add Topic to start planning"
     * — an invitation to rebuild a syllabus that is sitting there intact.
     */
    public function test_a_syllabus_that_could_not_be_read_is_not_an_empty_one(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->svc($fb)->saveCurriculum(self::CS, self::SUB, [
            ['title' => 'Fractions', 'chapter' => '1', 'est_periods' => 4],
        ], null);

        $fb->failCollections = ['curriculum'];

        try {
            $this->svc($fb)->getCurriculum(self::CS, self::SUB);
            $this->fail('a syllabus that cannot be read must not read as absent');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~could not be read~i', $e->getMessage());
        }
    }

    // -- a topic that could not be read -------------------------------------

    /**
     * THE DEFECT. One topic document fails to load and is skipped, so the
     * syllabus comes back a topic short — with no indication that anything is
     * missing. The teacher sees a shorter course than the one they are meant to
     * teach, and nothing on the screen suggests otherwise.
     */
    public function test_a_topic_that_could_not_be_read_is_not_silently_dropped(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seed($fb);
        $fb->unreadableTopics = ['T2'];

        $res = $this->svc($fb)->getCurriculum(self::CS, self::SUB);

        $this->assertTrue($res['incomplete'] ?? false,
            'the screen has to be told the list it is showing is not the whole one');
        $this->assertSame(3, $res['expectedTopics'] ?? null,
            'and how many there should have been');
        $this->assertCount(2, $res['topics'],
            'the topics that DID load are still shown — a partial list beats none');
    }

    /** A complete read says so, so the screen can stay quiet in the normal case. */
    public function test_a_complete_read_is_marked_complete(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seed($fb);

        $res = $this->svc($fb)->getCurriculum(self::CS, self::SUB);

        $this->assertFalse($res['incomplete'] ?? true);
    }

    /**
     * The whole subcollection failing is the same fact, larger. It must not read
     * as "this subject has no syllabus".
     */
    public function test_a_wholly_unreadable_topic_list_says_so(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seed($fb);
        $fb->unreadableTopics = ['T1', 'T2', 'T3'];

        $res = $this->svc($fb)->getCurriculum(self::CS, self::SUB);

        $this->assertTrue($res['incomplete'] ?? false,
            'an empty list here means "we could not read them", not "there are none"');
        $this->assertSame(3, $res['expectedTopics'] ?? null);
    }

    // -- the counters -------------------------------------------------------

    /**
     * The percentage a head of department reads must never be computed from a
     * partial list. The stored counters are the school's own record and stay
     * authoritative.
     */
    public function test_the_progress_counters_survive_a_partial_read(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seed($fb, 1);
        $fb->unreadableTopics = ['T1'];          // the completed one

        $res = $this->svc($fb)->getCurriculum(self::CS, self::SUB);

        $this->assertSame(3, $res['totalTopics'] ?? null,
            'the class still has three topics however many we could read');
        $this->assertSame(1, $res['completedTopics'] ?? null,
            'and one of them is still finished');
    }
    /**
     * The same distinction on the two paths that change one topic. Both used
     * to answer a failed read with a statement about the syllabus: "Curriculum
     * not found for this class+subject", and "Topic not found in
     * subcollection".
     */
    public function test_marking_progress_on_an_unreadable_curriculum_says_so(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $ids = $this->seedTopics($fb);
        $fb->failCollections = ['curriculum'];

        try {
            $this->svc($fb)->updateTopicStatus(self::CS, self::SUB, $ids[0], 0, 'completed', 1);
            $this->fail('expected a refusal');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~could not be read~i', $e->getMessage());
            $this->assertDoesNotMatchRegularExpression('~not found~i', $e->getMessage());
        }
    }

    public function test_marking_progress_on_an_unreadable_topic_says_so(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $ids = $this->seedTopics($fb);
        $fb->unreadableTopics = [$ids[0]];

        try {
            $this->svc($fb)->updateTopicStatus(self::CS, self::SUB, $ids[0], 0, 'completed', 1);
            $this->fail('expected a refusal');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~could not be read~i', $e->getMessage());
        }

        $fb->unreadableTopics = [];
        $this->assertSame(0, $fb->docs['curriculum'][$this->docId()]['completedTopics'],
            'and the counter was not adjusted from a status nobody could read');
    }

    public function test_deleting_from_an_unreadable_curriculum_says_so(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $ids = $this->seedTopics($fb);
        $fb->failCollections = ['curriculum'];

        try {
            $this->svc($fb)->deleteTopic(self::CS, self::SUB, $ids[0], 0, 1);
            $this->fail('expected a refusal');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~could not be read~i', $e->getMessage());
        }

        $fb->failCollections = [];
        $this->assertCount(2, $fb->docs['curriculum/' . $this->docId() . '/topics'],
            'nothing was deleted');
    }

    private function seedTopics(FakeCurriculumFirebase $fb): array
    {
        $res = $this->svc($fb)->saveCurriculum(self::CS, self::SUB, [
            ['title' => 'Fractions', 'chapter' => '1', 'est_periods' => 4],
            ['title' => 'Decimals',  'chapter' => '2', 'est_periods' => 3],
        ], null);
        return array_column($res['topics'], 'topicId');
    }
}
