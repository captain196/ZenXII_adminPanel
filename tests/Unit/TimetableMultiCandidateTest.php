<?php
/**
 * MULTI-CANDIDATE GENERATION, DRIVEN FOR REAL.
 *
 * One request, several complete and independent candidate timetables. The
 * properties that matter are not "it returned three things" but:
 *
 *   - each candidate is a full canonical version, written and validated on its
 *     own, publishable on its own;
 *   - they are genuinely DIFFERENT, and a repeat is refused before it is ever
 *     written;
 *   - one candidate failing does not take the batch down;
 *   - nothing that already existed is touched;
 *   - a request that cannot be completed honestly is refused up front rather
 *     than half-served.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/FirestoreBatchPreconditionTrait.php';

/** A Firestore stand-in that answers queries, so listVersions/days work. */
class FakeMultiCandFirestore
{
    // Publishing commits the version and the TT-C8 concurrency token in one
    // preconditioned batch. A double that applies the ops and ignores the
    // preconditions cannot tell a working compare-and-set from a missing one.
    use FirestoreBatchPreconditionTrait;

    /** collection => docId => data */
    public array $store = [];
    public array $writes = [];

    /** 1-based day-write index to fail on; 0 = never. */
    public int $failDayWriteAt = 0;
    private int $dayWrites = 0;

    public function firestoreGetOrFail($collection, $docId)
    {
        if ($collection === 'timetablePublishGuards') {
            return $this->publishGuardDoc($docId, $this->store[$collection] ?? []);
        }
        return $this->store[$collection][$docId] ?? null;
    }

    public function firestoreQueryOrFail($collection, $conditions = [], $orderBy = null,
                                         $dir = 'ASC', $limit = null)
    {
        $rows = [];
        foreach (($this->store[$collection] ?? []) as $doc) {
            $ok = true;
            foreach ($conditions as $c) {
                [$field, $op, $val] = $c;
                if ($op !== '==') { continue; }
                if (($doc[$field] ?? null) !== $val) { $ok = false; break; }
            }
            if ($ok) { $rows[] = $doc; }
        }
        return $limit !== null ? array_slice($rows, 0, $limit) : $rows;
    }

    public function firestoreSet($collection, $docId, $data, $merge = false)
    {
        if ($collection === 'timetableVersionDays') {
            $this->dayWrites++;
            if ($this->failDayWriteAt > 0 && $this->dayWrites === $this->failDayWriteAt) {
                throw new \RuntimeException('day write refused');
            }
        }
        $prev = $this->store[$collection][$docId] ?? [];
        $this->store[$collection][$docId] = $merge ? array_merge($prev, $data) : $data;
        $this->writes[] = ['collection' => $collection, 'docId' => $docId, 'merge' => $merge];
        return true;
    }

    public function firestoreDelete($collection, $docId)
    {
        unset($this->store[$collection][$docId]);
        return true;
    }
}

final class TimetableMultiCandidateTest extends TestCase
{
    private const SCHOOL = 'SCH_TEST01';
    private const SESS   = '2026-27';
    private const PLAN   = 'PLAN_A';

    private function fb(): FakeMultiCandFirestore
    {
        $fb = new FakeMultiCandFirestore();
        $fb->store['timetableSettings'][self::SCHOOL . '_' . self::SESS] =
            ['periodsPerDay' => 6];
        return $fb;
    }

    private function store(FakeMultiCandFirestore $fb): Timetable_plan_store
    {
        return (new Timetable_plan_store())->init($fb, self::SCHOOL, self::SESS, 'STA0001');
    }

    private function svc(FakeMultiCandFirestore $fb, array $inputs): Timetable_service
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

