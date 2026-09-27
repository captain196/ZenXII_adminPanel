<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Consolidated Staff HR reconciliation audit (2026-08-19) — DEFECT FIX.
 *
 * Payroll classifies each employee's salary as a Teaching (GL 5010) or
 * Non-Teaching (GL 5020) expense. The primary signal is `staff_roles` +
 * the school's role catalogue; when an employee has no roles, or the role
 * defs carry no category, both payroll writers fall back to matching text
 * in Position and Department.
 *
 * That fallback read the LIVE profile values, which is wrong for a
 * historical period and — critically — was INCONSISTENT with a fix that
 * already existed in the same functions:
 *
 *   generate_payroll()          — Trail 2 Phase J already resolved
 *                                 $department as of the period start, but
 *                                 $position stayed live. One boolean
 *                                 expression, two temporal semantics.
 *
 *   regenerate_staff_payroll()  — WORSE. It resolved the period-effective
 *                                 $department at ~line 11340, then the
 *                                 classification ~400 lines later re-read
 *                                 $profile['Department'] RAW, silently
 *                                 bypassing its own fix. Regeneration is
 *                                 precisely the "edit today, recompute a
 *                                 past period" scenario Phase J existed to
 *                                 protect, and this path defeated it.
 *
 * Consequence: regenerating a past month for someone who had since been
 * transferred or promoted booked that month's adjustment journal to the
 * wrong expense account. Net pay is unaffected — this is a GL
 * classification defect, not a formula defect.
 *
 * The fix changes only WHEN the inputs are read, never the classification
 * rule or the account codes. 'position' was already in the tracked
 * Staff_employment_history_service::ATTRIBUTES vocabulary, so no schema
 * change was required.
 *
 * KNOWN REMAINING LIMITATION (documented, deliberately NOT fixed here):
 * the PRIMARY classification path reads `staff_roles` live. `staff_roles`
 * is NOT in the timeline ATTRIBUTES vocabulary, so resolving it
 * historically would require extending that locked vocabulary — a
 * data-model decision that is out of scope for an autonomous correctness
 * fix. See test_staff_roles_primary_path_is_still_live_and_documented().
 *
 * Source-regression tests (Firestore_service has no behavioral mock).
 */
