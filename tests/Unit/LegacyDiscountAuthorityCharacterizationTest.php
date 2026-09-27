<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

/**
 * CHARACTERIZATION of the THREE legacy discount authorities, ahead of P5/P9.
 *
 * This test changes NOTHING. It documents exactly what the legacy generator
 * does today so that convergence can be judged against measured behaviour
 * rather than assumption — the same role DD1CharacterizationTest plays.
 *
 * ── THE THREE AUTHORITIES (Fees::_getStudentDiscounts) ────────────────────
 *   1. `scholarshipAwards`        → $discounts['_scholarship'] — a POOLED,
 *                                   head-agnostic amount under a magic key
 *   2. `students.exemptedFees{}`  → $discounts[head] = -1  (full exemption)
 *   3. `students.discountHeads{}` → $discounts[head] = amount (per-head fixed)
 *
 * None of the three is visible to the entitlement path, and entitlements are
 * invisible to them — the bidirectional blindness DEC-013 names.
 *
 * ── MEASURED LIVE ESTATE (2026-08-18, ALL tenants) ────────────────────────
 *      scholarshipAwards ............ 0 documents
 *      students.exemptedFees ........ 0 students
 *      students.discountHeads ....... 0 students
 *
 * So all three authorities are **carrying no data anywhere**. That is the
 * single most useful fact for P9: retiring them requires **no migration**.
 * But `Fee_management.php:1389` still WRITES scholarshipAwards from a live UI,
 * so the paths are reachable — retirement must close the WRITE path, not just
 * the read.
 *
 * ── THE APPORTIONMENT OBSERVATION (OBS-SCH-1) ─────────────────────────────
 * The pooled scholarship is spread proportionally across the heads of EACH
 * month:
 *     $proportion      = $amount / $monthTotal;
 *     $discountAmount += round($scholarshipTotal * $proportion, 2);
 * Across one month the proportions sum to 1, so the FULL award is consumed in
 * that month — and `$scholarshipTotal` is the same value on every month's call.
 * A single award therefore discounts its full value once PER MONTH.
 *
 * This is NOT reported as a defect. Whether `amount` means "per year" or "per
 * month" is a product question the schema does not answer, there is no live
 * award anywhere to observe, and no test or document in the repo states the
 * intent. It is pinned here so convergence must confront it deliberately.
 */
class LegacyDiscountAuthorityCharacterizationTest extends TestCase
{
    /**
     * The apportionment arithmetic, copied verbatim from
     * Fees::_generateDemandsForMonth so the behaviour can be exercised.
     *
     * @param array $monthFees  head => amount for ONE month
     * @return array head => discountAmount
     */
    private function monthDiscounts(array $monthFees, array $discountMap): array
    {
        $scholarshipTotal = (float) ($discountMap['_scholarship'] ?? 0);
        $monthTotal = 0.0;
        foreach ($monthFees as $head => $amt) {
            if (($discountMap[$head] ?? 0) === -1) continue;   // exempt heads excluded
            $monthTotal += (float) $amt;
        }
        $out = [];
        foreach ($monthFees as $head => $amount) {
            $amount = (float) $amount;
            if (($discountMap[$head] ?? 0) === -1) { $out[$head] = 'EXEMPT'; continue; }
            $discount = 0.0;
            if (isset($discountMap[$head]) && $discountMap[$head] > 0) {
                $discount += (float) $discountMap[$head];
            }
            if ($scholarshipTotal > 0 && $monthTotal > 0) {
                $discount += round($scholarshipTotal * ($amount / $monthTotal), 2);
            }
            if ($discount > $amount) $discount = $amount;   // capped at the head
            $out[$head] = round($discount, 2);
        }
        return $out;
    }

    /** The real Class 9th / Section A chart. */
    private function aprilFees(): array
    {
        return ['Tuition Fee' => 600, 'Library Fee' => 50,
                'Transport Fee' => 200, 'Admission Fee' => 1000];
    }

    private function ordinaryMonthFees(): array
    {
        return ['Tuition Fee' => 600, 'Library Fee' => 50, 'Transport Fee' => 200];
    }

    // ══ AUTHORITY 1 · POOLED SCHOLARSHIP ════════════════════════════════

    /** A pooled award spreads across a month's heads in proportion to amount. */
    public function test_a_pooled_award_is_apportioned_by_head_amount(): void
    {
        $d = $this->monthDiscounts($this->ordinaryMonthFees(), ['_scholarship' => 425.0]);
        // monthTotal = 850. Tuition 600/850, Library 50/850, Transport 200/850.
        $this->assertSame(300.0, $d['Tuition Fee']);
        $this->assertSame(25.0,  $d['Library Fee']);
        $this->assertSame(100.0, $d['Transport Fee']);
        $this->assertSame(425.0, array_sum($d), 'the whole award is consumed within ONE month');
    }

