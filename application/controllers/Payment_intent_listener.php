<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Payment Intent Listener Controller
 *
 * Processes mobile payment intents written to Firebase by the SchoolSync parent app.
 * Runs as a cron endpoint or can be triggered manually by admin.
 *
 * Payment Flow:
 *   1. Parent app writes to Fees/Payment_Intents/{intentId} with status:"requested"
 *   2. This controller polls for "requested" intents, validates, creates gateway order
 *   3. Writes gateway_order_id + status:"order_created" back to Firebase
 *   4. Parent app reads order_id, opens Razorpay checkout
 *   5. Razorpay webhook hits Fee_management::payment_webhook() which calls complete_intent() here
 *   6. This controller marks intent as "completed"
 *
 * Extends MY_Controller which provides:
 *   $this->firebase, $this->school_name, $this->session_year,
 *   $this->admin_id, $this->admin_name, json_success(), json_error()
 */
class Payment_intent_listener extends MY_Controller
{
    private const INTENT_PATH = 'Fees/Payment_Intents';

    /** @var string Full Firebase path for intents */
    private $intentBase;

    /** @var string Full Firebase path for fees */
    private $feesBase;

    /** @var string Session root path */
    private $sessionRoot;

    public function __construct()
    {
        // Cron/system endpoints: allow both CLI and authenticated admin access.
        // For CLI invocation (cron), MY_Controller session check is bypassed
        // if the request comes from the command line.
        $isCli = is_cli();
        $isCronEndpoint = (
            isset($_SERVER['REQUEST_URI']) &&
            (strpos($_SERVER['REQUEST_URI'], 'process_pending') !== false ||
             strpos($_SERVER['REQUEST_URI'], 'expire_stale') !== false)
        );

        if ($isCli) {
            CI_Controller::__construct();
            $this->load->library('firebase');
            // CLI mode: school_name and session_year must be passed as arguments
            // or resolved from config. For now, rely on defaults or env.
            $this->school_name  = getenv('SCHOOL_NAME') ?: ($this->config->item('default_school') ?? '');
            $this->session_year = getenv('SESSION_YEAR') ?: ($this->config->item('default_session') ?? '');
            $this->admin_id     = 'SYSTEM_CRON';
            $this->admin_name   = 'System Cron';

            if (empty($this->school_name) || empty($this->session_year)) {
                log_message('error', 'Payment_intent_listener: Missing SCHOOL_NAME or SESSION_YEAR env vars for CLI mode');
                if (is_cli()) {
                    echo "ERROR: Set SCHOOL_NAME and SESSION_YEAR environment variables\n";
                    exit(1);
                }
            }
        } else {
            parent::__construct();
            // Don't require a specific permission — admin-level access is sufficient.
            // Role check is applied per-endpoint where needed.
        }

        $sn = $this->school_name;
        $sy = $this->session_year;
        $this->intentBase  = "Schools/{$sn}/{$sy}/" . self::INTENT_PATH;
        $this->feesBase    = "Schools/{$sn}/{$sy}/Accounts/Fees";
        $this->sessionRoot = "Schools/{$sn}/{$sy}";
    }

    // ══════════════════════════════════════════════════════════════════════
    //  1. PROCESS PENDING — Main cron entry point
    // ══════════════════════════════════════════════════════════════════════

