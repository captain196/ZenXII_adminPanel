<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * AuditLogs — Centralized ERP Audit Trail
 *
 * Read-only view of all auditable actions across the ERP. Logs live in
 * Firestore (collection `auditLogs`, doc ID `{schoolId}_{logId}`); the
 * helper `log_audit()` writes them. Migrated from RTDB 2026-05-05.
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

    /** Cap returned to the UI — paging is not required at this scale. */
    private const UI_PAGE_SIZE = 500;

    public function __construct()
    {
        parent::__construct();
        $this->load->helper('audit');
        require_permission('Admin Users'); // Audit logs live under Admin Users RBAC gate
    }

    /**
     * Pull the most recent N entries for the current school. The
     * Firestore index is (schoolId ASC, timestampMs DESC); a single
     * query returns docs already sorted newest-first.
     */
    private function _recent_logs(int $limit = self::UI_PAGE_SIZE): array
    {
        $rows = $this->fs->where(
            'auditLogs',
            [['schoolId', '==', $this->school_id]],
            'timestampMs',
            'DESC',
            $limit
        );

        $logs = [];
        foreach ((array) $rows as $row) {
            if (!is_array($row)) continue;
            $data = $row['data'] ?? null;
            if (!is_array($data)) continue;
            // Surface logId at the top level so existing JS keeps working.
            $data['logId'] = $data['logId'] ?? ($row['id'] ?? '');
            $logs[] = $data;
        }
        return $logs;
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
    //  AJAX: get_logs — most recent logs for the current school
    // ─────────────────────────────────────────────────────────────────────

    public function get_logs(): void
    {
        $this->_require_role(['Super Admin', 'Admin', 'Principal'], 'audit_get_logs');

        try {
            $logs  = $this->_recent_logs(self::UI_PAGE_SIZE);
            $total = $this->fs->count('auditLogs', [['schoolId', '==', $this->school_id]]);

            $this->json_success([
                'logs'  => $logs,
                'total' => $total,
            ]);
        } catch (\Exception $e) {
            log_message('error', 'AuditLogs::get_logs — ' . $e->getMessage());
            $this->json_error('Failed to load audit logs.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  AJAX: filter_logs — module / action / user / date filters
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
            $logs = [];
            foreach ($this->_recent_logs(self::UI_PAGE_SIZE) as $entry) {
                if ($module !== '' && ($entry['module'] ?? '') !== $module) continue;
                if ($action !== '' && stripos($entry['action'] ?? '', $action) === false) continue;
                if ($userId !== '') {
                    $matchUser = stripos($entry['userId']   ?? '', $userId) !== false
                              || stripos($entry['userName'] ?? '', $userId) !== false;
                    if (!$matchUser) continue;
                }

                $dateStr = substr($entry['timestamp'] ?? '', 0, 10);
                if ($dateFrom !== '' && $dateStr < $dateFrom) continue;
                if ($dateTo   !== '' && $dateStr > $dateTo)   continue;

                $logs[] = $entry;
            }

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
    //  AJAX: get_user_activity — all logs for one admin user
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
            $logs = [];
            foreach ($this->_recent_logs(self::UI_PAGE_SIZE) as $entry) {
                if (($entry['userId'] ?? '') !== $userId) continue;
                $logs[] = $entry;
            }
            $logs = array_slice($logs, 0, 200);

            // Per-module summary
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
            $rows = $this->_recent_logs(self::UI_PAGE_SIZE);

            $total      = $this->fs->count('auditLogs', [['schoolId', '==', $this->school_id]]);
            $byModule   = [];
            $byUser     = [];
            $todayCount = 0;
            $today      = gmdate('Y-m-d');
            $adminList  = [];

            foreach ($rows as $entry) {
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
            log_message('error', 'AuditLogs::get_stats — ' . $e->getMessage());
            $this->json_error('Failed to load audit stats.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  AJAX: archive_old — relocate oldest logs to auditArchive
    // ─────────────────────────────────────────────────────────────────────

    public function archive_old(): void
    {
        $this->_require_role(['Super Admin', 'Admin'], 'audit_archive');

        try {
            $archived = audit_archive_old(null, $this->school_id, self::LOG_LIMIT);
            $this->json_success([
                'message'  => $archived > 0
                    ? "Archived {$archived} old log(s)."
                    : 'No logs need archiving (under ' . number_format(self::LOG_LIMIT) . ' limit).',
                'archived' => $archived,
            ]);
        } catch (\Exception $e) {
            log_message('error', 'AuditLogs::archive_old — ' . $e->getMessage());
            $this->json_error('Archive operation failed.');
        }
    }
}
