<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Hostel Controller — buildings, rooms, allocations, attendance.
 *
 * Phase 5 — fully migrated off the Realtime Database onto Firestore.
 * Firestore collections used (all auto-scoped by schoolId via
 * Firestore_service::docId()):
 *
 *   hostelBuildings/{schoolId}_{buildingId}
 *   hostelRooms/{schoolId}_{roomId}
 *   hostelAllocations/{schoolId}_{studentId}
 *   hostelAttendance/{schoolId}_{date}_{studentId}
 *
 * The Parent + Teacher apps already subscribe to hostelRooms /
 * hostelAllocations / hostelComplaints via CampusLifeFirestoreRepository,
 * so moving the admin writes here makes the three surfaces consistent
 * in real time. Fee integration still flows through Fee_lifecycle
 * (itself Firestore-only post-Phase-5), which writes a demand doc with
 * category=Hostel.
 */
class Hostel extends MY_Controller
{
    private const HST_VIEW_ROLES   = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Hostel Warden', 'Accountant', 'Operations Manager'];
    private const HST_MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Hostel Warden', 'Operations Manager'];

    private const COL_BUILDINGS  = 'hostelBuildings';
    private const COL_ROOMS      = 'hostelRooms';
    private const COL_ALLOCS     = 'hostelAllocations';
    private const COL_ATTENDANCE = 'hostelAttendance';

    public function __construct()
    {
        parent::__construct();
        $this->load->library('Operations_accounting', null, 'operations_accounting');
        $this->operations_accounting->init(
            $this->firebase, $this->school_name, $this->session_year,
            $this->admin_id ?? 'system', $this
        );
        $this->load->library('Fee_lifecycle', null, 'feeLifecycle');
        $this->feeLifecycle->init($this->firebase, $this->school_name, $this->session_year, $this->admin_id ?? 'system');
    }

    // ── RBAC helpers ───────────────────────────────────────────────────
    private function _require_manage(): void
    {
        if (!in_array($this->admin_role, self::HST_MANAGE_ROLES, true)) $this->json_error('Access denied.', 403);
    }
    private function _require_view(): void
    {
        if (!in_array($this->admin_role, self::HST_VIEW_ROLES, true)) $this->json_error('Access denied.', 403);
    }

