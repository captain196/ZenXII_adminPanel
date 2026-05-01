<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * security_log_helper — Structured security event logging.
 *
 * Logs security events to Firebase RTDB at System/Logs/Security/{pushKey}
 * and to CodeIgniter's error log. Never logs passwords or tokens.
 *
 * Events logged:
 *   AUTH_FAILURE       — Failed login attempt
 *   AUTH_LOCKOUT       — Account locked due to brute force
 *   AUTH_SUCCESS        — Successful login (for audit trail)
 *   CROSS_SCHOOL       — Attempted cross-tenant access
 *   ROLE_DENIED        — Insufficient role for requested action
 *   PATH_VIOLATION     — Firebase path manipulation attempt
 *   TOKEN_INVALID      — Invalid Firebase ID token presented
 *   TOKEN_EXPIRED      — Expired token detected
 *   OTP_ABUSE          — OTP rate limit triggered
 *   SESSION_TAMPER     — Session data failed integrity check
 *   SUSPICIOUS_INPUT   — Malicious input detected (path injection, etc.)
 */

if (!function_exists('log_security')) {
    /**
     * Log a security event.
     *
     * @param string $event   Event type (one of the constants above)
     * @param array  $context Contextual data (NEVER include passwords/tokens)
     * @param string $severity  'warning' or 'critical'
     */
    function log_security(string $event, array $context = [], string $severity = 'warning'): void
    {
        $CI =& get_instance();

        $entry = [
            'event'     => $event,
            'severity'  => $severity,
            'ip'        => $CI->input->ip_address(),
            'uri'       => $CI->uri->uri_string(),
            'method'    => $CI->input->method(),
            'user_agent' => substr((string) $CI->input->user_agent(), 0, 200),
            'timestamp' => date('c'),
        ];

        // Add session context if available (don't crash if no session)
        if (isset($CI->session)) {
            $entry['admin_id']  = $CI->session->userdata('admin_id') ?? null;
            $entry['school_id'] = $CI->session->userdata('school_id') ?? null;
            $entry['role']      = $CI->session->userdata('admin_role') ?? null;
        }

        // Merge caller context (sanitize — never log sensitive data)
        $safeContext = array_diff_key($context, array_flip([
            'password', 'token', 'idToken', 'refreshToken', 'otp',
            'secret', 'api_key', 'credentials', 'hash',
        ]));
        $entry = array_merge($entry, $safeContext);

        // Write to CI error log (always succeeds)
        $logLine = "SECURITY [{$event}] [{$severity}] "
            . "ip={$entry['ip']} uri={$entry['uri']} "
            . "admin={$entry['admin_id']} school={$entry['school_id']} "
            . json_encode($safeContext);
        log_message('error', $logLine);

        // Write to Firebase (best-effort, don't block the request)
        try {
            if (isset($CI->firebase)) {
                $monthKey = date('Y-m');
                $CI->firebase->push("System/Logs/Security/{$monthKey}", $entry);
            }
        } catch (\Exception $e) {
            // Silently fail — logging should never break functionality
        }
    }
}

if (!function_exists('log_auth_failure')) {
    /**
     * Convenience: log an authentication failure.
     */
    function log_auth_failure(string $userId, string $reason, array $extra = []): void
    {
        log_security('AUTH_FAILURE', array_merge([
            'target_user' => $userId,
            'reason'      => $reason,
        ], $extra));
    }
}

if (!function_exists('log_auth_success')) {
    /**
     * Convenience: log a successful authentication.
     */
    function log_auth_success(string $userId, string $method = 'firebase_auth', array $extra = []): void
    {
        log_security('AUTH_SUCCESS', array_merge([
            'target_user' => $userId,
            'auth_method' => $method,
        ], $extra), 'info');
    }
}

if (!function_exists('log_cross_school_attempt')) {
    /**
     * Convenience: log a cross-school access attempt.
     */
    function log_cross_school_attempt(string $targetSchool, string $action = ''): void
    {
        log_security('CROSS_SCHOOL', [
            'target_school' => $targetSchool,
            'action'        => $action,
        ], 'critical');
    }
}
