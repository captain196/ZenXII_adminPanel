<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Advanced Attendance System Controller
 *
 * Features: Student & Staff attendance, Biometric/RFID/Face Recognition integration,
 * Late arrival tracking, Analytics, Mobile API compatibility.
 */
class Attendance extends MY_Controller
{
    /** Roles for attendance settings and device management */
    private const MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal'];

    /** Roles that may mark attendance */
    private const MARK_ROLES   = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator', 'Class Teacher', 'Teacher'];

    /** Roles that may view attendance data */
    private const VIEW_ROLES   = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator', 'HR Manager', 'Class Teacher', 'Teacher'];

    /** API key file cache TTL in seconds */
    private const API_KEY_CACHE_TTL = 300;

    /** Minimum face recognition confidence to accept a punch */
    private const FACE_CONFIDENCE_THRESHOLD = 0.75;

    /** Rate limit window in seconds (15 minutes) */
    private const RATE_LIMIT_WINDOW = 900;

    /** Maximum failed API key attempts per IP within the rate limit window */
    private const MAX_FAILED_ATTEMPTS = 20;

    /** Duplicate punch detection window in seconds (5 minutes) */
    private const DUPLICATE_WINDOW = 300;

    /** Idempotency store TTL in seconds (7 days) */
    private const IDEMPOTENCY_TTL = 604800;

    /** Internal API rate limit: max requests per user per minute */
    private const INTERNAL_RATE_LIMIT = 60;

    /** Read-through cache TTL (seconds) for attendanceSettings/{schoolId} */
    private const ATT_SETTINGS_CACHE_TTL = 300;

    /** Schema version stamped on the attendanceSettings document */
    private const ATT_SETTINGS_SCHEMA_VERSION = 1;

    /** Schema version stamped on attendanceDevices / attendanceDeviceKeys */
    private const ATT_DEVICE_SCHEMA_VERSION = 1;

    /** Schema version stamped on attendanceProcessedEvents / attendanceAuditLog */
    private const ATT_PUNCH_SCHEMA_VERSION = 1;

    /** Audit-log retention for Firestore TTL (expiresAt) — 24 months in seconds */
    private const AUDIT_TTL_SECONDS = 63072000;

    /** Routes that skip session auth (use API-key auth instead) */
    protected $public_routes = [
        'admin_login/index',
        'admin_login/check_credentials',
        'admin_login/get_server_date',
        'attendance/api_punch',
    ];

    /** Valid attendance mark characters */
    private $valid_marks = ['P', 'A', 'L', 'H', 'T', 'V'];

    public function __construct()
    {
        parent::__construct();
        // Skip RBAC for API routes (auth handled separately)
        $method = strtolower($this->router->fetch_method());
        if ($method !== 'api_punch') {
            require_permission('Attendance');
        }

        // Firestore_service ($this->fs) already loaded and initialized by MY_Controller
    }

    /**
     * Module-level authorization override — "module grant = all sections".
     *
     * The base MY_Controller::_require_role() gates each action by matching the
     * admin's role NAME against a hardcoded allowlist (MANAGE/MARK/VIEW_ROLES).
     * That name-matching is incompatible with the custom admin roles created in
     * Admin Users: any school-defined role (e.g. "Academic Coordinator") that is
     * granted the Attendance module via RBAC still gets bounced from sections
     * like Settings / devices / policy, because its label isn't in the hardcoded
     * list. The result was a coordinator who could open Attendance but not its
     * Settings page.
     *
     * We make the RBAC "Attendance" permission the single source of truth for the
     * whole module: anyone who holds it (enforced in the constructor via
     * require_permission('Attendance')) may use every Attendance section. Any
     * caller that somehow reaches here without the permission still falls through
     * to the base role check. The per-method self::MANAGE_ROLES/MARK_ROLES/
     * VIEW_ROLES arguments are retained but no longer restrict RBAC holders.
     */
    protected function _require_role(array $allowed, string $action = ''): void
    {
        if (has_permission('Attendance')) {
            return;
        }
        parent::_require_role($allowed, $action);
    }

    /** Month names → numbers */
    private $month_map = [
        'January' => 1, 'February' => 2, 'March' => 3, 'April' => 4,
        'May' => 5, 'June' => 6, 'July' => 7, 'August' => 8,
        'September' => 9, 'October' => 10, 'November' => 11, 'December' => 12,
    ];

    /** Indian academic year month order */
    private $academic_months = [
        'April','May','June','July','August','September',
        'October','November','December','January','February','March'
    ];

    /* ================================================================
       GROUP A: PAGE LOADS
       ================================================================ */

    /**
     * Dashboard — today's summary cards, recent punches
     */
    public function index()
    {
        $this->_require_role(self::VIEW_ROLES);
        $data = [];
        $this->load->view('include/header', $data);
        $this->load->view('attendance/index', $data);
        $this->load->view('include/footer');
    }

    /**
     * Phase 2 Control Panel — daily summary + lock + corrections.
     * View-only renderer; all data is fetched client-side via the
     * existing Phase 1/2 JSON endpoints.
     */
    public function control()
    {
        $this->_require_role(self::VIEW_ROLES, 'attendance/control');
        $data['Classes']      = $this->_build_class_list();
        $data['session_year'] = $this->session_year;
        $this->load->view('include/header', $data);
        $this->load->view('attendance/control', $data);
        $this->load->view('include/footer');
    }

    /**
     * Dashboard stats — today's actual attendance counts (students + staff).
     * Reads today's mark (single character) from each student/staff attendance string.
     */
    public function dashboard_stats()
    {
        $this->_require_role(self::VIEW_ROLES, 'dashboard_stats');

        // Phase 8b/10: process pending teacher requests before
        // computing stats. Best-effort — don't let a failure break
        // the dashboard.
        try { $this->_process_pending_push_requests(); } catch (\Exception $e) {
            log_message('error', 'Attendance::dashboard_stats _process_pending_push_requests failed: ' . $e->getMessage());
        }
        try { $this->_process_approved_leaves(); } catch (\Exception $e) {
            log_message('error', 'Attendance::dashboard_stats _process_approved_leaves failed: ' . $e->getMessage());
        }

        $school  = $this->school_name;
        $session = $this->session_year;
        $today   = (int) date('j');           // day of month (1-31)
        $month   = date('F');                 // "March"
        $year    = (int) date('Y');           // 2026
        $attKey  = "{$month} {$year}";

        // ── Student stats — Firestore FIRST ──
        $stuP = 0; $stuA = 0; $stuT = 0; $stuL = 0; $stuTotal = 0;
        $todayDate = date('Y-m-d');

        // (a) Try per-day attendance records
        try {
            $todayAttDocs = $this->fs->schoolWhere('attendance', [
                ['date', '==', $todayDate],
                ['type', '==', 'student'],
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Attendance::dashboard_stats todayAttDocs Firestore read failed: ' . $e->getMessage());
            $todayAttDocs = [];
        }
        foreach ($todayAttDocs as $doc) {
            $mark = strtoupper($doc['data']['status'] ?? 'V');
            $stuTotal++;
            if ($mark === 'P') $stuP++;
            elseif ($mark === 'A') $stuA++;
            elseif ($mark === 'T') $stuT++;
            elseif ($mark === 'L') $stuL++;
        }
        // (b) Fall back to attendanceSummary dayWise strings (still Firestore)
        if ($stuTotal === 0) {
            try {
                $attSummaryDocs = $this->fs->schoolWhere('attendanceSummary', [
                    ['month', '==', date('Y-m')],
                ]);
            } catch (\Exception $e) {
                log_message('error', 'Attendance::dashboard_stats attSummaryDocs Firestore read failed: ' . $e->getMessage());
                $attSummaryDocs = [];
            }
            foreach ($attSummaryDocs as $doc) {
                $d = $doc['data'];
                if (($d['type'] ?? 'student') !== 'student') continue;
                $dayWise = $d['dayWise'] ?? '';
                if (strlen($dayWise) < $today) { $stuTotal++; continue; }
                $stuTotal++;
                $mark = strtoupper($dayWise[$today - 1]);
                if ($mark === 'P') $stuP++;
                elseif ($mark === 'A') $stuA++;
                elseif ($mark === 'T') $stuT++;
                elseif ($mark === 'L') $stuL++;
            }
        }
        // (c) Zero-RTDB hard line: the legacy RTDB student-attendance fallback
        // (nested firebase->get("{secRoot}/Students") over every class/section)
        // has been REMOVED. Firestore (per-day `attendance` + `attendanceSummary`)
        // is the only source of truth; an empty Firestore result is authoritative.
        // This also removes the dashboard's main latency source (N sequential
        // RTDB reads on every load).

        // ── Staff stats — CANONICAL Firestore collections ──
        // Phase 8: repointed from the legacy `attendance`/`attendanceSummary`
        // (type=staff) pair — which NO staff writer targets — to the canonical
        // `staffAttendance` / `staffAttendanceSummary` collections written by
        // Staff_attendance_writer. The dashboard is now just another consumer
        // of the same canonical data fed by GPS, device punch, manual marking,
        // and leave approval (no separate attendance logic here).
        $staffP = 0; $staffA = 0; $staffT = 0; $staffTotal = 0;
        try {
            $staffAttDocs = $this->fs->schoolWhere('staffAttendance', [
                ['date', '==', $todayDate],
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Attendance::dashboard_stats staffAttendance Firestore read failed: ' . $e->getMessage());
            $staffAttDocs = [];
        }
        foreach ($staffAttDocs as $doc) {
            $mark = strtoupper($doc['data']['status'] ?? 'V');
            $staffTotal++;
            if ($mark === 'P') $staffP++;
            elseif ($mark === 'A') $staffA++;
            elseif ($mark === 'T') $staffT++;
        }
        // Fall back to monthly summary strings (still canonical Firestore)
        if ($staffTotal === 0) {
            try {
                $staffSummaryDocs = $this->fs->schoolWhere('staffAttendanceSummary', [
                    ['month', '==', date('Y-m')],
                ]);
            } catch (\Exception $e) {
                log_message('error', 'Attendance::dashboard_stats staffAttendanceSummary Firestore read failed: ' . $e->getMessage());
                $staffSummaryDocs = [];
            }
            foreach ($staffSummaryDocs as $doc) {
                $dayWise = $doc['data']['dayWise'] ?? '';
                if (strlen($dayWise) < $today) { $staffTotal++; continue; }
                $staffTotal++;
                $mark = strtoupper($dayWise[$today - 1]);
                if ($mark === 'P') $staffP++;
                elseif ($mark === 'A') $staffA++;
                elseif ($mark === 'T') $staffT++;
            }
        }
        // Firestore canonical (no RTDB fallback). When upstream Firestore returns
        // empty totals, that is the authoritative result — no roster fallback.

        // Count pending student leave applications.
        // [Fix #6] Bounded read (cap 500). This is a dashboard badge, not an audit
        // total: at extreme scale (>500 pending) it reports the cap rather than an
        // unbounded scan. Firestore lacks a cheap count without an aggregation query
        // here, so a bounded cap is the safe trade-off.
        $pendingLeaves = 0;
        try {
            $leaveDocs = $this->fs->schoolWhere('leaveApplications', [
                ['status',        '==', 'pending'],
                ['applicantType', '==', 'student'],
            ], null, 'ASC', 500);
            $pendingLeaves = count($leaveDocs);
        } catch (\Exception $e) {
            log_message('error', 'Attendance::dashboard_stats pendingLeaves count failed: ' . $e->getMessage());
        }

        return $this->json_success([
            'date'     => date('Y-m-d'),
            'month'    => $month,
            'year'     => $year,
            'day'      => $today,
            'students' => [
                'total'   => $stuTotal,
                'present' => $stuP,
                'absent'  => $stuA,
                'late'    => $stuT,
                'leave'   => $stuL,
            ],
            'staff'    => [
                'total'   => $staffTotal,
                'present' => $staffP,
                'absent'  => $staffA,
                'late'    => $staffT,
            ],
            'pendingLeaves' => $pendingLeaves,
        ]);
    }

    /**
     * Student attendance marking page
     */
    public function student_attendance()
    {
        $this->_require_role(self::VIEW_ROLES);

        $data['Classes']      = $this->_build_class_list();
        $data['months']       = $this->academic_months;
        $data['session_year'] = $this->session_year;

        $this->load->view('include/header', $data);
        $this->load->view('attendance/student', $data);
        $this->load->view('include/footer');
    }

    /**
     * Phase 8a diagnostic — send a test push to a raw FCM token.
     * Bypasses the entire device registry to test the FCM gateway
     * directly.
     *
     *   POST /attendance/test_push
     *   Params: fcm_token, title (optional), body (optional)
     */
    public function test_push()
    {
        $this->_require_role(self::MANAGE_ROLES, 'test_push');
        $fcmToken = trim((string) $this->input->post('fcm_token'));
        if ($fcmToken === '') {
            return $this->json_error('fcm_token is required.');
        }
        $title = trim((string) ($this->input->post('title') ?: 'Test Push'));
        $body  = trim((string) ($this->input->post('body')  ?: 'This is a test notification from the admin panel.'));

        $this->load->library('push_service');
        $sent = $this->push_service->sendToTokens([$fcmToken], [
            'title' => $title,
            'body'  => $body,
            'data'  => ['type' => 'test_push'],
        ]);

        return $this->json_success([
            'sent'    => $sent,
            'message' => $sent > 0
                ? "Push delivered to FCM gateway ({$sent} accepted). Check the device in ~5 seconds."
                : 'Push REJECTED by FCM gateway. Token may be invalid, expired, or from a different Firebase project.',
        ]);
    }

    /**
     * Staff attendance marking page
     */
    public function staff_attendance()
    {
        $this->_require_role(self::VIEW_ROLES);
        $data['months']       = $this->academic_months;
        $data['session_year'] = $this->session_year;

        $this->load->view('include/header', $data);
        $this->load->view('attendance/staff', $data);
        $this->load->view('include/footer');
    }

    /**
     * Settings page — thresholds, holidays, working days, devices
     */
    public function settings()
    {
        $this->_require_role(self::MANAGE_ROLES, 'att_settings');
        $this->load->view('include/header');
        $this->load->view('attendance/settings');
        $this->load->view('include/footer');
    }

    /**
     * Audit Log page — searchable trail of every attendance mark/edit and the
     * reason behind it. Data is fetched client-side from fetch_audit_logs.
     * This is the ONLY surface for S2 "edit with reason" saves; before it the
     * reason was written to attendanceAuditLog but had no viewer.
     */
    public function audit()
    {
        $this->_require_role(self::MANAGE_ROLES, 'attendance/audit');
        $this->load->view('include/header');
        $this->load->view('attendance/audit');
        $this->load->view('include/footer');
    }

    /**
     * Analytics dashboard
     */
    public function analytics()
    {
        $this->_require_role(self::VIEW_ROLES);
        $data['Classes'] = $this->_build_class_list();
        $data['months']  = $this->academic_months;

        $this->load->view('include/header', $data);
        $this->load->view('attendance/analytics', $data);
        $this->load->view('include/footer');
    }

    /**
     * Punch log viewer
     */
    public function punch_log()
    {
        $this->_require_role(self::VIEW_ROLES);
        $this->load->view('include/header');
        $this->load->view('attendance/punch_log');
        $this->load->view('include/footer');
    }

    /** Admin/HR page: review + approve/reject staff GPS-attendance regularizations. */
    public function staff_regularization()
    {
        $this->_require_role(self::VIEW_ROLES);
        $this->load->view('include/header');
        $this->load->view('attendance/staff_regularization');
        $this->load->view('include/footer');
    }

    /**
     * Health check — verifies Firebase connectivity, config presence, cache status
     */
    public function health_check()
    {
        $this->_require_role(self::MANAGE_ROLES, 'health_check');
        $checks = [];

        // 1. Firebase connectivity
        $start = microtime(true);
        $schoolDoc = $this->fs->get('schools', $this->school_id);
        $fbTime = round((microtime(true) - $start) * 1000);
        $checks['firebase'] = [
            'status'      => $schoolDoc ? 'ok' : 'error',
            'latency_ms'  => $fbTime,
            'school_name' => $schoolDoc['name'] ?? 'unreachable',
        ];

        // 2. Attendance config presence
        $config = $schoolDoc['attendanceConfig'] ?? null;
        $checks['config'] = [
            'status'               => is_array($config) ? 'ok' : 'missing',
            'late_threshold_student' => $config['late_threshold_student'] ?? 'not set',
            'late_threshold_staff'   => $config['late_threshold_staff'] ?? 'not set',
            'working_days'         => isset($config['working_days']) ? count($config['working_days']) . ' days' : 'not set',
            'biometric_enabled'    => $config['biometric_enabled'] ?? false,
            'rfid_enabled'         => $config['rfid_enabled'] ?? false,
            'face_recognition_enabled' => $config['face_recognition_enabled'] ?? false,
        ];

        // 3. Active session
        $session = $this->session_year;
        $checks['session'] = [
            'status'  => $session ? 'ok' : 'missing',
            'current' => $session,
        ];

        // 4. Class list
        $classList = $this->_build_class_list();
        $checks['classes'] = [
            'status' => !empty($classList) ? 'ok' : 'empty',
            'count'  => count($classList),
        ];

        // 5. Cache layer status
        $redis = $this->_get_redis();
        $attCacheDir = APPPATH . 'cache/attendance/';
        $checks['cache'] = [
            'backend'            => $redis ? 'redis' : 'file',
            'redis_available'    => $redis ? 'ok' : 'unavailable',
            'file_cache_dir'     => is_dir($attCacheDir) ? 'ok' : 'missing',
            'file_cache_writable' => is_dir($attCacheDir) && is_writable($attCacheDir) ? 'ok' : 'not writable',
        ];

        // 6. Devices
        $devices = $schoolDoc['devices'] ?? null;
        $deviceCount = is_array($devices) ? count($devices) : 0;
        $activeDevices = 0;
        if (is_array($devices)) {
            foreach ($devices as $d) {
                if (is_array($d) && ($d['status'] ?? '') === 'active') $activeDevices++;
            }
        }
        $checks['devices'] = [
            'total'  => $deviceCount,
            'active' => $activeDevices,
        ];

        // Overall status
        $overallOk = ($checks['firebase']['status'] === 'ok')
            && ($checks['config']['status'] === 'ok')
            && ($checks['session']['status'] === 'ok');

        return $this->json_success([
            'healthy' => $overallOk,
            'checks'  => $checks,
            'checked_at' => date('c'),
        ]);
    }

    /**
     * Cron-callable: Clean expired idempotency entries and old queue files.
     * Call via: GET /attendance/cleanup (MANAGE_ROLES only)
     */
    public function cleanup()
    {
        $this->_require_role(self::MANAGE_ROLES, 'cleanup');
        $school  = $this->school_name;
        $session = $this->session_year;
        $now     = time();
        $deleted = 0;

        // Clean expired idempotency entries — Phase 6D: Firestore
        // attendanceProcessedEvents (schoolId-scoped) instead of RTDB.
        try {
            $events = $this->fs->schoolList('attendanceProcessedEvents');
        } catch (\Exception $e) {
            $events = [];
        }
        if (is_array($events)) {
            foreach ($events as $data) {
                $data = $data['data'] ?? $data;
                if (!is_array($data)) continue;
                $expiresAt = (int) ($data['expiresAt'] ?? $data['expires_at'] ?? 0);
                if ($expiresAt > 0 && $expiresAt <= $now) {
                    $docId = ($data['schoolId'] ?? $this->school_id) . '_' . ($data['eventId'] ?? '');
                    try { $this->fs->remove('attendanceProcessedEvents', $docId); } catch (\Exception $e) {}
                    $deleted++;
                }
            }
        }

        // Clean stale file cache (older than 1 hour)
        $cacheDir = APPPATH . 'cache/attendance/';
        $staleFiles = 0;
        if (is_dir($cacheDir)) {
            foreach (glob($cacheDir . '*.json') as $f) {
                if ((time() - filemtime($f)) > 3600) {
                    @unlink($f);
                    $staleFiles++;
                }
            }
        }

        // Phase 6E — legacy audit JSONL→RTDB queue retired; nothing to flush.
        return $this->json_success([
            'expired_events_deleted' => $deleted,
            'stale_cache_cleaned'    => $staleFiles,
            'queue_flushed'          => 0,
        ]);
    }

    /**
     * Query audit logs with filters.
     * POST: year_month (YYYY-MM, required), action, user, class, target, page, limit
     */
    public function fetch_audit_logs()
    {
        $this->_require_role(self::MANAGE_ROLES, 'fetch_audit_logs');
        if (!$this->_check_rate_limit('fetch_audit_logs')) {
            return $this->json_error('Rate limit exceeded. Max ' . self::INTERNAL_RATE_LIMIT . ' requests/minute.', 429);
        }

        $yearMonth = trim((string) $this->input->post('year_month'));
        if (!$yearMonth || !preg_match('/^\d{4}-\d{2}$/', $yearMonth)) {
            $yearMonth = date('Y-m');
        }

        $filterAction = trim((string) $this->input->post('action'));
        $filterUser   = trim((string) $this->input->post('user'));
        $filterClass  = trim((string) $this->input->post('class'));
        $filterTarget = trim((string) $this->input->post('target'));
        // Free-text search box (matches student id/name, class, section, reason,
        // acting user, action) — resolved client-of-endpoint via the enrichment
        // + haystack match below.
        $filterSearch = strtolower(trim((string) $this->input->post('search')));

        // Resolve targetId/userId → display name once (students + staff maps) so
        // the audit view can search BY NAME and show who did what instead of bare
        // IDs. The endpoint used to return only raw IDs, so an admin couldn't tell
        // which student a mark/reason belonged to or which teacher entered it.
        $nameMaps = $this->_audit_name_maps();

        // Read exclusively from Firestore attendanceAuditLog (schoolId-scoped +
        // yearMonth==). Canonical schema only — no dual-field bridge. The POST
        // filter param names (user/class/target) map to canonical doc fields
        // userId/className/targetId. Response contract, pagination, and
        // epoch-desc sort preserved.
        try {
            $rawLogs = $this->fs->schoolWhere('attendanceAuditLog', [
                ['yearMonth', '==', $yearMonth],
            ]);
        } catch (\Exception $e) {
            log_message('error', 'fetch_audit_logs attendanceAuditLog read failed: ' . $e->getMessage());
            $rawLogs = [];
        }

        $logs = [];
        if (is_array($rawLogs)) {
            foreach ($rawLogs as $doc) {
                $logId = $doc['id'] ?? '';
                $entry = $doc['data'] ?? $doc;
                if (!is_array($entry)) continue;

                // Apply filters (canonical fields)
                if ($filterAction && ($entry['action'] ?? '') !== $filterAction) continue;
                if ($filterUser && (string) ($entry['userId'] ?? '') !== $filterUser) continue;
                if ($filterClass && (string) ($entry['className'] ?? '') !== $filterClass) continue;
                if ($filterTarget && (string) ($entry['targetId'] ?? '') !== $filterTarget) continue;

                if ($logId !== '') $entry['log_id'] = $logId;

                // Enrich with display names for the target (student/staff) and
                // the acting user, so the UI can show names and search by them.
                $tType = (string) ($entry['targetType'] ?? 'student');
                $tId   = (string) ($entry['targetId'] ?? '');
                $uId   = (string) ($entry['userId'] ?? '');
                $entry['targetName'] = ($tType === 'staff')
                    ? ($nameMaps['staff'][$tId] ?? '')
                    : ($nameMaps['students'][$tId] ?? ($nameMaps['staff'][$tId] ?? ''));
                $entry['userName'] = $nameMaps['staff'][$uId]
                    ?? ($nameMaps['students'][$uId] ?? '');

                // Free-text search across id / name / class / section / reason /
                // user / action / date (case-insensitive substring).
                if ($filterSearch !== '') {
                    $hay = strtolower(implode(' ', [
                        $tId,
                        (string) $entry['targetName'],
                        (string) ($entry['className'] ?? ''),
                        (string) ($entry['section'] ?? ''),
                        (string) ($entry['reason'] ?? ''),
                        $uId,
                        (string) $entry['userName'],
                        (string) ($entry['action'] ?? ''),
                        (string) ($entry['date'] ?? ''),
                    ]));
                    if (strpos($hay, $filterSearch) === false) continue;
                }

                $logs[] = $entry;
            }
        }

        // Sort by epoch descending (newest first)
        usort($logs, function ($a, $b) {
            return ($b['epoch'] ?? 0) - ($a['epoch'] ?? 0);
        });

        // Paginate
        $total = count($logs);
        $page  = max(1, (int) ($this->input->post('page') ?: 1));
        $limit = max(1, min(100, (int) ($this->input->post('limit') ?: 50)));
        $offset = ($page - 1) * $limit;
        $paged  = array_slice($logs, $offset, $limit);

        return $this->json_success([
            'logs'       => $paged,
            'year_month' => $yearMonth,
            'pagination' => [
                'page'        => $page,
                'limit'       => $limit,
                'total'       => $total,
                'total_pages' => (int) ceil($total / $limit),
            ],
        ]);
    }

    /**
     * Build one-shot id→name maps for students and staff (school-scoped), used
     * to enrich audit rows so the log shows/searches by name rather than raw
     * IDs. Tolerant of the mixed field casing across the two collections.
     * Best-effort: a read failure yields an empty map (rows fall back to IDs).
     *
     * @return array{students: array<string,string>, staff: array<string,string>}
     */
    private function _audit_name_maps(): array
    {
        $students = [];
        $staff    = [];

        try {
            foreach ($this->fs->schoolWhere('students', []) as $doc) {
                $d = is_array($doc) ? ($doc['data'] ?? $doc) : null;
                if (!is_array($d)) continue;
                $name = (string) ($d['Name'] ?? $d['name'] ?? '');
                if ($name === '') continue;
                foreach ([$d['User Id'] ?? null, $d['studentId'] ?? null, $d['userId'] ?? null] as $k) {
                    if (is_string($k) && $k !== '') $students[$k] = $name;
                }
            }
        } catch (\Exception $e) {
            log_message('error', '_audit_name_maps students read failed: ' . $e->getMessage());
        }

        try {
            foreach ($this->fs->schoolWhere('staff', []) as $doc) {
                $d = is_array($doc) ? ($doc['data'] ?? $doc) : null;
                if (!is_array($d)) continue;
                $name = (string) ($d['name'] ?? $d['Name'] ?? '');
                if ($name === '') continue;
                foreach ([$d['staffId'] ?? null, $d['User Id'] ?? null, $d['userId'] ?? null, $d['id'] ?? null] as $k) {
                    if (is_string($k) && $k !== '') $staff[$k] = $name;
                }
            }
        } catch (\Exception $e) {
            log_message('error', '_audit_name_maps staff read failed: ' . $e->getMessage());
        }

        return ['students' => $students, 'staff' => $staff];
    }

    /**
     * Debug endpoint — verify what the parent/teacher apps would read for
     * a given student + month. Accepts GET so it can be hit directly from
     * the browser address bar while debugging sync issues.
     *
     * URL: /attendance/debug_student_sync?student_id=STU0001&month=May&year=2026
     *
     * Returns the exact Firestore docId being looked up, whether the doc
     * exists, and (if it does) the full document the parent/teacher apps
     * would see. If `exists` is false, the admin save never landed for
     * this student — most likely the silent-skip bug (look at `lookup` to
     * see what schoolId and docId the request resolved to).
     */
    public function debug_student_sync()
    {
        $this->_require_role(self::VIEW_ROLES, 'debug_student_sync');

        $studentId = trim((string) ($this->input->get('student_id') ?: $this->input->post('student_id')));
        $month     = trim((string) ($this->input->get('month')      ?: $this->input->post('month')));
        $yearRaw   = trim((string) ($this->input->get('year')       ?: $this->input->post('year')));

        if (!$studentId || !$month) {
            return $this->json_error('student_id and month are required.');
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $studentId)) {
            return $this->json_error('Invalid student_id format.');
        }
        if (!isset($this->month_map[$month])) {
            return $this->json_error('Invalid month (use full name like "May").');
        }

        $monthNum = $this->month_map[$month];
        $year     = ((int) $yearRaw) ?: $this->_resolve_year($month);
        $monthKey = sprintf('%04d-%02d', $year, $monthNum);

        // Same docId the admin save uses and the parent/teacher apps read.
        $docId = $this->fs->docId2($studentId, $monthKey);

        $doc = $this->fs->get('attendanceSummary', $docId);

        // Also try to read the student's own profile so the admin can
        // spot mismatched className/section/status that would prevent
        // future saves from picking them up.
        $studentDocId = $this->fs->docId($studentId);
        $studentDoc   = $this->fs->get('students', $studentDocId);

        // Raw lateTimes type — empty map and empty array both decode to []
        // in PHP, so the `doc` view above can't tell them apart. We
        // re-query the doc via reflection-style access to the REST client
        // so we can read the raw protobuf type tag (mapValue vs arrayValue).
        $lateTimesRawType = 'unknown';
        try {
            $client = $this->fs->raw_client();   // see helper below
            if ($client !== null) {
                $rawDoc = $client->getRawDocument('attendanceSummary', $docId);
                $rawLT  = $rawDoc['fields']['lateTimes'] ?? null;
                if (is_array($rawLT)) {
                    if (isset($rawLT['mapValue']))      $lateTimesRawType = 'mapValue';
                    elseif (isset($rawLT['arrayValue'])) $lateTimesRawType = 'arrayValue';
                    elseif (isset($rawLT['nullValue']))  $lateTimesRawType = 'nullValue';
                    else $lateTimesRawType = 'other:' . implode(',', array_keys($rawLT));
                } elseif ($rawLT === null) {
                    $lateTimesRawType = 'missing';
                }
            }
        } catch (\Exception $e) {
            $lateTimesRawType = 'error:' . $e->getMessage();
        }

        return $this->json_success([
            'lookup' => [
                'schoolId'     => $this->school_id,
                'studentId'    => $studentId,
                'month'        => $month,
                'year'         => $year,
                'monthKey'     => $monthKey,
                'collection'   => 'attendanceSummary',
                'attDocId'     => $docId,
                'studentDocId' => $studentDocId,
            ],
            'attendance_summary' => [
                'exists' => $doc !== null,
                'doc'    => $doc,
                'lateTimes_raw_type' => $lateTimesRawType,
            ],
            'student_profile' => [
                'exists' => $studentDoc !== null,
                'doc'    => $studentDoc,
            ],
        ]);
    }

    /* ================================================================
       GROUP B: STUDENT ATTENDANCE AJAX
       ================================================================ */

    /**
     * Fetch attendance grid for a class/section/month
     * POST: class (e.g. "Class 9th"), section ("A"), month ("April")
     */
    public function fetch_student_attendance()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_student_att');
        $class   = trim((string) $this->input->post('class'));
        $section = trim((string) $this->input->post('section'));
        $month   = trim((string) $this->input->post('month'));

        if (!$class || !$section || !$month) {
            return $this->json_error('Class, section, and month are required.');
        }

        $class   = $this->safe_path_segment($class, 'class');
        $section = $this->safe_path_segment($section, 'section');

        // H-01 FIX: Teachers can only view attendance for their assigned classes
        if (!$this->_teacher_can_access($class, "Section {$section}")) {
            return $this->json_error('You are not assigned to this class/section.', 403);
        }

        if (!isset($this->month_map[$month])) {
            return $this->json_error('Invalid month.');
        }

        $school  = $this->school_name;
        $session = $this->session_year;
        $year    = $this->_resolve_year($month);
        $monthNum = $this->month_map[$month];
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);

        // ── ROSTER: Firestore-only (R5) ──
        // _get_section_students now goes through Roster_helper as
        // Strategy 0 (canonical), with the Status-relaxed and
        // attendanceSummary-derived strategies as deeper safety nets.
        // The RTDB roster fallback that used to live here was removed.
        $list = $this->_get_section_students($class, $section);
        $sectionRoot = $this->_resolve_section_root($class, $section);

        if (empty($list)) {
            return $this->json_success([
                'students'    => [],
                'daysInMonth' => $daysInMonth,
                'sundays'     => $this->_get_sundays($year, $monthNum),
                'holidays'    => $this->_get_holidays_for_month($month, $year),
                'month'       => $month,
                'year'        => $year,
            ]);
        }

        $attKey = "{$month} {$year}";
        $monthKey = date('Y-m', mktime(0, 0, 0, $monthNum, 1, $year));

        // ── READ: ONE Firestore query for the whole month's student summaries ──
        // Replaces the previous N+1 (one point-read per student, ~0.9s each →
        // ~35s for a 40-student class). attendanceSummary is keyed
        // {schoolId}_{studentId}_{YYYY-MM} — exactly ONE doc per student per
        // month, independent of section — so a month-wide query mapped by
        // studentId yields the IDENTICAL per-student result the point-reads did
        // (section-agnostic), collapsing N reads into 1. Firestore-only; the
        // canonical store; response shape unchanged.
        $byStudent = [];
        try {
            // Section-scoped read (audit finding H10). Previously this filtered
            // ONLY [month, type] and then discarded every doc not in the roster —
            // a school-wide scan (~10k reads to render one 40-student section at
            // scale). className/section are stamped by BOTH writers (bulk +
            // per-day), so equality-filtering them scopes the read to this one
            // section. Served by the composite index
            // [schoolId, className, section, month] (firestore.indexes.json).
            $sumDocs = $this->fs->schoolWhere('attendanceSummary', [
                ['className', '==', Firestore_service::classKey($class)],
                ['section',   '==', Firestore_service::sectionKey($section)],
                ['month',     '==', $monthKey],
            ]);
            foreach ($sumDocs as $entry) {
                $d = is_array($entry) ? ($entry['data'] ?? $entry) : null;
                if (!is_array($d)) continue;
                $sid = (string) ($d['studentId'] ?? '');
                if ($sid !== '') $byStudent[$sid] = $d;
            }
        } catch (\Exception $e) {
            log_message('error', 'Attendance::fetch_student_attendance summary query failed: ' . $e->getMessage());
            $byStudent = []; // empty ⇒ everyone vacant (same as a missing point-read)
        }

        $students = [];
        foreach ($list as $studentId => $studentName) {
            $summaryDoc = $byStudent[$studentId] ?? [];
            $attStr  = $summaryDoc['dayWise']   ?? '';
            $lateRaw = $summaryDoc['lateTimes'] ?? [];

            if (!is_array($lateRaw)) $lateRaw = [];
            $attStr = is_string($attStr) ? str_pad($attStr, $daysInMonth, 'V') : str_repeat('V', $daysInMonth);

            $students[] = [
                'id'         => $studentId,
                'name'       => is_string($studentName) ? $studentName : (string) $studentId,
                'attendance' => $attStr,
                'late'       => $this->_normalize_late_data($lateRaw),
            ];
        }

        usort($students, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        return $this->json_success([
            'students'    => $students,
            'daysInMonth' => $daysInMonth,
            'sundays'     => $this->_get_sundays($year, $monthNum),
            'holidays'    => $this->_get_holidays_for_month($month, $year),
            'month'       => $month,
            'year'        => $year,
        ]);
    }

    /**
     * Save full month attendance for multiple students
     * POST: class, section, month, attendance (JSON: {studentId: "PPAPLL...", ...}), late (JSON: {studentId: {day: time}})
     */
    public function save_student_attendance()
    {
        $this->_require_role(self::MARK_ROLES, 'save_student_att');
        $class   = trim((string) $this->input->post('class'));
        $section = trim((string) $this->input->post('section'));
        $month   = trim((string) $this->input->post('month'));
        $attData = $this->input->post('attendance');
        $lateData = $this->input->post('late');

        if (!$class || !$section || !$month || !$attData) {
            return $this->json_error('Missing required fields.');
        }

        $class   = $this->safe_path_segment($class, 'class');
        $section = $this->safe_path_segment($section, 'section');

        // H-01 FIX: Teachers can only mark attendance for their assigned classes
        if (!$this->_teacher_can_access($class, "Section {$section}")) {
            return $this->json_error('You are not assigned to this class/section.', 403);
        }

        if (!isset($this->month_map[$month])) {
            return $this->json_error('Invalid month.');
        }

        $school  = $this->school_name;
        $session = $this->session_year;
        $year    = $this->_resolve_year($month);
        $attKey  = "{$month} {$year}";
        $monthNum = $this->month_map[$month];
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);

        if (is_string($attData)) {
            $attData = json_decode($attData, true);
        }
        if (is_string($lateData)) {
            $lateData = json_decode($lateData, true);
        }

        if (!is_array($attData)) {
            return $this->json_error('Invalid attendance data.');
        }

        // Load holidays + governance config
        $this->load->helper('attendance');
        $nonWorking = $this->_resolve_non_working_days($monthNum, $year);
        // Defined BEFORE the governance block: the needs_approval branch below
        // reads the current summary via docId2($sid, $monthKey). Pre-fix this was
        // assigned only later (~line 894), so that branch built the wrong doc id
        // (null month) → $curStr all 'V' → unreliable diff + a PHP notice.
        $monthKey = date('Y-m', mktime(0, 0, 0, $monthNum, 1, $year));
        $rules = $this->_att_rules();
        $pastLimit = (int)($rules['allow_past_edit_days'] ?? 0);
        $requireApproval = !empty($rules['require_approval_for_backdated']);

        // ── DATE GOVERNANCE (bulk): validate the first non-V, non-H mark's date ──
        // For bulk saves, check if the string contains any marks for future or past days
        // Validate EVERY submitted student string (audit finding M5): the first
        // violation drives the response (needs_approval → diff all students
        // below; hard error → reject). Previously only the first student
        // (reset($attData)) was validated, so a later student's future / past
        // out-of-window day slipped through completely ungoverned.
        $govResult = ['ok' => true];
        foreach ($attData as $chkStr) {
            if (!is_string($chkStr)) continue;
            $r = att_validate_date_governance(
                $chkStr, $daysInMonth, $monthNum, $year, $pastLimit, $requireApproval
            );
            if (!$r['ok']) { $govResult = $r; break; }
        }
        if (true) {
            if (!$govResult['ok']) {
                if (!empty($govResult['needs_approval'])) {
                    // Compute diff: store only changed days per student
                    $diffData = [];
                    $auditBulk = [];
                    foreach ($attData as $sid => $newStr) {
                        $sid = trim((string)$sid);
                        if (!preg_match('/^[A-Za-z0-9_]+$/', $sid)) continue;
                        // Read current attendance from Firestore
                        $curSummary = $this->fs->get('attendanceSummary', $this->fs->docId2($sid, $monthKey));
                        $curStr = ($curSummary['dayWise'] ?? '');
                        $curStr = is_string($curStr) ? str_pad($curStr, $daysInMonth, 'V') : str_repeat('V', $daysInMonth);
                        $changes = [];
                        for ($d = 0; $d < $daysInMonth && $d < strlen($newStr); $d++) {
                            if ($newStr[$d] !== $curStr[$d]) {
                                $changes[$d + 1] = $newStr[$d]; // day => new mark
                                $auditBulk[$sid][$d + 1] = ['old' => $curStr[$d], 'new' => $newStr[$d]];
                            }
                        }
                        if (!empty($changes)) $diffData[$sid] = $changes;
                    }
                    if (empty($diffData)) {
                        return $this->json_success(['saved' => 0, 'message' => 'No changes detected.']);
                    }
                    // Backdated bulk marks past the free-edit window are no longer
                    // queued to the retired RTDB approval store. Direct the user to
                    // the Firestore correction workflow (Attendance → Corrections).
                    return $this->json_error(
                        'One or more dates are past the free-edit window. Please file '
                        . 'correction requests (Attendance → Corrections) for admin approval.', 422
                    );
                }
                return $this->json_error($govResult['error']);
            }
        }

        $saved = 0;
        // $monthKey already resolved above (before the governance block).
        $sectionRoot = $this->_resolve_section_root($class, $section);

        // Resolve a studentId → name map once so each Firestore write
        // can stamp `studentName`. This makes derived-roster reads
        // (Strategy 3 in `_get_section_students`) return real names
        // instead of falling back to the studentId.
        $nameMap = $this->_get_section_students($class, $section);

        // B1 — Cache Active-status per studentId to avoid N+1 Firestore
        // reads inside the bulk loop. We pre-fetch via _get_active_roster
        // (the same source the teacher /attendance/save endpoint uses)
        // rather than Roster_helper alone, because the `students`
        // collection contains a mix of legacy `Status` (PascalCase) and
        // new `status` (camelCase) field shapes plus a mix of case in
        // the value ("Active" / "active" / ""). Roster_helper's strict
        // `status == 'Active'` Firestore filter silently drops docs in
        // the legacy shape — that was the silent-skip bug that caused
        // students to disappear from parent/teacher views after admin
        // bulk saves.
        $activeRoster = $this->_get_active_roster($class, $section);
        $activeRosterIds = [];
        foreach ($activeRoster as $rid => $_unused) { $activeRosterIds[$rid] = true; }

        // Collect every student we *couldn't* save and why, so the
        // admin UI can surface them as a warning instead of the user
        // discovering hours later that parent/teacher views are empty.
        $skipped = [];   // [{studentId, name, reason}]
        $batch = [];      // docId => full attendanceSummary payload (for one :batchWrite)
        $batchMeta = [];  // docId => ['studentId'=>, 'name'=>]  (for saved/skipped mapping)

        foreach ($attData as $studentId => $attString) {
            $studentId = trim((string) $studentId);
            if (!preg_match('/^[A-Za-z0-9_]+$/', $studentId)) {
                $skipped[] = [
                    'studentId' => $studentId,
                    'name'      => $nameMap[$studentId] ?? $studentId,
                    'reason'    => 'invalid_id_format',
                ];
                continue;
            }

            // Attendance status gate. Reject marks for any student
            // whose Firestore doc is not Active (Inactive / TC / Deleted).
            if (!isset($activeRosterIds[$studentId])) {
                log_message('warning',
                    "save_student_attendance: skipped non-Active student {$studentId} "
                    . "in {$class}/{$section} — status gate"
                );
                $skipped[] = [
                    'studentId' => $studentId,
                    'name'      => $nameMap[$studentId] ?? $studentId,
                    'reason'    => 'not_in_active_roster',
                ];
                continue;
            }

            $cleanStr = $this->_sanitize_att_string((string) $attString, $daysInMonth);
            $cleanStr = enforce_holidays_on_string($cleanStr, $daysInMonth, $nonWorking);

            // Count statuses
            $present = $absent = $leave = $holiday = $tardy = 0;
            $working = 0;
            for ($i = 0; $i < strlen($cleanStr); $i++) {
                $ch = $cleanStr[$i];
                if ($ch === 'P') { $present++; $working++; }
                elseif ($ch === 'A') { $absent++; $working++; }
                elseif ($ch === 'L') { $leave++; $working++; }
                elseif ($ch === 'H') { $holiday++; }
                elseif ($ch === 'T') { $tardy++; $working++; }
            }
            $pct = $working > 0 ? round(($present + $tardy) / $working * 100, 1) : 0;

            // Build late metadata.
            //
            // CRITICAL: keys MUST be strings and the empty case MUST be a
            // stdClass — not an empty PHP array — otherwise Firestore stores
            // the field as `array_value` instead of `map_value`. The Android
            // SDKs in the parent + teacher apps declare this field as
            // `Map<String, Map<String, String>>`, and `toObject()` throws on
            // an array→map mismatch. Result: the whole doc fails to
            // deserialize and the parent app silently shows "no data" even
            // though the doc is present in Firestore. (Confirmed via the
            // debug_student_sync endpoint — STU0001 had `lateTimes: []` and
            // the parent UI was blank as a consequence.)
            $lateMap = new \stdClass();
            if (is_array($lateData) && isset($lateData[$studentId]) && is_array($lateData[$studentId])) {
                foreach ($lateData[$studentId] as $day => $time) {
                    $day = (int) $day;
                    if ($day < 1 || $day > $daysInMonth) continue;
                    $time = preg_replace('/[^0-9:]/', '', (string) $time);
                    if ($time) $lateMap->{(string) $day} = ['time' => $time];
                }
            }

            // ── ACCUMULATE for one non-atomic :batchWrite (canonical store) ──
            // Byte-identical payload to the previous per-student set(); the
            // batch is committed once after the loop and each doc's independent
            // status maps to saved / skipped[] exactly as the per-student path did.
            $studentName = $nameMap[$studentId] ?? $studentId;
            $summaryDocId = $this->fs->docId2($studentId, $monthKey);
            $batch[$summaryDocId] = [
                'schoolId'   => $this->school_id,
                'studentId'  => $studentId,
                'studentName'=> $studentName,
                'type'       => 'student',
                'className'  => Firestore_service::classKey($class),
                'section'    => Firestore_service::sectionKey($section),
                // Canonical combined key ("Class 9th/Section A"). The Teacher
                // dashboard's "Today's Attendance" card and the app query
                // attendanceSummary by sectionKey; the per-day writer already
                // stamps it, but this BULK writer was missing it → any class
                // saved via the admin grid showed every student as unmarked on
                // the Teacher dashboard (audit finding H6). Kept in sync here.
                'sectionKey' => Firestore_service::buildSectionKey($class, $section),
                'month'      => $monthKey,
                'monthLabel' => $attKey,
                'session'    => $session,
                'dayWise'    => $cleanStr,
                'present'    => $present,
                'absent'     => $absent,
                'leave'      => $leave,
                'holiday'    => $holiday,
                'tardy'      => $tardy,
                'percentage' => $pct,
                // Per-day arrival times for tardy marks: {day:int → {time:str}}
                'lateTimes'  => $lateMap,
                'updatedAt'  => date('c'),
                'updatedBy'  => $this->admin_id,
            ];
            $batchMeta[$summaryDocId] = ['studentId' => $studentId, 'name' => $studentName];
        }

        // ── ONE non-atomic Firestore :batchWrite (canonical store) ──
        // Replaces N sequential set() calls with a single round-trip while
        // preserving PER-STUDENT partial success: each doc's independent status
        // maps to saved / skipped[] just like the per-student path. merge=true
        // mirrors the previous set(..., true). Firestore-only; no RTDB. A write
        // failure still lands the student in `skipped` (no silent-skip) so the
        // admin UI surfaces it instead of parent/teacher views going empty.
        if (!empty($batch)) {
            $writeResults = $this->fs->batchWrite('attendanceSummary', $batch, true);
            foreach ($batchMeta as $docId => $meta) {
                if (!empty($writeResults[$docId])) {
                    $saved++;
                } else {
                    log_message('error',
                        "save_student_attendance: Firestore batch write FAILED for {$meta['studentId']} "
                        . "in {$class}/{$section} {$attKey} — see Firestore_service log"
                    );
                    $skipped[] = [
                        'studentId' => $meta['studentId'],
                        'name'      => $meta['name'],
                        'reason'    => 'firestore_write_failed',
                    ];
                }
            }
        }

        $this->_log_attendance_change('BULK_SAVE_STUDENT', [
            'targetType' => 'class', 'targetId' => "{$class}|{$section}",
            'className' => $class, 'section' => $section, 'month' => $attKey,
            'count' => $saved, 'skipped' => count($skipped),
        ]);

        // Fire communication events for newly absent/late students
        $this->_fire_student_att_events($class, $section, $attKey);

        return $this->json_success([
            'saved'   => $saved,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Quick-mark single student, single day
     * POST: class, section, month, student_id, day (1-31), mark (P/A/L/H/T)
     */
    public function mark_student_day()
    {
        $this->_require_role(self::MARK_ROLES, 'mark_student_day');
        $class      = $this->safe_path_segment(trim((string) $this->input->post('class')), 'class');
        $section    = $this->safe_path_segment(trim((string) $this->input->post('section')), 'section');
        $month      = trim((string) $this->input->post('month'));
        $studentId  = trim((string) $this->input->post('student_id'));
        $day        = (int) $this->input->post('day');
        $mark       = strtoupper(trim((string) $this->input->post('mark')));
        $lateTime   = trim((string) $this->input->post('late_time'));

        if (!$class || !$section || !$month || !$studentId || !$day || !$mark) {
            return $this->json_error('Missing required fields.');
        }
        // H-01 FIX: Teachers can only mark attendance for their assigned classes
        if (!$this->_teacher_can_access($class, "Section {$section}")) {
            return $this->json_error('You are not assigned to this class/section.', 403);
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $studentId)) {
            return $this->json_error('Invalid student ID.');
        }
        if (!in_array($mark, $this->valid_marks)) {
            return $this->json_error('Invalid attendance mark.');
        }
        if (!isset($this->month_map[$month])) {
            return $this->json_error('Invalid month.');
        }

        // B1 — Attendance status gate. Reject any single-day mark for a
        // non-Active student (Inactive / TC / Deleted). Pre-fix the only
        // gate was the class/section assignment check, which would still
        // accept marks for a withdrawn student left in the form by a
        // stale page.
        if (!$this->roster->is_active($studentId)) {
            return $this->json_error('Cannot mark attendance for an inactive student.', 400);
        }

        $school  = $this->school_name;
        $session = $this->session_year;
        $year    = $this->_resolve_year($month);
        $attKey  = "{$month} {$year}";
        $monthNum = $this->month_map[$month];
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);

        if ($day < 1 || $day > $daysInMonth) {
            return $this->json_error('Invalid day number.');
        }

        // Block marking on Sundays/holidays — must be 'H'  (HC-3: canonical source)
        if ($mark !== 'H' && isset($this->_resolve_non_working_days($monthNum, $year)[$day])) {
            return $this->json_error("Day {$day} is a holiday/Sunday. Cannot mark as {$mark}.");
        }

        // ── DATE GOVERNANCE: block future, route past to approval ──
        $govCheck = $this->_check_day_governance($day, $monthNum, $year, $mark, [
            'class' => $class, 'section' => $section, 'month' => $month,
            'student_id' => $studentId, 'day' => $day, 'mark' => $mark,
        ]);
        if ($govCheck !== null) {
            if (!empty($govCheck['needs_approval'])) {
                // Backdated single-day marks past the free-edit window are no longer
                // queued to the retired RTDB approval store. Direct the user to the
                // Firestore correction workflow (correction_submit → correction_decide).
                return $this->json_error(
                    'This date is past the free-edit window. Please file a correction '
                    . 'request (Attendance → Corrections) for admin approval.', 422
                );
            }
            return $this->json_error($govCheck['error'] ?? 'Date validation failed.');
        }

        // Concurrency lock — mutex KEY ONLY (no RTDB I/O).
        $sectionRoot = $this->_resolve_section_root($class, $section);
        $attPath = "{$sectionRoot}/Students/{$studentId}/Attendance/{$attKey}";

        if (!$this->_acquire_att_lock($attPath)) {
            return $this->json_error('Another attendance update is in progress. Try again.', 409);
        }

        // Previous mark from Firestore canonical (attendanceSummary.dayWise) —
        // replaces the retired RTDB month-string seed read (same pattern the
        // backdated-approval branch above already uses).
        $monthKeyIso = sprintf('%04d-%02d', $year, $monthNum);
        $curSum = null;
        try { $curSum = $this->fs->get('attendanceSummary', $this->fs->docId2($studentId, $monthKeyIso)); }
        catch (\Throwable $e) {}
        $curDayWise = ($curSum && is_string($curSum['dayWise'] ?? null)) ? $curSum['dayWise'] : '';
        $oldMark = (is_string($curDayWise) && isset($curDayWise[$day - 1])) ? $curDayWise[$day - 1] : 'V';

        // Firestore canonical write — per-day `attendance` doc + `attendanceSummary`
        // convergence (via _syncDailyToFirestore). RTDB dayWise mirror + Late node
        // REMOVED (the Late node had no Firestore-reading consumer and no UI writer).
        // Single-student name lookup — Firestore-only.
        $stuInfo = $this->roster->for_student($studentId);
        $stuName = is_array($stuInfo) ? (string) ($stuInfo['Name'] ?? '') : '';
        $fsOk = $this->_syncDailyToFirestore(
            $studentId, $mark, $class, $section, $day, $attKey,
            $stuName, $mark === 'T'
        );
        if (!$fsOk) {
            $this->_release_att_lock($attPath);
            return $this->json_error('Firestore write failed; attendance not saved. Please retry.');
        }
        $this->_release_att_lock($attPath);

        $this->_log_attendance_change('MARK_STUDENT_DAY', [
            'targetType' => 'student', 'targetId' => $studentId,
            'className' => $class, 'section' => $section,
            'day' => $day, 'month' => $attKey, 'oldValue' => $oldMark, 'newValue' => $mark,
        ]);

        // Canonical per-month attendanceSummary already converged above via
        // _syncDailyToFirestore → _applyDayToSummary. The legacy RTDB writer
        // update_student_att_summary() has been retired (Component 4).

        // Fire communication event for newly absent/late (only on transition)
        if ($oldMark !== $mark && ($mark === 'A' || $mark === 'T')) {
            $this->_fire_single_student_event($studentId, $class, $section, $mark, $day, $attKey);
        }

        return $this->json_success(['mark' => $mark, 'day' => $day]);
    }

    /**
     * Bulk-mark all students in a section for a specific day
     * POST: class, section, month, day, mark
     */
    public function bulk_mark_student()
    {
        $this->_require_role(self::MARK_ROLES, 'bulk_mark_student');
        $class   = $this->safe_path_segment(trim((string) $this->input->post('class')), 'class');
        $section = $this->safe_path_segment(trim((string) $this->input->post('section')), 'section');
        $month   = trim((string) $this->input->post('month'));
        $day     = (int) $this->input->post('day');
        $mark    = strtoupper(trim((string) $this->input->post('mark')));

        if (!$class || !$section || !$month || !$day || !$mark) {
            return $this->json_error('Missing required fields.');
        }
        // H-01 FIX: Teachers can only bulk-mark attendance for their assigned classes
        if (!$this->_teacher_can_access($class, "Section {$section}")) {
            return $this->json_error('You are not assigned to this class/section.', 403);
        }
        if (!in_array($mark, $this->valid_marks)) {
            return $this->json_error('Invalid mark.');
        }
        if (!isset($this->month_map[$month])) {
            return $this->json_error('Invalid month.');
        }

        $school  = $this->school_name;
        $session = $this->session_year;
        $year    = $this->_resolve_year($month);
        $attKey  = "{$month} {$year}";
        $monthNum = $this->month_map[$month];
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);

        if ($day < 1 || $day > $daysInMonth) {
            return $this->json_error('Invalid day.');
        }

        // Block marking on Sundays/holidays — must be 'H' (parity with the
        // single-day path; canonical non-working-day source). Pre-fix, bulk
        // mark had no such guard so a whole section could be written P/A onto
        // a holiday/Sunday, diverging the per-day `attendance` docs from the
        // summary (which later re-stamps 'H').
        if ($mark !== 'H' && isset($this->_resolve_non_working_days($monthNum, $year)[$day])) {
            return $this->json_error("Day {$day} is a holiday/Sunday. Cannot bulk-mark as {$mark}.");
        }

        // ── DATE GOVERNANCE: block future days; route past-window edits to the
        // correction workflow (parity with mark_student_day). Pre-fix, bulk
        // mark accepted any future day up to daysInMonth.
        $govCheck = $this->_check_day_governance($day, $monthNum, $year, $mark, [
            'class' => $class, 'section' => $section, 'month' => $month,
            'day' => $day, 'mark' => $mark,
        ]);
        if ($govCheck !== null) {
            if (!empty($govCheck['needs_approval'])) {
                return $this->json_error(
                    'This date is past the free-edit window. Please file correction '
                    . 'requests (Attendance → Corrections) for admin approval.', 422
                );
            }
            return $this->json_error($govCheck['error'] ?? 'Date validation failed.');
        }

        $sectionRoot = $this->_resolve_section_root($class, $section);
        // R5 — roster from Firestore. The per-student attendance writes
        // below at `{sectionRoot}/Students/{id}/Attendance/{key}` are
        // unchanged (RTDB attendance mirror is out of R5 scope).
        $list = $this->_get_section_students($class, $section);
        if (empty($list)) {
            return $this->json_error('No students found.');
        }

        // Phase 7e — Firestore-first bulk mark.
        $bulkMarks = [];
        foreach ($list as $studentId => $name) {
            if (!is_string($studentId) || trim($studentId) === '') continue;
            $bulkMarks[$studentId] = ['mark' => $mark, 'name' => is_string($name) ? $name : ''];
        }
        $fsOk = $this->_syncBulkDailyToFirestore($bulkMarks, $class, $section, $day, $attKey);
        if ($fsOk === false) {
            return $this->json_error('Firestore write failed; bulk attendance not saved. Please retry.');
        }

        // Student-attendance RTDB mirror REMOVED — Firestore is canonical
        // (written above via _syncBulkDailyToFirestore). Count students marked
        // for the response (unchanged behaviour).
        $count = 0;
        foreach ($list as $studentId => $name) {
            if (!is_string($studentId) || trim($studentId) === '') continue;
            $count++;
        }

        $this->_log_attendance_change('BULK_MARK_STUDENT', [
            'targetType' => 'class', 'targetId' => "{$class}|{$section}",
            'className' => $class, 'section' => $section, 'day' => $day,
            'month' => $attKey, 'mark' => $mark, 'count' => $count,
        ]);

        // Parent notification (P2 fix, 2026-07-07): bulk-marking a whole section
        // Absent/Tardy previously sent parents nothing (unlike save_student_attendance).
        // _fire_student_att_events self-guards to today's marks only and dedups
        // per student, so a backdated bulk mark won't spam and a re-run won't double-send.
        if ($mark === 'A' || $mark === 'T') {
            $this->_fire_student_att_events($class, $section, $attKey);
        }

        return $this->json_success(['marked' => $count]);
    }

    /**
     * Individual student attendance summary (full session)
     * POST: student_id, class, section
     */
    public function get_student_summary()
    {
        $this->_require_role(self::VIEW_ROLES, 'student_summary');
        $studentId = trim((string) $this->input->post('student_id'));
        $class     = $this->safe_path_segment(trim((string) $this->input->post('class')), 'class');
        $section   = $this->safe_path_segment(trim((string) $this->input->post('section')), 'section');

        if (!$studentId || !$class || !$section) {
            return $this->json_error('Missing required fields.');
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $studentId)) {
            return $this->json_error('Invalid student ID.');
        }

        $summary = [];
        $totals = ['P' => 0, 'A' => 0, 'L' => 0, 'H' => 0, 'T' => 0, 'V' => 0, 'total_days' => 0];

        // ── READ: Firestore FIRST (canonical) ──
        $fsDocs = [];
        try {
            $fsDocs = $this->fs->schoolWhere('attendanceSummary', [
                ['studentId', '==', $studentId],
                ['type', '==', 'student'],
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Attendance::get_student_summary fsDocs Firestore read failed: ' . $e->getMessage());
            $fsDocs = [];
        }

        if (!empty($fsDocs)) {
            foreach ($fsDocs as $entry) {
                $d = is_array($entry) ? ($entry['data'] ?? $entry) : null;
                if (!is_array($d)) continue;
                // Session scope (P2 fix): studentId persists across academic
                // years, so a returning student's prior-year summary docs also
                // match studentId==. Skip any doc that explicitly belongs to a
                // DIFFERENT session. Legacy docs missing the field fall through
                // unchanged, so this can never hide current-session data.
                $docSession = $d['session'] ?? null;
                if ($docSession !== null && (string) $docSession !== (string) $this->session_year) {
                    continue;
                }
                $attStr = $d['dayWise'] ?? '';
                if (!is_string($attStr) || $attStr === '') continue;
                $monthLabel = $d['monthLabel'] ?? ($d['month'] ?? '');
                $stats = $this->_compute_month_stats($attStr);
                $summary[$monthLabel] = $stats;
                foreach (['P', 'A', 'L', 'H', 'T', 'V'] as $ch) {
                    $totals[$ch] += $stats[$ch];
                }
                $totals['total_days'] += strlen($attStr);
            }
        }
        // RTDB fallback REMOVED — attendanceSummary (Firestore) is the sole source.

        $working = $totals['P'] + $totals['A'] + $totals['L'] + $totals['T'];
        $totals['attendance_pct'] = $working > 0
            ? round(($totals['P'] + $totals['T']) / $working * 100, 1)
            : 0;

        return $this->json_success([
            'months'  => $summary,
            'totals'  => $totals,
        ]);
    }

    /* ================================================================
       GROUP C: STAFF ATTENDANCE AJAX
       ================================================================ */

    /**
     * Fetch staff attendance for a month.
     *
     * POST: month
     *
     * R1.4 — Firestore-only. Delegates to _fetch_staff_attendance_fs.
     * MVT telemetry retained as observability.
     */
    public function fetch_staff_attendance()
    {
        $this->load->library('stream_b_telemetry');
        $this->stream_b_telemetry->begin('fetch_staff_attendance', (string) ($this->school_id ?? ''));
        $resp = $this->_fetch_staff_attendance_fs();
        $this->stream_b_telemetry->commit();
        return $resp;
    }

    /**
     * Phase IV Step IV.2 — NEW Firestore-only fetch_staff_attendance path.
     *
     * NOT YET WIRED. Step IV.3 will add the dispatcher; until then, this
     * method has zero callers and runs only when the verifier (Step IV.4)
     * exercises it explicitly. Production behavior is unchanged.
     *
     * Op profile per call (M staff, 30-day month):
     *   - 1 FS query: staff(schoolId, status='Active')              [same as legacy]
     *   - 1 FS query: staffAttendanceSummary(schoolId, month)       [same as legacy; F-SB-4]
     *   - 1 FS range query: staffAttendance(schoolId, date in [first..last])  [F-SB-3; REPLACES RTDB Late month read at legacy line 1650]
     *   - 0 RTDB ops anywhere
     *
     * Late-data semantic note (Phase IV trade-off):
     *   Legacy RTDB stored late as wall-clock arrival time "10:30".
     *   The Phase II canonical staffAttendance doc stores `lateMinutes`
     *   (integer; minutes late from start). This method formats lateMinutes
     *   as "H:MM" duration ("0:30" = 30 min late) when present. The UI
     *   string-renders whatever it receives — the response *shape* is
     *   preserved; the late VALUE semantic shifts from clock time to
     *   duration for tenants on the fs writer.
     *
     * Backward-compat: tenants whose data was written by bulk saves
     * (`_save_staff_attendance_fs`) have no per-day docs — `late` is
     * returned as empty `{}`. UI degrades to "—". This is acceptable
     * (per Phase IV design package §3.4); operator can rollback via flag.
     *
     * Failure modes (fail-loud; NO RTDB fallback):
     *   - Any Firestore query failure → 500 json_error; the response
     *     shape is preserved (empty staff list or empty late as appropriate)
     *     where the partial data is non-fatal; complete failure throws.
     */
    private function _fetch_staff_attendance_fs()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_staff_att');
        $month = trim((string) $this->input->post('month'));

        if (!$month || !isset($this->month_map[$month])) {
            return $this->json_error('Invalid month.');
        }

        $year         = $this->_resolve_year($month);
        $monthNum     = $this->month_map[$month];
        $daysInMonth  = cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);
        $attKey       = "{$month} {$year}";
        $monthKeyISO  = sprintf('%04d-%02d', $year, $monthNum);

        /* 1. Roster — Firestore canonical (same as legacy). */
        $allTeachers = [];
        try {
            $fsDocs = $this->fs->schoolWhere('staff', [['status', '==', 'Active']]);
            if (!empty($fsDocs)) {
                foreach ($fsDocs as $doc) {
                    $d   = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $sid = (string) ($d['staffId'] ?? $d['userId'] ?? '');
                    if ($sid !== '') {
                        $allTeachers[$sid] = [
                            'Name'        => $d['Name'] ?? $d['name'] ?? $sid,
                            'Department'  => $d['Department'] ?? $d['department'] ?? '',
                            'Designation' => $d['designation'] ?? $d['Position'] ?? $d['position'] ?? '',
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Attendance::_fetch_staff_attendance_fs allTeachers query failed: ' . $e->getMessage());
        }

        /* 2. Monthly summary dayWise strings — Firestore (F-SB-4; same as legacy). */
        $allStaffAtt = [];
        try {
            $fsDocs = $this->fs->schoolWhere('staffAttendanceSummary', [
                ['month', '==', $monthKeyISO],
            ]);
            if (empty($fsDocs)) {
                $fsDocs = $this->fs->schoolWhere('staffAttendanceSummary', [
                    ['monthLabel', '==', $attKey],
                ]);
            }
            foreach ($fsDocs as $entry) {
                $d = is_array($entry) ? ($entry['data'] ?? $entry) : null;
                if (!is_array($d)) continue;
                $sid = (string) ($d['staffId'] ?? '');
                $dw  = (string) ($d['dayWise']  ?? '');
                if ($sid !== '' && $dw !== '') {
                    $allStaffAtt[$sid] = $dw;
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Attendance::_fetch_staff_attendance_fs allStaffAtt read failed: ' . $e->getMessage());
            $allStaffAtt = [];
        }

        /* 3. Per-day late metadata via F-SB-3 range query.
         *    REPLACES the legacy RTDB Late-month read (legacy line 1650).
         *    Range query: staffAttendance(schoolId == X AND date in [first..last]). */
        $dateFrom     = sprintf('%04d-%02d-01', $year, $monthNum);
        $dateTo       = sprintf('%04d-%02d-%02d', $year, $monthNum, $daysInMonth);
        $allStaffLate = []; // [staffId => [day => "H:MM"]]
        try {
            $perDayRows = $this->firebase->firestoreQuery('staffAttendance', [
                ['schoolId', '==', $this->school_id],
                ['date',     '>=', $dateFrom],
                ['date',     '<=', $dateTo],
            ]);
            if (is_array($perDayRows)) {
                foreach ($perDayRows as $row) {
                    $d    = is_array($row['data'] ?? null) ? $row['data'] : [];
                    $sid  = (string) ($d['staffId']     ?? '');
                    $date = (string) ($d['date']        ?? '');
                    $mins = (int)    ($d['lateMinutes'] ?? 0);
                    if ($sid === '' || $date === '' || $mins <= 0) continue;
                    $day  = (int) substr($date, 8, 2);
                    if ($day < 1 || $day > $daysInMonth) continue;
                    // Format minutes as H:MM duration (Phase II→IV semantic; see method docblock).
                    $allStaffLate[$sid][$day] = sprintf('%d:%02d', intdiv($mins, 60), $mins % 60);
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Attendance::_fetch_staff_attendance_fs lateMinutes F-SB-3 range query failed: ' . $e->getMessage());
            // Per architectural lock: NO RTDB fallback.
            // Empty late = graceful degradation; UI shows "—" for late times.
            $allStaffLate = [];
        }

        /* 4. Assemble response — shape IDENTICAL to legacy. */
        $staffList = [];
        foreach ($allTeachers as $staffId => $profile) {
            if (!is_string($staffId) || trim($staffId) === '') continue;
            $name = is_array($profile) ? ($profile['Name'] ?? $staffId) : (string) $staffId;

            $attStr = isset($allStaffAtt[$staffId]) && is_string($allStaffAtt[$staffId])
                ? $allStaffAtt[$staffId] : '';
            $attStr = str_pad($attStr, $daysInMonth, 'V');

            $lateRaw = isset($allStaffLate[$staffId]) && is_array($allStaffLate[$staffId])
                ? $allStaffLate[$staffId] : [];

            $dept  = is_array($profile) ? ($profile['Department']  ?? '') : '';
            $desig = is_array($profile) ? ($profile['Designation'] ?? '') : '';
            $staffList[] = [
                'id'          => $staffId,
                'name'        => $name,
                'department'  => $dept,
                'designation' => $desig,
                'attendance'  => $attStr,
                'late'        => $this->_normalize_late_data($lateRaw),
            ];
        }
        usort($staffList, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        return $this->json_success([
            'staff'       => $staffList,
            'daysInMonth' => $daysInMonth,
            'sundays'     => $this->_get_sundays($year, $monthNum),
            'holidays'    => $this->_get_holidays_for_month($month, $year),
            'month'       => $month,
            'year'        => $year,
        ]);
    }

    /**
     * Save staff attendance for a month.
     *
     * POST: month, attendance (JSON: {staffId: "PPAP...", ...}), late (JSON)
     *
     * R1.3 — Firestore-only. Delegates to _save_staff_attendance_fs.
     * MVT telemetry retained as observability.
     */
    public function save_staff_attendance()
    {
        $this->load->library('stream_b_telemetry');
        $this->stream_b_telemetry->begin('save_staff_attendance', (string) ($this->school_id ?? ''));
        $resp = $this->_save_staff_attendance_fs();
        $this->stream_b_telemetry->commit();
        return $resp;
    }

    /**
     * Phase III Step III.2 — NEW Firestore-only save_staff_attendance path.
     *
     * Activated when stream_b_flags::stream_b_writer_fs_only=true OR the
     * caller tenant is in stream_b_flags::enabled_for_schools[].
     *
     * Op profile per call (M staff, cache hit, no CAS retry):
     *   - 0 ops: lock check (Lock_cache hit)
     *   - 1 op:  firestoreQuery F-SB-4 (batch-read summaries + __updateTime)
     *   - M ops: per-staff commitBatch with CAS-protected summary write
     *   - 0 RTDB ops anywhere
     *
     * Trade-off (Phase III scope): only writes summary docs (not per-day
     * staffAttendance docs). Per-day docs are written by mark_staff_day; this
     * bulk path is for whole-month overwrites. Per-day backfill is a Phase IV
     * follow-up if needed.
     *
     * Failure modes (fail-loud; NO RTDB fallback):
     *   - MonthLocked         → 400 with locked-month diagnostic
     *   - F-SB-4 query failure → 500 (caller retries)
     *   - Per-staff CAS exhausted → recorded in skipped[]; bulk continues
     */
    private function _save_staff_attendance_fs()
    {
        $this->_require_role(self::MARK_ROLES, 'save_staff_att');
        $month   = trim((string) $this->input->post('month'));
        $attData = $this->input->post('attendance');

        if (!$month || !$attData) {
            return $this->json_error('Missing required fields.');
        }
        if (!isset($this->month_map[$month])) {
            return $this->json_error('Invalid month.');
        }
        if (is_string($attData)) $attData = json_decode($attData, true);
        if (!is_array($attData)) return $this->json_error('Invalid data.');

        $year        = $this->_resolve_year($month);
        $monthNum    = $this->month_map[$month];
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);
        $monthKey    = sprintf('%04d-%02d', $year, $monthNum);
        $tele        = isset($this->stream_b_telemetry) ? $this->stream_b_telemetry : null;

        // 1. Lock check (cached). Replaces legacy RTDB lock-check helper.
        $this->load->library('lock_cache');
        $lock = $this->lock_cache->is_locked($this->school_id, $this->session_year, $monthKey);
        if (!empty($lock['is_locked'])) {
            if ($tele) $tele->update([
                'cas_final_outcome' => 'month_locked',
                'rtdb_writes_count' => 0,
                'http_status'       => 400,
            ]);
            return $this->json_error("Staff attendance for {$month} {$year} is locked. Unlock before editing.");
        }

        // 2. Batch-read existing summaries via F-SB-4 to capture __updateTime
        //    for CAS protection. Replaces the per-staff RTDB N+1 read at
        //    legacy line 1753 with a single Firestore query.
        $existingSummaries = []; // staffId => ['__updateTime' => ..., 'dayWise' => ...]
        try {
            $rows = $this->firebase->firestoreQuery('staffAttendanceSummary', [
                ['schoolId', '==', $this->school_id],
                ['month',    '==', $monthKey],
            ]);
            foreach ($rows as $row) {
                $data = is_array($row['data'] ?? null) ? $row['data'] : [];
                $sid  = (string) ($data['staffId'] ?? '');
                if ($sid !== '') {
                    $existingSummaries[$sid] = [
                        '__updateTime' => (string) ($data['__updateTime'] ?? ''),
                        'dayWise'      => (string) ($data['dayWise']      ?? ''),
                    ];
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'Attendance::_save_staff_attendance_fs F-SB-4 query failed: ' . $e->getMessage());
            if ($tele) $tele->update([
                'cas_final_outcome' => 'query_failed',
                'rtdb_writes_count' => 0,
                'http_status'       => 500,
            ]);
            return $this->json_error('Save failed; please retry.');
        }

        // 3. Per-staff sequential commitBatch with CAS retry budget.
        //    Phase V will replace this with commitBatchesParallel.
        $this->load->helper('attendance');
        $saved          = 0;
        $skipped        = [];
        $attemptsTotal  = 0;
        // Staff dayWise supports the full company-schedule status set that the
        // GPS punch writer (Staff_attendance_writer) produces. Pre-fix this map
        // and the sanitizer only knew P/A/L/H/T/V, so an admin grid save
        // rewrote M (half-day) / W (work-on-off) / O (weekly-off) → V and
        // dropped their counts — silently clobbering GPS-written payroll marks.
        // Field names + workingDays formula are kept IDENTICAL to the canonical
        // writer's STATUS_TO_FIELD / workingDays so both write paths agree.
        $staffStatus    = ['P','A','L','H','T','V','M','W','O'];
        $statusToField  = [
            'P'=>'present','A'=>'absent','L'=>'leave','H'=>'holiday','T'=>'tardy',
            'V'=>'void','M'=>'halfDay','W'=>'extraWorked','O'=>'weeklyOff',
        ];

        foreach ($attData as $staffId => $attString) {
            $staffId = trim((string) $staffId);
            if (!preg_match('/^[A-Za-z0-9_]+$/', $staffId)) {
                $skipped[] = ['staffId' => $staffId, 'name' => $staffId, 'reason' => 'invalid_id_format'];
                continue;
            }
            $cleanStr = $this->_sanitize_att_string($attString, $daysInMonth, $staffStatus);

            // Compute ABSOLUTE counts from cleanStr across the full 9-field set.
            // Writing every field (not just the 6 old ones) means the merge:true
            // summary write can never leave a stale halfDay/extraWorked/weeklyOff
            // count behind when dayWise legitimately loses those marks.
            $counts = [
                'present'=>0,'absent'=>0,'leave'=>0,'holiday'=>0,'tardy'=>0,
                'void'=>0,'halfDay'=>0,'extraWorked'=>0,'weeklyOff'=>0,
            ];
            for ($i = 0; $i < $daysInMonth; $i++) {
                $c = strtoupper($cleanStr[$i] ?? 'V');
                $f = $statusToField[$c] ?? 'void';
                $counts[$f]++;
            }
            // Parity with Staff_attendance_writer::workingDays (adds halfDay).
            $workingDays = (int) ($counts['present'] + $counts['leave'] + $counts['tardy'] + $counts['halfDay']);

            $summaryDocId = "{$this->school_id}_{$staffId}_{$monthKey}";
            $captured     = $existingSummaries[$staffId]['__updateTime'] ?? '';

            // CAS retry budget per-staff (3 retries)
            $committed = false;
            for ($attempt = 0; $attempt <= 3; $attempt++) {
                $attemptsTotal++;
                $precondition = ($captured !== '')
                    ? ['updateTime' => $captured]
                    : ['exists' => false];

                $payload = array_merge([
                    'schoolId'    => $this->school_id,
                    'session'     => $this->session_year,
                    'staffId'     => $staffId,
                    'month'       => $monthKey,
                    'year'        => $year,
                    'monthNumber' => $monthNum,
                    'dayWise'     => $cleanStr,
                    'totalDays'   => $daysInMonth,
                    '_updatedAt'  => date('c'),
                ], $counts);
                $payload['workingDays'] = $workingDays;

                $ops = [
                    ['op' => 'set', 'collection' => 'staffAttendanceSummary',
                     'docId' => $summaryDocId, 'data' => $payload, 'merge' => true,
                     'precondition' => $precondition],
                ];
                $ok = $this->firebase->firestoreCommitBatch($ops);
                if ($ok === true) {
                    $committed = true;
                    break;
                }
                // CAS conflict or transient — re-read summary for fresh __updateTime
                $fresh   = $this->firebase->firestoreGet('staffAttendanceSummary', $summaryDocId);
                $captured = is_array($fresh) ? (string) ($fresh['__updateTime'] ?? '') : '';
                usleep((50 * (1 << $attempt) + mt_rand(0, 50)) * 1000);
            }
            if ($committed) {
                $saved++;
            } else {
                $skipped[] = ['staffId' => $staffId, 'name' => $staffId, 'reason' => 'firestore_cas_exhausted'];
            }
        }

        if ($tele) $tele->update([
            'cas_attempts'      => $attemptsTotal,
            'cas_final_outcome' => empty($skipped) ? 'success' : (count($skipped) === count($attData) ? 'all_failed' : 'partial'),
            'cache_hit'         => (($lock['source'] ?? 'live') === 'cache'),
            'rtdb_writes_count' => 0,
        ]);

        return $this->json_success([
            'saved'   => $saved,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Quick-mark single staff member, single day.
     *
     * POST: month, staff_id, day, mark, late_time (optional)
     *
     * R1.2 — Firestore-only. Delegates to _mark_staff_day_fs.
     * MVT telemetry retained as observability.
     */
    public function mark_staff_day()
    {
        $this->load->library('stream_b_telemetry');
        $this->stream_b_telemetry->begin('mark_staff_day', (string) ($this->school_id ?? ''));
        $resp = $this->_mark_staff_day_fs();
        $this->stream_b_telemetry->commit();
        return $resp;
    }

    /**
     * Phase II Step II.4 — NEW Firestore-only W5 path.
     *
     * Activated when stream_b_flags::stream_b_writer_fs_only=true OR the
     * caller tenant appears in stream_b_flags::enabled_for_schools[].
     *
     * Op profile per call (cache hit, no CAS retry):
     *   - 1 Firestore read  (summary, with __updateTime captured)
     *   - 2 Firestore writes (staffAttendance set + summary set in single
     *                         atomic commitBatch with CAS precondition on summary)
     *   - 0 RTDB ops anywhere
     *
     * Failure modes (all fail-loud; no RTDB fallback):
     *   - MonthLockedException        → 400 with locked-month diagnostic
     *   - CASRetryExhaustedException  → 409 ("concurrency conflict — retry")
     *   - any other writer/Firestore  → 500 logged + generic error
     */
    private function _mark_staff_day_fs()
    {
        $this->_require_role(self::MARK_ROLES, 'mark_staff_day');
        $month    = trim((string) $this->input->post('month'));
        $staffId  = trim((string) $this->input->post('staff_id'));
        $day      = (int) $this->input->post('day');
        $mark     = strtoupper(trim((string) $this->input->post('mark')));
        $lateTime = trim((string) $this->input->post('late_time'));

        if (!$month || !$staffId || !$day || !$mark) {
            return $this->json_error('Missing required fields.');
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $staffId)) {
            return $this->json_error('Invalid staff ID.');
        }
        if (!in_array($mark, $this->valid_marks)) {
            return $this->json_error('Invalid mark.');
        }
        if (!isset($this->month_map[$month])) {
            return $this->json_error('Invalid month.');
        }
        $year        = $this->_resolve_year($month);
        $monthNum    = $this->month_map[$month];
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);
        if ($day < 1 || $day > $daysInMonth) {
            return $this->json_error('Invalid day.');
        }
        // Canonical ISO date for the writer: YYYY-MM-DD
        $dateISO = sprintf('%04d-%02d-%02d', $year, $monthNum, $day);

        // Parse late minutes (HH:MM offset from a configured baseline could be
        // captured here; for Phase II we just store 0 unless caller supplied an
        // explicit numeric value).
        $lateMinutes = 0;
        if ($mark === 'T' && $lateTime !== '' && ctype_digit($lateTime)) {
            $lateMinutes = (int) $lateTime;
        }

        $context = [
            'markedBy'    => (string) ($this->admin_id ?? 'unknown'),
            'source'      => 'manual',
            'lateMinutes' => $lateMinutes,
        ];

        $this->load->library('staff_attendance_writer');
        $tele = isset($this->stream_b_telemetry) ? $this->stream_b_telemetry : null;
        try {
            $this->staff_attendance_writer->init(
                $this->firebase, $this->school_id, $this->session_year
            );
            $result = $this->staff_attendance_writer->markSingleDay(
                $staffId, $dateISO, $mark, $context
            );
            if ($tele) $tele->update([
                'cas_attempts'      => (int) ($result['attempts'] ?? 1),
                'cas_final_outcome' => 'success',
                'cache_hit'         => (($result['cache_source'] ?? 'live') === 'cache'),
                'rtdb_writes_count' => 0,
            ]);
        } catch (MonthLockedException $e) {
            if ($tele) $tele->update([
                'cas_final_outcome' => 'month_locked',
                'rtdb_writes_count' => 0,
                'http_status'       => 400,
            ]);
            return $this->json_error("Staff attendance for {$month} {$year} is locked. Unlock before editing.");
        } catch (CASRetryExhaustedException $e) {
            log_message('warning', 'Attendance::_mark_staff_day_fs CAS exhausted: ' . $e->getMessage());
            if ($tele) $tele->update([
                'cas_attempts'      => 4, // initial + 3 retries
                'cas_final_outcome' => 'exhausted',
                'rtdb_writes_count' => 0,
                'http_status'       => 409,
            ]);
            return $this->json_error('Concurrency conflict — please retry the action.', 409);
        } catch (\Throwable $e) {
            log_message('error', 'Attendance::_mark_staff_day_fs failed: ' . $e->getMessage());
            if ($tele) $tele->update([
                'cas_final_outcome' => 'error',
                'rtdb_writes_count' => 0,
                'http_status'       => 500,
            ]);
            return $this->json_error('Save failed; please retry.');
        }

        return $this->json_success([
            'mark'             => $mark,
            'day'              => $day,
            'previous_status'  => $result['previous_status'] ?? '',
            'cas_attempts'     => $result['attempts'] ?? 1,
            'duration_ms'      => $result['duration_ms'] ?? 0,
        ]);
    }

    /**
     * Bulk-mark all staff for a day.
     *
     * R2 — Firestore-only via Staff_attendance_writer::bulkMarkDay.
     *
     * POST: month, day, mark
     */
    public function bulk_mark_staff()
    {
        $this->_require_role(self::MARK_ROLES, 'bulk_mark_staff');
        $month = trim((string) $this->input->post('month'));
        $day   = (int) $this->input->post('day');
        $mark  = strtoupper(trim((string) $this->input->post('mark')));

        if (!$month || !$day || !$mark) {
            return $this->json_error('Missing required fields.');
        }
        if (!in_array($mark, $this->valid_marks) || !isset($this->month_map[$month])) {
            return $this->json_error('Invalid input.');
        }

        $year     = $this->_resolve_year($month);
        $monthNum = $this->month_map[$month];
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);
        if ($day < 1 || $day > $daysInMonth) {
            return $this->json_error('Invalid day.');
        }
        $dateISO = sprintf('%04d-%02d-%02d', $year, $monthNum, $day);
        $attKey  = "{$month} {$year}";

        // Roster from Firestore canonical staff/*.
        try {
            $staffDocs = $this->fs->schoolWhere('staff', [['sessions', 'array-contains', $this->session_year]]);
        } catch (\Throwable $e) {
            log_message('error', 'Attendance::bulk_mark_staff roster query failed: ' . $e->getMessage());
            return $this->json_error('Failed to load staff roster.');
        }
        $statusByStaffId = [];
        if (is_array($staffDocs)) {
            foreach ($staffDocs as $entry) {
                $d = is_array($entry['data'] ?? null) ? $entry['data'] : (is_array($entry) ? $entry : []);
                $sid = (string) ($d['staffId'] ?? '');
                if ($sid !== '') $statusByStaffId[$sid] = $mark;
            }
        }
        if (empty($statusByStaffId)) {
            return $this->json_error('No staff found.');
        }

        // Delegate to Firestore-only writer (Lock_cache + F-SB-4 + per-staff CAS).
        $this->load->library('staff_attendance_writer');
        try {
            $this->staff_attendance_writer->init(
                $this->firebase, $this->school_id, $this->session_year
            );
            $result = $this->staff_attendance_writer->bulkMarkDay(
                $statusByStaffId, $dateISO, [
                    'markedBy' => (string) ($this->admin_id ?? 'unknown'),
                    'source'   => 'bulk_mark',
                ]
            );
        } catch (MonthLockedException $e) {
            return $this->json_error("Staff attendance for {$month} {$year} is locked. Unlock before editing.");
        } catch (\Throwable $e) {
            log_message('error', 'Attendance::bulk_mark_staff writer failed: ' . $e->getMessage());
            return $this->json_error('Bulk mark failed; please retry.');
        }

        $this->_log_attendance_change('BULK_MARK_STAFF', [
            'targetType' => 'staff',
            'day' => $day, 'month' => $attKey, 'mark' => $mark,
            'count' => (int) ($result['committed'] ?? 0),
        ]);

        return $this->json_success([
            'marked'     => (int) ($result['committed']  ?? 0),
            'failed_ids' => (array) ($result['failed_ids'] ?? []),
        ]);
    }

    /**
     * Auto-fill today's staff attendance.
     *
     * Logic:
     *   - Reads all teachers from session roster
     *   - For each teacher, checks today's mark in their attendance string
     *   - If today is unmarked (V = vacant), marks as P (Present)
     *   - If already marked (P/A/L/H/T), does NOT overwrite
     *
     * This is the "Mark All Present" shortcut — admin then only needs to
     * change exceptions (absent, leave, late) instead of marking everyone.
     *
     * POST: (no params — auto-detects today's month/day)
     */
    public function autofill_staff_today()
    {
        $this->_require_role(self::MARK_ROLES, 'autofill_staff');

        $now      = new DateTime();
        $year     = (int) $now->format('Y');
        $monthNum = (int) $now->format('n');
        $day      = (int) $now->format('j');
        $dateISO  = $now->format('Y-m-d');
        $attKey   = $now->format('F') . " {$year}";

        // Roster from Firestore canonical staff/*.
        try {
            $staffDocs = $this->fs->schoolWhere('staff', [['sessions', 'array-contains', $this->session_year]]);
        } catch (\Throwable $e) {
            log_message('error', 'Attendance::autofill_staff_today roster query failed: ' . $e->getMessage());
            return $this->json_error('Failed to load staff roster.');
        }
        $staffIds = [];
        if (is_array($staffDocs)) {
            foreach ($staffDocs as $entry) {
                $d = is_array($entry['data'] ?? null) ? $entry['data'] : (is_array($entry) ? $entry : []);
                $sid = (string) ($d['staffId'] ?? '');
                if ($sid !== '') $staffIds[] = $sid;
            }
        }
        if (empty($staffIds)) {
            return $this->json_success(['marked' => 0, 'message' => 'No staff found in session.']);
        }

        // Delegate to Firestore-only writer (Lock_cache + F-SB-4 + per-staff CAS).
        // bulkAutofillDay broadcasts 'P' to all staff. Idempotent: staff already
        // marked 'P' produce delta=0 (no count change). The legacy "skipped"
        // semantic is dropped (in dev environment per ZERO-RTDB policy).
        $this->load->library('staff_attendance_writer');
        try {
            $this->staff_attendance_writer->init(
                $this->firebase, $this->school_id, $this->session_year
            );
            $result = $this->staff_attendance_writer->bulkAutofillDay(
                $staffIds, $dateISO, 'P', [
                    'markedBy' => (string) ($this->admin_id ?? 'unknown'),
                ]
            );
        } catch (MonthLockedException $e) {
            return $this->json_error('Staff attendance for this month is locked.');
        } catch (\Throwable $e) {
            log_message('error', 'Attendance::autofill_staff_today writer failed: ' . $e->getMessage());
            return $this->json_error('Autofill failed; please retry.');
        }

        $this->_log_attendance_change('AUTOFILL_STAFF_TODAY', [
            'targetType' => 'staff',
            'day' => $day, 'month' => $attKey,
            'marked' => (int) ($result['committed'] ?? 0),
        ]);

        return $this->json_success([
            'marked'     => (int) ($result['committed']  ?? 0),
            'failed_ids' => (array) ($result['failed_ids'] ?? []),
            'date'       => $now->format('d M Y'),
        ]);
    }

    /* ================================================================
       GROUP D: SETTINGS AJAX
       ================================================================ */

    /**
     * Get attendance settings
     */
    public function get_settings()
    {
        $this->_require_role(self::MANAGE_ROLES, 'get_settings');

        // Firestore-canonical (Phase 6B): read via the shared settings helper
        // (read-through cache). Return only the general-settings keys so the
        // JSON response shape stays identical to the pre-migration contract.
        $settings = $this->_get_attendance_settings();
        $config = [
            'late_threshold_student'   => $settings['late_threshold_student'],
            'late_threshold_staff'     => $settings['late_threshold_staff'],
            'working_days'             => $settings['working_days'],
            'biometric_enabled'        => $settings['biometric_enabled'],
            'rfid_enabled'             => $settings['rfid_enabled'],
            'face_recognition_enabled' => $settings['face_recognition_enabled'],
        ];

        return $this->json_success(['config' => $config]);
    }

    /**
     * Save attendance settings
     * POST: JSON config fields
     */
    public function save_settings()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_settings');
        $allowed = [
            'late_threshold_student', 'late_threshold_staff',
            'working_days', 'biometric_enabled', 'rfid_enabled', 'face_recognition_enabled',
        ];

        $data = [];
        foreach ($allowed as $key) {
            $val = $this->input->post($key);
            if ($val !== null) {
                if (in_array($key, ['biometric_enabled', 'rfid_enabled', 'face_recognition_enabled'])) {
                    $data[$key] = filter_var($val, FILTER_VALIDATE_BOOLEAN);
                } elseif ($key === 'working_days' && is_string($val)) {
                    $data[$key] = json_decode($val, true) ?: [];
                } else {
                    $data[$key] = $val;
                }
            }
        }

        if (empty($data)) {
            return $this->json_error('No settings to save.');
        }

        // Firestore-canonical (Phase 6B): merge-write the general settings into
        // attendanceSettings/{schoolId}. merge=true preserves the co-located
        // `rules` sub-map and any prior fields. Stamp schema/audit metadata.
        $data['schemaVersion'] = self::ATT_SETTINGS_SCHEMA_VERSION;
        $data['schoolId']      = $this->school_id;
        $data['updatedAt']     = date('c');
        $data['updatedBy']     = $this->admin_id ?? 'system';

        $ok = $this->fs->set('attendanceSettings', $this->school_id, $data, true);
        if (!$ok) {
            return $this->json_error('Failed to save settings.');
        }

        // Invalidate the read-through cache so subsequent reads see the change.
        $this->_invalidate_attendance_settings_cache($this->school_id);

        return $this->json_success(['message' => 'Settings saved.']);
    }

    /**
     * GET — Return the school's GPS Attendance Policy (Firestore-only).
     *
     * Reads schools/{id}.attendancePolicy. Does NOT touch the legacy RTDB
     * Config/Attendance path. Part of the Attendance Policy Framework
     * (GPS method, Phase 4). Firestore is the sole source of truth.
     *
     * Firestore reads: 1 (schools/{id}). Firestore writes: 0. RTDB: none.
     */
    public function get_attendance_policy()
    {
        $this->_require_role(self::MANAGE_ROLES, 'get_attendance_policy');

        $schoolDoc = $this->fs->get('schools', $this->school_id);
        $policy = (is_array($schoolDoc) && is_array($schoolDoc['attendancePolicy'] ?? null))
            ? $schoolDoc['attendancePolicy'] : [];

        // Prefill hint: existing staff late threshold (read-only; not modified).
        $staffThreshold = '';
        if (is_array($schoolDoc) && is_array($schoolDoc['attendanceConfig'] ?? null)) {
            $staffThreshold = (string) ($schoolDoc['attendanceConfig']['late_threshold_staff'] ?? '');
        }

        return $this->json_success([
            'policy'                  => $policy,
            'default_staff_threshold' => $staffThreshold,
        ]);
    }

    /**
     * POST — Save the school's GPS Attendance Policy to Firestore
     * (schools/{id}.attendancePolicy). Single source of truth.
     *
     * This endpoint NEVER writes the legacy RTDB Config/Attendance path —
     * it is fully Firestore-native and writes a SEPARATE document field
     * (attendancePolicy), leaving attendanceConfig/attendanceRules intact.
     *
     * Body: policy (JSON string)
     * Firestore reads: 0. Firestore writes: 1 (schools/{id} merge). RTDB: none.
     */
    public function save_attendance_policy()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_attendance_policy');
        $this->load->helper('geofence');

        $raw = $this->input->post('policy');
        $in  = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        if (!is_array($in)) {
            return $this->json_error('Invalid policy payload.');
        }

        $gpsIn = is_array($in['gps'] ?? null) ? $in['gps'] : [];
        $geoIn = is_array($gpsIn['geofence'] ?? null) ? $gpsIn['geofence'] : [];
        $winIn = is_array($in['windows'] ?? null) ? $in['windows'] : [];
        $enabled = !empty($gpsIn['enabled']) || !empty($geoIn['active']);

        // ── Geofence validation (strict only when GPS is enabled) ──
        $centerLat = $geoIn['centerLat'] ?? null;
        $centerLng = $geoIn['centerLng'] ?? null;
        $radius    = (int) ($geoIn['radius'] ?? 0);
        if ($enabled) {
            if (!gf_valid_coord($centerLat, $centerLng)) {
                return $this->json_error('A valid campus latitude/longitude is required.');
            }
            // Hard cap tightened 2026-07-07: the old 5000 m ceiling let a fence
            // span a whole town (a live tenant sat at 5 km = effectively no fence,
            // enabling off-campus punches). A single school campus is comfortably
            // under 2 km; anything larger is a misconfiguration that defeats the
            // geofence. Existing over-cap policies must be corrected on next save.
            if ($radius < 25 || $radius > 2000) {
                return $this->json_error('Campus radius must be between 25 and 2000 metres. A larger radius defeats the geofence — use a value that tightly covers the campus.');
            }
        }

        $maxAcc = (int) ($gpsIn['maxAccuracyMeters'] ?? 100);
        if ($maxAcc < 10 || $maxAcc > 1000) {
            return $this->json_error('Max GPS accuracy must be between 10 and 1000 metres.');
        }
        $tol = (int) ($gpsIn['boundaryToleranceMeters'] ?? 0);
        if ($tol < 0 || $tol > 200) $tol = 0;
        // ── Work Schedule — the SINGLE source of shift timings + hours ──
        // Consolidated (2026-07): the old separate "attendance windows" are gone
        // from the UI. Late/on-time is derived from shiftStart + grace; full/half
        // from hours worked; there is no check-out time gating. A derived
        // `windows` block is still written for backward-compatible readers.
        $schedIn = is_array($in['schedule'] ?? null) ? $in['schedule'] : [];
        $sched = [];

        // Shift times (HH:MM). latestCheckIn is an OPTIONAL hard cutoff ('' = none).
        foreach (['shiftStart', 'shiftEnd', 'earlyOutBefore', 'latestCheckIn', 'earliestCheckIn'] as $k) {
            $v = (string) ($schedIn[$k] ?? '');
            if ($v !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) {
                return $this->json_error("Invalid time for {$k} (expected HH:MM).");
            }
            if ($v !== '') $sched[$k] = $v;
        }

        $grace = (int) ($schedIn['graceMinutes'] ?? 0);
        if ($grace < 0 || $grace > 120) $grace = 0;
        $sched['graceMinutes'] = $grace;

        // fullDayHours = 0 → keep the classic on-time/late-only model (no
        // hours-based half-day classification). Otherwise half ≤ full.
        $fullH = (float) ($schedIn['fullDayHours'] ?? 8);
        $halfH = (float) ($schedIn['halfDayHours'] ?? 4);
        if ($fullH < 0) $fullH = 0;
        if ($halfH < 0) $halfH = 0;
        if ($fullH > 24 || $halfH > 24 || ($fullH > 0 && $halfH > $fullH)) {
            return $this->json_error('Half-day hours must not exceed the full-day hours.');
        }
        $brk = (int) ($schedIn['breakMinutes'] ?? 0);
        if ($brk < 0 || $brk > 480) $brk = 0;
        $sched['fullDayHours'] = $fullH;
        $sched['halfDayHours'] = $halfH;
        $sched['breakMinutes'] = $brk;
        $mc = (string) ($schedIn['missedCheckout'] ?? 'regularize');
        $sched['missedCheckout'] = in_array($mc, ['regularize', 'half', 'absent', 'auto_close'], true) ? $mc : 'regularize';

        // Derived windows (back-compat + engine fallback): start = shiftStart,
        // grace carried, optional hard latest-check-in, NO check-out gating.
        $shiftStart = $sched['shiftStart'] ?? '09:00';
        $derivedWindows = [
            'earliestCheckIn' => $sched['earliestCheckIn'] ?? '00:00',
            'lateThreshold'   => $shiftStart,
            'gracePeriodMin'  => $grace,
            'latestCheckIn'   => $sched['latestCheckIn'] ?? '23:59',
            'checkoutStart'   => null,
            'checkoutLatest'  => null,
        ];

        // ── Weekly-offs + rest-day working + auto-absent ──
        $validDays  = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $weeklyOffs = array_values(array_intersect($validDays,
            is_array($in['weeklyOffs'] ?? null) ? $in['weeklyOffs'] : []));
        $allowWorkOnOff = !empty($in['allowWorkOnOff']);
        $autoAbsent     = array_key_exists('autoAbsent', $in) ? !empty($in['autoAbsent']) : true;

        // ── Build the canonical policy map (consumed by Attendance_policy) ──
        $policy = [
            'version'        => 1,
            'enabledMethods' => $enabled ? ['gps', 'manual'] : ['manual'],
            'gps' => [
                'geofence' => [
                    'active'    => (bool) $enabled,
                    'centerLat' => gf_valid_coord($centerLat, $centerLng) ? (float) $centerLat : 0.0,
                    'centerLng' => gf_valid_coord($centerLat, $centerLng) ? (float) $centerLng : 0.0,
                    'radius'    => $radius > 0 ? $radius : 200,
                ],
                'maxAccuracyMeters'       => $maxAcc,
                'allowMockLocation'       => !empty($gpsIn['allowMockLocation']),
                'boundaryToleranceMeters' => $tol,
            ],
            'shifts' => [
                'default' => [
                    'name'     => 'General',
                    'windows'  => $derivedWindows,
                    'schedule' => $sched,
                ],
            ],
            'weeklyOffs'     => $weeklyOffs,
            'allowWorkOnOff' => $allowWorkOnOff,
            'autoAbsent'     => $autoAbsent,
            'updatedAt' => date('c'),
            'updatedBy' => (string) ($this->admin_id ?? ''),
        ];

        try {
            $this->fs->update('schools', $this->school_id, ['attendancePolicy' => $policy]);
        } catch (\Throwable $e) {
            log_message('error', 'Attendance::save_attendance_policy failed: ' . $e->getMessage());
            return $this->json_error('Could not save the attendance policy. Please retry.', 500);
        }

        if (function_exists('log_audit')) {
            log_audit('Attendance', 'save_gps_policy', $this->school_id,
                'GPS attendance policy updated (gps=' . ($enabled ? 'on' : 'off') . ')');
        }

        return $this->json_success(['message' => 'GPS attendance policy saved.', 'policy' => $policy]);
    }

    /**
     * HC-4 (Option D): Holiday STATUS for the read-only Attendance "Holiday
     * Management" page. Holidays are authored EXCLUSIVELY in the Academic
     * Calendar (Calendar_service → calendarEvents); this endpoint only READS
     * the canonical set via Holiday_service. There is NO holiday writer here
     * (the legacy RTDB save_holidays writer was retired in HC-4).
     */
    public function get_holidays()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_holidays');

        $holidays    = [];
        $lastUpdated = null;
        try {
            $this->load->library('holiday_service');
            $this->holiday_service->init($this->fs, (string) $this->school_id, (string) $this->session_year);
            $holidays    = $this->holiday_service->all_holiday_dates(); // [dateISO => name]
            $lastUpdated = $this->holiday_service->last_updated_at();
        } catch (\Throwable $e) {
            log_message('error', 'Attendance::get_holidays HC-4 failed: ' . $e->getMessage());
        }

        $today    = date('Y-m-d');
        $upcoming = 0;
        foreach ($holidays as $d => $_n) { if ($d >= $today) $upcoming++; }

        return $this->json_success([
            'holidays'        => $holidays,            // date => name (read-only display)
            'canonicalSource' => 'Academic Calendar (calendarEvents)',
            'session'         => (string) $this->session_year,
            'total'           => count($holidays),
            'upcoming'        => $upcoming,
            'lastUpdated'     => $lastUpdated,          // ISO-8601 or null
            'editorUrl'       => base_url('academic') . '#calendar',
            'readOnly'        => true,
        ]);
    }

    /* ================================================================
       GROUP E: DEVICE MANAGEMENT AJAX
       ================================================================ */

    /**
     * Fetch registered devices
     */
    public function fetch_devices()
    {
        $this->_require_role(self::MANAGE_ROLES, 'fetch_devices');

        $list = [];

        // Phase 6C — Firestore-only read (RTDB Config/Devices fallback removed).
        try {
            $docs = $this->fs->schoolList('attendanceDevices');
        } catch (\Exception $e) {
            log_message('error', 'Attendance::fetch_devices Firestore read failed: ' . $e->getMessage());
            $docs = [];
        }

        if (!empty($docs) && is_array($docs)) {
            foreach ($docs as $d) {
                $d = $d['data'] ?? $d;
                if (!is_array($d)) continue;
                $list[] = [
                    'id'        => $d['deviceId'] ?? $d['device_id'] ?? '',
                    'name'      => $d['name'] ?? '',
                    'type'      => $d['type'] ?? 'unknown',
                    'location'  => $d['location'] ?? '',
                    'status'    => $d['status'] ?? 'inactive',
                    'last_ping' => $d['lastPing'] ?? $d['last_ping'] ?? '',
                    'created_at' => $d['createdAt'] ?? $d['created_at'] ?? '',
                ];
            }
        }

        return $this->json_success(['devices' => $list]);
    }

    /**
     * Register a new device
     * POST: name, type (biometric|rfid|face_recognition), location
     */
    public function register_device()
    {
        $this->_require_role(self::MANAGE_ROLES, 'register_device');
        $name     = trim((string) $this->input->post('name'));
        $type     = trim((string) $this->input->post('type'));
        $location = trim((string) $this->input->post('location'));

        if (!$name || !$type) {
            return $this->json_error('Device name and type are required.');
        }
        if (!in_array($type, ['biometric', 'rfid', 'face_recognition'])) {
            return $this->json_error('Invalid device type.');
        }

        // Generate API key
        $rawKey  = bin2hex(random_bytes(32));
        $keyHash = hash('sha256', $rawKey);
        $deviceId = 'DEV_' . strtoupper(substr(md5(uniqid('', true)), 0, 8));

        // Phase 6C — Firestore-only write (RTDB Config/Devices + API_Keys mirrors removed).
        $fsDoc = [
            'schoolId'      => $this->school_id,
            'deviceId'      => $deviceId,
            'name'          => $name,
            'type'          => $type,
            'location'      => $location,
            'status'        => 'active',
            'apiKeyHash'    => $keyHash,
            'createdAt'     => date('c'),
            'lastPing'      => '',
            'schemaVersion' => self::ATT_DEVICE_SCHEMA_VERSION,
        ];
        try {
            $fsOk = (bool) $this->fs->set('attendanceDevices', $this->fs->docId($deviceId), $fsDoc, true);
        } catch (\Exception $e) {
            $fsOk = false;
        }
        if (!$fsOk) {
            return $this->json_error('Firestore write failed; device not registered. Please retry.');
        }

        // Firestore key→device index — canonical device-auth lookup.
        try {
            $this->fs->set('attendanceDeviceKeys', $keyHash, [
                'keyHash'       => $keyHash,
                'deviceId'      => $deviceId,
                'schoolId'      => $this->school_id,
                'schoolName'    => $this->school_name,
                'createdAt'     => date('c'),
                'schemaVersion' => self::ATT_DEVICE_SCHEMA_VERSION,
            ], true);
        } catch (\Exception $e) { /* best-effort */ }

        return $this->json_success([
            'device_id' => $deviceId,
            'api_key'   => $rawKey,
            'message'   => 'Device registered. Save the API key — it will not be shown again.',
        ]);
    }

    /**
     * Update device config
     * POST: device_id, name, location, status
     */
    public function update_device()
    {
        $this->_require_role(self::MANAGE_ROLES, 'update_device');
        $deviceId = trim((string) $this->input->post('device_id'));
        if (!$deviceId || !preg_match('/^[A-Za-z0-9_]+$/', $deviceId)) {
            return $this->json_error('Invalid device ID.');
        }

        $updates = [];
        foreach (['name', 'location', 'status'] as $field) {
            $val = $this->input->post($field);
            if ($val !== null) {
                $updates[$field] = trim((string) $val);
            }
        }
        if (isset($updates['status']) && !in_array($updates['status'], ['active', 'inactive'])) {
            return $this->json_error('Invalid status.');
        }

        if (empty($updates)) {
            return $this->json_error('Nothing to update.');
        }

        // Phase 6C — Firestore-only update (RTDB Config/Devices mirror removed).
        $fsUpdates = [];
        foreach ($updates as $k => $v) { $fsUpdates[$k] = $v; }
        $fsUpdates['schoolId'] = $this->school_id;
        $fsUpdates['deviceId'] = $deviceId;
        try {
            $fsOk = (bool) $this->fs->set('attendanceDevices', $this->fs->docId($deviceId), $fsUpdates, true);
        } catch (\Exception $e) {
            $fsOk = false;
        }
        if (!$fsOk) {
            return $this->json_error('Firestore update failed; please retry.');
        }

        return $this->json_success(['message' => 'Device updated.']);
    }

    /**
     * Delete a device
     * POST: device_id
     */
    public function delete_device()
    {
        $this->_require_role(self::MANAGE_ROLES, 'delete_device');
        $deviceId = trim((string) $this->input->post('device_id'));
        if (!$deviceId || !preg_match('/^[A-Za-z0-9_]+$/', $deviceId)) {
            return $this->json_error('Invalid device ID.');
        }

        // Phase 6C — Firestore-only. Resolve the key hash to purge its auth
        // index + cached auth entry (RTDB API_Keys mirrors removed).
        $hash = null;
        try {
            $fsDev = $this->fs->get('attendanceDevices', $this->fs->docId($deviceId));
            if (is_array($fsDev)) {
                $hash = $fsDev['apiKeyHash'] ?? $fsDev['api_key_hash'] ?? null;
            }
        } catch (\Exception $e) { $fsDev = null; }
        if ($hash) {
            try { $this->fs->remove('attendanceDeviceKeys', $hash); } catch (\Exception $e) {}
            // Instant revocation — bust the cached auth entry for this key.
            $this->_cache_delete("api_key_{$hash}");
        }

        // Firestore-only delete of the device record.
        try { $this->fs->remove('attendanceDevices', $this->fs->docId($deviceId)); } catch (\Exception $e) {}

        return $this->json_success(['message' => 'Device deleted.']);
    }

    /**
     * Regenerate API key for a device
     * POST: device_id
     */
    public function regenerate_key()
    {
        $this->_require_role(self::MANAGE_ROLES, 'regenerate_key');
        $deviceId = trim((string) $this->input->post('device_id'));
        if (!$deviceId || !preg_match('/^[A-Za-z0-9_]+$/', $deviceId)) {
            return $this->json_error('Invalid device ID.');
        }

        // Phase 6C — Firestore-only lookup (RTDB Config/Devices fallback removed).
        $device = null;
        try {
            $fsDev = $this->fs->get('attendanceDevices', $this->fs->docId($deviceId));
            if (is_array($fsDev)) {
                $device = [
                    'api_key_hash' => $fsDev['apiKeyHash'] ?? $fsDev['api_key_hash'] ?? '',
                ];
            }
        } catch (\Exception $e) { /* not found */ }

        if (!is_array($device)) {
            return $this->json_error('Device not found.');
        }

        // Purge the old key's auth index + cached auth entry (instant revocation).
        if (!empty($device['api_key_hash'])) {
            $oldHash = $device['api_key_hash'];
            try { $this->fs->remove('attendanceDeviceKeys', $oldHash); } catch (\Exception $e) {}
            $this->_cache_delete("api_key_{$oldHash}");
        }

        // Generate new key
        $rawKey  = bin2hex(random_bytes(32));
        $keyHash = hash('sha256', $rawKey);

        // Firestore-only apiKeyHash update.
        try {
            $fsOk = (bool) $this->fs->set('attendanceDevices', $this->fs->docId($deviceId), [
                'schoolId'   => $this->school_id,
                'deviceId'   => $deviceId,
                'apiKeyHash' => $keyHash,
            ], true);
        } catch (\Exception $e) { $fsOk = false; }
        if (!$fsOk) {
            return $this->json_error('Firestore update failed; please retry.');
        }

        // Refresh the Firestore key→device index for the new key.
        try {
            $this->fs->set('attendanceDeviceKeys', $keyHash, [
                'keyHash'       => $keyHash,
                'deviceId'      => $deviceId,
                'schoolId'      => $this->school_id,
                'schoolName'    => $this->school_name,
                'createdAt'     => date('c'),
                'schemaVersion' => self::ATT_DEVICE_SCHEMA_VERSION,
            ], true);
        } catch (\Exception $e) { /* best-effort */ }

        return $this->json_success([
            'api_key' => $rawKey,
            'message' => 'New API key generated. Save it — it will not be shown again.',
        ]);
    }

    /* ================================================================
       GROUP F: DEVICE API ENDPOINT (API-key auth, no session)
       ================================================================ */

    /**
     * Receive punch from biometric/RFID/face-recognition device
     * POST JSON: { person_id, person_type (student|staff), direction (in|out),
     *              punch_time (ISO8601), confidence (0-1), class, section }
     * Header: X-API-Key: <raw_key>
     */
    public function api_punch()
    {
        $auth = $this->_validate_api_key();
        if (!$auth) {
            return $this->json_error('Invalid API key.', 401);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            return $this->json_error('Invalid JSON body.');
        }

        $personId   = trim($input['person_id'] ?? '');
        $personType = trim($input['person_type'] ?? '');
        $direction  = trim($input['direction'] ?? 'in');
        $punchTime  = trim($input['punch_time'] ?? date('c'));
        $confidence = (float) ($input['confidence'] ?? 1.0);
        $class      = trim($input['class'] ?? '');
        $section    = trim($input['section'] ?? '');
        $eventId    = trim($input['event_id'] ?? '');

        // Sanitize class/section to prevent Firebase path injection (public endpoint)
        if ($class && !preg_match('/^[A-Za-z0-9 \'_\-]+$/', $class)) $class = '';
        if ($section && !preg_match('/^[A-Za-z0-9 \'_\-]+$/', $section)) $section = '';
        if ($direction && !in_array($direction, ['in', 'out'])) $direction = 'in';

        if (!$personId || !$personType) {
            return $this->json_error('person_id and person_type required.');
        }
        if (!in_array($personType, ['student', 'staff'])) {
            return $this->json_error('person_type must be student or staff.');
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $personId)) {
            return $this->json_error('Invalid person_id format.');
        }

        // ── C-05 FIX: Verify person_id belongs to the authenticated school ──
        $schoolName_pre = $auth['school_name'];
        if ($personType === 'student') {
            // Phase 6D: Firestore-only membership check. Reads the canonical
            // students/{schoolId}_{studentId} doc, replacing the legacy RTDB
            // Users/Parents/{parentDbKey}/{id}/Name lookup (parity-verified).
            $schoolIdForCheck = (string) ($auth['school_id'] ?? $this->school_id);
            $stuDoc = $this->fs->get('students', "{$schoolIdForCheck}_{$personId}");
            if (!is_array($stuDoc) || empty($stuDoc)) {
                return $this->json_error('Person ID does not belong to this school.', 403);
            }
        } elseif ($personType === 'staff') {
            // Firestore canonical: staff/{schoolId}_{staffId}.
            $schoolIdForCheck = (string) ($auth['school_id'] ?? '');
            if ($schoolIdForCheck === '') {
                try {
                    $hits = $this->firebase->firestoreQuery('schools',
                        [['schoolName', '==', $schoolName_pre]], null, 'ASC', 1);
                    if (is_array($hits) && !empty($hits)) {
                        $first = is_array($hits[0]['data'] ?? null) ? $hits[0]['data'] : $hits[0];
                        $schoolIdForCheck = (string) ($first['schoolId'] ?? $hits[0]['id'] ?? '');
                    }
                } catch (\Throwable $e) {
                    log_message('error', 'api_punch schoolId derivation failed: ' . $e->getMessage());
                }
            }
            if ($schoolIdForCheck === '') {
                return $this->json_error('Cannot resolve school identity for punch.', 500);
            }
            $staffDoc = $this->fs->getEntity('staff', "{$schoolIdForCheck}_{$personId}");
            if (empty($staffDoc) || !is_array($staffDoc)) {
                return $this->json_error('Staff ID does not belong to this school.', 403);
            }
        }

        // Reject low-confidence face recognition punches.
        // Phase 6C: device record read from Firestore attendanceDevices
        // (canonical) instead of RTDB Config/Devices — same source the Admin
        // device screen writes, preventing device split-brain. Tenant-scoped
        // docId built explicitly from the API-key school.
        $devDocId = ($auth['school_id'] ?? $this->school_id) . '_' . $auth['device_id'];
        $deviceInfo_pre = $this->fs->get('attendanceDevices', $devDocId);
        $devType = is_array($deviceInfo_pre) ? ($deviceInfo_pre['type'] ?? '') : '';
        if ($devType === 'face_recognition' && $confidence < self::FACE_CONFIDENCE_THRESHOLD) {
            return $this->json_error('Confidence too low for face recognition. Score: ' . $confidence, 422);
        }

        $schoolName = $auth['school_name'];
        $deviceId   = $auth['device_id'];

        // Determine session year from school config
        // SW3 (2026-05-26): cut over from RTDB Config/ActiveSession +
        // System/Schools/{name}/Sessions to Firestore schools/{id}.currentSession
        // + .sessions. Firestore is the sole canonical session authority;
        // RTDB session paths are retired. Preserves the prior shape of the
        // resolution chain (active marker → last-known session in list →
        // computed fallback) so device punch behavior stays unchanged.
        $activeSession = '';
        try {
            // SW3-FIX: schools collection is keyed by SCH_ id, not the school name.
            $schoolDoc = $this->firebase->firestoreGet('schools', $auth['school_id'] ?? $this->school_id);
            if (is_array($schoolDoc)
                && isset($schoolDoc['currentSession'])
                && is_string($schoolDoc['currentSession'])
                && $schoolDoc['currentSession'] !== '') {
                $activeSession = $schoolDoc['currentSession'];
            }
        } catch (\Throwable $e) {
            log_message('error',
                'Attendance::api_punch SW3 Firestore session lookup failed for school ['
                . $schoolName . ']: ' . $e->getMessage());
        }
        // SC-Step10/G2 (2026-06-06): FAIL-CLOSED. schools/{id}.currentSession is the SOLE
        // session authority — NO sessions[0] fallback, NO synthetic session generation.
        // If it is unavailable, reject the punch rather than recording it against an
        // inferred/fabricated session.
        if ($activeSession === '') {
            log_message('error',
                'SC10 fail-closed: api_punch no currentSession for school [' . $schoolName . '] — punch rejected.');
            return $this->json_error('No active academic session configured for this school. Punch rejected.', 409);
        }
        $session = (string) $activeSession;

        // Device type already fetched during confidence check (reuse $deviceInfo_pre)
        $deviceType = $devType ?: 'unknown';

        // Parse punch time
        $ts = strtotime($punchTime);
        if (!$ts) $ts = time();
        $dateStr = date('Y-m-d', $ts);
        $timeStr = date('H:i', $ts);
        $dayOfMonth = (int) date('j', $ts);
        $monthName  = date('F', $ts);
        $yearNum    = (int) date('Y', $ts);
        $attKey     = "{$monthName} {$yearNum}";
        $monthNum   = (int) date('n', $ts);
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNum, $yearNum);

        // ── Idempotency check via event_id (device-generated UUID) ──
        // If the device sends an event_id, we check ProcessedEvents first.
        // This is O(1) vs the O(N) time-window dedup scan below.
        if ($eventId) {
            if (!preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $eventId)) {
                return $this->json_error('Invalid event_id format.', 400);
            }
            // Phase 6D: durable idempotency via Firestore attendanceProcessedEvents
            // (point read by {schoolId}_{eventId}), replacing RTDB ProcessedEvents.
            $evtDocId = ($auth['school_id'] ?? $this->school_id) . '_' . $eventId;
            $existing = $this->fs->get('attendanceProcessedEvents', $evtDocId);
            if (is_array($existing) && !empty($existing)) {
                // Check TTL — treat expired entries as non-existent
                $expiresAt = (int) ($existing['expiresAt'] ?? $existing['expires_at'] ?? 0);
                if ($expiresAt > time()) {
                    // Still valid — return the original result (idempotent)
                    return $this->json_success([
                        'mark'       => $existing['mark'] ?? 'P',
                        'person_id'  => $personId,
                        'time'       => $existing['time'] ?? $timeStr,
                        'direction'  => $direction,
                        'idempotent' => true,
                    ]);
                }
                // Expired — delete stale entry and reprocess
                try { $this->fs->remove('attendanceProcessedEvents', $evtDocId); } catch (\Exception $e) {}
            }
        }

        // Dedup check — reject if same person punched within 5 minutes (fallback
        // for devices without event_id). Phase 6D: scans the canonical Firestore
        // attendancePunches collection (schoolId-scoped, date==) instead of RTDB
        // Punch_Log — single source of truth, no separate dedup store.
        try {
            $existingPunches = $this->fs->schoolList('attendancePunches', [['date', '==', $dateStr]]);
        } catch (\Exception $e) {
            $existingPunches = [];
        }
        if (is_array($existingPunches)) {
            foreach ($existingPunches as $pData) {
                $pData = $pData['data'] ?? $pData;
                if (!is_array($pData)) continue;
                if (($pData['person_id'] ?? '') !== $personId) continue;
                if (($pData['direction'] ?? '') !== $direction) continue;
                $prevTs = strtotime($pData['punch_time'] ?? '');
                if ($prevTs && abs($ts - $prevTs) < self::DUPLICATE_WINDOW) {
                    return $this->json_error('Duplicate punch within ' . (self::DUPLICATE_WINDOW / 60) . '-minute window.', 409);
                }
            }
        }

        // Log punch
        $punchData = [
            'person_id'   => $personId,
            'person_type' => $personType,
            'device_id'   => $deviceId,
            'device_type' => $deviceType,
            'punch_time'  => date('c', $ts),
            'direction'   => $direction,
            'confidence'  => $confidence,
            'processed'   => true,
        ];
        if ($class) $punchData['class'] = $class;
        if ($section) $punchData['section'] = $section;

        // Phase 6D — Firestore attendancePunches is the sole canonical punch log
        // (RTDB Punch_Log mirror push removed; this write also feeds dedup above).
        $punchId = 'PUNCH_' . dechex((int) ($ts * 1000)) . '_' . bin2hex(random_bytes(4));
        try {
            $this->fs->set('attendancePunches', $this->fs->docId($punchId), array_merge($punchData, [
                'schoolId'    => $auth['school_id'] ?? $this->school_id,
                'session'     => $session,
                'date'        => $dateStr,
                'punchId'     => $punchId,
                'createdAt'   => date('c'),
            ]), true);
        } catch (\Exception $e) { /* best-effort */ }

        // Update last_ping on device — Firestore-only (Phase 6C: redundant RTDB
        // Config/Devices last_ping write removed; halves per-punch device writes).
        try {
            $this->fs->set('attendanceDevices', $this->fs->docId($deviceId), [
                'schoolId' => $auth['school_id'] ?? $this->school_id,
                'deviceId' => $deviceId,
                'lastPing' => date('c'),
            ], true);
        } catch (\Exception $e) { /* best-effort */ }

        // Determine mark (P or T based on late threshold).
        // Phase 6B: threshold now sourced from the shared Firestore
        // attendanceSettings/{schoolId} document (same source as the Admin
        // Settings screen) to prevent split-brain. Scoped to the API-key
        // school. The remaining api_punch RTDB logic stays for Phase 6D.
        $settings = $this->_get_attendance_settings($auth['school_id'] ?? $this->school_id);
        $threshold = $personType === 'staff'
            ? ($settings['late_threshold_staff'] ?? '09:00')
            : ($settings['late_threshold_student'] ?? '08:30');

        $mark = 'P';
        if ($direction === 'in' && $timeStr > $threshold) {
            $mark = 'T'; // Late
        }

        // Write attendance (only for 'in' direction)
        if ($direction === 'in') {
            if ($personType === 'student' && $class && $section) {
                // Concurrency lock — mutex KEY ONLY (no RTDB I/O).
                $secRoot = $this->_resolve_section_root($class, $section);
                $attPath = "{$secRoot}/Students/{$personId}/Attendance/{$attKey}";

                if ($this->_acquire_att_lock($attPath)) {
                    // Previous mark from Firestore canonical (attendanceSummary.dayWise)
                    // — replaces the retired RTDB month-string seed read; preserves the
                    // "first IN-punch of the day wins" semantic.
                    $monthKeyIso = sprintf('%04d-%02d', $yearNum, $monthNum);
                    $curSum = null;
                    try { $curSum = $this->fs->get('attendanceSummary', $this->fs->docId2($personId, $monthKeyIso)); }
                    catch (\Throwable $e) {}
                    $curDayWise = ($curSum && is_string($curSum['dayWise'] ?? null)) ? $curSum['dayWise'] : '';
                    $oldDevMark = (is_string($curDayWise) && isset($curDayWise[$dayOfMonth - 1])) ? $curDayWise[$dayOfMonth - 1] : 'V';
                    if ($oldDevMark === 'V') {
                        // Firestore canonical write (per-day attendance + attendanceSummary).
                        $this->_syncDailyToFirestore($personId, $mark, $class, $section,
                            $dayOfMonth, $attKey, '', $mark === 'T');
                    }
                    $this->_release_att_lock($attPath);
                }
                // Punch Late RTDB record REMOVED — dead node (zero Firestore-reading
                // consumers, per Component-1 evidence). The 'T' mark itself persists
                // to Firestore via dayWise (_syncDailyToFirestore above).
            } elseif ($personType === 'staff') {
                // R6: Firestore-only via Staff_attendance_writer (lock-aware CAS).
                // - Concurrency: writer's Firestore CAS preconditions supersede the
                //   process-local `_acquire_att_lock` mutex; the staff branch no
                //   longer takes that lock.
                // - "First IN punch of the day wins" semantic preserved by a
                //   pre-write peek at staffAttendanceSummary.dayWise[day-1].
                // - lateMinutes computed from (punch_time - threshold) so the
                //   canonical staffAttendance daily doc carries the late precision
                //   that the retired RTDB Staff_Attendance/Late record used to hold
                //   (the legacy record had zero readers anywhere in the codebase).
                $dateISO          = date('Y-m-d', $ts);
                $monthKey         = sprintf('%04d-%02d', $yearNum, $monthNum);
                $schoolIdForWrite = (string) ($auth['school_id'] ?? $this->school_id);

                $summaryDocId    = "{$schoolIdForWrite}_{$personId}_{$monthKey}";
                $summarySnapshot = $this->firebase->firestoreGet('staffAttendanceSummary', $summaryDocId);
                $existingDayWise = is_array($summarySnapshot) ? (string) ($summarySnapshot['dayWise'] ?? '') : '';
                $existingChar    = strlen($existingDayWise) >= $dayOfMonth
                    ? $existingDayWise[$dayOfMonth - 1]
                    : 'V';

                if ($existingChar === 'V') {
                    $lateMinutes = 0;
                    if ($mark === 'T') {
                        $thrTs = strtotime("{$dateStr} {$threshold}");
                        if ($thrTs) $lateMinutes = max(0, (int) round(($ts - $thrTs) / 60));
                    }
                    try {
                        $this->load->library('staff_attendance_writer');
                        $this->staff_attendance_writer->init($this->firebase, $schoolIdForWrite, $session);
                        $this->staff_attendance_writer->markSingleDay($personId, $dateISO, $mark, [
                            'markedBy'     => 'device:' . $deviceId,
                            'source'       => 'punch',
                            'lateMinutes'  => $lateMinutes,
                            'punchEventId' => $eventId ?: '',
                        ]);
                    } catch (MonthLockedException $e) {
                        return $this->json_error('Attendance for this month is locked.', 409);
                    } catch (\Throwable $e) {
                        log_message('error', 'api_punch staff markSingleDay failed: ' . $e->getMessage());
                        return $this->json_error('Could not record staff attendance. Please retry.', 500);
                    }
                }
            }
        }

        // Store event_id for idempotency (if provided), with TTL for auto-expiry.
        // Phase 6D: durable Firestore attendanceProcessedEvents (not RTDB).
        if ($eventId) {
            $evtDocId = ($auth['school_id'] ?? $this->school_id) . '_' . $eventId;
            try {
                $this->fs->set('attendanceProcessedEvents', $evtDocId, [
                    'schoolId'      => $auth['school_id'] ?? $this->school_id,
                    'eventId'       => $eventId,
                    'session'       => $session,
                    'mark'          => $mark,
                    'time'          => $timeStr,
                    'personId'      => $personId,
                    'person_id'     => $personId,
                    'direction'     => $direction,
                    'processedAt'   => date('c'),
                    'expiresAt'     => time() + self::IDEMPOTENCY_TTL,
                    'schemaVersion' => self::ATT_PUNCH_SCHEMA_VERSION,
                ], true);
            } catch (\Exception $e) { /* best-effort */ }
        }

        // Audit log for device punches — canonical schema. Device school +
        // actor override the stamp defaults; targetType from person type.
        $this->_audit_write([
            'schoolId'   => $auth['school_id'] ?? $this->school_id,
            'userId'     => 'device:' . $deviceId,
            'role'       => 'device',
            'action'     => 'DEVICE_PUNCH',
            'targetId'   => $personId,
            'targetType' => $personType,
            'mark'       => $mark,
            'direction'  => $direction,
            'time'       => $timeStr,
            'date'       => $dateStr,
        ]);

        return $this->json_success([
            'mark'      => $mark,
            'person_id' => $personId,
            'time'      => $timeStr,
            'direction' => $direction,
        ]);
    }

    /* ================================================================
       GROUP G: ANALYTICS AJAX
       ================================================================ */

    /**
     * Fetch class-wise attendance analytics for a month
     * POST: month, class (optional — if empty, all classes)
     */
    public function fetch_analytics()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_analytics');
        if (!$this->_check_rate_limit('fetch_analytics')) {
            return $this->json_error('Rate limit exceeded. Max ' . self::INTERNAL_RATE_LIMIT . ' requests/minute.', 429);
        }
        $month = trim((string) $this->input->post('month'));
        $classFilter = trim((string) $this->input->post('class'));

        if (!$month || !isset($this->month_map[$month])) {
            return $this->json_error('Invalid month.');
        }

        $school  = $this->school_name;
        $session = $this->session_year;
        $year    = $this->_resolve_year($month);
        $monthNum = $this->month_map[$month];
        $monthKey = sprintf('%04d-%02d', $year, $monthNum);
        $attKey  = "{$month} {$year}";

        // ── Firestore is the canonical source. A SINGLE query fetches every
        //    student summary for the month; class-wise analytics are aggregated
        //    IN MEMORY from those docs (className / section / dayWise are all
        //    present on each doc). This eliminates the previous N+1 pattern
        //    (one roster query per class/section), which cost ~60–170s for
        //    schools with many sections. Response shape is unchanged.
        $fsDocs = [];
        try {
            $fsDocs = $this->fs->schoolWhere('attendanceSummary', [
                ['month', '==', $monthKey],
                ['type', '==', 'student'],
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Attendance::fetch_analytics attendanceSummary read failed: ' . $e->getMessage());
            $fsDocs = [];
        }

        // Group summaries by class + section, summing per-student stats computed
        // from dayWise — the IDENTICAL computation used by the prior per-student
        // path, just sourced by grouping the canonical summaries directly.
        $groups = []; // "className||sectionLetter" => running totals
        foreach ($fsDocs as $entry) {
            $d = is_array($entry) ? ($entry['data'] ?? $entry) : null;
            if (!is_array($d)) continue;

            $sid = (string) ($d['studentId'] ?? '');
            $dw  = $d['dayWise'] ?? '';
            if ($sid === '' || !is_string($dw) || $dw === '') continue;

            $cName = (string) ($d['className'] ?? '');
            if ($cName === '') continue;
            if ($classFilter && $cName !== $classFilter) continue;

            // Match the legacy class-list format (letter only, no "Section " prefix).
            $secLetter = str_replace('Section ', '', (string) ($d['section'] ?? ''));

            $key = $cName . '||' . $secLetter;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'class'   => $cName,
                    'section' => $secLetter,
                    'P' => 0, 'A' => 0, 'L' => 0, 'H' => 0, 'T' => 0, 'V' => 0,
                    'students' => 0,
                ];
            }

            $stats = $this->_compute_month_stats($dw);
            foreach (['P', 'A', 'L', 'H', 'T', 'V'] as $ch) {
                $groups[$key][$ch] += $stats[$ch];
            }
            $groups[$key]['students']++;
        }

        // Deterministic order (class, then section).
        ksort($groups);

        $analytics = [];
        foreach ($groups as $g) {
            $classTotals = [
                'P' => $g['P'], 'A' => $g['A'], 'L' => $g['L'],
                'H' => $g['H'], 'T' => $g['T'], 'V' => $g['V'],
                'students' => $g['students'],
            ];

            $working = $classTotals['P'] + $classTotals['A'] + $classTotals['L'] + $classTotals['T'];
            $present_pct = $working > 0
                ? round(($classTotals['P'] + $classTotals['T']) / $working * 100, 1)
                : 0;

            $analytics[] = [
                'class'       => $g['class'],
                'section'     => $g['section'],
                'label'       => str_replace('Class ', '', $g['class']) . ' ' . $g['section'],
                'students'    => $classTotals['students'],
                'present_pct' => $present_pct,
                'absent_pct'  => $working > 0 ? round($classTotals['A'] / $working * 100, 1) : 0,
                'late_count'  => $classTotals['T'],
                'totals'      => $classTotals,
            ];
        }

        // Pagination
        $total = count($analytics);
        $page  = max(1, (int) ($this->input->post('page') ?: 1));
        $limit = max(1, min(200, (int) ($this->input->post('limit') ?: 50)));
        $offset = ($page - 1) * $limit;
        $paged  = array_slice($analytics, $offset, $limit);

        return $this->json_success([
            'analytics'  => $paged,
            'month'      => $month,
            'year'       => $year,
            'pagination' => [
                'page'       => $page,
                'limit'      => $limit,
                'total'      => $total,
                'total_pages' => (int) ceil($total / $limit),
            ],
        ]);
    }

    /**
     * Monthly trend — attendance percentage per month across the session
     * POST: class (optional), section (optional)
     */
    public function fetch_monthly_trend()
    {
        $this->_require_role(self::VIEW_ROLES, 'monthly_trend');
        $classFilter   = trim((string) $this->input->post('class'));
        $sectionFilter = trim((string) $this->input->post('section'));

        $school  = $this->school_name;
        $session = $this->session_year;

        $classList = $this->_build_class_list();

        // Build section keys that match the filter
        $filteredSections = [];
        $filteredCsPairs = []; // [{className, section}, ...] — used for Firestore matching
        foreach ($classList as $cls) {
            if ($classFilter && $cls['class_name'] !== $classFilter) continue;
            if ($sectionFilter && $cls['section'] !== $sectionFilter) continue;
            $filteredSections[] = str_replace(' ', '_', $cls['class_name']) . '_' . $cls['section'];
            $filteredCsPairs[] = [
                'className' => Firestore_service::classKey($cls['class_name']),
                'section'   => Firestore_service::sectionKey($cls['section']),
            ];
        }

        // ── READ: Firestore FIRST — pre-fetch every student summary in the school ──
        // One query → group by `month` (YYYY-MM) → keyed totals per month.
        // The academic-month loop below prefers these totals and only
        // falls back to the cached RTDB summary / raw compute when the
        // Firestore set has no entry for a given month.
        $fsTotalsByMonth = []; // monthKey ("YYYY-MM") → ['P'=>x, 'work'=>y]
        try {
            $fsDocs = $this->fs->schoolWhere('attendanceSummary', [
                ['type', '==', 'student'],
            ]);
            foreach ($fsDocs as $entry) {
                $d = is_array($entry) ? ($entry['data'] ?? $entry) : null;
                if (!is_array($d)) continue;

                // Honour the same class+section filters as the legacy path
                if (!empty($filteredCsPairs)) {
                    $docCls = $d['className'] ?? '';
                    $docSec = $d['section']   ?? '';
                    $matched = false;
                    foreach ($filteredCsPairs as $pair) {
                        if ($pair['className'] === $docCls && $pair['section'] === $docSec) {
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched) continue;
                }

                $mk = $d['month'] ?? '';
                if (!preg_match('/^\d{4}-\d{2}$/', $mk)) continue;
                if (!isset($fsTotalsByMonth[$mk])) {
                    $fsTotalsByMonth[$mk] = ['P' => 0, 'work' => 0];
                }
                $present = (int) ($d['present'] ?? 0);
                $tardy   = (int) ($d['tardy']   ?? 0);
                $absent  = (int) ($d['absent']  ?? 0);
                $leave   = (int) ($d['leave']   ?? 0);
                $fsTotalsByMonth[$mk]['P']    += $present + $tardy;
                $fsTotalsByMonth[$mk]['work'] += $present + $tardy + $absent + $leave;
            }
        } catch (\Exception $e) {
            log_message('error', 'Attendance::fetch_monthly_trend fsTotalsByMonth Firestore read failed: ' . $e->getMessage());
            $fsTotalsByMonth = []; // fall through to legacy paths
        }

        $trend = [];
        $needFullCompute = false;

        foreach ($this->academic_months as $month) {
            $year    = $this->_resolve_year($month);
            $attKey  = "{$month} {$year}";
            $monthNum = $this->month_map[$month];
            $monthKey = sprintf('%04d-%02d', $year, $monthNum);

            $monthEnd = mktime(23, 59, 59, $monthNum, cal_days_in_month(CAL_GREGORIAN, $monthNum, $year), $year);
            if ($monthEnd > time()) {
                continue;
            }

            // ── Firestore FIRST ──
            if (isset($fsTotalsByMonth[$monthKey]) && $fsTotalsByMonth[$monthKey]['work'] > 0) {
                $row = $fsTotalsByMonth[$monthKey];
                $trend[] = [
                    'month'       => $month,
                    'year'        => $year,
                    'present_pct' => round($row['P'] / $row['work'] * 100, 1),
                    'cached'      => true,
                ];
                continue;
            }

            // Zero-RTDB: no Firestore data for this month → 0%. The legacy RTDB
            // fallbacks (cached `…/Attendance/Summary/Students` node + raw
            // `{secRoot}/Students` compute) are removed; attendanceSummary is
            // the sole source of truth for the trend.
            $trend[] = [
                'month'       => $month,
                'year'        => $year,
                'present_pct' => 0,
                'cached'      => false,
            ];
        }

        return $this->json_success(['trend' => $trend]);
    }

    /**
     * Individual report — single student or staff member full session
     * POST: person_id, person_type (student|staff), class (if student), section (if student)
     */
    public function fetch_individual_report()
    {
        $this->_require_role(self::VIEW_ROLES, 'individual_report');
        $personId   = trim((string) $this->input->post('person_id'));
        $personType = trim((string) $this->input->post('person_type'));
        $class      = trim((string) $this->input->post('class'));
        $section    = trim((string) $this->input->post('section'));

        if (!$personId || !$personType) {
            return $this->json_error('person_id and person_type required.');
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $personId)) {
            return $this->json_error('Invalid person ID.');
        }

        $school  = $this->school_name;
        $session = $this->session_year;

        // Validate class/section before the loop (not inside it)
        if ($personType === 'student') {
            if (!$class || !$section) {
                return $this->json_error('Class and section required for student report.');
            }
            $class   = $this->safe_path_segment($class, 'class');
            $section = $this->safe_path_segment($section, 'section');
        }

        // Look up person name for confirmation
        $personName = '';
        $personClass = '';
        $personSection = '';
        if ($personType === 'student') {
            // Zero-RTDB: student profile from Firestore (was RTDB
            // Users/Parents/{parent_db_key}/{id}).
            $profile = $this->fs->get('students', "{$this->school_id}_{$personId}");
            if (is_array($profile)) {
                $personName    = $profile['Name'] ?? $profile['name'] ?? '';
                $personClass   = $profile['Class'] ?? $profile['class'] ?? '';
                $personSection = $profile['Section'] ?? $profile['section'] ?? '';
            }
        } else {
            try {
                $staffDoc = $this->fs->getEntity('staff', "{$this->school_id}_{$personId}");
                if (is_array($staffDoc)) {
                    $personName = $staffDoc['Name'] ?? $staffDoc['name'] ?? '';
                }
            } catch (\Throwable $e) {
                log_message('error', 'Attendance::fetch_individual_report staff lookup failed: ' . $e->getMessage());
            }
        }

        $monthlyData = [];
        $grandTotals = ['P' => 0, 'A' => 0, 'L' => 0, 'H' => 0, 'T' => 0, 'V' => 0];

        // ── READ: Firestore FIRST — pre-fetch every month for this person ──
        // One query gets all monthly summaries; we key them by `month`
        // ("YYYY-MM") so the loop below can do an O(1) lookup before
        // hitting RTDB. Empty result → fall through to RTDB per-month.
        $fsByMonth = [];
        try {
            if ($personType === 'student') {
                $fsDocs = $this->fs->schoolWhere('attendanceSummary', [
                    ['studentId', '==', $personId],
                    ['type',      '==', 'student'],
                ]);
            } else {
                $fsDocs = $this->fs->schoolWhere('staffAttendanceSummary', [
                    ['staffId', '==', $personId],
                ]);
            }
            foreach ($fsDocs as $entry) {
                $d = is_array($entry) ? ($entry['data'] ?? $entry) : null;
                if (!is_array($d)) continue;
                $mk = $d['month']  ?? '';
                $dw = $d['dayWise'] ?? '';
                if (preg_match('/^\d{4}-\d{2}$/', $mk) && is_string($dw) && $dw !== '') {
                    $fsByMonth[$mk] = $dw;
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Attendance::fetch_individual_report fsByMonth Firestore read failed: ' . $e->getMessage());
            $fsByMonth = []; // fall through to RTDB-only path
        }

        foreach ($this->academic_months as $month) {
            $year   = $this->_resolve_year($month);
            $attKey = "{$month} {$year}";
            $monthNum = $this->month_map[$month] ?? 0;
            $monthKey = $monthNum ? sprintf('%04d-%02d', $year, $monthNum) : '';

            // ── Firestore FIRST ──
            $attStr = $monthKey !== '' ? ($fsByMonth[$monthKey] ?? '') : '';

            // Zero-RTDB hard line: BOTH the staff and the student RTDB fallbacks
            // are removed. Firestore (staffAttendanceSummary / attendanceSummary)
            // is the sole source of truth for attendance history.

            if ($attStr === '') {
                $monthlyData[] = ['month' => $month, 'year' => $year, 'stats' => null];
                continue;
            }

            $stats = $this->_compute_month_stats($attStr);
            $working = $stats['P'] + $stats['A'] + $stats['L'] + $stats['T'];
            $stats['present_pct'] = $working > 0
                ? round(($stats['P'] + $stats['T']) / $working * 100, 1)
                : 0;

            $monthlyData[] = ['month' => $month, 'year' => $year, 'stats' => $stats];

            foreach (['P', 'A', 'L', 'H', 'T', 'V'] as $ch) {
                $grandTotals[$ch] += $stats[$ch];
            }
        }

        $gWork = $grandTotals['P'] + $grandTotals['A'] + $grandTotals['L'] + $grandTotals['T'];
        $grandTotals['present_pct'] = $gWork > 0
            ? round(($grandTotals['P'] + $grandTotals['T']) / $gWork * 100, 1)
            : 0;

        return $this->json_success([
            'person_name'    => $personName,
            'person_class'   => $personClass,
            'person_section' => $personSection,
            'person_id'      => $personId,
            'person_type'    => $personType,
            'months'         => $monthlyData,
            'totals'         => $grandTotals,
        ]);
    }

    // compute_summary() RETIRED (Component 5) — orphan endpoint (no UI/route caller) that rebuilt the dead section-summary RTDB node.

    /**
     * Fetch punch log for a date.
     * POST: date (YYYY-MM-DD), page, limit
     *
     * Uses shallow_get to count total keys first, then fetches only the requested page
     * to avoid loading 1000s of punch records into memory at once.
     */
    public function fetch_punch_log()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_punch_log');
        $date = trim((string) $this->input->post('date'));
        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }

        $basePath = "Schools/{$this->school_name}/{$this->session_year}/Attendance/Punch_Log/{$date}";

        $page  = max(1, (int) ($this->input->post('page') ?: 1));
        $limit = max(1, min(200, (int) ($this->input->post('limit') ?: 50)));

        // Phase 7d — Firestore-first read.
        try {
            $fsPunches = $this->fs->schoolList('attendancePunches', [
                ['date', '==', $date],
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Attendance::fetch_punch_log fsPunches Firestore read failed: ' . $e->getMessage());
            $fsPunches = [];
        }

        if (!empty($fsPunches) && is_array($fsPunches)) {
            // OP-7 — sort by canonical timestamp. GPS staff punches carry
            // `serverTime` (server-authoritative); legacy device rows carry
            // `punch_time`. Fall back gracefully so both shapes sort correctly.
            $punchTs = function ($r) {
                return (string) ($r['serverTime'] ?? $r['punch_time'] ?? $r['time'] ?? $r['serverTimestamp'] ?? '');
            };
            usort($fsPunches, function ($a, $b) use ($punchTs) {
                return strcmp($punchTs($b), $punchTs($a));   // newest first
            });
            $total = count($fsPunches);
            $totalPages = (int) ceil($total / $limit);
            $offset = ($page - 1) * $limit;
            $slice = array_slice($fsPunches, $offset, $limit);
            $punches = [];
            foreach ($slice as $p) {
                if (!is_array($p)) continue;
                $punches[] = $this->_normalize_punch_row($p, $punchTs($p));
            }
            $this->_resolve_punch_names($punches);   // OP-7b: fill display names from staff/students
            return $this->json_success([
                'punches'    => $punches,
                'date'       => $date,
                'pagination' => [
                    'page' => $page, 'limit' => $limit,
                    'total' => $total, 'total_pages' => $totalPages,
                ],
            ]);
        }

        // Firestore is the ONLY source of truth (zero-RTDB hard line). When the
        // attendancePunches query returns empty, that is the authoritative result
        // — the legacy RTDB Punch_Log fallback has been removed (was: shallow_get
        // + per-key firebase->get over Schools/.../Attendance/Punch_Log/{date}).
        return $this->json_success([
            'punches'    => [],
            'date'       => $date,
            'pagination' => ['page' => $page, 'limit' => $limit, 'total' => 0, 'total_pages' => 0],
        ]);
    }

    /**
     * OP-7b — Fill a human-readable `name` on each normalized punch row for the
     * admin Punch Log. PRESENTATION ONLY: it resolves staff/student ids to their
     * display name from the canonical Firestore docs. Batched + de-duplicated so
     * it costs at most one read per UNIQUE person on the page (not per row), and
     * is fully best-effort — any lookup miss simply leaves the id showing.
     *
     * @param array $punches normalized rows (by reference) — each gains/keeps `name`
     */
    private function _resolve_punch_names(array &$punches): void
    {
        if (empty($punches)) return;

        // Collect the unique (type,id) pairs that still need a name.
        $need = [];
        foreach ($punches as $row) {
            $pid = (string) ($row['user_id'] ?? $row['person_id'] ?? '');
            if ($pid === '' || ($row['name'] ?? '') !== '') continue;
            $isStaff = in_array(strtolower((string) ($row['type'] ?? '')), ['staff', 'teacher'], true);
            $need[($isStaff ? 's:' : 'u:') . $pid] = ['id' => $pid, 'staff' => $isStaff];
        }
        if (empty($need)) return;

        $names = [];
        foreach ($need as $key => $meta) {
            try {
                if ($meta['staff']) {
                    $doc = $this->fs->get('staff', "{$this->school_id}_{$meta['id']}");
                    $nm  = is_array($doc) ? (string) ($doc['Name'] ?? $doc['name'] ?? '') : '';
                } else {
                    $doc = $this->fs->get('students', "{$this->school_id}_{$meta['id']}");
                    $nm  = is_array($doc) ? (string) ($doc['Name'] ?? $doc['name'] ?? '') : '';
                }
                if ($nm !== '') $names[$key] = $nm;
            } catch (\Throwable $e) {
                // best-effort — leave the id to display
            }
        }
        if (empty($names)) return;

        foreach ($punches as &$row) {
            if (($row['name'] ?? '') !== '') continue;
            $pid = (string) ($row['user_id'] ?? $row['person_id'] ?? '');
            if ($pid === '') continue;
            $isStaff = in_array(strtolower((string) ($row['type'] ?? '')), ['staff', 'teacher'], true);
            $key = ($isStaff ? 's:' : 'u:') . $pid;
            if (isset($names[$key])) $row['name'] = $names[$key];
        }
        unset($row);
    }

    /**
     * OP-7 — Normalize one attendancePunches row into a single, view-agnostic
     * contract for the Device Punch Log. PRESENTATION-MAPPING ONLY: it reshapes
     * existing canonical Firestore fields for display; it makes NO attendance
     * decision, computes no business value, and reads/writes nothing.
     *
     * Two source shapes converge here:
     *   - GPS staff self-punch (Staff_attendance::_record_punch): staffId,
     *     personType, method=gps, serverTime, outcome, rejectionReason, mark,
     *     accuracy, distanceMeters, mock, lat/lng, deviceInfo{model,...}.
     *   - Legacy device/student rows: person_id, type, punch_time, device,
     *     device_type, confidence.
     *
     * @param array  $p   raw Firestore (or legacy) punch row
     * @param string $ts  pre-resolved canonical timestamp string
     * @return array       unified row the view renders
     */
    private function _normalize_punch_row(array $p, string $ts): array
    {
        $isGps  = (($p['method'] ?? '') === 'gps') || (($p['personType'] ?? '') === 'staff');
        $device = is_array($p['deviceInfo'] ?? null) ? $p['deviceInfo'] : [];

        $deviceLabel = $p['device'] ?? '';
        if ($deviceLabel === '' && $device) {
            $maker = trim((string) ($device['manufacturer'] ?? ''));
            $model = trim((string) ($device['model'] ?? ''));
            $deviceLabel = trim($maker . ' ' . $model);
            if ($deviceLabel === '') $deviceLabel = trim((string) ($device['os'] ?? ''));
        }

        return [
            // identity / classification (legacy view keys)
            'id'          => (string) ($p['punchId'] ?? $p['id'] ?? ''),
            'person_id'   => (string) ($p['staffId'] ?? $p['person_id'] ?? ''),
            'user_id'     => (string) ($p['staffId'] ?? $p['person_id'] ?? $p['user_id'] ?? ''),
            'name'        => (string) ($p['name'] ?? ''),   // resolved by _resolve_punch_names()
            'type'        => (string) ($p['personType'] ?? $p['type'] ?? 'student'),
            'direction'   => (string) ($p['direction'] ?? 'in'),
            'time'        => $ts !== '' ? $ts : (string) ($p['time'] ?? ''),
            'device'      => $deviceLabel !== '' ? $deviceLabel : '',
            'device_type' => $isGps ? 'GPS' : (string) ($p['device_type'] ?? ''),
            'confidence'  => isset($p['confidence']) ? $p['confidence'] : null,
            // GPS audit evidence (new — empty for non-GPS rows)
            'method'           => (string) ($p['method'] ?? ($isGps ? 'gps' : '')),
            'outcome'          => (string) ($p['outcome'] ?? ''),
            'rejection_reason' => (string) ($p['rejectionReason'] ?? ''),
            'mark'             => (string) ($p['mark'] ?? ''),
            'accuracy'         => isset($p['accuracy']) && is_numeric($p['accuracy']) ? (float) $p['accuracy'] : null,
            'distance'         => isset($p['distanceMeters']) && is_numeric($p['distanceMeters']) ? (float) $p['distanceMeters'] : null,
            'mock'             => !empty($p['mock']),
            'lat'              => isset($p['lat']) && is_numeric($p['lat']) ? (float) $p['lat'] : null,
            'lng'              => isset($p['lng']) && is_numeric($p['lng']) ? (float) $p['lng'] : null,
        ];
    }

    /* ================================================================
       GROUP H: MOBILE API (session auth — teacher app)
       ================================================================ */

    /**
     * Get classes/sections the logged-in teacher is assigned to
     */
    public function api_get_classes()
    {
        $this->_require_role(self::VIEW_ROLES, 'api_get_classes');
        header('Content-Type: application/json');
        $classes = $this->_build_class_list();
        return $this->json_success(['classes' => $classes]);
    }

    /**
     * Get student list for a class/section
     * POST: class, section
     */
    public function api_get_students()
    {
        $this->_require_role(self::VIEW_ROLES, 'api_get_students');
        $class   = $this->safe_path_segment(trim((string) $this->input->post('class')), 'class');
        $section = $this->safe_path_segment(trim((string) $this->input->post('section')), 'section');

        if (!$class || !$section) {
            return $this->json_error('Class and section required.');
        }

        // R5 — Firestore-only roster lookup (pure listing endpoint;
        // no attendance reads downstream).
        $list = $this->_get_section_students($class, $section);

        $students = [];
        if (!empty($list)) {
            foreach ($list as $id => $name) {
                if (!is_string($id) || trim($id) === '') continue;
                $students[] = ['id' => $id, 'name' => is_string($name) ? $name : (string) $id];
            }
            usort($students, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
        }

        return $this->json_success(['students' => $students]);
    }

    /**
     * Get today's attendance for a class/section
     * POST: class, section
     */
    public function api_get_attendance()
    {
        $this->_require_role(self::VIEW_ROLES, 'api_get_attendance');
        $class   = $this->safe_path_segment(trim((string) $this->input->post('class')), 'class');
        $section = $this->safe_path_segment(trim((string) $this->input->post('section')), 'section');

        if (!$class || !$section) {
            return $this->json_error('Class and section required.');
        }

        $today    = (int) date('j');
        $month    = date('F');
        $year     = (int) date('Y');
        $monthNum = (int) date('n');
        $monthKey = sprintf('%d-%02d', $year, $monthNum);
        $attKey   = "{$month} {$year}";

        // Roster from Firestore via Roster_helper (canonical).
        // RTDB per-student attendance fallback REMOVED — attendanceSummary only.
        $list = $this->_get_section_students($class, $section);

        // ── READ: Firestore FIRST — per-student dayWise this month ──
        $fsDayWise = [];
        try {
            $fsDocs = $this->fs->schoolWhere('attendanceSummary', [
                ['month', '==', $monthKey],
                ['type', '==', 'student'],
            ]);
            foreach ($fsDocs as $entry) {
                $d = is_array($entry) ? ($entry['data'] ?? $entry) : null;
                if (!is_array($d)) continue;
                $sid = $d['studentId'] ?? '';
                $dw  = $d['dayWise'] ?? '';
                if ($sid !== '' && is_string($dw)) $fsDayWise[$sid] = $dw;
            }
        } catch (\Exception $e) { /* fall back */ }

        $result = [];
        if (!empty($list)) {
            foreach ($list as $id => $name) {
                if (!is_string($id) || trim($id) === '') continue;
                $todayMark = 'V';

                // Firestore canonical (RTDB per-student fallback REMOVED)
                if (isset($fsDayWise[$id]) && strlen($fsDayWise[$id]) >= $today) {
                    $todayMark = $fsDayWise[$id][$today - 1];
                }

                $result[] = [
                    'id'   => $id,
                    'name' => is_string($name) ? $name : (string) $id,
                    'mark' => $todayMark,
                ];
            }
            usort($result, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
        }

        return $this->json_success([
            'students' => $result,
            'date'     => date('Y-m-d'),
            'month'    => $month,
            'year'     => $year,
            'day'      => (int) $today,
        ]);
    }

    /**
     * Teacher marks attendance for today from mobile app
     * POST: class, section, attendance (JSON: {student_id: "P"|"A"|"L"|"T"|"H", ...}),
     *        late_times (JSON: {student_id: "08:47", ...})
     */
    /**
     * Phase 8b — Lightweight endpoint for the teacher app to trigger
     * parent push notifications after marking attendance. The teacher
     * app writes directly to Firestore (canonical), then calls this
     * endpoint to fire the push pipeline which lives in PHP.
     *
     * POST params: student_id, mark (A|T), class, section, day, month
     *
     * Returns: {status, pushed, queued}
     */
    /**
     * Phase 8b — Process push requests written by the teacher app.
     *
     * The teacher app writes a Firestore doc to `pushRequests` when
     * it marks a student A/T. This endpoint reads pending requests
     * for the current school, fires the push pipeline for each, and
     * marks them as processed. Called by the admin dashboard on load
     * or by a cron job.
     *
     * GET /attendance/process_push_requests
     */
    public function process_push_requests()
    {
        $this->_require_role(self::VIEW_ROLES, 'process_push_requests');
        $this->_process_pending_push_requests();
        return $this->json_success(['message' => 'Pending push requests processed.']);
    }

    public function teacher_notify()
    {
        $this->_require_role(self::MARK_ROLES, 'teacher_notify');

        $studentId = trim((string) $this->input->post('student_id'));
        $mark      = strtoupper(trim((string) $this->input->post('mark')));
        $class     = $this->safe_path_segment(trim((string) $this->input->post('class')), 'class');
        $section   = $this->safe_path_segment(trim((string) $this->input->post('section')), 'section');
        $day       = (int) $this->input->post('day');
        $month     = trim((string) $this->input->post('month'));

        if (!$studentId || !$mark || !$class || !$section || !$day) {
            return $this->json_error('student_id, mark, class, section, and day are required.');
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $studentId)) {
            return $this->json_error('Invalid student ID.');
        }
        if (!in_array($mark, ['A', 'T'])) {
            return $this->json_success(['pushed' => 0, 'message' => 'Push only fires for A or T marks.']);
        }

        // Default month to current if not provided
        if ($month === '') {
            $month = date('F');
        }
        $year   = $this->_resolve_year($month);
        $attKey = "{$month} {$year}";

        // Fire the same pipeline the admin uses
        $this->_fire_single_student_event($studentId, $class, $section, $mark, $day, $attKey);

        return $this->json_success([
            'message' => "Notification pipeline fired for {$studentId} ({$mark}).",
        ]);
    }

    public function api_mark_attendance()
    {
        $this->_require_role(self::MARK_ROLES, 'api_mark_attendance');
        $class   = $this->safe_path_segment(trim((string) $this->input->post('class')), 'class');
        $section = $this->safe_path_segment(trim((string) $this->input->post('section')), 'section');
        $attData = $this->input->post('attendance');
        $lateTimes = $this->input->post('late_times');

        if (!$class || !$section || !$attData) {
            return $this->json_error('class, section, and attendance required.');
        }
        // H-01 FIX: Teachers can only mark attendance for their assigned classes
        if (!$this->_teacher_can_access($class, "Section {$section}")) {
            return $this->json_error('You are not assigned to this class/section.', 403);
        }

        if (is_string($attData)) $attData = json_decode($attData, true);
        if (is_string($lateTimes)) $lateTimes = json_decode($lateTimes, true);
        if (!is_array($attData)) return $this->json_error('Invalid attendance data.');

        $school  = $this->school_name;
        $session = $this->session_year;
        $today   = (int) date('j');
        $month   = date('F');
        $year    = (int) date('Y');
        $attKey  = "{$month} {$year}";
        $monthNum = (int) date('n');
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);

        // Phase 7e — Firestore-first then RTDB mirror.
        $bulkMarks = [];
        foreach ($attData as $studentId => $mark) {
            $studentId = trim((string) $studentId);
            if (!preg_match('/^[A-Za-z0-9_]+$/', $studentId)) continue;
            $mark = strtoupper(trim((string) $mark));
            if (!in_array($mark, $this->valid_marks)) continue;
            $bulkMarks[$studentId] = ['mark' => $mark, 'name' => ''];
        }
        if (!empty($bulkMarks)) {
            $fsOk = $this->_syncBulkDailyToFirestore($bulkMarks, $class, $section, $today, $attKey);
            if ($fsOk === false) {
                return $this->json_error('Firestore write failed; attendance not saved. Please retry.');
            }
        }

        // Student-attendance RTDB mirror REMOVED (dayWise + Late nodes) —
        // Firestore is canonical (written above via _syncBulkDailyToFirestore).
        $saved = 0;
        foreach ($bulkMarks as $studentId => $info) {
            $saved++;
        }

        $this->_log_attendance_change('MOBILE_MARK_STUDENT', [
            'targetType' => 'class', 'targetId' => "{$class}|{$section}",
            'className' => $class, 'section' => $section, 'date' => date('Y-m-d'), 'count' => $saved,
        ]);

        return $this->json_success(['saved' => $saved, 'date' => date('Y-m-d')]);
    }

    /**
     * Mark attendance from a scanned student QR.
     *
     * POST: qr_token  (URL-safe base64 of "{schoolId}|{studentId}", per
     *                   `qr_token_helper`; same format the ID card prints)
     *
     * Behaviour:
     *   1. Decode the token; reject malformed input as `invalid`.
     *   2. Enforce tenant isolation — refuse a token whose schoolId
     *      doesn't match the caller's `school_name` (SCH_xxx). This is
     *      the *only* security gate against forged QR cards (the token
     *      itself isn't signed; we trade signature complexity for
     *      tenant-bounded blast radius).
     *   3. Resolve the student from Firestore. Reject if not Active.
     *   4. Idempotency: if today's attendance doc already exists with
     *      status='P', return `already_marked` instead of writing.
     *   5. Otherwise write Present via the existing Firestore daily
     *      writer (`_syncDailyToFirestore`). RTDB attendance mirror is
     *      out of scope here — Firestore is canonical for attendance
     *      reads in the parent / teacher apps.
     *
     * Response shape:
     *   success         { status:'success',  code:'success',         student_name, student_id, class, section, date }
     *   already_marked  { status:'success',  code:'already_marked',  student_name, student_id, date }
     *   invalid         { status:'error',    message, http 400/403/404 }
     */
    public function scan_qr()
    {
        $this->_require_role(self::MARK_ROLES, 'attendance_scan_qr');

        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required.');
        }

        $token = trim((string) $this->input->post('qr_token'));
        if ($token === '') {
            return $this->json_error('Missing QR token.');
        }

        $this->load->helper('qr_token');
        $decoded = qr_token_decode($token);
        if ($decoded === null) {
            // Includes both "structurally garbage" and "signature
            // tampered / forged" — we deliberately don't distinguish
            // so we don't leak the verifier's state to an attacker.
            return $this->json_error('Invalid QR token.');
        }

        $tokSchoolId = $decoded['schoolId'];
        $studentId   = $decoded['studentId'];

        // Migration window — legacy 2-part (unsigned) tokens are still
        // accepted so existing printed ID cards keep working; logged so
        // we can flip acceptance off once every active card is reissued.
        if (!empty($decoded['legacy'])) {
            log_message('warning',
                "Attendance::scan_qr — legacy unsigned token used for {$tokSchoolId}/{$studentId} "
                . "(reissue this student's ID card to mint a signed token)"
            );
        }

        // Tenant isolation. `school_name` is SCH_xxx in this codebase
        // per non-obvious-conventions memory.
        if ($tokSchoolId !== $this->school_name) {
            log_message('warning',
                "Attendance::scan_qr — cross-school attempt: token={$tokSchoolId} caller={$this->school_name}"
            );
            // BUG-034: tenant-boundary security telemetry (Phase 6+ scope; mirror Homework BUG-014)
            if (isset($this->sec_telem) && $this->sec_telem->isReady()) {
                $this->sec_telem->emit('CROSS_TENANT_PROBE', 'warning', [
                    'endpoint'      => __FUNCTION__,
                    'token_school'  => $tokSchoolId,
                    'caller_school' => $this->school_name,
                ]);
            }
            // BUG-036: existence-oracle collapse — match truly-not-found branch (line ~3653) shape; CROSS_TENANT_PROBE telemetry above preserves forensic capability (mirror Homework v1 BUG-015 pattern)
            return $this->json_error('Student not found.', 404);
        }

        // Firestore student fetch — same docId convention the rest of
        // the SIS module uses post Tier-A ({schoolId}_{studentId}).
        $stuDoc = $this->fs->get('students', "{$this->school_name}_{$studentId}");
        if (empty($stuDoc) || !is_array($stuDoc)) {
            return $this->json_error('Student not found.', 404);
        }

        $statusRaw = (string) ($stuDoc['status'] ?? $stuDoc['Status'] ?? '');
        if (strcasecmp($statusRaw, 'Active') !== 0) {
            return $this->json_error("Student is not Active (status: {$statusRaw}).");
        }

        $name      = (string) ($stuDoc['name']      ?? $stuDoc['Name']    ?? $studentId);
        $className = (string) ($stuDoc['className'] ?? $stuDoc['Class']   ?? '');
        $section   = (string) ($stuDoc['section']   ?? $stuDoc['Section'] ?? '');
        if ($className === '' || $section === '') {
            return $this->json_error('Student has no class/section assigned.');
        }

        // Date arithmetic for today's attendance doc.
        $today    = (int) date('j');
        $monthNum = (int) date('n');
        $year     = (int) date('Y');
        $attKey   = date('F') . " {$year}";
        $date     = sprintf('%04d-%02d-%02d', $year, $monthNum, $today);
        $docId    = "{$this->school_name}_{$date}_{$studentId}";

        // Idempotency check — re-scanning the same card shouldn't
        // overwrite a Late/Tardy mark with Present, and shouldn't
        // log a noise change either.
        //
        // BUT: we still need to verify that BOTH stores agree.
        // Pre-fix scans (before the attendanceSummary writer was
        // wired up) wrote only the daily doc; their summary doc is
        // missing or stale. If we early-return on the daily check
        // alone, the summary never gets backfilled and the parent /
        // teacher / admin views (which read summary, not daily) keep
        // showing nothing. So we treat "daily=P, summary=P-for-today"
        // as the only true already_marked state. Anything else falls
        // through to the writer below — `_syncDailyToFirestore` is
        // idempotent (set with merge), so re-writing P-over-P is a
        // no-op except that it kicks the summary back in sync.
        try {
            $existing = $this->fs->get('attendance', $docId);
        } catch (\Exception $e) {
            log_message('error', "Attendance::scan_qr existing read failed: " . $e->getMessage());
            $existing = null;
        }
        $monthKey      = sprintf('%04d-%02d', $year, $monthNum);
        $daysInMonth   = (int) cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);
        $summaryDocId  = $this->fs->docId2($studentId, $monthKey);
        $existingSum   = null;
        try {
            $existingSum = $this->fs->get('attendanceSummary', $summaryDocId);
        } catch (\Exception $e) {
            log_message('error', "Attendance::scan_qr summary read failed: " . $e->getMessage());
        }
        $sumDayWise = is_array($existingSum) ? (string) ($existingSum['dayWise'] ?? '') : '';
        $sumDayWise = str_pad($sumDayWise, $daysInMonth, 'V');
        $todayCharInSummary = (strlen($sumDayWise) >= $today) ? $sumDayWise[$today - 1] : 'V';

        $dailyAlreadyP   = is_array($existing) && (string) ($existing['status'] ?? '') === 'P';
        $summaryAlreadyP = ($todayCharInSummary === 'P');

        if ($dailyAlreadyP && $summaryAlreadyP) {
            return $this->json_success([
                'code'         => 'already_marked',
                'message'      => "{$name} is already marked Present for {$date}.",
                'student_id'   => $studentId,
                'student_name' => $name,
                'class'        => $className,
                'section'      => $section,
                'date'         => $date,
            ]);
        }

        // Write Present via the canonical Firestore daily writer.
        // (Daily `attendance/{schoolId}_{date}_{studentId}` doc — used
        //  by audit / dashboards.)
        $ok = $this->_syncDailyToFirestore(
            $studentId, 'P', $className, $section,
            $today, $attKey, $name, false, 0
        );
        if (!$ok) {
            return $this->json_error('Could not save attendance. Please retry.', 500);
        }

        // Also update `attendanceSummary` — the per-month dayWise
        // string. THIS is the doc the parent app, teacher app and
        // admin monthly views actually read; without this update the
        // daily doc above lands fine but the cross-system views show
        // nothing because they don't query the daily collection.
        // We already read `$existingSum` above in the idempotency
        // check; reuse it here so we don't double-fetch.
        $dayWise = $sumDayWise;
        if (strlen($dayWise) > $daysInMonth) {
            $dayWise = substr($dayWise, 0, $daysInMonth);
        }
        $dayWise[$today - 1] = 'P';

        // Recompute counters from the updated dayWise.
        $present = $absent = $leave = $holiday = $tardy = $working = 0;
        for ($i = 0, $n = strlen($dayWise); $i < $n; $i++) {
            $ch = $dayWise[$i];
            if      ($ch === 'P') { $present++; $working++; }
            elseif  ($ch === 'A') { $absent++;  $working++; }
            elseif  ($ch === 'L') { $leave++;   $working++; }
            elseif  ($ch === 'H') { $holiday++;             }
            elseif  ($ch === 'T') { $tardy++;   $working++; }
        }
        $pct = $working > 0 ? round(($present + $tardy) / $working * 100, 1) : 0;

        try {
            $this->fs->set('attendanceSummary', $summaryDocId, [
                'schoolId'    => $this->school_id,
                'studentId'   => $studentId,
                'studentName' => $name,
                'type'        => 'student',
                'className'   => Firestore_service::classKey($className),
                'section'     => Firestore_service::sectionKey($section),
                'month'       => $monthKey,
                'monthLabel'  => $attKey,
                'session'     => $this->session_year,
                'dayWise'     => $dayWise,
                'present'     => $present,
                'absent'      => $absent,
                'leave'       => $leave,
                'holiday'     => $holiday,
                'tardy'       => $tardy,
                'percentage'  => $pct,
                'updatedAt'   => date('c'),
                'updatedBy'   => $this->admin_id ?? 'kiosk',
            ], true);
        } catch (\Exception $e) {
            // Daily doc already wrote — log the summary failure but
            // still return success since at least the canonical-daily
            // store succeeded. The next bulk reconciler / monthly view
            // recomputation will pick the day up.
            log_message('error', 'scan_qr: attendanceSummary write failed: ' . $e->getMessage());
        }

        return $this->json_success([
            'code'         => 'success',
            'message'      => "Attendance marked Present for {$name}.",
            'student_id'   => $studentId,
            'student_name' => $name,
            'class'        => $className,
            'section'      => $section,
            'date'         => $date,
        ]);
    }

    /**
     * Renders the QR scan UI (manual paste for now; camera-based
     * scanner is a follow-up). Visible to users with mark-attendance
     * permission since they're the ones who'll actually be at the
     * door scanning IDs.
     */
    public function scan()
    {
        $this->_require_role(self::MARK_ROLES, 'attendance_scan');
        $this->load->view('include/header');
        $this->load->view('attendance/scan_qr');
        $this->load->view('include/footer');
    }

    /**
     * Student Leave management page
     */
    public function student_leaves()
    {
        $this->_require_role(self::VIEW_ROLES);
        $data['Classes'] = $this->_build_class_list();
        $this->load->view('include/header', $data);
        $this->load->view('attendance/student_leave', $data);
        $this->load->view('include/footer');
    }

    /* ================================================================
       GROUP I: STUDENT LEAVE MANAGEMENT
       ================================================================ */

    /**
     * List student leave applications.
     * POST: class (optional), section (optional), status_filter (optional: pending|approved|rejected|all)
     *
     * Teachers see leaves for their assigned classes only.
     * Admins see all.
     */
    public function list_student_leaves()
    {
        $this->_require_role(self::VIEW_ROLES, 'list_student_leaves');
        $classFilter   = trim((string) $this->input->post('class'));
        $sectionFilter = trim((string) $this->input->post('section'));
        $statusFilter  = trim((string) ($this->input->post('status_filter') ?: 'pending'));

        try {
            $conditions = [];
            if ($statusFilter !== '' && $statusFilter !== 'all') {
                $conditions[] = ['status', '==', $statusFilter];
            }
            $conditions[] = ['applicantType', '==', 'student'];

            // [Fix #6] Bounded read (cap 500) + server-side ordering by appliedAt.
            // Fall back to an unordered bounded read if the composite index for
            // (status/applicantType + appliedAt) isn't deployed, so the page never
            // hard-fails. The PHP usort below still guarantees final ordering.
            try {
                $docs = $this->fs->schoolWhere('leaveApplications', $conditions, 'appliedAt', 'DESC', 500);
            } catch (\Exception $eOrder) {
                $docs = $this->fs->schoolWhere('leaveApplications', $conditions, null, 'ASC', 500);
            }
        } catch (\Exception $e) {
            return $this->json_error('Failed to fetch leave applications: ' . $e->getMessage());
        }

        $leaves = [];
        foreach ($docs as $entry) {
            // [Fix #10] Removed dead duplicate $d assignment.
            $d = is_array($entry) ? ($entry['data'] ?? $entry) : null;
            $id = is_array($entry) ? ($d['id'] ?? '') : '';
            if (!is_array($d)) continue;

            // Filter by class/section if provided
            if ($classFilter && ($d['className'] ?? '') !== Firestore_service::classKey($classFilter)) continue;
            if ($sectionFilter && ($d['section'] ?? '') !== Firestore_service::sectionKey($sectionFilter)) continue;

            // [Fix #7] Scope to assigned classes for ALL non-admin teaching roles
            // (not just the literal 'Teacher' role) via their actual assignments.
            if (!$this->_leave_can_access($d['className'] ?? '', $d['section'] ?? '')) continue;

            $leaves[] = [
                'id'             => $id,
                'leaveId'        => $d['leaveId'] ?? $id,
                'studentId'      => $d['applicantId'] ?? '',
                'studentName'    => $d['applicantName'] ?? '',
                'className'      => $d['className'] ?? '',
                'section'        => $d['section'] ?? '',
                'leaveType'      => $d['leaveType'] ?? '',
                'startDate'      => $d['startDate'] ?? '',
                'endDate'        => $d['endDate'] ?? '',
                'numberOfDays'   => (int) ($d['numberOfDays'] ?? 0),
                'reason'         => $d['reason'] ?? '',
                'status'         => $d['status'] ?? 'pending',
                'appliedAt'      => $d['appliedAt'] ?? '',
                'approvedBy'     => $d['approvedBy'] ?? '',
                'remarks'        => $d['remarks'] ?? '',
            ];
        }

        // Sort by appliedAt descending
        usort($leaves, function ($a, $b) {
            return strcmp((string) ($b['appliedAt'] ?? ''), (string) ($a['appliedAt'] ?? ''));
        });

        return $this->json_success(['leaves' => $leaves]);
    }

    /**
     * Approve a student leave application.
     * POST: leave_id, remarks (optional)
     *
     * On approval:
     *   1. Update leaveApplications doc status → "approved"
     *   2. Update attendanceSummary dayWise: mark "L" for each leave day
     *   3. Recompute counts + percentage
     *   4. Fire push notification to parent
     */
    public function approve_student_leave()
    {
        $this->_require_role(self::MARK_ROLES, 'approve_student_leave');
        $leaveId = trim((string) $this->input->post('leave_id'));
        $remarks = trim((string) ($this->input->post('remarks') ?? ''));

        if ($leaveId === '') return $this->json_error('leave_id is required.');

        // Read the leave doc from Firestore
        $leave = null;
        try {
            $leave = $this->fs->get('leaveApplications', $leaveId);
        } catch (\Exception $e) {}
        if (!is_array($leave)) return $this->json_error('Leave application not found.');

        // [Fix C2] Cross-school IDOR guard — a leave from another tenant must be
        // indistinguishable from "not found", BEFORE any status/stamp/push.
        if (($leave['schoolId'] ?? '') !== $this->school_id) {
            return $this->json_error('Leave application not found.', 404);
        }

        // Status compared case-insensitively (accept legacy CapitalCase docs).
        if (strtolower((string) ($leave['status'] ?? '')) !== 'pending') {
            return $this->json_error('Leave is not in pending status.');
        }

        $studentId = $leave['applicantId'] ?? '';
        $startDate = $leave['startDate'] ?? '';
        $endDate   = $leave['endDate'] ?? '';
        $className = $leave['className'] ?? '';
        $section   = $leave['section'] ?? '';

        if ($studentId === '' || $startDate === '' || $endDate === '') {
            return $this->json_error('Invalid leave application data.');
        }

        // [Fix #5] Strict date validation — reject unparseable/out-of-order/over-cap
        // ranges with a clean error so a bad date can NEVER reach the stamp path as
        // a 500. createFromFormat is lenient (rolls over), so also require the
        // round-trip to equal the input.
        $sDt = \DateTime::createFromFormat('Y-m-d', $startDate);
        $eDt = \DateTime::createFromFormat('Y-m-d', $endDate);
        if (!$sDt || !$eDt
            || $sDt->format('Y-m-d') !== $startDate
            || $eDt->format('Y-m-d') !== $endDate) {
            return $this->json_error('Leave has invalid start/end dates.');
        }
        $sDt->setTime(0, 0, 0);
        $eDt->setTime(0, 0, 0);
        if ($eDt < $sDt) {
            return $this->json_error('End date cannot be before start date.');
        }
        $spanDays = (int) $sDt->diff($eDt)->days + 1; // inclusive
        if ($spanDays > 60) {
            return $this->json_error('Leave span exceeds the 60-day limit.');
        }

        // Past-date sanity guard — reject approval when start date is older than 60 days.
        // Stops late-night data tampering on ancient leave records; legitimate
        // backdated cases should go through the correction-request flow.
        $startTs = strtotime($startDate);
        if ($startTs && (time() - $startTs) > (60 * 86400)) {
            return $this->json_error('Cannot approve leave whose start date is older than 60 days.');
        }

        // [Fix #7] Scope to assigned classes for ALL non-admin teaching roles
        // (not just the literal 'Teacher' role). Admin roles bypass.
        if (!$this->_leave_can_access($className, $section)) {
            return $this->json_error('You are not assigned to this class/section.', 403);
        }

        // [Contract §6] No self-approval — approver id must differ from applicant id.
        if ((string) $this->admin_id !== '' && (string) $this->admin_id === (string) $studentId) {
            return $this->json_error('You cannot approve your own leave.', 403);
        }

        $approverName = $this->admin_name ?? $this->session->userdata('user_id') ?? 'system';

        // [Fix #4] Duplicate-approval / concurrency guard — re-read immediately
        // before the status flip and confirm the leave is STILL pending. This
        // narrows the window in which two concurrent approvals both flip + push.
        // (Residual: a true CAS-on-leave-doc flip would fully close it; the
        // authoritative summary write below already uses a CAS precondition.)
        try {
            $fresh = $this->fs->get('leaveApplications', $leaveId);
        } catch (\Exception $e) { $fresh = null; }
        if (!is_array($fresh) || strtolower((string) ($fresh['status'] ?? '')) !== 'pending') {
            return $this->json_error('Leave has already been processed.');
        }

        // [Fix H7] Flip status to approved FIRST but WITHOUT attendanceStamped=true.
        // If the stamp write fails below, the doc keeps attendanceStamped=false so
        // the reconciler (_process_approved_leaves) picks it up and retries.
        // attendanceStamped is set true ONLY after a confirmed-successful stamp.
        try {
            $this->fs->set('leaveApplications', $leaveId, [
                'status'            => 'approved',
                'approvedBy'        => $approverName,
                'approvedAt'        => date('c'),
                'remarks'           => $remarks,
                'attendanceStamped' => false,
            ], true);
        } catch (\Exception $e) {
            return $this->json_error('Failed to update leave status: ' . $e->getMessage());
        }

        // [Fix H6/H7] Stamp "L" for each working, unmarked day in the range.
        // Returns ['ok'=>bool,'days'=>int]; we honour the success flag.
        $stamp       = $this->_stamp_leave_on_attendance($studentId, $className, $section, $startDate, $endDate);
        $daysUpdated = (int) ($stamp['days'] ?? 0);

        if (!empty($stamp['ok'])) {
            // Confirmed-successful stamp → NOW it is safe to mark stamped.
            try {
                $this->fs->set('leaveApplications', $leaveId, ['attendanceStamped' => true], true);
            } catch (\Exception $e) {
                log_message('error', "Leave {$leaveId} stamped OK but attendanceStamped flag write failed: " . $e->getMessage());
            }
        } else {
            // Stamp failed — leave attendanceStamped=false so the reconciler retries.
            log_message('error', "Leave {$leaveId} approved but attendance stamp failed; reconciler will retry.");
        }

        // 3. Fire push notification to parent
        try {
            $this->load->library('push_service');
            $studentName = $leave['applicantName'] ?? $studentId;
            $this->push_service->sendToUser($studentId, [
                'title' => 'Leave Approved',
                'body'  => "Leave for {$studentName} ({$startDate} to {$endDate}) has been approved.",
                'data'  => [
                    'type'      => 'leave_approved',
                    'leave_id'  => $leaveId,
                    'startDate' => $startDate,
                    'endDate'   => $endDate,
                ],
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Leave approval push failed: ' . $e->getMessage());
        }

        return $this->json_success([
            'message'     => "Leave approved. {$daysUpdated} attendance day(s) marked as Leave.",
            'daysUpdated' => $daysUpdated,
        ]);
    }

    /**
     * Reject a student leave application.
     * POST: leave_id, remarks (required)
     */
    public function reject_student_leave()
    {
        $this->_require_role(self::MARK_ROLES, 'reject_student_leave');
        $leaveId = trim((string) $this->input->post('leave_id'));
        $remarks = trim((string) ($this->input->post('remarks') ?? ''));

        if ($leaveId === '') return $this->json_error('leave_id is required.');
        if ($remarks === '') return $this->json_error('Remarks are required when rejecting.');

        $leave = null;
        try {
            $leave = $this->fs->get('leaveApplications', $leaveId);
        } catch (\Exception $e) {}
        if (!is_array($leave)) return $this->json_error('Leave application not found.');

        // [Fix C2] Cross-school IDOR guard — reject before any status/push change.
        if (($leave['schoolId'] ?? '') !== $this->school_id) {
            return $this->json_error('Leave application not found.', 404);
        }

        // Status compared case-insensitively (accept legacy CapitalCase docs).
        if (strtolower((string) ($leave['status'] ?? '')) !== 'pending') {
            return $this->json_error('Leave is not in pending status.');
        }

        // [Fix #7] Scope to assigned classes for ALL non-admin teaching roles
        // (the reject path previously had NO class scoping at all).
        if (!$this->_leave_can_access($leave['className'] ?? '', $leave['section'] ?? '')) {
            return $this->json_error('You are not assigned to this class/section.', 403);
        }

        $studentId = $leave['applicantId'] ?? '';
        $rejecterName = $this->admin_name ?? $this->session->userdata('user_id') ?? 'system';

        // Update leave status
        try {
            $this->fs->set('leaveApplications', $leaveId, [
                'status'     => 'rejected',
                'approvedBy' => $rejecterName,
                'approvedAt' => date('c'),
                'remarks'    => $remarks,
            ], true);
        } catch (\Exception $e) {
            return $this->json_error('Failed to update leave status: ' . $e->getMessage());
        }

        // Push notification to parent
        try {
            $this->load->library('push_service');
            $studentName = $leave['applicantName'] ?? $studentId;
            $this->push_service->sendToUser($studentId, [
                'title' => 'Leave Rejected',
                'body'  => "Leave for {$studentName} ({$leave['startDate']} to {$leave['endDate']}) was rejected. Reason: {$remarks}",
                'data'  => [
                    'type'      => 'leave_rejected',
                    'leave_id'  => $leaveId,
                    'remarks'   => $remarks,
                ],
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Leave rejection push failed: ' . $e->getMessage());
        }

        return $this->json_success(['message' => 'Leave rejected.']);
    }

    /**
     * Stamp "L" on attendance dayWise for each day in a leave date range.
     * Handles leaves that span multiple months by updating each month's
     * attendanceSummary doc separately.
     *
     * @return array{ok:bool,days:int}  ok=false if ANY month's summary write
     *         failed (so callers can withhold attendanceStamped=true and let the
     *         reconciler retry). days = number of dayWise cells actually flipped.
     */
    private function _stamp_leave_on_attendance(
        string $studentId, string $className, string $section,
        string $startDate, string $endDate
    ): array {
        // [Fix #5] Never let unparseable/out-of-order dates reach the write path —
        // return a clean no-op failure instead of throwing (the `new \DateTime()`
        // of an invalid string would 500). Callers already validate; this is
        // defense-in-depth for the reconciler path too.
        $start = \DateTime::createFromFormat('Y-m-d', $startDate);
        $end   = \DateTime::createFromFormat('Y-m-d', $endDate);
        if (!$start || !$end
            || $start->format('Y-m-d') !== $startDate
            || $end->format('Y-m-d') !== $endDate) {
            return ['ok' => false, 'days' => 0];
        }
        $start->setTime(0, 0, 0);
        $end->setTime(0, 0, 0);
        if ($start > $end) return ['ok' => false, 'days' => 0];

        $updated = 0;
        $allOk   = true;
        $current = clone $start;

        // Group days by month
        $monthDays = [];
        while ($current <= $end) {
            $monthKey = $current->format('Y-m');
            $day = (int) $current->format('j');
            if (!isset($monthDays[$monthKey])) {
                $monthDays[$monthKey] = [
                    'monthNum' => (int) $current->format('n'),
                    'year'     => (int) $current->format('Y'),
                    'days'     => [],
                ];
            }
            $monthDays[$monthKey]['days'][] = $day;
            $current->modify('+1 day');
        }

        $hasCas = isset($this->firebase) && method_exists($this->firebase, 'firestoreGet');

        // For each month, read → modify → write the dayWise string.
        foreach ($monthDays as $monthKey => $info) {
            $docId       = $this->fs->docId2($studentId, $monthKey);
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $info['monthNum'], $info['year']);

            // [Fix #2/H6] Weekend + holiday calendar from the SAME canonical source
            // the marking paths use (Sundays + calendarEvents holidays). Days keyed
            // here must NEVER be stamped 'L'.
            $nonWorking = $this->_resolve_non_working_days($info['monthNum'], $info['year']);

            // [Fix #4] CAS read-modify-write retry loop (mirrors _syncDailyToFirestore)
            // so a concurrent attendance writer converging on the same summary doc
            // can't be silently clobbered by this stamp.
            $maxTries = $hasCas ? 4 : 1;
            $monthOk  = false;
            for ($try = 0; $try < $maxTries; $try++) {
                $doc = null;
                $precondition = null;
                if ($hasCas) {
                    try { $doc = $this->firebase->firestoreGet('attendanceSummary', $docId); } catch (\Exception $e) {}
                    $ut = is_array($doc) ? (string) ($doc['__updateTime'] ?? '') : '';
                    $precondition = ($ut !== '') ? ['updateTime' => $ut] : ['exists' => false];
                } else {
                    try { $doc = $this->fs->get('attendanceSummary', $docId); } catch (\Exception $e) {}
                }

                $dayWise = ($doc && is_string($doc['dayWise'] ?? null) && $doc['dayWise'] !== '')
                    ? str_pad($doc['dayWise'], $daysInMonth, 'V')
                    : str_repeat('V', $daysInMonth);

                // [Fix H6] Stamp 'L' ONLY on an unmarked ('V' or blank) WORKING day.
                // Never overwrite a real mark (P/A/T/H/L); never a weekend/holiday.
                $changed      = false;
                $monthUpdated = 0;
                foreach ($info['days'] as $day) {
                    if ($day < 1 || $day > $daysInMonth) continue;
                    if (isset($nonWorking[$day])) continue;                 // weekend/holiday
                    $existing = $dayWise[$day - 1];
                    if ($existing !== 'V' && $existing !== ' ') continue;   // only overwrite unmarked
                    $dayWise[$day - 1] = 'L';
                    $changed = true;
                    $monthUpdated++;
                }

                if (!$changed) { $monthOk = true; break; } // nothing to do = success

                $name = (string) ($doc['studentName'] ?? '');
                $ok = $this->_syncStudentSummaryToFirestore(
                    $studentId, $className, $section,
                    $info['monthNum'], $info['year'], $dayWise, $name, $precondition
                );
                if ($ok) { $updated += $monthUpdated; $monthOk = true; break; }
                // Precondition conflict (or transient) — brief backoff, then re-read.
                if ($try < $maxTries - 1) usleep(40000 * ($try + 1));
            }
            if (!$monthOk) $allOk = false;
        }

        return ['ok' => $allOk, 'days' => $updated];
    }

    /**
     * [Fix #7] Leave-scope check that binds ALL non-admin teaching roles to their
     * class assignments — not just the literal 'Teacher' role that
     * MY_Controller::_teacher_can_access() scopes.
     *
     * A 'Class Teacher' (or any other teaching-role string) previously slipped
     * through _teacher_can_access() (which no-ops for role !== 'Teacher') and
     * could act on any class. Here scoping is by ACTUAL assignments: an admin-level
     * role bypasses; any non-admin user WITH teaching assignments is restricted to
     * exactly those class+sections; a non-admin user with NO assignments (e.g. an
     * Academic Coordinator / HR Manager granted the module) keeps broad access,
     * preserving prior behaviour and not locking them out.
     */
    private function _leave_can_access(string $className, string $section): bool
    {
        if ($this->_is_admin_role()) return true;
        $assignments = $this->_get_teacher_assignments();
        if (empty($assignments)) return true; // broad, non-class-scoped role
        $sec   = str_replace('Section ', '', $section);
        $csKey = $this->_cs_norm($className, "Section {$sec}");
        return isset($assignments[$csKey]);
    }

    /**
     * Check if the current user has an admin-level role.
     */
    private function _is_admin_role(): bool
    {
        $role = $this->admin_role ?? $this->session->userdata('admin_role') ?? '';
        return in_array($role, ['Admin', 'admin', 'School Super Admin', 'Principal', 'Vice Principal']);
    }

    /* ================================================================
       PRIVATE HELPERS
       ================================================================ */

    /**
     * Resolve calendar year for a month within the academic session
     * April–December → session start year, January–March → session end year
     */
    private $_academic_start_month = null;

    private function _resolve_year(string $month): int
    {
        $parts = explode('-', $this->session_year);
        $startYear = (int) ($parts[0] ?? date('Y'));
        $endYear   = (int) ($parts[1] ?? ($startYear + 1));

        // Handle 2-digit years (e.g. "25-26" → 2025, 2026)
        if ($startYear < 100) $startYear += 2000;
        if ($endYear < 100)   $endYear += 2000;

        // Read configurable academic year start month (default April = 4)
        if ($this->_academic_start_month === null) {
            // Zero-RTDB: default the academic year start to April (4). The legacy
            // RTDB read (Schools/.../Config/AcademicYear/start_month) is removed;
            // make this Firestore-configurable later if a non-April school needs it.
            $this->_academic_start_month = 4;
        }

        // Check if the session has actually started.
        // Session 2026-27 with start_month=4 (April) starts in April 2026.
        // If today is before that, the session hasn't begun yet and ALL months
        // should map to the current calendar year.
        //
        // Example: Session "2026-27", today is March 2026 (before April 2026)
        //   → Session not started → January/February/March → 2026 (not 2027)
        //
        // Once session starts (April 2026+), standard logic applies:
        //   → April-December → startYear (2026)
        //   → January-March  → endYear (2027)
        $currentYear  = (int) date('Y');
        $currentMonth = (int) date('n');
        $sessionStarted = ($currentYear > $startYear)
            || ($currentYear === $startYear && $currentMonth >= $this->_academic_start_month);

        if (!$sessionStarted) {
            return $currentYear;
        }

        $monthNum = $this->month_map[$month] ?? 0;
        return ($monthNum >= $this->_academic_start_month) ? $startYear : $endYear;
    }

    /**
     * Get Sunday day numbers for a month
     */
    private function _get_sundays(int $year, int $month): array
    {
        $sundays = [];
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        for ($d = 1; $d <= $daysInMonth; $d++) {
            if (date('w', mktime(0, 0, 0, $month, $d, $year)) == 0) {
                $sundays[] = $d;
            }
        }
        return $sundays;
    }

    /**
     * Get holiday day numbers for a month from config
     */
    /**
     * HC-3: resolve non-working days (Sundays + holidays) for a month.
     * Holidays come ONLY from the canonical Holiday_service (calendarEvents);
     * the Sunday + merge logic lives in the pure helper nw_days_from_holidays().
     * This is the single controller-side holiday-resolution point — the
     * attendance_helper stays a pure utility (no service/RTDB access).
     */
    private function _resolve_non_working_days(int $monthNum, int $year): array
    {
        $this->load->helper('attendance');
        try {
            $this->load->library('holiday_service');
            $this->holiday_service->init($this->fs, (string) $this->school_id, (string) $this->session_year);
            $holidayDayMap = $this->holiday_service->holidays_in_month($year, $monthNum);
        } catch (\Throwable $e) {
            log_message('error', 'Attendance::_resolve_non_working_days HC-3 failed: ' . $e->getMessage());
            $holidayDayMap = [];
        }
        return nw_days_from_holidays($holidayDayMap, $monthNum, $year);
    }

    /**
     * HC-3: holidays (day => name) for a month for the register grid views.
     * Canonical source = calendarEvents via Holiday_service (was RTDB).
     */
    private function _get_holidays_for_month(string $monthName, int $year): array
    {
        $monthNum = $this->month_map[$monthName] ?? 0;
        if ($monthNum < 1) return [];
        try {
            $this->load->library('holiday_service');
            $this->holiday_service->init($this->fs, (string) $this->school_id, (string) $this->session_year);
            return $this->holiday_service->holidays_in_month($year, $monthNum);
        } catch (\Throwable $e) {
            log_message('error', 'Attendance::_get_holidays_for_month HC-3 failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Validate X-API-Key header, return school/device info or false
     */
    private function _validate_api_key()
    {
        $rawKey = $this->input->get_request_header('X-API-Key', true);
        if (!$rawKey) {
            $rawKey = $this->input->get_request_header('X-Api-Key', true);
        }
        if (!$rawKey || strlen($rawKey) < 16) return false;

        // ── C-03 FIX: Rate limit failed API key attempts — max 20 per IP per 15 min.
        // Phase 6F: Cache fixed-window counter (Redis → file), replacing the legacy
        // RTDB rate-limit node. Ephemeral runtime state — no RTDB.
        $clientIp = $this->input->ip_address();
        $ipKey    = preg_replace('/[^a-zA-Z0-9]/', '_', $clientIp);
        $rlKey    = "apikey_fail_{$ipKey}";
        if ((int) $this->_cache_get($rlKey) >= self::MAX_FAILED_ATTEMPTS) {
            log_message('error', "API key rate limit exceeded for IP: {$clientIp}");
            return false;
        }

        $keyHash = hash('sha256', $rawKey);

        // Check cache first (Redis or file)
        $cacheKey = "api_key_{$keyHash}";
        $cached = $this->_cache_get($cacheKey);
        if (is_array($cached) && !empty($cached['school_name'])) {
            return $cached;
        }

        // Phase 6C — Firestore key→device index (sole canonical source).
        // RTDB System/API_Keys fallback and the X-School header-hint fallback
        // have been removed; device auth resolves against Firestore only.
        try {
            $fsLookup = $this->fs->get('attendanceDeviceKeys', $keyHash);
        } catch (\Exception $e) { $fsLookup = null; }
        if (is_array($fsLookup) && !empty($fsLookup['schoolName']) && !empty($fsLookup['deviceId'])) {
            $lookup = [
                'device_id'   => $fsLookup['deviceId'],
                'school_name' => $fsLookup['schoolName'],
                'school_id'   => $fsLookup['schoolId'] ?? '',
            ];
            $this->_cache_set($cacheKey, $lookup, self::API_KEY_CACHE_TTL);
            return $lookup;
        }

        // Record failed attempt (Cache fixed-window; TTL = RATE_LIMIT_WINDOW).
        $fails = (int) $this->_cache_get($rlKey);
        $this->_cache_set($rlKey, $fails + 1, self::RATE_LIMIT_WINDOW);

        return false;
    }

    /**
     * @deprecated R5 (Firestore-only roster migration) — no live callers
     *             remain inside Attendance.php. Every roster derivation
     *             now goes through `_get_section_students()` which is
     *             backed by `Roster_helper::for_class()` (Strategy 0).
     *             Kept as a one-release safety net in case any forgotten
     *             call path surfaces; safe to delete after R5 is
     *             verified in production.
     *
     * Extract student list from a Students node (legacy RTDB shape).
     * Handles two data layouts:
     *   1. Standard: Students/List/{id: name} + Students/{id}/{data}
     *   2. No-List:  Students/{id}/{Name: "...", ...} (List sub-key missing)
     *
     * Returns associative array: [ studentId => studentName, ... ]
     */
    private function _extract_student_list(array $studentsNode): array
    {
        // Prefer the explicit List index
        if (!empty($studentsNode['List']) && is_array($studentsNode['List'])) {
            return $studentsNode['List'];
        }

        // Fallback: build list from student data nodes
        $list = [];
        foreach ($studentsNode as $key => $val) {
            // Skip known non-student keys
            if ($key === 'List' || is_numeric($key)) continue;
            // Student nodes are arrays with a Name field
            if (is_array($val) && isset($val['Name'])) {
                $list[$key] = (string) $val['Name'];
            }
        }
        return $list;
    }

    /**
     * Resolve the section root path, supporting both new and legacy formats.
     *
     * New format:    Schools/{school}/{session}/Class 8th/Section A
     * Legacy format: Schools/{school}/{session}/Class 8th 'A'
     *
     * Checks new format first; falls back to legacy if no Students/List found.
     * Caches per class+section so subsequent calls don't re-read Firebase.
     */
    private $_section_root_cache = [];

    /**
     * Build a section identifier for Firestore queries.
     * Returns a composite key used for attendance document lookups.
     * No longer queries RTDB — uses Firestore sections collection.
     */
    /**
     * Resolve the canonical RTDB section root for a class+section.
     *
     * Returns: `Schools/{schoolName}/{sessionYear}/Class 8th/Section A`
     *
     * Used by every reader/writer in this controller as the base path
     * for `{secRoot}/Students/{studentId}/Attendance/{attKey}` style
     * accesses. Both `$class` and `$section` are normalized through
     * `Firestore_service::classKey()` / `sectionKey()` so callers can
     * pass either the bare value ("8th", "A") or the prefixed value
     * ("Class 8th", "Section A") and the result is identical.
     */
    /**
     * Phase 8b — process pending push requests written by the teacher
     * app to the `pushRequests` Firestore collection. Called
     * automatically from dashboard_stats and process_push_requests.
     */
    private function _process_pending_push_requests(): void
    {
        // Phase 10: teacher app writes push requests to Firestore
        // `pushRequests` collection (security rules now allow it).
        // We read pending docs, fire the push, then delete them.
        try {
            $docs = $this->fs->schoolWhere('pushRequests', [
                ['status', '==', 'pending'],
            ]);
        } catch (\Exception $e) { return; }

        foreach ($docs as $entry) {
            $d = $entry['data'] ?? $entry;
            $d = is_array($entry) ? ($entry['data'] ?? $entry) : null;
            $docId = is_array($entry) ? ($d['id'] ?? '') : '';
            if (!is_array($d)) continue;

            $studentId = $d['studentId'] ?? '';
            $mark      = strtoupper($d['mark'] ?? '');
            $source    = $d['source'] ?? '';
            $class     = $d['class'] ?? '';
            $section   = $d['section'] ?? '';
            $day       = (int) ($d['day'] ?? 0);
            $month     = $d['month'] ?? date('F');

            // Phase 10f: handle leave approve/reject push requests from teacher
            if ($source === 'teacher_leave_approve' || $source === 'teacher_leave_reject') {
                $this->_process_leave_push_request($d, $source);
            } elseif ($source === 'homework_created') {
                $this->_process_homework_created_push($d);
            } elseif ($source === 'homework_reviewed') {
                $this->_process_homework_reviewed_push($d);
            } elseif ($studentId !== '' && in_array($mark, ['A', 'T']) && $day >= 1) {
                $year   = $this->_resolve_year($month);
                $attKey = "{$month} {$year}";
                $this->_fire_single_student_event($studentId, $class, $section, $mark, $day, $attKey);
            }

            // Delete processed request from Firestore
            if ($docId !== '') {
                try { $this->fs->remove('pushRequests', $docId); } catch (\Exception $e) {}
            }
        }
    }

    /**
     * Phase 10: process approved student leaves that haven't been
     * stamped on attendance yet. The teacher app sets
     * `attendanceStamped: false` when approving; we read those,
     * stamp "L" on the dayWise, fire push, and mark as stamped.
     */
    /**
     * Phase 10f: handle a leave approve/reject push request from the teacher app.
     * Fires FCM push to the parent immediately.
     */
    private function _process_leave_push_request(array $d, string $source): void
    {
        $studentId = $d['studentId'] ?? '';
        $startDate = $d['startDate'] ?? '';
        $endDate   = $d['endDate']   ?? '';
        $remarks   = $d['remarks']   ?? '';
        $markedBy  = $d['markedBy']  ?? '';

        if ($studentId === '') return;

        try {
            $this->load->library('push_service');

            // Get student name from the leave doc or roster
            $leaveId = $d['leaveId'] ?? '';
            $studentName = $studentId;
            if ($leaveId !== '') {
                try {
                    $leaveDoc = $this->fs->get('leaveApplications', $leaveId);
                    $studentName = $leaveDoc['applicantName'] ?? $studentId;
                } catch (\Exception $e) {}
            }

            if ($source === 'teacher_leave_approve') {
                $this->push_service->sendToUser($studentId, [
                    'title' => 'Leave Approved',
                    'body'  => "Leave for {$studentName} ({$startDate} to {$endDate}) has been approved by {$markedBy}.",
                    'data'  => [
                        'type'      => 'leave_approved',
                        'leave_id'  => $leaveId,
                        'startDate' => $startDate,
                        'endDate'   => $endDate,
                    ],
                ]);
            } elseif ($source === 'teacher_leave_reject') {
                $this->push_service->sendToUser($studentId, [
                    'title' => 'Leave Rejected',
                    'body'  => "Leave for {$studentName} ({$startDate} to {$endDate}) was rejected. Reason: {$remarks}",
                    'data'  => [
                        'type'      => 'leave_rejected',
                        'leave_id'  => $leaveId,
                        'remarks'   => $remarks,
                    ],
                ]);
            }
        } catch (\Exception $e) {
            log_message('error', "Leave push request processing failed: " . $e->getMessage());
        }
    }

    /**
     * HW-1: Push notification to ALL parents in a class when homework is created.
     */
    private function _process_homework_created_push(array $d): void
    {
        $class      = $d['class'] ?? '';
        $section    = $d['section'] ?? '';
        $title      = $d['title'] ?? 'New Homework';
        $subject    = $d['subject'] ?? '';
        $dueDate    = $d['dueDate'] ?? '';
        $markedBy   = $d['markedBy'] ?? '';

        if ($class === '' || $section === '') return;

        try {
            $this->load->library('push_service');
            $students = $this->_get_section_students($class, $section);

            foreach ($students as $studentId => $name) {
                $this->push_service->sendToUser((string) $studentId, [
                    'title' => "New Homework: {$subject}",
                    'body'  => "{$title} — due {$dueDate}. Assigned by {$markedBy}.",
                    'data'  => [
                        'type'       => 'homework_created',
                        'homeworkId' => $d['homeworkId'] ?? '',
                        'subject'    => $subject,
                    ],
                ]);
            }
        } catch (\Exception $e) {
            log_message('error', 'Homework created push failed: ' . $e->getMessage());
        }
    }

    /**
     * HW-2: Push notification to a specific parent when homework is graded.
     */
    private function _process_homework_reviewed_push(array $d): void
    {
        $studentId = $d['studentId'] ?? '';
        $remark    = $d['remark'] ?? '';
        $score     = $d['score'] ?? '';
        $markedBy  = $d['markedBy'] ?? '';

        if ($studentId === '') return;

        try {
            $this->load->library('push_service');
            $scoreText = ($score !== '' && (int) $score >= 0) ? "Score: {$score}. " : '';
            $this->push_service->sendToUser($studentId, [
                'title' => 'Homework Graded',
                'body'  => "{$scoreText}Reviewed by {$markedBy}." . ($remark ? " \"{$remark}\"" : ''),
                'data'  => [
                    'type'       => 'homework_reviewed',
                    'homeworkId' => $d['homeworkId'] ?? '',
                ],
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Homework reviewed push failed: ' . $e->getMessage());
        }
    }

    private function _process_approved_leaves(): void
    {
        // [Fix #6] Bounded read (cap 200 per pass) + server-side ordering; fall
        // back to unordered bounded read if the composite index isn't deployed.
        $conds = [
            ['status',            '==', 'approved'],
            ['attendanceStamped', '==', false],
            ['applicantType',     '==', 'student'],
        ];
        try {
            $docs = $this->fs->schoolWhere('leaveApplications', $conds, 'appliedAt', 'DESC', 200);
        } catch (\Exception $e) {
            try {
                $docs = $this->fs->schoolWhere('leaveApplications', $conds, null, 'ASC', 200);
            } catch (\Exception $e2) { return; }
        }

        foreach ($docs as $entry) {
            // [Fix #10] Removed dead duplicate $d assignment.
            $d = is_array($entry) ? ($entry['data'] ?? $entry) : null;
            $docId = is_array($entry) ? ($d['id'] ?? '') : '';
            if (!is_array($d) || $docId === '') continue;

            $studentId = $d['applicantId'] ?? '';
            $startDate = $d['startDate']   ?? '';
            $endDate   = $d['endDate']     ?? '';
            $className = $d['className']   ?? '';
            $section   = $d['section']     ?? '';

            if ($studentId === '' || $startDate === '' || $endDate === '') continue;

            // [Fix H7] Stamp "L" — only mark attendanceStamped + push on a
            // confirmed-successful stamp, so a failed stamp is retried next pass.
            $stamp = $this->_stamp_leave_on_attendance($studentId, $className, $section, $startDate, $endDate);
            if (empty($stamp['ok'])) continue;

            // Fire push to parent
            try {
                $this->load->library('push_service');
                $studentName = $d['applicantName'] ?? $studentId;
                $this->push_service->sendToUser($studentId, [
                    'title' => 'Leave Approved',
                    'body'  => "Leave for {$studentName} ({$startDate} to {$endDate}) has been approved.",
                    'data'  => [
                        'type'      => 'leave_approved',
                        'leave_id'  => $docId,
                        'startDate' => $startDate,
                        'endDate'   => $endDate,
                    ],
                ]);
            } catch (\Exception $e) {}

            // Mark as stamped so we don't process it again
            try {
                $this->fs->set('leaveApplications', $docId, [
                    'attendanceStamped' => true,
                ], true);
            } catch (\Exception $e) {}
        }
    }

    private function _resolve_section_root(string $class, string $section): string
    {
        $classKey   = Firestore_service::classKey($class);     // "Class 8th"
        $sectionKey = Firestore_service::sectionKey($section); // "Section A"
        return "Schools/{$this->school_name}/{$this->session_year}/{$classKey}/{$sectionKey}";
    }

    /**
     * Get the roster for a class+section from Firestore.
     *
     * Tries strategies in order before giving up:
     *
     *   0. Roster_helper (R5 canonical) — Active students via the
     *      compound `schoolId+className+section+status` index.
     *   1. Canonical query: `students` where Class+Section+Status=Active
     *      (PascalCase legacy fields — kept as a safety net for any
     *      doc shape that didn't go through Entity_firestore_sync).
     *   2. Status-relaxed:  `students` where Class+Section (any Status)
     *   3. Derived roster:  `attendanceSummary` where className+section+type=student
     *      (any student who has ever been marked in this section)
     *
     * Strategy 3 is the safety net: even if a school's `students`
     * collection isn't fully populated, any student who has been
     * marked at least once will be discoverable through their
     * attendance summary document.
     *
     * Returns [studentId => name, ...]
     */
    private function _get_section_students(string $class, string $section): array
    {
        $classPrefixed   = Firestore_service::classKey($class);
        $sectionPrefixed = Firestore_service::sectionKey($section);

        // ── Strategy 0: Roster_helper (R5 canonical Firestore source) ──
        // Uses the compound `schoolId+className+section+status` index
        // and the same `[uid => fields]` shape every other R5-migrated
        // call site uses. Flatten to the legacy `[uid => name]` map
        // expected by every caller of _get_section_students.
        try {
            if (isset($this->roster) && method_exists($this->roster, 'for_class')) {
                $rosterFull = $this->roster->for_class($classPrefixed, $sectionPrefixed);
                if (!empty($rosterFull)) {
                    $list = [];
                    foreach ($rosterFull as $uid => $fields) {
                        $list[$uid] = is_array($fields)
                            ? (string) ($fields['Name'] ?? $uid)
                            : (string) $uid;
                    }
                    return $list;
                }
            }
        } catch (\Exception $e) { /* fall through to strategy 1 */ }

        // ── Strategy 1: canonical students collection (Active only) ──
        try {
            $studentDocs = $this->fs->schoolWhere('students', [
                ['Class',   '==', $classPrefixed],
                ['Section', '==', $sectionPrefixed],
                ['Status',  '==', 'Active'],
            ], 'Name', 'ASC');
            $list = $this->_extractRosterFromStudentDocs($studentDocs);
            if (!empty($list)) return $list;
        } catch (\Exception $e) { /* try next strategy */ }

        // ── Strategy 2: students collection without Status filter ──
        // Catches docs whose `Status` field is missing, lowercase,
        // "active", or any value other than the canonical "Active".
        try {
            $studentDocs = $this->fs->schoolWhere('students', [
                ['Class',   '==', $classPrefixed],
                ['Section', '==', $sectionPrefixed],
            ], 'Name', 'ASC');
            $list = $this->_extractRosterFromStudentDocs($studentDocs);
            if (!empty($list)) return $list;
        } catch (\Exception $e) { /* try next strategy */ }

        // ── Strategy 3: derive roster from attendanceSummary ──
        // Any student with a summary doc in this section is, by
        // definition, enrolled. Works even when the `students`
        // collection has no entry for them yet.
        //
        // Existing summary docs may not carry `studentName`, so we
        // backfill names from the `students` collection in a single
        // unfiltered query — works even when the per-student docs
        // have a Class/Section that doesn't match the canonical key
        // (which is exactly why strategies 1 and 2 missed them).
        try {
            $sumDocs = $this->fs->schoolWhere('attendanceSummary', [
                ['className', '==', $classPrefixed],
                ['section',   '==', $sectionPrefixed],
                ['type',      '==', 'student'],
            ]);
            $list = [];
            foreach ($sumDocs as $entry) {
                $d = is_array($entry) ? ($entry['data'] ?? $entry) : null;
                if (!is_array($d)) continue;
                $sid  = $d['studentId']   ?? '';
                $name = $d['studentName'] ?? '';
                if ($sid !== '') $list[$sid] = $name;
            }

            if (!empty($list)) {
                // Backfill names from the school's `students` collection.
                // One query with no Class/Section/Status filters so we
                // capture every student doc regardless of field shape.
                try {
                    $allStudentDocs = $this->fs->schoolWhere('students', []);
                    $nameMap = [];
                    foreach ($allStudentDocs as $doc) {
                        $d = $doc['data'] ?? $doc;
                        $s = is_array($doc) ? ($doc['data'] ?? $doc) : null;
                        if (!is_array($s)) continue;
                        $uid = $s['User Id'] ?? $s['studentId'] ?? ($d['id'] ?? '');
                        if ($uid === '') continue;
                        $nm = $s['Name'] ?? $s['name'] ?? '';
                        if ($nm !== '') $nameMap[$uid] = $nm;
                    }
                    foreach ($list as $sid => $existingName) {
                        if ($existingName === '' && isset($nameMap[$sid])) {
                            $list[$sid] = $nameMap[$sid];
                        }
                    }
                } catch (\Exception $e) { /* names stay as IDs */ }

                // Final fallback: any name still empty → use studentId
                foreach ($list as $sid => $name) {
                    if ($name === '') $list[$sid] = $sid;
                }

                return $list;
            }
        } catch (\Exception $e) { /* fall through */ }

        return [];
    }

    /**
     * Helper: turn a list of `students`-collection doc envelopes
     * into a flat `[studentId => name]` map. Accepts any of the
     * legacy field-name variants (`User Id` / `studentId`,
     * `Name` / `name`).
     */
    private function _extractRosterFromStudentDocs(array $docs): array
    {
        $list = [];
        foreach ($docs as $doc) {
            $s = is_array($doc) ? ($doc['data'] ?? $doc) : null;
            if (!is_array($s)) continue;
            $uid = $s['User Id'] ?? $s['studentId'] ?? ($doc['id'] ?? '');
            if ($uid === '') continue;
            $list[$uid] = $s['Name'] ?? $s['name'] ?? $uid;
        }
        return $list;
    }

    /**
     * Build class/section list from session tree.
     * Supports both new format (Class 8th/Section A) and legacy (Class 8th 'A').
     * Results are file-cached for API_KEY_CACHE_TTL seconds to avoid repeated Firebase reads.
     */
    private $_class_list_cache = null;

    private function _build_class_list(): array
    {
        // In-memory cache for the current request
        if ($this->_class_list_cache !== null) {
            return $this->_class_list_cache;
        }

        // Shared cache (Redis or file)
        $cacheKey = "class_list_{$this->school_name}_{$this->session_year}";
        $cached = $this->_cache_get($cacheKey);
        if (is_array($cached) && !empty($cached)) {
            $this->_class_list_cache = $cached;
            return $cached;
        }

        // Read from Firestore sections collection
        $sectionDocs = $this->fs->schoolWhere('sections', []);
        $classes = [];
        $seen    = [];

        foreach ($sectionDocs as $doc) {
            $sd = $doc['data'];
            $classKey = $sd['className'] ?? '';
            $sectionLetter = str_replace('Section ', '', $sd['section'] ?? '');
            if (!$classKey || !$sectionLetter) continue;

            $fp = "{$classKey}|{$sectionLetter}";
            if (!isset($seen[$fp])) {
                $seen[$fp] = true;
                $classes[] = [
                    'class_name' => $classKey,
                    'section'    => $sectionLetter,
                ];
            }
        }

        // Legacy format removed — Firestore sections collection handles all formats

        // Cache to shared layer and in-memory
        $this->_class_list_cache = $classes;
        $this->_cache_set($cacheKey, $classes, self::API_KEY_CACHE_TTL);

        return $classes;
    }

    /**
     * Compute P/A/L/H/T/V counts from an attendance string
     */
    private function _compute_month_stats(string $attStr): array
    {
        $stats = ['P' => 0, 'A' => 0, 'L' => 0, 'H' => 0, 'T' => 0, 'V' => 0];
        for ($i = 0; $i < strlen($attStr); $i++) {
            $ch = strtoupper($attStr[$i]);
            if (isset($stats[$ch])) {
                $stats[$ch]++;
            } else {
                $stats['V']++;
            }
        }
        return $stats;
    }

    // ====================================================================
    //  DATE GOVERNANCE (future block + backdated approval)
    // ====================================================================

    /** @var array Per-request memo of merged attendance settings, keyed by schoolId */
    private $_attSettingsCache = [];

    /**
     * Merge a raw attendanceSettings document over the canonical defaults.
     * Guarantees the general-settings keys and a `rules` sub-map are present.
     */
    private function _merge_attendance_settings_defaults(array $doc): array
    {
        $defaults = [
            'late_threshold_student'   => '08:30',
            'late_threshold_staff'     => '09:00',
            'working_days'             => ['Mon','Tue','Wed','Thu','Fri','Sat'],
            'biometric_enabled'        => false,
            'rfid_enabled'             => false,
            'face_recognition_enabled' => false,
            'rules'                    => [],
        ];
        $merged = array_merge($defaults, $doc);
        if (!is_array($merged['rules'] ?? null)) $merged['rules'] = [];
        return $merged;
    }

    /**
     * Reusable, Firestore-canonical reader for Attendance Settings & Rules
     * (Phase 6B). Reads attendanceSettings/{schoolId} through a file-cache
     * read-through layer, then a per-request memo. Firestore is the single
     * source of truth; the cache is a pure accelerator invalidated on save.
     *
     * @param string|null $schoolId  Target school (defaults to the session's).
     *                               api_punch passes the API-key school id.
     */
    private function _get_attendance_settings(?string $schoolId = null): array
    {
        $sid = $schoolId ?: $this->school_id;

        if (isset($this->_attSettingsCache[$sid])) {
            return $this->_attSettingsCache[$sid];
        }

        $this->load->driver('cache', ['adapter' => 'file']);
        $cacheKey = 'att_settings_' . $sid;
        $settings = $this->cache->get($cacheKey);

        if (!is_array($settings)) {
            $doc = null;
            try {
                $doc = $this->fs->get('attendanceSettings', $sid);
            } catch (\Throwable $e) {
                log_message('error', 'attendanceSettings read failed for ' . $sid . ': ' . $e->getMessage());
                $doc = null;
            }
            $settings = $this->_merge_attendance_settings_defaults(is_array($doc) ? $doc : []);
            $this->cache->save($cacheKey, $settings, self::ATT_SETTINGS_CACHE_TTL);
        }

        $this->_attSettingsCache[$sid] = $settings;
        return $settings;
    }

    /**
     * Invalidate the read-through cache + per-request memo after a settings
     * write, so the next read reflects the new canonical Firestore state.
     */
    private function _invalidate_attendance_settings_cache(?string $schoolId = null): void
    {
        $sid = $schoolId ?: $this->school_id;
        $this->load->driver('cache', ['adapter' => 'file']);
        $this->cache->delete('att_settings_' . $sid);
        unset($this->_attSettingsCache[$sid]);
    }

    /**
     * Load attendance governance rules (Phase 6B: sourced from the shared
     * Firestore attendanceSettings document's `rules` sub-map).
     */
    private function _att_rules(): array
    {
        $rules = $this->_get_attendance_settings()['rules'] ?? [];
        return is_array($rules) ? $rules : [];
    }

    /**
     * Validate a single-day mark for date governance.
     * Returns: null if OK, or error string/array to return to client.
     */
    private function _check_day_governance(int $day, int $monthNum, int $year, string $mark, array $postData): ?array
    {
        if ($mark === 'H' || $mark === 'V') return null; // holidays/vacant skip governance

        $rules = $this->_att_rules();
        $pastLimit = (int)($rules['allow_past_edit_days'] ?? 0);
        $requireApproval = !empty($rules['require_approval_for_backdated']);

        // Block future
        if (att_is_future_date($day, $monthNum, $year)) {
            return ['error' => 'Cannot mark attendance for future dates.'];
        }

        // Past date handling
        if (att_is_past_date($day, $monthNum, $year)) {
            // Check edit limit
            if ($pastLimit > 0 && !att_is_past_within_limit($day, $monthNum, $year, $pastLimit)) {
                return ['error' => "Cannot edit attendance older than {$pastLimit} days."];
            }

            // Require approval
            if ($requireApproval) {
                return ['needs_approval' => true, 'data' => $postData];
            }
        }

        return null; // today or past-within-limit with no approval required
    }

    // Legacy RTDB PendingApproval correction subsystem RETIRED (Component 3) — superseded by the Firestore correction flow (correction_submit / correction_list / correction_decide over attendanceCorrectionRequests).

    // ====================================================================
    //  ATTENDANCE LOCK (prevents edits after payroll)
    //
    //  R3 (Stream B): canonical store is Firestore collection
    //  `staffAttendanceLocks`, doc id `{schoolId}_{session}_{monthKey}`,
    //  schema { schoolId, session, month, isLocked, lockedBy, lockedAtMs,
    //  unlockedBy?, unlockedAtMs? }. The dead `_check_staff_att_lock`
    //  helper (RTDB read; no callers) was removed at R3. The write-gate
    //  is enforced by Staff_attendance_writer via Lock_cache::is_locked();
    //  payroll/month-close must call Lock_cache::is_locked_live().
    // ====================================================================

    /**
     * POST — Lock staff attendance for a month (called after payroll finalization).
     * Writes a Firestore staffAttendanceLocks doc and invalidates Lock_cache.
     */
    public function lock_staff_attendance()
    {
        $this->_require_role(self::MANAGE_ROLES, 'lock_staff_att');
        $month = trim((string) $this->input->post('month'));
        if (!$month) return $this->json_error('Month is required.');

        $year     = $this->_resolve_year($month);
        $monthNum = (int) date('n', strtotime("1 {$month} {$year}"));
        if ($monthNum < 1 || $monthNum > 12) {
            return $this->json_error('Invalid month name.');
        }
        $monthKey = sprintf('%04d-%02d', $year, $monthNum);
        $attKey   = "{$month} {$year}";

        $docId = "{$this->school_id}_{$this->session_year}_{$monthKey}";

        try {
            $this->firebase->firestoreSet('staffAttendanceLocks', $docId, [
                'schoolId'   => $this->school_id,
                'session'    => $this->session_year,
                'month'      => $monthKey,
                'isLocked'   => true,
                'lockedBy'   => $this->admin_name ?? $this->admin_id ?? 'system',
                'lockedAtMs' => (int) (microtime(true) * 1000),
            ], false);
        } catch (\Throwable $e) {
            log_message('error', 'lock_staff_attendance Firestore write failed: ' . $e->getMessage());
            return $this->json_error('Failed to lock attendance. Please retry.');
        }

        $this->load->library('lock_cache');
        $this->lock_cache->invalidate($this->school_id, $this->session_year, $monthKey);

        $this->_log_attendance_change('LOCK_STAFF_ATTENDANCE', [
            'targetType' => 'staff',
            'attKey'   => $attKey,
            'monthKey' => $monthKey,
        ]);

        return $this->json_success(['message' => "Staff attendance locked for {$attKey}."]);
    }

    /**
     * POST — Unlock staff attendance for a month (admin override).
     * Marks the Firestore lock doc isLocked=false (preserves audit trail).
     */
    public function unlock_staff_attendance()
    {
        $this->_require_role(self::MANAGE_ROLES, 'unlock_staff_att');
        $month = trim((string) $this->input->post('month'));
        if (!$month) return $this->json_error('Month is required.');

        $year     = $this->_resolve_year($month);
        $monthNum = (int) date('n', strtotime("1 {$month} {$year}"));
        if ($monthNum < 1 || $monthNum > 12) {
            return $this->json_error('Invalid month name.');
        }
        $monthKey = sprintf('%04d-%02d', $year, $monthNum);
        $attKey   = "{$month} {$year}";

        $docId = "{$this->school_id}_{$this->session_year}_{$monthKey}";

        try {
            // merge=true so unlock is idempotent even if the doc was never
            // explicitly locked (e.g., schema-init edge cases).
            $this->firebase->firestoreSet('staffAttendanceLocks', $docId, [
                'schoolId'     => $this->school_id,
                'session'      => $this->session_year,
                'month'        => $monthKey,
                'isLocked'     => false,
                'unlockedBy'   => $this->admin_name ?? $this->admin_id ?? 'system',
                'unlockedAtMs' => (int) (microtime(true) * 1000),
            ], true);
        } catch (\Throwable $e) {
            log_message('error', 'unlock_staff_attendance Firestore write failed: ' . $e->getMessage());
            return $this->json_error('Failed to unlock attendance. Please retry.');
        }

        $this->load->library('lock_cache');
        $this->lock_cache->invalidate($this->school_id, $this->session_year, $monthKey);

        $this->_log_attendance_change('UNLOCK_STAFF_ATTENDANCE', [
            'targetType' => 'staff',
            'attKey'   => $attKey,
            'monthKey' => $monthKey,
        ]);

        return $this->json_success(['message' => "Staff attendance unlocked for {$attKey}."]);
    }

    // ====================================================================
    //  ATTENDANCE → COMMUNICATION EVENT TRIGGERS
    // ====================================================================

    /**
     * Fire communication events for a single student mark change.
     * Called from mark_student_day() when mark transitions to A or T.
     */
    /**
     * Fire notification when a student is marked Absent or Late.
     *
     * Two notification paths (belt-and-suspenders):
     *   1. Communication trigger pipeline (fire_event → Queue → process_queue)
     *   2. Direct parent notification (writes to student's notification inbox
     *      readable by parent app in real-time via Firebase listener)
     *
     * Dedup: per student+date+mark — fires only once per combination.
     */
    private function _fire_single_student_event(
        string $studentId, string $class, string $section,
        string $mark, int $day, string $attKey
    ): void {
        try {
            $this->load->helper('attendance');
            $date = date('Y-m-d');

            // ── DEDUP: Firestore-only (Phase 6G) ──
            // Canonical collection `attendanceEventsFired`,
            // doc id `{schoolId}_{md5(student|date|mark)}`. The legacy RTDB
            // Event_Fired check + mirror have been removed (Firestore is sole).
            $dedupKey  = att_event_dedup_key($studentId, $date, $mark);
            $fsDedupId = $this->school_id . '_' . $dedupKey;

            $fsDedupDoc = null;
            try {
                $fsDedupDoc = $this->fs->get('attendanceEventsFired', $fsDedupId);
            } catch (\Exception $e) {
                log_message('error', "Attendance dedup Firestore read failed: " . $e->getMessage());
            }
            if (is_array($fsDedupDoc)) return;

            // Student profile — Firestore students/{schoolId}_{studentId}
            // (Phase 6G: replaces the legacy RTDB Users/Parents read).
            $studentData = null;
            try {
                $studentData = $this->fs->get('students', "{$this->school_id}_{$studentId}");
            } catch (\Exception $e) { /* fall back to id below */ }
            $studentName = is_array($studentData) ? ($studentData['name'] ?? $studentId) : $studentId;
            $parentName  = is_array($studentData) ? ($studentData['fatherName'] ?? '') : '';

            $eventType = ($mark === 'A') ? 'student_absent' : 'student_late';
            $statusLabel = ($mark === 'A') ? 'Absent' : 'Late';

            $eventData = [
                'student_id'   => $studentId,
                'student_name' => $studentName,
                'parent_name'  => $parentName,
                'class'        => $class,
                'section'      => $section,
                'date'         => $date,
                'day'          => $day,
                'month'        => $attKey,
                'status'       => $mark,
            ];

            // ── PATH 1: Communication trigger pipeline ──
            $queued = 0;
            try {
                $this->load->library('communication_helper');
                $this->communication_helper->init(
                    $this->firebase, $this->school_name, $this->session_year, $this->parent_db_key, $this->fs, $this->school_id
                );
                $queued = $this->communication_helper->fire_event($eventType, $eventData);
            } catch (\Exception $e) {
                log_message('error', "Attendance trigger pipeline failed: " . $e->getMessage());
            }

            // ── PATH 2: Persistent in-app notification — Firestore `notifications`
            // (Phase 6G: replaces the legacy RTDB inbox). Matches the Parent App's
            // NotificationDoc contract (userId==studentId, `read` state, createdAt).
            // This is the SOLE per-user attendance notification-list write.
            try {
                $notifDocId = "{$this->school_id}_ATT_" . date('YmdHis') . '_' . substr(md5($studentId . $day), 0, 6);
                $this->fs->set('notifications', $notifDocId, [
                    'schoolId'  => $this->school_id,
                    'userId'    => $studentId,
                    'type'      => $eventType,
                    'title'     => "Attendance: {$statusLabel}",
                    'body'      => "{$studentName} was marked {$statusLabel} on " . date('d M Y') . " ({$class}, {$section})",
                    'priority'  => 'normal',
                    'read'      => false,
                    'createdAt' => date('c'),
                    'data'      => [
                        'type'       => $eventType,
                        'student_id' => $studentId,
                        'class'      => $class,
                        'section'    => $section,
                        'date'       => $date,
                    ],
                ], true);
            } catch (\Exception $e) {
                log_message('error', "Attendance direct notification failed: " . $e->getMessage());
            }

            // ── PATH 3: Real-time FCM push (Phase C — 2026-04-08) ──
            // Fires immediately so the parent gets a notification even if
            // no trigger is configured AND process_queue cron hasn't run yet.
            $pushed = 0;
            try {
                $this->load->library('push_service');
                $pushed = $this->push_service->sendToUser($studentId, [
                    'title' => "Attendance: {$statusLabel}",
                    'body'  => "{$studentName} was marked {$statusLabel} on " . date('d M Y'),
                    'data'  => [
                        'type'       => $eventType,
                        'student_id' => $studentId,
                        'class'      => $class,
                        'section'    => $section,
                        'date'       => $date,
                    ],
                ]);
            } catch (\Exception $e) {
                log_message('error', "Attendance FCM push failed: " . $e->getMessage());
            }

            // ── Mark as fired — Firestore-only dedup seal (Phase 6G) ──
            $dedupRecord = [
                'schoolId'  => $this->school_id,
                'studentId' => $studentId,
                'mark'      => $mark,
                'date'      => $date,
                'eventType' => $eventType,
                'queued'    => $queued,
                'direct'    => true,
                'pushed'    => $pushed,
                'at'        => date('c'),
            ];
            try {
                $this->fs->set('attendanceEventsFired', $fsDedupId, $dedupRecord, true);
            } catch (\Exception $e) {
                log_message('error', "Attendance dedup Firestore write failed: " . $e->getMessage());
            }
        } catch (\Exception $e) {
            log_message('error', 'Attendance: notification event failed: ' . $e->getMessage());
        }
    }

    /**
     * Fire communication events after bulk student attendance save.
     * Detects which students were newly marked A or T (today only) and fires events.
     */
    private function _fire_student_att_events(string $class, string $section, string $attKey): void
    {
        // Only fire for today's marks (not historical edits)
        $today = (int)date('j');
        $currentMonth = date('F') . ' ' . date('Y');
        if ($attKey !== $currentMonth) return;

        try {
            $this->load->library('communication_helper');
            $this->communication_helper->init(
                $this->firebase, $this->school_name, $this->session_year, $this->parent_db_key
            );

            // R5 — Firestore-only roster discovery via Roster_helper
            // (Strategy 0 inside _get_section_students). The previous
            // RTDB shallow_get fallback at this site is gone; the
            // attendanceSummary-derived strategy inside the helper
            // catches every student who has ever been marked, so a
            // school with no `students` docs but real attendance still
            // fans out push notifications.
            $students = $this->_get_section_students($class, $section);
            if (empty($students)) return;

            // Phase 7w: dropped the section-level dedup
            // (`md5(class_section_attKey_today)`). It blocked ALL
            // subsequent pushes for the section once any single
            // A/T fire happened for the day, even for different
            // students whose marks had genuinely changed since the
            // earlier save. The per-student dedup inside
            // `_fire_single_student_event` (keyed on
            // student|date|mark) is still in place and correctly
            // prevents duplicate pushes for the same combination.

            // Read current dayWise per student from Firestore first
            // (admin-canonical), fall back to RTDB. We need the full
            // dayWise string to look at today's character.
            $monthNum = (int)date('n');
            $year     = (int)date('Y');
            $monthKey = sprintf('%04d-%02d', $year, $monthNum);

            $fsDayWise = [];
            try {
                $fsDocs = $this->fs->schoolWhere('attendanceSummary', [
                    ['month', '==', $monthKey],
                    ['type',  '==', 'student'],
                ]);
                foreach ($fsDocs as $entry) {
                    $d = is_array($entry) ? ($entry['data'] ?? $entry) : null;
                    if (!is_array($d)) continue;
                    $sid = $d['studentId'] ?? '';
                    $dw  = $d['dayWise']   ?? '';
                    if ($sid !== '' && is_string($dw)) $fsDayWise[$sid] = $dw;
                }
            } catch (\Exception $e) { /* Firestore-only; empty ⇒ no events fired */ }

            foreach ($students as $studentId => $v) {
                $studentId = (string)$studentId;
                if ($studentId === '') continue;

                // Firestore canonical (RTDB dayWise fallback REMOVED)
                $attStr = $fsDayWise[$studentId] ?? '';
                if ($attStr === '' || strlen($attStr) < $today) continue;

                $todayMark = strtoupper($attStr[$today - 1]);
                if ($todayMark === 'A' || $todayMark === 'T') {
                    $this->_fire_single_student_event($studentId, $class, $section, $todayMark, $today, $attKey);
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Attendance: bulk event trigger failed: ' . $e->getMessage());
        }
    }

    // ====================================================================
    //  FIRESTORE SYNC — writes daily attendance for Android apps
    // ====================================================================

    /**
     * Write a student's daily attendance mark to Firestore (the canonical
     * store) — Phase 7a flipped this to be the *primary* write path so
     * mark_student_day calls it BEFORE the RTDB mirror update.
     *
     * Collection: attendance
     * DocId: {schoolId}_{date}_{studentId}
     * Fields: match Android AttendanceDoc exactly
     *
     * @return bool true on success, false if the Firestore write threw.
     *              Bulk callers that don't care about the return value can
     *              still ignore it; the strict per-day path checks it.
     */
    private function _syncDailyToFirestore(
        string $studentId,
        string $mark,
        string $class,
        string $section,
        int    $day,
        string $attKey,
        string $studentName = '',
        bool   $isLate = false,
        int    $lateMinutes = 0
    ): bool {
        try {
            $school  = $this->school_name;
            $session = $this->session_year;

            // Parse attKey "April 2026" → date "2026-04-02"
            $monthNum = $this->month_map[explode(' ', $attKey)[0]] ?? 0;
            $year     = (int)(explode(' ', $attKey)[1] ?? date('Y'));
            if ($monthNum === 0) return false;
            $date = sprintf('%04d-%02d-%02d', $year, $monthNum, $day);

            $classKey   = Firestore_service::classKey($class);
            $sectionKey = Firestore_service::sectionKey($section);
            $sectionStr = "{$classKey}/{$sectionKey}";

            // Phase 4 (2026-04-08): stamp classOrder/sectionCode/className/section
            // alongside sectionKey so attendance docs match Phase 1-3 shape.
            require_once APPPATH . 'libraries/Entity_firestore_sync.php';
            $cs = Entity_firestore_sync::normalizeClassSection($classKey, $sectionKey);

            // DocId matches Android: {schoolId}_{date}_{studentId}
            $docId = "{$school}_{$date}_{$studentId}";

            $doc = [
                'schoolId'    => $school,
                'session'     => $session,
                'date'        => $date,
                // audit M16: stamp type='student' so the fast per-day dashboard
                // query schoolWhere('attendance',[date==,type=='student']) matches
                // (writers previously omitted it → that query hit 0 docs and
                // silently fell back to the attendanceSummary path).
                'type'        => 'student',
                'className'   => $cs['className']  !== '' ? $cs['className']  : $classKey,
                'section'     => $cs['section']    !== '' ? $cs['section']    : $sectionKey,
                'classOrder'  => $cs['classOrder'],
                'sectionCode' => $cs['sectionCode'],
                'sectionKey'  => $sectionStr,
                'studentId'   => $studentId,
                'studentName' => $studentName,
                'status'      => $mark,
                'markedBy'    => $this->admin_id ?? $this->session->userdata('admin_id') ?? 'system',
                'markedAt'    => date('c'),
                'late'        => $isLate || $mark === 'T',
                'lateMinutes' => $lateMinutes,
                'notified'    => false,
            ];

            $ok = (bool) $this->fs->set('attendance', $docId, $doc, true);
            log_message('debug', "Attendance Firestore sync: {$docId} → {$mark}");

            // P1 CONVERGENCE (Phase 1, add-only): keep the per-month canonical
            // `attendanceSummary.dayWise` in sync for this single day too. Every
            // single-day / bulk / api / punch student write funnels through here,
            // so this makes `attendanceSummary` the always-complete canonical
            // store that apps + reports + analytics read — closing the gap where
            // single-day marks previously updated only the per-day `attendance`
            // collection (+ RTDB). Best-effort during the add-only phase: a
            // summary-sync failure is logged but does NOT fail the primary write
            // (the RTDB mirror + per-day `attendance` doc remain intact).
            $this->_applyDayToSummary($studentId, $class, $section, $day, $attKey, $mark, $studentName);

            return $ok;
        } catch (\Exception $e) {
            log_message('error', "Attendance Firestore sync failed for {$studentId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * P1 CONVERGENCE helper — read-modify-write the canonical per-month
     * `attendanceSummary.dayWise` for a SINGLE day, then let
     * _syncStudentSummaryToFirestore recompute counts/percentage + write.
     * PRESENTATION/AGGREGATE ONLY — makes `attendanceSummary` complete so the
     * RTDB `dayWise` fallback can eventually be retired. Best-effort (logs).
     *
     * @return bool true if the summary was written.
     */
    private function _applyDayToSummary(
        string $studentId,
        string $class,
        string $section,
        int    $day,
        string $attKey,
        string $mark,
        string $studentName = ''
    ): bool {
        try {
            $parts    = explode(' ', $attKey);
            $monthNum = $this->month_map[$parts[0] ?? ''] ?? 0;
            $year     = (int) ($parts[1] ?? date('Y'));
            if ($monthNum === 0) return false;

            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);
            if ($day < 1 || $day > $daysInMonth) return false;

            $monthKey = sprintf('%04d-%02d', $year, $monthNum);
            $docId    = $this->fs->docId2($studentId, $monthKey);
            $nonWorking = $this->_resolve_non_working_days($monthNum, $year);
            $hasCas = isset($this->firebase) && method_exists($this->firebase, 'firestoreGet');

            // CAS RETRY LOOP (audit finding H11) — this read-modify-write of the
            // canonical dayWise runs UNLOCKED from the bulk-save and Teacher-app
            // save() paths, so a naive read→mutate→set could drop a concurrent
            // writer's mark. Read the current dayWise WITH its __updateTime,
            // apply this single day, and write with a precondition; on a
            // conflict re-read the fresh dayWise and re-apply (bounded retries).
            $maxTries = $hasCas ? 4 : 1;
            for ($try = 0; $try < $maxTries; $try++) {
                $doc = null;
                $precondition = null;
                if ($hasCas) {
                    try { $doc = $this->firebase->firestoreGet('attendanceSummary', $docId); } catch (\Exception $e) {}
                    $ut = is_array($doc) ? (string) ($doc['__updateTime'] ?? '') : '';
                    $precondition = ($ut !== '') ? ['updateTime' => $ut] : ['exists' => false];
                } else {
                    try { $doc = $this->fs->get('attendanceSummary', $docId); } catch (\Exception $e) {}
                }

                $dayWise = ($doc && is_string($doc['dayWise'] ?? null) && $doc['dayWise'] !== '')
                    ? $doc['dayWise'] : str_repeat('V', $daysInMonth);
                $dayWise = str_pad($dayWise, $daysInMonth, 'V');

                // Apply the single day, then re-stamp holidays/Sundays as 'H'
                // (identical rule to save_student_attendance / approve paths).
                $dayWise[$day - 1] = $mark;
                $dayWise = enforce_holidays_on_string($dayWise, $daysInMonth, $nonWorking);

                // Preserve an existing name if the caller didn't supply one.
                $name = $studentName;
                if ($name === '' && $doc && isset($doc['studentName'])) {
                    $name = (string) $doc['studentName'];
                }

                $ok = $this->_syncStudentSummaryToFirestore(
                    $studentId, $class, $section, $monthNum, $year, $dayWise, $name, $precondition
                );
                if ($ok) return true;
                // Precondition conflict (or transient) — brief backoff, then re-read.
                if ($try < $maxTries - 1) usleep(40000 * ($try + 1));
            }
            return false;
        } catch (\Exception $e) {
            log_message('error', "Attendance summary day-sync failed for {$studentId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Phase 7b — Firestore-first write for a single staff member's
     * daily attendance mark.
     *
     * Collection: staffAttendance
     * DocId:      {schoolId}_{date}_{staffId}
     *
     * Mirrors _syncDailyToFirestore but for staff. Returns bool so the
     * strict per-day path (mark_staff_day) can bail before touching RTDB
     * if the Firestore write fails.
     */
    private function _syncStaffDailyToFirestore(
        string $staffId,
        string $mark,
        int    $day,
        string $attKey,
        string $staffName = '',
        bool   $isLate = false,
        int    $lateMinutes = 0
    ): bool {
        try {
            $school  = $this->school_name;
            $session = $this->session_year;

            $monthNum = $this->month_map[explode(' ', $attKey)[0]] ?? 0;
            $year     = (int)(explode(' ', $attKey)[1] ?? date('Y'));
            if ($monthNum === 0) return false;
            $date = sprintf('%04d-%02d-%02d', $year, $monthNum, $day);

            $docId = "{$school}_{$date}_{$staffId}";
            $doc = [
                'schoolId'    => $school,
                'session'     => $session,
                'date'        => $date,
                'staffId'     => $staffId,
                'staffName'   => $staffName,
                'status'      => $mark,
                'markedBy'    => $this->admin_id ?? $this->session->userdata('admin_id') ?? 'system',
                'markedAt'    => date('c'),
                'late'        => $isLate || $mark === 'T',
                'lateMinutes' => $lateMinutes,
                'notified'    => false,
            ];

            $ok = (bool) $this->fs->set('staffAttendance', $docId, $doc, true);
            log_message('debug', "Staff attendance Firestore sync: {$docId} → {$mark}");
            return $ok;
        } catch (\Exception $e) {
            log_message('error', "Staff attendance Firestore sync failed for {$staffId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Phase 7b — recompute and write a monthly staff attendance summary
     * to Firestore. Mirrors the shape used for `attendanceSummary` so the
     * Android apps can read either with the same parser.
     *
     * Collection: staffAttendanceSummary
     * DocId:      {schoolId}_{staffId}_{monthKey}
     */
    private function _syncStaffSummaryToFirestore(
        string $staffId,
        string $attKey,
        string $dayWise,
        string $staffName = ''
    ): bool {
        try {
            $school  = $this->school_name;
            $session = $this->session_year;

            $monthNum = $this->month_map[explode(' ', $attKey)[0]] ?? 0;
            $year     = (int)(explode(' ', $attKey)[1] ?? date('Y'));
            if ($monthNum === 0) return false;
            $monthKey = sprintf('%04d-%02d', $year, $monthNum);

            $present  = substr_count($dayWise, 'P');
            $absent   = substr_count($dayWise, 'A');
            $leave    = substr_count($dayWise, 'L');
            $holiday  = substr_count($dayWise, 'H');
            $tardy    = substr_count($dayWise, 'T');
            $vacation = substr_count($dayWise, 'V');
            $total    = strlen($dayWise);
            $working  = $total - $holiday - $vacation;
            // Phase 9b: include tardy — matches the student formula
            $pct      = $working > 0 ? (($present + $tardy) / $working) * 100.0 : 0.0;

            $docId = "{$school}_{$staffId}_{$monthKey}";
            $doc = [
                'schoolId'    => $school,
                'session'     => $session,
                'staffId'     => $staffId,
                'staffName'   => $staffName,
                'type'        => 'staff',
                'month'       => $monthKey,
                'monthLabel'  => $attKey,
                'dayWise'     => $dayWise,
                'present'     => $present,
                'absent'      => $absent,
                'leave'       => $leave,
                'holiday'     => $holiday,
                'tardy'       => $tardy,
                'late'        => $tardy,  // legacy alias — same trick as student summary
                'vacation'    => $vacation,
                'totalDays'   => $total,
                'workingDays' => $working,
                'percentage'  => $pct,
                'updatedAt'   => date('c'),
                'updatedBy'   => $this->admin_id ?? 'system',
            ];

            return (bool) $this->fs->set('staffAttendanceSummary', $docId, $doc, true);
        } catch (\Exception $e) {
            log_message('error', "Staff attendance summary Firestore sync failed for {$staffId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Recompute and write a monthly student attendance summary to Firestore.
     *
     * Mirrors the inline write in `save_student_attendance` so the
     * `attendanceSummary` collection stays the single source of truth
     * regardless of whether marks land via bulk save, single-day click,
     * teacher app sync, or backdated approval.
     *
     * Collection: attendanceSummary
     * DocId:      {schoolId}_{studentId}_{YYYY-MM}
     *
     * Uses set(merge:true) so any existing `lateTimes` map (admin-owned
     * arrival times) is preserved across summary recomputations.
     */
    private function _syncStudentSummaryToFirestore(
        string $studentId,
        string $class,
        string $section,
        int    $monthNum,
        int    $year,
        string $dayWise,
        string $studentName = '',
        ?array $precondition = null   // audit H11: optional CAS precondition (updateTime|exists)
    ): bool {
        try {
            $monthName = date('F', mktime(0, 0, 0, $monthNum, 1, $year));
            $monthKey  = sprintf('%04d-%02d', $year, $monthNum);
            $attKey    = "{$monthName} {$year}";

            $present = $absent = $leave = $holiday = $tardy = 0;
            $working = 0;
            for ($i = 0; $i < strlen($dayWise); $i++) {
                $ch = $dayWise[$i];
                if ($ch === 'P')      { $present++; $working++; }
                elseif ($ch === 'A')  { $absent++;  $working++; }
                elseif ($ch === 'L')  { $leave++;   $working++; }
                elseif ($ch === 'H')  { $holiday++; }
                elseif ($ch === 'T')  { $tardy++;   $working++; }
            }
            $pct = $working > 0 ? round(($present + $tardy) / $working * 100, 1) : 0;

            // Build the doc. Only include `studentName` when the
            // caller actually has it — `set(merge:true)` then leaves
            // any existing name on the doc untouched.
            $doc = [
                'schoolId'   => $this->school_id,
                'studentId'  => $studentId,
                'type'       => 'student',
                'className'  => Firestore_service::classKey($class),
                'section'    => Firestore_service::sectionKey($section),
                // Canonical combined key ("Class 9th/Section A"). Added 2026-07-07:
                // the Teacher dashboard's "Today's Attendance" card queries
                // attendanceSummary by sectionKey, but summaries never carried the
                // field → the query matched 0 docs → every student showed unmarked.
                // Same format the per-day `attendance` docs already use.
                'sectionKey' => Firestore_service::buildSectionKey($class, $section),
                'month'      => $monthKey,
                'monthLabel' => $attKey,
                'session'    => $this->session_year,
                'dayWise'    => $dayWise,
                'present'    => $present,
                'absent'     => $absent,
                'leave'      => $leave,
                'holiday'    => $holiday,
                'tardy'      => $tardy,
                'percentage' => $pct,
                'updatedAt'  => date('c'),
                'updatedBy'  => $this->admin_id ?? 'system',
            ];
            if ($studentName !== '') {
                $doc['studentName'] = $studentName;
            }

            $docId = $this->fs->docId2($studentId, $monthKey);

            // CAS write (audit finding H11): when the caller supplies a
            // precondition (from a read that captured __updateTime), commit via
            // firestoreCommitBatch so a concurrent writer converging on the same
            // {studentId}_{month} doc cannot silently drop this update — a stale
            // precondition makes the commit return false and the caller re-reads
            // + retries. merge:true preserves the admin-owned lateTimes map,
            // identical to the fs->set(...,true) legacy path used when no
            // precondition is passed (all pre-existing callers unchanged).
            if ($precondition !== null
                && isset($this->firebase)
                && method_exists($this->firebase, 'firestoreCommitBatch')) {
                $ok = $this->firebase->firestoreCommitBatch([
                    ['op' => 'set', 'collection' => 'attendanceSummary', 'docId' => $docId,
                     'data' => $doc, 'merge' => true, 'precondition' => $precondition],
                ]);
                return $ok === true;
            }
            return (bool) $this->fs->set('attendanceSummary', $docId, $doc, true);
        } catch (\Exception $e) {
            log_message('error', "Student attendance summary Firestore sync failed for {$studentId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Sync a batch of student marks to Firestore in one go.
     * Used by bulk_mark_student and api_mark_attendance.
     */
    private function _syncBulkDailyToFirestore(
        array  $studentMarks,  // [ studentId => ['mark' => 'P', 'name' => '...'] ]
        string $class,
        string $section,
        int    $day,
        string $attKey
    ): bool {
        $allOk = true;
        foreach ($studentMarks as $studentId => $info) {
            $ok = $this->_syncDailyToFirestore(
                $studentId,
                $info['mark'] ?? 'V',
                $class, $section, $day, $attKey,
                $info['name'] ?? '',
                ($info['mark'] ?? '') === 'T',
                0
            );
            if (!$ok) $allOk = false;
        }
        return $allOk;
    }

    // ====================================================================
    //  LOGGING
    // ====================================================================

    private function _log_attendance_change(string $action, array $details): void
    {
        // Phase 6E — direct Firestore attendanceAuditLog write via the unified
        // canonical writer. The legacy JSONL→RTDB System/Logs queue is retired;
        // best-effort semantics preserved (never interrupts attendance ops).
        $this->_audit_write(array_merge(['action' => $action], $details));
    }

    // _update_summary_incremental() RETIRED (Component 5) — maintained a section-summary RTDB node (Attendance/Summary/Students) with zero live readers; analytics/dashboard read Firestore attendanceSummary.

    /* ================================================================
       CACHE ABSTRACTION — Redis with circuit breaker + file fallback
       ================================================================ */

    /** Circuit breaker: max consecutive failures before tripping */
    private const CB_FAIL_THRESHOLD = 3;
    /** Circuit breaker: seconds to wait before retrying Redis after trip */
    private const CB_COOLDOWN = 60;
    /** Redis read/write timeout in seconds (100ms) */
    private const REDIS_TIMEOUT = 0.1;

    /** @var object|null Redis connection */
    private $_redis = null;
    private $_redis_checked = false;
    /** Circuit breaker state — shared via static so it persists if controller is re-instantiated */
    private static $_cb_failures = 0;
    private static $_cb_tripped_at = 0;

    /**
     * Get a value from cache. Redis → file fallback. Never blocks.
     */
    private function _cache_get(string $key)
    {
        $redis = $this->_get_redis();
        if ($redis) {
            try {
                $val = $redis->get("att:{$key}");
                if ($val !== false) {
                    self::$_cb_failures = 0; // reset on success
                    $decoded = json_decode($val, true);
                    return $decoded !== null ? $decoded : $val;
                }
                return null;
            } catch (Exception $e) {
                $this->_cb_record_failure($e);
            }
        }

        // File fallback
        $file = $this->_cache_file_path($key);
        if (!is_file($file)) return null;
        $meta = json_decode(file_get_contents($file), true);
        if (!is_array($meta) || !isset($meta['ttl'], $meta['data'])) return null;
        if ((time() - filemtime($file)) >= $meta['ttl']) {
            @unlink($file);
            return null;
        }
        return $meta['data'];
    }

    /**
     * Set a value in cache. Redis → file fallback. Never blocks.
     */
    private function _cache_set(string $key, $value, int $ttl = 300): void
    {
        $redis = $this->_get_redis();
        if ($redis) {
            try {
                $redis->setex("att:{$key}", $ttl, json_encode($value));
                self::$_cb_failures = 0;
                return;
            } catch (Exception $e) {
                $this->_cb_record_failure($e);
            }
        }

        // File fallback
        $file = $this->_cache_file_path($key);
        $dir  = dirname($file);
        try {
            if (!is_dir($dir)) mkdir($dir, 0700, true);
            file_put_contents($file, json_encode(['ttl' => $ttl, 'data' => $value]), LOCK_EX);
        } catch (Exception $e) {
            log_message('error', "Cache write failed [{$key}]: " . $e->getMessage());
        }
    }

    /**
     * Delete a cache key.
     */
    private function _cache_delete(string $key): void
    {
        $redis = $this->_get_redis();
        if ($redis) {
            try {
                $redis->del("att:{$key}");
                return;
            } catch (Exception $e) {
                $this->_cb_record_failure($e);
            }
        }
        $file = $this->_cache_file_path($key);
        if (is_file($file)) @unlink($file);
    }

    /**
     * Atomic increment a cache counter (for rate limiting / metrics).
     * Returns the new value. Falls back to file-based counter.
     */
    private function _cache_incr(string $key, int $ttl = 60): int
    {
        $redis = $this->_get_redis();
        if ($redis) {
            try {
                $val = $redis->incr("att:{$key}");
                if ($val === 1) $redis->expire("att:{$key}", $ttl);
                self::$_cb_failures = 0;
                return (int) $val;
            } catch (Exception $e) {
                $this->_cb_record_failure($e);
            }
        }

        // File fallback: read-increment-write (not atomic, but sufficient for rate limiting)
        $file = $this->_cache_file_path($key);
        $count = 0;
        if (is_file($file) && (time() - filemtime($file)) < $ttl) {
            $count = (int) file_get_contents($file);
        }
        $count++;
        $dir = dirname($file);
        try {
            if (!is_dir($dir)) mkdir($dir, 0700, true);
            file_put_contents($file, $count, LOCK_EX);
        } catch (Exception $e) { /* best-effort */ }
        return $count;
    }

    /**
     * Lazy-init Redis with circuit breaker.
     * Returns Redis instance or null (never blocks >100ms).
     */
    private function _get_redis()
    {
        // Circuit breaker: if tripped, skip Redis until cooldown expires
        if (self::$_cb_tripped_at > 0) {
            if ((time() - self::$_cb_tripped_at) < self::CB_COOLDOWN) {
                return null; // circuit open — fast fail
            }
            // Cooldown expired — half-open: allow one attempt
            self::$_cb_tripped_at = 0;
            self::$_cb_failures = 0;
            $this->_redis = null;
            $this->_redis_checked = false;
        }

        if ($this->_redis_checked) return $this->_redis;
        $this->_redis_checked = true;

        if (!class_exists('Redis')) return null;
        try {
            $r = new Redis();
            $host = defined('REDIS_HOST') ? REDIS_HOST : '127.0.0.1';
            $port = defined('REDIS_PORT') ? REDIS_PORT : 6379;
            if ($r->connect($host, $port, self::REDIS_TIMEOUT)) {
                $r->setOption(Redis::OPT_READ_TIMEOUT, self::REDIS_TIMEOUT);
                $this->_redis = $r;
                return $r;
            }
        } catch (Exception $e) {
            $this->_cb_record_failure($e);
        }
        return null;
    }

    /**
     * Record a Redis failure. Trips the circuit after CB_FAIL_THRESHOLD consecutive failures.
     */
    private function _cb_record_failure(Exception $e): void
    {
        self::$_cb_failures++;
        $this->_redis = null;
        $this->_redis_checked = true;

        if (self::$_cb_failures >= self::CB_FAIL_THRESHOLD) {
            self::$_cb_tripped_at = time();
            log_message('error', 'Redis circuit breaker TRIPPED after ' . self::CB_FAIL_THRESHOLD
                . ' failures. Cooldown ' . self::CB_COOLDOWN . 's. Last error: ' . $e->getMessage());
        }
    }

    /**
     * Derive file cache path from key.
     */
    private function _cache_file_path(string $key): string
    {
        return APPPATH . 'cache/attendance/' . md5($key) . '.json';
    }

    /**
     * Acquire a short-lived lock for an attendance string read-modify-write.
     * Prevents concurrent writes from silently overwriting each other.
     *
     * Uses cache layer (Redis SETNX or file lock). Lock auto-expires after 5s.
     * Returns true if lock acquired, false if another write is in progress.
     */
    private function _acquire_att_lock(string $attPath): bool
    {
        $lockKey = "lock_" . md5($attPath);

        // Try Redis SETNX (atomic)
        $redis = $this->_get_redis();
        if ($redis) {
            try {
                $acquired = $redis->set("att:{$lockKey}", 1, ['NX', 'EX' => 5]);
                return (bool) $acquired;
            } catch (Exception $e) {
                $this->_cb_record_failure($e);
            }
        }

        // File fallback: use lock file with TTL
        $lockFile = APPPATH . 'cache/attendance/locks/' . md5($attPath) . '.lock';
        $lockDir  = dirname($lockFile);
        try {
            if (!is_dir($lockDir)) mkdir($lockDir, 0700, true);
            // Check if lock exists and is still valid (5s TTL)
            if (is_file($lockFile) && (time() - filemtime($lockFile)) < 5) {
                return false; // locked by another request
            }
            file_put_contents($lockFile, getmypid(), LOCK_EX);
            return true;
        } catch (Exception $e) {
            return true; // fail-open: allow write if lock system broken
        }
    }

    /**
     * Release an attendance string lock.
     */
    private function _release_att_lock(string $attPath): void
    {
        $lockKey = "lock_" . md5($attPath);

        $redis = $this->_get_redis();
        if ($redis) {
            try { $redis->del("att:{$lockKey}"); } catch (Exception $e) {}
            return;
        }

        $lockFile = APPPATH . 'cache/attendance/locks/' . md5($attPath) . '.lock';
        if (is_file($lockFile)) @unlink($lockFile);
    }

    /**
     * Cache-based rate limiter for internal APIs.
     * Uses _cache_incr for O(1) check — zero Firebase overhead.
     * Returns true if allowed, false if rate-limited.
     */
    private function _check_rate_limit(string $endpoint): bool
    {
        $userId = $this->session->userdata('user_id') ?: $this->input->ip_address();
        $key = "rl_{$endpoint}_" . md5($userId) . '_' . date('YmdHi'); // per-minute bucket
        $count = $this->_cache_incr($key, 60);
        return $count <= self::INTERNAL_RATE_LIMIT;
    }


    /**
     * Normalize a late-metadata entry to a time string.
     * Handles both formats:
     *   - Object format (from api_punch):  {"time": "09:15", "threshold": "09:00"} → "09:15"
     *   - String format (legacy/manual):   "09:15" → "09:15"
     */
    private function _normalize_late_entry($entry): string
    {
        if (is_array($entry) && isset($entry['time'])) {
            return (string) $entry['time'];
        }
        return is_string($entry) ? $entry : '';
    }

    /**
     * Normalize all late entries for a person (keyed by day number).
     * Returns [ day => timeString, ... ]
     */
    private function _normalize_late_data(array $lateEntries): array
    {
        $normalized = [];
        foreach ($lateEntries as $day => $entry) {
            $time = $this->_normalize_late_entry($entry);
            if ($time !== '') {
                $normalized[$day] = $time;
            }
        }
        return $normalized;
    }

    /**
     * Sanitize an attendance string to only valid characters, padded to length
     */
    private function _sanitize_att_string(string $raw, int $daysInMonth, ?array $allowed = null): string
    {
        // $allowed defaults to the student status set ($this->valid_marks =
        // P/A/L/H/T/V). Staff attendance passes the wider company-schedule set
        // (adds M/W/O) so those GPS-written marks are not silently rewritten to V.
        $allowed = $allowed ?? $this->valid_marks;
        $raw = strtoupper(trim($raw));
        $raw = substr($raw, 0, $daysInMonth);
        $clean = '';
        for ($i = 0; $i < strlen($raw); $i++) {
            $clean .= in_array($raw[$i], $allowed) ? $raw[$i] : 'V';
        }
        return str_pad($clean, $daysInMonth, 'V');
    }

    /* ================================================================
       PHASE 1 — TIME-BASED EDIT CONTROL (smart save + state machine)
       ================================================================
       Implements the refactored attendance contract:
         - single PHP write path
         - server-time-only date resolution
         - 3-stage gate (S1 free → S2 restricted → S3 locked)
         - per-day attendance docs + Firestore audit log
         - default-to-Present "smart save"
       New endpoint: POST /attendance/save
       Legacy endpoints (save_student_attendance, mark_student_day) remain
       untouched until parity is verified.
       ================================================================ */

    /** Per-request cache of school timezone (DateTimeZone). */
    private $_p1_tz_cache = null;

    /** Per-request cache of holidays keyed by "Y-m". */
    private $_p1_holiday_cache = [];

    /**
     * Resolve school timezone. Defaults to Asia/Kolkata.
     * Reads `schools/{id}.timezone` once per request; matches the canonical
     * pattern in Admin.php:662–664.
     */
    private function _get_school_timezone(): \DateTimeZone
    {
        if ($this->_p1_tz_cache !== null) return $this->_p1_tz_cache;
        $tzName = 'Asia/Kolkata';
        try {
            $sdata = $this->fs->get('schools', $this->school_id);
            $candidate = is_array($sdata) ? (string) ($sdata['timezone'] ?? '') : '';
            if ($candidate !== '' && in_array($candidate, timezone_identifiers_list(), true)) {
                $tzName = $candidate;
            }
        } catch (\Exception $e) { /* fall through to IST default */ }
        return $this->_p1_tz_cache = new \DateTimeZone($tzName);
    }

    /**
     * Server "today" in the school's timezone (YYYY-MM-DD).
     * Clients MUST NOT send a date for today operations — this is the source.
     */
    private function _server_today(): string
    {
        return (new \DateTime('now', $this->_get_school_timezone()))->format('Y-m-d');
    }

    /**
     * Server "now" as a DateTime in the school's timezone.
     */
    private function _server_now(): \DateTime
    {
        return new \DateTime('now', $this->_get_school_timezone());
    }

    /**
     * Build the deterministic lock document id.
     * Spaces in class/section keys replaced with hyphens so the id has no
     * whitespace. Result is stable for a given (school, class, section, date).
     */
    private function _lock_doc_id(string $class, string $section, string $date): string
    {
        $cls = str_replace(' ', '-', Firestore_service::classKey($class));
        $sec = str_replace(' ', '-', Firestore_service::sectionKey($section));
        return "{$this->school_id}_{$cls}_{$sec}_{$date}";
    }

    /**
     * Fetch the lock state for (class, section, date).
     * Missing doc = unlocked (default).
     *
     * @return array{locked:bool, lockedBy:?string, lockedAt:?string}
     */
    private function _get_lock(string $class, string $section, string $date): array
    {
        $docId = $this->_lock_doc_id($class, $section, $date);
        $doc   = $this->fs->get('attendanceLocks', $docId);
        if (!is_array($doc)) {
            return ['locked' => false, 'lockedBy' => null, 'lockedAt' => null];
        }
        return [
            'locked'       => (bool) ($doc['locked'] ?? false),
            'lockedBy'     => $doc['lockedBy']     ?? null,
            'lockedAt'     => $doc['lockedAt']     ?? null,
            'lockReason'   => $doc['lockReason']   ?? null,
            'unlockedBy'   => $doc['unlockedBy']   ?? null,
            'unlockedAt'   => $doc['unlockedAt']   ?? null,
            'unlockReason' => $doc['unlockReason'] ?? null,
        ];
    }

    /**
     * Compute the edit stage for (class, section, date).
     *   S1_FREE        → today, before 10:30, unlocked
     *   S2_RESTRICTED  → today, 10:30 ≤ now < 18:00, unlocked  (reason required)
     *   S3_LOCKED      → otherwise (locked, past day, future day, or after 18:00)
     *
     * Phase 2 extension: an explicit admin unlock (lock doc with locked=false
     * AND unlockedAt set) bypasses the time-of-day cutoff and treats the
     * date as S2_RESTRICTED until the admin re-locks.
     */
    private function _stage(string $class, string $section, string $date): string
    {
        // Any non-today write is locked. Backdated/forward edits go through
        // the correction-request flow (Phase 2).
        $today = $this->_server_today();
        if ($date !== $today) return 'S3_LOCKED';

        $lock = $this->_get_lock($class, $section, $date);

        // Explicit lock takes precedence over everything below.
        if (!empty($lock['locked'])) return 'S3_LOCKED';

        // Phase 2 — admin explicit unlock bypasses the 18:00 time gate.
        // Doc was created by the auto-lock cron / admin lock; admin then
        // flipped locked → false, which sets unlockedAt. Edits are allowed
        // but always restricted (reason required).
        if (!empty($lock['unlockedAt'])) return 'S2_RESTRICTED';

        $tz   = $this->_get_school_timezone();
        $now  = $this->_server_now();
        $cutG = new \DateTime("{$today} 10:30:00", $tz);
        $cutL = new \DateTime("{$today} 18:00:00", $tz);

        if ($now < $cutG) return 'S1_FREE';
        if ($now < $cutL) return 'S2_RESTRICTED';

        // After 18:00 with no lock doc yet (cron lag) → still locked.
        return 'S3_LOCKED';
    }

    /**
     * Enforce the stage contract. Exits with the right HTTP status if blocked.
     * Phase 1 has no admin-bypass — those land in Phase 2 with the correction flow.
     */
    private function _gate_write(string $stage, string $reason, string $role): void
    {
        if ($stage === 'S1_FREE') return;
        if ($stage === 'S2_RESTRICTED') {
            if (strlen(trim($reason)) < 10) {
                $this->json_error('Reason required for edits after 10:30 AM (min 10 chars).', 400);
            }
            return;
        }
        // S3_LOCKED
        $this->json_error('Attendance is locked. File a correction request.', 423);
    }

    /**
     * Write one row to the Firestore audit log. Direct write — no JSONL queue.
     * Failures are logged but never abort the user-visible action.
     */
    /**
     * Phase 6E — Canonical unified audit-log schema stamp. Every attendance
     * audit writer (_audit_write, _log_attendance_change, the api_punch device
     * audit, and the batch save/auto-lock writers) routes its row through this
     * so the single Firestore `attendanceAuditLog` collection carries a
     * consistent shape and a 24-month `expiresAt` for native TTL retention.
     * Caller-supplied keys win over the defaults. Never throws.
     */
    private function _audit_stamp(array $row): array
    {
        $now = time();
        return array_merge([
            'schoolId'      => $this->school_id,
            'userId'        => $this->admin_id ?: ($this->session->userdata('user_id') ?: 'system'),
            'role'          => $this->admin_role ?: ($this->session->userdata('Role') ?: 'system'),
            'sourceIp'      => $this->input->ip_address(),
            'timestamp'     => date('c'),
            'epoch'         => $now,
            'yearMonth'     => date('Y-m'),
            'expiresAt'     => $now + self::AUDIT_TTL_SECONDS,
            'schemaVersion' => self::ATT_PUNCH_SCHEMA_VERSION,
        ], $row);
    }

    private function _audit_write(array $row): void
    {
        $row = $this->_audit_stamp($row);
        try {
            // Monotonic + random suffix avoids collisions inside the same second.
            // docId honors the row's schoolId (api_punch uses the device school).
            $sid   = $row['schoolId'] ?? $this->school_id;
            $docId = "{$sid}_A" . date('YmdHis') . sprintf('%04d', mt_rand(0, 9999));
            $this->fs->set('attendanceAuditLog', $docId, $row, false);
        } catch (\Exception $e) {
            log_message('error', 'attendanceAuditLog write failed: ' . $e->getMessage()
                . ' row=' . json_encode($row, JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Active roster for (class, section), keyed by studentId.
     * Returns: [ studentId => [ name, admissionDate ] ]
     * Filters status='Active' canonically. Uses Roster_helper first;
     * falls back to a direct `students` query.
     */
    private function _get_active_roster(string $class, string $section): array
    {
        $classPrefixed   = Firestore_service::classKey($class);
        $sectionPrefixed = Firestore_service::sectionKey($section);

        $out = [];

        // Strategy 0 — Roster_helper (R5 canonical Firestore source)
        try {
            if (isset($this->roster) && method_exists($this->roster, 'for_class')) {
                $rows = $this->roster->for_class($classPrefixed, $sectionPrefixed);
                if (is_array($rows) && !empty($rows)) {
                    foreach ($rows as $uid => $fields) {
                        if (!is_array($fields)) continue;
                        $statusVal = (string) ($fields['Status'] ?? $fields['status'] ?? 'Active');
                        if (strcasecmp($statusVal, 'Active') !== 0) continue;
                        $out[(string) $uid] = [
                            'name' => (string) ($fields['Name'] ?? $fields['name'] ?? $uid),
                            'admissionDate' => (string) (
                                $fields['admissionDate']
                                    ?? $fields['Admission Date']
                                    ?? $fields['admission_date']
                                    ?? ''
                            ),
                        ];
                    }
                    if (!empty($out)) return $out;
                }
            }
        } catch (\Exception $e) { /* fall through */ }

        // Strategy 1 — direct students collection query
        try {
            $docs = $this->fs->schoolWhere('students', [
                ['Class',   '==', $classPrefixed],
                ['Section', '==', $sectionPrefixed],
            ], 'Name', 'ASC');
            foreach ($docs as $doc) {
                $d = is_array($doc) ? ($doc['data'] ?? $doc) : null;
                if (!is_array($d)) continue;
                $statusVal = (string) ($d['Status'] ?? $d['status'] ?? 'Active');
                if (strcasecmp($statusVal, 'Active') !== 0) continue;
                $uid = (string) ($d['User Id'] ?? $d['studentId'] ?? '');
                if ($uid === '') continue;
                $out[$uid] = [
                    'name' => (string) ($d['Name'] ?? $d['name'] ?? $uid),
                    'admissionDate' => (string) (
                        $d['admissionDate']
                            ?? $d['Admission Date']
                            ?? $d['admission_date']
                            ?? ''
                    ),
                ];
            }
        } catch (\Exception $e) { /* return what we have */ }

        return $out;
    }

    /**
     * Is $date (YYYY-MM-DD) a holiday in the school's calendar?
     * Reuses the existing attendance helper to read non-working days.
     */
    private function _is_holiday(string $date): bool
    {
        $parts = explode('-', $date);
        if (count($parts) !== 3) return false;
        $y = (int) $parts[0]; $m = (int) $parts[1]; $d = (int) $parts[2];
        if ($y < 1970 || $m < 1 || $m > 12 || $d < 1 || $d > 31) return false;

        $cacheKey = "{$y}-{$m}";
        if (!array_key_exists($cacheKey, $this->_p1_holiday_cache)) {
            $this->load->helper('attendance');
            try {
                $this->_p1_holiday_cache[$cacheKey] =
                    $this->_resolve_non_working_days($m, $y) ?: [];
            } catch (\Exception $e) {
                $this->_p1_holiday_cache[$cacheKey] = [];
            }
        }
        return !empty($this->_p1_holiday_cache[$cacheKey][$d]);
    }

    /**
     * Validate that admission date is on or before $serverDate.
     * Returns true if writable, false to block. Unknown / unparseable
     * admission dates are NOT blocked (defensive).
     */
    private function _admission_allows(string $admStr, string $serverDate): bool
    {
        $admStr = trim($admStr);
        if ($admStr === '') return true;
        $ts = strtotime($admStr);
        if ($ts === false) return true;
        return date('Y-m-d', $ts) <= $serverDate;
    }

    /* ================================================================
       PHASE 1 — POST /attendance/save  (smart default-Present)
       ================================================================
       Body:
         class    : string  (required)
         section  : string  (required, letter only e.g. "A")
         absent   : string[]                               (studentIds → A)
         leave    : string[]                               (studentIds → L)
         late     : array<{studentId, lateMinutes?}>       (P + late=true)
         reason   : string                                 (required in S2,
                                                            min 10 chars)
       Date is server-resolved — clients MUST NOT supply a date.
       Everything in the active roster but NOT in the three sets is
       written as Present.

       Phase 1 writes ONLY the per-day `attendance` collection.
       The `attendanceSummary` collection is NOT updated here — it will be
       computed on read or rebuilt by a Phase 3 job. This avoids the
       read-modify-write race that comes with maintaining derived state
       on every write.

       Concurrency model: Firestore set(merge:true) is the unit of safety.
       Two writers on the same student-day → last write wins. Every write
       carries lastUpdatedBy/lastUpdatedAt/lastUpdateStage and produces an
       audit row, so conflicts are reconstructible after the fact.
       ================================================================ */
    public function save()
    {
        $this->_require_role(self::MARK_ROLES, 'attendance/save');

        $class   = trim((string) $this->input->post('class'));
        $section = trim((string) $this->input->post('section'));
        $reason  = trim((string) $this->input->post('reason'));
        if ($class === '' || $section === '') {
            return $this->json_error('class and section are required.');
        }
        $class   = $this->safe_path_segment($class, 'class');
        $section = $this->safe_path_segment($section, 'section');

        // Class-ownership check (no-op for non-Teacher roles)
        if (!$this->_teacher_can_access($class, "Section {$section}")) {
            return $this->json_error('You are not assigned to this class/section.', 403);
        }

        // Decode arrays — accept JSON strings or PHP-encoded arrays
        $absent = $this->input->post('absent');
        $leave  = $this->input->post('leave');
        $late   = $this->input->post('late');
        if (is_string($absent)) $absent = json_decode($absent, true);
        if (is_string($leave))  $leave  = json_decode($leave,  true);
        if (is_string($late))   $late   = json_decode($late,   true);
        if (!is_array($absent)) $absent = [];
        if (!is_array($leave))  $leave  = [];
        if (!is_array($late))   $late   = [];

        // Server-resolved date — body MUST NOT carry one
        $serverDate = $this->_server_today();

        // Holiday gate
        if ($this->_is_holiday($serverDate)) {
            return $this->json_error('Cannot mark attendance on a declared holiday.', 422);
        }

        // Stage gate (also enforces lock + after-18:00)
        $stage = $this->_stage($class, $section, $serverDate);
        $this->_gate_write($stage, $reason, $this->admin_role ?? '');

        // Active roster — must exist
        $roster = $this->_get_active_roster($class, $section);
        if (empty($roster)) {
            return $this->json_error('No active students in this section.', 404);
        }

        // Build delta sets (intersect with roster — silently drop unknown ids;
        // first-claim wins so a student listed in absent + leave goes to absent)
        $absentSet = [];
        foreach ($absent as $sid) {
            $sid = is_string($sid) ? trim($sid) : '';
            if ($sid !== '' && isset($roster[$sid])) $absentSet[$sid] = true;
        }
        $leaveSet = [];
        foreach ($leave as $sid) {
            $sid = is_string($sid) ? trim($sid) : '';
            if ($sid !== '' && isset($roster[$sid]) && !isset($absentSet[$sid])) {
                $leaveSet[$sid] = true;
            }
        }
        $lateSet = [];
        foreach ($late as $entry) {
            if (is_string($entry)) $entry = ['studentId' => $entry];
            if (!is_array($entry)) continue;
            $sid = trim((string) ($entry['studentId'] ?? ''));
            if ($sid === '' || !isset($roster[$sid])) continue;
            if (isset($absentSet[$sid]) || isset($leaveSet[$sid])) continue;
            $lm = (int) ($entry['lateMinutes'] ?? 0);
            if ($lm < 0)   $lm = 0;
            if ($lm > 180) $lm = 180;   // sanity cap (3h)
            $lateSet[$sid] = $lm;
        }

        $sectionKey  = Firestore_service::buildSectionKey($class, $section);
        $classKeyN   = Firestore_service::classKey($class);
        $sectionKeyN = Firestore_service::sectionKey($section);
        $userId      = $this->admin_id ?: 'system';
        $role        = $this->admin_role ?: 'system';
        $nowIso      = $this->_server_now()->format('c');

        // ── PRE-FETCH (single network call) ───────────────────────────────
        // Fetch every existing attendance doc for this section+date in one
        // schoolWhere query. Replaces N per-student get() calls. Used to:
        //   (a) detect no-ops so we don't write/audit rows that didn't change
        //   (b) carry old values into the audit log for traceability
        $existing = [];
        try {
            $rows = $this->fs->schoolWhere('attendance', [
                ['date',       '==', $serverDate],
                ['sectionKey', '==', $sectionKey],
            ]);
            foreach ($rows as $entry) {
                $d = is_array($entry) ? ($entry['data'] ?? $entry) : null;
                if (!is_array($d)) continue;
                $sid = (string) ($d['studentId'] ?? '');
                if ($sid !== '') $existing[$sid] = $d;
            }
        } catch (\Exception $e) {
            // If the pre-fetch fails the loop falls back to "treat as new
            // mark" — slightly less rich audit but still safe.
            log_message('warning', 'attendance pre-fetch failed: ' . $e->getMessage());
        }

        // ── BUILD BATCH ──────────────────────────────────────────────────
        $attendanceWrites = [];   // docId => doc
        $auditWrites      = [];   // docId => doc
        $summaryUpdates   = [];   // studentId => ['mark' => char, 'name' => string]
        $updated          = [];
        $rejected         = [];

        $auditPrefix = "{$this->school_id}_A" . date('YmdHis');
        $auditSeq    = 0;

        foreach ($roster as $sid => $info) {
            // Admission gate — never mark before admission
            if (!$this->_admission_allows((string) $info['admissionDate'], $serverDate)) {
                $rejected[] = ['studentId' => $sid, 'reason' => 'before admission date'];
                continue;
            }

            // Resolve target status + late
            $newStatus = 'P'; $newLate = false; $newLm = 0;
            if (isset($absentSet[$sid]))    { $newStatus = 'A'; }
            elseif (isset($leaveSet[$sid])) { $newStatus = 'L'; }
            elseif (isset($lateSet[$sid]))  { $newStatus = 'P'; $newLate = true; $newLm = $lateSet[$sid]; }

            $prev      = $existing[$sid] ?? null;
            $oldStatus = is_array($prev) ? (string) ($prev['status']      ?? '')    : '';
            $oldLate   = is_array($prev) ? (bool)   ($prev['late']        ?? false) : false;
            $oldLm     = is_array($prev) ? (int)    ($prev['lateMinutes'] ?? 0)     : 0;

            if ($oldStatus === $newStatus && $oldLate === $newLate && $oldLm === $newLm) {
                continue;   // no-op; nothing to write, nothing to audit
            }

            $perDayId = "{$this->school_id}_{$serverDate}_{$sid}";
            $attendanceWrites[$perDayId] = [
                'schoolId'        => $this->school_id,
                'date'            => $serverDate,
                'studentId'       => $sid,
                'studentName'     => $info['name'],
                'sectionKey'      => $sectionKey,
                'className'       => $classKeyN,
                'section'         => $sectionKeyN,
                'status'          => $newStatus,
                'late'            => $newLate,
                'lateMinutes'     => $newLm,
                'lastUpdatedBy'   => $userId,
                'lastUpdatedRole' => $role,
                'lastUpdatedAt'   => $nowIso,
                'lastUpdateStage' => $stage,
            ];

            $auditId = $auditPrefix . '_' . sprintf('%04d', $auditSeq++) . sprintf('%04d', mt_rand(0, 9999));
            // Phase 6E — unified schema stamp (adds user/role/epoch/yearMonth/
            // expiresAt TTL). Governance-native userId/targetId/className kept;
            // fetch_audit_logs reads user??userId, target??targetId, class??className.
            $auditWrites[$auditId] = $this->_audit_stamp([
                'userId'        => $userId,
                'role'          => $role,
                'action'        => ($oldStatus === '') ? 'MARK' : 'EDIT',
                'stage'         => $stage,
                'targetType'    => 'student',
                'targetId'      => $sid,
                'date'          => $serverDate,
                'oldValue'      => ($oldStatus === '') ? null
                    : ['status' => $oldStatus, 'late' => $oldLate, 'lateMinutes' => $oldLm],
                'newValue'      => ['status' => $newStatus, 'late' => $newLate, 'lateMinutes' => $newLm],
                'changedFields' => array_values(array_filter([
                    $oldStatus !== $newStatus ? 'status'      : null,
                    $oldLate   !== $newLate   ? 'late'        : null,
                    $oldLm     !== $newLm     ? 'lateMinutes' : null,
                ])),
                'reason'        => $reason !== '' ? $reason : null,
                'className'     => $classKeyN,
                'section'       => $sectionKeyN,
            ]);

            $updated[] = $sid;

            // Canonical dayWise mark for the per-month summary: 'T' encodes a
            // late/tardy present, otherwise the raw status (P/A/L) — identical
            // encoding to the admin single-day/bulk paths (_syncDailyToFirestore).
            $summaryUpdates[$sid] = [
                'mark' => $newLate ? 'T' : $newStatus,
                'name' => (string) $info['name'],
            ];
        }

        // ── COMMIT ───────────────────────────────────────────────────────
        // batchSet returns the count of successful writes. We don't unwind
        // partial failures — Firestore set(merge:true) is idempotent, so
        // a retry of the whole save() converges to the intended state.
        $attExpected = count($attendanceWrites);
        $attWritten  = $attExpected;
        if (!empty($attendanceWrites)) {
            $okAtt = $this->fs->batchSet('attendance', $attendanceWrites);
            $attWritten = (int) $okAtt;
            if ($okAtt < $attExpected) {
                log_message('error', sprintf(
                    'attendance batchSet partial: %d/%d for school=%s section=%s date=%s',
                    $okAtt, $attExpected,
                    $this->school_id, $sectionKey, $serverDate
                ));
            }
        }
        if (!empty($auditWrites)) {
            try { $this->fs->batchSet('attendanceAuditLog', $auditWrites); }
            catch (\Exception $e) {
                log_message('error', 'attendanceAuditLog batchSet failed: ' . $e->getMessage());
            }
        }

        // ── P1 CONVERGENCE (add-only, Firestore-only) ─────────────────────
        // The batch above updates only the per-day `attendance` docs. The
        // Teacher/Parent apps, report cards and analytics read the per-MONTH
        // canonical `attendanceSummary.dayWise`, so without this step a bulk
        // teacher save persists but the month grid stays stale on refresh.
        // Reuse the SAME shared helper the admin single-day/bulk paths use
        // (_applyDayToSummary → _syncStudentSummaryToFirestore) — no duplicated
        // logic, no RTDB. Best-effort: a summary-sync failure is logged but
        // never fails the primary write (the per-day docs are already committed).
        if (!empty($summaryUpdates)) {
            $dt     = \DateTime::createFromFormat('Y-m-d', $serverDate);
            $attKey = $dt ? $dt->format('F Y') : date('F Y', strtotime($serverDate));
            $dayNum = $dt ? (int) $dt->format('j') : (int) date('j', strtotime($serverDate));
            foreach ($summaryUpdates as $sid => $u) {
                $this->_applyDayToSummary(
                    $sid, $class, $section, $dayNum, $attKey, $u['mark'], $u['name']
                );

                // ── PARENT NOTIFICATION (P1 fix, 2026-07-07) ──────────────
                // The Teacher app marks attendance THROUGH this endpoint. Before
                // this, a child marked Absent/Late from the app produced no push
                // and no in-app alert — only the admin single-day/bulk UI paths
                // notified. Reuse the SAME per-student event helper those paths
                // use so the contract (dedup, FCM channel, notifications doc) is
                // identical. Only 'A' (absent) and 'T' (tardy) notify; 'L'/'P'
                // do not. The per-student dedup inside _fire_single_student_event
                // (keyed student|date|mark) prevents a double-send if an admin
                // later re-saves the same day. Best-effort: a notify failure is
                // logged but never fails the already-committed attendance write.
                if ($u['mark'] === 'A' || $u['mark'] === 'T') {
                    try {
                        $this->_fire_single_student_event(
                            $sid, $class, $section, $u['mark'], $dayNum, $attKey
                        );
                    } catch (\Exception $e) {
                        log_message('error', 'attendance save() notify failed: ' . $e->getMessage());
                    }
                }
            }
        }

        $resp = [
            'updated'    => $updated,
            'rejected'   => $rejected,
            'date'       => $serverDate,
            'stage'      => $stage,
            'rosterSize' => count($roster),
        ];
        // audit M8: surface partial persistence instead of reporting a clean
        // success while some per-day docs failed to write. batchSet is
        // idempotent, so the app can safely retry the whole save.
        if ($attWritten < $attExpected) {
            $resp['partial']       = true;
            $resp['writtenCount']  = $attWritten;
            $resp['expectedCount'] = $attExpected;
        }
        return $this->json_success($resp);
    }

    /* ================================================================
       PHASE 2 — LOCK SYSTEM + CORRECTION FLOW
       ================================================================
       New endpoints:
         GET  /attendance/lock              → lock_get
         POST /attendance/lock/set          → lock_set            (admin)
         POST /attendance/cron/auto_lock    → cron_auto_lock      (cron / admin)
         POST /attendance/correction/submit → correction_submit   (teacher)
         GET  /attendance/correction/list   → correction_list
         POST /attendance/correction/decide → correction_decide   (admin)

       Adds collections:
         attendanceLocks                    (one doc per class+section+date)
         attendanceCorrectionRequests       (auto-id per request)

       Audit actions added:
         LOCK, UNLOCK, AUTO_LOCK,
         CORRECTION_REQUEST, CORRECTION_APPROVE, CORRECTION_REJECT
       ================================================================ */

    /**
     * Privileged write — bypasses _gate_write entirely.
     * Used only by correction_decide('approve') after admin review.
     * Always writes one EDIT audit row tied to the request id.
     */
    private function _privileged_write_attendance(
        string $serverDate, string $studentId, string $studentName,
        string $class, string $section, string $sectionKey,
        string $newStatus, bool $newLate, int $newLm,
        string $reason, ?string $requestId
    ): bool {
        $perDayId = "{$this->school_id}_{$serverDate}_{$studentId}";

        $prev      = $this->fs->get('attendance', $perDayId);
        $oldStatus = is_array($prev) ? (string) ($prev['status']      ?? '')    : '';
        $oldLate   = is_array($prev) ? (bool)   ($prev['late']        ?? false) : false;
        $oldLm     = is_array($prev) ? (int)    ($prev['lateMinutes'] ?? 0)     : 0;

        $isNoOp = ($oldStatus === $newStatus && $oldLate === $newLate && $oldLm === $newLm);

        $userId = $this->admin_id ?: 'system';
        $role   = $this->admin_role ?: 'system';
        $nowIso = $this->_server_now()->format('c');

        if (!$isNoOp) {
            $ok = $this->fs->set('attendance', $perDayId, [
                'schoolId'        => $this->school_id,
                'date'            => $serverDate,
                'studentId'       => $studentId,
                'studentName'     => $studentName,
                'sectionKey'      => $sectionKey,
                'className'       => Firestore_service::classKey($class),
                'section'         => Firestore_service::sectionKey($section),
                'status'          => $newStatus,
                'late'            => $newLate,
                'lateMinutes'     => $newLm,
                'lastUpdatedBy'   => $userId,
                'lastUpdatedRole' => $role,
                'lastUpdatedAt'   => $nowIso,
                'lastUpdateStage' => 'CORRECTION',
            ], true);
            if (!$ok) return false;

            // Converge the canonical per-month attendanceSummary for this day using
            // the SAME shared helper the mark/save/punch paths use, so an approved
            // correction is immediately reflected in every summary-reading surface
            // (Parent app, attendance register, dashboard, analytics, report card).
            // Firestore-only; 'T' encodes a late/tardy present (system convention).
            $dt = \DateTime::createFromFormat('Y-m-d', $serverDate);
            if ($dt) {
                $this->_applyDayToSummary(
                    $studentId, $class, $section,
                    (int) $dt->format('j'), $dt->format('F Y'),
                    ($newLate ? 'T' : $newStatus), $studentName
                );
            }

            $this->_audit_write([
                'action'        => 'EDIT',
                'stage'         => 'CORRECTION',
                'targetType'    => 'student',
                'targetId'      => $studentId,
                'date'          => $serverDate,
                'oldValue'      => ($oldStatus === '') ? null
                    : ['status' => $oldStatus, 'late' => $oldLate, 'lateMinutes' => $oldLm],
                'newValue'      => ['status' => $newStatus, 'late' => $newLate, 'lateMinutes' => $newLm],
                'changedFields' => array_values(array_filter([
                    $oldStatus !== $newStatus ? 'status'      : null,
                    $oldLate   !== $newLate   ? 'late'        : null,
                    $oldLm     !== $newLm     ? 'lateMinutes' : null,
                ])),
                'reason'        => $reason !== '' ? $reason : null,
                'requestId'     => $requestId,
                'className'     => Firestore_service::classKey($class),
                'section'       => Firestore_service::sectionKey($section),
            ]);
        }
        return true;
    }

    /* ────────────────────────  LOCK  ──────────────────────── */

    /**
     * GET /attendance/lock?class=&section=&date=
     * Returns current lock state + computed stage for the date.
     */
    public function lock_get()
    {
        $this->_require_role(self::VIEW_ROLES, 'lock_get');

        $class   = trim((string) $this->input->get('class'));
        $section = trim((string) $this->input->get('section'));
        $date    = trim((string) $this->input->get('date'));
        if ($class === '' || $section === '') {
            return $this->json_error('class and section are required.');
        }
        if ($date === '') $date = $this->_server_today();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $this->json_error('date must be YYYY-MM-DD.');
        }
        $class   = $this->safe_path_segment($class, 'class');
        $section = $this->safe_path_segment($section, 'section');

        return $this->json_success([
            'date'  => $date,
            'lock'  => $this->_get_lock($class, $section, $date),
            'stage' => $this->_stage($class, $section, $date),
        ]);
    }

    /**
     * POST /attendance/lock/set
     * Body: class, section, date, locked (bool|"true"|"1"), reason
     * Admin only. Unlock requires reason ≥10 chars; lock reason is optional.
     */
    public function lock_set()
    {
        $this->_require_role(self::MANAGE_ROLES, 'lock_set');

        $class   = trim((string) $this->input->post('class'));
        $section = trim((string) $this->input->post('section'));
        $date    = trim((string) $this->input->post('date'));
        $locked  = $this->input->post('locked');
        $reason  = trim((string) $this->input->post('reason'));

        if ($class === '' || $section === '' || $date === '') {
            return $this->json_error('class, section and date are required.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $this->json_error('date must be YYYY-MM-DD.');
        }

        if (is_string($locked)) $locked = strtolower(trim($locked));
        $lockedBool = ($locked === true || $locked === 1 || $locked === '1' || $locked === 'true');

        if (!$lockedBool && strlen($reason) < 10) {
            return $this->json_error('Unlock requires a reason (min 10 chars).', 400);
        }

        $class   = $this->safe_path_segment($class, 'class');
        $section = $this->safe_path_segment($section, 'section');
        $userId  = $this->admin_id ?: 'system';
        $nowIso  = $this->_server_now()->format('c');
        $cls     = Firestore_service::classKey($class);
        $sec     = Firestore_service::sectionKey($section);
        $docId   = $this->_lock_doc_id($class, $section, $date);

        $payload = [
            'schoolId'  => $this->school_id,
            'className' => $cls,
            'section'   => $sec,
            'date'      => $date,
            'locked'    => $lockedBool,
        ];
        if ($lockedBool) {
            $payload['lockedAt']   = $nowIso;
            $payload['lockedBy']   = $userId;
            if ($reason !== '') $payload['lockReason'] = $reason;
        } else {
            $payload['unlockedAt']   = $nowIso;
            $payload['unlockedBy']   = $userId;
            $payload['unlockReason'] = $reason;
        }

        if (!$this->fs->set('attendanceLocks', $docId, $payload, true)) {
            return $this->json_error('Failed to update lock.', 500);
        }

        // Misuse signal — count UNLOCK actions in the last 7 days for this
        // school. We tag the audit row with the running count so dashboards
        // and alert rules can light up on repeated unlocks.
        $unlocksLast7Days = 0;
        $frequentFlag     = false;
        if (!$lockedBool) {
            try {
                $weekAgoIso = $this->_server_now()->modify('-7 days')->format('c');
                $rows = $this->fs->schoolWhere('attendanceAuditLog', [
                    ['action', '==', 'UNLOCK'],
                ], null, 'ASC', 200);
                foreach ($rows as $entry) {
                    $d = is_array($entry) ? ($entry['data'] ?? $entry) : null;
                    if (!is_array($d)) continue;
                    $ts = (string) ($d['timestamp'] ?? '');
                    if ($ts !== '' && $ts >= $weekAgoIso) $unlocksLast7Days++;
                }
                // Include the unlock about to happen
                $unlocksLast7Days++;
                $frequentFlag = $unlocksLast7Days >= 3;
                if ($frequentFlag) {
                    log_message('warning', sprintf(
                        'frequent UNLOCK: school=%s admin=%s class=%s section=%s date=%s count7d=%d',
                        $this->school_id, $userId, $cls, $sec, $date, $unlocksLast7Days
                    ));
                }
            } catch (\Exception $e) {
                log_message('warning', 'unlock frequency check failed: ' . $e->getMessage());
            }
        }

        $auditRow = [
            'action'     => $lockedBool ? 'LOCK' : 'UNLOCK',
            'stage'      => 'LOCK',
            'targetType' => 'class',
            'targetId'   => "{$cls}|{$sec}",
            'date'       => $date,
            'newValue'   => ['locked' => $lockedBool],
            'reason'     => $reason !== '' ? $reason : null,
            'className'  => $cls,
            'section'    => $sec,
        ];
        if (!$lockedBool) {
            $auditRow['unlocksLast7Days'] = $unlocksLast7Days;
            if ($frequentFlag) $auditRow['flag'] = 'frequent_unlock';
        }
        $this->_audit_write($auditRow);

        return $this->json_success([
            'date'  => $date,
            'lock'  => $this->_get_lock($class, $section, $date),
            'stage' => $this->_stage($class, $section, $date),
        ]);
    }

    /**
     * Cron-only auth gate. Allows:
     *   • CLI execution (php_sapi_name === 'cli')
     *   • HTTP with X-Cron-Token header matching env ATTENDANCE_CRON_TOKEN
     * Rejects everything else with 403. Replaces session/role auth for
     * cron endpoints so a logged-in admin alone cannot trigger them.
     */
    private function _require_cron_token(): void
    {
        if (PHP_SAPI === 'cli') return;

        $configured = (string) (getenv('ATTENDANCE_CRON_TOKEN') ?: '');
        $provided   = (string) ($this->input->server('HTTP_X_CRON_TOKEN') ?? '');

        if ($configured === '' || $provided === '' || !hash_equals($configured, $provided)) {
            log_message('error', sprintf(
                'cron unauthorized: ip=%s ua=%s',
                $this->input->ip_address(),
                substr((string) $this->input->user_agent(), 0, 120)
            ));
            $this->json_error('Unauthorized cron call.', 403);
        }
    }

    /**
     * POST /attendance/cron/auto_lock
     * Locks every (class, section) that has any attendance for today.
     * Idempotent: skips already-locked (class, section, today).
     * Should be wired to a 6 PM scheduled job per school.
     *
     * Auth: cron token (or CLI). NOT session-gated — see _require_cron_token.
     */
    public function cron_auto_lock()
    {
        $this->_require_cron_token();

        $serverDate = $this->_server_today();
        $nowIso     = $this->_server_now()->format('c');

        $rows = [];
        try {
            $rows = $this->fs->schoolWhere('attendance', [
                ['date', '==', $serverDate],
            ]);
        } catch (\Exception $e) {
            log_message('error', 'cron_auto_lock query failed: ' . $e->getMessage());
            return $this->json_error('Query failed.', 500);
        }

        // Group by (className, section)
        $groups = [];
        foreach ($rows as $entry) {
            $d = is_array($entry) ? ($entry['data'] ?? $entry) : null;
            if (!is_array($d)) continue;
            $cn = (string) ($d['className'] ?? '');
            $sc = (string) ($d['section']   ?? '');
            if ($cn === '' || $sc === '') continue;
            $groups["{$cn}|{$sc}"] = ['className' => $cn, 'section' => $sc];
        }

        $lockWrites  = [];
        $auditWrites = [];
        $auditPrefix = "{$this->school_id}_A" . date('YmdHis');
        $auditSeq    = 0;

        foreach ($groups as $g) {
            $cn = $g['className']; $sc = $g['section'];
            $docId = $this->_lock_doc_id($cn, $sc, $serverDate);

            $existing = $this->fs->get('attendanceLocks', $docId);
            if (is_array($existing) && !empty($existing['locked'])) continue;  // already locked

            $lockWrites[$docId] = [
                'schoolId'   => $this->school_id,
                'className'  => $cn,
                'section'    => $sc,
                'date'       => $serverDate,
                'locked'     => true,
                'lockedAt'   => $nowIso,
                'lockedBy'   => 'system',
                'lockReason' => 'auto-lock 18:00',
            ];

            $auditId = $auditPrefix . '_' . sprintf('%04d', $auditSeq++) . sprintf('%04d', mt_rand(0, 9999));
            // Phase 6E — unified schema stamp (adds user/role/epoch/yearMonth/
            // expiresAt TTL). Actor 'system' resolves via the stamp in cron
            // context; governance-native userId/targetId/className retained.
            $auditWrites[$auditId] = $this->_audit_stamp([
                'userId'     => 'system',
                'role'       => 'system',
                'action'     => 'AUTO_LOCK',
                'stage'      => 'LOCK',
                'targetType' => 'class',
                'targetId'   => "{$cn}|{$sc}",
                'date'       => $serverDate,
                'newValue'   => ['locked' => true],
                'reason'     => 'auto-lock 18:00',
                'className'  => $cn,
                'section'    => $sc,
            ]);
        }

        $written = 0;
        if (!empty($lockWrites))  $written = $this->fs->batchSet('attendanceLocks', $lockWrites);
        if (!empty($auditWrites)) $this->fs->batchSet('attendanceAuditLog', $auditWrites);

        return $this->json_success([
            'date'             => $serverDate,
            'sectionsScanned'  => count($groups),
            'sectionsLocked'   => $written,
        ]);
    }

    /* ──────────────────────  CORRECTION  ────────────────────── */

    /**
     * Build a per-day-doc-shaped context for a student+date from the per-month
     * attendanceSummary when no per-day `attendance` doc exists. Lets a teacher
     * file a correction for any day the History grid shows (which is driven by
     * summary.dayWise), not only days that already have a per-day doc.
     *
     * Returns the same keys correction_submit reads off a per-day doc
     * (status/late/lateMinutes/className/section/studentName), or null when the
     * student has no summary for that month at all.
     *
     * @return array<string,mixed>|null
     */
    private function _correction_context_from_summary(string $studentId, string $date): ?array
    {
        $ym  = substr($date, 0, 7);                 // "2026-07"
        $day = (int) substr($date, 8, 2);           // 1..31
        if ($day < 1) return null;

        $sumId = "{$this->school_id}_{$studentId}_{$ym}";
        $sum   = $this->fs->get('attendanceSummary', $sumId);
        if (!is_array($sum)) return null;

        // Current mark = the dayWise char for this day (if any). 'T' encodes a
        // late present; P/A/L map straight through; anything else (V/H/-/blank/
        // out-of-range) is treated as "unmarked" so the correction simply sets it.
        $dayWise = (string) ($sum['dayWise'] ?? '');
        $char    = ($day <= strlen($dayWise)) ? strtoupper($dayWise[$day - 1]) : '';
        $status  = '';
        $late    = false;
        if ($char === 'T')            { $status = 'P'; $late = true; }
        elseif (in_array($char, ['P', 'A', 'L'], true)) { $status = $char; }

        return [
            'status'      => $status,
            'late'        => $late,
            'lateMinutes' => 0,
            'className'   => (string) ($sum['className']   ?? ''),
            'section'     => (string) ($sum['section']     ?? ''),
            'studentName' => (string) ($sum['studentName'] ?? $studentId),
        ];
    }

    /**
     * POST /attendance/correction/submit
     * Body: studentId, date, requestedMark{status,late?,lateMinutes?}, reason (≥10)
     * Teacher must be assigned to the student's class+section.
     */
    public function correction_submit()
    {
        $this->_require_role(self::MARK_ROLES, 'correction_submit');

        $studentId    = trim((string) $this->input->post('studentId'));
        $date         = trim((string) $this->input->post('date'));
        $reason       = trim((string) $this->input->post('reason'));
        $requestedRaw = $this->input->post('requestedMark');
        if (is_string($requestedRaw)) $requestedRaw = json_decode($requestedRaw, true);

        if ($studentId === '' || $date === '' || strlen($reason) < 10) {
            return $this->json_error('studentId, date and reason (min 10 chars) are required.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $this->json_error('date must be YYYY-MM-DD.');
        }
        if ($date > $this->_server_today()) {
            return $this->json_error('Cannot file a correction for a future date.');
        }

        if (!is_array($requestedRaw)) {
            return $this->json_error('requestedMark must be {status, late?, lateMinutes?}.');
        }
        $reqStatus = strtoupper((string) ($requestedRaw['status'] ?? ''));
        if (!in_array($reqStatus, ['P', 'A', 'L'], true)) {
            return $this->json_error('requestedMark.status must be P, A, or L.');
        }
        $reqLate = !empty($requestedRaw['late']);
        $reqLm   = (int) ($requestedRaw['lateMinutes'] ?? 0);
        if ($reqLm < 0)   $reqLm = 0;
        if ($reqLm > 180) $reqLm = 180;
        if ($reqStatus !== 'P') { $reqLate = false; $reqLm = 0; }

        // Fetch existing per-day attendance doc for context (+ ownership check).
        // FALLBACK: the History grid a teacher corrects from is rendered off the
        // per-MONTH attendanceSummary.dayWise, and many past days have NO per-day
        // `attendance` doc (attendance was never taken that day, or only ever
        // summarised). Hard-requiring the per-day doc made every such correction
        // fail with "No attendance record" even though the grid clearly showed a
        // mark — and re-marking the month in admin didn't help because those
        // paths converge the summary, not necessarily a per-day doc for each day.
        // So when the per-day doc is absent, derive the current mark + class/
        // section from the month summary. On approval,
        // _privileged_write_attendance writes the per-day doc and re-converges
        // the summary, so the record self-heals.
        $perDayId = "{$this->school_id}_{$date}_{$studentId}";
        $cur      = $this->fs->get('attendance', $perDayId);
        if (!is_array($cur)) {
            $cur = $this->_correction_context_from_summary($studentId, $date);
        }
        if (!is_array($cur)) {
            return $this->json_error('No attendance data exists for that student in that month — take attendance for the class first, then file a correction.', 404);
        }
        $className   = (string) ($cur['className']   ?? '');
        $section     = (string) ($cur['section']     ?? '');
        $studentName = (string) ($cur['studentName'] ?? $studentId);

        if (!$this->_teacher_can_access($className, $section)) {
            return $this->json_error('You are not assigned to this class/section.', 403);
        }

        // Reject if a pending request already exists for this student+date.
        // Same teacher submitting twice = no-op; different teacher = collision
        // that admin must resolve on the existing request first.
        try {
            $dups = $this->fs->schoolWhere('attendanceCorrectionRequests', [
                ['studentId', '==', $studentId],
                ['date',      '==', $date],
                ['status',    '==', 'pending'],
            ], null, 'ASC', 1);
            if (!empty($dups)) {
                $first    = $dups[0];
                $existing = is_array($first) ? ($first['data'] ?? $first) : null;
                $existingId = is_array($existing)
                    ? (string) ($existing['id'] ?? $existing['docId'] ?? '')
                    : '';
                http_response_code(409);
                header('Content-Type: application/json');
                echo json_encode([
                    'status'            => 'error',
                    'message'           => 'A pending correction already exists for this student on this date.',
                    'existingRequestId' => $existingId,
                    'csrf_token'        => $this->security->get_csrf_hash(),
                ]);
                exit;
            }
        } catch (\Exception $e) {
            // Index not deployed yet → don't block; submission proceeds.
            // Admin will see the duplicate when reviewing.
            log_message('warning', 'duplicate-pending check failed: ' . $e->getMessage());
        }

        $reqId   = "{$this->school_id}_C" . date('YmdHis') . sprintf('%04d', mt_rand(0, 9999));
        $userId  = $this->admin_id ?: 'system';
        $role    = $this->admin_role ?: 'system';
        $nowIso  = $this->_server_now()->format('c');

        $currentMark = [
            'status'      => (string) ($cur['status']      ?? ''),
            'late'        => (bool)   ($cur['late']        ?? false),
            'lateMinutes' => (int)    ($cur['lateMinutes'] ?? 0),
        ];
        $requestedMark = [
            'status'      => $reqStatus,
            'late'        => $reqLate,
            'lateMinutes' => $reqLm,
        ];

        $request = [
            'schoolId'        => $this->school_id,
            'requestedBy'     => $userId,
            'requestedByRole' => $role,
            'requestedAt'     => $nowIso,
            'requestedAtTs'   => time(),
            'className'       => $className,
            'section'         => $section,
            'date'            => $date,
            'studentId'       => $studentId,
            'studentName'     => $studentName,
            'currentMark'     => $currentMark,
            'requestedMark'   => $requestedMark,
            'reason'          => $reason,
            'status'          => 'pending',
        ];

        if (!$this->fs->set('attendanceCorrectionRequests', $reqId, $request, false)) {
            return $this->json_error('Failed to submit request.', 500);
        }

        $this->_audit_write([
            'action'     => 'CORRECTION_REQUEST',
            'stage'      => 'CORRECTION',
            'targetType' => 'student',
            'targetId'   => $studentId,
            'date'       => $date,
            'oldValue'   => $currentMark,
            'newValue'   => $requestedMark,
            'reason'     => $reason,
            'requestId'  => $reqId,
            'className'  => $className,
            'section'    => $section,
        ]);

        return $this->json_success([
            'requestId' => $reqId,
            'status'    => 'pending',
        ]);
    }

    /**
     * GET /attendance/correction/list?status=&date=
     * Admin/HR/Coordinator: see all (default status=pending).
     * Teacher: own requests only.
     */
    public function correction_list()
    {
        $this->_require_role(self::VIEW_ROLES, 'correction_list');

        $statusFilter = strtolower(trim((string) $this->input->get('status')));
        if ($statusFilter === '') $statusFilter = 'pending';
        if (!in_array($statusFilter, ['pending', 'approved', 'rejected', 'all'], true)) {
            $statusFilter = 'pending';
        }
        $dateFilter = trim((string) $this->input->get('date'));

        // Pagination — cursor on requestedAtTs (descending). Caller passes
        // back the last page's `nextCursor` to fetch the next chunk.
        $limit = (int) ($this->input->get('limit') ?: 25);
        if ($limit < 1)   $limit = 1;
        if ($limit > 100) $limit = 100;
        $cursor = (int) $this->input->get('cursor');

        $conds = [];
        if ($statusFilter !== 'all') $conds[] = ['status', '==', $statusFilter];
        if ($dateFilter !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) {
            $conds[] = ['date', '==', $dateFilter];
        }
        if (strcasecmp($this->admin_role ?? '', 'Teacher') === 0) {
            $conds[] = ['requestedBy', '==', $this->admin_id];
        }
        if ($cursor > 0) {
            $conds[] = ['requestedAtTs', '<', $cursor];
        }

        $rows = [];
        try {
            $rows = $this->fs->schoolWhere(
                'attendanceCorrectionRequests',
                $conds,
                'requestedAtTs', 'DESC',
                $limit
            );
        } catch (\Exception $e) {
            log_message('error', 'correction_list query failed: ' . $e->getMessage());
        }

        $out        = [];
        $nextCursor = null;
        foreach ($rows as $entry) {
            $d = is_array($entry) ? ($entry['data'] ?? $entry) : null;
            if (!is_array($d)) continue;
            // Expose the Firestore doc id (already on $entry['id']) so the
            // admin UI can call /correction/decide with the right requestId.
            $d['requestId'] = $entry['id'] ?? ($d['requestId'] ?? null);
            $out[] = $d;
            $nextCursor = (int) ($d['requestedAtTs'] ?? 0);
        }
        // Only expose nextCursor when the page is full — otherwise we've
        // reached the end and the client should stop paginating.
        if (count($out) < $limit) $nextCursor = null;

        return $this->json_success([
            'requests'   => $out,
            'count'      => count($out),
            'limit'      => $limit,
            'nextCursor' => $nextCursor,
            'filter'     => ['status' => $statusFilter, 'date' => $dateFilter],
        ]);
    }

    /* ================================================================
       STAFF GPS-ATTENDANCE REGULARIZATION REVIEW (admin / HR)
       ----------------------------------------------------------------
       The Teacher app files regularization requests (missed/failed GPS
       punches) into `attendanceRegularizations` (personType='staff',
       status='pending'). Pre-fix there was NO admin surface to act on
       them, so staff had no path to fix a missed punch except the manual
       grid. These two endpoints list and decide those requests; approval
       stamps the corrected day via the SAME canonical writer the GPS
       punch uses (source='correction'), keeping counts/workingDays right.
       ================================================================ */

    /** Roles allowed to approve/reject staff regularizations (adds HR Manager). */
    private const STAFF_REG_DECIDE_ROLES = [
        'Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'HR Manager',
    ];

    /**
     * POST /attendance/staff_regularization/list
     * Body: status? (pending|approved|rejected|cancelled|auto_rejected|all), limit?
     * Lists staff regularization requests for this school, newest date first,
     * also grouped by batchId so the UI can show one request (many dates) together.
     */
    public function staff_regularization_list()
    {
        $this->_require_role(self::VIEW_ROLES, 'staff_regularization_list');

        $statusFilter = strtolower(trim((string) $this->input->post('status')));
        $allowed = ['pending', 'approved', 'rejected', 'cancelled', 'auto_rejected', 'all'];
        if (!in_array($statusFilter, $allowed, true)) $statusFilter = 'pending';

        $limit = (int) $this->input->post('limit');
        if ($limit < 1 || $limit > 200) $limit = 100;

        // Equality-only conditions (schoolId auto-added by schoolWhere) → served by
        // single-field indexes; no composite index required, no orderBy.
        $conds = [['personType', '==', 'staff']];
        if ($statusFilter !== 'all') $conds[] = ['status', '==', $statusFilter];

        $rows = [];
        try {
            $rows = $this->fs->schoolWhere('attendanceRegularizations', $conds);
        } catch (\Exception $e) {
            log_message('error', 'staff_regularization_list query failed: ' . $e->getMessage());
        }

        $out = [];
        foreach ($rows as $entry) {
            $d = is_array($entry) ? ($entry['data'] ?? $entry) : null;
            if (!is_array($d)) continue;
            $d['requestId'] = $entry['id'] ?? ($d['requestId'] ?? null);
            $out[] = $d;
        }
        usort($out, static function ($a, $b) {
            return strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''));
        });
        if (count($out) > $limit) $out = array_slice($out, 0, $limit);

        $batches = [];
        foreach ($out as $d) {
            $bid = (string) ($d['batchId'] ?? $d['requestId'] ?? '');
            if ($bid === '') continue;
            $batches[$bid][] = $d;
        }

        return $this->json_success([
            'requests' => $out,
            'batches'  => $batches,
            'count'    => count($out),
            // NB: key is 'filter', NOT 'status' — json_success() merges
            // ['status'=>'success'] with this array, so a 'status' key here would
            // overwrite the success marker (the client then read res.status!='success').
            'filter'   => $statusFilter,
        ]);
    }

    /**
     * POST /attendance/staff_regularization/decide
     * Body: doc_id (single) OR batch_id (whole request); decision (approve|reject);
     *       remarks?; mark? (optional final mark override, defaults to requestedStatus).
     * Approve = stamp the day via Staff_attendance_writer (source='correction') +
     * mark the request approved. Reject = mark rejected only. Month-locked days are
     * skipped (reported), never silently dropped.
     */
    public function staff_regularization_decide()
    {
        $this->_require_role(self::STAFF_REG_DECIDE_ROLES, 'staff_regularization_decide');

        $docId    = trim((string) $this->input->post('doc_id'));
        $batchId  = trim((string) $this->input->post('batch_id'));
        $decision = strtolower(trim((string) $this->input->post('decision')));
        $remarks  = trim((string) $this->input->post('remarks'));
        $override = strtoupper(trim((string) $this->input->post('mark')));   // optional

        if (!in_array($decision, ['approve', 'reject'], true)) {
            return $this->json_error('decision (approve|reject) is required.');
        }
        if ($docId === '' && $batchId === '') {
            return $this->json_error('doc_id or batch_id is required.');
        }

        // ── Resolve the target request docs (single or whole batch) ──
        $targets = [];
        if ($docId !== '') {
            $doc = $this->fs->get('attendanceRegularizations', $docId);
            if (!is_array($doc)) return $this->json_error('Request not found.', 404);
            $doc['requestId'] = $docId;
            $targets[] = $doc;
        } else {
            try {
                $rows = $this->fs->schoolWhere('attendanceRegularizations', [
                    ['batchId',    '==', $batchId],
                    ['personType', '==', 'staff'],
                ]);
            } catch (\Exception $e) {
                return $this->json_error('Could not load request batch.', 500);
            }
            foreach ($rows as $entry) {
                $d = is_array($entry) ? ($entry['data'] ?? $entry) : null;
                if (!is_array($d)) continue;
                $d['requestId'] = $entry['id'] ?? null;
                $targets[] = $d;
            }
            if (empty($targets)) return $this->json_error('No requests found for this batch.', 404);
        }

        $userId = $this->admin_id ?: 'system';
        $nowIso = $this->_server_now()->format('c');

        $staffStatus = ['P', 'A', 'L', 'H', 'T', 'V', 'M', 'W', 'O'];
        $overrideOk  = ($override !== '' && in_array($override, $staffStatus, true));

        $writerReady = false;
        $applied = [];
        $skipped = [];

        foreach ($targets as $d) {
            $rid = (string) ($d['requestId'] ?? '');
            // Tenant + type + state guards (mirror correction_decide).
            if ((string) ($d['schoolId'] ?? '') !== $this->school_id) { $skipped[] = ['id' => $rid, 'reason' => 'not_found']; continue; }
            if ((string) ($d['personType'] ?? '') !== 'staff')        { $skipped[] = ['id' => $rid, 'reason' => 'not_staff']; continue; }
            if ((string) ($d['status'] ?? 'pending') !== 'pending')   { $skipped[] = ['id' => $rid, 'reason' => 'already_decided']; continue; }

            $staffId = (string) ($d['staffId'] ?? '');
            $date    = (string) ($d['date'] ?? '');
            if ($staffId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $skipped[] = ['id' => $rid, 'reason' => 'bad_request'];
                continue;
            }

            if ($decision === 'reject') {
                $this->fs->set('attendanceRegularizations', $rid, [
                    'status'     => 'rejected',
                    'reviewedBy' => $userId,
                    'reviewedAt' => $nowIso,
                    'remarks'    => $remarks,
                ], true);
                $this->_audit_write([
                    'action' => 'STAFF_REG_REJECT', 'stage' => 'CORRECTION',
                    'targetType' => 'staff', 'targetId' => $staffId, 'date' => $date,
                    'reason' => $remarks !== '' ? $remarks : null, 'requestId' => $rid,
                ]);
                $applied[] = ['id' => $rid, 'date' => $date, 'result' => 'rejected'];
                continue;
            }

            // ── SELF-APPROVAL GUARD (audit finding H1) ──────────────────
            // The approver ($userId) is the reviewer's own Firebase uid
            // (MY_Controller: admin_id = RAW uid). A privileged reviewer
            // (Principal/VP/HR) must NOT approve their OWN missed-punch
            // regularization — that is self-service payroll fraud with a
            // clean audit naming them as both subject and approver. Require a
            // different approver. (Self-REJECT is harmless and still allowed.)
            if ($staffId !== '' && $staffId === $userId) {
                $skipped[] = ['id' => $rid, 'reason' => 'self_approval_forbidden'];
                continue;
            }

            // ── approve: apply the corrected mark via the canonical writer ──
            // requestedStatus is constrained to {P, M} by the Firestore create
            // rule + the app; the override (admin manual) may be any staff mark.
            $reqMark   = strtoupper((string) ($d['requestedStatus'] ?? 'P'));
            $finalMark = $overrideOk ? $override
                : (in_array($reqMark, $staffStatus, true) ? $reqMark : 'P');

            try {
                if (!$writerReady) {
                    $this->load->library('staff_attendance_writer');
                    $this->staff_attendance_writer->init($this->firebase, $this->school_id, $this->session_year);
                    $writerReady = true;
                }
                $this->staff_attendance_writer->markSingleDay($staffId, $date, $finalMark, [
                    'markedBy'     => 'admin:' . $userId,
                    'source'       => 'correction',
                    'correctionId' => $rid,
                ]);
            } catch (MonthLockedException $e) {
                $skipped[] = ['id' => $rid, 'reason' => 'month_locked'];
                continue;
            } catch (\Throwable $e) {
                log_message('error', 'staff_regularization_decide writer failed for ' . $rid . ': ' . $e->getMessage());
                $skipped[] = ['id' => $rid, 'reason' => 'write_failed'];
                continue;
            }

            $this->fs->set('attendanceRegularizations', $rid, [
                'status'      => 'approved',
                'appliedMark' => $finalMark,
                'reviewedBy'  => $userId,
                'reviewedAt'  => $nowIso,
                'remarks'     => $remarks,
            ], true);
            $this->_audit_write([
                'action' => 'STAFF_REG_APPROVE', 'stage' => 'CORRECTION',
                'targetType' => 'staff', 'targetId' => $staffId, 'date' => $date,
                'newValue' => $finalMark, 'reason' => $remarks !== '' ? $remarks : null,
                'requestId' => $rid,
            ]);
            $applied[] = ['id' => $rid, 'date' => $date, 'result' => 'approved', 'mark' => $finalMark];
        }

        return $this->json_success([
            'decision'     => $decision,
            'applied'      => $applied,
            'skipped'      => $skipped,
            'appliedCount' => count($applied),
            'skippedCount' => count($skipped),
        ]);
    }

    /**
     * POST /attendance/correction/decide
     * Body: requestId, decision ("approve"|"reject"), note?, overrideMark?
     * Admin only. Approve = privileged write to attendance + status=approved.
     * Reject = status=rejected only.
     */
    public function correction_decide()
    {
        $this->_require_role(self::MANAGE_ROLES, 'correction_decide');

        $reqId    = trim((string) $this->input->post('requestId'));
        $decision = strtolower(trim((string) $this->input->post('decision')));
        $note     = trim((string) $this->input->post('note'));
        $override = $this->input->post('overrideMark');
        if (is_string($override)) $override = json_decode($override, true);

        if ($reqId === '' || !in_array($decision, ['approve', 'reject'], true)) {
            return $this->json_error('requestId and decision (approve|reject) are required.');
        }

        $req = $this->fs->get('attendanceCorrectionRequests', $reqId);
        if (!is_array($req)) {
            return $this->json_error('Request not found.', 404);
        }
        if ((string) ($req['schoolId'] ?? '') !== $this->school_id) {
            // BUG-034: tenant-boundary security telemetry (Phase 6+ scope; mirror Homework BUG-014)
            if (isset($this->sec_telem) && $this->sec_telem->isReady()) {
                $this->sec_telem->emit('CROSS_TENANT_PROBE', 'warning', [
                    'endpoint'       => __FUNCTION__,
                    'request_id'     => $reqId,
                    'request_school' => (string) ($req['schoolId'] ?? ''),
                    'caller_school'  => $this->school_id,
                ]);
            }
            // BUG-036: existence-oracle collapse — match truly-not-found branch shape; CROSS_TENANT_PROBE telemetry above preserves forensic capability (mirror Homework v1 BUG-015 pattern)
            return $this->json_error('Request not found.', 404);
        }
        if (((string) ($req['status'] ?? 'pending')) !== 'pending') {
            return $this->json_error('Request already decided.', 409);
        }

        $userId = $this->admin_id ?: 'system';
        $nowIso = $this->_server_now()->format('c');

        $studentId   = (string) ($req['studentId']   ?? '');
        $studentName = (string) ($req['studentName'] ?? $studentId);
        $date        = (string) ($req['date']        ?? '');
        $className   = (string) ($req['className']   ?? '');
        $section     = (string) ($req['section']     ?? '');

        if ($decision === 'reject') {
            $this->fs->set('attendanceCorrectionRequests', $reqId, [
                'status'       => 'rejected',
                'reviewedBy'   => $userId,
                'reviewedAt'   => $nowIso,
                'reviewedAtTs' => time(),
                'reviewNote'   => $note,
            ], true);

            $this->_audit_write([
                'action'     => 'CORRECTION_REJECT',
                'stage'      => 'CORRECTION',
                'targetType' => 'student',
                'targetId'   => $studentId,
                'date'       => $date,
                'reason'     => $note !== '' ? $note : null,
                'requestId'  => $reqId,
                'className'  => $className,
                'section'    => $section,
            ]);

            return $this->json_success(['decision' => 'rejected']);
        }

        // ── approve ──
        // Drift check: re-read the live attendance doc and compare against
        // the snapshot the request was filed with. If something changed in
        // the meantime, refuse unless admin explicitly passes force=true.
        $force = $this->input->post('force');
        $force = ($force === true || $force === 1 || $force === '1' || $force === 'true');

        $liveDoc   = $this->fs->get('attendance', "{$this->school_id}_{$date}_{$studentId}");
        $liveMark  = [
            'status'      => is_array($liveDoc) ? (string) ($liveDoc['status']      ?? '')    : '',
            'late'        => is_array($liveDoc) ? (bool)   ($liveDoc['late']        ?? false) : false,
            'lateMinutes' => is_array($liveDoc) ? (int)    ($liveDoc['lateMinutes'] ?? 0)     : 0,
        ];
        $reqSnapshot = is_array($req['currentMark'] ?? null) ? $req['currentMark'] : [];
        $expMark = [
            'status'      => (string) ($reqSnapshot['status']      ?? ''),
            'late'        => (bool)   ($reqSnapshot['late']        ?? false),
            'lateMinutes' => (int)    ($reqSnapshot['lateMinutes'] ?? 0),
        ];
        $drift = ($liveMark['status'] !== $expMark['status']
               || $liveMark['late']   !== $expMark['late']
               || $liveMark['lateMinutes'] !== $expMark['lateMinutes']);

        if ($drift && !$force) {
            http_response_code(409);
            header('Content-Type: application/json');
            echo json_encode([
                'status'     => 'error',
                'message'    => 'Live attendance has changed since this request was filed. Pass force=true to override.',
                'expected'   => $expMark,
                'current'    => $liveMark,
                'csrf_token' => $this->security->get_csrf_hash(),
            ]);
            exit;
        }

        $reqMark = is_array($req['requestedMark'] ?? null) ? $req['requestedMark'] : [];
        if (is_array($override)) {
            if (isset($override['status']))      $reqMark['status']      = $override['status'];
            if (isset($override['late']))        $reqMark['late']        = $override['late'];
            if (isset($override['lateMinutes'])) $reqMark['lateMinutes'] = $override['lateMinutes'];
        }

        $newStatus = strtoupper((string) ($reqMark['status'] ?? ''));
        if (!in_array($newStatus, ['P', 'A', 'L'], true)) {
            return $this->json_error('Invalid status in approval payload.', 400);
        }
        $newLate = !empty($reqMark['late']);
        $newLm   = (int) ($reqMark['lateMinutes'] ?? 0);
        if ($newLm < 0)   $newLm = 0;
        if ($newLm > 180) $newLm = 180;
        if ($newStatus !== 'P') { $newLate = false; $newLm = 0; }

        $sectionKey = Firestore_service::buildSectionKey($className, $section);
        $reasonStr  = (string) ($req['reason'] ?? '');

        if (!$this->_privileged_write_attendance(
            $date, $studentId, $studentName,
            $className, $section, $sectionKey,
            $newStatus, $newLate, $newLm,
            $reasonStr, $reqId
        )) {
            return $this->json_error('Failed to apply correction.', 500);
        }

        // PARENT NOTIFICATION — an approved correction that lands on Absent or
        // Tardy must notify the parent, exactly like the daily save() path. Before
        // this, flipping a child to Absent via a correction was silent (only the
        // mark/save/bulk paths notified). Reuse the SAME per-student event helper
        // so the FCM channel + notifications doc + dedup contract are identical;
        // the per-student dedup (keyed student|date|mark) makes a re-approval of
        // the same mark a no-op. Best-effort: a notify failure never fails the
        // already-applied correction.
        //
        // GUARD: only for TODAY's corrections. _fire_single_student_event stamps
        // the event with the SERVER's current date (it was built for the today-only
        // save path), so firing it for a back-dated correction would tell the
        // parent the child was absent *today* — wrong. Past-date corrections are
        // silently applied (the mark + summary still update everywhere).
        $notifMark = $newLate ? 'T' : $newStatus;
        if (($notifMark === 'A' || $notifMark === 'T') && $date === $this->_server_today()) {
            try {
                $dt2     = \DateTime::createFromFormat('Y-m-d', $date);
                $attKey2 = $dt2 ? $dt2->format('F Y') : date('F Y', strtotime($date));
                $dayNum2 = $dt2 ? (int) $dt2->format('j') : (int) date('j', strtotime($date));
                $this->_fire_single_student_event(
                    $studentId, $className, $section, $notifMark, $dayNum2, $attKey2
                );
            } catch (\Exception $e) {
                log_message('error', 'correction_decide notify failed: ' . $e->getMessage());
            }
        }

        $this->fs->set('attendanceCorrectionRequests', $reqId, [
            'status'       => 'approved',
            'reviewedBy'   => $userId,
            'reviewedAt'   => $nowIso,
            'reviewedAtTs' => time(),
            'reviewNote'   => $note,
            'forceApplied' => ($drift && $force),
            'liveMarkAtApproval' => $liveMark,
            'appliedMark'  => [
                'status'      => $newStatus,
                'late'        => $newLate,
                'lateMinutes' => $newLm,
            ],
        ], true);

        $this->_audit_write([
            'action'      => 'CORRECTION_APPROVE',
            'stage'       => 'CORRECTION',
            'targetType'  => 'student',
            'targetId'    => $studentId,
            'date'        => $date,
            'newValue'    => ['status' => $newStatus, 'late' => $newLate, 'lateMinutes' => $newLm],
            'reason'      => $note !== '' ? $note : null,
            'requestId'   => $reqId,
            'className'   => $className,
            'section'     => $section,
            'forceApplied'=> ($drift && $force),
            'driftFromExpected' => $drift,
        ]);

        return $this->json_success([
            'decision'    => 'approved',
            'appliedMark' => ['status' => $newStatus, 'late' => $newLate, 'lateMinutes' => $newLm],
        ]);
    }

    /* ================================================================
       PHASE 3 — GET /attendance/summary  (compute on read)
       ================================================================
       Counts P/A/L/late from the canonical `attendance` collection on
       demand. NO write-time aggregation. Costs one Firestore query per
       call; result is small (one row) and the existing
       (schoolId, sectionKey, date) composite index covers both modes:

         • Daily:    ?class=…&section=…&date=YYYY-MM-DD
         • Monthly:  ?class=…&section=…&month=YYYY-MM
                     (translates to date >= start AND date <= end)

       Response: { present, absent, leave, late, total, scope }
       ================================================================ */
    public function summary()
    {
        $this->_require_role(self::VIEW_ROLES, 'attendance/summary');

        $class   = trim((string) $this->input->get('class'));
        $section = trim((string) $this->input->get('section'));
        $date    = trim((string) $this->input->get('date'));
        $month   = trim((string) $this->input->get('month'));

        if ($class === '' || $section === '') {
            return $this->json_error('class and section are required.');
        }

        // Class-ownership check — non-admins limited to their assigned classes
        if (!$this->_teacher_can_access($class, "Section {$section}")) {
            return $this->json_error('You are not assigned to this class/section.', 403);
        }

        $class   = $this->safe_path_segment($class, 'class');
        $section = $this->safe_path_segment($section, 'section');
        $sectionKey = Firestore_service::buildSectionKey($class, $section);

        // Resolve date range
        $rangeStart = ''; $rangeEnd = ''; $scopeLabel = '';
        if ($date !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return $this->json_error('date must be YYYY-MM-DD.');
            }
            $rangeStart = $date;
            $rangeEnd   = $date;
            $scopeLabel = $date;
        } elseif ($month !== '') {
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                return $this->json_error('month must be YYYY-MM.');
            }
            [$y, $m] = array_map('intval', explode('-', $month));
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $m, $y);
            $rangeStart  = sprintf('%04d-%02d-01',  $y, $m);
            $rangeEnd    = sprintf('%04d-%02d-%02d', $y, $m, $daysInMonth);
            $scopeLabel  = $month;
        } else {
            // Default — today
            $today      = $this->_server_today();
            $rangeStart = $today;
            $rangeEnd   = $today;
            $scopeLabel = $today;
        }

        // Build the query. Equalities + range on `date` is supported by
        // the (schoolId, sectionKey, date) composite index.
        $conds = [
            ['sectionKey', '==', $sectionKey],
            ['date',       '>=', $rangeStart],
            ['date',       '<=', $rangeEnd],
        ];

        $rows = [];
        try {
            $rows = $this->fs->schoolWhere('attendance', $conds, 'date', 'ASC');
        } catch (\Exception $e) {
            log_message('error', 'attendance/summary query failed: ' . $e->getMessage());
            return $this->json_error('Query failed.', 500);
        }

        // Tally in-memory. Single pass, O(n).
        $present = $absent = $leave = $late = $holiday = $vacation = 0;
        foreach ($rows as $entry) {
            $d = is_array($entry) ? ($entry['data'] ?? $entry) : null;
            if (!is_array($d)) continue;
            $status = (string) ($d['status'] ?? '');
            $isLate = (bool)   ($d['late']   ?? false);
            switch ($status) {
                case 'P': $present++;  if ($isLate) $late++; break;
                case 'A': $absent++;   break;
                case 'L': $leave++;    break;
                case 'H': $holiday++;  break;
                case 'V': $vacation++; break;
            }
        }
        $total = $present + $absent + $leave;

        return $this->json_success([
            'present'  => $present,
            'absent'   => $absent,
            'leave'    => $leave,
            'late'     => $late,
            'holiday'  => $holiday,
            'vacation' => $vacation,
            'total'    => $total,
            'scope'    => [
                'class'   => Firestore_service::classKey($class),
                'section' => Firestore_service::sectionKey($section),
                'range'   => $scopeLabel,
                'from'    => $rangeStart,
                'to'      => $rangeEnd,
                'mode'    => $date !== '' ? 'daily' : ($month !== '' ? 'monthly' : 'today'),
            ],
        ]);
    }
}
