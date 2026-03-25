<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Examination controller — Advanced Examination Management Hub
 *
 * Adds merit lists, performance analytics, tabulation sheets, and bulk
 * result processing on top of the existing Exam + Result modules.
 *
 * Firebase paths READ (never written by this controller except bulk_compute):
 *   Schools/{school}/{year}/Exams/{EXM0001}/...
 *   Schools/{school}/{year}/Results/Computed/{examId}/{classKey}/{sectionKey}/{userId}
 *   Schools/{school}/{year}/Results/Marks/{examId}/{classKey}/{sectionKey}/{subject}/{userId}
 *   Schools/{school}/{year}/Results/Templates/{examId}/{classKey}/{sectionKey}/{subject}
 *   Schools/{school}/{year}/Class 9th/Section A/Students/List/{userId: name}
 *   Schools/{school}/Subject_list/{classKey}/{code}
 *   Users/Parents/{school_id}/{userId}
 *
 * Firebase paths WRITTEN by bulk_compute:
 *   Schools/{school}/{year}/Results/Computed/{examId}/{classKey}/{sectionKey}/{userId}
 *
 * Grade engine: exact copy of Result.php thresholds — MUST stay in sync.
 *
 * classKey   = "Class 9th"   (full prefix)
 * sectionKey = "Section A"   (full prefix)
 *
 * RBAC:
 *   Super Admin / Admin — full access (all views + bulk_compute + export)
 *   Teacher             — merit_list, analytics, tabulation (read-only, own classes only filtered client-side)
 */
class Examination extends MY_Controller
{
    /** Roles allowed to bulk-compute and export. */
    const ADMIN_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'];

    /** Roles allowed to view examination data (read-only). */
    const VIEW_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'];

    public function __construct()
    {
        parent::__construct();
        require_permission('Examinations');
        $this->load->library('exam_engine');
        $this->exam_engine->init($this->firebase, $this->school_name, $this->session_year);

        $this->load->library('Fee_defaulter_check', null, 'feeDefaulter');
        $this->feeDefaulter->init($this->firebase, $this->school_name, $this->session_year);

        // Firestore helper
        $this->load->library('Firestore_helper', null, 'fs');
        $this->fs->init($this->firebase, $this->school_name, $this->session_year);
    }

    // ══════════════════════════════════════════════════════════════════════
    // PAGE VIEWS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Dashboard hub — overview of exams, quick actions, recent activity.
     */
    public function index()
    {
        $this->_require_role(self::VIEW_ROLES, 'view examination dashboard');
        $school = $this->school_name;
        $year   = $this->session_year;

        $raw   = $this->firebase->get("Schools/{$school}/{$year}/Exams") ?? [];
        $exams = [];
        $stats = ['total' => 0, 'published' => 0, 'completed' => 0, 'draft' => 0];

        foreach ($raw as $id => $e) {
            if ($id === 'Count' || !is_array($e)) continue;
            $stats['total']++;
            $status = $e['Status'] ?? 'Draft';
            if ($status === 'Published')  $stats['published']++;
            if ($status === 'Completed')  $stats['completed']++;
            if ($status === 'Draft')      $stats['draft']++;
            $exams[] = array_merge(['id' => $id], $e);
        }

        // Sort by CreatedAt desc for recent activity
        usort($exams, function($a, $b) { return ($b['CreatedAt'] ?? 0) <=> ($a['CreatedAt'] ?? 0); });

        // Recent 5 exams for the activity feed
        $recentExams = array_slice($exams, 0, 5);

        // Class structure for quick-action dropdowns
        $structure = $this->exam_engine->get_class_structure();

        $this->load->view('include/header');
        $this->load->view('examination/index', [
            'stats'       => $stats,
            'exams'       => $exams,
            'recentExams' => $recentExams,
            'structure'   => $structure,
        ]);
        $this->load->view('include/footer');
    }

    /**
     * Merit list page — select exam, class; renders merit tables via AJAX.
     */
    public function merit_list()
    {
        $this->_require_role(self::VIEW_ROLES, 'view merit list');
        $school    = $this->school_name;
        $year      = $this->session_year;
        $exams     = $this->exam_engine->get_active_exams();
        $structure = $this->exam_engine->get_class_structure();

        $this->load->view('include/header');
        $this->load->view('examination/merit_list', [
            'exams'     => $exams,
            'structure' => $structure,
        ]);
        $this->load->view('include/footer');
    }

    /**
     * Performance analytics page — charts and tables via AJAX.
     */
    public function analytics()
    {
        $this->_require_role(self::VIEW_ROLES, 'view examination analytics');
        $exams     = $this->exam_engine->get_active_exams();
        $structure = $this->exam_engine->get_class_structure();

        $this->load->view('include/header');
        $this->load->view('examination/analytics', [
            'exams'     => $exams,
            'structure' => $structure,
        ]);
        $this->load->view('include/footer');
    }

