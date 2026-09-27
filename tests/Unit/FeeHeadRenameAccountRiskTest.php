<?php

use PHPUnit\Framework\TestCase;

if (!class_exists('Operations_accounting')) {
    require_once APPPATH . 'libraries/Operations_accounting.php';
}
if (!class_exists('Fee_late_fee_service')) {
    require_once APPPATH . 'libraries/Fee_late_fee_service.php';
}

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  FH-RENAME-1 — A DISPLAY RENAME CAN MOVE MONEY TO A DIFFERENT LEDGER
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `Fee_head_resolver`'s rule 2 is unambiguous:
 *
 *   "NEVER CLASSIFY BY NAME. No keyword matching, no substring test, no
 *    module-name inference. The name is display identity only and is mutable."
 *
 * And rule 1 says a head "survives session rollover, repricing, **rename** and
 * archival with the same id" — so renaming is explicitly sanctioned.
 *
 * But the income account is resolved from that same name:
 *
 *     public function resolve_fee_account(string $feeHead, array $configMap, array $coa)
 *
 * There is no `feeHeadId` or `feeHeadRef` parameter. All three call sites pass
 * a NAME, taken from `$alloc['fee_head']`. So the ledger a charge credits is a
 * **pure function of the mutable display string**.
 *
 * ── WHAT THIS FILE PROVES ─────────────────────────────────────────────────
 * Not that the code "looks risky" — that renaming a head to another perfectly
 * reasonable label silently re-routes its income, including into Tuition Fees.
 *
 * ── WHY IT IS LATENT, NOT LIVE ────────────────────────────────────────────
 * Nothing in the application can write to `feeHeadRegistry` — no UI, no
 * endpoint, no service. The registry exists only because a migration script
 * created it. So no rename can happen today.
 *
 * It becomes live the moment ANY write path exists, which is why it is
 * recorded before one is built rather than after.
 *
 * ── NOT FIXED HERE, DELIBERATELY ──────────────────────────────────────────
 * The smallest safe correction is an explicit account binding on the head
 * (§ FEE_HEAD_ARCHITECTURE_INVESTIGATION.md), which means adding a field to
 * registry documents and changing the resolution contract. Both are outside
 * the current gate, and the standing instruction is not to modify the registry
 * or invent accounting rules. This test pins the risk so it cannot be
 * rediscovered as a surprise.
 */
class FeeHeadRenameAccountRiskTest extends TestCase
{
    private static array $keywords = [];
    private static string $src = '';

    public static function setUpBeforeClass(): void
    {
        self::$src = (string) file_get_contents(APPPATH . 'libraries/Operations_accounting.php');

        $at    = strpos(self::$src, "\$keywords = [");
        $end   = strpos(self::$src, '];', $at);
        $table = substr(self::$src, $at, $end - $at);

        preg_match_all("/'(\d{4})'\s*=>\s*\[([^\]]*)\]/", $table, $m, PREG_SET_ORDER);
        foreach ($m as $row) {
            preg_match_all("/'([^']+)'/", $row[2], $terms);
            self::$keywords[$row[1]] = $terms[1];
        }
    }

    /** The real resolution order, over the real table. */
    private function resolve(string $feeHead, array $configMap = []): string
    {
        $headLower = strtolower(trim($feeHead));
        if (isset($configMap[$headLower])) return $configMap[$headLower];
        foreach (self::$keywords as $code => $terms) {
            foreach ($terms as $t) if (strpos($headLower, $t) !== false) return $code;
        }
        return '4010';
    }

    // ══ THE PREMISE ═════════════════════════════════════════════════════════

    /**
     * The account resolver receives ONLY a name. If it ever gains a stable
     * identity parameter, this whole risk closes and the file should be
     * re-derived rather than kept.
     */
    public function test_control_the_resolver_is_given_only_the_name(): void
    {
        $this->assertStringContainsString(
            'public function resolve_fee_account(string $feeHead, array $configMap, array $coa): string',
            self::$src,
            'the account resolver signature changed — if it now receives a feeHeadId or '
            . 'feeHeadRef, the rename risk may be closed');

        // And no call site supplies a stable id.
        preg_match_all('/resolve_fee_account\(([^)]*)\)/', self::$src, $calls);
        foreach ($calls[1] as $args) {
            $this->assertStringNotContainsString('feeHeadRef', $args,
                'a call site now passes a stable ref — re-derive this file');
            $this->assertStringNotContainsString('feeHeadId', $args);
        }
    }

    /** The registry really does declare the name mutable. */
    public function test_control_the_registry_declares_the_name_mutable(): void
    {
        $res = (string) file_get_contents(APPPATH . 'libraries/Fee_head_resolver.php');

        $this->assertStringContainsString('The name is display identity only and is mutable', $res,
            'the registry no longer declares the name mutable — if the name is now an '
            . 'identity, the contradiction this file records is resolved');
        $this->assertStringContainsString('rename and archival with the same id', $res,
            'rename is no longer described as a supported operation');
    }

    // ══ FH-RENAME-1 — THE PROOF ═════════════════════════════════════════════

    /**
     * A cost-recovery head renamed to an equally reasonable label lands in
     * **Tuition Fees**. Nothing warns, and the journal balances.
     */
    public function test_a_rename_can_move_a_cost_recovery_into_tuition_revenue(): void
    {
        $before = $this->resolve('Lost Book');
        $this->assertSame('4140', $before, 'the cost-recovery mapping regressed');

        // Every one of these is a plausible re-label of the same head.
        foreach (['Student Levy', 'Recovery Charge', 'Miscellaneous Charge', 'Book Charge'] as $renamed) {
            $after = $this->resolve($renamed);

            $this->assertSame('4010', $after,
                "renaming 'Lost Book' to '{$renamed}' happens to keep a non-Tuition account — "
                . 'if the keyword table has grown, re-derive which names are still unsafe');
            $this->assertNotSame($before, $after,
                'this rename is safe; pick a different one to demonstrate the risk');
        }
    }

