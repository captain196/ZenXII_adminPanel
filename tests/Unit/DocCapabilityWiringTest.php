<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The capability wiring for Documents — server grades, and the client agreeing.
 *
 * The module is gated by the RBAC key `Certificates` at three grades:
 * view < edit < manage. Renaming the module for users does NOT rename that key,
 * which is stored per-tenant in `schools.staffRoles` and mirrored in
 * functions/rbac_modules.json — changing it is a data migration across every
 * school, and this test exists partly to make that trade explicit.
 *
 * What is pinned here:
 *   1. every endpoint declares a grade — nothing is reachable ungated
 *   2. reads are `view`, design writes are `edit`, lifecycle acts are `manage`
 *   3. the client's rank order matches the server's
 */
class DocCapabilityWiringTest extends TestCase
{
    private static string $ctl;
    private static string $js;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        self::$ctl = (string) file_get_contents($root . '/application/controllers/Doc_templates.php');
        self::$js  = (string) file_get_contents($root . '/assets/js/doctemplates/designer.js');
    }

    /** @return array<string,string> method => grade */
    private function capabilities(): array
    {
        $i = strpos(self::$ctl, 'const CAPABILITIES');
        $this->assertNotFalse($i, 'CAPABILITIES table not found');
        $j = strpos(self::$ctl, '];', $i);
        preg_match_all("/'([a-z_]+)'\s*=>\s*'(view|edit|manage)'/", substr(self::$ctl, $i, $j - $i), $m, PREG_SET_ORDER);

        $out = [];
        foreach ($m as $r) {
            $out[$r[1]] = $r[2];
        }
        $this->assertNotEmpty($out, 'parsed zero capabilities — the parser is wrong, not the file');
        return $out;
    }

    /** Every public endpoint is declared. An undeclared one is refused by _remap, but silently. */
    public function test_every_public_endpoint_declares_a_grade(): void
    {
        preg_match_all('/^    public function ([a-z_][a-zA-Z0-9_]*)\(/m', self::$ctl, $m);
        $public = array_values(array_filter($m[1], fn($n) => $n !== '__construct' && $n !== '_remap'));
        $caps   = $this->capabilities();

        $missing = array_diff($public, array_keys($caps));
        $this->assertSame([], array_values($missing),
            'endpoint(s) with no declared capability: ' . implode(', ', $missing));
    }

    /** Lifecycle acts — the ones that freeze or expose a legal record — need manage. */
    public function test_lifecycle_actions_require_manage(): void
    {
        $caps = $this->capabilities();
        foreach (['publish', 'activate', 'deactivate', 'archive', 'delete'] as $m) {
            $this->assertSame('manage', $caps[$m] ?? null,
                "'$m' changes what a school issues and must require manage");
        }
    }

    /** Anything that writes needs at least edit — never view. */
    public function test_no_write_endpoint_is_reachable_at_view_grade(): void
    {
        $caps = $this->capabilities();
        foreach (['create', 'save', 'duplicate', 'upload_asset', 'save_block', 'seed_standard',
                  'proof_pdf', 'publish', 'activate', 'deactivate', 'archive', 'delete'] as $m) {
            $this->assertNotSame('view', $caps[$m] ?? null,
                "'$m' writes and must not be reachable at view grade");
        }
    }

    /**
     * A view grade must be able to READ — including opening a template.
     *
     * `design` was graded `edit`, so a view-grade user could not so much as look
     * at the certificate their school issues. A read grade that reads nothing is
     * not a grade.
     */
    public function test_a_view_grade_can_reach_every_read_path(): void
    {
        $caps = $this->capabilities();
        foreach (['index', 'gallery', 'design', 'get_types', 'get_templates',
                  'get_template', 'get_versions', 'version_pdf', 'get_blocks'] as $m) {
            $this->assertSame('view', $caps[$m] ?? null,
                "'$m' only reads and should be reachable at view grade");
        }
    }

    /** Client and server must agree on what outranks what. */
    public function test_the_client_rank_order_matches_the_server(): void
    {
        $this->assertMatchesRegularExpression(
            '/const RANK = \{\s*view:\s*1,\s*edit:\s*2,\s*manage:\s*3\s*\}/',
            self::$js,
            'the client rank table has drifted from rbac_level_rank()'
        );
    }

    /**
     * Hiding a control is presentation. Every write path must ALSO refuse.
     *
     * The client previously consulted its capability flags in exactly two places
     * while rendering Publish, Make live, Deactivate, Save and New document for
     * everybody — so a view-grade user could press them and get a generic
     * failure with no idea what was missing.
     */
    public function test_every_client_write_entry_point_refuses_before_acting(): void
    {
        foreach ([
            'saveNow'            => 'edit',
            'newCustomDocument'  => 'edit',
            'openStarter'        => 'edit',
            'proofOnServer'      => 'edit',
            'addObject'          => 'edit',
            'duplicateSel'       => 'edit',
            'tryDelete'          => 'edit',
            'openPublish'        => 'manage',
            'openActivate'       => 'manage',
        ] as $fn => $grade) {
            $at = strpos(self::$js, "function $fn(");
            $this->assertNotFalse($at, "client function $fn() not found");
            $body = substr(self::$js, $at, 400);
            $this->assertMatchesRegularExpression(
                '/refuse\("' . $grade . '"/', $body,
                "$fn() does not refuse below '$grade' before acting"
            );
        }
    }

    /** The history recorder is the backstop for any path nobody guarded. */
    public function test_the_undo_recorder_refuses_for_a_read_only_grade(): void
    {
        $at = strpos(self::$js, 'function push(label, before, after){');
        $this->assertNotFalse($at);
        $this->assertStringContainsString('readOnly()', substr(self::$js, $at, 600),
            'push() must refuse for a reader — it is the last line of defence when an '
            . 'entry point is added without a guard');
    }

    /**
     * The save endpoint must not swallow the service's rejection report.
     *
     * `Doc_template_service::save()` drops any field that is not an editable part of
     * the design and returns their names. The controller returned only the new
     * lockVersion, so that report died at the boundary and the client was answered
     * "saved" with no indication that part of its request had been discarded.
     * Found by live probe, not by review: the service was correct and the layer
     * above it silently undid the honesty.
     */
    public function test_the_save_endpoint_returns_the_rejected_field_names(): void
    {
        $at = strpos(self::$ctl, 'public function save(): void');
        $this->assertNotFalse($at);
        $body = substr(self::$ctl, $at, 2000);

        $this->assertStringContainsString("rejectedFields", $body,
            'the save endpoint drops the rejection report — the client is told "saved" '
            . 'while part of its payload was silently discarded');
    }
}
