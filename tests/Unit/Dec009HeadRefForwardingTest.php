<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Fee_entitlement_reader.php';
require_once __DIR__ . '/../../application/libraries/Fee_concession_reader.php';
require_once __DIR__ . '/../../application/libraries/Fee_generation_service.php';

/**
 * DEC-009 — the production pricing path must FORWARD the school-scoped
 * feeHeadRef into appliesTo().
 *
 * THE DEFECT THIS CLOSES (proven live 2026-08-18, student STU0184).
 * `Fee_generation_service::generateDemandsForStudent()` built its applicability
 * closure with a `$ref` parameter and then called `appliesTo()` with only FIVE
 * arguments, discarding it. The comment justifying that described
 * `$this->concessionReader` as the legacy reader — but the property holds
 * WHICHEVER reader `Fee_lifecycle` injected, and under USE_ENTITLEMENT_BILLING
 * that is `Fee_entitlement_reader`, whose scope is the `head:<feeHeadRef>`
 * string and whose FIRST match tier is the ref.
 *
 * Consequence: a head-scoped entitlement captured through the F-3 picker —
 * which emits `head:FH_…` — matched NOTHING. The demand was billed at gross,
 * the entitlement showed `active`, and nothing logged an error. A silent
 * overcharge, invisible from every surface.
 *
 * WHY THE SHADOW DID NOT CATCH IT. `_shadowCompare()` builds its OWN closure
 * that forwards the ref correctly, so the two paths differed by construction —
 * and the shadow is off by default, so the diff never ran.
 *
 * The tests below fail against the pre-fix implementation.
 */
class Dec009HeadRefForwardingTest extends TestCase
{
    private const SCHOOL = 'SCH_B56BB9A401';
    private const SESS   = '2026-27';
    private const REF    = 'FH_DF3C200439E2';   // registry identity — what the entitlement scopes to
    private const ROWID  = 'FH_3F2B4F85161B';   // per-chart-row feeHeadId — deliberately DIFFERENT

    /** The live UAT entitlement: 10% off Tuition, scoped by registry ref. */
    private function entitlement(): array
    {
        return [
            'entitlementId' => 'SCH_B56BB9A401_STU0184_E474b1368a0f56fece572',
            'schoolId'      => self::SCHOOL,
            'studentId'     => 'STU0184',
            'kind'          => 'concession',
            'scope'         => 'head:' . self::REF,
            'valueType'     => 'percent',
            'value'         => 10.0,
            'effectiveFrom' => '2026-04-01',
            'effectiveTo'   => null,
            'session'       => self::SESS,
            'status'        => 'active',
        ];
    }

    /** The live chart for Class 9th / Section A, as specs. */
    private function specs(): array
    {
        $heads = [
            ['Tuition Fee',   600.0, 'academic',  self::REF,          self::ROWID],
            ['Library Fee',    50.0, 'academic',  'FH_088BB5D74C9F',  'FH_LIB_ROW'],
            ['Transport Fee', 200.0, 'transport', 'FH_E038A430DF0D',  'FH_TRN_ROW'],
        ];
        $specs = [];
        foreach ($heads as [$name, $amt, $cat, $ref, $rowId]) {
            for ($i = 0; $i < 12; $i++) {
                $m = (($i + 3) % 12) + 1;
                $y = ($m >= 4) ? 2026 : 2027;
                $specs[] = ['data' => [
                    'feeHead'     => $name,   'feeHeadId'  => $rowId,
                    'feeHeadRef'  => $ref,    'category'   => $cat,
                    'periodKey'   => sprintf('%04d-%02d', $y, $m),
                    'grossAmount' => $amt,    'netAmount'  => $amt,
                    'balance'     => $amt,    'paidAmount' => 0.0,
                    'status'      => 'unpaid',
                ]];
            }
        }
        $specs[] = ['data' => [
            'feeHead'     => 'Admission Fee', 'feeHeadId' => 'FH_ADM_ROW',
            'feeHeadRef'  => 'FH_1DDD704466C5', 'category' => 'academic',
            'periodKey'   => '2026-04', 'grossAmount' => 1000.0, 'netAmount' => 1000.0,
            'balance'     => 1000.0, 'paidAmount' => 0.0, 'status' => 'unpaid',
        ]];
        return ['chartFound' => true, 'specs' => $specs,
                'assigned' => ['Tuition Fee', 'Library Fee', 'Transport Fee', 'Admission Fee']];
    }

    private function lifecycle(array $built)
    {
        return new class($built) {
            private array $built;
            public function __construct(array $b) { $this->built = $b; }
            public function buildAdmissionDemandSpecs($s, $c, $sec, $preview = false): array
            {
                return $this->built;
            }
        };
    }

