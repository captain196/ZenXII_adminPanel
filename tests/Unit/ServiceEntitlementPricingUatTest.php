<?php

use PHPUnit\Framework\TestCase;

foreach (['Fee_entitlement_reader', 'Service_entitlement_pricing'] as $__c) {
    if (!class_exists($__c)) require_once APPPATH . 'libraries/' . $__c . '.php';
}

/**
 * A reader whose CANDIDATE SET is supplied by the test, and whose matching
 * logic is the real one.
 *
 * Only `activeConcessionsForStudent()` — the Firestore query — is replaced.
 * `appliesTo()`, `deductionType()` and `deductionValue()` are inherited
 * unchanged, so every scope rule, the I3 service invariant and the whole
 * effective-window comparison are the production implementations.
 *
 * That distinction is the point of this file: a hand-written double of
 * `appliesTo()` would prove only that the double agrees with itself, which is
 * exactly how `Phase156LateFeeEngineTest` stayed green through a real defect.
 */
class UatFakeEntitlementReader extends Fee_entitlement_reader
{
    public array $candidates = [];

    public function activeConcessionsForStudent(string $schoolId, string $studentId, string $session): array
    {
        return $this->candidates;
    }
}

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  USE_ENTITLEMENT_SERVICE_PRICING — UAT DRY RUN, NO TENANT TOUCHED
 * ══════════════════════════════════════════════════════════════════════════
 *
 * The flag is FALSE and stays FALSE. This file exercises the code path that a
 * rollout would switch on, so the UAT that follows an authorization is a
 * confirmation rather than a discovery.
 *
 * ── WHY NOT AGAINST A REAL TENANT ─────────────────────────────────────────
 * Creating a service entitlement means a WRITE against a live tenant. The
 * standing policy is explicit after an incident where a probe deleted live
 * configuration, so `SCH_B56BB9A401` is not touched. Everything below is driven
 * in-process against the real pricing and matching code.
 *
 * ── WHAT A ROLLOUT WOULD ACTIVATE ─────────────────────────────────────────
 * With the flag on, `Fee_lifecycle::createModuleFee()` routes a Transport or
 * Hostel charge through `Service_entitlement_pricing::priceFor()` before
 * writing the demand. With it off, `$net === $amount` and the demand is
 * byte-identical to today — which is why the flag being FALSE is inert rather
 * than broken.
 *
 * The residual it leaves open (SVC-1) is the reason the packet recommends
 * rollout: a school can approve transport concessions and bill every one at
 * full fare. That is now logged per demand, but the family is still overcharged.
 */
class ServiceEntitlementPricingUatTest extends TestCase
{
    private const SCHOOL  = 'SCH_UATDRY';
    private const SESSION = '2026-27';
    private const STUDENT = 'STU0012';

    private UatFakeEntitlementReader $reader;
    private Service_entitlement_pricing $pricing;

    protected function setUp(): void
    {
        $this->reader  = new UatFakeEntitlementReader();
        $this->pricing = new Service_entitlement_pricing();
        $this->pricing->init($this->reader, self::SCHOOL, self::SESSION);
    }

    /** A concession as `Entitlement_service` actually stores one. */
    private function ent(array $over = []): array
    {
        return array_merge([
            'kind'          => 'concession',
            'status'        => 'active',
            'scope'         => 'category:transport',
            'valueType'     => 'percent',
            'value'         => 25.0,
            'effectiveFrom' => '2026-04-01',
            'effectiveTo'   => null,
        ], $over);
    }

    private function price(array $ents, string $category = 'transport',
                          string $head = 'Transport Fee', string $period = '2026-04',
                          float $gross = 12000.0, string $ref = ''): array
    {
        $this->reader->candidates = $ents;
        return $this->pricing->priceFor(self::STUDENT, $category, $head, $period, $gross, $ref);
    }

    // ══ CONTROL — THE FLAG IS STILL OFF ═════════════════════════════════════

