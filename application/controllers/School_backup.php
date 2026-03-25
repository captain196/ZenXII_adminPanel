<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * School_backup — School-Level Backup Management
 *
 * Allows school admins (Admin / Principal) to:
 *   - Enable/disable daily automatic backups
 *   - Create manual backups (limited to 1 per day)
 *   - View backup history
 *   - Download their own school's backup files
 *
 * Security constraints:
 *   - All operations scoped to current school only ($this->school_name)
 *   - No restore or delete capabilities (Superadmin-only)
 *   - backup_id ownership validated before every download
 *   - Manual backup rate-limited to 1 per calendar day
 *
 * Firebase paths:
 *   Schools/{school_id}/Config/BackupSchedule   — per-school schedule config
 *   System/Backups/{safe_uid}/{backup_id}        — backup metadata (shared with SA)
 *
 * Routes:
 *   school_backup                        → index (page load)
 *   school_backup/get_backups            → AJAX backup list
 *   school_backup/get_schedule           → AJAX schedule config
 *   school_backup/save_schedule          → AJAX save schedule
 *   school_backup/create_backup          → AJAX manual backup
 *   school_backup/download/(:any)        → file download
 */
class School_backup extends MY_Controller
{
    /** Roles allowed to access the backup module (case-insensitive check via _check_role) */
    const ALLOWED_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal'];

    /** Maximum retention days a school admin can set */
    const MAX_RETENTION = 14;

    /** Maximum manual backups per calendar day */
    const DAILY_MANUAL_LIMIT = 1;