    /**
     * Tabulation sheet page — full marks table for print.
     */
    public function tabulation()
    {
        $this->_require_role(self::VIEW_ROLES, 'view tabulation sheet');
        $exams     = $this->exam_engine->get_active_exams();
        $structure = $this->exam_engine->get_class_structure();

        $this->load->view('include/header');
        $this->load->view('examination/tabulation', [
            'exams'     => $exams,
            'structure' => $structure,
        ]);
        $this->load->view('include/footer');
    }

    // ══════════════════════════════════════════════════════════════════════
    // AJAX ENDPOINTS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * POST AJAX — Merit list data for a given exam + class (all sections or one).
     *
     * Input: examId, classKey, sectionKey (optional — empty = all sections), topN (default 10)
     * Returns: {toppers, subjectToppers, classToppers}
     */
    public function get_merit_data()
    {
        $this->_require_role(self::VIEW_ROLES, 'view merit data');
        header('Content-Type: application/json');

        $school     = $this->school_name;
        $year       = $this->session_year;
        $examId     = $this->safe_path_segment(trim((string) $this->input->post('examId')), 'examId');
        $classKey   = $this->safe_path_segment(trim((string) $this->input->post('classKey')), 'classKey');
        $sectionKey = $this->safe_path_segment(trim((string) $this->input->post('sectionKey')), 'sectionKey');
        $topN       = max(1, min(100, (int) ($this->input->post('topN') ?: 10)));

        if (!$examId || !$classKey) {
            $this->json_error('Exam and class are required.', 400);
        }

        $exam = $this->firebase->get("Schools/{$school}/{$year}/Exams/{$examId}");
        if (!$exam || !is_array($exam)) {
            $this->json_error('Exam not found.', 404);
        }

        $structure = $this->exam_engine->get_class_structure();
        $sections  = $structure[$classKey] ?? [];
        if (empty($sections)) {
            $this->json_error('No sections found for this class.', 404);
        }

        // If specific section requested, filter
        if ($sectionKey) {
            $letter = str_replace('Section ', '', $sectionKey);
            if (!in_array($letter, $sections, true)) {
                $this->json_error('Section not found.', 404);
            }
            $sections = [$letter];
        }

        // Collect computed results across sections
        $allResults      = []; // uid => {data + section}
        $subjectScores   = []; // subject => [uid => {score, maxMarks, name, section}]

        foreach ($sections as $letter) {
            $secKey  = "Section {$letter}";
            $computed = $this->firebase->get(
                "Schools/{$school}/{$year}/Results/Computed/{$examId}/{$classKey}/{$secKey}"
            );
            if (!is_array($computed) || empty($computed)) continue;

            // Load student names for this section
            $roster = $this->exam_engine->get_student_names($classKey, $secKey);

            foreach ($computed as $uid => $res) {
                if (!is_array($res)) continue;
                $studentName = $roster[$uid] ?? $uid;

                $allResults[$uid] = [
                    'userId'     => $uid,
                    'name'       => $studentName,
                    'section'    => $letter,
                    'totalMarks' => (int) ($res['TotalMarks'] ?? 0),
                    'maxMarks'   => (int) ($res['MaxMarks'] ?? 0),
                    'percentage' => (float) ($res['Percentage'] ?? 0),
                    'grade'      => $res['Grade'] ?? '',
                    'passFail'   => $res['PassFail'] ?? '',
                ];

                // Subject-wise scores
                $subjects = $res['Subjects'] ?? [];
                if (is_array($subjects)) {
                    foreach ($subjects as $subj => $subjData) {
                        if (!is_array($subjData)) continue;
                        $subjectScores[$subj][$uid] = [
                            'name'       => $studentName,
                            'section'    => $letter,
                            'total'      => (int) ($subjData['Total'] ?? 0),
                            'maxMarks'   => (int) ($subjData['MaxMarks'] ?? 0),
                            'percentage' => (float) ($subjData['Percentage'] ?? 0),
                            'absent'     => !empty($subjData['Absent']),
                        ];
                    }
                }
            }
        }

        if (empty($allResults)) {
            $this->json_error('No computed results found. Please compute results first.', 404);
        }

        // Sort by percentage desc — competition ranking
        uasort($allResults, function($a, $b) { return $b['percentage'] <=> $a['percentage']; });

        $toppers = $this->exam_engine->assign_ranks(array_values($allResults));
        $toppers = array_slice($toppers, 0, $topN);

        // Class toppers (across all sections) — same as toppers if no section filter
        $classToppers = $toppers;

        // Subject-wise toppers
        $subjectToppers = [];
        foreach ($subjectScores as $subj => $scores) {
            // Exclude absent students
            $eligible = array_filter($scores, function($s) { return !$s['absent']; });
            uasort($eligible, function($a, $b) { return $b['percentage'] <=> $a['percentage']; });
            $ranked = $this->exam_engine->assign_ranks(array_values($eligible));
            $subjectToppers[$subj] = array_slice($ranked, 0, min(5, $topN));
        }

        $this->json_success([
            'toppers'        => $toppers,
            'classToppers'   => $classToppers,
            'subjectToppers' => $subjectToppers,
            'examName'       => $exam['Name'] ?? $examId,
            'className'      => $classKey,
            'totalStudents'  => count($allResults),
            'csrf_token'     => $this->security->get_csrf_hash(),
        ]);
    }