    /**
     * A week with real slack in it: 6 days x 6 periods = 36 slots per section,
     * 20 periods of teaching. Plenty of equally good placements, so seeds have
     * something to choose between.
     */
    private function inputs(int $sections = 2): array
    {
        $subjects = [
            ['संस्कृत',        'STA01', 'A Sharma', 6],
            ['Social Science', 'STA02', 'B Khan',   5],
            ['Maths',          'STA03', 'C Rao',    5],
            ['Kannada',        'STA04', 'D Iyer',   4],
        ];
        $secs = []; $assign = [];
        for ($i = 1; $i <= $sections; $i++) {
            $key = "Class {$i}/Section A";
            $secs[] = ['className' => "Class {$i}", 'section' => 'Section A', 'key' => $key];
            foreach ($subjects as [$sub, $tid, $name, $ppw]) {
                $assign[$key][] = ['subject' => $sub, 'code' => substr($sub, 0, 2),
                                   'teacherId' => $tid, 'teacherName' => $name,
                                   'periodsPerWeek' => $ppw];
            }
        }
        return [
            'periodsPerDay' => 6,
            'workingDays'   => ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'],
            'periodTimes'   => array_map(fn($i) => ['start' => "0{$i}:00AM", 'end' => "0{$i}:45AM"],
                                         range(1, 6)),
            'sections'      => $secs,
            'sectionAssignments' => $assign,
        ];
    }

    private function daysOf(FakeMultiCandFirestore $fb, string $versionId): array
    {
        $out = [];
        foreach (($fb->store['timetableVersionDays'] ?? []) as $id => $doc) {
            if (strpos($id, '_' . $versionId . '_') !== false) { $out[] = $doc; }
        }
        return $out;
    }

    // ── the core capability ──────────────────────────────────────────────

    public function test_one_request_builds_several_independent_candidates(): void
    {
        $fb  = $this->fb();
        $out = $this->svc($fb, $this->inputs())
                    ->generateCandidates($this->store($fb), self::PLAN, 3);

        $this->assertSame(3, $out['requested']);
        $this->assertSame(3, $out['built'], 'three were asked for and three were built');
        $this->assertCount(3, $out['candidates']);
        $this->assertStringStartsWith('BATCH_', $out['batchId']);

        $ids = array_column($out['candidates'], 'versionId');
        $this->assertCount(3, array_unique($ids), 'each candidate is its own version');

        foreach ($ids as $vid) {
            $this->assertCount(12, $this->daysOf($fb, $vid),
                'each candidate holds a complete week for both sections');
        }
    }

    /**
     * The first candidate of every batch is the UNSEEDED timetable, so a batch
     * always contains the one a single generation would have produced.
     * Variation is offered alongside it, never instead of it.
     */
    public function test_the_first_candidate_is_the_unseeded_timetable(): void
    {
        $fb  = $this->fb();
        $out = $this->svc($fb, $this->inputs())
                    ->generateCandidates($this->store($fb), self::PLAN, 3);

        $this->assertSame(0, $out['candidates'][0]['seed']);
        foreach (array_slice($out['candidates'], 1) as $c) {
            $this->assertNotSame(0, $c['seed'], 'later candidates must vary');
        }
    }

    public function test_the_candidates_are_genuinely_different(): void
    {
        $fb  = $this->fb();
        $out = $this->svc($fb, $this->inputs())
                    ->generateCandidates($this->store($fb), self::PLAN, 3);

        $sigs = [];
        foreach ($out['candidates'] as $c) {
            $sigs[] = Timetable_metrics::signature($this->daysOf($fb, $c['versionId']));
        }
        $this->assertCount(3, array_unique($sigs),
            'three candidates that are the same timetable are not three candidates');

        // And each reports how far it sits from its nearest sibling.
        foreach ($out['candidates'] as $c) {
            $this->assertGreaterThan(0, $c['distinctness']['differing'],
                'a candidate identical to a sibling must never be kept');
            $this->assertFalse($c['distinctness']['identical']);
        }
    }

