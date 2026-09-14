<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Academic Management Controller
 *
 * Unified hub for subject assignment, period scheduling, curriculum planning,
 * academic calendar, master timetable grid, and substitute teacher management.
 *
 * Firestore collections (ALL reads/writes are Firestore-only):
 *   subjectAssignments  (per-class subject assignments, via subject_assignment_service)
 *   curriculum           (curriculum docs: {schoolId}_{session}_{classSection}_{subject})
 *   calendarEvents       (academic calendar: {schoolId}_{eventId})
 *   substitutes          (substitute teacher records: {schoolId}_{subId})
 *   timetableSettings    (period config: {schoolId}_{session})
 *   timetables           (per-day section timetables: {school}_{session}_{sectionKey}_{day})
 *   subjects             (subject catalog, queried by schoolId)
 *   schools              (school doc, for Config/Classes)
 *   staff                (teacher/staff profiles, queried by schoolId)
 *   sections             (class-section roster, queried by schoolId + session)
 *   staffAttendance      (staff attendance records)
 *   leaveApplications    (leave requests)
 */
class Academic extends MY_Controller
{
    /**
     * Page size for the substitute-flow timetable scans. One doc per
     * class-section for the day, so this bounds class-sections, not periods.
     *
     * Saturation is REPORTED (see the `completeness` key the endpoint
     * returns), never silently truncated: get_absent_teacher_schedule drives
     * which periods an admin arranges cover for, so a period lost to the cap
     * is a class that quietly ends up with nobody in front of it.
     */
    const SUBSTITUTE_SCAN_LIMIT = 100;

    /**
     * Page size for the substitute SUGGESTION scan. Wider than
     * SUBSTITUTE_SCAN_LIMIT because it must see every class-section to know
     * who is busy, not just the absent teacher's own rows.
     */
    const SUBSTITUTE_SUGGEST_LIMIT = 200;

    public function __construct()
    {
        parent::__construct();
        require_permission('Academic');
    }

    /* ══════════════════════════════════════════════════════════════════════
       PHASE 5 — SERVICE LAYER LOADERS

       Each endpoint that has been refactored to the service layer goes
       through one of these helpers. They lazy-init the audit log + the
       requested service exactly once per request. Idempotent — safe to
       call repeatedly. The audit_svc is shared across all four services
       so only one bind per request.
    ══════════════════════════════════════════════════════════════════════ */

    private function _ensure_audit_svc(): void
    {
        require_once APPPATH . 'libraries/Service_exception.php';
        $this->load->library('audit_log_service', null, 'audit_svc');
        if (!$this->audit_svc->isReady()) {
            $this->audit_svc->init(
                $this->firebase, $this->school_id, $this->session_year,
                [
                    'uid'  => $this->admin_id   ?? '',
                    'name' => $this->admin_name ?? '',
                    'role' => $this->admin_role ?? '',
                ]
            );
        }
    }

    private function _curriculum_svc()
    {
        $this->_ensure_audit_svc();
        $this->load->library('curriculum_service', null, 'curriculum_svc');
        if (!$this->curriculum_svc->isReady()) {
            $this->curriculum_svc->init(
                $this->firebase, $this->fs,
                $this->school_id, $this->school_name, $this->session_year,
                $this->admin_id   ?? '',
                $this->admin_name ?? '',
                $this->admin_role ?? '',
                $this->audit_svc
            );
        }
        return $this->curriculum_svc;
    }

    private function _calendar_svc()
    {
        $this->_ensure_audit_svc();
        $this->load->library('calendar_service', null, 'calendar_svc');
        if (!$this->calendar_svc->isReady()) {
            $this->calendar_svc->init(
                $this->firebase, $this->fs,
                $this->school_id, $this->session_year,
                $this->admin_id   ?? '',
                $this->admin_name ?? '',
                $this->audit_svc
            );
        }
        return $this->calendar_svc;
    }

    /**
     * Tell the teachers whose day just changed.
     *
     * Never allowed to break the operation it reports on: the cover is
     * already committed by the time this runs, and a push that fails must not
     * turn a saved cover into an error on screen. It is logged instead.
     *
     * The dedupe key carries the direction, so "you are covering" and "that
     * cover is cancelled" for the same record are never mistaken for repeats
     * of one another.
     */
    private function _notify_cover(array $staffIds, string $date,
                                   string $recordId, bool $withdrawn): void
    {
        $staffIds = array_values(array_unique(array_filter(array_map(
            fn($s) => trim((string) $s), $staffIds))));
        if (empty($staffIds)) { return; }

        $on = $date !== '' ? " on {$date}" : '';
        $mark = $withdrawn ? 'SUBSTITUTE_CANCELLED' : 'SUBSTITUTE_ASSIGNED';
        $dedupe = 'substitute_' . ($withdrawn ? 'cancel_' : 'assign_')
            . ($recordId !== '' ? $recordId : $date)
            . '_' . substr(md5(implode(',', $staffIds)), 0, 8);

        try {
            $this->emit_push($mark, $dedupe, [
                'recipientStaffIds' => $staffIds,
                'title' => $withdrawn ? 'Substitute Cover Cancelled' : 'Substitute Assigned',
                'body'  => $withdrawn
                    ? "A substitute cover assigned to you{$on} has been cancelled."
                    : "You have been assigned as a substitute teacher{$on}.",
                'date'  => $date,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'substitute cover push failed (mark=' . $mark
                . ' record=' . $recordId . '): ' . $e->getMessage());
        }
    }

    /**
     * "Can this teacher take this subject?", with the read failure told apart
     * from the answer.
     *
     * Returns TRUE when the check could not run, so the caller adds no
     * "not authorized" line about somebody who may be perfectly qualified —
     * a truthful error is appended here instead, and its presence stops the
     * save just the same.
     */
    private function _can_teach_or_explain(string $tid, string $code,
                                           string $name, array &$errors): bool
    {
        try {
            return (bool) $this->sas->teacherCanTeach($tid, $code, $name);
        } catch (\Throwable $e) {
            log_message('error', 'save_class_matrix: staff record unreadable for '
                . $tid . ': ' . $e->getMessage());
            $msg = 'The staff records could not be read, so who teaches what could '
                . 'not be checked and nothing was saved. Please try again in a moment.';
            if (!in_array($msg, $errors, true)) { $errors[] = $msg; }
            return true;
        }
    }

    private function _substitute_svc()
    {
        $this->_ensure_audit_svc();
        $this->load->library('substitute_service', null, 'substitute_svc');
        if (!$this->substitute_svc->isReady()) {
            $this->substitute_svc->init(
                $this->firebase, $this->fs,
                $this->school_id, $this->session_year,
                $this->admin_id   ?? '',
                $this->admin_name ?? '',
                $this->audit_svc
            );
        }
        return $this->substitute_svc;
    }

    private function _timetable_svc()
    {
        $this->_ensure_audit_svc();
        $this->load->library('timetable_service', null, 'timetable_svc');
        if (!$this->timetable_svc->isReady()) {
            $this->timetable_svc->init(
                $this->firebase, $this->fs,
                $this->school_id, $this->session_year,
                $this->admin_id   ?? '',
                $this->admin_name ?? '',
                $this->audit_svc
            );
        }
        return $this->timetable_svc;
    }

    private function _lesson_plan_svc()
    {
        $this->_ensure_audit_svc();
        $this->load->library('lesson_plan_service', null, 'lesson_plan_svc');
        if (!$this->lesson_plan_svc->isReady()) {
            $this->lesson_plan_svc->init(
                $this->firebase, $this->fs,
                $this->school_id, $this->school_name, $this->session_year,
                $this->admin_id   ?? '',
                $this->admin_name ?? '',
                $this->admin_role ?? '',
                $this->audit_svc
            );
        }
        return $this->lesson_plan_svc;
    }

    private function _analytics_svc()
    {
        $this->load->library('analytics_service', null, 'analytics_svc');
        if (!$this->analytics_svc->isReady()) {
            $this->analytics_svc->init(
                $this->firebase, $this->fs,
                $this->school_id, $this->session_year
            );
        }
        return $this->analytics_svc;
    }

    /* ══════════════════════════════════════════════════════════════════════
       PAGE LOAD
    ══════════════════════════════════════════════════════════════════════ */

    public function index()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'academic_view', 'Academic', 'view');

        $data['session_year']       = $this->session_year;
        $data['school_name']        = $this->school_name;
        $data['school_display_name'] = $this->school_display_name ?? $this->school_name;
        $data['school_id']          = $this->school_id;
        $data['admin_role']         = $this->admin_role ?? '';

