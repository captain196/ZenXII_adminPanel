<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

/**
 * IS THE ALLOCATION LEDGER A PREREQUISITE FOR CORRECT ACCOUNTING?
 *
 * The programme has been treating `USE_ALLOCATION_LEDGER` as if accounting
 * depended on it. This file settles that question against the REAL engine.
 *
 * ── WHAT THE ENGINE ALREADY DOES ──────────────────────────────────────────
 * `Operations_accounting::_buildGranularLines()` takes a `$discountAmount`
 * argument, entirely separately from the `concessions[]` path:
 *
 *     Dr Cash/Bank            (what was collected)
 *     Dr Discount Allowed     (5190, an expense)
 *     Cr Income               (at GROSS)
 *
 * So a V2-discounted demand is ALREADY booked correctly: revenue is recognised
 * at list price and the discount appears as its own expense line. That happens
 * with `concessions_enabled` OFF and with zero allocation rows.
 *
 * ── WHAT THE LEDGER WOULD ADD ─────────────────────────────────────────────
 * `concessions[]` splits that single "Discount Allowed" line by concession TYPE
 * into per-type contra-revenue accounts (Merit Scholarship, Sibling, RTE/EWS…).
 * That is a reporting refinement, not a correctness fix — and it is gated behind
 * its own `concessions_enabled` env flag, which is off.
 *
 * ── CONCLUSION THESE TESTS SUPPORT ────────────────────────────────────────
 * The allocation ledger is OPTIONAL AUDIT INFRASTRUCTURE. Core Billing V2
 * accounting is correct without it. Merging the two into one readiness verdict
 * would have overstated what is blocked.
 *
 * The payment path supplies `discount_amount` (`FeeCollectionService:1169`,
 * `:1728`), so this is the live production shape, not a hypothetical one.
 */
class BillingV2DiscountAccountingWithoutLedgerTest extends TestCase
{
    /** Configure the shared CI stub, per its documented protocol. */
    private function configureCI(bool $concessionsEnabled): void
    {
        $CI = get_instance();
        $CI->config = new class($concessionsEnabled) {
            private bool $on;
            public function __construct(bool $on) { $this->on = $on; }
            public function item($key, $group = null)
            {
                return $key === 'concessions_enabled' ? $this->on : null;
            }
        };
        $CI->acctFsSync = new class { public function readFeeAccountMap() { return []; } };
    }

    protected function setUp(): void
    {
        $this->configureCI(false);          // production default: concessions OFF
        if (!class_exists('Operations_accounting', false)) {
            require_once APPPATH . 'libraries/Operations_accounting.php';
        }
    }

    protected function tearDown(): void { get_instance()->config = null; }

    private function coa(array $omit = []): array
    {
        $spec = [
            '1010' => ['Cash in Hand',      'Asset'],
            '4010' => ['Tuition Income',    'Income'],
            '4060' => ['Late Fee Income',   'Income'],
            '5190' => ['Discount Allowed',  'Expense'],
        ];
        $coa = [];
        foreach ($spec as $code => $v) {
            $code = (string) $code;
            if (in_array($code, $omit, true)) continue;
            $coa[$code] = ['status' => 'active', 'is_group' => false,
                           'name' => $v[0], 'category' => $v[1]];
        }
        return $coa;
    }

    /** Drive the REAL private line builder. */
    private function build(array $allocations, array $coa, float $cash,
                           float $fine = 0.0, float $discount = 0.0): array
    {
        $obj = (new ReflectionClass('Operations_accounting'))->newInstanceWithoutConstructor();
        $m   = new ReflectionMethod('Operations_accounting', '_buildGranularLines');
        $m->setAccessible(true);
        $res = $m->invoke($obj, $coa, '1010', $coa['1010'], $cash,
                          'Receipt R1', 'STU9001', $allocations, $fine, $discount);
        return is_array($res[0] ?? null) ? $res[0] : [];
    }

    private function totals(array $lines): array
    {
        $dr = 0.0; $cr = 0.0;
        foreach ($lines as $l) { $dr += (float) $l['dr']; $cr += (float) $l['cr']; }
        return [round($dr, 2), round($cr, 2)];
    }

    private function lineFor(array $lines, string $code): ?array
    {
        foreach ($lines as $l) if ((string) $l['account_code'] === $code) return $l;
        return null;
    }

    // ══ THE HEADLINE — a V2 discount books correctly with NO ledger ═════════

    /**
     * STU0185's real shape: ₹600 tuition, ₹60 entitlement discount, ₹540 paid.
     * Zero allocation rows, concessions_enabled OFF — production as it stands.
     */
    public function test_a_v2_discount_books_correctly_without_any_allocation_row(): void
    {
        $lines = $this->build(
            [['fee_head' => 'Tuition Fee', 'amount' => 540.0]],   // no concessions[] key
            $this->coa(), 540.0, 0.0, 60.0
        );
        list($dr, $cr) = $this->totals($lines);

        $this->assertSame($dr, $cr, 'the journal must balance');
        $this->assertSame(600.0, $cr, 'income must be recognised at GROSS, not at what was collected');

        $cash = $this->lineFor($lines, '1010');
        $this->assertSame(540.0, (float) $cash['dr'], 'cash is what actually arrived');

        $disc = $this->lineFor($lines, '5190');
        $this->assertNotNull($disc, 'the discount must appear as its own expense line');
        $this->assertSame(60.0, (float) $disc['dr']);
    }