    /** A repeat is refused BEFORE it is written, never written then deleted. */
    public function test_a_repeated_timetable_is_never_written(): void
    {
        // One section, one subject, one teacher, six periods a week into six
        // days: every seed produces the same timetable, so every attempt after
        // the first is a duplicate.
        $inputs = [
            'periodsPerDay' => 1,
            'workingDays'   => ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'],
            'periodTimes'   => [['start' => '09:00AM', 'end' => '09:45AM']],
            'sections'      => [['className' => 'Class 1', 'section' => 'Section A',
                                 'key' => 'Class 1/Section A']],
            'sectionAssignments' => ['Class 1/Section A' => [
                ['subject' => 'Maths', 'code' => 'M', 'teacherId' => 'STA01',
                 'teacherName' => 'C Rao', 'periodsPerWeek' => 6],
            ]],
        ];

        $fb  = $this->fb();
        $out = $this->svc($fb, $inputs)->generateCandidates($this->store($fb), self::PLAN, 3);

        $this->assertSame(1, $out['built'],
            'only one distinct timetable exists, so only one is kept');
        $this->assertGreaterThan(0, $out['duplicates'], 'the repeats were counted');

        $versions = $fb->store['timetableVersions'] ?? [];
        $this->assertCount(1, $versions,
            'a duplicate must never reach Firestore — a written-then-discarded '
            . 'version would consume the version budget for nothing');

        // And the operator is told, in words, why they got fewer.
        $this->assertNotEmpty($out['notes']);
        $this->assertMatchesRegularExpression('~few real alternatives~i',
            implode(' ', $out['notes']));
    }

    // ── independence ─────────────────────────────────────────────────────

    public function test_every_candidate_is_validated_on_its_own(): void
    {
        $fb  = $this->fb();
        $out = $this->svc($fb, $this->inputs())
                    ->generateCandidates($this->store($fb), self::PLAN, 3);

        foreach ($out['candidates'] as $c) {
            $doc = $fb->store['timetableVersions'][
                self::SCHOOL . '_' . self::SESS . '_' . self::PLAN . '_' . $c['versionId']];
            $this->assertArrayHasKey('validation', $doc,
                'a verdict must be stored per candidate, never copied from a sibling');
            $this->assertFalse($doc['validationStale'],
                'a freshly generated and checked candidate is not stale');
            $this->assertNotNull($c['verdict']['hardCount']);
            $this->assertIsBool($c['verdict']['publishable']);
        }
    }

    public function test_generating_does_not_touch_anything_that_already_existed(): void
    {
        $fb = $this->fb();

        // A published timetable and an older proposal, both already in place.
        foreach ([['V_PUB', 'published'], ['V_OLD', 'candidate']] as [$vid, $status]) {
            $fb->store['timetableVersions'][
                self::SCHOOL . '_' . self::SESS . '_' . self::PLAN . '_' . $vid] = [
                'schoolId' => self::SCHOOL, 'session' => self::SESS, 'planId' => self::PLAN,
                'versionId' => $vid, 'status' => $status, 'effectiveFrom' => '2026-01-01',
            ];
        }
        $before = $fb->store['timetableVersions'];

        $this->svc($fb, $this->inputs())->generateCandidates($this->store($fb), self::PLAN, 3);

        foreach (['V_PUB', 'V_OLD'] as $vid) {
            $key = self::SCHOOL . '_' . self::SESS . '_' . self::PLAN . '_' . $vid;
            $this->assertSame($before[$key], $fb->store['timetableVersions'][$key],
                "{$vid} must be byte-identical after a generation run");
        }
    }

    /** One candidate failing must not cost the ones already built. */
    public function test_a_failed_candidate_does_not_take_the_batch_down(): void
    {
        $fb = $this->fb();
        // Fail partway through the SECOND candidate's days (13 days per
        // candidate would be 12 writes; fail on the 13th = candidate 2, day 1).
        $fb->failDayWriteAt = 13;

        $out = $this->svc($fb, $this->inputs())
                    ->generateCandidates($this->store($fb), self::PLAN, 3);

        $this->assertSame(1, $out['failed'], 'exactly one candidate failed');
        $this->assertGreaterThanOrEqual(1, $out['built'],
            'the candidates that succeeded are still usable');

        // A failed attempt is retried with another seed, so the batch can
        // still reach the number asked for. That is correct: the operator
        // asked for three usable timetables, and three usable timetables is
        // what they should get. What must NOT happen is the failure being
        // hidden -- it is counted and reported either way.
        $this->assertSame($out['built'], count($out['candidates']),
            'the count and the list must agree -- never report more than exist');

        // Every candidate reported must actually hold a complete week.
        foreach ($out['candidates'] as $c) {
            $this->assertCount(12, $this->daysOf($fb, $c['versionId']),
                'a candidate listed as built must be complete, not partial');
        }

        // The failure is reported, never smoothed over.
        $this->assertMatchesRegularExpression('~could not be built~i',
            implode(' ', $out['notes']));
    }

