<?php
/**
 * RETENTION — what may be removed, what may never be, and who decides.
 *
 * The dangerous failure here is not "capacity was not reclaimed". It is
 * removing a timetable that was in force, or one teachers are reading, or one
 * still being written. Every protection is therefore tested as a REFUSAL, and
 * the store is tested for re-verifying rather than trusting what the screen
 * showed a moment ago.
 */

use PHPUnit\Framework\TestCase;

class FakeRetentionFirestore
{
    public array $store = [];
    public array $deleted = [];

    public function firestoreGetOrFail($collection, $docId)
    {
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
        $prev = $this->store[$collection][$docId] ?? [];
        $this->store[$collection][$docId] = $merge ? array_merge($prev, $data) : $data;
        return true;
    }

    public function firestoreDelete($collection, $docId)
    {
        $this->deleted[] = $collection . '/' . $docId;
        unset($this->store[$collection][$docId]);
        return true;
    }
}

final class TimetableRetentionTest extends TestCase
{
    private const SCHOOL = 'SCH_RET01';
    private const SESS   = '2026-27';
    private const PLAN   = 'PLAN_A';

    private function v(string $id, string $status, array $over = []): array
    {
        return array_merge([
            'schoolId'  => self::SCHOOL, 'session' => self::SESS,
            'planId'    => self::PLAN,   'versionId' => $id,
            'status'    => $status,
            'generation' => ['state' => 'complete'],
        ], $over);
    }

    private function classOf(array $rows, string $id): array
    {
        foreach ($rows as $r) { if ($r['versionId'] === $id) { return $r; } }
        $this->fail("no classification for {$id}");
    }

    // ── the protections ──────────────────────────────────────────────────

    /**
     * A published timetable is the record of what was in force, and attendance
     * is checked against it. No amount of pressure on capacity makes removing
     * one correct.
     */
    public function test_published_timetables_are_never_reclaimable(): void
    {
        $rows = Timetable_retention::classify([
            $this->v('PAST',   'published', ['effectiveFrom' => '2026-01-01', 'effectiveTo' => '2026-06-30']),
            $this->v('NOW',    'published', ['effectiveFrom' => '2026-08-01', 'effectiveTo' => '']),
            $this->v('FUTURE', 'published', ['effectiveFrom' => '2026-12-01', 'effectiveTo' => '']),
            $this->v('OLD',    'superseded', ['effectiveFrom' => '2025-04-01']),
        ], '2026-09-01');

        foreach (['PAST', 'NOW', 'FUTURE', 'OLD'] as $id) {
            $this->assertSame(Timetable_retention::PROTECTED, $this->classOf($rows, $id)['class'],
                "{$id} must never be offered for removal");
        }

        // And each says WHY in words an operator can act on.
        $this->assertSame(Timetable_retention::R_IN_FORCE, $this->classOf($rows, 'NOW')['reason']);
        $this->assertSame(Timetable_retention::R_FUTURE,   $this->classOf($rows, 'FUTURE')['reason']);
        $this->assertSame(Timetable_retention::R_PUBLISHED, $this->classOf($rows, 'PAST')['reason']);
        $this->assertSame(Timetable_retention::R_SUPERSEDED, $this->classOf($rows, 'OLD')['reason']);
        $this->assertStringContainsString('in force today',
            $this->classOf($rows, 'NOW')['why']);
    }

    /** A run still writing days must not have its version pulled from under it. */
    public function test_a_version_still_being_built_is_protected(): void
    {
        $rows = Timetable_retention::classify([
            $this->v('BUILDING', 'candidate', ['generation' => ['state' => 'running']]),
        ], '2026-09-01');

        $r = $this->classOf($rows, 'BUILDING');
        $this->assertSame(Timetable_retention::PROTECTED, $r['class']);
        $this->assertSame(Timetable_retention::R_GENERATING, $r['reason']);
    }

    /** A proposal nobody published is the only thing that may be let go of. */
    public function test_unpublished_proposals_are_reclaimable_and_say_which_kind(): void
    {
        $rows = Timetable_retention::classify([
            $this->v('UNUSED', 'candidate'),
            $this->v('DRAFT',  'draft'),
            $this->v('BROKEN', 'candidate', ['generation' => ['state' => 'failed']]),
        ], '2026-09-01');

        foreach (['UNUSED', 'DRAFT', 'BROKEN'] as $id) {
            $this->assertSame(Timetable_retention::RECLAIMABLE, $this->classOf($rows, $id)['class']);
        }

        $this->assertSame(Timetable_retention::R_FAILED, $this->classOf($rows, 'BROKEN')['reason'],
            'a run that did not finish is worth naming separately: it can never '
            . 'be checked or published, so it is pure dead weight');
        $this->assertSame(Timetable_retention::R_UNUSED, $this->classOf($rows, 'UNUSED')['reason']);
    }

