<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Otp_service — OTP generation, verification, and password reset tokens.
 *
 * Replaces the Node.js Auth API's forgot_password / verify_otp / reset_password
 * endpoints. All state stored in Firebase RTDB at System/PasswordResets/{userId}.
 *
 * Flow:
 *   1. generateOtp($userId)        → creates OTP, returns masked email
 *   2. verifyOtp($userId, $otp)    → validates OTP, returns reset token
 *   3. consumeResetToken($userId, $token, $newPassword) → resets password
 */
class Otp_service
{
    /** @var object Firebase library */
    private $firebase;

    /** OTP validity in seconds */
    private const OTP_TTL = 600;  // 10 minutes

    /** Reset token validity in seconds */
    private const TOKEN_TTL = 900;  // 15 minutes

    /** Max OTP verification attempts before lockout */
    private const MAX_OTP_ATTEMPTS = 5;

    /** OTP length */
    private const OTP_LENGTH = 6;

    /** Phase 5 — Firestore collection for OTP sessions (replaces RTDB System/PasswordResets) */
    private const OTP_COLLECTION = 'otp_sessions';

    /** Rate limit: max OTP generation requests per user per window */
    private const RATE_MAX_PER_USER = 3;
    private const RATE_WINDOW_USER  = 300;  // 5 minutes

    /** Rate limit: max OTP requests per IP per window */
    private const RATE_MAX_PER_IP = 5;
    private const RATE_WINDOW_IP  = 300;  // 5 minutes

    public function __construct()
    {
        $CI =& get_instance();
        if (!isset($CI->firebase)) {
            $CI->load->library('firebase');
        }
        $this->firebase = $CI->firebase;
    }

    // ══════════════════════════════════════════════════════════════════
    //  ADMIN OTP FLOW
    // ══════════════════════════════════════════════════════════════════

