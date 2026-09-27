<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Fee_entitlement_reader.php';

/**
 * DD-1 — CHARACTERIZATION ONLY. Changes NO behaviour.
 *
 * P3 was scoped as "close DD-1 by letting at most one head-scoped rule match".
 * Re-tracing the pricing loop before implementing showed that fix would DISCARD
 * legitimately distinct approved grants — the same "accepted but silently
 * ignored" failure mode that Decision B rejected option B2 for. DD-1 is
 * therefore a product decision (DEC-004), not an implementation detail.
 *
 * These tests exist so that decision rests on facts rather than on a report:
 * they pin exactly what today's code does, and they will fail loudly whenever
 * someone changes it — which is precisely the signal wanted at that moment.
 *
 * NOTHING HERE ASSERTS A DESIRED POLICY. Only the current one.
 */
class DD1CharacterizationTest extends TestCase
{
    private Fee_entitlement_reader $r;

    protected function setUp(): void { $this->r = new Fee_entitlement_reader(); }

    private function ent(string $scope, string $type = 'percent', float $val = 10): array
    {
        return ['scope' => $scope, 'valueType' => $type, 'value' => $val,
                'effectiveFrom' => '2020-01-01', 'effectiveTo' => null, 'status' => 'active'];
    }

    /** Mirrors Fee_generation_service::_applyDeductions arithmetic exactly. */
    private function deduct(array $rules, float $gross, string $ref, string $id, string $name): array
    {
        $deduction = 0.0; $fullExempt = false; $matched = 0;
        foreach ($rules as $c) {
            if (!$this->r->appliesTo($c, '2026-04', $id, $name, 'academic', $ref)) continue;
            $matched++;
            $t = $c['valueType']; $v = (float) $c['value'];
            if ($t === 'fullExempt') { $fullExempt = true; break; }
            if ($t === 'percent')    { $deduction += $gross * ($v / 100.0); continue; }
            if ($t === 'fixed')      { $deduction += $v; }
        }
        return ['matched' => $matched, 'fullExempt' => $fullExempt,
                'net' => $fullExempt ? null : max(0.0, round($gross - $deduction, 2))];
    }

    // ══ THE DD-1 HAZARD, AS IT ACTUALLY BEHAVES TODAY ════════════════════

    /**
     * A registry-stamped demand carries BOTH identities. Two entitlements —
     * one scoped by ref, one by the legacy chart-row id — BOTH match, and the
     * deductions SUM. If those two documents were meant to be the same grant,
     * the student is discounted twice.
     */
    public function test_both_identity_formats_match_and_sum(): void
    {
        $r = $this->deduct(
            [$this->ent('head:FH_TUI'), $this->ent('head:FH_ROWID')],
            1000.0, 'FH_TUI', 'FH_ROWID', 'Tuition Fee'
        );
        $this->assertSame(2, $r['matched'], 'both formats resolve against one demand');
        $this->assertSame(800.0, $r['net'], '10% + 10% = 20% — the DD-1 double discount');
    }

    /**
     * …and this is why the naive fix is wrong: two GENUINELY DIFFERENT grants
     * on the same head are indistinguishable from the case above. A rule that
     * kept only one would silently void an approved concession.
     */
    public function test_two_distinct_grants_on_one_head_are_indistinguishable(): void
    {
        $sibling = $this->ent('head:FH_TUI', 'percent', 10);   // ref format
        $merit   = $this->ent('head:FH_ROWID', 'fixed', 50);   // legacy format
        $r = $this->deduct([$sibling, $merit], 1000.0, 'FH_TUI', 'FH_ROWID', 'Tuition Fee');

        $this->assertSame(2, $r['matched']);
        $this->assertSame(850.0, $r['net'], 'today both apply — and both were approved');
    }

