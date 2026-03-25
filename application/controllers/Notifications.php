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
    private const MAX_ALERTS = 15;

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

        $school  = $this->school_name;
        $session = $this->session_year;
        $role    = $this->admin_role ?? '';
        $adminId = $this->admin_id ?? '';

        $tasks  = [];
        $alerts = [];

        // Load dismissed alert keys for this user (lightweight single read)
        $dismissedRaw = $this->firebase->get(
            "Schools/{$school}/Notifications/Dismissed/{$adminId}"
        );
        $dismissed = is_array($dismissedRaw) ? array_keys($dismissedRaw) : [];

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

        echo json_encode([
            'status' => 'success',
            'tasks'  => array_slice($tasks, 0, self::MAX_ALERTS),
            'alerts' => array_slice($alerts, 0, self::MAX_ALERTS),
            'ts'     => date('c'),
        ]);
    }

    /**
     * POST — Dismiss an alert (per-user, persistent).
     */
    public function dismiss_alert()
    {
        $key = trim($this->input->post('key') ?? '');
        if ($key === '') {
            $this->json_error('Alert key is required.');
        }
        $key     = $this->safe_path_segment($key, 'alert_key');
        $adminId = $this->safe_path_segment($this->admin_id, 'admin_id');

        $this->firebase->set(
            "Schools/{$this->school_name}/Notifications/Dismissed/{$adminId}/{$key}",
            date('c')
        );
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
        $month    = $now->format('F Y');   // "March 2026"
        $dayNum   = (int) $now->format('j'); // 1-based day of month

        // Staff attendance check (admin/principal only)
        if (in_array($role, self::ADMIN_ROLES, true)) {
            $staffAtt = $this->firebase->get(
                "Schools/{$school}/{$session}/Staff_Attendance/{$month}"
            );
            $staffMarked = 0;
            $staffTotal  = 0;
            if (is_array($staffAtt)) {
                foreach ($staffAtt as $sid => $attStr) {
                    if ($sid === 'Late' || !is_string($attStr)) continue;
                    $staffTotal++;
                    if (strlen($attStr) >= $dayNum) {
                        $ch = $attStr[$dayNum - 1];
                        if ($ch !== 'V' && $ch !== '') $staffMarked++;
                    }
                }
            }
            if ($staffTotal > 0 && $staffMarked < $staffTotal) {
                $missing = $staffTotal - $staffMarked;
                $tasks[] = [
                    'id'       => 'att_staff_' . $now->format('Ymd'),
                    'icon'     => 'fa-id-badge',
                    'color'    => '#d97706',
                    'title'    => 'Staff attendance incomplete',
                    'detail'   => "{$missing} of {$staffTotal} staff not marked today",
                    'action'   => 'attendance/staff',
                    'priority' => 'high',
                    'module'   => 'Attendance',
                ];
            }
        }

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
     * Check pending leave requests.
     */
    private function _check_leaves(string $school, array &$tasks, array &$alerts): void
    {
        $reqs = $this->firebase->get("Schools/{$school}/HR/Leaves/Requests");
        if (!is_array($reqs)) return;

        $pending = 0;
        foreach ($reqs as $rid => $lr) {
            if (($lr['status'] ?? '') === 'Pending') $pending++;
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

        $runs = $this->firebase->get("Schools/{$school}/{$session}/HR/Payroll/Runs");
        $found = false;
        if (is_array($runs)) {
            foreach ($runs as $rid => $run) {
                if (($run['month'] ?? '') === $curMonth && ($run['year'] ?? '') === $curYear) {
                    $found = true;
                    $status = $run['status'] ?? 'Draft';
                    // Payroll exists but not finalized
                    if ($status === 'Draft') {
                        $tasks[] = [
                            'id'       => 'payroll_finalize_' . $curMonth,
                            'icon'     => 'fa-lock',
                            'color'    => '#d97706',
                            'title'    => "Finalize {$curMonth} payroll",
                            'detail'   => "Payroll run ({$rid}) is in Draft. Finalize before month-end.",
                            'action'   => 'hr/payroll',
                            'priority' => $dayOfMonth >= 25 ? 'high' : 'medium',
                            'module'   => 'HR',
                        ];
                    }
                    break;
                }
            }
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
        $apps = $this->firebase->get("Schools/{$school}/HR/Recruitment/Applicants");
        if (!is_array($apps)) return;

        $applied = 0;
        $interview = 0;
        $selected = 0;
        foreach ($apps as $aid => $a) {
            $st = $a['status'] ?? $a['stage'] ?? '';
            if ($st === 'Applied')      $applied++;
            if ($st === 'Interview' || $st === 'Interviewed')  $interview++;
            if ($st === 'Selected')     $selected++;
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
        // Check for pending/failed fee entries
        $pending = $this->firebase->get("Schools/{$school}/{$session}/Accounts/Pending_fees");
        if (is_array($pending) && count($pending) > 0) {
            $cnt = count($pending);
            $alerts[] = [
                'key'     => 'fees_pending_entries',
                'type'    => 'warning',
                'icon'    => 'fa-exclamation-triangle',
                'title'   => "{$cnt} pending fee entries need attention",
                'detail'  => 'Some fee collections may have failed to post. Review and retry.',
                'action'  => 'fees/fees_records',
                'module'  => 'Fees',
            ];
        }

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
        $pendingJ = $this->firebase->get("Schools/{$school}/{$session}/Accounts/Pending_journals");
        if (is_array($pendingJ) && count($pendingJ) > 0) {
            $cnt = count($pendingJ);
            $alerts[] = [
                'key'     => 'acct_pending_journals',
                'type'    => 'error',
                'icon'    => 'fa-calculator',
                'title'   => "{$cnt} journal entries pending",
                'detail'  => 'Failed journal entries need manual posting.',
                'action'  => 'accounting',
                'module'  => 'Accounting',
            ];
        }
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
