<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Result controller — Dynamic Result Management System
 *
 * Firebase structure (under Schools/{school}/{year}/Results/):
 *   Templates/{examId}/{classKey}/{sectionKey}/{subject}/
 *     Components/ { 0: {Name:"Theory",MaxMarks:80}, ... }
 *     TotalMaxMarks: 100
 *   Marks/{examId}/{classKey}/{sectionKey}/{subject}/{userId}/
 *     {ComponentName}: marks, Total, Absent, SavedAt
 *   Computed/{examId}/{classKey}/{sectionKey}/{userId}/
 *     TotalMarks, MaxMarks, Percentage, Grade, PassFail, Rank
 *     Subjects/ { English: {Total,MaxMarks,Percentage,Grade,PassFail} }
 *   CumulativeConfig/
 *     Exams/ { EXM0001: {Weight:40,Label:"Mid-Term"}, ... }
 *     TotalWeight: 100
 *   Cumulative/{classKey}/{sectionKey}/{userId}/
 *     WeightedTotal, Grade, PassFail, Rank
 *     Subjects/ { English: {WeightedScore,Grade,PassFail} }
 *
 * classKey  = "Class 9th"   (full prefix)
 * sectionKey= "Section A"   (full prefix)
 *
 * NOTE: compute_grade() thresholds must stay in sync with JS in marks_sheet.php
 *
 * RBAC:
 *   Super Admin / Admin — full access (templates, marks, compute, cumulative)
 *   Teacher             — save_marks (own assigned classes/subjects only),
 *                          view marks_sheet, marks_entry, class_result, student_result
 */
class Result extends MY_Controller
{
    /** Roles allowed to design templates, compute results, configure cumulative. */
    private const ADMIN_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator'];

    /** Roles allowed to enter/save marks (Teachers limited to own classes via _teacher_can_access). */
    private const MARKS_ENTRY_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator', 'Class Teacher', 'Teacher'];

    /** Roles allowed to view results, marks, report cards. */
    private const VIEW_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator', 'Class Teacher', 'Teacher'];

    public function __construct()
    {
        parent::__construct();
        require_permission('Results');
        $this->load->library('exam_engine');
        $this->exam_engine->init($this->firebase, $this->school_name, $this->session_year);

        $this->load->library('Fee_defaulter_check', null, 'feeDefaulter');
        $this->feeDefaulter->init($this->firebase, $this->school_name, $this->session_year);

        // Firestore helper
        $this->load->library('Firestore_helper', null, 'fs');
        $this->fs->init($this->firebase, $this->school_name, $this->session_year);
    }

    /**
     * Sanitize common Result path segments from user input.
     * Applies safe_path_segment() to each non-empty value.
     */
    private function _safe_result_params(array $params): array
    {
        $out = [];
        foreach ($params as $key => $val) {
            $out[$key] = ($val !== '') ? $this->safe_path_segment($val, $key) : '';
        }
        return $out;
    }

    // ══════════════════════════════════════════════════════════════════
    // PAGE VIEWS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Hub dashboard — shows exam cards with template/marks/computed status.
     */
    public function index()
    {
        $this->_require_role(self::VIEW_ROLES, 'view_results');
        $school = $this->school_name;
        $year   = $this->session_year;

        $raw   = $this->firebase->get("Schools/{$school}/{$year}/Exams") ?? [];
        $exams = [];
        foreach ($raw as $id => $e) {
            if ($id === 'Count' || !is_array($e)) continue;
            if (($e['Status'] ?? '') === 'Draft') continue; // only Published/Completed
            $exams[] = array_merge(['id' => $id], $e);
        }
        usort($exams, fn($a, $b) => ($b['CreatedAt'] ?? 0) <=> ($a['CreatedAt'] ?? 0));

        $this->load->view('include/header');
        $this->load->view('result/index', ['exams' => $exams]);
        $this->load->view('include/footer');
    }

    /**
     * Template designer — set components (Theory, Practical, etc.) per subject.
     */
    public function template_designer($examId = null)
    {
        $this->_require_role(self::ADMIN_ROLES, 'design exam template');

        $school    = $this->school_name;
        $year      = $this->session_year;
        $structure = $this->exam_engine->get_class_structure();
        $exams     = $this->exam_engine->get_active_exams();

        $data = [
            'structure' => $structure,
            'exams'     => $exams,
            'examId'    => $examId,
            'exam'      => null,
            'subjects'  => [],
        ];

        if ($examId) {
            $exam = $this->firebase->get("Schools/{$school}/{$year}/Exams/{$examId}");
            if ($exam && is_array($exam)) {
                $data['exam'] = array_merge(['id' => $examId], $exam);
            }
        }

        $this->load->view('include/header');
        $this->load->view('result/template_designer', $data);
        $this->load->view('include/footer');
    }

    /**
     * Marks entry selector — pick exam, class, section; shows subject grid with progress.
     */
    public function marks_entry($examId = null)
    {
        $this->_require_role(self::VIEW_ROLES, 'marks_entry');
        $school    = $this->school_name;
        $year      = $this->session_year;
        $structure = $this->exam_engine->get_class_structure();
        $exams     = $this->exam_engine->get_active_exams();

        $data = [
            'structure' => $structure,
            'exams'     => $exams,
            'examId'    => $examId,
            'exam'      => null,
        ];

        if ($examId) {
            $exam = $this->firebase->get("Schools/{$school}/{$year}/Exams/{$examId}");
            if ($exam && is_array($exam)) {
                $data['exam'] = array_merge(['id' => $examId], $exam);
            }
        }

        $this->load->view('include/header');
        $this->load->view('result/marks_entry', $data);
        $this->load->view('include/footer');
    }

    /**
     * Marks sheet — data-entry table for one exam+class+section+subject.
     *
     * URL segments are URL-encoded; decode here.
     */
    public function marks_sheet($examId = null, $classKey = null, $sectionKey = null, $subject = null)
    {
        $this->_require_role(self::VIEW_ROLES, 'view_marks_sheet');
        $school = $this->school_name;
        $year   = $this->session_year;

        // Decode URL segments
        $examId     = $examId     ? urldecode($examId)     : null;
        $classKey   = $classKey   ? urldecode($classKey)   : null;
        $sectionKey = $sectionKey ? urldecode($sectionKey) : null;
        $subject    = $subject    ? urldecode($subject)    : null;

        if (!$examId || !$classKey || !$sectionKey || !$subject) {
            $this->session->set_flashdata('error', 'Missing parameters.');
            redirect('result/marks_entry');
        }

        // RBAC: Teachers can only view marks sheets for their assigned classes/subjects
        if (!$this->_teacher_can_access($classKey, $sectionKey, $subject)) {
            show_error('You do not have permission to access this marks sheet.', 403, 'Access Denied');
        }

        // Load exam metadata
        $exam = $this->firebase->get("Schools/{$school}/{$year}/Exams/{$examId}");
        if (!$exam || !is_array($exam)) {
            $this->session->set_flashdata('error', 'Exam not found.');
            redirect('result/marks_entry');
        }
        $exam = array_merge(['id' => $examId], $exam);

        // Guard: template must exist before marks entry
        $template = $this->firebase->get(
            "Schools/{$school}/{$year}/Results/Templates/{$examId}/{$classKey}/{$sectionKey}/{$subject}"
        );
        if (!$template || empty($template['Components'])) {
            $this->session->set_flashdata(
                'error',
                "No template found for {$subject}. Please design the template first."
            );
            redirect("result/template_designer/{$examId}");
        }

        // Load student list
        $studentList = $this->firebase->get(
            "Schools/{$school}/{$year}/{$classKey}/{$sectionKey}/Students/List"
        ) ?? [];
        if (!is_array($studentList)) $studentList = [];

        // Load existing marks
        $existingMarks = $this->firebase->get(
            "Schools/{$school}/{$year}/Results/Marks/{$examId}/{$classKey}/{$sectionKey}/{$subject}"
        ) ?? [];
        if (!is_array($existingMarks)) $existingMarks = [];

        $data = [
            'examId'        => $examId,
            'exam'          => $exam,
            'classKey'      => $classKey,
            'sectionKey'    => $sectionKey,
            'subject'       => $subject,
            'template'      => $template,
            'studentList'   => $studentList,
            'existingMarks' => $existingMarks,
        ];

        $this->load->view('include/header');
        $this->load->view('result/marks_sheet', $data);
        $this->load->view('include/footer');
    }

