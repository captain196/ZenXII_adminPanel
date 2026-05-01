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
        $rows = $this->firebase->firestoreQuery(self::COL_VEHICLES,
            [['schoolId', '==', $this->school_name]], 'number', 'ASC');
        $list = [];
        foreach ((array) $rows as $doc) {
            $v = is_array($doc) ? $doc : [];
            $id = (string) ($v['vehicleId'] ?? $v['id'] ?? '');
            if ($id === '') continue;
            $v['id'] = $id;
            $list[] = $v;
        }
        $this->json_success(['vehicles' => $list]);
    }

    public function save_vehicle()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_vehicle');
        $this->_require_manage();
        $id          = trim($this->input->post('id') ?? '');
        $number      = trim($this->input->post('number') ?? '');
        $type        = trim($this->input->post('type') ?? 'Bus');
        $capacity    = max(1, (int) ($this->input->post('capacity') ?? 40));
        $driverName  = trim($this->input->post('driver_name') ?? '');
        $driverPhone = trim($this->input->post('driver_phone') ?? '');
        $staffId     = trim($this->input->post('staff_id') ?? '');
        $insuranceNo = trim($this->input->post('insurance_no') ?? '');
        $insuranceExp = trim($this->input->post('insurance_expiry') ?? '');
        $fitnessExp  = trim($this->input->post('fitness_expiry') ?? '');
        $gpsEnabled  = ($this->input->post('gps_enabled') ?? '0') === '1';

        if ($number === '') $this->json_error('Vehicle number is required.');

        $isNew = ($id === '');
        if ($isNew) {
            $id = $this->operations_accounting->next_id($this->_counters('Vehicle'), 'VH');
        } else {
            $id = $this->safe_path_segment($id, 'vehicle_id');
            // Preserve staff_id from Firestore (not in form — future staff integration)
            if ($staffId === '') {
                $existing = $this->fs->getEntity(self::COL_VEHICLES, $id);
                $staffId = is_array($existing) ? ($existing['staff_id'] ?? '') : '';
            }
        }

        $status = trim($this->input->post('status') ?? 'Active');
        if (!in_array($status, ['Active', 'Inactive', 'Maintenance'], true)) $status = 'Active';

        $data = [
            'vehicleId'        => $id,
            'number'           => $number,
            'type'             => $type,
            'capacity'         => $capacity,
            'driver_name'      => $driverName,
            'driver_phone'     => $driverPhone,
            'staff_id'         => $staffId,
            'insurance_no'     => $insuranceNo,
            'insurance_expiry' => $insuranceExp,
            'fitness_expiry'   => $fitnessExp,
            'gps_enabled'      => $gpsEnabled,
            'status'           => $status,
            'updated_at'       => date('c'),
        ];
        if ($isNew) $data['created_at'] = date('c');

        $this->fs->setEntity(self::COL_VEHICLES, $id, $data, /* merge */ true);
        $this->json_success(['id' => $id, 'message' => 'Vehicle saved.']);
    }

    public function delete_vehicle()
    {
        $this->_require_role(self::MANAGE_ROLES, 'delete_vehicle');
        $this->_require_manage();
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'vehicle_id');

        // Block deletion if any active route references this vehicle.
        $activeRoutes = $this->firebase->firestoreQuery(self::COL_ROUTES, [
            ['schoolId',   '==', $this->school_name],
            ['vehicle_id', '==', $id],
            ['status',     '==', 'Active'],
        ]);
        if (!empty($activeRoutes)) {
            $this->json_error('Cannot delete: vehicle is assigned to an active route.');
        }
        $this->fs->remove(self::COL_VEHICLES, $this->fs->docId($id));
        $this->json_success(['message' => 'Vehicle deleted.']);
    }

    // ====================================================================
    //  ROUTES
    // ====================================================================

    public function get_routes()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        $rows = $this->firebase->firestoreQuery(self::COL_ROUTES,
            [['schoolId', '==', $this->school_name]], 'name', 'ASC');
        $list = [];
        foreach ((array) $rows as $doc) {
            $r = is_array($doc) ? $doc : [];
            $id = (string) ($r['routeId'] ?? $r['id'] ?? '');
            if ($id === '') continue;
            $r['id'] = $id;
            $list[] = $r;
        }
        $this->json_success(['routes' => $list]);
    }

    public function save_route()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_route');
        $this->_require_manage();
        $id         = trim($this->input->post('id') ?? '');
        $name       = trim($this->input->post('name') ?? '');
        $vehicleId  = trim($this->input->post('vehicle_id') ?? '');
        $startPoint = trim($this->input->post('start_point') ?? '');
        $endPoint   = trim($this->input->post('end_point') ?? '');
        $distanceKm = (float) ($this->input->post('distance_km') ?? 0);
        $monthlyFee = (float) ($this->input->post('monthly_fee') ?? 0);

        if ($name === '') $this->json_error('Route name is required.');

        $isNew = ($id === '');
        if ($isNew) {
            $id = $this->operations_accounting->next_id($this->_counters('Route'), 'RT');
        } else {
            $id = $this->safe_path_segment($id, 'route_id');
        }

        $data = [
            'routeId'     => $id,
            'name'        => $name,
            'vehicle_id'  => $vehicleId,
            'start_point' => $startPoint,
            'end_point'   => $endPoint,
            'distance_km' => $distanceKm,
            'monthly_fee' => $monthlyFee,
            'status'      => 'Active',
            'updated_at'  => date('c'),
        ];
        if ($isNew) $data['created_at'] = date('c');

        $this->fs->setEntity(self::COL_ROUTES, $id, $data, /* merge */ true);
        $this->json_success(['id' => $id, 'message' => 'Route saved.']);
    }

    public function delete_route()
    {
        $this->_require_role(self::MANAGE_ROLES, 'delete_route');
        $this->_require_manage();
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'route_id');

        // Block deletion if any student is assigned.
        $studentsOnRoute = $this->firebase->firestoreQuery(self::COL_ASSIGNMENTS, [
            ['schoolId', '==', $this->school_name],
            ['route_id', '==', $id],
        ]);
        if (!empty($studentsOnRoute)) {
            $this->json_error('Cannot delete: students are assigned to this route.');
        }

        // Delete associated stops for this route.
        $routeStops = $this->firebase->firestoreQuery(self::COL_STOPS, [
            ['schoolId', '==', $this->school_name],
            ['route_id', '==', $id],
        ]);
        foreach ((array) $routeStops as $s) {
            $sid = (string) ($s['stopId'] ?? $s['id'] ?? '');
            if ($sid !== '') $this->fs->remove(self::COL_STOPS, $this->fs->docId($sid));
        }

        $this->fs->remove(self::COL_ROUTES, $this->fs->docId($id));
        $this->json_success(['message' => 'Route and associated stops deleted.']);
    }

    // ====================================================================
    //  STOPS
    // ====================================================================

    /** GET — Stops for a route. ?route_id=RT0001 */
    public function get_stops()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        $routeId = trim($this->input->get('route_id') ?? '');

        $where = [['schoolId', '==', $this->school_name]];
        if ($routeId !== '') $where[] = ['route_id', '==', $routeId];

        $rows = $this->firebase->firestoreQuery(self::COL_STOPS, $where);
        $list = [];
        foreach ((array) $rows as $doc) {
            $s = is_array($doc) ? $doc : [];
            $id = (string) ($s['stopId'] ?? $s['id'] ?? '');
            if ($id === '') continue;
            $s['id'] = $id;
            $list[] = $s;
        }
        usort($list, function ($a, $b) {
            return ((int) ($a['order'] ?? 0)) - ((int) ($b['order'] ?? 0));
        });
        $this->json_success(['stops' => $list]);
    }

    public function save_stop()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_stop');
        $this->_require_manage();
        $id         = trim($this->input->post('id') ?? '');
        $routeId    = $this->safe_path_segment(trim($this->input->post('route_id') ?? ''), 'route_id');
        $name       = trim($this->input->post('name') ?? '');
        $pickupTime = trim($this->input->post('pickup_time') ?? '');
        $dropTime   = trim($this->input->post('drop_time') ?? '');
        $order      = max(0, (int) ($this->input->post('order') ?? 0));

        if ($name === '') $this->json_error('Stop name is required.');

        $isNew = ($id === '');
        if ($isNew) {
            $id = $this->operations_accounting->next_id($this->_counters('Stop'), 'STP');
        } else {
            $id = $this->safe_path_segment($id, 'stop_id');
        }

        $data = [
            'stopId'      => $id,
            'route_id'    => $routeId,
            'name'        => $name,
            'pickup_time' => $pickupTime,
            'drop_time'   => $dropTime,
            'order'       => $order,
            'status'      => 'Active',
            'updated_at'  => date('c'),
        ];
        if ($isNew) $data['created_at'] = date('c');

        $this->fs->setEntity(self::COL_STOPS, $id, $data, /* merge */ true);
        $this->json_success(['id' => $id, 'message' => 'Stop saved.']);
    }

    public function delete_stop()
    {
        $this->_require_role(self::MANAGE_ROLES, 'delete_stop');
        $this->_require_manage();
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'stop_id');
        $this->fs->remove(self::COL_STOPS, $this->fs->docId($id));
        $this->json_success(['message' => 'Stop deleted.']);
    }

    // ====================================================================
    //  STUDENT ASSIGNMENTS
    // ====================================================================

    public function get_assignments()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        $rows = $this->firebase->firestoreQuery(self::COL_ASSIGNMENTS,
            [['schoolId', '==', $this->school_name]], 'student_name', 'ASC');
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