    /**
     * POST AJAX — Performance analytics data.
     *
     * Input: examId, classKey, sectionKey (optional)
     * Returns: {classAvg, passRate, gradeDistribution, subjectAnalysis, trends}
     */
    public function get_analytics_data()
    {
        $this->_require_role(self::VIEW_ROLES, 'view analytics data');
        header('Content-Type: application/json');

        $school     = $this->school_name;
        $year       = $this->session_year;
        $examId     = $this->safe_path_segment(trim((string) $this->input->post('examId')), 'examId');
        $classKey   = $this->safe_path_segment(trim((string) $this->input->post('classKey')), 'classKey');
        $sectionKey = $this->safe_path_segment(trim((string) $this->input->post('sectionKey')), 'sectionKey');

        if (!$examId || !$classKey) {
            $this->json_error('Exam and class are required.', 400);
        }

        $exam = $this->firebase->get("Schools/{$school}/{$year}/Exams/{$examId}");
        if (!$exam || !is_array($exam)) {
            $this->json_error('Exam not found.', 404);
        }

        $scale      = $exam['GradingScale'] ?? 'Percentage';
        $passingPct = (int) ($exam['PassingPercent'] ?? 33);

        $structure = $this->exam_engine->get_class_structure();
        $sections  = $structure[$classKey] ?? [];
        if (empty($sections)) {
            $this->json_error('No sections found for this class.', 404);
        }

        // Filter to specific section if provided
        if ($sectionKey) {
            $letter = str_replace('Section ', '', $sectionKey);
            if (!in_array($letter, $sections, true)) {
                $this->json_error('Section not found.', 404);
            }
            $sections = [$letter];
        }

        // Aggregate data across sections
        $totalPct       = 0;
        $totalStudents  = 0;
        $passCount      = 0;
        $failCount      = 0;
        $gradeDistrib   = [];
        $subjectData    = []; // subject => {totalPct, count, max, min, passCount, failCount}
        $sectionResults = []; // section => {avg, passRate, count}

        foreach ($sections as $letter) {
            $secKey   = "Section {$letter}";
            $computed = $this->firebase->get(
                "Schools/{$school}/{$year}/Results/Computed/{$examId}/{$classKey}/{$secKey}"
            );
            if (!is_array($computed) || empty($computed)) continue;

            $secTotal = 0;
            $secCount = 0;
            $secPass  = 0;

            foreach ($computed as $uid => $res) {
                if (!is_array($res)) continue;

                $pct   = (float) ($res['Percentage'] ?? 0);
                $grade = $res['Grade'] ?? 'N/A';
                $pf    = $res['PassFail'] ?? 'Fail';

                $totalPct += $pct;
                $totalStudents++;
                $secTotal += $pct;
                $secCount++;

                if ($pf === 'Pass') { $passCount++; $secPass++; }
                else                { $failCount++; }

                // Grade distribution
                if (!isset($gradeDistrib[$grade])) $gradeDistrib[$grade] = 0;
                $gradeDistrib[$grade]++;

                // Subject-wise analysis
                $subjects = $res['Subjects'] ?? [];
                if (is_array($subjects)) {
                    foreach ($subjects as $subj => $sd) {
                        if (!is_array($sd)) continue;
                        $sPct = (float) ($sd['Percentage'] ?? 0);
                        $sPf  = $sd['PassFail'] ?? 'Fail';

                        if (!isset($subjectData[$subj])) {
                            $subjectData[$subj] = [
                                'totalPct'  => 0,
                                'count'     => 0,
                                'highest'   => 0,
                                'lowest'    => 100,
                                'passCount' => 0,
                                'failCount' => 0,
                            ];
                        }
                        $subjectData[$subj]['totalPct'] += $sPct;
                        $subjectData[$subj]['count']++;
                        if ($sPct > $subjectData[$subj]['highest']) $subjectData[$subj]['highest'] = $sPct;
                        if ($sPct < $subjectData[$subj]['lowest'])  $subjectData[$subj]['lowest']  = $sPct;
                        if ($sPf === 'Pass') $subjectData[$subj]['passCount']++;
                        else                 $subjectData[$subj]['failCount']++;
                    }
                }
            }

            if ($secCount > 0) {
                $sectionResults[$letter] = [
                    'avg'      => round($secTotal / $secCount, 2),
                    'passRate' => round($secPass / $secCount * 100, 2),
                    'count'    => $secCount,
                ];
            }
        }

        if ($totalStudents === 0) {
            $this->json_error('No computed results found. Please compute results first.', 404);
        }

        // Build subject analysis
        $subjectAnalysis = [];
        foreach ($subjectData as $subj => $sd) {
            $subjectAnalysis[$subj] = [
                'average'  => round($sd['totalPct'] / $sd['count'], 2),
                'highest'  => round($sd['highest'], 2),
                'lowest'   => round($sd['lowest'], 2),
                'passRate' => round($sd['passCount'] / $sd['count'] * 100, 2),
                'students' => $sd['count'],
            ];
        }

        // Sort grade distribution by grade quality (best first)
        ksort($gradeDistrib);

        $this->json_success([
            'classAvg'          => round($totalPct / $totalStudents, 2),
            'passRate'          => round($passCount / $totalStudents * 100, 2),
            'totalStudents'     => $totalStudents,
            'passCount'         => $passCount,
            'failCount'         => $failCount,
            'gradeDistribution' => $gradeDistrib,
            'subjectAnalysis'   => $subjectAnalysis,
            'sectionResults'    => $sectionResults,
            'examName'          => $exam['Name'] ?? $examId,
            'className'         => $classKey,
            'gradingScale'      => $scale,
            'passingPercent'    => $passingPct,
            'csrf_token'        => $this->security->get_csrf_hash(),
        ]);
    }