        $this->load->view('include/header');
        $this->load->view('academic/index', $data);
        $this->load->view('include/footer');
    }

    /* ══════════════════════════════════════════════════════════════════════
       SHARED: CLASS / SUBJECT / TEACHER DATA
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * Return all class-sections + subjects for dropdowns
     */
    public function get_classes_subjects()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'academic_data', 'Academic', 'view');
        $school  = $this->school_name;
        $session = $this->session_year;

        $classes  = $this->_get_session_classes();
        $subjects = $this->_getSubjectsFromFirestore();

        return $this->json_success([
            'classes'  => $classes,
            'subjects' => $subjects,
        ]);
    }

    /**
     * Return all teachers for the current session (Firestore-first via staff collection).
     * Each teacher includes `teaching_subjects` so the frontend can filter dropdowns.
     */
    public function get_all_teachers()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'academic_data', 'Academic', 'view');

        $teachers = [];

        // ── Firestore-first: read staff collection scoped to school ──
        try {
            $staffDocs = $this->fs->schoolWhere('staff', []);
            foreach ($staffDocs as $s) {
                $d = $s['data'] ?? $s;
                $data = $s['data'] ?? [];
                $staffId = $data['staffId'] ?? $data['User ID'] ?? $d['id'] ?? '';
                if ($staffId === '') continue;

                // Filter to teachers only — ROLE_TEACHER in staff_roles / primary_role,
                // OR teaching_subjects, OR a legacy Position/designation that reads
                // like a teacher (case-insensitive so 'Maths Teacher','teacher','TGT'
                // etc. from un-migrated accounts aren't dropped from the picker).
                $roles = $data['staff_roles'] ?? [];
                $isTeacher = false;
                if (is_array($roles)) {
                    foreach ($roles as $r) {
                        if ($r === 'ROLE_TEACHER') { $isTeacher = true; break; }
                    }
                }
                if (!$isTeacher && ($data['primary_role'] ?? '') === 'ROLE_TEACHER') {
                    $isTeacher = true;
                }
                $teachingSubjects = $data['teaching_subjects'] ?? [];
                if (!$isTeacher && is_array($teachingSubjects) && !empty($teachingSubjects)) {
                    $isTeacher = true;
                }
                // Legacy free-text Position/designation.
                $position = strtolower(trim((string) ($data['Position'] ?? $data['position'] ?? $data['designation'] ?? '')));
                if (!$isTeacher && $position !== '' && (
                        strpos($position, 'teacher') !== false ||
                        strpos($position, 'lecturer') !== false ||
                        strpos($position, 'faculty') !== false ||
                        in_array($position, ['tgt','pgt','head of department','senior teacher','lab assistant','sports coach'], true)
                    )) {
                    $isTeacher = true;
                }
                if (!$isTeacher) continue;

                $teachers[] = [
                    'id'                => $staffId,
                    'name'              => $data['name'] ?? $data['Name'] ?? $staffId,
                    'teaching_subjects' => is_array($teachingSubjects) ? array_values($teachingSubjects) : [],
                ];
            }
        } catch (\Exception $e) {
            log_message('error', "Academic::get_all_teachers Firestore failed: " . $e->getMessage());
        }

        // Sort alphabetically by name
        usort($teachers, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        return $this->json_success(['teachers' => $teachers]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       SUBJECT ASSIGNMENT
       Reads classes from  Schools/{school}/Config/Classes  (structural data in School Config)
       Reads subjects from Schools/{school}/Subject_list    (master catalog in School Config)
       Stores assignments  Schools/{school}/{session}/Academic/Subject_Assignments/{class}
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * Get available subjects from global Subject_list, Config/Classes list,
     * and current session assignments per class.
     */
    public function get_subject_assignments()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_subject_assignments', 'Academic', 'view');

        $school  = $this->school_name;
        $session = $this->session_year;

        // 1. Read class list from Firestore school doc
        $configClasses = $this->_getConfigClassesFromFirestore();

        // If Config/Classes is empty, fall back to live enumeration
        $classes = [];
        if (!empty($configClasses)) {
            foreach ($configClasses as $cls) {
                if (!is_array($cls)) continue;
                if (!empty($cls['deleted'])) continue;
                $classes[] = [
                    'key'   => $cls['key'] ?? $cls['name'] ?? '',
                    'label' => $cls['label'] ?? $cls['name'] ?? $cls['key'] ?? '',
                ];
            }
        }
        if (empty($classes)) {
            // Fallback: enumerate from session root
            $sessionClasses = $this->_get_session_classes();
            $seen = [];
            foreach ($sessionClasses as $sc) {
                $k = $sc['class_key'];
                if (isset($seen[$k])) continue;
                $seen[$k] = true;
                $classes[] = ['key' => $k, 'label' => $k];
            }
        }

        // 2. Global Subject_list (master catalog) — Firestore-only
        $catalog = $this->_getSubjectCatalogFromFirestore();

        // 3. Current session assignments — ONE bulk Firestore query (not per-class)
        $this->load->library('subject_assignment_service', null, 'sas');
        $this->sas->init(
            $this->fs,
            $this->firebase,
            $this->school_id,
            $this->school_name,
            $this->session_year
        );

        $allByClass = $this->sas->getAllForSession();

        // Build a lookup from canonical className → fbKey for the JS dropdown
        $classLabelToFbKey = [];
        foreach ($classes as $cls) {
            $rawKey   = (string) ($cls['key'] ?? '');
            $labelKey = $this->_normalize_class_label($rawKey);
            $fbKey    = $this->_class_to_firebase_key($rawKey);
            $classLabelToFbKey[$labelKey] = $fbKey;
            $classLabelToFbKey[$rawKey]   = $fbKey; // also map raw key
            $classLabelToFbKey[$fbKey]    = $fbKey;  // identity
        }

        $assignments = [];
        foreach ($allByClass as $className => $docs) {
            $fbKey = $classLabelToFbKey[$className]
                  ?? $classLabelToFbKey[$this->_normalize_class_label($className)]
                  ?? $this->_class_to_firebase_key($className);
            $assigned = [];
            foreach ($docs as $info) {
                $assigned[] = [
                    'code'             => (string)($info['subjectCode'] ?? ''),
                    'name'             => $info['subjectName'] ?? '',
                    'category'         => $info['category'] ?? 'Core',
                    'periods_week'     => (int)($info['periodsPerWeek'] ?? 0),
                    'teacher_id'       => $info['teacherId'] ?? '',
                    'teacher_name'     => $info['teacherName'] ?? '',
                    'is_class_teacher' => !empty($info['isClassTeacher']),
                ];
            }
            // Merge with any existing under the same fbKey (e.g. section-specific)
            if (isset($assignments[$fbKey])) {
                $assignments[$fbKey] = array_merge($assignments[$fbKey], $assigned);
            } else {
                $assignments[$fbKey] = $assigned;
            }
        }

        // Ensure every class in the dropdown has an entry (even if empty)
        foreach ($classes as $cls) {
            $fbKey = $this->_class_to_firebase_key((string)($cls['key'] ?? ''));
            if (!isset($assignments[$fbKey])) {
                $assignments[$fbKey] = [];
            }
        }

        // 4. Period settings for validation — Firestore-only
        $settings = null;
        try {
            $fsDocId = "{$this->school_id}_{$session}";
            $settings = $this->firebase->firestoreGet('timetableSettings', $fsDocId);
        } catch (\Exception $e) {
            log_message('error', 'Academic::get_subject_assignments timetableSettings Firestore read failed: ' . $e->getMessage());
        }
        if (!is_array($settings)) $settings = [];
        $periodsPerDay = (int) ($settings['No_of_periods'] ?? 0);
        $workingDays = isset($settings['Working_days']) && is_array($settings['Working_days'])
            ? $settings['Working_days']
            : ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        $numWorkingDays = count($workingDays);
        $maxPeriodsPerWeek = $periodsPerDay * $numWorkingDays;

        return $this->json_success([
            'classes'     => $classes,
            'catalog'     => $catalog,
            'assignments' => $assignments,
            'periodSettings' => [
                'periodsPerDay'    => $periodsPerDay,
                'workingDays'      => $workingDays,
                'numWorkingDays'   => $numWorkingDays,
                'maxPeriodsPerWeek' => $maxPeriodsPerWeek,
            ],
        ]);
    }

    /**
     * Save subject assignments for a class (Phase 2: Firestore-first via service).
     * POST: class_key, section_key (optional), subjects (JSON array)
     *
     * Each subject can include `is_class_teacher` boolean.
     * Validation:
     *   - Teacher must have subject in their teaching_subjects
     *   - Only one teacher per (class, section) can be marked as class teacher
     */
    public function save_subject_assignments()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'save_subject_assignments', 'Academic', 'manage');

        $classKey   = trim($this->input->post('class_key') ?? '');
        $sectionKey = trim($this->input->post('section_key') ?? '');
        $rawSubs    = $this->input->post('subjects');

        if (empty($classKey)) {
            return $this->json_error('Class is required');
        }

        // Normalize to canonical labels so the Teacher app, sections collection,
        // RTDB session structure, and timetable paths all match. Without this,
        // assignments are written as className="8" and become invisible to the
        // Teacher app which queries className="Class 8th".
        $classKey   = $this->_normalize_class_label($classKey);
        $sectionKey = $this->_normalize_section_label($sectionKey);

        $fbKey = $this->safe_path_segment($this->_class_to_firebase_key($classKey), 'class_key');
        if ($sectionKey !== '') {
            $sectionKey = $this->safe_path_segment($sectionKey, 'section_key');
        }

        $subjects = is_string($rawSubs) ? json_decode($rawSubs, true) : $rawSubs;
        if (!is_array($subjects)) $subjects = [];

        // ── Period validation — Firestore-only ──────────────────
        $school  = $this->school_name;
        $session = $this->session_year;
        $settings = null;
        try {
            $settings = $this->firebase->firestoreGet('timetableSettings', "{$this->school_id}_{$session}");
        } catch (\Exception $e) {
            log_message('error', 'Academic::save_subject_assignments timetableSettings Firestore read failed: ' . $e->getMessage());
        }
        if (!is_array($settings)) $settings = [];
        $periodsPerDay = (int) ($settings['No_of_periods'] ?? 0);
        $workingDays = isset($settings['Working_days']) && is_array($settings['Working_days'])
            ? $settings['Working_days']
            : ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        $numWorkingDays = count($workingDays);
        $maxPeriodsPerWeek = $periodsPerDay * $numWorkingDays;

        if ($maxPeriodsPerWeek > 0) {
            $totalAssigned = 0;
            foreach ($subjects as $sub) {
                $pw = (int) ($sub['periods_week'] ?? 0);
                // Per-subject: can't exceed working days
                if ($pw > $numWorkingDays) {
                    $name = $sub['name'] ?? $sub['code'] ?? 'Unknown';
                    return $this->json_error("{$name} has {$pw} periods/week but there are only {$numWorkingDays} working days.");
                }
                $totalAssigned += $pw;
            }
            // Total: can't exceed max
            if ($totalAssigned > $maxPeriodsPerWeek) {
                return $this->json_error("Total assigned periods ({$totalAssigned}) exceeds the maximum ({$maxPeriodsPerWeek} = {$periodsPerDay} periods/day × {$numWorkingDays} days). Remove or reduce some subjects.");
            }
        }

        // ── Teacher workload check (warning, not blocking) ──────
        $workloadWarnings = [];
        $workloadThreshold = 30; // default max periods/week per teacher
        foreach ($subjects as $sub) {
            $tid = trim($sub['teacher_id'] ?? '');
            $pw  = (int) ($sub['periods_week'] ?? 0);
            if ($tid === '' || $pw === 0) continue;

            // Count this teacher's existing periods across ALL sections
            try {
                $allAssignments = $this->sas ?? null;
                if (!$allAssignments) {
                    $this->load->library('subject_assignment_service', null, 'sas');
                    $this->sas->init($this->fs, $this->firebase, $this->school_id, $this->school_name, $this->session_year);
                }
                $teacherAssignments = $this->sas->getAssignmentsForTeacher($tid);
                $existingPW = 0;
                foreach ($teacherAssignments as $a) {
                    // Skip assignments for the current class+section (they're being replaced)
                    $aClass = $a['className'] ?? '';
                    $aSec   = $a['section'] ?? '';
                    if ($aClass === $classKey && $aSec === $sectionKey) continue;
                    $existingPW += (int) ($a['periodsPerWeek'] ?? 0);
                }
                $newTotal = $existingPW + $pw;
                if ($newTotal > $workloadThreshold) {
                    $tname = $sub['teacher_name'] ?? $tid;
                    $workloadWarnings[] = "{$tname} will have {$newTotal} periods/week (threshold: {$workloadThreshold})";
                }
            } catch (\Exception $e) {
                // Non-blocking — skip workload check if query fails
            }
        }

        // Use Subject_assignment_service for validation + dual-write
        $this->load->library('subject_assignment_service', null, 'sas');
        $this->sas->init(
            $this->fs,
            $this->firebase,
            $this->school_id,
            $this->school_name,
            $this->session_year
        );

        $result = $this->sas->saveClassAssignments($fbKey, $sectionKey, $subjects);

        if (!$result['success']) {
            return $this->json_error(implode("\n", $result['errors']));
        }

        $allWarnings = array_merge($result['warnings'], $workloadWarnings);

        // Auto-sync timetable periods for this class/section (see save_class_matrix).
        $sync = ['docsUpdated' => 0, 'periodsChanged' => 0];
        try {
            $sync = $this->_timetable_assignments_changed($fbKey, $sectionKey);
        } catch (\Throwable $e) {
            log_message('error', 'save_subject_assignments timetable auto-sync failed: ' . $e->getMessage());
        }

        return $this->json_success([
            'class'         => $fbKey,
            'section'       => $sectionKey,
            'count'         => $result['saved'],
            'warnings'      => $allWarnings,
            'timetableSync' => $sync,
        ]);
    }

    /**
     * Get subject assignments for a specific class+section (Phase 4).
     * POST: class_key, section_key
     */
    public function get_subject_assignments_for_section()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_subject_assignments', 'Academic', 'view');

        $classKey = trim($this->input->post('class_key') ?? '');
        $sectionKey = trim($this->input->post('section_key') ?? '');

        if ($classKey === '') {
            return $this->json_error('class_key is required');
        }

        // Same canonical normalization as save_subject_assignments
        $classKey   = $this->_normalize_class_label($classKey);
        $sectionKey = $this->_normalize_section_label($sectionKey);

        $classKey = $this->safe_path_segment($this->_class_to_firebase_key($classKey), 'class_key');
        if ($sectionKey !== '') {
            $sectionKey = $this->safe_path_segment($sectionKey, 'section_key');
        }

        $this->load->library('subject_assignment_service', null, 'sas');
        $this->sas->init(
            $this->fs,
            $this->firebase,
            $this->school_id,
            $this->school_name,
            $this->session_year
        );

        $serviceResults = $this->sas->getAssignmentsForClass($classKey, $sectionKey);

        $subjects = [];
        foreach ($serviceResults as $info) {
            $subjects[] = [
                'code'             => (string)($info['subjectCode'] ?? ''),
                'name'             => $info['subjectName'] ?? '',
                'category'         => $info['category'] ?? 'Core',
                'periods_week'     => (int)($info['periodsPerWeek'] ?? 0),
                'teacher_id'       => $info['teacherId'] ?? '',
                'teacher_name'     => $info['teacherName'] ?? '',
                'is_class_teacher' => !empty($info['isClassTeacher']),
            ];
        }

        return $this->json_success([
            'class_key'   => $classKey,
            'section_key' => $sectionKey,
            'subjects'    => $subjects,
        ]);
    }

    /**
     * Matrix view (Subject × Section) for a whole class in a single call.
     * POST: class_key
     *
     * Groups all of a class's assignment rows by subject:
     *   - a row with section=""  → the subject is CLASS-WIDE (same teacher for
     *     every section); we surface it as same_for_all=true.
     *   - rows with section="Section X" → the subject is PER-SECTION; we surface
     *     each section's teacher. Any per-section row flips same_for_all to false.
     * Class teacher is derived from isClassTeacher on the rows (per-section wins
     * over a class-wide flag).
     */
    public function get_class_matrix()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_class_matrix', 'Academic', 'view');

        $classInput = trim($this->input->post('class_key') ?? '');
        if ($classInput === '') {
            return $this->json_error('class_key is required');
        }

        $canonicalClass = $this->_normalize_class_label($classInput);   // "Class 9th"
        $fbKey = $this->safe_path_segment($this->_class_to_firebase_key($canonicalClass), 'class_key');

        // Sections that exist for this class (canonical className match).
        // Fail-loud: this grid is what the administrator then SAVES, and the
        // save reconciles — anything the request does not mention is deleted.
        // A failed sections read used to draw a grid with no section columns,
        // which on save revoked every section teacher in the class. An empty
        // class and an unreadable one must not look the same here of all places.
        $sections = [];
        try {
            $fsDocs = $this->fs->schoolWhereOrFail('sections',
                [['className', '==', $canonicalClass]]);
            foreach ($fsDocs as $doc) {
                $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                $sec = (string)($d['section'] ?? '');
                if ($sec !== '') $sections[] = $sec;
            }
        } catch (\Throwable $e) {
            log_message('error', 'Academic::get_class_matrix sections read failed: ' . $e->getMessage());
            return $this->json_error('The sections for this class could not be read, '
                . 'so the matrix was not loaded. Please try again in a moment — do '
                . 'not save from a partly loaded grid.');
        }
        $sections = array_values(array_unique($sections));
        sort($sections);

        $this->load->library('subject_assignment_service', null, 'sas');
        $this->sas->init($this->fs, $this->firebase, $this->school_id, $this->school_name, $this->session_year);

        // Same reasoning: an unreadable assignment list drawn as an empty grid
        // becomes a mass revocation the moment somebody saves it.
        try {
            $rows = $this->sas->getAssignmentsForClassOrFail($fbKey, '');   // all rows
        } catch (\Throwable $e) {
            log_message('error', 'Academic::get_class_matrix assignments read failed: '
                . $e->getMessage());
            return $this->json_error('The subject assignments for this class could '
                . 'not be read, so the matrix was not loaded. Please try again in a '
                . 'moment — do not save from a partly loaded grid.');
        }

        $subjects = [];        // code => entry
        $classTeachers = [];   // "Section X" => teacherId

        foreach ($rows as $r) {
            $code = (string)($r['subjectCode'] ?? '');
            if ($code === '') continue;
            $sec  = (string)($r['section'] ?? '');      // '' = class-wide
            $tid  = (string)($r['teacherId'] ?? '');
            $tnm  = (string)($r['teacherName'] ?? '');
            $isCT = !empty($r['isClassTeacher']);

            if (!isset($subjects[$code])) {
                $subjects[$code] = [
                    'code'                    => $code,
                    'name'                    => $r['subjectName'] ?? '',
                    'category'                => $r['category'] ?? 'Core',
                    'periods_week'            => (int)($r['periodsPerWeek'] ?? 0),
                    'same_for_all'            => true,
                    'class_wide_teacher_id'   => '',
                    'class_wide_teacher_name' => '',
                    'section_teachers'        => [],
                ];
            }
            $pw = (int)($r['periodsPerWeek'] ?? 0);
            if ($pw > 0) $subjects[$code]['periods_week'] = $pw;

            if ($sec === '') {
                $subjects[$code]['class_wide_teacher_id']   = $tid;
                $subjects[$code]['class_wide_teacher_name'] = $tnm;
                if ($isCT && $tid !== '') {
                    foreach ($sections as $s) {
                        if (!isset($classTeachers[$s])) $classTeachers[$s] = $tid;
                    }
                }
            } else {
                $subjects[$code]['same_for_all'] = false;
                $subjects[$code]['section_teachers'][$sec] = ['id' => $tid, 'name' => $tnm];
                if ($isCT && $tid !== '') $classTeachers[$sec] = $tid;   // per-section overrides class-wide
            }
        }

        return $this->json_success([
            'class_key'      => $fbKey,
            'canonical'      => $canonicalClass,
            'sections'       => $sections,
            'subjects'       => array_values($subjects),
            'class_teachers' => (object)$classTeachers,
        ]);
    }

    /**
     * Save a whole-class matrix in one call.
     * POST: class_key, matrix (JSON: {sections:[], subjects:[...], class_teachers:{}})
     *
     * Reuses the tested saveClassAssignments() per shape:
     *   - same_for_all subjects → one class-wide save (section_key="")
     *   - per-section subjects  → one save per section
     * Because each saveClassAssignments() deletes-then-writes its own (class,
     * section) rows, a subject that moved class-wide↔per-section is cleaned up:
     * it simply no longer appears in the shape it left. This makes the old
     * "class-wide + per-section both apply" conflict impossible.
     */
    public function save_class_matrix()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'save_class_matrix', 'Academic', 'manage');

        $classInput = trim($this->input->post('class_key') ?? '');
        $rawMatrix  = $this->input->post('matrix');
        if ($classInput === '') {
            return $this->json_error('Class is required');
        }

        $canonicalClass = $this->_normalize_class_label($classInput);
        $fbKey = $this->safe_path_segment($this->_class_to_firebase_key($canonicalClass), 'class_key');

        $matrix = is_string($rawMatrix) ? json_decode($rawMatrix, true) : $rawMatrix;
        if (!is_array($matrix)) {
            return $this->json_error('Invalid matrix payload');
        }
        $subjectsIn      = is_array($matrix['subjects'] ?? null) ? $matrix['subjects'] : [];
        $sectionsIn      = is_array($matrix['sections'] ?? null) ? $matrix['sections'] : [];
        $classTeachersIn = is_array($matrix['class_teachers'] ?? null) ? $matrix['class_teachers'] : [];

        require_once APPPATH . 'libraries/Entity_firestore_sync.php';
        $this->load->library('subject_assignment_service', null, 'sas');
        $this->sas->init($this->fs, $this->firebase, $this->school_id, $this->school_name, $this->session_year);

        // ── Build the DESIRED doc set (deterministic docId → full doc) ──
        // Reconciliation model: compute every doc the matrix should produce,
        // validate ALL of it, then diff against what exists and delete-stale +
        // write. No blanket deletes, no cross-call ordering — a validation or
        // write failure cannot leave the class half-wiped.
        $desired = [];            // docId => doc
        $errors  = [];
        $ctFlaggedSection = [];   // section => true once a per-section CT is flagged

        $build = function (string $section, array $sub, string $tid, string $tname, bool $isCT)
            use ($fbKey, $canonicalClass) {
            $cs     = Entity_firestore_sync::normalizeClassSection($fbKey, $section);
            $cClass = ($cs['className'] ?? '') !== '' ? $cs['className'] : $canonicalClass;
            $cSec   = $cs['section'] ?? '';                       // '' = class-wide
            $skComp = ($cClass !== '' && $cSec !== '') ? "{$cClass}/{$cSec}" : '';
            $docId  = $this->sas->docId($fbKey, $section, (string)$sub['code']);
            return [$docId, [
                'schoolId'       => $this->school_id,
                'session'        => $this->session_year,
                'className'      => $cClass,
                'section'        => $cSec,
                'classOrder'     => $cs['classOrder'] ?? 0,
                'sectionCode'    => $cs['sectionCode'] ?? '',
                'sectionKey'     => $skComp,
                'subjectCode'    => (string)$sub['code'],
                'subjectName'    => (string)($sub['name'] ?? $sub['code']),
                'category'       => (string)($sub['category'] ?? 'Core'),
                'periodsPerWeek' => (int)($sub['periods_week'] ?? 0),
                'teacherId'      => $tid,
                'teacherName'    => $tname,
                'isClassTeacher' => $isCT,
                'updatedAt'      => date('c'),
            ]];
        };

        // ── PERIOD CAPS — the SERVER's copy of the rule ─────────────────
        //
        // save_subject_assignments() has enforced these since it was written.
        // This surface — the second writer of the very same collection — had
        // them ONLY in the browser, so any request that did not come from that
        // screen could write a curriculum the week cannot hold.
        //
        // It is not theoretical. Class 9th in the live tenant asks for 45
        // periods against a 36-period week; the generator then silently
        // under-delivers (English 10 short, Hindi 5 short) and the shortfall
        // surfaces only later, in a timetable report, as though the generator
        // had failed. The same two-writers-one-guard shape produced ACAD-D14
        // and ACAD-D18 in this file.
        //
        // Through the UI nothing changes: the screen already refuses the same
        // two conditions before posting. This closes the bypass around it.
        //
        // Both key shapes are read. saveSettings() dual-writes
        // periodsPerDay/No_of_periods and workingDays/Working_days, so the
        // legacy-only read in the sibling works today — but it would silently
        // disable the cap if that dual-write were ever dropped.
        //
        // Unconfigured period scheduling means there IS no cap to apply, and
        // inventing one would refuse every save for a school that has not set
        // its week up yet. That matches the sibling exactly.
        $ttSettings = null;
        try {
            $ttSettings = $this->firebase->firestoreGet(
                'timetableSettings', "{$this->school_id}_{$this->session_year}");
        } catch (\Throwable $e) {
            log_message('error', 'Academic::save_class_matrix timetableSettings read failed: '
                . $e->getMessage());
        }
        if (!is_array($ttSettings)) { $ttSettings = []; }

        $periodsPerDay = (int) ($ttSettings['periodsPerDay'] ?? $ttSettings['No_of_periods'] ?? 0);
        $workingDaysIn = $ttSettings['workingDays'] ?? $ttSettings['Working_days'] ?? null;
        $workingDays   = is_array($workingDaysIn) && $workingDaysIn
            ? $workingDaysIn
            : ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        $numWorkingDays    = count($workingDays);
        $maxPeriodsPerWeek = $periodsPerDay * $numWorkingDays;

        if ($maxPeriodsPerWeek > 0) {
            $totalAssigned = 0;
            foreach ($subjectsIn as $sub) {
                if ((string)($sub['code'] ?? '') === '') continue;
                $pw = (int) ($sub['periods_week'] ?? 0);
                // More periods than working days means the same subject twice
                // in one day for at least one day — a different statement than
                // the operator made, so it is refused rather than reshaped.
                if ($pw > $numWorkingDays) {
                    $n = (string)($sub['name'] ?? $sub['code'] ?? 'Unknown');
                    $errors[] = "{$n} has {$pw} periods/week but there are only "
                              . "{$numWorkingDays} working days.";
                }
                $totalAssigned += $pw;
            }
            if ($totalAssigned > $maxPeriodsPerWeek) {
                $errors[] = "Total assigned periods ({$totalAssigned}) exceeds the "
                          . "maximum ({$maxPeriodsPerWeek} = {$periodsPerDay} periods/day "
                          . "× {$numWorkingDays} days). Remove or reduce some subjects.";
            }
        }

        foreach ($subjectsIn as $sub) {
            $code = (string)($sub['code'] ?? '');
            if ($code === '') continue;
            $name = (string)($sub['name'] ?? $code);

            if (!empty($sub['same_for_all'])) {
                $tid = (string)($sub['class_wide_teacher_id'] ?? '');
                $tnm = (string)($sub['class_wide_teacher_name'] ?? '');
                if ($tid !== '' && !$this->_can_teach_or_explain($tid, $code, $name, $errors)) {
                    $errors[] = "{$name}: selected teacher is not authorized to teach this subject.";
                }
                [$id, $doc] = $build('', $sub, $tid, $tnm, false);
                $desired[$id] = $doc;
            } else {
                $st = is_array($sub['section_teachers'] ?? null) ? $sub['section_teachers'] : [];
                foreach ($sectionsIn as $s) {
                    $t   = is_array($st[$s] ?? null) ? $st[$s] : [];
                    $tid = (string)($t['id'] ?? '');
                    $tnm = (string)($t['name'] ?? '');
                    if ($tid !== '' && !$this->_can_teach_or_explain($tid, $code, $name, $errors)) {
                        $errors[] = "{$name} ({$s}): selected teacher is not authorized to teach this subject.";
                    }
                    $isCT = false;
                    if ($tid !== '' && isset($classTeachersIn[$s]) && (string)$classTeachersIn[$s] === $tid && empty($ctFlaggedSection[$s])) {
                        $isCT = true; $ctFlaggedSection[$s] = true;
                    }
                    [$id, $doc] = $build($s, $sub, $tid, $tnm, $isCT);
                    $desired[$id] = $doc;
                }
            }
        }

        // Class-wide class teacher: for a section whose CT teacher has no
        // per-section row, flag one of that teacher's class-wide rows.
        foreach ($classTeachersIn as $s => $tid) {
            $tid = (string)$tid;
            if ($tid === '' || !empty($ctFlaggedSection[$s])) continue;
            foreach ($desired as $id => $doc) {
                if ($doc['section'] === '' && $doc['teacherId'] === $tid && empty($doc['isClassTeacher'])) {
                    $desired[$id]['isClassTeacher'] = true;
                    break;
                }
            }
        }

        // Validation failed → abort BEFORE touching any data.
        if (!empty($errors)) {
            return $this->json_error(implode("\n", array_unique($errors)));
        }

        // ── Load CURRENT docs for this class (ids only) ────────────────
        // ACAD-D18: $current is the REVOCATION SET — every id in it that is not
        // in $desired gets deleted below. schoolWhere() -> firestoreQuery()
        // returns [] for a FAILED read exactly as it does for "this class has no
        // assignments yet", so a transient failure emptied the revocation set,
        // the writes below still ran, and every teacher the admin removed kept
        // their row — and with it their marks-write access, which
        // firestore.rules grants straight off these docs.
        //
        // Same defect as ACAD-D14 in Subject_assignment_service; this is the
        // second write surface for the same collection.
        // ── THE OTHER WRITE SURFACE HOLDS THE SAME AUTHORITY ─────────────
        //
        // Subject_assignment_service serialises a per-section save on a
        // per-section document, so two administrators cannot both replace one
        // section from the same snapshot. This screen replaces a WHOLE CLASS
        // and, until now, took no token at all — so it could land in the middle
        // of a section save without either noticing, and a teacher removed on
        // one screen could survive on the other with their marks access intact.
        //
        // Taking one token per section (plus the class-wide slot) interlocks
        // the two surfaces without collapsing them: administrators working on
        // DIFFERENT sections still do not block each other, because they touch
        // different documents.
        // A token is needed for every section whose authority this save can
        // CHANGE — which is not the same as the sections the request listed.
        // This screen reconciles the whole class: a section the grid does not
        // mention still has its rows revoked, so taking tokens only for the
        // listed sections would leave exactly the revocations nobody agreed to
        // unguarded.
        //
        // The existing serialisation documents for this class are therefore
        // enumerated first. Any section that has ever been saved has one, so a
        // concurrent per-section save is caught whether or not this grid knew
        // that section existed.
        $ledgerSections = $sectionsIn;
        $ledgerSections[] = '';                       // the class-wide slot
        $ledgerTokens = [];      // ledgerDocId => updateTime ('' = expected absent)
        try {
            $existingLedgers = $this->fs->whereOrFail('subjectAssignmentSections', [
                ['schoolId',  '==', $this->school_id],
                ['session',   '==', $this->session_year],
                ['className', '==', $canonicalClass],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'save_class_matrix: could not enumerate the '
                . 'serialisation documents for ' . $canonicalClass . ': ' . $e->getMessage());
            return $this->json_error('The class could not be locked for editing, so '
                . 'nothing was saved. Please try again in a moment.');
        }
        foreach ($existingLedgers as $lrow) {
            $lid = (string) ($lrow['id'] ?? '');
            if ($lid === '') { continue; }
            $ld = is_array($lrow['data'] ?? null) ? $lrow['data'] : $lrow;
            $ledgerTokens[$lid] = (string) ($ld['__updateTime'] ?? '');
        }
        foreach (array_unique($ledgerSections) as $ls) {
            $lid = $this->sas->sectionLedgerId($fbKey, (string) $ls);
            if (array_key_exists($lid, $ledgerTokens)) { continue; }
            $ldoc = $this->fs->get('subjectAssignmentSections', $lid);
            $ledgerTokens[$lid] = is_array($ldoc)
                ? (string) ($ldoc['__updateTime'] ?? '') : '';
        }

        $current = [];
        $currentSections = [];   // the section each stored row belongs to
        try {
            $rows = $this->firebase->firestoreQueryOrFail('subjectAssignments', [
                ['schoolId',  '==', $this->school_id],
                ['session',   '==', $this->session_year],
                ['className', '==', $canonicalClass],
            ]);
            foreach ($rows as $r) {
                $rid = $r['id'] ?? ($r['data']['id'] ?? '');
                if ($rid !== '') {
                    $current[$rid] = true;
                    $rd = is_array($r['data'] ?? null) ? $r['data'] : $r;
                    $currentSections[] = (string) ($rd['section'] ?? '');
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'Academic::save_class_matrix current-load failed: ' . $e->getMessage());
            return $this->json_error(
                'The assignments already saved for this class could not be read, so '
                . 'nothing was changed. Saving now would have left teachers you removed '
                . 'still assigned. Please try again in a moment.'
            );
        }

        // Safety: refuse to wipe a populated class from a request that arrived
        // incomplete. The empty-request case was already covered; the one that
        // was not is a request carrying its class-wide subjects but NO SECTIONS,
        // which builds no per-section rows and so revokes every section teacher
        // in the class while still looking like a real save. That is exactly the
        // shape a failed sections read upstream produces.
        //
        // The decision lives in Subject_matrix_guard so it can be tested by
        // running it rather than by reading this method.
        require_once APPPATH . 'libraries/Subject_matrix_guard.php';

        // Any section that turned up in the current rows but had no
        // serialisation document when the tokens were taken is a section this
        // save could revoke with nothing guarding the decision — it appeared
        // between the two reads. Take a token for it now: if one exists, that
        // section changed underneath this request and the save is refused.
        $unguarded = Subject_matrix_guard::unguardedSections(
            $currentSections,
            fn(string $sec): string => $this->sas->sectionLedgerId($fbKey, $sec),
            $ledgerTokens
        );
        foreach ($unguarded as $sec) {
            $lid  = $this->sas->sectionLedgerId($fbKey, $sec);
            $ldoc = $this->fs->get('subjectAssignmentSections', $lid);
            if (is_array($ldoc)) {
                log_message('error', 'save_class_matrix: section ' . $sec . ' of '
                    . $canonicalClass . ' was changed while this grid was open');
                return $this->json_error('Somebody else changed the subjects for '
                    . 'this class while you were editing them, so nothing was saved. '
                    . 'Reload the class and try again.');
            }
            $ledgerTokens[$lid] = '';
        }

        $refusal = Subject_matrix_guard::refusalReason($desired, $currentSections, $sectionsIn);
        if ($refusal !== null) {
            log_message('error', 'Academic::save_class_matrix refused an incomplete '
                . 'request for ' . $canonicalClass . ': ' . $refusal);
            return $this->json_error($refusal);
        }

        // ── Reconcile: delete stale, then write desired ────────────────
        // remove() returns BOOL and never throws, so the old try/catch inspected
        // nothing at all — a refused delete was invisible. Revocations are
        // attempted first and, if any fails, we stop BEFORE writing the new
        // grants: adding access while failing to remove it is the one ordering
        // that must never happen.
        $stale     = Subject_matrix_guard::staleIds(array_keys($current), $desired);
        $rawClient = method_exists($this->fs, 'raw_client') ? $this->fs->raw_client() : null;
        $canBatch  = ($rawClient !== null && method_exists($rawClient, 'commitBatchStatus'));

        $saved = 0;
        if ($canBatch) {
            // One commit for the whole class: every revocation and every grant
            // lands together, conditional on nobody else having touched these
            // sections since the tokens above were read. Revoking and granting
            // in separate round trips left a window where a teacher had been
            // removed but their replacement was not yet in place.
            $ops = Subject_matrix_guard::commitOps($stale, $desired, $ledgerTokens, [
                'schoolId'  => $this->school_id,
                'session'   => $this->session_year,
                'className' => $canonicalClass,
                'updatedAt' => date('c'),
            ]);

            try {
                $commit = $rawClient->commitBatchStatus($ops);
            } catch (\Throwable $e) {
                log_message('error', 'save_class_matrix commit failed: ' . $e->getMessage());
                return $this->json_error('Could not save the assignments. Nothing was changed.');
            }

            $status = (string) ($commit['status'] ?? '');
            if ($status === 'PRECONDITION_CONFLICT') {
                log_message('error', 'save_class_matrix: class CAS conflict for '
                    . $canonicalClass);
                return $this->json_error('Somebody else changed the subjects for this '
                    . 'class while you were editing them, so nothing was saved — saving '
                    . 'now would have merged your grid into theirs and left teachers '
                    . 'assigned that neither of you chose. Reload the class and try again.');
            }
            if ($status !== 'SUCCESS') {
                log_message('error', 'save_class_matrix commit returned ' . $status);
                return $this->json_error('Could not save the assignments. Nothing was changed.');
            }
            $saved = count($desired);
        } else {
            // A client that cannot batch keeps the previous ordering: revoke
            // first and stop before granting, because adding access while
            // failing to remove it is the one order that must never happen.
            $revokeFailed = [];
            foreach ($stale as $rid) {
                if (!$this->fs->remove('subjectAssignments', $rid)) {
                    $revokeFailed[] = $rid;
                    log_message('error', 'Academic::save_class_matrix could not revoke '
                        . 'subjectAssignments/' . $rid);
                }
            }
            if (!empty($revokeFailed)) {
                return $this->json_error(
                    'Could not remove ' . count($revokeFailed) . ' assignment(s) that you '
                    . 'deleted, so no changes were saved. Those teachers would otherwise '
                    . 'have kept access to this class. Please try again in a moment.'
                );
            }
            foreach ($desired as $id => $doc) {
                try { $this->fs->set('subjectAssignments', $id, $doc, true); $saved++; }
                catch (\Exception $e) {
                    $errors[] = "Failed to save {$doc['subjectName']}";
                    log_message('error', "save_class_matrix set [{$id}] failed: " . $e->getMessage());
                }
            }
        }

        // ── Cross-write sections.classTeacherId per section ────────────
        foreach ($sectionsIn as $s) {
            $cs     = Entity_firestore_sync::normalizeClassSection($fbKey, $s);
            $cClass = ($cs['className'] ?? '') !== '' ? $cs['className'] : $canonicalClass;
            $cSec   = $cs['section'] ?? '';
            if ($cSec === '') continue;
            $ctId   = (string)($classTeachersIn[$s] ?? '');
            $sid    = "{$this->school_id}_{$this->session_year}_{$cClass}_{$cSec}";
            try {
                $this->fs->set('sections', $sid, [
                    'classTeacherId' => $ctId,
                    'className'      => $cClass,
                    'section'        => $cSec,
                    'classOrder'     => $cs['classOrder'] ?? 0,
                    'sectionCode'    => $cs['sectionCode'] ?? '',
                    'sectionKey'     => "{$cClass}/{$cSec}",
                    'updatedAt'      => date('c'),
                ], true);
            } catch (\Exception $e) {
                log_message('error', "save_class_matrix section CT cross-write [{$sid}] failed: " . $e->getMessage());
            }
        }

        if (!empty($errors)) {
            return $this->json_error(implode("\n", array_unique($errors)));
        }

        // Auto-sync: propagate teacher changes into already-generated
        // timetable periods for this class so a reassignment shows up in the
        // app immediately (prevents matrix↔timetable teacherId drift).
        $sync = ['docsUpdated' => 0, 'periodsChanged' => 0];
        try {
            $sync = $this->_timetable_assignments_changed($canonicalClass, '');
        } catch (\Throwable $e) {
            log_message('error', 'save_class_matrix timetable auto-sync failed: ' . $e->getMessage());
        }

        return $this->json_success([
            'count'          => $saved,
            'warnings'       => [],
            'timetableSync'  => $sync,
        ]);
    }

    /**
     * Get list of teachers eligible to teach a subject (filtered dropdown).
     * POST: subject_code, subject_name (optional)
     */
    public function get_eligible_teachers()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'view_subject_assignments', 'Academic', 'manage');

        $code = trim($this->input->post('subject_code') ?? '');
        $name = trim($this->input->post('subject_name') ?? '');

        if ($code === '') {
            return $this->json_error('subject_code is required');
        }

        $this->load->library('subject_assignment_service', null, 'sas');
        $this->sas->init(
            $this->fs,
            $this->firebase,
            $this->school_id,
            $this->school_name,
            $this->session_year
        );

        $teachers = $this->sas->getEligibleTeachers($code, $name);

        return $this->json_success([
            'subject_code' => $code,
            'subject_name' => $name,
            'teachers'     => $teachers,
        ]);
    }

    /**
     * Copy subject assignments from one class to another.
     */
    public function copy_subject_assignments()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'copy_subject_assignments', 'Academic', 'manage');

        $fromKey = $this->safe_path_segment(trim($this->input->post('from_key') ?? ''), 'from_key');
        $toKey   = $this->safe_path_segment(trim($this->input->post('to_key') ?? ''), 'to_key');

        if (empty($fromKey) || empty($toKey)) {
            return $this->json_error('Source and destination are required');
        }
        if ($fromKey === $toKey) {
            return $this->json_error('Source and destination cannot be the same');
        }

        // Load the subject assignment service for Firestore-first operations
        $this->load->library('subject_assignment_service', null, 'sas');
        $this->sas->init($this->fs, $this->firebase, $this->school_id, $this->school_name, $this->session_year);

        // Read fail-loud: this endpoint ACTS on an empty answer by stopping and
        // telling the operator the source class has nothing in it. Through the
        // tolerant read that sentence is indistinguishable from a failed query,
        // and it sends somebody off to check a class that is perfectly fine.
        try {
            $sourceAssignments = $this->sas->getAssignmentsForClassOrFail($fromKey, '');
        } catch (\Throwable $e) {
            log_message('error', 'copy_subject_assignments: source unreadable ('
                . $fromKey . '): ' . $e->getMessage());
            return $this->json_error('The source class could not be read, so nothing '
                . 'was copied. Please try again in a moment — this is not the same '
                . 'as the class having no subjects.');
        }

        if (empty($sourceAssignments)) {
            return $this->json_error('No assignments found in source class');
        }

        // Build subjects array in the format saveClassAssignments expects
        $subjects = [];
        foreach ($sourceAssignments as $a) {
            $subjects[] = [
                'code'             => $a['subjectCode'] ?? '',
                'name'             => $a['subjectName'] ?? '',
                'category'         => $a['category'] ?? 'Core',
                'periods_week'     => (int)($a['periodsPerWeek'] ?? 0),
                'teacher_id'       => $a['teacherId'] ?? '',
                'teacher_name'     => $a['teacherName'] ?? '',
                'is_class_teacher' => !empty($a['isClassTeacher']),
            ];
        }

        // Save to destination class via service (Firestore-only)
        $result = $this->sas->saveClassAssignments($toKey, '', $subjects);

        // The save can refuse — a teacher not recorded for the subject, an
        // unreadable delete set, a failed commit. This reported success either
        // way, and read a 'count' key the service has never returned, so the
        // number shown was the number ATTEMPTED. An operator was told their
        // subjects had been copied when nothing had been written at all.
        if (empty($result['success'])) {
            return $this->json_error(!empty($result['errors'])
                ? implode(' ', $result['errors'])
                : 'The subjects could not be copied. Nothing was changed.');
        }
        $count = (int) ($result['saved'] ?? 0);

        // Auto-sync destination class's timetable periods (see save_class_matrix).
        $sync = ['docsUpdated' => 0, 'periodsChanged' => 0];
        try {
            $sync = $this->_timetable_assignments_changed($toKey, '');
        } catch (\Throwable $e) {
            log_message('error', 'copy_subject_assignments timetable auto-sync failed: ' . $e->getMessage());
        }

        return $this->json_success([
            'from' => $fromKey, 'to' => $toKey, 'count' => $count,
            'warnings' => $result['warnings'] ?? [],
            'timetableSync' => $sync,
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       CURRICULUM PLANNING
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * Curriculum doc ID: {schoolId}_{session}_{classSection}_{subject}
     */
    private function _currDocId(string $classSection, string $subject): string
    {
        $cs  = str_replace([' ', '/'], '_', $classSection);
        $sub = str_replace([' ', '/'], '_', $subject);
        return "{$this->school_id}_{$this->session_year}_{$cs}_{$sub}";
    }

    /**
     * Per-assignment authorization for curriculum writes.
     *
     * Admin-tier roles bypass. Teachers must have a row in `subjectAssignments`
     * matching (class, section, subject, teacherId=current_admin_id).
     *
     * @param string $classSectionRaw  e.g. "Class 5/A" (un-sanitized)
     * @param string $subjectRaw       subject name OR subject code
     */
    private function _can_edit_curriculum(string $classSectionRaw, string $subjectRaw): bool
    {
        $role = $this->admin_role ?? '';
        $bypassRoles = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'];
        if (in_array($role, $bypassRoles, true)) {
            return true;
        }

        // Split on LAST '/'. Class names may contain spaces; the section is
        // always the trailing segment ("Class 10th/A" → ["Class 10th", "A"]).
        $className = $classSectionRaw;
        $section   = '';
        $slash = strrpos($classSectionRaw, '/');
        if ($slash !== false) {
            $className = trim(substr($classSectionRaw, 0, $slash));
            $section   = trim(substr($classSectionRaw, $slash + 1));
        }

        try {
            $this->load->library('subject_assignment_service', null, 'sas');
            $this->sas->init($this->fs, $this->firebase, $this->school_id, $this->school_name, $this->session_year);
            $assignments = $this->sas->getAssignmentsForClass($className, $section);
        } catch (\Exception $e) {
            log_message('error', '_can_edit_curriculum SAS lookup failed: ' . $e->getMessage());
            return false;
        }

        $teacherId = $this->admin_id ?? '';
        if ($teacherId === '') return false;

        foreach ($assignments as $a) {
            if (($a['teacherId'] ?? '') !== $teacherId) continue;
            $aName = $a['subjectName'] ?? '';
            $aCode = $a['subjectCode'] ?? '';
            if (strcasecmp($aName, $subjectRaw) === 0 || $aCode === $subjectRaw) {
                return true;
            }
        }
        return false;
    }

    /* ──────────────────────────────────────────────────────────────────
       Phase 1 — Curriculum subcollection helpers

       The curriculum collection is in transition from
         curriculum/{parentDocId}.topics[]   (legacy embedded array)
       to
         curriculum/{parentDocId}/topics/{topicId}   (subcollection)

       Parent doc carries `topicsModel: 'subcollection'` once migrated
       and `topicIds: [uuid, ...]` as the source-of-truth ordering.
       Per-topic partial updates (status flip, single delete) only touch
       the subcollection doc + parent counters — no full-doc rewrite.

       The legacy `topics[]` array on the parent is preserved untouched
       after migration (frozen snapshot) so a rollback is possible.
       Readers prefer subcollection when topicsModel is set; otherwise
       fall back to the array.
    ──────────────────────────────────────────────────────────────────── */

    /** RFC 4122 v4 UUID. Stable per topic across saves. */
    private function _curr_uuid(): string
    {
        if (function_exists('random_bytes')) {
            $b = random_bytes(16);
            $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
            $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
            $h = bin2hex($b);
            return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-'
                 . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
        }
        return 'topic_' . uniqid('', true);
    }

    private function _curr_subcoll_path(string $parentDocId): string
    {
        return "curriculum/{$parentDocId}/topics";
    }

    /**
     * Convert a subcollection topic doc (or a legacy array element) into
     * the legacy-array shape the JS UI consumes. Adds `topicId` so
     * forward-looking JS can use it; existing JS ignores extra keys.
     */
    private function _curr_topic_to_legacy(array $d, string $topicId = ''): array
    {
        return [
            'topicId'        => $topicId !== '' ? $topicId : ($d['topicId'] ?? ''),
            'title'          => $d['title']         ?? '',
            'chapter'        => $d['chapter']       ?? '',
            'est_periods'    => (int)  ($d['estPeriods']    ?? $d['est_periods']    ?? 0),
            'status'         => in_array(($d['status'] ?? ''), ['not_started','in_progress','completed'], true)
                                    ? $d['status'] : 'not_started',
            'completed_date' => (string)($d['completedDate'] ?? $d['completed_date'] ?? ''),
            'sort_order'     => (int)  ($d['sortOrder']     ?? $d['sort_order']     ?? 0),
        ];
    }

    /**
     * Dual-mode topic loader. Returns topics in legacy shape, ordered.
     *  - subcollection mode: parent.topicIds[] → parallel-fetch each topic
     *  - array mode (legacy): parent.topics[] (Phase-0 frozen path)
     */
    private function _curr_load_topics(string $parentDocId, ?array $parent): array
    {
        if (!is_array($parent)) return [];

        $mode = $parent['topicsModel'] ?? '';
        if ($mode === 'subcollection' && is_array($parent['topicIds'] ?? null)) {
            $topicIds = array_values($parent['topicIds']);
            if (empty($topicIds)) return [];
            $coll = $this->_curr_subcoll_path($parentDocId);
            $reqs = [];
            foreach ($topicIds as $tid) {
                if (!is_string($tid) || $tid === '') continue;
                $reqs[$tid] = ['collection' => $coll, 'docId' => $tid];
            }
            $results = [];
            try {
                $rest = $this->firebase->getFirestoreDb();
                if ($rest && method_exists($rest, 'getDocumentsParallel')) {
                    $results = $rest->getDocumentsParallel($reqs);
                } else {
                    // Sequential fallback if the parallel primitive is unavailable.
                    foreach ($reqs as $tag => $r) {
                        $results[$tag] = $this->firebase->firestoreGet($r['collection'], $r['docId']);
                    }
                }
            } catch (\Throwable $e) {
                log_message('error', '_curr_load_topics subcoll fetch failed: ' . $e->getMessage());
                return [];
            }

            $topics = [];
            foreach ($topicIds as $tid) {
                $d = $results[$tid] ?? null;
                if (!is_array($d)) continue;
                $topics[] = $this->_curr_topic_to_legacy($d, $tid);
            }
            return $topics;
        }

        // Legacy array fallback
        if (is_array($parent['topics'] ?? null)) {
            $topics = [];
            foreach ($parent['topics'] as $i => $t) {
                if (!is_array($t)) continue;
                $t['sort_order'] = $t['sort_order'] ?? $i;
                $topics[] = $this->_curr_topic_to_legacy($t);
            }
            return $topics;
        }

        return [];
    }

    /** Compute aggregate progress counters from a topics list (legacy shape). */
    private function _curr_compute_counters(array $topics): array
    {
        $total = count($topics);
        $completed = 0;
        foreach ($topics as $t) {
            if (($t['status'] ?? '') === 'completed') $completed++;
        }
        $pct = $total > 0 ? round(($completed / $total) * 1000) / 10 : 0;
        return [
            'totalTopics'     => $total,
            'completedTopics' => $completed,
            'percentComplete' => $pct,
        ];
    }

    public function get_curriculum()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_curriculum', 'Academic', 'view');
        try {
            return $this->json_success($this->_curriculum_svc()->getCurriculum(
                (string)($this->input->post('class_section') ?? ''),
                (string)($this->input->post('subject') ?? '')
            ));
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    public function save_curriculum()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'save_curriculum', 'Academic', 'edit');
        try {
            return $this->json_success($this->_curriculum_svc()->saveCurriculum(
                (string)($this->input->post('class_section') ?? ''),
                (string)($this->input->post('subject') ?? ''),
                $this->input->post('topics'),
                $this->input->post('expected_version')
            ));
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    public function update_topic_status()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'update_curriculum', 'Academic', 'edit');
        try {
            return $this->json_success($this->_curriculum_svc()->updateTopicStatus(
                (string)($this->input->post('class_section') ?? ''),
                (string)($this->input->post('subject') ?? ''),
                $this->input->post('topicId'),
                $this->input->post('index'),
                trim($this->input->post('status') ?? ''),
                $this->input->post('expected_version')
            ));
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    public function delete_topic()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'delete_curriculum', 'Academic', 'manage');
        try {
            return $this->json_success($this->_curriculum_svc()->deleteTopic(
                (string)($this->input->post('class_section') ?? ''),
                (string)($this->input->post('subject') ?? ''),
                $this->input->post('topicId'),
                $this->input->post('index'),
                $this->input->post('expected_version')
            ));
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    /* ══════════════════════════════════════════════════════════════════════
       LESSON PLANS  (Phase 6 — Daily/Monthly Lesson Planner)
    ══════════════════════════════════════════════════════════════════════ */

    public function get_lesson_plan()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_lesson_plan', 'Academic', 'view');
        try {
            $plan = $this->_lesson_plan_svc()->getLessonPlan(
                trim((string)($this->input->post('teacher_id') ?: $this->admin_id)),
                trim((string)($this->input->post('date') ?? '')),
                (int)($this->input->post('period_index') ?? -1)
            );
            return $this->json_success(['plan' => $plan]);  // null when no plan exists
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    public function get_daily_plan()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_lesson_plan', 'Academic', 'view');
        try {
            // Envelope under `daily` because json_success merges the payload
            // into the top-level object — numeric-keyed arrays would
            // otherwise serialize as { "0": {...}, "1": {...} }.
            return $this->json_success(['daily' => $this->_lesson_plan_svc()->getDailyPlan(
                trim((string)($this->input->post('teacher_id') ?: $this->admin_id)),
                trim((string)($this->input->post('date') ?? ''))
            )]);
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    public function save_lesson_plan()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'edit_lesson_plan', 'Academic', 'edit');
        try {
            return $this->json_success($this->_lesson_plan_svc()->saveLessonPlan([
                'class_name'       => (string)($this->input->post('class_name')     ?? ''),
                'section_name'     => (string)($this->input->post('section_name')   ?? ''),
                'subject'          => (string)($this->input->post('subject')        ?? ''),
                'teacher_id'       => (string)($this->input->post('teacher_id')     ?: $this->admin_id),
                'teacher_name'     => (string)($this->input->post('teacher_name')   ?? ''),
                'date'             => (string)($this->input->post('date')           ?? ''),
                'period_index'     => $this->input->post('period_index'),
                'topic_id'         => (string)($this->input->post('topic_id')       ?? ''),
                'notes'            => (string)($this->input->post('notes')          ?? ''),
                'status'           => (string)($this->input->post('status')         ?? 'planned'),
                'rescheduled_to'   => (string)($this->input->post('rescheduled_to') ?? ''),
                'expected_version' => $this->input->post('expected_version'),
            ]));
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    /**
     * The history of one lesson plan. Both clients have always written these
     * entries; nothing read them back until now.
     */
    public function lesson_plan_history()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_lesson_plan', 'Academic', 'view');
        try {
            return $this->json_success($this->_lesson_plan_svc()->planHistory(
                trim((string)($this->input->post('teacher_id') ?: $this->admin_id)),
                trim((string)($this->input->post('date') ?? '')),
                (int)($this->input->post('period_index') ?? -1)
            ));
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    public function get_monthly_plan()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_lesson_plan', 'Academic', 'view');
        try {
            return $this->json_success($this->_lesson_plan_svc()->getMonthlyPlan(
                trim((string)($this->input->post('teacher_id') ?: $this->admin_id)),
                (int)($this->input->post('year')  ?? 0),
                (int)($this->input->post('month') ?? 0)
            ));
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    /* ══════════════════════════════════════════════════════════════════════
       ANALYTICS  (Phase 8A — admin)  +  PARENT VIEW  (Phase 8B)
    ══════════════════════════════════════════════════════════════════════ */

    private const ANALYTICS_ADMIN_ROLES = [
        'Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'
    ];
    private const ANALYTICS_PARENT_ROLES = [
        'Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher', 'Parent'
    ];

    public function analytics_syllabus_progress()
    {
        $this->_require_role(self::ANALYTICS_ADMIN_ROLES, 'view_analytics', 'Academic', 'view');
        try {
            return $this->json_success(['rows' => $this->_analytics_svc()->getSyllabusProgress()]);
        } catch (Service_exception $e) { return $this->json_error($e->getMessage()); }
    }

    public function analytics_daily_monitoring()
    {
        $this->_require_role(self::ANALYTICS_ADMIN_ROLES, 'view_analytics', 'Academic', 'view');
        try {
            return $this->json_success($this->_analytics_svc()->getDailyMonitoring(
                trim((string)($this->input->post('date') ?: date('Y-m-d')))
            ));
        } catch (Service_exception $e) { return $this->json_error($e->getMessage()); }
    }

    public function analytics_delays()
    {
        $this->_require_role(self::ANALYTICS_ADMIN_ROLES, 'view_analytics', 'Academic', 'view');
        try {
            return $this->json_success($this->_analytics_svc()->getDelays());
        } catch (Service_exception $e) { return $this->json_error($e->getMessage()); }
    }

    public function analytics_subject_progress()
    {
        $this->_require_role(self::ANALYTICS_ADMIN_ROLES, 'view_analytics', 'Academic', 'view');
        try {
            return $this->json_success(['rows' => $this->_analytics_svc()->getSubjectProgress(
                trim((string)($this->input->post('class_section') ?? ''))
            )]);
        } catch (Service_exception $e) { return $this->json_error($e->getMessage()); }
    }

    public function parent_daily_lessons()
    {
        $this->_require_role(self::ANALYTICS_PARENT_ROLES, 'view_parent_planner', 'Academic', 'view');
        try {
            return $this->json_success($this->_analytics_svc()->getParentDailyLessons(
                trim((string)($this->input->post('class_section') ?? '')),
                trim((string)($this->input->post('date')          ?: date('Y-m-d')))
            ));
        } catch (Service_exception $e) { return $this->json_error($e->getMessage()); }
    }

    public function parent_subject_progress()
    {
        $this->_require_role(self::ANALYTICS_PARENT_ROLES, 'view_parent_planner', 'Academic', 'view');
        try {
            return $this->json_success($this->_analytics_svc()->getParentSubjectProgress(
                trim((string)($this->input->post('class_section') ?? ''))
            ));
        } catch (Service_exception $e) { return $this->json_error($e->getMessage()); }
    }

    /* ══════════════════════════════════════════════════════════════════════
       ACADEMIC CALENDAR
    ══════════════════════════════════════════════════════════════════════ */

    public function get_calendar_events()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_calendar', 'Academic', 'view');
        try {
            return $this->json_success($this->_calendar_svc()->getEvents(
                trim($this->input->post('month') ?? '')
            ));
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    /**
     * CAL-CACHE-1 (2026-09-13) — bust the Holiday_service read cache after any
     * calendar write so a just-added/removed holiday (or special-working-day)
     * shows in student attendance IMMEDIATELY, instead of waiting out
     * Holiday_service's ~5-minute TTL. flush() is the method Holiday_service
     * documents for exactly this. Best-effort — a cache hiccup must never fail
     * the save/delete.
     */
    private function _flush_holiday_cache(): void
    {
        try {
            $this->load->library('holiday_service');
            $this->holiday_service->init($this->fs, (string) $this->school_id, (string) $this->session_year);
            $this->holiday_service->flush();
        } catch (\Throwable $e) {
            log_message('error', 'Academic::_flush_holiday_cache failed: ' . $e->getMessage());
        }
    }

    public function save_event()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'manage_calendar', 'Academic', 'manage');
        try {
            $res = $this->_calendar_svc()->saveEvent(
                trim($this->input->post('id')          ?? ''),
                trim($this->input->post('title')       ?? ''),
                trim($this->input->post('type')        ?? 'event'),
                trim($this->input->post('startDate')   ?? $this->input->post('start_date') ?? ''),
                trim($this->input->post('endDate')     ?? $this->input->post('end_date')   ?? ''),
                trim($this->input->post('description') ?? ''),
                $this->input->post('visibleTo'),
                // Phase D — applicability. Absent POST fields decode to null,
                // which Calendar_service::saveEvent() treats as "no restriction",
                // so an existing caller that never sends these keeps working
                // exactly as before.
                $this->input->post('appliesTo'),
                $this->input->post('departments'),
                $this->input->post('exceptStaff')
            );
            $this->_flush_holiday_cache();   // CAL-CACHE-1: reflect in attendance immediately
            return $this->json_success($res);
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    public function delete_event()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'manage_calendar', 'Academic', 'manage');
        try {
            $res = $this->_calendar_svc()->deleteEvent(
                trim($this->input->post('id') ?? '')
            );
            $this->_flush_holiday_cache();   // CAL-CACHE-1: reflect in attendance immediately
            return $this->json_success($res);
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    /* ══════════════════════════════════════════════════════════════════════
       MASTER TIMETABLE
    ══════════════════════════════════════════════════════════════════════ */

    public function get_master_timetable()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_timetable', 'Academic', 'view');
        try {
            return $this->json_success($this->_timetable_svc()->getMasterTimetable(
                $this->_get_session_classes()
            ));
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    /**
     * The timetable in force on a date, read from the CANONICAL versions.
     *
     * ── WHY THIS EXISTS ─────────────────────────────────────────────────
     *
     * get_master_timetable() above reads the LEGACY `timetables` collection.
     * That collection is a PROJECTION: its only writer is
     * Timetable_plan_store::projectPublishedVersion(apply:true), which runs
     * when a version is in force on the day it is published, or from
     * Timetable_projection_cron — CLI-only, and unscheduled while the
     * canonical switchover sits behind its two owner gates.
     *
     * So a timetable can be published, correct, and visible on the Master
     * Timetable screen while the legacy collection still holds the previous
     * one. Teacher Workload read that stale copy and reported it as fact:
     * on 2026-09-12 a teacher with 18 canonical periods showed as absent from
     * the load table entirely (UAT, operator instruction: workload must follow
     * the canonical timetable, not the legacy mirror).
     *
     * ── WHAT THIS IS NOT ────────────────────────────────────────────────
     *
     * This is NOT the canonical switchover. It adds one admin-panel READ of
     * the canonical versions; it writes nothing, changes no projection, and
     * leaves the Teacher and Parent apps on `timetables` exactly as the
     * architecture requires. Gate A (rollover) and gate B (TT-D1 rules) both
     * govern WRITES to `timetables` and are untouched by a server-side read
     * made through the service account.
     *
     * Returns the same {settings, timetables} shape get_master_timetable()
     * returns, so a consumer swaps the endpoint and nothing else, PLUS a
     * `source` block saying which version answered and for which date —
     * because "no timetable applies" and "we could not tell" must never
     * render as the same empty grid.
     */
    public function get_canonical_timetable()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_timetable', 'Academic', 'view');

        $date = trim((string) ($this->input->post('date') ?? ''));
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }

        require_once APPPATH . 'libraries/Timetable_plan_store.php';

        try {
            $store = (new Timetable_plan_store())->init(
                $this->firebase,
                (string) $this->school_id,
                (string) $this->session_year,
                (string) ($this->session->userdata('admin_id') ?? '')
            );

            // A READ MUST NOT WRITE. ensureRegularPlan() creates the plan
            // document, and an audit 'create' row attributed to the viewer,
            // when none exists. At a brand-new school the first person to open
            // Teacher Workload would therefore author a record — and that
            // person may hold only `view`, which this endpoint grants. Worse,
            // if the write failed, a school whose only real state is "no
            // timetable yet" would get a hard error instead of being told so.
            //
            // Nothing on this path needs that document: resolveForDate() reads
            // the versions, and listCandidateDays() uses planId only as a query
            // filter. Publishing still creates the plan, from the edit side.
            $planId = Timetable_plan_store::PLAN_REGULAR;

            // The SAME resolver the Master Timetable screen uses. Re-deriving
            // "which timetable applies" here would be a second authority on the
            // one question the whole lifecycle exists to answer.
            $res    = $store->resolveForDate($date);
            $status = (string) ($res['status'] ?? '');

            $source = [
                'date'      => $date,
                'status'    => $status,
                'versionId' => (string) ($res['version']['versionId'] ?? ''),
                'explain'   => (string) ($res['explain'] ?? ''),
                'canonical' => true,
            ];

            // Anything but a single resolved version returns NO grid and says
            // why. An unreadable read that rendered as an empty week would put
            // every teacher's load at zero and look like a quiet school.
            if ($status !== Timetable_plan_service::RESOLVED
                || ($source['versionId'] === '')) {
                return $this->json_success([
                    'settings'   => $this->_timetable_svc()->getSettings(),
                    'timetables' => (object) [],
                    'source'     => $source,
                    // 'none' is a COMPLETE answer — the school genuinely has no
                    // timetable for that date. Every other non-resolved status
                    // means we could not tell, which is incomplete.
                    'completeness' => ['complete' => ($status === Timetable_plan_service::NONE)],
                ]);
            }

            $days = $store->listCandidateDays($planId, $source['versionId']);
        } catch (\Throwable $e) {
            log_message('error', 'Academic::get_canonical_timetable failed: ' . $e->getMessage());
            return $this->json_error(
                'The timetable that applies on ' . $date . ' could not be read, so '
                . 'no workload is shown. This is not an empty timetable — please try '
                . 'again in a moment.'
            );
        }

        // Reshape version days into the legacy grid shape the consumers expect:
        //   { "Class 9th/Section A": { "Monday": [cell, …] } }
        $timetables = [];
        foreach ($days as $d) {
            if (!is_array($d)) continue;
            $sectionKey = (string) ($d['classSection'] ?? '');
            $day        = (string) ($d['day'] ?? '');
            if ($sectionKey === '' || $day === '') continue;

            // Index BY period slot, then fill the gaps — never array_values() a
            // sparse array. The consumer reads periods[0..n-1] as period 1..n,
            // so collapsing a missing slot would shift every later period one
            // earlier: a lesson stored for period 4 would render, and be
            // counted against a teacher, as period 3. Today's data has no gaps;
            // this makes a gap harmless rather than silently wrong.
            $cells = [];
            $maxIdx = -1;
            foreach ((is_array($d['periods'] ?? null) ? $d['periods'] : []) as $i => $p) {
                if (!is_array($p)) { continue; }
                $idx = ((int) ($p['periodNumber'] ?? ($i + 1))) - 1;
                if ($idx < 0) { $idx = (int) $i; }
                $subject = trim((string) ($p['subject'] ?? ''));
                $cells[$idx] = $subject === '' ? null : [
                    'subject'      => $subject,
                    'teacher_id'   => trim((string) ($p['teacherId'] ?? '')),
                    'teacher_name' => trim((string) ($p['teacher'] ?? '')),
                ];
                if ($idx > $maxIdx) { $maxIdx = $idx; }
            }
            $dense = [];
            for ($p = 0; $p <= $maxIdx; $p++) {
                $dense[$p] = $cells[$p] ?? null;     // a free period stays a hole
            }
            $timetables[$sectionKey][$day] = $dense;
        }

        // listCandidateDays() THROWS on saturation rather than truncating, so a
        // grid that got here is whole by construction — unlike the legacy read,
        // which caps and reports a partial week.
        return $this->json_success([
            'settings'     => $this->_timetable_svc()->getSettings(),
            'timetables'   => $timetables ?: (object) [],
            'source'       => $source,
            'completeness' => ['complete' => true],
        ]);
    }

    public function save_period()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'edit_timetable', 'Academic', 'manage');
        try {
            return $this->json_success($this->_timetable_svc()->savePeriod(
                $this->safe_path_segment(trim($this->input->post('class_key') ?? ''), 'class_key'),
                $this->safe_path_segment(trim($this->input->post('section')   ?? ''), 'section'),
                trim($this->input->post('day') ?? ''),
                (int)($this->input->post('period_index') ?? -1),
                trim($this->input->post('subject')      ?? ''),
                trim($this->input->post('teacher_id')   ?? ''),
                trim($this->input->post('teacher_name') ?? '')
            ));
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    /**
     * Pre-save conflict check for a single cell.
     *
     * Checks: (1) teacher double-booked in another class for same day/period,
     *         (2) weekly period-limit exceeded for the subject in this class.
     *
     * Returns {conflict:bool, type, message, severity} or {conflict:false, warnings:[]}.
     */
    public function detect_conflicts()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_timetable', 'Academic', 'view');
        try {
            return $this->json_success($this->_timetable_svc()->detectConflicts(
                trim($this->input->post('subject')         ?? ''),
                trim($this->input->post('teacher_id')      ?? ''),
                trim($this->input->post('day')             ?? ''),
                (int)($this->input->post('period_index') ?? -1),
                trim($this->input->post('exclude_class')   ?? ''),
                trim($this->input->post('exclude_section') ?? '')
            ));
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    /* ══════════════════════════════════════════════════════════════════════
       SUBSTITUTE MANAGEMENT
    ══════════════════════════════════════════════════════════════════════ */

    public function get_substitutes()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_substitutes', 'Academic', 'view');
        try {
            return $this->json_success($this->_substitute_svc()->getSubstitutes(
                trim($this->input->post('date')      ?? ''),
                trim($this->input->post('date_from') ?? ''),
                trim($this->input->post('date_to')   ?? '')
            ));
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    /**
     * Normalize a substitute doc for API response — emit BOTH camelCase
     * (canonical) and snake_case (legacy) keys so existing JS clients keep
     * working through the migration window.
     */
    private function _substitute_response_shape(array $d): array
    {
        // Top-level identity fields
        $absentId   = $d['absentTeacherId']   ?? $d['absent_teacher_id']   ?? '';
        $absentName = $d['absentTeacherName'] ?? $d['absent_teacher_name'] ?? '';
        $createdBy  = $d['createdByName']     ?? $d['created_by']          ?? '';
        $updatedBy  = $d['updatedByName']     ?? $d['updated_by']          ?? '';

        $d['absentTeacherId']     = $absentId;
        $d['absent_teacher_id']   = $absentId;
        $d['absentTeacherName']   = $absentName;
        $d['absent_teacher_name'] = $absentName;
        $d['createdByName']       = $createdBy;
        $d['created_by']          = $createdBy;
        $d['updatedByName']       = $updatedBy;
        $d['updated_by']          = $updatedBy;

        // Per-assignment fields inside `assignments[]`
        if (isset($d['assignments']) && is_array($d['assignments'])) {
            $d['assignments'] = array_map(function ($a) {
                if (!is_array($a)) return $a;
                $sid = $a['substituteTeacherId']   ?? $a['substitute_teacher_id']   ?? '';
                $snm = $a['substituteTeacherName'] ?? $a['substitute_teacher_name'] ?? '';
                $a['substituteTeacherId']     = $sid;
                $a['substitute_teacher_id']   = $sid;
                $a['substituteTeacherName']   = $snm;
                $a['substitute_teacher_name'] = $snm;
                return $a;
            }, $d['assignments']);
        }

        return $d;
    }

    /**
     * CAL-P3B — warn, then require explicit confirmation, before arranging cover
     * on a date the school is not teaching.
     *
     * WHY A GATE AT ALL. Saving a cover NOTIFIES the covering teacher. Without
     * this, a school could push somebody to cover a lesson on a declared student
     * holiday, and the first they would know of it is the notification.
     *
     * WHY CONFIRMATION AND NOT REFUSAL. Special sessions on a closure day are
     * legitimate — a revision class before a board exam, a make-up session. A
     * flat refusal would remove a real workflow to prevent an unlikely mistake.
     * The market evidence is the same for invigilation: working a non-working
     * day is normal and handled as workload, not as an impossibility. So the
     * operator is told plainly and then allowed to proceed on purpose.
     *
     * WHY IT LIVES HERE AND NOT IN Substitute_service. The Substitute programme
     * owns what a cover IS and how it is written. This is the Calendar telling
     * the operator what the date MEANS before that write happens. The service's
     * signature, validation and persistence are untouched, so the two programmes
     * stay separable.
     *
     * FAIL-OPEN, DELIBERATELY. The gate engages only on a definite `false`. An
     * unconfigured tenant (null) or an unreadable calendar (null) passes
     * straight through, because a calendar outage must not stop a school
     * arranging cover for a lesson that is genuinely running.
     *
     * @return array|null null to proceed; a response array to return instead
     */
    private function _non_instruction_cover_gate(string $dateStr): ?array
    {
        if (trim($dateStr) === '') return null;

        $cal = $this->_student_calendar_for_date($dateStr);

        // Only a definite "no instruction" gates. null never does.
        if (($cal['instruction'] ?? null) !== false) return null;

        $confirmed = filter_var(
            (string) ($this->input->post('confirm_non_instruction') ?? ''),
            FILTER_VALIDATE_BOOLEAN
        );
        if ($confirmed) {
            // Recorded so the trail shows the operator was told and chose to
            // continue, rather than looking like an ordinary cover.
            log_message('info', sprintf(
                'Academic::save_substitute — cover confirmed on a non-instruction date '
                . '(school=%s date=%s reason=%s source=%s)',
                $this->school_id, $dateStr,
                (string) ($cal['reason'] ?? ''), (string) ($cal['source'] ?? '')
            ));
            return null;
        }

        return [
            'needsConfirmation' => true,
            'calendar'          => $cal,
            'message'           => ($cal['message'] ?? '')
                ?: 'No classes are scheduled on this date.',
        ];
    }

    public function save_substitute()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'manage_substitutes', 'Academic', 'manage');

        // CAL-P3B — before anything is written or anybody is notified.
        $gate = $this->_non_instruction_cover_gate(
            trim($this->input->post('date') ?? ''));
        if ($gate !== null) {
            return $this->json_error($gate['message']
                . ' Confirm that cover is still needed before this is saved.', 409);
        }

        try {
            // NOTE: class_section + subject are LEGACY-FALLBACK fields only —
            // used when the new `assignments[]` array is empty. Pass raw (do NOT
            // call safe_path_segment unconditionally; it 400s on empty input
            // and would block the modern path where assignments[] is supplied).
            // The service runs sanitization only when actually using the legacy path.
            $res = $this->_substitute_svc()->saveSubstitute(
                trim($this->input->post('id')   ?? ''),
                trim($this->input->post('date') ?? ''),
                trim($this->input->post('absentTeacherId')   ?? $this->input->post('absent_teacher_id')   ?? ''),
                trim($this->input->post('absentTeacherName') ?? $this->input->post('absent_teacher_name') ?? ''),
                $this->input->post('assignments'),
                trim($this->input->post('reason') ?? ''),
                trim($this->input->post('substituteTeacherId')   ?? $this->input->post('substitute_teacher_id')   ?? ''),
                trim($this->input->post('substituteTeacherName') ?? $this->input->post('substitute_teacher_name') ?? ''),
                trim($this->input->post('class_section') ?? ''),
                trim($this->input->post('subject') ?? ''),
                $this->input->post('periods')
            );

            // ── Who has to be told ───────────────────────────────────────
            //
            // Both lists come from what saveSubstitute() actually PERSISTED,
            // not from the submitted form. The form was the wrong source twice
            // over: an assignment the save drops would still have produced a
            // notification, and an edit that moved a period to a different
            // teacher told nobody, leaving the first teacher believing they
            // were still covering it.
            $this->_notify_cover($res['substituteTeacherIds'] ?? [], $res['date'] ?? '',
                (string) ($res['id'] ?? ''), false);
            $this->_notify_cover($res['removedTeacherIds'] ?? [], $res['date'] ?? '',
                (string) ($res['id'] ?? ''), true);

            return $this->json_success($res);
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    public function update_substitute()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'manage_substitutes', 'Academic', 'manage');
        try {
            $res = $this->_substitute_svc()->updateSubstituteStatus(
                trim($this->input->post('id')     ?? ''),
                trim($this->input->post('status') ?? '')
            );

            // Cancelling takes somebody's duty away; reviving gives it back.
            // Completing changes nothing about who is expected where.
            if (!empty($res['coverWithdrawn']) || !empty($res['coverRestored'])) {
                $this->_notify_cover($res['affectedTeacherIds'] ?? [], $res['date'] ?? '',
                    (string) ($res['id'] ?? ''), !empty($res['coverWithdrawn']));
            }

            return $this->json_success($res);
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    public function delete_substitute()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'manage_substitutes', 'Academic', 'manage');
        try {
            $res = $this->_substitute_svc()->deleteSubstitute(
                trim($this->input->post('id') ?? '')
            );

            $this->_notify_cover($res['affectedTeacherIds'] ?? [], $res['date'] ?? '',
                (string) ($res['id'] ?? ''), !empty($res['coverWithdrawn']));

            return $this->json_success($res);
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    /**
     * Get a teacher's timetable schedule for substitute availability check
     */
    public function get_teacher_schedule()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_schedule', 'Academic', 'view');
        $teacherId = trim($this->input->post('teacher_id') ?? '');
        $date      = trim($this->input->post('date') ?? '');

        if (empty($teacherId)) return $this->json_error('Teacher ID required');

        $school  = $this->school_name;
        $session = $this->session_year;

        // Determine day of week from date
        $dayName = '';
        if ($date !== '' && strtotime($date)) {
            $dayName = date('l', strtotime($date)); // "Monday", "Tuesday", etc.
        }

        $classes  = $this->_get_session_classes();
        $schedule = [];

        // Firestore-first: check substitute assignments for this date.
        // A candidate is "busy" if they are already the SUBSTITUTE on any period
        // that day. The modern substitute model stores this inside assignments[]
        // (camelCase substituteTeacherId + periodNumber) with a single top-level
        // `date`; older records carried top-level substitute_teacher_id/periods/
        // date_end. Both shapes are handled below.
        $busyPeriods = [];
        $busyComplete = true;
        // ACAD-D16 — the last member of the ACAD-D12 family, in the two
        // places that ask "is this teacher already covering something?".
        //
        // sessionWhere() routes to firestoreQuery(), which returns [] for a
        // missing index, a network failure, 403 and 429 alike. None of them
        // throw, so the catch below — written precisely for this — could
        // never fire, and a failed read produced NO existing covers. Every
        // teacher already covering a class that period was then offered as
        // free, and the list said it was complete.
        //
        // The scan stays session-wide because a legacy cover can span days
        // (top-level date + date_end), and narrowing it to one date would
        // quietly stop seeing those. It is bounded and says so when the
        // bound is reached: this feeds a suggestion, and the save still
        // refuses a real clash, so it reports rather than denies.
        try {
            $fsSubs = $this->firebase->firestoreQueryOrFail('substitutes', [
                ['schoolId', '==', $school],
                ['session',  '==', $session],
            ], 'date', 'DESC', self::SUBSTITUTE_SCAN_LIMIT + 1);

            if (count($fsSubs) > self::SUBSTITUTE_SCAN_LIMIT) {
                $busyComplete = false;
                $fsSubs = array_slice($fsSubs, 0, self::SUBSTITUTE_SCAN_LIMIT);
                log_message('error', 'get_teacher_schedule: existing-cover scan hit '
                    . 'its limit (school=' . $school . ')');
            }
            foreach ($fsSubs as $doc) {
                $sub = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                if (($sub['status'] ?? '') === 'cancelled') continue;

                $subDate    = $sub['date'] ?? '';
                $subDateEnd = $sub['date_end'] ?? $subDate;
                if ($subDate === '' || $date < $subDate || $date > $subDateEnd) continue;

                // Modern model: per-period assignments[].
                if (is_array($sub['assignments'] ?? null)) {
                    foreach ($sub['assignments'] as $a) {
                        if (!is_array($a)) continue;
                        $aSubId = $a['substituteTeacherId'] ?? $a['substitute_teacher_id'] ?? '';
                        if ($aSubId === $teacherId && isset($a['periodNumber'])) {
                            $busyPeriods[] = (int) $a['periodNumber'];
                        }
                    }
                }

                // Legacy model: top-level substitute_teacher_id + periods[].
                $legacySubId = $sub['substitute_teacher_id'] ?? '';
                if ($legacySubId === $teacherId && is_array($sub['periods'] ?? null)) {
                    foreach ($sub['periods'] as $p) {
                        $busyPeriods[] = (int) $p;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Without these rows the teacher looks entirely free.
            $busyComplete = false;
            log_message('error', 'get_teacher_schedule: Firestore substitute query '
                . 'failed: ' . $e->getMessage());
        }
        $busyPeriods = array_values(array_unique($busyPeriods));

        // Firestore-only: timetable settings
        $maxPeriods = 6;
        try {
            $fsSettings = $this->firebase->firestoreGet('timetableSettings', "{$this->school_id}_{$session}");
            if (is_array($fsSettings)) $maxPeriods = (int) ($fsSettings['No_of_periods'] ?? 6);
        } catch (\Exception $e) {
            log_message('error', "get_teacher_schedule: timetableSettings Firestore read failed: " . $e->getMessage());
        }

        return $this->json_success([
            'teacher_id'   => $teacherId,
            'date'         => $date,
            'day'          => $dayName,
            'busy_periods' => array_values($busyPeriods),
            'max_periods'  => $maxPeriods,
            // Which periods are free is read NEGATIVELY by the caller, so it
            // has to be able to tell "free" from "we could not find out".
            'completeness' => [
                'complete' => $busyComplete,
                'cap'      => self::SUBSTITUTE_SCAN_LIMIT,
            ],
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       PERIOD SCHEDULING  (timings, periods, recesses, working days)
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * Get timetable settings + working days for the current session
     */
    public function get_timetable_settings()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_tt_settings', 'Academic', 'view');
        try {
            return $this->json_success($this->_timetable_svc()->getSettings());
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    /**
     * Save period scheduling (periods, times, recesses, working days)
     */
    public function save_timetable_settings()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'save_tt_settings', 'Academic', 'manage');
        try {
            $saved = $this->_timetable_svc()->saveSettings(
                trim($this->input->post('start_time')    ?? ''),
                trim($this->input->post('end_time')      ?? ''),
                (int)($this->input->post('no_of_periods') ?? 0),
                $this->input->post('recesses')     ?? '[]',
                $this->input->post('working_days') ?? '[]'
            );

            // ── THE SHAPE OF THE WEEK JUST CHANGED ───────────────────────
            //
            // Every unpublished proposal was built into the OLD week: its days
            // hold the old number of periods, at the old times, around the old
            // breaks. A proposal generated for six periods a day would keep a
            // "no problems" verdict after a move to eight, and publishing it
            // would hand teachers a week the school does not run.
            //
            // This is the approved Option B decision applied one level up: the
            // timetable is never rewritten, the verdict is invalidated, and the
            // operator is told to look again. Published versions are untouched —
            // they record what was in force under the old schedule, which stays
            // true.
            $saved['versionsMarkedStale'] = $this->_timetable_schedule_changed();

            return $this->json_success($saved);
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    /* ══════════════════════════════════════════════════════════════════════
       SECTION-LEVEL TIMETABLE ENDPOINTS
       Used by section_students.php for single class-section timetable view
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * Get timetable for a single class-section.
     * POST: class_name, section_name
     */
    public function get_section_timetable()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_timetable', 'Academic', 'view');

        $class   = $this->safe_path_segment(trim($this->input->post('class_name') ?? ''), 'class_name');
        $section = $this->safe_path_segment(trim($this->input->post('section_name') ?? ''), 'section_name');

        if (empty($class) || empty($section)) {
            return $this->json_error('Class and section are required');
        }

        // Firestore-first: query timetables for this section
        $data = null;
        $fsQueryOk = false;
        try {
            require_once APPPATH . 'libraries/Entity_firestore_sync.php';
            $cs = Entity_firestore_sync::normalizeClassSection($class, $section);
            $canonClass = $cs['className'] ?: $class;
            $canonSection = $cs['section'] ?: $section;
            $sectionKey = "{$canonClass}/{$canonSection}";

            $fsDocs = $this->firebase->firestoreQuery('timetables', [
                ['schoolId', '==', $this->school_name],
                ['sectionKey', '==', $sectionKey],
                ['session', '==', $this->session_year],
            ], null, 'ASC', 7);

            $fsQueryOk = true; // Query succeeded
            $data = [];
            foreach ($fsDocs as $doc) {
                $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                $day = $d['day'] ?? '';
                if ($day === '') continue;
                $dayData = [];
                foreach (($d['periods'] ?? []) as $p) {
                    $pi = ($p['periodNumber'] ?? 1) - 1;
                    $dayData[$pi] = [
                        'subject'      => $p['subject'] ?? '',
                        'teacher'      => $p['teacher'] ?? '',
                        'teacher_id'   => $p['teacherId'] ?? '',
                        'startTime'    => $p['startTime'] ?? '',
                        'endTime'      => $p['endTime'] ?? '',
                        'type'         => $p['type'] ?? 'class',
                    ];
                }
                $data[$day] = $dayData;
            }
        } catch (\Exception $e) {
            log_message('error', "get_section_timetable Firestore query failed: " . $e->getMessage());
        }

        if ($data === null) $data = [];

        return $this->json_success($data);
    }

    /**
     * OPTION B — subject assignments changed, so affected timetable work needs
     * re-checking. It is NOT rewritten.
     *
     * This replaces reconcileClassFromMatrix(), which used to reach into the
     * live `timetables` collection and rewrite the teacher on every period whose
     * subject matched the new assignment. Under the canonical architecture that
     * is not allowed twice over: a published timetable is immutable, and a
     * draft or candidate is somebody's unfinished work that must not change
     * under them.
     *
     * What happens instead: the draft and candidate versions that actually
     * contain this class have their verdict marked stale, with the reason
     * recorded, so publishing refuses until someone checks them again. Published
     * versions are not touched at all.
     *
     * Failure here is logged and swallowed on purpose: the assignments HAVE been
     * saved by the time this runs, and failing the whole request would tell the
     * operator their assignment change did not happen when it did. The
     * consequence of a missed mark is a verdict that looks fresher than it is,
     * which the publish gate still re-checks against the content fingerprint.
     */
    /**
     * Mark every unpublished proposal stale after a Period Scheduling change.
     *
     * Mirrors _timetable_assignments_changed(): the settings are already saved
     * by the time this runs, so a failure here must not fail the save. It is
     * logged and reported as empty rather than pretending nothing needed
     * marking — the difference matters when an operator is deciding whether to
     * trust an old verdict.
     */
    private function _timetable_schedule_changed(): array
    {
        try {
            require_once APPPATH . 'libraries/Timetable_plan_store.php';
            $store = (new Timetable_plan_store())->init(
                $this->firebase, (string) $this->school_id, (string) $this->session_year,
                (string) ($this->session->userdata('admin_id') ?? ''));

            return $store->markAllVersionsStaleForSchedule()['marked'];
        } catch (\Throwable $e) {
            log_message('error', 'Academic: could not mark timetable versions stale after '
                . 'a Period Scheduling change: ' . $e->getMessage()
                . ' — a proposal may still advertise a verdict built for the old week.');
            return [];
        }
    }

    private function _timetable_assignments_changed(string $classKey, string $sectionKey = ''): array
    {
        $out = [
            // Kept for the response contract. They are now always zero, and
            // truthfully so: nothing is rewritten any more.
            'docsUpdated' => 0, 'periodsChanged' => 0, 'skippedManual' => 0,
            'versionsMarkedStale' => [], 'reason' => '', 'policy' => 'mark_stale',
        ];

        try {
            require_once APPPATH . 'libraries/Timetable_plan_store.php';
            $store = (new Timetable_plan_store())->init(
                $this->firebase, (string) $this->school_id, (string) $this->session_year,
                (string) ($this->session->userdata('admin_id') ?? ''));

            $r = $store->markVersionsStaleForClass($classKey, $sectionKey);
            $out['versionsMarkedStale'] = $r['marked'];
            $out['reason']              = $r['reason'];
        } catch (\Throwable $e) {
            log_message('error', 'Academic: could not mark timetable versions stale after '
                . 'an assignment change (class=' . $classKey . '): ' . $e->getMessage());
        }

        return $out;
    }
    /**
     * Save full timetable for a single class-section (bulk write).
     * POST: class_name, section_name, timetable (JSON string)
     */
    public function save_section_timetable()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'edit_timetable', 'Academic', 'manage');
        try {
            return $this->json_success($this->_timetable_svc()->saveSectionTimetable(
                $this->safe_path_segment(trim($this->input->post('class_name')   ?? ''), 'class_name'),
                $this->safe_path_segment(trim($this->input->post('section_name') ?? ''), 'section_name'),
                $this->input->post('timetable')
            ));
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    /**
     * Get subjects for a given class (for timetable subject picker).
     * POST: class_name (e.g. "Class 8th")
     * Returns: {class_subjects: [{name,code}], all_subjects: [{name,code}]}
     */
    public function get_class_subjects()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'academic_data', 'Academic', 'view');

        $className = trim($this->input->post('class_name') ?? '');

        if (!$className) {
            return $this->json_success(['class_subjects' => [], 'all_subjects' => []]);
        }

        // Extract numeric class key or text key (Nursery/LKG/UKG)
        $requestedKey = null;
        if (preg_match('/(\d+)/', $className, $m)) {
            $requestedKey = (int)$m[1];
        } elseif (preg_match('/(Nursery|LKG|UKG)/i', $className, $m2)) {
            $requestedKey = ucfirst(strtolower($m2[1]));
        }

        // Firestore-only: read subjects collection
        $subjectMap = $this->_getSubjectsFromFirestore();

        $classSubjects = [];
        $allSubjects   = [];

        foreach ($subjectMap as $classNum => $subs) {
            foreach ($subs as $sub) {
                $name = $sub['name'] ?? '';
                $code = $sub['code'] ?? '';
                if (empty($name)) continue;

                $allSubjects[$name] = ['name' => $name, 'code' => $code];

                // Match requested class
                if ($requestedKey !== null) {
                    if (is_int($requestedKey) && is_numeric($classNum) && (int)$classNum === $requestedKey) {
                        $classSubjects[$name] = ['name' => $name, 'code' => $code];
                    } elseif (is_string($requestedKey) && strcasecmp($classNum, $requestedKey) === 0) {
                        $classSubjects[$name] = ['name' => $name, 'code' => $code];
                    }
                }
            }
        }

        return $this->json_success([
            'class_subjects' => array_values($classSubjects),
            'all_subjects'   => array_values($allSubjects),
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       PRIVATE HELPERS
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * Read subjects from Firestore 'subjects' collection, return in RTDB-compatible
     * format: [classNum => [{code, name}]]
     */
    private function _getSubjectsFromFirestore(): array
    {
        $subjects = [];
        try {
            $fsDocs = $this->fs->schoolWhere('subjects', []);
            if (is_array($fsDocs)) {
                foreach ($fsDocs as $doc) {
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $classKey = $d['classKey'] ?? '';
                    $code     = $d['subjectCode'] ?? $d['code'] ?? '';
                    $name     = $d['name'] ?? $d['subject_name'] ?? (string) $code;
                    if ($classKey === '' || $code === '') continue;
                    $subjects[$classKey][] = [
                        'code' => (string) $code,
                        'name' => $name,
                    ];
                }
            }
        } catch (\Exception $e) {
            log_message('error', "Academic::_getSubjectsFromFirestore failed: " . $e->getMessage());
        }
        return $subjects;
    }

    /**
     * Read subjects from Firestore with full catalog fields (code, name, category, stream).
     * Returns: [classNum => [{code, name, category, stream}]]
     */
    private function _getSubjectCatalogFromFirestore(): array
    {
        $catalog = [];
        try {
            $fsDocs = $this->fs->schoolWhere('subjects', []);
            if (is_array($fsDocs)) {
                foreach ($fsDocs as $doc) {
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $classKey = $d['classKey'] ?? '';
                    $code     = $d['subjectCode'] ?? $d['code'] ?? '';
                    if ($classKey === '' || $code === '') continue;
                    $catalog[$classKey][] = [
                        'code'     => (string) $code,
                        'name'     => $d['name'] ?? $d['subject_name'] ?? (string) $code,
                        'category' => $d['category'] ?? 'Core',
                        'stream'   => $d['stream'] ?? '',
                    ];
                }
            }
        } catch (\Exception $e) {
            log_message('error', "Academic::_getSubjectCatalogFromFirestore failed: " . $e->getMessage());
        }
        return $catalog;
    }

    /**
     * Read Config/Classes from Firestore school doc's `classes` field.
     * Returns array of class entries (same shape as old RTDB Config/Classes).
     */
    private function _getConfigClassesFromFirestore(): array
    {
        try {
            $schoolDoc = $this->fs->get('schools', $this->school_id);
            if (is_array($schoolDoc) && isset($schoolDoc['classes']) && is_array($schoolDoc['classes'])) {
                return $schoolDoc['classes'];
            }
        } catch (\Exception $e) {
            log_message('error', "Academic::_getConfigClassesFromFirestore failed: " . $e->getMessage());
        }
        return [];
    }

    /**
     * Convert a class key (e.g. "Class 9th") to a Firebase-safe path segment.
     * Config/Classes stores keys like "Class 9th" which are valid Firebase keys.
     */
    private function _class_to_firebase_key(string $key): string
    {
        $key = trim($key);
        // Firebase keys cannot contain . $ # [ ] /
        return str_replace(['.', '$', '#', '[', ']', '/'], '_', $key);
    }

    /**
     * Normalize a Config/Classes raw key (e.g. "8", "LKG") to its canonical
     * display label (e.g. "Class 8th", "Class LKG").
     *
     * Reason: Config/Classes stores `key="8"` and `label="Class 8th"`. The admin
     * panel UI sends the raw key when saving assignments, which means
     * subjectAssignments documents end up with `className="8"`. The Teacher
     * Android app, the RTDB session structure (`Schools/{s}/{year}/Class 8th`),
     * the sections collection (`className="Class 8th"`), and the timetable
     * paths all expect the prefixed label form. Without this normalization,
     * teacher queries against the assignments collection silently miss every
     * record.
     *
     * Lookup is from the same Config/Classes node the dropdown loads from, so
     * the result is always in sync with what the user sees.
     *
     * Returns the input unchanged if no match is found (legacy data, custom
     * class names, etc.) so this is safe to apply to existing flows.
     */
    private function _normalize_class_label(string $rawKey): string
    {
        $rawKey = trim($rawKey);
        if ($rawKey === '') return '';
        // If it already has the "Class " prefix, leave it alone — already canonical.
        if (stripos($rawKey, 'Class ') === 0) return $rawKey;

        $configClasses = $this->_getConfigClassesFromFirestore();
        if (is_array($configClasses)) {
            foreach ($configClasses as $cls) {
                if (!is_array($cls)) continue;
                if (!empty($cls['deleted'])) continue;
                if (($cls['key'] ?? null) === $rawKey) {
                    return (string) ($cls['label'] ?? $rawKey);
                }
            }
        }
        return $rawKey;
    }

    /**
     * Normalize a section key from raw form ("A", "Section A", "") to the
     * canonical "Section A" form. Empty stays empty (class-wide assignment).
     */
    private function _normalize_section_label(string $rawSec): string
    {
        $rawSec = trim($rawSec);
        if ($rawSec === '') return '';
        if (stripos($rawSec, 'Section ') === 0) return $rawSec;
        return 'Section ' . $rawSec;
    }

    // ══════════════════════════════════════════════════════════════════════
    //  AUTO-GENERATE TIMETABLE
    // ══════════════════════════════════════════════════════════════════════

    /**
     * POST /academic/auto_generate_timetable
     *
     * Reads all subject assignments + period settings, then generates a
     * complete weekly timetable for all sections using a constraint
     * satisfaction (greedy + spread) algorithm.
     *
     * POST params:
     *   preview=1  → return generated timetable without saving (default)
     *   confirm=1  → save to Firestore
     *   class_key  → optional, scope to one class only
     *
     * Constraints respected:
     *   1. No teacher double-booking (same teacher, same day+period across sections)
     *   2. Subject spread (distribute periods_per_week evenly across days)
     *   3. No same subject twice in one day per section (soft — allows if forced)
     *   4. Hard subjects (Math, Science, English) prefer morning periods
     *   5. Respects periods_per_day from TimetableSettings
     */
    public function auto_generate_timetable()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'edit_timetable', 'Academic', 'manage');
        try {
            return $this->json_success($this->_timetable_svc()->autoGenerate(
                ($this->input->post('confirm') === '1'),
                ($this->input->post('force_overwrite') === '1'),
                trim($this->input->post('class_key') ?? '')
            ));
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SMART SUBSTITUTE SUGGESTION
    // ══════════════════════════════════════════════════════════════════════

    /**
     * POST /academic/suggest_substitutes
     *
     * Given an absent teacher + date + periods, suggests the best available
     * substitute teachers ranked by:
     *   1. Can teach the same subject (teaching_subjects match)
     *   2. Free during those periods (no timetable conflict)
     *   3. Lowest current workload (fewest total periods/week)
     *
     * POST: absent_teacher_id, date (Y-m-d), periods (comma-separated period numbers)
     * Returns: ranked list of suggestions with availability info
     */
    /**
     * POST /academic/get_absent_teachers
     *
     * Returns teachers who are marked absent/leave for a given date.
     * Reads from:
     *   1. Approved leave requests (leaveApplications) covering the date
     *   2. Staff attendance from Firestore staffAttendance collection
     *
     * POST: date (Y-m-d)
     * Returns: {absent_teachers: [{id, name, reason, leave_type}], all_teachers: [{id, name}]}
     */
    public function get_absent_teachers()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'manage_substitutes', 'Academic', 'manage');
        try {
            // Moved into Substitute_service so it can be TESTED. The three
            // reads behind it (staff, approved leave, the day's attendance)
            // each sat in an empty catch over a helper that returns [] when it
            // fails, so a failed read arrived here as a shorter list of absent
            // teachers — and a teacher missing from that list is a class
            // nobody arranges cover for.
            $res = $this->_substitute_svc()->getAbsentTeachers(
                trim($this->input->post('date') ?? ''));

            // Legacy snake_case keys kept: the panel reads these.
            return $this->json_success([
                'date'             => $res['date'],
                'absent_teachers'  => $res['absentTeachers'],
                'all_teachers'     => $res['allTeachers'],
                'absentTeachers'   => $res['absentTeachers'],
                'allTeachers'      => $res['allTeachers'],
            ]);
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    /**
     * POST /academic/get_cover_policy — the school's own cover rules.
     * Readable by anyone who may see substitutes: the limits explain the
     * refusals they will meet.
     */
    public function get_cover_policy()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_substitutes', 'Academic', 'view');
        try {
            return $this->json_success($this->_substitute_svc()->getPolicy());
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    /** POST /academic/save_cover_policy — setting the rules is a manage act. */
    public function save_cover_policy()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal'], 'manage_substitutes', 'Academic', 'manage');
        try {
            return $this->json_success($this->_substitute_svc()->savePolicy([
                'maxCoverPeriodsPerDay'  => (int) ($this->input->post('max_per_day')  ?? 0),
                'maxCoverPeriodsPerWeek' => (int) ($this->input->post('max_per_week') ?? 0),
                'requireSubjectMatch'    => $this->input->post('require_subject_match') === '1',
                'teachingStaffOnly'      => $this->input->post('teaching_staff_only') === '1',
            ]));
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    /**
     * POST /academic/keep_cover — keep a cover the timetable moved under.
     *
     * One of three things an administrator can do about a drifted cover; the
     * other two, cancelling and re-arranging, already exist. Which is right
     * depends on the class and the day, so this records a decision rather
     * than making one.
     */
    public function keep_cover()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'manage_substitutes', 'Academic', 'manage');
        try {
            return $this->json_success($this->_substitute_svc()->acknowledgeDrift(
                trim($this->input->post('id') ?? '')
            ));
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    /**
     * POST /academic/cover_history — the history of ONE cover.
     *
     * Answers "who cancelled this?" from the platform's existing Academic
     * audit trail. Readable at view level: the people who have to work
     * around a change are the ones who need to know who made it.
     */
    public function cover_history()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'view_substitutes', 'Academic', 'view');
        try {
            return $this->json_success($this->_substitute_svc()->coverHistory(
                trim($this->input->post('id') ?? '')
            ));
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    /**
     * POST /academic/cover_log — who has been carrying the cover.
     *
     * The STPCD expects the burden to be shared equitably over the year and
     * names the cover log as what makes that possible.
     */
    public function cover_log()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'view_substitutes', 'Academic', 'view');
        try {
            return $this->json_success($this->_substitute_svc()->coverLog(
                trim($this->input->post('from') ?? ''),
                trim($this->input->post('to')   ?? '')
            ));
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    /**
     * POST /academic/get_absent_teacher_schedule
     *
     * Returns the absent teacher's timetable for a specific date,
     * plus per-period substitute suggestions.
     *
     * POST: teacher_id, date (Y-m-d)
     * Returns: {day, periods: [{periodNumber, subject, className, section, startTime, endTime,
     *           suggestions: [{teacherId, name, subjectMatch, free}]}]}
     */
    public function get_absent_teacher_schedule()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'manage_substitutes', 'Academic', 'manage');

        $teacherId = trim($this->input->post('teacher_id') ?? '');
        $dateStr   = trim($this->input->post('date') ?? '');

        if ($teacherId === '' || $dateStr === '') {
            return $this->json_error('Teacher and date are required');
        }

        $dt = new \DateTime($dateStr);
        $dayOfWeek = $dt->format('l'); // "Monday", "Tuesday" etc.
        $school  = $this->school_name;
        $session = $this->session_year;

        // Get the absent teacher's periods on this day from timetable
        // ACAD-D13 — this list decides which periods get a substitute at all.
        // A period missing from it is a class that quietly ends up uncovered, so
        // neither a truncated scan nor a failed read may pass as "this teacher
        // has nothing scheduled". Read cap+1 to detect saturation, and read
        // fail-loud because firestoreQuery() returns [] for a failed read exactly
        // as it does for a teacher with a free day.
        $absentPeriods = [];
        $complete      = true;
        try {
            $ttDocs = $this->firebase->firestoreQueryOrFail('timetables', [
                ['schoolId', '==', $school],
                ['session', '==', $session],
                ['day', '==', $dayOfWeek],
            ], null, 'ASC', self::SUBSTITUTE_SCAN_LIMIT + 1);

            if (count($ttDocs) > self::SUBSTITUTE_SCAN_LIMIT) {
                $complete = false;
                $ttDocs   = array_slice($ttDocs, 0, self::SUBSTITUTE_SCAN_LIMIT);
                log_message('error', 'get_absent_teacher_schedule: scan truncated at '
                    . self::SUBSTITUTE_SCAN_LIMIT . ' (school=' . $school . ' day=' . $dayOfWeek
                    . ') — periods needing cover may be missing.');
            }

            foreach ($ttDocs as $doc) {
                $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                foreach (($d['periods'] ?? []) as $p) {
                    if (($p['teacherId'] ?? '') === $teacherId && ($p['subject'] ?? '') !== '') {
                        $absentPeriods[] = [
                            'periodNumber' => (int) ($p['periodNumber'] ?? 0),
                            'subject'      => $p['subject'] ?? '',
                            'className'    => $d['className'] ?? '',
                            'section'      => $d['section'] ?? '',
                            'startTime'    => $p['startTime'] ?? '',
                            'endTime'      => $p['endTime'] ?? '',
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            $complete = false;
            log_message('error', 'get_absent_teacher_schedule: timetable read failed '
                . '(school=' . $school . ' day=' . $dayOfWeek . '): ' . $e->getMessage());
        }

        usort($absentPeriods, fn($a, $b) => $a['periodNumber'] - $b['periodNumber']);

        if (empty($absentPeriods)) {
            return $this->json_success([
                'day'     => $dayOfWeek,
                'date'    => $dateStr,
                'periods' => [],
                // Never assert "no classes" off a read we could not complete.
                'message' => $complete
                    ? 'This teacher has no classes on ' . $dayOfWeek
                    : 'The timetable could not be read in full, so this teacher\'s '
                      . $dayOfWeek . ' classes cannot be listed. Do NOT treat this as '
                      . 'an empty day — reload before arranging cover.',
                'completeness' => ['complete' => $complete, 'cap' => self::SUBSTITUTE_SCAN_LIMIT],
                'calendar'     => $this->_student_calendar_for_date($dateStr),
            ]);
        }

        // Build busy map for ALL teachers on this day
        $busyMap = []; // [teacherId][periodNum] => true
        try {
            foreach ($ttDocs as $doc) {
                $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                foreach (($d['periods'] ?? []) as $p) {
                    $tid = $p['teacherId'] ?? '';
                    $pn  = (int) ($p['periodNumber'] ?? 0);
                    if ($tid !== '' && ($p['subject'] ?? '') !== '') {
                        $busyMap[$tid][$pn] = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'get_absent_teacher_schedule: busy-map build failed: '
                . $e->getMessage());
        }

        // Also mark teachers busy from EXISTING substitute assignments on this
        // date. A teacher already covering another absentee that period is NOT
        // free — without this they get wrongly suggested as available a second
        // time. Handles both the modern assignments[] model and legacy docs.
        // ACAD-D16 — the last member of the ACAD-D12 family, in the two
        // places that ask "is this teacher already covering something?".
        //
        // sessionWhere() routes to firestoreQuery(), which returns [] for a
        // missing index, a network failure, 403 and 429 alike. None of them
        // throw, so the catch below — written precisely for this — could
        // never fire, and a failed read produced NO existing covers. Every
        // teacher already covering a class that period was then offered as
        // free, and the list said it was complete.
        //
        // The scan stays session-wide because a legacy cover can span days
        // (top-level date + date_end), and narrowing it to one date would
        // quietly stop seeing those. It is bounded and says so when the
        // bound is reached: this feeds a suggestion, and the save still
        // refuses a real clash, so it reports rather than denies.
        try {
            $fsSubs = $this->firebase->firestoreQueryOrFail('substitutes', [
                ['schoolId', '==', $school],
                ['session',  '==', $session],
            ], 'date', 'DESC', self::SUBSTITUTE_SCAN_LIMIT + 1);

            if (count($fsSubs) > self::SUBSTITUTE_SCAN_LIMIT) {
                $complete = false;
                $fsSubs = array_slice($fsSubs, 0, self::SUBSTITUTE_SCAN_LIMIT);
                log_message('error', 'get_absent_teacher_schedule: existing-cover '
                    . 'scan hit its limit (school=' . $school . ' date=' . $dateStr . ')');
            }

            foreach ($fsSubs as $doc) {
                $sub = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                if (($sub['status'] ?? '') === 'cancelled') continue;

                $subDate    = $sub['date'] ?? '';
                $subDateEnd = $sub['date_end'] ?? $subDate;
                if ($subDate === '' || $dateStr < $subDate || $dateStr > $subDateEnd) continue;

                foreach (($sub['assignments'] ?? []) as $a) {
                    if (!is_array($a)) continue;
                    $aSubId = $a['substituteTeacherId'] ?? $a['substitute_teacher_id'] ?? '';
                    if ($aSubId !== '' && isset($a['periodNumber'])) {
                        $busyMap[$aSubId][(int) $a['periodNumber']] = true;
                    }
                }

                $legacySubId = $sub['substitute_teacher_id'] ?? '';
                if ($legacySubId !== '' && is_array($sub['periods'] ?? null)) {
                    foreach ($sub['periods'] as $p) {
                        $busyMap[$legacySubId][(int) $p] = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            // ACAD-D13: without these rows a teacher already covering another
            // absentee this period looks free and gets offered as a substitute.
            $complete = false;
            log_message('error', 'get_absent_teacher_schedule: existing-cover read '
                . 'failed (school=' . $school . ' date=' . $dateStr . '): ' . $e->getMessage());
        }

        // Get all active teachers with capabilities
        $allStaff = [];
        try {
            $fsDocs = $this->fs->schoolWhere('staff', [['status', '==', 'Active']]);
            foreach ($fsDocs as $doc) {
                $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                $tid = $d['staffId'] ?? $d['userId'] ?? '';
                if ($tid === '' || $tid === $teacherId) continue;
                $subs = $d['teaching_subjects'] ?? [];
                if (!is_array($subs)) $subs = [];
                $allStaff[$tid] = [
                    'id'       => $tid,
                    'name'     => $d['Name'] ?? $d['name'] ?? $tid,
                    'subjects' => $subs,
                ];
            }
        } catch (\Throwable $e) {
            // ACAD-D13: with no staff the suggestion dropdown renders EMPTY, which
            // reads as "nobody is available" rather than "we could not look".
            $complete = false;
            log_message('error', 'get_absent_teacher_schedule: staff read failed (school='
                . $school . '): ' . $e->getMessage());
        }

        // ── HOW MUCH EACH CANDIDATE IS ALREADY CARRYING ──────────────────
        //
        // Untis puts this number in front of the person choosing rather than
        // enforcing a ceiling, and the STPCD expects the burden to be shared
        // equitably over the year. Neither works if the person arranging cover
        // cannot see who has been covering all week.
        //
        // Degrades rather than denies: without these numbers the list is still
        // usable and the save still enforces whatever limits the school set.
        $coverLoad = [];
        $coverPolicy = ['maxCoverPeriodsPerDay' => 0, 'maxCoverPeriodsPerWeek' => 0];
        try {
            $svc = $this->_substitute_svc();
            $coverLoad   = $svc->coverLoadOn($dateStr);
            $coverPolicy = $svc->getPolicy();
        } catch (\Throwable $e) {
            $complete = false;
            log_message('error', 'get_absent_teacher_schedule: cover load unavailable '
                . '(school=' . $school . ' date=' . $dateStr . '): ' . $e->getMessage());
        }

        // For each absent period, find available substitutes
        foreach ($absentPeriods as &$period) {
            $pn = $period['periodNumber'];
            $neededSubject = $period['subject'];
            $suggestions = [];

            foreach ($allStaff as $tid => $teacher) {
                $isFree = !isset($busyMap[$tid][$pn]);
                if (!$isFree) continue;

                $subjectMatch = false;
                foreach ($teacher['subjects'] as $ts) {
                    if (strcasecmp($ts, $neededSubject) === 0) {
                        $subjectMatch = true;
                        break;
                    }
                }

                $suggestions[] = [
                    'teacherId'    => $tid,
                    'name'         => $teacher['name'],
                    'subjectMatch' => $subjectMatch,
                    'free'         => true,
                    // What this person is already carrying, so the load can be
                    // spread deliberately rather than by whoever sorts first.
                    'coverToday'   => (int) ($coverLoad[$tid]['day']  ?? 0),
                    'coverThisWeek'=> (int) ($coverLoad[$tid]['week'] ?? 0),
                ];
            }

            // Subject specialist first, then whoever has covered least this
            // week, then by name so the order is stable. Sorting by name alone
            // meant the same few teachers were offered first every time, which
            // is precisely the pattern a cover log exists to prevent.
            usort($suggestions, function ($a, $b) {
                if ($a['subjectMatch'] !== $b['subjectMatch']) return $b['subjectMatch'] - $a['subjectMatch'];
                if ($a['coverThisWeek'] !== $b['coverThisWeek']) return $a['coverThisWeek'] - $b['coverThisWeek'];
                if ($a['coverToday']    !== $b['coverToday'])    return $a['coverToday']    - $b['coverToday'];
                return strcasecmp($a['name'], $b['name']);
            });

            $period['suggestions'] = array_slice($suggestions, 0, 5);
        }
        unset($period);

        return $this->json_success([
            'day'     => $dayOfWeek,
            'date'    => $dateStr,
            'periods' => $absentPeriods,
            // ACAD-D13: the admin arranges cover from this list. Saying it is
            // partial is the difference between a known gap and a class nobody
            // turns up to.
            'completeness' => ['complete' => $complete, 'cap' => self::SUBSTITUTE_SCAN_LIMIT],
            // The screen shows these beside the load so a limit is visible
            // BEFORE somebody arranges cover that will be refused.
            'coverPolicy'  => [
                'maxCoverPeriodsPerDay'  => (int) ($coverPolicy['maxCoverPeriodsPerDay']  ?? 0),
                'maxCoverPeriodsPerWeek' => (int) ($coverPolicy['maxCoverPeriodsPerWeek'] ?? 0),
            ],
            // CAL-P3 — advisory. See _student_calendar_for_date().
            'calendar'     => $this->_student_calendar_for_date($dateStr),
        ]);
    }

    /**
     * CAL-P3 — is regular instruction scheduled for students on this date?
     *
     * THE PRINCIPLE THIS IMPLEMENTS. The published timetable is the school's
     * NORMAL schedule; the student calendar says whether that schedule is
     * operational on a particular date. Those are different questions, and the
     * data model already keeps them apart: `timetables` documents are keyed
     * `{school}_{session}_{sectionKey}_{WEEKDAY}`, so there is no per-date
     * timetable document to suppress. Deleting one would remove that subject
     * from EVERY Monday, not from one holiday. The operational answer therefore
     * has to be composed at the point a DATE is turned into a weekday — here.
     *
     * WHY `instruction` AND NOT `attendance`. Arranging cover is about whether a
     * lesson runs, not about whether a register is taken. On an exam-only day
     * `instruction` is false while `attendance` is true, and there is no
     * ordinary period to cover; on a special working day or a half day
     * `instruction` is true and cover IS needed. `instruction` is the dimension
     * that matches the question this screen asks.
     *
     * ADVISORY, DELIBERATELY. Nothing here refuses to list periods or blocks a
     * substitute being saved. Refusing would change the Substitute programme's
     * write semantics, which this programme does not own — so the calendar is
     * reported and the decision to act on it is left to the operator and to the
     * owner of that workflow.
     *
     * NULL is a real answer: an unconfigured tenant resolves to null rather than
     * to an invented true or false, and a read failure is reported as such so
     * the screen can say "could not check" instead of implying "all clear".
     */
    private function _student_calendar_for_date(string $dateStr): array
    {
        $out = ['instruction' => null, 'reason' => null,
                'source' => 'unavailable', 'message' => ''];

        if ($dateStr === '') return $out;

        try {
            $ts = strtotime($dateStr);
            if ($ts === false) return $out;
            $y = (int) date('Y', $ts); $m = (int) date('n', $ts); $d = (int) date('j', $ts);
            $iso = date('Y-m-d', $ts);

            $hol = $swd = [];
            try {
                $this->load->library('holiday_service');
                $this->holiday_service->init(
                    $this->fs, (string) $this->school_id, (string) $this->session_year);
                // STUDENT audience. A staff-only closure must never read as
                // "no lesson to cover" — it is precisely when cover IS needed.
                $hol = $this->holiday_service->holidays_in_month($y, $m, 'students');
                $swd = $this->holiday_service->special_working_days_in_month($y, $m, 'students');
            } catch (\Throwable $e) {
                log_message('error', 'Academic::_student_calendar_for_date holidays: ' . $e->getMessage());
                return $out;
            }

            $this->load->library('student_calendar_reader');
            $this->student_calendar_reader->init(
                $this->fs, (string) $this->school_id, (string) $this->session_year);

            $r = $this->student_calendar_reader->resolveDate($iso, [
                'holiday'           => isset($hol[$d]),
                'specialWorkingDay' => isset($swd[$d]),
            ]);

            $out['instruction'] = $r['instruction'];
            $out['reason']      = $r['reason'];
            $out['source']      = $r['source'];

            if ($r['instruction'] === false) {
                $name = isset($hol[$d]) ? (string) $hol[$d] : '';
                $out['message'] = $name !== ''
                    ? "No classes are scheduled on this date — {$name}. Cover is not normally needed."
                    : 'No classes are scheduled on this date. Cover is not normally needed.';
            } elseif ($r['instruction'] === null) {
                $out['message'] = 'The school calendar could not be checked for this date.';
            }
        } catch (\Throwable $e) {
            log_message('error', 'Academic::_student_calendar_for_date failed: ' . $e->getMessage());
        }

        return $out;
    }

    public function suggest_substitutes()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'manage_substitutes', 'Academic', 'manage');

        $absentTid = trim($this->input->post('absent_teacher_id') ?? '');
        $dateStr   = trim($this->input->post('date') ?? '');
        $periodsRaw = trim($this->input->post('periods') ?? '');

        if ($absentTid === '' || $dateStr === '') {
            return $this->json_error('Absent teacher and date are required');
        }

        $requestedPeriods = array_filter(array_map('intval', explode(',', $periodsRaw)));
        if (empty($requestedPeriods)) {
            return $this->json_error('At least one period is required');
        }

        $school  = $this->school_name;
        $session = $this->session_year;

        // Parse date → day of week
        $dt = \DateTime::createFromFormat('Y-m-d', $dateStr);
        if (!$dt) return $this->json_error('Invalid date format');
        $dayOfWeek = $dt->format('l'); // "Monday", "Tuesday", etc.

        // ── 1. Find what the absent teacher teaches on this day ──
        // ACAD-D15 — the last member of the ACAD-D12 family.
        //
        // $busyMap below is built from these docs and then read NEGATIVELY:
        //     if (!isset($busyMap[$tid][$pn])) { ...offer this teacher... }
        // so an EMPTY map offers EVERY teacher, including ones already teaching
        // their own class that period. This read had no try/catch at all, and
        // firestoreQuery() returns [] on failure rather than throwing, so a
        // failed read silently produced a full slate of bogus suggestions.
        //
        // Substitute_service enforces the actual booking (ACAD-D12), so a bad
        // suggestion cannot by itself create a clash — it would be offered and
        // then refused at save. Completeness problem, not a safety one: report,
        // do not deny.
        //
        // SEVERITY IS LOW TODAY, and the reason is worth recording: this endpoint
        // is routed (routes.php: academic/suggest_substitutes) but has NO caller
        // anywhere — not in the admin views, not in the Teacher app, not in the
        // Parent app. Nobody currently sees these suggestions. It is hardened
        // here so it cannot be wired up later and inherit the family defect, not
        // because it is misleading anyone now. The `completeness` key it returns
        // has, accordingly, no UI consumer yet either.
        $absentSchedule = []; // [periodNum => {subject, className, section}]
        $complete = true;
        $ttDocs   = [];
        try {
            $ttDocs = $this->firebase->firestoreQueryOrFail('timetables', [
                ['schoolId', '==', $school],
                ['session', '==', $session],
                ['day', '==', $dayOfWeek],
            ], null, 'ASC', self::SUBSTITUTE_SUGGEST_LIMIT + 1);

            if (count($ttDocs) > self::SUBSTITUTE_SUGGEST_LIMIT) {
                $complete = false;
                $ttDocs   = array_slice($ttDocs, 0, self::SUBSTITUTE_SUGGEST_LIMIT);
                log_message('error', 'suggest_substitutes: scan truncated at '
                    . self::SUBSTITUTE_SUGGEST_LIMIT . ' (school=' . $school
                    . ' day=' . $dayOfWeek . ') — some teachers will look free when they are not.');
            }
        } catch (\Throwable $e) {
            $complete = false;
            log_message('error', 'suggest_substitutes: timetable read failed (school='
                . $school . ' day=' . $dayOfWeek . '): ' . $e->getMessage());
        }

        foreach ($ttDocs as $doc) {
            $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
            $periods = $d['periods'] ?? [];
            if (!is_array($periods)) continue;
            foreach ($periods as $p) {
                if (($p['teacherId'] ?? '') === $absentTid) {
                    $pn = (int) ($p['periodNumber'] ?? 0);
                    $absentSchedule[$pn] = [
                        'subject'   => $p['subject'] ?? '',
                        'className' => $d['className'] ?? '',
                        'section'   => $d['section'] ?? '',
                    ];
                }
            }
        }

        // ── 2. Build teacher busy map for this day ──
        $busyMap = []; // [teacherId][periodNum] => true
        foreach ($ttDocs as $doc) {
            $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
            $periods = $d['periods'] ?? [];
            if (!is_array($periods)) continue;
            foreach ($periods as $p) {
                $tid = $p['teacherId'] ?? '';
                $pn  = (int) ($p['periodNumber'] ?? 0);
                if ($tid !== '' && ($p['subject'] ?? '') !== '') {
                    $busyMap[$tid][$pn] = true;
                }
            }
        }

        // ── 3. Get all teachers with their capabilities ──
        $allStaff = $this->fs->schoolWhere('staff', [['status', '==', 'Active']]);
        $teachers = [];
        foreach ($allStaff as $doc) {
            $d = $doc['data'] ?? $doc;
            $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
            $tid = $d['staffId'] ?? $d['userId'] ?? ($d['id'] ?? '');
            if ($tid === '' || $tid === $absentTid) continue;
            $subs = $d['teaching_subjects'] ?? [];
            if (!is_array($subs)) $subs = [];
            $teachers[$tid] = [
                'name'     => $d['Name'] ?? $d['name'] ?? '',
                'subjects' => $subs,
                'id'       => $tid,
            ];
        }

        // ── 4. Count existing workload per teacher ──
        $this->load->library('subject_assignment_service', null, 'sas');
        $this->sas->init($this->fs, $this->firebase, $this->school_id, $school, $session);
        $workloadMap = []; // [teacherId] => total periods/week
        foreach ($teachers as $tid => $t) {
            $asgn = $this->sas->getAssignmentsForTeacher($tid);
            $total = 0;
            foreach ($asgn as $a) $total += (int) ($a['periodsPerWeek'] ?? 0);
            $workloadMap[$tid] = $total;
        }

        // ── 5. Score each teacher for each requested period ──
        $suggestions = []; // [{teacherId, name, score, available_periods, subject_match, workload}]

        foreach ($teachers as $tid => $t) {
            $freePeriods = [];
            $subjectMatches = 0;
            foreach ($requestedPeriods as $pn) {
                if (!isset($busyMap[$tid][$pn])) {
                    $freePeriods[] = $pn;
                    // Check subject match for this period
                    $neededSubject = $absentSchedule[$pn]['subject'] ?? '';
                    if ($neededSubject !== '') {
                        foreach ($t['subjects'] as $ts) {
                            if (strcasecmp($ts, $neededSubject) === 0) {
                                $subjectMatches++;
                                break;
                            }
                        }
                    }
                }
            }

            if (empty($freePeriods)) continue; // teacher busy on all requested periods

            $workload = $workloadMap[$tid] ?? 0;
            // Score: subject matches (×100) + free period coverage (×10) - workload
            $score = ($subjectMatches * 100) + (count($freePeriods) * 10) - $workload;

            $suggestions[] = [
                'teacherId'        => $tid,
                'name'             => $t['name'],
                'score'            => $score,
                'availablePeriods' => $freePeriods,
                'totalFree'        => count($freePeriods),
                'subjectMatch'     => $subjectMatches > 0,
                'workload'         => $workload,
                'subjects'         => $t['subjects'],
            ];
        }

        // Sort by score descending (best first)
        usort($suggestions, function ($a, $b) { return $b['score'] - $a['score']; });

        // Return top 5
        $top = array_slice($suggestions, 0, 5);

        return $this->json_success([
            'date'           => $dateStr,
            'day'            => $dayOfWeek,
            'absentTeacher'  => $absentTid,
            'absentSchedule' => $absentSchedule,
            'suggestions'    => $top,
            // ACAD-D15: an availability list built from a partial read will
            // offer teachers who are not actually free.
            'completeness'   => ['complete' => $complete, 'cap' => self::SUBSTITUTE_SUGGEST_LIMIT],
        ]);
    }

    // _get_session_classes() is inherited from MY_Controller (protected)
}
