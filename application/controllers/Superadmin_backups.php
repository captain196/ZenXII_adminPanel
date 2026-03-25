<?php
defined('BASEPATH') OR exit('No direct script access allowed');
require_once APPPATH . 'core/MY_Superadmin_Controller.php';

/**
 * Superadmin_backups — Full Backup & Restore Management
 *
 * Backup types:
 *   firebase  — School Firebase subtree as JSON (fast, default)
 *   full      — Firebase + system config + uploaded file manifest + actual files (ZIP if ZipArchive available)
 *
 * Firebase paths:
 *   System/Backups/{safe_uid}/{backup_id}  — per-backup metadata
 *   System/BackupSchedule                  — schedule configuration & last-run state
 *
 * Local storage:
 *   application/backups/{safe_uid}/{backup_id}.json|.zip
 *   Protected by .htaccess (Deny from all)
 */
class Superadmin_backups extends MY_Superadmin_Controller
{
    const BACKUP_DIR  = 'application/backups/';
    const UPLOADS_DIR = 'uploads/';
    const ZIP_SIZE_CAP = 52428800; // 50 MB cap for uploaded files in a full backup

    public function __construct()
    {
        parent::__construct();
        $dir = FCPATH . self::BACKUP_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true); // M-10 FIX: owner-only permissions
            file_put_contents($dir . '.htaccess', "Order deny,allow\nDeny from all\n");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET  /superadmin/backups
    // ─────────────────────────────────────────────────────────────────────────

