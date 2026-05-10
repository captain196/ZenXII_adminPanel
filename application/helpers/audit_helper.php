<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Audit Helper — Centralized ERP Audit Logging (Firestore)
 *
 * Writes structured audit entries to the `auditLogs` collection.
 *   Collection: auditLogs
 *   Doc ID:     {schoolId}_{logId}
 *
 * Migrated 2026-05-05 from RTDB (Schools/{school}/AuditLogs/{logId}) to
 * Firestore per the no-RTDB policy. The previous RTDB write was the
 * dominant latency cost on every admin action — on slow networks the
 * Kreait SDK's transient-retry path could block 10–20s before failing,
 * which manifested as the Red Flag create form taking ~24s end-to-end.
 *
 * Usage (from any controller extending MY_Controller):
 *   log_audit('Fees', 'collect_fee', 'RCPT00032', 'Collected fee');
 *
 * Non-blocking semantics: failures are caught and logged but never
 * interrupt the request. If the Firestore service is not yet loaded
 * the function returns silently — admin pages stay usable even if the
 * audit subsystem is down.
 */

/**
 * Write an audit log entry to Firestore.
 *
 * @param string $module      Module name (e.g. 'Fees', 'SIS', 'HR')
 * @param string $action      Action key (e.g. 'collect_fee', 'create_student')
 * @param string $entityId    ID of the affected entity
 * @param string $description Human-readable description
 * @return void
 */
function log_audit(string $module, string $action, string $entityId = '', string $description = ''): void
{
    try {
        $CI =& get_instance();

        // Firestore service must be loaded and initialized — if not, the
        // controller is running before MY_Controller::__construct's fs
        // bootstrap (rare). Degrade silently.
        if (!isset($CI->fs) || !$CI->fs->isReady()) {
            return;
        }
        $schoolId = $CI->fs->schoolId();
        if ($schoolId === '') return;

        $nowMs = (int) round(microtime(true) * 1000);
        $logId = 'AL_' . date('Ymd_His') . '_' . substr(uniqid('', true), -6);

        $entry = [
            'schoolId'    => $schoolId,
            'logId'       => $logId,
            'userId'      => $CI->session->userdata('admin_id') ?? '',
            'userName'    => $CI->session->userdata('admin_name') ?? '',
            'userRole'    => $CI->session->userdata('admin_role') ?? '',
            'module'      => $module,
            'action'      => $action,
            'entityId'    => $entityId,
            'description' => $description,
            'ipAddress'   => $CI->input->ip_address(),
            'timestamp'   => gmdate('Y-m-d\TH:i:s\Z', (int) ($nowMs / 1000)),
            'timestampMs' => $nowMs,
        ];

        $CI->fs->set('auditLogs', $CI->fs->docId($logId), $entry);
    } catch (\Exception $e) {
        log_message('error', 'audit_helper::log_audit failed: ' . $e->getMessage());
    }
}

/**
 * Total count of audit logs for the current school.
 *
 * Signature kept for backward compatibility with legacy callers that
 * passed `$firebase` and `$school`; both args are now ignored — the
 * count is derived from the loaded Firestore service.
 *
 * @return int
 */
function audit_log_count($firebase = null, string $school = ''): int
{
    try {
        $CI =& get_instance();
        if (!isset($CI->fs) || !$CI->fs->isReady()) return 0;

        $schoolId = $CI->fs->schoolId();
        if ($schoolId === '') return 0;

        return $CI->fs->count('auditLogs', [['schoolId', '==', $schoolId]]);
    } catch (\Exception $e) {
        return 0;
    }
}

/**
 * Move oldest audit logs into the `auditArchive` collection when the
 * active log count exceeds $limit. Preserves doc IDs so an audit-trail
 * search can union the two collections cleanly.
 *
 * @return int  Number of entries archived.
 */
function audit_archive_old($firebase = null, string $school = '', int $limit = 10000): int
{
    try {
        $CI =& get_instance();
        if (!isset($CI->fs) || !$CI->fs->isReady()) return 0;

        $schoolId = $CI->fs->schoolId();
        if ($schoolId === '') return 0;

        $totalCount = $CI->fs->count('auditLogs', [['schoolId', '==', $schoolId]]);
        if ($totalCount <= $limit) return 0;

        $toArchive = $totalCount - $limit;

        // Fetch oldest entries (ascending by timestampMs).
        $oldest = $CI->fs->where(
            'auditLogs',
            [['schoolId', '==', $schoolId]],
            'timestampMs',
            'ASC',
            $toArchive
        );

        $archived = 0;
        foreach ($oldest as $row) {
            $docId = (string) ($row['id'] ?? '');
            $data  = $row['data'] ?? null;
            if ($docId === '' || !is_array($data)) continue;

            if ($CI->fs->set('auditArchive', $docId, $data)
                && $CI->fs->remove('auditLogs', $docId)) {
                $archived++;
            }
        }
        return $archived;
    } catch (\Exception $e) {
        log_message('error', 'audit_archive_old failed: ' . $e->getMessage());
        return 0;
    }
}
