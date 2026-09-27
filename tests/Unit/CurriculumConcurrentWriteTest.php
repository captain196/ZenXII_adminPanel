<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Curriculum_service.php';
require_once __DIR__ . '/CurriculumAuthorityTest.php';   // fakes

/**
 * TWO PEOPLE EDITING ONE SYLLABUS AT THE SAME TIME.
 *
 * Every write path takes an expected_version and compares it with the stored
 * one, which is the right idea and, on its own, not a guard: the comparison
 * happens in PHP, and between reading the version and writing the batch there
 * is a window in which the other person's save lands. Both requests read
 * version 1, both find it matches, both write version 2 — and the second one
 * silently replaces work the first person was just told had been saved.
 *
 * The check has to travel WITH the write. Firestore takes a precondition on the
 * document's updateTime, so a batch built from a stale read is refused by the
 * server rather than by a comparison made a moment earlier. That is what the
 * substitute module's cover-day ledger does, and the same mechanism applies
 * here to the syllabus document every topic hangs off.
 */
final class CurriculumConcurrentWriteTest extends TestCase
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

    private function rows(array $topics): array
    {
        return array_map(static fn($t) => [
            'topicId' => $t['topicId'], 'title' => $t['title'],
            'chapter' => $t['chapter'], 'est_periods' => $t['est_periods'],
            'status'  => $t['status'],
        ], $topics);
    }

    private function seed(FakeCurriculumFirebase $fb): array
    {
        $res = $this->svc($fb)->saveCurriculum(self::CS, self::SUB, [
            ['title' => 'Fractions', 'chapter' => '1', 'est_periods' => 4],
            ['title' => 'Decimals',  'chapter' => '2', 'est_periods' => 3],
        ], null);
        return array_column($res['topics'], 'topicId');
    }

    // -- the race -----------------------------------------------------------

    /**
     * THE DEFECT. Both editors are handed version 1. The first save lands. The
     * second was built before that happened, and its version check already
     * passed — so it wrote anyway.
     */
    public function test_a_save_built_before_someone_elses_landed_is_refused(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $ids = $this->seed($fb);

        $rows = $this->rows($this->svc($fb)->getCurriculum(self::CS, self::SUB)['topics']);

        // B is mid-request: it has read version 1 and decided what to write.
        // A's save lands in that window — after B's version check passed.
        $fb->beforeCommit = function () use ($fb, $rows) {
            $a = $rows; $a[0]['title'] = 'Fractions — rewritten by A';
            $this->svc($fb)->saveCurriculum(self::CS, self::SUB, $a, 1);
        };

        $b = $rows; $b[0]['title'] = 'Fractions — rewritten by B';
        try {
            $this->svc($fb)->saveCurriculum(self::CS, self::SUB, $b, 1);
            $this->fail('a save built on a syllabus that moved underneath it must not land');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~another user|reload~i', $e->getMessage());
        }

        $this->assertSame('Fractions — rewritten by A',
            $fb->docs['curriculum/' . $this->docId() . '/topics'][$ids[0]]['title'],
            "A's work is still there — B did not write over the top of it");
        $this->assertSame(2, $fb->docs['curriculum'][$this->docId()]['version'],
            'and the version moved exactly once');
    }

    /**
     * The refusal must be all-or-nothing. A batch that is refused must not have
     * written some of its topics first.
     */
    public function test_a_refused_save_writes_nothing_at_all(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $ids = $this->seed($fb);

        $rows = $this->rows($this->svc($fb)->getCurriculum(self::CS, self::SUB)['topics']);

        $fb->beforeCommit = function () use ($fb, $rows) {
            $this->svc($fb)->saveCurriculum(self::CS, self::SUB, $rows, 1);
        };

        $mine = $rows;
        $mine[0]['title'] = 'Should not appear';
        $mine[1]['title'] = 'Nor should this';
        try {
            $this->svc($fb)->saveCurriculum(self::CS, self::SUB, $mine, 1);
            $this->fail('expected a refusal');
        } catch (Service_exception $e) {
            $this->addToAssertionCount(1);
        }

        $topics = $fb->docs['curriculum/' . $this->docId() . '/topics'];
        $this->assertSame('Fractions', $topics[$ids[0]]['title']);
        $this->assertSame('Decimals',  $topics[$ids[1]]['title']);
    }

    /** Marking progress races the same way, and is refused the same way. */
    public function test_marking_a_topic_from_a_stale_view_is_refused(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $ids = $this->seed($fb);

        $fb->beforeCommit = function () use ($fb, $ids) {
            $this->svc($fb)->updateTopicStatus(self::CS, self::SUB, $ids[0], 0, 'completed', 1);
        };

        try {
            $this->svc($fb)->updateTopicStatus(self::CS, self::SUB, $ids[1], 1, 'completed', 1);
            $this->fail('the second request was decided before the first landed');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~another user|reload~i', $e->getMessage());
        }

        $this->assertSame(1, $fb->docs['curriculum'][$this->docId()]['completedTopics'],
            'the counter moved once, for the one change that was applied');
    }

    /** So does deletion. */
    public function test_deleting_from_a_stale_view_is_refused(): void
    {
        $fb  = new FakeCurriculumFirebase();
        $ids = $this->seed($fb);

        $fb->beforeCommit = function () use ($fb, $ids) {
            $this->svc($fb)->updateTopicStatus(self::CS, self::SUB, $ids[0], 0, 'completed', 1);
        };

        try {
            $this->svc($fb)->deleteTopic(self::CS, self::SUB, $ids[1], 1, 1);
            $this->fail('a deletion decided before another change landed must not apply');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~another user|reload~i', $e->getMessage());
        }

        $this->assertArrayHasKey($ids[1],
            $fb->docs['curriculum/' . $this->docId() . '/topics'],
            'the topic somebody meant to delete from an old view is still there');
    }

    // -- what must still work -----------------------------------------------

    public function test_consecutive_saves_from_a_reloaded_view_all_land(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seed($fb);

        for ($i = 2; $i <= 5; $i++) {
            $view = $this->svc($fb)->getCurriculum(self::CS, self::SUB);
            $rows = $this->rows($view['topics']);
            $rows[0]['title'] = 'Edit number ' . $i;
            $this->svc($fb)->saveCurriculum(self::CS, self::SUB, $rows, $view['version']);
        }

        $this->assertSame(5, $fb->docs['curriculum'][$this->docId()]['version']);
        $this->assertSame('Edit number 5',
            $this->svc($fb)->getCurriculum(self::CS, self::SUB)['topics'][0]['title']);
    }

    /** The very first save has nothing to race with, and must not be refused. */
    public function test_creating_a_syllabus_needs_no_previous_version(): void
    {
        $fb = new FakeCurriculumFirebase();

        $res = $this->svc($fb)->saveCurriculum(self::CS, self::SUB, [
            ['title' => 'Fractions', 'chapter' => '1', 'est_periods' => 4],
        ], null);

        $this->assertSame(1, $res['version']);
    }

    /**
     * Two people creating the same syllabus at once: the second must not
     * quietly obliterate the first, even though neither had a version.
     */
    public function test_two_simultaneous_creations_do_not_overwrite_each_other(): void
    {
        $fb = new FakeCurriculumFirebase();

        $fb->beforeCommit = function () use ($fb) {
            $this->svc($fb)->saveCurriculum(self::CS, self::SUB, [
                ['title' => 'Written first', 'chapter' => '1', 'est_periods' => 1],
            ], null);
        };

        try {
            $this->svc($fb)->saveCurriculum(self::CS, self::SUB, [
                ['title' => 'Written second', 'chapter' => '1', 'est_periods' => 1],
            ], null);
            $this->fail('both requests believed they were creating the syllabus');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~another user|reload~i', $e->getMessage());
        }

        $titles = array_column(
            $this->svc($fb)->getCurriculum(self::CS, self::SUB)['topics'], 'title');
        $this->assertSame(['Written first'], $titles);
    }
}
