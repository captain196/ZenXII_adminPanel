<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * WHAT AN ADMINISTRATOR CAN ACTUALLY REACH.
 *
 * "The method throws in a unit test" and "the production route no longer offers
 * this workflow" are different claims, and only the second one retires a defect.
 * This file is about the second: the routes, the controller actions behind them,
 * and the screen an administrator is actually looking at.
 *
 * It still proves reachability STRUCTURALLY — nothing here drives a browser or a
 * live HTTP request. That limit is stated rather than glossed, and the browser
 * steps it cannot cover are listed in the phase report.
 */
final class TimetableLegacyReachabilityTest extends TestCase
{
    private function file(string $rel): string
    {
        return (string) file_get_contents(__DIR__ . '/../../' . $rel);
    }

    private function academicJs(): string
    {
        $v = $this->file('application/views/academic/index.php');
        $code = (string) preg_replace('~/\*.*?\*/~s', '', $v);
        return (string) preg_replace('~^\s*//.*$~m', '', $code);
    }

    // ── the legacy screen no longer offers a timetable to edit ──────────

    /**
     * The tab holds the CANONICAL timetable itself — not the legacy grid, and
     * not a signpost to somewhere else.
     *
     * It sits beside Period Scheduling and Subject Assignment, the two things a
     * timetable is built from, so an administrator has one place to go instead
     * of choosing between two screens for one job.
     */
    public function test_the_tab_renders_the_canonical_timetable(): void
    {
        $v = $this->file('application/views/academic/index.php');

        $i = strpos($v, 'id="pane-timetable"');
        $this->assertNotFalse($i, 'the timetable pane is gone');

        $pane = substr($v, $i, 1400);
        $this->assertStringContainsString("load->view('timetable/lifecycle')", $pane,
            'the tab must render the canonical panel, not a copy of it and not a '
            . 'link away from the page');

        // The legacy grid must not have come back with it.
        foreach (['ttViewToggle', 'ttGridWrap', 'data-view="teacher"'] as $editorBit) {
            $this->assertStringNotContainsString($editorBit, $pane,
                'the legacy grid must not return: every cell in it calls a writer '
                . 'that now refuses');
        }
    }

    /**
     * The legacy loader must not be reachable from the TAB.
     *
     * It still appears inside AC.tt's own save handlers, which are unreachable
     * now that the pane holds no grid, and behind one `if (AC.tt._loaded)` guard
     * that can never be true because nothing sets it. Asserting the string is
     * absent everywhere would fail for the wrong reason; what matters is the
     * entry point.
     */
    public function test_the_legacy_timetable_loader_is_not_reachable_from_the_tab(): void
    {
        $js = $this->academicJs();

        $i = strpos($js, "getElementById('acTabs').addEventListener");
        $this->assertNotFalse($i, 'the tab handler is gone');
        $handler = substr($js, $i, 1400);

        $this->assertStringContainsString("window.ZXTimetable.init()", $handler,
            'opening the tab must start the CANONICAL panel');
        $this->assertStringNotContainsString('AC.tt.load()', $handler,
            'and it must not start the legacy loader: its grid elements no longer '
            . 'exist on this page');

        // The other tabs must still load — this is a removal of one entry point,
        // not a broken tab bar.
        foreach (['scheduling', 'subjects', 'workload', 'planner'] as $keep) {
            $this->assertStringContainsString("k==='{$keep}'", $handler,
                "the {$keep} tab must still load; only the timetable entry point "
                . 'was retired');
        }
    }

    /**
     * The READ endpoint stays. Teacher Workload and Lesson Planner both call
     * academic/get_master_timetable, and what it reads is the compatibility
     * projection — so it is a consumer of the canonical timetable, not a
     * surviving piece of legacy authority.
     */
    public function test_the_legacy_read_endpoint_survives_for_its_real_consumers(): void
    {
        $routes = $this->file('application/config/routes.php');
        $this->assertStringContainsString(
            "academic/get_master_timetable", $routes,
            'Teacher Workload and Lesson Planner read through this; removing it '
            . 'would break two working screens to tidy up a third');

        $js = $this->academicJs();
        $this->assertGreaterThanOrEqual(2,
            substr_count($js, "academic/get_master_timetable"),
            'premise: more than one screen depends on this read');
    }

    // ── every legacy WRITE path refuses, at the service ─────────────────