    /** A Fee_entitlement_reader whose query is stubbed to the live UAT row. */
    private function entitlementReader(array $ents)
    {
        return new class($ents) extends Fee_entitlement_reader {
            private array $ents;
            public function __construct(array $e) { $this->ents = $e; }
            public function activeConcessionsForStudent(string $sch, string $stu, string $sess): array
            {
                return $this->ents;
            }
        };
    }

    private function generate($reader, array $built): array
    {
        $svc = new Fee_generation_service();
        $svc->init($this->lifecycle($built), $reader, null, self::SCHOOL, self::SESS);
        return $svc->generateDemandsForStudent('STU0184', 'Class 9th', 'Section A');
    }

    private function totals(array $result): array
    {
        $g = 0.0; $n = 0.0; $d = 0.0; $byHead = [];
        foreach ($result['specs'] as $e) {
            $x = $e['data'];
            $g += (float) $x['grossAmount'];
            $n += (float) ($x['netAmount'] ?? $x['grossAmount']);
            $d += (float) ($x['discountAmount'] ?? 0);
            $byHead[$x['feeHead']][] = $x;
        }
        return ['gross' => $g, 'net' => $n, 'disc' => $d, 'byHead' => $byHead,
                'count' => count($result['specs'])];
    }

    // ══ THE REGRESSION ═══════════════════════════════════════════════════

    /**
     * THE headline test. Pre-fix this returns net 11200 / discount 0 — the
     * exact silent overcharge observed live.
     */
    public function test_a_head_ref_scoped_entitlement_actually_discounts(): void
    {
        $t = $this->totals($this->generate(
            $this->entitlementReader([$this->entitlement()]), $this->specs()));

        $this->assertSame(37, $t['count'], 'the full chart must still be priced');
        $this->assertEqualsWithDelta(11200.0, $t['gross'], 0.01, 'gross is never rewritten');
        $this->assertEqualsWithDelta(720.0,   $t['disc'],  0.01,
            'a head-ref-scoped entitlement MUST deduct — 12 x 60. Zero here is the DEC-009 defect.');
        $this->assertEqualsWithDelta(10480.0, $t['net'],   0.01);
    }

    /** Per-occurrence: 600 gross -> 540 net, twelve times, gross untouched. */
    public function test_every_tuition_occurrence_is_600_gross_and_540_net(): void
    {
        $t = $this->totals($this->generate(
            $this->entitlementReader([$this->entitlement()]), $this->specs()));

        $tuition = $t['byHead']['Tuition Fee'];
        $this->assertCount(12, $tuition);
        foreach ($tuition as $d) {
            $this->assertEqualsWithDelta(600.0, (float) $d['grossAmount'], 0.01,
                'grossAmount must NEVER be rewritten by an entitlement');
            $this->assertEqualsWithDelta(540.0, (float) $d['netAmount'], 0.01);
            $this->assertEqualsWithDelta(60.0,  (float) $d['discountAmount'], 0.01);
        }
    }

    /** Only the scoped head moves. Library/Transport/Admission are untouched. */
    public function test_no_other_head_is_discounted(): void
    {
        $t = $this->totals($this->generate(
            $this->entitlementReader([$this->entitlement()]), $this->specs()));

        foreach (['Library Fee' => 50.0, 'Transport Fee' => 200.0, 'Admission Fee' => 1000.0] as $h => $amt) {
            foreach ($t['byHead'][$h] as $d) {
                $this->assertEqualsWithDelta($amt, (float) $d['netAmount'], 0.01, $h . ' must not be discounted');
                $this->assertEqualsWithDelta(0.0, (float) ($d['discountAmount'] ?? 0), 0.01);
            }
        }
    }

    /**
     * THE ARGUMENT IS LOAD-BEARING. Same entitlement, same demand — the only
     * difference is whether the ref is passed. This is the defect in one
     * assertion pair.
     */
    public function test_the_ref_argument_is_what_makes_the_match_work(): void
    {
        $r = new Fee_entitlement_reader();
        $e = $this->entitlement();

        $this->assertTrue($r->appliesTo($e, '2026-04', self::ROWID, 'Tuition Fee', 'academic', self::REF),
            'with the ref, tier 1 matches');
        $this->assertFalse($r->appliesTo($e, '2026-04', self::ROWID, 'Tuition Fee', 'academic', ''),
            'without it, no tier matches — feeHeadId and name both differ from the ref');
    }

    // ══ THE SHARED CONTRACT THAT PREVENTS A RECURRENCE ═══════════════════

