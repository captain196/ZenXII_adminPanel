<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  ACC-PM1 — ONLINE FEE PAYMENTS WERE BOOKED TO CASH IN HAND
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `Operations_accounting` chooses the debit ledger for a fee receipt like this:
 *
 *     $payMode = strtolower(trim($params['payment_mode'] ?? 'cash'));
 *     …
 *     if ($bankCode !== '' && isset($coa[$bankCode]))            → use it
 *     elseif (in_array($payMode, ['bank','cheque','upi','neft',
 *                                 'rtgs','online','bank_transfer'], true))
 *                                                                → find a bank account
 *     else                                                       → '1010'
 *
 * The parent payment paths passed `payment_mode => 'Online - Razorpay'`.
 * Lowercased that is `'online - razorpay'`, which is NOT `'online'`, so it fell
 * through to the else branch and every online fee payment was debited to
 * account **1010 — Cash in Hand**.
 *
 * And no `bank_code` rescued it: `bank_ledger_code` appears ZERO times in
 * Fee_management.php, so the first branch could never fire on a parent payment.
 *
 * ── WHY NOTHING CAUGHT THIS ───────────────────────────────────────────────
 * The journal still BALANCED. Dr = Cr held perfectly; the debit simply went to
 * the wrong asset account. Every accounting invariant anyone thought to check
 * was satisfied by a misclassification — a reminder that "balances" and
 * "correct" are different properties.
 *
 * The consequence is not a rounding difference: a school's books would show
 * cash in hand that does not physically exist, an understated bank balance, and
 * a bank reconciliation that can never be made to agree.
 *
 * ── THE FIX ALSO REMOVES A PROVIDER LOCK ──────────────────────────────────
 * `payment_mode` is an ACCOUNTING CLASSIFICATION, so it must be
 * provider-neutral. Gateway identity moved to its own `gateway` receipt field,
 * which is provenance rather than classification — so adding a second provider
 * cannot change which ledger is debited.
 */
class PaymentModeLedgerClassificationTest extends TestCase
{
    /**
     * The REAL list, not a copy of it.
     *
     * This file used to declare its own `BANK_MODES` duplicating the inline
     * array in `Operations_accounting`. That is the drift PM-1 removed: the
     * same list had been written out three times across fees, payroll and
     * library, and one of the three had the polarity inverted. A test holding
     * a fourth copy would keep passing while the production list changed
     * underneath it.
     */
    private function bankModes(): array
    {
        if (!class_exists('Operations_accounting')) {
            require_once APPPATH . 'libraries/Operations_accounting.php';
        }
        return Operations_accounting::NON_CASH_MODES;
    }

    /** Reproduces the library's classification decision, over the real list. */
    private function resolvesToBank(string $paymentMode): bool
    {
        return in_array(strtolower(trim($paymentMode)), $this->bankModes(), true);
    }

    // ══ CONTROL — THE OLD VALUE REALLY DID MISCLASSIFY ══════════════════════

    /**
     * If this ever stopped failing, ACC-PM1 would be wrong and every assertion
     * below would be arbitrary.
     */
    public function test_control_the_old_payment_mode_did_not_resolve_to_bank(): void
    {
        $this->assertFalse($this->resolvesToBank('Online - Razorpay'),
            "'Online - Razorpay' now classifies as a bank payment. If the matching list "
            . 'gained a compound entry, re-derive ACC-PM1 before trusting this file.');
    }

