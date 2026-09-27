<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Curriculum_service.php';
require_once __DIR__ . '/CurriculumAuthorityTest.php';   // fakes

/**
 * THE NUMBER A HEAD OF DEPARTMENT READS.
 *
 * The parent document carries totalTopics, completedTopics and a percentage.
 * They are maintained by delta — each operation adjusts them rather than
 * recounting — which is the right shape, because recounting from a partial read
 * would be worse. But a delta is only as good as the fact it is derived from,
 * and each of those facts comes from a read that can fail.
 *
 * A counter that drifts does not announce itself. It stays wrong for the rest of
 * the year, it compounds with every further edit, and the number it produces is
 * the one somebody uses to decide whether a class is behind.
 */
final class CurriculumProgressCountersTest extends TestCase
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

    private function parent_(FakeCurriculumFirebase $fb): array
    {
        return $fb->docs['curriculum'][$this->docId()];
    }

    /** Three topics, the first already taught. */
    private function seed(FakeCurriculumFirebase $fb): array
    {
        $svc = $this->svc($fb);
        $res = $svc->saveCurriculum(self::CS, self::SUB, [
            ['title' => 'Fractions', 'chapter' => '1', 'est_periods' => 4, 'status' => 'completed'],
            ['title' => 'Decimals',  'chapter' => '2', 'est_periods' => 3],
            ['title' => 'Algebra',   'chapter' => '3', 'est_periods' => 5],
        ], null);
        return array_column($res['topics'], 'topicId');
    }

    // -- the ordinary arithmetic -------------------------------------------

    public function test_saving_sets_the_counters_from_what_was_saved(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seed($fb);

        $p = $this->parent_($fb);
        $this->assertSame(3, $p['totalTopics']);
        $this->assertSame(1, $p['completedTopics']);
        $this->assertSame(33.3, $p['percentComplete']);
    }

    public function test_marking_a_topic_complete_moves_the_counter_by_one(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $ids = $this->seed($fb);

        $res = $this->svc($fb)->updateTopicStatus(self::CS, self::SUB, $ids[1], 1, 'completed', 1);

        $this->assertSame(2, $res['completedTopics']);
        $this->assertSame(2, $this->parent_($fb)['completedTopics']);
    }

    public function test_un_marking_a_topic_moves_it_back(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $ids = $this->seed($fb);

        $res = $this->svc($fb)->updateTopicStatus(self::CS, self::SUB, $ids[0], 0, 'not_started', 1);

        $this->assertSame(0, $res['completedTopics']);
    }

    /** Marking something that is already complete must not double-count. */
    public function test_marking_an_already_complete_topic_changes_nothing(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $ids = $this->seed($fb);

        $res = $this->svc($fb)->updateTopicStatus(self::CS, self::SUB, $ids[0], 0, 'completed', 1);

        $this->assertSame(1, $res['completedTopics'],
            'the topic was already finished; finishing it again is not progress');
    }

    // -- deleting -----------------------------------------------------------

    public function test_deleting_an_unfinished_topic_leaves_the_completed_count(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $ids = $this->seed($fb);

        $this->svc($fb)->deleteTopic(self::CS, self::SUB, $ids[1], 1, 1);

        $p = $this->parent_($fb);
        $this->assertSame(2, $p['totalTopics']);
        $this->assertSame(1, $p['completedTopics']);
        $this->assertSame(50.0, $p['percentComplete']);
    }

    public function test_deleting_a_finished_topic_takes_it_off_both_counts(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $ids = $this->seed($fb);

        $this->svc($fb)->deleteTopic(self::CS, self::SUB, $ids[0], 0, 1);

        $p = $this->parent_($fb);
        $this->assertSame(2, $p['totalTopics']);
        $this->assertSame(0, $p['completedTopics'],
            'the finished lesson went with the topic');
    }

    /**
     * THE DEFECT. Deciding whether the topic was finished needs its document,
     * and that read was silent: a failure read as "not completed", so deleting a
     * finished topic left completedTopics one too high — permanently, and
     * compounding with every further deletion.
     */
    public function test_a_deletion_is_refused_when_the_topic_cannot_be_read(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $ids = $this->seed($fb);
        $fb->unreadableTopics = [$ids[0]];       // the completed one

        try {
            $this->svc($fb)->deleteTopic(self::CS, self::SUB, $ids[0], 0, 1);
            $this->fail('the counters cannot be adjusted from a fact we do not have');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~try again~i', $e->getMessage());
        }

        $fb->unreadableTopics = [];
        $p = $this->parent_($fb);
        $this->assertSame(3, $p['totalTopics'], 'nothing was changed');
        $this->assertSame(1, $p['completedTopics']);
    }

    /** A topic that is genuinely gone is a no-op deletion, not a failure. */
    public function test_deleting_an_already_deleted_topic_still_tidies_the_parent(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $ids = $this->seed($fb);

        // The topic document is gone, but the parent still lists its id.
        unset($fb->docs['curriculum/' . $this->docId() . '/topics'][$ids[1]]);

        $this->svc($fb)->deleteTopic(self::CS, self::SUB, $ids[1], 1, 1);

        $p = $this->parent_($fb);
        $this->assertSame(2, $p['totalTopics'],
            'the dangling id is removed from the parent');
        $this->assertSame(1, $p['completedTopics'],
            'and an absent topic was certainly not a finished one');
    }

    // -- the counters are never recomputed from a partial read --------------

    /**
     * A save recounts from the submitted list, which is complete by definition.
     * The read path must not recount from topics that merely happened to load.
     */
    public function test_reading_a_partial_curriculum_does_not_rewrite_the_counters(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $ids = $this->seed($fb);
        $fb->unreadableTopics = [$ids[0]];

        $before = $this->parent_($fb);
        $this->svc($fb)->getCurriculum(self::CS, self::SUB);
        $after  = $this->parent_($fb);

        $this->assertSame($before, $after, 'looking at a syllabus does not change it');
    }
}
