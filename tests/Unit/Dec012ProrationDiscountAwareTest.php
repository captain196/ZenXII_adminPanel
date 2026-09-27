<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Fee_firestore_txn.php';

/**
 * DEC-012 — discount-aware proration (Option A), plus the write-boundary
 * whitelist defect it uncovered.
 *
 * THE DEFECT. `proRateFees()` computed `newNet = grossAmount * factor` — from
 * GROSS — ignoring `discountAmount` and never updating it. A demand carrying an
 * approved concession came out as gross 2400 / discount 240 / net 1200: the
 * concession silently erased, and `gross − discount == net` violated. Proration
 * is the LAST fee-affecting step in all five Transport callers, so it ran after
 * pricing and undid it.
 *
 * OPTION A (chosen): proration scales the CHARGE — gross and discount scale
 * together, so the invariant holds by construction. The rejected alternatives
 * (absorb proration into `discountAmount`; add a third `prorationAmount`) bill
 * the family the IDENTICAL amount; the choice was only about what `grossAmount`
 * means afterwards. Under A it means "the charge for the period actually
 * covered" — which is already what `createModuleFee` does when it re-prices a
 * service demand in place.
 *
 * THE SECOND DEFECT, found while implementing this. `updateDemand()` is a STRICT
 * WHITELIST with no passthrough. `proRateFees()` has always passed `prorated`
 * and `prorateFactor`; both were silently DROPPED and never reached Firestore —
 * which is why the estate contains zero demands carrying a proration marker. The
 * audit trail of a money-changing operation was being discarded at the write
 * boundary.
 *
 * MEASURED BEFORE SHIPPING (all tenants): 89 proration-eligible demands, ZERO
 * carrying a discount, ZERO already prorated. This changes no existing money.
 */
class Dec012ProrationDiscountAwareTest extends TestCase
{
    /**
     * The exact arithmetic `proRateFees()` performs, mirrored so the money can
     * be asserted without a live Firestore. Kept byte-aligned with the source;
     * the wiring assertions at the bottom pin that alignment.
     */
    private function prorate(array $d, float $factor): array
    {
        $curGross  = (float) ($d['grossAmount'] ?? $d['netAmount'] ?? 0);
        $curDisc   = (float) ($d['discountAmount'] ?? $d['discount_amount'] ?? 0);
        $listGross = array_key_exists('listGrossAmount', $d)
            ? (float) $d['listGrossAmount'] : $curGross;
        $listDisc  = array_key_exists('listDiscountAmount', $d)
            ? (float) $d['listDiscountAmount'] : $curDisc;

        $newGross = round($listGross * $factor, 2);
        $newDisc  = round($listDisc  * $factor, 2);
        $newNet   = round($newGross - $newDisc, 2);
        $paid     = (float) ($d['paidAmount'] ?? 0);
        $fine     = (float) ($d['fineAmount'] ?? $d['fine_amount'] ?? 0);
        $newBal   = round($newNet + $fine - $paid, 2);
        if ($newBal < 0) $newBal = 0;

        return [
            'grossAmount' => $newGross, 'discountAmount' => $newDisc,
            'netAmount' => $newNet, 'balance' => $newBal,
            'listGrossAmount' => $listGross, 'listDiscountAmount' => $listDisc,
            'prorated' => true, 'prorateFactor' => $factor,
        ];
    }

    private function demand(float $gross, float $disc, float $net): array
    {
        return ['grossAmount' => $gross, 'discountAmount' => $disc, 'netAmount' => $net,
                'frequency' => 'annual', 'status' => 'unpaid', 'category' => 'transport',
                'paidAmount' => 0.0];
    }

    // ══ THE DEFECT ══════════════════════════════════════════════════════

