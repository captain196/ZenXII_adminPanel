<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Razorpay Payment Gateway Adapter
 *
 * Implements the same public interface as Payment_gateway_mock so that
 * Payment_service can swap between them transparently.
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
class Payment_gateway_razorpay
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

    /** @return string */
    public function get_name(): string
    {
        return 'razorpay';
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
