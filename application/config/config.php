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

// ─────────────────────────────────────────────────────────────────────────────
//  TIMEZONE — pinned, because the financial DATE depends on it
// ─────────────────────────────────────────────────────────────────────────────
// ZenX pinned no timezone and inherited whatever the host had. Receipts,
// journal entries, demand due-dates and processed-payment markers are all
// stamped with date('c') / date('d-m-Y'), so the host's timezone decided the
// ACCOUNTING DATE of a payment.
//
// Demonstrated: the first live payment was stamped 2026-08-26T06:18:07+02:00
// on a dev box running Europe/Berlin. The instant was right, the offset was
// not — and on a UTC host (production) it is worse than cosmetic:
//
//     a payment at 00:30 IST on 27 Aug is recorded as 26 Aug
//
// which files it in the wrong accounting day, and across a month boundary in
// the wrong accounting MONTH. Every Indian school this serves closes its books
// on IST dates.
//
// Overridable via APP_TIMEZONE for anyone running outside India, but it now
// always resolves to an EXPLICIT zone rather than an accident of the host.
$_appTz = getenv('APP_TIMEZONE') ?: 'Asia/Kolkata';
if (in_array($_appTz, timezone_identifiers_list(), true)) {
    date_default_timezone_set($_appTz);
}
unset($_appTz);

$_is_https = (
    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
);
// SEC-13 FIX: Validate HTTP_HOST against allowlist to prevent host-header injection
$_allowed_hosts = ['localhost', '127.0.0.1', 'localhost:8080', 'localhost:8000'];
if (getenv('APP_HOST')) $_allowed_hosts[] = getenv('APP_HOST');

// Canonical public (parent-facing) base URL — see the block below. Resolve it
// FIRST so its host can also be trusted for base_url detection: whatever host
// the app is publicly served on (LAN IP for phone testing / ngrok / the prod
// domain) is by definition a legitimate host, so the admission form submits back
// to the SAME host the parent reached it on instead of falling back to localhost.
$_public_base_url = getenv('PUBLIC_BASE_URL')
    ?: (isset($_ENV['PUBLIC_BASE_URL']) ? $_ENV['PUBLIC_BASE_URL'] : 'https://www.zenxii.com/');
$_pub_host = parse_url($_public_base_url, PHP_URL_HOST);
if ($_pub_host) {
    $_pub_port = parse_url($_public_base_url, PHP_URL_PORT);
    $_allowed_hosts[] = $_pub_host;
    if ($_pub_port) $_allowed_hosts[] = $_pub_host . ':' . $_pub_port;
}

$_host = (isset($_SERVER['HTTP_HOST']) && in_array($_SERVER['HTTP_HOST'], $_allowed_hosts, true))
    ? $_SERVER['HTTP_HOST']
    : 'localhost';
// Detect subdirectory from SCRIPT_NAME (e.g., /ZenX/school/index.php → /ZenX/school/)
$_subdir = '';
if (isset($_SERVER['SCRIPT_NAME'])) {
    $_subdir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
}
$config['base_url'] =
    ($_is_https ? 'https' : 'http')
    . '://' . $_host
    . $_subdir;

