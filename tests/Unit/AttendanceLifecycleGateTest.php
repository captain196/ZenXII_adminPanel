<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The tenant lifecycle gate must actually run for Attendance.
 *
 * THE DEFECT (found 2026-08-23, fixed here).
 *
 * MY_Controller::_require_role() carries the institution/commercial lifecycle
 * clause — the check that answers "may anyone act on this school right now",
 * as opposed to "may this actor". Its own docblock calls it the second
 * authorization entry point in the codebase, reached by 926 call sites.
 *
 * It sits behind `if ($module !== null)`, at the TOP of the base method.
 *
 * Attendance overrides _require_role(), and the override returns as soon as the
 * caller holds the Attendance module:
 *
 *     if (!$strict && has_permission('Attendance', $level)) return;   // ← base never runs
 *     if ($strict  && has_permission('Attendance', 'manage')) return; // ← base never runs
 *     ...
 *     if ($strict) parent::_require_role($allowed, $action);          // ← no module → clause skipped
 *
 * So for the ordinary case — a user who holds Attendance, which is everyone who
 * uses the module — the gate never executed. And the fifteen `strict` admin
 * sites delegated with no module at all, so it never executed for them either,
 * by either route. The whole Attendance module was outside the gate.
 *
 * What that allowed: a CLOSED institution (allowRead true, allowWrite false)
 * could still mark attendance, rewrite the attendance policy, rotate device API
 * keys, lock and unlock months, and approve regularizations that land on
 * payroll.
 *
 * LATENT, not live: it needs a tenant in a non-active state to bite. It is
 * still a hole in the authorization layer, and it is cheap to close.
 *
 * SCOPE. The identical shape exists in Sis.php's override and is NOT fixed
 * here — that controller carries admissions and student records, and widening
 * this repair into it is a separate release decision. Reported, not silently
 * left: see the audit report.
 */
final class AttendanceLifecycleGateTest extends TestCase
{
    private static string $att  = '';
    private static string $base = '';

