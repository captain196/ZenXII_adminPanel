<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ZenX Leave Policy Programme — Phase 6 (Maximum Balance), 2026-08-18.
 *
 * max_balance (how much an employee may HOLD at any point) is distinct
 * from max_carry (how much moves into the next Leave Year — Phase 7). The
 * accrual engine itself was built in Phase 3
 * (Hr::_accrue_monthly_leave_one_type()); this phase's own scope is
 * specifically the ceiling's correctness against the authorization's
 * worked example: "EL monthly accrual=1, max_balance=30. If balance is
 * already 30, next accrual blocked. If employee consumes 5, balance=25,
 * future accrual can resume." An earlier draft of the Phase 3 ceiling
 * checked raw accruedToDate (never resuming after use) instead of the
 * CONSUMABLE balance (accrued-used) — fixed as part of this phase, caught
 * by writing this exact test before declaring Phase 6 done.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller code).
 */
class LeavePhase6MaxBalanceTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        // The accrual engine moved to Leave_accrual_service (2026-09-20); read
        // both so the max-balance ceiling guard follows the engine (verbatim).
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

    private function accrualBody(): string
    {
        $start = strpos($this->src, 'public function accrueOneType(');
        $this->assertNotFalse($start);
        return $this->extractFunctionBody($this->src, $start);
    }

    public function test_ceiling_checked_against_consumable_balance_not_raw_accrued(): void
    {
        $body = $this->accrualBody();
        $this->assertStringContainsString("\$used            = (float) (\$entry['used'] ?? 0);", $body);
        $this->assertStringContainsString(
            'if ($maxBalance !== null && ($next - $used) > $maxBalance) {',
            $body,
            'the worked example requires resumption after usage — checking raw accruedToDate alone would permanently stop accrual the first time the ceiling is touched.'
        );
    }

    public function test_worked_example_arithmetic(): void
    {
        // EL monthly accrual=1, max_balance=30. accruedToDate already 30,
        // used=0 -> next=31, (31-0)=31 > 30 -> blocked.
        $accruedToDate = 30.0; $used = 0.0; $monthlyAmt = 1.0; $maxBalance = 30.0;
        $next = $accruedToDate + $monthlyAmt;
        $this->assertGreaterThan($maxBalance, $next - $used, 'accrual must be blocked at the ceiling.');

        // Employee consumes 5 -> used=5, current consumable balance=25.
        $used = 5.0;
        // Next month's accrual: next = 30 + 1 = 31; consumable-after = 31-5=26 <= 30 -> allowed.
        $next2 = $accruedToDate + $monthlyAmt;
        $this->assertLessThanOrEqual($maxBalance, $next2 - $used, 'accrual must resume once usage frees room under the ceiling.');
    }

    public function test_ceiling_never_claws_back_already_credited_balance(): void
    {
        $body = $this->accrualBody();
        $ceilingCheckPos = strpos($body, 'if ($maxBalance !== null && ($next - $used) > $maxBalance) {');
        $breakPos = strpos($body, 'break;', $ceilingCheckPos);
        $assignPos = strpos($body, '$accruedToDate = $next;');
        $this->assertNotFalse($breakPos);
        $this->assertNotFalse($assignPos);
        $this->assertLessThan($assignPos, $breakPos);
    }

    public function test_lowering_max_balance_does_not_retroactively_reduce_stored_balance(): void
    {
        // The accrual function only ever WRITES accruedToDate by adding
        // $monthlyAmt inside the credit loop — it never sets accruedToDate
        // to anything smaller than what was already stored (no clawback
        // statement exists anywhere in the function).
        $body = $this->accrualBody();
        $this->assertStringNotContainsString('$accruedToDate = 0', $body);
        $this->assertStringNotContainsString('min($accruedToDate', $body);
    }

    public function test_max_balance_forward_declared_in_save_leave_type_since_phase_3(): void
    {
        // Confirms Phase 6 didn't need a second schema change — Phase 3
        // already forward-declared this field precisely so Phase 6
        // wouldn't have to.
        $this->assertStringContainsString("'max_balance'        => \$maxBalance,", $this->src);
        $this->assertStringContainsString(
            "\$maxBalanceRaw = trim((string) \$this->input->post('max_balance'));",
            $this->src
        );
    }

    public function test_max_balance_validated_non_negative_at_save_time(): void
    {
        $this->assertStringContainsString(
            "if (\$maxBalance !== null && \$maxBalance < 0) {",
            $this->src
        );
    }

    public function test_null_max_balance_means_no_ceiling(): void
    {
        $this->assertStringContainsString(
            "\$maxBalance    = (\$maxBalanceRaw === '') ? null : (float) \$maxBalanceRaw;",
            $this->src
        );
    }
}