    /**
     * Spelling out the consequence rather than just the predicate: the else
     * branch hardcodes 1010, the conventional Cash in Hand account.
     */
    public function test_control_an_unmatched_mode_falls_through_to_the_cash_account(): void
    {
        // PM-1 promoted the inline array to a shared constant; PM-3 promoted
        // the classification itself into resolveCashBankCode(). Each move
        // invalidated the previous source anchor without changing behaviour —
        // which is the argument for asserting the behaviour instead. The
        // classifier is now a pure function, so the consequence can be stated
        // directly rather than inferred from the shape of nearby source.
        require_once APPPATH . 'libraries/Operations_accounting.php';
        $coa = [
            '1010' => ['name' => 'Cash in Hand', 'is_bank' => false, 'status' => 'active'],
            '1020' => ['name' => 'SBI Bank A/c', 'is_bank' => true,  'status' => 'active'],
        ];
        $this->assertSame('1010',
            Operations_accounting::resolveCashBankCode($coa, '', 'gift_voucher'),
            'an unmatched payment mode no longer falls through to 1010 (Cash in Hand) — '
            . 're-derive what it now debits before trusting any ledger built from it');

        // Control: the assertion above must not pass merely because the
        // function always returns 1010.
        $this->assertSame('1020',
            Operations_accounting::resolveCashBankCode($coa, '', 'online'),
            'the classifier returns 1010 for everything, so the fall-through test proves nothing');
    }

    // ══ THE FIX ═════════════════════════════════════════════════════════════

    public function test_the_canonical_online_mode_resolves_to_bank(): void
    {
        $this->assertTrue($this->resolvesToBank('Online'),
            "'Online' must classify as a bank payment — that is the whole fix");
        $this->assertTrue($this->resolvesToBank('online'));
        $this->assertTrue($this->resolvesToBank(' Online '),
            'the library trims before matching, so surrounding whitespace must not matter');
    }

    /**
     * WOULD HAVE FAILED BEFORE: all three parent payment paths sent
     * 'Online - Razorpay'.
     */
    public function test_no_payment_path_sends_a_mode_that_misclassifies(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');

        preg_match_all("/'payment_mode'\s*=>\s*'([^']+)'/", $src, $m);
        $this->assertNotEmpty($m[1], 'no payment_mode literals found — the payload shape changed');

        $misclassified = array_values(array_unique(array_filter(
            $m[1],
            fn(string $mode) => !$this->resolvesToBank($mode) && stripos($mode, 'cash') === false
        )));

        $this->assertSame([], $misclassified,
            'these payment_mode values classify as neither bank nor cash and would be '
            . 'debited to account 1010 (Cash in Hand): ' . implode(', ', $misclassified)
            . '. Money arriving in the school bank account would be recorded as physical '
            . 'cash, and the journal would still balance — so nothing else would catch it.');
    }

    /**
     * The classification must not carry provider identity, or adding a second
     * gateway silently changes which ledger is debited.
     */
    public function test_the_payment_mode_is_provider_neutral(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');

        preg_match_all("/'payment_mode'\s*=>\s*'([^']+)'/", $src, $m);

        foreach ($m[1] as $mode) {
            foreach (['razorpay', 'cashfree', 'payu', 'easebuzz', 'stripe', 'paytm'] as $provider) {
                $this->assertStringNotContainsStringIgnoringCase($provider, $mode,
                    "payment_mode '{$mode}' names a gateway. payment_mode is an ACCOUNTING "
                    . 'CLASSIFICATION consumed by Operations_accounting to select the '
                    . 'cash/bank ledger — a provider name in it means a new provider gets a '
                    . 'different ledger, which is how ACC-PM1 happened.');
            }
        }
    }