    /**
     * POST AJAX — Compare two exams for the same class/section.
     *
     * Input: examId1, examId2, classKey, sectionKey
     * Returns: {exam1, exam2, studentComparison, subjectComparison}
     */
    public function get_exam_comparison()
    {
        $this->_require_role(self::VIEW_ROLES, 'view exam comparison');
        header('Content-Type: application/json');

        $school     = $this->school_name;
        $year       = $this->session_year;
        $examId1    = $this->safe_path_segment(trim((string) $this->input->post('examId1')), 'examId1');
        $examId2    = $this->safe_path_segment(trim((string) $this->input->post('examId2')), 'examId2');
        $classKey   = $this->safe_path_segment(trim((string) $this->input->post('classKey')), 'classKey');
        $sectionKey = $this->safe_path_segment(trim((string) $this->input->post('sectionKey')), 'sectionKey');

        if (!$examId1 || !$examId2 || !$classKey || !$sectionKey) {
            $this->json_error('All fields are required for comparison.', 400);
        }

        if ($examId1 === $examId2) {
            $this->json_error('Please select two different exams.', 400);
        }

        // Load exam metadata
        $exam1 = $this->firebase->get("Schools/{$school}/{$year}/Exams/{$examId1}");
        $exam2 = $this->firebase->get("Schools/{$school}/{$year}/Exams/{$examId2}");

        if (!is_array($exam1) || !is_array($exam2)) {
            $this->json_error('One or both exams not found.', 404);
        }

        // Load computed results for both exams
        $basePath1 = "Schools/{$school}/{$year}/Results/Computed/{$examId1}/{$classKey}/{$sectionKey}";
        $basePath2 = "Schools/{$school}/{$year}/Results/Computed/{$examId2}/{$classKey}/{$sectionKey}";

        $computed1 = $this->firebase->get($basePath1) ?? [];
        $computed2 = $this->firebase->get($basePath2) ?? [];

        if (!is_array($computed1)) $computed1 = [];
        if (!is_array($computed2)) $computed2 = [];

        if (empty($computed1) && empty($computed2)) {
            $this->json_error('No computed results found for either exam.', 404);
        }

        // Student names
        $roster = $this->exam_engine->get_student_names($classKey, $sectionKey);

        // Common students
        $allUids = array_unique(array_merge(array_keys($computed1), array_keys($computed2)));

        $studentComparison = [];
        foreach ($allUids as $uid) {
            $r1 = $computed1[$uid] ?? null;
            $r2 = $computed2[$uid] ?? null;

            $pct1 = $r1 ? (float) ($r1['Percentage'] ?? 0) : null;
            $pct2 = $r2 ? (float) ($r2['Percentage'] ?? 0) : null;

            $diff = ($pct1 !== null && $pct2 !== null) ? round($pct2 - $pct1, 2) : null;

            $studentComparison[] = [
                'userId'  => $uid,
                'name'    => $roster[$uid] ?? $uid,
                'exam1'   => $r1 ? [
                    'percentage' => $pct1,
                    'grade'      => $r1['Grade'] ?? '',
                    'rank'       => (int) ($r1['Rank'] ?? 0),
                    'passFail'   => $r1['PassFail'] ?? '',
                ] : null,
                'exam2'   => $r2 ? [
                    'percentage' => $pct2,
                    'grade'      => $r2['Grade'] ?? '',
                    'rank'       => (int) ($r2['Rank'] ?? 0),
                    'passFail'   => $r2['PassFail'] ?? '',
                ] : null,
                'diff'    => $diff,
            ];
        }

        // Sort by exam2 percentage desc (or exam1 if exam2 missing)
        usort($studentComparison, function ($a, $b) {
            $aPct = $a['exam2']['percentage'] ?? $a['exam1']['percentage'] ?? 0;
            $bPct = $b['exam2']['percentage'] ?? $b['exam1']['percentage'] ?? 0;
            return $bPct <=> $aPct;
        });

        // Subject-wise comparison (aggregate averages)
        $subjectComparison = [];
        $subj1Totals = [];
        $subj2Totals = [];

        foreach ($computed1 as $uid => $res) {
            if (!is_array($res) || !isset($res['Subjects'])) continue;
            foreach ($res['Subjects'] as $subj => $sd) {
                if (!is_array($sd)) continue;
                if (!isset($subj1Totals[$subj])) $subj1Totals[$subj] = ['sum' => 0, 'count' => 0];
                $subj1Totals[$subj]['sum'] += (float) ($sd['Percentage'] ?? 0);
                $subj1Totals[$subj]['count']++;
            }
        }
        foreach ($computed2 as $uid => $res) {
            if (!is_array($res) || !isset($res['Subjects'])) continue;
            foreach ($res['Subjects'] as $subj => $sd) {
                if (!is_array($sd)) continue;
                if (!isset($subj2Totals[$subj])) $subj2Totals[$subj] = ['sum' => 0, 'count' => 0];
                $subj2Totals[$subj]['sum'] += (float) ($sd['Percentage'] ?? 0);
                $subj2Totals[$subj]['count']++;
            }
        }

        $allSubjects = array_unique(array_merge(array_keys($subj1Totals), array_keys($subj2Totals)));
        foreach ($allSubjects as $subj) {
            $avg1 = isset($subj1Totals[$subj]) ? round($subj1Totals[$subj]['sum'] / $subj1Totals[$subj]['count'], 2) : null;
            $avg2 = isset($subj2Totals[$subj]) ? round($subj2Totals[$subj]['sum'] / $subj2Totals[$subj]['count'], 2) : null;
            $subjectComparison[$subj] = [
                'exam1Avg' => $avg1,
                'exam2Avg' => $avg2,
                'diff'     => ($avg1 !== null && $avg2 !== null) ? round($avg2 - $avg1, 2) : null,
            ];
        }

        $this->json_success([
            'exam1' => [
                'id'   => $examId1,
                'name' => $exam1['Name'] ?? $examId1,
                'type' => $exam1['Type'] ?? '',
            ],
            'exam2' => [
                'id'   => $examId2,
                'name' => $exam2['Name'] ?? $examId2,
                'type' => $exam2['Type'] ?? '',
            ],
            'studentComparison' => $studentComparison,
            'subjectComparison' => $subjectComparison,
            'className'         => $classKey,
            'sectionKey'        => $sectionKey,
            'csrf_token'        => $this->security->get_csrf_hash(),
        ]);
    }

