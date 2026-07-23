<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Homework Tracking Controller
 *
 * Admin portal module for monitoring, managing, and analysing homework
 * assigned by teachers through the mobile app.
 *
 * Firestore structure (shared with Teacher + Parent apps):
 *   homework/{hwId}        — class-level assignments. Doc ID format
 *                            "{schoolId}_{epochMs}". Filtered by
 *                            schoolId + sectionKey ("Class 8th/Section A").
 *   submissions/{subId}    — per-student submission. Doc ID format
 *                            "{hwId}_{studentId}".
 *   teacherMarks/{markId}  — teacher score for non-submitters. Doc ID
 *                            format "{hwId}_{studentId}".
 *
 * All AJAX endpoints return JSON via json_success() / json_error().
 */
class Homework extends MY_Controller
{
    /** Roles that may manage (create / edit / delete) homework */
    private const MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator'];

    /** Roles that may view homework data */
    private const VIEW_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator', 'Class Teacher', 'Teacher'];

    /* Firestore read/pagination caps — kept below Firestore's hard limits and
       reused across the chunked-cursor scans (_fetch_all_homework,
       _calc_submission_rate) and the cascade delete. */
    /** Per-page chunk size for cursor-paginated scans (sub-500 headroom). */
    private const FETCH_CHUNK = 499;
    /** Safety cap on cursor-pagination iterations (FETCH_CHUNK × this ≈ 125k). */
    private const FETCH_MAX_ITERS = 250;
    /** Default single-shot query cap for roster / submission / section reads. */
    private const QUERY_LIMIT = 500;
    /** Larger single-shot cap for roster + subject-assignment reads. */
    private const MAX_QUERY_LIMIT = 1000;

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
        $this->_require_role(self::VIEW_ROLES, 'homework_view', 'Homework', 'view');

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
        $this->_require_role(self::VIEW_ROLES, 'homework_overview', 'Homework', 'view');

        $all = $this->_fetch_all_homework();
        $today = $this->_school_today();  // Finding #9 2026-05-14 — school-timezone (IST), not server UTC; parity with M1A.7

        $total    = 0;
        $active   = 0;
        $overdue  = 0;
        $closed   = 0;
        $archived = 0;
        $totalSubmissionRate = 0;
        $ratedCount = 0;

