<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * School Guard Helper — Centralized school_id validation and access control.
 *
 * Two contexts:
 *   1. Authenticated (MY_Controller) — school_id comes from session, already
 *      validated by MY_Controller::_is_safe_segment(). Use get_school_context().
 *   2. Public (Admission_public) — school_id comes from URL parameter.
 *      Use validate_public_school_id() to validate format + existence.
 *
 * This helper ensures:
 *   - Consistent validation logic (single source of truth)
 *   - Logging of invalid access attempts
 *   - No raw user input ever reaches Firebase paths
 */

if (!function_exists('validate_public_school_id')) {
    /**
     * Validate a school_id from a public (unauthenticated) URL parameter.
     * Checks format + Firebase existence. Returns sanitized school_id or halts with 404.
     *
     * @param  string $school_id  Raw school_id from URL
     * @return string             Validated school_id (safe for Firebase paths)
     */
    function validate_public_school_id(string $school_id): string
    {
        // Format check: alphanumeric + underscore only (blocks path traversal, dots, slashes)
        if ($school_id === '' || !preg_match('/^[A-Za-z0-9_]+$/', $school_id)) {
            log_message('error', "SCHOOL_GUARD: invalid school_id format — input=[{$school_id}] ip=[" . _guard_ip() . ']');
            show_404();
            exit;
        }

        // Existence check: school must have Config/Profile or exist in System/Schools
        $CI =& get_instance();
        if (!isset($CI->firebase)) {
            $CI->load->library('firebase');
        }

        $profile = $CI->firebase->get("Schools/{$school_id}/Config/Profile");
        if (is_array($profile) && !empty($profile)) {
            return $school_id;
        }

        // Fallback: check System/Schools (onboarded but profile not yet in Config/)
        $sysProfile = $CI->firebase->get("System/Schools/{$school_id}/profile");
        if (is_array($sysProfile) && !empty($sysProfile)) {
            return $school_id;
        }

        log_message('error', "SCHOOL_GUARD: school not found — id=[{$school_id}] ip=[" . _guard_ip() . ']');
        show_404();
        exit;
    }
}

if (!function_exists('get_school_context')) {
    /**
     * Get validated school context from an authenticated MY_Controller session.
     * Returns array with all school identifiers. Halts if session is invalid.
     *
     * Use this in controllers that extend MY_Controller to get a clean,
     * validated school context without re-reading from Firebase.
     *
     * @return array {school_name, school_id, school_code, parent_db_key, session_year, display_name}
     */
    function get_school_context(): array
    {
        $CI =& get_instance();

        $school_name = $CI->school_name ?? '';
        if ($school_name === '' || !preg_match('/^[A-Za-z0-9_\- ]+$/', $school_name)) {
            log_message('error', 'SCHOOL_GUARD: get_school_context() called with empty/unsafe school_name');
            if ($CI->input->is_ajax_request()) {
                header('Content-Type: application/json');
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Invalid school session.']);
                exit;
            }
            redirect('admin_login');
            exit;
        }

        return [
            'school_name'    => $school_name,
            'school_id'      => $CI->school_id ?? '',
            'school_code'    => $CI->school_code ?? '',
            'parent_db_key'  => $CI->parent_db_key ?? '',
            'session_year'   => $CI->session_year ?? '',
            'display_name'   => $CI->school_display_name ?? $school_name,
        ];
    }
}

if (!function_exists('assert_same_school')) {
    /**
     * Assert that a Firebase path belongs to the current school.
     * Prevents cross-school access if a path is accidentally constructed
     * with a different school identifier.
     *
     * @param  string $path       Firebase path to validate
     * @param  string $school_id  Expected school identifier
     * @return bool               True if path starts with Schools/{school_id}/
     */
    function assert_same_school(string $path, string $school_id): bool
    {
        $prefix = "Schools/{$school_id}/";
        if (strpos($path, $prefix) === 0) {
            return true;
        }

        log_message('error', "SCHOOL_GUARD: cross-school path detected — path=[{$path}] expected_school=[{$school_id}] ip=[" . _guard_ip() . ']');
        return false;
    }
}

if (!function_exists('_guard_ip')) {
    /** Get client IP for logging (internal helper). */
    function _guard_ip(): string
    {
        $CI =& get_instance();
        return $CI->input->ip_address();
    }
}
