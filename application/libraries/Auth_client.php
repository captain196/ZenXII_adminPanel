<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Auth_client — cURL wrapper for the unified Auth API.
 *
 * Server-to-server calls from PHP to the Node.js Auth API.
 * All /internal/* routes require the X-Internal-Key header.
 *
 * Usage:
 *   $this->load->library('auth_client');
 *   $result = $this->auth_client->web_login($admin_id, '', $password, $ip);
 */
class Auth_client
{
    /** @var string Auth API base URL (no trailing slash) */
    private $base_url;

    /** @var string Pre-shared internal API key */
    private $internal_key;

    /** @var int Total request timeout in seconds */
    private $timeout;

    /** @var int Connection timeout in seconds */
    private $connect_timeout;

    public function __construct()
    {
        $CI =& get_instance();
        $CI->config->load('auth_api', TRUE);

        $this->base_url        = rtrim($CI->config->item('auth_api_base_url', 'auth_api'), '/');
        $this->internal_key    = $CI->config->item('auth_api_internal_key', 'auth_api');
        $this->timeout         = (int) $CI->config->item('auth_api_timeout', 'auth_api') ?: 10;
        $this->connect_timeout = (int) $CI->config->item('auth_api_connect_timeout', 'auth_api') ?: 5;
    }

    // ─────────────────────────────────────────────────────────────
    //  PUBLIC METHODS
    // ─────────────────────────────────────────────────────────────

    /**
     * Web login — called by Admin_login::check_credentials().
     * School code is resolved automatically from MongoDB by adminId.
     *
     * @param  string $admin_id    Login ID (e.g. SSA0001, ADM0001)
     * @param  string $school_code Deprecated — ignored (resolved from MongoDB)
     * @param  string $password    Raw password (never logged)
     * @param  string $ip          Client IP for rate-limit context
     * @return array  ['success' => bool, ...] — on success includes 'user', 'subscription', 'sessions', 'displayName'
     */
    public function web_login(string $admin_id, string $school_code, string $password, string $ip): array
    {
        return $this->_post('/internal/web-login', [
            'adminId'  => $admin_id,
            'password' => $password,
            'ip'       => $ip,
        ]);
    }

    /**
     * Sync an admin record to MongoDB (create or update).
     * Called by AdminUsers after Firebase write. Best-effort — failure is logged, not fatal.
     */
    public function sync_admin(array $data): array
    {
        return $this->_post('/internal/sync-admin', $data);
    }

    /**
     * Delete an admin from MongoDB.
     * For super_admin role, pass $school_code = '' and $role = 'super_admin'.
     */
    public function delete_admin(string $admin_id, string $school_code, string $role = ''): array
    {
        $payload = ['adminId' => $admin_id, 'schoolCode' => $school_code];
        if ($role !== '') {
            $payload['role'] = $role;
        }
        return $this->_post('/internal/delete-admin', $payload);
    }

    /**
     * Reset admin password in MongoDB.
     * Also increments tokenVersion to invalidate all mobile sessions.
     * For super_admin role, pass $school_code = '' and $role = 'super_admin'.
     */
    public function reset_password(string $admin_id, string $school_code, string $password_hash, string $role = ''): array
    {
        $payload = [
            'adminId'      => $admin_id,
            'schoolCode'   => $school_code,
            'passwordHash' => $password_hash,
        ];
        if ($role !== '') {
            $payload['role'] = $role;
        }
        return $this->_post('/internal/reset-password', $payload);
    }

    /**
     * Generate a sequential ID via the Auth API (race-safe).
     * @param string $prefix  "SCHCODE", "SSA", "ADM", etc.
     * @return string|null  The generated ID, or null on failure.
     */
    public function generate_id(string $prefix): ?string
    {
        $result = $this->_post('/internal/generate-id', ['prefix' => $prefix]);
        return $result['id'] ?? null;
    }

    /**
     * Forgot password — sends OTP to registered email.
     */
    public function forgot_password(string $admin_id): array
    {
        return $this->_post('/internal/forgot-password', ['adminId' => $admin_id]);
    }

    /**
     * Verify OTP — returns reset token if valid.
     */
    public function verify_otp(string $admin_id, string $otp): array
    {
        return $this->_post('/internal/verify-otp', ['adminId' => $admin_id, 'otp' => $otp]);
    }

    /**
     * Reset password with OTP reset token.
     */
    public function reset_password_otp(string $admin_id, string $reset_token, string $new_password): array
    {
        return $this->_post('/internal/reset-password-otp', [
            'adminId'     => $admin_id,
            'resetToken'  => $reset_token,
            'newPassword' => $new_password,
        ]);
    }

    /**
     * Student forgot password — finds all student accounts by email, sends one OTP.
     * Returns list of associated accounts for parent to select from.
     */
    public function forgot_password_student(string $email): array
    {
        return $this->_post('/internal/forgot-password-student', ['email' => $email]);
    }

    /**
     * Student verify OTP — parent selects which account to reset.
     */
    public function verify_otp_student(string $email, string $otp, string $user_id): array
    {
        return $this->_post('/internal/verify-otp-student', [
            'email'  => $email,
            'otp'    => $otp,
            'userId' => $user_id,
        ]);
    }

    /**
     * Student reset password — resets password for the selected student account.
     */
    public function reset_password_student(string $user_id, string $reset_token, string $new_password): array
    {
        return $this->_post('/internal/reset-password-student', [
            'userId'      => $user_id,
            'resetToken'  => $reset_token,
            'newPassword' => $new_password,
        ]);
    }