    /**
     * POST AJAX — Full tabulation data for an exam + class + section.
     *
     * Input: examId, classKey, sectionKey
     * Returns: {students[], subjects[], marks{}, templates{}, examName, computed{}}
     */
    public function get_tabulation_data()
    {
        $this->_require_role(self::VIEW_ROLES, 'view tabulation data');
        header('Content-Type: application/json');

        $school     = $this->school_name;
        $year       = $this->session_year;
        $examId     = $this->safe_path_segment(trim((string) $this->input->post('examId')), 'examId');
        $classKey   = $this->safe_path_segment(trim((string) $this->input->post('classKey')), 'classKey');
        $sectionKey = $this->safe_path_segment(trim((string) $this->input->post('sectionKey')), 'sectionKey');

        if (!$examId || !$classKey || !$sectionKey) {
            $this->json_error('Exam, class, and section are required.', 400);
        }

        $exam = $this->firebase->get("Schools/{$school}/{$year}/Exams/{$examId}");
        if (!$exam || !is_array($exam)) {
            $this->json_error('Exam not found.', 404);
        }

        // Load templates
        $templates = $this->firebase->get(
            "Schools/{$school}/{$year}/Results/Templates/{$examId}/{$classKey}/{$sectionKey}"
        ) ?? [];
        if (!is_array($templates)) $templates = [];

        // Load marks
        $marksNode = $this->firebase->get(
            "Schools/{$school}/{$year}/Results/Marks/{$examId}/{$classKey}/{$sectionKey}"
        ) ?? [];
        if (!is_array($marksNode)) $marksNode = [];

        // Load computed results (for totals, grades, ranks)
        $computed = $this->firebase->get(
            "Schools/{$school}/{$year}/Results/Computed/{$examId}/{$classKey}/{$sectionKey}"
        ) ?? [];
        if (!is_array($computed)) $computed = [];

        // Student roster
        $roster = $this->exam_engine->get_student_names($classKey, $sectionKey);

        // Collect all student IDs (from marks + computed)
        $allUids = [];
        foreach ($marksNode as $subj => $stuMarks) {
            if (is_array($stuMarks)) {
                foreach (array_keys($stuMarks) as $uid) {
                    $allUids[$uid] = true;
                }
            }
        }
        foreach (array_keys($computed) as $uid) {
            $allUids[$uid] = true;
        }

        if (empty($allUids)) {
            $this->json_error('No marks or results found for this selection.', 404);
        }

        // Build students array with names
        $students = [];
        foreach (array_keys($allUids) as $uid) {
            $students[] = [
                'userId' => $uid,
                'name'   => $roster[$uid] ?? $uid,
            ];
        }
        // Sort alphabetically by name
        usort($students, function($a, $b) { return strcasecmp($a['name'], $b['name']); });

        // Extract subject list from templates
        $subjects = [];
        foreach ($templates as $subj => $tmpl) {
            if (!is_array($tmpl)) continue;
            $subjects[] = [
                'name'     => $subj,
                'maxMarks' => (int) ($tmpl['TotalMaxMarks'] ?? 0),
                'components' => is_array($tmpl['Components'] ?? null) ? $tmpl['Components'] : [],
            ];
        }

        // Build marks matrix: subject => userId => {Total, components...}
        $marksMatrix = [];
        foreach ($marksNode as $subj => $stuMarks) {
            if (!is_array($stuMarks)) continue;
            $marksMatrix[$subj] = [];
            foreach ($stuMarks as $uid => $mData) {
                if (!is_array($mData)) continue;
                $marksMatrix[$subj][$uid] = $mData;
            }
        }

        // Build computed summary per student
        $computedSummary = [];
        foreach ($computed as $uid => $res) {
            if (!is_array($res)) continue;
            $computedSummary[$uid] = [
                'totalMarks' => (int) ($res['TotalMarks'] ?? 0),
                'maxMarks'   => (int) ($res['MaxMarks'] ?? 0),
                'percentage' => (float) ($res['Percentage'] ?? 0),
                'grade'      => $res['Grade'] ?? '',
                'passFail'   => $res['PassFail'] ?? '',
                'rank'       => (int) ($res['Rank'] ?? 0),
            ];
        }

        $this->json_success([
            'students'        => $students,
            'subjects'        => $subjects,
            'marks'           => $marksMatrix,
            'computed'        => $computedSummary,
            'examName'        => $exam['Name'] ?? $examId,
            'examType'        => $exam['Type'] ?? '',
            'className'       => $classKey,
            'sectionKey'      => $sectionKey,
            'gradingScale'    => $exam['GradingScale'] ?? 'Percentage',
            'passingPercent'  => (int) ($exam['PassingPercent'] ?? 33),
            'csrf_token'      => $this->security->get_csrf_hash(),
        ]);
    }

