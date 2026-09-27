<?php
defined('BASEPATH') or exit('No direct script access allowed');

// GW-ORD-1 — the order-reuse rule is shared with Admission_public and defined
// once. A CI library has no `$this->load`, so the dependency is explicit.
// Guarded because the test bootstrap may already have required it.
if (!class_exists('Payment_order_provenance', false)) {
    require_once __DIR__ . '/Payment_order_provenance.php';
}

/**
 * Payment Service — Gateway-agnostic payment orchestrator.
 *
 * Coordinates between the gateway adapter and the fee collection system.
 * This service NEVER trusts the frontend — it verifies everything server-side.
 *
 * Adapter pattern: swap Payment_gateway_mock with Payment_gateway_razorpay
 * by changing the init() call. The service interface stays identical.
 *
 * Firestore-only storage (NO RTDB):
 *   feeOnlineOrders/{schoolId}_{gatewayOrderId}
 *     — full order lifecycle (created → verified → paid|failed)
 *
 * Doc-ID is keyed off the gateway order ID directly so verify_payment()
 * can look it up in O(1) without a separate index doc.
 */
class Payment_service
{
    /** @var CI_Controller */
    private $CI;

    /** @var object Firebase library (RTDB legacy + Firestore helper) */
    private $firebase;

    /** @var string Legacy: base path for RTDB fee data (kept for compatibility but unused for orders) */
    private $feesBase;

    /** @var object Gateway adapter (mock, razorpay, etc.) */
    private $gateway;

    /** @var string Admin performing the operation */
    private $adminId;

    /** @var string School ID (Firestore prefix) */
    private $schoolId = '';

    /** @var string Session year (e.g. "2025-26") */
    private $session = '';

    private const COL_ONLINE_ORDERS = 'feeOnlineOrders';

    /**
     * Initialize the service.
     *
     * @param object $firebase   Firebase library instance
     * @param string $feesBase   Legacy RTDB feesBase (kept for back-compat callers)
     * @param object $gateway    Gateway adapter instance
     * @param string $adminId    Current admin ID
     * @param string $schoolId   Optional explicit school ID (parsed from feesBase if blank)
     * @param string $session    Optional explicit session year (parsed from feesBase if blank)
     */
    public function init($firebase, string $feesBase, $gateway, string $adminId = '', string $schoolId = '', string $session = ''): void
    {
        $this->CI       = &get_instance();
        $this->firebase = $firebase;
        $this->feesBase = $feesBase;
        $this->gateway  = $gateway;
        $this->adminId  = $adminId;

        if ($schoolId === '' || $session === '') {
            // Parse `Schools/{schoolId}/{session}/Accounts/Fees` for back-compat callers
            $parts = explode('/', $feesBase);
            if ($schoolId === '' && isset($parts[1])) $schoolId = $parts[1];
            if ($session  === '' && isset($parts[2])) $session  = $parts[2];
        }
        $this->schoolId = $schoolId;
        $this->session  = $session;
    }

    /**
     * Compose Firestore doc ID for an online order.
     */
    private function _orderDocId(string $gatewayOrderId): string
    {
        return "{$this->schoolId}_{$gatewayOrderId}";
    }

    // ====================================================================
    //  ORDER MANAGEMENT
    // ====================================================================