    public function index()
    {
        $schools = []; $schedule = [];
        try {
            $raw  = $this->firebase->get('System/Schools') ?? [];
            foreach ($raw as $name => $s) {
                if (!is_array($s)) continue;
                $schools[$name] = $s['profile']['name'] ?? $name;
            }
            ksort($schools);
            $schedule = $this->firebase->get('System/BackupSchedule') ?? [];
        } catch (Exception $e) {}

        $data = [
            'page_title' => 'Backup Management',
            'schools'    => $schools,
            'schedule'   => $schedule,
        ];
        $this->load->view('superadmin/include/sa_header', $data);
        $this->load->view('superadmin/backups/index',     $data);
        $this->load->view('superadmin/include/sa_footer');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/backups/fetch_backups
    // Returns backup list for one school (school_uid) or all schools (empty)
    // ─────────────────────────────────────────────────────────────────────────

    public function fetch_backups()
    {
        $school_uid = trim($this->input->post('school_uid', TRUE) ?? '');
        try {
            if ($school_uid) {
                $safe_uid = $this->_safe_dir($school_uid);
                $raw      = $this->firebase->get("System/Backups/{$safe_uid}") ?? [];
                $rows = [];
                foreach ($raw as $bid => $b) {
                    $rows[] = array_merge(['backup_id' => $bid, 'school_uid' => $school_uid, 'safe_uid' => $safe_uid], $b);
                }
            } else {
                // All schools — for global overview
                $all  = $this->firebase->get('System/Backups') ?? [];
                $rows = [];
                foreach ($all as $suid => $backups) {
                    if (!is_array($backups)) continue;
                    foreach ($backups as $bid => $b) {
                        $rows[] = array_merge(['backup_id' => $bid, 'school_uid' => $suid], $b);
                    }
                }
            }
            usort($rows, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
            $this->json_success(['rows' => $rows, 'backups' => $rows, 'total' => count($rows)]);
        } catch (Exception $e) {
            $this->json_error('Failed to load backup list.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/backups/create_backup
    // ─────────────────────────────────────────────────────────────────────────

    public function create_backup()
    {
        $school_uid  = trim($this->input->post('school_uid',  TRUE) ?? '');
        $backup_type = trim($this->input->post('backup_type', TRUE) ?? 'firebase');
        if (empty($school_uid)) { $this->json_error('School UID required.'); return; }
        if (!in_array($backup_type, ['firebase', 'full'])) $backup_type = 'firebase';

        try {
            $result = $this->_do_backup($school_uid, $backup_type, $this->sa_name);
            $this->sa_log('backup_created', $school_uid, [
                'backup_id'   => $result['backup_id'],
                'backup_type' => $backup_type,
                'size'        => $result['size_human'],
            ]);
            $this->json_success(array_merge($result, [
                'message' => "Backup '{$result['backup_id']}' created ({$result['size_human']}).",
            ]));
        } catch (Exception $e) {
            log_message('error', 'SA create_backup: ' . $e->getMessage());
            $this->json_error('Backup failed: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/backups/restore_backup
    // DESTRUCTIVE — requires confirmation_token = "RESTORE"
    // ─────────────────────────────────────────────────────────────────────────

    public function restore_backup()
    {
        $school_uid         = trim($this->input->post('school_uid',         TRUE) ?? '');
        $backup_id          = trim($this->input->post('backup_id',          TRUE) ?? '');
        $confirmation_token = trim($this->input->post('confirmation_token', TRUE) ?? '');

        // Validate token FIRST so the error message is always meaningful
        if (empty($school_uid) || empty($backup_id)) {
            $this->json_error('School UID and Backup ID are required.'); return;
        }
        if ($confirmation_token !== 'RESTORE') {
            $this->json_error('Type RESTORE (uppercase) to confirm this destructive operation.'); return;
        }

        // Role check after token — developer and superadmin both allowed
        if (!in_array($this->sa_role, ['superadmin', 'developer'], true)) {
            $this->json_error('Only Super Admins can restore backups.', 403); return;
        }

        try {
            $safe_uid = $this->_safe_dir($school_uid);
            $meta     = $this->firebase->get("System/Backups/{$safe_uid}/{$backup_id}");
            if (empty($meta)) { $this->json_error('Backup record not found.'); return; }

            // [FIX-4] Sanitise filename — prevent path traversal
            $raw_filename = basename($meta['filename'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_\-\.]+$/', $raw_filename)) {
                $this->json_error('Backup metadata contains an invalid filename.'); return;
            }

            $filepath = FCPATH . self::BACKUP_DIR . $safe_uid . '/' . $raw_filename;
            if (!file_exists($filepath)) {
                $this->json_error('Backup file not found on disk. It may have been manually deleted.'); return;
            }

            $export = $this->_read_backup_file($filepath, $raw_filename);
            if (!$export || !isset($export['Schools'], $export['firebase_key'])) {
                $this->json_error('Backup file is corrupted or has an invalid format.'); return;
            }

            $firebase_key = $export['firebase_key'];

            // [FIX] Validate firebase_key to prevent path injection
            if (!preg_match('/^[A-Za-z0-9 \'_\-]+$/u', $firebase_key)) {
                $this->json_error('Backup contains an invalid firebase_key — possible path injection.'); return;
            }

            // Auto-create safety backup before restoring
            $safety_meta = $this->_create_safety_backup($firebase_key, $school_uid, $backup_id);

            // Restore Firebase data — academic tree
            $this->firebase->set("Schools/{$firebase_key}", $export['Schools']);

            // Restore Users/Admin and Users/Parents if present in backup (format 1.3+)
            $restored_users = false;
            if (!empty($export['UsersAdmin']) && is_array($export['UsersAdmin'])) {
                $ak = $export['admin_key'] ?? '';
                if ($ak !== '' && preg_match('/^[A-Za-z0-9 \'_\-]+$/u', $ak)) {
                    $this->firebase->set("Users/Admin/{$ak}", $export['UsersAdmin']);
                    $restored_users = true;
                }
            }
            if (!empty($export['UsersParents']) && is_array($export['UsersParents'])) {
                $pk = $export['parent_key'] ?? '';
                if ($pk !== '' && preg_match('/^[A-Za-z0-9 \'_\-]+$/u', $pk)) {
                    $this->firebase->set("Users/Parents/{$pk}", $export['UsersParents']);
                    $restored_users = true;
                }
            }

            // Update restore stamp on backup record
            $this->firebase->update("System/Backups/{$safe_uid}/{$backup_id}", [
                'last_restored_at' => date('Y-m-d H:i:s'),
                'last_restored_by' => $this->sa_name,
            ]);

            $this->sa_log('backup_restored', $school_uid, [
                'backup_id'    => $backup_id,
                'safety_id'    => $safety_meta['backup_id'] ?? '',
                'firebase_key' => $firebase_key,
                'users_restored' => $restored_users,
            ]);

            $user_msg = $restored_users ? ' Admin & student profiles also restored.' : '';
            $this->json_success([
                'message'   => "School data restored from '{$backup_id}'. Safety backup '{$safety_meta['backup_id']}' was created automatically.{$user_msg}",
                'safety_id' => $safety_meta['backup_id'] ?? '',
            ]);
        } catch (Exception $e) {
            log_message('error', 'SA restore_backup: ' . $e->getMessage());
            $this->json_error('Restore failed: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/backups/upload_restore
    // Restore from a locally-uploaded JSON backup file
    // ─────────────────────────────────────────────────────────────────────────

    public function upload_restore()
    {
        if (!in_array($this->sa_role, ['superadmin', 'developer'], true)) {
            $this->json_error('Insufficient privileges.', 403); return;
        }

        $confirmation_token = trim($this->input->post('confirmation_token', TRUE) ?? '');
        if ($confirmation_token !== 'RESTORE') {
            $this->json_error('Type RESTORE to confirm.'); return;
        }

        if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            $this->json_error('No valid backup file uploaded.'); return;
        }

        $file = $_FILES['backup_file'];

        // M-03 FIX: Validate is_uploaded_file + MIME check before reading
        if (!is_uploaded_file($file['tmp_name'])) {
            $this->json_error('Invalid upload.'); return;
        }
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'json') {
            $this->json_error('Only .json backup files are supported for upload restore.'); return;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($file['tmp_name']);
        if (!in_array($realMime, ['application/json', 'text/plain'], true)) {
            $this->json_error('File content does not appear to be valid JSON.'); return;
        }

        $json   = file_get_contents($file['tmp_name']);
        $export = $json ? json_decode($json, true) : null;

        if (!$export || !isset($export['Schools'], $export['firebase_key'])) {
            $this->json_error('Uploaded file is not a valid backup (missing Schools or firebase_key fields).'); return;
        }

        $firebase_key = $export['firebase_key'];
        $school_name  = $export['school_name'] ?? $firebase_key;

        // [FIX] Validate firebase_key to prevent path injection
        if (!preg_match('/^[A-Za-z0-9 \'_\-]+$/u', $firebase_key)) {
            $this->json_error('Backup contains an invalid firebase_key — possible path injection.'); return;
        }

        try {
            // Safety backup
            $safety_meta = $this->_create_safety_backup($firebase_key, $school_name, 'upload_restore');

            // Restore academic data
            $this->firebase->set("Schools/{$firebase_key}", $export['Schools']);

            // Restore Users/Admin and Users/Parents if present (format 1.3+)
            $restored_users = false;
            if (!empty($export['UsersAdmin']) && is_array($export['UsersAdmin'])) {
                $ak = $export['admin_key'] ?? '';
                if ($ak !== '' && preg_match('/^[A-Za-z0-9 \'_\-]+$/u', $ak)) {
                    $this->firebase->set("Users/Admin/{$ak}", $export['UsersAdmin']);
                    $restored_users = true;
                }
            }
            if (!empty($export['UsersParents']) && is_array($export['UsersParents'])) {
                $pk = $export['parent_key'] ?? '';
                if ($pk !== '' && preg_match('/^[A-Za-z0-9 \'_\-]+$/u', $pk)) {
                    $this->firebase->set("Users/Parents/{$pk}", $export['UsersParents']);
                    $restored_users = true;
                }
            }

            $this->sa_log('backup_upload_restored', $firebase_key, [
                'source_file'    => $file['name'],
                'safety_id'      => $safety_meta['backup_id'] ?? '',
                'users_restored' => $restored_users,
            ]);

            $user_msg = $restored_users ? ' Admin & student profiles also restored.' : '';
            $this->json_success([
                'message'   => "Data for '{$school_name}' restored from uploaded file. Safety backup '{$safety_meta['backup_id']}' created.{$user_msg}",
                'safety_id' => $safety_meta['backup_id'] ?? '',
            ]);
        } catch (Exception $e) {
            log_message('error', 'SA upload_restore: ' . $e->getMessage());
            $this->json_error('Upload restore failed: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/backups/delete_backup
    // ─────────────────────────────────────────────────────────────────────────

    public function delete_backup()
    {
        $school_uid = trim($this->input->post('school_uid', TRUE) ?? '');
        $backup_id  = trim($this->input->post('backup_id',  TRUE) ?? '');
        if (empty($school_uid) || empty($backup_id)) {
            $this->json_error('Required fields missing.'); return;
        }

        try {
            $safe_uid = $this->_safe_dir($school_uid);
            $meta     = $this->firebase->get("System/Backups/{$safe_uid}/{$backup_id}");
            $filename = basename($meta['filename'] ?? '');
            if ($filename && !preg_match('/^[A-Za-z0-9_\-\.]+$/', $filename)) $filename = '';

            $filepath = FCPATH . self::BACKUP_DIR . $safe_uid . '/' . $filename;
            if ($filename && file_exists($filepath)) unlink($filepath);

            $this->firebase->delete("System/Backups/{$safe_uid}", $backup_id);
            $this->sa_log('backup_deleted', $school_uid, ['backup_id' => $backup_id]);
            $this->json_success(['message' => "Backup '{$backup_id}' deleted."]);
        } catch (Exception $e) {
            $this->json_error('Failed to delete backup.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET  /superadmin/backups/download/{school_uid}/{backup_id}
    // ─────────────────────────────────────────────────────────────────────────

    public function download($school_uid = '', $backup_id = '')
    {
        if (empty($school_uid) || empty($backup_id)) {
            show_error('Invalid download request.', 400); return;
        }

        $school_uid = $this->_safe_dir(urldecode($school_uid));
        $backup_id  = preg_replace('/[^A-Za-z0-9_\-]/', '', $backup_id);

        try {
            $meta     = $this->firebase->get("System/Backups/{$school_uid}/{$backup_id}");
            $filename = basename($meta['filename'] ?? ($backup_id . '.json'));
            $filepath = FCPATH . self::BACKUP_DIR . $school_uid . '/' . $filename;
        } catch (Exception $e) {
            show_error('Backup metadata not found.', 404); return;
        }

        if (!file_exists($filepath)) {
            show_error('Backup file not found on server.', 404); return;
        }

        $this->sa_log('backup_downloaded', $school_uid, ['backup_id' => $backup_id]);

        $ctype = str_ends_with($filepath, '.zip') ? 'application/zip' : 'application/json';
        header('Content-Type: ' . $ctype);
        header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        readfile($filepath);
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/backups/backup_stats
    // Global backup statistics across all schools
    // ─────────────────────────────────────────────────────────────────────────

    public function backup_stats()
    {
        try {
            $all            = $this->firebase->get('System/Backups') ?? [];
            $total_backups  = 0;
            $total_bytes    = 0;
            $schools_backed = 0;
            $manual_count   = 0;
            $auto_count     = 0;
            $latest         = null;

            foreach ($all as $suid => $backups) {
                if (!is_array($backups)) continue;
                $schools_backed++;
                foreach ($backups as $bid => $b) {
                    if (!is_array($b)) continue;
                    $total_backups++;
                    $total_bytes += (int)($b['size_bytes'] ?? 0);
                    $type = $b['type'] ?? '';
                    if ($type === 'scheduled') $auto_count++;
                    elseif ($type === 'manual') $manual_count++;
                    if (!$latest || strcmp($b['created_at'] ?? '', $latest['created_at'] ?? '') > 0) {
                        $latest = array_merge(['backup_id' => $bid, 'safe_uid' => $suid], $b);
                    }
                }
            }

            $this->json_success([
                'total_backups'  => $total_backups,
                'total_size'     => $this->_human_size($total_bytes),
                'total_bytes'    => $total_bytes,
                'schools_backed' => $schools_backed,
                'manual_count'   => $manual_count,
                'auto_count'     => $auto_count,
                'latest_backup'  => $latest,
            ]);
        } catch (Exception $e) {
            $this->json_error('Failed to load backup stats.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/backups/get_schedule
    // ─────────────────────────────────────────────────────────────────────────

    public function get_schedule()
    {
        try {
            $schedule = $this->firebase->get('System/BackupSchedule') ?? [];
            // Ensure core fields are always present so JS checks don't fail on empty schedule
            $schedule += [
                'enabled'     => false,
                'frequency'   => 'daily',
                'backup_time' => '02:00',
                'retention'   => 7,
                'backup_type' => 'firebase',
                'scope'       => 'all',
            ];
            $this->json_success(['schedule' => $schedule]);
        } catch (Exception $e) {
            $this->json_error('Failed to load schedule.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/backups/save_schedule
    // ─────────────────────────────────────────────────────────────────────────

    public function save_schedule()
    {
        $enabled     = (bool)$this->input->post('enabled',     TRUE);
        $frequency   = trim($this->input->post('frequency',    TRUE) ?? 'daily');
        $day         = (int) $this->input->post('day_of_week', TRUE);
        $time        = trim($this->input->post('backup_time',  TRUE) ?? '02:00');
        $scope       = trim($this->input->post('scope',        TRUE) ?? 'all');
        $retention   = max(1, (int)$this->input->post('retention',   TRUE));
        $backup_type = trim($this->input->post('backup_type',  TRUE) ?? 'firebase');

        if (!in_array($frequency,   ['daily', 'weekly']))        $frequency   = 'daily';
        if (!in_array($scope,       ['all', 'selected']))        $scope       = 'all';
        if (!in_array($backup_type, ['firebase', 'full']))       $backup_type = 'firebase';
        if ($day < 0 || $day > 6)                                $day         = 0;
        if (!preg_match('/^\d{2}:\d{2}$/', $time))               $time        = '02:00';

        try {
            $existing = $this->firebase->get('System/BackupSchedule') ?? [];
            // Preserve or generate cron key
            $cron_key = $existing['cron_key'] ?? substr(md5(uniqid('bkp_', true)), 0, 20);

            $schedule = [
                'enabled'     => $enabled,
                'frequency'   => $frequency,
                'day_of_week' => $day,
                'backup_time' => $time,
                'scope'       => $scope,
                'retention'   => $retention,
                'backup_type' => $backup_type,
                'cron_key'    => $cron_key,
                'updated_at'  => date('Y-m-d H:i:s'),
                'updated_by'  => $this->sa_name,
            ];
            // Preserve last run info
            foreach (['last_run', 'last_run_count', 'last_run_by'] as $k) {
                if (isset($existing[$k])) $schedule[$k] = $existing[$k];
            }

            $this->firebase->set('System/BackupSchedule', $schedule);
            $this->sa_log('backup_schedule_saved', '', ['frequency' => $frequency, 'enabled' => $enabled]);
            $this->json_success(['schedule' => $schedule, 'cron_key' => $cron_key, 'message' => 'Schedule saved successfully.']);
        } catch (Exception $e) {
            $this->json_error('Failed to save schedule: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/backups/run_scheduled_now
    // Manual trigger — backs up all schools (or selected scope), applies retention
    // ─────────────────────────────────────────────────────────────────────────

    public function run_scheduled_now()
    {
        set_time_limit(300);
        ini_set('memory_limit', '256M');

        try {
            $schedule    = $this->firebase->get('System/BackupSchedule') ?? [];
            $backup_type = $schedule['backup_type'] ?? 'firebase';
            $retention   = max(1, (int)($schedule['retention'] ?? 7));

            $raw     = $this->firebase->get('System/Schools') ?? [];
            $schools = array_keys(array_filter($raw, 'is_array'));

            if (empty($schools)) {
                $this->json_error('No schools found.'); return;
            }

            $succeeded = 0; $failed = 0; $results = [];
            foreach ($schools as $school_uid) {
                try {
                    $r = $this->_do_backup($school_uid, $backup_type, 'scheduled');
                    $this->_apply_retention($school_uid, $retention);
                    $succeeded++;
                    $results[] = ['school' => $school_uid, 'status' => 'ok', 'backup_id' => $r['backup_id'], 'size' => $r['size_human']];
                } catch (Exception $e) {
                    $failed++;
                    $results[] = ['school' => $school_uid, 'status' => 'error', 'message' => $e->getMessage()];
                    log_message('error', "Scheduled backup failed for {$school_uid}: " . $e->getMessage());
                }
            }

            $this->firebase->update('System/BackupSchedule', [
                'last_run'       => date('Y-m-d H:i:s'),
                'last_run_count' => $succeeded,
                'last_run_by'    => $this->sa_name,
            ]);

            $this->sa_log('backup_scheduled_run', '', [
                'succeeded' => $succeeded,
                'failed'    => $failed,
                'trigger'   => 'manual',
            ]);

            $this->json_success([
                'succeeded' => $succeeded,
                'failed'    => $failed,
                'results'   => $results,
                'message'   => "Scheduled run complete: {$succeeded} school(s) backed up" . ($failed ? ", {$failed} failed." : '.'),
            ]);
        } catch (Exception $e) {
            log_message('error', 'SA run_scheduled_now: ' . $e->getMessage());
            $this->json_error('Scheduled run failed: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE — Core backup logic
    // ─────────────────────────────────────────────────────────────────────────

    private function _do_backup(string $school_uid, string $backup_type, string $created_by): array
    {
        $firebase_key = $school_uid;

        // Verify school exists in System/Schools (primary registry)
        $school_node = $this->firebase->get("System/Schools/{$firebase_key}");
        if (empty($school_node)) {
            throw new Exception("School '{$school_uid}' not found.");
        }

        // Pull academic data (may be empty for newly onboarded schools)
        $backup_data = $this->firebase->get("Schools/{$firebase_key}") ?? [];

        // ── Resolve keys for Users/Admin and Users/Parents ──────────────
        $profile     = is_array($school_node['profile'] ?? null) ? $school_node['profile'] : [];
        $school_code = $profile['school_code'] ?? ($school_node['School Id'] ?? '');
        $isSCH       = strpos($firebase_key, 'SCH_') === 0;
        // Users/Admin always keyed by school_code
        // Users/Parents keyed by school_id for SCH_ schools, school_code for legacy
        $admin_key   = $school_code;
        $parent_key  = $isSCH ? $firebase_key : ($school_code ?: $firebase_key);

        // Pull user data (admin credentials + student profiles)
        $admin_data  = [];
        $parent_data = [];
        if (!empty($admin_key)) {
            try { $admin_data = $this->firebase->get("Users/Admin/{$admin_key}") ?? []; } catch (Exception $e) {}
        }
        if (!empty($parent_key)) {
            try { $parent_data = $this->firebase->get("Users/Parents/{$parent_key}") ?? []; } catch (Exception $e) {}
        }

        $export = [
            'backup_format' => '1.3',
            'backup_type'   => $backup_type,
            'school_name'   => $school_uid,
            'firebase_key'  => $firebase_key,
            'school_code'   => $school_code,
            'admin_key'     => $admin_key,
            'parent_key'    => $parent_key,
            'backed_up_at'  => date('Y-m-d H:i:s'),
            'backed_up_by'  => $created_by,
            'Schools'       => $backup_data,
            'UsersAdmin'    => $admin_data,
            'UsersParents'  => $parent_data,
        ];

        if ($backup_type === 'full') {
            $export['SystemConfig'] = $this->_get_system_config();
            $export['FileManifest'] = $this->_scan_uploads($school_uid);
        }

        $backup_id  = 'BKP_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 6);
        $safe_uid   = $this->_safe_dir($school_uid);
        $school_dir = FCPATH . self::BACKUP_DIR . $safe_uid . '/';
        if (!is_dir($school_dir)) mkdir($school_dir, 0700, true); // M-10 FIX: owner-only permissions

        // For full backups, try ZIP (includes actual uploaded files)
        $use_zip = ($backup_type === 'full') && extension_loaded('zip');
        $filename = $backup_id . ($use_zip ? '.zip' : '.json');
        $filepath = $school_dir . $filename;

        if ($use_zip) {
            $zip = new ZipArchive();
            if ($zip->open($filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $zip->addFromString('firebase_data.json',
                    json_encode([
                        'Schools'      => $backup_data,
                        'UsersAdmin'   => $admin_data,
                        'UsersParents' => $parent_data,
                        'firebase_key' => $firebase_key,
                        'school_code'  => $school_code,
                        'admin_key'    => $admin_key,
                        'parent_key'   => $parent_key,
                        'backed_up_at' => date('Y-m-d H:i:s'),
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                );
                $zip->addFromString('system_config.json',  json_encode($export['SystemConfig'],  JSON_PRETTY_PRINT));
                $zip->addFromString('file_manifest.json',  json_encode($export['FileManifest'],  JSON_PRETTY_PRINT));
                $this->_zip_uploads($zip, $school_uid);
                $zip->close();
                $size_bytes = filesize($filepath);
            } else {
                // ZipArchive failed — fall through to JSON
                $use_zip  = false;
                $filename = $backup_id . '.json';
                $filepath = $school_dir . $filename;
            }
        }

        if (!$use_zip) {
            $json       = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $size_bytes = strlen($json);
            if (file_put_contents($filepath, $json) === false) {
                throw new Exception('Failed to write backup file. Check directory permissions.');
            }
            chmod($filepath, 0600); // M-10 FIX: owner read/write only
        }

        $meta = [
            'backup_id'   => $backup_id,
            'school_name' => $school_uid,
            'filename'    => $filename,
            'backup_type' => $backup_type,
            'format'      => $use_zip ? 'zip' : 'json',
            'size_bytes'  => $size_bytes,
            'size_human'  => $this->_human_size($size_bytes),
            'type'        => $created_by === 'scheduled' ? 'scheduled' : 'manual',
            'status'      => 'completed',
            'created_at'  => date('Y-m-d H:i:s'),
            'created_by'  => $created_by,
        ];

        $this->firebase->set("System/Backups/{$safe_uid}/{$backup_id}", $meta);

        return [
            'backup_id'  => $backup_id,
            'size_human' => $meta['size_human'],
            'size_bytes' => $size_bytes,
            'filename'   => $filename,
            'format'     => $meta['format'],
        ];
    }

    private function _create_safety_backup(string $firebase_key, string $school_name, string $before_action): array
    {
        $safety_data = $this->firebase->get("Schools/{$firebase_key}");
        if (empty($safety_data)) return ['backup_id' => ''];

        // ── Resolve keys for Users/Admin and Users/Parents ──────────────
        $school_node = $this->firebase->get("System/Schools/{$firebase_key}");
        if (!is_array($school_node)) $school_node = [];
        $profile     = is_array($school_node['profile'] ?? null) ? $school_node['profile'] : [];
        $school_code = $profile['school_code'] ?? ($school_node['School Id'] ?? '');
        $isSCH       = strpos($firebase_key, 'SCH_') === 0;
        $admin_key   = $school_code;
        $parent_key  = $isSCH ? $firebase_key : ($school_code ?: $firebase_key);

        $admin_data  = [];
        $parent_data = [];
        if (!empty($admin_key)) {
            try { $admin_data = $this->firebase->get("Users/Admin/{$admin_key}") ?? []; } catch (Exception $e) {}
        }
        if (!empty($parent_key)) {
            try { $parent_data = $this->firebase->get("Users/Parents/{$parent_key}") ?? []; } catch (Exception $e) {}
        }

        $safe_uid    = $this->_safe_dir($firebase_key);
        $safety_id   = 'SAFETY_' . date('Ymd_His');
        $safety_dir  = FCPATH . self::BACKUP_DIR . $safe_uid . '/';
        if (!is_dir($safety_dir)) mkdir($safety_dir, 0700, true); // M-10 FIX: owner-only permissions

        $safety_export = [
            'backup_format' => '1.3',
            'backup_type'   => 'firebase',
            'school_name'   => $school_name,
            'firebase_key'  => $firebase_key,
            'school_code'   => $school_code,
            'admin_key'     => $admin_key,
            'parent_key'    => $parent_key,
            'backed_up_at'  => date('Y-m-d H:i:s'),
            'backed_up_by'  => 'auto-safety',
            'Schools'       => $safety_data,
            'UsersAdmin'    => $admin_data,
            'UsersParents'  => $parent_data,
        ];
        $safety_json   = json_encode($safety_export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $safetyPath = $safety_dir . $safety_id . '.json';
        file_put_contents($safetyPath, $safety_json);
        chmod($safetyPath, 0600); // M-10 FIX: owner read/write only

        $meta = [
            'backup_id'   => $safety_id,
            'school_name' => $school_name,
            'filename'    => $safety_id . '.json',
            'backup_type' => 'firebase',
            'format'      => 'json',
            'type'        => 'pre_restore_safety',
            'status'      => 'completed',
            'size_bytes'  => strlen($safety_json),
            'size_human'  => $this->_human_size(strlen($safety_json)),
            'created_at'  => date('Y-m-d H:i:s'),
            'created_by'  => $this->sa_name,
            'note'        => "Auto-created before: {$before_action}",
        ];
        $this->firebase->set("System/Backups/{$safe_uid}/{$safety_id}", $meta);

        return $meta;
    }

    private function _read_backup_file(string $filepath, string $filename): ?array
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === 'zip' && extension_loaded('zip')) {
            $zip = new ZipArchive();
            if ($zip->open($filepath) !== true) return null;
            $json = $zip->getFromName('firebase_data.json');
            $zip->close();
            if ($json === false) return null;
            $data = json_decode($json, true);
            // Wrap in expected format
            if ($data && isset($data['Schools'], $data['firebase_key'])) return $data;
            return null;
        }
        $json = file_get_contents($filepath);
        return $json ? json_decode($json, true) : null;
    }

    private function _apply_retention(string $school_uid, int $keep): void
    {
        $safe_uid = $this->_safe_dir($school_uid);
        $raw      = $this->firebase->get("System/Backups/{$safe_uid}") ?? [];
        if (!is_array($raw) || count($raw) <= $keep) return;

        // Only auto-delete non-safety backups
        $entries = [];
        foreach ($raw as $bid => $b) {
            if (!is_array($b)) continue;
            if (strpos((string)$bid, 'SAFETY') !== false) continue;
            $entries[$bid] = $b['created_at'] ?? '';
        }
        asort($entries);

        $excess = count($entries) - $keep;
        if ($excess <= 0) return;

        foreach (array_slice(array_keys($entries), 0, $excess) as $bid) {
            $filename = basename($raw[$bid]['filename'] ?? '');
            if ($filename && preg_match('/^[A-Za-z0-9_\-\.]+$/', $filename)) {
                $fp = FCPATH . self::BACKUP_DIR . $safe_uid . '/' . $filename;
                if (file_exists($fp)) @unlink($fp);
            }
            $this->firebase->delete("System/Backups/{$safe_uid}", $bid);
        }
    }

    private function _get_system_config(): array
    {
        $db_info = [];
        try {
            $db_cfg  = $this->config->item('db');
            if ($db_cfg) {
                $db_info = [
                    'hostname' => $db_cfg['hostname'] ?? 'localhost',
                    'database' => $db_cfg['database'] ?? '',
                    'driver'   => $db_cfg['dbdriver'] ?? '',
                    // password intentionally excluded for security
                ];
            }
        } catch (Exception $e) {}

        return [
            'php_version'     => PHP_VERSION,
            'ci_version'      => CI_VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'server_time'     => date('Y-m-d H:i:s'),
            'timezone'        => date_default_timezone_get(),
            'database'        => $db_info,
            'app_env'         => ENVIRONMENT,
            'base_url'        => base_url(),
            'disk_free_mb'    => round(disk_free_space('.') / 1048576, 1),
            'memory_limit'    => ini_get('memory_limit'),
        ];
    }

    private function _scan_uploads(string $school_uid): array
    {
        $safe_uid  = $this->_safe_dir($school_uid);
        $manifest  = [
            'school_uid'  => $school_uid,
            'scanned_at'  => date('Y-m-d H:i:s'),
            'total_files' => 0,
            'total_size'  => 0,
            'files'       => [],
        ];

        $base = FCPATH . self::UPLOADS_DIR;
        if (!is_dir($base)) return $manifest;

        $candidates = [
            $base . $safe_uid . '/',
            $base . 'students/' . $safe_uid . '/',
            $base . 'teachers/' . $safe_uid . '/',
            $base . 'photos/'   . $safe_uid . '/',
        ];

        foreach ($candidates as $dir) {
            if (!is_dir($dir)) continue;
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
                );
                foreach ($it as $file) {
                    $manifest['files'][] = [
                        'path'  => str_replace(FCPATH, '', $file->getPathname()),
                        'size'  => $file->getSize(),
                        'mtime' => date('Y-m-d H:i:s', $file->getMTime()),
                    ];
                    $manifest['total_size'] += $file->getSize();
                }
            } catch (Exception $e) {}
        }

        $manifest['total_files']   = count($manifest['files']);
        $manifest['total_size_hr'] = $this->_human_size($manifest['total_size']);
        return $manifest;
    }

    private function _zip_uploads(ZipArchive $zip, string $school_uid): void
    {
        $safe_uid   = $this->_safe_dir($school_uid);
        $base        = FCPATH . self::UPLOADS_DIR;
        if (!is_dir($base)) return;

        $total_size = 0;
        $candidates = [
            $base . $safe_uid . '/',
            $base . 'students/' . $safe_uid . '/',
            $base . 'teachers/' . $safe_uid . '/',
        ];

        foreach ($candidates as $dir) {
            if (!is_dir($dir)) continue;
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
                );
                foreach ($it as $file) {
                    if ($total_size + $file->getSize() > self::ZIP_SIZE_CAP) return;
                    $rel = 'files/' . str_replace(FCPATH, '', $file->getPathname());
                    $zip->addFile($file->getPathname(), $rel);
                    $total_size += $file->getSize();
                }
            } catch (Exception $e) {}
        }
    }

    private function _safe_dir(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9\-]/', '_', trim($name));
    }

    private function _human_size(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576)    return round($bytes / 1048576,    2) . ' MB';
        if ($bytes >= 1024)       return round($bytes / 1024,       1) . ' KB';
        return $bytes . ' B';
    }
}
