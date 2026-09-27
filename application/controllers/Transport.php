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
    // Authorization is 100% graded RBAC (2026-08-07). Every endpoint calls
    // require_permission('Transport', <action>, <view|edit|manage>); the legacy
    // role-name allow-lists (VIEW_ROLES / TRN_VIEW_ROLES / TRN_MANAGE_ROLES /
    // OPS_ADMIN_ROLES) and the _require_view()/_require_manage() predicates they
    // backed have been deleted. Access is granted only by holding the Transport
    // module (directly, or via its Operations umbrella parent) at the required
    // level — or by a RBAC_BYPASS_ROLES principal. Do not reintroduce a
    // role-name list here.

    // Firestore collections (apps already subscribe to these)
    const COL_VEHICLES    = 'vehicles';
    const COL_ROUTES      = 'routes';
    const COL_STOPS       = 'transportStops';
    const COL_ASSIGNMENTS = 'studentRoutes';

    public function __construct()
    {
        parent::__construct();
        require_permission('Transport');
        // TMS F3 (2026-07-07): Operations_accounting load removed —
        // F1 migrated vehicle allocation, F2 migrated route/stop, F3
        // migrates assignments. No Transport code path uses
        // Operations_accounting after F3. Keep Fee_lifecycle for
        // assignment fee calls.
        $this->load->library('Fee_lifecycle', null, 'feeLifecycle');
        $this->feeLifecycle->init($this->firebase, $this->school_name, $this->session_year, $this->admin_id ?? 'system');

        $this->load->library('numbering_service', null, 'numbering');
        $this->numbering->init($this->fs, $this->school_name, $this->session_year);
        $this->load->library('transport_service', null, 'transport');
        $this->transport->init(
            $this->fs, $this->numbering,
            $this->school_name, $this->session_year,
            $this->admin_id ?? 'system'
        );
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
        require_permission('Transport', 'transport_view', 'view');
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
        require_permission('Transport', 'transport_view', 'view');
        // TMS F1 (2026-07-07): delegate to Transport_service.
        $this->json_success(['vehicles' => $this->transport->listVehicles()]);
    }

    public function save_vehicle()
    {
        require_permission('Transport', 'save_vehicle', 'manage');

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
        require_permission('Transport', 'delete_vehicle', 'manage');
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
        require_permission('Transport', 'transition_vehicle_lifecycle', 'manage');
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
        require_permission('Transport', 'transport_view', 'view');
        $vid = $this->safe_path_segment(trim($this->input->get('vehicle_id') ?? ''), 'vehicle_id');
        if ($vid === '') { $this->json_success(['events' => []]); }
        $rows = (array) $this->fs->schoolWhere(
            'vehicleLifecycleLog',
            [['vehicle_id', '==', $vid]],
            'event_at', 'DESC'
        );
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) { continue; }
            // schoolWhere returns {id, data:{…}} — ship the FLAT lifecycle fields,
            // not the envelope (else the UI reads every field off the wrong level).
            $d = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
            $d['id'] = (string) ($d['eventId'] ?? $r['id'] ?? $d['id'] ?? '');
            $out[] = $d;
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
        require_permission('Transport', 'upload_vehicle_document', 'edit');
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
            // Second arg = anyVehicle. Manage may upload against any vehicle;
            // an edit-level caller is scoped to the vehicle they drive. Read
            // from the existing Graded RBAC resolver — no RBAC change.
            ], function_exists('has_permission') ? has_permission('Transport', 'manage') : false);
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
        require_permission('Transport', 'delete_vehicle_document', 'manage');
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
        require_permission('Transport', 'transport_view', 'view');
        $vid = $this->safe_path_segment(trim($this->input->get('vehicle_id') ?? ''), 'vehicle_id');
        $this->json_success(['documents' => $this->transport->listVehicleDocuments($vid)]);
    }

    /** GET /transport/get_expiring_documents?days=30 */
    public function get_expiring_documents()
    {
        require_permission('Transport', 'transport_view', 'view');
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
        require_permission('Transport', 'record_replacement', 'manage');
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
        require_permission('Transport', 'transfer_vehicle_between_routes', 'manage');
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
        require_permission('Transport', 'transport_view', 'view');
        // TMS F2 (2026-07-07): delegate to Transport_service. Includes soft-
        // delete filter (Archived excluded by default) + ?include_archived=1
        // for admin recovery views.
        $includeArchived = ((string) ($this->input->get('include_archived') ?? '')) === '1';
        $this->json_success(['routes' => $this->transport->listRoutes($includeArchived)]);
    }

    public function save_route()
    {
        require_permission('Transport', 'save_route', 'manage');
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
        require_permission('Transport', 'delete_route', 'manage');
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
        require_permission('Transport', 'unarchive_route', 'manage');
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
        require_permission('Transport', 'transport_view', 'view');
        // TMS F2 (2026-07-07): delegate to Transport_service.
        $routeId = trim($this->input->get('route_id') ?? '');
        $this->json_success(['stops' => $this->transport->listStops($routeId)]);
    }

    public function save_stop()
    {
        require_permission('Transport', 'save_stop', 'manage');
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
            // ── TRN-STOP-STATUS-1 ────────────────────────────────────────────
            // Was: trim($this->input->post('status') ?? '').
            //
            // The Stop modal does not post a status — it sends id, route_id,
            // name, times, order, address and coordinates. That made this ''
            // rather than null, and '' is NOT null, so saveStop()'s documented
            // default — $input['status'] ?? $existing['status'] ?? 'Active' —
            // never fired. Every stop created through the real UI was stored
            // with status='' .
            //
            // The consequence sat on the canonical transport lifecycle:
            // approveTransportRequest() refuses a non-Active stop, so a school
            // could create a route and a stop, a family could request transport,
            // and the request could never be approved —
            // "Stop STP0001 is not Active (status='')". Reproduced end to end.
            //
            // NULL when absent, so "not supplied" reaches the service as absent
            // and its default applies. An explicitly posted status still wins,
            // and an edit still inherits the stored value.
            'status'      => (($__st = trim((string) ($this->input->post('status') ?? ''))) !== ''
                              ? $__st : null),
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
        require_permission('Transport', 'delete_stop', 'manage');
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
        require_permission('Transport', 'suspend_route', 'manage');
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
        require_permission('Transport', 'resume_route', 'manage');
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
        require_permission('Transport', 'transport_view', 'view');
        $this->json_success(['suspensions' => $this->transport->listActiveSuspensions()]);
    }

    /** GET /transport/get_route_suspensions?route_id=RT0001&include_resolved=1 */
    public function get_route_suspensions()
    {
        require_permission('Transport', 'transport_view', 'view');
        $routeId = $this->safe_path_segment(trim($this->input->get('route_id') ?? ''), 'route_id');
        $inc     = ((string) ($this->input->get('include_resolved') ?? '1')) !== '0';
        $this->json_success(['suspensions' => $this->transport->listSuspensionsForRoute($routeId, $inc)]);
    }

    /** GET /transport/get_suspension_config — returns reason categories + defaults for UI seeding */
    public function get_suspension_config()
    {
        require_permission('Transport', 'transport_view', 'view');
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
        require_permission('Transport', 'transport_view', 'view');
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
        require_permission('Transport', 'transport_view', 'view');
        // TMS F3 (2026-07-07): delegate to Transport_service. Filters:
        // ?route_id, ?class, ?status, ?subscription_type, ?fee_category.
        $filters = [
            'route_id'          => trim($this->input->get('route_id') ?? ''),
            'student_class'     => trim($this->input->get('class') ?? ''),
            'status'            => trim($this->input->get('status') ?? ''),
            'subscription_type' => trim($this->input->get('subscription_type') ?? ''),
            'fee_category'      => trim($this->input->get('fee_category') ?? ''),
        ];
        $sessionFilter = trim($this->input->get('session') ?? $this->session_year);
        $this->json_success(['assignments' => $this->transport->listAssignments($sessionFilter, $filters)]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  F14 Phase 1g — Transport REQUEST worklist (hybrid F-3)
    //
    //  A declaration ("we want transport") is a request, not an entitlement.
    //  These endpoints are the review surface that converts one into a real
    //  studentRoutes assignment. Graded RBAC throughout, same as the rest of
    //  the module: viewing needs Transport:view, acting needs Transport:edit.
    // ─────────────────────────────────────────────────────────────────────

    /** GET transport/get_transport_requests?status=pending|approved|rejected|all */
    public function get_transport_requests()
    {
        require_permission('Transport', 'transport_view', 'view');
        $status = trim((string) ($this->input->get('status') ?? 'pending'));
        try {
            $rows = $this->transport->listTransportRequests($status === '' ? 'pending' : $status);
        } catch (\InvalidArgumentException $e) {
            return $this->json_error($e->getMessage());
        }
        $this->json_success(['requests' => $rows]);
    }

    /**
     * POST transport/request_transport — mid-session activation entry point.
     *
     * Identical logic to the admission path: route + stop supplied ⇒ assign
     * immediately; otherwise ⇒ a pending worklist row. One workflow, so a
     * mid-session request cannot behave differently from an admission one.
     */
    public function request_transport()
    {
        require_permission('Transport', 'request_transport', 'edit');
        $studentId = trim((string) ($this->input->post('student_id') ?? ''));
        if ($studentId === '') { return $this->json_error('Student is required.'); }
        $sd = $this->input->post('service_days');
        $sd = is_array($sd) ? $sd : (is_string($sd) && $sd !== '' ? explode(',', $sd) : []);
        try {
            $res = $this->transport->requestTransport([
                'studentId'      => $this->safe_path_segment($studentId, 'student_id'),
                'student_name'   => (string) ($this->input->post('student_name')  ?? ''),
                'student_class'  => (string) ($this->input->post('student_class') ?? ''),
                // MODEL A: no route_id/stop_id here. A request is descriptive
                // (requested_area / stop_hint); management selects the canonical
                // route and stop at approve_transport_request. Canonical IDs are
                // deliberately NOT mapped into the free-text hint fields — the
                // semantic distinction is preserved.
                'type'           => (string) ($this->input->post('type')     ?? 'both'),
                'service_days'   => array_map('trim', $sd),
                'effective_from' => (string) ($this->input->post('effective_from') ?? ''),
                'requested_area' => (string) ($this->input->post('requested_area') ?? ''),
                'stop_hint'      => (string) ($this->input->post('stop_hint') ?? ''),
            // Always a pending worklist row. The manage flag that used to be
            // passed here gated a direct-assignment shortcut that was removed
            // (2026-08-15) — it never worked, and it threw for manage callers
            // only. Canonical assignment now goes through
            // approve_transport_request or save_assignment, both manage-gated.
            ], 'mid_session');
        } catch (\InvalidArgumentException $e) {
            return $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport::request_transport failed: ' . $e->getMessage());
            return $this->json_error('Could not record the transport request.');
        }
        $this->json_success($res);
    }

    /**
     * POST transport/approve_transport_request — request → real assignment.
     * Route AND stop are mandatory; approving without them would recreate the
     * incomplete-assignment problem the worklist exists to prevent.
     */
    public function approve_transport_request()
    {
        require_permission('Transport', 'approve_transport_request', 'manage');
        $requestId = trim((string) ($this->input->post('request_id') ?? ''));
        if ($requestId === '') { return $this->json_error('request_id is required.'); }
        $sd = $this->input->post('service_days');
        $sd = is_array($sd) ? $sd : (is_string($sd) && $sd !== '' ? explode(',', $sd) : []);
        try {
            $res = $this->transport->approveTransportRequest(
                $this->safe_path_segment($requestId, 'request_id'), [
                    'route_id'       => (string) ($this->input->post('route_id') ?? ''),
                    'stop_id'        => (string) ($this->input->post('stop_id')  ?? ''),
                    'type'           => (string) ($this->input->post('type')     ?? 'both'),
                    'service_days'   => array_map('trim', $sd),
                    'effective_from' => (string) ($this->input->post('effective_from') ?? ''),
                    'effective_to'   => (string) ($this->input->post('effective_to')   ?? ''),
                    'monthly_fee'    => $this->input->post('monthly_fee'),
                    'notes'          => (string) ($this->input->post('notes') ?? ''),
                ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport::approve_transport_request failed: ' . $e->getMessage());
            return $this->json_error('Could not approve the transport request.');
        }
        $this->json_success($res);
    }

    /** POST transport/reject_transport_request — reason required, no assignment. */
    public function reject_transport_request()
    {
        require_permission('Transport', 'reject_transport_request', 'manage');
        $requestId = trim((string) ($this->input->post('request_id') ?? ''));
        $reason    = trim((string) ($this->input->post('reason') ?? ''));
        if ($requestId === '') { return $this->json_error('request_id is required.'); }
        try {
            $this->transport->rejectTransportRequest(
                $this->safe_path_segment($requestId, 'request_id'), $reason);
        } catch (\InvalidArgumentException $e) {
            return $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport::reject_transport_request failed: ' . $e->getMessage());
            return $this->json_error('Could not reject the transport request.');
        }
        $this->json_success(['message' => 'Transport request rejected.']);
    }

    public function save_assignment()
    {
        require_permission('Transport', 'save_assignment', 'manage');
        // TMS F3 (2026-07-07): delegate to Transport_service::assignStudent
        // with pre-write validation (student active in SIS, route
        // operational, stop belongs to route, vehicle Active).
        $sd = $this->input->post('service_days');
        $sd = is_array($sd) ? $sd : (is_string($sd) && $sd !== '' ? explode(',', $sd) : null);
        $input = [
            'student_id'            => $this->safe_path_segment(trim($this->input->post('student_id') ?? ''), 'student_id'),
            'route_id'              => $this->safe_path_segment(trim($this->input->post('route_id') ?? ''), 'route_id'),
            'stop_id'               => trim($this->input->post('stop_id') ?? ''),
            'type'                  => trim($this->input->post('type') ?? 'both'),
            'subscription_type'     => trim($this->input->post('subscription_type') ?? 'Permanent'),
            'direction'             => trim($this->input->post('direction') ?? ''),
            'fee_category'          => trim($this->input->post('fee_category') ?? 'Standard'),
            'concession_id'         => trim($this->input->post('concession_id') ?? ''),
            'hostel_transport_flag' => ((string) ($this->input->post('hostel_transport_flag') ?? '')) === '1',
            'effective_from'        => trim($this->input->post('effective_from') ?? ''),
            'effective_to'          => trim($this->input->post('effective_to') ?? ''),
            'season_start'          => trim($this->input->post('season_start') ?? ''),
            'season_end'            => trim($this->input->post('season_end') ?? ''),
        ];
        if ($sd !== null) { $input['service_days'] = array_map('trim', $sd); }

        try {
            $result = $this->transport->assignStudent($input);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport::save_assignment failed: ' . $e->getMessage());
            $this->json_error('Failed to save assignment: ' . $e->getMessage());
        }
        $verb = $result['isNew'] ? 'assigned' : 'updated';
        $suffix = ($result['fee_status'] === 'pending')
            ? ' (fee demand pending — will retry).'
            : '.';
        $this->json_success([
            'id'         => $result['id'],
            'fee_status' => $result['fee_status'],
            'message'    => "Student {$verb}{$suffix}",
        ]);
    }

    public function delete_assignment()
    {
        require_permission('Transport', 'delete_assignment', 'manage');
        $studentId = $this->safe_path_segment(trim($this->input->post('student_id') ?? ''), 'student_id');
        $reason    = trim($this->input->post('reason') ?? 'unassigned via admin');
        try {
            $this->transport->unassignStudent($studentId, $reason);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport::delete_assignment failed: ' . $e->getMessage());
            $this->json_error('Failed to remove assignment.');
        }
        $this->json_success(['message' => 'Assignment removed.']);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  TMS F3 (2026-07-07) — Assignment lifecycle + bulk + history
    // ═══════════════════════════════════════════════════════════════════

    /** POST /transport/suspend_assignment — student_id, from, until, reason */
    public function suspend_assignment()
    {
        require_permission('Transport', 'suspend_assignment', 'manage');
        try {
            $this->transport->suspendAssignment(
                $this->safe_path_segment(trim($this->input->post('student_id') ?? ''), 'student_id'),
                trim($this->input->post('from')   ?? ''),
                trim($this->input->post('until')  ?? ''),
                trim($this->input->post('reason') ?? '')
            );
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport::suspend_assignment failed: ' . $e->getMessage());
            $this->json_error('Failed to suspend assignment.');
        }
        $this->json_success(['message' => 'Assignment suspended.']);
    }

    /** POST /transport/resume_assignment — student_id, reason */
    public function resume_assignment()
    {
        require_permission('Transport', 'resume_assignment', 'manage');
        try {
            $this->transport->resumeAssignment(
                $this->safe_path_segment(trim($this->input->post('student_id') ?? ''), 'student_id'),
                trim($this->input->post('reason') ?? '')
            );
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport::resume_assignment failed: ' . $e->getMessage());
            $this->json_error('Failed to resume assignment.');
        }
        $this->json_success(['message' => 'Assignment resumed.']);
    }

    /** POST /transport/change_stop — student_id, new_stop_id, reason */
    public function change_stop()
    {
        require_permission('Transport', 'change_stop', 'manage');
        try {
            $this->transport->changeStop(
                $this->safe_path_segment(trim($this->input->post('student_id') ?? ''), 'student_id'),
                $this->safe_path_segment(trim($this->input->post('new_stop_id') ?? ''), 'stop_id'),
                trim($this->input->post('reason') ?? '')
            );
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport::change_stop failed: ' . $e->getMessage());
            $this->json_error('Failed to change stop.');
        }
        $this->json_success(['message' => 'Stop changed.']);
    }

    /** POST /transport/change_route — student_id, new_route_id, new_stop_id, reason */
    public function change_route()
    {
        require_permission('Transport', 'change_route', 'manage');
        try {
            $this->transport->changeRoute(
                $this->safe_path_segment(trim($this->input->post('student_id') ?? ''), 'student_id'),
                $this->safe_path_segment(trim($this->input->post('new_route_id') ?? ''), 'route_id'),
                trim($this->input->post('new_stop_id') ?? ''),
                trim($this->input->post('reason') ?? '')
            );
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport::change_route failed: ' . $e->getMessage());
            $this->json_error('Failed to change route.');
        }
        $this->json_success(['message' => 'Route changed.']);
    }

    /** POST /transport/change_subscription — student_id, new_type, params, reason */
    public function change_subscription()
    {
        require_permission('Transport', 'change_subscription', 'manage');
        $params = [
            'effective_from' => trim($this->input->post('effective_from') ?? ''),
            'effective_to'   => trim($this->input->post('effective_to')   ?? ''),
            'season_start'   => trim($this->input->post('season_start')   ?? ''),
            'season_end'     => trim($this->input->post('season_end')     ?? ''),
            'direction'      => trim($this->input->post('direction')      ?? ''),
        ];
        try {
            $this->transport->changeSubscription(
                $this->safe_path_segment(trim($this->input->post('student_id') ?? ''), 'student_id'),
                trim($this->input->post('new_type') ?? ''),
                $params,
                trim($this->input->post('reason') ?? '')
            );
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport::change_subscription failed: ' . $e->getMessage());
            $this->json_error('Failed to change subscription.');
        }
        $this->json_success(['message' => 'Subscription changed.']);
    }

    /**
     * POST /transport/bulk_assign
     * Params: rows_json (JSON array up to 500 rows). Each row:
     *   {student_id, route_id, stop_id, type, subscription_type, ...}
     */
    public function bulk_assign()
    {
        require_permission('Transport', 'bulk_assign', 'manage');
        $raw  = trim($this->input->post('rows_json') ?? '');
        if ($raw === '') {
            $this->json_error('rows_json is required.');
        }
        $rows = json_decode($raw, true);
        if (!is_array($rows)) {
            $this->json_error('rows_json must be a JSON array.');
        }
        try {
            $result = $this->transport->bulkAssignStudents($rows);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport::bulk_assign failed: ' . $e->getMessage());
            $this->json_error('Bulk assign failed.');
        }
        $this->json_success([
            'batch_id'         => $result['batch_id'],
            'assigned_count'   => $result['assigned_count'],
            'skipped_capacity' => $result['skipped_capacity'],
            'skipped_conflict' => $result['skipped_conflict'],
            'errors'           => $result['errors'],
            'message'          => "Bulk assign complete: {$result['assigned_count']} assigned",
        ]);
    }

    /** GET /transport/get_assignment_history?student_id=STU0007 */
    public function get_assignment_history()
    {
        require_permission('Transport', 'transport_view', 'view');
        $sid = $this->safe_path_segment(trim($this->input->get('student_id') ?? ''), 'student_id');
        $this->json_success(['history' => $this->transport->getAssignmentHistory($sid)]);
    }

    /** GET /transport/get_student_assignment?student_id=STU0007 — Student 360 (read-only) */
    public function get_student_assignment()
    {
        require_permission('Transport', 'transport_view', 'view');
        $sid = $this->safe_path_segment(trim($this->input->get('student_id') ?? ''), 'student_id');
        $this->json_success(['assignment' => $this->transport->getStudentAssignment($sid)]);
    }

    /** POST /transport/reconcile_pending_fees — admin trigger */
    public function reconcile_pending_fees()
    {
        require_permission('Transport', 'reconcile_pending_fees', 'manage');
        try {
            $r = $this->transport->reconcilePendingFees();
        } catch (\Throwable $e) {
            log_message('error', 'Transport::reconcile_pending_fees failed: ' . $e->getMessage());
            $this->json_error('Reconciliation failed.');
        }
        $this->json_success([
            'retried'       => $r['retried'],
            'still_pending' => $r['still_pending'],
            'message'       => "Reconciled: {$r['retried']} retried, {$r['still_pending']} still pending",
        ]);
    }

    /** POST /transport/rebuild_capacity_cache — admin trigger (Refinement #2) */
    public function rebuild_capacity_cache()
    {
        require_permission('Transport', 'rebuild_capacity_cache', 'manage');
        $routeId = trim($this->input->post('route_id') ?? '');
        try {
            $r = $this->transport->rebuildCapacityCache($routeId);
        } catch (\Throwable $e) {
            log_message('error', 'Transport::rebuild_capacity_cache failed: ' . $e->getMessage());
            $this->json_error('Capacity cache rebuild failed.');
        }
        $this->json_success([
            'rebuilt_count' => $r['rebuilt_count'],
            'message'       => "Capacity cache rebuilt for {$r['rebuilt_count']} route(s).",
        ]);
    }

    /** GET — Search students for assignment. ?q=name */
    public function search_students()
    {
        require_permission('Transport', 'transport_view', 'view');
        $q = trim($this->input->get('q') ?? '');
        if (strlen($q) < 2) {
            $this->json_success(['students' => []]);
            return;
        }
        // NF-1 (Option B): route through the Transport/Firestore service layer —
        // the F3 refactor removed the Operations_accounting load from this
        // controller, so the old Operations_accounting::search_students() call
        // fatally 500'd (undefined property). Keep this decoupled.
        $results = $this->transport->searchStudents($q);
        // Merge class+section for Transport's expected format
        foreach ($results as &$r) {
            $r['class'] = trim(($r['class'] ?? '') . ' ' . ($r['section'] ?? ''));
            unset($r['section'], $r['user_id']);
        }
        unset($r);
        $this->json_success(['students' => $results]);
    }
}
