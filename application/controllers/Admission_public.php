<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * PUBLIC ADMISSION FIX — Public admission controller (no login required).
 *
 * Extends CI_Controller directly (NOT MY_Controller) to bypass auth.
 * Provides a public-facing admission form for any school identified by school_id.
 *
 * Routes:
 *   GET  admission/form/{school_id}   → renders form
 *   POST admission/submit/{school_id} → processes submission
 *
 * Firebase paths:
 *   Schools/{school_id}/Config/Profile         — school info (read)
 *   Schools/{school_id}/Config/ActiveSession   — current session (read)
 *   Schools/{school_id}/CRM/Admissions/        — application storage (write)
 *   System/RateLimits/public_admission/{ip}    — rate limiting (read/write)
 */
class Admission_public extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        // PUBLIC ADMISSION FIX — load only what we need, no session/auth
        $this->load->library('firebase');
        $this->load->library('session'); // needed for CSRF
        $this->load->helper('url');
        $this->load->helper('notification');
        $this->load->helper('school_guard');
    }

    // ─── PUBLIC: Render admission form ─────────────────────────────────

    public function form($school_id = '')
    {
        // SCHOOL GUARD — validate format + existence (shows 404 if invalid)
        $school_id = validate_public_school_id($school_id);

        // Fetch school profile (guard already confirmed existence, but we need the data)
        $profile = $this->firebase->get("Schools/{$school_id}/Config/Profile");
        if (!is_array($profile) || empty($profile)) {
            $profile = $this->firebase->get("System/Schools/{$school_id}/profile") ?? [];
        }

        // Get active session to load class list
        $activeSession = $this->firebase->get("Schools/{$school_id}/Config/ActiveSession");
        if (empty($activeSession) || !is_string($activeSession)) {
            $activeSession = '';
        }

        // Build class list from session root
        $classes = [];
        if ($activeSession !== '') {
            // shallow_get() returns array_keys() — a flat array of node names
            $sessionKeys = $this->firebase->shallow_get("Schools/{$school_id}/{$activeSession}");
            if (is_array($sessionKeys)) {
                foreach ($sessionKeys as $nodeName) {
                    if (strpos($nodeName, 'Class ') !== 0) continue;
                    $classes[] = $nodeName;
                }
                // Natural sort: Class 1st, Class 2nd, ... Class 10th
                usort($classes, 'strnatcmp');
            }
        }

        $data = [
            'school_id'      => $school_id,
            'school_profile' => $profile,
            'session_year'   => $activeSession,
            'classes'        => $classes,
        ];

        $this->load->view('admission/public_form', $data);
    }

    // ─── PUBLIC: Handle form submission ─────────────────────────────────

    public function submit($school_id = '')
    {
        header('Content-Type: application/json');

        // PUBLIC ADMISSION FIX — accept only POST
        if ($this->input->method() !== 'post') {
            http_response_code(405);
            echo json_encode(['status' => 'error', 'message' => 'POST required.']);
            return;
        }

        // SCHOOL GUARD — validate format + existence
        $school_id = validate_public_school_id($school_id);

        // Fetch profile for display name (guard confirmed existence)
        $profile = $this->firebase->get("Schools/{$school_id}/Config/Profile");
        if (!is_array($profile) || empty($profile)) {
            $profile = $this->firebase->get("System/Schools/{$school_id}/profile") ?? [];
        }

        // ── Rate limiting: 10 submissions per IP per 15 minutes ──
        $clientIp = $this->input->ip_address();
        $ipKey    = preg_replace('/[^a-zA-Z0-9]/', '_', $clientIp);
        $ratePath = "System/RateLimits/public_admission/{$ipKey}";
        $rateData = $this->firebase->get($ratePath);
        $windowStart = time() - 900;

        if (is_array($rateData)) {
            $recentCount = 0;
            foreach ($rateData as $ts => $v) {
                if ((int) $ts >= $windowStart) {
                    $recentCount++;
                } else {
                    // Clean up expired entries
                    $this->firebase->delete($ratePath, (string) $ts);
                }
            }
            if ($recentCount >= 10) {
                http_response_code(429);
                echo json_encode(['status' => 'error', 'message' => 'Too many submissions. Please try again later.']);
                return;
            }
        }
        // Record this attempt
        $this->firebase->set("{$ratePath}/" . time() . '_' . mt_rand(1000, 9999), 1);

        // ── Input validation with length limits ──
        $fieldLimits = [
            'student_name' => 100, 'parent_name' => 100, 'phone' => 20,
            'email' => 150, 'class' => 50, 'message' => 500,
        ];
        $data = [];
        foreach ($fieldLimits as $f => $maxLen) {
            $val = trim($this->input->post($f) ?? '');
            if (mb_strlen($val) > $maxLen) {
                echo json_encode(['status' => 'error', 'message' => "Field '{$f}' exceeds maximum length."]);
                return;
            }
            $data[$f] = $val;
        }

        // Required fields
        if ($data['student_name'] === '') {
            echo json_encode(['status' => 'error', 'message' => 'Student name is required.']);
            return;
        }
        if ($data['class'] === '') {
            echo json_encode(['status' => 'error', 'message' => 'Class is required.']);
            return;
        }
        if ($data['phone'] === '') {
            echo json_encode(['status' => 'error', 'message' => 'Phone number is required.']);
            return;
        }

        // Phone format: 10-15 digits (with optional + prefix)
        $phoneCleaned = preg_replace('/[\s\-]/', '', $data['phone']);
        if (!preg_match('/^\+?\d{10,15}$/', $phoneCleaned)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid phone number format.']);
            return;
        }

        // Email format (optional)
        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid email address.']);
            return;
        }

        // ── Duplicate lead prevention ──────────────────────────────────────
        // LEAD SYSTEM — prevent same phone + same class within 24 hours.
        // Uses a lightweight index at System/DupCheck/admission/{school}/{phoneHash}
        // to avoid scanning the entire Applications collection.
        $phoneNorm = preg_replace('/[^0-9]/', '', $phoneCleaned);       // digits only
        $phoneHash = substr(md5($phoneNorm), 0, 16);                   // short hash (collision-safe enough)
        $dupPath   = "System/DupCheck/admission/{$school_id}/{$phoneHash}";
        $dupRecord = $this->firebase->get($dupPath);

        if (is_array($dupRecord)) {
            $dupClass = $dupRecord['class'] ?? '';
            $dupTime  = (int) ($dupRecord['ts'] ?? 0);
            $elapsed  = time() - $dupTime;

            // Same phone + same class + within 24 hours = duplicate
            if ($dupClass === $data['class'] && $elapsed < 86400) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'You have already applied recently. Our team will contact you shortly.',
                ]);
                return;
            }
        }

        // ── Generate lead ID and store ──
        $now     = date('Y-m-d H:i:s');
        $crmBase = "Schools/{$school_id}/CRM/Admissions";

        // Generate unique application ID using atomic push() to avoid race conditions.
        // push() generates a Firebase-unique key atomically — no read-increment-write.
        // We derive a readable APP prefix + short unique suffix from the push key.
        $counterPath = "{$crmBase}/CounterLog";
        $pushKey     = $this->firebase->push($counterPath, ['ts' => time()]);
        if ($pushKey === null) {
            // Fallback: timestamp + random if push fails (still unique, just less clean)
            $pushKey = time() . '_' . mt_rand(10000, 99999);
            log_message('error', "Admission_public::submit push() failed for {$school_id}, using fallback ID");
        }
        // Extract last 8 chars of push key for readability (Firebase keys are ~20 chars, unique)
        $appId = 'APP_' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $pushKey), -8));

        $applicationData = [
            'id'            => $appId,
            'student_name'  => $data['student_name'],
            'parent_name'   => $data['parent_name'],
            'phone'         => $data['phone'],
            'email'         => $data['email'],
            'class'         => $data['class'],
            'notes'         => $data['message'],
            'status'         => 'new',
            'stage'          => 'inquiry',
            'source'         => 'public_form',
            'payment_status' => 'pending',
            'session'       => $this->firebase->get("Schools/{$school_id}/Config/ActiveSession") ?? '',
            'created_at'    => $now,
            'updated_at'    => $now,
            'submitted_ip'  => $clientIp,
            'history'       => [
                ['action' => 'Application submitted via public form', 'by' => 'Public', 'timestamp' => $now]
            ],
        ];

        $this->firebase->set("{$crmBase}/Applications/{$appId}", $applicationData);

        // LEAD SYSTEM — record duplicate-check entry (written only on successful save)
        $this->firebase->set($dupPath, [
            'class' => $data['class'],
            'ts'    => time(),
            'app'   => $appId,
        ]);

        // LEAD SYSTEM — notify parent (fire-and-forget, never blocks response)
        $schoolDisplayName = $profile['display_name'] ?? $profile['school_name'] ?? $profile['name'] ?? $school_id;
        notify_admission_received($data['phone'], $schoolDisplayName, $appId);

        // AUDIT — log public application submission
        $this->_public_audit($school_id, 'Admission', 'public_application', $appId,
            "Public admission submitted: {$data['student_name']} for class {$data['class']}");

        // Check if school requires admission fee payment
        $admissionFee = $this->firebase->get("Schools/{$school_id}/Config/AdmissionFee");
        $feeAmount    = (is_array($admissionFee) && !empty($admissionFee['amount']))
                      ? (float) $admissionFee['amount'] : 0;
        $feeEnabled   = (is_array($admissionFee) && !empty($admissionFee['enabled']));

        echo json_encode([
            'status'          => 'success',
            'message'         => 'Application submitted successfully.',
            'app_id'          => $appId,
            'payment_required'=> $feeEnabled && $feeAmount > 0,
            'payment_amount'  => $feeAmount,
            'csrf_token'      => $this->security->get_csrf_hash(),
        ]);
    }

    // ─── PAYMENT: Initiate admission fee payment ───────────────────────

    public function initiate_payment($school_id = '')
    {
        header('Content-Type: application/json');

        if ($this->input->method() !== 'post') {
            http_response_code(405);
            echo json_encode(['status' => 'error', 'message' => 'POST required.']);
            return;
        }

        // SCHOOL GUARD — validate format + existence
        $school_id = validate_public_school_id($school_id);

        $appId = trim($this->input->post('app_id') ?? '');
        if ($appId === '' || !preg_match('/^[A-Za-z0-9_]+$/', $appId)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid application ID.']);
            return;
        }

        // Fetch application — verify it exists and belongs to this school
        $crmBase = "Schools/{$school_id}/CRM/Admissions";
        $app = $this->firebase->get("{$crmBase}/Applications/{$appId}");
        if (!is_array($app)) {
            echo json_encode(['status' => 'error', 'message' => 'Application not found.']);
            return;
        }

        // Prevent duplicate payment
        if (($app['payment_status'] ?? '') === 'paid') {
            echo json_encode(['status' => 'error', 'message' => 'Payment already completed for this application.']);
            return;
        }

        // Get admission fee config
        $admissionFee = $this->firebase->get("Schools/{$school_id}/Config/AdmissionFee");
        if (!is_array($admissionFee) || empty($admissionFee['enabled']) || empty($admissionFee['amount'])) {
            echo json_encode(['status' => 'error', 'message' => 'Admission fee is not configured for this school.']);
            return;
        }
        $amount   = (float) $admissionFee['amount'];
        $currency = $admissionFee['currency'] ?? 'INR';
        if ($amount <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid fee amount.']);
            return;
        }

        // Create payment order via gateway adapter
        $this->load->library('Payment_gateway_mock');
        $gateway = $this->payment_gateway_mock;

        $order = $gateway->create_order($amount, $appId, [
            'school_id'    => $school_id,
            'app_id'       => $appId,
            'student_name' => $app['student_name'] ?? '',
            'type'         => 'admission_fee',
        ]);

        $now = date('Y-m-d H:i:s');
        $paymentId = 'ADMPAY_' . time() . '_' . mt_rand(1000, 9999);

        // Store payment record in Firebase
        $paymentRecord = [
            'payment_id'   => $paymentId,
            'order_id'     => $order['order_id'],
            'app_id'       => $appId,
            'student_name' => $app['student_name'] ?? '',
            'phone'        => $app['phone'] ?? '',
            'amount'       => $amount,
            'currency'     => $currency,
            'status'       => 'created',
            'gateway'      => $order['gateway'] ?? 'mock',
            'created_at'   => $now,
            'ip'           => $this->input->ip_address(),
        ];
        $this->firebase->set("Schools/{$school_id}/Payments/Admissions/{$paymentId}", $paymentRecord);

        // Mark application as payment-initiated
        $this->firebase->update("{$crmBase}/Applications/{$appId}", [
            'payment_status' => 'initiated',
            'payment_id'     => $paymentId,
            'order_id'       => $order['order_id'],
            'updated_at'     => $now,
        ]);

        // Return order details for frontend checkout
        $profile = $this->firebase->get("Schools/{$school_id}/Config/Profile") ?? [];
        echo json_encode([
            'status'       => 'success',
            'payment_id'   => $paymentId,
            'order_id'     => $order['order_id'],
            'amount'       => $amount,
            'amount_paise' => (int) ($amount * 100),
            'currency'     => $currency,
            'school_name'  => $profile['display_name'] ?? $school_id,
            'student_name' => $app['student_name'] ?? '',
            'email'        => $app['email'] ?? '',
            'phone'        => $app['phone'] ?? '',
            'gateway'      => $order['gateway'] ?? 'mock',
            'csrf_token'   => $this->security->get_csrf_hash(),
        ]);
    }

    // ─── PAYMENT: Verify and confirm payment ───────────────────────────

    public function payment_callback($school_id = '')
    {
        header('Content-Type: application/json');

        if ($this->input->method() !== 'post') {
            http_response_code(405);
            echo json_encode(['status' => 'error', 'message' => 'POST required.']);
            return;
        }

        // SCHOOL GUARD — validate format + existence
        $school_id = validate_public_school_id($school_id);

        $paymentId  = trim($this->input->post('payment_id') ?? '');
        $orderId    = trim($this->input->post('order_id') ?? '');
        $gatewayPid = trim($this->input->post('gateway_payment_id') ?? '');
        $signature  = trim($this->input->post('signature') ?? '');

        if ($paymentId === '' || $orderId === '' || $gatewayPid === '') {
            echo json_encode(['status' => 'error', 'message' => 'Missing payment details.']);
            return;
        }

        // Fetch payment record
        $paymentPath = "Schools/{$school_id}/Payments/Admissions/{$paymentId}";
        $payment = $this->firebase->get($paymentPath);
        if (!is_array($payment)) {
            echo json_encode(['status' => 'error', 'message' => 'Payment record not found.']);
            return;
        }

        // Prevent double-processing
        if (($payment['status'] ?? '') === 'paid') {
            echo json_encode(['status' => 'success', 'message' => 'Payment already confirmed.', 'already_paid' => true]);
            return;
        }

        // Verify order_id matches
        if (($payment['order_id'] ?? '') !== $orderId) {
            echo json_encode(['status' => 'error', 'message' => 'Order ID mismatch.']);
            return;
        }

        // Verify payment via gateway adapter
        $this->load->library('Payment_gateway_mock');
        $gateway = $this->payment_gateway_mock;

        if (($payment['gateway'] ?? 'mock') === 'mock') {
            // Mock mode: server-side simulation (frontend doesn't have real gateway SDK)
            $simResult  = $gateway->simulate_payment($orderId, (float)($payment['amount'] ?? 0));
            $gatewayPid = $simResult['payment_id'];
            $signature  = $simResult['signature'] ?? '';
            $verified   = $simResult['status'] === 'success'
                        && $gateway->verify_signature($orderId, $gatewayPid, $signature);
        } else {
            // Real gateway: verify the signature from the callback
            $verified = $gateway->verify_signature($orderId, $gatewayPid, $signature);
        }

        $now   = date('Y-m-d H:i:s');
        $appId = $payment['app_id'] ?? '';

        if ($verified) {
            // Update payment record
            $this->firebase->update($paymentPath, [
                'status'             => 'paid',
                'gateway_payment_id' => $gatewayPid,
                'signature'          => $signature,
                'verified_at'        => $now,
            ]);

            // Update application status
            if ($appId !== '') {
                $crmBase = "Schools/{$school_id}/CRM/Admissions";
                $app = $this->firebase->get("{$crmBase}/Applications/{$appId}");
                $history = is_array($app) ? ($app['history'] ?? []) : [];
                $history[] = ['action' => "Admission fee paid ({$gatewayPid})", 'by' => 'Payment Gateway', 'timestamp' => $now];

                $this->firebase->update("{$crmBase}/Applications/{$appId}", [
                    'payment_status' => 'paid',
                    'status'         => 'approved',
                    'stage'          => 'approved',
                    'updated_at'     => $now,
                    'history'        => $history,
                ]);
            }

            // Notify parent — use currency from payment record, fallback to INR
            $this->load->helper('notification');
            $profile = $this->firebase->get("Schools/{$school_id}/Config/Profile") ?? [];
            $schoolName = $profile['display_name'] ?? $profile['school_name'] ?? $school_id;
            $payCurrency = $payment['currency'] ?? 'INR';
            notify_sms($payment['phone'] ?? '', "Payment of {$payCurrency} {$payment['amount']} received for {$schoolName} admission (Ref: {$paymentId}). Your application is now approved.", [
                'type'       => 'payment_success',
                'payment_id' => $paymentId,
            ]);

            // AUDIT — log successful payment (public context, no session)
            $this->_public_audit($school_id, 'Payment', 'payment_success', $paymentId,
                "Admission fee paid: {$payCurrency} {$payment['amount']} for app {$appId}");

            echo json_encode([
                'status'     => 'success',
                'message'    => 'Payment verified successfully. Application approved!',
                'csrf_token' => $this->security->get_csrf_hash(),
            ]);
        } else {
            // Payment verification failed
            $this->firebase->update($paymentPath, [
                'status'             => 'failed',
                'gateway_payment_id' => $gatewayPid,
                'failure_reason'     => 'Signature verification failed',
                'failed_at'          => $now,
            ]);

            // AUDIT — log failed payment
            $this->_public_audit($school_id, 'Payment', 'payment_failed', $paymentId,
                "Payment verification failed for app {$appId}");

            echo json_encode(['status' => 'error', 'message' => 'Payment verification failed.', 'csrf_token' => $this->security->get_csrf_hash()]);
        }
    }

    // ─── PAYMENT: Status check (polling) ───────────────────────────────

    public function payment_status($school_id = '')
    {
        header('Content-Type: application/json');

        // SCHOOL GUARD — validate format + existence
        $school_id = validate_public_school_id($school_id);
        $paymentId = trim($this->input->get('payment_id') ?? '');
        if ($paymentId === '' || !preg_match('/^[A-Za-z0-9_]+$/', $paymentId)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid payment ID.']);
            return;
        }
        $payment = $this->firebase->get("Schools/{$school_id}/Payments/Admissions/{$paymentId}");
        if (!is_array($payment)) {
            echo json_encode(['status' => 'error', 'message' => 'Payment not found.']);
            return;
        }
        echo json_encode([
            'status'         => 'success',
            'payment_status' => $payment['status'] ?? 'unknown',
            'amount'         => $payment['amount'] ?? 0,
        ]);
    }

    // ─── Audit logging for public context (no session) ─────────────────

    /**
     * Write audit entry from public controller (no session/admin context).
     * Uses 'Public' as the user identifier. Non-blocking.
     */
    private function _public_audit(string $schoolId, string $module, string $action, string $entityId, string $description): void
    {
        try {
            $logId = 'AL_' . date('Ymd_His') . '_' . substr(uniqid('', true), -6);
            $this->firebase->set("Schools/{$schoolId}/AuditLogs/{$logId}", [
                'userId'      => 'public',
                'userName'    => 'Public Form',
                'userRole'    => 'Public',
                'module'      => $module,
                'action'      => $action,
                'entityId'    => $entityId,
                'description' => $description,
                'ipAddress'   => $this->input->ip_address(),
                'timestamp'   => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Admission_public::_public_audit failed: ' . $e->getMessage());
        }
    }
}
