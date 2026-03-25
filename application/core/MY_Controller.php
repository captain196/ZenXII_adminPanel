<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * MY_Controller — Secure base controller for all authenticated pages.
 *
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║  SECURITY FIXES                                                  ║
 * ╠══════════════════════════════════════════════════════════════════╣
 * ║  [FIX-1]  Auth guard — every child controller gets auth free     ║
 * ║  [FIX-2]  CSRF on all POST — Ajax gets JSON 403                 ║
 * ║  [FIX-3]  Firebase path sanitisation — safe_path_segment()      ║
 * ║  [FIX-4]  Session tamper check on school_name + session_year    ║
 * ║  [FIX-5]  No-cache + full security headers on every response    ║
 * ║  [FIX-6]  json_success() / json_error() with correct HTTP codes ║
 * ║  [FIX-7]  Ownership guard — assert_school_ownership()           ║
 * ╠══════════════════════════════════════════════════════════════════╣
 * ║  BUGS FIXED                                                      ║
 * ║  [BUG-1]  Sub live-check: wrong path (missing "Users/" prefix)  ║
 * ║  [BUG-2]  Sub live-check: wrong model ($this->CM → $this->firebase)║
 * ║  [BUG-3]  unset_userdata missed 'current_session','session_year'║
 * ╠══════════════════════════════════════════════════════════════════╣
 * ║  AUDIT FIXES (this revision)                                     ║
 * ║  [A-01]  Firebase downtime no longer kicks out users            ║
 * ║  [A-02]  Security headers added (X-Frame, CSP, etc.)            ║
 * ║  [A-03]  'current_session' shared to views (account_book fix)   ║
 * ║  [A-04]  SESSION_KEYS references Admin_login constant directly  ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */
class MY_Controller extends CI_Controller
{
    protected $admin_id;
    protected $school_id;
    protected $school_code;
    protected $school_display_name;
    protected $admin_role;
    protected $admin_name;
    protected $session_year;
    protected $school_name;
    protected $school_features;
    protected $available_sessions = [];
    /** Key for Users/Parents/{key}/ paths — school_code for legacy, school_id for SCH_ schools */
    protected $parent_db_key;

    /**
     * Routes that skip auth + CSRF checks.
     * Format: 'controller/method' lowercase.
     */
    protected $public_routes = [
        'admin_login/index',
        'admin_login/check_credentials',
        'admin_login/get_server_date',
    ];

