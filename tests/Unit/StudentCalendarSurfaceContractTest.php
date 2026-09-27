<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * THE ADMIN SURFACE IS REACHABLE AND CANNOT BE TALKED OUT OF ITS INVARIANTS.
 *
 * A controller method that no route reaches is exactly as useless as the
 * configuration this programme found unread, so reachability is asserted rather
 * than assumed. The rest of this file guards the properties that live in the
 * controller and therefore cannot be proven by the store's own unit tests:
 * the dating boundary is server-computed, the write goes through the one store,
 * and the surface never touches the staff domain.
 *
 * Source-level assertions, because booting CodeIgniter is not available here.
 * They are deliberately narrow: each one names a specific way the surface could
 * be made unsafe.
 */
final class StudentCalendarSurfaceContractTest extends TestCase
{
    private static string $ctrl = '';
    private static string $routes = '';

    public static function setUpBeforeClass(): void
    {
        self::$ctrl   = (string) file_get_contents(APPPATH . 'controllers/Student_calendar.php');
        self::$routes = (string) file_get_contents(APPPATH . 'config/routes.php');
    }

    /** Comments stripped, so documentation cannot satisfy a code assertion. */
    private function code(): string
    {
        $c = preg_replace('~/\*.*?\*/~s', '', self::$ctrl);
        return (string) preg_replace('~//[^\n]*~', '', (string) $c);
    }

    // ── REACHABILITY ─────────────────────────────────────────────────────

    /** @dataProvider endpoints */
    public function test_every_endpoint_has_a_route_and_a_method(string $ep): void
    {
        $this->assertStringContainsString("\$route['student_calendar/{$ep}']", self::$routes,
            "student_calendar/{$ep} has no route — the method would be unreachable");
        $this->assertStringContainsString("'Student_calendar/{$ep}'", self::$routes,
            "the route for {$ep} does not point at the controller");
        $this->assertMatchesRegularExpression('~public function ' . $ep . '\s*\(~', self::$ctrl,
            "Student_calendar::{$ep}() does not exist");
    }

    public function endpoints(): array
    {
        return [['get_policy'], ['preview'], ['save_version']];
    }

    // ── THE SCREEN ───────────────────────────────────────────────────────

    /**
     * Menu + route + screen. A route with no menu entry is a page nobody finds,
     * and a controller with no view is an API nobody can use — both have shipped
     * in this repository before and both read as "done" from the code alone.
     */
    public function test_the_screen_is_reachable_from_the_menu(): void
    {
        $this->assertStringContainsString("\$route['student_calendar']", self::$routes,
            'the page itself needs a route, not only its endpoints');
        $this->assertMatchesRegularExpression('~public function index\s*\(~', self::$ctrl);
        $this->assertFileExists(APPPATH . 'views/student_calendar/index.php');

        $header = (string) file_get_contents(APPPATH . 'views/include/header.php');
        $this->assertStringContainsString("base_url('student_calendar')", $header,
            'the School Week page must be linked from the navigation');
    }

    public function test_the_menu_entry_names_its_owning_programme(): void
    {
        $header = (string) file_get_contents(APPPATH . 'views/include/header.php');
        $this->assertStringContainsString('CALENDAR PROGRAMME', $header,
            'header.php is edited by several programmes at once; the line must be '
            . 'attributable so it can be isolated at commit time');
    }

    // ── THE VIEW'S OWN SAFETY PROPERTIES ─────────────────────────────────

    private function view(): string
    {
        return (string) file_get_contents(APPPATH . 'views/student_calendar/index.php');
    }

    /**
     * THE PHANTOM-SUCCESS TRAP. fetch() does not reject on 403 or 500, so a
     * refused save renders as a saved one unless BOTH the HTTP status and the
     * payload's own status field are checked. This has bitten this codebase
     * before and is documented as a house rule.
     */
    public function test_the_client_fails_closed_on_a_refused_request(): void
    {
        $v = $this->view();
        $this->assertStringContainsString('!r.ok', $v,
            'the HTTP status must be checked — fetch() resolves on 403');
        $this->assertStringContainsString("j.status === 'error'", $v,
            "the payload's own status must be checked too");
    }

