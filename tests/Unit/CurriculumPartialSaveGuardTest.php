<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Curriculum_service.php';
require_once __DIR__ . '/CurriculumAuthorityTest.php';   // fakes

/**
 * SAVING A SYLLABUS YOU WERE ONLY SHOWN PART OF.
 *
 * saveCurriculum takes the whole list and makes the stored syllabus match it, so
 * a topic absent from the request is a topic deleted. That is safe only if the
 * screen was holding every topic when the request was built — and it is not: a
 * topic document that fails to load is simply missing from the list the screen
 * holds. Press "Add Topic" after a partial load and the save quietly removes
 * every topic that did not load, along with the lesson plans' only link to them.
 *
 * The screen now refuses to save an incomplete list, but the screen is not the
 * boundary. And there is a better answer than guessing at the caller's
 * knowledge: in this product a topic is never removed by saving a shorter list.
 * Deletion has its own endpoint, its own confirmation and its own audit entry.
 * So an omitted topic is not a deletion — it is a list that was built without
 * it, and the save is refused.
 */
final class CurriculumPartialSaveGuardTest extends TestCase
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

    private function topicColl(): string
    {
        return 'curriculum/' . $this->docId() . '/topics';
    }

    /** A term's syllabus: three topics. */
    private function seed(FakeCurriculumFirebase $fb): array
    {
        $res = $this->svc($fb)->saveCurriculum(self::CS, self::SUB, [
            ['title' => 'Fractions', 'chapter' => '1', 'est_periods' => 4],
            ['title' => 'Decimals',  'chapter' => '2', 'est_periods' => 3],
            ['title' => 'Algebra',   'chapter' => '3', 'est_periods' => 5],
        ], null);
        return array_column($res['topics'], 'topicId');
    }

    private function asPayload(array $topics): array
    {
        return array_map(static fn($t) => [
            'topicId' => $t['topicId'], 'title' => $t['title'],
            'chapter' => $t['chapter'], 'est_periods' => $t['est_periods'],
            'status'  => $t['status'],
        ], $topics);
    }

    // -- the accident this prevents -----------------------------------------

    /**
     * One topic document will not load; the teacher adds a new one, which saves
     * the list the screen is holding. The unread topic must survive.
     */
    public function test_a_save_built_from_a_partial_list_removes_nothing(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $ids = $this->seed($fb);

        $fb->unreadableTopics = [$ids[1]];                 // Decimals will not load
        $view = $this->svc($fb)->getCurriculum(self::CS, self::SUB);
        $this->assertTrue($view['incomplete'], 'the read reported itself incomplete');
        $this->assertCount(2, $view['topics']);

        $rows   = $this->asPayload($view['topics']);
        $rows[] = ['title' => 'Geometry', 'chapter' => '4', 'est_periods' => 6];

        try {
            $this->svc($fb)->saveCurriculum(self::CS, self::SUB, $rows, 1);
            $this->fail('a list missing a stored topic must not be written as the truth');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~reload~i', $e->getMessage());
        }

        $fb->unreadableTopics = [];
        $this->assertArrayHasKey($ids[1], $fb->docs[$this->topicColl()],
            'the topic that would not load is still in the syllabus');
        $this->assertCount(3, $fb->docs[$this->topicColl()],
            'and nothing was added on the back of a list that was not whole');
    }

    /** The message has to name the real problem, not the topic ids. */
    public function test_the_refusal_explains_itself(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $ids = $this->seed($fb);

        $view = $this->svc($fb)->getCurriculum(self::CS, self::SUB);
        $rows = $this->asPayload(array_slice($view['topics'], 1));

        try {
            $this->svc($fb)->saveCurriculum(self::CS, self::SUB, $rows, 1);
            $this->fail('expected a refusal');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~1 topic~i', $e->getMessage(),
                'it says how many topics the request left out');
            $this->assertMatchesRegularExpression('~delete~i', $e->getMessage(),
                'and where deletion actually lives');
        }
    }

    // -- what still works ---------------------------------------------------

    public function test_editing_every_topic_in_place_is_fine(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $ids = $this->seed($fb);

        $view = $this->svc($fb)->getCurriculum(self::CS, self::SUB);
        $rows = $this->asPayload($view['topics']);
        $rows[0]['title'] = 'Fractions and Ratios';

        $after = $this->svc($fb)->saveCurriculum(self::CS, self::SUB, $rows, 1);

        $this->assertCount(3, $after['topics']);
        $this->assertSame('Fractions and Ratios',
            $fb->docs[$this->topicColl()][$ids[0]]['title']);
    }

    public function test_adding_a_topic_to_a_complete_list_is_fine(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seed($fb);

        $view   = $this->svc($fb)->getCurriculum(self::CS, self::SUB);
        $rows   = $this->asPayload($view['topics']);
        $rows[] = ['title' => 'Geometry', 'chapter' => '4', 'est_periods' => 6];

        $after = $this->svc($fb)->saveCurriculum(self::CS, self::SUB, $rows, 1);

        $this->assertCount(4, $after['topics']);
        $this->assertSame(4, $fb->docs['curriculum'][$this->docId()]['totalTopics']);
    }

    public function test_reordering_is_still_fine(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $ids = $this->seed($fb);

        $view = $this->svc($fb)->getCurriculum(self::CS, self::SUB);
        $rows = $this->asPayload($view['topics']);
        $rows = [$rows[2], $rows[0], $rows[1]];

        $after = $this->svc($fb)->saveCurriculum(self::CS, self::SUB, $rows, 1);

        $this->assertSame([$ids[2], $ids[0], $ids[1]],
            array_column($after['topics'], 'topicId'));
    }

    /** The first save of a brand-new syllabus omits nothing, by definition. */
    public function test_the_very_first_save_is_not_an_omission(): void
    {
        $fb = new FakeCurriculumFirebase();

        $res = $this->svc($fb)->saveCurriculum(self::CS, self::SUB, [
            ['title' => 'Fractions', 'chapter' => '1', 'est_periods' => 4],
        ], null);

        $this->assertCount(1, $res['topics']);
    }

    /**
     * A parent that lists a topic whose document is already gone must not wedge
     * the syllabus shut: deleting it is how the list is tidied, and that path
     * still works.
     */
    public function test_a_dangling_id_can_still_be_cleared_through_deletion(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $ids = $this->seed($fb);
        unset($fb->docs[$this->topicColl()][$ids[1]]);   // document gone, id remains

        $this->svc($fb)->deleteTopic(self::CS, self::SUB, $ids[1], 1, 1);

        $view = $this->svc($fb)->getCurriculum(self::CS, self::SUB);
        $rows = $this->asPayload($view['topics']);
        $rows[0]['title'] = 'Edited after the tidy-up';

        $after = $this->svc($fb)->saveCurriculum(self::CS, self::SUB, $rows, 2);
        $this->assertCount(2, $after['topics'], 'the syllabus is editable again');
    }
}
