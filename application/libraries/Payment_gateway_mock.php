<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Mock Payment Gateway Adapter
 *
 * Simulates a real payment gateway (Razorpay/Paytm/Stripe) for development
 * and demo purposes. Returns deterministic results that exercise the full
 * payment flow: order creation, checkout simulation, and signature verification.
 *
 * To swap for a real gateway, create Payment_gateway_razorpay.php with the
 * same public interface and change the adapter loaded in Payment_service.php.
 *
 * Success rate: 80% by default (configurable).
 */
class Payment_gateway_mock
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
}
