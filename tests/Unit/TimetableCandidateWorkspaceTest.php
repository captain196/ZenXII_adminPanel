<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__ . '/../../system/');
if (!defined('APPPATH'))  define('APPPATH',  __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Timetable_plan_store.php';

/** Fake Firestore with a single-doc store, so version status can be controlled. */
class FakeCandidateFirestore
{
    public array $docs = [];          // docId => data
    public array $rows = [];          // query results
    public array $written = [];       // docId => [data, merge]
    public bool  $getThrows   = false;
    public bool  $listThrows  = false;
    public bool  $writeFalseFor = false;   // docId prefix to refuse
    public bool  $verdictWriteThrows = false;

    public function firestoreGetOrFail($collection, $docId)
    {
        if ($this->getThrows) throw new \RuntimeException('GET_FAILED');
        return $this->docs[$docId] ?? null;
    }

    public function firestoreQueryOrFail($collection, $conditions = [], $orderBy = null, $dir = 'ASC', $limit = null)
    {
        if ($this->listThrows) throw new \RuntimeException('QUERY_FAILED');
        return $limit === null ? $this->rows : array_slice($this->rows, 0, $limit);
    }

    public function firestoreSet($collection, $docId, $data, $merge = true)
    {
        if ($this->verdictWriteThrows && $collection === 'timetableVersions') {
            throw new \RuntimeException('verdict write failed');
        }
        $this->written[$docId] = ['data' => $data, 'merge' => $merge];
        if ($this->writeFalseFor && $collection === 'timetableVersionDays') return false;
        return true;
    }
}

/**
 * PHASE 3 — the isolated candidate workspace.
 *
 * This is the structural answer to ACAD-D26, which no amount of locking or
 * compare-and-swap on the live path can give.
 *
 * D26: generation preloads the timetable, computes for up to 300 seconds, then
 * writes the whole thing back. An administrator who saves an edit inside that
 * window has it silently overwritten, because generation and manual editing act
 * on THE SAME LIVE ARTIFACT. Patching the write narrows the window; it never
 * closes it.
 *
 * A candidate lives in its own collection. Generation writing there cannot
 * overwrite a live edit, because it never touches the document the administrator
 * is editing. The window is not narrowed — it stops existing.
 *
 * Two further guarantees this phase must hold:
 *
 *   - a published version is never edited in place (correct under EITHER answer
 *     to the phase-4 immutability decision, so it can be enforced now);
 *   - a manual edit clears the generator verdict, because a score is only true
 *     for the arrangement that produced it.
 */
final class TimetableCandidateWorkspaceTest extends TestCase
{
    private const SCHOOL = 'SCH_A';
    private const SESS   = '2026-2027';

    private function store(FakeCandidateFirestore $fb): Timetable_plan_store
    {
        return (new Timetable_plan_store())->init($fb, self::SCHOOL, self::SESS, 'STA0001');
    }

    private function withVersion(string $status = 'draft'): FakeCandidateFirestore
    {
        $fb = new FakeCandidateFirestore();
        $fb->docs[self::SCHOOL . '_' . self::SESS . '_PLAN_A_V1'] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => 'PLAN_A', 'versionId' => 'V1', 'status' => $status,
            'effectiveFrom' => '2026-04-01', 'effectiveTo' => '',
            'score' => 1240, 'validationStale' => false,
        ];
        return $fb;
    }

    private function periods(): array
    {
        // The LIVE period shape, unchanged — this is what makes the phase-5
        // projection a re-parenting rather than a reshape.
        return [[
            'periodNumber' => 1, 'subject' => 'Mathematics', 'teacher' => 'R Rao',
            'teacherId' => 'STA0112', 'startTime' => '09:00AM', 'endTime' => '09:45AM',
            'room' => '', 'type' => 'class',
        ]];
    }

    // ── the D26 structural claim ─────────────────────────────────────────

    public function test_a_candidate_day_never_touches_the_live_collection(): void
    {
        $fb = $this->withVersion();
        $this->store($fb)->saveCandidateDay('PLAN_A', 'V1', 'Class 8/Section A', 'Monday', $this->periods(), false);

        $this->assertNotEmpty($fb->written, 'nothing was written at all');
        foreach (array_keys($fb->written) as $docId) {
            $this->assertStringStartsWith(self::SCHOOL . '_' . self::SESS . '_PLAN_A_V1', $docId,
                'every candidate write must be scoped to its version. A write outside '
                . 'that namespace is the D26 defect returning: generation and manual '
                . 'editing sharing one artifact.');
        }
    }

    /** The candidate must carry the live period shape, or phase 5 becomes a reshape. */
    public function test_the_candidate_preserves_the_live_period_shape(): void
    {
        $fb = $this->withVersion();
        $res = $this->store($fb)->saveCandidateDay(
            'PLAN_A', 'V1', 'Class 8/Section A', 'Monday', $this->periods(), false);

        $p = $res['day']['periods'][0];
        foreach (['periodNumber', 'subject', 'teacher', 'teacherId',
                  'startTime', 'endTime', 'room', 'type'] as $field) {
            $this->assertArrayHasKey($field, $p,
                "the candidate dropped '{$field}' from the period shape — the phase-5 "
                . 'projection depends on this being a re-parenting, not a reshape, '
                . 'because the Teacher app reads the live documents directly');
        }
    }

    // ── published versions are not edited in place ───────────────────────

    /**
     * Correct under EITHER answer to the immutability decision, which is why it
     * can be enforced before that decision is taken.
     */
    public function test_a_published_version_cannot_be_edited_directly(): void
    {
        foreach (['published', 'superseded'] as $status) {
            $fb = $this->withVersion($status);

            try {
                $this->store($fb)->saveCandidateDay(
                    'PLAN_A', 'V1', 'Class 8/Section A', 'Monday', $this->periods());
                $this->fail("editing a '{$status}' version should have been refused");
            } catch (Service_exception $e) {
                $this->assertMatchesRegularExpression('~new version~i', $e->getMessage(),
                    'the refusal must tell the operator what to do instead');
            }

            $this->assertSame([], $fb->written,
                "a refused edit on a '{$status}' version must write nothing at all");
        }
    }

    public function test_editing_a_missing_version_is_refused(): void
    {
        $fb = new FakeCandidateFirestore();   // no version stored

        try {
            $this->store($fb)->saveCandidateDay(
                'PLAN_A', 'V1', 'Class 8/Section A', 'Monday', $this->periods());
            $this->fail('editing a version that does not exist must be refused');
        } catch (Service_exception $e) {
            // Check the REASON, not merely that something was thrown. Without the
            // explicit null guard a missing version falls through to the status
            // check and is reported as "already published" — which sends the
            // operator to create a new version from one that never existed.
            $this->assertMatchesRegularExpression('~does not exist~i', $e->getMessage(),
                'a missing version must be reported as missing, not as published');
        }
        $this->assertSame([], $fb->written);
    }

    /**
     * A version document belonging to another tenant must read as ABSENT. The
     * docId is composed from the store tenant, so this only bites on a collision
     * or a mis-scoped historical write — but if it ever happens, the alternative
     * is editing another school's timetable.
     */
    public function test_a_version_from_another_tenant_reads_as_absent(): void
    {
        foreach ([['schoolId' => 'SCH_OTHER'], ['session' => '2025-2026']] as $over) {
            $fb = $this->withVersion();
            $docId = self::SCHOOL . '_' . self::SESS . '_PLAN_A_V1';
            $fb->docs[$docId] = array_merge($fb->docs[$docId], $over);

            $this->assertNull($this->store($fb)->getVersion('PLAN_A', 'V1'),
                'a version carrying another tenant or session must never be returned');

            try {
                $this->store($fb)->saveCandidateDay(
                    'PLAN_A', 'V1', 'Class 8/Section A', 'Monday', $this->periods());
                $this->fail('writing into a foreign-tenant version must be refused');
            } catch (Service_exception $e) {
                $this->assertMatchesRegularExpression('~does not exist~i', $e->getMessage());
            }
            $this->assertSame([], $fb->written,
                'no day may be written into a version that is not this tenant\'s');
        }
    }

    /** A failed version read must not be mistaken for "no such version". */
    public function test_a_failed_version_read_refuses_rather_than_creating(): void
    {
        $fb = $this->withVersion();
        $fb->getThrows = true;

        try {
            $this->store($fb)->saveCandidateDay(
                'PLAN_A', 'V1', 'Class 8/Section A', 'Monday', $this->periods());
            $this->fail('a failed version read must refuse');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~could not be read~i', $e->getMessage(),
                'a read failure must be reported as a failure, not as an absent version');
        }
        $this->assertSame([], $fb->written);
    }

    // ── a manual edit invalidates the generator verdict ──────────────────

    public function test_a_manual_edit_clears_the_score_and_marks_validation_stale(): void
    {
        $fb = $this->withVersion();
        $this->store($fb)->saveCandidateDay(
            'PLAN_A', 'V1', 'Class 8/Section A', 'Monday', $this->periods(), true);

        $verdict = $fb->written[self::SCHOOL . '_' . self::SESS . '_PLAN_A_V1'] ?? null;
        $this->assertNotNull($verdict,
            'a manual edit must invalidate the generator verdict — Untis built its '
            . 'diagnosis tool because discrepancies arise "in particular after manual '
            . 'corrections"');
        $this->assertTrue($verdict['data']['validationStale']);
        $this->assertNull($verdict['data']['score'],
            'a score is only true for the arrangement that produced it');
        $this->assertTrue($verdict['merge'],
            'the verdict update must MERGE — replacing the version document would '
            . 'destroy its effective dates and lineage');
    }

    /** Generated writes are not manual edits and must not clear the verdict. */
    public function test_a_generated_write_leaves_the_verdict_intact(): void
    {
        $fb = $this->withVersion();
        $this->store($fb)->saveCandidateDay(
            'PLAN_A', 'V1', 'Class 8/Section A', 'Monday', $this->periods(), false);

        $this->assertArrayNotHasKey(self::SCHOOL . '_' . self::SESS . '_PLAN_A_V1', $fb->written,
            'the generator writing its own output must not invalidate the score it '
            . 'is about to record');
    }

    /**
     * The day is already stored safely when the verdict flag fails, so failing
     * the whole edit would be worse for the operator. It must not, however, be
     * silent.
     */
    public function test_a_failed_verdict_update_does_not_lose_the_saved_day(): void
    {
        $fb = $this->withVersion();
        $fb->verdictWriteThrows = true;

        $res = $this->store($fb)->saveCandidateDay(
            'PLAN_A', 'V1', 'Class 8/Section A', 'Monday', $this->periods(), true);

        $this->assertArrayHasKey('docId', $res,
            'the edit itself succeeded and must be reported as such');
        $this->assertStringContainsString('_Class 8_Section A_Monday', $res['docId']);
    }

    // ── write integrity ──────────────────────────────────────────────────

    public function test_a_refused_day_write_is_not_reported_as_success(): void
    {
        $fb = $this->withVersion();
        $fb->writeFalseFor = true;

        $this->expectException(Service_exception::class);
        $this->store($fb)->saveCandidateDay(
            'PLAN_A', 'V1', 'Class 8/Section A', 'Monday', $this->periods());
    }

    // ── reading candidates back ──────────────────────────────────────────

    public function test_a_failed_candidate_read_refuses_rather_than_looking_empty(): void
    {
        $fb = $this->withVersion();
        $fb->listThrows = true;

        $this->expectException(Service_exception::class);
        $this->store($fb)->listCandidateDays('PLAN_A', 'V1');
    }

    public function test_a_saturated_candidate_refuses_rather_than_being_validated_partial(): void
    {
        $fb = $this->withVersion();
        $row = ['data' => [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => 'PLAN_A', 'versionId' => 'V1',
            'classSection' => 'C/S', 'day' => 'Monday', 'periods' => [],
        ]];
        $fb->rows = array_fill(0, Timetable_plan_store::DAY_SCAN_LIMIT + 1, $row);

        $this->expectException(Service_exception::class);
        $this->store($fb)->listCandidateDays('PLAN_A', 'V1');
    }

    public function test_candidate_days_from_another_tenant_are_dropped(): void
    {
        $fb = $this->withVersion();
        $fb->rows = [
            ['data' => ['schoolId' => 'SCH_OTHER', 'session' => self::SESS,
                        'planId' => 'PLAN_A', 'versionId' => 'V1', 'day' => 'Monday']],
            ['data' => ['schoolId' => self::SCHOOL, 'session' => '2025-2026',
                        'planId' => 'PLAN_A', 'versionId' => 'V1', 'day' => 'Monday']],
        ];

        $this->assertSame([], $this->store($fb)->listCandidateDays('PLAN_A', 'V1'),
            'a candidate day from another school or session must never be read back '
            . 'into this tenant');
    }

    public function test_the_candidate_read_is_scoped_to_one_version(): void
    {
        $fb = new class extends FakeCandidateFirestore {
            public array $conds = [];
            public function firestoreQueryOrFail($collection, $conditions = [], $orderBy = null, $dir = 'ASC', $limit = null)
            {
                $this->conds = $conditions;
                return [];
            }
        };
        $this->store($fb)->listCandidateDays('PLAN_A', 'V1');

        $fields = array_map(fn($c) => $c[0], $fb->conds);
        foreach (['schoolId', 'session', 'planId', 'versionId'] as $f) {
            $this->assertContains($f, $fields,
                "the candidate read must be scoped by {$f}, or one candidate would "
                . 'read another candidate\'s days');
        }
    }

    // ── run attribution (policy-neutral) ─────────────────────────────────

    /**
     * Which run produced a day.
     *
     * Needed under EVERY candidate-preservation policy, so recording it decides
     * nothing. Without it, days from a failed run and days from an earlier
     * successful one sit side by side and cannot be told apart — the version
     * knows it is incomplete (ACAD-D24) but nothing knows WHICH days are suspect.
     */
    public function test_a_generated_day_records_the_run_that_produced_it(): void
    {
        $fb = $this->withVersion();
        $res = $this->store($fb)->saveCandidateDay(
            'PLAN_A', 'V1', 'Class 8/Section A', 'Monday',
            $this->periods(), false, 'GEN_20260828_abc123');

        $this->assertSame('GEN_20260828_abc123', $res['day']['generationRunId']);

        $stored = $fb->written[$res['docId']]['data'];
        $this->assertSame('GEN_20260828_abc123', $stored['generationRunId'],
            'the attribution must be persisted, not merely returned');
    }

    /** A hand edit belongs to no run, and must say so rather than inherit one. */
    public function test_a_manual_edit_records_no_run(): void
    {
        $fb = $this->withVersion();
        $res = $this->store($fb)->saveCandidateDay(
            'PLAN_A', 'V1', 'Class 8/Section A', 'Monday', $this->periods(), true);

        $this->assertNull($res['day']['generationRunId'],
            'a hand edit has no generation run; inheriting one would attribute human '
            . 'work to the generator');
        $this->assertTrue($res['day']['manuallyEdited']);
    }

    /**
     * Attribution and the preservation question are separate. This field records
     * WHERE a day came from; it must not be read as WHETHER it survives the next
     * run. That decision is still open.
     */
    public function test_attribution_does_not_encode_a_preservation_rule(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_plan_store.php');
        $code = (string) preg_replace('~/\*.*?\*/~s', '', $src);
        $code = (string) preg_replace('~^\s*//.*$~m', '', $code);

        $i = strpos($code, 'generationRunId');
        $this->assertNotFalse($i);

        // No branch anywhere may currently decide anything from this field.
        $this->assertSame(0, preg_match_all('~if\s*\([^)]*generationRunId~', $code),
            'a decision is being taken from the run id — candidate preservation '
            . 'semantics are an open owner decision and must not be encoded yet');
    }
    // ── phase discipline ─────────────────────────────────────────────────

    public function test_phase_three_still_never_touches_the_live_timetable(): void
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
}
