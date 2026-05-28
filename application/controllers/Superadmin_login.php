<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Superadmin_login
 *
 * Authenticates against the existing admin credential store:
 *   Users/Admin/{school_id}/{admin_id}
 *
 * Access is granted only when Role === 'Super Admin'.
 * Rate limiting stored in Firebase at RateLimit/SA/{ip} (no MySQL needed).
 *
 * AUTH-C2 (2026-05-27): per-account lockout state in Firestore
 *   superadminAuthState/SA_{adminId} — 5 attempts / 30 min sliding window,
 *   30 min lock on trip. Tracks ALL submitted adminIds (including unknown
 *   ones) to defeat enumeration via lock-vs-no-lock signal.
 * AUTH-C5 (2026-05-27): SA login events emitted via Security_telemetry
 *   (security_events collection) — SA_LOGIN_SUCCESS / _FAILED / _LOCKED /
 *   _ROLE_REJECTED. Synthetic schoolId 'SA_PANEL' since SA login is
 *   pre-school-context.
 * AUTH-M5 (deferred): IP rate-limit still uses RTDB RateLimit/SA/{ip} —
 *   migration to Firestore is a separate hardening stream.
 */
class Superadmin_login extends CI_Controller
{
    private const DUMMY_HASH    = '$2y$10$usesomesillystringfore2uDLvp1Ii2e./U9C8sBjqp8I/p7';
    private const MAX_ID_LEN    = 32;
    private const MAX_PW_LEN    = 72;
    private const IP_MAX_FAILS  = 10;
    private const IP_WINDOW_SEC = 1800; // 30 minutes

    // ── AUTH-C2 (2026-05-27): per-account lockout policy (mirrors Admin_login) ──
    private const ACCT_MAX_FAILS  = 5;     // lock after 5 failures
    private const ACCT_WINDOW_SEC = 1800;  // 30 min sliding window
    private const ACCT_LOCK_SEC   = 1800;  // 30 min lock duration
    private const FS_AUTH_STATE   = 'superadminAuthState';