    /**
     * Provenance must survive the fix — the old string was carrying gateway
     * identity implicitly, and dropping it entirely would lose real information.
     *
     * PA-CORE-1 (2026-08-24): this originally counted the literal
     * `"'gateway'   => 'razorpay'"` — which was itself the defect the
     * provider-agnostic audit found: every receipt claimed 'razorpay'
     * regardless of which adapter actually processed the payment.
     * `PaymentProviderProvenanceTruthfulnessTest` now proves each site reads a
     * TRUTHFUL value (derived from `Payment_service::verify_payment()`'s
     * `get_name()`, or the order's own recorded gateway). This test keeps its
     * original, narrower job: prove a `gateway` key exists on every receipt
     * payload at all, whatever its right-hand side — literal, variable, or
     * expression — so provenance is not silently dropped a second time.
     */
    public function test_gateway_provenance_is_preserved_on_the_receipt(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');

        $payloads = substr_count($src, "'extra_receipt_fields'");
        preg_match_all("/'gateway'\s*=>\s*[^,]+,/", $src, $m);
        $gateways = 0;
        foreach ($m[0] as $line) {
            // Only count occurrences that sit inside an extra_receipt_fields
            // block, not e.g. fetch_online_payments()'s unrelated display row.
            if (strpos($line, 'gateway_order_id') !== false) continue;
            $gateways++;
        }

        $this->assertGreaterThanOrEqual($payloads, $gateways,
            "{$payloads} receipt payloads but only {$gateways} lines assign a 'gateway' key. "
            . 'Removing the provider name from payment_mode is only correct if the identity '
            . 'is recorded somewhere that is not an accounting classification.');

        // The three known receipt-writing sites must each read a TRUTHFUL
        // source, not a literal — the exact defect PA-CORE-1 fixed.
        foreach (['$gatewayNameParent', '$gatewayName',
                  "(string) (\$order['gateway'] ?? 'unknown')"] as $truthfulSource) {
            $this->assertStringContainsString("'gateway'   => {$truthfulSource},", $src,
                "expected a receipt-provenance site reading {$truthfulSource}, and did not "
                . 'find one — has a write site regressed to a hardcoded literal?');
        }
    }

    /**
     * ── THE THREE SURFACES SOLVED THIS DIFFERENTLY, AND ONE DIDN'T ────────
     *
     * `Operations_accounting` offers exactly two ways to reach the right
     * ledger, and each collection surface must use one of them:
     *
     *   1. an explicit `bankLedgerCode` — wins outright (first branch)
     *   2. a `payment_mode` that is a member of the classification list
     *
     * Admission uses (2): `'payment_mode' => 'online'  // engine → Dr Bank`.
     * The admin counter uses (1): its JS appends `bankLedgerCode` from the
     * selected account's `data-code` whenever `data-isbank === '1'`.
     * The parent fee path used NEITHER — a compound mode and no ledger code —
     * which is ACC-PM1.
     *
     * The counter's mechanism is the fragile one: its payment_mode is a Chart-of-
     * Accounts NAME chosen by the operator ("HDFC Bank Current A/c"), which
     * classifies as nothing. It is correct ONLY because the code accompanies it.
     * Deleting that one JS line would silently route every bank collection to
     * Cash in Hand, and the journal would still balance.
     */
    public function test_the_counter_sends_a_ledger_code_because_its_mode_cannot_classify(): void
    {
        $view = (string) file_get_contents(APPPATH . 'views/fees_counter.php');

        $this->assertStringContainsString("fd.append('bankLedgerCode'", $view,
            'the fee counter no longer submits the selected ledger code. Its payment_mode '
            . 'is a Chart-of-Accounts NAME ("HDFC Bank Current A/c"), which matches nothing '
            . 'in the classification list — so without the code, every bank collection '
            . 'would be debited to Cash in Hand and the journal would still balance.');

        $this->assertStringContainsString("data-isbank", $view,
            'the bank/cash distinction the ledger-code submission depends on is gone');
    }

    /**
     * Admission reaches the right ledger the other way. Pinned so the two
     * surfaces cannot both drift to "neither mechanism", which is the state the
     * parent fee path was in.
     */
    public function test_admission_uses_a_classifying_payment_mode(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Admission_public.php');

        preg_match_all("/'payment_mode'\s*=>\s*'([^']+)'/", $src, $m);
        $this->assertNotEmpty($m[1], 'admission no longer sets payment_mode literals');

        foreach ($m[1] as $mode) {
            $classifies = $this->resolvesToBank($mode)
                || in_array(strtolower(trim($mode)), ['cash', 'offline'], true);

            $this->assertTrue($classifies,
                "admission sends payment_mode '{$mode}', which classifies as neither a bank "
                . 'mode nor cash. It would be debited to account 1010 regardless of how the '
                . 'money actually arrived.');
        }
    }

    // ══ REFUNDS — CORRECT TODAY, BUT BY A DIFFERENT MECHANISM ═══════════════

