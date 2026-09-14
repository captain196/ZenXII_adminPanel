<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ACAD-D11 — the master timetable must never present partial data as complete.
 *
 * getMasterTimetable() reads the `timetables` collection, which holds ONE doc
 * per (class-section, day). The set is therefore class-sections x working days,
 * and it was capped at a bare 500 with no signal:
 *
 *     $fsDocs = firestoreQuery(TT, [schoolId, session], null, 'ASC', 500);
 *
 * At six working days that stops at ~83 class-sections. A large K-12 school
 * reaches it (14 grades x 6 sections = 504 docs), so this is a real scale, not
 * a hypothetical one.
 *
 * WHAT IS LOST IS NOT RANDOM. No orderBy is sent, so Firestore falls back to
 * __name__ ASC, and docIds are {school}_{session}_{classSection}_{day}. The
 * dropped rows are therefore WHOLE class-sections at the lexicographic tail,
 * and the SAME ones on every request — consistently invisible rather than
 * intermittently wrong.
 *
 * Three consumers share the payload:
 *   1. the Master Timetable grid          — rows silently absent;
 *   2. the Teacher Workload tab           — totals are computed CLIENT-SIDE by
 *      walking exactly these cells, so an under-count is shown as an
 *      authoritative periods-per-week figure;
 *   3. the Lesson Planner class picker    — options are derived from the
 *      timetable keys, so class-sections vanish from the dropdown.
 *
 * NOT AFFECTED, and deliberately left alone (verified, not assumed): the
 * server-side workload warning and the substitute-suggestion engine both read
 * `subjectAssignments` through SAS, which is uncapped. No authorization or
 * compliance decision is made from this payload.
 *
 * The fix is a completeness contract, not a bigger cap: fetch cap+1 to detect
 * saturation for the cost of one doc, and report {complete, loaded, cap} so the
 * UI can say so.
 */
final class MasterTimetableCompletenessTest extends TestCase
{
    private function svc(): string
    {
        $p = __DIR__ . '/../../application/libraries/Timetable_service.php';
        $this->assertFileExists($p);
        return (string) file_get_contents($p);
    }

    private function view(): string
    {
        $p = __DIR__ . '/../../application/views/academic/index.php';
        $this->assertFileExists($p);
        return (string) file_get_contents($p);
    }

    private function getMaster(): string
    {
        $s = $this->svc();
        $i = strpos($s, 'function getMasterTimetable(');
        $this->assertNotFalse($i, 'getMasterTimetable() is gone');
        $e = strpos($s, "\n    public function ", $i + 10);
        $this->assertNotFalse($e, 'could not bound getMasterTimetable()');
        return substr($s, $i, $e - $i);
    }

    // -- the contract exists ---------------------------------------------

    public function test_the_cap_is_a_named_constant_not_a_literal(): void
    {
        $this->assertMatchesRegularExpression(
            '~const MASTER_SCAN_LIMIT\s*=\s*\d+~', $this->svc(),
            'the cap must be named so the saturation check cannot drift from it');

        $fn = $this->getMaster();
        $this->assertDoesNotMatchRegularExpression(
            "~'ASC',\s*500\s*\)~", $fn,
            'a bare literal cap is back — it can drift out of step with the check');
    }

    /** cap+1 is what makes saturation detectable at all. */
    public function test_it_reads_one_past_the_cap_to_detect_saturation(): void
    {
        $fn = $this->getMaster();

        $this->assertMatchesRegularExpression(
            '~self::MASTER_SCAN_LIMIT \+ 1~', $fn,
            'reading exactly the cap cannot distinguish "exactly full" from '
            . '"truncated"; read cap+1');

        $this->assertMatchesRegularExpression(
            '~count\(\$fsDocs\) > self::MASTER_SCAN_LIMIT~', $fn,
            'no saturation check');

        $this->assertMatchesRegularExpression(
            '~array_slice\(\$fsDocs, 0, self::MASTER_SCAN_LIMIT\)~', $fn,
            'the probe doc must be trimmed before the payload is built');
    }

    /** A failed read is incomplete, not an empty school. */
    public function test_a_failed_read_is_reported_as_incomplete(): void
    {
        $fn = $this->getMaster();

        $this->assertStringContainsString('firestoreQueryOrFail', $fn,
            'firestoreQuery() returns [] for a failed read as well as an empty '
            . 'school; without a fail-loud read the UI shows a blank grid as '
            . 'though the school had no timetable at all');

        $q = strpos($fn, 'firestoreQueryOrFail');
        $this->assertNotFalse($q, 'the fail-loud timetable read is gone');
        $c = strpos($fn, 'catch', $q);
        $this->assertNotFalse($c, 'the read failure is no longer handled');

        $this->assertMatchesRegularExpression(
            '~\$complete\s*=\s*false;~', substr($fn, $c, 400),
            'the catch must mark the payload incomplete, not just log');
    }

    public function test_the_contract_is_returned_to_the_caller(): void
    {
        $fn = $this->getMaster();

        foreach (["'complete' => \$complete", "'loaded'   => \$loaded", "'cap'"] as $key) {
            $this->assertStringContainsString($key, $fn,
                "the completeness contract must carry {$key}");
        }
    }

