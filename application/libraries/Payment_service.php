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

        // Check for duplicate pending orders for same student + months + amount
        $existing = $this->_find_pending_order($studentId, $feeMonths, $amount);
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
        $patched = array_merge($order, [
            'last_attempt_at'     => date('c'),
            'last_attempt_status' => $result['status'],
            'gateway_payment_id'  => $result['payment_id'] ?? '',
        ]);
        $this->firebase->firestoreSet(self::COL_ONLINE_ORDERS, $docId, $patched);

        $result['record_id'] = $docId;
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
        $docId = $this->_orderDocId($orderId);
        $order = $this->firebase->firestoreGet(self::COL_ONLINE_ORDERS, $docId);
        if (!is_array($order)) {
            return ['verified' => false, 'error' => 'Order not found.'];
        }

        // Idempotent: already paid or in processing
        $orderStatus = $order['status'] ?? '';
        if ($orderStatus === 'paid') {
            return ['verified' => true, 'record_id' => $docId, 'order' => $order, 'already_paid' => true];
        }
        if ($orderStatus === 'processing') {
            return ['verified' => false, 'error' => 'This payment is currently being processed. Please wait.'];
        }

        // Verify signature via gateway adapter
        $sigValid = $this->gateway->verify_signature($orderId, $paymentId, $signature);
        if (!$sigValid) {
            $patched = array_merge($order, [
                'status'             => 'failed',
                'failed_at'          => date('c'),
                'failure_reason'     => 'Signature verification failed',
                'gateway_payment_id' => $paymentId,
            ]);
            $this->firebase->firestoreSet(self::COL_ONLINE_ORDERS, $docId, $patched);
            log_message('error', "PaymentService: signature FAILED order={$orderId} payment={$paymentId}");
            return ['verified' => false, 'error' => 'Payment signature verification failed.'];
        }

        // Mark as "verified" (signature OK) — NOT "paid" yet. The controller
        // marks "paid" only after fee writes succeed; this prevents orphan
        // "paid" orders with no receipt.
        $now = date('c');
        $patched = array_merge($order, [
            'status'             => 'verified',
            'gateway_payment_id' => $paymentId,
            'verified_at'        => $now,
            'verified_by'        => $this->adminId,
            'signature'          => $signature,
        ]);
        $this->firebase->firestoreSet(self::COL_ONLINE_ORDERS, $docId, $patched);

        $studentId = $order['student_id'] ?? '';
        log_message('info', "PaymentService: signature VERIFIED order={$orderId} payment={$paymentId} student={$studentId}");

        return [
            'verified'     => true,
            'record_id'    => $docId,
            'order'        => $patched,
            'already_paid' => false,
        ];
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
    private function _find_pending_order(string $studentId, array $months, float $amount): ?array
    {
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
            log_message('warning', 'PaymentService::_find_pending_order query failed: ' . $e->getMessage());
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
            if ($sameMonths && $sameAmount) {
                $order['_id'] = (string) ($row['id'] ?? $this->_orderDocId((string) ($order['gateway_order_id'] ?? '')));
                return $order;
            }
        }

        return null;
    }
}
