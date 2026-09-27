<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tests\Support\StaffMenu;

/**
 * FEATURE 10 — every configuration introduced must be reachable from the panel.
 *
 * The standing requirement is that a school administrator configures policy
 * through ZenX, not through a Firestore console, a PHP file or a developer. A
 * setting that exists only as an endpoint is, from the school's point of view,
 * not a feature.
 *
 * This suite checks the two things that make a config screen real:
 *
 *   1. It is REACHABLE — a tab, a panel, a loader, and a route.
 *   2. It FAILS CLOSED — CI returns a BLANK 403 when CSRF fails and `fetch()`
 *      does not reject on 403 or 500, so a save that was refused must never
 *      report as done.
 *
 * The second is the documented trap in this repository, and it is why the new
 * screens use the page's existing `api()` / `postJson()` helpers rather than a
 * raw `fetch()`.
 */
final class CompensationAdminUiTest extends TestCase
{
    private static string $view = '';
    private static string $ctrl = '';
    private static string $routes = '';

    public static function setUpBeforeClass(): void
    {
        $root = __DIR__ . '/../../application/';
        self::$view   = (string) file_get_contents($root . 'views/compensation/index.php');
        self::$ctrl   = (string) file_get_contents($root . 'controllers/Compensation.php');
        self::$routes = (string) file_get_contents($root . 'config/routes.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$view = ''; self::$ctrl = ''; self::$routes = '';
    }

    // ── Reachability ─────────────────────────────────────────────────────

    /**
     * EVERY tab the controller accepts must have a route.
     *
     * FOUND IN UAT 2026-08-23: `/compensation/fnf` returned 404. The tab had
     * been added to the controller's `$allowed` list and to the tab bar, but
     * NOT to `routes.php` — so CodeIgniter tried to call `Compensation::fnf()`,
     * which does not exist.
     *
     * This file declares every compensation route EXPLICITLY; there is no
     * `compensation/(:any)` catch-all. So a tab without a route is a 404, and
     * nothing in the controller or the view reveals that.
     *
     * The previous version of this test asserted only that the string 'fnf'
     * appeared somewhere in the controller — which it did, in `$allowed`. It
     * passed while the page was completely unreachable. It is now derived from
     * the controller's OWN list, so adding a ninth tab without a route fails
     * here rather than in somebody's browser.
     */
    public function test_every_allowed_tab_has_a_route(): void
    {
        $this->assertSame(1, preg_match('/\$allowed = \[(.*?)\];/s', self::$ctrl, $m),
            'the allowed-tab list must be findable, or this test proves nothing');

        preg_match_all("/'([a-z_]+)'/", $m[1], $t);
        $tabs = $t[1];

        $this->assertGreaterThanOrEqual(8, count($tabs),
            'scan sanity — the controller declares eight tabs');
        $this->assertContains('fnf', $tabs);
        $this->assertContains('classification', $tabs);

        $unrouted = [];
        foreach ($tabs as $tab) {
            if (strpos(self::$routes, "\$route['compensation/{$tab}']") === false) {
                $unrouted[] = $tab;
            }
        }

        $this->assertSame([], $unrouted,
            'these tabs are reachable in the UI but 404 on click: ' . implode(', ', $unrouted));
    }

    /** And every routed tab must actually render a panel. */
    public function test_every_routed_tab_has_a_panel(): void
    {
        preg_match('/\$allowed = \[(.*?)\];/s', self::$ctrl, $m);
        preg_match_all("/'([a-z_]+)'/", $m[1], $t);

        $missing = [];
        foreach ($t[1] as $tab) {
            if (strpos(self::$view, "\$at === '{$tab}' ? ' active' : ''") === false) {
                $missing[] = $tab;
            }
        }

        $this->assertSame([], $missing,
            'these tabs route but render nothing: ' . implode(', ', $missing));
    }

    /** Both are registered in the tab bar, so they can be clicked to. */
    public function test_both_tabs_appear_in_the_tab_bar(): void
    {
        foreach ([
            "'classification' => ['panel' => 'panelClassification'",
            "'fnf'         => ['panel' => 'panelFnf'",
        ] as $tab) {
            $this->assertStringContainsString($tab, self::$view,
                'a panel with no tab is unreachable by clicking');
        }
    }

    /** Both panels exist and are keyed to their tab. */
    public function test_both_panels_render_for_their_tab(): void
    {
        foreach (['classification', 'fnf'] as $tab) {
            $this->assertStringContainsString(
                "\$at === '{$tab}' ? ' active' : ''", self::$view,
                "the {$tab} panel must show when its tab is active");
        }
    }

    /** Both have a loader that actually runs on that tab. */
    public function test_both_panels_load_their_data(): void
    {
        $this->assertStringContainsString("if (TAB === 'classification') {", self::$view);
        // Assert the LOADERS, not the exact line: the F&F tab now also loads
        // the settlement list, and pinning the literal made an addition read
        // as a regression.
        // There is more than one `if (TAB === 'fnf')` block — one wires the
        // preview button. Anchor on the INIT line, which is the one that loads.
        $i = strpos(self::$view, "if (TAB === 'fnf') { loadFnfPolicy();");
        $this->assertNotFalse($i, 'the F&F tab must load its data on open');
        $init = substr(self::$view, (int) $i, 140);

        $this->assertStringContainsString('loadFnfPolicy();', $init);
        $this->assertStringContainsString('wireFnfForm();', $init);
        $this->assertStringContainsString('loadSettlements();', $init,
            'the settlement list must load with the tab, or the panel renders empty');
    }

    /** Every endpoint the screens call is routed — routes here are explicit. */
    public function test_every_endpoint_the_screens_call_is_routed(): void
    {
        foreach ([
            'get_component_classifications',
            'save_component_classification',
            'get_fnf_policy',
            'save_fnf_policy',
            'preview_fnf',
        ] as $ep) {
            $this->assertStringContainsString("compensation/{$ep}", self::$routes,
                "compensation routes are declared explicitly — without an entry {$ep} 404s");
        }
    }

    /**
     * THE PAGE ITSELF MUST BE REACHABLE FROM THE MENU.
     *
     * FOUND 2026-08-23, reported by the operator: "how can I see the
     * configuration settings? there is no button/option."
     *
     * They were right. The whole Compensation page — salary components, pay
     * grades, structures, statutory configuration, and the two tabs added by
     * this programme — had routes and a complete view but NO sidebar entry
     * anywhere. It was reachable only by typing `/compensation`.
     *
     * Every other test in this suite checked that a TAB was reachable within
     * the page. None checked the page was reachable at all, which is how a
     * whole configuration surface stayed invisible while its tests passed.
     *
     * The same defect is already recorded in `header.php` for
     * `Attendance::staff_attendance()`, so it is a recurring shape in this
     * codebase rather than a one-off.
     */
    public function test_the_compensation_page_is_reachable_from_the_menu(): void
    {
        $header = (string) file_get_contents(
            __DIR__ . '/../../application/views/include/header.php'
        );

        $this->assertStringContainsString("base_url('compensation')", $header,
            'a configuration page with no menu entry is invisible to the school, '
            . 'however good its tabs are');
    }

    /**
     * And its menu gate must match the controller's, or the link would appear
     * for somebody it then refuses.
     */
    public function test_the_menu_gate_matches_the_controller_gate(): void
    {
        $header = (string) file_get_contents(
            __DIR__ . '/../../application/views/include/header.php'
        );

        // Asserted by RENDERING the menu. The old version looked for a $can('HR')
        // closer to the link than any endif, which the 2026-08-23 consolidation
        // broke by lifting the gate into a named variable — without changing who
        // actually reaches the page.
        $this->assertTrue(StaffMenu::reaches('compensation', ['HR']),
            'HR access must reach the compensation page');

        $this->assertFalse(StaffMenu::reaches('compensation', ['SIS', 'Attendance', 'Admin Users']),
            'the controller refuses without HR, so the link must not be offered');

        // And the controller gates on exactly that capability.
        $this->assertStringContainsString("require_permission('HR');", self::$ctrl,
            'the controller must require the same capability the menu gate checks');
    }
    /**
     * EVERY endpoint this programme added must be called from a screen.
     *
     * FOUND IN UAT 2026-08-23, alongside the missing tab routes. Three endpoints
     * were routed, permission-gated and tested — and called by no UI at all:
     * the employee statutory profile (both halves) and the F&F preview. From a
     * school's point of view they did not exist.
     *
     * This is the same defect as the missing route and the missing menu entry,
     * one layer up: an endpoint reachable only by an engineer with curl is not
     * a feature. Asserted here so the next endpoint cannot land without a way
     * to reach it.
     */
    public function test_every_endpoint_this_programme_added_is_called_from_a_screen(): void
    {
        foreach ([
            'get_component_classifications',
            'save_component_classification',
            'get_fnf_policy',
            'save_fnf_policy',
            'get_staff_statutory_profile',
            'save_staff_statutory_profile',
            'preview_fnf',
        ] as $ep) {
            $this->assertStringContainsString("compensation/{$ep}", self::$view,
                "{$ep} is routed and gated but no screen calls it — it is invisible to the school");
        }
    }

    /** The preview screen must say plainly that it changes nothing. */
    public function test_the_preview_screen_says_it_creates_nothing(): void
    {
        $this->assertStringContainsString(
            'creates no settlement, no payslip and no accounting entry', self::$view,
            'an operator must be able to click Preview without wondering what it did');
    }

    /**
     * The employee statutory screen must explain WHY membership is recorded
     * rather than derived — otherwise it looks like redundant data entry.
     */
    public function test_the_statutory_profile_screen_explains_why_it_exists(): void
    {
        $this->assertStringContainsString(
            'stays a member after they cross it', self::$view);
    }
    // ── Fail-closed ──────────────────────────────────────────────────────

    /**
     * THE DOCUMENTED TRAP. A raw `fetch()` POST would be refused by CI's global
     * CSRF filter with a BLANK 403 — no log, no console error — and because
     * `fetch()` does not reject on 403, the panel would report the save as
     * done. The page's `postJson()` carries the token.
     */
    public function test_the_new_saves_carry_the_csrf_token(): void
    {
        $this->assertStringContainsString(
            "postJson('compensation/save_component_classification', payload)", self::$view);
        $this->assertStringContainsString(
            "postJson('compensation/save_fnf_policy', payload)", self::$view);

        $this->assertStringNotContainsString(
            "fetch(BASE + 'compensation/save_", self::$view,
            'a raw fetch POST here would be rejected with a blank 403 and reported as success');
    }

    /** And the reads go through the helper that rejects on an error payload. */
    public function test_the_new_reads_fail_closed(): void
    {
        $this->assertStringContainsString("api('compensation/get_component_classifications", self::$view);
        $this->assertStringContainsString("api('compensation/get_fnf_policy')", self::$view);

        $this->assertStringNotContainsString(
            "fetch(BASE + 'compensation/get_component_classifications", self::$view,
            'a bare fetch would render a 403 body as an empty list rather than an error');
    }

    /** The helper it delegates to genuinely checks both transport and payload. */
    public function test_the_shared_helper_checks_transport_and_payload(): void
    {
        $i = strpos(self::$view, 'function api(path, opts)');
        $this->assertNotFalse($i, 'the shared helper must exist');
        $body = substr(self::$view, (int) $i, 700);

        $this->assertStringContainsString("if (!r.ok || (j && j.status === 'error'))", $body,
            'both must be checked — either alone lets a failure through');
    }

    // ── Permission is enforced server-side, not by hiding a form ─────────

    /** The write endpoints require manage; hiding a form is only a courtesy. */
    public function test_the_write_endpoints_require_manage(): void
    {
        foreach ([
            'public function save_component_classification(): void',
            'public function save_fnf_policy(): void',
            'public function save_staff_statutory_profile(): void',
        ] as $fn) {
            $i = strpos(self::$ctrl, $fn);
            $this->assertNotFalse($i, "{$fn} not found");
            $this->assertStringContainsString('$this->_require_manage();',
                substr(self::$ctrl, (int) $i, 400),
                "{$fn} must enforce permission server-side");
        }
    }

    /** The read endpoints require at least view. */
    public function test_the_read_endpoints_require_view(): void
    {
        foreach ([
            'public function get_component_classifications(): void',
            'public function get_fnf_policy(): void',
            'public function preview_fnf(): void',
        ] as $fn) {
            $i = strpos(self::$ctrl, $fn);
            $this->assertNotFalse($i, "{$fn} not found");
            $this->assertStringContainsString('$this->_require_view();',
                substr(self::$ctrl, (int) $i, 400));
        }
    }

    /** A view-only user gets no form rendered, and the handlers no-op safely. */
    public function test_a_view_only_user_sees_no_editable_form(): void
    {
        $this->assertStringContainsString('<?php if ($canManage): ?>', self::$view);
        $this->assertStringContainsString(
            "var card = document.getElementById('clsFormCard');\n    if (!card) return;", self::$view,
            'the handler must tolerate the form being absent rather than throwing');
        $this->assertStringContainsString(
            "var btn = document.getElementById('fnfSave');\n    if (!btn) return;", self::$view);
    }

    // ── The screens explain, rather than presenting bare fields ──────────

    /**
     * A classification screen showing three unexplained words would be worse
     * than none. The panel must say what each state MEANS.
     */
    public function test_the_classification_screen_explains_the_three_states(): void
    {
        $this->assertStringContainsString('counts toward the statutory base', self::$view);
        $this->assertStringContainsString('Excluded, but still remuneration', self::$view);
        $this->assertStringContainsString('Not remuneration (reimbursement of actuals)', self::$view);
    }

    /** And that a determination is dated, so a correction is not retroactive. */
    public function test_the_classification_screen_says_determinations_are_dated(): void
    {
        // Asserted on a contiguous fragment: the sentence wraps across lines in
        // the template, so the full phrase never appears as one string.
        $this->assertStringContainsString('A determination is <b>dated</b>', self::$view);
        $this->assertStringContainsString('changes a payroll that has already run', self::$view);
    }

    /**
     * The F&F screen must say plainly that these are the school's choices and
     * that an unset basis REFUSES — otherwise an empty settlement looks broken
     * rather than unconfigured.
     */
    public function test_the_fnf_screen_says_the_values_are_the_schools_own(): void
    {
        $this->assertStringContainsString('not statutory values', self::$view);
        $this->assertStringContainsString('comes from your appointment letters', self::$view);
        $this->assertStringContainsString('refuse', self::$view);
    }

    /** And that separation of duties cannot be switched off. */
    public function test_the_fnf_screen_says_separation_of_duties_is_not_optional(): void
    {
        $this->assertStringContainsString(
            'Separation of duties is always enforced and cannot be switched off', self::$view);
    }

    /** Incomplete configuration is surfaced, not left to be discovered. */
    public function test_incomplete_configuration_is_shown_on_the_screen(): void
    {
        $this->assertStringContainsString('Configuration required before a settlement', self::$view);
    }

    // ── Units are converted at the boundary ──────────────────────────────

    /**
     * The threshold is entered in RUPEES and stored in PAISE. Getting this
     * wrong by a factor of a hundred is the classic money-field defect.
     */
    public function test_the_threshold_converts_rupees_to_paise(): void
    {
        $this->assertStringContainsString(
            'dualThresholdPaise: thRupees === \'\' ? null : Math.round(parseFloat(thRupees) * 100)',
            self::$view,
            'the field is rupees and the server stores paise');
        $this->assertStringContainsString("(th / 100)", self::$view,
            'and it must convert back for display');
    }

    /** An empty threshold sends null, not zero — they mean different things. */
    public function test_an_empty_threshold_sends_null_not_zero(): void
    {
        $this->assertStringContainsString("thRupees === '' ? null", self::$view,
            'zero would mean "every settlement needs two approvals"; null means "not configured"');
    }

    // ── No second settings framework ─────────────────────────────────────

    /**
     * PHASE E. The screens must reuse the page's existing helpers and styling,
     * not introduce a parallel one.
     */
    public function test_the_screens_reuse_the_existing_page_helpers(): void
    {
        foreach (['api(', 'postJson(', 'msg(', 'esc(', 'today()'] as $helper) {
            $this->assertStringContainsString($helper, self::$view);
        }

        foreach (['angular', 'React', 'Vue.', 'axios('] as $framework) {
            $this->assertStringNotContainsString($framework, self::$view,
                'no new frontend framework — the panel is AdminLTE and jQuery');
        }
    }

    /** And the existing class vocabulary, so the screens look like the product. */
    public function test_the_screens_use_the_existing_style_vocabulary(): void
    {
        // The REAL vocabulary this page defines. An earlier draft of these
        // panels invented cmp-table / cmp-grid / cmp-scroll / cmp-btn-primary /
        // cmp-sub / cmp-card-head — none of which had any CSS behind them, so
        // the screens would have rendered unstyled. Checked by name here, and
        // exhaustively by the next test.
        foreach (['cmp-panel', 'cmp-card', 'cmp-form', 'cmp-field', 'cmp-btn',
                  'cmp-note', 'cmp-tbl', 'cmp-tblwrap'] as $cls) {
            $this->assertGreaterThan(1, substr_count(self::$view, $cls),
                "{$cls} must be reused rather than a new one invented");
        }
    }

    /**
     * EVERY cmp- class used anywhere in the view must have a rule behind it.
     *
     * This is the check that would have caught the invented classes
     * immediately: a class with no CSS is invisible in a source diff and
     * obvious only when somebody opens the screen.
     */
    public function test_every_class_used_has_css_behind_it(): void
    {
        preg_match_all('/class="([a-z0-9 \-]*cmp-[a-z-]+[a-z0-9 \-]*)"/', self::$view, $m);

        $used = [];
        foreach ($m[1] as $attr) {
            foreach (explode(' ', $attr) as $c) {
                $c = trim($c);
                if ($c !== '' && strpos($c, 'cmp-') === 0) $used[$c] = true;
            }
        }
        $this->assertNotEmpty($used, 'scan sanity — classes must have been found');

        $undefined = [];
        foreach (array_keys($used) as $c) {
            if (!preg_match('/
\.' . preg_quote($c, '/') . '[\s{,.:]/', self::$view)) {
                $undefined[] = $c;
            }
        }

        $this->assertSame([], $undefined,
            'these classes have no CSS and would render unstyled: ' . implode(', ', $undefined));
    }
}