    /**
     * The caller cannot know which reader it holds, so the signatures must not
     * diverge. Divergence is what allowed the argument to be dropped "safely".
     */
    public function test_both_readers_expose_the_same_six_argument_contract(): void
    {
        foreach (['Fee_entitlement_reader', 'Fee_concession_reader'] as $cls) {
            $m = new ReflectionMethod($cls, 'appliesTo');
            $this->assertSame(6, $m->getNumberOfParameters(), $cls . '::appliesTo must take 6 parameters');
            $this->assertSame('feeHeadRef', $m->getParameters()[5]->getName(),
                $cls . ' — the 6th parameter must be the registry ref');
            $this->assertTrue($m->getParameters()[5]->isOptional(),
                $cls . ' — it must stay optional so existing callers are unaffected');
        }
    }

    /** The legacy reader accepts the ref and its behaviour is UNCHANGED. */
    public function test_the_legacy_reader_ignores_the_ref_and_does_not_regress(): void
    {
        $legacy = ['scope' => 'head', 'targetFeeHeadId' => self::ROWID,
                   'type' => 'percent', 'value' => 10, 'effectiveFrom' => '2026-04-01'];
        $r = new Fee_concession_reader();

        $this->assertTrue($r->appliesTo($legacy, '2026-04', self::ROWID, 'Tuition Fee', 'academic', self::REF));
        $this->assertTrue($r->appliesTo($legacy, '2026-04', self::ROWID, 'Tuition Fee', 'academic', ''),
            'passing or omitting the ref must make no difference to a legacy record');
        $this->assertFalse($r->appliesTo($legacy, '2026-04', 'FH_OTHER', 'Other Fee', 'academic', self::REF),
            'the ref must NOT become a back-door match tier for legacy records');
    }

    /** The production closure forwards it — pinned so it cannot be dropped again. */
    public function test_the_production_closure_forwards_the_ref(): void
    {
        $code = '';
        foreach (token_get_all(file_get_contents(APPPATH . 'libraries/Fee_generation_service.php')) as $t) {
            $code .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        $this->assertStringNotContainsString('appliesTo($c, $p, $hId, $h, $cat)', $code,
            'the five-argument call is the defect — it must not return');
        $this->assertSame(2, substr_count($code, '$cat, $ref)'),
            'BOTH the production and shadow closures must forward the ref');
    }

    // ══ NEIGHBOURING BEHAVIOUR MUST NOT MOVE ═════════════════════════════

    /** Other scope kinds still work — the fix is additive, not a rewrite. */
    public function test_category_and_all_scopes_are_unaffected(): void
    {
        $cat = ['scope' => 'category:academic', 'valueType' => 'percent', 'value' => 10.0,
                'kind' => 'concession', 'status' => 'active', 'effectiveFrom' => '2026-04-01',
                'effectiveTo' => null, 'session' => self::SESS];
        $t = $this->totals($this->generate($this->entitlementReader([$cat]), $this->specs()));
        // academic = Tuition (12x600) + Library (12x50) + Admission (1x1000) = 8800 @ 10%
        $this->assertEqualsWithDelta(880.0, $t['disc'], 0.01, 'category scope still deducts');
        foreach ($t['byHead']['Transport Fee'] as $d) {
            $this->assertEqualsWithDelta(200.0, (float) $d['netAmount'], 0.01,
                'transport is not academic — untouched');
        }
    }

    /** Invariant I3: a blanket concession never nets against a service head. */
    public function test_scope_all_still_excludes_service_categories(): void
    {
        $all = ['scope' => 'all', 'valueType' => 'percent', 'value' => 10.0,
                'kind' => 'concession', 'status' => 'active', 'effectiveFrom' => '2026-04-01',
                'effectiveTo' => null, 'session' => self::SESS];
        $t = $this->totals($this->generate($this->entitlementReader([$all]), $this->specs()));
        foreach ($t['byHead']['Transport Fee'] as $d) {
            $this->assertEqualsWithDelta(200.0, (float) $d['netAmount'], 0.01,
                'I3 — scope=all must never discount a service head');
        }
        $this->assertEqualsWithDelta(880.0, $t['disc'], 0.01);
    }

    /** No entitlements at all ⇒ byte-identical to the chart. */
    public function test_zero_entitlements_leaves_the_chart_untouched(): void
    {
        $t = $this->totals($this->generate($this->entitlementReader([]), $this->specs()));
        $this->assertSame(37, $t['count']);
        $this->assertEqualsWithDelta(11200.0, $t['net'], 0.01);
        $this->assertEqualsWithDelta(0.0, $t['disc'], 0.01);
    }

    /** An entitlement whose window has not opened yet must not discount. */
    public function test_effective_window_is_still_honoured(): void
    {
        $e = $this->entitlement();
        $e['effectiveFrom'] = '2027-01-01';
        $t = $this->totals($this->generate($this->entitlementReader([$e]), $this->specs()));
        // Only Jan/Feb/Mar 2027 fall inside the window: 3 x 60.
        $this->assertEqualsWithDelta(180.0, $t['disc'], 0.01);
    }
}
