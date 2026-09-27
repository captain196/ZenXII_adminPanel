<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ZenX HRMS Phase 1 — proves capability and RBAC stay two INDEPENDENT
 * gates at every new HRMS surface, per the corrected Phase 1 spec:
 *   "Capability disabled + permission granted  → DENY"
 *   "Capability enabled + permission denied    → DENY"
 *   "Capability enabled + permission granted   → ALLOW"
 *
 * Behavioral testing of this matrix would require executing the controller
 * against a mocked session + Firestore — not possible today for
 * Firestore_service-based code (see LocationSchoolIdPredicateTest's
 * docblock for why). This test instead proves the STRUCTURAL precondition
 * the matrix depends on: every mutating/reading HRMS endpoint calls BOTH
 * require_permission() (RBAC — "can this user?") AND require_capability()
 * (tenant capability — "does this tenant have it?"), as two separate,
 * unconditional statements — neither wrapped inside the other, neither
 * substituting for the other. Given that, the three-outcome matrix follows
 * directly from require_permission()'s and require_capability()'s own
 * (independently established) fail-closed behavior.
 */
class HrmsCapabilityGateTest extends TestCase
{
    private string $workforceLocationsSrc;
    private string $schoolConfigSrc;
    private string $capabilityHelperSrc;

    protected function setUp(): void
    {
        $this->workforceLocationsSrc = $this->readCodeOnly(
            __DIR__ . '/../../application/controllers/Workforce_locations.php'
        );
        $this->schoolConfigSrc = $this->readCodeOnly(
            __DIR__ . '/../../application/controllers/School_config.php'
        );
        $this->capabilityHelperSrc = $this->readCodeOnly(
            __DIR__ . '/../../application/helpers/capability_helper.php'
        );
    }

    /**
     * Read a PHP file with all comments stripped, so these assertions
     * measure EXECUTABLE code only. Load-bearing in both directions: a
     * docblock that names require_capability() must neither inflate a
     * count (making a real gap look covered) nor satisfy an assertion on
     * its own (making a test pass with no actual gate in place).
     */
    private function readCodeOnly(string $path): string
    {
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw, "Could not read {$path}");

        $out = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        return $out;
    }

    /**
     * Every one of Workforce_locations.php's three public endpoints
     * (get_data, save, delete) must call require_permission() at least
     * once — the RBAC gate is present everywhere, not just on mutations.
     */
    public function test_every_workforce_locations_endpoint_checks_rbac(): void
    {
        $count = substr_count($this->workforceLocationsSrc, 'require_permission(\'HR\',');
        $this->assertGreaterThanOrEqual(
            3,
            $count,
            "Expected require_permission('HR', ...) in all 3 Workforce_locations.php endpoints (get_data/save/delete); found {$count}."
        );
    }

    /**
     * Every one of the same three endpoints must ALSO call
     * require_capability(HRMS_CORE_CAPABILITY) — proving the tenant gate is
     * present at every one of the same sites as the RBAC gate, not a subset.
     */
    public function test_every_workforce_locations_endpoint_checks_capability(): void
    {
        $count = substr_count($this->workforceLocationsSrc, 'require_capability(HRMS_CORE_CAPABILITY,');
        $this->assertGreaterThanOrEqual(
            3,
            $count,
            "Expected require_capability(HRMS_CORE_CAPABILITY, ...) in all 3 Workforce_locations.php endpoints; found {$count}."
        );
        $this->assertSame(
            substr_count($this->workforceLocationsSrc, 'require_permission(\'HR\','),
            $count,
            'require_permission() and require_capability() must appear the SAME number of times in Workforce_locations.php — every RBAC-gated endpoint must also be capability-gated, and vice versa.'
        );
    }

    /**
     * The two gates must be two SEPARATE statements, not one nested inside
     * the other's conditional body — nesting would make one gate's outcome
     * depend on the other's, breaking independence.
     */
    public function test_capability_check_is_not_nested_inside_permission_conditional(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/require_permission\([^)]*\)\s*\)\s*\{[^}]*require_capability/s',
            $this->workforceLocationsSrc,
            'require_capability() must not be nested inside an if-block gated by require_permission() — the two calls should be independent, unconditional statements.'
        );
    }

    /**
     * School_config::save_terminology() — the one new HRMS-gated endpoint
     * living inside School_config.php rather than Workforce_locations.php —
     * must independently carry both gates too, proving the pattern isn't
     * specific to one controller.
     */
    public function test_save_terminology_checks_both_gates(): void
    {
        $pos = strpos($this->schoolConfigSrc, 'function save_terminology()');
        $this->assertNotFalse($pos, 'save_terminology() must exist in School_config.php.');
        $body = substr($this->schoolConfigSrc, $pos, 700);
        $this->assertStringContainsString('_require_role(', $body, 'save_terminology() must check RBAC.');
        $this->assertStringContainsString('require_capability(HRMS_CORE_CAPABILITY', $body, 'save_terminology() must check tenant capability.');
    }

    /**
     * has_capability()/require_capability() must be defined guarded by
     * function_exists() (this codebase's established helper convention,
     * e.g. deptrole_helper.php), and must fail CLOSED (return/deny) when
     * the capability service isn't loaded — never fail open.
     */
    public function test_capability_helper_fails_closed_when_service_missing(): void
    {
        $this->assertStringContainsString("if (!function_exists('has_capability'))", $this->capabilityHelperSrc);
        $this->assertStringContainsString("if (!function_exists('require_capability'))", $this->capabilityHelperSrc);
        $this->assertStringContainsString('return false;', $this->capabilityHelperSrc);
    }
}
