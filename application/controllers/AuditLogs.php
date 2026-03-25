<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * AuditLogs — Centralized ERP Audit Trail
 *
 * Provides a read-only view of all auditable actions across the ERP.
 * Logs are stored at Schools/{school}/AuditLogs/{logId}.
 *
 * Access:
 *   Admin / Super Admin — full access (view + archive)
 *   Principal           — view only
 *   Others              — blocked by RBAC
 */
class AuditLogs extends MY_Controller
{
    /** Max logs to keep before archiving older entries */
    private const LOG_LIMIT = 10000;

    public function __construct()
    {
        parent::__construct();
        $this->load->helper('audit');
        require_permission('Admin Users'); // Audit logs live under Admin Users RBAC gate
    }

    /** Firebase base path for this school's audit logs */
    private function _base(): string
    {
        return "Schools/{$this->school_name}/AuditLogs";
    }

    // ─────────────────────────────────────────────────────────────────────
    //  PAGE
    // ─────────────────────────────────────────────────────────────────────

    public function index(): void
    {
        $this->_require_role(['Super Admin', 'Admin', 'Principal'], 'audit_view');

        $data = [
            'page_title' => 'Audit Logs',
        ];

        $this->load->view('include/header', $data);
        $this->load->view('audit_logs/index', $data);
        $this->load->view('include/footer');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  AJAX: get_logs — returns most recent logs (paginated)
    // ─────────────────────────────────────────────────────────────────────

    public function get_logs(): void
    {
        $this->_require_role(['Super Admin', 'Admin', 'Principal'], 'audit_get_logs');

        try {
            $all = $this->firebase->get($this->_base()) ?? [];
            if (!is_array($all)) $all = [];

            $logs = [];
            foreach ($all as $id => $entry) {
                if (!is_array($entry)) continue;
                $entry['logId'] = $id;
                $logs[] = $entry;
            }

            // Sort newest first
            usort($logs, function ($a, $b) {
                return strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? '');
            });

            // Limit to 500 for UI performance
            $logs = array_slice($logs, 0, 500);

            $this->json_success([
                'logs'  => $logs,
                'total' => count($all),
            ]);
        } catch (\Exception $e) {
            log_message('error', 'AuditLogs::get_logs — ' . $e->getMessage());
            $this->json_error('Failed to load audit logs.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  AJAX: filter_logs — filter by module, action, date range, user
    // ─────────────────────────────────────────────────────────────────────

    public function filter_logs(): void
    {
        $this->_require_role(['Super Admin', 'Admin', 'Principal'], 'audit_filter');

        $module    = trim($this->input->post('module', TRUE) ?? '');
        $action    = trim($this->input->post('action', TRUE) ?? '');
        $userId    = trim($this->input->post('user_id', TRUE) ?? '');
        $dateFrom  = trim($this->input->post('date_from', TRUE) ?? '');
        $dateTo    = trim($this->input->post('date_to', TRUE) ?? '');

        try {
            $all = $this->firebase->get($this->_base()) ?? [];
            if (!is_array($all)) $all = [];

            $logs = [];
            foreach ($all as $id => $entry) {
                if (!is_array($entry)) continue;

                // Module filter
                if ($module !== '' && ($entry['module'] ?? '') !== $module) continue;

                // Action filter
                if ($action !== '' && stripos($entry['action'] ?? '', $action) === false) continue;

                // User filter
                if ($userId !== '') {
                    $matchUser = stripos($entry['userId'] ?? '', $userId) !== false
                              || stripos($entry['userName'] ?? '', $userId) !== false;
                    if (!$matchUser) continue;
                }

                // Date range filter
                $ts = $entry['timestamp'] ?? '';
                $dateStr = substr($ts, 0, 10); // YYYY-MM-DD from ISO timestamp

                if ($dateFrom !== '' && $dateStr < $dateFrom) continue;
                if ($dateTo !== '' && $dateStr > $dateTo) continue;

                $entry['logId'] = $id;
                $logs[] = $entry;
            }

            // Sort newest first
            usort($logs, function ($a, $b) {
                return strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? '');
            });

            $logs = array_slice($logs, 0, 500);

            $this->json_success([
                'logs'  => $logs,
                'total' => count($logs),
            ]);
        } catch (\Exception $e) {
            log_message('error', 'AuditLogs::filter_logs — ' . $e->getMessage());
            $this->json_error('Failed to filter audit logs.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  AJAX: get_user_activity — all logs for a specific admin user
    // ─────────────────────────────────────────────────────────────────────

    public function get_user_activity(): void
    {
        $this->_require_role(['Super Admin', 'Admin', 'Principal'], 'audit_user_activity');

        $userId = trim($this->input->post('user_id', TRUE) ?? '');
        if (empty($userId)) {
            $this->json_error('User ID is required.');
            return;
        }

        try {
            $all = $this->firebase->get($this->_base()) ?? [];
            if (!is_array($all)) $all = [];

            $logs = [];
            foreach ($all as $id => $entry) {
                if (!is_array($entry)) continue;
                if (($entry['userId'] ?? '') !== $userId) continue;
                $entry['logId'] = $id;
                $logs[] = $entry;
            }

            usort($logs, function ($a, $b) {
                return strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? '');
            });

            $logs = array_slice($logs, 0, 200);

            // Compute summary
            $modules = [];
            foreach ($logs as $l) {
                $m = $l['module'] ?? 'Unknown';
                $modules[$m] = ($modules[$m] ?? 0) + 1;
            }

            $this->json_success([
                'logs'    => $logs,
                'total'   => count($logs),
                'summary' => $modules,
            ]);
        } catch (\Exception $e) {
            log_message('error', 'AuditLogs::get_user_activity — ' . $e->getMessage());
            $this->json_error('Failed to load user activity.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  AJAX: get_stats — dashboard summary
    // ─────────────────────────────────────────────────────────────────────

    public function get_stats(): void
    {
        $this->_require_role(['Super Admin', 'Admin', 'Principal'], 'audit_stats');

        try {
            $all = $this->firebase->get($this->_base()) ?? [];
            if (!is_array($all)) $all = [];

            $total       = 0;
            $byModule    = [];
            $byUser      = [];
            $todayCount  = 0;
            $today       = gmdate('Y-m-d');
            $adminList   = [];

            foreach ($all as $id => $entry) {
                if (!is_array($entry)) continue;
                $total++;

                $mod  = $entry['module'] ?? 'Unknown';
                $uid  = $entry['userId'] ?? '';
                $name = $entry['userName'] ?? $uid;
                $ts   = substr($entry['timestamp'] ?? '', 0, 10);

                $byModule[$mod] = ($byModule[$mod] ?? 0) + 1;
                $byUser[$uid]   = ($byUser[$uid] ?? 0) + 1;

                if ($ts === $today) $todayCount++;

                if ($uid && !isset($adminList[$uid])) {
                    $adminList[$uid] = $name;
                }
            }

            arsort($byModule);
            arsort($byUser);

            $this->json_success([
                'total'      => $total,
                'today'      => $todayCount,
                'by_module'  => $byModule,
                'by_user'    => array_slice($byUser, 0, 10, true),
                'admin_list' => $adminList,
            ]);
        } catch (\Exception $e) {
            $this->json_error('Failed to load audit stats.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  AJAX: archive_old — move oldest logs to AuditArchive
    // ─────────────────────────────────────────────────────────────────────

    public function archive_old(): void
    {
        $this->_require_role(['Super Admin', 'Admin'], 'audit_archive');

        try {
            $archived = audit_archive_old($this->firebase, $this->school_name, self::LOG_LIMIT);
            $this->json_success([
                'message'  => $archived > 0
                    ? "Archived {$archived} old log(s)."
                    : 'No logs need archiving (under ' . number_format(self::LOG_LIMIT) . ' limit).',
                'archived' => $archived,
            ]);
        } catch (\Exception $e) {
            $this->json_error('Archive operation failed.');
        }
    }
}
