<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/PaymentProviderInterface.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  P17 — CASHFREE PAYMENTS ADAPTER  (⚠ NOT PRODUCTION-VERIFIED — see below)
 * ══════════════════════════════════════════════════════════════════════════
 *
 * The second real provider, selected in P16 on evidence rather than
 * popularity. Implemented entirely behind PaymentProviderInterface: no
 * Billing V2 core file knows this class exists.
 *
 * ── ⚠ VERIFICATION STATUS — READ BEFORE ENABLING ─────────────────────────
 * This adapter is written against Cashfree's CURRENT published API
 * documentation and passes the shared provider contract suite. It has NOT
 * been exercised against the live Cashfree API, because no Cashfree merchant
 * credentials exist for ZenX.
 *
 * Per this programme's own rule — "a provider is SUPPORTED only if its
 * complete required flow is actually implemented AND tested" — Cashfree is
 * therefore **IMPLEMENTED, NOT SUPPORTED**. It is deliberately NOT registered
 * in Payment_gateway_selector::adapter_class(), so no tenant can select it,
 * and Fee_management's $validProviders does not accept it. Registering it is
 * a separate, deliberate step that must follow a real sandbox integration
 * test. Shipping it as selectable on documentation alone would repeat exactly
 * the PA-UI-1 defect this programme opened by fixing: a UI offering a
 * provider the system cannot actually honour.
 *
 * ── SOURCES (retrieved 2026-08-24) ───────────────────────────────────────
 *   Create Order : https://www.cashfree.com/docs/api-reference/payments/latest/orders/create
 *   Webhooks     : https://www.cashfree.com/docs/payments/online/webhooks/overview
 *
 * ── WHY CASHFREE, AND WHY IT IS "CASE B" ─────────────────────────────────
 * Same-day onboarding (vs Razorpay 2–3 days, PayU longer), flat ~1.6% MDR,
 * T+2 settlement with instant available — all reasonable for schools.
 *
 * But its checkout is driven by a `payment_session_id` consumed by Cashfree's
 * OWN Android SDK (`com.cashfree.pg`), which the ZenX Parent App does not
 * bundle. It is therefore **CASE B: a new checkout technology**, and adding it
 * legitimately requires a Parent App release. That is not an architectural
 * failure — the capability contract already DESCRIBES it correctly
 * (`checkout_type => 'webview'`); the app simply cannot render an engine it
 * does not contain.
 *
 * ── WHAT STAYS OUT OF THIS CLASS ─────────────────────────────────────────
 * No Firestore. No receipts. No allocation. No accounting. No amount
 * authority — `fetch_payment()` reports what Cashfree says was captured and
 * the CORE compares it (MONEY-1). This adapter translates protocol; it never
 * decides money.
 */
class Payment_gateway_cashfree implements PaymentProviderInterface
{
    /** Cashfree pins behaviour to a dated API version; sent on every call. */
    private const API_VERSION = '2026-01-01';

    private const HOST_LIVE    = 'https://api.cashfree.com/pg';
    private const HOST_SANDBOX = 'https://sandbox.cashfree.com/pg';

    /** @var string */ private $clientId = '';
    /** @var string */ private $clientSecret = '';
    /** @var string */ private $mode = 'test';

    public function __construct(array $params = [])
    {
        // Mapped from ZenX's own config field names so the provider config
        // shape stays uniform across adapters — the resolver and the admin
        // screens must not need per-provider field knowledge.
        $this->clientId     = (string) ($params['api_key']    ?? '');
        $this->clientSecret = (string) ($params['api_secret'] ?? '');
        $this->mode         = strtolower(trim((string) ($params['mode'] ?? 'test')));
    }

    public function get_name(): string
    {
        return 'cashfree';
    }

    /**
     * `refunds` is FALSE even though Cashfree's API supports them: ZenX has no
     * refund execution path at all (PA-CON-2), and a capability flag is a
     * promise about what THIS SYSTEM does, not about what the provider could
     * do. `simulatable` is false — this is a real provider.
     */
    public function capabilities(): array
    {
        return [
            'checkout_type' => 'webview',   // payment_session_id → Cashfree SDK/hosted
            'cards'         => true,
            'upi'           => true,
            'net_banking'   => true,
            'wallets'       => true,
            'webhooks'      => true,
            'refunds'       => false,
            'simulatable'   => false,
            // GET /pg/orders/{id}/payments returns payment_amount for the
            // SUCCESS attempt, normalized to amount_paise by fetch_payment().
            'reports_captured_amount' => true,
        ];
    }

