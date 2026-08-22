<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_gps_service — F13.1 Live GPS data foundation (Milestone M1).
 *
 * F13 is a NEW feature layered on the frozen F1–F12.1 Transport module. It
 * gets its own service rather than extending `Transport_service` (7,900 LoC,
 * frozen), exactly as F12.1 got `Transport_compliance_service`.
 *
 * ─── M1 SCOPE: CONTRACT ONLY — THIS CLASS PERFORMS NO WRITES ─────────────
 * M1 establishes the data contract, the validation gates and the viewer
 * allowlist resolver. The write paths (`openSession` / `closeSession` and the
 * `startTrip` / `completeTrip` / `cancelTrip` wiring approved as D-2) land in
 * M2. Everything here is a pure function or a read.
 *
 * ─── THE HOT/COLD SPLIT (the load-bearing decision) ──────────────────────
 * Firestore sustains ~1 write/sec/document. A design that streamed positions
 * into `tripLogs` would throttle and silently drop under load. So:
 *
 *   vehicleLivePositions/{schoolId}_{vehicleId}   HOT  — one doc per vehicle,
 *       OVERWRITTEN, ≤1 write/15s. The ONLY document a parent map subscribes
 *       to, which is what keeps the parent read cost at exactly one document.
 *
 *   vehicleBreadcrumbs/{schoolId}_{gpsSessionId}_{epochMs}   COLD — append-
 *       only, one doc per ping, immutable, TTL-expired at 30 days.
 *
 * ─── AUTHORIZATION MODEL (D-1: server-created hot doc) ───────────────────
 * The hot doc is created SERVER-SIDE at trip start and deleted at trip end.
 * The driver app may only UPDATE it, and only the fields in
 * DRIVER_MUTABLE_FIELDS. Rules enforce this; the client cannot create, cannot
 * delete, and cannot touch a server-owned field.
 *
 * This exists because the obvious client-create design does not hold up:
 * `isDriverOfTrip()` reads `resource.data.driver_staff_id`, which is null on a
 * create, and `isSameSchool()` is deliberately permissive when
 * `resource == null` — so a client-create rule would have had neither a driver
 * check nor a tenant check. Server-create removes the whole class of problem
 * and lets the existing rules helpers be reused unmodified.
 *
 * ─── VIEWER ALLOWLIST (the tenant-isolation control) ─────────────────────
 * A parent must see only their child's bus, only during an active trip. Doing
 * that with a cross-document `get()` in a rule would cost a rule-read on every
 * position update. Instead the server denormalises `authorized_viewer_uids[]`
 * onto the hot doc at trip start, from the already-frozen roster, and the rule
 * is a cheap `in` check.
 *
 * NOTE ON IDENTITY: parent-app auth uses the STUDENT's `userId` as the
 * Firebase auth UID (`firestore.rules` isOwnStudent, ~line 264) — there is no
 * parent identity in the token. The allowlist therefore holds student userIds,
 * not parent ids. The field is named `authorized_viewer_uids` rather than the
 * design's `authorized_parent_user_ids` to stop that being misread.
 *
 * ─── ANTI-SPOOFING (D-4) ─────────────────────────────────────────────────
 * Mirrors the proven Staff Attendance model (`Attendance_policy` gates 3-4):
 * reject mock locations, reject fixes worse than the accuracy ceiling, and
 * reject garbage coordinates — all fail-closed, reusing `geofence_helper`'s
 * primitives rather than reimplementing haversine/accuracy maths.
 */
class Transport_gps_service
{
    // ── collections ──────────────────────────────────────────────────────
    public const COL_LIVE_POSITIONS = 'vehicleLivePositions';
    public const COL_BREADCRUMBS    = 'vehicleBreadcrumbs';

    /**
     * Fields the DRIVER app may change on the hot doc. The Firestore rule
     * pins `affectedKeys().hasOnly()` to exactly this list, so a compromised
     * or buggy client can never rewrite tripId, the allowlist, or the
     * tracking flag.
     */
    public const DRIVER_MUTABLE_FIELDS = [
        'lat', 'lng', 'speed_kmph', 'heading', 'accuracy_m', 'altitude_m',
        'recorded_at', 'recorded_at_ms', 'received_at', 'is_mock', 'source',
        'app_version', 'client_platform', 'client_timestamp',
    ];

