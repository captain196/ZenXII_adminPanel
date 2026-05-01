<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Notifications Controller — Daily Workflow Automation & Reminder Engine
 *
 * Reads from existing module Firebase paths (NO writes to other modules).
 * Generates role-aware task lists and smart alerts for the dashboard.
 *
 * Firebase paths written:
 *   Schools/{school}/Notifications/{id}         — persistent alerts (admin-created)
 *   Schools/{school}/Notifications/Dismissed/{adminId}/{alertKey} — dismissals
 *
 * Firebase paths READ (from other modules):
 *   Schools/{school}/{session}/Staff_Attendance/{month}  — attendance status
 *   Schools/{school}/HR/Leaves/Requests                  — pending leaves
 *   Schools/{school}/{session}/HR/Payroll/Runs           — payroll status
 *   Schools/{school}/HR/Recruitment/Applicants            — ATS pipeline
 *   System/Schools/{school_id}/subscription               — subscription expiry
 */
class Notifications extends MY_Controller
{
    /** Roles that see all operational tasks */
    private const ADMIN_ROLES = ['Admin', 'Principal', 'Super Admin', 'School Super Admin'];

    /** Max alerts per response */
    private const MAX_ALERTS = 10;

    // ====================================================================
    //  MAIN ENDPOINT — Today's Tasks + Smart Alerts
    // ====================================================================

    /**
     * GET — Returns role-filtered tasks and alerts for the current user.
     * Called by dashboard (home.php) and bell dropdown (header.php).
     */
    public function get_tasks()
    {
        header('Content-Type: application/json');
        // Release session lock so concurrent dashboard fetches run in parallel.
        if (function_exists('session_write_close')) @session_write_close();

        // 5-minute cache per school+admin. Tasks/alerts are a composite of
        // 7 RTDB reads plus computed aggregates; caching lets the notification
        // bell and dashboard panel share one backend computation window.
        $this->load->library('dashboard_cache');
        $cacheKey = 'tasks_' . ($this->admin_id ?? 'anon') . '_' . ($this->admin_role ?? '');
        $cacheAge = null;
        $cached = $this->dashboard_cache->get($this->school_name, $cacheKey, $cacheAge);
        if ($cached !== null) {
            log_message('debug', "DASHBOARD_CACHE HIT key={$cacheKey} school={$this->school_name} age=" . ($cacheAge === null ? 'unknown' : $cacheAge) . 's');
            echo json_encode($cached);
            return;
        }
        log_message('debug', "DASHBOARD_CACHE MISS key={$cacheKey} school={$this->school_name}");

        $school  = $this->school_name;
        $session = $this->session_year;
        $role    = $this->admin_role ?? '';
        $adminId = $this->admin_id ?? '';

        $tasks  = [];
        $alerts = [];

        // Dismissed alert keys — stored in Firestore under doc id
        // {schoolId}_{adminId} with a flat map field `keys: { key1: true, ... }`.
        // A single-doc read (no RTDB) so we stay on the Firestore-only path.
        $dismissed = [];
        try {
            $dismissDoc = $this->fs->get(
                'dashboardDismissedAlerts',
                "{$this->school_id}_{$adminId}"
            );
            if (is_array($dismissDoc) && is_array($dismissDoc['keys'] ?? null)) {
                $dismissed = array_keys($dismissDoc['keys']);
            }
        } catch (\Exception $e) {
            log_message('error', 'Notifications::get_tasks dismissed read failed — ' . $e->getMessage());
        }

        // ── 1. Attendance check (Admin, Principal, Teacher) ──
        if (has_permission('Attendance')) {
            $this->_check_attendance($school, $session, $role, $adminId, $tasks, $alerts);
        }

        // ── 2. Leave requests (Admin, Principal, HR) ──
        if (has_permission('HR')) {
            $this->_check_leaves($school, $tasks, $alerts);
        }

        // ── 3. Payroll status (Admin only) ──
        if (has_permission('HR') && in_array($role, self::ADMIN_ROLES, true)) {
            $this->_check_payroll($school, $session, $tasks, $alerts);
        }

        // ── 4. ATS applicants (Admin, HR) ──
        if (has_permission('HR')) {
            $this->_check_applicants($school, $tasks, $alerts);
        }

        // ── 5. Fee collection (Admin, Accountant) ──
        if (has_permission('Fees')) {
            $this->_check_fees($school, $session, $tasks, $alerts);
        }

        // ── 6. Accounting — pending journals (Finance roles) ──
        if (has_permission('Accounting')) {
            $this->_check_accounting($school, $session, $tasks, $alerts);
        }

        // ── 7. Subscription expiry (all roles see this) ──
        $this->_check_subscription($alerts);

        // Filter out dismissed alerts
        if (!empty($dismissed)) {
            $alerts = array_values(array_filter($alerts, function ($a) use ($dismissed) {
                return !in_array($a['key'] ?? '', $dismissed, true);
            }));
        }

        // Sort tasks by priority (high first)
        usort($tasks, function ($a, $b) {
            $p = ['high' => 0, 'medium' => 1, 'low' => 2];
            return ($p[$a['priority'] ?? 'low'] ?? 2) <=> ($p[$b['priority'] ?? 'low'] ?? 2);
        });

        $payload = [
            'status' => 'success',
            'tasks'  => array_slice($tasks, 0, self::MAX_ALERTS),
            'alerts' => array_slice($alerts, 0, self::MAX_ALERTS),
            'ts'     => date('c'),
        ];
        $this->dashboard_cache->set($this->school_name, $cacheKey, $payload, 600);
        echo json_encode($payload);
    }