    private function host(): string
    {
        return $this->mode === 'live' ? self::HOST_LIVE : self::HOST_SANDBOX;
    }

    private function assertCredentials(): void
    {
        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new \RuntimeException('Cashfree: client id / secret not configured.');
        }
    }

    /**
     * POST /pg/orders
     *
     * Returns the SAME normalized shape every adapter returns —
     * `order_id` is the caller-facing reference. Cashfree's own
     * `payment_session_id` is what its checkout actually consumes, so it is
     * passed through for the client contract to relay; it is not a secret
     * (it is issued specifically for the client to use).
     */
    public function create_order(float $amount, string $receiptRef = '', array $meta = []): array
    {
        $this->assertCredentials();

        if ($amount <= 0) {
            throw new \RuntimeException('Cashfree: amount must be greater than zero.');
        }

        // Cashfree requires customer_id (3-50 alnum) and a 10-digit phone.
        // A missing phone is a CONFIGURATION problem, not something to paper
        // over with a fake number — a fabricated customer identity is the same
        // defect class as a fabricated provider identity.
        $customerId    = preg_replace('/[^A-Za-z0-9]/', '', (string) ($meta['student_id'] ?? ''));
        $customerPhone = preg_replace('/[^0-9]/', '', (string) ($meta['customer_phone'] ?? ''));

        if (strlen($customerId) < 3) {
            throw new \RuntimeException('Cashfree: customer_id must be at least 3 alphanumeric characters.');
        }

        $body = [
            'order_amount'   => round($amount, 2),
            'order_currency' => 'INR',
            'order_id'       => substr($receiptRef, 0, 45),
            'customer_details' => [
                'customer_id'    => substr($customerId, 0, 50),
                'customer_phone' => $customerPhone,
            ],
        ];

        $resp = $this->request('POST', '/orders', $body);

        if (empty($resp['payment_session_id']) || empty($resp['order_id'])) {
            $msg = $resp['message'] ?? 'Cashfree order creation failed.';
            throw new \RuntimeException('Cashfree: ' . $msg);
        }

        return [
            'order_id'           => (string) $resp['order_id'],
            'amount'             => round($amount, 2),
            'amount_paise'       => (int) round($amount * 100),
            'currency'           => (string) ($resp['order_currency'] ?? 'INR'),
            'status'             => (string) ($resp['order_status'] ?? 'ACTIVE'),
            'gateway'            => 'cashfree',
            // Client-facing, non-secret: this is what the checkout consumes.
            'payment_session_id' => (string) $resp['payment_session_id'],
            'cf_order_id'        => (string) ($resp['cf_order_id'] ?? ''),
            'created_at'         => date('c'),
        ];
    }

    /**
     * Cashfree's callback/webhook field names, normalized to the same triple
     * every other adapter returns. Falls back to generic keys so a normalized
     * caller works unchanged.
     */
    public function parse_callback(array $body): array
    {
        // Webhook payloads nest under `data`; direct callbacks do not.
        $data    = is_array($body['data'] ?? null) ? $body['data'] : $body;
        $order   = is_array($data['order'] ?? null) ? $data['order'] : [];
        $payment = is_array($data['payment'] ?? null) ? $data['payment'] : [];

        return [
            'order_id'   => trim((string) ($order['order_id'] ?? $body['order_id'] ?? '')),
            'payment_id' => trim((string) ($payment['cf_payment_id'] ?? $body['cf_payment_id'] ?? $body['payment_id'] ?? '')),
            // Cashfree signs the TRANSPORT (headers), not a body field — see
            // verify_webhook_signature(). There is no in-body signature, and
            // inventing one here would be worse than returning empty.
            'signature'  => trim((string) ($body['signature'] ?? '')),
        ];
    }

    /**
     * ── PAY-OPS-4 · WHICH WEBHOOK EVENTS MAY MOVE MONEY ─────────────────
     *
     * The sibling of `Payment_gateway_razorpay::classify_event()`, and the
     * reason that method lives on the adapter rather than in one shared
     * table: Cashfree and Razorpay do not speak the same event vocabulary.
     *
     *   field name      Razorpay `event`          Cashfree `type`
     *   success         payment.captured          PAYMENT_SUCCESS_WEBHOOK
     *   failure         payment.failed            PAYMENT_FAILED_WEBHOOK
     *   cancellation    (does not exist)          PAYMENT_USER_DROPPED_WEBHOOK
     *
     * Cashfree DOES have a genuine cancellation event — a parent who opens
     * checkout and abandons it — and it is handled here for that reason, not
     * because a cancellation was wanted for symmetry with Razorpay, which has
     * none. Handling only what a provider actually sends is the whole point
     * of putting this on the adapter.
     *
     * Cashfree also carries the per-payment verdict inside the body at
     * `data.payment.payment_status` ∈ SUCCESS | FAILED | USER_DROPPED |
     * PENDING. It is consulted as a SECOND gate, never as a substitute: an
     * envelope typed SUCCESS whose payment row says PENDING is contradictory,
     * and the safe reading of a contradiction is "not a success".
     *
     * Note this classification is advisory in exactly the same way Razorpay's
     * is — the core still re-confirms with `fetch_payment()` before any money
     * moves, and this adapter's `fetch_payment()` returns `[]` unless it finds
     * a row Cashfree itself marks SUCCESS.
     *
     * @param  array $body decoded webhook body
     * @return array{class: string, event: string, reason: string}
     */
    public function classify_event(array $body): array
    {
        // ── SCALAR-GUARDED, AND NOT INCIDENTALLY ────────────────────────
        // A webhook body is JSON off the network. `{"event": ["payment.captured"]}`
        // decodes to an ARRAY, and `(string)` on an array raises "Array to
        // string conversion" - in PHP 8 a WARNING, not a Throwable, so a
        // try/catch at the call site does NOT contain it and the value silently
        // becomes the literal string "Array".
        //
        // Written inline rather than delegated to a shared helper on
        // Payment_gateway_selector: the selector INSTANTIATES this adapter, and
        // an adapter that called back into the selector would be a class this
        // file does not load. CI3 loads libraries one at a time, so that is a
        // fatal "class not found" the moment an adapter is used without the
        // selector - the exact FEE-W1 failure mode this money path has already
        // been bitten by once.
        //
        // Booleans are scalar but never an event name, so `{"event": true}`
        // must not become "1".
        $rawType = $body['type'] ?? '';
        $type    = (is_scalar($rawType) && !is_bool($rawType))
            ? strtoupper(trim((string) $rawType))
            : '';

        if ($type === '') {
            return [
                'class'  => 'ignore',
                'event'  => '',
                'reason' => 'no `type` field — not a recognisable Cashfree webhook envelope',
            ];
        }

        // The per-payment verdict, when the envelope carries one.
        $data    = is_array($body['data'] ?? null) ? $body['data'] : [];
        $payment = is_array($data['payment'] ?? null) ? $data['payment'] : [];
        $rawStatus = $payment['payment_status'] ?? '';
        $status    = (is_scalar($rawStatus) && !is_bool($rawStatus))
            ? strtoupper(trim((string) $rawStatus))
            : '';

        if ($type === 'PAYMENT_SUCCESS_WEBHOOK') {
            // Envelope and payment row must agree. An absent row is tolerated
            // (older payload versions omit it); a row that says anything OTHER
            // than SUCCESS is a contradiction and is refused.
            if ($status !== '' && $status !== 'SUCCESS') {
                return [
                    'class'  => 'ignore',
                    'event'  => $type,
                    'reason' => "envelope says success but data.payment.payment_status is '{$status}' — contradictory, refusing to treat as success",
                ];
            }
            return [
                'class'  => 'success',
                'event'  => $type,
                'reason' => 'provider reports the payment succeeded',
            ];
        }

        if ($type === 'PAYMENT_FAILED_WEBHOOK') {
            return [
                'class'  => 'failure',
                'event'  => $type,
                'reason' => 'provider reports the payment attempt failed',
            ];
        }

        if ($type === 'PAYMENT_USER_DROPPED_WEBHOOK') {
            return [
                'class'  => 'failure',
                'event'  => $type,
                'reason' => 'the payer abandoned checkout — Cashfree\'s cancellation event',
            ];
        }

        return [
            'class'  => 'ignore',
            'event'  => $type,
            'reason' => 'event is not part of the fee-reconciliation contract',
        ];
    }

    /**
     * ⚠ NOT the webhook check. Cashfree has no per-payment "order|payment"
     * body signature of the kind Razorpay uses for its checkout callback;
     * authenticity comes from the webhook HMAC over the raw body
     * (verify_webhook_signature) and from re-fetching the payment
     * server-side.
     *
     * Returning FALSE unconditionally is the honest and SAFE answer: the core
     * treats a false signature as unverified, which is correct — Cashfree
     * payments must be confirmed via fetch_payment()/webhook, never by
     * trusting a client-supplied blob. Returning true here would hand a
     * forged client callback the same authority as a verified one.
     */
    public function verify_signature(string $orderId, string $paymentId, string $signature): bool
    {
        log_message('error', 'Payment_gateway_cashfree::verify_signature — Cashfree has no '
            . 'client-callback body signature; confirmation must come from the webhook HMAC '
            . 'or a server-side fetch_payment(). Refusing (fail-closed).');
        return false;
    }

    /**
     * Webhook authenticity, per Cashfree's published algorithm:
     *
     *     Base64( HMAC-SHA256( x-webhook-timestamp + RAW_BODY, secret ) )
     *
     * The raw, unparsed body is mandatory — re-encoding parsed JSON changes
     * the bytes and the signature will not match.
     */
    public function verify_webhook_signature(array $headers, string $rawBody, array $secrets): bool
    {
        $signature = '';
        $timestamp = '';
        foreach ($headers as $k => $v) {
            $lk = strtolower((string) $k);
            if ($lk === 'x-webhook-signature') $signature = (string) $v;
            if ($lk === 'x-webhook-timestamp') $timestamp = (string) $v;
        }

        $secret = (string) ($secrets['webhook_secret'] ?? $secrets['api_secret'] ?? '');

        // Every uncertainty fails closed — never "no signature means trusted".
        if ($signature === '' || $timestamp === '' || $secret === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $timestamp . $rawBody, $secret, true));
        return hash_equals($expected, $signature);
    }

    /**
     * GET /pg/orders/{order_id}/payments — the provider's OWN record of what
     * was captured. The core compares this against the stored order amount
     * (MONEY-1); this method only reports.
     */
    public function fetch_payment(string $paymentId): array
    {
        $this->assertCredentials();

        $resp = $this->request('GET', '/orders/' . rawurlencode($paymentId) . '/payments');

        // Cashfree returns a LIST of payment attempts for an order. The
        // captured one is authoritative; anything else is not a payment.
        $rows = is_array($resp) ? $resp : [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            if (strtoupper((string) ($row['payment_status'] ?? '')) !== 'SUCCESS') continue;

            $amount = isset($row['payment_amount']) ? round(floatval($row['payment_amount']), 2) : null;
            return [
                'payment_id'   => (string) ($row['cf_payment_id'] ?? $paymentId),
                'amount'       => $amount,
                // Exact minor units for MONEY-1's integer comparison.
                'amount_paise' => $amount === null ? null : (int) round($amount * 100),
                'currency'     => (string) ($row['payment_currency'] ?? 'INR'),
                'status'       => 'captured',
                'gateway'      => 'cashfree',
                'method'       => (string) ($row['payment_group'] ?? ''),
                'fetched_at'   => date('c'),
            ];
        }

        // No successful payment. Empty array — never a fabricated "captured".
        return [];
    }

    // ══════════════════════════════════════════════════════════════════
    //  PRIVATE
    // ══════════════════════════════════════════════════════════════════

    private function request(string $method, string $pathSuffix, ?array $body = null): array
    {
        $ch = curl_init($this->host() . $pathSuffix);
        $headers = [
            'Content-Type: application/json',
            'x-api-version: ' . self::API_VERSION,
            'x-client-id: ' . $this->clientId,
            'x-client-secret: ' . $this->clientSecret,
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw  = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $http === 0) {
            throw new \RuntimeException('Cashfree: transport failure (' . ($err ?: 'no response') . ').');
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Cashfree: non-JSON response (HTTP ' . $http . ').');
        }

        if ($http < 200 || $http >= 300) {
            $msg = (string) ($decoded['message'] ?? ('HTTP ' . $http));
            throw new \RuntimeException('Cashfree: ' . $msg);
        }

        return $decoded;
    }
}
