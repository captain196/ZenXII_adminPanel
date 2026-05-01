<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Learning Management System (LMS) Controller
 *
 * Provides Online Classes, Study Materials, Assignments, Quizzes management.
 * Tab-based SPA with clean URL routing — each tab loads via server route + AJAX data.
 *
 * Access: Admin / Principal / Teacher (RBAC via MANAGE_ROLES / VIEW_ROLES)
 * Teachers can only CRUD content they own (teacherId enforcement).
 * Student-facing endpoints (get_student_*) are used by mobile apps.
 *
 * Firebase paths:
 *   Schools/{school}/{session}/LMS/Classes/{id}
 *   Schools/{school}/{session}/LMS/Materials/{id}
 *   Schools/{school}/{session}/LMS/Assignments/{id}
 *   Schools/{school}/{session}/LMS/Submissions/{assignmentId}/{studentId}
 *   Schools/{school}/{session}/LMS/Quizzes/{id}
 *   Schools/{school}/{session}/LMS/QuizAttempts/{quizId}/{studentId}/{attemptId}
 */
class Lms extends MY_Controller
{
    /** Roles that can manage (create/edit/delete) LMS content */
    const MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'];

    /** All roles that can view LMS content */
    const VIEW_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'];

    private $_lmsBase;

    public function __construct()
    {
        parent::__construct();
        require_permission('LMS');
        $this->_lmsBase = "Schools/{$this->school_name}/{$this->session_year}/LMS";
    }

    /* ══════════════════════════════════════════════════════════════════════
       PAGE LOAD
    ══════════════════════════════════════════════════════════════════════ */

    public function index($tab = 'dashboard')
    {
        $this->_require_role(self::VIEW_ROLES, 'lms_view');

        $validTabs = ['dashboard', 'classes', 'materials', 'assignments', 'quizzes'];
        if (!in_array($tab, $validTabs, true)) $tab = 'dashboard';

        $data['session_year'] = $this->session_year;
        $data['school_name']  = $this->school_name;
        $data['school_id']    = $this->school_id;
        $data['admin_role']   = $this->admin_role ?? '';
        $data['admin_id']     = $this->admin_id ?? '';
        $data['admin_name']   = $this->session->userdata('admin_name') ?? '';
        $data['active_tab']   = $tab;

        $this->load->view('include/header');
        $this->load->view('lms/index', $data);
        $this->load->view('include/footer');
    }

    /* ══════════════════════════════════════════════════════════════════════
       SHARED DATA ENDPOINTS
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * GET — return all class-sections + subjects for dropdowns
     */
    public function get_classes_subjects()
    {
        $this->_require_role(self::VIEW_ROLES, 'lms_data');

        $classes  = $this->_get_session_classes();
        $subjects = [];
        $subjectList = $this->firebase->get("Schools/{$this->school_name}/Subject_list") ?? [];
        if (is_array($subjectList)) {
            foreach ($subjectList as $classNum => $subs) {
                if (!is_array($subs)) continue;
                foreach ($subs as $code => $sub) {
                    if (!is_array($sub)) continue;
                    $subjects[$classNum][] = [
                        'code' => $code,
                        'name' => $sub['subject_name'] ?? $sub['name'] ?? (string)$code,
                    ];
                }
            }
        }

        $this->json_success(['classes' => $classes, 'subjects' => $subjects]);
    }