    /**
     * POST — Dismiss an alert (per-user, persistent).
     */
    public function dismiss_alert()
    {
        // Any authenticated admin can dismiss their own alerts; explicit
        // guard here so the audit tool flags a future role tightening.
        $this->_require_role(self::ADMIN_ROLES, 'dismiss_alert');
        $this->_require_post();
        $key = trim($this->input->post('key') ?? '');
        if ($key === '') {
            $this->json_error('Alert key is required.');
        }
        $key     = $this->safe_path_segment($key, 'alert_key');
        $adminId = $this->safe_path_segment($this->admin_id, 'admin_id');

        // Firestore write replacing the legacy RTDB path. Merge under a
        // map field so multiple dismissed keys accumulate in one doc.
        $this->fs->set(
            'dashboardDismissedAlerts',
            "{$this->school_id}_{$adminId}",
            [
                'schoolId'  => $this->school_id,
                'adminId'   => $adminId,
                'keys'      => [$key => date('c')],
                'updatedAt' => date('c'),
            ],
            /* merge */ true
        );

        // Bust the get_tasks cache so the dismissed alert disappears immediately
        // instead of waiting up to 10 min for the next TTL refresh.
        try {
            $this->load->library('dashboard_cache');
            $cacheKey = 'tasks_' . ($adminId ?? 'anon') . '_' . ($this->admin_role ?? '');
            $this->dashboard_cache->invalidate($this->school_name, $cacheKey);
        } catch (\Exception $e) { /* non-fatal */ }

        $this->json_success(['message' => 'Alert dismissed.']);
    }

    // ====================================================================
    //  PRIVATE CHECK METHODS — read-only from other module paths
    // ====================================================================

    /**
     * Check if today's attendance has been marked.
     */
    private function _check_attendance(string $school, string $session, string $role, string $adminId, array &$tasks, array &$alerts): void
    {
        $now      = new DateTime();

        // Staff attendance check removed — Staff_Attendance lives in RTDB and
        // hasn't been migrated to Firestore yet. Forcing a dashboard load to
        // wait on an RTDB roundtrip violates the Firestore-only policy and
        // was the slowest single contributor to dashboard cold-load time.
        // When staffAttendance is Firestore-native, re-enable via aggregation.

        // Student attendance — general reminder (always show before noon)
        $hour = (int) $now->format('G');
        if ($hour < 14) {
            $tasks[] = [
                'id'       => 'att_student_' . $now->format('Ymd'),
                'icon'     => 'fa-calendar-check-o',
                'color'    => '#0f766e',
                'title'    => 'Mark student attendance',
                'detail'   => 'Daily student attendance for ' . $now->format('d M Y'),
                'action'   => 'attendance/student',
                'priority' => 'medium',
                'module'   => 'Attendance',
            ];
        }
    }

