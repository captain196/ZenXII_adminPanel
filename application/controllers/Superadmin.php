<?php
defined('BASEPATH') OR exit('No direct script access allowed');
require_once APPPATH . 'core/MY_Superadmin_Controller.php';

/**
 * Superadmin — Global SaaS Dashboard controller
 * Primary data: Firestore schools/{schoolId} + schoolControl/{schoolId}
 * Subscriptions: Firestore subscriptions/{id}
 * Summary/charts: live-computed via B2_registry_service (no RTDB cache)
 */
class Superadmin extends MY_Superadmin_Controller
{
    public function __construct() { parent::__construct(); }

    // ─────────────────────────────────────────────────────────────────────────
    // GET  /superadmin/dashboard
    // ─────────────────────────────────────────────────────────────────────────

    public function dashboard()
    {
        $summary       = [];
        $expiry_alerts = [];

        try {
            // Live-compute from the canonical Firestore schools/schoolControl/
            // subscriptions surface. Cache skip is acceptable — tenant count is
            // small and Firestore queries are sub-second.
            $summary       = $this->_compute_summary_firestore();
            $expiry_alerts = $this->_expiry_alerts_firestore();
        } catch (Exception $e) {
            log_message('error', 'SA Dashboard: ' . $e->getMessage());
        }

        // Today's SA activity
        $recent_activity = [];
        try {
            $logs = $this->firebase->get('System/Logs/Activity/' . date('Y-m-d'));
            if (is_array($logs)) {
                $logs = array_values($logs);
                usort($logs, fn($a, $b) => strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? ''));
                $recent_activity = array_slice($logs, 0, 12);
            }
        } catch (Exception $e) { /* non-critical */ }

        // B2.3.4-A Phase 1B — augment Hub data with analytics service
        // payload. Tolerant of missing analytics service (initial bring-up
        // window) — falls back to pre-Phase-1B widgets if anything throws.
        $hub_analytics = [
            'kpi'             => null,
            'time_series'     => ['schools_growth' => [], 'revenue' => []],
            'recent_activity' => [],
            'top_schools'     => [],
            'expiring_soon'   => [],
            'alerts'          => [],
            'saved_searches'  => [],
            'mrr'             => 0,
            'active_subscriptions' => 0,
        ];
        try {
            $this->load->library('b2_analytics_service');
            $this->b2_analytics_service->init($this->firebase);
            $hub_analytics = $this->b2_analytics_service->get_hub_payload(
                (string) ($this->sa_id ?? '')
            );
            $hub_analytics['mrr'] = $hub_analytics['kpi']['mrr'] ?? 0;
            $hub_analytics['active_subscriptions'] = $hub_analytics['kpi']['active_subscriptions'] ?? 0;
        } catch (\Throwable $e) {
            log_message('error', 'SA Dashboard: analytics payload failed: ' . $e->getMessage());
        }

        $data = [
            'page_title'      => 'Super Admin Dashboard',
            'summary'         => $summary,
            'recent_activity' => $recent_activity,
            'expiry_alerts'   => array_slice($expiry_alerts, 0, 8),
            'hub'             => $hub_analytics,
            'sa_id'           => (string) ($this->sa_id ?? ''),
        ];

        $this->load->view('superadmin/include/sa_header', $data);
        $this->load->view('superadmin/dashboard',         $data);
        $this->load->view('superadmin/include/sa_footer');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // B2.3.4-A Phase 1B — Hub AJAX endpoints
    // ─────────────────────────────────────────────────────────────────────────

    // GET  /superadmin/dashboard/hub_data — full Hub payload refresh
    public function hub_data()
    {
        try {
            $this->load->library('b2_analytics_service');
            $this->b2_analytics_service->init($this->firebase);
            $this->json_success($this->b2_analytics_service->get_hub_payload(
                (string) ($this->sa_id ?? '')
            ));
        } catch (\Throwable $e) {
            log_message('error', 'SA hub_data: ' . $e->getMessage());
            $this->json_error('Hub data fetch failed: ' . $e->getMessage());
        }
    }

    // GET  /superadmin/dashboard/quick_search?q=...
    public function quick_search()
    {
        $q = trim((string) ($this->input->get('q', TRUE) ?? ''));
        if (mb_strlen($q) < 2) {
            $this->json_success(['rows' => []]); return;
        }
        try {
            $this->load->library('b2_analytics_service');
            $this->b2_analytics_service->init($this->firebase);
            $rows = $this->b2_analytics_service->quick_search($q, 8);
            $this->json_success(['rows' => $rows]);
        } catch (\Throwable $e) {
            log_message('error', 'SA quick_search: ' . $e->getMessage());
            $this->json_error('Quick search failed.');
        }
    }

