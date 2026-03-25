<?php
defined('BASEPATH') OR exit('No direct script access allowed');
require_once APPPATH . 'core/MY_Superadmin_Controller.php';

/**
 * Superadmin_monitor
 * System monitoring: login activity, API usage, error logs,
 * Firebase database usage, and server health indicators.
 *
 * Firebase paths used:
 *   System/Logs/Activity/{date}      — SA actions
 *   System/Logs/Logins/{date}        — SA logins
 *   System/Logs/SchoolLogins/{date}  — school admin logins
 *   System/Logs/Errors/{date}        — application errors
 *   System/Logs/ApiUsage/{date}      — tracked AJAX/API calls
 */
class Superadmin_monitor extends MY_Superadmin_Controller
{
    public function __construct() { parent::__construct(); }

    // ─────────────────────────────────────────────────────────────────────────
    // GET  /superadmin/monitor
    // ─────────────────────────────────────────────────────────────────────────

    public function index()
    {
        $today = date('Y-m-d');
        $login_count = $error_count = $activity_count = $api_count = $school_login_count = 0;

        try {
            $activity      = $this->firebase->get("System/Logs/Activity/{$today}")     ?? [];
            $errors        = $this->firebase->get("System/Logs/Errors/{$today}")       ?? [];
            $school_logins = $this->firebase->get("System/Logs/SchoolLogins/{$today}") ?? [];
            $api_logs      = $this->firebase->get("System/Logs/ApiUsage/{$today}")     ?? [];

            $activity_count      = count($activity);
            $error_count         = count($errors);
            $school_login_count  = count($school_logins);
            $api_count           = count($api_logs);
        } catch (Exception $e) { /* non-critical */ }

        $data = [
            'page_title'         => 'System Monitor',
            'today'              => $today,
            'login_count'        => $login_count,
            'error_count'        => $error_count,
            'activity_count'     => $activity_count,
            'api_count'          => $api_count,
            'school_login_count' => $school_login_count,
        ];

        $this->load->view('superadmin/include/sa_header', $data);
        $this->load->view('superadmin/monitor/index',     $data);
        $this->load->view('superadmin/include/sa_footer');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/monitor/logins
    // ─────────────────────────────────────────────────────────────────────────

    public function fetch_login_logs()
    {
        $date = trim($this->input->post('date', TRUE) ?? date('Y-m-d'));
        if (!$this->_valid_date($date)) { $this->json_error('Invalid date.'); return; }
        try {
            $raw  = $this->firebase->get("System/Logs/Logins/{$date}") ?? [];
            $rows = array_values($raw);
            usort($rows, fn($a, $b) => strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? ''));
            $this->json_success(['rows' => $rows, 'date' => $date, 'total' => count($rows)]);
        } catch (Exception $e) { $this->json_error('Failed to load login logs.'); }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/monitor/errors
    // ─────────────────────────────────────────────────────────────────────────

    public function fetch_error_logs()
    {
        $date = trim($this->input->post('date', TRUE) ?? date('Y-m-d'));
        if (!$this->_valid_date($date)) { $this->json_error('Invalid date.'); return; }
        try {
            $raw  = $this->firebase->get("System/Logs/Errors/{$date}") ?? [];
            $rows = array_values($raw);
            usort($rows, fn($a, $b) => strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? ''));
            $this->json_success(['rows' => $rows, 'date' => $date, 'total' => count($rows)]);
        } catch (Exception $e) { $this->json_error('Failed to load error logs.'); }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/monitor/activity
    // ─────────────────────────────────────────────────────────────────────────

    public function fetch_activity_logs()
    {
        $date = trim($this->input->post('date', TRUE) ?? date('Y-m-d'));
        if (!$this->_valid_date($date)) { $this->json_error('Invalid date.'); return; }
        try {
            $raw  = $this->firebase->get("System/Logs/Activity/{$date}") ?? [];
            $rows = array_values($raw);
            usort($rows, fn($a, $b) => strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? ''));
            $this->json_success(['rows' => $rows, 'logs' => $rows, 'date' => $date, 'total' => count($rows)]);
        } catch (Exception $e) { $this->json_error('Failed to load activity logs.'); }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/monitor/school_logins
    // ─────────────────────────────────────────────────────────────────────────

    public function fetch_school_logins()
    {
        $date       = trim($this->input->post('date',       TRUE) ?? date('Y-m-d'));
        $school_uid = trim($this->input->post('school_uid', TRUE) ?? '');
        if (!$this->_valid_date($date)) { $this->json_error('Invalid date.'); return; }
        try {
            $raw  = $this->firebase->get("System/Logs/SchoolLogins/{$date}") ?? [];
            $rows = array_values($raw);
            if ($school_uid) {
                $rows = array_values(array_filter($rows, fn($r) => ($r['school_uid'] ?? '') === $school_uid));
            }
            usort($rows, fn($a, $b) => strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? ''));
            $this->json_success(['rows' => $rows, 'total' => count($rows)]);
        } catch (Exception $e) { $this->json_error('Failed to load school login logs.'); }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/monitor/fetch_api_logs
    // ─────────────────────────────────────────────────────────────────────────

    public function fetch_api_logs()
    {
        $date = trim($this->input->post('date', TRUE) ?? date('Y-m-d'));
        if (!$this->_valid_date($date)) { $this->json_error('Invalid date.'); return; }
        try {
            $raw  = $this->firebase->get("System/Logs/ApiUsage/{$date}") ?? [];
            $rows = array_values($raw);
            usort($rows, fn($a, $b) => strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? ''));
            $this->json_success(['rows' => $rows, 'date' => $date, 'total' => count($rows)]);
        } catch (Exception $e) { $this->json_error('Failed to load API logs.'); }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/monitor/log_api_call
    // Called by SA-panel JS to record a tracked AJAX/API call
    // ─────────────────────────────────────────────────────────────────────────

    public function log_api_call()
    {
        $method      = strtoupper(trim($this->input->post('method',      TRUE) ?? 'POST'));
        $endpoint    = trim($this->input->post('endpoint',               TRUE) ?? '');
        $duration_ms = (int)($this->input->post('duration_ms',           TRUE) ?? 0);
        $status      = trim($this->input->post('status',                 TRUE) ?? 'success');

        if (empty($endpoint)) { $this->json_error('Endpoint required.'); return; }

        $allowed_methods = ['GET','POST','PUT','PATCH','DELETE'];
        $allowed_status  = ['success','error','timeout'];
        if (!in_array($method, $allowed_methods)) $method = 'POST';
        if (!in_array($status, $allowed_status))  $status = 'success';

        $entry = [
            'method'      => $method,
            'endpoint'    => substr($endpoint, 0, 250),
            'duration_ms' => $duration_ms,
            'status'      => $status,
            'sa_id'       => $this->sa_id,
            'sa_name'     => $this->sa_name,
            'ip'          => $this->input->ip_address(),
            'timestamp'   => date('Y-m-d H:i:s'),
        ];

        try {
            $date = date('Y-m-d');
            $key  = 'api_' . substr(md5(uniqid('', true)), 0, 8);
            $this->firebase->update("System/Logs/ApiUsage/{$date}", [$key => $entry]);

            // M-05 FIX: Audit log for API call tracking (skip sa_log for high-frequency
            // calls to avoid recursion — only log errors/timeouts)
            if ($status !== 'success') {
                $this->sa_log('api_call_logged', '', ['endpoint' => $endpoint, 'status' => $status]);
            }

            $this->json_success(['logged' => true]);
        } catch (Exception $e) {
            $this->json_error('Log failed.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/monitor/firebase_usage
    // Aggregate last 7 days of all log-type counts (Firebase reads = our ops)
    // ─────────────────────────────────────────────────────────────────────────

    public function firebase_usage()
    {
        $result = [];
        for ($i = 6; $i >= 0; $i--) {
            $d         = date('Y-m-d', strtotime("-{$i} days"));
            $result[$d] = ['date' => $d, 'activity' => 0, 'school_logins' => 0, 'errors' => 0, 'api_calls' => 0];
        }

        try {
            foreach (array_keys($result) as $d) {
                $result[$d]['activity']      = count($this->firebase->get("System/Logs/Activity/{$d}")     ?? []);
                $result[$d]['school_logins'] = count($this->firebase->get("System/Logs/SchoolLogins/{$d}") ?? []);
                $result[$d]['errors']        = count($this->firebase->get("System/Logs/Errors/{$d}")       ?? []);
                $result[$d]['api_calls']     = count($this->firebase->get("System/Logs/ApiUsage/{$d}")     ?? []);
            }
        } catch (Exception $e) { /* return partial */ }

        $this->json_success(['days' => array_values($result)]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/monitor/clear_logs
    // ─────────────────────────────────────────────────────────────────────────

    public function clear_logs()
    {
        if (!in_array($this->sa_role, ['superadmin', 'developer'], true)) {
            $this->json_error('Insufficient privileges.', 403); return;
        }
        $log_type = trim($this->input->post('log_type', TRUE) ?? '');
        $date     = trim($this->input->post('date',     TRUE) ?? '');

        $allowed_types = ['Logins', 'Errors', 'Activity', 'SchoolLogins', 'ApiUsage'];
        if (!in_array($log_type, $allowed_types) || !$this->_valid_date($date)) {
            $this->json_error('Invalid log type or date.'); return;
        }
        try {
            $this->firebase->delete("System/Logs/{$log_type}", $date);
            $this->sa_log("logs_cleared_{$log_type}", '', ['date' => $date]);
            $this->json_success(['message' => "{$log_type} logs for {$date} cleared."]);
        } catch (Exception $e) { $this->json_error('Failed to clear logs.'); }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/monitor/system_health
    // Comprehensive health check: Firebase, MySQL, memory, disk, CPU load
    // ─────────────────────────────────────────────────────────────────────────

    public function system_health()
    {
        $mem_used  = memory_get_usage(true);
        $mem_peak  = memory_get_peak_usage(true);
        $mem_lim   = ini_get('memory_limit');
        $lim_bytes = $this->_parse_memory($mem_lim);

        $disk_free  = disk_free_space('.');
        $disk_total = disk_total_space('.');
        $disk_used  = $disk_total - $disk_free;

        $health = [
            'php_version'     => PHP_VERSION,
            'ci_version'      => CI_VERSION,
            'server_time'     => date('Y-m-d H:i:s'),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'firebase_ok'     => false,
            'firebase_ms'     => 0,
            'mysql_ok'        => false,
            'disk_free_mb'    => round($disk_free  / 1048576, 1),
            'disk_total_mb'   => round($disk_total / 1048576, 1),
            'disk_used_mb'    => round($disk_used  / 1048576, 1),
            'disk_used_pct'   => $disk_total > 0 ? round($disk_used / $disk_total * 100) : 0,
            'memory_used_mb'  => round($mem_used / 1048576, 1),
            'memory_peak_mb'  => round($mem_peak / 1048576, 1),
            'memory_limit'    => $mem_lim,
            'memory_limit_mb' => $lim_bytes > 0 ? round($lim_bytes / 1048576) : 0,
            'memory_used_pct' => $lim_bytes > 0 ? round($mem_used / $lim_bytes * 100) : 0,
            'opcache_enabled' => function_exists('opcache_get_status') && !empty(opcache_get_status()),
            'load_avg'        => null,
        ];

        // CPU load averages (Linux/macOS only)
        if (function_exists('sys_getloadavg')) {
            $la = sys_getloadavg();
            if ($la) {
                $health['load_avg'] = ['1m' => round($la[0], 2), '5m' => round($la[1], 2), '15m' => round($la[2], 2)];
            }
        }

        // Firebase ping with response-time measurement
        try {
            $t0 = microtime(true);
            $this->firebase->get('System/Stats/Summary');
            $health['firebase_ms'] = round((microtime(true) - $t0) * 1000);
            $health['firebase_ok'] = true;
        } catch (Exception $e) {
            $health['firebase_error'] = $e->getMessage();
        }

        // MySQL connectivity (optional — SA panel is Firebase-only)
        $health['mysql_configured'] = false;
        try {
            if (!empty($this->db) && is_object($this->db)) {
                $this->db->query('SELECT 1');
                $health['mysql_ok']         = true;
                $health['mysql_configured'] = true;
            }
            // No DB configured is not an error for the SA panel
        } catch (Throwable $e) {
            $health['mysql_configured'] = true; // DB was configured but failed
            $health['mysql_error']      = $e->getMessage();
        }

        $this->json_success($health);
    }

    // ─────────────────────────────────────────────────────────────────────────

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/monitor/cleanup_old_logs
    // Auto-delete log entries older than N days (default 30) across all log types
    // Only superadmin and developer roles allowed
    // ─────────────────────────────────────────────────────────────────────────

    public function cleanup_old_logs()
    {
        if (!in_array($this->sa_role, ['superadmin', 'developer'], true)) {
            $this->json_error('Insufficient privileges.', 403); return;
        }

        $retain_days = max(1, (int)($this->input->post('retain_days', TRUE) ?? 30));
        $cutoff      = date('Y-m-d', strtotime("-{$retain_days} days"));

        $log_types = ['Logins', 'Errors', 'Activity', 'SchoolLogins', 'ApiUsage'];
        $deleted   = [];
        $errors    = [];

        foreach ($log_types as $type) {
            try {
                // Shallow read — only date keys, not full log entries
                $dates = $this->firebase->shallow_get("System/Logs/{$type}") ?? [];
                foreach (array_keys($dates) as $date) {
                    if (!$this->_valid_date($date)) continue;
                    if ($date < $cutoff) {
                        $this->firebase->delete("System/Logs/{$type}", $date);
                        $deleted[] = "{$type}/{$date}";
                    }
                }
            } catch (Exception $e) {
                $errors[] = "{$type}: " . $e->getMessage();
            }
        }

        $this->sa_log('logs_cleanup', '', [
            'retain_days' => $retain_days,
            'cutoff'      => $cutoff,
            'deleted'     => count($deleted),
        ]);

        $this->json_success([
            'deleted' => count($deleted),
            'entries' => $deleted,
            'errors'  => $errors,
            'message' => count($deleted) . ' log date(s) older than ' . $retain_days . ' days removed.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function _parse_memory(string $val): int
    {
        $val  = trim($val);
        $last = strtolower(substr($val, -1));
        $num  = (int)$val;
        if ($last === 'g') return $num * 1073741824;
        if ($last === 'm') return $num * 1048576;
        if ($last === 'k') return $num * 1024;
        return $num;
    }

    private function _valid_date(string $d): bool
    {
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        return $dt && $dt->format('Y-m-d') === $d;
    }
}
