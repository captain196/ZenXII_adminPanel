<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/PaymentProviderInterface.php';

/**
 * Mock Payment Gateway Adapter
 *
 * Simulates a real payment gateway for development and demo purposes.
 * Returns deterministic results that exercise the full payment flow: order
 * creation, checkout simulation, and signature verification.
 *
 * (P1 2026-08-24: the docblock previously named "Razorpay/Paytm/Stripe" here
 * — only Razorpay has ever had an adapter; see PA-UI-1 in the provider-
 * agnostic architecture audit.)
 *
 * To swap for a real gateway, create a class implementing
 * PaymentProviderInterface and change the adapter loaded in
 * Payment_service.php.
 *
 * Success rate: 80% by default (configurable).
 */
class Payment_gateway_mock implements PaymentProviderInterface
{
    /** @var float Success probability (0.0 to 1.0) */
    private $successRate = 0.80;

    /**
     * Create a payment order on the "gateway".
     *
     * @param  float  $amount      Amount in INR
     * @param  string $receiptRef  Internal receipt reference
     * @param  array  $meta        Additional metadata (student_id, etc.)
     * @return array  {order_id, amount, currency, status, created_at}
     */
    public function create_order(float $amount, string $receiptRef = '', array $meta = []): array
    {
        $orderId = 'ORD_' . strtoupper(bin2hex(random_bytes(6)));

        return [
            'order_id'   => $orderId,
            'amount'     => round($amount, 2),
            'amount_paise' => (int) ($amount * 100),
            'currency'   => 'INR',
            'receipt'    => $receiptRef,
            'status'     => 'created',
            'gateway'    => 'mock',
            'created_at' => date('c'),
        ];
    }

    /**
     * Simulate a payment attempt.
     * Returns success ~80% of the time, failure ~20%.
     *
     * @param  string $orderId  The order to "pay"
     * @param  float  $amount   Amount expected
     * @return array  {payment_id, order_id, status, amount, signature}
     */
    public function simulate_payment(string $orderId, float $amount): array
    {
        $paymentId = 'pay_' . strtoupper(bin2hex(random_bytes(8)));
        $success   = (mt_rand(1, 100) <= ($this->successRate * 100));

        // Generate a deterministic signature (HMAC of order+payment with a mock secret)
        $mockSecret = 'mock_secret_key_for_dev';
        $signature  = hash_hmac('sha256', $orderId . '|' . $paymentId, $mockSecret);

        return [
            'payment_id' => $paymentId,
            'order_id'   => $orderId,
            'status'     => $success ? 'success' : 'failed',
            'amount'     => round($amount, 2),
            'currency'   => 'INR',
            'method'     => 'mock_upi',
            'signature'  => $success ? $signature : '',
            'error_code' => $success ? '' : 'PAYMENT_DECLINED',
            'error_msg'  => $success ? '' : 'Simulated payment failure (mock gateway)',
            'gateway'    => 'mock',
            'timestamp'  => date('c'),
        ];
    }

    /**
     * Verify a payment signature.
     *
     * @param  string $orderId    Gateway order ID
     * @param  string $paymentId  Gateway payment ID
     * @param  string $signature  Signature from callback
     * @return bool
     */
    public function verify_signature(string $orderId, string $paymentId, string $signature): bool
    {
        if ($signature === '') return false;
        $mockSecret = 'mock_secret_key_for_dev';
        $expected   = hash_hmac('sha256', $orderId . '|' . $paymentId, $mockSecret);
        return hash_equals($expected, $signature);
    }

    /**
     * Fetch payment details (mock).
     *
     * BUG-050 / PS-3: interface parity with Payment_gateway_razorpay::fetch_payment.
     * The mock cannot retrieve a real captured amount (no gateway round-trip), so
     * returns a sentinel `mock_skip_amount_check => true` that signals
     * Payment_service::verify_payment to skip amount enforcement for this gateway.
     * Mock-mode test flows therefore behave identically to pre-PS-3 semantics
     * (signature-only verification) while real Razorpay flows engage the guard.
     *
     * @return array {payment_id, amount_paise, amount, status, currency,
     *                gateway, mock_skip_amount_check}
     */
    public function fetch_payment(string $paymentId): array
    {
        return [
            'payment_id'              => $paymentId,
            'amount_paise'            => 0,
            'amount'                  => 0.0,
            'status'                  => 'captured',
            'currency'                => 'INR',
            'gateway'                 => 'mock',
            'mock_skip_amount_check'  => true,
            'fetched_at'              => date('c'),
        ];
    }

    /**
     * Set success rate for testing.
     *
     * @param float $rate 0.0 to 1.0
     */
    public function set_success_rate(float $rate): void
    {
        $this->successRate = max(0, min(1, $rate));
    }

