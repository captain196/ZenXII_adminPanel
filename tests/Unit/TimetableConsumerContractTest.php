<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Timetable_projection_service.php';
require_once __DIR__ . '/../../application/libraries/Entity_firestore_sync.php';

/**
 * WHAT THE PROJECTION WRITES MUST BE WHAT THE APPS ASK FOR.
 *
 * The Teacher and Parent apps read the legacy `timetables` collection directly
 * through the client SDK. Neither can be repointed at the canonical version
 * architecture without an app release, so the agreed consumer design is that a
 * published version is PROJECTED into that collection one way, and the apps
 * keep reading it.
 *
 * That makes one string load-bearing across three repositories. Both apps
 * narrow their query with:
 *
 *     whereEqualTo("schoolId", …).whereEqualTo("sectionKey", …)
 *
 * building `sectionKey` locally as classKey(class) + "/" + sectionKey(section),
 * then filtering `session` in the client. If the projection writes that field to
 * a different rule, every query matches nothing and both apps show an empty
 * timetable for a class that has a full one — indistinguishable, on screen,
 * from a school that has not built its timetable yet.
 *
 * This test is the contract between the three. The expected strings are what
 * the apps compute; the actual strings come from running the real projection.
 * It fails if either side drifts.
 *
 * (The apps' side of the same contract is pinned in their own JVM tests:
 * ClassSectionKeyContractTest, in both repositories.)
 */
final class TimetableConsumerContractTest extends TestCase
{
    private const SCHOOL = 'SCH_TT01';
    private const SESS   = '2026-27';

    /** One canonical version day, in the shape the store hands over. */
    private function versionDays(array $classSections): array
    {
        $out = [];
        foreach ($classSections as $cs) {
            $out[] = [
                'classSection' => $cs,
                'day'          => 'Monday',
                'periods'      => [
                    ['periodNumber' => 1, 'subject' => 'Mathematics',
                     'teacherId' => 'STA0001', 'teacher' => 'A Teacher',
                     'startTime' => '09:00', 'endTime' => '09:40', 'type' => 'class'],
                ],
            ];
        }
        return $out;
    }

    private function project(array $classSections): array
    {
        return Timetable_projection_service::project($this->versionDays($classSections), [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => 'PLAN1', 'versionId' => 'V1',
            'at' => '2026-09-01T00:00:00+00:00', 'byUid' => 'STA_HEAD',
        ]);
    }

    /** What an app builds for (class, section) — the rule both now implement. */
    private function appSectionKey(string $className, string $section): string
    {
        $c = Entity_firestore_sync::normalizeClassSection($className, $section);
        return $c['className'] . '/' . $c['section'];
    }

    // -- the field the query filters on -------------------------------------

    public function test_the_projection_writes_the_section_key_the_apps_query(): void
    {
        $docs = $this->project(['Class 9th/Section A']);

        $this->assertCount(1, $docs);
        $doc = reset($docs);
        $this->assertSame('Class 9th/Section A', $doc['sectionKey']);
        $this->assertSame($this->appSectionKey('9', 'A'), $doc['sectionKey'],
            'a numeric class label from the catalogue must reach the same key');
        $this->assertSame($this->appSectionKey('Class 9', 'A'), $doc['sectionKey'],
            'and so must a prefixed label with no ordinal');
    }

    /**
     * A malformed key stored on a version must not propagate: the projection
     * rebuilds it from canonical parts, so the apps still find the document.
     */
    public function test_a_malformed_stored_key_is_rebuilt_not_copied(): void
    {
        $docs = $this->project(['9/A']);
        $doc  = reset($docs);

        $this->assertSame('Class 9th/Section A', $doc['sectionKey'],
            'the app queries the canonical string, so that is what must be written');
        $this->assertSame('Class 9th', $doc['className']);
        $this->assertSame('Section A', $doc['section']);
    }

    // -- the other two fields the apps depend on ----------------------------

