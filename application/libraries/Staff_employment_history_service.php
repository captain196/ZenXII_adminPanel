<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Staff_employment_history_service — Phase 1 (Position/Department/EmploymentType only).
 *
 * THE writer for `staffEmploymentEvents` (immutable append-only) and
 * `staffEmploymentTimeline` (derived, effective-dated projection). Mirrors
 * Student_history_service's event-log mechanism (create-if-not-exists,
 * {schoolId}_{staffId}_{eventKey} ids) and Sr_no_service::relink's
 * commitBatch+CAS+bounded-retry pattern for the timeline close/open pair —
 * no new concurrency primitive is introduced.
 *
 * Source-of-truth hierarchy (locked by design decision):
 *   staff                    = authoritative CURRENT state
 *   staffEmploymentEvents    = authoritative IMMUTABLE historical record
 *   staffEmploymentTimeline  = DERIVED effective-dated projection, rebuildable
 *                              from the event log; never independently edited.
 *
 * Deliberately excluded from this class's responsibility: RBAC (staffRoles[],
 * primary_role, staffCapabilities), Academic Assignment (subjectAssignments,
 * sections.classTeacherId), Attendance/work_schedules, Payroll/salaryStructure,
 * Examination/Result. This service never reads or writes any of those.
 *
 * Sequencing (documented deviation from literal single-commit atomicity,
 * per the approved design's own allowance for a justified different
 * sequence): the `staff` document's current-state fields for
 * position/department/employmentType are written by the EXISTING,
 * unmodified Staff.php code paths (new_staff/_commit_staff_row/edit_staff),
 * because those fields are computed inline among ~15 other interdependent
 * fields (including the RBAC-adjacent primary_role -> Position derivation)
 * in live, heavily-tested code this phase is not authorized to restructure.
 * This service is invoked strictly AFTER that write is confirmed to have
 * succeeded, and performs ONE atomic commitBatch for the event(s) +
 * timeline entries. Guarantee actually delivered: an event/timeline entry
 * can never exist without a preceding, already-successful current-state
 * write. The remaining one-directional gap — current-state write succeeds,
 * this service's batch then fails — is narrow, logged as an error, and
 * surfaced to the caller as a soft warning (mirroring the existing
 * authWarning/phoneIndexWarning pattern in Staff.php), never silently
 * swallowed. It is NOT auto-healed, because with no event ever written
 * there is nothing for the rebuild-from-events procedure to replay.
 */
class Staff_employment_history_service
{
    public const EVENT_COL    = 'staffEmploymentEvents';
    public const TIMELINE_COL = 'staffEmploymentTimeline';

    /**
     * Read caches for resolveAttributeAsOf(). Request-scoped only — this service
     * is rebuilt per request, so there is no cross-request staleness to manage.
     *
     * $warmIndex is populated by warmTimeline() in ONE query and keyed
     * "staffId|attribute"; $rowCache memoizes individual per-staff reads for
     * callers that never warm. See warmTimeline() for the N+1 this removes.
     */
    private bool  $warmed    = false;
    private array $warmIndex = [];
    private array $rowCache  = [];

    /** Phase 1+2C+3B.1 locked vocabulary — do not extend without a new authorized phase.
     *  'employmentStatus' (Phase 3B.1) is deliberately NEVER driven through
     *  recordChanges()'s generic {ATTR}_CHANGED derivation — its event types
     *  are the explicit SEPARATED/REACTIVATED/REHIRED vocabulary, set via
     *  recordEmploymentStatusChange() only. It IS included here so
     *  recordJoined()'s day-one snapshot covers it (every new hire's
     *  employmentStatus timeline correctly starts at 'Active'). */
    public const ATTRIBUTES = ['position', 'department', 'employmentType', 'reportingManagerId', 'employmentStatus'];

    public const EMPLOYMENT_STATUS_COL = 'staffSeparations';

    /** Bounded CAS retry — mirrors Sr_no_service::MAX_RETRIES's shape, deliberately
     *  smaller: HR attribute edits are low-contention compared to SRNO claims. */
    private const MAX_RETRIES = 2; // up to 3 total attempts

    /** @var object|null Firestore_service instance ($this->fs on the controller). */
    private $fs = null;
    private string $schoolId = '';
    private string $actor = '';

