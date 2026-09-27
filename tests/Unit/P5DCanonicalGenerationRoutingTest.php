<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Fee_generation_service.php';

/**
 * P5-D — SINGLE-STUDENT GENERATION ROUTED THROUGH THE CANONICAL PIPELINE.
 *
 * ── THE DEFECT ────────────────────────────────────────────────────────────
 * `fees/generate_demands_for_student` built its own specs and priced them from
 * `_getStudentDiscounts()` — `scholarshipAwards`, `students.exemptedFees`,
 * `students.discountHeads`. It never read `studentEntitlements`.
 *
 * Proven live 2026-08-20: STU0185, holding an APPROVED ACTIVE 10% Tuition
 * concession, produced 37 demands at ₹11,200 with `discountAmount = 0`.
 * Correct is ₹10,480. No error, no log — the entitlement simply stopped being
 * reflected in the money.
 *
 * ── THE FIX ───────────────────────────────────────────────────────────────
 * Route to `Fee_lifecycle::assignInitialFees()`, which already IS the canonical
 * pipeline. Its name says "initial" but it builds the WHOLE SESSION from the
 * class chart — the same charge set, correctly priced. Nothing new was written.
 *
 * These tests drive the REAL `Fee_generation_service` (the pricing half of that
 * pipeline) with production-shaped specs and a real entitlement, and assert on
 * the money that comes out.
 */
class P5DCanonicalGenerationRoutingTest extends TestCase
{
    private const SCHOOL  = 'SCH_TESTTENANT1';
    private const SESSION = '2026-27';
    private const HEAD    = 'FH_DF3C200439E2';

    /**
     * The exact chart STU0185 bills against: Tuition 600/mo, Library 50/mo,
     * Transport 200/mo, Admission 1000 annual. 12 months ⇒ 37 charges.
     */
    private function specs(): array
    {
        $specs  = [];
        $months = ['2026-04','2026-05','2026-06','2026-07','2026-08','2026-09',
                   '2026-10','2026-11','2026-12','2027-01','2027-02','2027-03'];

        foreach ($months as $pk) {
            foreach ([['Tuition Fee', 600.0, self::HEAD], ['Library Fee', 50.0, 'FH_LIB'],
                      ['Transport Fee', 200.0, 'FH_TRN']] as $h) {
                list($name, $amt, $ref) = $h;
                $specs[] = $this->spec($name, $ref, $pk, $amt);
            }
        }
        // Annual head, April only.
        $specs[] = $this->spec('Admission Fee', 'FH_ADM', '2026-04', 1000.0);
        return ['specs' => $specs, 'assigned' => ['Tuition Fee','Library Fee','Transport Fee','Admission Fee'],
                'chartFound' => true];
    }

    private function spec(string $head, string $ref, string $pk, float $amt): array
    {
        return [
            'op'       => 'set',
            'demandId' => 'DEM_STU9001_' . str_replace('-', '', $pk) . '_' . $ref,
            'data'     => [
                'studentId' => 'STU9001', 'feeHead' => $head, 'feeHeadId' => $ref,
                'feeHeadRef' => $ref, 'periodKey' => $pk, 'category' => 'academic',
                'grossAmount' => $amt, 'discountAmount' => 0.0,
                'netAmount' => $amt, 'balance' => $amt, 'status' => 'unpaid',
            ],
        ];
    }

    /** A lifecycle double returning the canonical spec set. */
    private function lifecycle(array $built)
    {
        return new class($built) {
            private array $built;
            public function __construct(array $b) { $this->built = $b; }
            public function buildAdmissionDemandSpecs($sid, $c, $s, $preview = false): array
            {
                return $this->built;
            }
        };
    }

