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
$_internalKey = getenv('AUTH_INTERNAL_SECRET');
if (empty($_internalKey) && ENVIRONMENT === 'production') {
    show_error('AUTH_INTERNAL_SECRET environment variable is required in production.', 500);
}
$config['auth_api_internal_key']    = $_internalKey ?: '8b9bb3bd40f1757454022b5bed876753b1183e30317674d1c4380a8c9284e9cf';
$config['auth_api_timeout']         = 120;  // total request timeout (seconds) — Render cold start can take 90s+
$config['auth_api_connect_timeout'] = 120;  // connection timeout (seconds)
