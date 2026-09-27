<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Scholarship_allocator.php';

/**
 * P3D-F3 — the percentage base, fixed at BOTH of its occurrences.
 *
 * THE DEFECT. Two places in `Fee_management` computed a PER-PERIOD percentage
 * against a base that summed EVERY fee head regardless of frequency — mixing
 * monthly heads with the annual one:
 *
 *   scholarship award  (`$scholType === 'percentage'`)
 *   student discount   (`$discType  === 'percentage'`)
 *
 * On the real Class 9th chart that base is 500 + 50 + 200 + 1000(annual) = 1750,
 * so a 10% award computed **₹175 per period**. An annual head is billed ONCE;
 * folding it into a per-period rate inflates every month by a twelfth of a
 * charge that is not there. Service heads are excluded too — the default scope
 * is `academic`, and a scholarship does not cut a bus fare unless the school
 * says so deliberately.
 *
 * Correct base: 500 + 50 = **550 ⇒ ₹55**. That is
 * `SCHOLARSHIP_PHASE_C_TECHNICAL_DESIGN.md` §2 verbatim: *"⇒ award ₹55 per
 * period. Not ₹175."*
 *
 * WHY BOTH AT ONCE. The second occurrence was found by grepping for the pattern
 * after fixing the first. A defect that exists twice will reappear a third time
 * unless every instance is closed together — and both now call the SAME tested
 * helper, so the two bases cannot drift apart.
 *
 * SCOPE OF THIS FIX. Only the BASE. Phase C's whole-session apportionment is
 * NOT adopted here: it needs the canonical pipeline (design §8), because a
 * per-month engine structurally cannot see the complete spec set.
 *
 * SAFETY. `scholarshipAwards` is EMPTY on every tenant (measured), so no award
 * anywhere was produced by the old base, and the path only runs when the
 * operator leaves the amount blank.
 */
class P3DF3PercentageBaseTest extends TestCase
{
    /** The real Class 9th / Section A chart. */
    private function chart(): array
    {
        return [
            ['name' => 'Tuition Fee',   'amount' => 500,  'frequency' => 'monthly'],
            ['name' => 'Library Fee',   'amount' => 50,   'frequency' => 'monthly'],
            ['name' => 'Transport Fee', 'amount' => 200,  'frequency' => 'monthly'],
            ['name' => 'Admission Fee', 'amount' => 1000, 'frequency' => 'annual'],
        ];
    }

    private function code(string $rel): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents(APPPATH . $rel)) as $t) {
            $out .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        return $out;
    }

    // ══ THE DESIGN'S OWN WORKED EXAMPLE ═════════════════════════════════

    public function test_the_corrected_base_matches_the_design(): void
    {
        $base = Scholarship_allocator::percentageBase($this->chart(), 'academic');
        $this->assertSame(550.0, $base, 'monthly + in-scope only');
        $this->assertSame(55.0, round($base * 10 / 100, 2), 'the design says ₹55 per period');
    }

    /** The old base, kept as the contrast the design calls wrong. */
    public function test_the_old_base_produced_175(): void
    {
        $old = 0.0;
        foreach ($this->chart() as $h) $old += (float) $h['amount'];
        $this->assertSame(1750.0, $old);
        $this->assertSame(175.0, round($old * 10 / 100, 2),
            'the documented wrong figure — kept so the change is legible');
    }

    /** An annual head must never enter a per-period base, at any percentage. */
    public function test_the_annual_head_is_always_excluded(): void
    {
        foreach ([5, 10, 25, 100] as $pct) {
            $withAnnual = 1750.0 * $pct / 100;
            $correct    = Scholarship_allocator::percentageBase($this->chart(), 'academic') * $pct / 100;
            $this->assertLessThan($withAnnual, $correct, "at {$pct}% the annual head must not inflate the rate");
        }
    }

    /** A chart of only annual heads yields a zero per-period base — not a crash. */
    public function test_an_all_annual_chart_yields_a_zero_base(): void
    {
        $this->assertSame(0.0, Scholarship_allocator::percentageBase(
            [['name' => 'Admission Fee', 'amount' => 1000, 'frequency' => 'annual']], 'academic'));
    }

    /** An empty or malformed chart is safe. */
    public function test_a_missing_chart_is_safe(): void
    {
        $this->assertSame(0.0, Scholarship_allocator::percentageBase([], 'academic'));
        $this->assertSame(0.0, Scholarship_allocator::percentageBase([['name' => 'X']], 'academic'));
    }

    // ══ BOTH OCCURRENCES ARE FIXED, AND SHARE ONE IMPLEMENTATION ════════

    /** Neither site may sum heads inline any more. */
    public function test_neither_site_sums_every_head_inline(): void
    {
        $code = $this->code('controllers/Fee_management.php');
        $this->assertStringNotContainsString('$totalFee += floatval($h[\'amount\'] ?? 0);', $code,
            'the unfiltered inline base must be gone from BOTH sites');
        $this->assertStringNotContainsString('$totalFee = 0;', $code);
    }

    /** Both now call the one tested helper. */
    public function test_both_sites_use_the_shared_helper(): void
    {
        $code = $this->code('controllers/Fee_management.php');
        $this->assertSame(2, substr_count($code, 'Scholarship_allocator::percentageBase'),
            'exactly two call sites — the scholarship award and the student discount');
        $this->assertSame(2, substr_count($code, "libraries/Scholarship_allocator.php"),
            'each site requires the helper explicitly (no autoloader in CI3)');
    }

    /** The scholarship site computes the award from the corrected base. */
    public function test_the_scholarship_site_is_wired_correctly(): void
    {
        $code = $this->code('controllers/Fee_management.php');
        $this->assertMatchesRegularExpression(
            '/\$amount\s*=\s*round\(\s*Scholarship_allocator::percentageBase\(\$heads,\s*\'academic\'\)\s*\*\s*\$scholValue\s*\/\s*100/s',
            $code);
    }

    /** The student-discount site likewise. */
    public function test_the_student_discount_site_is_wired_correctly(): void
    {
        $code = $this->code('controllers/Fee_management.php');
        $this->assertMatchesRegularExpression(
            '/\$discountAmount\s*=\s*round\(\s*Scholarship_allocator::percentageBase\(\$heads,\s*\'academic\'\)\s*\*\s*\$discValue\s*\/\s*100/s',
            $code);
    }

    // ══ WHAT WAS DELIBERATELY NOT CHANGED ═══════════════════════════════

    /**
     * Only the BASE moved. The legacy per-month apportionment is untouched —
     * adopting Phase C's whole-session allocation needs the canonical pipeline
     * (design §8), and a flag-gated shim in a per-month engine would be a
     * per-month approximation of a whole-session algorithm: green tests, wrong money.
     */
    public function test_the_legacy_apportionment_is_untouched(): void
    {
        $this->assertStringContainsString('$scholarshipTotal * $proportion',
            (string) file_get_contents(APPPATH . 'controllers/Fees.php'),
            'apportionment still belongs to P5 convergence, not to this fix');
    }

    /** A fixed-value award is unaffected — only percentages had the defect. */
    public function test_fixed_awards_are_unaffected(): void
    {
        $code = $this->code('controllers/Fee_management.php');
        $this->assertStringContainsString('$amount = $scholValue;', $code,
            'the fixed branch must remain byte-identical');
    }
}