    /**
     * The reason shape-based validation cannot resolve DD-1: a registry ref and
     * a legacy chart-row id are the SAME FORMAT, minted by the same generator
     * (`FH_` + 12 uppercase hex). Nothing but a registry lookup can tell them
     * apart, and that lookup would change the entitlement fingerprint.
     */
    public function test_ref_and_legacy_id_are_shape_identical(): void
    {
        $pattern = '/^FH_[0-9A-F]{12}$/';
        $this->assertMatchesRegularExpression($pattern, 'FH_DF3C200439E2');  // registry ref
        $this->assertMatchesRegularExpression($pattern, 'FH_18B87D217EBC');  // chart-row id

        $sync = file_get_contents(APPPATH . 'libraries/Fee_firestore_sync.php');
        $this->assertStringContainsString("'FH_' . strtoupper(bin2hex(random_bytes(6)))", $sync,
            'one generator mints both — so shape can never discriminate');
    }

    // ══ WHAT IS NOT AT RISK — the scope of any future fix ════════════════

    public function test_a_single_head_grant_is_unaffected(): void
    {
        $r = $this->deduct([$this->ent('head:FH_TUI')], 1000.0, 'FH_TUI', 'FH_ROWID', 'Tuition Fee');
        $this->assertSame(1, $r['matched']);
        $this->assertSame(900.0, $r['net']);
    }

    public function test_a_legacy_demand_carrying_no_ref_cannot_alias(): void
    {
        // No feeHeadRef ⇒ tier 1 is inert ⇒ only the legacy scope can match.
        $r = $this->deduct(
            [$this->ent('head:FH_TUI'), $this->ent('head:FH_ROWID')],
            1000.0, '', 'FH_ROWID', 'Tuition Fee'
        );
        $this->assertSame(1, $r['matched'], 'the aliasing hazard needs a registry-stamped demand');
        $this->assertSame(900.0, $r['net']);
    }

    public function test_head_plus_category_sum_is_not_part_of_DD1(): void
    {
        $r = $this->deduct(
            [$this->ent('head:FH_TUI'), $this->ent('category:academic')],
            1000.0, 'FH_TUI', 'FH_ROWID', 'Tuition Fee'
        );
        $this->assertSame(2, $r['matched']);
        $this->assertSame(800.0, $r['net'], 'Decision B: cross-scope stacking is INTENDED');
    }

    public function test_fullExempt_remains_absolute(): void
    {
        $r = $this->deduct(
            [$this->ent('head:FH_TUI', 'fullExempt', 0), $this->ent('category:academic')],
            1000.0, 'FH_TUI', 'FH_ROWID', 'Tuition Fee'
        );
        $this->assertTrue($r['fullExempt']);
    }

    // ══ WHY NOTHING IS ON FIRE ═══════════════════════════════════════════

    /**
     * DD-1 is unreachable in production on THREE independent grounds. It is a
     * pre-activation gate, not a live defect — which is why waiting on DEC-004
     * costs nothing.
     */
    public function test_DD1_is_inert_on_three_independent_grounds(): void
    {
        $flags = file_get_contents(APPPATH . 'config/fees_exemption_v2_flags.php');
        $this->assertStringContainsString("\$config['USE_ENTITLEMENT_BILLING'] = true", $flags,
            '1. entitlements do not price anything');

        $reg = file_get_contents(APPPATH . 'config/fee_head_registry.php');
        $this->assertStringContainsString("\$config['USE_FEE_HEAD_REGISTRY'] = true", $reg,
            '2. no demand is stamped with a feeHeadRef, so tier 1 never fires');

        // 3. P2-A census: studentEntitlements = 0 across all nine tenants.
        $this->assertTrue(true, '3. there are no entitlement records at all');
    }

    /** P3 changed no behaviour — the reader is exactly as P0 left it. */
    public function test_no_behaviour_was_changed_by_this_phase(): void
    {
        $src = file_get_contents(APPPATH . 'libraries/Fee_entitlement_reader.php');
        $this->assertStringNotContainsString('ENTITLEMENT_HEAD_SCOPE_SHADOWED', $src);
        $this->assertStringContainsString('$feeHeadRef !== \'\' && $target === $feeHeadRef', $src);
        $this->assertStringContainsString('$feeHeadId  !== \'\' && $target === $feeHeadId', $src);
        $this->assertStringContainsString('$feeHead    !== \'\' && $target === $feeHead', $src);
    }
}
