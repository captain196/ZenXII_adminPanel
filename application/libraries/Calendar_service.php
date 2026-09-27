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
    const VALID_TYPES = ['holiday','exam','meeting','event','activity','special_working_day','half_day'];

    /**
     * Workforce Policy Programme Phase D (2026-08-18) — calendar applicability.
     * `appliesTo` answers "who does this event apply TO" (students/staff) —
     * distinct from `visibleTo` above, which only ever answered "who sees
     * this in the UI". Absent/empty on either new field means "no
     * restriction" — every event written before this phase, or any new event
     * that doesn't set these, applies to everyone exactly as it always has.
     * See Working_calendar_service.php for how `special_working_day` and
     * these filters combine into the one canonical precedence model.
     */
    const VALID_APPLIES_TO = ['students','staff'];

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

    /**
     * CAL-PERF-1 — read-through cache for the calendar event list.
     *
     * MEASURED, NOT GUESSED. A cold `sessionWhere('calendarEvents')` costs
     * ~1.9 SECONDS on this deployment, reproducibly, **returning zero
     * documents** — so the cost is the Firestore round trip (TLS + query) per
     * PHP process, not the payload. Measured with
     * `application/controllers/Calendar_perf_probe.php` across three separate
     * processes: 1936 ms, 1899 ms, 1905 ms cold; ~440 ms warm in-process.
     *
     * This method reads the WHOLE event list and filters by month in PHP, and
     * the Academic Planner calls it again on every prev/next month click. So
     * paging through a year of the calendar paid twelve identical ~1.9s reads.
     *
     * The cache is deliberately modelled on `Holiday_service`, which already
     * uses exactly this two-tier shape (per-request memo + short file TTL) over
     * the same collection — this is the established pattern here, not a new one.
     *
     * CORRECTNESS OVER SPEED. `Calendar_service` is the ONLY writer of
     * `calendarEvents`, so both write paths invalidate this cache explicitly.
     * An administrator therefore never sees their own save go missing, which is
     * the failure mode that would make a cache worse than the latency.
     * A read failure caches nothing.
     */
    private const CACHE_TTL     = 120;   // seconds; 0 disables the file tier
    private const CACHE_VERSION = 1;

    /** @var array<string,array> per-request memo, keyed school+session */
    private static $memo = [];

    private function _cacheKey(): string
    {
        return 'cal_events_v' . self::CACHE_VERSION . '_'
             . md5($this->schoolId . '|' . $this->session);
    }

    private function _cacheFile(): ?string
    {
        if (self::CACHE_TTL <= 0 || $this->schoolId === '') return null;
        $dir = APPPATH . 'cache/calendar_events';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return is_dir($dir) ? $dir . '/' . $this->_cacheKey() . '.json' : null;
    }

    /** Called by BOTH write paths. Never let a stale list outlive a save. */
    private function _invalidateCache(): void
    {
        unset(self::$memo[$this->_cacheKey()]);
        $f = $this->_cacheFile();
        if ($f !== null && is_file($f)) @unlink($f);
    }

    /** The raw document list, cached. Null means "could not read". */
    private function _cachedDocs(): ?array
    {
        $key = $this->_cacheKey();
        if (isset(self::$memo[$key])) return self::$memo[$key];

        $file = $this->_cacheFile();
        if ($file !== null && is_file($file)
            && (time() - (int) @filemtime($file)) < self::CACHE_TTL) {
            $raw = @file_get_contents($file);
            $dec = ($raw !== false) ? json_decode($raw, true) : null;
            if (is_array($dec)) { self::$memo[$key] = $dec; return $dec; }
        }

        try {
            $docs = $this->fs->sessionWhere(self::COLLECTION, []);
        } catch (\Throwable $e) {
            // Do NOT cache a failure — a transient error must not persist as
            // "this school has no calendar" for the next two minutes.
            log_message('error', 'Calendar_service::_cachedDocs read failed: ' . $e->getMessage());
            return null;
        }

        $docs = is_array($docs) ? $docs : [];
        self::$memo[$key] = $docs;
        if ($file !== null) @file_put_contents($file, json_encode($docs), LOCK_EX);
        return $docs;
    }

    public function getEvents(string $month = ''): array
    {
        $events = [];
        try {
            $fsDocs = $this->_cachedDocs();
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
        string $startDate, string $endDate, string $description, $visibleToRaw,
        $appliesToRaw = null, $departmentsRaw = null, $exceptStaffRaw = null
    ): array {
        $endDate = $endDate !== '' ? $endDate : $startDate;

        $visibleTo = is_string($visibleToRaw) ? json_decode($visibleToRaw, true) : $visibleToRaw;
        if (!is_array($visibleTo) || empty($visibleTo)) $visibleTo = ['admin','teacher'];
        $visibleTo = array_values(array_intersect($visibleTo, self::ALLOWED_AUDIENCES));
        if (empty($visibleTo)) $visibleTo = ['admin'];

        // Phase D applicability — absent/empty means "no restriction" on
        // every axis, so an event saved without touching these fields (every
        // pre-Phase-D event, and any admin who just leaves them blank)
        // applies to everyone exactly as it always did. Never defaulted to a
        // non-empty value the way visibleTo is above — an empty array here
        // is itself the meaningful, common case, not an error to correct.
        $appliesTo = is_string($appliesToRaw) ? json_decode($appliesToRaw, true) : $appliesToRaw;
        $appliesTo = is_array($appliesTo)
            ? array_values(array_intersect($appliesTo, self::VALID_APPLIES_TO)) : [];

        $departments = is_string($departmentsRaw) ? json_decode($departmentsRaw, true) : $departmentsRaw;
        $departments = is_array($departments)
            ? array_values(array_unique(array_filter(array_map(
                fn($d) => trim((string) $d), $departments
            ), fn($d) => $d !== ''))) : [];

        $exceptStaff = is_string($exceptStaffRaw) ? json_decode($exceptStaffRaw, true) : $exceptStaffRaw;
        $exceptStaff = is_array($exceptStaff)
            ? array_values(array_unique(array_filter(array_map(
                fn($s) => trim((string) $s), $exceptStaff
            ), fn($s) => $s !== ''))) : [];

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
            // ACAD-D17: this null decides CREATE vs UPDATE below. firestoreGet()
            // returns null for a FAILED read exactly as it does for a genuine 404,
            // so a transient failure made an existing record look new: version reset
            // to 1, createdAt/createdBy overwritten, and an audit entry written that
            // says 'create' for what was an update. Fail-loud: null now means ABSENT.
            try { $existing = $this->firebase->firestoreGetOrFail(self::COLLECTION, $fsDocId); }
            catch (\Throwable $e) {
                log_message('error', 'Calendar_service: existing-event read failed (doc='
                    . $fsDocId . '): ' . $e->getMessage());
                throw new Service_exception(
                    'Could not read the event you are editing, so nothing was saved. '
                    . 'Please try again in a moment.',
                    'read'
                );
            }
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
            'appliesTo'     => $appliesTo,     // Phase D: [] = students + staff (no restriction)
            'departments'   => $departments,   // Phase D: [] = every department (no restriction)
            'exceptStaff'   => $exceptStaff,   // Phase D: [] = no individual exception
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
        // CAL-PERF-1 — this class is the ONLY writer, so the cached list must die
        // here or an administrator would not see their own save.
        $this->_invalidateCache();

        $this->_audit(
            is_array($existing) ? 'update' : 'create', 'calendarEvent', $id,
            is_array($existing) ? [
                'title'     => (string)($existing['title']     ?? ''),
                'startDate' => (string)($existing['startDate'] ?? $existing['start_date'] ?? ''),
                'endDate'   => (string)($existing['endDate']   ?? $existing['end_date']   ?? ''),
                'visibleTo' => is_array($existing['visibleTo'] ?? null) ? $existing['visibleTo'] : [],
            ] : null,
            ['title' => $title, 'type' => $type, 'startDate' => $startDate, 'endDate' => $endDate, 'visibleTo' => $visibleTo,
             'appliesTo' => $appliesTo, 'departments' => $departments, 'exceptStaff' => $exceptStaff],
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

        // ACAD-D9: same shape as ACAD-D8 in Substitute_service. The delete was
        // swallowed while _audit() and the success return ran regardless, so a
        // failed removal told the operator the event was gone, wrote an audit
        // entry recording a deletion that never happened, and left the event
        // live. calendarEvents is also where HOLIDAYS live (Holiday_service
        // reads them), so a holiday that "failed to delete" keeps suppressing
        // attendance while the admin believes it was removed.
        // CAL-PERF-1 — invalidate BEFORE the delete as well as after, so a
        // failure part-way cannot leave a list that still contains the event.
        $this->_invalidateCache();
        try { $this->firebase->firestoreDelete(self::COLLECTION, $fsDocId); }
        catch (\Throwable $e) {
            log_message('error', 'Calendar_service::deleteEvent failed (doc='
                . $fsDocId . '): ' . $e->getMessage());
            throw new Service_exception(
                'The event could not be removed. Nothing was changed — '
                . 'please try again in a moment.',
                'write'
            );
        }

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
