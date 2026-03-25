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
        $current     = $date_from;

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
