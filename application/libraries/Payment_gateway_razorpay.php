<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/PaymentProviderInterface.php';

/**
 * Razorpay Payment Gateway Adapter
 *
 * P1 (2026-08-24): formally implements PaymentProviderInterface. Purely
 * additive — no method signature changed, none removed. `capabilities()` is
 * the only new method; see PaymentProviderInterface's docblock for what it
 * must and must not claim.
 *
 * Uses raw cURL against the Razorpay REST API — no SDK dependency.
 *
 * Credentials are injected by Payment_service via the CI loader params:
 *   $this->load->library('Payment_gateway_razorpay', [
 *       'api_key'    => 'rzp_test_xxx',
 *       'api_secret' => 'xxxxxxxxxxxxxxxx',
 *       'api_base'   => 'https://api.razorpay.com/v1',   // optional
 *   ]);
 */
class Payment_gateway_razorpay implements PaymentProviderInterface
{
    /** @var string */
    private $keyId = '';

    /** @var string */
    private $keySecret = '';

    /** @var string */
    private $apiBase = 'https://api.razorpay.com/v1';

    public function __construct(array $params = [])
    {
        $this->keyId     = (string) ($params['api_key']    ?? '');
        $this->keySecret = (string) ($params['api_secret'] ?? '');
        if (!empty($params['api_base'])) {
            $this->apiBase = rtrim((string) $params['api_base'], '/');
        }
    }

    /**
     * Create a Razorpay order.
     *
     * @throws RuntimeException on API error
     */
    public function create_order(float $amount, string $receiptRef = '', array $meta = []): array
    {
        $this->_assert_credentials();

        $amountPaise = (int) round($amount * 100);
        if ($amountPaise <= 0) {
            throw new \RuntimeException('Razorpay: amount must be greater than zero.');
        }

        // Razorpay requires receipt <= 40 chars; trim if needed.
        $receipt = substr($receiptRef, 0, 40);

        $notes = [];
        foreach ($meta as $k => $v) {
            // Razorpay notes must be string and <= 15 keys, 256 chars each.
            if (is_scalar($v)) {
                $notes[(string) $k] = substr((string) $v, 0, 256);
            }
            if (count($notes) >= 15) break;
        }

        $body = [
            'amount'          => $amountPaise,
            'currency'        => 'INR',
            'receipt'         => $receipt,
            'payment_capture' => 1,
            'notes'           => (object) $notes,
        ];

        $resp = $this->_request('POST', '/orders', $body);

        if (empty($resp['id'])) {
            $msg = $resp['error']['description'] ?? 'Razorpay order creation failed.';
            throw new \RuntimeException('Razorpay: ' . $msg);
        }

        return [
            'order_id'     => $resp['id'],
            'amount'       => round($amount, 2),
            'amount_paise' => $amountPaise,
            'currency'     => $resp['currency'] ?? 'INR',
            'receipt'      => $resp['receipt']  ?? $receipt,
            'status'       => $resp['status']   ?? 'created',
            'gateway'      => 'razorpay',
            'created_at'   => date('c'),
        ];
    }

    /**
     * Razorpay payments are driven by the client SDK; there is no
     * server-side "simulate". Included to satisfy the adapter interface.
     */
    public function simulate_payment(string $orderId, float $amount): array
    {
        throw new \RuntimeException(
            'Razorpay adapter does not support simulate_payment. Use the mock adapter in test mode or trigger payment via the Razorpay checkout.'
        );
    }

    /**
     * Verify a Razorpay payment signature:
     *   expected = HMAC_SHA256(order_id + "|" + payment_id, key_secret)
     * Uses hash_equals for timing-safe comparison.
     */
    public function verify_signature(string $orderId, string $paymentId, string $signature): bool
    {
        if ($signature === '' || $this->keySecret === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $this->keySecret);
        return hash_equals($expected, $signature);
    }