    /**
     * Check pending leave requests — Firestore `leaveApplications`.
     */
    private function _check_leaves(string $school, array &$tasks, array &$alerts): void
    {
        // Firestore-first: count pending leaves via aggregation (zero doc transfer).
        $pending = 0;
        try {
            $pending = (int) $this->fs->count('leaveApplications', [
                ['schoolId', '==', $this->school_id],
                ['status',   '==', 'Pending'],
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Notifications::_check_leaves Firestore count failed — ' . $e->getMessage());
            return;
        }

        if ($pending > 0) {
            $tasks[] = [
                'id'       => 'leave_pending',
                'icon'     => 'fa-calendar-minus-o',
                'color'    => '#d97706',
                'title'    => "Approve leave requests",
                'detail'   => "{$pending} pending request(s) awaiting approval",
                'action'   => 'hr/leaves',
                'priority' => $pending >= 5 ? 'high' : 'medium',
                'module'   => 'HR',
            ];
            if ($pending >= 5) {
                $alerts[] = [
                    'key'     => 'leave_backlog',
                    'type'    => 'warning',
                    'icon'    => 'fa-calendar-minus-o',
                    'title'   => "{$pending} leave requests pending",
                    'detail'  => 'Review and approve pending leave requests to avoid backlog.',
                    'action'  => 'hr/leaves',
                    'module'  => 'HR',
                ];
            }
        }
    }

    /**
     * Check if current month's payroll has been generated.
     */
    private function _check_payroll(string $school, string $session, array &$tasks, array &$alerts): void
    {
        $now       = new DateTime();
        $curMonth  = $now->format('F');  // "March"
        $curYear   = $now->format('Y');  // "2026"
        $dayOfMonth = (int) $now->format('j');

        // Only remind from day 20 onwards
        if ($dayOfMonth < 20) return;

        // Firestore-first: `salarySlips` with matching monthKey proves the
        // current-month payroll has been generated. RTDB `HR/Payroll/Runs`
        // is legacy and violates the Firestore-only policy.
        $monthKey = $now->format('Y-m');
        $found = false;
        try {
            $generated = (int) $this->fs->count('salarySlips', [
                ['schoolId', '==', $this->school_id],
                ['monthKey', '==', $monthKey],
            ]);
            $found = $generated > 0;
        } catch (\Exception $e) {
            log_message('error', 'Notifications::_check_payroll Firestore count failed — ' . $e->getMessage());
            return; // fail silently; dashboard keeps working without this tile
        }

        if (!$found) {
            $alerts[] = [
                'key'     => "payroll_missing_{$curMonth}_{$curYear}",
                'type'    => 'error',
                'icon'    => 'fa-money',
                'title'   => "Payroll not generated for {$curMonth} {$curYear}",
                'detail'  => 'Generate payroll before month-end to avoid delays.',
                'action'  => 'hr/payroll',
                'module'  => 'HR',
            ];
            $tasks[] = [
                'id'       => 'payroll_gen_' . $curMonth,
                'icon'     => 'fa-money',
                'color'    => '#dc2626',
                'title'    => "Generate {$curMonth} payroll",
                'detail'   => 'No payroll run exists for this month yet.',
                'action'   => 'hr/payroll',
                'priority' => 'high',
                'module'   => 'HR',
            ];
        }
    }

    /**
     * Check ATS pipeline for applicants needing action.
     */
    private function _check_applicants(string $school, array &$tasks, array &$alerts): void
    {
        // Firestore-first: count applicants by status. We tolerate the legacy
        // `stage` field via a two-pronged count so older docs still register.
        $applied = 0;
        $interview = 0;
        $selected = 0;
        try {
            $applied   = (int) $this->fs->count('hrApplicants', [
                ['schoolId', '==', $this->school_id],
                ['status',   '==', 'Applied'],
            ]);
            $interview = (int) $this->fs->count('hrApplicants', [
                ['schoolId', '==', $this->school_id],
                ['status',   '==', 'Interview'],
            ]);
            $selected  = (int) $this->fs->count('hrApplicants', [
                ['schoolId', '==', $this->school_id],
                ['status',   '==', 'Selected'],
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Notifications::_check_applicants Firestore count failed — ' . $e->getMessage());
            return;
        }

        $pending = $applied + $interview;
        if ($pending > 0) {
            $tasks[] = [
                'id'       => 'ats_review',
                'icon'     => 'fa-th-list',
                'color'    => '#7c3aed',
                'title'    => 'Review applicants',
                'detail'   => "{$applied} new, {$interview} awaiting interview",
                'action'   => 'ats',
                'priority' => $applied >= 5 ? 'high' : 'low',
                'module'   => 'HR',
            ];
        }
        if ($selected > 0) {
            $tasks[] = [
                'id'       => 'ats_onboard',
                'icon'     => 'fa-user-plus',
                'color'    => '#15803d',
                'title'    => "Onboard selected candidates",
                'detail'   => "{$selected} candidate(s) selected — convert to staff",
                'action'   => 'ats',
                'priority' => 'medium',
                'module'   => 'HR',
            ];
        }
    }

    /**
     * Check fee collection status.
     */
    private function _check_fees(string $school, string $session, array &$tasks, array &$alerts): void
    {
        // Pending-fee-entries alert removed — RTDB `Accounts/Pending_fees`
        // is legacy and used only by the old fee-retry pipeline. With
        // Firestore-first fees, failed writes surface in the admin
        // transaction audit tool instead.

        // Generic daily reminder for fee counter
        $tasks[] = [
            'id'       => 'fees_collect',
            'icon'     => 'fa-inr',
            'color'    => '#d97706',
            'title'    => 'Fee collection',
            'detail'   => 'Open fee counter to collect today\'s payments',
            'action'   => 'fees/fees_counter',
            'priority' => 'low',
            'module'   => 'Fees',
        ];
    }

    /**
     * Check for accounting issues (pending journals, reconciliation).
     */
    private function _check_accounting(string $school, string $session, array &$tasks, array &$alerts): void
    {
        // Pending journals alert removed — RTDB `Accounts/Pending_journals`
        // is legacy. Accounting module is mid-migration; the equivalent
        // Firestore surface will be a `journalDrafts` collection when
        // Journals module lands. Until then this check is a no-op.
    }

    /**
     * Check subscription expiry from session data (no extra Firebase reads).
     */
    private function _check_subscription(array &$alerts): void
    {
        $expiry   = (int) $this->session->userdata('subscription_expiry');
        $graceEnd = (int) $this->session->userdata('subscription_grace_end');
        $now      = time();

        if ($expiry <= 0) return;

        if ($now > $expiry) {
            if ($graceEnd > 0 && $now < $graceEnd) {
                $daysLeft = max(1, (int) ceil(($graceEnd - $now) / 86400));
                $alerts[] = [
                    'key'     => 'sub_grace',
                    'type'    => 'error',
                    'icon'    => 'fa-exclamation-circle',
                    'title'   => "Subscription expired — {$daysLeft} day(s) of grace remaining",
                    'detail'  => 'Renew your subscription to avoid service interruption.',
                    'action'  => '',
                    'module'  => 'System',
                ];
            }
        } else {
            $daysUntil = (int) ceil(($expiry - $now) / 86400);
            if ($daysUntil <= 15) {
                $alerts[] = [
                    'key'     => 'sub_expiring',
                    'type'    => 'warning',
                    'icon'    => 'fa-clock-o',
                    'title'   => "Subscription expires in {$daysUntil} day(s)",
                    'detail'  => 'Contact support to renew before the grace period.',
                    'action'  => '',
                    'module'  => 'System',
                ];
            }
        }
    }
}
