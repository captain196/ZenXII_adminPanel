<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_qr_attendance_service — F14 Phase 1.
 *
 * The server-authoritative orchestration layer for Transport attendance.
 * Nothing here is a second attendance system: every write is delegated to the
 * ONE canonical writer, `Transport_service::_writeBoardingEvent()` (via the
 * public markStudent* methods). This class owns the DECISIONS, not the storage:
 *
 *     raw QR  →  signed-token verify  →  tenant  →  student
 *             →  Transport eligibility (studentRoutes)
 *             →  trip + driver ownership + lifecycle state
 *             →  frozen roster  |  explicit alternate-bus
 *             →  event state machine
 *             →  idempotency / replay
 *             →  canonical write  →  notify  →  audit
 *
 * WHY A SERVER HOP AT ALL (F-2, operator-approved):
 * the Teacher app used to write `transportAttendance` straight to Firestore,
 * which made security rules the entire authorization boundary. Rules cannot
 * verify an HMAC, and cannot resolve an entitlement across studentRoutes
 * status + effective dates + session + service days. So the hard requirement
 * "a valid QR alone must never be sufficient" was structurally unachievable on
 * that path. Attendance mutations are now server-only; the driver's Firestore
 * create permission is withdrawn in firestore.rules.
 *
 * THE QR IS AN IDENTIFIER, NOT A CREDENTIAL. It carries {schoolId|studentId|sig}
 * and nothing else — no trip, no authorisation, no expiry. Possession proves
 * nothing. Authority comes from the scanning driver's session plus the trip they
 * own. A photographed card gets a caller precisely nowhere.
 *
 * CANONICAL DEPENDENCIES (all read-only here):
 *   • studentRoutes                        — eligibility authority (F-1)
 *   • tripLogs.roster_student_ids          — frozen roster, never modified
 *   • vehicleLivePositions (F13.1)         — bus position for school arrival
 *   • schools.attendancePolicy.gps         — campus geofence (same source as
 *                                            Staff Attendance)
 *   • qr_token_helper                      — signature verification
 *   • Transport_notifier                   — parent push (no second sender)
 *
 * Firestore-only. No RTDB anywhere.
 */
class Transport_qr_attendance_service
{
    // ─── The four canonical events. No aliases, no alternate names. ───────
    public const EV_BOARDED_AT_STOP     = 'boarded_at_stop';
    public const EV_ALIGHTED_AT_SCHOOL  = 'alighted_at_school';
    public const EV_BOARDED_AT_SCHOOL   = 'boarded_at_school';
    public const EV_ALIGHTED_AT_STOP    = 'alighted_at_stop';

    public const CANONICAL_EVENTS = [
        self::EV_BOARDED_AT_STOP, self::EV_ALIGHTED_AT_SCHOOL,
        self::EV_BOARDED_AT_SCHOOL, self::EV_ALIGHTED_AT_STOP,
    ];

    /**
     * Legacy → canonical read aliases. Historical rows are NEVER rewritten;
     * they are interpreted. `no_show` is deliberately absent — it is a
     * resolution, not a journey event.
     */
    public const LEGACY_ALIASES = [
        'boarded_pickup' => self::EV_BOARDED_AT_STOP,
        'dropped_home'   => self::EV_ALIGHTED_AT_STOP,
    ];

    /** Which leg each event belongs to. Morning and afternoon never mix. */
    public const EVENT_LEG = [
        self::EV_BOARDED_AT_STOP    => 'Pickup',
        self::EV_ALIGHTED_AT_SCHOOL => 'Pickup',
        self::EV_BOARDED_AT_SCHOOL  => 'Drop',
        self::EV_ALIGHTED_AT_STOP   => 'Drop',
    ];

    /** Legal transitions per leg, keyed by the student's current state. */
    public const TRANSITIONS = [
        'Pickup' => [
            ''                          => [self::EV_BOARDED_AT_STOP],
            self::EV_BOARDED_AT_STOP    => [self::EV_ALIGHTED_AT_SCHOOL],
            self::EV_ALIGHTED_AT_SCHOOL => [],
        ],
        'Drop' => [
            ''                          => [self::EV_BOARDED_AT_SCHOOL],
            self::EV_BOARDED_AT_SCHOOL  => [self::EV_ALIGHTED_AT_STOP],
            self::EV_ALIGHTED_AT_STOP   => [],
        ],
    ];

    public const MODE_ASSIGNED      = 'assigned';
    public const MODE_ALTERNATE_BUS = 'alternate_bus';
    public const MODE_OPS_EXCEPTION = 'operational_exception';

    public const RES_NONE           = 'none';
    public const RES_BOARDED        = 'boarded';
    public const RES_ALTERNATE_BUS  = 'alternate_bus';
    public const RES_ABSENT         = 'absent';
    public const RES_VERIFIED_OTHER = 'verified_other';
    public const RES_UNRESOLVED     = 'unresolved';

    /** Driver-selectable resolutions. `alternate_bus` is NOT here — the server
     *  derives it from evidence; a driver may never simply assert it. */
    public const DRIVER_RESOLUTIONS = [self::RES_ABSENT, self::RES_VERIFIED_OTHER];

    public const SRC_QR     = 'qr_scan';
    public const SRC_MANUAL = 'manual';
    public const SRC_BULK   = 'bulk_school_arrival';

    /** Default campus radius when a school configured a fence without one. */
    public const DEFAULT_SCHOOL_RADIUS_M = 200;

    /** @var object|null Firestore_service */
    private $fs = null;
    /** @var object|null Transport_service — the ONLY attendance writer */
    private $transport = null;
    private string $schoolId = '';
    private string $session  = '';
    private string $actorId  = '';

    public function __construct()
    {
        $CI =& get_instance();
        $CI->load->helper('qr_token');
        $CI->load->helper('geofence');
    }

    public function init($fs, $transport, string $schoolId, string $session, string $actorId = 'system'): self
    {
        $this->fs        = $fs;
        $this->transport = $transport;
        $this->schoolId  = $schoolId;
        $this->session   = $session;
        $this->actorId   = $actorId !== '' ? $actorId : 'system';
        return $this;
    }

    private function _assertReady(): void
    {
        if ($this->fs === null || $this->transport === null || $this->schoolId === '') {
            throw new \RuntimeException('Transport_qr_attendance_service not initialised.');
        }
    }

    /** schoolWhere()/get() return either a flat doc or ['id'=>…,'data'=>…]. */
    private function _flat($row): array
    {
        if (!is_array($row)) { return []; }
        if (isset($row['data']) && is_array($row['data'])) {
            $d = $row['data'];
            if (!empty($row['id']) && !isset($d['id'])) { $d['id'] = $row['id']; }
            return $d;
        }
        return $row;
    }