    /**
     * The whole point of the dry run. If the flag were already true this file
     * would be certifying live behaviour, not a rehearsal.
     */
    public function test_control_the_flag_is_still_false(): void
    {
        $cfg = (string) file_get_contents(APPPATH . 'config/fees_exemption_v2_flags.php');

        // ── THE ROLLOUT HAPPENED — FRAMING RE-DERIVED (2026-08-30) ─────────
        // This asserted the flag was still false, and said so explicitly: "if a
        // rollout has happened, this file becomes a live-behaviour certification
        // and its framing must be re-derived." It has, so it is.
        //
        // SVC-1 was the reason. With the flag off, an ACTIVE service-scoped
        // concession was silently ignored and the family paid the full fare —
        // measured on a clean tenant as net=1000, discount=0 against a ₹1000
        // transport fare. That is a correctness defect for a school with no
        // legacy data, not a migration posture, so the flag now ships ON.
        //
        // This suite therefore certifies LIVE behaviour rather than inertness.
        // What must not regress is the pair: the flag is on, AND invariant I3
        // still refuses a blanket concession against a service head — verified
        // end to end in scripts/service_entitlement_proof.js (16/16).
        $this->assertMatchesRegularExpression(
            "/'USE_ENTITLEMENT_SERVICE_PRICING'\s*\]\s*=\s*true/i", $cfg,
            'USE_ENTITLEMENT_SERVICE_PRICING must ship ON: a service concession that is '
            . 'approved but never applied bills the family in full, silently (SVC-1).');

        $reader = (string) file_get_contents(APPPATH . 'libraries/Fee_entitlement_reader.php');
        $this->assertStringContainsString("SERVICE_CATEGORIES = ['transport', 'hostel', 'meals']", $reader,
            'invariant I3 lost its service-category list — with service pricing ON, a blanket '
            . "scope:'all' concession would start discounting bus and hostel fares");
    }

    /** No entitlement at all ⇒ gross, which is today's behaviour for everyone. */
    public function test_control_a_student_with_no_entitlement_pays_gross(): void
    {
        $r = $this->price([]);

        $this->assertSame(12000.0, $r['net']);
        $this->assertSame(0.0, $r['deduction']);
        $this->assertSame(0, $r['applied']);
    }

    // ══ E · TRANSPORT AND HOSTEL ════════════════════════════════════════════

    public function test_a_transport_scoped_concession_discounts_a_transport_fare(): void
    {
        $r = $this->price([$this->ent()]);

        $this->assertSame(9000.0, $r['net'], 'a 25% transport concession did not reach the fare');
        $this->assertSame(3000.0, $r['deduction']);
        $this->assertSame(1, $r['applied']);
    }

    public function test_a_hostel_scoped_concession_discounts_a_hostel_charge(): void
    {
        $r = $this->price(
            [$this->ent(['scope' => 'category:hostel', 'value' => 50.0])],
            'hostel', 'Hostel Fee', '2026-04', 40000.0
        );

        $this->assertSame(20000.0, $r['net']);
        $this->assertSame(20000.0, $r['deduction']);
    }

    /** Cross-service leakage: a transport grant must not touch a hostel bill. */
    public function test_a_transport_concession_does_not_discount_hostel(): void
    {
        $r = $this->price([$this->ent()], 'hostel', 'Hostel Fee', '2026-04', 40000.0);

        $this->assertSame(40000.0, $r['net'],
            'a transport-scoped concession reduced a HOSTEL charge. Scopes would be '
            . 'decorative and one grant would discount every service.');
        $this->assertSame(0, $r['applied']);
    }

    // ══ E · INVARIANT I3 — THE ONE THAT PROTECTS REAL COSTS ═════════════════

    /**
     * Transport, hostel and meals are third-party costs the school actually
     * pays out. A blanket "all fees 50%" concession must never touch them.
     */
    public function test_a_blanket_all_scope_concession_cannot_discount_a_service(): void
    {
        foreach (['transport', 'hostel', 'meals'] as $service) {
            $r = $this->price([$this->ent(['scope' => 'all', 'value' => 50.0])],
                              $service, ucfirst($service) . ' Fee', '2026-04', 12000.0);

            $this->assertSame(12000.0, $r['net'],
                "invariant I3 failed for '{$service}': a blanket concession halved a service "
                . 'charge the school pays a third party for');
            $this->assertSame(0, $r['applied']);
        }
    }

    /** But a head-scoped grant IS a deliberate service concession and must apply. */
    public function test_a_head_scoped_grant_still_reaches_a_service_charge(): void
    {
        $r = $this->price([$this->ent(['scope' => 'head:Transport Fee', 'value' => 10.0])]);

        $this->assertSame(10800.0, $r['net'],
            'a head-scoped transport grant was refused. I3 must exclude only BLANKET scopes, '
            . 'not deliberately service-targeted ones.');
    }

