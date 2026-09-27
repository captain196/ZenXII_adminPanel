<?php
/**
 * Service_entitlement_pricing::priceForPeriods — REAL ARITHMETIC.
 *
 * This is the half of TRN-CONC-PERIOD-1 that decides money. A service demand
 * is filed under the SESSION's first month whatever month service starts, and
 * appliesTo() reads that key as a real month — so a concession effective from
 * December was compared against April and silently refused. The operator saw
 * "Entitlement recorded." and the parent paid in full.
 *
 * The first attempted fix widened the match to "effective anywhere in the
 * session". That made FUTURE-dated concessions apply immediately and kept a
 * concession scheduled to end in September discounting a November period —
 * caught by ServiceEntitlementPricingUatTest, and reverted. Swapping a silent
 * under-charge for a silent over-charge is not a fix.
 *
 * What is tested here is the intersection instead:
 *
 *      SERVICE ACTIVE MONTHS  ∩  CONCESSION VALID MONTHS  =  DISCOUNTED
 *
 * Every key handed to appliesTo() is a REAL month, so effective-from and
 * effective-to are compared against the period they actually describe.
 */

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Service_entitlement_pricing.php';

final class ServicePeriodPricingTest extends TestCase
{
    /**
     * A reader with the REAL date/scope semantics, not a yes-stub.
     *
     * Stubbing appliesTo() to "true" would make every test below pass while
     * proving nothing about the windows — which is the exact failure mode this
     * whole exercise keeps running into. So this reproduces the two rules that
     * matter: the effective window, and I3.
     */
    private function reader(array $ents)
    {
        return new class($ents) {
            public function __construct(private array $ents) {}
            public function activeConcessionsForStudent($s, $stu, $sess) { return $this->ents; }

            public function appliesTo(array $e, string $periodKey, string $headId,
                                      string $head, string $category = '', string $ref = ''): bool
            {
                $scope = (string) ($e['scope'] ?? '');
                if ($scope === '') return false;

                // I3: a blanket concession may never reach a service category.
                if ($scope === 'all') {
                    if (in_array(strtolower($category), ['transport', 'hostel', 'meals'], true)) return false;
                } elseif (strpos($scope, 'category:') === 0) {
                    if (strcasecmp(substr($scope, 9), $category) !== 0) return false;
                } else {
                    return false;
                }

                if (!preg_match('/^(\d{4})-(\d{2})$/', $periodKey, $m)) return false;
                $firstOfMonth = "{$m[1]}-{$m[2]}-01";
                $lastOfMonth  = date('Y-m-t', (int) strtotime($firstOfMonth));

                $from = (string) ($e['effectiveFrom'] ?? '');
                $to   = (string) ($e['effectiveTo'] ?? '');
                if ($from === '' || strcmp($from, $lastOfMonth) > 0) return false;
                if ($to !== '' && strcmp($to, $firstOfMonth) < 0)    return false;
                return true;
            }

            public function deductionType(array $e)  { return (string) ($e['valueType'] ?? 'percent'); }
            public function deductionValue(array $e) { return (float)  ($e['value'] ?? 0); }
        };
    }

    private function svc(array $ents): Service_entitlement_pricing
    {
        $s = new Service_entitlement_pricing();
        $s->init($this->reader($ents), 'SCH_T1', '2026-27');
        return $s;
    }

    /** Aug → Mar at 1,000/month. */
    private function fares(): array
    {
        return ['2026-08' => 1000.0, '2026-09' => 1000.0, '2026-10' => 1000.0, '2026-11' => 1000.0,
                '2026-12' => 1000.0, '2027-01' => 1000.0, '2027-02' => 1000.0, '2027-03' => 1000.0];
    }

    private function price(array $ents): array
    {
        return $this->svc($ents)->priceForPeriods('STU1', 'transport', 'Transport Fee', $this->fares());
    }

    private function ent(array $over = []): array
    {
        return array_merge(['scope' => 'category:transport', 'valueType' => 'percent',
                            'value' => 50, 'effectiveFrom' => '2026-04-01', 'effectiveTo' => ''], $over);
    }

    // ══ THE DECIDED RULE: PROSPECTIVE ═══════════════════════════════════════

    /**
     * 50% effective 1 December: August–November at full fare, December–March at
     * half. Matches the runtime proof exactly — gross 8,000, discount 2,000,
     * net 6,000.
     */
    public function test_a_mid_session_concession_discounts_only_from_its_effective_date(): void
    {
        $r = $this->price([$this->ent(['effectiveFrom' => '2026-12-01'])]);

        $this->assertSame(8000.0, $r['gross']);
        $this->assertSame(2000.0, $r['deduction'], '4 months x 1000 x 50%');
        $this->assertSame(6000.0, $r['net']);
    }

    /** The defect itself: before the fix this returned zero discount. */
    public function test_a_concession_dated_after_the_session_start_is_not_silently_ignored(): void
    {
        $r = $this->price([$this->ent(['effectiveFrom' => '2026-09-15'])]);

        $this->assertGreaterThan(0.0, $r['deduction'],
            'a concession granted after April must still reach the fare — being filed under '
            . 'the session-start month is a filing detail, not an expiry');
        $this->assertSame(3500.0, $r['deduction'], 'Sep..Mar = 7 months x 500');
    }

