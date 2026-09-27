<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Work_schedules — PHASE 1: the schedules + assignments admin surface.
 *
 * Until this screen exists no tenant can declare a work schedule, so payroll is
 * blocked for every tenant (N-23). This is the unblock.
 *
 * ─── WHAT IT MANAGES ─────────────────────────────────────────────────────
 *   schools/{id}.attendancePolicy.workSchedules[]   the catalogue
 *   staffScheduleAssignments                        who gets which, from when
 *
 * Assignments gain `attachmentType` / `attachmentId` so one record can attach a
 * schedule to a DEPARTMENT (inherited by everyone in it) or to a STAFF member
 * (a pick between two departmental offerings, or an outright override).
 *
 * BACK-COMPATIBILITY: records written before Phase 1 carry only `staffId`.
 * _normaliseAssignment() reads them as attachmentType 'staff' so nothing has to
 * be migrated and no existing payslip changes.
 *
 * ─── D4 — THE weeklyOffs PRE-FILL ────────────────────────────────────────
 * The tenant's declared `attendancePolicy.weeklyOffs` is offered as a
 * SUGGESTION on the create form. It is never adopted automatically: until an
 * administrator saves a schedule, none exists and payroll stays blocked. The
 * operator declared weekly-offs for attendance; adopting that for pay without
 * a human confirming it is the inference N-23 was caused by.
 */
class Work_schedules extends MY_Controller
{
    const COLLECTION = 'staffScheduleAssignments';

    public function __construct()
    {
        parent::__construct();
        $this->load->library('Work_schedule_service');
        // Both are used statically. CI's loader does not resolve static calls,
        // so they must be required explicitly or save_schedule() fatals on
        // Working_calendar_service::DAYS.
        require_once APPPATH . 'libraries/Working_calendar_service.php';
        require_once APPPATH . 'libraries/Policy_assignment_service.php';
    }

    private function _require_view()   { require_permission('HR', 'view'); }
    private function _require_manage() { require_permission('HR', 'manage'); }

