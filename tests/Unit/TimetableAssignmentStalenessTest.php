<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__ . '/../../system/');
if (!defined('APPPATH'))  define('APPPATH',  __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../../application/libraries/Timetable_plan_store.php';

/**
 * OPTION B — subject assignments changed, so affected work needs re-checking.
 *
 * The legacy behaviour this replaces: saving subject assignments ran
 * reconcileClassFromMatrix(), which reached into the LIVE timetable and rewrote
 * the teacher on every period whose subject matched. A change to who teaches
 * what silently edited the timetable teachers were already looking at.
 *
 * The approved policy is that such a change may mark work as needing another
 * check, and may do nothing else. These tests hold that line from both sides:
 * the right versions ARE marked, and published content is NOT touched.
 */
class FakeStalenessFirestore
{
    public array $versions = [];
    public array $days     = [];
    public array $writes   = [];
    public array $unreadableVersions = [];   // versionIds whose days cannot be read

    public function firestoreGetOrFail($collection, $docId)
    {
        return $this->versions[$docId] ?? null;
    }

    public function firestoreQueryOrFail($collection, $conditions = [], $orderBy = null, $dir = 'ASC', $limit = null)
    {
        $want = [];
        foreach ($conditions as $c) { $want[(string) ($c[0] ?? '')] = $c[2] ?? null; }

        if ($collection === 'timetableVersionDays'
            && in_array((string) ($want['versionId'] ?? ''), $this->unreadableVersions, true)) {
            throw new \RuntimeException('index missing');
        }

        $src = ['timetableVersionDays' => $this->days,
                'timetableVersions'    => $this->versions][$collection] ?? [];
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
        $t = ['timetableVersions' => 'versions', 'timetableVersionDays' => 'days'][$collection] ?? null;
        if ($t !== null) {
            $this->{$t}[$docId] = $merge ? array_merge($this->{$t}[$docId] ?? [], $data) : $data;
        }
        $this->writes[] = ['collection' => $collection, 'docId' => $docId,
                           'data' => $data, 'merge' => $merge];
        return true;
    }
}

final class TimetableAssignmentStalenessTest extends TestCase
{
    private const SCHOOL = 'SCH_A';
    private const SESS   = '2026-2027';
    private const PLAN   = 'REGULAR';

    private function store(FakeStalenessFirestore $fb): Timetable_plan_store
    {
        return (new Timetable_plan_store())->init($fb, self::SCHOOL, self::SESS, 'STA0001');
    }

    private function vDoc(string $vid): string
    {
        return self::SCHOOL . '_' . self::SESS . '_' . self::PLAN . '_' . $vid;
    }

    private function addVersion(FakeStalenessFirestore $fb, string $vid,
                                string $status, string $classSection): void
    {
        $fb->versions[$this->vDoc($vid)] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => self::PLAN, 'versionId' => $vid, 'status' => $status,
            'effectiveFrom' => '2026-06-01', 'effectiveTo' => '',
            'validationStale' => false,
            'validation' => ['hardCount' => 0, 'publishable' => true,
                             'fingerprint' => 'fp_' . $vid, 'hard' => [], 'soft' => []],
        ];
        $fb->days[$this->vDoc($vid) . '_day'] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => self::PLAN, 'versionId' => $vid,
            'classSection' => $classSection, 'day' => 'Monday',
            'periods' => [[
                'periodNumber' => 1, 'subject' => 'Mathematics', 'teacher' => 'Old',
                'teacherId' => 'STA_OLD', 'startTime' => '', 'endTime' => '',
                'room' => '', 'type' => 'class',
            ]],
            'manuallyEdited' => false,
        ];
    }

    private function isStale(FakeStalenessFirestore $fb, string $vid): bool
    {
        return (bool) ($fb->versions[$this->vDoc($vid)]['validationStale'] ?? false);
    }

    // ── the right versions are marked ───────────────────────────────────

    public function test_a_candidate_using_the_class_is_marked_stale(): void
    {
        $fb = new FakeStalenessFirestore();
        $this->addVersion($fb, 'C1', 'candidate', 'Class 8th/Section A');

        $r = $this->store($fb)->markVersionsStaleForClass('Class 8', 'Section A');

        $this->assertSame(['C1'], $r['marked']);
        $this->assertTrue($this->isStale($fb, 'C1'));
    }

    public function test_a_draft_using_the_class_is_marked_stale(): void
    {
        $fb = new FakeStalenessFirestore();
        $this->addVersion($fb, 'D1', 'draft', 'Class 8th/Section A');

        $this->store($fb)->markVersionsStaleForClass('Class 8', 'Section A');
        $this->assertTrue($this->isStale($fb, 'D1'));
    }

    public function test_a_version_for_a_different_class_is_left_alone(): void
    {
        $fb = new FakeStalenessFirestore();
        $this->addVersion($fb, 'C1', 'candidate', 'Class 9th/Section A');

        $r = $this->store($fb)->markVersionsStaleForClass('Class 8', 'Section A');

        $this->assertSame([], $r['marked']);
        $this->assertFalse($this->isStale($fb, 'C1'),
            'a candidate for a class nobody touched must not be disturbed');
    }

    public function test_a_version_for_a_different_section_is_left_alone(): void
    {
        $fb = new FakeStalenessFirestore();
        $this->addVersion($fb, 'C1', 'candidate', 'Class 8th/Section B');

        $this->store($fb)->markVersionsStaleForClass('Class 8', 'Section A');
        $this->assertFalse($this->isStale($fb, 'C1'));
    }

    public function test_a_class_wide_change_marks_every_section_of_that_class(): void
    {
        $fb = new FakeStalenessFirestore();
        $this->addVersion($fb, 'A', 'candidate', 'Class 8th/Section A');
        $this->addVersion($fb, 'B', 'candidate', 'Class 8th/Section B');
        $this->addVersion($fb, 'C', 'candidate', 'Class 9th/Section A');

        // No section given: the assignment applies to the whole class.
        $r = $this->store($fb)->markVersionsStaleForClass('Class 8', '');

        sort($r['marked']);
        $this->assertSame(['A', 'B'], $r['marked']);
        $this->assertFalse($this->isStale($fb, 'C'));
    }

    public function test_the_class_name_is_normalised_before_matching(): void
    {
        $fb = new FakeStalenessFirestore();
        $this->addVersion($fb, 'C1', 'candidate', 'Class 8th/Section A');

        // "8" must match a stored "Class 8th" — the canonical normaliser decides,
        // not a string compare.
        $this->store($fb)->markVersionsStaleForClass('8', 'A');
        $this->assertTrue($this->isStale($fb, 'C1'));
    }

    // ── published data is untouchable ───────────────────────────────────

    public function test_a_published_version_is_never_marked_or_modified(): void
    {
        $fb = new FakeStalenessFirestore();
        $this->addVersion($fb, 'P1', 'published', 'Class 8th/Section A');
        $before = $fb->versions[$this->vDoc('P1')];

        $r = $this->store($fb)->markVersionsStaleForClass('Class 8', 'Section A');

        $this->assertSame([], $r['marked']);
        $this->assertSame(1, $r['skippedPublished']);
        $this->assertSame($before, $fb->versions[$this->vDoc('P1')],
            'a published timetable is an immutable record of what was in force; an '
            . 'assignment change cannot reach back into it');
    }

    public function test_a_superseded_version_is_left_alone(): void
    {
        $fb = new FakeStalenessFirestore();
        $this->addVersion($fb, 'S1', 'superseded', 'Class 8th/Section A');

        $this->store($fb)->markVersionsStaleForClass('Class 8', 'Section A');
        $this->assertFalse($this->isStale($fb, 'S1'));
    }

    public function test_timetable_content_is_never_changed(): void
    {
        $fb = new FakeStalenessFirestore();
        $this->addVersion($fb, 'C1', 'candidate', 'Class 8th/Section A');
        $daysBefore = $fb->days;

        $this->store($fb)->markVersionsStaleForClass('Class 8', 'Section A');

        $this->assertSame($daysBefore, $fb->days,
            'this policy marks work as needing a check; it does not regenerate, '
            . 'rewrite a teacher, or delete anything');

        $collections = array_values(array_unique(array_column($fb->writes, 'collection')));
        $this->assertSame(['timetableVersions'], $collections,
            'only the verdict flag is written — never day content, and never the '
            . 'legacy collection the Teacher app reads');
    }

    // ── explainability ──────────────────────────────────────────────────

    public function test_the_reason_is_recorded_and_names_the_class(): void
    {
        $fb = new FakeStalenessFirestore();
        $this->addVersion($fb, 'C1', 'candidate', 'Class 8th/Section A');

        $this->store($fb)->markVersionsStaleForClass('Class 8', 'Section A');

        $why = (string) ($fb->versions[$this->vDoc('C1')]['staleReason'] ?? '');
        $this->assertMatchesRegularExpression('~subject assignments~i', $why);
        $this->assertMatchesRegularExpression('~Class 8th~', $why,
            '"needs another check" is not actionable; the operator must be told '
            . 'what changed');
    }

    public function test_the_reason_reaches_the_operator_through_the_verdict(): void
    {
        $fb = new FakeStalenessFirestore();
        $this->addVersion($fb, 'C1', 'candidate', 'Class 8th/Section A');
        $store = $this->store($fb);

        $store->markVersionsStaleForClass('Class 8', 'Section A');
        $cur = $store->currentVerdict(self::PLAN, 'C1');

        $this->assertFalse($cur['trusted']);
        $this->assertMatchesRegularExpression('~subject assignments~i', $cur['reason']);
    }

    public function test_a_hand_edit_records_its_own_distinct_reason(): void
    {
        $fb = new FakeStalenessFirestore();
        $this->addVersion($fb, 'C1', 'candidate', 'Class 8th/Section A');

        $this->store($fb)->saveCandidateDay(
            self::PLAN, 'C1', 'Class 8th/Section A', 'Monday', [], true);

        $why = (string) ($fb->versions[$this->vDoc('C1')]['staleReason'] ?? '');
        $this->assertMatchesRegularExpression('~by hand~i', $why,
            'an edit and an assignment change are different causes and must not '
            . 'be reported with the same sentence');
    }

    // ── publishing is genuinely gated by this ───────────────────────────

    public function test_a_marked_version_cannot_be_published_until_rechecked(): void
    {
        $fb = new FakeStalenessFirestore();
        $this->addVersion($fb, 'C1', 'candidate', 'Class 8th/Section A');
        $fb->versions[$this->vDoc('C1')]['generation'] =
            ['runId' => 'G', 'state' => Timetable_plan_service::GEN_COMPLETE, 'daysWritten' => 1];
        $store = $this->store($fb);

        // Make the verdict genuinely trustworthy first.
        $store->validateCandidate(self::PLAN, 'C1');
        $this->assertTrue($store->currentVerdict(self::PLAN, 'C1')['trusted'],
            'premise: the verdict is trusted before the assignment change');

        $store->markVersionsStaleForClass('Class 8', 'Section A');

        try {
            $store->publishCandidate(self::PLAN, 'C1', '2026-07-01');
            $this->fail('a version marked stale must not publish');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~subject assignments~i', $e->getMessage());
        }
        $this->assertSame(Timetable_plan_service::STATUS_CANDIDATE,
            $fb->versions[$this->vDoc('C1')]['status']);
    }

    public function test_re_validating_clears_the_staleness(): void
    {
        $fb = new FakeStalenessFirestore();
        $this->addVersion($fb, 'C1', 'candidate', 'Class 8th/Section A');
        $fb->versions[$this->vDoc('C1')]['generation'] =
            ['runId' => 'G', 'state' => Timetable_plan_service::GEN_COMPLETE, 'daysWritten' => 1];
        $store = $this->store($fb);

        $store->markVersionsStaleForClass('Class 8', 'Section A');
        $this->assertFalse($store->currentVerdict(self::PLAN, 'C1')['trusted']);

        $store->validateCandidate(self::PLAN, 'C1');

        $this->assertTrue($store->currentVerdict(self::PLAN, 'C1')['trusted'],
            'checking it again must clear the block, or the operator is stuck');
    }

    // ── fail SAFE, which here means marking, not skipping ───────────────

    /**
     * Everywhere else an unreadable input means REFUSE. Here the conservative
     * direction is the opposite: marking costs a re-check, while skipping leaves
     * a verdict looking fresh that may no longer be true — and publishing trusts
     * that flag.
     */
    public function test_a_version_that_cannot_be_inspected_is_marked_anyway(): void
    {
        $fb = new FakeStalenessFirestore();
        $this->addVersion($fb, 'C1', 'candidate', 'Class 8th/Section A');
        $fb->unreadableVersions = ['C1'];

        $r = $this->store($fb)->markVersionsStaleForClass('Class 8', 'Section A');

        $this->assertSame(['C1'], $r['marked']);
        $this->assertSame(['C1'], $r['unreadable']);
        $this->assertTrue($this->isStale($fb, 'C1'),
            'a version we could not inspect must not keep a verdict that looks fresh');
    }

    public function test_an_unreadable_version_list_is_a_real_failure(): void
    {
        $fb = new class extends FakeStalenessFirestore {
            public function firestoreQueryOrFail($collection, $conditions = [], $orderBy = null, $dir = 'ASC', $limit = null)
            {
                if ($collection === 'timetableVersions') { throw new \RuntimeException('index missing'); }
                return parent::firestoreQueryOrFail($collection, $conditions, $orderBy, $dir, $limit);
            }
        };

        $this->expectException(Service_exception::class);
        $this->store($fb)->markVersionsStaleForClass('Class 8', 'Section A');
    }

    // ── tenant and session ──────────────────────────────────────────────

    public function test_another_tenants_versions_are_never_marked(): void
    {
        $fb = new FakeStalenessFirestore();
        $this->addVersion($fb, 'C1', 'candidate', 'Class 8th/Section A');
        $fb->versions[$this->vDoc('C1')]['schoolId'] = 'SCH_OTHER';

        $r = $this->store($fb)->markVersionsStaleForClass('Class 8', 'Section A');
        $this->assertSame([], $r['marked']);
    }

    public function test_another_sessions_versions_are_never_marked(): void
    {
        $fb = new FakeStalenessFirestore();
        $this->addVersion($fb, 'C1', 'candidate', 'Class 8th/Section A');
        $fb->versions[$this->vDoc('C1')]['session'] = '2025-2026';

        $r = $this->store($fb)->markVersionsStaleForClass('Class 8', 'Section A');
        $this->assertSame([], $r['marked']);
    }

    // ── the retired legacy path ─────────────────────────────────────────

    public function test_the_retired_sync_writes_nothing_and_says_so(): void
    {
        require_once __DIR__ . '/../../application/libraries/Timetable_service.php';

        $svc = new Timetable_service();
        $r   = $svc->reconcileClassFromMatrix('Class 8', 'Section A');

        $this->assertSame(0, $r['docsUpdated']);
        $this->assertSame(0, $r['periodsChanged']);
        $this->assertTrue($r['retired'],
            'a caller must be able to tell that this did nothing, rather than '
            . 'reading a zero as "there was nothing to change"');
    }
    // ── the schedule itself changing ─────────────────────────────────────

    /**
     * A change to Period Scheduling is the approved Option B decision one level
     * up: it does not alter who teaches what, it alters THE SHAPE OF THE WEEK
     * every proposal was built into.
     *
     * A proposal generated for six periods a day keeps a "no problems" verdict
     * after the school moves to eight, and publishing it would hand teachers a
     * week the school does not run. So every unpublished proposal is marked —
     * the timetable itself is never rewritten, only the verdict invalidated.
     */
    public function test_a_schedule_change_marks_every_proposal_stale(): void
    {
        $fb = new FakeStalenessFirestore();
        $this->addVersion($fb, 'V_DRAFT', 'draft',     'Class 8/Section A');
        $this->addVersion($fb, 'V_CAND1', 'candidate', 'Class 8/Section A');
        $this->addVersion($fb, 'V_CAND2', 'candidate', 'Class 9/Section B');

        $out = $this->store($fb)->markAllVersionsStaleForSchedule();

        sort($out['marked']);
        $this->assertSame(['V_CAND1', 'V_CAND2', 'V_DRAFT'], $out['marked'],
            'a schedule change affects every section at once, so unlike an '
            . 'assignment change it is not scoped to one class');
        $this->assertSame(3, $out['checked']);
        $this->assertSame(0, $out['skippedPublished']);

        foreach (['V_DRAFT', 'V_CAND1', 'V_CAND2'] as $id) {
            $this->assertTrue($this->isStale($fb, $id), "{$id} must need re-checking");
            $this->assertTrue($fb->versions[$this->vDoc($id)]['factsStale'],
                'its measurements describe the old week too');
        }
    }

    /**
     * Published and superseded versions are untouched. They record what was in
     * force under the OLD schedule, and that stays true — invalidating them
     * would falsify the record.
     */
    public function test_a_schedule_change_never_touches_a_published_timetable(): void
    {
        $fb = new FakeStalenessFirestore();
        $this->addVersion($fb, 'V_LIVE', 'published',  'Class 8/Section A');
        $this->addVersion($fb, 'V_OLD',  'superseded', 'Class 8/Section A');
        $this->addVersion($fb, 'V_CAND', 'candidate',  'Class 8/Section A');

        $before = [
            'V_LIVE' => $fb->versions[$this->vDoc('V_LIVE')],
            'V_OLD'  => $fb->versions[$this->vDoc('V_OLD')],
        ];

        $out = $this->store($fb)->markAllVersionsStaleForSchedule();

        $this->assertSame(['V_CAND'], $out['marked']);
        $this->assertSame(2, $out['skippedPublished']);

        foreach ($before as $id => $doc) {
            $this->assertSame($doc, $fb->versions[$this->vDoc($id)],
                "{$id} must be byte-identical: it records a week that really was "
                . 'in force');
        }
    }

    /** The reason must say what happened, not merely that something did. */
    public function test_the_operator_is_told_why_it_needs_re_checking(): void
    {
        $fb = new FakeStalenessFirestore();
        $this->addVersion($fb, 'V_CAND', 'candidate', 'Class 8/Section A');

        $out = $this->store($fb)->markAllVersionsStaleForSchedule();

        $this->assertMatchesRegularExpression('~Period Scheduling changed~i', $out['reason']);
        $this->assertMatchesRegularExpression('~different week~i', $out['reason']);
        $this->assertSame($out['reason'],
            $fb->versions[$this->vDoc('V_CAND')]['staleReason'],
            'and the reason must reach the version, not only the response');
    }

    /** A school with nothing built yet is a normal case, not an error. */
    public function test_a_school_with_no_proposals_marks_nothing(): void
    {
        $out = $this->store(new FakeStalenessFirestore())->markAllVersionsStaleForSchedule();

        $this->assertSame([], $out['marked']);
        $this->assertSame(0, $out['checked']);
    }

    /**
     * Saving the settings must still succeed even if the marking fails: the
     * schedule is already stored by then, and refusing the save would leave the
     * school unable to change its own timetable settings. The failure is logged
     * and reported as nothing marked, which is honest.
     */
    public function test_the_settings_save_does_not_depend_on_the_marking(): void
    {
        $ctrl = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Academic.php');

        // THE WIRING, not just the helper. A staleness rule that exists but is
        // never called is the most expensive kind of dead code: it reads as
        // implemented in every review and protects nothing.
        $save = strpos($ctrl, 'public function save_timetable_settings()');
        $this->assertNotFalse($save, 'premise: the settings save exists');
        $saveBody = substr($ctrl, $save, strpos($ctrl, "
    }", $save) - $save);
        $this->assertStringContainsString('_timetable_schedule_changed()', $saveBody,
            'saving Period Scheduling must mark proposals stale — otherwise a '
            . 'proposal built for the old week keeps a clean verdict');

        $i = strpos($ctrl, 'private function _timetable_schedule_changed()');
        $this->assertNotFalse($i, 'the settings save must mark proposals stale');
        $body = substr($ctrl, $i, strpos($ctrl, "\n    }", $i) - $i);

        $this->assertStringContainsString('catch (\Throwable $e)', $body,
            'a marking failure must not fail the save');
        $this->assertStringContainsString('return [];', $body,
            'and must report nothing marked rather than pretending it worked');
        $this->assertStringContainsString('markAllVersionsStaleForSchedule', $body);
    }
}