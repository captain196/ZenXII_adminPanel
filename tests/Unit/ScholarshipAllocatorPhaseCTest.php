<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Scholarship_allocator.php';

/**
 * Phase C scholarship allocator — implements the APPROVED design
 * (`SCHOLARSHIP_PHASE_C_TECHNICAL_DESIGN.md` §2–§6).
 *
 * The per-period / whole-session question is decided BY THAT DOCUMENT, not here
 * and not by me. These tests pin the document's rules.
 *
 * WHAT IT FIXES — OBS-SCH-1, measured against the live chart:
 * `Fees::_generateDemandsForMonth` applies the FULL award inside every month, so
 * a ₹425 award discounts ₹5,100.01 over a 12-month session (~12×). The design:
 * *"Generation: applies `_scholarship` per month → design: one allocation over
 * the full session set."*
 *
 * AND P3D-F3 — the percentage base sums all heads regardless of frequency,
 * mixing monthly with annual (500+50+200+1000 = 1750) and distorting every
 * month's rate.
 *
 * UNWIRED: pure, zero callers. Live scholarship billing is unchanged.
 */
class ScholarshipAllocatorPhaseCTest extends TestCase
{
    /** The chart from the design document's own worked example. */
    private function designChart(): array
    {
        return [
            ['name' => 'Tuition Fee',   'amount' => 500,  'frequency' => 'monthly'],
            ['name' => 'Library Fee',   'amount' => 50,   'frequency' => 'monthly'],
            ['name' => 'Transport Fee', 'amount' => 200,  'frequency' => 'monthly'],
            ['name' => 'Admission Fee', 'amount' => 1000, 'frequency' => 'annual'],
        ];
    }

    private function spec(string $head, string $period, float $gross, array $over = []): array
    {
        return array_merge([
            'feeHead' => $head, 'periodKey' => $period, 'grossAmount' => $gross,
            'feeHeadId' => 'FH_' . strtoupper(substr(md5($head), 0, 8)),
            'status' => 'unpaid', 'paidAmount' => 0.0, 'session' => '2026-27',
        ], $over);
    }

    /** 12 monthly periods of the academic session. */
    private function months(): array
    {
        return ['2026-04','2026-05','2026-06','2026-07','2026-08','2026-09',
                '2026-10','2026-11','2026-12','2027-01','2027-02','2027-03'];
    }

    // ══ §2 · THE PERCENTAGE BASE (P3D-F3) ═══════════════════════════════

    /**
     * THE DESIGN'S OWN WORKED EXAMPLE: 10%, scope academic.
     * base = 500 + 50 = 550 ⇒ ₹55 per period. "Not ₹175."
     */
    public function test_the_percentage_base_excludes_annual_and_service_heads(): void
    {
        $base = Scholarship_allocator::percentageBase($this->designChart(), 'academic');
        $this->assertSame(550.0, $base,
            'Transport excluded as a service head; Admission excluded as annual');
        $this->assertSame(55.0, round($base * 10 / 100, 2), 'the design says 55 per period');
    }

    /** The pre-fix base — kept as the contrast case the design calls wrong. */
    public function test_the_old_base_summed_everything(): void
    {
        $all = 0.0;
        foreach ($this->designChart() as $h) $all += $h['amount'];
        $this->assertSame(1750.0, $all, 'the documented wrong base');
        $this->assertNotSame($all, Scholarship_allocator::percentageBase($this->designChart(), 'academic'));
    }

    /** scope=all pulls the service head back in, but still excludes annual. */
    public function test_scope_all_includes_service_heads_but_not_annual(): void
    {
        $this->assertSame(750.0, Scholarship_allocator::percentageBase($this->designChart(), 'all'),
            '500 + 50 + 200; the annual head stays out of a per-period base');
    }

    /** A category scope narrows to one category. */
    public function test_category_scope_narrows_the_base(): void
    {
        $this->assertSame(200.0,
            Scholarship_allocator::percentageBase($this->designChart(), 'category:transport'));
    }

    // ══ §4 · SCOPE AND CLASSIFICATION ═══════════════════════════════════