    // ══ THE INVARIANTS THE FIRST ATTEMPT BROKE ══════════════════════════════

    /** A future-dated concession must not apply to months before it starts. */
    public function test_a_future_dated_concession_does_not_apply_early(): void
    {
        $r = $this->price([$this->ent(['effectiveFrom' => '2027-03-01'])]);

        $this->assertSame(500.0, $r['deduction'], 'March alone');
        $this->assertSame(7500.0, $r['net']);
    }

    /** An expired concession must not keep discounting after it ends. */
    public function test_an_expired_concession_stops_discounting(): void
    {
        $r = $this->price([$this->ent(['effectiveTo' => '2026-09-30'])]);

        $this->assertSame(1000.0, $r['deduction'], 'Aug and Sep only');
        $this->assertSame(7000.0, $r['net']);
    }

    /** Entirely outside the service window → nothing. */
    public function test_a_concession_outside_the_service_period_discounts_nothing(): void
    {
        $r = $this->price([$this->ent(['effectiveFrom' => '2026-04-01', 'effectiveTo' => '2026-06-30'])]);

        $this->assertSame(0.0, $r['deduction'], 'service starts in August; the concession ended in June');
        $this->assertSame(8000.0, $r['net']);
    }

    // ══ I3 ══════════════════════════════════════════════════════════════════

    public function test_a_blanket_concession_never_reaches_a_service_fee(): void
    {
        $r = $this->price([$this->ent(['scope' => 'all'])]);

        $this->assertSame(0.0, $r['deduction'],
            'I3: only a deliberately service-scoped entitlement may cut a bus fare');
        $this->assertSame(8000.0, $r['net']);
    }

    // ══ VALUE TYPES ═════════════════════════════════════════════════════════

    /**
     * A FIXED amount is deducted ONCE, not once per month. Against a single
     * demand "Rs 500 off" has always meant Rs 500; multiplying it by the months
     * would silently reprice every existing fixed concession twelve-fold.
     */
    public function test_a_fixed_amount_is_not_multiplied_by_the_months(): void
    {
        $r = $this->price([$this->ent(['valueType' => 'fixed', 'value' => 500])]);

        $this->assertSame(500.0, $r['deduction']);
        $this->assertSame(7500.0, $r['net']);
    }

    /** Full exemption zeroes the months it covers — and only those. */
    public function test_full_exemption_covers_only_the_months_it_is_effective_for(): void
    {
        $r = $this->price([$this->ent(['valueType' => 'fullExempt', 'effectiveFrom' => '2027-01-01'])]);

        $this->assertSame(3000.0, $r['deduction'], 'Jan, Feb, Mar fully exempt');
        $this->assertSame(5000.0, $r['net']);
    }

    /** A month's discount can never exceed that month's fare. */
    public function test_a_month_can_never_be_discounted_below_zero(): void
    {
        $r = $this->price([
            $this->ent(['value' => 80]),
            $this->ent(['value' => 80]),
        ]);

        $this->assertSame(8000.0, $r['deduction'], 'two 80% concessions cap at the fare, not 160%');
        $this->assertSame(0.0, $r['net'], 'net floors at zero — never negative');

        // The PER-MONTH breakdown must be capped too, not just the total. A
        // global floor alone leaves each month reporting 1,600 against a 1,000
        // fare — a figure no receipt, ledger line or parent statement could
        // ever justify, and one a total-only assertion cannot see.
        foreach ($r['months'] as $periodKey => $m) {
            $this->assertLessThanOrEqual($m['gross'], $m['deduction'],
                "month {$periodKey} was discounted by more than its own fare");
        }
    }

    // ══ SAFETY ══════════════════════════════════════════════════════════════

    /** No entitlements → gross, untouched. */
    public function test_no_entitlement_means_no_discount(): void
    {
        $r = $this->price([]);
        $this->assertSame(8000.0, $r['net']);
        $this->assertSame(0.0, $r['deduction']);
    }

    /** No billable months → nothing to bill and nothing to discount. */
    public function test_no_billable_months_bills_nothing(): void
    {
        $r = $this->svc([$this->ent()])->priceForPeriods('STU1', 'transport', 'Transport Fee', []);
        $this->assertSame(0.0, $r['gross']);
        $this->assertSame(0.0, $r['deduction']);
    }

    /**
     * Per-month fares differ after a route change; the discount must follow the
     * fare in force that month rather than an average.
     */
    public function test_the_discount_follows_the_fare_in_force_each_month(): void
    {
        $r = $this->svc([$this->ent(['effectiveFrom' => '2026-09-01'])])
                  ->priceForPeriods('STU1', 'transport', 'Transport Fee',
                                    ['2026-08' => 1000.0, '2026-09' => 2000.0]);

        $this->assertSame(3000.0, $r['gross']);
        $this->assertSame(1000.0, $r['deduction'], '50% of September\'s 2,000, not of August\'s 1,000');
        $this->assertSame(2000.0, $r['net']);
    }
}
