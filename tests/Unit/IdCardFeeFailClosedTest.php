<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  ID-CARD REPLACEMENT — THE FAIL-CLOSED GUARDS NOTHING WAS TESTING
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `Id_card_fee_policy::amountFor()` decides what a family is charged for a
 * replacement card. It reads a per-school override document, falls back to a
 * config value, and refuses anything it cannot trust:
 *
 *     if (!in_array($reason, self::chargeableReasons(), true)) return 0.0;
 *     $raw = $overrides[$reason] ?? ($this->amounts[$reason] ?? 0);
 *     if (!is_numeric($raw)) return 0.0;
 *     $amt = (float) $raw;
 *     return $amt > 0 ? round($amt, 2) : 0.0;
 *
 * ── WHY THIS FILE EXISTS ──────────────────────────────────────────────────
 * The 210 existing ID-card tests were mutation-tested rather than trusted. Two
 * mutations were caught immediately:
 *
 *   making 'wear' chargeable          → 1 test failed
 *   collapsing every version to V1    → 6 tests failed
 *
 * A third **survived with all 210 green**: replacing
 *
 *     if (!is_numeric($raw)) return 0.0;
 * with
 *     if (!is_numeric($raw)) $raw = 500;
 *
 * — i.e. a school whose `replacementFees` document holds a non-numeric value
 * would charge every family ₹500, and nothing in the suite noticed.
 *
 * The guard is CORRECT today. What was missing is any test able to detect its
 * removal, and this is the one guard where the failure direction takes money
 * from families who owe nothing. `schoolOverrides()` reads a hand-editable
 * Firestore document, so `"lost": "two hundred"`, `"lost": null` or
 * `"lost": ""` are all realistic — a migrated or hand-edited config, not a
 * hypothetical.
 *
 * These tests drive the REAL policy object. `amountFor()` and `isChargeable()`
 * need no Firestore handle, so there is nothing to stub and no copy to drift.
 */
class IdCardFeeFailClosedTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        foreach (['Id_card_service', 'Id_card_fee_policy'] as $c) {
            if (!class_exists($c, false)) {
                require_once APPPATH . 'libraries/' . $c . '.php';
            }
        }
    }

    private function policy(array $amounts = []): \Id_card_fee_policy
    {
        return (new \Id_card_fee_policy())->init(null, $amounts, 'ID Card Replacement', 'IDCard');
    }

    // ══ CONTROL — A LEGITIMATE CHARGE STILL WORKS ═══════════════════════════

    /**
     * Without this every assertion below could pass because the policy charges
     * nothing at all, which would make "fails closed" meaningless.
     */
    public function test_control_a_configured_chargeable_reason_costs_money(): void
    {
        $p = $this->policy(['lost' => 200]);

        $this->assertSame(200.0, $p->amountFor(\Id_card_service::REASON_LOST),
            'the policy charges nothing even when properly configured, so the fail-closed '
            . 'assertions below prove nothing');
        $this->assertTrue($p->isChargeable(\Id_card_service::REASON_LOST));
    }

    /** A school override outranks the shipped config default. */
    public function test_control_a_school_override_wins_over_the_config_default(): void
    {
        $p = $this->policy(['lost' => 200]);

        $this->assertSame(350.0, $p->amountFor(\Id_card_service::REASON_LOST, ['lost' => 350]),
            'the per-school override no longer takes precedence, so a school cannot set its '
            . 'own replacement fee');
    }

    // ══ THE GUARD THE MUTATION ESCAPED ══════════════════════════════════════

    /**
     * WOULD NOT HAVE FAILED BEFORE — this is the mutation that survived all 210
     * existing tests.
     *
     * Every value here is one a hand-edited or migrated `replacementFees`
     * document can realistically hold.
     */
    public function test_a_non_numeric_configured_amount_charges_nothing(): void
    {
        foreach ([['two hundred'], [null], [''], [[]], [true], ['₹200'], ['200rs'], [' ']] as $case) {
            $raw = $case[0];
            $p   = $this->policy(['lost' => $raw]);

            $this->assertSame(0.0, $p->amountFor(\Id_card_service::REASON_LOST),
                'a non-numeric replacement fee (' . var_export($raw, true) . ') produced a '
                . 'charge. This value comes from a hand-editable Firestore document, and the '
                . 'failure direction here takes money from a family that owes nothing — so it '
                . 'must resolve to zero, never to a default.');
            $this->assertFalse($p->isChargeable(\Id_card_service::REASON_LOST));
        }
    }

    /** The same applies to a school override, which is the likelier source. */
    public function test_a_non_numeric_school_override_charges_nothing(): void
    {
        $p = $this->policy(['lost' => 200]);   // a valid fallback exists

        $this->assertSame(0.0, $p->amountFor(\Id_card_service::REASON_LOST, ['lost' => 'free']),
            'a malformed school override fell back to the config default instead of failing '
            . 'closed. A school that typed something wrong would keep charging the old amount '
            . 'while believing it had changed it.');
    }

    /**
     * A negative amount must not become a credit. `round()` on a negative would
     * otherwise flow a negative gross into `createModuleFee`, which floors the
     * balance but still writes a nonsensical charge.
     */
    public function test_a_negative_amount_charges_nothing(): void
    {
        foreach ([-1, -0.01, -500, '-200'] as $raw) {
            $this->assertSame(0.0, $this->policy(['lost' => $raw])->amountFor(\Id_card_service::REASON_LOST),
                "a negative replacement fee ({$raw}) was accepted — a charge cannot be a credit");
        }
    }

    /** Zero is not a charge, and must not be raised as a demand at all. */
    public function test_zero_is_not_chargeable(): void
    {
        $this->assertFalse($this->policy(['lost' => 0])->isChargeable(\Id_card_service::REASON_LOST));
        $this->assertFalse($this->policy([])->isChargeable(\Id_card_service::REASON_LOST),
            'an entirely unconfigured school charges for replacements. The shipped default '
            . 'must be free — a school opts IN to charging.');
    }

    /** Numeric strings ARE valid — schools configure through a form. */
    public function test_a_numeric_string_is_accepted(): void
    {
        $this->assertSame(200.0, $this->policy(['lost' => '200'])->amountFor(\Id_card_service::REASON_LOST),
            'a numeric string was rejected. Config arrives from a form post and from JSON, so '
            . 'refusing "200" would silently make every configured school free.');
        $this->assertSame(199.5, $this->policy(['lost' => '199.50'])->amountFor(\Id_card_service::REASON_LOST));
    }

    /** Money is rounded to paise, not left at float precision. */
    public function test_the_amount_is_rounded_to_paise(): void
    {
        $this->assertSame(200.13, $this->policy(['lost' => 200.129])->amountFor(\Id_card_service::REASON_LOST));
    }

    // ══ WHO MAY BE CHARGED AT ALL ═══════════════════════════════════════════

    /**
     * The reason gate runs BEFORE any amount is read, so no configuration can
     * make a blameless reason chargeable. Normal wear, the school's own data
     * corrections, an admin reissue and the first card are never the family's
     * fault.
     */
    public function test_no_configuration_can_charge_for_a_blameless_reason(): void
    {
        foreach ([\Id_card_service::REASON_FIRST_ISSUE,
                  \Id_card_service::REASON_WEAR,
                  \Id_card_service::REASON_DATA_CORRECTION,
                  \Id_card_service::REASON_ADMIN] as $reason) {

            // Configured AND overridden — both routes must be refused.
            $p = $this->policy([$reason => 500]);

            $this->assertSame(0.0, $p->amountFor($reason, [$reason => 900]),
                "'{$reason}' became chargeable through configuration. The first card is part "
                . 'of admission, and wear, data corrections and admin reissues are not the '
                . "family's fault — no config value may override that.");
        }
    }

    /** An unknown reason is refused rather than charged a default. */
    public function test_an_unknown_reason_is_refused(): void
    {
        $this->assertSame(0.0, $this->policy(['whatever' => 500])->amountFor('whatever'));
        $this->assertSame(0.0, $this->policy(['' => 500])->amountFor(''));
    }

    /** The chargeable set is exactly the three fault-based reasons. */
    public function test_the_chargeable_set_is_the_three_fault_reasons(): void
    {
        $this->assertSame(
            [\Id_card_service::REASON_LOST, \Id_card_service::REASON_STOLEN, \Id_card_service::REASON_DAMAGED],
            \Id_card_fee_policy::chargeableReasons(),
            'the chargeable-reason set changed. Adding one here charges families for something '
            . 'they were not charged for yesterday, which is a policy decision, not a code one.');
    }

    // ══ THE OVERRIDE READ FAILS CLOSED TOO ══════════════════════════════════

    /**
     * `schoolOverrides()` is best-effort by design: an unreadable document
     * means "use the fallback". It must never throw, because a Firestore blip
     * during a card issue would otherwise abort the issue itself.
     */
    public function test_an_unreadable_override_document_yields_no_overrides(): void
    {
        $exploding = new class {
            public function get($c, $d) { throw new \RuntimeException('firestore down'); }
        };
        $p = (new \Id_card_fee_policy())->init($exploding, ['lost' => 200], 'ID Card Replacement', 'IDCard');

        $this->assertSame([], $p->schoolOverrides('SCH_X', '2026-27'),
            'an unreadable override document propagated an exception instead of falling back. '
            . 'A Firestore blip would abort the card issue itself.');
    }

    /** An uninitialised policy charges nothing rather than guessing. */
    public function test_an_uninitialised_policy_returns_no_overrides(): void
    {
        $this->assertSame([], (new \Id_card_fee_policy())->schoolOverrides('SCH_X', '2026-27'));
        $this->assertFalse((new \Id_card_fee_policy())->isReady());
    }

    // ══ IDENTITY — ONE HEAD, MANY INSTANCES ═════════════════════════════════

    /**
     * The head is STABLE across versions and the version is carried as the
     * charge instance. Already covered by six tests elsewhere (mutation-proven),
     * pinned here because `amountFor()` and `instanceKey()` together are what
     * make a second replacement a second demand rather than a silent no-op.
     */
    public function test_the_head_is_stable_and_the_version_is_the_instance(): void
    {
        $p = $this->policy(['lost' => 200]);

        $this->assertSame($p->feeHeadFor(1), $p->feeHeadFor(7),
            'the head name varies by card version again. That mints an unbounded family of '
            . 'head names which cannot be pre-registered, so the second replacement fails '
            . 'closed under the fee-head registry.');
        $this->assertSame('V7', $p->instanceKey(7));
        $this->assertNotSame($p->instanceKey(2), $p->instanceKey(3),
            'two replacements would collapse onto one demand id and the family would be '
            . 'charged once for two cards');
    }

    /** A nonsensical version is clamped, never used raw in an identity. */
    public function test_a_version_below_one_is_clamped(): void
    {
        $p = $this->policy();

        $this->assertSame('V1', $p->instanceKey(0));
        $this->assertSame('V1', $p->instanceKey(-5),
            'a negative card version reached the demand id, producing an identity like '
            . 'V-5 that no regeneration could reproduce');
    }
}