    /**
     * Create a payment order.
     *
     * Validates input, calls the gateway adapter, and stores the order in Firebase.
     *
     * @param  array $params  {student_id, student_name, class, section, amount, fee_months[]}
     * @return array          {order_id, payment_record_id, amount, gateway}
     * @throws \RuntimeException on validation failure
     */
    public function create_order(array $params): array
    {
        // Validate required fields
        $studentId   = trim($params['student_id'] ?? '');
        $studentName = trim($params['student_name'] ?? '');
        $amount      = round(floatval($params['amount'] ?? 0), 2);
        $feeMonths   = $params['fee_months'] ?? [];
        $class       = trim($params['class'] ?? '');
        $section     = trim($params['section'] ?? '');

        if ($studentId === '') throw new \RuntimeException('Student ID is required.');
        if ($amount <= 0)     throw new \RuntimeException('Amount must be greater than zero.');
        if (empty($feeMonths)) throw new \RuntimeException('At least one fee month must be selected.');

        // ── GW-ORD-1 · A PENDING ORDER IS ONLY REUSABLE ON THE GATEWAY THAT
        //    MINTED IT ────────────────────────────────────────────────────────
        // The dedupe below matches on school+session+student+months+amount and
        // returns the stored `gateway_order_id` verbatim. It did NOT check which
        // adapter produced that id, and an order id is only meaningful to the
        // gateway that issued it.
        //
        // Observed live: a mock-minted `ORD_…` was handed to the real Razorpay
        // checkout, which rejects an id it never issued the instant the sheet
        // renders — indistinguishable, to a parent, from a declined card. Worse,
        // the dedupe kept returning that same dead id on every retry, so fixing
        // the gateway did NOT fix the payment. The bad order permanently
        // poisoned that student+months+amount combination.
        //
        // Reuse now requires the order to belong to the ACTIVE gateway. A
        // mismatch falls through to minting a fresh order rather than reusing a
        // dead one: a duplicate pending row is recoverable, an unpayable order
        // is not. Nothing is mutated here — the stale row is left for the
        // reconciler rather than silently rewritten under a parent's payment.
        $activeGateway = method_exists($this->gateway, 'get_name')
            ? $this->gateway->get_name()
            : 'unknown';

        $existing = $this->_find_pending_order($studentId, $feeMonths, $amount, $activeGateway);
        if ($existing) {
            return [
                'order_id'          => $existing['gateway_order_id'],
                'payment_record_id' => $existing['_id'],
                'amount'            => $existing['amount'],
                // P11: GW-ORD-1 above already PROVED this order belongs to
                // $activeGateway (an unrecorded gateway is refused, not reused),
                // so the old '?? "mock"' was both unreachable AND untruthful.
                // Degrading to the genuinely active gateway keeps it honest even
                // if that guarantee is ever weakened.
                'gateway'           => $existing['gateway'] ?? $activeGateway,
                'existing'          => true,
            ];
        }

        // Create order via gateway adapter
        $receiptRef  = 'FEE_' . $studentId . '_' . date('YmdHis');
        $gwOrder     = $this->gateway->create_order($amount, $receiptRef, [
            'student_id' => $studentId,
        ]);

        $orderId     = $gwOrder['order_id'];
        $gatewayName = method_exists($this->gateway, 'get_name') ? $this->gateway->get_name() : 'unknown';
        $now         = date('c');
        $docId       = $this->_orderDocId($orderId);

        $record = [
            'schoolId'           => $this->schoolId,
            'session'            => $this->session,
            'student_id'         => $studentId,
            'student_name'       => $studentName,
            'class'              => $class,
            'section'            => $section,
            'amount'             => $amount,
            'fee_months'         => $feeMonths,
            'gateway'            => $gatewayName,
            'gateway_order_id'   => $orderId,
            'gateway_payment_id' => '',
            'status'             => 'created',
            'created_at'         => $now,
            'created_by'         => $this->adminId,
            'paid_at'            => '',
            'verified_at'        => '',
        ];

        $this->firebase->firestoreSet(self::COL_ONLINE_ORDERS, $docId, $record);

        log_message('info', "PaymentService: order created docId={$docId} gw_order={$orderId} student={$studentId} amount={$amount}");

        return [
            'order_id'          => $orderId,
            'payment_record_id' => $docId,
            'amount'            => $amount,
            'gateway'           => $gatewayName,
            'existing'          => false,
        ];
    }

    // ====================================================================
    //  PAYMENT SIMULATION (DEV ONLY)
    // ====================================================================

    /**
     * Simulate a payment attempt (mock gateway only).
     *
     * @param  string $orderId  Gateway order ID
     * @return array  Simulation result {status, payment_id, signature, ...}
     */
    public function simulate(string $orderId): array
    {
        $docId = $this->_orderDocId($orderId);
        $order = $this->firebase->firestoreGet(self::COL_ONLINE_ORDERS, $docId);
        if (!is_array($order)) {
            throw new \RuntimeException('Order not found.');
        }
        if (($order['status'] ?? '') === 'paid') {
            throw new \RuntimeException('Order already paid.');
        }

        // Call gateway simulate
        $result = $this->gateway->simulate_payment($orderId, (float) ($order['amount'] ?? 0));

        // Merge simulation result into the order doc (firestoreSet replaces,
        // so spread the existing doc to preserve unrelated fields).
        // Delta + merge, never a full replace from `$order`. That snapshot is
        // read before this point and a webhook can land in between; writing
        // the whole document back would silently revert whatever it wrote.
        $this->firebase->firestoreSet(self::COL_ONLINE_ORDERS, $docId, [
            'last_attempt_at'     => date('c'),
            'last_attempt_status' => $result['status'],
            'gateway_payment_id'  => $result['payment_id'] ?? '',
        ], true);

        $result['record_id'] = $docId;
        return $result;
    }

