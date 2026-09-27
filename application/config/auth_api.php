<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
|--------------------------------------------------------------------------
| Auth API Configuration
|--------------------------------------------------------------------------
| Unified authentication API (Node.js + MongoDB).
| Web login: PHP calls /internal/web-login, then creates PHP session.
| Mobile login: Apps call /mobile/login directly for JWT tokens.
|
| Set environment variables AUTH_API_URL and AUTH_INTERNAL_SECRET
| in production. The fallback values are for local development only.
*/

$config['auth_api_base_url']        = getenv('AUTH_API_URL') ?: 'http://localhost:3000';

// SECURITY: Internal key MUST come from environment variable in production.
// Fallback for local dev only — remove if running in production.
// ── SEC-CFG-1 · SAME FAIL-OPEN SHAPE, HIGHER STAKES ─────────────────────────
// The fallback below is not a placeholder — it is a REAL shared secret,
// published in this repository, used to authenticate this panel to the Node
// auth backend. The guard fired only when ENVIRONMENT === 'production', which
// index.php derives from `$_SERVER['CI_ENV'] ?? 'development'`. If CI_ENV is
// absent on the host, ENVIRONMENT reads 'development', the check does not fire,
// and the panel authenticates with a key anyone can read.
//
// Inverted to require an EXPLICIT non-production environment. As with the
// encryption key, that is an improvement rather than a cure: a missing CI_ENV
// still resolves to 'development'. The fallback is therefore also made LOUD, so
// the condition is discoverable in logs instead of silent.
//
// Recorded as SEC-CFG-1 — a PLATFORM security issue whose real fix is
// operational (guarantee CI_ENV, then retire the committed fallbacks entirely).
// Not closed inside a Billing V2 workstream.
$_internalKey = getenv('AUTH_INTERNAL_SECRET');
if (empty($_internalKey) && !in_array(ENVIRONMENT, ['development', 'testing'], true)) {
    show_error('AUTH_INTERNAL_SECRET environment variable is required outside development.', 500);
}
if (empty($_internalKey)) {
    error_log('[SEC-CFG-1] AUTH_INTERNAL_SECRET is not set — falling back to the PUBLISHED '
        . 'development secret. ENVIRONMENT=' . (defined('ENVIRONMENT') ? ENVIRONMENT : 'undefined')
        . '. If this appears on a live host, the internal auth channel is protected by a '
        . 'secret that is in the repository.');
}
$config['auth_api_internal_key']    = $_internalKey ?: '8b9bb3bd40f1757454022b5bed876753b1183e30317674d1c4380a8c9284e9cf';
// PERF: A 120s ceiling on the login path let a slow/unreachable backend hang the
// request (and pin an Apache worker) for up to two minutes. Fast, tunable timeouts:
//   - connect_timeout fails fast (5s) when the backend is down/unreachable.
//   - total timeout (20s) leaves headroom for a WARM request while never hanging users.
// If the Node backend runs on a cold-starting host (e.g. Render, ~90s first hit),
// keep it warm (a lightweight cron ping) rather than raising these back to 120s.
// Both are env-overridable so ops can tune without a code change / redeploy.
$config['auth_api_timeout']         = (int) (getenv('AUTH_API_TIMEOUT') ?: 20);          // total request timeout (seconds)
$config['auth_api_connect_timeout'] = (int) (getenv('AUTH_API_CONNECT_TIMEOUT') ?: 5);   // connection timeout (seconds)
