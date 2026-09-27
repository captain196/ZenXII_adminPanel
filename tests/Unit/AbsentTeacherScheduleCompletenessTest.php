<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ACAD-D13 — "this teacher has no classes today" must be a fact, not a guess.
 *
 * Academic::get_absent_teacher_schedule() lists the periods an absent teacher
 * was due to take, and the admin arranges cover from exactly that list. A
 * period missing from it is a class that quietly ends up with nobody in front
 * of it — the schedule is not a report, it is a work list.
 *
 * It was built from:
 *
 *     try { $ttDocs = firestoreQuery('timetables', [school, session, day],
 *                                    null, 'ASC', 100); ... }
 *     catch (\Exception $e) {}                       // silent
 *     ...
 *     if (empty($absentPeriods)) return json_success([
 *         'message' => 'This teacher has no classes on ' . $dayOfWeek,   // <-- asserted
 *     ]);
 *
 * Two ways that sentence becomes false:
 *
 *   1. the read failed — firestoreQuery() returns [] for a missing index, a
 *      network error, 403, 429 and an uninitialised client alike, and none of
 *      them throw, so the catch never fired (see ACAD-D12);
 *   2. the scan saturated at 100 class-sections for the day, and the teacher's
 *      period sat in a doc past the cap.
 *
 * In both cases the admin is told, positively, that no cover is needed.
 *
 * WITHDRAWN while investigating this, and recorded so it is not "found" again:
 * the query filters `['schoolId', '==', $school]` where `$school =
 * $this->school_name`, which looks like a name/id mix-up. It is not.
 * MY_Controller sets `$this->school_name = $this->school_id;  // canonical:
 * name == id` (and the session value is documented `= school_id (SCH_XXXXXX)`).
 * The filter is correct — the naming is merely a readability hazard.
 */
final class AbsentTeacherScheduleCompletenessTest extends TestCase
{
    private function ctrl(): string
    {
        $p = __DIR__ . '/../../application/controllers/Academic.php';
        $this->assertFileExists($p);
        return (string) file_get_contents($p);
    }

    private function view(): string
    {
        $p = __DIR__ . '/../../application/views/academic/index.php';
        $this->assertFileExists($p);
        return (string) file_get_contents($p);
    }

    private function method(): string
    {
        $s = $this->ctrl();
        $i = strpos($s, 'function get_absent_teacher_schedule(');
        $this->assertNotFalse($i, 'get_absent_teacher_schedule() is gone');
        $e = strpos($s, "\n    public function ", $i + 10);
        $this->assertNotFalse($e, 'could not bound the method');
        return substr($s, $i, $e - $i);
    }

    public function test_the_cap_is_named_and_probed_one_past(): void
    {
        $this->assertMatchesRegularExpression(
            '~const SUBSTITUTE_SCAN_LIMIT\s*=\s*\d+~', $this->ctrl(),
            'the cap must be named so the saturation check cannot drift from it');

        $fn = $this->method();

        $this->assertMatchesRegularExpression(
            '~self::SUBSTITUTE_SCAN_LIMIT \+ 1~', $fn,
            'reading exactly the cap cannot tell "exactly full" from "truncated"');

        $this->assertMatchesRegularExpression(
            '~count\(\$ttDocs\) > self::SUBSTITUTE_SCAN_LIMIT~', $fn,
            'no saturation check');

        $this->assertMatchesRegularExpression(
            '~array_slice\(\$ttDocs, 0, self::SUBSTITUTE_SCAN_LIMIT\)~', $fn,
            'the probe doc must be trimmed before the list is built');

        $this->assertDoesNotMatchRegularExpression(
            "~'ASC',\s*100\s*\)~", $fn, 'a bare literal cap is back');
    }

    public function test_the_read_is_fail_loud_and_marks_incomplete(): void
    {
        $fn = $this->method();

        $this->assertStringContainsString('firestoreQueryOrFail', $fn,
            'firestoreQuery() returns [] for a failed read exactly as it does for '
            . 'a teacher with a free day; the two must be distinguishable here');

        $this->assertDoesNotMatchRegularExpression(
            '~catch\s*\(\\\\Exception \$e\)\s*\{\s*\}~', $fn,
            'the silent catch is back');

        $this->assertSame(6, preg_match_all('~\$complete\s*=\s*false;~', $fn),
            'six paths make the view incomplete. The sixth is the cover-load read '
            . 'behind "how much is this candidate already carrying" — without it the '
            . 'suggestions are still usable and the save still enforces the school '
            . "limits, so it degrades rather than denies, but it must say so. Five: "
            . 'the timetable scan saturating, a '
            . 'failed timetable read, the existing-cover scan saturating, a failed '
            . 'existing-cover read, and a failed staff read. The cover scan gained a '
            . 'bound of its own (ACAD-D16) — it used to be unbounded, so a school '
            . 'with more covers than one page silently lost some and every teacher '
            . 'in the lost rows looked free. A different count means one of the five '
            . 'stopped reporting.');
    }

    /** The load-bearing sentence. */
    public function test_it_never_asserts_no_classes_off_an_incomplete_read(): void
    {
        $fn = $this->method();

        $i = strpos($fn, 'has no classes on');
        $this->assertNotFalse($i, 'the empty-day message is gone');

        $window = substr($fn, max(0, $i - 400), 500);
        $this->assertMatchesRegularExpression(
            '~\$complete\s*\?~', $window,
            '"has no classes" must be conditional on a complete read — otherwise a '
            . 'failed read positively tells the admin that no cover is needed');
    }

    public function test_both_returns_carry_the_contract(): void
    {
        $fn = $this->method();
        $this->assertSame(2, preg_match_all("~'completeness'\s*=>~", $fn),
            'both the empty-list and the populated return must carry the contract; '
            . 'the empty one is the more dangerous of the two');
    }

    // -- the UI honours it -----------------------------------------------

    public function test_the_empty_panel_uses_the_server_wording_when_incomplete(): void
    {
        $v = $this->view();

        $i = strpos($v, 'has no classes on ');
        $this->assertNotFalse($i, 'the empty-schedule message is gone from the view');

        $window = substr($v, max(0, $i - 600), 700);
        $this->assertMatchesRegularExpression(
            '~d\.completeness && d\.completeness\.complete===false~', $window,
            'the panel must not print "no classes / no substitutes needed" when the '
            . 'server said the read was incomplete');
    }

    public function test_a_partial_list_is_banner_flagged_above_the_rows(): void
    {
        $v = $this->view();

        $i = strpos($v, 'Render per-period rows');
        $this->assertNotFalse($i, 'the schedule renderer is gone');
        $region = substr($v, $i, 700);

        $this->assertStringContainsString('acIncompleteBanner(d.completeness', $region,
            'a partial work list still gets arranged from — it must say so above '
            . 'the rows rather than reading as the whole day');
    }
}
