<?php
/**
 * WHO MAY DO WHAT, AND WHAT IS WRITTEN DOWN AFTERWARDS.
 *
 * Two audit questions that no unit test reaches, because both live at the
 * controller boundary:
 *
 *   Permissions. Every substitute endpoint has to go through the graded RBAC
 *   bridge with a module and a level. A hidden button is not security: an
 *   endpoint that checks nothing is reachable by anybody who can type a URL.
 *
 *   Auditability. Cover is a record of who was asked to stand in front of a
 *   class. Every change to it, and every change to the rules governing it,
 *   has to leave a trace naming the person who made it.
 */

use PHPUnit\Framework\TestCase;

final class SubstitutePermissionAndAuditTest extends TestCase
{
    private const CTRL = __DIR__ . '/../../application/controllers/Academic.php';
    private const SVC  = __DIR__ . '/../../application/libraries/Substitute_service.php';

    private function code(string $file): string
    {
        $src = (string) file_get_contents($file);
        $out = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { continue; }
                $out .= $t[1];
            } else { $out .= $t; }
        }
        return $out;
    }

    /** The substitute surface, and the level each endpoint must demand. */
    private const ENDPOINTS = [
        'get_substitutes'              => 'view',
        'get_cover_policy'             => 'view',
        'cover_log'                    => 'view',
        'cover_history'                => 'view',
        'keep_cover'                   => 'manage',
        'get_absent_teachers'          => 'manage',
        'get_absent_teacher_schedule'  => 'manage',
        'save_substitute'              => 'manage',
        'update_substitute'            => 'manage',
        'delete_substitute'            => 'manage',
        'save_cover_policy'            => 'manage',
    ];

    public function test_every_endpoint_goes_through_the_authorization_bridge(): void
    {
        $code = $this->code(self::CTRL);

        foreach (self::ENDPOINTS as $fn => $level) {
            $i = strpos($code, 'function ' . $fn . '(');
            $this->assertNotFalse($i, "{$fn} is missing");

            $body = substr($code, $i, 700);
            $this->assertStringContainsString('_require_role', $body,
                "{$fn} authorizes nobody — a hidden button is not security, and this "
                . 'endpoint is reachable by anybody who can type a URL');
        }
    }

    /**
     * The bridge is graded: it takes a module and a level, and consults
     * has_permission() before falling back to the legacy name list. An endpoint
     * that passes no module gets the name gate alone, which blocks every custom
     * role a school has built.
     */
    public function test_every_endpoint_asks_for_a_graded_level_not_a_name_list(): void
    {
        $code = $this->code(self::CTRL);

        foreach (self::ENDPOINTS as $fn => $level) {
            $i = strpos($code, 'function ' . $fn . '(');
            $body = substr($code, $i, 700);

            $this->assertMatchesRegularExpression(
                "~_require_role\(\[[^\]]*\],\s*'[a-z_]+',\s*'Academic',\s*'{$level}'\)~",
                $body,
                "{$fn} must ask for Academic/{$level} through the graded bridge, so a "
                . "school's own roles are honoured rather than only the built-in names");
        }
    }

    /** Reading is not managing: the two must not collapse into one level. */
    public function test_reading_cover_does_not_require_permission_to_change_it(): void
    {
        $code = $this->code(self::CTRL);

        foreach (['get_substitutes', 'cover_log', 'get_cover_policy',
                  'cover_history'] as $fn) {
            $i = strpos($code, 'function ' . $fn . '(');
            $body = substr($code, $i, 700);
            $this->assertStringContainsString("'Academic', 'view'", $body,
                "{$fn} only reads, and demanding manage would hide the cover from the "
                . 'people who have to work around it');
        }
    }

    /**
     * Setting the rules is a leadership act, not a clerical one. The person who
     * arranges cover day to day should not be able to raise the ceiling they are
     * being held to.
     */
    public function test_setting_the_policy_is_not_open_to_whoever_arranges_cover(): void
    {
        $code = $this->code(self::CTRL);

        $i = strpos($code, 'function save_cover_policy(');
        $this->assertNotFalse($i);
        $body = substr($code, $i, 700);

        $this->assertStringNotContainsString('Academic Coordinator', $body,
            'raising your own cover limit is not part of arranging cover');
        $this->assertStringContainsString('Principal', $body);
    }

    // -- what is written down ---------------------------------------------

    public function test_every_change_to_cover_leaves_a_trace(): void
    {
        $code = $this->code(self::SVC);

        // create / update, status change, delete, and the policy itself.
        foreach ([
            "_audit(\n                is_array(\$current) ? 'update' : 'create'" => 'saving cover',
            "'status_change'"      => 'changing a status',
            "_audit('delete'"      => 'removing cover',
            "'substitutePolicy'"   => 'changing the rules',
        ] as $needle => $what) {
            // Both sides are flattened, carriage returns included: these files
            // are CRLF, and a needle that only drops "\n" never matches.
            $flat = static function (string $x): string {
                return str_replace(["\r", "\n", ' '], '', $x);
            };

            $this->assertStringContainsString(
                $flat($needle),
                $flat($code),
                "{$what} leaves no trace, and cover is a record of who was asked to "
                . 'stand in front of a class');
        }
    }

    /**
     * An audit entry that fails must not take the operation down with it, and
     * must not vanish silently either.
     */
    public function test_a_failed_audit_is_logged_rather_than_swallowed(): void
    {
        $code = $this->code(self::SVC);

        $i = strpos($code, 'function _audit(');
        $this->assertNotFalse($i);
        $body = substr($code, $i, 500);

        $this->assertStringContainsString('log_message', $body,
            'an audit entry nobody wrote is at least recorded as missing');
        $this->assertDoesNotMatchRegularExpression('~catch\s*\([^)]*\)\s*\{\s*\}~', $body,
            'an empty catch here loses the trace and says nothing');
    }

    /**
     * The status-change trace has to name the status it REPLACED. "Set to
     * cancelled" answers nothing on its own; "assigned → cancelled" is the
     * fact somebody will need.
     */
    public function test_the_status_trace_records_what_it_replaced(): void
    {
        $code = $this->code(self::SVC);

        $i = strpos($code, "'status_change'");
        $this->assertNotFalse($i);
        $body = substr($code, $i, 300);

        $this->assertMatchesRegularExpression("~\['status' => \\\$was\]~", $body,
            'the previous status is the half that makes the entry useful');
    }
}
