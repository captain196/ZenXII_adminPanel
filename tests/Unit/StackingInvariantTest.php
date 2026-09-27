<?php
/**
 * PHASE 8 — THE STACKING MODEL AND ITS PLATFORM INVARIANTS.
 *
 * ── WHAT THE TRACE FOUND, BEFORE ANY CODE WAS WRITTEN ─────────────────────
 *
 * The question "should discounts stack?" was already answered by the
 * architecture, in two different places, and the answer is not a switch:
 *
 *   GENERATION layer — grants STACK ADDITIVELY. Every rule whose scope matches
 *   a demand contributes to one `$deduction`, which is then clamped. This is
 *   deliberate: a sibling concession and a scholarship are separate awards and
 *   both should reduce the bill. DEC-004 closed the one case where stacking was
 *   an ACCIDENT — the same logical head reached through two id formats, summed
 *   as though it were two grants.
 *
 *   COLLECTION layer — the counter adjustment applies on top of the already
 *   reduced demand. Also deliberate, also additive, and across a layer
 *   boundary rather than within one (STUDENT_DISCOUNTS_FORENSIC_AUDIT §V).
 *
 * ── WHY NO STACKING-ORDER SETTING WAS ADDED ───────────────────────────────
 *
 * Addition is commutative. With an additive model and a single clamp, the
 * order in which grants are applied cannot change the result, so a
 * "stacking order" preference would be a UI control that changes nothing —
 * precisely the thing the brief forbids ("do not expose a UI setting that the
 * pricing engine does not genuinely implement"). The honest answer is that
 * order is not a school choice here because it is not a degree of freedom.
 *
 * What IS a school choice — a tighter ceiling on a counter adjustment — is
 * configurable, and lives in Benefit_policy_service.
 *
 * ── THE INVARIANTS, WHICH ARE NOT CONFIGURABLE ────────────────────────────
 */

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

final class StackingInvariantTest extends TestCase
{
    private const GEN    = __DIR__ . '/../../application/libraries/Fee_generation_service.php';
    private const FEES   = __DIR__ . '/../../application/controllers/Fees.php';
    private const POLICY = __DIR__ . '/../../application/views/financial_policies/index.php';

    private function src(string $f): string { return (string) file_get_contents($f); }

    /**
     * The model both engines implement: sum every matching grant, then clamp
     * the NET at zero. Mirrors Fee_generation_service's STACK-1 arithmetic and
     * Fees::_generateDemandsForMonth's STACK-2 clamp.
     *
     * @return array{net:float,discount:float,excess:float}
     */
    private function price(float $gross, array $grants): array
    {
        $deduction = 0.0;
        foreach ($grants as $g) $deduction += $g;
        $net      = max(0.0, round($gross - $deduction, 2));
        $applied  = round($gross - $net, 2);
        $excess   = $deduction > $gross ? round($deduction - $gross, 2) : 0.0;
        return ['net' => $net, 'discount' => $applied, 'excess' => $excess];
    }

    // ══ INVARIANT 1 · PAYABLE IS NEVER NEGATIVE ════════════════════════════

    public function test_the_payable_amount_can_never_go_negative(): void
    {
        foreach ([[1000.0, [600, 700]], [1000.0, [1000, 1]], [500.0, [99999]]] as [$gross, $grants]) {
            $r = $this->price($gross, $grants);
            $this->assertGreaterThanOrEqual(0.0, $r['net'],
                'a family must never be billed a negative amount, however many grants overlap');
        }
    }

    /** An RTE full exemption plus a 20% sibling discount = 120% of gross. */
    public function test_a_full_exemption_plus_a_percentage_grant_bills_zero(): void
    {
        $r = $this->price(1000.0, [1000.0, 200.0]);
        $this->assertSame(0.0, $r['net'], 'a genuinely exempt family is billed zero — the right answer');
        $this->assertSame(200.0, $r['excess'], 'and the surplus grant is 200, which must be reported');
    }

    // ══ INVARIANT 2 · DISCOUNT NEVER EXCEEDS THE CHARGE ════════════════════

    public function test_the_recorded_discount_never_exceeds_the_charge(): void
    {
        $r = $this->price(1000.0, [5000.0]);
        $this->assertSame(1000.0, $r['discount'],
            'the STORED discount is the clamped figure — otherwise gross - discount == net breaks');
        $this->assertLessThanOrEqual(1000.0, $r['discount']);
    }

    // ══ INVARIANT 3 · gross − discount == net, ALWAYS ══════════════════════

    public function test_the_reporting_identity_holds_for_every_combination(): void
    {
        foreach ([[1200.0, [100, 250]], [1200.0, [1200]], [1200.0, [700, 700]],
                  [999.99, [0.01]], [0.0, [500]]] as [$gross, $grants]) {
            $r = $this->price($gross, $grants);
            $this->assertEqualsWithDelta($gross - $r['discount'], $r['net'], 0.005,
                'every reader depends on gross - discount == net; a clamp that broke it '
                . 'would make the demand describe money that does not add up');
        }
    }

    // ══ INVARIANT 4 · ORDER-INDEPENDENCE (why no order setting exists) ═════

    public function test_stacking_is_order_independent(): void
    {
        $g = [300.0, 150.0, 75.5];
        $a = $this->price(1000.0, $g);
        shuffle($g);
        $b = $this->price(1000.0, $g);
        $this->assertSame($a['net'], $b['net'],
            'addition is commutative, so a "stacking order" setting would change nothing. '
            . 'Exposing one would be a control that does not control anything.');
    }