    /** Server-owned hot-doc fields — written at openSession, never by a client. */
    public const SERVER_OWNED_FIELDS = [
        'schoolId', 'vehicleId', 'tripId', 'gps_session_id', 'driver_staff_id',
        'authorized_viewer_uids', 'live_tracking_enabled', 'session_started_at',
    ];

    /** Position sources. `iot_device` is reserved for a later hardware slice. */
    public const SOURCES = ['driver_app', 'iot_device'];

    // ── anti-spoofing / quality gates (D-4) ──────────────────────────────
    /** Mirrors Attendance_policy::DEFAULT_MAX_ACCURACY_M — one ERP-wide ceiling. */
    public const MAX_ACCURACY_M = 100.0;
    /** A school bus that appears to exceed this is a bad fix, not a bus. */
    public const MAX_PLAUSIBLE_SPEED_KMPH = 150.0;

    // ── throttling / staleness (the write-budget controls) ───────────────
    /** Minimum seconds between hot-doc writes. 15s ⇒ ≤4 writes/min/vehicle. */
    public const MIN_WRITE_INTERVAL_S = 15;
    /** Suppress writes entirely once stationary this long (pickup dwell). */
    public const STATIONARY_SUPPRESS_S = 60;
    /** A position older than this renders as "last seen N minutes ago". */
    public const STALE_AFTER_S = 120;
    /** Movement below this is "stationary" for suppression purposes. */
    public const STATIONARY_RADIUS_M = 25.0;

    // ── M2: session lifecycle + fix ordering ─────────────────────────────
    /**
     * A session whose trip is still InProgress but which has not received a fix
     * for this long is presumed abandoned (driver app crashed, killed, or out
     * of battery) and is swept. Deliberately generous relative to
     * STALE_AFTER_S: rendering a position as "stale" after 2 minutes is a UI
     * nicety, whereas tearing the session down is destructive, so it waits.
     */
    public const STALE_SESSION_AFTER_S = 1800;   // 30 min
    /**
     * Tolerance for a device clock running ahead of the server. Without an
     * upper bound, one skewed device could stamp a far-future time and
     * permanently block every later fix for that vehicle via the monotonicity
     * rule.
     */
    public const MAX_CLOCK_SKEW_S = 120;         // 2 min
    /**
     * A fix older than this never reaches the hot doc. This is what implements
     * "a queued offline position must not be presented as current" — a bus that
     * left a tunnel should not rewind on the parent's map. Breadcrumbs are
     * exempt: late history is legitimate and their ids make replay idempotent.
     */
    public const MAX_FIX_AGE_S = 300;            // 5 min

    // ── retention (D-3: native Firestore TTL) ────────────────────────────
    public const BREADCRUMB_TTL_DAYS = 30;
    /**
     * The field a Firestore TTL policy must be configured against.
     *
     * CRITICAL: TTL only acts on a field of Firestore type **Timestamp**. An
     * ISO-8601 *string* is silently ignored and the document lives forever.
     * `Firestore_rest_client::encode()` emits a `timestampValue` for a
     * DateTimeInterface or for the `['__firestore_type' => 'timestamp']`
     * sentinel — `breadcrumbExpiresAt()` below returns the sentinel so a
     * server-side writer cannot get this wrong. The Kotlin driver app must
     * likewise write a `Timestamp`, never a String.
     */
    public const TTL_FIELD = 'expires_at';

    /** @var object|null Firestore_service */
    private $fs = null;
    private string $schoolId = '';
    private string $adminId  = 'system';

    public function __construct()
    {
        get_instance()->load->helper('geofence');
    }

    public function init($fs, string $schoolId, string $adminId = 'system'): self
    {
        $this->fs       = $fs;
        $this->schoolId = trim($schoolId);
        $this->adminId  = trim($adminId) !== '' ? trim($adminId) : 'system';
        return $this;
    }

