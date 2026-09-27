<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * THE SUBJECTS SCREEN REACHES WHAT IT CALLS.
 *
 * Three things have to line up for a feature to exist for a school: the screen
 * calls an endpoint, the endpoint is routed, and the controller has the method.
 * Each can pass its own tests while the feature is dead.
 *
 * This module had a live example of the fourth failure — everything lined up and
 * the save still could not work, because it called through a dependency the
 * service never had. Reachability is necessary, not sufficient; the behavioural
 * tests are what prove the thing actually saves.
 */
final class SubjectAssignmentReachabilityTest extends TestCase
{
    private const CTRL   = __DIR__ . '/../../application/controllers/Academic.php';
    private const ROUTES = __DIR__ . '/../../application/config/routes.php';
    private const VIEW   = __DIR__ . '/../../application/views/academic/index.php';

    /** Endpoints the Subjects pane depends on. */
    private const ENDPOINTS = [
        'get_subject_assignments'             => 'view',
        'get_subject_assignments_for_section' => 'view',
        'save_subject_assignments'            => 'manage',
        'copy_subject_assignments'            => 'manage',
        'get_class_matrix'                    => 'view',
        'save_class_matrix'                   => 'manage',
        'get_all_teachers'                    => 'view',
    ];

    private function code(string $file): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($file)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { continue; }
                $out .= $t[1];
            } else { $out .= $t; }
        }
        return $out;
    }

    public function test_every_endpoint_exists_on_the_controller(): void
    {
        $ctrl = $this->code(self::CTRL);

        foreach (array_keys(self::ENDPOINTS) as $fn) {
            $this->assertMatchesRegularExpression(
                '~public function ' . preg_quote($fn, '~') . '\s*\(~', $ctrl,
                "the Subjects screen depends on academic/{$fn} and no such method exists");
        }
    }

    public function test_every_endpoint_is_explicitly_routed(): void
    {
        $routes = (string) file_get_contents(self::ROUTES);

        foreach (array_keys(self::ENDPOINTS) as $fn) {
            $this->assertMatchesRegularExpression(
                "~'academic/{$fn}'\s*\]\s*=\s*'Academic/{$fn}'~", $routes,
                "academic/{$fn} is not routed to its own method");
        }
    }

    /** Reading is not managing, and a hidden button is not security. */
    public function test_every_endpoint_asks_for_a_graded_level(): void
    {
        $ctrl = $this->code(self::CTRL);

        foreach (self::ENDPOINTS as $fn => $level) {
            $i = strpos($ctrl, 'function ' . $fn . '(');
            $this->assertNotFalse($i, "{$fn} is missing");
            $body = substr($ctrl, $i, 700);

            $this->assertMatchesRegularExpression(
                "~_require_role\(\[[^\]]*\],\s*'[a-z_]+',\s*'Academic',\s*'{$level}'\)~",
                $body,
                "{$fn} must ask for Academic/{$level} through the graded bridge, so a "
                . "school's own roles are honoured rather than only the built-in names");
        }
    }

    /**
     * Both write surfaces exist. The Subjects pane has two — the per-class
     * editor and the class matrix — and a fix applied to one of them leaves a
     * teacher's access reachable through the other.
     */
    public function test_both_write_surfaces_are_present_and_routed(): void
    {
        $ctrl   = $this->code(self::CTRL);
        $routes = (string) file_get_contents(self::ROUTES);

        foreach (['save_subject_assignments', 'save_class_matrix'] as $fn) {
            $this->assertStringContainsString('function ' . $fn . '(', $ctrl);
            $this->assertStringContainsString("'academic/{$fn}'", $routes);
        }
    }

    /** Everything the screen calls must be a method that exists. */
    public function test_the_screen_calls_nothing_that_is_missing(): void
    {
        $view = (string) file_get_contents(self::VIEW);
        $ctrl = $this->code(self::CTRL);

        preg_match_all("~post\('academic/([a-z_]+)'~", $view, $m);
        $called = array_values(array_unique($m[1]));
        $this->assertNotEmpty($called);

        foreach ($called as $fn) {
            $this->assertMatchesRegularExpression(
                '~public function ' . preg_quote($fn, '~') . '\s*\(~', $ctrl,
                "the screen calls academic/{$fn} and no such method exists");
        }
    }
}
