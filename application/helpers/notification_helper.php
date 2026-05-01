<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Notification Helper — Provider-agnostic messaging layer.
 *
 * Currently logs messages (simulation mode). Designed for drop-in replacement
 * with SMS gateway (Twilio, MSG91, etc.) or WhatsApp Business API.
 *
 * Usage:
 *   $this->load->helper('notification');
 *   notify_sms($phone, $message, $context);
 *
 * To integrate a real provider, edit _dispatch_sms() below.
 * The public API (notify_sms / notify_admission_received / notify_admission_confirmed)
 * stays unchanged — all callers are unaffected.
 */

// ─── Core dispatch (swap this for real provider) ───────────────────────

if (!function_exists('_dispatch_sms')) {
    /**
     * Low-level send function. Replace internals with real API call.
     *
     * @param string $phone   Normalized phone (digits, optional +)
     * @param string $message Message body (max ~160 chars for SMS)
     * @param array  $context Optional metadata for logging/templates
     * @return bool  True if sent (or simulated), false on failure
     */
    function _dispatch_sms(string $phone, string $message, array $context = []): bool
    {
        // ┌──────────────────────────────────────────────────────────┐
        // │  SIMULATION MODE — replace this block with real provider │
        // │                                                          │
        // │  Example (Twilio):                                       │
        // │    $twilio = new \Twilio\Rest\Client($sid, $token);     │
        // │    $twilio->messages->create($phone, [                  │
        // │        'from' => $fromNumber,                            │
        // │        'body' => $message,                               │
        // │    ]);                                                    │
        // │                                                          │
        // │  Example (MSG91):                                        │
        // │    $ch = curl_init('https://api.msg91.com/...');        │
        // │    curl_setopt_array($ch, [...]);                       │
        // │    curl_exec($ch);                                       │
        // │                                                          │
        // │  Example (WhatsApp Business):                            │
        // │    POST https://graph.facebook.com/v18.0/{phone_id}/    │
        // │         messages                                         │
        // └──────────────────────────────────────────────────────────┘

        $contextStr = !empty($context) ? ' | ctx=' . json_encode($context) : '';
        log_message('info', "NOTIFICATION [SIM] to={$phone} msg=\"{$message}\"{$contextStr}");
        return true;
    }
}

// ─── Public API ────────────────────────────────────────────────────────

if (!function_exists('notify_sms')) {
    /**
     * Send an SMS/WhatsApp notification. Fails silently (never blocks caller).
     *
     * @param string $phone   Recipient phone number
     * @param string $message Message text
     * @param array  $context Optional: ['type'=>'admission', 'school'=>'...', 'app_id'=>'...']
     * @return bool
     */
    function notify_sms(string $phone, string $message, array $context = []): bool
    {
        try {
            if (empty($phone) || empty($message)) return false;

            // Normalize: strip spaces/dashes, keep + prefix
            $phone = preg_replace('/[\s\-\(\)]/', '', trim($phone));

            return _dispatch_sms($phone, $message, $context);
        } catch (\Throwable $e) {
            log_message('error', 'notify_sms failed: ' . $e->getMessage());
            return false;
        }
    }
}

// ─── Pre-built notification templates ──────────────────────────────────

if (!function_exists('notify_admission_received')) {
    /**
     * Notify parent that their admission application was received.
     *
     * `$receiptUrl` is optional — when provided, the SMS body links the
     * parent to a printable PDF receipt of the submitted application.
     * Kept optional so any other caller of this helper that doesn't yet
     * pass the URL (legacy / batch retry) keeps working.
     */
    function notify_admission_received(string $phone, string $schoolName, string $appId, string $receiptUrl = ''): bool
    {
        $msg = "Thank you for applying to {$schoolName}. "
             . "Your application ID is {$appId}.";
        if ($receiptUrl !== '') {
            $msg .= " Receipt: {$receiptUrl}";
        }
        $msg .= " Our team will contact you shortly.";
        return notify_sms($phone, $msg, [
            'type'        => 'admission_received',
            'school'      => $schoolName,
            'app_id'      => $appId,
            'receipt_url' => $receiptUrl,
        ]);
    }
}

if (!function_exists('notify_admission_confirmed')) {
    /**
     * Notify parent that their child's admission is confirmed.
     */
    function notify_admission_confirmed(string $phone, string $schoolName, string $studentId, string $studentName): bool
    {
        $msg = "Congratulations! {$studentName}'s admission to {$schoolName} is confirmed. "
             . "Student ID: {$studentId}. Welcome to our school family!";
        return notify_sms($phone, $msg, [
            'type'       => 'admission_confirmed',
            'school'     => $schoolName,
            'student_id' => $studentId,
        ]);
    }
}

if (!function_exists('notify_enrollment_credentials')) {
    /**
     * Send the parent app login credentials to the parent's phone.
     *
     * Sent immediately after `enroll_student` creates the Firebase Auth
     * user. Without this SMS the parent has no way to discover the
     * auto-generated password and can't log in. Phase A Part 1.
     *
     * The message includes a hint that password change is required on
     * first login (Phase A Part 2 enforces it in the parent app).
     */
    function notify_enrollment_credentials(string $phone, string $schoolName, string $studentName, string $studentId, string $password): bool
    {
        $msg = "Welcome to {$schoolName}! {$studentName}'s admission is confirmed. "
             . "Parent app login — User ID: {$studentId}, Password: {$password}. "
             . "You will be asked to set a new password on first login.";
        return notify_sms($phone, $msg, [
            'type'       => 'enrollment_credentials',
            'school'     => $schoolName,
            'student_id' => $studentId,
        ]);
    }
}
