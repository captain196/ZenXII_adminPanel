<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  SC-ACCT-1 — A COST RECOVERY MUST NOT BE BOOKED AS TUITION REVENUE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `Operations_accounting::resolve_fee_account()` picks the income account a
 * receipt credits, in three steps:
 *
 *   1. exact match in the school's `feeAccountMap`   (authoritative)
 *   2. keyword heuristics over the fee-head name
 *   3. DEFAULT: 4010 Tuition Fees
 *
 * Step 3 is the trap, and it has already bitten once. **ID-ACCT-1**: an ID-card
 * replacement head matched no keyword and fell through to 4010, *"booking a
 * consumable reimbursement as tuition revenue and inflating fee-collection
 * analytics."* It was fixed by mapping "id card" to 4140 Miscellaneous Income,
 * on the reasoning that recovering the cost of a consumable is not fee income.
 *
 * Student Charges reintroduced exactly that shape one charge type later: "Lost
 * Book", "Property Damage" and "Breakage" match nothing and would be credited
 * to Tuition Fees.
 *
 * ── WHY THIS MATTERS EVEN THOUGH Dr = Cr ──────────────────────────────────
 * The journal balances either way. ACC-PM1 already proved that a balanced
 * journal can be financially wrong — there the debit went to Cash instead of
 * Bank; here the credit goes to Tuition instead of Miscellaneous Income. In
 * both cases every arithmetic invariant is satisfied by the misclassification,
 * which is precisely why nothing caught them.
 *
 * ── HOW THIS TEST WORKS ───────────────────────────────────────────────────
 * It PARSES the real keyword table out of Operations_accounting.php and runs
 * the real resolution order over it. A reimplemented copy of the rules could
 * drift from the source and keep passing; reading the table means the source is
 * what is being tested.
 */
class FeeHeadIncomeAccountTest extends TestCase
{
    private static array $keywords = [];
    private static string $src = '';

    public static function setUpBeforeClass(): void
    {
        self::$src = (string) file_get_contents(APPPATH . 'libraries/Operations_accounting.php');

        // Pull the `'code' => ['term', ...]` rows out of the keyword table, in
        // source order — order decides which account wins.
        $at  = strpos(self::$src, "\$keywords = [");
        $end = strpos(self::$src, '];', $at);
        $table = substr(self::$src, $at, $end - $at);

        preg_match_all("/'(\d{4})'\s*=>\s*\[([^\]]*)\]/", $table, $m, PREG_SET_ORDER);
        foreach ($m as $row) {
            preg_match_all("/'([^']+)'/", $row[2], $terms);
            self::$keywords[$row[1]] = $terms[1];
        }
    }

    /** The real resolution order, over the real table. */
    private function resolve(string $feeHead): string
    {
        $headLower = strtolower(trim($feeHead));
        foreach (self::$keywords as $code => $terms) {
            foreach ($terms as $term) {
                if (strpos($headLower, $term) !== false) return $code;
            }
        }
        return '4010';   // the documented default
    }

    // ══ CONTROL — THE TABLE WAS ACTUALLY READ ═══════════════════════════════

    public function test_control_the_keyword_table_was_parsed(): void
    {
        $this->assertNotEmpty(self::$keywords,
            'no keyword rows were parsed — every assertion below would be testing an empty '
            . 'table and would pass vacuously');
        $this->assertArrayHasKey('4040', self::$keywords, 'the transport mapping is missing');
        $this->assertContains('transport', self::$keywords['4040']);
    }

    /**
     * Pins the default. If it ever stops being Tuition the severity of an
     * unmapped head changes completely, and this file should be re-derived.
     */
    public function test_control_an_unmapped_head_still_defaults_to_tuition(): void
    {
        $this->assertStringContainsString("// 3. Default: Tuition Fees (4010)", self::$src,
            'the documented default changed — re-derive what an unmapped fee head now credits');
        $this->assertSame('4010', $this->resolve('Some Unmapped Head'));
    }

    // ══ SC-ACCT-1 ═══════════════════════════════════════════════════════════