    /** The classification order is mapping → head → heuristic → default. */
    public function test_classification_follows_the_designed_order(): void
    {
        $r = Scholarship_allocator::classify('Bus Fee', [], ['bus fee' => 'academic']);
        $this->assertSame(['category' => 'academic', 'signal' => 'mapping'], $r,
            'an explicit mapping outranks everything');

        $r = Scholarship_allocator::classify('Bus Fee', ['category' => 'transport']);
        $this->assertSame('head', $r['signal'], "the head's own category outranks the heuristic");

        $r = Scholarship_allocator::classify('Bus Fee');
        $this->assertSame(['category' => 'transport', 'signal' => 'heuristic'], $r,
            'substring match is the LAST resort and is marked as such');

        $r = Scholarship_allocator::classify('Tuition Fee');
        $this->assertSame(['category' => 'academic', 'signal' => 'default'], $r);
    }

    /**
     * THE DESIGN'S HONEST GAP, carried forward not hidden: with `feeCategories`
     * empty, service exclusion rests on a substring. The signal makes that
     * visible instead of silently authoritative.
     */
    public function test_a_heuristic_answer_is_distinguishable_from_an_authoritative_one(): void
    {
        $this->assertSame('heuristic', Scholarship_allocator::classify('Transport Fee')['signal']);
        $this->assertSame('mapping',
            Scholarship_allocator::classify('Transport Fee', [], ['transport fee' => 'transport'])['signal'],
            'populating feeCategories is the real fix — a DATA task, per the design');
    }

    /** An unrecognised scope never applies — fail closed. */
    public function test_an_unrecognised_scope_matches_nothing(): void
    {
        $this->assertFalse(Scholarship_allocator::inScope('Tuition Fee', [], 'nonsense'));
    }

    /** head: scope matches by id OR name. */
    public function test_head_scope_matches_by_id_or_name(): void
    {
        $s = ['feeHeadId' => 'FH_ABC'];
        $this->assertTrue(Scholarship_allocator::inScope('Tuition Fee', $s, 'head:FH_ABC'));
        $this->assertTrue(Scholarship_allocator::inScope('Tuition Fee', $s, 'head:tuition fee'));
        $this->assertFalse(Scholarship_allocator::inScope('Tuition Fee', $s, 'head:FH_OTHER'));
    }

    // ══ §3 · ELIGIBILITY ════════════════════════════════════════════════

    public function test_only_unpaid_in_scope_specs_are_eligible(): void
    {
        $specs = [
            $this->spec('Tuition Fee',   '2026-04', 500),
            $this->spec('Transport Fee', '2026-04', 200),                                  // service
            $this->spec('Library Fee',   '2026-04', 50, ['status' => 'paid']),             // settled
            $this->spec('Library Fee',   '2026-05', 50, ['paidAmount' => 10.0]),           // partly paid
            $this->spec('Library Fee',   '2026-06', 50, ['concessionApplied' => 5.0]),     // other authority
            $this->spec('Library Fee',   '2026-07', 0),                                    // nothing to discount
            $this->spec('Library Fee',   '2026-08', 50),                                   // ELIGIBLE
        ];
        $e = Scholarship_allocator::eligibleSpecs($specs, 'academic', '2026-27');
        $this->assertCount(2, $e, 'only the April Tuition and the August Library survive');
        $this->assertSame(['Tuition Fee', 'Library Fee'], array_column($e, 'feeHead'));
    }

    /** A demand from another session is not eligible. */
    public function test_cross_session_specs_are_excluded(): void
    {
        $specs = [$this->spec('Tuition Fee', '2025-06', 500, ['session' => '2025-26'])];
        $this->assertSame([], Scholarship_allocator::eligibleSpecs($specs, 'academic', '2026-27'));
    }

    // ══ §5.2 · WHOLE-SESSION — THE OBS-SCH-1 FIX ════════════════════════