    // ====================================================================
    //  PAYMENT VERIFICATION
    // ====================================================================

    /** The browser/Parent-App return. Authenticated by the order|payment HMAC. */
    public const SOURCE_FRONTEND = 'frontend';
    /** A server-to-server delivery whose raw body the caller has ALREADY HMAC-verified. */
    public const SOURCE_WEBHOOK  = 'webhook';

    /**
     * Verify a payment callback and mark as paid if valid.
     *
     * Validates:
     *   1. Order exists in Firebase
     *   2. Not already paid (idempotent)
     *   3. The payment is authentic — HOW depends on $source, see below
     *   4. Amount matches
     *
     * ── PAY-OPS-4 · THE TWO SOURCES DO NOT CARRY THE SAME PROOF ─────────
     *
     * This method had exactly one notion of authenticity: the Razorpay
     * browser-return signature, `HMAC(order_id|payment_id, api_secret)`.
     *
     * A webhook never carries that. Razorpay signs a webhook over the RAW BODY
     * with the WEBHOOK secret, in the `X-Razorpay-Signature` header — which is
     * why `Payment_gateway_razorpay::parse_callback()` deliberately returns an
     * empty `signature` for webhook shapes rather than inventing one.
     *
     * So the webhook path reached here with `signature === ''`,
     * `verify_signature()` returned false on its first guard, and every
     * genuinely-signed delivery was refused with "Payment signature
     * verification failed" — a 422 that reads like fraud and is actually a
     * category error. The webhook could never reconcile a payment. Observed on
     * a REAL capture: Razorpay took ₹1,900 (pay_TV7acX8SsAmaf8 /
     * order_TV6eZr9M88GFTI) while the client's token expired mid-verification,
     * and the webhook — the one mechanism that exists for exactly that case —
     * could not settle it.
     *
     * ── WHAT REPLACES IT, AND WHY IT IS NOT WEAKER ──────────────────────
     *
     * NOT "skip verification for webhooks". The webhook's authenticity is
     * established BEFORE this method by the webhook HMAC over the raw body,
     * and is then re-established here from the provider's own record: ZenX
     * asks the gateway what happened to this payment and requires the answer
     * to be `captured`. That is a stronger claim than any signature — a
     * signature proves the message came from the provider, a fetch proves the
     * MONEY IS THERE — and it costs one API call the frontend path already
     * makes anyway (for MONEY-1 amount enforcement), reusing that exact
     * `fetch_payment()` architecture rather than adding a second client.
     *
     * The frontend branch below is byte-for-byte what it always was. A webhook
     * must never become a way to settle a payment the browser return could not.
     *
     * ── ALLOWLIST, NOT DENYLIST ─────────────────────────────────────────
     * Razorpay payment states include created / authorized / captured /
     * refunded / failed. Only `captured` settles. Writing this as "not failed"
     * would admit `authorized` — funds merely HELD, still voidable — and issue
     * a receipt for money the school may never receive.
     *
     * ── RETRYABILITY IS PART OF THE ANSWER ──────────────────────────────
     * A webhook refusal is not one thing. "The provider says this payment
     * failed" is final; "we could not reach the provider" is not. The caller
     * must be able to tell them apart, because marking the second as final
     * discards the gateway's retry — the only remaining path to the money. So
     * every refusal carries `webhook_retryable`.
     *
     * @param  string $orderId    Gateway order ID
     * @param  string $paymentId  Gateway payment ID
     * @param  string $signature  Gateway signature (browser return only)
     * @param  string $source     SOURCE_FRONTEND | SOURCE_WEBHOOK. Anything
     *                            unrecognised is treated as FRONTEND — the
     *                            stricter branch — so a typo cannot open the
     *                            weaker door.
     * @return array  {verified, record_id, order, payment_data, webhook_retryable}
     */
    public function verify_payment(
        string $orderId,
        string $paymentId,
        string $signature,
        string $source = self::SOURCE_FRONTEND
    ): array {
        $isWebhook = ($source === self::SOURCE_WEBHOOK);

        $docId = $this->_orderDocId($orderId);
        $order = $this->firebase->firestoreGet(self::COL_ONLINE_ORDERS, $docId);
        if (!is_array($order)) {
            // The webhook handler resolved this school FROM this order moments
            // ago, so the document demonstrably exists. Failing to read it now
            // is a transport problem, not a missing order — retryable.
            return ['verified' => false, 'error' => 'Order not found.', 'webhook_retryable' => true];
        }

        // Idempotent: already paid or in processing
        $orderStatus = $order['status'] ?? '';
        if ($orderStatus === 'paid') {
            return ['verified' => true, 'record_id' => $docId, 'order' => $order, 'already_paid' => true];
        }
        if ($orderStatus === 'processing') {
            // Another attempt owns this order right now. For a webhook that is
            // precisely a "come back later", never a rejection.
            return [
                'verified' => false,
                'error'    => 'This payment is currently being processed. Please wait.',
                'webhook_retryable' => true,
            ];
        }

        // `$fetched` is shared with the amount block below so a webhook makes
        // ONE provider call, not two: the same fetch that confirms capture also
        // supplies the authoritative amount MONEY-1 compares against.
        $fetched     = null;
        $verifiedVia = 'signature';

        if ($isWebhook) {
            $verifiedVia = 'provider_fetch';
            $fetched     = $this->fetch_payment($paymentId);

            // Unreachable / errored / no successful payment row. Note
            // `Payment_gateway_cashfree::fetch_payment()` returns [] rather
            // than null when it finds no SUCCESS row, so both are refused —
            // and neither is written to the order as a failure, because we do
            // not know that it failed. We know only that we could not confirm.
            if (!is_array($fetched) || $fetched === []) {
                log_message('error',
                    "PaymentService: webhook could not confirm payment with the provider "
                    . "order={$orderId} payment={$paymentId} — nothing applied, retryable");
                return [
                    'verified' => false,
                    'error'    => 'Could not confirm this payment with the payment gateway. Nothing has been applied.',
                    'webhook_retryable' => true,
                ];
            }

            $providerStatus = strtolower(trim((string) ($fetched['status'] ?? '')));
            if ($providerStatus !== 'captured') {
                // The provider has spoken and the answer is not "captured".
                // Terminal: retrying asks the same question and gets the same
                // answer. A LATER genuine capture arrives as its own delivery
                // with its own event id, so nothing here blocks it.
                $this->firebase->firestoreSet(self::COL_ONLINE_ORDERS, $docId, [
                    'status'                 => 'failed',
                    'failed_at'              => date('c'),
                    'failure_reason'         => "Gateway reports payment status '{$providerStatus}', not captured",
                    'gateway_payment_id'     => $paymentId,
                    'gateway_payment_status' => $providerStatus,
                ], true);
                log_message('error',
                    "PaymentService: webhook REFUSED — provider status '{$providerStatus}' "
                    . "order={$orderId} payment={$paymentId}");
                return [
                    'verified' => false,
                    'error'    => 'The payment gateway does not report this payment as captured.',
                    'webhook_retryable' => false,
                ];
            }

            // ── PROVENANCE · the payment must belong to THIS order ──────
            // The frontend path gets this binding for free: its signature is
            // computed over `order_id|payment_id`, so a payment presented
            // against the wrong order cannot produce a valid one. The webhook
            // HMAC covers the body but says nothing about which order a
            // payment belongs to, so the binding is asserted explicitly here
            // from the provider's own record. Checked only when the provider
            // reports an order id — Cashfree's fetch does not.
            $fetchedOrder = trim((string) ($fetched['order_id'] ?? ''));
            if ($fetchedOrder !== '' && $fetchedOrder !== $orderId) {
                log_message('error',
                    "PaymentService: webhook REFUSED — payment {$paymentId} belongs to order "
                    . "{$fetchedOrder}, presented for {$orderId}");
                return [
                    'verified' => false,
                    'error'    => 'This payment belongs to a different order. No money has been applied.',
                    'webhook_retryable' => false,
                ];
            }
        } else {
            // ── FRONTEND · UNCHANGED ────────────────────────────────────
            // Verify signature via gateway adapter
            $sigValid = $this->gateway->verify_signature($orderId, $paymentId, $signature);
            if (!$sigValid) {
                // Delta + merge, never a full replace from `$order`. That snapshot is
                // read before this point and a webhook can land in between; writing
                // the whole document back would silently revert whatever it wrote.
                $this->firebase->firestoreSet(self::COL_ONLINE_ORDERS, $docId, [
                    'status'             => 'failed',
                    'failed_at'          => date('c'),
                    'failure_reason'     => 'Signature verification failed',
                    'gateway_payment_id' => $paymentId,
                ], true);
                log_message('error', "PaymentService: signature FAILED order={$orderId} payment={$paymentId}");
                return [
                    'verified' => false,
                    'error'    => 'Payment signature verification failed.',
                    'webhook_retryable' => false,
                ];
            }
        }

        // Mark as "verified" (signature OK) — NOT "paid" yet. The controller
        // marks "paid" only after fee writes succeed; this prevents orphan
        // "paid" orders with no receipt.
        $now = date('c');
        // Delta + merge, never a full replace from `$order`. That snapshot was
        // read before verify_signature(), which is a NETWORK call to the
        // gateway — a webhook can land during it and write status='paid'.
        // Writing the whole document back would silently revert that to
        // 'verified', losing the paid state for a payment that really was paid.
        $verifiedDelta = [
            'status'             => 'verified',
            'gateway_payment_id' => $paymentId,
            'verified_at'        => $now,
            'verified_by'        => $this->adminId,
            'signature'          => $signature,
            // PAY-OPS-4 · PROVENANCE. Two different proofs now reach this
            // line, and reconciliation must be able to tell which one was
            // presented. Recorded rather than inferred from the (empty)
            // signature, because "no signature" is also what a bug looks like.
            'verified_via'       => $verifiedVia,   // 'signature' | 'provider_fetch'
            'verified_source'    => $source,        // 'frontend'  | 'webhook'
        ];
        $this->firebase->firestoreSet(self::COL_ONLINE_ORDERS, $docId, $verifiedDelta, true);

        // The caller consumes this as $verifyResult['order']. Compose it
        // locally from the snapshot plus the delta rather than persisting the
        // whole document — the return value is a view for this request, not
        // the authority for what the document now contains.
        $patched = array_merge($order, $verifiedDelta);

        $studentId = $order['student_id'] ?? '';
        log_message('info', "PaymentService: VERIFIED via {$verifiedVia} ({$source}) order={$orderId} payment={$paymentId} student={$studentId}");

        // ── BUG-050 / PS-3: amount-mismatch enforcement support ─────────
        // After signature OK, fetch the captured payment from the gateway
        // to retrieve the AUTHORITATIVE amount. Callers (Fee_management
        // _verify_and_process + parent_verify_payment) compare this against
        // the locally-stored order amount to detect tampering. Fail-OPEN
        // policy: if fetch fails OR adapter doesn't support fetch, return
        // gateway_amount=null + gateway_amount_unavailable=true so callers
        // can opt to log + proceed rather than brick payment on Razorpay
        // API outage. Mock adapter returns a sentinel that skips the check.
        $gatewayAmount             = null;
        $gatewayAmountMinor        = null;   // MONEY-1: exact paise when the adapter has it
        $gatewayAmountUnavailable  = true;
        $gatewayPaymentStatus      = '';
        // PAY-OPS-4: the webhook branch ALREADY fetched — that fetch was the
        // authorisation. Reuse it rather than calling the provider a second
        // time for the same payment: two calls could disagree, and the one
        // that authorised is the one the amount must be checked against.
        if ($fetched === null) {
            $fetched = $this->fetch_payment($paymentId);
        }
        if (is_array($fetched)) {
            if (!empty($fetched['mock_skip_amount_check'])) {
                // Mock gateway — leave gateway_amount=null; callers gate
                // telemetry by gateway name so this does NOT emit warnings.
                $gatewayAmountUnavailable = true;
                $gatewayPaymentStatus     = (string) ($fetched['status'] ?? '');
            } else {
                $gatewayAmount            = isset($fetched['amount']) ? round(floatval($fetched['amount']), 2) : null;
                $gatewayAmountUnavailable = ($gatewayAmount === null);
                $gatewayPaymentStatus     = (string) ($fetched['status'] ?? '');

                // ── MONEY-1 · SURFACE THE EXACT MINOR-UNIT AMOUNT ──────────
                // Razorpay settles in PAISE and returns an integer. The adapter
                // already carries it as `amount_paise`, then ALSO derives a
                // rupee float via round($paise / 100, 2) — and only that float
                // was passed on.
                //
                // Callers then compared floats with a ±₹0.50 tolerance. That
                // tolerance is ~50x wider than the ≤₹0.01 error the rounding
                // can introduce, so a genuine capture mismatch of up to fifty
                // paise passed as a match — a blind spot created ONLY by
                // discarding an exact integer that was already in hand.
                //
                // Passing it through lets callers compare exactly when it is
                // available and fall back to the tolerant float comparison when
                // it is not. Deliberately additive: no stored document, no
                // Firestore field, no app model and no accounting value changes
                // representation. A full float→integer migration of the money
                // model is a different, much larger question and is NOT what
                // this does.
                if (isset($fetched['amount_paise']) && is_numeric($fetched['amount_paise'])) {
                    $gatewayAmountMinor = (int) $fetched['amount_paise'];
                }
            }
        }

        return [
            'verified'                    => true,
            'record_id'                   => $docId,
            'order'                       => $patched,
            'already_paid'                => false,
            // PAY-OPS-4: which proof was actually presented, so a caller (and
            // reconciliation) never has to guess from the source string alone.
            'verified_via'                => $verifiedVia,   // 'signature' | 'provider_fetch'
            'webhook_retryable'           => false,          // verified — nothing to retry
            // BUG-050 / PS-3: amount-mismatch enforcement payload (additive).
            'gateway_amount'              => $gatewayAmount,            // float|null, in rupees
            'gateway_amount_minor'        => $gatewayAmountMinor,       // int|null, EXACT paise (MONEY-1)
            'gateway_amount_unavailable'  => $gatewayAmountUnavailable, // bool
            'gateway_payment_status'      => $gatewayPaymentStatus,     // 'captured'|'authorized'|''
            'gateway_name'                => method_exists($this->gateway, 'get_name') ? $this->gateway->get_name() : 'unknown',
            // Whether the ACTIVE adapter can report what the provider actually
            // captured. Callers use this to decide whether the absence of an
            // amount is expected (a simulator has none) or a genuine
            // amount-verification outage worth alerting on. Exposed here so no
            // caller needs a hardcoded list of provider names to answer it —
            // such a list is a second provider authority, and an allowlist
            // shaped one silently disabled the check for every non-Razorpay
            // provider in Admission_public until 2026-08-24.
            //
            // Null (not false) when the adapter does not declare capabilities
            // at all: "did not say" is not the same as "said no", and the
            // caller must be able to fall back rather than assume.
            'gateway_reports_amount'      => method_exists($this->gateway, 'capabilities')
                ? (bool) ($this->gateway->capabilities()['reports_captured_amount'] ?? false)
                : null,
        ];
    }

