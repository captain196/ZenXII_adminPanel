<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Timetable_projection_service.php';

/**
 * "Are teachers seeing this timetable?" must be answered by EQUIVALENCE, and the
 * answer must say what kind of difference it found.
 *
 * ── WHY A COUNT WAS NOT ENOUGH ──────────────────────────────────────────
 *
 * Every signal in this system used to answer with a pending count. On
 * 2026-09-12 a live tenant held 18 legacy documents that matched no published
 * version, were missing Class 8th entirely, and carried no projection stamp at
 * all. The count said "18 pending" — the same thing it says when the apps are
 * one lesson out of date.
 *
 * "The projection command completed" is weaker still: a run that materialises
 * the WRONG version succeeds exactly as loudly as one that materialises the
 * right one.
 *
 * So the diagnosis distinguishes six states, and the two that imply a defect
 * rather than a pending job — `divergent` and `absent` — are reported first.
 */
final class TimetableProjectionDiagnosisTest extends TestCase
{
    private const V = 'CAND_NEW';

    /** A document as the projection would build it. */
    private function want(string $subject = 'Mathematics'): array
    {
        return [
            'schoolId' => 'SCH_X', 'session' => '2026-27',
            'sectionKey' => 'Class 8th/Section A', 'day' => 'Monday',
            'periods' => [['periodNumber' => 1, 'subject' => $subject,
                           'teacher' => 'Sunita Raghav', 'teacherId' => 'STA0112']],
            'projectedFromPlan'    => 'REGULAR',
            'projectedFromVersion' => self::V,
            'projectedAt'          => '2026-09-13T00:20:00+00:00',
            'projectedByUid'       => 'cron',
        ];
    }

    /** Run the real pipeline: plan() then diagnose(), never a hand-built plan. */
    private function diagnose(array $projected, array $existing): array
    {
        $plan = Timetable_projection_service::plan($projected, $existing);
        return Timetable_projection_service::diagnose($projected, $existing, $plan);
    }

    // ── the six states ──────────────────────────────────────────────────

    public function test_matching_content_and_provenance_is_in_sync(): void
    {
        $d = $this->diagnose(['doc1' => $this->want()], ['doc1' => $this->want()]);

        $this->assertTrue($d['inSync'], 'an identical copy is not reported as in sync');
        $this->assertSame(1, $d['counts']['current']);
        $this->assertStringContainsString('are seeing this timetable', $d['summary']);
    }

    /** The state the live tenant was actually in. */
    public function test_a_document_with_no_stamp_is_unprojected_not_merely_stale(): void
    {
        $legacy = $this->want('Computer Applications');
        unset($legacy['projectedFromVersion'], $legacy['projectedFromPlan'],
              $legacy['projectedAt'], $legacy['projectedByUid']);

        $d = $this->diagnose(['doc1' => $this->want()], ['doc1' => $legacy]);

        $this->assertFalse($d['inSync']);
        $this->assertSame(1, $d['counts']['unprojected'],
            'a document that never came from a published version is reported as '
            . 'stale, which suggests a projection merely lagged — it never ran');
        $this->assertSame(0, $d['counts']['stale']);
        $this->assertStringContainsString('never sent from a published timetable', $d['summary']);
        $this->assertSame([], $d['sourceVersions'],
            'a document with no stamp must contribute no source version');
    }

    public function test_a_document_from_an_earlier_version_is_stale(): void
    {
        $old = $this->want('History');
        $old['projectedFromVersion'] = 'CAND_OLD';

        $d = $this->diagnose(['doc1' => $this->want()], ['doc1' => $old]);

        $this->assertSame(1, $d['counts']['stale']);
        $this->assertSame(0, $d['counts']['unprojected']);
        $this->assertSame(['CAND_OLD' => 1], $d['sourceVersions'],
            'the version the apps are actually showing is not reported');
    }

