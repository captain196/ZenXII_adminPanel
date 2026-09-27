<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Attendance_completion — PHASE B1: Student Attendance Completion Dashboard.
 *
 * Answers the operational question mature SIS products track and ZenX lacked:
 *   "Which class/sections have COMPLETED attendance for a given date, and which
 *    have NOT?" — distinguishing 'nobody marked' from 'marked, all present'.
 *
 * SOURCE-OF-TRUTH: this is a PURE DERIVED READ. It introduces NO new attendance
 * master and NO completion-marker collection — completion is computed from the
 * canonical `attendanceSummary` (per-student dayWise) + the `sections` roster +
 * active `students`, overlaid with the canonical non-working-day set
 * (Student_calendar_reader + Holiday_service) and `attendanceLocks`. It writes
 * NOTHING and does not touch the (entangled) Attendance.php write paths.
 *
 * COST: ~3 school-scoped queries for the whole school on any date (sections,
 * students, attendanceSummary[month]) + 1 locks scan — no N+1 per section.
 *
 * TENANT/SESSION: every read is $this->fs->schoolWhere(...) (schoolId ==) and
 * scoped to the server-resolved current session. Client never supplies tenant.
 */
class Attendance_completion extends MY_Controller
{
    /** Completion states (derived). */
    const ST_HOLIDAY     = 'holiday';       // non-working day for students
    const ST_LOCKED      = 'locked';        // attendanceLocks locked for this date
    const ST_NOT_STARTED = 'not_started';   // 0 students marked
    const ST_PARTIAL     = 'partial';       // some marked
    const ST_COMPLETED   = 'completed';     // all active students marked

    private const VIEW_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator', 'Class Teacher', 'Teacher'];

    public function __construct()
    {
        parent::__construct();
        $this->load->helper('rbac');
    }

    /** Dashboard shell. */
    public function index()
    {
        require_permission('Attendance', 'view');
        $this->load->view('include/header');
        $this->load->view('attendance/completion');
        $this->load->view('include/footer');
    }

    /**
     * GET attendance/completion_data?date=YYYY-MM-DD
     * Returns per-section completion for the date + summary counts.
     */
    public function data()
    {
        require_permission('Attendance', 'view');
        header('Content-Type: application/json');

        $date = trim((string) $this->input->get('date'));
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
            return $this->json_error('A valid date (YYYY-MM-DD) is required.');
        }
        $year     = (int) substr($date, 0, 4);
        $monthNum = (int) substr($date, 5, 2);
        $day      = (int) substr($date, 8, 2);
        $monthKey = sprintf('%04d-%02d', $year, $monthNum);