    // ── honest refusals ──────────────────────────────────────────────────

    public function test_more_than_the_ceiling_is_refused(): void
    {
        $fb = $this->fb();
        $this->expectException(Service_exception::class);
        $this->expectExceptionMessageMatches('~At most 5~');
        $this->svc($fb, $this->inputs())->generateCandidates($this->store($fb), self::PLAN, 9);
    }

    /**
     * A school too large to finish is told so, WITH the number that would fit,
     * rather than being allowed to start and time out half-way.
     */
    public function test_a_school_too_large_to_finish_is_refused_with_a_usable_number(): void
    {
        $fb = $this->fb();
        // 220 sections x 6 days = 1320 day documents -> 2643 requests each,
        // which is already past the whole request budget.
        $inputs = $this->inputs(1);
        $secs = [];
        for ($i = 1; $i <= 220; $i++) {
            $secs[] = ['className' => "Class {$i}", 'section' => 'Section A',
                       'key' => "Class {$i}/Section A"];
        }
        $inputs['sections'] = $secs;

        try {
            $this->svc($fb, $inputs)->generateCandidates($this->store($fb), self::PLAN, 3);
            $this->fail('a request that cannot complete must be refused');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~too large~i', $e->getMessage());
            $this->assertMatchesRegularExpression('~Up to \d+ can be built~', $e->getMessage(),
                'the refusal must say how many WOULD work');
        }

        $this->assertSame([], $fb->store['timetableVersions'] ?? [],
            'nothing may be written by a request that was refused');
    }

    /**
     * Generating must never make the live timetable unresolvable. Past the scan
     * limit the store cannot prove which timetable is in force and refuses to
     * resolve any of them — so creating versions that cross it is refused here.
     */
    public function test_generation_refuses_to_cross_the_version_scan_limit(): void
    {
        $fb = $this->fb();
        $limit = Timetable_plan_store::VERSION_SCAN_LIMIT;
        for ($i = 0; $i < $limit - 1; $i++) {
            $fb->store['timetableVersions']["pad_{$i}"] = [
                'schoolId' => self::SCHOOL, 'session' => self::SESS,
                'planId' => self::PLAN, 'versionId' => "PAD{$i}", 'status' => 'candidate',
            ];
        }

        try {
            $this->svc($fb, $this->inputs())->generateCandidates($this->store($fb), self::PLAN, 3);
            $this->fail('crossing the scan limit must be refused');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~limit of ' . $limit . '~', $e->getMessage());
            $this->assertMatchesRegularExpression('~Discard some~i', $e->getMessage(),
                'the operator must be told what to do about it');
        }
    }

    // ── the facts a comparison screen needs ──────────────────────────────