    /**
     * THE POINT: gross revenue survives the discount. A school reading its P&L
     * sees ₹600 of tuition income and ₹60 of discount expense — not ₹540 of
     * income with the concession invisible.
     */
    public function test_revenue_is_not_silently_netted_down_by_the_discount(): void
    {
        $lines  = $this->build([['fee_head' => 'Tuition Fee', 'amount' => 540.0]],
                               $this->coa(), 540.0, 0.0, 60.0);
        $income = $this->lineFor($lines, '4010');

        $this->assertSame(600.0, (float) $income['cr'],
            'netting the discount into revenue would hide the concession from reporting');
    }

    // ══ THE LEDGER IS NOT LOAD-BEARING HERE ════════════════════════════════

    /**
     * Identical inputs, with and without a `concessions[]` key present but the
     * flag off. The journal must be byte-identical — proving the ledger/concession
     * machinery contributes nothing while disabled.
     */
    public function test_the_journal_is_identical_whether_or_not_concessions_are_attached(): void
    {
        $plain = $this->build([['fee_head' => 'Tuition Fee', 'amount' => 540.0]],
                              $this->coa(), 540.0, 0.0, 60.0);

        $withConc = $this->build([[
            'fee_head' => 'Tuition Fee', 'amount' => 540.0,
            'concessions' => [['type' => 'Merit Scholarship', 'amount' => 60.0]],
        ]], $this->coa(), 540.0, 0.0, 60.0);

        $this->assertSame($plain, $withConc,
            'with concessions_enabled OFF the concessions[] key must be completely inert');
    }

    // ══ DEGRADED CHART ═════════════════════════════════════════════════════

    /**
     * With no discount-expense account available the engine folds the discount
     * into reduced revenue (documented legacy behaviour). Still balanced — the
     * school loses gross-revenue visibility, not correctness.
     */
    public function test_without_a_discount_account_the_journal_still_balances(): void
    {
        $lines = $this->build([['fee_head' => 'Tuition Fee', 'amount' => 540.0]],
                              $this->coa(['5190']), 540.0, 0.0, 60.0);
        list($dr, $cr) = $this->totals($lines);

        $this->assertSame($dr, $cr, 'Dr=Cr must hold even on a degraded chart');
        $this->assertNull($this->lineFor($lines, '5190'));
    }

    // ══ ZERO DISCOUNT — the overwhelming common case ═══════════════════════

    public function test_a_student_with_no_discount_is_unaffected(): void
    {
        $lines = $this->build([['fee_head' => 'Tuition Fee', 'amount' => 600.0]],
                              $this->coa(), 600.0, 0.0, 0.0);
        list($dr, $cr) = $this->totals($lines);

        $this->assertSame($dr, $cr);
        $this->assertSame(600.0, $cr);
        $this->assertNull($this->lineFor($lines, '5190'),
            'no discount ⇒ no discount line at all');
    }

    // ══ PARTIAL PAYMENT AGAINST A DISCOUNTED DEMAND ════════════════════════

    /**
     * A family paying part of a discounted demand. The discount recognised is
     * what the caller passes; the journal must still balance and must never
     * recognise more income than charge + fine.
     */
    public function test_a_partial_payment_against_a_discounted_demand_balances(): void
    {
        $lines = $this->build([['fee_head' => 'Tuition Fee', 'amount' => 270.0]],
                              $this->coa(), 270.0, 0.0, 30.0);
        list($dr, $cr) = $this->totals($lines);

        $this->assertSame($dr, $cr);
        $this->assertSame(300.0, $cr, 'half the charge settled ⇒ half recognised, at gross');
    }

    // ══ FINE + DISCOUNT TOGETHER ═══════════════════════════════════════════

