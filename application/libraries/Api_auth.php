<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Api_auth — Firebase ID token verification middleware for API endpoints.
 *
 * Verifies the `Authorization: Bearer <token>` header on API requests
 * from Android apps (Parent + Teacher). Extracts uid, role, school_id
 * from Firebase custom claims.
 *
 * Usage in controllers:
 *
 *   $this->load->library('api_auth');
 *   $user = $this->api_auth->require_auth();          // dies with 401 if invalid
 *   $this->api_auth->require_role(['Admin','Teacher']); // dies with 403 if wrong role
 *   $this->api_auth->require_school($school_id);       // dies with 403 if wrong school
 *
 * For controllers extending MY_Controller (session-based):
 *   Use this ONLY for endpoints that also accept Bearer tokens from mobile apps.
 *   Session-based auth is handled by MY_Controller's constructor.
 *
 * For pure API controllers (no session):
 *   Use this as the sole authentication mechanism.
 */
class Api_auth
{
    /** @var object Firebase library */
    private $firebase;

    /** @var array|null Cached claims from the current request */
    private $claims = null;

    /** @var bool Whether authentication has been attempted this request */
    private $checked = false;

    public function __construct()
    {
        $CI =& get_instance();
        if (!isset($CI->firebase)) {
            $CI->load->library('firebase');
        }
        $this->firebase = $CI->firebase;
    }

    /**
     * Authenticate the current request via Firebase ID token.
     *
     * Extracts the token from the Authorization header and verifies it.
     * Returns the decoded claims or null if not authenticated.
     *
     * @return array|null  ['uid', 'email', 'role', 'school_id', 'school_code', 'parent_db_key', 'exp', 'iat']
     */
    public function authenticate(): ?array
    {
        if ($this->checked) {
            return $this->claims;
        }
        $this->checked = true;

        $token = $this->_extract_bearer_token();
        if ($token === null) {
            return null;
        }

        $this->claims = $this->firebase->verifyFirebaseToken($token);
        return $this->claims;
    }

    /**
     * Require authentication. Returns claims or dies with 401.
     *
     * @return array  Decoded claims
     */
    public function require_auth(): array
    {
        $claims = $this->authenticate();
        if ($claims === null) {
            $this->_abort(401, 'Authentication required. Provide a valid Firebase ID token.');
        }

        // Check token expiry (belt-and-suspenders — kreait already checks)
        if (isset($claims['exp']) && $claims['exp'] < time()) {
            $this->_abort(401, 'Token expired. Please re-authenticate.');
        }

        return $claims;
    }

    /**
     * Require a specific role (or set of roles). Dies with 403 if mismatch.
     *
     * @param string|array $roles  Allowed role(s). Case-insensitive comparison.
     * @return array  The claims (for chaining)
     */
    public function require_role($roles): array
    {
        $claims    = $this->require_auth();
        $userRole  = strtolower(trim($claims['role'] ?? ''));
        $allowed   = array_map('strtolower', array_map('trim', (array) $roles));

        // Bypass roles always pass
        $bypass = ['super admin', 'school super admin', 'admin'];
        if (in_array($userRole, $bypass, true)) {
            return $claims;
        }

        if (!in_array($userRole, $allowed, true)) {
            $this->_log_security('ROLE_DENIED', $claims, [
                'required' => implode('|', $allowed),
                'actual'   => $userRole,
            ]);
            $this->_abort(403, 'Insufficient permissions.');
        }

        return $claims;
    }

    /**
     * Require that the authenticated user belongs to a specific school.
     * Prevents cross-tenant data access.
     *
     * @param string $school_id  The school_id (SCH_XXXXXX) to check against
     * @return array  The claims
     */
    public function require_school(string $school_id): array
    {
        $claims = $this->require_auth();

        $userSchool = $claims['school_id'] ?? '';
        $userRole   = strtolower(trim($claims['role'] ?? ''));

        // Super admins can access any school
        if ($userRole === 'super admin') {
            return $claims;
        }

        if ($userSchool !== $school_id) {
            $this->_log_security('CROSS_SCHOOL', $claims, [
                'requested_school' => $school_id,
                'user_school'      => $userSchool,
            ]);
            $this->_abort(403, 'Access denied. Cross-school access is not permitted.');
        }

        return $claims;
    }