    // ─────────────────────────────────────────────────────────────────────
    public function __construct()
    {
        parent::__construct();

        // ── HTTPS enforcement (PHP-level fallback if .htaccess redirect is not active) ──
        // Activated by FORCE_HTTPS=true in .env
        if (
            getenv('FORCE_HTTPS') === 'true'
            && (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on')
            && (!isset($_SERVER['HTTP_X_FORWARDED_PROTO']) || $_SERVER['HTTP_X_FORWARDED_PROTO'] !== 'https')
        ) {
            header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
            exit;
        }

        $this->load->library('firebase');
        $this->load->library('session');
        $this->load->helper('url');
        $this->load->helper('rbac');
        $this->load->helper('audit');

        // [FIX-5] + [A-02] Full security + no-cache headers
        $this->_send_security_headers();

        // ── Pull session data ─────────────────────────────────────────────
        $this->admin_id            = $this->session->userdata('admin_id');
        $this->school_id           = $this->session->userdata('school_id');       // SCH_XXXXXX
        $this->school_code         = $this->session->userdata('school_code');     // login code
        $this->admin_role          = $this->session->userdata('admin_role');
        $this->admin_name          = $this->session->userdata('admin_name');
        $this->session_year        = $this->session->userdata('session');         // legacy key
        $this->school_name         = $this->session->userdata('schoolName');      // = school_id (SCH_XXXXXX)
        $this->school_display_name = $this->session->userdata('school_display_name') ?? $this->school_name;
        $this->school_features     = $this->session->userdata('school_features');
        $this->available_sessions  = $this->session->userdata('available_sessions') ?? [];

        // For Users/Parents/ and Users/Admin/ paths:
        // school_code holds the login code (e.g. "10005") which is the Firebase
        // path key for Users/Admin/{code}/ and Users/Parents/{code}/.
        // Falls back to school_id for legacy schools without school_code.
        $this->parent_db_key = $this->school_code ?: $this->school_id;

        // ── Determine current route ───────────────────────────────────────
        $controller = strtolower($this->router->fetch_class());
        $method     = strtolower($this->router->fetch_method());
        $route_key  = $controller . '/' . $method;
        $is_public  = in_array($route_key, $this->public_routes, true);

        // ── [FIX-1] Authentication guard ─────────────────────────────────
        if (! $is_public) {
            if (! $this->admin_id || ! $this->school_id) {
                if ($this->input->is_ajax_request()) {
                    $this->json_error('Session expired. Please log in again.', 401);
                }
                redirect('admin_login');
            }

            // ── [FIX-4] Session tamper check ──────────────────────────────
            if (
                ! $this->_is_safe_segment((string) $this->school_name) ||
                ! $this->_is_safe_segment((string) $this->session_year)
            ) {
                log_message('error',
                    'MY_Controller: unsafe session — destroying. school_name=['
                    . $this->school_name . ']'
                );
                $this->session->sess_destroy();
                if ($this->input->is_ajax_request()) {
                    $this->json_error('Invalid session. Please log in again.', 401);
                }
                redirect('admin_login');
            }

            // ── Session year whitelist check ─────────────────────────────
            // If session_year is not in the cached whitelist, refresh from
            // Firebase before forcing logout — another admin may have added
            // a new session since this user logged in.
            $available = $this->available_sessions;
            if (!empty($available) && is_array($available)
                && !in_array($this->session_year, $available, true)) {
                // Refresh from Firebase
                $freshSessions = $this->firebase->get("Schools/{$this->school_name}/Sessions");
                if (is_array($freshSessions)) {
                    $freshSessions = array_values(array_filter($freshSessions, 'is_string'));
                    $this->session->set_userdata('available_sessions', $freshSessions);
                    $this->available_sessions = $freshSessions;
                }
                // Re-check after refresh
                if (!empty($freshSessions) && !in_array($this->session_year, $freshSessions, true)) {
                    log_message('error',
                        'MY_Controller: session_year [' . $this->session_year
                        . '] not in whitelist even after refresh — forcing logout.'
                    );
                    $this->_force_logout('Invalid academic session. Please log in again.');
                }
            }

            $now = time();

            // ── [BUG-1+2 FIX] [A-01] Live subscription re-check every 5 min ──
            //
            // [A-01] CRITICAL FIX: if Firebase is unreachable the library
            // returns null/false. We SKIP the check rather than kicking the user
            // out — a network blip must not end everyone's session.
            //
            $lastCheck = (int) $this->session->userdata('sub_check_ts');

            // Subscription re-check every 5 minutes.
            // Previously 60s — too aggressive, causes excessive Firebase reads and
            // contributes to "unreachable" errors under rate-limit pressure.
            if ($now - $lastCheck >= 300) {
                // Subscription status check — try new path, then legacy fallback
                $liveStatus = $this->firebase->get("System/Schools/{$this->school_id}/subscription/status");
                if ($liveStatus === null || $liveStatus === false || $liveStatus === '') {
                    $liveStatus = $this->firebase->get("Users/Schools/{$this->school_id}/subscription/status");
                }

                // [A-01] Only act if Firebase actually returned a value
                if ($liveStatus !== null && $liveStatus !== false && $liveStatus !== '') {
                    $liveStatus = (string) $liveStatus;
                    $this->session->set_userdata('sub_check_ts', $now);

                    // Case-insensitive comparison — onboarding may write 'active'/'Active'
                    if (! in_array(strtolower($liveStatus), ['active', 'grace_period'], true)) {
                        log_message('info',
                            "Sub status=[{$liveStatus}] school=[{$this->school_name}] — forcing logout."
                        );
                        $this->_force_logout(
                            'Your school subscription is no longer active. Please contact support.'
                        );
                    }

                    // ── Admin account status re-check (piggyback on same interval) ──
                    // If another admin disables this account mid-session, force logout.
                    if (!empty($this->admin_id) && !empty($this->parent_db_key)) {
                        $adminStatus = $this->firebase->get(
                            "Users/Admin/{$this->parent_db_key}/{$this->admin_id}/Status"
                        );
                        if (is_string($adminStatus) && strtolower($adminStatus) !== 'active') {
                            log_message('info',
                                "Admin status=[{$adminStatus}] admin=[{$this->admin_id}]"
                                . " school=[{$this->school_name}] — forcing logout."
                            );
                            $this->_force_logout(
                                'Your account has been deactivated. Please contact your administrator.'
                            );
                        }
                    }

                    // ── RBAC permission refresh (piggyback on same interval) ──
                    // If another admin changes this role's permissions, pick it up.
                    $freshPerms = load_role_permissions(
                        $this->firebase,
                        $this->school_name,
                        $this->admin_role ?? ''
                    );
                    $this->session->set_userdata('rbac_permissions', $freshPerms);
                } else {
                    // Firebase unreachable — update timestamp to avoid hammering
                    // Firebase on every request, but don't kick the user out.
                    log_message('error',
                        'MY_Controller: Firebase unreachable during sub check for school=['
                        . $this->school_name . ']. Skipping — will retry in 60s.'
                    );
                    $this->session->set_userdata('sub_check_ts', $now);
                }
            }

            // ── Subscription timestamp expiry / grace-period check ────────
            $subExpiry = (int) $this->session->userdata('subscription_expiry');
            $graceEnd  = (int) $this->session->userdata('subscription_grace_end');

            if ($subExpiry > 0 && $now > $subExpiry) {
                if ($graceEnd > 0 && $now > $graceEnd) {
                    $this->_force_logout(
                        'Your subscription has expired and the grace period has ended. Please renew to continue.'
                    );
                }

                // Still in grace period — refresh warning
                $daysLeft = max(1, (int) ceil(($graceEnd - $now) / 86400));
                $this->session->set_userdata('subscription_warning',
                    'Subscription expired. You have ' . $daysLeft
                    . ' day(s) of grace period remaining. Please renew immediately.'
                );
            }
        }

        // ── [FIX-2] CSRF on all non-public POST requests ──────────────────
        // Skip when CI's built-in csrf_protection is ON — it already verified
        // and removed the token from $_POST, so a second check would always fail.
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ! $is_public && ! config_item('csrf_protection')) {
            $token_name = $this->security->get_csrf_token_name();
            $token_hash = $this->security->get_csrf_hash();

            $sent = $this->input->post($token_name)
                 ?? $this->input->get_request_header('X-CSRF-Token', TRUE);

            if (!is_string($sent) || !hash_equals($token_hash, $sent)) {
                log_message('error',
                    'CSRF failure route=' . $route_key
                    . ' ip=' . $this->input->ip_address()
                );
                if ($this->input->is_ajax_request()) {
                    $this->json_error('Security token mismatch. Please refresh the page.', 403);
                }
                show_error('CSRF validation failed.', 403);
            }
        }

        // ── Normalize features: module keys → sidebar display names ────────
        // Superadmin stores features as keys ('student_management','fees',…)
        // but sidebar checks display names ('Student Management','Fees Management',…).
        $this->school_features = $this->_normalize_features($this->school_features);

        // ── [A-03] Share common vars with all views ───────────────────────
        // 'current_session' added so account_book.php gets the right variable.
        // ── [RBAC] Load permissions (cached in session at login) ────────
        $rbac_permissions = $this->session->userdata('rbac_permissions');
        if (!is_array($rbac_permissions)) {
            // First request after login or session doesn't have it yet — load now
            $rbac_permissions = load_role_permissions(
                $this->firebase,
                $this->school_name,
                $this->admin_role ?? ''
            );
            $this->session->set_userdata('rbac_permissions', $rbac_permissions);
        }

        $this->load->vars([
            'school_id'            => $this->school_id,           // SCH_XXXXXX
            'school_code'          => $this->school_code,         // login code
            'admin_id'             => $this->admin_id,
            'school_name'          => $this->school_display_name, // human name (for views)
            'school_display_name'  => $this->school_display_name, // human name (explicit)
            'school_firebase_key'  => $this->school_name,         // SCH_XXXXXX (for Firebase paths)
            'session_year'         => $this->session_year,
            'current_session'      => $this->session_year,        // [A-03] account_book.php reads this
            'available_sessions'   => $this->available_sessions,  // session switcher dropdown
            'admin_name'           => $this->admin_name,
            'admin_role'           => $this->admin_role,
            'school_features'      => $this->school_features,
            'rbac_permissions'     => $rbac_permissions,           // [RBAC] module permissions
            'subscription_warning' => $this->session->userdata('subscription_warning'),
        ]);
    }

