<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Homework Tracking Controller
 *
 * Admin portal module for monitoring, managing, and analysing homework
 * assigned by teachers through the mobile app.
 *
 * Firebase structure (written by Teacher mobile app):
 *   Schools/{schoolId}/{session}/{className}/{sectionName}/Homework/{hwId}
 *
 * All AJAX endpoints return JSON via json_success() / json_error().
 */
class Homework extends MY_Controller
{
    /** Roles that may manage (create / edit / delete) homework */
    private const MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator'];

    /** Roles that may view homework data */
    private const VIEW_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator', 'Class Teacher', 'Teacher'];

    public function __construct()
    {
        parent::__construct();
        require_permission('Homework');
    }

    /* ================================================================
       PAGE ROUTES
       ================================================================ */

    /**
     * Main homework dashboard — single-page view with tabs.
     */
    public function index()
    {
        $this->_require_role(self::VIEW_ROLES, 'homework_view');

        $data = [
            'page_title' => 'Homework Tracking',
        ];

        $this->load->view('include/header', $data);
        $this->load->view('homework/index', $data);
        $this->load->view('include/footer');
    }

    /* ================================================================
       AJAX — DASHBOARD
       ================================================================ */

    /**
     * Dashboard overview stats: total, active, overdue, submission rate.
     */
    public function get_overview()
    {
        $this->_require_role(self::VIEW_ROLES, 'homework_overview');

        $all = $this->_fetch_all_homework();
        $today = date('Y-m-d');

        $total   = 0;
        $active  = 0;
        $overdue = 0;
        $closed  = 0;
        $totalSubmissionRate = 0;
        $ratedCount = 0;

        foreach ($all as $hw) {
            $total++;
            $status  = $hw['status'] ?? 'Active';
            $dueDate = $hw['dueDate'] ?? '';

            if (strcasecmp($status, 'Active') === 0) {
                $active++;
                if ($dueDate && $dueDate < $today) {
                    $overdue++;
                }
            } elseif (strcasecmp($status, 'Closed') === 0) {
                $closed++;
            }

            // Submission rate
            $rate = $this->_calc_submission_rate($hw);
            if ($rate !== null) {
                $totalSubmissionRate += $rate;
                $ratedCount++;
            }
        }

        $avgRate = $ratedCount > 0 ? round($totalSubmissionRate / $ratedCount, 1) : 0;

        // Due today / this week
        $dueToday = 0;
        $dueWeek  = 0;
        $weekEnd  = date('Y-m-d', strtotime('+7 days'));

        foreach ($all as $hw) {
            $status  = $hw['status'] ?? 'Active';
            if (strcasecmp($status, 'Active') !== 0) continue;
            $dd = $hw['dueDate'] ?? '';
            if ($dd === $today) $dueToday++;
            if ($dd >= $today && $dd <= $weekEnd) $dueWeek++;
        }

        $this->json_success([
            'total'       => $total,
            'active'      => $active,
            'overdue'     => $overdue,
            'closed'      => $closed,
            'avg_rate'    => $avgRate,
            'due_today'   => $dueToday,
            'due_week'    => $dueWeek,
        ]);
    }