    /** CSRF: the token must be sent, and rotated from every response. */
    public function test_the_client_sends_and_rotates_the_csrf_token(): void
    {
        $v = $this->view();
        $this->assertStringContainsString('get_csrf_token_name()', $v);
        $this->assertStringContainsString('fd.append(CSRF_NAME, CSRF_HASH)', $v,
            'every POST must carry the token or CI rejects it with a blank 403');
        $this->assertStringContainsString('CSRF_HASH = j.csrf_token', $v,
            'the hash must be rotated from each response or the NEXT post 403s');
    }

    /** Server-rendered values must not be injected as raw HTML. */
    public function test_untrusted_values_are_escaped_before_rendering(): void
    {
        $v = $this->view();
        $this->assertStringContainsString('function escapeHtml', $v);
        $this->assertStringContainsString('escapeHtml(v.effectiveFrom', $v,
            'stored calendar values are rendered into the history table and must be escaped');
    }

    /** Read-only for a viewer: the editor must be disabled, not merely hidden. */
    public function test_the_editor_is_disabled_without_manage_permission(): void
    {
        $v = $this->view();
        $this->assertStringContainsString("has_permission('Attendance', 'manage')", $v);
        $this->assertStringContainsString('$sc_can_manage ? \'\' : \'disabled\'', $v,
            'the fieldset must be disabled so a viewer cannot submit a form the '
            . 'server would refuse anyway');
    }

    /**
     * A conflict must offer a RELOAD, never a retry: retrying would resubmit over
     * the policy that just landed, which is the lost update the store's
     * precondition exists to prevent.
     */
    public function test_a_conflict_offers_reload_rather_than_retry(): void
    {
        $v = $this->view();
        $this->assertStringContainsString('httpStatus === 409', $v);
        $this->assertStringContainsString('location.reload()', $v);
    }

    /** The effective-date field must not offer a date the server will refuse. */
    public function test_the_effective_date_input_is_bounded_by_the_settled_date(): void
    {
        $v = $this->view();
        $this->assertStringContainsString(".min = min", $v,
            'the date input must carry a minimum derived from settledThrough');
        $this->assertStringContainsString('settledThrough', $v);
    }

    /**
     * THE TIMEZONE OFF-BY-ONE, pinned.
     *
     * `new Date('2026-08-31T00:00:00')` parses as LOCAL midnight while
     * toISOString() reports UTC. East of UTC the +1 day therefore lands back on
     * the settled date itself — in IST the picker offered, and defaulted to, the
     * one date the server refuses. Reproduced under TZ=Asia/Kolkata before
     * fixing; the 'Z' is what makes the arithmetic zone-independent.
     */
    public function test_the_minimum_date_is_computed_in_utc(): void
    {
        $v = $this->view();
        $this->assertStringContainsString("j.settledThrough + 'T00:00:00Z'", $v,
            'the settled boundary must be parsed as UTC — without the Z this is '
            . 'off by one day in every timezone east of UTC, which includes the '
            . 'entire market this product serves');
        $this->assertStringNotContainsString("settledThrough + 'T00:00:00'\)", $v,
            'the local-time parse must be gone, not merely supplemented');
    }

    /** Every server-supplied message rendered as HTML must be escaped. */
    public function test_no_server_message_reaches_innerhtml_unescaped(): void
    {
        $v = $this->view();

        // note() assigns innerHTML, so every dynamic argument must be escaped.
        $this->assertSame(0, preg_match_all("~note\(\\\$\('\w+'\),\s*(?:e|j)\.\w+~", $v),
            'a server message is passed to note() without escapeHtml(). The '
            . 'validator quotes the offending value back — an unescaped day token '
            . "is an injection route into the administrator's own page.");
    }