    public static function setUpBeforeClass(): void
    {
        self::$att  = (string) file_get_contents(__DIR__ . '/../../application/controllers/Attendance.php');
        self::$base = (string) file_get_contents(__DIR__ . '/../../application/core/MY_Controller.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$att = '';
        self::$base = '';
    }

    /** The override's body, bounded at the next method. */
    private function overrideBody(): string
    {
        $i = strpos(self::$att, 'protected function _require_role');
        $this->assertNotFalse($i, 'Attendance still overrides the role gate');

        $j = strpos(self::$att, '    /** Month names', (int) $i);
        if ($j === false) {
            $j = strpos(self::$att, '    private function ', (int) $i + 40);
        }
        $this->assertNotFalse($j);

        $body = substr(self::$att, (int) $i, (int) $j - (int) $i);
        $this->assertStringContainsString('$strict', $body, 'slice sanity');
        return $body;
    }

    /**
     * The premise of the whole defect: the gate lives in the base, behind a
     * non-null module. If this ever stops being true the reasoning above needs
     * revisiting rather than the test being deleted.
     */
    public function test_the_base_gate_is_still_conditional_on_a_module(): void
    {
        $i = strpos(self::$base, 'protected function _require_role');
        $this->assertNotFalse($i);
        $head = substr(self::$base, (int) $i, 2000);

        $this->assertStringContainsString('if ($module !== null) {', $head);
        $this->assertStringContainsString('lifecycle_allows_operation($module, $level, $action)', $head);
    }

    /** The override now runs the gate. */
    public function test_the_override_runs_the_lifecycle_gate(): void
    {
        $body = $this->overrideBody();

        $this->assertStringContainsString('$this->_lifecycle_gate_or_deny(', $body,
            'the Attendance override must run the lifecycle gate itself, because '
            . 'it returns before the base method can');
    }

    /**
     * And runs it BEFORE the early returns — a gate placed after them would be
     * exactly as dead as the one it replaced.
     */
    public function test_the_gate_runs_before_every_early_return(): void
    {
        $body = $this->overrideBody();

        $gate = strpos($body, '$this->_lifecycle_gate_or_deny(');
        $this->assertNotFalse($gate);

        foreach (['if (!$strict && has_permission(', 'if ($strict && has_permission('] as $shortcut) {
            $at = strpos($body, $shortcut);
            $this->assertNotFalse($at, "expected shortcut missing: {$shortcut}");
            $this->assertLessThan($at, $gate,
                'the lifecycle gate must precede the permission shortcut, or a '
                . 'module holder skips straight past it');
        }
    }

    /** Strict admin sites are gated as WRITES, not as reads. */
    public function test_strict_admin_sites_are_gated_at_manage(): void
    {
        $body = $this->overrideBody();

        $this->assertStringContainsString(
            "\$this->_lifecycle_gate_or_deny(\$action, \$strict ? 'manage' : \$level);", $body,
            'settings, device-key rotation, month locks and regularization '
            . 'approvals are writes; gating them at view level would let a '
            . 'read-only tenant perform them');
    }

    /** The gate can only ever deny — it must not grant anything. */
    public function test_the_gate_is_deny_only(): void
    {
        $i = strpos(self::$att, 'private function _lifecycle_gate_or_deny');
        $this->assertNotFalse($i);
        $helper = substr(self::$att, (int) $i, 2200);

        $this->assertStringContainsString('if (!empty($lc[\'allowed\'])) {', $helper);
        $this->assertStringContainsString('return;', $helper);

        // It never touches permissions, roles or session state.
        foreach (['has_permission(', 'admin_role', '$allowed'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $helper,
                'the lifecycle gate must not participate in permission logic');
        }
    }

    /** A missing helper degrades to today's behaviour, never a hard failure. */
    public function test_a_missing_helper_does_not_break_the_panel(): void
    {
        $i = strpos(self::$att, 'private function _lifecycle_gate_or_deny');
        $helper = substr(self::$att, (int) $i, 2200);

        $this->assertStringContainsString("if (!function_exists('lifecycle_allows_operation')) {", $helper);
        $this->assertStringContainsString('@include_once APPPATH', $helper);
        $this->assertStringContainsString('return;   // helper absent', $helper,
            'an absent guard helper must fail open, exactly as the base does — '
            . 'a deploy ordering problem must not lock every school out of attendance');
    }

    /** A denial is logged with enough context to diagnose it. */
    public function test_a_denial_is_logged_with_its_context(): void
    {
        $i = strpos(self::$att, 'private function _lifecycle_gate_or_deny');
        $helper = substr(self::$att, (int) $i, 2200);

        $this->assertStringContainsString('LIFECYCLE_DENIED', $helper);
        foreach (['$level', '$action', 'admin_id', 'school_id'] as $field) {
            $this->assertStringContainsString($field, $helper);
        }
    }

    /** AJAX callers get a 403 JSON body, page loads get the 403 screen. */
    public function test_denial_matches_the_shape_the_caller_expects(): void
    {
        $i = strpos(self::$att, 'private function _lifecycle_gate_or_deny');
        $helper = substr(self::$att, (int) $i, 2200);

        $this->assertStringContainsString('$this->input->is_ajax_request()', $helper);
        $this->assertStringContainsString('$this->json_error($lc[\'message\'], 403)', $helper,
            'json_error() exits, so a denied AJAX call cannot fall through');
        $this->assertStringContainsString("show_error(\$lc['message'], 403", $helper);
    }

    /**
     * The graded-RBAC behaviour is UNCHANGED by this repair. `strict` remains a
     * deliberate sentinel meaning "no module shortcut at view/edit level", and a
     * custom role holding Attendance at manage still reaches the admin sites.
     */
    public function test_the_permission_semantics_are_untouched(): void
    {
        $body = $this->overrideBody();

        $this->assertStringContainsString("\$strict = (\$module === 'strict');", $body);
        $this->assertStringContainsString("if (!\$strict && has_permission('Attendance', \$level)) {", $body);
        $this->assertStringContainsString("if (\$strict && has_permission('Attendance', 'manage')) {", $body);
        $this->assertStringContainsString("parent::_require_role(\$allowed, \$action, 'Attendance', \$level);", $body);
    }

    /**
     * All fifteen strict call sites still pass the sentinel. This test exists
     * because an earlier pass of this audit misread `strict` as a bug and
     * started rewriting them into ('Attendance', 'manage') — which would have
     * dropped the hard name floor on device-key rotation and month locks.
     */
    public function test_the_strict_sentinel_sites_are_intact(): void
    {
        $n = preg_match_all("~_require_role\(self::[A-Z_]+, '[a-z_/]+', 'strict'\)~", self::$att);

        $this->assertSame(15, $n,
            'the strict sites are a deliberate hard floor for admin actions '
            . '(settings, policy, device keys, month locks, correction and '
            . 'regularization approvals) — not a defect to be refactored away');
    }
}