    /**
     * Full homework list with optional filters.
     *
     * POST params: class, section, subject, teacher, status, date_from, date_to
     */
    public function get_homework_list()
    {
        $this->_require_role(self::VIEW_ROLES, 'homework_list');

        $filterClass   = trim($this->input->post('class') ?? '');
        $filterSection = trim($this->input->post('section') ?? '');
        $filterSubject = trim($this->input->post('subject') ?? '');
        $filterTeacher = trim($this->input->post('teacher') ?? '');
        $filterStatus  = trim($this->input->post('status') ?? '');
        $dateFrom      = trim($this->input->post('date_from') ?? '');
        $dateTo        = trim($this->input->post('date_to') ?? '');

        $all  = $this->_fetch_all_homework();
        $list = [];
        $today = date('Y-m-d');

        foreach ($all as $hw) {
            // Apply filters
            if ($filterClass && $hw['_class'] !== $filterClass) continue;
            if ($filterSection && $hw['_section'] !== $filterSection) continue;
            if ($filterSubject && strcasecmp($hw['subject'] ?? '', $filterSubject) !== 0) continue;
            if ($filterTeacher && ($hw['teacherId'] ?? '') !== $filterTeacher) continue;

            $status = $hw['status'] ?? 'Active';
            if ($filterStatus) {
                if (strcasecmp($filterStatus, 'Overdue') === 0) {
                    $dd = $hw['dueDate'] ?? '';
                    if (!(strcasecmp($status, 'Active') === 0 && $dd && $dd < $today)) continue;
                } else {
                    if (strcasecmp($status, $filterStatus) !== 0) continue;
                }
            }

            $dd = $hw['dueDate'] ?? '';
            if ($dateFrom && $dd && $dd < $dateFrom) continue;
            if ($dateTo && $dd && $dd > $dateTo) continue;

            $rate = $this->_calc_submission_rate($hw);
            $isOverdue = (strcasecmp($status, 'Active') === 0 && $dd && $dd < $today);

            $list[] = [
                'id'          => $hw['_id'],
                'title'       => $hw['title'] ?? 'Untitled',
                'subject'     => $hw['subject'] ?? '-',
                'class'       => $hw['_class'],
                'section'     => $hw['_section'],
                'teacherId'   => $hw['teacherId'] ?? '',
                'teacherName' => $hw['teacherName'] ?? '-',
                'dueDate'     => $dd,
                'status'      => $isOverdue ? 'Overdue' : ucfirst(strtolower($status)),
                'rate'        => $rate ?? 0,
                'createdAt'   => $hw['createdAt'] ?? 0,
                'description' => $hw['description'] ?? '',
            ];
        }

        // Sort by createdAt descending (handles both timestamp and ISO string)
        usort($list, function ($a, $b) {
            $ta = $a['createdAt'] ?? '';
            $tb = $b['createdAt'] ?? '';
            // Convert ISO strings to timestamps for comparison
            if (is_string($ta) && !is_numeric($ta)) $ta = strtotime($ta) ?: 0;
            if (is_string($tb) && !is_numeric($tb)) $tb = strtotime($tb) ?: 0;
            return (int)$tb - (int)$ta;
        });

        $this->json_success(['homework' => $list]);
    }

    /**
     * Single homework with full details + submission breakdown.
     *
     * POST: class, section, hw_id
     */
    public function get_homework_detail()
    {
        $this->_require_role(self::VIEW_ROLES, 'homework_detail');

        $hwId = $this->safe_path_segment($this->input->post('hw_id') ?? '', 'hw_id');

        // Read from Firestore
        $hw = $this->firebase->firestoreGet('homework', $hwId);
        if (!is_array($hw)) {
            $this->json_error('Homework not found.', 404);
        }

        // Read submissions from Firestore
        $subDocs = $this->firebase->firestoreQuery(
            'submissions',
            [['homeworkId', '=', $hwId]],
            null, 'ASC', 500
        );

        $submissionList = [];
        $submitted = 0;
        $pending   = 0;

        foreach ($subDocs as $sub) {
            $d = $sub['data'];
            $subStatus = $d['status'] ?? 'pending';
            if (in_array(strtolower($subStatus), ['submitted', 'reviewed', 'complete', 'done'])) {
                $submitted++;
            } else {
                $pending++;
            }
            $submissionList[] = [
                'studentId'   => $d['studentId'] ?? '',
                'studentName' => $d['studentName'] ?? '',
                'status'      => $subStatus,
                'text'        => $d['text'] ?? '',
                'remarks'     => $d['remark'] ?? '',
                'submittedAt' => $d['submittedAt'] ?? '',
                'score'       => $d['score'] ?? -1,
                'reviewedBy'  => $d['reviewedBy'] ?? '',
            ];
        }

        $today = date('Y-m-d');
        $status  = $hw['status'] ?? 'active';
        $dueDate = $hw['dueDate'] ?? '';
        $isOverdue = (strtolower($status) === 'active' && $dueDate && $dueDate < $today);
        $totalStudents = intval($hw['totalStudents'] ?? ($submitted + $pending));

        $this->json_success([
            'homework' => [
                'id'          => $hwId,
                'title'       => $hw['title'] ?? 'Untitled',
                'description' => $hw['description'] ?? '',
                'subject'     => $hw['subject'] ?? '-',
                'class'       => $hw['className'] ?? '',
                'section'     => $hw['section'] ?? '',
                'teacherId'   => $hw['teacherId'] ?? '',
                'teacherName' => $hw['teacherName'] ?? '-',
                'dueDate'     => $dueDate,
                'status'      => $isOverdue ? 'Overdue' : ucfirst(strtolower($status)),
                'createdAt'   => $hw['createdAt'] ?? '',
                'submitted'   => $submitted,
                'pending'     => $pending,
                'total'       => max($totalStudents, $submitted + $pending),
            ],
            'submissions' => $submissionList,
        ]);
    }

