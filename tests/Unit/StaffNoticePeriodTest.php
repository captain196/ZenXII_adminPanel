<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Staff HR Advanced Capability Programme — Phase 2 (Notice Period),
 * 2026-08-18.
 *
 * New collection `staffNoticePeriods/{schoolId}_{staffId}` — one active
 * record per staff member. Deliberately upstream of, and never duplicating,
 * the existing Staff::set_status() separation path: set_status() reads
 * $_POST directly and is HTTP-response-shaped, not a clean internal API,
 * so wrapping it would mean either duplicating its logic or fragile
 * superglobal manipulation — both out of bounds. complete_notice_period()
 * instead REQUIRES the staff record to already be Inactive (i.e. the UI
 * must have already called the existing set_status endpoint) before it
 * will close out the notice record.
 *
 * No money is calculated anywhere in this phase — notice duration is a
 * technical default (30 days), operator-overridable per employment type
 * via schools.noticePeriodDays, same shape every other configurable policy
 * in this codebase already uses. Nothing here computes notice pay,
 * deductions, or any financial figure.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller code).
 */
class StaffNoticePeriodTest extends TestCase
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

    // ── RBAC — every endpoint reuses the existing SIS-module grading ────

    public function test_all_endpoints_gated_by_existing_sis_module_roles(): void
    {
        foreach ([
            ['get_notice_period(', 'VIEW_ROLES', 'view'],
            ['submit_resignation(', 'MANAGE_ROLES', 'manage'],
            ['decide_notice_period(', 'MANAGE_ROLES', 'manage'],
            ['withdraw_resignation(', 'MANAGE_ROLES', 'manage'],
            ['complete_notice_period(', 'MANAGE_ROLES', 'manage'],
        ] as [$fn, $roles, $level]) {
            $body = $this->functionBody("public function {$fn}");
            $this->assertStringContainsString("_require_role(self::{$roles}, \"\", \"SIS\", \"{$level}\")", $body);
        }
    }

    // ── Duration resolution: configurable, technical default, no money ──

    public function test_duration_falls_back_through_type_then_default_then_constant(): void
    {
        $body = $this->functionBody('private function _notice_period_days(');
        $this->assertStringContainsString("isset(\$map[\$employmentType]) && (int) \$map[\$employmentType] > 0", $body);
        $this->assertStringContainsString("isset(\$map['default']) && (int) \$map['default'] > 0", $body);
        $this->assertStringContainsString('return self::NOTICE_PERIOD_DEFAULT_DAYS;', $body);
    }

    public function test_no_financial_calculation_anywhere_in_this_phase(): void
    {
        $submitBody = $this->functionBody('public function submit_resignation(');
        $decideBody = $this->functionBody('public function decide_notice_period(');
        foreach ([$submitBody, $decideBody] as $body) {
            $lower = strtolower($body);
            foreach (['salary', 'payroll', 'deduct', 'encash', 'pay ', 'amount'] as $term) {
                $this->assertStringNotContainsString($term, $lower, "'{$term}' must never appear in Notice Period logic — no financial policy invented here.");
            }
        }
    }

    // ── Submission: one active notice at a time, Active-staff only ──────

    public function test_refuses_a_second_active_notice_period(): void
    {
        $body = $this->functionBody('public function submit_resignation(');
        $this->assertStringContainsString('self::NOTICE_ACTIVE_STATUSES', $body);
        $this->assertStringContainsString("'An active notice period already exists for this staff member. Withdraw or decide it first.'", $body);
    }

    public function test_refuses_for_already_inactive_staff(): void
    {
        $body = $this->functionBody('public function submit_resignation(');
        $this->assertStringContainsString("if (strtolower(trim(\$rawStatus)) !== 'active') {", $body);
    }

    public function test_expected_last_working_day_derived_from_start_plus_duration(): void
    {
        $body = $this->functionBody('public function submit_resignation(');
        $this->assertStringContainsString(
            "\$expectedLwd = date('Y-m-d', strtotime(\"{\$noticeStart} +{\$durationDays} days\"));",
            $body
        );
    }

    // ── Decision: approve/waive/reduce/reject, adjusted date sanity ─────

    public function test_only_pending_notices_can_be_decided(): void
    {
        $body = $this->functionBody('public function decide_notice_period(');
        $this->assertStringContainsString("if ((string) (\$notice['status'] ?? '') !== 'pending') {", $body);
    }

    public function test_waive_and_reduce_require_an_explicit_earlier_date(): void
    {
        $body = $this->functionBody('public function decide_notice_period(');
        $this->assertStringContainsString("if (in_array(\$decision, ['waive', 'reduce'], true)) {", $body);
        $this->assertStringContainsString(
            "if (\$actualLwdRaw > \$expectedLwd) {",
            $body
        );
    }

    public function test_reject_clears_the_last_working_day(): void
    {
        $body = $this->functionBody('public function decide_notice_period(');
        $this->assertStringContainsString("if (\$decision === 'reject') \$actualLwd = null;", $body);
    }

    // ── Completion: NEVER triggers separation itself ────────────────────

    public function test_complete_notice_period_never_calls_set_status(): void
    {
        $body = $this->functionBody('public function complete_notice_period(');
        $this->assertStringNotContainsString('set_status(', $body,
            'this endpoint must never internally trigger separation — it only closes out the notice record after the UI has already called the existing, unmodified set_status endpoint separately.');
    }

    public function test_complete_notice_period_verifies_staff_is_already_inactive(): void
    {
        $body = $this->functionBody('public function complete_notice_period(');
        $this->assertStringContainsString("if (strtolower(trim(\$rawStatus)) !== 'inactive') {", $body);
        $this->assertStringContainsString(
            "'The staff member must be marked Inactive (via Separation) before the notice period can be completed.'",
            $body
        );
    }

    public function test_complete_only_valid_from_a_decided_state(): void
    {
        $body = $this->functionBody('public function complete_notice_period(');
        $this->assertStringContainsString("in_array((string) (\$notice['status'] ?? ''), ['approved', 'waived', 'reduced'], true)", $body);
    }

    // ── Withdrawal: pending only ─────────────────────────────────────────

    public function test_withdraw_only_allowed_while_pending(): void
    {
        $body = $this->functionBody('public function withdraw_resignation(');
        $this->assertStringContainsString("if ((string) (\$notice['status'] ?? '') !== 'pending') {", $body);
    }

    // ── Every mutation is audit-logged ───────────────────────────────────

    public function test_every_mutation_audit_logged(): void
    {
        $this->assertStringContainsString("log_audit('Staff', 'notice_period_submitted',", $this->src);
        $this->assertStringContainsString("log_audit('Staff', 'notice_period_decided',", $this->src);
        $this->assertStringContainsString("log_audit('Staff', 'notice_period_withdrawn',", $this->src);
        $this->assertStringContainsString("log_audit('Staff', 'notice_period_completed',", $this->src);
    }

    // ── Tenant isolation: doc id is school-scoped ────────────────────────

    public function test_notice_doc_id_is_school_scoped(): void
    {
        $body = $this->functionBody('private function _notice_doc_id(');
        $this->assertStringContainsString('return "{$this->school_id}_{$staffId}";', $body);
    }
}