    /**
     * Class result table — computed results for an exam+class+section.
     */
    public function class_result($examId = null)
    {
        $this->_require_role(self::VIEW_ROLES, 'view_class_result');
        $school    = $this->school_name;
        $year      = $this->session_year;
        $structure = $this->exam_engine->get_class_structure();
        $exams     = $this->exam_engine->get_active_exams();

        $data = [
            'structure' => $structure,
            'exams'     => $exams,
            'examId'    => $examId,
            'exam'      => null,
        ];

        if ($examId) {
            $exam = $this->firebase->get("Schools/{$school}/{$year}/Exams/{$examId}");
            if ($exam && is_array($exam)) {
                $data['exam'] = array_merge(['id' => $examId], $exam);
            }
        }

        $this->load->view('include/header');
        $this->load->view('result/class_result', $data);
        $this->load->view('include/footer');
    }

    /**
     * Student result — all exams in tabs for one student.
     */
    public function student_result($userId = null)
    {
        $this->_require_role(self::VIEW_ROLES, 'view_student_result');
        $school = $this->school_name;
        $year   = $this->session_year;

        if (!$userId) {
            redirect('result');
        }

        // Load student profile
        $profile = $this->firebase->get("Users/Parents/{$this->parent_db_key}/{$userId}") ?? [];
        if (!is_array($profile)) $profile = [];

        $studentName = $profile['Name'] ?? $profile['name'] ?? 'Unknown Student';
        $className   = $profile['Class']   ?? '';
        $section     = $profile['Section'] ?? '';
        $classKey    = $className ? "Class {$className}" : '';
        $sectionKey  = $section   ? "Section {$section}" : '';

        // RBAC: Teachers can only view results for their assigned classes
        if ($classKey && $sectionKey && !$this->_teacher_can_access($classKey, $sectionKey)) {
            show_error('You do not have permission to view this student\'s results.', 403, 'Access Denied');
        }

        // Check if results are withheld for fee defaulter
        $resultWithheld = false;
        $withheldDues   = 0;
        try {
            $defaulterStatus = $this->feeDefaulter->isDefaulter($userId);
            if ($defaulterStatus['is_defaulter']) {
                // Check if result withholding is active for this student
                $defaulterNode = $this->firebase->get(
                    "Schools/{$this->school_name}/{$this->session_year}/Fees/Defaulters/{$userId}"
                );
                if (!empty($defaulterNode['result_withheld'])) {
                    $resultWithheld = true;
                    $withheldDues   = $defaulterStatus['total_dues'];
                }
            }
        } catch (Exception $e) {
            log_message('error', "Fee defaulter check failed in Result: " . $e->getMessage());
        }

        // Load all active exams
        $exams = $this->exam_engine->get_active_exams();

        // Load computed results for this student across all exams
        $results = [];
        foreach ($exams as $exam) {
            if (!$classKey || !$sectionKey) continue;
            $computed = $this->firebase->get(
                "Schools/{$school}/{$year}/Results/Computed/{$exam['id']}/{$classKey}/{$sectionKey}/{$userId}"
            );
            if ($computed && is_array($computed)) {
                $results[$exam['id']] = $computed;
            }
        }

        $data = [
            'userId'          => $userId,
            'profile'         => $profile,
            'studentName'     => $studentName,
            'classKey'        => $classKey,
            'sectionKey'      => $sectionKey,
            'exams'           => $exams,
            'results'         => $resultWithheld ? [] : $results,
            'result_withheld' => $resultWithheld,
            'withheld_dues'   => $withheldDues,
        ];

        $this->load->view('include/header');
        $this->load->view('result/student_result', $data);
        $this->load->view('include/footer');
    }

  
    // public function report_card($userId = null, $examId = null)
    // {
    //     $school = $this->school_name;
    //     $year   = $this->session_year;

    //     if (!$userId || !$examId) { redirect('result'); }

    //     // Load exam
    //     $exam = $this->firebase->get("Schools/{$school}/{$year}/Exams/{$examId}");
    //     if (!$exam || !is_array($exam)) { redirect('result'); }
    //     $exam = array_merge(['id' => $examId], $exam);

    //     // Load student profile
    //     $profile = $this->firebase->get("Users/Parents/{$school}/{$userId}") ?? [];
    //     if (!is_array($profile)) $profile = [];

    //     $className  = $profile['Class']   ?? '';
    //     $section    = $profile['Section'] ?? '';
    //     $classKey   = $className ? "Class {$className}" : '';
    //     $sectionKey = $section   ? "Section {$section}" : '';

    //     // Load computed result
    //     $computed = null;
    //     if ($classKey && $sectionKey) {
    //         $computed = $this->firebase->get(
    //             "Schools/{$school}/{$year}/Results/Computed/{$examId}/{$classKey}/{$sectionKey}/{$userId}"
    //         );
    //     }

    //     // Load templates for all subjects (to get component details)
    //     $templates = [];
    //     if ($classKey && $sectionKey) {
    //         $examTemplates = $this->firebase->get(
    //             "Schools/{$school}/{$year}/Results/Templates/{$examId}/{$classKey}/{$sectionKey}"
    //         ) ?? [];
    //         if (is_array($examTemplates)) {
    //             $templates = $examTemplates;
    //         }
    //     }

    //     // Load raw marks for this student
    //     $marks = [];
    //     if ($classKey && $sectionKey) {
    //         $subjects = array_keys($templates);
    //         foreach ($subjects as $subj) {
    //             $sm = $this->firebase->get(
    //                 "Schools/{$school}/{$year}/Results/Marks/{$examId}/{$classKey}/{$sectionKey}/{$subj}/{$userId}"
    //             );
    //             if ($sm && is_array($sm)) {
    //                 $marks[$subj] = $sm;
    //             }
    //         }
    //     }

    //     // Load school info
    //     $schoolInfo = $this->firebase->get("Schools/{$school}/Info") ?? [];
    //     if (!is_array($schoolInfo)) $schoolInfo = [];

    //     $data = [
    //         'userId'      => $userId,
    //         'examId'      => $examId,
    //         'exam'        => $exam,
    //         'profile'     => $profile,
    //         'classKey'    => $classKey,
    //         'sectionKey'  => $sectionKey,
    //         'computed'    => $computed,
    //         'templates'   => $templates,
    //         'marks'       => $marks,
    //         'schoolInfo'  => $schoolInfo,
    //         'schoolName'  => $school,
    //         'sessionYear' => $year,
    //     ];

    //     // Report card is standalone — no header/footer chrome
    //     $this->load->view('result/report_card', $data);
    // }

    /**
     * Cumulative — config (weights) + class-level weighted result table.
     */
    public function report_card($userId = null, $examId = null)
    {
        $this->_require_role(self::VIEW_ROLES, 'view_report_card');

        if (!$userId || !$examId) {
            redirect('result');
        }

        // Check if results are withheld for fee defaulter
        try {
            $defaulterStatus = $this->feeDefaulter->isDefaulter($userId);
            if ($defaulterStatus['is_defaulter']) {
                // Check if result withholding is active for this student
                $defaulterNode = $this->firebase->get(
                    "Schools/{$this->school_name}/{$this->session_year}/Fees/Defaulters/{$userId}"
                );
                if (!empty($defaulterNode['result_withheld'])) {
                    // Check for admin override
                    $forceOverride = $this->input->get_post('force_override');
                    if (!$forceOverride) {
                        $this->json_error(
                            'Results withheld due to outstanding fees of Rs. ' . $defaulterStatus['total_dues'],
                            403
                        );
                        return;
                    }
                    // Log override
                    $this->firebase->push(
                        "Schools/{$this->school_name}/{$this->session_year}/Fees/Audit_Logs",
                        [
                            'event'         => 'result_withhold_override',
                            'student_id'    => $userId,
                            'overridden_by' => $this->admin_id ?? 'system',
                            'timestamp'     => date('c'),
                        ]
                    );
                }
            }
        } catch (Exception $e) {
            log_message('error', "Fee defaulter check failed in Result: " . $e->getMessage());
        }

        $data = $this->_load_report_card_data($userId, $examId);
        if ($data === null) {
            redirect('result');
        }

        $this->load->view('result/report_card', $data);
    }

