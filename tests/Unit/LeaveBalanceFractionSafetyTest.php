<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Leave balances must survive fractional leave days.
 *
 * DEFECT (found 2026-08-19 while completing the leave financial reconciliation):
 * allocate_leave_balances() reconciles `used` from approved requests and calls
 * that reconciliation the authoritative source. It read each request's day count
 * as `(int) ($req['days'] ?? 0)`.
 *
 * `days` is provably fractional:
 *   • an explicit half-day request is 0.5;
 *   • under Calendar Half-Day the calendar-aware count makes a FULL-day request
 *     falling on such a day 0.5 as well;
 *   • a multi-day span crossing one yields e.g. 2.5.
 *
 * `(int) 0.5 === 0`, so a half-day leave consumed ZERO balance: the entitlement
 * could be taken repeatedly and never debited. Carry-forward had the same cast,
 * confiscating a half day at each year boundary.
 *
 * The fix is integer-PRESERVING: a whole-day value is still added as an int, so
 * a tenant with no fractional leave keeps integer `used`/`balance` values and
 * its stored documents do not change type. That property is asserted here
 * because a naive `(float)` fix would silently retype every tenant's balances.
 */
final class LeaveBalanceFractionSafetyTest extends TestCase
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

    public function test_used_days_are_no_longer_truncated(): void
    {
        $this->assertStringNotContainsString("\$d = (int) (\$req['days'] ?? 0);", $this->src,
            'an (int) cast here makes a half-day leave consume zero balance');
        $this->assertStringContainsString("\$d = (float) (\$req['days'] ?? 0);", $this->src);
    }

    public function test_used_accumulation_preserves_integers(): void
    {
        $this->assertStringContainsString(
            "\$usedMap[\$sid][\$tid] += (\$d == floor(\$d)) ? (int) \$d : \$d;",
            $this->src,
            'whole days must stay int so untouched tenants keep integer balances'
        );
    }

    public function test_carry_forward_is_fraction_safe(): void
    {
        $this->assertStringNotContainsString(
            "\$prevBal = (int) \$prevBalances[\$staffId][\$typeId]['balance'];",
            $this->src
        );
        $this->assertStringContainsString(
            "\$prevBal = (\$pb == floor(\$pb)) ? (int) \$pb : \$pb;",
            $this->src
        );
    }

    /**
     * The balance WRITER was already fraction-safe. Pinned so a future tidy-up
     * cannot reintroduce truncation on the other side of the same value.
     */
    public function test_balance_writer_floors_with_float_comparison(): void
    {
        $this->assertStringContainsString("(float) \$bd['balance'] < 0", $this->src);
        $this->assertStringContainsString("(float) \$bd['used']    < 0", $this->src);
    }

    /**
     * Executable check of the exact arithmetic the reconciler now performs —
     * the property that matters is not the syntax but the outcome.
     */
    public function test_accumulation_arithmetic_behaves_correctly(): void
    {
        $accumulate = static function (array $dayCounts) {
            $total = 0;
            foreach ($dayCounts as $raw) {
                $d = (float) $raw;
                $total += ($d == floor($d)) ? (int) $d : $d;
            }
            return $total;
        };

        // Whole days only — must remain an int, byte-identical to the old behaviour.
        $wholeOnly = $accumulate([1, 2, 3]);
        $this->assertSame(6, $wholeOnly);
        $this->assertIsInt($wholeOnly, 'no fraction in play must not retype the value');

        // A single half-day is debited, not discarded.
        $this->assertSame(0.5, $accumulate([0.5]));

        // Calendar Half-Day case: a full-day request resolved to 0.5.
        $this->assertSame(2.5, $accumulate([2, 0.5]));

        // Multi-day span crossing a Calendar Half-Day.
        $this->assertSame(2.5, $accumulate([2.5]));

        // The defect itself: under the old cast every one of these became 0.
        $this->assertSame(1.5, $accumulate([0.5, 0.5, 0.5]));
    }
}