    /**
     * THE HEADLINE. A fixed award is allocated ONCE across the session, not once
     * per month. Under the legacy engine a ₹425 award produced ~₹5,100.
     */
    public function test_a_fixed_award_is_allocated_once_across_the_session(): void
    {
        $specs = [];
        foreach ($this->months() as $m) {
            $specs[] = $this->spec('Tuition Fee', $m, 500);
            $specs[] = $this->spec('Library Fee', $m, 50);
        }
        $e = Scholarship_allocator::eligibleSpecs($specs, 'academic', '2026-27');
        $r = Scholarship_allocator::allocate($e, 425.0, Scholarship_allocator::BASIS_WHOLE_SESSION);

        $this->assertSame(425.0, $r['effective'], 'the WHOLE award, once');
        $this->assertSame(425.0, round(array_sum($r['shares']), 2),
            'Σ shares == effective EXACTLY — never award ± n paise');
        $this->assertLessThan(5100.0, array_sum($r['shares']),
            'the legacy engine produced ~5,100 for this award — that is OBS-SCH-1');
    }

    /** Shares are weighted by gross, not split evenly (`/12` is rejected). */
    public function test_shares_are_weighted_by_gross_not_divided_evenly(): void
    {
        $specs = [$this->spec('Tuition Fee', '2026-04', 900),
                  $this->spec('Library Fee', '2026-04', 100)];
        $r = Scholarship_allocator::allocate($specs, 100.0, Scholarship_allocator::BASIS_WHOLE_SESSION);
        $v = array_values($r['shares']);
        sort($v);
        $this->assertSame([10.0, 90.0], $v, '9:1 by gross, not 50:50');
    }

    /** The remainder lands deterministically so re-runs cannot drift. */
    public function test_the_rounding_remainder_is_deterministic(): void
    {
        $specs = [];
        foreach (['2026-04', '2026-05', '2026-06'] as $m) $specs[] = $this->spec('Tuition Fee', $m, 333.33);
        $a = Scholarship_allocator::allocate($specs, 100.0, Scholarship_allocator::BASIS_WHOLE_SESSION);
        $b = Scholarship_allocator::allocate(array_reverse($specs), 100.0, Scholarship_allocator::BASIS_WHOLE_SESSION);
        $this->assertSame($a['shares'], $b['shares'],
            'input order must not change the outcome — generation and recalculation must agree');
        $this->assertSame(100.0, round(array_sum($a['shares']), 2));
    }

    /** Idempotent: computed from GROSS every run, never from a previous net. */
    public function test_repeated_allocation_is_idempotent(): void
    {
        $specs = [$this->spec('Tuition Fee', '2026-04', 500), $this->spec('Library Fee', '2026-04', 50)];
        $first  = Scholarship_allocator::allocate($specs, 200.0, Scholarship_allocator::BASIS_WHOLE_SESSION);
        $second = Scholarship_allocator::allocate($specs, 200.0, Scholarship_allocator::BASIS_WHOLE_SESSION);
        $this->assertSame($first, $second);
    }

    // ══ §6 · CAP AND REMAINDER ══════════════════════════════════════════

    /** An award larger than eligible fees is CAPPED; the student gets no credit. */
    public function test_an_oversized_award_is_capped_at_eligible_gross(): void
    {
        $specs = [$this->spec('Tuition Fee', '2026-04', 500)];
        $r = Scholarship_allocator::allocate($specs, 5000.0, Scholarship_allocator::BASIS_WHOLE_SESSION);
        $this->assertSame(500.0, $r['effective'], 'capped at total eligible gross');
        $this->assertSame(500.0, round(array_sum($r['shares']), 2));
        $this->assertSame(4500.0, $r['unallocated'],
            'the remainder is REPORTED so it can be recorded on the award, not silently discarded');
    }

    /** No share may exceed its own gross. */
    public function test_no_share_exceeds_its_own_gross(): void
    {
        $specs = [$this->spec('Tuition Fee', '2026-04', 500), $this->spec('Library Fee', '2026-04', 50)];
        $r = Scholarship_allocator::allocate($specs, 550.0, Scholarship_allocator::BASIS_WHOLE_SESSION);
        foreach ($specs as $s) {
            $k = $s['periodKey'] . '|' . $s['feeHeadId'];
            $this->assertLessThanOrEqual($s['grossAmount'] + 0.005, $r['shares'][$k]);
        }
    }

