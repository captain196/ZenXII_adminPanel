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
     * Dashboard stats — today's actual attendance counts (students + staff).
     * Reads today's mark (single character) from each student/staff attendance string.
     */
    public function dashboard_stats()
    {
        $this->_require_role(self::VIEW_ROLES, 'dashboard_stats');

        // Phase 8b/10: process pending teacher requests before
        // computing stats. Best-effort — don't let a failure break
        // the dashboard.
        try { $this->_process_pending_push_requests(); } catch (\Exception $e) {}
        try { $this->_process_approved_leaves(); } catch (\Exception $e) {}

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
        } catch (\Exception $e) { $todayAttDocs = []; }
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
            } catch (\Exception $e) { $attSummaryDocs = []; }
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
        // (c) RTDB fallback — only fires when both Firestore reads returned
        // empty (e.g. partial migration: school not yet backfilled).
        if ($stuTotal === 0) {
            try {
                $classList = $this->_build_class_list();
                foreach ($classList as $cls) {
                    // R5 — roster from Firestore via Roster_helper. Bulk
                    // RTDB read kept ONLY as the per-student attendance
                    // fallback (`$allStudents[$id]['Attendance']` below);
                    // its roster role is gone.
                    $secRoot     = $this->_resolve_section_root($cls['class_name'], $cls['section']);
                    $secList     = $this->_get_section_students($cls['class_name'], $cls['section']);
                    if (empty($secList)) continue;
                    $allStudents = $this->firebase->get("{$secRoot}/Students");
                    if (!is_array($allStudents)) $allStudents = [];
                    foreach ($secList as $studentId => $name) {
                        if (!is_string($studentId) || trim($studentId) === '') continue;
                        $attStr = isset($allStudents[$studentId]['Attendance'][$attKey])
                            && is_string($allStudents[$studentId]['Attendance'][$attKey])
                            ? $allStudents[$studentId]['Attendance'][$attKey] : '';
                        $stuTotal++;
                        if (strlen($attStr) < $today) continue;
                        $mark = strtoupper($attStr[$today - 1]);
                        if ($mark === 'P') $stuP++;
                        elseif ($mark === 'A') $stuA++;
                        elseif ($mark === 'T') $stuT++;
                        elseif ($mark === 'L') $stuL++;
                    }
                }
            } catch (\Exception $e) { /* leave totals at zero */ }
        }

        // ── Staff stats — Firestore FIRST ──
        $staffP = 0; $staffA = 0; $staffT = 0; $staffTotal = 0;
        try {
            $staffAttDocs = $this->fs->schoolWhere('attendance', [
                ['date', '==', $todayDate],
                ['type', '==', 'staff'],
            ]);
        } catch (\Exception $e) { $staffAttDocs = []; }
        foreach ($staffAttDocs as $doc) {
            $mark = strtoupper($doc['data']['status'] ?? 'V');
            $staffTotal++;
            if ($mark === 'P') $staffP++;
            elseif ($mark === 'A') $staffA++;
            elseif ($mark === 'T') $staffT++;
        }
        // Fall back to summary strings (still Firestore)
        if ($staffTotal === 0) {
            try {
                $staffSummaryDocs = $this->fs->schoolWhere('attendanceSummary', [
                    ['month', '==', date('Y-m')],
                    ['type', '==', 'staff'],
                ]);
            } catch (\Exception $e) { $staffSummaryDocs = []; }
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
        // RTDB fallback — only fires when both Firestore reads returned empty.
        if ($staffTotal === 0) {
            try {
                $teachers = $this->firebase->get("Schools/{$school}/{$session}/Teachers");
                $staffAtt = $this->firebase->get("Schools/{$school}/{$session}/Staff_Attendance/{$attKey}");
                if (!is_array($staffAtt)) $staffAtt = [];
                if (is_array($teachers)) {
                    foreach ($teachers as $staffId => $profile) {
                        if (!is_string($staffId) || trim($staffId) === '') continue;
                        $attStr = isset($staffAtt[$staffId]) && is_string($staffAtt[$staffId])
                            ? $staffAtt[$staffId] : '';
                        $staffTotal++;
                        if (strlen($attStr) < $today) continue;
                        $mark = strtoupper($attStr[$today - 1]);
                        if ($mark === 'P') $staffP++;
                        elseif ($mark === 'A') $staffA++;
                        elseif ($mark === 'T') $staffT++;
                    }
                }
            } catch (\Exception $e) { /* leave totals at zero */ }
        }

        // Count pending student leave applications
        $pendingLeaves = 0;
        try {
            $leaveDocs = $this->fs->schoolWhere('leaveApplications', [
                ['status',        '==', 'pending'],
                ['applicantType', '==', 'student'],
            ]);
            $pendingLeaves = count($leaveDocs);
        } catch (\Exception $e) {}

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
     * Phase 7x diagnostic — returns exactly what Push_service / Device_service
     * see for a given userId. Hit this in the browser when a push fails:
     *   GET /attendance/debug_push?user=STU0001
     */
    public function debug_push()
    {
        $this->_require_role(self::MANAGE_ROLES, 'debug_push');
        $userId = trim((string) $this->input->get('user'));
        if ($userId === '') return $this->json_error('user query param is required');

        $this->load->library('device_service');
        $this->load->library('push_service');

        $devices = $this->device_service->listDevices($userId);
        $tokens  = $this->device_service->getFcmTokens($userId);

        // Firestore canonical store (Phase 8a)
        $firestoreDocs = [];
        try {
            $firestoreDocs = $this->fs->where('userDevices', [
                ['userId', '==', $userId],
            ]);
        } catch (\Exception $e) {
            $firestoreDocs = ['error' => $e->getMessage()];
        }

        // RTDB legacy mirror
        $rawRtdbNode = $this->firebase->get("Users/Devices/{$userId}");

        $diag = [
            'userId'                  => $userId,
            'firestore_userDevices'   => $firestoreDocs,
            'firestore_count'         => is_array($firestoreDocs) ? count($firestoreDocs) : 0,
            'rtdb_users_devices_node' => $rawRtdbNode,
            'rtdb_parsed_devices'     => $devices,
            'rtdb_parsed_count'       => count($devices),
            'eligible_tokens'         => array_map(function ($t) {
                return strlen($t) > 20 ? substr($t, 0, 20) . '... (' . strlen($t) . ' chars)' : $t;
            }, $tokens),
            'eligible_tokens_count'   => count($tokens),
            'next_step'               => count($tokens) > 0
                ? 'Tokens look good. If push still not arriving, problem is on the FCM gateway side — check the PHP log for sendMulticast errors.'
                : 'NO ELIGIBLE TOKENS. Both Firestore userDevices collection and RTDB Users/Devices node are empty or missing fcmToken. The parent/teacher app has not registered yet — open it on a real device, log in, and retry.',
        ];
        return $this->json_success($diag);
    }

    /**
     * Phase 8a diagnostic — manually register an FCM token from the
     * admin browser when the Android app can't (emulator without Play
     * Services, build issues, etc.).
     *
     *   POST /attendance/register_test_token
     *   Params: user_id, fcm_token
     *
     * Writes to Firestore `userDevices` + RTDB mirror so the full
     * push pipeline can be tested end-to-end from the admin side.
     */
    public function register_test_token()
    {
        $this->_require_role(self::MANAGE_ROLES, 'register_test_token');
        $userId   = trim((string) $this->input->post('user_id'));
        $fcmToken = trim((string) $this->input->post('fcm_token'));
        if ($userId === '' || $fcmToken === '') {
            return $this->json_error('user_id and fcm_token are required.');
        }
        $deviceId = 'ADMIN_MANUAL_' . substr(md5($fcmToken), 0, 8);
        $now = date('c');

        $doc = [
            'schoolId'   => $this->school_id,
            'userId'     => $userId,
            'deviceId'   => $deviceId,
            'fcmToken'   => $fcmToken,
            'platform'   => 'android',
            'status'     => 'active',
            'lastActive' => $now,
            'appRole'    => 'parent',
            'source'     => 'admin_manual',
        ];

        // Firestore canonical write
        $fsOk = false;
        try {
            $fsDocId = "{$userId}_{$deviceId}";
            $fsOk = (bool) $this->fs->set('userDevices', $fsDocId, $doc, true);
        } catch (\Exception $e) {
            return $this->json_error('Firestore write failed: ' . $e->getMessage());
        }

        // RTDB mirror
        try {
            $this->firebase->set("Users/Devices/{$userId}/{$deviceId}", [
                'fcmToken'   => $fcmToken,
                'status'     => 'active',
                'platform'   => 'android',
                'lastActive' => $now,
            ]);
        } catch (\Exception $e) { /* mirror best-effort */ }

        return $this->json_success([
            'message'     => "Token registered for {$userId}. Firestore: " . ($fsOk ? 'YES' : 'NO') . ". Now mark the student absent to test the push.",
            'firestore_doc' => "{$userId}_{$deviceId}",
            'rtdb_path'     => "Users/Devices/{$userId}/{$deviceId}",
        ]);
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

        // Clean expired ProcessedEvents
        $eventsPath = "Schools/{$school}/{$session}/Attendance/ProcessedEvents";
        $events = $this->firebase->get($eventsPath);
        if (is_array($events)) {
            foreach ($events as $eventId => $data) {
                if (!is_array($data)) continue;
                $expiresAt = $data['expires_at'] ?? 0;
                if ($expiresAt > 0 && $expiresAt <= $now) {
                    $this->firebase->delete("{$eventsPath}/{$eventId}");
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

        // Flush async queue if present
        $queueFlushed = $this->_flush_queue();

        return $this->json_success([
            'expired_events_deleted' => $deleted,
            'stale_cache_cleaned'    => $staleFiles,
            'queue_flushed'          => $queueFlushed,
        ]);
    }

    /**
     * One-time migration: rename a wrongly-computed attendance month key.
     * POST: old_key (e.g. "March 2027"), new_key (e.g. "March 2026")
     *
     * Moves data for ALL students in ALL class/sections + staff attendance + late metadata.
     * Safe to run multiple times (idempotent — skips if old_key doesn't exist).
     */
    public function fix_attendance_keys()
    {
        $this->_require_role(self::MANAGE_ROLES, 'fix_attendance_keys');

        $oldKey = trim((string) $this->input->post('old_key'));
        $newKey = trim((string) $this->input->post('new_key'));

        if (!$oldKey || !$newKey || $oldKey === $newKey) {
            return $this->json_error('old_key and new_key are required and must differ.');
        }
        // Validate format: "MonthName YYYY"
        if (!preg_match('/^[A-Z][a-z]+ \d{4}$/', $oldKey) || !preg_match('/^[A-Z][a-z]+ \d{4}$/', $newKey)) {
            return $this->json_error('Keys must be in format "March 2026".');
        }

        $school  = $this->school_name;
        $session = $this->session_year;
        $migrated = ['students' => 0, 'student_late' => 0, 'staff' => 0, 'staff_late' => 0];

        // ── 1. Student attendance: {sectionRoot}/Students/{id}/Attendance/{key} ──
        // R5 — roster from Firestore. Per-student RTDB attendance reads
        // (oldPath/newPath below) are unchanged; only the discovery list
        // moved off RTDB.
        $classList = $this->_build_class_list();
        foreach ($classList as $cls) {
            $secRoot = $this->_resolve_section_root($cls['class_name'], $cls['section']);
            $list    = $this->_get_section_students($cls['class_name'], $cls['section']);
            if (empty($list)) continue;

            foreach ($list as $studentId => $name) {
                if (!is_string($studentId) || trim($studentId) === '') continue;
                $oldPath = "{$secRoot}/Students/{$studentId}/Attendance/{$oldKey}";
                $data = $this->firebase->get($oldPath);
                if ($data === null) continue;

                // Copy to new key, delete old
                $newPath = "{$secRoot}/Students/{$studentId}/Attendance/{$newKey}";
                $this->firebase->set($newPath, $data);
                $this->firebase->delete($oldPath);
                $migrated['students']++;
            }
        }

        // ── 2. Student late metadata: Schools/{school}/{session}/Attendance/Late/{key} ──
        $oldLatePath = "Schools/{$school}/{$session}/Attendance/Late/{$oldKey}";
        $lateData = $this->firebase->get($oldLatePath);
        if (is_array($lateData) && !empty($lateData)) {
            $newLatePath = "Schools/{$school}/{$session}/Attendance/Late/{$newKey}";
            $this->firebase->set($newLatePath, $lateData);
            $this->firebase->delete($oldLatePath);
            $migrated['student_late'] = count($lateData);
        }

        // ── 3. Staff attendance: Schools/{school}/{session}/Staff_Attendance/{key} ──
        $oldStaffPath = "Schools/{$school}/{$session}/Staff_Attendance/{$oldKey}";
        $staffAtt = $this->firebase->get($oldStaffPath);
        if (is_array($staffAtt) || is_string($staffAtt)) {
            $newStaffPath = "Schools/{$school}/{$session}/Staff_Attendance/{$newKey}";
            $this->firebase->set($newStaffPath, $staffAtt);
            $this->firebase->delete($oldStaffPath);
            $migrated['staff'] = is_array($staffAtt) ? count($staffAtt) : 1;
        }

        // ── 4. Staff late metadata: Schools/{school}/{session}/Staff_Attendance/Late/{key} ──
        $oldStaffLatePath = "Schools/{$school}/{$session}/Staff_Attendance/Late/{$oldKey}";
        $staffLate = $this->firebase->get($oldStaffLatePath);
        if (is_array($staffLate) && !empty($staffLate)) {
            $newStaffLatePath = "Schools/{$school}/{$session}/Staff_Attendance/Late/{$newKey}";
            $this->firebase->set($newStaffLatePath, $staffLate);
            $this->firebase->delete($oldStaffLatePath);
            $migrated['staff_late'] = count($staffLate);
        }

        // ── 5. Summary cache (just delete — will be recomputed) ──
        $oldSummaryPath = "Schools/{$school}/{$session}/Attendance/Summary/Students/{$oldKey}";
        $this->firebase->delete($oldSummaryPath);

        return $this->json_success([
            'message'  => "Migrated '{$oldKey}' → '{$newKey}'",
            'migrated' => $migrated,
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

        $schoolId = $this->school_name;
        $logPath  = "System/Logs/Attendance/{$schoolId}/{$yearMonth}";
        $rawLogs  = $this->firebase->get($logPath);

        $logs = [];
        if (is_array($rawLogs)) {
            foreach ($rawLogs as $logId => $entry) {
                if (!is_array($entry)) continue;

                // Apply filters
                if ($filterAction && ($entry['action'] ?? '') !== $filterAction) continue;
                if ($filterUser && ($entry['user'] ?? '') !== $filterUser) continue;
                if ($filterClass && ($entry['class'] ?? '') !== $filterClass) continue;
                if ($filterTarget && ($entry['target'] ?? '') !== $filterTarget) continue;

                $entry['log_id'] = $logId;
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

        // Lazy-loaded RTDB caches — only populated on first fallback hit
        // so we don't pay an RTDB read when Firestore has every student.
        $rtdbSectionStudents = null;       // {$sectionRoot}/Students
        $rtdbLateMap = null;               // Schools/{school}/{session}/Attendance/Late/{attKey}

        // Batch-read attendance summaries for all students in this month
        $students = [];
        foreach ($list as $studentId => $studentName) {
            // ── PER-STUDENT: Firestore FIRST ──
            $summaryDocId = $this->fs->docId2($studentId, $monthKey);
            $summaryDoc = $this->fs->get('attendanceSummary', $summaryDocId);
            $attStr  = $summaryDoc['dayWise']   ?? '';
            $lateRaw = $summaryDoc['lateTimes'] ?? [];

            // ── PER-STUDENT: RTDB fallback for the dayWise string ──
            if ($attStr === '') {
                if ($rtdbSectionStudents === null) {
                    $loaded = $this->firebase->get("{$sectionRoot}/Students");
                    $rtdbSectionStudents = is_array($loaded) ? $loaded : [];
                }
                if (isset($rtdbSectionStudents[$studentId]['Attendance'][$attKey])
                    && is_string($rtdbSectionStudents[$studentId]['Attendance'][$attKey])
                ) {
                    $attStr = $rtdbSectionStudents[$studentId]['Attendance'][$attKey];
                }
            }

            // ── PER-STUDENT: RTDB fallback for arrival times ──
            if (empty($lateRaw)) {
                if ($rtdbLateMap === null) {
                    $loaded = $this->firebase->get("Schools/{$school}/{$session}/Attendance/Late/{$attKey}");
                    $rtdbLateMap = is_array($loaded) ? $loaded : [];
                }
                if (isset($rtdbLateMap[$studentId]) && is_array($rtdbLateMap[$studentId])) {
                    $lateRaw = $rtdbLateMap[$studentId];
                }
            }

            if (!is_array($lateRaw)) $lateRaw = [];
            $attStr = str_pad($attStr, $daysInMonth, 'V');

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
        $nonWorking = get_non_working_days($this->firebase, $school, $monthNum, $year);
        $rules = $this->_att_rules();
        $pastLimit = (int)($rules['allow_past_edit_days'] ?? 0);
        $requireApproval = !empty($rules['require_approval_for_backdated']);

        // ── DATE GOVERNANCE (bulk): validate the first non-V, non-H mark's date ──
        // For bulk saves, check if the string contains any marks for future or past days
        $sampleStr = reset($attData);
        if (is_string($sampleStr)) {
            $govResult = att_validate_date_governance(
                $sampleStr, $daysInMonth, $monthNum, $year, $pastLimit, $requireApproval
            );
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
                    $reqId = $this->_create_pending_request('student_bulk', [
                        'target_id' => 'bulk', 'class' => $class, 'section' => $section,
                        'month' => $month, 'data' => $diffData, 'data_format' => 'diff',
                        'audit' => $auditBulk,
                    ]);
                    return $this->json_success([
                        'message'    => 'Backdated attendance submitted for admin approval.',
                        'request_id' => $reqId,
                        'pending'    => true,
                    ]);
                }
                return $this->json_error($govResult['error']);
            }
        }

        $saved = 0;
        $monthKey = date('Y-m', mktime(0, 0, 0, $monthNum, 1, $year));
        $sectionRoot = $this->_resolve_section_root($class, $section);

        // Resolve a studentId → name map once so each Firestore write
        // can stamp `studentName`. This makes derived-roster reads
        // (Strategy 3 in `_get_section_students`) return real names
        // instead of falling back to the studentId.
        $nameMap = $this->_get_section_students($class, $section);

        // B1 — Cache Active-status per studentId to avoid N+1 Firestore
        // reads inside the bulk loop. Pre-fetched once via Roster_helper
        // (which already filters status='Active' at the source).
        $activeRosterIds = [];
        $rosterRows = $this->roster->for_class($class, $section);
        foreach ($rosterRows as $rid => $_unused) { $activeRosterIds[$rid] = true; }

        foreach ($attData as $studentId => $attString) {
            $studentId = trim((string) $studentId);
            if (!preg_match('/^[A-Za-z0-9_]+$/', $studentId)) continue;

            // B1 — Attendance status gate. Reject marks for any student
            // whose Firestore doc is not status='Active' (Inactive / TC /
            // Deleted). Pre-fix this loop accepted any well-formed
            // studentId in the POST, so a stale form / scripted POST
            // could land marks on withdrawn students.
            if (!isset($activeRosterIds[$studentId])) {
                log_message('warning',
                    "save_student_attendance: skipped non-Active student {$studentId} "
                    . "in {$class}/{$section} — status gate (B1)"
                );
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

            // Build late metadata
            $lateMap = [];
            if (is_array($lateData) && isset($lateData[$studentId]) && is_array($lateData[$studentId])) {
                foreach ($lateData[$studentId] as $day => $time) {
                    $day = (int) $day;
                    if ($day < 1 || $day > $daysInMonth) continue;
                    $time = preg_replace('/[^0-9:]/', '', (string) $time);
                    if ($time) $lateMap[$day] = ['time' => $time];
                }
            }

            // ── WRITE: Firestore FIRST (canonical store) ──
            $studentName = $nameMap[$studentId] ?? $studentId;
            $summaryDocId = $this->fs->docId2($studentId, $monthKey);
            $fsOk = (bool) $this->fs->set('attendanceSummary', $summaryDocId, [
                'schoolId'   => $this->school_id,
                'studentId'  => $studentId,
                'studentName'=> $studentName,
                'type'       => 'student',
                'className'  => Firestore_service::classKey($class),
                'section'    => Firestore_service::sectionKey($section),
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
            ], true);

            // ── RTDB mirror (best-effort) — skip if Firestore failed ──
            // Stays until Phase 8 per Firestore-first migration contract.
            if ($fsOk) {
                try {
                    // Mirror the dayWise string at the canonical RTDB path
                    $attPath = "{$sectionRoot}/Students/{$studentId}/Attendance/{$attKey}";
                    $this->firebase->set($attPath, $cleanStr);

                    // Mirror per-day arrival times so RTDB-driven views still see them
                    foreach ($lateMap as $day => $entry) {
                        $latePath = "Schools/{$school}/{$session}/Attendance/Late/{$attKey}/{$studentId}/{$day}";
                        $this->firebase->set($latePath, $entry);
                    }
                } catch (\Exception $e) {
                    log_message('error', "save_student_attendance RTDB mirror failed for {$studentId}: " . $e->getMessage());
                }
            }

            $saved++;
        }

        $this->_log_attendance_change('BULK_SAVE_STUDENT', [
            'class' => $class, 'section' => $section, 'month' => $attKey, 'count' => $saved,
        ]);

        // Fire communication events for newly absent/late students
        $this->_fire_student_att_events($class, $section, $attKey);

        return $this->json_success(['saved' => $saved]);
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

        // Block marking on Sundays/holidays — must be 'H'
        $this->load->helper('attendance');
        if ($mark !== 'H' && is_non_working_day($this->firebase, $school, $day, $monthNum, $year)) {
            return $this->json_error("Day {$day} is a holiday/Sunday. Cannot mark as {$mark}.");
        }

        // ── DATE GOVERNANCE: block future, route past to approval ──
        $govCheck = $this->_check_day_governance($day, $monthNum, $year, $mark, [
            'class' => $class, 'section' => $section, 'month' => $month,
            'student_id' => $studentId, 'day' => $day, 'mark' => $mark,
        ]);
        if ($govCheck !== null) {
            if (!empty($govCheck['needs_approval'])) {
                // Fetch old mark for audit trail
                $sr = $this->_resolve_section_root($class, $section);
                $curStr = $this->firebase->get("{$sr}/Students/{$studentId}/Attendance/{$attKey}");
                $oldMk = (is_string($curStr) && isset($curStr[$day - 1])) ? $curStr[$day - 1] : 'V';
                $reqId = $this->_create_pending_request('student_day', [
                    'target_id' => $studentId, 'class' => $class, 'section' => $section,
                    'month' => $month, 'day' => $day, 'mark' => $mark,
                    'audit' => ['old_value' => $oldMk, 'new_value' => $mark],
                ]);
                return $this->json_success([
                    'message'    => 'Backdated attendance submitted for approval.',
                    'request_id' => $reqId,
                    'pending'    => true,
                ]);
            }
            return $this->json_error($govCheck['error'] ?? 'Date validation failed.');
        }

        // Read-modify-write with lock to prevent concurrent overwrites
        $sectionRoot = $this->_resolve_section_root($class, $section);
        $attPath = "{$sectionRoot}/Students/{$studentId}/Attendance/{$attKey}";

        if (!$this->_acquire_att_lock($attPath)) {
            return $this->json_error('Another attendance update is in progress. Try again.', 409);
        }

        $existing = $this->firebase->get($attPath);
        $attStr = is_string($existing) ? $existing : str_repeat('V', $daysInMonth);
        $attStr = str_pad($attStr, $daysInMonth, 'V');

        // Compute the new month string in memory.
        $oldMark = $attStr[$day - 1];
        $attStr[$day - 1] = $mark;

        // ── Firestore-first (Phase 7a fix) ─────────────────────────────
        // Daily attendance doc is the canonical store; the RTDB month
        // string is now a best-effort mirror. If Firestore fails we
        // release the lock and bail BEFORE touching RTDB so the two
        // stores can never disagree on this day.
        // Single-student name lookup — Firestore-only (R5).
        // Replaces `firebase->get("{$sectionRoot}/Students/List/{$studentId}")`.
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

        // ── RTDB mirror ────────────────────────────────────────────────
        $this->firebase->set($attPath, $attStr);
        $this->_release_att_lock($attPath);

        // Handle late time — set if T, clean up if changed FROM T
        $latePath = "Schools/{$school}/{$session}/Attendance/Late/{$attKey}/{$studentId}/{$day}";
        if ($mark === 'T' && $lateTime) {
            $lateTime = preg_replace('/[^0-9:]/', '', $lateTime);
            $this->firebase->set($latePath, ['time' => $lateTime]);
        } elseif ($mark !== 'T') {
            $this->firebase->delete($latePath);
        }

        $this->_log_attendance_change('MARK_STUDENT_DAY', [
            'target' => $studentId, 'class' => $class, 'section' => $section,
            'day' => $day, 'month' => $attKey, 'old' => $oldMark, 'new' => $mark,
        ]);

        // Incrementally update cached summary (avoids full recompute)
        $this->_update_summary_incremental($class, $section, $attKey, $studentId, $oldMark, $mark);

        // Centralized summary update
        $studentBase = "{$sectionRoot}/Students/{$studentId}";
        update_student_att_summary($this->firebase, $studentBase, $school, $attKey, $monthNum, $year);

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

        // RTDB mirror (best-effort)
        $count = 0;
        foreach ($list as $studentId => $name) {
            if (!is_string($studentId) || trim($studentId) === '') continue;

            $attPath = "{$sectionRoot}/Students/{$studentId}/Attendance/{$attKey}";
            $existing = $this->firebase->get($attPath);
            $attStr = is_string($existing) ? $existing : str_repeat('V', $daysInMonth);
            $attStr = str_pad($attStr, $daysInMonth, 'V');
            $attStr[$day - 1] = $mark;
            $this->firebase->set($attPath, $attStr);
            $count++;
        }

        $this->_log_attendance_change('BULK_MARK_STUDENT', [
            'class' => $class, 'section' => $section, 'day' => $day,
            'month' => $attKey, 'mark' => $mark, 'count' => $count,
        ]);

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
            $fsDocs = [];
        }

        if (!empty($fsDocs)) {
            foreach ($fsDocs as $entry) {
                $d = is_array($entry) ? ($entry['data'] ?? $entry) : null;
                if (!is_array($d)) continue;
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
        } else {
            // ── RTDB fallback (legacy / pre-migration data) ──
            $sectionRoot = $this->_resolve_section_root($class, $section);
            $basePath = "{$sectionRoot}/Students/{$studentId}/Attendance";
            $allAtt = $this->firebase->get($basePath);
            if (is_array($allAtt)) {
                foreach ($allAtt as $monthKey => $attStr) {
                    if (!is_string($attStr)) continue;
                    $stats = $this->_compute_month_stats($attStr);
                    $summary[$monthKey] = $stats;
                    foreach (['P', 'A', 'L', 'H', 'T', 'V'] as $ch) {
                        $totals[$ch] += $stats[$ch];
                    }
                    $totals['total_days'] += strlen($attStr);
                }
            }
        }

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
     * Fetch staff attendance for a month
     * POST: month
     */
    public function fetch_staff_attendance()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_staff_att');
        $month = trim((string) $this->input->post('month'));

        if (!$month || !isset($this->month_map[$month])) {
            return $this->json_error('Invalid month.');
        }

        $school  = $this->school_name;
        $session = $this->session_year;
        $year    = $this->_resolve_year($month);
        $monthNum = $this->month_map[$month];
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);
        $attKey = "{$month} {$year}";
        $attKeySafe = str_replace(' ', '_', $attKey); // "April_2026" — used in staffAttendanceSummary doc id

        // Roster — Firestore-first
        $allTeachers = null;
        try {
            $fsDocs = $this->fs->schoolWhere('staff', [['status', '==', 'Active']]);
            if (!empty($fsDocs)) {
                $allTeachers = [];
                foreach ($fsDocs as $doc) {
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $sid = $d['staffId'] ?? $d['userId'] ?? '';
                    if ($sid !== '') {
                        $allTeachers[$sid] = [
                            'Name'       => $d['Name'] ?? $d['name'] ?? $sid,
                            'Department' => $d['Department'] ?? $d['department'] ?? '',
                            'Designation'=> $d['designation'] ?? $d['Position'] ?? $d['position'] ?? '',
                        ];
                    }
                }
            }
        } catch (\Exception $e) {}
        // RTDB fallback only on Firestore exception
        if ($allTeachers === null) {
            $allTeachers = $this->firebase->get("Schools/{$school}/{$session}/Teachers");
        }

        // ── READ: Firestore FIRST for the per-staff dayWise strings ──
        $allStaffAtt = [];
        $monthKeyISO = sprintf('%04d-%02d', $year, $monthNum); // "2026-04"
        try {
            // Try both month formats: "2026-04" (ISO) and "April 2026" (label)
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
                $sid = $d['staffId'] ?? '';
                $dw  = $d['dayWise'] ?? '';
                if ($sid !== '' && is_string($dw)) {
                    $allStaffAtt[$sid] = $dw;
                }
            }
        } catch (\Exception $e) {
            $allStaffAtt = [];
        }

        // No RTDB fallback for dayWise strings — empty Firestore = valid (no attendance marked yet)

        // Per-day late times — still RTDB (no Firestore staff lateTimes yet)
        $allStaffLate = $this->firebase->get("Schools/{$school}/{$session}/Staff_Attendance/Late/{$attKey}");
        if (!is_array($allStaffLate)) $allStaffLate = [];
        $staffList = [];

        if (is_array($allTeachers)) {
            foreach ($allTeachers as $staffId => $profile) {
                if (!is_string($staffId) || trim($staffId) === '') continue;
                $name = is_array($profile) ? ($profile['Name'] ?? $staffId) : (string) $staffId;

                // Extract attendance from batch-read
                $attStr = isset($allStaffAtt[$staffId]) && is_string($allStaffAtt[$staffId])
                    ? $allStaffAtt[$staffId] : '';
                // Pad the attendance string to daysInMonth (JS expects a string, not array)
                $attStr = str_pad($attStr, $daysInMonth, 'V');

                // Extract late data from batch-read and normalize shape
                $lateRaw = isset($allStaffLate[$staffId]) && is_array($allStaffLate[$staffId])
                    ? $allStaffLate[$staffId] : [];

                $dept = is_array($profile) ? ($profile['Department'] ?? '') : '';
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
     * Save staff attendance for a month
     * POST: month, attendance (JSON: {staffId: "PPAP...", ...}), late (JSON)
     */
    public function save_staff_attendance()
    {
        $this->_require_role(self::MARK_ROLES, 'save_staff_att');
        $month   = trim((string) $this->input->post('month'));
        $attData = $this->input->post('attendance');
        $lateData = $this->input->post('late');

        if (!$month || !$attData) {
            return $this->json_error('Missing required fields.');
        }
        if (!isset($this->month_map[$month])) {
            return $this->json_error('Invalid month.');
        }

        $school  = $this->school_name;
        $session = $this->session_year;
        $year    = $this->_resolve_year($month);
        $attKey  = "{$month} {$year}";

        // Check attendance lock
        $lock = $this->_check_staff_att_lock($attKey);
        if ($lock) {
            return $this->json_error("Staff attendance for {$attKey} is locked (locked by {$lock['locked_by']} on " . substr($lock['locked_at'], 0, 10) . "). Unlock before editing.");
        }

        $monthNum = $this->month_map[$month];
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);

        if (is_string($attData)) $attData = json_decode($attData, true);
        if (is_string($lateData)) $lateData = json_decode($lateData, true);
        if (!is_array($attData)) return $this->json_error('Invalid data.');

        // Load holidays + governance
        $this->load->helper('attendance');
        $nonWorking = get_non_working_days($this->firebase, $school, $monthNum, $year);
        $rules = $this->_att_rules();
        $pastLimit = (int)($rules['allow_past_edit_days'] ?? 0);
        $requireApproval = !empty($rules['require_approval_for_backdated']);

        // Bulk date governance check
        $sampleStr = reset($attData);
        if (is_string($sampleStr)) {
            $govResult = att_validate_date_governance(
                $sampleStr, $daysInMonth, $monthNum, $year, $pastLimit, $requireApproval
            );
            if (!$govResult['ok']) {
                if (!empty($govResult['needs_approval'])) {
                    // Compute diff: store only changed days per staff
                    $diffData = [];
                    $auditBulk = [];
                    foreach ($attData as $sid => $newStr) {
                        $sid = trim((string)$sid);
                        if (!preg_match('/^[A-Za-z0-9_]+$/', $sid)) continue;
                        $curStr = $this->firebase->get("Schools/{$school}/{$session}/Staff_Attendance/{$attKey}/{$sid}");
                        $curStr = is_string($curStr) ? str_pad($curStr, $daysInMonth, 'V') : str_repeat('V', $daysInMonth);
                        $changes = [];
                        for ($d = 0; $d < $daysInMonth && $d < strlen($newStr); $d++) {
                            if ($newStr[$d] !== $curStr[$d]) {
                                $changes[$d + 1] = $newStr[$d];
                                $auditBulk[$sid][$d + 1] = ['old' => $curStr[$d], 'new' => $newStr[$d]];
                            }
                        }
                        if (!empty($changes)) $diffData[$sid] = $changes;
                    }
                    if (empty($diffData)) {
                        return $this->json_success(['saved' => 0, 'message' => 'No changes detected.']);
                    }
                    $reqId = $this->_create_pending_request('staff_bulk', [
                        'target_id' => 'bulk', 'month' => $month, 'data' => $diffData, 'data_format' => 'diff',
                        'audit' => $auditBulk,
                    ]);
                    return $this->json_success([
                        'message' => 'Backdated staff attendance submitted for approval.',
                        'request_id' => $reqId, 'pending' => true,
                    ]);
                }
                return $this->json_error($govResult['error']);
            }
        }

        // Pre-load staff names so the Firestore docs include them.
        $allStaff = $this->firebase->get("Schools/{$school}/{$session}/Teachers");
        if (!is_array($allStaff)) $allStaff = [];

        $saved = 0;
        foreach ($attData as $staffId => $attString) {
            $staffId = trim((string) $staffId);
            if (!preg_match('/^[A-Za-z0-9_]+$/', $staffId)) continue;

            $cleanStr = $this->_sanitize_att_string($attString, $daysInMonth);
            $cleanStr = enforce_holidays_on_string($cleanStr, $daysInMonth, $nonWorking);

            $staffName = '';
            if (isset($allStaff[$staffId]) && is_array($allStaff[$staffId])) {
                $staffName = (string)($allStaff[$staffId]['Name'] ?? $allStaff[$staffId]['name'] ?? '');
            }

            // Phase 7b — Firestore primary write for the monthly summary.
            // Best-effort here because bulk save is a hot path; failures
            // are logged but don't block the rest of the batch.
            $this->_syncStaffSummaryToFirestore($staffId, $attKey, $cleanStr, $staffName);

            $attPath = "Schools/{$school}/{$session}/Staff_Attendance/{$attKey}/{$staffId}";
            $this->firebase->set($attPath, $cleanStr);
            $saved++;

            // Write RTDB summary cache (legacy).
            update_staff_att_summary($this->firebase, $school, $session, $staffId, $attKey, $monthNum, $year);

            if (is_array($lateData) && isset($lateData[$staffId]) && is_array($lateData[$staffId])) {
                foreach ($lateData[$staffId] as $day => $time) {
                    $day = (int) $day;
                    if ($day < 1 || $day > $daysInMonth) continue;
                    $time = preg_replace('/[^0-9:]/', '', (string) $time);
                    if ($time) {
                        $latePath = "Schools/{$school}/{$session}/Staff_Attendance/Late/{$attKey}/{$staffId}/{$day}";
                        $this->firebase->set($latePath, ['time' => $time]);
                    }
                }
            }
        }

        $this->_log_attendance_change('BULK_SAVE_STAFF', [
            'month' => $attKey, 'count' => $saved,
        ]);

        return $this->json_success(['saved' => $saved]);
    }

    /**
     * Quick-mark single staff member, single day
     * POST: month, staff_id, day, mark, late_time (optional)
     */
    public function mark_staff_day()
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

        $school  = $this->school_name;
        $session = $this->session_year;
        $year    = $this->_resolve_year($month);
        $attKey  = "{$month} {$year}";

        // Check attendance lock
        $lock = $this->_check_staff_att_lock($attKey);
        if ($lock) {
            return $this->json_error("Staff attendance for {$attKey} is locked. Unlock before editing.");
        }

        $monthNum = $this->month_map[$month];
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);

        if ($day < 1 || $day > $daysInMonth) {
            return $this->json_error('Invalid day.');
        }

        // Block marking on holidays/Sundays
        $this->load->helper('attendance');
        if ($mark !== 'H' && is_non_working_day($this->firebase, $school, $day, $monthNum, $year)) {
            return $this->json_error("Day {$day} is a holiday/Sunday. Cannot mark as {$mark}.");
        }

        // ── DATE GOVERNANCE ──
        $govCheck = $this->_check_day_governance($day, $monthNum, $year, $mark, [
            'staff_id' => $staffId, 'month' => $month, 'day' => $day, 'mark' => $mark,
        ]);
        if ($govCheck !== null) {
            if (!empty($govCheck['needs_approval'])) {
                // Fetch old mark for audit trail
                $curStr = $this->firebase->get("Schools/{$school}/{$session}/Staff_Attendance/{$attKey}/{$staffId}");
                $oldMk = (is_string($curStr) && isset($curStr[$day - 1])) ? $curStr[$day - 1] : 'V';
                $reqId = $this->_create_pending_request('staff_day', [
                    'target_id' => $staffId, 'month' => $month, 'day' => $day, 'mark' => $mark,
                    'audit' => ['old_value' => $oldMk, 'new_value' => $mark],
                ]);
                return $this->json_success([
                    'message' => 'Backdated staff attendance submitted for approval.',
                    'request_id' => $reqId, 'pending' => true,
                ]);
            }
            return $this->json_error($govCheck['error'] ?? 'Date validation failed.');
        }

        $attPath = "Schools/{$school}/{$session}/Staff_Attendance/{$attKey}/{$staffId}";

        if (!$this->_acquire_att_lock($attPath)) {
            return $this->json_error('Another attendance update is in progress. Try again.', 409);
        }

        $existing = $this->firebase->get($attPath);
        $attStr = is_string($existing) ? $existing : str_repeat('V', $daysInMonth);
        $attStr = str_pad($attStr, $daysInMonth, 'V');
        $oldMark = $attStr[$day - 1];
        $attStr[$day - 1] = $mark;

        // ── Firestore-first (Phase 7b) ─────────────────────────────────
        // Daily staff doc is the canonical store. RTDB is the mirror.
        $staffName = '';
        $staffMeta = $this->firebase->get("Schools/{$school}/{$session}/Teachers/{$staffId}");
        if (is_array($staffMeta)) {
            $staffName = (string)($staffMeta['Name'] ?? $staffMeta['name'] ?? '');
        }
        $fsOk = $this->_syncStaffDailyToFirestore(
            $staffId, $mark, $day, $attKey, $staffName, $mark === 'T'
        );
        if (!$fsOk) {
            $this->_release_att_lock($attPath);
            return $this->json_error('Firestore write failed; staff attendance not saved. Please retry.');
        }
        // Monthly summary mirror — best-effort, don't fail the request.
        $this->_syncStaffSummaryToFirestore($staffId, $attKey, $attStr, $staffName);

        // ── RTDB mirror ────────────────────────────────────────────────
        $this->firebase->set($attPath, $attStr);
        $this->_release_att_lock($attPath);

        // Handle late time — set if T, clean up if changed FROM T
        $latePath = "Schools/{$school}/{$session}/Staff_Attendance/Late/{$attKey}/{$staffId}/{$day}";
        if ($mark === 'T' && $lateTime) {
            $lateTime = preg_replace('/[^0-9:]/', '', $lateTime);
            $this->firebase->set($latePath, ['time' => $lateTime]);
        } elseif ($mark !== 'T') {
            $this->firebase->delete($latePath);
        }

        $this->_log_attendance_change('MARK_STAFF_DAY', [
            'target' => $staffId, 'day' => $day, 'month' => $attKey,
            'old' => $oldMark, 'new' => $mark,
        ]);

        // Update RTDB summary cache (legacy — Firestore summary already written above).
        update_staff_att_summary($this->firebase, $school, $session, $staffId, $attKey, $monthNum, $year);

        return $this->json_success(['mark' => $mark, 'day' => $day]);
    }

    /**
     * Bulk-mark all staff for a day
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

        $school  = $this->school_name;
        $session = $this->session_year;
        $year    = $this->_resolve_year($month);
        $attKey  = "{$month} {$year}";
        $monthNum = $this->month_map[$month];
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);

        if ($day < 1 || $day > $daysInMonth) {
            return $this->json_error('Invalid day.');
        }

        $teacherKeys = $this->firebase->shallow_get("Schools/{$school}/{$session}/Teachers");
        if (!is_array($teacherKeys)) {
            return $this->json_error('No staff found.');
        }

        // Batch-read all staff attendance for this month in 1 read (instead of N)
        $allStaffAtt = $this->firebase->get("Schools/{$school}/{$session}/Staff_Attendance/{$attKey}");
        if (!is_array($allStaffAtt)) $allStaffAtt = [];

        // Pre-load staff names for the Firestore docs.
        $staffMeta = $this->firebase->get("Schools/{$school}/{$session}/Teachers");
        if (!is_array($staffMeta)) $staffMeta = [];

        $count = 0;
        foreach ($teacherKeys as $staffId => $v) {
            if (!is_string($staffId) || trim($staffId) === '') continue;
            $existing = isset($allStaffAtt[$staffId]) && is_string($allStaffAtt[$staffId])
                ? $allStaffAtt[$staffId] : '';
            $attStr = $existing ?: str_repeat('V', $daysInMonth);
            $attStr = str_pad($attStr, $daysInMonth, 'V');
            $attStr[$day - 1] = $mark;

            $name = isset($staffMeta[$staffId]) && is_array($staffMeta[$staffId])
                ? (string)($staffMeta[$staffId]['Name'] ?? $staffMeta[$staffId]['name'] ?? '')
                : '';

            // Phase 7b — best-effort Firestore mirror per staff.
            $this->_syncStaffDailyToFirestore($staffId, $mark, $day, $attKey, $name, $mark === 'T');
            $this->_syncStaffSummaryToFirestore($staffId, $attKey, $attStr, $name);

            $attPath = "Schools/{$school}/{$session}/Staff_Attendance/{$attKey}/{$staffId}";
            $this->firebase->set($attPath, $attStr);
            $count++;
        }

        $this->_log_attendance_change('BULK_MARK_STAFF', [
            'day' => $day, 'month' => $attKey, 'mark' => $mark, 'count' => $count,
        ]);

        return $this->json_success(['marked' => $count]);
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
        $school  = $this->school_name;
        $session = $this->session_year;

        $now       = new DateTime();
        $monthName = $now->format('F');        // "March"
        $day       = (int)$now->format('j');   // 23
        $year      = (int)$now->format('Y');
        $monthNum  = (int)$now->format('n');
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);
        $attKey    = "{$monthName} {$year}";

        // Get all teachers in this session
        $teacherKeys = $this->firebase->shallow_get("Schools/{$school}/{$session}/Teachers");
        if (!is_array($teacherKeys) || empty($teacherKeys)) {
            return $this->json_success(['marked' => 0, 'skipped' => 0, 'message' => 'No staff found in session.']);
        }

        // Batch-read all staff attendance for this month
        $allStaffAtt = $this->firebase->get("Schools/{$school}/{$session}/Staff_Attendance/{$attKey}");
        if (!is_array($allStaffAtt)) $allStaffAtt = [];

        // Pre-load staff metadata for names (Firestore docs need them)
        $staffMeta = $this->firebase->get("Schools/{$school}/{$session}/Teachers");
        if (!is_array($staffMeta)) $staffMeta = [];

        $marked  = 0;
        $skipped = 0;
        foreach ($teacherKeys as $staffId => $v) {
            if (!is_string($staffId) || trim($staffId) === '') continue;

            $existing = isset($allStaffAtt[$staffId]) && is_string($allStaffAtt[$staffId])
                ? $allStaffAtt[$staffId] : '';
            $attStr = str_pad($existing, $daysInMonth, 'V');

            // Only mark if today is vacant (V) — don't overwrite existing marks
            $currentMark = $attStr[$day - 1] ?? 'V';
            if ($currentMark === 'V') {
                $attStr[$day - 1] = 'P';

                $name = isset($staffMeta[$staffId]) && is_array($staffMeta[$staffId])
                    ? (string)($staffMeta[$staffId]['Name'] ?? $staffMeta[$staffId]['name'] ?? '')
                    : '';

                // Phase 7b — best-effort Firestore mirror per staff (bulk op).
                $this->_syncStaffDailyToFirestore($staffId, 'P', $day, $attKey, $name, false);
                $this->_syncStaffSummaryToFirestore($staffId, $attKey, $attStr, $name);

                $attPath = "Schools/{$school}/{$session}/Staff_Attendance/{$attKey}/{$staffId}";
                $this->firebase->set($attPath, $attStr);
                $marked++;
            } else {
                $skipped++;
            }
        }

        $this->_log_attendance_change('AUTOFILL_STAFF_TODAY', [
            'day' => $day, 'month' => $attKey, 'marked' => $marked, 'skipped' => $skipped,
        ]);

        return $this->json_success([
            'marked'  => $marked,
            'skipped' => $skipped,
            'date'    => $now->format('d M Y'),
            'message' => "{$marked} staff marked Present for today. {$skipped} already had attendance.",
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
        $path = "Schools/{$this->school_name}/Config/Attendance";
        $config = $this->firebase->get($path);

        $defaults = [
            'late_threshold_student' => '08:30',
            'late_threshold_staff'   => '09:00',
            'working_days'           => ['Mon','Tue','Wed','Thu','Fri','Sat'],
            'biometric_enabled'      => false,
            'rfid_enabled'           => false,
            'face_recognition_enabled' => false,
        ];

        if (is_array($config)) {
            $config = array_merge($defaults, $config);
        } else {
            $config = $defaults;
        }

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

        $path = "Schools/{$this->school_name}/Config/Attendance";
        $this->firebase->update($path, $data);

        return $this->json_success(['message' => 'Settings saved.']);
    }

    /**
     * Get holidays list
     */
    public function get_holidays()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_holidays');
        $path = "Schools/{$this->school_name}/Config/Attendance/holidays";
        $holidays = $this->firebase->get($path);

        return $this->json_success([
            'holidays' => is_array($holidays) ? $holidays : [],
        ]);
    }

    /**
     * Save holidays
     * POST: holidays (JSON object: {"YYYY-MM-DD": "Holiday Name", ...})
     */
    public function save_holidays()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_holidays');
        $holidays = $this->input->post('holidays');
        if (is_string($holidays)) {
            $holidays = json_decode($holidays, true);
        }
        if (!is_array($holidays)) {
            return $this->json_error('Invalid holidays data.');
        }

        // Validate date formats and sanitize names
        $clean = [];
        foreach ($holidays as $date => $name) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $clean[$date] = trim((string) $name);
            }
        }

        $path = "Schools/{$this->school_name}/Config/Attendance/holidays";
        $this->firebase->set($path, $clean);

        return $this->json_success(['saved' => count($clean)]);
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

        // Phase 7c — Firestore-first read.
        try {
            $docs = $this->fs->schoolList('attendanceDevices');
        } catch (\Exception $e) {
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
        } else {
            // RTDB fallback
            $path = "Schools/{$this->school_name}/Config/Devices";
            $devices = $this->firebase->get($path);
            if (is_array($devices)) {
                foreach ($devices as $id => $dev) {
                    if (!is_array($dev)) continue;
                    $list[] = [
                        'id'        => $id,
                        'name'      => $dev['name'] ?? '',
                        'type'      => $dev['type'] ?? 'unknown',
                        'location'  => $dev['location'] ?? '',
                        'status'    => $dev['status'] ?? 'inactive',
                        'last_ping' => $dev['last_ping'] ?? '',
                        'created_at' => $dev['created_at'] ?? '',
                    ];
                }
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

        $deviceData = [
            'name'        => $name,
            'type'        => $type,
            'location'    => $location,
            'status'      => 'active',
            'api_key_hash' => $keyHash,
            'created_at'  => date('c'),
            'last_ping'   => '',
        ];

        // Phase 7c — Firestore-first write (must succeed before RTDB mirror).
        $fsDoc = [
            'schoolId'    => $this->school_id,
            'deviceId'    => $deviceId,
            'name'        => $name,
            'type'        => $type,
            'location'    => $location,
            'status'      => 'active',
            'apiKeyHash'  => $keyHash,
            'createdAt'   => date('c'),
            'lastPing'    => '',
        ];
        try {
            $fsOk = (bool) $this->fs->set('attendanceDevices', $this->fs->docId($deviceId), $fsDoc, true);
        } catch (\Exception $e) {
            $fsOk = false;
        }
        if (!$fsOk) {
            return $this->json_error('Firestore write failed; device not registered. Please retry.');
        }

        // Phase 7c — Firestore key→device index for fast device auth lookup.
        try {
            $this->fs->set('attendanceDeviceKeys', $keyHash, [
                'keyHash'    => $keyHash,
                'deviceId'   => $deviceId,
                'schoolId'   => $this->school_id,
                'schoolName' => $this->school_name,
                'createdAt'  => date('c'),
            ], true);
        } catch (\Exception $e) { /* best-effort */ }

        // RTDB mirror (best-effort)
        $this->firebase->set("Schools/{$this->school_name}/Config/Devices/{$deviceId}", $deviceData);

        // Save API key lookup — dual-write to both school-scoped and System-level index
        $keyData = [
            'device_id'   => $deviceId,
            'school_name' => $this->school_name,
        ];
        $this->firebase->set("Schools/{$this->school_name}/Config/API_Keys/{$keyHash}", $keyData);
        $this->firebase->set("System/API_Keys/{$keyHash}", $keyData);

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

        // Phase 7c — Firestore-first update.
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

        $path = "Schools/{$this->school_name}/Config/Devices/{$deviceId}";
        $this->firebase->update($path, $updates);

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

        // Get key hash to delete from both API_Keys lookups (try Firestore first)
        $devPath = "Schools/{$this->school_name}/Config/Devices/{$deviceId}";
        $hash = null;
        try {
            $fsDev = $this->fs->get('attendanceDevices', $this->fs->docId($deviceId));
            if (is_array($fsDev)) {
                $hash = $fsDev['apiKeyHash'] ?? $fsDev['api_key_hash'] ?? null;
            }
        } catch (\Exception $e) { $fsDev = null; }
        if (!$hash) {
            $device = $this->firebase->get($devPath);
            if (is_array($device) && !empty($device['api_key_hash'])) {
                $hash = $device['api_key_hash'];
            }
        }
        if ($hash) {
            try { $this->fs->remove('attendanceDeviceKeys', $hash); } catch (\Exception $e) {}
            $this->firebase->delete("Schools/{$this->school_name}/Config/API_Keys/{$hash}");
            $this->firebase->delete("System/API_Keys/{$hash}");
        }

        // Phase 7c — Firestore-first delete.
        try { $this->fs->remove('attendanceDevices', $this->fs->docId($deviceId)); } catch (\Exception $e) {}
        $this->firebase->delete($devPath);

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

        // Phase 7c — Firestore-first lookup.
        $device = null;
        try {
            $fsDev = $this->fs->get('attendanceDevices', $this->fs->docId($deviceId));
            if (is_array($fsDev)) {
                $device = [
                    'api_key_hash' => $fsDev['apiKeyHash'] ?? $fsDev['api_key_hash'] ?? '',
                ];
            }
        } catch (\Exception $e) { /* fall back */ }

        $devPath = "Schools/{$this->school_name}/Config/Devices/{$deviceId}";
        if (!$device) {
            $device = $this->firebase->get($devPath);
        }
        if (!is_array($device)) {
            return $this->json_error('Device not found.');
        }

        // Delete old key lookup from both indexes
        if (!empty($device['api_key_hash'])) {
            $oldHash = $device['api_key_hash'];
            try { $this->fs->remove('attendanceDeviceKeys', $oldHash); } catch (\Exception $e) {}
            $this->firebase->delete("Schools/{$this->school_name}/Config/API_Keys/{$oldHash}");
            $this->firebase->delete("System/API_Keys/{$oldHash}");
        }

        // Generate new key
        $rawKey  = bin2hex(random_bytes(32));
        $keyHash = hash('sha256', $rawKey);

        $keyData = [
            'device_id'   => $deviceId,
            'school_name' => $this->school_name,
        ];

        // Firestore-first apiKeyHash update.
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

        // Phase 7c — refresh Firestore key→device index.
        try {
            $this->fs->set('attendanceDeviceKeys', $keyHash, [
                'keyHash'    => $keyHash,
                'deviceId'   => $deviceId,
                'schoolId'   => $this->school_id,
                'schoolName' => $this->school_name,
                'createdAt'  => date('c'),
            ], true);
        } catch (\Exception $e) { /* best-effort */ }

        $this->firebase->update($devPath, ['api_key_hash' => $keyHash]);
        $this->firebase->set("Schools/{$this->school_name}/Config/API_Keys/{$keyHash}", $keyData);
        $this->firebase->set("System/API_Keys/{$keyHash}", $keyData);

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
        $__metric_start = microtime(true);

        $auth = $this->_validate_api_key();
        if (!$auth) {
            $this->_log_metric('api_punch', $__metric_start, 'auth_fail');
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
        // Resolve parent_db_key for this school (legacy schools use school_code, SCH_ schools use school_id)
        $schoolMeta = $this->firebase->get("System/Schools/{$schoolName_pre}");
        $parentDbKey = $schoolName_pre; // default
        if (is_array($schoolMeta)) {
            if (!empty($schoolMeta['school_code']) && strpos($schoolName_pre, 'SCH_') !== 0) {
                $parentDbKey = $schoolMeta['school_code'];
            }
        }
        if ($personType === 'student') {
            $personCheck = $this->firebase->get("Users/Parents/{$parentDbKey}/{$personId}/Name");
            if (!$personCheck) {
                return $this->json_error('Person ID does not belong to this school.', 403);
            }
        } elseif ($personType === 'staff') {
            $staffCheck = $this->firebase->get("Users/Teachers/{$schoolName_pre}/{$personId}/Name");
            if (!$staffCheck) {
                return $this->json_error('Staff ID does not belong to this school.', 403);
            }
        }

        // Reject low-confidence face recognition punches
        $deviceInfo_pre = $this->firebase->get("Schools/{$auth['school_name']}/Config/Devices/{$auth['device_id']}");
        $devType = is_array($deviceInfo_pre) ? ($deviceInfo_pre['type'] ?? '') : '';
        if ($devType === 'face_recognition' && $confidence < self::FACE_CONFIDENCE_THRESHOLD) {
            return $this->json_error('Confidence too low for face recognition. Score: ' . $confidence, 422);
        }

        $schoolName = $auth['school_name'];
        $deviceId   = $auth['device_id'];

        // Determine session year from school config
        $activeSession = $this->firebase->get("Schools/{$schoolName}/Config/ActiveSession");
        if (!$activeSession) {
            $sessions = $this->firebase->get("System/Schools/{$schoolName}/Sessions");
            $activeSession = is_array($sessions) ? end($sessions) : date('Y') . '-' . (date('Y') + 1);
        }
        $session = is_string($activeSession) ? $activeSession : (string) $activeSession;

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
            $eventPath = "Schools/{$schoolName}/{$session}/Attendance/ProcessedEvents/{$eventId}";
            $existing = $this->firebase->get($eventPath);
            if (is_array($existing)) {
                // Check TTL — treat expired entries as non-existent
                $expiresAt = $existing['expires_at'] ?? 0;
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
                $this->firebase->delete($eventPath);
            }
        }

        // Dedup check — reject if same person punched within 5 minutes (fallback for devices without event_id)
        $existingPunches = $this->firebase->get("Schools/{$schoolName}/{$session}/Attendance/Punch_Log/{$dateStr}");
        if (is_array($existingPunches)) {
            foreach ($existingPunches as $pId => $pData) {
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

        // Phase 7d — Firestore punch log mirror (best-effort).
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

        $this->firebase->push("Schools/{$schoolName}/{$session}/Attendance/Punch_Log/{$dateStr}", $punchData);

        // Update last_ping on device — Firestore + RTDB
        try {
            $this->fs->set('attendanceDevices', $this->fs->docId($deviceId), [
                'schoolId' => $auth['school_id'] ?? $this->school_id,
                'deviceId' => $deviceId,
                'lastPing' => date('c'),
            ], true);
        } catch (\Exception $e) { /* best-effort */ }
        $this->firebase->update("Schools/{$schoolName}/Config/Devices/{$deviceId}", [
            'last_ping' => date('c'),
        ]);

        // Determine mark (P or T based on late threshold)
        $config = $this->firebase->get("Schools/{$schoolName}/Config/Attendance");
        $threshold = '08:30';
        if (is_array($config)) {
            $threshold = $personType === 'staff'
                ? ($config['late_threshold_staff'] ?? '09:00')
                : ($config['late_threshold_student'] ?? '08:30');
        }

        $mark = 'P';
        if ($direction === 'in' && $timeStr > $threshold) {
            $mark = 'T'; // Late
        }

        // Write attendance (only for 'in' direction)
        if ($direction === 'in') {
            if ($personType === 'student' && $class && $section) {
                $secRoot = $this->_resolve_section_root($class, $section);
                $attPath = "{$secRoot}/Students/{$personId}/Attendance/{$attKey}";

                if ($this->_acquire_att_lock($attPath)) {
                    $existing = $this->firebase->get($attPath);
                    $attStr = is_string($existing) ? $existing : str_repeat('V', $daysInMonth);
                    $attStr = str_pad($attStr, $daysInMonth, 'V');
                    $oldDevMark = $attStr[$dayOfMonth - 1];
                    if ($oldDevMark === 'V') {
                        $attStr[$dayOfMonth - 1] = $mark;

                        // ── Firestore FIRST (canonical) ──
                        $this->_syncDailyToFirestore($personId, $mark, $class, $section,
                            $dayOfMonth, $attKey, '', $mark === 'T');

                        // ── RTDB mirror (best-effort, stays until Phase 8) ──
                        $this->firebase->set($attPath, $attStr);
                        $this->_update_summary_incremental($class, $section, $attKey, $personId, $oldDevMark, $mark);
                    }
                    $this->_release_att_lock($attPath);
                }

                if ($mark === 'T') {
                    // RTDB mirror — Firestore lateTimes map is filled by
                    // save_student_attendance / mark_student_day; punch
                    // path keeps the legacy Late record on RTDB only for
                    // now (TODO: nested merge into attendanceSummary).
                    $this->firebase->set(
                        "Schools/{$schoolName}/{$session}/Attendance/Late/{$attKey}/{$personId}/{$dayOfMonth}",
                        ['time' => $timeStr, 'threshold' => $threshold]
                    );
                }
            } elseif ($personType === 'staff') {
                $attPath = "Schools/{$schoolName}/{$session}/Staff_Attendance/{$attKey}/{$personId}";

                if ($this->_acquire_att_lock($attPath)) {
                    $existing = $this->firebase->get($attPath);
                    $attStr = is_string($existing) ? $existing : str_repeat('V', $daysInMonth);
                    $attStr = str_pad($attStr, $daysInMonth, 'V');
                    if ($attStr[$dayOfMonth - 1] === 'V') {
                        $attStr[$dayOfMonth - 1] = $mark;

                        // ── Firestore FIRST (canonical) ──
                        $this->_syncStaffDailyToFirestore($personId, $mark, $dayOfMonth, $attKey, '', $mark === 'T');
                        $this->_syncStaffSummaryToFirestore($personId, $attKey, $attStr, '');

                        // ── RTDB mirror (best-effort, stays until Phase 8) ──
                        $this->firebase->set($attPath, $attStr);
                    }
                    $this->_release_att_lock($attPath);
                }

                if ($mark === 'T') {
                    $this->firebase->set(
                        "Schools/{$schoolName}/{$session}/Staff_Attendance/Late/{$attKey}/{$personId}/{$dayOfMonth}",
                        ['time' => $timeStr, 'threshold' => $threshold]
                    );
                }
            }
        }

        // Store event_id for idempotency (if provided), with TTL for auto-expiry
        if ($eventId) {
            $eventPath = "Schools/{$schoolName}/{$session}/Attendance/ProcessedEvents/{$eventId}";
            $this->firebase->set($eventPath, [
                'mark'       => $mark,
                'time'       => $timeStr,
                'person_id'  => $personId,
                'direction'  => $direction,
                'processed'  => date('c'),
                'expires_at' => time() + self::IDEMPOTENCY_TTL,
            ]);
        }

        // Audit log for device punches — date-partitioned path
        $punchLog = [
            'user'      => 'device:' . $deviceId,
            'role'      => 'device',
            'action'    => 'DEVICE_PUNCH',
            'school'    => $schoolName,
            'target'    => $personId,
            'type'      => $personType,
            'mark'      => $mark,
            'direction' => $direction,
            'time'      => $timeStr,
            'date'      => $dateStr,
            'timestamp' => date('c'),
            'epoch'     => time(),
            'ip'        => $this->input->ip_address(),
        ];
        $yearMonth = date('Y-m', $ts);
        $logKey    = date('d_His', $ts) . '_' . mt_rand(1000, 9999);
        $this->firebase->set("System/Logs/Attendance/{$schoolName}/{$yearMonth}/{$logKey}", $punchLog);

        $this->_log_metric('api_punch', $__metric_start, 'success', $schoolName);

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

        // ── READ: Firestore FIRST — pre-fetch every student summary for the month ──
        // Builds a flat studentId → dayWise map. The per-class loop below
        // prefers this map and only falls back to the RTDB roster's
        // Attendance subkey when the map has no entry for a student.
        $fsByStudent = [];
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
                if ($sid !== '' && is_string($dw) && $dw !== '') {
                    $fsByStudent[$sid] = $dw;
                }
            }
        } catch (\Exception $e) {
            $fsByStudent = []; // fall through to RTDB-only path
        }

        $classList = $this->_build_class_list();
        $analytics = [];

        foreach ($classList as $cls) {
            $cName = $cls['class_name'];
            $sec   = $cls['section'];

            if ($classFilter && $cName !== $classFilter) continue;

            // R5 — roster from Firestore via Roster_helper. The bulk
            // RTDB read of `{secRoot}/Students` is retained ONLY for
            // the per-student attendance fallback below
            // (`$allStudents[$studentId]['Attendance'][$attKey]`); it
            // is no longer the roster source.
            $secRoot = $this->_resolve_section_root($cName, $sec);
            $list    = $this->_get_section_students($cName, $sec);
            if (empty($list)) continue;
            $allStudents = $this->firebase->get("{$secRoot}/Students");
            if (!is_array($allStudents)) $allStudents = [];

            $classTotals = ['P' => 0, 'A' => 0, 'L' => 0, 'H' => 0, 'T' => 0, 'V' => 0, 'students' => 0];

            foreach ($list as $studentId => $name) {
                if (!is_string($studentId) || trim($studentId) === '') continue;
                // Firestore-first → RTDB fallback for the dayWise string
                $attStr = $fsByStudent[$studentId] ?? '';
                if ($attStr === ''
                    && isset($allStudents[$studentId]['Attendance'][$attKey])
                    && is_string($allStudents[$studentId]['Attendance'][$attKey])
                ) {
                    $attStr = $allStudents[$studentId]['Attendance'][$attKey];
                }
                if (!$attStr) continue;

                $stats = $this->_compute_month_stats($attStr);
                foreach (['P', 'A', 'L', 'H', 'T', 'V'] as $ch) {
                    $classTotals[$ch] += $stats[$ch];
                }
                $classTotals['students']++;
            }

            $working = $classTotals['P'] + $classTotals['A'] + $classTotals['L'] + $classTotals['T'];
            $present_pct = $working > 0
                ? round(($classTotals['P'] + $classTotals['T']) / $working * 100, 1)
                : 0;

            $analytics[] = [
                'class'       => $cName,
                'section'     => $sec,
                'label'       => str_replace('Class ', '', $cName) . ' ' . $sec,
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

            // ── RTDB fallback: cached summary node ──
            $summaryPath = "Schools/{$school}/{$session}/Attendance/Summary/Students/{$attKey}";
            $summary = $this->firebase->get($summaryPath);

            if (is_array($summary) && !empty($summary)) {
                $totalP = 0; $totalWork = 0;
                foreach ($filteredSections as $csKey) {
                    if (!isset($summary[$csKey]) || !is_array($summary[$csKey])) continue;
                    $sec = $summary[$csKey];
                    if (isset($sec['students']) && is_array($sec['students'])) {
                        foreach ($sec['students'] as $sData) {
                            if (!is_array($sData)) continue;
                            $working = ($sData['P'] ?? 0) + ($sData['A'] ?? 0) + ($sData['L'] ?? 0) + ($sData['T'] ?? 0);
                            $totalP += ($sData['P'] ?? 0) + ($sData['T'] ?? 0);
                            $totalWork += $working;
                        }
                    }
                }
                $trend[] = [
                    'month'       => $month,
                    'year'        => $year,
                    'present_pct' => $totalWork > 0 ? round($totalP / $totalWork * 100, 1) : 0,
                    'cached'      => true,
                ];
                continue;
            }

            // Fallback: compute from raw data (lazy pre-fetch section data once)
            if (!$needFullCompute) {
                $needFullCompute = true;
                $sectionData = [];      // RTDB Students node (attendance fallback)
                $sectionRosters = [];   // R5 — roster from Firestore
                foreach ($classList as $cls) {
                    if ($classFilter && $cls['class_name'] !== $classFilter) continue;
                    if ($sectionFilter && $cls['section'] !== $sectionFilter) continue;
                    $key = $cls['class_name'] . '|' . $cls['section'];
                    $secRoot = $this->_resolve_section_root($cls['class_name'], $cls['section']);
                    $sectionData[$key]    = $this->firebase->get("{$secRoot}/Students");
                    $sectionRosters[$key] = $this->_get_section_students($cls['class_name'], $cls['section']);
                }
            }

            $totalP = 0; $totalWork = 0;
            foreach ($sectionData as $secKey => $allStudents) {
                if (!is_array($allStudents)) $allStudents = [];
                // R5 — roster from Firestore; RTDB Students node is now
                // an attendance-fallback source only.
                $secList = $sectionRosters[$secKey] ?? [];
                if (empty($secList)) continue;
                foreach ($secList as $studentId => $name) {
                    if (!is_string($studentId)) continue;
                    $attStr = isset($allStudents[$studentId]['Attendance'][$attKey])
                        && is_string($allStudents[$studentId]['Attendance'][$attKey])
                        ? $allStudents[$studentId]['Attendance'][$attKey] : '';
                    if (!$attStr) continue;
                    $stats = $this->_compute_month_stats($attStr);
                    $working = $stats['P'] + $stats['A'] + $stats['L'] + $stats['T'];
                    $totalP += $stats['P'] + $stats['T'];
                    $totalWork += $working;
                }
            }

            $trend[] = [
                'month'       => $month,
                'year'        => $year,
                'present_pct' => $totalWork > 0 ? round($totalP / $totalWork * 100, 1) : 0,
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
            $profile = $this->firebase->get("Users/Parents/{$this->parent_db_key}/{$personId}");
            if (is_array($profile)) {
                $personName    = $profile['Name'] ?? $profile['name'] ?? '';
                $personClass   = $profile['Class'] ?? '';
                $personSection = $profile['Section'] ?? '';
            }
        } else {
            $staffData = $this->firebase->get("Users/Teachers/{$this->school_id}/{$personId}");
            if (is_array($staffData)) {
                $personName = $staffData['Name'] ?? $staffData['Profile']['name'] ?? '';
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
            $fsByMonth = []; // fall through to RTDB-only path
        }

        foreach ($this->academic_months as $month) {
            $year   = $this->_resolve_year($month);
            $attKey = "{$month} {$year}";
            $monthNum = $this->month_map[$month] ?? 0;
            $monthKey = $monthNum ? sprintf('%04d-%02d', $year, $monthNum) : '';

            // ── Firestore FIRST ──
            $attStr = $monthKey !== '' ? ($fsByMonth[$monthKey] ?? '') : '';

            // ── RTDB fallback ──
            if ($attStr === '') {
                if ($personType === 'student') {
                    $secRoot = $this->_resolve_section_root($class, $section);
                    $attPath = "{$secRoot}/Students/{$personId}/Attendance/{$attKey}";
                } else {
                    $attPath = "Schools/{$school}/{$session}/Staff_Attendance/{$attKey}/{$personId}";
                }
                $rtdbVal = $this->firebase->get($attPath);
                if (is_string($rtdbVal)) $attStr = $rtdbVal;
            }

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

    /**
     * Compute and cache summary for a month
     * POST: month
     */
    public function compute_summary()
    {
        $this->_require_role(self::VIEW_ROLES, 'compute_summary');
        $month = trim((string) $this->input->post('month'));
        if (!$month || !isset($this->month_map[$month])) {
            return $this->json_error('Invalid month.');
        }

        $school  = $this->school_name;
        $session = $this->session_year;
        $year    = $this->_resolve_year($month);
        $attKey  = "{$month} {$year}";

        $classList = $this->_build_class_list();
        $summaryPath = "Schools/{$school}/{$session}/Attendance/Summary/Students/{$attKey}";

        foreach ($classList as $cls) {
            $cName = $cls['class_name'];
            $sec   = $cls['section'];
            $csKey = str_replace(' ', '_', $cName) . '_' . $sec;

            // R5 — roster from Firestore via Roster_helper.
            // Bulk RTDB Students read kept for the per-student
            // `$allStudents[$studentId]['Attendance']` fallback below.
            $secRoot = $this->_resolve_section_root($cName, $sec);
            $list    = $this->_get_section_students($cName, $sec);
            if (empty($list)) continue;
            $allStudents = $this->firebase->get("{$secRoot}/Students");
            if (!is_array($allStudents)) $allStudents = [];

            $studentStats = [];
            $totalStudents = 0;
            $avgPct = 0;

            foreach ($list as $studentId => $name) {
                if (!is_string($studentId)) continue;
                $attStr = isset($allStudents[$studentId]['Attendance'][$attKey])
                    && is_string($allStudents[$studentId]['Attendance'][$attKey])
                    ? $allStudents[$studentId]['Attendance'][$attKey] : '';
                if (!$attStr) continue;

                $stats = $this->_compute_month_stats($attStr);
                $working = $stats['P'] + $stats['A'] + $stats['L'] + $stats['T'];
                $pct = $working > 0 ? round(($stats['P'] + $stats['T']) / $working * 100, 1) : 0;

                $studentStats[$studentId] = array_merge($stats, ['pct' => $pct]);
                $totalStudents++;
                $avgPct += $pct;
            }

            $this->firebase->set("{$summaryPath}/{$csKey}", [
                'total_students'  => $totalStudents,
                'avg_present_pct' => $totalStudents > 0 ? round($avgPct / $totalStudents, 1) : 0,
                'students'        => $studentStats,
            ]);
        }

        return $this->json_success(['message' => 'Summary computed.']);
    }

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
        } catch (\Exception $e) { $fsPunches = []; }

        if (!empty($fsPunches) && is_array($fsPunches)) {
            usort($fsPunches, function ($a, $b) {
                return strcmp((string)($a['punch_time'] ?? ''), (string)($b['punch_time'] ?? ''));
            });
            $total = count($fsPunches);
            $totalPages = (int) ceil($total / $limit);
            $offset = ($page - 1) * $limit;
            $slice = array_slice($fsPunches, $offset, $limit);
            $punches = [];
            foreach ($slice as $p) {
                if (!is_array($p)) continue;
                $p['id'] = $p['punchId'] ?? '';
                $punches[] = $p;
            }
            return $this->json_success([
                'punches'    => $punches,
                'date'       => $date,
                'pagination' => [
                    'page' => $page, 'limit' => $limit,
                    'total' => $total, 'total_pages' => $totalPages,
                ],
            ]);
        }

        // RTDB fallback — shallow get for total count and key list
        $allKeys = $this->firebase->shallow_get($basePath);
        if (!is_array($allKeys)) {
            return $this->json_success([
                'punches'    => [],
                'date'       => $date,
                'pagination' => ['page' => $page, 'limit' => $limit, 'total' => 0, 'total_pages' => 0],
            ]);
        }

        $keyList = array_keys($allKeys);
        sort($keyList); // Firebase push IDs are chronologically sortable
        $total = count($keyList);
        $totalPages = (int) ceil($total / $limit);
        $offset = ($page - 1) * $limit;
        $pageKeys = array_slice($keyList, $offset, $limit);

        // Fetch only the records for this page
        $punches = [];
        foreach ($pageKeys as $key) {
            $punch = $this->firebase->get("{$basePath}/{$key}");
            if (is_array($punch)) {
                $punch['id'] = $key;
                $punches[] = $punch;
            }
        }

        return $this->json_success([
            'punches'    => $punches,
            'date'       => $date,
            'pagination' => [
                'page'        => $page,
                'limit'       => $limit,
                'total'       => $total,
                'total_pages' => $totalPages,
            ],
        ]);
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

        // R5 — roster from Firestore via Roster_helper (canonical).
        // Bulk RTDB Students read kept solely as the per-student
        // attendance fallback below
        // (`$allStudents[$id]['Attendance'][$attKey]`).
        $secRoot = $this->_resolve_section_root($class, $section);
        $list    = $this->_get_section_students($class, $section);
        $allStudents = $this->firebase->get("{$secRoot}/Students");
        if (!is_array($allStudents)) $allStudents = [];

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

                // Firestore canonical
                if (isset($fsDayWise[$id]) && strlen($fsDayWise[$id]) >= $today) {
                    $todayMark = $fsDayWise[$id][$today - 1];
                }
                // RTDB fallback per student
                if ($todayMark === 'V'
                    && isset($allStudents[$id]['Attendance'][$attKey])
                    && is_string($allStudents[$id]['Attendance'][$attKey])
                    && strlen($allStudents[$id]['Attendance'][$attKey]) >= $today) {
                    $todayMark = $allStudents[$id]['Attendance'][$attKey][$today - 1];
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

        $saved = 0;
        foreach ($bulkMarks as $studentId => $info) {
            $mark = $info['mark'];

            $secRoot = $this->_resolve_section_root($class, $section);
            $attPath = "{$secRoot}/Students/{$studentId}/Attendance/{$attKey}";
            $existing = $this->firebase->get($attPath);
            $attStr = is_string($existing) ? $existing : str_repeat('V', $daysInMonth);
            $attStr = str_pad($attStr, $daysInMonth, 'V');
            $attStr[$today - 1] = $mark;
            $this->firebase->set($attPath, $attStr);
            $saved++;

            // Late time
            if ($mark === 'T' && is_array($lateTimes) && !empty($lateTimes[$studentId])) {
                $lateTime = preg_replace('/[^0-9:]/', '', (string) $lateTimes[$studentId]);
                if ($lateTime) {
                    $latePath = "Schools/{$school}/{$session}/Attendance/Late/{$attKey}/{$studentId}/{$today}";
                    $this->firebase->set($latePath, ['time' => $lateTime]);
                }
            }
        }

        $this->_log_attendance_change('MOBILE_MARK_STUDENT', [
            'class' => $class, 'section' => $section, 'date' => date('Y-m-d'), 'count' => $saved,
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
            return $this->json_error('This QR is for a different school.', 403);
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

            $docs = $this->fs->schoolWhere('leaveApplications', $conditions);
        } catch (\Exception $e) {
            return $this->json_error('Failed to fetch leave applications: ' . $e->getMessage());
        }

        $leaves = [];
        foreach ($docs as $entry) {
            $d = $entry['data'] ?? $entry;
            $d = is_array($entry) ? ($entry['data'] ?? $entry) : null;
            $id = is_array($entry) ? ($d['id'] ?? '') : '';
            if (!is_array($d)) continue;

            // Filter by class/section if provided
            if ($classFilter && ($d['className'] ?? '') !== Firestore_service::classKey($classFilter)) continue;
            if ($sectionFilter && ($d['section'] ?? '') !== Firestore_service::sectionKey($sectionFilter)) continue;

            // Teachers: only show leaves for their assigned classes
            if (!$this->_is_admin_role()) {
                $cls = $d['className'] ?? '';
                $sec = str_replace('Section ', '', $d['section'] ?? '');
                if (!$this->_teacher_can_access($cls, "Section {$sec}")) continue;
            }

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
        if (($leave['status'] ?? '') !== 'pending') return $this->json_error('Leave is not in pending status.');

        $studentId = $leave['applicantId'] ?? '';
        $startDate = $leave['startDate'] ?? '';
        $endDate   = $leave['endDate'] ?? '';
        $className = $leave['className'] ?? '';
        $section   = $leave['section'] ?? '';

        if ($studentId === '' || $startDate === '' || $endDate === '') {
            return $this->json_error('Invalid leave application data.');
        }

        // Teachers can only approve for their assigned classes
        if (!$this->_is_admin_role()) {
            $sec = str_replace('Section ', '', $section);
            if (!$this->_teacher_can_access($className, "Section {$sec}")) {
                return $this->json_error('You are not assigned to this class/section.', 403);
            }
        }

        $approverName = $this->admin_name ?? $this->session->userdata('user_id') ?? 'system';

        // 1. Update leave status in Firestore
        try {
            $this->fs->set('leaveApplications', $leaveId, [
                'status'            => 'approved',
                'approvedBy'        => $approverName,
                'approvedAt'        => date('c'),
                'remarks'           => $remarks,
                'attendanceStamped' => true,  // admin stamps immediately below
            ], true);
        } catch (\Exception $e) {
            return $this->json_error('Failed to update leave status: ' . $e->getMessage());
        }

        // 2. Update attendance dayWise — mark "L" for each day in the range
        $daysUpdated = $this->_stamp_leave_on_attendance($studentId, $className, $section, $startDate, $endDate);

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
        if (($leave['status'] ?? '') !== 'pending') return $this->json_error('Leave is not in pending status.');

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
     * @return int Number of days updated
     */
    private function _stamp_leave_on_attendance(
        string $studentId, string $className, string $section,
        string $startDate, string $endDate
    ): int {
        $start = new \DateTime($startDate);
        $end   = new \DateTime($endDate);
        if ($start > $end) return 0;

        $updated = 0;
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

        // For each month, read → modify → write the dayWise string
        foreach ($monthDays as $monthKey => $info) {
            $docId = $this->fs->docId2($studentId, $monthKey);
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $info['monthNum'], $info['year']);

            // Read existing summary
            $doc = null;
            try { $doc = $this->fs->get('attendanceSummary', $docId); } catch (\Exception $e) {}

            $dayWise = ($doc && isset($doc['dayWise']) && is_string($doc['dayWise']))
                ? str_pad($doc['dayWise'], $daysInMonth, 'V')
                : str_repeat('V', $daysInMonth);

            // Stamp "L" for each leave day (skip holidays — H stays as H)
            $changed = false;
            foreach ($info['days'] as $day) {
                if ($day < 1 || $day > $daysInMonth) continue;
                $existing = $dayWise[$day - 1];
                if ($existing === 'H') continue; // don't overwrite holidays
                $dayWise[$day - 1] = 'L';
                $changed = true;
                $updated++;
            }

            if (!$changed) continue;

            // Recompute counts
            $monthName = date('F', mktime(0, 0, 0, $info['monthNum'], 1, $info['year']));
            $attKey = "{$monthName} {$info['year']}";

            // Use the helper to write (handles counts + percentage + Firestore + RTDB mirror)
            $this->_syncStudentSummaryToFirestore(
                $studentId, $className, $section,
                $info['monthNum'], $info['year'], $dayWise,
                $doc['studentName'] ?? ''
            );

            // RTDB mirror
            try {
                $sectionRoot = $this->_resolve_section_root($className, $section);
                $attPath = "{$sectionRoot}/Students/{$studentId}/Attendance/{$attKey}";
                $this->firebase->set($attPath, $dayWise);
            } catch (\Exception $e) {}
        }

        return $updated;
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
            $config = $this->firebase->get("Schools/{$this->school_name}/Config/AcademicYear/start_month");
            $this->_academic_start_month = ($config && (int) $config >= 1 && (int) $config <= 12)
                ? (int) $config : 4;
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
    private function _get_holidays_for_month(string $monthName, int $year): array
    {
        $config = $this->firebase->get("Schools/{$this->school_name}/Config/Attendance/holidays");
        if (!is_array($config)) return [];

        $monthNum = $this->month_map[$monthName] ?? 0;
        $holidays = [];

        foreach ($config as $date => $name) {
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) continue;
            if ((int) $m[1] === $year && (int) $m[2] === $monthNum) {
                $holidays[(int) $m[3]] = is_string($name) ? $name : '';
            }
        }

        return $holidays;
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

        // ── C-03 FIX: Rate limit failed API key attempts — max 20 per IP per 15 min ──
        $clientIp = $this->input->ip_address();
        $ipKey    = preg_replace('/[^a-zA-Z0-9]/', '_', $clientIp);
        $ratePath = "System/RateLimits/api_key/{$ipKey}";
        $rateData = $this->firebase->get($ratePath);
        $windowStart = time() - self::RATE_LIMIT_WINDOW;
        if (is_array($rateData)) {
            $recentCount = 0;
            foreach ($rateData as $ts => $v) {
                if ((int) $ts >= $windowStart) $recentCount++;
                else $this->firebase->delete($ratePath, (string) $ts);
            }
            if ($recentCount >= self::MAX_FAILED_ATTEMPTS) {
                log_message('error', "API key rate limit exceeded for IP: {$clientIp}");
                return false;
            }
        }

        $keyHash = hash('sha256', $rawKey);

        // Check cache first (Redis or file)
        $cacheKey = "api_key_{$keyHash}";
        $cached = $this->_cache_get($cacheKey);
        if (is_array($cached) && !empty($cached['school_name'])) {
            return $cached;
        }

        // Phase 7c — Firestore key→device index (canonical).
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

        // RTDB fallback — System-level key index
        $lookup = $this->firebase->get("System/API_Keys/{$keyHash}");
        if (is_array($lookup) && !empty($lookup['school_name'])) {
            $this->_cache_set($cacheKey, $lookup, self::API_KEY_CACHE_TTL);
            return $lookup;
        }

        // Fallback: if the school name is passed in the request header — sanitize to prevent path injection
        $schoolHint = trim($_SERVER['HTTP_X_SCHOOL'] ?? '');
        if ($schoolHint && preg_match('/^[A-Za-z0-9 _\-]+$/', $schoolHint)) {
            $lookup = $this->firebase->get("Schools/{$schoolHint}/Config/API_Keys/{$keyHash}");
            if (is_array($lookup)) {
                $lookup['school_name'] = $schoolHint;
                $this->_cache_set($cacheKey, $lookup, self::API_KEY_CACHE_TTL);
                return $lookup;
            }
        }

        // Log failed attempt for rate limiting
        $this->firebase->set("{$ratePath}/" . time() . '_' . mt_rand(1000, 9999), 1);

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
        try {
            $docs = $this->fs->schoolWhere('leaveApplications', [
                ['status',            '==', 'approved'],
                ['attendanceStamped', '==', false],
                ['applicantType',     '==', 'student'],
            ]);
        } catch (\Exception $e) { return; }

        foreach ($docs as $entry) {
            $d = $entry['data'] ?? $entry;
            $d = is_array($entry) ? ($entry['data'] ?? $entry) : null;
            $docId = is_array($entry) ? ($d['id'] ?? '') : '';
            if (!is_array($d) || $docId === '') continue;

            $studentId = $d['applicantId'] ?? '';
            $startDate = $d['startDate']   ?? '';
            $endDate   = $d['endDate']     ?? '';
            $className = $d['className']   ?? '';
            $section   = $d['section']     ?? '';

            if ($studentId === '' || $startDate === '' || $endDate === '') continue;

            // Stamp "L" on attendance dayWise
            $this->_stamp_leave_on_attendance($studentId, $className, $section, $startDate, $endDate);

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

    /**
     * Log an attendance change to the audit trail.
     *
     * Uses fire-and-forget: writes to a local JSONL queue file (non-blocking),
     * which is flushed to Firebase by the cleanup() cron endpoint.
     * Falls back to direct Firebase write if queue write fails.
     *
     * Path: System/Logs/Attendance/{schoolId}/{YYYY-MM}/{logId}
     */
    // ====================================================================
    //  DATE GOVERNANCE (future block + backdated approval)
    // ====================================================================

    /** @var array|null Cached attendance rules config */
    private $_attRulesCache = null;

    /**
     * Load attendance governance config.
     */
    private function _att_rules(): array
    {
        if ($this->_attRulesCache !== null) return $this->_attRulesCache;
        $rules = $this->firebase->get("Schools/{$this->school_name}/Config/AttendanceRules");
        $this->_attRulesCache = is_array($rules) ? $rules : [];
        return $this->_attRulesCache;
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

    /**
     * Check for an existing pending request with the same signature.
     * Returns request ID if duplicate found, empty string otherwise.
     */
    private function _find_duplicate_pending(string $type, array $payload): string
    {
        $path = "Schools/{$this->school_name}/{$this->session_year}/Attendance/PendingApproval";
        $all  = $this->firebase->get($path);
        if (!is_array($all)) return '';

        $targetId = $payload['target_id'] ?? '';
        $month    = $payload['month'] ?? '';
        $day      = $payload['day'] ?? null;

        foreach ($all as $id => $req) {
            if (!is_array($req)) continue;
            if (($req['status'] ?? '') !== 'pending') continue;
            // Expired requests don't count as duplicates
            if (!empty($req['expires_at']) && strtotime($req['expires_at']) < time()) continue;

            if (($req['type'] ?? '') === $type
                && ($req['target_id'] ?? '') === $targetId
                && ($req['month'] ?? '') === $month
                && ($req['day'] ?? null) == $day
            ) {
                return (string)$id;
            }
        }
        return '';
    }

    /**
     * Create a pending approval request for backdated attendance.
     */
    private function _create_pending_request(string $type, array $payload): string
    {
        // ── DUPLICATE CHECK ──
        $existingId = $this->_find_duplicate_pending($type, $payload);
        if ($existingId !== '') return $existingId; // return existing request ID

        $path = "Schools/{$this->school_name}/{$this->session_year}/Attendance/PendingApproval";
        $now  = date('c');
        $requestId = $this->firebase->push($path, [
            'type'         => $type,
            'target_id'    => $payload['target_id'] ?? '',
            'class'        => $payload['class'] ?? '',
            'section'      => $payload['section'] ?? '',
            'month'        => $payload['month'] ?? '',
            'day'          => $payload['day'] ?? null,
            'mark'         => $payload['mark'] ?? '',
            'data'         => $payload['data'] ?? [],
            'data_format'  => $payload['data_format'] ?? 'full',
            'audit'        => $payload['audit'] ?? [],
            'submitted_by' => $this->admin_id ?? $this->session->userdata('user_id') ?? 'unknown',
            'submitted_by_name' => $this->admin_name ?? '',
            'submitted_at' => $now,
            'expires_at'   => date('c', strtotime($now . ' +7 days')),
            'status'       => 'pending',
        ]);
        return $requestId ?? '';
    }

    /**
     * POST — Approve a pending backdated attendance request.
     * Admin/Principal only. Applies the attendance and updates summaries.
     */
    public function approve_attendance_request()
    {
        $this->_require_role(self::MANAGE_ROLES, 'approve_att_request');
        $requestId = $this->safe_path_segment(trim($this->input->post('request_id') ?? ''), 'request_id');
        if ($requestId === '') return $this->json_error('Request ID is required.');

        $path = "Schools/{$this->school_name}/{$this->session_year}/Attendance/PendingApproval/{$requestId}";
        $req = $this->firebase->get($path);
        if (!is_array($req)) return $this->json_error('Request not found.');
        if (($req['status'] ?? '') !== 'pending') return $this->json_error('Request is not pending.');

        // ── EXPIRY CHECK: auto-reject if past expires_at ──
        if (!empty($req['expires_at']) && strtotime($req['expires_at']) < time()) {
            $this->firebase->update($path, [
                'status'      => 'expired',
                'expired_at'  => date('c'),
            ]);
            return $this->json_error('Request has expired (older than 7 days). Auto-rejected.');
        }

        $this->load->helper('attendance');
        $school  = $this->school_name;
        $session = $this->session_year;
        $type    = $req['type'] ?? 'student';

        // ── REVALIDATE GOVERNANCE: ensure request is still allowed ──
        $rules     = $this->_att_rules();
        $pastLimit = (int)($rules['allow_past_edit_days'] ?? 0);
        $reqMonth  = $req['month'] ?? '';
        $reqMonthNum = $this->month_map[$reqMonth] ?? 0;
        $reqYear   = $reqMonthNum ? $this->_resolve_year($reqMonth) : 0;

        if ($reqMonthNum && in_array($type, ['student_day', 'staff_day'])) {
            $reqDay = (int)($req['day'] ?? 0);
            if ($reqDay && att_is_future_date($reqDay, $reqMonthNum, $reqYear)) {
                return $this->json_error('Cannot approve: request targets a future date.');
            }
            if ($reqDay && $pastLimit > 0 && !att_is_past_within_limit($reqDay, $reqMonthNum, $reqYear, $pastLimit)) {
                return $this->json_error("Cannot approve: date is now beyond the {$pastLimit}-day edit window.");
            }
        }
        if ($reqMonthNum && in_array($type, ['student_bulk', 'staff_bulk'])) {
            // For bulk, validate using the stored data's first string
            $bulkData = $req['data'] ?? [];
            $sampleStr = is_array($bulkData) ? reset($bulkData) : '';
            if (is_string($sampleStr) && $sampleStr !== '') {
                $dim = cal_days_in_month(CAL_GREGORIAN, $reqMonthNum, $reqYear);
                $govResult = att_validate_date_governance($sampleStr, $dim, $reqMonthNum, $reqYear, $pastLimit, false);
                if (!$govResult['ok'] && empty($govResult['needs_approval'])) {
                    return $this->json_error('Cannot approve: ' . ($govResult['error'] ?? 'governance check failed.'));
                }
            }
        }

        if ($type === 'student_day') {
            // Single day mark
            $class    = $req['class'] ?? '';
            $section  = $req['section'] ?? '';
            $month    = $req['month'] ?? '';
            $day      = (int)($req['day'] ?? 0);
            $mark     = strtoupper($req['mark'] ?? 'V');
            $targetId = $req['target_id'] ?? '';
            $monthNum = $this->month_map[$month] ?? 0;
            $year     = $this->_resolve_year($month);

            if (!$monthNum || !$day || !$targetId) return $this->json_error('Invalid request data.');
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);
            $attKey = "{$month} {$year}";

            $sectionRoot = $this->_resolve_section_root($class, $section);
            $attPath = "{$sectionRoot}/Students/{$targetId}/Attendance/{$attKey}";
            $existing = $this->firebase->get($attPath);
            $attStr = is_string($existing) ? $existing : str_repeat('V', $daysInMonth);
            $attStr = str_pad($attStr, $daysInMonth, 'V');

            // Check conflict
            $currentMark = strtoupper($attStr[$day - 1] ?? 'V');
            $overwrite = $this->_att_rules()['overwrite_on_approval'] ?? true;
            if ($currentMark !== 'V' && !$overwrite) {
                return $this->json_error("Day {$day} already marked as '{$currentMark}'. Overwrite disabled.");
            }

            $attStr[$day - 1] = $mark;
            $nonWorking = get_non_working_days($this->firebase, $school, $monthNum, $year);
            $attStr = enforce_holidays_on_string($attStr, $daysInMonth, $nonWorking);

            // ── WRITE: Firestore FIRST (canonical) ──
            $fsOk = $this->_syncStudentSummaryToFirestore(
                $targetId, $class, $section, $monthNum, $year, $attStr
            );
            if (!$fsOk) {
                return $this->json_error('Firestore write failed; backdated attendance not approved. Please retry.');
            }

            // ── RTDB mirror (best-effort, stays until Phase 8) ──
            $this->firebase->set($attPath, $attStr);

            $studentBase = "{$sectionRoot}/Students/{$targetId}";
            update_student_att_summary($this->firebase, $studentBase, $school, $attKey, $monthNum, $year);

            // Fire notification if absent/late
            if ($mark === 'A' || $mark === 'T') {
                $this->_fire_single_student_event($targetId, $class, $section, $mark, $day, $attKey);
            }

        } elseif ($type === 'staff_day') {
            $month    = $req['month'] ?? '';
            $day      = (int)($req['day'] ?? 0);
            $mark     = strtoupper($req['mark'] ?? 'V');
            $targetId = $req['target_id'] ?? '';
            $monthNum = $this->month_map[$month] ?? 0;
            $year     = $this->_resolve_year($month);

            if (!$monthNum || !$day || !$targetId) return $this->json_error('Invalid request data.');
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);
            $attKey = "{$month} {$year}";

            $attPath = "Schools/{$school}/{$session}/Staff_Attendance/{$attKey}/{$targetId}";
            $existing = $this->firebase->get($attPath);
            $attStr = is_string($existing) ? $existing : str_repeat('V', $daysInMonth);
            $attStr = str_pad($attStr, $daysInMonth, 'V');
            $attStr[$day - 1] = $mark;

            $nonWorking = get_non_working_days($this->firebase, $school, $monthNum, $year);
            $attStr = enforce_holidays_on_string($attStr, $daysInMonth, $nonWorking);

            // ── WRITE: Firestore FIRST (canonical) ──
            $fsOk = $this->_syncStaffSummaryToFirestore($targetId, $attKey, $attStr, '');
            if (!$fsOk) {
                return $this->json_error('Firestore write failed; backdated staff attendance not approved. Please retry.');
            }

            // ── RTDB mirror (best-effort, stays until Phase 8) ──
            $this->firebase->set($attPath, $attStr);

            update_staff_att_summary($this->firebase, $school, $session, $targetId, $attKey, $monthNum, $year);

        } elseif ($type === 'student_bulk') {
            $data = $req['data'] ?? [];
            $class   = $req['class'] ?? '';
            $section = $req['section'] ?? '';
            $month   = $req['month'] ?? '';
            $monthNum = $this->month_map[$month] ?? 0;
            $year     = $this->_resolve_year($month);
            $isDiff   = ($req['data_format'] ?? '') === 'diff';

            if (!$monthNum || empty($data)) return $this->json_error('Invalid bulk request.');
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);
            $attKey = "{$month} {$year}";
            $nonWorking = get_non_working_days($this->firebase, $school, $monthNum, $year);
            $sectionRoot = $this->_resolve_section_root($class, $section);

            foreach ($data as $studentId => $payload) {
                $studentId = trim((string)$studentId);
                if (!preg_match('/^[A-Za-z0-9_]+$/', $studentId)) continue;
                $attPath = "{$sectionRoot}/Students/{$studentId}/Attendance/{$attKey}";

                // Build the new dayWise string in memory before any write.
                if ($isDiff && is_array($payload)) {
                    // Diff format: {day => mark, ...} — read-modify-write only changed days
                    $existing = $this->firebase->get($attPath);
                    $attStr = is_string($existing) ? str_pad($existing, $daysInMonth, 'V') : str_repeat('V', $daysInMonth);
                    foreach ($payload as $d => $mk) {
                        $d = (int)$d;
                        if ($d >= 1 && $d <= $daysInMonth) $attStr[$d - 1] = strtoupper((string)$mk);
                    }
                    $attStr = enforce_holidays_on_string($attStr, $daysInMonth, $nonWorking);
                } else {
                    // Legacy full-string format (backward compat)
                    $attStr = $this->_sanitize_att_string((string)$payload, $daysInMonth);
                    $attStr = enforce_holidays_on_string($attStr, $daysInMonth, $nonWorking);
                }

                // ── WRITE: Firestore FIRST (canonical) ──
                $fsOk = $this->_syncStudentSummaryToFirestore(
                    $studentId, $class, $section, $monthNum, $year, $attStr
                );

                // ── RTDB mirror (best-effort, only on Firestore success) ──
                if ($fsOk) {
                    $this->firebase->set($attPath, $attStr);
                    $studentBase = "{$sectionRoot}/Students/{$studentId}";
                    update_student_att_summary($this->firebase, $studentBase, $school, $attKey, $monthNum, $year);
                }
            }
        } elseif ($type === 'staff_bulk') {
            $data     = $req['data'] ?? [];
            $month    = $req['month'] ?? '';
            $monthNum = $this->month_map[$month] ?? 0;
            $year     = $this->_resolve_year($month);
            $isDiff   = ($req['data_format'] ?? '') === 'diff';

            if (!$monthNum || empty($data)) return $this->json_error('Invalid staff bulk request.');
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);
            $attKey = "{$month} {$year}";
            $nonWorking = get_non_working_days($this->firebase, $school, $monthNum, $year);

            foreach ($data as $staffId => $payload) {
                $staffId = trim((string)$staffId);
                if (!preg_match('/^[A-Za-z0-9_]+$/', $staffId)) continue;
                $attPath = "Schools/{$school}/{$session}/Staff_Attendance/{$attKey}/{$staffId}";

                // Build the new dayWise string in memory before any write.
                if ($isDiff && is_array($payload)) {
                    $existing = $this->firebase->get($attPath);
                    $attStr = is_string($existing) ? str_pad($existing, $daysInMonth, 'V') : str_repeat('V', $daysInMonth);
                    foreach ($payload as $d => $mk) {
                        $d = (int)$d;
                        if ($d >= 1 && $d <= $daysInMonth) $attStr[$d - 1] = strtoupper((string)$mk);
                    }
                    $attStr = enforce_holidays_on_string($attStr, $daysInMonth, $nonWorking);
                } else {
                    $attStr = $this->_sanitize_att_string((string)$payload, $daysInMonth);
                    $attStr = enforce_holidays_on_string($attStr, $daysInMonth, $nonWorking);
                }

                // ── WRITE: Firestore FIRST (canonical) ──
                $fsOk = $this->_syncStaffSummaryToFirestore($staffId, $attKey, $attStr, '');

                // ── RTDB mirror (best-effort, only on Firestore success) ──
                if ($fsOk) {
                    $this->firebase->set($attPath, $attStr);
                    update_staff_att_summary($this->firebase, $school, $session, $staffId, $attKey, $monthNum, $year);
                }
            }
        } else {
            return $this->json_error("Unknown request type: {$type}");
        }

        // Mark approved
        $this->firebase->update($path, [
            'status'      => 'approved',
            'approved_by' => $this->admin_name ?? $this->admin_id ?? 'system',
            'approved_at' => date('c'),
        ]);

        $this->_log_attendance_change('APPROVE_BACKDATED', [
            'request_id' => $requestId, 'type' => $type,
            'audit'      => $req['audit'] ?? [],
        ]);

        return $this->json_success(['message' => 'Backdated attendance approved and applied.']);
    }

    /**
     * POST — Reject a pending backdated attendance request.
     */
    public function reject_attendance_request()
    {
        $this->_require_role(self::MANAGE_ROLES, 'reject_att_request');
        $requestId = $this->safe_path_segment(trim($this->input->post('request_id') ?? ''), 'request_id');
        $reason    = trim($this->input->post('reason') ?? '');

        if ($requestId === '') return $this->json_error('Request ID is required.');

        $path = "Schools/{$this->school_name}/{$this->session_year}/Attendance/PendingApproval/{$requestId}";
        $req = $this->firebase->get($path);
        if (!is_array($req)) return $this->json_error('Request not found.');
        if (($req['status'] ?? '') !== 'pending') return $this->json_error('Request is not pending.');

        $this->firebase->update($path, [
            'status'      => 'rejected',
            'rejected_by' => $this->admin_name ?? $this->admin_id ?? 'system',
            'rejected_at' => date('c'),
            'reason'      => $reason,
        ]);

        return $this->json_success(['message' => 'Request rejected.']);
    }

    /**
     * GET — List pending approval requests.
     */
    public function list_pending_attendance()
    {
        $this->_require_role(self::MANAGE_ROLES, 'list_pending_att');
        $path = "Schools/{$this->school_name}/{$this->session_year}/Attendance/PendingApproval";
        $all = $this->firebase->get($path);
        $pending = [];
        if (is_array($all)) {
            foreach ($all as $id => $req) {
                if (!is_array($req)) continue;
                if (($req['status'] ?? '') !== 'pending') continue;
                // Auto-expire stale requests
                if (!empty($req['expires_at']) && strtotime($req['expires_at']) < time()) {
                    $expPath = "Schools/{$this->school_name}/{$this->session_year}/Attendance/PendingApproval/{$id}";
                    $this->firebase->update($expPath, ['status' => 'expired', 'expired_at' => date('c')]);
                    continue;
                }
                $req['id'] = $id;
                $pending[] = $req;
            }
        }
        return $this->json_success(['requests' => $pending, 'count' => count($pending)]);
    }

    // ====================================================================
    //  ATTENDANCE LOCK (prevents edits after payroll)
    // ====================================================================

    /**
     * Check if staff attendance for a month is locked (e.g., after payroll).
     * Returns lock data if locked, null if not.
     */
    private function _check_staff_att_lock(string $attKey): ?array
    {
        $lockPath = "Schools/{$this->school_name}/{$this->session_year}/Staff_Attendance/Locks/{$attKey}";
        $lock = $this->firebase->get($lockPath);
        if (is_array($lock) && !empty($lock['locked'])) {
            return $lock;
        }
        return null;
    }

    /**
     * POST — Lock staff attendance for a month (called after payroll finalization).
     */
    public function lock_staff_attendance()
    {
        $this->_require_role(self::MANAGE_ROLES, 'lock_staff_att');
        $month = trim((string) $this->input->post('month'));
        if (!$month) return $this->json_error('Month is required.');

        $year = $this->_resolve_year($month);
        $attKey = "{$month} {$year}";

        $lockPath = "Schools/{$this->school_name}/{$this->session_year}/Staff_Attendance/Locks/{$attKey}";
        $this->firebase->set($lockPath, [
            'locked'    => true,
            'locked_at' => date('c'),
            'locked_by' => $this->admin_name ?? $this->admin_id ?? 'system',
        ]);
        return $this->json_success(['message' => "Staff attendance locked for {$attKey}."]);
    }

    /**
     * POST — Unlock staff attendance for a month (admin override).
     */
    public function unlock_staff_attendance()
    {
        $this->_require_role(self::MANAGE_ROLES, 'unlock_staff_att');
        $month = trim((string) $this->input->post('month'));
        if (!$month) return $this->json_error('Month is required.');

        $year = $this->_resolve_year($month);
        $attKey = "{$month} {$year}";

        $lockPath = "Schools/{$this->school_name}/{$this->session_year}/Staff_Attendance/Locks/{$attKey}";
        $this->firebase->delete($lockPath);
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

            // ── DEDUP: Phase 8a — Firestore-first, RTDB fallback ──
            // Canonical: collection `attendanceEventsFired`,
            //            doc id `{schoolId}_{md5(student|date|mark)}`
            // Legacy:    RTDB Schools/{school}/{session}/Attendance/Event_Fired/{md5key}
            //
            // We check BOTH stores so that a record written under
            // either path blocks duplicate fires. New writes go to
            // Firestore first, then mirror RTDB.
            $dedupKey  = att_event_dedup_key($studentId, $date, $mark);
            $fsDedupId = $this->school_id . '_' . $dedupKey;
            $rtdbDedupPath = "Schools/{$this->school_name}/{$this->session_year}/Attendance/Event_Fired/{$dedupKey}";

            // Firestore check first
            $fsDedupDoc = null;
            try {
                $fsDedupDoc = $this->fs->get('attendanceEventsFired', $fsDedupId);
            } catch (\Exception $e) {
                log_message('error', "Attendance dedup Firestore read failed: " . $e->getMessage());
            }
            if (is_array($fsDedupDoc)) return;

            // RTDB legacy check
            $rtdbDedup = $this->firebase->get($rtdbDedupPath);
            if ($rtdbDedup !== null) return;

            // Get student profile
            $studentData = $this->firebase->get("Users/Parents/{$this->parent_db_key}/{$studentId}");
            $studentName = is_array($studentData) ? ($studentData['Name'] ?? $studentId) : $studentId;
            $parentName  = is_array($studentData) ? ($studentData['Father Name'] ?? '') : '';

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

            // ── PATH 2: In-app notification (RTDB inbox the parent app listens to) ──
            try {
                $notifId = 'ATT_' . date('YmdHis') . '_' . substr(md5($studentId . $day), 0, 6);
                $notifPath = "Users/Parents/{$this->parent_db_key}/{$studentId}/Notifications/{$notifId}";
                $this->firebase->set($notifPath, [
                    'type'    => $eventType,
                    'title'   => "Attendance: {$statusLabel}",
                    'message' => "{$studentName} was marked {$statusLabel} on " . date('d M Y') . " ({$class}, {$section})",
                    'date'    => $date,
                    'day'     => $day,
                    'read'    => false,
                    'created_at' => date('c'),
                ]);
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

            // ── Mark as fired — Phase 8a: Firestore FIRST → RTDB mirror ──
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
            // RTDB mirror (best-effort, stays until Phase 9)
            try {
                $this->firebase->set($rtdbDedupPath, [
                    'student' => $studentId, 'mark' => $mark,
                    'queued' => $queued, 'direct' => true,
                    'pushed' => $pushed, 'at' => date('c'),
                ]);
            } catch (\Exception $e) { /* mirror best-effort */ }
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
            } catch (\Exception $e) { /* fall through to per-student RTDB read */ }

            $sectionRoot = $this->_resolve_section_root($class, $section);

            foreach ($students as $studentId => $v) {
                $studentId = (string)$studentId;
                if ($studentId === '') continue;

                // Firestore-first → RTDB fallback for the dayWise
                $attStr = $fsDayWise[$studentId] ?? '';
                if ($attStr === '') {
                    $attPath = "{$sectionRoot}/Students/{$studentId}/Attendance/{$attKey}";
                    $rtdb = $this->firebase->get($attPath);
                    if (is_string($rtdb)) $attStr = $rtdb;
                }
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
            return $ok;
        } catch (\Exception $e) {
            log_message('error', "Attendance Firestore sync failed for {$studentId}: " . $e->getMessage());
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
        string $studentName = ''
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
        $logEntry = array_merge([
            'user'      => $this->session->userdata('user_id') ?: 'api',
            'role'      => $this->session->userdata('Role') ?: 'device',
            'action'    => $action,
            'school'    => $this->school_name,
            'date'      => date('Y-m-d'),
            'timestamp' => date('c'),
            'epoch'     => time(),
            'ip'        => $this->input->ip_address(),
        ], $details);

        // Fire-and-forget: append to local queue (microseconds, not network RTT)
        if ($this->_enqueue('audit_log', $logEntry)) {
            return;
        }

        // Fallback: direct write (only if queue file write fails)
        $yearMonth = date('Y-m');
        $logKey    = date('d_His') . '_' . mt_rand(1000, 9999);
        $schoolId  = $this->school_name;
        $this->firebase->set("System/Logs/Attendance/{$schoolId}/{$yearMonth}/{$logKey}", $logEntry);
    }

    /* ================================================================
       ASYNC QUEUE — Local JSONL file queue, flushed by cron
       ================================================================ */

    /**
     * Append an item to the local JSONL queue file.
     * Returns true on success, false on failure.
     */
    private function _enqueue(string $type, array $data): bool
    {
        $queueDir = APPPATH . 'cache/attendance/queue/';
        try {
            if (!is_dir($queueDir)) mkdir($queueDir, 0700, true);
            $file = $queueDir . $type . '_' . date('Y-m-d') . '.jsonl';
            $line = json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
            return file_put_contents($file, $line, FILE_APPEND | LOCK_EX) !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Flush the queue: read all JSONL files, write to Firebase, delete processed files.
     * Called by cleanup() endpoint (cron job).
     * Returns count of items flushed.
     */
    private function _flush_queue(): int
    {
        $queueDir = APPPATH . 'cache/attendance/queue/';
        if (!is_dir($queueDir)) return 0;

        $flushed = 0;
        $files = glob($queueDir . 'audit_log_*.jsonl');
        if (!$files) return 0;

        foreach ($files as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!$lines) {
                @unlink($file);
                continue;
            }

            foreach ($lines as $line) {
                $entry = json_decode($line, true);
                if (!is_array($entry) || empty($entry['school'])) continue;

                $schoolId  = $entry['school'];
                $yearMonth = substr($entry['date'] ?? date('Y-m-d'), 0, 7);
                $logKey    = substr($entry['date'] ?? date('Y-m-d'), 8, 2)
                    . '_' . date('His', $entry['epoch'] ?? time())
                    . '_' . mt_rand(1000, 9999);

                $this->firebase->set(
                    "System/Logs/Attendance/{$schoolId}/{$yearMonth}/{$logKey}",
                    $entry
                );
                $flushed++;
            }

            @unlink($file); // Remove processed file
        }

        return $flushed;
    }

    /**
     * Incrementally update the Summary cache when a single student's mark changes.
     * Adjusts the cached P/A/L/H/T/V counts rather than requiring full recompute.
     *
     * @param string $class   e.g. "Class 9th"
     * @param string $section e.g. "A"
     * @param string $attKey  e.g. "April 2026"
     * @param string $studentId
     * @param string $oldMark Previous mark character (P/A/L/H/T/V)
     * @param string $newMark New mark character
     */
    private function _update_summary_incremental(
        string $class, string $section, string $attKey,
        string $studentId, string $oldMark, string $newMark
    ): void {
        if ($oldMark === $newMark) return;

        $school  = $this->school_name;
        $session = $this->session_year;
        $csKey   = str_replace(' ', '_', $class) . '_' . $section;
        $summaryPath = "Schools/{$school}/{$session}/Attendance/Summary/Students/{$attKey}/{$csKey}";

        $summary = $this->firebase->get($summaryPath);
        if (!is_array($summary) || !isset($summary['students'])) return;

        // Update the individual student stats
        if (isset($summary['students'][$studentId]) && is_array($summary['students'][$studentId])) {
            $s = &$summary['students'][$studentId];

            // Decrement old, increment new
            $validMarks = ['P', 'A', 'L', 'H', 'T', 'V'];
            if (in_array($oldMark, $validMarks) && isset($s[$oldMark])) {
                $s[$oldMark] = max(0, ($s[$oldMark] ?? 0) - 1);
            }
            if (in_array($newMark, $validMarks)) {
                $s[$newMark] = ($s[$newMark] ?? 0) + 1;
            }

            // Recompute this student's percentage
            $working = ($s['P'] ?? 0) + ($s['A'] ?? 0) + ($s['L'] ?? 0) + ($s['T'] ?? 0);
            $s['pct'] = $working > 0
                ? round((($s['P'] ?? 0) + ($s['T'] ?? 0)) / $working * 100, 1)
                : 0;
        }

        // Recompute section-level avg from all students
        $totalPct = 0;
        $totalStudents = 0;
        foreach ($summary['students'] as $sData) {
            if (!is_array($sData)) continue;
            $totalPct += $sData['pct'] ?? 0;
            $totalStudents++;
        }
        $summary['total_students'] = $totalStudents;
        $summary['avg_present_pct'] = $totalStudents > 0
            ? round($totalPct / $totalStudents, 1) : 0;

        $this->firebase->set($summaryPath, $summary);
    }

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

    /* ================================================================
       OBSERVABILITY — Aggregated endpoint metrics
       ================================================================ */

    /**
     * Record a performance metric via incremental counter update.
     * Path: System/Metrics/Attendance/{YYYY-MM-DD}/{endpoint}
     *
     * Instead of writing N individual metric entries, we maintain a single
     * summary node per endpoint per day and update it incrementally.
     * This reduces Firebase writes from O(N) to O(1) per request.
     */
    private function _log_metric(string $endpoint, float $startTime, string $status, string $school = ''): void
    {
        $latencyMs = round((microtime(true) - $startTime) * 1000);
        $dateStr   = date('Y-m-d');
        $summaryPath = "System/Metrics/Attendance/{$dateStr}/{$endpoint}";

        // Read current counters (1 read)
        $current = $this->firebase->get($summaryPath);
        if (!is_array($current)) {
            $current = [
                'total_requests' => 0,
                'error_count'    => 0,
                'slow_count'     => 0,
                'total_latency'  => 0,
                'max_latency'    => 0,
                'last_updated'   => '',
            ];
        }

        // Increment counters
        $current['total_requests'] = ($current['total_requests'] ?? 0) + 1;
        $current['total_latency']  = ($current['total_latency'] ?? 0) + $latencyMs;
        if ($status !== 'success') {
            $current['error_count'] = ($current['error_count'] ?? 0) + 1;
        }
        if ($latencyMs > 200) {
            $current['slow_count'] = ($current['slow_count'] ?? 0) + 1;
        }
        if ($latencyMs > ($current['max_latency'] ?? 0)) {
            $current['max_latency'] = $latencyMs;
        }
        $current['avg_latency'] = $current['total_requests'] > 0
            ? round($current['total_latency'] / $current['total_requests'])
            : 0;
        $current['last_updated'] = date('c');

        // Single write (1 update)
        $this->firebase->set($summaryPath, $current);
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
    private function _sanitize_att_string(string $raw, int $daysInMonth): string
    {
        $raw = strtoupper(trim($raw));
        $raw = substr($raw, 0, $daysInMonth);
        $clean = '';
        for ($i = 0; $i < strlen($raw); $i++) {
            $clean .= in_array($raw[$i], $this->valid_marks) ? $raw[$i] : 'V';
        }
        return str_pad($clean, $daysInMonth, 'V');
    }
}
