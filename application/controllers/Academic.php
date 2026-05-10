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
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'academic_view');

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
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'academic_data');
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
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'academic_data');

        $teachers = [];

        // ── Firestore-first: read staff collection scoped to school ──
        try {
            $staffDocs = $this->fs->schoolWhere('staff', []);
            foreach ($staffDocs as $s) {
                $d = $s['data'] ?? $s;
                $data = $s['data'] ?? [];
                $staffId = $data['staffId'] ?? $data['User ID'] ?? $d['id'] ?? '';
                if ($staffId === '') continue;

                // Filter to teachers only — has ROLE_TEACHER role OR teaching_subjects array
                $roles = $data['staff_roles'] ?? [];
                $isTeacher = false;
                if (is_array($roles)) {
                    foreach ($roles as $r) {
                        if ($r === 'ROLE_TEACHER') { $isTeacher = true; break; }
                    }
                }
                $teachingSubjects = $data['teaching_subjects'] ?? [];
                if (!$isTeacher && is_array($teachingSubjects) && !empty($teachingSubjects)) {
                    $isTeacher = true;
                }
                // Also check Position (legacy)
                $position = $data['Position'] ?? $data['position'] ?? '';
                if (!$isTeacher && in_array($position, ['Teacher','Senior Teacher','Head of Department','Lab Assistant','Sports Coach'], true)) {
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
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_subject_assignments');

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
        } catch (\Exception $e) {}
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
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'save_subject_assignments');

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
        } catch (\Exception $e) {}
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

        return $this->json_success([
            'class'    => $fbKey,
            'section'  => $sectionKey,
            'count'    => $result['saved'],
            'warnings' => $allWarnings,
        ]);
    }

    /**
     * Get subject assignments for a specific class+section (Phase 4).
     * POST: class_key, section_key
     */
    public function get_subject_assignments_for_section()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_subject_assignments');

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
     * Get list of teachers eligible to teach a subject (filtered dropdown).
     * POST: subject_code, subject_name (optional)
     */
    public function get_eligible_teachers()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'view_subject_assignments');

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
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'copy_subject_assignments');

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

        // Get source assignments from Firestore-first
        $sourceAssignments = $this->sas->getAssignmentsForClass($fromKey, '');
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
        $count = $result['count'] ?? count($subjects);

        return $this->json_success(['from' => $fromKey, 'to' => $toKey, 'count' => $count]);
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
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_curriculum');
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
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'save_curriculum');
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
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'update_curriculum');
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
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'delete_curriculum');
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
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_lesson_plan');
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
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_lesson_plan');
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
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'edit_lesson_plan');
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

    public function get_monthly_plan()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_lesson_plan');
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
        $this->_require_role(self::ANALYTICS_ADMIN_ROLES, 'view_analytics');
        try {
            return $this->json_success(['rows' => $this->_analytics_svc()->getSyllabusProgress()]);
        } catch (Service_exception $e) { return $this->json_error($e->getMessage()); }
    }

    public function analytics_daily_monitoring()
    {
        $this->_require_role(self::ANALYTICS_ADMIN_ROLES, 'view_analytics');
        try {
            return $this->json_success($this->_analytics_svc()->getDailyMonitoring(
                trim((string)($this->input->post('date') ?: date('Y-m-d')))
            ));
        } catch (Service_exception $e) { return $this->json_error($e->getMessage()); }
    }

    public function analytics_delays()
    {
        $this->_require_role(self::ANALYTICS_ADMIN_ROLES, 'view_analytics');
        try {
            return $this->json_success($this->_analytics_svc()->getDelays());
        } catch (Service_exception $e) { return $this->json_error($e->getMessage()); }
    }

    public function analytics_subject_progress()
    {
        $this->_require_role(self::ANALYTICS_ADMIN_ROLES, 'view_analytics');
        try {
            return $this->json_success(['rows' => $this->_analytics_svc()->getSubjectProgress(
                trim((string)($this->input->post('class_section') ?? ''))
            )]);
        } catch (Service_exception $e) { return $this->json_error($e->getMessage()); }
    }

    public function parent_daily_lessons()
    {
        $this->_require_role(self::ANALYTICS_PARENT_ROLES, 'view_parent_planner');
        try {
            return $this->json_success($this->_analytics_svc()->getParentDailyLessons(
                trim((string)($this->input->post('class_section') ?? '')),
                trim((string)($this->input->post('date')          ?: date('Y-m-d')))
            ));
        } catch (Service_exception $e) { return $this->json_error($e->getMessage()); }
    }

    public function parent_subject_progress()
    {
        $this->_require_role(self::ANALYTICS_PARENT_ROLES, 'view_parent_planner');
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
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_calendar');
        try {
            return $this->json_success($this->_calendar_svc()->getEvents(
                trim($this->input->post('month') ?? '')
            ));
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    public function save_event()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'manage_calendar');
        try {
            return $this->json_success($this->_calendar_svc()->saveEvent(
                trim($this->input->post('id')          ?? ''),
                trim($this->input->post('title')       ?? ''),
                trim($this->input->post('type')        ?? 'event'),
                trim($this->input->post('startDate')   ?? $this->input->post('start_date') ?? ''),
                trim($this->input->post('endDate')     ?? $this->input->post('end_date')   ?? ''),
                trim($this->input->post('description') ?? ''),
                $this->input->post('visibleTo')
            ));
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    public function delete_event()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'manage_calendar');
        try {
            return $this->json_success($this->_calendar_svc()->deleteEvent(
                trim($this->input->post('id') ?? '')
            ));
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    /* ══════════════════════════════════════════════════════════════════════
       MASTER TIMETABLE
    ══════════════════════════════════════════════════════════════════════ */

    public function get_master_timetable()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_timetable');
        try {
            return $this->json_success($this->_timetable_svc()->getMasterTimetable(
                $this->_get_session_classes()
            ));
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    public function save_period()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'edit_timetable');
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
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_timetable');
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
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_substitutes');
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

    public function save_substitute()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'manage_substitutes');
        try {
            // NOTE: class_section + subject are LEGACY-FALLBACK fields only —
            // used when the new `assignments[]` array is empty. Pass raw (do NOT
            // call safe_path_segment unconditionally; it 400s on empty input
            // and would block the modern path where assignments[] is supplied).
            // The service runs sanitization only when actually using the legacy path.
            return $this->json_success($this->_substitute_svc()->saveSubstitute(
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
            ));
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    public function update_substitute()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'manage_substitutes');
        try {
            return $this->json_success($this->_substitute_svc()->updateSubstituteStatus(
                trim($this->input->post('id')     ?? ''),
                trim($this->input->post('status') ?? '')
            ));
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    public function delete_substitute()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'manage_substitutes');
        try {
            return $this->json_success($this->_substitute_svc()->deleteSubstitute(
                trim($this->input->post('id') ?? '')
            ));
        } catch (Service_exception $e) {
            return $this->json_error($e->getMessage());
        }
    }

    /**
     * Get a teacher's timetable schedule for substitute availability check
     */
    public function get_teacher_schedule()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_schedule');
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

        // Firestore-first: check substitute assignments for this date
        $busyPeriods = [];
        try {
            $fsSubs = $this->fs->sessionWhere('substitutes', []);
            foreach ($fsSubs as $doc) {
                $sub = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                if (($sub['status'] ?? '') === 'cancelled') continue;
                $subDate    = $sub['date'] ?? '';
                $subDateEnd = $sub['date_end'] ?? $subDate;
                if ($date < $subDate || $date > $subDateEnd) continue;
                if (($sub['substitute_teacher_id'] ?? '') === $teacherId) {
                    $busyPeriods = array_merge($busyPeriods, is_array($sub['periods'] ?? null) ? $sub['periods'] : []);
                }
            }
        } catch (\Exception $e) {
            log_message('error', "get_teacher_schedule: Firestore substitute query failed: " . $e->getMessage());
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
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_tt_settings');
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
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'save_tt_settings');
        try {
            return $this->json_success($this->_timetable_svc()->saveSettings(
                trim($this->input->post('start_time')    ?? ''),
                trim($this->input->post('end_time')      ?? ''),
                (int)($this->input->post('no_of_periods') ?? 0),
                $this->input->post('recesses')     ?? '[]',
                $this->input->post('working_days') ?? '[]'
            ));
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
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_timetable');

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
     * Save full timetable for a single class-section (bulk write).
     * POST: class_name, section_name, timetable (JSON string)
     */
    public function save_section_timetable()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'edit_timetable');
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
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'academic_data');

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
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'edit_timetable');
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
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'manage_substitutes');

        $dateStr = trim($this->input->post('date') ?? '');
        if ($dateStr === '' || !strtotime($dateStr)) {
            return $this->json_error('Valid date is required');
        }

        $school  = $this->school_name;
        $session = $this->session_year;
        $dt = new \DateTime($dateStr);
        $dayNum = (int) $dt->format('j');
        $monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
                       7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
        $attKey = $monthNames[(int)$dt->format('n')] . ' ' . $dt->format('Y');

        // Get all staff
        $allStaff = [];
        try {
            $fsDocs = $this->fs->schoolWhere('staff', [['status', '==', 'Active']]);
            foreach ($fsDocs as $doc) {
                $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                $tid = $d['staffId'] ?? $d['userId'] ?? '';
                if ($tid === '') continue;
                $allStaff[$tid] = [
                    'id'   => $tid,
                    'name' => $d['Name'] ?? $d['name'] ?? $tid,
                ];
            }
        } catch (\Exception $e) {}

        $absentTeachers = [];

        // Method 1: Check approved leave requests covering this date (Firestore-first)
        try {
            $leaveReqs = $this->fs->schoolWhere('leaveApplications', [
                ['status', '==', 'approved'],
                ['applicantType', '==', 'staff'],
            ]);
            foreach ($leaveReqs as $doc) {
                $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                $from = $d['fromDate'] ?? $d['from_date'] ?? '';
                $to   = $d['toDate'] ?? $d['to_date'] ?? $from;
                $sid  = $d['staffId'] ?? $d['applicantId'] ?? '';
                if ($sid === '' || $from === '') continue;
                if ($dateStr >= $from && $dateStr <= $to) {
                    $absentTeachers[$sid] = [
                        'id'         => $sid,
                        'name'       => $allStaff[$sid]['name'] ?? $d['applicantName'] ?? $sid,
                        'reason'     => 'Leave: ' . ($d['leaveType'] ?? $d['leave_type'] ?? 'General'),
                        'leave_type' => $d['leaveType'] ?? $d['leave_type'] ?? '',
                    ];
                }
            }
        } catch (\Exception $e) {}

        // Method 2: Check staffAttendance Firestore collection for A or L marks
        $fsAttFound = false;
        try {
            $attDocs = $this->fs->schoolWhere('staffAttendance', [
                ['date', '==', $dateStr],
            ]);
            if (!empty($attDocs)) {
                $fsAttFound = true;
                foreach ($attDocs as $doc) {
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $sid  = $d['staffId'] ?? '';
                    $mark = strtoupper($d['status'] ?? '');
                    if ($sid === '' || ($mark !== 'L' && $mark !== 'A')) continue;
                    if (!isset($absentTeachers[$sid])) {
                        $absentTeachers[$sid] = [
                            'id'         => $sid,
                            'name'       => $allStaff[$sid]['name'] ?? $d['staffName'] ?? $sid,
                            'reason'     => $mark === 'L' ? 'On Leave' : 'Absent',
                            'leave_type' => $mark === 'L' ? 'leave' : 'absent',
                        ];
                    }
                }
            }
        } catch (\Exception $e) {}

        // Build all_teachers list (for manual override — admin can still pick any teacher)
        $allList = array_values($allStaff);
        usort($allList, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        return $this->json_success([
            'date'             => $dateStr,
            'absent_teachers'  => array_values($absentTeachers),
            'all_teachers'     => $allList,
        ]);
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
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'manage_substitutes');

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
        $absentPeriods = [];
        try {
            $ttDocs = $this->firebase->firestoreQuery('timetables', [
                ['schoolId', '==', $school],
                ['session', '==', $session],
                ['day', '==', $dayOfWeek],
            ], null, 'ASC', 100);

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
        } catch (\Exception $e) {}

        usort($absentPeriods, fn($a, $b) => $a['periodNumber'] - $b['periodNumber']);

        if (empty($absentPeriods)) {
            return $this->json_success([
                'day'     => $dayOfWeek,
                'date'    => $dateStr,
                'periods' => [],
                'message' => 'This teacher has no classes on ' . $dayOfWeek,
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
        } catch (\Exception $e) {}

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
        } catch (\Exception $e) {}

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
                ];
            }

            // Sort: subject match first, then alphabetical
            usort($suggestions, function ($a, $b) {
                if ($a['subjectMatch'] !== $b['subjectMatch']) return $b['subjectMatch'] - $a['subjectMatch'];
                return strcasecmp($a['name'], $b['name']);
            });

            $period['suggestions'] = array_slice($suggestions, 0, 5);
        }
        unset($period);

        return $this->json_success([
            'day'     => $dayOfWeek,
            'date'    => $dateStr,
            'periods' => $absentPeriods,
        ]);
    }

    public function suggest_substitutes()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'manage_substitutes');

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
        $absentSchedule = []; // [periodNum => {subject, className, section}]
        $ttDocs = $this->firebase->firestoreQuery('timetables', [
            ['schoolId', '==', $school],
            ['session', '==', $session],
            ['day', '==', $dayOfWeek],
        ], null, 'ASC', 200);

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
        ]);
    }

    // _get_session_classes() is inherited from MY_Controller (protected)
}
