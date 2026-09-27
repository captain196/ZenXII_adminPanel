<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Endpoints that existed with no way in.
 *
 * This programme kept finding the same shape: a complete, guarded, tested
 * capability that no screen could reach. A repository sweep across the Staff
 * Lifecycle controllers (routes x controller methods x view references) turned
 * up two more:
 *
 *   PAYROLL-HEALTH-1  `Hr::payroll_health_page` renders a real observability
 *                     page from a real view, and had no route and no link. It
 *                     also went through the legacy name-gate with no module,
 *                     so graded RBAC never applied and no custom role could be
 *                     granted it however it was configured.
 *
 *   COMP-OFF-1        `Hr::earn_comp_off` and `Hr::get_comp_off_ledger` are
 *                     complete and idempotent, `_consumeCompOffLedger` already
 *                     spends credits, a COMP_OFF leave type is seeded and an
 *                     expiry policy card shipped on 2026-08-22 — but nothing
 *                     called either endpoint. Comp-Off could be configured and
 *                     consumed, and never earned.
 *
 * Both were wired to their existing screens. Nothing new was built.
 *
 * The sweep that found them lives in the programme notes; these tests hold the
 * result so the way in cannot quietly disappear again.
 */
final class LifecycleEndpointReachabilityTest extends TestCase
{
    private static string $routes = '';
    private static string $hrView = '';
    private static string $hr     = '';

    public static function setUpBeforeClass(): void
    {
        $root = __DIR__ . '/../../';
        self::$routes = (string) file_get_contents($root . 'application/config/routes.php');
        self::$hrView = (string) file_get_contents($root . 'application/views/hr/index.php');
        self::$hr     = (string) file_get_contents($root . 'application/controllers/Hr.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$routes = self::$hrView = self::$hr = '';
    }

    /**
     * Route + method + screen, for each endpoint. A route alone is not
     * reachability: `/compensation/fnf` had a controller and no route and 404'd,
     * and this page had a view and no link, which is the same failure wearing
     * different clothes.
     *
     * @dataProvider wiredEndpoints
     */
    public function test_the_endpoint_is_reachable(string $route, string $target, string $method): void
    {
        $this->assertStringContainsString("\$route['{$route}']", self::$routes,
            "{$route} has no declared route");

        $this->assertStringContainsString("= '{$target}';", self::$routes,
            "{$route} does not resolve to {$target}");

        $this->assertStringContainsString("public function {$method}(", self::$hr,
            "Hr::{$method}() does not exist, so the route points at nothing");
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function wiredEndpoints(): array
    {
        return [
            'payroll health page' => ['hr/payroll_health_page', 'Hr/payroll_health_page', 'payroll_health_page'],
            'payroll health data' => ['hr/payroll_health',      'Hr/payroll_health',      'payroll_health'],
            'comp-off earn'       => ['hr/earn_comp_off',       'Hr/earn_comp_off',       'earn_comp_off'],
            'comp-off ledger'     => ['hr/get_comp_off_ledger', 'Hr/get_comp_off_ledger', 'get_comp_off_ledger'],
        ];
    }

    // ── The screen actually offers them ──────────────────────────────────

    /** Payroll Health is linked from the HR screen, not just addressable. */
    public function test_payroll_health_is_linked_from_the_hr_screen(): void
    {
        $this->assertStringContainsString("base_url('hr/payroll_health_page')", self::$hrView,
            'the page is addressable but nothing links to it — the defect that was fixed');
    }

    /** Comp-Off has a tab, a panel, and controls that call the endpoints. */
    public function test_comp_off_has_a_screen(): void
    {
        $this->assertStringContainsString('data-sub="compOff"', self::$hrView, 'no sub-tab');
        $this->assertStringContainsString('id="subCompOff"', self::$hrView, 'no panel');

        $this->assertStringContainsString("post('hr/earn_comp_off'", self::$hrView,
            'nothing on the screen credits a Comp-Off');
        $this->assertStringContainsString("getJSON('hr/get_comp_off_ledger?staff_id='", self::$hrView,
            'nothing on the screen reads the ledger');

        // Reachable from the namespace the buttons call through.
        foreach (['creditCompOff:', 'loadCompOffLedger:'] as $exported) {
            $this->assertStringContainsString($exported, self::$hrView,
                'the handler is not exported on HR, so the onclick would throw');
        }
    }

    /**
     * The screen reuses the HR screen's own helpers. A second transport here
     * would drift from the first on CSRF refresh and error reporting.
     */
    public function test_comp_off_reuses_the_screens_own_transport(): void
    {
        $i = strpos(self::$hrView, 'function creditCompOff()');
        $this->assertNotFalse($i);
        $body = substr(self::$hrView, (int) $i, 900);

        $this->assertStringContainsString("post('hr/earn_comp_off'", $body);
        $this->assertStringNotContainsString('fetch(', $body,
            'the HR screen speaks jQuery; a stray fetch would bypass its CSRF refresh');
        $this->assertStringContainsString('_coBusy', $body,
            'without a busy guard a double click sends two credits');
    }

    /** Only classes this stylesheet defines — an invented one renders unstyled. */
    public function test_comp_off_uses_only_real_css_classes(): void
    {
        $i = strpos(self::$hrView, 'id="subCompOff"');
        $panel = substr(self::$hrView, (int) $i, 1800);

        preg_match_all('~class="([a-z0-9 _-]+)"~i', $panel, $m);
        $used = [];
        foreach ($m[1] as $attr) {
            foreach (preg_split('~\s+~', trim($attr)) as $cls) {
                if ($cls !== '' && strpos($cls, 'hr-') === 0) { $used[$cls] = true; }
            }
        }
        $this->assertNotEmpty($used, 'the panel should carry the screen\'s own classes');

        foreach (array_keys($used) as $cls) {
            $this->assertMatchesRegularExpression(
                '~\.' . preg_quote($cls, '~') . '[{,: ]~', self::$hrView,
                "{$cls} is not defined in this screen's stylesheet");
        }
    }
}
