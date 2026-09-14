<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__ . '/../../system/');
if (!defined('APPPATH'))  define('APPPATH',  __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Timetable_plan_store.php';

/**
 * Fake Firestore. Lets a test choose between a working read, a FAILED read, and
 * a saturated one — the three cases the old architecture collapsed into a single
 * empty array.
 */
class FakeTimetableFirestore
{
    public array $rows = [];
    public bool  $readThrows  = false;
    public bool  $writeReturnsFalse = false;
    public bool  $writeThrows = false;
    public array $written = [];

    public function firestoreQueryOrFail($collection, $conditions = [], $orderBy = null, $dir = 'ASC', $limit = null)
    {
        if ($this->readThrows) {
            throw new \RuntimeException('QUERY_FAILED:' . $collection . ':UNAVAILABLE');
        }
        // Honour the limit exactly as Firestore does. A fake that ignores it
        // makes the cap+1 saturation probe untestable: dropping the probe would
        // still return every row and the truncation check would fire anyway.
        return $limit === null ? $this->rows : array_slice($this->rows, 0, $limit);
    }

    public function firestoreSet($collection, $docId, $data, $merge = true)
    {
        if ($this->writeThrows) throw new \RuntimeException('boom');
        $this->written[$docId] = $data;
        return $this->writeReturnsFalse ? false : true;
    }
}

/**
 * PHASE 2 — persistence for the authoritative timetable model.
 *
 * The property under test is the one the whole programme exists for:
 *
 *   A FAILED READ MUST NEVER LOOK LIKE "THIS SCHOOL HAS NO TIMETABLE".
 *
 * Resolution is the worst possible place for that ambiguity. `firestoreQuery()`
 * returns [] for a missing index, a 403, a 429, a network blip and an
 * uninitialised client alike — identical to a school with nothing scheduled. A
 * caller that cannot tell those apart concludes "no timetable today" and acts
 * on it.
 *
 * These tests drive the real store against a fake Firestore, so they check what
 * it DOES, not what its source says.
 */
final class TimetablePlanStoreTest extends TestCase
{
    private function row(array $over = []): array
    {
        return array_merge([
            'schoolId' => 'SCH_A', 'session' => '2026-2027',
            'planId' => 'PLAN_REG', 'planName' => 'Regular',
            'versionId' => 'V1', 'status' => 'published',
            'effectiveFrom' => '2026-04-01', 'effectiveTo' => '',
        ], $over);
    }

    private function store(FakeTimetableFirestore $fb): Timetable_plan_store
    {
        return (new Timetable_plan_store())->init($fb, 'SCH_A', '2026-2027', 'STA0001');
    }

    // ── the property this phase exists to guarantee ──────────────────────

    public function test_a_failed_read_is_unreadable_not_none(): void
    {
        $fb = new FakeTimetableFirestore();
        $fb->readThrows = true;

        $r = $this->store($fb)->resolveForDate('2026-07-10');

        $this->assertSame('unreadable', $r['status'],
            'a failed read reported as "none" would tell the caller no timetable '
            . 'applies today — the exact silent-wrong-answer this programme removes');
        $this->assertNull($r['version']);
        $this->assertStringContainsString('could not be determined', $r['explain']);
    }

    /** And the honest empty case must still be distinguishable from that. */
    public function test_a_proven_empty_store_is_none(): void
    {
        $fb = new FakeTimetableFirestore();
        $fb->rows = [];

        $r = $this->store($fb)->resolveForDate('2026-07-10');

        $this->assertSame('none', $r['status'],
            'an empty-but-successful read is a PROVEN absence and must stay '
            . 'distinguishable from a failure');
    }

    public function test_a_saturated_scan_refuses_rather_than_answering(): void
    {
        $fb = new FakeTimetableFirestore();
        // cap + 1 rows: the probe that makes truncation detectable
        $fb->rows = array_fill(0, Timetable_plan_store::VERSION_SCAN_LIMIT + 1, $this->row());

        $r = $this->store($fb)->resolveForDate('2026-07-10');

        $this->assertSame('unreadable', $r['status'],
            'resolving from a truncated set could return the wrong timetable, or '
            . 'none at all; saturation is "cannot prove", not an answer');
    }

    public function test_a_working_read_resolves_and_explains(): void
    {
        $fb = new FakeTimetableFirestore();
        $fb->rows = [['id' => 'x', 'data' => $this->row()]];

        $r = $this->store($fb)->resolveForDate('2026-07-10');

        $this->assertSame('resolved', $r['status']);
        $this->assertSame('PLAN_REG', $r['version']['planId']);
        $this->assertStringContainsString('Regular', $r['explain'],
            'the resolver must say WHY this timetable applied, per the house '
            . 'explainability convention');
    }

    // ── tenant and session isolation ─────────────────────────────────────

    public function test_rows_from_another_school_or_session_never_resolve(): void
    {
        $fb = new FakeTimetableFirestore();
        $fb->rows = [
            ['data' => $this->row(['schoolId' => 'SCH_OTHER'])],
            ['data' => $this->row(['session'  => '2025-2026'])],
        ];

        $r = $this->store($fb)->resolveForDate('2026-07-10');

        $this->assertSame('none', $r['status'],
            'a timetable belonging to another school or session must never resolve '
            . 'as live — the query is scoped, but defence in depth is required '
            . 'because a mis-scoped row reaching resolution is a tenant leak');
    }

    public function test_it_reads_scoped_by_school_and_session(): void
    {
        $captured = null;
        $fb = new class extends FakeTimetableFirestore {
            public array $conds = [];
            public function firestoreQueryOrFail($collection, $conditions = [], $orderBy = null, $dir = 'ASC', $limit = null)
            {
                $this->conds = $conditions;
                return [];
            }
        };

        $this->store($fb)->resolveForDate('2026-07-10');

        $fields = array_map(fn($c) => $c[0], $fb->conds);
        $this->assertContains('schoolId', $fields, 'the read must be tenant-scoped');
        $this->assertContains('session',  $fields, 'the read must be session-scoped');
    }

    // ── writes: draft only, and never a false success ────────────────────

    /**
     * The publish lifecycle is a phase-4 owner decision. Until it is taken, no
     * code path here may produce a published version — including a caller who
     * simply asks for one.
     */
    public function test_a_caller_cannot_publish_through_the_draft_path(): void
    {
        $fb = new FakeTimetableFirestore();

        $res = $this->store($fb)->createDraftVersion([
            'planId' => 'P', 'versionId' => 'V1', 'status' => 'published',
            'effectiveFrom' => '2026-04-01',
        ]);

        $this->assertSame('draft', $res['version']['status'],
            'status must be forced to draft — publishing before the immutability '
            . 'decision would bake in the answer');
        $stored = array_values($fb->written)[0];
        $this->assertSame('draft', $stored['status'], 'the STORED status must be draft too');
    }

    public function test_the_caller_cannot_choose_the_tenant(): void
    {
        $fb = new FakeTimetableFirestore();

        $res = $this->store($fb)->createDraftVersion([
            'schoolId' => 'SCH_SOMEONE_ELSE', 'session' => '1999-2000',
            'planId' => 'P', 'versionId' => 'V1', 'effectiveFrom' => '2026-04-01',
        ]);

        $this->assertSame('SCH_A', $res['version']['schoolId'],
            'tenant must come from the initialised store, never from the payload');
        $this->assertSame('2026-2027', $res['version']['session']);
        $this->assertArrayHasKey('SCH_A_2026-2027_P_V1', $fb->written,
            'the docId must be built from the store tenant, not the payload');
    }

    public function test_an_invalid_draft_is_refused_before_any_write(): void
    {
        $fb = new FakeTimetableFirestore();

        $this->expectException(Service_exception::class);
        try {
            $this->store($fb)->createDraftVersion([
                'planId' => 'P', 'versionId' => 'V1', 'effectiveFrom' => '',
            ]);
        } finally {
            $this->assertSame([], $fb->written,
                'validation must run before persistence, not after');
        }
    }

    /**
     * firestoreSet returns a bool and does not throw. An ignored false would
     * report a draft that does not exist — the false-success shape this
     * codebase has already been cleared of elsewhere.
     */
    public function test_a_refused_write_is_not_reported_as_success(): void
    {
        $fb = new FakeTimetableFirestore();
        $fb->writeReturnsFalse = true;

        $this->expectException(Service_exception::class);
        $this->store($fb)->createDraftVersion([
            'planId' => 'P', 'versionId' => 'V1', 'effectiveFrom' => '2026-04-01',
        ]);
    }

    public function test_a_throwing_write_is_also_refused(): void
    {
        $fb = new FakeTimetableFirestore();
        $fb->writeThrows = true;

        $this->expectException(Service_exception::class);
        $this->store($fb)->createDraftVersion([
            'planId' => 'P', 'versionId' => 'V1', 'effectiveFrom' => '2026-04-01',
        ]);
    }

    public function test_an_uninitialised_store_refuses_rather_than_guessing(): void
    {
        $bare = new Timetable_plan_store();
        $this->assertFalse($bare->isReady());

        // Must fail on the readiness check, not by tripping over a null client
        // inside the try and surfacing as a generic "could not be read". The
        // operator needs to know the store was never set up for a school.
        try {
            $bare->listVersions();
            $this->fail('an uninitialised store must refuse');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~not initialised~i', $e->getMessage(),
                'the refusal must name the real cause — an uninitialised store is a '
                . 'wiring bug, not a transient read failure, and must not be reported '
                . 'as one');
        }
    }

    // ── phase discipline ─────────────────────────────────────────────────

    /**
     * Phase 2 is additive ONLY while it stays off the live collection. The
     * compatibility projection belongs to phase 5, and publishing to phase 4.
     */
    public function test_phase_two_never_touches_the_live_timetable(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_plan_store.php');
        $code = (string) preg_replace('~/\*.*?\*/~s', '', $src);
        $code = (string) preg_replace('~^\s*//.*$~m', '', $code);

        // The projection now deliberately connects the legacy collection, so the
        // old "must not appear at all" clause is replaced by the invariant that
        // actually matters: the legacy collection is DERIVED, not authoritative.
        $this->assertSame(1, substr_count($code, "'timetables'"),
            "the legacy collection name must appear exactly once, as the constant "
            . 'that documents its role — every other use must go through it');
        $this->assertStringContainsString("COLLECTION_LEGACY_TT = 'timetables'", $code,
            'the constant must say the collection is legacy, at every use site');

        // Only the projection may touch it, and it may never delete from it.
        foreach (explode('function ', $code) as $chunk) {
            if (strpos($chunk, 'self::COLLECTION_LEGACY_TT') === false) continue;
            $name = strtok($chunk, '(');
            $this->assertContains($name,
                ['projectPublishedVersion', '_existingLegacyDocs'],
                "{$name}() must not touch the legacy collection; only the "
                . 'projection may, or it becomes a second editable authority');
        }
        $this->assertStringNotContainsString('firestoreDelete(self::COLLECTION_LEGACY_TT', $code,
            'live teacher-visible documents must never be deleted as a side effect '
            . 'of projecting; orphans are reported for review');
        // Publishing now EXISTS — the owner decided the immutability policy this
        // clause previously guarded. What replaces it is stricter: publishing
        // must be one-way. There is no unpublish and no edit-in-place, because
        // either would make the published record something other than what was
        // actually published.
        foreach (['function unpublish', 'function revertPublish',
                  'function editPublished', 'function deleteVersion'] as $backdoor) {
            $this->assertStringNotContainsString($backdoor, $code,
                "published timetables are immutable; {$backdoor}() would be the "
                . 'mutable backdoor the version architecture exists to avoid');
        }
        $this->assertStringContainsString('function publishCandidate', $code,
            'a candidate that can never be published is not a lifecycle');
    }

    /** The pure layer must stay pure — phase 1's reversibility depends on it. */
    public function test_the_resolution_service_is_still_free_of_persistence(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_plan_service.php');

        foreach (['firestoreSet', 'firestoreQuery', 'firestoreGet'] as $io) {
            $this->assertStringNotContainsString($io, $src,
                "storage leaked into the pure resolver ({$io}) — the two layers "
                . 'must stay separable');
        }
    }
}