    /**
     * OBS-SCH-1 — the same award is consumed AGAIN every month, because
     * `$scholarshipTotal` is the full award on every month's call.
     */
    public function test_OBS_SCH_1_the_award_is_consumed_once_per_month(): void
    {
        $award = 425.0;
        $annual = 0.0;
        // April carries the yearly heads too; the other 11 do not.
        $annual += array_sum($this->monthDiscounts($this->aprilFees(), ['_scholarship' => $award]));
        for ($m = 0; $m < 11; $m++) {
            $annual += array_sum($this->monthDiscounts($this->ordinaryMonthFees(), ['_scholarship' => $award]));
        }
        // Measured: 5100.01 against 425 x 12 = 5100.00. The one-paisa excess is
        // real and is itself part of the characterization: the apportioned share
        // is round()ed PER HEAD, so the parts need not sum back exactly to the
        // award. Over 12 months the drift accumulates. Tolerance is set to a
        // rupee so the 12x behaviour — the point of this test — is what fails if
        // it ever changes, not the rounding noise.
        $this->assertEqualsWithDelta($award * 12, $annual, 1.00,
            'a single award discounts its FULL value in each of the 12 months');
        $this->assertGreaterThan($award * 11, $annual,
            'unambiguously many times the award, not once');
    }

    /** With no award nothing is deducted — the overwhelmingly common case. */
    public function test_no_award_deducts_nothing(): void
    {
        $d = $this->monthDiscounts($this->ordinaryMonthFees(), []);
        $this->assertSame(0.0, array_sum($d));
    }

    /** An award larger than the month's total is capped per head. */
    public function test_an_oversized_award_is_capped_at_each_head(): void
    {
        $d = $this->monthDiscounts($this->ordinaryMonthFees(), ['_scholarship' => 99999.0]);
        $this->assertSame(600.0, $d['Tuition Fee'], 'never more than the head itself');
        $this->assertSame(50.0,  $d['Library Fee']);
        $this->assertSame(200.0, $d['Transport Fee']);
    }

    // ══ AUTHORITY 2 · FULL EXEMPTION ════════════════════════════════════

    /** An exempt head is dropped, and is excluded from the apportionment base. */
    public function test_an_exempt_head_is_excluded_from_the_apportionment_base(): void
    {
        $d = $this->monthDiscounts($this->ordinaryMonthFees(),
            ['_scholarship' => 250.0, 'Transport Fee' => -1]);
        $this->assertSame('EXEMPT', $d['Transport Fee']);
        // base excludes Transport: monthTotal = 650
        $this->assertSame(round(250.0 * (600 / 650), 2), $d['Tuition Fee']);
        $this->assertSame(round(250.0 * (50 / 650), 2),  $d['Library Fee']);
    }

    // ══ AUTHORITY 3 · PER-HEAD FIXED ════════════════════════════════════

    /** A per-head fixed discount STACKS on top of the pooled apportionment. */
    public function test_a_per_head_discount_stacks_with_the_pooled_award(): void
    {
        $d = $this->monthDiscounts($this->ordinaryMonthFees(),
            ['_scholarship' => 85.0, 'Tuition Fee' => 100.0]);
        // Tuition: 100 fixed + 85*(600/850)=60  → 160
        $this->assertSame(160.0, $d['Tuition Fee'],
            'the two legacy authorities ADD — they are not alternatives');
    }

    /** Stacking is still capped at the head amount. */
    public function test_stacked_legacy_discounts_are_capped(): void
    {
        $d = $this->monthDiscounts($this->ordinaryMonthFees(),
            ['_scholarship' => 800.0, 'Tuition Fee' => 500.0]);
        $this->assertSame(600.0, $d['Tuition Fee']);
    }

    // ══ THE AUTHORITIES ARE STILL SEPARATE AND STILL BLIND ══════════════

    /** All three legacy sources are read in ONE place — the convergence point. */
    public function test_the_three_authorities_share_a_single_reader(): void
    {
        $src = file_get_contents(APPPATH . 'controllers/Fees.php');
        $at  = strpos($src, 'function _getStudentDiscounts');
        $this->assertNotFalse($at);
        $seg = substr($src, $at, 1600);
        $this->assertStringContainsString('readScholarshipAwards', $seg);
        $this->assertStringContainsString("exemptedFees", $seg);
        $this->assertStringContainsString("discountHeads", $seg);
        $this->assertStringContainsString("_scholarship", $seg);
    }

    /**
     * DEC-013's core: the legacy generator still knows nothing about
     * entitlements. Pinned so convergence has to change this deliberately.
     */
    public function test_the_legacy_generator_remains_entitlement_blind(): void
    {
        $src = file_get_contents(APPPATH . 'controllers/Fees.php');
        $at  = strpos($src, 'function _getStudentDiscounts');
        $seg = substr($src, $at, 1600);
        $this->assertStringNotContainsString('Entitlement', $seg,
            'the legacy discount reader must not silently gain entitlement awareness — '
            . 'that convergence is P5 and must be deliberate');
    }

    /** The scholarship WRITE path is still live — retirement must close it. */
    public function test_the_scholarship_write_path_still_exists(): void
    {
        $this->assertStringContainsString("firestoreSet('scholarshipAwards'",
            file_get_contents(APPPATH . 'controllers/Fee_management.php'),
            'the collection is empty estate-wide but still writable from the UI, '
            . 'so P9 must close the writer, not only the reader');
    }
}
