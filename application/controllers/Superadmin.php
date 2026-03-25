<?php
defined('BASEPATH') OR exit('No direct script access allowed');
require_once APPPATH . 'core/MY_Superadmin_Controller.php';

/**
 * Superadmin — Global SaaS Dashboard controller
 * Primary data: System/Schools/{name}/
 * SA metadata  : System/Schools/{name}/
 * Payments     : System/Payments/{id}/
 * Stats cache  : System/Stats/Summary
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
            $cached = $this->firebase->get('System/Stats/Summary');
            if (is_array($cached) && !empty($cached['total_schools'])) {
                $summary = $cached;
            } else {
                $summary = $this->_compute_summary();
                $this->firebase->set('System/Stats/Summary', $summary);
            }

            // Expiry alerts — always live (needs accurate timing, small payload)
            $schools = $this->firebase->get('System/Schools') ?? [];
            foreach ($schools as $name => $schoolData) {
                if (!is_array($schoolData)) continue;
                $sub     = is_array($schoolData['subscription'] ?? null) ? $schoolData['subscription'] : [];
                $saP     = is_array($schoolData['profile']     ?? null) ? $schoolData['profile']     : [];
                $endDate = $sub['expiry_date'] ?? ($sub['duration']['endDate'] ?? '');
                if ($endDate && strtotime($endDate) !== false) {
                    $days = (int)ceil((strtotime($endDate) - time()) / 86400);
                    if ($days >= 0 && $days <= 15) {
                        $expiry_alerts[] = [
                            'uid'         => $name,
                            'name'        => $saP['name']      ?? $name,
                            'expiry_date' => $endDate,
                            'days_left'   => $days,
                            'plan_name'   => $sub['plan_name'] ?? '—',
                        ];
                    }
                }
            }
            usort($expiry_alerts, fn($a, $b) => $a['days_left'] - $b['days_left']);
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

        $data = [
            'page_title'      => 'Super Admin Dashboard',
            'summary'         => $summary,
            'recent_activity' => $recent_activity,
            'expiry_alerts'   => array_slice($expiry_alerts, 0, 8),
        ];

        $this->load->view('superadmin/include/sa_header', $data);
        $this->load->view('superadmin/dashboard',         $data);
        $this->load->view('superadmin/include/sa_footer');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/dashboard/refresh_stats
    // ─────────────────────────────────────────────────────────────────────────

    public function refresh_stats()
    {
        try {
            $summary = $this->_compute_summary();
            $this->firebase->set('System/Stats/Summary', $summary);
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
            $schools    = $this->firebase->get('System/Schools') ?? [];
            $payments   = $this->firebase->get('System/Payments') ?? [];
            $thirty_ago = date('Y-m-d', strtotime('-30 days'));

            $status_counts = ['active' => 0, 'grace' => 0, 'expired' => 0, 'suspended' => 0, 'inactive' => 0];
            $plan_dist     = [];
            $top_schools   = [];
            $recent_regs   = [];

            foreach ($schools as $name => $school) {
                if (!is_array($school)) continue;

                $sub   = is_array($school['subscription'] ?? null) ? $school['subscription'] : [];
                $cache = is_array($school['stats_cache']  ?? null) ? $school['stats_cache']  : [];
                $saP   = is_array($school['profile']      ?? null) ? $school['profile']      : [];

                // Status distribution — stored lowercase in Firebase
                $status = strtolower($sub['status'] ?? 'inactive');
                if (isset($status_counts[$status])) $status_counts[$status]++;
                else $status_counts['inactive']++;

                // Plan distribution
                $plan_name = $sub['plan_name'] ?? '— No Plan';
                $plan_dist[$plan_name] = ($plan_dist[$plan_name] ?? 0) + 1;

                // Top schools by students
                $students = (int)($cache['total_students'] ?? 0);
                if ($students > 0) {
                    $top_schools[] = ['name' => $saP['name'] ?? $name, 'count' => $students];
                }

                // Recent registrations
                $created = $saP['created_at'] ?? '';
                if ($created && substr($created, 0, 10) >= $thirty_ago) {
                    $recent_regs[] = [
                        'name'        => $saP['name']        ?? $name,
                        'city'        => $saP['city']        ?? '',
                        'plan_name'   => $plan_name,
                        'school_code' => $saP['school_code'] ?? '',
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

            // Revenue by month — last 6 months
            $revenue_trend = [];
            for ($i = 5; $i >= 0; $i--) {
                $revenue_trend[date('Y-m', strtotime("-{$i} months"))] = 0.0;
            }
            foreach ($payments as $p) {
                if (!is_array($p) || ($p['status'] ?? '') !== 'paid' || empty($p['paid_date'])) continue;
                $mk = substr($p['paid_date'], 0, 7);
                if (isset($revenue_trend[$mk])) $revenue_trend[$mk] += (float)($p['amount'] ?? 0);
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
            // Search schools
            $schools = $this->firebase->get('System/Schools') ?? [];
            foreach ($schools as $uid => $school) {
                if (!is_array($school)) continue;
                $profile = is_array($school['profile'] ?? null) ? $school['profile'] : [];
                $name    = $profile['school_name'] ?? ($profile['name'] ?? $uid);
                $code    = $profile['school_code'] ?? '';
                $city    = $profile['city'] ?? '';

                if (stripos($name, $q) !== false || stripos($code, $q) !== false
                    || stripos($city, $q) !== false || stripos($uid, $q) !== false) {
                    $sub = is_array($school['subscription'] ?? null) ? $school['subscription'] : [];
                    $results[] = [
                        'type'   => 'school',
                        'icon'   => 'fa-building',
                        'title'  => $name,
                        'detail' => ($code ? "Code: {$code}" : '') . ($city ? " · {$city}" : ''),
                        'url'    => 'superadmin/schools/view/' . urlencode($uid),
                        'status' => $sub['status'] ?? 'inactive',
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
    // PRIVATE: Compute full summary from Firebase (writes to cache)
    // ─────────────────────────────────────────────────────────────────────────

    private function _compute_summary(): array
    {
        $schools    = $this->firebase->get('System/Schools') ?? [];
        $payments   = $this->firebase->get('System/Payments') ?? [];
        $thirty_ago = date('Y-m-d', strtotime('-30 days'));

        $total_schools  = 0;
        $active_schools = 0;
        $total_students = 0;
        $total_staff    = 0;
        $recent_regs    = 0;

        foreach ($schools as $name => $schoolData) {
            if (!is_array($schoolData)) continue;
            $total_schools++;

            $sub    = is_array($schoolData['subscription'] ?? null) ? $schoolData['subscription'] : [];
            $cache  = is_array($schoolData['stats_cache']  ?? null) ? $schoolData['stats_cache']  : [];
            $profile= is_array($schoolData['profile']      ?? null) ? $schoolData['profile']      : [];

            if (strtolower((string)($sub['status'] ?? '')) === 'active') $active_schools++;
            $total_students += (int)($cache['total_students'] ?? 0);
            $total_staff    += (int)($cache['total_staff']    ?? 0);

            $created = $profile['created_at'] ?? '';
            if ($created && substr($created, 0, 10) >= $thirty_ago) $recent_regs++;
        }

        // Revenue from paid payments only
        $total_revenue = 0.0;
        foreach ($payments as $p) {
            if (is_array($p) && ($p['status'] ?? '') === 'paid') {
                $total_revenue += (float)($p['amount'] ?? 0);
            }
        }

        return [
            'total_schools'  => $total_schools,
            'active_schools' => $active_schools,
            'total_students' => $total_students,
            'total_staff'    => $total_staff,
            'total_revenue'  => $total_revenue,
            'recent_regs'    => $recent_regs,
            'last_refreshed' => date('Y-m-d\TH:i:sP'), // ISO 8601 with local timezone offset for JS Date parsing
        ];
    }
}