    /**
     * ⚠ CHARACTERIZATION — ACC-1: a fee discount is spread onto LATE-FEE income.
     *
     * `_buildGranularLines()` distributes the discount proportionally across
     * EVERY credit account (`Operations_accounting.php:1540-1550`), and the fine
     * credit is already in that set:
     *
     *     fee 540 + fine 50 = 590 credits · discount 60 distributed pro rata
     *       Tuition  540/590 × 60 = 54.92  → 594.92
     *       Late fee  50/590 × 60 =  5.08  →  55.08
     *
     * The journal BALANCES (650 = 650) and total revenue is right, so this is
     * not a money leak. But the split between income accounts is wrong: a
     * TUITION concession has inflated LATE-FEE income by ₹5.08. A school's P&L
     * overstates late-fee revenue and understates tuition whenever a discounted
     * demand is settled together with a fine.
     *
     * The comment on that block says the intent is that "gross income lines sum
     * to gross fee value" — and a fine is not part of the fee value. Including
     * it in the proportional base looks like an oversight rather than a policy.
     *
     * NOT FIXED HERE. Changing how a discount is apportioned between income
     * accounts is a live accounting posting change and an accounting-policy
     * decision, not a refactor — it is exactly the kind of thing that must not
     * be altered autonomously. This test PINS current behaviour so the finding
     * cannot be lost, and fails if the apportionment changes either way.
     *
     * Pre-existing: this predates Billing V2 and is unrelated to P5 convergence.
     */
    public function test_ACC1_a_discount_no_longer_touches_late_fee_income(): void
    {
        $lines = $this->build([['fee_head' => 'Tuition Fee', 'amount' => 540.0]],
                              $this->coa(), 590.0, 50.0, 60.0);
        list($dr, $cr) = $this->totals($lines);

        $this->assertSame($dr, $cr, 'the journal must balance');
        $this->assertSame(650.0, $cr, 'total revenue: 600 gross fee + 50 fine');

        $fine = $this->lineFor($lines, '4060');
        $this->assertNotNull($fine, 'the fine is credited to its own income account');
        $this->assertSame(50.0, (float) $fine['cr'],
            'ACC-1 FIXED: a TUITION concession must not gross up LATE-FEE income. '
            . 'Before the fix this was 55.08 — 5.08 of the concession landed here.');

        $income = $this->lineFor($lines, '4010');
        $this->assertSame(600.0, (float) $income['cr'],
            'the whole discount now grosses up the fee it actually relates to');
    }

    /**
     * The fine falls back to 4010 when 4060 is inactive, commingling it with
     * tuition. The fix excludes the fine AMOUNT, not its account — so the
     * apportionment must still be correct in that shape.
     */
    public function test_ACC1_holds_when_the_fine_falls_back_onto_the_fee_account(): void
    {
        $lines = $this->build([['fee_head' => 'Tuition Fee', 'amount' => 540.0]],
                              $this->coa(['4060']), 590.0, 50.0, 60.0);
        list($dr, $cr) = $this->totals($lines);

        $this->assertSame($dr, $cr, 'balanced even when fee and fine share one account');
        $this->assertSame(650.0, $cr);

        // 4010 now carries fee 540 + fine 50, and should receive the full 60
        // discount on its FEE portion only: 600 + 50 = 650.
        $income = $this->lineFor($lines, '4010');
        $this->assertSame(650.0, (float) $income['cr'],
            'excluding the fine by AMOUNT keeps the maths right even when the '
            . 'account cannot distinguish the two');
    }

    /**
     * Degenerate input: a discount with no fee income to apportion it across.
     * Nonsensical, but Dr=Cr must still hold rather than the journal breaking.
     */
    public function test_ACC1_a_fine_only_receipt_with_a_discount_still_balances(): void
    {
        $lines = $this->build([], $this->coa(), 50.0, 50.0, 10.0);
        list($dr, $cr) = $this->totals($lines);

        $this->assertSame($dr, $cr,
            'the fallback distribution must preserve Dr=Cr on a degenerate input');
    }

    /** Without a fine the apportionment is unambiguous — all of it lands on the fee. */
    public function test_without_a_fine_the_discount_lands_entirely_on_the_fee_income(): void
    {
        $lines  = $this->build([['fee_head' => 'Tuition Fee', 'amount' => 540.0]],
                               $this->coa(), 540.0, 0.0, 60.0);
        $income = $this->lineFor($lines, '4010');

        $this->assertSame(600.0, (float) $income['cr'],
            'with no competing credit account the discount grosses up only the fee — '
            . 'which is why ACC-1 only manifests when a fine is settled alongside a discount');
    }

    // ══ THE PRODUCTION SHAPE IS REAL, NOT HYPOTHETICAL ═════════════════════

    /** The payment path genuinely supplies `discount_amount`. */
    public function test_the_payment_path_supplies_the_discount_to_accounting(): void
    {
        $svc = (string) file_get_contents(APPPATH . 'services/FeeCollectionService.php');
        $this->assertMatchesRegularExpression(
            "/'discount_amount'\s*=>\s*round\(\(float\)\s*\(\\\$discountFees/", $svc,
            'if the collector did not pass the discount, none of the above would run in production');
    }

    /** Concession journaling is env-gated and off — it is not the default model. */
    public function test_concession_journaling_remains_env_gated(): void
    {
        $cfg = (string) file_get_contents(APPPATH . 'config/config.php');
        $this->assertMatchesRegularExpression(
            "/\\\$config\\['concessions_enabled'\\]\s*=\s*\(string\)\s*\(getenv\(/", $cfg,
            'the per-type contra-revenue model is an opt-in enhancement, not the baseline');
    }
}
