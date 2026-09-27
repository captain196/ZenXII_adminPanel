<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ZenX Leave Policy Programme — Phase 7 (Carry-Forward), 2026-08-18.
 *
 * Preview -> Review -> Confirm, HR-triggered, never automatic. Reuses the
 * SAME CAS-lock pattern already proven by decide_leave()'s pending->
 * processing transition, so a second Confirm click is structurally
 * impossible to double-apply — not merely made unlikely.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller code) plus direct arithmetic verification of the worked
 * example from the authorization.
 */
class LeavePhase7CarryForwardTest extends TestCase
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

    // ── Worked-example arithmetic (authorization's own table) ───────────

    public function test_worked_example_teacher_a_and_b(): void
    {
        // Teacher A: unused=14, maxCarry=10 -> carry=10, forfeit=4.
        $unusedA = 14.0; $maxCarryA = 10.0;
        $eligibleA = min($unusedA, $maxCarryA);
        $forfeitA  = max(0.0, $unusedA - $eligibleA);
        $this->assertSame(10.0, $eligibleA);
        $this->assertSame(4.0, $forfeitA);

        // Teacher B: unused=6, maxCarry=10 -> carry=6, forfeit=0.
        $unusedB = 6.0; $maxCarryB = 10.0;
        $eligibleB = min($unusedB, $maxCarryB);
        $forfeitB  = max(0.0, $unusedB - $eligibleB);
        $this->assertSame(6.0, $eligibleB);
        $this->assertSame(0.0, $forfeitB);
    }

    public function test_non_carry_forward_type_always_forfeits_all(): void
    {
        $body = $this->functionBody('public function preview_leave_year_closure(');
        $this->assertStringContainsString('$eligibleCarry = $carryForward ? min($unused, $maxCarry) : 0.0;', $body);
    }

    // ── Preview: read-only against balances, blocked by pending requests ─

    public function test_preview_gated_by_flag_and_view_tier(): void
    {
        $body = $this->functionBody('public function preview_leave_year_closure(');
        $this->assertStringContainsString("_require_role(self::HR_ROLES, 'preview_leave_year_closure', 'HR', 'view')", $body);
        $this->assertStringContainsString("if (!\$this->_leave_flag('LEAVE_YEAR_CLOSURE_ENABLED')) {", $body);
    }

    public function test_preview_blocked_by_pending_requests(): void
    {
        $body = $this->functionBody('public function preview_leave_year_closure(');
        $this->assertStringContainsString('$pendingCount = $this->_count_pending_leave_requests_for_year($year);', $body);
        $this->assertStringContainsString('if ($pendingCount > 0) {', $body);
    }

    public function test_pending_count_fails_closed_on_error(): void
    {
        $body = $this->functionBody('private function _count_pending_leave_requests_for_year(');
        $catchPos = strpos($body, 'catch (\Exception $e) {');
        $this->assertNotFalse($catchPos);
        $returnPos = strpos($body, 'return 1;', $catchPos);
        $this->assertNotFalse($returnPos, 'the catch branch must return 1 (fail closed), never 0.');
    }

    public function test_preview_persists_snapshot_for_confirm_to_replay(): void
    {
        $body = $this->functionBody('public function preview_leave_year_closure(');
        $this->assertStringContainsString("'status'      => 'previewed',", $body);
        $this->assertStringContainsString("'perStaff'    => \$perStaff,", $body);
    }

    // ── Confirm: manage-tier, CAS-locked, truly idempotent ───────────────

    public function test_confirm_gated_at_manage_tier(): void
    {
        $body = $this->functionBody('public function confirm_leave_year_closure(');
        $this->assertStringContainsString("_require_role(self::HR_ROLES, 'confirm_leave_year_closure', 'HR', 'manage')", $body);
    }

    public function test_confirm_is_idempotent_on_already_confirmed(): void
    {
        $body = $this->functionBody('public function confirm_leave_year_closure(');
        $this->assertStringContainsString("if (\$status === 'confirmed') {", $body);
        $this->assertStringContainsString("'idempotent' => true", $body);
        // Confirms the idempotent branch returns BEFORE any CAS lock /
        // balance-write code runs — a second click truly does nothing.
        $idemPos = strpos($body, "if (\$status === 'confirmed') {");
        $casPos  = strpos($body, "\$lockToken = (string) (\$closure['__updateTime'] ?? '');");
        $this->assertLessThan($casPos, $idemPos);
    }

    public function test_confirm_refuses_without_a_prior_preview(): void
    {
        $body = $this->functionBody('public function confirm_leave_year_closure(');
        $this->assertStringContainsString("if (!is_array(\$closure) || empty(\$closure)) {", $body);
        $this->assertStringContainsString("'No preview found for this year. Run the preview first.'", $body);
    }

    public function test_confirm_uses_cas_precondition_before_touching_any_balance(): void
    {
        // The lock (status: previewed -> closing, CAS-guarded on
        // __updateTime) must be won BEFORE the per-staff balance loop runs
        // — this is what makes double-confirm structurally impossible, not
        // just statistically unlikely.
        $body = $this->functionBody('public function confirm_leave_year_closure(');
        $this->assertStringContainsString("'precondition' => ['updateTime' => \$lockToken],", $body);
        $lockPos = strpos($body, "if (!\$wonLock) {");
        $balancePos = strpos($body, 'foreach ($byStaff as $staffId => $rows) {');
        $this->assertNotFalse($lockPos);
        $this->assertNotFalse($balancePos);
        $this->assertLessThan($balancePos, $lockPos);
    }

    public function test_confirm_blocked_by_pending_requests_too(): void
    {
        // Not just at preview time — a request could newly enter 'pending'
        // between preview and confirm.
        $body = $this->functionBody('public function confirm_leave_year_closure(');
        $this->assertStringContainsString('$pendingCount = $this->_count_pending_leave_requests_for_year($year);', $body);
    }

    public function test_carry_is_additive_never_overwrites_existing_carried(): void
    {
        $body = $this->functionBody('public function confirm_leave_year_closure(');
        $this->assertStringContainsString(
            "\$entry['carried'] = (float) (\$entry['carried'] ?? 0) + \$carryAmt;",
            $body
        );
    }

    public function test_zero_carry_rows_are_skipped_not_written(): void
    {
        $body = $this->functionBody('public function confirm_leave_year_closure(');
        $this->assertStringContainsString('if ($tid === \'\' || $carryAmt <= 0) continue;', $body);
    }

    public function test_confirm_writes_carry_into_next_years_balance_doc(): void
    {
        $body = $this->functionBody('public function confirm_leave_year_closure(');
        $this->assertStringContainsString('$this->_casLeaveBalance($staffId, $nextYear, function', $body);
    }

    public function test_closure_confirmed_action_audit_logged(): void
    {
        $body = $this->functionBody('public function confirm_leave_year_closure(');
        $this->assertStringContainsString("'action'        => 'leave_year_closed',", $body);
    }
}