    /**
     * Fetch payment details from Razorpay (GET /v1/payments/{id}).
     *
     * BUG-050 / PS-3: used by Payment_service::verify_payment() to retrieve
     * the captured amount for defense-in-depth amount-mismatch enforcement.
     * Razorpay returns amount in PAISE (integer); we normalize to rupees so
     * the caller can compare directly against the locally-stored order amount
     * (which is in rupees).
     *
     * @throws RuntimeException on API error
     * @return array {payment_id, amount_paise, amount, status, currency,
     *                method, order_id, captured, fetched_at}
     */
    public function fetch_payment(string $paymentId): array
    {
        $this->_assert_credentials();
        if ($paymentId === '') {
            throw new \RuntimeException('Razorpay: paymentId is required.');
        }

        $resp = $this->_request('GET', '/payments/' . rawurlencode($paymentId));

        if (empty($resp['id'])) {
            $msg = $resp['error']['description'] ?? 'Razorpay payment fetch failed.';
            throw new \RuntimeException('Razorpay: ' . $msg);
        }

        $amountPaise = (int) ($resp['amount'] ?? 0);
        return [
            'payment_id'   => (string) $resp['id'],
            'order_id'     => (string) ($resp['order_id'] ?? ''),
            'amount_paise' => $amountPaise,
            'amount'       => round($amountPaise / 100, 2),
            'currency'     => (string) ($resp['currency'] ?? 'INR'),
            'status'       => (string) ($resp['status']   ?? ''),
            'method'       => (string) ($resp['method']   ?? ''),
            'captured'     => !empty($resp['captured']),
            'fetched_at'   => date('c'),
        ];
    }

    /** @return string */
    public function get_name(): string
    {
        return 'razorpay';
    }

    /**
     * P1: what this adapter actually does today, not what Razorpay's API
     * could theoretically support. `refunds` is false because no adapter in
     * this codebase implements refunds (PA-CON-2) — Razorpay's API does, but
     * ZenX's refund path is entirely internal accounting reversal, so
     * claiming true here would be a promise nothing honours.
     */
    public function capabilities(): array
    {
        return [
            'checkout_type' => 'native_sdk',   // com.razorpay:checkout Android SDK
            'cards'         => true,
            'upi'           => true,
            'net_banking'   => true,
            'wallets'       => true,
            'webhooks'      => true,
            'refunds'       => false,
            'simulatable'   => false,          // simulate_payment() throws by design
            // fetch_payment() returns amount_paise from Razorpay's own record,
            // which is what MONEY-1 compares against.
            'reports_captured_amount' => true,
        ];
    }