    /**
     * POST AJAX — Bulk compute results for multiple sections at once.
     *
     * Input: examId, classKey (compute all sections of this class), OR
     *        examId + allClasses=1 (compute every class/section)
     * Returns: {message, processed[]}
     */
    public function bulk_compute()
    {
        $this->_require_role(self::ADMIN_ROLES, 'bulk compute results');
        set_time_limit(120); // Allow up to 2 minutes for large schools
        header('Content-Type: application/json');

        $school     = $this->school_name;
        $year       = $this->session_year;
        $examId     = $this->safe_path_segment(trim((string) $this->input->post('examId')), 'examId');
        $classKey   = $this->safe_path_segment(trim((string) $this->input->post('classKey')), 'classKey');
        $allClasses = (bool) $this->input->post('allClasses');

        if (!$examId) {
            $this->json_error('Exam ID is required.', 400);
        }
        if (!$allClasses && !$classKey) {
            $this->json_error('Class or allClasses flag is required.', 400);
        }

        $exam = $this->firebase->get("Schools/{$school}/{$year}/Exams/{$examId}");
        if (!$exam || !is_array($exam)) {
            $this->json_error('Exam not found.', 404);
        }

        $scale      = $exam['GradingScale']   ?? 'Percentage';
        $passingPct = (int) ($exam['PassingPercent'] ?? 33);

        $structure = $this->exam_engine->get_class_structure();
        $processed = [];
        $errors    = [];

        // Determine which classes to process
        $classesToProcess = [];
        if ($allClasses) {
            $classesToProcess = $structure;
        } else {
            if (!isset($structure[$classKey])) {
                $this->json_error('Class not found.', 404);
            }
            $classesToProcess = [$classKey => $structure[$classKey]];
        }

        foreach ($classesToProcess as $cls => $sectionLetters) {
            foreach ($sectionLetters as $letter) {
                $secKey = "Section {$letter}";
                $result = $this->_compute_section_results($examId, $cls, $secKey, $scale, $passingPct);

                if ($result['success']) {
                    $processed[] = [
                        'class'   => $cls,
                        'section' => $secKey,
                        'count'   => $result['count'],
                    ];
                } else {
                    $errors[] = [
                        'class'   => $cls,
                        'section' => $secKey,
                        'reason'  => $result['reason'],
                    ];
                }
            }
        }

        $totalProcessed = array_sum(array_column($processed, 'count'));

        // Fix H3: Notify parents/students via Communication module
        if ($totalProcessed > 0) {
            try {
                $this->load->library('Communication_helper', null, 'comm');
                $this->comm->init($this->firebase, $school, $year);
                foreach ($processed as $p) {
                    $this->comm->fire_event('exam_result', [
                        'exam_id'        => $examId,
                        'exam_name'      => $exam['Name'] ?? $examId,
                        'class'          => $p['class'],
                        'section'        => $p['section'],
                        'students_count' => $p['count'],
                    ]);
                }
            } catch (\Exception $e) {
                log_message('error', "Communication fire_event failed after bulk_compute: " . $e->getMessage());
            }
        }

        $this->json_success([
            'message'   => "Bulk compute complete. {$totalProcessed} student(s) across " . count($processed) . " section(s).",
            'processed' => $processed,
            'errors'    => $errors,
            'csrf_token' => $this->security->get_csrf_hash(),
        ]);
    }

