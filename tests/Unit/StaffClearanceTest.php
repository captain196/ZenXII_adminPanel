<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Staff HR Advanced Capability Programme — Phase 3 (Clearance / Handover),
 * 2026-08-18.
 *
 * Confirmed green-field before implementation: no clearance/handover/
 * asset-return/NOC concept existed anywhere else in this codebase, HR or
 * otherwise, to reuse or risk duplicating. New collection
 * `staffClearances/{schoolId}_{staffId}`.
 *
 * Department list is per-tenant configurable (schools.clearanceDepartments),
 * never a hardcoded assumption that every school needs the same nine.
 *
 * Explicitly does NOT create any Payroll deduction when a department is
 * flagged — that stays a separate, not-yet-authorized financial-policy
 * decision.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller code).
 */
class StaffClearanceTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../application/controllers/Staff.php');
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

    private function extractFunctionBody(string $src, int $functionKeywordOffset): string
    {
        $openBrace = strpos($src, '{', $functionKeywordOffset);
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
        $this->fail('Unbalanced braces while extracting function body.');
    }

    private function functionBody(string $needle): string
    {
        $start = strpos($this->src, $needle);
        $this->assertNotFalse($start, "Expected to find: {$needle}");
        return $this->extractFunctionBody($this->src, $start);
    }

    public function test_all_endpoints_gated_by_existing_sis_module_roles(): void
    {
        foreach ([
            ['get_clearance(', 'VIEW_ROLES', 'view'],
            ['initiate_clearance(', 'MANAGE_ROLES', 'manage'],
            ['update_clearance_department(', 'MANAGE_ROLES', 'manage'],
        ] as [$fn, $roles, $level]) {
            $body = $this->functionBody("public function {$fn}");
            $this->assertStringContainsString("_require_role(self::{$roles}, \"\", \"SIS\", \"{$level}\")", $body);
        }
    }

    public function test_department_list_is_tenant_configurable_not_hardcoded(): void
    {
        $body = $this->functionBody('private function _clearance_departments(');
        $this->assertStringContainsString("\$school['clearanceDepartments'] ?? null", $body);
        $this->assertStringContainsString('return self::CLEARANCE_DEFAULT_DEPARTMENTS;', $body);
    }

    public function test_never_creates_a_payroll_deduction(): void
    {
        foreach (['initiate_clearance(', 'update_clearance_department('] as $fn) {
            $body = $this->functionBody("public function {$fn}");
            $lower = strtolower($body);
            foreach (['payroll', 'deduct', 'salary'] as $term) {
                $this->assertStringNotContainsString($term, $lower,
                    "'{$term}' must never appear — a flagged department blocks THIS workflow's completion only, no financial consequence is coded.");
            }
        }
    }

    public function test_refuses_a_second_concurrent_clearance_process(): void
    {
        $body = $this->functionBody('public function initiate_clearance(');
        $this->assertStringContainsString("(string) (\$existing['status'] ?? '') === 'in_progress'", $body);
    }

    public function test_seeds_every_configured_department_as_pending(): void
    {
        $body = $this->functionBody('public function initiate_clearance(');
        $this->assertStringContainsString(
            "\$deptState[\$dept] = ['status' => 'pending', 'note' => '', 'decidedBy' => null, 'decidedAt' => null];",
            $body
        );
    }

    public function test_rejects_a_department_not_part_of_the_process(): void
    {
        $body = $this->functionBody('public function update_clearance_department(');
        $this->assertStringContainsString('if (!array_key_exists($dept, $departments)) {', $body);
    }

    public function test_rejects_invalid_status_value(): void
    {
        $body = $this->functionBody('public function update_clearance_department(');
        $this->assertStringContainsString('self::CLEARANCE_DEPT_STATUSES', $body);
    }

    public function test_cannot_update_an_already_completed_clearance(): void
    {
        $body = $this->functionBody('public function update_clearance_department(');
        $this->assertStringContainsString("if ((string) (\$clearance['status'] ?? '') !== 'in_progress') {", $body);
    }

    public function test_overall_completion_requires_every_department_explicitly_cleared(): void
    {
        $body = $this->functionBody('public function update_clearance_department(');
        // A pending OR flagged department must block completion — the loop
        // checks for exactly 'cleared', never defaults an unreviewed
        // department to cleared.
        $this->assertStringContainsString("if (!is_array(\$d) || (string) (\$d['status'] ?? '') !== 'cleared') { \$allCleared = false; break; }", $body);
    }

    public function test_completion_sets_completed_at_timestamp(): void
    {
        $body = $this->functionBody('public function update_clearance_department(');
        $this->assertStringContainsString("\$patch['status'] = 'completed';", $body);
        $this->assertStringContainsString("\$patch['completedAt'] = date('c');", $body);
    }

    public function test_tenant_scoped_doc_id(): void
    {
        $body = $this->functionBody('private function _clearance_doc_id(');
        $this->assertStringContainsString('return "{$this->school_id}_{$staffId}";', $body);
    }

    public function test_every_mutation_audit_logged(): void
    {
        $this->assertStringContainsString("log_audit('Staff', 'clearance_initiated',", $this->src);
        $this->assertStringContainsString("log_audit('Staff', 'clearance_department_updated',", $this->src);
    }
}