    /**
     * Provider-specific credential validation (2026-08-24).
     *
     * Razorpay enforces strict key-prefix conventions:
     *   rzp_live_*  ⇒ ONLY works against the live account
     *   rzp_test_*  ⇒ ONLY works against the test account
     *
     * A mismatch is not cosmetic: a live key saved with mode='test' silently
     * disables HMAC enforcement on the webhook handler, so anyone who knows
     * the endpoint URL can post a spoofed payload.
     *
     * This rule lived in `Fee_management::save_gateway_config()` behind
     * `$provider === 'razorpay'` — provider-specific protocol knowledge sitting
     * in the core, and an allowlist that would silently skip validation for
     * every other provider. It belongs to the adapter that knows the
     * convention; the core now just asks whichever adapter is configured.
     *
     * Empty/legacy/partner keys pass through unchecked: Razorpay's current docs
     * lock only these two prefixes, and refusing an unrecognised format would
     * lock out a key shape we simply have not seen.
     *
     * @return array{ok: bool, error: string}
     */
    public function validate_credentials(array $config): array
    {
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $mode   = strtolower(trim((string) ($config['mode'] ?? '')));

        if ($apiKey === '') {
            return ['ok' => true, 'error' => ''];   // nothing to contradict yet
        }

        if (strpos($apiKey, 'rzp_live_') === 0 && $mode !== 'live') {
            return ['ok' => false, 'error' =>
                'Mode/key mismatch: rzp_live_* key requires mode=live. Either change the '
                . 'mode or use a rzp_test_* key.'];
        }
        if (strpos($apiKey, 'rzp_test_') === 0 && $mode !== 'test') {
            return ['ok' => false, 'error' =>
                'Mode/key mismatch: rzp_test_* key requires mode=test. Either change the '
                . 'mode or use a rzp_live_* key.'];
        }

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Exercise the stored credentials against the provider (2026-08-24).
     *
     * Relocated from `Fee_management::test_gateway_connection()`, which
     * performed this Razorpay API call inline behind
     * `if ($provider !== 'razorpay')`. That guard was honest but structural
     * dead-weight: a second ENABLED provider would be untestable, and its
     * refusal message ("has no adapter") became untrue the moment a second
     * adapter existed.
     *
     * ── DESIGN CHOICES CARRIED OVER DELIBERATELY ─────────────────────────
     *  • A READ, never an order. A "test" button that quietly accumulates
     *    orders in a merchant account is one operators stop pressing.
     *  • Transport failure is reported as DISTINCT from a credential failure.
     *    Telling an operator their keys are wrong when the server simply
     *    cannot reach the internet sends them to regenerate good keys.
     *  • The secret is used and never returned.
     *
     * @return array{ok: bool, message: string, error: string, kind: string}
     *         kind ∈ config | transport | credentials | http | ok
     */
    public function test_connection(array $config): array
    {
        $key    = trim((string) ($config['api_key']    ?? ''));
        $secret = trim((string) ($config['api_secret'] ?? ''));
        $mode   = strtolower(trim((string) ($config['mode'] ?? '')));

        if ($key === '' || $secret === '') {
            return ['ok' => false, 'kind' => 'config', 'message' => '',
                    'error' => 'The API key or secret is missing. Save both, then test again.'];
        }

        // Reuses the SAME rule save_gateway_config enforces — one definition,
        // not a restatement that can drift from it.
        $creds = $this->validate_credentials(['api_key' => $key, 'mode' => $mode]);
        if (empty($creds['ok'])) {
            return ['ok' => false, 'kind' => 'config', 'message' => '',
                    'error' => $creds['error'] . ' Fix this before testing — a live key in '
                             . 'test mode disables webhook signature checking.'];
        }

        // The cheapest authenticated GET Razorpay offers. count=1 keeps the
        // response tiny; only the status code matters.
        $ch = curl_init($this->apiBase . '/payments?count=1');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $key . ':' . $secret,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body    = curl_exec($ch);
        $http    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($body === false || $http === 0) {
            return ['ok' => false, 'kind' => 'transport', 'message' => '',
                    'error' => 'Could not reach Razorpay from this server ('
                             . ($curlErr !== '' ? $curlErr : 'no response')
                             . '). This is a network or TLS problem, not a credential problem.'];
        }

        if ($http === 401) {
            return ['ok' => false, 'kind' => 'credentials', 'message' => '',
                    'error' => 'Razorpay rejected these credentials (HTTP 401). The secret is '
                             . 'most likely stale — Razorpay shows a secret only once, and '
                             . 'regenerating a key invalidates the previous secret immediately. '
                             . 'Generate a fresh key pair and save both values.'];
        }

        if ($http < 200 || $http >= 300) {
            $desc = '';
            $j = json_decode((string) $body, true);
            if (is_array($j)) $desc = (string) ($j['error']['description'] ?? '');
            return ['ok' => false, 'kind' => 'http', 'message' => '',
                    'error' => 'Razorpay returned HTTP ' . $http
                             . ($desc !== '' ? ': ' . $desc : '') . '.'];
        }

        return ['ok' => true, 'kind' => 'ok', 'error' => '',
                'message' => 'Razorpay accepted these credentials (' . strtoupper($mode) . ' mode).'];
    }

    /**
     * P2 (2026-08-24) — PROTOCOL RELOCATION, NOT A BEHAVIOUR CHANGE.
     *
     * Normalizes Razorpay's callback/webhook field names into the three
     * values Payment_service actually needs. This exact logic was duplicated
     * in TWO places in Fee_management.php — parent_verify_payment() and
     * payment_webhook() — with one subtle divergence: the callback path
     * trimmed each value, the webhook path did not. Trimming here for both
     * callers is a deliberate, narrow strengthening (a value with incidental
     * whitespace that would have been REJECTED by the webhook path before is
     * now accepted the same way the callback path already accepted it) —
     * documented explicitly rather than silently widened, and covered by its
     * own test (`test_parse_callback_trims_whitespace_a_documented_strengthening`).
     *
     * Falls back to the generic `order_id`/`payment_id`/`signature` keys when
     * the razorpay_-prefixed ones are absent — this is what already lets a
     * hypothetical differently-shaped provider's normalized event reach the
     * same call sites unchanged (see the P8 hypothetical-provider proof).
     *
     * ── PAY-OPS-3 · THE TWO SHAPES ARE NOT THE SAME SHAPE ───────────────
     *
     * The line below used to read "or the decoded webhook payload — same shape
     * either way". That is true of the browser return and FALSE of the
     * webhook. Razorpay posts the entity nested:
     *
     *     { "event": "payment.captured",
     *       "payload": { "payment": { "entity": {
     *           "id": "pay_xxx", "order_id": "order_yyy" } } } }
     *
     * There is no top-level `razorpay_order_id`, `order_id` or `payment_id`,
     * so every lookup fell through to '' and the webhook could not identify
     * the payment it was told about. Signature verification would pass and the
     * handler would then do nothing — the failure mode that looks like "the
     * webhook is broken" with nothing in the logs to say why.
     *
     * The sibling `Payment_gateway_cashfree::parse_callback()` already handles
     * its own nesting ("Webhook payloads nest under `data`"); this adapter
     * simply never did. Found while wiring up a real webhook endpoint, before
     * it was pointed at anything.
     *
     * ── WHY `signature` STAYS EMPTY FOR WEBHOOKS ────────────────────────
     * Deliberate. A webhook is signed in the `X-Razorpay-Signature` HEADER
     * against the raw body and the webhook secret, which
     * `webhook_signature_valid()` verifies. The body carries no signature, and
     * inventing one here would let a caller believe the payload was
     * self-verifying.
     *
     * @param array $body decoded JSON body or POST array (browser return), or
     *                     the decoded webhook payload (nested — see above).
     * @return array{order_id: string, payment_id: string, signature: string}
     */
    public function parse_callback(array $body): array
    {
        // Webhook entities, when present. `payment.captured` and
        // `payment.failed` carry payment.entity; `order.paid` carries both,
        // so payment.entity is preferred and order.entity is the fallback for
        // the order id only.
        $payment = is_array($body['payload']['payment']['entity'] ?? null)
            ? $body['payload']['payment']['entity'] : [];
        $order   = is_array($body['payload']['order']['entity'] ?? null)
            ? $body['payload']['order']['entity'] : [];

        return [
            'order_id'   => trim((string) ($body['razorpay_order_id']   ?? $body['gateway_order_id'] ?? $body['order_id']   ?? $payment['order_id'] ?? $order['id'] ?? '')),
            'payment_id' => trim((string) ($body['razorpay_payment_id'] ?? $body['gateway_payment_id'] ?? $body['payment_id'] ?? $payment['id']       ?? '')),
            'signature'  => trim((string) ($body['razorpay_signature']  ?? $body['signature']          ?? '')),
        ];
    }

    /**
     * ── PAY-OPS-4 · WHICH WEBHOOK EVENTS MAY MOVE MONEY ─────────────────
     *
     * `parse_callback()` answers "which payment is this about". It does NOT
     * answer "did this payment succeed" — and until now nothing did. The
     * handler fed EVERY delivery into the same settlement pipeline, so a
     * `payment.failed` notification took the identical path to
     * `payment.captured` and was stopped only by a signature check that a
     * webhook was never going to satisfy anyway. Remove that accidental
     * blocker and a failure notification would have settled fees.
     *
     * Classification lives on the ADAPTER, next to `parse_callback()`, for
     * the same reason that does: the event vocabulary is the provider's, not
     * ZenX's. Cashfree names the field `type` and calls a cancellation
     * `PAYMENT_USER_DROPPED_WEBHOOK`; Razorpay names it `event` and has no
     * cancellation event at all. A single shared table would have to invent a
     * vocabulary neither provider speaks.
     *
     * ── THE REAL RAZORPAY CONTRACT, NOT AN ASSUMED ONE ──────────────────
     * Razorpay's webhook catalogue contains `payment.authorized`,
     * `payment.captured`, `payment.failed`, `payment.pending`, `order.paid`,
     * the `refund.*` family, `payment.dispute.*`, `settlement.*` and the
     * subscription/invoice/virtual-account families.
     *
     * There is **no `payment.cancelled` event**. It is deliberately absent
     * below rather than added "for symmetry" — handling an event a provider
     * never sends is dead code that reads like coverage.
     *
     * `payment.authorized` is the trap in this list. The money is HELD, not
     * captured, and an authorization can still expire or be voided. It is
     * classified IGNORE, not SUCCESS: settling on it would issue a receipt
     * for funds the school may never receive. The subsequent
     * `payment.captured` is the event that reconciles.
     *
     * Anything unrecognised — a new event Razorpay adds, a family ZenX does
     * not consume — classifies IGNORE, which is the fail-safe answer: it
     * produces no financial record at all.
     *
     * @param  array $body decoded webhook body
     * @return array{class: string, event: string, reason: string}
     *         class ∈ SUCCESS | FAILURE | IGNORE (Payment_gateway_selector consts)
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
        $rawEvent = $body['event'] ?? '';
        $event    = (is_scalar($rawEvent) && !is_bool($rawEvent))
            ? strtolower(trim((string) $rawEvent))
            : '';

        // No `event` key at all (or a non-scalar one): this is a browser
        // return or a malformed delivery, not a webhook we can act on.
        // The frontend path authenticates it with the order|payment HMAC and
        // never consults this method, so reaching here without one means a
        // malformed delivery. IGNORE — never settle on an unidentified shape.
        if ($event === '') {
            return [
                'class'  => 'ignore',
                'event'  => '',
                'reason' => 'no `event` field — not a recognisable Razorpay webhook envelope',
            ];
        }

        if ($event === 'payment.captured' || $event === 'order.paid') {
            return [
                'class'  => 'success',
                'event'  => $event,
                'reason' => 'provider reports the payment as captured',
            ];
        }

        if ($event === 'payment.failed') {
            return [
                'class'  => 'failure',
                'event'  => $event,
                'reason' => 'provider reports the payment attempt failed',
            ];
        }

        return [
            'class'  => 'ignore',
            'event'  => $event,
            'reason' => $event === 'payment.authorized' || $event === 'payment.pending'
                ? 'authorized/pending is not captured — the funds are held, not settled'
                : 'event is not part of the fee-reconciliation contract',
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════════

    private function _assert_credentials(): void
    {
        if ($this->keyId === '' || $this->keySecret === '') {
            throw new \RuntimeException(
                'Razorpay credentials are not configured. Set api_key and api_secret in Gateway Config.'
            );
        }
    }

    private function _request(string $method, string $path, array $body = null): array
    {
        $url = $this->apiBase . $path;
        $ch  = curl_init($url);

        $headers = ['Accept: application/json'];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $this->keyId . ':' . $this->keySecret,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 25,
        ];

        if ($body !== null) {
            $json = json_encode($body);
            $opts[CURLOPT_POSTFIELDS] = $json;
            $headers[] = 'Content-Type: application/json';
        }

        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $errNo = curl_errno($ch);
        $err   = curl_error($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo !== 0) {
            throw new \RuntimeException("Razorpay network error ({$errNo}): {$err}");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Razorpay returned non-JSON response (HTTP {$code}).");
        }

        if ($code >= 400) {
            $msg = $decoded['error']['description'] ?? "HTTP {$code}";
            throw new \RuntimeException("Razorpay API error: {$msg}");
        }

        return $decoded;
    }
}