    /**
     * Mobile app login with device binding (for teacher/student apps).
     * Replaces email-based login with userId-based + device verification.
     *
     * @param  string $user_id   TEA0001, STU0001, etc.
     * @param  string $password  Raw password
     * @param  array  $device    ['deviceId'=>..., 'deviceName'=>..., 'platform'=>..., 'os'=>..., 'appVersion'=>...]
     * @return array  On success: user, firebaseProfile, schoolInfo, accessToken, refreshToken, deviceBound
     *                On DEVICE_NOT_BOUND: code='DEVICE_NOT_BOUND', parentPhoneMasked, emailMasked
     */
    public function mobile_app_login(string $user_id, string $password, array $device): array
    {
        return $this->_post('/internal/mobile-app-login', [
            'userId'   => $user_id,
            'password' => $password,
            'device'   => $device,
        ]);
    }

    /**
     * Request OTP for new device binding (sent to user's email).
     */
    public function request_device_otp(string $user_id): array
    {
        return $this->_post('/internal/request-device-otp', ['userId' => $user_id]);
    }

    /**
     * Verify OTP and bind new device + auto-login.
     */
    public function verify_device_otp(string $user_id, string $otp, array $device): array
    {
        return $this->_post('/internal/verify-device-otp', [
            'userId' => $user_id,
            'otp'    => $otp,
            'device' => $device,
        ]);
    }

    /**
     * Sync a student record to MongoDB (create or update).
     * Called by Sis.php after Firebase write. Best-effort — failure is logged, not fatal.
     *
     * @param array $data  Keys: studentId, name, email, phone, password, schoolId (login code),
     *                     schoolCode (Firebase key), parentDbKey, parentPhone, createdBy,
     *                     className, section, rollNo, fatherName, motherName, dob, admissionDate,
     *                     gender, profilePic, schoolDisplayName, deviceBindingMethod ("otp"|"auto")
     */
    public function sync_student(array $data): array
    {
        return $this->_post('/internal/sync-student', $data);
    }

    /**
     * Delete a student from MongoDB.
     */
    public function delete_student(string $student_id): array
    {
        return $this->_post('/internal/delete-admin', ['adminId' => $student_id]);
    }

    /**
     * Bind a device to a user account (teacher/student mobile auth).
     */
    public function bind_device(string $user_id, string $device_id, array $meta = []): array
    {
        return $this->_post('/internal/bind-device', array_merge([
            'userId'   => $user_id,
            'deviceId' => $device_id,
        ], $meta));
    }

    /**
     * Verify if a device is bound to a user.
     */
    public function verify_device(string $user_id, string $device_id): array
    {
        return $this->_post('/internal/verify-device', [
            'userId'   => $user_id,
            'deviceId' => $device_id,
        ]);
    }

    /**
     * Get list of devices bound to a student/teacher account.
     */
    public function list_devices(string $user_id): array
    {
        return $this->_post('/internal/list-devices', ['userId' => $user_id]);
    }

    /**
     * Remove a bound device from a student/teacher account.
     */
    public function remove_device(string $user_id, string $device_id): array
    {
        return $this->_post('/internal/remove-device', ['userId' => $user_id, 'deviceId' => $device_id]);
    }

    /**
     * Block a device (e.g. stolen/compromised).
     */
    public function block_device(string $user_id, string $device_id): array
    {
        return $this->_post('/internal/block-device', ['userId' => $user_id, 'deviceId' => $device_id]);
    }

    /**
     * Health check — returns true if Auth API is reachable.
     */
    public function health_check(): bool
    {
        $result = $this->_get('/internal/health');
        return !empty($result['success']);
    }

    // ─────────────────────────────────────────────────────────────
    //  PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────

    /**
     * POST JSON to an Auth API endpoint with internal key header.
     */
    private function _post(string $endpoint, array $payload): array
    {
        $url = $this->base_url . $endpoint;
        $ch  = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connect_timeout,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Internal-Key: ' . $this->internal_key,
            ],
            // SSL: verify in production (HTTPS), skip for localhost (HTTP)
            CURLOPT_SSL_VERIFYPEER => (strpos($this->base_url, 'https') === 0),
            CURLOPT_SSL_VERIFYHOST => (strpos($this->base_url, 'https') === 0) ? 2 : 0,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        $errno    = curl_errno($ch);
        curl_close($ch);

        if ($errno) {
            log_message('error', "Auth_client: cURL error [{$errno}] {$error} — endpoint={$endpoint} url={$url}");
            return ['success' => false, 'message' => 'Auth service unavailable', 'unavailable' => true];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            log_message('error', "Auth_client: invalid JSON response — HTTP {$httpCode} endpoint={$endpoint}");
            return ['success' => false, 'message' => 'Invalid auth service response', 'unavailable' => true];
        }

        return $decoded;
    }

    /**
     * GET from an Auth API endpoint with internal key header.
     */
    private function _get(string $endpoint): array
    {
        $url = $this->base_url . $endpoint;
        $ch  = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_HTTPGET        => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connect_timeout,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'X-Internal-Key: ' . $this->internal_key,
            ],
            CURLOPT_SSL_VERIFYPEER => (strpos($this->base_url, 'https') === 0),
            CURLOPT_SSL_VERIFYHOST => (strpos($this->base_url, 'https') === 0) ? 2 : 0,
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        $errno    = curl_errno($ch);
        curl_close($ch);

        if ($errno) {
            log_message('error', "Auth_client: cURL GET error [{$errno}] {$error} — endpoint={$endpoint}");
            return ['success' => false, 'message' => 'Auth service unavailable', 'unavailable' => true];
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : ['success' => false, 'message' => 'Invalid response'];
    }
}
