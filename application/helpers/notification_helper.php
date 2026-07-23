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

        // Route to a real provider when one is configured via environment
        // variables (secret-safe — no keys in source/git). With NO provider
        // configured this falls back to simulation (log only), so default
        // behaviour is unchanged until a school wires a gateway.
        //   SMS_PROVIDER = 'msg91' | 'twilio'
        //   MSG91_AUTHKEY / MSG91_SENDER / MSG91_ROUTE
        //   TWILIO_SID / TWILIO_TOKEN / TWILIO_FROM
        $provider = strtolower(trim((string) getenv('SMS_PROVIDER')));

        // Normalize: bare 10-digit Indian numbers → 91XXXXXXXXXX / +91….
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) === 10) $digits = '91' . $digits;
        $e164 = '+' . $digits;

        try {
            if ($provider === 'msg91') {
                $authKey = (string) getenv('MSG91_AUTHKEY');
                if ($authKey === '') return _sms_sim($phone, $message, $context, 'msg91 authkey missing');
                $url = 'https://api.msg91.com/api/sendhttp.php?' . http_build_query([
                    'authkey' => $authKey,
                    'mobiles' => $digits,
                    'message' => $message,
                    'sender'  => (string) (getenv('MSG91_SENDER') ?: 'SCHOOL'),
                    'route'   => (string) (getenv('MSG91_ROUTE') ?: '4'),
                    'country' => '0',
                ]);
                $resp = _sms_http('GET', $url);
                $ok = ($resp !== null && stripos($resp, 'error') === false);
                log_message($ok ? 'info' : 'error', "NOTIFICATION [MSG91] to={$phone} ok=" . ($ok ? '1' : '0') . " resp=" . substr((string) $resp, 0, 140));
                return $ok;
            }
            if ($provider === 'twilio') {
                $sid = (string) getenv('TWILIO_SID'); $tok = (string) getenv('TWILIO_TOKEN'); $from = (string) getenv('TWILIO_FROM');
                if ($sid === '' || $tok === '' || $from === '') return _sms_sim($phone, $message, $context, 'twilio not configured');
                $resp = _sms_http('POST', "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json",
                    ['To' => $e164, 'From' => $from, 'Body' => $message], $sid . ':' . $tok);
                $ok = ($resp !== null && strpos($resp, '"sid"') !== false && stripos($resp, '"error_code": ') === false);
                log_message($ok ? 'info' : 'error', "NOTIFICATION [TWILIO] to={$phone} ok=" . ($ok ? '1' : '0'));
                return $ok;
            }
        } catch (\Throwable $e) {
            log_message('error', 'SMS provider dispatch failed: ' . $e->getMessage());
            return false;
        }

        // No provider configured → simulation (log only) — unchanged default.
        return _sms_sim($phone, $message, $context, 'no SMS_PROVIDER configured');
    }
}

if (!function_exists('_sms_sim')) {
    /** Simulation sink — logs the message and returns true (no real send). */
    function _sms_sim(string $phone, string $message, array $context = [], string $why = ''): bool
    {
        $ctx = !empty($context) ? ' | ctx=' . json_encode($context) : '';
        $w   = $why !== '' ? " ({$why})" : '';
        // SECURITY: never log credentials/OTPs in cleartext. With no SMS provider
        // configured this sim sink is the default path, so an un-redacted enroll
        // message would write the parent's login password straight to the log
        // file. Mask secret-bearing tokens while keeping the template readable.
        $safe = preg_replace('/(Password|Pass|OTP|PIN|User\s*ID|Login\s*ID)\s*[:=]\s*\S+/i', '$1: [REDACTED]', $message);
        log_message('info', "NOTIFICATION [SIM]{$w} to={$phone} len=" . strlen($message) . " msg=\"{$safe}\"{$ctx}");
        return true;
    }
}

if (!function_exists('_sms_http')) {
    /** Minimal cURL wrapper for provider calls. Returns the response body, or null on transport error. */
    function _sms_http(string $method, string $url, array $post = [], string $basicAuth = ''): ?string
    {
        if (!function_exists('curl_init')) { log_message('error', 'SMS: cURL unavailable'); return null; }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
        ]);
        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        if ($basicAuth !== '') curl_setopt($ch, CURLOPT_USERPWD, $basicAuth);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) { log_message('error', "SMS HTTP transport error: {$err}"); return null; }
        if ($code >= 400)   { log_message('error', "SMS HTTP {$code}: " . substr((string) $body, 0, 140)); }
        return (string) $body;
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

if (!function_exists('notify_application_approved')) {
    /** Notify an applicant that their application was approved (decision outcome). */
    function notify_application_approved(string $phone, string $schoolName, string $studentName, string $appId, string $remarks = ''): bool
    {
        $who = $studentName !== '' ? $studentName . "'s" : 'your';
        $msg = "Good news! {$who} application to {$schoolName} (Ref: {$appId}) has been APPROVED.";
        if ($remarks !== '') $msg .= " Note: {$remarks}.";
        $msg .= " Our team will contact you with the next steps.";
        return notify_sms($phone, $msg, ['type' => 'application_approved', 'school' => $schoolName, 'app_id' => $appId]);
    }
}

if (!function_exists('notify_application_rejected')) {
    /** Notify an applicant that their application was not accepted (decision outcome). */
    function notify_application_rejected(string $phone, string $schoolName, string $studentName, string $appId, string $reason = ''): bool
    {
        $who = $studentName !== '' ? $studentName . "'s" : 'your';
        $msg = "Update on {$who} application to {$schoolName} (Ref: {$appId}): we're unable to offer admission at this time.";
        if ($reason !== '') $msg .= " Reason: {$reason}.";
        $msg .= " Thank you for your interest.";
        return notify_sms($phone, $msg, ['type' => 'application_rejected', 'school' => $schoolName, 'app_id' => $appId]);
    }
}
