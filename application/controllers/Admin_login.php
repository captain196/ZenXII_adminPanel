<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Admin_login.php — Production-hardened login controller.
 * Extends CI_Controller (NOT MY_Controller — avoids auth redirect loop).
 *
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║  WAVE C (2026-06-03) — Firebase Auth + Firestore-only cutover    ║
 * ╠══════════════════════════════════════════════════════════════════╣
 * ║  Credential authority   : Firebase Auth (signInWithEmail)        ║
 * ║  Authorization (role)   : Firebase Auth custom claims            ║
 * ║  Authorization (status) : Firestore staff/{schoolId}_{adminId}   ║
 * ║  Lockout state          : Firestore adminAuthState/{adminId}     ║
 * ║  Per-IP rate limit      : Firestore adminIpRateLimit/{ip}        ║
 * ║  Subscription gate      : B2_registry_service::login_access_view ║
 * ║                                                                  ║
 * ║  Removed (legacy paths)                                          ║
 * ║   - Node/Mongo Auth API PRIMARY credential check                  ║
 * ║   - RTDB direct-login fallback helper                            ║
 * ║   - RTDB Users/Admin scan helper                                 ║
 * ║   - RTDB schoolCode->schoolId resolver helper                    ║
 * ║   - RTDB-backed IP rate-limit + lockout helpers                  ║
 * ║   - Logout RTDB AccessHistory write                              ║
 * ║                                                                  ║
 * ║  Out of scope (intentional — separate workstreams)               ║
 * ║   - Password reset: auth_client retained for forgot/verify/reset ║
 * ║   - Student password reset (same)                                ║
 * ║   - Wave D claim-writer centralisation                           ║
 * ╠══════════════════════════════════════════════════════════════════╣
 * ║  SECURITY MEASURES                                               ║
 * ║  [S-01]  POST-only enforcement on check_credentials              ║
 * ║  [S-02]  Input length + format validation                        ║
 * ║  [S-03]  Path injection guard (alphanumeric + - _ only)          ║
 * ║  [S-04]  Generic error messages — no user/school enumeration     ║
 * ║  [S-06]  Per-account lockout (5 fails / 30 min) — Firestore      ║
 * ║  [S-07]  Per-IP rate limit (20 fails / 15 min) — Firestore       ║
 * ║  [S-08]  Password length capped at 72 chars (bcrypt DoS guard)   ║
 * ║  [S-10]  Session fixation prevented — sess_regenerate(TRUE)      ║
 * ║  [S-11]  All session keys cleared on logout                      ║
 * ║  [S-12]  Security + no-cache headers on every response           ║
 * ║  [S-13]  Log injection prevented — inputs sanitised before log   ║
 * ║  [S-14]  Subscription status + date gating at login time         ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */
class Admin_login extends CI_Controller
{
    // ── Input limits (S-02 / S-08) ────────────────────────────────────────
    private const MAX_ADMIN_ID_LEN  = 32;
    private const MAX_PASSWORD_LEN  = 72;  // bcrypt silently ignores beyond 72

    // ── Per-IP rate limit (S-07) — Firestore-backed ──────────────────────
    private const IP_MAX_FAILS  = 20;   // max fails from one IP
    private const IP_WINDOW_SEC = 900;  // 15-minute sliding window

    // ── Wave C: per-account lockout (S-06) — Firestore-canonical ─────────
    private const ACCT_MAX_FAILS      = 5;
    private const ACCT_LOCK_SEC       = 1800;  // 30 min
    private const FS_ADMIN_AUTH_STATE = 'adminAuthState';
    private const FS_ADMIN_IP_RL      = 'adminIpRateLimit';

    // ── Single source of truth for ALL session keys ───────────────────────
    // Must stay in sync with MY_Controller::SESSION_KEYS.
    public const SESSION_KEYS = [
        'admin_id',
        'school_id',              // SCH_XXXXXX (Firestore canonical)
        'school_code',            // numeric login code (back-compat)
        'admin_role',
        'admin_name',
        'session',
        'current_session',
        'session_year',
        'schoolName',
        'school_display_name',
        'school_features',
        'available_sessions',
        'subscription_expiry',
        'subscription_grace_end',
        'subscription_warning',
        'sub_check_ts',
        'login_csrf',
    ];

