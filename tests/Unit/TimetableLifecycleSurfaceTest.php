<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__ . '/../../system/');
if (!defined('APPPATH'))  define('APPPATH',  __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../../application/libraries/Timetable_plan_store.php';

/**
 * PHASE B + F — the canonical timetable HTTP surface and its authorization.
 *
 * A CI controller cannot be instantiated here, so its BEHAVIOUR through the
 * store is exercised directly and its AUTHORIZATION is pinned structurally.
 * That split is stated rather than glossed: the guard expressions below are
 * verified to exist and to be the right grade, not observed rejecting a
 * request.
 *
 * The rule this file defends: no timetable surface becomes reachable without an
 * explicitly verified authorization model, and nothing on it can reach the
 * legacy collection or mutate published data.
 */
final class TimetableLifecycleSurfaceTest extends TestCase
{
    private function controller(): string
    {
        return (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Timetable_lifecycle.php');
    }

    private function routes(): string
    {
        return (string) file_get_contents(
            __DIR__ . '/../../application/config/routes.php');
    }

    /** Every public action on the controller. */
    private function actions(): array
    {
        preg_match_all('~\n    public function (\w+)\(~', $this->controller(), $m);
        return array_values(array_diff($m[1], ['__construct']));
    }

    // ── every action is authorized, at the right grade ──────────────────

    public function test_every_action_requires_a_permission(): void
    {
        $src = $this->controller();

        foreach ($this->actions() as $action) {
            $i = strpos($src, "public function {$action}(");
            $body = substr($src, $i, 600);

            $this->assertStringContainsString("require_permission('Academic'", $body,
                "{$action}() is reachable over HTTP with no permission check. A UI "
                . 'that hides a button is not authorization.');
        }
    }

    public function test_the_permission_grades_match_the_impact_of_the_action(): void
    {
        $src = $this->controller();

        $expected = [
            // reading changes nothing
            'index'               => 'view',
            'versions'            => 'view',
            'verdict'             => 'view',
            'projection_preview'  => 'view',
            // reading whether the legacy mirror matches what the resolver says
            // is in force. It dry-runs and writes nothing; applying stays
            // CLI-only behind APPLY=1, which is the seam where a person decides
            // and which this deliberately does not move.
            'visibility'          => 'view',
            // …and CHANGING what they see is `manage`, the same grade as
            // publish(). It decides what every teacher and parent in the school
            // opens, and the apps hold one timetable, so there is no undo.
            'apply_projection'    => 'manage',
            'days'                => 'view',
            'editor_options'      => 'view',
            // printing shows nothing the screen does not already show, and an
            // unpublished timetable is stamped as a proposal on the page itself
            'pdf'                 => 'view',
            // reports on versions the operator can already see, removes nothing
            'capacity'            => 'view',
            // private work on a candidate — nobody else sees it
            'create_change_version' => 'edit',
            'generate_candidate'  => 'edit',
            'create_blank'        => 'edit',
            // building several proposals is still only building proposals
            'generate_candidates' => 'edit',
            // naming changes no lesson: which timetable applies is decided by
            // its dates, never by its label
            'rename'              => 'edit',
            'save_day'            => 'edit',
            'save_period'         => 'edit',
            'validate_candidate'  => 'edit',
            // these change more than one person's work: publishing changes what
            // every teacher sees, and discarding removes a proposal outright
            'publish'             => 'manage',
            'discard'             => 'manage',
            // reclaim is bulk discard, so it carries discard's grade
            'reclaim'             => 'manage',
            // it is the step that makes a stuck build removable
            'abandon'             => 'manage',
            // ending a live timetable's future applicability is at least as
            // consequential as starting it, so it carries publish's grade.
            // RBAC grants on module and level only, so this is deliberately
            // NOT modelled as a capability separable from publish.
            'supersede'           => 'manage',
        ];

        $this->assertSame(
            array_values(array_diff($this->actions(), array_keys($expected))), [],
            'a new action was added without deciding what grade of access it needs');

        foreach ($expected as $action => $level) {
            $i = strpos($src, "public function {$action}(");
            $this->assertNotFalse($i, "{$action}() is gone");
            $body = substr($src, $i, 600);

            $this->assertMatchesRegularExpression(
                "~require_permission\('Academic',\s*'[a-z_]+',\s*'{$level}'\)~", $body,
                "{$action}() must require '{$level}'. Publishing is separated at "
                . "'manage' because editing a candidate affects nobody and "
                . 'publishing changes what every teacher and parent sees.');
        }
    }

    /**
     * The period index is the one input that addresses a specific cell, and
     * PHP's int cast turns "abc" into 0 — which would silently edit period 1
     * instead of refusing. The store cannot catch that: its parameter is typed
     * int, so coercion has already happened by the time it is called.
     *
     * A mutation removing this check survived the behavioural suite because a
     * CI_Controller body cannot be executed here, so the guard is pinned
     * structurally and that limit is stated rather than glossed.
     */
    public function test_the_period_index_is_validated_before_use(): void
    {
        $src  = $this->controller();
        $i    = strpos($src, 'public function save_period(');
        $this->assertNotFalse($i, 'save_period() is gone');
        $body = substr($src, $i, 1600);

        $this->assertStringContainsString('is_numeric($periodRaw)', $body,
            'a non-numeric period index casts to 0 and would edit the first '
            . 'period instead of being refused');
        $this->assertStringContainsString('< 0', $body,
            'a negative period index must be refused');

        $guard = strpos($body, 'is_numeric($periodRaw)');
        $call  = strpos($body, 'saveCandidatePeriod(');
        $this->assertNotFalse($call);
        $this->assertLessThan($call, $guard,
            'the check must run before the cell is addressed, not after');
    }

    /**
     * save_day must go through the CELL-based writer. Pointing it back at
     * saveCandidateDay() would restore whole-day replacement over HTTP, which is
     * the ACAD-D21 shape the legacy whole-week save had.
     */
    public function test_the_bulk_endpoint_uses_the_cell_based_writer(): void
    {
        $src  = $this->controller();
        $i    = strpos($src, 'public function save_day(');
        $this->assertNotFalse($i, 'save_day() is gone');
        $body = substr($src, $i, 2200);

        $this->assertStringContainsString('saveCandidateDayCells(', $body,
            'the bulk endpoint must replace only the addressed cells');
        $this->assertStringNotContainsString('store()->saveCandidateDay(', $body,
            'whole-day replacement must not be reachable over HTTP');
        $this->assertStringContainsString('is_numeric($index)', $body,
            'a non-numeric key casts to 0 and would rewrite the first period');
    }

    /**
     * Loading a stateful library is not the same as initialising it.
     *
     * generate_candidate() loaded Timetable_service and called it straight away.
     * The service keeps schoolId and session as state and builds every document
     * id from them, so an un-init'd instance addressed document "_" and read
     * nothing — which reached the operator as "Period Scheduling not configured"
     * on a school whose period scheduling was configured. An empty read and an
     * absent setting are indistinguishable from the outside, which is the whole
     * defect class this programme exists to remove.
     */
    public function test_stateful_libraries_are_initialised_before_use(): void
    {
        $src = $this->controller();

        // The library must only be reached through a helper that init()s it.
        $this->assertStringContainsString('private function generator()', $src,
            'the generator must be obtained through an initialising helper');
        $this->assertStringContainsString('$this->tt->init(', $src,
            'Timetable_service keeps the tenant and session as state; loading it '
            . 'without init() leaves every lookup pointed at an empty document id');
        $this->assertStringContainsString('$this->tt->isReady()', $src,
            'readiness must be checked, not assumed');

        // And nothing may call the service without going through that helper.
        $code = (string) preg_replace('~/\*.*?\*/~s', '', $src);
        $code = (string) preg_replace('~^\s*//.*$~m', '', $code);
        preg_match_all('~\$this->tt->(\w+)\(~', $code, $m);
        $allowed = ['init', 'isReady'];
        foreach (array_unique($m[1]) as $call) {
            $this->assertContains($call, $allowed,
                "\$this->tt->{$call}() bypasses generator(): call it through the "
                . 'helper so the service is guaranteed to be initialised');
        }
    }

    public function test_no_legacy_role_name_gate_is_used(): void
    {
        // Strip comments: the class docblock legitimately NAMES the legacy gate
        // to explain why it is not used, and that is documentation, not a call.
        $code = (string) preg_replace('~/\*.*?\*/~s', '', $this->controller());
        $code = (string) preg_replace('~^\s*//.*$~m', '', $code);

        $this->assertStringNotContainsString('_require_role', $code,
            'the legacy name gate still shadows RBAC elsewhere in this codebase '
            . 'and blocks custom roles; a new surface must not inherit it');
    }

    // ── tenant and session cannot be supplied by the caller ─────────────

    public function test_the_tenant_and_session_come_from_the_request_context(): void
    {
        $src = $this->controller();
        $i    = strpos($src, 'private function store(');
        // Wide enough to span the method AND the comment above the wiring: store()
        // now also injects the audit service, so a 700-char window stopped short of
        // the tenant binding this test is about.
        $body = substr($src, $i, 2200);

        $this->assertStringContainsString('$this->school_id', $body);
        $this->assertStringContainsString('$this->session_year', $body);

        foreach (['school_id', 'schoolId', 'session', 'session_year'] as $param) {
            $this->assertStringNotContainsString("input->post('{$param}')", $src,
                "accepting {$param} from the request would let one tenant address "
                . "another tenant's timetables");
            $this->assertStringNotContainsString("input->get('{$param}')", $src);
        }
    }

    // ── the surface cannot reach the legacy collection or published data ─

    public function test_the_surface_never_writes_the_legacy_collection(): void
    {
        $src = $this->controller();

        $this->assertStringNotContainsString("'timetables'", $src);
        $this->assertStringNotContainsString('COLLECTION_LEGACY_TT', $src);
        $this->assertStringNotContainsString('projectPublishedVersion($this->planId(), $versionId, true', $src,
            'no HTTP request may rewrite what teachers currently see; applying the '
            . 'projection is the cron\'s job, behind APPLY=1');
    }

    public function test_the_projection_preview_is_read_only(): void
    {
        $src  = $this->controller();
        $i    = strpos($src, 'public function projection_preview(');
        $body = substr($src, $i, 700);

        $this->assertStringContainsString('projectPublishedVersion(', $body);
        $this->assertMatchesRegularExpression(
            '~projectPublishedVersion\(\s*\$this->planId\(\),\s*\$versionId\)~', $body,
            'the preview must omit the apply argument entirely, so it cannot be '
            . 'turned into a write by a mistaken truthy value');
    }

    public function test_there_is_no_unpublish_or_delete_action(): void
    {
        foreach (['unpublish', 'delete_version', 'revert', 'edit_published',
                  'force_publish'] as $forbidden) {
            $this->assertNotContains($forbidden, $this->actions(),
                "published timetables are immutable; {$forbidden}() would be the "
                . 'mutable backdoor the version architecture exists to remove');
        }
    }

    // ── routing ─────────────────────────────────────────────────────────

    public function test_every_action_is_routed(): void
    {
        $routes = $this->routes();
        foreach ($this->actions() as $action) {
            $this->assertStringContainsString("Timetable_lifecycle/{$action}", $routes,
                "{$action}() has no route, so the surface is not actually reachable "
                . '— a menu entry pointing at it would 404');
        }
    }

    public function test_the_surface_is_not_csrf_exempt(): void
    {
        $config = (string) file_get_contents(
            __DIR__ . '/../../application/config/config.php');

        $this->assertStringNotContainsString('timetable/', $config,
            'the canonical timetable surface must NOT be added to '
            . 'csrf_exclude_uris: these are ordinary session-authenticated admin '
            . 'POSTs, and exempting them would remove a protection for '
            . 'convenience rather than necessity');
    }

    // ── behaviour: the store the surface calls actually enforces this ───

    public function test_a_published_version_is_immutable_through_the_store(): void
    {
        $fb = new FakeCronFirestore();
        $doc = 'SCH_A_2026-2027_REGULAR_V1';
        $fb->versions[$doc] = [
            'schoolId' => 'SCH_A', 'session' => '2026-2027',
            'planId' => 'REGULAR', 'versionId' => 'V1',
            'status' => Timetable_plan_service::STATUS_PUBLISHED,
            'effectiveFrom' => '2026-06-01', 'effectiveTo' => '',
        ];
        $store = (new Timetable_plan_store())->init($fb, 'SCH_A', '2026-2027', 'STA1');

        $this->expectException(Service_exception::class);
        $this->expectExceptionMessageMatches('~cannot be edited directly~i');
        $store->saveCandidateDay('REGULAR', 'V1', 'Class 8th/Section A', 'Monday', []);
    }

    public function test_the_regular_plan_is_created_once_and_reused(): void
    {
        $fb    = new FakeCronFirestore();
        $store = (new Timetable_plan_store())->init($fb, 'SCH_A', '2026-2027', 'STA1');

        $a = $store->ensureRegularPlan();
        $writesAfterFirst = count($fb->writes);
        $b = $store->ensureRegularPlan();

        $this->assertSame('REGULAR', $a['planId']);
        $this->assertSame($a['planId'], $b['planId']);
        $this->assertSame(Timetable_plan_service::KIND_REGULAR, $a['kind']);
        $this->assertCount($writesAfterFirst, $fb->writes,
            'the plan must be created once; re-creating it on every request would '
            . 'rewrite it constantly');
    }

    public function test_another_tenants_plan_document_is_refused(): void
    {
        $fb = new FakeCronFirestore();
        $fb->plans['SCH_A_2026-2027_REGULAR'] = [
            'schoolId' => 'SCH_OTHER', 'session' => '2026-2027',
            'planId' => 'REGULAR', 'kind' => 'regular',
        ];
        $store = (new Timetable_plan_store())->init($fb, 'SCH_A', '2026-2027', 'STA1');

        $this->expectException(Service_exception::class);
        $store->ensureRegularPlan();
    }
}