    /**
     * Detailed submission list for a homework.
     *
     * Merges submissions from Firestore with the full class roster so students
     * who haven't submitted still appear as "pending".
     *
     * POST: class, section, hw_id
     */
    public function get_submissions()
    {
        $this->_require_role(self::VIEW_ROLES, 'homework_submissions');

        $hwId = $this->safe_path_segment($this->input->post('hw_id') ?? '', 'hw_id');

        // Read homework from Firestore
        $hw = $this->firebase->firestoreGet('homework', $hwId);
        if (!is_array($hw)) {
            $this->json_error('Homework not found.', 404);
        }

        // Read submissions from Firestore
        $subDocs = $this->firebase->firestoreQuery(
            'submissions',
            [['homeworkId', '=', $hwId]],
            null, 'ASC', 500
        );

        // Index submissions by studentId
        $subMap = [];
        foreach ($subDocs as $sub) {
            $d = $sub['data'];
            $sid = $d['studentId'] ?? '';
            $subMap[$sid] = [
                'studentId'   => $sid,
                'studentName' => $d['studentName'] ?? '',
                'rollNo'      => '-',
                'status'      => $d['status'] ?? 'pending',
                'text'        => $d['text'] ?? '',
                'remarks'     => $d['remark'] ?? '',
                'submittedAt' => $d['submittedAt'] ?? '',
                'score'       => $d['score'] ?? -1,
                'reviewedBy'  => $d['reviewedBy'] ?? '',
            ];
        }

        // Fetch full class roster from Firestore students collection
        $cls = $hw['className'] ?? '';
        $sec = $hw['section'] ?? '';
        $result = [];

        if ($cls && $sec) {
            $sectionKey = "{$cls}/{$sec}";
            try {
                $studentDocs = $this->firebase->firestoreQuery(
                    'students',
                    [
                        ['schoolId', '=', $this->school_name],
                        ['sectionKey', '=', $sectionKey],
                    ],
                    null, 'ASC', 500
                );
                foreach ($studentDocs as $doc) {
                    $sd = $doc['data'];
                    // Student doc ID is "{schoolId}_{studentId}" but submission
                    // stores just the raw studentId (e.g. "STU0001"). Match by
                    // the studentId field inside the doc, not the doc ID.
                    $sid = $sd['studentId'] ?? $sd['userId'] ?? $doc['id'];
                    if (isset($subMap[$sid])) {
                        // Student has a submission doc — use it
                        $entry = $subMap[$sid];
                        $entry['studentName'] = $sd['name'] ?? $sd['Name'] ?? $entry['studentName'];
                        $entry['rollNo'] = $sd['rollNo'] ?? $sd['RollNo'] ?? '-';
                        $result[] = $entry;
                        unset($subMap[$sid]);
                    } else {
                        // No submission — show as pending
                        $result[] = [
                            'studentId'   => $sid,
                            'studentName' => $sd['name'] ?? $sd['Name'] ?? $sid,
                            'rollNo'      => $sd['rollNo'] ?? $sd['RollNo'] ?? '-',
                            'status'      => 'pending',
                            'text'        => '',
                            'remarks'     => '',
                            'submittedAt' => '',
                            'score'       => -1,
                            'reviewedBy'  => '',
                        ];
                    }
                }
            } catch (\Exception $e) {
                // Firestore students query failed — fall through to submission-only list
            }
        }

        // Append any remaining submissions not matched to roster (edge case)
        foreach ($subMap as $entry) {
            $result[] = $entry;
        }

        // If no roster found, fall back to submission docs only
        if (empty($result)) {
            foreach ($subDocs as $sub) {
                $d = $sub['data'];
                $result[] = [
                    'studentId'   => $d['studentId'] ?? '',
                    'studentName' => $d['studentName'] ?? '',
                    'rollNo'      => '-',
                    'status'      => $d['status'] ?? 'pending',
                    'text'        => $d['text'] ?? '',
                    'remarks'     => $d['remark'] ?? '',
                    'submittedAt' => $d['submittedAt'] ?? '',
                    'score'       => $d['score'] ?? -1,
                    'reviewedBy'  => $d['reviewedBy'] ?? '',
                ];
            }
        }

        $totalStudents = max(intval($hw['totalStudents'] ?? 0), count($result));
        $submittedCount = 0;
        foreach ($result as $r) {
            if (in_array(strtolower($r['status']), ['submitted', 'reviewed', 'complete', 'done'])) {
                $submittedCount++;
            }
        }

        $this->json_success([
            'submissions'    => $result,
            'total_students' => $totalStudents,
            'submitted'      => $submittedCount,
            'hw_title'       => $hw['title'] ?? 'Untitled',
        ]);
    }