        try {
            // 1) Non-working overlay (canonical): Holiday_service + Student_calendar_reader.
            $isNonWorking = false; $nonWorkingReason = '';
            try {
                $this->load->library('holiday_service');
                $this->holiday_service->init($this->fs, (string) $this->school_id, (string) $this->session_year);
                $holidayMap = $this->holiday_service->holidays_in_month($year, $monthNum, 'students');
                $swdMap     = $this->holiday_service->special_working_days_in_month($year, $monthNum, 'students');
                $this->load->library('student_calendar_reader');
                $this->student_calendar_reader->init($this->fs, (string) $this->school_id, (string) $this->session_year);
                $res = $this->student_calendar_reader->resolveMonth($year, $monthNum, $holidayMap, $swdMap);
                $nw  = is_array($res['nonWorking'] ?? null) ? $res['nonWorking'] : [];
                if (isset($nw[$day])) { $isNonWorking = true; $nonWorkingReason = (string) $nw[$day]; }
            } catch (\Throwable $e) {
                log_message('error', 'completion: non-working overlay failed: ' . $e->getMessage());
            }

            // 2) Sections (current session).
            $sections = [];  // combinedKey => ['className'=>, 'section'=>]
            foreach ((array) $this->fs->schoolWhere('sections', []) as $doc) {
                $d = is_array($doc) ? ($doc['data'] ?? $doc) : [];
                if (!is_array($d)) continue;
                $sess = (string) ($d['session'] ?? '');
                if ($sess !== '' && strcasecmp($sess, (string) $this->session_year) !== 0) continue;
                $cls = (string) ($d['className'] ?? $d['Class'] ?? '');
                $sec = (string) ($d['section'] ?? $d['Section'] ?? '');
                if ($cls === '' || $sec === '') continue;
                $combined = Firestore_service::classKey($cls) . '/' . Firestore_service::sectionKey($sec);
                $sections[$combined] = ['className' => Firestore_service::classKey($cls), 'section' => Firestore_service::sectionKey($sec)];
            }

            // 3) Active students grouped by section (total per section).
            $total = [];      // combinedKey => int
            $activeIds = [];  // combinedKey => [studentId => true]  (COMPLETE-1: exact roster)
            foreach ((array) $this->fs->schoolWhere('students', []) as $doc) {
                $d = is_array($doc) ? ($doc['data'] ?? $doc) : [];
                if (!is_array($d)) continue;
                $st = strtolower(trim((string) ($d['status'] ?? $d['Status'] ?? '')));
                if ($st !== '' && $st !== 'active') continue;   // blank defaults to active (roster convention)
                $cls = (string) ($d['Class'] ?? $d['className'] ?? $d['class'] ?? '');
                $sec = (string) ($d['Section'] ?? $d['section'] ?? '');
                if ($cls === '' || $sec === '') continue;
                $combined = Firestore_service::classKey($cls) . '/' . Firestore_service::sectionKey($sec);
                if (!isset($sections[$combined])) {
                    // student in a section not present in `sections` — still track it
                    $sections[$combined] = ['className' => Firestore_service::classKey($cls), 'section' => Firestore_service::sectionKey($sec)];
                }
                $total[$combined] = ($total[$combined] ?? 0) + 1;
                $sid = trim((string) ($d['User Id'] ?? $d['studentId'] ?? $d['userId'] ?? $d['id'] ?? ''));
                if ($sid !== '') $activeIds[$combined][$sid] = true;
            }

            // 4) Marked counts for the day, grouped by section (canonical summary).
            $marked = [];  // combinedKey => int
            foreach ((array) $this->fs->schoolWhere('attendanceSummary', [['month', '==', $monthKey]]) as $doc) {
                $d = is_array($doc) ? ($doc['data'] ?? $doc) : [];
                if (!is_array($d)) continue;
                $combined = (string) ($d['sectionKey'] ?? '');
                if ($combined === '') {
                    $cls = (string) ($d['className'] ?? ''); $sec = (string) ($d['section'] ?? '');
                    if ($cls === '' || $sec === '') continue;
                    $combined = Firestore_service::classKey($cls) . '/' . Firestore_service::sectionKey($sec);
                }
                // COMPLETE-1: only count a summary row that belongs to an ACTIVE
                // student CURRENTLY in this section. A TC'd / transferred student
                // whose old summary still carries a mark must not inflate the
                // "marked" count above the active total (which would flip a
                // genuinely PARTIAL section to a false COMPLETED).
                $sid = trim((string) ($d['studentId'] ?? $d['userId'] ?? ''));
                if ($sid === '' || empty($activeIds[$combined][$sid])) continue;
                $dw = (string) ($d['dayWise'] ?? '');
                $ch = (is_string($dw) && isset($dw[$day - 1])) ? $dw[$day - 1] : 'V';
                if ($ch !== 'V' && $ch !== '') {   // any real code (P/A/L/H/T) = this student was accounted for
                    $marked[$combined] = ($marked[$combined] ?? 0) + 1;
                }
            }

            // 5) Locked sections for this date (one scan; ids end with _{date}).
            $locked = [];  // combinedKey => true
            foreach ((array) $this->fs->schoolWhere('attendanceLocks', []) as $doc) {
                $d = is_array($doc) ? ($doc['data'] ?? $doc) : [];
                $id = is_array($doc) ? (string) ($doc['id'] ?? '') : '';
                if (!is_array($d) || empty($d['locked'])) continue;
                // lock id = {schoolId}_{classKey-dashed}_{sectionKey-dashed}_{date}
                if (substr($id, -strlen('_' . $date)) !== '_' . $date) continue;
                // recover combined key from the doc if present, else from id
                $combined = '';
                if (!empty($d['className']) && !empty($d['section'])) {
                    $combined = Firestore_service::classKey((string)$d['className']) . '/' . Firestore_service::sectionKey((string)$d['section']);
                }
                if ($combined !== '') $locked[$combined] = true;
                else $locked['__id__' . $id] = true;  // fallback marker
            }

            // 6) Derive per-section status.
            $rows = []; $counts = [self::ST_COMPLETED=>0, self::ST_PARTIAL=>0, self::ST_NOT_STARTED=>0, self::ST_LOCKED=>0, self::ST_HOLIDAY=>0];
            foreach ($sections as $key => $meta) {
                $tot = (int) ($total[$key] ?? 0);
                $mk  = (int) ($marked[$key] ?? 0);
                if ($isNonWorking)                 $status = self::ST_HOLIDAY;
                elseif (isset($locked[$key]))      $status = self::ST_LOCKED;
                elseif ($mk === 0)                 $status = self::ST_NOT_STARTED;
                elseif ($mk < $tot)                $status = self::ST_PARTIAL;
                else                               $status = self::ST_COMPLETED;
                $counts[$status] = ($counts[$status] ?? 0) + 1;
                $rows[] = [
                    'className' => $meta['className'],
                    'section'   => str_replace('Section ', '', $meta['section']),
                    'sectionKey'=> $key,
                    'total'     => $tot,
                    'marked'    => $mk,
                    'status'    => $status,
                ];
            }
            usort($rows, fn($a, $b) => strcmp($a['sectionKey'], $b['sectionKey']));

            return $this->json_success([
                'date'        => $date,
                'isHoliday'   => $isNonWorking,
                'holidayName' => $nonWorkingReason,
                'sections'    => $rows,
                'summary'     => $counts,
                'totalSections' => count($rows),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Attendance_completion::data failed: ' . $e->getMessage());
            return $this->json_error('Could not compute attendance completion.', 500);
        }
    }
}
