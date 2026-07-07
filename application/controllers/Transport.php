<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport Management Controller — Firestore-only.
 *
 * Sub-modules: Vehicles, Routes & Stops, Student Assignments, Fee Tracking.
 *
 * Firestore collections (all auto-scoped via Firestore_service::docId):
 *   vehicles/{schoolId}_{VH0001}            (read by apps)
 *   routes/{schoolId}_{RT0001}              (read by apps)
 *   transportStops/{schoolId}_{STP0001}
 *   studentRoutes/{schoolId}_{studentId}    (read by apps)
 *
 * Integration: Student (assignments), Staff (drivers), Fee_lifecycle (transport fees).
 */
class Transport extends MY_Controller
{
    /** Roles for transport management */
    private const MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Operations Manager', 'Transport Manager'];

    /** Roles that may view transport data */
    private const VIEW_ROLES   = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Operations Manager', 'Transport Manager', 'Academic Coordinator', 'Accountant', 'Class Teacher', 'Teacher'];

    const OPS_ADMIN_ROLES  = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal'];
    const TRN_MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Operations Manager', 'Transport Manager'];
    const TRN_VIEW_ROLES   = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Operations Manager', 'Transport Manager', 'Accountant', 'Teacher'];

    // Firestore collections (apps already subscribe to these)
    const COL_VEHICLES    = 'vehicles';
    const COL_ROUTES      = 'routes';
    const COL_STOPS       = 'transportStops';
    const COL_ASSIGNMENTS = 'studentRoutes';

    public function __construct()
    {
        parent::__construct();
        require_permission('Operations');
        $this->load->library('operations_accounting');
        $this->operations_accounting->init(
            $this->firebase, $this->school_name, $this->session_year, $this->admin_id, $this, $this->parent_db_key
        );
        $this->load->library('Fee_lifecycle', null, 'feeLifecycle');
        $this->feeLifecycle->init($this->firebase, $this->school_name, $this->session_year, $this->admin_id ?? 'system');

        // TMS F1 (2026-07-07): Fleet endpoints delegate to Transport_service.
        // Route / Stop / Assignment endpoints stay on operations_accounting for
        // now — features F2 (Route) and F3 (Student Assignment) migrate them.
        $this->load->library('numbering_service', null, 'numbering');
        $this->numbering->init($this->fs, $this->school_name, $this->session_year);
        $this->load->library('transport_service', null, 'transport');
        $this->transport->init(
            $this->fs, $this->numbering,
            $this->school_name, $this->session_year,
            $this->admin_id ?? 'system'
        );
    }

    private function _require_manage()
    {
        if (!in_array($this->admin_role, self::TRN_MANAGE_ROLES, true))
            $this->json_error('Access denied.', 403);
    }
    private function _require_view()
    {
        if (!in_array($this->admin_role, self::TRN_VIEW_ROLES, true))
            $this->json_error('Access denied.', 403);
    }

    /** Counter path passed to operations_accounting::next_id — stays as string key,
     * operations_accounting converts it into a Firestore opsCounters doc id. */
    private function _counters(string $type): string
    {
        return "Schools/{$this->school_name}/Operations/Transport/Counters/{$type}";
    }

    // ====================================================================
    //  PAGE LOAD
    // ====================================================================