    /**
     * DEC-011 — the registry ref must be forwarded, or a `head:FH_…` grant (the
     * only shape the current picker emits) matches nothing and the student is
     * billed full fare.
     */
    public function test_a_registry_ref_scoped_grant_matches_via_the_forwarded_ref(): void
    {
        $r = $this->price(
            [$this->ent(['scope' => 'head:FH_TRANSPORT01', 'value' => 20.0])],
            'transport', 'Transport Fee', '2026-04', 12000.0, 'FH_TRANSPORT01'
        );

        $this->assertSame(9600.0, $r['net'],
            'a feeHeadRef-scoped entitlement did not match. DEC-011 forwards the ref precisely '
            . 'so the reader\'s first match tier can fire — without it an approved transport '
            . 'concession is silently billed at full fare.');
    }

    // ══ E · EFFECTIVE DATES, EXPIRY, REVOCATION ═════════════════════════════

    public function test_a_future_dated_concession_does_not_apply_yet(): void
    {
        $r = $this->price([$this->ent(['effectiveFrom' => '2026-09-01'])]);

        $this->assertSame(12000.0, $r['net'],
            'a concession starting in September discounted April');
    }

    public function test_an_expired_concession_no_longer_applies(): void
    {
        $r = $this->price([$this->ent(['effectiveTo' => '2026-03-31'])]);

        $this->assertSame(12000.0, $r['net'],
            'a concession that ended in March still discounted April');
    }

    /**
     * ENT-DISC-1's scheduled end, seen from the pricing side. A row scheduled to
     * end in September stays `status: 'active'` and keeps discounting until then
     * — which is the whole reason that fix works without a query change.
     */
    public function test_a_scheduled_end_keeps_discounting_until_the_date(): void
    {
        $scheduled = $this->ent(['effectiveTo' => '2026-09-30', 'scheduledEnd' => true]);

        $this->assertSame(9000.0, $this->price([$scheduled], 'transport', 'Transport Fee', '2026-04')['net'],
            'a concession scheduled to end in September stopped discounting April');
        $this->assertSame(12000.0, $this->price([$scheduled], 'transport', 'Transport Fee', '2026-11')['net'],
            'a concession scheduled to end in September still discounted November');
    }

    /** An immediate revocation removes the row from the candidate set entirely. */
    public function test_an_immediately_revoked_concession_is_not_a_candidate(): void
    {
        // What the reader would return after discontinue(): nothing, because
        // its query filters status == 'active'.
        $r = $this->price([]);

        $this->assertSame(12000.0, $r['net']);
    }

    // ══ E · REPEATED GENERATION, ROLLOVER, FARE CHANGE ══════════════════════

    /** Pricing is pure: the same inputs must always give the same answer. */
    public function test_repeated_generation_prices_identically(): void
    {
        $ents = [$this->ent()];

        $first  = $this->price($ents);
        $second = $this->price($ents);
        $third  = $this->price($ents);

        $this->assertSame($first, $second, 'repeated generation produced a different price');
        $this->assertSame($second, $third,
            'pricing is not idempotent, so a reconcile or rollover would compound the discount');
    }

    /**
     * Rollover moves the demand to the next session's period. A concession with
     * no end date follows; one that ended does not.
     */
    public function test_rollover_carries_an_open_ended_concession_and_drops_a_closed_one(): void
    {
        $open   = $this->ent();
        $closed = $this->ent(['effectiveTo' => '2027-03-31']);

        $this->assertSame(9000.0, $this->price([$open], 'transport', 'Transport Fee', '2027-04')['net'],
            'an open-ended concession stopped applying after rollover');
        $this->assertSame(12000.0, $this->price([$closed], 'transport', 'Transport Fee', '2027-04')['net'],
            'a concession that ended in March 2027 still discounted the 2027-28 session');
    }

    /** A fare change re-prices from the NEW gross, not a remembered one. */
    public function test_a_fare_change_discounts_the_new_fare(): void
    {
        $ents = [$this->ent()];

        $this->assertSame(9000.0,  $this->price($ents, 'transport', 'Transport Fee', '2026-04', 12000.0)['net']);
        $this->assertSame(13500.0, $this->price($ents, 'transport', 'Transport Fee', '2026-04', 18000.0)['net'],
            'an upgraded route was discounted against the OLD fare — the PRORATE-1 shape, one '
            . 'layer up');
    }