    public function init($fs, string $schoolId, string $actorId): self
    {
        $this->fs       = $fs;
        $this->schoolId = $schoolId;
        $this->actor    = trim($actorId);
        return $this;
    }

    private function ready(): bool
    {
        return is_object($this->fs) && $this->schoolId !== '';
    }

    private function eventKey(): string
    {
        return date('YmdHis') . '_' . bin2hex(random_bytes(3));
    }

    private function eventDocId(string $eventKey): string
    {
        return "{$this->schoolId}_{$eventKey}";
    }

    private function timelineDocId(string $staffId, string $attribute, string $eventKey): string
    {
        return "{$this->schoolId}_{$staffId}_{$attribute}_{$eventKey}";
    }

    /**
     * JOINED — the anchor event for a newly created staff member. One event,
     * up to 3 timeline entries (Phase 1 attributes), all in one commitBatch.
     * A blank value is still recorded (an explicit "unset at day one" entry)
     * so all three streams start consistently rather than some attributes
     * having no entry until their first edit.
     *
     * @param array $attributes ['position'=>string,'department'=>string,'employmentType'=>string]
     * @return array ['ok'=>bool,'reason'=>string,'eventId'=>string|null]
     */
    public function recordJoined(string $staffId, array $attributes, string $effectiveFrom = ''): array
    {
        if (!$this->ready() || $staffId === '') {
            return ['ok' => false, 'reason' => 'not_initialized', 'eventId' => null];
        }
        $effectiveFrom = $effectiveFrom !== '' ? $effectiveFrom : date('Y-m-d');
        $eventKey      = $this->eventKey();
        $eventId       = $this->eventDocId($eventKey);
        $now           = date('Y-m-d H:i:s');

        $snapshot = [];
        foreach (self::ATTRIBUTES as $attr) {
            $snapshot[$attr] = (string) ($attributes[$attr] ?? '');
        }

        $ops = [[
            'op'           => 'update',
            'collection'   => self::EVENT_COL,
            'docId'        => $eventId,
            'data'         => [
                'schoolId'         => $this->schoolId,
                'staffId'          => $staffId,
                'eventType'        => 'JOINED',
                'attribute'        => '',
                'fromValue'        => '',
                'toValue'          => '',
                'effectiveFrom'    => $effectiveFrom,
                'changed_by'       => $this->actor,
                'changed_at'       => $now,
                'reason'           => '',
                'correctsEventRef' => '',
                'reversesEventRef' => '',
                'metadata'         => $snapshot,
            ],
            'merge'        => false,
            'precondition' => ['exists' => false],
        ]];

        foreach (self::ATTRIBUTES as $attr) {
            $ops[] = [
                'op'           => 'update',
                'collection'   => self::TIMELINE_COL,
                'docId'        => $this->timelineDocId($staffId, $attr, $eventKey),
                'data'         => [
                    'schoolId'      => $this->schoolId,
                    'staffId'       => $staffId,
                    'attribute'     => $attr,
                    'value'         => $snapshot[$attr],
                    'effectiveFrom' => $effectiveFrom,
                    'effectiveTo'   => null,
                    'eventRef'      => $eventId,
                    'setBy'         => $this->actor,
                    'reason'        => '',
                ],
                'merge'        => false,
                'precondition' => ['exists' => false],
            ];
        }

        $ok = $this->fs->commitBatch($ops);
        if (!$ok) {
            log_message('error', "Staff_employment_history_service: JOINED batch failed school={$this->schoolId} staff={$staffId}");
            return ['ok' => false, 'reason' => 'commit_failed', 'eventId' => null];
        }
        return ['ok' => true, 'reason' => 'created', 'eventId' => $eventId];
    }