    /**
     * Fetch a payment from the gateway. Returns null if the adapter does
     * not support fetch OR if the API call fails. Used by verify_payment
     * for defense-in-depth amount-mismatch enforcement (BUG-050 / PS-3).
     *
     * Fail-open by design: a fetch failure must NOT brick a payment that
     * has a valid signature — Razorpay has already captured the funds at
     * this point. Callers decide whether to enforce strictness based on
     * the gateway name and operational policy.
     */
    public function fetch_payment(string $paymentId): ?array
    {
        if ($paymentId === '') {
            return null;
        }
        // P2 #32 (2026-08-12): these three log lines used level 'warning',
        // which is NOT a CodeIgniter 3 log level — Log::write_log() drops any
        // level absent from its ERROR/DEBUG/INFO/ALL map, so every one of them
        // was silently discarded. Compounding it, config.php sets
        // log_threshold = 1 (errors only), so 'info' and 'debug' would also be
        // discarded in production: 'error' is the ONLY level that survives.
        // These are precisely the signals an operator needs when a payment is
        // accepted WITHOUT gateway amount verification, so they must be
        // durable. Prefixed to make clear this is a fail-open telemetry line,
        // not a failed payment — the enforcement decision lives in the callers
        // (Fee_management::parent_verify_payment / the webhook handler) and is
        // deliberately unchanged here.
        if (!method_exists($this->gateway, 'fetch_payment')) {
            log_message('error', 'PAYMENT_AMOUNT_UNVERIFIED (fail-open) PaymentService::fetch_payment — gateway adapter ' . (method_exists($this->gateway, 'get_name') ? $this->gateway->get_name() : 'unknown') . ' lacks fetch_payment; amount enforcement will fail-open.');
            return null;
        }
        try {
            $resp = $this->gateway->fetch_payment($paymentId);
            return is_array($resp) ? $resp : null;
        } catch (\Exception $e) {
            log_message('error', "PAYMENT_AMOUNT_UNVERIFIED (fail-open) PaymentService::fetch_payment failed payment={$paymentId}: " . $e->getMessage());
            return null;
        }
    }

