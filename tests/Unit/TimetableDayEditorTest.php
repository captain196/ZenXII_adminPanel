<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__ . '/../../system/');
if (!defined('APPPATH'))  define('APPPATH',  __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../../application/libraries/Timetable_plan_store.php';

/**
 * THE CANONICAL DAY-GRID EDITOR.
 *
 * This replaces the legacy per-period editor, which wrote straight into the
 * collection teachers read. The replacement edits a DRAFT OR CANDIDATE version
 * and nothing else.
 *
 * Two things from the legacy editor are reproduced here because they are
 * user-facing correctness, not implementation detail:
 *
 *   - the rest of the day survives the edit (ACAD-D6/D19). The read that decides
 *     what is preserved is fail-loud, because overwriting content we could not
 *     read is precisely the data loss those defects were raised for.
 *   - the day is sized from configured periods-per-day and REFUSES when that is
 *     unset (ACAD-D7), instead of defaulting to a literal and truncating a
 *     school that runs more periods than the default.
 *
 * One thing is deliberately NOT reproduced: the legacy teacher double-booking
 * scan across the LIVE collection. A candidate is a self-contained timetable and
 * may legitimately move a teacher the live timetable has elsewhere, so checking
 * a candidate against live data would refuse correct edits and accept wrong
 * ones. Clashes inside the candidate are Timetable_validation_service's job, and
 * an edit marks the verdict stale so publishing cannot skip that check.
 */
class FakeEditorFirestore
{
    public array $versions = [];
    public array $days     = [];
    public array $settings = [];
    public array $writes   = [];
    public bool  $dayQueryUnreadable = false;
    public bool  $settingsUnreadable = false;
    public bool  $dayDeleteFails     = false;

    public function firestoreGetOrFail($collection, $docId)
    {
        if ($collection === 'timetableSettings') {
            if ($this->settingsUnreadable) { throw new \RuntimeException('read failed'); }
            return $this->settings[$docId] ?? null;
        }
        return $this->versions[$docId] ?? null;
    }

    public function firestoreQueryOrFail($collection, $conditions = [], $orderBy = null, $dir = 'ASC', $limit = null)
    {
        if ($collection === 'timetableVersionDays' && $this->dayQueryUnreadable) {
            throw new \RuntimeException('index missing');
        }
        $want = [];
        foreach ($conditions as $c) { $want[(string) ($c[0] ?? '')] = $c[2] ?? null; }

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

    public function firestoreDelete($collection, $docId)
    {
        if ($collection === 'timetableVersionDays' && $this->dayDeleteFails) {
            throw new \RuntimeException('delete refused');
        }
        $t = ['timetableVersions' => 'versions', 'timetableVersionDays' => 'days'][$collection] ?? null;
        if ($t !== null) { unset($this->{$t}[$docId]); }
        $this->writes[] = ['collection' => $collection, 'docId' => $docId, 'op' => 'delete'];
        return true;
    }
    /**
     * The interactive editor commits conditionally, so this stand-in needs the
     * primitive. It applies the write and reports success WITHOUT evaluating
     * the precondition: nothing here is testing concurrency, and a stand-in
     * that pretended to enforce it would give false confidence.
     * TimetableDayConcurrencyTest carries one that honours it properly.
     */
    public function firestoreCommitBatchStatus(array $ops)
    {
        foreach ($ops as $op) {
            $this->firestoreSet($op['collection'], $op['docId'],
                $op['data'], (bool) ($op['merge'] ?? false));
        }
        // 'SUCCESS' — the literal the real client returns (COMMIT_SUCCESS). 'OK'
        // matched only what the production check happened to compare against, so
        // the double and the code agreed with each other and not with Firestore.
        return ['status' => 'SUCCESS'];
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

final class TimetableDayEditorTest extends TestCase
{
    private const SCHOOL = 'SCH_A';
    private const SESS   = '2026-2027';
    private const PLAN   = 'REGULAR';
    private const SEC    = 'Class 8th/Section A';

    private function store(FakeEditorFirestore $fb): Timetable_plan_store
    {
        return (new Timetable_plan_store())->init($fb, self::SCHOOL, self::SESS, 'STA0001');
    }

    private function vDoc(string $vid): string
    {
        return self::SCHOOL . '_' . self::SESS . '_' . self::PLAN . '_' . $vid;
    }

    private function period(int $n, string $sub, string $tid): array
    {
        return ['periodNumber' => $n, 'subject' => $sub, 'teacher' => $tid ? 'T ' . $tid : '',
                'teacherId' => $tid, 'startTime' => '09:0' . $n . 'AM',
                'endTime' => '09:4' . $n . 'AM', 'room' => 'R' . $n, 'type' => 'class'];
    }

    private function fb(string $status = 'candidate', int $periodsPerDay = 4): FakeEditorFirestore
    {
        $fb = new FakeEditorFirestore();
        $fb->settings[self::SCHOOL . '_' . self::SESS] = ['periodsPerDay' => $periodsPerDay];
        $fb->versions[$this->vDoc('V1')] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => self::PLAN, 'versionId' => 'V1', 'status' => $status,
            'effectiveFrom' => '2026-06-01', 'effectiveTo' => '',
            'validationStale' => false,
            'validation' => ['hardCount' => 0, 'publishable' => true,
                             'fingerprint' => 'abc', 'hard' => [], 'soft' => []],
        ];
        $fb->days[$this->vDoc('V1') . '_Class 8th_Section A_Monday'] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => self::PLAN, 'versionId' => 'V1',
            'classSection' => self::SEC, 'day' => 'Monday',
            'periods' => [
                $this->period(1, 'Mathematics', 'STA001'),
                $this->period(2, 'Science', 'STA002'),
                $this->period(3, 'English', 'STA003'),
                $this->period(4, '', ''),
            ],
            'manuallyEdited' => false,
        ];
        return $fb;
    }

    private function storedDay(FakeEditorFirestore $fb): array
    {
        return $fb->days[$this->vDoc('V1') . '_Class 8th_Section A_Monday'];
    }

    // ── the edit itself ─────────────────────────────────────────────────

    public function test_editing_one_period_changes_only_that_period(): void
    {
        $fb = $this->fb();
        $this->store($fb)->saveCandidatePeriod(self::PLAN, 'V1', self::SEC, 'Monday', 1, [
            'subject' => 'History', 'teacherId' => 'STA009', 'teacher' => 'R Rao',
        ]);

        $p = $this->storedDay($fb)['periods'];
        $this->assertSame('History', $p[1]['subject']);
        $this->assertSame('STA009', $p[1]['teacherId']);
        $this->assertSame('R Rao', $p[1]['teacher']);

        // ACAD-D6/D19: the rest of the day must survive.
        $this->assertSame('Mathematics', $p[0]['subject']);
        $this->assertSame('English', $p[2]['subject']);
        $this->assertSame('STA003', $p[2]['teacherId']);
        $this->assertSame('R3', $p[2]['room'],
            'untouched periods must be preserved whole, not rebuilt from defaults');
    }

    public function test_the_edited_period_keeps_its_time_slot(): void
    {
        $fb = $this->fb();
        $this->store($fb)->saveCandidatePeriod(self::PLAN, 'V1', self::SEC, 'Monday', 1, [
            'subject' => 'History', 'teacherId' => 'STA009',
        ]);

        $p = $this->storedDay($fb)['periods'][1];
        $this->assertSame('09:02AM', $p['startTime'],
            'times belong to the slot, not the lesson; an edit must not blank them');
        $this->assertSame('09:42AM', $p['endTime']);
    }

    public function test_a_period_can_be_cleared(): void
    {
        $fb = $this->fb();
        $this->store($fb)->saveCandidatePeriod(self::PLAN, 'V1', self::SEC, 'Monday', 0, [
            'subject' => '', 'teacherId' => '', 'teacher' => '',
        ]);

        $p = $this->storedDay($fb)['periods'];
        $this->assertSame('', $p[0]['subject']);
        $this->assertSame('', $p[0]['teacherId']);
        $this->assertSame('Science', $p[1]['subject'], 'clearing one cell must not clear others');
    }

    public function test_the_day_is_sized_from_configured_periods_per_day(): void
    {
        $fb = $this->fb('candidate', 8);
        $this->store($fb)->saveCandidatePeriod(self::PLAN, 'V1', self::SEC, 'Monday', 1, [
            'subject' => 'History', 'teacherId' => 'STA009',
        ]);

        $p = $this->storedDay($fb)['periods'];
        $this->assertCount(8, $p,
            'ACAD-D7: a school running 8 periods must not have its day truncated '
            . 'to a hard-coded default');
        $this->assertSame(8, $p[7]['periodNumber']);
        $this->assertSame('', $p[7]['subject']);
    }

    public function test_a_day_that_does_not_exist_yet_can_be_started(): void
    {
        $fb = $this->fb();
        $this->store($fb)->saveCandidatePeriod(self::PLAN, 'V1', self::SEC, 'Tuesday', 2, [
            'subject' => 'Art', 'teacherId' => 'STA007',
        ]);

        $doc = $fb->days[$this->vDoc('V1') . '_Class 8th_Section A_Tuesday'];
        $this->assertCount(4, $doc['periods']);
        $this->assertSame('Art', $doc['periods'][2]['subject']);
        $this->assertSame('', $doc['periods'][0]['subject']);
    }

    // ── lifecycle: only draft and candidate are editable ────────────────

    public function test_a_published_version_refuses_the_edit(): void
    {
        $fb = $this->fb('published');

        try {
            $this->store($fb)->saveCandidatePeriod(self::PLAN, 'V1', self::SEC, 'Monday', 0, [
                'subject' => 'History', 'teacherId' => 'STA009',
            ]);
            $this->fail('a published timetable must not be editable');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~already been published~i', $e->getMessage());
        }

        $this->assertSame('Mathematics', $this->storedDay($fb)['periods'][0]['subject'],
            'a refused edit must leave the timetable exactly as it was');
    }

    public function test_a_draft_is_editable(): void
    {
        $fb = $this->fb('draft');
        $this->store($fb)->saveCandidatePeriod(self::PLAN, 'V1', self::SEC, 'Monday', 0, [
            'subject' => 'History', 'teacherId' => 'STA009',
        ]);
        $this->assertSame('History', $this->storedDay($fb)['periods'][0]['subject']);
    }

    public function test_a_superseded_version_refuses_the_edit(): void
    {
        $fb = $this->fb('superseded');
        $this->expectException(Service_exception::class);
        $this->store($fb)->saveCandidatePeriod(self::PLAN, 'V1', self::SEC, 'Monday', 0, [
            'subject' => 'History', 'teacherId' => 'STA009',
        ]);
    }

    public function test_the_lifecycle_is_checked_before_anything_is_read(): void
    {
        $fb = $this->fb('published');
        $fb->settingsUnreadable = true;   // would throw if it got that far

        try {
            $this->store($fb)->saveCandidatePeriod(self::PLAN, 'V1', self::SEC, 'Monday', 0, []);
            $this->fail('expected a refusal');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~already been published~i', $e->getMessage(),
                'the immutability refusal must come first, not a settings error');
        }
    }

    // ── an edit invalidates the recorded verdict ────────────────────────

    public function test_an_edit_marks_the_verdict_stale(): void
    {
        $fb = $this->fb();
        $this->assertFalse($fb->versions[$this->vDoc('V1')]['validationStale'],
            'premise: the verdict starts fresh');

        $this->store($fb)->saveCandidatePeriod(self::PLAN, 'V1', self::SEC, 'Monday', 1, [
            'subject' => 'History', 'teacherId' => 'STA009',
        ]);

        $this->assertTrue($fb->versions[$this->vDoc('V1')]['validationStale'],
            'a hand edit must invalidate the recorded verdict, or a timetable could '
            . 'be published on a check that describes a different arrangement');
    }

    public function test_the_edited_day_is_recorded_as_a_manual_edit(): void
    {
        $fb = $this->fb();
        $this->store($fb)->saveCandidatePeriod(self::PLAN, 'V1', self::SEC, 'Monday', 1, [
            'subject' => 'History', 'teacherId' => 'STA009',
        ]);

        $this->assertTrue($this->storedDay($fb)['manuallyEdited'],
            'the record that a human chose this must survive, or a later generation '
            . 'run has no way to know it is discarding a decision');
    }

    // ── fail loud ───────────────────────────────────────────────────────

    public function test_an_unreadable_day_refuses_rather_than_overwriting(): void
    {
        $fb = $this->fb();
        $fb->dayQueryUnreadable = true;

        try {
            $this->store($fb)->saveCandidatePeriod(self::PLAN, 'V1', self::SEC, 'Monday', 1, [
                'subject' => 'History', 'teacherId' => 'STA009',
            ]);
            $this->fail('an unreadable day must not be overwritten');
        } catch (Service_exception $e) {
            $this->assertDoesNotMatchRegularExpression('~saved~i', $e->getMessage());
        }

        $this->assertSame('Mathematics', $this->storedDay($fb)['periods'][0]['subject'],
            'ACAD-D6: if we cannot see what is stored we must not write over it');
    }

    public function test_unconfigured_periods_per_day_refuses(): void
    {
        $fb = $this->fb();
        $fb->settings[self::SCHOOL . '_' . self::SESS] = ['periodsPerDay' => 0];

        try {
            $this->store($fb)->saveCandidatePeriod(self::PLAN, 'V1', self::SEC, 'Monday', 1, [
                'subject' => 'History', 'teacherId' => 'STA009',
            ]);
            $this->fail('an unknown day length must not be guessed at');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~not configured~i', $e->getMessage());
        }
        $this->assertSame(4, count($this->storedDay($fb)['periods']));
    }

    public function test_unreadable_settings_refuse(): void
    {
        $fb = $this->fb();
        $fb->settingsUnreadable = true;

        $this->expectException(Service_exception::class);
        $this->expectExceptionMessageMatches('~could not read the period settings~i');
        $this->store($fb)->saveCandidatePeriod(self::PLAN, 'V1', self::SEC, 'Monday', 1, [
            'subject' => 'History', 'teacherId' => 'STA009',
        ]);
    }

    public function test_invalid_addresses_are_refused(): void
    {
        $fb = $this->fb();
        foreach ([['', 'Monday', 0], [self::SEC, '', 0], [self::SEC, 'Monday', -1]] as $args) {
            try {
                $this->store($fb)->saveCandidatePeriod(
                    self::PLAN, 'V1', $args[0], $args[1], $args[2], ['subject' => 'X']);
                $this->fail('an incomplete address must be refused');
            } catch (Service_exception $e) {
                $this->assertMatchesRegularExpression('~required~i', $e->getMessage());
            }
        }
    }

    // ── it never touches live data ──────────────────────────────────────

    public function test_editing_never_writes_the_legacy_collection(): void
    {
        $fb = $this->fb();
        $this->store($fb)->saveCandidatePeriod(self::PLAN, 'V1', self::SEC, 'Monday', 1, [
            'subject' => 'History', 'teacherId' => 'STA009',
        ]);

        $written = array_values(array_unique(array_column($fb->writes, 'collection')));
        sort($written);
        $this->assertSame(['timetableVersionDays', 'timetableVersions'], $written,
            'the canonical editor replaces a direct-write editor; writing the live '
            . 'collection here would recreate exactly the problem it removes');
    }

    // ── tenant and session ──────────────────────────────────────────────

    public function test_another_tenants_version_cannot_be_edited(): void
    {
        $fb = $this->fb();
        $fb->versions[$this->vDoc('V1')]['schoolId'] = 'SCH_OTHER';

        $this->expectException(Service_exception::class);
        $this->store($fb)->saveCandidatePeriod(self::PLAN, 'V1', self::SEC, 'Monday', 1, [
            'subject' => 'History', 'teacherId' => 'STA009',
        ]);
    }

    public function test_another_sessions_version_cannot_be_edited(): void
    {
        $fb = $this->fb();
        $fb->versions[$this->vDoc('V1')]['session'] = '2025-2026';

        $this->expectException(Service_exception::class);
        $this->store($fb)->saveCandidatePeriod(self::PLAN, 'V1', self::SEC, 'Monday', 1, [
            'subject' => 'History', 'teacherId' => 'STA009',
        ]);
    }

    // ── reading a version for the editor ────────────────────────────────

    public function test_reading_reports_whether_the_version_is_editable(): void
    {
        foreach (['draft' => true, 'candidate' => true,
                  'published' => false, 'superseded' => false] as $status => $editable) {
            $fb = $this->fb($status);
            $r  = $this->store($fb)->readVersionForEditing(self::PLAN, 'V1');

            $this->assertSame($editable, $r['editable'],
                "a {$status} version must report editable=" . var_export($editable, true)
                . ', so the screen can show the operator what they are looking at '
                . 'instead of refusing them cell by cell');
            $this->assertSame($status, $r['status']);
        }
    }

    /**
     * A hand-built draft has no generation block at all, and
     * isGenerationComplete() is false for it. Gating the editor on that helper
     * would refuse exactly the version the editor exists to serve.
     */
    public function test_a_never_generated_draft_can_still_be_opened(): void
    {
        $fb = $this->fb('draft');
        unset($fb->versions[$this->vDoc('V1')]['generation']);

        $r = $this->store($fb)->readVersionForEditing(self::PLAN, 'V1');
        $this->assertCount(1, $r['days']);
        $this->assertTrue($r['editable']);
    }

    public function test_an_unreadable_day_set_is_a_failure_not_an_empty_timetable(): void
    {
        $fb = $this->fb();
        $fb->dayQueryUnreadable = true;

        $this->expectException(Service_exception::class);
        $this->store($fb)->readVersionForEditing(self::PLAN, 'V1');
    }

    public function test_a_missing_version_is_refused(): void
    {
        $fb = $this->fb();
        $this->expectException(Service_exception::class);
        $this->expectExceptionMessageMatches('~does not exist~i');
        $this->store($fb)->readVersionForEditing(self::PLAN, 'NOPE');
    }

    // ── the deliberate non-reproduction, pinned ─────────────────────────

    /**
     * The legacy editor refused a save when the same teacher appeared in another
     * section at that period IN THE LIVE COLLECTION. That check is not carried
     * forward, and this pins the reason: the canonical editor must not read live
     * data to decide what a candidate may contain.
     */
    public function test_the_editor_does_not_consult_live_data(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_plan_store.php');
        $i = strpos($src, 'public function saveCandidatePeriod(');
        $this->assertNotFalse($i);
        $body = substr($src, $i, (int) strpos(substr($src, $i), "\n    /**"));

        $this->assertStringNotContainsString('COLLECTION_LEGACY_TT', $body,
            'a candidate may legitimately move a teacher the live timetable has '
            . 'elsewhere; validating a candidate against live data would refuse '
            . 'correct edits and accept wrong ones');
    }

    /**
     * And the clash it stopped checking is genuinely still caught — by the
     * validator, which is the single authority on what is wrong with a timetable.
     */
    public function test_a_clash_created_by_editing_is_caught_by_validation(): void
    {
        $fb = $this->fb();
        // A second section sharing the slot.
        $fb->days[$this->vDoc('V1') . '_Class 9th_Section A_Monday'] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => self::PLAN, 'versionId' => 'V1',
            'classSection' => 'Class 9th/Section A', 'day' => 'Monday',
            'periods' => [$this->period(1, 'Science', 'STA050')],
            'manuallyEdited' => false,
        ];

        // Put STA050 in both sections at period 1.
        $this->store($fb)->saveCandidatePeriod(self::PLAN, 'V1', self::SEC, 'Monday', 0, [
            'subject' => 'Science', 'teacherId' => 'STA050', 'teacher' => 'T',
        ]);

        $days = $this->store($fb)->readVersionForEditing(self::PLAN, 'V1')['days'];
        $v = Timetable_validation_service::validate($days);

        $codes = array_column($v['hard'], 'code');
        $this->assertContains(Timetable_validation_service::H_TEACHER_DOUBLE_BOOKED, $codes,
            'the clash the legacy editor blocked at save time must still be caught '
            . 'before anyone sees it');
        $this->assertFalse($v['publishable'],
            'and it must block publishing, which is where it now matters');
    }
    // ── multi-cell (bulk) editing ───────────────────────────────────────

    public function test_several_cells_can_be_saved_at_once(): void
    {
        $fb = $this->fb();
        $this->store($fb)->saveCandidateDayCells(self::PLAN, 'V1', self::SEC, 'Monday', [
            0 => ['subject' => 'History', 'teacherId' => 'STA009', 'teacher' => 'A'],
            3 => ['subject' => 'Art',     'teacherId' => 'STA010', 'teacher' => 'B'],
        ]);

        $p = $this->storedDay($fb)['periods'];
        $this->assertSame('History', $p[0]['subject']);
        $this->assertSame('Art', $p[3]['subject']);
    }

    /**
     * The property that makes bulk editing safe: periods the operator did not
     * touch come from the SERVER's current copy, never from the browser's. This
     * is exactly what the legacy whole-week save got wrong (ACAD-D21).
     */
    public function test_a_bulk_save_preserves_every_untouched_period(): void
    {
        $fb = $this->fb();

        // Somebody else changed period 2 since this browser loaded the day.
        $key = $this->vDoc('V1') . '_Class 8th_Section A_Monday';
        $fb->days[$key]['periods'][1] = $this->period(2, 'Geography', 'STA077');

        $this->store($fb)->saveCandidateDayCells(self::PLAN, 'V1', self::SEC, 'Monday', [
            0 => ['subject' => 'History', 'teacherId' => 'STA009'],
        ]);

        $p = $this->storedDay($fb)['periods'];
        $this->assertSame('History', $p[0]['subject']);
        $this->assertSame('Geography', $p[1]['subject'],
            'a bulk save must not revert a change to a period this operator '
            . 'never touched');
        $this->assertSame('STA077', $p[1]['teacherId']);
    }

    public function test_a_bulk_save_with_no_changes_is_refused(): void
    {
        $fb = $this->fb();
        try {
            $this->store($fb)->saveCandidateDayCells(self::PLAN, 'V1', self::SEC, 'Monday', []);
            $this->fail('an empty change set must be refused, not written');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~no changes~i', $e->getMessage());
        }
        $this->assertSame('Mathematics', $this->storedDay($fb)['periods'][0]['subject']);
    }

    public function test_a_bulk_save_refuses_an_out_of_range_index(): void
    {
        $fb = $this->fb();
        $this->expectException(Service_exception::class);
        $this->store($fb)->saveCandidateDayCells(self::PLAN, 'V1', self::SEC, 'Monday', [
            -1 => ['subject' => 'History'],
        ]);
    }

    public function test_a_bulk_save_refuses_a_non_numeric_index(): void
    {
        $fb = $this->fb();
        $this->expectException(Service_exception::class);
        $this->store($fb)->saveCandidateDayCells(self::PLAN, 'V1', self::SEC, 'Monday', [
            'first' => ['subject' => 'History'],
        ]);
    }

    public function test_a_bulk_save_refuses_a_malformed_cell(): void
    {
        $fb = $this->fb();
        $this->expectException(Service_exception::class);
        $this->store($fb)->saveCandidateDayCells(self::PLAN, 'V1', self::SEC, 'Monday', [
            0 => 'not-an-array',
        ]);
    }

    public function test_a_bulk_save_on_a_published_version_is_refused(): void
    {
        $fb = $this->fb('published');
        try {
            $this->store($fb)->saveCandidateDayCells(self::PLAN, 'V1', self::SEC, 'Monday', [
                0 => ['subject' => 'History', 'teacherId' => 'STA009'],
            ]);
            $this->fail('published is immutable in bulk too');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~already been published~i', $e->getMessage());
        }
        $this->assertSame('Mathematics', $this->storedDay($fb)['periods'][0]['subject']);
    }

    public function test_a_bulk_save_marks_the_verdict_stale(): void
    {
        $fb = $this->fb();
        $this->store($fb)->saveCandidateDayCells(self::PLAN, 'V1', self::SEC, 'Monday', [
            0 => ['subject' => 'History', 'teacherId' => 'STA009'],
            1 => ['subject' => 'Art',     'teacherId' => 'STA010'],
        ]);
        $this->assertTrue($fb->versions[$this->vDoc('V1')]['validationStale']);
    }
    // ── discarding a proposal ───────────────────────────────────────────

    public function test_a_proposal_can_be_discarded_with_its_days(): void
    {
        $fb = $this->fb('candidate');
        $this->assertNotEmpty($fb->days);

        $r = $this->store($fb)->discardVersion(self::PLAN, 'V1');

        $this->assertSame(1, $r['daysRemoved']);
        $this->assertSame([], $fb->days,
            'the days belong to nothing else; leaving them would orphan the '
            . 'largest part of the data');
        $this->assertArrayNotHasKey($this->vDoc('V1'), $fb->versions);
    }

    public function test_a_draft_can_be_discarded(): void
    {
        $fb = $this->fb('draft');
        $this->store($fb)->discardVersion(self::PLAN, 'V1');
        $this->assertArrayNotHasKey($this->vDoc('V1'), $fb->versions);
    }

    /**
     * A published timetable is the record of what was in force. Attendance and
     * substitution are audited against it, so "what was the timetable on the
     * 12th" must stay answerable.
     */
    public function test_a_published_timetable_can_never_be_discarded(): void
    {
        $fb = $this->fb('published');

        try {
            $this->store($fb)->discardVersion(self::PLAN, 'V1');
            $this->fail('a published timetable must not be deletable');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~attendance is checked~i', $e->getMessage(),
                'the refusal must say why, not merely refuse');
        }

        $this->assertArrayHasKey($this->vDoc('V1'), $fb->versions);
        $this->assertNotEmpty($fb->days, 'and its days must survive too');
    }

    public function test_a_superseded_timetable_cannot_be_discarded(): void
    {
        $fb = $this->fb('superseded');
        $this->expectException(Service_exception::class);
        $this->store($fb)->discardVersion(self::PLAN, 'V1');
    }

    public function test_discarding_an_unreadable_version_is_refused(): void
    {
        $fb = $this->fb('candidate');
        $fb->dayQueryUnreadable = true;

        try {
            $this->store($fb)->discardVersion(self::PLAN, 'V1');
            $this->fail('a version whose days cannot be read must not be removed');
        } catch (Service_exception $e) {
            $this->assertArrayHasKey($this->vDoc('V1'), $fb->versions,
                'deleting the version while its days are unreadable would orphan '
                . 'them permanently');
        }
    }

    public function test_discarding_a_missing_version_is_refused(): void
    {
        $fb = $this->fb('candidate');
        $this->expectException(Service_exception::class);
        $this->expectExceptionMessageMatches('~does not exist~i');
        $this->store($fb)->discardVersion(self::PLAN, 'NOPE');
    }

    public function test_another_tenants_version_cannot_be_discarded(): void
    {
        $fb = $this->fb('candidate');
        $fb->versions[$this->vDoc('V1')]['schoolId'] = 'SCH_OTHER';

        $this->expectException(Service_exception::class);
        $this->store($fb)->discardVersion(self::PLAN, 'V1');
    }

    public function test_discarding_never_touches_the_legacy_collection(): void
    {
        $fb = $this->fb('candidate');
        $this->store($fb)->discardVersion(self::PLAN, 'V1');

        $written = array_values(array_unique(array_column($fb->writes, 'collection')));
        sort($written);
        $this->assertSame(['timetableVersionDays', 'timetableVersions'], $written,
            'discarding a proposal must not disturb what teachers can see');
    }
    /**
     * A half-removed version is worse than one left alone: its days are gone but
     * it still appears in the list, and opening it shows an empty week.
     */
    public function test_a_failed_day_removal_leaves_the_version_in_place(): void
    {
        $fb = $this->fb('candidate');
        $fb->dayDeleteFails = true;

        try {
            $this->store($fb)->discardVersion(self::PLAN, 'V1');
            $this->fail('a failed day removal must stop the discard');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~left\s+in place~i', $e->getMessage(),
                'the operator must be told it was NOT discarded');
        }

        $this->assertArrayHasKey($this->vDoc('V1'), $fb->versions,
            'the version must survive a failed removal, or it vanishes from the '
            . 'list while its days remain');
    }
    // ── naming a timetable ───────────────────────────────────────────────

    public function test_a_timetable_can_be_given_a_name_a_school_uses(): void
    {
        $fb = $this->fb('candidate');
        $out = $this->store($fb)->renameVersion(self::PLAN, 'V1', '  Winter   timetable ');

        $this->assertSame('Winter timetable', $out['label'],
            'surrounding and repeated whitespace must be tidied, not stored');
        $this->assertSame('Winter timetable',
            $fb->versions[$this->vDoc('V1')]['label'] ?? null);
    }

    /**
     * A PUBLISHED timetable can be renamed, and that is deliberate.
     *
     * Published DATA is immutable — a correction is a new version. A label is
     * not that data: no lesson changes, and resolution is strictly by effective
     * date, so the name is never consulted to decide what is in force. Naming
     * the timetable that is actually running is the entire reason a school
     * wants this, and refusing here would leave the feature working only on
     * drafts.
     */
    public function test_a_published_timetable_can_be_named(): void
    {
        $fb = $this->fb('published');
        $this->store($fb)->renameVersion(self::PLAN, 'V1', 'Winter');

        $doc = $fb->versions[$this->vDoc('V1')];
        $this->assertSame('Winter', $doc['label'] ?? null);
        $this->assertSame('published', $doc['status'] ?? null,
            'renaming must not disturb the status');
    }

    /** A name that is blank, or a paragraph, is not a name. */
    public function test_an_empty_or_overlong_name_is_refused(): void
    {
        foreach (['', '   ', str_repeat('x', 61)] as $bad) {
            try {
                $this->store($this->fb('candidate'))->renameVersion(self::PLAN, 'V1', $bad);
                $this->fail('a name of ' . strlen($bad) . ' chars must be refused');
            } catch (Service_exception $e) {
                $this->assertSame('validation', $e->getCode() ?: 'validation');
            }
        }

        // The boundary itself is usable.
        $fb = $this->fb('candidate');
        $this->store($fb)->renameVersion(self::PLAN, 'V1', str_repeat('x', 60));
        $this->assertSame(60, strlen($fb->versions[$this->vDoc('V1')]['label']));
    }

    public function test_renaming_a_timetable_that_does_not_exist_is_refused(): void
    {
        $this->expectException(Service_exception::class);
        $this->store($this->fb('candidate'))->renameVersion(self::PLAN, 'NOPE', 'Winter');
    }
}