    /**
     * Firestore_service::where() returns rows as ['id' => docId, 'data' => [...]],
     * NOT as flat field maps. Reading $row['staffId'] therefore always yields
     * null — which silently dropped every employee and produced a green
     * "everyone resolves" banner on a tenant where nobody was configured.
     *
     * Unwrap once, here, and keep the doc id available as `_docId`. Flat rows
     * are passed through unchanged so this stays correct if the shape differs.
     */
    private function _rows(array $docs): array
    {
        $out = [];
        foreach ($docs as $d) {
            if (!is_array($d)) continue;
            if (isset($d['data']) && is_array($d['data'])) {
                $row = $d['data'];
                $row['_docId'] = (string) ($d['id'] ?? '');
            } else {
                $row = $d;
            }
            $out[] = $row;
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Page
    // ─────────────────────────────────────────────────────────────────────

    public function index()
    {
        $this->_require_view();
        $this->load->view('include/header');
        $this->load->view('work_schedules/index');
        $this->load->view('include/footer');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Reads
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Everything the screen needs in one round trip: the catalogue, the
     * assignments, the department list, and — the point of the screen — who
     * cannot currently be paid and why.
     */
    public function get_data()
    {
        $this->_require_view();

        $school   = $this->fs->get('schools', $this->school_id);
        $policy   = is_array($school['attendancePolicy'] ?? null) ? $school['attendancePolicy'] : [];
        $catalogue= Work_schedule_service::schedulesFrom($policy);

        $assignments = [];
        foreach ($this->_rows($this->fs->schoolWhere(self::COLLECTION, [])) as $a) {
            $assignments[] = $this->_normaliseAssignment($a);
        }

        $staff = $this->_rows($this->fs->schoolWhere('staff', []));
        $departments = [];
        foreach ($staff as $s) {
            if (!$this->_isActive($s)) continue;
            $d = $this->_dept($s);
            if ($d !== '') $departments[$d] = ($departments[$d] ?? 0) + 1;
        }
        ksort($departments);

        $this->json_success([
            'schedules'    => array_values($catalogue),
            'assignments'  => $assignments,
            'departments'  => $departments,
            'weeklyOffs'   => array_values((array) ($policy['weeklyOffs'] ?? [])),
            'blocked'      => $this->_blockedStaff($catalogue, $assignments, $staff),
            // Counts so the panel can distinguish "nobody is blocked" from
            // "no staff were found" — as an empty list alone, the two are
            // indistinguishable on screen and the second reads as success.
            'staff_total'  => count($staff),
            'staff_active' => count(array_filter($staff, fn($s) => $this->_isActive($s))),
        ]);
    }

    /**
     * Every employee who cannot be resolved today, with the reason. This is the
     * N-23 exposure made visible rather than discovered at payroll time.
     */
    private function _blockedStaff(array $catalogue, array $assignments, array $staff): array
    {
        $today  = date('Y-m-d');
        $byId   = [];
        foreach ($catalogue as $s) $byId[(string) ($s['scheduleId'] ?? '')] = $s;

        $out = [];
        foreach ($staff as $s) {
            if (!$this->_isActive($s)) continue;
            $staffId = $this->_staffId($s);

            // FAIL CLOSED. An employee we cannot identify is not an employee we
            // can pay — skipping them produced a green "everyone resolves" banner
            // while every one of them was in fact unresolvable. Never `continue`
            // here: report them instead, with the reason.
            if ($staffId === '') {
                $out[] = [
                    'staffId'    => '(no id)',
                    'name'       => (string) ($s['name'] ?? $s['Name'] ?? $s['staff_name'] ?? '(unnamed)'),
                    'department' => $this->_dept($s),
                    'block'      => 'unidentifiable',
                    'why'        => "This staff record has no staffId field, so payroll\n"
                                  . "cannot address it. Fields present: "
                                  . implode(', ', array_slice(array_keys($s), 0, 12)),
                ];
                continue;
            }

            $r = Policy_assignment_service::resolve([
                'policies'             => $byId,
                'assignments'          => $assignments,
                'staffId'              => $staffId,
                'department'           => $this->_dept($s),
                'organisationPolicyId' => null,   // D4 surfaces separately, see note
            ], $today);

            if (!empty($r['block'])) {
                $out[] = [
                    'staffId'    => $staffId,
                    'name'       => (string) ($s['name'] ?? $s['Name'] ?? $s['staff_name'] ?? ''),
                    'department' => $this->_dept($s),
                    'block'      => $r['block'],
                    'why'        => Policy_assignment_service::explain($r),
                ];
            }
        }
        return $out;
    }

    /**
     * Staff docs are written with `status` defaulting to 'Active' — CAPITAL A
     * (Firestore_service.php:1072). A case-sensitive comparison silently skips
     * every employee, which made the blocked panel render empty and read as
     * "nobody is blocked" when in fact nobody was configured.
     */
    private function _isActive(array $s): bool
    {
        $status = strtolower(trim((string) ($s['status'] ?? $s['Status'] ?? 'active')));
        return $status === '' || $status === 'active';
    }

    /** Staff docs carry both camelCase and capitalised variants. */
    private function _staffId(array $s): string
    {
        return trim((string) ($s['staffId'] ?? $s['staff_id'] ?? $s['StaffId'] ?? ''));
    }

    private function _dept(array $s): string
    {
        return trim((string) ($s['department'] ?? $s['Department'] ?? ''));
    }

    /** Resolve one employee on one date — the "why is this day off?" preview. */
    public function preview()
    {
        $this->_require_view();

        $staffId = trim((string) $this->input->post('staff_id'));
        $date    = trim((string) $this->input->post('date'));
        if ($staffId === '' || $date === '') {
            return $this->json_error('staff_id and date are required');
        }

        $staff = $this->fs->get('staff', $this->fs->docId($staffId));
        if (!is_array($staff)) return $this->json_error('Staff member not found');

        $school    = $this->fs->get('schools', $this->school_id);
        $policy    = is_array($school['attendancePolicy'] ?? null) ? $school['attendancePolicy'] : [];
        $byId      = [];
        foreach (Work_schedule_service::schedulesFrom($policy) as $s) {
            $byId[(string) ($s['scheduleId'] ?? '')] = $s;
        }

        $assignments = [];
        foreach ($this->_rows($this->fs->schoolWhere(self::COLLECTION, [['staffId', '==', $staffId]])) as $a) {
            $assignments[] = $this->_normaliseAssignment($a);
        }
        foreach ($this->_rows($this->fs->schoolWhere(self::COLLECTION, [])) as $a) {
            $n = $this->_normaliseAssignment($a);
            if ($n['attachmentType'] === 'department') $assignments[] = $n;
        }

        $r = Policy_assignment_service::resolve([
            'policies'             => $byId,
            'assignments'          => $assignments,
            'staffId'              => $staffId,
            'department'           => (string) ($staff['department'] ?? ''),
            'organisationPolicyId' => null,
        ], $date);

        $this->json_success([
            'resolved_level' => $r['level'],
            'schedule_id'    => $r['policyId'],
            'schedule'       => $r['policy'],
            'blocked'        => $r['block'],
            'why'            => Policy_assignment_service::explain($r),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Writes — catalogue
    // ─────────────────────────────────────────────────────────────────────

    public function save_schedule()
    {
        $this->_require_manage();

        $name = trim((string) $this->input->post('name'));
        $offs = $this->input->post('weekly_offs');
        $offs = is_array($offs) ? array_values(array_intersect(
            array_map('strval', $offs), Working_calendar_service::DAYS)) : [];
        $id   = trim((string) $this->input->post('schedule_id'));

        if ($name === '')            return $this->json_error('Schedule name is required');
        if (mb_strlen($name) > 60)   return $this->json_error('Schedule name is too long');
        if (count($offs) >= 7)       return $this->json_error('A schedule cannot have every day off');

        $school = $this->fs->get('schools', $this->school_id);
        $policy = is_array($school['attendancePolicy'] ?? null) ? $school['attendancePolicy'] : [];
        $list   = is_array($policy['workSchedules'] ?? null) ? $policy['workSchedules'] : [];

        if ($id === '') {
            $id = 'WS_' . strtoupper(bin2hex(random_bytes(4)));
            $list[] = [
                'scheduleId' => $id, 'name' => $name, 'weeklyOffs' => $offs,
                'createdAt'  => date('c'),
            ];
        } else {
            $found = false;
            foreach ($list as $i => $s) {
                if ((string) ($s['scheduleId'] ?? '') === $id) {
                    $list[$i]['name']       = $name;
                    $list[$i]['weeklyOffs'] = $offs;
                    $list[$i]['updatedAt']  = date('c');
                    $found = true;
                    break;
                }
            }
            if (!$found) return $this->json_error('Schedule not found');
        }

        // Production hardening (2026-08-19) — lost-update fix; see
        // delete_schedule() for the full rationale. `workSchedules` is the
        // only key this endpoint owns, so the mask is scoped to it and a
        // concurrent geofence/working-week save can no longer be clobbered.
        $this->fs->update('schools', $this->school_id, [
            'attendancePolicy.workSchedules' => array_values($list),
        ]);

        $this->json_success(['schedule_id' => $id]);
    }

    /**
     * Refuse to delete a schedule that anyone still uses — otherwise every
     * employee on it silently becomes unresolvable at the next payroll run.
     */
    public function delete_schedule()
    {
        $this->_require_manage();

        $id = trim((string) $this->input->post('schedule_id'));
        if ($id === '') return $this->json_error('schedule_id is required');

        $inUse = 0;
        foreach ($this->_rows($this->fs->schoolWhere(self::COLLECTION, [])) as $a) {
            $n = $this->_normaliseAssignment($a);
            if ($n['policyId'] === $id) $inUse++;
        }
        if ($inUse > 0) {
            return $this->json_error("This schedule is assigned to {$inUse} place(s). Reassign them first.");
        }

        $school = $this->fs->get('schools', $this->school_id);
        $policy = is_array($school['attendancePolicy'] ?? null) ? $school['attendancePolicy'] : [];
        $list   = is_array($policy['workSchedules'] ?? null) ? $policy['workSchedules'] : [];

        $remaining = array_values(array_filter($list,
            static fn($s) => (string) ($s['scheduleId'] ?? '') !== $id));

        // Production hardening (2026-08-19) — lost-update fix. Writing the
        // whole `attendancePolicy` map back meant this read-modify-write
        // could clobber a concurrent geofence/shift or working-week save
        // made between our read above and this write. `workSchedules` is
        // the ONLY key this endpoint owns, so scope the mask to it.
        $this->fs->update('schools', $this->school_id, [
            'attendancePolicy.workSchedules' => $remaining,
        ]);
        $this->json_success(['deleted' => $id]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Writes — assignments
    // ─────────────────────────────────────────────────────────────────────

    public function save_assignment()
    {
        $this->_require_manage();

        $rec = [
            'attachmentType' => trim((string) $this->input->post('attachment_type')),
            'attachmentId'   => trim((string) $this->input->post('attachment_id')),
            'policyId'       => trim((string) $this->input->post('schedule_id')),
            'policyType'     => 'work_schedule',
            'effectiveFrom'  => trim((string) $this->input->post('effective_from')),
            'effectiveTo'    => trim((string) $this->input->post('effective_to')),
        ];
        $assignmentId = trim((string) $this->input->post('assignment_id'));
        if ($assignmentId !== '') $rec['assignmentId'] = $assignmentId;

        $v = Policy_assignment_service::validateAssignment($rec);
        if (!$v['valid']) return $this->json_error(implode('; ', $v['errors']));

        // The schedule must exist, or the assignment is unresolvable on save.
        $school = $this->fs->get('schools', $this->school_id);
        $policy = is_array($school['attendancePolicy'] ?? null) ? $school['attendancePolicy'] : [];
        $known  = [];
        foreach (Work_schedule_service::schedulesFrom($policy) as $s) {
            $known[] = (string) ($s['scheduleId'] ?? '');
        }
        if (!in_array($rec['policyId'], $known, true)) {
            return $this->json_error('That schedule does not exist');
        }

        // Refuse an overlap BEFORE writing — an ambiguous state must never be
        // persisted. resolve() blocking on it is the second line of defence.
        $existing = [];
        foreach ($this->_rows($this->fs->schoolWhere(self::COLLECTION, [])) as $a) {
            $existing[] = $this->_normaliseAssignment($a);
        }
        $clashes = Policy_assignment_service::findOverlaps($existing, $rec);
        if (!empty($clashes)) {
            return $this->json_error(
                'This overlaps an existing assignment (from ' .
                (string) ($clashes[0]['effectiveFrom'] ?? '?') .
                '). End that one first, or change the dates.');
        }

        if ($assignmentId === '') {
            $assignmentId = 'WSA_' . strtoupper(bin2hex(random_bytes(5)));
        }

        $doc = array_merge($rec, [
            'assignmentId' => $assignmentId,
            'schoolId'     => $this->school_id,
            'effectiveTo'  => $rec['effectiveTo'] !== '' ? $rec['effectiveTo'] : null,
            'updatedAt'    => date('c'),
        ]);
        // Legacy mirror so pre-Phase-1 readers keep working unchanged.
        $doc['staffId']    = $rec['attachmentType'] === 'staff' ? $rec['attachmentId'] : '';
        $doc['scheduleId'] = $rec['policyId'];

        $this->fs->set(self::COLLECTION, $this->fs->docId($assignmentId), $doc);
        $this->json_success(['assignment_id' => $assignmentId]);
    }

    public function delete_assignment()
    {
        $this->_require_manage();

        $id = trim((string) $this->input->post('assignment_id'));
        if ($id === '') return $this->json_error('assignment_id is required');

        // remove(), not delete() — Firestore_service has no delete(). Takes a RAW
        // docId, so docId() must be applied here, matching how save_assignment()
        // writes it (set + docId), never removeEntity() which prefixes again.
        $this->fs->remove(self::COLLECTION, $this->fs->docId($id));
        $this->json_success(['deleted' => $id]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Internals
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Read any assignment — Phase 1 shape or the older staff-only shape — as
     * the Phase 0 record. Nothing has to be migrated.
     *
     * Delegates to `Work_schedule_service::normaliseAssignment()` — Payroll's
     * resolver convergence (2026-08-18) needed the identical normalisation,
     * so the logic now lives once in the library instead of being copied.
     */
    private function _normaliseAssignment(array $a): array
    {
        return Work_schedule_service::normaliseAssignment($a);
    }
}