    /* ================================================================
       AJAX — ANALYTICS
       ================================================================ */

    /**
     * Per-class homework stats: total assigned, avg submission rate.
     */
    public function get_class_summary()
    {
        $this->_require_role(self::VIEW_ROLES, 'homework_class_summary');

        $all    = $this->_fetch_all_homework();
        $groups = [];

        foreach ($all as $hw) {
            $key = $hw['_class'] . ' / ' . $hw['_section'];
            if (!isset($groups[$key])) {
                $groups[$key] = ['total' => 0, 'rateSum' => 0, 'rateCount' => 0, 'class' => $hw['_class'], 'section' => $hw['_section']];
            }
            $groups[$key]['total']++;
            $rate = $this->_calc_submission_rate($hw);
            if ($rate !== null) {
                $groups[$key]['rateSum'] += $rate;
                $groups[$key]['rateCount']++;
            }
        }

        $result = [];
        foreach ($groups as $key => $g) {
            $result[] = [
                'label'    => $key,
                'class'    => $g['class'],
                'section'  => $g['section'],
                'total'    => $g['total'],
                'avg_rate' => $g['rateCount'] > 0 ? round($g['rateSum'] / $g['rateCount'], 1) : 0,
            ];
        }

        usort($result, function ($a, $b) { return strcmp($a['label'], $b['label']); });

        $this->json_success(['classes' => $result]);
    }

    /**
     * Per-teacher homework stats.
     */
    public function get_teacher_activity()
    {
        $this->_require_role(self::VIEW_ROLES, 'homework_teacher_activity');

        $all    = $this->_fetch_all_homework();
        $groups = [];

        foreach ($all as $hw) {
            $tid = $hw['teacherId'] ?? 'unknown';
            if (!isset($groups[$tid])) {
                $groups[$tid] = [
                    'teacherId'   => $tid,
                    'teacherName' => $hw['teacherName'] ?? $tid,
                    'total'       => 0,
                    'rateSum'     => 0,
                    'rateCount'   => 0,
                    'weeks'       => [],
                ];
            }
            $groups[$tid]['total']++;
            $rate = $this->_calc_submission_rate($hw);
            if ($rate !== null) {
                $groups[$tid]['rateSum'] += $rate;
                $groups[$tid]['rateCount']++;
            }
            // Track week of creation for regularity
            $ts = $this->_normalizeTimestamp($hw['createdAt'] ?? 0);
            if ($ts > 0) {
                $week = date('Y-W', $ts);
                $groups[$tid]['weeks'][$week] = true;
            }
        }

        $result = [];
        foreach ($groups as $g) {
            $result[] = [
                'teacherId'   => $g['teacherId'],
                'teacherName' => $g['teacherName'],
                'total'       => $g['total'],
                'avg_rate'    => $g['rateCount'] > 0 ? round($g['rateSum'] / $g['rateCount'], 1) : 0,
                'active_weeks' => count($g['weeks']),
            ];
        }

        usort($result, function ($a, $b) { return $b['total'] - $a['total']; });

        $this->json_success(['teachers' => $result]);
    }