    // ─────────────────────────────────────────────────────────────────────
    public function __construct()
    {
        parent::__construct();
        $this->load->library('session');
        $this->load->library('firebase');

        // Wave C (2026-06-03): security telemetry for ADMIN_LOGIN_* events.
        // Admin login has no school context until after auth resolves the
        // claim-derived schoolId; use synthetic 'ADMIN_PANEL' to satisfy
        // Security_telemetry's init contract (mirrors Superadmin_login).
        $this->load->library('security_telemetry', null, 'sec_telem');
        $this->sec_telem->init($this->firebase, 'ADMIN_PANEL', [
            'uid'  => '',
            'role' => 'anonymous',
        ], '');

        $this->load->helper('url');

        // [S-12] Security + no-cache headers on every response
        $this->_send_security_headers();

        // Redirect already-authenticated admins away from the login page only
        if (
            $this->session->userdata('admin_id') &&
            $this->router->fetch_class()  === 'admin_login' &&
            $this->router->fetch_method() === 'index'
        ) {
            redirect('admin/index');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  INDEX
    // ─────────────────────────────────────────────────────────────────────
    public function index(): void
    {
        $this->load->view('admin_login');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  CHECK CREDENTIALS — Wave C: Firebase Auth + Firestore-only
    // ─────────────────────────────────────────────────────────────────────
    public function check_credentials(): void
    {
        // [S-01] POST only
        if ($this->input->method() !== 'post') {
            redirect('admin_login');
        }

        $now = time();
        $ip  = $this->_get_real_ip();

        // ── [S-02] Read + length-validate inputs ──────────────────────────
        $rawAdminId  = (string) $this->input->post('admin_id');
        $rawPassword = (string) $this->input->post('password', FALSE);

        if ($rawAdminId === '' || $rawPassword === '') {
            $this->session->set_flashdata('error', 'Admin ID and Password are required.');
            redirect('admin_login');
        }

        if (
            strlen($rawAdminId)  > self::MAX_ADMIN_ID_LEN  ||
            strlen($rawPassword) > self::MAX_PASSWORD_LEN
        ) {
            $this->session->set_flashdata('error', 'Invalid credentials.');
            redirect('admin_login');
        }

        $adminId  = trim($rawAdminId);
        $password = $rawPassword;

        // [S-03] Path injection guard
        if (! $this->_is_safe_id($adminId)) {
            $this->session->set_flashdata('error', 'Invalid credentials.');
            redirect('admin_login');
        }

        // [S-07] Per-IP rate limit (Firestore-canonical)
        if ($this->_admin_ip_blocked($ip, $now)) {
            $this->session->set_flashdata('error', 'Too many login attempts. Please try again later.');
            redirect('admin_login');
        }

        // [S-06] Per-account lockout enforcement (Firestore-canonical).
        // Wave C follow-up (2026-06-03): _record_admin_account_fail writes
        // lockedUntil to adminAuthState/{adminId} after ACCT_MAX_FAILS, but
        // the original cutover (ae605392) did not read/enforce it here.
        // This block blocks the Firebase Auth call while a lockout window
        // is in force, mirroring the IP-rate-limit gate above.
        if ($this->_admin_account_locked($adminId, $now)) {
            $this->sec_telem->emit('ADMIN_LOGIN_LOCKED', 'warning', [
                'admin_id' => $adminId,
                'ip'       => $ip,
                'reason'   => 'still_locked',
            ], ['type' => 'school_admin', 'id' => $adminId]);
            $this->session->set_flashdata('error', 'Account locked. Please try again later.');
            redirect('admin_login');
        }

        // ══════════════════════════════════════════════════════════════
        //  WAVE C: Firebase-Auth-first admin login (sole credential authority).
        //  _try_firebase_admin_login() emits success + redirects on a valid
        //  Firebase Auth credential. Falls through (returns) only on failure.
        // ══════════════════════════════════════════════════════════════
        $this->_try_firebase_admin_login($adminId, $password, $ip, $now);

        // Reached here means Firebase Auth rejected (or claims/status check failed).
        // Record per-IP + per-account fails, emit telemetry, return to login form.
        $this->_record_admin_ip_fail($ip, $now);
        $locked = $this->_record_admin_account_fail($adminId, $ip, $now);

        $this->sec_telem->emit(
            $locked ? 'ADMIN_LOGIN_LOCKED' : 'ADMIN_LOGIN_FAILED',
            $locked ? 'warning' : 'info',
            ['admin_id' => $adminId, 'ip' => $ip, 'reason' => 'firebase_auth_rejected'],
            ['type' => 'school_admin', 'id' => $adminId]
        );

        $this->session->set_flashdata('error', $locked
            ? 'Too many failed attempts. Account locked for 30 minutes.'
            : 'Invalid credentials. Please try again.');
        redirect('admin_login');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  WAVE C — Firebase-Auth-first admin login.
    //  Credential authority: Firebase Auth (signInWithEmail).
    //  Authorization: Firebase Auth custom claims + Firestore staff.status.
    //  Emits ADMIN_LOGIN_SUCCESS + redirects on success; returns on failure.
    // ─────────────────────────────────────────────────────────────────────
    private function _try_firebase_admin_login(string $adminId, string $password, string $ip, int $now): void
    {
        // Step 1: Credential check via Firebase Auth.
        $email  = Firebase::authEmail($adminId);
        $signIn = $this->firebase->signInWithEmail($email, $password);
        if ($signIn === null) {
            return; // Caller falls through to lockout + ADMIN_LOGIN_FAILED telemetry.
        }

        // Step 2: Extract uid + custom claims from sign-in result.
        $uid    = $this->_signin_uid($signIn, $adminId);
        $claims = $this->_signin_claims($signIn, $uid);

        // Wave C admin-claims schema (canonical post-backfill):
        //   role         : machine label (SA) OR display string (staff legacy)
        //   roleLabel    : display string (always populated post-backfill)
        //   schoolId     : Firestore SCH_* key
        //   schoolCode   : numeric login code
        //   parentDbKey  : legacy alias of schoolCode (back-compat — retirement deferred to Wave E)
        // TRANSITIONAL BRIDGE (2026-06-06): accept legacy snake_case claims emitted by
        // pre-camelCase writers (Staff/Ssa_reset/Admin + any historical AdminUsers accounts).
        // RETIRE once all admin-side writers emit camelCase AND legacy claims are backfilled.
        // Tracking: RBAC_CLAIMS_REMEDIATION_PACKAGE.md (C3/C4).
        $schoolId   = (string) ($claims['schoolId']   ?? $claims['school_id']   ?? '');
        $schoolCode = (string) ($claims['schoolCode'] ?? $claims['school_code'] ?? '');
        $roleLabel  = (string) ($claims['roleLabel']  ?? '');
        $roleRaw    = (string) ($claims['role']       ?? '');

        if ($schoolId === '' || $roleRaw === '') {
            $this->sec_telem->emit('ADMIN_LOGIN_AUTHZ_MISSING', 'warning', [
                'admin_id'     => $adminId,
                'ip'           => $ip,
                'has_schoolId' => $schoolId !== '',
                'has_role'     => $roleRaw  !== '',
            ], ['type' => 'school_admin', 'id' => $adminId]);
            return;
        }

        // Step 3: Firestore staff status check (canonical Active/Inactive).
        try {
            $staffDoc = $this->firebase->firestoreGet('staff', $schoolId . '_' . $adminId);
        } catch (\Throwable $e) {
            log_message('error', 'Wave C: staff status read failed for ' . $adminId . ': ' . $e->getMessage());
            $staffDoc = null;
        }
        if (!is_array($staffDoc) || empty($staffDoc)) {
            $this->sec_telem->emit('ADMIN_LOGIN_AUTHZ_MISSING', 'warning', [
                'admin_id' => $adminId,
                'ip'       => $ip,
                'reason'   => 'staff_doc_absent',
                'schoolId' => $schoolId,
            ], ['type' => 'school_admin', 'id' => $adminId]);
            return;
        }
        $rawStatus = (string) ($staffDoc['status'] ?? $staffDoc['Status'] ?? 'Active');
        if (strcasecmp(trim($rawStatus), 'Active') !== 0) {
            $this->sec_telem->emit('ADMIN_LOGIN_AUTHZ_INACTIVE', 'warning', [
                'admin_id' => $adminId,
                'ip'       => $ip,
                'schoolId' => $schoolId,
                'status'   => $rawStatus,
            ], ['type' => 'school_admin', 'id' => $adminId]);
            $this->session->set_flashdata('error', 'Account deactivated. Contact admin.');
            redirect('admin_login');
        }

        // Step 4: Subscription / lifecycle gate (existing B2_registry_service — Firestore-canonical).
        $this->load->library('B2_registry_service');
        $this->b2_registry_service->init($this->firebase);
        $view = $this->b2_registry_service->login_access_view($schoolId, $now);

        if (empty($view['known'])) {
            $this->session->set_flashdata('error', 'Subscription record not found. Please contact support.');
            redirect('admin_login');
        }
        if (empty($view['allowed'])) {
            $this->session->set_flashdata('error', 'Subscription is not active. Please contact support.');
            redirect('admin_login');
        }

        $endTs         = (int) ($view['periodEndTs'] ?? 0);
        $graceEndTs    = (int) ($view['graceEndTs']  ?? 0);
        $endDate       = ($endTs > 0) ? date('Y-m-d', $endTs) : '';
        $daysRemaining = ($endTs > 0) ? (int) ceil(($endTs - $now) / 86400) : 0;
        $subWarning    = ($endTs > 0 && $daysRemaining <= 7)
            ? "Subscription expires in {$daysRemaining} day(s) on {$endDate}. Please renew soon."
            : null;

        // Step 5: Establish session.
        $this->_clear_admin_ip_block($ip);
        $this->_clear_admin_account_fail($adminId, $schoolId);

        // [S-10] Prevent session fixation
        $this->session->sess_regenerate(TRUE);

        // Clear any SA panel session to prevent session bleed-through
        $this->session->unset_userdata(['sa_id', 'sa_name', 'sa_role', 'sa_email', 'sa_csrf_token']);

        $adminName    = (string) ($staffDoc['name'] ?? $staffDoc['Name'] ?? $adminId);
        $displayRole  = $roleLabel !== '' ? $roleLabel : $roleRaw;

        // [S-11] Store session data (mirrors legacy session-shape contract).
        $this->session->set_userdata([
            'admin_id'               => $adminId,
            'school_id'              => $schoolId,         // SCH_XXXXXX (Firestore canonical)
            'school_code'            => $schoolCode,       // numeric login code (back-compat)
            'admin_role'             => $displayRole,
            'admin_name'             => $adminName,
            'subscription_expiry'    => $endTs,
            'subscription_grace_end' => $graceEndTs,
            'subscription_warning'   => $subWarning,
            // Subscription/lifecycle + admin status were JUST validated by
            // login_access_view() above, so seed the throttle timestamps to
            // "now": the next periodic re-check fires on the normal 5-min / 30s
            // cadence instead of redundantly re-running on the very first page
            // load. Security is unchanged — re-checks still run every 5 minutes.
            'sub_check_ts'           => time(),
            'push_check_ts'          => time(),
        ]);

        // Hydrate session-derived fields from schools/{schoolId} Firestore doc.
        $this->_hydrate_admin_session_from_school($schoolId);

        // [RBAC] Permissions are hydrated by MY_Controller on the first
        // authenticated request, where the Firestore `fs` service is loaded.
        // Admin_login extends CI_Controller and does not load `fs`, so hydrating
        // here would no-op (fail-closed []) — deferral keeps login read-free and
        // Firestore-canonical (schools/{schoolId}.roles is the sole authority).

        $this->sec_telem->emit('ADMIN_LOGIN_SUCCESS', 'info', [
            'admin_id'    => $adminId,
            'ip'          => $ip,
            'auth_source' => 'firebase',
            'schoolId'    => $schoolId,
            'role'        => $roleRaw,
        ], ['type' => 'school_admin', 'id' => $adminId]);

        log_message('info',
            'Login OK (firebase-auth) admin=' . $this->_log_safe($adminId)
            . ' school=' . $this->_log_safe($schoolCode)
            . ' schoolId=' . $this->_log_safe($schoolId)
            . ' uid=' . $this->_log_safe($uid)
            . ' ip=' . $ip
        );

        redirect('admin/index');
    }

    /**
     * Extract the authenticated uid from the Kreait SignInResult.
     * Falls back to adminId if the structure is opaque.
     */
    private function _signin_uid($signIn, string $adminId): string
    {
        try {
            if (is_object($signIn) && method_exists($signIn, 'data')) {
                $d = $signIn->data();
                if (is_array($d) && !empty($d['localId'])) {
                    return (string) $d['localId'];
                }
            }
        } catch (\Throwable $e) {
            /* fall through */
        }
        return $adminId;
    }

    /**
     * Extract custom claims from the SignInResult's ID token (JWT payload).
     * Firebase puts custom claims as top-level fields in the JWT payload
     * alongside standard claims (iat, exp, sub, etc.); we decode the JWT
     * and filter out the standard fields to return only the custom claims.
     *
     * If extraction fails for any reason, returns an empty array — the
     * caller will then emit ADMIN_LOGIN_AUTHZ_MISSING and fall through to error.
     */
    private function _signin_claims($signIn, string $uid): array
    {
        if (!is_object($signIn)) return [];

        $idToken = '';
        // Kreait SignInResult exposes idToken() returning string|null.
        if (method_exists($signIn, 'idToken')) {
            try { $idToken = (string) $signIn->idToken(); } catch (\Throwable $e) { /* try fallback */ }
        }
        // Fallback: data() array key 'idToken'.
        if ($idToken === '' && method_exists($signIn, 'data')) {
            try {
                $d = $signIn->data();
                if (is_array($d) && !empty($d['idToken'])) {
                    $idToken = (string) $d['idToken'];
                }
            } catch (\Throwable $e) { /* fall through */ }
        }
        if ($idToken === '') return [];

        // JWT has 3 base64url-encoded segments separated by '.' — payload is segment 2.
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) return [];

        try {
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            if (!is_array($payload)) return [];

            // Filter standard JWT + Firebase reserved claims; keep custom ones.
            static $standard = [
                'iss', 'aud', 'sub', 'iat', 'exp', 'auth_time', 'nbf',
                'firebase', 'email', 'email_verified', 'user_id', 'name', 'picture', 'phone_number',
            ];
            $claims = [];
            foreach ($payload as $k => $v) {
                if (!in_array($k, $standard, true)) {
                    $claims[$k] = $v;
                }
            }
            return $claims;
        } catch (\Throwable $e) {
            log_message('warning', 'Wave C: ID-token claims decode failed for ' . $uid . ': ' . $e->getMessage());
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  WAVE C: Firestore-canonical IP rate limit (replaces RTDB _is_ip_blocked)
    // ─────────────────────────────────────────────────────────────────────
    private function _admin_ip_blocked(string $ip, int $now): bool
    {
        try {
            $doc = $this->firebase->firestoreGet(self::FS_ADMIN_IP_RL, $this->_ip_doc_id($ip));
        } catch (\Throwable $e) {
            return false; // fail-open on Firestore error (don't lock honest users out)
        }
        if (!is_array($doc)) return false;

        $windowStart = (int) ($doc['windowStart'] ?? 0);
        if ($now - $windowStart > self::IP_WINDOW_SEC) return false;

        return (int) ($doc['fails'] ?? 0) >= self::IP_MAX_FAILS;
    }

    private function _record_admin_ip_fail(string $ip, int $now): void
    {
        $docId = $this->_ip_doc_id($ip);
        try {
            $cur = $this->firebase->firestoreGet(self::FS_ADMIN_IP_RL, $docId);
        } catch (\Throwable $e) {
            $cur = null;
        }
        if (!is_array($cur) || ($now - (int) ($cur['windowStart'] ?? 0)) > self::IP_WINDOW_SEC) {
            $payload = ['ip' => $ip, 'windowStart' => $now, 'fails' => 1, 'updatedAt' => date('c', $now)];
        } else {
            $payload = [
                'ip'          => $ip,
                'windowStart' => (int) ($cur['windowStart'] ?? $now),
                'fails'       => (int) ($cur['fails'] ?? 0) + 1,
                'updatedAt'   => date('c', $now),
            ];
        }
        try {
            $this->firebase->firestoreSet(self::FS_ADMIN_IP_RL, $docId, $payload);
        } catch (\Throwable $e) {
            log_message('error', 'Wave C: admin IP rate-limit write failed: ' . $e->getMessage());
        }
    }

    private function _clear_admin_ip_block(string $ip): void
    {
        try {
            $this->firebase->firestoreSet(self::FS_ADMIN_IP_RL, $this->_ip_doc_id($ip), [
                'ip'          => $ip,
                'fails'       => 0,
                'windowStart' => 0,
                'updatedAt'   => date('c'),
            ]);
        } catch (\Throwable $e) {
            /* best-effort cleanup */
        }
    }

    /** Firestore-safe docId for an IP address (replaces dots/colons with hyphens). */
    private function _ip_doc_id(string $ip): string
    {
        return str_replace(['.', ':'], '-', $ip);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  WAVE C: Firestore-canonical per-account lockout (replaces RTDB)
    // ─────────────────────────────────────────────────────────────────────
    /**
     * Per-account lockout check (Wave C follow-up — 2026-06-03).
     *
     * Reads adminAuthState/{adminId}.lockedUntil and returns true if it
     * still resolves to a future timestamp. Used by check_credentials to
     * block Firebase Auth sign-in while a lockout window is in force.
     *
     * Fail-open on Firestore read errors (don't lock honest users out
     * of admin if Firestore is flaky) — mirrors _admin_ip_blocked.
     */
    private function _admin_account_locked(string $adminId, int $now): bool
    {
        try {
            $doc = $this->firebase->firestoreGet(self::FS_ADMIN_AUTH_STATE, $this->_acct_doc_id($adminId));
        } catch (\Throwable $e) {
            return false; // fail-open
        }
        if (!is_array($doc)) return false;
        $lockedUntilRaw = $doc['lockedUntil'] ?? null;
        if (empty($lockedUntilRaw) || !is_string($lockedUntilRaw)) return false;
        $lockedUntilTs = (int) strtotime($lockedUntilRaw);
        return $lockedUntilTs > 0 && $lockedUntilTs > $now;
    }

    /**
     * Record a failed admin login attempt. Returns true if this attempt
     * crossed the lockout threshold (caller emits ADMIN_LOGIN_LOCKED).
     */
    private function _record_admin_account_fail(string $adminId, string $ip, int $now): bool
    {
        $docId = $this->_acct_doc_id($adminId);
        try {
            $cur = $this->firebase->firestoreGet(self::FS_ADMIN_AUTH_STATE, $docId);
        } catch (\Throwable $e) {
            $cur = null;
        }
        $attempts = (int) (is_array($cur) ? ($cur['attempts'] ?? 0) : 0) + 1;
        $payload  = [
            'adminId'    => $adminId,
            'attempts'   => $attempts,
            'lastFailIp' => $ip,
            'lastFailAt' => date('c', $now),
            'updatedAt'  => date('c', $now),
        ];
        $locked = false;
        if ($attempts >= self::ACCT_MAX_FAILS) {
            $payload['lockedUntil'] = date('c', $now + self::ACCT_LOCK_SEC);
            $locked = true;
        }
        try {
            $this->firebase->firestoreSet(self::FS_ADMIN_AUTH_STATE, $docId, $payload);
        } catch (\Throwable $e) {
            log_message('error', 'Wave C: admin account-fail write failed: ' . $e->getMessage());
        }
        return $locked;
    }

    private function _clear_admin_account_fail(string $adminId, string $schoolId): void
    {
        try {
            $this->firebase->firestoreSet(self::FS_ADMIN_AUTH_STATE, $this->_acct_doc_id($adminId), [
                'adminId'     => $adminId,
                'schoolId'    => $schoolId,
                'attempts'    => 0,
                'lockedUntil' => null,
                'lastLoginAt' => date('c'),
                'updatedAt'   => date('c'),
            ]);
        } catch (\Throwable $e) {
            /* best-effort cleanup */
        }
    }

    /** Firestore-safe docId for an adminId (alphanumeric + hyphens/underscores only). */
    private function _acct_doc_id(string $adminId): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '_', $adminId);
    }

    /**
     * Hydrate session-derived fields (session/current_session/session_year/
     * schoolName/school_display_name/school_features/available_sessions) from
     * the schools/{schoolId} Firestore doc + B2 entitlement reader for
     * school_features. Best-effort — defaults are safe if any read fails.
     */
    private function _hydrate_admin_session_from_school(string $schoolId): void
    {
        try {
            $schoolDoc = $this->firebase->firestoreGet('schools', $schoolId);
        } catch (\Throwable $e) {
            $schoolDoc = null;
        }
        if (!is_array($schoolDoc)) $schoolDoc = [];

        $name = (string) ($schoolDoc['name'] ?? $schoolDoc['display_name'] ?? $schoolDoc['schoolName'] ?? '');

        $sessions = $schoolDoc['sessions'] ?? [];
        if (!is_array($sessions)) $sessions = [];
        $sessions = array_values(array_filter($sessions, 'is_string'));
        rsort($sessions);

        $currentSession = (string) ($schoolDoc['currentSession'] ?? '');

        // SC-Step10/G1 (2026-06-06): FAIL-CLOSED. schools/{id}.currentSession is the SOLE
        // session authority — NO sessions[0] fallback. If it is absent, the tenant has no
        // canonical active session: block login (clear the partial session) rather than
        // silently activating a non-canonical session.
        if ($currentSession === '') {
            log_message('error',
                'SC10 fail-closed: schoolId=' . $schoolId . ' has no currentSession at login — blocking.');
            $this->session->unset_userdata(self::SESSION_KEYS);
            $this->session->set_flashdata('error',
                'Your school has no active academic session configured. Please contact your administrator.');
            redirect('admin_login');
            return;
        }

        // Wave C hotfix (2026-06-03): features live in tenantPublic/{schoolId}.activeModules
        // (canonical B2 entitlement reader), NOT on the schools/{id} doc. The original
        // cutover (ae605392) read schools/{id}.features which doesn't exist — left
        // school_features empty, hiding all feature-gated sidebar items. B2_registry_service
        // is already initialized in _try_firebase_admin_login above; reuse it here.
        // Returns snake_case machine keys; MY_Controller::_normalize_features() maps
        // them to Title Case sidebar labels + adds core defaults.
        $this->load->library('B2_registry_service');
        $this->b2_registry_service->init($this->firebase);
        $features = $this->b2_registry_service->get_features($schoolId);

        $this->session->set_userdata([
            'session'             => $currentSession,
            'current_session'     => $currentSession,
            'session_year'        => $currentSession,
            'schoolName'          => $schoolId,                                     // legacy: holds SCH_* key
            'school_display_name' => $name !== '' ? $name : $schoolId,
            'school_features'     => $features,
            'available_sessions'  => $sessions,
        ]);
    }

    /**
     * Phase 2A — block login if the staff doc backing this admin id is
     * Inactive. Reads `staff/{schoolFirebaseKey}_{userId}` from Firestore
     * and rejects on any status other than "Active" (case-insensitive).
     *
     * Wave C note: the primary status check is now embedded in
     * _try_firebase_admin_login() above; this helper is retained for any
     * other callers that gate on staff status (none today; defence-in-depth).
     *
     * Skipped silently when no staff doc is present (e.g., legacy admin
     * accounts without a Firestore mirror).
     */
    private function _assert_staff_active(string $schoolFirebaseKey, string $userId): void
    {
        if ($schoolFirebaseKey === '' || $userId === '') return;

        $docId = $schoolFirebaseKey . '_' . $userId;
        try {
            $staffDoc = $this->firebase->firestoreGet('staff', $docId);
        } catch (\Throwable $e) {
            log_message('error', 'Staff status guard read failed for ' . $userId . ': ' . $e->getMessage());
            return; // fail-open
        }

        if (!is_array($staffDoc) || empty($staffDoc)) {
            return; // no staff doc — non-staff admin account, or legacy
        }

        $rawStatus = (string) ($staffDoc['status'] ?? $staffDoc['Status'] ?? 'Active');
        if (strcasecmp(trim($rawStatus), 'Active') !== 0) {
            log_message('info', 'Login blocked — staff status=' . $rawStatus . ' user=' . $this->_log_safe($userId));
            $this->session->set_flashdata('error', 'Account deactivated. Contact admin.');
            redirect('admin_login');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  LOGOUT
    // ─────────────────────────────────────────────────────────────────────
    public function logout(): void
    {
        // Wave C: legacy RTDB AccessHistory write removed. Audit is now
        // captured by ADMIN_LOGIN_SUCCESS telemetry (Firestore security_events)
        // + Firebase Auth's own lastSignInTime field.

        // [S-11] Clear ALL keys — no ghost data
        $this->session->unset_userdata(self::SESSION_KEYS);
        $this->session->set_flashdata('success', 'You have been successfully logged out.');
        redirect('admin_login');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  GET SERVER DATE
    // ─────────────────────────────────────────────────────────────────────
    public function get_server_date(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['date' => date('d-m-Y')]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  GET  /admin_login/forgot_password   (out of Wave C scope — auth_client retained)
    // ─────────────────────────────────────────────────────────────────────
    public function forgot_password(): void
    {
        $this->load->view('admin_forgot_password');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  POST  /admin_login/recovery_contact
    //  Contact-card recovery (no OTP). Given a login ID, return who to
    //  contact to get the password reset:
    //    • School Super Admin (SSAxxxx / claim role school_super_admin)
    //        → the platform Super Admin recovery contact (one global block,
    //          editable at /superadmin/school_admins).
    //    • Every other role → their own school's forget_password_details
    //          (the SSA contact), resolved from the Firebase Auth claim.
    // ─────────────────────────────────────────────────────────────────────
    public function recovery_contact(): void
    {
        if ($this->input->method() !== 'post') { redirect('admin_login'); return; }

        $adminId = strtoupper(trim((string) $this->input->post('admin_id', TRUE)));
        if ($adminId === '') {
            $this->_json_response(['status' => 'error', 'message' => 'Please enter your ID.']);
            return;
        }

        // This recovery surface is the WEB ADMIN PANEL, whose login is limited to
        // admin accounts (ADMxxxx) and the school super admin (SSAxxxx). Field
        // teachers (STAxxxx) and students (STUxxxx) sign in through their mobile
        // apps and must use the in-app "Forgot password", not this page. Reject
        // app account types up front by ID prefix.
        $appAccountMsg = 'This isn\'t a web admin account. Teachers and students should use "Forgot password" inside their ZenXii app.';
        if (preg_match('/^(STA|STU|PAR)\d+$/', $adminId)) {
            $this->_json_response(['status' => 'error', 'message' => $appAccountMsg]);
            return;
        }

        // SSA by ID prefix is enough; we still resolve the Auth record for
        // everyone else to read the school claim.
        $isSsa    = (bool) preg_match('/^SSA\d+$/', $adminId);
        $schoolId = '';

        if (!$isSsa) {
            $user = $this->firebase->getFirebaseUser($adminId);
            if ($user === null) {
                $this->_json_response(['status' => 'error',
                    'message' => 'No account found for "' . $adminId . '". Check your ID and try again.']);
                return;
            }
            $claims = [];
            try { $claims = (array) ($user->customClaims ?? []); } catch (\Throwable $e) { $claims = []; }
            $role = strtolower((string) ($claims['role'] ?? ''));

            // Backstop the prefix check: app-only roles never recover here even if
            // they were issued a non-standard ID.
            if (in_array($role, ['teacher', 'student', 'parent'], true)) {
                $this->_json_response(['status' => 'error', 'message' => $appAccountMsg]);
                return;
            }

            if ($role === 'school_super_admin') {
                $isSsa = true;
            } else {
                $schoolId = (string) ($claims['schoolId'] ?? ($claims['school_id'] ?? ''));
            }
        }

        // ── School Super Admin → platform Super Admin contact ──
        if ($isSsa) {
            $block  = (array) ($this->firebase->firestoreGet('platformSettings', 'recoveryContact') ?? []);
            $name   = trim((string) ($block['name']   ?? ''));
            $email  = trim((string) ($block['email']  ?? ''));
            $number = trim((string) ($block['number'] ?? ''));

            if ($name === '' && $email === '' && $number === '') {
                $this->_json_response(['status' => 'success', 'found' => false, 'scope' => 'platform',
                    'message' => 'The platform recovery contact has not been set up yet. Please contact ZenXii support.']);
                return;
            }
            $this->_json_response([
                'status'   => 'success', 'found' => true, 'scope' => 'platform',
                'title'    => 'ZenXii Platform Support',
                'subtitle' => 'School Super Admin password recovery',
                'name'     => $name, 'number' => $number, 'email' => $email,
            ]);
            return;
        }

        if ($schoolId === '') {
            $this->_json_response(['status' => 'error',
                'message' => 'Could not resolve your school. Please contact your administrator.']);
            return;
        }

        // ── Any other role → their school's recovery contact (the SSA) ──
        $school     = (array) ($this->firebase->firestoreGet('schools', $schoolId) ?? []);
        $fpd        = is_array($school['forget_password_details'] ?? null) ? $school['forget_password_details'] : [];
        $name       = trim((string) ($fpd['name']   ?? ''));
        $email      = trim((string) ($fpd['email']  ?? ''));
        $number     = trim((string) ($fpd['number'] ?? ''));
        $schoolName = trim((string) ($school['schoolName'] ?? ($school['name'] ?? 'Your school')));

        if ($name === '' && $email === '' && $number === '') {
            $this->_json_response(['status' => 'success', 'found' => false, 'scope' => 'school',
                'title'   => $schoolName,
                'message' => 'Your school has not set a recovery contact yet. Please reach the school office in person.']);
            return;
        }
        $this->_json_response([
            'status'   => 'success', 'found' => true, 'scope' => 'school',
            'title'    => $schoolName,
            'subtitle' => 'Contact your school admin to reset your password',
            'name'     => $name, 'number' => $number, 'email' => $email,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  POST  /admin_login/send_otp
    // ─────────────────────────────────────────────────────────────────────
    public function send_otp(): void
    {
        if ($this->input->method() !== 'post') { redirect('admin_login'); return; }

        $adminId = trim((string) $this->input->post('admin_id', TRUE));
        if (empty($adminId)) {
            $this->_json_response(['status' => 'error', 'message' => 'Admin ID is required.']);
            return;
        }

        $this->load->library('auth_client');
        $result = $this->auth_client->forgot_password($adminId);

        $this->_json_response([
            'status'       => !empty($result['success']) ? 'success' : 'error',
            'message'      => $result['message'] ?? 'Request failed.',
            'email_masked' => $result['email_masked'] ?? '',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  POST  /admin_login/verify_otp
    // ─────────────────────────────────────────────────────────────────────
    public function verify_otp(): void
    {
        if ($this->input->method() !== 'post') { redirect('admin_login'); return; }

        $adminId = trim((string) $this->input->post('admin_id', TRUE));
        $otp     = trim((string) $this->input->post('otp', TRUE));

        if (empty($adminId) || empty($otp)) {
            $this->_json_response(['status' => 'error', 'message' => 'Admin ID and OTP are required.']);
            return;
        }

        $this->load->library('auth_client');
        $result = $this->auth_client->verify_otp($adminId, $otp);

        $this->_json_response([
            'status'      => !empty($result['success']) ? 'success' : 'error',
            'message'     => $result['message'] ?? 'Verification failed.',
            'resetToken'  => $result['resetToken'] ?? '',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  POST  /admin_login/reset_password
    // ─────────────────────────────────────────────────────────────────────
    public function reset_password(): void
    {
        if ($this->input->method() !== 'post') { redirect('admin_login'); return; }

        $adminId      = trim((string) $this->input->post('admin_id', TRUE));
        $resetToken   = trim((string) $this->input->post('reset_token', TRUE));
        $newPassword  = (string) $this->input->post('new_password', FALSE);

        if (empty($adminId) || empty($resetToken) || empty($newPassword)) {
            $this->_json_response(['status' => 'error', 'message' => 'All fields are required.']);
            return;
        }

        if (strlen($newPassword) < 8) {
            $this->_json_response(['status' => 'error', 'message' => 'Password must be at least 8 characters.']);
            return;
        }

        $this->load->library('auth_client');
        $result = $this->auth_client->reset_password_otp($adminId, $resetToken, $newPassword);

        $this->_json_response([
            'status'  => !empty($result['success']) ? 'success' : 'error',
            'message' => $result['message'] ?? 'Password reset failed.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Student Password Reset   (out of Wave C scope — auth_client retained)
    // ─────────────────────────────────────────────────────────────────────

    public function student_forgot_password(): void
    {
        $this->load->view('student_forgot_password');
    }

    public function student_send_otp(): void
    {
        if ($this->input->method() !== 'post') { redirect('admin_login'); return; }

        $email = trim((string) $this->input->post('email', TRUE));
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->_json_response(['status' => 'error', 'message' => 'A valid email address is required.']);
            return;
        }

        $this->load->library('auth_client');
        $result = $this->auth_client->forgot_password_student($email);

        $this->_json_response([
            'status'       => !empty($result['success']) ? 'success' : 'error',
            'message'      => $result['message'] ?? 'Request failed.',
            'email_masked' => $result['email_masked'] ?? '',
            'accounts'     => $result['accounts'] ?? [],
        ]);
    }

    public function student_verify_otp(): void
    {
        if ($this->input->method() !== 'post') { redirect('admin_login'); return; }

        $email  = trim((string) $this->input->post('email', TRUE));
        $otp    = trim((string) $this->input->post('otp', TRUE));
        $userId = trim((string) $this->input->post('user_id', TRUE));

        if (empty($email) || empty($otp) || empty($userId)) {
            $this->_json_response(['status' => 'error', 'message' => 'Email, OTP, and account selection are required.']);
            return;
        }

        $this->load->library('auth_client');
        $result = $this->auth_client->verify_otp_student($email, $otp, $userId);

        $this->_json_response([
            'status'     => !empty($result['success']) ? 'success' : 'error',
            'message'    => $result['message'] ?? 'Verification failed.',
            'resetToken' => $result['resetToken'] ?? '',
            'userId'     => $result['userId'] ?? '',
        ]);
    }

    public function student_reset_password(): void
    {
        if ($this->input->method() !== 'post') { redirect('admin_login'); return; }

        $userId      = trim((string) $this->input->post('user_id', TRUE));
        $resetToken  = trim((string) $this->input->post('reset_token', TRUE));
        $newPassword = (string) $this->input->post('new_password', FALSE);

        if (empty($userId) || empty($resetToken) || empty($newPassword)) {
            $this->_json_response(['status' => 'error', 'message' => 'All fields are required.']);
            return;
        }

        if (strlen($newPassword) < 6) {
            $this->_json_response(['status' => 'error', 'message' => 'Password must be at least 6 characters.']);
            return;
        }

        $this->load->library('auth_client');
        $result = $this->auth_client->reset_password_student($userId, $resetToken, $newPassword);

        $this->_json_response([
            'status'  => !empty($result['success']) ? 'success' : 'error',
            'message' => $result['message'] ?? 'Password reset failed.',
        ]);
    }

    /**
     * JSON response helper for forgot password endpoints.
     * Includes refreshed CSRF token so the multi-step form keeps working.
     */
    private function _json_response(array $payload): void
    {
        $csrfName = $this->security->get_csrf_token_name();
        $payload[$csrfName] = $this->security->get_csrf_hash();
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }

    // =========================================================================
    //  PRIVATE HELPERS
    // =========================================================================

    /**
     * [S-12] Emit all security + no-cache headers.
     */
    private function _send_security_headers(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }

    /**
     * [S-03] Returns TRUE if value is safe to use as a path segment / docId.
     * Allows: letters, digits, hyphens, underscores only.
     */
    private function _is_safe_id(string $value): bool
    {
        return $value !== '' && (bool) preg_match('/^[A-Za-z0-9_\-]+$/', $value);
    }

    /**
     * Get the real client IP. Falls back to REMOTE_ADDR (cannot be spoofed).
     */
    private function _get_real_ip(): string
    {
        $ip = $this->input->ip_address();
        return ($ip === '::1') ? '127.0.0.1' : $ip;
    }

    /**
     * [S-13] Strip newlines/control chars before logging — prevents log injection.
     */
    private function _log_safe(string $value): string
    {
        return preg_replace('/[\r\n\t\x00-\x1F\x7F]/', '_', $value);
    }
}
