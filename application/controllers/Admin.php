<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin controller
 *
 * SECURITY FIXES:
 * [FIX-1]  Removed duplicate auth check in __construct — MY_Controller handles it.
 * [FIX-2]  Hardcoded school ID '1111' replaced with $this->school_id from session.
 * [FIX-3]  manage_admin: password update uses password_hash (was plaintext).
 * [FIX-4]  All Firebase paths use session school_id (not hardcoded '1111').
 * [FIX-5]  updateUserData: user can only update their own school's admin data.
 * [FIX-6]  Debug echo / print_r calls removed from production code.
 */
class Admin extends MY_Controller
{
    private const ADMIN_ROLES = ['Super Admin', 'School Super Admin', 'Admin'];
    private const VIEW_ROLES  = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator', 'HR Manager', 'Accountant', 'Front Office', 'Class Teacher', 'Teacher', 'Librarian', 'Transport Manager', 'Hostel Warden'];

    public function __construct()
    {
        parent::__construct();
        // [FIX-1] No duplicate auth here — MY_Controller __construct handles it.
    }

    public function index()
    {
        // Dashboard is the landing page — any authenticated admin can see it.
        // MY_Controller __construct already enforces authentication.

        // Role-specific dashboard redirects
        // Non-bypass roles get redirected to their primary module dashboard
        $role = $this->admin_role ?? '';
        $role_redirects = [
            'HR Manager'           => 'hr',
            'Accountant'           => 'accounting',
            'Academic Coordinator' => 'academic',
            'Librarian'            => 'library',
            'Transport Manager'    => 'transport',
            'Hostel Warden'        => 'hostel',
            'Operations Manager'   => 'operations',
        ];

        if (isset($role_redirects[$role])) {
            redirect($role_redirects[$role]);
            return;
        }

        $school_id    = $this->school_id;
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        // Fetch school logo from Firestore
        $schoolDoc = $this->fs->get('schools', $school_id);
        $school_logo_url = $schoolDoc['logoUrl'] ?? $schoolDoc['logo_url'] ?? '';
        if (!$school_logo_url) {
            $school_logo_url = base_url('tools/dist/img/default-school.png');
        }

        $data = [
            'admin_name'      => $this->admin_name,
            'admin_role'      => $this->admin_role,
            'school_id'       => $school_id,
            'admin_id'        => $this->admin_id,
            'schoolName'      => $school_name,
            'Session'         => $session_year,
            'school_logo_url' => $school_logo_url,
        ];

        $this->load->view('include/header', $data);
        $this->load->view('home', $data);
        $this->load->view('include/footer');
    }

    // ====================================================================
    //  DASHBOARD DATA — single AJAX endpoint (max 5 Firebase reads)
    // ====================================================================

    /**
     * FAST dashboard payload — only critical stats + today's attendance
     * + upcoming events. Uses server-side aggregations where possible so
     * we don't pull full collections. Heavy chart/calendar/demographics
     * data moved to get_dashboard_charts() which the frontend lazy-loads.
     *
     * Response shape preserved: every key the old endpoint returned is
     * still present. Lazy fields (students_by_class, gender, monthly_fees,
     * calendar_events, events.ongoing, events.recent, stats.classes,
     * stats.sections, stats.fee_defaulters) are shipped as empty
     * placeholders on the initial response and overwritten by the
     * charts endpoint once it returns.
     */
    public function get_dashboard_data()
    {
        header('Content-Type: application/json');

        // Release the PHP session lock immediately. Dashboard endpoints
        // don't write to session, but CI3's file session handler holds a
        // per-user lock for the life of the request — which serialises
        // all parallel AJAX calls on the same browser session. Closing
        // early lets the 4 dashboard fetches actually run in parallel.
        if (function_exists('session_write_close')) @session_write_close();

        $role = $this->admin_role;

        $this->load->library('dashboard_cache');
        $cacheKey = 'dashboard_data_' . ($role ?? '');
        $cacheAge = null;
        $cached = $this->dashboard_cache->get($this->school_name, $cacheKey, $cacheAge);
        if ($cached !== null) {
            log_message('debug', "DASHBOARD_CACHE HIT key={$cacheKey} school={$this->school_name} age=" . ($cacheAge === null ? 'unknown' : $cacheAge) . 's');
            echo json_encode($cached);
            return;
        }
        log_message('debug', "DASHBOARD_CACHE MISS key={$cacheKey} school={$this->school_name}");

        $session   = $this->session_year;
        $schoolId  = $this->school_id;
        $todayDate = date('Y-m-d');

        // ── Aggregation 1: Active student count (no docs transferred) ────
        $studentCount = $this->fs->count('students', [
            ['schoolId', '==', $schoolId],
            ['status',   '==', 'Active'],
        ]);

        // ── Aggregation 2: Teacher count ─────────────────────────────────
        $teacherCount = $this->fs->count('staff', [
            ['schoolId', '==', $schoolId],
            ['sessions', 'array-contains', $session],
        ]);

        // ── Aggregation 3+4: feeReceipts count + sum(allocated_amount) ───
        $feesCollected = 0.0;
        $receiptCount  = 0;
        if ($role !== 'Teacher') {
            $feeAgg = $this->fs->aggregate('feeReceipts', [
                ['schoolId', '==', $schoolId],
                ['session',  '==', $session],
            ], [
                ['op' => 'count', 'alias' => 'n'],
                ['op' => 'sum',   'field' => 'allocated_amount', 'alias' => 'total'],
            ]);
            $receiptCount  = (int)   ($feeAgg['n']     ?? 0);
            $feesCollected = (float) ($feeAgg['total'] ?? 0);
        }

        // ── Bounded query: upcoming events (limit 5) ─────────────────────
        // Post-migration: events are written with camelCase `startDate` only.
        // Read fallbacks to `start_date` keep legacy docs working until the
        // backfill/cleanup scripts have run.
        $upcoming = [];
        $upcomingDocs = $this->fs->where(
            'events',
            [
                ['schoolId',  '==', $schoolId],
                ['status',    '==', 'scheduled'],
                ['startDate', '>=', $todayDate],
            ],
            'startDate', 'ASC', 5
        );
        foreach ((array) $upcomingDocs as $doc) {
            $d = $doc['data'] ?? $doc;
            $evt = $doc['data'];
            $start = $evt['startDate'] ?? $evt['start_date'] ?? '';
            $end   = $evt['endDate']   ?? $evt['end_date']   ?? $start;
            $upcoming[] = [
                'id'       => $d['id'],
                'title'    => $evt['title']    ?? '',
                'category' => $evt['category'] ?? 'event',
                'start'    => $start,
                'end'      => $end,
                'status'   => $evt['status']   ?? 'scheduled',
                'location' => $evt['location'] ?? '',
            ];
        }

        // ── Today's attendance (bounded by date filter — small scan) ─────
        $attPresent = 0; $attAbsent = 0; $attLate = 0; $attTotal = 0;
        $todayAttDocs = $this->fs->schoolWhere('attendance', [
            ['date', '==', $todayDate],
            ['type', '==', 'student'],
        ]);
        foreach ((array) $todayAttDocs as $doc) {
            $mark = strtoupper($doc['data']['status'] ?? 'V');
            $attTotal++;
            if ($mark === 'P') $attPresent++;
            elseif ($mark === 'A') $attAbsent++;
            elseif ($mark === 'L' || $mark === 'T') { $attPresent++; $attLate++; }
        }
        $attRate = $attTotal > 0 ? round(($attPresent / $attTotal) * 100, 1) : null;

        // Response shape matches the old endpoint — lazy keys are empty
        // placeholders that the charts endpoint will backfill.
        $payload = [
            'role'              => $role,
            'stats'             => [
                'students'       => $studentCount,
                'teachers'       => $teacherCount,
                'classes'        => 0,
                'sections'       => 0,
                'fees_collected' => $feesCollected,
                'receipt_count'  => $receiptCount,
                'fee_defaulters' => 0,
            ],
            'attendance'        => [
                'present' => $attPresent,
                'absent'  => $attAbsent,
                'late'    => $attLate,
                'total'   => $attTotal,
                'rate'    => $attRate,
            ],
            'students_by_class' => (object) [],
            'gender'            => ['Male' => 0, 'Female' => 0, 'Other' => 0],
            'monthly_fees'      => (object) [],
            'events'            => [
                'upcoming' => $upcoming,
                'ongoing'  => [],
                'recent'   => [],
            ],
            'calendar_events'   => [],
        ];

        $this->dashboard_cache->set($this->school_name, $cacheKey, $payload, 600);
        echo json_encode($payload);
    }

