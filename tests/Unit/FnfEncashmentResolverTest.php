<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Behavioural cover for the F&F leave-encashment input resolver.
 *
 * Executes `Fnf_encashment_resolver` directly — it is pure, so no harness is
 * needed. The resolver returns DAYS and never money; converting days to rupees
 * belongs to the off-cycle payroll run using the tenant's `days_basis`, so a day
 * of encashed leave is worth exactly what a day of salary is worth that month.
 *
 * Two properties carry the design:
 *
 *   1. Encashability is a PER-TYPE FLAG that defaults to FALSE. D-002 Q3 framed
 *      this as one policy choice (earned only / earned+carried / per type); that
 *      framing was wrong. A school encashing Earned but not Casual Leave is not
 *      in conflict with one encashing neither — they have different leave
 *      policies, which `schools.leaveTypes` already models per type. Defaulting
 *      it ON would invent a payment obligation nobody agreed to.
 *
 *   2. The available-days rule is the SHIPPED leave engine's, clamp included:
 *      `max(0, allocated + carried - used)`. Without the clamp an over-consumed
 *      type would report negative days and REDUCE a settlement — paying someone
 *      less because they took unpaid leave.
 */
final class FnfEncashmentResolverTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
        require_once __DIR__ . '/../../application/libraries/Fnf_encashment_resolver.php';
    }

    private function type(string $name, float $perYear, $encashable = false): array
    {
        return ['name' => $name, 'days_per_year' => $perYear, 'encashable' => $encashable];
    }

    private function bal(float $used = 0.0, float $carried = 0.0): array
    {
        return ['used' => $used, 'carried' => $carried];
    }

    // ── The available-days rule mirrors the leave engine ─────────────────

    public function test_available_is_allocated_plus_carried_less_used(): void
    {
        $this->assertSame(
            9.5,
            Fnf_encashment_resolver::availableDays($this->bal(5.5, 3.0), 12.0)
        );
    }

    public function test_a_fractional_balance_survives(): void
    {
        $this->assertSame(0.5, Fnf_encashment_resolver::availableDays($this->bal(11.5), 12.0));
    }

    /**
     * THE CLAMP. `used` accrues for unpaid types too, so over-consumption is
     * reachable. A negative here would reduce the settlement — paying someone
     * LESS because they took unpaid leave.
     */
    public function test_over_consumption_clamps_to_zero_never_negative(): void
    {
        $this->assertSame(0.0, Fnf_encashment_resolver::availableDays($this->bal(20.0), 12.0));
        $this->assertSame(0.0, Fnf_encashment_resolver::availableDays($this->bal(5.0), 0.0));
    }

    public function test_carried_forward_days_are_encashable_when_available(): void
    {
        $this->assertSame(3.0, Fnf_encashment_resolver::availableDays($this->bal(0.0, 3.0), 0.0),
            'carry-forward is real entitlement, not a display artifact');
    }

    public function test_a_missing_balance_entry_means_only_the_allocation(): void
    {
        $this->assertSame(12.0, Fnf_encashment_resolver::availableDays([], 12.0));
    }

    // ── Encashability is a flag, and it defaults OFF ─────────────────────

    public function test_a_type_with_no_flag_is_not_encashable(): void
    {
        $this->assertFalse(Fnf_encashment_resolver::isEncashable(['name' => 'Casual Leave']),
            'defaulting this ON would invent a payment obligation');
    }

    public function test_the_flag_accepts_the_shapes_config_actually_arrives_in(): void
    {
        foreach ([true, 'true', '1', 1, 'yes'] as $on) {
            $this->assertTrue(Fnf_encashment_resolver::isEncashable(['encashable' => $on]),
                'truthy config must enable encashment');
        }
        foreach ([false, 'false', '0', 0, '', null, 'no'] as $off) {
            $this->assertFalse(Fnf_encashment_resolver::isEncashable(['encashable' => $off]),
                'anything not clearly true must stay OFF');
        }
    }

    // ── Resolution across a real type set ────────────────────────────────

    public function test_only_encashable_types_contribute(): void
    {
        $r = Fnf_encashment_resolver::resolve(
            ['EL' => $this->bal(2.0), 'CL' => $this->bal(1.0)],
            [
                'EL' => $this->type('Earned Leave', 12.0, true),
                'CL' => $this->type('Casual Leave', 6.0, false),
            ]
        );

        $this->assertSame(10.0, $r['encashableDays'], 'only Earned Leave counts');
    }

    /**
     * Every type considered is reported, with a reason. A settlement that
     * silently omits a type cannot be checked — an employee disputing their F&F
     * is entitled to see that Casual Leave was looked at and found
     * non-encashable, not simply absent.
     */
    public function test_excluded_types_are_reported_with_a_reason(): void
    {
        $r = Fnf_encashment_resolver::resolve(
            ['EL' => $this->bal(), 'CL' => $this->bal(), 'LOP' => $this->bal(3.0)],
            [
                'EL'  => $this->type('Earned Leave', 12.0, true),
                'CL'  => $this->type('Casual Leave', 6.0, false),
                'LOP' => $this->type('Loss of Pay', 0.0, true),
            ]
        );

        $this->assertCount(3, $r['perType'], 'nothing may be silently dropped');

        $byId = [];
        foreach ($r['perType'] as $row) $byId[$row['typeId']] = $row;

        $this->assertSame('encashable', $byId['EL']['reason']);
        $this->assertSame('type_not_encashable', $byId['CL']['reason']);
        $this->assertSame('no_balance', $byId['LOP']['reason'],
            'encashable but exhausted is a different fact from not encashable');
    }

    public function test_the_total_matches_the_sum_of_the_encashable_rows(): void
    {
        $r = Fnf_encashment_resolver::resolve(
            ['EL' => $this->bal(2.5), 'SL' => $this->bal(1.0, 2.0)],
            [
                'EL' => $this->type('Earned Leave', 12.0, true),
                'SL' => $this->type('Sick Leave', 6.0, true),
                'CL' => $this->type('Casual Leave', 6.0, false),
            ]
        );

        $sum = 0.0;
        foreach ($r['perType'] as $row) {
            if ($row['encashable']) $sum = round($sum + $row['available'], 2);
        }

        $this->assertEqualsWithDelta($r['encashableDays'], $sum, 0.0001,
            'the headline figure must reconcile against its own breakdown');
        $this->assertSame(16.5, $r['encashableDays']);
    }

    public function test_fractional_balances_accumulate_exactly(): void
    {
        $r = Fnf_encashment_resolver::resolve(
            ['A' => $this->bal(0.5), 'B' => $this->bal(0.25)],
            ['A' => $this->type('A', 1.0, true), 'B' => $this->type('B', 1.0, true)]
        );

        $this->assertSame(1.25, $r['encashableDays']);
    }

    // ── Degenerate input ─────────────────────────────────────────────────

    public function test_no_types_yields_nothing_encashable(): void
    {
        $r = Fnf_encashment_resolver::resolve([], []);

        $this->assertSame(0.0, $r['encashableDays']);
        $this->assertSame([], $r['perType']);
    }

    public function test_a_type_with_no_balance_document_still_reports(): void
    {
        $r = Fnf_encashment_resolver::resolve([], ['EL' => $this->type('Earned Leave', 12.0, true)]);

        $this->assertSame(12.0, $r['encashableDays'], 'an untouched allocation is still owed');
        $this->assertCount(1, $r['perType']);
    }

    public function test_a_malformed_type_is_skipped_not_fatal(): void
    {
        $r = Fnf_encashment_resolver::resolve(
            ['EL' => $this->bal()],
            ['EL' => $this->type('Earned Leave', 12.0, true), 'BAD' => 'not-an-array']
        );

        $this->assertSame(12.0, $r['encashableDays']);
        $this->assertCount(1, $r['perType']);
    }

    public function test_a_type_falls_back_to_its_code_then_its_id_for_a_name(): void
    {
        $r = Fnf_encashment_resolver::resolve(
            [],
            [
                'X' => ['code' => 'EL', 'days_per_year' => 1.0, 'encashable' => true],
                'Y' => ['days_per_year' => 1.0, 'encashable' => true],
            ]
        );

        $names = array_column($r['perType'], 'name', 'typeId');
        $this->assertSame('EL', $names['X']);
        $this->assertSame('Y', $names['Y'], 'an unnamed type must still be identifiable');
    }

    // ── The boundary: days, never money ──────────────────────────────────

    /**
     * The divisor question (D-002 Q4) is resolved — encashment uses the
     * tenant's existing `days_basis` — but it is resolved in the payroll run,
     * not here. This resolver must contain no rupee arithmetic at all.
     */
    public function test_the_resolver_computes_no_money(): void
    {
        $path = __DIR__ . '/../../application/libraries/Fnf_encashment_resolver.php';
        $src  = file_get_contents($path);
        $this->assertNotFalse($src);

        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
                $code .= $t[1];
            } else {
                $code .= $t;
            }
        }

        $this->assertStringContainsString('function resolve', $code, 'comment-stripping sanity');

        foreach (['days_basis', 'basic', 'gross', 'salary', 'paise', 'amount', '/ 26', '/ 30'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $code,
                "money belongs to the off-cycle run, not the input resolver ({$needle})");
        }
    }
}
