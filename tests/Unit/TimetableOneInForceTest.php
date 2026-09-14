<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__ . '/../../system/');
if (!defined('APPPATH'))  define('APPPATH',  __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../../application/libraries/Timetable_plan_service.php';

/**
 * ONE TIMETABLE IN FORCE — the predicate, and the fact that there is only one.
 *
 * For a given school, session and date, exactly one published version may
 * apply. Owner-authorised 2026-08-31 (TT-C8).
 *
 * ── WHAT WENT WRONG ─────────────────────────────────────────────────────
 *
 * "In force" was defined twice and the two disagreed. The publish-time check
 * filtered by `planId`; the resolver did not. So publishing a second plan's
 * version over a live one reported NO overlap — not a warning, nothing at all —
 * and the projection ran, because it was gated on that emptiness. The resolver
 * then saw two matches and reported AMBIGUOUS for ever, the cron refused, and
 * with no unpublish the school could not get back out.
 *
 * That failure needed no race. One administrator, two sequential clicks.
 *
 * These tests hold the predicate to interval arithmetic, and then hold the
 * resolver to the SAME predicate — because the defect was never in either
 * definition on its own, it was in there being two.
 */
final class TimetableOneInForceTest extends TestCase
{
    private function v(string $plan, string $vid, string $from, string $to = '',
                       string $status = 'published'): array
    {
        return ['schoolId' => 'SCH_A', 'session' => '2026-27',
                'planId' => $plan, 'versionId' => $vid, 'planName' => 'Plan ' . $plan,
                'status' => $status, 'effectiveFrom' => $from, 'effectiveTo' => $to];
    }

    // ── interval arithmetic ──────────────────────────────────────────────

    /** @dataProvider ranges */
    public function test_range_overlap(string $aF, string $aT, string $bF, string $bT,
                                       bool $expected, string $why): void
    {
        $this->assertSame($expected,
            Timetable_plan_service::rangesOverlap($aF, $aT, $bF, $bT), $why);
        $this->assertSame($expected,
            Timetable_plan_service::rangesOverlap($bF, $bT, $aF, $aT),
            'overlap is symmetric — ' . $why);
    }

    public function ranges(): array
    {
        return [
            'disjoint, a first'   => ['2026-01-01', '2026-03-31', '2026-04-01', '2026-06-30', false, 'they do not share a day'],
            'touching'            => ['2026-01-01', '2026-03-31', '2026-03-31', '2026-06-30', true,  'both ends are inclusive, so 31 March is in both'],
            'contained'           => ['2026-01-01', '2026-12-31', '2026-06-01', '2026-06-30', true,  'one sits inside the other'],
            'identical'           => ['2026-04-01', '2026-06-30', '2026-04-01', '2026-06-30', true,  'the same period'],
            'open vs later closed'=> ['2026-04-01', '',           '2026-10-01', '2026-12-31', true,  'an open end claims every later date'],
            'open vs earlier'     => ['2026-04-01', '',           '2026-01-01', '2026-03-31', false, 'the earlier one ends before it starts'],
            'both open'           => ['2026-04-01', '',           '2026-10-01', '',           true,  'two open-ended periods always meet'],
            'no start'            => ['',           '',           '2026-04-01', '',           false, 'a range with no start is not on the calendar'],
            'malformed start'     => ['not-a-date', '',           '2026-04-01', '',           false, 'and neither is a malformed one'],
        ];
    }

    // ── the plan filter: the actual hole ─────────────────────────────────

    public function test_a_different_plan_covering_the_same_dates_is_an_overlap(): void
    {
        $versions = [
            $this->v('PLAN_REGULAR', 'V1', '2026-04-01'),
            $this->v('PLAN_EXAM',    'V1', '2026-06-01', '2026-06-15', 'candidate'),
        ];

        $found = Timetable_plan_service::publishedOverlaps(
            $versions, 'PLAN_EXAM', 'V1', '2026-06-01', '2026-06-15');

        $this->assertCount(1, $found,
            'a school has one timetable in force on any day; which plan it came '
            . 'from does not change that');
        $this->assertSame('PLAN_REGULAR', $found[0]['planId']);
    }

    /**
     * Self-exclusion must match on plan AND version.
     *
     * 'V1' exists in every plan — versionIds are only unique within a plan. A
     * self-check on the versionId alone would silently excuse another plan's
     * V1 from the overlap test, which is the same hole in a new place.
     */
    public function test_self_exclusion_needs_both_ids(): void
    {
        $versions = [
            $this->v('PLAN_REGULAR', 'V1', '2026-04-01'),
            $this->v('PLAN_EXAM',    'V1', '2026-04-01'),
        ];

        $found = Timetable_plan_service::publishedOverlaps(
            $versions, 'PLAN_EXAM', 'V1', '2026-04-01', '');

        $this->assertCount(1, $found, 'only the version being published is itself');
        $this->assertSame('PLAN_REGULAR', $found[0]['planId']);
    }

    public function test_only_published_versions_can_be_in_the_way(): void
    {
        $versions = [
            $this->v('P', 'DRAFT', '2026-04-01', '', 'draft'),
            $this->v('P', 'CAND',  '2026-04-01', '', 'candidate'),
            $this->v('P', 'OLD',   '2026-04-01', '', 'superseded'),
        ];

        $this->assertSame([], Timetable_plan_service::publishedOverlaps(
            $versions, 'P', 'NEW', '2026-04-01', ''),
            'work in progress is not in force — that separation is what lets a '
            . 'timetable be built in the open without a teacher seeing it');
    }

    public function test_a_non_overlapping_publish_is_clear(): void
    {
        $versions = [$this->v('P', 'TERM1', '2026-04-01', '2026-09-30')];

        $this->assertSame([], Timetable_plan_service::publishedOverlaps(
            $versions, 'P', 'TERM2', '2026-10-01', ''),
            'consecutive terms are the normal case and must not be obstructed');
    }

    // ── the resolver asks the same question ──────────────────────────────

    public function test_resolution_and_overlap_agree(): void
    {
        // If a version covers a date, then publishing anything over that date
        // must see it as an overlap. Any pair where those two disagree is a
        // way to create a second live timetable.
        $live = $this->v('PLAN_REGULAR', 'V1', '2026-04-01', '2026-09-30');

        foreach (['2026-03-31', '2026-04-01', '2026-06-15', '2026-09-30', '2026-10-01'] as $date) {
            $covers = Timetable_plan_service::coversDate($live, $date);

            $blocks = Timetable_plan_service::publishedOverlaps(
                [$live], 'PLAN_OTHER', 'VX', $date, $date) !== [];

            $this->assertSame($covers, $blocks,
                "on {$date} the resolver and the publish check must give the "
                . 'same answer, or one of them is a way in');
        }
    }

    public function test_resolution_uses_the_shared_predicate(): void
    {
        $versions = [
            $this->v('PLAN_REGULAR', 'V1', '2026-04-01', '2026-09-30'),
            $this->v('PLAN_EXAM',    'V1', '2026-10-01'),
        ];

        $r = Timetable_plan_service::resolveForDate($versions, '2026-06-15');
        $this->assertSame(Timetable_plan_service::RESOLVED, $r['status']);
        $this->assertSame('PLAN_REGULAR', $r['version']['planId']);

        $r2 = Timetable_plan_service::resolveForDate($versions, '2026-11-01');
        $this->assertSame(Timetable_plan_service::RESOLVED, $r2['status']);
        $this->assertSame('PLAN_EXAM', $r2['version']['planId'],
            'resolution spans plans — which is exactly why the overlap check '
            . 'has to as well');
    }

    /**
     * The resolver still refuses to guess when it finds two.
     *
     * Publishing can no longer create this state, so this is the safety net for
     * data that predates the rule or arrives another way. It must never be
     * quietly resolved in favour of one.
     */
    public function test_a_pre_existing_pair_is_still_ambiguous(): void
    {
        $r = Timetable_plan_service::resolveForDate([
            $this->v('PLAN_REGULAR', 'V1', '2026-04-01'),
            $this->v('PLAN_EXAM',    'V1', '2026-06-01'),
        ], '2026-07-01');

        $this->assertSame(Timetable_plan_service::AMBIGUOUS, $r['status']);
        $this->assertCount(2, $r['candidates']);
    }

    public function test_coversDate_refuses_junk(): void
    {
        $live = $this->v('P', 'V1', '2026-04-01');

        $this->assertFalse(Timetable_plan_service::coversDate($live, 'not-a-date'));
        $this->assertFalse(Timetable_plan_service::coversDate(null, '2026-06-01'));
        $this->assertFalse(Timetable_plan_service::coversDate(
            $this->v('P', 'V1', '', ''), '2026-06-01'),
            'a published version with no start date applies on no day at all');
    }
}