    // ══ VALUE TYPES AND STACKING ════════════════════════════════════════════

    public function test_a_fixed_value_concession_deducts_that_amount(): void
    {
        $r = $this->price([$this->ent(['valueType' => 'fixed', 'value' => 1500.0])]);

        $this->assertSame(10500.0, $r['net']);
    }

    public function test_a_full_exemption_zeroes_the_charge(): void
    {
        $r = $this->price([$this->ent(['valueType' => 'fullExempt'])]);

        $this->assertSame(0.0, $r['net']);
        $this->assertSame(12000.0, $r['deduction'],
            'a full exemption must record the whole charge as the concession, or contra-revenue '
            . 'understates what the school forwent');
    }

    /** Two grants stack, and the net can never go negative. */
    public function test_stacked_concessions_never_produce_a_negative_charge(): void
    {
        $r = $this->price([
            $this->ent(['value' => 60.0]),
            $this->ent(['value' => 60.0, 'scope' => 'head:Transport Fee']),
        ]);

        $this->assertSame(0.0, $r['net'],
            'stacked concessions produced a negative charge, inverting a fare into a credit');
        $this->assertSame(12000.0, $r['deduction'],
            'the recorded deduction must be the CLAMPED figure so gross - deduction == net');
        $this->assertSame(2, $r['applied']);
    }

    /** An unsupported value type contributes nothing — bill gross, the safe direction. */
    public function test_an_unsupported_value_type_contributes_nothing(): void
    {
        $r = $this->price([$this->ent(['valueType' => 'somethingNew'])]);

        $this->assertSame(12000.0, $r['net'],
            'an unrecognised value type guessed a discount instead of billing gross');
    }

    // ══ FAIL-SAFE BEHAVIOUR ═════════════════════════════════════════════════

    /**
     * If the reader throws, the charge must be billed at GROSS rather than
     * discounted on a guess or refused outright — a transport bill that fails
     * to generate is worse than one that is not discounted.
     */
    public function test_a_reader_failure_bills_gross_rather_than_guessing(): void
    {
        $exploding = new class extends Fee_entitlement_reader {
            public function activeConcessionsForStudent(string $s, string $st, string $se): array
            {
                throw new \RuntimeException('firestore down');
            }
        };
        $p = new Service_entitlement_pricing();
        $p->init($exploding, self::SCHOOL, self::SESSION);

        $r = $p->priceFor(self::STUDENT, 'transport', 'Transport Fee', '2026-04', 12000.0);

        $this->assertSame(12000.0, $r['net'],
            'a reader failure changed the price. Billing gross is recoverable; billing a '
            . 'guessed discount is not.');
    }

    /** A zero or negative gross is not a charge and must not be priced. */
    public function test_a_non_positive_gross_is_returned_untouched(): void
    {
        $this->assertSame(0.0, $this->price([$this->ent()], 'transport', 'Transport Fee', '2026-04', 0.0)['net']);
    }
    /**
     * STACK-1 on the service path. The clamp was already correct; the silence
     * was not, and the detector had been added to the tuition path only. Found
     * by actually exercising this path rather than by reading it.
     */
    public function test_a_service_concession_beyond_the_fare_is_reported(): void
    {
        $src = (string) file_get_contents(APPPATH . 'libraries/Service_entitlement_pricing.php');

        $this->assertStringContainsString('if ($deduction > $gross + 0.005) {', $src,
            'service concessions exceeding the fare are clamped silently. The family is billed '
            . 'zero, which is correct, but the surplus grant is recorded nowhere — so revoking '
            . 'one of two overlapping grants appears to do nothing.');
        $this->assertStringContainsString('ACC_CONCESSION_EXCEEDS_CHARGE', $src);
    }

    /** And the clamp itself must survive the addition. */
    public function test_the_service_clamp_is_unchanged(): void
    {
        $src = (string) file_get_contents(APPPATH . 'libraries/Service_entitlement_pricing.php');

        $this->assertStringContainsString('$net = max(0.0, round($gross - $deduction, 2));', $src,
            'the zero floor was removed while adding the detector — stacked service concessions '
            . 'could now produce a negative fare');
    }
}