    /**
     * THE ALARMING ONE. Stamped with the current version, but the content
     * disagrees — something wrote over a projected document. Every other state
     * is a job waiting to run; this one is a defect.
     */
    public function test_content_that_disagrees_under_the_current_stamp_is_divergent(): void
    {
        $tampered = $this->want('Games');   // same stamp, different lesson

        $d = $this->diagnose(['doc1' => $this->want()], ['doc1' => $tampered]);

        $this->assertSame(1, $d['counts']['divergent'],
            'a projected document whose content was changed by something else is '
            . 'not distinguished from one that is merely out of date');
        $this->assertStringContainsString('changed by something other than publishing',
            $d['summary']);
    }

    public function test_a_class_with_no_document_at_all_is_absent(): void
    {
        $d = $this->diagnose(['doc1' => $this->want(), 'doc2' => $this->want()],
                             ['doc1' => $this->want()]);

        $this->assertSame(1, $d['counts']['absent'],
            'a class whose timetable is missing from the apps entirely is not '
            . 'reported — this is the Class 8th case, where teachers and parents '
            . 'see nothing at all');
        $this->assertStringContainsString('missing entirely', $d['summary']);
    }

    public function test_a_document_the_version_does_not_cover_is_an_orphan(): void
    {
        $d = $this->diagnose(['doc1' => $this->want()],
                             ['doc1' => $this->want(), 'ghost' => $this->want()]);

        $this->assertSame(1, $d['counts']['orphan']);
        $this->assertFalse($d['inSync'],
            'the apps hold a class this timetable does not cover, so they are not '
            . 'showing this timetable');
        $this->assertContains('ghost', $d['documents']['orphan']);
    }

    // ── the properties that make it an answer rather than a number ──────

    /**
     * The count and the verdict must be able to disagree. The apps can hold
     * exactly the right NUMBER of documents and all of them be wrong.
     */
    public function test_the_right_number_of_wrong_documents_is_not_in_sync(): void
    {
        $wrong = $this->want('Games');
        $wrong['projectedFromVersion'] = 'CAND_OLD';

        $d = $this->diagnose(['a' => $this->want(), 'b' => $this->want()],
                             ['a' => $wrong, 'b' => $wrong]);

        $this->assertSame(2, $d['expected']);
        $this->assertFalse($d['inSync'],
            'two documents were expected and two are present, and the verdict was '
            . 'taken from the count rather than from the comparison');
        $this->assertSame(0, $d['counts']['current']);
    }

    /** A mixture is a state no single publish can produce. */
    public function test_a_mixture_of_versions_is_reported_as_a_mixture(): void
    {
        $old = $this->want('History');
        $old['projectedFromVersion'] = 'CAND_OLD';

        $d = $this->diagnose(['a' => $this->want(), 'b' => $this->want()],
                             ['a' => $this->want(), 'b' => $old]);

        $this->assertSame(['CAND_NEW' => 1, 'CAND_OLD' => 1], $d['sourceVersions'],
            'the apps hold documents from two different versions and only one is '
            . 'reported, so a partially-applied run reads as a clean one');
    }

    public function test_it_reports_when_the_apps_were_last_written_to(): void
    {
        $older = $this->want();
        $older['projectedAt'] = '2026-09-01T00:20:00+00:00';

        $d = $this->diagnose(['a' => $this->want(), 'b' => $this->want()],
                             ['a' => $older, 'b' => $this->want()]);

        $this->assertSame('2026-09-13T00:20:00+00:00', $d['lastProjectedAt'],
            'the most recent materialisation time is not reported, so nobody can '
            . 'tell whether the apps were written to last night or in June');
    }

    /** An empty school is in sync with an empty timetable — not "broken". */
    public function test_nothing_expected_and_nothing_present_is_in_sync(): void
    {
        $d = $this->diagnose([], []);
        $this->assertTrue($d['inSync']);
        $this->assertSame(0, $d['expected']);
    }
}