    /** Normalise a stored event type to canonical, leaving history untouched. */
    public static function canonicalEvent(string $stored): string
    {
        if (in_array($stored, self::CANONICAL_EVENTS, true)) { return $stored; }
        return self::LEGACY_ALIASES[$stored] ?? $stored;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  QR — signed tokens only on this path
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Decode a scanned string, accepting ONLY signed 3-part tokens.
     *
     * `qr_token_decode()` still returns legacy 2-part tokens with
     * `legacy => true` for older features mid-migration. An unsigned token is
     * forgeable for ANY (schoolId, studentId) — the helper says so itself — so
     * F14 fails closed regardless of what other callers tolerate.
     *
     * @return array{studentId:string} on success
     * @throws \InvalidArgumentException with a driver-safe message
     */
    public function decodeStudentToken(string $rawQr): array
    {
        $this->_assertReady();
        $raw = trim($rawQr);
        if ($raw === '') {
            throw new \InvalidArgumentException('Nothing was scanned. Try again.');
        }
        $decoded = qr_token_decode($raw);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('This QR code is not a valid student ID.');
        }
        if (!empty($decoded['legacy'])) {
            log_message('error',
                "F14 scan REJECTED unsigned legacy token school={$this->schoolId} actor={$this->actorId}");
            throw new \InvalidArgumentException(
                'This ID card is an older unsigned version and cannot be used for bus attendance. '
                . 'Please have it reissued.');
        }

        // ── SEC-ID-1 (Phase 19L) — CANONICAL CREDENTIAL AUTHORITY ────────
        // F14's own unsigned-token rejection above is KEPT and runs FIRST:
        // this consumer has always been stricter than the school-wide policy
        // (a bus is an unsupervised boarding decision), and deferring to the
        // shared authority must not relax it. What the authority adds is the
        // card-LIFECYCLE verdict — superseded and revoked — which F14 had no
        // way to know on its own.
        //
        // ⚠ Inert today: every flag ships false, so this accepts exactly what
        // F14 accepted before. Enforcement is a later, evidence-gated flip.
        $CI = &get_instance();
        $CI->load->library('Id_card_service', null, 'idCardSvcF14');
        if (!$CI->idCardSvcF14->isReady()) {
            // Firebase comes from the CI instance, NOT from $this — this
            // service holds no `firebase` property. Passing a non-existent
            // property would leave the card service `ready === false`, which
            // is silent today (flags off) but would make currentVersion()
            // return 0 for every student the moment enforcement is enabled —
            // rejecting the entire school at the bus door. Verified against
            // the property list, not assumed.
            $CI->idCardSvcF14->init(
                $CI->firebase ?? null, $this->fs, $this->schoolId, $this->actorId
            );
        }
        if (!$CI->idCardSvcF14->isReady()) {
            // Fail SAFE, not closed, and WITHOUT short-circuiting: the card
            // service could not be built, so fall through to F14's own
            // remaining checks (the tenant arm below) rather than either
            // refusing a valid boarding or — worse — returning early and
            // skipping tenant isolation.
            log_message('error',
                "SEC-ID-1 F14 — Id_card_service unavailable; falling back to F14-only validation school={$this->schoolId}");
        } else {
            $cred = $CI->idCardSvcF14->validateCredential($decoded, \Id_card_service::configuredFlags());
            if (empty($cred['valid'])) {
                log_message('error',
                    "SEC-ID-1 F14 scan REJECTED ({$cred['code']}) school={$this->schoolId} actor={$this->actorId}");
                throw new \InvalidArgumentException(
                    \Id_card_service::rejectionMessage((string) $cred['code']));
            }
        }
        // Tenant arm 1 of 3: the token's own school must be this school.
        if ((string) ($decoded['schoolId'] ?? '') !== $this->schoolId) {
            log_message('error',
                'F14 scan REJECTED cross-tenant token school=' . $this->schoolId
                . ' token_school=' . (string) ($decoded['schoolId'] ?? '?'));
            throw new \InvalidArgumentException('This ID card belongs to a different school.');
        }
        return ['studentId' => (string) $decoded['studentId']];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Eligibility — studentRoutes is the authority (F-1)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Resolve whether a student may travel on `$onDate` in `$direction`.
     *
     * This is the gate the whole feature turns on: a student can exist in SIS,
     * hold a perfectly valid QR, and still have NO transport facility. Such a
     * scan must produce nothing at all — no event, no count, no notification.
     *
     * Checks, in order: an assignment exists for this session → status Active →
     * effective_from/to window → not currently suspended → type covers this
     * direction → service_days covers this weekday.
     *
     * @return array{eligible:bool, reason:string, message:string, assignment:array}
     */
    public function resolveEligibility(string $studentId, string $onDate, string $direction): array
    {
        $this->_assertReady();
        $no = static fn(string $reason, string $msg) => [
            'eligible' => false, 'reason' => $reason, 'message' => $msg, 'assignment' => [],
        ];

        if ($studentId === '') { return $no('no_student', 'No student was identified.'); }

        $rows = [];
        try {
            $rows = (array) $this->fs->schoolWhere('studentRoutes',
                [['studentId', '==', $studentId]], null, 'ASC', 50);
        } catch (\Throwable $e) {
            log_message('error', 'F14 eligibility lookup failed: ' . $e->getMessage());
            // Fail CLOSED. An infrastructure error must never read as "eligible".
            return $no('lookup_failed', 'Could not verify transport enrolment. Try again.');
        }

        $dayName = date('l', strtotime($onDate));
        $candidates = [];
        foreach ($rows as $row) {
            $a = $this->_flat($row);
            if ($a === []) { continue; }
            if ((string) ($a['session'] ?? '') !== $this->session) { continue; }
            $candidates[] = $a;
        }
        if ($candidates === []) {
            return $no('not_enrolled', 'Student is not enrolled for Transport.');
        }

        $lastReason = 'not_enrolled';
        $lastMsg    = 'Student is not enrolled for Transport.';
        foreach ($candidates as $a) {
            if ((string) ($a['status'] ?? '') !== 'Active') {
                $lastReason = 'inactive';
                $lastMsg    = 'Student\'s transport facility is not active.';
                continue;
            }
            // Effective window. Empty bound = open-ended.
            $from = trim((string) ($a['effective_from'] ?? ''));
            $to   = trim((string) ($a['effective_to'] ?? ''));
            if ($from !== '' && $onDate < $from) {
                $lastReason = 'not_yet_effective';
                $lastMsg    = 'Student\'s transport starts on ' . $from . '.';
                continue;
            }
            if ($to !== '' && $onDate > $to) {
                $lastReason = 'expired';
                $lastMsg    = 'Student\'s transport ended on ' . $to . '.';
                continue;
            }
            // Suspension window.
            $sFrom = trim((string) ($a['suspended_from']  ?? ''));
            $sTo   = trim((string) ($a['suspended_until'] ?? ''));
            if ($sFrom !== '' && $onDate >= $sFrom && ($sTo === '' || $onDate <= $sTo)) {
                $lastReason = 'suspended';
                $lastMsg    = 'Student\'s transport is suspended.';
                continue;
            }
            // Direction cover. Missing type = both (backward compatible).
            $type = strtolower(trim((string) ($a['type'] ?? 'both')));
            if ($type === '') { $type = 'both'; }
            if ($direction === 'Pickup' && !in_array($type, ['pickup', 'both'], true)) {
                $lastReason = 'direction_not_covered';
                $lastMsg    = 'Student is not enrolled for the morning pickup.';
                continue;
            }
            if ($direction === 'Drop' && !in_array($type, ['drop', 'both'], true)) {
                $lastReason = 'direction_not_covered';
                $lastMsg    = 'Student is not enrolled for the afternoon drop.';
                continue;
            }
            // Service days, compared on the 3-letter stem: real assignments
            // store "Mon" while date('l') yields "Monday". A strict compare
            // silently excluded every student (fixed 2026-08-12).
            $days = (array) ($a['service_days'] ?? []);
            if ($days !== []) {
                $stem = static fn($d) => strtolower(substr(trim((string) $d), 0, 3));
                if (!in_array($stem($dayName), array_map($stem, $days), true)) {
                    $lastReason = 'not_service_day';
                    $lastMsg    = 'Student does not travel on ' . $dayName . '.';
                    continue;
                }
            }
            return ['eligible' => true, 'reason' => 'ok', 'message' => '', 'assignment' => $a];
        }
        return $no($lastReason, $lastMsg);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Per-student journey state on one trip
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Current canonical state of a student on a trip: '' | boarded_* |
     * alighted_*. Derived from stored events, legacy types included.
     */
    public function studentStateOnTrip(string $tripId, string $studentId): string
    {
        $this->_assertReady();
        $rows = [];
        try {
            $rows = (array) $this->fs->schoolWhere('transportAttendance',
                [['tripId', '==', $tripId], ['studentId', '==', $studentId]], null, 'ASC', 20);
        } catch (\Throwable $e) {
            log_message('error', 'F14 state lookup failed: ' . $e->getMessage());
            throw new \RuntimeException('Could not read attendance state.');
        }
        $seen = [];
        foreach ($rows as $row) {
            $r = $this->_flat($row);
            $ev = self::canonicalEvent((string) ($r['event_type'] ?? ''));
            if (in_array($ev, self::CANONICAL_EVENTS, true)) { $seen[$ev] = true; }
        }
        // Alighted outranks boarded; a student cannot un-alight.
        foreach ([self::EV_ALIGHTED_AT_SCHOOL, self::EV_ALIGHTED_AT_STOP,
                  self::EV_BOARDED_AT_SCHOOL, self::EV_BOARDED_AT_STOP] as $ev) {
            if (isset($seen[$ev])) { return $ev; }
        }
        return '';
    }

    /**
     * Validate a proposed transition.
     *
     * @return array{ok:bool, idempotent:bool, message:string}
     */
    public function validateTransition(string $leg, string $currentState, string $proposed): array
    {
        if (!in_array($proposed, self::CANONICAL_EVENTS, true)) {
            return ['ok' => false, 'idempotent' => false, 'message' => 'Unknown attendance event.'];
        }
        if ((self::EVENT_LEG[$proposed] ?? '') !== $leg) {
            return ['ok' => false, 'idempotent' => false,
                    'message' => 'That action does not belong to this trip\'s journey.'];
        }
        // Re-scanning the same event is a no-op, not an error and not a
        // second row — the driver just gets told it is already recorded.
        if ($currentState === $proposed) {
            return ['ok' => false, 'idempotent' => true, 'message' => 'Already recorded.'];
        }
        $allowed = self::TRANSITIONS[$leg][$currentState] ?? [];
        if (!in_array($proposed, $allowed, true)) {
            if ($currentState === '') {
                return ['ok' => false, 'idempotent' => false,
                        'message' => 'Student has not boarded yet.'];
            }
            return ['ok' => false, 'idempotent' => false,
                    'message' => 'This student has already completed this journey.'];
        }
        return ['ok' => true, 'idempotent' => false, 'message' => ''];
    }

    /** Leg for a trip: Pickup = morning, Drop = afternoon. */
    public function legOfTrip(array $trip): string
    {
        $d = (string) ($trip['trip_direction'] ?? '');
        if ($d === 'Pickup' || $d === 'Drop') { return $d; }
        // Older trips carry only tripType.
        return strtolower((string) ($trip['tripType'] ?? '')) === 'afternoon' ? 'Drop' : 'Pickup';
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Alternate bus — evidence first, driver assertion last
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Did this student board some OTHER trip today? Used to reconcile a student
     * who is expected on this bus but never boarded it.
     *
     * Evidence beats assertion: if canonical attendance already shows the
     * student boarding another vehicle, the assigned bus reconciles
     * automatically and the driver is never asked. A driver may not claim
     * "alternate bus" for a case the system can verify — nor be blamed for one.
     *
     * @return array{found:bool, trip_id:string, vehicle_id:string, route_id:string, event_at:string}
     */
    public function findBoardingOnOtherTrip(string $studentId, string $tripDate, string $excludeTripId): array
    {
        $this->_assertReady();
        $none = ['found' => false, 'trip_id' => '', 'vehicle_id' => '', 'route_id' => '', 'event_at' => ''];
        try {
            $rows = (array) $this->fs->schoolWhere('transportAttendance',
                [['studentId', '==', $studentId], ['trip_date', '==', $tripDate]], null, 'ASC', 50);
        } catch (\Throwable $e) {
            log_message('error', 'F14 alternate-bus lookup failed: ' . $e->getMessage());
            return $none;   // no evidence ⇒ stays unresolved, never auto-cleared
        }
        foreach ($rows as $row) {
            $r  = $this->_flat($row);
            $tid = (string) ($r['tripId'] ?? '');
            if ($tid === '' || $tid === $excludeTripId) { continue; }
            $ev = self::canonicalEvent((string) ($r['event_type'] ?? ''));
            if ($ev !== self::EV_BOARDED_AT_STOP && $ev !== self::EV_BOARDED_AT_SCHOOL) { continue; }
            return [
                'found'      => true,
                'trip_id'    => $tid,
                'vehicle_id' => (string) ($r['actual_vehicle_id'] ?? $r['vehicleId'] ?? ''),
                'route_id'   => (string) ($r['actual_route_id'] ?? $r['route_id'] ?? ''),
                'event_at'   => (string) ($r['event_at'] ?? ''),
            ];
        }
        return $none;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  School-arrival geofence — consumes F13.1, creates no GPS system
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Is the bus actually at school?
     *
     * Reads the bus position from F13.1 `vehicleLivePositions` and the campus
     * fence from `schools.attendancePolicy.gps.geofences[]` — the SAME fence
     * Staff Attendance uses. No second GPS system, no second fence store, and a
     * driver-supplied coordinate is never trusted.
     *
     * @return array{ok:bool, reason:string, message:string, distance_m:?int, fence:string}
     */
    /**
     * Unit 2B — is a USABLE campus geofence configured for this school?
     *
     * Read-only. Reads the SAME store verifyBusAtSchool() reads —
     * `schools.attendancePolicy.gps.geofences[]` — and applies the SAME
     * validity rule its fence loop applies (numeric centerLat/centerLng).
     * There is deliberately no second geofence system and no second copy of
     * the maths: a fence this returns false for is exactly a fence
     * verifyBusAtSchool() would skip.
     *
     * A fence present but malformed therefore counts as ABSENT — otherwise a
     * Pickup trip would pass creation and still be uncompletable, which is the
     * very failure this prerequisite exists to prevent.
     */
    public function campusGeofenceConfigured(): bool
    {
        $this->_assertReady();
        try {
            $school = $this->fs->get('schools', $this->schoolId);
            $school = is_array($school) ? $this->_flat($school) : [];
            $pol    = (array) ($school['attendancePolicy'] ?? []);
            return $this->campusFences((array) ($pol['gps'] ?? [])) !== [];
        } catch (\Throwable $e) {
            // Fail CLOSED: if the policy cannot be read we cannot claim a fence
            // exists, and a trip that cannot be completed must not be created.
            log_message('error', 'Unit 2B campusGeofenceConfigured read failed: ' . $e->getMessage());
        }
        return false;
    }

    /**
     * THE single reader of the campus geofence store — both shapes.
     *
     * The tenant policy has carried two shapes since the multi-campus change:
     *
     *   gps.geofences[]  — current, multi-campus
     *   gps.geofence     — legacy, single campus (still written by older data
     *                      and still READ by Attendance → Settings → GPS
     *                      Attendance, which coalesces exactly like this:
     *                      "prefer gps.geofences[]; else wrap the legacy
     *                      singular" — views/attendance/settings.php:1412)
     *
     * Unit 2B originally read the plural only. A tenant that configured its
     * campus before the migration and never re-saved therefore had a perfectly
     * good fence that createTrip() could not see, so every Pickup trip was
     * refused with a message telling the operator to configure a campus they
     * had already configured. Found on a live tenant (IIT Kanpur: legacy
     * singular present, plural absent), invisible to the probe tenant because
     * it stores the plural shape.
     *
     * Returning the fences rather than a bool keeps ONE validity rule and ONE
     * reader for both callers, so creation and completion can never disagree
     * about whether a campus exists. Nothing is migrated or written here: the
     * legacy document is read as it stands.
     *
     * @param  array $gps  attendancePolicy.gps
     * @return array<int,array>  valid fences, normalised; [] when none
     */
    public function campusFences(array $gps): array
    {
        $candidates = (array) ($gps['geofences'] ?? []);
        if ($candidates === []) {
            $legacy = $gps['geofence'] ?? null;      // legacy singular
            if (is_array($legacy) && $legacy !== []) { $candidates = [$legacy]; }
        }

        $valid = [];
        foreach ($candidates as $f) {
            if (!is_array($f)) { continue; }
            $lat = $f['centerLat'] ?? null;
            $lng = $f['centerLng'] ?? null;
            // Same rule as before — a malformed fence counts as ABSENT, so a
            // Pickup trip can never pass creation and be uncompletable.
            if (is_numeric($lat) && is_numeric($lng) && gf_valid_coord((float) $lat, (float) $lng)) {
                $valid[] = $f;
            }
        }
        return $valid;
    }

    public function verifyBusAtSchool(string $vehicleId): array
    {
        $this->_assertReady();
        $fail = static fn(string $reason, string $msg, ?int $d = null) => [
            'ok' => false, 'reason' => $reason, 'message' => $msg, 'distance_m' => $d, 'fence' => '',
        ];

        // 1. Campus fences.
        $school = $this->fs->get('schools', $this->schoolId);
        $school = is_array($school) ? $this->_flat($school) : [];
        $pol    = (array) ($school['attendancePolicy'] ?? []);
        $gps    = (array) ($pol['gps'] ?? []);
        // Both shapes, one reader — see campusFences(). Creation and completion
        // MUST agree about whether a campus exists: if this read the plural only
        // while createTrip() accepted the legacy singular, a legacy tenant could
        // create a Pickup trip and then never be able to complete it.
        $fences = $this->campusFences($gps);
        if ($fences === []) {
            return $fail('no_geofence',
                'The school location is not configured, so arrival at school cannot be confirmed. '
                . 'Ask the office to set the campus location.');
        }
        $tolerance = (float) ($gps['boundaryToleranceMeters'] ?? 0);
        $maxAcc    = (float) ($gps['maxAccuracyMeters'] ?? 100);

        // 2. Bus position — F13.1, read-only.
        $pos = $this->fs->getEntity('vehicleLivePositions', $vehicleId);
        $pos = is_array($pos) ? $this->_flat($pos) : [];
        if ($pos === []) {
            return $fail('no_position',
                'No GPS position has been received from this bus yet, so arrival at school cannot be '
                . 'confirmed.');
        }
        // array_key_exists, NOT ??: a stored null must read as "absent", and
        // `$pos['lat'] ?? null === null` collapses both cases into one.
        $hasLat = array_key_exists('lat', $pos) && $pos['lat'] !== null;
        $hasLng = array_key_exists('lng', $pos) && $pos['lng'] !== null;
        if (!$hasLat || !$hasLng) {
            return $fail('no_fix',
                'The bus has not reported a GPS fix yet. Arrival at school cannot be confirmed.');
        }
        $lat = (float) $pos['lat'];
        $lng = (float) $pos['lng'];
        if (!gf_valid_coord($lat, $lng)) {
            return $fail('bad_fix', 'The bus GPS position is not usable.');
        }
        // Freshness — reuse F13.1's own staleness ceiling.
        $ms  = (int) ($pos['recorded_at_ms'] ?? 0);
        $age = $ms > 0 ? max(0, time() - (int) round($ms / 1000)) : PHP_INT_MAX;
        $stale = defined('TRANSPORT_GPS_STALE_AFTER_S')
            ? TRANSPORT_GPS_STALE_AFTER_S
            : (class_exists('Transport_gps_service') ? Transport_gps_service::STALE_AFTER_S : 120);
        if ($age > $stale) {
            return $fail('stale_fix',
                'The bus GPS position is too old to confirm arrival at school. Check that tracking '
                . 'is running.');
        }
        $acc = array_key_exists('accuracy_m', $pos) && $pos['accuracy_m'] !== null
            ? (float) $pos['accuracy_m'] : null;
        if ($acc !== null && !gf_accuracy_acceptable($acc, $maxAcc)) {
            return $fail('poor_accuracy',
                'The bus GPS position is not precise enough to confirm arrival at school.');
        }

        // 3. Nearest fence.
        $best = null; $bestName = '';
        foreach ($fences as $f) {
            $flat = $f['centerLat'] ?? null; $flng = $f['centerLng'] ?? null;
            if (!is_numeric($flat) || !is_numeric($flng)) { continue; }
            $radius = (float) ($f['radius'] ?? 0);
            if ($radius <= 0) { $radius = self::DEFAULT_SCHOOL_RADIUS_M; }
            $d = gf_haversine($lat, $lng, (float) $flat, (float) $flng);
            if (gf_within_radius($d, $radius, $tolerance)) {
                return ['ok' => true, 'reason' => 'inside', 'message' => '',
                        'distance_m' => (int) round($d),
                        'fence' => (string) ($f['name'] ?? $f['id'] ?? 'campus')];
            }
            if ($best === null || $d < $best) { $best = $d; $bestName = (string) ($f['name'] ?? ''); }
        }
        return $fail('outside_geofence',
            'The bus is not at school yet'
            . ($best !== null ? ' (about ' . (int) round($best) . ' m away)' : '')
            . '. Arrival can only be recorded at the school.',
            $best !== null ? (int) round($best) : null);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Trip + attendance loading
    // ─────────────────────────────────────────────────────────────────────

    /** Load a trip or fail. Public so the API layer can reuse one loader. */
    public function loadTrip(string $tripId): array
    {
        $this->_assertReady();
        $t = $this->fs->getEntity('tripLogs', $tripId);
        if (!is_array($t)) { throw new \InvalidArgumentException('Trip not found.'); }
        $t = $this->_flat($t);
        if ((string) ($t['schoolId'] ?? '') !== $this->schoolId) {
            // Tenant arm 3 of 3.
            log_message('error', "F14 cross-tenant trip access school={$this->schoolId} trip={$tripId}");
            throw new \InvalidArgumentException('Trip not found.');
        }
        return $t;
    }

    /** All attendance rows for a trip, keyed studentId → canonical state. */
    private function _statesForTrip(string $tripId): array
    {
        $out = [];
        try {
            $rows = (array) $this->fs->schoolWhere('transportAttendance',
                [['tripId', '==', $tripId]], null, 'ASC', 1000);
        } catch (\Throwable $e) {
            log_message('error', 'F14 trip attendance read failed: ' . $e->getMessage());
            throw new \RuntimeException('Could not read attendance for this trip.');
        }
        foreach ($rows as $row) {
            $r   = $this->_flat($row);
            $sid = (string) ($r['studentId'] ?? '');
            if ($sid === '') { continue; }
            $ev  = self::canonicalEvent((string) ($r['event_type'] ?? ''));
            $cur = $out[$sid] ?? ['state' => '', 'resolution' => self::RES_NONE, 'row' => []];
            if (in_array($ev, self::CANONICAL_EVENTS, true)) {
                // Alighted outranks boarded — a student cannot un-alight.
                $rank = [
                    '' => 0, self::EV_BOARDED_AT_STOP => 1, self::EV_BOARDED_AT_SCHOOL => 1,
                    self::EV_ALIGHTED_AT_SCHOOL => 2, self::EV_ALIGHTED_AT_STOP => 2,
                ];
                if (($rank[$ev] ?? 0) >= ($rank[$cur['state']] ?? 0)) {
                    $cur['state'] = $ev;
                    $cur['row']   = $r;
                }
            }
            // A stored no_show / explicit resolution.
            $rs = (string) ($r['resolution_state'] ?? '');
            if ($rs !== '' && $rs !== self::RES_NONE) {
                $cur['resolution'] = $rs;
            } elseif ((string) ($r['event_type'] ?? '') === 'no_show'
                      && $cur['resolution'] === self::RES_NONE) {
                $cur['resolution'] = self::RES_ABSENT;
            }
            $out[$sid] = $cur;
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Driver roster — the operational list the app was missing
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The driver's current-trip roster plus counts and unresolved detail.
     *
     * Expected population = the trip's FROZEN roster (never recomputed, or the
     * list would shift under the driver mid-trip). Alternate-bus arrivals are
     * appended as off-roster passengers actually aboard.
     */
    public function tripRoster(string $tripId): array
    {
        $trip   = $this->loadTrip($tripId);
        $leg    = $this->legOfTrip($trip);
        $states = $this->_statesForTrip($tripId);
        $expected = array_values(array_unique(array_map('strval',
            (array) ($trip['roster_student_ids'] ?? []))));
        $tripDate = (string) ($trip['trip_date'] ?? date('Y-m-d'));

        $boardEv  = $leg === 'Pickup' ? self::EV_BOARDED_AT_STOP    : self::EV_BOARDED_AT_SCHOOL;
        $alightEv = $leg === 'Pickup' ? self::EV_ALIGHTED_AT_SCHOOL : self::EV_ALIGHTED_AT_STOP;

        $students = [];
        $c = ['expected' => count($expected), 'boarded' => 0, 'alighted' => 0, 'on_board' => 0,
              'alternate' => 0, 'absent' => 0, 'resolved_other' => 0, 'unresolved' => 0,
              'off_roster_aboard' => 0];

        foreach ($expected as $sid) {
            $st  = $states[$sid] ?? ['state' => '', 'resolution' => self::RES_NONE, 'row' => []];
            $stu = $this->_studentBrief($sid);
            $row = [
                'student_id'   => $sid,
                'name'         => $stu['name'],
                'class'        => $stu['class'],
                'photo_url'    => $stu['photo_url'],
                'assigned_stop_id'   => '',
                'assigned_stop_name' => '',
                'assigned_route_id'  => (string) ($trip['route_id'] ?? ''),
                'state'        => $st['state'],
                'boarded'      => $st['state'] === $boardEv || $st['state'] === $alightEv,
                'alighted'     => $st['state'] === $alightEv,
                'on_roster'    => true,
                'boarding_mode'=> (string) ($st['row']['boarding_mode'] ?? ''),
                'resolution'   => $st['resolution'],
                'actual_vehicle_id' => '',
                'actual_trip_id'    => '',
            ];
            if ($row['alighted'])      { $c['alighted']++; $c['boarded']++; }
            elseif ($row['boarded'])   { $c['boarded']++;  $c['on_board']++; }

            if (!$row['boarded']) {
                // Not on this bus. Reconcile from evidence BEFORE calling it an
                // exception — a child who boarded another bus is accounted for,
                // and the driver should never be asked about a case the system
                // can already answer.
                if ($st['resolution'] === self::RES_ABSENT)              { $c['absent']++; }
                elseif ($st['resolution'] === self::RES_VERIFIED_OTHER)  { $c['resolved_other']++; }
                else {
                    $alt = $this->findBoardingOnOtherTrip($sid, $tripDate, $tripId);
                    if ($alt['found']) {
                        $row['resolution']        = self::RES_ALTERNATE_BUS;
                        $row['actual_vehicle_id'] = $alt['vehicle_id'];
                        $row['actual_trip_id']    = $alt['trip_id'];
                        $c['alternate']++;
                    } else {
                        $row['resolution'] = self::RES_UNRESOLVED;
                        $c['unresolved']++;
                    }
                }
            } else {
                $row['resolution'] = self::RES_BOARDED;
            }
            $students[] = $row;
        }

        // Off-roster passengers actually aboard this bus (alternate-bus inbound).
        foreach ($states as $sid => $st) {
            if (in_array($sid, $expected, true)) { continue; }
            if ($st['state'] === '') { continue; }
            $stu = $this->_studentBrief($sid);
            $aboard = $st['state'] === $boardEv;
            if ($aboard) { $c['on_board']++; }
            if ($st['state'] === $alightEv) { $c['alighted']++; }
            if ($st['state'] === $boardEv || $st['state'] === $alightEv) {
                $c['boarded']++; $c['off_roster_aboard']++;
            }
            $students[] = [
                'student_id' => $sid, 'name' => $stu['name'], 'class' => $stu['class'],
                'photo_url'  => $stu['photo_url'],
                'assigned_stop_id' => '', 'assigned_stop_name' => '',
                'assigned_route_id' => (string) ($st['row']['assigned_route_id'] ?? ''),
                'state' => $st['state'], 'boarded' => true,
                'alighted' => $st['state'] === $alightEv,
                'on_roster' => false,
                'boarding_mode' => (string) ($st['row']['boarding_mode'] ?? self::MODE_ALTERNATE_BUS),
                'resolution' => self::RES_BOARDED,
                'actual_vehicle_id' => (string) ($trip['vehicleId'] ?? ''),
                'actual_trip_id'    => $tripId,
            ];
        }

        return [
            'trip_id'    => $tripId,
            'leg'        => $leg,
            'direction'  => (string) ($trip['trip_direction'] ?? ''),
            'trip_date'  => $tripDate,
            'status'     => (string) ($trip['status'] ?? ''),
            'route_id'   => (string) ($trip['route_id'] ?? ''),
            'route_name' => (string) ($trip['route_snapshot']['name'] ?? ''),
            'vehicle_id' => (string) ($trip['vehicleId'] ?? ''),
            'counts'     => $c,
            'students'   => $students,
        ];
    }

    /** Name/class/photo for the driver's list. Missing student ⇒ safe blanks. */
    private function _studentBrief(string $studentId): array
    {
        $blank = ['name' => $studentId, 'class' => '', 'photo_url' => ''];
        try {
            $s = $this->fs->getEntity('students', $studentId);
        } catch (\Throwable $_) { return $blank; }
        if (!is_array($s)) { return $blank; }
        $s = $this->_flat($s);
        $cls = trim((string) ($s['class'] ?? ''));
        $sec = trim((string) ($s['section'] ?? ''));
        return [
            'name'      => (string) ($s['name'] ?? $s['student_name'] ?? $studentId),
            'class'     => trim($cls . ($sec !== '' ? '-' . $sec : '')),
            'photo_url' => (string) ($s['photo_url'] ?? $s['photo'] ?? ''),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Completion gate
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Why a trip may not be completed yet.
     *
     * MORNING: counts need not balance — a child may legitimately not travel.
     * Every EXPECTED student must merely reach a resolved state
     * (boarded→alighted | alternate_bus | absent | verified_other). Only
     * genuinely unknown students block.
     *
     * AFTERNOON: every student who actually BOARDED must have alighted at a
     * stop. There is no bulk action on this leg, by design — a child being let
     * off at the right place is the whole point.
     *
     * Morning and afternoon totals are never compared.
     *
     * @return array{blocked:bool, reason:string, message:string, students:array}
     */
    /**
     * @param bool $skipArrivalCheck F14 Phase 2A — suppress ONLY the geofence
     *        arrival check, for an audited admin override. Everything after it
     *        still runs.
     *
     *        This parameter exists because `school_not_reached` returns EARLY:
     *        when GPS cannot prove arrival, `unresolved_students` and
     *        `still_on_board` are never reached. An override implemented by
     *        merely ignoring the returned reason would therefore have silently
     *        bypassed BOTH student-safety blockers as well. Skipping the check
     *        here — rather than discarding its verdict at the call site — keeps
     *        those two blockers authoritative under override.
     *
     *        Default false: ordinary completion behaviour is unchanged.
     */
    public function completionBlockers(string $tripId, bool $skipArrivalCheck = false): array
    {
        $r   = $this->tripRoster($tripId);
        $leg = $r['leg'];
        $c   = $r['counts'];

        if ($leg === 'Pickup') {
            // ── PART A GUARD (2026-08-12) ────────────────────────────────
            // A school-bound trip may not complete just because the driver
            // pressed a button: the bus must be provably AT school. Audited and
            // found unenforced — Transport_service::completeTrip() checked only
            // status and Pending checkpoints, so a Pickup trip could complete
            // from anywhere, with no GPS at all.
            //
            // Reuses F13.1 telemetry and the shared campus geofence. No second
            // GPS system, and a driver-supplied coordinate is never trusted.
            // Applies to Pickup ONLY — the afternoon leg ends at the stops, not
            // at school, and must keep its own state machine.
            //
            // $skipArrivalCheck (F14 Phase 2A) suppresses ONLY this block. The
            // two student-safety blockers below still decide the outcome.
            if (!$skipArrivalCheck) {
                $trip = $this->loadTrip($tripId);
                $geo  = $this->verifyBusAtSchool((string) ($trip['vehicleId'] ?? ''));
                if (!$geo['ok']) {
                    return ['blocked' => true, 'reason' => 'school_not_reached',
                        'message' => $geo['message'], 'students' => [],
                        'geo_reason' => $geo['reason'], 'distance_m' => $geo['distance_m']];
                }
            }

            $stuck = array_values(array_filter($r['students'], static fn($s) =>
                $s['resolution'] === self::RES_UNRESOLVED));
            if ($stuck !== []) {
                return ['blocked' => true, 'reason' => 'unresolved_students',
                    'message' => count($stuck) . ' student(s) are not accounted for.',
                    'students' => $stuck];
            }
            // A student still physically aboard at the end of the morning leg is
            // the one failure mode that must never pass quietly.
            $aboard = array_values(array_filter($r['students'], static fn($s) =>
                $s['boarded'] && !$s['alighted']));
            if ($aboard !== []) {
                return ['blocked' => true, 'reason' => 'still_on_board',
                    'message' => count($aboard) . ' student(s) are still marked on board.',
                    'students' => $aboard];
            }
            return ['blocked' => false, 'reason' => '', 'message' => '', 'students' => []];
        }

        $aboard = array_values(array_filter($r['students'], static fn($s) =>
            $s['boarded'] && !$s['alighted']));
        if ($aboard !== []) {
            return ['blocked' => true, 'reason' => 'not_all_alighted',
                'message' => count($aboard) . ' student(s) have not been dropped off yet.',
                'students' => $aboard];
        }
        return ['blocked' => false, 'reason' => '', 'message' => '', 'students' => []];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  SCAN — the whole chain, in one place
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Process one QR scan against one trip.
     *
     * Which event a scan produces is INFERRED from the leg and the student's
     * current state, so the driver never picks an event type:
     *   Pickup  : (nothing yet) → boarded_at_stop.   Morning alighting is the
     *             geofenced bulk action only — never an individual scan.
     *   Drop    : (nothing yet) → boarded_at_school; boarded → alighted_at_stop.
     *
     * @param array $ctx driver_staff_id, client_event_id, client_event_at,
     *                   confirm_alternate (bool), scan_source
     * @return array status ok|already|needs_confirmation|rejected + message
     */
    public function scan(string $rawQr, string $tripId, array $ctx = []): array
    {
        $this->_assertReady();

        // 1. Token → student (signed only, tenant-bound).
        $studentId = $this->decodeStudentToken($rawQr)['studentId'];

        // 2. Trip must exist, be this school's, and be running.
        $trip = $this->loadTrip($tripId);
        if ((string) ($trip['status'] ?? '') !== 'InProgress') {
            return $this->_reject('trip_not_running',
                'This trip is not running. Start the trip first.');
        }
        $leg      = $this->legOfTrip($trip);
        $tripDate = (string) ($trip['trip_date'] ?? date('Y-m-d'));

        // 3. Transport entitlement — the hard gate. A valid QR proves identity,
        //    never entitlement. No event, no count, no notification, no roster
        //    insertion for a student without active transport.
        $elig = $this->resolveEligibility($studentId, $tripDate, $leg);
        if (!$elig['eligible']) {
            log_message('error',
                "F14 scan REJECTED not-eligible student={$studentId} reason={$elig['reason']} "
                . "trip={$tripId} school={$this->schoolId}");
            return $this->_reject($elig['reason'], $elig['message']);
        }
        $assign = $elig['assignment'];

        // 4. Roster vs alternate bus.
        $roster    = array_map('strval', (array) ($trip['roster_student_ids'] ?? []));
        $onRoster  = in_array($studentId, $roster, true);
        $confirmed = !empty($ctx['confirm_alternate']);
        if (!$onRoster && !$confirmed) {
            // Do NOT silently add them, and do NOT simply refuse — a child
            // standing at the door of the wrong bus is a real situation.
            $brief = $this->_studentBrief($studentId);
            return [
                'status'  => 'needs_confirmation',
                'reason'  => 'assigned_to_another_bus',
                'message' => 'Student is assigned to another bus.',
                'student' => ['student_id' => $studentId] + $brief,
                'assigned' => [
                    'route_id' => (string) ($assign['route_id'] ?? ''),
                    'stop_id'  => (string) ($assign['stop_id']  ?? ''),
                ],
                'actions' => ['cancel', 'board_as_alternate_bus'],
            ];
        }

        // 5. Which event does this scan mean?
        $state = $this->studentStateOnTrip($tripId, $studentId);
        if ($leg === 'Pickup') {
            $event = self::EV_BOARDED_AT_STOP;
        } else {
            $event = $state === '' ? self::EV_BOARDED_AT_SCHOOL : self::EV_ALIGHTED_AT_STOP;
        }

        // 6. State machine.
        $t = $this->validateTransition($leg, $state, $event);
        if (!$t['ok']) {
            $brief = $this->_studentBrief($studentId);
            return [
                'status'  => $t['idempotent'] ? 'already' : 'rejected',
                'reason'  => $t['idempotent'] ? 'duplicate' : 'invalid_transition',
                'message' => $t['message'],
                'student' => ['student_id' => $studentId] + $brief,
            ];
        }

        // 7. Write through the ONE canonical writer.
        $mode = $onRoster ? self::MODE_ASSIGNED : self::MODE_ALTERNATE_BUS;
        $opts = [
            'marked_by_role'      => 'driver',
            'scan_source'         => (string) ($ctx['scan_source'] ?? self::SRC_QR),
            'allow_off_roster'    => !$onRoster,
            'boarding_mode'       => $mode,
            'assigned_route_id'   => (string) ($assign['route_id'] ?? ''),
            'assigned_stop_id'    => (string) ($assign['stop_id']  ?? ''),
            'assigned_vehicle_id' => $this->_vehicleOfRoute((string) ($assign['route_id'] ?? '')),
            'actual_route_id'     => (string) ($trip['route_id']  ?? ''),
            'actual_vehicle_id'   => (string) ($trip['vehicleId'] ?? ''),
            'client_event_id'     => (string) ($ctx['client_event_id'] ?? ''),
            'client_event_at'     => (string) ($ctx['client_event_at'] ?? ''),
        ];
        if ($mode === self::MODE_ALTERNATE_BUS) {
            $opts['alternate_reason'] = 'missed_assigned_bus';
        }
        // GPS-at-scan from F13.1 telemetry — never a client-supplied coordinate.
        $opts += $this->_gpsAtScan((string) ($trip['vehicleId'] ?? ''));

        $written = $this->_writeCanonical($tripId, $studentId, $event, $opts,
            $event === self::EV_ALIGHTED_AT_STOP ? (string) ($assign['stop_id'] ?? '') : (string) ($assign['stop_id'] ?? ''));

        // 8. Notify the parent (existing engine, dedupe key = the event doc id).
        $this->_notifyEvent($event, $trip, $studentId, $written['docId'], $mode);

        $brief = $this->_studentBrief($studentId);
        return [
            'status'  => 'ok',
            'event'   => $event,
            'mode'    => $mode,
            'doc_id'  => $written['docId'],
            'message' => $this->_confirmText($event, $mode, $brief['name']),
            'student' => ['student_id' => $studentId] + $brief,
        ];
    }

    private function _reject(string $reason, string $message): array
    {
        return ['status' => 'rejected', 'reason' => $reason, 'message' => $message];
    }

    /** Route → its vehicle (the link lives on the route, not the vehicle). */
    private function _vehicleOfRoute(string $routeId): string
    {
        if ($routeId === '') { return ''; }
        try {
            $r = $this->fs->getEntity('routes', $routeId);
        } catch (\Throwable $_) { return ''; }
        return is_array($r) ? (string) ($this->_flat($r)['vehicle_id'] ?? '') : '';
    }

    /** Bus position at the moment of the scan, from F13.1. Best-effort. */
    private function _gpsAtScan(string $vehicleId): array
    {
        if ($vehicleId === '') { return []; }
        try {
            $p = $this->fs->getEntity('vehicleLivePositions', $vehicleId);
        } catch (\Throwable $_) { return []; }
        if (!is_array($p)) { return []; }
        $p = $this->_flat($p);
        $hasLat = array_key_exists('lat', $p) && $p['lat'] !== null;
        $hasLng = array_key_exists('lng', $p) && $p['lng'] !== null;
        if (!$hasLat || !$hasLng) { return []; }
        return [
            'event_lat'    => (float) $p['lat'],
            'event_lng'    => (float) $p['lng'],
            'event_at_gps' => (string) ($p['recorded_at'] ?? ''),
        ];
    }

    /** Delegate to the canonical writer for the given canonical event. */
    private function _writeCanonical(string $tripId, string $studentId, string $event, array $opts, string $stopId = ''): array
    {
        switch ($event) {
            case self::EV_BOARDED_AT_STOP:
                return $this->transport->markBoardedAtStop($tripId, $studentId, $stopId ?: null, '', $opts);
            case self::EV_ALIGHTED_AT_SCHOOL:
                return $this->transport->markAlightedAtSchool($tripId, $studentId, '', $opts);
            case self::EV_BOARDED_AT_SCHOOL:
                return $this->transport->markBoardedAtSchool($tripId, $studentId, '', $opts);
            case self::EV_ALIGHTED_AT_STOP:
                return $this->transport->markAlightedAtStop($tripId, $studentId, $stopId ?: null, '', $opts);
        }
        throw new \InvalidArgumentException('Unknown canonical event: ' . $event);
    }

    private function _confirmText(string $event, string $mode, string $name): string
    {
        $alt = $mode === self::MODE_ALTERNATE_BUS ? ' (alternate bus)' : '';
        switch ($event) {
            case self::EV_BOARDED_AT_STOP:    return $name . ' boarded' . $alt . '.';
            case self::EV_ALIGHTED_AT_SCHOOL: return $name . ' marked arrived at school.';
            case self::EV_BOARDED_AT_SCHOOL:  return $name . ' boarded for the return journey' . $alt . '.';
            case self::EV_ALIGHTED_AT_STOP:   return $name . ' dropped off.';
        }
        return 'Recorded.';
    }

    /**
     * Parent push through the EXISTING Transport_notifier — no second sender.
     * Dedupe key is the attendance doc id, so an offline re-flush of the same
     * scan cannot notify twice.
     */
    private function _notifyEvent(string $event, array $trip, string $studentId, string $docId, string $mode): void
    {
        $map = [
            self::EV_BOARDED_AT_STOP    => 'student.boarded_at_stop',
            self::EV_ALIGHTED_AT_SCHOOL => 'student.alighted_at_school',
            self::EV_BOARDED_AT_SCHOOL  => 'student.boarded_at_school',
            self::EV_ALIGHTED_AT_STOP   => 'student.alighted_at_stop',
        ];
        $category = $map[$event] ?? '';
        if ($category === '') { return; }
        try {
            $CI =& get_instance();
            if (!isset($CI->transport_notifier)) {
                $CI->load->library('transport_notifier', null, 'transport_notifier');
            }
            $n = $CI->transport_notifier;
            if (!method_exists($n, 'notify')) { return; }
            $n->notify($category, [
                'trip_id'      => (string) ($trip['id'] ?? ''),
                'student_id'   => $studentId,
                'student_ids'  => [$studentId],
                'route_id'     => (string) ($trip['route_id'] ?? ''),
                'route_name'   => (string) ($trip['route_snapshot']['name'] ?? ''),
                'vehicle_id'   => (string) ($trip['vehicleId'] ?? ''),
                'trip_date'    => (string) ($trip['trip_date'] ?? ''),
                'direction'    => (string) ($trip['trip_direction'] ?? ''),
                'boarding_mode'=> $mode,
                'dedupe_key'   => $docId,
            ]);
        } catch (\Throwable $e) {
            // A notification failure must never lose the attendance record —
            // the safety fact is the event, the push is a courtesy.
            log_message('error', "F14 notify {$category} failed: " . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  School arrival — bulk, geofenced, boarded-only
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Mark everyone who ACTUALLY boarded this morning trip as alighted at
     * school. Refused unless the bus is inside the campus fence.
     *
     * Two rules that matter:
     *  • It never invents an alighting for a student who never boarded. Two
     *    students who never got on must stay visible as exceptions, not be
     *    silently cleared.
     *  • It exists for `alighted_at_school` ONLY. There is deliberately no bulk
     *    equivalent for the afternoon: being let off at the right stop is
     *    individual by nature.
     */
    public function markAllAlightedAtSchool(string $tripId, array $ctx = []): array
    {
        $trip = $this->loadTrip($tripId);
        if ((string) ($trip['status'] ?? '') !== 'InProgress') {
            return $this->_reject('trip_not_running', 'This trip is not running.');
        }
        if ($this->legOfTrip($trip) !== 'Pickup') {
            return $this->_reject('wrong_leg',
                'Arrival at school applies to the morning trip only. Drop off each student at their stop.');
        }
        $vehicleId = (string) ($trip['vehicleId'] ?? '');
        $geo = $this->verifyBusAtSchool($vehicleId);
        if (!$geo['ok']) {
            return ['status' => 'rejected', 'reason' => $geo['reason'], 'message' => $geo['message'],
                    'distance_m' => $geo['distance_m']];
        }

        $states = $this->_statesForTrip($tripId);
        $done = 0; $skipped = 0; $failed = [];
        foreach ($states as $sid => $st) {
            if ($st['state'] !== self::EV_BOARDED_AT_STOP) { $skipped++; continue; }
            $opts = [
                'marked_by_role'    => 'driver',
                'scan_source'       => self::SRC_BULK,
                'allow_off_roster'  => true,   // alternate-bus passengers alight here too
                'boarding_mode'     => (string) ($st['row']['boarding_mode'] ?? self::MODE_ASSIGNED),
                'actual_route_id'   => (string) ($trip['route_id']  ?? ''),
                'actual_vehicle_id' => $vehicleId,
                'client_event_id'   => (string) ($ctx['client_event_id'] ?? ''),
            ] + $this->_gpsAtScan($vehicleId);
            try {
                $w = $this->transport->markAlightedAtSchool($tripId, $sid, '', $opts);
                $this->_notifyEvent(self::EV_ALIGHTED_AT_SCHOOL, $trip, $sid, $w['docId'],
                    (string) $opts['boarding_mode']);
                $done++;
            } catch (\Throwable $e) {
                // Partial success is reported, never hidden.
                $failed[] = ['student_id' => $sid, 'error' => $e->getMessage()];
                log_message('error', "F14 bulk alight failed student={$sid} trip={$tripId}: " . $e->getMessage());
            }
        }
        $blockers = $this->completionBlockers($tripId);
        return [
            'status' => 'ok', 'alighted' => $done, 'skipped' => $skipped,
            'failed' => $failed, 'fence' => $geo['fence'], 'distance_m' => $geo['distance_m'],
            'message' => $done . ' student(s) marked arrived at school.',
            'blockers' => $blockers,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Exception resolution
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Resolve an expected student who never boarded.
     *
     * A driver may record only `absent` or `verified_other`. `alternate_bus` is
     * deliberately NOT selectable — the server derives it from canonical
     * attendance evidence, so a driver can neither fake it nor be blamed for a
     * child who quietly took another bus.
     */
    public function resolveException(string $tripId, string $studentId, string $resolution, string $reason, array $ctx = []): array
    {
        $trip = $this->loadTrip($tripId);
        if (!in_array($resolution, self::DRIVER_RESOLUTIONS, true)) {
            return $this->_reject('invalid_resolution',
                'Choose either "did not travel" or a verified reason. Alternate bus is detected automatically.');
        }
        if (trim($reason) === '' && $resolution === self::RES_VERIFIED_OTHER) {
            return $this->_reject('reason_required', 'A reason is required.');
        }
        $roster = array_map('strval', (array) ($trip['roster_student_ids'] ?? []));
        if (!in_array($studentId, $roster, true)) {
            return $this->_reject('not_expected', 'That student is not expected on this trip.');
        }
        $state = $this->studentStateOnTrip($tripId, $studentId);
        if ($state !== '') {
            return $this->_reject('already_travelling',
                'That student has already been recorded on this trip.');
        }
        // Evidence still wins: never let a driver mark "absent" a child the
        // system can see boarded another bus.
        $alt = $this->findBoardingOnOtherTrip($studentId, (string) ($trip['trip_date'] ?? ''), $tripId);
        if ($alt['found']) {
            return ['status' => 'auto_resolved', 'reason' => 'alternate_bus',
                'message' => 'This student boarded another bus and is already accounted for.',
                'actual_trip_id' => $alt['trip_id'], 'actual_vehicle_id' => $alt['vehicle_id']];
        }

        $opts = [
            'marked_by_role'    => 'driver',
            'scan_source'       => self::SRC_MANUAL,
            'resolution_state'  => $resolution,
            'resolution_reason' => trim($reason),
            'boarding_mode'     => self::MODE_ASSIGNED,
            'client_event_id'   => (string) ($ctx['client_event_id'] ?? ''),
        ];
        $w = $this->transport->markStudentNoShow($tripId, $studentId, trim($reason), $opts);
        return ['status' => 'ok', 'resolution' => $resolution,
                'doc_id' => (string) ($w['docId'] ?? ''),
                'message' => 'Recorded.',
                'blockers' => $this->completionBlockers($tripId)];
    }
}