    public function test_each_candidate_records_the_facts_about_itself(): void
    {
        $fb  = $this->fb();
        $out = $this->svc($fb, $this->inputs())
                    ->generateCandidates($this->store($fb), self::PLAN, 3);

        foreach ($out['candidates'] as $c) {
            $doc = $fb->store['timetableVersions'][
                self::SCHOOL . '_' . self::SESS . '_' . self::PLAN . '_' . $c['versionId']];
            $this->assertArrayHasKey('facts', $doc);

            $f = $doc['facts'];
            $this->assertSame($out['batchId'], $f['batchId'],
                'the batch is recorded so a comparison screen can group them');
            $this->assertArrayHasKey('seed', $f, 'the seed makes a candidate reproducible');
            $this->assertArrayHasKey('metrics', $f);
            $this->assertArrayHasKey('distinctness', $f);

            $m = $f['metrics'];
            $this->assertSame(72, $m['cells'], '2 sections x 6 days x 6 periods');
            $this->assertSame(40, $m['filled'], '2 sections x 20 periods of teaching');
            $this->assertTrue($m['shortfall']['known'],
                'the curriculum target was supplied, so shortfall is a measurement');
            $this->assertSame(0, $m['shortfall']['total'],
                'this week has room for everything asked for');
            $this->assertSame(4, $m['teacherLoad']['teachers']);
        }
    }

    /** Curriculum shortfall is measured server-side, per candidate. */
    public function test_a_curriculum_shortfall_is_measured_not_guessed(): void
    {
        // 30 periods of teaching asked for, 24 slots available.
        $inputs = $this->inputs(1);
        $inputs['periodsPerDay'] = 4;
        $inputs['periodTimes']   = array_slice($inputs['periodTimes'], 0, 4);
        $inputs['sectionAssignments']['Class 1/Section A'][0]['periodsPerWeek'] = 16;

        $fb  = $this->fb();
        $fb->store['timetableSettings'][self::SCHOOL . '_' . self::SESS] = ['periodsPerDay' => 4];
        $out = $this->svc($fb, $inputs)->generateCandidates($this->store($fb), self::PLAN, 1);

        $m = $out['candidates'][0]['metrics'];
        $this->assertTrue($m['shortfall']['known']);
        $this->assertGreaterThan(0, $m['shortfall']['total'],
            'more teaching was asked for than the week can hold');
        $this->assertNotEmpty($m['shortfall']['bySubject'],
            'and the report must name WHICH subject went short');
        $this->assertArrayHasKey('asked', $m['shortfall']['bySubject'][0]);
    }
    // ── selection and publishing ─────────────────────────────────────────

    /**
     * Choosing one candidate must leave every other one exactly as it was.
     *
     * This is the property that makes offering several safe. If publishing
     * changed, invalidated or superseded its siblings, then generating five
     * options would mean losing four of them the moment a decision is made —
     * and an administrator who changed their mind would have nothing to go
     * back to.
     */
    public function test_publishing_one_candidate_leaves_the_others_untouched(): void
    {
        $fb  = $this->fb();
        $st  = $this->store($fb);
        $out = $this->svc($fb, $this->inputs())->generateCandidates($st, self::PLAN, 3);
        $this->assertSame(3, $out['built'], 'premise: three candidates exist');

        $ids    = array_column($out['candidates'], 'versionId');
        $chosen = $ids[1];
        $others = [$ids[0], $ids[2]];

        $key    = fn(string $v) => self::SCHOOL . '_' . self::SESS . '_' . self::PLAN . '_' . $v;
        $before = [];
        foreach ($others as $o) { $before[$o] = $fb->store['timetableVersions'][$key($o)]; }
        $dayCountBefore = count($fb->store['timetableVersionDays']);

        $st->publishCandidate(self::PLAN, $chosen, '2026-09-01');

        $this->assertSame('published',
            $fb->store['timetableVersions'][$key($chosen)]['status'],
            'the chosen candidate is published');

        foreach ($others as $o) {
            $this->assertSame($before[$o], $fb->store['timetableVersions'][$key($o)],
                'a sibling must be byte-identical after another is published — '
                . 'not superseded, not invalidated, not restatused');
            $this->assertSame('candidate',
                $fb->store['timetableVersions'][$key($o)]['status'],
                'and still a proposal the administrator can return to');
        }

        $this->assertSame($dayCountBefore, count($fb->store['timetableVersionDays']),
            'publishing must not add or remove any day document');
    }