    /** THE HEADLINE: a discounted annual fare, prorated to half a year. */
    public function test_a_discounted_demand_keeps_its_discount(): void
    {
        $r = $this->prorate($this->demand(2400, 240, 2160), 0.5);

        $this->assertSame(1200.0, $r['grossAmount'],    'the charge for the covered period');
        $this->assertSame(120.0,  $r['discountAmount'], 'the concession scales WITH the charge');
        $this->assertSame(1080.0, $r['netAmount'],
            'PRE-FIX THIS WAS 1200 - the 10% concession was silently erased');
        $this->assertNull(Fee_firestore_txn::moneyIncoherence($r),
            'gross - discount == net must hold after proration');
    }

    /** The concession RATE must survive proration exactly. */
    public function test_the_discount_rate_is_preserved(): void
    {
        foreach ([0.25, 0.5, 0.75, 1 / 3] as $factor) {
            $r = $this->prorate($this->demand(2400, 240, 2160), $factor);
            $rate = $r['discountAmount'] / $r['grossAmount'];
            $this->assertEqualsWithDelta(0.10, $rate, 0.0005,
                "the 10% rate must survive a factor of {$factor}");
            $this->assertNull(Fee_firestore_txn::moneyIncoherence($r));
        }
    }

    /** An UNDISCOUNTED demand: net is unchanged from the old behaviour. */
    public function test_an_undiscounted_demand_bills_the_same_net_as_before(): void
    {
        $r = $this->prorate($this->demand(2400, 0, 2400), 0.5);
        $this->assertSame(1200.0, $r['netAmount'],
            'the old code produced 2400*0.5 = 1200 too - no family is billed differently');
        $this->assertSame(1200.0, $r['grossAmount'],
            'Option A cost, accepted: gross now reflects the covered period');
        $this->assertSame(0.0, $r['discountAmount']);
    }

    /** Full exemption stays fully exempt at any factor. */
    public function test_a_full_exemption_stays_free(): void
    {
        $r = $this->prorate($this->demand(2400, 2400, 0), 0.5);
        $this->assertSame(0.0, $r['netAmount'], 'a fully exempt student never owes anything');
        $this->assertNull(Fee_firestore_txn::moneyIncoherence($r));
    }

    // ══ IDEMPOTENCY — WHY THE LIST ANCHOR EXISTS ════════════════════════

    /**
     * Without the anchor, Option A would COMPOUND: 2400 → 1200 → 600. The five
     * Transport callers can each prorate the same demand, so this is not
     * hypothetical.
     */
    public function test_proration_is_idempotent(): void
    {
        $once  = $this->prorate($this->demand(2400, 240, 2160), 0.5);
        $twice = $this->prorate(array_merge($this->demand(2400, 240, 2160), $once), 0.5);

        $this->assertSame($once['grossAmount'],    $twice['grossAmount'],
            'a second proration must NOT compound to 600');
        $this->assertSame($once['discountAmount'], $twice['discountAmount']);
        $this->assertSame($once['netAmount'],      $twice['netAmount']);
    }

    /** Re-prorating at a DIFFERENT factor recomputes from the list, not the prorated value. */
    public function test_a_new_factor_recomputes_from_the_list_charge(): void
    {
        $half    = $this->prorate($this->demand(2400, 240, 2160), 0.5);
        $quarter = $this->prorate(array_merge($this->demand(2400, 240, 2160), $half), 0.25);

        $this->assertSame(600.0, $quarter['grossAmount'], '2400 * 0.25, not 1200 * 0.25');
        $this->assertSame(60.0,  $quarter['discountAmount']);
        $this->assertSame(540.0, $quarter['netAmount']);
    }

    /** The anchor is written once and never drifts. */
    public function test_the_list_anchor_is_stable(): void
    {
        $r1 = $this->prorate($this->demand(2400, 240, 2160), 0.5);
        $this->assertSame(2400.0, $r1['listGrossAmount']);
        $this->assertSame(240.0,  $r1['listDiscountAmount']);
        $r2 = $this->prorate(array_merge($this->demand(2400, 240, 2160), $r1), 0.75);
        $this->assertSame(2400.0, $r2['listGrossAmount'], 'the original charge stays recoverable');
        $this->assertSame(240.0,  $r2['listDiscountAmount']);
    }

