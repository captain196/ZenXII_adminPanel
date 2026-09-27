<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Every RBAC refusal must record WHAT was refused.
 *
 * `MY_Controller::_require_role(array $allowed, string $action, ?string $module,
 * string $level)` logs `action=[...]` on denial. Payroll.php passed "" at all 13
 * call sites and Staff.php at 41 of 46, so a denial there was written to the log
 * as `action=[]` — a refusal recorded without the thing refused, which is the
 * one detail an investigation needs.
 *
 * RBAC itself was never affected: module and level were always supplied, and
 * `$action` only influences a decision for `$module === 'Transport'`
 * (LIFECYCLE_SAFETY_OPS). Every call site fixed here passes 'HR' or 'SIS', so
 * labelling them changed the audit trail and nothing else — checked before the
 * edit, not assumed.
 *
 * Recorded in ZENX_HRMS_P0_IMPLEMENTATION_REPORT.md §12 as an open P1 for
 * Payroll; the same defect was present in Staff.php at three times the scale.
 */
final class RbacDenialAuditLabelTest extends TestCase
{
    /** Controllers on the Staff Lifecycle surface. */
    // Staff.php is deliberately ABSENT. It carries the same defect at 41 call
    // sites, but 27 tests owned by other programmes (onboarding, separation,
    // transfer, employment history, clearance, rehire) pin the literal
    // `_require_role(self::X_ROLES, "", "SIS", ...)` signature. Labelling them
    // is a real improvement and a coordinated change across those suites — it
    // is reported, not smuggled in here. ZENX_HRMS_P0_IMPLEMENTATION_REPORT.md
    // §12 scopes this P1 to Payroll for the same reason.
    private const CONTROLLERS = [
        'Payroll', 'Hr', 'Compensation', 'Attendance',
    ];

    /**
     * @return array<string, string> controller => source
     */
    private static function sources(): array
    {
        $out = [];
        foreach (self::CONTROLLERS as $c) {
            $p = __DIR__ . '/../../application/controllers/' . $c . '.php';
            if (is_file($p)) { $out[$c] = (string) file_get_contents($p); }
        }

        return $out;
    }

    // ── The property ─────────────────────────────────────────────────────

    /** No refusal is logged without naming the action. */
    public function test_no_require_role_call_has_an_empty_action_label(): void
    {
        foreach (self::sources() as $name => $src) {
            // Both quote styles, tolerant of spacing.
            preg_match_all('~_require_role\([^;]*?,\s*(""|\x27\x27)\s*,~', $src, $m);

            $this->assertSame([], $m[0],
                "{$name}.php still refuses access without naming the action — "
                . 'the denial log reads action=[] and cannot be investigated');
        }
    }

    /**
     * The two payroll observability endpoints went through the legacy
     * name-gate with no module at all, so graded RBAC never applied to them
     * and a custom role could not be granted access however it was configured.
     */
    public function test_payroll_health_goes_through_graded_rbac(): void
    {
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');

        foreach (['payroll_health_page', 'payroll_health'] as $action) {
            $this->assertStringContainsString(
                "\$this->_require_role(self::ADMIN_ROLES, '{$action}', 'HR', 'manage');", $hr,
                "Hr::{$action} must pass a module and level, or it bypasses graded RBAC "
                . 'and blocks every custom role');
        }
    }

    /**
     * The labelling was only safe because none of these call sites gate on
     * Transport. If one ever does, `$action` stops being a label and starts
     * being a decision, and these call sites must be re-examined.
     */
    public function test_the_labelled_call_sites_do_not_gate_on_transport(): void
    {
        foreach (['Payroll'] as $name) {
            $src = (string) file_get_contents(
                __DIR__ . '/../../application/controllers/' . $name . '.php');

            preg_match_all('~_require_role\([^;]*?\)~', $src, $m);
            foreach ($m[0] as $call) {
                $this->assertStringNotContainsString("'Transport'", $call,
                    "{$name}.php now gates on Transport, where the action name is a "
                    . 'decision input (LIFECYCLE_SAFETY_OPS), not just an audit label');
                $this->assertStringNotContainsString('"Transport"', $call);
            }
        }
    }

    /** The label should be the method it guards — the convention elsewhere. */
    public function test_labels_name_the_method_they_guard(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../application/controllers/Payroll.php');
        $lines = preg_split('~\r?\n~', $src) ?: [];

        $current = '';
        $checked = 0;
        foreach ($lines as $line) {
            if (preg_match('~^\s*(?:public|private|protected)\s+function\s+([a-zA-Z_]\w*)~', $line, $mm)) {
                $current = $mm[1];
                continue;
            }
            if (strpos($line, '_require_role(') === false || strpos($line, '//') === 0) { continue; }
            if (!preg_match('~_require_role\([^,]+,\s*["\x27]([^"\x27]*)["\x27]~', $line, $am)) { continue; }

            $this->assertSame($current, $am[1],
                "a guard inside {$current}() is labelled '{$am[1]}'");
            $checked++;
        }

        $this->assertGreaterThanOrEqual(13, $checked, 'expected to check every Payroll guard');
    }
}