    /** @return string Gateway adapter name */
    public function get_name(): string
    {
        return 'mock';
    }

    /**
     * P1: the mock's whole purpose is simulation — `simulatable` is true here
     * and here alone among the three adapters. `refunds` stays false: the
     * mock has no refund method either, and a simulator claiming a capability
     * no real adapter has would make it a worse test double, not a better one.
     */
    public function capabilities(): array
    {
        return [
            'checkout_type' => 'native_sdk',
            'cards'         => true,
            'upi'           => true,
            'net_banking'   => true,
            'wallets'       => true,
            'webhooks'      => false,
            'refunds'       => false,
            'simulatable'   => true,
            // The mock returns mock_skip_amount_check and no real captured
            // amount. Saying so explicitly lets the core distinguish "nothing to
            // compare" from "comparison skipped".
            'reports_captured_amount' => false,
        ];
    }

    /**
     * ── PAY-OPS-4 · EVENT CLASSIFICATION FOR THE SIMULATOR ──────────────
     *
     * Mirrors `Payment_gateway_razorpay::classify_event()`'s vocabulary
     * deliberately: the mock's job is to let the webhook pipeline be exercised
     * end-to-end without a real provider, and it cannot do that if a simulated
     * `payment.failed` classifies differently from a real one.
     *
     * It does NOT mirror Razorpay's `payment.authorized` nuance, because the
     * simulator never emits one. Nothing here is a second authority on what a
     * real provider sends — `Payment_gateway_selector::select()` already
     * confines this adapter to SIMULATOR_ENVIRONMENTS, so a mock event can
     * never classify a production payment.
     */
    /**
     * ── PAY-OPS-4 · THE SIMULATOR COULD CLASSIFY BUT NOT PARSE ──────────
     *
     * This adapter had no `parse_callback()` at all, so
     * `Payment_gateway_selector::parse_provider_callback()` fell through to
     * its generic TOP-LEVEL fallback for mock-configured tenants. A browser
     * return parsed fine (its ids ARE top-level); a webhook did not, because
     * the entity is nested — so every simulated webhook was rejected with
     * "Missing order_id or payment_id" and the pipeline could not be exercised
     * without a real provider at all.
     *
     * That is the same defect PAY-OPS-3 fixed in the Razorpay adapter, still
     * present here by omission rather than by a wrong assumption. Found while
     * proving the webhook works through a SECOND provider — which is the point
     * of having a second one.
     *
     * The shape mirrors Razorpay's deliberately: a simulator that accepts a
     * shape the real adapter rejects, or rejects one it accepts, is a worse
     * test double rather than a more convenient one.
     */
    public function parse_callback(array $body): array
    {
        $payment = is_array($body['payload']['payment']['entity'] ?? null)
            ? $body['payload']['payment']['entity'] : [];
        $order   = is_array($body['payload']['order']['entity'] ?? null)
            ? $body['payload']['order']['entity'] : [];

        $pick = function (array $keys) use ($body, $payment, $order): string {
            foreach ($keys as [$src, $k]) {
                $bag = $src === 'body' ? $body : ($src === 'payment' ? $payment : $order);
                $v = $bag[$k] ?? null;
                if ($v === null || (!is_scalar($v) || is_bool($v))) continue;
                $v = trim((string) $v);
                if ($v !== '') return $v;
            }
            return '';
        };

        return [
            'order_id'   => $pick([['body', 'razorpay_order_id'], ['body', 'gateway_order_id'],
                                   ['body', 'order_id'], ['payment', 'order_id'], ['order', 'id']]),
            'payment_id' => $pick([['body', 'razorpay_payment_id'], ['body', 'gateway_payment_id'],
                                   ['body', 'payment_id'], ['payment', 'id']]),
            // Like the real adapters: a webhook body carries no signature, and
            // inventing one would let a caller think the payload self-verifies.
            'signature'  => $pick([['body', 'razorpay_signature'], ['body', 'signature']]),
        ];
    }

    public function classify_event(array $body): array
    {
        // Scalar-guarded for the same reason the real adapters are - a
        // simulator that tolerates a shape the real one rejects is a worse
        // test double, not a more forgiving one.
        $rawEvent = $body['event'] ?? '';
        $event    = (is_scalar($rawEvent) && !is_bool($rawEvent))
            ? strtolower(trim((string) $rawEvent))
            : '';

        if ($event === 'payment.captured' || $event === 'order.paid') {
            return ['class' => 'success', 'event' => $event, 'reason' => 'simulated capture'];
        }
        if ($event === 'payment.failed') {
            return ['class' => 'failure', 'event' => $event, 'reason' => 'simulated failure'];
        }
        return [
            'class'  => 'ignore',
            'event'  => $event,
            'reason' => $event === '' ? 'no `event` field' : 'event is not part of the fee-reconciliation contract',
        ];
    }
}