    /**
     * Record one or more Phase-1 attribute changes on an EXISTING staff
     * member as a single combined commitBatch: for each changed attribute,
     * close its currently-open timeline entry (CAS-protected) and open a
     * new one, plus one event per changed attribute. All ops across all
     * changed attributes commit together — either every attribute's
     * event+timeline pair lands, or none do.
     *
     * Bounded optimistic-concurrency retry (MAX_RETRIES): on a CAS conflict
     * the whole batch is rejected, this method re-reads the currently-open
     * entries fresh and rebuilds the batch — never an unbounded loop. A
     * caller that exhausts retries gets a defined 'contention_exhausted'
     * reason and must ask the admin to re-load and re-submit; this service
     * never silently retries beyond MAX_RETRIES nor drops the request.
     *
     * @param array $changes  [attribute => newValue] — ONLY attributes whose
     *                        value actually changed; caller is responsible
     *                        for diffing against the existing record first.
     * @return array ['ok'=>bool,'reason'=>string,'retries'=>int,'eventIds'=>array]
     */
    public function recordChanges(string $staffId, array $changes, string $effectiveFrom = '', string $reason = ''): array
    {
        if (!$this->ready() || $staffId === '') {
            return ['ok' => false, 'reason' => 'not_initialized', 'retries' => 0, 'eventIds' => []];
        }
        $changes = array_intersect_key($changes, array_flip(self::ATTRIBUTES));
        if (empty($changes)) {
            return ['ok' => true, 'reason' => 'noop', 'retries' => 0, 'eventIds' => []];
        }
        $effectiveFrom = $effectiveFrom !== '' ? $effectiveFrom : date('Y-m-d');
        $now           = date('Y-m-d H:i:s');

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            $ops      = [];
            $eventIds = [];

            foreach ($changes as $attr => $newValue) {
                $newValue = (string) $newValue;

                // Find the currently-open entry for this attribute, if any.
                $open = $this->fs->schoolWhere(self::TIMELINE_COL, [
                    ['staffId', '==', $staffId],
                    ['attribute', '==', $attr],
                    ['effectiveTo', '==', null],
                ], null, 'ASC', 2);
                $openRow = null;
                if (is_array($open)) {
                    foreach ($open as $row) {
                        if (is_array($row) && isset($row['id'])) { $openRow = $row; break; }
                    }
                }
                $oldValue = '';
                if ($openRow) {
                    $oldValue = (string) ($openRow['data']['value'] ?? '');
                    if ($oldValue === $newValue) {
                        // Already this value (e.g. a retried/duplicate submit) — skip this
                        // attribute entirely rather than writing a no-op CHANGED event.
                        continue;
                    }
                }

                $eventKey = $this->eventKey();
                $eventId  = $this->eventDocId($eventKey);
                $eventIds[$attr] = $eventId;

                $ops[] = [
                    'op'           => 'update',
                    'collection'   => self::EVENT_COL,
                    'docId'        => $eventId,
                    'data'         => [
                        'schoolId'         => $this->schoolId,
                        'staffId'          => $staffId,
                        'eventType'        => $this->attributeEventPrefix($attr) . '_CHANGED',
                        'attribute'        => $attr,
                        'fromValue'        => $oldValue,
                        'toValue'          => $newValue,
                        'effectiveFrom'    => $effectiveFrom,
                        'changed_by'       => $this->actor,
                        'changed_at'       => $now,
                        'reason'           => $reason,
                        'correctsEventRef' => '',
                        'reversesEventRef' => '',
                        'metadata'         => [],
                    ],
                    'merge'        => false,
                    'precondition' => ['exists' => false],
                ];

                if ($openRow) {
                    $updateTime = (string) ($openRow['data']['__updateTime'] ?? '');
                    $ops[] = [
                        'op'           => 'update',
                        'collection'   => self::TIMELINE_COL,
                        'docId'        => (string) $openRow['id'],
                        'data'         => ['effectiveTo' => $effectiveFrom],
                        'merge'        => true,
                        'precondition' => $updateTime !== '' ? ['updateTime' => $updateTime] : ['exists' => true],
                    ];
                }

                $ops[] = [
                    'op'           => 'update',
                    'collection'   => self::TIMELINE_COL,
                    'docId'        => $this->timelineDocId($staffId, $attr, $eventKey),
                    'data'         => [
                        'schoolId'      => $this->schoolId,
                        'staffId'       => $staffId,
                        'attribute'     => $attr,
                        'value'         => $newValue,
                        'effectiveFrom' => $effectiveFrom,
                        'effectiveTo'   => null,
                        'eventRef'      => $eventId,
                        'setBy'         => $this->actor,
                        'reason'        => $reason,
                    ],
                    'merge'        => false,
                    'precondition' => ['exists' => false],
                ];
            }

            if (empty($ops)) {
                return ['ok' => true, 'reason' => 'noop', 'retries' => $attempt, 'eventIds' => []];
            }

            if ($this->fs->commitBatch($ops)) {
                return ['ok' => true, 'reason' => 'recorded', 'retries' => $attempt, 'eventIds' => $eventIds];
            }
            // CAS conflict (stale updateTime on the entry being closed) or a
            // transient failure — loop re-reads the open entries fresh.
        }

