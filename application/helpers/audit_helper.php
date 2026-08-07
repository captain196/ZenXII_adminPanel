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
function log_audit(string $module, string $action, string $entityId = '', string $description = '', array $context = []): void
{
    try {
        $CI =& get_instance();

        // Firestore service must be loaded and initialized — if not, the
        // controller is running before MY_Controller::__construct's fs
        // bootstrap (rare). Degrade silently.
        // Degrade silently unless `fs` is the Firestore_service gateway that
        // provides isReady()/schoolId(). Some controllers (Result,
        // Examination) rebind `fs` to Firestore_helper, which lacks both —
        // calling them there would fatal. method_exists keeps audit logging
        // intact for Firestore_service and a safe no-op elsewhere.
        if (!isset($CI->fs)
            || !method_exists($CI->fs, 'isReady')
            || !method_exists($CI->fs, 'schoolId')
            || !$CI->fs->isReady()) {
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

        // M7-A — optional, additive correlation/context. Backward compatible:
        // legacy 4-arg callers pass no $context, so the schema is unchanged for
        // them. `appId` is the canonical Admission-application correlation key
        // (queryable via the auditLogs (schoolId, appId, timestampMs) index);
        // `entityType` names the affected entity; `meta` carries bounded old/new
        // state. No parallel audit store is introduced — this is the same
        // canonical `auditLogs` collection.
        if (!empty($context['appId']))      $entry['appId']      = (string) $context['appId'];
        if (!empty($context['entityType'])) $entry['entityType'] = (string) $context['entityType'];
        if (isset($context['meta']) && is_array($context['meta']) && $context['meta'] !== []) {
            $entry['meta'] = $context['meta'];
        }

        $CI->fs->set('auditLogs', $CI->fs->docId($logId), $entry);
    } catch (\Exception $e) {
        log_message('error', 'audit_helper::log_audit failed: ' . $e->getMessage());
    }
}

/**
 * Reconstruct the COMPLETE permanent audit trail for one Admission application,
 * unioning the active `auditLogs` and the `auditArchive` collections (archival
 * moves oldest entries out of auditLogs, so a single-collection read is NOT a
 * complete trail). Correlates on the additive `appId` field. Newest-first.
 *
 * Tenant-safe: every query is schoolId-scoped. Read-only.
 *
 * @return array merged rows [{...entry, id}], deduped by doc id, newest-first
 */
function audit_trail_for_app(string $appId, int $limit = 500): array
{
    try {
        $CI =& get_instance();
        if (!isset($CI->fs) || !method_exists($CI->fs, 'isReady') || !method_exists($CI->fs, 'schoolId') || !$CI->fs->isReady()) return [];
        $schoolId = $CI->fs->schoolId();
        if ($schoolId === '' || $appId === '') return [];

        $merged = [];
        foreach (['auditLogs', 'auditArchive'] as $col) {
            $rows = $CI->fs->where($col, [['schoolId', '==', $schoolId], ['appId', '==', $appId]], 'timestampMs', 'DESC', $limit);
            foreach ((array) $rows as $row) {
                if (!is_array($row)) continue;
                $data = $row['data'] ?? null;
                if (!is_array($data)) continue;
                $id = (string) ($row['id'] ?? ($data['logId'] ?? ''));
                if ($id !== '') $merged[$id] = $data; // dedupe by stable doc id (archival preserves ids)
            }
        }
        $out = array_values($merged);
        usort($out, fn($a, $b) => ((int) ($b['timestampMs'] ?? 0)) <=> ((int) ($a['timestampMs'] ?? 0)));
        return array_slice($out, 0, $limit);
    } catch (\Exception $e) {
        log_message('error', 'audit_trail_for_app failed: ' . $e->getMessage());
        return [];
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
        if (!isset($CI->fs) || !method_exists($CI->fs, 'isReady') || !method_exists($CI->fs, 'schoolId') || !$CI->fs->isReady()) return 0;

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
        if (!isset($CI->fs) || !method_exists($CI->fs, 'isReady') || !method_exists($CI->fs, 'schoolId') || !$CI->fs->isReady()) return 0;

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
