<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Fee_entitlement_reader.php';
require_once __DIR__ . '/../../application/libraries/Service_entitlement_pricing.php';

/**
 * DEC-011 — the DEC-009 defect, second site: the SERVICE pricing path.
 *
 * `Service_entitlement_pricing::priceFor()` called
 * `$this->reader->appliesTo($e, $periodKey, '', $feeHead, $serviceCategory)`
 * — FIVE arguments. The reader's first match tier is `feeHeadRef`, so that tier
 * could never fire here. And because `feeHeadId` is also passed as `''` for
 * module fees, a `head:FH_…` entitlement — the ONLY shape the F-3 picker can
 * now emit — fell through all three tiers and matched NOTHING.
 *
 * CONSEQUENCE HAD PHASE D BEEN ACTIVATED: a student holding an approved,
 * active, transport-scoped concession would have been billed the FULL fare.
 * Silent: `applied=0`, no error, entitlement still reads `active`. Precisely
 * what happened to STU0184 on the tuition path under DEC-009.
 *
 * FOUND BY: auditing every remaining `appliesTo()` call site after DEC-009,
 * rather than assuming the one reported site was the only one.
 *
 * INERT WHEN FIXED: `USE_ENTITLEMENT_SERVICE_PRICING` is false globally and
 * granted to no tenant, so this changes no live billing. It is fixed now so
 * that Phase D does not activate a known silent overcharge.
 *
 * NOTE ON I3: a `scope:'all'` entitlement must STILL never discount a service
 * head. Forwarding the ref must not weaken that — pinned below.
 */
class Dec011ServicePricingRefForwardingTest extends TestCase
{
    private const REF = 'FH_E038A430DF0D';   // the live Transport Fee head

    private function reader(): Fee_entitlement_reader
    {
        return new Fee_entitlement_reader();
    }

    private function svc($reader): Service_entitlement_pricing
    {
        $p = new Service_entitlement_pricing();
        $p->init($reader, 'SCH_B56BB9A401', '2026-27');
        return $p;
    }

    /** An active, approved, head-scoped transport concession. */
    private function ent(string $scope, string $valueType = 'percent', $value = 10): array
    {
        return [
            'entitlementId' => 'SCH_B56BB9A401_STU0185_Etest',
            'schoolId'      => 'SCH_B56BB9A401',
            'studentId'     => 'STU0185',
            'kind'          => 'concession',
            'scope'         => $scope,
            'valueType'     => $valueType,
            'value'         => $value,
            'status'        => 'active',
            'effectiveFrom' => '2026-04-01',
            'effectiveTo'   => '',
            'session'       => '2026-27',
        ];
    }

    /** Reader double returning a fixed candidate set — isolates the match logic. */
    private function readerWith(array $ents)
    {
        return new class($ents) extends Fee_entitlement_reader {
            private array $ents;
            public function __construct(array $ents) { $this->ents = $ents; }
            public function activeConcessionsForStudent(string $s, string $st, string $sess): array
            {
                return $this->ents;
            }
        };
    }

    // == THE DEFECT =====================================================

    /**
     * THE HEADLINE. A registry-ref-scoped transport concession must cut the
     * fare. Pre-fix this returned net=2400, deduction=0, applied=0.
     */
    public function test_a_head_ref_scoped_entitlement_discounts_the_service_fee(): void
    {
        $svc = $this->svc($this->readerWith([$this->ent('head:' . self::REF)]));
        $r   = $svc->priceFor('STU0185', 'transport', 'Transport Fee', '2026-04', 2400.0, self::REF);

        $this->assertSame(1, $r['applied'], 'THE DEFECT: the ref tier never fired, so nothing applied');
        $this->assertSame(240.0,  $r['deduction'], '10% of 2400');
        $this->assertSame(2160.0, $r['net']);
    }

    /** Without the ref argument the match is impossible — the pre-fix behaviour. */
    public function test_omitting_the_ref_still_cannot_match_a_ref_scoped_entitlement(): void
    {
        $svc = $this->svc($this->readerWith([$this->ent('head:' . self::REF)]));
        $r   = $svc->priceFor('STU0185', 'transport', 'Transport Fee', '2026-04', 2400.0);

        $this->assertSame(0, $r['applied'],
            'this is exactly what production did before DEC-011 - kept as the contrast case');
        $this->assertSame(2400.0, $r['net'], 'billed at GROSS - the silent overcharge');
    }

    /** fullExempt through the ref tier zeroes the fare. */
    public function test_full_exemption_via_the_ref_tier(): void
    {
        $svc = $this->svc($this->readerWith([$this->ent('head:' . self::REF, 'fullExempt', 0)]));
        $r   = $svc->priceFor('STU0185', 'transport', 'Transport Fee', '2026-04', 2400.0, self::REF);
        $this->assertSame(0.0,    $r['net']);
        $this->assertSame(2400.0, $r['deduction']);
    }

    /** A fixed-amount concession through the ref tier. */
    public function test_fixed_amount_via_the_ref_tier(): void
    {
        $svc = $this->svc($this->readerWith([$this->ent('head:' . self::REF, 'fixed', 500)]));
        $r   = $svc->priceFor('STU0185', 'transport', 'Transport Fee', '2026-04', 2400.0, self::REF);
        $this->assertSame(500.0,  $r['deduction']);
        $this->assertSame(1900.0, $r['net']);
    }

    // == I3 MUST NOT BE WEAKENED ========================================