    /**
     * The explicit `feeAccountMap` is keyed by the LOWERCASE NAME, so a rename
     * silently orphans a mapping a school deliberately configured. That is the
     * sharper half: the school did the right thing and lost it.
     */
    public function test_a_rename_orphans_the_schools_own_explicit_mapping(): void
    {
        $configMap = ['lost book' => '4140'];

        $this->assertSame('4140', $this->resolve('Lost Book', $configMap),
            'the explicit map is no longer consulted first');

        // Same head, same school, same intent — renamed.
        $this->assertSame('4010', $this->resolve('Student Levy', $configMap),
            "a rename kept the school's explicit mapping. If the map is now keyed by a stable "
            . 'id rather than the name, this risk is closed — re-derive the file.');
    }

    /**
     * Direction matters: the failure is toward TUITION, which is the account
     * every unmapped head defaults to. So the silent outcome is always
     * "fee income overstated", never a harmless mis-file.
     */
    public function test_the_unmapped_default_is_tuition_which_is_why_this_matters(): void
    {
        $this->assertSame('4010', $this->resolve('Something Nobody Mapped'),
            'the default changed — re-derive the severity of an unmapped head');
        $this->assertStringContainsString('// 3. Default: Tuition Fees (4010)', self::$src);
    }

    // ══ WHY IT CANNOT HAPPEN TODAY ══════════════════════════════════════════

    /**
     * The mitigation is entirely accidental: nothing can write to the registry.
     * Pinned so that whoever adds the first write path is confronted by this
     * test rather than discovering the coupling afterwards.
     */
    public function test_nothing_in_the_application_can_rename_a_fee_head(): void
    {
        $writers = [];
        foreach (['libraries', 'controllers', 'services'] as $dir) {
            $path = APPPATH . $dir;
            if (!is_dir($path)) continue;
            foreach (scandir($path) as $f) {
                if (substr($f, -4) !== '.php') continue;
                $src = (string) file_get_contents($path . DIRECTORY_SEPARATOR . $f);
                $src = (string) preg_replace('!/\*.*?\*/!s', '', $src);
                $src = (string) preg_replace('!^\s*//.*$!m', '', $src);

                // A write to the registry collection, by any of the app's verbs.
                if (preg_match('/(firestoreSet|setEntity|updateEntity|firestoreCreate)\s*\(\s*[^,]*feeHeadRegistry/i', $src)
                    // Refined 2026-08-29: this was /Fee_head_resolver::COL\s*,/,
                    // which matched ANY use of the constant — including
                    // firestoreQuery(), i.e. a READ. Reading the registry to check
                    // whether a school's heads are all registered cannot rename
                    // anything, and treating it as a write capability made the
                    // guard fire on the very check that protects billing. Both
                    // patterns now require an actual write verb.
                    || preg_match('/(firestoreSet|setEntity|updateEntity|firestoreCreate)\s*\(\s*Fee_head_resolver::COL/', $src)) {
                    $writers[] = $f;
                }
            }
        }

        // ── UPDATED 2026-08-29 · ONE CREATE-ONLY WRITER IS NOW ALLOWED ──────
        //
        // This asserted that NOTHING could write feeHeadRegistry. That held only
        // because the collection's sole writer was a developer script outside
        // application/ — which is precisely why a newly onboarded school could
        // not obtain a fee head at all, and, with registry mode on, silently
        // billed nobody. The absence this test was protecting was not a safety
        // property; it was the day-one gap.
        //
        // What FH-RENAME-1 actually warns about is RENAMING: the income account
        // is bound to the head's NAME, so changing it re-routes money. Creating
        // a NEW head cannot do that — there is no prior account to re-route, and
        // the identity is create-if-absent, so a repeat is a no-op.
        //
        // So the invariant is kept where the risk really lives: exactly one
        // writer is permitted, and it is verified BELOW to hold no update verb
        // at all. If it ever gains one, this test fails again — which is the
        // point. Any OTHER writer still fails immediately.
        $allowed = ['Fee_head_registry_service.php'];
        $unexpected = array_values(array_diff($writers, $allowed));

        $this->assertSame([], $unexpected,
            'something new can write to feeHeadRegistry: ' . implode(', ', $unexpected)
            . '. FH-RENAME-1 is no longer latent — a rename through that path will silently '
            . 're-route income to a different ledger. Bind the account to the head before '
            . 'shipping any write capability.');

        // The permitted writer must be able to CREATE and nothing else. A rename
        // is an UPDATE, so the absence of every update verb is what makes the
        // allowance above safe — and it is asserted, not assumed.
        foreach ($allowed as $f) {
            $path = APPPATH . 'libraries' . DIRECTORY_SEPARATOR . $f;
            if (!is_file($path)) continue;
            $src = (string) file_get_contents($path);
            $src = (string) preg_replace('!/\*.*?\*/!s', '', $src);
            $src = (string) preg_replace('!^\s*//.*$!m', '', $src);

            foreach (['firestoreSet', 'firestoreUpdate', 'updateEntity', 'setEntity'] as $verb) {
                $this->assertStringNotContainsString($verb . '(', $src,
                    "{$f} gained {$verb}() — the registry writer must be CREATE-ONLY. An update "
                    . 'verb here is a rename capability, and a renamed fee head silently '
                    . 're-routes income to a different account (FH-RENAME-1). Bind the account '
                    . 'to the head identity before allowing any update.');
            }

            $this->assertStringContainsString('firestoreCreate(', $src,
                "{$f} is listed as the registry writer but no longer creates anything");
        }
    }
}