    /**
     * Homework stats by subject.
     */
    public function get_subject_breakdown()
    {
        $this->_require_role(self::VIEW_ROLES, 'homework_subject_breakdown');

        $all    = $this->_fetch_all_homework();
        $groups = [];

        foreach ($all as $hw) {
            $subj = $hw['subject'] ?? 'Unknown';
            if (!isset($groups[$subj])) {
                $groups[$subj] = ['total' => 0, 'rateSum' => 0, 'rateCount' => 0];
            }
            $groups[$subj]['total']++;
            $rate = $this->_calc_submission_rate($hw);
            if ($rate !== null) {
                $groups[$subj]['rateSum'] += $rate;
                $groups[$subj]['rateCount']++;
            }
        }

        $result = [];
        foreach ($groups as $subj => $g) {
            $result[] = [
                'subject'  => $subj,
                'total'    => $g['total'],
                'avg_rate' => $g['rateCount'] > 0 ? round($g['rateSum'] / $g['rateCount'], 1) : 0,
            ];
        }

        usort($result, function ($a, $b) { return $b['total'] - $a['total']; });

        $this->json_success(['subjects' => $result]);
    }

    /**
     * All overdue homework that hasn't been closed.
     */
    public function get_overdue_report()
    {
        $this->_require_role(self::VIEW_ROLES, 'homework_overdue_report');

        $all   = $this->_fetch_all_homework();
        $today = date('Y-m-d');
        $list  = [];

        foreach ($all as $hw) {
            $status  = $hw['status'] ?? 'Active';
            $dueDate = $hw['dueDate'] ?? '';

            if (strcasecmp($status, 'Active') !== 0) continue;
            if (!$dueDate || $dueDate >= $today) continue;

            $daysPast = (int) ((strtotime($today) - strtotime($dueDate)) / 86400);
            $rate = $this->_calc_submission_rate($hw);

            $list[] = [
                'id'          => $hw['_id'],
                'title'       => $hw['title'] ?? 'Untitled',
                'subject'     => $hw['subject'] ?? '-',
                'class'       => $hw['_class'],
                'section'     => $hw['_section'],
                'teacherName' => $hw['teacherName'] ?? '-',
                'dueDate'     => $dueDate,
                'days_past'   => $daysPast,
                'rate'        => $rate ?? 0,
            ];
        }

        usort($list, function ($a, $b) { return $b['days_past'] - $a['days_past']; });

        $this->json_success(['overdue' => $list]);
    }

    /**
     * Homework creation and submission trends over time (weekly).
     */
    public function get_trend_data()
    {
        $this->_require_role(self::VIEW_ROLES, 'homework_trends');

        $all = $this->_fetch_all_homework();

        // Group by week
        $weeklyCreated = [];
        $weeklyRates   = [];

        foreach ($all as $hw) {
            $ts = $this->_normalizeTimestamp($hw['createdAt'] ?? 0);
            if ($ts <= 0) continue;

            $weekKey = date('Y-W', $ts);
            if (!isset($weeklyCreated[$weekKey])) {
                $weeklyCreated[$weekKey] = 0;
                $weeklyRates[$weekKey]   = ['sum' => 0, 'count' => 0];
            }
            $weeklyCreated[$weekKey]++;

            $rate = $this->_calc_submission_rate($hw);
            if ($rate !== null) {
                $weeklyRates[$weekKey]['sum'] += $rate;
                $weeklyRates[$weekKey]['count']++;
            }
        }

        ksort($weeklyCreated);

        $labels    = [];
        $created   = [];
        $rates     = [];

        foreach ($weeklyCreated as $week => $count) {
            // Convert week key to readable date (Monday of that week)
            $parts = explode('-', $week);
            if (count($parts) === 2) {
                $dt = new DateTime();
                $dt->setISODate((int)$parts[0], (int)$parts[1]);
                $labels[] = $dt->format('d M');
            } else {
                $labels[] = $week;
            }
            $created[] = $count;
            $rInfo     = $weeklyRates[$week] ?? ['sum' => 0, 'count' => 0];
            $rates[]   = $rInfo['count'] > 0 ? round($rInfo['sum'] / $rInfo['count'], 1) : 0;
        }

        $this->json_success([
            'labels'  => $labels,
            'created' => $created,
            'rates'   => $rates,
        ]);
    }

