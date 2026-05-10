<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'libraries/Service_exception.php';
require_once APPPATH . 'libraries/Entity_firestore_sync.php';

/**
 * Substitute_service — owns substitute teacher record CRUD.
 *
 * Phase 5: extracted from Academic.php with ZERO behavior change.
 * Phase 0 invariants preserved:
 *   - Pure-read get_substitutes (no read-side mutation)
 *   - Canonical schoolId (not school_name) in conflict-check query
 *   - camelCase canonical fields with snake_case mirrors in API response
 *   - Class/section normalization via Entity_firestore_sync
 *   - id-derivation from docId for legacy docs
 * Phase 4 audit logging on every mutation.
 */
class Substitute_service
{
    /** @var object */ private $firebase;
    /** @var object */ private $fs;
    /** @var string */ private $schoolId  = '';
    /** @var string */ private $session   = '';
    /** @var string */ private $adminId   = '';
    /** @var string */ private $adminName = '';
    /** @var object|null */ private $audit = null;
    /** @var bool   */ private $ready = false;

    const COLLECTION = 'substitutes';

    public function init(
        $firebase, $fs,
        string $schoolId, string $session,
        string $adminId, string $adminName,
        $auditLogService = null
    ): self {
        $this->firebase  = $firebase;
        $this->fs        = $fs;
        $this->schoolId  = $schoolId;
        $this->session   = $session;
        $this->adminId   = $adminId;
        $this->adminName = $adminName;
        $this->audit     = $auditLogService;
        $this->ready     = ($firebase !== null && $schoolId !== '');
        return $this;
    }

    public function isReady(): bool { return $this->ready; }

    // ══════════════════════════════════════════════════════════════════

