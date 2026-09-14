<?php

use PHPUnit\Framework\TestCase;

/**
 * "Are teachers actually seeing this timetable yet?"
 *
 * ── WHY IT EXISTS ───────────────────────────────────────────────────────
 *
 * Publishing is instant for the resolver and INVISIBLE to everything that
 * reads the legacy `timetables` collection — the Teacher app, the Parent app,
 * Substitute, Lesson Planner. Those documents change only when the projection
 * cron runs, and this repository owns no scheduler: every ZenX cron is
 * CLI-only, one tenant per invocation, driven by the operator's own crontab
 * (Transport_expiry_cron states the convention).
 *
 * So a correct, published, in-force timetable can sit unmaterialised for days
 * while every app shows the previous one, and nothing said so. On 2026-09-12
 * the only symptom was a teacher missing from a workload table. The gap is now
 * stated where it is created.
 *
 * ── WHAT IT MUST NOT BECOME ─────────────────────────────────────────────
 *
 * A second authority, or an APPLY button. It asks the resolver which version is
 * in force and asks the projection for a DRY RUN. Applying stays CLI-only
 * behind APPLY=1 — that seam is where a person decides, and this must not move
 * it. Both are asserted below.
 */
final class TimetableVisibilityStatusTest extends TestCase
{
    private static string $ctrl = '';
    private static string $view = '';

    public static function setUpBeforeClass(): void
    {
        $root = __DIR__ . '/../..';
        self::$ctrl = (string) file_get_contents($root . '/application/controllers/Timetable_lifecycle.php');
        self::$view = (string) file_get_contents($root . '/application/views/timetable/lifecycle.php');
    }

    /** The endpoint body, bounded by the next method. */
    private function body(): string
    {
        $i = strpos(self::$ctrl, 'public function visibility(): void');
        $this->assertNotFalse($i, 'the visibility endpoint is gone');
        $next = strpos(self::$ctrl, "\n    public function ", $i + 10);
        return substr(self::$ctrl, $i, ($next !== false ? $next - $i : 4000));
    }

    // ── reachability: route + controller + a caller ─────────────────────

    public function test_it_is_routed_and_called_from_the_screen(): void
    {
        $routes = (string) file_get_contents(
            __DIR__ . '/../../application/config/routes.php');

        $this->assertStringContainsString(
            "\$route['timetable/visibility']", $routes, 'the route is gone');
        $this->assertStringContainsString(
            "api('timetable/visibility?date='", self::$view,
            'nothing calls it — a routed endpoint with no caller is invisible');
    }

    // ── it decides nothing ──────────────────────────────────────────────

    public function test_it_asks_the_shared_resolver_rather_than_deciding(): void
    {
        $b = $this->body();

        $this->assertStringContainsString('resolveForDate(', $b,
            'it does not ask the resolver, so it would be a second answer to '
            . '"which timetable is in force"');
        $this->assertStringContainsString('Timetable_plan_service::RESOLVED', $b,
            'it does not use the resolver\'s own outcome constants');
    }

    /**
     * THE LINE THAT MATTERS. Applying the projection is CLI-only behind
     * APPLY=1. A `true` third argument here would move that seam into a screen
     * that any Academic-view user can open.
     */
    public function test_it_never_applies_the_projection(): void
    {
        $b = $this->body();

        $this->assertStringContainsString('projectPublishedVersion(', $b,
            'it no longer compares against the projection at all');

        // The dry run passes exactly two arguments; $apply defaults to false.
        $this->assertMatchesRegularExpression(
            '~projectPublishedVersion\(\s*\$planId\s*,\s*\$out\[\x27versionId\x27\]\s*\)~', $b,
            'the projection call passes an explicit apply flag — this endpoint '
            . 'must only ever dry-run');

        $this->assertStringNotContainsString('true)', str_replace(
            ['=== true)', '!== true)'], '', $b),
            'a literal true is being passed somewhere in this endpoint');
    }

    public function test_it_requires_only_view_permission(): void
    {
        $b = $this->body();
        $this->assertMatchesRegularExpression(
            "~require_permission\('Academic',\s*'[a-z_]+',\s*'view'\)~", $b,
            'reading whether teachers can see the timetable changes nothing and '
            . 'must not demand more than view');
    }

    // ── it never claims more than it knows ──────────────────────────────

    /**
     * Ambiguous, none, unreadable — in each the mirror CANNOT be judged, and
     * saying "current" or "stale" would both be inventions.
     */
    public function test_an_unresolved_date_is_reported_as_not_comparable(): void
    {
        $b = $this->body();

        $this->assertStringContainsString("\$out['comparable'] = false;", $b,
            'a date with no single resolved version is still being judged');
        $this->assertStringContainsString("\$out['pending']    = null;", $b,
            'a pending count is being reported for a date whose timetable is unknown');
    }

    /**
     * If the CHECK fails, the screen must not render reassurance. A failed
     * check that draws "teachers are seeing this" is the same silent-stale
     * defect one level up.
     */
    public function test_a_failed_check_never_renders_as_confirmation(): void
    {
        $b = $this->body();
        $this->assertStringContainsString('This is not a confirmation that they are', $b,
            'a failed check does not say that it failed');

        // And on the client.
        $i = strpos(self::$view, 'function loadVisibility()');
        $this->assertNotFalse($i, 'the client half is gone');
        // Wide enough to reach the handler's end: the banner grew when it
        // gained the "send to teachers and parents" action, and a slice that
        // stopped short would report the catch as missing when it is there.
        $js = substr(self::$view, $i, 6000);

        $this->assertStringContainsString('.catch(', $js,
            'a failed request is unhandled, so the panel keeps whatever it showed before');
        $this->assertStringContainsString('could not be checked', $js,
            'the failure path does not say the check failed');
    }

    /** The answer must carry its own remedy — the exact command, tenant-bound. */
    public function test_it_returns_the_exact_command_for_this_tenant(): void
    {
        $b = $this->body();

        $this->assertStringContainsString('timetable_projection_cron run', $b,
            'the status does not tell the operator how to fix it');
        $this->assertStringContainsString('$this->school_id', $b,
            'the command is not bound to this tenant');
        $this->assertStringContainsString('$this->session_year', $b,
            'the command is not bound to this session');
    }

    /**
     * The tenant must come from the authenticated server context, never from
     * the request — a caller-supplied school id would make this a cross-tenant
     * read of another school's timetable state.
     */
    public function test_the_tenant_is_never_caller_supplied(): void
    {
        $b = $this->body();

        $this->assertStringNotContainsString("input->get('school", $b);
        $this->assertStringNotContainsString("input->post('school", $b);
        $this->assertStringNotContainsString("input->get('session", $b);

        // Only the DATE may come from the caller, and it is validated.
        $this->assertMatchesRegularExpression(
            '~preg_match\(\x27/\^\\\\d\{4\}-\\\\d\{2\}-\\\\d\{2\}\$/\x27,\s*\$date\)~', $b,
            'the caller-supplied date is not validated to an ISO shape');
    }
}
