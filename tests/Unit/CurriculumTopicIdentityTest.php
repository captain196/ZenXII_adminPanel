<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Curriculum_service.php';
require_once __DIR__ . '/CurriculumAuthorityTest.php';   // fakes

/**
 * WHICH TOPIC IS WHICH, AFTER AN EDIT.
 *
 * Topics live one document each in a subcollection, and their ids are handed
 * out on save. The save assigned them BY POSITION — the first topic in the
 * submitted list got the first stored id, the second got the second — so
 * removing a topic shifted every later topic's content into somebody else's
 * document.
 *
 * That is not a tidiness problem. A lesson plan stores the topicId it was
 * written for, and Lesson_plan_service validates that id against the
 * curriculum. Delete the first topic of the term and every lesson plan after it
 * silently points at the wrong lesson, while its snapshotted title still reads
 * like the right one. Progress marked against a topic id moves the same way.
 *
 * The client already holds and returns each topic's id; the save was throwing
 * it away.
 *
 * Removal has since been taken away from the save entirely — a topic is
 * removed through delete_topic and a save that omits one is refused, because
 * an omitted topic is far more often a topic that failed to load than a topic
 * somebody meant to remove. So the removal cases below go through the button a
 * user actually presses.
 */
final class CurriculumTopicIdentityTest extends TestCase
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

    /** Create three topics and return their server-assigned ids, in order. */
    private function seedThree(FakeCurriculumFirebase $fb): array
    {
        $res = $this->svc($fb)->saveCurriculum(self::CS, self::SUB, [
            ['title' => 'Fractions', 'chapter' => '1', 'est_periods' => 4],
            ['title' => 'Decimals',  'chapter' => '2', 'est_periods' => 3],
            ['title' => 'Algebra',   'chapter' => '3', 'est_periods' => 5],
        ], null);

        $ids = array_column($res['topics'], 'topicId');
        $this->assertCount(3, array_filter($ids), 'every topic gets an id');
        return $ids;
    }

    /** What the client sends back: the rows it was given, minus one. */
    private function asPayload(array $topics): array
    {
        return array_map(static fn($t) => [
            'topicId'     => $t['topicId'],
            'title'       => $t['title'],
            'chapter'     => $t['chapter'],
            'est_periods' => $t['est_periods'],
            'status'      => $t['status'],
        ], $topics);
    }

    // -- identity survives an edit -----------------------------------------

    /**
     * THE DEFECT. Remove the first topic of the term. Every later topic used to
     * shift up one document, so a lesson plan written for Decimals ended up
     * attached to whatever content moved into that id.
     */
    public function test_removing_a_topic_leaves_the_others_with_their_own_ids(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $ids = $this->seedThree($fb);
        [$fractions, $decimals, $algebra] = $ids;

        $this->svc($fb)->deleteTopic(self::CS, self::SUB, $fractions, 0, 1);

        $after = $this->svc($fb)->getCurriculum(self::CS, self::SUB);
        $byId  = [];
        foreach ($after['topics'] as $t) { $byId[$t['topicId']] = $t['title']; }

        $this->assertSame('Decimals', $byId[$decimals] ?? null,
            'Decimals kept its own id — a lesson plan written for it still points '
            . 'at Decimals');
        $this->assertSame('Algebra', $byId[$algebra] ?? null);
        $this->assertArrayNotHasKey($fractions, $byId,
            'and the removed topic is gone rather than quietly reused');
    }

    /** The removed topic's document is actually deleted, not left orphaned. */
    public function test_the_removed_topic_document_is_deleted(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $ids = $this->seedThree($fb);
        $fractions = $ids[0];

        $this->svc($fb)->deleteTopic(self::CS, self::SUB, $fractions, 0, 1);

        $this->assertArrayNotHasKey($fractions, $fb->docs[$this->topicColl()],
            'a topic nobody teaches must not linger for a lesson plan to find');
        $this->assertCount(2, $fb->docs[$this->topicColl()]);
    }

    /** Reordering is not renaming: ids stay with their content. */
    public function test_reordering_topics_does_not_move_their_identities(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $ids = $this->seedThree($fb);
        [$fractions, $decimals, $algebra] = $ids;

        $res  = $this->svc($fb)->getCurriculum(self::CS, self::SUB);
        $rows = $this->asPayload($res['topics']);
        $rows = [$rows[2], $rows[0], $rows[1]];                 // Algebra first

        $after = $this->svc($fb)->saveCurriculum(self::CS, self::SUB, $rows, 1);

        $byId = [];
        foreach ($after['topics'] as $t) { $byId[$t['topicId']] = $t['title']; }

        $this->assertSame('Algebra', $byId[$algebra]);
        $this->assertSame('Fractions', $byId[$fractions]);
        $this->assertSame('Decimals', $byId[$decimals]);
    }

    /** A genuinely new topic gets a new id and does not steal an existing one. */
    public function test_a_new_topic_gets_its_own_identity(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $ids = $this->seedThree($fb);

        $res  = $this->svc($fb)->getCurriculum(self::CS, self::SUB);
        $rows = $this->asPayload($res['topics']);
        $rows[] = ['title' => 'Geometry', 'chapter' => '4', 'est_periods' => 6];

        $after = $this->svc($fb)->saveCurriculum(self::CS, self::SUB, $rows, 1);

        $newIds = array_column($after['topics'], 'topicId');
        $this->assertCount(4, array_unique($newIds), 'four topics, four identities');
        foreach ($ids as $old) {
            $this->assertContains($old, $newIds, 'and none of the originals moved');
        }
    }

    /** A topic that keeps its id keeps the record of when it was created. */
    public function test_an_edited_topic_keeps_its_creation_record(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $ids = $this->seedThree($fb);
        $first = $ids[0];
        $createdAt = $fb->docs[$this->topicColl()][$first]['createdAt'] ?? null;
        $this->assertNotNull($createdAt);

        $res  = $this->svc($fb)->getCurriculum(self::CS, self::SUB);
        $rows = $this->asPayload($res['topics']);
        $rows[0]['title'] = 'Fractions and Ratios';

        $this->svc($fb)->saveCurriculum(self::CS, self::SUB, $rows, 1);

        $this->assertSame($createdAt,
            $fb->docs[$this->topicColl()][$first]['createdAt'] ?? null,
            'editing a title is not creating a topic');
        $this->assertSame('Fractions and Ratios',
            $fb->docs[$this->topicColl()][$first]['title']);
    }

    // -- ids the client should not be able to invent ------------------------

    /**
     * The id comes back from the server, so a request carrying one this
     * curriculum has never issued is not an edit of anything. It is treated as
     * a new topic rather than written to whatever document it names.
     */
    public function test_an_unknown_topic_id_does_not_address_an_arbitrary_document(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seedThree($fb);

        $rows   = $this->asPayload($this->svc($fb)->getCurriculum(self::CS, self::SUB)['topics']);
        $rows[] = ['topicId' => 'INJECTED', 'title' => 'Smuggled', 'chapter' => 'x',
                   'est_periods' => 1];

        $res = $this->svc($fb)->saveCurriculum(self::CS, self::SUB, $rows, 1);

        $ids = array_column($res['topics'], 'topicId');
        $this->assertNotContains('INJECTED', $ids,
            'a topic id the server never issued is not an identity');
        $this->assertArrayNotHasKey('INJECTED', $fb->docs[$this->topicColl()]);
        $this->assertCount(4, $ids, 'it was stored as the new topic it really is');
    }

    /** The same id twice in one payload must not collapse two topics into one. */
    public function test_a_repeated_topic_id_does_not_collapse_two_topics(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $ids = $this->seedThree($fb);

        $rows   = $this->asPayload($this->svc($fb)->getCurriculum(self::CS, self::SUB)['topics']);
        $rows[] = ['topicId' => $ids[0], 'title' => 'A second claim on the same id',
                   'chapter' => '4', 'est_periods' => 1];

        $res = $this->svc($fb)->saveCurriculum(self::CS, self::SUB, $rows, 1);

        $out = array_column($res['topics'], 'topicId');
        $this->assertCount(4, array_unique($out),
            'four topics remain four topics whatever the request claimed');
        $this->assertSame('Fractions', $fb->docs[$this->topicColl()][$ids[0]]['title'],
            'and the original was not overwritten by the row impersonating it');
    }
}