    // ── CAL-P4 — ROLLOVER READINESS ──────────────────────────────────────

    /**
     * Rollover carries the weekly pattern automatically (school-scoped and
     * effective-dated) but deliberately carries no dated holidays. The gap that
     * leaves — a new session running with every festival treated as an ordinary
     * teaching day — is closed by TELLING the school, not by copying last year's
     * dates forward as this year's truth.
     */
    public function test_the_screen_reports_new_session_holiday_readiness(): void
    {
        $code = $this->code();
        // The exact WIRING, not the method name: the name survives as the method
        // definition even when the call is removed from the response, so a mutant
        // that dropped the wiring passed unnoticed.
        $this->assertStringContainsString("'readiness'       => \$this->_calendar_readiness(),", $code,
            'readiness must actually be returned to the screen, not merely defined');
        $this->assertStringContainsString("holidays_in_range(\$from, \$to, 'students')", $code,
            'readiness must count REAL declared STUDENT holidays — a staff-only '
            . 'closure is not a school holiday, and guessing would be worthless');
        // The element id alone would still be present if the JavaScript that
        // fills it were deleted — a banner that exists but never renders. Assert
        // the wiring that actually puts the message on screen.
        $v = $this->view();
        $this->assertStringContainsString("note(\$('scReadiness'), escapeHtml(rd.message), 'warn')", $v,
            'the readiness message must actually be rendered, not just have a container');
        $this->assertStringContainsString('rd.checked && rd.message', $v,
            'and it must only render when the check actually ran — an unreadable '
            . 'calendar must not display as "no holidays declared"');
    }

    /** A read failure must not masquerade as "you have declared nothing". */
    public function test_readiness_distinguishes_none_declared_from_could_not_check(): void
    {
        $code = $this->code();
        // The exact GUARD, not the name: load_ok() also appears in get_policy(),
        // so a mutant that neutered this guard kept the substring and survived.
        $this->assertStringContainsString(
            'if (!$this->holiday_service->load_ok()) return $out;', $code,
            'an unreadable calendar must leave checked=false rather than report zero '
            . 'holidays — a false alarm trains the administrator to ignore the banner');
        $this->assertMatchesRegularExpression("~'checked'\s*=>\s*false~", $code);
    }

    /**
     * The session window must come from the CANONICAL authority, not a second
     * interpretation invented here.
     *
     * An earlier pass concluded no such authority existed and used a rolling
     * twelve months. That was wrong — the search had been for field names rather
     * than for a service. `Leave_year_service` is pure, static, already live via
     * prorateEntitlement(), and owns the April convention as a CONFIGURABLE
     * default that a tenant overrides in one place.
     */
    public function test_the_session_window_reuses_leave_year_service(): void
    {
        $code = $this->code();
        $this->assertStringContainsString('Leave_year_service::boundsForSession(', $code,
            'the session boundary must be asked of the canonical resolver');
        // The ASSIGNMENT, not the field name. A mutant that neutered the guard
        // left `academicYearStartMonth` on the line above still being READ, so
        // the name-only assertion passed while the override was ignored. This is
        // the SIXTH substring collision in this programme — a name appearing
        // somewhere in a file proves nothing about whether it is acted on.
        $this->assertStringContainsString(
            'if ($sm >= 1 && $sm <= 12) $startMonth = $sm;', $code,
            "reading the tenant's academicYearStartMonth is not the same as "
            . 'honouring it — the override must actually be applied');
        // The ASSIGNMENT again, not the name. This is the direct sibling of the
        // academicYearStartMonth collision above and I had left it in the weak
        // form — a mutant that read the field and then discarded it would have
        // passed. Seventh instance of the same mistake in this programme.
        $this->assertStringContainsString(
            "\$overrides = \$school['sessionBoundaries'];", $code,
            'a per-session override must be READ INTO the resolver call, not merely mentioned');

        // The April default must not be re-typed here as a literal.
        $this->assertStringContainsString('Leave_year_service::DEFAULT_START_MONTH', $code,
            'the default must be READ from the owning service, never duplicated — '
            . 'a second literal 4 in this file would be a new hardcoded convention');
        $this->assertDoesNotMatchRegularExpression('~startMonth\s*=\s*4\b~', $code,
            'no locally invented April start');
    }

