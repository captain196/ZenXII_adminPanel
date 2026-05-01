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

    public function get_curriculum()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_curriculum');

        $classSection = trim($this->input->post('class_section') ?? '');
        $subject      = trim($this->input->post('subject') ?? '');

        if (empty($classSection) || empty($subject)) {
            return $this->json_error('Class and subject required');
        }

        $classSection = $this->safe_path_segment($classSection, 'class_section');
        $subject      = $this->safe_path_segment($subject, 'subject');

        // Firestore-only
        $topics = [];
        try {
            $fsDoc = $this->firebase->firestoreGet('curriculum', $this->_currDocId($classSection, $subject));
            if (is_array($fsDoc) && isset($fsDoc['topics'])) {
                $topics = array_values($fsDoc['topics']);
            }
        } catch (\Exception $e) {
            log_message('error', "get_curriculum Firestore read failed: " . $e->getMessage());
        }

        return $this->json_success([
            'topics'        => $topics,
            'class_section' => $classSection,
            'subject'       => $subject,
        ]);
    }

    public function save_curriculum()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'save_curriculum');

        $classSection = trim($this->input->post('class_section') ?? '');
        $subject      = trim($this->input->post('subject') ?? '');
        $topicsRaw    = $this->input->post('topics');

        if (empty($classSection) || empty($subject)) {
            return $this->json_error('Class and subject required');
        }

        $classSection = $this->safe_path_segment($classSection, 'class_section');
        $subject      = $this->safe_path_segment($subject, 'subject');

        $topics = is_string($topicsRaw) ? json_decode($topicsRaw, true) : $topicsRaw;
        if (!is_array($topics)) $topics = [];

        $clean = [];
        foreach ($topics as $i => $t) {
            if (!is_array($t) || empty(trim($t['title'] ?? ''))) continue;
            $clean[] = [
                'title'          => trim($t['title']),
                'chapter'        => trim($t['chapter'] ?? ''),
                'est_periods'    => max(0, (int)($t['est_periods'] ?? 0)),
                'status'         => in_array($t['status'] ?? '', ['not_started', 'in_progress', 'completed'])
                                        ? $t['status'] : 'not_started',
                'completed_date' => ($t['status'] ?? '') === 'completed' ? ($t['completed_date'] ?? date('Y-m-d')) : '',
                'sort_order'     => $i,
            ];
        }

        // Firestore-only write
        $fsDocId = $this->_currDocId($classSection, $subject);
        $this->firebase->firestoreSet('curriculum', $fsDocId, [
            'schoolId'      => $this->school_id,
            'session'       => $this->session_year,
            'classSection'  => $classSection,
            'subject'       => $subject,
            'topics'        => $clean,
            'updatedAt'     => date('c'),
        ]);

        return $this->json_success(['topics' => $clean]);
    }

    public function update_topic_status()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'update_curriculum');
        $classSection = $this->safe_path_segment(trim($this->input->post('class_section') ?? ''), 'class_section');
        $subject      = $this->safe_path_segment(trim($this->input->post('subject') ?? ''), 'subject');
        $index        = (int)($this->input->post('index') ?? -1);
        $status       = trim($this->input->post('status') ?? '');

        if (empty($classSection) || empty($subject) || $index < 0) {
            return $this->json_error('Invalid parameters');
        }
        if (!in_array($status, ['not_started', 'in_progress', 'completed'])) {
            return $this->json_error('Invalid status');
        }

        // Read from Firestore, update topic, write back
        $fsDocId = $this->_currDocId($classSection, $subject);
        $fsDoc = null;
        try { $fsDoc = $this->firebase->firestoreGet('curriculum', $fsDocId); } catch (\Exception $e) {}

        $topics = [];
        if (is_array($fsDoc) && isset($fsDoc['topics'])) {
            $topics = array_values($fsDoc['topics']);
        }

        if (!isset($topics[$index])) {
            return $this->json_error('Topic not found');
        }

        $topics[$index]['status'] = $status;
        $topics[$index]['completed_date'] = ($status === 'completed') ? date('Y-m-d') : '';

        // Write back to Firestore
        $this->firebase->firestoreSet('curriculum', $fsDocId, [
            'schoolId'     => $this->school_id,
            'session'      => $this->session_year,
            'classSection' => $classSection,
            'subject'      => $subject,
            'topics'       => $topics,
            'updatedAt'    => date('c'),
        ]);

        return $this->json_success(['index' => $index, 'status' => $status]);
    }

    public function delete_topic()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'delete_curriculum');
        $classSection = $this->safe_path_segment(trim($this->input->post('class_section') ?? ''), 'class_section');
        $subject      = $this->safe_path_segment(trim($this->input->post('subject') ?? ''), 'subject');
        $index        = (int)($this->input->post('index') ?? -1);

        if (empty($classSection) || empty($subject) || $index < 0) {
            return $this->json_error('Invalid parameters');
        }

        // Read from Firestore
        $fsDocId = $this->_currDocId($classSection, $subject);
        $fsDoc = null;
        try { $fsDoc = $this->firebase->firestoreGet('curriculum', $fsDocId); } catch (\Exception $e) {}

        $topics = [];
        if (is_array($fsDoc) && isset($fsDoc['topics'])) {
            $topics = array_values($fsDoc['topics']);
        }

        if (!isset($topics[$index])) {
            return $this->json_error('Topic not found');
        }

        array_splice($topics, $index, 1);
        foreach ($topics as $i => &$t) { $t['sort_order'] = $i; }
        unset($t);

        // Write back to Firestore
        $this->firebase->firestoreSet('curriculum', $fsDocId, [
            'schoolId'     => $this->school_id,
            'session'      => $this->session_year,
            'classSection' => $classSection,
            'subject'      => $subject,
            'topics'       => $topics,
            'updatedAt'    => date('c'),
        ]);

        return $this->json_success(['topics' => $topics]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       ACADEMIC CALENDAR
    ══════════════════════════════════════════════════════════════════════ */

    public function get_calendar_events()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_calendar');
        $month = trim($this->input->post('month') ?? '');

        // Firestore-first
        $events = [];
        try {
            $fsDocs = $this->fs->sessionWhere('calendarEvents', []);
            if (is_array($fsDocs) && !empty($fsDocs)) {
                foreach ($fsDocs as $doc) {
                    $d = $doc['data'] ?? $doc;
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $d['id'] = $d['id'] ?? '';
                    if ($month !== '') {
                        $evStart = $d['start_date'] ?? '';
                        $evEnd   = $d['end_date'] ?? $evStart;
                        $monthStart = $month . '-01';
                        $monthEnd   = date('Y-m-t', strtotime($monthStart));
                        if ($evEnd < $monthStart || $evStart > $monthEnd) continue;
                    }
                    $events[] = $d;
                }
            }
        } catch (\Exception $e) {}

        usort($events, fn($a, $b) => strcmp($a['start_date'] ?? '', $b['start_date'] ?? ''));
        return $this->json_success(['events' => $events]);
    }

    public function save_event()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'manage_calendar');
        $id         = trim($this->input->post('id') ?? '');
        $title      = trim($this->input->post('title') ?? '');
        $type       = trim($this->input->post('type') ?? 'event');
        $startDate  = trim($this->input->post('start_date') ?? '');
        $endDate    = trim($this->input->post('end_date') ?? '') ?: $startDate;
        $desc       = trim($this->input->post('description') ?? '');

        if (empty($title) || empty($startDate)) {
            return $this->json_error('Title and start date are required');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !strtotime($startDate)) {
            return $this->json_error('Invalid start date format');
        }
        if ($endDate !== $startDate && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) || !strtotime($endDate))) {
            return $this->json_error('Invalid end date format');
        }
        if ($endDate < $startDate) {
            return $this->json_error('End date cannot be before start date');
        }

        $validTypes = ['holiday', 'exam', 'meeting', 'event', 'activity'];
        if (!in_array($type, $validTypes)) $type = 'event';

        $data = [
            'title'       => $title,
            'type'        => $type,
            'start_date'  => $startDate,
            'end_date'    => $endDate,
            'description' => $desc,
            'updatedAt'   => date('c'),
        ];

        // Firestore-first write
        if ($id === '') {
            $id = 'EVT_' . uniqid();
            $data['createdAt'] = date('c');
        }
        $fsDocId = "{$this->school_id}_{$id}";
        $fsData = array_merge($data, [
            'schoolId' => $this->school_id,
            'session'  => $this->session_year,
        ]);
        $this->firebase->firestoreSet('calendarEvents', $fsDocId, $fsData, true);

        return $this->json_success(['id' => $id, 'event' => $data]);
    }

    public function delete_event()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'manage_calendar');
        $id = trim($this->input->post('id') ?? '');
        if (empty($id)) return $this->json_error('Event ID required');

        // Firestore-only delete
        $fsDocId = "{$this->school_id}_{$id}";
        try { $this->firebase->firestoreDelete('calendarEvents', $fsDocId); } catch (\Exception $e) {}

        return $this->json_success([]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       MASTER TIMETABLE
    ══════════════════════════════════════════════════════════════════════ */

    public function get_master_timetable()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_timetable');
        $school  = $this->school_name;
        $session = $this->session_year;

        // 1. Timetable settings — Firestore-only
        $settings = [];
        try {
            $fsSettings = $this->firebase->firestoreGet('timetableSettings', "{$this->school_id}_{$session}");
            if (is_array($fsSettings)) $settings = $fsSettings;
        } catch (\Exception $e) {}
        if (!is_array($settings)) $settings = [];

        // Normalize recesses
        $recesses = [];
        if (isset($settings['Recesses']) && is_array($settings['Recesses'])) {
            foreach ($settings['Recesses'] as $r) {
                if (is_array($r) && isset($r['after_period'], $r['duration'])) {
                    $recesses[] = ['after_period' => (int)$r['after_period'], 'duration' => (int)$r['duration']];
                }
            }
        }

        // Working days for conflict scanning scope
        $defaultDays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        $workingDays = isset($settings['Working_days']) && is_array($settings['Working_days'])
            ? $settings['Working_days'] : $defaultDays;

        $settingsClean = [
            'start_time'       => $settings['Start_time'] ?? '9:00AM',
            'end_time'         => $settings['End_time'] ?? '3:00PM',
            'no_of_periods'    => (int)($settings['No_of_periods'] ?? 6),
            'length_of_period' => (float)($settings['Length_of_period'] ?? 45),
            'recesses'         => $recesses,
            'working_days'     => $workingDays,
        ];

        // 2. All class-section timetables — Firestore-first
        $classes = $this->_get_session_classes();
        $timetables = [];
        $firestoreQueryOk = false;

        try {
            $fsDocs = $this->firebase->firestoreQuery('timetables', [
                ['schoolId', '==', $school],
                ['session', '==', $session],
            ], null, 'ASC', 500);

            $firestoreQueryOk = true; // Query succeeded (even if 0 results)

            // Group by label → day → periods
            foreach ($fsDocs as $doc) {
                $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                $cn  = $d['className'] ?? '';
                $sec = $d['section'] ?? '';
                $day = $d['day'] ?? '';
                if ($cn === '' || $sec === '' || $day === '') continue;
                $label = "{$cn} ({$sec})";
                if (!isset($timetables[$label])) $timetables[$label] = [];
                $periods = $d['periods'] ?? [];
                $dayData = [];
                foreach ($periods as $p) {
                    $pi = ($p['periodNumber'] ?? 1) - 1;
                    $dayData[$pi] = [
                        'subject'    => $p['subject'] ?? '',
                        'teacher'    => $p['teacher'] ?? '',
                        'teacher_id' => $p['teacherId'] ?? '',
                        'teacherId'  => $p['teacherId'] ?? '',
                        'startTime'  => $p['startTime'] ?? '',
                        'endTime'    => $p['endTime'] ?? '',
                        'type'       => $p['type'] ?? 'class',
                    ];
                }
                $timetables[$label][$day] = $dayData;
            }
        } catch (\Exception $e) {
            $firestoreQueryOk = false;
        }

        // 3. Subject assignments — Firestore-only via service
        $assignments = [];
        try {
            $this->load->library('subject_assignment_service', null, 'sas');
            $this->sas->init($this->fs, $this->firebase, $this->school_id, $this->school_name, $this->session_year);
            $allByClass = $this->sas->getAllForSession();
            foreach ($allByClass as $className => $docs) {
                $fbKey = $this->_class_to_firebase_key($className);
                $assignments[$fbKey] = [];
                foreach ($docs as $info) {
                    $code = $info['subjectCode'] ?? '';
                    $assignments[$fbKey][$code] = [
                        'teacher_id'   => $info['teacherId'] ?? '',
                        'teacher_name' => $info['teacherName'] ?? '',
                        'periods_week' => (int)($info['periodsPerWeek'] ?? 0),
                        'subject_name' => $info['subjectName'] ?? '',
                    ];
                }
            }
        } catch (\Exception $e) {
            log_message('error', "get_master_timetable: subject assignments Firestore query failed: " . $e->getMessage());
        }

        return $this->json_success([
            'settings'            => $settingsClean,
            'timetables'          => $timetables,
            'classes'             => $classes,
            'subject_assignments' => $assignments,
        ]);
    }

    public function save_period()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'edit_timetable');
        $classKey   = $this->safe_path_segment(trim($this->input->post('class_key') ?? ''), 'class_key');
        $section    = $this->safe_path_segment(trim($this->input->post('section') ?? ''), 'section');
        $day        = trim($this->input->post('day') ?? '');
        $periodIdx  = (int)($this->input->post('period_index') ?? -1);
        $subject    = trim($this->input->post('subject') ?? '');
        $teacherId  = trim($this->input->post('teacher_id') ?? '');
        $teacherName = trim($this->input->post('teacher_name') ?? '');

        $validDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        if (empty($classKey) || empty($section) || !in_array($day, $validDays) || $periodIdx < 0) {
            return $this->json_error('Missing or invalid parameters');
        }

        $school  = $this->school_name;
        $session = $this->session_year;

        // ── CONFLICT CHECKS (before any write) — Firestore-only ──

        $warnings = [];

        // Pre-fetch all timetable docs for this day from Firestore (single query, used for all checks)
        $allDayDocs = [];
        try {
            $allDayDocs = $this->firebase->firestoreQuery('timetables', [
                ['schoolId', '==', $school],
                ['session', '==', $session],
                ['day', '==', $day],
            ], null, 'ASC', 200);
        } catch (\Exception $e) {}

        // Build a lookup: [sectionKey] => [periodIdx => {subject, teacherId, ...}]
        $dayTimetables = [];
        foreach ($allDayDocs as $doc) {
            $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
            $cn  = $d['className'] ?? '';
            $sec2 = $d['section'] ?? '';
            $sk = "{$cn}|{$sec2}";
            $dayTimetables[$sk] = [];
            foreach (($d['periods'] ?? []) as $p) {
                $pi = ($p['periodNumber'] ?? 1) - 1;
                $dayTimetables[$sk][$pi] = [
                    'subject'    => $p['subject'] ?? '',
                    'teacher_id' => $p['teacherId'] ?? '',
                    'teacher_name' => $p['teacher'] ?? '',
                ];
            }
        }

        // Also pre-fetch all timetable docs for this section across ALL days (for weekly period-limit)
        $allSectionDocs = [];
        try {
            require_once APPPATH . 'libraries/Entity_firestore_sync.php';
            $csNorm = Entity_firestore_sync::normalizeClassSection($classKey, "Section {$section}");
            $normSectionKey = ($csNorm['className'] ?: $classKey) . '/' . ($csNorm['section'] ?: "Section {$section}");
            $allSectionDocs = $this->firebase->firestoreQuery('timetables', [
                ['schoolId', '==', $school],
                ['session', '==', $session],
                ['sectionKey', '==', $normSectionKey],
            ], null, 'ASC', 7);
        } catch (\Exception $e) {}

        if ($subject !== '') {
            // 1. Teacher conflict: same teacher in same day+period in another class (HARD BLOCK)
            if ($teacherId !== '') {
                foreach ($dayTimetables as $sk => $periods) {
                    // Skip own section
                    $parts = explode('|', $sk);
                    $skClass = $parts[0] ?? '';
                    $skSec   = $parts[1] ?? '';
                    // Match by checking if this is the same section being edited
                    if ($this->_class_to_firebase_key($skClass) === $this->_class_to_firebase_key($classKey)
                        && (str_replace('Section ', '', $skSec) === $section || $skSec === "Section {$section}")) continue;

                    $otherCell = $periods[$periodIdx] ?? [];
                    $otherTeacherId = $otherCell['teacher_id'] ?? '';
                    if ($otherTeacherId !== '' && $otherTeacherId === $teacherId) {
                        $label = "{$skClass} ({$skSec})";
                        return $this->json_error(
                            "Teacher conflict: {$teacherName} is already assigned to {$label} on {$day} period " . ($periodIdx + 1) . ". Remove that assignment first."
                        );
                    }
                }
            }

            // 2. Duplicate subject in same class on same day (WARNING only — double periods are valid)
            // Find this section's day data from the pre-fetched docs
            $dayTt = [];
            foreach ($dayTimetables as $sk => $periods) {
                $parts = explode('|', $sk);
                $skClass = $parts[0] ?? '';
                $skSec   = $parts[1] ?? '';
                if ($this->_class_to_firebase_key($skClass) === $this->_class_to_firebase_key($classKey)
                    && (str_replace('Section ', '', $skSec) === $section || $skSec === "Section {$section}")) {
                    $dayTt = $periods;
                    break;
                }
            }

            $subjectCountToday = 0;
            foreach ($dayTt as $i => $cell) {
                if ((int)$i === $periodIdx) continue; // skip the cell being edited
                $cellSub = is_array($cell) ? ($cell['subject'] ?? '') : (string)$cell;
                if ($cellSub !== '' && strcasecmp($cellSub, $subject) === 0) {
                    $subjectCountToday++;
                }
            }
            if ($subjectCountToday > 0) {
                $warnings[] = "{$subject} already appears {$subjectCountToday} other time(s) on {$day}";
            }

            // ── 2.5 NEW (Phase 4): Validate teacher-subject assignment exists ──
            // The teacher MUST have an assignment for this subject in this class
            // (either at section level or class-wide). This is the source-of-truth check.
            if ($teacherId !== '') {
                try {
                    $this->load->library('subject_assignment_service', null, 'sas');
                    $this->sas->init(
                        $this->fs,
                        $this->firebase,
                        $this->school_id,
                        $this->school_name,
                        $this->session_year
                    );

                    $fbClassKeyForSas = $this->_class_to_firebase_key($classKey);
                    $sectionKeyForSas = 'Section ' . $section;

                    // Look up assignments for this section first, then fall back to class-wide
                    $sectionAssignments = $this->sas->getAssignmentsForClass($fbClassKeyForSas, $sectionKeyForSas);
                    $classWideAssignments = $this->sas->getAssignmentsForClass($fbClassKeyForSas, '');

                    $merged = array_merge($sectionAssignments, $classWideAssignments);
                    $matchFound = false;
                    foreach ($merged as $a) {
                        $aSubject = $a['subjectName'] ?? '';
                        $aCode    = $a['subjectCode'] ?? '';
                        $aTeacher = $a['teacherId'] ?? '';
                        if ($aTeacher === $teacherId &&
                            (strcasecmp($aSubject, $subject) === 0 || strcasecmp($aCode, $subject) === 0)) {
                            $matchFound = true;
                            break;
                        }
                    }
                    if (!$matchFound) {
                        $warnings[] = "No subject assignment found for {$teacherName} → {$subject} in {$classKey}. Add it in Academic Planner → Subject Assignments first.";
                    }
                } catch (\Exception $e) {
                    log_message('error', "save_period subjectAssignments check failed: " . $e->getMessage());
                }
            }

            // 3. Weekly period-limit check from Subject_Assignments (Firestore-only)
            $limit = 0;
            try {
                if (!isset($this->sas)) {
                    $this->load->library('subject_assignment_service', null, 'sas');
                    $this->sas->init($this->fs, $this->firebase, $this->school_id, $this->school_name, $this->session_year);
                }
                $fbClassKey = $this->_class_to_firebase_key($classKey);
                $sectionKeyForSas = 'Section ' . $section;
                $sectionAssigns = $this->sas->getAssignmentsForClass($fbClassKey, $sectionKeyForSas);
                $classWideAssigns = $this->sas->getAssignmentsForClass($fbClassKey, '');
                $mergedAssigns = array_merge($sectionAssigns, $classWideAssigns);
                foreach ($mergedAssigns as $a) {
                    $aName = $a['subjectName'] ?? '';
                    $aCode = $a['subjectCode'] ?? '';
                    if (strcasecmp($aName, $subject) === 0 || strcasecmp($aCode, $subject) === 0) {
                        $limit = (int)($a['periodsPerWeek'] ?? 0);
                        break;
                    }
                }
            } catch (\Exception $e) {}

            if ($limit > 0) {
                // Count this subject across all days for this class-section (Firestore)
                $weekCount = 0;
                foreach ($allSectionDocs as $doc) {
                    $d2 = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $docDay = $d2['day'] ?? '';
                    foreach (($d2['periods'] ?? []) as $p) {
                        $pi2 = ($p['periodNumber'] ?? 1) - 1;
                        // Skip the cell being edited (it will be replaced)
                        if ($docDay === $day && $pi2 === $periodIdx) continue;
                        $cellSub = $p['subject'] ?? '';
                        if ($cellSub !== '' && strcasecmp($cellSub, $subject) === 0) {
                            $weekCount++;
                        }
                    }
                }
                $newTotal = $weekCount + 1; // +1 for the period being saved
                if ($newTotal > $limit) {
                    $warnings[] = "{$subject} will have {$newTotal} periods/week (limit: {$limit})";
                }
            }
        }

        // ── WRITE — Firestore-only ──

        // Use normalizeClassSection (already done above in pre-fetch, reuse if available)
        if (!isset($csNorm)) {
            require_once APPPATH . 'libraries/Entity_firestore_sync.php';
            $csNorm = Entity_firestore_sync::normalizeClassSection($classKey, "Section {$section}");
        }
        $canonClass   = $csNorm['className'] ?: $classKey;
        $canonSection = $csNorm['section'] ?: "Section {$section}";
        $sectionKeyWrite = "{$canonClass}/{$canonSection}";
        $safeKey = str_replace('/', '_', $sectionKeyWrite);
        $fsDocId = "{$school}_{$session}_{$safeKey}_{$day}";

        // Read existing Firestore doc to get current periods for this day
        $existingPeriods = [];
        try {
            $existingDoc = $this->firebase->firestoreGet('timetables', $fsDocId);
            if (is_array($existingDoc) && isset($existingDoc['periods'])) {
                foreach ($existingDoc['periods'] as $p) {
                    $pi2 = ($p['periodNumber'] ?? 1) - 1;
                    $existingPeriods[$pi2] = $p;
                }
            }
        } catch (\Exception $e) {}

        $fsSettings = [];
        try { $fsSettings = $this->firebase->firestoreGet('timetableSettings', "{$this->school_id}_{$session}") ?? []; } catch (\Exception $e) {}
        $maxP = (int) ($fsSettings['No_of_periods'] ?? 8);

        // Build full period array
        $periodDocs = [];
        for ($p = 0; $p < max($maxP, $periodIdx + 1); $p++) {
            if ($p === $periodIdx) {
                // The cell we just edited
                $periodDocs[] = [
                    'periodNumber' => $p + 1,
                    'subject'      => $subject,
                    'teacher'      => $teacherName,
                    'teacherId'    => $teacherId,
                    'startTime'    => $existingPeriods[$p]['startTime'] ?? '',
                    'endTime'      => $existingPeriods[$p]['endTime'] ?? '',
                    'room'         => '',
                    'type'         => 'class',
                ];
            } elseif (isset($existingPeriods[$p])) {
                $periodDocs[] = $existingPeriods[$p];
            } else {
                $periodDocs[] = [
                    'periodNumber' => $p + 1,
                    'subject'      => '',
                    'teacher'      => '',
                    'teacherId'    => '',
                    'startTime'    => '',
                    'endTime'      => '',
                    'room'         => '',
                    'type'         => 'class',
                ];
            }
        }

        $this->firebase->firestoreSet('timetables', $fsDocId, [
            'schoolId'    => $school,
            'session'     => $session,
            'className'   => $canonClass,
            'section'     => $canonSection,
            'classOrder'  => $csNorm['classOrder'],
            'sectionCode' => $csNorm['sectionCode'],
            'sectionKey'  => $sectionKeyWrite,
            'day'         => $day,
            'periods'     => $periodDocs,
            'updatedAt'   => date('c'),
        ]);

        return $this->json_success([
            'day'          => $day,
            'period_index' => $periodIdx,
            'subject'      => $subject,
            'teacher_id'   => $teacherId,
            'teacher_name' => $teacherName,
            'warnings'     => $warnings,
        ]);
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
        $subject   = trim($this->input->post('subject') ?? '');
        $teacherId = trim($this->input->post('teacher_id') ?? '');
        $day       = trim($this->input->post('day') ?? '');
        $periodIdx = (int)($this->input->post('period_index') ?? -1);
        $excludeClass   = trim($this->input->post('exclude_class') ?? '');
        $excludeSection = trim($this->input->post('exclude_section') ?? '');

        if ((empty($subject) && empty($teacherId)) || empty($day) || $periodIdx < 0) {
            return $this->json_success(['conflict' => false, 'warnings' => []]);
        }

        $school  = $this->school_name;
        $session = $this->session_year;
        $warnings = [];

        // Pre-fetch all timetable docs for this day from Firestore (single query)
        $allDayDocs = [];
        try {
            $allDayDocs = $this->firebase->firestoreQuery('timetables', [
                ['schoolId', '==', $school],
                ['session', '==', $session],
                ['day', '==', $day],
            ], null, 'ASC', 200);
        } catch (\Exception $e) {}

        // ── 1. Teacher conflict (HARD — blocks save) ──
        if ($teacherId !== '') {
            foreach ($allDayDocs as $doc) {
                $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                $cn  = $d['className'] ?? '';
                $sec = $d['section'] ?? '';
                // Skip the section being edited
                if ($this->_class_to_firebase_key($cn) === $this->_class_to_firebase_key($excludeClass)
                    && (str_replace('Section ', '', $sec) === $excludeSection || $sec === "Section {$excludeSection}" || $sec === $excludeSection)) continue;

                foreach (($d['periods'] ?? []) as $p) {
                    $pi = ($p['periodNumber'] ?? 1) - 1;
                    if ($pi !== $periodIdx) continue;
                    $cellTeacherId = $p['teacherId'] ?? '';
                    if ($cellTeacherId !== '' && $cellTeacherId === $teacherId) {
                        $label = "{$cn} ({$sec})";
                        return $this->json_success([
                            'conflict' => true,
                            'type'     => 'teacher',
                            'severity' => 'error',
                            'message'  => "Teacher conflict: already assigned to {$label} on {$day} P" . ($periodIdx + 1),
                        ]);
                    }
                }
            }
        }

        // ── 2. Duplicate subject same day (SOFT warning) ──
        if ($excludeClass !== '' && $subject !== '') {
            foreach ($allDayDocs as $doc) {
                $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                $cn  = $d['className'] ?? '';
                $sec = $d['section'] ?? '';
                if ($this->_class_to_firebase_key($cn) !== $this->_class_to_firebase_key($excludeClass)) continue;
                if (str_replace('Section ', '', $sec) !== $excludeSection && $sec !== "Section {$excludeSection}" && $sec !== $excludeSection) continue;

                $dupCount = 0;
                foreach (($d['periods'] ?? []) as $p) {
                    $pi = ($p['periodNumber'] ?? 1) - 1;
                    if ($pi === $periodIdx) continue;
                    $cellSub = $p['subject'] ?? '';
                    if ($cellSub !== '' && strcasecmp($cellSub, $subject) === 0) $dupCount++;
                }
                if ($dupCount > 0) {
                    $warnings[] = [
                        'type'    => 'duplicate',
                        'message' => "{$subject} already has {$dupCount} other period(s) on {$day}",
                    ];
                }
                break;
            }
        }

        // ── 3. Weekly period-limit from Subject_Assignments (Firestore-only) ──
        if ($excludeClass !== '' && $subject !== '') {
            $limit = 0;
            try {
                $this->load->library('subject_assignment_service', null, 'sas');
                $this->sas->init($this->fs, $this->firebase, $this->school_id, $this->school_name, $this->session_year);
                $fbKey = $this->_class_to_firebase_key($excludeClass);
                $secAssigns = $this->sas->getAssignmentsForClass($fbKey, "Section {$excludeSection}");
                $clsAssigns = $this->sas->getAssignmentsForClass($fbKey, '');
                foreach (array_merge($secAssigns, $clsAssigns) as $a) {
                    $aName = $a['subjectName'] ?? '';
                    $aCode = $a['subjectCode'] ?? '';
                    if (strcasecmp($aName, $subject) === 0 || strcasecmp($aCode, $subject) === 0) {
                        $limit = (int)($a['periodsPerWeek'] ?? 0);
                        break;
                    }
                }
            } catch (\Exception $e) {}

            if ($limit > 0) {
                // Query all days for this section from Firestore
                $weekCount = 0;
                try {
                    require_once APPPATH . 'libraries/Entity_firestore_sync.php';
                    $csN = Entity_firestore_sync::normalizeClassSection($excludeClass, "Section {$excludeSection}");
                    $normSK = ($csN['className'] ?: $excludeClass) . '/' . ($csN['section'] ?: "Section {$excludeSection}");
                    $weekDocs = $this->firebase->firestoreQuery('timetables', [
                        ['schoolId', '==', $school],
                        ['session', '==', $session],
                        ['sectionKey', '==', $normSK],
                    ], null, 'ASC', 7);
                    foreach ($weekDocs as $doc) {
                        $d2 = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                        $docDay = $d2['day'] ?? '';
                        foreach (($d2['periods'] ?? []) as $p) {
                            $pi2 = ($p['periodNumber'] ?? 1) - 1;
                            if ($docDay === $day && $pi2 === $periodIdx) continue;
                            $cellSub = $p['subject'] ?? '';
                            if ($cellSub !== '' && strcasecmp($cellSub, $subject) === 0) $weekCount++;
                        }
                    }
                } catch (\Exception $e) {}
                $newTotal = $weekCount + 1;
                if ($newTotal > $limit) {
                    $warnings[] = [
                        'type'    => 'period_limit',
                        'message' => "{$subject}: {$newTotal} periods/week exceeds limit of {$limit}",
                    ];
                }
            }
        }

        return $this->json_success(['conflict' => false, 'warnings' => $warnings]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       SUBSTITUTE MANAGEMENT
    ══════════════════════════════════════════════════════════════════════ */

    public function get_substitutes()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_substitutes');
        $date     = trim($this->input->post('date') ?? '');
        $dateFrom = trim($this->input->post('date_from') ?? '');
        $dateTo   = trim($this->input->post('date_to') ?? '');

        // Firestore-first
        $records = [];
        try {
            $conditions = [];
            if ($date !== '') $conditions[] = ['date', '==', $date];
            $fsDocs = $this->fs->sessionWhere('substitutes', $conditions, null, 'ASC', 100);
            if (is_array($fsDocs) && !empty($fsDocs)) {
                foreach ($fsDocs as $doc) {
                    $d = $doc['data'] ?? $doc;
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $d['id'] = $d['id'] ?? '';
                    $recDate = $d['date'] ?? '';
                    if ($dateFrom !== '' && $recDate < $dateFrom) continue;
                    if ($dateTo !== '' && $recDate > $dateTo) continue;
                    $records[] = $d;
                }
            }
        } catch (\Exception $e) {}

        usort($records, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));
        if ($date === '' && $dateFrom === '' && $dateTo === '') {
            $records = array_slice($records, 0, 100);
        }

        // Auto-complete: mark past-date "assigned" records as "completed"
        $today = date('Y-m-d');
        foreach ($records as &$rec) {
            if (($rec['status'] ?? '') === 'assigned' && ($rec['date'] ?? '') < $today) {
                $rec['status'] = 'completed';
                // Async update in Firestore
                $recId = $rec['id'] ?? '';
                if ($recId !== '') {
                    try {
                        $fsDocId = "{$this->school_id}_{$recId}";
                        $this->firebase->firestoreSet('substitutes', $fsDocId, [
                            'status' => 'completed', 'updatedAt' => date('c'), 'updated_by' => 'system'
                        ], true);
                    } catch (\Exception $e) {}
                }
            }
        }
        unset($rec);

        return $this->json_success(['substitutes' => $records]);
    }

    public function save_substitute()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'manage_substitutes');

        $id         = trim($this->input->post('id') ?? '');
        $dateStr    = trim($this->input->post('date') ?? '');
        $absentId   = trim($this->input->post('absent_teacher_id') ?? '');
        $absentName = trim($this->input->post('absent_teacher_name') ?? '');
        $reason     = trim($this->input->post('reason') ?? '');

        if (empty($dateStr) || empty($absentId)) {
            return $this->json_error('Date and absent teacher are required');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr) || !strtotime($dateStr)) {
            return $this->json_error('Invalid date format');
        }

        // Parse assignments array (new per-period format)
        $assignmentsRaw = $this->input->post('assignments');
        $assignments = is_string($assignmentsRaw) ? json_decode($assignmentsRaw, true) : $assignmentsRaw;

        // Legacy flat format fallback
        if (!is_array($assignments) || empty($assignments)) {
            $substituteId   = trim($this->input->post('substitute_teacher_id') ?? '');
            $substituteName = trim($this->input->post('substitute_teacher_name') ?? '');
            $classSection   = $this->safe_path_segment(trim($this->input->post('class_section') ?? ''), 'class_section');
            $subject        = trim($this->input->post('subject') ?? '');
            $periodsRaw     = $this->input->post('periods');
            $periods = is_string($periodsRaw) ? json_decode($periodsRaw, true) : $periodsRaw;
            if (!is_array($periods)) $periods = [];
            $periods = array_values(array_unique(array_filter(array_map('intval', $periods), fn($p) => $p >= 1)));

            if (empty($substituteId) || empty($classSection) || empty($periods)) {
                return $this->json_error('Assignments data or legacy fields (substitute, class, periods) required');
            }
            if ($absentId === $substituteId) {
                return $this->json_error('Absent teacher and substitute cannot be the same person');
            }

            // Convert legacy format to assignments array
            $assignments = [];
            foreach ($periods as $pn) {
                $assignments[] = [
                    'periodNumber'            => $pn,
                    'subject'                 => $subject,
                    'className'               => $classSection,
                    'section'                 => '',
                    'substitute_teacher_id'   => $substituteId,
                    'substitute_teacher_name' => $substituteName,
                ];
            }
        }

        // Validate each assignment
        $dt = new \DateTime($dateStr);
        $dayOfWeek = $dt->format('l');

        // Load timetable data for conflict checking
        $ttDocs = [];
        $busyMap = []; // [teacherId][periodNum] => true
        try {
            $ttDocs = $this->firebase->firestoreQuery('timetables', [
                ['schoolId', '==', $this->school_name],
                ['session', '==', $this->session_year],
                ['day', '==', $dayOfWeek],
            ], null, 'ASC', 100);
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

        // Load existing substitutes for duplicate/conflict detection (Firestore-first)
        $existingSubs = [];
        try {
            $fsDocs = $this->fs->sessionWhere('substitutes', [['date', '==', $dateStr]], null, 'ASC', 200);
            foreach ($fsDocs as $doc) {
                $d = $doc['data'] ?? $doc;
                $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                $d['id'] = $d['id'] ?? '';
                if (($d['status'] ?? '') === 'cancelled') continue;
                if ($id !== '' && ($d['id'] ?? '') === $id) continue;
                $existingSubs[] = $d;
            }
        } catch (\Exception $e) {}

        // Validate each assignment
        $cleanAssignments = [];
        foreach ($assignments as $a) {
            $pn    = (int) ($a['periodNumber'] ?? 0);
            $subId = trim($a['substitute_teacher_id'] ?? '');
            $subNm = trim($a['substitute_teacher_name'] ?? '');
            if ($pn < 1 || empty($subId)) continue;

            if ($subId === $absentId) {
                return $this->json_error("Cannot assign absent teacher as substitute for Period {$pn}");
            }

            // Check: substitute teacher's own timetable conflict
            if (isset($busyMap[$subId][$pn])) {
                return $this->json_error("{$subNm} already teaches their own class during Period {$pn} on {$dayOfWeek}");
            }

            // Check: substitute teacher already covering another substitute at same period
            foreach ($existingSubs as $ex) {
                $exAssigns = $ex['assignments'] ?? [];
                if (!empty($exAssigns)) {
                    foreach ($exAssigns as $ea) {
                        if ((int)($ea['periodNumber'] ?? 0) === $pn && ($ea['substitute_teacher_id'] ?? '') === $subId) {
                            return $this->json_error("{$subNm} is already covering another substitution at Period {$pn} on this date");
                        }
                    }
                }
                // Legacy format: check flat substitute_teacher_id + periods array
                if (($ex['substitute_teacher_id'] ?? '') === $subId) {
                    $exPeriods = $ex['periods'] ?? [];
                    if (is_array($exPeriods) && in_array($pn, $exPeriods)) {
                        return $this->json_error("{$subNm} is already covering another substitution at Period {$pn} on this date");
                    }
                }
            }

            // Check: same absent teacher already has this period covered
            foreach ($existingSubs as $ex) {
                if (($ex['absent_teacher_id'] ?? '') !== $absentId) continue;
                $exAssigns = $ex['assignments'] ?? [];
                if (!empty($exAssigns)) {
                    foreach ($exAssigns as $ea) {
                        if ((int)($ea['periodNumber'] ?? 0) === $pn) {
                            return $this->json_error("Period {$pn} for this teacher is already covered by another substitute record");
                        }
                    }
                }
                $exPeriods = $ex['periods'] ?? [];
                if (is_array($exPeriods) && in_array($pn, $exPeriods)) {
                    return $this->json_error("Period {$pn} for this teacher is already covered by another substitute record");
                }
            }

            $cleanAssignments[] = [
                'periodNumber'            => $pn,
                'subject'                 => trim($a['subject'] ?? ''),
                'className'               => trim($a['className'] ?? ''),
                'section'                 => trim($a['section'] ?? ''),
                'substitute_teacher_id'   => $subId,
                'substitute_teacher_name' => $subNm,
            ];
        }

        if (empty($cleanAssignments)) {
            return $this->json_error('No valid period assignments found');
        }

        usort($cleanAssignments, fn($a, $b) => $a['periodNumber'] - $b['periodNumber']);
        $adminName = $this->session->userdata('admin_name') ?? 'Admin';

        $data = [
            'date'                => $dateStr,
            'absent_teacher_id'   => $absentId,
            'absent_teacher_name' => $absentName,
            'assignments'         => $cleanAssignments,
            'reason'              => $reason,
            'updated_at'          => date('Y-m-d H:i:s'),
            'updated_by'          => $adminName,
        ];

        if ($id !== '') {
            $fsDocId = "{$this->school_id}_{$id}";
            $current = null;
            try { $current = $this->firebase->firestoreGet('substitutes', $fsDocId); } catch (\Exception $e) {}
            $data['status']     = is_array($current) ? ($current['status'] ?? 'assigned') : 'assigned';
            $data['createdAt']  = is_array($current) ? ($current['createdAt'] ?? $current['created_at'] ?? date('c')) : date('c');
            $data['created_by'] = is_array($current) ? ($current['created_by'] ?? $adminName) : $adminName;
        } else {
            $id = 'SUB_' . uniqid();
            $data['status']     = 'assigned';
            $data['createdAt']  = date('c');
            $data['created_by'] = $adminName;
        }

        // Firestore-first write
        $fsDocId = "{$this->school_id}_{$id}";
        $fsData = array_merge($data, [
            'schoolId'  => $this->school_id,
            'session'   => $this->session_year,
            'updatedAt' => date('c'),
        ]);
        $this->firebase->firestoreSet('substitutes', $fsDocId, $fsData, true);

        return $this->json_success(['id' => $id, 'assignments_count' => count($cleanAssignments)]);
    }

    public function update_substitute()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'manage_substitutes');
        $id     = trim($this->input->post('id') ?? '');
        $status = trim($this->input->post('status') ?? '');

        if (empty($id)) return $this->json_error('Substitute ID required');
        if (!in_array($status, ['assigned', 'completed', 'cancelled'])) {
            return $this->json_error('Invalid status');
        }

        $adminName = $this->session->userdata('admin_name') ?? 'Admin';
        $updateData = [
            'status'     => $status,
            'updatedAt'  => date('c'),
            'updated_by' => $adminName,
        ];

        // Firestore-only
        $fsDocId = "{$this->school_id}_{$id}";
        $this->firebase->firestoreSet('substitutes', $fsDocId, $updateData, true);

        return $this->json_success(['id' => $id, 'status' => $status]);
    }

    public function delete_substitute()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'manage_substitutes');
        $id = trim($this->input->post('id') ?? '');
        if (empty($id)) return $this->json_error('Substitute ID required');

        // Firestore-only delete
        $fsDocId = "{$this->school_id}_{$id}";
        try { $this->firebase->firestoreDelete('substitutes', $fsDocId); } catch (\Exception $e) {}

        return $this->json_success([]);
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

        // Firestore-only
        $fsDocId = "{$this->school_id}_{$this->session_year}";
        $settings = [];
        try {
            $fsDoc = $this->firebase->firestoreGet('timetableSettings', $fsDocId);
            if (is_array($fsDoc) && !empty($fsDoc)) $settings = $fsDoc;
        } catch (\Exception $e) {
            log_message('error', "get_timetable_settings Firestore read failed: " . $e->getMessage());
        }
        if (!is_array($settings)) $settings = [];

        // Normalize recesses (handle both new and legacy format)
        $recesses = [];
        if (isset($settings['Recesses']) && is_array($settings['Recesses'])) {
            foreach ($settings['Recesses'] as $r) {
                if (is_array($r) && isset($r['after_period'], $r['duration'])) {
                    $recesses[] = ['after_period' => (int)$r['after_period'], 'duration' => (int)$r['duration']];
                }
            }
        } elseif (isset($settings['Recess_breaks']) && is_array($settings['Recess_breaks'])) {
            // Legacy backward compat
            foreach ($settings['Recess_breaks'] as $range) {
                if (!is_string($range) || strpos($range, '-') === false) continue;
                $parts = array_map('trim', explode('-', $range));
                $fromMin = $this->_time_to_minutes($parts[0]);
                $toMin   = $this->_time_to_minutes($parts[1]);
                if ($toMin > $fromMin) {
                    $recesses[] = ['after_period' => null, 'duration' => $toMin - $fromMin];
                }
            }
        }

        // Working days (default Mon-Sat)
        $defaultDays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        $workingDays = isset($settings['Working_days']) && is_array($settings['Working_days'])
            ? $settings['Working_days'] : $defaultDays;

        return $this->json_success([
            'start_time'       => $settings['Start_time'] ?? '9:00AM',
            'end_time'         => $settings['End_time'] ?? '3:00PM',
            'no_of_periods'    => (int)($settings['No_of_periods'] ?? 6),
            'length_of_period' => (float)($settings['Length_of_period'] ?? 45),
            'recesses'         => $recesses,
            'working_days'     => $workingDays,
        ]);
    }

    /**
     * Save period scheduling (periods, times, recesses, working days)
     */
    public function save_timetable_settings()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'save_tt_settings');

        $startRaw = trim($this->input->post('start_time') ?? ''); // HH:mm (24h)
        $endRaw   = trim($this->input->post('end_time') ?? '');   // HH:mm (24h)
        $periods  = (int)($this->input->post('no_of_periods') ?? 0);
        $recessRaw = $this->input->post('recesses') ?? '[]';
        $recesses  = is_string($recessRaw) ? json_decode($recessRaw, true) : $recessRaw;
        if (!is_array($recesses)) $recesses = [];

        // Working days
        $daysRaw     = $this->input->post('working_days') ?? '[]';
        $workingDays = is_string($daysRaw) ? json_decode($daysRaw, true) : $daysRaw;
        $allDays     = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
        if (!is_array($workingDays) || empty($workingDays)) {
            $workingDays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        }
        $workingDays = array_values(array_intersect($workingDays, $allDays));

        if (empty($startRaw) || empty($endRaw) || $periods <= 0) {
            return $this->json_error('Start time, end time, and number of periods are required');
        }

        $startMin = $this->_time_to_minutes($startRaw);
        $endMin   = $this->_time_to_minutes($endRaw);

        if ($endMin <= $startMin) {
            return $this->json_error('End time must be after start time');
        }

        // Validate and total recess minutes
        $cleanRecesses = [];
        $recessMinutes = 0;
        foreach ($recesses as $r) {
            if (!is_array($r)) continue;
            $dur   = (int)($r['duration'] ?? 0);
            $after = (int)($r['after_period'] ?? 0);
            if ($dur > 0 && $after > 0 && $after < $periods) {
                $cleanRecesses[] = ['after_period' => $after, 'duration' => $dur];
                $recessMinutes += $dur;
            }
        }

        $available = $endMin - $startMin - $recessMinutes;
        if ($available <= 0) {
            return $this->json_error('Recess duration exceeds available time');
        }

        $periodLength = round($available / $periods, 1);

        // Convert 24h to AM/PM for Firebase storage (matches existing format)
        $startAmPm = $this->_to_ampm($startRaw);
        $endAmPm   = $this->_to_ampm($endRaw);

        $data = [
            'Start_time'       => $startAmPm,
            'End_time'         => $endAmPm,
            'No_of_periods'    => $periods,
            'Length_of_period'  => $periodLength,
            'Recesses'         => array_values($cleanRecesses),
            'Working_days'     => $workingDays,
        ];

        // Firestore-first write
        $fsDocId = "{$this->school_id}_{$this->session_year}";
        $fsData = array_merge($data, [
            'schoolId'  => $this->school_id,
            'session'   => $this->session_year,
            'updatedAt' => date('c'),
        ]);
        $this->firebase->firestoreSet('timetableSettings', $fsDocId, $fsData);

        return $this->json_success(['length_of_period' => $periodLength, 'settings' => $data]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       PRIVATE HELPERS
    ══════════════════════════════════════════════════════════════════════ */

    private function _time_to_minutes(string $time): int
    {
        // Accept both "HH:mm" (24h) and "h:mmAM/PM" formats
        $time = strtoupper(trim($time));
        // Try AM/PM first
        if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)$/', $time, $m)) {
            $h = (int)$m[1];
            $min = (int)$m[2];
            if ($m[3] === 'PM' && $h !== 12) $h += 12;
            if ($m[3] === 'AM' && $h === 12) $h = 0;
            return $h * 60 + $min;
        }
        // 24h format
        if (strpos($time, ':') !== false) {
            $parts = explode(':', $time);
            return ((int)$parts[0] * 60) + (int)$parts[1];
        }
        return 0;
    }

    private function _to_ampm(string $time24): string
    {
        $dt = \DateTime::createFromFormat('H:i', trim($time24));
        return $dt ? $dt->format('g:iA') : $time24;
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

        $class   = $this->safe_path_segment(trim($this->input->post('class_name') ?? ''), 'class_name');
        $section = $this->safe_path_segment(trim($this->input->post('section_name') ?? ''), 'section_name');
        $raw     = $this->input->post('timetable');

        if (empty($class) || empty($section)) {
            return $this->json_error('Class and section are required');
        }

        $timetable = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($timetable)) {
            return $this->json_error('Invalid timetable data');
        }

        // Validate day keys
        $validDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $clean = [];
        foreach ($timetable as $day => $periods) {
            if (!in_array($day, $validDays)) continue;
            $clean[$day] = is_array($periods) ? $periods : [];
        }

        // ── Write to Firestore 'timetables' collection (Firestore-only) ──
        // Phase 3 (2026-04-08): normalize class/section into the canonical
        // shape (matches students/sections/subjectAssignments) so the Teacher
        // app's `where('sectionKey','==', "Class 8th/Section A")` query
        // actually matches. Previously this wrote raw POST values like
        // "Class 8" / "A" which never matched canonical reads.
        try {
            require_once APPPATH . 'libraries/Entity_firestore_sync.php';
            $cs = Entity_firestore_sync::normalizeClassSection($class, $section);
            $canonicalClass   = $cs['className'] !== '' ? $cs['className'] : $class;
            $canonicalSection = $cs['section']   !== '' ? $cs['section']   : $section;
            $sectionKey = ($canonicalClass !== '' && $canonicalSection !== '')
                ? "{$canonicalClass}/{$canonicalSection}"
                : "{$class}/{$section}";

            $sn = $this->school_name;
            $sy = $this->session_year;

            foreach ($clean as $day => $periods) {
                $safeKey = str_replace('/', '_', $sectionKey);
                $docId = "{$sn}_{$sy}_{$safeKey}_{$day}";
                $periodDocs = [];
                $periodNum = 1;

                foreach ($periods as $key => $entry) {
                    if (!is_array($entry)) continue;
                    $type = strtolower(trim($entry['type'] ?? 'class'));
                    $isBreak = ($type === 'break' || $type === 'lunch'
                        || stripos($key, 'Break') === 0 || strcasecmp($key, 'Lunch') === 0);

                    $periodDocs[] = [
                        'periodNumber' => is_numeric($key) ? intval($key) : $periodNum,
                        'subject'      => $isBreak ? '' : ($entry['subject'] ?? $entry['Subject'] ?? ''),
                        'teacher'      => $isBreak ? '' : ($entry['teacher'] ?? $entry['Teacher'] ?? ''),
                        'teacherId'    => $entry['teacherId'] ?? $entry['teacher_id'] ?? '',
                        'startTime'    => $entry['startTime'] ?? $entry['start_time'] ?? '',
                        'endTime'      => $entry['endTime'] ?? $entry['end_time'] ?? '',
                        'room'         => $entry['room'] ?? $entry['Room'] ?? '',
                        'type'         => $isBreak ? ($type === 'lunch' ? 'lunch' : 'break') : 'class',
                    ];
                    $periodNum++;
                }

                $this->firebase->firestoreSet('timetables', $docId, [
                    'schoolId'    => $sn,
                    'session'     => $sy,
                    'className'   => $canonicalClass,
                    'section'     => $canonicalSection,
                    'classOrder'  => $cs['classOrder'],
                    'sectionCode' => $cs['sectionCode'],
                    'sectionKey'  => $sectionKey,
                    'day'         => $day,
                    'periods'     => $periodDocs,
                    'updatedAt'   => date('c'),
                ]);
            }
        } catch (\Exception $e) {
            log_message('error', "save_section_timetable: Firestore sync failed: " . $e->getMessage());
        }

        return $this->json_success(['message' => 'Timetable saved successfully']);
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

        // Allow up to 5 minutes for large schools
        set_time_limit(300);

        $confirm    = ($this->input->post('confirm') === '1');
        $scopeClass = trim($this->input->post('class_key') ?? '');
        $school     = $this->school_name;
        $session    = $this->session_year;

        // ── 1. Read period settings — Firestore-only ──
        $settings = null;
        try {
            $settings = $this->firebase->firestoreGet('timetableSettings', "{$this->school_id}_{$session}");
        } catch (\Exception $e) {}
        if (!is_array($settings)) $settings = [];
        $periodsPerDay = (int) ($settings['No_of_periods'] ?? 0);
        if ($periodsPerDay <= 0) {
            return $this->json_error('Period Scheduling not configured. Set periods per day first.');
        }
        $workingDays = isset($settings['Working_days']) && is_array($settings['Working_days'])
            ? $settings['Working_days']
            : ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        $numDays = count($workingDays);
        $startTime  = $settings['Start_time'] ?? '09:00AM';
        $periodLen  = (float) ($settings['Length_of_period'] ?? 45);
        $recesses   = isset($settings['Recesses']) && is_array($settings['Recesses'])
            ? $settings['Recesses'] : [];

        // Compute period start/end times
        $periodTimes = $this->_compute_period_times($startTime, $periodsPerDay, $periodLen, $recesses);

        // ── 2. Read all subject assignments from Firestore ──
        $this->load->library('subject_assignment_service', null, 'sas');
        $this->sas->init($this->fs, $this->firebase, $this->school_id, $this->school_name, $session);

        // Get all sections
        $sectionDocs = $this->fs->schoolWhere('sections', [['session', '==', $session]]);
        $sections = [];
        foreach ($sectionDocs as $doc) {
            $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
            $cn = $d['className'] ?? '';
            $sc = $d['section'] ?? '';
            if ($cn === '' || $sc === '') continue;
            if ($scopeClass !== '' && $cn !== $this->_normalize_class_label($scopeClass)) continue;
            $sections[] = ['className' => $cn, 'section' => $sc, 'key' => "{$cn}/{$sc}"];
        }

        if (empty($sections)) {
            return $this->json_error('No sections found. Create classes and sections in School Config first.');
        }

        // Load ALL assignments in ONE bulk query (not per-section — saves 19 Firestore calls)
        $allByClass = $this->sas->getAllForSession();
        $sectionAssignments = []; // [sectionKey] => [{subject, teacherId, teacherName, periodsPerWeek}]
        foreach ($sections as $sec) {
            $cn = $sec['className'];
            $sc = $sec['section'];
            $sk = $sec['key'];
            // Match assignments: exact section OR class-wide (empty section)
            $matched = [];
            foreach (($allByClass[$cn] ?? []) as $a) {
                $aSec = $a['section'] ?? '';
                if ($aSec === $sc || $aSec === '') {
                    $pw = (int) ($a['periodsPerWeek'] ?? 0);
                    if ($pw <= 0) continue;
                    $matched[] = [
                        'subject'     => ($a['subjectName'] ?? '') ?: ($a['subjectCode'] ?? ''),
                        'code'        => $a['subjectCode'] ?? '',
                        'teacherId'   => $a['teacherId'] ?? '',
                        'teacherName' => $a['teacherName'] ?? '',
                        'periodsPerWeek' => $pw,
                    ];
                }
            }
            $sectionAssignments[$sk] = $matched;
        }

        // ── 3. Run the algorithm ──
        $hardSubjects = ['Mathematics', 'Math', 'Science', 'Physics', 'Chemistry', 'Biology', 'English'];
        $grid = [];         // [sectionKey][day] => [periodIdx => {subject, teacherId, teacherName, type}]
        $teacherBusy = [];  // [teacherId][day][periodIdx] => sectionKey (tracks conflicts)

        foreach ($sections as $sec) {
            $sk = $sec['key'];
            $items = $sectionAssignments[$sk] ?? [];
            if (empty($items)) continue;

            // Calculate per-day distribution for each subject
            // E.g. 5 periods over 6 days → [1,1,1,1,1,0] spread evenly
            $subjectSlots = []; // [{subject, teacherId, ...}] flat list — one entry per needed period
            foreach ($items as $item) {
                for ($i = 0; $i < $item['periodsPerWeek']; $i++) {
                    $subjectSlots[] = $item;
                }
            }

            // Sort: hard subjects first (get morning slots), then by periods descending
            usort($subjectSlots, function ($a, $b) use ($hardSubjects) {
                $aHard = in_array($a['subject'], $hardSubjects) ? 0 : 1;
                $bHard = in_array($b['subject'], $hardSubjects) ? 0 : 1;
                if ($aHard !== $bHard) return $aHard - $bHard;
                return $b['periodsPerWeek'] - $a['periodsPerWeek'];
            });

            // Initialize grid for this section
            foreach ($workingDays as $day) {
                $grid[$sk][$day] = array_fill(0, $periodsPerDay, null);
            }

            // Track subjects placed per day for spread constraint
            $daySubjectCount = []; // [day][subject] => count
            foreach ($workingDays as $day) $daySubjectCount[$day] = [];

            // Place each subject slot
            foreach ($subjectSlots as $slot) {
                $placed = false;
                $subj = $slot['subject'];
                $tid  = $slot['teacherId'];

                // Find the best (day, period) for this slot
                // Priority: day with fewest of this subject, then earliest free period
                // Also ensure teacher not double-booked
                $candidates = [];
                foreach ($workingDays as $di => $day) {
                    $dayCount = $daySubjectCount[$day][$subj] ?? 0;
                    for ($p = 0; $p < $periodsPerDay; $p++) {
                        if ($grid[$sk][$day][$p] !== null) continue; // slot taken
                        if ($tid !== '' && isset($teacherBusy[$tid][$day][$p])) continue; // teacher busy
                        $candidates[] = [
                            'day' => $day, 'dayIdx' => $di, 'period' => $p,
                            'dayCount' => $dayCount, 'score' => $dayCount * 100 + $p,
                        ];
                    }
                }

                // Sort candidates by score (fewest same-subject on that day, then earliest period)
                usort($candidates, function ($a, $b) { return $a['score'] - $b['score']; });

                if (!empty($candidates)) {
                    $best = $candidates[0];
                    $grid[$sk][$best['day']][$best['period']] = [
                        'subject'     => $subj,
                        'teacherId'   => $tid,
                        'teacherName' => $slot['teacherName'],
                        'type'        => 'class',
                    ];
                    if ($tid !== '') {
                        $teacherBusy[$tid][$best['day']][$best['period']] = $sk;
                    }
                    $daySubjectCount[$best['day']][$subj] = ($daySubjectCount[$best['day']][$subj] ?? 0) + 1;
                }
                // If no valid slot found, the period is simply unallocated (section has too many periods)
            }
        }

        // ── 4. Build response / write ──
        $result = [
            'sections_generated' => 0,
            'conflicts'          => 0,
            'unallocated'        => 0,
            'timetable'          => [],
        ];

        require_once APPPATH . 'libraries/Entity_firestore_sync.php';

        foreach ($grid as $sk => $days) {
            $parts = explode('/', $sk);
            $className = $parts[0] ?? '';
            $sectionName = $parts[1] ?? '';
            $cs = Entity_firestore_sync::normalizeClassSection($className, $sectionName);
            $canonClass = $cs['className'] ?: $className;
            $canonSection = $cs['section'] ?: $sectionName;
            $sectionKey = "{$canonClass}/{$canonSection}";

            $sectionData = [];
            foreach ($days as $day => $periods) {
                $periodDocs = [];
                $freeCount = 0;
                foreach ($periods as $pi => $cell) {
                    $time = $periodTimes[$pi] ?? ['start' => '', 'end' => ''];
                    if ($cell === null) {
                        $freeCount++;
                        $periodDocs[] = [
                            'periodNumber' => $pi + 1,
                            'subject'      => '',
                            'teacher'      => '',
                            'teacherId'    => '',
                            'startTime'    => $time['start'],
                            'endTime'      => $time['end'],
                            'room'         => '',
                            'type'         => 'class', // empty class period
                        ];
                    } else {
                        $periodDocs[] = [
                            'periodNumber' => $pi + 1,
                            'subject'      => $cell['subject'],
                            'teacher'      => $cell['teacherName'],
                            'teacherId'    => $cell['teacherId'],
                            'startTime'    => $time['start'],
                            'endTime'      => $time['end'],
                            'room'         => '',
                            'type'         => $cell['type'],
                        ];
                    }
                }
                $result['unallocated'] += $freeCount;
                $sectionData[$day] = $periodDocs;

                if ($confirm) {
                    // Write to Firestore — replace / with _ in doc ID (Firestore rejects /)
                    $safeKey = str_replace('/', '_', $sectionKey);
                    $docId = "{$this->school_name}_{$session}_{$safeKey}_{$day}";
                    try {
                        $this->firebase->firestoreSet('timetables', $docId, [
                            'schoolId'    => $this->school_name,
                            'session'     => $session,
                            'className'   => $canonClass,
                            'section'     => $canonSection,
                            'classOrder'  => $cs['classOrder'],
                            'sectionCode' => $cs['sectionCode'],
                            'sectionKey'  => $sectionKey,
                            'day'         => $day,
                            'periods'     => $periodDocs,
                            'updatedAt'   => date('c'),
                        ]);
                    } catch (\Exception $e) {
                        log_message('error', "auto_generate: Firestore write failed [{$sectionKey}/{$day}]: " . $e->getMessage());
                        $result['conflicts']++;
                    }
                }
            }

            $result['sections_generated']++;
            $result['timetable'][$sk] = $sectionData;
        }

        $result['mode'] = $confirm ? 'saved' : 'preview';
        return $this->json_success($result);
    }

    /**
     * Compute start/end times for each period based on settings.
     */
    private function _compute_period_times(string $startTime, int $numPeriods, float $periodLen, array $recesses): array
    {
        $times = [];
        // Parse start time
        $startMin = $this->_time_to_minutes($startTime);
        $currentMin = $startMin;

        // Build recess lookup: after_period => duration_minutes
        $recessAfter = [];
        foreach ($recesses as $r) {
            $ap = $r['after_period'] ?? null;
            $dur = (int) ($r['duration'] ?? 0);
            if ($ap !== null && $dur > 0) {
                $recessAfter[(int) $ap] = $dur;
            }
        }

        for ($i = 0; $i < $numPeriods; $i++) {
            $start = $currentMin;
            $end   = $currentMin + (int) $periodLen;
            $times[] = [
                'start' => $this->_minutes_to_time($start),
                'end'   => $this->_minutes_to_time($end),
            ];
            $currentMin = $end;

            // Add recess after this period if configured
            $periodNum = $i + 1;
            if (isset($recessAfter[$periodNum])) {
                $currentMin += $recessAfter[$periodNum];
            }
        }

        return $times;
    }

    private function _minutes_to_time(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        $ampm = $h >= 12 ? 'PM' : 'AM';
        $h12 = $h % 12 ?: 12;
        return sprintf('%d:%02d%s', $h12, $m, $ampm);
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