        log_message('error', "Staff_employment_history_service: recordChanges CAS exhausted school={$this->schoolId} staff={$staffId} attrs=" . implode(',', array_keys($changes)));
        return ['ok' => false, 'reason' => 'contention_exhausted', 'retries' => self::MAX_RETRIES + 1, 'eventIds' => []];
    }

    /**
     * Trail 2 (Work Schedule → Calendar → Attendance → Leave/LOP → Payroll),
     * Phase J, 2026-08-18 — READ-ONLY resolver over the existing
     * `staffEmploymentTimeline` projection. Answers "what was this
     * attribute's value as of a given date", never "what is it now".
     *
     * Added to close a confirmed bug: Payroll read `staff.Department`/
     * `Position` directly (the LIVE, present-tense value) when resolving a
     * payroll PERIOD's calendar/holiday-audience, so an admin editing a
     * staff member's department today could silently change the computed
     * working-days/holiday math for an already-past period if that period
     * is (re)generated after the edit. This method lets a caller resolve
     * the value that was ACTUALLY effective during the period being
     * calculated instead.
     *
     * Falls back to null (never throws, never blocks) when no timeline
     * entry exists for this staff/attribute — the overwhelming common case
     * for any staff member whose department has never changed since they
     * joined is covered by `recordJoined()`'s day-one snapshot, but a
     * caller must still treat null as "use the current/live value" rather
     * than as an error.
     */
    /**
     * Timeline rows for one staff/attribute, served from the warm index when the
     * caller has preloaded, otherwise fetched on demand. Null means the read
     * failed (distinct from "no history", which is an empty array).
     */
    private function timelineRows(string $staffId, string $attribute): ?array
    {
        if ($this->warmed) {
            return $this->warmIndex[$staffId . '|' . $attribute] ?? [];
        }

        $key = $staffId . '|' . $attribute;
        if (array_key_exists($key, $this->rowCache)) return $this->rowCache[$key];

        try {
            $rows = $this->fs->schoolWhere(self::TIMELINE_COL, [
                ['staffId',   '==', $staffId],
                ['attribute', '==', $attribute],
            ]);
        } catch (\Throwable $e) {
            log_message('error', "Staff_employment_history_service: resolveAttributeAsOf query failed staff={$staffId} attr={$attribute}: " . $e->getMessage());
            return null;
        }

        $this->rowCache[$key] = is_array($rows) ? $rows : [];
        return $this->rowCache[$key];
    }

    /**
     * Preload the whole tenant's timeline in ONE query.
     *
     * NO-N+1 (2026-08-19). resolveAttributeAsOf() is a real Firestore query with
     * no internal caching, and payroll calls it twice per employee — department
     * and position — inside the roster loop. On a 200-staff school that is 400
     * queries for a single payroll run, an N+1 introduced by effective dating.
     *
     * Payroll already reads the entire roster and every schedule assignment in
     * one query each, so reading the timeline the same way is consistent with
     * how this path is built rather than a new pattern. After warming, every
     * resolve is served from memory.
     *
     * Best-effort by design: if the read fails the service stays cold and each
     * resolve falls back to its own query, which is exactly the previous
     * behaviour. Warming can never change an answer — only where it came from.
     */
    public function warmTimeline(): bool
    {
        if ($this->warmed) return true;
        if (!$this->ready()) return false;

        try {
            $rows = $this->fs->schoolWhere(self::TIMELINE_COL, []);
        } catch (\Throwable $e) {
            log_message('error', 'Staff_employment_history_service::warmTimeline failed, falling back to per-staff reads: ' . $e->getMessage());
            return false;
        }

        $index = [];
        foreach ((is_array($rows) ? $rows : []) as $row) {
            $d = is_array($row['data'] ?? null) ? $row['data'] : (is_array($row) ? $row : null);
            if (!is_array($d)) continue;
            $sid  = (string) ($d['staffId'] ?? '');
            $attr = (string) ($d['attribute'] ?? '');
            if ($sid === '' || $attr === '') continue;
            $index[$sid . '|' . $attr][] = $row;
        }

        $this->warmIndex = $index;
        $this->warmed    = true;
        return true;
    }

    /**
     * Employment EPISODES derived from an event list.
     *
     * JOINED/REHIRED opens an episode, SEPARATED closes it, and every other
     * event (REACTIVATED included) continues whichever episode is open. A
     * dangling episode is closed defensively rather than dropped, because
     * losing one would understate how long somebody worked here.
     *
     * Extracted from `Staff::get_employment_history()` on 2026-08-22 so the
     * Full & Final settlement resolves service duration from the SAME
     * derivation the employment-history screen displays. Pure and static: it
     * touches no Firestore and no instance state, so both callers are
     * guaranteed the same answer for the same events.
     *
     * `$events` must be in chronological order — the caller already sorts them.
     *
     * @param  array $events each ['eventType' => string, 'effectiveFrom' => 'Y-m-d']
     * @return array<int, array{startEvent: string, start: string, end: ?string}>
     */
    public static function episodesFromEvents(array $events): array
    {
        $episodes = [];
        $current  = null;

        foreach ($events as $evt) {
            $type = is_array($evt) ? (string) ($evt['eventType'] ?? '') : '';
            $from = is_array($evt) ? (string) ($evt['effectiveFrom'] ?? '') : '';

            if (in_array($type, ['JOINED', 'REHIRED'], true)) {
                if ($current !== null) $episodes[] = $current;   // close a dangling episode rather than lose it
                $current = ['startEvent' => $type, 'start' => $from, 'end' => null];
            } elseif ($type === 'SEPARATED' && $current !== null) {
                $current['end'] = $from;
                $episodes[] = $current;
                $current = null;
            }
        }
        if ($current !== null) $episodes[] = $current;

        return $episodes;
    }

    /**
     * Service duration, as INPUT to a settlement — never as a verdict.
     *
     * Returns both readings, because which one a gratuity calculation should
     * use is unresolved policy (D-002 Q1) and this method must not pre-empt it:
     *
     *   totalDays       the sum of every episode — service across all stints
     *   continuousDays  the CURRENT unbroken stint only
     *   episodeCount    how many stints there were
     *   hasBreak        true when the two readings differ
     *
     * For someone who never left they are identical, which is the ordinary
     * case. They diverge only after a rehire — and that divergence is exactly
     * the thing an owner has to rule on, so it is surfaced rather than
     * silently resolved one way.
     *
     * `$asOf` closes an episode that is still open (normally the last working
     * day). Dates are inclusive of both endpoints: someone who joins and leaves
     * on the same day served one day, not zero.
     *
     * @return array{totalDays: int, continuousDays: int, episodeCount: int, hasBreak: bool}
     */
    public static function serviceDaysFromEpisodes(array $episodes, string $asOf): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
            return ['totalDays' => 0, 'continuousDays' => 0, 'episodeCount' => 0, 'hasBreak' => false];
        }

        $total      = 0;
        $continuous = 0;
        $counted    = 0;

        foreach ($episodes as $ep) {
            $start = is_array($ep) ? (string) ($ep['start'] ?? '') : '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) continue;

            $endRaw = is_array($ep) ? ($ep['end'] ?? null) : null;
            $end    = (is_string($endRaw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endRaw))
                ? $endRaw
                : $asOf;                                   // still open → close it at $asOf

            if ($end < $start) continue;                   // corrupt window — ignore, never negative
            if ($start > $asOf) continue;                  // future-dated stint has not started

            if ($end > $asOf) $end = $asOf;                // never count service beyond the as-of date

            $days = (int) ((new DateTime($start))->diff(new DateTime($end))->days) + 1;   // inclusive

            $total     += $days;
            $continuous = $days;                           // last episode wins — the current stint
            $counted++;
        }

        return [
            'totalDays'      => $total,
            'continuousDays' => $counted > 0 ? $continuous : 0,
            'episodeCount'   => $counted,
            'hasBreak'       => $counted > 1 && $total !== $continuous,
        ];
    }

    /** Drop the warm index — used when a writer changes the timeline mid-request. */
    public function coolTimeline(): void
    {
        $this->warmed    = false;
        $this->warmIndex = [];
        $this->rowCache  = [];
    }

    public function resolveAttributeAsOf(string $staffId, string $attribute, string $asOfDate): ?string
    {
        if (!$this->ready() || $staffId === '' || !in_array($attribute, self::ATTRIBUTES, true)) return null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDate)) return null;

        $rows = $this->timelineRows($staffId, $attribute);
        if ($rows === null) return null;

        $best = null;
        $bestFrom = '';
        foreach ((is_array($rows) ? $rows : []) as $row) {
            $d = is_array($row['data'] ?? null) ? $row['data'] : (is_array($row) ? $row : null);
            if (!is_array($d)) continue;
            $ef = (string) ($d['effectiveFrom'] ?? '');
            if ($ef === '' || $ef > $asOfDate) continue;                       // starts after asOfDate — not yet effective
            $et = $d['effectiveTo'] ?? null;
            if ($et !== null && (string) $et !== '' && (string) $et <= $asOfDate) continue; // already closed before asOfDate
            // Multiple windows can technically satisfy the filter above only
            // under data corruption (the writer always closes the prior
            // entry before opening a new one) — defensively pick the one
            // with the LATEST effectiveFrom, i.e. the most recent window
            // that had actually started by asOfDate.
            if ($best === null || $ef > $bestFrom) {
                $best = (string) ($d['value'] ?? '');
                $bestFrom = $ef;
            }
        }
        return $best;
    }

    /**
     * Explicit event-type prefix per attribute — NOT derived by regex from the
     * field name. 'reportingManagerId' is why: the field carries an 'Id' suffix
     * (it stores a staffId reference) but the event/concept name is
     * REPORTING_MANAGER_CHANGED, not REPORTING_MANAGER_ID_CHANGED — a generic
     * camelCase-to-SNAKE_CASE derivation would silently get this one wrong.
     */
    private const EVENT_TYPE_PREFIX = [
        'position'           => 'POSITION',
        'department'         => 'DEPARTMENT',
        'employmentType'     => 'EMPLOYMENT_TYPE',
        'reportingManagerId' => 'REPORTING_MANAGER',
    ];

    private function attributeEventPrefix(string $attr): string
    {
        return self::EVENT_TYPE_PREFIX[$attr] ?? strtoupper(trim(preg_replace('/(?<!^)[A-Z]/', '_$0', $attr)));
    }

    /** Only these event types may be passed to recordEmploymentStatusChange(). */
    private const EMPLOYMENT_STATUS_EVENT_TYPES = ['SEPARATED', 'REACTIVATED', 'REHIRED'];

    /**
     * Pure decision function, no I/O — Phase 3B.1 locked rule: a return-to-
     * Active transition is REHIRED if it is accompanied by a change to any
     * of Position/Department/EmploymentType/ReportingManagerId, otherwise
     * REACTIVATED. Deliberately NOT based on elapsed time (the locked
     * decision explicitly rejects a date-gap heuristic).
     *
     * @param array $otherAttributeChanges  [attribute => newValue] for any of
     *              position/department/employmentType/reportingManagerId
     *              being changed IN THE SAME transition. Empty/absent = none.
     */
    public function determineReactivationEventType(array $otherAttributeChanges): string
    {
        $relevant = array_intersect_key(
            $otherAttributeChanges,
            array_flip(['position', 'department', 'employmentType', 'reportingManagerId'])
        );
        return empty($relevant) ? 'REACTIVATED' : 'REHIRED';
    }

    /**
     * Records a SEPARATED / REACTIVATED / REHIRED transition of the
     * 'employmentStatus' attribute — deliberately a SEPARATE method from
     * recordChanges(), not a generic attribute-loop call, because these
     * three event types are explicit business facts (Phase 3B.1 locked
     * decision), not a derived "{ATTR}_CHANGED" label. Same bounded-CAS-
     * retry / commitBatch shape as recordChanges(), duplicated rather than
     * shared to avoid touching that already-proven Phase 1/2C code path.
     *
     * When $separationType is non-empty (SEPARATED only), a `staffSeparations`
     * record is created IN THE SAME atomic commitBatch as the event+timeline
     * ops — stronger atomicity than Phase 1's original sequential pattern,
     * safely achievable here because this is a narrow, dedicated write with
     * no RBAC-adjacent entanglement (see this class's own docblock for why
     * Position/Department/etc. did NOT get this same treatment).
     *
     * @return array ['ok'=>bool,'reason'=>string,'retries'=>int,'eventId'=>?string,'separationId'=>?string]
     */
    public function recordEmploymentStatusChange(
        string $staffId,
        string $eventType,
        string $toValue,
        string $effectiveFrom,
        string $reason = '',
        array $metadata = [],
        string $separationType = '',
        string $actualLastWorkingDate = ''
    ): array {
        if (!$this->ready() || $staffId === '') {
            return ['ok' => false, 'reason' => 'not_initialized', 'retries' => 0, 'eventId' => null, 'separationId' => null];
        }
        if (!in_array($eventType, self::EMPLOYMENT_STATUS_EVENT_TYPES, true)) {
            return ['ok' => false, 'reason' => 'invalid_event_type', 'retries' => 0, 'eventId' => null, 'separationId' => null];
        }
        $effectiveFrom = $effectiveFrom !== '' ? $effectiveFrom : date('Y-m-d');
        $fromValue     = $toValue === 'Active' ? 'Inactive' : 'Active';
        $now           = date('Y-m-d H:i:s');
        $attr          = 'employmentStatus';

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            $open = $this->fs->schoolWhere(self::TIMELINE_COL, [
                ['staffId', '==', $staffId],
                ['attribute', '==', $attr],
                ['effectiveTo', '==', null],
            ], null, 'ASC', 2);
            $openRow = null;
            if (is_array($open)) {
                foreach ($open as $row) {
                    if (is_array($row) && isset($row['id'])) { $openRow = $row; break; }
                }
            }
            // If an open entry exists, its value is the authoritative fromValue;
            // the caller-supplied $toValue's implied fromValue is a fallback only
            // for the (legacy-data) case where no prior employmentStatus entry exists yet.
            $resolvedFromValue = $openRow ? (string) ($openRow['data']['value'] ?? $fromValue) : $fromValue;

            $eventKey = $this->eventKey();
            $eventId  = $this->eventDocId($eventKey);

            $ops = [[
                'op'           => 'update',
                'collection'   => self::EVENT_COL,
                'docId'        => $eventId,
                'data'         => [
                    'schoolId'         => $this->schoolId,
                    'staffId'          => $staffId,
                    'eventType'        => $eventType,
                    'attribute'        => $attr,
                    'fromValue'        => $resolvedFromValue,
                    'toValue'          => $toValue,
                    'effectiveFrom'    => $effectiveFrom,
                    'changed_by'       => $this->actor,
                    'changed_at'       => $now,
                    'reason'           => $reason,
                    'correctsEventRef' => '',
                    'reversesEventRef' => '',
                    'metadata'         => $metadata,
                ],
                'merge'        => false,
                'precondition' => ['exists' => false],
            ]];

            if ($openRow) {
                $updateTime = (string) ($openRow['data']['__updateTime'] ?? '');
                $ops[] = [
                    'op'           => 'update',
                    'collection'   => self::TIMELINE_COL,
                    'docId'        => (string) $openRow['id'],
                    'data'         => ['effectiveTo' => $effectiveFrom],
                    'merge'        => true,
                    'precondition' => $updateTime !== '' ? ['updateTime' => $updateTime] : ['exists' => true],
                ];
            }

            $ops[] = [
                'op'           => 'update',
                'collection'   => self::TIMELINE_COL,
                'docId'        => $this->timelineDocId($staffId, $attr, $eventKey),
                'data'         => [
                    'schoolId'      => $this->schoolId,
                    'staffId'       => $staffId,
                    'attribute'     => $attr,
                    'value'         => $toValue,
                    'effectiveFrom' => $effectiveFrom,
                    'effectiveTo'   => null,
                    'eventRef'      => $eventId,
                    'setBy'         => $this->actor,
                    'reason'        => $reason,
                ],
                'merge'        => false,
                'precondition' => ['exists' => false],
            ];

            $separationId = null;
            if ($eventType === 'SEPARATED' && $separationType !== '') {
                $separationId = "{$this->schoolId}_{$staffId}_{$eventKey}";
                $ops[] = [
                    'op'           => 'update',
                    'collection'   => self::EMPLOYMENT_STATUS_COL,
                    'docId'        => $separationId,
                    'data'         => [
                        'schoolId'              => $this->schoolId,
                        'staffId'               => $staffId,
                        'separationType'        => $separationType,
                        'reason'                => $reason,
                        'status'                => 'SEPARATED',
                        'actualLastWorkingDate' => $actualLastWorkingDate !== '' ? $actualLastWorkingDate : $effectiveFrom,
                        'initiatedBy'           => $this->actor,
                        'initiatedAt'           => $now,
                        'updatedAt'             => $now,
                        'employmentEventRef'    => $eventId,
                    ],
                    'merge'        => false,
                    'precondition' => ['exists' => false],
                ];
            }

            if ($this->fs->commitBatch($ops)) {
                return ['ok' => true, 'reason' => 'recorded', 'retries' => $attempt, 'eventId' => $eventId, 'separationId' => $separationId];
            }
            // CAS conflict or transient failure — loop re-reads the open entry fresh.
        }

        log_message('error', "Staff_employment_history_service: recordEmploymentStatusChange CAS exhausted school={$this->schoolId} staff={$staffId} eventType={$eventType}");
        return ['ok' => false, 'reason' => 'contention_exhausted', 'retries' => self::MAX_RETRIES + 1, 'eventId' => null, 'separationId' => null];
    }

    /** Bounded ancestor-chain depth — mirrors Sr_no_service::MAX_CHAIN_DEPTH's
     *  exact reasoning: a realistic ceiling on genuine school-org depth, and a
     *  guard against a pre-existing corrupt loop causing an unbounded walk. */
    public const REPORTING_MANAGER_MAX_CHAIN_DEPTH = 25;

    /**
     * Validates a proposed reportingManagerId for $employeeStaffId, school-
     * scoped to $this->schoolId (set by init(), same as every write in this
     * class). Lives here, not on Staff.php, so it can be unit-tested against
     * a fake $fs the same way the rest of this class already is — mirrors
     * Sr_no_service::_chainContains()'s bounded-walk pattern, applied to the
     * `staff` collection's reportingManagerId chain instead of students'
     * previousStudentId chain.
     *
     * Order (cheapest first):
     *   1. empty candidate = clearing the relationship, always valid
     *   2. self-reference (pure string compare, no I/O)
     *   3. manager doc resolves under THIS school's prefix (this IS the tenancy
     *      check — a School-B staffId looked up under School A's prefix simply
     *      does not resolve, so no separate schoolId field comparison is needed)
     *   4. manager status == 'Active' (the only two real status values ZenX
     *      has today — 'Inactive' or any other value is rejected)
     *   5. bounded cycle detection — walks the CANDIDATE manager's own current
     *      reportingManagerId chain upward; a broken link (doc doesn't
     *      resolve) ends the walk without rejecting; an Inactive staff member
     *      found mid-chain does NOT reject (topology only, per design)
     *
     * @return array ['ok'=>bool,'reason'=>string]
     */
    public function validateManagerAssignment(string $employeeStaffId, string $candidateManagerId): array
    {
        $candidateManagerId = trim($candidateManagerId);
        if ($candidateManagerId === '') {
            return ['ok' => true, 'reason' => 'cleared'];
        }
        if (!$this->ready()) {
            return ['ok' => false, 'reason' => 'not_initialized'];
        }
        if ($candidateManagerId === $employeeStaffId) {
            return ['ok' => false, 'reason' => 'self_reference'];
        }

        $managerDoc = $this->fs->get('staff', $this->staffDocId($candidateManagerId));
        if (!is_array($managerDoc) || empty($managerDoc)) {
            return ['ok' => false, 'reason' => 'manager_not_found'];
        }
        if (($managerDoc['status'] ?? '') !== 'Active') {
            return ['ok' => false, 'reason' => 'manager_inactive'];
        }

        $cursor = $candidateManagerId;
        $seen = [];
        for ($i = 0; $i < self::REPORTING_MANAGER_MAX_CHAIN_DEPTH; $i++) {
            if ($cursor === $employeeStaffId) {
                return ['ok' => false, 'reason' => 'circular_reference'];
            }
            if (isset($seen[$cursor])) {
                break; // pre-existing corrupt loop unrelated to this new assignment — stop, don't block
            }
            $seen[$cursor] = true;
            $doc = ($cursor === $candidateManagerId) ? $managerDoc : $this->fs->get('staff', $this->staffDocId($cursor));
            $next = is_array($doc) ? trim((string) ($doc['reportingManagerId'] ?? '')) : '';
            if ($next === '') break; // chain end — no manager above this point
            $cursor = $next;
        }

        return ['ok' => true, 'reason' => 'valid'];
    }

    private function staffDocId(string $staffId): string
    {
        return "{$this->schoolId}_{$staffId}";
    }
}