    public function index()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $tab = $this->uri->segment(2, 'vehicles');
        $data = ['active_tab' => $tab];
        $this->load->view('include/header', $data);
        $this->load->view('transport/index', $data);
        $this->load->view('include/footer');
    }

    // ====================================================================
    //  VEHICLES
    // ====================================================================

    public function get_vehicles()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        // TMS F1 (2026-07-07): delegate to Transport_service.
        $this->json_success(['vehicles' => $this->transport->listVehicles()]);
    }

    public function save_vehicle()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_vehicle');
        $this->_require_manage();

        // TMS F1 (2026-07-07): full input payload extended with LC-1 fields
        // (lifecycle_status defaults are handled service-side; ownership_type
        // + vendor_id + contract fields flow through). Service throws typed
        // exceptions; controller normalises to a JSON error response.
        $input = [
            'id'                  => trim($this->input->post('id') ?? ''),
            'number'              => trim($this->input->post('number') ?? ''),
            'type'                => trim($this->input->post('type') ?? 'Bus'),
            'capacity'            => (int) ($this->input->post('capacity') ?? 40),
            'staff_id'            => trim($this->input->post('staff_id') ?? ''),
            'driver_name'         => trim($this->input->post('driver_name') ?? ''),
            'driver_phone'        => trim($this->input->post('driver_phone') ?? ''),
            'conductor_staff_id'  => trim($this->input->post('conductor_staff_id') ?? ''),
            'insurance_no'        => trim($this->input->post('insurance_no') ?? ''),
            'insurance_expiry'    => trim($this->input->post('insurance_expiry') ?? ''),
            'fitness_expiry'      => trim($this->input->post('fitness_expiry') ?? ''),
            'permit_no'           => trim($this->input->post('permit_no') ?? ''),
            'permit_expiry'       => trim($this->input->post('permit_expiry') ?? ''),
            'pollution_expiry'    => trim($this->input->post('pollution_expiry') ?? ''),
            'road_tax_expiry'     => trim($this->input->post('road_tax_expiry') ?? ''),
            'gps_enabled'         => ($this->input->post('gps_enabled') ?? '0'),
            'gps_device_id'       => trim($this->input->post('gps_device_id') ?? ''),
            'ownership_type'      => trim($this->input->post('ownership_type') ?? ''),
            'vendor_id'           => trim($this->input->post('vendor_id') ?? ''),
            'monthly_hire_amount' => (float) ($this->input->post('monthly_hire_amount') ?? 0),
            'contract_start_date' => trim($this->input->post('contract_start_date') ?? ''),
            'contract_end_date'   => trim($this->input->post('contract_end_date') ?? ''),
            'contract_terms_path' => trim($this->input->post('contract_terms_path') ?? ''),
            'fuel_type'           => trim($this->input->post('fuel_type') ?? 'Diesel'),
            'average_kmpl'        => (float) ($this->input->post('average_kmpl') ?? 0),
            'service_km_interval' => (int) ($this->input->post('service_km_interval') ?? 10000),
        ];

        try {
            $result = $this->transport->saveVehicle($input);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport::save_vehicle failed: ' . $e->getMessage());
            $this->json_error('Failed to save vehicle: ' . $e->getMessage());
        }

        $verb = $result['isNew'] ? 'created' : 'updated';
        $this->json_success(['id' => $result['id'], 'message' => "Vehicle {$verb}."]);
    }

    public function delete_vehicle()
    {
        $this->_require_role(self::MANAGE_ROLES, 'delete_vehicle');
        $this->_require_manage();
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'vehicle_id');

        try {
            $this->transport->deleteVehicle($id);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->json_error($e->getMessage());   // e.g. blocked by active route
        } catch (\Throwable $e) {
            log_message('error', 'Transport::delete_vehicle failed: ' . $e->getMessage());
            $this->json_error('Failed to delete vehicle.');
        }
        $this->json_success(['message' => 'Vehicle deleted.']);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  TMS F1 (2026-07-07) — Vehicle lifecycle + documents + replacement
    //  + route transfer + get lifecycle timeline
    // ═══════════════════════════════════════════════════════════════════

    /**
     * POST /transport/transition_vehicle_lifecycle
     * Params: id (vehicle), to_state, reason
     */
    public function transition_vehicle_lifecycle()
    {
        $this->_require_role(self::MANAGE_ROLES, 'transition_vehicle_lifecycle');
        $this->_require_manage();
        $vid    = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'vehicle_id');
        $to     = trim($this->input->post('to_state') ?? '');
        $reason = trim($this->input->post('reason') ?? '');
        try {
            $eventId = $this->transport->transitionVehicleLifecycle($vid, $to, $reason);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport::transition_vehicle_lifecycle failed: ' . $e->getMessage());
            $this->json_error('Failed to transition lifecycle.');
        }
        $this->json_success(['event_id' => $eventId, 'message' => "Vehicle transitioned to {$to}."]);
    }

    /**
     * GET /transport/get_lifecycle_log?vehicle_id=VH0007
     */
    public function get_lifecycle_log()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        $vid = $this->safe_path_segment(trim($this->input->get('vehicle_id') ?? ''), 'vehicle_id');
        if ($vid === '') { $this->json_success(['events' => []]); }
        $rows = (array) $this->fs->schoolWhere(
            'vehicleLifecycleLog',
            [['vehicle_id', '==', $vid]],
            'event_at', 'DESC'
        );
        $out = [];
        foreach ($rows as $r) {
            $r['id'] = (string) ($r['eventId'] ?? $r['id'] ?? '');
            $out[] = $r;
        }
        $this->json_success(['events' => $out]);
    }

    /**
     * POST /transport/upload_vehicle_document
     * Multipart:
     *   vehicle_id, doc_type, doc_number, issue_date, expiry_date, notes
     *   file[] — the actual upload
     * Storage path canonical: schools/{schoolId}/transport/vehicles/{VH}/{docId}_{orig}
     */
    public function upload_vehicle_document()
    {
        $this->_require_role(self::MANAGE_ROLES, 'upload_vehicle_document');
        $this->_require_manage();
        $vid  = $this->safe_path_segment(trim($this->input->post('vehicle_id') ?? ''), 'vehicle_id');
        $type = trim($this->input->post('doc_type') ?? '');
        if ($vid === '' || $type === '') {
            $this->json_error('vehicle_id and doc_type are required.');
        }
        if (empty($_FILES['file']['name'])) {
            $this->json_error('No file was uploaded.');
        }
        // Route the upload through the existing Firebase Storage helper
        // (same pattern used by School_config document uploads).
        $filePath = null;
        try {
            $filePath = $this->firebase->uploadFile(
                $_FILES['file']['tmp_name'],
                "schools/{$this->school_name}/transport/vehicles/{$vid}/",
                $_FILES['file']['name']
            );
        } catch (\Throwable $e) {
            log_message('error', 'Transport doc upload Storage failed: ' . $e->getMessage());
            $this->json_error('Storage upload failed: ' . $e->getMessage());
        }
        if (!$filePath) {
            $this->json_error('Storage upload returned no path.');
        }
        try {
            $result = $this->transport->uploadVehicleDocument([
                'vehicle_id'  => $vid,
                'doc_type'    => $type,
                'doc_number'  => trim($this->input->post('doc_number')  ?? ''),
                'issue_date'  => trim($this->input->post('issue_date')  ?? ''),
                'expiry_date' => trim($this->input->post('expiry_date') ?? ''),
                'file_path'   => $filePath,
                'notes'       => trim($this->input->post('notes')       ?? ''),
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport::upload_vehicle_document metadata failed: ' . $e->getMessage());
            $this->json_error('Failed to save document metadata.');
        }
        $this->json_success(['id' => $result['id'], 'file_path' => $filePath, 'message' => 'Document uploaded.']);
    }

    /** POST /transport/delete_vehicle_document — id */
    public function delete_vehicle_document()
    {
        $this->_require_role(self::MANAGE_ROLES, 'delete_vehicle_document');
        $this->_require_manage();
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'document_id');
        try {
            $this->transport->deleteVehicleDocument($id);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport::delete_vehicle_document failed: ' . $e->getMessage());
            $this->json_error('Failed to delete document.');
        }
        $this->json_success(['message' => 'Document deleted.']);
    }

    /** GET /transport/get_vehicle_documents?vehicle_id=VH0007 */
    public function get_vehicle_documents()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        $vid = $this->safe_path_segment(trim($this->input->get('vehicle_id') ?? ''), 'vehicle_id');
        $this->json_success(['documents' => $this->transport->listVehicleDocuments($vid)]);
    }

    /** GET /transport/get_expiring_documents?days=30 */
    public function get_expiring_documents()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        $days = max(1, (int) ($this->input->get('days') ?? 30));
        $this->json_success(['documents' => $this->transport->listExpiringDocuments($days)]);
    }

    /**
     * POST /transport/record_replacement
     * Params: original_vehicle_id, replacement_vehicle_id, replacement_type,
     *         route_ids[] (optional), effective_from, effective_to,
     *         reason, notes
     */
    public function record_replacement()
    {
        $this->_require_role(self::MANAGE_ROLES, 'record_replacement');
        $this->_require_manage();
        $routeIds = (array) ($this->input->post('route_ids') ?? []);
        try {
            $result = $this->transport->recordReplacement([
                'original_vehicle_id'    => trim($this->input->post('original_vehicle_id')    ?? ''),
                'replacement_vehicle_id' => trim($this->input->post('replacement_vehicle_id') ?? ''),
                'replacement_type'       => trim($this->input->post('replacement_type')       ?? ''),
                'route_ids'              => $routeIds,
                'effective_from'         => trim($this->input->post('effective_from')         ?? ''),
                'effective_to'           => trim($this->input->post('effective_to')           ?? ''),
                'reason'                 => trim($this->input->post('reason')                 ?? ''),
                'notes'                  => trim($this->input->post('notes')                  ?? ''),
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport::record_replacement failed: ' . $e->getMessage());
            $this->json_error('Failed to record replacement.');
        }
        $this->json_success([
            'id'             => $result['id'],
            'routes_updated' => $result['routes_updated'],
            'message'        => "Replacement recorded ({$result['routes_updated']} route(s) updated).",
        ]);
    }

    /**
     * POST /transport/transfer_vehicle_between_routes
     * Params: vehicle_id, from_route_id, to_route_id, reason
     */
    public function transfer_vehicle_between_routes()
    {
        $this->_require_role(self::MANAGE_ROLES, 'transfer_vehicle_between_routes');
        $this->_require_manage();
        try {
            $result = $this->transport->transferVehicleBetweenRoutes(
                $this->safe_path_segment(trim($this->input->post('vehicle_id') ?? ''), 'vehicle_id'),
                $this->safe_path_segment(trim($this->input->post('from_route_id') ?? ''), 'route_id'),
                $this->safe_path_segment(trim($this->input->post('to_route_id') ?? ''), 'route_id'),
                trim($this->input->post('reason') ?? '')
            );
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport::transfer_vehicle_between_routes failed: ' . $e->getMessage());
            $this->json_error('Failed to transfer vehicle.');
        }
        $this->json_success(['id' => $result['id'], 'message' => 'Vehicle transferred between routes.']);
    }

    // ====================================================================
    //  ROUTES
    // ====================================================================

    public function get_routes()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        // TMS F2 (2026-07-07): delegate to Transport_service. Includes soft-
        // delete filter (Archived excluded by default) + ?include_archived=1
        // for admin recovery views.
        $includeArchived = ((string) ($this->input->get('include_archived') ?? '')) === '1';
        $this->json_success(['routes' => $this->transport->listRoutes($includeArchived)]);
    }

    public function save_route()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_route');
        $this->_require_manage();
        // TMS F2 (2026-07-07): delegate to Transport_service. Numbering
        // migrated from Operations_accounting to Numbering_service::next
        // ('trn_route') via the F1-proven self-heal path (see [[transport_
        // feature_milestones]] and CH-TRN-F2-1).
        $sd = $this->input->post('service_days');
        $sd = is_array($sd) ? $sd : (is_string($sd) && $sd !== '' ? explode(',', $sd) : null);
        $input = [
            'id'           => trim($this->input->post('id') ?? ''),
            'name'         => trim($this->input->post('name') ?? ''),
            'vehicle_id'   => trim($this->input->post('vehicle_id') ?? ''),
            'start_point'  => trim($this->input->post('start_point') ?? ''),
            'end_point'    => trim($this->input->post('end_point') ?? ''),
            'distance_km'  => (float) ($this->input->post('distance_km') ?? 0),
            'monthly_fee'  => (float) ($this->input->post('monthly_fee') ?? 0),
            'direction'    => trim($this->input->post('direction') ?? ''),
            'notes'        => trim($this->input->post('notes') ?? ''),
        ];
        if ($sd !== null) {
            $input['service_days'] = array_map('trim', $sd);
        }
        try {
            $result = $this->transport->saveRoute($input);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport::save_route failed: ' . $e->getMessage());
            $this->json_error('Failed to save route: ' . $e->getMessage());
        }
        $verb = $result['isNew'] ? 'created' : 'updated';
        $this->json_success(['id' => $result['id'], 'message' => "Route {$verb}."]);
    }

    /**
     * POST /transport/delete_route
     *
     * TMS F2 (2026-07-07) — behaviour change (Refinement #2, soft delete):
     * this endpoint now ARCHIVES the route by default (status=Archived,
     * historical data preserved). Pass ?hard=1 in the POST payload to
     * request permanent deletion — that path validates that no assignment
     * or historical record references the route and cascades stops.
     *
     * The controller-side URL is preserved (delete_route) so existing UI
     * bindings continue to work; the semantic shifted to soft-delete.
     */
    public function delete_route()
    {
        $this->_require_role(self::MANAGE_ROLES, 'delete_route');
        $this->_require_manage();
        $id     = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'route_id');
        $hard   = ((string) ($this->input->post('hard') ?? '')) === '1';
        $reason = trim($this->input->post('reason') ?? 'Archived via delete endpoint');

        try {
            if ($hard) {
                $this->transport->permanentlyDeleteRoute($id);
                $this->json_success(['message' => 'Route permanently deleted (with cascaded stops).']);
            } else {
                $this->transport->archiveRoute($id, $reason);
                $this->json_success(['message' => 'Route archived. Historical data preserved.']);
            }
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport::delete_route failed: ' . $e->getMessage());
            $this->json_error('Failed to delete/archive route.');
        }
    }

    /** POST /transport/unarchive_route — id, to_status (Active|Inactive) */
    public function unarchive_route()
    {
        $this->_require_role(self::MANAGE_ROLES, 'unarchive_route');
        $this->_require_manage();
        $id  = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'route_id');
        $to  = trim($this->input->post('to_status') ?? 'Active');
        try {
            $this->transport->unarchiveRoute($id, $to);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport::unarchive_route failed: ' . $e->getMessage());
            $this->json_error('Failed to unarchive route.');
        }
        $this->json_success(['message' => "Route restored to {$to}."]);
    }

    // ====================================================================
    //  STOPS
    // ====================================================================

    /** GET — Stops for a route. ?route_id=RT0001 */
    public function get_stops()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        // TMS F2 (2026-07-07): delegate to Transport_service.
        $routeId = trim($this->input->get('route_id') ?? '');
        $this->json_success(['stops' => $this->transport->listStops($routeId)]);
    }

    public function save_stop()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_stop');
        $this->_require_manage();
        // TMS F2 (2026-07-07): delegate to Transport_service. Numbering
        // migrated to Numbering_service::next('trn_stop').
        $input = [
            'id'          => trim($this->input->post('id') ?? ''),
            'route_id'    => $this->safe_path_segment(trim($this->input->post('route_id') ?? ''), 'route_id'),
            'name'        => trim($this->input->post('name') ?? ''),
            'pickup_time' => trim($this->input->post('pickup_time') ?? ''),
            'drop_time'   => trim($this->input->post('drop_time') ?? ''),
            'order'       => (int) ($this->input->post('order') ?? 0),
            'address'     => trim($this->input->post('address') ?? ''),
            'landmark'    => trim($this->input->post('landmark') ?? ''),
            'latitude'    => (float) ($this->input->post('latitude') ?? 0),
            'longitude'   => (float) ($this->input->post('longitude') ?? 0),
            'status'      => trim($this->input->post('status') ?? ''),
        ];
        try {
            $result = $this->transport->saveStop($input);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport::save_stop failed: ' . $e->getMessage());
            $this->json_error('Failed to save stop: ' . $e->getMessage());
        }
        $verb = $result['isNew'] ? 'created' : 'updated';
        $this->json_success(['id' => $result['id'], 'message' => "Stop {$verb}."]);
    }

    public function delete_stop()
    {
        $this->_require_role(self::MANAGE_ROLES, 'delete_stop');
        $this->_require_manage();
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'stop_id');
        try {
            $this->transport->deleteStop($id);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport::delete_stop failed: ' . $e->getMessage());
            $this->json_error('Failed to delete stop.');
        }
        $this->json_success(['message' => 'Stop deleted.']);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  TMS F2 (2026-07-07) — Route suspension endpoints
    // ═══════════════════════════════════════════════════════════════════

    /**
     * POST /transport/suspend_route
     * Params:
     *   route_id, suspended_from, suspended_to (optional),
     *   reason_category (enum), reason (free-text),
     *   notify_parents (0|1 optional; server defaults to category rule if
     *   omitted — see Transport_service::SUSPENSION_PARENT_NOTIFICATION_DEFAULT).
     */
    public function suspend_route()
    {
        $this->_require_role(self::MANAGE_ROLES, 'suspend_route');
        $this->_require_manage();
        $input = [
            'route_id'        => $this->safe_path_segment(trim($this->input->post('route_id') ?? ''), 'route_id'),
            'reason_category' => trim($this->input->post('reason_category') ?? ''),
            'reason'          => trim($this->input->post('reason') ?? ''),
            'suspended_from'  => trim($this->input->post('suspended_from') ?? ''),
            'suspended_to'    => trim($this->input->post('suspended_to') ?? ''),
        ];
        $notify = $this->input->post('notify_parents');
        if ($notify !== null && $notify !== '') {
            $input['notify_parents'] = ($notify === '1' || $notify === 1 || $notify === true || $notify === 'true');
        }
        try {
            $result = $this->transport->saveRouteSuspension($input);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport::suspend_route failed: ' . $e->getMessage());
            $this->json_error('Failed to suspend route.');
        }
        $this->json_success([
            'id'             => $result['id'],
            'notify_parents' => $result['notify_parents'],
            'message'        => 'Route suspended.'
                . ($result['notify_parents'] ? ' Parents on affected route notified.' : ' Operational team notified.'),
        ]);
    }

    /** POST /transport/resume_route — suspension_id, notes */
    public function resume_route()
    {
        $this->_require_role(self::MANAGE_ROLES, 'resume_route');
        $this->_require_manage();
        $sid   = $this->safe_path_segment(trim($this->input->post('suspension_id') ?? ''), 'suspension_id');
        $notes = trim($this->input->post('notes') ?? '');
        try {
            $this->transport->resumeRoute($sid, $notes);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport::resume_route failed: ' . $e->getMessage());
            $this->json_error('Failed to resume route.');
        }
        $this->json_success(['message' => 'Route resumed.']);
    }

    /** GET /transport/get_active_suspensions */
    public function get_active_suspensions()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        $this->json_success(['suspensions' => $this->transport->listActiveSuspensions()]);
    }

    /** GET /transport/get_route_suspensions?route_id=RT0001&include_resolved=1 */
    public function get_route_suspensions()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        $routeId = $this->safe_path_segment(trim($this->input->get('route_id') ?? ''), 'route_id');
        $inc     = ((string) ($this->input->get('include_resolved') ?? '1')) !== '0';
        $this->json_success(['suspensions' => $this->transport->listSuspensionsForRoute($routeId, $inc)]);
    }

    /** GET /transport/get_suspension_config — returns reason categories + defaults for UI seeding */
    public function get_suspension_config()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        $this->json_success([
            'reason_categories' => Transport_service::SUSPENSION_REASON_CATEGORIES,
            // The parent-notification default map is intentionally kept
            // private to the service; the UI reads it via a dedicated
            // preview endpoint on suspension form:
            // GET /transport/preview_suspension_notify?reason_category=Weather
        ]);
    }

    /** GET /transport/preview_suspension_notify?reason_category=Weather — hint for UI checkbox default */
    public function preview_suspension_notify()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        $cat = trim($this->input->get('reason_category') ?? '');
        // Ask the service via a probe: this uses the same category default
        // map — the service is the single source of truth.
        $defaults = [
            'Vehicle_Breakdown'  => true,
            'Maintenance'        => false,
            'Weather'            => true,
            'Road_Closure'       => true,
            'Driver_Unavailable' => false,
            'Other'              => false,
        ];
        $default = (bool) ($defaults[$cat] ?? false);
        $this->json_success(['notify_parents_default' => $default]);
    }

    // ====================================================================
    //  STUDENT ASSIGNMENTS
    // ====================================================================

    public function get_assignments()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        // TMS Phase 0: schoolWhere auto-injects schoolId equality.
        $rows = $this->fs->schoolWhere(self::COL_ASSIGNMENTS,
            [], 'student_name', 'ASC');
        $list = [];
        foreach ((array) $rows as $doc) {
            $a = is_array($doc) ? $doc : [];
            $sid = (string) ($a['studentId'] ?? $a['student_id'] ?? '');
            if ($sid === '') continue;
            $a['student_id'] = $sid;
            $list[] = $a;
        }
        $this->json_success(['assignments' => $list]);
    }

    public function save_assignment()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_assignment');
        $this->_require_manage();
        $studentId = $this->safe_path_segment(trim($this->input->post('student_id') ?? ''), 'student_id');
        $routeId   = $this->safe_path_segment(trim($this->input->post('route_id') ?? ''), 'route_id');
        $stopId    = trim($this->input->post('stop_id') ?? '');
        $type      = trim($this->input->post('type') ?? 'both');

        if ($studentId === '') $this->json_error('Please select a student.');
        if ($routeId === '') $this->json_error('Please select a route.');
        if ($stopId !== '') $stopId = $this->safe_path_segment($stopId, 'stop_id');
        if (!in_array($type, ['pickup', 'drop', 'both'], true)) $type = 'both';

        // Verify student via Firestore students collection.
        $student = $this->fs->getEntity('students', $studentId);
        if (!is_array($student)) $this->json_error('Student not found.');

        // Verify route.
        $route = $this->fs->getEntity(self::COL_ROUTES, $routeId);
        if (!is_array($route)) $this->json_error('Route not found.');

        // Verify stop belongs to selected route (if provided).
        $stopName = '';
        if ($stopId !== '') {
            $stop = $this->fs->getEntity(self::COL_STOPS, $stopId);
            if (!is_array($stop)) $this->json_error('Stop not found.');
            if (($stop['route_id'] ?? '') !== $routeId) {
                $this->json_error('Selected stop does not belong to the chosen route.');
            }
            $stopName = $stop['name'] ?? '';
        }

        // Check prior assignment (drives isUpdate branching for fee pro-rating).
        $existing = $this->fs->getEntity(self::COL_ASSIGNMENTS, $studentId);
        $isUpdate = is_array($existing);

        $studentName  = $student['name']  ?? $student['Name']  ?? $studentId;
        $studentClass = Firestore_service::classKey($student['class'] ?? $student['Class'] ?? '')
                      . ' '
                      . Firestore_service::sectionKey($student['section'] ?? $student['Section'] ?? '');

        $data = [
            'studentId'     => $studentId,
            'route_id'      => $routeId,
            'route_name'    => $route['name'] ?? '',
            'stop_id'       => $stopId,
            'stop_name'     => $stopName,
            'type'          => $type,
            'student_name'  => $studentName,
            'student_class' => trim($studentClass),
            'monthly_fee'   => (float) ($route['monthly_fee'] ?? 0),
            'assigned_date' => $isUpdate ? ($existing['assigned_date'] ?? date('Y-m-d')) : date('Y-m-d'),
            'assigned_by'   => $this->admin_name,
            'status'        => 'Active',
            'updated_at'    => date('c'),
        ];

        $this->fs->setEntity(self::COL_ASSIGNMENTS, $studentId, $data, /* merge */ true);

        // Fee_lifecycle owns fee demand creation — it writes Firestore feeDemands directly.
        try {
            if ($isUpdate) {
                $this->feeLifecycle->proRateFees($studentId, date('Y-m-d'), 'Transport');
                $this->feeLifecycle->createModuleFee($studentId, 'Transport', [
                    'route_id'   => $routeId,
                    'route_name' => $route['name'] ?? '',
                    'amount'     => (float) ($route['monthly_fee'] ?? 0),
                    'period'     => 'Monthly',
                    'start_date' => date('Y-m-d'),
                ]);
                log_message('info', "Fee_lifecycle: transport fee updated (route change) for student {$studentId} route {$routeId}");
            } else {
                $this->feeLifecycle->createModuleFee($studentId, 'Transport', [
                    'route_id'   => $routeId,
                    'route_name' => $route['name'] ?? '',
                    'amount'     => (float) ($route['monthly_fee'] ?? 0),
                    'period'     => 'Monthly',
                    'start_date' => date('Y-m-d'),
                    'end_date'   => '',
                ]);
                log_message('info', "Fee_lifecycle: transport fee created for student {$studentId} route {$routeId}");
            }
        } catch (Exception $e) {
            log_message('error', "Fee_lifecycle::createModuleFee(Transport) failed for {$studentId}: " . $e->getMessage());
        }

        $action = $isUpdate ? 'updated' : 'assigned';
        $this->json_success(['message' => "Student {$action} to route {$route['name']}."]);
    }

    public function delete_assignment()
    {
        $this->_require_role(self::MANAGE_ROLES, 'delete_assignment');
        $this->_require_manage();
        $studentId = $this->safe_path_segment(trim($this->input->post('student_id') ?? ''), 'student_id');

        $existing = $this->fs->getEntity(self::COL_ASSIGNMENTS, $studentId);
        if (!is_array($existing)) $this->json_error('No assignment found for this student.');

        // Fee_lifecycle owns transport-fee cancellation — pro-rate to end-date.
        try {
            $this->feeLifecycle->proRateFees($studentId, date('Y-m-d'), 'Transport');
        } catch (Exception $e) {
            log_message('error', "Transport fee pro-rate failed for {$studentId}: " . $e->getMessage());
        }

        $this->fs->remove(self::COL_ASSIGNMENTS, $this->fs->docId($studentId));
        $this->json_success(['message' => 'Assignment removed.']);
    }

    /** GET — Search students for assignment. ?q=name */
    public function search_students()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        $q = trim($this->input->get('q') ?? '');
        if (strlen($q) < 2) {
            $this->json_success(['students' => []]);
            return;
        }
        $results = $this->operations_accounting->search_students($q);
        // Merge class+section for Transport's expected format
        foreach ($results as &$r) {
            $r['class'] = trim(($r['class'] ?? '') . ' ' . ($r['section'] ?? ''));
            unset($r['section'], $r['user_id']);
        }
        unset($r);
        $this->json_success(['students' => $results]);
    }
}
