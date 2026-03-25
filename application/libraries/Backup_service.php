<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Backup_service — Shared backup engine used by both Superadmin and School-admin controllers.
 *
 * Extracted from Superadmin_backups::_do_backup() to avoid code duplication.
 * All backup creation, retention, and file-serving logic lives here.
 *
 * Usage:
 *   $this->load->library('backup_service');
 *   $result = $this->backup_service->create_backup($school_uid, 'firebase', 'admin_name');
 */
class Backup_service
{
    const BACKUP_DIR   = 'application/backups/';
    const UPLOADS_DIR  = 'uploads/';
    const ZIP_SIZE_CAP = 52428800; // 50 MB

    /** @var object CI instance */
    private $CI;

    /** @var object Firebase library */
    private $firebase;

    public function __construct()
    {
        $this->CI = &get_instance();
        $this->firebase = $this->CI->firebase;

        $dir = FCPATH . self::BACKUP_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
            file_put_contents($dir . '.htaccess', "Order deny,allow\nDeny from all\n");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  PUBLIC API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Create a full backup of a school's Firebase data.
     *
     * @param  string $school_uid   Firebase key for the school (e.g. "Demo" or "SCH_XXXXXX")
     * @param  string $backup_type  'firebase' or 'full'
     * @param  string $created_by   Name/ID of whoever triggered the backup
     * @return array  {backup_id, size_human, size_bytes, filename, format}
     * @throws Exception on failure
     */
    public function create_backup(string $school_uid, string $backup_type, string $created_by): array
    {
        $firebase_key = $school_uid;

        // Verify school exists in System/Schools
        $school_node = $this->firebase->get("System/Schools/{$firebase_key}");
        if (empty($school_node)) {
            throw new Exception("School '{$school_uid}' not found.");
        }

        // Pull academic data
        $backup_data = $this->firebase->get("Schools/{$firebase_key}") ?? [];

        // Resolve keys for Users/Admin and Users/Parents
        $profile     = is_array($school_node['profile'] ?? null) ? $school_node['profile'] : [];
        $school_code = $profile['school_code'] ?? ($school_node['School Id'] ?? '');
        $isSCH       = strpos($firebase_key, 'SCH_') === 0;
        $admin_key   = $school_code;
        $parent_key  = $isSCH ? $firebase_key : ($school_code ?: $firebase_key);

        // Pull user data
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
        $safe_uid   = $this->safe_dir($school_uid);
        $school_dir = FCPATH . self::BACKUP_DIR . $safe_uid . '/';
        if (!is_dir($school_dir)) mkdir($school_dir, 0750, true);

        // For full backups, try ZIP
        $use_zip  = ($backup_type === 'full') && extension_loaded('zip');
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
                $zip->addFromString('system_config.json', json_encode($export['SystemConfig'], JSON_PRETTY_PRINT));
                $zip->addFromString('file_manifest.json', json_encode($export['FileManifest'], JSON_PRETTY_PRINT));
                $this->_zip_uploads($zip, $school_uid);
                $zip->close();
                $size_bytes = filesize($filepath);
            } else {
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
        }

        $meta = [
            'backup_id'   => $backup_id,
            'school_name' => $school_uid,
            'filename'    => $filename,
            'backup_type' => $backup_type,
            'format'      => $use_zip ? 'zip' : 'json',
            'size_bytes'  => $size_bytes,
            'size_human'  => $this->human_size($size_bytes),
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

    /**
     * Apply retention policy — delete oldest non-safety backups exceeding $keep count.
     */
    public function apply_retention(string $school_uid, int $keep): void
    {
        $safe_uid = $this->safe_dir($school_uid);
        $raw      = $this->firebase->get("System/Backups/{$safe_uid}") ?? [];
        if (!is_array($raw) || count($raw) <= $keep) return;

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

    /**
     * Get backup list for a single school.
     *
     * @return array Sorted list of backup records (newest first)
     */
    public function get_school_backups(string $school_uid): array
    {
        $safe_uid = $this->safe_dir($school_uid);
        $raw      = $this->firebase->get("System/Backups/{$safe_uid}") ?? [];
        if (!is_array($raw)) return [];

        $rows = [];
        foreach ($raw as $bid => $b) {
            if (!is_array($b)) continue;
            $rows[] = array_merge(['backup_id' => $bid], $b);
        }

        usort($rows, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        return $rows;
    }

    /**
     * Serve a backup file for download.
     * Returns false if file doesn't exist; otherwise sends headers + file and exits.
     */
    public function serve_download(string $school_uid, string $backup_id): bool
    {
        $safe_uid  = $this->safe_dir($school_uid);
        $backup_id = preg_replace('/[^A-Za-z0-9_\-]/', '', $backup_id);

        $meta = $this->firebase->get("System/Backups/{$safe_uid}/{$backup_id}");
        if (!is_array($meta) || empty($meta['filename'])) return false;

        $filename = basename($meta['filename']);
        if (!preg_match('/^[A-Za-z0-9_\-\.]+$/', $filename)) return false;

        $filepath = FCPATH . self::BACKUP_DIR . $safe_uid . '/' . $filename;
        if (!file_exists($filepath)) return false;

        $ctype = (substr($filepath, -4) === '.zip') ? 'application/zip' : 'application/json';
        header('Content-Type: ' . $ctype);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        readfile($filepath);
        exit;
    }

    /**
     * Check whether a backup_id exists for a given school.
     */
    public function backup_belongs_to_school(string $school_uid, string $backup_id): bool
    {
        $safe_uid  = $this->safe_dir($school_uid);
        $backup_id = preg_replace('/[^A-Za-z0-9_\-]/', '', $backup_id);
        $meta      = $this->firebase->get("System/Backups/{$safe_uid}/{$backup_id}");
        return is_array($meta) && !empty($meta['backup_id']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  UTILITIES (public — reusable by callers)
    // ─────────────────────────────────────────────────────────────────────────

    public function safe_dir(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9\-]/', '_', trim($name));
    }

    public function human_size(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576)    return round($bytes / 1048576,    2) . ' MB';
        if ($bytes >= 1024)       return round($bytes / 1024,       1) . ' KB';
        return $bytes . ' B';
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  PRIVATE — Helpers (moved from Superadmin_backups)
    // ─────────────────────────────────────────────────────────────────────────

    private function _get_system_config(): array
    {
        $db_info = [];
        try {
            $db_cfg = $this->CI->config->item('db');
            if ($db_cfg) {
                $db_info = [
                    'hostname' => $db_cfg['hostname'] ?? 'localhost',
                    'database' => $db_cfg['database'] ?? '',
                    'driver'   => $db_cfg['dbdriver'] ?? '',
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
        $safe_uid = $this->safe_dir($school_uid);
        $manifest = [
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
        $manifest['total_size_hr'] = $this->human_size($manifest['total_size']);
        return $manifest;
    }

    private function _zip_uploads(ZipArchive $zip, string $school_uid): void
    {
        $safe_uid = $this->safe_dir($school_uid);
        $base     = FCPATH . self::UPLOADS_DIR;
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
}
