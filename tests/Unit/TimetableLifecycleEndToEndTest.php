<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__ . '/../../system/');
if (!defined('APPPATH'))  define('APPPATH',  __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../../application/libraries/Timetable_plan_store.php';
require_once __DIR__ . '/../Support/FirestoreBatchPreconditionTrait.php';

/**
 * THE CANONICAL LIFECYCLE, WALKED END TO END.
 *
 * Every stage is covered in isolation elsewhere. This file exists because the
 * stages COMPOSING correctly is a separate claim from each of them working: a
 * verdict that goes stale is only useful if publishing actually consults it, and
 * an immutable published version is only useful if the correction route from it
 * genuinely leads back to a publishable candidate.
 *
 * The walk is:
 *
 *   plan → draft → edit → validate → publish → resolve
 *        → change version → edit → re-validate → publish again
 *
 * with the refusals proved at the point they should bite, not asserted from the
 * outside.
 */
class FakeE2EFirestore
{
    use FirestoreBatchPreconditionTrait;

    /** TT-C8 publish concurrency token. */
    public array $guards   = [];

    public array $plans    = [];
    public array $versions = [];
    public array $days     = [];
    public array $legacy   = [];
    public array $settings = [];
    public array $writes   = [];

    public function firestoreGetOrFail($collection, $docId)
    {
        if ($collection === 'timetablePublishGuards') {
            return $this->publishGuardDoc($docId, $this->guards);
        }
        if ($collection === 'timetablePlans')    { return $this->plans[$docId]    ?? null; }
        if ($collection === 'timetableSettings') { return $this->settings[$docId] ?? null; }
        return $this->versions[$docId] ?? null;
    }

    public function firestoreQueryOrFail($collection, $conditions = [], $orderBy = null, $dir = 'ASC', $limit = null)
    {
        $want = [];
        foreach ($conditions as $c) { $want[(string) ($c[0] ?? '')] = $c[2] ?? null; }

        $src = ['timetableVersionDays' => $this->days,
                'timetableVersions'    => $this->versions,
                'timetablePlans'       => $this->plans,
                'timetables'           => $this->legacy][$collection] ?? [];

        $out = [];
        foreach ($src as $row) {
            foreach ($want as $k => $v) {
                if (($row[$k] ?? null) !== $v) { continue 2; }
            }
            $out[] = $row;
        }
        return $out;
    }

    public function firestoreSet($collection, $docId, $data, $merge = true)
    {
        $t = ['timetableVersions' => 'versions', 'timetableVersionDays' => 'days',
              'timetablePlans' => 'plans', 'timetables' => 'legacy'][$collection] ?? null;
        if ($t !== null) {
            $this->{$t}[$docId] = $merge ? array_merge($this->{$t}[$docId] ?? [], $data) : $data;
        }
        $this->writes[] = ['collection' => $collection, 'docId' => $docId,
                           'data' => $data, 'merge' => $merge];
        return true;
    }
    /**
     * The interactive editor commits conditionally, so this stand-in needs the
     * primitive. It applies the write and reports success WITHOUT evaluating
     * the precondition: nothing here is testing concurrency, and a stand-in
     * that pretended to enforce it would give false confidence.
     * TimetableDayConcurrencyTest carries one that honours it properly.
     */
}

final class TimetableLifecycleEndToEndTest extends TestCase
{
    private const SCHOOL = 'SCH_A';
    private const SESS   = '2026-2027';
    private const SEC    = 'Class 8th/Section A';

    private FakeE2EFirestore $fb;
    private Timetable_plan_store $store;
    private string $plan;

    protected function setUp(): void
    {

        $this->fb = new FakeE2EFirestore();
        $this->fb->settings[self::SCHOOL . '_' . self::SESS] = ['periodsPerDay' => 3];
        $this->store = (new Timetable_plan_store())->init(
            $this->fb, self::SCHOOL, self::SESS, 'STA0001');
        $this->plan = (string) $this->store->ensureRegularPlan()['planId'];
    }


    /** A draft with one populated day, as generation would leave it. */
    private function seedDraft(string $vid, string $teacherId = 'STA001'): void
    {
        $this->store->createDraftVersion([
            'planId' => $this->plan, 'versionId' => $vid,
            'effectiveFrom' => '2026-06-01', 'effectiveTo' => '',
        ]);
        $run = $this->store->beginGenerationRun($this->plan, $vid, ['kind' => 'test']);
        $this->store->saveCandidateDay($this->plan, $vid, self::SEC, 'Monday', [
            ['periodNumber' => 1, 'subject' => 'Mathematics', 'teacher' => 'A',
             'teacherId' => $teacherId, 'startTime' => '09:00AM', 'endTime' => '09:45AM',
             'room' => '', 'type' => 'class'],
            ['periodNumber' => 2, 'subject' => 'Science', 'teacher' => 'B',
             'teacherId' => 'STA002', 'startTime' => '09:45AM', 'endTime' => '10:30AM',
             'room' => '', 'type' => 'class'],
            ['periodNumber' => 3, 'subject' => '', 'teacher' => '', 'teacherId' => '',
             'startTime' => '', 'endTime' => '', 'room' => '', 'type' => 'class'],
        ], false, $run);
        $this->store->completeGenerationRun($this->plan, $vid, $run, 1);
    }

    private function vDoc(string $vid): string
    {
        return self::SCHOOL . '_' . self::SESS . '_' . $this->plan . '_' . $vid;
    }

    // ── the whole chain ─────────────────────────────────────────────────

    public function test_the_full_lifecycle_composes(): void
    {
        // 1. a draft exists and is not live
        $this->seedDraft('V1');
        $this->assertSame(Timetable_plan_service::NONE,
            $this->store->resolveForDate('2026-06-15')['status'],
            'an unpublished draft must never resolve');

        // 2. it cannot be published unchecked
        try {
            $this->store->publishCandidate($this->plan, 'V1', '2026-06-01');
            $this->fail('an unchecked timetable must not publish');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~not been checked~i', $e->getMessage());
        }

        // 3. checking it succeeds and is trusted
        $verdict = $this->store->validateCandidate($this->plan, 'V1');
        $this->assertTrue($verdict['publishable']);
        $this->assertTrue($this->store->currentVerdict($this->plan, 'V1')['trusted']);

        // 4. an edit makes the verdict untrustworthy again
        $this->store->saveCandidatePeriod($this->plan, 'V1', self::SEC, 'Monday', 2, [
            'subject' => 'English', 'teacherId' => 'STA003', 'teacher' => 'C',
        ]);
        $this->assertFalse($this->store->currentVerdict($this->plan, 'V1')['trusted'],
            'editing after a check must invalidate that check');

        try {
            $this->store->publishCandidate($this->plan, 'V1', '2026-06-01');
            $this->fail('a stale verdict must block publishing');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~by hand~i', $e->getMessage());
        }

        // 5. re-check, then publish
        $this->store->validateCandidate($this->plan, 'V1');
        $res = $this->store->publishCandidate($this->plan, 'V1', '2026-06-01');
        $this->assertSame([], $res['overlaps']);

        // 6. it now resolves — and only on its own dates
        $r = $this->store->resolveForDate('2026-06-15');
        $this->assertSame(Timetable_plan_service::RESOLVED, $r['status']);
        $this->assertSame('V1', $r['version']['versionId']);
        $this->assertSame(Timetable_plan_service::NONE,
            $this->store->resolveForDate('2026-05-31')['status']);

        // 7. it is now immutable
        foreach (['edit' => fn() => $this->store->saveCandidatePeriod(
                      $this->plan, 'V1', self::SEC, 'Monday', 0, ['subject' => 'X']),
                  'regenerate' => fn() => $this->store->beginGenerationRun($this->plan, 'V1'),
                  'republish' => fn() => $this->store->publishCandidate(
                      $this->plan, 'V1', '2026-09-01')] as $what => $attempt) {
            try {
                $attempt();
                $this->fail("a published timetable must refuse to {$what}");
            } catch (Service_exception $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        // 8. the correction route: a change version, edited and published anew
        $chg = $this->store->createChangeVersion($this->plan, 'V1');
        $this->assertSame(1, $chg['days_copied']);

        $published = $this->fb->versions[$this->vDoc('V1')];
        $this->store->saveCandidatePeriod(
            $this->plan, $chg['versionId'], self::SEC, 'Monday', 0, [
                'subject' => 'Physics', 'teacherId' => 'STA004', 'teacher' => 'D',
            ]);
        $this->assertSame($published, $this->fb->versions[$this->vDoc('V1')],
            'editing the correction must not disturb what was published');

        $this->store->validateCandidate($this->plan, $chg['versionId']);

        // 9. AND HERE THE CORRECTION ROUTE STOPS — TT-C8, deliberately.
        //
        // V1 was published open-ended, so it claims every future date. With one
        // timetable in force enforced, its own correction cannot be published
        // over it: the change version is refused exactly like any other
        // overlapping publish. This test used to assert the opposite (both
        // published, resolution AMBIGUOUS), which is the state the rule exists
        // to prevent.
        //
        // This is not a defect in the guard, and it is not cosmetic either: the
        // sanctioned way to correct a live timetable is blocked whenever the
        // original was published without an end date, and nothing in the
        // product can add one. It is the concrete case for the recovery action
        // (option 6), which is DESIGN-ONLY at the time of writing, and it is
        // asserted here so it cannot be forgotten.
        try {
            $this->store->publishCandidate($this->plan, $chg['versionId'], '2026-09-01');
            $this->fail('an open-ended predecessor must block its own correction today');
        } catch (Service_exception $e) {
            $this->assertSame('overlap', $e->errorType);
        }

        $this->assertSame('V1',
            $this->store->resolveForDate('2026-07-01')['version']['versionId'],
            'V1 is still the one in force, and still the only one');
        $this->assertSame(Timetable_plan_service::RESOLVED,
            $this->store->resolveForDate('2026-10-01')['status'],
            'and no date resolves ambiguously, which is the whole point');

        // 10. the legacy collection is reached at exactly ONE moment: publishing
        //     something that is in force. Generating, editing, validating and
        //     taking a change version must never touch what teachers can see.
        $legacyWrites = array_values(array_filter($this->fb->writes,
            fn($w) => $w['collection'] === 'timetables'));
        $this->assertNotEmpty($legacyWrites,
            'publishing a timetable that applies today must reach teachers, or a '
            . 'newly onboarded school publishes and nothing happens');

        // Everything BEFORE the first publish must be canonical-only.
        $seenPublish = false;
        foreach ($this->fb->writes as $w) {
            if ($w['collection'] === 'timetableVersions'
                && ($w['data']['status'] ?? '') === Timetable_plan_service::STATUS_PUBLISHED) {
                $seenPublish = true;
            }
            if ($w['collection'] === 'timetables') {
                $this->assertTrue($seenPublish,
                    'the legacy collection was written before anything was '
                    . 'published — generation or editing is leaking into what '
                    . 'teachers can see');
            }
        }

        $collections = array_values(array_unique(array_column($this->fb->writes, 'collection')));
        sort($collections);
        $this->assertSame(
            ['timetablePlans', 'timetablePublishGuards', 'timetableVersionDays',
             'timetableVersions', 'timetables'],
            $collections,
            'outside the canonical three there is the compatibility projection, '
            . 'written at publish, and the publish concurrency token, written '
            . 'in the same commit as the version it guards. Nothing else.');
    }

    // ── the gates that must hold at every stage ─────────────────────────

    public function test_an_incomplete_generation_cannot_be_validated_or_published(): void
    {
        $this->store->createDraftVersion([
            'planId' => $this->plan, 'versionId' => 'V2',
            'effectiveFrom' => '2026-06-01', 'effectiveTo' => '',
        ]);
        $run = $this->store->beginGenerationRun($this->plan, 'V2');
        $this->store->failGenerationRun($this->plan, 'V2', $run, 0, 'boom');

        foreach (['validate' => fn() => $this->store->validateCandidate($this->plan, 'V2'),
                  'publish'  => fn() => $this->store->publishCandidate(
                      $this->plan, 'V2', '2026-06-01')] as $what => $attempt) {
            try {
                $attempt();
                $this->fail("a failed generation must not be able to {$what}");
            } catch (Service_exception $e) {
                $this->assertMatchesRegularExpression('~not generated completely~i',
                    $e->getMessage());
            }
        }
    }

    public function test_a_never_completed_run_cannot_masquerade_as_finished(): void
    {
        $this->store->createDraftVersion([
            'planId' => $this->plan, 'versionId' => 'V3',
            'effectiveFrom' => '2026-06-01', 'effectiveTo' => '',
        ]);
        $this->store->beginGenerationRun($this->plan, 'V3');   // never completed

        $this->assertFalse(Timetable_plan_service::isGenerationComplete(
            $this->fb->versions[$this->vDoc('V3')]),
            'a run still marked running is not complete');

        $this->expectException(Service_exception::class);
        $this->store->publishCandidate($this->plan, 'V3', '2026-06-01');
    }

    public function test_hard_violations_block_publishing_and_soft_ones_do_not(): void
    {
        // Two sections, same teacher, same slot — a hard clash.
        $this->seedDraft('V4');
        $run = $this->store->beginGenerationRun($this->plan, 'V4');
        $this->store->saveCandidateDay($this->plan, 'V4', 'Class 9th/Section A', 'Monday', [
            ['periodNumber' => 1, 'subject' => 'Mathematics', 'teacher' => 'A',
             'teacherId' => 'STA001', 'startTime' => '', 'endTime' => '',
             'room' => '', 'type' => 'class'],
        ], false, $run);
        $this->store->completeGenerationRun($this->plan, 'V4', $run, 2);

        $v = $this->store->validateCandidate($this->plan, 'V4');
        $this->assertFalse($v['publishable']);
        $this->assertGreaterThan(0, $v['softCount'], 'the empty period is soft');

        try {
            $this->store->publishCandidate($this->plan, 'V4', '2026-06-01');
            $this->fail('a hard violation must block publishing');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~must be fixed~i', $e->getMessage());
        }

        // Fix the clash; the soft violation remains and must not block.
        $this->store->saveCandidatePeriod(
            $this->plan, 'V4', 'Class 9th/Section A', 'Monday', 0, [
                'subject' => 'Mathematics', 'teacherId' => 'STA099', 'teacher' => 'Z',
            ]);
        $v2 = $this->store->validateCandidate($this->plan, 'V4');
        $this->assertTrue($v2['publishable']);
        $this->assertGreaterThan(0, $v2['softCount']);
        $this->assertNull($v2['score'],
            'no combined score may appear until weights are chosen');

        $this->store->publishCandidate($this->plan, 'V4', '2026-06-01');
        $this->assertSame(Timetable_plan_service::STATUS_PUBLISHED,
            $this->fb->versions[$this->vDoc('V4')]['status']);
    }

    public function test_an_assignment_change_mid_workflow_forces_a_recheck(): void
    {
        $this->seedDraft('V5');
        $this->store->validateCandidate($this->plan, 'V5');
        $this->assertTrue($this->store->currentVerdict($this->plan, 'V5')['trusted']);

        $this->store->markVersionsStaleForClass('Class 8', 'Section A');

        $cur = $this->store->currentVerdict($this->plan, 'V5');
        $this->assertFalse($cur['trusted']);
        $this->assertMatchesRegularExpression('~subject assignments~i', $cur['reason']);

        try {
            $this->store->publishCandidate($this->plan, 'V5', '2026-06-01');
            $this->fail('publishing must wait for the re-check');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~subject assignments~i', $e->getMessage());
        }

        $this->store->validateCandidate($this->plan, 'V5');
        $this->store->publishCandidate($this->plan, 'V5', '2026-06-01');
        $this->assertSame(Timetable_plan_service::STATUS_PUBLISHED,
            $this->fb->versions[$this->vDoc('V5')]['status']);
    }

    public function test_only_a_published_version_reaches_the_projection(): void
    {
        $this->seedDraft('V6');

        try {
            $this->store->projectPublishedVersion($this->plan, 'V6', true);
            $this->fail('an unpublished version must not reach teachers');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~not been published~i', $e->getMessage());
        }
        $this->assertSame([], $this->fb->legacy);

        $this->store->validateCandidate($this->plan, 'V6');
        $res = $this->store->publishCandidate($this->plan, 'V6', '2026-06-01');

        // Publishing something already in force reaches teachers by itself.
        $this->assertTrue($res['projection']['applied']);
        $this->assertSame(1, $res['projection']['written']);
        $this->assertNotEmpty($this->fb->legacy,
            'a published version is what the compatibility projection materialises');

        // And running the projection again changes nothing — which is what makes
        // it safe for the cron to run every day over a timetable that has not
        // moved.
        $again = $this->store->projectPublishedVersion($this->plan, 'V6', true);
        $this->assertSame(0, $again['written'],
            're-projecting an unchanged timetable must write nothing');
    }
}
