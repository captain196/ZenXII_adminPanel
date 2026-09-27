<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ZenX Leave Policy Programme — Phase 3 (Accrual), 2026-08-18.
 *
 * _accrue_monthly_leave_one_type() touches Firestore via _casLeaveBalance()
 * (no behavioral mock exists for controller code in this codebase — see
 * StaffCreationAuditMetadataTest's docblock), so this is a source-regression
 * test verifying the idempotency/ceiling/flag-gating logic structurally,
 * consistent with every other controller-touching test in this suite.
 */
class LeavePhase3AccrualTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        // The accrual ENGINE was extracted to Leave_accrual_service (2026-09-20);
        // Hr now delegates to it. Read BOTH so these source guards follow the
        // engine to its single-authority home (bodies moved verbatim).
        $out = '';
        foreach ([
            __DIR__ . '/../../application/controllers/Hr.php',
            __DIR__ . '/../../application/libraries/Leave_accrual_service.php',
        ] as $file) {
            $raw = file_get_contents($file);
            $this->assertNotFalse($raw, "missing {$file}");
            foreach (token_get_all($raw) as $token) {
                if (is_array($token)) {
                    if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                    $out .= $token[1];
                } else {
                    $out .= $token;
                }
            }
            $out .= "\n";
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

    public function test_accrual_gated_by_per_school_setting_first(): void
    {
        // Repointed 2026-09-19: accrual is now gated by the PER-SCHOOL setting
        // (schools.leavePolicy.accrualEnabled via _school_accrual_enabled()),
        // not the fleet-wide LEAVE_ACCRUAL_ENABLED flag — the school decides.
        // Default false → the engine is still a no-op for every tenant that
        // hasn't opted in, so the gate-first safety property is unchanged.
        $body = $this->functionBody('public function accrueOneType(');
        $this->assertStringContainsString('if (!$this->schoolAccrualEnabled()) return $none;', $body,
            'accrual must return the no-op result unless the school opted in');
        $this->assertStringNotContainsString("_leave_flag('LEAVE_ACCRUAL_ENABLED')", $body,
            'the fleet-wide flag must no longer gate the per-school engine');
    }

    public function test_accrual_only_applies_to_monthly_mode_types(): void
    {
        $body = $this->functionBody('public function accrueOneType(');
        $this->assertStringContainsString(
            "if ((string) (\$ver['accrual_mode'] ?? 'lump_sum') !== 'monthly') {",
            $body,
            'a type with no accrual_mode set, or explicitly lump_sum, must never be credited — existing types stay unchanged by default.'
        );
    }

    public function test_monthly_amount_uses_half_day_granularity(): void
    {
        // Repointed 2026-09-19: the fixed `round(($daysPerYear/12)*2)/2` per month
        // was a DIVERGENCE defect (it summed to 12× that, not the configured
        // annual — 20/yr → 18). The engine now distributes the exact annual via
        // the pure _accrual_installment() helper, which keeps the 0.5-day
        // granularity while trueing the window up to $daysPerYear.
        $body = $this->functionBody('public function accrueOneType(');
        $this->assertStringContainsString('self::installment(', $body,
            'accrual must distribute via the divergence-free installment helper');
        $this->assertStringNotContainsString('round(($daysPerYear / 12) * 2) / 2', $body,
            'the old fixed per-month amount (which diverged from the annual) must be gone');

        $helper = $this->functionBody('public static function installment(');
        $this->assertStringContainsString('* 2) / 2', $helper,
            'the installment helper keeps 0.5-day granularity');
    }

    public function test_idempotency_via_last_accrual_month_marker(): void
    {
        $body = $this->functionBody('public function accrueOneType(');
        $this->assertStringContainsString("(float) (\$entry['accruedToDate'] ?? 0);", $body);
        $this->assertStringContainsString("(string) (\$entry['lastAccrualMonth'] ?? '');", $body);
        // Whitespace-robust (the engine moved verbatim to the service; exact
        // indentation is not the contract). Resume strictly AFTER the last
        // credited month, so a covered month is never re-credited on a re-run.
        $collapsed = (string) preg_replace('/\s+/', ' ', $body);
        $this->assertStringContainsString(
            "\$lastMonth !== '' && \$lastMonth >= \$earliest", $collapsed,
            'resume must be gated on the stored lastAccrualMonth marker.');
        $this->assertStringContainsString(
            "? \$this->nextMonth(\$lastMonth) : \$earliest;", $collapsed,
            'a month already covered by lastAccrualMonth must never be re-credited on a repeat run.');
    }

    public function test_max_balance_ceiling_only_enforced_when_its_own_flag_is_on(): void
    {
        // Full mechanics (checked against CONSUMABLE balance, not raw
        // accruedToDate — the resumption-after-use behavior) are Phase 6's
        // own scope; see LeavePhase6MaxBalanceTest.
        $body = $this->functionBody('public function accrueOneType(');
        $this->assertStringContainsString(
            "\$maxBalance  = \$this->leaveFlag('LEAVE_MAX_BALANCE_ENABLED') ? (\$ver['max_balance'] ?? null) : null;",
            $body
        );
    }

    public function test_ceiling_pauses_rather_than_claws_back(): void
    {
        $body = $this->functionBody('public function accrueOneType(');
        // The break happens BEFORE $accruedToDate is reassigned to $next —
        // i.e. the ceiling stops future credit, it never reduces what's
        // already stored.
        $breakPos = strpos($body, 'break;');
        $assignPos = strpos($body, '$accruedToDate = $next;');
        $this->assertNotFalse($breakPos);
        $this->assertNotFalse($assignPos);
        $this->assertLessThan($assignPos, $breakPos);
    }

    public function test_credit_loop_is_bounded(): void
    {
        $body = $this->functionBody('public function accrueOneType(');
        $this->assertStringContainsString('$guard < 12', $body,
            'a single year-scoped balance doc can never need more than 12 monthly credits — bounds a malformed marker from looping unboundedly.');
    }

    public function test_accrual_credit_is_audit_logged(): void
    {
        $body = $this->functionBody('public function accrueOneType(');
        $this->assertStringContainsString("'action'         => 'leave_accrual_credited',", $body);
    }

    public function test_run_leave_accrual_gated_at_manage_tier(): void
    {
        $body = $this->functionBody('public function run_leave_accrual(');
        $this->assertStringContainsString("_require_role(self::HR_ROLES, 'run_leave_accrual', 'HR', 'manage')", $body);
    }

    public function test_run_leave_accrual_is_a_safe_noop_when_flag_off(): void
    {
        $body = $this->functionBody('public function run_leave_accrual(');
        $this->assertStringContainsString("'ran' => false,", $body);
    }

    public function test_next_month_source_uses_real_date_math_not_string_manipulation(): void
    {
        $body = $this->functionBody('private function _next_month(');
        $this->assertStringContainsString('mktime(0, 0, 0,', $body,
            'must use real calendar math (handles Dec->Jan year rollover) rather than naive string increment.');
    }

    public function test_forward_declared_fields_default_to_current_behavior(): void
    {
        $body = $this->functionBody('public function save_leave_type(');
        $this->assertStringContainsString("if (!in_array(\$accrualMode, ['lump_sum', 'monthly'], true)) \$accrualMode = 'lump_sum';", $body);
        $this->assertStringContainsString("\$maxBalance    = (\$maxBalanceRaw === '') ? null : (float) \$maxBalanceRaw;", $body);
        $this->assertStringContainsString('$waitingPeriodDays = max(0, (int) ($this->input->post(\'waiting_period_days\') ?? 0));', $body);
    }
}