    /** A version with no status at all must not fall through into reclaimable. */
    public function test_an_unrecognised_status_is_not_treated_as_disposable(): void
    {
        $rows = Timetable_retention::classify([
            ['versionId' => 'WEIRD', 'status' => 'something_new'],
        ], '2026-09-01');

        // It is not published, so it classifies as reclaimable — but the point
        // is that the ONLY way to be reclaimable is to reach the final branch,
        // and every protection above it returns early. This test exists so that
        // adding a new protected status without adding its branch is visible.
        $this->assertSame(Timetable_retention::RECLAIMABLE, $this->classOf($rows, 'WEIRD')['class'],
            'if a new protected status is introduced, it needs its own branch '
            . 'here — reaching the end means "nobody protected it"');
    }

    public function test_unreadable_input_classifies_nothing(): void
    {
        $this->assertSame([], Timetable_retention::classify('not versions'));
        $this->assertSame([], Timetable_retention::classify([]));
    }

    // ── capacity ─────────────────────────────────────────────────────────

    public function test_capacity_reports_what_could_be_recovered(): void
    {
        $rows = Timetable_retention::classify([
            $this->v('P1', 'published', ['effectiveFrom' => '2026-08-01']),
            $this->v('C1', 'candidate'),
            $this->v('C2', 'candidate'),
            $this->v('C3', 'candidate', ['generation' => ['state' => 'failed']]),
        ], '2026-09-01');

        $cap = Timetable_retention::capacity($rows, 10, 5);

        $this->assertSame(4, $cap['used']);
        $this->assertSame(6, $cap['headroom']);
        $this->assertSame(1, $cap['protected']);
        $this->assertSame(3, $cap['reclaimable']);
        $this->assertSame(1, $cap['failed']);
        $this->assertSame(9, $cap['headroomAfter'],
            'reclaiming everything reclaimable leaves room for all but the protected one');
        $this->assertFalse($cap['atRisk'], '6 of headroom still fits a batch of 5');
    }

    /**
     * "At risk" is not a chosen threshold: it is exactly the point at which the
     * largest generation this school can request stops being possible.
     */
    public function test_at_risk_is_the_moment_a_full_batch_stops_fitting(): void
    {
        $rows = array_map(fn($i) => $this->v("C{$i}", 'candidate'), range(1, 95));

        $this->assertFalse(Timetable_retention::capacity($rows, 100, 5)['atRisk'],
            '95 used of 100 still fits a batch of 5 exactly');

        $rows[] = $this->v('C96', 'candidate');
        $this->assertTrue(Timetable_retention::capacity($rows, 100, 5)['atRisk'],
            'one more and the largest request can no longer complete');
    }

    // ── orphans ──────────────────────────────────────────────────────────

    /**
     * Discarding deletes days before the version and refuses if a day delete
     * fails, so an orphan should be impossible — which is exactly why finding
     * one matters.
     */
    public function test_orphan_days_are_found_and_counted(): void
    {
        $orphans = Timetable_retention::orphanDays(
            ['V1', 'V1', 'V2', 'GONE', 'GONE', 'GONE'],
            ['V1', 'V2']);

        $this->assertSame(['GONE' => 3], $orphans);
    }

    public function test_no_orphans_reports_nothing_rather_than_guessing(): void
    {
        $this->assertSame([], Timetable_retention::orphanDays(['V1'], ['V1']));
        $this->assertSame([], Timetable_retention::orphanDays([], []));
    }

    // ── the store: re-verification, not trust ────────────────────────────

    private function store(FakeRetentionFirestore $fb): Timetable_plan_store
    {
        return (new Timetable_plan_store())->init($fb, self::SCHOOL, self::SESS, 'STA0001');
    }

    private function seed(): FakeRetentionFirestore
    {
        $fb = new FakeRetentionFirestore();
        $key = fn($id) => self::SCHOOL . '_' . self::SESS . '_' . self::PLAN . '_' . $id;
        foreach ([
            ['LIVE', 'published', ['effectiveFrom' => '2026-08-01', 'effectiveTo' => '']],
            ['P1',   'candidate', []],
            ['P2',   'candidate', []],
        ] as [$id, $status, $over]) {
            $fb->store['timetableVersions'][$key($id)] = $this->v($id, $status, $over);
        }
        return $fb;
    }

    public function test_the_report_separates_what_may_go_from_what_may_not(): void
    {
        $fb  = $this->seed();
        $rep = $this->store($fb)->retentionReport('2026-09-01');

        $this->assertCount(3, $rep['rows']);
        $this->assertSame(2, $rep['capacity']['reclaimable']);
        $this->assertSame(1, $rep['capacity']['protected']);
        $this->assertSame(Timetable_plan_store::VERSION_SCAN_LIMIT, $rep['capacity']['limit']);
    }