    /** The real entitlement shape the F-3 picker emits: scope `head:<ref>`. */
    private function reader(array $ents)
    {
        return new class($ents) {
            private array $ents;
            public function __construct(array $e) { $this->ents = $e; }
            public function activeConcessionsForStudent($school, $student, $session): array
            {
                return $this->ents;
            }
            public function appliesTo(array $e, string $pk, string $headId, string $head,
                                      string $cat = '', string $ref = ''): bool
            {
                $scope = (string) ($e['scope'] ?? '');
                if (strpos($scope, 'head:') === 0) {
                    $want = substr($scope, 5);
                    return $want !== '' && ($want === $ref || $want === $headId);
                }
                return $scope === 'all';
            }
            public function deductionType(array $e): string  { return (string) ($e['valueType'] ?? 'percent'); }
            public function deductionValue(array $e): float  { return (float) ($e['value'] ?? 0); }
        };
    }

    private function service($lifecycle, $reader): Fee_generation_service
    {
        $s = new Fee_generation_service();
        $s->init($lifecycle, $reader, null, self::SCHOOL, self::SESSION);
        return $s;
    }

    private function totals(array $out): array
    {
        $g = 0.0; $d = 0.0; $n = 0.0;
        foreach ($out['specs'] as $e) {
            $g += (float) ($e['data']['grossAmount']    ?? 0);
            $d += (float) ($e['data']['discountAmount'] ?? 0);
            $n += (float) ($e['data']['netAmount']      ?? 0);
        }
        return ['gross' => round($g, 2), 'discount' => round($d, 2), 'net' => round($n, 2),
                'count' => count($out['specs'])];
    }

    // ══ THE HEADLINE — the exact live failure, now correct ═════════════════

    /**
     * STU0185's real shape: 10% on Tuition only. The legacy path produced
     * ₹11,200 / ₹0. The canonical path must produce ₹11,200 / ₹720 / ₹10,480.
     */
    public function test_an_entitled_student_is_priced_not_billed_at_gross(): void
    {
        $ent = [['entitlementId' => 'SE_1', 'scope' => 'head:' . self::HEAD,
                 'valueType' => 'percent', 'value' => 10.0, 'status' => 'active']];

        $out = $this->service($this->lifecycle($this->specs()), $this->reader($ent))
                    ->generateDemandsForStudent('STU9001', 'Class 9th', 'Section A');

        $t = $this->totals($out);

        $this->assertSame(37, $t['count'], 'the canonical path emits the same 37 charges');
        $this->assertSame(11200.0, $t['gross'], 'gross is unchanged — a concession never alters the charge');
        $this->assertSame(720.0, $t['discount'],
            'THE DEFECT: the legacy path produced 0.00 here while the entitlement read "active"');
        $this->assertSame(10480.0, $t['net']);
    }

    /** Only the entitled head is discounted — Library/Transport/Admission are not. */
    public function test_only_the_scoped_head_is_discounted(): void
    {
        $ent = [['entitlementId' => 'SE_1', 'scope' => 'head:' . self::HEAD,
                 'valueType' => 'percent', 'value' => 10.0, 'status' => 'active']];

        $out = $this->service($this->lifecycle($this->specs()), $this->reader($ent))
                    ->generateDemandsForStudent('STU9001', 'Class 9th', 'Section A');

        foreach ($out['specs'] as $e) {
            $d = $e['data'];
            $disc = (float) ($d['discountAmount'] ?? 0);
            if ((string) $d['feeHeadRef'] === self::HEAD) {
                $this->assertSame(60.0, $disc, '10% of a 600 tuition charge');
            } else {
                $this->assertSame(0.0, $disc,
                    "a head-scoped concession must not touch '{$d['feeHead']}'");
            }
        }
    }

    // ══ THE INVARIANT HOLDS THROUGHOUT ═════════════════════════════════════

    public function test_every_priced_spec_satisfies_net_equals_gross_minus_discount(): void
    {
        $ent = [['entitlementId' => 'SE_1', 'scope' => 'head:' . self::HEAD,
                 'valueType' => 'percent', 'value' => 10.0, 'status' => 'active']];

        $out = $this->service($this->lifecycle($this->specs()), $this->reader($ent))
                    ->generateDemandsForStudent('STU9001', 'Class 9th', 'Section A');

        foreach ($out['specs'] as $e) {
            $d = $e['data'];
            $this->assertEqualsWithDelta(
                (float) $d['grossAmount'] - (float) $d['discountAmount'],
                (float) $d['netAmount'], 0.005,
                'money coherence must hold on every emitted spec, not just in aggregate');
        }
    }

