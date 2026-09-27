<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Curriculum_service.php';
require_once __DIR__ . '/../../application/libraries/Audit_log_service.php';
require_once __DIR__ . '/CurriculumAuthorityTest.php';   // fakes

/**
 * WHAT THE SYLLABUS REMEMBERS ABOUT WHO CHANGED IT.
 *
 * Curriculum_service already calls the platform audit service on all six of its
 * write paths. Whether any of those calls is ACCEPTED is a different question,
 * and one nothing had ever asked: log() validates the action and the entity type
 * against two allowlists and, when either is unlisted, returns false and writes
 * nothing. Curriculum_service discards that return, like every other caller.
 *
 * That is not hypothetical. The substitute module was found logging every policy
 * change to an entity type the allowlist did not contain, so a year of changes to
 * a school's cover limits had been silently dropped. The same call shape, made
 * from here, is worth running rather than reading — through the real audit
 * service, asserting a document actually lands.
 */
final class CurriculumAuditTrailTest extends TestCase
{
    private const SCHOOL = 'SCH_CUR01';
    private const SESS   = '2026-27';
    private const CS     = 'Class 9th/Section A';
    private const SUB    = 'Mathematics';

    private function audit(FakeCurriculumFirebase $fb): Audit_log_service
    {
        return (new Audit_log_service())->init($fb, self::SCHOOL, self::SESS, [
            'uid' => 'STA_HEAD', 'name' => 'A Head', 'role' => 'Principal',
        ]);
    }

    private function svc(FakeCurriculumFirebase $fb, ?Audit_log_service $audit): Curriculum_service
    {
        return (new Curriculum_service())
            ->init($fb, $fb, self::SCHOOL, self::SCHOOL, self::SESS,
                   'STA_HEAD', 'A Head', 'Principal', $audit)
            ->withAssignmentService(new FakeAssignmentLookup());
    }

    /** Every entry the real audit service accepted, in order. */
    private function entries(FakeCurriculumFirebase $fb): array
    {
        return array_values($fb->docs['academicAuditLog'] ?? []);
    }

    private function seed(FakeCurriculumFirebase $fb, ?Audit_log_service $audit): array
    {
        $res = $this->svc($fb, $audit)->saveCurriculum(self::CS, self::SUB, [
            ['title' => 'Fractions', 'chapter' => '1', 'est_periods' => 4],
            ['title' => 'Decimals',  'chapter' => '2', 'est_periods' => 3],
        ], null);
        return array_column($res['topics'], 'topicId');
    }

    // -- the three write paths ---------------------------------------------

    public function test_creating_a_syllabus_is_recorded(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seed($fb, $this->audit($fb));

        $e = $this->entries($fb);
        $this->assertCount(1, $e, 'the entry was accepted, not silently refused');
        $this->assertSame('create', $e[0]['action']);
        $this->assertSame('curriculum', $e[0]['entityType']);
        $this->assertSame('STA_HEAD', $e[0]['actor']['uid']);
        $this->assertNull($e[0]['before'], 'there was nothing there before');
    }

    public function test_editing_a_syllabus_records_what_it_was(): void
    {
        $fb    = new FakeCurriculumFirebase();
        $audit = $this->audit($fb);
        $this->seed($fb, $audit);

        $rows   = array_map(static fn($t) => [
            'topicId' => $t['topicId'], 'title' => $t['title'],
            'chapter' => $t['chapter'], 'est_periods' => $t['est_periods'],
            'status'  => $t['status'],
        ], $this->svc($fb, $audit)->getCurriculum(self::CS, self::SUB)['topics']);
        $rows[] = ['title' => 'Algebra', 'chapter' => '3', 'est_periods' => 5];

        $this->svc($fb, $audit)->saveCurriculum(self::CS, self::SUB, $rows, 1);

        $e = $this->entries($fb);
        $this->assertCount(2, $e);
        $this->assertSame('update', $e[1]['action']);
        $this->assertSame(2, $e[1]['before']['totalTopics'],
            'the record says what the syllabus held before the edit');
        $this->assertSame(3, $e[1]['after']['totalTopics']);
    }