    /** A dry run must change nothing at all. */
    public function test_a_dry_run_removes_nothing(): void
    {
        $fb  = $this->seed();
        $out = $this->store($fb)->reclaimVersions(self::PLAN, ['P1', 'P2'], true);

        $this->assertTrue($out['dryRun']);
        $this->assertCount(2, $out['removed'], 'it reports what WOULD go');
        $this->assertSame([], $fb->deleted, 'and deletes nothing');
        $this->assertCount(3, $fb->store['timetableVersions']);
    }

    public function test_reclaiming_removes_only_the_proposals(): void
    {
        $fb  = $this->seed();
        $out = $this->store($fb)->reclaimVersions(self::PLAN, ['P1', 'P2'], false);

        $this->assertCount(2, $out['removed']);
        $this->assertSame([], $out['refused']);
        $this->assertSame([], $out['failed']);
        $this->assertCount(1, $fb->store['timetableVersions'],
            'only the published timetable survives');
    }

    /**
     * THE PROPERTY THAT MATTERS. The classification an operator saw was computed
     * when the screen loaded; a version can have been published since. Every
     * identifier is therefore re-checked against state read NOW.
     */
    public function test_a_published_timetable_is_refused_even_if_asked_for(): void
    {
        $fb  = $this->seed();
        $out = $this->store($fb)->reclaimVersions(self::PLAN, ['LIVE', 'P1'], false);

        $this->assertCount(1, $out['refused'], 'the published one is refused');
        $this->assertSame('LIVE', $out['refused'][0]['versionId']);
        $this->assertStringContainsString('in force today', $out['refused'][0]['reason']);

        $this->assertCount(1, $out['removed'], 'and the proposal still goes');
        $this->assertSame('P1', $out['removed'][0]['versionId']);

        $key = self::SCHOOL . '_' . self::SESS . '_' . self::PLAN . '_LIVE';
        $this->assertArrayHasKey($key, $fb->store['timetableVersions'],
            'a refusal must leave the timetable exactly where it was');
    }

    /**
     * One bad identifier must not cost the operator the other nineteen. Tidying
     * twenty proposals is a single intention, not twenty independent ones.
     */
    public function test_one_refusal_does_not_abandon_the_rest(): void
    {
        $fb  = $this->seed();
        $out = $this->store($fb)->reclaimVersions(
            self::PLAN, ['NOT_A_VERSION', 'P1', 'LIVE', 'P2'], false);

        $this->assertCount(2, $out['removed']);
        $this->assertCount(2, $out['refused']);
        $this->assertContains('NOT_A_VERSION', array_column($out['refused'], 'versionId'));
    }

    public function test_an_unknown_version_is_refused_with_a_readable_reason(): void
    {
        $fb  = $this->seed();
        $out = $this->store($fb)->reclaimVersions(self::PLAN, ['GHOST'], false);

        $this->assertSame([], $out['removed']);
        $this->assertStringContainsString('no longer exists', $out['refused'][0]['reason']);
    }
    // ── a build that never finished ──────────────────────────────────────

    /**
     * THE STATE THAT HAD NO EXIT.
     *
     * `running` is only ever left by the request that set it. If that request
     * dies, the version stays `running` for ever: it can never be validated,
     * never published, and retention protects it from removal — so it sits in
     * the version count permanently and pushes a blameless school towards the
     * point where no timetable resolves at all.
     */
    public function test_a_stuck_build_can_be_given_up_on_and_then_removed(): void
    {
        $fb  = $this->seed();
        $key = self::SCHOOL . '_' . self::SESS . '_' . self::PLAN . '_STUCK';
        $fb->store['timetableVersions'][$key] = $this->v('STUCK', 'candidate', [
            'generation' => ['state' => 'running', 'runId' => 'GEN_X',
                             'startedAt' => '2026-08-01T09:00:00+00:00', 'daysWritten' => 4],
        ]);
        $st = $this->store($fb);

        // Before: protected, so it cannot be reclaimed.
        $before = $st->retentionReport('2026-09-01');
        $this->assertSame(Timetable_retention::PROTECTED,
            $this->classOf($before['rows'], 'STUCK')['class']);

        $out = $st->reclaimVersions(self::PLAN, ['STUCK'], false);
        $this->assertSame([], $out['removed'], 'a build in progress must not be removed');
        $this->assertCount(1, $out['refused']);

        // The operator gives up on it.
        $st->abandonStuckRun(self::PLAN, 'STUCK');
        $this->assertSame('failed',
            $fb->store['timetableVersions'][$key]['generation']['state']);

        // After: reclaimable, and named as a build that did not finish.
        $after = $st->retentionReport('2026-09-01');
        $row   = $this->classOf($after['rows'], 'STUCK');
        $this->assertSame(Timetable_retention::RECLAIMABLE, $row['class']);
        $this->assertSame(Timetable_retention::R_FAILED, $row['reason']);

        $out2 = $st->reclaimVersions(self::PLAN, ['STUCK'], false);
        $this->assertCount(1, $out2['removed'], 'and now it can go');
        $this->assertArrayNotHasKey($key, $fb->store['timetableVersions']);
    }