    /**
     * Require auth + specific school in one call (convenience).
     */
    public function require_school_role(string $school_id, $roles): array
    {
        $this->require_school($school_id);
        return $this->require_role($roles);
    }

    /**
     * Get the current authenticated user's claims (or null).
     * Does NOT trigger authentication — call authenticate() or require_auth() first.
     */
    public function get_claims(): ?array
    {
        return $this->claims;
    }

    /**
     * Get the UID of the authenticated user (or null).
     */
    public function uid(): ?string
    {
        return $this->claims['uid'] ?? null;
    }

    /**
     * Get the school_id of the authenticated user (or null).
     */
    public function school_id(): ?string
    {
        return $this->claims['school_id'] ?? null;
    }

    /**
     * Get the role of the authenticated user (or null).
     */
    public function role(): ?string
    {
        return $this->claims['role'] ?? null;
    }

    /**
     * Check if the current request has a valid Bearer token without requiring it.
     * Useful for endpoints that accept both session-based and token-based auth.
     */
    public function is_token_auth(): bool
    {
        return $this->authenticate() !== null;
    }

    // ══════════════════════════════════════════════════════════════════
    //  PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Extract Bearer token from Authorization header.
     */
    private function _extract_bearer_token(): ?string
    {
        // Try standard Authorization header
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        // Apache may strip the header — try getallheaders() as fallback
        if (empty($header) && function_exists('getallheaders')) {
            $headers = getallheaders();
            $header  = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        if (empty($header)) {
            return null;
        }

        // Must be "Bearer <token>"
        if (strpos($header, 'Bearer ') !== 0) {
            return null;
        }

        $token = trim(substr($header, 7));
        return ($token !== '') ? $token : null;
    }

    /**
     * Abort with a JSON error response and appropriate HTTP status.
     */
    private function _abort(int $httpCode, string $message): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'success' => false,
            'error'   => $message,
            'code'    => $httpCode,
        ]);
        exit;
    }

    /**
     * Log a security event via the canonical Firestore Security_telemetry
     * library (collection `security_events`), from the Teacher/Parent app
     * Bearer-token API surface.
     *
     * Phase 1 (RTDB elimination, 2026-07-03): the legacy best-effort write to
     * RTDB `System/Logs/Security/{month}` was removed. emit() is best-effort —
     * it always writes a structured '[STAFF_SECURITY]' log line before the
     * Firestore write and never throws — so the always-on audit line and
     * fail-safe posture are preserved. The tenant (`school_id`) and actor
     * (`uid`/`role`) come from the verified token claims; `school_id` is echoed
     * into detail for the per-tenant security_events(detail.schoolId, ts) index.
     */
    private function _log_security(string $event, ?array $claims, array $extra = []): void
    {
        try {
            $CI =& get_instance();
            $schoolId = (string) ($claims['school_id'] ?? '');
            if ($schoolId === '') $schoolId = 'UNKNOWN';

            $CI->load->library('security_telemetry', null, 'sec_telem');
            $CI->sec_telem->init($this->firebase, $schoolId, [
                'uid'          => (string) ($claims['uid']  ?? 'unknown'),
                'role'         => (string) ($claims['role'] ?? 'unknown'),
                'school_claim' => (string) ($claims['school_id'] ?? $schoolId),
            ]);

            $detail = array_merge([
                'schoolId' => $schoolId,
                'surface'  => 'mobile_api',
                'uri'      => $CI->uri->uri_string(),
            ], $extra);

            // CROSS_SCHOOL is a cross-tenant breach attempt → 'critical';
            // ROLE_DENIED → 'error'. Both map to CI log level 'error' (always
            // logged, even at production threshold=1) — preserving the legacy
            // log_message('error') guarantee.
            $severity = ($event === 'CROSS_SCHOOL') ? 'critical' : 'error';

            $CI->sec_telem->emit($event, $severity, $detail);
        } catch (\Throwable $e) {
            // Non-critical — telemetry must never break the API request.
            log_message('error', "API_AUTH [{$event}] telemetry_failed " . $e->getMessage());
        }
    }
}