    /**
     * THE INVARIANT. A blanket 'all' concession must NEVER discount a service
     * head, ref forwarded or not. If this ever fails, the fix has leaked.
     */
    public function test_I3_a_blanket_all_concession_still_cannot_touch_a_service_fee(): void
    {
        $svc = $this->svc($this->readerWith([$this->ent('all')]));
        foreach (['transport', 'hostel', 'meals'] as $cat) {
            $r = $svc->priceFor('STU0185', $cat, 'Transport Fee', '2026-04', 2400.0, self::REF);
            $this->assertSame(0, $r['applied'], "I3 breached for {$cat}");
            $this->assertSame(2400.0, $r['net']);
        }
    }

    /** A category-scoped service concession is the deliberate opt-in and still works. */
    public function test_a_category_scoped_service_concession_still_applies(): void
    {
        $svc = $this->svc($this->readerWith([$this->ent('category:transport')]));
        $r   = $svc->priceFor('STU0185', 'transport', 'Transport Fee', '2026-04', 2400.0, self::REF);
        $this->assertSame(1, $r['applied']);
        $this->assertSame(240.0, $r['deduction']);
    }

    // == CROSS-HEAD ISOLATION ===========================================

    /** A concession scoped to ANOTHER head must not touch this fare. */
    public function test_a_different_head_ref_does_not_match(): void
    {
        $svc = $this->svc($this->readerWith([$this->ent('head:FH_DF3C200439E2')]));  // Tuition
        $r   = $svc->priceFor('STU0185', 'transport', 'Transport Fee', '2026-04', 2400.0, self::REF);
        $this->assertSame(0, $r['applied'], 'a tuition-scoped grant must not cut the bus fare');
        $this->assertSame(2400.0, $r['net']);
    }

    /** Legacy name-scoped records keep working — tier 3 is untouched. */
    public function test_legacy_name_scoped_entitlement_still_matches_by_name(): void
    {
        $svc = $this->svc($this->readerWith([$this->ent('head:Transport Fee')]));
        $r   = $svc->priceFor('STU0185', 'transport', 'Transport Fee', '2026-04', 2400.0, self::REF);
        $this->assertSame(1, $r['applied'], 'the name fallback must survive the ref forwarding');
    }

    // == FAIL-OPEN AND ZERO CASES =======================================

    /** No entitlements -> gross, untouched. */
    public function test_no_entitlement_bills_gross(): void
    {
        $r = $this->svc($this->readerWith([]))
            ->priceFor('STU0185', 'transport', 'Transport Fee', '2026-04', 2400.0, self::REF);
        $this->assertSame(2400.0, $r['net']);
        $this->assertSame(0, $r['applied']);
    }

    /** A reader that throws must fail OPEN to gross, never under-bill. */
    public function test_a_failing_reader_bills_gross(): void
    {
        $bad = new class extends Fee_entitlement_reader {
            public function activeConcessionsForStudent(string $s, string $st, string $sess): array
            {
                throw new \RuntimeException('firestore down');
            }
        };
        $r = $this->svc($bad)->priceFor('STU0185', 'transport', 'Transport Fee', '2026-04', 2400.0, self::REF);
        $this->assertSame(2400.0, $r['net'], 'fail OPEN - under-billing is the unsafe direction');
        $this->assertSame(0.0, $r['deduction']);
    }

    /** The deduction can never exceed the gross. */
    public function test_deduction_is_clamped_to_gross(): void
    {
        $svc = $this->svc($this->readerWith([$this->ent('head:' . self::REF, 'fixed', 99999)]));
        $r   = $svc->priceFor('STU0185', 'transport', 'Transport Fee', '2026-04', 2400.0, self::REF);
        $this->assertSame(0.0,    $r['net'], 'net floors at zero');
        $this->assertSame(2400.0, $r['deduction'], 'deduction never exceeds gross');
    }

    // == THE CONTRACT IS THREADED END TO END ============================

    /** Every layer must carry the ref, or the chain silently breaks again. */
    public function test_the_ref_is_threaded_through_every_layer(): void
    {
        $strip = function (string $rel): string {
            $out = '';
            foreach (token_get_all(file_get_contents(APPPATH . $rel)) as $t) {
                $out .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
            }
            return $out;
        };

        $svc = $strip('libraries/Service_entitlement_pricing.php');
        $this->assertStringContainsString(
            'appliesTo($e, $periodKey, \'\', $feeHead, $serviceCategory, $feeHeadRef)', $svc,
            'priceFor must forward the ref as the 6th argument');

        // Whitespace-insensitive: this file is stored CRLF and the call is
        // wrapped across lines. Assert the ARGUMENT ORDER, not the formatting.
        $lif = $strip('libraries/Fee_lifecycle.php');
        $this->assertMatchesRegularExpression(
            '/priceFor\(\s*\$studentId,\s*strtolower\(\$moduleType\),\s*\$headName,\s*'
            . '\$periodKey,\s*\$gross,\s*\$feeHeadRef\s*\)/',
            $lif, '_priceServiceFee must forward the ref it received');
        $this->assertStringContainsString("(string) (\$moduleM5['feeHeadRef'] ?? '')", $lif,
            'createModuleFee must pass the ref resolved by the M5 registry stamp');
    }

    /** DEC-009 (the tuition site) must remain forwarded too. */
    public function test_DEC009_tuition_site_still_forwards_the_ref(): void
    {
        $src = file_get_contents(APPPATH . 'libraries/Fee_generation_service.php');
        $this->assertStringContainsString(
            'appliesTo($c, $p, $hId, $h, $cat, $ref)', $src,
            'the DEC-009 fix must not be lost');
    }
}