        foreach ($all as $hw) {
            $total++;
            $status  = $hw['status'] ?? 'Active';
            $dueDate = $this->_dueDatePrefix($hw['dueDate'] ?? '');

            if (strcasecmp($status, 'Active') === 0) {
                $active++;
                if ($dueDate && $dueDate < $today) {
                    $overdue++;
                }
            } elseif (strcasecmp($status, 'Closed') === 0) {
                $closed++;
            } elseif (strcasecmp($status, 'Archived') === 0) {
                // BUG-2 — Archived was previously uncounted, so the status
                // donut (Active/Overdue/Closed) never summed to `total` when
                // archived homework existed. Counting it lets the view emit an
                // Archived slice; with mutually-exclusive slices
                // (Active-not-overdue + Overdue + Closed + Archived) the donut
                // sums to `total`.
                $archived++;
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
        // BUG-001 — anchor week-end to the IST-derived $today (Finding #9
        // school-timezone parity) instead of the server-TZ "now". Without
        // this, after ~18:30 IST when server (UTC) date trails IST by one,
        // the comparison `$dd <= $weekEnd` excluded items legitimately due
        // on the 7th IST day. Date-string arithmetic preserves the IST
        // anchor: strtotime on a YYYY-MM-DD string yields a server-TZ
        // midnight; +7 days advances the date label; date() formats it
        // back as YYYY-MM-DD — no TZ shift in the round-trip.
        $weekEnd  = date('Y-m-d', strtotime('+7 days', strtotime($today)));

        foreach ($all as $hw) {
            $status  = $hw['status'] ?? 'Active';
            if (strcasecmp($status, 'Active') !== 0) continue;
            $dd = $this->_dueDatePrefix($hw['dueDate'] ?? '');
            if ($dd === '') continue;
            if ($dd === $today) $dueToday++;
            if ($dd >= $today && $dd <= $weekEnd) $dueWeek++;
        }

        // BUG-016 — dual-emit per project HR canonical pattern
        // (PROJECT_STATUS.md §1). Flat fields preserved for the current
        // dashboard view consumer; `overview` namespaced wrapper added
        // for shape consistency with the other 9 read endpoints and to
        // give future API consumers (2027 public-API roadmap) a single
        // form to read.
        $overview = [
            'total'       => $total,
            'active'      => $active,
            'overdue'     => $overdue,
            'closed'      => $closed,
            'archived'    => $archived,
            'avg_rate'    => $avgRate,
            'due_today'   => $dueToday,
            'due_week'    => $dueWeek,
        ];
        $this->json_success(array_merge($overview, ['overview' => $overview]));
    }

    /**
     * Full homework list with optional filters.
     *
     * POST params: class, section, subject, teacher, status, date_from, date_to
     */
    public function get_homework_list()
    {
        $this->_require_role(self::VIEW_ROLES, 'homework_list', 'Homework', 'view');

        $filterClass   = trim($this->input->post('class') ?? '');
        $filterSection = trim($this->input->post('section') ?? '');
        $filterSubject = trim($this->input->post('subject') ?? '');
        $filterTeacher = trim($this->input->post('teacher') ?? '');
        $filterStatus  = trim($this->input->post('status') ?? '');
        $dateFrom      = trim($this->input->post('date_from') ?? '');
        $dateTo        = trim($this->input->post('date_to') ?? '');

        $all  = $this->_fetch_all_homework();
        $list = [];
        $today = $this->_school_today();  // Finding #9 2026-05-14 — school-timezone (IST), not server UTC; parity with M1A.7

        foreach ($all as $hw) {
            // Apply filters
            if ($filterClass && $hw['_class'] !== $filterClass) continue;
            if ($filterSection && $hw['_section'] !== $filterSection) continue;
            if ($filterSubject && strcasecmp($hw['subject'] ?? '', $filterSubject) !== 0) continue;
            if ($filterTeacher && ($hw['teacherId'] ?? '') !== $filterTeacher) continue;

            $status = $hw['status'] ?? 'Active';
            // Date-only prefix for comparisons — see _dueDatePrefix() doc.
            $ddRaw  = $hw['dueDate'] ?? '';
            $ddDate = $this->_dueDatePrefix($ddRaw);
            if ($filterStatus) {
                if (strcasecmp($filterStatus, 'Overdue') === 0) {
                    if (!(strcasecmp($status, 'Active') === 0 && $ddDate && $ddDate < $today)) continue;
                } else {
                    if (strcasecmp($status, $filterStatus) !== 0) continue;
                }
            }

            if ($dateFrom && $ddDate && $ddDate < $dateFrom) continue;
            if ($dateTo   && $ddDate && $ddDate > $dateTo)   continue;

            $rate = $this->_calc_submission_rate($hw);
            $isOverdue = (strcasecmp($status, 'Active') === 0 && $ddDate && $ddDate < $today);

            $list[] = [
                'id'          => $hw['_id'],
                'title'       => $hw['title'] ?? 'Untitled',
                'subject'     => $hw['subject'] ?? '-',
                'class'       => $hw['_class'],
                'section'     => $hw['_section'],
                'teacherId'   => $hw['teacherId'] ?? '',
                'teacherName' => $hw['teacherName'] ?? '-',
                'dueDate'     => $ddRaw,
                'status'      => $isOverdue ? 'Overdue' : ucfirst(strtolower($status)),
                'rate'        => $rate ?? 0,
                'createdAt'   => $hw['createdAt'] ?? 0,
                'description' => $hw['description'] ?? '',
            ];
        }

        // Sort by createdAt descending. Use _normalizeTimestamp (same helper
        // _fetch_all_homework uses) so a Firestore Timestamp-map createdAt
        // ({_seconds}/{seconds}) is handled too — the prior inline comparator
        // only coped with numeric/ISO-string forms and sorted maps as 0.
        usort($list, function ($a, $b) {
            $ta = $this->_normalizeTimestamp($a['createdAt'] ?? 0);
            $tb = $this->_normalizeTimestamp($b['createdAt'] ?? 0);
            return $tb <=> $ta;
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
        $this->_require_role(self::VIEW_ROLES, 'homework_detail', 'Homework', 'view');

        $hwId = $this->safe_path_segment($this->input->post('hw_id') ?? '', 'hw_id');

        // Read from Firestore
        $hw = $this->firebase->firestoreGet('homework', $hwId);
        if (!is_array($hw)) {
            $this->json_error('Homework not found.', 404);
        }
        if (($hw['schoolId'] ?? $hw['schoolCode'] ?? '') !== $this->school_name) {
            // BUG-014 — emit security telemetry on cross-tenant access attempt.
            // $this->sec_telem auto-init'd by MY_Controller::_require_role above.
            if (isset($this->sec_telem) && $this->sec_telem->isReady()) {
                $this->sec_telem->emit('CROSS_TENANT_PROBE', 'warning', [
                    'endpoint'         => 'Homework::get_homework_detail',
                    'collection'       => 'homework',
                    'attempted_doc_id' => $hwId,
                    'attempted_school' => (string) ($hw['schoolId'] ?? $hw['schoolCode'] ?? ''),
                    'actor_school'     => (string) $this->school_name,
                ]);
            }
            // BUG-015 — return 404 (same as truly-not-found) to close the
            // existence oracle. The BUG-014 telemetry emit above already
            // captured the cross-tenant attempt in security_events; the
            // response itself is now indistinguishable from "doc absent".
            $this->json_error('Homework not found.', 404);
        }

        // Merge submissions + teacherMarks + full class roster (submission >
        // mark > pending) via the shared helper. Detail drops the tracker-only
        // rollNo/source fields; the counts below derive from this merged list
        // so submitted + pending always equals the row count.
        $submissionList = $this->_merge_submissions($hw, $hwId, false);

        // Count from the merged list so submitted + pending == row count.
        $submitted = 0;
        $pending   = 0;
        foreach ($submissionList as $r) {
            if (in_array(strtolower($r['status']), ['submitted', 'reviewed', 'complete', 'done'])) {
                $submitted++;
            } else {
                $pending++;
            }
        }

        $today = $this->_school_today();  // Finding #9 2026-05-14 — school-timezone (IST), not server UTC; parity with M1A.7
        $status  = $hw['status'] ?? 'active';
        $dueDate = $hw['dueDate'] ?? '';
        $ddDate  = $this->_dueDatePrefix($dueDate);
        $isOverdue = (strtolower($status) === 'active' && $ddDate && $ddDate < $today);
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
        $this->_require_role(self::VIEW_ROLES, 'homework_submissions', 'Homework', 'view');

        $hwId = $this->safe_path_segment($this->input->post('hw_id') ?? '', 'hw_id');

        // Read homework from Firestore
        $hw = $this->firebase->firestoreGet('homework', $hwId);
        if (!is_array($hw)) {
            $this->json_error('Homework not found.', 404);
        }
        if (($hw['schoolId'] ?? $hw['schoolCode'] ?? '') !== $this->school_name) {
            // BUG-014 — emit security telemetry on cross-tenant access attempt.
            if (isset($this->sec_telem) && $this->sec_telem->isReady()) {
                $this->sec_telem->emit('CROSS_TENANT_PROBE', 'warning', [
                    'endpoint'         => 'Homework::get_submissions',
                    'collection'       => 'homework',
                    'attempted_doc_id' => $hwId,
                    'attempted_school' => (string) ($hw['schoolId'] ?? $hw['schoolCode'] ?? ''),
                    'actor_school'     => (string) $this->school_name,
                ]);
            }
            // BUG-015 — return 404 (same as truly-not-found) to close the
            // existence oracle. The BUG-014 telemetry emit above already
            // captured the cross-tenant attempt in security_events; the
            // response itself is now indistinguishable from "doc absent".
            $this->json_error('Homework not found.', 404);
        }

        // Merge submissions + teacherMarks + full class roster (submission >
        // mark > pending) via the shared helper. The tracker keeps the
        // rollNo/source fields.
        $result = $this->_merge_submissions($hw, $hwId, true);

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
        $this->_require_role(self::VIEW_ROLES, 'homework_class_summary', 'Homework', 'view');

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
        $this->_require_role(self::VIEW_ROLES, 'homework_teacher_activity', 'Homework', 'view');

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
        $this->_require_role(self::VIEW_ROLES, 'homework_subject_breakdown', 'Homework', 'view');

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
        $this->_require_role(self::VIEW_ROLES, 'homework_overdue_report', 'Homework', 'view');

        $all   = $this->_fetch_all_homework();
        $today = $this->_school_today();  // Finding #9 2026-05-14 — school-timezone (IST), not server UTC; parity with M1A.7
        $list  = [];

        foreach ($all as $hw) {
            $status  = $hw['status'] ?? 'Active';
            $dueDate = $hw['dueDate'] ?? '';
            $ddDate  = $this->_dueDatePrefix($dueDate);

            if (strcasecmp($status, 'Active') !== 0) continue;
            if (!$ddDate || $ddDate >= $today) continue;

            // Day-diff is computed from the date prefix to avoid timezone
            // bias from the ISO time/offset suffix.
            $daysPast = (int) ((strtotime($today) - strtotime($ddDate)) / 86400);
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
        $this->_require_role(self::VIEW_ROLES, 'homework_trends', 'Homework', 'view');

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

        // BUG-016 — dual-emit per project HR canonical pattern. Flat
        // fields preserved for the current chart consumer; `trends`
        // namespaced wrapper added for shape consistency.
        $trends = [
            'labels'  => $labels,
            'created' => $created,
            'rates'   => $rates,
        ];
        $this->json_success(array_merge($trends, ['trends' => $trends]));
    }

    /**
     * Get the canonical list of subjects taught in a class (or, if no
     * class is given, every subject taught in the school). Sourced from
     * `subjectAssignments` so the dropdown matches what teachers actually
     * teach — preventing the previous "type any string" footgun that let
     * "Maths" / "Mathematics" / "MATH" coexist as separate subjects in
     * the homework collection.
     *
     * Admin-only meta assignments (e.g. "Class Teacher Duty" with code 999)
     * are filtered out so they don't pollute the homework dropdown.
     *
     * Common spelling drift is normalised on the way out so the dropdown
     * never shows both "Maths" AND "Mathematics" as separate options. The
     * underlying data should still be cleaned via the Maths-fix script.
     *
     * POST: class (optional)
     */
    public function get_subjects_for_class()
    {
        $this->_require_role(self::VIEW_ROLES, 'homework_subjects', 'Homework', 'view');

        $class = trim($this->input->post('class') ?? '');

        $this->json_success(['subjects' => $this->_subjects_for_class($class)]);
    }

    /**
     * Valid, deduped, sorted subject names for a class in the current session.
     *
     * Single source of truth for the create-form dropdown
     * (get_subjects_for_class) AND server-side validation in update_homework,
     * so an edit can't set a subject the dropdown would never have offered.
     *
     * @param string $class Raw class label ("8" / "8th" / "Class 8th"); ''
     *                      returns subjects across all classes this session.
     * @return string[]
     */
    private function _subjects_for_class(string $class): array
    {
        // Filter to the current session — subjectAssignments stores one doc
        // per (school, session, class, section, subjectCode) so an
        // un-scoped query returns last year's assignments alongside this
        // year's, doubling/tripling every entry in the dropdown.
        $filters = [
            ['schoolId', '=', $this->school_name],
            ['session',  '=', $this->session_year],
        ];
        if ($class !== '') {
            // Normalize so "8" / "8th" / "Class 8th" all resolve to the
            // same canonical "Class 8th" written by the assignments writer.
            require_once APPPATH . 'libraries/Entity_firestore_sync.php';
            $cs = \Entity_firestore_sync::normalizeClassSection($class, '');
            $canonClass = $cs['className'] ?: $class;
            $filters[] = ['className', '=', $canonClass];
        }

        // Subject names that are admin/duty meta-assignments rather than
        // actual teachable subjects — must not appear in the homework
        // dropdown. Match case-insensitively.
        $excludedSubjects = ['class teacher duty', 'duty', 'class teacher'];
        // Admin/meta subject codes (legacy + current). 999 is the canonical
        // class-teacher marker in this codebase.
        $excludedCodes = ['999'];
        // Subject-name normalisation — collapses common spelling variants
        // so the dropdown shows one canonical entry. Lowercased keys.
        $aliasMap = [
            'maths' => 'Mathematics',
            'math'  => 'Mathematics',
        ];

        $subjects = [];
        try {
            $docs = $this->firebase->firestoreQuery(
                'subjectAssignments', $filters, null, 'ASC', self::MAX_QUERY_LIMIT
            );
            $seen = [];
            foreach ($docs as $doc) {
                $d = $doc['data'] ?? [];
                $code = trim((string)($d['subjectCode'] ?? ''));
                if (in_array($code, $excludedCodes, true)) continue;

                $rawName = trim((string)($d['subjectName'] ?? ''));
                if ($rawName === '') continue;

                $lower = strtolower($rawName);
                if (in_array($lower, $excludedSubjects, true)) continue;

                // Normalise via alias map so "Maths" → "Mathematics" before
                // dedup. Falls through with original name otherwise.
                $name = $aliasMap[$lower] ?? $rawName;

                $key = strtolower($name);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $subjects[] = $name;
            }
            sort($subjects, SORT_NATURAL | SORT_FLAG_CASE);
        } catch (\Exception $e) {
            log_message('error', 'Homework::_subjects_for_class — Firestore query failed: ' . $e->getMessage());
        }

        return $subjects;
    }

    /**
     * Get students for a class/section (for submission tracking tab).
     * Firestore-only (no RTDB fallback).
     */
    public function get_students_for_class()
    {
        $this->_require_role(self::VIEW_ROLES, 'homework_students', 'Homework', 'view');

        $class   = $this->safe_path_segment($this->input->post('class') ?? '', 'class');
        $section = $this->safe_path_segment($this->input->post('section') ?? '', 'section');

        $students = [];

        // Normalize the inbound class/section so the sectionKey matches what
        // the mobile apps wrote. The form already submits canonical values in
        // most cases, but defend against legacy short forms ("8", "A") that
        // would silently miss every document.
        require_once APPPATH . 'libraries/Entity_firestore_sync.php';
        $cs = \Entity_firestore_sync::normalizeClassSection($class, $section);
        $cls     = $cs['className'] ?: $class;
        $secFull = $cs['section']   ?: $section;
        $sectionKey = "{$cls}/{$secFull}";

        try {
            $docs = $this->firebase->firestoreQuery(
                'students',
                [
                    ['schoolId',   '=', $this->school_name],
                    ['sectionKey', '=', $sectionKey],
                ],
                null, 'ASC', self::QUERY_LIMIT
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
            log_message('error', 'Homework::get_students_for_class — Firestore query failed: ' . $e->getMessage());
        }

        $this->json_success(['students' => $students]);
    }

    /**
     * Get all class/section combos for the school (for create form + filters).
     * Reads from Firestore sections collection.
     */
    public function get_class_sections()
    {
        $this->_require_role(self::VIEW_ROLES, 'homework_class_sections', 'Homework', 'view');

        $result = [];

        // Firestore sections collection — single source of truth.
        try {
            $docs = $this->firebase->firestoreQuery(
                'sections',
                [['schoolId', '=', $this->school_name]],
                null, 'ASC', self::QUERY_LIMIT
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
            log_message('error', 'Homework::get_class_sections — Firestore query failed: ' . $e->getMessage());
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
        $this->_require_role(self::MANAGE_ROLES, 'homework_create', 'Homework', 'edit');

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
        if ($title === '')                $this->json_error('Title is required.', 400);
        if (strlen($title)       > 200)   $this->json_error('Title exceeds 200 characters.', 400);         // BUG-013
        if ($subject === '')              $this->json_error('Subject is required.', 400);
        if (strlen($subject)     > 100)   $this->json_error('Subject exceeds 100 characters.', 400);       // BUG-013
        if (strlen($description) > 10000) $this->json_error('Description exceeds 10000 characters.', 400); // BUG-013
        if ($dueDate === '')              $this->json_error('Due date is required.', 400);
        if (empty($sections))             $this->json_error('At least one section is required.', 400);

        $createdIds = [];

        // Canonical class/section normalizer — must match the format Teacher +
        // Parent apps use ("Class 8th" / "Section A"), otherwise the docs
        // become invisible to mobile queries that filter by sectionKey.
        require_once APPPATH . 'libraries/Entity_firestore_sync.php';
        // Firestore Timestamp helper — admin must write `createdAt` as a
        // proper Timestamp so it sorts together with Teacher-app writes
        // (which use serverTimestamp()). Number-vs-Timestamp mixed types
        // sort separately in Firestore, pushing admin docs to the bottom
        // of the mobile-app's "recent homework" lists.
        require_once APPPATH . 'libraries/Firestore_rest_client.php';

        foreach ($sections as $sec) {
            $sec = $this->safe_path_segment(trim($sec), 'section');

            // Normalize via the canonical helper (same as every other admin
            // writer — Academic, Attendance, Subject_assignment_service, etc).
            // "8" → "Class 8th", "A" → "Section A", and existing prefixes
            // pass through unchanged.
            $cs = \Entity_firestore_sync::normalizeClassSection($class, $sec);
            $cls     = $cs['className'] ?: $class;
            $secFull = $cs['section']   ?: $sec;
            $sectionKey = "{$cls}/{$secFull}";

            // Finding #8 2026-05-14 — entropy suffix added to prevent
            // millisecond-collision in multi-section bulk-create (this
            // foreach iterates fast enough that adjacent iterations can
            // share the same microtime when Finding #22's roster query
            // is cached or fast). 2^32 entropy values per millisecond
            // per school make collisions effectively impossible.
            // Format stays {schoolId}_{ms}_{hex8} — lexicographic
            // timestamp ordering preserved; hex suffix is tiebreaker.
            // hwId treated opaquely by all consumers (verified pre-deploy
            // grep across Admin PHP + Teacher + Parent — zero parsers).
            //
            // Backlog dependency: any future Finding #22 cache optimization
            // MUST verify this entropy hardening is deployed first, or
            // bundle both fixes together. Without #8 entropy, #22 caching
            // would amplify multi-section bulk-create collisions to
            // routine occurrence.
            $hwId = "{$this->school_name}_"
                  . round(microtime(true) * 1000)
                  . '_' . bin2hex(random_bytes(4));

            // Roster size for accurate submission rate. Post-BUG-003: fail
            // closed on roster query exception (previously left totalStudents
            // at 0 silently, persisting a misleading denormalized baseline).
            // Empty result is still permitted (sectionKey may legitimately
            // have zero students). The fallback in _calc_submission_rate
            // covers the empty-result case via direct submission counting.
            //
            // Finding #22 (deferred, 2026-05-14): this synchronous roster
            // query blocks the create response. Bounded P3 operational
            // concern:
            //   • Typical class (20-50 students): ~200-500ms latency
            //   • Slow / quota-throttled Firestore: 5-30s; bulk-create by
            //     malicious insider can freeze admin panel (recoverable
            //     by stopping the bulk creator; insider-only scope)
            //   • 1000-cap silently truncates roster for mega-classes
            //     (1001+ students) — rare but produces wrong totalStudents
            //   • Audit's originally-cited site (Homework_firestore_sync
            //     _countStudents) is DEAD CODE — legacy RTDB→Firestore
            //     migration library no longer in active flow
            // Proper treatment (cache by (schoolId, sectionKey) + cursor
            // pagination + cap warning) deferred to a future denormalization
            // hardening phase that unifies with Finding #20 (totalStudents
            // fallback semantics) and Finding #2/#37 (denormalized
            // submissionCount). No current cross-tenant or privilege-
            // escalation risk; operational degradation is recoverable.
            $totalStudents = 0;
            try {
                $rosterDocs = $this->firebase->firestoreQuery(
                    'students',
                    [
                        ['schoolId',   '=', $this->school_name],
                        ['sectionKey', '=', $sectionKey],
                    ],
                    null, 'ASC', self::MAX_QUERY_LIMIT
                );
                $totalStudents = is_array($rosterDocs) ? count($rosterDocs) : 0;
            } catch (\Exception $e) {
                // BUG-003 — fail-closed on roster lookup failure. Previously
                // left totalStudents=0 silently. Partial-state note: sections
                // processed before this iteration are already written; the 503
                // response surfaces the createdIds count so the operator can
                // retry only the remaining section(s).
                log_message('error', 'Homework::create_homework — roster lookup failed for sectionKey='
                    . $sectionKey . ' (createdSoFar=' . count($createdIds) . ' of ' . count($sections)
                    . ' sections, ids=[' . implode(',', $createdIds) . ']); aborting create: ' . $e->getMessage());
                $this->json_error(
                    'Roster lookup failed for section "' . $secFull . '". '
                    . count($createdIds) . ' of ' . count($sections)
                    . ' section(s) already created. Retry the remaining section(s) after a moment.',
                    503
                );
                return;
            }

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
                'dueDate'         => $this->_normalizeDueDate($dueDate),
                'createdAt'       => \FirestoreRestClient::timestamp(round(microtime(true) * 1000)),
                'status'          => 'active',
                'submissionCount' => 0,
                'totalStudents'   => $totalStudents,
                'attachments'     => [],
            ];

            // Write directly to Firestore
            $ok = $this->firebase->firestoreSet('homework', $hwId, $hwData);

            if ($ok) {
                // Enqueue a push request so parents (and class teachers) get
                // notified — mirrors the Teacher app's HomeworkFirestoreRepo
                // shape so a single Cloud Function dispatcher can fan out
                // both admin- and teacher-created homework. The dispatcher
                // for HOMEWORK_CREATED is not yet wired into functions/
                // index.js (see comment at the early-return on unknown mark);
                // until that lands, the doc sits with status=pending and is
                // harmless. Best-effort write — swallowed errors don't abort
                // the homework create.
                try {
                    $reqId = "{$this->school_name}_hw_{$hwId}";
                    // Pre-format the due date in IST so the Cloud Function (or
                    // any future notification renderer) doesn't have to parse
                    // ISO. End user sees "06 May 2026, 11:59 PM IST" instead
                    // of "2026-05-06T23:59:59+05:30".
                    $dueDateDisplay = $hwData['dueDate'];
                    try {
                        $dt = new \DateTime($hwData['dueDate']);
                        $dt->setTimezone(new \DateTimeZone('Asia/Kolkata'));
                        $dueDateDisplay = $dt->format('d M Y, h:i A') . ' IST';
                    } catch (\Exception $eFmt) {
                        // Leave as raw ISO if parse failed
                    }
                    $this->firebase->firestoreSet('pushRequests', $reqId, [
                        'schoolId'        => $this->school_name,
                        'studentId'       => '',
                        'mark'            => 'HOMEWORK_CREATED',
                        'class'           => $cls,
                        'section'         => $secFull,
                        'day'             => 0,
                        'month'           => '',
                        'date'            => '',
                        'source'          => 'homework_created',
                        'markedBy'        => $this->admin_name ?? 'Admin',
                        'status'          => 'pending',
                        'homeworkId'      => $hwId,
                        'title'           => $title,
                        'subject'         => $subject,
                        'dueDate'         => $hwData['dueDate'],   // raw ISO
                        'dueDateDisplay'  => $dueDateDisplay,       // human-readable
                        'sectionKey'      => $sectionKey,
                        'createdAt'       => date('c'),
                    ]);
                } catch (\Exception $e) {
                    log_message('error', 'homework_create pushRequests write failed (non-fatal): ' . $e->getMessage());
                }
                $createdIds[] = ['class' => $cls, 'section' => $secFull, 'id' => $hwId];
            }
        }

        if (empty($createdIds)) {
            $this->json_error('Failed to create homework. Please try again.', 500);
        }

        // Partial-success surfacing: firestoreSet can return false without
        // throwing, in which case that section is silently skipped (not added
        // to $createdIds). Compare requested vs created so the UI can warn the
        // operator which section(s) need a retry instead of reporting a clean
        // success.
        $requested = count($sections);
        $created   = count($createdIds);
        if ($created < $requested) {
            log_message('error', 'Homework::create_homework — partial create: '
                . $created . ' of ' . $requested . ' section(s) saved for title="' . $title
                . '" (class=' . $class . ')');
        }
        log_audit('Homework', 'homework_create', $class, "Created homework: {$title} for {$created} of {$requested} section(s)");

        $message = ($created < $requested)
            ? "Homework saved for {$created} of {$requested} section(s). The remaining section(s) could not be saved — please retry them."
            : 'Homework created successfully.';

        $this->json_success([
            'created'   => $createdIds,
            'requested' => $requested,
            'message'   => $message,
        ]);
    }

    /**
     * Update homework details.
     *
     * POST: class, section, hw_id, title, description, subject, due_date, status
     */
    public function update_homework()
    {
        $this->_require_role(self::MANAGE_ROLES, 'homework_update', 'Homework', 'edit');

        $hwId = $this->safe_path_segment($this->input->post('hw_id') ?? '', 'hw_id');

        // Read from Firestore
        $existing = $this->firebase->firestoreGet('homework', $hwId);
        if (!is_array($existing)) {
            $this->json_error('Homework not found.', 404);
        }
        if (($existing['schoolId'] ?? $existing['schoolCode'] ?? '') !== $this->school_name) {
            // BUG-014 — emit security telemetry on cross-tenant access attempt.
            if (isset($this->sec_telem) && $this->sec_telem->isReady()) {
                $this->sec_telem->emit('CROSS_TENANT_PROBE', 'warning', [
                    'endpoint'         => 'Homework::update_homework',
                    'collection'       => 'homework',
                    'attempted_doc_id' => $hwId,
                    'attempted_school' => (string) ($existing['schoolId'] ?? $existing['schoolCode'] ?? ''),
                    'actor_school'     => (string) $this->school_name,
                ]);
            }
            // BUG-015 — return 404 (same as truly-not-found) to close the
            // existence oracle. The BUG-014 telemetry emit above already
            // captured the cross-tenant attempt in security_events; the
            // response itself is now indistinguishable from "doc absent".
            $this->json_error('Homework not found.', 404);
        }

        $updates = [];

        $title = trim($this->input->post('title') ?? '');
        if ($title !== '' && strlen($title) > 200) $this->json_error('Title exceeds 200 characters.', 400);          // BUG-013
        if ($title !== '') $updates['title'] = $title;

        $desc = trim($this->input->post('description') ?? '');
        if ($desc !== '' && strlen($desc) > 10000) $this->json_error('Description exceeds 10000 characters.', 400);  // BUG-013
        if ($desc !== '') $updates['description'] = $desc;

        $subj = trim($this->input->post('subject') ?? '');
        if ($subj !== '') {
            if (strlen($subj) > 100) $this->json_error('Subject exceeds 100 characters.', 400);                      // BUG-013
            // BUG-4 — validate the submitted subject against the class's
            // subjectAssignments (the same source the create-form dropdown
            // uses) instead of accepting arbitrary free text. Enforce only
            // when the class actually has assignments; an empty list (none
            // configured, or a transient Firestore failure) falls back to the
            // length guard above so a legitimate edit is never blocked.
            $validSubjects = $this->_subjects_for_class((string) ($existing['className'] ?? ''));
            if (!empty($validSubjects)) {
                $match = null;
                foreach ($validSubjects as $vs) {
                    if (strcasecmp($vs, $subj) === 0) { $match = $vs; break; }
                }
                if ($match === null) {
                    $this->json_error(
                        'Invalid subject for this class. Choose one of: ' . implode(', ', $validSubjects),
                        400
                    );
                }
                $subj = $match;  // canonicalise to the assignment's spelling/case
            }
            $updates['subject'] = $subj;
        }

        // Finding #21 2026-05-14 — guard retroactive dueDate shortening.
        // Before this guard, any MANAGE_ROLES holder could set dueDate to a
        // past date with one PHP call, instantly marking live homework
        // "Overdue" for an entire class. Gate triggers only when:
        //   (a) dueDate is being changed
        //   (b) AND new dueDate is earlier than existing dueDate (shortening)
        //   (c) AND at least one submission already exists
        // Without `force=1`, the request is rejected with HTTP 409. With
        // `force=1`, the change proceeds but the audit-log entry records
        // FORCE_SHORTENING=true for forensic visibility. Extending dueDate
        // and editing dueDate before any submission exist are unaffected.
        //
        // If the submissions-existence query itself fails (Firestore
        // unreachable / quota / network), the request fails closed (503)
        // rather than silently allowing the shortening — admin retries
        // when connectivity restores.
        $oldDueDate           = (string) ($existing['dueDate'] ?? '');
        $newDueDateNormalized = '';
        $forcedShortening     = false;

        $dd = trim($this->input->post('due_date') ?? '');
        if ($dd !== '') {
            $newDueDateNormalized = $this->_normalizeDueDate($dd);

            // Compare date prefixes only (timezone-agnostic, matches the
            // existing _dueDatePrefix() helper semantics used elsewhere).
            $oldPrefix = $this->_dueDatePrefix($oldDueDate);
            $newPrefix = $this->_dueDatePrefix($newDueDateNormalized);

            if ($oldPrefix !== '' && $newPrefix !== '' && $newPrefix < $oldPrefix) {
                // Shortening detected — verify whether submissions exist.
                $forceFlag = trim((string) ($this->input->post('force') ?? ''));
                $isForced  = in_array(strtolower($forceFlag), ['1', 'true', 'yes'], true);

                $submissionsExist = null;
                try {
                    $subs = $this->firebase->firestoreQuery(
                        'submissions',
                        [
                            ['schoolId',   '=', $this->school_name],
                            ['homeworkId', '=', $hwId],
                        ],
                        null, 'ASC', 1
                    );
                    $submissionsExist = is_array($subs) && !empty($subs);
                } catch (\Exception $e) {
                    log_message('error',
                        "Homework::update_homework — submission-count verification failed for {$hwId}: "
                        . $e->getMessage());
                    $this->json_error(
                        'Could not verify submission count for dueDate change. Retry shortly.',
                        503
                    );
                    return;
                }

                if ($submissionsExist && !$isForced) {
                    $this->json_error(
                        'Shortening the dueDate is not permitted after submissions exist for this homework. '
                        . 'Re-submit with force=1 to override (this action will be audited).',
                        409
                    );
                    return;
                }
                if ($submissionsExist && $isForced) {
                    $forcedShortening = true;
                }
            }

            $updates['dueDate'] = $newDueDateNormalized;
        }

        $st = trim($this->input->post('status') ?? '');
        if ($st !== '' && in_array(strtolower($st), ['active', 'closed', 'archived'], true)) {
            $updates['status'] = strtolower($st);
        }

        if (empty($updates)) {
            $this->json_error('No changes provided.', 400);
        }

        // Write to Firestore directly
        $this->firebase->firestoreUpdate('homework', $hwId, $updates);

        // Finding #21 audit enrichment: when dueDate is in the update set,
        // capture old → new values in the audit description so forensic
        // review can detect magnitude of change. FORCE_SHORTENING marker
        // distinguishes explicit overrides from routine edits.
        $auditMsg = "Updated homework: " . implode(', ', array_keys($updates));
        if (isset($updates['dueDate'])) {
            $auditMsg .= ' | dueDate old=' . ($oldDueDate !== '' ? $oldDueDate : '(none)')
                       . ' new=' . $newDueDateNormalized;
            if ($forcedShortening) {
                $auditMsg .= ' FORCE_SHORTENING=true';
            }
        }
        log_audit('Homework', 'homework_update', $hwId, $auditMsg);

        $this->json_success(['message' => 'Homework updated successfully.']);
    }

    /**
     * Delete homework.
     *
     * POST: class, section, hw_id
     */
    public function delete_homework()
    {
        $this->_require_role(self::MANAGE_ROLES, 'homework_delete', 'Homework', 'manage');

        $hwId = $this->safe_path_segment($this->input->post('hw_id') ?? '', 'hw_id');

        // Read from Firestore
        $existing = $this->firebase->firestoreGet('homework', $hwId);
        if (!is_array($existing)) {
            $this->json_error('Homework not found.', 404);
        }
        if (($existing['schoolId'] ?? $existing['schoolCode'] ?? '') !== $this->school_name) {
            // BUG-014 — emit security telemetry on cross-tenant access attempt.
            if (isset($this->sec_telem) && $this->sec_telem->isReady()) {
                $this->sec_telem->emit('CROSS_TENANT_PROBE', 'warning', [
                    'endpoint'         => 'Homework::delete_homework',
                    'collection'       => 'homework',
                    'attempted_doc_id' => $hwId,
                    'attempted_school' => (string) ($existing['schoolId'] ?? $existing['schoolCode'] ?? ''),
                    'actor_school'     => (string) $this->school_name,
                ]);
            }
            // BUG-015 — return 404 (same as truly-not-found) to close the
            // existence oracle. The BUG-014 telemetry emit above already
            // captured the cross-tenant attempt in security_events; the
            // response itself is now indistinguishable from "doc absent".
            $this->json_error('Homework not found.', 404);
        }

        // Chunked atomic delete with deterministic cursor pagination.
        // Pages through submissions ordered by document ID (__name__) using
        // startAfter(lastDocId) — guarantees no skipped docs and no repeated
        // reads even when the dataset exceeds the 499-per-batch cap. Each
        // batch commit is checked; any failure aborts and surfaces an error
        // without progressing to the homework delete.
        $totalDeleted = 0;
        $chunkLimit   = self::FETCH_CHUNK;
        $lastDocId    = '';
        $maxIters     = self::FETCH_MAX_ITERS;  // safety cap: 250 × 499 ≈ 125k subs/homework

        for ($iter = 0; $iter < $maxIters; $iter++) {
            try {
                // 4d defense-in-depth 2026-05-14: schoolId filter added even
                // though line 1142 ownership gate already verified the parent
                // homework belongs to $this->school_name. Explicit filter
                // protects against future refactor weakening the gate and
                // closes the spoofed-submission edge case (parent writing a
                // submission with cross-school homeworkId). Served by existing
                // (schoolId, homeworkId) composite index at indexes.json:238.
                $submissions = $this->firebase->firestoreQuery(
                    'submissions',
                    [
                        ['schoolId',   '=', $this->school_name],
                        ['homeworkId', '=', $hwId],
                    ],
                    '__name__',
                    'ASC',
                    $chunkLimit,
                    $lastDocId !== '' ? $lastDocId : null
                );
            } catch (\Exception $e) {
                $this->json_error('Failed to query submissions. Delete aborted (' . $totalDeleted . ' submissions removed before failure).', 500);
                return;
            }

            if (empty($submissions)) break;

            $ops = [];
            $newLast = '';
            foreach ($submissions as $sub) {
                $subId = $sub['id'] ?? '';
                if ($subId === '') continue;
                $ops[] = ['op' => 'delete', 'collection' => 'submissions', 'docId' => $subId];
                $newLast = $subId;
            }
            if (empty($ops)) break;

            // Pagination safety — if the cursor didn't advance, the query
            // stalled (e.g. orderBy index/fallback issue). Abort instead of
            // looping forever or repeatedly deleting the same docs.
            if ($newLast === $lastDocId) {
                $this->json_error('Pagination cursor stalled. Delete aborted (' . $totalDeleted . ' submissions removed before failure).', 500);
                return;
            }

            $ok = false;
            try { $ok = $this->firebase->firestoreCommitBatch($ops); }
            catch (\Exception $e) { $ok = false; }

            if (!$ok) {
                $this->json_error('Failed to delete submissions. Delete aborted (' . $totalDeleted . ' removed before failure). Homework not removed.', 500);
                return;
            }

            $totalDeleted += count($ops);
            $lastDocId    = $newLast;

            if (count($submissions) < $chunkLimit) break;
        }

        if ($iter >= $maxIters) {
            $this->json_error('Submission delete iteration cap reached (' . $totalDeleted . ' removed). Re-run delete to continue.', 500);
            return;
        }

        // 4a teacherMarks cascade 2026-05-14: sibling-drift bug fix. The
        // Teacher app cascades teacherMarks on delete (HomeworkTeacherViewModel
        // executeDelete lines 363-376); Admin previously did not, leaving
        // orphan mark docs for students who were graded without submitting.
        // Same chunked-paginated pattern as submissions above; same error
        // surfacing. Schoolid filter is required (defense-in-depth + serves
        // composite index at indexes.json:940).
        $marksDeleted = 0;
        $lastMarkId   = '';
        for ($mIter = 0; $mIter < $maxIters; $mIter++) {
            try {
                $marks = $this->firebase->firestoreQuery(
                    'teacherMarks',
                    [
                        ['schoolId',   '=', $this->school_name],
                        ['homeworkId', '=', $hwId],
                    ],
                    '__name__',
                    'ASC',
                    $chunkLimit,
                    $lastMarkId !== '' ? $lastMarkId : null
                );
            } catch (\Exception $e) {
                $this->json_error('Submissions removed (' . $totalDeleted . ') but teacherMarks query failed (' . $marksDeleted . ' marks removed before failure). Homework not removed.', 500);
                return;
            }

            if (empty($marks)) break;

            $mOps = [];
            $newLastMark = '';
            foreach ($marks as $mk) {
                $mId = $mk['id'] ?? '';
                if ($mId === '') continue;
                $mOps[] = ['op' => 'delete', 'collection' => 'teacherMarks', 'docId' => $mId];
                $newLastMark = $mId;
            }
            if (empty($mOps)) break;

            if ($newLastMark === $lastMarkId) {
                $this->json_error('Submissions removed (' . $totalDeleted . ') but teacherMarks pagination cursor stalled (' . $marksDeleted . ' marks removed before failure). Homework not removed.', 500);
                return;
            }

            $mOk = false;
            try { $mOk = $this->firebase->firestoreCommitBatch($mOps); }
            catch (\Exception $e) { $mOk = false; }

            if (!$mOk) {
                $this->json_error('Submissions removed (' . $totalDeleted . ') but teacherMarks deletion failed (' . $marksDeleted . ' removed before failure). Homework not removed.', 500);
                return;
            }

            $marksDeleted += count($mOps);
            $lastMarkId    = $newLastMark;

            if (count($marks) < $chunkLimit) break;
        }

        if ($mIter >= $maxIters) {
            $this->json_error('teacherMarks delete iteration cap reached (' . $marksDeleted . ' removed). Re-run delete to continue.', 500);
            return;
        }

        // All submissions + teacherMarks gone — now remove the homework doc itself.
        $ok = false;
        try {
            $ok = $this->firebase->firestoreCommitBatch([
                ['op' => 'delete', 'collection' => 'homework', 'docId' => $hwId]
            ]);
        } catch (\Exception $e) { $ok = false; }

        if (!$ok) {
            $this->json_error('Submissions (' . $totalDeleted . ') and teacherMarks (' . $marksDeleted . ') removed but homework deletion failed. Re-run delete to retry.', 500);
            return;
        }

        log_audit('Homework', 'homework_delete', $hwId, "Deleted homework: " . ($existing['title'] ?? '') . " (+" . $totalDeleted . " submissions, +" . $marksDeleted . " teacherMarks)");

        $this->json_success(['message' => 'Homework deleted successfully.']);
    }

    /**
     * Close homework — mark as Closed.
     *
     * POST: class, section, hw_id
     */
    public function close_homework()
    {
        $this->_require_role(self::MANAGE_ROLES, 'homework_close', 'Homework', 'edit');

        $hwId = $this->safe_path_segment($this->input->post('hw_id') ?? '', 'hw_id');

        $existing = $this->firebase->firestoreGet('homework', $hwId);
        if (!is_array($existing)) {
            $this->json_error('Homework not found.', 404);
        }
        if (($existing['schoolId'] ?? $existing['schoolCode'] ?? '') !== $this->school_name) {
            // BUG-014 — emit security telemetry on cross-tenant access attempt.
            if (isset($this->sec_telem) && $this->sec_telem->isReady()) {
                $this->sec_telem->emit('CROSS_TENANT_PROBE', 'warning', [
                    'endpoint'         => 'Homework::close_homework',
                    'collection'       => 'homework',
                    'attempted_doc_id' => $hwId,
                    'attempted_school' => (string) ($existing['schoolId'] ?? $existing['schoolCode'] ?? ''),
                    'actor_school'     => (string) $this->school_name,
                ]);
            }
            // BUG-015 — return 404 (same as truly-not-found) to close the
            // existence oracle. The BUG-014 telemetry emit above already
            // captured the cross-tenant attempt in security_events; the
            // response itself is now indistinguishable from "doc absent".
            $this->json_error('Homework not found.', 404);
        }

        // BUG-008 — capture prior status for audit enrichment + idempotent
        // short-circuit. Pattern mirrors Finding #21 update_homework audit
        // enrichment (line ~1242) where dueDate old=... new=... is appended
        // to the audit description. Idempotent path skips the no-op write
        // AND the no-op audit entry so a re-clicked Close doesn't pollute
        // the audit trail with indistinguishable "Closed homework" lines
        // (closes BUG-008's audit-log integrity defect AND partially
        // mitigates BUG-009's TOCTOU symptom by collapsing redundant
        // writes — the full TOCTOU window remains BUG-009's scope).
        $prevStatus = (string) ($existing['status'] ?? 'active');
        if (strtolower($prevStatus) === 'closed') {
            $this->json_success(['message' => 'Homework already closed.']);
            return;
        }

        // Update in Firestore directly
        $this->firebase->firestoreUpdate('homework', $hwId, ['status' => 'closed']);

        log_audit('Homework', 'homework_close', $hwId, "Closed homework | status old={$prevStatus} new=closed");

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
        $result = [];

        // S1 — scope reads to the current admin session so homework from a
        // prior session doesn't leak into analytics/list after a rollover.
        // Same source the writer uses for the `session` field in
        // create_homework ($this->session_year). Blank-guard: when no
        // current session is known, skip the filter rather than hiding
        // everything (legacy docs may predate the session field entirely).
        $session = $this->session_year;
        $filters = [['schoolId', '=', $school]];
        if (!empty($session)) {
            $filters[] = ['session', '=', $session];
        }

        // Finding #10 2026-05-14 — cursor pagination replaces prior hard-cap
        // of 500. Prior behavior silently truncated analytics for any school
        // with 501+ homework items (every mature school after a few months),
        // misleading every analytics endpoint (overview, list, class-summary,
        // teacher-activity, subject-breakdown, overdue-report, trends).
        // Pattern mirrors the production-proven delete_homework cascade
        // pagination (this file lines 1257-1303 / Finding #4 deployment).
        //
        // Ordering: cursor uses '__name__' ASC for stable pagination, then
        // we restore the existing createdAt DESC ordering callers expect via
        // an in-memory sort (analytics endpoints already route createdAt
        // through _normalizeTimestamp at lines 588, 704 — same helper used
        // for the sort comparator).
        //
        // Safety: 250 iterations × 499/chunk = ~125k homework hard ceiling;
        // beyond that, a warning is logged and partial result is returned
        // (still better than silent truncation at 500).
        $chunkLimit = self::FETCH_CHUNK;
        $maxIters   = self::FETCH_MAX_ITERS;
        $lastDocId  = '';

        for ($iter = 0; $iter < $maxIters; $iter++) {
            try {
                $docs = $this->firebase->firestoreQuery(
                    'homework',
                    $filters,
                    '__name__',
                    'ASC',
                    $chunkLimit,
                    $lastDocId !== '' ? $lastDocId : null
                );
            } catch (\Exception $e) {
                log_message('error',
                    'Homework::_fetch_all_homework chunk query failed at iter '
                    . $iter . ' (returned ' . count($result) . ' docs so far): '
                    . $e->getMessage());
                break;
            }

            if (empty($docs)) break;

            $newLast = '';
            foreach ($docs as $doc) {
                $hw = $doc['data'];
                $hw['_class']   = $hw['className'] ?? '';
                $hw['_section'] = $hw['section'] ?? '';
                $hw['_id']      = $doc['id'];
                $hw['_source']  = 'firestore';
                $result[] = $hw;
                $newLast = $doc['id'];
            }

            // Cursor-stall guard — mirrors delete_homework cascade defensive check.
            if ($newLast === $lastDocId) {
                log_message('warning',
                    'Homework::_fetch_all_homework pagination cursor stalled at '
                    . $newLast . ' (returned ' . count($result) . ' docs).');
                break;
            }
            $lastDocId = $newLast;

            if (count($docs) < $chunkLimit) break;
        }

        if ($iter >= $maxIters) {
            log_message('warning',
                'Homework::_fetch_all_homework iteration cap reached ('
                . count($result) . ' docs accumulated); some homework may be missing.');
        }

        // Restore createdAt DESC ordering that callers expect (matches prior
        // single-query behavior). _normalizeTimestamp handles numeric/string/
        // Firestore-Timestamp-map representations uniformly so mixed-type
        // createdAt fields sort consistently.
        //
        // BUG-010 — Schwartzian transform: precompute the normalized
        // timestamp per item once (O(n)), then sort by the precomputed
        // integer (O(n log n) comparisons, each O(1)). Eliminates the
        // prior in-comparator _normalizeTimestamp() calls which made
        // strtotime the dominant cost (~2N log N strtotime invocations)
        // for mature schools (5000+ docs). The added `_ts` field follows
        // the existing underscore-prefix convention (_class, _section,
        // _id, _source) and is ignored by callers which destructure
        // specific fields.
        foreach ($result as &$item) {
            $item['_ts'] = $this->_normalizeTimestamp($item['createdAt'] ?? 0);
        }
        unset($item);
        usort($result, function ($a, $b) {
            return $b['_ts'] <=> $a['_ts'];
        });

        return $result;
    }

    /**
     * Merge a homework's submissions + teacherMarks + full class roster into a
     * single ordered list where every rostered student appears exactly once,
     * with precedence submission > teacherMark > pending. Shared by
     * get_homework_detail and get_submissions so the two endpoints can never
     * drift (they previously carried ~90% duplicated merge logic).
     *
     * Row shape (submission tracker — $includeRosterMeta = true):
     *   studentId, studentName, rollNo, status, text, remarks, submittedAt,
     *   score, reviewedBy, source
     * Detail modal ($includeRosterMeta = false) drops the tracker-only
     * rollNo + source fields, preserving the historical detail row shape.
     *
     * @param array  $hw                Homework doc (needs className/section).
     * @param string $hwId              Homework doc ID.
     * @param bool   $includeRosterMeta Include rollNo + source fields.
     * @return array Ordered list of merged rows.
     */
    private function _merge_submissions(array $hw, string $hwId, bool $includeRosterMeta): array
    {
        // Read submissions from Firestore. schoolId predicate is
        // defense-in-depth (mirrors delete/update/teacherMarks pattern);
        // composite (schoolId, homeworkId) index serves it.
        $subDocs = $this->firebase->firestoreQuery(
            'submissions',
            [
                ['schoolId',   '=', $this->school_name],
                ['homeworkId', '=', $hwId],
            ],
            null, 'ASC', self::QUERY_LIMIT
        );

        // Index submissions by studentId.
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
                'source'      => 'submission',
            ];
        }

        // Index teacherMarks by studentId (evaluations recorded for students
        // who never submitted a doc). Legacy marks without a status field
        // default to 'reviewed' (the previously hardcoded value) for
        // back-compat.
        $tmMap = [];
        try {
            $tmDocs = $this->firebase->firestoreQuery(
                'teacherMarks',
                [
                    ['schoolId',   '=', $this->school_name],
                    ['homeworkId', '=', $hwId],
                ],
                null, 'ASC', self::QUERY_LIMIT
            );
            foreach ($tmDocs as $tm) {
                $td   = $tm['data'];
                $tsid = $td['studentId'] ?? '';
                if ($tsid === '') continue;
                $tmMap[$tsid] = [
                    'studentId'   => $tsid,
                    'studentName' => '',
                    'rollNo'      => '-',
                    'status'      => $td['status'] ?? 'reviewed',
                    'text'        => '',
                    'remarks'     => $td['remark'] ?? '',
                    'submittedAt' => '',
                    'score'       => $td['score'] ?? -1,
                    'reviewedBy'  => $td['teacherId'] ?? '',
                    'source'      => 'teacherMark',
                ];
            }
        } catch (\Exception $e) {
            log_message('error', 'Homework::_merge_submissions — teacherMarks query failed: ' . $e->getMessage());
        }

        // Fetch full class roster and merge (submission > mark > pending).
        $cls = $hw['className'] ?? '';
        $sec = $hw['section'] ?? '';
        $result = [];

        if ($cls && $sec) {
            $sectionKey = "{$cls}/{$sec}";
            try {
                $studentDocs = $this->firebase->firestoreQuery(
                    'students',
                    [
                        ['schoolId',   '=', $this->school_name],
                        ['sectionKey', '=', $sectionKey],
                    ],
                    null, 'ASC', self::QUERY_LIMIT
                );
                foreach ($studentDocs as $doc) {
                    $sd  = $doc['data'];
                    // Submission stores raw studentId (e.g. "STU0001"); match
                    // by the studentId field inside the doc, not the doc ID.
                    $sid = $sd['studentId'] ?? $sd['userId'] ?? $doc['id'];
                    if (isset($subMap[$sid])) {
                        // Student has a submission doc — use it (the canonical
                        // record; teacher reviews update this, not teacherMarks).
                        $entry = $subMap[$sid];
                        $entry['studentName'] = $sd['name'] ?? $sd['Name'] ?? $entry['studentName'];
                        $entry['rollNo']      = $sd['rollNo'] ?? $sd['RollNo'] ?? '-';
                        $result[] = $entry;
                        unset($subMap[$sid]);
                        unset($tmMap[$sid]);  // submission supersedes any stray mark
                    } elseif (isset($tmMap[$sid])) {
                        // Teacher recorded a mark without a submission.
                        $entry = $tmMap[$sid];
                        $entry['studentName'] = $sd['name'] ?? $sd['Name'] ?? $sid;
                        $entry['rollNo']      = $sd['rollNo'] ?? $sd['RollNo'] ?? '-';
                        $result[] = $entry;
                        unset($tmMap[$sid]);
                    } else {
                        // No submission, no mark — pending.
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
                            'source'      => 'roster',
                        ];
                    }
                }
            } catch (\Exception $e) {
                log_message('error', 'Homework::_merge_submissions — students roster query failed: ' . $e->getMessage());
            }
        }

        // Append any submissions / marks not matched to the roster (edge case:
        // student left the section after submitting / being marked).
        foreach ($subMap as $entry) {
            $result[] = $entry;
        }
        foreach ($tmMap as $entry) {
            $result[] = $entry;
        }

        // No roster found — fall back to submission docs only.
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
                    'source'      => 'submission',
                ];
            }
        }

        // Detail modal shape omits the tracker-only rollNo/source fields —
        // strip them so the historical get_homework_detail row shape is
        // preserved exactly.
        if (!$includeRosterMeta) {
            foreach ($result as &$row) {
                unset($row['rollNo'], $row['source']);
            }
            unset($row);
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

        // Denormalization Integrity Phase 1 observability (Findings
        // #2/#20/#22/#37 unified workstream — added 2026-05-15).
        // Compares the denormalized cached counters against authoritative
        // Firestore count() aggregation values. PURELY OBSERVATIONAL —
        // never affects the returned rate, never modifies cached fields,
        // never alters endpoint responses. Sampled + per-request cached
        // to keep Firestore read amplification operationally acceptable.
        $this->_observeRateDivergence($hw, $submissionCount, $totalStudents);

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

        // Finding #10 2026-05-14 — cursor pagination replaces prior hard-cap
        // of 500. Prior behavior under-reported submission rate for any
        // homework with 501+ submissions when totalStudents was missing
        // from the parent homework doc. Same chunked __name__ ASC cursor
        // pattern as _fetch_all_homework + delete_homework cascade. Counts
        // (total + submitted) accumulate incrementally per chunk; rate is
        // computed once at the end.
        $chunkLimit = self::FETCH_CHUNK;
        $maxIters   = self::FETCH_MAX_ITERS;
        $lastSubId  = '';
        $total      = 0;
        $submitted  = 0;

        try {
            for ($iter = 0; $iter < $maxIters; $iter++) {
                $subDocs = $this->firebase->firestoreQuery(
                    'submissions',
                    [
                        ['schoolId',   '=', $this->school_name],
                        ['homeworkId', '=', $hwId],
                    ],
                    '__name__',
                    'ASC',
                    $chunkLimit,
                    $lastSubId !== '' ? $lastSubId : null
                );

                if (empty($subDocs)) break;

                $newLast = '';
                foreach ($subDocs as $sub) {
                    $d = $sub['data'];
                    $status = strtolower($d['status'] ?? '');
                    if (in_array($status, ['submitted', 'complete', 'done', 'checked', 'reviewed'], true)) {
                        $submitted++;
                    }
                    $total++;
                    $newLast = $sub['id'] ?? '';
                }

                if ($newLast === '' || $newLast === $lastSubId) {
                    log_message('warning',
                        'Homework::_calc_submission_rate cursor stalled for hwId=' . $hwId
                        . ' (counted ' . $total . ' submissions).');
                    break;
                }
                $lastSubId = $newLast;

                if (count($subDocs) < $chunkLimit) break;
            }

            if ($iter >= $maxIters) {
                log_message('warning',
                    'Homework::_calc_submission_rate iteration cap reached for hwId=' . $hwId
                    . ' (counted ' . $total . '); rate may be under-reported.');
            }

            if ($total === 0) {
                $this->_submissionCache[$hwId] = null;
                return null;
            }

            $rate = round(($submitted / $total) * 100, 1);
            $this->_submissionCache[$hwId] = $rate;
            return $rate;
        } catch (\Exception $e) {
            log_message('error',
                'Homework::_calc_submission_rate query failed for hwId=' . $hwId
                . ' (counted ' . $total . ' before failure): ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Denormalization Integrity Phase 1 — observability instrumentation
     * (Findings #2 / #20 / #22 / #37 unified workstream).
     *
     * Compares the denormalized `submissionCount` and `totalStudents`
     * carried on each homework doc against authoritative Firestore
     * count() aggregations. Logs divergence at structured 'warning'
     * severity so the ops team can quantify drift across the deployed
     * population. PURELY OBSERVATIONAL — does not affect the returned
     * rate, does not mutate cached fields, does not change any
     * endpoint response.
     *
     * Sampling + rate-limiting:
     *   - 100% observation when cachedTotalStudents <= 0 (the
     *     suspicious case that triggers _calc_submission_rate fallback)
     *   - 5% random sample for healthy-looking calls
     *   - Max 10 divergence-log emissions per request (prevents log
     *     spam during heavy analytics dashboards)
     *
     * Per-request caching:
     *   - Authoritative submission count cached per hwId
     *   - Authoritative roster count cached per sectionKey
     *   - Multiple analytics endpoints in the same request share the
     *     cache; ~50× amortization on dashboards that touch the same
     *     homework/section repeatedly.
     *
     * Failure handling:
     *   - count() failures return -1 sentinel; logged as 'n/a' in the
     *     divergence record; production behavior is preserved completely.
     */
    private function _observeRateDivergence(array $hw, int $cachedSubmissionCount, int $cachedTotalStudents): void
    {
        // Rate-limit per request to bound log volume.
        if (!isset($this->_divergenceLogCount)) {
            $this->_divergenceLogCount = 0;
        }
        if ($this->_divergenceLogCount >= 10) return;

        // Sampling decision: always observe the suspicious fallback case
        // (cachedTotalStudents <= 0); 5% random sample for everything else.
        $suspicious = ($cachedTotalStudents <= 0);
        $sampled    = (mt_rand(1, 100) <= 5);
        if (!$suspicious && !$sampled) return;

        $hwId = $hw['_id'] ?? '';
        if ($hwId === '') return;

        // Per-request authoritative-count caches.
        if (!isset($this->_authSubCache))    $this->_authSubCache    = [];
        if (!isset($this->_authRosterCache)) $this->_authRosterCache = [];

        $cls = $hw['className'] ?? '';
        $sec = $hw['section']   ?? '';
        $sectionKey = ($cls !== '' && $sec !== '') ? "{$cls}/{$sec}" : '';

        // Bound the expensive count() aggregations per request. The 10-emission
        // cap at the top of this method only gates *logging* — without the
        // guard below, every homework with totalStudents<=0 (the 100%-observed
        // "suspicious" case) fired up to two count() queries apiece, so a
        // dashboard with hundreds of such items issued hundreds of reads
        // regardless of the log cap. We cap the number of *distinct*
        // count()-issuing observations (cache misses) per request so the read
        // amplification is bounded. Cache hits are free and never gated.
        if (!isset($this->_divergenceObserveCount)) {
            $this->_divergenceObserveCount = 0;
        }
        $needsSubQuery    = !array_key_exists($hwId, $this->_authSubCache);
        $needsRosterQuery = ($sectionKey !== '' && !array_key_exists($sectionKey, $this->_authRosterCache));
        if (($needsSubQuery || $needsRosterQuery) && $this->_divergenceObserveCount >= 10) {
            return; // observation cap reached — skip the wasted reads
        }
        if ($needsSubQuery || $needsRosterQuery) {
            $this->_divergenceObserveCount++;
        }

        // Authoritative submission count (per hwId).
        if ($needsSubQuery) {
            $this->_authSubCache[$hwId] = $this->firebase->firestoreCount('submissions', [
                ['homeworkId', '=', $hwId],
            ]);
        }
        $authSub = $this->_authSubCache[$hwId];

        // Authoritative roster count (per sectionKey).
        $authRoster = -1;
        if ($sectionKey !== '') {
            if ($needsRosterQuery) {
                $this->_authRosterCache[$sectionKey] = $this->firebase->firestoreCount('students', [
                    ['schoolId',   '=', $this->school_name],
                    ['sectionKey', '=', $sectionKey],
                    ['status',     '=', 'Active'],
                ]);
            }
            $authRoster = $this->_authRosterCache[$sectionKey];
        }

        // Divergence percentages (relative to authoritative).
        $subDiv = ($authSub > 0)
            ? round(abs($cachedSubmissionCount - $authSub) / max(1, $authSub) * 100, 1)
            : -1.0;
        $rosterDiv = ($authRoster > 0)
            ? round(abs($cachedTotalStudents - $authRoster) / max(1, $authRoster) * 100, 1)
            : -1.0;

        // Log only on meaningful signal:
        //   - submission count diverges >= 5% from authoritative, OR
        //   - roster count diverges >= 5% from authoritative, OR
        //   - either count() call failed (-1 sentinel) and the cached
        //     value is non-zero (indicates we couldn't verify a populated
        //     cache — worth surfacing for ops review).
        $threshold = 5.0;
        $shouldLog = ($subDiv >= $threshold)
                  || ($rosterDiv >= $threshold)
                  || ($authSub === -1 && $cachedSubmissionCount > 0)
                  || ($authRoster === -1 && $sectionKey !== '' && $cachedTotalStudents > 0);

        if ($shouldLog) {
            // CI3 Log.php only recognises ERROR / DEBUG / INFO / ALL in
            // $_levels (system/core/Log.php:106). Passing 'warning' here
            // triggered an undefined-index PHP warning at Log.php:181 which
            // bled HTML into the JSON API response, breaking mobile parsing.
            // Use 'error' for high-signal observability — same convention
            // as ACC_IDEMP_CLAIMED / ACC_JOURNAL_COMMITTED in the accounting
            // pipeline.
            log_message('error', sprintf(
                'ACC_DENORM_DIVERGENCE schoolId=%s hwId=%s sec=%s'
                . ' subCached=%d subAuth=%s subDiv=%s'
                . ' rosterCached=%d rosterAuth=%s rosterDiv=%s',
                $this->school_name,
                $hwId,
                $sectionKey !== '' ? $sectionKey : 'n/a',
                $cachedSubmissionCount,
                ($authSub    === -1 ? 'n/a' : (string) $authSub),
                ($subDiv     === -1.0 ? 'n/a' : ($subDiv . '%')),
                $cachedTotalStudents,
                ($authRoster === -1 ? 'n/a' : (string) $authRoster),
                ($rosterDiv  === -1.0 ? 'n/a' : ($rosterDiv . '%'))
            ));
            $this->_divergenceLogCount++;
        }
    }

    /**
     * Normalise a dueDate input to ISO 8601 with timezone offset
     * (e.g. "2026-05-15T23:59:59+05:30"). Inputs already in ISO-with-TZ
     * form are returned unchanged. Date-only "YYYY-MM-DD" inputs are
     * pinned to end-of-day in IST so existing date-picker UIs keep
     * working without any client change. Anything unrecognised is
     * returned as-is so legacy/foreign formats don't crash the write.
     */
    private function _normalizeDueDate(string $input): string
    {
        $input = trim($input);
        if ($input === '') return '';
        // Already ISO 8601 with timezone offset or 'Z' — leave it.
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}([+-]\d{2}:?\d{2}|Z)$/', $input)) {
            return $input;
        }
        // Date-only — pin to end of school day in IST.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) {
            return $input . 'T23:59:59+05:30';
        }
        try {
            $dt = new \DateTime($input, new \DateTimeZone('Asia/Kolkata'));
            return $dt->format('Y-m-d\TH:i:sP');
        } catch (\Exception $e) {
            return $input;
        }
    }

    /**
     * Return the YYYY-MM-DD prefix of a stored dueDate value so it can be
     * lexicographically compared against `date('Y-m-d')` strings.
     *
     * dueDate is stored as ISO 8601 with timezone (e.g.
     * "2026-05-15T23:59:59+05:30") so a raw `$dd === $today` or
     * `$dd <= $weekEnd` would never match. This helper strips the time/TZ
     * suffix without converting through strtotime — the comparison stays
     * date-only and timezone-agnostic.
     */
    private function _dueDatePrefix($dd): string
    {
        if (!is_string($dd) || $dd === '') return '';
        return substr($dd, 0, 10);
    }
}
