<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Payment Service — Gateway-agnostic payment orchestrator.
 *
 * Coordinates between the gateway adapter and the fee collection system.
 * This service NEVER trusts the frontend — it verifies everything server-side.
 *
 * Adapter pattern: swap Payment_gateway_mock with Payment_gateway_razorpay
 * by changing the init() call. The service interface stays identical.
 *
 * Firebase paths used:
 *   {feesBase}/Online_Orders/{order_id}     — order lifecycle
 *   {feesBase}/Online_Payments/{payment_id} — payment records
 */
class Payment_service
{
    /** @var CI_Controller */
    private $CI;

    /** @var object Firebase library */
    private $firebase;

    /** @var string Base path for fees data */
    private $feesBase;

    /** @var object Gateway adapter (mock, razorpay, etc.) */
    private $gateway;

    /** @var string Admin performing the operation */
    private $adminId;

    /**
     * Initialize the service.
     *
     * @param object $firebase   Firebase library instance
     * @param string $feesBase   e.g. "Schools/Demo/2025-26/Accounts/Fees"
     * @param object $gateway    Gateway adapter instance
     * @param string $adminId    Current admin ID
     */
    public function init($firebase, string $feesBase, $gateway, string $adminId = ''): void
    {
        $this->CI       = &get_instance();
        $this->firebase = $firebase;
        $this->feesBase = $feesBase;
        $this->gateway  = $gateway;
        $this->adminId  = $adminId;
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

        // Check for duplicate pending orders for same student + months
        $existing = $this->_find_pending_order($studentId, $feeMonths);
        if ($existing) {
            return [
                'order_id'          => $existing['gateway_order_id'],
                'payment_record_id' => $existing['_id'],
                'amount'            => $existing['amount'],
                'gateway'           => $existing['gateway'] ?? 'mock',
                'existing'          => true,
            ];
        }

        // Create order via gateway adapter
        $receiptRef  = 'FEE_' . $studentId . '_' . date('YmdHis');
        $gwOrder     = $this->gateway->create_order($amount, $receiptRef, [
            'student_id' => $studentId,
        ]);

        $orderId    = $gwOrder['order_id'];
        $gatewayName = method_exists($this->gateway, 'get_name') ? $this->gateway->get_name() : 'unknown';
        $now         = date('c');

        // Store order in Firebase
        $recordId = 'OP_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
        $record = [
            'student_id'       => $studentId,
            'student_name'     => $studentName,
            'class'            => $class,
            'section'          => $section,
            'amount'           => $amount,
            'fee_months'       => $feeMonths,
            'gateway'          => $gatewayName,
            'gateway_order_id' => $orderId,
            'gateway_payment_id' => '',
            'status'           => 'created',
            'created_at'       => $now,
            'created_by'       => $this->adminId,
            'paid_at'          => '',
            'verified_at'      => '',
        ];

        $this->firebase->set("{$this->feesBase}/Online_Orders/{$recordId}", $record);

        // Index for O(1) lookup by gateway order ID
        $this->firebase->set("{$this->feesBase}/Online_Orders_Index/{$orderId}", $recordId);

        log_message('info', "PaymentService: order created id={$recordId} gw_order={$orderId} student={$studentId} amount={$amount}");

        return [
            'order_id'          => $orderId,
            'payment_record_id' => $recordId,
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
        // Look up the order
        $recordId = $this->firebase->get("{$this->feesBase}/Online_Orders_Index/{$orderId}");
        if (empty($recordId)) {
            throw new \RuntimeException('Order not found.');
        }
        $order = $this->firebase->get("{$this->feesBase}/Online_Orders/{$recordId}");
        if (!is_array($order)) {
            throw new \RuntimeException('Order record not found.');
        }
        if (($order['status'] ?? '') === 'paid') {
            throw new \RuntimeException('Order already paid.');
        }

        // Call gateway simulate
        $result = $this->gateway->simulate_payment($orderId, (float) ($order['amount'] ?? 0));

        // Update order with simulation result
        $this->firebase->update("{$this->feesBase}/Online_Orders/{$recordId}", [
            'last_attempt_at'     => date('c'),
            'last_attempt_status' => $result['status'],
            'gateway_payment_id'  => $result['payment_id'] ?? '',
        ]);

        $result['record_id'] = $recordId;
        return $result;
    }

    // ====================================================================
    //  PAYMENT VERIFICATION
    // ====================================================================

    /**
     * Verify a payment callback and mark as paid if valid.
     *
     * Validates:
     *   1. Order exists in Firebase
     *   2. Not already paid (idempotent)
     *   3. Gateway signature is valid
     *   4. Amount matches
     *
     * @param  string $orderId    Gateway order ID
     * @param  string $paymentId  Gateway payment ID
     * @param  string $signature  Gateway signature
     * @return array  {verified, record_id, order, payment_data}
     */
    public function verify_payment(string $orderId, string $paymentId, string $signature): array
    {
        // 1. Look up order
        $recordId = $this->firebase->get("{$this->feesBase}/Online_Orders_Index/{$orderId}");
        if (empty($recordId)) {
            return ['verified' => false, 'error' => 'Order not found.'];
        }
        $order = $this->firebase->get("{$this->feesBase}/Online_Orders/{$recordId}");
        if (!is_array($order)) {
            return ['verified' => false, 'error' => 'Order record corrupted.'];
        }

        // 2. Idempotent: already paid or in processing
        $orderStatus = $order['status'] ?? '';
        if ($orderStatus === 'paid') {
            return ['verified' => true, 'record_id' => $recordId, 'order' => $order, 'already_paid' => true];
        }
        if ($orderStatus === 'processing') {
            return ['verified' => false, 'error' => 'This payment is currently being processed. Please wait.'];
        }

        // 3. Verify signature via gateway adapter
        $sigValid = $this->gateway->verify_signature($orderId, $paymentId, $signature);
        if (!$sigValid) {
            // Mark as failed
            $this->firebase->update("{$this->feesBase}/Online_Orders/{$recordId}", [
                'status'             => 'failed',
                'failed_at'          => date('c'),
                'failure_reason'     => 'Signature verification failed',
                'gateway_payment_id' => $paymentId,
            ]);
            log_message('error', "PaymentService: signature FAILED order={$orderId} payment={$paymentId}");
            return ['verified' => false, 'error' => 'Payment signature verification failed.'];
        }

        // 4. Mark as "verified" (signature OK) — NOT "paid" yet.
        //    The controller will mark "paid" only after fee writes succeed.
        //    This prevents orphan "paid" orders with no receipt.
        $now = date('c');
        $this->firebase->update("{$this->feesBase}/Online_Orders/{$recordId}", [
            'status'             => 'verified',
            'gateway_payment_id' => $paymentId,
            'verified_at'        => $now,
            'verified_by'        => $this->adminId,
            'signature'          => $signature,
        ]);

        $studentId = $order['student_id'] ?? '';
        log_message('info', "PaymentService: signature VERIFIED order={$orderId} payment={$paymentId} student={$studentId}");

        return [
            'verified'     => true,
            'record_id'    => $recordId,
            'order'        => $order,
            'already_paid' => false,
        ];
    }

    // ====================================================================
    //  PRIVATE HELPERS
    // ====================================================================

    /**
     * Find an existing pending (unpaid) order for the same student + months.
     */
    private function _find_pending_order(string $studentId, array $months): ?array
    {
        $all = $this->firebase->get("{$this->feesBase}/Online_Orders");
        if (!is_array($all)) return null;

        $sortedMonths = $months;
        sort($sortedMonths);
        $monthKey = implode(',', $sortedMonths);

        foreach ($all as $id => $order) {
            if (!is_array($order)) continue;
            if (($order['student_id'] ?? '') !== $studentId) continue;
            if (($order['status'] ?? '') !== 'created') continue;

            // Check if same months
            $orderMonths = $order['fee_months'] ?? [];
            if (is_array($orderMonths)) {
                sort($orderMonths);
                if (implode(',', $orderMonths) === $monthKey) {
                    $order['_id'] = $id;
                    return $order;
                }
            }
        }

        return null;
    }
}
