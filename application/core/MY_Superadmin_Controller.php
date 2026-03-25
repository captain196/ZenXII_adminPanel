<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * MY_Superadmin_Controller
 * Base controller for all Super Admin panel pages.
 * Completely separate from MY_Controller — no school/session scope.
 */
class MY_Superadmin_Controller extends CI_Controller
{
    protected $sa_id;
    protected $sa_name;
    protected $sa_role;
    protected $sa_email;

    public function __construct()
    {
        parent::__construct();
        $this->load->library('firebase');
        $this->_send_security_headers();

        $sa_id = $this->session->userdata('sa_id');

        if (empty($sa_id)) {
            // Log unauthorized access attempt when debug mode is active
            if (defined('GRADER_DEBUG') && GRADER_DEBUG) {
                if (!class_exists('Debug_tracker', false)) {
                    require_once APPPATH . 'libraries/Debug_tracker.php';
                }
                $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'unknown';
                $ip  = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'cli';
                Debug_tracker::getInstance()->record_unauthorized($uri, $ip, '');
            }
            if ($this->input->is_ajax_request()) {
                $this->json_error('Session expired. Please log in again.', 401);
            }
            $this->session->sess_destroy();
            redirect('superadmin/login');
            exit;
        }

        // ── Session-based CSRF token for the SA panel ─────────────────────────
        // We cannot rely on CI3's cookie-based CSRF for the SA panel because the
        // school-admin panel shares the same cookie name ('csrf_token') and domain.
        // Whichever panel last made a POST overwrites the cookie, causing the other
        // panel's next AJAX call to fail with 403.
        //
        // Solution: generate one token per SA session, store it server-side, and
        // validate exclusively against that.  The SA routes are excluded from CI3's
        // built-in cookie check via $config['csrf_exclude_uris'] in config.php.
        // ─────────────────────────────────────────────────────────────────────────
        if (!$this->session->userdata('sa_csrf_token')) {
            $this->session->set_userdata('sa_csrf_token', bin2hex(random_bytes(32)));
        }
        $sa_csrf_token = $this->session->userdata('sa_csrf_token');

        // CSRF on all POST requests — uses session token, not CI3 cookie
        if ($this->input->method() === 'post') {
            $this->_verify_csrf($sa_csrf_token);
        }

        $this->sa_id    = $sa_id;
        $this->sa_name  = $this->session->userdata('sa_name');
        $this->sa_role  = $this->session->userdata('sa_role');
        $this->sa_email = $this->session->userdata('sa_email');

        // Share with all SA views — sa_csrf_token goes into the meta tag via sa_header.php
        $this->load->vars([
            'sa_id'         => $this->sa_id,
            'sa_name'       => $this->sa_name,
            'sa_role'       => $this->sa_role,
            'sa_email'      => $this->sa_email,
            'sa_csrf_token' => $sa_csrf_token,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CSRF VERIFICATION  (session-based — independent of CI3 cookie)
    //
    // Reads the submitted token from $_POST['csrf_token'] OR the
    // X-CSRF-Token request header, then compares with the per-session token
    // stored at construction time above.
    // ─────────────────────────────────────────────────────────────────────────

    private function _verify_csrf(string $session_token): void
    {
        // Read submitted token — POST body first, header as fallback
        $sent = trim((string)($this->input->post('csrf_token') ?? ''));
        if ($sent === '') {
            $sent = trim((string)($this->input->get_request_header('X-CSRF-Token', TRUE) ?? ''));
        }

        if ($sent === '' || !hash_equals($session_token, $sent)) {
            log_message('error', 'SA CSRF failure ip=' . $this->input->ip_address()
                . ' sent=' . substr($sent, 0, 8) . '...');
            if ($this->input->is_ajax_request()) {
                $this->json_error('Security token mismatch. Please refresh the page.', 403);
            }
            show_error('CSRF validation failed.', 403);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECURITY HEADERS
    // ─────────────────────────────────────────────────────────────────────────

    private function _send_security_headers(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

        // ── HSTS — only sent over HTTPS ──
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            header('Strict-Transport-Security: max-age=86400; includeSubDomains');
        }

        // ── Content-Security-Policy (mirrors MY_Controller's CSP) ──
        $csp = "default-src 'self'; "
            . "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://cdn.datatables.net; "
            . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://cdn.datatables.net https://api.fontshare.com; "
            . "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com https://cdn.fontshare.com; "
            . "img-src 'self' data: blob: https://*.googleapis.com https://*.firebasestorage.googleapis.com; "
            . "connect-src 'self' https://*.firebaseio.com https://*.firebasedatabase.app https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; "
            . "frame-ancestors 'none'; "
            . "base-uri 'self'; "
            . "form-action 'self';";

        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            $csp = "upgrade-insecure-requests; " . $csp;
        }

        header("Content-Security-Policy: " . $csp);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // JSON RESPONSE HELPERS  (mirrors MY_Controller signature)
    // ─────────────────────────────────────────────────────────────────────────

    protected function json_success(array $data = []): void
    {
        header('Content-Type: application/json');
        echo json_encode(array_merge(['status' => 'success'], $data));
        exit;
    }

    protected function json_error(string $message, int $http_code = 400): void
    {
        http_response_code($http_code);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => $message]);
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ACTIVITY LOGGER
    // ─────────────────────────────────────────────────────────────────────────

    protected function sa_log(string $action, string $school_uid = '', array $meta = []): void
    {
        try {
            $this->firebase->push('System/Logs/Activity/' . date('Y-m-d'), [
                'sa_id'      => $this->sa_id,
                'sa_name'    => $this->sa_name,
                'action'     => $action,
                'school_uid' => $school_uid,
                'ip'         => $this->input->ip_address(),
                'meta'       => $meta,
                'timestamp'  => date('Y-m-d H:i:s'),
            ]);
        } catch (Exception $e) {
            log_message('error', 'SA activity log failed: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SAFE SEGMENT VALIDATOR  (same pattern as MY_Controller)
    // ─────────────────────────────────────────────────────────────────────────

    protected function safe_segment(string $value, string $field = 'value'): string
    {
        $value = trim($value);
        if ($value === '') {
            $this->json_error("Missing required field: {$field}", 400);
        }
        if (!preg_match("/^[A-Za-z0-9 ',_\-]+$/u", $value)) {
            $this->json_error("Invalid characters in field: {$field}", 400);
        }
        return $value;
    }
}