    /**
     * GET or CLI — Poll for "requested" intents and process them.
     *
     * Usage:
     *   GET  /payment_intent_listener/process_pending
     *   CLI  php index.php payment_intent_listener process_pending
     */
    public function process_pending()
    {
        $startTime = microtime(true);
        log_message('info', 'PaymentIntentListener: process_pending started');

        $processed = 0;
        $failed    = 0;
        $errors    = [];

        try {
            // Read ALL intents
            $allIntents = $this->firebase->get($this->intentBase);

            if (!is_array($allIntents) || empty($allIntents)) {
                log_message('info', 'PaymentIntentListener: No intents found.');
                $this->_json_out([
                    'status'    => 'success',
                    'message'   => 'No intents found.',
                    'processed' => 0,
                    'failed'    => 0,
                    'errors'    => [],
                    'duration_ms' => round((microtime(true) - $startTime) * 1000),
                ]);
                return;
            }

            // Filter for "requested" status and process each
            foreach ($allIntents as $intentId => $intentData) {
                if (!is_array($intentData)) continue;
                if (($intentData['status'] ?? '') !== 'requested') continue;

                try {
                    $result = $this->_process_single_intent($intentId, $intentData);
                    if ($result['success']) {
                        $processed++;
                    } else {
                        $failed++;
                        $errors[] = [
                            'intent_id' => $intentId,
                            'error'     => $result['error'] ?? 'Unknown error',
                        ];
                    }
                } catch (\Exception $e) {
                    $failed++;
                    $errors[] = [
                        'intent_id' => $intentId,
                        'error'     => $e->getMessage(),
                    ];
                    log_message('error', "PaymentIntentListener: Exception processing intent={$intentId}: " . $e->getMessage());

                    // Mark as failed so we don't retry indefinitely
                    $this->_safe_update_intent($intentId, [
                        'status'        => 'failed',
                        'error_message' => 'Processing exception: ' . $e->getMessage(),
                        'failed_at'     => date('c'),
                    ]);
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'PaymentIntentListener: Fatal error in process_pending: ' . $e->getMessage());
            $errors[] = ['intent_id' => 'GLOBAL', 'error' => $e->getMessage()];
        }

        $durationMs = round((microtime(true) - $startTime) * 1000);
        log_message('info', "PaymentIntentListener: process_pending completed. processed={$processed} failed={$failed} duration={$durationMs}ms");

        $this->_json_out([
            'status'      => 'success',
            'processed'   => $processed,
            'failed'      => $failed,
            'errors'      => $errors,
            'duration_ms' => $durationMs,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  2. PROCESS SINGLE INTENT (private)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Validate, create gateway order, and update intent.
     *
     * @param  string $intentId   Firebase key
     * @param  array  $data       Intent data from Firebase
     * @return array  {success: bool, error?: string, order_id?: string}
     */
    private function _process_single_intent(string $intentId, array $data): array
    {
        log_message('info', "PaymentIntentListener: Processing intent={$intentId}");

        // ── A. Validate intent data ──
        $validation = $this->_validate_intent($data);
        if (!$validation['valid']) {
            $this->_safe_update_intent($intentId, [
                'status'        => 'failed',
                'error_message' => $validation['error'],
                'failed_at'     => date('c'),
            ]);
            return ['success' => false, 'error' => $validation['error']];
        }

        $studentId = trim($data['student_id']);
        $amount    = round(floatval($data['amount']), 2);
        $feeMonths = $data['fee_months'];
        $class     = trim($data['class'] ?? '');
        $section   = trim($data['section'] ?? '');

        // ── B. Idempotency check — prevent double-processing ──
        $idempotencyPath = "{$this->feesBase}/Idempotency/{$intentId}";
        $existingIdemp   = $this->firebase->get($idempotencyPath);
        if (is_array($existingIdemp) && !empty($existingIdemp['processed_at'])) {
            log_message('info', "PaymentIntentListener: Intent={$intentId} already processed (idempotency). Skipping.");
            return [
                'success'  => true,
                'order_id' => $existingIdemp['order_id'] ?? '',
                'skipped'  => true,
            ];
        }

        // ── C. Check for duplicate intent (same student + same months, active) ──
        $duplicateId = $this->_check_duplicate($studentId, $feeMonths);
        if ($duplicateId !== null && $duplicateId !== $intentId) {
            $msg = "Duplicate intent exists: {$duplicateId} for same student + months.";
            log_message('info', "PaymentIntentListener: {$msg} intent={$intentId}");
            $this->_safe_update_intent($intentId, [
                'status'              => 'failed',
                'error_message'       => $msg,
                'duplicate_intent_id' => $duplicateId,
                'failed_at'           => date('c'),
            ]);
            $this->firebase->delete("{$this->feesBase}/Idempotency/{$intentId}");
            return ['success' => false, 'error' => $msg];
        }

        // ── D. Mark as "processing" to prevent concurrent processing ──
        $this->_safe_update_intent($intentId, [
            'status'        => 'processing',
            'processing_at' => date('c'),
            'processed_by'  => $this->admin_id ?? 'SYSTEM',
        ]);

        // Write idempotency lock (before gateway call)
        $this->firebase->set($idempotencyPath, [
            'intent_id'  => $intentId,
            'student_id' => $studentId,
            'locked_at'  => date('c'),
        ]);

        // ── E. Read student info from Firebase for name enrichment ──
        $studentName = trim($data['student_name'] ?? '');
        if ($studentName === '' && $class !== '' && $section !== '') {
            $studentInfo = $this->_read_student_info($studentId, $class, $section);
            if ($studentInfo !== null) {
                $studentName = $studentInfo['name'] ?? '';
            }
        }
        // Fallback: use student_id as name if still empty
        if ($studentName === '') {
            $studentName = $studentId;
        }

        // ── F. Initialize Payment_service + Payment_gateway_mock ──
        try {
            $this->load->library('Payment_gateway_mock');
            $this->load->library('Payment_service');

            $gateway = $this->payment_gateway_mock;
            $paymentService = $this->payment_service;
            $paymentService->init(
                $this->firebase,
                $this->feesBase,
                $gateway,
                $this->admin_id ?? 'SYSTEM'
            );

            // ── G. Create payment order ──
            $orderResult = $paymentService->create_order([
                'student_id'   => $studentId,
                'student_name' => $studentName,
                'class'        => $class,
                'section'      => $section,
                'amount'       => $amount,
                'fee_months'   => $feeMonths,
            ]);

            $gatewayOrderId = $orderResult['order_id'] ?? '';

            if (empty($gatewayOrderId)) {
                throw new \RuntimeException('Payment service returned empty order_id.');
            }

            // ── H. Success: update intent with order details ──
            $now = date('c');
            $this->_safe_update_intent($intentId, [
                'status'            => 'order_created',
                'gateway_order_id'  => $gatewayOrderId,
                'payment_record_id' => $orderResult['payment_record_id'] ?? '',
                'gateway'           => $orderResult['gateway'] ?? 'mock',
                'order_amount'      => $orderResult['amount'] ?? $amount,
                'order_created_at'  => $now,
                'student_name'      => $studentName,
            ]);

            // Update idempotency record
            $this->firebase->update($idempotencyPath, [
                'processed_at' => $now,
                'order_id'     => $gatewayOrderId,
                'status'       => 'order_created',
            ]);

            log_message('info', "PaymentIntentListener: Order created for intent={$intentId} order={$gatewayOrderId} student={$studentId} amount={$amount}");

            return [
                'success'  => true,
                'order_id' => $gatewayOrderId,
            ];

        } catch (\Exception $e) {
            // ── I. Failure: mark intent as failed ──
            $errorMsg = $e->getMessage();
            log_message('error', "PaymentIntentListener: Order creation failed for intent={$intentId}: {$errorMsg}");

            $this->_safe_update_intent($intentId, [
                'status'        => 'failed',
                'error_message' => $errorMsg,
                'failed_at'     => date('c'),
            ]);

            // Clean up idempotency lock on failure so it can be retried
            $this->firebase->delete($idempotencyPath);

            return ['success' => false, 'error' => $errorMsg];
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    //  3. PROCESS SINGLE — Admin manual endpoint
    // ══════════════════════════════════════════════════════════════════════

    /**
     * GET /payment_intent_listener/process_single/{intentId}
     *
     * Admin endpoint to manually process a specific intent.
     */
    public function process_single(string $intentId = '')
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Accountant'], 'process_payment_intent');

        $intentId = trim($intentId);
        if ($intentId === '') {
            $this->json_error('Intent ID is required.', 400);
            return;
        }

        // Sanitize: reject path-injection characters
        if (preg_match('/[.\/\#\$\[\]]/', $intentId)) {
            $this->json_error('Invalid intent ID format.', 400);
            return;
        }

        log_message('info', "PaymentIntentListener: Manual process requested for intent={$intentId} by admin={$this->admin_id}");

        // Read the intent
        $intentData = $this->firebase->get("{$this->intentBase}/{$intentId}");
        if (!is_array($intentData) || empty($intentData)) {
            $this->json_error('Intent not found.', 404);
            return;
        }

        // Must be in "requested" status
        $currentStatus = $intentData['status'] ?? '';
        if ($currentStatus !== 'requested') {
            $this->json_error(
                "Intent cannot be processed: current status is \"{$currentStatus}\". Only \"requested\" intents can be processed.",
                409
            );
            return;
        }

        // Process it
        $result = $this->_process_single_intent($intentId, $intentData);

        if ($result['success']) {
            $this->json_success([
                'message'   => !empty($result['skipped'])
                    ? 'Intent was already processed (idempotent skip).'
                    : 'Intent processed successfully.',
                'intent_id' => $intentId,
                'order_id'  => $result['order_id'] ?? '',
            ]);
        } else {
            $this->json_error($result['error'] ?? 'Processing failed.', 422);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    //  4. COMPLETE INTENT — Called by Fee_management::payment_webhook()
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Mark an intent as completed after successful payment.
     *
     * Called internally by Fee_management::payment_webhook(), NOT an HTTP endpoint.
     *
     * @param  string $intentId    The intent ID
     * @param  string $receiptNo   Generated receipt number
     * @param  array  $paymentData Additional payment details (payment_id, signature, etc.)
     * @return bool
     */
    public function complete_intent(string $intentId, string $receiptNo = '', array $paymentData = []): bool
    {
        $intentId = trim($intentId);
        if ($intentId === '') {
            log_message('error', 'PaymentIntentListener: complete_intent called with empty intentId.');
            return false;
        }

        log_message('info', "PaymentIntentListener: Completing intent={$intentId} receipt={$receiptNo}");

        try {
            // Verify the intent exists
            $intentData = $this->firebase->get("{$this->intentBase}/{$intentId}");
            if (!is_array($intentData) || empty($intentData)) {
                log_message('error', "PaymentIntentListener: complete_intent — intent={$intentId} not found.");
                return false;
            }

            $currentStatus = $intentData['status'] ?? '';
            // Allow completion from order_created, paying, or processing states
            $completableStatuses = ['order_created', 'paying', 'processing'];
            if (!in_array($currentStatus, $completableStatuses, true)) {
                // If already completed, that's fine (idempotent)
                if ($currentStatus === 'completed') {
                    log_message('info', "PaymentIntentListener: intent={$intentId} already completed. Idempotent OK.");
                    return true;
                }
                log_message('error', "PaymentIntentListener: complete_intent — intent={$intentId} has status \"{$currentStatus}\", cannot complete.");
                return false;
            }

            $now = date('c');
            $updateData = [
                'status'       => 'completed',
                'completed_at' => $now,
                'receipt_no'   => $receiptNo,
            ];

            // Merge payment data (payment_id, signature, gateway response, etc.)
            if (!empty($paymentData)) {
                $updateData['payment_data'] = $paymentData;
            }

            $this->firebase->update("{$this->intentBase}/{$intentId}", $updateData);

            // Update idempotency record
            $idempotencyPath = "{$this->feesBase}/Idempotency/{$intentId}";
            $this->firebase->update($idempotencyPath, [
                'completed_at' => $now,
                'receipt_no'   => $receiptNo,
                'status'       => 'completed',
            ]);

            log_message('info', "PaymentIntentListener: Intent={$intentId} marked as completed. receipt={$receiptNo}");
            return true;

        } catch (\Exception $e) {
            log_message('error', "PaymentIntentListener: complete_intent exception for intent={$intentId}: " . $e->getMessage());
            return false;
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    //  5. EXPIRE STALE — Cron endpoint for stale intents
    // ══════════════════════════════════════════════════════════════════════

    /**
     * GET or CLI — Expire intents stuck in "order_created" for > 30 minutes.
     *
     * Usage:
     *   GET  /payment_intent_listener/expire_stale
     *   CLI  php index.php payment_intent_listener expire_stale
     */
    public function expire_stale()
    {
        $staleThresholdMinutes = 30;
        $now       = time();
        $expired   = 0;
        $errors    = [];

        log_message('info', 'PaymentIntentListener: expire_stale started');

        try {
            $allIntents = $this->firebase->get($this->intentBase);
            if (!is_array($allIntents) || empty($allIntents)) {
                $this->_json_out([
                    'status'  => 'success',
                    'message' => 'No intents found.',
                    'expired' => 0,
                ]);
                return;
            }

            foreach ($allIntents as $intentId => $intentData) {
                if (!is_array($intentData)) continue;

                $status = $intentData['status'] ?? '';
                // Expire intents stuck in "order_created" or "processing"
                if (!in_array($status, ['order_created', 'processing'], true)) continue;

                // Determine the timestamp to check staleness against
                $timestampField = ($status === 'order_created')
                    ? ($intentData['order_created_at'] ?? '')
                    : ($intentData['processing_at'] ?? '');

                if ($timestampField === '') {
                    // Fallback to created_at
                    $timestampField = $intentData['created_at'] ?? '';
                }

                if ($timestampField === '') continue;

                $intentTime = strtotime($timestampField);
                if ($intentTime === false) continue;

                $ageMinutes = ($now - $intentTime) / 60;
                if ($ageMinutes < $staleThresholdMinutes) continue;

                // Expire this intent
                try {
                    $this->firebase->update("{$this->intentBase}/{$intentId}", [
                        'status'     => 'expired',
                        'expired_at' => date('c'),
                        'expired_reason' => "Stale after " . round($ageMinutes) . " minutes in \"{$status}\" status.",
                    ]);
                    $expired++;
                    log_message('info', "PaymentIntentListener: Expired stale intent={$intentId} age=" . round($ageMinutes) . "min status={$status}");
                } catch (\Exception $e) {
                    $errors[] = ['intent_id' => $intentId, 'error' => $e->getMessage()];
                    log_message('error', "PaymentIntentListener: Failed to expire intent={$intentId}: " . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'PaymentIntentListener: Fatal error in expire_stale: ' . $e->getMessage());
            $errors[] = ['intent_id' => 'GLOBAL', 'error' => $e->getMessage()];
        }

        log_message('info', "PaymentIntentListener: expire_stale completed. expired={$expired}");

        $this->_json_out([
            'status'  => 'success',
            'expired' => $expired,
            'errors'  => $errors,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  6. STATUS — GET endpoint for debugging / admin monitoring
    // ══════════════════════════════════════════════════════════════════════

    /**
     * GET /payment_intent_listener/status/{intentId}
     *
     * Returns the current intent data as JSON.
     */
    public function status(string $intentId = '')
    {
        $this->_require_role(
            ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Accountant'],
            'view_payment_intent'
        );

        $intentId = trim($intentId);
        if ($intentId === '') {
            $this->json_error('Intent ID is required.', 400);
            return;
        }

        // Sanitize
        if (preg_match('/[.\/\#\$\[\]]/', $intentId)) {
            $this->json_error('Invalid intent ID format.', 400);
            return;
        }

        $intentData = $this->firebase->get("{$this->intentBase}/{$intentId}");
        if (!is_array($intentData) || empty($intentData)) {
            $this->json_error('Intent not found.', 404);
            return;
        }

        $this->json_success([
            'intent_id' => $intentId,
            'intent'    => $intentData,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  7. VALIDATE INTENT (private)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Validate intent data before processing.
     *
     * @param  array $data  Intent data
     * @return array {valid: bool, error: string}
     */
    private function _validate_intent(array $data): array
    {
        // student_id must exist and not be empty
        $studentId = trim($data['student_id'] ?? '');
        if ($studentId === '') {
            return ['valid' => false, 'error' => 'student_id is required.'];
        }

        // amount must be > 0
        $amount = floatval($data['amount'] ?? 0);
        if ($amount <= 0) {
            return ['valid' => false, 'error' => 'amount must be greater than zero.'];
        }

        // fee_months must be a non-empty array
        $feeMonths = $data['fee_months'] ?? null;
        if (!is_array($feeMonths) || empty($feeMonths)) {
            return ['valid' => false, 'error' => 'fee_months must be a non-empty array.'];
        }

        // status must be "requested" (not already in progress)
        $status = $data['status'] ?? '';
        if ($status !== 'requested') {
            return ['valid' => false, 'error' => "Intent status is \"{$status}\", expected \"requested\"."];
        }

        // class and section should be present (from parent app)
        $class   = trim($data['class'] ?? '');
        $section = trim($data['section'] ?? '');
        if ($class === '' || $section === '') {
            return ['valid' => false, 'error' => 'class and section are required in the intent.'];
        }

        // Sanitize: reject path-injection characters in key fields
        $pathFields = [$studentId, $class, $section];
        foreach ($pathFields as $field) {
            if (preg_match('/[\#\$\[\]]/', $field)) {
                return ['valid' => false, 'error' => 'Intent contains invalid characters in key fields.'];
            }
        }

        return ['valid' => true, 'error' => ''];
    }

    // ══════════════════════════════════════════════════════════════════════
    //  8. CHECK DUPLICATE (private)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Scan existing intents for a duplicate: same student + same months
     * with an active status (order_created, paying, processing).
     *
     * @param  string $studentId
     * @param  array  $feeMonths
     * @return string|null  Existing intent ID, or null if no duplicate
     */
    private function _check_duplicate(string $studentId, array $feeMonths): ?string
    {
        $activeStatuses = ['order_created', 'paying', 'processing'];

        $sortedMonths = $feeMonths;
        sort($sortedMonths);
        $monthKey = implode(',', $sortedMonths);

        try {
            $allIntents = $this->firebase->get($this->intentBase);
            if (!is_array($allIntents)) return null;

            foreach ($allIntents as $id => $intent) {
                if (!is_array($intent)) continue;
                if (($intent['student_id'] ?? '') !== $studentId) continue;
                if (!in_array($intent['status'] ?? '', $activeStatuses, true)) continue;

                // Compare months
                $existingMonths = $intent['fee_months'] ?? [];
                if (is_array($existingMonths)) {
                    sort($existingMonths);
                    if (implode(',', $existingMonths) === $monthKey) {
                        return $id;
                    }
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'PaymentIntentListener: _check_duplicate error: ' . $e->getMessage());
            // On error, allow processing (fail-open for duplicate check)
        }

        return null;
    }

    // ══════════════════════════════════════════════════════════════════════
    //  PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Read student info from Firebase class roster.
     *
     * Path: Schools/{school}/{session}/{class}/{section}/Students/{studentId}/
     *
     * @param  string $studentId
     * @param  string $class     e.g. "Class 9th"
     * @param  string $section   e.g. "Section A"
     * @return array|null  Student data or null
     */
    private function _read_student_info(string $studentId, string $class, string $section): ?array
    {
        try {
            $path = "{$this->sessionRoot}/{$class}/{$section}/Students/{$studentId}";
            $data = $this->firebase->get($path);
            return is_array($data) ? $data : null;
        } catch (\Exception $e) {
            log_message('error', "PaymentIntentListener: Failed to read student info for {$studentId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Safely update an intent in Firebase. Never throws — logs errors instead.
     *
     * @param string $intentId
     * @param array  $data
     */
    private function _safe_update_intent(string $intentId, array $data): void
    {
        try {
            $this->firebase->update("{$this->intentBase}/{$intentId}", $data);
        } catch (\Exception $e) {
            log_message('error', "PaymentIntentListener: Failed to update intent={$intentId}: " . $e->getMessage());
        }
    }

    /**
     * Standardized JSON response for cron/system endpoints.
     * Includes CSRF token when available (for admin-triggered calls).
     *
     * @param array $data
     */
    private function _json_out(array $data): void
    {
        if (property_exists($this, 'security') && $this->security !== null) {
            $data['csrf_token'] = $this->security->get_csrf_hash();
        }
        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($data));
    }
}
