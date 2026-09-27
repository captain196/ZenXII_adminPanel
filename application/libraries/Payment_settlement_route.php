<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  PAYMENT_SETTLEMENT_ROUTE — where gateway money is booked, and when
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE ACCOUNTING PROBLEM ───────────────────────────────────────────────
 * ZenX booked an online fee payment as:
 *
 *     Dr  Bank (gross)  /  Cr  Fee Income (gross)     ← at capture
 *
 * That asserts two things the bank does not agree with:
 *
 *   1. WHEN — the money is not in the bank at capture. A gateway settles on
 *      a cycle (commonly T+2 working days in India). On capture day the funds
 *      are held BY THE GATEWAY, not by the school.
 *   2. HOW MUCH — the bank never receives the gross. The gateway deducts MDR
 *      and the GST charged on that MDR, and credits the NET.
 *
 * So a ledger built this way can never reconcile against a bank statement:
 * the books say "700 into SBI on 26 Aug"; the statement says "~683 on 28 Aug",
 * usually batched together with other days' payments.
 *
 * ── THE ESTABLISHED MODEL ────────────────────────────────────────────────
 * Standard practice across ERP and gateway-reconciliation guidance is a
 * GATEWAY CLEARING ACCOUNT — an asset meaning "money the gateway owes us":
 *
 *   Capture (T+0)          Dr  Gateway Clearing        gross
 *                              Cr  Fee Income               gross
 *
 *   Settlement (T+2)       Dr  Bank                    net
 *                          Dr  Gateway Fees            MDR base
 *                          Dr  Input GST               GST on MDR
 *                              Cr  Gateway Clearing         gross
 *
 * The clearing balance is then a live, checkable figure: it should equal
 * money captured but not yet settled. A balance that will not clear is a
 * reconciliation exception, which is exactly the signal a school needs.
 *
 * Sources consulted for the principle (implementation is ZenX's own):
 *   · Razorpay — settlement cycles, MDR composition, settlement transparency
 *   · Worldline India — how gateway settlement works (acquirer nets MDR)
 *   · AI Accountant — India gateway settlement-file parsing: Dr fees +
 *     Dr input GST / Cr gateway clearing; GST on MDR eligible for ITC only
 *     when the settlement report itemises it
 *
 * ── WHY A ROUTE OBJECT RATHER THAN A CONSTANT ────────────────────────────
 * A school may run several gateways into several banks (Razorpay → SBI,
 * Cashfree → HDFC). Provider names must not appear in core accounting, so the
 * mapping is DATA, per school and per provider, and this class only resolves
 * it. Nothing here knows what "razorpay" means.
 *
 * ── FAIL-SOFT, NEVER FAIL-ARBITRARY ──────────────────────────────────────
 * When a school has configured no route, the previous behaviour — "the first
 * active bank ledger the chart happens to yield" — is preserved in SHAPE but
 * not in arbitrariness: candidates are sorted by account code, so the answer
 * is deterministic and reproducible instead of depending on map/query order.
 * A payment is never refused over bookkeeping metadata, but the resolution is
 * always reproducible and always states which mode produced it.
 */
class Payment_settlement_route
{
    /** Per-school, per-provider settlement mapping. */
    const COL_ROUTES = 'paymentSettlementRoutes';

    /** How the debit account was arrived at — carried into the journal. */
    const SRC_CONFIGURED    = 'configured';
    const SRC_DETERMINISTIC = 'deterministic_fallback';
    const SRC_AMBIGUOUS     = 'ambiguous_fallback';
    const SRC_CASH          = 'cash';
    const SRC_NONE          = 'unresolved';

    /**
     * Count of postable active bank ledgers. One means the fallback has no
     * choice to get wrong; two or more means any unconfigured pick is a guess.
     */
    public static function activeBankCount(array $coa): int
    {
        $n = 0;
        foreach ($coa as $acct) {
            if (!is_array($acct)) continue;
            if (empty($acct['is_bank'])) continue;
            if (!empty($acct['is_group'])) continue;
            if (($acct['status'] ?? '') !== 'active') continue;
            $n++;
        }
        return $n;
    }

    /**
     * Deterministic replacement for "first active bank ledger".
     *
     * Sorting by account code makes the result a function of the DATA rather
     * than of iteration order. Two runs over the same chart now always agree,
     * which is the property AccountingReconciler's repair path needs and did
     * not have.
     *
     * @param array $coa chart of accounts keyed by account code
     * @return string|null the account code, or null when the chart has no
     *         active bank ledger at all
     */
    public static function firstActiveBankDeterministic(array $coa): ?string
    {
        $codes = [];
        foreach ($coa as $code => $acct) {
            if (!is_array($acct)) continue;
            if (empty($acct['is_bank'])) continue;
            if (!empty($acct['is_group'])) continue;      // a group is not postable
            if (($acct['status'] ?? '') !== 'active') continue;
            $codes[] = (string) $code;
        }
        if (!$codes) return null;
        // Natural, case-insensitive: '1020' before '1100', and stable for
        // alphanumeric charts too.
        usort($codes, static fn($a, $b) => strnatcasecmp($a, $b));
        return $codes[0];
    }

    /**
     * Read a school's configured settlement route for one provider.
     *
     * Doc id is `{schoolId}_{provider}` — one active route per provider per
     * school. `environment` lets a school point TEST money somewhere harmless
     * while LIVE money goes to the real account.
     *
     * @return array|null the route, or null when none is configured
     */
    public static function load($firebase, string $schoolId, string $provider, string $environment = 'live'): ?array
    {
        if ($schoolId === '' || $provider === '') return null;
        if (!is_object($firebase) || !method_exists($firebase, 'firestoreGet')) return null;

        try {
            $doc = $firebase->firestoreGet(self::COL_ROUTES, $schoolId . '_' . $provider);
        } catch (\Throwable $e) {
            log_message('error', 'Payment_settlement_route::load failed for '
                . $schoolId . '/' . $provider . ': ' . $e->getMessage());
            return null;
        }
        if (!is_array($doc)) return null;

        // Re-assert tenant identity on the returned data. A query is not a
        // substitute for checking what came back.
        if ((string) ($doc['schoolId'] ?? '') !== $schoolId) return null;
        if (empty($doc['active'])) return null;

        // An environment-scoped route only applies to its own environment. A
        // blank value means "any", which is the sane default for a school that
        // has never run anything but live.
        $routeEnv = strtolower(trim((string) ($doc['environment'] ?? '')));
        if ($routeEnv !== '' && $routeEnv !== strtolower($environment)) return null;

        return $doc;
    }

    /**
     * Decide the account a captured payment debits, and say why.
     *
     * Preference order, most authoritative first:
     *   1. the operator's explicit till-side choice (cash counter)
     *   2. the school's configured clearing account for this provider
     *   3. the school's configured settlement bank for this provider
     *   4. a deterministic active bank ledger
     *   5. Cash in Hand — only for genuinely cash-like modes
     *
     * @return array{
     *   debit_account:?string, source:string, clearing:?string, bank:?string,
     *   fee_account:?string, tax_account:?string, deferred_settlement:bool
     * }
     */
    public static function resolveDebit(
        array $coa,
        ?array $route,
        string $operatorBankCode,
        string $payMode,
        bool $isNonCashMode
    ): array {
        $out = [
            'debit_account'       => null,
            'source'              => self::SRC_NONE,
            'clearing'            => null,
            'bank'                => null,
            'fee_account'         => null,
            'tax_account'         => null,
            'deferred_settlement' => false,
        ];

        $usable = static function (?string $code) use ($coa): bool {
            if ($code === null || $code === '') return false;
            $a = $coa[$code] ?? null;
            return is_array($a) && ($a['status'] ?? '') === 'active' && empty($a['is_group']);
        };

        if (is_array($route)) {
            $out['clearing']    = self::str($route, 'clearingLedgerCode');
            $out['bank']        = self::str($route, 'settlementBankLedgerCode');
            $out['fee_account'] = self::str($route, 'gatewayFeeLedgerCode');
            $out['tax_account'] = self::str($route, 'gatewayTaxLedgerCode');
        }

        // 1. An operator standing at a till chose an account. Nothing outranks
        //    a human who watched the money arrive.
        if ($operatorBankCode !== '' && $usable($operatorBankCode)) {
            $out['debit_account'] = $operatorBankCode;
            $out['source']        = self::SRC_CONFIGURED;
            return $out;
        }

        if ($isNonCashMode) {
            // 2. Clearing account — the correct destination at capture time,
            //    because the bank does not have the money yet.
            if ($usable($out['clearing'])) {
                $out['debit_account']       = $out['clearing'];
                $out['source']              = self::SRC_CONFIGURED;
                $out['deferred_settlement'] = true;
                return $out;
            }
            // 3. A school that named its settlement bank but no clearing
            //    account has chosen the simpler (T+0 = bank) model knowingly.
            if ($usable($out['bank'])) {
                $out['debit_account'] = $out['bank'];
                $out['source']        = self::SRC_CONFIGURED;
                return $out;
            }
            // 4. Nothing configured. Deterministic — but say honestly whether
            //    there was a real choice to get wrong.
            //
            //    ONE active bank: the fallback cannot pick the wrong account,
            //    because there is only one. That is the common case and is
            //    reported as an ordinary fallback.
            //
            //    TWO OR MORE: any pick is a GUESS. A school whose gateway
            //    settles into HDFC would have its payments attributed to SBI
            //    purely because SBI sorts first. The payment is still posted —
            //    refusing a parent's payment over bookkeeping configuration
            //    would be the worse failure, and the books must balance — but
            //    the attribution is marked AMBIGUOUS so it is reportable and
            //    correctable rather than silently wrong.
            $auto = self::firstActiveBankDeterministic($coa);
            if ($auto !== null) {
                $out['debit_account'] = $auto;
                $out['source']        = self::activeBankCount($coa) > 1
                    ? self::SRC_AMBIGUOUS
                    : self::SRC_DETERMINISTIC;
                return $out;
            }
            // 5. No bank at all. Cash in Hand would be a lie for an online
            //    payment, so this resolves to nothing and the caller decides.
            return $out;
        }

        $out['debit_account'] = '1010';
        $out['source']        = self::SRC_CASH;
        return $out;
    }

    private static function str(array $a, string $k): ?string
    {
        $v = trim((string) ($a[$k] ?? ''));
        return $v === '' ? null : $v;
    }
}