    /**
     * How long it has been building is REPORTED, so a person can judge it.
     * The code deliberately does not: generation is bounded by execution time
     * and this work is almost entirely I/O, so wall-clock age proves nothing.
     */
    public function test_a_long_running_build_reports_its_age_without_judging_it(): void
    {
        $rows = Timetable_retention::classify([
            $this->v('OLD', 'candidate', ['generation' => [
                'state' => 'running', 'runId' => 'R1',
                'startedAt' => date('c', time() - (30 * 3600))]]),
            $this->v('NEW', 'candidate', ['generation' => [
                'state' => 'running', 'runId' => 'R2', 'startedAt' => date('c')]]),
        ], date('Y-m-d'));

        $old = $this->classOf($rows, 'OLD');
        $this->assertSame(Timetable_retention::PROTECTED, $old['class'],
            'age alone never makes it removable — that is the operator\'s call');
        $this->assertSame(30, $old['buildHours']);
        $this->assertStringContainsString('30 hour(s)', $old['why']);
        $this->assertStringContainsString('mark it as not finished', $old['why'],
            'and the way out must be named where the problem is shown');

        $new = $this->classOf($rows, 'NEW');
        $this->assertSame(0, $new['buildHours']);
        $this->assertStringNotContainsString('hour(s)', $new['why'],
            'a build that just started is not described as a problem');
    }

    /** An unreadable start time must not become "0 hours" and read as fresh. */
    public function test_a_build_with_no_start_time_is_not_reported_as_new(): void
    {
        $rows = Timetable_retention::classify([
            $this->v('NOSTART', 'candidate', ['generation' => ['state' => 'running']]),
        ], date('Y-m-d'));

        $r = $this->classOf($rows, 'NOSTART');
        $this->assertNull($r['buildHours'], 'unknown must be null, not zero');
        $this->assertSame('This timetable is still being built.', $r['why']);

        // A timestamp that cannot be parsed is just as unknown as a missing
        // one. Reporting it as 0 hours would describe a build that may have
        // been stuck for a week as one that just started.
        $bad = Timetable_retention::classify([
            $this->v('BADTIME', 'candidate', ['generation' => [
                'state' => 'running', 'runId' => 'R9',
                'startedAt' => 'not a timestamp']]),
        ], date('Y-m-d'));
        $this->assertNull($this->classOf($bad, 'BADTIME')['buildHours'],
            'an unreadable start time is unknown, never zero');
    }

    public function test_giving_up_on_something_that_is_not_running_is_refused(): void
    {
        $fb = $this->seed();
        $st = $this->store($fb);

        foreach (['LIVE', 'P1'] as $id) {
            try {
                $st->abandonStuckRun(self::PLAN, $id);
                $this->fail("{$id} is not running; giving up on it makes no sense");
            } catch (Service_exception $e) {
                $this->assertMatchesRegularExpression('~not being built~i', $e->getMessage());
            }
        }
    }

    public function test_giving_up_on_a_missing_version_is_refused(): void
    {
        try {
            $this->store($this->seed())->abandonStuckRun(self::PLAN, 'GHOST');
            $this->fail('a version that does not exist must be refused');
        } catch (Service_exception $e) {
            // Not merely "it threw": a missing version and a version in the
            // wrong state are different problems, and the operator has to be
            // told which one they have.
            $this->assertMatchesRegularExpression('~does not exist~i', $e->getMessage(),
                'the refusal must name the real problem, not report the wrong one');
            $this->assertDoesNotMatchRegularExpression('~not being built~i', $e->getMessage());
        }
    }

    /**
     * A run with no identifier cannot be closed safely: failGenerationRun
     * matches on runId, and closing the wrong run would mark somebody else's
     * work failed.
     */
    public function test_a_build_with_no_run_id_is_left_alone(): void
    {
        $fb  = $this->seed();
        $key = self::SCHOOL . '_' . self::SESS . '_' . self::PLAN . '_NOID';
        $fb->store['timetableVersions'][$key] = $this->v('NOID', 'candidate', [
            'generation' => ['state' => 'running', 'startedAt' => date('c')],
        ]);

        try {
            $this->store($fb)->abandonStuckRun(self::PLAN, 'NOID');
            $this->fail('a run that cannot be identified must not be closed');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~cannot be identified~i', $e->getMessage());
        }

        $this->assertSame('running',
            $fb->store['timetableVersions'][$key]['generation']['state'],
            'and it must be left exactly as it was');
    }
}