    public function __construct()
    {
        parent::__construct();
        $this->load->library(['session', 'firebase']);

        // ── AUTH-C5 (2026-05-27): SA login telemetry ──
        // SA login has no school context until after auth, so we pass the
        // synthetic schoolId 'SA_PANEL' to satisfy Security_telemetry's init
        // contract. Events emitted from this controller are SA-prefixed and
        // easily distinguishable in security_events queries.
        $this->load->library('security_telemetry', null, 'sec_telem');
        $this->sec_telem->init($this->firebase, 'SA_PANEL', [
            'uid'  => '',           // unknown until authenticate() succeeds
            'role' => 'anonymous',
        ], '');

        $this->load->helper('url');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET  /superadmin/csrf_token
    // Returns the correct CSRF token for the current context:
    //   • SA session active  → session-based sa_csrf_token (used by MY_Superadmin_Controller)
    //   • No SA session      → CI3 cookie token (for the login form POST)
    //
    // The SA panel uses session-based CSRF to avoid cookie collision with the
    // school-admin panel.  Test runners must call this endpoint after login
    // (not before) to receive the session token.
    // ─────────────────────────────────────────────────────────────────────────
    public function csrf_token()
    {
        $sa_id = $this->session->userdata('sa_id');

        if (!empty($sa_id)) {
            // SA session active — generate/fetch the session-based CSRF token.
            // Mirrors the same initialisation logic in MY_Superadmin_Controller
            // so calling this endpoint is equivalent to loading any SA page.
            if (!$this->session->userdata('sa_csrf_token')) {
                $this->session->set_userdata('sa_csrf_token', bin2hex(random_bytes(32)));
            }
            $token = $this->session->userdata('sa_csrf_token');
            $name  = 'csrf_token';
        } else {
            // No SA session — return CI3 cookie token for the login form.
            $token = $this->security->get_csrf_hash();
            $name  = $this->security->get_csrf_token_name();
        }

        $this->output
             ->set_content_type('application/json')
             ->set_output(json_encode([
                 'csrf_name'  => $name,
                 'csrf_token' => $token,
             ]));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET  /superadmin/login
    // ─────────────────────────────────────────────────────────────────────────
    public function index()
    {
        if ($this->session->userdata('sa_id')) {
            redirect('superadmin/dashboard');
            return;
        }
        $this->load->view('superadmin/login');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/login/authenticate
    // ─────────────────────────────────────────────────────────────────────────
    public function authenticate()
    {
        if ($this->input->method() !== 'post') {
            redirect('superadmin/login');
            return;
        }

        // Manual CSRF check — CI3's cookie-based CSRF is excluded for all SA
        // routes because the school-admin panel shares the same cookie and
        // overwrites the token, causing 403 on the SA login form.
        $csrfName  = $this->security->get_csrf_token_name();
        $csrfSent  = trim((string) $this->input->post($csrfName));
        $csrfCookie = trim((string) ($this->input->cookie($csrfName) ?? ''));
        // Accept if the submitted token matches EITHER the cookie or the current hash
        $csrfHash  = $this->security->get_csrf_hash();
        if ($csrfSent === '' || ($csrfSent !== $csrfCookie && $csrfSent !== $csrfHash)) {
            $this->_json(['status' => 'error', 'message' => 'Security token expired. Please refresh the page and try again.']);
            return;
        }

        $ip  = $this->input->ip_address();
        $ip  = ($ip === '::1') ? '127.0.0.1' : $ip;
        $now = time();

        // ── Rate limit check (Firebase) ──────────────────────────────────────
        if ($this->_is_ip_blocked($ip, $now)) {
            $this->_json(['status' => 'error', 'message' => 'Too many failed attempts. Try again in 30 minutes.']);
            return;
        }

        // ── Read + validate inputs ───────────────────────────────────────────
        $adminId  = trim((string) $this->input->post('admin_id',  TRUE));
        $password = (string) $this->input->post('password', FALSE);  // R5-SEC-1: bypass XSS filter for passwords

        if ($adminId === '' || $password === '') {
            $this->_json(['status' => 'error', 'message' => 'User ID and Password are required.']);
            return;
        }

        if (strlen($adminId) > self::MAX_ID_LEN || strlen($password) > self::MAX_PW_LEN) {
            $this->_record_fail($ip, $now);
            $this->_json(['status' => 'error', 'message' => 'Invalid credentials.']);
            return;
        }

        // Firebase path injection guard — block dangerous chars (. # $ [ ] /)
        if (preg_match('/[.#$\[\]\/]/', $adminId)) {
            $this->_record_fail($ip, $now);
            $this->_json(['status' => 'error', 'message' => 'Invalid credentials.']);
            return;
        }

        // ── AUTH-C2 per-account lockout check (Firestore) ───────────────────
        // Must come AFTER input validation (regex + length) so a locked-doc
        // is never written for malformed ids. Emits SA_LOGIN_LOCKED telemetry
        // if currently locked; otherwise falls through to credential check.
        if ($this->_is_account_locked($adminId, $now)) {
            $this->sec_telem->emit('SA_LOGIN_LOCKED', 'warning', [
                'admin_id' => $adminId,
                'ip'       => $ip,
                'reason'   => 'lock_active_at_attempt',
            ], ['type' => 'superadmin', 'id' => $adminId]);
            $this->_json(['status' => 'error', 'message' => 'Account temporarily locked. Try again later.']);
            return;
        }

        // ══════════════════════════════════════════════════════════════
        //  PRIMARY: Auth API (MongoDB) — resolves school + role automatically
        // ══════════════════════════════════════════════════════════════
        $this->load->library('auth_client');
        $result = $this->auth_client->web_login($adminId, '', $password, $ip);

        if (!empty($result['unavailable'])) {
            log_message('error', 'SA Login: Auth API unavailable');
            $this->_json(['status' => 'error', 'message' => 'Authentication service is temporarily unavailable. Please try again shortly.']);
            return;
        }

        if (empty($result['success'])) {
            $this->_record_fail($ip, $now);
            $locked = $this->_record_account_fail($adminId, $ip, $now);
            $this->sec_telem->emit(
                $locked ? 'SA_LOGIN_LOCKED' : 'SA_LOGIN_FAILED',
                $locked ? 'warning' : 'info',
                [
                    'admin_id' => $adminId,
                    'ip'       => $ip,
                    'reason'   => 'invalid_credentials',
                    'attempts' => $this->_acct_attempts($adminId),
                ],
                ['type' => 'superadmin', 'id' => $adminId]
            );
            $this->_json(['status' => 'error', 'message' => $result['message'] ?? 'Invalid credentials.']);
            return;
        }

        // ── Extract user data from Auth API response ──────────────────────
        $userData        = $result['user']            ?? [];
        $firebaseProfile = $result['firebaseProfile'] ?? [];
        $mongoRole       = $userData['role']           ?? '';

        // Role check: ONLY project-level super_admin (company employees) can access SA panel
        // school_super_admin users must use the admin panel — they manage ONE school, not all
        $fbRole = $firebaseProfile['Role'] ?? '';
        $isSuperAdmin = ($mongoRole === 'super_admin')
                     || strtolower(trim($fbRole)) === 'super admin';

        if (!$isSuperAdmin) {
            $this->_record_fail($ip, $now);
            $locked = $this->_record_account_fail($adminId, $ip, $now);
            $this->sec_telem->emit(
                $locked ? 'SA_LOGIN_LOCKED' : 'SA_LOGIN_ROLE_REJECTED',
                'warning',
                [
                    'admin_id'     => $adminId,
                    'ip'           => $ip,
                    'mongo_role'   => $mongoRole,
                    'fb_role'      => $fbRole,
                    'attempts'     => $this->_acct_attempts($adminId),
                    'lock_tripped' => $locked,
                ],
                ['type' => 'superadmin', 'id' => $adminId]
            );
            $this->_json(['status' => 'error', 'message' => 'Access denied. Super Admin role required.']);
            return;
        }

        // Account status
        $status = $firebaseProfile['Status'] ?? $userData['status'] ?? 'Active';
        if ($status !== 'Active') {
            $this->_json(['status' => 'error', 'message' => 'Account is inactive. Contact support.']);
            return;
        }

        // ── Success ───────────────────────────────────────────────────────────
        $this->_clear_fail($ip);
        $this->_clear_account_fail($adminId);
        $this->sec_telem->emit('SA_LOGIN_SUCCESS', 'info', [
            'admin_id'      => $adminId,
            'ip'            => $ip,
            'mongo_role'    => $mongoRole,
            'resolved_role' => ($mongoRole === 'super_admin') ? 'developer' : 'superadmin',
        ], ['type' => 'superadmin', 'id' => $adminId]);

        // Clear any school admin session data to prevent session bleed-through
        $this->session->unset_userdata([
            'admin_id', 'school_id', 'school_code', 'admin_role', 'admin_name',
            'session', 'current_session', 'session_year', 'schoolName',
            'school_display_name', 'school_features', 'available_sessions',
            'subscription_expiry', 'subscription_grace_end', 'subscription_warning',
            'sub_check_ts', 'login_csrf',
        ]);

        $this->session->sess_regenerate(TRUE);

        // Resolve name, email, role from API response
        $resolvedName  = (string) ($userData['name']  ?? $firebaseProfile['Name']  ?? $adminId);
        $resolvedEmail = (string) ($userData['email'] ?? $firebaseProfile['Email'] ?? '');
        $resolvedRole  = ($mongoRole === 'super_admin') ? 'developer' : 'superadmin';

        $this->session->set_userdata([
            'sa_id'    => $adminId,
            'sa_name'  => $resolvedName,
            'sa_role'  => $resolvedRole,
            'sa_email' => $resolvedEmail,
        ]);

        // Update Firebase access history (best-effort)
        $schoolCode = $userData['schoolCode'] ?? '';
        $loginCode  = $userData['schoolId']   ?? '';
        try {
            if ($mongoRole === 'super_admin') {
                $this->firebase->update("Users/Admin/Our Panel/{$adminId}", [
                    'SA_LastLogin'   => date('Y-m-d H:i:s'),
                    'SA_LastLoginIP' => $ip,
                ]);
            } elseif ($loginCode) {
                $this->firebase->update("Users/Admin/{$loginCode}/{$adminId}/AccessHistory", [
                    'SA_LastLogin'   => date('Y-m-d H:i:s'),
                    'SA_LastLoginIP' => $ip,
                ]);
            }
        } catch (Exception $e) { /* non-critical */ }

        $this->_json(['status' => 'success', 'redirect' => base_url('superadmin/dashboard')]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET  /superadmin/login/forgot_password
    // ─────────────────────────────────────────────────────────────────────────
    public function forgot_password()
    {
        $this->load->view('superadmin/forgot_password');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/login/send_otp
    // ─────────────────────────────────────────────────────────────────────────
    public function send_otp()
    {
        if ($this->input->method() !== 'post') { redirect('superadmin/login'); return; }

        $adminId = trim((string) $this->input->post('admin_id', TRUE));
        if (empty($adminId)) {
            $this->_json(['status' => 'error', 'message' => 'Admin ID is required.']);
            return;
        }

        $this->load->library('auth_client');
        $result = $this->auth_client->forgot_password($adminId);

        $this->_json([
            'status'       => !empty($result['success']) ? 'success' : 'error',
            'message'      => $result['message'] ?? 'Request failed.',
            'email_masked' => $result['email_masked'] ?? '',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/login/verify_otp
    // ─────────────────────────────────────────────────────────────────────────
    public function verify_otp()
    {
        if ($this->input->method() !== 'post') { redirect('superadmin/login'); return; }

        $adminId = trim((string) $this->input->post('admin_id', TRUE));
        $otp     = trim((string) $this->input->post('otp', TRUE));

        if (empty($adminId) || empty($otp)) {
            $this->_json(['status' => 'error', 'message' => 'Admin ID and OTP are required.']);
            return;
        }

        $this->load->library('auth_client');
        $result = $this->auth_client->verify_otp($adminId, $otp);

        $this->_json([
            'status'      => !empty($result['success']) ? 'success' : 'error',
            'message'     => $result['message'] ?? 'Verification failed.',
            'resetToken'  => $result['resetToken'] ?? '',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/login/reset_password
    // ─────────────────────────────────────────────────────────────────────────
    public function reset_password()
    {
        if ($this->input->method() !== 'post') { redirect('superadmin/login'); return; }

        $adminId      = trim((string) $this->input->post('admin_id', TRUE));
        $resetToken   = trim((string) $this->input->post('reset_token', TRUE));
        $newPassword  = (string) $this->input->post('new_password', FALSE);

        if (empty($adminId) || empty($resetToken) || empty($newPassword)) {
            $this->_json(['status' => 'error', 'message' => 'All fields are required.']);
            return;
        }

        if (strlen($newPassword) < 8) {
            $this->_json(['status' => 'error', 'message' => 'Password must be at least 8 characters.']);
            return;
        }

        $this->load->library('auth_client');
        $result = $this->auth_client->reset_password_otp($adminId, $resetToken, $newPassword);

        $this->_json([
            'status'  => !empty($result['success']) ? 'success' : 'error',
            'message' => $result['message'] ?? 'Password reset failed.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET  /superadmin/login/logout
    // ─────────────────────────────────────────────────────────────────────────
    public function logout()
    {
        $this->session->unset_userdata(['sa_id', 'sa_name', 'sa_role', 'sa_email', 'sa_csrf_token']);
        $this->session->sess_destroy();
        // Prevent browser back-button cache from restoring the session
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        redirect('superadmin/login');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE — Firebase-based rate limiting (no MySQL dependency)
    // ─────────────────────────────────────────────────────────────────────────

    private function _ip_path(string $ip): string
    {
        return 'RateLimit/SA/' . str_replace(['.', ':'], '-', $ip);
    }

    private function _is_ip_blocked(string $ip, int $now): bool
    {
        try {
            $rec = $this->firebase->get($this->_ip_path($ip));
            if (!is_array($rec)) return false;
            if (($now - (int)($rec['windowStart'] ?? 0)) > self::IP_WINDOW_SEC) return false;
            return (int)($rec['fails'] ?? 0) >= self::IP_MAX_FAILS;
        } catch (Exception $e) { return false; }
    }

    private function _record_fail(string $ip, int $now): void
    {
        try {
            $path = $this->_ip_path($ip);
            $rec  = $this->firebase->get($path);
            if (!is_array($rec) || ($now - (int)($rec['windowStart'] ?? 0)) > self::IP_WINDOW_SEC) {
                $this->firebase->update($path, ['windowStart' => $now, 'fails' => 1]);
            } else {
                $this->firebase->update($path, ['fails' => (int)($rec['fails'] ?? 0) + 1]);
            }
        } catch (Exception $e) { /* non-critical */ }
    }

    private function _clear_fail(string $ip): void
    {
        try {
            $this->firebase->update($this->_ip_path($ip), ['fails' => 0, 'windowStart' => 0]);
        } catch (Exception $e) {}
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AUTH-C2 — Firestore-based per-account lockout (NO RTDB)
    // Doc:    superadminAuthState/SA_{adminId}
    // Policy: 5 attempts / 30 min sliding window → 30 min lock
    // Scope:  All submitted adminIds tracked (including unknown ones) to
    //         defeat enumeration via lock-vs-no-lock signal.
    // ─────────────────────────────────────────────────────────────────────────

    private function _acct_doc_id(string $adminId): string
    {
        // adminId already regex-validated against /[.#$\[\]\/]/ in authenticate();
        // safe for Firestore doc-id (alphanumeric + underscore/hyphen only).
        return 'SA_' . $adminId;
    }

    private function _is_account_locked(string $adminId, int $now): bool
    {
        try {
            $doc = $this->firebase->firestoreGet(self::FS_AUTH_STATE, $this->_acct_doc_id($adminId));
            if (!is_array($doc) || empty($doc['lockedUntil'])) return false;
            $lockedUntil = (int) strtotime((string) $doc['lockedUntil']);
            return $lockedUntil > $now;
        } catch (Throwable $e) {
            // Fail-open on read errors — same posture as IP rate-limit reader.
            // Credentials check still gates auth; missing lockout read is not
            // worth blocking legitimate users.
            log_message('error', 'SA _is_account_locked read failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Records one failed attempt against an SA id. Returns TRUE if this
     * increment tripped the lock (so callers emit SA_LOGIN_LOCKED telemetry).
     */
    private function _record_account_fail(string $adminId, string $ip, int $now): bool
    {
        try {
            $docId = $this->_acct_doc_id($adminId);
            $cur   = $this->firebase->firestoreGet(self::FS_AUTH_STATE, $docId);
            $windowStart = (is_array($cur) && (int)($cur['windowStart'] ?? 0) > 0)
                ? (int)$cur['windowStart'] : 0;

            if (!is_array($cur) || ($now - $windowStart) > self::ACCT_WINDOW_SEC) {
                $attempts    = 1;
                $windowStart = $now;
            } else {
                $attempts = (int) ($cur['attempts'] ?? 0) + 1;
            }

            $payload = [
                'adminId'     => $adminId,
                'attempts'    => $attempts,
                'windowStart' => $windowStart,
                'lockedUntil' => null,
                'lastFailIp'  => $ip,
                'lastFailAt'  => date('c', $now),
                'updatedAt'   => date('c', $now),
            ];

            $tripped = false;
            if ($attempts >= self::ACCT_MAX_FAILS) {
                $payload['lockedUntil'] = date('c', $now + self::ACCT_LOCK_SEC);
                $tripped = true;
            }

            $this->firebase->firestoreSet(self::FS_AUTH_STATE, $docId, $payload, false);
            return $tripped;
        } catch (Throwable $e) {
            log_message('error', 'SA _record_account_fail failed: ' . $e->getMessage());
            return false; // fail-open — never block legitimate flow on telemetry write
        }
    }

    private function _clear_account_fail(string $adminId): void
    {
        try {
            $this->firebase->firestoreDelete(self::FS_AUTH_STATE, $this->_acct_doc_id($adminId));
        } catch (Throwable $e) {
            // Non-critical; lock would still expire naturally after ACCT_LOCK_SEC.
            log_message('error', 'SA _clear_account_fail failed: ' . $e->getMessage());
        }
    }

    private function _acct_attempts(string $adminId): int
    {
        try {
            $doc = $this->firebase->firestoreGet(self::FS_AUTH_STATE, $this->_acct_doc_id($adminId));
            return is_array($doc) ? (int) ($doc['attempts'] ?? 0) : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function _json(array $payload, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }
}