    public function test_every_legacy_write_action_reaches_a_retired_service(): void
    {
        $svc = $this->file('application/libraries/Timetable_service.php');

        // The three controller actions an administrator could still POST to.
        $map = [
            'save_period'             => 'savePeriod',
            'save_section_timetable'  => 'saveSectionTimetable',
            'auto_generate_timetable' => 'autoGenerate',
        ];

        $ctrl = $this->file('application/controllers/Academic.php');
        foreach ($map as $action => $method) {
            $this->assertStringContainsString("public function {$action}()", $ctrl,
                "{$action}() was removed rather than retired — the route would "
                . '404 instead of explaining where the work went');

            $i = strpos($svc, "function {$method}(");
            $this->assertNotFalse($i, "{$method}() is gone from the service");

            // Bound the method and require the retirement guard inside it.
            $end = strpos($svc, "\n    public function ", $i + 10);
            $body = $end === false ? substr($svc, $i) : substr($svc, $i, $end - $i);
            $this->assertStringContainsString('_refuseLegacyWrite', $body,
                "{$method}() can still write a timetable: the route is reachable, "
                . 'so the refusal has to live in the method itself');
        }
    }

    public function test_the_refusal_names_where_the_work_moved(): void
    {
        $svc = $this->file('application/libraries/Timetable_service.php');
        $i = strpos($svc, 'private function _refuseLegacyWrite(');
        $this->assertNotFalse($i);
        $body = substr($svc, $i, 1200);

        $this->assertStringContainsString('Master Timetable', $body,
            'a refusal that does not say where to go leaves an administrator '
            . 'staring at a broken screen');
        $this->assertMatchesRegularExpression('~check it, and publish it~', $body,
            'and it should describe the replacement workflow, not just name it');
    }

    // ── Period Scheduling is a prerequisite, not a legacy writer ────────

    public function test_period_scheduling_is_preserved_as_a_prerequisite(): void
    {
        $v = $this->file('application/views/academic/index.php');

        $this->assertStringContainsString('data-tab="scheduling"', $v,
            'Period Scheduling configures periods per day and working days, which '
            . 'the CANONICAL editor reads — removing it would break the new '
            . 'architecture, not the old one');
        $this->assertStringContainsString("AC.ps.init()", $v,
            'and it must still load');

        // The proof that it is a prerequisite: the canonical store reads it.
        $store = $this->file('application/libraries/Timetable_plan_store.php');
        $this->assertStringContainsString("COLLECTION_SETTINGS = 'timetableSettings'", $store,
            'the canonical store reads the settings Period Scheduling writes');

        // And the proof it is not a legacy writer: it writes settings, not
        // timetables.
        $svc = $this->file('application/libraries/Timetable_service.php');
        $i = strpos($svc, 'public function saveSettings(');
        $end = strpos($svc, "\n    public function ", $i + 10);
        $body = substr($svc, $i, $end - $i);
        $this->assertStringNotContainsString('COLLECTION_TT', $body,
            'Period Scheduling must not write the timetable collection — if it '
            . 'did, it would be a legacy writer and not a prerequisite');
    }

    // ── one authority, one entry point ─────────────────────────────────

    /**
     * ONE entry point. An administrator must never have to decide between
     * "Academic Planner" and "Master Timetable" when the second is built
     * entirely from the first.
     */
    public function test_the_menu_offers_exactly_one_way_in(): void
    {
        $header = $this->file('application/views/include/header.php');

        $this->assertStringNotContainsString("base_url('timetable')", $header,
            'a second sidebar entry for the timetable is a second front door to '
            . 'one room');
        $this->assertStringContainsString("base_url('academic')", $header,
            'Academic Planner is the way in — it owns scheduling, assignments and '
            . 'now the timetable itself');
    }

    /**
     * The published URL still works. It was linked and bookmarked, so it lands
     * on the same screen rather than 404ing.
     */
    public function test_the_old_url_still_lands_on_the_one_screen(): void
    {
        $ctrl = $this->file('application/controllers/Timetable_lifecycle.php');

        $this->assertMatchesRegularExpression(
            "~redirect\(base_url\('academic#timetable'\)\)~", $ctrl,
            '/timetable must lead to the tab, not to a second copy of the screen');

        $routes = $this->file('application/config/routes.php');
        $this->assertStringContainsString("Timetable_lifecycle/index", $routes,
            'the route must survive so the old link resolves');
    }

    public function test_no_new_timetable_work_targets_the_legacy_writers(): void
    {
        // Comments are stripped: the canonical controller NAMES the legacy
        // writers in docblocks to explain why it does not behave like them, and
        // that documentation is the opposite of a dependency.
        $ctrl = (string) preg_replace('~/\*.*?\*/~s', '',
            $this->file('application/controllers/Timetable_lifecycle.php'));
        $ctrl = (string) preg_replace('~^\s*//.*$~m', '', $ctrl);

        foreach (['savePeriod', 'saveSectionTimetable', 'autoGenerate(',
                  'reconcileClassFromMatrix'] as $legacy) {
            $this->assertStringNotContainsString($legacy, $ctrl,
                'the canonical surface must never call a legacy writer: that is '
                . 'how the old architecture would regain authority');
        }
    }
}
