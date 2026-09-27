<?php
/**
 * EVERY BUTTON REACHES SOMETHING, AND EVERY ENDPOINT IS REACHABLE.
 *
 * Three things have to line up for a feature to exist for a school: the screen
 * calls an endpoint, the endpoint is routed, and the controller has the method.
 * Any two of the three passing their own tests still leaves a dead button.
 *
 * This module already had a live example. saveSubstitute() has always accepted
 * an id and replaced a cover's assignments, update_substitute has always been
 * routed — and nothing on the screen ever set subEditId, so re-arranging a
 * cover was unreachable and the only way to change one was to delete it.
 */

use PHPUnit\Framework\TestCase;

final class SubstituteReachabilityTest extends TestCase
{
    private const CTRL   = __DIR__ . '/../../application/controllers/Academic.php';
    private const ROUTES = __DIR__ . '/../../application/config/routes.php';
    private const VIEW   = __DIR__ . '/../../application/views/academic/index.php';

    /** Every endpoint the substitute surface depends on. */
    private const ENDPOINTS = [
        'get_substitutes', 'save_substitute', 'update_substitute', 'delete_substitute',
        'get_absent_teachers', 'get_absent_teacher_schedule',
        'get_cover_policy', 'save_cover_policy', 'cover_log', 'cover_history',
        'keep_cover',
    ];

    public function test_every_endpoint_the_screen_calls_exists_on_the_controller(): void
    {
        $ctrl = (string) file_get_contents(self::CTRL);
        $view = (string) file_get_contents(self::VIEW);

        preg_match_all("~post\('academic/([a-z_]+)'~", $view, $m);
        $called = array_values(array_unique($m[1]));
        $this->assertNotEmpty($called, 'the panel calls nothing at all?');

        foreach ($called as $fn) {
            $this->assertMatchesRegularExpression(
                '~public function ' . preg_quote($fn, '~') . '\s*\(~', $ctrl,
                "the screen calls academic/{$fn} and no such method exists");
        }
    }

    public function test_every_endpoint_is_explicitly_routed(): void
    {
        $routes = (string) file_get_contents(self::ROUTES);

        foreach (self::ENDPOINTS as $fn) {
            $this->assertStringContainsString("'academic/{$fn}'", $routes,
                "academic/{$fn} is not routed. CI would reach it by default routing "
                . 'today, but every sibling here is explicit, and a surface that '
                . 'works only by accident is one nobody can rely on');
        }
    }

    public function test_every_route_points_at_the_method_it_names(): void
    {
        $routes = (string) file_get_contents(self::ROUTES);

        foreach (self::ENDPOINTS as $fn) {
            $this->assertMatchesRegularExpression(
                "~'academic/{$fn}'\s*\]\s*=\s*'Academic/{$fn}'~", $routes,
                "the route for academic/{$fn} points somewhere else");
        }
    }

    /**
     * The three things a cover can have done to it, and the control that
     * reaches each. A drift warning with no way to resolve it is how the
     * warning became furniture in the first place.
     */
    public function test_every_cover_action_has_a_control_on_the_screen(): void
    {
        $view = (string) file_get_contents(self::VIEW);

        foreach ([
            'AC.sub.edit('      => 're-arranging a cover',
            'AC.sub.setStatus(' => 'cancelling or completing a cover',
            'AC.sub.del('       => 'removing a cover',
            'AC.sub.keep('      => 'keeping a cover the timetable moved under',
            'AC.sub.history('   => 'asking who changed a cover',
            'AC.sub.loadLog('   => 'the cover log',
            'AC.sub.savePolicy(' => 'setting the cover policy',
        ] as $call => $what) {
            $this->assertStringContainsString($call, $view,
                "nothing on the screen reaches {$what}");
        }
    }

    /** And each of those controls calls a function that is actually defined. */
    public function test_every_control_calls_a_function_that_exists(): void
    {
        $view = (string) file_get_contents(self::VIEW);

        preg_match_all('~AC\.sub\.([a-zA-Z_]+)\(~', $view, $m);
        $called = array_values(array_unique($m[1]));

        foreach ($called as $fn) {
            $this->assertMatchesRegularExpression(
                '~\n\s*' . preg_quote($fn, '~') . '\s*:\s*function~', $view,
                "the screen calls AC.sub.{$fn}() and no such function is defined — "
                . 'the button throws and every later handler on the page dies with it');
        }
    }
}