    // POST /superadmin/dashboard/saved_search_save
    public function saved_search_save()
    {
        $name = trim((string) ($this->input->post('name', TRUE) ?? ''));
        $filtersJson = (string) ($this->input->post('filters', TRUE) ?? '{}');
        $sortJson = (string) ($this->input->post('sort', TRUE) ?? '{}');
        $filters = json_decode($filtersJson, true);
        $sort    = json_decode($sortJson, true);
        if ($name === '' || !is_array($filters)) {
            $this->json_error('name + filters required'); return;
        }
        try {
            $this->load->library('b2_analytics_service');
            $this->b2_analytics_service->init($this->firebase);
            $id = $this->b2_analytics_service->save_search(
                (string) ($this->sa_id ?? ''), $name, $filters, is_array($sort) ? $sort : []
            );
            $this->json_success(['id' => $id]);
        } catch (\Throwable $e) {
            log_message('error', 'SA saved_search_save: ' . $e->getMessage());
            $this->json_error('Save failed.');
        }
    }

    // POST /superadmin/dashboard/saved_search_delete
    public function saved_search_delete()
    {
        $slug = trim((string) ($this->input->post('slug', TRUE) ?? ''));
        if ($slug === '') { $this->json_error('slug required'); return; }
        try {
            $this->load->library('b2_analytics_service');
            $this->b2_analytics_service->init($this->firebase);
            $ok = $this->b2_analytics_service->delete_saved_search(
                (string) ($this->sa_id ?? ''), $slug
            );
            $this->json_success(['deleted' => $ok]);
        } catch (\Throwable $e) {
            log_message('error', 'SA saved_search_delete: ' . $e->getMessage());
            $this->json_error('Delete failed.');
        }
    }

    // POST /superadmin/dashboard/alert_dismiss
    public function alert_dismiss()
    {
        $alertId = trim((string) ($this->input->post('alert_id', TRUE) ?? ''));
        $reason  = trim((string) ($this->input->post('reason', TRUE) ?? 'manual'));
        if ($alertId === '') { $this->json_error('alert_id required'); return; }
        try {
            $this->load->library('b2_analytics_service');
            $this->b2_analytics_service->init($this->firebase);
            $ok = $this->b2_analytics_service->resolve_alert(
                $alertId, (string) ($this->sa_id ?? ''), $reason
            );
            $this->json_success(['resolved' => $ok]);
        } catch (\Throwable $e) {
            log_message('error', 'SA alert_dismiss: ' . $e->getMessage());
            $this->json_error('Dismiss failed.');
        }
    }