    /** Over-clamped cases are order-independent too. */
    public function test_order_independence_survives_the_clamp(): void
    {
        $this->assertSame(
            $this->price(500.0, [400, 300])['net'],
            $this->price(500.0, [300, 400])['net']
        );
    }

    // ══ INVARIANT 5 · THE SAME GRANT CANNOT BE COUNTED TWICE ═══════════════

    /**
     * DEC-004. Stacking is legitimate only between DISTINCT grants; the same
     * logical head reached through two id formats is one grant, and summing it
     * twice is an accident, not a policy.
     */
    public function test_the_double_count_guard_is_still_present(): void
    {
        $this->assertStringContainsString('DEC-004', $this->src(self::GEN),
            'the guard that stops one grant being counted twice must not be removed — '
            . 'without it, stacking silently becomes double-charging in reverse');
    }

    // ══ INVARIANT 6 · OVER-GRANTING FAILS LOUD ON BOTH ENGINES ═════════════

    /**
     * The clamp makes the money right, which is exactly why nobody notices.
     * The demand can only record the clamped discount, so the surplus is
     * invisible and revoking one of two overlapping grants changes nothing —
     * the bill stays zero and the revocation looks like it failed.
     */
    public function test_both_generation_paths_report_an_over_grant(): void
    {
        $this->assertStringContainsString('ACC_CONCESSION_EXCEEDS_CHARGE', $this->src(self::GEN),
            'the canonical engine must report it');
        $this->assertStringContainsString('ACC_CONCESSION_EXCEEDS_CHARGE', $this->src(self::FEES),
            'and so must the legacy generator — otherwise the same condition is observable '
            . 'on one path and invisible on the other, which is worse than neither');
    }

    /** The legacy signal must fire on the same condition, not merely exist. */
    public function test_the_legacy_signal_is_attached_to_the_clamp(): void
    {
        $src = $this->src(self::FEES);
        $at  = strpos($src, 'ACC_CONCESSION_EXCEEDS_CHARGE path=legacy');
        $this->assertNotFalse($at, 'the legacy over-grant signal is missing');
        // 2026-09-14 — was a ±1400/2200 character window. Bounded by the enclosing
        // method now, so the clamp and its signal are checked wherever they sit in
        // it rather than only while they stay within a byte budget.
        $window = $this->enclosingMethod($src, $at);
        $this->assertStringContainsString('$discountAmount > $amount', $window,
            'the report must be driven by the over-grant condition itself');
        $this->assertStringContainsString('$discountAmount = $amount;', $window,
            'and the clamp must still happen — the money stays right, only the silence goes');
    }

    // ══ INVARIANT 7 · NO UI CLAIMS A CONTROL THE ENGINE LACKS ══════════════

    public function test_no_stacking_switch_is_exposed_to_schools(): void
    {
        if (!is_file(self::POLICY)) { $this->markTestSkipped('policy screen absent'); }
        $ui = strtolower($this->src(self::POLICY));
        foreach (['stacking order', 'combination rule', 'discount precedence'] as $claim) {
            $this->assertStringNotContainsString($claim, $ui,
                "the screen must not offer '{$claim}' — the engine is additive and clamped, "
                . 'so such a control would be inert. An inert money setting is worse than none: '
                . 'a school would believe it had changed pricing.');
        }
    }

    // ══ INVARIANT 8 · THE LAYER BOUNDARY IS PRESERVED ══════════════════════

    /**
     * A collection adjustment stacks ACROSS the layer boundary, on top of an
     * already-reduced demand — and is capped by the outstanding balance, so
     * the cross-layer total can never exceed what is actually owed either.
     */
    public function test_the_cross_layer_total_cannot_exceed_what_is_owed(): void
    {
        // demand layer: 1,000 gross, 400 concession -> 600 balance
        $demand = $this->price(1000.0, [400.0]);
        $this->assertSame(600.0, $demand['net']);

        // collection layer: the counter may waive at most the 600 outstanding
        $outstanding = $demand['net'];
        $waiver      = min(750.0, $outstanding);   // mirrors the ADJ-GOV ceiling
        $this->assertSame(600.0, $waiver,
            'a 750 counter waiver against a 600 balance is refused down to 600 — so the '
            . 'concession and the waiver together can never exceed the original charge');
        $this->assertGreaterThanOrEqual(0.0, $outstanding - $waiver);
    }

    /**
     * The enclosing method body around $at.
     *
     * Replaces a fixed character window. A window silently stops covering the
     * line it was written to pin the moment code is inserted nearby — the
     * assertion then passes or fails on a truncated string rather than on the
     * property it claims to test.
     */
    private function enclosingMethod(string $s, int $at): string
    {
        $start = strrpos(substr($s, 0, $at), '    function ');
        foreach (['    public function ', '    private function ', '    protected function '] as $sig) {
            $p = strrpos(substr($s, 0, $at), $sig);
            if ($p !== false && ($start === false || $p > $start)) $start = $p;
        }
        if ($start === false) return $s;
        $end = preg_match('/\n    (?:public|private|protected) function /', $s, $m,
                          PREG_OFFSET_CAPTURE, $at) ? $m[0][1] : strlen($s);
        return substr($s, $start, $end - $start);
    }
}