    /**
     * WOULD HAVE FAILED BEFORE: none of these matched a keyword, so all three
     * resolved to 4010 Tuition Fees.
     */
    public function test_a_cost_recovery_charge_is_not_credited_to_tuition(): void
    {
        foreach (['Lost Book', 'Property Damage', 'Breakage Charge', 'Lost Library Book'] as $head) {
            $acct = $this->resolve($head);

            $this->assertNotSame('4010', $acct,
                "'{$head}' credits 4010 Tuition Fees. Recovering the cost of a lost or "
                . 'damaged item is not income from the sale of education — booking it beside '
                . 'Tuition overstates Fee Income and inflates collection analytics, exactly '
                . 'as ID-ACCT-1 found for ID cards.');
            $this->assertSame('4140', $acct, "'{$head}' should credit Miscellaneous Income");
        }
    }

    /**
     * The ID-card precedent must keep working — this change extended its row
     * rather than replacing it.
     */
    public function test_the_id_card_precedent_is_preserved(): void
    {
        $this->assertSame('4140', $this->resolve('ID Card Replacement'));
        $this->assertSame('4140', $this->resolve('Identity Card Replacement (Card v3)'),
            'the older version-scoped head name must still resolve — historical receipts '
            . 'carry it');
    }

    // ══ A PENALTY IS NOT A COST RECOVERY ════════════════════════════════════

    /**
     * Ordering is load-bearing. A library FINE is a penalty (4060); a lost-book
     * REPLACEMENT is cost recovery (4140). If the new terms were placed before
     * the penalty row, every fine would silently become Miscellaneous Income.
     */
    public function test_a_fine_still_resolves_to_the_penalty_account(): void
    {
        foreach (['Library Fine', 'Late Fee', 'Overdue Penalty'] as $head) {
            $this->assertSame('4060', $this->resolve($head),
                "'{$head}' no longer resolves to the fine/penalty account. The cost-recovery "
                . 'terms must stay AFTER the penalty row, or a fine becomes Miscellaneous '
                . 'Income and penalty revenue disappears from the accounts.');
        }
    }

    // ══ NOTHING ELSE MOVED ══════════════════════════════════════════════════

    /**
     * The new terms are broad words. This proves they did not capture heads
     * that belong elsewhere.
     */
    public function test_the_established_mappings_are_unchanged(): void
    {
        $expected = [
            'Tuition Fee'        => '4010',
            'Admission Fee'      => '4020',
            'Examination Fee'    => '4030',
            'Transport Fee'      => '4040',
            'Hostel Fee'         => '4050',
            'Bus Fee'            => '4040',
            'Boarding Charges'   => '4050',
        ];

        foreach ($expected as $head => $code) {
            $this->assertSame($code, $this->resolve($head),
                "'{$head}' now resolves to a different account — a cost-recovery keyword is "
                . 'capturing a head that belongs elsewhere');
        }
    }

    /**
     * The residual, stated rather than hidden. A trip contribution is genuinely
     * ambiguous — arguably activity income rather than cost recovery — so no
     * keyword was invented for it. It falls to the default, and a school that
     * wants it elsewhere maps it explicitly.
     *
     * This test exists so that behaviour is a recorded decision rather than an
     * oversight someone rediscovers later.
     */
    public function test_an_ambiguous_activity_charge_is_left_to_explicit_mapping(): void
    {
        $this->assertSame('4010', $this->resolve('Trip Contribution'),
            'a keyword was added for an activity contribution. That is a product decision '
            . '(is it fee income or cost recovery?), not a heuristic — schools that treat it '
            . 'as activity income should map the head explicitly instead.');
    }

    // ══ CONCESSIONS — THE CONTRA SIDE ═══════════════════════════════════════

    /** The concession keyword table, parsed from the same source file. */
    private function concessionKeywords(): array
    {
        $at  = strpos(self::$src, "\$kw = [");
        $this->assertNotFalse($at, 'the concession keyword table was renamed');
        $end = strpos(self::$src, '];', $at);
        $table = substr(self::$src, $at, $end - $at);

        preg_match_all("/'(\d{4})'\s*=>\s*\[([^\]]*)\]/", $table, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $row) {
            preg_match_all("/'([^']+)'/", $row[2], $terms);
            $out[$row[1]] = $terms[1];
        }
        return $out;
    }