    /**
     * POST AJAX — Export merit list as structured printable data.
     *
     * Input: examId, classKey, sectionKey (optional), topN
     * Returns: {printData} — ready for client-side print rendering
     */
    public function export_merit_list()
    {
        $this->_require_role(self::ADMIN_ROLES, 'export merit list');
        header('Content-Type: application/json');

        $school     = $this->school_name;
        $year       = $this->session_year;
        $examId     = $this->safe_path_segment(trim((string) $this->input->post('examId')), 'examId');
        $classKey   = $this->safe_path_segment(trim((string) $this->input->post('classKey')), 'classKey');
        $sectionKey = $this->safe_path_segment(trim((string) $this->input->post('sectionKey')), 'sectionKey');
        $topN       = max(1, min(100, (int) ($this->input->post('topN') ?: 10)));

        if (!$examId || !$classKey) {
            $this->json_error('Exam and class are required.', 400);
        }

        $exam = $this->firebase->get("Schools/{$school}/{$year}/Exams/{$examId}");
        if (!$exam || !is_array($exam)) {
            $this->json_error('Exam not found.', 404);
        }

        // Load school profile for header
        $profile = $this->firebase->get("System/Schools/{$school}/profile") ?? [];

        $structure = $this->exam_engine->get_class_structure();
        $sections  = $structure[$classKey] ?? [];
        if (empty($sections)) {
            $this->json_error('No sections found.', 404);
        }

        if ($sectionKey) {
            $letter = str_replace('Section ', '', $sectionKey);
            if (!in_array($letter, $sections, true)) {
                $this->json_error('Section not found.', 404);
            }
            $sections = [$letter];
        }

        // Collect all results
        $allResults = [];
        foreach ($sections as $letter) {
            $secKey   = "Section {$letter}";
            $computed = $this->firebase->get(
                "Schools/{$school}/{$year}/Results/Computed/{$examId}/{$classKey}/{$secKey}"
            );
            if (!is_array($computed) || empty($computed)) continue;

            $roster = $this->exam_engine->get_student_names($classKey, $secKey);

            foreach ($computed as $uid => $res) {
                if (!is_array($res)) continue;

                // Check fee eligibility for exam
                $feeEligible = true;
                $feeBlockReason = '';
                try {
                    $eligibility = $this->feeDefaulter->checkExamEligibility($uid, $exam['Name'] ?? $examId);
                    if (!$eligibility['eligible']) {
                        $feeEligible = false;
                        $feeBlockReason = $eligibility['reason'] ?? 'Outstanding fees';
                    }
                } catch (Exception $e) {
                    log_message('error', "Fee defaulter check failed in Examination: " . $e->getMessage());
                    // Don't block on check failure — allow access
                }

                $allResults[] = [
                    'userId'     => $uid,
                    'name'       => $roster[$uid] ?? $uid,
                    'section'    => $letter,
                    'totalMarks' => (int) ($res['TotalMarks'] ?? 0),
                    'maxMarks'   => (int) ($res['MaxMarks'] ?? 0),
                    'percentage' => (float) ($res['Percentage'] ?? 0),
                    'grade'      => $res['Grade'] ?? '',
                    'passFail'   => $res['PassFail'] ?? '',
                    'fee_eligible' => $feeEligible,
                    'fee_block_reason' => $feeBlockReason,
                ];
            }
        }

        if (empty($allResults)) {
            $this->json_error('No results available for export.', 404);
        }

        usort($allResults, function($a, $b) { return $b['percentage'] <=> $a['percentage']; });
        $ranked = $this->exam_engine->assign_ranks($allResults);
        $ranked = array_slice($ranked, 0, $topN);

        $this->json_success([
            'printData' => [
                'schoolName'  => $school,
                'schoolLogo'  => $profile['logo'] ?? '',
                'address'     => $profile['address'] ?? '',
                'examName'    => $exam['Name'] ?? $examId,
                'examType'    => $exam['Type'] ?? '',
                'className'   => $classKey,
                'sectionKey'  => $sectionKey ?: 'All Sections',
                'sessionYear' => $year,
                'generatedAt' => date('d-m-Y H:i:s'),
                'toppers'     => $ranked,
                'totalStudents' => count($allResults),
            ],
            'csrf_token' => $this->security->get_csrf_hash(),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Compute results for a single section of an exam.
     * Same grading engine as Result.php::compute_results().
     *
     * @return array {success: bool, count: int, reason: string}
     */
    private function _compute_section_results(
        string $examId,
        string $classKey,
        string $sectionKey,
        string $scale,
        int    $passingPct
    ): array {
        $school = $this->school_name;
        $year   = $this->session_year;

        // Load templates
        $templatesNode = $this->firebase->get(
            "Schools/{$school}/{$year}/Results/Templates/{$examId}/{$classKey}/{$sectionKey}"
        ) ?? [];
        if (!is_array($templatesNode) || empty($templatesNode)) {
            return ['success' => false, 'count' => 0, 'reason' => 'No templates found'];
        }

        // Load marks
        $allMarksNode = $this->firebase->get(
            "Schools/{$school}/{$year}/Results/Marks/{$examId}/{$classKey}/{$sectionKey}"
        ) ?? [];
        if (!is_array($allMarksNode)) $allMarksNode = [];

        // Collect unique student IDs across all subjects
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
            return ['success' => false, 'count' => 0, 'reason' => 'No marks entered'];
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
                $subjMax   = (int) ($tmpl['TotalMaxMarks'] ?? 0);
                $stuMarks  = $allMarksNode[$subj][$uid] ?? [];
                $absent    = !empty($stuMarks['Absent']);
                $subjTotal = $absent ? 0 : (int) ($stuMarks['Total'] ?? 0);
                $subjPct   = $subjMax > 0 ? ($subjTotal / $subjMax * 100) : 0;
                $subjGrade = $absent ? 'AB' : $this->exam_engine->compute_grade($subjPct, $scale);
                $subjPass  = $absent ? 'Fail' : $this->exam_engine->compute_pass_fail($subjPct, $passingPct);

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

        // Sort by Percentage desc → assign competition ranks (1,1,3)
        uasort($studentResults, function($a, $b) { return $b['Percentage'] <=> $a['Percentage']; });
        $this->exam_engine->assign_ranks_assoc($studentResults, 'Percentage');

        // Write to Computed node — single batch update instead of per-student writes
        $basePath = "Schools/{$school}/{$year}/Results/Computed/{$examId}/{$classKey}/{$sectionKey}";
        $this->firebase->update($basePath, $studentResults);

        // Fix H4: Clear stale flag after fresh computation
        $this->firebase->delete("{$basePath}", '_stale');

        // ── Sync results to Firestore 'results' collection ──
        try {
            $examData = $this->firebase->get("Schools/{$school}/{$year}/Exams/{$examId}");
            $examName = is_array($examData) ? ($examData['Name'] ?? $examId) : $examId;
            $sectionKeyFs = "{$classKey}/{$sectionKey}";

            foreach ($studentResults as $uid => $result) {
                $docId = "{$school}_{$examId}_{$sectionKeyFs}_{$uid}";

                // Map subjects for Firestore ResultDoc format
                $fsSubjects = [];
                foreach ($result['Subjects'] as $subj => $subjData) {
                    $fsSubjects[$subj] = [
                        'total'    => $subjData['Total'] ?? 0,
                        'maxMarks' => $subjData['MaxMarks'] ?? 0,
                        'grade'    => $subjData['Grade'] ?? '',
                        'absent'   => $subjData['Absent'] ?? false,
                    ];
                }

                $this->fs->set(Firestore_helper::RESULTS, $docId, [
                    'schoolId'    => $school,
                    'session'     => $year,
                    'examId'      => $examId,
                    'examName'    => $examName,
                    'studentId'   => $uid,
                    'className'   => $classKey,
                    'section'     => $sectionKey,
                    'sectionKey'  => $sectionKeyFs,
                    'subjects'    => $fsSubjects,
                    'totalMarks'  => $result['TotalMarks'] ?? 0,
                    'maxMarks'    => $result['MaxMarks'] ?? 0,
                    'percentage'  => $result['Percentage'] ?? 0,
                    'grade'       => $result['Grade'] ?? '',
                    'rank'        => $result['Rank'] ?? 0,
                    'passFail'    => $result['PassFail'] ?? '',
                    'computedAt'  => date('c'),
                ]);
            }
        } catch (\Exception $e) {
            log_message('error', "_compute_section_results: Firestore sync failed [{$examId}/{$classKey}/{$sectionKey}]: " . $e->getMessage());
        }

        return ['success' => true, 'count' => count($studentResults), 'reason' => ''];
    }
}