    /** Both failure modes must set the same flag. */
    public function test_both_truncation_and_failure_set_incomplete(): void
    {
        $fn = $this->getMaster();
        $this->assertSame(3, preg_match_all('~\$complete\s*=\s*false;~', $fn),
            'three paths make the payload incomplete: a failed settings read, '
            . 'saturation, and a failed timetable read. A different count means '
            . 'one stopped reporting, or a fourth appeared unreviewed.');
    }

    // -- the UI honours it -----------------------------------------------

    public function test_the_ui_has_a_banner_helper_that_only_fires_when_incomplete(): void
    {
        $v = $this->view();

        $this->assertStringContainsString('function acIncompleteBanner(', $v);
        $this->assertMatchesRegularExpression(
            '~if\(!c \|\| c\.complete !== false\) return \x27\x27;~', $v,
            'the banner must render only on an explicit complete:false, so a '
            . 'server that never sends the contract does not spam the operator');
    }

    /**
     * The banner must be driven from stored state inside render(), not set once
     * at load. Workload can be opened AFTER the timetable tab has already
     * loaded, which skips the load path entirely.
     */
    /**
     * ACAD-D11 — where it still applies.
     *
     * This used to cover two tabs. The legacy Master Timetable tab no longer
     * renders a timetable at all: it is a signpost to the canonical screen, so a
     * completeness banner on it would describe nothing.
     *
     * Teacher Workload no longer reads academic/get_master_timetable: as of
     * 2026-09-12 it answers from the CANONICAL published version
     * (academic/get_canonical_timetable), because the compatibility projection
     * is only written when a version is in force on its publish day or by an
     * unscheduled CLI cron — so the screen was reporting a stale copy as fact.
     * See TeacherWorkloadCanonicalSourceTest.
     *
     * The REQUIREMENT here is unchanged and is why this test survives that
     * move: an UNDER-COUNTED periods-per-week total looks like a real number
     * and gets acted on, so completeness must be stored and re-read on every
     * render. Only the source of that stored value moved, from AC.tt to the
     * workload's own AC.wl._src.
     */
    public function test_teacher_workload_renders_the_banner_on_every_render(): void
    {
        $v = $this->view();

        $this->assertMatchesRegularExpression('~<div id="wlIncomplete"></div>~', $v,
            'the banner container is missing from Teacher Workload');

        $this->assertMatchesRegularExpression(
            '~acSetCompleteness\(\x27wlIncomplete\x27,\s*AC\.wl\._src\.completeness~', $v,
            'the banner must be driven from stored completeness inside render(), '
            . 'or it is lost on re-render and on the already-loaded path');
    }

    /**
     * And the retired tab must not quietly grow a timetable again. If a grid
     * ever returns to that pane, this fails and the completeness contract has to
     * be reinstated with it.
     */
    public function test_the_retired_tab_no_longer_renders_a_timetable(): void
    {
        $v = $this->view();

        foreach (['ttGridWrap', 'ttIncomplete'] as $goneWithThePane) {
            $this->assertStringNotContainsString('id="' . $goneWithThePane . '"', $v,
                'the legacy pane is now a signpost. If a timetable grid comes back '
                . 'to it, the ACAD-D11 completeness banner must come back too — '
                . 'and so must a writer that is no longer retired.');
        }
    }

    /** The workload wording must name the consequence, not just say "incomplete". */
    public function test_the_workload_banner_warns_the_numbers_are_understated(): void
    {
        $v = $this->view();

        $i = strpos($v, "acSetCompleteness('wlIncomplete'");
        $this->assertNotFalse($i);
        $call = substr($v, $i, 400);

        $this->assertMatchesRegularExpression('~LOWER than the real figure~', $call,
            'a missing grid row is visible, but an under-counted total looks like '
            . 'a real number — the banner must say the totals are understated');
    }

    /** The Lesson Planner derives its class list from this payload too. */
    public function test_the_lesson_planner_warns_its_class_list_may_be_partial(): void
    {
        $v = $this->view();

        $i = strpos($v, 'AC.lp._mt=mt');
        $this->assertNotFalse($i, 'the planner bootstrap is gone');
        $region = substr($v, $i, 500);

        // Storing mt.completeness is NOT enough — the operator has to be told.
        // Asserting only that the property is referenced lets the warning itself
        // be deleted while the test stays green.
        $this->assertMatchesRegularExpression(
            '~mt\.completeness && mt\.completeness\.complete===false\)\s*toast\(~', $region,
            'the planner builds its class/section picker from the timetable keys, '
            . 'so truncation silently removes class-sections from the dropdown; '
            . 'it must warn, not just record the flag');
    }

    /** The container must sit outside the region render() overwrites. */
    public function test_the_workload_banner_survives_a_rerender(): void
    {
        $v = $this->view();

        $banner = strpos($v, '<div id="wlIncomplete"></div>');
        $this->assertNotFalse($banner, 'the Workload banner container is gone');

        $host = strpos($v, 'id="wlBody"');
        if ($host === false) { $host = strpos($v, 'id="wlTable"'); }
        $this->assertNotFalse($host, 'the Workload render target is gone');

        $this->assertLessThan($host, $banner,
            'the banner must live outside the element whose innerHTML is replaced '
            . 'on every render, or it disappears the moment the table redraws');
    }
}