    /**
     * GET — dashboard statistics
     * Fixed: single full-get per node instead of shallow_get + full get for teachers
     */
    public function get_dashboard()
    {
        $this->_require_role(self::VIEW_ROLES, 'lms_dashboard');

        $base = $this->_lmsBase;
        $role = $this->admin_role ?? '';
        $tid  = $this->admin_id;

        // Single full read per node (avoids double-read for teachers)
        $allClasses  = $this->firebase->get("{$base}/Classes") ?? [];
        $allMats     = $this->firebase->get("{$base}/Materials") ?? [];
        $allAssign   = $this->firebase->get("{$base}/Assignments") ?? [];
        $allQuizzes  = $this->firebase->get("{$base}/Quizzes") ?? [];

        if (!is_array($allClasses))  $allClasses  = [];
        if (!is_array($allMats))     $allMats     = [];
        if (!is_array($allAssign))   $allAssign   = [];
        if (!is_array($allQuizzes))  $allQuizzes  = [];

        // Filter for teachers
        if ($role === 'Teacher') {
            $allClasses = array_filter($allClasses, fn($c) => is_array($c) && ($c['teacherId'] ?? '') === $tid);
            $allMats    = array_filter($allMats, fn($m) => is_array($m) && ($m['teacherId'] ?? '') === $tid);
            $allAssign  = array_filter($allAssign, fn($a) => is_array($a) && ($a['teacherId'] ?? '') === $tid);
            $allQuizzes = array_filter($allQuizzes, fn($q) => is_array($q) && ($q['teacherId'] ?? '') === $tid);
        }

        // Upcoming classes (next 7 days)
        $upcoming = [];
        $now = date('Y-m-d');
        $weekLater = date('Y-m-d', strtotime('+7 days'));
        foreach ($allClasses as $id => $cls) {
            if (!is_array($cls)) continue;
            $d = $cls['date'] ?? '';
            if ($d >= $now && $d <= $weekLater) {
                $cls['id'] = $id;
                $upcoming[] = $cls;
            }
        }
        usort($upcoming, fn($a, $b) => strcmp(($a['date'] ?? '') . ($a['time'] ?? ''), ($b['date'] ?? '') . ($b['time'] ?? '')));
        $upcoming = array_slice($upcoming, 0, 10);

        // Pending assignments count
        $pendingAssignments = 0;
        foreach ($allAssign as $a) {
            if (!is_array($a)) continue;
            $due = $a['dueDate'] ?? '';
            if ($due >= $now && ($a['status'] ?? 'active') === 'active') {
                $pendingAssignments++;
            }
        }

        $this->json_success([
            'totalClasses'      => count($allClasses),
            'totalMaterials'    => count($allMats),
            'totalAssignments'  => count($allAssign),
            'totalQuizzes'      => count($allQuizzes),
            'upcomingClasses'   => $upcoming,
            'pendingAssignments'=> $pendingAssignments,
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       ONLINE CLASSES
    ══════════════════════════════════════════════════════════════════════ */

    public function get_classes()
    {
        $this->_require_role(self::VIEW_ROLES, 'lms_classes');

        $all = $this->firebase->get("{$this->_lmsBase}/Classes") ?? [];
        if (!is_array($all)) $all = [];

        $role = $this->admin_role ?? '';
        $tid  = $this->admin_id;
        $rows = [];

        foreach ($all as $id => $cls) {
            if (!is_array($cls)) continue;
            if ($role === 'Teacher' && ($cls['teacherId'] ?? '') !== $tid) continue;
            $cls['id'] = $id;
            $rows[] = $cls;
        }

        usort($rows, function($a, $b) {
            $cmp = strcmp($b['date'] ?? '', $a['date'] ?? '');
            return $cmp !== 0 ? $cmp : strcmp($b['time'] ?? '', $a['time'] ?? '');
        });

        $this->json_success(['classes' => $rows]);
    }

    public function save_class()
    {
        $this->_require_role(self::MANAGE_ROLES, 'lms_save_class');

        $id          = $this->input->post('id');
        $title       = trim($this->input->post('title') ?? '');
        $subject     = trim($this->input->post('subject') ?? '');
        $classKey    = trim($this->input->post('classKey') ?? '');
        $sectionKey  = trim($this->input->post('sectionKey') ?? '');
        $date        = trim($this->input->post('date') ?? '');
        $time        = trim($this->input->post('time') ?? '');
        $duration    = (int)($this->input->post('duration') ?? 60);
        $meetLink    = trim($this->input->post('meetLink') ?? '');
        $description = trim($this->input->post('description') ?? '');
        $status      = trim($this->input->post('status') ?? 'scheduled');

        if ($title === '' || $classKey === '' || $date === '') {
            $this->json_error('Title, class, and date are required.');
        }

        // Validate meetLink — reject dangerous protocols
        if ($meetLink !== '') {
            $meetLink = $this->_validate_url($meetLink);
        }

        $record = [
            'title'       => $title,
            'subject'     => $subject,
            'classKey'    => $classKey,
            'sectionKey'  => $sectionKey,
            'date'        => $date,
            'time'        => $time,
            'duration'    => $duration,
            'meetLink'    => $meetLink,
            'description' => $description,
            'status'      => $status,
            'teacherId'   => $this->admin_id,
            'teacherName' => $this->session->userdata('admin_name') ?? '',
            'updatedAt'   => date('Y-m-d H:i:s'),
        ];

        $base = "{$this->_lmsBase}/Classes";

        if ($id) {
            $id = $this->safe_path_segment($id, 'classId');
            if (($this->admin_role ?? '') === 'Teacher') {
                $existing = $this->firebase->get("{$base}/{$id}");
                if (!is_array($existing) || ($existing['teacherId'] ?? '') !== $this->admin_id) {
                    $this->json_error('You can only edit your own classes.', 403);
                }
            }
            $this->firebase->update("{$base}/{$id}", $record);
        } else {
            $record['createdAt'] = date('Y-m-d H:i:s');
            $id = $this->firebase->push($base, $record);
        }

        $this->json_success(['id' => $id]);
    }

    public function delete_class()
    {
        $this->_require_role(self::MANAGE_ROLES, 'lms_delete_class');

        $id = $this->input->post('id');
        if (!$id) $this->json_error('Class ID required.');
        $id = $this->safe_path_segment($id, 'classId');

        $base = "{$this->_lmsBase}/Classes";

        if (($this->admin_role ?? '') === 'Teacher') {
            $existing = $this->firebase->get("{$base}/{$id}");
            if (!is_array($existing) || ($existing['teacherId'] ?? '') !== $this->admin_id) {
                $this->json_error('You can only delete your own classes.', 403);
            }
        }

        $this->firebase->delete($base, $id);
        $this->json_success();
    }

    /* ══════════════════════════════════════════════════════════════════════
       STUDY MATERIALS
    ══════════════════════════════════════════════════════════════════════ */

    public function get_materials()
    {
        $this->_require_role(self::VIEW_ROLES, 'lms_materials');

        $all = $this->firebase->get("{$this->_lmsBase}/Materials") ?? [];
        if (!is_array($all)) $all = [];

        $role = $this->admin_role ?? '';
        $tid  = $this->admin_id;
        $rows = [];

        foreach ($all as $id => $mat) {
            if (!is_array($mat)) continue;
            if ($role === 'Teacher' && ($mat['teacherId'] ?? '') !== $tid) continue;
            $mat['id'] = $id;
            $rows[] = $mat;
        }

        usort($rows, fn($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));

        $this->json_success(['materials' => $rows]);
    }

    public function save_material()
    {
        $this->_require_role(self::MANAGE_ROLES, 'lms_save_material');

        $id          = $this->input->post('id');
        $title       = trim($this->input->post('title') ?? '');
        $subject     = trim($this->input->post('subject') ?? '');
        $classKey    = trim($this->input->post('classKey') ?? '');
        $sectionKey  = trim($this->input->post('sectionKey') ?? '');
        $type        = trim($this->input->post('type') ?? 'document');
        $url         = trim($this->input->post('url') ?? '');
        $description = trim($this->input->post('description') ?? '');

        if ($title === '' || $classKey === '') {
            $this->json_error('Title and class are required.');
        }

        // Validate type
        $validTypes = ['document', 'video', 'link', 'image', 'presentation'];
        if (!in_array($type, $validTypes, true)) $type = 'document';

        // Validate URL — reject dangerous protocols
        if ($url !== '') {
            $url = $this->_validate_url($url);
        }

        $record = [
            'title'       => $title,
            'subject'     => $subject,
            'classKey'    => $classKey,
            'sectionKey'  => $sectionKey,
            'type'        => $type,
            'url'         => $url,
            'description' => $description,
            'teacherId'   => $this->admin_id,
            'teacherName' => $this->session->userdata('admin_name') ?? '',
            'updatedAt'   => date('Y-m-d H:i:s'),
        ];

        $base = "{$this->_lmsBase}/Materials";

        if ($id) {
            $id = $this->safe_path_segment($id, 'materialId');
            if (($this->admin_role ?? '') === 'Teacher') {
                $existing = $this->firebase->get("{$base}/{$id}");
                if (!is_array($existing) || ($existing['teacherId'] ?? '') !== $this->admin_id) {
                    $this->json_error('You can only edit your own materials.', 403);
                }
            }
            $this->firebase->update("{$base}/{$id}", $record);
        } else {
            $record['createdAt'] = date('Y-m-d H:i:s');
            $id = $this->firebase->push($base, $record);
        }

        $this->json_success(['id' => $id]);
    }

    public function delete_material()
    {
        $this->_require_role(self::MANAGE_ROLES, 'lms_delete_material');

        $id = $this->input->post('id');
        if (!$id) $this->json_error('Material ID required.');
        $id = $this->safe_path_segment($id, 'materialId');

        $base = "{$this->_lmsBase}/Materials";

        if (($this->admin_role ?? '') === 'Teacher') {
            $existing = $this->firebase->get("{$base}/{$id}");
            if (!is_array($existing) || ($existing['teacherId'] ?? '') !== $this->admin_id) {
                $this->json_error('You can only delete your own materials.', 403);
            }
        }

        $this->firebase->delete($base, $id);
        $this->json_success();
    }

    /* ══════════════════════════════════════════════════════════════════════
       ASSIGNMENTS
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * GET — list assignments with submission counts read from stored counter
     * Fixed: no longer does O(N) shallow_get per assignment
     */
    public function get_assignments()
    {
        $this->_require_role(self::VIEW_ROLES, 'lms_assignments');

        $all = $this->firebase->get("{$this->_lmsBase}/Assignments") ?? [];
        if (!is_array($all)) $all = [];

        $role = $this->admin_role ?? '';
        $tid  = $this->admin_id;
        $rows = [];

        foreach ($all as $id => $a) {
            if (!is_array($a)) continue;
            if ($role === 'Teacher' && ($a['teacherId'] ?? '') !== $tid) continue;
            $a['id'] = $id;
            // Use stored counter instead of per-item shallow_get
            $a['submissionCount'] = (int)($a['_submissionCount'] ?? 0);
            $rows[] = $a;
        }

        usort($rows, fn($a, $b) => strcmp($b['dueDate'] ?? '', $a['dueDate'] ?? ''));

        $this->json_success(['assignments' => $rows]);
    }

    public function save_assignment()
    {
        $this->_require_role(self::MANAGE_ROLES, 'lms_save_assignment');

        $id          = $this->input->post('id');
        $title       = trim($this->input->post('title') ?? '');
        $subject     = trim($this->input->post('subject') ?? '');
        $classKey    = trim($this->input->post('classKey') ?? '');
        $sectionKey  = trim($this->input->post('sectionKey') ?? '');
        $description = trim($this->input->post('description') ?? '');
        $dueDate     = trim($this->input->post('dueDate') ?? '');
        $maxMarks    = (int)($this->input->post('maxMarks') ?? 100);
        $attachUrl   = trim($this->input->post('attachUrl') ?? '');
        $status      = trim($this->input->post('status') ?? 'active');

        if ($title === '' || $classKey === '' || $dueDate === '') {
            $this->json_error('Title, class, and due date are required.');
        }

        // Validate attachment URL — reject dangerous protocols
        if ($attachUrl !== '') {
            $attachUrl = $this->_validate_url($attachUrl);
        }

        $record = [
            'title'       => $title,
            'subject'     => $subject,
            'classKey'    => $classKey,
            'sectionKey'  => $sectionKey,
            'description' => $description,
            'dueDate'     => $dueDate,
            'maxMarks'    => $maxMarks,
            'attachUrl'   => $attachUrl,
            'status'      => $status,
            'teacherId'   => $this->admin_id,
            'teacherName' => $this->session->userdata('admin_name') ?? '',
            'updatedAt'   => date('Y-m-d H:i:s'),
        ];

        $base = "{$this->_lmsBase}/Assignments";

        if ($id) {
            $id = $this->safe_path_segment($id, 'assignmentId');
            if (($this->admin_role ?? '') === 'Teacher') {
                $existing = $this->firebase->get("{$base}/{$id}");
                if (!is_array($existing) || ($existing['teacherId'] ?? '') !== $this->admin_id) {
                    $this->json_error('You can only edit your own assignments.', 403);
                }
            }
            $this->firebase->update("{$base}/{$id}", $record);
        } else {
            $record['_submissionCount'] = 0;
            $record['createdAt'] = date('Y-m-d H:i:s');
            $id = $this->firebase->push($base, $record);

            // Fire communication event
            try {
                $this->load->library('Communication_helper');
                $this->communication_helper->init($this->firebase, $this->school_name, $this->session_year, $this->parent_db_key, $this->fs, $this->school_id);
                $this->communication_helper->fire_event('assignment_created', [
                    'title'      => $title,
                    'subject'    => $subject,
                    'classKey'   => $classKey,
                    'sectionKey' => $sectionKey,
                    'dueDate'    => $dueDate,
                    'teacher'    => $record['teacherName'],
                ]);
            } catch (\Exception $e) {
                log_message('error', 'LMS comm event failed: ' . $e->getMessage());
            }
        }

        $this->json_success(['id' => $id]);
    }

    public function delete_assignment()
    {
        $this->_require_role(self::MANAGE_ROLES, 'lms_delete_assignment');

        $id = $this->input->post('id');
        if (!$id) $this->json_error('Assignment ID required.');
        $id = $this->safe_path_segment($id, 'assignmentId');

        $base = "{$this->_lmsBase}/Assignments";

        if (($this->admin_role ?? '') === 'Teacher') {
            $existing = $this->firebase->get("{$base}/{$id}");
            if (!is_array($existing) || ($existing['teacherId'] ?? '') !== $this->admin_id) {
                $this->json_error('You can only delete your own assignments.', 403);
            }
        }

        $this->firebase->delete($base, $id);
        $this->firebase->delete("{$this->_lmsBase}/Submissions", $id);
        $this->json_success();
    }

    /**
     * GET — view submissions for an assignment
     * Fixed: assignment read cached; path injection protected
     */
    public function get_submissions()
    {
        $this->_require_role(self::VIEW_ROLES, 'lms_submissions');

        $assignmentId = $this->input->get('assignmentId');
        if (!$assignmentId) $this->json_error('Assignment ID required.');
        $assignmentId = $this->safe_path_segment($assignmentId, 'assignmentId');

        // Single read — cached for reuse below
        $assign = $this->firebase->get("{$this->_lmsBase}/Assignments/{$assignmentId}");
        if (!is_array($assign)) $this->json_error('Assignment not found.', 404);

        // Verify ownership for teachers
        if (($this->admin_role ?? '') === 'Teacher') {
            if (($assign['teacherId'] ?? '') !== $this->admin_id) {
                $this->json_error('Access denied.', 403);
            }
        }

        $subs = $this->firebase->get("{$this->_lmsBase}/Submissions/{$assignmentId}") ?? [];
        if (!is_array($subs)) $subs = [];

        // Enrich with student names from roster
        $classKey   = $assign['classKey'] ?? '';
        $sectionKey = $assign['sectionKey'] ?? '';
        $roster = [];
        if ($classKey && $sectionKey) {
            $secLetter = str_replace('Section ', '', $sectionKey);
            $rosterPath = "Schools/{$this->school_name}/{$this->session_year}/{$classKey}/Section {$secLetter}/Students/List";
            $roster = $this->firebase->get($rosterPath) ?? [];
            if (!is_array($roster)) $roster = [];
        }

        $rows = [];
        foreach ($subs as $studentId => $sub) {
            if (!is_array($sub)) continue;
            $sub['studentId'] = $studentId;
            $sub['studentName'] = $roster[$studentId]['Name'] ?? $sub['studentName'] ?? $studentId;
            $rows[] = $sub;
        }

        usort($rows, fn($a, $b) => strcmp($b['submittedAt'] ?? '', $a['submittedAt'] ?? ''));

        $this->json_success([
            'submissions' => $rows,
            'assignment'  => $assign,
        ]);
    }

    /**
     * POST — grade a submission
     * Fixed: path injection; fires assignment_graded event
     */
    public function grade_submission()
    {
        $this->_require_role(self::MANAGE_ROLES, 'lms_grade');

        $assignmentId = $this->input->post('assignmentId');
        $studentId    = $this->input->post('studentId');
        $marks        = $this->input->post('marks');
        $feedback     = trim($this->input->post('feedback') ?? '');

        if (!$assignmentId || !$studentId || $marks === null) {
            $this->json_error('Assignment ID, student ID, and marks are required.');
        }

        $assignmentId = $this->safe_path_segment($assignmentId, 'assignmentId');
        $studentId    = $this->safe_path_segment($studentId, 'studentId');

        // Verify ownership for teachers
        $assign = $this->firebase->get("{$this->_lmsBase}/Assignments/{$assignmentId}");
        if (!is_array($assign)) $this->json_error('Assignment not found.', 404);

        if (($this->admin_role ?? '') === 'Teacher') {
            if (($assign['teacherId'] ?? '') !== $this->admin_id) {
                $this->json_error('Access denied.', 403);
            }
        }

        // Validate marks upper bound
        $maxMarks = (float)($assign['maxMarks'] ?? 100);
        $marksVal = (float)$marks;
        if ($marksVal < 0 || $marksVal > $maxMarks) {
            $this->json_error("Marks must be between 0 and {$maxMarks}.");
        }

        $this->firebase->update("{$this->_lmsBase}/Submissions/{$assignmentId}/{$studentId}", [
            'marks'    => $marksVal,
            'feedback' => $feedback,
            'gradedAt' => date('Y-m-d H:i:s'),
            'gradedBy' => $this->admin_id,
            'status'   => 'graded',
        ]);

        // Fire assignment_graded communication event
        try {
            $this->load->library('Communication_helper');
            $this->communication_helper->init($this->firebase, $this->school_name, $this->session_year, $this->parent_db_key, $this->fs, $this->school_id);
            $this->communication_helper->fire_event('assignment_graded', [
                'student_id'    => $studentId,
                'assignment_id' => $assignmentId,
                'title'         => $assign['title'] ?? '',
                'subject'       => $assign['subject'] ?? '',
                'marks'         => $marksVal,
                'maxMarks'      => $maxMarks,
                'teacher'       => $this->session->userdata('admin_name') ?? '',
            ]);
        } catch (\Exception $e) {
            log_message('error', 'LMS grade comm event failed: ' . $e->getMessage());
        }

        $this->json_success();
    }

    /**
     * POST — submit an assignment on behalf of a student
     * (students don't have login; teacher/admin records submission)
     */
    public function submit_assignment()
    {
        $this->_require_role(self::MANAGE_ROLES, 'lms_submit_assignment');

        $assignmentId = $this->input->post('assignmentId');
        $studentId    = $this->input->post('studentId');
        $fileUrl      = trim($this->input->post('fileUrl') ?? '');

        if (!$assignmentId || !$studentId) {
            $this->json_error('Assignment ID and student ID are required.');
        }

        $assignmentId = $this->safe_path_segment($assignmentId, 'assignmentId');
        $studentId    = $this->safe_path_segment($studentId, 'studentId');

        // Load assignment
        $assign = $this->firebase->get("{$this->_lmsBase}/Assignments/{$assignmentId}");
        if (!is_array($assign)) $this->json_error('Assignment not found.', 404);

        // Reject if closed
        if (($assign['status'] ?? '') === 'closed') {
            $this->json_error('This assignment is closed and no longer accepts submissions.');
        }

        // Verify teacher ownership
        if (($this->admin_role ?? '') === 'Teacher') {
            if (($assign['teacherId'] ?? '') !== $this->admin_id) {
                $this->json_error('Access denied.', 403);
            }
        }

        // Check if student already submitted
        $existingSub = $this->firebase->get("{$this->_lmsBase}/Submissions/{$assignmentId}/{$studentId}");
        if (is_array($existingSub) && !empty($existingSub)) {
            $this->json_error('This student has already submitted. Delete existing submission first to resubmit.');
        }

        // Validate student is enrolled in the assignment's class/section
        $classKey   = $assign['classKey'] ?? '';
        $sectionKey = $assign['sectionKey'] ?? '';
        if ($classKey && $sectionKey) {
            $secLetter  = str_replace('Section ', '', $sectionKey);
            $rosterPath = "Schools/{$this->school_name}/{$this->session_year}/{$classKey}/Section {$secLetter}/Students/List/{$studentId}";
            $enrolled   = $this->firebase->get($rosterPath);
            if (!is_array($enrolled)) {
                $this->json_error('Student is not enrolled in this class/section.');
            }
            $studentName = $enrolled['Name'] ?? $studentId;
        } else {
            $studentName = $studentId;
        }

        // Save submission
        $submission = [
            'fileUrl'     => $fileUrl,
            'studentName' => $studentName,
            'submittedAt' => date('Y-m-d H:i:s'),
            'status'      => 'submitted',
            'marks'       => null,
            'feedback'    => null,
            'recordedBy'  => $this->admin_id,
        ];

        $this->firebase->set(
            "{$this->_lmsBase}/Submissions/{$assignmentId}/{$studentId}",
            $submission
        );

        // Increment submission counter on assignment
        $count = (int)($assign['_submissionCount'] ?? 0) + 1;
        $this->firebase->update("{$this->_lmsBase}/Assignments/{$assignmentId}", [
            '_submissionCount' => $count,
        ]);

        $this->json_success(['studentName' => $studentName]);
    }

    /**
     * POST — delete a student's submission (allows resubmission)
     * Single Firebase read for assignment — reused for ownership check + counter update.
     */
    public function delete_submission()
    {
        $this->_require_role(self::MANAGE_ROLES, 'lms_delete_submission');

        $assignmentId = $this->input->post('assignmentId');
        $studentId    = $this->input->post('studentId');

        if (!$assignmentId || !$studentId) {
            $this->json_error('Assignment ID and student ID are required.');
        }

        $assignmentId = $this->safe_path_segment($assignmentId, 'assignmentId');
        $studentId    = $this->safe_path_segment($studentId, 'studentId');

        // Single read — used for both ownership check and counter update
        $assign = $this->firebase->get("{$this->_lmsBase}/Assignments/{$assignmentId}");
        if (!is_array($assign)) {
            $this->json_error('Assignment not found.', 404);
        }

        // Verify ownership for teachers
        if (($this->admin_role ?? '') === 'Teacher') {
            if (($assign['teacherId'] ?? '') !== $this->admin_id) {
                $this->json_error('Access denied.', 403);
            }
        }

        // Delete submission first
        $this->firebase->delete("{$this->_lmsBase}/Submissions/{$assignmentId}", $studentId);

        // Decrement counter only after successful delete
        $count = max(0, (int)($assign['_submissionCount'] ?? 0) - 1);
        $this->firebase->update("{$this->_lmsBase}/Assignments/{$assignmentId}", [
            '_submissionCount' => $count,
        ]);

        $this->json_success();
    }

    /* ══════════════════════════════════════════════════════════════════════
       QUIZZES
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * GET — list quizzes with attempt counts from stored counter
     * Fixed: no longer does O(N) shallow_get per quiz
     */
    public function get_quizzes()
    {
        $this->_require_role(self::VIEW_ROLES, 'lms_quizzes');

        $all = $this->firebase->get("{$this->_lmsBase}/Quizzes") ?? [];
        if (!is_array($all)) $all = [];

        $role = $this->admin_role ?? '';
        $tid  = $this->admin_id;
        $rows = [];

        foreach ($all as $id => $q) {
            if (!is_array($q)) continue;
            if ($role === 'Teacher' && ($q['teacherId'] ?? '') !== $tid) continue;
            $q['id'] = $id;
            // Use stored counter instead of per-item shallow_get
            $q['attemptCount'] = (int)($q['_attemptCount'] ?? 0);
            $rows[] = $q;
        }

        usort($rows, fn($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));

        $this->json_success(['quizzes' => $rows]);
    }

    /**
     * GET — single quiz detail with questions (teacher view — includes correctIndex)
     * Fixed: path injection protection
     */
    public function get_quiz()
    {
        $this->_require_role(self::VIEW_ROLES, 'lms_quiz_detail');

        $quizId = $this->input->get('id');
        if (!$quizId) $this->json_error('Quiz ID required.');
        $quizId = $this->safe_path_segment($quizId, 'quizId');

        $quiz = $this->firebase->get("{$this->_lmsBase}/Quizzes/{$quizId}");
        if (!is_array($quiz)) $this->json_error('Quiz not found.', 404);

        if (($this->admin_role ?? '') === 'Teacher' && ($quiz['teacherId'] ?? '') !== $this->admin_id) {
            $this->json_error('Access denied.', 403);
        }

        $quiz['id'] = $quizId;
        $this->json_success(['quiz' => $quiz]);
    }

    /**
     * GET — quiz for student attempt (strips correctIndex from questions)
     */
    public function get_student_quiz()
    {
        $this->_require_role(self::VIEW_ROLES, 'lms_student_quiz');

        $quizId = $this->input->get('id');
        if (!$quizId) $this->json_error('Quiz ID required.');
        $quizId = $this->safe_path_segment($quizId, 'quizId');

        $quiz = $this->firebase->get("{$this->_lmsBase}/Quizzes/{$quizId}");
        if (!is_array($quiz)) $this->json_error('Quiz not found.', 404);

        if (($quiz['status'] ?? '') !== 'active') {
            $this->json_error('This quiz is not active.');
        }

        // Strip correct answers — student must not see them
        $safeQuestions = [];
        foreach (($quiz['questions'] ?? []) as $q) {
            $safeQuestions[] = [
                'question' => $q['question'] ?? '',
                'options'  => $q['options'] ?? [],
                'marks'    => $q['marks'] ?? 1,
            ];
        }

        $this->json_success([
            'quiz' => [
                'id'          => $quizId,
                'title'       => $quiz['title'] ?? '',
                'subject'     => $quiz['subject'] ?? '',
                'classKey'    => $quiz['classKey'] ?? '',
                'sectionKey'  => $quiz['sectionKey'] ?? '',
                'duration'    => $quiz['duration'] ?? 30,
                'maxMarks'    => $quiz['maxMarks'] ?? 0,
                'description' => $quiz['description'] ?? '',
                'questions'   => $safeQuestions,
            ],
        ]);
    }

    public function save_quiz()
    {
        $this->_require_role(self::MANAGE_ROLES, 'lms_save_quiz');

        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true);
        if (!is_array($input)) {
            $this->json_error('Invalid JSON payload.');
        }

        // Manual CSRF check for JSON body
        $csrfName  = $this->security->get_csrf_token_name();
        $csrfValue = $input[$csrfName] ?? '';
        if (!$csrfValue || $csrfValue !== $this->security->get_csrf_hash()) {
            $this->json_error('CSRF token mismatch.', 403);
        }

        $id          = $input['id'] ?? null;
        $title       = trim($input['title'] ?? '');
        $subject     = trim($input['subject'] ?? '');
        $classKey    = trim($input['classKey'] ?? '');
        $sectionKey  = trim($input['sectionKey'] ?? '');
        $duration    = (int)($input['duration'] ?? 30);
        $questions   = $input['questions'] ?? [];
        $status      = trim($input['status'] ?? 'draft');
        $description = trim($input['description'] ?? '');
        $attemptLimit = (int)($input['attemptLimit'] ?? 1);

        if ($title === '' || $classKey === '' || empty($questions)) {
            $this->json_error('Title, class, and at least one question are required.');
        }

        // Validate questions
        $cleanQuestions = [];
        foreach ($questions as $i => $q) {
            if (!is_array($q)) continue;
            $qText = trim($q['question'] ?? '');
            if ($qText === '') continue;

            $options = $q['options'] ?? [];
            if (!is_array($options) || count($options) < 2) {
                $this->json_error("Question " . ($i + 1) . " must have at least 2 options.");
            }

            $correctIdx = (int)($q['correctIndex'] ?? 0);
            $marks      = (float)($q['marks'] ?? 1);

            $cleanQuestions[] = [
                'question'     => $qText,
                'options'      => array_values($options),
                'correctIndex' => $correctIdx,
                'marks'        => $marks,
            ];
        }

        if (empty($cleanQuestions)) {
            $this->json_error('At least one valid question is required.');
        }

        $maxMarks = array_sum(array_column($cleanQuestions, 'marks'));

        $record = [
            'title'        => $title,
            'subject'      => $subject,
            'classKey'     => $classKey,
            'sectionKey'   => $sectionKey,
            'duration'     => $duration,
            'maxMarks'     => $maxMarks,
            'questions'    => $cleanQuestions,
            'status'       => $status,
            'description'  => $description,
            'attemptLimit' => max(1, $attemptLimit),
            'teacherId'    => $this->admin_id,
            'teacherName'  => $this->session->userdata('admin_name') ?? '',
            'updatedAt'    => date('Y-m-d H:i:s'),
        ];

        $base = "{$this->_lmsBase}/Quizzes";

        if ($id) {
            $id = $this->safe_path_segment($id, 'quizId');
            if (($this->admin_role ?? '') === 'Teacher') {
                $existing = $this->firebase->get("{$base}/{$id}");
                if (!is_array($existing) || ($existing['teacherId'] ?? '') !== $this->admin_id) {
                    $this->json_error('You can only edit your own quizzes.', 403);
                }
            }
            $this->firebase->update("{$base}/{$id}", $record);
        } else {
            $record['_attemptCount'] = 0;
            $record['createdAt'] = date('Y-m-d H:i:s');
            $id = $this->firebase->push($base, $record);
        }

        $this->json_success(['id' => $id]);
    }

    public function delete_quiz()
    {
        $this->_require_role(self::MANAGE_ROLES, 'lms_delete_quiz');

        $id = $this->input->post('id');
        if (!$id) $this->json_error('Quiz ID required.');
        $id = $this->safe_path_segment($id, 'quizId');

        $base = "{$this->_lmsBase}/Quizzes";

        if (($this->admin_role ?? '') === 'Teacher') {
            $existing = $this->firebase->get("{$base}/{$id}");
            if (!is_array($existing) || ($existing['teacherId'] ?? '') !== $this->admin_id) {
                $this->json_error('You can only delete your own quizzes.', 403);
            }
        }

        $this->firebase->delete($base, $id);
        $this->firebase->delete("{$this->_lmsBase}/QuizAttempts", $id);
        $this->json_success();
    }

    /**
     * GET — view quiz attempts/results
     * Supports new multi-attempt structure: QuizAttempts/{quizId}/{studentId}/{attemptId}
     * Also handles legacy single-attempt format: QuizAttempts/{quizId}/{studentId} = {score:…}
     */
    public function get_quiz_attempts()
    {
        $this->_require_role(self::VIEW_ROLES, 'lms_quiz_attempts');

        $quizId = $this->input->get('quizId');
        if (!$quizId) $this->json_error('Quiz ID required.');
        $quizId = $this->safe_path_segment($quizId, 'quizId');

        // Verify ownership for teachers
        if (($this->admin_role ?? '') === 'Teacher') {
            $quiz = $this->firebase->get("{$this->_lmsBase}/Quizzes/{$quizId}");
            if (!is_array($quiz) || ($quiz['teacherId'] ?? '') !== $this->admin_id) {
                $this->json_error('Access denied.', 403);
            }
        }

        $allAttempts = $this->firebase->get("{$this->_lmsBase}/QuizAttempts/{$quizId}") ?? [];
        if (!is_array($allAttempts)) $allAttempts = [];

        $rows = [];
        foreach ($allAttempts as $studentId => $studentData) {
            if (!is_array($studentData)) continue;

            if (isset($studentData['score'])) {
                // Legacy single-attempt format
                $studentData['studentId'] = $studentId;
                $studentData['attemptNum'] = 1;
                $rows[] = $studentData;
            } else {
                // New multi-attempt format: each child is an attempt
                foreach ($studentData as $attemptId => $att) {
                    if (!is_array($att)) continue;
                    $att['studentId'] = $studentId;
                    $att['attemptId'] = $attemptId;
                    $rows[] = $att;
                }
            }
        }

        usort($rows, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        $this->json_success(['attempts' => $rows]);
    }

    /**
     * POST — submit a quiz attempt on behalf of a student (auto-graded)
     * Attempts stored at QuizAttempts/{quizId}/{studentId}/{attemptId} to support multiple attempts.
     */
    public function submit_quiz_attempt()
    {
        $this->_require_role(self::MANAGE_ROLES, 'lms_submit_quiz');

        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true);
        if (!is_array($input)) {
            $this->json_error('Invalid JSON payload.');
        }

        // Manual CSRF for JSON body
        $csrfName  = $this->security->get_csrf_token_name();
        $csrfValue = $input[$csrfName] ?? '';
        if (!$csrfValue || $csrfValue !== $this->security->get_csrf_hash()) {
            $this->json_error('CSRF token mismatch.', 403);
        }

        $quizId    = trim($input['quizId'] ?? '');
        $studentId = trim($input['studentId'] ?? '');
        $answers   = $input['answers'] ?? [];
        $startedAt = trim($input['startedAt'] ?? date('Y-m-d H:i:s'));

        if (!$quizId || !$studentId) {
            $this->json_error('Quiz ID and student ID are required.');
        }
        if (!is_array($answers)) {
            $this->json_error('Answers must be an array.');
        }

        $quizId    = $this->safe_path_segment($quizId, 'quizId');
        $studentId = $this->safe_path_segment($studentId, 'studentId');

        // Load quiz
        $quiz = $this->firebase->get("{$this->_lmsBase}/Quizzes/{$quizId}");
        if (!is_array($quiz)) $this->json_error('Quiz not found.', 404);

        if (($quiz['status'] ?? '') !== 'active') {
            $this->json_error('This quiz is not active.');
        }

        // Verify teacher ownership
        if (($this->admin_role ?? '') === 'Teacher') {
            if (($quiz['teacherId'] ?? '') !== $this->admin_id) {
                $this->json_error('Access denied.', 403);
            }
        }

        // Check attempt limit — count existing attempts under the student node
        $attemptLimit    = (int)($quiz['attemptLimit'] ?? 1);
        $studentAttempts = $this->firebase->get("{$this->_lmsBase}/QuizAttempts/{$quizId}/{$studentId}") ?? [];
        if (!is_array($studentAttempts)) $studentAttempts = [];

        // Migrate legacy single-attempt format: {score:…} → {ATT001: {score:…}}
        if (isset($studentAttempts['score'])) {
            $legacyAttempt = $studentAttempts;
            $legacyAttempt['attemptId'] = 'ATT001';
            $this->firebase->set(
                "{$this->_lmsBase}/QuizAttempts/{$quizId}/{$studentId}",
                ['ATT001' => $legacyAttempt]
            );
            $studentAttempts = ['ATT001' => $legacyAttempt];
        }

        $existingCount = count($studentAttempts);
        if ($existingCount >= $attemptLimit) {
            $this->json_error("This student has reached the attempt limit ({$attemptLimit}).");
        }

        // Auto-grade: compare answers with correctIndex
        $questions = $quiz['questions'] ?? [];
        $score = 0;
        $totalMarks = 0;
        $gradedAnswers = [];

        foreach ($questions as $i => $q) {
            $qMarks    = (float)($q['marks'] ?? 1);
            $totalMarks += $qMarks;
            $correct   = (int)($q['correctIndex'] ?? 0);
            $given     = isset($answers[$i]) ? (int)$answers[$i] : -1;
            $isCorrect = ($given === $correct);
            if ($isCorrect) $score += $qMarks;
            $gradedAnswers[] = $given;
        }

        // Look up student name from roster
        $classKey   = $quiz['classKey'] ?? '';
        $sectionKey = $quiz['sectionKey'] ?? '';
        $studentName = $studentId;
        if ($classKey && $sectionKey) {
            $secLetter = str_replace('Section ', '', $sectionKey);
            $rosterPath = "Schools/{$this->school_name}/{$this->session_year}/{$classKey}/Section {$secLetter}/Students/List/{$studentId}";
            $profile = $this->firebase->get($rosterPath);
            if (is_array($profile)) {
                $studentName = $profile['Name'] ?? $studentId;
            }
        }

        // Generate attempt ID
        $attemptNum = $existingCount + 1;
        $attemptId  = 'ATT' . str_pad($attemptNum, 3, '0', STR_PAD_LEFT);

        // Save attempt under student node (does not overwrite previous attempts)
        $attempt = [
            'attemptId'   => $attemptId,
            'answers'     => $gradedAnswers,
            'score'       => $score,
            'totalMarks'  => $totalMarks,
            'startedAt'   => $startedAt,
            'completedAt' => date('Y-m-d H:i:s'),
            'studentName' => $studentName,
            'recordedBy'  => $this->admin_id,
        ];

        $this->firebase->set(
            "{$this->_lmsBase}/QuizAttempts/{$quizId}/{$studentId}/{$attemptId}",
            $attempt
        );

        // Increment attempt counter only after successful write
        $count = (int)($quiz['_attemptCount'] ?? 0) + 1;
        $this->firebase->update("{$this->_lmsBase}/Quizzes/{$quizId}", [
            '_attemptCount' => $count,
        ]);

        // Fire quiz_result communication event
        try {
            $this->load->library('Communication_helper');
            $this->communication_helper->init($this->firebase, $this->school_name, $this->session_year, $this->parent_db_key, $this->fs, $this->school_id);
            $this->communication_helper->fire_event('quiz_result', [
                'student_id'  => $studentId,
                'student_name'=> $studentName,
                'quiz_id'     => $quizId,
                'title'       => $quiz['title'] ?? '',
                'subject'     => $quiz['subject'] ?? '',
                'score'       => $score,
                'totalMarks'  => $totalMarks,
                'classKey'    => $classKey,
                'sectionKey'  => $sectionKey,
            ]);
        } catch (\Exception $e) {
            log_message('error', 'LMS quiz result comm event failed: ' . $e->getMessage());
        }

        $this->json_success([
            'score'      => $score,
            'totalMarks' => $totalMarks,
            'studentName'=> $studentName,
            'attemptId'  => $attemptId,
            'attemptNum' => $attemptNum,
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       STUDENT VIEW ENDPOINTS
       Teacher/admin selects a class+section to see student perspective.
       Returns only content matching that class+section.
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * GET — list online classes for a specific class/section (student view)
     * Params: classKey, sectionKey
     */
    public function get_student_classes()
    {
        $this->_require_role(self::VIEW_ROLES, 'lms_student_view');

        $classKey   = $this->input->get('classKey') ?? '';
        $sectionKey = $this->input->get('sectionKey') ?? '';
        if (!$classKey) $this->json_error('classKey is required.');

        $all = $this->firebase->get("{$this->_lmsBase}/Classes") ?? [];
        if (!is_array($all)) $all = [];

        $rows = [];
        foreach ($all as $id => $cls) {
            if (!is_array($cls)) continue;
            if (($cls['classKey'] ?? '') !== $classKey) continue;
            if ($sectionKey && ($cls['sectionKey'] ?? '') !== $sectionKey && ($cls['sectionKey'] ?? '') !== '') continue;
            // Students only see scheduled or live classes
            $st = $cls['status'] ?? 'scheduled';
            if ($st === 'cancelled') continue;
            $cls['id'] = $id;
            $rows[] = $cls;
        }

        usort($rows, fn($a, $b) => strcmp(($a['date'] ?? '') . ($a['time'] ?? ''), ($b['date'] ?? '') . ($b['time'] ?? '')));

        $this->json_success(['classes' => $rows]);
    }

    /**
     * GET — list materials for a specific class/section (student view)
     */
    public function get_student_materials()
    {
        $this->_require_role(self::VIEW_ROLES, 'lms_student_view');

        $classKey   = $this->input->get('classKey') ?? '';
        $sectionKey = $this->input->get('sectionKey') ?? '';
        if (!$classKey) $this->json_error('classKey is required.');

        $all = $this->firebase->get("{$this->_lmsBase}/Materials") ?? [];
        if (!is_array($all)) $all = [];

        $rows = [];
        foreach ($all as $id => $mat) {
            if (!is_array($mat)) continue;
            if (($mat['classKey'] ?? '') !== $classKey) continue;
            if ($sectionKey && ($mat['sectionKey'] ?? '') !== $sectionKey && ($mat['sectionKey'] ?? '') !== '') continue;
            $mat['id'] = $id;
            $rows[] = $mat;
        }

        usort($rows, fn($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));

        $this->json_success(['materials' => $rows]);
    }

    /**
     * GET — list assignments for a specific class/section with submission status per student
     * Params: classKey, sectionKey
     * Optimized: single batch read of all Submissions instead of N reads per assignment.
     */
    public function get_student_assignments()
    {
        $this->_require_role(self::VIEW_ROLES, 'lms_student_view');

        $classKey   = $this->input->get('classKey') ?? '';
        $sectionKey = $this->input->get('sectionKey') ?? '';
        if (!$classKey) $this->json_error('classKey is required.');

        $all = $this->firebase->get("{$this->_lmsBase}/Assignments") ?? [];
        if (!is_array($all)) $all = [];

        // Load roster for this class/section
        $roster = [];
        if ($classKey && $sectionKey) {
            $secLetter = str_replace('Section ', '', $sectionKey);
            $rosterPath = "Schools/{$this->school_name}/{$this->session_year}/{$classKey}/Section {$secLetter}/Students/List";
            $roster = $this->firebase->get($rosterPath) ?? [];
            if (!is_array($roster)) $roster = [];
        }

        // Single batch read — all submissions at once instead of per-assignment
        $allSubs = $this->firebase->get("{$this->_lmsBase}/Submissions") ?? [];
        if (!is_array($allSubs)) $allSubs = [];

        $rows = [];
        foreach ($all as $id => $a) {
            if (!is_array($a)) continue;
            if (($a['classKey'] ?? '') !== $classKey) continue;
            if ($sectionKey && ($a['sectionKey'] ?? '') !== $sectionKey && ($a['sectionKey'] ?? '') !== '') continue;
            $a['id'] = $id;
            unset($a['_submissionCount']); // strip internal field

            // Use batch-loaded submissions for this assignment
            $subs = $allSubs[$id] ?? [];
            if (!is_array($subs)) $subs = [];

            $studentStatuses = [];
            foreach ($roster as $sid => $stu) {
                if (!is_array($stu)) continue;
                $subData = $subs[$sid] ?? null;
                $studentStatuses[] = [
                    'studentId'   => $sid,
                    'studentName' => $stu['Name'] ?? $sid,
                    'status'      => is_array($subData) ? ($subData['status'] ?? 'submitted') : 'pending',
                    'marks'       => is_array($subData) ? ($subData['marks'] ?? null) : null,
                    'submittedAt' => is_array($subData) ? ($subData['submittedAt'] ?? '') : '',
                ];
            }
            $a['studentStatuses'] = $studentStatuses;
            $rows[] = $a;
        }

        usort($rows, fn($a, $b) => strcmp($b['dueDate'] ?? '', $a['dueDate'] ?? ''));

        $this->json_success(['assignments' => $rows, 'roster' => $roster]);
    }

    /**
     * GET — list quizzes for a specific class/section with attempt status per student
     * Params: classKey, sectionKey
     * Optimized: single batch read of all QuizAttempts instead of N reads per quiz.
     * Handles both legacy single-attempt and new multi-attempt structures.
     */
    public function get_student_quizzes()
    {
        $this->_require_role(self::VIEW_ROLES, 'lms_student_view');

        $classKey   = $this->input->get('classKey') ?? '';
        $sectionKey = $this->input->get('sectionKey') ?? '';
        if (!$classKey) $this->json_error('classKey is required.');

        $all = $this->firebase->get("{$this->_lmsBase}/Quizzes") ?? [];
        if (!is_array($all)) $all = [];

        // Load roster
        $roster = [];
        if ($classKey && $sectionKey) {
            $secLetter = str_replace('Section ', '', $sectionKey);
            $rosterPath = "Schools/{$this->school_name}/{$this->session_year}/{$classKey}/Section {$secLetter}/Students/List";
            $roster = $this->firebase->get($rosterPath) ?? [];
            if (!is_array($roster)) $roster = [];
        }

        // Single batch read — all quiz attempts at once instead of per-quiz
        $allAttempts = $this->firebase->get("{$this->_lmsBase}/QuizAttempts") ?? [];
        if (!is_array($allAttempts)) $allAttempts = [];

        $rows = [];
        foreach ($all as $id => $q) {
            if (!is_array($q)) continue;
            if (($q['classKey'] ?? '') !== $classKey) continue;
            if ($sectionKey && ($q['sectionKey'] ?? '') !== $sectionKey && ($q['sectionKey'] ?? '') !== '') continue;
            // Only show active and closed quizzes in student view (not drafts)
            if (($q['status'] ?? 'draft') === 'draft') continue;

            $q['id'] = $id;
            // Strip questions (student shouldn't see answers in list view)
            $q['questionCount'] = count($q['questions'] ?? []);
            unset($q['questions'], $q['_attemptCount']);

            // Use batch-loaded attempts for this quiz
            $quizAttempts = $allAttempts[$id] ?? [];
            if (!is_array($quizAttempts)) $quizAttempts = [];

            $studentStatuses = [];
            foreach ($roster as $sid => $stu) {
                if (!is_array($stu)) continue;
                $studentData = $quizAttempts[$sid] ?? null;

                // Resolve best score from legacy or multi-attempt format
                $attempted  = false;
                $bestScore  = null;
                $totalMarks = null;
                $attemptCnt = 0;

                if (is_array($studentData)) {
                    $attempted = true;
                    if (isset($studentData['score'])) {
                        // Legacy single-attempt
                        $bestScore  = $studentData['score'] ?? 0;
                        $totalMarks = $studentData['totalMarks'] ?? 0;
                        $attemptCnt = 1;
                    } else {
                        // Multi-attempt — find best score
                        foreach ($studentData as $att) {
                            if (!is_array($att)) continue;
                            $attemptCnt++;
                            $s = (float)($att['score'] ?? 0);
                            if ($bestScore === null || $s > $bestScore) {
                                $bestScore  = $s;
                                $totalMarks = $att['totalMarks'] ?? 0;
                            }
                        }
                    }
                }

                $studentStatuses[] = [
                    'studentId'    => $sid,
                    'studentName'  => $stu['Name'] ?? $sid,
                    'attempted'    => $attempted,
                    'attemptCount' => $attemptCnt,
                    'score'        => $bestScore,
                    'totalMarks'   => $totalMarks,
                ];
            }
            $q['studentStatuses'] = $studentStatuses;
            $rows[] = $q;
        }

        usort($rows, fn($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));

        $this->json_success(['quizzes' => $rows, 'roster' => $roster]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       COUNTER RECONCILIATION
       Background-callable methods to correct drifted counters.
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * POST — reconcile _submissionCount for a single assignment by counting actual submissions.
     */
    public function rebuild_submission_count()
    {
        $this->_require_role(self::MANAGE_ROLES, 'lms_reconcile');

        $assignmentId = $this->input->post('assignmentId');
        if (!$assignmentId) $this->json_error('Assignment ID required.');
        $assignmentId = $this->safe_path_segment($assignmentId, 'assignmentId');

        $assign = $this->firebase->get("{$this->_lmsBase}/Assignments/{$assignmentId}");
        if (!is_array($assign)) $this->json_error('Assignment not found.', 404);

        $subs = $this->firebase->get("{$this->_lmsBase}/Submissions/{$assignmentId}") ?? [];
        $actualCount = is_array($subs) ? count($subs) : 0;

        $this->firebase->update("{$this->_lmsBase}/Assignments/{$assignmentId}", [
            '_submissionCount' => $actualCount,
        ]);

        $this->json_success([
            'previous' => (int)($assign['_submissionCount'] ?? 0),
            'actual'   => $actualCount,
        ]);
    }

    /**
     * POST — reconcile _attemptCount for a single quiz by counting actual attempts.
     * Handles both legacy single-attempt and new multi-attempt structures.
     */
    public function rebuild_attempt_count()
    {
        $this->_require_role(self::MANAGE_ROLES, 'lms_reconcile');

        $quizId = $this->input->post('quizId');
        if (!$quizId) $this->json_error('Quiz ID required.');
        $quizId = $this->safe_path_segment($quizId, 'quizId');

        $quiz = $this->firebase->get("{$this->_lmsBase}/Quizzes/{$quizId}");
        if (!is_array($quiz)) $this->json_error('Quiz not found.', 404);

        $allAttempts = $this->firebase->get("{$this->_lmsBase}/QuizAttempts/{$quizId}") ?? [];
        $actualCount = 0;
        if (is_array($allAttempts)) {
            foreach ($allAttempts as $studentData) {
                if (!is_array($studentData)) continue;
                if (isset($studentData['score'])) {
                    // Legacy single-attempt
                    $actualCount++;
                } else {
                    // Multi-attempt — count each child
                    foreach ($studentData as $att) {
                        if (is_array($att)) $actualCount++;
                    }
                }
            }
        }

        $this->firebase->update("{$this->_lmsBase}/Quizzes/{$quizId}", [
            '_attemptCount' => $actualCount,
        ]);

        $this->json_success([
            'previous' => (int)($quiz['_attemptCount'] ?? 0),
            'actual'   => $actualCount,
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       PRIVATE HELPERS
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * Validate a URL — reject dangerous protocols (javascript:, data:, vbscript:).
     * Allows only http:// and https:// URLs.
     *
     * @param  string $url
     * @return string Validated URL
     */
    private function _validate_url(string $url): string
    {
        $url = trim($url);
        if ($url === '') return '';

        // Reject dangerous protocols
        $lower = strtolower(trim($url));
        $blocked = ['javascript:', 'data:', 'vbscript:', 'file:'];
        foreach ($blocked as $proto) {
            if (strpos($lower, $proto) === 0) {
                $this->json_error('Invalid URL protocol. Only http:// and https:// are allowed.');
            }
        }

        // Must start with http:// or https://
        if (strpos($lower, 'http://') !== 0 && strpos($lower, 'https://') !== 0) {
            $this->json_error('URL must start with http:// or https://');
        }

        // Basic URL format validation
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->json_error('Invalid URL format.');
        }

        return $url;
    }
}
