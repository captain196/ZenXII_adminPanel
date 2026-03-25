<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Red Flags Dashboard Controller
 *
 * Comprehensive red-flag monitoring for student issues raised by teachers
 * via the mobile app. Provides KPIs, analytics, flag management, and
 * student drill-down views.
 *
 * Firebase paths:
 *   Schools/{school}/{session}/{classKey}/{sectionKey}/RedFlags/{studentId}/{flagId}
 *   Schools/{school}/{session}/{classKey}/{sectionKey}/Students/{studentId}
 */
class Red_flags extends MY_Controller
{
    /** Roles that may manage (resolve/create/delete) flags */
    private const MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal'];

    /** Roles that may view flags */
    private const VIEW_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator', 'Teacher'];

    /** Valid flag types */
    private const ALLOWED_TYPES = ['Homework', 'Behavior', 'Performance'];

    /** Valid severity levels */
    private const ALLOWED_SEVERITIES = ['Low', 'Medium', 'High'];

    /** Valid statuses */
    private const ALLOWED_STATUSES = ['Active', 'Resolved'];

    /** Max text length for flag messages */
    private const MAX_MESSAGE_LENGTH = 1000;

    public function __construct()
    {
        parent::__construct();
        require_permission('Red Flags');
    }

    // =========================================================================
    //  PRIVATE HELPERS
    // =========================================================================

    /**
     * Build base Firebase path for a class/section within current session.
     */
    private function _class_path(string $classKey, string $sectionKey): string
    {
        return "Schools/{$this->school_name}/{$this->session_year}/{$classKey}/{$sectionKey}";
    }

    /**
     * Deny access — JSON for AJAX, redirect for pages.
     */
    private function _deny_access(): void
    {
        if ($this->input->is_ajax_request()) {
            $this->json_error('Access denied.', 403);
        }
        redirect(base_url('admin'));
    }

