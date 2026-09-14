<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__ . '/../../system/');
if (!defined('APPPATH'))  define('APPPATH',  __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Timetable_plan_store.php';

/** Firestore double that can refuse or throw on the version write. */
class FakeGenRunFirestore
{
    public array $docs = [];
    public array $rows = [];
    public array $written = [];
    public bool  $versionWriteFalse  = false;
    public bool  $versionWriteThrows = false;
    public bool  $listThrows = false;

    public function firestoreGetOrFail($collection, $docId)
    {
        return $this->docs[$docId] ?? null;
    }

    public function firestoreQueryOrFail($collection, $conditions = [], $orderBy = null, $dir = 'ASC', $limit = null)
    {
        if ($this->listThrows) throw new \RuntimeException('QUERY_FAILED');
        return $limit === null ? $this->rows : array_slice($this->rows, 0, $limit);
    }

    public function firestoreSet($collection, $docId, $data, $merge = true)
    {
        if ($collection === 'timetableVersions') {
            if ($this->versionWriteThrows) throw new \RuntimeException('stamp failed');
            if ($this->versionWriteFalse) return false;
            // Merge into the stored doc so the lifecycle can be driven end-to-end.
            $this->docs[$docId] = array_merge($this->docs[$docId] ?? [], $data);
        }
        $this->written[] = ['collection' => $collection, 'docId' => $docId,
                            'data' => $data, 'merge' => $merge];
        return true;
    }
}

/**
 * ACAD-D24 at candidate level — a partial generation must never look finished.
 *
 * The live generator commits in sequential chunks of 450. If chunk 3 of 7 fails,
 * chunks 1-2 are already committed and the run stops. Moving generation into an
 * isolated candidate stops that damaging the LIVE timetable — but it does not,
 * by itself, stop the half-written candidate from reading like a finished one.
 * A half-written timetable that reads as finished is just as dangerous once it
 * reaches validation, comparison, or publish.
 *
 * So completeness is explicit and CLOSED: running, failed, absent, or an
 * unrecognised state all mean incomplete. The safe reading, not the convenient
 * one.
 *
 * These tests drive the real store through the real lifecycle against a fake
 * Firestore that merges writes, so the state transitions are exercised rather
 * than asserted from source.
 */
final class TimetableGenerationRunTest extends TestCase
{
    private const SCHOOL = 'SCH_A';
    private const SESS   = '2026-2027';
    private const DOC    = 'SCH_A_2026-2027_PLAN_A_V1';

    private function fb(string $status = 'draft'): FakeGenRunFirestore
    {
        $fb = new FakeGenRunFirestore();
        $fb->docs[self::DOC] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => 'PLAN_A', 'versionId' => 'V1', 'status' => $status,
            'effectiveFrom' => '2026-04-01', 'effectiveTo' => '',
        ];
        return $fb;
    }

    private function store(FakeGenRunFirestore $fb): Timetable_plan_store
    {
        return (new Timetable_plan_store())->init($fb, self::SCHOOL, self::SESS, 'STA0001');
    }

    // ── the closed-completeness rule ─────────────────────────────────────

    public function test_only_a_finished_run_counts_as_complete(): void
    {
        foreach ([
            'never generated' => [],
            'running'         => ['generation' => ['state' => 'running']],
            'failed'          => ['generation' => ['state' => 'failed']],
            'garbage'         => ['generation' => ['state' => 'somethingelse']],
            'not an array'    => ['generation' => 'complete'],
        ] as $label => $doc) {
            $this->assertFalse(Timetable_plan_service::isGenerationComplete($doc),
                "a version that is '{$label}' must NOT read as complete — the rule is "
                . 'closed, so anything that is not a finished run is incomplete');
        }

        // A non-array version is not a version. A read that returned null, or a
        // decode that produced a scalar, must fail closed like everything else —
        // otherwise the one input that means "I have nothing usable here" would
        // be the one input that reads as complete.
        foreach ([null, '', 'complete', 0, 1, false, true] as $notAVersion) {
            $this->assertFalse(Timetable_plan_service::isGenerationComplete($notAVersion),
                'a non-array version must never read as complete: '
                . var_export($notAVersion, true));
        }

        $this->assertTrue(Timetable_plan_service::isGenerationComplete(
            ['generation' => ['state' => 'complete']]));
    }

    // ── the lifecycle, driven end to end ─────────────────────────────────

    public function test_a_run_that_completes_can_then_be_read(): void
    {
        $fb = $this->fb();
        $store = $this->store($fb);

        $runId = $store->beginGenerationRun('PLAN_A', 'V1', ['scope' => 'all']);
        $this->assertStringStartsWith('GEN_', $runId);

        // Mid-run the candidate must be unusable.
        try {
            $store->readCompleteCandidate('PLAN_A', 'V1');
            $this->fail('a candidate mid-generation must not be readable as complete');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~not generated completely~i', $e->getMessage());
        }

        $store->completeGenerationRun('PLAN_A', 'V1', $runId, 42);

        $read = $store->readCompleteCandidate('PLAN_A', 'V1');
        $this->assertSame(42, $read['version']['generation']['daysWritten']);
        $this->assertSame('complete', $read['version']['generation']['state']);
    }

    /**
     * The D24 scenario itself: the run stops part-way. The days already written
     * stay — deleting them would be a second partial write on top of the first —
     * but the candidate must be unusable and say why.
     */
    public function test_a_failed_run_leaves_an_unusable_candidate_not_a_finished_one(): void
    {
        $fb = $this->fb();
        $store = $this->store($fb);

        $runId = $store->beginGenerationRun('PLAN_A', 'V1');
        $store->failGenerationRun('PLAN_A', 'V1', $runId, 3,
            'commit chunk 3 of 7 failed');

        $this->assertFalse(
            Timetable_plan_service::isGenerationComplete($fb->docs[self::DOC]));

        try {
            $store->readCompleteCandidate('PLAN_A', 'V1');
            $this->fail('a failed run must not produce a usable candidate');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~generate it again~i', $e->getMessage(),
                'the operator needs to know the recovery action');
            $this->assertMatchesRegularExpression('~investigated~i', $e->getMessage(),
                'and that the partial result was kept deliberately, not left by accident');
        }

        $this->assertSame('commit chunk 3 of 7 failed',
            $fb->docs[self::DOC]['generation']['reason'],
            'the failure reason must survive for diagnosis');
    }

    /**
     * If the process dies immediately after beginning, nothing ever writes
     * 'complete'. The candidate must stay unusable rather than reverting to
     * something that looks finished.
     */
    public function test_an_abandoned_run_never_becomes_complete_on_its_own(): void
    {
        $fb = $this->fb();
        $this->store($fb)->beginGenerationRun('PLAN_A', 'V1');

        $this->assertSame('running', $fb->docs[self::DOC]['generation']['state']);
        $this->assertFalse(
            Timetable_plan_service::isGenerationComplete($fb->docs[self::DOC]),
            'an abandoned run must fail closed — the absence of a completion stamp '
            . 'is itself the signal');
    }

    // ── the stamp itself must not be silently lost ───────────────────────

    /**
     * If the RUNNING stamp is not recorded, a run that later dies would leave a
     * candidate with no generation block at all. Under the closed rule that
     * still reads as incomplete — but the operator would get no reason, so the
     * write must be verified rather than assumed.
     */
    public function test_a_refused_stamp_prevents_the_run_from_starting(): void
    {
        $fb = $this->fb();
        $fb->versionWriteFalse = true;

        $this->expectException(Service_exception::class);
        $this->store($fb)->beginGenerationRun('PLAN_A', 'V1');
    }

    public function test_a_throwing_stamp_prevents_the_run_from_starting(): void
    {
        $fb = $this->fb();
        $fb->versionWriteThrows = true;

        $this->expectException(Service_exception::class);
        $this->store($fb)->beginGenerationRun('PLAN_A', 'V1');
    }

    /** The stamp must merge — a version owns its dates, lineage and status. */
    public function test_the_stamp_merges_and_preserves_the_version(): void
    {
        $fb = $this->fb();
        $this->store($fb)->beginGenerationRun('PLAN_A', 'V1');

        $write = null;
        foreach ($fb->written as $w) {
            if ($w['collection'] === 'timetableVersions') $write = $w;
        }
        $this->assertNotNull($write);
        $this->assertTrue($write['merge'],
            'a replace-write would destroy effectiveFrom, status and lineage');

        $this->assertSame('2026-04-01', $fb->docs[self::DOC]['effectiveFrom'],
            'the effective range must survive a generation run');
        $this->assertSame('draft', $fb->docs[self::DOC]['status']);
    }

    // ── published versions are not generated into ────────────────────────

    public function test_a_published_version_cannot_be_generated_into(): void
    {
        foreach (['published', 'superseded'] as $status) {
            $fb = $this->fb($status);
            try {
                $this->store($fb)->beginGenerationRun('PLAN_A', 'V1');
                $this->fail("generating into a '{$status}' version must be refused");
            } catch (Service_exception $e) {
                $this->assertMatchesRegularExpression('~new version~i', $e->getMessage());
            }
            $this->assertSame([], $fb->written,
                'a refused run must not stamp anything');
        }
    }

    public function test_generating_into_a_missing_version_is_refused_by_reason(): void
    {
        $fb = new FakeGenRunFirestore();   // nothing stored

        try {
            $this->store($fb)->beginGenerationRun('PLAN_A', 'V1');
            $this->fail('a missing version must be refused');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~does not exist~i', $e->getMessage(),
                'a missing version must not be reported as published');
        }
    }

    // ── an incomplete candidate can never be live ────────────────────────

    /**
     * Belt and braces. Resolution only ever considers PUBLISHED versions, so an
     * incomplete candidate cannot reach a teacher even if every guard above were
     * removed. Worth proving, because it is the last line of defence.
     */
    public function test_an_incomplete_candidate_can_never_resolve_as_live(): void
    {
        $partial = [[
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => 'PLAN_A', 'versionId' => 'V1',
            'status' => 'candidate',
            'generation' => ['state' => 'failed'],
            'effectiveFrom' => '2026-04-01', 'effectiveTo' => '',
        ]];

        $r = Timetable_plan_service::resolveForDate($partial, '2026-07-10');
        $this->assertSame('none', $r['status'],
            'a half-generated candidate must never be resolvable as the live '
            . 'timetable for a date');
    }

    // ── read integrity is not weakened by the new path ───────────────────

    public function test_a_complete_candidate_still_refuses_an_unreadable_day_set(): void
    {
        $fb = $this->fb();
        $store = $this->store($fb);
        $runId = $store->beginGenerationRun('PLAN_A', 'V1');
        $store->completeGenerationRun('PLAN_A', 'V1', $runId, 5);

        $fb->listThrows = true;   // days unreadable even though the run finished

        $this->expectException(Service_exception::class);
        $store->readCompleteCandidate('PLAN_A', 'V1');
    }

    // ── phase discipline ─────────────────────────────────────────────────

    public function test_this_phase_still_touches_neither_live_data_nor_publishing(): void
    {
        foreach (['Timetable_plan_store.php', 'Timetable_plan_service.php'] as $f) {
            $src = (string) file_get_contents(__DIR__ . '/../../application/libraries/' . $f);
            $code = (string) preg_replace('~/\*.*?\*/~s', '', $src);
            $code = (string) preg_replace('~^\s*//.*$~m', '', $code);

            // The store now names the legacy collection once, as the constant the
            // projection uses. The pure resolution service must still never
            // mention it at all.
            $allowed = ($f === 'Timetable_plan_store.php') ? 1 : 0;
            $this->assertSame($allowed, substr_count($code, "'timetables'"),
                "{$f}: the legacy collection may be named only by the constant the "
                . 'projection goes through, and not at all by the pure resolver');
            // Publishing now exists; the owner decided the policy this clause
            // guarded. The replacement is stricter: it must be ONE-WAY.
            foreach (['function unpublish', 'function revertPublish',
                      'function editPublished'] as $backdoor) {
                $this->assertStringNotContainsString($backdoor, $code,
                    "{$f}: published timetables are immutable, so {$backdoor}() "
                    . 'must not exist');
            }
        }
    }
}
