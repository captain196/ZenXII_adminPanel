<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * The single capability key gating every Phase-1 HRMS surface (Organisation
 * profile, terminology, Locations). A capability key absent from a tenant's
 * tenantPublic.activeModules is disabled by default — see
 * Tenant_capability_service's own doc comment for the fail-closed rationale.
 * Centralized here so every call site references the constant, never a
 * repeated magic string.
 */
if (!defined('HRMS_CORE_CAPABILITY')) {
    define('HRMS_CORE_CAPABILITY', 'hrms_core');
}

/**
 * The rest of the agreed HRMS capability tree (W1.1). HRMS Core + HRMS Payroll
 * are the two cross-vertical engines of ONE canonical ZenX HRMS; Corporate and
 * Retail are optional vertical extensions on top of Core, never separate HRMS
 * products.
 *
 * Deliberately NOT defined: hrms_attendance / hrms_leave. Attendance and Leave
 * already exist as ZenX capabilities and are part of the HRMS ecosystem; giving
 * them second entitlement keys would create two switches for one capability.
 *
 * Dependency rule enforced at grant time (B2_registry_service::set_module_override):
 *   hrms_payroll / hrms_corporate / hrms_retail  ⇒  require hrms_core.
 */
if (!defined('HRMS_PAYROLL_CAPABILITY'))   define('HRMS_PAYROLL_CAPABILITY', 'hrms_payroll');
if (!defined('HRMS_CORPORATE_CAPABILITY')) define('HRMS_CORPORATE_CAPABILITY', 'hrms_corporate');
if (!defined('HRMS_RETAIL_CAPABILITY'))    define('HRMS_RETAIL_CAPABILITY', 'hrms_retail');

if (!defined('HRMS_CAPABILITIES')) {
    define('HRMS_CAPABILITIES', [
        HRMS_CORE_CAPABILITY,
        HRMS_PAYROLL_CAPABILITY,
        HRMS_CORPORATE_CAPABILITY,
        HRMS_RETAIL_CAPABILITY,
    ]);
}

/**
 * Tenant capability gate — deliberately separate from rbac_helper.php's
 * has_permission()/require_permission(). Mirrors their shape so the two
 * gates read the same way at a call site, but they answer different
 * questions and neither substitutes for the other:
 *
 *   require_permission('HR', 'save_location', 'edit')   → can THIS USER?
 *   require_capability('hrms_core', 'save_location')     → does THIS TENANT have it?
 *
 * A Phase-1 HRMS endpoint gates on BOTH, independently.
 */

if (!function_exists('has_capability')) {
    function has_capability(string $key): bool
    {
        $CI =& get_instance();
        if (!isset($CI->capability)) {
            // Tenant_capability_service should always be loaded by
            // MY_Controller — if it's somehow missing, fail closed rather
            // than silently granting every capability.
            log_message('error', "has_capability({$key}) called with no capability service loaded — denying.");
            return false;
        }
        return $CI->capability->isEnabled($key);
    }
}

if (!function_exists('require_capability')) {
    function require_capability(string $key, string $action = ''): void
    {
        if (has_capability($key)) {
            return;
        }

        $CI     =& get_instance();
        $school = $CI->session->userdata('school_id') ?? '';
        $label  = $action ? " ({$action})" : '';

        log_message('error', "Capability denied: key=[{$key}] school=[{$school}]{$label}");

        if ($CI->input->is_ajax_request()) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode([
                'status'     => 'error',
                'message'    => 'This feature is not enabled for your school.',
                'csrf_token' => $CI->security->get_csrf_hash(),
            ]);
            exit;
        }

        // Every Phase-1 capability-gated endpoint is AJAX-only; this is a
        // defensive fallback for a future full-page route, mirroring
        // require_permission()'s equivalent full-page branch.
        redirect(base_url());
    }
}