    public function test_every_projected_document_carries_school_and_session(): void
    {
        $docs = $this->project(['Class 9th/Section A', 'Class 10th/Section B']);

        $this->assertCount(2, $docs);
        foreach ($docs as $doc) {
            $this->assertSame(self::SCHOOL, $doc['schoolId'],
                'the apps filter on schoolId in the query — the rules require it');
            $this->assertSame(self::SESS, $doc['session'],
                'and filter session in the client, so it has to be on the document');
            $this->assertSame('Monday', $doc['day']);
        }
    }

    public function test_the_document_id_matches_the_admin_read_path(): void
    {
        $docs = $this->project(['Class 9th/Section A']);

        // Lesson_plan_service and Timetable_service read this document by id:
        //   {schoolId}_{session}_{sectionKey with / → _}_{day}
        $this->assertArrayHasKey(
            self::SCHOOL . '_' . self::SESS . '_Class 9th_Section A_Monday',
            $docs,
            'the id the admin services build must be the id the projection writes');
    }

    // -- periods are carried through intact ---------------------------------

    public function test_the_periods_reach_the_apps_unchanged(): void
    {
        $docs = $this->project(['Class 9th/Section A']);
        $doc  = reset($docs);

        $this->assertCount(1, $doc['periods']);
        $p = $doc['periods'][0];
        $this->assertSame(1, $p['periodNumber']);
        $this->assertSame('Mathematics', $p['subject']);
        $this->assertSame('STA0001', $p['teacherId'],
            'the teacher app claims a period by teacherId — losing it empties '
            . 'their week');
        $this->assertSame('09:00', $p['startTime']);
    }

    /** The provenance the projection stamps says which version a screen shows. */
    public function test_each_document_records_the_version_it_came_from(): void
    {
        $docs = $this->project(['Class 9th/Section A']);
        $doc  = reset($docs);

        $this->assertSame('PLAN1', $doc['projectedFromPlan']);
        $this->assertSame('V1',    $doc['projectedFromVersion']);
        $this->assertNotSame('',   $doc['projectedAt']);
    }

    // -- what happens to what the new version does not account for ----------

    /**
     * A section dropped by the new version leaves a legacy document behind.
     * The apps have no version awareness — they read whatever is there — so
     * that document is still on a teacher's screen until something removes it.
     * The projection REPORTS it rather than deleting it, which is the safe
     * half of the answer; the removal is a separate authorised step.
     */
    public function test_a_dropped_section_is_reported_as_an_orphan(): void
    {
        $projected = $this->project(['Class 9th/Section A']);
        $existing  = $projected + [
            self::SCHOOL . '_' . self::SESS . '_Class 9th_Section B_Monday' => [
                'schoolId' => self::SCHOOL, 'session' => self::SESS,
                'sectionKey' => 'Class 9th/Section B', 'day' => 'Monday',
                'periods' => [],
            ],
        ];

        $plan = Timetable_projection_service::plan($projected, $existing);

        $this->assertSame(
            [self::SCHOOL . '_' . self::SESS . '_Class 9th_Section B_Monday'],
            $plan['orphans'],
            'the section the new version dropped is named, not silently deleted');
        $this->assertArrayNotHasKey(
            self::SCHOOL . '_' . self::SESS . '_Class 9th_Section B_Monday',
            $plan['writes']);
    }

    /** Re-projecting an unchanged version writes nothing. */
    public function test_projecting_the_same_version_twice_changes_nothing(): void
    {
        $projected = $this->project(['Class 9th/Section A']);
        $plan      = Timetable_projection_service::plan($projected, $projected);

        $this->assertSame([], $plan['writes'],
            'a projection that rewrites identical documents would churn the '
            . 'version counter every night');
        $this->assertCount(1, $plan['unchanged']);
    }

    /** A changed period advances the legacy version counter the apps ignore
     *  but the admin UI uses for its concurrency check. */
    public function test_a_changed_period_advances_the_legacy_version(): void
    {
        $projected = $this->project(['Class 9th/Section A']);
        $docId     = array_key_first($projected);
        $existing  = $projected;
        $existing[$docId]['periods'][0]['subject'] = 'Science';
        $existing[$docId]['version'] = 7;

        $plan = Timetable_projection_service::plan($projected, $existing);

        $this->assertArrayHasKey($docId, $plan['writes']);
        $this->assertSame(8, $plan['writes'][$docId]['version']);
    }
}