    private function _assertReady(): void
    {
        if ($this->fs === null || $this->schoolId === '') {
            throw new \LogicException('Transport_gps_service::init() must be called first.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Document ids — RC-3: a SINGLE {schoolId}_ prefix, applied by docId().
    //  These return the BARE id; the caller passes it through fs->docId(),
    //  exactly as the compliance service does. Never hand-prefix here or the
    //  id double-prefixes.
    // ─────────────────────────────────────────────────────────────────────

    public function livePositionId(string $vehicleId): string
    {
        $vehicleId = trim($vehicleId);
        if ($vehicleId === '') { throw new \InvalidArgumentException('vehicleId is required.'); }
        return $vehicleId;
    }

    public function breadcrumbId(string $gpsSessionId, int $epochMs): string
    {
        $gpsSessionId = trim($gpsSessionId);
        if ($gpsSessionId === '') { throw new \InvalidArgumentException('gps_session_id is required.'); }
        if ($epochMs <= 0)        { throw new \InvalidArgumentException('epochMs must be positive.'); }
        return $gpsSessionId . '_' . $epochMs;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Anti-spoofing / quality validation (D-4)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Validate one inbound position. Fail-closed: anything missing, garbage or
     * implausible is rejected rather than stored.
     *
     * Reject reasons mirror Attendance_policy's vocabulary so the two GPS
     * surfaces read the same in logs: `mock_location`, `poor_accuracy`,
     * `invalid_coordinates`, plus `implausible_speed` which is specific to a
     * moving vehicle.
     *
     * @return array{ok:bool, reason:string}
     */
    public function validatePosition(array $p): array
    {
        $lat = $p['lat'] ?? null;
        $lng = $p['lng'] ?? null;
        if (!gf_valid_coord($lat, $lng)) {
            return ['ok' => false, 'reason' => 'invalid_coordinates'];
        }

        // Gate: mock location. Unlike staff attendance there is no
        // allow-mock escape hatch — a spoofed bus position is never
        // legitimate, and it would corrupt every downstream slice
        // (geofence auto-arrival, ETA, driver-behaviour scoring).
        if (!empty($p['is_mock'])) {
            return ['ok' => false, 'reason' => 'mock_location'];
        }

        // Gate: accuracy ceiling. gf_accuracy_acceptable() fails closed on a
        // missing or negative accuracy, so an app that omits it is rejected.
        $accuracy = isset($p['accuracy_m']) ? (float) $p['accuracy_m'] : -1.0;
        if (!gf_accuracy_acceptable($accuracy, self::MAX_ACCURACY_M)) {
            return ['ok' => false, 'reason' => 'poor_accuracy'];
        }

        // Gate: plausibility. A negative or absurd speed is a bad fix.
        if (isset($p['speed_kmph'])) {
            $sp = (float) $p['speed_kmph'];
            if (!is_finite($sp) || $sp < 0.0 || $sp > self::MAX_PLAUSIBLE_SPEED_KMPH) {
                return ['ok' => false, 'reason' => 'implausible_speed'];
            }
        }

        return ['ok' => true, 'reason' => ''];
    }

    /**
     * Should this fix be written, given the last one? Implements the two
     * write-budget controls: the 15s floor and stationary suppression.
     *
     * Stationary suppression is the significant one — pickup dwell is a large
     * share of trip time, and a parked bus does not need 4 writes a minute.
     */
    public function shouldWrite(?array $last, array $next, ?int $nowTs = null): bool
    {
        if (!is_array($last) || empty($last['recorded_at'])) {
            return true;                                   // first fix of the session
        }
        $nowTs  = $nowTs ?? time();
        $lastTs = strtotime((string) $last['recorded_at']);
        if ($lastTs === false) { return true; }            // unparseable → write

        $elapsed = $nowTs - $lastTs;
        if ($elapsed < self::MIN_WRITE_INTERVAL_S) {
            return false;                                  // inside the throttle floor
        }
        if (!gf_valid_coord($last['lat'] ?? null, $last['lng'] ?? null)
            || !gf_valid_coord($next['lat'] ?? null, $next['lng'] ?? null)) {
            return true;
        }
        $moved = gf_haversine(
            (float) $last['lat'], (float) $last['lng'],
            (float) $next['lat'], (float) $next['lng']
        );
        if ($moved <= self::STATIONARY_RADIUS_M && $elapsed < self::STATIONARY_SUPPRESS_S) {
            return false;                                  // parked, and not yet due
        }
        return true;
    }

    /** A hot doc whose last fix is older than STALE_AFTER_S renders as stale. */
    public function isStale(?array $pos, ?int $nowTs = null): bool
    {
        if (!is_array($pos) || empty($pos['recorded_at'])) { return true; }
        $ts = strtotime((string) $pos['recorded_at']);
        if ($ts === false) { return true; }
        return (($nowTs ?? time()) - $ts) > self::STALE_AFTER_S;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Viewer allowlist
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Resolve a frozen trip roster (studentIds) to the auth UIDs allowed to
     * watch that vehicle.
     *
     * Field precedence is `students.userId`, falling back to `studentId` with
     * a logged warning — IDENTICAL to
     * `Transport_notifier::_recipientUserIdFromStudent()`, which gate
     * `CH-TRN-RCP-1` pins as the ERP's canonical recipient resolver.
     *
     * It is duplicated rather than called because that method is `private` on
     * a FROZEN class; calling it would mean widening a frozen API, which is
     * outside the D-2 exception. Gate `CH-TRN-F13-2` source-scans both
     * implementations and fails if their field precedence ever diverges, so
     * the duplication cannot silently rot.
     *
     * @param  array<int,string> $studentIds
     * @return array<int,string> deduped auth UIDs
     */
    public function resolveViewerUids(array $studentIds): array
    {
        $this->_assertReady();
        $studentIds = array_values(array_unique(array_filter(array_map('strval', $studentIds))));
        if ($studentIds === []) { return []; }

        $byId = [];
        $missing = [];
        foreach (array_chunk($studentIds, 10) as $chunk) {
            try {
                $rows = (array) $this->fs->schoolWhere('students', [['studentId', 'in', $chunk]], null, 'ASC', 10);
                $found = [];
                foreach ($rows as $r) {
                    $inner = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
                    $sid = (string) ($inner['studentId'] ?? '');
                    if ($sid !== '') { $byId[$sid] = $inner; $found[$sid] = true; }
                }
                foreach ($chunk as $sid) { if (empty($found[$sid])) { $missing[] = $sid; } }
            } catch (\Throwable $_) {
                // Missing composite index / query error → point-read fallback,
                // same tolerance as the notifier's resolver.
                foreach ($chunk as $sid) { $missing[] = $sid; }
            }
        }
        foreach (array_values(array_unique($missing)) as $sid) {
            if (isset($byId[$sid])) { continue; }
            try {
                $stu = $this->fs->getEntity('students', $sid);
                if (is_array($stu)) { $byId[$sid] = $stu; }
            } catch (\Throwable $_) {}
        }

        $uids = [];
        foreach ($studentIds as $sid) {
            if (!isset($byId[$sid])) { continue; }
            $uid = trim((string) ($byId[$sid]['userId'] ?? ''));
            if ($uid === '') {
                log_message('error', "Transport_gps_service: student {$sid} has no userId; falling back to studentId as viewer uid.");
                $uid = $sid;
            }
            $uids[$uid] = true;
        }
        return array_keys($uids);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Document builders — the shapes M2's writers will persist.
    //  Pure: no I/O, so they are directly unit-testable.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The hot doc as created SERVER-SIDE at trip start (D-1).
     *
     * Position fields start null: the doc exists so the driver has something
     * to update and the parent has something to subscribe to, but it asserts
     * no location until the first accepted fix.
     */
    public function buildSessionDoc(
        string $vehicleId,
        string $tripId,
        string $gpsSessionId,
        string $driverStaffId,
        array  $viewerUids
    ): array {
        $this->_assertReady();
        return [
            'schoolId'               => $this->schoolId,
            'vehicleId'              => trim($vehicleId),
            'tripId'                 => trim($tripId),
            'gps_session_id'         => trim($gpsSessionId),
            'driver_staff_id'        => trim($driverStaffId),
            'authorized_viewer_uids' => array_values($viewerUids),
            'live_tracking_enabled'  => true,
            'session_started_at'     => date('c'),
            // position — unset until the first accepted fix
            'lat'                    => null,
            'lng'                    => null,
            'speed_kmph'             => null,
            'heading'                => null,
            'accuracy_m'             => null,
            'altitude_m'             => null,
            'recorded_at'            => null,
            // Ordering key. Starts at 0, never null, so the FIRST fix always
            // satisfies the monotonicity check in both PHP and the rule.
            'recorded_at_ms'         => 0,
            'received_at'            => null,
            'is_mock'                => false,
            'source'                 => 'driver_app',
        ];
    }

    /**
     * One immutable breadcrumb.
     *
     * `expires_at` is emitted as a Firestore Timestamp sentinel, NOT a string —
     * see TTL_FIELD. A string here would make the TTL policy a no-op and the
     * collection would grow without bound.
     */
    public function buildBreadcrumbDoc(
        string $vehicleId,
        string $tripId,
        string $gpsSessionId,
        string $driverStaffId,
        array  $p
    ): array {
        $this->_assertReady();
        return [
            'schoolId'        => $this->schoolId,
            'vehicleId'       => trim($vehicleId),
            'tripId'          => trim($tripId),
            'gps_session_id'  => trim($gpsSessionId),
            'driver_staff_id' => trim($driverStaffId),
            'lat'             => (float) $p['lat'],
            'lng'             => (float) $p['lng'],
            'speed_kmph'      => isset($p['speed_kmph']) ? (float) $p['speed_kmph'] : null,
            'heading'         => isset($p['heading'])    ? (float) $p['heading']    : null,
            'altitude_m'      => isset($p['altitude_m']) ? (float) $p['altitude_m'] : null,
            'recorded_at'     => (string) ($p['recorded_at'] ?? date('c')),
            'recorded_at_ms'  => (int) ($p['recorded_at_ms'] ?? 0),
            self::TTL_FIELD   => $this->breadcrumbExpiresAt(),
        ];
    }

    /**
     * TTL expiry as a Firestore Timestamp sentinel.
     * `Firestore_rest_client::encode()` turns `__firestore_type => timestamp`
     * into a native `timestampValue`, which is the only type a TTL policy acts on.
     *
     * @return array{__firestore_type:string, value:string}
     */
    public function breadcrumbExpiresAt(?int $nowTs = null): array
    {
        $ts = ($nowTs ?? time()) + (self::BREADCRUMB_TTL_DAYS * 86400);
        return [
            '__firestore_type' => 'timestamp',
            'value'            => gmdate('Y-m-d\TH:i:s.000000\Z', $ts),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Reads
    // ─────────────────────────────────────────────────────────────────────

    /** Current hot doc for a vehicle, or null. */
    public function getLivePosition(string $vehicleId): ?array
    {
        $this->_assertReady();
        $doc = $this->fs->getEntity(self::COL_LIVE_POSITIONS, $this->livePositionId($vehicleId));
        return is_array($doc) ? $doc : null;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  M2 — SESSION LIFECYCLE (the server-authoritative kill switch, D-2)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Open a tracking session for a trip that has just gone InProgress.
     *
     * Creates the hot doc server-side (D-1) with the driver binding and the
     * viewer allowlist resolved from the trip's ALREADY-FROZEN roster. The
     * roster is frozen at createTrip (`roster_frozen_at`), so the allowlist is
     * a stable snapshot and the parent read rule needs no cross-document get().
     *
     * Rebuilt on every open rather than reused: a student unassigned since the
     * last trip must not still be able to watch this bus.
     *
     * @param  array  $trip   the trip doc as it was read during the transition
     * @return array{ok:bool, viewers:int, reason:string}
     */
    public function openSession(array $trip, string $tripId): array
    {
        $this->_assertReady();
        $vehicleId = trim((string) ($trip['vehicleId'] ?? ''));
        $driverId  = trim((string) ($trip['driver_staff_id'] ?? ''));
        $sessionId = trim((string) ($trip['gps_session_id'] ?? ''));
        if ($vehicleId === '' || $sessionId === '') {
            return ['ok' => false, 'viewers' => 0, 'reason' => 'trip is missing vehicleId or gps_session_id'];
        }

        $viewers = $this->resolveViewerUids((array) ($trip['roster_student_ids'] ?? []));
        $doc     = $this->buildSessionDoc($vehicleId, $tripId, $sessionId, $driverId, $viewers);

        // Overwrite, do NOT merge: a previous session's position must never
        // survive into a new one, or a parent would briefly see yesterday's
        // location presented as live.
        $this->fs->setEntity(self::COL_LIVE_POSITIONS, $this->livePositionId($vehicleId), $doc, /* merge */ false);
        return ['ok' => true, 'viewers' => count($viewers), 'reason' => ''];
    }

    /**
     * Close a tracking session — the privacy invariant made real.
     *
     * Deleting the hot doc is what actually stops a parent seeing a position
     * after the children are off the bus. The `live_tracking_enabled = false`
     * flag that completeTrip/cancelTrip writes onto the TRIP doc is the
     * belt-and-braces half: even if this delete fails, the Firestore rule
     * refuses further driver updates, so the position can only ever go stale,
     * never continue moving.
     *
     * @return array{ok:bool, reason:string}
     */
    public function closeSession(string $vehicleId): array
    {
        $this->_assertReady();
        $vehicleId = trim($vehicleId);
        if ($vehicleId === '') { return ['ok' => false, 'reason' => 'vehicleId required']; }
        try {
            $this->fs->remove(self::COL_LIVE_POSITIONS, $this->fs->docId($this->livePositionId($vehicleId)));
            return ['ok' => true, 'reason' => ''];
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => $e->getMessage()];
        }
    }

    /**
     * Sweep sessions the driver app never closed.
     *
     * A crashed, force-stopped or out-of-battery driver device leaves a hot doc
     * behind with tracking still enabled. Without this, the parent map shows a
     * frozen bus indefinitely and the doc is never cleaned up.
     *
     * Authority is the TRIP, not the position: a session is stale when its trip
     * is no longer InProgress, or when the last fix is older than
     * STALE_SESSION_AFTER_S. Deciding from the trip means a genuinely parked bus
     * on a live trip is never swept.
     *
     * @return array{scanned:int, closed:int, kept:int, details:array<int,string>}
     */
    public function sweepStaleSessions(int $limit = 200): array
    {
        $this->_assertReady();
        $rows = [];
        try {
            $rows = (array) $this->fs->schoolWhere(self::COL_LIVE_POSITIONS, [], null, 'ASC', $limit);
        } catch (\Throwable $e) {
            return ['scanned' => 0, 'closed' => 0, 'kept' => 0, 'details' => ['query failed: ' . $e->getMessage()]];
        }

        $now = time();
        $closed = 0; $kept = 0; $details = [];
        foreach ($rows as $row) {
            $d   = (isset($row['data']) && is_array($row['data'])) ? $row['data'] : $row;
            $vid = trim((string) ($d['vehicleId'] ?? ''));
            $tid = trim((string) ($d['tripId'] ?? ''));
            if ($vid === '') { continue; }

            $reason = '';
            if ($tid !== '') {
                $trip = null;
                try { $trip = $this->fs->getEntity('tripLogs', $tid); } catch (\Throwable $_) {}
                if (!is_array($trip)) {
                    $reason = 'trip missing';
                } elseif ((string) ($trip['status'] ?? '') !== 'InProgress') {
                    $reason = 'trip status=' . (string) ($trip['status'] ?? '?');
                }
            } else {
                $reason = 'no tripId on the session doc';
            }

            if ($reason === '') {
                $rec = (string) ($d['recorded_at'] ?? '');
                $ts  = $rec !== '' ? strtotime($rec) : false;
                // A session with no fix yet is judged from when it opened, so a
                // driver who starts a trip and never streams is still swept.
                if ($ts === false) { $ts = strtotime((string) ($d['session_started_at'] ?? '')) ?: 0; }
                if ($ts > 0 && ($now - $ts) > self::STALE_SESSION_AFTER_S) {
                    $reason = 'no fix for ' . (int) round(($now - $ts) / 60) . ' min';
                }
            }

            if ($reason === '') { $kept++; continue; }
            $res = $this->closeSession($vid);
            if (!empty($res['ok'])) {
                $closed++; $details[] = "{$vid}: {$reason}";
            } else {
                $details[] = "{$vid}: close FAILED ({$res['reason']})";
            }
        }
        return ['scanned' => count($rows), 'closed' => $closed, 'kept' => $kept, 'details' => $details];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Timestamp monotonicity — stale / out-of-order fixes may not win
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Reject a fix that is older than, or the same age as, the one already
     * stored — plus fixes whose clock is implausible against SERVER time.
     *
     * Why this is necessary. Positions are written by the driver device
     * directly to Firestore, and a device queues fixes while offline. Without
     * an ordering guard, a flush after a tunnel or dead-zone replays a
     * twenty-minute-old position over the current one, and the parent map jumps
     * the bus backwards. Last-write-wins is the correct policy for "current
     * position" only when "last" means *newest recorded*, not *newest received*.
     *
     * Ordering uses `recorded_at_ms` (integer epoch millis), not the ISO
     * `recorded_at` string: string comparison is only safe when every writer
     * emits an identical zero-padded UTC format, and a device sending
     * `+05:30` instead of `Z` would silently compare wrong.
     *
     * Two clock-sanity bounds sit either side, both measured against SERVER
     * time so a wrong device clock cannot defeat them:
     *   • FUTURE — a fix more than MAX_CLOCK_SKEW_S ahead is rejected. Without
     *     this, one device with a wrong clock could stamp a far-future time and
     *     permanently block every subsequent legitimate fix for that vehicle.
     *   • ANCIENT — a fix older than MAX_FIX_AGE_S is rejected outright, which
     *     is what implements the "discard stale queued offline fixes" rule for
     *     the hot doc. Breadcrumbs are unaffected: history legitimately syncs
     *     late, and their deterministic ids make the replay idempotent.
     *
     * Mirrored in the Firestore rule, because the driver writes the hot doc
     * directly and never passes through PHP — the rule is the real enforcement
     * point; this method covers server-mediated paths and is what the probe
     * exercises.
     *
     * @return array{ok:bool, reason:string}
     */
    public function validateFixOrdering(?array $existing, array $incoming, ?int $nowTs = null): array
    {
        $nowMs = (($nowTs ?? time()) * 1000);

        $newMs = $incoming['recorded_at_ms'] ?? null;
        if (!is_int($newMs) && !(is_string($newMs) && ctype_digit($newMs))) {
            return ['ok' => false, 'reason' => 'missing_recorded_at_ms'];
        }
        $newMs = (int) $newMs;
        if ($newMs <= 0) { return ['ok' => false, 'reason' => 'missing_recorded_at_ms']; }

        if ($newMs > $nowMs + (self::MAX_CLOCK_SKEW_S * 1000)) {
            return ['ok' => false, 'reason' => 'future_timestamp'];
        }
        if ($newMs < $nowMs - (self::MAX_FIX_AGE_S * 1000)) {
            return ['ok' => false, 'reason' => 'fix_too_old'];
        }

        $oldMs = (int) ($existing['recorded_at_ms'] ?? 0);
        if ($oldMs > 0 && $newMs <= $oldMs) {
            // Equal counts as stale: a repeat of the same instant carries no
            // new information and would only burn a write.
            return ['ok' => false, 'reason' => 'out_of_order'];
        }
        return ['ok' => true, 'reason' => ''];
    }

    /**
     * Full server-side acceptance check for one fix: quality gates (D-4) then
     * ordering. Returns the first failure so the reason is unambiguous.
     *
     * @return array{ok:bool, reason:string}
     */
    public function acceptFix(?array $existing, array $incoming, ?int $nowTs = null): array
    {
        $q = $this->validatePosition($incoming);
        if (empty($q['ok'])) { return $q; }
        return $this->validateFixOrdering($existing, $incoming, $nowTs);
    }
}