    /**
     * Batch report cards — render all students in a class/section as a single
     * multi-page HTML document with CSS page-break-before per student.
     *
     * URL: result/batch_report_cards/{examId}/{classKey}/{sectionKey}
     */
    public function batch_report_cards($examId = null, $classKey = null, $sectionKey = null)
    {
        $this->_require_role(self::ADMIN_ROLES, 'batch report cards');

        $school = $this->school_name;
        $year   = $this->session_year;

        if (!$examId || !$classKey || !$sectionKey) {
            redirect('result/class_result');
        }
        $examId     = urldecode($examId);
        $classKey   = urldecode($classKey);
        $sectionKey = urldecode($sectionKey);

        // Load exam
        $exam = $this->firebase->get("Schools/{$school}/{$year}/Exams/{$examId}");
        if (!$exam || !is_array($exam)) {
            redirect('result/class_result');
        }
        $exam = array_merge(['id' => $examId], $exam);

        // Load all templates for this exam/class/section
        $templates = $this->firebase->get(
            "Schools/{$school}/{$year}/Results/Templates/{$examId}/{$classKey}/{$sectionKey}"
        ) ?? [];
        if (!is_array($templates)) $templates = [];

        // Load all computed results
        $computed = $this->firebase->get(
            "Schools/{$school}/{$year}/Results/Computed/{$examId}/{$classKey}/{$sectionKey}"
        ) ?? [];
        if (!is_array($computed)) $computed = [];
        unset($computed['_stale']);

        // Load all marks for all subjects and students in one batch
        $allMarks = $this->firebase->get(
            "Schools/{$school}/{$year}/Results/Marks/{$examId}/{$classKey}/{$sectionKey}"
        ) ?? [];
        if (!is_array($allMarks)) $allMarks = [];

        // Load student roster
        $roster = $this->exam_engine->get_student_names($classKey, $sectionKey);

        // Load school info
        $schoolInfo = $this->firebase->get("Schools/{$school}/Info") ?? [];
        if (!is_array($schoolInfo)) $schoolInfo = [];

        // Build per-student data using the parent_db_key for profile lookups
        $school_id = $this->parent_db_key;
        $students  = [];
        $userIds   = array_unique(array_merge(array_keys($computed), array_keys($roster)));
        sort($userIds);

        // Single Firebase read for all profiles (fixes N+1 query pattern)
        $allProfiles = $this->firebase->get("Users/Parents/{$school_id}") ?? [];
        if (!is_array($allProfiles)) $allProfiles = [];

        foreach ($userIds as $userId) {
            if (!isset($computed[$userId])) continue; // Only students with computed results

            $profile = (isset($allProfiles[$userId]) && is_array($allProfiles[$userId]))
                ? $allProfiles[$userId] : [];

            // Per-student marks
            $stuMarks = [];
            foreach ($templates as $subject => $tmp) {
                $stuMarks[$subject] = $allMarks[$subject][$userId] ?? [];
            }

            $students[] = [
                'userId'      => $userId,
                'examId'      => $examId,
                'exam'        => $exam,
                'profile'     => $profile,
                'classKey'    => $classKey,
                'sectionKey'  => $sectionKey,
                'computed'    => $computed[$userId],
                'templates'   => $templates,
                'marks'       => $stuMarks,
                'schoolInfo'  => $schoolInfo,
                'schoolName'  => $school,
                'sessionYear' => $year,
            ];
        }

        // Load selected report card template
        $rcTemplate = $this->firebase->get("Schools/{$school}/Config/ReportCardTemplate");
        $rcAllowed  = ['classic', 'cbse', 'minimal', 'modern', 'elegant'];
        if (!$rcTemplate || !is_string($rcTemplate) || !in_array($rcTemplate, $rcAllowed, true)) $rcTemplate = 'classic';

        // Render batch view — reuses report_card view in a loop
        $data = [
            'students'    => $students,
            'schoolInfo'  => $schoolInfo,
            'exam'        => $exam,
            'classKey'    => $classKey,
            'sectionKey'  => $sectionKey,
            'sessionYear' => $year,
            'rc_template' => $rcTemplate,
        ];

        $this->load->view('result/batch_report_cards', $data);
    }

    public function cumulative()
    {
        $this->_require_role(self::ADMIN_ROLES, 'cumulative results');

        $school    = $this->school_name;
        $year      = $this->session_year;
        $structure = $this->exam_engine->get_class_structure();
        $exams     = $this->exam_engine->get_active_exams();

        $config = $this->firebase->get(
            "Schools/{$school}/{$year}/Results/CumulativeConfig"
        ) ?? [];
        if (!is_array($config)) $config = [];

        $data = [
            'structure' => $structure,
            'exams'     => $exams,
            'config'    => $config,
        ];

        $this->load->view('include/header');
        $this->load->view('result/cumulative', $data);
        $this->load->view('include/footer');
    }

    // ══════════════════════════════════════════════════════════════════
    // AJAX ENDPOINTS
    // ══════════════════════════════════════════════════════════════════

    /**
     * POST AJAX — Save component definitions (template) for exam+class+section+subject.
     */
    public function save_template()
    {
        $this->_require_role(self::ADMIN_ROLES, 'save exam template');
        header('Content-Type: application/json');

        $school = $this->school_name;
        $year   = $this->session_year;

        $examId     = trim((string) $this->input->post('examId'));
        $classKey   = trim((string) $this->input->post('classKey'));
        $sectionKey = trim((string) $this->input->post('sectionKey'));
        $subject    = trim((string) $this->input->post('subject'));
        $compsJson  = (string) $this->input->post('components');

        if (!$examId || !$classKey || !$sectionKey || !$subject) {
            $this->json_error('Missing required fields.', 400);
        }
        extract($this->_safe_result_params(compact('examId', 'classKey', 'sectionKey', 'subject')));
        if (strpos($classKey, 'Class ') !== 0) {
            $this->json_error('Invalid class key.', 400);
        }
        if (strpos($sectionKey, 'Section ') !== 0) {
            $this->json_error('Invalid section key.', 400);
        }

        $components = json_decode($compsJson, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($components) || empty($components)) {
            $this->json_error('Invalid components data.', 400);
        }

        // Validate and build components array
        $totalMax    = 0;
        $compsClean  = [];
        foreach ($components as $i => $comp) {
            $name     = trim(strip_tags((string) ($comp['name']     ?? '')));
            $maxMarks = (int) ($comp['maxMarks'] ?? 0);
            if (!$name || $maxMarks < 1 || $maxMarks > 999) {
                $this->json_error("Component #{$i}: name required, maxMarks must be 1–999.", 400);
            }
            $totalMax        += $maxMarks;
            $compsClean[$i]   = ['Name' => $name, 'MaxMarks' => $maxMarks];
        }

        $template = [
            'Components'    => $compsClean,
            'TotalMaxMarks' => $totalMax,
            'CreatedAt'     => (int) round(microtime(true) * 1000),
            'CreatedBy'     => $this->admin_id ?? '',
        ];

        $path = "Schools/{$school}/{$year}/Results/Templates/{$examId}/{$classKey}/{$sectionKey}/{$subject}";
        $this->firebase->set($path, $template);

        log_audit('Results', 'save_template', $examId, "Saved marks template for {$classKey}/{$sectionKey}/{$subject}");

        $this->json_success(['message' => 'Template saved.', 'totalMaxMarks' => $totalMax]);
    }

    /**
     * GET AJAX — Fetch template JSON for pre-population.
     */
    public function get_template()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_template');
        header('Content-Type: application/json');

        $school     = $this->school_name;
        $year       = $this->session_year;
        $examId     = trim((string) $this->input->get('examId'));
        $classKey   = trim((string) $this->input->get('classKey'));
        $sectionKey = trim((string) $this->input->get('sectionKey'));
        $subject    = trim((string) $this->input->get('subject'));

        if (!$examId || !$classKey || !$sectionKey || !$subject) {
            echo json_encode(['template' => null]);
            return;
        }

        // RBAC: Teachers can only view templates for their assigned classes/subjects
        if (!$this->_teacher_can_access($classKey, $sectionKey, $subject)) {
            echo json_encode(['template' => null]);
            return;
        }

        extract($this->_safe_result_params(compact('examId', 'classKey', 'sectionKey', 'subject')));

        $path     = "Schools/{$school}/{$year}/Results/Templates/{$examId}/{$classKey}/{$sectionKey}/{$subject}";
        $template = $this->firebase->get($path);