    /** An unparseable session label must still produce a useful reminder. */
    public function test_an_unparseable_session_label_falls_back_not_silent(): void
    {
        $code = $this->code();
        $this->assertStringContainsString("'the next twelve months'", $code,
            'a malformed label must degrade to an imprecise reminder, never to none');
    }

    /** Rollover must never copy dated holidays forward as future truth. */
    public function test_the_school_week_surface_writes_no_calendar_events(): void
    {
        $code = $this->code();
        foreach (['saveEvent', 'Calendar_service', 'carriedFromSession'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code,
                "the school-week surface must not write calendar EVENTS ({$forbidden}). "
                . "Last year's Diwali is not this year's Diwali.");
        }
    }

    /** The screen must state the domain boundary in words a school reads. */
    public function test_the_screen_explains_the_staff_student_boundary(): void
    {
        $v = $this->view();
        $this->assertStringContainsString('Work Schedules', $v,
            'an administrator must be told where staff working days live, or they '
            . 'will look for them here');
    }

    /** Divergence is offered for adoption, never auto-applied. */
    public function test_the_divergence_banner_never_applies_itself(): void
    {
        $v = $this->view();
        $this->assertStringContainsString('Nothing has been changed', $v);
        $this->assertStringContainsString('Not saved yet', $v,
            'loading the saved days into the editor must not read as having saved them');
    }

    // ── AUTHORISATION ────────────────────────────────────────────────────

    /**
     * Counted against the actual number of public entry points rather than a
     * hardcoded number — a literal goes stale the moment an endpoint is added,
     * and a stale count fails for the wrong reason instead of catching a genuinely
     * ungated endpoint. (It did exactly that when index() was added.)
     */
    public function test_every_public_entry_point_is_permission_gated(): void
    {
        $code = $this->code();
        preg_match_all('~public function (\w+)\s*\(~', $code, $m);
        $public = array_values(array_filter($m[1], fn($n) => $n !== '__construct'));

        $this->assertGreaterThan(0, count($public));
        $this->assertSame(count($public), substr_count($code, 'require_permission('),
            'every public endpoint must be gated — an ungated preview() would leak a '
            . "school's operating calendar. Public methods found: " . implode(', ', $public));
    }

    public function test_writing_requires_manage_and_reading_only_view(): void
    {
        $code = $this->code();
        $this->assertStringContainsString("require_permission(self::MODULE, 'student_calendar_manage', 'manage')", $code,
            'saving a school week must require manage, not view');
        $this->assertSame(1, substr_count($code, "'student_calendar_manage', 'manage'"),
            'exactly one endpoint — the writer — may require manage');
        $this->assertSame(3, substr_count($code, "'student_calendar_view', 'view'"),
            'the read endpoints (index, get_policy, preview) require only view');
    }

    // ── THE DATING BOUNDARY IS NOT CLIENT-CONTROLLED ─────────────────────

    /**
     * If the client could supply `settledThrough`, the retroactive gate would be
     * advisory: anyone could post a boundary in the distant past and back-date a
     * policy over recorded attendance.
     */
    public function test_the_settled_boundary_is_computed_on_the_server(): void
    {
        $code = $this->code();
        $this->assertStringContainsString('private function _settled_through()', $code);
        $this->assertStringContainsString('$this->_settled_through()', $code);

        $this->assertDoesNotMatchRegularExpression(
            "~settledThrough.{0,40}\\\$this->input->(post|get)~s", $code,
            'the settled boundary must never come from the request');
    }