    /**
     * Get students for a class/section (for submission tracking tab).
     * Firestore-first, RTDB fallback.
     */
    public function get_students_for_class()
    {
        $this->_require_role(self::VIEW_ROLES, 'homework_students');

        $class   = $this->safe_path_segment($this->input->post('class') ?? '', 'class');
        $section = $this->safe_path_segment($this->input->post('section') ?? '', 'section');

        $students = [];

        // Firestore first
        $sectionKey = "{$class}/{$section}";
        try {
            $docs = $this->firebase->firestoreQuery(
                'students',
                [
                    ['schoolId', '=', $this->school_name],
                    ['sectionKey', '=', $sectionKey],
                ],
                null, 'ASC', 500
            );
            foreach ($docs as $doc) {
                $d = $doc['data'];
                $students[] = [
                    'id'   => $doc['id'],
                    'name' => $d['name'] ?? $d['Name'] ?? $doc['id'],
                    'roll' => $d['rollNo'] ?? $d['RollNo'] ?? $doc['id'],
                ];
            }
        } catch (\Exception $e) {
            // Firestore failed — RTDB fallback
        }

        // RTDB fallback
        if (empty($students)) {
            $path = "Schools/{$this->school_name}/{$this->session_year}/{$class}/{$section}/Students";
            $data = $this->firebase->get($path);
            if (is_array($data)) {
                $list = $data['List'] ?? $data;
                if (is_array($list)) {
                    foreach ($list as $sid => $info) {
                        if (is_string($info)) {
                            $students[] = ['id' => $sid, 'name' => $info, 'roll' => $sid];
                        } elseif (is_array($info)) {
                            $students[] = [
                                'id'   => $sid,
                                'name' => $info['Name'] ?? $info['name'] ?? $sid,
                                'roll' => $info['RollNo'] ?? $info['Roll'] ?? $info['roll'] ?? $sid,
                            ];
                        }
                    }
                }
            }
        }

        $this->json_success(['students' => $students]);
    }

    /**
     * Get all class/section combos for the school (for create form + filters).
     * Reads from Firestore sections collection.
     */
    public function get_class_sections()
    {
        $this->_require_role(self::VIEW_ROLES, 'homework_class_sections');

        $result = [];

        // Firestore first — sections collection
        try {
            $docs = $this->firebase->firestoreQuery(
                'sections',
                [['schoolId', '=', $this->school_name]],
                null, 'ASC', 500
            );
            foreach ($docs as $doc) {
                $d = $doc['data'];
                $cls = $d['className'] ?? '';
                $sec = $d['section'] ?? '';
                if ($cls && $sec) {
                    $result[] = ['class' => $cls, 'section' => $sec];
                }
            }
        } catch (\Exception $e) {
            // fallback below
        }

        // RTDB fallback — shallow_get session root
        if (empty($result)) {
            $root = "Schools/{$this->school_name}/{$this->session_year}";
            $keys = $this->firebase->shallow_get($root);
            if (is_array($keys)) {
                foreach ($keys as $key => $val) {
                    if (strpos($key, 'Class ') === 0) {
                        $classNode = $this->firebase->shallow_get("{$root}/{$key}");
                        if (is_array($classNode)) {
                            foreach ($classNode as $sk => $sv) {
                                if (strpos($sk, 'Section ') === 0) {
                                    $result[] = ['class' => $key, 'section' => $sk];
                                }
                            }
                        }
                    }
                }
            }
        }

        $this->json_success(['class_sections' => $result]);
    }

    /* ================================================================
       AJAX — CRUD
       ================================================================ */