// ─────────────────────────────────────────────────────────────────────────────
//  PUBLIC BASE URL  — the canonical, parent-facing origin.
//  `base_url` above reflects whatever host the current request came in on
//  (localhost / LAN IP / ngrok during dev). That is WRONG for anything a
//  parent sees or that gets PRINTED (QR posters, the shareable admission link):
//  those must always resolve to the public host regardless of where the admin
//  happens to be browsing from. Production default mirrors the parent app's
//  BASE_URL (`https://www.zenxii.com/`, served at domain root behind Cloudflare);
//  override per-deployment via the PUBLIC_BASE_URL env var (see .env) — e.g. a
//  LAN IP or ngrok URL for local phone testing.
// ─────────────────────────────────────────────────────────────────────────────
$config['public_base_url'] = $_public_base_url;

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
// PERF: info/debug logging writes 50–120 flock'd file appends per hot request.
// Env-overridable so you can flip to 2 (info) temporarily for debugging without a redeploy.
$config['log_threshold']        = (int) (getenv('LOG_THRESHOLD') ?: 1);   // 1 = errors only (production default)
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
// ── SEC-CFG-1 · THE ENVIRONMENT SIGNAL CANNOT BE TRUSTED HERE ───────────────
// This guard fired only when ENVIRONMENT === 'production'. index.php resolves
// that as `$_SERVER['CI_ENV'] ?? 'development'`, so it depends on
// `SetEnv CI_ENV production` living in the production Apache config — a file
// deliberately not in git. If it is ever absent, ENVIRONMENT silently becomes
// 'development', this check does not fire, and the fallback below becomes the
// live CI encryption key: a value published in this repository, protecting
// sessions and encrypted cookies.
//
// Inverted to require an EXPLICIT non-production environment, so an unset or
// unexpected value denies. That is a real improvement but NOT a complete fix:
// if CI_ENV is missing the value reads 'development' and still passes. The
// code cannot reliably detect production when the only signal is the very
// variable that is missing.
//
// So the fallback is additionally made LOUD. A silent weak key is the dangerous
// condition; an observable one can be caught in a log sweep. error_log() rather
// than log_message() because CI's logger is not yet available at config time.
//
// The real fix is operational — guaranteeing CI_ENV is set on the host — and is
// recorded as SEC-CFG-1. It is a PLATFORM security issue, not a Billing V2 one,
// and is deliberately not being closed inside a fees workstream.
$_encKey = getenv('ENCRYPTION_KEY');
if (empty($_encKey) && !in_array(ENVIRONMENT, ['development', 'testing'], true)) {
    show_error('ENCRYPTION_KEY environment variable is required outside development.', 500);
}
if (empty($_encKey)) {
    error_log('[SEC-CFG-1] ENCRYPTION_KEY is not set — falling back to the PUBLISHED '
        . 'development key. ENVIRONMENT=' . (defined('ENVIRONMENT') ? ENVIRONMENT : 'undefined')
        . '. If this appears on a live host, sessions and encrypted cookies are protected '
        . 'by a key that is in the repository.');
}
$config['encryption_key'] = $_encKey ?: 'CHANGE_ME_IN_ENV';

/* ── IDC-SECRET-1 (2026-09-19) — ID-card QR signing secret ─────────────────
 *
 * Id_card_service mints every QR through qr_token_encode_v2(), which HMACs with
 * _qr_secret(). That helper reads THIS key first and falls back to
 * `encryption_key` — so while this is empty, every printed ID card in every
 * school is signed with the session/cookie key.
 *
 * The coupling matters at exactly one moment: ROTATION. Rotating ENCRYPTION_KEY
 * is a routine security action, and doing it today silently invalidates every
 * ID card already in a student's hand. Nothing errors — the QRs simply stop
 * validating, and the cause is two config layers away from the symptom.
 *
 * Setting this gives card credentials their own rotation story, independent of
 * session security. LEFT EMPTY DELIBERATELY: filling it in re-signs every card
 * minted afterwards, so previously issued cards would need reissuing. That is an
 * owner decision and a migration, not a config tweak — do it BEFORE cards are
 * printed at scale, or plan the reissue.
 *
 * Generate with: php -r "echo bin2hex(random_bytes(32));"
 */
$config['qr_token_secret'] = (string) (getenv('QR_TOKEN_SECRET') ?: '');
// Fallback is for local development only. Generate a production key with:
// php -r "echo bin2hex(random_bytes(32));"

// ─────────────────────────────────────────────────────────────────────────────
//  Phase: Discount/Concession Journaling (Stage 1, 2026-05-10)
//  Feature flag for the additive concessions[] payload in
//  Operations_accounting::_buildGranularLines. Default false: existing
//  receipt flows produce byte-identical journals to the pre-concession
//  engine. Operator opts in when the CoA has the contra-revenue accounts
//  configured (4990-4998 range or 5190 fallback) and the fee-counter UI
//  emits the new payload field.
// ─────────────────────────────────────────────────────────────────────────────
$config['concessions_enabled'] = (string) (getenv('CONCESSIONS_ENABLED') ?: '') === '1';

// ─────────────────────────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────────────
//  SESSION SETTINGS
//  FIX: secure cookies, strict SameSite, HttpOnly, longer expiry
// ─────────────────────────────────────────────────────────────────────────────
// PERF: env-switchable so sessions can move to Redis (which unblocks the file-lock
// that serializes a user's concurrent AJAX) WITHOUT a code change. Default stays
// 'files' — behaviour is identical unless SESS_DRIVER is set AND Redis is running.
$config['sess_driver']           = getenv('SESS_DRIVER') ?: 'files';
$config['sess_cookie_name']      = 'grader_session';     // ← not the default ci_session
$config['sess_samesite']         = 'Lax';              // Lax = safe default; Strict breaks redirects from external links
$config['sess_expiration']       = 28800;                // 8 hours — comfortable for a full work day