    public function test_today_is_server_resolved(): void
    {
        $this->assertStringContainsString('private function _today()', $this->code());
        $this->assertDoesNotMatchRegularExpression(
            "~\\\$this->input->(post|get)\('today'~", $this->code(),
            'the client must not be able to declare what day it is');
    }

    // ── ONE WRITER ───────────────────────────────────────────────────────

    public function test_the_controller_writes_only_through_the_store(): void
    {
        $code = $this->code();
        $this->assertStringContainsString('Student_calendar_store', $code);

        foreach (['$this->fs->set(', '$this->fs->update(', 'firestoreSet(',
                  'firestoreUpdate(', 'firestoreDelete(', 'setEntity('] as $direct) {
            $this->assertStringNotContainsString($direct, $code,
                "the controller performs a direct write ({$direct}); every calendar "
                . 'write must go through Student_calendar_store so the append-only '
                . 'and compare-and-set guarantees cannot be bypassed');
        }
    }

    // ── DOMAIN SEPARATION ────────────────────────────────────────────────

    public function test_the_surface_never_touches_the_staff_domain(): void
    {
        $code = $this->code();
        foreach ([
            'Working_calendar_service', 'Policy_assignment_service',
            'Work_schedule_service', 'workSchedules', 'attendancePolicy',
            'staffScheduleAssignments',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code,
                "the student school-week surface references '{$forbidden}'. Staff "
                . 'working time is a different question with a different owner.');
        }
    }

    /** Holiday reads on this surface must be student-scoped. */
    public function test_holiday_reads_pass_the_student_audience(): void
    {
        $code = $this->code();
        if (strpos($code, 'holidays_in_month(') === false) {
            $this->markTestSkipped('no holiday read on this surface');
        }
        $this->assertSame(0, preg_match_all("~holidays_in_month\(\s*\\\$year,\s*\\\$month\s*\)~", $code),
            'a holiday read with no audience defaults to staff (CAL-D9/CAL-D11); '
            . "the student surface must pass 'students'");
        $this->assertStringContainsString("'students'", $code);
    }

    // ── THE DIVERGENCE IS REPORTED, NEVER APPLIED ────────────────────────

    public function test_divergence_is_surfaced_but_never_auto_applied(): void
    {
        $code = $this->code();
        $this->assertStringContainsString('divergence()', $code,
            'the CAL-D10 mismatch must be reported to the administrator');
        // Match the QUOTED key, not the bare substring: `special_working_days_in_month`
        // legitimately contains "working_days", and asserting on the substring
        // failed against correct code — a false positive in the test, not a
        // defect in the surface.
        $this->assertStringNotContainsString("'working_days'", $code,
            'the surface must not read the stale setting directly, and must '
            . 'certainly never save it as policy on the school\'s behalf');
        $this->assertStringNotContainsString('"working_days"', $code);
    }

    // ── FAILURE SEMANTICS ARE DISTINGUISHED ──────────────────────────────

    /**
     * A concurrent-save refusal is not a validation error and not a server fault.
     * Collapsing them would make the front-end offer a retry that silently
     * overwrites the other administrator's policy.
     */
    public function test_conflict_invalid_and_failure_are_reported_distinctly(): void
    {
        $code = $this->code();
        $this->assertStringContainsString('Student_calendar_store::CONFLICT', $code);
        $this->assertStringContainsString('409', $code, 'a conflict must be a 409');
        $this->assertStringContainsString('Student_calendar_store::INVALID', $code);
        $this->assertStringContainsString('422', $code, 'a validation refusal must be a 422');
    }

    public function test_the_routes_block_documents_the_domain_boundary(): void
    {
        $this->assertStringContainsString('CALENDAR PROGRAMME', self::$routes,
            'the shared routes file must say which programme owns these lines — it '
            . 'is edited by several at once and the block must be separable');
    }
}