    // POST /superadmin/dashboard/alerts_regenerate
    public function alerts_regenerate()
    {
        try {
            $this->load->library('b2_analytics_service');
            $this->b2_analytics_service->init($this->firebase);
            $result = $this->b2_analytics_service->generate_alerts();
            $this->json_success($result);
        } catch (\Throwable $e) {
            log_message('error', 'SA alerts_regenerate: ' . $e->getMessage());
            $this->json_error('Regenerate failed.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/dashboard/refresh_stats
    // ─────────────────────────────────────────────────────────────────────────

    public function refresh_stats()
    {
        try {
            $summary = $this->_compute_summary_firestore();
            $this->sa_log('refresh_stats');
            $this->json_success($summary);
        } catch (Exception $e) {
            log_message('error', 'SA refresh_stats: ' . $e->getMessage());
            $this->json_error('Failed to refresh stats: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/dashboard/charts
    // AJAX: returns all chart data in one request
    // ─────────────────────────────────────────────────────────────────────────

    public function dashboard_charts()
    {
        try {
            $svc     = $this->_b23_registry();
            $tenants = $svc->list_tenants_summary();
            $thirty_ago = date('Y-m-d', strtotime('-30 days'));

            // planFamilyId → planName map (small N).
            $planMap = [];
            foreach ($svc->list_plans() as $pf) {
                $pid = (string) ($pf['planFamilyId'] ?? '');
                if ($pid !== '') $planMap[$pid] = (string) ($pf['name'] ?? $pid);
            }

            // createdAt is not on the summary row — build a schoolId → createdAt
            // map from the schools collection once (cheap, small N).
            $createdMap = [];
            try {
                foreach ((array) $this->firebase->firestoreQuery('schools', []) as $row) {
                    $d  = is_array($row['data'] ?? null) ? $row['data'] : (is_array($row) ? $row : []);
                    $id = (string) ($row['id'] ?? $d['schoolId'] ?? $d['__firestoreId'] ?? '');
                    if ($id !== '') $createdMap[$id] = (string) ($d['createdAt'] ?? '');
                }
            } catch (\Throwable $e) { /* best-effort */ }

            $status_counts = ['active' => 0, 'grace' => 0, 'expired' => 0, 'suspended' => 0, 'inactive' => 0];
            $plan_dist     = [];
            $top_schools   = [];
            $recent_regs   = [];

            foreach ($tenants as $t) {
                $sid = (string) ($t['schoolId'] ?? '');

                // Status distribution — disabled tenants count as inactive.
                $status = !empty($t['adminDisabled'])
                    ? 'inactive'
                    : strtolower((string) ($t['lifecycleState'] ?? 'inactive'));
                if (isset($status_counts[$status])) $status_counts[$status]++;
                else $status_counts['inactive']++;

                // Plan distribution
                $planFid   = (string) ($t['planFamilyId'] ?? '');
                $plan_name = $planMap[$planFid] ?? ($planFid !== '' ? $planFid : '— No Plan');
                $plan_dist[$plan_name] = ($plan_dist[$plan_name] ?? 0) + 1;

                // Top schools by students
                $students = (int) ($t['totalStudents'] ?? 0);
                if ($students > 0) {
                    $top_schools[] = ['name' => $t['schoolName'] ?? $sid, 'count' => $students];
                }

                // Recent registrations
                $created = $createdMap[$sid] ?? '';
                if ($created && substr($created, 0, 10) >= $thirty_ago) {
                    $recent_regs[] = [
                        'name'        => $t['schoolName'] ?? $sid,
                        'city'        => $t['city']       ?? '',
                        'plan_name'   => $plan_name,
                        'school_code' => $t['schoolCode'] ?? '',
                        'created_at'  => $created,
                        'status'      => $status,
                    ];
                }
            }

            // Sort top schools descending; keep top 8
            usort($top_schools, fn($a, $b) => $b['count'] - $a['count']);
            $top_schools = array_slice($top_schools, 0, 8);

            // Recent regs — newest first
            usort($recent_regs, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

            // Revenue by month — last 6 months, from canonical paid payments.
            $revenue_trend = [];
            for ($i = 5; $i >= 0; $i--) {
                $revenue_trend[date('Y-m', strtotime("-{$i} months"))] = 0.0;
            }
            foreach ($svc->list_paid_payments() as $p) {
                if (empty($p['paid_date'])) continue;
                $mk = substr((string) $p['paid_date'], 0, 7);
                if (isset($revenue_trend[$mk])) $revenue_trend[$mk] += (float) ($p['amount'] ?? 0);
            }

            $this->json_success([
                'status_counts'  => $status_counts,
                'plan_dist'      => $plan_dist,
                'plan_counts'    => $plan_dist,         // backward compat for dashboard.php
                'top_schools'    => array_values($top_schools),
                'school_students'=> array_values($top_schools), // backward compat
                'recent_regs'    => $recent_regs,
                'revenue_trend'  => $revenue_trend,
                'revenue_months' => $revenue_trend,     // backward compat
            ]);
        } catch (Exception $e) {
            log_message('error', 'SA dashboard_charts: ' . $e->getMessage());
            $this->json_error('Failed to load chart data.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/dashboard/search
    // Quick search across schools, plans, payments — partial match, case-insensitive
    // ─────────────────────────────────────────────────────────────────────────

    public function search()
    {
        $q = strtolower(trim($this->input->post('q', TRUE) ?? ''));
        if (strlen($q) < 2) {
            $this->json_success(['results' => []]); return;
        }

        $results = [];

        try {
            // Search schools — canonical Firestore tenant registry.
            $tenants = $this->_b23_registry()->list_tenants_summary();
            foreach ($tenants as $t) {
                $uid  = (string) ($t['schoolId']   ?? '');
                $name = (string) ($t['schoolName'] ?? $uid);
                $code = (string) ($t['schoolCode'] ?? '');
                $city = (string) ($t['city']       ?? '');

                if (stripos($name, $q) !== false || stripos($code, $q) !== false
                    || stripos($city, $q) !== false || stripos($uid, $q) !== false) {
                    $status = !empty($t['adminDisabled'])
                        ? 'inactive'
                        : strtolower((string) ($t['lifecycleState'] ?? 'inactive'));
                    $results[] = [
                        'type'   => 'school',
                        'icon'   => 'fa-building',
                        'title'  => $name,
                        'detail' => ($code ? "Code: {$code}" : '') . ($city ? " · {$city}" : ''),
                        'url'    => 'superadmin/schools/view/' . urlencode($uid),
                        'status' => $status,
                    ];
                }
                if (count($results) >= 15) break;
            }

            // Search plans
            if (count($results) < 15) {
                $plans = $this->firebase->get('System/Plans') ?? [];
                foreach ($plans as $pid => $plan) {
                    if (!is_array($plan)) continue;
                    $pname = $plan['name'] ?? $pid;
                    if (stripos($pname, $q) !== false || stripos($pid, $q) !== false) {
                        $results[] = [
                            'type'   => 'plan',
                            'icon'   => 'fa-tags',
                            'title'  => $pname,
                            'detail' => 'Plan · ' . ($plan['billing_cycle'] ?? '') . ' · ₹' . number_format((float)($plan['price'] ?? 0)),
                            'url'    => 'superadmin/plans',
                            'status' => '',
                        ];
                    }
                    if (count($results) >= 15) break;
                }
            }

            $this->json_success(['results' => $results, 'query' => $q]);
        } catch (Exception $e) {
            log_message('error', 'SA search: ' . $e->getMessage());
            $this->json_error('Search failed.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Canonical Firestore registry accessor + summary/expiry helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function _b23_registry()
    {
        $this->load->library('b2_registry_service');
        $this->b2_registry_service->init($this->firebase);
        return $this->b2_registry_service;
    }

    /**
     * Firestore-canonical dashboard summary — sources every value from the
     * B2 canonical surface (schools + schoolControl + subscriptions +
     * payments). Never reads or writes RTDB.
     */
    private function _compute_summary_firestore(): array
    {
        $svc     = $this->_b23_registry();
        $tenants = $svc->list_tenants_summary();

        $thirty_ago = date('Y-m-d', strtotime('-30 days'));
        $total_schools  = 0;
        $active_schools = 0;
        $total_students = 0;
        $total_staff    = 0;
        $recent_regs    = 0;

        foreach ($tenants as $t) {
            $total_schools++;
            if (strtolower((string) ($t['lifecycleState'] ?? '')) === 'active') $active_schools++;
            $total_students += (int) ($t['totalStudents'] ?? 0);
            $total_staff    += (int) ($t['totalStaff']    ?? 0);
        }

        // Recent registrations — list_tenants_summary doesn't return
        // createdAt; pull the schools collection directly for this single
        // aggregate. Cheap (one query, small N).
        try {
            $schoolsRaw = $this->firebase->firestoreQuery('schools', []);
            if (is_array($schoolsRaw)) {
                foreach ($schoolsRaw as $row) {
                    $data = is_array($row['data'] ?? null) ? $row['data'] : (is_array($row) ? $row : []);
                    $id   = (string) ($row['id'] ?? $data['__firestoreId'] ?? '');
                    if (!preg_match('/^SCH_[A-Z0-9]+$/', $id)) continue;
                    $created = (string) ($data['createdAt'] ?? '');
                    if ($created !== '' && substr($created, 0, 10) >= $thirty_ago) $recent_regs++;
                }
            }
        } catch (\Throwable $e) { /* best-effort */ }

        // Revenue — paid payments via canonical accessor.
        $total_revenue = 0.0;
        foreach ($svc->list_paid_payments() as $p) {
            $total_revenue += (float) ($p['amount'] ?? 0);
        }

        return [
            'total_schools'  => $total_schools,
            'active_schools' => $active_schools,
            'total_students' => $total_students,
            'total_staff'    => $total_staff,
            'total_revenue'  => $total_revenue,
            'recent_regs'    => $recent_regs,
            'last_refreshed' => date('Y-m-d\TH:i:sP'),
        ];
    }

    /**
     * Firestore-canonical expiry alerts (≤15 days remaining) — reads
     * subscription period-end via list_tenants_summary which already
     * resolves the subscriptionId pointer to the subscriptions doc.
     */
    private function _expiry_alerts_firestore(): array
    {
        $svc     = $this->_b23_registry();
        $tenants = $svc->list_tenants_summary();

        // Build planFamilyId → planName map once (small N).
        $planMap = [];
        foreach ($svc->list_plans() as $pf) {
            $pid = (string) ($pf['planFamilyId'] ?? '');
            if ($pid !== '') $planMap[$pid] = (string) ($pf['name'] ?? $pid);
        }

        $out = [];
        foreach ($tenants as $t) {
            $endDate = (string) ($t['subscriptionPeriodEnd'] ?? '');
            if ($endDate === '' || strtotime($endDate) === false) continue;
            $days = (int) ceil((strtotime($endDate) - time()) / 86400);
            if ($days < 0 || $days > 15) continue;
            $planFid = (string) ($t['planFamilyId'] ?? '');
            $out[] = [
                'uid'         => (string) ($t['schoolId'] ?? ''),
                'name'        => (string) ($t['schoolName'] ?? $t['schoolId'] ?? ''),
                'expiry_date' => $endDate,
                'days_left'   => $days,
                'plan_name'   => $planMap[$planFid] ?? ($planFid !== '' ? $planFid : '—'),
            ];
        }
        usort($out, fn($a, $b) => $a['days_left'] - $b['days_left']);
        return $out;
    }
}