    // =========================================================================
    //  PRIVATE HELPERS
    // =========================================================================

    /**
     * [A-02] Centralised security + no-cache headers.
     * Called from __construct so every authenticated page gets them.
     */
    private function _send_security_headers(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

        // ── HSTS — only sent over HTTPS to prevent header injection over HTTP ──
        // After initial testing, increase max-age to 31536000 (1 year).
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            header('Strict-Transport-Security: max-age=86400; includeSubDomains');
        }

        // H-03 FIX: Content-Security-Policy — restrict resource loading
        // Allows self, Google Fonts, Font Awesome CDN, DataTables, and inline styles/scripts
        // (inline needed for CI3 views with embedded <script>/<style> blocks)
        $csp = "default-src 'self'; "
            . "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://cdn.datatables.net; "
            . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://cdn.datatables.net https://api.fontshare.com; "
            . "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com https://cdn.fontshare.com; "
            . "img-src 'self' data: blob: https://*.googleapis.com https://*.firebasestorage.googleapis.com; "
            . "connect-src 'self' https://*.firebaseio.com https://*.firebasedatabase.app https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; "
            . "frame-ancestors 'none'; "
            . "base-uri 'self'; "
            . "form-action 'self';";

        // When HTTPS is active, add upgrade-insecure-requests to auto-upgrade
        // any leftover http:// references in views/templates
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            $csp = "upgrade-insecure-requests; " . $csp;
        }

        header("Content-Security-Policy: " . $csp);
    }

    /**
     * Convert module keys stored in Firebase to sidebar display names.
     *
     * Superadmin_schools saves features as module keys: 'student_management','fees',…
     * The sidebar (header.php) checks for display names: 'Student Management','Fees Management',…
     * This method bridges the gap, and also handles legacy schools that may already
     * have display names stored.
     */
    private function _normalize_features($features): array
    {
        if (!is_array($features) || empty($features)) return [];

        // Module key → sidebar display name(s) used in header.php in_array() checks
        $map = [
            'student_management' => ['Student Management'],
            'staff_management'   => ['Staff Management'],
            'fees'               => ['Fees Management'],
            'accounts'           => ['Account Management'],
            'exams'              => ['Exam Management'],
            'results'            => ['Exam Management'],
            'attendance'         => ['Student Management'],
            'notices'            => ['Notice and Announcement'],
            'gallery'            => ['School Management'],
            'timetable'          => ['School Management', 'Class Management'],
            'id_cards'           => ['Student Management'],
            'homework'           => ['School Management'],
            'sms_alerts'         => [],
            'parent_app'         => [],
            'teacher_app'        => [],
        ];

        $normalized = [];
        foreach ($features as $f) {
            $f = trim((string)$f);
            if ($f === '') continue;
            if (isset($map[$f])) {
                // Module key — map to display names
                foreach ($map[$f] as $name) {
                    $normalized[$name] = true;
                }
            } else {
                // Already a display name (legacy) or unknown — keep as-is
                $normalized[$f] = true;
            }
        }

        // Core features always available for any plan
        $normalized['School Management']  = true;
        $normalized['Class Management']   = true;
        $normalized['Subject Management'] = true;

        return array_keys($normalized);
    }

    /**
     * Clear all session keys and redirect to login.
     * Single method used by ALL forced-logout paths so keys stay in sync.
     * [A-04] References Admin_login::SESSION_KEYS — single source of truth.
     */
    private function _force_logout(string $errorMessage): void
    {
        // [A-04] Use Admin_login's SESSION_KEYS constant as the single source
        // of truth so both controllers always clear the exact same set of keys.
        $keys = class_exists('Admin_login')
            ? Admin_login::SESSION_KEYS
            : [
                'admin_id', 'school_id', 'school_code', 'admin_role', 'admin_name',
                'session', 'current_session', 'session_year',
                'schoolName', 'school_display_name', 'school_features',
                'subscription_expiry', 'subscription_grace_end', 'subscription_warning',
                'sub_check_ts', 'login_csrf',
            ];

        $this->session->unset_userdata($keys);

        if ($this->input->is_ajax_request()) {
            $this->json_error($errorMessage, 403);
        }

        $this->session->set_flashdata('error', $errorMessage);
        redirect('admin_login');
    }

    // =========================================================================
    //  [FIX-3] FIREBASE PATH SANITISATION
    // =========================================================================

    /**
     * Validate and return a value safe to embed in a Firebase RTDB path.
     * Blocked: / . # $ [ ] and anything Firebase forbids.
     *
     * Usage:
     *   $class = $this->safe_path_segment($this->input->post('class'), 'class');
     *   $path  = "Schools/{$this->school_id}/{$this->session_year}/{$class}";
     */
    protected function safe_path_segment(string $value, string $field = 'value'): string
    {
        $value = trim($value);

        if ($value === '') {
            $this->json_error("Missing required field: {$field}", 400);
        }

        if (! $this->_is_safe_segment($value)) {
            log_message('error',
                "Unsafe Firebase segment [{$field}]=[{$value}] ip="
                . $this->input->ip_address()
            );
            $this->json_error("Invalid characters in field: {$field}", 400);
        }

        return $value;
    }

    /**
     * TRUE if value contains only safe Firebase key characters.
     * Allows: letters, digits, spaces, hyphens, underscores, apostrophes, commas.
     * (School names like "Maharishi Vidhya Mandir, Balaghat" need spaces + commas.)
     */
    private function _is_safe_segment(string $value): bool
    {
        return $value !== '' && (bool) preg_match("/^[A-Za-z0-9 ',_\-]+$/u", $value);
    }

    // =========================================================================
    //  [FIX-7] OWNERSHIP GUARD
    // =========================================================================

    /**
     * Abort 403 if the given school_name doesn't match the session.
     * Call before any cross-school Firebase read/write.
     */
    protected function assert_school_ownership(string $school_name): void
    {
        if ($school_name !== $this->school_name) {
            log_message('error',
                "Ownership violation: session=[{$this->school_name}]"
                . " tried=[{$school_name}] admin=[{$this->admin_id}]"
            );
            $this->json_error('Access denied.', 403);
        }
    }

    // =========================================================================
    //  [FIX-6] JSON RESPONSE HELPERS
    // =========================================================================

    protected function json_success(array $data = []): void
    {
        header('Content-Type: application/json');
        $data['csrf_token'] = $this->security->get_csrf_hash();
        echo json_encode(array_merge(['status' => 'success'], $data));
        exit;
    }

    protected function json_error(string $message, int $http_code = 400): void
    {
        http_response_code($http_code);
        header('Content-Type: application/json');
        echo json_encode([
            'status'     => 'error',
            'message'    => $message,
            'csrf_token' => $this->security->get_csrf_hash(),
        ]);
        exit;
    }

    // =========================================================================
    //  ROLE-BASED ACCESS CONTROL
    // =========================================================================

    /**
     * Abort 403 if the current user's role is not in the allowed list.
     *
     * @param array $allowed  e.g. ['Super Admin', 'Admin']
     * @param string $action  Human-readable action name for log/message
     */
    protected function _require_role(array $allowed, string $action = ''): void
    {
        $role = $this->admin_role ?? '';

        // Super Admin and School Super Admin always pass
        if (strcasecmp($role, 'Super Admin') === 0) return;
        if (strcasecmp($role, 'School Super Admin') === 0) return;

        // Case-insensitive role match (Firebase role values may vary in casing)
        foreach ($allowed as $a) {
            if (strcasecmp($role, $a) === 0) return;
        }

        $label = $action ? " ({$action})" : '';
        log_message('error',
            "RBAC denied: role=[{$role}] admin=[{$this->admin_id}]"
            . " school=[{$this->school_name}]{$label}"
        );

        if ($this->input->is_ajax_request()) {
            $this->json_error('You do not have permission to perform this action.', 403);
        }

        // Redirect to dashboard instead of showing a harsh 403 error page
        $this->session->set_flashdata('error', 'You do not have access to that page.');
        redirect('admin/index');
    }

    /**
     * Load the current teacher's class/subject assignments from Duties.
     *
     * Firebase path: Schools/{school}/{year}/Teachers/{adminId}/Duties
     * Structure:     {DutyType}/{classSection}/{subject}: time
     *   e.g.  SubjectTeacher / Class 9th 'A' / Mathematics : "09:00-10:00"
     *
     * Returns a flat set of normalised keys the teacher is assigned to:
     *   ['Class 9th|Section A'            => true,   // class+section access
     *    'Class 9th|Section A|Mathematics' => true ]  // class+section+subject access
     *
     * Result is cached on the instance so repeated calls within one request are free.
     *
     * @return array  Associative [key => true] for fast isset() lookups
     */
    protected function _get_teacher_assignments(): array
    {
        // Instance cache
        if (isset($this->_teacher_assign_cache)) {
            return $this->_teacher_assign_cache;
        }

        $school = $this->school_name;
        $year   = $this->session_year;
        $tid    = $this->admin_id;
        $map    = [];

        $duties = $this->firebase->get("Schools/{$school}/{$year}/Teachers/{$tid}/Duties");
        if (!is_array($duties)) {
            $this->_teacher_assign_cache = $map;
            return $map;
        }

        foreach ($duties as $dutyType => $classes) {
            if (!is_array($classes)) continue;
            foreach ($classes as $classSection => $subjects) {
                // classSection = "Class 9th 'A'"
                // Parse → classKey="Class 9th", sectionLetter="A"
                if (preg_match("/^(.+?)\\s*'([^']*)'\\s*$/", $classSection, $m)) {
                    $classKey      = trim($m[1]);  // "Class 9th"
                    $sectionLetter = trim($m[2]);  // "A"
                } else {
                    $classKey      = $classSection;
                    $sectionLetter = '';
                }
                $sectionKey = $sectionLetter ? "Section {$sectionLetter}" : '';
                $csKey      = "{$classKey}|{$sectionKey}";

                $map[$csKey] = true;

                if (is_array($subjects)) {
                    foreach (array_keys($subjects) as $subject) {
                        $map["{$csKey}|{$subject}"] = true;
                    }
                }
            }
        }

        $this->_teacher_assign_cache = $map;
        return $map;
    }

    /**
     * Check if the current teacher is assigned to the given class/section (and optionally subject).
     * Non-Teacher roles always return true (they have full access).
     */
    protected function _teacher_can_access(string $classKey, string $sectionKey, string $subject = ''): bool
    {
        if (($this->admin_role ?? '') !== 'Teacher') return true;

        $assignments = $this->_get_teacher_assignments();
        $csKey = "{$classKey}|{$sectionKey}";

        // Must at least be assigned to this class+section
        if (!isset($assignments[$csKey])) return false;

        // If a subject check is requested, verify that too
        if ($subject !== '' && !isset($assignments["{$csKey}|{$subject}"])) return false;

        return true;
    }

    /**
     * Enumerate all class-sections for the current session.
     *
     * Uses shallow_get to read session root keys, filters for "Class " nodes,
     * then reads each class's children for "Section " nodes.
     *
     * @return array  List of ['class_key'=>'Class 9th', 'section'=>'A',
     *                         'label'=>'Class 9th / Section A',
     *                         'class_section'=>"Class 9th 'A'"]
     */
    protected function _get_session_classes(): array
    {
        $school  = $this->school_name;
        $session = $this->session_year;
        $classes = [];

        $keys = $this->firebase->shallow_get("Schools/{$school}/{$session}");
        if (!is_array($keys)) return $classes;

        foreach ($keys as $key) {
            if (strpos($key, 'Class ') !== 0) continue;
            $sectionKeys = $this->firebase->shallow_get("Schools/{$school}/{$session}/{$key}");
            if (!is_array($sectionKeys)) continue;
            foreach ($sectionKeys as $sk) {
                if (strpos($sk, 'Section ') !== 0) continue;
                $secLetter = str_replace('Section ', '', $sk);
                $classes[] = [
                    'class_key'     => $key,
                    'section'       => $secLetter,
                    'label'         => $key . ' / Section ' . $secLetter,
                    'class_section' => $key . " '" . $secLetter . "'",
                ];
            }
        }
        return $classes;
    }
}