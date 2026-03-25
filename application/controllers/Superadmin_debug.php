<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'core/MY_Superadmin_Controller.php';

/**
 * Superadmin_debug — SA Debug Testing Panel
 *
 * Routes:
 *   GET  superadmin/debug                   index()
 *   POST superadmin/debug/get_logs          get_logs()
 *   POST superadmin/debug/get_stats         get_stats()
 *   POST superadmin/debug/toggle_debug      toggle_debug()
 *   POST superadmin/debug/clear_debug_logs  clear_debug_logs()
 *   POST superadmin/debug/schema_check      schema_check()
 *   POST superadmin/debug/log_ajax_error    log_ajax_error()
 */
class Superadmin_debug extends MY_Superadmin_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('firebase');
        if (!class_exists('Debug_tracker', false)) {
            $this->load->library('Debug_tracker');
        }
    }

    // ─── GET: render panel ────────────────────────────────────────────────────

    public function index(): void
    {
        $dates = Debug_tracker::available_dates();
        $today = date('Y-m-d');
        $stats = Debug_tracker::compute_stats($today);

        $data = [
            'page_title'   => 'Debug Panel',
            'active_menu'  => 'debug',
            'debug_on'     => GRADER_DEBUG,
            'flag_file'    => APPPATH . 'logs/.debug_enabled',
            'log_dates'    => $dates,
            'today'        => $today,
            'stats'        => $stats,
            'slow_threshold'=> Debug_tracker::SLOW_MS,
        ];

        $this->load->view('superadmin/include/sa_header', $data);
        $this->load->view('superadmin/debug/index', $data);
        $this->load->view('superadmin/include/sa_footer');
    }

    // ─── POST: return parsed log entries for a given date + type filter ───────

    public function get_logs(): void
    {
        $date  = $this->input->post('date')  ?: date('Y-m-d');
        $types = $this->input->post('types') ?: [];          // array or empty = all

        // Sanitise date
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->json_error('Invalid date format.');
        }
        if (!is_array($types)) $types = [];

        $entries = Debug_tracker::read_log($date, $types);

        // Return newest-first
        $entries = array_reverse($entries);

        $this->json_success([
            'entries' => array_slice($entries, 0, 500), // cap at 500 for AJAX
            'total'   => count($entries),
            'date'    => $date,
        ]);
    }

    // ─── POST: aggregated stats for a date ───────────────────────────────────

    public function get_stats(): void
    {
        $date = $this->input->post('date') ?: date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->json_error('Invalid date.');
        }

        $this->json_success(['stats' => Debug_tracker::compute_stats($date)]);
    }

    // ─── POST: enable / disable debug mode ───────────────────────────────────

    public function toggle_debug(): void
    {
        $enable = $this->input->post('enable');

        if ($enable === '1' || $enable === true || $enable === 'true') {
            $ok = Debug_tracker::enable();
            $this->sa_log('debug_mode_enabled', '', ['flag_file' => APPPATH . 'logs/.debug_enabled']);
            $this->json_success(['enabled' => true, 'message' => $ok ? 'Debug mode enabled.' : 'Failed to create flag file.']);
        } else {
            $ok = Debug_tracker::disable();
            $this->sa_log('debug_mode_disabled');
            $this->json_success(['enabled' => false, 'message' => $ok ? 'Debug mode disabled.' : 'Failed to remove flag file.']);
        }
    }

    // ─── POST: delete old debug log files ────────────────────────────────────

    public function clear_debug_logs(): void
    {
        $date = $this->input->post('date'); // 'all' or YYYY-MM-DD

        $deleted = 0;
        if ($date === 'all') {
            foreach (Debug_tracker::available_dates() as $d) {
                $f = APPPATH . 'logs/' . 'debug_' . $d . '.log';
                if (file_exists($f) && @unlink($f)) $deleted++;
            }
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $f = APPPATH . 'logs/debug_' . $date . '.log';
            if (file_exists($f) && @unlink($f)) $deleted = 1;
        } else {
            $this->json_error('Invalid date parameter.');
        }

        $this->sa_log('debug_logs_cleared', '', ['deleted' => $deleted, 'date' => $date]);
        $this->json_success(['deleted' => $deleted]);
    }

    // ─── POST: run schema validation against live Firebase data ──────────────

    public function schema_check(): void
    {
        $results = [];

        $checks = [
            // [label, firebase_path, required_fields]
            ['System/Schools (first 5)', 'System/Schools', ['profile','subscription','status']],
            ['System/Plans (all)',       'System/Plans',  ['name','price','billing_cycle','modules']],
            ['System/BackupSchedule',   'System/BackupSchedule', ['enabled','frequency','retention','cron_key']],
            ['System/Stats/Summary',    'System/Stats/Summary',  ['total_schools','active_schools','updated_at']],
        ];

        foreach ($checks as [$label, $path, $required]) {
            try {
                $data = $this->firebase->get($path);
                if (!is_array($data)) {
                    $results[] = ['path' => $path, 'label' => $label, 'status' => 'empty', 'issues' => ['Node is null or not an array']];
                    continue;
                }

                // For collection nodes (Schools, Plans) — check each child
                $issues = [];
                $is_collection = in_array($path, ['System/Schools', 'System/Plans'], true);

                if ($is_collection) {
                    $checked = 0;
                    foreach ($data as $key => $child) {
                        if ($checked >= 5) break; // limit to first 5 for speed
                        if (!is_array($child)) continue;
                        $missing = array_diff($required, array_keys($child));
                        if (!empty($missing)) {
                            $issues[] = "{$key}: missing [" . implode(', ', $missing) . "]";
                        }
                        $checked++;
                    }
                } else {
                    $missing = array_diff($required, array_keys($data));
                    if (!empty($missing)) {
                        $issues[] = "Missing: " . implode(', ', $missing);
                    }
                }

                $results[] = [
                    'path'   => $path,
                    'label'  => $label,
                    'status' => empty($issues) ? 'ok' : 'mismatch',
                    'issues' => $issues,
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'path'   => $path,
                    'label'  => $label,
                    'status' => 'error',
                    'issues' => [$e->getMessage()],
                ];
            }
        }

        $this->json_success(['results' => $results, 'checked_at' => date('Y-m-d H:i:s')]);
    }

    // ─── POST: receive client-side AJAX error reports ────────────────────────

    public function log_ajax_error(): void
    {
        if (!GRADER_DEBUG) {
            $this->json_success([]); // silently accept but don't log
            return;
        }

        if (!class_exists('Debug_tracker', false)) {
            require_once APPPATH . 'libraries/Debug_tracker.php';
        }

        $url     = $this->input->post('url',              TRUE) ?: '';
        $status  = (int)($this->input->post('status',     TRUE) ?: 0);
        $error   = $this->input->post('error',             TRUE) ?: '';
        $preview = $this->input->post('response_preview',  TRUE) ?: '';

        Debug_tracker::getInstance()->record_ajax_error(
            $url,
            $status,
            $error,
            $preview,
            $this->input->ip_address()
        );
        Debug_tracker::getInstance()->flush();

        // M-05 FIX: Audit log for AJAX error recording
        $this->sa_log('ajax_error_logged', '', ['url' => substr($url, 0, 200), 'status' => $status]);

        $this->json_success([]);
    }
}
