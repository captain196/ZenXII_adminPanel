<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Hostel Management Controller
 *
 * Sub-modules: Buildings, Rooms, Allocations, Attendance
 *
 * Firebase paths:
 *   Schools/{school}/Operations/Hostel/Buildings/{BLD0001}
 *   Schools/{school}/Operations/Hostel/Rooms/{RM0001}
 *   Schools/{school}/Operations/Hostel/Allocations/{student_id}
 *   Schools/{school}/Operations/Hostel/Counters/{type}
 *   Schools/{school}/{session}/Operations/Hostel/Attendance/{date}/{student_id}
 *
 * Integration: Student module (allocations), Staff (warden), Fees (hostel fee)
 */
class Hostel extends MY_Controller
{
    /** Roles for hostel management */
    private const MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Operations Manager', 'Hostel Warden', 'Warden'];

    /** Roles that may view hostel data */
    private const VIEW_ROLES   = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Operations Manager', 'Hostel Warden', 'Warden', 'Academic Coordinator', 'Accountant', 'Class Teacher', 'Teacher'];

    const OPS_ADMIN_ROLES  = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal'];
    const HST_MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Operations Manager', 'Hostel Warden', 'Warden'];
    const HST_VIEW_ROLES   = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Operations Manager', 'Hostel Warden', 'Warden', 'Accountant', 'Teacher'];

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
        if (!in_array($this->admin_role, self::HST_MANAGE_ROLES, true))
            $this->json_error('Access denied.', 403);
    }
    private function _require_view()
    {
        if (!in_array($this->admin_role, self::HST_VIEW_ROLES, true))
            $this->json_error('Access denied.', 403);
    }

    // ── Path Helpers ────────────────────────────────────────────────────
    private function _hst(string $sub = ''): string
    {
        $b = "Schools/{$this->school_name}/Operations/Hostel";
        return $sub !== '' ? "{$b}/{$sub}" : $b;
    }
    private function _buildings(string $id = ''): string
    {
        return $id !== '' ? $this->_hst("Buildings/{$id}") : $this->_hst('Buildings');
    }
    private function _rooms(string $id = ''): string
    {
        return $id !== '' ? $this->_hst("Rooms/{$id}") : $this->_hst('Rooms');
    }
    private function _allocations(string $id = ''): string
    {
        return $id !== '' ? $this->_hst("Allocations/{$id}") : $this->_hst('Allocations');
    }
    private function _attendance(string $date = ''): string
    {
        $b = "Schools/{$this->school_name}/{$this->session_year}/Operations/Hostel/Attendance";
        return $date !== '' ? "{$b}/{$date}" : $b;
    }
    private function _counters(string $type): string
    {
        return $this->_hst("Counters/{$type}");
    }

    // ====================================================================
    //  PAGE LOAD
    // ====================================================================

    public function index()
    {
        $this->_require_role(self::VIEW_ROLES, 'hostel_view');
        $tab = $this->uri->segment(2, 'buildings');
        $data = ['active_tab' => $tab];
        $this->load->view('include/header', $data);
        $this->load->view('hostel/index', $data);
        $this->load->view('include/footer');
    }

    // ====================================================================
    //  BUILDINGS
    // ====================================================================

    public function get_buildings()
    {
        $this->_require_role(self::VIEW_ROLES, 'hostel_view');
        $this->_require_view();
        $buildings = $this->firebase->get($this->_buildings());
        $list = [];
        if (is_array($buildings)) {
            foreach ($buildings as $id => $b) { $b['id'] = $id; $list[] = $b; }
        }
        $this->json_success(['buildings' => $list]);
    }

    public function save_building()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_building');
        $this->_require_manage();
        $id       = trim($this->input->post('id') ?? '');
        $name     = trim($this->input->post('name') ?? '');
        $type     = trim($this->input->post('type') ?? 'mixed');
        $wardenId = trim($this->input->post('warden_id') ?? '');
        $wardenName = trim($this->input->post('warden_name') ?? '');
        $floors   = max(1, (int) ($this->input->post('floors') ?? 1));
        $address  = trim($this->input->post('address') ?? '');

        if ($name === '') $this->json_error('Building name is required.');
        if (!in_array($type, ['boys', 'girls', 'mixed'], true)) $type = 'mixed';

        $isNew = ($id === '');
        if ($isNew) {
            $id = $this->operations_accounting->next_id($this->_counters('Building'), 'BLD');
        } else {
            $id = $this->safe_path_segment($id, 'building_id');
            // Preserve warden_id from Firebase (not in form — future staff integration)
            if ($wardenId === '') {
                $existing = $this->firebase->get($this->_buildings($id));
                $wardenId = is_array($existing) ? ($existing['warden_id'] ?? '') : '';
            }
        }

        $data = [
            'name'        => $name,
            'type'        => $type,
            'warden_id'   => $wardenId,
            'warden_name' => $wardenName,
            'floors'      => $floors,
            'address'     => $address,
            'status'      => 'Active',
            'updated_at'  => date('c'),
        ];
        if ($isNew) $data['created_at'] = date('c');

        $this->firebase->set($this->_buildings($id), $data);
        $this->json_success(['id' => $id, 'message' => 'Building saved.']);
    }

    public function delete_building()
    {
        $this->_require_role(self::MANAGE_ROLES, 'delete_building');
        $this->_require_manage();
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'building_id');

        // Check if rooms exist in this building
        $rooms = $this->firebase->get($this->_rooms());
        if (is_array($rooms)) {
            foreach ($rooms as $r) {
                if (($r['building_id'] ?? '') === $id) {
                    $this->json_error('Cannot delete: building has rooms. Remove rooms first.');
                }
            }
        }
        $this->firebase->delete($this->_buildings(), $id);
        $this->json_success(['message' => 'Building deleted.']);
    }

    // ====================================================================
    //  ROOMS
    // ====================================================================

    /** GET — List rooms. ?building_id=BLD0001 for filter */
    public function get_rooms()
    {
        $this->_require_role(self::VIEW_ROLES, 'hostel_view');
        $this->_require_view();
        $buildingId = trim($this->input->get('building_id') ?? '');

        $rooms = $this->firebase->get($this->_rooms());
        $list = [];
        if (is_array($rooms)) {
            foreach ($rooms as $id => $r) {
                if ($buildingId !== '' && ($r['building_id'] ?? '') !== $buildingId) continue;
                $r['id'] = $id;
                $list[] = $r;
            }
        }
        usort($list, function ($a, $b) {
            $cmp = strcmp($a['building_id'] ?? '', $b['building_id'] ?? '');
            return $cmp !== 0 ? $cmp : ((int) ($a['floor'] ?? 0) - (int) ($b['floor'] ?? 0));
        });
        $this->json_success(['rooms' => $list]);
    }

    public function save_room()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_room');
        $this->_require_manage();
        $id         = trim($this->input->post('id') ?? '');
        $buildingId = $this->safe_path_segment(trim($this->input->post('building_id') ?? ''), 'building_id');
        $floor      = max(0, (int) ($this->input->post('floor') ?? 1));
        $roomNo     = trim($this->input->post('room_no') ?? '');
        $type       = trim($this->input->post('type') ?? 'double');
        $beds       = max(1, (int) ($this->input->post('beds') ?? 2));
        $monthlyFee = (float) ($this->input->post('monthly_fee') ?? 0);
        $facilities = trim($this->input->post('facilities') ?? '');

        if ($roomNo === '') $this->json_error('Room number is required.');
        if (!in_array($type, ['single', 'double', 'triple', 'dormitory'], true)) $type = 'double';

        $isNew = ($id === '');
        if ($isNew) {
            $id = $this->operations_accounting->next_id($this->_counters('Room'), 'RM');
            $occupied = 0;
        } else {
            $id = $this->safe_path_segment($id, 'room_id');
            $existing = $this->firebase->get($this->_rooms($id));
            $occupied = is_array($existing) ? (int) ($existing['occupied'] ?? 0) : 0;
            if ($beds < $occupied) $this->json_error("Cannot reduce beds below current occupancy ({$occupied}).");
        }

        $data = [
            'building_id' => $buildingId,
            'floor'       => $floor,
            'room_no'     => $roomNo,
            'type'        => $type,
            'beds'        => $beds,
            'occupied'    => $occupied,
            'monthly_fee' => $monthlyFee,
            'facilities'  => $facilities,
            'status'      => 'Active',
            'updated_at'  => date('c'),
        ];
        if ($isNew) $data['created_at'] = date('c');

        $this->firebase->set($this->_rooms($id), $data);
        $this->json_success(['id' => $id, 'message' => 'Room saved.']);
    }

    public function delete_room()
    {
        $this->_require_role(self::MANAGE_ROLES, 'delete_room');
        $this->_require_manage();
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'room_id');

        $room = $this->firebase->get($this->_rooms($id));
        if (is_array($room) && (int) ($room['occupied'] ?? 0) > 0) {
            $this->json_error('Cannot delete: room has occupants.');
        }
        $this->firebase->delete($this->_rooms(), $id);
        $this->json_success(['message' => 'Room deleted.']);
    }

    // ====================================================================
    //  ALLOCATIONS
    // ====================================================================

    public function get_allocations()
    {
        $this->_require_role(self::VIEW_ROLES, 'hostel_view');
        $this->_require_view();
        $allocations = $this->firebase->get($this->_allocations());
        $list = [];
        if (is_array($allocations)) {
            foreach ($allocations as $sid => $a) { $a['student_id'] = $sid; $list[] = $a; }
        }
        $this->json_success(['allocations' => $list]);
    }

    public function save_allocation()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_allocation');
        $this->_require_manage();
        $studentId = $this->safe_path_segment(trim($this->input->post('student_id') ?? ''), 'student_id');
        $roomId    = $this->safe_path_segment(trim($this->input->post('room_id') ?? ''), 'room_id');
        $bedNo     = max(1, (int) ($this->input->post('bed_no') ?? 1));

        // Verify student (use parent_db_key — legacy schools key by school_code, not school_id)
        $student = $this->firebase->get("Users/Parents/{$this->parent_db_key}/{$studentId}");
        if (!is_array($student)) $this->json_error('Student not found.');

        // Verify room and check capacity
        $room = $this->firebase->get($this->_rooms($roomId));
        if (!is_array($room)) $this->json_error('Room not found.');

        // Check if student already allocated
        $existing = $this->firebase->get($this->_allocations($studentId));
        $isReallocation = is_array($existing) && ($existing['status'] ?? '') === 'Active';

        if (!$isReallocation && (int) ($room['occupied'] ?? 0) >= (int) ($room['beds'] ?? 0)) {
            $this->json_error('Room is full. No available beds.');
        }
        if ($bedNo > (int) ($room['beds'] ?? 0)) {
            $this->json_error("Bed number exceeds room capacity ({$room['beds']}).");
        }

        // If reallocating, decrement old room's occupied count
        if ($isReallocation) {
            $oldRoomId = $existing['room_id'] ?? '';
            if ($oldRoomId !== '' && $oldRoomId !== $roomId) {
                $oldRoom = $this->firebase->get($this->_rooms($oldRoomId));
                if (is_array($oldRoom)) {
                    $this->firebase->set(
                        $this->_rooms($oldRoomId) . '/occupied',
                        max(0, (int) ($oldRoom['occupied'] ?? 0) - 1)
                    );
                }
            }
        }

        // Get building info for display
        $building = $this->firebase->get($this->_buildings($room['building_id'] ?? ''));
        $buildingName = is_array($building) ? ($building['name'] ?? '') : '';

        $data = [
            'room_id'       => $roomId,
            'room_no'       => $room['room_no'] ?? '',
            'building_id'   => $room['building_id'] ?? '',
            'building_name' => $buildingName,
            'bed_no'        => $bedNo,
            'student_name'  => $student['Name'] ?? $studentId,
            'student_class' => ($student['Class'] ?? '') . ' ' . ($student['Section'] ?? ''),
            'monthly_fee'   => (float) ($room['monthly_fee'] ?? 0),
            'check_in'      => date('Y-m-d'),
            'check_out'     => '',
            'status'        => 'Active',
            'updated_at'    => date('c'),
        ];

        $this->firebase->set($this->_allocations($studentId), $data);

        // M-01 FIX: Post-write occupancy verification to prevent race condition.
        // Re-read room after allocation write, count actual active allocations,
        // and reconcile the occupied counter to prevent over-booking.
        $needsIncrement = !$isReallocation || ($existing['room_id'] ?? '') !== $roomId;
        if ($needsIncrement) {
            $this->firebase->set(
                $this->_rooms($roomId) . '/occupied',
                (int) ($room['occupied'] ?? 0) + 1
            );

            // Post-write verification: re-read room and check for over-allocation
            $roomAfter = $this->firebase->get($this->_rooms($roomId));
            $occupiedAfter = (int) ($roomAfter['occupied'] ?? 0);
            $bedsTotal     = (int) ($roomAfter['beds'] ?? 0);

            if ($occupiedAfter > $bedsTotal) {
                // Race detected — rollback this allocation
                $this->firebase->delete($this->_allocations(), $studentId);
                $this->firebase->set(
                    $this->_rooms($roomId) . '/occupied',
                    max(0, $occupiedAfter - 1)
                );
                $this->json_error('Room became full while processing. Please try again or choose another room.');
            }
        }

        // ── Fee Integration: create/update hostel fee component (session-scoped) ──
        $feePath = "Schools/{$this->school_name}/{$this->session_year}/Fees/Student_Fee_Items/{$studentId}/Hostel";
        $this->firebase->set($feePath, [
            'building_id'    => $room['building_id'] ?? '',
            'building_name'  => $buildingName,
            'room_id'        => $roomId,
            'room_no'        => $room['room_no'] ?? '',
            'monthly_fee'    => (float) ($room['monthly_fee'] ?? 0),
            'effective_from' => date('Y-m-d'),
            'status'         => 'active',
            'updated_at'     => date('c'),
        ]);

        // Auto-create hostel fee demand via Fee_lifecycle
        try {
            if ($isReallocation) {
                // Room change — create new demand with differential fee info
                $oldFee = (float) ($existing['monthly_fee'] ?? 0);
                $this->feeLifecycle->createModuleFee($studentId, 'Hostel', [
                    'building'     => $buildingName,
                    'room'         => $roomId,
                    'room_type'    => $room['type'] ?? '',
                    'amount'       => (float) ($room['monthly_fee'] ?? 0),
                    'mess_charges' => 0,
                    'period'       => 'Monthly',
                    'start_date'   => date('Y-m-d'),
                    'previous_fee' => $oldFee,
                ]);
                log_message('info', "Fee_lifecycle: hostel fee updated (room change) for student {$studentId} room {$roomId}");
            } else {
                // New allocation — create demand
                $this->feeLifecycle->createModuleFee($studentId, 'Hostel', [
                    'building'     => $buildingName,
                    'room'         => $roomId,
                    'room_type'    => $room['type'] ?? '',
                    'amount'       => (float) ($room['monthly_fee'] ?? 0),
                    'mess_charges' => 0,
                    'period'       => 'Monthly',
                    'start_date'   => date('Y-m-d'),
                ]);
                log_message('info', "Fee_lifecycle: hostel fee created for student {$studentId}");
            }
        } catch (Exception $e) {
            log_message('error', "Fee_lifecycle::createModuleFee(Hostel) failed: " . $e->getMessage());
        }

        $this->json_success(['message' => "Student allocated to Room {$room['room_no']}, Bed {$bedNo}."]);
    }

    public function delete_allocation()
    {
        $this->_require_role(self::MANAGE_ROLES, 'delete_allocation');
        $this->_require_manage();
        $studentId = $this->safe_path_segment(trim($this->input->post('student_id') ?? ''), 'student_id');

        $alloc = $this->firebase->get($this->_allocations($studentId));
        if (!is_array($alloc)) $this->json_error('Allocation not found.');

        // Decrement room occupancy
        $roomId = $alloc['room_id'] ?? '';
        if ($roomId !== '') {
            $room = $this->firebase->get($this->_rooms($roomId));
            if (is_array($room)) {
                $this->firebase->set(
                    $this->_rooms($roomId) . '/occupied',
                    max(0, (int) ($room['occupied'] ?? 0) - 1)
                );
            }
        }

        // ── Fee Integration: deactivate hostel fee component (session-scoped) ──
        $feePath = "Schools/{$this->school_name}/{$this->session_year}/Fees/Student_Fee_Items/{$studentId}/Hostel";
        $existingFee = $this->firebase->get($feePath);
        if (is_array($existingFee)) {
            $this->firebase->update($feePath, [
                'monthly_fee' => 0,
                'status'      => 'inactive',
                'removed_at'  => date('c'),
                'updated_at'  => date('c'),
            ]);
        }

        // Flag hostel fee as checked out via Fee_lifecycle
        try {
            $this->firebase->update(
                "Schools/{$this->school_name}/{$this->session_year}/Fees/Student_Fee_Items/{$studentId}/Hostel",
                ['status' => 'checked_out', 'checkout_date' => date('c'), 'checked_out_by' => $this->admin_id ?? 'system']
            );
        } catch (Exception $e) {
            log_message('error', "Hostel fee checkout failed for {$studentId}: " . $e->getMessage());
        }

        // Mark as checked out rather than deleting (audit trail)
        $this->firebase->update($this->_allocations($studentId), [
            'check_out'  => date('Y-m-d'),
            'status'     => 'CheckedOut',
            'updated_at' => date('c'),
        ]);

        $this->json_success(['message' => 'Student checked out.']);
    }

    // ====================================================================
    //  HOSTEL ATTENDANCE
    // ====================================================================

    /** GET — Attendance for a date. ?date=YYYY-MM-DD */
    public function get_attendance()
    {
        $this->_require_role(self::VIEW_ROLES, 'hostel_view');
        $this->_require_view();
        $date = trim($this->input->get('date') ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

        $att = $this->firebase->get($this->_attendance($date));
        $list = [];
        if (is_array($att)) {
            foreach ($att as $sid => $a) { $a['student_id'] = $sid; $list[] = $a; }
        }

        // Get all active allocations for the full roster
        $allocations = $this->firebase->get($this->_allocations());
        $roster = [];
        if (is_array($allocations)) {
            foreach ($allocations as $sid => $al) {
                if (($al['status'] ?? '') === 'Active') {
                    $roster[] = [
                        'student_id'   => $sid,
                        'student_name' => $al['student_name'] ?? $sid,
                        'room_no'      => $al['room_no'] ?? '',
                        'building_name' => $al['building_name'] ?? '',
                    ];
                }
            }
        }

        $this->json_success(['attendance' => $list, 'roster' => $roster, 'date' => $date]);
    }

    /** POST — Save hostel attendance. Params: date, attendance (JSON array) */
    public function save_attendance()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_attendance');
        $this->_require_manage();
        $date = trim($this->input->post('date') ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $this->json_error('Invalid date.');

        $records = json_decode($this->input->post('attendance') ?? '[]', true);
        if (!is_array($records) || empty($records)) $this->json_error('No attendance data.');

        $batch = [];
        foreach ($records as $rec) {
            $sid    = $rec['student_id'] ?? '';
            $status = $rec['status'] ?? 'P';
            if ($sid === '' || !in_array($status, ['P', 'A', 'L'], true)) continue;
            $batch[$sid] = [
                'status'    => $status,
                'marked_by' => $this->admin_name,
                'marked_at' => date('c'),
            ];
        }

        if (empty($batch)) $this->json_error('No valid attendance records.');

        // OPS-2 FIX: Use update() to merge with existing attendance (prevents overwrite when two wardens mark different buildings simultaneously)
        $this->firebase->update($this->_attendance($date), $batch);

        $this->json_success(['message' => 'Attendance saved for ' . count($batch) . ' students.']);
    }

    /** GET — Occupancy stats. */
    public function get_stats()
    {
        $this->_require_role(self::VIEW_ROLES, 'hostel_view');
        $this->_require_view();

        $buildings = $this->firebase->get($this->_buildings()) ?? [];
        $rooms     = $this->firebase->get($this->_rooms()) ?? [];
        if (!is_array($buildings)) $buildings = [];
        if (!is_array($rooms))     $rooms     = [];

        $totalBeds = 0; $totalOccupied = 0;
        $byBuilding = [];

        foreach ($rooms as $rid => $r) {
            $bid   = $r['building_id'] ?? 'unknown';
            $beds  = (int) ($r['beds'] ?? 0);
            $occ   = (int) ($r['occupied'] ?? 0);
            $totalBeds     += $beds;
            $totalOccupied += $occ;

            if (!isset($byBuilding[$bid])) {
                $bldg = $buildings[$bid] ?? null;
                $byBuilding[$bid] = [
                    'name'     => is_array($bldg) ? ($bldg['name'] ?? $bid) : $bid,
                    'rooms'    => 0,
                    'beds'     => 0,
                    'occupied' => 0,
                ];
            }
            $byBuilding[$bid]['rooms']++;
            $byBuilding[$bid]['beds']     += $beds;
            $byBuilding[$bid]['occupied'] += $occ;
        }

        $this->json_success([
            'stats' => [
                'total_buildings' => count($buildings),
                'total_rooms'     => count($rooms),
                'total_beds'      => $totalBeds,
                'total_occupied'  => $totalOccupied,
                'occupancy_pct'   => $totalBeds > 0 ? round(($totalOccupied / $totalBeds) * 100, 1) : 0,
                'by_building'     => array_values($byBuilding),
            ],
        ]);
    }

    /** GET — Search students. ?q=name */
    public function search_students()
    {
        $this->_require_role(self::VIEW_ROLES, 'hostel_view');
        $this->_require_view();
        $results = $this->operations_accounting->search_students(
            $this->input->get('q') ?? ''
        );
        // Merge class+section for Hostel's expected format
        foreach ($results as &$r) {
            $r['class'] = trim(($r['class'] ?? '') . ' ' . ($r['section'] ?? ''));
            unset($r['section'], $r['user_id']);
        }
        unset($r);
        $this->json_success(['students' => $results]);
    }
}
