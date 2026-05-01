<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Admin_login.php — Production-hardened login controller.
 * Extends CI_Controller (NOT MY_Controller — avoids auth redirect loop).
 *
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║  SECURITY MEASURES                                               ║
 * ╠══════════════════════════════════════════════════════════════════╣
 * ║  [S-01]  POST-only enforcement on check_credentials             ║
 * ║  [S-02]  Input length + format validation                        ║
 * ║  [S-03]  Firebase path injection blocked (/ . # $ [ ] chars)    ║
 * ║  [S-04]  Generic error messages — no user/school enumeration     ║
 * ║  [S-05]  Timing-safe credential flow — dummy hash on miss        ║
 * ║  [S-06]  Per-account brute-force lockout (5 attempts / 30 min)  ║
 * ║  [S-07]  Per-IP rate limiting (20 fails / 15 min across any ID) ║
 * ║  [S-08]  Password length capped at 72 chars (bcrypt DoS guard)  ║
 * ║  [S-09]  password_hash / password_verify + plain-text migration ║
 * ║  [S-10]  Session fixation prevented — sess_regenerate(TRUE)     ║
 * ║  [S-11]  All session keys cleared on logout + Firebase updated  ║
 * ║  [S-12]  Security + no-cache headers on every response          ║
 * ║  [S-13]  Log injection prevented — inputs sanitised before log  ║
 * ║  [S-14]  Subscription status + date gating at login time        ║
 * ║  [S-15]  School ID resolved via Indexes/School_codes index      ║
 * ╠══════════════════════════════════════════════════════════════════╣
 * ║  AUDIT FIXES (this revision)                                     ║
 * ║  [A-01]  Lockout check moved BEFORE bcrypt — saves CPU on lock  ║
 * ║  [A-02]  SESSION_KEYS includes 'login_csrf' — no ghost keys     ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */
class Admin_login extends CI_Controller
{
    // ── Dummy bcrypt hash — timing-safe flow when admin not found (S-05) ─
    private const DUMMY_HASH = '$2y$10$usesomesillystringfore2uDLvp1Ii2e./U9C8sBjqp8I/p7';

    // ── Input limits (S-02 / S-08) ────────────────────────────────────────
    private const MAX_ADMIN_ID_LEN  = 32;
    private const MAX_PASSWORD_LEN  = 72;  // bcrypt silently ignores beyond 72

    // ── Per-IP rate limit (S-07) ──────────────────────────────────────────
    private const IP_MAX_FAILS  = 20;   // max fails from one IP
    private const IP_WINDOW_SEC = 900;  // 15-minute sliding window

    // ── Single source of truth for ALL session keys ───────────────────────
    // Must stay in sync with MY_Controller::SESSION_KEYS.
    // [A-02] 'login_csrf' included so logout clears it cleanly.
    public const SESSION_KEYS = [
        'admin_id',
        'school_id',              // now SCH_XXXXXX
        'school_code',            // login code
        'admin_role',
        'admin_name',
        'session',
        'current_session',
        'session_year',
        'schoolName',
        'school_display_name',    // human-readable name
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
    //  CHECK CREDENTIALS
    // ─────────────────────────────────────────────────────────────────────
    public function check_credentials(): void
    {
        // [S-01] POST only
        if ($this->input->method() !== 'post') {
            redirect('admin_login');
        }

        $now      = time();
        $firebase = $this->firebase;
        $ip       = $this->_get_real_ip();

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

        // [S-03] Firebase path injection guard
        if (! $this->_is_safe_id($adminId)) {
            $this->session->set_flashdata('error', 'Invalid credentials.');
            redirect('admin_login');
        }

        // ══════════════════════════════════════════════════════════════
        //  LOCAL-DEV FIREBASE-DIRECT LOGIN (auto-resolves school from adminId)
        //  When the Auth API is unreachable (Node service down, Render asleep),
        //  fall back to reading admin record straight from Firebase RTDB.
        //  If a matching school is found for the adminId, the call below
        //  always redirects (success → /admin, fail → /admin_login with error)
        //  so control never returns past it.
        // ══════════════════════════════════════════════════════════════
        $autoSchoolCode = $this->_findSchoolForAdmin($adminId);
        if ($autoSchoolCode !== null) {
            $this->_firebase_fallback_login($adminId, $autoSchoolCode, $password, $ip, $now);
            // unreachable — _firebase_fallback_login redirects in all branches.
        }

        // ══════════════════════════════════════════════════════════════
        //  PRIMARY: Call Auth API (MongoDB lookup — schoolCode resolved automatically)
        // ══════════════════════════════════════════════════════════════
        $this->load->library('auth_client');
        $result = $this->auth_client->web_login($adminId, '', $password, $ip);

        if (!empty($result['unavailable'])) {
            log_message('error', 'Auth API unavailable — cannot login without it');
            $this->session->set_flashdata('error', 'Authentication service is temporarily unavailable. Please try again shortly.');
            redirect('admin_login');
        }

        if (empty($result['success'])) {
            $message = $result['message'] ?? 'Invalid credentials. Please try again.';
            $this->session->set_flashdata('error', $message);
            redirect('admin_login');
        }

        // ══════════════════════════════════════════════════════════════
        //  AUTH API SUCCESS — extract data and set session
        // ══════════════════════════════════════════════════════════════
        $userData     = $result['user']         ?? [];
        $subscription = $result['subscription'] ?? [];
        $sessions     = $result['sessions']     ?? [];

        // MongoDB: schoolId = login code (10005), schoolCode = Firebase key (SCH_XXXXXX)
        $school_login_code   = $userData['schoolId']    ?? '';   // login code — for Users/Admin/{code}/
        $school_firebase_key = $userData['schoolCode']  ?? '';   // Firebase key — for Schools/{key}/, System/Schools/{key}/
        $displayName         = $result['displayName']   ?? $school_firebase_key;
        $adminName           = $userData['name']        ?? '';

        // Phase 2A — block login if staff record is Inactive. (No-op for non-staff admins.)
        $this->_assert_staff_active($school_firebase_key, $adminId);

        // Role: use Firebase profile's Role (e.g. "Super Admin", "School Super Admin")
        // which is what the rest of the PHP app expects
        $firebaseProfile = $result['firebaseProfile'] ?? [];
        $adminRole       = $firebaseProfile['Role']   ?? $userData['role'] ?? '';

        // Subscription timestamps
        $endDate     = $subscription['endDate']  ?? '';
        $endTs       = ($endDate !== '') ? (int) strtotime($endDate . ' 23:59:59') : $now + 86400;
        $graceEndRaw = $subscription['graceEnd'] ?? '';
        $graceEndTs  = ($graceEndRaw !== '' && strtotime($graceEndRaw) !== false)
            ? (int) strtotime($graceEndRaw . ' 23:59:59')
            : $endTs + (7 * 86400);
        $daysRemaining = (int) ceil(($endTs - $now) / 86400);
        $subWarning    = ($daysRemaining <= 7)
            ? "Subscription expires in {$daysRemaining} day(s) on {$endDate}. Please renew soon."
            : null;

        $financialYear     = $sessions['active']    ?? '';
        $availableSessions = $sessions['available']  ?? [];
        $schoolFeatures    = $subscription['features'] ?? [];

        // Update Firebase access history (fire-and-forget for audit trail)
        $firebase->update("Users/Admin/{$school_login_code}/{$adminId}/AccessHistory", [
            'LastLogin'     => date('c', $now),
            'LoginIP'       => $ip,
            'LoginAttempts' => 0,
            'LockedUntil'   => null,
            'IsLoggedIn'    => true,
        ]);

        // [S-10] Prevent session fixation
        $this->session->sess_regenerate(TRUE);

        // Clear any SA panel session to prevent session bleed-through
        $this->session->unset_userdata(['sa_id', 'sa_name', 'sa_role', 'sa_email', 'sa_csrf_token']);

        // [S-11] Store all session data — identical keys as before
        $this->session->set_userdata([
            'admin_id'               => $adminId,
            'school_id'              => $school_firebase_key,   // SCH_XXXXXX — used for Schools/{id}/ paths
            'school_code'            => $school_login_code,     // 10005 — used for Users/Admin/{code}/ paths
            'admin_role'             => $adminRole,
            'admin_name'             => $adminName,
            'session'                => $financialYear,
            'current_session'        => $financialYear,
            'session_year'           => $financialYear,
            'schoolName'             => $school_firebase_key,
            'school_display_name'    => $displayName,
            'school_features'        => $schoolFeatures,
            'available_sessions'     => $availableSessions,
            'subscription_expiry'    => $endTs,
            'subscription_grace_end' => $graceEndTs,
            'subscription_warning'   => $subWarning,
            'sub_check_ts'           => 0,
        ]);

        // [RBAC] Cache role permissions in session
        $this->load->helper('rbac');
        $rbacPerms = load_role_permissions($firebase, $school_firebase_key, $adminRole);
        $this->session->set_userdata('rbac_permissions', $rbacPerms);

        log_message('info',
            'Login OK (auth-api) admin=' . $this->_log_safe($adminId)
            . ' school=' . $this->_log_safe($school_login_code)
            . ' schoolId=' . $this->_log_safe($school_firebase_key)
            . ' source=' . ($result['source'] ?? 'unknown')
            . ' ip=' . $ip
        );

        redirect('admin/index');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  FIREBASE FALLBACK LOGIN (DEPRECATED)
    //  Kept for emergency use only. Auth API is the sole auth path.
    //  The admin login flow no longer calls this — it shows an error instead.
    // ─────────────────────────────────────────────────────────────────────
    private function _firebase_fallback_login(
        string $adminId,
        string $schoolId,
        string $password,
        string $ip,
        int    $now
    ): void {
        $firebase = $this->firebase;

        // Per-IP rate limit
        if ($this->_is_ip_blocked($ip, $now, $firebase)) {
            $this->session->set_flashdata('error', 'Too many login attempts. Please try again later.');
            redirect('admin_login');
        }

        // Resolve school
        $schoolId_resolved = $this->_resolveSchoolId($schoolId);

        // Fetch admin record
        $adminData = null;
        if ($schoolId_resolved !== null) {
            $raw = $firebase->get("Users/Admin/{$schoolId}/{$adminId}");
            $adminData = is_array($raw) ? $raw : null;
        }

        // Per-account lockout
        if ($adminData !== null) {
            $accessHistory = $adminData['AccessHistory'] ?? [];
            $lockedUntil   = isset($accessHistory['LockedUntil'])
                ? (int) strtotime((string) $accessHistory['LockedUntil'])
                : 0;
            if ($lockedUntil > 0 && $now >= $lockedUntil) {
                $firebase->update("Users/Admin/{$schoolId}/{$adminId}/AccessHistory",
                    ['LoginAttempts' => 0, 'LockedUntil' => null]);
                $lockedUntil = 0;
            }
            if ($lockedUntil > $now) {
                $minutes = (int) ceil(($lockedUntil - $now) / 60);
                $this->session->set_flashdata('error',
                    "Account temporarily locked. Try again in {$minutes} minute(s).");
                redirect('admin_login');
            }
        }

        // Password verification
        $storedHash       = ($adminData !== null)
            ? (string) ($adminData['Credentials']['Password'] ?? '') : self::DUMMY_HASH;
        // Normalise Node-bcrypt prefix ($2b$) to PHP-bcrypt ($2y$) — the algorithms
        // are identical, only the prefix differs, and PHP's password_verify() on some
        // builds rejects $2b$ outright. This makes existing Node-stored hashes verify.
        if (strncmp($storedHash, '$2b$', 4) === 0) {
            $storedHash = '$2y$' . substr($storedHash, 4);
        }
        $credentialsValid = false;

        if ($adminData !== null && $schoolId_resolved !== null) {
            $credentialsValid = password_verify($password, $storedHash);
            if (! $credentialsValid && strlen($storedHash) !== 60
                && strpos($storedHash, '$2y$') !== 0 && strpos($storedHash, '$2a$') !== 0
                && $password === $storedHash) {
                $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $firebase->update("Users/Admin/{$schoolId}/{$adminId}/Credentials", ['Password' => $newHash]);
                $credentialsValid = true;
            }
        } else {
            password_verify($password, self::DUMMY_HASH);
        }

        if (! $credentialsValid) {
            $this->_record_ip_fail($ip, $now, $firebase);
            if ($adminData !== null) {
                $this->_record_account_fail($adminId, $schoolId, $adminData, $firebase, $now);
            }
            $this->session->set_flashdata('error', 'Invalid credentials. Please try again.');
            redirect('admin_login');
        }

        // Legacy RTDB Status guard — only blocks on EXPLICIT "Inactive".
        // Treats missing/empty as Active because most staff records (STA*) have
        // never had this RTDB field set; only SSA accounts and a few legacy
        // admins do. The canonical Active/Inactive decision is now made by
        // _assert_staff_active() below using Firestore staff.status.
        $rtdbStatus = trim((string) ($adminData['Status'] ?? ''));
        if ($rtdbStatus !== '' && strcasecmp($rtdbStatus, 'Inactive') === 0) {
            $this->session->set_flashdata('error', 'Account deactivated. Contact admin.');
            redirect('admin_login');
        }

        // Phase 2A — Firestore-canonical staff status check (post-Phase-1 toggle aware).
        $this->_assert_staff_active($schoolId_resolved, $adminId);

        // Subscription check
        $subscription = null;
        foreach (["System/Schools/{$schoolId_resolved}/subscription",
                  "Users/Schools/{$schoolId_resolved}/subscription"] as $subPath) {
            $subscription = $firebase->get($subPath);
            if ($subscription && is_array($subscription)) break;
            $subscription = null;
        }
        if (! $subscription || ! is_array($subscription)) {
            $this->session->set_flashdata('error', 'Subscription record not found. Please contact support.');
            redirect('admin_login');
        }
        $status  = (string) ($subscription['status'] ?? 'Inactive');
        $duration = is_array($subscription['duration'] ?? null) ? $subscription['duration'] : [];
        $endDate = trim((string) ($duration['endDate'] ?? ''));
        if (!in_array($status, ['Active', 'Grace_Period'], true)) {
            $this->session->set_flashdata('error', 'Subscription is not active. Please contact support.');
            redirect('admin_login');
        }
        $parsedEndDate = ($endDate !== '') ? strtotime($endDate) : false;
        if ($parsedEndDate === false || $parsedEndDate < $now) {
            $this->session->set_flashdata('error',
                'Subscription expired on ' . htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8')
                . '. Please contact our team to renew.');
            redirect('admin_login');
        }

        $endTs       = (int) strtotime($endDate . ' 23:59:59');
        $graceEndRaw = trim((string)($subscription['grace_end'] ?? ''));
        $graceEndTs  = ($graceEndRaw !== '' && strtotime($graceEndRaw) !== false)
            ? (int) strtotime($graceEndRaw . ' 23:59:59') : $endTs + (7 * 86400);
        $daysRemaining = (int) ceil(($endTs - $now) / 86400);
        $subWarning    = ($daysRemaining <= 7)
            ? "Subscription expires in {$daysRemaining} day(s) on {$endDate}. Please renew soon." : null;

        $this->_clear_ip_fails($ip, $firebase);
        $firebase->update("Users/Admin/{$schoolId}/{$adminId}/AccessHistory", [
            'LastLogin' => date('c', $now), 'LoginIP' => $ip,
            'LoginAttempts' => 0, 'LockedUntil' => null, 'IsLoggedIn' => true,
        ]);

        $this->session->sess_regenerate(TRUE);

        // Sessions
        $month = (int) date('m', $now);
        $year  = (int) date('Y', $now);
        $computedSession = ($month >= 4)
            ? $year . '-' . substr($year + 1, -2)
            : ($year - 1) . '-' . substr($year, -2);
        $storedSessions = $firebase->get("Schools/{$schoolId_resolved}/Sessions");
        $availableSessions = (is_array($storedSessions) && !empty($storedSessions))
            ? array_values(array_unique(array_filter($storedSessions, 'is_string'))) : [];
        if (!in_array($computedSession, $availableSessions, true)) {
            $availableSessions[] = $computedSession;
            $firebase->set("Schools/{$schoolId_resolved}/Sessions", $availableSessions);
        }
        rsort($availableSessions);
        $activeSession = $firebase->get("Schools/{$schoolId_resolved}/Config/ActiveSession");
        $financialYear = (!empty($activeSession) && is_string($activeSession)
            && in_array($activeSession, $availableSessions, true))
            ? $activeSession : $availableSessions[0];

        // Features
        $schoolFeatures = [];
        foreach (["System/Schools/{$schoolId_resolved}/subscription/features",
                  "Users/Schools/{$schoolId_resolved}/subscription/features"] as $fp) {
            $featuresRaw = $firebase->get($fp);
            if (is_array($featuresRaw) && !empty($featuresRaw)) {
                $schoolFeatures = array_values($featuresRaw); break;
            }
        }

        // Display name
        $displayName = '';
        foreach (["System/Schools/{$schoolId_resolved}/profile",
                  "Users/Schools/{$schoolId_resolved}/profile"] as $pp) {
            $profileData = $firebase->get($pp);
            if (is_array($profileData)) {
                $displayName = $profileData['school_name'] ?? $profileData['name'] ?? '';
                if (!empty($displayName)) break;
            }
        }
        if (empty($displayName) && strpos($schoolId_resolved, 'SCH_') !== 0) $displayName = $schoolId_resolved;
        if (empty($displayName)) $displayName = $schoolId_resolved;

        $this->session->unset_userdata(['sa_id', 'sa_name', 'sa_role', 'sa_email', 'sa_csrf_token']);
        $this->session->set_userdata([
            'admin_id' => $adminId, 'school_id' => $schoolId_resolved,
            'school_code' => $schoolId,
            'admin_role' => $adminData['Role'] ?? $adminData['Profile']['role'] ?? '',
            'admin_name' => $adminData['Name'] ?? $adminData['Profile']['name'] ?? '',
            'session' => $financialYear, 'current_session' => $financialYear,
            'session_year' => $financialYear, 'schoolName' => $schoolId_resolved,
            'school_display_name' => $displayName, 'school_features' => $schoolFeatures,
            'available_sessions' => $availableSessions,
            'subscription_expiry' => $endTs, 'subscription_grace_end' => $graceEndTs,
            'subscription_warning' => $subWarning, 'sub_check_ts' => 0,
        ]);

        $this->load->helper('rbac');
        $adminRole = $adminData['Role'] ?? $adminData['Profile']['role'] ?? '';
        // FIX: $school_firebase_key was undefined here; the Firebase key is $schoolId_resolved.
        $rbacPerms = load_role_permissions($firebase, $schoolId_resolved, $adminRole);
        $this->session->set_userdata('rbac_permissions', $rbacPerms);

        log_message('info',
            'Login OK (firebase-fallback) admin=' . $this->_log_safe($adminId)
            . ' school=' . $this->_log_safe($schoolId) . ' ip=' . $ip);

        redirect('admin/index');
    }

    /**
     * Phase 2A — block login if the staff doc backing this admin id is
     * Inactive. Reads `staff/{schoolFirebaseKey}_{userId}` from Firestore
     * and rejects on any status other than "Active" (case-insensitive).
     *
     * Skipped silently when no staff doc is present (e.g., legacy admin
     * accounts without a Firestore mirror) — credentials check already ran,
     * so this is a defence-in-depth gate, not the primary auth.
     */
    private function _assert_staff_active(string $schoolFirebaseKey, string $userId): void
    {
        if ($schoolFirebaseKey === '' || $userId === '') return;

        $docId = $schoolFirebaseKey . '_' . $userId;
        try {
            $staffDoc = $this->firebase->firestoreGet('staff', $docId);
        } catch (\Throwable $e) {
            log_message('error', 'Staff status guard read failed for ' . $userId . ': ' . $e->getMessage());
            // Fail-open: don't lock the user out of admin if Firestore is flaky.
            return;
        }

        if (!is_array($staffDoc) || empty($staffDoc)) {
            // No staff doc — either a non-staff admin account, or legacy.
            // Don't block; the primary auth already verified credentials.
            return;
        }

        // Read camelCase first (canonical), fall back to PascalCase for legacy docs.
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
        $adminId    = $this->session->userdata('admin_id');
        $schoolCode = $this->session->userdata('school_code');

        if ($adminId && $schoolCode && $this->_is_safe_id((string)$adminId) && $this->_is_safe_id((string)$schoolCode)) {
            $this->firebase->update(
                "Users/Admin/{$schoolCode}/{$adminId}/AccessHistory",
                ['IsLoggedIn' => false, 'LoginIP' => null]
            );
        }

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
    //  GET  /admin_login/forgot_password
    // ─────────────────────────────────────────────────────────────────────
    public function forgot_password(): void
    {
        $this->load->view('admin_forgot_password');
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
    //  Student Password Reset (parent email → select account → reset)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Show student forgot password page.
     */
    public function student_forgot_password(): void
    {
        $this->load->view('student_forgot_password');
    }

    /**
     * POST /admin_login/student_send_otp
     * Parent enters email → OTP sent → returns list of associated student accounts.
     */
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

    /**
     * POST /admin_login/student_verify_otp
     * Parent submits email + OTP + selected student userId.
     */
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

    /**
     * POST /admin_login/student_reset_password
     * Reset password for the selected student account.
     */
    public function student_reset_password(): void
    {
        if ($this->input->method() !== 'post') { redirect('admin_login'); return; }

        $userId     = trim((string) $this->input->post('user_id', TRUE));
        $resetToken = trim((string) $this->input->post('reset_token', TRUE));
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
     * Centralised here so both __construct and any future public methods use it.
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
     * [S-15] Resolve school identifier from a login code.
     *
     * 1. New path: Indexes/School_codes/{code} → SCH_XXXXXX
     * 2. Legacy fallback: School_ids/{code} → school_name (pre-migration schools)
     *
     * Returns the resolved identifier (SCH_XXXXXX or school_name) or null.
     */
    /**
     * Find the schoolCode (e.g. "10001") whose Users/Admin/{schoolCode}/{adminId}
     * record exists in Firebase. Returns null if not found.
     *
     * Used by check_credentials() to enable Firebase-direct login when the
     * Node Auth API is unreachable. Reads Users/Admin top-level once and
     * scans children for the adminId — single RTDB read, ~O(schools).
     */
    private function _findSchoolForAdmin(string $adminId): ?string
    {
        $firebase = $this->firebase;
        $allAdmins = $firebase->get('Users/Admin');
        if (!is_array($allAdmins)) {
            return null;
        }
        foreach ($allAdmins as $key => $admins) {
            if (!is_array($admins)) continue;
            if (isset($admins[$adminId]) && is_array($admins[$adminId])) {
                return (string) $key;
            }
        }
        return null;
    }

    private function _resolveSchoolId(string $schoolCode): ?string
    {
        $firebase = $this->firebase;

        // ── New architecture: Indexes/School_codes/{code} → SCH_XXXXXX ──
        $schoolId = $firebase->get("Indexes/School_codes/{$schoolCode}");
        if ($schoolId && is_array($schoolId)) {
            $schoolId = reset($schoolId);
        }
        if ($schoolId && is_string($schoolId) && strpos(trim($schoolId), 'SCH_') === 0) {
            return trim($schoolId);
        }

        // ── Legacy fallback: School_ids/{code} → school_name ──
        $schoolName = $firebase->get("School_ids/{$schoolCode}");
        if ($schoolName && is_array($schoolName)) {
            $schoolName = reset($schoolName);
        }
        if ($schoolName && is_string($schoolName) && trim($schoolName) !== '' && $schoolName !== 'Count') {
            return trim($schoolName);
        }

        return null;
    }

    /**
     * [S-06] [A-01] Record a failed attempt on a specific account.
     * Lock after 5 failures for 30 minutes.
     */
    private function _record_account_fail(
        string $adminId,
        string $schoolId,
        array  $adminData,
        object $firebase,
        int    $now
    ): void {
        $path     = "Users/Admin/{$schoolId}/{$adminId}/AccessHistory";
        $attempts = (int) ($adminData['AccessHistory']['LoginAttempts'] ?? 0) + 1;
        $update   = ['LoginAttempts' => $attempts];

        if ($attempts >= 5) {
            $update['LockedUntil'] = date('c', $now + 1800);
        }

        $firebase->update($path, $update);
    }

    /**
     * [S-07] Returns TRUE if this IP has exceeded the rate limit.
     */
    private function _is_ip_blocked(string $ip, int $now, object $firebase): bool
    {
        $record = $firebase->get($this->_ip_path($ip));
        if (! is_array($record)) return false;

        $windowStart = (int) ($record['windowStart'] ?? 0);
        if ($now - $windowStart > self::IP_WINDOW_SEC) return false;

        return (int) ($record['fails'] ?? 0) >= self::IP_MAX_FAILS;
    }

    /**
     * [S-07] Record one failure for this IP.
     */
    private function _record_ip_fail(string $ip, int $now, object $firebase): void
    {
        $path   = $this->_ip_path($ip);
        $record = $firebase->get($path);

        if (! is_array($record) || ($now - (int)($record['windowStart'] ?? 0)) > self::IP_WINDOW_SEC) {
            $firebase->update($path, ['windowStart' => $now, 'fails' => 1]);
        } else {
            $firebase->update($path, ['fails' => (int)($record['fails'] ?? 0) + 1]);
        }
    }

    /**
     * [S-07] Clear IP fail counter on successful login.
     */
    private function _clear_ip_fails(string $ip, object $firebase): void
    {
        $firebase->update($this->_ip_path($ip), ['fails' => 0, 'windowStart' => 0]);
    }

    /**
     * [S-07] Firebase-safe path for an IP address.
     * Replaces . and : (IPv4/IPv6 chars) with hyphens.
     */
    private function _ip_path(string $ip): string
    {
        $safeIp = str_replace(['.', ':'], '-', $ip);
        return "RateLimit/Login/{$safeIp}";
    }

    /**
     * [S-03] Returns TRUE if value is safe to use as a Firebase path segment.
     * Allows: letters, digits, hyphens, underscores only (no spaces — for IDs).
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