<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// ─────────────────────────────────────────────────────────────────────────────
//  BASE URL  — change to your live domain before deployment
// ─────────────────────────────────────────────────────────────────────────────

// $config['base_url'] = 'http://localhost/Grader/school/';   // ← update this

// R5-SEC-3 FIX: Load .env EARLY so APP_HOST is available for host allowlist below
if (defined('FCPATH') && file_exists(FCPATH . '.env')) {
    $_envLines = file(FCPATH . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($_envLines as $_envLine) {
        $_envLine = trim($_envLine);
        if ($_envLine === '' || $_envLine[0] === '#') continue;
        if (strpos($_envLine, '=') === false) continue;
        list($_envKey, $_envVal) = array_map('trim', explode('=', $_envLine, 2));
        $_envVal = trim($_envVal, '"\'');
        if (!array_key_exists($_envKey, $_ENV)) {
            $_ENV[$_envKey] = $_envVal;
            putenv("{$_envKey}={$_envVal}");
        }
    }
    unset($_envLines, $_envLine, $_envKey, $_envVal);
}

$_is_https = (
    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
);
// SEC-13 FIX: Validate HTTP_HOST against allowlist to prevent host-header injection
$_allowed_hosts = ['localhost', '127.0.0.1', 'localhost:8080', 'localhost:8000'];
if (getenv('APP_HOST')) $_allowed_hosts[] = getenv('APP_HOST');
$_host = (isset($_SERVER['HTTP_HOST']) && in_array($_SERVER['HTTP_HOST'], $_allowed_hosts, true))
    ? $_SERVER['HTTP_HOST']
    : 'localhost';
// Detect subdirectory from SCRIPT_NAME (e.g., /Grader/school/index.php → /Grader/school/)
$_subdir = '';
if (isset($_SERVER['SCRIPT_NAME'])) {
    $_subdir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
}
$config['base_url'] =
    ($_is_https ? 'https' : 'http')
    . '://' . $_host
    . $_subdir;

$config['index_page'] = '';
$config['uri_protocol'] = 'REQUEST_URI';
$config['url_suffix'] = '';
$config['language'] = 'english';
$config['charset'] = 'UTF-8';
$config['enable_hooks'] = TRUE;
$config['subclass_prefix'] = 'MY_';
$config['composer_autoload'] = TRUE;
$config['composer_autoload'] = realpath(APPPATH . '../vendor/autoload.php');

// ─────────────────────────────────────────────────────────────────────────────
//  ALLOWED URI CHARACTERS
//  Apostrophes needed for class names like "8th 'A'"
// ─────────────────────────────────────────────────────────────────────────────
$config['permitted_uri_chars'] = "a-z 0-9~%.:_\\-\\'\\+\\ ";

$config['enable_query_strings'] = FALSE;
$config['controller_trigger']   = 'c';
$config['function_trigger']     = 'm';
$config['directory_trigger']    = 'd';
$config['allow_get_array']      = TRUE;

// ─────────────────────────────────────────────────────────────────────────────
//  ERROR LOGGING
//  FIX: Set to 1 (errors only) on production — level 4 fills disk fast
// ─────────────────────────────────────────────────────────────────────────────
$config['log_threshold']        = 2;   // TESTING: errors + info (revert to 1 for production)
$config['log_path']             = '';
$config['log_file_extension']   = '';
$config['log_file_permissions'] = 0644;
$config['log_date_format']      = 'Y-m-d H:i:s';

$config['error_views_path']  = '';
$config['cache_path']        = '';
$config['cache_query_string'] = FALSE;

// ─────────────────────────────────────────────────────────────────────────────
//  ENCRYPTION KEY
//  Loaded from .env file — NEVER hardcode in source code.
//  Generate with: php -r "echo bin2hex(random_bytes(32));"
// ─────────────────────────────────────────────────────────────────────────────
// .env already loaded at top of file (R5-SEC-3 FIX)
$_encKey = getenv('ENCRYPTION_KEY');
if (empty($_encKey) && ENVIRONMENT === 'production') {
    show_error('ENCRYPTION_KEY environment variable is required in production.', 500);
}
$config['encryption_key'] = $_encKey ?: 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';
// Fallback is for local development only. Generate a production key with:
// php -r "echo bin2hex(random_bytes(32));"

// ─────────────────────────────────────────────────────────────────────────────
//  SESSION SETTINGS
//  FIX: secure cookies, strict SameSite, HttpOnly, longer expiry
// ─────────────────────────────────────────────────────────────────────────────
$config['sess_driver']           = 'files';
$config['sess_cookie_name']      = 'grader_session';     // ← not the default ci_session
$config['sess_samesite']         = 'Lax';              // Lax = safe default; Strict breaks redirects from external links
$config['sess_expiration']       = 28800;                // 8 hours — comfortable for a full work day

// FIX: Use a DEDICATED session directory instead of shared system temp.
// The shared temp dir allows other PHP apps to trigger garbage collection
// on our session files via their own gc_maxlifetime (often just 24 min).
// A dedicated dir ensures only our gc_maxlifetime setting applies.
$_sess_dir = APPPATH . 'sessions';
if (!is_dir($_sess_dir)) { @mkdir($_sess_dir, 0700, true); }
$config['sess_save_path']        = $_sess_dir;

$config['sess_match_ip']         = FALSE;  // Disabled: localhost flips between 127.0.0.1/::1 causing logouts. Re-enable in production with stable IPs.
$config['sess_time_to_update']   = 600;   // regenerate every 10 min (was 5 min — too aggressive, causes AJAX race conditions)
$config['sess_regenerate_destroy'] = FALSE;              // keep old session briefly to avoid AJAX race on regenerate

// NOTE: CI3's Session library already calls ini_set('session.gc_maxlifetime', sess_expiration).
// The key fix is the DEDICATED directory above — the shared temp dir (sys_get_temp_dir)
// was the root cause: other PHP apps (phpMyAdmin, etc.) run GC with their own
// gc_maxlifetime=1440 (24 min default) and delete OUR session files prematurely.
// A dedicated dir ensures only our app's GC settings apply.

// ─────────────────────────────────────────────────────────────────────────────
//  COOKIE SETTINGS
//  FIX: httponly=TRUE prevents JS access to session cookie
//       secure=TRUE forces HTTPS-only cookie (enable when SSL is active)
// ─────────────────────────────────────────────────────────────────────────────
$config['cookie_prefix']   = '';
$config['cookie_domain']   = '';
$config['cookie_path']     = '/';
$config['cookie_secure']   = $_is_https;  // Auto-enabled when serving over HTTPS
$config['cookie_httponly'] = TRUE;    // FIX: prevents JavaScript from reading the cookie
$config['cookie_samesite'] = 'Strict'; // M-08 FIX: Strict prevents cross-site cookie leakage

$config['standardize_newlines']  = FALSE;
$config['global_xss_filtering']  = TRUE;   // SEC-2 FIX: apply XSS filter to all input globally

// ─────────────────────────────────────────────────────────────────────────────
//  CSRF PROTECTION
//  FIX: ENABLED. csrf_regenerate=FALSE is correct for AJAX apps — setting TRUE
//       causes token mismatch when multiple tabs are open.
// ─────────────────────────────────────────────────────────────────────────────
$config['csrf_protection']  = TRUE;
$config['csrf_token_name']  = 'csrf_token';
$config['csrf_cookie_name'] = 'csrf_token';
$config['csrf_expire']      = 7200;
$config['csrf_regenerate']  = FALSE;   // Keep FALSE for AJAX apps

// ── SA panel CSRF handled by MY_Superadmin_Controller (session-based, not cookie). ──
// The SA and school-admin panels share the same cookie domain/name, so CI3's
// cookie-based check collides when both are open.  We exclude all SA routes
// (except the login form POST) and verify CSRF manually in the base controller
// using a token stored in the authenticated SA session.
$config['csrf_exclude_uris'] = [
    'superadmin/dashboard(.*)',
    'superadmin/schools(.*)',
    'superadmin/plans(.*)',
    'superadmin/reports(.*)',
    'superadmin/monitor(.*)',
    'superadmin/backups(.*)',
    'superadmin/debug(.*)',
    'superadmin/migration(.*)',
    'superadmin/admins(.*)',
    'superadmin/bootstrap(.*)',
    'superadmin/login(.*)',
    'superadmin/csrf_token',
    'fee_management/payment_webhook',
    'fee_management/parent_create_order',
    'fee_management/parent_verify_payment',
];

$config['compress_output'] = FALSE;
$config['time_reference']  = 'local';
$config['rewrite_short_tags'] = FALSE;
$config['proxy_ips'] = '';

// ─────────────────────────────────────────────────────────────────────────────
//  FEES MODULE — Migration Flags
// ─────────────────────────────────────────────────────────────────────────────
// Phase 1: Set to FALSE to stop writing legacy Month Fee flags.
//          Demand-based system becomes single source of truth.
//          Month Fee is still READ for backward compat (dashboard, reports).
// Phase 2: Remove all Month Fee reads (future).
$config['use_legacy_month_fee'] = false;

// ── Firestore Failure Simulation ───────────────────────────────────────
// Set to TRUE to simulate Firestore write failures.
// RTDB writes continue normally. Firestore writes return false with logged error.
// Used for testing retry queue, fallback behavior, and system resilience.
// NEVER enable in production.
$config['simulate_firestore_failure'] = false;
