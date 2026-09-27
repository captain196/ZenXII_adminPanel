<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  THE AMOUNT-COMPARISON MATRIX — BOTH SURFACES, ONE RULE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Fees and Admission each verify the captured amount, and each has had the
 * SAME defect at a different time:
 *
 *   Fees      — fixed as MONEY-1
 *   Admission — carried the ±₹0.50 float tolerance until 2026-08-24, on the
 *               PUBLIC unauthenticated form
 *
 * Two implementations of one money rule is how they drifted. This file drives
 * the SAME matrix through BOTH, so a future divergence fails here rather than
 * being discovered on a settlement statement.
 *
 * ── THE RULE ─────────────────────────────────────────────────────────────
 * Where the provider supplies minor units, compare EXACT INTEGERS.
 * `abs(a - b) > 0.50` must never be the primary check — it is ~50x wider than
 * the ≤₹0.01 error rounding can introduce, so a shortfall of up to fifty
 * paise passes as a match.
 *
 * ── WHY THE COMPARATORS ARE MIRRORED HERE ────────────────────────────────
 * Both live in private methods on CI3 controllers, which cannot be
 * instantiated outside the framework. Each is reproduced EXACTLY as its
 * controller defines it, and each controller is asserted to still contain
 * that definition (see the two `test_*_controller_still_defines_it` cases).
 * That is weaker than calling them directly and is stated rather than glossed.
 */
class PaymentAmountSafetyMatrixTest extends TestCase
{
    /** Mirrors Fee_management::_gatewayAmountMismatch(). */
    private function feesMismatch(float $expectedRupees, $reportedRupees, $reportedMinor): bool
    {
        if ($reportedMinor !== null && is_numeric($reportedMinor)) {
            return (int) round($expectedRupees * 100) !== (int) $reportedMinor;
        }
        return abs($expectedRupees - floatval($reportedRupees)) > 0.50;
    }

    /** Mirrors Admission_public::_assert_captured_amount()'s comparison. */
    private function admissionMismatch(float $expectedRupees, float $gwRupees, ?int $gwMinor): bool
    {
        if ($gwMinor !== null) {
            return (int) round($expectedRupees * 100) !== $gwMinor;
        }
        return abs($expectedRupees - $gwRupees) > 0.50;
    }

    /** Every case: [label, expectedRupees, reportedRupees, reportedMinor, mustBeRefused]. */
    public function amountMatrix(): array
    {
        return [
            'exact'                 => ['exact',            700.00, 700.00, 70000, false],
            'one paise short'       => ['1p short',         700.00, 699.99, 69999, true],
            'one paise excess'      => ['1p excess',        700.00, 700.01, 70001, true],
            'forty-nine paise short'=> ['49p short',        700.00, 699.51, 69951, true],
            'fifty paise short'     => ['50p short',        700.00, 699.50, 69950, true],
            'one rupee short'       => ['Rs1 short',        700.00, 699.00, 69900, true],
            'large shortfall'       => ['Rs600 short',      700.00, 100.00, 10000, true],
            'zero captured'         => ['zero',             700.00,   0.00,     0, true],
            'decimal amount exact'  => ['decimal exact',    123.45, 123.45, 12345, false],
            'decimal one paise off' => ['decimal 1p off',   123.45, 123.44, 12344, true],
            'float-imprecise exact' => ['6000 exact',      6000.00,6000.00,600000, false],
            'float-imprecise short' => ['6000 1p short',   6000.00,5999.99,599999, true],
        ];
    }

    /**
     * @dataProvider amountMatrix
     */
    public function test_fees_and_admission_agree_on_every_amount_case(
        string $label, float $expected, float $reported, ?int $minor, bool $mustRefuse
    ): void {
        $fees      = $this->feesMismatch($expected, $reported, $minor);
        $admission = $this->admissionMismatch($expected, $reported, $minor);

        $this->assertSame($mustRefuse, $fees,
            "FEES got {$label} wrong: expected " . ($mustRefuse ? 'REFUSE' : 'ACCEPT'));
        $this->assertSame($mustRefuse, $admission,
            "ADMISSION got {$label} wrong: expected " . ($mustRefuse ? 'REFUSE' : 'ACCEPT'));

        $this->assertSame($fees, $admission,
            "Fees and Admission DISAGREE on {$label}. Two surfaces applying one money rule "
            . 'differently is exactly how the ±0.50 tolerance survived on the public '
            . 'admission form for so long.');
    }

    // ══ THE CONTROL — the old comparison really was blind ════════════════