    // ══ NO ENTITLEMENT ⇒ BYTE-IDENTICAL TO LEGACY ══════════════════════════

    /**
     * The overwhelming common case. Routing must not change the money for a
     * student who has no concession at all.
     */
    public function test_a_student_without_entitlements_is_unchanged(): void
    {
        $out = $this->service($this->lifecycle($this->specs()), $this->reader([]))
                    ->generateDemandsForStudent('STU9001', 'Class 9th', 'Section A');

        $t = $this->totals($out);
        $this->assertSame(37, $t['count']);
        $this->assertSame(11200.0, $t['gross']);
        $this->assertSame(0.0, $t['discount'], 'no concession ⇒ no deduction');
        $this->assertSame(11200.0, $t['net']);
    }

    // ══ FIXED AND STACKED ══════════════════════════════════════════════════

    public function test_a_fixed_entitlement_deducts_a_flat_amount_per_charge(): void
    {
        $ent = [['entitlementId' => 'SE_F', 'scope' => 'head:' . self::HEAD,
                 'valueType' => 'fixed', 'value' => 100.0, 'status' => 'active']];

        $out = $this->service($this->lifecycle($this->specs()), $this->reader($ent))
                    ->generateDemandsForStudent('STU9001', 'Class 9th', 'Section A');

        $this->assertSame(1200.0, $this->totals($out)['discount'],
            '100 off each of 12 tuition charges');
    }

    /** Two grants on the same head stack — neither is silently discarded. */
    public function test_stacked_entitlements_both_apply(): void
    {
        $ents = [
            ['entitlementId' => 'SE_1', 'scope' => 'head:' . self::HEAD,
             'valueType' => 'percent', 'value' => 10.0, 'status' => 'active'],
            ['entitlementId' => 'SE_2', 'scope' => 'head:' . self::HEAD,
             'valueType' => 'fixed', 'value' => 50.0, 'status' => 'active'],
        ];

        $out = $this->service($this->lifecycle($this->specs()), $this->reader($ents))
                    ->generateDemandsForStudent('STU9001', 'Class 9th', 'Section A');

        // 12 × (60 + 50) = 1,320. Discarding either grant would void an
        // approved concession — the failure mode DEC-004 rejected.
        $this->assertSame(1320.0, $this->totals($out)['discount']);
    }

    // ══ A DISCOUNT CAN NEVER EXCEED THE CHARGE ═════════════════════════════

    public function test_an_oversized_fixed_grant_cannot_produce_a_negative_net(): void
    {
        $ent = [['entitlementId' => 'SE_BIG', 'scope' => 'head:' . self::HEAD,
                 'valueType' => 'fixed', 'value' => 5000.0, 'status' => 'active']];

        $out = $this->service($this->lifecycle($this->specs()), $this->reader($ent))
                    ->generateDemandsForStudent('STU9001', 'Class 9th', 'Section A');

        foreach ($out['specs'] as $e) {
            $this->assertGreaterThanOrEqual(0.0, (float) $e['data']['netAmount'],
                'a school can never owe a family money through a concession');
            $this->assertLessThanOrEqual(
                (float) $e['data']['grossAmount'] + 0.005,
                (float) $e['data']['discountAmount'],
                'a discount may never exceed the charge it applies to');
        }
    }

    // ══ NO CHART ⇒ NOTHING ═════════════════════════════════════════════════

    public function test_no_chart_produces_no_specs(): void
    {
        $empty = ['specs' => [], 'assigned' => [], 'chartFound' => false];
        $out   = $this->service($this->lifecycle($empty), $this->reader([]))
                      ->generateDemandsForStudent('STU9001', 'Class 9th', 'Section A');

        $this->assertFalse($out['chartFound']);
        $this->assertSame([], $out['specs']);
    }
}