    // ====================================================================
    //  PRIVATE HELPERS
    // ====================================================================

    /**
     * Find an existing pending (unpaid) order for the same student + months
     * + amount. Firestore query: schoolId + session + studentId + status='created'.
     *
     * Including amount in the dedup key is critical for partial-payment
     * flows: a parent who tried "pay full ₹2,800" and abandoned, then
     * came back to "pay ₹1,000 partial" must NOT be handed the cached
     * ₹2,800 order — Razorpay would reject the verify because the captured
     * amount won't match what the parent actually intended to pay.
     */
    /**
     * @param string $activeGateway name of the adapter now configured. An order
     *   minted by a different adapter is NOT reusable — its order id is
     *   meaningless to the current gateway (GW-ORD-1). Empty string disables the
     *   check, which no caller should do.
     */
    private function _find_pending_order(
        string $studentId,
        array $months,
        float $amount,
        string $activeGateway = ''
    ): ?array {
        $sortedMonths = $months;
        sort($sortedMonths);
        $monthKey = implode(',', $sortedMonths);

        try {
            $rows = $this->firebase->firestoreQuery(self::COL_ONLINE_ORDERS, [
                ['schoolId',   '==', $this->schoolId],
                ['session',    '==', $this->session],
                ['student_id', '==', $studentId],
                ['status',     '==', 'created'],
            ]);
        } catch (\Exception $e) {
            // P2 #32 — 'warning' is not a CI3 level and was being dropped.
            log_message('error', 'PaymentService::_find_pending_order query failed: ' . $e->getMessage());
            return null;
        }

        foreach ((array) $rows as $row) {
            $order = is_array($row['data'] ?? null) ? $row['data'] : $row;
            if (!is_array($order)) continue;
            $orderMonths = $order['fee_months'] ?? [];
            if (!is_array($orderMonths)) continue;
            sort($orderMonths);
            $sameMonths = implode(',', $orderMonths) === $monthKey;
            $sameAmount = abs((float)($order['amount'] ?? 0) - $amount) < 0.005;
            if (!$sameMonths || !$sameAmount) continue;

            // GW-ORD-1 — the order id is only meaningful to the gateway that
            // issued it. Reusing one across adapters hands the client an id the
            // gateway will refuse, and keeps refusing on every retry.
            if ($activeGateway !== '') {
                $orderGateway = $order['gateway'] ?? '';
                if (!Payment_order_provenance::matchesActiveGateway($orderGateway, $activeGateway)) {
                    log_message('error',
                        'PaymentService: GW-ORD-1 skipping pending order ['
                        . (string) ($order['gateway_order_id'] ?? '?') . '] minted by gateway ['
                        . ($orderGateway !== '' ? $orderGateway : 'UNRECORDED')
                        . '] while active gateway is [' . $activeGateway
                        . ']. A fresh order will be created; the stale row is left for '
                        . 'reconciliation. student=' . $studentId);
                    continue;
                }
            }

            $order['_id'] = (string) ($row['id'] ?? $this->_orderDocId((string) ($order['gateway_order_id'] ?? '')));
            return $order;
        }

        return null;
    }

    /**
     * P2 (2026-08-24) — thin passthrough, not a decision. Delegates field
     * normalization to the active adapter's own `parse_callback()` when it
     * has one; falls back to the pre-P2 generic key set otherwise, so an
     * adapter that has not been extracted yet (or never needs it — mock,
     * unavailable) does not break. Duck-typed the same way `get_name()` and
     * `fetch_payment()` already are here — this method contains no provider
     * name and never will.
     *
     * The controller calls THIS, never the raw adapter, so provider protocol
     * relocation cannot accidentally hand the controller a direct handle to
     * bypass Payment_service's other guarantees.
     */
    public function parse_callback(array $body): array
    {
        if (method_exists($this->gateway, 'parse_callback')) {
            return $this->gateway->parse_callback($body);
        }
        return [
            'order_id'   => trim((string) ($body['gateway_order_id'] ?? $body['order_id'] ?? '')),
            'payment_id' => trim((string) ($body['gateway_payment_id'] ?? $body['payment_id'] ?? '')),
            'signature'  => trim((string) ($body['signature'] ?? '')),
        ];
    }
}