    /**
     * Create homework (admin-created).
     *
     * POST: class, section (or sections[] for multi), subject, title, description, due_date
     */
    public function create_homework()
    {
        $this->_require_role(self::MANAGE_ROLES, 'homework_create');

        $title       = trim($this->input->post('title') ?? '');
        $description = trim($this->input->post('description') ?? '');
        $subject     = trim($this->input->post('subject') ?? '');
        $dueDate     = trim($this->input->post('due_date') ?? '');
        $class       = $this->safe_path_segment($this->input->post('class') ?? '', 'class');

        // Support multi-section assignment
        $sections = $this->input->post('sections');
        if (!is_array($sections) || empty($sections)) {
            $singleSection = trim($this->input->post('section') ?? '');
            $sections = $singleSection ? [$singleSection] : [];
        }

        // Validate
        if ($title === '')   $this->json_error('Title is required.', 400);
        if ($subject === '') $this->json_error('Subject is required.', 400);
        if ($dueDate === '') $this->json_error('Due date is required.', 400);
        if (empty($sections)) $this->json_error('At least one section is required.', 400);

        $createdIds = [];

        foreach ($sections as $sec) {
            $sec = $this->safe_path_segment(trim($sec), 'section');

            // Ensure class/section prefixes
            $cls = (stripos($class, 'Class ') === 0) ? $class : "Class {$class}";
            $secFull = (stripos($sec, 'Section ') === 0) ? $sec : "Section {$sec}";
            $sectionKey = "{$cls}/{$secFull}";

            $hwId = "{$this->school_name}_" . round(microtime(true) * 1000);

            $hwData = [
                'schoolId'        => $this->school_name,
                'session'         => $this->session_year,
                'className'       => $cls,
                'section'         => $secFull,
                'sectionKey'      => $sectionKey,
                'title'           => $title,
                'description'     => $description,
                'subject'         => $subject,
                'teacherId'       => $this->admin_id,
                'teacherName'     => $this->admin_name ?? 'Admin',
                'dueDate'         => $dueDate,
                'createdAt'       => round(microtime(true) * 1000),
                'status'          => 'active',
                'submissionCount' => 0,
                'totalStudents'   => 0,
                'attachments'     => [],
            ];

            // Write directly to Firestore
            $ok = $this->firebase->firestoreSet('homework', $hwId, $hwData);

            if ($ok) {
                $createdIds[] = ['class' => $cls, 'section' => $secFull, 'id' => $hwId];
            }
        }

        if (empty($createdIds)) {
            $this->json_error('Failed to create homework. Please try again.', 500);
        }

        log_audit('Homework', 'homework_create', $class, "Created homework: {$title} for " . count($sections) . " section(s)");

        $this->json_success(['created' => $createdIds, 'message' => 'Homework created successfully.']);
    }

    /**
     * Update homework details.
     *
     * POST: class, section, hw_id, title, description, subject, due_date, status
     */
    public function update_homework()
    {
        $this->_require_role(self::MANAGE_ROLES, 'homework_update');

        $hwId = $this->safe_path_segment($this->input->post('hw_id') ?? '', 'hw_id');

        // Read from Firestore
        $existing = $this->firebase->firestoreGet('homework', $hwId);
        if (!is_array($existing)) {
            $this->json_error('Homework not found.', 404);
        }

        $updates = [];

        $title = trim($this->input->post('title') ?? '');
        if ($title !== '') $updates['title'] = $title;

        $desc = trim($this->input->post('description') ?? '');
        if ($desc !== '') $updates['description'] = $desc;

        $subj = trim($this->input->post('subject') ?? '');
        if ($subj !== '') $updates['subject'] = $subj;

        $dd = trim($this->input->post('due_date') ?? '');
        if ($dd !== '') $updates['dueDate'] = $dd;

        $st = trim($this->input->post('status') ?? '');
        if ($st !== '' && in_array(strtolower($st), ['active', 'closed', 'archived'], true)) {
            $updates['status'] = strtolower($st);
        }

        if (empty($updates)) {
            $this->json_error('No changes provided.', 400);
        }

        // Write to Firestore directly
        $this->firebase->firestoreUpdate('homework', $hwId, $updates);

        log_audit('Homework', 'homework_update', $hwId, "Updated homework: " . implode(', ', array_keys($updates)));

        $this->json_success(['message' => 'Homework updated successfully.']);
    }

    /**
     * Delete homework.
     *
     * POST: class, section, hw_id
     */
    public function delete_homework()
    {
        $this->_require_role(self::MANAGE_ROLES, 'homework_delete');

        $hwId = $this->safe_path_segment($this->input->post('hw_id') ?? '', 'hw_id');

        // Read from Firestore
        $existing = $this->firebase->firestoreGet('homework', $hwId);
        if (!is_array($existing)) {
            $this->json_error('Homework not found.', 404);
        }

        // Delete from Firestore
        $this->firebase->firestoreDelete('homework', $hwId);

        // Also delete all submissions for this homework
        try {
            $submissions = $this->firebase->firestoreQuery(
                'submissions',
                [['homeworkId', '=', $hwId]],
                null, 'ASC', 500
            );
            foreach ($submissions as $sub) {
                $this->firebase->firestoreDelete('submissions', $sub['id']);
            }
        } catch (\Exception $e) {
            // Non-critical
        }

        log_audit('Homework', 'homework_delete', $hwId, "Deleted homework: " . ($existing['title'] ?? ''));

        $this->json_success(['message' => 'Homework deleted successfully.']);
    }