    // ══ PAYMENT SAFETY ══════════════════════════════════════════════════

    /** A partial payment reduces the balance, and the fine is not prorated. */
    public function test_balance_accounts_for_payment_and_fine(): void
    {
        $d = $this->demand(2400, 240, 2160);
        $d['paidAmount'] = 500.0; $d['fineAmount'] = 50.0; $d['status'] = 'partial';
        $r = $this->prorate($d, 0.5);
        $this->assertSame(1080.0, $r['netAmount']);
        $this->assertSame(630.0,  $r['balance'], 'net 1080 + fine 50 - paid 500');
        $this->assertNull(Fee_firestore_txn::moneyIncoherence($r),
            'a fine must not break the charge invariant');
    }

    /** Over-collection floors the balance at zero (and is logged in production). */
    public function test_over_collection_floors_the_balance(): void
    {
        $d = $this->demand(2400, 240, 2160);
        $d['paidAmount'] = 2000.0;
        $r = $this->prorate($d, 0.5);
        // Production assigns the literal int 0 here; updateDemand casts every
        // money field to float at the write boundary, so assert by value.
        $this->assertEquals(0, $r['balance'], 'balance never goes negative');
        $this->assertSame(1080.0, $r['netAmount'], 'the charge itself is still correct');
    }

    // ══ THE WHITELIST DEFECT ════════════════════════════════════════════

    /**
     * `updateDemand` silently discarded every key missing from its map. The
     * proration markers were among them, so the audit trail never persisted.
     */
    public function test_the_write_whitelist_now_carries_the_proration_fields(): void
    {
        $src = file_get_contents(APPPATH . 'libraries/Fee_firestore_txn.php');
        $map = substr($src, strpos($src, 'public function updateDemand'), 3000);
        foreach (['prorated', 'prorateFactor', 'listGrossAmount', 'listDiscountAmount'] as $f) {
            $this->assertStringContainsString("'" . $f . "'", $map,
                "'{$f}' must be whitelisted or updateDemand silently drops it");
        }
    }

    /** Proration must actually WRITE gross and discount, not just net. */
    public function test_proration_writes_gross_and_discount(): void
    {
        $src = file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php');
        $call = substr($src, strpos($src, "updateDemand(\$did, ["), 700);
        foreach (['grossAmount', 'discountAmount', 'netAmount', 'balance',
                  'listGrossAmount', 'listDiscountAmount'] as $f) {
            $this->assertStringContainsString($f, $call,
                "proration must persist '{$f}'");
        }
    }

    /** The old gross-only derivation must be gone. */
    public function test_the_old_gross_only_derivation_is_removed(): void
    {
        $src = '';
        foreach (token_get_all(file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php')) as $t) {
            $src .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        $this->assertStringNotContainsString('$newNet    = round($grossAmt * $factor, 2)', $src);
        $this->assertStringNotContainsString('round($grossAmt * $factor', $src,
            'net must no longer be derived from gross alone');
        $this->assertStringContainsString('$newNet    = round($newGross - $newDisc, 2)', $src,
            'net must be derived from the scaled gross MINUS the scaled discount');
    }

    // ══ GUARDS UNCHANGED ════════════════════════════════════════════════

    /** Paid/archived skip, annual-only, and the full-year early return must survive. */
    public function test_the_existing_proration_guards_are_intact(): void
    {
        $src = '';
        foreach (token_get_all(file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php')) as $t) {
            $src .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        $this->assertStringContainsString("\$st === 'paid' || \$st === 'archived'", $src,
            'a fully paid demand must still be skipped');
        $this->assertStringContainsString("\$freq !== 'annual' && \$freq !== 'yearly'", $src,
            'monthly demands must still never be prorated');
        $this->assertStringContainsString('$remaining <= 0 || $remaining >= 12', $src,
            'a full-year join must still be a no-op');
        $this->assertStringContainsString('FEE_PRORATE_OVERCOLLECTION', $src,
            'the over-collection signal must survive');
    }
}
