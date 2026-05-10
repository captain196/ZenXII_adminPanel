<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'libraries/Service_exception.php';

/**
 * Calendar_service — owns calendarEvents CRUD.
 *
 * Phase 5: extracted from Academic.php with ZERO behavior change.
 * Phase 0 (snake/camel dual-shape, visibleTo audience), Phase 4 (audit
 * logging) invariants preserved.
 */
class Calendar_service
{
    /** @var object */ private $firebase;
    /** @var object */ private $fs;
    /** @var string */ private $schoolId  = '';
    /** @var string */ private $session   = '';
    /** @var string */ private $adminId   = '';
    /** @var string */ private $adminName = '';
    /** @var object|null */ private $audit = null;
    /** @var bool   */ private $ready = false;

    const COLLECTION = 'calendarEvents';
    const ALLOWED_AUDIENCES = ['admin','teacher','parent'];
    const VALID_TYPES = ['holiday','exam','meeting','event','activity'];

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

    public function getEvents(string $month = ''): array
    {
        $events = [];
        try {
            $fsDocs = $this->fs->sessionWhere(self::COLLECTION, []);
            if (is_array($fsDocs) && !empty($fsDocs)) {
                $prefix = $this->schoolId . '_';
                foreach ($fsDocs as $doc) {
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    // Derive `id` from docId (strip {schoolId}_ prefix) so the
                    // calendar grid's edit/delete onclick has a valid id.
                    // Legacy docs never stored `id` as a field — same bug
                    // pattern as substitutes (Phase-0 F-5).
                    $docKey = $doc['id'] ?? '';
                    if ($docKey !== '' && strpos($docKey, $prefix) === 0) {
                        $d['id'] = substr($docKey, strlen($prefix));
                    } elseif (empty($d['id'])) {
                        $d['id'] = $docKey;
                    }
                    $startDate = $d['startDate'] ?? $d['start_date'] ?? '';
                    $endDate   = $d['endDate']   ?? $d['end_date']   ?? $startDate;
                    if ($month !== '') {
                        $monthStart = $month . '-01';
                        $monthEnd   = date('Y-m-t', strtotime($monthStart));
                        if ($endDate < $monthStart || $startDate > $monthEnd) continue;
                    }
                    $d['startDate']  = $startDate;
                    $d['endDate']    = $endDate;
                    $d['start_date'] = $startDate;
                    $d['end_date']   = $endDate;
                    $events[] = $d;
                }
            }
        } catch (\Throwable $e) {}

        usort($events, fn($a, $b) => strcmp($a['startDate'] ?? '', $b['startDate'] ?? ''));
        return ['events' => $events];
    }

    public function saveEvent(
        string $id, string $title, string $type,
        string $startDate, string $endDate, string $description, $visibleToRaw
    ): array {
        $endDate = $endDate !== '' ? $endDate : $startDate;

        $visibleTo = is_string($visibleToRaw) ? json_decode($visibleToRaw, true) : $visibleToRaw;
        if (!is_array($visibleTo) || empty($visibleTo)) $visibleTo = ['admin','teacher'];
        $visibleTo = array_values(array_intersect($visibleTo, self::ALLOWED_AUDIENCES));
        if (empty($visibleTo)) $visibleTo = ['admin'];

        if ($title === '' || $startDate === '') {
            throw new Service_exception('Title and start date are required', 'validation');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !strtotime($startDate)) {
            throw new Service_exception('Invalid start date format', 'validation');
        }
        if ($endDate !== $startDate && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) || !strtotime($endDate))) {
            throw new Service_exception('Invalid end date format', 'validation');
        }
        if ($endDate < $startDate) {
            throw new Service_exception('End date cannot be before start date', 'validation');
        }
        if (!in_array($type, self::VALID_TYPES, true)) $type = 'event';

        $now = date('c');
        $existing = null;
        $isNew = ($id === '');
        if (!$isNew) {
            $fsDocId = "{$this->schoolId}_{$id}";
            try { $existing = $this->firebase->firestoreGet(self::COLLECTION, $fsDocId); }
            catch (\Throwable $e) {}
        } else {
            $id = 'EVT_' . uniqid();
            $fsDocId = "{$this->schoolId}_{$id}";
        }

        $currVersion = is_array($existing) ? (int)($existing['version'] ?? 0) : 0;

        $data = [
            'title'         => $title,
            'type'          => $type,
            'startDate'     => $startDate,
            'endDate'       => $endDate,
            'description'   => $description,
            'visibleTo'     => $visibleTo,
            'updatedAt'     => $now,
            'updatedByUid'  => $this->adminId,
            'updatedByName' => $this->adminName,
            'version'       => $currVersion + 1,
        ];
        if (!is_array($existing)) {
            $data['createdAt']     = $now;
            $data['createdByUid']  = $this->adminId;
            $data['createdByName'] = $this->adminName;
        }
        $data['id']       = $id;          // self-describing — reader uses this directly
        $data['schoolId'] = $this->schoolId;
        $data['session']  = $this->session;

        $this->firebase->firestoreSet(self::COLLECTION, $fsDocId, $data, true);

        $this->_audit(
            is_array($existing) ? 'update' : 'create', 'calendarEvent', $id,
            is_array($existing) ? [
                'title'     => (string)($existing['title']     ?? ''),
                'startDate' => (string)($existing['startDate'] ?? $existing['start_date'] ?? ''),
                'endDate'   => (string)($existing['endDate']   ?? $existing['end_date']   ?? ''),
                'visibleTo' => is_array($existing['visibleTo'] ?? null) ? $existing['visibleTo'] : [],
            ] : null,
            ['title' => $title, 'type' => $type, 'startDate' => $startDate, 'endDate' => $endDate, 'visibleTo' => $visibleTo],
            []
        );

        $eventResp = $data;
        $eventResp['start_date'] = $startDate;
        $eventResp['end_date']   = $endDate;
        return ['id' => $id, 'event' => $eventResp];
    }

    public function deleteEvent(string $id): array
    {
        if ($id === '') throw new Service_exception('Event ID required', 'validation');

        $fsDocId = "{$this->schoolId}_{$id}";
        $before = null;
        try {
            $existing = $this->firebase->firestoreGet(self::COLLECTION, $fsDocId);
            if (is_array($existing)) {
                $before = [
                    'title'     => (string)($existing['title']     ?? ''),
                    'startDate' => (string)($existing['startDate'] ?? $existing['start_date'] ?? ''),
                    'type'      => (string)($existing['type']      ?? ''),
                ];
            }
        } catch (\Throwable $e) {}

        try { $this->firebase->firestoreDelete(self::COLLECTION, $fsDocId); }
        catch (\Throwable $e) {}

        $this->_audit('delete', 'calendarEvent', $id, $before, null, []);
        return [];
    }

    private function _audit(string $action, string $entityType, string $entityId, ?array $before, ?array $after, array $metadata): void
    {
        if (!$this->audit) return;
        try { $this->audit->log($action, $entityType, $entityId, $before, $after, $metadata); }
        catch (\Throwable $e) { log_message('error', 'calendar audit failed: ' . $e->getMessage()); }
    }
}