        echo json_encode(['template' => $template]);
    }

    /**
     * POST AJAX — Batch-save marks for all students in one subject.
     *
     * Payload: JSON in marksData field.
     * { examId, classKey, sectionKey, subject,
     *   students: [{userId, absent, marks:{ComponentName:value,...}, total}] }
     */
    public function save_marks()
    {
        $this->_require_role(self::MARKS_ENTRY_ROLES, 'save_marks');
        header('Content-Type: application/json');

        $school = $this->school_name;
        $year   = $this->session_year;

        $raw = (string) $this->input->post('marksData');
        if (empty($raw)) {
            $this->json_error('No marks data received.', 400);
        }

        $payload = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
            $this->json_error('Invalid JSON payload.', 400);
        }

        $examId     = trim((string) ($payload['examId']     ?? ''));
        $classKey   = trim((string) ($payload['classKey']   ?? ''));
        $sectionKey = trim((string) ($payload['sectionKey'] ?? ''));
        $subject    = trim((string) ($payload['subject']    ?? ''));

        if (!$examId || !$classKey || !$sectionKey || !$subject) {
            $this->json_error('Missing required fields.', 400);
        }
        extract($this->_safe_result_params(compact('examId', 'classKey', 'sectionKey', 'subject')));

        // RBAC: Teachers can only save marks for their assigned classes/subjects
        if (!$this->_teacher_can_access($classKey, $sectionKey, $subject)) {
            $this->json_error('You are not assigned to this class/subject.', 403);
        }
        $students   = $payload['students'] ?? [];
        if (!is_array($students)) {
            $this->json_error('Students data must be an array.', 400);
        }

        // ── Fix H1: Load template to enforce marks upper bound ──────────
        $tmplPath = "Schools/{$school}/{$year}/Results/Templates/{$examId}/{$classKey}/{$sectionKey}/{$subject}";
        $template = $this->firebase->get($tmplPath);
        if (!is_array($template) || empty($template['Components'])) {
            $this->json_error('No template found for this subject. Design a template first.', 400);
        }
        $compMaxMap = [];
        foreach ($template['Components'] as $c) {
            if (is_array($c) && !empty($c['Name'])) {
                $compMaxMap[$c['Name']] = (int) ($c['MaxMarks'] ?? 0);
            }
        }
        $templateTotalMax = (int) ($template['TotalMaxMarks'] ?? 0);

        // ── Fix M1: Load student roster for enrollment validation ───────
        $roster = $this->firebase->get(
            "Schools/{$school}/{$year}/{$classKey}/{$sectionKey}/Students/List"
        ) ?? [];
        if (!is_array($roster)) $roster = [];

        $savedAt  = (int) round(microtime(true) * 1000);
        $savedBy  = $this->admin_id ?? '';
        $basePath = "Schools/{$school}/{$year}/Results/Marks/{$examId}/{$classKey}/{$sectionKey}/{$subject}";
        $count    = 0;
        $warnings = [];

        foreach ($students as $stu) {
            $userId = trim((string) ($stu['userId'] ?? ''));
            if (!$userId) continue;
            $userId = $this->safe_path_segment($userId, 'userId');

            // Fix M1: Validate student belongs to this class/section roster
            if (!empty($roster) && !isset($roster[$userId])) {
                $warnings[] = "Student {$userId} not in class roster — skipped.";
                continue;
            }

            $absent   = !empty($stu['absent']);
            $rawMarks = is_array($stu['marks'] ?? null) ? $stu['marks'] : [];

            // Sanitize component marks + enforce upper bound (Fix H1)
            $marksClean  = [];
            $computeTotal = 0;
            foreach ($rawMarks as $comp => $val) {
                $comp = strip_tags(trim((string) $comp));
                if (!$comp) continue;
                $markVal = $absent ? 0 : max(0, (int) $val);
                // Clamp to component MaxMarks if template defines it
                if (isset($compMaxMap[$comp]) && $markVal > $compMaxMap[$comp]) {
                    $markVal = $compMaxMap[$comp];
                }
                $marksClean[$comp] = $markVal;
                $computeTotal += $markVal;
            }

            $total = $absent ? 0 : $computeTotal;

            $entry = array_merge($marksClean, [
                'Total'   => $total,
                'Absent'  => $absent,
                'SavedAt' => $savedAt,
                'SavedBy' => $savedBy,
            ]);

            $this->firebase->set("{$basePath}/{$userId}", $entry);
            $count++;
        }

        // ── Fix H4: Mark computed results as stale ──────────────────────
        $stalePath = "Schools/{$school}/{$year}/Results/Computed/{$examId}/{$classKey}/{$sectionKey}/_stale";
        $this->firebase->set($stalePath, true);

        // ── Sync marks to Firestore 'marks' collection ──
        try {
            $sectionKeyFs = "{$classKey}/{$sectionKey}";
            foreach ($students as $stu) {
                $userId = trim((string) ($stu['userId'] ?? ''));
                if (!$userId) continue;
                $absent    = !empty($stu['absent']);
                $rawMarks  = is_array($stu['marks'] ?? null) ? $stu['marks'] : [];
                $theory    = 0.0;
                $practical = 0.0;
                $total     = 0.0;
                foreach ($rawMarks as $comp => $val) {
                    $v = $absent ? 0.0 : max(0.0, floatval($val));
                    if (stripos($comp, 'Theory') !== false) $theory = $v;
                    elseif (stripos($comp, 'Practical') !== false) $practical = $v;
                    $total += $v;
                }
                if ($absent) $total = 0.0;

                $marksDocId = "{$school}_{$examId}_{$sectionKeyFs}_{$subject}_{$userId}";

                // Resolve student name (best-effort)
                $stuName = isset($roster[$userId]) ?
                    (is_string($roster[$userId]) ? $roster[$userId] : ($roster[$userId]['Name'] ?? '')) : '';

                $this->fs->set(Firestore_helper::MARKS, $marksDocId, [
                    'schoolId'    => $school,
                    'session'     => $year,
                    'examId'      => $examId,
                    'studentId'   => $userId,
                    'studentName' => $stuName,
                    'sectionKey'  => $sectionKeyFs,
                    'subject'     => $subject,
                    'theory'      => $theory,
                    'practical'   => $practical,
                    'total'       => $total,
                    'absent'      => $absent,
                    'maxMarks'    => $templateTotalMax,
                    'savedAt'     => date('c'),
                    'savedBy'     => $savedBy,
                ]);
            }
        } catch (\Exception $e) {
            log_message('error', "save_marks: Firestore sync failed [{$examId}/{$subject}]: " . $e->getMessage());
        }

        log_audit('Results', 'save_marks', $examId, "Saved marks for {$count} student(s) in {$subject}");

        $result = ['message' => "Marks saved for {$count} student(s)."];
        if (!empty($warnings)) {
            $result['warnings'] = $warnings;
        }
        $this->json_success($result);
    }

    /**
     * GET AJAX — Fetch marks for a subject (pre-populate marks sheet).
     */
    public function get_marks()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_marks');
        header('Content-Type: application/json');

        $school     = $this->school_name;
        $year       = $this->session_year;
        $examId     = trim((string) $this->input->get('examId'));
        $classKey   = trim((string) $this->input->get('classKey'));
        $sectionKey = trim((string) $this->input->get('sectionKey'));
        $subject    = trim((string) $this->input->get('subject'));

        if (!$examId || !$classKey || !$sectionKey || !$subject) {
            echo json_encode(['marks' => (object)[]]);
            return;
        }
        extract($this->_safe_result_params(compact('examId', 'classKey', 'sectionKey', 'subject')));

        // Fix M3: Teachers can only view marks for their assigned classes/subjects
        if (($this->admin_role ?? '') === 'Teacher') {
            if (!$this->_teacher_can_access($classKey, $sectionKey, $subject)) {
                echo json_encode(['marks' => (object)[]]);
                return;
            }
        }

        $path  = "Schools/{$school}/{$year}/Results/Marks/{$examId}/{$classKey}/{$sectionKey}/{$subject}";
        $marks = $this->firebase->get($path) ?? [];

        echo json_encode(['marks' => is_array($marks) ? $marks : (object)[]]);
    }

    /**
     * POST AJAX — Compute grades/ranks for an exam+class+section → write Computed node.
     */
    public function compute_results()
    {
        $this->_require_role(self::ADMIN_ROLES, 'compute results');
        header('Content-Type: application/json');

        $school     = $this->school_name;
        $year       = $this->session_year;
        $examId     = trim((string) $this->input->post('examId'));
        $classKey   = trim((string) $this->input->post('classKey'));
        $sectionKey = trim((string) $this->input->post('sectionKey'));

        if (!$examId || !$classKey || !$sectionKey) {
            $this->json_error('Missing required fields.', 400);
        }
        extract($this->_safe_result_params(compact('examId', 'classKey', 'sectionKey')));

        $exam = $this->firebase->get("Schools/{$school}/{$year}/Exams/{$examId}");
        if (!$exam || !is_array($exam)) {
            $this->json_error('Exam not found.', 404);
        }

        $scale      = $exam['GradingScale']   ?? 'Percentage';
        $passingPct = (int) ($exam['PassingPercent'] ?? 33);

        // Load all subject templates for this class/section
        $templatesNode = $this->firebase->get(
            "Schools/{$school}/{$year}/Results/Templates/{$examId}/{$classKey}/{$sectionKey}"
        ) ?? [];
        if (!is_array($templatesNode) || empty($templatesNode)) {
            $this->json_error('No templates found. Please design templates first.', 400);
        }

        // Load all marks for this class/section
        $allMarksNode = $this->firebase->get(
            "Schools/{$school}/{$year}/Results/Marks/{$examId}/{$classKey}/{$sectionKey}"
        ) ?? [];
        if (!is_array($allMarksNode)) $allMarksNode = [];

        // Collect all unique student IDs across all subjects
        $allUserIds = [];
        foreach ($allMarksNode as $subj => $stuMarks) {
            if (is_array($stuMarks)) {
                foreach (array_keys($stuMarks) as $uid) {
                    $allUserIds[$uid] = true;
                }
            }
        }
        $allUserIds = array_keys($allUserIds);

        if (empty($allUserIds)) {
            $this->json_error('No marks entered yet for this class/section.', 400);
        }

        // Per student: aggregate subjects
        $studentResults = [];
        foreach ($allUserIds as $uid) {
            $totalMarks = 0;
            $maxMarks   = 0;
            $subjects   = [];
            $allPass    = true;

            foreach ($templatesNode as $subj => $tmpl) {
                if (!is_array($tmpl)) continue;
                $subjMax    = (int) ($tmpl['TotalMaxMarks'] ?? 0);
                $stuMarks   = $allMarksNode[$subj][$uid] ?? [];
                $absent     = !empty($stuMarks['Absent']);
                $subjTotal  = $absent ? 0 : (int) ($stuMarks['Total'] ?? 0);
                $subjPct    = $subjMax > 0 ? ($subjTotal / $subjMax * 100) : 0;
                $subjGrade  = $absent ? 'AB' : $this->exam_engine->compute_grade($subjPct, $scale);
                $subjPass   = $absent ? 'Fail' : $this->exam_engine->compute_pass_fail($subjPct, $passingPct);

                if ($subjPass === 'Fail') $allPass = false;

                $subjects[$subj] = [
                    'Total'      => $subjTotal,
                    'MaxMarks'   => $subjMax,
                    'Percentage' => round($subjPct, 2),
                    'Grade'      => $subjGrade,
                    'PassFail'   => $subjPass,
                    'Absent'     => $absent,
                ];

                $totalMarks += $subjTotal;
                $maxMarks   += $subjMax;
            }

            $overallPct   = $maxMarks > 0 ? ($totalMarks / $maxMarks * 100) : 0;
            $overallGrade = $this->exam_engine->compute_grade($overallPct, $scale);
            $overallPass  = $allPass ? $this->exam_engine->compute_pass_fail($overallPct, $passingPct) : 'Fail';

            $studentResults[$uid] = [
                'TotalMarks' => $totalMarks,
                'MaxMarks'   => $maxMarks,
                'Percentage' => round($overallPct, 2),
                'Grade'      => $overallGrade,
                'PassFail'   => $overallPass,
                'Subjects'   => $subjects,
                'ComputedAt' => (int) round(microtime(true) * 1000),
            ];
        }

        // Sort by Percentage desc → assign competition ranks
        uasort($studentResults, fn($a, $b) => $b['Percentage'] <=> $a['Percentage']);
        $this->exam_engine->assign_ranks_assoc($studentResults, 'Percentage');

        // EX-2 FIX: Single batch write instead of N individual writes
        $basePath = "Schools/{$school}/{$year}/Results/Computed/{$examId}/{$classKey}/{$sectionKey}";
        $this->firebase->set($basePath, $studentResults);

        // ── Fix H4: Clear stale flag after fresh computation ────────────
        $this->firebase->delete("{$basePath}", '_stale');

        // ── Fix H3: Notify parents/students via Communication module ────
        try {
            $this->load->library('Communication_helper', null, 'comm');
            $this->comm->init($this->firebase, $school, $year);
            $this->comm->fire_event('exam_result', [
                'exam_id'        => $examId,
                'exam_name'      => $exam['Name'] ?? $examId,
                'class'          => $classKey,
                'section'        => $sectionKey,
                'students_count' => count($studentResults),
            ]);
        } catch (\Exception $e) {
            log_message('error', "Communication fire_event failed after compute_results: " . $e->getMessage());
        }

        $this->json_success([
            'message' => 'Results computed for ' . count($studentResults) . ' student(s).',
            'count'   => count($studentResults),
        ]);
    }

    /**
     * POST AJAX — Save exam weights for cumulative calculation.
     */
    public function save_cumulative_config()
    {
        $this->_require_role(self::ADMIN_ROLES, 'save cumulative config');
        header('Content-Type: application/json');

        $school    = $this->school_name;
        $year      = $this->session_year;
        $configRaw = (string) $this->input->post('config');

        if (empty($configRaw)) {
            $this->json_error('No config data.', 400);
        }

        $config = json_decode($configRaw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($config)) {
            $this->json_error('Invalid JSON.', 400);
        }

        $examsConfig  = $config['exams'] ?? [];
        $totalWeight  = 0;
        $examsClean   = [];

        foreach ($examsConfig as $examId => $item) {
            $weight = (int) ($item['weight'] ?? 0);
            $label  = strip_tags(trim((string) ($item['label'] ?? $examId)));
            if ($weight < 0 || $weight > 100) {
                $this->json_error("Weight for {$examId} must be 0–100.", 400);
            }
            $totalWeight         += $weight;
            $examsClean[$examId]  = ['Weight' => $weight, 'Label' => $label];
        }

        if ($totalWeight !== 100) {
            $this->json_error("Total weight must be exactly 100 (got {$totalWeight}).", 400);
        }

        $payload = ['Exams' => $examsClean, 'TotalWeight' => 100];
        $this->firebase->set("Schools/{$school}/{$year}/Results/CumulativeConfig", $payload);

        $this->json_success(['message' => 'Cumulative config saved.']);
    }

    /**
     * POST AJAX — Compute and write Cumulative results for a class/section.
     */
    public function compute_cumulative()
    {
        $this->_require_role(self::ADMIN_ROLES, 'compute cumulative');
        header('Content-Type: application/json');

        $school     = $this->school_name;
        $year       = $this->session_year;
        $classKey   = trim((string) $this->input->post('classKey'));
        $sectionKey = trim((string) $this->input->post('sectionKey'));

        if (!$classKey || !$sectionKey) {
            $this->json_error('Missing classKey or sectionKey.', 400);
        }
        extract($this->_safe_result_params(compact('classKey', 'sectionKey')));

        // Load config
        $config = $this->firebase->get("Schools/{$school}/{$year}/Results/CumulativeConfig") ?? [];
        if (!is_array($config) || empty($config['Exams'])) {
            $this->json_error('No cumulative config found. Save config first.', 400);
        }
        if ((int) ($config['TotalWeight'] ?? 0) !== 100) {
            $this->json_error('TotalWeight must be 100.', 400);
        }

        $examWeights = $config['Exams'];
        $examIds     = array_keys($examWeights);

        // Load computed results per exam
        $allExamResults = [];
        foreach ($examIds as $examId) {
            $node = $this->firebase->get(
                "Schools/{$school}/{$year}/Results/Computed/{$examId}/{$classKey}/{$sectionKey}"
            ) ?? [];
            if (is_array($node)) {
                $allExamResults[$examId] = $node;
            }
        }

        if (empty($allExamResults)) {
            $this->json_error('No computed results found for any included exam.', 400);
        }

        // Gather all student IDs
        $allUids = [];
        foreach ($allExamResults as $examId => $stuMap) {
            foreach (array_keys($stuMap) as $uid) {
                $allUids[$uid] = true;
            }
        }

        $studentCumulative = [];
        foreach (array_keys($allUids) as $uid) {
            $weightedTotal    = 0;
            $subjectWeighted  = [];
            $anyFail          = false;

            foreach ($examIds as $examId) {
                $weight    = (int) ($examWeights[$examId]['Weight'] ?? 0);
                $stuResult = $allExamResults[$examId][$uid] ?? null;
                if (!$stuResult) continue;

                $stuPct  = (float) ($stuResult['Percentage'] ?? 0);
                $weightedTotal += ($stuPct * $weight / 100);

                if (($stuResult['PassFail'] ?? '') === 'Fail') $anyFail = true;

                foreach ($stuResult['Subjects'] ?? [] as $subj => $subjData) {
                    $subjPct = (float) ($subjData['Percentage'] ?? 0);
                    if (!isset($subjectWeighted[$subj])) $subjectWeighted[$subj] = 0;
                    $subjectWeighted[$subj] += ($subjPct * $weight / 100);
                }
            }

            // EX-5 FIX: Track exam coverage for this student
            $totalExams = count($examIds);
            $examsAppeared = 0;
            foreach ($examIds as $eid) {
                if (isset($allExamResults[$eid][$uid])) $examsAppeared++;
            }
            $isPartial = ($examsAppeared < $totalExams);

            // Load grading scale from first available exam
            $scale      = 'Percentage';
            $passingPct = 33;
            foreach ($examIds as $examId) {
                $examMeta = $this->firebase->get("Schools/{$school}/{$year}/Exams/{$examId}");
                if ($examMeta && is_array($examMeta)) {
                    $scale      = $examMeta['GradingScale']  ?? 'Percentage';
                    $passingPct = (int) ($examMeta['PassingPercent'] ?? 33);
                    break;
                }
            }

            $overallGrade = $this->exam_engine->compute_grade($weightedTotal, $scale);
            $overallPass  = $anyFail ? 'Fail' : $this->exam_engine->compute_pass_fail($weightedTotal, $passingPct);

            $subjResults = [];
            foreach ($subjectWeighted as $subj => $ws) {
                $subjResults[$subj] = [
                    'WeightedScore' => round($ws, 2),
                    'Grade'         => $this->exam_engine->compute_grade($ws, $scale),
                    'PassFail'      => $this->exam_engine->compute_pass_fail($ws, $passingPct),
                ];
            }

            $studentCumulative[$uid] = [
                'WeightedTotal' => round($weightedTotal, 2),
                'Grade'         => $overallGrade,
                'PassFail'      => $overallPass,
                'Subjects'      => $subjResults,
                'ComputedAt'    => (int) round(microtime(true) * 1000),
                'ExamsCovered'  => $examsAppeared,    // EX-5 FIX
                'TotalExams'    => $totalExams,       // EX-5 FIX
                'IsPartial'     => $isPartial,        // EX-5 FIX
            ];
        }

        // Sort and assign ranks
        uasort($studentCumulative, fn($a, $b) => $b['WeightedTotal'] <=> $a['WeightedTotal']);
        $this->exam_engine->assign_ranks_assoc($studentCumulative, 'WeightedTotal');

        // EX-2 FIX: Single batch write instead of N individual writes
        $basePath = "Schools/{$school}/{$year}/Results/Cumulative/{$classKey}/{$sectionKey}";
        $this->firebase->set($basePath, $studentCumulative);

        $this->json_success([
            'message' => 'Cumulative computed for ' . count($studentCumulative) . ' student(s).',
            'count'   => count($studentCumulative),
        ]);
    }

    /**
     * GET AJAX — Fetch cumulative results for cumulative view.
     * Returns {students:[...], subjects:[...]} JSON.
     */
    public function get_cumulative_data()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_cumulative_data');
        header('Content-Type: application/json');

        $school     = $this->school_name;
        $year       = $this->session_year;
        $classKey   = trim((string) $this->input->get('classKey'));
        $sectionKey = trim((string) $this->input->get('sectionKey'));

        if (!$classKey || !$sectionKey) {
            echo json_encode(['students' => [], 'subjects' => []]);
            return;
        }
        extract($this->_safe_result_params(compact('classKey', 'sectionKey')));

        // Fix M3: Teachers can only view cumulative data for their assigned classes
        if (($this->admin_role ?? '') === 'Teacher') {
            if (!$this->_teacher_can_access($classKey, $sectionKey)) {
                echo json_encode(['students' => [], 'subjects' => []]);
                return;
            }
        }

        $cumulative = $this->firebase->get(
            "Schools/{$school}/{$year}/Results/Cumulative/{$classKey}/{$sectionKey}"
        ) ?? [];

        if (!is_array($cumulative) || empty($cumulative)) {
            echo json_encode(['students' => [], 'subjects' => []]);
            return;
        }

        $studentList = $this->firebase->get(
            "Schools/{$school}/{$year}/{$classKey}/{$sectionKey}/Students/List"
        ) ?? [];
        if (!is_array($studentList)) $studentList = [];

        $subjects = [];
        foreach ($cumulative as $uid => $res) {
            if (!is_array($res)) continue;
            foreach (array_keys($res['Subjects'] ?? []) as $s) {
                $subjects[$s] = true;
            }
        }
        $subjects = array_keys($subjects);
        sort($subjects);

        $rows = [];
        foreach ($cumulative as $uid => $res) {
            if (!is_array($res)) continue;
            $rows[] = [
                'uid'          => $uid,
                'name'         => is_string($studentList[$uid] ?? null) ? $studentList[$uid] : $uid,
                'rank'         => $res['Rank']          ?? '—',
                'weightedTotal' => $res['WeightedTotal'] ?? 0,
                'grade'        => $res['Grade']         ?? '',
                'passFail'     => $res['PassFail']      ?? '',
                'subjects'     => $res['Subjects']      ?? [],
            ];
        }
        usort($rows, fn($a, $b) => ($a['rank'] ?? 999) <=> ($b['rank'] ?? 999));

        echo json_encode(['students' => $rows, 'subjects' => $subjects]);
    }

    /**
     * GET AJAX — Fetch computed results for class_result view.
     * Returns {students:[...], subjects:[...]} JSON.
     */
    public function get_class_result_data()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_class_result_data');
        header('Content-Type: application/json');

        $school     = $this->school_name;
        $year       = $this->session_year;
        $examId     = trim((string) $this->input->get('examId'));
        $classKey   = trim((string) $this->input->get('classKey'));
        $sectionKey = trim((string) $this->input->get('sectionKey'));

        if (!$examId || !$classKey || !$sectionKey) {
            echo json_encode(['students' => [], 'subjects' => []]);
            return;
        }
        extract($this->_safe_result_params(compact('examId', 'classKey', 'sectionKey')));

        $computedPath = "Schools/{$school}/{$year}/Results/Computed/{$examId}/{$classKey}/{$sectionKey}";
        $computed = $this->firebase->get($computedPath) ?? [];

        if (!is_array($computed) || empty($computed)) {
            echo json_encode(['students' => [], 'subjects' => []]);
            return;
        }

        // Fix H4: Extract and remove stale flag from results
        $stale = !empty($computed['_stale']);
        unset($computed['_stale']);

        // Fix M3: Teachers can only view their assigned classes
        if (($this->admin_role ?? '') === 'Teacher') {
            if (!$this->_teacher_can_access($classKey, $sectionKey)) {
                echo json_encode(['students' => [], 'subjects' => []]);
                return;
            }
        }

        $studentList = $this->firebase->get(
            "Schools/{$school}/{$year}/{$classKey}/{$sectionKey}/Students/List"
        ) ?? [];
        if (!is_array($studentList)) $studentList = [];

        // Collect subject names
        $subjects = [];
        foreach ($computed as $uid => $res) {
            if (!is_array($res)) continue;
            foreach (array_keys($res['Subjects'] ?? []) as $s) {
                $subjects[$s] = true;
            }
        }
        $subjects = array_keys($subjects);
        sort($subjects);

        // ── Attendance eligibility check (config-driven) ──
        $attRules = $this->firebase->get("Schools/{$school}/Config/AttendanceRules");
        $minAttPercent = 0;
        $attEnabled = false;
        if (is_array($attRules) && !empty($attRules['enabled'])) {
            $minAttPercent = floatval($attRules['min_attendance_percent'] ?? 0);
            $attEnabled = ($minAttPercent > 0);
        }

        // Pre-load attendance helper if needed
        if ($attEnabled) {
            $this->load->helper('attendance');
        }

        $sessionParts = explode('-', $year);
        $fromYear = (int)$sessionParts[0];
        $toYear   = $fromYear + 1;
        $sectionBase = "Schools/{$school}/{$year}/{$classKey}/{$sectionKey}/Students";

        $rows = [];
        foreach ($computed as $uid => $res) {
            if (!is_array($res)) continue;
            $row = [
                'uid'      => $uid,
                'name'     => is_string($studentList[$uid] ?? null) ? $studentList[$uid] : $uid,
                'rank'     => $res['Rank']      ?? '—',
                'total'    => $res['TotalMarks'] ?? 0,
                'maxMarks' => $res['MaxMarks']   ?? 0,
                'pct'      => $res['Percentage'] ?? 0,
                'grade'    => $res['Grade']      ?? '',
                'passFail' => $res['PassFail']   ?? '',
                'subjects' => $res['Subjects']   ?? [],
            ];

            // Add attendance percentage + low attendance flag (holidays excluded)
            if ($attEnabled) {
                $studentBase = "{$sectionBase}/{$uid}";
                $attData = get_student_attendance_percent(
                    $this->firebase, $studentBase, $school,
                    'April', $fromYear, 'March', $toYear
                );
                $row['attendance_percent'] = $attData['percent'];
                $row['low_attendance'] = ($attData['percent'] < $minAttPercent);

                // Store eligibility record for audit
                $eligible = ($attData['percent'] >= $minAttPercent);
                try {
                    $this->firebase->set(
                        "Schools/{$school}/{$year}/ExamEligibility/{$uid}/{$examId}",
                        [
                            'attendance_percent' => $attData['percent'],
                            'working_days'       => $attData['working'],
                            'holidays'           => $attData['holiday'] ?? 0,
                            'eligible'           => $eligible,
                            'threshold'          => $minAttPercent,
                            'evaluated_at'       => date('c'),
                        ]
                    );
                } catch (\Exception $e) { /* non-fatal */ }
            }

            $rows[] = $row;
        }
        usort($rows, fn($a, $b) => ($a['rank'] ?? 999) <=> ($b['rank'] ?? 999));

        $response = ['students' => $rows, 'subjects' => $subjects, 'stale' => $stale];
        if ($attEnabled) {
            $response['min_attendance_percent'] = $minAttPercent;
        }
        echo json_encode($response);
    }

    /**
     * GET AJAX — Template + marks + computed status per exam for a class/section.
     */
    public function get_exam_status()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_exam_status');
        header('Content-Type: application/json');

        $school     = $this->school_name;
        $year       = $this->session_year;
        $examId     = trim((string) $this->input->get('examId'));
        $classKey   = trim((string) $this->input->get('classKey'));
        $sectionKey = trim((string) $this->input->get('sectionKey'));

        if (!$examId || !$classKey || !$sectionKey) {
            echo json_encode(['status' => null]);
            return;
        }
        extract($this->_safe_result_params(compact('examId', 'classKey', 'sectionKey')));

        // Fix M3: Teachers can only view their assigned classes
        if (($this->admin_role ?? '') === 'Teacher') {
            if (!$this->_teacher_can_access($classKey, $sectionKey)) {
                echo json_encode(['status' => null]);
                return;
            }
        }

        // Templates
        $templatesNode = $this->firebase->shallow_get(
            "Schools/{$school}/{$year}/Results/Templates/{$examId}/{$classKey}/{$sectionKey}"
        );
        $templateCount = count($templatesNode);

        // Count subjects with marks
        $marksCount = 0;
        foreach ($templatesNode as $subj) {
            $mNode = $this->firebase->shallow_get(
                "Schools/{$school}/{$year}/Results/Marks/{$examId}/{$classKey}/{$sectionKey}/{$subj}"
            );
            if (!empty($mNode)) $marksCount++;
        }

        // Computed
        $computedNode  = $this->firebase->shallow_get(
            "Schools/{$school}/{$year}/Results/Computed/{$examId}/{$classKey}/{$sectionKey}"
        );
        $computedCount = count($computedNode);
        // Fix H4: Don't count _stale as a computed student
        if (isset($computedNode['_stale'])) $computedCount--;

        // Fix H4: Check stale flag
        $stale = isset($computedNode['_stale']);

        echo json_encode([
            'status' => [
                'templates' => $templateCount,
                'marks'     => $marksCount,
                'computed'  => $computedCount,
                'stale'     => $stale,
            ],
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // PDF DOWNLOAD
    // ══════════════════════════════════════════════════════════════════

    /**
     * Download a single student's report card as PDF.
     *
     * URL: result/download_pdf/{userId}/{examId}
     * Reuses the same data-loading logic as report_card(), but captures
     * the view output as a string and passes it through Dompdf.
     */
    public function download_pdf($userId = null, $examId = null)
    {
        $this->_require_role(self::VIEW_ROLES, 'download_pdf');
        $school = $this->school_name;
        $year   = $this->session_year;

        if (!$userId || !$examId) {
            redirect('result');
        }

        // Check if results are withheld for fee defaulter
        try {
            $defaulterStatus = $this->feeDefaulter->isDefaulter($userId);
            if ($defaulterStatus['is_defaulter']) {
                $defaulterNode = $this->firebase->get(
                    "Schools/{$school}/{$year}/Fees/Defaulters/{$userId}"
                );
                if (!empty($defaulterNode['result_withheld'])) {
                    $forceOverride = $this->input->get_post('force_override');
                    if (!$forceOverride) {
                        $this->json_error(
                            'Results withheld due to outstanding fees of Rs. ' . $defaulterStatus['total_dues'],
                            403
                        );
                        return;
                    }
                    // Log override
                    $this->firebase->push(
                        "Schools/{$school}/{$year}/Fees/Audit_Logs",
                        [
                            'event'         => 'result_withhold_override',
                            'student_id'    => $userId,
                            'overridden_by' => $this->admin_id ?? 'system',
                            'timestamp'     => date('c'),
                        ]
                    );
                }
            }
        } catch (Exception $e) {
            log_message('error', "Fee defaulter check failed in Result: " . $e->getMessage());
        }

        // ── Load all data (same as report_card()) ──
        $data = $this->_load_report_card_data($userId, $examId);
        if ($data === null) {
            redirect('result');
        }

        // ── Render the template view to a string ──
        $html = $this->load->view('result/report_card', $data, true);

        // ── Build filename: StudentName_Class_Section.pdf ──
        $profile  = $data['profile'];
        $name     = trim($profile['Name'] ?? 'Student');
        $class    = trim(str_replace('Class ', '', $data['classKey']));
        $section  = trim(str_replace('Section ', '', $data['sectionKey']));
        $filename = "{$name}_{$class}_{$section}.pdf";

        // ── Generate and download PDF ──
        $this->load->library('pdf_generator');
        $this->pdf_generator->download($html, $filename);
    }

    /**
     * Download batch report cards as a ZIP of individual PDFs.
     *
     * URL: result/download_batch_pdf/{examId}/{classKey}/{sectionKey}
     *
     * Architecture: Generates one PDF per student, writes each to temp dir,
     * then zips and streams. This keeps peak memory to ~1 student's PDF at
     * a time instead of holding all 500 in memory simultaneously.
     */
    public function download_batch_pdf($examId = null, $classKey = null, $sectionKey = null)
    {
        $this->_require_role(self::ADMIN_ROLES, 'download_batch_pdf');

        $school = $this->school_name;
        $year   = $this->session_year;

        if (!$examId || !$classKey || !$sectionKey) {
            redirect('result/class_result');
        }
        $examId     = urldecode($examId);
        $classKey   = urldecode($classKey);
        $sectionKey = urldecode($sectionKey);

        // ── Bump limits for large batches ──
        set_time_limit(600);  // 10 minutes
        ini_set('memory_limit', '512M');

        // ── Load shared data (one Firebase read each) ──
        $exam = $this->firebase->get("Schools/{$school}/{$year}/Exams/{$examId}");
        if (!$exam || !is_array($exam)) {
            redirect('result/class_result');
        }
        $exam = array_merge(['id' => $examId], $exam);

        $templates = $this->firebase->get(
            "Schools/{$school}/{$year}/Results/Templates/{$examId}/{$classKey}/{$sectionKey}"
        ) ?? [];
        if (!is_array($templates)) $templates = [];

        $computed = $this->firebase->get(
            "Schools/{$school}/{$year}/Results/Computed/{$examId}/{$classKey}/{$sectionKey}"
        ) ?? [];
        if (!is_array($computed)) $computed = [];
        unset($computed['_stale']);

        $allMarks = $this->firebase->get(
            "Schools/{$school}/{$year}/Results/Marks/{$examId}/{$classKey}/{$sectionKey}"
        ) ?? [];
        if (!is_array($allMarks)) $allMarks = [];

        $schoolInfo = $this->firebase->get("Schools/{$school}/Info") ?? [];
        if (!is_array($schoolInfo)) $schoolInfo = [];

        $school_id   = $this->parent_db_key;
        $allProfiles = $this->firebase->get("Users/Parents/{$school_id}") ?? [];
        if (!is_array($allProfiles)) $allProfiles = [];

        $rcTemplate = $this->firebase->get("Schools/{$school}/Config/ReportCardTemplate");
        $rcAllowed  = ['classic', 'cbse', 'minimal', 'modern', 'elegant'];
        if (!$rcTemplate || !is_string($rcTemplate) || !in_array($rcTemplate, $rcAllowed, true)) {
            $rcTemplate = 'classic';
        }

        // ── Build student list ──
        $roster  = $this->exam_engine->get_student_names($classKey, $sectionKey);
        $userIds = array_unique(array_merge(array_keys($computed), array_keys($roster)));
        sort($userIds);

        // ── Generate PDFs one at a time ──
        $this->load->library('pdf_generator');
        $items = [];
        $classShort   = trim(str_replace('Class ', '', $classKey));
        $sectionShort = trim(str_replace('Section ', '', $sectionKey));

        foreach ($userIds as $userId) {
            if (!isset($computed[$userId])) continue;

            $profile = (isset($allProfiles[$userId]) && is_array($allProfiles[$userId]))
                ? $allProfiles[$userId] : [];

            // Per-student marks
            $stuMarks = [];
            foreach ($templates as $subject => $tmp) {
                $stuMarks[$subject] = $allMarks[$subject][$userId] ?? [];
            }

            $data = [
                'userId'      => $userId,
                'examId'      => $examId,
                'exam'        => $exam,
                'profile'     => $profile,
                'classKey'    => $classKey,
                'sectionKey'  => $sectionKey,
                'computed'    => $computed[$userId],
                'templates'   => $templates,
                'marks'       => $stuMarks,
                'schoolInfo'  => $schoolInfo,
                'schoolName'  => $school,
                'sessionYear' => $year,
                'rc_template' => $rcTemplate,
            ];

            // Render to HTML string
            $html = $this->load->view('result/report_card', $data, true);

            // Filename: StudentName_Class_Section.pdf
            $name = trim($profile['Name'] ?? $userId);
            $items[] = [
                'html'     => $html,
                'filename' => "{$name}_{$classShort}_{$sectionShort}.pdf",
            ];

            // Free the HTML string immediately
            unset($html, $data, $stuMarks, $profile);
        }

        if (empty($items)) {
            redirect('result/class_result');
        }

        // ── ZIP and download ──
        $examSafe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $exam['Name'] ?? 'Exam');
        $zipName  = "Report_Cards_{$examSafe}_{$classShort}_{$sectionShort}.zip";
        $this->pdf_generator->batch_download($items, $zipName);
    }

    /**
     * AJAX endpoint: check batch PDF generation progress.
     * Returns estimated count so UI can show progress.
     *
     * POST: {examId, classKey, sectionKey}
     */
    public function batch_pdf_count()
    {
        $this->_require_role(self::ADMIN_ROLES, 'batch_pdf_count');
        header('Content-Type: application/json');

        $school = $this->school_name;
        $year   = $this->session_year;

        $examId     = trim((string) $this->input->post('examId'));
        $classKey   = trim((string) $this->input->post('classKey'));
        $sectionKey = trim((string) $this->input->post('sectionKey'));

        if (!$examId || !$classKey || !$sectionKey) {
            echo json_encode(['count' => 0]);
            return;
        }

        $computed = $this->firebase->get(
            "Schools/{$school}/{$year}/Results/Computed/{$examId}/{$classKey}/{$sectionKey}"
        ) ?? [];
        if (!is_array($computed)) $computed = [];
        unset($computed['_stale']);

        echo json_encode(['count' => count($computed)]);
    }

    // ──────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────────────────────────

    /**
     * Load all data needed for a single student's report card.
     * Shared by report_card() and download_pdf().
     *
     * @return array|null  View data array, or null if invalid
     */
    private function _load_report_card_data(string $userId, string $examId): ?array
    {
        $school = $this->school_name;
        $year   = $this->session_year;

        $exam = $this->firebase->get("Schools/{$school}/{$year}/Exams/{$examId}");
        if (!$exam || !is_array($exam)) return null;
        $exam = array_merge(['id' => $examId], $exam);

        $school_id = $this->parent_db_key;
        $profile   = $this->firebase->get("Users/Parents/{$school_id}/{$userId}") ?? [];
        if (!is_array($profile)) $profile = [];

        $className  = trim($profile['Class']   ?? '');
        $section    = trim($profile['Section'] ?? '');
        $classKey   = $className ? "Class {$className}"   : '';
        $sectionKey = $section   ? "Section {$section}"   : '';

        // RBAC
        if ($classKey && $sectionKey && !$this->_teacher_can_access($classKey, $sectionKey)) {
            show_error('You do not have permission to view this report card.', 403, 'Access Denied');
        }

        // Computed result
        $computed = [];
        if ($classKey && $sectionKey) {
            $c = $this->firebase->get(
                "Schools/{$school}/{$year}/Results/Computed/{$examId}/{$classKey}/{$sectionKey}/{$userId}"
            );
            if (is_array($c)) $computed = $c;
        }
        if (empty($computed)) {
            $computed = [
                'TotalMarks' => 0, 'MaxMarks' => 0, 'Percentage' => 0,
                'Grade' => '', 'PassFail' => '', 'Rank' => '', 'Subjects' => [],
            ];
        }

        // Templates
        $templates = [];
        if ($classKey && $sectionKey) {
            $t = $this->firebase->get(
                "Schools/{$school}/{$year}/Results/Templates/{$examId}/{$classKey}/{$sectionKey}"
            );
            if (is_array($t)) $templates = $t;
        }

        // Marks (single read)
        $marks = [];
        if ($classKey && $sectionKey) {
            $allMarks = $this->firebase->get(
                "Schools/{$school}/{$year}/Results/Marks/{$examId}/{$classKey}/{$sectionKey}"
            );
            if (is_array($allMarks)) {
                foreach ($templates as $subject => $tmp) {
                    if (isset($allMarks[$subject][$userId]) && is_array($allMarks[$subject][$userId])) {
                        $marks[$subject] = $allMarks[$subject][$userId];
                    }
                }
            }
        }

        // School info
        $schoolInfo = $this->firebase->get("Schools/{$school}/Info") ?? [];
        if (!is_array($schoolInfo)) $schoolInfo = [];

        // Template style
        $rcTemplate = $this->firebase->get("Schools/{$school}/Config/ReportCardTemplate");
        $rcAllowed  = ['classic', 'cbse', 'minimal', 'modern', 'elegant'];
        if (!$rcTemplate || !is_string($rcTemplate) || !in_array($rcTemplate, $rcAllowed, true)) {
            $rcTemplate = 'classic';
        }

        return [
            'userId'      => $userId,
            'examId'      => $examId,
            'exam'        => $exam,
            'profile'     => $profile,
            'classKey'    => $classKey,
            'sectionKey'  => $sectionKey,
            'computed'    => $computed,
            'templates'   => $templates,
            'marks'       => $marks,
            'schoolInfo'  => $schoolInfo,
            'schoolName'  => $school,
            'sessionYear' => $year,
            'rc_template' => $rcTemplate,
        ];
    }

}
