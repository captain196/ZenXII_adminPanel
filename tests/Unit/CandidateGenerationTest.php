<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__ . '/../../system/');
if (!defined('APPPATH'))  define('APPPATH',  __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../../application/libraries/Timetable_service.php';
require_once __DIR__ . '/../../application/libraries/Timetable_plan_store.php';

/**
 * Firestore double shared by the service and the store, so a candidate run can
 * be driven end to end and every write inspected.
 */
class FakeCandidateGenFirestore
{
    public array $docs = [];      // docId => data (versions)
    public array $writes = [];    // [collection, docId, data, merge]
    public int   $failDayWriteAt = 0;   // 1-based day index to fail on, 0 = never

    private int $dayWrites = 0;

    public function firestoreGetOrFail($collection, $docId)
    {
        return $this->docs[$docId] ?? null;
    }

    public function firestoreQueryOrFail($collection, $conditions = [], $orderBy = null, $dir = 'ASC', $limit = null)
    {
        return [];
    }

    public function firestoreSet($collection, $docId, $data, $merge = true)
    {
        if ($collection === 'timetableVersionDays') {
            $this->dayWrites++;
            if ($this->failDayWriteAt > 0 && $this->dayWrites === $this->failDayWriteAt) {
                throw new \RuntimeException('day write refused');
            }
        }
        if ($collection === 'timetableVersions') {
            $this->docs[$docId] = array_merge($this->docs[$docId] ?? [], $data);
        }
        $this->writes[] = ['collection' => $collection, 'docId' => $docId,
                           'data' => $data, 'merge' => $merge];
        return true;
    }

    public function collectionsWritten(): array
    {
        return array_values(array_unique(array_column($this->writes, 'collection')));
    }
}

/**
 * PHASE 7 — candidate generation, Option E.
 *
 * The approved model: regeneration NEVER rewrites an edited candidate. It
 * creates a new one and generates entirely inside it.
 *
 * ── WHY THIS REMOVES ACAD-D26 FROM THIS PATH ────────────────────────────
 *
 * D26 exists because the live generator preloads, computes for up to 300
 * seconds, then writes back over an artifact an administrator may have edited
 * meanwhile. Here the destination is a version created moments earlier that
 * nothing else references, so there is no shared artifact to overwrite. The
 * window does not exist on this path — it is not merely narrower.
 *
 * The OLD live generator is untouched and still has the window, so D26 is NOT
 * globally closed. These tests prove the new path, not the old one.
 *
 * ── WHY ZENX NEEDS NO PER-CELL LOCKS ────────────────────────────────────
 *
 * aSc and Untis protect manual work with explicit locks because they optimise
 * ONE working timetable in place — a lock is their only mechanism. ZenX has
 * versions, so protection comes from never writing to the source at all. aSc
 * additionally warns that over-locking can make a timetable ungeneratable.
 */
final class CandidateGenerationTest extends TestCase
{
    private const SCHOOL = 'SCH_A';
    private const SESS   = '2026-2027';

    private function fb(): FakeCandidateGenFirestore
    {
        return new FakeCandidateGenFirestore();
    }

    private function store(FakeCandidateGenFirestore $fb): Timetable_plan_store
    {
        return (new Timetable_plan_store())->init($fb, self::SCHOOL, self::SESS, 'STA0001');
    }

    /**
     * A real service with ONLY its input loading stubbed.
     *
     * _loadGenerationInputs() does genuine Firestore + SAS I/O, so it is the one
     * seam replaced. Placement, composition, candidate creation, the run
     * lifecycle and every write below it are the real implementation.
     *
     * This exists because mutation testing proved it had to: while the
     * orchestrator could not be executed, four mutations INSIDE it survived
     * unnoticed — a failed run that never marked itself failed, a run that never
     * completed, days written with no attribution, and generated days marked as
     * hand edits.
     */
    private function svc(FakeCandidateGenFirestore $fb, array $inputs): Timetable_service
    {
        $svc = new class extends Timetable_service {
            public array $stub = [];
            protected function _loadGenerationInputs(string $session, string $scopeClass): array
            {
                return $this->stub;
            }
        };
        $svc->stub = $inputs;

        foreach (['schoolId' => self::SCHOOL, 'session' => self::SESS,
                  'adminId' => 'STA0001', 'adminName' => 'R Rao',
                  'firebase' => $fb] as $p => $v) {
            $r = new ReflectionProperty(Timetable_service::class, $p);
            $r->setAccessible(true);
            $r->setValue($svc, $v);
        }
        return $svc;
    }

    private function inputs(): array
    {
        return [
            'periodsPerDay' => 2,
            'workingDays'   => ['Monday', 'Tuesday'],
            'periodTimes'   => [['start' => '09:00AM', 'end' => '09:45AM'],
                                ['start' => '09:45AM', 'end' => '10:30AM']],
            'sections'      => [['className' => 'Class 8', 'section' => 'Section A',
                                 'key' => 'Class 8/Section A']],
            'sectionAssignments' => ['Class 8/Section A' => [
                ['subject' => 'Mathematics', 'code' => 'M', 'teacherId' => 'STA001',
                 'teacherName' => 'R Rao', 'periodsPerWeek' => 2],
            ]],
        ];
    }

    // ── the orchestrator, driven for real ────────────────────────────────

    public function test_a_full_candidate_run_completes_and_attributes_every_day(): void
    {
        $fb = $this->fb();
        $store = $this->store($fb);
        $svc = $this->svc($fb, $this->inputs());

        $res = $svc->generateIntoCandidate($store, 'PLAN_A');

        $this->assertSame('candidate', $res['mode']);
        $this->assertStringStartsWith('CAND_', $res['versionId']);
        $this->assertSame(2, $res['days_written'], 'both working days must be written');

        $versionDoc = $fb->docs[self::SCHOOL . '_' . self::SESS . '_PLAN_A_' . $res['versionId']];
        $this->assertTrue(Timetable_plan_service::isGenerationComplete($versionDoc),
            'a successful run must mark the candidate complete, or it stays unusable');
        $this->assertSame(2, $versionDoc['generation']['daysWritten']);

        // Every generated day carries its run id and is not a hand edit.
        $dayWrites = array_values(array_filter($fb->writes,
            fn($w) => $w['collection'] === 'timetableVersionDays'));
        $this->assertCount(2, $dayWrites);
        foreach ($dayWrites as $w) {
            $this->assertSame($res['generationId'], $w['data']['generationRunId'],
                'a generated day must be attributable to the run that produced it');
            $this->assertFalse($w['data']['manuallyEdited'],
                'generated content must never be recorded as a hand edit');
            $this->assertStringContainsString('_' . $res['versionId'] . '_', $w['docId'],
                'every day must land inside the NEW candidate');
        }
    }

    /** ACAD-D24 on the real path: a mid-run failure must mark and refuse. */
    public function test_a_mid_run_write_failure_marks_the_candidate_failed(): void
    {
        $fb = $this->fb();
        $fb->failDayWriteAt = 2;            // second day write refuses
        $store = $this->store($fb);
        $svc = $this->svc($fb, $this->inputs());

        try {
            $svc->generateIntoCandidate($store, 'PLAN_A');
            $this->fail('a failed day write must stop the run');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~not changed~i', $e->getMessage(),
                'the operator must be told their existing timetable is intact');
        }

        // Find the candidate that was created and check it is marked failed.
        $vid = null;
        foreach ($fb->docs as $docId => $d) {
            if (($d['status'] ?? '') === 'candidate') $vid = $d['versionId'];
        }
        $this->assertNotNull($vid);

        $doc = $fb->docs[self::SCHOOL . '_' . self::SESS . '_PLAN_A_' . $vid];
        $this->assertSame('failed', $doc['generation']['state'],
            'a run that stopped part-way must record itself as failed');
        $this->assertFalse(Timetable_plan_service::isGenerationComplete($doc));
        $this->assertSame(1, $doc['generation']['daysWritten'],
            'the days already written are counted, not silently discarded');

        try {
            $store->readCompleteCandidate('PLAN_A', $vid);
            $this->fail('a partially generated candidate must be unusable');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~not generated completely~i', $e->getMessage());
        }
    }

    /** The live collection must never appear, even on the failure path. */
    public function test_a_full_run_never_writes_the_live_collection(): void
    {
        $fb = $this->fb();
        $svc = $this->svc($fb, $this->inputs());
        $svc->generateIntoCandidate($this->store($fb), 'PLAN_A');

        $this->assertSame(['timetableVersions', 'timetableVersionDays'],
            $fb->collectionsWritten(),
            'ACAD-D26 is removed from this path precisely because generation never '
            . 'touches the artifact an administrator may be editing');
    }

    /** A refused candidate creation must not be reported as success. */
    public function test_a_refused_candidate_creation_is_not_reported_as_success(): void
    {
        $fb = new class extends FakeCandidateGenFirestore {
            public function firestoreSet($collection, $docId, $data, $merge = true)
            {
                if ($collection === 'timetableVersions') return false;
                return parent::firestoreSet($collection, $docId, $data, $merge);
            }
        };

        $this->expectException(Service_exception::class);
        $this->store($fb)->createGenerationCandidate('PLAN_A');
    }

    // ── Option E: the source candidate is never written to ───────────────

    public function test_regeneration_creates_a_new_candidate_and_never_writes_the_source(): void
    {
        $fb = $this->fb();
        $store = $this->store($fb);

        // An existing, hand-edited candidate.
        $srcDoc = self::SCHOOL . '_' . self::SESS . '_PLAN_A_V_OLD';
        $fb->docs[$srcDoc] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => 'PLAN_A', 'versionId' => 'V_OLD', 'status' => 'candidate',
            'effectiveFrom' => '2026-04-01', 'effectiveTo' => '',
        ];
        $before = $fb->docs[$srcDoc];

        $created = $store->createGenerationCandidate('PLAN_A', 'V_OLD');

        $this->assertNotSame('V_OLD', $created['versionId'],
            'regeneration must produce a NEW version, never reuse the source');
        $this->assertStringStartsWith('CAND_', $created['versionId']);

        $this->assertSame($before, $fb->docs[$srcDoc],
            'the source candidate document must be byte-identical afterwards — under '
            . 'Option E it is protected by never being written to, not by a rule');

        foreach ($fb->writes as $w) {
            $this->assertNotSame($srcDoc, $w['docId'],
                'a write targeted the source candidate');
        }
    }

    public function test_each_candidate_gets_a_distinct_identity(): void
    {
        $fb = $this->fb();
        $store = $this->store($fb);

        $a = $store->createGenerationCandidate('PLAN_A');
        $b = $store->createGenerationCandidate('PLAN_A');

        $this->assertNotSame($a['versionId'], $b['versionId'],
            'two runs must not collide on one candidate id, or the second would '
            . 'overwrite the first');
    }

    /** Provenance must be real, or it is a claim nobody verified. */
    public function test_regenerating_from_a_missing_source_is_refused(): void
    {
        $fb = $this->fb();

        try {
            $this->store($fb)->createGenerationCandidate('PLAN_A', 'V_GHOST');
            $this->fail('regenerating from a non-existent version must be refused');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~could not be found~i', $e->getMessage());
        }
        $this->assertSame([], $fb->writes, 'nothing may be created on a refused regeneration');
    }

    /** Provenance is recorded but confers nothing. */
    public function test_lineage_is_recorded_as_provenance_only(): void
    {
        $fb = $this->fb();
        $store = $this->store($fb);
        $srcDoc = self::SCHOOL . '_' . self::SESS . '_PLAN_A_V_OLD';
        $fb->docs[$srcDoc] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => 'PLAN_A', 'versionId' => 'V_OLD', 'status' => 'candidate',
            'effectiveFrom' => '2026-04-01', 'effectiveTo' => '',
        ];

        $created = $store->createGenerationCandidate('PLAN_A', 'V_OLD');
        $this->assertSame('V_OLD', $created['version']['regeneratedFrom']);

        // The source keeps its own status: nothing was superseded or invalidated.
        $this->assertSame('candidate', $fb->docs[$srcDoc]['status'],
            'recording provenance must not change the source. What a lineage link '
            . 'MEANS beyond provenance is an open product question.');
        $this->assertArrayNotHasKey('supersededBy', $fb->docs[$srcDoc]);
    }

    public function test_a_fresh_candidate_records_no_lineage(): void
    {
        $fb = $this->fb();
        $created = $this->store($fb)->createGenerationCandidate('PLAN_A');

        $this->assertNull($created['version']['regeneratedFrom'],
            'a candidate generated from scratch has no source and must not claim one');
    }

    // ── tenant and session safety ────────────────────────────────────────

    public function test_the_caller_cannot_choose_tenant_session_or_status(): void
    {
        $fb = $this->fb();
        $created = $this->store($fb)->createGenerationCandidate('PLAN_A');

        $this->assertSame(self::SCHOOL, $created['version']['schoolId']);
        $this->assertSame(self::SESS,   $created['version']['session']);
        $this->assertSame('candidate',  $created['version']['status'],
            'a generation target must never be created already published');
        $this->assertStringStartsWith(self::SCHOOL . '_' . self::SESS . '_',
            $created['docId'],
            'identity comes from trusted server-side context, not from a payload');
    }

    /** A source belonging to another tenant must read as absent. */
    public function test_a_foreign_tenant_source_cannot_be_regenerated_from(): void
    {
        $fb = $this->fb();
        $srcDoc = self::SCHOOL . '_' . self::SESS . '_PLAN_A_V_OLD';
        $fb->docs[$srcDoc] = [
            'schoolId' => 'SCH_OTHER', 'session' => self::SESS,
            'planId' => 'PLAN_A', 'versionId' => 'V_OLD', 'status' => 'candidate',
            'effectiveFrom' => '2026-04-01', 'effectiveTo' => '',
        ];

        $this->expectException(Service_exception::class);
        $this->store($fb)->createGenerationCandidate('PLAN_A', 'V_OLD');
    }

    // ── the candidate path never touches live data ───────────────────────

    public function test_candidate_creation_writes_only_to_version_collections(): void
    {
        $fb = $this->fb();
        $store = $this->store($fb);
        $created = $store->createGenerationCandidate('PLAN_A');
        $runId = $store->beginGenerationRun('PLAN_A', $created['versionId']);
        $store->saveCandidateDay('PLAN_A', $created['versionId'],
            'Class 8/Section A', 'Monday',
            [['periodNumber' => 1, 'subject' => 'Mathematics', 'teacher' => 'R Rao',
              'teacherId' => 'STA001', 'startTime' => '09:00AM', 'endTime' => '09:45AM',
              'room' => '', 'type' => 'class']], false, $runId);

        $this->assertSame(['timetableVersions', 'timetableVersionDays'],
            $fb->collectionsWritten(),
            'the candidate path must never write the live timetables collection — '
            . 'that is what removes ACAD-D26 from this path');
    }

    // ── ACAD-D24 on the candidate path ───────────────────────────────────

    /**
     * A run that fails part-way must leave the candidate unusable, the previous
     * candidate untouched, and enough information to diagnose it.
     */
    public function test_a_partial_run_marks_the_candidate_failed_and_unusable(): void
    {
        $fb = $this->fb();
        $store = $this->store($fb);

        $created = $store->createGenerationCandidate('PLAN_A');
        $vid = $created['versionId'];
        $runId = $store->beginGenerationRun('PLAN_A', $vid);

        $day = [['periodNumber' => 1, 'subject' => 'X', 'teacher' => '', 'teacherId' => '',
                 'startTime' => '', 'endTime' => '', 'room' => '', 'type' => 'class']];

        $store->saveCandidateDay('PLAN_A', $vid, 'C/S', 'Monday', $day, false, $runId);
        $store->failGenerationRun('PLAN_A', $vid, $runId, 1, 'day 2 write refused');

        $doc = $fb->docs[self::SCHOOL . '_' . self::SESS . '_PLAN_A_' . $vid];
        $this->assertFalse(Timetable_plan_service::isGenerationComplete($doc));

        try {
            $store->readCompleteCandidate('PLAN_A', $vid);
            $this->fail('a partially generated candidate must not be readable as complete');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~not generated completely~i', $e->getMessage());
        }

        $this->assertSame('day 2 write refused', $doc['generation']['reason'],
            'the diagnosis must survive');
        $this->assertSame(1, $doc['generation']['daysWritten'],
            'the days already written are kept and counted, not silently deleted');
    }

    public function test_a_completed_run_is_readable_and_attributed(): void
    {
        $fb = $this->fb();
        $store = $this->store($fb);

        $created = $store->createGenerationCandidate('PLAN_A');
        $vid = $created['versionId'];
        $runId = $store->beginGenerationRun('PLAN_A', $vid);

        $res = $store->saveCandidateDay('PLAN_A', $vid, 'C/S', 'Monday',
            [['periodNumber' => 1]], false, $runId);
        $store->completeGenerationRun('PLAN_A', $vid, $runId, 1);

        $this->assertSame($runId, $res['day']['generationRunId'],
            'every generated day must be attributable to its run');
        $this->assertFalse($res['day']['manuallyEdited'],
            'generated content is not a hand edit');

        $read = $store->readCompleteCandidate('PLAN_A', $vid);
        $this->assertSame('complete', $read['version']['generation']['state']);
    }

    // ── the orchestrator wires the shared pieces, not copies ─────────────

    public function test_the_candidate_path_reuses_the_shared_algorithm(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_service.php');
        $i = strpos($src, 'public function generateIntoCandidate(');
        $this->assertNotFalse($i, 'generateIntoCandidate is gone');
        $e = strpos($src, "\n    private function ", $i);
        $body = substr($src, $i, $e - $i);

        foreach (['_loadGenerationInputs', '_placeGrid', '_composeGeneratedDays'] as $shared) {
            $this->assertStringContainsString('$this->' . $shared . '(', $body,
                "the candidate path must reuse {$shared}, not carry its own copy");
        }

        $code = (string) preg_replace('~^\s*//.*$~m', '', $body);
        foreach (['COLLECTION_TT', "'timetables'", '_commitLiveBatch', '_liveBatchOpsFrom'] as $live) {
            $this->assertStringNotContainsString($live, $code,
                "the candidate path referenced the live destination ({$live})");
        }
    }

    /** Still exactly one placement and one composition across the whole file. */
    public function test_no_second_generator_was_introduced(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_service.php');
        $code = (string) preg_replace('~/\*.*?\*/~s', '', $src);
        $code = (string) preg_replace('~^\s*//.*$~m', '', $code);

        $this->assertSame(1, preg_match_all('~private function _placeGrid\(~', $code));
        $this->assertSame(1, preg_match_all('~private function _composeGeneratedDays\(~', $code));
        // protected now, so the candidate orchestrator can be driven in a test.
        $this->assertSame(1, preg_match_all('~function _loadGenerationInputs\(~', $code));
        // The distinctive shape of the ONE placer: the tie collector. If a
        // second placement implementation appeared, this would match twice.
        $this->assertSame(1,
            preg_match_all('~\$dayCount === \$bestDay && \$p === \$bestPeriod~', $code),
            'a second placement implementation has appeared');

        // And the folded score must never come back. `dayCount * 100 + period`
        // encoded a priority as arithmetic — Timefold names this score folding —
        // and it silently inverted for any school with 100+ periods in a day.
        $this->assertSame(0, preg_match_all('~\$dayCount \* 100~', $code),
            'score folding has returned: express the ordering, do not multiply it');
    }

    /**
     * Composition on the candidate path must be handed a lookup that reports
     * nothing stored — a brand-new candidate is empty, so no preservation policy
     * is exercised or implied.
     */
    public function test_the_candidate_lookup_reports_nothing_stored(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_service.php');
        $i = strpos($src, 'public function generateIntoCandidate(');
        $e = strpos($src, "\n    private function ", $i);
        $body = substr($src, $i, $e - $i);

        $this->assertMatchesRegularExpression(
            '~\$nothingStored\s*=\s*fn\(string \$sectionKey, string \$day\)\s*=>\s*null;~', $body,
            'a new candidate contains nothing; the lookup must say so rather than '
            . 'importing the live D23 rule');
        $this->assertMatchesRegularExpression(
            '~_composeGeneratedDays\(\s*\n?\s*\$grid, \$inputs\[\x27periodTimes\x27\], \$nothingStored, false,~', $body,
            'force-overwrite must be false and the lookup must be the empty one');
    }

    // ── the live path is untouched ───────────────────────────────────────

    public function test_the_live_generator_still_uses_the_live_destination(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_service.php');
        $i = strpos($src, 'public function autoGenerate(');
        $e = strpos($src, "\n    public function generateIntoCandidate(", $i);
        $body = substr($src, $i, $e - $i);

        $this->assertStringContainsString('$this->_liveBatchOpsFrom(', $body,
            'the live path must be unchanged by this phase');
        $this->assertStringContainsString('$this->_commitLiveBatch(', $body);
        $this->assertStringNotContainsString('generateIntoCandidate', $body,
            'the live generator must not have been rewired to candidates in this phase');
    }
    // ── typing a timetable in by hand ────────────────────────────────────

    /**
     * A blank timetable must cover EVERY section, including one with no subject
     * assignments at all.
     *
     * This is the one place blank generation must not copy _placeGrid, which
     * skips an unassigned section because it has nothing to place. A school
     * typing its timetable in by hand is very likely not to have filled in
     * assignments yet — skipping those sections would hand back a blank
     * timetable missing exactly the classes it was opened to fill.
     */
    public function test_a_blank_timetable_covers_sections_with_no_assignments(): void
    {
        $inputs = $this->inputs();
        $inputs['sections'][] = ['className' => 'Class 9', 'section' => 'Section B',
                                 'key' => 'Class 9/Section B'];
        // Deliberately NO entry in sectionAssignments for the new section.

        $fb    = $this->fb();
        $store = $this->store($fb);
        $res   = $this->svc($fb, $inputs)->createBlankCandidate($store, 'PLAN_A');

        $this->assertSame('blank', $res['mode']);
        $this->assertSame(2, $res['sections']);
        $this->assertSame(4, $res['days'],
            'two sections x two working days: an unassigned section must still '
            . 'get its week');

        $written = [];
        foreach ($fb->writes as $w) {
            if ($w['collection'] === 'timetableVersionDays') {
                $written[] = $w['data']['classSection'] ?? '';
            }
        }
        // Note the canonical form: the blank path runs through the same
        // class/section normalisation as everything else, so "Class 9"
        // is stored as "Class 9th". A blank timetable that skipped that
        // would not match the days any other writer produces.
        $this->assertContains('Class 9th/Section B', $written,
            'the section with no assignments is the whole point of typing one in');
        $this->assertContains('Class 8th/Section A', $written,
            'and the assigned section must still be covered');
    }

    /**
     * Blank means blank — but the WEEK is still fully formed: the working days,
     * the number of periods and their times all come from Period Scheduling, so
     * a hand-built timetable has the same shape a generated one would.
     */
    public function test_a_blank_timetable_places_nothing_but_keeps_the_times(): void
    {
        $fb    = $this->fb();
        $store = $this->store($fb);
        $res   = $this->svc($fb, $this->inputs())->createBlankCandidate($store, 'PLAN_A');

        $this->assertSame(2, $res['days']);

        $seen = 0;
        foreach ($fb->writes as $w) {
            if ($w['collection'] !== 'timetableVersionDays') { continue; }
            $seen++;
            $this->assertCount(2, $w['data']['periods'], 'periodsPerDay must be honoured');
            foreach ($w['data']['periods'] as $i => $p) {
                $this->assertSame('', (string) ($p['subject'] ?? 'x'),
                    'a blank timetable must place no subject');
                $this->assertSame('', (string) ($p['teacherId'] ?? 'x'),
                    'and no teacher');
                $this->assertNotSame('', (string) ($p['startTime'] ?? ''),
                    'the period times must still be set, or the operator is '
                    . 'typing into a week with no shape');
            }
        }
        $this->assertSame(2, $seen, 'premise: both days were written');
    }

    /**
     * The blank path is the SAME candidate machinery, so a part-written blank
     * fails the same way: marked failed, refused, and nothing published touched.
     */
    public function test_a_failed_blank_marks_the_run_failed(): void
    {
        $fb = $this->fb();
        $fb->failDayWriteAt = 1;
        $store = $this->store($fb);

        try {
            $this->svc($fb, $this->inputs())->createBlankCandidate($store, 'PLAN_A');
            $this->fail('a failed day write must stop the blank');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~was not changed~i', $e->getMessage(),
                'the operator must be told nothing existing was harmed');
        }

        $failed = false;
        foreach ($fb->docs as $doc) {
            if (($doc['generation']['state'] ?? '') === 'failed') { $failed = true; }
        }
        if (!$failed) {
            foreach ($fb->writes as $w) {
                if (($w['data']['generation']['state'] ?? '') === 'failed') { $failed = true; }
            }
        }
        $this->assertTrue($failed, 'the run must be recorded as failed');
    }
}