    // ── Doc ID helpers (Firestore) ────────────────────────────────────
    private function _buildingDocId(string $id): string    { return $this->fs->docId($id); }
    private function _roomDocId(string $id): string        { return $this->fs->docId($id); }
    private function _allocDocId(string $studentId): string{ return $this->fs->docId($studentId); }
    private function _attendDocId(string $date, string $studentId): string
    {
        return $this->fs->docId2($date, $studentId);
    }
    private function _counters(string $type): string
    {
        // Keep counter path identical to the legacy RTDB path so the
        // existing Operations_accounting::next_id() issues the same
        // BLD/RM sequence. next_id() itself already runs on Firestore.
        return "Schools/{$this->school_name}/Operations/Hostel/Counters/{$type}";
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
        $rows = $this->firebase->firestoreQuery(self::COL_BUILDINGS,
            [['schoolId', '==', $this->school_name]], 'name', 'ASC');
        $list = [];
        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? $r; if (!is_array($d)) continue;
            $d['id'] = $d['buildingId'] ?? ($r['id'] ?? '');
            $list[] = $d;
        }
        $this->json_success(['buildings' => $list]);
    }

    public function save_building()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_building');
        $this->_require_manage();
        $id         = trim($this->input->post('id') ?? '');
        $name       = trim($this->input->post('name') ?? '');
        $type       = trim($this->input->post('type') ?? 'mixed');
        $wardenId   = trim($this->input->post('warden_id') ?? '');
        $wardenName = trim($this->input->post('warden_name') ?? '');
        $floors     = max(1, (int) ($this->input->post('floors') ?? 1));
        $address    = trim($this->input->post('address') ?? '');

        if ($name === '') $this->json_error('Building name is required.');
        if (!in_array($type, ['boys', 'girls', 'mixed'], true)) $type = 'mixed';

        $isNew = ($id === '');
        if ($isNew) {
            $id = $this->operations_accounting->next_id($this->_counters('Building'), 'BLD');
        } else {
            $id = $this->safe_path_segment($id, 'building_id');
            if ($wardenId === '') {
                $existing = $this->fs->getEntity(self::COL_BUILDINGS, $id);
                $wardenId = is_array($existing) ? ($existing['warden_id'] ?? $existing['wardenId'] ?? '') : '';
            }
        }

        $data = [
            'buildingId'  => $id,
            'name'        => $name,
            'type'        => $type,
            'warden_id'   => $wardenId,    // legacy snake_case kept for backward read
            'wardenId'    => $wardenId,    // canonical camelCase
            'warden_name' => $wardenName,
            'wardenName'  => $wardenName,
            'floors'      => $floors,
            'address'     => $address,
            'status'      => 'Active',
            'updatedAt'   => date('c'),
        ];
        if ($isNew) $data['createdAt'] = date('c');

        $this->fs->setEntity(self::COL_BUILDINGS, $id, $data, /* merge */ true);
        $this->json_success(['id' => $id, 'message' => 'Building saved.']);
    }

    public function delete_building()
    {
        $this->_require_role(self::MANAGE_ROLES, 'delete_building');
        $this->_require_manage();
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'building_id');

        // Block delete if rooms exist in this building.
        $rooms = $this->firebase->firestoreQuery(self::COL_ROOMS, [
            ['schoolId',    '==', $this->school_name],
            ['building_id', '==', $id],
        ], null, 'ASC', 1);
        if (!empty($rooms)) $this->json_error('Cannot delete: building has rooms. Remove rooms first.');

        $this->fs->remove(self::COL_BUILDINGS, $this->_buildingDocId($id));
        $this->json_success(['message' => 'Building deleted.']);
    }

    // ====================================================================
    //  ROOMS
    // ====================================================================
    public function get_rooms()
    {
        $this->_require_role(self::VIEW_ROLES, 'hostel_view');
        $this->_require_view();
        $buildingId = trim($this->input->get('building_id') ?? '');

        $filters = [['schoolId', '==', $this->school_name]];
        if ($buildingId !== '') $filters[] = ['building_id', '==', $buildingId];
        $rows = $this->firebase->firestoreQuery(self::COL_ROOMS, $filters);

        $list = [];
        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? $r; if (!is_array($d)) continue;
            $d['id'] = $d['roomId'] ?? ($r['id'] ?? '');
            $list[] = $d;
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
            $existing = $this->fs->getEntity(self::COL_ROOMS, $id);
            $occupied = is_array($existing) ? (int) ($existing['occupied'] ?? 0) : 0;
            if ($beds < $occupied) $this->json_error("Cannot reduce beds below current occupancy ({$occupied}).");
        }

        $data = [
            'roomId'      => $id,
            'building_id' => $buildingId,
            'floor'       => $floor,
            'room_no'     => $roomNo,
            'type'        => $type,
            'beds'        => $beds,
            'occupied'    => $occupied,
            'monthly_fee' => $monthlyFee,
            'facilities'  => $facilities,
            'status'      => 'Active',
            'updatedAt'   => date('c'),
        ];
        if ($isNew) $data['createdAt'] = date('c');

        $this->fs->setEntity(self::COL_ROOMS, $id, $data, /* merge */ true);
        $this->json_success(['id' => $id, 'message' => 'Room saved.']);
    }

    public function delete_room()
    {
        $this->_require_role(self::MANAGE_ROLES, 'delete_room');
        $this->_require_manage();
        $id   = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'room_id');
        $room = $this->fs->getEntity(self::COL_ROOMS, $id);
        if (is_array($room) && (int) ($room['occupied'] ?? 0) > 0) {
            $this->json_error('Cannot delete: room has occupants.');
        }
        $this->fs->remove(self::COL_ROOMS, $this->_roomDocId($id));
        $this->json_success(['message' => 'Room deleted.']);
    }

    // ====================================================================
    //  ALLOCATIONS
    // ====================================================================
    public function get_allocations()
    {
        $this->_require_role(self::VIEW_ROLES, 'hostel_view');
        $this->_require_view();
        $rows = $this->firebase->firestoreQuery(self::COL_ALLOCS,
            [['schoolId', '==', $this->school_name]]);
        $list = [];
        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? $r; if (!is_array($d)) continue;
            $d['student_id'] = $d['studentId'] ?? $d['student_id'] ?? ($r['id'] ?? '');
            $list[] = $d;
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

        // Verify student from the Firestore `students` collection (the
        // legacy RTDB Users/Parents/… lookup is no longer consulted).
        $student = $this->firebase->firestoreGet('students', "{$this->school_name}_{$studentId}");
        if (!is_array($student)) $this->json_error('Student not found.');

        $room = $this->fs->getEntity(self::COL_ROOMS, $roomId);
        if (!is_array($room)) $this->json_error('Room not found.');

        $existing       = $this->fs->getEntity(self::COL_ALLOCS, $studentId);
        $isReallocation = is_array($existing) && ($existing['status'] ?? '') === 'Active';

        if (!$isReallocation && (int) ($room['occupied'] ?? 0) >= (int) ($room['beds'] ?? 0)) {
            $this->json_error('Room is full. No available beds.');
        }
        if ($bedNo > (int) ($room['beds'] ?? 0)) {
            $this->json_error("Bed number exceeds room capacity ({$room['beds']}).");
        }

        // Reallocation: decrement the previous room's occupied counter.
        if ($isReallocation) {
            $oldRoomId = $existing['room_id'] ?? '';
            if ($oldRoomId !== '' && $oldRoomId !== $roomId) {
                $oldRoom = $this->fs->getEntity(self::COL_ROOMS, $oldRoomId);
                if (is_array($oldRoom)) {
                    $this->fs->setEntity(self::COL_ROOMS, $oldRoomId, [
                        'occupied'  => max(0, (int) ($oldRoom['occupied'] ?? 0) - 1),
                        'updatedAt' => date('c'),
                    ], /* merge */ true);
                }
            }
        }

        $building     = $this->fs->getEntity(self::COL_BUILDINGS, (string) ($room['building_id'] ?? ''));
        $buildingName = is_array($building) ? ($building['name'] ?? '') : '';

        $data = [
            'studentId'     => $studentId,
            'room_id'       => $roomId,
            'room_no'       => $room['room_no'] ?? '',
            'building_id'   => $room['building_id'] ?? '',
            'building_name' => $buildingName,
            'bed_no'        => $bedNo,
            'student_name'  => $student['name'] ?? $student['studentName'] ?? $studentId,
            'student_class' => ($student['className'] ?? '') . ' ' . ($student['section'] ?? ''),
            'monthly_fee'   => (float) ($room['monthly_fee'] ?? 0),
            'check_in'      => date('Y-m-d'),
            'check_out'     => '',
            'status'        => 'Active',
            'updatedAt'     => date('c'),
        ];
        $this->fs->setEntity(self::COL_ALLOCS, $studentId, $data, /* merge */ true);

        // Post-write occupancy verification (OPS M-01 race guard).
        $needsIncrement = !$isReallocation || ($existing['room_id'] ?? '') !== $roomId;
        if ($needsIncrement) {
            $this->fs->setEntity(self::COL_ROOMS, $roomId, [
                'occupied'  => (int) ($room['occupied'] ?? 0) + 1,
                'updatedAt' => date('c'),
            ], /* merge */ true);
            $roomAfter     = $this->fs->getEntity(self::COL_ROOMS, $roomId);
            $occupiedAfter = (int) (($roomAfter['occupied'] ?? 0));
            $bedsTotal     = (int) (($roomAfter['beds']     ?? 0));
            if ($occupiedAfter > $bedsTotal) {
                // Race detected — rollback.
                $this->fs->remove(self::COL_ALLOCS, $this->_allocDocId($studentId));
                $this->fs->setEntity(self::COL_ROOMS, $roomId, [
                    'occupied'  => max(0, $occupiedAfter - 1),
                    'updatedAt' => date('c'),
                ], /* merge */ true);
                $this->json_error('Room became full while processing. Please try again or choose another room.');
            }
        }

        // Fee integration — Fee_lifecycle writes the hostel fee demand
        // to Firestore feeDemands (category=Hostel) in the canonical shape.
        try {
            $this->feeLifecycle->createModuleFee($studentId, 'Hostel', [
                'building'     => $buildingName,
                'room'         => $roomId,
                'room_type'    => $room['type'] ?? '',
                'amount'       => (float) ($room['monthly_fee'] ?? 0),
                'mess_charges' => 0,
                'period'       => 'Monthly',
                'start_date'   => date('Y-m-d'),
                'previous_fee' => $isReallocation ? (float) ($existing['monthly_fee'] ?? 0) : 0,
            ]);
        } catch (\Exception $e) {
            log_message('error', "Fee_lifecycle::createModuleFee(Hostel) failed for {$studentId}: " . $e->getMessage());
        }

        $this->json_success(['message' => "Student allocated to Room {$room['room_no']}, Bed {$bedNo}."]);
    }

    public function delete_allocation()
    {
        $this->_require_role(self::MANAGE_ROLES, 'delete_allocation');
        $this->_require_manage();
        $studentId = $this->safe_path_segment(trim($this->input->post('student_id') ?? ''), 'student_id');

        $alloc = $this->fs->getEntity(self::COL_ALLOCS, $studentId);
        if (!is_array($alloc)) $this->json_error('Allocation not found.');

        // Decrement room occupancy.
        $roomId = $alloc['room_id'] ?? '';
        if ($roomId !== '') {
            $room = $this->fs->getEntity(self::COL_ROOMS, $roomId);
            if (is_array($room)) {
                $this->fs->setEntity(self::COL_ROOMS, $roomId, [
                    'occupied'  => max(0, (int) ($room['occupied'] ?? 0) - 1),
                    'updatedAt' => date('c'),
                ], /* merge */ true);
            }
        }

        // Mark demand as frozen via Fee_lifecycle (which writes to Firestore feeDemands).
        try {
            // A targeted "stop collecting this student's hostel fee" is
            // a lifecycle-level concern; freeze handles it cleanly.
            $this->feeLifecycle->freezeFeesOnSoftDelete($studentId);
        } catch (\Exception $e) {
            log_message('error', "Fee_lifecycle::freeze(hostel-checkout) failed for {$studentId}: " . $e->getMessage());
        }

        // Preserve audit trail — flip status instead of deleting.
        $this->fs->setEntity(self::COL_ALLOCS, $studentId, [
            'check_out' => date('Y-m-d'),
            'status'    => 'CheckedOut',
            'updatedAt' => date('c'),
        ], /* merge */ true);

        $this->json_success(['message' => 'Student checked out.']);
    }

    // ====================================================================
    //  HOSTEL ATTENDANCE
    // ====================================================================
    public function get_attendance()
    {
        $this->_require_role(self::VIEW_ROLES, 'hostel_view');
        $this->_require_view();
        $date = trim($this->input->get('date') ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

        // Attendance records for the date (one doc per student).
        $attRows = $this->firebase->firestoreQuery(self::COL_ATTENDANCE, [
            ['schoolId', '==', $this->school_name],
            ['date',     '==', $date],
        ]);
        $list = [];
        foreach ((array) $attRows as $r) {
            $d = $r['data'] ?? $r; if (!is_array($d)) continue;
            $d['student_id'] = $d['studentId'] ?? ($r['id'] ?? '');
            $list[] = $d;
        }

        // Full active-allocation roster for the UI.
        $allocRows = $this->firebase->firestoreQuery(self::COL_ALLOCS, [
            ['schoolId', '==', $this->school_name],
            ['status',   '==', 'Active'],
        ]);
        $roster = [];
        foreach ((array) $allocRows as $r) {
            $al = $r['data'] ?? $r; if (!is_array($al)) continue;
            $roster[] = [
                'student_id'    => $al['studentId'] ?? ($r['id'] ?? ''),
                'student_name'  => $al['student_name']  ?? '',
                'room_no'       => $al['room_no']       ?? '',
                'building_name' => $al['building_name'] ?? '',
            ];
        }

        $this->json_success(['attendance' => $list, 'roster' => $roster, 'date' => $date]);
    }

    public function save_attendance()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_attendance');
        $this->_require_manage();
        $date = trim($this->input->post('date') ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $this->json_error('Invalid date.');

        $records = json_decode($this->input->post('attendance') ?? '[]', true);
        if (!is_array($records) || empty($records)) $this->json_error('No attendance data.');

        $written = 0;
        foreach ($records as $rec) {
            $sid    = $rec['student_id'] ?? '';
            $status = $rec['status']     ?? 'P';
            if ($sid === '' || !in_array($status, ['P', 'A', 'L'], true)) continue;
            $docId = $this->_attendDocId($date, $sid);
            $this->firebase->firestoreSet(self::COL_ATTENDANCE, $docId, [
                'schoolId'   => $this->school_name,
                'session'    => $this->session_year,
                'date'       => $date,
                'studentId'  => $sid,
                'status'     => $status,
                'marked_by'  => $this->admin_name,
                'marked_at'  => date('c'),
                'updatedAt'  => date('c'),
            ], /* merge */ true);
            $written++;
        }

        if ($written === 0) $this->json_error('No valid attendance records.');
        $this->json_success(['message' => "Attendance saved for {$written} students."]);
    }

    // ====================================================================
    //  STATS
    // ====================================================================
    public function get_stats()
    {
        $this->_require_role(self::VIEW_ROLES, 'hostel_view');
        $this->_require_view();

        $bRows = $this->firebase->firestoreQuery(self::COL_BUILDINGS,
            [['schoolId', '==', $this->school_name]]);
        $rRows = $this->firebase->firestoreQuery(self::COL_ROOMS,
            [['schoolId', '==', $this->school_name]]);

        $buildings = [];
        foreach ((array) $bRows as $r) {
            $d = $r['data'] ?? $r; if (!is_array($d)) continue;
            $bid = $d['buildingId'] ?? ($r['id'] ?? '');
            $buildings[$bid] = $d;
        }
        $rooms = [];
        foreach ((array) $rRows as $r) {
            $d = $r['data'] ?? $r; if (!is_array($d)) continue;
            $rooms[] = $d;
        }

        $totalBeds = 0; $totalOccupied = 0; $byBuilding = [];
        foreach ($rooms as $r) {
            $bid  = $r['building_id'] ?? 'unknown';
            $beds = (int) ($r['beds'] ?? 0);
            $occ  = (int) ($r['occupied'] ?? 0);
            $totalBeds += $beds; $totalOccupied += $occ;

            if (!isset($byBuilding[$bid])) {
                $byBuilding[$bid] = [
                    'name'     => $buildings[$bid]['name'] ?? $bid,
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

    public function search_students()
    {
        $this->_require_role(self::VIEW_ROLES, 'hostel_view');
        $this->_require_view();
        $results = $this->operations_accounting->search_students($this->input->get('q') ?? '');
        foreach ($results as &$r) {
            $r['class'] = trim(($r['class'] ?? '') . ' ' . ($r['section'] ?? ''));
            unset($r['section'], $r['user_id']);
        }
        unset($r);
        $this->json_success(['students' => $results]);
    }
}
