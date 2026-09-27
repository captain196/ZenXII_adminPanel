<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__ . '/../../system/');
if (!defined('APPPATH'))  define('APPPATH',  __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../../application/libraries/Timetable_plan_store.php';

/**
 * A Firestore double that actually STORES day documents and serves them back,
 * because a copy operation cannot be tested against a fake that always answers
 * "no days".
 */
class FakeChangeVersionFirestore
{
    public array $versions = [];   // docId => version data
    public array $days     = [];   // docId => day data
    public array $writes   = [];   // every write, in order
    public int   $failDayWriteAt = 0;   // 1-based index of the day write to fail
    public bool  $dayQueryUnreadable = false;
    public bool  $refuseVersionWrite  = false;

    private int $dayWrites = 0;

    public function firestoreGetOrFail($collection, $docId)
    {
        return $this->versions[$docId] ?? null;
    }

    public function firestoreQueryOrFail($collection, $conditions = [], $orderBy = null, $dir = 'ASC', $limit = null)
    {
        if ($collection === 'timetableVersionDays') {
            if ($this->dayQueryUnreadable) {
                throw new \RuntimeException('index missing');
            }
            $want = [];
            foreach ($conditions as $c) {
                $want[(string) ($c[0] ?? '')] = $c[2] ?? null;
            }
            $out = [];
            foreach ($this->days as $d) {
                foreach ($want as $k => $v) {
                    if (($d[$k] ?? null) !== $v) continue 2;
                }
                $out[] = $d;
            }
            return $out;
        }
        return [];
    }

    public function firestoreSet($collection, $docId, $data, $merge = true)
    {
        if ($collection === 'timetableVersionDays') {
            $this->dayWrites++;
            if ($this->failDayWriteAt > 0 && $this->dayWrites === $this->failDayWriteAt) {
                throw new \RuntimeException('day write refused');
            }
            $this->days[$docId] = $merge
                ? array_merge($this->days[$docId] ?? [], $data)
                : $data;
        }
        if ($collection === 'timetableVersions') {
            if ($this->refuseVersionWrite) return false;
            $this->versions[$docId] = $merge
                ? array_merge($this->versions[$docId] ?? [], $data)
                : $data;
        }
        $this->writes[] = ['collection' => $collection, 'docId' => $docId,
                           'data' => $data, 'merge' => $merge];
        return true;
    }

    /** Every docId this fake was asked to WRITE. */
    public function writtenDocIds(): array
    {
        return array_values(array_unique(array_column($this->writes, 'docId')));
    }
}

/**
 * PHASE 8 — CREATE A CHANGE VERSION.
 *
 * The owner approved published-timetable immutability: a published timetable is
 * never edited in place; a change is made by creating a new editable version
 * from it, editing that, validating, and publishing a new version resolved by
 * its effective period.
 *
 * The repository already enforced half of that. saveCandidateDay() refuses to
 * write to a published version with the message "Create a new version from it
 * and edit that instead" — an instruction to perform an operation that did not
 * exist. Immutability without a fast copy is not a policy, it is a dead end:
 * the administrator's only remaining route is to rebuild a week by hand, which
 * the owner explicitly ruled out.
 *
 * These tests pin the properties that make immutability safe rather than merely
 * restrictive:
 *   - the SOURCE IS NEVER WRITTEN (the whole point)
 *   - the copy is faithful, including the manual-edit provenance on each day
 *   - the new version is a DRAFT and carries no claim about when it applies
 *   - an unreadable source day set is never mistaken for an empty one
 *   - a half-finished copy is marked failed and refused thereafter (ACAD-D24)
 *   - a known-broken source cannot seed a fresh version
 *   - lineage is provenance ONLY: no supersession, no status inheritance
 */
final class ChangeVersionTest extends TestCase
{
    private const SCHOOL = 'SCH_A';
    private const SESS   = '2026-2027';
    private const PLAN   = 'PLAN_A';

    private function store(FakeChangeVersionFirestore $fb): Timetable_plan_store
    {
        return (new Timetable_plan_store())->init($fb, self::SCHOOL, self::SESS, 'STA0001');
    }

    private function vDoc(string $versionId): string
    {
        return self::SCHOOL . '_' . self::SESS . '_' . self::PLAN . '_' . $versionId;
    }

    /**
     * A published source with two days, one of which a human tuned by hand.
     * Published deliberately: that is the case the guard's message describes.
     */
    private function fbWithPublishedSource(string $vid = 'V1'): FakeChangeVersionFirestore
    {
        $fb = new FakeChangeVersionFirestore();
        $fb->versions[$this->vDoc($vid)] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => self::PLAN, 'versionId' => $vid,
            'status' => Timetable_plan_service::STATUS_PUBLISHED,
            'effectiveFrom' => '2026-04-01', 'effectiveTo' => '',
        ];
        foreach ([
            ['Class 8th/Section A', 'Monday',  false],
            ['Class 8th/Section A', 'Tuesday', true],
        ] as [$cs, $day, $manual]) {
            $safe = str_replace('/', '_', $cs);
            $fb->days[$this->vDoc($vid) . "_{$safe}_{$day}"] = [
                'schoolId' => self::SCHOOL, 'session' => self::SESS,
                'planId' => self::PLAN, 'versionId' => $vid,
                'classSection' => $cs, 'day' => $day,
                'periods' => [['subject' => 'Mathematics', 'teacherId' => 'STA001']],
                'manuallyEdited' => $manual,
            ];
        }
        return $fb;
    }

    // ── the property immutability exists for ─────────────────────────────

    public function test_the_source_version_is_never_written(): void
    {
        $fb = $this->fbWithPublishedSource();
        $this->store($fb)->createChangeVersion(self::PLAN, 'V1');

        foreach ($fb->writtenDocIds() as $docId) {
            $this->assertStringNotContainsString('_V1', $docId,
                'a change version must not write to the version it copies — that is '
                . 'the entire content of the immutability decision');
        }
    }

    public function test_the_published_source_keeps_its_status_and_effective_period(): void
    {
        $fb = $this->fbWithPublishedSource();
        $before = $fb->versions[$this->vDoc('V1')];

        $this->store($fb)->createChangeVersion(self::PLAN, 'V1');

        $this->assertSame($before, $fb->versions[$this->vDoc('V1')],
            'creating a change version must not supersede, unpublish, re-date or '
            . 'otherwise touch the source — lineage is provenance only');
    }

    // ── the copy must be faithful and fast ───────────────────────────────

    public function test_every_day_is_copied_into_the_new_version(): void
    {
        $fb  = $this->fbWithPublishedSource();
        $res = $this->store($fb)->createChangeVersion(self::PLAN, 'V1');

        $this->assertSame(2, $res['days_copied']);
        $this->assertStringStartsWith('CHG_', $res['versionId']);

        $copied = array_values(array_filter($fb->days,
            fn($d) => ($d['versionId'] ?? '') === $res['versionId']));
        $this->assertCount(2, $copied,
            'immutability is only workable if the administrator starts from the '
            . 'timetable they already have');

        $daysCopied = array_column($copied, 'day');
        sort($daysCopied);
        $this->assertSame(['Monday', 'Tuesday'], $daysCopied);
        $this->assertSame([['subject' => 'Mathematics', 'teacherId' => 'STA001']],
            $copied[0]['periods'], 'period content must survive the copy intact');
    }

    public function test_manual_edit_provenance_is_copied_not_fabricated(): void
    {
        $fb  = $this->fbWithPublishedSource();
        $res = $this->store($fb)->createChangeVersion(self::PLAN, 'V1');

        $byDay = [];
        foreach ($fb->days as $d) {
            if (($d['versionId'] ?? '') === $res['versionId']) {
                $byDay[$d['day']] = $d;
            }
        }

        $this->assertFalse($byDay['Monday']['manuallyEdited'],
            'a generated day must not become a hand edit by being copied');
        $this->assertTrue($byDay['Tuesday']['manuallyEdited'],
            'a day a human tuned is still a human decision in the copy; flattening '
            . 'it would destroy the record that stops a later generation run from '
            . 'silently discarding that work');
    }

    // ── the new version makes no claim about when it applies ─────────────

    public function test_the_new_version_is_a_draft_and_never_resolves(): void
    {
        $fb  = $this->fbWithPublishedSource();
        $res = $this->store($fb)->createChangeVersion(self::PLAN, 'V1');

        $new = $fb->versions[$this->vDoc($res['versionId'])];
        $this->assertSame(Timetable_plan_service::STATUS_DRAFT, $new['status'],
            'a change version must not inherit the published status of its source');

        // Whatever its placeholder dates, a draft must never be resolvable.
        $r = Timetable_plan_service::resolveForDate([$new], '2026-06-01');
        $this->assertSame(Timetable_plan_service::NONE, $r['status'],
            'an unpublished change version must never go live by accident');
    }

    public function test_the_source_effective_period_is_not_copied(): void
    {
        $fb  = $this->fbWithPublishedSource();
        $res = $this->store($fb)->createChangeVersion(self::PLAN, 'V1');

        $new = $fb->versions[$this->vDoc($res['versionId'])];
        $this->assertNotSame('2026-04-01', $new['effectiveFrom'],
            'copying the source dates would make two versions claim the same period '
            . 'the moment either is published');
    }

    public function test_lineage_is_recorded_as_provenance_only(): void
    {
        $fb  = $this->fbWithPublishedSource();
        $res = $this->store($fb)->createChangeVersion(self::PLAN, 'V1');

        $new = $fb->versions[$this->vDoc($res['versionId'])];
        $this->assertSame('V1', $new['regeneratedFrom']);

        // Provenance must not smuggle in lifecycle meaning.
        foreach (['supersedes', 'replaces', 'invalidates', 'unpublishes'] as $k) {
            $this->assertArrayNotHasKey($k, $new,
                "regeneratedFrom means origination and nothing more; '{$k}' would be "
                . 'a semantic the owner explicitly withheld');
        }
    }

    public function test_the_new_version_carries_this_tenant_and_session(): void
    {
        $fb  = $this->fbWithPublishedSource();
        $res = $this->store($fb)->createChangeVersion(self::PLAN, 'V1');
        $new = $fb->versions[$this->vDoc($res['versionId'])];

        $this->assertSame(self::SCHOOL, $new['schoolId']);
        $this->assertSame(self::SESS,   $new['session']);
    }

    /**
     * Isolation is enforced a step earlier than this operation: getVersion()
     * checks schoolId and session on the document it read and returns null when
     * either differs, so a foreign-tenant source is not merely sanitised on
     * copy — it is unreadable, and the copy never begins.
     */
    public function test_a_foreign_tenant_source_cannot_be_copied_at_all(): void
    {
        $fb = $this->fbWithPublishedSource();
        $fb->versions[$this->vDoc('V1')]['schoolId'] = 'SCH_OTHER';

        try {
            $this->store($fb)->createChangeVersion(self::PLAN, 'V1');
            $this->fail('a foreign-tenant version must never be copyable');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~could not be found~i', $e->getMessage());
        }

        $this->assertSame([], $fb->writtenDocIds(),
            'nothing may be created from another tenant\'s timetable');
    }

    public function test_a_foreign_session_source_cannot_be_copied(): void
    {
        $fb = $this->fbWithPublishedSource();
        $fb->versions[$this->vDoc('V1')]['session'] = '2025-2026';

        $this->expectException(Service_exception::class);
        $this->store($fb)->createChangeVersion(self::PLAN, 'V1');
    }

    // ── fail-loud: unreadable is not empty ───────────────────────────────

    public function test_an_unreadable_source_day_set_refuses_and_creates_nothing(): void
    {
        $fb = $this->fbWithPublishedSource();
        $fb->dayQueryUnreadable = true;

        try {
            $this->store($fb)->createChangeVersion(self::PLAN, 'V1');
            $this->fail('an unreadable day set must not produce a change version');
        } catch (Service_exception $e) {
            $this->assertStringNotContainsString('nothing to copy', $e->getMessage(),
                'a failed read must never be reported as an empty timetable — that '
                . 'is the defect class this programme exists to remove');
        }

        $this->assertSame([], $fb->writtenDocIds(),
            'nothing may be created when the source could not be read');
    }

    public function test_a_genuinely_empty_source_is_refused_distinctly(): void
    {
        $fb = $this->fbWithPublishedSource();
        $fb->days = [];   // genuinely empty, and readable

        $this->expectException(Service_exception::class);
        $this->expectExceptionMessageMatches('~nothing to copy~i');
        $this->store($fb)->createChangeVersion(self::PLAN, 'V1');
    }

    public function test_a_missing_source_is_refused(): void
    {
        $fb = new FakeChangeVersionFirestore();
        $this->expectException(Service_exception::class);
        $this->expectExceptionMessageMatches('~could not be found~i');
        $this->store($fb)->createChangeVersion(self::PLAN, 'V_NOPE');
    }

    // ── a known-broken source must not seed a fresh version ──────────────

    public function test_a_failed_generation_source_cannot_be_copied(): void
    {
        $fb = $this->fbWithPublishedSource();
        $fb->versions[$this->vDoc('V1')]['generation'] =
            ['state' => Timetable_plan_service::GEN_FAILED, 'daysWritten' => 1];

        $this->expectException(Service_exception::class);
        $this->expectExceptionMessageMatches('~not generated completely~i');
        $this->store($fb)->createChangeVersion(self::PLAN, 'V1');
    }

    public function test_a_running_generation_source_cannot_be_copied(): void
    {
        $fb = $this->fbWithPublishedSource();
        $fb->versions[$this->vDoc('V1')]['generation'] =
            ['state' => Timetable_plan_service::GEN_RUNNING];

        $this->expectException(Service_exception::class);
        $this->store($fb)->createChangeVersion(self::PLAN, 'V1');
    }

    public function test_an_unrecognised_generation_state_is_refused(): void
    {
        $fb = $this->fbWithPublishedSource();
        $fb->versions[$this->vDoc('V1')]['generation'] = ['state' => 'weird'];

        $this->expectException(Service_exception::class);
        $this->store($fb)->createChangeVersion(self::PLAN, 'V1');
    }

    /**
     * The main case. A published or hand-built timetable has NO generation block
     * at all, and isGenerationComplete() reports false for it. Gating on that
     * helper would refuse exactly the source this operation exists to serve.
     */
    public function test_a_never_generated_source_can_be_copied(): void
    {
        $fb = $this->fbWithPublishedSource();
        $this->assertArrayNotHasKey('generation', $fb->versions[$this->vDoc('V1')]);
        $this->assertFalse(
            Timetable_plan_service::isGenerationComplete($fb->versions[$this->vDoc('V1')]),
            'premise: a hand-built timetable is not "generation complete"');

        $res = $this->store($fb)->createChangeVersion(self::PLAN, 'V1');
        $this->assertSame(2, $res['days_copied'],
            'a hand-built or published timetable is the primary source for a change '
            . 'version and must not be blocked by a generator-output check');
    }

    // ── ACAD-D24 containment on the copy path ────────────────────────────

    public function test_a_half_finished_copy_is_marked_failed_and_refused(): void
    {
        $fb = $this->fbWithPublishedSource();
        $fb->failDayWriteAt = 2;

        try {
            $this->store($fb)->createChangeVersion(self::PLAN, 'V1');
            $this->fail('a failed day write must stop the copy');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~was not changed~i', $e->getMessage(),
                'the operator must be told the timetable they copied from is intact');
        }

        $chg = null;
        foreach ($fb->versions as $v) {
            if (str_starts_with((string) ($v['versionId'] ?? ''), 'CHG_')) $chg = $v;
        }
        $this->assertNotNull($chg, 'the partial version is kept for diagnosis');
        $this->assertSame(Timetable_plan_service::GEN_FAILED, $chg['generation']['state']);
        $this->assertSame(1, $chg['generation']['daysWritten']);

        $this->expectException(Service_exception::class);
        $this->expectExceptionMessageMatches('~not generated completely~i');
        $this->store($fb)->readCompleteCandidate(self::PLAN, $chg['versionId']);
    }

    public function test_a_failed_copy_still_never_writes_the_source(): void
    {
        $fb = $this->fbWithPublishedSource();
        $fb->failDayWriteAt = 1;

        try { $this->store($fb)->createChangeVersion(self::PLAN, 'V1'); }
        catch (Service_exception $e) { /* expected */ }

        foreach ($fb->writtenDocIds() as $docId) {
            $this->assertStringNotContainsString('_V1', $docId,
                'even the failure path must leave the source untouched');
        }
    }

    public function test_a_successful_copy_marks_the_run_complete(): void
    {
        $fb  = $this->fbWithPublishedSource();
        $res = $this->store($fb)->createChangeVersion(self::PLAN, 'V1');

        $new = $fb->versions[$this->vDoc($res['versionId'])];
        $this->assertTrue(Timetable_plan_service::isGenerationComplete($new),
            'a complete copy must be usable, or immutability strands the operator');
        $this->assertSame(2, $new['generation']['daysWritten']);
    }

    public function test_the_copy_run_is_attributed_and_labelled(): void
    {
        $fb  = $this->fbWithPublishedSource();
        $res = $this->store($fb)->createChangeVersion(self::PLAN, 'V1');

        $new = $fb->versions[$this->vDoc($res['versionId'])];
        $this->assertSame('change_version_copy', $new['generation']['context']['kind'],
            'a copy run must be distinguishable from a generation run in the record');
        $this->assertSame('V1', $new['generation']['context']['copiedFrom']);

        foreach ($fb->days as $d) {
            if (($d['versionId'] ?? '') === $res['versionId']) {
                $this->assertSame($res['runId'], $d['generationRunId'],
                    'every copied day must be attributable to the run that wrote it');
            }
        }
    }

    public function test_a_refused_version_write_is_not_reported_as_success(): void
    {
        $fb = $this->fbWithPublishedSource();
        $fb->refuseVersionWrite = true;

        $this->expectException(Service_exception::class);
        $this->store($fb)->createChangeVersion(self::PLAN, 'V1');
    }

    // ── ACAD-D28 / D29 — closing a run must not erase or misattribute it ─

    /**
     * stampGeneration() writes the whole `generation` map, so closing a run used
     * to REPLACE it and discard startedAt, startedBy and context. Nothing read
     * those after completion, so the loss was invisible — which is exactly why
     * it survived: a completed version could not be told apart from a copy and
     * carried no record of who populated it.
     */
    public function test_closing_a_run_preserves_who_started_it_and_why(): void
    {
        $fb  = $this->fbWithPublishedSource();
        $res = $this->store($fb)->createChangeVersion(self::PLAN, 'V1');
        $gen = $fb->versions[$this->vDoc($res['versionId'])]['generation'];

        $this->assertSame(Timetable_plan_service::GEN_COMPLETE, $gen['state']);
        $this->assertArrayHasKey('startedAt', $gen,
            'a completed run must still record when it began');
        $this->assertSame('STA0001', $gen['startedBy'],
            'a completed run must still record who ran it');
        $this->assertSame('change_version_copy', $gen['context']['kind'],
            'a completed run must still record what it was');
    }

    public function test_a_failed_run_also_keeps_its_provenance(): void
    {
        $fb = $this->fbWithPublishedSource();
        $fb->failDayWriteAt = 2;

        try { $this->store($fb)->createChangeVersion(self::PLAN, 'V1'); }
        catch (Service_exception $e) { /* expected */ }

        $chg = null;
        foreach ($fb->versions as $v) {
            if (str_starts_with((string) ($v['versionId'] ?? ''), 'CHG_')) $chg = $v;
        }
        $this->assertSame('STA0001', $chg['generation']['startedBy']);
        $this->assertSame('V1', $chg['generation']['context']['copiedFrom'],
            'a failure record is only useful if it still says what was attempted');
        $this->assertArrayHasKey('reason', $chg['generation']);
    }

    /**
     * ACAD-D29. Completing a run that has already been superseded would stamp a
     * DIFFERENT run's work COMPLETE — the ACAD-D26 shape (a late writer landing
     * on top of a newer one) applied to the status record instead of the days.
     */
    public function test_a_stale_run_cannot_close_a_newer_one(): void
    {
        $fb    = $this->fbWithPublishedSource();
        $store = $this->store($fb);
        $res   = $store->createChangeVersion(self::PLAN, 'V1');

        $stale = 'GEN_20200101000000_aaaaaa';
        try {
            $store->completeGenerationRun(self::PLAN, $res['versionId'], $stale, 99);
            $this->fail('a superseded run must not be able to close a newer one');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~started again~i', $e->getMessage());
        }

        $gen = $fb->versions[$this->vDoc($res['versionId'])]['generation'];
        $this->assertSame($res['runId'], $gen['runId'],
            'the current run must still own the record');
        $this->assertSame(2, $gen['daysWritten'],
            'the stale run must not overwrite the real day count');
    }

    public function test_a_stale_run_cannot_mark_a_newer_one_failed(): void
    {
        $fb    = $this->fbWithPublishedSource();
        $store = $this->store($fb);
        $res   = $store->createChangeVersion(self::PLAN, 'V1');

        try {
            $store->failGenerationRun(self::PLAN, $res['versionId'],
                'GEN_20200101000000_aaaaaa', 0, 'stale');
            $this->fail('a superseded run must not be able to fail a newer one');
        } catch (Service_exception $e) { /* expected */ }

        $gen = $fb->versions[$this->vDoc($res['versionId'])]['generation'];
        $this->assertSame(Timetable_plan_service::GEN_COMPLETE, $gen['state'],
            'a good candidate must not be marked failed by a run that lost the race');
    }

    // ── the promise the guard makes is now keepable ──────────────────────

    public function test_the_immutability_guard_message_now_names_a_real_operation(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_plan_store.php');

        $this->assertStringContainsString(
            'Create a new version from it and edit that instead', $src,
            'premise: the guard tells the administrator to create a new version');
        $this->assertStringContainsString(
            'public function createChangeVersion(', $src,
            'a refusal that instructs the operator to perform an operation the '
            . 'software does not provide is a dead end, not a policy');
    }

    public function test_only_plan_collections_are_written(): void
    {
        $fb = $this->fbWithPublishedSource();
        $this->store($fb)->createChangeVersion(self::PLAN, 'V1');

        $this->assertSame(
            ['timetableVersions', 'timetableVersionDays'],
            array_values(array_unique(array_column($fb->writes, 'collection'))),
            'the live timetable contract the Teacher app reads must be untouched');
    }
}