    private function resolveConcession(string $type): string
    {
        $lc = strtolower(trim($type));
        foreach ($this->concessionKeywords() as $code => $terms) {
            foreach ($terms as $t) {
                if (strpos($lc, $t) !== false) return $code;
            }
        }
        return '4990';   // documented generic contra-revenue fallback
    }

    /**
     * Every concession type ZenX can actually STORE must land in its own contra
     * account. `Entitlement_service::CONCESSION_TYPES` is a closed vocabulary,
     * so this list is exhaustive rather than illustrative.
     *
     * A concession is a contra-revenue entry: it reduces income. Sending one to
     * the wrong contra account misstates WHY revenue was reduced, which is the
     * question a trustee asks when fee income is below budget.
     */
    public function test_every_storable_concession_type_has_its_own_contra_account(): void
    {
        $expected = [
            'sibling'     => '4993',
            'staff_ward'  => '4994',
            'rte_ews'     => '4997',
            'scholarship' => '4991',
        ];

        foreach ($expected as $type => $code) {
            $this->assertSame($code, $this->resolveConcession($type),
                "concession type '{$type}' no longer resolves to its own contra account. "
                . 'Collapsing types loses the reason revenue was reduced.');
        }
    }

    /**
     * 'other' is the deliberate catch-all and must resolve to the GENERIC
     * contra account — not to a specific one, and never to a fee-income account
     * (which would ADD revenue where a concession should reduce it).
     *
     * Note recorded rather than fixed: `Entitlement_requests` maps a HARDSHIP
     * request onto concessionType 'other', so hardship books to 4990 even
     * though 4992 (need-based / financial aid) exists and arguably fits. That
     * is a granularity choice, not a defect — same P&L section, same sign,
     * correct total; only the sub-classification is coarser. Recorded so the
     * behaviour is a decision rather than something rediscovered later.
     */
    public function test_the_catch_all_concession_type_resolves_to_generic_contra(): void
    {
        $acct = $this->resolveConcession('other');

        $this->assertSame('4990', $acct);
        $this->assertStringStartsWith('49', $acct,
            "the catch-all concession resolved to '{$acct}', outside the 49xx contra-revenue "
            . 'range. A concession must REDUCE income; booking it elsewhere would move money '
            . 'in the wrong direction.');
    }

    /**
     * A concession must never resolve into the fee-income range. That would
     * turn a discount into revenue — the sign error that no balance check
     * catches, because Dr=Cr holds either way.
     */
    public function test_no_concession_type_resolves_into_fee_income(): void
    {
        foreach (['sibling', 'staff_ward', 'rte_ews', 'scholarship', 'other', 'anything unknown'] as $type) {
            $acct = $this->resolveConcession($type);

            $this->assertNotSame('4010', $acct, "concession '{$type}' resolved to Tuition Fees");
            $this->assertMatchesRegularExpression('/^(49|51)\d\d$/', $acct,
                "concession '{$type}' resolved to '{$acct}', which is neither contra-revenue "
                . '(49xx) nor the legacy discount-expense fallback (5190)');
        }
    }

    /**
     * Explicit mapping must remain the authoritative step — the keywords only
     * decide what an UNMAPPED head does.
     */
    public function test_explicit_configuration_still_takes_precedence(): void
    {
        $fn = strpos(self::$src, 'public function resolve_fee_account');
        $this->assertNotFalse($fn);
        $body = substr(self::$src, $fn, 900);

        $this->assertMatchesRegularExpression(
            '/if \(isset\(\$configMap\[\$headLower\]\)\) \{\s*return \$configMap\[\$headLower\];/',
            $body,
            'the explicit feeAccountMap lookup no longer runs first. Keyword heuristics must '
            . 'never override a mapping an operator configured deliberately.'
        );
    }
}