    /**
     * DAY ONE, for a school that has never had a timetable.
     *
     * No flag, no migration, no legacy setup: settings and assignments exist,
     * and the whole canonical path runs from an empty store to a timetable that
     * resolves as in force.
     */
    public function test_a_brand_new_school_reaches_a_published_timetable(): void
    {
        $fb = $this->fb();
        $st = $this->store($fb);

        // Nothing exists yet.
        $this->assertSame([], $fb->store['timetableVersions'] ?? [],
            'premise: a school with no timetable history at all');

        // 1. Build several.
        $out = $this->svc($fb, $this->inputs())->generateCandidates($st, self::PLAN, 3);
        $this->assertSame(3, $out['built']);

        // 2. Every one arrives already checked and already measured, so the
        //    administrator can compare without doing anything first.
        foreach ($out['candidates'] as $c) {
            $this->assertNotNull($c['verdict']['hardCount']);
            $this->assertArrayHasKey('metrics', $c);
            $this->assertArrayHasKey('distinctness', $c);
        }

        // 3. Nothing is live while they are being considered.
        $this->assertSame('none', $st->resolveForDate('2026-09-01')['status'],
            'no proposal may reach a teacher before it is published');

        // 4. Choose one and publish it.
        $chosen = $out['candidates'][0]['versionId'];
        $st->publishCandidate(self::PLAN, $chosen, '2026-09-01');

        // 5. It is now the timetable in force, and exactly one is.
        $res = $st->resolveForDate('2026-09-01');
        $this->assertSame('resolved', $res['status']);
        $this->assertSame($chosen, $res['version']['versionId']);
    }
    // ── gaps found by auditing the multi-candidate work itself ───────────

    /**
     * A SECOND batch must not hand back the first batch's timetables again.
     *
     * Duplicate rejection was batch-local: every batch starts with the
     * unseeded, fully deterministic timetable, so generating twice produced two
     * byte-identical proposals. An administrator who did not like the first
     * three and pressed Generate again would be given the same timetable back
     * as though it were a new option — the exact "five copies with different
     * ids" failure this feature exists to avoid.
     */
    public function test_a_second_batch_does_not_repeat_the_first_batchs_timetables(): void
    {
        $fb  = $this->fb();
        $st  = $this->store($fb);
        $svc = $this->svc($fb, $this->inputs());

        $first  = $svc->generateCandidates($st, self::PLAN, 2);
        $second = $svc->generateCandidates($st, self::PLAN, 2);

        $sigs = [];
        foreach (array_merge($first['candidates'], $second['candidates']) as $c) {
            $sigs[] = Timetable_metrics::signature($this->daysOf($fb, $c['versionId']));
        }

        $this->assertSame(count($sigs), count(array_unique($sigs)),
            'a proposal that already exists must never be offered again as a new '
            . 'alternative, whichever batch produced it');
    }

