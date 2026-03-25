<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Audit Helper — Centralized ERP Audit Logging
 *
 * Writes structured audit entries to Firebase:
 *   Schools/{school}/AuditLogs/{logId}
 *
 * Usage (from any controller extending MY_Controller):
 *   log_audit('Fees', 'collect_fee', 'RCPT00032', 'Collected fee');
 *
 * Automatically captures: admin user, role, school, IP, timestamp.
 * Non-blocking — failures are logged but never interrupt the request.
 */

/**
 * Write an audit log entry to Firebase.
 *
 * @param string $module      Module name (e.g. 'Fees', 'SIS', 'HR')
 * @param string $action      Action key (e.g. 'collect_fee', 'create_student')
 * @param string $entityId    ID of the affected entity (receipt, student, admin, etc.)
 * @param string $description Human-readable description of the action
 * @return void
 */
function log_audit(string $module, string $action, string $entityId = '', string $description = ''): void
{
    try {
        $CI =& get_instance();

        $school = $CI->session->userdata('schoolName') ?? '';
        if (empty($school) || !isset($CI->firebase)) {
            return; // No school context or Firebase not loaded — skip silently
        }

        $now   = gmdate('Y-m-d\TH:i:s\Z');
        $logId = 'AL_' . date('Ymd_His') . '_' . substr(uniqid('', true), -6);

        $entry = [
            'userId'      => $CI->session->userdata('admin_id') ?? '',
            'userName'    => $CI->session->userdata('admin_name') ?? '',
            'userRole'    => $CI->session->userdata('admin_role') ?? '',
            'module'      => $module,
            'action'      => $action,
            'entityId'    => $entityId,
            'description' => $description,
            'ipAddress'   => $CI->input->ip_address(),
            'timestamp'   => $now,
        ];

        $CI->firebase->set("Schools/{$school}/AuditLogs/{$logId}", $entry);
    } catch (\Exception $e) {
        log_message('error', 'audit_helper::log_audit failed: ' . $e->getMessage());
    }
}

/**
 * Get the total count of audit logs for a school (used for archival).
 *
 * @param  object $firebase    Firebase library instance
 * @param  string $school      School identifier
 * @return int
 */
function audit_log_count($firebase, string $school): int
{
    try {
        $keys = $firebase->shallow_get("Schools/{$school}/AuditLogs");
        return is_array($keys) ? count($keys) : 0;
    } catch (\Exception $e) {
        return 0;
    }
}

/**
 * Archive oldest logs when count exceeds the limit.
 * Moves oldest entries to Schools/{school}/AuditArchive/.
 *
 * @param  object $firebase  Firebase library instance
 * @param  string $school    School identifier
 * @param  int    $limit     Max logs to keep (default 10000)
 * @return int               Number of logs archived
 */
function audit_archive_old($firebase, string $school, int $limit = 10000): int
{
    try {
        $all = $firebase->get("Schools/{$school}/AuditLogs");
        if (!is_array($all) || count($all) <= $limit) {
            return 0;
        }

        // Sort by timestamp ascending (oldest first)
        uasort($all, function ($a, $b) {
            return strcmp($a['timestamp'] ?? '', $b['timestamp'] ?? '');
        });

        $toArchive = count($all) - $limit;
        $archived  = 0;
        $keys      = array_keys($all);

        for ($i = 0; $i < $toArchive; $i++) {
            $key   = $keys[$i];
            $entry = $all[$key];

            // Write to archive
            $firebase->set("Schools/{$school}/AuditArchive/{$key}", $entry);
            // Remove from active logs
            $firebase->delete("Schools/{$school}/AuditLogs", $key);
            $archived++;
        }

        return $archived;
    } catch (\Exception $e) {
        log_message('error', 'audit_archive_old failed: ' . $e->getMessage());
        return 0;
    }
}