    /**
     * Close homework — mark as Closed.
     *
     * POST: class, section, hw_id
     */
    public function close_homework()
    {
        $this->_require_role(self::MANAGE_ROLES, 'homework_close');

        $hwId = $this->safe_path_segment($this->input->post('hw_id') ?? '', 'hw_id');

        $existing = $this->firebase->firestoreGet('homework', $hwId);
        if (!is_array($existing)) {
            $this->json_error('Homework not found.', 404);
        }

        // Update in Firestore directly
        $this->firebase->firestoreUpdate('homework', $hwId, ['status' => 'closed']);

        log_audit('Homework', 'homework_close', $hwId, "Closed homework");

        $this->json_success(['message' => 'Homework closed successfully.']);
    }

    /* ================================================================
       PRIVATE HELPERS
       ================================================================ */

    /**
     * Fetch all homework across all classes/sections for the current session.
     *
     * Returns a flat array of homework items, each enriched with:
     *   _class, _section, _id
     *
     * @return array
     */
    /**
     * Normalize createdAt to Unix seconds.
     * Handles: epoch ms (number), ISO string, Firestore timestamp map.
     */
    private function _normalizeTimestamp($ts): int
    {
        if (is_numeric($ts)) {
            $n = (int) $ts;
            // If > 1e12 it's milliseconds
            return $n > 1000000000000 ? intval($n / 1000) : $n;
        }
        if (is_string($ts) && $ts !== '') {
            $parsed = strtotime($ts);
            return $parsed ?: 0;
        }
        if (is_array($ts)) {
            // Firestore {_seconds:…} or {seconds:…}
            return intval($ts['_seconds'] ?? $ts['seconds'] ?? 0);
        }
        return 0;
    }

    private function _fetch_all_homework(): array
    {
        $school = $this->school_name;

        // Read ALL homework from Firestore 'homework' collection for this school
        $docs = $this->firebase->firestoreQuery(
            'homework',
            [['schoolId', '=', $school]],
            'createdAt', 'DESC', 500
        );

        $result = [];
        foreach ($docs as $doc) {
            $hw = $doc['data'];
            $hw['_class']   = $hw['className'] ?? '';
            $hw['_section'] = $hw['section'] ?? '';
            $hw['_id']      = $doc['id'];
            $hw['_source']  = 'firestore';
            $result[] = $hw;
        }

        return $result;
    }

    /**
     * Calculate submission rate for a homework item.
     *
     * Uses submissionCount / totalStudents from the homework doc.
     * Falls back to querying submissions collection if counts are missing.
     *
     * @param array $hw Homework data
     * @return float|null Percentage (0-100) or null if no data
     */
    private function _calc_submission_rate(array $hw): ?float
    {
        $totalStudents = intval($hw['totalStudents'] ?? 0);
        $submissionCount = intval($hw['submissionCount'] ?? 0);

        // If totalStudents is available and > 0, use the stored counts
        if ($totalStudents > 0) {
            return round(($submissionCount / $totalStudents) * 100, 1);
        }

        // Fallback: query submissions collection for this homework
        $hwId = $hw['_id'] ?? '';
        if (!$hwId) return null;

        // Use cached submissions if available
        if (!isset($this->_submissionCache)) {
            $this->_submissionCache = [];
        }
        if (isset($this->_submissionCache[$hwId])) {
            return $this->_submissionCache[$hwId];
        }

        try {
            $subDocs = $this->firebase->firestoreQuery(
                'submissions',
                [['homeworkId', '=', $hwId]],
                null, 'ASC', 500
            );

            $total = count($subDocs);
            if ($total === 0) {
                $this->_submissionCache[$hwId] = null;
                return null;
            }

            $submitted = 0;
            foreach ($subDocs as $sub) {
                $d = $sub['data'];
                $status = strtolower($d['status'] ?? '');
                if (in_array($status, ['submitted', 'complete', 'done', 'checked', 'reviewed'], true)) {
                    $submitted++;
                }
            }

            $rate = round(($submitted / $total) * 100, 1);
            $this->_submissionCache[$hwId] = $rate;
            return $rate;
        } catch (\Exception $e) {
            return null;
        }
    }
}