class PayrollExpenseClassificationEffectiveDatingTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($raw);
        $out = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        $this->src = $out;
    }

    private function extractFunctionBody(string $src, int $offset): string
    {
        $openBrace = strpos($src, '{', $offset);
        $this->assertNotFalse($openBrace);
        $depth = 0;
        $len = strlen($src);
        for ($i = $openBrace; $i < $len; $i++) {
            if ($src[$i] === '{') $depth++;
            elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) return substr($src, $openBrace, $i - $openBrace + 1);
            }
        }
        $this->fail('Unbalanced braces.');
    }

    private function fn(string $name): string
    {
        $start = strpos($this->src, 'function ' . $name . '(');
        $this->assertNotFalse($start, "{$name}() must exist.");
        return $this->extractFunctionBody($this->src, $start);
    }

    // ── generate_payroll() ────────────────────────────────────────────

    public function test_generate_payroll_resolves_position_as_of_period_start(): void
    {
        $body = $this->fn('generate_payroll');
        $this->assertStringContainsString(
            "resolveAttributeAsOf(\$staffId, 'position', \$periodStart)", $body,
            'position must be resolved for the payroll period, not read live.');
    }

    public function test_generate_payroll_still_resolves_department_as_of_period_start(): void
    {
        // Guards the pre-existing Phase J behaviour against regression.
        $body = $this->fn('generate_payroll');
        $this->assertStringContainsString(
            "resolveAttributeAsOf(\$staffId, 'department', \$periodStart)", $body);
    }

    public function test_generate_payroll_classification_uses_both_resolved_variables(): void
    {
        $body = $this->fn('generate_payroll');
        // Both sides of the boolean now read the resolved locals, not $profile.
        $this->assertStringContainsString("stripos(\$position, 'teacher')", $body);
        $this->assertStringContainsString("stripos(\$position, 'lecturer')", $body);
        $this->assertStringContainsString("stripos(\$department, 'teaching')", $body);
    }

    public function test_generate_payroll_position_resolution_fails_open_to_live(): void
    {
        $body = $this->fn('generate_payroll');
        $this->assertStringContainsString(
            "if (\$histPos !== null && \$histPos !== '') \$position = \$histPos;", $body,
            'a null/empty history result must leave the live value in place — never blank the position.');
        $this->assertStringContainsString('} catch (\Throwable $e) {', $body);
    }

    // ── regenerate_staff_payroll() ────────────────────────────────────

    public function test_regenerate_classification_no_longer_rereads_raw_profile_department(): void
    {
        // THE CORE DEFECT: this exact expression previously bypassed the
        // period-effective $department the function had already computed.
        $body = $this->fn('regenerate_staff_payroll');
        $this->assertStringNotContainsString("\$_dep = (string) (\$profile['Department'] ?? '');", $body,
            'the classification must not re-read the live department — it defeats the Phase J resolution above it.');
        $this->assertStringContainsString('$_dep = $department;', $body,
            'it must reuse the already-resolved period-effective department.');
    }

    public function test_regenerate_resolves_position_as_of_period_start(): void
    {
        $body = $this->fn('regenerate_staff_payroll');
        $this->assertStringContainsString("resolveAttributeAsOf(", $body);
        $this->assertStringContainsString("'position',", $body);
    }

    public function test_regenerate_position_resolution_fails_open_to_live(): void
    {
        $body = $this->fn('regenerate_staff_payroll');
        $this->assertStringContainsString(
            "if (\$histPos !== null && \$histPos !== '') \$_pos = \$histPos;", $body);
        $this->assertStringContainsString('} catch (\Throwable $e) {', $body);
    }

    public function test_regenerate_still_resolves_department_for_working_days(): void
    {
        // The original Phase J fix in this function must remain intact.
        $body = $this->fn('regenerate_staff_payroll');
        $this->assertStringContainsString(
            "resolveAttributeAsOf(\$staffId, 'department', \$periodStart)", $body);
    }

    // ── The rule and the account codes are UNCHANGED ──────────────────

    public function test_expense_account_codes_are_untouched(): void
    {
        // Only the DATE at which inputs are read changed. Changing a GL
        // account code would be inventing financial policy.
        $this->assertStringContainsString("\$expenseAcct = \$isTeaching ? '5010' : '5020';", $this->src);
    }

    public function test_classification_keywords_are_untouched(): void
    {
        // 'teacher' / 'lecturer' / 'teaching' — the rule itself is not ours
        // to change during a correctness fix.
        $this->assertSame(2, substr_count($this->src, "stripos(\$position, 'teacher')")
                            + substr_count($this->src, "stripos(\$_pos, 'teacher')"),
            'exactly two payroll writers classify, each with the same keyword rule.');
    }

    public function test_no_payroll_formula_was_altered(): void
    {
        // The fix must not touch gross/net/deduction arithmetic. These
        // anchors are the delta-journal invariants from the regeneration
        // path; their presence proves the surrounding maths is intact.
        $body = $this->fn('regenerate_staff_payroll');
        $this->assertStringContainsString('$dGross    = round($gross', $body);
        $this->assertStringContainsString('$dNet      = round($netPay', $body);
        $this->assertStringContainsString('$hasDelta = (abs($dGross) >= 0.005)', $body);
    }

    // ── Documented remaining limitation ───────────────────────────────

    public function test_staff_roles_primary_path_is_still_live_and_documented(): void
    {
        // Honest limitation marker: the PRIMARY signal (staff_roles) is
        // still read live in both writers. staff_roles is not a timeline
        // attribute, so historical resolution would require extending the
        // locked ATTRIBUTES vocabulary — a data-model decision, not an
        // autonomous correctness fix. If someone later adds it to the
        // vocabulary, this test should be revisited deliberately.
        $svc = file_get_contents(__DIR__ . '/../../application/libraries/Staff_employment_history_service.php');
        $this->assertStringContainsString(
            "public const ATTRIBUTES = ['position', 'department', 'employmentType', 'reportingManagerId', 'employmentStatus'];",
            $svc,
            'if this vocabulary changed, re-evaluate whether staff_roles can now be resolved historically.');
        $this->assertStringNotContainsString("'staff_roles'", $svc);

        // And both writers do still read it live — stated, not hidden.
        $this->assertStringContainsString("\$staffRoles = \$profile['staff_roles'] ?? [];", $this->src);
    }
}
