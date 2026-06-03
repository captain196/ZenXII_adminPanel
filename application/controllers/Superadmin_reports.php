<?php
defined('BASEPATH') OR exit('No direct script access allowed');
require_once APPPATH . 'core/MY_Superadmin_Controller.php';

/**
 * Superadmin_reports
 * Global reports: students across all schools, revenue, system activity.
 */
class Superadmin_reports extends MY_Superadmin_Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * B2.3.4-E: single-source check for the b2.reports_firestore flag.
     * Cached per-request via static. Returns FALSE during build phase.
     * Independent of b2.registry_firestore — reports has its own dial so
     * a reports-specific bug can be rolled back without reverting the
     * live B2.3.2 surface.
     */
    private function _b234e_reports_firestore_on(): bool
    {
        static $cached = null;
        if ($cached === null) {
            $this->config->load('b2_migration_flags', FALSE, TRUE);
            $flags  = $this->config->item('b2_migration_flags') ?: [];
            $cached = !empty($flags['b2.reports_firestore']);
        }
        return $cached;
    }

    /**
     * B2.3.4-E helper: lazy-load + bind the B2_registry_service.
     */
    private function _b234e_registry()
    {
        $this->load->library('b2_registry_service');
        $this->b2_registry_service->init($this->firebase);
        return $this->b2_registry_service;
    }

    /**
     * B2.3.4-E helper: translate canonical Firestore lifecycle.state into
     * the legacy snake_case `status` string the existing report views
     * expect. Scope-limited to this controller; deleted with the legacy
     * branches at B2.3.3 retirement.
     */
    private function _b234e_lifecycle_to_status(string $state): string
    {
        static $map = [
            'active'        => 'active',
            'trialing'      => 'active',
            'expiring_soon' => 'active',
            'grace'         => 'grace_period',
            'past_due'      => 'inactive',
            'suspended'     => 'suspended',
            'expired'       => 'inactive',
        ];
        return $map[$state] ?? 'inactive';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET  /superadmin/reports
    // ─────────────────────────────────────────────────────────────────────────

    public function index()
    {
        $data = ['page_title' => 'Global Reports'];
        $this->load->view('superadmin/include/sa_header', $data);
        $this->load->view('superadmin/reports/index',     $data);
        $this->load->view('superadmin/include/sa_footer');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/reports/students
    // Returns per-school student counts from stats_cache
    // ─────────────────────────────────────────────────────────────────────────

    public function students_summary()
    {
        // ── B2.3.4-E: flag-gated single-source students summary ──────────
        if ($this->_b234e_reports_firestore_on()) {
            $svc      = $this->_b234e_registry();
            $tenants  = $svc->list_tenants_summary();
            $planById = [];
            foreach ($svc->list_plans() as $fs) {
                $pid = (string) ($fs['planFamilyId'] ?? '');
                if ($pid !== '') $planById[$pid] = (string) ($fs['name'] ?? $pid);
            }
            $rows  = [];
            $total = 0;
            foreach ($tenants as $t) {
                $count  = (int) $t['totalStudents'];
                $total += $count;
                $pfid     = (string) $t['planFamilyId'];
                $planName = $planById[$pfid] ?? '—';
                $rows[] = [
                    'uid'          => (string) $t['schoolId'],
                    'name'         => $t['schoolName'] !== '' ? $t['schoolName'] : (string) $t['schoolId'],
                    'city'         => (string) $t['city'],
                    'status'       => $this->_b234e_lifecycle_to_status((string) $t['lifecycleState']),
                    'plan_name'    => $planName,
                    'students'     => $count,
                    'staff'        => (int) $t['totalStaff'],
                    'last_updated' => (string) $t['statsLastUpdated'],
                ];
            }
            usort($rows, fn($a, $b) => $b['students'] - $a['students']);
            $this->json_success(['rows' => $rows, 'total' => $total]);
            return;
        }

        try {
            $schools = $this->firebase->get('System/Schools') ?? [];
            $rows    = [];
            $total   = 0;

            foreach ($schools as $name => $school) {
                if (!is_array($school)) continue;
                $sub   = is_array($school['subscription'] ?? null) ? $school['subscription'] : [];
                $cache = is_array($school['stats_cache']  ?? null) ? $school['stats_cache']  : [];
                $saP   = is_array($school['profile']      ?? null) ? $school['profile']      : [];

                $count  = (int)($cache['total_students'] ?? 0);
                $total += $count;
                $rows[] = [
                    'uid'         => $name,
                    'name'        => $saP['name']      ?? $name,
                    'city'        => $saP['city']       ?? '',
                    'status'      => strtolower($sub['status'] ?? 'inactive'),
                    'plan_name'   => $sub['plan_name']  ?? '—',
                    'students'    => $count,
                    'staff'       => (int)($cache['total_staff']    ?? 0),
                    'last_updated'=> $cache['last_updated'] ?? '',
                ];
            }

            usort($rows, fn($a, $b) => $b['students'] - $a['students']);
            $this->json_success(['rows' => $rows, 'total' => $total]);
        } catch (Exception $e) {
            $this->json_error('Failed to load student summary.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/reports/revenue
    // ─────────────────────────────────────────────────────────────────────────

    public function revenue_summary()
    {
        // ── B2.3.4-E: flag-gated single-source revenue summary ───────────
        if ($this->_b234e_reports_firestore_on()) {
            $svc      = $this->_b234e_registry();
            $tenants  = $svc->list_tenants_summary();
            $paid     = $svc->list_paid_payments();
            $planById = [];
            $planPriceById = [];
            foreach ($svc->list_plans() as $fs) {
                $pid = (string) ($fs['planFamilyId'] ?? '');
                if ($pid === '') continue;
                $planById[$pid]      = (string) ($fs['name']  ?? $pid);
                $planPriceById[$pid] = (float)  ($fs['price'] ?? 0);
            }
            $school_revenue = [];
            foreach ($paid as $p) {
                if (!is_array($p)) continue;
                $suid = (string) ($p['school_uid'] ?? '');
                if ($suid === '') continue;
                $school_revenue[$suid] = ($school_revenue[$suid] ?? 0) + (float) ($p['amount'] ?? 0);
            }
            $rows          = [];
            $total_revenue = 0.0;
            $active_count  = 0;
            foreach ($tenants as $t) {
                $sid     = (string) $t['schoolId'];
                $state   = (string) $t['lifecycleState'];
                $status  = $this->_b234e_lifecycle_to_status($state);
                if ($status === 'active') $active_count++;
                $revenue = (float) ($school_revenue[$sid] ?? 0);
                $total_revenue += $revenue;
                $pfid     = (string) $t['planFamilyId'];
                $planName = $planById[$pfid]      ?? '—';
                $price    = $planPriceById[$pfid] ?? 0;
                $rows[] = [
                    'uid'         => $sid,
                    'name'        => $t['schoolName'] !== '' ? $t['schoolName'] : $sid,
                    'plan_name'   => $planName,
                    'plan_price'  => $price,
                    'expiry_date' => (string) $t['subscriptionPeriodEnd'],
                    'sub_status'  => $status,
                    'revenue'     => $revenue,
                ];
            }
            usort($rows, fn($a, $b) => ($b['revenue'] <=> $a['revenue']));
            $this->json_success([
                'rows'           => $rows,
                'total_revenue'  => $total_revenue,
                'active_schools' => $active_count,
            ]);
            return;
        }

        try {
            $schools      = $this->firebase->get('System/Schools') ?? [];
            $payments_raw = $this->firebase->get('System/Payments') ?? [];
            $rows         = [];
            $total_revenue= 0.0;
            $active_count = 0;

            // Build per-school revenue from paid subscription payments
            $school_revenue = [];
            foreach ($payments_raw as $pid => $p) {
                if (!is_array($p)) continue;
                if (strtolower($p['status'] ?? '') !== 'paid') continue;
                $suid = $p['school_uid'] ?? $p['school_name'] ?? '';
                $school_revenue[$suid] = ($school_revenue[$suid] ?? 0) + (float)($p['amount'] ?? 0);
            }

            foreach ($schools as $name => $school) {
                if (!is_array($school)) continue;
                $sub    = is_array($school['subscription'] ?? null) ? $school['subscription'] : [];
                $saP    = is_array($school['profile']      ?? null) ? $school['profile']      : [];

                $status = strtolower($sub['status'] ?? 'inactive');
                if ($status === 'active') $active_count++;
                $revenue       = (float)($school_revenue[$name] ?? 0);
                $total_revenue += $revenue;
                $expiry = $sub['expiry_date'] ?? ($sub['duration']['endDate'] ?? '');

                $rows[] = [
                    'uid'         => $name,
                    'name'        => $saP['name']     ?? $name,
                    'plan_name'   => $sub['plan_name'] ?? '—',
                    'plan_price'  => $sub['plan_price'] ?? 0,
                    'expiry_date' => $expiry,
                    'sub_status'  => strtolower($sub['status'] ?? 'inactive'),
                    'revenue'     => $revenue,
                ];
            }

            usort($rows, fn($a, $b) => $b['revenue'] - $a['revenue']);
            $this->json_success([
                'rows'          => $rows,
                'total_revenue' => $total_revenue,
                'active_schools'=> $active_count,
            ]);
        } catch (Exception $e) {
            $this->json_error('Failed to load revenue summary.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/reports/activity
    // Returns activity logs for a given date range
    // ─────────────────────────────────────────────────────────────────────────

    public function activity_summary()
    {
        $date_from = trim($this->input->post('date_from', TRUE) ?? date('Y-m-d', strtotime('-7 days')));
        $date_to   = trim($this->input->post('date_to',   TRUE) ?? date('Y-m-d'));

        if (!$this->_valid_date($date_from) || !$this->_valid_date($date_to)) {
            $this->json_error('Invalid date range.');
            return;
        }

        // [FIX-8] Cap to 30 days max to prevent excessive Firebase reads (30 reads max)
        $from_ts = strtotime($date_from);
        $to_ts   = strtotime($date_to);
        if ($to_ts < $from_ts) { $this->json_error('date_from must be before date_to.'); return; }
        if (($to_ts - $from_ts) > (30 * 86400)) {
            $date_from = date('Y-m-d', $to_ts - (30 * 86400));
        }

        $rows        = [];
        $action_map  = [];
        $school_map  = [];

        // ── B2.3.4-E: flag-gated single-source activity summary ─────────
        // Scope: tenantAudit holds B2-canonical actions only. Legacy log
        // types (Login / Error / SchoolLogin / ApiUsage) live in their
        // own RTDB buckets and are retired by the separate B-MON wave.
        // Response gains `_meta.source = 'b2_audit'` so the operator can
        // distinguish current scope from the eventual B-MON merge.
        if ($this->_b234e_reports_firestore_on()) {
            $svc   = $this->_b234e_registry();
            $audit = $svc->list_recent_activity($date_from, $date_to, 1000);
            foreach ($audit as $a) {
                if (!is_array($a)) continue;
                $action = (string) ($a['action']   ?? 'unknown');
                $school = (string) ($a['schoolId'] ?? 'system');
                $ts     = (string) ($a['ts']       ?? '');
                $date   = substr($ts, 0, 10);
                $actor  = is_array($a['actor']    ?? null) ? $a['actor']    : [];
                $meta   = is_array($a['metadata'] ?? null) ? $a['metadata'] : [];
                // Flatten actor.uid → sa_user and actor.ip → ip so the view
                // can render the table without nested lookups. tenantAudit
                // doesn't store IP today (carry: B-MON wave will add it);
                // fall back to '—' so the column doesn't look broken.
                $rows[] = [
                    'log_id'     => (string) ($a['eventId'] ?? ''),
                    'date'       => $date,
                    'timestamp'  => $ts,
                    'action'     => $action,
                    'school_uid' => $school,
                    'sa_user'    => (string) ($actor['uid']  ?? '—'),
                    'sa_role'    => (string) ($actor['role'] ?? ''),
                    'ip'         => (string) ($actor['ip']   ?? '—'),
                    'reason'     => (string) ($meta['reason'] ?? ''),
                    'metadata'   => $meta,
                    'actor'      => $actor,
                ];
                $action_map[$action] = ($action_map[$action] ?? 0) + 1;
                $school_map[$school] = ($school_map[$school] ?? 0) + 1;
            }
            arsort($action_map);
            arsort($school_map);
            usort($rows, fn($a, $b) => strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? ''));
            $this->json_success([
                'rows'       => array_slice($rows, 0, 200),
                'total'      => count($rows),
                'action_map' => $action_map,
                'school_map' => $school_map,
                '_meta'      => ['source' => 'b2_audit'],
            ]);
            return;
        }

        $current = $date_from;
        try {
            while ($current <= $date_to) {
                $day_logs = $this->firebase->get("System/Logs/Activity/{$current}") ?? [];
                foreach ($day_logs as $lid => $log) {
                    $rows[] = array_merge(['log_id' => $lid, 'date' => $current], $log);
                    $action = $log['action'] ?? 'unknown';
                    $school = $log['school_uid'] ?? 'system';
                    $action_map[$action]  = ($action_map[$action]  ?? 0) + 1;
                    $school_map[$school]  = ($school_map[$school]  ?? 0) + 1;
                }
                $current = date('Y-m-d', strtotime($current . ' +1 day'));
            }

            arsort($action_map);
            arsort($school_map);

            // Most recent first
            usort($rows, fn($a, $b) => strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? ''));

            $this->json_success([
                'rows'       => array_slice($rows, 0, 200),
                'total'      => count($rows),
                'action_map' => $action_map,
                'school_map' => $school_map,
            ]);
        } catch (Exception $e) {
            $this->json_error('Failed to load activity logs.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/reports/plans_distribution
    // ─────────────────────────────────────────────────────────────────────────

    public function plans_distribution()
    {
        // ── B2.3.4-E: flag-gated single-source plans distribution ───────
        if ($this->_b234e_reports_firestore_on()) {
            $svc      = $this->_b234e_registry();
            $tenants  = $svc->list_tenants_summary();
            $planById = [];
            foreach ($svc->list_plans() as $fs) {
                $pid = (string) ($fs['planFamilyId'] ?? '');
                if ($pid !== '') $planById[$pid] = (string) ($fs['name'] ?? $pid);
            }
            $plan_map = [];
            foreach ($tenants as $t) {
                $pfid = (string) $t['planFamilyId'];
                $plan = $pfid !== '' ? ($planById[$pfid] ?? 'No Plan') : 'No Plan';
                $plan_map[$plan] = ($plan_map[$plan] ?? 0) + 1;
            }
            $rows = [];
            foreach ($plan_map as $plan => $count) {
                $rows[] = ['plan' => $plan, 'count' => $count];
            }
            usort($rows, fn($a, $b) => $b['count'] - $a['count']);
            $this->json_success(['rows' => $rows]);
            return;
        }

        try {
            $schools  = $this->firebase->get('System/Schools') ?? [];
            $plan_map = [];

            foreach ($schools as $name => $school) {
                if (!is_array($school)) continue;
                $sub  = is_array($school['subscription'] ?? null) ? $school['subscription'] : [];
                $plan = $sub['plan_name'] ?? 'No Plan';
                $plan_map[$plan] = ($plan_map[$plan] ?? 0) + 1;
            }

            $rows = [];
            foreach ($plan_map as $plan => $count) {
                $rows[] = ['plan' => $plan, 'count' => $count];
            }
            usort($rows, fn($a, $b) => $b['count'] - $a['count']);

            $this->json_success(['rows' => $rows]);
        } catch (Exception $e) {
            $this->json_error('Failed to load plan distribution.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function _valid_date(string $d): bool
    {
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        return $dt && $dt->format('Y-m-d') === $d;
    }
}