    /**
     * If this ever reports a mismatch, the defect being guarded never existed
     * and everything above is describing a problem that was not real.
     */
    public function test_control_the_old_tolerance_accepted_a_49_paise_shortfall(): void
    {
        $this->assertFalse(abs(700.00 - 699.51) > 0.50,
            'the ±₹0.50 comparison no longer accepts a 49-paise shortfall — re-derive '
            . 'MONEY-1 before trusting this file');
    }

    // ══ MALFORMED / ABSENT MINOR UNITS ═══════════════════════════════════

    /**
     * A non-numeric or absent minor amount must NOT be cast to 0 and compared
     * — that would refuse every payment. It must fall back to the tolerant
     * comparison, which is the historical behaviour.
     */
    public function test_absent_minor_units_fall_back_rather_than_refusing_everything(): void
    {
        $this->assertFalse($this->feesMismatch(700.00, 700.00, null),
            'an exact payment is refused when the provider omits minor units — every '
            . 'payment from such a provider would fail');
        $this->assertFalse($this->admissionMismatch(700.00, 700.00, null));

        // …but a real mismatch is still caught by the fallback.
        $this->assertTrue($this->feesMismatch(700.00, 600.00, null));
        $this->assertTrue($this->admissionMismatch(700.00, 600.00, null));
    }

    /** Fees additionally guards against a non-numeric minor value. */
    public function test_a_non_numeric_minor_amount_is_not_treated_as_zero(): void
    {
        // 'abc' is not numeric → must fall back, not compare against (int)'abc' = 0.
        $this->assertFalse($this->feesMismatch(700.00, 700.00, 'abc'),
            'a non-numeric minor amount was cast to 0 and compared, refusing a correct '
            . 'payment');
    }

    /** Zero minor units is a REAL value (a zero capture), not "absent". */
    public function test_zero_minor_units_is_a_real_value_not_an_absent_one(): void
    {
        $this->assertTrue($this->feesMismatch(700.00, 0.00, 0),
            'a zero capture against a ₹700 order was accepted — 0 was treated as "no '
            . 'value supplied" rather than "nothing was captured"');
        $this->assertTrue($this->admissionMismatch(700.00, 0.00, 0));
    }

    // ══ BOTH CONTROLLERS STILL DEFINE IT THIS WAY ════════════════════════

    private function executable(string $rel, string $marker, string $terminator): string
    {
        $src = (string) file_get_contents(APPPATH . $rel);
        $at  = strpos($src, $marker);
        $this->assertNotFalse($at, "{$marker} was renamed or removed");
        $end = strpos($src, $terminator, $at + 30);
        $body = substr($src, $at, ($end !== false ? $end - $at : 6000));

        $code = '';
        foreach (token_get_all('<?php ' . $body) as $t) {
            if (is_array($t)) { if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $code .= $t[1]; }
            else $code .= $t;
        }
        return $code;
    }

    public function test_fees_controller_still_defines_the_exact_comparison(): void
    {
        $code = $this->executable('controllers/Fee_management.php',
            'private function _gatewayAmountMismatch', "\n    private function ");

        $this->assertStringContainsString('$expectedMinor !== (int) $reportedMinor', $code,
            'Fees no longer compares exact minor units');
    }

    public function test_admission_controller_still_defines_the_exact_comparison(): void
    {
        $code = $this->executable('controllers/Admission_public.php',
            'private function _assert_captured_amount', "\n    public function ");

        $this->assertStringContainsString('$expectedMinor !== $gwMinor', $code,
            'Admission no longer compares exact minor units — the ±0.50 tolerance is back '
            . 'on the PUBLIC unauthenticated form');
        $this->assertStringContainsString('(int) round($expectedAmount * 100)', $code,
            'round() before the cast is gone: (int)(6000*100) can be 599999, refusing '
            . 'every payment at such an amount');
    }

    /**
     * Neither surface may use the tolerance as its PRIMARY check. It may only
     * appear as the documented fallback when minor units are unavailable.
     */
    public function test_neither_surface_uses_the_tolerance_as_the_primary_check(): void
    {
        $fees = $this->executable('controllers/Fee_management.php',
            'private function _gatewayAmountMismatch', "\n    private function ");
        $adm  = $this->executable('controllers/Admission_public.php',
            'private function _assert_captured_amount', "\n    public function ");

        foreach ([['Fees', $fees], ['Admission', $adm]] as [$label, $code]) {
            if (strpos($code, '0.50') === false) continue;   // no tolerance at all: fine

            $this->assertMatchesRegularExpression(
                '/(elseif|else\s*\{)[^}]*0\.50|Minor[^;]*;[\s\S]*0\.50/',
                $code,
                "{$label} uses the ±0.50 tolerance outside a fallback branch. It must only "
                . 'apply when the provider supplied no minor units.');
        }
    }
}