    /**
     * Editing a candidate must mark its MEASUREMENTS stale, not just its
     * verdict.
     *
     * The verdict already goes stale on a hand edit. The facts did not — so a
     * comparison screen would keep showing the curriculum shortfall, teaching
     * load and distinctness of the timetable as it was BEFORE the edit, next to
     * candidates whose numbers are current. Comparing a school's options on
     * numbers that describe a timetable that no longer exists is worse than
     * showing nothing.
     */
    public function test_editing_a_candidate_marks_its_measurements_stale(): void
    {
        $fb  = $this->fb();
        $st  = $this->store($fb);
        $out = $this->svc($fb, $this->inputs())->generateCandidates($st, self::PLAN, 2);

        $vid = $out['candidates'][0]['versionId'];
        $key = self::SCHOOL . '_' . self::SESS . '_' . self::PLAN . '_' . $vid;

        $this->assertFalse($fb->store['timetableVersions'][$key]['factsStale'] ?? false,
            'premise: a freshly generated candidate has current measurements');

        // A hand edit: replace one period of one day.
        $days = $this->daysOf($fb, $vid);
        $this->assertNotEmpty($days, 'premise: the candidate has days');
        $day  = $days[0];
        $periods = $day['periods'];
        $periods[0] = array_merge($periods[0],
            ['subject' => 'Something Else', 'teacher' => 'X', 'teacherId' => 'STA99']);

        $st->saveCandidateDay(self::PLAN, $vid, $day['classSection'], $day['day'],
                              $periods, true);

        $doc = $fb->store['timetableVersions'][$key];
        $this->assertTrue($doc['validationStale'], 'premise: the verdict goes stale');
        // A TOP-LEVEL flag: a merge write of ['facts' => ['stale' => true]] would
        // replace the whole facts map and destroy the measurements it annotates.
        $this->assertTrue($doc['factsStale'] ?? false,
            'the measurements describe the timetable BEFORE the edit and must be '
            . 'marked stale, or comparison shows numbers for a timetable that no '
            . 'longer exists');
    }
    /**
     * A hand-edited candidate's stored signature describes the timetable BEFORE
     * the edit, so it must not be used to reject a new proposal.
     *
     * Otherwise: generate once, edit the result, generate again — and the
     * generator's own deterministic output is rejected as "a duplicate" of a
     * timetable that no longer exists anywhere. The administrator is left with
     * nothing and no way to act on the refusal.
     */
    public function test_a_hand_edited_candidates_signature_is_not_trusted(): void
    {
        $fb  = $this->fb();
        $st  = $this->store($fb);
        $svc = $this->svc($fb, $this->inputs());

        $first = $svc->generateCandidates($st, self::PLAN, 1);
        $this->assertSame(1, $first['built'], 'premise: one candidate exists');
        $vid = $first['candidates'][0]['versionId'];

        // Edit it, so its stored measurements — signature included — describe a
        // timetable that is no longer there.
        $days    = $this->daysOf($fb, $vid);
        $day     = $days[0];
        $periods = $day['periods'];
        $periods[0] = array_merge($periods[0],
            ['subject' => 'Changed By Hand', 'teacher' => 'X', 'teacherId' => 'STA99']);
        $st->saveCandidateDay(self::PLAN, $vid, $day['classSection'], $day['day'],
                              $periods, true);

        $key = self::SCHOOL . '_' . self::SESS . '_' . self::PLAN . '_' . $vid;
        $this->assertTrue($fb->store['timetableVersions'][$key]['factsStale'],
            'premise: the edit staled the measurements');

        // The deterministic run produces the ORIGINAL timetable again. Nothing
        // in the school now holds that timetable, so it is a real proposal.
        $second = $svc->generateCandidates($st, self::PLAN, 1);

        $this->assertSame(1, $second['built'],
            'the generator must be able to offer a timetable that no longer '
            . 'exists anywhere, even though an edited version once matched it');
        $this->assertSame(0, $second['duplicates']);
    }
    // ── publishing discloses what it does not deliver ────────────────────

    /** A week too small for the curriculum, so a shortfall is guaranteed. */
    private function tightInputs(): array
    {
        $inputs = $this->inputs(1);
        $inputs['periodsPerDay'] = 4;
        $inputs['periodTimes']   = array_slice($inputs['periodTimes'], 0, 4);
        $inputs['sectionAssignments']['Class 1/Section A'][0]['periodsPerWeek'] = 16;
        return $inputs;
    }

    /**
     * Publishing a timetable that does not deliver the curriculum is still
     * ALLOWED — that is an owner decision and it has not been made. What must
     * not happen is publishing it silently.
     *
     * Publishing already discloses an overlap for the same reason: a safety
     * state only the database knows about is a trap, and publishing is the last
     * moment anybody looks.
     */
    public function test_publishing_discloses_a_curriculum_shortfall(): void
    {
        $fb = $this->fb();
        $fb->store['timetableSettings'][self::SCHOOL . '_' . self::SESS] = ['periodsPerDay' => 4];
        $st  = $this->store($fb);
        $out = $this->svc($fb, $this->tightInputs())->generateCandidates($st, self::PLAN, 1);

        $vid = $out['candidates'][0]['versionId'];
        $this->assertGreaterThan(0, $out['candidates'][0]['metrics']['shortfall']['total'],
            'premise: this week cannot hold the curriculum');

        $res = $st->publishCandidate(self::PLAN, $vid, '2026-09-01');

        $this->assertArrayHasKey('shortfall', $res,
            'publishing must report what the timetable does not deliver');
        $this->assertTrue($res['shortfall']['known']);
        $this->assertGreaterThan(0, $res['shortfall']['total']);
        $this->assertNotEmpty($res['shortfall']['bySubject'],
            'and name the subject, so the operator can act on it');

        // It is DISCLOSED, not prevented: the timetable published.
        $key = self::SCHOOL . '_' . self::SESS . '_' . self::PLAN . '_' . $vid;
        $this->assertSame('published', $fb->store['timetableVersions'][$key]['status'],
            'disclosure must not quietly become a block — that is Decision 2');
    }