    /**
     * Refunds were never affected by ACC-PM1, and the reason is the useful part:
     * `Fee_management::process_refund` validates against a CLOSED vocabulary and
     * rejects anything else outright —
     *
     *     $validModes = ['cash', 'bank_transfer', 'cheque', 'online'];
     *
     * — whereas the collection path accepted a free-form string. Validating the
     * vocabulary is what made the difference, not the classifier.
     *
     * ── PM-2 · THE DRIFT THIS TEST PREDICTED, NOW CLOSED ──────────────────
     * When this file was written the two lists HAD drifted. The refund journals
     * classified against their own `['bank_transfer','cheque','online','upi','neft']`,
     * which accepted `upi`/`neft` that $validModes can never produce and omitted
     * `bank`/`rtgs` that the COLLECTION list accepts. The note here warned that
     * adding `'rtgs'` to $validModes — an obviously reasonable change — would
     * silently credit refunds from Cash in Hand.
     *
     * PM-2 closed it by pointing BOTH refund journals at the same
     * `Operations_accounting::NON_CASH_MODES` the collection path uses. There is
     * one list now, so the two cannot disagree by construction.
     *
     * The assertion below is unchanged in meaning and still worth keeping: it
     * checks every mode the ENDPOINT accepts classifies as bank or cash. A future
     * $validModes entry outside the shared set would still be a silent Cash
     * credit, because the refund path has no bank-ledger-code escape hatch — the
     * mode is the only signal deciding the ledger.
     */
    public function test_every_accepted_refund_mode_reaches_the_correct_ledger(): void
    {
        $ctrl = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');
        $acct = (string) file_get_contents(APPPATH . 'libraries/Operations_accounting.php');

        $this->assertSame(1, preg_match("/\\\$validModes = \[([^\]]+)\]/", $ctrl, $vm),
            'the refund mode allowlist was renamed or removed — without it the refund path '
            . 'accepts free-form modes, which is exactly what caused ACC-PM1 on collection');

        $accepted = array_map(
            fn($s) => strtolower(trim($s, " '\"")),
            explode(',', $vm[1])
        );

        // PM-3: both refund journals now route through the one shared
        // classifier instead of holding their own copy of the in_array test.
        // The invariant PM-2 closed is unchanged and more strongly held; only
        // the expression that expresses it moved.
        $this->assertSame(2, substr_count($acct, "self::resolveCashBankCode(\$coa, '', \$refundMode)"),
            'a refund journal no longer resolves through the shared classifier, so it can drift '
            . 'from the collection path again — which is exactly the drift PM-2 closed');
        $this->assertStringNotContainsString('in_array($refundMode, self::NON_CASH_MODES, true)', $acct,
            'an inline refund-mode test is back alongside the shared classifier — two copies is '
            . 'how PM-2 happened');

        $bankModes = $this->bankModes();

        foreach ($accepted as $mode) {
            $classifies = in_array($mode, $bankModes, true) || $mode === 'cash';

            $this->assertTrue($classifies,
                "refund mode '{$mode}' is accepted by the endpoint but classifies as neither "
                . 'a bank mode nor cash, so it would be credited from account 1010 (Cash in '
                . 'Hand) however the money actually leaves. Unlike collection, the refund '
                . 'path has NO bank-ledger-code branch to rescue it — the mode is the only '
                . 'signal.');
        }
    }

    /**
     * The parent paths supply no bank_ledger_code, so the FIRST branch cannot
     * rescue a misclassified mode. Pinned because if that ever changes, the
     * relationship between these two facts changes too.
     */
    public function test_the_parent_paths_still_rely_on_the_mode_not_an_explicit_bank_code(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');

        $this->assertSame(0, substr_count($src, 'bank_ledger_code'),
            'a payment path now supplies bank_ledger_code. That is not wrong — it takes '
            . 'priority over payment_mode — but it changes which mechanism is actually '
            . 'choosing the ledger, and ACC-PM1 should be re-derived against it.');
    }
}
