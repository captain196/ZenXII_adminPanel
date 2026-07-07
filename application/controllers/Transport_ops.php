<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport Ops — F1 Fleet Management sub-controller (2026-07-07).
 *
 * Maintenance + Fuel + Expiry-dashboard endpoints. Delegates all business
 * logic to Transport_service (DOMAIN_FLEET methods introduced in F1 LC-14).
 *
 * Endpoints:
 *   GET  /transport_ops/get_maintenance_logs?vehicle_id=VH0007
 *   POST /transport_ops/save_maintenance_log
 *   GET  /transport_ops/get_fuel_logs?vehicle_id=VH0007
 *   POST /transport_ops/save_fuel_log
 *   GET  /transport_ops/get_expiry_dashboard              — reads cache
 *   POST /transport_ops/recompute_expiry_dashboard        — admin trigger
 */
class Transport_ops extends MY_Controller
{
    private const MANAGE_ROLES = [
        'Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal',
        'Operations Manager', 'Transport Manager',
    ];
    private const VIEW_ROLES = [
        'Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal',
        'Operations Manager', 'Transport Manager', 'Accountant', 'Teacher',
    ];

    public function __construct()
    {
        parent::__construct();
        require_permission('Operations');
        $this->load->library('numbering_service', null, 'numbering');
        $this->numbering->init($this->fs, $this->school_name, $this->session_year);
        $this->load->library('transport_service', null, 'transport');
        $this->transport->init(
            $this->fs, $this->numbering,
            $this->school_name, $this->session_year,
            $this->admin_id ?? 'system'
        );
    }

    private function _require_manage(): void
    {
        if (!in_array($this->admin_role, self::MANAGE_ROLES, true)) {
            $this->json_error('Access denied.', 403);
        }
    }
    private function _require_view(): void
    {
        if (!in_array($this->admin_role, self::VIEW_ROLES, true)) {
            $this->json_error('Access denied.', 403);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Maintenance
    // ═══════════════════════════════════════════════════════════════════

    public function get_maintenance_logs()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        $vid = $this->safe_path_segment(trim($this->input->get('vehicle_id') ?? ''), 'vehicle_id');
        $this->json_success(['logs' => $this->transport->listMaintenanceLogs($vid)]);
    }

    public function save_maintenance_log()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_maintenance_log');
        $this->_require_manage();
        $parts = $this->input->post('parts_replaced');
        $parts = is_array($parts) ? $parts : (is_string($parts) && $parts !== '' ? explode(',', $parts) : []);
        try {
            $result = $this->transport->saveMaintenanceLog([
                'vehicle_id'                  => $this->safe_path_segment(trim($this->input->post('vehicle_id') ?? ''), 'vehicle_id'),
                'service_type'                => trim($this->input->post('service_type') ?? 'Routine'),
                'service_date'                => trim($this->input->post('service_date') ?? date('Y-m-d')),
                'service_km'                  => (int) ($this->input->post('service_km') ?? 0),
                'workshop'                    => trim($this->input->post('workshop') ?? ''),
                'cost'                        => (float) ($this->input->post('cost') ?? 0),
                'parts_replaced'              => array_map('trim', $parts),
                'next_service_date_projected' => trim($this->input->post('next_service_date_projected') ?? ''),
                'notes'                       => trim($this->input->post('notes') ?? ''),
                'receipt_path'                => trim($this->input->post('receipt_path') ?? ''),
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport_ops::save_maintenance_log failed: ' . $e->getMessage());
            $this->json_error('Failed to save maintenance log.');
        }
        $this->json_success(['id' => $result['id'], 'message' => 'Maintenance recorded.']);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Fuel
    // ═══════════════════════════════════════════════════════════════════

    public function get_fuel_logs()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        $vid = $this->safe_path_segment(trim($this->input->get('vehicle_id') ?? ''), 'vehicle_id');
        $this->json_success(['logs' => $this->transport->listFuelLogs($vid)]);
    }

    public function save_fuel_log()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_fuel_log');
        $this->_require_manage();
        try {
            $result = $this->transport->saveFuelLog([
                'vehicle_id'      => $this->safe_path_segment(trim($this->input->post('vehicle_id') ?? ''), 'vehicle_id'),
                'fuel_date'       => trim($this->input->post('fuel_date') ?? date('Y-m-d')),
                'litres'          => (float) ($this->input->post('litres') ?? 0),
                'price_per_litre' => (float) ($this->input->post('price_per_litre') ?? 0),
                'total_amount'    => (float) ($this->input->post('total_amount') ?? 0),
                'station'         => trim($this->input->post('station') ?? ''),
                'driver_staff_id' => trim($this->input->post('driver_staff_id') ?? ''),
                'odometer_km'     => (int) ($this->input->post('odometer_km') ?? 0),
                'notes'           => trim($this->input->post('notes') ?? ''),
                'receipt_path'    => trim($this->input->post('receipt_path') ?? ''),
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport_ops::save_fuel_log failed: ' . $e->getMessage());
            $this->json_error('Failed to save fuel log.');
        }
        $this->json_success([
            'id'            => $result['id'],
            'computed_kmpl' => $result['computed_kmpl'] ?? 0,
            'message'       => 'Fuel entry recorded.',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Expiry dashboard
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Read the tenant's cached expiry dashboard summary. Cache doc:
     *   vehicleExpiryDashboardCache/{schoolId}_VEXPCACHE
     * If missing (never computed), returns an empty envelope so the UI
     * can display a "run now" prompt without crashing.
     */
    public function get_expiry_dashboard()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        $cacheDocId = $this->school_name . '_VEXPCACHE';
        $doc = $this->fs->getEntity('vehicleExpiryDashboardCache', $cacheDocId);
        if (!is_array($doc)) {
            $doc = [
                'schoolId'       => $this->school_name,
                'computed_at'    => '',
                'count_7_days'   => 0,
                'count_15_days'  => 0,
                'count_30_days'  => 0,
                'items'          => [],
                'items_truncated'=> false,
            ];
        }
        $this->json_success(['dashboard' => $doc]);
    }

    /**
     * Admin-triggered recompute. Normal path is the nightly Transport_expiry_cron;
     * this endpoint is available for on-demand refresh (e.g. after a batch
     * document upload).
     */
    public function recompute_expiry_dashboard()
    {
        $this->_require_role(self::MANAGE_ROLES, 'recompute_expiry_dashboard');
        $this->_require_manage();
        try {
            $summary = $this->transport->computeFleetExpiryDashboard();
        } catch (\Throwable $e) {
            log_message('error', 'Transport_ops::recompute_expiry_dashboard failed: ' . $e->getMessage());
            $this->json_error('Failed to recompute dashboard.');
        }
        $this->json_success([
            'count_7_days'  => $summary['count_7_days'],
            'count_15_days' => $summary['count_15_days'],
            'count_30_days' => $summary['count_30_days'],
            'message'       => 'Dashboard recomputed.',
        ]);
    }
}
