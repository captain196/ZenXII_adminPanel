<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * HRMS Continuation Programme — cross-check finding (2026-08-18). A prior
 * memory (`hr_payroll_p0_salary_exposure.md`, 2026-08-11) flagged 4 salary-
 * exposure defects, reported-only at the time. Re-verifying against current
 * code this pass: #1 (salary on the readable `staff` doc) is confirmed
 * fixed (`Staff.php` PII_KEYS now includes `salaryStructure`,
 * `_sync_salary_structure()` writes to `staffPrivate`). #4
 * (`Compensation::get_salary_assignments()` — any HR:view holder could read
 * an arbitrary staff_id's salary assignment history) was confirmed STILL
 * PRESENT and is fixed here, reusing the exact same HR:manage clamp
 * pattern Hr.php already applies to every other cross-staff payroll read
 * (get_payslip/get_payroll_slips/export_payroll_report) — not a new RBAC
 * capability, an existing pattern applied consistently.
 *
 * #2 (salary values at log_message('info', ...)) and #3 (salarySlips write
 * rule) were also re-checked: #2 is still present (unfixed, out of this
 * fix's scope — a log-hygiene issue, not an access-control one); #3 was not
 * re-verified this pass (firestore.rules changes concurrently by other
 * sessions per this repo's own CLAUDE.md — a rules change needs its own
 * pull-first pass, not bundled into this PHP-only fix).
 *
 * Source-regression test (Firestore_service has no behavioral mock for
 * controller code).
 */
class CompensationSalaryAssignmentsIdorFixTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../application/controllers/Compensation.php');
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

    public function test_get_salary_assignments_clamps_staff_id_without_manage(): void
    {
        $start = strpos($this->src, 'public function get_salary_assignments(');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->src, $start);

        $this->assertStringContainsString(
            "if (!(function_exists('has_permission') && has_permission('HR', 'manage'))) {",
            $body
        );
        $this->assertStringContainsString("\$staffId = (string) (\$this->admin_id ?? '');", $body);

        // The clamp must run BEFORE safe_path_segment()/any Firestore read —
        // an attacker-controlled staff_id must never reach the query.
        $clampPos = strpos($body, "has_permission('HR', 'manage')");
        $readPos  = strpos($body, '$this->assignments->listForStaff($staffId);');
        $this->assertNotFalse($clampPos);
        $this->assertNotFalse($readPos);
        $this->assertLessThan($readPos, $clampPos);
    }

    public function test_self_service_still_reads_own_id_without_manage(): void
    {
        // A caller WITHOUT HR:manage still gets an answer — their own — not
        // a blanket refusal. Confirmed by the clamp assigning admin_id
        // rather than raising json_error.
        $start = strpos($this->src, 'public function get_salary_assignments(');
        $body = $this->extractFunctionBody($this->src, $start);
        $this->assertStringNotContainsString('json_error', $body,
            'the fix must be a clamp (self-service still works), not an outright refusal for non-manage callers.');
    }
}
