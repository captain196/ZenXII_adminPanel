<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Attendance_coverage_service.php';
require_once APPPATH . 'libraries/Teaching_staff.php';

/**
 * Attendance_coverage — PHASE C2: substitute / coverage attendance marking.
 *
 * Admin-facing CRUD for the date-scoped coverage grants that let a substitute
 * teacher mark a section they do not own, for ONE day only. The authorisation
 * predicate itself lives in Attendance_coverage_service::can_cover() and is
 * consulted by the marking endpoints in Attendance.php (additive to the owner
 * check — see MY_Controller::_teacher_can_access).
 *
 * Boundary:
 *   - grant / revoke require Attendance MANAGE (Admin/Principal). A teacher
 *     cannot self-grant; the granter is always the server-side admin uid.
 *   - list requires Attendance VIEW.
 *   - Tenant identity is $this->school_id (server-derived) — never trusted from
 *     the client. The substitute must be a real teaching-staff member of THIS
 *     school, so a grant cannot be pointed at an outside or phantom uid.
 *
 * Writes ONLY the attendanceCoverage collection (authorisation records). Never
 * touches attendanceSummary or the per-day attendance docs.
 */
class Attendance_coverage extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->helper('rbac');
    }

    private function svc(): Attendance_coverage_service
    {
        $s = new Attendance_coverage_service();
        return $s->init($this->fs, (string) $this->school_id, (string) $this->session_year);
    }

    public function index()
    {
        require_permission('Attendance', 'view');
        $this->load->view('include/header');
        $this->load->view('attendance/coverage');
        $this->load->view('include/footer');
    }

    /** GET — list grants (optional ?date=YYYY-MM-DD). */
    public function list_grants()
    {
        require_permission('Attendance', 'view');
        try {
            $date = trim((string) $this->input->get('date'));
            if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return $this->json_error('Invalid date filter.');
            }
            return $this->json_success(['grants' => $this->svc()->listGrants($date)]);
        } catch (\Throwable $e) {
            log_message('error', 'coverage list_grants: ' . $e->getMessage());
            return $this->json_error('Could not load coverage grants.', 500);
        }
    }

    /** POST — grant coverage for one date/class/section/substitute. */
    public function grant()
    {
        require_permission('Attendance', 'manage');
        if ($this->input->method() !== 'post') return $this->json_error('POST required.');

        $date      = trim((string) $this->input->post('date'));
        $class     = trim((string) $this->input->post('class'));
        $section   = trim((string) $this->input->post('section'));
        $teacherId = trim((string) $this->input->post('substitute_id'));
        $reason    = trim((string) $this->input->post('reason'));

        // Server-derived granter identity — never from the client.
        $grantedBy   = (string) ($this->admin_id ?? '');
        $grantedName = (string) ($this->admin_name ?? $this->session->userdata('admin_name') ?? '');

        // The substitute must be a real, ACTIVE staff member of THIS school. That
        // is the tenant-isolation guard — a grant can never point at an outside or
        // phantom uid. We deliberately do NOT require the Teaching_staff::isTeaching
        // classification: the authority to mark comes from the admin's explicit,
        // date-scoped grant, and a "Class Teacher"-role stand-in (who fails the
        // strict ROLE_TEACHER test) is a perfectly valid substitute.
        $staff = null;
        try {
            $staff = $this->fs->get('staff', $this->school_id . '_' . $teacherId);
        } catch (\Throwable $e) {
            log_message('error', 'coverage grant staff read: ' . $e->getMessage());
            return $this->json_error('Could not verify the substitute right now. Please retry.', 503);
        }
        if (!is_array($staff)) {
            return $this->json_error('That substitute has no staff record at this school.', 422);
        }
        $status = strtolower(trim((string) ($staff['status'] ?? $staff['Status'] ?? 'active')));
        if ($status !== 'active') {
            return $this->json_error('That staff member is not active and cannot be given coverage.', 422);
        }
        $teacherName = \Teaching_staff::name($staff);

        try {
            $id = $this->svc()->grant(
                $date, $class, $section, $teacherId, $teacherName,
                $grantedBy, $grantedName, $reason
            );
        } catch (InvalidArgumentException $e) {
            return $this->json_error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            log_message('error', 'coverage grant: ' . $e->getMessage());
            return $this->json_error('Could not save the coverage grant.', 500);
        }

        log_message('info', sprintf(
            'coverage GRANT school=%s date=%s class=%s section=%s sub=%s by=%s',
            $this->school_id, $date, $class, $section, $teacherId, $grantedBy));

        return $this->json_success([
            'id'          => $id,
            'date'        => $date,
            'substitute'  => ['id' => $teacherId, 'name' => $teacherName],
            'message'     => $teacherName . ' can now mark ' . $class . ' ' . $section . ' on ' . $date . '.',
        ]);
    }

    /** POST — revoke a coverage grant. */
    public function revoke()
    {
        require_permission('Attendance', 'manage');
        if ($this->input->method() !== 'post') return $this->json_error('POST required.');

        $date      = trim((string) $this->input->post('date'));
        $class     = trim((string) $this->input->post('class'));
        $section    = trim((string) $this->input->post('section'));
        $teacherId = trim((string) $this->input->post('substitute_id'));
        $revokedBy = (string) ($this->admin_id ?? '');

        if ($date === '' || $class === '' || $section === '' || $teacherId === '') {
            return $this->json_error('date, class, section and substitute_id are required.');
        }
        try {
            $ok = $this->svc()->revoke($date, $class, $section, $teacherId, $revokedBy);
        } catch (\Throwable $e) {
            log_message('error', 'coverage revoke: ' . $e->getMessage());
            return $this->json_error('Could not revoke the coverage grant.', 500);
        }
        if (!$ok) return $this->json_error('No active coverage grant found to revoke.', 404);

        log_message('info', sprintf(
            'coverage REVOKE school=%s date=%s class=%s section=%s sub=%s by=%s',
            $this->school_id, $date, $class, $section, $teacherId, $revokedBy));

        return $this->json_success(['message' => 'Coverage revoked.']);
    }
}