    /**
     * A shortfall measured before a hand edit describes a timetable that no
     * longer exists. Reporting its number as though it were current would be
     * worse than reporting nothing, so it is reported as UNKNOWN.
     */
    public function test_a_stale_shortfall_is_reported_as_unknown_not_zero(): void
    {
        $fb = $this->fb();
        $fb->store['timetableSettings'][self::SCHOOL . '_' . self::SESS] = ['periodsPerDay' => 4];
        $st  = $this->store($fb);
        $out = $this->svc($fb, $this->tightInputs())->generateCandidates($st, self::PLAN, 1);
        $vid = $out['candidates'][0]['versionId'];

        // Edit, then re-check so publishing is permitted again. The verdict is
        // fresh; the MEASUREMENTS are not, because nothing recomputed them.
        $days    = $this->daysOf($fb, $vid);
        $periods = $days[0]['periods'];
        $periods[0] = array_merge($periods[0],
            ['subject' => 'Changed', 'teacher' => 'X', 'teacherId' => 'STA99']);
        $st->saveCandidateDay(self::PLAN, $vid, $days[0]['classSection'], $days[0]['day'],
                              $periods, true);
        $st->validateCandidate(self::PLAN, $vid);

        $res = $st->publishCandidate(self::PLAN, $vid, '2026-09-01');

        $this->assertFalse($res['shortfall']['known'],
            'a stale measurement must not be presented as current');
        $this->assertSame('stale', $res['shortfall']['reason'],
            'and the operator must be able to tell WHY it is unknown');
        $this->assertSame(0, $res['shortfall']['total'],
            'the number is meaningless when known is false');
    }
    /**
     * A candidate built by a path that records no measurements must publish
     * with shortfall reported as UNKNOWN — never as zero.
     *
     * `generateIntoCandidate` is the single-candidate path: it writes a complete
     * timetable but records no facts. Reporting "0 short" for it would tell an
     * operator the curriculum is fully delivered when nobody has checked, which
     * is the silent-empty-read failure this whole programme exists to remove,
     * at the one moment it would be most believed.
     */
    public function test_publishing_a_candidate_with_no_measurements_says_so(): void
    {
        $fb = $this->fb();
        $fb->store['timetableSettings'][self::SCHOOL . '_' . self::SESS] = ['periodsPerDay' => 4];
        $st  = $this->store($fb);

        // The single-candidate path: complete, valid, but never measured.
        $gen = $this->svc($fb, $this->tightInputs())
                    ->generateIntoCandidate($st, self::PLAN);
        $vid = $gen['versionId'];

        $key = self::SCHOOL . '_' . self::SESS . '_' . self::PLAN . '_' . $vid;
        $this->assertArrayNotHasKey('facts', $fb->store['timetableVersions'][$key],
            'premise: this path records no measurements');

        $st->validateCandidate(self::PLAN, $vid);
        $res = $st->publishCandidate(self::PLAN, $vid, '2026-09-01');

        $this->assertFalse($res['shortfall']['known'],
            'nothing measured the curriculum, so nothing may be claimed about it');
        $this->assertSame('not measured', $res['shortfall']['reason']);
        $this->assertSame(0, $res['shortfall']['total'],
            'the number carries no meaning while known is false');
    }
}