    /**
     * Strip control characters from text for safe Firebase storage.
     */
    private function _clean_text(string $text): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', trim($text));
    }

    /**
     * Generate a unique flag ID.
     */
    private function _generate_flag_id(): string
    {
        return 'RF_' . date('Ymd_His') . '_' . substr(uniqid('', true), -6);
    }

    /**
     * Collect ALL red flags across every class/section in current session.
     * Returns flat array of flag records enriched with class/section/student info.
     *
     * @param  array|null $classFilter  Optional — restrict to specific classes
     * @return array
     */
    private function _collect_all_flags(?array $classFilter = null): array
    {
        $classes = $this->_get_session_classes();
        $allFlags = [];

        foreach ($classes as $cls) {
            $classKey   = $cls['class_key'];
            $sectionKey = 'Section ' . $cls['section'];
            $label      = $cls['label'];

            // Apply class filter if provided
            if ($classFilter !== null) {
                $filterMatch = false;
                foreach ($classFilter as $f) {
                    if (isset($f['class_key'], $f['section'])) {
                        if ($f['class_key'] === $classKey && $f['section'] === $cls['section']) {
                            $filterMatch = true;
                            break;
                        }
                    }
                }
                if (!$filterMatch) continue;
            }

            // Teacher role: only see assigned classes
            if (!$this->_teacher_can_access($classKey, $sectionKey)) {
                continue;
            }

            $basePath = $this->_class_path($classKey, $sectionKey);
            $redFlags = $this->firebase->get("{$basePath}/RedFlags");

            if (!is_array($redFlags)) continue;

            // Load student names for this section (cached per section)
            $students = $this->firebase->get("{$basePath}/Students");
            $studentNames = [];
            if (is_array($students)) {
                foreach ($students as $sid => $sdata) {
                    if (is_array($sdata)) {
                        $studentNames[$sid] = [
                            'name'       => $sdata['Name'] ?? $sid,
                            'rollNo'     => $sdata['RollNo'] ?? '',
                            'fatherName' => $sdata['FatherName'] ?? '',
                        ];
                    }
                }
            }

            foreach ($redFlags as $studentId => $flags) {
                if (!is_array($flags)) continue;

                $stuInfo = $studentNames[$studentId] ?? [
                    'name'       => $studentId,
                    'rollNo'     => '',
                    'fatherName' => '',
                ];

                foreach ($flags as $flagId => $flag) {
                    if (!is_array($flag)) continue;

                    $allFlags[] = [
                        'flagId'      => $flagId,
                        'studentId'   => $studentId,
                        'studentName' => $stuInfo['name'],
                        'rollNo'      => $stuInfo['rollNo'],
                        'fatherName'  => $stuInfo['fatherName'],
                        'classKey'    => $classKey,
                        'sectionKey'  => $sectionKey,
                        'classLabel'  => $label,
                        'type'        => $flag['type'] ?? 'Unknown',
                        'severity'    => $flag['severity'] ?? 'Low',
                        'message'     => $flag['message'] ?? '',
                        'subject'     => $flag['subject'] ?? '',
                        'teacherId'   => $flag['teacherId'] ?? '',
                        'teacherName' => $flag['teacherName'] ?? '',
                        'createdAt'   => $flag['createdAt'] ?? 0,
                        'status'      => $flag['status'] ?? 'Active',
                        'resolvedAt'  => $flag['resolvedAt'] ?? null,
                        'resolvedBy'  => $flag['resolvedBy'] ?? null,
                    ];
                }
            }
        }

        // Sort by createdAt descending
        usort($allFlags, function ($a, $b) {
            return ($b['createdAt'] ?? 0) <=> ($a['createdAt'] ?? 0);
        });

        return $allFlags;
    }

    // =========================================================================
    //  PAGE ROUTES
    // =========================================================================

    /**
     * Main Red Flags Dashboard (SPA — all tabs in one view).
     */
    public function index()
    {
        $this->_require_role(self::VIEW_ROLES, 'red_flags_view');
        $data = [];
        $this->load->view('include/header', $data);
        $this->load->view('red_flags/index', $data);
        $this->load->view('include/footer');
    }

    // =========================================================================
    //  AJAX ENDPOINTS
    // =========================================================================

    /**
     * GET — Return classes for filter dropdowns.
     */
    public function get_classes()
    {
        $this->_require_role(self::VIEW_ROLES, 'red_flags_classes');

        $classes = $this->_get_session_classes();

        // For Teacher role, filter to assigned classes only
        if (($this->admin_role ?? '') === 'Teacher') {
            $filtered = [];
            foreach ($classes as $cls) {
                $sectionKey = 'Section ' . $cls['section'];
                if ($this->_teacher_can_access($cls['class_key'], $sectionKey)) {
                    $filtered[] = $cls;
                }
            }
            $classes = $filtered;
        }

        $this->json_success(['classes' => $classes]);
    }

    /**
     * GET — Dashboard KPIs: total, active, resolved, by type, by severity, by class.
     */
    public function get_overview()
    {
        $this->_require_role(self::VIEW_ROLES, 'red_flags_overview');

        $allFlags = $this->_collect_all_flags();

        $total    = count($allFlags);
        $active   = 0;
        $resolved = 0;
        $high     = 0;
        $thisWeek = 0;

        $byType     = ['Homework' => 0, 'Behavior' => 0, 'Performance' => 0];
        $bySeverity = ['Low' => 0, 'Medium' => 0, 'High' => 0];
        $byClass    = [];
        $byStatus   = ['Active' => 0, 'Resolved' => 0];

        $weekAgo = strtotime('-7 days');
        $recentFlags = [];
        $studentCounts = [];

        foreach ($allFlags as $f) {
            // Status counts
            if (($f['status'] ?? 'Active') === 'Active') {
                $active++;
                $byStatus['Active']++;
            } else {
                $resolved++;
                $byStatus['Resolved']++;
            }

            // Severity
            $sev = $f['severity'] ?? 'Low';
            if (isset($bySeverity[$sev])) $bySeverity[$sev]++;
            if ($sev === 'High') $high++;

            // Type
            $type = $f['type'] ?? 'Unknown';
            if (isset($byType[$type])) $byType[$type]++;

            // Class breakdown
            $cl = $f['classLabel'] ?? 'Unknown';
            if (!isset($byClass[$cl])) $byClass[$cl] = ['total' => 0, 'active' => 0, 'high' => 0];
            $byClass[$cl]['total']++;
            if (($f['status'] ?? 'Active') === 'Active') $byClass[$cl]['active']++;
            if ($sev === 'High') $byClass[$cl]['high']++;

            // This week
            $ts = is_numeric($f['createdAt']) ? (int) $f['createdAt'] : 0;
            // Handle millisecond timestamps
            if ($ts > 9999999999) $ts = (int) ($ts / 1000);
            if ($ts >= $weekAgo) $thisWeek++;

            // Recent flags (top 10)
            if (count($recentFlags) < 10) {
                $recentFlags[] = $f;
            }

            // Student flag counts
            $sid = $f['studentId'];
            if (!isset($studentCounts[$sid])) {
                $studentCounts[$sid] = [
                    'studentId'   => $sid,
                    'studentName' => $f['studentName'],
                    'classLabel'  => $f['classLabel'],
                    'rollNo'      => $f['rollNo'],
                    'count'       => 0,
                    'lastFlag'    => $f['createdAt'],
                    'highCount'   => 0,
                ];
            }
            $studentCounts[$sid]['count']++;
            if ($sev === 'High') $studentCounts[$sid]['highCount']++;
        }

        // Top flagged students (top 10 by count)
        usort($studentCounts, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });
        $topStudents = array_slice(array_values($studentCounts), 0, 10);

        $this->json_success([
            'total'       => $total,
            'active'      => $active,
            'resolved'    => $resolved,
            'high'        => $high,
            'thisWeek'    => $thisWeek,
            'byType'      => $byType,
            'bySeverity'  => $bySeverity,
            'byClass'     => $byClass,
            'byStatus'    => $byStatus,
            'recentFlags' => $recentFlags,
            'topStudents' => $topStudents,
        ]);
    }

    /**
     * POST — Fetch flags with filters.
     *
     * Filters: class_key, section, type, severity, status, student, teacher, date_from, date_to
     */
    public function get_flags()
    {
        $this->_require_role(self::VIEW_ROLES, 'red_flags_list');

        $allFlags = $this->_collect_all_flags();

        // Apply filters from POST
        $fClassKey  = trim($this->input->post('class_key') ?? '');
        $fSection   = trim($this->input->post('section') ?? '');
        $fType      = trim($this->input->post('type') ?? '');
        $fSeverity  = trim($this->input->post('severity') ?? '');
        $fStatus    = trim($this->input->post('status') ?? '');
        $fStudent   = trim($this->input->post('student') ?? '');
        $fTeacher   = trim($this->input->post('teacher') ?? '');
        $fDateFrom  = trim($this->input->post('date_from') ?? '');
        $fDateTo    = trim($this->input->post('date_to') ?? '');

        $filtered = [];

        foreach ($allFlags as $f) {
            // Class filter
            if ($fClassKey !== '' && $f['classKey'] !== $fClassKey) continue;
            if ($fSection !== '' && $f['sectionKey'] !== 'Section ' . $fSection) continue;

            // Type
            if ($fType !== '' && $f['type'] !== $fType) continue;

            // Severity
            if ($fSeverity !== '' && $f['severity'] !== $fSeverity) continue;

            // Status
            if ($fStatus !== '' && $f['status'] !== $fStatus) continue;

            // Student name search (case-insensitive partial)
            if ($fStudent !== '' && stripos($f['studentName'], $fStudent) === false
                && stripos($f['studentId'], $fStudent) === false) {
                continue;
            }

            // Teacher search
            if ($fTeacher !== '' && stripos($f['teacherName'], $fTeacher) === false
                && stripos($f['teacherId'], $fTeacher) === false) {
                continue;
            }

            // Date range
            $ts = is_numeric($f['createdAt']) ? (int) $f['createdAt'] : 0;
            if ($ts > 9999999999) $ts = (int) ($ts / 1000);

            if ($fDateFrom !== '') {
                $from = strtotime($fDateFrom . ' 00:00:00');
                if ($from !== false && $ts < $from) continue;
            }
            if ($fDateTo !== '') {
                $to = strtotime($fDateTo . ' 23:59:59');
                if ($to !== false && $ts > $to) continue;
            }

            $filtered[] = $f;
        }

        $this->json_success([
            'flags'   => $filtered,
            'total'   => count($filtered),
        ]);
    }

    /**
     * GET — All flags for a specific student.
     */
    public function get_student_flags(string $studentId = '')
    {
        $this->_require_role(self::VIEW_ROLES, 'red_flags_student');

        if ($studentId === '') {
            $this->json_error('Student ID is required.', 400);
        }

        $studentId = $this->safe_path_segment($studentId, 'studentId');
        $allFlags = $this->_collect_all_flags();

        $studentFlags = [];
        $studentInfo  = null;

        foreach ($allFlags as $f) {
            if ($f['studentId'] === $studentId) {
                if ($studentInfo === null) {
                    $studentInfo = [
                        'studentId'   => $f['studentId'],
                        'studentName' => $f['studentName'],
                        'rollNo'      => $f['rollNo'],
                        'fatherName'  => $f['fatherName'],
                        'classLabel'  => $f['classLabel'],
                    ];
                }
                $studentFlags[] = $f;
            }
        }

        // Pattern analysis
        $typeBreakdown     = ['Homework' => 0, 'Behavior' => 0, 'Performance' => 0];
        $severityBreakdown = ['Low' => 0, 'Medium' => 0, 'High' => 0];
        $activeCount       = 0;
        $resolvedCount     = 0;

        foreach ($studentFlags as $sf) {
            $t = $sf['type'] ?? 'Unknown';
            if (isset($typeBreakdown[$t])) $typeBreakdown[$t]++;

            $s = $sf['severity'] ?? 'Low';
            if (isset($severityBreakdown[$s])) $severityBreakdown[$s]++;

            if (($sf['status'] ?? 'Active') === 'Active') {
                $activeCount++;
            } else {
                $resolvedCount++;
            }
        }

        $this->json_success([
            'student'   => $studentInfo,
            'flags'     => $studentFlags,
            'total'     => count($studentFlags),
            'analysis'  => [
                'byType'     => $typeBreakdown,
                'bySeverity' => $severityBreakdown,
                'active'     => $activeCount,
                'resolved'   => $resolvedCount,
            ],
        ]);
    }

    /**
     * POST — Resolve a single flag.
     */
    public function resolve_flag()
    {
        $this->_require_role(self::MANAGE_ROLES, 'red_flags_resolve');

        $classKey   = $this->safe_path_segment($this->input->post('class_key') ?? '', 'class_key');
        $sectionKey = $this->safe_path_segment($this->input->post('section_key') ?? '', 'section_key');
        $studentId  = $this->safe_path_segment($this->input->post('student_id') ?? '', 'student_id');
        $flagId     = $this->safe_path_segment($this->input->post('flag_id') ?? '', 'flag_id');

        $path = $this->_class_path($classKey, $sectionKey) . "/RedFlags/{$studentId}/{$flagId}";

        // Verify flag exists
        $existing = $this->firebase->get($path);
        if (!is_array($existing)) {
            $this->json_error('Flag not found.', 404);
        }

        if (($existing['status'] ?? '') === 'Resolved') {
            $this->json_error('Flag is already resolved.', 400);
        }

        $now = time() * 1000; // millisecond timestamp to match mobile app

        $this->firebase->update($path, [
            'status'     => 'Resolved',
            'resolvedAt' => $now,
            'resolvedBy' => $this->admin_id,
        ]);

        log_audit('Red Flags', 'resolve_flag', $flagId, "Resolved flag for student {$studentId}");

        $this->json_success(['message' => 'Flag resolved successfully.']);
    }

    /**
     * POST — Admin creates a new flag.
     */
    public function create_flag()
    {
        $this->_require_role(self::MANAGE_ROLES, 'red_flags_create');

        $classKey   = $this->safe_path_segment($this->input->post('class_key') ?? '', 'class_key');
        $sectionKey = $this->safe_path_segment($this->input->post('section_key') ?? '', 'section_key');
        $studentId  = $this->safe_path_segment($this->input->post('student_id') ?? '', 'student_id');

        $type     = trim($this->input->post('type') ?? '');
        $severity = trim($this->input->post('severity') ?? '');
        $message  = $this->_clean_text($this->input->post('message') ?? '');
        $subject  = $this->_clean_text($this->input->post('subject') ?? '');

        // Validate
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            $this->json_error('Invalid flag type. Must be: ' . implode(', ', self::ALLOWED_TYPES));
        }
        if (!in_array($severity, self::ALLOWED_SEVERITIES, true)) {
            $this->json_error('Invalid severity. Must be: ' . implode(', ', self::ALLOWED_SEVERITIES));
        }
        if ($message === '') {
            $this->json_error('Message is required.');
        }
        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            $this->json_error('Message exceeds maximum length of ' . self::MAX_MESSAGE_LENGTH . ' characters.');
        }

        // Verify student exists
        $studentPath = $this->_class_path($classKey, $sectionKey) . "/Students/{$studentId}";
        $studentData = $this->firebase->get($studentPath);
        if (!is_array($studentData)) {
            $this->json_error('Student not found.', 404);
        }

        $flagId = $this->_generate_flag_id();
        $now    = time() * 1000;

        $flagData = [
            'type'        => $type,
            'severity'    => $severity,
            'message'     => $message,
            'subject'     => $subject,
            'teacherId'   => $this->admin_id,
            'teacherName' => $this->admin_name ?? $this->admin_id,
            'createdAt'   => $now,
            'status'      => 'Active',
        ];

        $flagPath = $this->_class_path($classKey, $sectionKey) . "/RedFlags/{$studentId}/{$flagId}";
        $this->firebase->set($flagPath, $flagData);

        log_audit('Red Flags', 'create_flag', $flagId, "Created {$severity} {$type} flag for student {$studentId}");

        $this->json_success([
            'message' => 'Flag created successfully.',
            'flagId'  => $flagId,
        ]);
    }

    /**
     * POST — Delete a flag.
     */
    public function delete_flag()
    {
        $this->_require_role(self::MANAGE_ROLES, 'red_flags_delete');

        $classKey   = $this->safe_path_segment($this->input->post('class_key') ?? '', 'class_key');
        $sectionKey = $this->safe_path_segment($this->input->post('section_key') ?? '', 'section_key');
        $studentId  = $this->safe_path_segment($this->input->post('student_id') ?? '', 'student_id');
        $flagId     = $this->safe_path_segment($this->input->post('flag_id') ?? '', 'flag_id');

        $path = $this->_class_path($classKey, $sectionKey) . "/RedFlags/{$studentId}/{$flagId}";

        // Verify exists
        $existing = $this->firebase->get($path);
        if (!is_array($existing)) {
            $this->json_error('Flag not found.', 404);
        }

        $this->firebase->delete($path);

        log_audit('Red Flags', 'delete_flag', $flagId, "Deleted flag for student {$studentId}");

        $this->json_success(['message' => 'Flag deleted successfully.']);
    }

    /**
     * POST — Bulk resolve multiple flags.
     *
     * Expects POST body: flags[] = array of { class_key, section_key, student_id, flag_id }
     */
    public function bulk_resolve()
    {
        $this->_require_role(self::MANAGE_ROLES, 'red_flags_bulk_resolve');

        $flags = $this->input->post('flags');
        if (!is_array($flags) || empty($flags)) {
            $this->json_error('No flags provided for resolution.');
        }

        if (count($flags) > 100) {
            $this->json_error('Maximum 100 flags can be resolved at once.');
        }

        $now      = time() * 1000;
        $resolved = 0;
        $errors   = [];

        foreach ($flags as $i => $f) {
            if (!is_array($f)) continue;

            $ck  = trim($f['class_key'] ?? '');
            $sk  = trim($f['section_key'] ?? '');
            $sid = trim($f['student_id'] ?? '');
            $fid = trim($f['flag_id'] ?? '');

            if ($ck === '' || $sk === '' || $sid === '' || $fid === '') {
                $errors[] = "Item {$i}: missing required fields.";
                continue;
            }

            // Validate path safety
            if (!preg_match("/^[A-Za-z0-9 ',_\-]+$/u", $ck)
                || !preg_match("/^[A-Za-z0-9 ',_\-]+$/u", $sk)
                || !preg_match("/^[A-Za-z0-9 ',_\-]+$/u", $sid)
                || !preg_match("/^[A-Za-z0-9 ',_\-]+$/u", $fid)) {
                $errors[] = "Item {$i}: invalid characters.";
                continue;
            }

            $path = $this->_class_path($ck, $sk) . "/RedFlags/{$sid}/{$fid}";
            $existing = $this->firebase->get($path);

            if (!is_array($existing)) {
                $errors[] = "Item {$i}: flag not found.";
                continue;
            }

            if (($existing['status'] ?? '') === 'Resolved') {
                continue; // Already resolved, skip silently
            }

            $this->firebase->update($path, [
                'status'     => 'Resolved',
                'resolvedAt' => $now,
                'resolvedBy' => $this->admin_id,
            ]);
            $resolved++;
        }

        log_audit('Red Flags', 'bulk_resolve', '', "Bulk resolved {$resolved} flags");

        $this->json_success([
            'message'  => "{$resolved} flag(s) resolved successfully.",
            'resolved' => $resolved,
            'errors'   => $errors,
        ]);
    }

    /**
     * GET — Analytics: trends over time, by class, by type, teacher activity.
     */
    public function get_trends()
    {
        $this->_require_role(self::VIEW_ROLES, 'red_flags_trends');

        $allFlags = $this->_collect_all_flags();

        // ── Weekly trend (last 12 weeks) ──
        $weeklyTrend  = [];
        $now          = time();
        for ($w = 11; $w >= 0; $w--) {
            $weekStart = strtotime("-{$w} weeks", strtotime('monday this week', $now));
            $weekEnd   = $weekStart + (7 * 86400) - 1;
            $label     = date('M d', $weekStart);
            $weeklyTrend[$label] = ['created' => 0, 'resolved' => 0];
        }

        // ── Month comparison ──
        $thisMonthStart = strtotime(date('Y-m-01'));
        $lastMonthStart = strtotime(date('Y-m-01', strtotime('-1 month')));
        $lastMonthEnd   = $thisMonthStart - 1;
        $thisMonth = 0;
        $lastMonth = 0;

        // ── Teacher activity ──
        $teacherActivity = [];

        // ── Subject breakdown ──
        $subjectBreakdown = [];

        // ── Resolution time analysis ──
        $resolutionTimes = [];

        foreach ($allFlags as $f) {
            $ts = is_numeric($f['createdAt']) ? (int) $f['createdAt'] : 0;
            if ($ts > 9999999999) $ts = (int) ($ts / 1000);

            // Weekly trend
            foreach ($weeklyTrend as $label => &$wk) {
                // Reconstruct week start from label
                $wStart = strtotime($label . ' ' . date('Y'));
                if ($wStart === false) continue;
                $wEnd = $wStart + (7 * 86400) - 1;
                if ($ts >= $wStart && $ts <= $wEnd) {
                    $wk['created']++;
                    if (($f['status'] ?? 'Active') === 'Resolved') {
                        $wk['resolved']++;
                    }
                    break;
                }
            }
            unset($wk);

            // Month comparison
            if ($ts >= $thisMonthStart) {
                $thisMonth++;
            } elseif ($ts >= $lastMonthStart && $ts <= $lastMonthEnd) {
                $lastMonth++;
            }

            // Teacher activity
            $tid = $f['teacherName'] ?: ($f['teacherId'] ?? 'Unknown');
            if (!isset($teacherActivity[$tid])) {
                $teacherActivity[$tid] = ['name' => $tid, 'teacherId' => $f['teacherId'] ?? '', 'count' => 0, 'high' => 0];
            }
            $teacherActivity[$tid]['count']++;
            if (($f['severity'] ?? '') === 'High') $teacherActivity[$tid]['high']++;

            // Subject breakdown
            $subj = $f['subject'] ?: 'General';
            if (!isset($subjectBreakdown[$subj])) $subjectBreakdown[$subj] = 0;
            $subjectBreakdown[$subj]++;

            // Resolution time
            if (($f['status'] ?? '') === 'Resolved' && !empty($f['resolvedAt'])) {
                $rts = is_numeric($f['resolvedAt']) ? (int) $f['resolvedAt'] : 0;
                if ($rts > 9999999999) $rts = (int) ($rts / 1000);
                if ($rts > $ts && $ts > 0) {
                    $diffHours = ($rts - $ts) / 3600;
                    $resolutionTimes[] = round($diffHours, 1);
                }
            }
        }

        // Sort teacher activity by count
        usort($teacherActivity, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });
        $teacherActivity = array_slice($teacherActivity, 0, 15);

        // Sort subject breakdown
        arsort($subjectBreakdown);

        // Avg resolution time
        $avgResolution = !empty($resolutionTimes)
            ? round(array_sum($resolutionTimes) / count($resolutionTimes), 1)
            : 0;

        $this->json_success([
            'weeklyTrend'      => $weeklyTrend,
            'thisMonth'        => $thisMonth,
            'lastMonth'        => $lastMonth,
            'monthChange'      => $lastMonth > 0
                ? round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1)
                : ($thisMonth > 0 ? 100 : 0),
            'teacherActivity'  => array_values($teacherActivity),
            'subjectBreakdown' => $subjectBreakdown,
            'avgResolutionHrs' => $avgResolution,
            'resolutionTimes'  => $resolutionTimes,
        ]);
    }

    // =========================================================================
    //  ADDITIONAL AJAX ENDPOINTS
    // =========================================================================

    /**
     * GET — Single flag detail with full student info.
     *
     * Finds the flag across all classes (since the caller may not know the
     * class/section). Falls back to scanning _collect_all_flags.
     */
    public function get_flag_detail(string $flagId = '')
    {
        $this->_require_role(self::VIEW_ROLES, 'red_flags_detail');

        if ($flagId === '') {
            $this->json_error('Flag ID is required.', 400);
        }

        $flagId = $this->safe_path_segment($flagId, 'flagId');
        $allFlags = $this->_collect_all_flags();

        $found = null;
        foreach ($allFlags as $f) {
            if ($f['flagId'] === $flagId) {
                $found = $f;
                break;
            }
        }

        if ($found === null) {
            $this->json_error('Flag not found.', 404);
        }

        $this->json_success(['flag' => $found]);
    }

    /**
     * POST — Get students for a specific class/section.
     *
     * Used by the Create Flag form to populate the student dropdown
     * independently of existing flags.
     */
    public function get_students_for_class()
    {
        $this->_require_role(self::VIEW_ROLES, 'red_flags_students');

        $classKey   = $this->safe_path_segment($this->input->post('class_key') ?? '', 'class_key');
        $sectionKey = $this->safe_path_segment($this->input->post('section_key') ?? '', 'section_key');

        // Verify teacher access
        if (!$this->_teacher_can_access($classKey, $sectionKey)) {
            $this->json_error('Access denied for this class.', 403);
        }

        $basePath = $this->_class_path($classKey, $sectionKey);
        $students = $this->firebase->get("{$basePath}/Students");

        $list = [];
        if (is_array($students)) {
            foreach ($students as $sid => $sdata) {
                if (!is_array($sdata)) continue;
                $list[] = [
                    'studentId'  => $sid,
                    'name'       => $sdata['Name'] ?? $sid,
                    'rollNo'     => $sdata['RollNo'] ?? '',
                    'fatherName' => $sdata['FatherName'] ?? '',
                ];
            }
            // Sort by name
            usort($list, function ($a, $b) {
                return strcasecmp($a['name'], $b['name']);
            });
        }

        $this->json_success(['students' => $list]);
    }

    /**
     * GET — Per-class flag summary: total, active, high, per class-section.
     */
    public function get_class_summary()
    {
        $this->_require_role(self::VIEW_ROLES, 'red_flags_class_summary');

        $allFlags = $this->_collect_all_flags();
        $summary  = [];

        foreach ($allFlags as $f) {
            $label = $f['classLabel'] ?? 'Unknown';
            if (!isset($summary[$label])) {
                $summary[$label] = [
                    'classLabel' => $label,
                    'classKey'   => $f['classKey'] ?? '',
                    'sectionKey' => $f['sectionKey'] ?? '',
                    'total'      => 0,
                    'active'     => 0,
                    'resolved'   => 0,
                    'high'       => 0,
                    'medium'     => 0,
                    'low'        => 0,
                    'students'   => [],
                ];
            }

            $summary[$label]['total']++;

            if (($f['status'] ?? 'Active') === 'Active') {
                $summary[$label]['active']++;
            } else {
                $summary[$label]['resolved']++;
            }

            $sev = strtolower($f['severity'] ?? 'low');
            if (isset($summary[$label][$sev])) {
                $summary[$label][$sev]++;
            }

            // Track unique students
            $sid = $f['studentId'] ?? '';
            if ($sid !== '' && !in_array($sid, $summary[$label]['students'], true)) {
                $summary[$label]['students'][] = $sid;
            }
        }

        // Convert students array to count
        foreach ($summary as &$s) {
            $s['uniqueStudents'] = count($s['students']);
            unset($s['students']);
        }
        unset($s);

        // Sort by total descending
        usort($summary, function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });

        $this->json_success(['classSummary' => array_values($summary)]);
    }

    /**
     * GET — Teacher activity: flags created per teacher with breakdowns.
     */
    public function get_teacher_activity()
    {
        $this->_require_role(self::VIEW_ROLES, 'red_flags_teacher_activity');

        $allFlags = $this->_collect_all_flags();
        $teachers = [];

        foreach ($allFlags as $f) {
            $tid  = $f['teacherId'] ?? 'Unknown';
            $name = $f['teacherName'] ?: $tid;

            if (!isset($teachers[$tid])) {
                $teachers[$tid] = [
                    'teacherId'   => $tid,
                    'teacherName' => $name,
                    'total'       => 0,
                    'high'        => 0,
                    'medium'      => 0,
                    'low'         => 0,
                    'homework'    => 0,
                    'behavior'    => 0,
                    'performance' => 0,
                    'active'      => 0,
                    'resolved'    => 0,
                    'lastFlagAt'  => 0,
                ];
            }

            $teachers[$tid]['total']++;

            $sev = strtolower($f['severity'] ?? 'low');
            if (isset($teachers[$tid][$sev])) {
                $teachers[$tid][$sev]++;
            }

            $type = strtolower($f['type'] ?? '');
            if (isset($teachers[$tid][$type])) {
                $teachers[$tid][$type]++;
            }

            if (($f['status'] ?? 'Active') === 'Active') {
                $teachers[$tid]['active']++;
            } else {
                $teachers[$tid]['resolved']++;
            }

            $ts = is_numeric($f['createdAt']) ? (int) $f['createdAt'] : 0;
            if ($ts > $teachers[$tid]['lastFlagAt']) {
                $teachers[$tid]['lastFlagAt'] = $ts;
            }
        }

        // Sort by total descending
        usort($teachers, function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });

        $this->json_success(['teachers' => array_values($teachers)]);
    }

    /**
     * Alias — get_trend_data maps to get_trends.
     */
    public function get_trend_data()
    {
        $this->get_trends();
    }

    /**
     * Alias — add_flag maps to create_flag.
     */
    public function add_flag()
    {
        $this->create_flag();
    }
}