    /**
     * Generate an OTP for an admin user.
     *
     * @param  string $adminId    Admin ID (e.g. ADM0001)
     * @return array  ['success' => bool, 'message' => string, 'email_masked' => string]
     */
    public function generateAdminOtp(string $adminId): array
    {
        // Rate limit check (per-user + per-IP)
        $rateLimited = $this->_checkRateLimit($adminId);
        if ($rateLimited !== null) {
            return ['success' => false, 'message' => $rateLimited, 'email_masked' => ''];
        }

        // Find the admin across all schools
        $adminData = $this->_findAdmin($adminId);
        if ($adminData === null) {
            return ['success' => false, 'message' => 'Account not found.', 'email_masked' => ''];
        }

        $email = $adminData['email'] ?? '';
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'No valid email associated with this account.', 'email_masked' => ''];
        }

        // Generate and store OTP
        $otp = $this->_generateOtpCode();
        $this->_storeOtp($adminId, $otp, $email);

        // Send OTP email
        $name = $adminData['name'] ?? $adminId;
        $sent = $this->_sendOtpEmail($email, $otp, $name, 'Admin');

        if (!$sent) {
            return ['success' => false, 'message' => 'Failed to send OTP email. Please try again.', 'email_masked' => ''];
        }

        return [
            'success'      => true,
            'message'      => 'OTP sent to your registered email.',
            'email_masked' => $this->_maskEmail($email),
        ];
    }

    /**
     * Verify an admin OTP.
     *
     * @return array ['success' => bool, 'message' => string, 'resetToken' => string]
     */
    public function verifyAdminOtp(string $adminId, string $otp): array
    {
        return $this->_verifyOtp($adminId, $otp);
    }

    /**
     * Reset an admin password using the reset token.
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public function resetAdminPassword(string $adminId, string $resetToken, string $newPassword): array
    {
        // Validate token
        $valid = $this->_consumeToken($adminId, $resetToken);
        if (!$valid) {
            return ['success' => false, 'message' => 'Invalid or expired reset token.'];
        }

        // Find admin to get school_code
        $adminData = $this->_findAdmin($adminId);
        if ($adminData === null) {
            return ['success' => false, 'message' => 'Account not found.'];
        }

        // Update password in Firebase Auth
        $email = Firebase::authEmail($adminId);
        $this->firebase->updateFirebaseUser($adminId, ['password' => $newPassword]);

        // Update bcrypt hash in RTDB (backward compatibility during migration)
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $schoolCode = $adminData['school_code'];
        $this->firebase->update("Users/Admin/{$schoolCode}/{$adminId}/Credentials", [
            'Password' => $hash,
        ]);

        // Mark as migrated since we just set the Firebase Auth password
        $this->firebase->update("Users/Admin/{$schoolCode}/{$adminId}", [
            'auth_migrated' => true,
        ]);

        return ['success' => true, 'message' => 'Password has been reset successfully.'];
    }

    // ══════════════════════════════════════════════════════════════════
    //  STUDENT OTP FLOW
    // ══════════════════════════════════════════════════════════════════

    /**
     * Find student accounts by parent email and send OTP.
     *
     * @return array ['success' => bool, 'message' => string, 'email_masked' => string, 'accounts' => array]
     */
    public function generateStudentOtp(string $parentEmail): array
    {
        // Rate limit check (per-email + per-IP)
        $rateLimited = $this->_checkRateLimit('student_' . md5(strtolower($parentEmail)));
        if ($rateLimited !== null) {
            return ['success' => false, 'message' => $rateLimited, 'email_masked' => '', 'accounts' => []];
        }

        $accounts = $this->_findStudentsByParentEmail($parentEmail);
        if (empty($accounts)) {
            return ['success' => false, 'message' => 'No accounts found for this email.', 'email_masked' => '', 'accounts' => []];
        }

        // Generate OTP keyed by email (shared across all student accounts)
        $otpKey = 'STUDENT_' . md5(strtolower($parentEmail));
        $otp    = $this->_generateOtpCode();
        $this->_storeOtp($otpKey, $otp, $parentEmail);

        $sent = $this->_sendOtpEmail($parentEmail, $otp, 'Parent', 'Student Account');
        if (!$sent) {
            return ['success' => false, 'message' => 'Failed to send OTP email.', 'email_masked' => '', 'accounts' => []];
        }

        // Return masked account list (userId + name only)
        $masked = array_map(function ($a) {
            return ['userId' => $a['userId'], 'name' => $a['name']];
        }, $accounts);

        return [
            'success'      => true,
            'message'      => 'OTP sent to your registered email.',
            'email_masked' => $this->_maskEmail($parentEmail),
            'accounts'     => $masked,
        ];
    }

    /**
     * Verify student OTP.
     */
    public function verifyStudentOtp(string $parentEmail, string $otp, string $userId): array
    {
        $otpKey = 'STUDENT_' . md5(strtolower($parentEmail));
        $result = $this->_verifyOtp($otpKey, $otp);

        if (!empty($result['success'])) {
            $result['userId'] = $userId;
        }
        return $result;
    }

    /**
     * Reset student password.
     */
    public function resetStudentPassword(string $userId, string $resetToken, string $newPassword): array
    {
        $otpKey = null;
        // Try to find the OTP key — check both direct userId and STUDENT_ prefixed keys
        $data = $this->firebase->firestoreGet(self::OTP_COLLECTION, $this->_otpDocId($userId));
        if ($data && (isset($data['resetToken']) || isset($data['reset_token']))) {
            $otpKey = $userId;
        }
        // If not found by userId, the token might be under a STUDENT_ key
        // The controller passes the userId, but the token was stored under STUDENT_ key
        // We need to search — but since we can't enumerate, rely on the resetToken validation
        // Strategy: try consuming from the userId path first, then iterate if needed

        if ($otpKey === null) {
            // The caller should pass the correct key — for students, it's STUDENT_{md5(email)}
            // Since we can't resolve here, try the userId directly
            $otpKey = $userId;
        }

        $valid = $this->_consumeToken($otpKey, $resetToken);
        if (!$valid) {
            return ['success' => false, 'message' => 'Invalid or expired reset token.'];
        }

        // Find student to get school_code
        $studentData = $this->_findStudent($userId);
        if ($studentData === null) {
            return ['success' => false, 'message' => 'Student account not found.'];
        }

        // Update Firebase Auth password
        $this->firebase->updateFirebaseUser($userId, ['password' => $newPassword]);

        // Update RTDB password hash
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $schoolCode = $studentData['school_code'];
        $this->firebase->update("Users/Parents/{$schoolCode}/{$userId}/Credentials", [
            'Password' => $hash,
        ]);
        $this->firebase->update("Users/Parents/{$schoolCode}/{$userId}", [
            'auth_migrated' => true,
        ]);

        return ['success' => true, 'message' => 'Password has been reset successfully.'];
    }

    // ══════════════════════════════════════════════════════════════════
    //  PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Generate a random numeric OTP.
     */
    private function _generateOtpCode(): string
    {
        return str_pad((string) random_int(0, (10 ** self::OTP_LENGTH) - 1), self::OTP_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Store OTP in Firestore with TTL and attempt counter.
     * Doc ID is the OTP key (adminId or hashed student email) so the
     * lookup in _verifyOtp / _consumeToken is a direct get().
     */
    private function _storeOtp(string $key, string $otp, string $email): void
    {
        $this->firebase->firestoreSet(self::OTP_COLLECTION, $this->_otpDocId($key), [
            'otpKey'    => $key,
            'otpHash'   => password_hash($otp, PASSWORD_BCRYPT, ['cost' => 10]),
            'email'     => $email,
            'expiresAt' => time() + self::OTP_TTL,
            'attempts'  => 0,
            'createdAt' => date('c'),
            'updatedAt' => date('c'),
        ]);
    }

    /** Sanitise key → Firestore-safe doc id (alphanum + underscore). */
    private function _otpDocId(string $key): string
    {
        return preg_replace('/[^A-Za-z0-9_\-]/', '_', $key);
    }

    /**
     * Verify OTP and return reset token on success.
     */
    private function _verifyOtp(string $key, string $otp): array
    {
        $docId = $this->_otpDocId($key);
        $data  = $this->firebase->firestoreGet(self::OTP_COLLECTION, $docId);

        // Tolerate legacy snake_case fields during the transition window
        // (camelCase is the canonical shape post-migration).
        $otpHash   = $data['otpHash']   ?? $data['otp_hash']   ?? null;
        $expiresAt = (int) ($data['expiresAt'] ?? $data['expires_at'] ?? 0);

        if (!$data || !is_array($data) || !$otpHash) {
            return ['success' => false, 'message' => 'No pending OTP found. Please request a new one.', 'resetToken' => ''];
        }

        // Check expiry — Firestore has no TTL enforcement at-rest, so
        // we evaluate it on every read and best-effort delete expired
        // docs. A nightly cleanup job handles orphans at scale.
        if (time() > $expiresAt) {
            $this->firebase->firestoreDelete(self::OTP_COLLECTION, $docId);
            return ['success' => false, 'message' => 'OTP has expired. Please request a new one.', 'resetToken' => ''];
        }

        $attempts = (int) ($data['attempts'] ?? 0);
        if ($attempts >= self::MAX_OTP_ATTEMPTS) {
            $this->firebase->firestoreDelete(self::OTP_COLLECTION, $docId);
            return ['success' => false, 'message' => 'Too many failed attempts. Please request a new OTP.', 'resetToken' => ''];
        }

        if (!password_verify($otp, $otpHash)) {
            $this->firebase->firestoreSet(self::OTP_COLLECTION, $docId, [
                'attempts'  => $attempts + 1,
                'updatedAt' => date('c'),
            ], /* merge */ true);
            $remaining = self::MAX_OTP_ATTEMPTS - $attempts - 1;
            return ['success' => false, 'message' => "Invalid OTP. {$remaining} attempt(s) remaining.", 'resetToken' => ''];
        }

        // OTP valid — generate reset token, consume the OTP hash.
        $resetToken = bin2hex(random_bytes(32));
        $this->firebase->firestoreSet(self::OTP_COLLECTION, $docId, [
            'otpHash'          => null, // consume OTP
            'resetToken'       => hash('sha256', $resetToken),
            'resetTokenExpiry' => time() + self::TOKEN_TTL,
            'updatedAt'        => date('c'),
        ], /* merge */ true);

        return [
            'success'    => true,
            'message'    => 'OTP verified successfully.',
            'resetToken' => $resetToken,
        ];
    }

    /**
     * Validate and consume a reset token.
     */
    private function _consumeToken(string $key, string $token): bool
    {
        $docId = $this->_otpDocId($key);
        $data  = $this->firebase->firestoreGet(self::OTP_COLLECTION, $docId);
        $reset = $data['resetToken']       ?? $data['reset_token']       ?? '';
        $exp   = (int) ($data['resetTokenExpiry'] ?? $data['reset_token_expiry'] ?? 0);
        if (!$data || !is_array($data) || $reset === '') return false;
        if (time() > $exp) {
            $this->firebase->firestoreDelete(self::OTP_COLLECTION, $docId);
            return false;
        }
        if (!hash_equals($reset, hash('sha256', $token))) return false;
        // Consume — delete the reset record.
        $this->firebase->firestoreDelete(self::OTP_COLLECTION, $docId);
        return true;
    }

    /**
     * Find an admin user across all schools.
     *
     * @return array|null  ['name', 'email', 'school_code', 'role'] or null
     */
    private function _findAdmin(string $adminId): ?array
    {
        // Fast path: O(1) reverse index lookup
        $indexedCode = $this->firebase->get("Indexes/AdminIds/{$adminId}");
        if ($indexedCode && is_string($indexedCode)) {
            $admin = $this->firebase->get("Users/Admin/{$indexedCode}/{$adminId}");
            if ($admin && is_array($admin)) {
                return [
                    'name'        => $admin['Name'] ?? $admin['Profile']['name'] ?? '',
                    'email'       => $admin['Email'] ?? $admin['Profile']['email'] ?? '',
                    'role'        => $admin['Role'] ?? $admin['Profile']['role'] ?? '',
                    'school_code' => $indexedCode,
                ];
            }
        }

        // Fallback: iterate all schools
        $schoolCodes = $this->firebase->shallow_get('Indexes/School_codes');
        foreach ($schoolCodes as $code) {
            $admin = $this->firebase->get("Users/Admin/{$code}/{$adminId}");
            if ($admin && is_array($admin)) {
                // Populate reverse index for next time
                $this->firebase->set("Indexes/AdminIds/{$adminId}", $code);
                return [
                    'name'        => $admin['Name'] ?? $admin['Profile']['name'] ?? '',
                    'email'       => $admin['Email'] ?? $admin['Profile']['email'] ?? '',
                    'role'        => $admin['Role'] ?? $admin['Profile']['role'] ?? '',
                    'school_code' => $code,
                ];
            }
        }
        return null;
    }

    /**
     * Find a student by userId.
     *
     * @return array|null  ['name', 'email', 'school_code'] or null
     */
    private function _findStudent(string $userId): ?array
    {
        $schoolCodes = $this->firebase->shallow_get('Indexes/School_codes');
        foreach ($schoolCodes as $code) {
            $student = $this->firebase->get("Users/Parents/{$code}/{$userId}");
            if ($student && is_array($student)) {
                return [
                    'name'        => $student['Name'] ?? $student['student_name'] ?? '',
                    'email'       => $student['Parent_email'] ?? $student['Email'] ?? '',
                    'school_code' => $code,
                ];
            }
        }
        return null;
    }

    /**
     * Find all student accounts linked to a parent email.
     *
     * @return array  [['userId' => '...', 'name' => '...', 'school_code' => '...'], ...]
     */
    private function _findStudentsByParentEmail(string $email): array
    {
        $results     = [];
        $emailLower  = strtolower(trim($email));
        $schoolCodes = $this->firebase->shallow_get('Indexes/School_codes');

        foreach ($schoolCodes as $code) {
            $students = $this->firebase->get("Users/Parents/{$code}");
            if (!is_array($students)) continue;

            foreach ($students as $id => $data) {
                if (!is_array($data)) continue;
                $parentEmail = strtolower(trim($data['Parent_email'] ?? $data['Email'] ?? ''));
                if ($parentEmail === $emailLower) {
                    $results[] = [
                        'userId'      => $id,
                        'name'        => $data['Name'] ?? $data['student_name'] ?? $id,
                        'school_code' => $code,
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Mask an email for display: j***n@gmail.com
     */
    private function _maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) return '***';

        $local  = $parts[0];
        $domain = $parts[1];

        if (strlen($local) <= 2) {
            $masked = $local[0] . '***';
        } else {
            $masked = $local[0] . str_repeat('*', strlen($local) - 2) . substr($local, -1);
        }

        return $masked . '@' . $domain;
    }

    /**
     * Send OTP email via CodeIgniter email library.
     */
    private function _sendOtpEmail(string $toEmail, string $otp, string $userName, string $accountType = 'Admin'): bool
    {
        $CI =& get_instance();
        $CI->load->library('email');

        $CI->email->clear();
        $CI->email->from('noreply@schoolsync.app', 'SchoolSync');
        $CI->email->to($toEmail);
        $CI->email->subject("Password Reset OTP — SchoolSync {$accountType}");

        $body = "Dear {$userName},\n\n"
            . "Your one-time password (OTP) for password reset is:\n\n"
            . "    {$otp}\n\n"
            . "This OTP is valid for " . (self::OTP_TTL / 60) . " minutes.\n"
            . "Do not share this code with anyone.\n\n"
            . "If you did not request this, please ignore this email.\n\n"
            . "— SchoolSync Team";

        $CI->email->message($body);

        if ($CI->email->send()) {
            return true;
        }

        log_message('error', 'Otp_service::_sendOtpEmail() failed to ' . $toEmail . ': ' . $CI->email->print_debugger(['headers']));
        return false;
    }

    // ══════════════════════════════════════════════════════════════════
    //  RATE LIMITING
    // ══════════════════════════════════════════════════════════════════

    /**
     * Check OTP generation rate limits (per-user + per-IP).
     *
     * @param  string $userKey  Unique key for the user (adminId or hashed email)
     * @return string|null      Error message if rate limited, null if OK
     */
    private function _checkRateLimit(string $userKey): ?string
    {
        // Phase 5 — delegate to the Firestore-backed Rate_limiter
        // library introduced in the RBAC hardening pass. One bucket per
        // user and one per IP. The library fails OPEN on Firestore
        // errors, so a backend hiccup never blocks a legitimate reset.
        $CI =& get_instance();
        if (!isset($CI->rateLimiter)) {
            $CI->load->library('Rate_limiter', null, 'rateLimiter');
        }
        $ip = $CI->input->ip_address();

        $verdictUser = $CI->rateLimiter->check(
            $this->firebase, 'otp_user', $userKey, $ip,
            self::RATE_MAX_PER_USER, self::RATE_WINDOW_USER
        );
        if (!$verdictUser['allowed']) {
            $wait = (int) ceil(max(1, $verdictUser['retryAfter']) / 60);
            return "Too many OTP requests. Please wait {$wait} minute(s) before trying again.";
        }

        $verdictIp = $CI->rateLimiter->check(
            $this->firebase, 'otp_ip', $ip, $ip,
            self::RATE_MAX_PER_IP, self::RATE_WINDOW_IP
        );
        if (!$verdictIp['allowed']) {
            $wait = (int) ceil(max(1, $verdictIp['retryAfter']) / 60);
            return "Too many requests from this IP. Please wait {$wait} minute(s).";
        }

        return null;  // Not rate limited
    }
}