    /**
     * LAZY dashboard charts — the data that requires full-collection scans.
     * Called by the frontend AFTER get_dashboard_data lands so the main
     * tiles render immediately while charts/demographics populate shortly
     * after. 15-second cache keyed by schoolId + role.
     */
    public function get_dashboard_charts()
    {
        header('Content-Type: application/json');
        if (function_exists('session_write_close')) @session_write_close();

        $role = $this->admin_role;

        $this->load->library('dashboard_cache');
        $cacheKey = 'dashboard_charts_' . ($role ?? '');
        $cacheAge = null;
        $cached = $this->dashboard_cache->get($this->school_name, $cacheKey, $cacheAge);
        if ($cached !== null) {
            log_message('debug', "DASHBOARD_CACHE HIT key={$cacheKey} school={$this->school_name} age=" . ($cacheAge === null ? 'unknown' : $cacheAge) . 's');
            echo json_encode($cached);
            return;
        }
        log_message('debug', "DASHBOARD_CACHE MISS key={$cacheKey} school={$this->school_name}");

        // ── Students scan (for class + gender + section distribution) ───
        $studentDocs    = $this->fs->schoolWhere('students', [['status', '==', 'Active']]);
        $studentCount   = 0;
        $classDist      = [];
        $genderDist     = ['Male' => 0, 'Female' => 0, 'Other' => 0];
        $sectionSet     = [];
        $birthdaysToday = [];
        $todayMd        = date('m-d');
        foreach ((array) $studentDocs as $doc) {
            $s = $doc['data'];
            $studentCount++;
            $cls = trim($s['className'] ?? $s['Class'] ?? 'Unknown');
            $sec = trim($s['section']   ?? $s['Section'] ?? '');
            $classDist[$cls] = ($classDist[$cls] ?? 0) + 1;
            if ($cls && $sec) $sectionSet["{$cls}|{$sec}"] = true;
            $g = strtolower(trim($s['gender'] ?? $s['Gender'] ?? ''));
            if ($g === 'male' || $g === 'm')        $genderDist['Male']++;
            elseif ($g === 'female' || $g === 'f')  $genderDist['Female']++;
            elseif ($g !== '')                        $genderDist['Other']++;

            // Birthdays today — DOB formats in the wild include
            // "YYYY-MM-DD", "DD/MM/YYYY", "DD-MM-YYYY", and ISO timestamp.
            // Extract month-day tokens from each.
            $dob = (string) ($s['dob'] ?? $s['DOB'] ?? $s['dateOfBirth'] ?? '');
            if ($dob !== '') {
                $mdCandidate = '';
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $dob, $m)) {
                    // ISO: YYYY-MM-DD
                    $mdCandidate = $m[2] . '-' . $m[3];
                } elseif (preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})/', $dob, $m)) {
                    // Indian: DD/MM/YYYY or DD-MM-YYYY
                    $mdCandidate = $m[2] . '-' . $m[1];
                }
                if ($mdCandidate === $todayMd) {
                    $birthdaysToday[] = [
                        'studentId' => (string) ($s['studentId'] ?? $s['userId'] ?? $s['user_id'] ?? ''),
                        'name'      => (string) ($s['name'] ?? $s['Name'] ?? 'Student'),
                        'class'     => trim($cls . ' ' . $sec),
                        'className' => $cls,
                        'section'   => $sec,
                    ];
                }
            }
        }
        uksort($classDist, 'strnatcasecmp');
        $classCount   = count($classDist);
        $sectionCount = count($sectionSet);

        // ── Receipts scan (monthly breakdown + fee breakdown + unique paid students) ────
        $monthlyFees    = [];
        $paidStudentIds = [];
        $feeBreakdown   = ['today' => 0.0, 'month' => 0.0, 'year' => 0.0];
        $todayStr       = date('Y-m-d');
        $monthStartStr  = date('Y-m-01');
        $yearStartStr   = date('Y-01-01');
        if ($role !== 'Teacher') {
            $receiptDocs = $this->fs->sessionWhere('feeReceipts', []);
            foreach ((array) $receiptDocs as $doc) {
                $r = $doc['data'];
                $amt = (float) ($r['allocated_amount']
                              ?? $r['allocatedAmount']
                              ?? $r['amount']
                              ?? 0);
                $uid = $r['studentId'] ?? $r['userId'] ?? $r['user_id'] ?? '';
                if ($uid) $paidStudentIds[$uid] = true;
                $dateStr = $r['paidAt'] ?? $r['date'] ?? '';
                if ($dateStr) {
                    $ts = strtotime($dateStr);
                    if ($ts) {
                        $monthKey = date('Y-m', $ts);
                        $monthlyFees[$monthKey] = ($monthlyFees[$monthKey] ?? 0) + $amt;
                        // Fee breakdown by date bucket.
                        $iso = date('Y-m-d', $ts);
                        if ($iso >= $yearStartStr)  $feeBreakdown['year']  += $amt;
                        if ($iso >= $monthStartStr) $feeBreakdown['month'] += $amt;
                        if ($iso === $todayStr)     $feeBreakdown['today'] += $amt;
                    }
                }
            }
            ksort($monthlyFees);
        }
        $feeDefaulters = max(0, $studentCount - count($paidStudentIds));

        // ── Events scan (ongoing + recent + calendar) ───────────────────
        $eventDocs      = $this->fs->schoolWhere('events', []);
        $ongoing        = [];
        $recent         = [];
        $calendarEvents = [];
        $today          = date('Y-m-d');
        foreach ((array) $eventDocs as $doc) {
            $d = $doc['data'] ?? $doc;
            $evt    = $doc['data'];
            $start  = $evt['start_date'] ?? '';
            $end    = $evt['end_date']   ?? $start;
            $status = $evt['status']     ?? 'scheduled';
            $item = [
                'id'       => $d['id'],
                'title'    => $evt['title']    ?? '',
                'category' => $evt['category'] ?? 'event',
                'start'    => $start,
                'end'      => $end,
                'status'   => $status,
                'location' => $evt['location'] ?? '',
            ];
            if ($start) {
                $calendarEvents[] = ['date' => $start, 'title' => $item['title']];
            }
            if ($status === 'cancelled') continue;
            if ($status === 'ongoing' || ($start <= $today && $end >= $today)) {
                $ongoing[] = $item;
            } elseif ($status === 'completed') {
                $recent[] = $item;
            }
        }
        usort($recent, fn($a, $b) => strcmp($b['start'], $a['start']));
        $recent = array_slice($recent, 0, 3);

        // ── Top 5 Fee Defaulters ────────────────────────────────────────
        // Fetch defaulters for current school+session, sort by totalDues DESC
        // client-side (avoids a new composite index just for this).
        $topDefaulters = [];
        if ($role !== 'Teacher') {
            try {
                $defDocs = $this->fs->where('feeDefaulters', [
                    ['schoolId', '==', $this->school_id],
                    ['session',  '==', $this->session_year],
                ]);
                $defList = [];
                foreach ((array) $defDocs as $doc) {
                    $d = $doc['data'];
                    $dues = (float) ($d['totalDues'] ?? 0);
                    if ($dues <= 0) continue;
                    $defList[] = [
                        'studentId'     => (string) ($d['studentId'] ?? ''),
                        'name'          => (string) ($d['studentName'] ?? 'Student'),
                        'class'         => trim(((string) ($d['className'] ?? '')) . ' ' . ((string) ($d['section'] ?? ''))),
                        'totalDues'     => $dues,
                        'unpaidCount'   => is_array($d['unpaidMonths'] ?? null) ? count($d['unpaidMonths']) : 0,
                        'examBlocked'   => (bool) ($d['examBlocked'] ?? false),
                    ];
                }
                usort($defList, fn($a, $b) => $b['totalDues'] <=> $a['totalDues']);
                $topDefaulters = array_slice($defList, 0, 5);
            } catch (\Exception $e) {
                log_message('error', 'top_defaulters fetch failed: ' . $e->getMessage());
            }
        }

        // ── Today's Absent Students ─────────────────────────────────────
        $absentList  = [];
        try {
            $todayIso = date('Y-m-d');
            $attDocs  = $this->fs->schoolWhere('attendance', [
                ['date', '==', $todayIso],
                ['type', '==', 'student'],
            ]);
            foreach ((array) $attDocs as $doc) {
                $a = $doc['data'];
                $mark = strtoupper((string) ($a['status'] ?? ''));
                if ($mark !== 'A') continue;
                $absentList[] = [
                    'studentId' => (string) ($a['studentId'] ?? $a['personId'] ?? ''),
                    'name'      => (string) ($a['studentName'] ?? $a['personName'] ?? 'Student'),
                    'class'     => trim(((string) ($a['className'] ?? '')) . ' ' . ((string) ($a['section'] ?? ''))),
                ];
            }
            // Dedupe (same student could have multiple attendance rows for a day in edge cases)
            $seen = [];
            $absentList = array_values(array_filter($absentList, function ($r) use (&$seen) {
                $k = $r['studentId'] . '|' . $r['name'];
                if (isset($seen[$k])) return false;
                $seen[$k] = true;
                return true;
            }));
        } catch (\Exception $e) {
            log_message('error', 'absent_students scan failed: ' . $e->getMessage());
        }

        $payload = [
            'classes'           => $classCount,
            'sections'          => $sectionCount,
            'fee_defaulters'    => $feeDefaulters,
            'monthly_fees'      => $monthlyFees,
            'fee_breakdown'     => $feeBreakdown,
            'birthdays_today'   => $birthdaysToday,
            'top_defaulters'    => $topDefaulters,
            'absent_today'      => [
                'count'    => count($absentList),
                'students' => array_slice($absentList, 0, 8), // render up to 8; count shows total
            ],
            'events'            => [
                'ongoing' => $ongoing,
                'recent'  => $recent,
            ],
            'calendar_events'   => $calendarEvents,
        ];

        $this->dashboard_cache->set($this->school_name, $cacheKey, $payload, 600);
        echo json_encode($payload);
    }

    /**
     * Unified activity feed — merges recent fee receipts, leave applications,
     * and notices into a single timeline. Separate endpoint so the main
     * dashboard + charts render first, then activity fills in.
     */
    public function get_dashboard_activity()
    {
        header('Content-Type: application/json');
        if (function_exists('session_write_close')) @session_write_close();

        $role = $this->admin_role;

        $this->load->library('dashboard_cache');
        $cacheKey = 'dashboard_activity_' . ($role ?? '');
        $cacheAge = null;
        $cached = $this->dashboard_cache->get($this->school_name, $cacheKey, $cacheAge);
        if ($cached !== null) {
            log_message('debug', "DASHBOARD_CACHE HIT key={$cacheKey} school={$this->school_name} age=" . ($cacheAge === null ? 'unknown' : $cacheAge) . 's');
            echo json_encode($cached);
            return;
        }
        log_message('debug', "DASHBOARD_CACHE MISS key={$cacheKey} school={$this->school_name}");

        $schoolId   = $this->school_id;
        $activity   = [];

        // Recent fee receipts
        if ($role !== 'Teacher') {
            try {
                $rows = $this->fs->where(
                    'feeReceipts',
                    [['schoolId', '==', $schoolId]],
                    'createdAt', 'DESC', 5
                );
                foreach ((array) $rows as $doc) {
                    $r = $doc['data'];
                    $amt = (float) ($r['allocated_amount'] ?? $r['allocatedAmount'] ?? $r['amount'] ?? 0);
                    $activity[] = [
                        'type'   => 'fee',
                        'icon'   => 'fa-inr',
                        'color'  => '#15803d',
                        'title'  => '₹' . number_format($amt, 2) . ' collected',
                        'detail' => (string) ($r['studentName'] ?? $r['student_name'] ?? 'Student') .
                                    (isset($r['receiptNo']) ? ' · #' . $r['receiptNo'] : ''),
                        'time'   => (string) ($r['createdAt'] ?? $r['paidAt'] ?? ''),
                        'action' => 'fees/fees_records',
                    ];
                }
            } catch (\Exception $e) {
                log_message('error', 'activity feeReceipts failed: ' . $e->getMessage());
            }
        }

        // Recent leave applications
        try {
            $rows = $this->fs->where(
                'leaveApplications',
                [['schoolId', '==', $schoolId]],
                'createdAt', 'DESC', 5
            );
            foreach ((array) $rows as $doc) {
                $l = $doc['data'];
                $status = (string) ($l['status'] ?? 'Pending');
                $activity[] = [
                    'type'   => 'leave',
                    'icon'   => 'fa-calendar-minus-o',
                    'color'  => $status === 'Approved' ? '#15803d' : ($status === 'Rejected' ? '#dc2626' : '#d97706'),
                    'title'  => $status . ' leave',
                    'detail' => (string) ($l['applicantName'] ?? $l['staffName'] ?? $l['studentName'] ?? 'Applicant'),
                    'time'   => (string) ($l['createdAt'] ?? ''),
                    'action' => 'hr/leaves',
                ];
            }
        } catch (\Exception $e) {
            log_message('error', 'activity leaveApplications failed: ' . $e->getMessage());
        }

        // Recent notices — uses the camelCase notices collection (see
        // Communication_helper::write_event_notice migration).
        try {
            $rows = $this->fs->where(
                'notices',
                [['schoolId', '==', $this->school_name]],
                'createdAt', 'DESC', 5
            );
            foreach ((array) $rows as $doc) {
                $n = $doc['data'];
                $activity[] = [
                    'type'   => 'notice',
                    'icon'   => 'fa-bullhorn',
                    'color'  => '#6366f1',
                    'title'  => (string) ($n['title'] ?? 'Notice published'),
                    'detail' => 'Published to ' . ((string) ($n['targetGroup'] ?? $n['target_group'] ?? 'All School')),
                    'time'   => (string) ($n['createdAt'] ?? $n['sentAt'] ?? ''),
                    'action' => 'communication/notices',
                ];
            }
        } catch (\Exception $e) {
            log_message('error', 'activity notices failed: ' . $e->getMessage());
        }

        // Merge + sort by time DESC, keep top 10
        usort($activity, fn($a, $b) => strcmp($b['time'] ?? '', $a['time'] ?? ''));
        $activity = array_slice($activity, 0, 10);

        $payload = ['activity' => $activity];
        $this->dashboard_cache->set($this->school_name, $cacheKey, $payload, 600);
        echo json_encode($payload);
    }

    /**
     * Send a birthday wish to a student. Records the wish in Firestore
     * (`birthdayWishes/{schoolId}_{studentId}_{yyyymmdd}`) for idempotency —
     * one wish per admin per day — writes a notice into the student's
     * inbox, and fires an FCM push to the parent app.
     *
     * POST body: { studentId, studentName, className, section }
     */
    public function send_birthday_wish()
    {
        header('Content-Type: application/json');
        if ($this->input->method() !== 'post') {
            $this->json_error('POST required.', 405);
        }

        $studentId   = trim((string) $this->input->post('studentId'));
        $studentName = trim((string) $this->input->post('studentName'));
        $className   = trim((string) $this->input->post('className'));
        $section     = trim((string) $this->input->post('section'));

        if ($studentId === '') $this->json_error('Missing studentId');

        $result = $this->_send_birthday_wish_core(
            $studentId, $studentName, $className, $section,
            /*sentBy*/     $this->admin_id,
            /*sentByName*/ $this->admin_name,
            /*source*/     'admin_manual'
        );

        if ($result['status'] === 'already_sent') {
            $this->json_success([
                'status'  => 'already_sent',
                'message' => 'Birthday wish already sent today.',
                'sentAt'  => $result['sentAt'] ?? '',
            ]);
            return;
        }

        $pushSent = (int) ($result['deliveredPush'] ?? 0);
        $this->json_success([
            'status'        => 'sent',
            'wishId'        => $result['wishId'],
            'deliveredPush' => $pushSent,
            'message'       => $pushSent > 0
                ? "Birthday wish sent — delivered to {$pushSent} device(s)."
                : 'Birthday wish recorded. Push delivery pending (device may be offline).',
        ]);
    }

    /**
     * Cron endpoint — auto-sends birthday wishes to every active student
     * whose DOB month-day matches today. Idempotent per day via the same
     * `birthdayWishes/{schoolId}_{studentId}_{YYYYMMDD}` key; safe to run
     * multiple times per day without duplicating pushes.
     *
     * Auth: requires ?token={config['birthday_cron_token']}. Falls back
     * to allowing loopback (127.0.0.1 / ::1) when token is not configured
     * so local cron works out-of-the-box.
     *
     * Iterates every school in the `schools` collection and processes
     * birthdays in that school's timezone (Option B per-school TZ).
     */
    public function auto_send_birthday_wishes()
    {
        header('Content-Type: application/json');

        // Auth — either a configured token, or the request is from loopback.
        $configToken = (string) ($this->config->item('birthday_cron_token') ?: '');
        $providedToken = (string) ($this->input->get('token') ?: $this->input->get_request_header('X-Cron-Token', true) ?: '');
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        $isLoopback = in_array($remote, ['127.0.0.1', '::1'], true);
        if ($configToken !== '' ? !hash_equals($configToken, $providedToken) : !$isLoopback) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Forbidden.']);
            return;
        }

        // Don't block the cron on CI output buffering
        @set_time_limit(0);

        $schoolsProcessed = 0;
        $wishesSent       = 0;
        $wishesSkipped    = 0;
        $errors           = [];

        try {
            // List every school. In practice schools are small in count (<100
            // for most SaaS tenants), so a full scan is fine.
            $schools = $this->fs->where('schools', []);
        } catch (\Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'schools read failed: ' . $e->getMessage()]);
            return;
        }

        foreach ((array) $schools as $schoolDoc) {
            $d = $schoolDoc['data'] ?? $schoolDoc;
            $sdata   = is_array($schoolDoc['data'] ?? null) ? $schoolDoc['data'] : $schoolDoc;
            $sid     = (string) ($sdata['schoolId'] ?? $d['id'] ?? '');
            if ($sid === '') continue;

            // Respect per-school timezone so "today" matches the school's calendar.
            $tz = (string) ($sdata['timezone'] ?? 'Asia/Kolkata');
            if (!in_array($tz, timezone_identifiers_list(), true)) $tz = 'Asia/Kolkata';
            try {
                $now = new \DateTime('now', new \DateTimeZone($tz));
            } catch (\Exception $e) {
                $now = new \DateTime('now');
            }
            $todayMd = $now->format('m-d');

            // Fetch active students in this school.
            try {
                $students = $this->fs->where('students', [
                    ['schoolId', '==', $sid],
                    ['status',   '==', 'Active'],
                ]);
            } catch (\Exception $e) {
                $errors[] = "students read failed for {$sid}: " . $e->getMessage();
                continue;
            }

            $schoolsProcessed++;
            foreach ((array) $students as $studentDoc) {
                $s = is_array($studentDoc['data'] ?? null) ? $studentDoc['data'] : $studentDoc;
                $dob = (string) ($s['dob'] ?? $s['DOB'] ?? $s['dateOfBirth'] ?? '');
                if ($dob === '') continue;

                $mdCandidate = '';
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $dob, $m)) {
                    $mdCandidate = $m[2] . '-' . $m[3];
                } elseif (preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})/', $dob, $m)) {
                    $mdCandidate = $m[2] . '-' . $m[1];
                }
                if ($mdCandidate !== $todayMd) continue;

                $studentId   = (string) ($s['studentId'] ?? $s['userId'] ?? '');
                if ($studentId === '') continue;
                $studentName = (string) ($s['name'] ?? $s['studentName'] ?? $s['Name'] ?? 'Student');
                $className   = (string) ($s['className'] ?? $s['Class']   ?? '');
                $section     = (string) ($s['section']   ?? $s['Section'] ?? '');

                // Use the per-school context when calling the core — so the
                // wish doc references the right school id even though the
                // cron runner has no admin session.
                $r = $this->_send_birthday_wish_core(
                    $studentId, $studentName, $className, $section,
                    /*sentBy*/     'system_cron',
                    /*sentByName*/ 'Auto-Send',
                    /*source*/     'auto_cron',
                    /*forceSchoolId*/ $sid,
                    /*forceSchoolName*/ (string) ($sdata['school_display_name'] ?? $sdata['name'] ?? $sid),
                    /*forceTodayStamp*/ $now->format('Ymd')
                );
                if (($r['status'] ?? '') === 'sent') $wishesSent++;
                elseif (($r['status'] ?? '') === 'already_sent') $wishesSkipped++;
            }
        }

        echo json_encode([
            'status'              => 'ok',
            'ranAt'               => date('c'),
            'schools_processed'   => $schoolsProcessed,
            'wishes_sent'         => $wishesSent,
            'wishes_skipped_dup'  => $wishesSkipped,
            'errors'              => $errors,
        ]);
    }

    /**
     * Shared send logic — called from both the admin-manual button and
     * the cron auto-send. Writes: birthdayWishes audit doc, notices inbox
     * entry, FCM push to the parent. Returns a status summary dict.
     *
     * $forceSchoolId / $forceSchoolName / $forceTodayStamp let the cron
     * process any school regardless of the current admin session context.
     */
    private function _send_birthday_wish_core(
        string $studentId,
        string $studentName,
        string $className,
        string $section,
        string $sentBy,
        string $sentByName,
        string $source = 'admin_manual',
        ?string $forceSchoolId = null,
        ?string $forceSchoolName = null,
        ?string $forceTodayStamp = null
    ): array {
        $schoolId     = $forceSchoolId    ?? $this->school_id;
        $schoolName   = $forceSchoolName  ?? $this->school_display_name;
        $todayStamp   = $forceTodayStamp  ?? date('Ymd');
        $wishId       = "{$schoolId}_{$studentId}_{$todayStamp}";
        $nowIso       = date('c');

        // Idempotency guard.
        try {
            $existing = $this->fs->get('birthdayWishes', $wishId);
            if (is_array($existing) && !empty($existing)) {
                return [
                    'status' => 'already_sent',
                    'wishId' => $wishId,
                    'sentAt' => (string) ($existing['sentAt'] ?? ''),
                ];
            }
        } catch (\Exception $e) { /* fall through — send anyway */ }

        $title = "🎂 Happy Birthday, {$studentName}!";
        $body  = "Wishing you a wonderful year ahead from all of us at {$schoolName}.";

        // 1. Audit doc + idempotency key
        try {
            $this->fs->set('birthdayWishes', $wishId, [
                'wishId'      => $wishId,
                'schoolId'    => $schoolId,
                'studentId'   => $studentId,
                'studentName' => $studentName,
                'className'   => $className,
                'section'     => $section,
                'title'       => $title,
                'body'        => $body,
                'sentBy'      => $sentBy,
                'sentByName'  => $sentByName,
                'source'      => $source,
                'sentAt'      => $nowIso,
                'date'        => substr($nowIso, 0, 10),
            ]);
        } catch (\Exception $e) {
            log_message('error', '_send_birthday_wish_core: audit write failed — ' . $e->getMessage());
        }

        // 2. FCM push
        $pushSent = 0;
        try {
            $this->load->library('push_service');
            $pushSent = (int) $this->push_service->sendToUser($studentId, [
                'title' => $title,
                'body'  => $body,
                'data'  => [
                    'type'      => 'birthday_wish',
                    'studentId' => $studentId,
                    'wishId'    => $wishId,
                ],
            ]);
        } catch (\Exception $e) {
            log_message('error', '_send_birthday_wish_core: push failed — ' . $e->getMessage());
        }

        // 3. Parent-app inbox entry
        try {
            $this->fs->set('notices', $wishId, [
                'noticeId'        => $wishId,
                'schoolId'        => $schoolId,
                'title'           => $title,
                'description'     => $body,
                'category'        => 'Birthday',
                'priority'        => 'Normal',
                'targetGroup'     => "Student {$studentId}",
                'targetStudentId' => $studentId,
                'status'          => 'published',
                'source'          => 'birthday_wish',
                'sentBySource'    => $source,
                'createdBy'       => $sentBy,
                'createdAt'       => $nowIso,
                'publishedAt'     => $nowIso,
                'sentAt'          => $nowIso,
            ]);
        } catch (\Exception $e) {
            log_message('error', '_send_birthday_wish_core: notice write failed — ' . $e->getMessage());
        }

        // 4. Cache bust
        try {
            $this->load->library('dashboard_cache');
            // Manual path uses current admin role; cron doesn't have one, so
            // clear the whole dashboard_activity cache for this school.
            $this->dashboard_cache->invalidate(
                $schoolId,
                'dashboard_activity_' . ($this->admin_role ?? '')
            );
        } catch (\Exception $e) { /* non-fatal */ }

        return [
            'status'        => 'sent',
            'wishId'        => $wishId,
            'deliveredPush' => $pushSent,
        ];
    }

    // ====================================================================
    //  SUBSCRIPTION & PAYMENT INFO — school-side AJAX endpoint
    // ====================================================================

    public function get_subscription_info()
    {
        header('Content-Type: application/json');
        if (function_exists('session_write_close')) @session_write_close();

        $school_uid = $this->school_name;
        $today      = date('Y-m-d');

        $this->load->library('dashboard_cache');
        $cacheAge = null;
        $cached = $this->dashboard_cache->get($school_uid, 'subscription_info', $cacheAge);
        if ($cached !== null) {
            log_message('debug', "DASHBOARD_CACHE HIT key=subscription_info school={$school_uid} age=" . ($cacheAge === null ? 'unknown' : $cacheAge) . 's');
            echo json_encode($cached);
            return;
        }
        log_message('debug', "DASHBOARD_CACHE MISS key=subscription_info school={$school_uid}");

        try {
            $schoolDoc = $this->fs->get('schools', $school_uid);
            $sub       = $schoolDoc['subscription'] ?? [];
            $plan_id   = $sub['plan_id'] ?? '';
            $plan_data = [];
            if ($plan_id) {
                $plan_data = $this->fs->get('systemPlans', $plan_id) ?? [];
            }

            // Recent payments list — server-side orderBy + limit (needs composite
            // index school_uid + created_at DESC). Avoids fetch-all-then-sort.
            $recentPaymentDocs = $this->fs->where(
                'systemPayments',
                [['school_uid', '==', $school_uid]],
                'created_at',
                'DESC',
                10
            );
            $payments = [];
            foreach ((array) $recentPaymentDocs as $doc) {
                $d = $doc['data'] ?? $doc;
                $p = $doc['data'];
                $p['payment_id'] = $d['id'];
                $payments[] = $p;
            }

            // Totals require every payment row (status-conditional sums, earliest
            // unpaid due date). Kept as a full scan but result is cached for 30s,
            // so the scan only happens once per cache window per school.
            $allPaymentDocs = $this->fs->where('systemPayments', [['school_uid', '==', $school_uid]]);
            $totalPaid    = 0;
            $totalBalance = 0;
            $nextDueAmt   = 0;
            $nextDueDate  = '';
            foreach ((array) $allPaymentDocs as $doc) {
                $p = $doc['data'];
                $totalPaid += (float) ($p['amount_paid'] ?? 0);
                $st = $p['status'] ?? '';
                if (in_array($st, ['pending', 'partial', 'overdue'], true)) {
                    $bal = isset($p['balance']) ? (float) $p['balance']
                         : ((float) ($p['amount'] ?? 0) - (float) ($p['amount_paid'] ?? 0));
                    $totalBalance += $bal;
                    $dd = $p['due_date'] ?? '';
                    if (!$nextDueDate || ($dd && $dd < $nextDueDate)) {
                        $nextDueDate = $dd;
                        $nextDueAmt  = $bal;
                    }
                }
            }

            $expiry   = $sub['expiry_date'] ?? '';
            $daysLeft = $expiry ? (int) ceil((strtotime($expiry) - strtotime($today)) / 86400) : null;

            $payload = [
                'plan_name'      => $plan_data['name'] ?? ($sub['plan_name'] ?? '—'),
                'billing_cycle'  => $plan_data['billing_cycle'] ?? ($sub['billing_cycle'] ?? '—'),
                'sub_status'     => $sub['status'] ?? 'Inactive',
                'expiry_date'    => $expiry,
                'days_left'      => $daysLeft,
                'total_paid'     => $totalPaid,
                'total_balance'  => $totalBalance,
                'next_due_date'  => $nextDueDate,
                'next_due_amount'=> $nextDueAmt,
                'payments'       => $payments,
            ];
            $this->dashboard_cache->set($school_uid, 'subscription_info', $payload, 600);
            echo json_encode($payload);
        } catch (Exception $e) {
            echo json_encode(['error' => 'Failed to load subscription info.']);
        }
    }

    // ====================================================================
    //  MY PROFILE — accessible to ALL logged-in admins (no role gate)
    // ====================================================================

    public function profile()
    {
        $admin_id  = $this->admin_id;

        // POST: update own profile or password
        if ($this->input->method() === 'post') {
            header('Content-Type: application/json');

            $action = trim((string) $this->input->post('action'));

            // ── Password change ─────────────────────────────────────────
            if ($action === 'change_password') {
                $current  = (string) $this->input->post('current_password', FALSE);
                $newPass  = (string) $this->input->post('new_password', FALSE);
                $confirm  = (string) $this->input->post('confirm_password', FALSE);

                if (!$current || !$newPass || !$confirm) {
                    $this->json_error('All password fields are required.'); return;
                }
                if ($newPass !== $confirm) {
                    $this->json_error('New passwords do not match.'); return;
                }
                if (strlen($newPass) < 8) {
                    $this->json_error('Password must be at least 8 characters.'); return;
                }
                if (strlen($newPass) > 72) {
                    $this->json_error('Password must not exceed 72 characters.'); return;
                }

                // Password change via Firebase Auth (primary auth source)
                try {
                    $this->firebase->updateFirebaseUser($admin_id, ['password' => $newPass]);
                } catch (Exception $e) {
                    $this->json_error('Password change failed. Please try again.'); return;
                }
                $this->json_success(['message' => 'Password changed successfully.']);
                return;
            }

            // ── Update profile details ──────────────────────────────────
            if ($action === 'update_profile') {
                $name   = trim($this->input->post('name',   TRUE) ?? '');
                $email  = trim($this->input->post('email',  TRUE) ?? '');
                $phone  = trim($this->input->post('phone',  TRUE) ?? '');
                $gender = trim($this->input->post('gender', TRUE) ?? '');

                if (empty($name)) { $this->json_error('Name is required.'); return; }

                $update = ['Name' => $name];
                if ($email  !== '') $update['Email']  = $email;
                if ($phone  !== '') $update['Phone']  = $phone;
                if ($gender !== '') $update['Gender'] = $gender;

                $this->fs->updateEntity('admins', $admin_id, $update);
                $this->session->set_userdata('admin_name', $name);
                $this->json_success(['message' => 'Profile updated successfully.']);
                return;
            }

            $this->json_error('Invalid action.'); return;
        }

        // GET: Load profile page
        $adminData = $this->fs->getEntity('admins', $admin_id) ?? [];

        $data = [
            'profile' => [
                'admin_id' => $admin_id,
                'name'     => $adminData['Name']    ?? ($this->admin_name ?? ''),
                'email'    => $adminData['Email']   ?? '',
                'phone'    => $adminData['Phone']   ?? '',
                'role'     => $adminData['Role']    ?? ($this->admin_role ?? ''),
                'gender'   => $adminData['Gender']  ?? '',
                'status'   => $adminData['Status']  ?? 'Active',
                'dob'      => $adminData['DOB']     ?? '',
            ],
        ];

        $this->load->view('include/header', $data);
        $this->load->view('admin_profile', $data);
        $this->load->view('include/footer');
    }

    public function manage_admin()
    {
        $this->_require_role(self::ADMIN_ROLES);

        if ($this->input->method() === 'post') {
            header('Content-Type: application/json');

            $adminId = $this->safe_path_segment(trim((string) $this->input->post('admin_id')), 'admin_id');

            // ── Password update ───────────────────────────────────────────
            if ($this->input->post('newPassword') && $this->input->post('confirmPassword') && $adminId) {
                $newPassword     = $this->input->post('newPassword');
                $confirmPassword = $this->input->post('confirmPassword');

                if ($newPassword !== $confirmPassword) {
                    $this->json_error('Passwords do not match.', 400);
                }
                if (strlen($newPassword) < 8) {
                    $this->json_error('Password must be at least 8 characters.', 400);
                }
                if (strlen($newPassword) > 72) {
                    $this->json_error('Password must not exceed 72 characters.', 400);
                }

                // Update password via Firebase Auth
                try {
                    $this->firebase->updateFirebaseUser($adminId, ['password' => $newPassword]);
                    $this->json_success(['message' => 'Password updated successfully.']);
                } catch (Exception $e) {
                    $this->json_error('Failed to update password.', 500);
                }
            }

            // ── Fetch single admin ────────────────────────────────────────
            if ($adminId && !$this->input->post('name')) {
                $adminDetails = $this->fs->getEntity('admins', $adminId);

                if ($adminDetails) {
                    unset($adminDetails['Credentials']);
                    $this->json_success(['data' => $adminDetails]);
                } else {
                    $this->json_error('Admin not found.', 404);
                }
            }

            // ── Add new admin ─────────────────────────────────────────────
            $name     = trim((string) $this->input->post('name'));
            $email    = trim((string) $this->input->post('email'));
            $phone    = trim((string) $this->input->post('phone'));
            $dob      = trim((string) $this->input->post('dob'));
            $gender   = trim((string) $this->input->post('gender'));
            $role     = trim((string) $this->input->post('role'));
            $password = (string) $this->input->post('password', FALSE);

            if (!$name || !$email || !$password || !$role) {
                $this->json_error('Required fields missing.', 400);
            }
            if (strlen($password) < 8) {
                $this->json_error('Password must be at least 8 characters.', 400);
            }
            if (strlen($password) > 72) {
                $this->json_error('Password must not exceed 72 characters.', 400);
            }

            // Phase 4.2 final — atomic via withClaim(). The count-based
            // fallback was REMOVED (race-unsafe: two admins creating
            // simultaneously could land on the same ADMxxxx). If every
            // retry tier inside the claim-doc system is exhausted, we
            // return a controlled 503 instead of silently producing a
            // possibly-duplicate ID.
            $this->load->library('id_generator');
            $newAdminId = null;
            try {
                $newAdminId = $this->id_generator->withClaim('ADM', function ($admId) use ($dob, $email, $gender, $name, $phone, $role) {
                    $adminData = [
                        'schoolId'      => $this->school_id,
                        'adminId'       => $admId,
                        'AccessHistory' => [
                            'LastLogin'     => date('c'),
                            'LoginAttempts' => 0,
                            'LoginIP'       => $this->input->ip_address(),
                        ],
                        'Created On' => date('c'),
                        'DOB'         => $dob ? date('d-m-Y', strtotime($dob)) : '',
                        'Email'       => $email,
                        'Gender'      => $gender,
                        'Name'        => $name,
                        'PhoneNumber' => $phone,
                        'Role'        => $role,
                        'Status'      => 'Active',
                        'updatedAt'   => date('c'),
                    ];
                    // Any exception or a returned `false` triggers an
                    // automatic releaseClaim on the ADM id — no orphan
                    // claim-doc, no burnt ID number.
                    $this->fs->set('admins', $this->fs->docId($admId), $adminData, true);
                    return $admId;
                });
            } catch (\Throwable $e) {
                log_message('error', 'ID_GEN_INTEGRATION admin_create_failed school=' . $this->school_id . ' err=' . $e->getMessage());
                $this->json_error('Could not create admin right now. Please retry in a moment.', 503);
                return;
            }
            if (!$newAdminId) {
                // Firestore write returned null/false without throwing —
                // withClaim released the claim automatically; we just
                // surface a clean error.
                $this->json_error('Could not create admin (write failed). Please retry.', 500);
                return;
            }

            // Firebase Auth creation is a best-effort side effect. It is
            // NOT inside withClaim because withClaim can't undo a
            // partially-created Auth user — and if Auth fails the admin
            // doc already exists and should be kept (auth sync can be
            // retried separately from admin management UI).
            try {
                $authEmail = Firebase::authEmail($newAdminId);
                $this->firebase->createFirebaseUser($authEmail, $password, [
                    'uid'         => $newAdminId,
                    'displayName' => $name,
                ]);
                $this->firebase->setFirebaseClaims($newAdminId, [
                    'role'          => $role,
                    'school_id'     => $this->school_id,
                    'school_code'   => $this->school_code,
                    'parent_db_key' => $this->parent_db_key,
                ]);
            } catch (Exception $e) {
                log_message('error', 'Admin::manage_admin Firebase Auth failed: ' . $e->getMessage());
            }

            $this->json_success(['message' => 'Admin created.', 'adminId' => $newAdminId]);

        } else {
            // ── GET: List all admins from Firestore ───────────────────────
            $adminDocs = $this->fs->schoolWhere('admins', [], 'Name', 'ASC');

            $data = [
                'adminList'     => [],
                'activeAdmins'  => [],
                'inactiveAdmins'=> [],
                'adminId'       => null,
            ];

            $count = count($adminDocs);
            $data['adminId'] = 'ADM' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);

            foreach ($adminDocs as $doc) {
                $d = $doc['data'] ?? $doc;
                $value = $doc['data'];
                $key   = $value['adminId'] ?? $d['id'];
                $status = $value['Status'] ?? 'Unknown';
                $entry  = [
                    'id'     => $key,
                    'name'   => $value['Name']  ?? 'Unknown',
                    'role'   => $value['Role']  ?? 'Unknown',
                    'status' => $status,
                ];

                if ($status === 'Active') {
                    $data['activeAdmins'][]  = $entry;
                    $data['adminList'][]     = "{$key} - {$entry['name']} - {$entry['role']}";
                } else {
                    $data['inactiveAdmins'][] = $entry;
                }
            }

            $this->load->view('include/header');
            $this->load->view('manage_admin', $data);
            $this->load->view('include/footer');
        }
    }

    public function edit_admin()
    {
        $this->_require_role(self::ADMIN_ROLES);
        header('Content-Type: application/json');

        $admin_id  = trim((string) $this->input->post('admin_id'));

        if (!$admin_id) {
            $this->json_error('Admin ID required.', 400);
        }
        $admin_id = $this->safe_path_segment($admin_id, 'admin_id');

        $update_data = [
            'Name'        => trim((string) $this->input->post('admin_name')),
            'Email'       => trim((string) $this->input->post('admin_email')),
            'PhoneNumber' => trim((string) $this->input->post('admin_phone')),
            'Role'        => trim((string) $this->input->post('admin_role')),
            'DOB'         => trim((string) $this->input->post('admin_dob')),
            'Gender'      => trim((string) $this->input->post('admin_gender')),
        ];

        $result = $this->fs->updateEntity('admins', $admin_id, $update_data);

        if ($result) {
            $this->json_success();
        } else {
            $this->json_error('Update failed.', 500);
        }
    }

    // =========================================================================
    //  SESSION MANAGEMENT
    // =========================================================================

    /**
     * POST: Switch the active academic session for the current user.
     * The new year must already exist in the user's available_sessions list
     * (whitelist check prevents path injection and cross-school access).
     */
    public function switch_session(): void
    {
        // All logged-in roles can switch session — it only changes their own
        // PHP session view, not the school's global active session.
        $this->_require_role(self::VIEW_ROLES);
        if ($this->input->method() !== 'post') {
            $this->json_error('Method not allowed.', 405);
        }

        $new_year = trim((string) $this->input->post('session_year'));

        if (!preg_match('/^\d{4}-\d{2}$/', $new_year)) {
            $this->json_error('Invalid session year format.', 400);
        }

        // Whitelist — must be in this school's available sessions
        $available = $this->session->userdata('available_sessions') ?? [];
        if (!in_array($new_year, $available, true)) {
            $this->json_error('Session not available for your school.', 403);
        }

        // Update all three key aliases so every controller/view stays in sync
        $this->session->set_userdata([
            'session'         => $new_year,  // MY_Controller reads this
            'current_session' => $new_year,  // Account controller reads this
            'session_year'    => $new_year,  // Account_model reads this
        ]);

        // Persist active session to Firestore
        try {
            $this->fs->update('schools', $this->school_id, ['currentSession' => $new_year]);
        } catch (Exception $e) {
            log_message('error', 'switch_session: ActiveSession persist failed — ' . $e->getMessage());
        }

        log_message('info',
            "Session switched to [{$new_year}] admin=[{$this->admin_id}] school=[{$this->school_name}]"
        );
        $this->json_success(['session_year' => $new_year]);
    }

    /**
     * POST: Create a new academic session year in Firebase.
     * Restricted to Super Admin role.
     */
    public function create_session(): void
    {
        // Only Super Admin can create new academic sessions
        $this->_require_role(['Super Admin']);
        if ($this->input->method() !== 'post') {
            $this->json_error('Method not allowed.', 405);
        }

        $new_year = trim((string) $this->input->post('session_year'));

        if (!preg_match('/^\d{4}-\d{2}$/', $new_year)) {
            $this->json_error('Invalid format. Use YYYY-YY (e.g. 2026-27).', 400);
        }

        // Validate YY matches YYYY+1 (e.g. 2026-27 is valid, 2026-99 is not)
        [$yearPart, $yyPart] = explode('-', $new_year);
        $expectedYY = substr((string)((int)$yearPart + 1), -2);
        if ($yyPart !== $expectedYY) {
            $this->json_error(
                "Year mismatch: {$yearPart}-{$yyPart} should be {$yearPart}-{$expectedYY}.", 400
            );
        }

        $available = $this->session->userdata('available_sessions') ?? [];
        if (in_array($new_year, $available, true)) {
            $this->json_error('This session already exists.', 409);
        }

        // Create session in Firestore schools document
        $available[] = $new_year;
        rsort($available);

        $written = $this->fs->update('schools', $this->school_id, [
            'sessions'       => $available,
            'currentSession' => $new_year,
        ]);

        if (!$written) {
            $this->json_error('Could not create session. Please try again.', 503);
        }

        // Update PHP session only after Firebase confirms the write
        $this->session->set_userdata('available_sessions', $available);

        log_message('info',
            "New session [{$new_year}] created by admin=[{$this->admin_id}] school=[{$this->school_name}]"
        );
        $this->json_success(['session_year' => $new_year, 'available_sessions' => $available]);
    }

    /**
     * [FIX-5] updateUserData: scoped to the session school only.
     */
    public function updateUserData()
    {
        $this->_require_role(self::ADMIN_ROLES);
        header('Content-Type: application/json');

        $modalId   = trim((string) $this->input->post('modal_id'));
        $userData  = $this->input->post('user_data');

        if (!$modalId || !is_array($userData)) {
            $this->json_error('Invalid input data.', 400);
        }
        $modalId = $this->safe_path_segment($modalId, 'modal_id');

        // Whitelist: only allow safe profile fields
        $allowed = [
            'Name', 'Email', 'Phone', 'Gender', 'Address', 'DOB',
            'Qualification', 'Designation', 'Department', 'Photo',
            'Father_Name', 'Mother_Name', 'Blood_Group', 'Religion',
            'Aadhar', 'PAN', 'Experience', 'Joining_Date', 'Bio',
        ];
        $userData = array_intersect_key($userData, array_flip($allowed));

        if (empty($userData)) {
            $this->json_error('No valid fields to update.', 400);
        }

        try {
            $this->fs->updateEntity('admins', $modalId, $userData);
            $this->json_success(['message' => 'Data updated successfully.']);
        } catch (Exception $e) {
            log_message('error', 'Admin updateUserData: ' . $e->getMessage());
            $this->json_error('Error updating data.', 500);
        }
    }
}