    public function test_marking_a_topic_taught_is_recorded_against_the_topic(): void
    {
        $fb    = new FakeCurriculumFirebase();
        $audit = $this->audit($fb);
        $ids   = $this->seed($fb, $audit);

        $this->svc($fb, $audit)->updateTopicStatus(self::CS, self::SUB, $ids[0], 0, 'completed', 1);

        $e = $this->entries($fb)[1];
        $this->assertSame('status_change', $e['action']);
        $this->assertSame('curriculumTopic', $e['entityType']);
        $this->assertSame($ids[0], $e['entityId'], 'named by the topic, not its position');
        $this->assertSame('not_started', $e['before']['status']);
        $this->assertSame('completed',   $e['after']['status']);
    }

    public function test_deleting_a_topic_records_what_was_lost(): void
    {
        $fb    = new FakeCurriculumFirebase();
        $audit = $this->audit($fb);
        $ids   = $this->seed($fb, $audit);

        $this->svc($fb, $audit)->deleteTopic(self::CS, self::SUB, $ids[1], 1, 1);

        $e = $this->entries($fb)[1];
        $this->assertSame('delete', $e['action']);
        $this->assertSame('curriculumTopic', $e['entityType']);
        $this->assertSame('Decimals', $e['before']['title'],
            'a deletion is only useful in the log if it says what it removed');
        $this->assertNull($e['after']);
        $this->assertSame($this->parentDocId(), $e['metadata']['parentDocId'],
            'and which syllabus it came out of');
    }

    private function parentDocId(): string
    {
        return self::SCHOOL . '_' . self::SESS . '_Class_9th_Section_A_Mathematics';
    }

    // -- the properties that make the log usable ----------------------------

    public function test_every_entry_is_scoped_to_the_school_and_session(): void
    {
        $fb    = new FakeCurriculumFirebase();
        $audit = $this->audit($fb);
        $ids   = $this->seed($fb, $audit);
        $this->svc($fb, $audit)->updateTopicStatus(self::CS, self::SUB, $ids[0], 0, 'completed', 1);
        $this->svc($fb, $audit)->deleteTopic(self::CS, self::SUB, $ids[1], 1, 2);

        $e = $this->entries($fb);
        $this->assertCount(3, $e, 'all three writes were recorded');
        foreach ($e as $row) {
            $this->assertSame(self::SCHOOL, $row['schoolId']);
            $this->assertSame(self::SESS,   $row['session']);
            $this->assertNotSame('', $row['ts']);
            $this->assertStringStartsWith(self::SCHOOL . '_', $row['logId'],
                'the id sorts by school and then by time');
        }
    }

    /** A failed write is not a reason to lose the syllabus edit itself. */
    public function test_an_audit_failure_does_not_cost_the_edit(): void
    {
        $fb = new FakeCurriculumFirebase();
        $audit = new class extends Audit_log_service {
            public function log(string $a, string $b, string $c, ?array $d = null,
                                ?array $e = null, array $f = [], ?array $g = null): bool
            {
                throw new \RuntimeException('audit sink unavailable');
            }
        };
        $audit->init($fb, self::SCHOOL, self::SESS, []);

        $this->seed($fb, $audit);

        $this->assertNotEmpty($fb->docs['curriculum'] ?? [],
            'the syllabus is saved whether or not the log could be written');
    }

    /** And with no audit service wired at all, the writes still work. */
    public function test_the_service_works_without_an_audit_sink(): void
    {
        $fb = new FakeCurriculumFirebase();
        $this->seed($fb, null);

        $this->assertNotEmpty($fb->docs['curriculum'] ?? []);
        $this->assertSame([], $fb->docs['academicAuditLog'] ?? []);
    }
}
