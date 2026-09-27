<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * THE PLANNER REACHES WHAT IT CALLS.
 *
 * Four things have to line up for a feature to exist for a school: the screen
 * calls an endpoint, the endpoint is routed, the controller has the method, and
 * the method asks for a graded permission so a school's own roles are honoured.
 * Each can pass its own tests while the feature is dead.
 *
 * This module supplied the newest example of the fourth kind of failure: the
 * audit entries were written, routed and permissioned, and refused at the last
 * step by an allowlist nobody read the return value of.
 */
final class LessonPlanReachabilityTest extends TestCase
{
    private const CTRL   = __DIR__ . '/../../application/controllers/Academic.php';
    private const ROUTES = __DIR__ . '/../../application/config/routes.php';
    private const VIEW   = __DIR__ . '/../../application/views/academic/index.php';

    /** Endpoints the Lesson Planner depends on, and the level each must ask for. */
    private const ENDPOINTS = [
        'get_daily_plan'        => 'view',
        'get_lesson_plan'       => 'view',
        'get_monthly_plan'      => 'view',
        'save_lesson_plan'      => 'edit',
        'lesson_plan_history'   => 'view',
        'get_master_timetable'  => 'view',
        'get_curriculum'        => 'view',
        'get_all_teachers'      => 'view',
    ];

    /** The analytics and parent-facing surfaces fed by lesson plans. */
    private const ANALYTICS = [
        'analytics_syllabus_progress',
        'analytics_daily_monitoring',
        'analytics_delays',
        'analytics_subject_progress',
        'parent_daily_lessons',
        'parent_subject_progress',
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

        foreach (array_merge(array_keys(self::ENDPOINTS), self::ANALYTICS) as $fn) {
            $this->assertMatchesRegularExpression(
                '~public function ' . preg_quote($fn, '~') . '\s*\(~', $ctrl,
                "the planner depends on academic/{$fn} and no such method exists");
        }
    }

    public function test_every_endpoint_is_explicitly_routed(): void
    {
        $routes = (string) file_get_contents(self::ROUTES);

        foreach (array_merge(array_keys(self::ENDPOINTS), self::ANALYTICS) as $fn) {
            $this->assertMatchesRegularExpression(
                "~'academic/{$fn}'\s*\]\s*=\s*'Academic/{$fn}'~", $routes,
                "academic/{$fn} is not routed to its own method");
        }
    }

    /** Reading is not editing, and a hidden button is not security. */
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

    /**
     * The history the module now writes has to be reachable from the screen
     * that raises the question. A log with no reader is what the substitute
     * module was found with, and this one was worse.
     */
    public function test_the_planner_screen_can_read_a_plans_history(): void
    {
        $view = (string) file_get_contents(self::VIEW);

        $this->assertStringContainsString("post('academic/lesson_plan_history'", $view,
            'the endpoint exists but nothing on the screen calls it');
        $this->assertMatchesRegularExpression('~AC\.lp\.history\(~', $view,
            'and a row must offer it');
    }

    /**
     * The entity type the service audits under must be one the audit service
     * accepts. log() returns false for anything else and every caller in this
     * codebase discards that return.
     */
    public function test_the_audited_entity_type_is_accepted(): void
    {
        require_once __DIR__ . '/../../application/libraries/Audit_log_service.php';

        $svc = $this->code(__DIR__ . '/../../application/libraries/Lesson_plan_service.php');
        preg_match_all("~_audit\(\s*[^,]+,\s*'([a-zA-Z]+)'~", $svc, $m);

        $this->assertNotEmpty($m[1], 'the service audits nothing at all');
        foreach (array_unique($m[1]) as $entityType) {
            $this->assertContains($entityType, Audit_log_service::ENTITY_TYPES,
                "Lesson_plan_service audits '{$entityType}', which the audit service "
                . 'silently refuses');
        }
    }

    /** The actions it uses must be accepted too — the same allowlist trap. */
    public function test_the_audited_actions_are_accepted(): void
    {
        require_once __DIR__ . '/../../application/libraries/Audit_log_service.php';

        $svc = $this->code(__DIR__ . '/../../application/libraries/Lesson_plan_service.php');
        // The action is not always a literal — a save logs
        // `$isNew ? 'create' : 'update'` — so every quoted word in the
        // first argument is collected rather than just the first one.
        preg_match_all("~_audit\(([^,]*(?:,[^,]*)??),\s*'[a-zA-Z]+'~", $svc, $m);
        $actions = [];
        foreach ($m[1] as $expr) {
            if (preg_match_all("~'([a-z_]+)'~", $expr, $lit)) {
                $actions = array_merge($actions, $lit[1]);
            }
        }
        $this->assertNotEmpty($actions, 'no audited action could be read');

        foreach (array_unique($actions) as $action) {
            $this->assertContains($action, Audit_log_service::ACTIONS,
                "Lesson_plan_service logs action '{$action}', which is not on the allowlist");
        }
    }
}