    public function __construct()
    {
        parent::__construct();
        require_permission('Configuration');
        $this->load->library('backup_service');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  PAGE LOAD
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET — Render the backup management SPA page.
     */
    public function index()
    {
        $this->_require_role(self::ALLOWED_ROLES, 'school_backup_view');

        $data = [
            'page_title'    => 'Backup Management',
            'school_name'   => $this->school_display_name,
            'school_fb_key' => $this->school_name,
            'session_year'  => $this->session_year,
            'admin_role'    => $this->admin_role ?? '',
            'admin_id'      => $this->admin_id ?? '',
            'admin_name'    => $this->admin_name ?? '',
        ];

        $this->load->view('include/header');
        $this->load->view('school_backup/index', $data);
        $this->load->view('include/footer');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  AJAX — BACKUP LIST
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET — Return all backups for the current school (newest first).
     */
    public function get_backups()
    {
        $this->_require_role(self::ALLOWED_ROLES, 'school_backup_list');

        try {
            $rows = $this->backup_service->get_school_backups($this->school_name);

            // Compute summary stats
            $total_size  = 0;
            $manual      = 0;
            $scheduled   = 0;
            foreach ($rows as $r) {
                $total_size += (int) ($r['size_bytes'] ?? 0);
                $type = $r['type'] ?? '';
                if ($type === 'manual')    $manual++;
                if ($type === 'scheduled') $scheduled++;
            }

            $this->json_success([
                'backups'    => $rows,
                'total'      => count($rows),
                'total_size' => $this->backup_service->human_size($total_size),
                'manual'     => $manual,
                'scheduled'  => $scheduled,
            ]);
        } catch (Exception $e) {
            log_message('error', 'School_backup get_backups: ' . $e->getMessage());
            $this->json_error('Failed to load backup list.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  AJAX — SCHEDULE CONFIG
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET — Read the school's backup schedule configuration.
     */
    public function get_schedule()
    {
        $this->_require_role(self::ALLOWED_ROLES, 'school_backup_schedule');

        try {
            $path     = "Schools/{$this->school_name}/Config/BackupSchedule";
            $schedule = $this->firebase->get($path) ?? [];

            // Ensure defaults so the JS never gets nulls
            $schedule += [
                'enabled'   => false,
                'frequency' => 'daily',
                'retention' => 7,
                'last_run'  => '',
            ];

            $this->json_success(['schedule' => $schedule]);
        } catch (Exception $e) {
            log_message('error', 'School_backup get_schedule: ' . $e->getMessage());
            $this->json_error('Failed to load schedule.');
        }
    }

    /**
     * POST — Save the school's backup schedule settings.
     *
     * Accepts: enabled (bool), retention (int 1–14)
     * Frequency is always 'daily' for school admins.
     */
    public function save_schedule()
    {
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required', 405);
        }
        $this->_require_role(self::ALLOWED_ROLES, 'school_backup_save_schedule');

        $enabled   = filter_var($this->input->post('enabled'), FILTER_VALIDATE_BOOLEAN);
        $retention = (int) ($this->input->post('retention') ?? 7);

        // Clamp retention to safe range
        if ($retention < 1)                  $retention = 1;
        if ($retention > self::MAX_RETENTION) $retention = self::MAX_RETENTION;

        $schedule = [
            'enabled'    => $enabled,
            'frequency'  => 'daily',
            'retention'  => $retention,
            'updated_at' => date('c'),
            'updated_by' => $this->admin_id,
        ];

        try {
            $path = "Schools/{$this->school_name}/Config/BackupSchedule";

            $this->firebase->update($path, $schedule);

            if (function_exists('log_audit')) {
                log_audit('Backup', 'save_schedule', $this->school_name,
                    ($enabled ? 'Enabled' : 'Disabled') . " daily backup, retention={$retention} days"
                );
            }

            $this->json_success([
                'message'  => 'Backup schedule saved.',
                'schedule' => $schedule,
            ]);
        } catch (Exception $e) {
            log_message('error', 'School_backup save_schedule: ' . $e->getMessage());
            $this->json_error('Failed to save schedule.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  AJAX — MANUAL BACKUP
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST — Create a manual backup of the current school's data.
     *
     * Rate-limited: 1 manual backup per calendar day.
     * Backup type fixed to 'firebase' for school admins.
     */
    public function create_backup()
    {
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required', 405);
        }
        $this->_require_role(self::ALLOWED_ROLES, 'school_backup_create');

        try {
            // ── Rate limit: check today's manual backups ─────────────────
            $today = date('Y-m-d');
            $rows  = $this->backup_service->get_school_backups($this->school_name);

            $today_manual = 0;
            foreach ($rows as $r) {
                if (($r['type'] ?? '') === 'manual'
                    && strpos($r['created_at'] ?? '', $today) === 0
                ) {
                    $today_manual++;
                }
            }

            if ($today_manual >= self::DAILY_MANUAL_LIMIT) {
                return $this->json_error(
                    'You have already created a backup today. Manual backups are limited to '
                    . self::DAILY_MANUAL_LIMIT . ' per day.'
                );
            }

            // ── Create backup ────────────────────────────────────────────
            $created_by = $this->admin_name ?: $this->admin_id;
            $result     = $this->backup_service->create_backup(
                $this->school_name,
                'firebase',
                $created_by
            );

            // ── Apply retention if schedule has a retention value ────────
            $schedule  = $this->firebase->get("Schools/{$this->school_name}/Config/BackupSchedule");
            $retention = is_array($schedule) ? (int) ($schedule['retention'] ?? 7) : 7;
            $this->backup_service->apply_retention($this->school_name, max($retention, 1));

            if (function_exists('log_audit')) {
                log_audit('Backup', 'create_backup', $result['backup_id'],
                    "Manual backup created ({$result['size_human']})"
                );
            }

            $this->json_success(array_merge($result, [
                'message' => "Backup created successfully ({$result['size_human']}).",
            ]));
        } catch (Exception $e) {
            log_message('error', 'School_backup create_backup: ' . $e->getMessage());
            $this->json_error('Backup failed: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  DOWNLOAD
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET — Download a backup file belonging to the current school.
     *
     * Validates that the backup_id belongs to this school before serving.
     * Route: school_backup/download/{backup_id}
     */
    public function download($backup_id = '')
    {
        $this->_require_role(self::ALLOWED_ROLES, 'school_backup_download');

        $backup_id = preg_replace('/[^A-Za-z0-9_\-]/', '', trim($backup_id));
        if (empty($backup_id)) {
            show_error('Backup ID is required.', 400);
            return;
        }

        // Verify the backup belongs to this school
        if (!$this->backup_service->backup_belongs_to_school($this->school_name, $backup_id)) {
            show_error('Backup not found or does not belong to your school.', 404);
            return;
        }

        if (function_exists('log_audit')) {
            log_audit('Backup', 'download', $backup_id, 'Downloaded backup file');
        }

        // Serve the file (exits on success)
        $served = $this->backup_service->serve_download($this->school_name, $backup_id);
        if (!$served) {
            show_error('Backup file not found on server.', 404);
        }
    }
}
