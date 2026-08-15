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
        // ── Wave A (A2): Firebase-Auth-first SA login (flag-gated) ──
        // Credential authority = Firebase Auth. _try_firebase_sa_login()
        // emits success + exits on a valid developer-SA Firebase credential;
        // otherwise it returns and we fall through to the legacy path below.
        $this->config->load('sa_migration_flags', FALSE, TRUE);
        $saFlags = $this->config->item('sa_migration_flags');
        // Firebase Auth is the sole SA credential authority — no MongoDB / Auth API.
        // _try_firebase_sa_login() emits the success response and exits on a valid,
        // active developer-SA Firebase credential. If it returns, login failed.
        $this->_try_firebase_sa_login($adminId, $password, $ip, $now);

        // Reached here means Firebase-Auth login did not succeed (bad credentials,
        // or not an authorized/active developer SA). Record + return a clear message.
        $this->_record_fail($ip, $now);
        $locked = $this->_record_account_fail($adminId, $ip, $now);
        $this->sec_telem->emit(
            $locked ? 'SA_LOGIN_LOCKED' : 'SA_LOGIN_FAILED',
            $locked ? 'warning' : 'info',
            ['admin_id' => $adminId, 'ip' => $ip, 'reason' => 'firebase_auth_rejected', 'attempts' => $this->_acct_attempts($adminId)],
            ['type' => 'superadmin', 'id' => $adminId]
        );
        $this->_json(['status' => 'error', 'message' => $locked
            ? 'Too many failed attempts. Account locked for 30 minutes.'
            : 'Invalid User ID or password.']);
        return;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET  /superadmin/login/forgot_password
    // ─────────────────────────────────────────────────────────────────────────
    public function forgot_password()
    {
        $this->load->view('superadmin/forgot_password');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/login/recovery_contact
    //   Decides how a super admin recovers their password:
    //     • SUP0001 (primary owner) → OTP self-reset (no one above to contact).
    //     • Every other super admin → the recovery contact maintained by
    //       SUP0001 (platformSettings/superAdminRecovery); no OTP.
    // ─────────────────────────────────────────────────────────────────────────
    public function recovery_contact()
    {
        if ($this->input->method() !== 'post') { redirect('superadmin/login'); return; }

        $adminId = strtoupper(trim((string) $this->input->post('admin_id', TRUE)));
        if ($adminId === '') {
            $this->_json(['status' => 'error', 'message' => 'Please enter your Super Admin ID.']);
            return;
        }
        if (!preg_match('/^SUP\d+$/', $adminId)) {
            $this->_json(['status' => 'error', 'message' => 'Enter a valid Super Admin ID (e.g. SUP0001).']);
            return;
        }

        // The account must exist.
        $doc = $this->firebase->firestoreGet('superAdmins', $adminId);
        if (empty($doc) || !is_array($doc)) {
            $this->_json(['status' => 'error', 'message' => 'No super admin found for "' . $adminId . '".']);
            return;
        }

        // Primary owner → real recovery routes.
        //
        // WAS: mode 'otp', which sent the owner into send_otp → Auth_client →
        // POST http://localhost:3000/internal/forgot-password. Nothing listens on
        // that port in production (verified: no process, and AUTH_API_URL is not
        // in .env), so the wizard always failed after the 5s connect timeout with
        // "Failed to send OTP." The primary owner therefore had NO working
        // recovery at all — and the UI actively hid that fact behind a flow that
        // looked like it should work.
        //
        // Reviving the Node backend to serve this one path would contradict the
        // project's deliberate no-self-service-OTP model, so point at the two
        // routes that genuinely work instead:
        //   1. Any other active super admin can reset this account from
        //      Super Admins → Reset password (Superadmin_admins::reset_password
        //      carries no isPrimary guard, unlike toggle_status/delete).
        //   2. Break-glass on the server: `php index.php sa_recover reset_password
        //      SUP0001` (CLI only, not web-routable — see Sa_recover.php).
        if (!empty($doc['isPrimary']) || $adminId === 'SUP0001') {
            // found:false deliberately — the view renders r.message in a styled
            // alert for that branch, whereas found:true calls renderCard() and
            // would paint an empty name/phone/email card. Reusing the existing
            // branch means no view change is needed.
            $this->_json([
                'status'  => 'success', 'mode' => 'contact', 'found' => false,
                'message' => 'Self-service reset is not available for the primary super admin. '
                           . 'Ask another super admin to reset it from Super Admins → Reset '
                           . 'password. If no other super admin is reachable, an operator can '
                           . 'recover this account on the server using the break-glass command.',
            ]);
            return;
        }

        // Other super admins → contact card maintained by SUP0001.
        $block  = (array) ($this->firebase->firestoreGet('platformSettings', 'superAdminRecovery') ?? []);
        $name   = trim((string) ($block['name']   ?? ''));
        $email  = trim((string) ($block['email']  ?? ''));
        $number = trim((string) ($block['number'] ?? ''));

        if ($name === '' && $email === '' && $number === '') {
            $this->_json(['status' => 'success', 'mode' => 'contact', 'found' => false,
                'message' => 'The recovery contact has not been set up yet. Please reach the primary super admin (SUP0001).']);
            return;
        }
        $this->_json([
            'status'   => 'success', 'mode' => 'contact', 'found' => true,
            'title'    => 'ZenXii Super Admin',
            'subtitle' => 'Contact the primary super admin to reset your password',
            'name'     => $name, 'number' => $number, 'email' => $email,
        ]);
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

        // Only super admins use this OTP reset (the SA login is SUP-only).
        $adminId = strtoupper($adminId);
        if (!preg_match('/^SUP\d+$/', $adminId)) {
            $this->_json(['status' => 'error', 'message' => 'Invalid account.']);
            return;
        }

        // Step 1: validate the OTP reset token (Auth API / Mongo holds the token).
        $this->load->library('auth_client');
        $result = $this->auth_client->reset_password_otp($adminId, $resetToken, $newPassword);
        if (empty($result['success'])) {
            $this->_json(['status' => 'error', 'message' => $result['message'] ?? 'Password reset failed.']);
            return;
        }

        // Step 2: write the new password to the ACTUAL SA credential authority —
        // Firebase Auth. The Auth API/Mongo/RTDB write above is legacy bookkeeping
        // that the SA login does NOT read, so without this the old password keeps
        // working and the new one never takes effect. (uid == admin id.)
        $updated = $this->firebase->updateFirebaseUser($adminId, ['password' => $newPassword]);
        if ($updated === null) {
            $this->_json(['status' => 'error',
                'message' => 'Could not update your login credential. Please try again or contact support.']);
            return;
        }

        // End every existing session created with the old password.
        try { $this->firebase->revokeRefreshTokens($adminId); } catch (\Throwable $e) {}

        $this->_json([
            'status'  => 'success',
            'message' => 'Password reset successfully. You can now log in with your new password.',
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

    // ─────────────────────────────────────────────────────────────────────────
    // Wave A (A2) — Firebase-Auth-first developer-SA login.
    // Credential authority: Firebase Auth (signInWithEmail). Authorization:
    // RTDB Users/Admin/Our Panel/{id} Role=='Super Admin' (Firestore
    // superAdmins backfill lands in Phase A1). On a valid SA credential this
    // emits the success JSON and exits; otherwise it returns so the caller
    // falls through to the legacy Auth API path.
    // ─────────────────────────────────────────────────────────────────────────
    private function _try_firebase_sa_login(string $adminId, string $password, string $ip, int $now): void
    {
        $email  = Firebase::authEmail($adminId);
        $signIn = $this->firebase->signInWithEmail($email, $password);
        if ($signIn === null) {
            return; // not a valid Firebase-Auth credential — fall through
        }
        $signedUid = $this->_signin_uid($signIn, $adminId);

        // ── Authorization read (A2.1 dual-read when sa.authz_firestore=ON) ──
        // Credential already verified above; this only resolves role/status +
        // the session display fields. Firestore-first with RTDB fallback.
        $saFlags = $this->config->item('sa_migration_flags');
        $useFs   = is_array($saFlags) && !empty($saFlags['sa.authz_firestore']);

        $authz = $this->_resolve_sa_authz($adminId, $signedUid, $useFs, $ip);
        if ($authz === null) {
            return; // authenticated but not a developer SA — fall through to legacy
        }
        if ($authz['allow'] !== true) {
            $this->_json(['status' => 'error', 'message' => 'Account is inactive. Contact support.']);
            return;
        }

        // Success — establish SA session (mirrors the legacy success path).
        $this->_clear_fail($ip);
        $this->_clear_account_fail($adminId);
        $this->sec_telem->emit('SA_LOGIN_SUCCESS', 'info', [
            'admin_id'      => $adminId,
            'ip'            => $ip,
            'auth_source'   => 'firebase',
            'authz_source'  => $authz['source'],
            'resolved_role' => 'developer',
        ], ['type' => 'superadmin', 'id' => $adminId]);

        $this->session->unset_userdata([
            'admin_id', 'school_id', 'school_code', 'admin_role', 'admin_name',
            'session', 'current_session', 'session_year', 'schoolName',
            'school_display_name', 'school_features', 'available_sessions',
            'subscription_expiry', 'subscription_grace_end', 'subscription_warning',
            'sub_check_ts', 'login_csrf',
        ]);
        $this->session->sess_regenerate(TRUE);

        $this->session->set_userdata([
            'sa_id'    => $adminId,
            'sa_name'  => $authz['name'] !== '' ? $authz['name'] : $adminId,
            'sa_role'  => 'developer',
            'sa_email' => $authz['email'],
            // Forced set-new-password gate seed. Read from the superAdmins doc
            // (RTDB fallback); MY_Superadmin_Controller redirects to
            // superadmin/change_my_password before any SA page loads.
            'sa_must_change_password' => $this->_sa_must_change_flag($adminId, $useFs),
        ]);

        $this->_write_sa_access_history($adminId, $ip, $useFs);

        $this->_json(['status' => 'success', 'redirect' => base_url('superadmin/dashboard')]);
    }

    /** Extract the authenticated uid from the Kreait SignInResult (fallback: adminId). */
    private function _signin_uid($signIn, string $adminId): string
    {
        try {
            if (is_object($signIn) && method_exists($signIn, 'data')) {
                $d = $signIn->data();
                if (is_array($d) && !empty($d['localId'])) return (string) $d['localId'];
            }
        } catch (\Throwable $e) { /* fall through to default */ }
        return $adminId;
    }

    /**
     * Read the forced-change-password flag for a developer SA at login.
     * Firestore superAdmins doc first (when sa.authz_firestore is ON), RTDB
     * `Users/Admin/Our Panel/{id}` as fallback. Defaults to false on any read
     * error so a transient outage can never lock an SA into the change screen.
     */
    private function _sa_must_change_flag(string $adminId, bool $useFs): bool
    {
        if ($useFs) {
            try {
                $doc = $this->firebase->firestoreGet('superAdmins', $adminId);
                if (is_array($doc)) return !empty($doc['mustChangePassword']);
            } catch (\Throwable $e) {
                log_message('error', "SA must-change read failed for {$adminId}: " . $e->getMessage());
                return false;
            }
        }
        $rec = $this->firebase->get("Users/Admin/Our Panel/{$adminId}");
        return is_array($rec) && !empty($rec['mustChangePassword']);
    }

    /**
     * Resolve SA login authorization (A2.1 dual-read).
     * When $useFs: read Firestore superAdmins first; on doc-missing OR read
     * error fall back to RTDB Users/Admin/Our Panel. Emits SA_AUTHZ_PATH /
     * SA_AUTHZ_DIVERGENCE / SA_AUTHZ_DOC_MISSING. Returns null = "not a
     * developer SA, fall through to legacy"; else ['allow','reason','name','email','source'].
     */
    private function _resolve_sa_authz(string $adminId, string $signedUid, bool $useFs, string $ip): ?array
    {
        $saFlags = $this->config->item('sa_migration_flags');
        $strict  = is_array($saFlags) && !empty($saFlags['sa.authz_firestore_strict']);

        // ── A2.3 strict: Firestore ONLY — no RTDB read, no fallback ──
        if ($useFs && $strict) {
            $fsDoc = null;
            try { $fsDoc = $this->firebase->firestoreGet('superAdmins', $adminId); }
            catch (\Throwable $e) { log_message('error', "SA authz firestore read failed for {$adminId}: " . $e->getMessage()); }
            $this->sec_telem->emit('SA_AUTHZ_PATH', 'info', [
                'admin_id' => $adminId, 'ip' => $ip, 'path' => 'firestore',
                'reason' => is_array($fsDoc) ? 'doc' : 'no_doc', 'mode' => 'strict',
            ], ['type' => 'superadmin', 'id' => $adminId]);
            if (!is_array($fsDoc)) return null;
            $fs = $this->_decide_from_fs($fsDoc, $signedUid);
            if (!$fs['present'] || $fs['reason'] === 'role_mismatch' || $fs['reason'] === 'uid_mismatch') return null;
            return ['allow' => $fs['allow'], 'reason' => $fs['reason'], 'name' => $fs['name'], 'email' => $fs['email'], 'source' => 'firestore'];
        }

        // RTDB decision (also the dual-read parity baseline).
        $rtdb = $this->_decide_from_rtdb($this->firebase->get("Users/Admin/Our Panel/{$adminId}"));

        if (!$useFs) {
            if (!$rtdb['present'] || $rtdb['reason'] === 'role_mismatch') return null;
            return ['allow' => $rtdb['allow'], 'reason' => $rtdb['reason'], 'name' => $rtdb['name'], 'email' => $rtdb['email'], 'source' => 'rtdb'];
        }

        // Firestore-first.
        $fsDoc = null; $fsErr = false;
        try {
            $fsDoc = $this->firebase->firestoreGet('superAdmins', $adminId);
        } catch (\Throwable $e) {
            $fsErr = true;
            log_message('error', "SA authz firestore read failed for {$adminId}: " . $e->getMessage());
        }

        if (is_array($fsDoc)) {
            $fs = $this->_decide_from_fs($fsDoc, $signedUid);
            if ($rtdb['present'] && $fs['present'] && $rtdb['allow'] !== $fs['allow']) {
                $this->sec_telem->emit('SA_AUTHZ_DIVERGENCE', 'warning', [
                    'admin_id' => $adminId, 'ip' => $ip,
                    'rtdb_allow' => $rtdb['allow'], 'fs_allow' => $fs['allow'],
                    'rtdb_reason' => $rtdb['reason'], 'fs_reason' => $fs['reason'],
                ], ['type' => 'superadmin', 'id' => $adminId]);
            }
            $this->sec_telem->emit('SA_AUTHZ_PATH', 'info', [
                'admin_id' => $adminId, 'ip' => $ip, 'path' => 'firestore', 'reason' => $fs['reason'],
            ], ['type' => 'superadmin', 'id' => $adminId]);

            if (!$fs['present'] || $fs['reason'] === 'role_mismatch' || $fs['reason'] === 'uid_mismatch') return null;
            return ['allow' => $fs['allow'], 'reason' => $fs['reason'], 'name' => $fs['name'], 'email' => $fs['email'], 'source' => 'firestore'];
        }

        // Firestore doc missing OR read error → RTDB fallback.
        if (!$fsErr) {
            $this->sec_telem->emit('SA_AUTHZ_DOC_MISSING', 'warning', [
                'admin_id' => $adminId, 'ip' => $ip,
            ], ['type' => 'superadmin', 'id' => $adminId]);
        }
        $this->sec_telem->emit('SA_AUTHZ_PATH', 'warning', [
            'admin_id' => $adminId, 'ip' => $ip, 'path' => 'rtdb_fallback',
            'reason' => $fsErr ? 'firestore_unavailable' : 'doc_missing',
        ], ['type' => 'superadmin', 'id' => $adminId]);

        if (!$rtdb['present'] || $rtdb['reason'] === 'role_mismatch') return null;
        return ['allow' => $rtdb['allow'], 'reason' => $rtdb['reason'], 'name' => $rtdb['name'], 'email' => $rtdb['email'], 'source' => 'rtdb_fallback'];
    }

    /** Normalise the RTDB Our Panel record into an authorization decision. */
    private function _decide_from_rtdb($rec): array
    {
        if (!is_array($rec)) return ['present' => false, 'allow' => false, 'reason' => 'not_found', 'name' => '', 'email' => ''];
        $name  = (string) ($rec['Name'] ?? ($rec['Profile']['name'] ?? ''));
        $email = (string) ($rec['Email'] ?? ($rec['Profile']['email'] ?? ''));
        if (strcasecmp(trim((string) ($rec['Role'] ?? '')), 'Super Admin') !== 0) {
            return ['present' => true, 'allow' => false, 'reason' => 'role_mismatch', 'name' => $name, 'email' => $email];
        }
        if (strcasecmp(trim((string) ($rec['Status'] ?? 'Active')), 'Inactive') === 0) {
            return ['present' => true, 'allow' => false, 'reason' => 'inactive', 'name' => $name, 'email' => $email];
        }
        return ['present' => true, 'allow' => true, 'reason' => 'ok', 'name' => $name, 'email' => $email];
    }

    /** Normalise the Firestore superAdmins doc into an authorization decision. */
    private function _decide_from_fs(array $doc, string $signedUid): array
    {
        $name  = (string) ($doc['name'] ?? '');
        $email = (string) ($doc['email'] ?? '');
        // Defense-in-depth: doc must belong to the account that just signed in.
        $uid = (string) ($doc['firebaseUid'] ?? '');
        if ($uid !== '' && $signedUid !== '' && $uid !== $signedUid) {
            return ['present' => true, 'allow' => false, 'reason' => 'uid_mismatch', 'name' => $name, 'email' => $email];
        }
        if (strcasecmp(trim((string) ($doc['role'] ?? '')), 'super_admin') !== 0) {
            return ['present' => true, 'allow' => false, 'reason' => 'role_mismatch', 'name' => $name, 'email' => $email];
        }
        if (strcasecmp(trim((string) ($doc['status'] ?? 'Active')), 'Inactive') === 0) {
            return ['present' => true, 'allow' => false, 'reason' => 'inactive', 'name' => $name, 'email' => $email];
        }
        return ['present' => true, 'allow' => true, 'reason' => 'ok', 'name' => $name, 'email' => $email];
    }

    /** Access-history write. Dual-write (Firestore + RTDB) during A2.1; RTDB-only when flag OFF. */
    private function _write_sa_access_history(string $adminId, string $ip, bool $useFs): void
    {
        $saFlags = $this->config->item('sa_migration_flags');
        $strict  = is_array($saFlags) && !empty($saFlags['sa.authz_firestore_strict']);
        if ($useFs) {
            try {
                $this->firebase->firestoreSet('superAdmins', $adminId, [
                    'accessHistory' => ['lastLogin' => date('c'), 'lastLoginIp' => $ip],
                ], true);
            } catch (\Throwable $e) { log_message('error', "SA access-history FS write failed for {$adminId}: " . $e->getMessage()); }
        }
        if (!$strict) {
            try {
                $this->firebase->update("Users/Admin/Our Panel/{$adminId}", [
                    'SA_LastLogin'   => date('Y-m-d H:i:s'),
                    'SA_LastLoginIP' => $ip,
                ]);
            } catch (Exception $e) { /* non-critical */ }
        }
    }

    /** Sanitised IP key (Firestore doc-id or RTDB child). */
    private function _ip_key(string $ip): string
    {
        return str_replace(['.', ':'], '-', $ip);
    }

    /** SA IP rate-limit state in Firestore (saIpRateLimit) vs legacy RTDB (RateLimit/SA). */
    private function _ratelimit_firestore(): bool
    {
        $f = $this->config->item('sa_migration_flags');
        return is_array($f) && !empty($f['sa.ratelimit_firestore']);
    }

    private function _rl_get(string $key)
    {
        return $this->_ratelimit_firestore()
            ? $this->firebase->firestoreGet('saIpRateLimit', $key)
            : $this->firebase->get('RateLimit/SA/' . $key);
    }

    private function _rl_put(string $key, array $payload): void
    {
        if ($this->_ratelimit_firestore()) {
            $this->firebase->firestoreSet('saIpRateLimit', $key, $payload, true);
        } else {
            $this->firebase->update('RateLimit/SA/' . $key, $payload);
        }
    }

    private function _is_ip_blocked(string $ip, int $now): bool
    {
        try {
            $rec = $this->_rl_get($this->_ip_key($ip));
            if (!is_array($rec)) return false;
            if (($now - (int)($rec['windowStart'] ?? 0)) > self::IP_WINDOW_SEC) return false;
            return (int)($rec['fails'] ?? 0) >= self::IP_MAX_FAILS;
        } catch (\Throwable $e) { return false; }
    }

    private function _record_fail(string $ip, int $now): void
    {
        try {
            $key = $this->_ip_key($ip);
            $rec = $this->_rl_get($key);
            if (!is_array($rec) || ($now - (int)($rec['windowStart'] ?? 0)) > self::IP_WINDOW_SEC) {
                $this->_rl_put($key, ['windowStart' => $now, 'fails' => 1]);
            } else {
                $this->_rl_put($key, ['windowStart' => (int)($rec['windowStart'] ?? $now), 'fails' => (int)($rec['fails'] ?? 0) + 1]);
            }
        } catch (\Throwable $e) { /* non-critical */ }
    }

    private function _clear_fail(string $ip): void
    {
        try {
            $this->_rl_put($this->_ip_key($ip), ['fails' => 0, 'windowStart' => 0]);
        } catch (\Throwable $e) {}
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