// FIX: Use a DEDICATED session directory instead of shared system temp.
// The shared temp dir allows other PHP apps to trigger garbage collection
// on our session files via their own gc_maxlifetime (often just 24 min).
// A dedicated dir ensures only our gc_maxlifetime setting applies.
$_sess_dir = APPPATH . 'sessions';
if (!is_dir($_sess_dir)) { @mkdir($_sess_dir, 0700, true); }
// For the Redis driver set SESS_SAVE_PATH to a connection string, e.g.
//   SESS_SAVE_PATH="tcp://127.0.0.1:6379"   (needs the phpredis extension + redis-server).
// Default (files driver) keeps using the dedicated directory above.
$config['sess_save_path']        = getenv('SESS_SAVE_PATH') ?: $_sess_dir;

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
    // Rollout flags (T3, 2026-08-18). Same reason as every entry above: the SA
    // panel runs its OWN CSRF layer (MY_Superadmin_Controller::_verify_csrf)
    // against a token in the authenticated SA session, and CI3's cookie-based
    // check collides with it because the SA and school-admin panels share a
    // cookie domain. Without this line the endpoints return a blank 403 with
    // no log and no console error.
    'superadmin/rollout(.*)',
    // Maps Platform (2026-08-16). Every superadmin section is excluded here for
    // a specific reason, not for convenience: the SA panel runs its OWN CSRF
    // layer (MY_Superadmin_Controller::_verify_csrf) which validates the POST
    // field `csrf_token` against the SESSION token published in sa_header's
    // meta tag. CI's global CSRF wants the same field name with its own cookie
    // value, so leaving both active makes the two layers fight and every SA
    // POST fails. Excluding CI's layer does NOT remove CSRF protection — the SA
    // layer still enforces it, with a 64-char session token and hash_equals.
    'superadmin/maps(.*)',
    'superadmin/admins(.*)',
    'superadmin/school_admins(.*)',
    'superadmin/bootstrap(.*)',
    'superadmin/login(.*)',
    'superadmin/change_my_password(.*)',
    'superadmin/csrf_token',
    'fee_management/payment_webhook',
    'fee_management/parent_create_order',
    'fee_management/parent_verify_payment',
    // Admission payment gateway server-to-server webhook (HMAC-authenticated,
    // no admin session/CSRF — mirrors fee_management/payment_webhook).
    // Regex form REQUIRED: the route is admission/payment_webhook/(:any)
    // (school_id param), and CI3 matches csrf_exclude_uris as ^…$ — an exact
    // string would not exempt admission/payment_webhook/SCH_XXX → CSRF 403.
    'admission/payment_webhook(.*)',
    // Mobile API endpoints (Firebase ID-token authenticated, not CSRF-protected)
    'auth/(.*)',
    // Phase F (2026-08-08) — parent-facing entitlement requests from the Parent
    // app. Firebase Bearer-token authenticated (Entitlement_requests::_auth),
    // so there is no admin session and no CSRF field to send; without this
    // exclusion CI3 rejects the POST with a blank 403 — no log, no console
    // error. Authorization is NOT weakened: the token is verified and
    // per-student ownership re-checked server-side on every call.
    'entitlement_request/(.*)',
    'staff_attendance/(.*)',
    // F13.1 M3 / D-8 (2026-08-08) — canonical driver trip lifecycle. The
    // Teacher app authenticates with a Firebase Bearer token and holds no CI
    // session, so it cannot carry a CSRF token; without this exclusion CI's
    // global CSRF returns a blank 403 with no log and no console error.
    // Authorization is NOT weakened: Api_auth verifies the token and every
    // endpoint re-checks that the caller owns the trip (driver_staff_id ==
    // token uid) before touching the lifecycle.
    'transport_driver_api/(.*)',
    // Teacher app attendance POSTs (Bearer-token authenticated via _bearer_auth_bridge)
    'attendance/save',
    'attendance/correction/submit',
    // Admin-panel staff-regularization LIST (read-only AJAX, also hit by the
    // Approvals badge). `decide` is deliberately NOT excluded — it stamps payroll
    // marks, so it stays CSRF-protected; the view sends the token on every POST.
    'attendance/staff_regularization/list',
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