    /** No eligible specs, or a zero award, allocates nothing. */
    public function test_degenerate_inputs_allocate_nothing(): void
    {
        $this->assertSame([], Scholarship_allocator::allocate([], 100.0, Scholarship_allocator::BASIS_WHOLE_SESSION)['shares']);
        $this->assertSame([], Scholarship_allocator::allocate(
            [$this->spec('Tuition Fee', '2026-04', 500)], 0.0, Scholarship_allocator::BASIS_WHOLE_SESSION)['shares']);
    }

    // ══ §5.1 · PER-PERIOD (percentage) ══════════════════════════════════

    /** A per-period award applies WITHIN each period — correct once §2 fixes the base. */
    public function test_a_per_period_award_applies_within_each_period(): void
    {
        $specs = [];
        foreach (['2026-04', '2026-05'] as $m) {
            $specs[] = $this->spec('Tuition Fee', $m, 500);
            $specs[] = $this->spec('Library Fee', $m, 50);
        }
        // base 550 @10% = 55 per period, per the design's worked example.
        $r = Scholarship_allocator::allocate($specs, 55.0, Scholarship_allocator::BASIS_PER_PERIOD);
        $this->assertSame(110.0, round(array_sum($r['shares']), 2), '55 in each of the two periods');
        $this->assertSame(50.0, $r['shares']['2026-04|' . $specs[0]['feeHeadId']],
            '500/550 of 55 = 50 to Tuition');
    }

    // ══ IT IS UNWIRED AND PURE ══════════════════════════════════════════

    public function test_the_allocator_is_pure(): void
    {
        $src = file_get_contents(APPPATH . 'libraries/Scholarship_allocator.php');
        foreach (['firestore', 'firebase', 'get_instance', 'load->', 'query('] as $v) {
            $this->assertStringNotContainsString($v, $src, "must stay pure: found '{$v}'");
        }
    }

    /**
     * BOUNDARY MOVED (2026-08-19), NOT WIDENED.
     *
     * This asserted ZERO callers. `Fee_management` now calls ONE method —
     * `percentageBase()` — to fix P3D-F3, the per-period base that wrongly
     * summed annual heads. That is §2 of the design and is per-month safe.
     *
     * The APPORTIONMENT (`allocate()`) must still have no callers: it needs the
     * complete session spec set, which a per-month engine structurally cannot
     * see (design §8). Asserted separately below so adopting the base can never
     * be mistaken for adopting Phase C.
     */
    public function test_only_the_percentage_base_is_adopted(): void
    {
        $callers = [];
        foreach (array_merge(glob(APPPATH . 'controllers/*.php'),
                             glob(APPPATH . 'libraries/*.php')) as $f) {
            if (basename($f) === 'Scholarship_allocator.php') continue;
            if (strpos(file_get_contents($f), 'Scholarship_allocator') !== false) {
                $callers[] = basename($f);
            }
        }
        $this->assertSame(['Fee_management.php'], $callers,
            'only the percentage-base fix may use the allocator so far: ' . implode(', ', $callers));
    }

    /** The whole-session apportionment remains unadopted — it needs P5. */
    public function test_the_apportionment_is_not_adopted_anywhere(): void
    {
        foreach (array_merge(glob(APPPATH . 'controllers/*.php'),
                             glob(APPPATH . 'libraries/*.php')) as $f) {
            if (basename($f) === 'Scholarship_allocator.php') continue;
            $src = (string) file_get_contents($f);
            $this->assertStringNotContainsString('Scholarship_allocator::allocate', $src,
                basename($f) . ' must not call allocate() — whole-session allocation '
                . 'requires the canonical pipeline (design §8), not a per-month engine');
        }
    }

    /** And the legacy per-month engine is still the one in force. */
    public function test_the_legacy_engine_is_still_untouched(): void
    {
        $src = file_get_contents(APPPATH . 'controllers/Fees.php');
        $this->assertStringContainsString('$scholarshipTotal * $proportion', $src,
            'the legacy apportionment must remain until convergence is authorized');
    }
}