    public function getSubstitutes(string $date = '', string $dateFrom = '', string $dateTo = ''): array
    {
        $records = [];
        try {
            $conditions = [];
            if ($date !== '') $conditions[] = ['date', '==', $date];
            $fsDocs = $this->fs->sessionWhere(self::COLLECTION, $conditions, null, 'ASC', 100);
            if (is_array($fsDocs) && !empty($fsDocs)) {
                $prefix = $this->schoolId . '_';
                foreach ($fsDocs as $doc) {
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $docKey = $doc['id'] ?? '';
                    if ($docKey !== '' && strpos($docKey, $prefix) === 0) {
                        $d['id'] = substr($docKey, strlen($prefix));
                    } elseif (empty($d['id'])) {
                        $d['id'] = $docKey;
                    }
                    $recDate = $d['date'] ?? '';
                    if ($dateFrom !== '' && $recDate < $dateFrom) continue;
                    if ($dateTo   !== '' && $recDate > $dateTo)   continue;
                    $records[] = $this->_responseShape($d);
                }
            }
        } catch (\Throwable $e) {}

        usort($records, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));
        if ($date === '' && $dateFrom === '' && $dateTo === '') {
            $records = array_slice($records, 0, 100);
        }
        return ['substitutes' => $records];
    }

    public function saveSubstitute(
        string $id, string $dateStr,
        string $absentId, string $absentName,
        $assignmentsRaw,
        string $reason,
        // Legacy flat-format fallback fields:
        string $substituteId = '', string $substituteName = '',
        string $classSectionLegacy = '', string $subjectLegacy = '',
        $periodsRawLegacy = null
    ): array {
        if ($dateStr === '' || $absentId === '') {
            throw new Service_exception('Date and absent teacher are required', 'validation');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr) || !strtotime($dateStr)) {
            throw new Service_exception('Invalid date format', 'validation');
        }

        $assignments = is_string($assignmentsRaw) ? json_decode($assignmentsRaw, true) : $assignmentsRaw;

        if (!is_array($assignments) || empty($assignments)) {
            $periods = is_string($periodsRawLegacy) ? json_decode($periodsRawLegacy, true) : $periodsRawLegacy;
            if (!is_array($periods)) $periods = [];
            $periods = array_values(array_unique(array_filter(array_map('intval', $periods), fn($p) => $p >= 1)));

            if ($substituteId === '' || $classSectionLegacy === '' || empty($periods)) {
                throw new Service_exception('Assignments data or legacy fields (substitute, class, periods) required', 'validation');
            }
            if ($absentId === $substituteId) {
                throw new Service_exception('Absent teacher and substitute cannot be the same person', 'validation');
            }

            $assignments = [];
            foreach ($periods as $pn) {
                $assignments[] = [
                    'periodNumber'          => $pn,
                    'subject'               => $subjectLegacy,
                    'className'             => $classSectionLegacy,
                    'section'               => '',
                    'substituteTeacherId'   => $substituteId,
                    'substituteTeacherName' => $substituteName,
                ];
            }
        }

        $dt = new \DateTime($dateStr);
        $dayOfWeek = $dt->format('l');

        // FIX B-8: query timetables by canonical school_id.
        $busyMap = [];
        try {
            $ttDocs = $this->firebase->firestoreQuery('timetables', [
                ['schoolId', '==', $this->schoolId],
                ['session',  '==', $this->session],
                ['day',      '==', $dayOfWeek],
            ], null, 'ASC', 100);
            foreach ($ttDocs as $doc) {
                $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                foreach (($d['periods'] ?? []) as $p) {
                    $tid = $p['teacherId'] ?? '';
                    $pn  = (int) ($p['periodNumber'] ?? 0);
                    if ($tid !== '' && ($p['subject'] ?? '') !== '') $busyMap[$tid][$pn] = true;
                }
            }
        } catch (\Throwable $e) {}

        $existingSubs = [];
        try {
            $fsDocs = $this->fs->sessionWhere(self::COLLECTION, [['date', '==', $dateStr]], null, 'ASC', 200);
            foreach ($fsDocs as $doc) {
                $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                $d['id'] = $d['id'] ?? '';
                if (($d['status'] ?? '') === 'cancelled') continue;
                if ($id !== '' && ($d['id'] ?? '') === $id) continue;
                $existingSubs[] = $d;
            }
        } catch (\Throwable $e) {}

        $cleanAssignments = [];
        foreach ($assignments as $a) {
            $pn    = (int) ($a['periodNumber'] ?? 0);
            $subId = trim($a['substituteTeacherId']   ?? $a['substitute_teacher_id']   ?? '');
            $subNm = trim($a['substituteTeacherName'] ?? $a['substitute_teacher_name'] ?? '');
            if ($pn < 1 || $subId === '') continue;
            if ($subId === $absentId) {
                throw new Service_exception("Cannot assign absent teacher as substitute for Period {$pn}", 'validation');
            }
            if (isset($busyMap[$subId][$pn])) {
                throw new Service_exception("{$subNm} already teaches their own class during Period {$pn} on {$dayOfWeek}", 'conflict');
            }
            foreach ($existingSubs as $ex) {
                $exAssigns = $ex['assignments'] ?? [];
                if (!empty($exAssigns)) {
                    foreach ($exAssigns as $ea) {
                        $eaSubId = $ea['substituteTeacherId'] ?? $ea['substitute_teacher_id'] ?? '';
                        if ((int)($ea['periodNumber'] ?? 0) === $pn && $eaSubId === $subId) {
                            throw new Service_exception("{$subNm} is already covering another substitution at Period {$pn} on this date", 'conflict');
                        }
                    }
                }
                $exFlatSubId = $ex['substituteTeacherId'] ?? $ex['substitute_teacher_id'] ?? '';
                if ($exFlatSubId === $subId) {
                    $exPeriods = $ex['periods'] ?? [];
                    if (is_array($exPeriods) && in_array($pn, $exPeriods)) {
                        throw new Service_exception("{$subNm} is already covering another substitution at Period {$pn} on this date", 'conflict');
                    }
                }
            }
            foreach ($existingSubs as $ex) {
                $exAbsent = $ex['absentTeacherId'] ?? $ex['absent_teacher_id'] ?? '';
                if ($exAbsent !== $absentId) continue;
                $exAssigns = $ex['assignments'] ?? [];
                if (!empty($exAssigns)) {
                    foreach ($exAssigns as $ea) {
                        if ((int)($ea['periodNumber'] ?? 0) === $pn) {
                            throw new Service_exception("Period {$pn} for this teacher is already covered by another substitute record", 'conflict');
                        }
                    }
                }
                $exPeriods = $ex['periods'] ?? [];
                if (is_array($exPeriods) && in_array($pn, $exPeriods)) {
                    throw new Service_exception("Period {$pn} for this teacher is already covered by another substitute record", 'conflict');
                }
            }

            $rawClass = trim($a['className'] ?? '');
            $rawSec   = trim($a['section']   ?? '');
            if ($rawSec === '' && strpos($rawClass, '/') !== false) {
                $slash = strrpos($rawClass, '/');
                $rawSec   = trim(substr($rawClass, $slash + 1));
                $rawClass = trim(substr($rawClass, 0, $slash));
            }
            $cs = \Entity_firestore_sync::normalizeClassSection($rawClass, $rawSec);
            $cleanClass   = $cs['className']  !== '' ? $cs['className']  : $rawClass;
            $cleanSection = $cs['section']    !== '' ? $cs['section']    : $rawSec;
            $sectionKey   = ($cleanClass !== '' && $cleanSection !== '')
                ? "{$cleanClass}/{$cleanSection}" : '';

            $cleanAssignments[] = [
                'periodNumber'          => $pn,
                'subject'               => trim($a['subject'] ?? ''),
                'className'             => $cleanClass,
                'section'               => $cleanSection,
                'classOrder'            => $cs['classOrder'],
                'sectionCode'           => $cs['sectionCode'],
                'sectionKey'            => $sectionKey,
                'substituteTeacherId'   => $subId,
                'substituteTeacherName' => $subNm,
            ];
        }

        if (empty($cleanAssignments)) {
            throw new Service_exception('No valid period assignments found', 'validation');
        }
        usort($cleanAssignments, fn($a, $b) => $a['periodNumber'] - $b['periodNumber']);

        $now = date('c');
        $data = [
            'date'              => $dateStr,
            'dayOfWeek'         => $dayOfWeek,
            'absentTeacherId'   => $absentId,
            'absentTeacherName' => $absentName,
            'assignments'       => $cleanAssignments,
            'reason'            => $reason,
            'updatedAt'         => $now,
            'updatedByUid'      => $this->adminId,
            'updatedByName'     => $this->adminName,
        ];

        $current = null;
        if ($id !== '') {
            $fsDocId = "{$this->schoolId}_{$id}";
            try { $current = $this->firebase->firestoreGet(self::COLLECTION, $fsDocId); }
            catch (\Throwable $e) {}
        } else {
            $id      = 'SUB_' . uniqid();
            $fsDocId = "{$this->schoolId}_{$id}";
        }

        if (is_array($current)) {
            $data['status']        = $current['status']        ?? 'assigned';
            $data['createdAt']     = $current['createdAt']     ?? $current['created_at'] ?? $now;
            $data['createdByUid']  = $current['createdByUid']  ?? $this->adminId;
            $data['createdByName'] = $current['createdByName'] ?? $current['created_by'] ?? $this->adminName;
            $data['version']       = ((int)($current['version'] ?? 0)) + 1;
        } else {
            $data['status']        = 'assigned';
            $data['createdAt']     = $now;
            $data['createdByUid']  = $this->adminId;
            $data['createdByName'] = $this->adminName;
            $data['version']       = 1;
        }

        $fsData = array_merge($data, [
            'id'       => $id,
            'schoolId' => $this->schoolId,
            'session'  => $this->session,
        ]);
        $this->firebase->firestoreSet(self::COLLECTION, $fsDocId, $fsData, true);

        $this->_audit(
            is_array($current) ? 'update' : 'create', 'substitute', $id,
            is_array($current) ? [
                'status'           => (string)($current['status'] ?? ''),
                'assignmentsCount' => is_array($current['assignments'] ?? null) ? count($current['assignments']) : 0,
            ] : null,
            ['status' => $data['status'], 'date' => $dateStr, 'absentTeacherId' => $absentId, 'assignmentsCount' => count($cleanAssignments)],
            []
        );

        return [
            'id'                => $id,
            'assignments_count' => count($cleanAssignments),
            'version'           => $data['version'],
        ];
    }

    public function updateSubstituteStatus(string $id, string $status): array
    {
        if ($id === '') throw new Service_exception('Substitute ID required', 'validation');
        if (!in_array($status, ['assigned','completed','cancelled'])) {
            throw new Service_exception('Invalid status', 'validation');
        }

        $now = date('c');
        $fsDocId = "{$this->schoolId}_{$id}";
        $current = null;
        try { $current = $this->firebase->firestoreGet(self::COLLECTION, $fsDocId); }
        catch (\Throwable $e) {}
        $newVersion = (is_array($current) ? (int)($current['version'] ?? 0) : 0) + 1;

        $this->firebase->firestoreSet(self::COLLECTION, $fsDocId, [
            'status'        => $status,
            'updatedAt'     => $now,
            'updatedByUid'  => $this->adminId,
            'updatedByName' => $this->adminName,
            'version'       => $newVersion,
        ], true);

        $this->_audit(
            'status_change', 'substitute', $id,
            ['status' => is_array($current) ? (string)($current['status'] ?? '') : ''],
            ['status' => $status],
            []
        );

        return ['id' => $id, 'newStatus' => $status, 'version' => $newVersion];
    }

    public function deleteSubstitute(string $id): array
    {
        if ($id === '') throw new Service_exception('Substitute ID required', 'validation');

        $fsDocId = "{$this->schoolId}_{$id}";
        $before = null;
        try {
            $existing = $this->firebase->firestoreGet(self::COLLECTION, $fsDocId);
            if (is_array($existing)) {
                $before = [
                    'date'             => (string)($existing['date'] ?? ''),
                    'status'           => (string)($existing['status'] ?? ''),
                    'absentTeacherId'  => (string)($existing['absentTeacherId'] ?? $existing['absent_teacher_id'] ?? ''),
                    'assignmentsCount' => is_array($existing['assignments'] ?? null) ? count($existing['assignments']) : 0,
                ];
            }
        } catch (\Throwable $e) {}

        try { $this->firebase->firestoreDelete(self::COLLECTION, $fsDocId); }
        catch (\Throwable $e) {}

        $this->_audit('delete', 'substitute', $id, $before, null, []);
        return [];
    }

    private function _responseShape(array $d): array
    {
        $absentId   = $d['absentTeacherId']   ?? $d['absent_teacher_id']   ?? '';
        $absentName = $d['absentTeacherName'] ?? $d['absent_teacher_name'] ?? '';
        $createdBy  = $d['createdByName']     ?? $d['created_by']          ?? '';
        $updatedBy  = $d['updatedByName']     ?? $d['updated_by']          ?? '';

        $d['absentTeacherId']     = $absentId;
        $d['absent_teacher_id']   = $absentId;
        $d['absentTeacherName']   = $absentName;
        $d['absent_teacher_name'] = $absentName;
        $d['createdByName']       = $createdBy;
        $d['created_by']          = $createdBy;
        $d['updatedByName']       = $updatedBy;
        $d['updated_by']          = $updatedBy;

        if (isset($d['assignments']) && is_array($d['assignments'])) {
            $d['assignments'] = array_map(function ($a) {
                if (!is_array($a)) return $a;
                $sid = $a['substituteTeacherId']   ?? $a['substitute_teacher_id']   ?? '';
                $snm = $a['substituteTeacherName'] ?? $a['substitute_teacher_name'] ?? '';
                $a['substituteTeacherId']     = $sid;
                $a['substitute_teacher_id']   = $sid;
                $a['substituteTeacherName']   = $snm;
                $a['substitute_teacher_name'] = $snm;
                return $a;
            }, $d['assignments']);
        }
        return $d;
    }

    private function _audit(string $action, string $entityType, string $entityId, ?array $before, ?array $after, array $metadata): void
    {
        if (!$this->audit) return;
        try { $this->audit->log($action, $entityType, $entityId, $before, $after, $metadata); }
        catch (\Throwable $e) { log_message('error', 'substitute audit failed: ' . $e->getMessage()); }
    }
}
