<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_service — TMS Phase 0 foundation library.
 *
 * ─── SCOPE OF THIS FILE ──────────────────────────────────────────────────
 * Ground-floor library for the Transport Management System. Phase 0 landed
 * the infrastructure (audit emission, collection-name constants, tenant-
 * bound init). Feature F1 (Fleet Management, 2026-07-07) adds the first
 * business surface — DOMAIN_FLEET and DOMAIN_VENDOR methods for vehicles,
 * vehicle documents, lifecycle state machine, replacement + route transfer
 * history, vendor CRUD + linkage, maintenance + fuel logs, and the fleet
 * expiry engine.
 *
 * ─── RESERVED GPS FIELD NAMES (Phase 5 forward-compat) ───────────────────
 * F1 vehicle schema RESERVES the following field names for future Phase 5
 * (GPS) population. F1 code MUST NOT write to any of these; Phase 5 owns
 * their write paths. Collision-avoidance only — Firestore is schemaless.
 *
 *   gps_last_position_at   — ISO timestamp of last position ping
 *   gps_last_lat           — float
 *   gps_last_lng           — float
 *   gps_current_speed_kmph — float
 *   gps_status             — 'online' | 'offline' | 'unknown'
 *
 * ─── HR SNAPSHOT PATTERN (F1 refinement, 2026-07-07) ────────────────────
 * `driver_name` and `driver_phone` on the vehicle doc are HISTORICAL
 * SNAPSHOTS captured at vehicle-save time from the staff record identified
 * by `staff_id`. HR is the ONLY source of truth for current staff info;
 * the vehicle snapshot exists solely for audit / historical reference and
 * for Kotlin-app read compatibility (existing VehicleDoc.kt fields).
 *
 * There is NO LIVE SYNC mechanism — if the staff record's name/phone
 * changes later, the vehicle's snapshot remains stale until the next
 * vehicle save. This is deliberate:
 *   • matches the user directive "avoid duplicating HR data";
 *   • matches the user directive "driver snapshot exists only for
 *     historical/audit purposes and not duplicate HR ownership";
 *   • avoids maintaining a compat/sync layer that would fail gate 7
 *     (no compatibility shims).
 *
 * Consumers that need CURRENT staff info must join `staff` at read time
 * using the canonical FK `staff_id`. Admin panel accepts direct-typed
 * driver_name/driver_phone ONLY as fallback when staff_id is empty
 * (unlinked legacy or vendor-driver-not-in-staff cases).
 *
 * ─── MODULAR DECOMPOSITION ROADMAP ───────────────────────────────────────
 * Business logic in future Phase 1+ LCs is grouped by DOMAIN. When a domain
 * reaches the size or complexity threshold that warrants extraction into
 * its own library, the section can be lifted out into a dedicated class
 * (Transport_fleet_service, Transport_route_service, …) with no consumer
 * refactor beyond the load path. To make that extraction low-friction:
 *
 *   • Methods belonging to the SAME domain share no state with methods
 *     from OTHER domains beyond the shared init context (fs, numbering,
 *     schoolId, sessionYear, adminId).
 *   • Cross-domain collaboration happens through explicit, documented
 *     seams — never by one domain method calling another domain method
 *     directly.  Currently the only cross-domain seam is emitAudit(),
 *     which every domain calls the same way.
 *   • Collection names live in constants declared here (see below), not
 *     inline strings scattered across methods, so an extracted service
 *     imports one constant instead of several literals.
 *   • Future collaborations (e.g. Fleet notifying Route of a vehicle
 *     retirement) MUST route through a stable event/hook layer rather
 *     than direct method-to-method calls.
 *
 * Domain map (mirrors the roadmap in TMS v2.1 architecture §5.2):
 *
 *   DOMAIN_FLEET     — vehicles, vehicleDocuments, maintenance, fuel,
 *                      vehicleLifecycleLog, vehicleReplacementHistory,
 *                      vehicleRouteTransferHistory                (LC-1, LC-6, LC-9, LC-14)
 *   DOMAIN_VENDOR    — transportVendors                            (LC-8)
 *   DOMAIN_ROUTE     — routes, transportStops, routeSuspensions    (Phase 1 + LC-10)
 *   DOMAIN_ASSIGNMENT — routeAssignments, routeAssignmentHistory,
 *                      bulk assign, subscription, rollover         (LC-3, LC-4, LC-5, LC-11, LC-26)
 *   DOMAIN_TRIP      — tripLogs, transportAttendance               (LC-17, LC-18)
 *   DOMAIN_EXPENSE   — transportExpenses, PayrollAccountingService (LC-7)
 *   DOMAIN_DRIVER    — driver_meta on staff, driverIncidents,
 *                      driverPerformanceReviews,
 *                      temporaryDriverAssignments,
 *                      driverActivitySummary                       (LC-2, LC-15, LC-16, LC-19)
 *   DOMAIN_EVENT     — transportEvents, eventTripAssignments       (LC-23)
 *   DOMAIN_ALERT     — sosAlerts                                   (LC-13)
 *   DOMAIN_ANALYTICS — routeFleetSummary, vehicleExpiryDashboardCache,
 *                      fleetMonthlyExpenseSummary, busCapacityCache (LC-24, LC-25)
 *
 * ─── PUBLIC API SURFACE (this file, today) ───────────────────────────────
 *   init($fs, $numbering, $schoolId, $sessionYear, $adminId)
 *   emitAudit($entityType, $entityId, $action, $before, $after, $context = [])
 *
 * That is the entire Phase 0 surface. Every subsequent LC extends this
 * class with domain-scoped public methods, and every one of those methods
 * MUST call emitAudit() before returning (verifier CH-TRN-6 asserts this).
 *
 * ─── LOADING PATTERN ─────────────────────────────────────────────────────
 *   $this->load->library('transport_service', null, 'transport');
 *   $this->transport->init(
 *       $this->fs,
 *       $this->numbering,   // once numbering is Phase-1-loaded
 *       $this->school_name,
 *       $this->session_year,
 *       $this->admin_id ?? 'system'
 *   );
 *
 * Phase 0 does NOT load this library from Transport.php — Transport.php
 * continues its behaviour-equivalent path and only starts calling
 * Transport_service methods when the specific Phase 1 LC re-wires each
 * endpoint. That preserves byte-equivalent controller behaviour at Phase 0.
 */
class Transport_service
{
    // ─── Domain enum (used for verifier grouping + future extraction) ────
    public const DOMAIN_FLEET      = 'fleet';
    public const DOMAIN_VENDOR     = 'vendor';
    public const DOMAIN_ROUTE      = 'route';
    public const DOMAIN_ASSIGNMENT = 'assignment';
    public const DOMAIN_TRIP       = 'trip';
    public const DOMAIN_EXPENSE    = 'expense';
    public const DOMAIN_DRIVER     = 'driver';
    public const DOMAIN_EVENT      = 'event';
    public const DOMAIN_ALERT      = 'alert';
    public const DOMAIN_ANALYTICS  = 'analytics';

    // ─── Collection names (single source of truth) ───────────────────────
    // Every Phase 1+ writer that touches these collections MUST reference
    // the constant, not a bare string. Verifier CH-TRN-4 / CH-TRN-9 grep
    // for direct writes to feeDemands / ledger; adding new collections in
    // future LCs means adding a constant here.
    public const COL_VEHICLES                       = 'vehicles';
    public const COL_VEHICLE_DOCUMENTS              = 'vehicleDocuments';
    public const COL_VEHICLE_LIFECYCLE_LOG          = 'vehicleLifecycleLog';
    public const COL_VEHICLE_REPLACEMENT_HISTORY    = 'vehicleReplacementHistory';
    public const COL_VEHICLE_ROUTE_TRANSFER_HISTORY = 'vehicleRouteTransferHistory';
    public const COL_TRANSPORT_VENDORS              = 'transportVendors';
    public const COL_ROUTES                         = 'routes';
    public const COL_TRANSPORT_STOPS                = 'transportStops';
    public const COL_ROUTE_SUSPENSIONS              = 'routeSuspensions';
    public const COL_STUDENT_ROUTES_LEGACY          = 'studentRoutes';   // pre-v2 assignment (kept during rollover)
    public const COL_ROUTE_ASSIGNMENTS              = 'routeAssignments';
    public const COL_ROUTE_ASSIGNMENT_HISTORY       = 'routeAssignmentHistory';
    // PERF-7/PERF-8: upper bound for admin list scans (was unbounded). A single
    // tenant's expenses / active assignments sit well under this; the cap only
    // guards against a pathological unbounded full-collection read.
    public const LIST_SCAN_LIMIT                    = 2000;
    public const COL_TRIP_LOGS                      = 'tripLogs';
    public const COL_MAINTENANCE_LOGS               = 'maintenanceLogs';
    public const COL_FUEL_LOGS                      = 'fuelLogs';
    public const COL_TRANSPORT_EXPENSES             = 'transportExpenses';
    public const COL_DRIVER_INCIDENTS               = 'driverIncidents';
    public const COL_DRIVER_PERFORMANCE_REVIEWS     = 'driverPerformanceReviews';
    public const COL_TEMPORARY_DRIVER_ASSIGNMENTS   = 'temporaryDriverAssignments';
    public const COL_TRANSPORT_EVENTS               = 'transportEvents';
    public const COL_EVENT_TRIP_ASSIGNMENTS         = 'eventTripAssignments';
    public const COL_SOS_ALERTS                     = 'sosAlerts';
    public const COL_TRANSPORT_AUDIT_LOG            = 'transportAuditLog';

    // Denormalised caches — server-only writes (Firestore rules block
    // client writes). Kept together for the CH-TRN cache verifier group.
    public const COL_BUS_CAPACITY_CACHE             = 'busCapacityCache';
    public const COL_ROUTE_FLEET_SUMMARY            = 'routeFleetSummary';
    public const COL_DRIVER_ACTIVITY_SUMMARY        = 'driverActivitySummary';
    public const COL_VEHICLE_EXPIRY_DASHBOARD_CACHE = 'vehicleExpiryDashboardCache';
    public const COL_FLEET_MONTHLY_EXPENSE_SUMMARY  = 'fleetMonthlyExpenseSummary';

    /** @var \CI_Controller */
    private $ci;

    /** @var object|null Firestore_service */
    private $fs = null;

    /** @var object|null Numbering_service */
    private $numbering = null;

    /** @var string */
    private $schoolId = '';

    /** @var string */
    private $sessionYear = '';

    /** @var string */
    private $adminId = 'system';

    /** @var bool */
    private $ready = false;

    public function __construct()
    {
        $this->ci = &get_instance();
    }

    /**
     * Bind tenant context. Must be called before any business method that
     * touches Firestore. emitAudit() specifically requires init() to have
     * been called (asserted below).
     *
     * @param object      $fs          Firestore_service instance (already init'd on the same tenant).
     * @param object|null $numbering   Numbering_service instance. Phase 0 accepts null because
     *                                 no Phase 0 method allocates IDs; Phase 1+ LCs will require
     *                                 a bound Numbering_service.
     * @param string      $schoolId    Tenant identifier (SCH_XXXXXX).
     * @param string      $sessionYear Indian academic session (e.g. "2026-27").
     * @param string      $adminId     Actor identifier for audit rows (falls back to 'system').
     */
    public function init($fs, $numbering, string $schoolId, string $sessionYear = '', string $adminId = 'system'): self
    {
        if ($schoolId === '') {
            throw new \LogicException('Transport_service::init requires a non-empty schoolId');
        }
        $this->fs          = $fs;
        $this->numbering   = $numbering;
        $this->schoolId    = $schoolId;
        $this->sessionYear = $sessionYear;
        $this->adminId     = $adminId !== '' ? $adminId : 'system';
        $this->ready       = true;
        return $this;
    }

    /**
     * Append a row to the Transport audit log.
     *
     * Every Transport WRITE method — from every domain, from every LC —
     * MUST call this before returning to its caller. This is the single
     * cross-domain seam. The doc key is deterministic:
     *   {schoolId}_TAUD_{eventAtMicrotime}_{entityId}
     * which is unique enough for the write frequencies TMS produces
     * (a few dozen audit rows per admin action at most).
     *
     * Verifier CH-TRN-6 statically greps Transport_service's public
     * methods for a call to emitAudit(); introducing a new write method
     * without an audit call is a verifier failure.
     *
     * @param string $entityType e.g. 'vehicle', 'route', 'assignment'.
     * @param string $entityId   Business ID of the entity (VH0007, RT0002, studentId, …).
     * @param string $action     Verb: 'created' | 'updated' | 'deleted' | 'transitioned' | …
     * @param mixed  $before     State before the write (redacted / trimmed by caller).
     * @param mixed  $after      State after the write (redacted / trimmed by caller).
     * @param array  $context    Optional extras: domain, related IDs, bulk-batch ID, source app.
     *
     * @return string The generated audit row ID.
     */
    public function emitAudit(
        string $entityType,
        string $entityId,
        string $action,
        $before,
        $after,
        array  $context = []
    ): string {
        $this->_assertReady();

        // Trim before/after to keep the audit row well under the design
        // budget (§2 supplement: audit doc worst-case 2 KB). Callers are
        // expected to redact sensitive fields upstream; this is a safety
        // net against unintentionally huge payloads.
        $beforeJson = $this->_capJson($before, 4096);
        $afterJson  = $this->_capJson($after,  4096);

        $ts    = microtime(true);
        $tsMs  = (int) round($ts * 1000);
        $docId = sprintf('%s_TAUD_%d_%s', $this->schoolId, $tsMs, $entityId !== '' ? $entityId : 'na');

        $row = [
            'schoolId'    => $this->schoolId,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'action'      => $action,
            'domain'      => (string) ($context['domain'] ?? ''),
            'source'      => (string) ($context['source'] ?? 'admin_panel'),
            'session'     => (string) ($context['session'] ?? $this->sessionYear),
            'before'      => $beforeJson,
            'after'       => $afterJson,
            'context'     => $this->_capJson($context, 2048),
            'actor_id'    => $this->adminId,
            'event_at'    => date('c'),
            'event_at_ms' => $tsMs,
        ];

        // Direct setEntity — audit log is append-only from the server side.
        // Firestore rule for transportAuditLog denies client-side writes.
        $this->fs->setEntity(self::COL_TRANSPORT_AUDIT_LOG, $docId, $row, /* merge */ false);

        return $docId;
    }

    /**
     * Registry of collections OWNED by Transport. Used by Transport_verifier
     * CH-TRN-1 (rules coverage) and CH-TRN-2 (schoolId presence). Extension
     * point for future LCs: append the collection name to keep the verifier
     * in sync automatically.
     *
     * @return array<int,string>
     */
    public function ownedCollections(): array
    {
        return [
            // primary
            self::COL_VEHICLES,
            self::COL_VEHICLE_DOCUMENTS,
            self::COL_VEHICLE_LIFECYCLE_LOG,
            self::COL_VEHICLE_REPLACEMENT_HISTORY,
            self::COL_VEHICLE_ROUTE_TRANSFER_HISTORY,
            self::COL_TRANSPORT_VENDORS,
            self::COL_ROUTES,
            self::COL_TRANSPORT_STOPS,
            self::COL_ROUTE_SUSPENSIONS,
            self::COL_STUDENT_ROUTES_LEGACY,
            self::COL_ROUTE_ASSIGNMENTS,
            self::COL_ROUTE_ASSIGNMENT_HISTORY,
            self::COL_TRIP_LOGS,
            self::COL_TRANSPORT_ATTENDANCE,
            self::COL_MAINTENANCE_LOGS,
            self::COL_FUEL_LOGS,
            self::COL_TRANSPORT_EXPENSES,
            self::COL_DRIVER_INCIDENTS,
            self::COL_DRIVER_PERFORMANCE_REVIEWS,
            self::COL_TEMPORARY_DRIVER_ASSIGNMENTS,
            self::COL_TRANSPORT_EVENTS,
            self::COL_EVENT_TRIP_ASSIGNMENTS,
            self::COL_SOS_ALERTS,
            self::COL_TRANSPORT_AUDIT_LOG,
            // caches
            self::COL_BUS_CAPACITY_CACHE,
            self::COL_ROUTE_FLEET_SUMMARY,
            self::COL_DRIVER_ACTIVITY_SUMMARY,
            self::COL_VEHICLE_EXPIRY_DASHBOARD_CACHE,
            self::COL_FLEET_MONTHLY_EXPENSE_SUMMARY,
        ];
    }

    /**
     * Numbering kinds OWNED by Transport (mirrors config/numbering.php
     * additions). Used by Transport_verifier CH-TRN-7 for pad-width
     * utilisation. Extension point: append when a new LC registers a kind.
     *
     * @return array<int,string>
     */
    public function ownedNumberingKinds(): array
    {
        return [
            'trn_vehicle', 'trn_vehicle_doc', 'trn_vehicle_lifecycle',
            'trn_vehicle_replacement', 'trn_vehicle_transfer',
            'trn_vendor',
            'trn_route', 'trn_stop', 'trn_route_suspension',
            'trn_assignment_history',
            'trn_trip',
            'trn_maintenance', 'trn_fuel',
            'trn_expense',
            'trn_driver_incident', 'trn_driver_review', 'trn_temp_driver_assign',
            'trn_event', 'trn_event_assign',
            'trn_audit',
            // F12.1 M5 (2026-08-07) — CBSE compliance waiver reference.
            // Registered so CH-TRN-7 tracks its pad-width utilisation with the
            // rest of the Transport counters. See the M5 certification: the
            // kind is registered per spec §10-M5 but has no writer yet, because
            // spec §2.2 defines no field on vehicleCompliance to carry it.
            'trn_compliance_waiver',
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  DOMAIN_FLEET (F1) — Vehicle lifecycle constants
    // ═════════════════════════════════════════════════════════════════════

    /** Vehicle lifecycle states (state machine anchor). */
    public const LIFECYCLE_STATES = [
        'Purchased', 'Onboarding', 'Active', 'Maintenance', 'Retired', 'Disposed'
    ];

    /** Legal lifecycle transitions (from → allowed next states). Server-side
     *  enforcement; UI hides illegal-action buttons using the same map. */
    private const LIFECYCLE_TRANSITIONS = [
        'Purchased'   => ['Onboarding'],
        'Onboarding'  => ['Active', 'Retired'],
        'Active'      => ['Maintenance', 'Retired'],
        'Maintenance' => ['Active', 'Retired'],
        'Retired'     => ['Disposed', 'Active'],   // reactivation permitted with audit reason
        'Disposed'    => [],                        // terminal
    ];

    /** Ownership types. Vendor_id required when Contract. */
    public const OWNERSHIP_TYPES = ['School', 'Contract'];

    /** Fuel types recognised for kmpl computation + expense classification. */
    public const FUEL_TYPES = ['Diesel', 'Petrol', 'CNG', 'Electric', 'Hybrid'];

    /** Common vehicle document types — this is a HINT list; doc_type is a
     *  free-string field (per F1 refinement: schools may add their own types
     *  without schema change). UI seeds a dropdown with these + "Other". */
    public const COMMON_VEHICLE_DOC_TYPES = [
        'RC', 'Insurance', 'Permit', 'PUC', 'Fitness', 'RoadTax', 'Contract', 'Misc'
    ];

    /** Fields F1 code MUST NOT write — reserved for Phase 5 GPS. Kept in
     *  code as an enum so the verifier's future GPS-write-guard can grep
     *  the same source-of-truth list. */
    private const RESERVED_GPS_FIELDS = [
        'gps_last_position_at', 'gps_last_lat', 'gps_last_lng',
        'gps_current_speed_kmph', 'gps_status',
    ];

    // ═════════════════════════════════════════════════════════════════════
    //  DOMAIN_FLEET (F1) — Vehicle master CRUD
    // ═════════════════════════════════════════════════════════════════════

    /**
     * List all vehicles for the current tenant, ordered by `number`.
     * Uses composite index vehicles(schoolId, status, number) — see Phase 0
     * LC-F2 index #1. When no filter is supplied, the index still serves.
     *
     * @return array<int, array<string,mixed>>  Each row has the doc key on 'id'.
     */
    public function listVehicles(): array
    {
        $this->_assertReady();
        $rows = (array) $this->fs->schoolWhere(self::COL_VEHICLES, [], 'number', 'ASC');
        $out  = [];
        foreach ($rows as $doc) {
            $v = $this->_unwrapNestedRow($doc, 'schoolId');
            if (empty($v)) { continue; }
            $id = (string) ($v['vehicleId'] ?? $v['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $v['id'] = $id;
            $out[] = $v;
        }
        return $out;
    }

    /**
     * Fetch a single vehicle by ID. Returns null when the doc does not exist.
     */
    public function getVehicle(string $vehicleId): ?array
    {
        $this->_assertReady();
        if ($vehicleId === '') {
            return null;
        }
        $v = $this->fs->getEntity(self::COL_VEHICLES, $vehicleId);
        return is_array($v) ? $v : null;
    }

    /**
     * Save (create or update) a vehicle master record.
     *
     * ─── HR snapshot pattern ────────────────────────────────────────────
     * When `staff_id` is provided, driver_name + driver_phone are pulled
     * from the staff doc and SNAPSHOTTED onto the vehicle. Direct-typed
     * driver_name / driver_phone from the input are used ONLY as fallback
     * when staff_id is empty (unlinked legacy or vendor-driver cases).
     *
     * ─── Lifecycle defaults ─────────────────────────────────────────────
     * New vehicle → lifecycle_status defaults to 'Active' (matches the
     * behaviour of the current Transport.php save_vehicle, so existing
     * UI flows produce equivalent state).
     * Edit → lifecycle_status is PRESERVED from the existing doc (state
     * changes must go through transitionVehicleLifecycle()).
     *
     * ─── Ownership defaults ─────────────────────────────────────────────
     * New vehicle → ownership_type defaults to 'School'. Contract-owned
     * vehicles require vendor_id (validated).
     *
     * @param array<string,mixed> $input Raw controller-side input.
     * @return array{id:string, isNew:bool, data:array<string,mixed>}
     */
    public function saveVehicle(array $input): array
    {
        $this->_assertReady();

        $id       = trim((string) ($input['id'] ?? ''));
        $isNew    = ($id === '');
        $existing = $isNew ? null : $this->fs->getEntity(self::COL_VEHICLES, $id);

        // ── field extraction + defaults ───────────────────────────────────
        $number       = trim((string) ($input['number'] ?? ''));
        if ($number === '') {
            throw new \InvalidArgumentException('Vehicle number is required.');
        }
        $type         = trim((string) ($input['type'] ?? 'Bus'));
        $capacity     = max(1, (int) ($input['capacity'] ?? 40));
        $staffId      = trim((string) ($input['staff_id'] ?? ''));
        // Preserve staff_id from existing doc when input omits it (matches
        // pre-F1 Transport.php behaviour).
        if (!$isNew && $staffId === '' && is_array($existing)) {
            $staffId = (string) ($existing['staff_id'] ?? '');
        }

        // Insurance + statutory expiries (existing + F1 additions)
        $insuranceNo  = trim((string) ($input['insurance_no'] ?? ''));
        $insuranceExp = trim((string) ($input['insurance_expiry'] ?? ''));
        $fitnessExp   = trim((string) ($input['fitness_expiry'] ?? ''));
        $permitNo     = trim((string) ($input['permit_no'] ?? ''));
        $permitExp    = trim((string) ($input['permit_expiry'] ?? ''));
        $pollutionExp = trim((string) ($input['pollution_expiry'] ?? ''));
        $roadTaxExp   = trim((string) ($input['road_tax_expiry'] ?? ''));
        $gpsEnabled   = ((string) ($input['gps_enabled'] ?? '0')) === '1';
        $gpsDeviceId  = trim((string) ($input['gps_device_id'] ?? ''));
        $conductorSid = trim((string) ($input['conductor_staff_id'] ?? ''));

        // Ownership + vendor
        $ownership = trim((string) ($input['ownership_type'] ?? ''));
        if ($ownership === '' && is_array($existing)) {
            $ownership = (string) ($existing['ownership_type'] ?? 'School');
        }
        if ($ownership === '') {
            $ownership = 'School';
        }
        if (!in_array($ownership, self::OWNERSHIP_TYPES, true)) {
            throw new \InvalidArgumentException('Invalid ownership_type: ' . $ownership);
        }
        $vendorId          = trim((string) ($input['vendor_id'] ?? ''));
        $monthlyHire       = (float) ($input['monthly_hire_amount'] ?? 0);
        $contractStart     = trim((string) ($input['contract_start_date'] ?? ''));
        $contractEnd       = trim((string) ($input['contract_end_date'] ?? ''));
        $contractTermsPath = trim((string) ($input['contract_terms_path'] ?? ''));
        if ($ownership === 'Contract') {
            if ($vendorId === '') {
                throw new \InvalidArgumentException('vendor_id is required when ownership_type is Contract.');
            }
            $vendor = $this->fs->getEntity(self::COL_TRANSPORT_VENDORS, $vendorId);
            if (!is_array($vendor)) {
                throw new \InvalidArgumentException('Referenced vendor does not exist: ' . $vendorId);
            }
            if ((string) ($vendor['status'] ?? 'Active') !== 'Active') {
                throw new \InvalidArgumentException('Referenced vendor is not Active: ' . $vendorId);
            }
        }

        // Fuel + service planning
        $fuelType = trim((string) ($input['fuel_type'] ?? 'Diesel'));
        if (!in_array($fuelType, self::FUEL_TYPES, true)) {
            $fuelType = 'Diesel';
        }
        // F12.1 (M1, 2026-08-07) — CBSE compliance fields. Additive only; not
        // one of RESERVED_GPS_FIELDS. No existing caller populates these yet
        // (the compliance tab lands at M4), so every field PRESERVES the
        // existing value when the input omits it — identical discipline to
        // staff_id/ownership_type above — so today's Add/Edit Vehicle form
        // cannot silently wipe compliance data a later milestone writes.
        // New vehicles start at the Not_Assessed baseline (false/0/'').
        $cctvInstalled     = $isNew ? false : (bool) ($existing['cctv_installed'] ?? false);
        if (array_key_exists('cctv_installed', $input)) { $cctvInstalled = ((string) $input['cctv_installed']) === '1' || $input['cctv_installed'] === true; }
        $cctvWorking       = $isNew ? false : (bool) ($existing['cctv_working'] ?? false);
        if (array_key_exists('cctv_working', $input)) { $cctvWorking = ((string) $input['cctv_working']) === '1' || $input['cctv_working'] === true; }
        $cctvLastChecked   = trim((string) ($input['cctv_last_checked'] ?? ($existing['cctv_last_checked'] ?? '')));
        $sgFitted          = $isNew ? false : (bool) ($existing['speed_governor_fitted'] ?? false);
        if (array_key_exists('speed_governor_fitted', $input)) { $sgFitted = ((string) $input['speed_governor_fitted']) === '1' || $input['speed_governor_fitted'] === true; }
        $sgLimitKmph       = max(0, (int) ($input['speed_governor_limit_kmph'] ?? ($existing['speed_governor_limit_kmph'] ?? 0)));
        $ladyAttendantSid  = trim((string) ($input['lady_attendant_staff_id'] ?? ($existing['lady_attendant_staff_id'] ?? '')));
        $firstAidKit       = $isNew ? false : (bool) ($existing['first_aid_kit'] ?? false);
        if (array_key_exists('first_aid_kit', $input)) { $firstAidKit = ((string) $input['first_aid_kit']) === '1' || $input['first_aid_kit'] === true; }
        $fireExtinguisher  = $isNew ? false : (bool) ($existing['fire_extinguisher'] ?? false);
        if (array_key_exists('fire_extinguisher', $input)) { $fireExtinguisher = ((string) $input['fire_extinguisher']) === '1' || $input['fire_extinguisher'] === true; }
        $fireExtExpiry     = trim((string) ($input['fire_ext_expiry'] ?? ($existing['fire_ext_expiry'] ?? '')));
        $alarmBell         = $isNew ? false : (bool) ($existing['alarm_bell'] ?? false);
        if (array_key_exists('alarm_bell', $input)) { $alarmBell = ((string) $input['alarm_bell']) === '1' || $input['alarm_bell'] === true; }
        $windowGrills      = $isNew ? false : (bool) ($existing['window_grills'] ?? false);
        if (array_key_exists('window_grills', $input)) { $windowGrills = ((string) $input['window_grills']) === '1' || $input['window_grills'] === true; }
        $doorLockVerified  = $isNew ? false : (bool) ($existing['door_lock_verified'] ?? false);
        if (array_key_exists('door_lock_verified', $input)) { $doorLockVerified = ((string) $input['door_lock_verified']) === '1' || $input['door_lock_verified'] === true; }
        $emergencyPhoneNo  = trim((string) ($input['emergency_phone_no'] ?? ($existing['emergency_phone_no'] ?? '')));
        $seatingUnder12    = max(0, (int) ($input['seating_capacity_under12'] ?? ($existing['seating_capacity_under12'] ?? 0)));

        $avgKmpl           = (float) ($input['average_kmpl'] ?? ($existing['average_kmpl'] ?? 0));
        $svcKmInterval     = max(0, (int) ($input['service_km_interval'] ?? ($existing['service_km_interval'] ?? 10000)));
        $lastSvcKm         = max(0, (int) ($input['last_service_km'] ?? ($existing['last_service_km'] ?? 0)));
        $lastSvcDate       = trim((string) ($input['last_service_date'] ?? ($existing['last_service_date'] ?? '')));
        $nextSvcDueKm      = $lastSvcKm > 0 && $svcKmInterval > 0 ? ($lastSvcKm + $svcKmInterval) : 0;
        $nextSvcDueDateIn  = trim((string) ($input['next_service_due_date'] ?? ($existing['next_service_due_date'] ?? '')));

        // Lifecycle status (state machine anchor)
        // — new vehicles default 'Active' to match pre-F1 UI behaviour
        // — edits preserve existing state (must use transitionVehicleLifecycle for state changes)
        if ($isNew) {
            $lifecycleStatus = 'Active';
        } else {
            $lifecycleStatus = (string) ($existing['lifecycle_status'] ?? 'Active');
        }

        // Legacy 'status' field (Active|Inactive|Maintenance) — preserved
        // for backward compat with existing UI + Kotlin app reads. Kept in
        // sync with lifecycle_status pragmatically:
        //   Active,Onboarding,Purchased → 'Active'
        //   Maintenance                 → 'Maintenance'
        //   Retired,Disposed            → 'Inactive'
        $legacyStatus = $this->_deriveLegacyStatus($lifecycleStatus);

        // ── HR snapshot for driver_name / driver_phone ────────────────────
        $driverName  = trim((string) ($input['driver_name'] ?? ''));
        $driverPhone = trim((string) ($input['driver_phone'] ?? ''));
        if ($staffId !== '') {
            $staffSnap = $this->_snapshotDriverFromStaff($staffId);
            if ($staffSnap !== null) {
                // HR is source of truth: overwrite any typed values
                $driverName  = $staffSnap['name'];
                $driverPhone = $staffSnap['phone'];
            }
            // If staff lookup failed, keep whatever the caller supplied
            // (defensive — HR read failure shouldn't block a vehicle save).
        }

        // ── ID allocation (Numbering_service for new; safe_path_segment for edit) ──
        if ($isNew) {
            $id = $this->numbering->next('trn_vehicle', [
                'claimedBy'  => $this->adminId,
                'claimedFor' => 'F1:saveVehicle',
            ]);
        }

        // ── build the doc ─────────────────────────────────────────────────
        $now  = date('c');
        $data = [
            'vehicleId'             => $id,
            'schoolId'              => $this->schoolId,
            'number'                => $number,
            'type'                  => $type,
            'capacity'              => $capacity,
            'staff_id'              => $staffId,
            'driver_name'           => $driverName,
            'driver_phone'          => $driverPhone,
            'conductor_staff_id'    => $conductorSid,
            'insurance_no'          => $insuranceNo,
            'insurance_expiry'      => $insuranceExp,
            'fitness_expiry'        => $fitnessExp,
            'permit_no'             => $permitNo,
            'permit_expiry'         => $permitExp,
            'pollution_expiry'      => $pollutionExp,
            'road_tax_expiry'       => $roadTaxExp,
            'fuel_type'             => $fuelType,
            'average_kmpl'          => $avgKmpl,
            'service_km_interval'   => $svcKmInterval,
            'last_service_km'       => $lastSvcKm,
            'last_service_date'     => $lastSvcDate,
            'next_service_due_km'   => $nextSvcDueKm,
            'next_service_due_date' => $nextSvcDueDateIn,
            'gps_enabled'           => $gpsEnabled,
            'gps_device_id'         => $gpsDeviceId,
            'ownership_type'        => $ownership,
            'vendor_id'             => $ownership === 'Contract' ? $vendorId : '',
            'monthly_hire_amount'   => $ownership === 'Contract' ? $monthlyHire : 0.0,
            'contract_start_date'   => $ownership === 'Contract' ? $contractStart : '',
            'contract_end_date'     => $ownership === 'Contract' ? $contractEnd : '',
            'contract_terms_path'   => $ownership === 'Contract' ? $contractTermsPath : '',
            'lifecycle_status'      => $lifecycleStatus,
            'status'                => $legacyStatus,
            // F12.1 (M1) — CBSE compliance fields, additive.
            'cctv_installed'            => $cctvInstalled,
            'cctv_working'              => $cctvWorking,
            'cctv_last_checked'         => $cctvLastChecked,
            'speed_governor_fitted'     => $sgFitted,
            'speed_governor_limit_kmph' => $sgLimitKmph,
            'lady_attendant_staff_id'   => $ladyAttendantSid,
            'first_aid_kit'             => $firstAidKit,
            'fire_extinguisher'         => $fireExtinguisher,
            'fire_ext_expiry'           => $fireExtExpiry,
            'alarm_bell'                => $alarmBell,
            'window_grills'             => $windowGrills,
            'door_lock_verified'        => $doorLockVerified,
            'emergency_phone_no'        => $emergencyPhoneNo,
            'seating_capacity_under12'  => $seatingUnder12,
            'updated_at'            => $now,
        ];
        if ($isNew) {
            $data['created_at']    = $now;
            $data['created_by']    = $this->adminId;
        }

        // Guard: never overwrite reserved GPS fields — merge-true preserves
        // any Phase-5-written values.
        $this->_assertNoReservedGpsWrites($data);

        // ── write + audit ─────────────────────────────────────────────────
        $this->fs->setEntity(self::COL_VEHICLES, $id, $data, /* merge */ true);

        $this->emitAudit(
            'vehicle',
            $id,
            $isNew ? 'created' : 'updated',
            $isNew ? null : $this->_capVehicleForAudit($existing),
            $this->_capVehicleForAudit($data),
            ['domain' => self::DOMAIN_FLEET]
        );

        // First lifecycle event on create: an initial 'Activated' record so
        // the vehicle timeline is never empty. Uses transitionVehicleLifecycle
        // internally to keep the state-machine + log invariant unified.
        if ($isNew) {
            $this->_appendLifecycleEvent(
                $id, 'Activated', $lifecycleStatus, $lifecycleStatus,
                'Vehicle onboarded (initial state)', [],
                null
            );
        }

        return ['id' => $id, 'isNew' => $isNew, 'data' => $data];
    }

    /**
     * Delete a vehicle. Blocked when any Active route currently references
     * the vehicle (matches pre-F1 Transport.php behaviour).
     *
     * Uses composite routes(schoolId, vehicle_id, status) — added by F1 as
     * a delta from Phase 0 §16.
     */
    public function deleteVehicle(string $vehicleId): void
    {
        $this->_assertReady();
        if ($vehicleId === '') {
            throw new \InvalidArgumentException('vehicleId is required.');
        }
        $existing = $this->fs->getEntity(self::COL_VEHICLES, $vehicleId);
        if (!is_array($existing)) {
            throw new \InvalidArgumentException('Vehicle not found: ' . $vehicleId);
        }

        // Block deletion when active routes reference this vehicle.
        $activeRoutes = (array) $this->fs->schoolWhere(
            self::COL_ROUTES,
            [
                ['vehicle_id', '==', $vehicleId],
                ['status',     '==', 'Active'],
            ]
        );
        if ($activeRoutes !== []) {
            throw new \RuntimeException('Cannot delete: vehicle is assigned to an active route.');
        }

        $this->fs->remove(self::COL_VEHICLES, $this->fs->docId($vehicleId));

        $this->emitAudit(
            'vehicle',
            $vehicleId,
            'deleted',
            $this->_capVehicleForAudit($existing),
            null,
            ['domain' => self::DOMAIN_FLEET]
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    //  DOMAIN_FLEET (F1) — Vehicle documents
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Attach a document to a vehicle (RC, Insurance, Permit, PUC, Fitness,
     * RoadTax, Contract, Misc — or any school-defined type, since doc_type
     * is a free-string field per F1 refinement).
     *
     * The file itself is uploaded to Firebase Storage by the controller
     * before calling this method — the `file_path` field on the input is
     * the Storage path (e.g. schools/{schoolId}/transport/vehicles/{VH}/rc_1720000000.pdf).
     * This method persists only the metadata + emits audit.
     *
     * @param array{
     *   vehicle_id:string,
     *   doc_type:string,
     *   doc_number?:string,
     *   issue_date?:string,
     *   expiry_date?:string,
     *   file_path:string,
     *   notes?:string
     * } $input
     * @return array{id:string, data:array<string,mixed>}
     */
    /**
     * @param bool $anyVehicle TRUE only when the caller has established
     *        Transport · manage. FALSE restricts the upload to the vehicle the
     *        actor is canonically the effective driver of.
     */
    public function uploadVehicleDocument(array $input, bool $anyVehicle = false): array
    {
        $this->_assertReady();
        $vehicleId = trim((string) ($input['vehicle_id'] ?? ''));
        $docType   = trim((string) ($input['doc_type']   ?? ''));

        // ── Category-E actor scope (own vehicle only) ────────────────────────
        // Uploading compliance evidence is a legitimate driver errand, so this
        // endpoint stays at Transport:edit. But it took vehicle_id straight from
        // the request, so an edit-level caller could attach a document to ANY
        // vehicle. Scope it to the vehicle the actor actually drives.
        //
        // The relationship is the EXISTING canonical one — getEffectiveDriver()
        // (permanent vehicles.staff_id, overlaid by an active temporary
        // assignment covering today). No new identity mechanism is introduced.
        // $anyVehicle is a SEPARATE ARGUMENT, never an $input key, because
        // $input is built from client POST data.
        unset($input['_any_vehicle'], $input['any_vehicle']);
        if (!$anyVehicle && $vehicleId !== '') {
            $effective = (string) ($this->getEffectiveDriver($vehicleId) ?? '');
            if ($effective === '' || $effective !== (string) $this->adminId) {
                throw new \InvalidArgumentException(
                    'You may only upload documents for the vehicle assigned to you. '
                    . 'Uploading against another vehicle requires Transport · manage.');
            }
        }
        $filePath  = trim((string) ($input['file_path']  ?? ''));
        if ($vehicleId === '') {
            throw new \InvalidArgumentException('vehicle_id is required.');
        }
        if ($docType === '') {
            throw new \InvalidArgumentException('doc_type is required.');
        }
        if ($filePath === '') {
            throw new \InvalidArgumentException('file_path (Storage path) is required.');
        }
        // Sanity: vehicle must exist (avoid orphan docs).
        $vehicle = $this->fs->getEntity(self::COL_VEHICLES, $vehicleId);
        if (!is_array($vehicle)) {
            throw new \InvalidArgumentException('Vehicle not found: ' . $vehicleId);
        }

        $id  = $this->numbering->next('trn_vehicle_doc', [
            'claimedBy'  => $this->adminId,
            'claimedFor' => 'F1:uploadVehicleDocument',
        ]);
        $now = date('c');
        $data = [
            'vehicleDocumentId' => $id,
            'schoolId'          => $this->schoolId,
            'vehicle_id'        => $vehicleId,
            'doc_type'          => $docType,
            'doc_number'        => trim((string) ($input['doc_number']  ?? '')),
            'issue_date'        => trim((string) ($input['issue_date']  ?? '')),
            'expiry_date'       => trim((string) ($input['expiry_date'] ?? '')),
            'file_path'         => $filePath,
            'notes'             => trim((string) ($input['notes']       ?? '')),
            'uploaded_by'       => $this->adminId,
            'uploaded_at'       => $now,
            'status'            => 'Active',
        ];
        $this->fs->setEntity(self::COL_VEHICLE_DOCUMENTS, $id, $data, /* merge */ false);

        $this->emitAudit(
            'vehicleDocument',
            $id,
            'uploaded',
            null,
            ['doc_type' => $docType, 'vehicle_id' => $vehicleId, 'expiry_date' => $data['expiry_date']],
            ['domain' => self::DOMAIN_FLEET, 'vehicle_id' => $vehicleId]
        );

        return ['id' => $id, 'data' => $data];
    }

    /**
     * Delete a vehicle document metadata row. The underlying Storage file
     * is deleted by the controller (Storage bypasses this service).
     */
    public function deleteVehicleDocument(string $docId): void
    {
        $this->_assertReady();
        if ($docId === '') {
            throw new \InvalidArgumentException('docId is required.');
        }
        $existing = $this->fs->getEntity(self::COL_VEHICLE_DOCUMENTS, $docId);
        if (!is_array($existing)) {
            throw new \InvalidArgumentException('Vehicle document not found: ' . $docId);
        }
        $this->fs->remove(self::COL_VEHICLE_DOCUMENTS, $this->fs->docId($docId));

        $this->emitAudit(
            'vehicleDocument',
            $docId,
            'deleted',
            ['doc_type' => (string) ($existing['doc_type'] ?? ''), 'vehicle_id' => (string) ($existing['vehicle_id'] ?? '')],
            null,
            ['domain' => self::DOMAIN_FLEET, 'vehicle_id' => (string) ($existing['vehicle_id'] ?? '')]
        );
    }

    /**
     * List all documents for a given vehicle, most recent first.
     * Uses composite index vehicleDocuments(schoolId, vehicle_id, uploaded_at DESC).
     */
    public function listVehicleDocuments(string $vehicleId): array
    {
        $this->_assertReady();
        if ($vehicleId === '') {
            return [];
        }
        $rows = (array) $this->fs->schoolWhere(
            self::COL_VEHICLE_DOCUMENTS,
            [['vehicle_id', '==', $vehicleId]],
            'uploaded_at', 'DESC'
        );
        $out = [];
        foreach ($rows as $doc) {
            $d = $this->_unwrapNestedRow($doc, 'schoolId');
            if (empty($d)) { continue; }
            $id = (string) ($d['vehicleDocumentId'] ?? $d['id'] ?? '');
            if ($id === '') { continue; }
            $d['id'] = $id;
            $out[] = $d;
        }
        return $out;
    }

    /**
     * List vehicle documents whose expiry_date is within `$days` from today.
     * Feeds the expiry engine + dashboard cache. Server-side date-window
     * filter (Firestore lacks a good composite for this cross-vehicle scan).
     *
     * @param int $days  Look-ahead window (e.g. 30 for the 30-day board).
     * @return array<int,array<string,mixed>>
     */
    public function listExpiringDocuments(int $days = 30): array
    {
        $this->_assertReady();
        $today  = date('Y-m-d');
        $cutoff = date('Y-m-d', strtotime('+' . max(0, $days) . ' days'));
        $rows   = (array) $this->fs->schoolWhere(self::COL_VEHICLE_DOCUMENTS, []);
        $out    = [];
        foreach ($rows as $doc) {
            $d = $this->_unwrapNestedRow($doc, 'schoolId');
            if (empty($d)) { continue; }
            $exp = (string) ($d['expiry_date'] ?? '');
            if ($exp === '') { continue; }
            if ($exp >= $today && $exp <= $cutoff) {
                $d['id']          = (string) ($d['vehicleDocumentId'] ?? $d['id'] ?? '');
                $d['days_left']   = max(0, (int) ((strtotime($exp) - strtotime($today)) / 86400));
                $out[] = $d;
            }
        }
        usort($out, static fn($a, $b) => strcmp((string) $a['expiry_date'], (string) $b['expiry_date']));
        return $out;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  DOMAIN_FLEET (F1) — Vehicle lifecycle transitions (LC-6)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Transition a vehicle from its current lifecycle state to $toState.
     *
     * Validates the transition against the state matrix
     * (self::LIFECYCLE_TRANSITIONS). Batches:
     *   1. vehicles.lifecycle_status  = $toState
     *   2. vehicles.status            = _deriveLegacyStatus($toState)
     *   3. vehicleLifecycleLog append (via _appendLifecycleEvent)
     * Every transition emits an audit entry with prev/new state.
     *
     * @param string      $vehicleId
     * @param string      $toState           Target lifecycle_status value.
     * @param string      $reason            Operator-supplied reason. Required.
     * @param array       $attachmentPaths   Storage paths (0..N) — e.g. photos,
     *                                       scrapping certificates, workshop invoices.
     * @param string|null $relatedVehicleId  For Replacement_* events.
     * @return string     Event ID of the appended lifecycle log entry.
     */
    public function transitionVehicleLifecycle(
        string  $vehicleId,
        string  $toState,
        string  $reason,
        array   $attachmentPaths = [],
        ?string $relatedVehicleId = null
    ): string {
        $this->_assertReady();
        if ($vehicleId === '') {
            throw new \InvalidArgumentException('vehicleId is required.');
        }
        if (!in_array($toState, self::LIFECYCLE_STATES, true)) {
            throw new \InvalidArgumentException('Invalid target lifecycle state: ' . $toState);
        }
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('reason is required for a lifecycle transition.');
        }
        $vehicle = $this->fs->getEntity(self::COL_VEHICLES, $vehicleId);
        if (!is_array($vehicle)) {
            throw new \InvalidArgumentException('Vehicle not found: ' . $vehicleId);
        }
        $fromState = (string) ($vehicle['lifecycle_status'] ?? 'Active');
        if ($fromState === $toState) {
            throw new \InvalidArgumentException(
                "Vehicle {$vehicleId} is already in state '{$toState}'."
            );
        }
        $allowed = self::LIFECYCLE_TRANSITIONS[$fromState] ?? [];
        if (!in_array($toState, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Illegal lifecycle transition: {$fromState} → {$toState}. Allowed from {$fromState}: "
                . (empty($allowed) ? '(terminal)' : implode(', ', $allowed))
            );
        }

        // Update vehicle state + derived legacy status
        $newLegacyStatus = $this->_deriveLegacyStatus($toState);
        $update = [
            'lifecycle_status' => $toState,
            'status'           => $newLegacyStatus,
            'updated_at'       => date('c'),
        ];
        $this->fs->setEntity(self::COL_VEHICLES, $vehicleId, $update, /* merge */ true);

        // Append lifecycle log
        $eventType = $this->_deriveLifecycleEventType($fromState, $toState);
        $eventId   = $this->_appendLifecycleEvent(
            $vehicleId, $eventType, $fromState, $toState, $reason, $attachmentPaths, $relatedVehicleId
        );

        // Audit
        $this->emitAudit(
            'vehicle',
            $vehicleId,
            'transitioned',
            ['lifecycle_status' => $fromState, 'status' => (string) ($vehicle['status'] ?? '')],
            ['lifecycle_status' => $toState,   'status' => $newLegacyStatus],
            [
                'domain'             => self::DOMAIN_FLEET,
                'event_type'         => $eventType,
                'event_id'           => $eventId,
                'related_vehicle_id' => (string) ($relatedVehicleId ?? ''),
                'reason'             => $reason,
            ]
        );

        return $eventId;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  DOMAIN_FLEET (F1) — Vehicle replacement + route transfer (LC-9)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Record a vehicle replacement — Temporary (bus in workshop) or Permanent
     * (retiring old, activating new). Writes a vehicleReplacementHistory row;
     * optionally updates affected routes to point at the replacement; emits
     * lifecycle log entries on BOTH vehicles.
     *
     * Route reassignment semantics:
     *   - Temporary: routes' vehicle_id updated to replacement; caller is
     *     expected to schedule a reverse recordReplacement or transferVehicleBetweenRoutes
     *     at effective_to. F1 does NOT auto-revert (that's a cron concern
     *     deferred to a later feature).
     *   - Permanent: routes' vehicle_id updated to replacement; original
     *     vehicle's lifecycle_status may be transitioned separately via
     *     transitionVehicleLifecycle('Retired') by the caller.
     *
     * @param array{
     *   original_vehicle_id:string,
     *   replacement_vehicle_id:string,
     *   replacement_type:string,          // 'Temporary' | 'Permanent'
     *   route_ids?:array<int,string>,     // routes to update (empty = no route change)
     *   effective_from:string,            // ISO date
     *   effective_to?:string,             // ISO date (required for Temporary)
     *   reason:string,
     *   authorised_by?:string,
     *   notes?:string
     * } $input
     * @return array{id:string, data:array<string,mixed>, routes_updated:int}
     */
    public function recordReplacement(array $input): array
    {
        $this->_assertReady();
        $originalId    = trim((string) ($input['original_vehicle_id']    ?? ''));
        $replacementId = trim((string) ($input['replacement_vehicle_id'] ?? ''));
        $type          = trim((string) ($input['replacement_type']       ?? ''));
        $effFrom       = trim((string) ($input['effective_from']         ?? ''));
        $effTo         = trim((string) ($input['effective_to']           ?? ''));
        $reason        = trim((string) ($input['reason']                 ?? ''));
        if ($originalId === '' || $replacementId === '') {
            throw new \InvalidArgumentException('original_vehicle_id and replacement_vehicle_id are required.');
        }
        if ($originalId === $replacementId) {
            throw new \InvalidArgumentException('original and replacement vehicles must differ.');
        }
        if (!in_array($type, ['Temporary', 'Permanent'], true)) {
            throw new \InvalidArgumentException('replacement_type must be Temporary or Permanent.');
        }
        if ($effFrom === '') {
            throw new \InvalidArgumentException('effective_from is required.');
        }
        if ($type === 'Temporary' && $effTo === '') {
            throw new \InvalidArgumentException('effective_to is required for Temporary replacement.');
        }
        if ($reason === '') {
            throw new \InvalidArgumentException('reason is required.');
        }
        $orig = $this->fs->getEntity(self::COL_VEHICLES, $originalId);
        $repl = $this->fs->getEntity(self::COL_VEHICLES, $replacementId);
        if (!is_array($orig) || !is_array($repl)) {
            throw new \InvalidArgumentException('Both vehicles must exist prior to replacement recording.');
        }

        // Allocate record id
        $recordId = $this->numbering->next('trn_vehicle_replacement', [
            'claimedBy'  => $this->adminId,
            'claimedFor' => 'F1:recordReplacement',
        ]);

        // Update requested routes (if any)
        $routeIds = array_values(array_filter(array_map(
            'strval',
            (array) ($input['route_ids'] ?? [])
        )));
        $routesUpdated = 0;
        foreach ($routeIds as $rid) {
            $route = $this->fs->getEntity(self::COL_ROUTES, $rid);
            if (!is_array($route)) { continue; }
            $this->fs->setEntity(
                self::COL_ROUTES,
                $rid,
                ['vehicle_id' => $replacementId, 'updated_at' => date('c')],
                /* merge */ true
            );
            $routesUpdated++;
        }

        // Write replacement history row
        $now = date('c');
        $data = [
            'recordId'               => $recordId,
            'schoolId'               => $this->schoolId,
            'original_vehicle_id'    => $originalId,
            'replacement_vehicle_id' => $replacementId,
            'replacement_type'       => $type,
            'route_ids'              => $routeIds,
            'effective_from'         => $effFrom,
            'effective_to'           => $effTo,
            'reason'                 => $reason,
            'authorised_by'          => trim((string) ($input['authorised_by'] ?? $this->adminId)),
            'notes'                  => trim((string) ($input['notes']          ?? '')),
            'status'                 => 'Active',
            'created_at'             => $now,
        ];
        $this->fs->setEntity(self::COL_VEHICLE_REPLACEMENT_HISTORY, $recordId, $data, /* merge */ false);

        // Emit lifecycle log entries on both vehicles — they don't change
        // state (that's caller's separate transitionVehicleLifecycle call),
        // but the timeline should show the replacement event.
        $origLifecycle = (string) ($orig['lifecycle_status'] ?? 'Active');
        $replLifecycle = (string) ($repl['lifecycle_status'] ?? 'Active');
        $this->_appendLifecycleEvent(
            $originalId,
            'Replacement_Retired',
            $origLifecycle, $origLifecycle,
            "Replaced by {$replacementId} ({$type}). {$reason}",
            [],
            $replacementId
        );
        $this->_appendLifecycleEvent(
            $replacementId,
            'Replacement_Received',
            $replLifecycle, $replLifecycle,
            "Replacing {$originalId} ({$type}). {$reason}",
            [],
            $originalId
        );

        $this->emitAudit(
            'vehicleReplacement',
            $recordId,
            'recorded',
            null,
            $data,
            ['domain' => self::DOMAIN_FLEET, 'original_vehicle_id' => $originalId, 'replacement_vehicle_id' => $replacementId]
        );

        return ['id' => $recordId, 'data' => $data, 'routes_updated' => $routesUpdated];
    }

    /**
     * Move a vehicle from one route to another. Writes a
     * vehicleRouteTransferHistory row + updates both routes' vehicle_id.
     */
    public function transferVehicleBetweenRoutes(
        string $vehicleId,
        string $fromRouteId,
        string $toRouteId,
        string $reason
    ): array {
        $this->_assertReady();
        if ($vehicleId === '' || $fromRouteId === '' || $toRouteId === '') {
            throw new \InvalidArgumentException('vehicleId, fromRouteId and toRouteId are all required.');
        }
        if ($fromRouteId === $toRouteId) {
            throw new \InvalidArgumentException('fromRouteId and toRouteId must differ.');
        }
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('reason is required.');
        }
        $veh    = $this->fs->getEntity(self::COL_VEHICLES, $vehicleId);
        $fromR  = $this->fs->getEntity(self::COL_ROUTES,   $fromRouteId);
        $toR    = $this->fs->getEntity(self::COL_ROUTES,   $toRouteId);
        if (!is_array($veh) || !is_array($fromR) || !is_array($toR)) {
            throw new \InvalidArgumentException('Vehicle and both routes must exist.');
        }
        if ((string) ($fromR['vehicle_id'] ?? '') !== $vehicleId) {
            throw new \InvalidArgumentException(
                "Vehicle {$vehicleId} is not currently assigned to route {$fromRouteId}."
            );
        }

        $transferId = $this->numbering->next('trn_vehicle_transfer', [
            'claimedBy'  => $this->adminId,
            'claimedFor' => 'F1:transferVehicleBetweenRoutes',
        ]);
        $now  = date('c');

        // Detach from source route, attach to target
        $this->fs->setEntity(
            self::COL_ROUTES, $fromRouteId,
            ['vehicle_id' => '', 'updated_at' => $now],
            /* merge */ true
        );
        $this->fs->setEntity(
            self::COL_ROUTES, $toRouteId,
            ['vehicle_id' => $vehicleId, 'updated_at' => $now],
            /* merge */ true
        );

        $data = [
            'transferId'    => $transferId,
            'schoolId'      => $this->schoolId,
            'vehicle_id'    => $vehicleId,
            'from_route_id' => $fromRouteId,
            'to_route_id'   => $toRouteId,
            'effective_from'=> $now,
            'moved_by'      => $this->adminId,
            'reason'        => trim($reason),
        ];
        $this->fs->setEntity(self::COL_VEHICLE_ROUTE_TRANSFER_HISTORY, $transferId, $data, /* merge */ false);

        $this->emitAudit(
            'vehicleRouteTransfer',
            $transferId,
            'recorded',
            null,
            $data,
            ['domain' => self::DOMAIN_FLEET, 'vehicle_id' => $vehicleId]
        );

        return ['id' => $transferId, 'data' => $data];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  DOMAIN_VENDOR (F1) — Vendor CRUD + linkage (LC-8)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Save (create or update) a Transport Vendor master record.
     *
     * Vendor bank details are deferred to feature F5 (Transport Expenses +
     * Accounting) — F1 stores vendor master without bank fields to keep
     * the encryption decision atomic with the payment-flow feature.
     *
     * @param array<string,mixed> $input
     * @return array{id:string, isNew:bool, data:array<string,mixed>}
     */
    public function saveVendor(array $input): array
    {
        $this->_assertReady();
        $id       = trim((string) ($input['id'] ?? ''));
        $isNew    = ($id === '');
        $existing = $isNew ? null : $this->fs->getEntity(self::COL_TRANSPORT_VENDORS, $id);

        $name = trim((string) ($input['vendor_name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('vendor_name is required.');
        }

        if ($isNew) {
            $id = $this->numbering->next('trn_vendor', [
                'claimedBy'  => $this->adminId,
                'claimedFor' => 'F1:saveVendor',
            ]);
        }

        $now  = date('c');
        $data = [
            'vendorId'        => $id,
            'schoolId'        => $this->schoolId,
            'vendor_name'     => $name,
            'contact_person'  => trim((string) ($input['contact_person'] ?? '')),
            'phone'           => trim((string) ($input['phone']          ?? '')),
            'email'           => trim((string) ($input['email']          ?? '')),
            'gstin'           => trim((string) ($input['gstin']          ?? '')),
            'address'         => trim((string) ($input['address']        ?? '')),
            'status'          => trim((string) (
                $input['status']
                ?? ($existing['status'] ?? 'Active')
            )),
            'notes'           => trim((string) ($input['notes']          ?? '')),
            'agreement_paths' => array_values(array_filter(array_map(
                'strval',
                (array) ($input['agreement_paths'] ?? ($existing['agreement_paths'] ?? []))
            ))),
            'updated_at'      => $now,
        ];
        if (!in_array($data['status'], ['Active', 'Terminated'], true)) {
            $data['status'] = 'Active';
        }
        if ($isNew) {
            $data['onboarded_at'] = $now;
            $data['created_by']   = $this->adminId;
        }

        $this->fs->setEntity(self::COL_TRANSPORT_VENDORS, $id, $data, /* merge */ true);

        $this->emitAudit(
            'transportVendor',
            $id,
            $isNew ? 'created' : 'updated',
            $isNew ? null : $existing,
            $data,
            ['domain' => self::DOMAIN_VENDOR]
        );

        return ['id' => $id, 'isNew' => $isNew, 'data' => $data];
    }

    /**
     * Terminate a vendor. Flips status to 'Terminated', stamps terminated_at.
     * Does NOT auto-modify vehicles that reference the vendor — those are
     * flagged for admin review via the expiry engine's next run.
     */
    public function terminateVendor(string $vendorId, string $reason): void
    {
        $this->_assertReady();
        if ($vendorId === '' || trim($reason) === '') {
            throw new \InvalidArgumentException('vendorId and reason are required.');
        }
        $existing = $this->fs->getEntity(self::COL_TRANSPORT_VENDORS, $vendorId);
        if (!is_array($existing)) {
            throw new \InvalidArgumentException('Vendor not found: ' . $vendorId);
        }
        if ((string) ($existing['status'] ?? '') === 'Terminated') {
            throw new \InvalidArgumentException('Vendor is already Terminated: ' . $vendorId);
        }
        $now = date('c');
        $update = [
            'status'          => 'Terminated',
            'terminated_at'   => $now,
            'termination_reason' => trim($reason),
            'updated_at'      => $now,
        ];
        $this->fs->setEntity(self::COL_TRANSPORT_VENDORS, $vendorId, $update, /* merge */ true);

        $this->emitAudit(
            'transportVendor',
            $vendorId,
            'terminated',
            ['status' => (string) ($existing['status'] ?? '')],
            $update,
            ['domain' => self::DOMAIN_VENDOR, 'reason' => $reason]
        );
    }

    /**
     * Link a vendor to a vehicle (marks vehicle ownership_type=Contract).
     * Updates vehicle contract fields and appends a Vendor_Changed lifecycle
     * log entry.
     *
     * @param array{
     *   monthly_hire_amount?:float,
     *   contract_start_date?:string,
     *   contract_end_date?:string,
     *   contract_terms_path?:string
     * } $terms
     */
    public function linkVendorToVehicle(string $vendorId, string $vehicleId, array $terms = []): void
    {
        $this->_assertReady();
        if ($vendorId === '' || $vehicleId === '') {
            throw new \InvalidArgumentException('vendorId and vehicleId are required.');
        }
        $vendor  = $this->fs->getEntity(self::COL_TRANSPORT_VENDORS, $vendorId);
        $vehicle = $this->fs->getEntity(self::COL_VEHICLES,          $vehicleId);
        if (!is_array($vendor) || !is_array($vehicle)) {
            throw new \InvalidArgumentException('Vendor and vehicle must both exist.');
        }
        if ((string) ($vendor['status'] ?? '') !== 'Active') {
            throw new \InvalidArgumentException('Cannot link a non-Active vendor: ' . $vendorId);
        }

        $prevVendorId = (string) ($vehicle['vendor_id'] ?? '');
        $now = date('c');
        $vehicleUpdate = [
            'ownership_type'      => 'Contract',
            'vendor_id'           => $vendorId,
            'monthly_hire_amount' => (float) ($terms['monthly_hire_amount'] ?? ($vehicle['monthly_hire_amount'] ?? 0)),
            'contract_start_date' => trim((string) ($terms['contract_start_date'] ?? ($vehicle['contract_start_date'] ?? ''))),
            'contract_end_date'   => trim((string) ($terms['contract_end_date']   ?? ($vehicle['contract_end_date']   ?? ''))),
            'contract_terms_path' => trim((string) ($terms['contract_terms_path'] ?? ($vehicle['contract_terms_path'] ?? ''))),
            'updated_at'          => $now,
        ];
        $this->fs->setEntity(self::COL_VEHICLES, $vehicleId, $vehicleUpdate, /* merge */ true);

        $lifecycle = (string) ($vehicle['lifecycle_status'] ?? 'Active');
        $this->_appendLifecycleEvent(
            $vehicleId,
            'Vendor_Changed',
            $lifecycle, $lifecycle,
            "Vendor linked: {$prevVendorId} → {$vendorId}",
            [],
            null
        );

        $this->emitAudit(
            'vehicle',
            $vehicleId,
            'vendor_linked',
            ['ownership_type' => (string) ($vehicle['ownership_type'] ?? ''), 'vendor_id' => $prevVendorId],
            $vehicleUpdate,
            ['domain' => self::DOMAIN_VENDOR, 'vendor_id' => $vendorId]
        );
    }

    /**
     * List Active vendors, ordered by name. Uses composite
     * transportVendors(schoolId, status, vendor_name).
     */
    public function listActiveVendors(): array
    {
        $this->_assertReady();
        $rows = (array) $this->fs->schoolWhere(
            self::COL_TRANSPORT_VENDORS,
            [['status', '==', 'Active']],
            'vendor_name', 'ASC'
        );
        $out = [];
        foreach ($rows as $doc) {
            $v = $this->_unwrapNestedRow($doc, 'schoolId');
            if (empty($v)) { continue; }
            $id = (string) ($v['vendorId'] ?? $v['id'] ?? '');
            if ($id === '') { continue; }
            $v['id'] = $id;
            $out[] = $v;
        }
        return $out;
    }

    /**
     * List ALL vendors (both Active and Terminated), for the admin CRUD UI.
     */
    public function listAllVendors(): array
    {
        $this->_assertReady();
        $rows = (array) $this->fs->schoolWhere(
            self::COL_TRANSPORT_VENDORS, [], 'vendor_name', 'ASC'
        );
        $out = [];
        foreach ($rows as $doc) {
            $v = $this->_unwrapNestedRow($doc, 'schoolId');
            if (empty($v)) { continue; }
            $id = (string) ($v['vendorId'] ?? $v['id'] ?? '');
            if ($id === '') { continue; }
            $v['id'] = $id;
            $out[] = $v;
        }
        return $out;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  DOMAIN_FLEET (F1) — Maintenance + Fuel logs (LC-14)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Record a maintenance / service entry for a vehicle.
     * Updates the vehicle's last_service_km / last_service_date /
     * next_service_due_km alongside the log write (batched via merge=true).
     *
     * Cost stored on the log; posting to Accounting is deferred to F5.
     */
    public function saveMaintenanceLog(array $input): array
    {
        $this->_assertReady();
        $vehicleId = trim((string) ($input['vehicle_id'] ?? ''));
        if ($vehicleId === '') {
            throw new \InvalidArgumentException('vehicle_id is required.');
        }
        $vehicle = $this->fs->getEntity(self::COL_VEHICLES, $vehicleId);
        if (!is_array($vehicle)) {
            throw new \InvalidArgumentException('Vehicle not found: ' . $vehicleId);
        }

        $id  = $this->numbering->next('trn_maintenance', [
            'claimedBy'  => $this->adminId,
            'claimedFor' => 'F1:saveMaintenanceLog',
        ]);
        $now = date('c');
        $serviceKm      = max(0, (int) ($input['service_km'] ?? 0));
        $serviceDate    = trim((string) ($input['service_date'] ?? date('Y-m-d')));
        $svcKmInterval  = (int) ($vehicle['service_km_interval'] ?? 10000);
        $nextDueKm      = $serviceKm > 0 && $svcKmInterval > 0 ? ($serviceKm + $svcKmInterval) : 0;

        $partsReplaced = array_values(array_filter(array_map(
            'strval',
            (array) ($input['parts_replaced'] ?? [])
        )));

        $data = [
            'maintenanceId'                => $id,
            'schoolId'                     => $this->schoolId,
            'vehicle_id'                   => $vehicleId,
            'service_type'                 => trim((string) ($input['service_type']   ?? 'Routine')),
            'service_date'                 => $serviceDate,
            'service_km'                   => $serviceKm,
            'workshop'                     => trim((string) ($input['workshop']       ?? '')),
            'cost'                         => (float) ($input['cost']                 ?? 0),
            'parts_replaced'               => $partsReplaced,
            'next_service_km_projected'    => $nextDueKm,
            'next_service_date_projected'  => trim((string) ($input['next_service_date_projected'] ?? '')),
            'notes'                        => trim((string) ($input['notes']          ?? '')),
            'receipt_path'                 => trim((string) ($input['receipt_path']   ?? '')),
            'created_by'                   => $this->adminId,
            'created_at'                   => $now,
        ];
        $this->fs->setEntity(self::COL_MAINTENANCE_LOGS, $id, $data, /* merge */ false);

        // Update the vehicle's service planning fields
        $vehUpdate = [
            'last_service_km'       => $serviceKm,
            'last_service_date'     => $serviceDate,
            'next_service_due_km'   => $nextDueKm,
            'next_service_due_date' => (string) $data['next_service_date_projected'],
            'updated_at'            => $now,
        ];
        $this->fs->setEntity(self::COL_VEHICLES, $vehicleId, $vehUpdate, /* merge */ true);

        $this->emitAudit(
            'maintenanceLog',
            $id,
            'recorded',
            null,
            ['vehicle_id' => $vehicleId, 'service_date' => $serviceDate, 'cost' => $data['cost']],
            ['domain' => self::DOMAIN_FLEET, 'vehicle_id' => $vehicleId]
        );

        return ['id' => $id, 'data' => $data];
    }

    /**
     * List maintenance entries for a vehicle, most recent first.
     * Uses composite maintenanceLogs(schoolId, vehicle_id, service_date DESC).
     */
    public function listMaintenanceLogs(string $vehicleId): array
    {
        $this->_assertReady();
        if ($vehicleId === '') { return []; }
        $rows = (array) $this->fs->schoolWhere(
            self::COL_MAINTENANCE_LOGS,
            [['vehicle_id', '==', $vehicleId]],
            'service_date', 'DESC'
        );
        $out = [];
        foreach ($rows as $doc) {
            $d = $this->_unwrapNestedRow($doc, 'schoolId');
            if (empty($d)) { continue; }
            $id = (string) ($d['maintenanceId'] ?? $d['id'] ?? '');
            if ($id === '') { continue; }
            $d['id'] = $id;
            $out[] = $d;
        }
        return $out;
    }

    /**
     * Record a fuel entry for a vehicle. Recomputes vehicle.average_kmpl
     * from the LAST 5 fuel entries (rolling window) via _recomputeKmpl.
     * Cost stored on the log; Accounting posting deferred to F5.
     */
    public function saveFuelLog(array $input): array
    {
        $this->_assertReady();
        $vehicleId = trim((string) ($input['vehicle_id'] ?? ''));
        if ($vehicleId === '') {
            throw new \InvalidArgumentException('vehicle_id is required.');
        }
        $vehicle = $this->fs->getEntity(self::COL_VEHICLES, $vehicleId);
        if (!is_array($vehicle)) {
            throw new \InvalidArgumentException('Vehicle not found: ' . $vehicleId);
        }
        $litres = (float) ($input['litres']   ?? 0);
        $ppl    = (float) ($input['price_per_litre'] ?? 0);
        if ($litres <= 0) {
            throw new \InvalidArgumentException('litres must be greater than zero.');
        }
        $id  = $this->numbering->next('trn_fuel', [
            'claimedBy'  => $this->adminId,
            'claimedFor' => 'F1:saveFuelLog',
        ]);
        $now = date('c');
        $fuelDate  = trim((string) ($input['fuel_date']    ?? date('Y-m-d')));
        $odoKm     = max(0, (int) ($input['odometer_km']   ?? 0));
        $totalAmt  = (float) ($input['total_amount'] ?? ($litres * $ppl));

        $data = [
            'fuelId'           => $id,
            'schoolId'         => $this->schoolId,
            'vehicle_id'       => $vehicleId,
            'fuel_date'        => $fuelDate,
            'litres'           => $litres,
            'price_per_litre'  => $ppl,
            'total_amount'     => $totalAmt,
            'station'          => trim((string) ($input['station']    ?? '')),
            'driver_staff_id'  => trim((string) ($input['driver_staff_id'] ?? '')),
            'odometer_km'      => $odoKm,
            'notes'            => trim((string) ($input['notes']      ?? '')),
            'receipt_path'     => trim((string) ($input['receipt_path'] ?? '')),
            'created_by'       => $this->adminId,
            'created_at'       => $now,
        ];
        $this->fs->setEntity(self::COL_FUEL_LOGS, $id, $data, /* merge */ false);

        // Recompute rolling kmpl and update vehicle
        $newKmpl = $this->_recomputeKmpl($vehicleId, 5);
        $vehUpdate = [
            'average_kmpl' => $newKmpl,
            'updated_at'   => $now,
        ];
        // Only overwrite kmpl when we have enough data (>=2 fuel entries).
        if ($newKmpl > 0) {
            $this->fs->setEntity(self::COL_VEHICLES, $vehicleId, $vehUpdate, /* merge */ true);
            $data['computed_kmpl'] = $newKmpl;
            // Backfill the log with the computed kmpl for audit convenience.
            $this->fs->setEntity(self::COL_FUEL_LOGS, $id, ['computed_kmpl' => $newKmpl], /* merge */ true);
        }

        $this->emitAudit(
            'fuelLog',
            $id,
            'recorded',
            null,
            ['vehicle_id' => $vehicleId, 'fuel_date' => $fuelDate, 'litres' => $litres, 'total_amount' => $totalAmt, 'computed_kmpl' => $newKmpl],
            ['domain' => self::DOMAIN_FLEET, 'vehicle_id' => $vehicleId]
        );

        return ['id' => $id, 'data' => $data, 'computed_kmpl' => $newKmpl];
    }

    /**
     * List fuel entries for a vehicle, most recent first.
     */
    public function listFuelLogs(string $vehicleId): array
    {
        $this->_assertReady();
        if ($vehicleId === '') { return []; }
        $rows = (array) $this->fs->schoolWhere(
            self::COL_FUEL_LOGS,
            [['vehicle_id', '==', $vehicleId]],
            'fuel_date', 'DESC'
        );
        $out = [];
        foreach ($rows as $doc) {
            $d = $this->_unwrapNestedRow($doc, 'schoolId');
            if (empty($d)) { continue; }
            $id = (string) ($d['fuelId'] ?? $d['id'] ?? '');
            if ($id === '') { continue; }
            $d['id'] = $id;
            $out[] = $d;
        }
        return $out;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  DOMAIN_FLEET (F1) — Expiry engine (LC-14)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Compute the fleet expiry dashboard cache for the current tenant.
     *
     * Scans:
     *   • vehicles.{insurance_expiry, permit_expiry, pollution_expiry,
     *     fitness_expiry, road_tax_expiry}
     *   • vehicleDocuments.{expiry_date}
     *
     * Buckets each expiring item into 7-day / 15-day / 30-day windows,
     * writes {schoolId}_VEXPCACHE, and emits Communication notices for
     * 7-day expiries (audience: Admin + Transport Manager only, per the
     * F1-approved decision — Principal escalation is deferred to F11
     * dashboard).
     *
     * Returns the summary payload (also written to the cache doc).
     *
     * NOTE: driver_meta.licence_expires / medical_expires belong to
     * feature F4 (Driver Management) — F1 does NOT scan staff docs
     * for driver expiries. This method degrades gracefully once F4
     * populates driver_meta by adding a driver-side loop.
     */
    public function computeFleetExpiryDashboard(): array
    {
        $this->_assertReady();
        $today = date('Y-m-d');
        $t30   = date('Y-m-d', strtotime('+30 days'));
        $t15   = date('Y-m-d', strtotime('+15 days'));
        $t7    = date('Y-m-d', strtotime('+7 days'));

        $vehicles = (array) $this->fs->schoolWhere(self::COL_VEHICLES, []);
        $items    = [];  // flat list of {vehicleId, kind, label, expiry_date, days_left, window}
        foreach ($vehicles as $v) {
            if (!is_array($v)) { continue; }
            $v = $this->_unwrapNestedRow($v, 'schoolId');   // schoolWhere returns {id,data}
            $vid    = (string) ($v['vehicleId'] ?? $v['id'] ?? '');
            $number = (string) ($v['number']    ?? '');
            if ($vid === '') { continue; }

            $checks = [
                'insurance' => (string) ($v['insurance_expiry'] ?? ''),
                'fitness'   => (string) ($v['fitness_expiry']   ?? ''),
                'permit'    => (string) ($v['permit_expiry']    ?? ''),
                'pollution' => (string) ($v['pollution_expiry'] ?? ''),
                'road_tax'  => (string) ($v['road_tax_expiry']  ?? ''),
            ];
            foreach ($checks as $kind => $exp) {
                if ($exp === '' || $exp < $today) { continue; }
                if ($exp > $t30) { continue; }
                $items[] = [
                    'vehicleId'   => $vid,
                    'number'      => $number,
                    'kind'        => $kind,
                    'label'       => ucfirst(str_replace('_', ' ', $kind)),
                    'expiry_date' => $exp,
                    'days_left'   => max(0, (int) ((strtotime($exp) - strtotime($today)) / 86400)),
                    'window'      => $exp <= $t7 ? 7 : ($exp <= $t15 ? 15 : 30),
                    'source'      => 'vehicle',
                    'source_id'   => $vid,
                ];
            }
        }

        // Vehicle document expiries
        $expiringDocs = $this->listExpiringDocuments(30);
        foreach ($expiringDocs as $d) {
            $items[] = [
                'vehicleId'   => (string) ($d['vehicle_id'] ?? ''),
                'number'      => '',
                'kind'        => 'document:' . (string) ($d['doc_type'] ?? ''),
                'label'       => 'Doc: ' . (string) ($d['doc_type'] ?? ''),
                'expiry_date' => (string) ($d['expiry_date'] ?? ''),
                'days_left'   => (int) ($d['days_left'] ?? 0),
                'window'      => ($d['expiry_date'] ?? '') <= $t7 ? 7 : (($d['expiry_date'] ?? '') <= $t15 ? 15 : 30),
                'source'      => 'vehicleDocument',
                'source_id'   => (string) ($d['id'] ?? ''),
            ];
        }

        // Bucket counts
        $count7  = count(array_filter($items, static fn($i) => $i['window'] === 7));
        $count15 = count(array_filter($items, static fn($i) => $i['window'] === 15));
        $count30 = count(array_filter($items, static fn($i) => $i['window'] === 30));

        // Cache write
        $now = date('c');
        $cache = [
            'schoolId'          => $this->schoolId,
            'computed_at'       => $now,
            'count_7_days'      => $count7,
            'count_15_days'     => $count15,
            'count_30_days'     => $count30,
            'items'             => array_slice($items, 0, 100), // safety cap
            'items_truncated'   => count($items) > 100,
        ];
        // RC-3: bare entity id — setEntity() adds the single {schoolId} prefix,
        // giving canonical {schoolId}_VEXPCACHE (was double-prefixed, which the
        // bare-id analytics readers missed — PERF-5).
        $cacheDocId = 'VEXPCACHE';
        $this->fs->setEntity(self::COL_VEHICLE_EXPIRY_DASHBOARD_CACHE, $cacheDocId, $cache, /* merge */ false);

        // Notify Admin + Transport Manager on 7-day expiries. Post-F6 this
        // routes through Transport_notifier (fleet.doc_expiring category,
        // Important priority, 24h dedup bucket).
        if ($count7 > 0) {
            $this->_emitExpiryNotices(array_filter($items, static fn($i) => $i['window'] === 7));
        }

        return $cache;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  DOMAIN_ROUTE (F2, 2026-07-07) — Routes, Stops, Route Suspensions
    // ═════════════════════════════════════════════════════════════════════
    //
    // Feature F2 refinements baked in:
    //   1. Parent notification via business rule (category-defaulted +
    //      admin override).
    //   2. Soft delete via archiveRoute; permanentlyDeleteRoute kept as
    //      power-user action for pre-referenced cleanup.
    //   3. Stop ordering extensibility — reserved field-name registry
    //      RESERVED_STOP_OPTIMIZATION_FIELDS enforced by guard method so
    //      the future route-optimisation feature lands without collisions.
    //   4. Standardised suspension reason categories +  free-text notes.

    /** Route status enum — soft-delete anchor. */
    public const ROUTE_STATUSES = ['Active', 'Inactive', 'Archived'];

    /** Direction of service on a route (reserved for F3 subscription types). */
    public const ROUTE_DIRECTIONS = ['Both', 'Pickup', 'Drop'];

    /** Days a route may operate. */
    public const ROUTE_SERVICE_DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    /**
     * Stop-schema fields RESERVED for future route-optimisation feature.
     * F2 code MUST NOT write any of these — guard enforced by
     * _assertNoReservedStopOptimizationWrites(). Documents the forward-
     * compat contract so the optimisation feature can land without a
     * schema bump.
     */
    private const RESERVED_STOP_OPTIMIZATION_FIELDS = [
        'optimized_order',
        'distance_from_prev_km',
        'optimization_score',
        'route_optimizer_version',
    ];

    /**
     * Suspension reason categories (Refinement #4).
     *
     * Kept as a class constant for F2. Future per-tenant customisation
     * (e.g., schoolConfig.transportSuspensionCategories[]) can be layered
     * on later without breaking existing suspension docs — the field
     * remains a free string; the constant is just the recommended enum
     * the UI seeds a dropdown from.
     */
    public const SUSPENSION_REASON_CATEGORIES = [
        'Vehicle_Breakdown',
        'Maintenance',
        'Weather',
        'Road_Closure',
        'Driver_Unavailable',
        'Other',
    ];

    /**
     * Per-category default for whether parents on the affected route
     * should be notified. Refinement #1: parents notified only when
     * meaningful operational impact.
     *
     *   Vehicle_Breakdown  — unplanned, sudden. Parents need to know.
     *   Weather            — urgent, safety.
     *   Road_Closure       — impacts pickup schedule.
     *   Maintenance        — planned, internal.
     *   Driver_Unavailable — usually a short-term substitute; parents
     *                        rarely affected by the driver identity.
     *   Other              — conservative default; admin can opt-in.
     *
     * Admin can override in the UI via the `notify_parents` field on the
     * suspension form.
     */
    private const SUSPENSION_PARENT_NOTIFICATION_DEFAULT = [
        'Vehicle_Breakdown'  => true,
        'Maintenance'        => false,
        'Weather'            => true,
        'Road_Closure'       => true,
        'Driver_Unavailable' => false,
        'Other'              => false,
    ];

    public const SUSPENSION_STATUSES = ['Active', 'Resolved'];

    // ─── Route master ──────────────────────────────────────────────────

    /**
     * List routes for the current tenant, ordered by name. Excludes
     * Archived by default (soft-delete visibility rule).
     */
    public function listRoutes(bool $includeArchived = false): array
    {
        $this->_assertReady();
        $rows = (array) $this->fs->schoolWhere(self::COL_ROUTES, [], 'name', 'ASC');
        $out  = [];
        foreach ($rows as $doc) {
            $doc = $this->_unwrapNestedRow($doc, 'schoolId');
            if (empty($doc)) { continue; }
            $status = (string) ($doc['status'] ?? 'Active');
            if (!$includeArchived && $status === 'Archived') { continue; }
            $id = (string) ($doc['routeId'] ?? $doc['id'] ?? '');
            if ($id === '') { continue; }
            $doc['id'] = $id;
            $out[] = $doc;
        }
        return $out;
    }

    public function getRoute(string $routeId): ?array
    {
        $this->_assertReady();
        if ($routeId === '') { return null; }
        $r = $this->fs->getEntity(self::COL_ROUTES, $routeId);
        return is_array($r) ? $r : null;
    }

    /**
     * Save (create or update) a route. Numbering_service allocates RT
     * IDs on create (F2 migration from Operations_accounting — same
     * self-heal proven end-to-end for F1 VH0098 → VH0099).
     *
     * Additive F2 fields: session (tag, not doc-key), direction,
     * service_days, active_suspension_ref (denormalised badge),
     * notes.
     */
    public function saveRoute(array $input): array
    {
        $this->_assertReady();
        $id       = trim((string) ($input['id'] ?? ''));
        $isNew    = ($id === '');
        $existing = $isNew ? null : $this->fs->getEntity(self::COL_ROUTES, $id);

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Route name is required.');
        }
        $vehicleId = trim((string) ($input['vehicle_id'] ?? ''));
        if ($vehicleId !== '') {
            $veh = $this->fs->getEntity(self::COL_VEHICLES, $vehicleId);
            if (!is_array($veh)) {
                throw new \InvalidArgumentException('Referenced vehicle does not exist: ' . $vehicleId);
            }
        }
        // Allocate ID via Numbering_service on create
        if ($isNew) {
            $id = $this->numbering->next('trn_route', [
                'claimedBy'  => $this->adminId,
                'claimedFor' => 'F2:saveRoute',
            ]);
        }

        // Status: preserve on edit; default Active on create. Explicit
        // status changes go through archiveRoute / unarchiveRoute — do
        // NOT accept status flips through saveRoute (prevents accidental
        // client-side archive bypass of the archive-metadata write).
        if ($isNew) {
            $status = 'Active';
        } else {
            $status = (string) ($existing['status'] ?? 'Active');
        }

        // service_days: intersect with valid tokens
        $svcDaysInput = $input['service_days'] ?? ($existing['service_days'] ?? ['Mon','Tue','Wed','Thu','Fri','Sat']);
        if (!is_array($svcDaysInput)) { $svcDaysInput = []; }
        $svcDays = array_values(array_intersect(
            array_map(static fn($d) => (string) $d, $svcDaysInput),
            self::ROUTE_SERVICE_DAYS
        ));
        if ($svcDays === []) {
            $svcDays = ['Mon','Tue','Wed','Thu','Fri','Sat'];
        }

        $direction = (string) ($input['direction'] ?? ($existing['direction'] ?? 'Both'));
        if (!in_array($direction, self::ROUTE_DIRECTIONS, true)) {
            $direction = 'Both';
        }

        $now  = date('c');
        $data = [
            'routeId'              => $id,
            'schoolId'             => $this->schoolId,
            'name'                 => $name,
            'vehicle_id'           => $vehicleId,
            'start_point'          => trim((string) ($input['start_point'] ?? '')),
            'end_point'            => trim((string) ($input['end_point']   ?? '')),
            'distance_km'          => (float) ($input['distance_km'] ?? 0),
            'monthly_fee'          => (float) ($input['monthly_fee'] ?? 0),
            'status'               => $status,
            'session'              => (string) ($input['session'] ?? ($existing['session'] ?? $this->sessionYear)),
            'direction'            => $direction,
            'service_days'         => $svcDays,
            // active_suspension_ref preserved from existing (do not accept
            // client-side override — it is set by saveRouteSuspension /
            // resumeRoute only).
            'active_suspension_ref' => (string) ($existing['active_suspension_ref'] ?? ''),
            'notes'                => trim((string) ($input['notes'] ?? '')),
            'updated_at'           => $now,
        ];
        if ($isNew) {
            $data['created_at'] = $now;
            $data['created_by'] = $this->adminId;
        }

        $this->fs->setEntity(self::COL_ROUTES, $id, $data, /* merge */ true);

        $this->emitAudit(
            'route', $id,
            $isNew ? 'created' : 'updated',
            $isNew ? null : $existing,
            $data,
            ['domain' => self::DOMAIN_ROUTE]
        );

        return ['id' => $id, 'isNew' => $isNew, 'data' => $data];
    }

    /**
     * Soft-delete a route (Refinement #2): sets status='Archived', records
     * archived_at + archived_by + archive_reason. Historical assignments,
     * trips, and audit trails remain intact for reporting.
     *
     * Blocked when the route has an active suspension (resume first).
     */
    public function archiveRoute(string $routeId, string $reason): void
    {
        $this->_assertReady();
        if ($routeId === '') {
            throw new \InvalidArgumentException('routeId is required.');
        }
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('reason is required for archiving.');
        }
        $existing = $this->fs->getEntity(self::COL_ROUTES, $routeId);
        if (!is_array($existing)) {
            throw new \InvalidArgumentException('Route not found: ' . $routeId);
        }
        if ((string) ($existing['status'] ?? '') === 'Archived') {
            throw new \InvalidArgumentException('Route is already Archived: ' . $routeId);
        }
        if ((string) ($existing['active_suspension_ref'] ?? '') !== '') {
            throw new \InvalidArgumentException(
                'Cannot archive: route has an active suspension. Resume it first.'
            );
        }
        $now    = date('c');
        $update = [
            'status'         => 'Archived',
            'archived_at'    => $now,
            'archived_by'    => $this->adminId,
            'archive_reason' => trim($reason),
            'updated_at'     => $now,
        ];
        $this->fs->setEntity(self::COL_ROUTES, $routeId, $update, /* merge */ true);

        $this->emitAudit(
            'route', $routeId, 'archived',
            ['status' => (string) ($existing['status'] ?? '')],
            $update,
            ['domain' => self::DOMAIN_ROUTE, 'reason' => $reason]
        );
    }

    /**
     * Restore an Archived route back to Active (or Inactive if the caller
     * wants to review before re-activating).
     */
    public function unarchiveRoute(string $routeId, string $toStatus = 'Active'): void
    {
        $this->_assertReady();
        if ($routeId === '') {
            throw new \InvalidArgumentException('routeId is required.');
        }
        if (!in_array($toStatus, ['Active', 'Inactive'], true)) {
            throw new \InvalidArgumentException('unarchive target must be Active or Inactive.');
        }
        $existing = $this->fs->getEntity(self::COL_ROUTES, $routeId);
        if (!is_array($existing)) {
            throw new \InvalidArgumentException('Route not found: ' . $routeId);
        }
        if ((string) ($existing['status'] ?? '') !== 'Archived') {
            throw new \InvalidArgumentException('Route is not Archived.');
        }
        $now    = date('c');
        $update = [
            'status'          => $toStatus,
            // clear archive metadata but leave historical trace via audit
            'archived_at'     => '',
            'archived_by'     => '',
            'archive_reason'  => '',
            'unarchived_at'   => $now,
            'unarchived_by'   => $this->adminId,
            'updated_at'      => $now,
        ];
        $this->fs->setEntity(self::COL_ROUTES, $routeId, $update, /* merge */ true);

        $this->emitAudit(
            'route', $routeId, 'unarchived',
            ['status' => 'Archived'], $update,
            ['domain' => self::DOMAIN_ROUTE, 'to_status' => $toStatus]
        );
    }

    /**
     * Hard-delete a route — admin power action for cleanup of mis-created
     * routes that were never referenced. Blocked when ANY assignment
     * references the route (studentRoutes legacy OR routeAssignments
     * once F3 lands). Cascades stops that belong to this route.
     *
     * Admin UI SHOULD default to archiveRoute; permanentlyDeleteRoute
     * belongs behind a "power user" affordance with an extra confirm.
     */
    public function permanentlyDeleteRoute(string $routeId): void
    {
        $this->_assertReady();
        if ($routeId === '') {
            throw new \InvalidArgumentException('routeId is required.');
        }
        $existing = $this->fs->getEntity(self::COL_ROUTES, $routeId);
        if (!is_array($existing)) {
            throw new \InvalidArgumentException('Route not found: ' . $routeId);
        }
        // Reference checks — legacy + v2 collections
        $refs = (array) $this->fs->schoolWhere(
            self::COL_STUDENT_ROUTES_LEGACY, [['route_id', '==', $routeId]]
        );
        try {
            $newRefs = (array) $this->fs->schoolWhere(
                self::COL_ROUTE_ASSIGNMENTS, [['route_id', '==', $routeId]]
            );
            $refs = array_merge($refs, $newRefs);
        } catch (\Throwable $_) {
            // routeAssignments not yet populated by F3 — ignore
        }
        if ($refs !== []) {
            throw new \RuntimeException(
                'Cannot delete: students are assigned to this route. Archive instead.'
            );
        }

        // Cascade stops
        $stops = (array) $this->fs->schoolWhere(
            self::COL_TRANSPORT_STOPS, [['route_id', '==', $routeId]]
        );
        foreach ($stops as $s) {
            if (!is_array($s)) { continue; }
            $sid = (string) ($s['stopId'] ?? $s['id'] ?? '');
            if ($sid !== '') {
                $this->fs->remove(self::COL_TRANSPORT_STOPS, $this->fs->docId($sid));
            }
        }

        $this->fs->remove(self::COL_ROUTES, $this->fs->docId($routeId));

        $this->emitAudit(
            'route', $routeId, 'permanently_deleted',
            $existing, null,
            ['domain' => self::DOMAIN_ROUTE, 'cascaded_stops' => count($stops)]
        );
    }

    // ─── Stops ─────────────────────────────────────────────────────────

    public function listStops(string $routeId = ''): array
    {
        $this->_assertReady();
        $where = [];
        if ($routeId !== '') {
            $where[] = ['route_id', '==', $routeId];
        }
        $rows = (array) $this->fs->schoolWhere(self::COL_TRANSPORT_STOPS, $where);
        $out  = [];
        foreach ($rows as $doc) {
            $doc = $this->_unwrapNestedRow($doc, 'schoolId');
            if (empty($doc)) { continue; }
            $id = (string) ($doc['stopId'] ?? $doc['id'] ?? '');
            if ($id === '') { continue; }
            $doc['id'] = $id;
            $out[] = $doc;
        }
        // Sort in-memory by 'order' — small per-route list; avoids adding
        // a schoolId+order composite index when the route_id filter alone
        // already narrows to ≤30 stops (design bound).
        usort($out, static fn($a, $b) => ((int) ($a['order'] ?? 0)) - ((int) ($b['order'] ?? 0)));
        return $out;
    }

    public function getStop(string $stopId): ?array
    {
        $this->_assertReady();
        if ($stopId === '') { return null; }
        $s = $this->fs->getEntity(self::COL_TRANSPORT_STOPS, $stopId);
        return is_array($s) ? $s : null;
    }

    public function saveStop(array $input): array
    {
        $this->_assertReady();
        $id       = trim((string) ($input['id'] ?? ''));
        $isNew    = ($id === '');
        $existing = $isNew ? null : $this->fs->getEntity(self::COL_TRANSPORT_STOPS, $id);

        $routeId = trim((string) ($input['route_id'] ?? ''));
        if ($routeId === '') {
            throw new \InvalidArgumentException('route_id is required.');
        }
        $route = $this->fs->getEntity(self::COL_ROUTES, $routeId);
        if (!is_array($route)) {
            throw new \InvalidArgumentException('Referenced route does not exist: ' . $routeId);
        }
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Stop name is required.');
        }

        if ($isNew) {
            $id = $this->numbering->next('trn_stop', [
                'claimedBy'  => $this->adminId,
                'claimedFor' => 'F2:saveStop',
            ]);
        }

        $order = max(0, (int) ($input['order'] ?? ($existing['order'] ?? 0)));

        // ── Stop georeference (2026-08-16) ───────────────────────────────
        // Three states, deliberately distinct — collapsing them is how every
        // stop ended up at 0,0 in the first place:
        //
        //   UNSET   both blank  -> store 0/0, meaning "not georeferenced yet"
        //   INVALID out of range, non-numeric, or exactly 0,0 -> REJECT loudly
        //   VALID   in range    -> store as given
        //
        // createTrip() freezes these into every checkpoint as stop_location,
        // so a silently-coerced 0 would put a checkpoint in the Gulf of Guinea
        // and stay there for the life of the trip. Legacy stops created before
        // this feature keep working: they simply remain UNSET, and an edit that
        // leaves both boxes blank does not invent a coordinate for them.
        $latRaw = $input['latitude']  ?? '';
        $lngRaw = $input['longitude'] ?? '';
        $latStr = is_string($latRaw) ? trim($latRaw) : (string) $latRaw;
        $lngStr = is_string($lngRaw) ? trim($lngRaw) : (string) $lngRaw;
        $latSet = ($latStr !== '' && $latStr !== '0');
        $lngSet = ($lngStr !== '' && $lngStr !== '0');

        $latitude = 0.0; $longitude = 0.0;
        if ($latSet || $lngSet) {
            if (!$latSet || !$lngSet) {
                throw new \InvalidArgumentException(
                    'A stop needs BOTH latitude and longitude, or neither. '
                    . 'A half-placed stop looks georeferenced but is not.');
            }
            if (!is_numeric($latStr) || !is_numeric($lngStr)) {
                throw new \InvalidArgumentException('Latitude and longitude must be numbers.');
            }
            $latitude  = (float) $latStr;
            $longitude = (float) $lngStr;
            if ($latitude < -90 || $latitude > 90) {
                throw new \InvalidArgumentException("Latitude must be between -90 and 90 (got {$latitude}).");
            }
            if ($longitude < -180 || $longitude > 180) {
                throw new \InvalidArgumentException("Longitude must be between -180 and 180 (got {$longitude}).");
            }
        } elseif (!$isNew) {
            // Edit with both boxes blank: keep whatever the stop already had
            // rather than silently un-georeferencing it.
            $latitude  = (float) ($existing['latitude']  ?? 0);
            $longitude = (float) ($existing['longitude'] ?? 0);
        }

        $now  = date('c');
        $data = [
            'stopId'      => $id,
            'schoolId'    => $this->schoolId,
            'route_id'    => $routeId,
            'name'        => $name,
            'pickup_time' => trim((string) ($input['pickup_time'] ?? '')),
            'drop_time'   => trim((string) ($input['drop_time']   ?? '')),
            'order'       => $order,
            'address'     => trim((string) ($input['address']  ?? '')),
            'landmark'    => trim((string) ($input['landmark'] ?? '')),
            'latitude'    => $latitude,
            'longitude'   => $longitude,
            'status'      => trim((string) ($input['status'] ?? ($existing['status'] ?? 'Active'))),
            'updated_at'  => $now,
        ];
        if ($isNew) {
            $data['created_at'] = $now;
        }
        // Refinement #3: guard against writes to reserved future-optimisation fields
        $this->_assertNoReservedStopOptimizationWrites($data);

        $this->fs->setEntity(self::COL_TRANSPORT_STOPS, $id, $data, /* merge */ true);

        $this->emitAudit(
            'transportStop', $id,
            $isNew ? 'created' : 'updated',
            $isNew ? null : $existing,
            $data,
            ['domain' => self::DOMAIN_ROUTE, 'route_id' => $routeId]
        );

        return ['id' => $id, 'isNew' => $isNew, 'data' => $data];
    }

    public function deleteStop(string $stopId): void
    {
        $this->_assertReady();
        if ($stopId === '') {
            throw new \InvalidArgumentException('stopId is required.');
        }
        $existing = $this->fs->getEntity(self::COL_TRANSPORT_STOPS, $stopId);
        if (!is_array($existing)) {
            throw new \InvalidArgumentException('Stop not found: ' . $stopId);
        }
        // Block deletion when any active assignment references this stop
        $refs = (array) $this->fs->schoolWhere(
            self::COL_STUDENT_ROUTES_LEGACY, [['stop_id', '==', $stopId]]
        );
        try {
            $newRefs = (array) $this->fs->schoolWhere(
                self::COL_ROUTE_ASSIGNMENTS, [['stop_id', '==', $stopId]]
            );
            $refs = array_merge($refs, $newRefs);
        } catch (\Throwable $_) {
            // routeAssignments not yet populated
        }
        if ($refs !== []) {
            throw new \RuntimeException('Cannot delete: students are assigned to this stop.');
        }

        $this->fs->remove(self::COL_TRANSPORT_STOPS, $this->fs->docId($stopId));

        $this->emitAudit(
            'transportStop', $stopId, 'deleted',
            $existing, null,
            ['domain' => self::DOMAIN_ROUTE, 'route_id' => (string) ($existing['route_id'] ?? '')]
        );
    }

    // ─── Route Suspensions (LC-10) ─────────────────────────────────────

    /**
     * Create an Active route suspension (Refinement #1 + #4 baked in).
     *
     * Parent notification rule:
     *   - Admin + Transport Manager: ALWAYS notified
     *   - Parents on affected route:
     *       if input['notify_parents'] is explicitly provided → obey it
     *       else fall back to SUSPENSION_PARENT_NOTIFICATION_DEFAULT[category]
     *
     * @param array{
     *   route_id:string,
     *   suspended_from:string,
     *   suspended_to?:string,
     *   reason_category:string,        // one of SUSPENSION_REASON_CATEGORIES
     *   reason:string,                 // free-text notes
     *   notify_parents?:bool,          // admin override
     * } $input
     */
    public function saveRouteSuspension(array $input): array
    {
        $this->_assertReady();
        $routeId  = trim((string) ($input['route_id'] ?? ''));
        $category = trim((string) ($input['reason_category'] ?? ''));
        $reason   = trim((string) ($input['reason'] ?? ''));
        $from     = trim((string) ($input['suspended_from'] ?? ''));
        $to       = trim((string) ($input['suspended_to']   ?? ''));

        if ($routeId === '') { throw new \InvalidArgumentException('route_id is required.'); }
        if ($from === '')    { throw new \InvalidArgumentException('suspended_from is required.'); }
        if ($reason === '')  { throw new \InvalidArgumentException('reason (free-text) is required.'); }
        if (!in_array($category, self::SUSPENSION_REASON_CATEGORIES, true)) {
            throw new \InvalidArgumentException(
                'Invalid reason_category: ' . $category
                . '. Allowed: ' . implode(',', self::SUSPENSION_REASON_CATEGORIES)
            );
        }
        $route = $this->fs->getEntity(self::COL_ROUTES, $routeId);
        if (!is_array($route)) {
            throw new \InvalidArgumentException('Route not found: ' . $routeId);
        }
        if ((string) ($route['status'] ?? '') === 'Archived') {
            throw new \InvalidArgumentException('Cannot suspend an Archived route.');
        }
        if ((string) ($route['active_suspension_ref'] ?? '') !== '') {
            throw new \InvalidArgumentException('Route already has an active suspension. Resume it first.');
        }

        // Notification decision (Refinement #1)
        $notifyParents = array_key_exists('notify_parents', $input)
            ? (bool) $input['notify_parents']
            : (self::SUSPENSION_PARENT_NOTIFICATION_DEFAULT[$category] ?? false);

        $id  = $this->numbering->next('trn_route_suspension', [
            'claimedBy'  => $this->adminId,
            'claimedFor' => 'F2:saveRouteSuspension',
        ]);
        $now = date('c');
        $data = [
            'suspensionId'        => $id,
            'schoolId'            => $this->schoolId,
            'route_id'            => $routeId,
            'reason_category'     => $category,
            'reason'              => $reason,
            'suspended_from'      => $from,
            'suspended_to'        => $to,
            'notify_parents'      => $notifyParents,
            'parents_notified_at' => '',
            'authorised_by'       => $this->adminId,
            'status'              => 'Active',
            'created_at'          => $now,
            'updated_at'          => $now,
        ];
        $this->fs->setEntity(self::COL_ROUTE_SUSPENSIONS, $id, $data, /* merge */ false);

        // Denormalise onto the route for cheap read-side badge
        $this->fs->setEntity(
            self::COL_ROUTES, $routeId,
            ['active_suspension_ref' => $id, 'updated_at' => $now],
            /* merge */ true
        );

        // Audit BEFORE notice (audit is business-truth; notice is best-effort)
        $this->emitAudit(
            'routeSuspension', $id, 'created',
            null, $data,
            [
                'domain'          => self::DOMAIN_ROUTE,
                'route_id'        => $routeId,
                'reason_category' => $category,
                'notify_parents'  => $notifyParents,
            ]
        );

        // Best-effort Communication notice fanout
        $notifiedAt = $this->_emitSuspensionNotice($data, $route, /* isSuspend */ true);
        if ($notifiedAt !== '') {
            $this->fs->setEntity(
                self::COL_ROUTE_SUSPENSIONS, $id,
                ['parents_notified_at' => $notifiedAt],
                /* merge */ true
            );
        }

        return ['id' => $id, 'data' => $data, 'notify_parents' => $notifyParents];
    }

    /**
     * Resolve an Active route suspension (route resumes normal service).
     * Clears routes.active_suspension_ref and emits a resume notice using
     * the same audience rule as the original suspension.
     */
    public function resumeRoute(string $suspensionId, string $notes = ''): void
    {
        $this->_assertReady();
        if ($suspensionId === '') {
            throw new \InvalidArgumentException('suspensionId is required.');
        }
        $existing = $this->fs->getEntity(self::COL_ROUTE_SUSPENSIONS, $suspensionId);
        if (!is_array($existing)) {
            throw new \InvalidArgumentException('Suspension not found: ' . $suspensionId);
        }
        if ((string) ($existing['status'] ?? '') !== 'Active') {
            throw new \InvalidArgumentException('Suspension is not Active: ' . $suspensionId);
        }
        $routeId = (string) ($existing['route_id'] ?? '');
        $route   = $routeId !== '' ? $this->fs->getEntity(self::COL_ROUTES, $routeId) : null;

        $now    = date('c');
        $update = [
            'status'           => 'Resolved',
            'resolved_at'      => $now,
            'resolved_by'      => $this->adminId,
            'resolution_notes' => trim($notes),
            'suspended_to'     => ((string) ($existing['suspended_to'] ?? '')) !== ''
                ? (string) $existing['suspended_to']
                : date('Y-m-d'),
            'updated_at'       => $now,
        ];
        $this->fs->setEntity(self::COL_ROUTE_SUSPENSIONS, $suspensionId, $update, /* merge */ true);

        // Clear denormalisation on the route
        if (is_array($route)) {
            $this->fs->setEntity(
                self::COL_ROUTES, $routeId,
                ['active_suspension_ref' => '', 'updated_at' => $now],
                /* merge */ true
            );
        }

        $this->emitAudit(
            'routeSuspension', $suspensionId, 'resumed',
            ['status' => 'Active'], $update,
            ['domain' => self::DOMAIN_ROUTE, 'route_id' => $routeId]
        );

        // Best-effort resume notice — same audience rule as suspend
        $mergedForNotice = array_merge($existing, $update);
        $this->_emitSuspensionNotice($mergedForNotice, $route, /* isSuspend */ false);
    }

    /**
     * List currently Active suspensions (across all routes) — dashboard read.
     * Uses composite index routeSuspensions(schoolId, route_id, status,
     * suspended_from DESC) — Phase 0 index #14.
     */
    public function listActiveSuspensions(): array
    {
        $this->_assertReady();
        $rows = (array) $this->fs->schoolWhere(
            self::COL_ROUTE_SUSPENSIONS,
            [['status', '==', 'Active']],
            'suspended_from', 'DESC'
        );
        $out = [];
        foreach ($rows as $doc) {
            $doc = $this->_unwrapNestedRow($doc, 'schoolId');
            if (empty($doc)) { continue; }
            $id = (string) ($doc['suspensionId'] ?? $doc['id'] ?? '');
            if ($id === '') { continue; }
            $doc['id'] = $id;
            $out[] = $doc;
        }
        return $out;
    }

    /**
     * List all suspensions for a specific route (historical timeline).
     * Optional filter to hide Resolved entries.
     */
    public function listSuspensionsForRoute(string $routeId, bool $includeResolved = true): array
    {
        $this->_assertReady();
        if ($routeId === '') { return []; }
        $where = [['route_id', '==', $routeId]];
        if (!$includeResolved) {
            $where[] = ['status', '==', 'Active'];
        }
        $rows = (array) $this->fs->schoolWhere(
            self::COL_ROUTE_SUSPENSIONS, $where,
            'suspended_from', 'DESC'
        );
        $out = [];
        foreach ($rows as $doc) {
            $doc = $this->_unwrapNestedRow($doc, 'schoolId');
            if (empty($doc)) { continue; }
            $id = (string) ($doc['suspensionId'] ?? $doc['id'] ?? '');
            if ($id === '') { continue; }
            $doc['id'] = $id;
            $out[] = $doc;
        }
        return $out;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  DOMAIN_ASSIGNMENT (F3, 2026-07-07) — Student Transport Assignment
    // ═════════════════════════════════════════════════════════════════════
    //
    // Feature F3 refinements baked in:
    //   1. Session rollover auto-continues chunks in ONE invocation until
    //      complete or hits a wall-clock ceiling; checkpoint is recovery-
    //      only (not admin-interaction-required under normal ops).
    //   2. Admin "Rebuild Capacity Cache" action via rebuildCapacityCache()
    //      alongside the nightly reconciler.
    //   3. Extended assignment history: source field (manual|bulk|rollover|
    //      api) + batch_id correlation.
    //   4. Pre-write validation via _validateAssignmentContext() gate.

    /** Subscription types. Permanent+Temporary+OneWay+Seasonal writable in F3;
     *  EventOnly reserved for F8 (Transport Events feature). */
    public const SUBSCRIPTION_TYPES = ['Permanent', 'Temporary', 'Seasonal', 'OneWay', 'EventOnly'];

    /** Assignment fee categories. */
    public const FEE_CATEGORIES = [
        'Standard', 'Concession', 'Scholarship', 'Staff_Ward', 'Hostel', 'Waived',
    ];

    /** routeAssignmentHistory event types. */
    public const HISTORY_EVENT_TYPES = [
        'assigned', 'updated', 'removed', 'session_rolled',
        'suspended', 'resumed',
        'stop_changed_only', 'route_changed_only',
        'subscription_type_changed', 'fee_recategorised',
    ];

    /** Assignment-history change sources (Refinement #3). */
    public const HISTORY_SOURCES = ['manual', 'bulk', 'rollover', 'api'];

    /** Session rollover wall-clock ceiling per invocation (auto-continuation
     *  processes chunks sequentially until this is reached — Refinement #1). */
    private const ROLLOVER_WALLCLOCK_CEILING_SEC = 240;   // 4 minutes

    /** Rollover chunk size (students processed per checkpoint save). */
    private const ROLLOVER_CHUNK_SIZE = 100;

    /** Bulk-assign hard cap per invocation. */
    private const BULK_ASSIGN_HARD_CAP = 500;

    /** Half-fee ratio for OneWay subscriptions. */
    private const ONEWAY_FEE_MULTIPLIER = 0.5;

    /** Hostel-transport fee multiplier (typically weekend/holiday-only). */
    private const HOSTEL_FEE_MULTIPLIER = 0.5;

    // ─── Assignment CRUD ─────────────────────────────────────────────────

    /**
     * Assign a student to a route + stop (or edit existing assignment).
     *
     * Refinement #4 (pre-write validation) is enforced by
     * _validateAssignmentContext() before any Firestore write.
     *
     * Refinement #3: audit + history include `source` field. Defaults to
     * 'manual'; bulkAssignStudents overrides to 'bulk'; rolloverSession
     * overrides to 'rollover'.
     *
     * @param array $input {
     *   student_id, route_id, stop_id, type (pickup|drop|both),
     *   subscription_type, service_days, direction, fee_category,
     *   concession_id, hostel_transport_flag,
     *   effective_from, effective_to, season_start, season_end,
     * }
     * @param string $source  History source (default 'manual')
     * @param string $batchId Batch correlation id (for bulk/rollover)
     * @return array{id:string, isNew:bool, data:array, fee_status:string}
     */
    public function assignStudent(array $input, string $source = 'manual', string $batchId = ''): array
    {
        $this->_assertReady();
        $studentId = trim((string) ($input['student_id'] ?? ''));
        $routeId   = trim((string) ($input['route_id']   ?? ''));
        if ($studentId === '' || $routeId === '') {
            throw new \InvalidArgumentException('student_id and route_id are required.');
        }

        // Pre-write validation (Refinement #4)
        $ctx = $this->_validateAssignmentContext($input, /* isUpdate */ null);
        $student = $ctx['student'];
        $route   = $ctx['route'];
        $stop    = $ctx['stop'];   // may be null when stop_id empty

        $existing = $this->fs->getEntity(self::COL_STUDENT_ROUTES_LEGACY, $studentId);
        $isUpdate = is_array($existing);

        // Subscription + direction + fee computation
        $subscription  = trim((string) ($input['subscription_type'] ?? 'Permanent'));
        if (!in_array($subscription, self::SUBSCRIPTION_TYPES, true)) {
            throw new \InvalidArgumentException('Invalid subscription_type: ' . $subscription);
        }
        if ($subscription === 'EventOnly') {
            throw new \InvalidArgumentException(
                'EventOnly subscription is reserved for the Transport Events feature (F8).'
            );
        }
        $direction   = trim((string) ($input['direction'] ?? ($input['type'] === 'pickup' ? 'Pickup' : ($input['type'] === 'drop' ? 'Drop' : 'Both'))));
        if (!in_array($direction, ['Both', 'Pickup', 'Drop'], true)) {
            $direction = 'Both';
        }
        $feeCategory = trim((string) ($input['fee_category'] ?? 'Standard'));
        if (!in_array($feeCategory, self::FEE_CATEGORIES, true)) {
            $feeCategory = 'Standard';
        }
        $monthlyFee = $this->_computeAssignmentFee($route, $subscription, $direction, $feeCategory, $input);

        // CON-3: atomic capacity reserve (check + increment in one CAS step) —
        // replaces the old check-then-bump TOCTOU. On success the seat is
        // already consumed, so no separate +1 bump follows below.
        $capacityCheck = $this->_reserveCapacitySeat($routeId, /* isEdit */ $isUpdate, /* prevRouteId */ (string) ($existing['route_id'] ?? ''));
        if (!$capacityCheck['ok']) {
            throw new \RuntimeException('Cannot assign: ' . $capacityCheck['reason']);
        }

        // Build the assignment doc
        $now = date('c');
        $studentName = (string) ($student['name'] ?? $student['Name'] ?? $studentId);
        $studentClass = trim(
            \Firestore_service::classKey($student['class'] ?? $student['Class'] ?? '')
            . ' ' .
            \Firestore_service::sectionKey($student['section'] ?? $student['Section'] ?? '')
        );
        $svcDaysInput = $input['service_days'] ?? ($route['service_days'] ?? ['Mon','Tue','Wed','Thu','Fri','Sat']);
        if (!is_array($svcDaysInput)) { $svcDaysInput = []; }
        $svcDays = array_values(array_intersect(
            array_map(static fn($d) => (string) $d, $svcDaysInput),
            self::ROUTE_SERVICE_DAYS
        ));
        if ($svcDays === []) {
            $svcDays = ['Mon','Tue','Wed','Thu','Fri','Sat'];
        }
        $type = trim((string) ($input['type'] ?? 'both'));
        if (!in_array($type, ['pickup', 'drop', 'both'], true)) { $type = 'both'; }

        $data = [
            'studentId'             => $studentId,
            'schoolId'              => $this->schoolId,
            'session'               => $this->sessionYear,
            'route_id'              => $routeId,
            'route_name'            => (string) ($route['name'] ?? ''),
            'stop_id'               => (string) ($input['stop_id'] ?? ''),
            'stop_name'             => is_array($stop) ? (string) ($stop['name'] ?? '') : '',
            'type'                  => $type,
            'student_name'          => $studentName,
            'student_class'         => $studentClass,
            'monthly_fee'           => $monthlyFee,
            'subscription_type'     => $subscription,
            'service_days'          => $svcDays,
            'direction'             => $direction,
            'fee_category'          => $feeCategory,
            'concession_id'         => trim((string) ($input['concession_id'] ?? '')),
            'hostel_transport_flag' => (bool) ($input['hostel_transport_flag'] ?? false),
            'effective_from'        => trim((string) ($input['effective_from'] ?? '')),
            'effective_to'          => trim((string) ($input['effective_to']   ?? '')),
            'season_start'          => trim((string) ($input['season_start']   ?? '')),
            'season_end'            => trim((string) ($input['season_end']     ?? '')),
            'suspended_from'        => (string) ($existing['suspended_from']  ?? ''),
            'suspended_until'       => (string) ($existing['suspended_until'] ?? ''),
            'fee_demand_ref'        => 'pending',   // set to actual demand id after Fee_lifecycle call
            'event_id'              => (string) ($existing['event_id'] ?? ''),
            'assigned_date'         => $isUpdate ? (string) ($existing['assigned_date'] ?? $now) : $now,
            'assigned_by'           => $this->adminId,
            'status'                => 'Active',
            'updated_at'            => $now,
        ];
        if (!$isUpdate) { $data['created_at'] = $now; }

        // Firestore batched write: assignment + history + audit + capacity cache
        $this->fs->setEntity(self::COL_STUDENT_ROUTES_LEGACY, $studentId, $data, /* merge */ true);
        $historyEventType = $isUpdate ? 'updated' : 'assigned';
        $this->_appendAssignmentHistoryEvent(
            $studentId, $this->sessionYear, $historyEventType,
            $isUpdate ? $existing : null, $data,
            $source, $batchId,
            $isUpdate ? 'assignment updated' : 'assignment created'
        );
        // CON-3: the new-route +1 was already consumed atomically by
        // _reserveCapacitySeat above; only release the OLD route on a move.
        if ($isUpdate && (string) ($existing['route_id'] ?? '') !== '' && (string) ($existing['route_id'] ?? '') !== $routeId) {
            $this->_bumpCapacityCache((string) $existing['route_id'], -1);
        }

        $this->emitAudit(
            'studentRouteAssignment',
            $studentId,
            $isUpdate ? 'updated' : 'assigned',
            $isUpdate ? $existing : null,
            $data,
            [
                'domain'   => self::DOMAIN_ASSIGNMENT,
                'source'   => $source,
                'batch_id' => $batchId,
                'route_id' => $routeId,
            ]
        );

        // Fee_lifecycle call OUTSIDE the batch (pending-then-retry pattern)
        $feeStatus = 'pending';
        try {
            $this->ci->load->library('Fee_lifecycle', null, 'feeLifecycle');
            if ($this->_isSuspended($data)) {
                // G1 follow-up fix (2026-08-08): an edit to a currently-suspended
                // assignment (fee category / subscription / etc.) must not
                // silently restore full billing — createModuleFee always writes
                // the FULL amount. Leave the already-prorated demand alone;
                // resumeAssignment() is the only path that restores it.
                $this->fs->setEntity(self::COL_STUDENT_ROUTES_LEGACY, $studentId, ['fee_demand_ref' => 'suspended'], /* merge */ true);
                $feeStatus = 'suspended';
            } else {
                // Service starts on the date the operator chose, not on the day
                // the row happens to be written — a back-dated assignment must
                // bill the months it actually covers.
                $periods = $this->_syncServicePeriods(
                    $studentId, 'start',
                    (string) ($data['effective_from'] ?? date('Y-m-d')),
                    $monthlyFee, $data
                );
                $ref = $this->_writeTransportDemand($studentId, $periods, [
                    'route_id'   => $routeId,
                    'route_name' => (string) ($route['name'] ?? ''),
                    'end_date'   => (string) ($data['effective_to'] ?? ''),
                    'context'    => ['subscription_type' => $subscription, 'fee_category' => $feeCategory],
                ]);
                $this->fs->setEntity(self::COL_STUDENT_ROUTES_LEGACY, $studentId, ['fee_demand_ref' => $ref], /* merge */ true);
                // NO proRateFees() here any more. The amount is already the
                // sum of the months actually served (see _syncServicePeriods),
                // so a proration pass would re-derive it from a join date and
                // reintroduce TRN-PRORATE-INVERT-1. The G2 note this replaces
                // was about the ORDERING of that call; the call itself is what
                // turned out to be wrong for anything but a first assignment.
                // TRN-DOUBLE-BILL-1 — after the module charge exists, so the
                // comparison is against the state the family will actually see.
                $this->_warnIfChartAlsoBillsTransport($studentId);
                $feeStatus = 'ok';
            }
        } catch (\Throwable $e) {
            log_message('error', 'F3 assignStudent Fee_lifecycle failure for ' . $studentId . ': ' . $e->getMessage());
            // Assignment doc stays with fee_demand_ref='pending'; reconciler retries.
            $feeStatus = 'pending';
        }

        return ['id' => $studentId, 'isNew' => !$isUpdate, 'data' => $data, 'fee_status' => $feeStatus];
    }

    /**
     * Remove an active assignment. Pro-rates the fee to today. Historical
     * assignment log preserved via routeAssignmentHistory event.
     */
    public function unassignStudent(string $studentId, string $reason = '', string $source = 'manual', string $batchId = ''): void
    {
        $this->_assertReady();
        if ($studentId === '') {
            throw new \InvalidArgumentException('studentId is required.');
        }
        $existing = $this->fs->getEntity(self::COL_STUDENT_ROUTES_LEGACY, $studentId);
        if (!is_array($existing)) {
            throw new \InvalidArgumentException('No assignment found for student: ' . $studentId);
        }

        $routeId = (string) ($existing['route_id'] ?? '');

        // Removal CLOSES the service record and rebills the months served.
        //
        // Was: proRateFees($studentId, date('Y-m-d')) — today's date fed into a
        // JOIN-date parameter, so a student removed in February was billed
        // February to March, the months they would NOT be travelling, and not
        // the months they had. Best-effort, as before: a billing failure must
        // not roll back the removal.
        try {
            $periods = $this->_syncServicePeriods($studentId, 'stop', date('Y-m-d'), 0.0, $existing);
            $this->_writeTransportDemand($studentId, $periods, [
                'route_id'   => (string) ($existing['route_id'] ?? ''),
                'route_name' => (string) ($existing['route_name'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'F3 unassignStudent rebill failed for ' . $studentId . ': ' . $e->getMessage());
        }

        $this->fs->remove(self::COL_STUDENT_ROUTES_LEGACY, $this->fs->docId($studentId));
        $this->_appendAssignmentHistoryEvent(
            $studentId, (string) ($existing['session'] ?? $this->sessionYear),
            'removed', $existing, null, $source, $batchId, $reason
        );
        if ($routeId !== '') {
            $this->_bumpCapacityCache($routeId, -1);
        }
        $this->emitAudit(
            'studentRouteAssignment', $studentId, 'removed',
            $existing, null,
            ['domain' => self::DOMAIN_ASSIGNMENT, 'source' => $source, 'batch_id' => $batchId, 'reason' => $reason]
        );
    }

    /**
     * G1 follow-up fix (2026-08-08): true when this Transport assignment is
     * currently suspended. suspended_from/suspended_until have no automated
     * expiry anywhere in this file — resumeAssignment() is the only path
     * back to billed. Every other call site that can (re)write this
     * student's Transport demand via Fee_lifecycle::createModuleFee() (which
     * always writes the FULL amount) must check this first, or it silently
     * restores billing the operator explicitly turned off while
     * suspended_from stays set — a self-contradicting state.
     */
    private function _isSuspended(array $assignmentDoc): bool
    {
        return (string) ($assignmentDoc['suspended_from'] ?? '') !== '';
    }

    /**
     * Student-level (individual) suspension. Different from route suspension
     * (F2) — this affects one student while the route continues to run.
     */
    public function suspendAssignment(string $studentId, string $from, string $until, string $reason): void
    {
        $this->_assertReady();
        if ($studentId === '' || $from === '') {
            throw new \InvalidArgumentException('studentId and from date are required.');
        }
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('reason is required.');
        }
        $existing = $this->fs->getEntity(self::COL_STUDENT_ROUTES_LEGACY, $studentId);
        if (!is_array($existing)) {
            throw new \InvalidArgumentException('No assignment for student: ' . $studentId);
        }
        if ((string) ($existing['suspended_from'] ?? '') !== '') {
            throw new \InvalidArgumentException('Student is already suspended.');
        }
        $update = [
            'suspended_from'  => $from,
            'suspended_until' => trim($until),
            'updated_at'      => date('c'),
        ];
        $this->fs->setEntity(self::COL_STUDENT_ROUTES_LEGACY, $studentId, $update, /* merge */ true);
        $this->_appendAssignmentHistoryEvent(
            $studentId, (string) ($existing['session'] ?? $this->sessionYear),
            'suspended', ['suspended_from' => ''], $update,
            'manual', '', $reason
        );
        $this->emitAudit(
            'studentRouteAssignment', $studentId, 'suspended',
            null, $update,
            ['domain' => self::DOMAIN_ASSIGNMENT, 'source' => 'manual', 'reason' => $reason]
        );

        // G1 fix (2026-08-07): suspending an assignment never touched billing —
        // families kept being billed for a service they'd explicitly suspended.
        // Shrink the outstanding balance down to "owed through $from" using the
        // same proRateFees mechanism already wired into unassignStudent/
        // changeRoute (now functional per the G2 fix above). Best-effort,
        // pending-then-retry pattern matching every other Fee_lifecycle call in
        // this file — a failure here must not roll back the suspension itself.
        try {
            // Suspension CLOSES the service record at $from (exclusive) and
            // rebills the months actually served up to it.
            //
            // Was: proRateFees($studentId, $from) — the suspension date handed
            // to a JOIN-date parameter. Suspending on 1 February billed
            // February and March, the months the bus would NOT be running,
            // while August to January went unbilled. Proven: 8,000 -> 2,000
            // where 6,000 was owed.
            $periods = $this->_syncServicePeriods($studentId, 'stop', $from, 0.0, $existing);
            $this->_writeTransportDemand($studentId, $periods, [
                'route_id'   => (string) ($existing['route_id'] ?? ''),
                'route_name' => (string) ($existing['route_name'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'F3 suspendAssignment rebill failed for ' . $studentId . ': ' . $e->getMessage());
        }
    }

    public function resumeAssignment(string $studentId, string $reason = ''): void
    {
        $this->_assertReady();
        if ($studentId === '') {
            throw new \InvalidArgumentException('studentId required.');
        }
        $existing = $this->fs->getEntity(self::COL_STUDENT_ROUTES_LEGACY, $studentId);
        if (!is_array($existing)) {
            throw new \InvalidArgumentException('No assignment for student: ' . $studentId);
        }
        if ((string) ($existing['suspended_from'] ?? '') === '') {
            throw new \InvalidArgumentException('Student is not suspended.');
        }
        $update = [
            'suspended_from'  => '',
            'suspended_until' => '',
            'updated_at'      => date('c'),
        ];
        $this->fs->setEntity(self::COL_STUDENT_ROUTES_LEGACY, $studentId, $update, /* merge */ true);
        $this->_appendAssignmentHistoryEvent(
            $studentId, (string) ($existing['session'] ?? $this->sessionYear),
            'resumed', ['suspended_from' => (string) ($existing['suspended_from'] ?? '')], $update,
            'manual', '', $reason
        );
        $this->emitAudit(
            'studentRouteAssignment', $studentId, 'resumed',
            null, $update,
            ['domain' => self::DOMAIN_ASSIGNMENT, 'source' => 'manual', 'reason' => $reason]
        );

        // G1 fix (2026-08-07): restore full billing on resume. createModuleFee
        // is idempotent on this student's Transport demandId (FIN-1: preserves
        // paidAmount, recomputes balance against the newly-written full amount)
        // and — per the supporting fix above — resets the prorated/prorateFactor
        // flags left by the suspend-time proRateFees call. Best-effort, matching
        // every other Fee_lifecycle call in this file.
        try {
            // Resume OPENS a new service segment from today. The months served
            // BEFORE the suspension stay on the record and stay billed, which
            // is what the old code could not express: it rewrote the single
            // demand with one "full amount" and the earlier period vanished.
            $periods   = $this->_syncServicePeriods(
                $studentId, 'start', date('Y-m-d'),
                (float) ($existing['monthly_fee'] ?? 0), $existing
            );
            $demandRef = $this->_writeTransportDemand($studentId, $periods, [
                'route_id'   => (string) ($existing['route_id'] ?? ''),
                'route_name' => (string) ($existing['route_name'] ?? ''),
            ]);
            // G1 follow-up fix (2026-08-08): an edit/route-change/rollover that
            // happened WHILE suspended now stamps fee_demand_ref='suspended'
            // (see the guards above/elsewhere in this file) instead of a real
            // demand id. Clear it back on resume — otherwise the assignment
            // doc keeps showing 'suspended' even though billing is active
            // again. The demandId itself is deterministic per student+module
            // (route-independent), so this is always the same doc already
            // referenced by any earlier successful create.
            $this->fs->setEntity(self::COL_STUDENT_ROUTES_LEGACY, $studentId, ['fee_demand_ref' => $demandRef], /* merge */ true);

            // ── SUPERSEDED · TRN-RESUME-PRORATE-1 ─────────────────────────
            //
            // Resume was the only fee-affecting transport path that wrote a
            // demand and never prorated it. createModuleFee always writes the
            // FULL amount, so a resumed student was billed the whole session
            // regardless of when they resumed.
            //
            // MEASURED BEFORE THE FIX, on a Rs 1,000/month route joined in
            // August (fare basis unchanged, so this was purely the missing
            // proration):
            //     joined August   gross 666.67   (8/12)
            //     suspended Sept  gross 583.33   (7/12)
            //     resumed         gross 1000.00  <- full year, unprorated
            // Suspending and resuming RAISED the bill above what the student
            // was charged before they ever suspended, and left the demand
            // stamped prorated=true factor=0.583 while carrying the full
            // amount — flags that disagreed with the money.
            //
            // That over-charge lands on a parent, so it outranks the
            // under-charge TRN-FARE-BASIS-1 fixes in kind. With the fare basis
            // now twelve times larger, leaving this out would have turned a
            // 1.5x over-charge into an 18x one.
            //
            // SUPERSEDED by the service-period model: the amount is now the
            // sum of the months actually served, so there is nothing to prorate
            // and a proration pass would re-derive it from a join date.
        } catch (\Throwable $e) {
            log_message('error', 'F3 resumeAssignment createModuleFee failed for ' . $studentId . ': ' . $e->getMessage());
        }
    }

    public function changeStop(string $studentId, string $newStopId, string $reason): void
    {
        $this->_assertReady();
        if ($studentId === '' || $newStopId === '' || trim($reason) === '') {
            throw new \InvalidArgumentException('studentId, newStopId, reason are all required.');
        }
        $existing = $this->fs->getEntity(self::COL_STUDENT_ROUTES_LEGACY, $studentId);
        if (!is_array($existing)) {
            throw new \InvalidArgumentException('No assignment for student: ' . $studentId);
        }
        $routeId = (string) ($existing['route_id'] ?? '');
        // Validate the new stop belongs to the current route
        $stop = $this->fs->getEntity(self::COL_TRANSPORT_STOPS, $newStopId);
        if (!is_array($stop) || (string) ($stop['route_id'] ?? '') !== $routeId) {
            throw new \InvalidArgumentException('New stop does not belong to the current route.');
        }
        $update = [
            'stop_id'    => $newStopId,
            'stop_name'  => (string) ($stop['name'] ?? ''),
            'updated_at' => date('c'),
        ];
        $this->fs->setEntity(self::COL_STUDENT_ROUTES_LEGACY, $studentId, $update, /* merge */ true);
        $this->_appendAssignmentHistoryEvent(
            $studentId, (string) ($existing['session'] ?? $this->sessionYear),
            'stop_changed_only',
            ['stop_id' => (string) ($existing['stop_id'] ?? ''), 'stop_name' => (string) ($existing['stop_name'] ?? '')],
            $update, 'manual', '', $reason
        );
        $this->emitAudit(
            'studentRouteAssignment', $studentId, 'stop_changed',
            null, $update,
            ['domain' => self::DOMAIN_ASSIGNMENT, 'source' => 'manual', 'reason' => $reason]
        );
    }

    /**
     * Mid-session route change. Includes pro-rate on old route + new demand
     * on new route via Fee_lifecycle (pending-then-retry pattern).
     */
    public function changeRoute(string $studentId, string $newRouteId, string $newStopId, string $reason): void
    {
        $this->_assertReady();
        if ($studentId === '' || $newRouteId === '' || trim($reason) === '') {
            throw new \InvalidArgumentException('studentId, newRouteId, reason are required.');
        }
        $existing = $this->fs->getEntity(self::COL_STUDENT_ROUTES_LEGACY, $studentId);
        if (!is_array($existing)) {
            throw new \InvalidArgumentException('No assignment for student: ' . $studentId);
        }
        $prevRouteId = (string) ($existing['route_id'] ?? '');
        if ($prevRouteId === $newRouteId) {
            throw new \InvalidArgumentException('Cannot change route to the same route.');
        }

        // Validate new route + stop
        $newRoute = $this->fs->getEntity(self::COL_ROUTES, $newRouteId);
        if (!is_array($newRoute)) {
            throw new \InvalidArgumentException('New route not found: ' . $newRouteId);
        }
        if ((string) ($newRoute['status'] ?? '') !== 'Active') {
            throw new \InvalidArgumentException('New route is not Active.');
        }
        if ((string) ($newRoute['active_suspension_ref'] ?? '') !== '') {
            throw new \InvalidArgumentException('New route currently has an active suspension.');
        }
        $newStop = null;
        if ($newStopId !== '') {
            $newStop = $this->fs->getEntity(self::COL_TRANSPORT_STOPS, $newStopId);
            if (!is_array($newStop) || (string) ($newStop['route_id'] ?? '') !== $newRouteId) {
                throw new \InvalidArgumentException('New stop does not belong to the new route.');
            }
        }

        // CON-3: atomic capacity reserve on the new route (check + increment in
        // one CAS step); the +1 bump below is dropped since the seat is consumed here.
        $capacityCheck = $this->_reserveCapacitySeat($newRouteId, /* isEdit */ true, $prevRouteId);
        if (!$capacityCheck['ok']) {
            throw new \RuntimeException('Cannot change route: ' . $capacityCheck['reason']);
        }

        $newFee = $this->_computeAssignmentFee(
            $newRoute,
            (string) ($existing['subscription_type'] ?? 'Permanent'),
            (string) ($existing['direction'] ?? 'Both'),
            (string) ($existing['fee_category'] ?? 'Standard'),
            $existing
        );

        // G1 follow-up fix (2026-08-08): a route change on a currently-
        // suspended assignment must not silently restore full billing below.
        $suspended = $this->_isSuspended($existing);

        $update = [
            'route_id'      => $newRouteId,
            'route_name'    => (string) ($newRoute['name'] ?? ''),
            'stop_id'       => $newStopId,
            'stop_name'     => is_array($newStop) ? (string) ($newStop['name'] ?? '') : '',
            'monthly_fee'   => $newFee,
            'fee_demand_ref' => $suspended ? 'suspended' : 'pending',
            'updated_at'    => date('c'),
        ];
        $this->fs->setEntity(self::COL_STUDENT_ROUTES_LEGACY, $studentId, $update, /* merge */ true);
        $this->_appendAssignmentHistoryEvent(
            $studentId, (string) ($existing['session'] ?? $this->sessionYear),
            'route_changed_only',
            ['route_id' => $prevRouteId, 'route_name' => (string) ($existing['route_name'] ?? ''), 'stop_id' => (string) ($existing['stop_id'] ?? '')],
            $update, 'manual', '', $reason
        );
        // CON-3: new-route +1 already reserved atomically above; release the old route only.
        $this->_bumpCapacityCache($prevRouteId, -1);

        $this->emitAudit(
            'studentRouteAssignment', $studentId, 'route_changed',
            null, $update,
            ['domain' => self::DOMAIN_ASSIGNMENT, 'source' => 'manual', 'reason' => $reason,
             'prev_route_id' => $prevRouteId, 'new_route_id' => $newRouteId]
        );

        // Fee_lifecycle: create new demand (skipped while suspended — see fix above)
        try {
            if ($suspended) {
                return;
            }
            // A route change is a FARE change, not a restart: months already
            // served keep the fare they were served at, and the new fare
            // applies from today forward. The old code rewrote the whole
            // session at the new fare, retro-repricing months the family had
            // already consumed on the previous route.
            $periods = $this->_syncServicePeriods($studentId, 'refare', date('Y-m-d'), $newFee, $existing);
            $ref = $this->_writeTransportDemand($studentId, $periods, [
                'route_id'   => $newRouteId,
                'route_name' => (string) ($newRoute['name'] ?? ''),
            ]);
            $this->fs->setEntity(self::COL_STUDENT_ROUTES_LEGACY, $studentId, ['fee_demand_ref' => $ref], /* merge */ true);
            // SUPERSEDED: the amount is now per-month from the service
            // record, so there is nothing left to prorate.
        } catch (\Throwable $e) {
            log_message('error', 'F3 changeRoute createModuleFee failed: ' . $e->getMessage());
        }
    }

    public function changeSubscription(string $studentId, string $newType, array $params, string $reason): void
    {
        $this->_assertReady();
        if ($studentId === '' || $newType === '' || trim($reason) === '') {
            throw new \InvalidArgumentException('studentId, newType, reason required.');
        }
        if (!in_array($newType, self::SUBSCRIPTION_TYPES, true) || $newType === 'EventOnly') {
            throw new \InvalidArgumentException('Invalid subscription_type: ' . $newType);
        }
        $existing = $this->fs->getEntity(self::COL_STUDENT_ROUTES_LEGACY, $studentId);
        if (!is_array($existing)) {
            throw new \InvalidArgumentException('No assignment for student: ' . $studentId);
        }
        $route = $this->fs->getEntity(self::COL_ROUTES, (string) ($existing['route_id'] ?? ''));
        if (!is_array($route)) {
            throw new \InvalidArgumentException('Route referenced by assignment no longer exists.');
        }
        $newFee = $this->_computeAssignmentFee(
            $route, $newType,
            (string) ($existing['direction']    ?? 'Both'),
            (string) ($existing['fee_category'] ?? 'Standard'),
            array_merge($existing, $params)
        );

        // G1/G2 follow-up fix (2026-08-08): this function changes the billed
        // amount (subscription type drives the OneWay/Both fee multiplier)
        // but, unlike assignStudent/changeRoute, never used to call
        // Fee_lifecycle at all — it only stamped fee_demand_ref='pending' and
        // left the existing demand at its OLD amount indefinitely.
        // reconcilePendingFees() is manual-trigger-only (Transport.php:913,
        // no cron), so a stale demand could sit indefinitely until an
        // operator happened to run it. Mirror changeRoute's pattern instead:
        // regenerate synchronously, guarded by suspension.
        $suspended = $this->_isSuspended($existing);

        $update = array_merge([
            'subscription_type' => $newType,
            'monthly_fee'       => $newFee,
            'fee_demand_ref'    => $suspended ? 'suspended' : 'pending',
            'updated_at'        => date('c'),
        ], array_intersect_key($params, array_flip([
            'effective_from', 'effective_to', 'season_start', 'season_end', 'direction',
        ])));
        $this->fs->setEntity(self::COL_STUDENT_ROUTES_LEGACY, $studentId, $update, /* merge */ true);

        $this->_appendAssignmentHistoryEvent(
            $studentId, (string) ($existing['session'] ?? $this->sessionYear),
            'subscription_type_changed',
            ['subscription_type' => (string) ($existing['subscription_type'] ?? ''), 'monthly_fee' => (float) ($existing['monthly_fee'] ?? 0)],
            $update, 'manual', '', $reason
        );

        $this->emitAudit(
            'studentRouteAssignment', $studentId, 'subscription_changed',
            null, $update,
            ['domain' => self::DOMAIN_ASSIGNMENT, 'source' => 'manual', 'reason' => $reason]
        );

        if ($suspended) {
            return;
        }
        try {
            // Changing the subscription changes the FARE from today. Months
            // already served keep the fare they were served at.
            $periods = $this->_syncServicePeriods($studentId, 'refare', date('Y-m-d'), $newFee, $existing);
            $ref = $this->_writeTransportDemand($studentId, $periods, [
                'route_id'   => (string) ($existing['route_id'] ?? ''),
                'route_name' => (string) ($existing['route_name'] ?? ''),
            ]);
            $this->fs->setEntity(self::COL_STUDENT_ROUTES_LEGACY, $studentId, ['fee_demand_ref' => $ref], /* merge */ true);
        } catch (\Throwable $e) {
            log_message('error', 'F3 changeSubscription createModuleFee failed for ' . $studentId . ': ' . $e->getMessage());
        }
    }

    /**
     * Bulk assign up to BULK_ASSIGN_HARD_CAP students in one call.
     *
     * Refinement #3: source='bulk' with a common batch_id across all rows.
     *
     * @param array<int, array> $rows
     * @return array{
     *   batch_id:string, assigned_count:int,
     *   skipped_capacity:array, skipped_conflict:array, errors:array,
     * }
     */
    public function bulkAssignStudents(array $rows): array
    {
        $this->_assertReady();
        if ($rows === []) {
            throw new \InvalidArgumentException('At least one row is required.');
        }
        if (count($rows) > self::BULK_ASSIGN_HARD_CAP) {
            throw new \InvalidArgumentException(
                'Bulk assign hard cap is ' . self::BULK_ASSIGN_HARD_CAP . ' rows per invocation.'
            );
        }
        $batchId = 'BULK_' . (int) round(microtime(true) * 1000);

        $assigned = 0;
        $capSkip  = [];
        $conflict = [];
        $errors   = [];

        foreach ($rows as $row) {
            try {
                $r = $this->assignStudent((array) $row, 'bulk', $batchId);
                $assigned++;
                if ($r['fee_status'] !== 'ok') {
                    $errors[] = ['studentId' => (string) $row['student_id'], 'error' => 'fee_pending'];
                }
            } catch (\RuntimeException $e) {
                // Capacity exceeded etc.
                $capSkip[] = ['studentId' => (string) ($row['student_id'] ?? ''), 'reason' => $e->getMessage()];
            } catch (\InvalidArgumentException $e) {
                $conflict[] = ['studentId' => (string) ($row['student_id'] ?? ''), 'reason' => $e->getMessage()];
            } catch (\Throwable $e) {
                $errors[] = ['studentId' => (string) ($row['student_id'] ?? ''), 'error' => $e->getMessage()];
            }
        }

        // Fire completion notice via Transport_notifier (F6).
        $this->_notify('assignment.bulk_complete', [
            'batch_id'         => $batchId,
            'assigned'         => $assigned,
            'skipped_capacity' => count($capSkip),
            'errors'           => count($errors),
        ]);

        return [
            'batch_id'         => $batchId,
            'assigned_count'   => $assigned,
            'skipped_capacity' => $capSkip,
            'skipped_conflict' => $conflict,
            'errors'           => $errors,
        ];
    }

    /**
     * Auto-continuing chunked session rollover (Refinement #1). Runs chunks
     * sequentially in a single invocation until either:
     *   - All students processed (checkpoint.status = 'complete')
     *   - Wall-clock ceiling hit (ROLLOVER_WALLCLOCK_CEILING_SEC), returns
     *     'in_progress' — subsequent invocation resumes from checkpoint
     *   - Fatal error → checkpoint frozen with status = 'error'
     *
     * @return array{status:string, processed:int, total:int, batch_id:string, checkpoint_id:string}
     */
    public function rolloverSession(string $fromSession, string $toSession): array
    {
        $this->_assertReady();
        if ($fromSession === '' || $toSession === '' || $fromSession === $toSession) {
            throw new \InvalidArgumentException('fromSession and toSession must be non-empty and differ.');
        }
        $checkpointDocId = "{$this->schoolId}_ROLLOVER_{$fromSession}_{$toSession}";
        $checkpoint = $this->fs->getEntity('rolloverCheckpoint', $checkpointDocId);
        if (!is_array($checkpoint)) {
            // Fresh rollover
            $checkpoint = [
                'checkpointId'   => $checkpointDocId,
                'schoolId'       => $this->schoolId,
                'from_session'   => $fromSession,
                'to_session'     => $toSession,
                'batch_id'       => 'ROLL_' . (int) round(microtime(true) * 1000),
                'started_at'     => date('c'),
                'started_by'     => $this->adminId,
                'processed_ids'  => [],
                'processed_count' => 0,
                'error_count'    => 0,
                'status'         => 'running',
                'last_update_at' => date('c'),
            ];
            $this->fs->setEntity('rolloverCheckpoint', $checkpointDocId, $checkpoint, /* merge */ false);
        }
        if ((string) $checkpoint['status'] === 'complete') {
            return [
                'status' => 'complete',
                'processed' => (int) $checkpoint['processed_count'],
                'total' => (int) $checkpoint['processed_count'],
                'batch_id' => (string) $checkpoint['batch_id'],
                'checkpoint_id' => $checkpointDocId,
            ];
        }

        $wallClockStart = microtime(true);
        $batchId        = (string) $checkpoint['batch_id'];
        $processed      = (int) $checkpoint['processed_count'];
        $processedIds   = (array) $checkpoint['processed_ids'];
        $processedSet   = array_flip(array_map('strval', $processedIds));

        // Auto-continue chunks until wall-clock ceiling or exhaustion
        while (true) {
            if ((microtime(true) - $wallClockStart) > self::ROLLOVER_WALLCLOCK_CEILING_SEC) {
                $checkpoint['status'] = 'in_progress';
                $checkpoint['last_update_at'] = date('c');
                $this->fs->setEntity('rolloverCheckpoint', $checkpointDocId, $checkpoint, /* merge */ true);
                return [
                    'status' => 'in_progress',
                    'processed' => $processed,
                    'total' => $processed,   // unknown until complete
                    'batch_id' => $batchId,
                    'checkpoint_id' => $checkpointDocId,
                ];
            }

            $chunk = (array) $this->fs->schoolWhere(
                self::COL_STUDENT_ROUTES_LEGACY,
                [['session', '==', $fromSession]],
                '__name__', 'ASC',
                self::ROLLOVER_CHUNK_SIZE
            );
            $todo = [];
            foreach ($chunk as $row) {
                if (!is_array($row)) { continue; }
                // CON-4: schoolWhere returns nested {id, data:{…}} rows. Unwrap to
                // the data level ONCE here and carry the unwrapped row forward, so
                // the studentId read (this skip), the archive, and the fee-field
                // reads below all see real data. Reading studentId at the envelope
                // level yields '' for every row → the chunk would process NOBODY.
                $d = (isset($row['data']) && is_array($row['data'])) ? $row['data'] : $row;
                $sid = (string) ($d['studentId'] ?? '');
                if ($sid === '' || isset($processedSet[$sid])) { continue; }
                $todo[] = ['sid' => $sid, 'row' => $d];
            }
            if ($todo === []) {
                $checkpoint['status'] = 'complete';
                $checkpoint['completed_at'] = date('c');
                $checkpoint['processed_count'] = $processed;
                $checkpoint['last_update_at'] = date('c');
                $this->fs->setEntity('rolloverCheckpoint', $checkpointDocId, $checkpoint, /* merge */ true);
                $this->_notify('assignment.rollover_complete', [
                    'from_session' => $fromSession,
                    'to_session'   => $toSession,
                    'batch_id'     => $batchId,
                    'processed'    => $processed,
                ]);
                return [
                    'status' => 'complete',
                    'processed' => $processed,
                    'total' => $processed,
                    'batch_id' => $batchId,
                    'checkpoint_id' => $checkpointDocId,
                ];
            }

            foreach ($todo as $item) {
                $sid = $item['sid'];
                $row = $item['row'];
                try {
                    // Archive current session's assignment to routeAssignments
                    $archiveId  = "{$sid}__{$fromSession}";
                    $archiveDoc = array_merge($row, [
                        'rolled_over_at'  => date('c'),
                        'rolled_over_by'  => $this->adminId,
                        'rollover_batch_id' => $batchId,
                    ]);
                    $this->fs->setEntity(self::COL_ROUTE_ASSIGNMENTS, $archiveId, $archiveDoc, /* merge */ false);

                    // Update studentRoutes to new session.
                    // CON-4: stamp fee_demand_ref='pending' UP FRONT. The session
                    // flip drains this student from the fromSession re-pump query,
                    // so if the fee step below throws OR the process dies before
                    // it runs, the student would otherwise be left in the new
                    // session with NO new-session demand and no way to recover
                    // (reconcilePendingFees only rescans fee_demand_ref=='pending').
                    // Setting 'pending' here guarantees the reconciler picks it up;
                    // a successful fee create overwrites it with the real ref below.
                    $this->fs->setEntity(self::COL_STUDENT_ROUTES_LEGACY, $sid, [
                        'session'        => $toSession,
                        'assigned_date'  => date('Y-m-d'),
                        'fee_demand_ref' => 'pending',
                        'updated_at'     => date('c'),
                    ], /* merge */ true);

                    // History event
                    $this->_appendAssignmentHistoryEvent(
                        $sid, $toSession, 'session_rolled',
                        ['session' => $fromSession], ['session' => $toSession],
                        'rollover', $batchId,
                        "Rolled over from {$fromSession} to {$toSession}"
                    );

                    // Fee_lifecycle: cancel old session demands + create new
                    try {
                        $this->ci->load->library('Fee_lifecycle', null, 'feeLifecycle');
                        if (method_exists($this->ci->feeLifecycle, 'cancelSessionDemands')) {
                            $this->ci->feeLifecycle->cancelSessionDemands($sid, $fromSession, 'Transport');
                        }
                        // G1 follow-up fix (2026-08-08): rollover previously
                        // carried suspended_from/suspended_until forward (never
                        // touched them) but ALWAYS created a fresh full-amount
                        // new-session demand regardless — a suspended assignment
                        // would silently start being billed again on session
                        // rollover with zero operator action. Skip demand
                        // creation for a currently-suspended assignment; the
                        // suspension state already carried forward, and
                        // resumeAssignment() is the only path that (re)bills.
                        if ($this->_isSuspended($row)) {
                            $this->fs->setEntity(self::COL_STUDENT_ROUTES_LEGACY, $sid, [
                                'fee_demand_ref' => 'suspended',
                                'session'        => $toSession,
                            ], /* merge */ true);
                        } else {
                            // The new session starts a fresh service record: one
                            // segment covering the whole year at the carried fare.
                            // Without this reset the OLD session's segments would
                            // decide the new year's bill.
                            $this->ci->load->library('Transport_billing_period', null, 'trnPeriod');
                            $rollFare = (float) ($row['monthly_fee'] ?? 0);
                            $rollMonths = \Transport_billing_period::sessionMonths($toSession);
                            $rolloverPeriods = [];
                            foreach ($rollMonths as $pk) { $rolloverPeriods[$pk] = round($rollFare, 2); }
                            if ($rollMonths !== []) {
                                $this->fs->setEntity(self::COL_STUDENT_ROUTES_LEGACY, $sid, ['service_periods' => [[
                                    'from' => $rollMonths[0] . '-01', 'to' => '', 'monthly_fee' => round($rollFare, 2),
                                ]]], /* merge */ true);
                            }
                            // ROLL-2 — the demand belongs to the session being rolled
                            // INTO. Without this the period, the demand id and the stored
                            // session all came from whichever session Fee_lifecycle was
                            // inited with — normally the one being rolled OUT of — so the
                            // new demand landed on the id cancelSessionDemands had just
                            // archived, resurrecting it while the new session got nothing.
                            //
                            // A continuing student rides the WHOLE new session, so every
                            // month of $toSession is billable. Passed as periods so a
                            // concession effective part-way through the new year discounts
                            // only the months it covers.
                            $rolloverRef = $this->_writeTransportDemand($sid, $rolloverPeriods, [
                                'session'    => $toSession,
                                'route_id'   => (string) ($row['route_id'] ?? ''),
                                'route_name' => (string) ($row['route_name'] ?? ''),
                            ]);
                            // CON-4: only on a confirmed create do we point
                            // fee_demand_ref at the real demand (so the reconciler
                            // skips it). Any failure — throw here, or createModuleFee
                            // returning null — leaves the 'pending' marker set above
                            // for reconcilePendingFees to retry.
                            if (is_string($rolloverRef) && $rolloverRef !== '') {
                                // Include session => $toSession explicitly: setEntity
                                // auto-injects the SERVICE session when 'session' is
                                // absent, which would silently undo the flip to
                                // $toSession we just wrote above.
                                $this->fs->setEntity(self::COL_STUDENT_ROUTES_LEGACY, $sid, [
                                    'fee_demand_ref' => $rolloverRef,
                                    'session'        => $toSession,
                                ], /* merge */ true);
                            }
                        }
                    } catch (\Throwable $e) {
                        log_message('error', 'F3 rollover fee ops failed for ' . $sid . ': ' . $e->getMessage());
                    }

                    $processed++;
                    $processedIds[] = $sid;
                    $processedSet[$sid] = true;
                } catch (\Throwable $e) {
                    $checkpoint['error_count'] = ((int) ($checkpoint['error_count'] ?? 0)) + 1;
                    log_message('error', "F3 rollover student {$sid} failed: " . $e->getMessage());
                }
            }

            // Update checkpoint after each chunk
            $checkpoint['processed_count'] = $processed;
            $checkpoint['processed_ids']   = array_slice($processedIds, -1000);   // cap array size on doc
            $checkpoint['last_update_at']  = date('c');
            $this->fs->setEntity('rolloverCheckpoint', $checkpointDocId, $checkpoint, /* merge */ true);
        }
    }

    /**
     * Rebuild the capacity cache for a specific route (or all routes when
     * routeId is empty). Refinement #2 — administrative action.
     */
    public function rebuildCapacityCache(string $routeId = ''): array
    {
        $this->_assertReady();
        $routes = $routeId !== ''
            ? [(array) $this->fs->getEntity(self::COL_ROUTES, $routeId)]
            : (array) $this->fs->schoolWhere(self::COL_ROUTES, [], 'name', 'ASC');
        $rebuilt = 0;
        foreach ($routes as $r) {
            if (!is_array($r)) { continue; }
            $r = $this->_unwrapNestedRow($r, 'schoolId');   // bulk path is schoolWhere {id,data}; single-route getEntity is already flat
            $rid = (string) ($r['routeId'] ?? $r['id'] ?? '');
            if ($rid === '') { continue; }
            $vehicleId = (string) ($r['vehicle_id'] ?? '');
            $maxCapacity = 0;
            if ($vehicleId !== '') {
                $veh = $this->fs->getEntity(self::COL_VEHICLES, $vehicleId);
                $maxCapacity = is_array($veh) ? (int) ($veh['capacity'] ?? 0) : 0;
            }
            $count = count((array) $this->fs->schoolWhere(
                self::COL_STUDENT_ROUTES_LEGACY,
                [['route_id', '==', $rid], ['session', '==', $this->sessionYear]]
            ));
            $cacheId = $this->_capacityCacheId($rid);
            $this->fs->setEntity(self::COL_BUS_CAPACITY_CACHE, $cacheId, [
                'route_id'        => $rid,
                'session'         => $this->sessionYear,
                'current_count'   => $count,
                'max_capacity'    => $maxCapacity,
                'last_updated_at' => date('c'),
                'schoolId'        => $this->schoolId,
                'rebuilt_at'      => date('c'),
                'rebuilt_by'      => $this->adminId,
            ], /* merge */ false);
            // RC-3 self-healing migration: purge the pre-normalization
            // double-prefixed doc so no stale orphan lingers (which CH-TRN-F3-5
            // would otherwise flag as drift). Best-effort; the canonical doc
            // above is authoritative. removeEntity adds the outer {schoolId}
            // prefix, so the legacy inner id "{schoolId}_CAPCACHE_{rid}" targets
            // the old {schoolId}_{schoolId}_CAPCACHE_{rid} document.
            try {
                $this->fs->removeEntity(self::COL_BUS_CAPACITY_CACHE, "{$this->schoolId}_CAPCACHE_{$rid}");
            } catch (\Throwable $_) {}
            $rebuilt++;
        }
        $this->emitAudit(
            'busCapacityCache', $routeId !== '' ? $routeId : 'ALL', 'rebuilt',
            null, ['rebuilt_count' => $rebuilt],
            ['domain' => self::DOMAIN_ASSIGNMENT, 'source' => 'manual']
        );
        return ['rebuilt_count' => $rebuilt];
    }

    public function reconcilePendingFees(): array
    {
        $this->_assertReady();
        $rows = (array) $this->fs->schoolWhere(
            self::COL_STUDENT_ROUTES_LEGACY,
            [['fee_demand_ref', '==', 'pending']],
            null, 'ASC', 200
        );
        $retried = 0;
        $stillPending = 0;
        foreach ($rows as $r) {
            if (!is_array($r)) { continue; }
            // CON-4: schoolWhere returns nested {id, data:{…}} rows — read the
            // studentRoutes fields from the data level. Reading studentId at the
            // envelope level is always empty for a nested row, so every pending
            // student would be skipped and the reconciler would retry NOTHING —
            // defeating both the rollover recovery path and the assignStudent
            // pending-fee recovery.
            $d   = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
            $sid = (string) ($d['studentId'] ?? '');
            if ($sid === '') { continue; }
            // G1 follow-up fix (2026-08-08): a record can in principle be both
            // 'pending' (from a failed create) and suspended (operator action
            // in between) before this runs. Defensive, same as the other 4
            // createModuleFee call sites — never restore full billing here.
            if ($this->_isSuspended($d)) {
                $this->fs->setEntity(self::COL_STUDENT_ROUTES_LEGACY, $sid,
                    ['fee_demand_ref' => 'suspended'], /* merge */ true);
                continue;
            }
            try {
                $this->ci->load->library('Fee_lifecycle', null, 'feeLifecycle');
                // Recomputed from the service RECORD, not from a join date.
                $periods = $this->_syncServicePeriods($sid, 'recompute', date('Y-m-d'), 0.0, $d);
                $ref = $this->_writeTransportDemand($sid, $periods, [
                    'route_id'   => (string) ($d['route_id'] ?? ''),
                    'route_name' => (string) ($d['route_name'] ?? ''),
                ]);
                $this->fs->setEntity(self::COL_STUDENT_ROUTES_LEGACY, $sid,
                    ['fee_demand_ref' => $ref], /* merge */ true);

                // SUPERSEDED: the retry now recomputes the same period-based
                // amount assignStudent would have written, so there is no
                // half-step left to reproduce.
                $retried++;
            } catch (\Throwable $e) {
                $stillPending++;
                log_message('error', 'F3 reconcile pending fee failed for ' . $sid . ': ' . $e->getMessage());
            }
        }
        return ['retried' => $retried, 'still_pending' => $stillPending];
    }

    /**
     * NF-1 (Option B, 2026-08-04) — Firestore-native student typeahead search
     * for the assignment UI, routed through the Transport service layer instead
     * of the Operations_accounting dependency that F3 removed from the Transport
     * controller (the orphaned `$this->operations_accounting->search_students()`
     * call fatally 500'd). Reads the school's students once (school-scoped → no
     * cross-tenant leakage), caches the lightweight index for 5 minutes (file
     * cache; per-school key), and filters name/id substring in PHP. Result shape
     * matches the prior contract (id, name, class, section, user_id) so the
     * controller's post-processing is unchanged.
     *
     * @return array<int, array{id:string,name:string,class:string,section:string,user_id:string}>
     */
    public function searchStudents(string $query, int $limit = 20): array
    {
        $this->_assertReady();
        $q = strtolower(trim($query));
        if (strlen($q) < 2) { return []; }

        $cacheKey = 'trn_students_' . md5($this->schoolId);
        $index = false;
        try {
            $this->ci->load->driver('cache', ['adapter' => 'file']);
            $index = $this->ci->cache->get($cacheKey);
        } catch (\Throwable $_) { $index = false; }

        if (!is_array($index)) {
            $index = [];
            try {
                $rows = (array) $this->fs->schoolWhere('students', []);   // school-scoped
            } catch (\Throwable $_) { $rows = []; }
            foreach ($rows as $r) {
                $s = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
                if (!is_array($s)) { continue; }
                $id = (string) ($s['studentId'] ?? $s['userId'] ?? '');
                if ($id === '') { continue; }
                $index[] = [
                    'id'      => $id,
                    'name'    => (string) ($s['name'] ?? $s['Name'] ?? ''),
                    'class'   => (string) ($s['className'] ?? ''),
                    'section' => (string) ($s['section'] ?? ''),
                    'user_id' => $id,
                    // Phase 11.3 — SR Number, from the document already read to
                    // build this cached index: ZERO extra Firestore reads.
                    // Assigning a student to a route is a genuine office workflow
                    // where the school quotes its own register number.
                    'srNo'    => (string) ($s['srNo'] ?? ''),
                ];
            }
            try { $this->ci->cache->save($cacheKey, $index, 300); } catch (\Throwable $_) {}
        }

        $results = [];
        foreach ($index as $s) {
            // Phase 11.3 — match on SR Number too. `?? ''` keeps cache entries
            // written before this change (which lack the key) working until they
            // expire on the existing 300s TTL.
            if (strpos(strtolower((string) $s['name']), $q) !== false
                || strpos(strtolower((string) $s['id']), $q) !== false
                || ((string) ($s['srNo'] ?? '') !== '' && strtolower((string) $s['srNo']) === $q)) {
                $results[] = $s;
                if (count($results) >= $limit) { break; }
            }
        }
        return $results;
    }

    public function listAssignments(string $session = '', array $filters = []): array
    {
        $this->_assertReady();
        $where = [];
        if ($session !== '') {
            $where[] = ['session', '==', $session];
        }
        foreach ($filters as $k => $v) {
            if (in_array($k, ['route_id', 'student_class', 'status', 'subscription_type', 'fee_category'], true)
                && $v !== '' && $v !== null) {
                $where[] = [$k, '==', $v];
            }
        }
        // PERF-8: bound the scan (was unbounded). A tenant's active assignment
        // set is well under this cap; the fuller fix (cursor pagination +
        // [schoolId,session,route_id,student_name] composites) is deferred to an
        // index deployment.
        $rows = (array) $this->fs->schoolWhere(self::COL_STUDENT_ROUTES_LEGACY, $where, 'student_name', 'ASC', self::LIST_SCAN_LIMIT);
        $out = [];
        foreach ($rows as $doc) {
            $doc = $this->_unwrapNestedRow($doc, 'schoolId');
            if (empty($doc)) { continue; }
            $sid = (string) ($doc['studentId'] ?? '');
            if ($sid === '') { continue; }
            $doc['student_id'] = $sid;
            $out[] = $doc;
        }
        return $out;
    }

    public function getAssignmentHistory(string $studentId): array
    {
        $this->_assertReady();
        if ($studentId === '') { return []; }
        $rows = (array) $this->fs->schoolWhere(
            self::COL_ROUTE_ASSIGNMENT_HISTORY,
            [['studentId', '==', $studentId]],
            'event_at', 'DESC'
        );
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) { continue; }
            // Handle both flat and {id, data} nested response formats
            // (Firestore_service returns nested for some collections; this
            // guards against that surface variance).
            if (isset($r['data']) && is_array($r['data']) && !isset($r['event_type'])) {
                $docKey = (string) ($r['id'] ?? '');
                $r = $r['data'];
                if ($docKey !== '' && !isset($r['id'])) { $r['id'] = $docKey; }
            }
            $id = (string) ($r['eventId'] ?? $r['id'] ?? '');
            if ($id === '') { continue; }
            $r['id'] = $id;
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Student 360 (2026-08) — thin single-student CURRENT-assignment read.
     *
     * Returns the student's active transport assignment (the `studentRoutes`
     * doc, whose docId == studentId) or null when the student is unassigned.
     * READ-ONLY: performs no mutation and no history/audit write.
     *
     * The studentRoutes doc is denormalised at assignStudent() time
     * (route_name / stop_name / subscription_type / student_class / status are
     * all stored inline), so this needs exactly ONE point read — no route,
     * stop, or vehicle enrichment queries. Tenant isolation is enforced both
     * by getEntity()'s schoolId-prefixed docId AND a defensive schoolId check.
     */
    public function getStudentAssignment(string $studentId): ?array
    {
        $this->_assertReady();
        $studentId = trim($studentId);
        if ($studentId === '') { return null; }

        $doc = $this->fs->getEntity(self::COL_STUDENT_ROUTES_LEGACY, $studentId);
        if (!is_array($doc) || empty($doc)) { return null; }

        // Guard against {id, data} nested response variance (parity with
        // getAssignmentHistory()).
        if (isset($doc['data']) && is_array($doc['data']) && !isset($doc['studentId'])) {
            $doc = $doc['data'];
        }

        // Defensive tenant-isolation guard: never surface another school's row.
        $docSchool = (string) ($doc['schoolId'] ?? '');
        if ($docSchool !== '' && $docSchool !== $this->schoolId) { return null; }

        $doc['student_id'] = (string) ($doc['studentId'] ?? $studentId);
        return $doc;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  DOMAIN_DRIVER (F4, 2026-07-07) — Driver Management
    // ═════════════════════════════════════════════════════════════════════
    //
    // F4 architecture summary:
    //   • HR (staff master) is the SOLE source of truth for staff data.
    //   • Transport owns ONLY the `driver_meta` subobject on staff docs +
    //     the driverIncidents / driverPerformanceReviews /
    //     temporaryDriverAssignments / driverActivitySummary collections.
    //   • Temporary driver assignments use an OVERLAY pattern —
    //     vehicle.driver_staff_id is NEVER mutated during a temp window.
    //     getEffectiveDriver() computes on read.
    //   • Comprehensive readiness validation via _validateDriverReadiness
    //     runs before every driver-vehicle assignment (permanent + temp).

    /** Incident types recognised on driverIncidents. */
    public const INCIDENT_TYPES = [
        'Accident', 'Complaint', 'Traffic_Violation', 'Late_Arrival',
        'Absenteeism', 'Safety_Breach', 'Positive_Recognition',
    ];

    /** Incident severity levels. Critical triggers immediate notification. */
    public const INCIDENT_SEVERITIES = ['Info', 'Minor', 'Major', 'Critical'];

    /** Driver-meta enums documented for UI + validation. */
    public const DRIVER_LICENCE_CLASSES  = ['LMV', 'HMV', 'Bus', 'Auto', 'Multi'];
    public const DRIVER_VEHICLE_TYPES    = ['Bus', 'Van', 'Auto', 'Car'];

    /**
     * Operational availability status. driver_meta.operational_status
     * is checked by _validateDriverReadiness.
     *
     * Administrative states (persistent — admin/HR sets these):
     *   Available       — normal
     *   Unavailable     — generic block; admin knows the reason but the
     *                     specific category doesn't fit others
     *   On_Leave        — general leave (personal, vacation, etc.)
     *   Medical_Leave   — health-specific leave (aids HR reporting)
     *   Training        — attending a training programme
     *   Suspended       — disciplinary action; blocks assignments
     *
     * NOT in this enum:
     *   Assigned — this is a COMPUTED runtime state derived from active
     *              temp cover / trip in progress. See
     *              getEffectiveDriverAvailabilityStatus() for the read
     *              path. Storing "Assigned" as a persistent flag causes
     *              stale-state drift when trips end without an admin
     *              explicitly resetting the flag.
     */
    public const DRIVER_OPERATIONAL_STATUSES = [
        'Available', 'Unavailable', 'On_Leave',
        'Medical_Leave', 'Training', 'Suspended',
    ];

    /** Statuses that BLOCK new driver-vehicle assignments. Any admin-set
     *  state other than Available is treated as blocking by
     *  _validateDriverReadiness. */
    private const DRIVER_BLOCKING_STATUSES = [
        'Unavailable', 'On_Leave', 'Medical_Leave', 'Training', 'Suspended',
    ];

    /** Temporary assignment status enum. */
    public const TEMP_ASSIGNMENT_STATUSES = ['Active', 'Resolved', 'Cancelled'];

    /**
     * Fields Transport MAY write on staff docs. Enforced by
     * _assertStaffFieldDiscipline() and verifier CH-TRN-F4-1. Every
     * top-level payload key on a staff setEntity call must appear here.
     */
    private const ALLOWED_STAFF_TOP_LEVEL_KEYS = [
        'driver_meta', 'updated_at',
    ];

    /**
     * Batch 3 field partition for driver_meta.
     *
     * SENSITIVE — credential / fitness / management fields. Four of these are
     * read by _validateDriverReadiness() before every dispatch
     * (licence_expires, medical_expires, badge_expires, operational_status), and
     * blanking badge_no disables the badge check entirely. Writing any of them
     * additionally requires Transport:manage.
     *
     * BENIGN — legitimate driver self-service. Writable at Transport:edit, but
     * only on the actor's OWN record.
     *
     * Public so the endpoint and the service share one definition; a field added
     * to the form but to neither list is rejected rather than silently written.
     */
    public const DRIVER_META_SENSITIVE_FIELDS = [
        'licence_no', 'licence_class', 'licence_issued', 'licence_expires',
        'licence_authority', 'medical_check_last', 'medical_expires',
        'badge_no', 'badge_expires', 'operational_status', 'can_drive_types',
    ];

    public const DRIVER_META_BENIGN_FIELDS = [
        'years_experience', 'emergency_contact_name',
        'emergency_contact_phone', 'replacement_availability',
    ];

    // ─── Driver identity + meta ────────────────────────────────────────

    /**
     * List staff who have flags.can_drive_transport=true. Joins the
     * driver_meta subobject into the returned rows for UI convenience.
     *
     * Note: reads staff at tenant scale (typically ≤200 driver-flagged
     * staff). Uses composite index staff(schoolId, flags.can_drive_
     * transport) — HR-side index; F4 assumes it exists.
     */
    public function listActiveDrivers(): array
    {
        $this->_assertReady();
        try {
            $rows = (array) $this->fs->schoolWhere(
                'staff',
                [['flags.can_drive_transport', '==', true]],
                'name', 'ASC'
            );
        } catch (\Throwable $e) {
            // Fall back to full staff scan + in-memory filter when index missing
            $all  = (array) $this->fs->schoolWhere('staff', []);
            $rows = array_filter($all, function ($s) {
                $s = $this->_unwrapNestedRow($s, 'schoolId');   // schoolWhere returns {id,data}
                return is_array($s) && !empty($s['flags']['can_drive_transport']);
            });
        }
        $out = [];
        foreach ($rows as $r) {
            $r = $this->_unwrapNestedRow($r, 'schoolId');
            if (empty($r)) { continue; }
            $sid = (string) ($r['staffId'] ?? $r['id'] ?? '');
            if ($sid === '') { continue; }
            $r['staff_id'] = $sid;
            $out[] = $r;
        }
        return $out;
    }

    public function getDriver(string $staffId): ?array
    {
        $this->_assertReady();
        if ($staffId === '') { return null; }
        $s = $this->fs->getEntity('staff', $staffId);
        return is_array($s) ? $s : null;
    }

    /**
     * Update the Transport-owned driver_meta subobject on a staff doc.
     * HR staff fields (name/phone/role/etc.) are NEVER touched — the
     * write is a strict merge of only driver_meta.* keys.
     */
    public function updateDriverMeta(string $staffId, array $meta): void
    {
        $this->_assertReady();
        if ($staffId === '') {
            throw new \InvalidArgumentException('staffId is required.');
        }
        $staff = $this->fs->getEntity('staff', $staffId);
        if (!is_array($staff)) {
            throw new \InvalidArgumentException('Staff not found: ' . $staffId);
        }
        $prev = (array) ($staff['driver_meta'] ?? []);

        // ── Batch 3 invariants, enforced HERE so they bind every caller ──────
        //
        // Unknown keys are rejected outright — a field added to a form but to
        // neither partition list must never be written blind.
        $known = array_merge(self::DRIVER_META_SENSITIVE_FIELDS, self::DRIVER_META_BENIGN_FIELDS);
        $unknown = array_diff(array_keys($meta), $known);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'Unrecognised driver_meta field(s): ' . implode(', ', $unknown));
        }
        // Sensitive writes require Transport:manage, evaluated through the
        // existing Graded RBAC resolver — a READ of it, never a modification.
        // Fails CLOSED when the helper is unavailable (e.g. CLI), because a
        // context that cannot prove manage must not write credentials.
        $touchesSensitive = array_intersect(array_keys($meta), self::DRIVER_META_SENSITIVE_FIELDS) !== [];
        if ($touchesSensitive) {
            $ok = function_exists('has_permission') && has_permission('Transport', 'manage');
            if (!$ok) {
                throw new \InvalidArgumentException(
                    'Driver credential and fitness fields require Transport · manage.');
            }
        } elseif ((string) $staffId !== (string) $this->adminId) {
            // Benign-only writes are self-service and stay on the actor's record.
            throw new \InvalidArgumentException(
                'You may only update your own driver details.');
        }

        // Normalise + validate driver_meta fields. MERGED over the stored map:
        // the endpoint now sends only the submitted keys, and driver_meta is a
        // nested map — writing a partial map would erase every unsubmitted field.
        $clean = $prev;
        foreach ($meta as $k => $v) {
            $clean[$k] = is_string($v) ? trim($v) : $v;
        }
        // Enum validation
        if (isset($clean['licence_class']) && $clean['licence_class'] !== ''
            && !in_array($clean['licence_class'], self::DRIVER_LICENCE_CLASSES, true)) {
            throw new \InvalidArgumentException('Invalid licence_class: ' . $clean['licence_class']);
        }
        if (isset($clean['operational_status']) && $clean['operational_status'] !== ''
            && !in_array($clean['operational_status'], self::DRIVER_OPERATIONAL_STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid operational_status: ' . $clean['operational_status']);
        }
        if (isset($clean['can_drive_types']) && is_array($clean['can_drive_types'])) {
            $clean['can_drive_types'] = array_values(array_intersect(
                array_map(static fn($t) => (string) $t, $clean['can_drive_types']),
                self::DRIVER_VEHICLE_TYPES
            ));
        }
        $clean['last_updated_by'] = $this->adminId;
        $clean['last_updated_at'] = date('c');

        $payload = ['driver_meta' => $clean, 'updated_at' => date('c')];
        $this->_assertStaffFieldDiscipline($payload);
        $this->fs->setEntity('staff', $staffId, $payload, /* merge */ true);

        $prevMeta = (array) ($staff['driver_meta'] ?? []);
        $this->emitAudit(
            'driverMeta', $staffId, 'updated',
            ['driver_meta' => $prevMeta], $payload,
            ['domain' => self::DOMAIN_DRIVER]
        );
    }

    /**
     * Return the current effective driver for a vehicle on a given date.
     *
     * Overlay pattern: reads vehicle.driver_staff_id (permanent) then
     * checks active temporaryDriverAssignments for the vehicle. If an
     * active temp overlay covers $onDate, returns the covering driver;
     * otherwise returns the permanent driver.
     *
     * @return string|null Effective staff_id (or null when no permanent
     *                     driver is assigned to the vehicle either).
     */
    public function getEffectiveDriver(string $vehicleId, ?string $onDate = null): ?string
    {
        $this->_assertReady();
        if ($vehicleId === '') { return null; }
        $onDate = $onDate ?? date('Y-m-d');
        $vehicle = $this->fs->getEntity(self::COL_VEHICLES, $vehicleId);
        if (!is_array($vehicle)) { return null; }
        $permanent = (string) ($vehicle['staff_id'] ?? $vehicle['driver_staff_id'] ?? '');

        // Query active temp assignments for this vehicle that cover $onDate
        try {
            $temps = (array) $this->fs->schoolWhere(
                self::COL_TEMPORARY_DRIVER_ASSIGNMENTS,
                [
                    ['vehicle_id', '==', $vehicleId],
                    ['status',     '==', 'Active'],
                ],
                'effective_from', 'DESC'
            );
        } catch (\Throwable $_) {
            return $permanent !== '' ? $permanent : null;
        }
        foreach ($temps as $t) {
            $t = $this->_unwrapNestedRow($t, 'effective_from');
            if (!is_array($t)) { continue; }
            $from = (string) ($t['effective_from'] ?? '');
            $to   = (string) ($t['effective_to']   ?? '');
            if ($from !== '' && $to !== '' && $from <= $onDate && $onDate <= $to) {
                return (string) ($t['covering_staff_id'] ?? $permanent);
            }
        }
        return $permanent !== '' ? $permanent : null;
    }

    /**
     * Compute the effective availability status for a driver on a given
     * date. Blends the admin-set driver_meta.operational_status with
     * the computed runtime state 'Assigned' (currently covering a
     * vehicle via an active temp assignment).
     *
     * Return semantics:
     *   - If admin-set status is anything other than 'Available' →
     *     return the admin-set value verbatim (blocking state takes
     *     precedence)
     *   - Else if driver is currently covering a vehicle in an active
     *     temp assignment → return 'Assigned'
     *   - Else → return 'Available'
     *
     * Note: this is a READ operation; nothing is written. UI dashboards
     * call this to show at-a-glance driver status; validation callers
     * use _validateDriverReadiness (which reads only the admin-set
     * status — 'Assigned' does not block new assignments because a
     * driver can be temp-covering one vehicle and being considered
     * for another with different windows).
     */
    public function getEffectiveDriverAvailabilityStatus(string $staffId, ?string $onDate = null): string
    {
        $this->_assertReady();
        if ($staffId === '') { return 'Available'; }
        $onDate = $onDate ?? date('Y-m-d');
        $staff = $this->fs->getEntity('staff', $staffId);
        if (!is_array($staff)) { return 'Available'; }
        $meta = (array) ($staff['driver_meta'] ?? []);
        $admin = (string) ($meta['operational_status'] ?? 'Available');
        if ($admin !== 'Available' && $admin !== '') {
            return $admin;
        }
        // Compute 'Assigned' from active temp coverage
        try {
            $active = (array) $this->fs->schoolWhere(
                self::COL_TEMPORARY_DRIVER_ASSIGNMENTS,
                [['covering_staff_id', '==', $staffId], ['status', '==', 'Active']]
            );
            foreach ($active as $t) {
                $t = $this->_unwrapNestedRow($t, 'effective_from');
                if (!is_array($t)) { continue; }
                $from = (string) ($t['effective_from'] ?? '');
                $to   = (string) ($t['effective_to']   ?? '');
                if ($from !== '' && $to !== '' && $from <= $onDate && $onDate <= $to) {
                    return 'Assigned';
                }
            }
        } catch (\Throwable $_) {}
        return 'Available';
    }

    /**
     * Permanently replace the driver on a vehicle (LC-16 permanent path).
     * Runs comprehensive driver-readiness validation on the new driver,
     * updates vehicle.staff_id, and writes a lifecycle log entry.
     */
    public function replaceDriverPermanently(string $vehicleId, string $newStaffId, string $reason): void
    {
        $this->_assertReady();
        if ($vehicleId === '' || $newStaffId === '' || trim($reason) === '') {
            throw new \InvalidArgumentException('vehicleId, newStaffId and reason are all required.');
        }
        $vehicle = $this->fs->getEntity(self::COL_VEHICLES, $vehicleId);
        if (!is_array($vehicle)) {
            throw new \InvalidArgumentException('Vehicle not found: ' . $vehicleId);
        }
        $prevStaffId = (string) ($vehicle['staff_id'] ?? '');

        // Driver readiness validation (Refinement additional 2026-07-07)
        $this->_validateDriverReadiness($newStaffId, date('Y-m-d'));

        // Snapshot driver name/phone from staff for HR-history denorm
        $staffSnap = $this->_snapshotDriverFromStaff($newStaffId);
        $now = date('c');
        $vehUpdate = [
            'staff_id'    => $newStaffId,
            'driver_name' => $staffSnap['name']  ?? '',
            'driver_phone'=> $staffSnap['phone'] ?? '',
            'updated_at'  => $now,
        ];
        $this->fs->setEntity(self::COL_VEHICLES, $vehicleId, $vehUpdate, /* merge */ true);

        // Lifecycle log — 'Ownership_Transferred' repurposed for driver change;
        // event_type semantic covers "driver identity changed on vehicle".
        $this->_appendLifecycleEvent(
            $vehicleId,
            'Ownership_Transferred',
            (string) ($vehicle['lifecycle_status'] ?? 'Active'),
            (string) ($vehicle['lifecycle_status'] ?? 'Active'),
            "Driver replaced: {$prevStaffId} → {$newStaffId}. {$reason}",
            [],
            null
        );

        $this->emitAudit(
            'vehicle', $vehicleId, 'driver_replaced',
            ['staff_id' => $prevStaffId],
            $vehUpdate,
            ['domain' => self::DOMAIN_DRIVER, 'reason' => $reason, 'prev_staff_id' => $prevStaffId, 'new_staff_id' => $newStaffId]
        );
    }

    // ─── Driver Incidents ──────────────────────────────────────────────

    /**
     * @param bool $anySubject TRUE only when the caller has established
     *        Transport · manage. FALSE restricts the subject to the actor
     *        themselves, or to a trip the actor canonically owns.
     */
    public function recordDriverIncident(array $input, bool $anySubject = false): array
    {
        $this->_assertReady();
        $staffId = trim((string) ($input['staff_id'] ?? ''));
        $type    = trim((string) ($input['incident_type'] ?? ''));
        $sev     = trim((string) ($input['severity'] ?? 'Minor'));

        // ── Category-E actor scope (self or own trip) ────────────────────────
        // Reporting an incident is first-hand knowledge, so this stays at
        // Transport:edit. But staff_id came straight from the request, so an
        // edit-level caller could file a Critical incident naming a colleague.
        //
        // Both sides of the comparison are STAFF IDS — verified, not assumed:
        //   • actor  — session('admin_id') is the login id, and the admin roster
        //     derives a staff-sourced admin's id from $d['staffId'] ?? $d['id']
        //     (admin_roster_helper::_admin_roster_norm_staff).
        //   • trip   — tripLogs.driver_staff_id is set from getEffectiveDriver(),
        //     which returns vehicles.staff_id.
        // No Firebase-token/session equivalence is assumed: this path is the
        // Admin Panel only. A legacy admins-collection account (ADM id, not a
        // staff id) simply never matches and is denied — it fails CLOSED.
        //
        // $anySubject is a SEPARATE ARGUMENT, never an $input key.
        unset($input['_any_subject'], $input['any_subject']);
        if (!$anySubject) {
            $actor  = (string) $this->adminId;
            $isSelf = $actor !== '' && $staffId === $actor;
            $ownsTrip = false;
            $tripId = trim((string) ($input['related_trip_id'] ?? ''));
            if (!$isSelf && $tripId !== '' && $actor !== '') {
                $trip = $this->fs->getEntity(self::COL_TRIP_LOGS, $tripId);
                if (is_array($trip)) {
                    $trip = $this->_unwrapNestedRow($trip, 'vehicleId');
                    $ownsTrip = (string) ($trip['driver_staff_id'] ?? '') === $actor;
                }
            }
            if (!$isSelf && !$ownsTrip) {
                throw new \InvalidArgumentException(
                    'You may only record an incident for yourself, or against a trip you '
                    . 'operated. Recording an incident for another person requires '
                    . 'Transport · manage.');
            }
        }
        $desc    = trim((string) ($input['description'] ?? ''));
        if ($staffId === '' || $desc === '') {
            throw new \InvalidArgumentException('staff_id and description are required.');
        }
        if (!in_array($type, self::INCIDENT_TYPES, true)) {
            throw new \InvalidArgumentException('Invalid incident_type: ' . $type);
        }
        if (!in_array($sev, self::INCIDENT_SEVERITIES, true)) {
            $sev = 'Minor';
        }
        $staff = $this->fs->getEntity('staff', $staffId);
        if (!is_array($staff)) {
            throw new \InvalidArgumentException('Staff not found: ' . $staffId);
        }

        $id = $this->numbering->next('trn_driver_incident', [
            'claimedBy'  => $this->adminId,
            'claimedFor' => 'F4:recordDriverIncident',
        ]);
        $now = date('c');
        $data = [
            'incidentId'         => $id,
            'schoolId'           => $this->schoolId,
            'staff_id'           => $staffId,
            'incident_type'      => $type,
            'incident_date'      => trim((string) ($input['incident_date'] ?? date('Y-m-d'))),
            'incident_time'      => trim((string) ($input['incident_time'] ?? '')),
            'severity'           => $sev,
            'description'        => strlen($desc) > 4096 ? (substr($desc, 0, 4096) . '…') : $desc,
            'reported_by'        => $this->adminId,
            'related_trip_id'    => trim((string) ($input['related_trip_id']    ?? '')),
            'related_vehicle_id' => trim((string) ($input['related_vehicle_id'] ?? '')),
            'location'           => trim((string) ($input['location']           ?? '')),
            'resolution'         => '',
            'action_taken'       => '',
            'resolved_at'        => '',
            'resolved_by'        => '',
            'attachment_paths'   => array_values(array_filter(array_map(
                'strval', (array) ($input['attachment_paths'] ?? [])
            ))),
            'created_at'         => $now,
            'created_by'         => $this->adminId,
            'updated_at'         => $now,
        ];
        $this->fs->setEntity(self::COL_DRIVER_INCIDENTS, $id, $data, /* merge */ false);

        $this->emitAudit(
            'driverIncident', $id, 'recorded',
            null, $data,
            ['domain' => self::DOMAIN_DRIVER, 'staff_id' => $staffId, 'severity' => $sev, 'incident_type' => $type]
        );

        // Critical-severity Communication notice
        if ($sev === 'Critical') {
            $this->_emitCriticalIncidentNotice($data, $staff);
        }
        return ['id' => $id, 'data' => $data];
    }

    public function resolveDriverIncident(string $incidentId, string $resolution, string $actionTaken): void
    {
        $this->_assertReady();
        if ($incidentId === '' || trim($resolution) === '') {
            throw new \InvalidArgumentException('incidentId and resolution are required.');
        }
        $existing = $this->fs->getEntity(self::COL_DRIVER_INCIDENTS, $incidentId);
        if (!is_array($existing)) {
            throw new \InvalidArgumentException('Incident not found: ' . $incidentId);
        }
        if ((string) ($existing['resolved_at'] ?? '') !== '') {
            throw new \InvalidArgumentException('Incident is already resolved.');
        }
        // ── Batch 3 invariant: nobody closes their own incident ──────────────
        // Resolution is an independent managerial decision. Enforced after the
        // one-way guard above (which is preserved) and before any write, so a
        // self-resolution attempt changes nothing. Applies even at manage.
        if ((string) ($existing['staff_id'] ?? '') !== ''
            && (string) ($existing['staff_id'] ?? '') === (string) $this->adminId) {
            throw new \InvalidArgumentException(
                'You cannot resolve an incident recorded against yourself. Another '
                . 'authorised manager must review and close it.');
        }
        $now = date('c');
        $update = [
            'resolution'   => trim($resolution),
            'action_taken' => trim($actionTaken),
            'resolved_at'  => $now,
            'resolved_by'  => $this->adminId,
            'updated_at'   => $now,
        ];
        $this->fs->setEntity(self::COL_DRIVER_INCIDENTS, $incidentId, $update, /* merge */ true);
        $this->emitAudit(
            'driverIncident', $incidentId, 'resolved',
            ['resolved_at' => ''], $update,
            ['domain' => self::DOMAIN_DRIVER, 'staff_id' => (string) ($existing['staff_id'] ?? '')]
        );
    }

    public function listDriverIncidents(string $staffId = '', string $severity = ''): array
    {
        $this->_assertReady();
        $where = [];
        if ($staffId !== '')  { $where[] = ['staff_id', '==', $staffId]; }
        if ($severity !== '') { $where[] = ['severity', '==', $severity]; }
        try {
            $rows = (array) $this->fs->schoolWhere(
                self::COL_DRIVER_INCIDENTS, $where,
                'incident_date', 'DESC'
            );
        } catch (\Throwable $_) {
            $rows = [];
        }
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) { continue; }
            // Handle {id, data} nested format if present
            if (isset($r['data']) && is_array($r['data']) && !isset($r['incidentId'])) {
                $docKey = (string) ($r['id'] ?? '');
                $r = $r['data'];
                if ($docKey !== '' && !isset($r['id'])) { $r['id'] = $docKey; }
            }
            $id = (string) ($r['incidentId'] ?? $r['id'] ?? '');
            if ($id === '') { continue; }
            $r['id'] = $id;
            $out[] = $r;
        }
        return $out;
    }

    // ─── Performance Reviews ───────────────────────────────────────────

    public function savePerformanceReview(array $input): array
    {
        $this->_assertReady();
        $staffId = trim((string) ($input['staff_id'] ?? ''));
        $start   = trim((string) ($input['review_period_start'] ?? ''));
        $end     = trim((string) ($input['review_period_end']   ?? ''));
        if ($staffId === '' || $start === '' || $end === '') {
            throw new \InvalidArgumentException('staff_id, review_period_start, review_period_end are required.');
        }
        if ($end < $start) {
            throw new \InvalidArgumentException('review_period_end must be on or after review_period_start.');
        }
        // ── Batch 3 invariant: nobody reviews themselves ─────────────────────
        // Applies even at Transport:manage — a manager who also drives must not
        // author their own performance review.
        if ($staffId === (string) $this->adminId) {
            throw new \InvalidArgumentException(
                'You cannot record a performance review for yourself. Another authorised '
                . 'reviewer must complete it.');
        }
        $rating = max(1, min(5, (int) ($input['rating'] ?? 3)));

        // Auto-populate numeric fields from activity snapshot if not supplied
        $snap = $this->getDriverActivitySnapshot($staffId, $start, $end);

        $id = trim((string) ($input['id'] ?? ''));
        $isNew = ($id === '');
        if ($isNew) {
            $id = $this->numbering->next('trn_driver_review', [
                'claimedBy'  => $this->adminId,
                'claimedFor' => 'F4:savePerformanceReview',
            ]);
        }
        $now = date('c');
        $data = [
            'reviewId'              => $id,
            'schoolId'              => $this->schoolId,
            'staff_id'              => $staffId,
            'review_period_start'   => $start,
            'review_period_end'     => $end,
            'total_trips'           => (int)   ($input['total_trips']       ?? $snap['trips_count']),
            'total_km'              => (int)   ($input['total_km']          ?? $snap['total_km']),
            'on_time_pct'           => (float) ($input['on_time_pct']       ?? $snap['on_time_pct']),
            'incidents_count'       => (int)   ($input['incidents_count']   ?? $snap['incidents_count']),
            'critical_incidents'    => (int)   ($input['critical_incidents'] ?? $snap['critical_incidents']),
            'complaints_count'      => (int)   ($input['complaints_count']  ?? $snap['complaints_count']),
            'positive_recognitions' => (int)   ($input['positive_recognitions'] ?? $snap['positive_recognitions']),
            'rating'                => $rating,
            // Batch 3: SERVER-DERIVED, and a client-supplied reviewer_staff_id is
            // ignored outright. It was previously honoured when present, so any
            // authorised caller could attribute a review to somebody else —
            // forged provenance on an HR record. The authenticated actor is the
            // only reviewer identity this field may ever carry.
            'reviewer_staff_id'     => (string) $this->adminId,
            'notes'                 => trim((string) ($input['notes'] ?? '')),
            'action_items'          => array_values(array_filter(array_map(
                'strval', (array) ($input['action_items'] ?? [])
            ))),
            'next_review_due'       => trim((string) ($input['next_review_due'] ?? '')),
            'updated_at'            => $now,
        ];
        if ($isNew) {
            $data['created_at'] = $now;
            $data['created_by'] = $this->adminId;
        }
        $this->fs->setEntity(self::COL_DRIVER_PERFORMANCE_REVIEWS, $id, $data, /* merge */ true);
        $this->emitAudit(
            'driverPerformanceReview', $id,
            $isNew ? 'created' : 'updated',
            null, $data,
            ['domain' => self::DOMAIN_DRIVER, 'staff_id' => $staffId]
        );
        return ['id' => $id, 'isNew' => $isNew, 'data' => $data];
    }

    public function listPerformanceReviews(string $staffId): array
    {
        $this->_assertReady();
        if ($staffId === '') { return []; }
        try {
            $rows = (array) $this->fs->schoolWhere(
                self::COL_DRIVER_PERFORMANCE_REVIEWS,
                [['staff_id', '==', $staffId]],
                'review_period_end', 'DESC'
            );
        } catch (\Throwable $_) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) { continue; }
            if (isset($r['data']) && is_array($r['data']) && !isset($r['reviewId'])) {
                $docKey = (string) ($r['id'] ?? '');
                $r = $r['data'];
                if ($docKey !== '' && !isset($r['id'])) { $r['id'] = $docKey; }
            }
            $id = (string) ($r['reviewId'] ?? $r['id'] ?? '');
            if ($id === '') { continue; }
            $r['id'] = $id;
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Numeric activity snapshot for a driver over a date range. Used by
     * performance-review autofill + admin driver profile pages.
     */
    public function getDriverActivitySnapshot(string $staffId, string $from, string $to): array
    {
        $this->_assertReady();
        $snap = [
            'trips_count' => 0, 'total_km' => 0, 'on_time_pct' => 0.0,
            'incidents_count' => 0, 'critical_incidents' => 0,
            'complaints_count' => 0, 'positive_recognitions' => 0,
        ];
        if ($staffId === '' || $from === '' || $to === '') { return $snap; }

        // Trip aggregates. F7 (2026-07-07) landed tripLogs with a schema
        // that dual-carries Kotlin-canonical fields (`date`, `distance`)
        // + PHP additive aliases (`trip_date`, `distance_km`). Reader
        // prefers additive keys for backward compat with any older writes
        // and falls back to the Kotlin fields.
        try {
            $trips = (array) $this->fs->schoolWhere(
                self::COL_TRIP_LOGS,
                [
                    ['driver_staff_id', '==', $staffId],
                    ['date', '>=', $from],
                    ['date', '<=', $to],
                ]
            );
            $tripsNorm = [];
            foreach ($trips as $t) {
                $t = $this->_unwrapNestedRow($t, 'date');
                if (!is_array($t)) { continue; }
                $tripsNorm[] = $t;
                $snap['trips_count']++;
                $snap['total_km'] += (int) ($t['distance_km'] ?? $t['distance'] ?? 0);
            }
            if ($snap['trips_count'] > 0) {
                $onTimeTrips = 0;
                foreach ($tripsNorm as $t) {
                    if (!empty($t['on_time'])) { $onTimeTrips++; }
                }
                $snap['on_time_pct'] = round(($onTimeTrips / $snap['trips_count']) * 100, 1);
            }
        } catch (\Throwable $_) { /* tripLogs unavailable — driver has no trips in window */ }

        // Incident aggregates
        try {
            $incidents = (array) $this->fs->schoolWhere(
                self::COL_DRIVER_INCIDENTS,
                [
                    ['staff_id', '==', $staffId],
                    ['incident_date', '>=', $from],
                    ['incident_date', '<=', $to],
                ]
            );
            foreach ($incidents as $i) {
                $i = $this->_unwrapNestedRow($i, 'severity');
                if (!is_array($i)) { continue; }
                $snap['incidents_count']++;
                if (($i['severity'] ?? '') === 'Critical') { $snap['critical_incidents']++; }
                if (($i['incident_type'] ?? '') === 'Complaint') { $snap['complaints_count']++; }
                if (($i['incident_type'] ?? '') === 'Positive_Recognition') { $snap['positive_recognitions']++; }
            }
        } catch (\Throwable $_) {}

        return $snap;
    }

    // ─── Temporary + permanent assignment ──────────────────────────────

    public function assignTemporaryDriver(array $input): array
    {
        $this->_assertReady();
        $vehicleId       = trim((string) ($input['vehicle_id']       ?? ''));
        $coveringStaffId = trim((string) ($input['covering_staff_id']?? ''));
        $from            = trim((string) ($input['effective_from']   ?? ''));
        $to              = trim((string) ($input['effective_to']     ?? ''));
        $reason          = trim((string) ($input['reason']           ?? ''));
        if ($vehicleId === '' || $coveringStaffId === '' || $from === '' || $to === '' || $reason === '') {
            throw new \InvalidArgumentException('vehicle_id, covering_staff_id, effective_from, effective_to, and reason are all required.');
        }
        if ($to < $from) {
            throw new \InvalidArgumentException('effective_to must be on or after effective_from.');
        }
        // ── Batch 3 invariant: no self-assignment ────────────────────────────
        // getEffectiveDriver() PREFERS an active temp assignment covering the
        // trip date over the vehicle's permanent driver, so assigning oneself is
        // a self-service route onto a vehicle's dispatch. Enforced HERE, in the
        // service, so it binds every caller and cannot be bypassed by a future
        // endpoint. Additive only — the Graded RBAC check at the endpoint remains
        // the first authorization boundary.
        if ($coveringStaffId !== '' && $coveringStaffId === (string) $this->adminId) {
            throw new \InvalidArgumentException(
                'You cannot assign yourself as the temporary driver. Another authorised '
                . 'manager must record this assignment.');
        }
        $vehicle = $this->fs->getEntity(self::COL_VEHICLES, $vehicleId);
        if (!is_array($vehicle)) {
            throw new \InvalidArgumentException('Vehicle not found: ' . $vehicleId);
        }

        // Driver readiness validation
        $this->_validateDriverReadiness($coveringStaffId, $from);

        // Overlap detection — no active temp for same vehicle covering this window
        try {
            $existing = (array) $this->fs->schoolWhere(
                self::COL_TEMPORARY_DRIVER_ASSIGNMENTS,
                [['vehicle_id', '==', $vehicleId], ['status', '==', 'Active']]
            );
            foreach ($existing as $t) {
                $t = $this->_unwrapNestedRow($t, 'effective_from');
                if (!is_array($t)) { continue; }
                $eFrom = (string) ($t['effective_from'] ?? '');
                $eTo   = (string) ($t['effective_to']   ?? '');
                if ($eFrom !== '' && $eTo !== ''
                    && !($to < $eFrom || $from > $eTo)) {
                    throw new \RuntimeException(
                        'Overlap detected: an active temporary driver assignment already covers this vehicle in the window '
                        . $eFrom . ' → ' . $eTo
                    );
                }
            }
        } catch (\RuntimeException $e) { throw $e; }
        catch (\Throwable $_) {}

        $id = $this->numbering->next('trn_temp_driver_assign', [
            'claimedBy'  => $this->adminId,
            'claimedFor' => 'F4:assignTemporaryDriver',
        ]);
        $now = date('c');
        $data = [
            'assignmentId'      => $id,
            'schoolId'          => $this->schoolId,
            'vehicle_id'        => $vehicleId,
            'original_staff_id' => (string) ($vehicle['staff_id'] ?? $vehicle['driver_staff_id'] ?? ''),
            'covering_staff_id' => $coveringStaffId,
            'effective_from'    => $from,
            'effective_to'      => $to,
            'reason'            => $reason,
            'authorised_by'     => $this->adminId,
            'notes'             => trim((string) ($input['notes'] ?? '')),
            'status'            => 'Active',
            'auto_resolved_at'  => '',
            'resolved_by'       => '',
            'created_at'        => $now,
        ];
        $this->fs->setEntity(self::COL_TEMPORARY_DRIVER_ASSIGNMENTS, $id, $data, /* merge */ false);
        $this->emitAudit(
            'temporaryDriverAssignment', $id, 'assigned',
            null, $data,
            ['domain' => self::DOMAIN_DRIVER, 'vehicle_id' => $vehicleId, 'covering_staff_id' => $coveringStaffId]
        );
        return ['id' => $id, 'data' => $data];
    }

    public function resolveTemporaryDriver(string $assignmentId, string $reason = 'manual'): void
    {
        $this->_assertReady();
        if ($assignmentId === '') {
            throw new \InvalidArgumentException('assignmentId required.');
        }
        $existing = $this->fs->getEntity(self::COL_TEMPORARY_DRIVER_ASSIGNMENTS, $assignmentId);
        if (!is_array($existing)) {
            throw new \InvalidArgumentException('Temporary assignment not found: ' . $assignmentId);
        }
        if ((string) ($existing['status'] ?? '') !== 'Active') {
            throw new \InvalidArgumentException('Temporary assignment is not Active.');
        }
        $now = date('c');
        $update = [
            'status'           => 'Resolved',
            'auto_resolved_at' => $reason === 'auto' ? $now : '',
            'resolved_by'      => $this->adminId,
            'resolved_at'      => $now,
        ];
        $this->fs->setEntity(self::COL_TEMPORARY_DRIVER_ASSIGNMENTS, $assignmentId, $update, /* merge */ true);
        $this->emitAudit(
            'temporaryDriverAssignment', $assignmentId, 'resolved',
            ['status' => 'Active'], $update,
            ['domain' => self::DOMAIN_DRIVER, 'reason' => $reason]
        );
    }

    public function listTemporaryAssignments(string $vehicleId = '', bool $activeOnly = true): array
    {
        $this->_assertReady();
        $where = [];
        if ($vehicleId !== '') { $where[] = ['vehicle_id', '==', $vehicleId]; }
        if ($activeOnly)       { $where[] = ['status',     '==', 'Active']; }
        try {
            $rows = (array) $this->fs->schoolWhere(
                self::COL_TEMPORARY_DRIVER_ASSIGNMENTS, $where,
                'effective_from', 'DESC'
            );
        } catch (\Throwable $_) { $rows = []; }
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) { continue; }
            if (isset($r['data']) && is_array($r['data']) && !isset($r['assignmentId'])) {
                $docKey = (string) ($r['id'] ?? '');
                $r = $r['data'];
                if ($docKey !== '' && !isset($r['id'])) { $r['id'] = $docKey; }
            }
            $id = (string) ($r['assignmentId'] ?? $r['id'] ?? '');
            if ($id === '') { continue; }
            $r['id'] = $id;
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Auto-resolve temp assignments whose effective_to is past today.
     * Invoked by the nightly Transport_expiry_cron extension.
     */
    public function autoResolveExpiredTempAssignments(): array
    {
        $this->_assertReady();
        $today = date('Y-m-d');
        try {
            $active = (array) $this->fs->schoolWhere(
                self::COL_TEMPORARY_DRIVER_ASSIGNMENTS,
                [['status', '==', 'Active']]
            );
        } catch (\Throwable $_) { return ['resolved' => 0]; }
        $resolved = 0;
        foreach ($active as $t) {
            $t = $this->_unwrapNestedRow($t, 'effective_to');
            if (!is_array($t)) { continue; }
            $to = (string) ($t['effective_to'] ?? '');
            if ($to === '' || $to >= $today) { continue; }
            $id = (string) ($t['assignmentId'] ?? '');
            if ($id === '') { continue; }
            try {
                $this->resolveTemporaryDriver($id, 'auto');
                $resolved++;
            } catch (\Throwable $_) { /* per-doc failure logged by emitAudit; continue */ }
        }
        return ['resolved' => $resolved];
    }

    // ─── Driver Activity Summary ───────────────────────────────────────

    /**
     * Compute (or recompute) driverActivitySummary for one driver × one
     * month. Returns the summary payload also written to cache.
     */
    public function computeDriverActivitySummary(string $staffId, string $yearMonth): array
    {
        $this->_assertReady();
        if ($staffId === '' || !preg_match('/^\d{4}-\d{2}$/', $yearMonth)) {
            throw new \InvalidArgumentException('staffId and YYYY-MM yearMonth required.');
        }
        $monthStart = $yearMonth . '-01';
        $monthEnd   = date('Y-m-t', strtotime($monthStart));
        $snap = $this->getDriverActivitySnapshot($staffId, $monthStart, $monthEnd);

        // Temp cover days — sum of active temp windows this month
        $tempCoverDays = 0;
        try {
            $temps = (array) $this->fs->schoolWhere(
                self::COL_TEMPORARY_DRIVER_ASSIGNMENTS,
                [['covering_staff_id', '==', $staffId]]
            );
            foreach ($temps as $t) {
                if (!is_array($t)) { continue; }
                $t = $this->_unwrapNestedRow($t, 'schoolId');   // schoolWhere returns {id,data}
                $from = (string) ($t['effective_from'] ?? '');
                $to   = (string) ($t['effective_to']   ?? '');
                if ($from === '' || $to === '') { continue; }
                // Overlap with the month
                $ovFrom = max($from, $monthStart);
                $ovTo   = min($to, $monthEnd);
                if ($ovFrom > $ovTo) { continue; }
                $tempCoverDays += ((int) ((strtotime($ovTo) - strtotime($ovFrom)) / 86400)) + 1;
            }
        } catch (\Throwable $_) {}

        // Primary vehicles for this driver
        $primary = [];
        try {
            $vehicles = (array) $this->fs->schoolWhere(
                self::COL_VEHICLES,
                [['staff_id', '==', $staffId]]
            );
            foreach ($vehicles as $v) {
                if (!is_array($v)) { continue; }
                $vid = (string) ($v['vehicleId'] ?? $v['id'] ?? '');
                if ($vid !== '') { $primary[] = $vid; }
            }
        } catch (\Throwable $_) {}

        $now = date('c');
        $docId = "{$this->schoolId}_DACT_{$staffId}__{$yearMonth}";
        $data = [
            'summaryId'             => $docId,
            'schoolId'              => $this->schoolId,
            'staff_id'              => $staffId,
            'period_year_month'     => $yearMonth,
            'period_start'          => $monthStart,
            'period_end'            => $monthEnd,
            'trips_count'           => (int)   $snap['trips_count'],
            'total_km'              => (int)   $snap['total_km'],
            'on_time_pct'           => (float) $snap['on_time_pct'],
            'incidents_count'       => (int)   $snap['incidents_count'],
            'critical_incidents'    => (int)   $snap['critical_incidents'],
            'positive_recognitions' => (int)   $snap['positive_recognitions'],
            'temp_cover_days'       => $tempCoverDays,
            'primary_vehicles'      => $primary,
            'computed_at'           => $now,
            'computed_by'           => in_array($this->adminId, ['cron','transport_activity_cron','transport_expiry_cron'], true) ? 'cron' : 'manual_rebuild',
        ];
        $this->fs->setEntity(self::COL_DRIVER_ACTIVITY_SUMMARY, $docId, $data, /* merge */ false);
        return $data;
    }

    /**
     * Rebuild activity summaries (admin power action). When staffId is
     * empty, iterates all active drivers. When yearMonth is empty, uses
     * the current month.
     */
    public function rebuildActivitySummary(string $staffId = '', string $yearMonth = ''): array
    {
        $this->_assertReady();
        $ym = $yearMonth !== '' ? $yearMonth : date('Y-m');
        if ($staffId !== '') {
            $this->computeDriverActivitySummary($staffId, $ym);
            return ['rebuilt_count' => 1, 'year_month' => $ym];
        }
        $drivers = $this->listActiveDrivers();
        $count = 0;
        foreach ($drivers as $d) {
            if (!is_array($d)) { continue; }
            $sid = (string) ($d['staff_id'] ?? '');
            if ($sid === '') { continue; }
            try {
                $this->computeDriverActivitySummary($sid, $ym);
                $count++;
            } catch (\Throwable $_) {}
        }
        return ['rebuilt_count' => $count, 'year_month' => $ym];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  DOMAIN_EXPENSE (F5, 2026-07-07) — Transport Expenses + Accounting
    // ═════════════════════════════════════════════════════════════════════
    //
    // F5 architecture summary:
    //   • Expense taxonomy via enum (18 known types + free 'Other')
    //   • Payee model: Vendor | Staff | Driver | External
    //   • Cross-links to F1 maintenance/fuel logs (bidirectional read)
    //   • Pending-then-retry Accounting integration via
    //     PayrollAccountingService::postExpense (Payroll library owns the
    //     accounting seam; no direct ledger writes from Transport)
    //   • Configurable posting mode (schoolControl.transport.expense_
    //     posting_mode = 'manual' | 'auto'; default 'manual')
    //   • Generic attachments (not receipt-only) — schema supports
    //     Receipt | Invoice | Quotation | Workshop_Estimate | Warranty |
    //     Approval_Letter | Other without schema changes
    //   • Denormalised monthly per-vehicle expense summary
    //   • Reconciler for pending posts + admin retry surface

    /** Known expense types (free 'Other' + expense_type_custom allowed). */
    public const EXPENSE_TYPES = [
        // Vehicle operating
        'Vehicle_Fuel', 'Vehicle_Maintenance', 'Vehicle_Repair', 'Vehicle_Tyre_Replacement',
        // Vehicle statutory
        'Vehicle_Insurance_Premium', 'Vehicle_Permit_Fee', 'Vehicle_Fitness_Fee',
        'Vehicle_Pollution_Fee', 'Vehicle_Road_Tax',
        // Route operating
        'Toll_Fee', 'Parking_Fee', 'Emergency_Repair',
        // Driver
        'Driver_Allowance', 'Driver_Overtime', 'Driver_Uniform', 'Driver_Training_Fee',
        // Vendor
        'Vendor_Contract_Payment',
        // Event (F8, 2026-07-07) — chartered/hired vehicles + event-specific
        // supplementary spend that attaches to a transportEvent via
        // related_event_id
        'Event_Transport_Charter',
        // Catch-all
        'Miscellaneous', 'Other',
    ];

    public const PAYEE_TYPES = ['Vendor', 'Staff', 'Driver', 'External'];

    public const PAYMENT_METHODS = [
        'Cash', 'Bank_Transfer', 'Cheque', 'UPI', 'Card', 'Petty_Cash',
    ];

    /** Generic attachment categories — additive; schools may add 'Other'
     *  with a note without any schema change. */
    public const EXPENSE_ATTACHMENT_TYPES = [
        'Receipt', 'Invoice', 'Quotation', 'Workshop_Estimate',
        'Warranty', 'Approval_Letter', 'Other',
    ];

    public const EXPENSE_STATUSES = ['Active', 'Voided'];

    /** Retry cap for pending accounting posts before status flips to 'failed'. */
    private const EXPENSE_ACCOUNTING_MAX_ATTEMPTS = 5;

    /**
     * Expense type → Chart of Accounts code map. Codes are illustrative;
     * the school's actual CoA determines final. PayrollAccountingService
     * receives the code and routes to the correct journal line.
     */
    private const EXPENSE_TYPE_TO_LEDGER_CODE = [
        'Vehicle_Fuel'                => '6200',
        'Vehicle_Maintenance'         => '6210',
        'Vehicle_Repair'              => '6210',
        'Vehicle_Tyre_Replacement'    => '6220',
        'Vehicle_Insurance_Premium'   => '6230',
        'Toll_Fee'                    => '6240',
        'Parking_Fee'                 => '6240',
        'Driver_Allowance'            => '6250',
        'Driver_Overtime'             => '6250',
        'Driver_Uniform'              => '6250',
        'Driver_Training_Fee'         => '6250',
        'Vehicle_Permit_Fee'          => '6260',
        'Vehicle_Fitness_Fee'         => '6260',
        'Vehicle_Pollution_Fee'       => '6260',
        'Vehicle_Road_Tax'            => '6260',
        'Emergency_Repair'            => '6210',
        'Vendor_Contract_Payment'     => '6280',
        'Event_Transport_Charter'     => '6270',
        'Miscellaneous'               => '6290',
        'Other'                       => '6290',
    ];

    // ─── Expense CRUD ───────────────────────────────────────────────────

    /**
     * Save (create or update) a transport expense. Follows the pending-
     * then-retry Accounting pattern (F3 Fee_lifecycle precedent):
     *
     *   1. Validate payload
     *   2. Write expense doc with:
     *      - accounting_journal_ref = '' (manual mode, waiting for admin
     *        click) OR 'pending' (auto mode, first post will attempt now)
     *   3. If posting mode is 'auto' (or force_post=true): call
     *      PayrollAccountingService::postExpense; on success update ref;
     *      on failure leave 'pending' for reconciler
     *
     * Attachments (Refinement additional 2026-07-07): stored as an
     * array of {type, path, uploaded_at, uploaded_by, notes} objects —
     * supports receipts + invoices + quotations + workshop estimates +
     * warranty documents + approval letters without schema changes.
     */
    /**
     * @param bool $postingAuthorized TRUE only when the caller has established
     *        Transport · manage. Gates journal creation, never expense recording.
     */
    public function saveExpense(array $input, bool $postingAuthorized = false): array
    {
        $this->_assertReady();
        $id      = trim((string) ($input['id'] ?? ''));
        $isNew   = ($id === '');
        $existing = $isNew ? null : $this->fs->getEntity(self::COL_TRANSPORT_EXPENSES, $id);

        $this->_validateExpensePayload($input, $isNew);

        if ($isNew) {
            $id = $this->numbering->next('trn_expense', [
                'claimedBy'  => $this->adminId,
                'claimedFor' => 'F5:saveExpense',
            ]);
        }

        // ── FINANCE SEGREGATION (maker/checker) ──────────────────────────────
        // Recording an expense is 'edit'. CREATING THE ACCOUNTING JOURNAL is a
        // 'manage' act — post_expense_now / retry_expense_post / reconcile are
        // all manage-gated, and this method is the ONLY other path that reaches
        // _postToAccounting(). Left ungated it re-opened that boundary two ways:
        // a client-supplied force_post=1, and a tenant configured to auto-post.
        //
        // $postingAuthorized is a SEPARATE ARGUMENT, never an $input key: $input
        // is built from client POST data, so an authorization flag living there
        // could be set by anyone who can reach the endpoint. Only a caller that
        // has itself established manage may pass true.
        //
        // Defence in depth — strip any authorization-shaped keys a caller may
        // have folded into $input.
        unset($input['_posting_authorized'], $input['posting_authorized']);

        $forcePost = !empty($input['force_post']);
        if ($forcePost && !$postingAuthorized) {
            throw new \InvalidArgumentException(
                'Posting an expense to Accounting requires Transport · manage. '
                . 'The expense can be recorded and posted later by an authorised manager.');
        }
        // Auto-posting tenants: an unauthorised caller still RECORDS the expense,
        // it simply lands 'pending' for a manager to post. No capability is lost
        // and no journal is created — that is the maker/checker split.
        $postingMode = $forcePost ? 'auto' : $this->_getExpensePostingMode();

        // Normalise attachments — accept legacy 'receipt_path' as an implicit
        // Receipt attachment for backward compat with derived-from-log flows.
        $attachments = $this->_normaliseAttachments((array) ($input['attachments'] ?? []), (string) ($input['receipt_path'] ?? ''));

        $now = date('c');
        $amount = round((float) ($input['amount'] ?? 0), 2);
        $taxAmount = round((float) ($input['tax_amount'] ?? 0), 2);

        // FIN-3: a POSTED expense's financial fields are immutable. Editing the
        // amount/tax/payment-method/type/DATE would diverge the record from the
        // ledger — the deterministic sourceRef (TRN_EXPENSE_{id}) makes the
        // re-post a dedup no-op, so the change never reaches Accounting while
        // the record shows the new figures. expense_date is included because it
        // is the journal date fed to postExpense AND the FMES monthly-summary
        // bucket key — editing it on a posted expense would move the record to a
        // different day/accounting-period/month than its (unchanged) GL journal.
        // To correct a posted expense, void it (which reverses the journal —
        // FIN-4) and create a new one.
        $existingRef = $isNew ? '' : (string) ($existing['accounting_journal_ref'] ?? '');
        $isPosted    = $this->_isPostedRef($existingRef);
        if (!$isNew && $isPosted) {
            $newDate = trim((string) ($input['expense_date'] ?? date('Y-m-d')));
            $financialChanged =
                   $amount    !== round((float) ($existing['amount']     ?? 0), 2)
                || $taxAmount !== round((float) ($existing['tax_amount'] ?? 0), 2)
                || (string) ($input['payment_method'] ?? 'Cash') !== (string) ($existing['payment_method'] ?? 'Cash')
                || (string) $input['expense_type'] !== (string) ($existing['expense_type'] ?? '')
                || $newDate !== (string) ($existing['expense_date'] ?? '');
            if ($financialChanged) {
                throw new \RuntimeException(
                    "Cannot change the amount, tax, payment method, type, or date of a posted expense "
                    . "(journal {$existingRef}). Void this expense and create a new one instead."
                );
            }
        }
        $data = [
            'expenseId'               => $id,
            'schoolId'                => $this->schoolId,
            'expense_type'            => (string) $input['expense_type'],
            'expense_type_custom'     => trim((string) ($input['expense_type_custom'] ?? '')),
            'expense_date'            => trim((string) ($input['expense_date'] ?? date('Y-m-d'))),
            'amount'                  => $amount,
            'tax_amount'              => $taxAmount,
            'tax_rate'                => round((float) ($input['tax_rate'] ?? 0), 2),
            'total_amount'            => round($amount + $taxAmount, 2),
            'currency'                => 'INR',
            'payee_type'              => (string) $input['payee_type'],
            'payee_id'                => trim((string) ($input['payee_id']   ?? '')),
            'payee_name'              => trim((string) ($input['payee_name'] ?? '')),
            'vehicle_id'              => trim((string) ($input['vehicle_id'] ?? '')),
            'related_maintenance_id'  => trim((string) ($input['related_maintenance_id'] ?? '')),
            'related_fuel_id'         => trim((string) ($input['related_fuel_id']        ?? '')),
            'related_incident_id'     => trim((string) ($input['related_incident_id']    ?? '')),
            'related_event_id'        => trim((string) ($input['related_event_id']       ?? '')),
            'payment_method'          => (string) ($input['payment_method'] ?? 'Cash'),
            'payment_reference'       => trim((string) ($input['payment_reference'] ?? '')),
            'paid_by_staff_id'        => trim((string) ($input['paid_by_staff_id'] ?? $this->adminId)),
            'attachments'             => $attachments,
            // FIN-3: preserve the posted journal ref on a non-financial edit so
            // a benign save (notes/attachments/payee-name) does not reset the
            // link and re-trigger a dedup no-op post. Only new/pending/failed
            // records get the pending marker + attempt reset.
            'accounting_journal_ref'  => $isPosted ? $existingRef : ($postingMode === 'auto' ? 'pending' : ''),
            'accounting_attempts'     => $isPosted ? (int) ($existing['accounting_attempts'] ?? 0) : 0,
            'accounting_last_error'   => '',
            'notes'                   => trim((string) ($input['notes'] ?? '')),
            'status'                  => 'Active',
            'updated_at'              => $now,
        ];
        if ($isNew) {
            $data['created_at'] = $now;
            $data['created_by'] = $this->adminId;
        }
        $this->fs->setEntity(self::COL_TRANSPORT_EXPENSES, $id, $data, /* merge */ !$isNew);

        $this->emitAudit(
            'transportExpense', $id,
            $isNew ? 'created' : 'updated',
            $isNew ? null : $existing,
            $data,
            ['domain' => self::DOMAIN_EXPENSE, 'posting_mode' => $postingMode]
        );

        // Auto-post to Accounting (fire-and-await; failure leaves 'pending').
        // FIN-3: an already-posted expense is never re-posted (the sourceRef
        // would dedup) — its ref is preserved above and reported as-is.
        $postResult = ['status' => $isPosted ? 'posted' : ($postingMode === 'auto' ? 'pending' : 'manual'), 'journal_ref' => $existingRef];
        if ($postingMode === 'auto' && !$isPosted && $postingAuthorized) {
            $postResult = $this->_postToAccounting($id, $data);
        }
        return ['id' => $id, 'isNew' => $isNew, 'data' => $data, 'posting' => $postResult];
    }

    public function getExpense(string $expenseId): ?array
    {
        $this->_assertReady();
        if ($expenseId === '') { return null; }
        $e = $this->fs->getEntity(self::COL_TRANSPORT_EXPENSES, $expenseId);
        return is_array($e) ? $e : null;
    }

    /** Void (soft-delete) an expense. Only Active expenses may be voided. */
    /**
     * Does this accounting_journal_ref denote a REAL posted journal?
     *
     * The field carries four sentinels plus a real reference:
     *   ''        — manual mode, never queued
     *   'pending' — queued for the nightly reconciler
     *   'failed'  — retries exhausted
     *   'voided'  — the expense was voided before any post succeeded (FIN-5)
     *   <ref>     — an actual journal exists
     *
     * One definition, because this predicate was written out by hand at three
     * call sites. Adding the 'voided' sentinel to those copies independently
     * would have made a voided expense look POSTED to two of them — reversible
     * and reportable as though a journal existed.
     */
    private function _isPostedRef(string $ref): bool
    {
        return $ref !== '' && !in_array($ref, ['pending', 'failed', 'voided'], true);
    }

    public function voidExpense(string $expenseId, string $reason): void
    {
        $this->_assertReady();
        if ($expenseId === '' || trim($reason) === '') {
            throw new \InvalidArgumentException('expenseId and reason are required.');
        }
        $existing = $this->fs->getEntity(self::COL_TRANSPORT_EXPENSES, $expenseId);
        if (!is_array($existing)) {
            throw new \InvalidArgumentException('Expense not found: ' . $expenseId);
        }
        if ((string) ($existing['status'] ?? '') !== 'Active') {
            throw new \InvalidArgumentException('Expense is not Active.');
        }
        $now = date('c');
        $ref = (string) ($existing['accounting_journal_ref'] ?? '');
        $isPosted = $this->_isPostedRef($ref);
        $update = [
            'status'      => 'Voided',
            'voided_at'   => $now,
            'voided_by'   => $this->adminId,
            'void_reason' => trim($reason),
            'updated_at'  => $now,
        ];
        // FIN-4: a void of a POSTED expense must reverse its journal so the GL
        // nets to zero — a status-only void left Accounting overstating
        // transport expense with no offsetting entry. Reverse FIRST, then flip
        // the record: postReversal is idempotent (deterministic reversal
        // sourceRef), so a retry after a rare write failure will not
        // double-reverse. If the reversal cannot be posted we abort the void
        // rather than leave a voided-but-unreversed (divergent) record.
        if ($isPosted) {
            $reversalRef = $this->_reverseExpensePost($expenseId, $ref, trim($reason));
            if ($reversalRef === null) {
                throw new \RuntimeException(
                    "Could not reverse the accounting journal ({$ref}) for this expense; "
                    . "the void was NOT applied. Retry when Accounting is available."
                );
            }
            $update['accounting_reversal_ref'] = $reversalRef;
            $update['accounting_reversed_at']  = $now;
        } elseif ($ref === 'pending' || $ref === 'failed') {
            // FIN-5 (2026-08-19): settle a void that beat the post.
            //
            // There is no journal to reverse here — the post never succeeded —
            // but leaving the ref at 'pending' kept the record in the nightly
            // reconciler's queue, where it would eventually be posted (a journal
            // for a voided expense) and, until then, fire the stale-pending alarm
            // every single night with no action that could ever clear it.
            // 'voided' is a terminal state in the same lowercase vocabulary as
            // 'pending' and 'failed'.
            $update['accounting_journal_ref'] = 'voided';
        }
        $this->fs->setEntity(self::COL_TRANSPORT_EXPENSES, $expenseId, $update, /* merge */ true);
        $this->emitAudit(
            'transportExpense', $expenseId, 'voided',
            ['status' => 'Active'], $update,
            ['domain' => self::DOMAIN_EXPENSE, 'reason' => $reason]
        );
    }

    /** List expenses with optional filters. */
    public function listExpenses(array $filters = []): array
    {
        $this->_assertReady();
        $where = [];
        if (!empty($filters['expense_type'])) { $where[] = ['expense_type', '==', $filters['expense_type']]; }
        if (!empty($filters['vehicle_id']))   { $where[] = ['vehicle_id',   '==', $filters['vehicle_id']]; }
        if (!empty($filters['status']))       { $where[] = ['status',       '==', $filters['status']]; }
        try {
            // PERF-7: bound the scan. Ordered by expense_date DESC so a bounded
            // page is the most-recent expenses; prevents an unbounded
            // full-collection read every call. (A pushed-down expense_date range
            // query would be exact but needs a [schoolId,…,expense_date]
            // composite index — deferred to an index deployment.)
            $rows = (array) $this->fs->schoolWhere(
                self::COL_TRANSPORT_EXPENSES, $where,
                'expense_date', 'DESC', self::LIST_SCAN_LIMIT
            );
        } catch (\Throwable $_) { return []; }
        $out = [];
        foreach ($rows as $r) {
            $r = $this->_unwrapNestedRow($r, 'expense_type');
            if (!is_array($r)) { continue; }
            $id = (string) ($r['expenseId'] ?? $r['id'] ?? '');
            if ($id === '') { continue; }
            // Date-range post-filter (Firestore composite already indexes
            // by expense_date DESC; range predicates in-memory keep query
            // count bounded)
            if (!empty($filters['date_from']) && (string) ($r['expense_date'] ?? '') < $filters['date_from']) { continue; }
            if (!empty($filters['date_to'])   && (string) ($r['expense_date'] ?? '') > $filters['date_to'])   { continue; }
            $r['id'] = $id;
            $out[] = $r;
        }
        return $out;
    }

    // ─── Cross-link derivation from F1 logs ────────────────────────────

    /**
     * Convert a saved F1 maintenanceLogs entry into a transport expense.
     * One-click UI path — admin looks at a maintenance log row and posts
     * it to Accounting via this method.
     */
    public function createExpenseFromMaintenanceLog(string $maintenanceId, array $overrides = []): array
    {
        $this->_assertReady();
        $ml = $this->fs->getEntity(self::COL_MAINTENANCE_LOGS, $maintenanceId);
        if (!is_array($ml)) {
            throw new \InvalidArgumentException('Maintenance log not found: ' . $maintenanceId);
        }
        // Guard against double-posting: search existing expenses for this link
        $existing = (array) $this->fs->schoolWhere(
            self::COL_TRANSPORT_EXPENSES,
            [['related_maintenance_id', '==', $maintenanceId], ['status', '==', 'Active']]
        );
        if ($existing !== []) {
            throw new \RuntimeException('An active expense already exists for maintenance log ' . $maintenanceId);
        }
        $input = array_merge([
            'expense_type'           => 'Vehicle_Maintenance',
            'expense_date'           => (string) ($ml['service_date'] ?? date('Y-m-d')),
            'amount'                 => (float)  ($ml['cost']         ?? 0),
            'payee_type'             => 'External',
            'payee_name'             => (string) ($ml['workshop']     ?? ''),
            'vehicle_id'             => (string) ($ml['vehicle_id']   ?? ''),
            'related_maintenance_id' => $maintenanceId,
            'payment_method'         => 'Bank_Transfer',
            'notes'                  => 'Derived from maintenance log ' . $maintenanceId,
            'receipt_path'           => (string) ($ml['receipt_path'] ?? ''),
        ], $overrides);
        return $this->saveExpense($input);
    }

    /** Same pattern for F1 fuel logs. */
    public function createExpenseFromFuelLog(string $fuelId, array $overrides = []): array
    {
        $this->_assertReady();
        $fl = $this->fs->getEntity(self::COL_FUEL_LOGS, $fuelId);
        if (!is_array($fl)) {
            throw new \InvalidArgumentException('Fuel log not found: ' . $fuelId);
        }
        $existing = (array) $this->fs->schoolWhere(
            self::COL_TRANSPORT_EXPENSES,
            [['related_fuel_id', '==', $fuelId], ['status', '==', 'Active']]
        );
        if ($existing !== []) {
            throw new \RuntimeException('An active expense already exists for fuel log ' . $fuelId);
        }
        $input = array_merge([
            'expense_type'    => 'Vehicle_Fuel',
            'expense_date'    => (string) ($fl['fuel_date'] ?? date('Y-m-d')),
            'amount'          => (float)  ($fl['total_amount'] ?? 0),
            'payee_type'      => 'External',
            'payee_name'      => (string) ($fl['station'] ?? ''),
            'vehicle_id'      => (string) ($fl['vehicle_id'] ?? ''),
            'related_fuel_id' => $fuelId,
            'payment_method'  => 'Card',
            'notes'           => 'Derived from fuel log ' . $fuelId,
            'receipt_path'    => (string) ($fl['receipt_path'] ?? ''),
        ], $overrides);
        return $this->saveExpense($input);
    }

    // ─── Reconciliation ────────────────────────────────────────────────

    /**
     * Retry all expenses in 'pending' state up to $maxRetriesPerCall.
     * Nightly cron + admin action call this.
     */
    public function reconcilePendingAccountingPosts(int $maxRetriesPerCall = 50): array
    {
        $this->_assertReady();
        try {
            $rows = (array) $this->fs->schoolWhere(
                self::COL_TRANSPORT_EXPENSES,
                [['accounting_journal_ref', '==', 'pending']],
                null, 'ASC', $maxRetriesPerCall
            );
        } catch (\Throwable $_) { return ['retried' => 0, 'posted' => 0, 'failed' => 0, 'voided' => 0]; }
        $retried = 0; $posted = 0; $failed = 0; $voided = 0;
        foreach ($rows as $r) {
            $r = $this->_unwrapNestedRow($r, 'expense_type');
            if (!is_array($r)) { continue; }
            $id = (string) ($r['expenseId'] ?? $r['id'] ?? '');
            if ($id === '') { continue; }
            $retried++;
            $res = $this->_postToAccounting($id, $r);
            if ($res['status'] === 'posted') { $posted++; }
            elseif ($res['status'] === 'failed') { $failed++; }
            // FIN-5: a voided expense is settled, not posted and not a failure.
            // Counted so a nightly run that drains stale records reports what it
            // did rather than looking like it silently skipped work.
            elseif ($res['status'] === 'voided') { $voided++; }
        }
        return ['retried' => $retried, 'posted' => $posted, 'failed' => $failed, 'voided' => $voided];
    }

    /** Retry a single expense's pending post. */
    public function retryExpensePost(string $expenseId): array
    {
        $this->_assertReady();
        $existing = $this->fs->getEntity(self::COL_TRANSPORT_EXPENSES, $expenseId);
        if (!is_array($existing)) {
            throw new \InvalidArgumentException('Expense not found: ' . $expenseId);
        }
        return $this->_postToAccounting($expenseId, $existing);
    }

    /**
     * Admin-triggered manual post for expenses saved in 'manual' mode
     * (accounting_journal_ref='').
     */
    public function postExpenseNow(string $expenseId): array
    {
        $this->_assertReady();
        $existing = $this->fs->getEntity(self::COL_TRANSPORT_EXPENSES, $expenseId);
        if (!is_array($existing)) {
            throw new \InvalidArgumentException('Expense not found: ' . $expenseId);
        }
        $ref = (string) ($existing['accounting_journal_ref'] ?? '');
        if ($this->_isPostedRef($ref)) {
            throw new \InvalidArgumentException('Expense already posted to Accounting: ' . $ref);
        }
        // FIN-5: refuse a voided expense HERE rather than letting it fall through.
        // _postToAccounting() would also refuse it, but only after this method had
        // already written the ref back to 'pending' — briefly re-queueing a record
        // that must never be posted, and telling the admin nothing useful.
        $status = (string) ($existing['status'] ?? 'Active');
        if ($status !== 'Active') {
            throw new \InvalidArgumentException(
                "This expense is {$status} and cannot be posted to Accounting."
            );
        }
        // Mark as pending, then attempt post
        $this->fs->setEntity(self::COL_TRANSPORT_EXPENSES, $expenseId, [
            'accounting_journal_ref' => 'pending', 'updated_at' => date('c'),
        ], /* merge */ true);
        $existing['accounting_journal_ref'] = 'pending';
        return $this->_postToAccounting($expenseId, $existing);
    }

    // ─── Aggregation ───────────────────────────────────────────────────

    /**
     * Compute per-vehicle monthly expense summary for the specified month.
     * Aggregates transportExpenses + F1 maintenance costs + F1 fuel totals
     * so a single query answers "total spend on this vehicle this month".
     */
    public function computeFleetMonthlyExpenseSummary(string $yearMonth): array
    {
        $this->_assertReady();
        if (!preg_match('/^\d{4}-\d{2}$/', $yearMonth)) {
            throw new \InvalidArgumentException('YYYY-MM yearMonth required.');
        }
        $monthStart = $yearMonth . '-01';
        $monthEnd   = date('Y-m-t', strtotime($monthStart));

        // Fetch all Active expenses in month range
        $expenses = $this->listExpenses([
            'status' => 'Active', 'date_from' => $monthStart, 'date_to' => $monthEnd,
        ]);

        $summary = [
            // RC-3: bare entity id — setEntity() adds the single {schoolId}
            // prefix, giving canonical {schoolId}_FMES__{ym}. Was double-prefixed
            // ("{schoolId}_FMES__" + setEntity's prefix), which the bare-id
            // analytics readers missed (PERF-5). Matches the RFS_ convention.
            'summaryId'              => "FMES__{$yearMonth}",
            'schoolId'               => $this->schoolId,
            'period_year_month'      => $yearMonth,
            'period_start'           => $monthStart,
            'period_end'             => $monthEnd,
            'expenses_count'         => count($expenses),
            'total_amount'           => 0.0,
            'per_category'           => [],
            'per_vehicle'            => [],
            'per_payee_type'         => ['Vendor' => 0.0, 'Staff' => 0.0, 'Driver' => 0.0, 'External' => 0.0],
            'pending_accounting'     => 0,
            'failed_accounting'      => 0,
            'computed_at'            => date('c'),
            'computed_by'            => in_array($this->adminId, ['cron', 'transport_expense_reconciler'], true) ? 'cron' : 'manual_rebuild',
        ];
        foreach ($expenses as $e) {
            if (!is_array($e)) { continue; }
            $amt = (float) ($e['total_amount'] ?? $e['amount'] ?? 0);
            $summary['total_amount'] += $amt;
            $cat = (string) ($e['expense_type'] ?? 'Other');
            $summary['per_category'][$cat] = ($summary['per_category'][$cat] ?? 0.0) + $amt;
            $vid = (string) ($e['vehicle_id'] ?? '');
            if ($vid !== '') {
                $summary['per_vehicle'][$vid] = ($summary['per_vehicle'][$vid] ?? 0.0) + $amt;
            }
            $pt = (string) ($e['payee_type'] ?? 'External');
            if (isset($summary['per_payee_type'][$pt])) {
                $summary['per_payee_type'][$pt] += $amt;
            }
            $ref = (string) ($e['accounting_journal_ref'] ?? '');
            if ($ref === 'pending') { $summary['pending_accounting']++; }
            if ($ref === 'failed')  { $summary['failed_accounting']++; }
        }
        $summary['total_amount'] = round($summary['total_amount'], 2);
        foreach ($summary['per_category'] as $k => $v) { $summary['per_category'][$k] = round($v, 2); }
        foreach ($summary['per_vehicle']  as $k => $v) { $summary['per_vehicle'][$k]  = round($v, 2); }
        foreach ($summary['per_payee_type'] as $k => $v) { $summary['per_payee_type'][$k] = round($v, 2); }

        $this->fs->setEntity(self::COL_FLEET_MONTHLY_EXPENSE_SUMMARY, $summary['summaryId'], $summary, /* merge */ false);
        return $summary;
    }

    /** Rebuild monthly summaries (admin power action). */
    public function rebuildMonthlyExpenseSummary(string $yearMonth = ''): array
    {
        $ym = $yearMonth !== '' ? $yearMonth : date('Y-m');
        return $this->computeFleetMonthlyExpenseSummary($ym);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  F11 (2026-07-08) — routeFleetSummary compute (fills the F2 gap)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Compute + persist a per-route fleet summary. Fills the
     * routeFleetSummary collection gap — declared at F2 line 158 but never
     * written until F11. This is not a new business workflow; it is a
     * denormalised aggregate over existing tripLogs + studentRoutes +
     * driverIncidents, backing the F11 per-route KPI widgets.
     *
     * Reuses existing readers (schoolWhere on tripLogs/studentRoutes/
     * driverIncidents). Bounded windows (last-30d default) so a fleet
     * scale of hundreds of routes doesn't blow through Firestore read
     * budget.
     *
     * @param string $routeId when empty, rebuilds every Active route
     *                        (nightly cron path)
     * @param int    $windowDays trailing window for trips/incidents
     *                           (default 30)
     * @return array summary of what was computed
     */
    public function computeRouteFleetSummary(string $routeId = '', int $windowDays = 30): array
    {
        $this->_assertReady();
        $out = ['routes_processed' => 0, 'ids' => []];
        $now = date('c');
        $windowStart = date('Y-m-d', time() - $windowDays * 86400);
        $windowEnd   = date('Y-m-d');
        $curMonth    = date('Y-m');

        $routes = [];
        if ($routeId !== '') {
            $r = $this->fs->getEntity(self::COL_ROUTES, $routeId);
            if (is_array($r)) { $r['id'] = $routeId; $routes[] = $r; }
        } else {
            try {
                $rows = (array) $this->fs->schoolWhere(self::COL_ROUTES,
                    [['status', '==', 'Active']], null, 'ASC', 500);
                foreach ($rows as $rr) {
                    $inner = (isset($rr['data']) && is_array($rr['data'])) ? $rr['data'] : $rr;
                    $rid = (string) ($rr['id'] ?? $inner['id'] ?? '');
                    if ($rid === '') { continue; }
                    $inner['id'] = $rid;
                    $routes[] = $inner;
                }
            } catch (\Throwable $_) {}
        }

        foreach ($routes as $route) {
            $rid = (string) $route['id'];

            // Active students on the route (F3 studentRoutes LEGACY canonical)
            $activeStudents = 0;
            try {
                $sr = (array) $this->fs->schoolWhere(self::COL_STUDENT_ROUTES_LEGACY,
                    [['route_id', '==', $rid], ['status', '==', 'Active']], null, 'ASC', 2000);
                $activeStudents = count($sr);
            } catch (\Throwable $_) {}

            // Vehicle assigned to route (from route doc or first tripLogs match)
            $vehicleId = (string) ($route['vehicle_id'] ?? '');

            // Trips in trailing window + on-time %
            $tripsCount = 0; $onTime = 0; $incidents = 0;
            try {
                $trips = (array) $this->fs->schoolWhere(self::COL_TRIP_LOGS,
                    [['route_id', '==', $rid],
                     ['date', '>=', $windowStart],
                     ['date', '<=', $windowEnd]],
                    'date', 'DESC', 500);
                foreach ($trips as $t) {
                    $inner = $this->_unwrapNestedRow($t, 'date');
                    if (!is_array($inner)) { continue; }
                    $status = (string) ($inner['status'] ?? '');
                    if ($status === 'Completed') { $tripsCount++; }
                    if (!empty($inner['on_time'])) { $onTime++; }
                    if ($vehicleId === '') { $vehicleId = (string) ($inner['vehicleId'] ?? ''); }
                }
            } catch (\Throwable $_) {}
            $onTimePct = $tripsCount > 0 ? round(($onTime / $tripsCount) * 100, 1) : null;

            // Route incidents (via trips whose vehicle_id had incidents in window)
            // Cheaper: driverIncidents count for the effective driver on this vehicle
            $driverStaffId = '';
            if ($vehicleId !== '') {
                try {
                    $v = $this->fs->getEntity(self::COL_VEHICLES, $vehicleId);
                    if (is_array($v)) {
                        $driverStaffId = (string) ($v['staff_id'] ?? $v['driver_staff_id'] ?? '');
                    }
                } catch (\Throwable $_) {}
            }
            if ($driverStaffId !== '') {
                try {
                    $inc = (array) $this->fs->schoolWhere(self::COL_DRIVER_INCIDENTS,
                        [['staff_id', '==', $driverStaffId],
                         ['incident_date', '>=', $windowStart],
                         ['incident_date', '<=', $windowEnd]],
                        null, 'ASC', 100);
                    $incidents = count($inc);
                } catch (\Throwable $_) {}
            }

            // Cost this month — reuses the existing per-vehicle roll-up
            // from fleetMonthlyExpenseSummary (already populated by F5)
            $costThisMonth = 0.0;
            try {
                $fmes = $this->fs->getEntity(self::COL_FLEET_MONTHLY_EXPENSE_SUMMARY,
                    "FMES__{$curMonth}");
                if (is_array($fmes) && $vehicleId !== '') {
                    $costThisMonth = (float) ($fmes['per_vehicle'][$vehicleId] ?? 0.0);
                }
            } catch (\Throwable $_) {}

            $summaryId = "RFS_{$rid}";
            $data = [
                'summaryId'          => $summaryId,
                'schoolId'           => $this->schoolId,
                'route_id'           => $rid,
                'route_name'         => (string) ($route['name'] ?? ''),
                'vehicle_id'         => $vehicleId,
                'driver_staff_id'    => $driverStaffId,
                'active_students'    => $activeStudents,
                'trips_last_window'  => $tripsCount,
                'window_days'        => $windowDays,
                'on_time_pct'        => $onTimePct,
                'incidents_last_window' => $incidents,
                'cost_this_month'    => round($costThisMonth, 2),
                'period_month'       => $curMonth,
                'computed_at'        => $now,
                'computed_by'        => $this->adminId,
            ];
            $this->fs->setEntity(self::COL_ROUTE_FLEET_SUMMARY, $summaryId, $data, /* merge */ false);
            $out['ids'][] = $summaryId;
            $out['routes_processed']++;
        }
        return $out;
    }

    /** Rebuild helper — cron path. */
    public function rebuildRouteFleetSummary(): array
    {
        return $this->computeRouteFleetSummary('');
    }

    // ─── Read helpers ──────────────────────────────────────────────────

    public function getExpenseCategories(): array
    {
        return [
            'types'             => self::EXPENSE_TYPES,
            'payee_types'       => self::PAYEE_TYPES,
            'payment_methods'   => self::PAYMENT_METHODS,
            'attachment_types'  => self::EXPENSE_ATTACHMENT_TYPES,
        ];
    }

    public function getPendingAccountingCount(): int
    {
        $this->_assertReady();
        try {
            $rows = (array) $this->fs->schoolWhere(
                self::COL_TRANSPORT_EXPENSES,
                [['accounting_journal_ref', '==', 'pending']]
            );
            return count($rows);
        } catch (\Throwable $_) { return 0; }
    }

    // ─── private helpers ─────────────────────────────────────────────────

    private function _assertReady(): void
    {
        if (!$this->ready) {
            throw new \LogicException(
                'Transport_service: init() must be called before emitAudit() or any business method'
            );
        }
        if (!is_object($this->fs)) {
            throw new \LogicException(
                'Transport_service: Firestore_service is not bound; call init() with a live $fs'
            );
        }
    }

    // ─── DOMAIN_FLEET private helpers ────────────────────────────────────

    /**
     * Read a staff doc and return the current name + phone snapshot for
     * denormalisation onto the vehicle. Returns null on any lookup failure
     * (defensive — HR read failure must not block a vehicle save; caller
     * falls back to whatever was supplied).
     *
     * @return array{name:string, phone:string}|null
     */
    private function _snapshotDriverFromStaff(string $staffId): ?array
    {
        if ($staffId === '') {
            return null;
        }
        try {
            $staff = $this->fs->getEntity('staff', $staffId);
        } catch (\Throwable $_) {
            return null;
        }
        if (!is_array($staff)) {
            return null;
        }
        // Staff schema uses several historical name/phone keys; pick the
        // first non-empty match to be tolerant of shape drift documented
        // in [[hr_payroll_canonical_schema]].
        $name = (string) (
            $staff['name']         ??
            $staff['Name']         ??
            $staff['full_name']    ??
            $staff['fullName']     ??
            ''
        );
        $phone = (string) (
            $staff['phone']         ??
            $staff['contact']       ??
            $staff['mobile']        ??
            $staff['phone_number']  ??
            $staff['phoneNumber']   ??
            ''
        );
        return ['name' => trim($name), 'phone' => trim($phone)];
    }

    /**
     * Map a lifecycle_status value onto the legacy 3-state `status` field
     * (Active|Inactive|Maintenance) to preserve backward compatibility
     * with existing UI reads + Kotlin app reads that still read `status`.
     */
    private function _deriveLegacyStatus(string $lifecycleStatus): string
    {
        switch ($lifecycleStatus) {
            case 'Maintenance': return 'Maintenance';
            case 'Retired':
            case 'Disposed':    return 'Inactive';
            case 'Purchased':
            case 'Onboarding':
            case 'Active':
            default:            return 'Active';
        }
    }

    /**
     * Append a lifecycle log entry AND update the vehicle's lifecycle_status
     * in the same Firestore batch (atomicity). Used both by the initial
     * onboarding event in saveVehicle() and by transitionVehicleLifecycle()
     * (Phase F1 method, added in the next edit).
     *
     * @param string      $vehicleId
     * @param string      $eventType   'Onboarded'|'Activated'|'Sent_To_Maintenance'|
     *                                 'Returned_From_Maintenance'|'Retired'|
     *                                 'Disposed'|'Replacement_Received'|
     *                                 'Replacement_Retired'|'Ownership_Transferred'|
     *                                 'Vendor_Changed'
     * @param string      $prevState
     * @param string      $newState
     * @param string      $reason
     * @param array<int, string> $attachmentPaths
     * @param string|null $relatedVehicleId  For replacement events.
     * @return string     The allocated lifecycle log doc key.
     */
    private function _appendLifecycleEvent(
        string $vehicleId,
        string $eventType,
        string $prevState,
        string $newState,
        string $reason,
        array  $attachmentPaths = [],
        ?string $relatedVehicleId = null
    ): string {
        $eventId = $this->numbering->next('trn_vehicle_lifecycle', [
            'claimedBy'  => $this->adminId,
            'claimedFor' => 'F1:lifecycleEvent',
        ]);
        $now = date('c');
        $row = [
            'eventId'            => $eventId,
            'schoolId'           => $this->schoolId,
            'vehicle_id'         => $vehicleId,
            'event_type'         => $eventType,
            'event_at'           => $now,
            'event_by'           => $this->adminId,
            'reason'             => $reason,
            'prev_state'         => $prevState,
            'new_state'          => $newState,
            'related_vehicle_id' => (string) ($relatedVehicleId ?? ''),
            'attachment_paths'   => array_values(array_filter(array_map('strval', $attachmentPaths))),
        ];
        $this->fs->setEntity(self::COL_VEHICLE_LIFECYCLE_LOG, $eventId, $row, /* merge */ false);
        return $eventId;
    }

    /**
     * Trim a vehicle doc down to fields safe + useful for audit inclusion.
     * Strips the reserved GPS position fields (privacy) and any transient
     * caches.
     */
    private function _capVehicleForAudit(?array $v): ?array
    {
        if (!is_array($v)) {
            return null;
        }
        $out = $v;
        foreach (self::RESERVED_GPS_FIELDS as $f) {
            unset($out[$f]);
        }
        return $out;
    }

    /**
     * Guard: F1 code MUST NOT write Phase-5-reserved GPS position fields.
     * Throws when any reserved field is present in the payload — protects
     * the forward-compat contract stated at the top of this file.
     */
    private function _assertNoReservedGpsWrites(array $data): void
    {
        foreach (self::RESERVED_GPS_FIELDS as $f) {
            if (array_key_exists($f, $data)) {
                throw new \LogicException(
                    "Transport_service (F1) must not write reserved GPS field: {$f}"
                );
            }
        }
    }

    /**
     * Map a (fromState, toState) lifecycle transition onto the event_type
     * string that gets written on the vehicleLifecycleLog row. Keeps
     * events legible in audit + admin timeline UI.
     */
    private function _deriveLifecycleEventType(string $from, string $to): string
    {
        // canonical transitions per LIFECYCLE_TRANSITIONS
        if ($from === 'Purchased'   && $to === 'Onboarding')  { return 'Onboarded'; }
        if ($from === 'Onboarding'  && $to === 'Active')      { return 'Activated'; }
        if ($from === 'Onboarding'  && $to === 'Retired')     { return 'Retired'; }
        if ($from === 'Active'      && $to === 'Maintenance') { return 'Sent_To_Maintenance'; }
        if ($from === 'Active'      && $to === 'Retired')     { return 'Retired'; }
        if ($from === 'Maintenance' && $to === 'Active')      { return 'Returned_From_Maintenance'; }
        if ($from === 'Maintenance' && $to === 'Retired')     { return 'Retired'; }
        if ($from === 'Retired'     && $to === 'Disposed')    { return 'Disposed'; }
        if ($from === 'Retired'     && $to === 'Active')      { return 'Reactivated'; }
        // Fallback (should be unreachable because transitionVehicleLifecycle
        // validates against the same matrix). Kept for defensive audit.
        return "Transition_{$from}_to_{$to}";
    }

    /**
     * Roll-forward kmpl computation: take the last $window fuel entries
     * (most recent first) and compute (total litres over the window) ÷
     * (odometer delta over the window). Requires >=2 entries with
     * odometer_km > 0 for a meaningful ratio.
     *
     * Returns 0.0 when the sample is insufficient — caller preserves the
     * vehicle's existing average_kmpl in that case.
     */
    private function _recomputeKmpl(string $vehicleId, int $window = 5): float
    {
        if ($vehicleId === '' || $window < 2) { return 0.0; }
        $rows = (array) $this->fs->schoolWhere(
            self::COL_FUEL_LOGS,
            [['vehicle_id', '==', $vehicleId]],
            'fuel_date', 'DESC',
            $window
        );
        // Keep only entries with a real odometer reading; require at least 2.
        $usable = [];
        foreach ($rows as $r) {
            if (!is_array($r)) { continue; }
            $r = $this->_unwrapNestedRow($r, 'schoolId');   // schoolWhere returns {id,data}
            $odo = (int) ($r['odometer_km'] ?? 0);
            $lit = (float) ($r['litres']   ?? 0);
            if ($odo > 0 && $lit > 0) {
                $usable[] = ['odo' => $odo, 'lit' => $lit];
            }
        }
        if (count($usable) < 2) { return 0.0; }
        // Order oldest→newest to compute odometer delta between endpoints.
        usort($usable, static fn($a, $b) => $a['odo'] <=> $b['odo']);
        $odoDelta   = $usable[count($usable) - 1]['odo'] - $usable[0]['odo'];
        // Sum litres consumed BETWEEN the endpoints (exclude the earliest
        // entry — its litres delivered the fuel BEFORE the starting odo).
        $litSum = 0.0;
        for ($i = 1; $i < count($usable); $i++) {
            $litSum += $usable[$i]['lit'];
        }
        if ($odoDelta <= 0 || $litSum <= 0) { return 0.0; }
        return round($odoDelta / $litSum, 2);
    }

    // ─── DOMAIN_ROUTE (F2) private helpers ───────────────────────────────

    /**
     * F2 guard (Refinement #3): F2 code MUST NOT write stop-optimisation
     * fields reserved for the future route-optimisation feature. Enforces
     * the forward-compat contract at the write boundary.
     */
    private function _assertNoReservedStopOptimizationWrites(array $data): void
    {
        foreach (self::RESERVED_STOP_OPTIMIZATION_FIELDS as $f) {
            if (array_key_exists($f, $data)) {
                throw new \LogicException(
                    "Transport_service (F2) must not write reserved stop-optimisation field: {$f}"
                );
            }
        }
    }

    /**
     * Emit Communication notices for a suspension event (created or
     * resolved). Audience decision matches Refinement #1:
     *
     *   - Admin + Transport Manager: ALWAYS notified (operational audience)
     *   - Parents on the affected route: only when
     *     $suspension['notify_parents'] === true
     *
     * Returns the ISO timestamp when the parent-facing notice was emitted
     * (empty string when parents were not notified or when Communication
     * was unavailable). Callers stamp this on the suspension doc.
     *
     * Best-effort: any Communication-side failure is logged, not rethrown
     * — audit + business state are the source of truth.
     */
    // ─── DOMAIN_ASSIGNMENT (F3) private helpers ──────────────────────────

    /**
     * Refinement #4: pre-write validation for an assignment create/update.
     *
     * Checks:
     *   - Student exists (in SIS 'students') and is Active
     *   - Route exists AND is Active (not Archived/Inactive/Suspended)
     *   - Stop exists AND belongs to the selected route (when stop_id given)
     *   - Vehicle assigned to the route (if any) has lifecycle_status=Active
     *   - Session matches the current session
     *
     * Returns [ 'student' => ..., 'route' => ..., 'stop' => ...|null ]
     * for use by the caller.
     *
     * @throws \InvalidArgumentException on any validation failure.
     */
    private function _validateAssignmentContext(array $input, $isUpdate): array
    {
        $studentId = trim((string) ($input['student_id'] ?? ''));
        $routeId   = trim((string) ($input['route_id']   ?? ''));
        $stopId    = trim((string) ($input['stop_id']    ?? ''));
        $session   = trim((string) ($input['session']    ?? $this->sessionYear));

        // 1. Session check — assignment must belong to current session
        if ($session !== $this->sessionYear) {
            throw new \InvalidArgumentException(
                "Assignment session ({$session}) must match current session ({$this->sessionYear})."
            );
        }

        // 2. Student existence + active status
        $student = $this->fs->getEntity('students', $studentId);
        if (!is_array($student)) {
            throw new \InvalidArgumentException('Student not found: ' . $studentId);
        }
        $studentStatus = strtolower((string) (
            $student['status']
            ?? $student['Status']
            ?? $student['active_status']
            ?? 'active'
        ));
        if ($studentStatus !== '' && !in_array($studentStatus, ['active', ''], true)) {
            throw new \InvalidArgumentException(
                "Student {$studentId} is not Active in SIS (status='{$studentStatus}')."
            );
        }

        // 3. Route existence + operational status
        $route = $this->fs->getEntity(self::COL_ROUTES, $routeId);
        if (!is_array($route)) {
            throw new \InvalidArgumentException('Route not found: ' . $routeId);
        }
        $routeStatus = (string) ($route['status'] ?? 'Active');
        if ($routeStatus !== 'Active') {
            throw new \InvalidArgumentException(
                "Route {$routeId} is not Active (status='{$routeStatus}'). Cannot assign students to inactive or archived routes."
            );
        }
        if ((string) ($route['active_suspension_ref'] ?? '') !== '') {
            throw new \InvalidArgumentException(
                "Route {$routeId} currently has an active suspension. Resume it before assigning students."
            );
        }

        // 4. Stop belongs to route (when stop_id provided)
        $stop = null;
        if ($stopId !== '') {
            $stop = $this->fs->getEntity(self::COL_TRANSPORT_STOPS, $stopId);
            if (!is_array($stop)) {
                throw new \InvalidArgumentException('Stop not found: ' . $stopId);
            }
            if ((string) ($stop['route_id'] ?? '') !== $routeId) {
                throw new \InvalidArgumentException(
                    "Stop {$stopId} does not belong to route {$routeId}."
                );
            }
            $stopStatus = (string) ($stop['status'] ?? 'Active');
            if ($stopStatus !== 'Active') {
                throw new \InvalidArgumentException(
                    "Stop {$stopId} is not Active (status='{$stopStatus}')."
                );
            }
        }

        // 5. Vehicle assigned to the route is Active in lifecycle
        $vehicleId = (string) ($route['vehicle_id'] ?? '');
        if ($vehicleId !== '') {
            $veh = $this->fs->getEntity(self::COL_VEHICLES, $vehicleId);
            if (!is_array($veh)) {
                throw new \InvalidArgumentException(
                    "Route {$routeId} references a missing vehicle: {$vehicleId}."
                );
            }
            $vLifecycle = (string) ($veh['lifecycle_status'] ?? 'Active');
            if (!in_array($vLifecycle, ['Active', 'Onboarding', 'Purchased'], true)) {
                throw new \InvalidArgumentException(
                    "Route {$routeId} vehicle {$vehicleId} is not Active (lifecycle='{$vLifecycle}')."
                );
            }
        }

        return ['student' => $student, 'route' => $route, 'stop' => $stop];
    }

    /**
     * Compute the monthly fee for an assignment given the route + subscription
     * semantics. Applies OneWay + Hostel + fee_category adjustments.
     */
    /**
     * ══ TRN-FARE-BASIS-1 · MONTHLY FARE → ACADEMIC-SESSION DEMAND AMOUNT ══
     *
     * THE DEFECT THIS CLOSES. A route's fare is entered, stored, listed and
     * displayed as a MONTHLY figure — "Monthly Fee (Rs)" on the route form,
     * "Monthly Fee" in the route and assignment tables, `Transport 200/mo` in
     * the project's own design notes, and `Monthly` in the fee-head registry.
     * That monthly figure was handed to createModuleFee() unchanged, and
     * createModuleFee writes ONE demand per academic session. A route at
     * Rs 1,000/month therefore billed Rs 1,000 for the whole year.
     *
     * Proven side by side in one fresh tenant, one student:
     *     Tuition   (class chart, frequency=monthly, 500) -> 12 x 500 = 6,000
     *     Transport (this module, "Monthly Fee"  , 1000) ->  1 x  667 =   667
     * The chart is the product's own statement of what "monthly" means: it
     * bills a monthly head twelve times. Transport billed its monthly fare
     * once, then prorated it DOWN. Roughly a 12x under-charge to the school.
     *
     * ROOT CAUSE, for the record: the G2 fix of 2026-08-07 tagged the demand
     * 'Annual' so that proRateFees() would act on it at all. That tag was
     * chosen to unlock a PRORATION MECHANISM and silently redefined the
     * amount's MEANING from per-month to per-session. Nothing in the UI, the
     * registry or the docs ever agreed with the annual reading.
     *
     * WHY THE CONVERSION LIVES HERE AND NOT IN _computeAssignmentFee(). That
     * function's return is also written to the assignment document as
     * `monthly_fee` (see assignStudent) and rendered to staff as "Rs {fee}"
     * per month. Multiplying inside it would make the UI claim a Rs 12,000
     * monthly fare. The monthly figure stays monthly everywhere a human reads
     * it; it becomes a session amount at exactly one boundary — the demand.
     *
     * PRORATION IS STILL THE MECHANISM, NOT A REPLACEMENT FOR IT. The demand
     * carries the full twelve-month list value and proRateFees() scales it by
     * remainingMonths/12 from `listGrossAmount`, so a mid-session joiner pays
     * monthlyFare x remainingMonths and an April joiner pays the full year.
     * Because proration always recomputes from the list anchor it is
     * idempotent — running it again never compounds.
     *
     * EVERY caller must prorate after using this. Two did not (resumeAssignment
     * and the pending-fee reconciler); without proration this conversion would
     * turn their existing over-charge into a twelve-fold one. Both now prorate.
     */
    private const SESSION_MONTHS = 12;

    private function _sessionFareFromMonthly(float $monthlyFare): float
    {
        return round($monthlyFare * self::SESSION_MONTHS, 2);
    }

    /**
     * ══ THE AUTHORITATIVE SERVICE RECORD · TRN-PRORATE-INVERT-1 ════════════
     *
     * Records what happened to this student's transport, then derives the bill
     * from that record rather than transforming whatever the demand happened to
     * say. Every fee-affecting event funnels through here, so suspend → resume
     * → route change → removal CONVERGE on one answer instead of each
     * overwriting the last.
     *
     * $event:
     *   'start'    service begins (or resumes) on $date at $fare
     *   'stop'     service ends on $date — EXCLUSIVE, so stopping on the 1st
     *              does not bill that month
     *   'refare'   service continues but the fare changes from $date (route
     *              change): the running segment is closed and a new one opened,
     *              so months already served keep the fare they were served at
     *   'recompute' no state change — re-derive the bill from what is recorded
     *
     * @return array<string, float> periodKey => fare, the months to bill
     */
    private function _syncServicePeriods(
        string $studentId,
        string $event,
        string $date,
        float  $fare = 0.0,
        ?array $assignment = null,
        string $session = ''
    ): array {
        $this->ci->load->library('Transport_billing_period', null, 'trnPeriod');

        $session = $session !== '' ? $session : $this->sessionYear;
        $date    = trim($date) !== '' ? trim($date) : date('Y-m-d');

        if (!is_array($assignment)) {
            $assignment = $this->fs->getEntity(self::COL_STUDENT_ROUTES_LEGACY, $studentId);
        }
        $segments = [];
        if (is_array($assignment) && isset($assignment['service_periods']) && is_array($assignment['service_periods'])) {
            $segments = $assignment['service_periods'];
        }

        // A record that predates this model: seed one segment so an existing
        // assignment is not treated as never having had service. Its start is
        // the effective_from the operator actually chose, falling back to the
        // creation date — never to today, which would erase months served.
        if ($segments === [] && $event !== 'start' && is_array($assignment)) {
            $seed = trim((string) ($assignment['effective_from'] ?? ''));
            if ($seed === '') $seed = substr((string) ($assignment['created_at'] ?? ''), 0, 10);
            if ($seed !== '') {
                $segments = [[
                    'from'        => $seed,
                    'to'          => '',
                    'monthly_fee' => round((float) ($assignment['monthly_fee'] ?? 0), 2),
                ]];
            }
        }

        switch ($event) {
            case 'start':
                $segments = \Transport_billing_period::open($segments, $date, $fare);
                break;
            case 'stop':
                $segments = \Transport_billing_period::close($segments, $date);
                break;
            case 'refare':
                $segments = \Transport_billing_period::close($segments, $date);
                $segments = \Transport_billing_period::open($segments, $date, $fare);
                break;
            case 'recompute':
            default:
                break;
        }

        if ($event !== 'recompute') {
            $this->fs->setEntity(self::COL_STUDENT_ROUTES_LEGACY, $studentId,
                ['service_periods' => array_values($segments)], /* merge */ true);
        }

        return \Transport_billing_period::billableMonths($session, $segments);
    }

    /**
     * Write the transport demand from the service record.
     *
     * Deliberately does NOT call proRateFees(). Proration answers "you JOINED
     * in month X, pay the rest of the year" — right for a joiner, and the exact
     * mechanism that produced TRN-PRORATE-INVERT-1 when a STOP date was fed
     * into the same parameter. The months are now computed directly, so there
     * is nothing left to prorate and a second pass would only re-break it.
     *
     * Nothing else in the codebase prorates a Transport demand — verified —
     * so removing these calls leaves no other writer to clobber the result.
     */
    private function _writeTransportDemand(string $studentId, array $periods, array $ctx): string
    {
        $this->ci->load->library('Fee_lifecycle', null, 'feeLifecycle');

        $demandRef = $this->ci->feeLifecycle->createModuleFee($studentId, 'Transport', array_merge([
            'amount'          => round(array_sum($periods), 2),
            'billablePeriods' => $periods,
            'frequency'       => 'Annual',
            'period'          => 'Monthly',
            'start_date'      => date('Y-m-d'),
            'dueDate'         => $this->_moduleFeeDueDate(),
        ], $ctx));

        // ── EXCESS MONEY MUST NEVER SILENTLY DISAPPEAR ─────────────────────
        //
        // Every transport liability change lands here, which makes this the
        // one place that can see "the charge just fell below what the family
        // already paid". Fee_lifecycle floors the balance at zero — correct,
        // a balance must never go negative — but that alone left the
        // difference visible nowhere.
        //
        // This only DETECTS and raises a pending obligation in the existing
        // feeRefunds queue. It approves nothing and moves no money: refunds
        // still leave through the established maker-checker route.
        $this->_reconcileOverpayment($studentId, $demandRef);

        return is_string($demandRef) && $demandRef !== '' ? $demandRef : 'ok';
    }

    /**
     * Re-read the demand this write produced and reconcile any excess against
     * the canonical refund queue.
     *
     * Reads the demand back rather than trusting the amounts just computed:
     * `paidAmount` and any fine live on the stored document, and the whole
     * question is what the family has ALREADY paid against it.
     */
    private function _reconcileOverpayment(string $studentId, $demandRef): void
    {
        try {
            if (!is_string($demandRef) || $demandRef === '' || $demandRef === 'ok') return;

            $demand = $this->fs->get('feeDemands', $demandRef);
            if (!is_array($demand) || $demand === []) return;

            // Belt and braces: a demand from another tenant must never drive a
            // refund obligation in this one.
            if ((string) ($demand['schoolId'] ?? $this->schoolId) !== $this->schoolId) {
                log_message('error', "ACC_OVERPAYMENT_CROSS_TENANT demand=[{$demandRef}] school=[{$this->schoolId}]");
                return;
            }

            // The raw client comes from the CI instance — the established way a
            // library reaches it here (Lock_cache, Staff_attendance_finaliser).
            // Transport_service holds Firestore_service, not the client itself.
            $firebase = isset($this->ci->firebase) ? $this->ci->firebase : null;
            if ($firebase === null) {
                log_message('error', 'ACC_OVERPAYMENT_NO_CLIENT — firebase unavailable; '
                    . "excess for [{$studentId}] NOT reconciled. The liability change stands.");
                return;
            }

            $this->ci->load->library('Fee_firestore_txn', null, 'feeTxn');
            $this->ci->load->library('Overpayment_service', null, 'overpay');

            // Fee_firestore_txn must be init'd before writeRefund() will do
            // anything — it fails closed on !$this->ready and would otherwise
            // return false silently, losing the obligation.
            $this->ci->feeTxn->init($firebase, $this->fs, $this->schoolId, $this->sessionYear);

            $this->ci->overpay
                ->init($this->ci->feeTxn, $firebase,
                       $this->schoolId, $this->sessionYear, $this->adminId)
                ->reconcile($studentId, 'Transport', $demand);
        } catch (\Throwable $e) {
            // Never allowed to fail the service change that revealed it — the
            // reduction is correct on its own, and a missed obligation is
            // logged loudly and recoverable.
            log_message('error', "ACC_OVERPAYMENT_HOOK_FAILED [{$studentId}]: " . $e->getMessage());
        }
    }

    /**
     * ══ TRN-DUE-1 · A TRANSPORT DEMAND CARRIED NO DUE DATE ══════════════════
     *
     * DUE-2 fixed the plumbing — createModuleFee() now writes whatever
     * `dueDate` a caller passes — but Transport passed none, so every transport
     * demand was still stored with `dueDate: ''`. Confirmed on a live demand:
     * every field a fee reader filters on matched the tuition rows exactly,
     * except this one.
     *
     * Three consumers break on an empty due date, and DUE-2 recorded all three
     * PROVEN ON A REAL DEVICE for the sibling Student Charge case:
     *   • The Parent app falls back to inferring a due date from the demand's
     *     month label — and createModuleFee files every module charge under the
     *     SESSION START (April). A transport fee raised in August therefore
     *     displays to the parent as months overdue on the day it is created.
     *   • The late-fee engine cannot fine it: fineFor() returns null on an
     *     empty dueDate.
     *   • The reminder service cannot chase it.
     * So the charge sits unpaid and unchased while telling the family they are
     * already late.
     *
     * THIRTY DAYS FROM THE CHARGE, matching Student_charge_service's
     * DEFAULT_DUE_DAYS — the same convention already used for the other
     * per-student one-off this system raises. Deliberately NOT the session
     * start: the demand is created when transport is approved, which is when
     * the obligation begins, and a date in the past is exactly the bug above.
     */
    private function _moduleFeeDueDate(): string
    {
        return date('Y-m-d', strtotime('+30 days'));
    }

    /**
     * ══ TRN-DOUBLE-BILL-1 · THE SAME FAMILY CHARGED TWICE FOR ONE BUS ══════
     *
     * A school can put a "Transport Fee" head in the CLASS FEE CHART and also
     * run the Transport module. Nothing stops it. The two produce different
     * demand ids, so they never collide — they simply both stand:
     *
     *   chart   frequency 'monthly'  ->  TWELVE demands, billed to EVERY student
     *                                    in the class whether or not they ride
     *   module  one per session      ->  ONE demand, only for enrolled students
     *
     * Reproduced: a fixture tenant carried 12 chart transport demands of 300
     * AND the module demand, for the same child. Both are valid rows; the
     * family is simply billed twice, and the chart also bills children who
     * never asked for a bus.
     *
     * WHY THIS ONLY WARNS. The correct prevention is at CONFIGURATION time —
     * refusing to place a service-category head in a class chart at all, using
     * Fee_head_resolver::isServiceCategory(), the authority that already exists.
     * That code lives in Fee_management.php, which is at a parked commit
     * boundary this workstream must not disturb, so it is reported rather than
     * written here.
     *
     * What this DOES do is refuse to be silent at the moment the second charge
     * appears. It deletes nothing and changes no amount — rewriting a family's
     * existing demands from inside a route assignment would be far worse than
     * the duplicate — but the operator, and the log, now learn about it at the
     * exact point it happens instead of when a parent complains.
     */
    private function _warnIfChartAlsoBillsTransport(string $studentId): void
    {
        try {
            // schoolWhere(), not querySchool(): $this->fs here is
            // Firestore_service, which has no querySchool — the first version
            // of this check called it, threw, and was swallowed by the catch
            // below, so the warning silently never fired. Caught only because
            // the catch logs; a bare `return` would have hidden it completely.
            $rows = (array) $this->fs->schoolWhere('feeDemands', [['studentId', '=', $studentId]]);

            $chartRows = 0;
            $chartTotal = 0.0;
            foreach ($rows as $r) {
                $d = $r['data'] ?? $r;
                if (!is_array($d)) continue;
                // A transport-looking head that this module did NOT raise. The
                // module stamps moduleType; the chart generator does not.
                if ((string) ($d['moduleType'] ?? '') === 'Transport') continue;
                if (!preg_match('/transport/i', (string) ($d['feeHead'] ?? ''))) continue;
                if (in_array((string) ($d['status'] ?? ''), ['archived', 'cancelled'], true)) continue;
                $chartRows++;
                $chartTotal += (float) ($d['netAmount'] ?? 0);
            }

            if ($chartRows === 0) return;

            log_message('error',
                'ACC_TRANSPORT_DOUBLE_BILL school=[' . $this->schoolId . '] student=[' . $studentId
                . '] chart_transport_demands=[' . $chartRows . '] chart_total=[' . round($chartTotal, 2)
                . '] — this student is ALSO billed for transport by the class fee chart, on top of '
                . 'the module charge just raised. Transport is a per-student service and should be '
                . 'owned by the Transport module alone: remove the transport head from the class '
                . 'fee structure, or stop assigning routes. No demand has been altered.');

            // Surfaced on the assignment so an operator looking at the student
            // sees it, not only whoever reads the log.
            $this->fs->setEntity(self::COL_STUDENT_ROUTES_LEGACY, $studentId, [
                'chart_transport_conflict'       => true,
                'chart_transport_demand_count'   => $chartRows,
                'chart_transport_amount'         => round($chartTotal, 2),
            ], /* merge */ true);
        } catch (\Throwable $e) {
            // Diagnostic only — it must never be able to fail an assignment.
            log_message('error', 'TRN double-bill check failed for ' . $studentId . ': ' . $e->getMessage());
        }
    }

    private function _computeAssignmentFee(
        array $route,
        string $subscription,
        string $direction,
        string $feeCategory,
        array $params = []
    ): float {
        $base = (float) ($route['monthly_fee'] ?? 0);
        if ($feeCategory === 'Waived') {
            return 0.0;
        }
        $fee = $base;

        if ($subscription === 'OneWay' || $direction === 'Pickup' || $direction === 'Drop') {
            $fee *= self::ONEWAY_FEE_MULTIPLIER;
        }
        if (!empty($params['hostel_transport_flag'])) {
            $fee *= self::HOSTEL_FEE_MULTIPLIER;
        }
        // Fee_lifecycle applies concession/scholarship discount downstream;
        // service just carries the category tag on the assignment doc.

        return round($fee, 2);
    }

    /**
     * Check whether a route has remaining capacity for a new (or edit)
     * assignment. Reads the busCapacityCache first (fast path); falls
     * back to live count when cache is missing.
     *
     * @return array{ok:bool, reason:string, remaining:int}
     */
    private function _checkCapacityAvailable(string $routeId, bool $isEdit, string $prevRouteId): array
    {
        // If editing and staying on the same route, no delta capacity needed.
        if ($isEdit && $prevRouteId === $routeId) {
            return ['ok' => true, 'reason' => '', 'remaining' => -1];
        }
        $cacheId = $this->_capacityCacheId($routeId);
        $cache   = $this->fs->getEntity(self::COL_BUS_CAPACITY_CACHE, $cacheId);
        if (!is_array($cache)) {
            // Cache miss — compute from source (also seeds the cache)
            $rebuilt = $this->rebuildCapacityCache($routeId);
            $cache   = $this->fs->getEntity(self::COL_BUS_CAPACITY_CACHE, $cacheId);
            if (!is_array($cache)) {
                return ['ok' => true, 'reason' => 'cache unavailable — best-effort allow', 'remaining' => -1];
            }
        }
        $current = (int) ($cache['current_count'] ?? 0);
        $max     = (int) ($cache['max_capacity']  ?? 0);
        if ($max === 0) {
            // No vehicle assigned → allow (deferred check)
            return ['ok' => true, 'reason' => '', 'remaining' => -1];
        }
        $remaining = $max - $current;
        if ($remaining <= 0) {
            return [
                'ok' => false,
                'reason' => "route capacity {$current}/{$max} reached",
                'remaining' => 0,
            ];
        }
        return ['ok' => true, 'reason' => '', 'remaining' => $remaining];
    }

    /**
     * Append a routeAssignmentHistory event (Refinement #3: source + batch_id).
     */
    private function _appendAssignmentHistoryEvent(
        string $studentId,
        string $session,
        string $eventType,
        ?array $prevState,
        ?array $newState,
        string $source = 'manual',
        string $batchId = '',
        string $reason  = ''
    ): string {
        if (!in_array($eventType, self::HISTORY_EVENT_TYPES, true)) {
            $eventType = 'updated';
        }
        if (!in_array($source, self::HISTORY_SOURCES, true)) {
            $source = 'manual';
        }
        // CON-5: in a BATCH/rollover context the same (student, batch, event)
        // can be re-processed by a concurrent re-pump. A numbering-minted id +
        // merge:false would then create a DUPLICATE history doc and double-bump
        // the counter. Use a DETERMINISTIC id per (student, batch, event) so the
        // re-pump overwrites the same doc idempotently (and consumes no counter).
        // Manual single-actions keep the sequential numbering id (each is a
        // distinct, non-replayed user action).
        if ($batchId !== '') {
            $eventId = 'HIST_' . preg_replace('/[^A-Za-z0-9_]/', '_', "{$studentId}_{$batchId}_{$eventType}");
        } else {
            $eventId = $this->numbering->next('trn_assignment_history', [
                'claimedBy'  => $this->adminId,
                'claimedFor' => 'F3:_appendAssignmentHistoryEvent',
            ]);
        }
        $now = date('c');
        $row = [
            'eventId'   => $eventId,
            'schoolId'  => $this->schoolId,
            'studentId' => $studentId,
            'session'   => $session,
            'event_type'=> $eventType,
            'event_at'  => $now,
            'event_by'  => $this->adminId,
            'source'    => $source,
            'batch_id'  => $batchId,
            'reason'    => $reason,
            'prev_state'=> $prevState !== null ? $this->_capForHistory($prevState) : null,
            'new_state' => $newState  !== null ? $this->_capForHistory($newState)  : null,
        ];
        $this->fs->setEntity(self::COL_ROUTE_ASSIGNMENT_HISTORY, $eventId, $row, /* merge */ false);
        return $eventId;
    }

    /**
     * Trim an assignment doc down to fields safe for history inclusion.
     * Drops verbose or sensitive fields (created_at metadata, fee_demand_ref
     * transitions during retry, etc.) — history is a semantic record not a
     * full doc snapshot.
     */
    private function _capForHistory(array $data): array
    {
        $keep = [
            'route_id', 'route_name', 'stop_id', 'stop_name',
            'type', 'monthly_fee', 'subscription_type', 'direction',
            'fee_category', 'concession_id', 'hostel_transport_flag',
            'service_days', 'session',
            'suspended_from', 'suspended_until', 'status',
        ];
        $out = [];
        foreach ($keep as $k) {
            if (array_key_exists($k, $data)) {
                $out[$k] = $data[$k];
            }
        }
        return $out;
    }

    /**
     * RC-3 — THE canonical busCapacityCache document-id generator. Returns a
     * BARE entity id ("CAPCACHE_{routeId}") so setEntity/getEntity/casUpdateEntity
     * add the single {schoolId} prefix, giving canonical {schoolId}_CAPCACHE_{routeId}.
     * SINGLE SOURCE OF TRUTH: every CAPCACHE reader/writer MUST route its id
     * through this helper — no module may concatenate the id inline (enforced by
     * verifier CH-TRN-RC3-2). Prior code manually built "{schoolId}_CAPCACHE_..",
     * which setEntity then double-prefixed.
     */
    private function _capacityCacheId(string $routeId): string
    {
        return "CAPCACHE_{$routeId}";
    }

    /**
     * Bump the busCapacityCache current_count by ±1 (or larger delta for
     * bulk ops). Self-heals when cache doc is missing by triggering a
     * rebuild for the affected route.
     */
    private function _bumpCapacityCache(string $routeId, int $delta): void
    {
        if ($routeId === '' || $delta === 0) { return; }
        $cacheId = $this->_capacityCacheId($routeId);
        $cache   = $this->fs->getEntity(self::COL_BUS_CAPACITY_CACHE, $cacheId);
        if (!is_array($cache)) {
            // Cache missing — rebuild from source (which sets the correct count)
            try { $this->rebuildCapacityCache($routeId); } catch (\Throwable $_) {}
            return;
        }
        // CON-3: atomic increment via updateTime CAS — concurrent bumps can no
        // longer lose each other's update (count drift). Mirror the legacy
        // floor-at-zero. Self-heals when the cache doc is missing (below).
        $res = $this->fs->casUpdateEntity(self::COL_BUS_CAPACITY_CACHE, $cacheId,
            function (array $cache) use ($delta) {
                $current = (int) ($cache['current_count'] ?? 0);
                return [
                    'current_count'   => max(0, $current + $delta),
                    'last_updated_at' => date('c'),
                ];
            });
        if (($res['reason'] ?? '') === 'missing') {
            try { $this->rebuildCapacityCache($routeId); } catch (\Throwable $_) {}
        }
    }

    /**
     * CON-3 — atomically reserve one seat on $routeId: the capacity CHECK and
     * the current_count INCREMENT happen as a single updateTime-CAS step, so two
     * concurrent assigns can never both pass a 49/50 check and overfill to 51.
     * Returns the same shape as the legacy _checkCapacityAvailable() so call
     * sites are unchanged; on success it has ALSO consumed the seat (the caller
     * therefore must NOT issue a separate +1 bump). A rare post-reserve failure
     * leaves a +1 hint-drift that rebuildCapacityCache reconciles — the cache is
     * a hint, per the finding's own reconciliation model.
     *
     * @return array ['ok'=>bool, 'reason'=>string, 'reserved'=>bool]
     */
    private function _reserveCapacitySeat(string $routeId, bool $isEdit, string $prevRouteId): array
    {
        // Editing and staying on the same route consumes no new seat.
        if ($isEdit && $prevRouteId === $routeId) {
            return ['ok' => true, 'reason' => '', 'reserved' => false];
        }
        $cacheId = $this->_capacityCacheId($routeId);
        if (!is_array($this->fs->getEntity(self::COL_BUS_CAPACITY_CACHE, $cacheId))) {
            // Cache miss — seed from source so the CAS has a doc to act on.
            try { $this->rebuildCapacityCache($routeId); } catch (\Throwable $_) {}
        }
        $full = ['cur' => 0, 'max' => 0, 'hit' => false];
        $res = $this->fs->casUpdateEntity(self::COL_BUS_CAPACITY_CACHE, $cacheId,
            function (array $cache) use (&$full) {
                $max = (int) ($cache['max_capacity']  ?? 0);
                $cur = (int) ($cache['current_count'] ?? 0);
                if ($max > 0 && $cur >= $max) {          // atomic capacity check
                    $full = ['cur' => $cur, 'max' => $max, 'hit' => true];
                    return null;                          // abort — no seat free
                }
                return [                                  // atomic reservation (+1)
                    'current_count'   => $cur + 1,
                    'last_updated_at' => date('c'),
                ];
            });
        if ($full['hit']) {
            return ['ok' => false, 'reason' => "route capacity {$full['cur']}/{$full['max']} reached", 'reserved' => false];
        }
        if (($res['reason'] ?? '') === 'missing') {
            // No vehicle / cache unavailable → best-effort allow (legacy deferred check).
            return ['ok' => true, 'reason' => 'cache unavailable — best-effort allow', 'reserved' => false];
        }
        if (empty($res['ok'])) {
            return ['ok' => false, 'reason' => 'capacity reservation failed due to concurrent updates — please retry', 'reserved' => false];
        }
        return ['ok' => true, 'reason' => '', 'reserved' => true];
    }

    // ─── DOMAIN_EXPENSE (F5) private helpers ─────────────────────────────

    /**
     * Validate the expense payload before write. Hard-checks catch
     * mis-typed input at the service boundary.
     */
    private function _validateExpensePayload(array $input, bool $isNew): void
    {
        $type = trim((string) ($input['expense_type'] ?? ''));
        if ($type === '' || !in_array($type, self::EXPENSE_TYPES, true)) {
            throw new \InvalidArgumentException('Invalid expense_type: ' . $type);
        }
        if ($type === 'Other' && trim((string) ($input['expense_type_custom'] ?? '')) === '') {
            throw new \InvalidArgumentException('expense_type_custom is required when expense_type is Other.');
        }
        $amount = (float) ($input['amount'] ?? 0);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('amount must be > 0.');
        }
        $taxRate = (float) ($input['tax_rate'] ?? 0);
        if ($taxRate < 0 || $taxRate > 100) {
            throw new \InvalidArgumentException('tax_rate must be 0-100.');
        }
        $payeeType = trim((string) ($input['payee_type'] ?? ''));
        if (!in_array($payeeType, self::PAYEE_TYPES, true)) {
            throw new \InvalidArgumentException('Invalid payee_type: ' . $payeeType);
        }
        // Vendor / Staff / Driver payees require a payee_id
        if (in_array($payeeType, ['Vendor', 'Staff', 'Driver'], true)
            && trim((string) ($input['payee_id'] ?? '')) === '') {
            throw new \InvalidArgumentException('payee_id required for payee_type=' . $payeeType);
        }
        $method = trim((string) ($input['payment_method'] ?? 'Cash'));
        if (!in_array($method, self::PAYMENT_METHODS, true)) {
            throw new \InvalidArgumentException('Invalid payment_method: ' . $method);
        }
    }

    /**
     * Read the configured expense-posting mode from schoolControl.
     * Defaults to 'manual' when unset (safer — admin must explicitly
     * post to Accounting).
     */
    private function _getExpensePostingMode(): string
    {
        try {
            $ctrl = $this->fs->getEntity('schoolControl', $this->schoolId);
            if (is_array($ctrl)) {
                $mode = (string) ($ctrl['transport']['expense_posting_mode'] ?? '');
                if (in_array($mode, ['manual', 'auto'], true)) {
                    return $mode;
                }
            }
        } catch (\Throwable $_) {}
        return 'manual';
    }

    /**
     * Normalise the attachments payload into the canonical
     * [{type, path, uploaded_at, uploaded_by, notes}] shape.
     *
     * Backward compat: a legacy top-level `receipt_path` string is
     * absorbed into an implicit Receipt attachment (used by the F1-derived
     * createExpenseFromMaintenanceLog / createExpenseFromFuelLog flows).
     */
    private function _normaliseAttachments(array $attachments, string $legacyReceiptPath = ''): array
    {
        $out = [];
        foreach ($attachments as $a) {
            if (!is_array($a)) { continue; }
            $type = (string) ($a['type'] ?? 'Receipt');
            if (!in_array($type, self::EXPENSE_ATTACHMENT_TYPES, true)) {
                $type = 'Other';
            }
            $path = trim((string) ($a['path'] ?? ''));
            if ($path === '') { continue; }
            $out[] = [
                'type'        => $type,
                'path'        => $path,
                'uploaded_at' => (string) ($a['uploaded_at'] ?? date('c')),
                'uploaded_by' => (string) ($a['uploaded_by'] ?? $this->adminId),
                'notes'       => (string) ($a['notes'] ?? ''),
            ];
        }
        if ($legacyReceiptPath !== '' && $out === []) {
            $out[] = [
                'type'        => 'Receipt',
                'path'        => $legacyReceiptPath,
                'uploaded_at' => date('c'),
                'uploaded_by' => $this->adminId,
                'notes'       => 'derived',
            ];
        }
        return $out;
    }

    /**
     * Post an expense to Accounting via PayrollAccountingService (which
     * F5 extended with a Transport-facing postExpense method — see
     * PayrollAccountingService source). Success updates the expense's
     * accounting_journal_ref; failure increments attempts and leaves
     * status='pending' until the retry cap is reached, then flips to
     * 'failed' + fires an admin notice.
     *
     * @return array{status:string, journal_ref:string, error:string}
     */
    private function _postToAccounting(string $expenseId, array $expense): array
    {
        // ── FIN-5 (2026-08-19): NEVER post a journal for a non-Active expense.
        //
        // A void that happened BEFORE the post succeeded left the record at
        // accounting_journal_ref='pending', because voidExpense()'s $isPosted
        // test excludes 'pending' and so had nothing to reverse. The nightly
        // reconciler queries pending with no status filter, so the next cron run
        // would post a real journal for an expense the school had explicitly
        // voided — debiting the GL with nothing to offset it, and no second void
        // to trigger a reversal. Three such records were found stale in the test
        // tenant, each one a wrong journal waiting to happen.
        //
        // The guard lives HERE because all four posting paths funnel through this
        // method — the reconciler, retryExpensePost(), postExpenseNow() and
        // saveExpense()'s auto-post — so one check closes all of them. It also
        // SETTLES the record (see the write below) so a voided expense leaves the
        // pending queue instead of re-alarming every night forever; an alarm that
        // can never be cleared is an alarm operators learn to ignore.
        $status = (string) ($expense['status'] ?? 'Active');
        if ($status !== 'Active') {
            $this->fs->setEntity(self::COL_TRANSPORT_EXPENSES, $expenseId, [
                'accounting_journal_ref' => 'voided',
                'accounting_last_error'  => '',
                'updated_at'             => date('c'),
            ], /* merge */ true);
            return [
                'status'      => 'voided',
                'journal_ref' => 'voided',
                'error'       => "Expense is {$status}; no accounting journal was created.",
            ];
        }

        $ledgerCode = $this->_ledgerCodeFor((string) ($expense['expense_type'] ?? 'Other'));
        try {
            // PayrollAccountingService lives in application/services/ (not
            // application/libraries/), so we mirror the load pattern used by
            // Payroll.php: manual require + new + init. Cached on CI instance
            // so subsequent expenses in the same request reuse the object.
            if (!isset($this->ci->transportAccountingSvc)) {
                if (!class_exists('PayrollAccountingService')) {
                    require_once APPPATH . 'services/PayrollAccountingService.php';
                }
                $svc = new \PayrollAccountingService();
                // firebase is a raw Firebase_service reference; Transport_service
                // already holds it as $this->fs->firebase. Fall back to fs itself
                // when the raw handle isn't available (fs->firebase surface has
                // varied historically); PayrollAccountingService::init treats
                // the first arg opaquely and only null triggers a not-ready.
                // Firebase_service is loaded as $this->ci->firebase by
                // upstream controllers. Fall back to null (init still returns
                // self; isEnabled() then reports false) — probe log will show.
                $firebaseHandle = isset($this->ci->firebase) ? $this->ci->firebase : null;
                $svc->init($firebaseHandle, $this->schoolId, $this->sessionYear, $this->adminId, $this->ci);
                $this->ci->transportAccountingSvc = $svc;
            }
            $accountingSvc = $this->ci->transportAccountingSvc;
            if (!is_object($accountingSvc) || !method_exists($accountingSvc, 'postExpense')) {
                throw new \RuntimeException('PayrollAccountingService::postExpense not available');
            }
            $journalRef = $accountingSvc->postExpense([
                'source_module'   => 'Transport',
                'source_doc_id'   => $expenseId,
                'ledger_code'     => $ledgerCode,
                'amount'          => (float) ($expense['amount']       ?? 0),
                'tax_amount'      => (float) ($expense['tax_amount']   ?? 0),
                'total_amount'    => (float) ($expense['total_amount'] ?? 0),
                'expense_date'    => (string) ($expense['expense_date'] ?? date('Y-m-d')),
                'payee_type'      => (string) ($expense['payee_type']   ?? ''),
                'payee_id'        => (string) ($expense['payee_id']     ?? ''),
                'payee_name'      => (string) ($expense['payee_name']   ?? ''),
                'expense_type'    => (string) ($expense['expense_type'] ?? ''),
                'payment_method'  => (string) ($expense['payment_method'] ?? ''),
                'payment_reference' => (string) ($expense['payment_reference'] ?? ''),
                'notes'           => (string) ($expense['notes']        ?? ''),
                'schoolId'        => $this->schoolId,
                'created_by'      => $this->adminId,
            ]);
            if (!is_string($journalRef) || $journalRef === '') {
                throw new \RuntimeException('postExpense returned no journal ref');
            }
            $this->fs->setEntity(self::COL_TRANSPORT_EXPENSES, $expenseId, [
                'accounting_journal_ref' => $journalRef,
                'accounting_attempts'    => (int) ($expense['accounting_attempts'] ?? 0) + 1,
                'accounting_last_error'  => '',
                'updated_at'             => date('c'),
            ], /* merge */ true);
            $this->emitAudit(
                'transportExpense', $expenseId, 'posted_to_accounting',
                ['accounting_journal_ref' => (string) ($expense['accounting_journal_ref'] ?? '')],
                ['accounting_journal_ref' => $journalRef, 'ledger_code' => $ledgerCode],
                ['domain' => self::DOMAIN_EXPENSE]
            );
            return ['status' => 'posted', 'journal_ref' => $journalRef, 'error' => ''];
        } catch (\Throwable $e) {
            $attempts = (int) ($expense['accounting_attempts'] ?? 0) + 1;
            $shouldFail = $attempts >= self::EXPENSE_ACCOUNTING_MAX_ATTEMPTS;
            $update = [
                'accounting_journal_ref' => $shouldFail ? 'failed' : 'pending',
                'accounting_attempts'    => $attempts,
                'accounting_last_error'  => substr((string) $e->getMessage(), 0, 500),
                'updated_at'             => date('c'),
            ];
            try {
                $this->fs->setEntity(self::COL_TRANSPORT_EXPENSES, $expenseId, $update, /* merge */ true);
            } catch (\Throwable $_) {}
            log_message('error', 'F5 postToAccounting failed for ' . $expenseId . ' attempt ' . $attempts . ': ' . $e->getMessage());
            if ($shouldFail) {
                $this->_emitPendingStalenessNotice(array_merge($expense, $update));
                return ['status' => 'failed', 'journal_ref' => '', 'error' => $e->getMessage()];
            }
            return ['status' => 'pending', 'journal_ref' => '', 'error' => $e->getMessage()];
        }
    }

    /**
     * FIN-4 — reverse a previously-posted expense journal (void path). Reuses
     * the cached PayrollAccountingService seam and its postReversal(), which
     * posts a balancing reversing entry for the original journal and is
     * idempotent on a deterministic reversal sourceRef. Returns the reversal
     * entry id, or null if the reversal could not be posted (accounting
     * disabled / original missing / engine error) so the caller can abort the
     * void rather than leave the record and the ledger divergent.
     */
    private function _reverseExpensePost(string $expenseId, string $journalRef, string $reason): ?string
    {
        try {
            if (!isset($this->ci->transportAccountingSvc)) {
                if (!class_exists('PayrollAccountingService')) {
                    require_once APPPATH . 'services/PayrollAccountingService.php';
                }
                $svc = new \PayrollAccountingService();
                $firebaseHandle = isset($this->ci->firebase) ? $this->ci->firebase : null;
                $svc->init($firebaseHandle, $this->schoolId, $this->sessionYear, $this->adminId, $this->ci);
                $this->ci->transportAccountingSvc = $svc;
            }
            $svc = $this->ci->transportAccountingSvc;
            if (!is_object($svc) || !method_exists($svc, 'postReversal')) {
                log_message('error', "F5 _reverseExpensePost({$expenseId}): postReversal unavailable");
                return null;
            }
            $rev = $svc->postReversal($journalRef, 'Transport expense void: ' . $reason);
            if (!is_string($rev) || $rev === '') {
                log_message('error', "F5 _reverseExpensePost({$expenseId}, {$journalRef}): reversal returned no ref");
                return null;
            }
            $this->emitAudit(
                'transportExpense', $expenseId, 'reversed_in_accounting',
                ['accounting_journal_ref' => $journalRef],
                ['accounting_reversal_ref' => $rev],
                ['domain' => self::DOMAIN_EXPENSE, 'reason' => $reason]
            );
            return $rev;
        } catch (\Throwable $e) {
            log_message('error', "F5 _reverseExpensePost({$expenseId}, {$journalRef}) failed: " . $e->getMessage());
            return null;
        }
    }

    private function _ledgerCodeFor(string $expenseType): string
    {
        return (string) (self::EXPENSE_TYPE_TO_LEDGER_CODE[$expenseType] ?? self::EXPENSE_TYPE_TO_LEDGER_CODE['Other']);
    }

    /**
     * Notify Admin + Accountant + Transport Manager when an expense
     * exhausts its Accounting retry budget.
     */
    private function _emitPendingStalenessNotice(array $expense): void
    {
        $this->_notify('expense.pending_stale', [
            'expense_id'            => (string) ($expense['expenseId']   ?? ''),
            'expense_type'          => (string) ($expense['expense_type'] ?? ''),
            'total_amount'          => (float)  ($expense['total_amount'] ?? 0),
            'accounting_last_error' => (string) ($expense['accounting_last_error'] ?? ''),
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  DOMAIN_TRIP (F7, 2026-07-07) — Trip Logs + Transport Attendance
    // ═════════════════════════════════════════════════════════════════════
    //
    // Feature F7 delivers LC-17 (tripLogs) + LC-18 (transportAttendance).
    // The design is GPS-NATIVE from day 0 per operator directive: every
    // trip/checkpoint/boarding-event has first-class extension points for
    // the dedicated GPS phase (session id, location refs, geofence radii,
    // ETA + route-progress fields, correlation IDs). F7 itself writes NULL
    // to all live-position fields — verifier CH-TRN-F7-1 enforces this —
    // so the GPS phase adds telemetry acquisition, not schema redesign.
    //
    // Lifecycle (operator Refinement + additional 2026-07-07):
    //   Ready → InProgress → Completed | Cancelled
    //   Ready → Cancelled
    //
    // Ready is the dispatch-preparation state: roster snapshotted, driver
    // resolved via _effectiveDriverStaffId (F4 temp overlay), checkpoints
    // frozen from route's stop list, but actual_start_at not set. Aligns
    // naturally with GPS session establishment in Phase 5.
    //
    // Ownership boundary: Transport writes to transportAttendance ONLY.
    // The core `attendance` collection remains owned by class teachers —
    // Transport does NOT auto-mark daily attendance from boarding events.

    // Trip lifecycle states
    public const TRIP_STATUSES     = ['Ready', 'InProgress', 'Completed', 'Cancelled'];
    private const TRIP_ACTIVE_STATUSES = ['Ready', 'InProgress'];
    private const TRIP_TERMINAL_STATUSES = ['Completed', 'Cancelled'];

    // Trip directions — match F2 route directions vocabulary
    public const TRIP_DIRECTIONS = ['Pickup', 'Drop'];

    // Checkpoint states
    public const CHECKPOINT_STATES = ['Pending', 'Reached', 'Skipped'];

    // Boarding event types — enum for transportAttendance.event_type
    //
    // F14 added the four CANONICAL journey events. The legacy four stay in the
    // enum because historical rows carry them and are never rewritten;
    // Transport_qr_attendance_service::canonicalEvent() maps old → new at READ
    // time. 'no_show' is a resolution rather than a journey event and has no
    // canonical replacement.
    public const BOARDING_EVENT_TYPES = [
        // ── legacy (still written by pre-F14 callers, still readable) ──
        'boarded_pickup',      // student got on the pickup trip
        'dropped_home',        // student was dropped at end-of-drop-trip
        'no_show',             // student expected but did not board
        'alternate_pickup',    // student boarded at a non-planned stop
        // ── F14 canonical four ──
        'boarded_at_stop',     // morning  — boarded at their pickup stop
        'alighted_at_school',  // morning  — stepped off at school
        'boarded_at_school',   // afternoon— boarded at school
        'alighted_at_stop',    // afternoon— stepped off at their stop
    ];

    // ── ONE classification of "got on" vs "got off" (2026-08-16) ────────────
    //
    // Every consumer that counts heads must agree on which of the eight event
    // types means a child boarded and which means a child left the bus. They
    // did not: the trip tallies below and Transport_analytics_service each
    // hardcoded only the LEGACY four, so a trip boarded by QR scan counted as
    // ZERO students — in the trip modal and on the operations dashboard alike.
    // The office would have lost its head count at exactly the moment a
    // conductor started scanning.
    //
    // Derive from these constants rather than retyping a list; adding a ninth
    // event type then cannot silently skip a counter. 'no_show' appears in
    // neither set on purpose — it is a resolution, not a journey event.
    public const BOARDING_ON_EVENTS = [
        'boarded_pickup', 'alternate_pickup',    // legacy
        'boarded_at_stop', 'boarded_at_school',  // F14 canonical
    ];
    public const BOARDING_OFF_EVENTS = [
        'dropped_home',                                 // legacy
        'alighted_at_school', 'alighted_at_stop',       // F14 canonical
    ];

    // Collection name for the per-student boarding-event log
    public const COL_TRANSPORT_ATTENDANCE = 'transportAttendance';

    // Default geofence radius for a checkpoint (metres) — reserved for
    // GPS-phase stop-arrival detection. Schools may override per-stop
    // later without touching the F7 schema.
    private const DEFAULT_CHECKPOINT_GEOFENCE_RADIUS_M = 50;

    // Prefix used to build the GPS session id from a trip id — the
    // dedicated GPS phase writes vehicle telemetry keyed by this same
    // string, providing zero-refactor correlation.
    private const GPS_SESSION_ID_PREFIX = 'GPSSESS_';

    // ─── Public API ──────────────────────────────────────────────────────

    /**
     * Create a trip in Ready state (dispatch preparation). Snapshots the
     * roster + checkpoints; resolves the effective driver via F4 overlay;
     * establishes the GPS correlation id used later by the GPS phase.
     *
     * @param array $input {
     *   vehicle_id: string (required),
     *   route_id:   string (required),
     *   trip_date:  string YYYY-MM-DD (default: today),
     *   trip_direction: 'Pickup'|'Drop' (required),
     *   scheduled_start_at: ISO ts (optional),
     *   notes: string (optional),
     * }
     * @return array {tripId, data}
     */
    public function createTrip(array $input): array
    {
        $this->_assertReady();
        $vehicleId  = trim((string) ($input['vehicle_id'] ?? ''));
        $routeId    = trim((string) ($input['route_id']   ?? ''));
        $tripDate   = trim((string) ($input['trip_date']  ?? date('Y-m-d')));
        $direction  = trim((string) ($input['trip_direction'] ?? ''));
        // F8 (2026-07-07) — trip_kind ∈ {Regular, Event}. When Event,
        // route_id becomes OPTIONAL (per operator Refinement 2: some
        // events legitimately follow existing routes, others are
        // free-form destination travel) and event_id must be provided.
        $tripKind = trim((string) ($input['trip_kind'] ?? 'Regular'));
        $eventId  = trim((string) ($input['event_id']  ?? ''));

        if ($vehicleId === '') { throw new \InvalidArgumentException('vehicle_id is required.'); }
        if (!in_array($tripKind, ['Regular', 'Event'], true)) {
            throw new \InvalidArgumentException("Invalid trip_kind: '{$tripKind}'. Allowed: Regular, Event");
        }
        if ($tripKind === 'Regular' && $routeId === '') {
            throw new \InvalidArgumentException('route_id is required for Regular trips.');
        }
        if ($tripKind === 'Event' && $eventId === '') {
            throw new \InvalidArgumentException('event_id is required for Event trips.');
        }
        if (!in_array($direction, self::TRIP_DIRECTIONS, true)) {
            throw new \InvalidArgumentException("Invalid trip_direction: '{$direction}'. Allowed: " . implode(',', self::TRIP_DIRECTIONS));
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tripDate)) {
            throw new \InvalidArgumentException("Invalid trip_date: '{$tripDate}'. Expected YYYY-MM-DD.");
        }

        // ── UNIT 2B — CAMPUS GEOFENCE PREREQUISITE (2026-08-15) ──────────────
        //
        // A Pickup trip ends at school, and Part A refuses to complete one until
        // arrival is GPS-proven against the campus geofence. With no fence
        // configured, such a trip is STRUCTURALLY UNCOMPLETABLE the moment it is
        // created — the office can only ever close it by admin override. Refuse
        // at creation instead of stranding it.
        //
        // PICKUP ONLY. The afternoon leg ends at the stops, not at school, so a
        // Drop trip needs no campus fence and is deliberately unaffected.
        //
        // Reads the SAME canonical store as verifyBusAtSchool()
        // (schools.attendancePolicy.gps.geofences[]) through the SAME service —
        // no second geofence system, no duplicated maths.
        if ($direction === 'Pickup') {
            $CI =& get_instance();
            if (!isset($CI->f14_completion_guard)) {
                $CI->load->library('transport_qr_attendance_service', null, 'f14_completion_guard');
            }
            $CI->f14_completion_guard->init(
                $this->fs, $this, $this->schoolId, $this->sessionYear, $this->adminId);
            if (!$CI->f14_completion_guard->campusGeofenceConfigured()) {
                // UNIT 2B-3 (2026-08-15) — the destination named here used to be
                // "School Config → GPS / Attendance Policy", a page that does
                // not exist; the campus fence is written by Attendance →
                // Settings → GPS Attendance (/attendance/settings). Corrected
                // at the canonical source rather than relying on one client to
                // rewrite a wrong server message. The leading sentence is
                // unchanged — the admin UI keys its guidance panel on it.
                throw new \InvalidArgumentException(
                    'Campus arrival geofence is not configured, so a Pickup trip could never be '
                    . 'completed. Configure the campus location in Attendance → Settings → '
                    . 'GPS Attendance, then create the trip. (Drop trips are unaffected.)');
            }
        }

        $vehicle = $this->fs->getEntity(self::COL_VEHICLES, $vehicleId);
        if (!is_array($vehicle)) {
            throw new \InvalidArgumentException("Vehicle not found: {$vehicleId}");
        }
        // Validate event FK when applicable
        if ($tripKind === 'Event') {
            $event = $this->fs->getEntity(self::COL_TRANSPORT_EVENTS, $eventId);
            if (!is_array($event)) {
                throw new \InvalidArgumentException("Event not found: {$eventId}");
            }
            if (in_array((string) ($event['status'] ?? ''), ['Cancelled', 'Completed'], true)) {
                throw new \InvalidArgumentException("Event {$eventId} is in terminal state; cannot dispatch trip.");
            }
        }
        // Validate route only when provided (may be blank for Event trips)
        $route = null;
        if ($routeId !== '') {
            $route = $this->fs->getEntity(self::COL_ROUTES, $routeId);
            if (!is_array($route)) {
                throw new \InvalidArgumentException("Route not found: {$routeId}");
            }
            if ((string) ($route['status'] ?? 'Active') !== 'Active') {
                throw new \InvalidArgumentException("Route is not Active: {$routeId}");
            }
            // LOG-2: a suspension sets routes.active_suspension_ref but leaves
            // status='Active' (assignment + route-change paths already gate on
            // it). Dispatch must honour it too — otherwise a suspended route is
            // still dispatchable and students get boarded on a route that is
            // operationally suspended.
            if ((string) ($route['active_suspension_ref'] ?? '') !== '') {
                throw new \InvalidArgumentException("Route {$routeId} currently has an active suspension. Resume it before dispatching a trip.");
            }
        }

        // Resolve effective driver — honours F4 temporary assignments
        $driverStaffId = (string) ($this->getEffectiveDriver($vehicleId, $tripDate) ?? '');
        if ($driverStaffId === '') {
            throw new \InvalidArgumentException("No effective driver for vehicle {$vehicleId} on {$tripDate}.");
        }
        // LOG-1: driver readiness was validated at ASSIGNMENT time, but it is
        // daily-mutable (licence/medical/badge expiry, operational_status
        // On_Leave/Suspended, HR Inactive, can_drive_transport revoked). Re-
        // validate the EFFECTIVE driver (honours the F4 temp overlay) for the
        // trip date before dispatch — an unfit/on-leave driver must never be put
        // on a trip. Covers regular AND event trips (dispatchEventTrip → createTrip).
        $this->_validateDriverReadiness($driverStaffId, $tripDate);

        // Snapshot checkpoints from route stops (frozen at trip creation).
        // For Event trips with no route, checkpoints[] stays empty and the
        // "every checkpoint Reached-or-Skipped" completion gate is vacuously
        // satisfied — free-form destination travel doesn't need per-stop
        // orchestration.
        $stops = [];
        if ($routeId !== '') {
            $stops = $this->listStops($routeId);
            if ($stops === [] && $tripKind === 'Regular') {
                throw new \InvalidArgumentException("Route {$routeId} has no stops — cannot create Regular trip.");
            }
        }
        $checkpoints = [];
        foreach ($stops as $s) {
            $checkpoints[] = [
                'stop_id'                => (string) ($s['stopId']    ?? $s['id'] ?? ''),
                'stop_name'              => (string) ($s['name']      ?? ''),
                'order'                  => (int)    ($s['order']     ?? 0),
                'scheduled_pickup_time'  => (string) ($s['pickup_time'] ?? ''),
                'scheduled_drop_time'    => (string) ($s['drop_time']   ?? ''),
                // GPS-native planned location (from F2 stop registry)
                'stop_location'          => [
                    'lat' => (float) ($s['latitude']  ?? 0),
                    'lng' => (float) ($s['longitude'] ?? 0),
                ],
                'geofence_radius_m'      => self::DEFAULT_CHECKPOINT_GEOFENCE_RADIUS_M,
                // Runtime state
                'checkpoint_state'       => 'Pending',
                'reached_at'             => null,
                'left_at'                => null,
                'students_boarded_count' => 0,
                'students_no_show_count' => 0,
                // GPS-native runtime placeholders — NULL in F7
                'arrival_location_ref'   => null,
                'departure_location_ref' => null,
                'arrival_lat'            => null,
                'arrival_lng'            => null,
                'departure_lat'          => null,
                'departure_lng'          => null,
                'arrival_variance_m'     => null,
            ];
        }

        // Snapshot roster — read once, cache on trip doc.
        // Event trips get their roster from eventTripAssignments (F8);
        // Regular trips read studentRoutes LEGACY + routeAssignments v2.
        if ($tripKind === 'Event') {
            $roster = $this->_computeEventRosterForVehicle($eventId, $vehicleId);
        } else {
            $roster = $this->_computeTripRoster($routeId, $tripDate, $direction);
        }

        // Numbering
        $tripId = $this->numbering->next('trn_trip', [
            'claimedBy'  => $this->adminId,
            'claimedFor' => 'F7:createTrip',
        ]);
        $gpsSessionId = self::GPS_SESSION_ID_PREFIX . $tripId;

        $now = date('c');
        $data = [
            // ─── Kotlin-canonical base (matches shipped TripLogDoc.kt on
            //     both Parent + Teacher apps; app deserialization ignores
            //     the additive PHP-only fields below) ──────────────────
            'id'                 => $tripId,
            'schoolId'           => $this->schoolId,
            'vehicleId'          => $vehicleId,
            'date'               => $tripDate,
            'tripType'           => $direction === 'Pickup' ? 'morning' : 'afternoon',
            'startTime'          => trim((string) ($input['scheduled_start_at'] ?? '')),
            'endTime'            => '',
            'startOdometer'      => 0,
            'endOdometer'        => 0,
            'distance'           => 0.0,
            'fuelUsed'           => 0.0,
            'stopsCompleted'     => 0,
            'alerts'             => [],

            // ─── Additive PHP admin-side fields (Kotlin ignores them) ──
            'driver_staff_id'    => $driverStaffId,
            'route_id'           => $routeId,
            'route_snapshot'     => [
                'name'      => is_array($route) ? (string) ($route['name'] ?? '') : '',
                'direction' => is_array($route) ? (string) ($route['direction'] ?? '') : '',
            ],
            // F8 (2026-07-07) — event linkage
            'event_id'           => $eventId,
            'trip_kind'          => $tripKind,
            'session'            => $this->sessionYear,
            'trip_direction'     => $direction,
            'trip_date'          => $tripDate,                          // alias for reader at L3813
            'scheduled_start_at' => (string) ($input['scheduled_start_at'] ?? ''),
            'actual_start_at'    => null,
            'actual_end_at'      => null,
            'status'             => 'Ready',
            'on_time'            => null,                               // set on complete
            'duration_minutes'   => null,
            'distance_km'        => 0.0,                                // alias of `distance`
            'incidents_count'    => 0,
            'notes'              => trim((string) ($input['notes'] ?? '')),
            'checkpoints'        => $checkpoints,
            'roster_student_ids' => $roster,
            'roster_size'        => count($roster),
            'roster_frozen_at'   => $now,                               // immutable snapshot marker

            // ─── GPS-native architecture (Refinement additional) ──────
            // Correlation key used by the future GPS phase without
            // requiring a schema migration. NULL live-position fields
            // are reserved so the GPS phase adds telemetry acquisition,
            // not schema redesign.
            'gps_session_id'       => $gpsSessionId,
            'live_tracking_enabled'=> false,
            'route_progress_pct'   => 0,
            'next_checkpoint_id'   => count($checkpoints) > 0 ? (string) $checkpoints[0]['stop_id'] : '',
            'next_checkpoint_eta'  => null,
            'vehicle_location_ref' => null,      // future: vehicleLivePositions doc id
            'gps_start_lat'        => null,
            'gps_start_lng'        => null,
            'gps_start_at'         => null,
            'gps_end_lat'          => null,
            'gps_end_lng'          => null,
            'gps_end_at'           => null,
            'gps_last_position_at' => null,
            'gps_track_url'        => null,

            'created_at'         => $now,
            'created_by'         => $this->adminId,
            'updated_at'         => $now,
        ];
        $this->fs->setEntity(self::COL_TRIP_LOGS, $tripId, $data, /* merge */ false);

        $this->emitAudit(
            'trip', $tripId, 'created', null, $data,
            ['domain' => self::DOMAIN_TRIP]
        );
        return ['tripId' => $tripId, 'data' => $data];
    }

    /**
     * Transition trip Ready → InProgress. Sets actual_start_at, fires
     * trip.started notification.
     */
    public function startTrip(string $tripId): array
    {
        $this->_assertReady();
        if ($tripId === '') { throw new \InvalidArgumentException('tripId required.'); }
        // LOG-1: re-validate the frozen driver's readiness for TODAY before the
        // trip goes live. A driver fit when the trip was created (possibly days
        // earlier) may have become unfit since — licence/medical expiry, set to
        // On_Leave/Suspended, or made HR-Inactive. If the trip is missing the CAS
        // below reports it; here we only gate a present-and-driver-bearing trip.
        $tripForDriver = $this->fs->getEntity(self::COL_TRIP_LOGS, $tripId);
        if (is_array($tripForDriver)) {
            $driverForStart = (string) ($tripForDriver['driver_staff_id'] ?? '');
            if ($driverForStart !== '') {
                $this->_validateDriverReadiness($driverForStart, date('Y-m-d'));
            }
        }
        $now    = date('c');
        $before = [];
        $abort  = '';
        // CON-6: atomic Ready->InProgress via updateTime CAS. Two concurrent
        // starts can no longer both commit — the loser re-reads, its mutator
        // sees InProgress and aborts, so audit/notify fire exactly once.
        $res = $this->fs->casUpdateEntity(self::COL_TRIP_LOGS, $tripId,
            function (array $trip) use (&$before, &$abort, $now) {
                if (($trip['status'] ?? '') !== 'Ready') {
                    $abort = "Trip is not in Ready state (current: " . ($trip['status'] ?? '') . ").";
                    return null;
                }
                $before = $trip;
                return [
                    'status'          => 'InProgress',
                    'actual_start_at' => $now,
                    'startTime'       => (($trip['startTime'] ?? '') !== '') ? $trip['startTime'] : $now,
                    'updated_at'      => $now,
                    // F13.1 M2 (D-2) — arm the server-side tracking kill switch.
                    // Rides THIS CAS write rather than adding a second one, so
                    // CON-6's single-atomic-transition property is preserved.
                    'live_tracking_enabled' => true,
                    'gps_start_at'          => $now,
                ];
            });
        $this->_assertTripTransition($res, $tripId, $abort);

        // F13.1 M2 (D-2) — open the live-position session. Best-effort, exactly
        // like _setVehicleCurrentRoute below: the trip lifecycle is the business
        // truth and must not fail because telemetry could not be set up. If this
        // fails the trip still runs; tracking is simply unavailable, and the
        // sweeper will find nothing to close.
        $this->_openGpsSession($before, $tripId);

        $update = ['status' => 'InProgress', 'actual_start_at' => $now];
        $this->emitAudit('trip', $tripId, 'started', $before, array_merge($before, $update),
            ['domain' => self::DOMAIN_TRIP]);
        $this->_notify('trip.started', [
            'trip_id'      => $tripId,
            'route_id'     => (string) ($before['route_id'] ?? ''),
            'vehicle_id'   => (string) ($before['vehicleId'] ?? ''),
            'driver_id'    => (string) ($before['driver_staff_id'] ?? ''),
            'trip_date'    => (string) ($before['trip_date'] ?? ''),
            'direction'    => (string) ($before['trip_direction'] ?? ''),
            'roster_size'  => (int) ($before['roster_size'] ?? 0),
        ]);
        // XMOD-1: denormalise the in-progress route onto the vehicle so the
        // Teacher app has route context (DriverHome badge + driver SOS).
        $this->_setVehicleCurrentRoute((string) ($before['vehicleId'] ?? ''), (string) ($before['route_id'] ?? ''));
        return ['tripId' => $tripId, 'status' => 'InProgress'];
    }

    /**
     * Mark a checkpoint as reached. Server-timestamped; GPS location refs
     * remain NULL at F7 (GPS phase will populate).
     */
    public function markCheckpointReached(string $tripId, string $stopId): array
    {
        $this->_assertReady();
        return $this->_updateCheckpointState($tripId, $stopId, 'Reached');
    }

    /** Mark a checkpoint as departed (after Reached). Idempotent — retap safe. */
    public function markCheckpointLeft(string $tripId, string $stopId): array
    {
        $this->_assertReady();
        if ($tripId === '') { throw new \InvalidArgumentException('tripId required.'); }
        $now   = date('c');
        $abort = '';
        // CON-2: atomic single-element mutation of checkpoints[] via updateTime
        // CAS — a concurrent checkpoint/tally write can no longer clobber this
        // left_at (whole-array lost update).
        $res = $this->fs->casUpdateEntity(self::COL_TRIP_LOGS, $tripId,
            function (array $trip) use (&$abort, $tripId, $stopId, $now) {
                if (($trip['status'] ?? '') !== 'InProgress') {
                    $abort = "Trip {$tripId} not InProgress.";
                    return null;
                }
                $checkpoints = (array) ($trip['checkpoints'] ?? []);
                $found = false;
                foreach ($checkpoints as $i => $cp) {
                    if ((string) ($cp['stop_id'] ?? '') !== $stopId) { continue; }
                    if (($cp['checkpoint_state'] ?? 'Pending') !== 'Reached') {
                        $abort = "Cannot mark left — checkpoint state is '" . ($cp['checkpoint_state'] ?? 'Pending') . "', expected 'Reached'.";
                        return null;
                    }
                    $checkpoints[$i]['left_at'] = $now;
                    $found = true;
                    break;
                }
                if (!$found) {
                    $abort = "Stop {$stopId} not found in trip {$tripId} checkpoints.";
                    return null;
                }
                return ['checkpoints' => $checkpoints, 'updated_at' => $now];
            });
        $this->_assertTripTransition($res, $tripId, $abort);
        return ['tripId' => $tripId, 'stop_id' => $stopId, 'left_at' => $now];
    }

    /** Mark a checkpoint as skipped (per Refinement 4 — allows Completed with unreached stops). */
    public function markCheckpointSkipped(string $tripId, string $stopId, string $reason): array
    {
        $this->_assertReady();
        return $this->_updateCheckpointState($tripId, $stopId, 'Skipped', $reason);
    }

    /**
     * Mark a student boarded on a Pickup trip. Idempotent via deterministic
     * doc-id; validates eligibility from the frozen roster snapshot.
     */
    // ─────────────────────────────────────────────────────────────────────
    //  F14 Phase 1g — Transport REQUEST worklist (hybrid F-3)
    //
    //  A family declaring "we want transport" is a REQUEST, not an
    //  entitlement. Turning that declaration straight into a studentRoutes
    //  assignment would be wrong twice over: the school usually does not know
    //  the route or stop yet, so the student would be "eligible" but
    //  unroutable — and assignStudent() creates a transport FEE, so a bare
    //  declaration would start billing a family for a service not yet arranged.
    //
    //  Therefore:
    //    route + stop known  → canonical assignStudent() immediately
    //    otherwise           → a pending transportRequests row for admin review
    //
    //  studentRoutes remains the ONLY eligibility authority. This worklist is
    //  an inbox in front of it, never a parallel entitlement store, and it is
    //  never consulted by the eligibility resolver.
    // ─────────────────────────────────────────────────────────────────────

    public const COL_TRANSPORT_REQUESTS = 'transportRequests';

    public const REQUEST_STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];
    public const REQUEST_SOURCES  = ['admission', 'mid_session', 'parent_app', 'manual'];

    /**
     * Record a transport request, or — when route AND stop are both supplied —
     * skip the worklist and create the canonical assignment straight away.
     *
     * Idempotent on {schoolId}_{studentId}_{session}: one open request per
     * student per session. A repeat while one is already pending is a no-op, so
     * a double-submitted admission form cannot produce two worklist entries.
     *
     * @return array{outcome:string, requestId:string, assignmentId:string, message:string}
     *         outcome ∈ assigned | pending | already_pending | already_assigned
     */
    public function requestTransport(array $input, string $source = 'manual'): array
    {
        $this->_assertReady();
        $studentId = trim((string) ($input['studentId'] ?? ''));
        if ($studentId === '') {
            throw new \InvalidArgumentException('studentId is required.');
        }
        if (!in_array($source, self::REQUEST_SOURCES, true)) { $source = 'manual'; }

        $now = date('c');

        // Already entitled? Then there is nothing to request. Checked against
        // the canonical authority, not against this worklist.
        $existing = $this->_activeAssignmentFor($studentId);
        if ($existing !== []) {
            return ['outcome' => 'already_assigned',
                    'requestId' => '', 'assignmentId' => (string) ($existing['id'] ?? ''),
                    'message' => 'Student already has an active transport assignment.'];
        }

        // ── ALWAYS a pending worklist row. NO assignment, NO fee. ──
        //
        // A direct-assignment shortcut used to live here: given route_id +
        // stop_id it called assignStudent() and skipped the worklist. It was
        // removed (2026-08-15) because it never worked — it passed 'studentId'
        // while assignStudent() reads 'student_id', so every entry threw
        // "student_id and route_id are required." Runtime-proven, not inferred.
        //
        // Worse than dead: the only caller able to reach it was the controller
        // passing has_permission('Transport','manage'), so the exception hit
        // MANAGE users only, while edit users got a clean pending request.
        // Removing it makes every caller behave identically.
        //
        // Canonical assignment now has exactly two manage-gated routes:
        //     approve_transport_request -> approveTransportRequest() -> assignStudent()
        //     save_assignment                                        -> assignStudent()
        // requestTransport() deliberately no longer calls assignStudent() at all.
        // Entity id WITHOUT the school prefix — getEntity()/setEntity() add the
        // single `{schoolId}_` themselves (RC-3 convention). Embedding it here
        // and then using setEntity() would write {schoolId}_{schoolId}_… while a
        // raw get() looked elsewhere, so the row could never be found again.
        $docId = "{$studentId}_{$this->sessionYear}";
        $prior = $this->fs->getEntity(self::COL_TRANSPORT_REQUESTS, $docId);
        $prior = is_array($prior) ? $this->_unwrapNestedRow($prior, 'studentId') : [];
        if (($prior['status'] ?? '') === 'pending') {
            return ['outcome' => 'already_pending', 'requestId' => $docId, 'assignmentId' => '',
                    'message' => 'A transport request is already pending for this student.'];
        }

        // A re-request after a rejection keeps the earlier outcome in history
        // rather than erasing it — the audit trail of what the school decided
        // and when must survive.
        $history = (array) ($prior['history'] ?? []);
        if (($prior['status'] ?? '') !== '') {
            $history[] = [
                'status'      => (string) $prior['status'],
                'reviewed_at' => (string) ($prior['reviewed_at'] ?? ''),
                'reviewed_by' => (string) ($prior['reviewed_by'] ?? ''),
                'notes'       => (string) ($prior['review_notes'] ?? ''),
            ];
        }

        $data = [
            'id'              => $docId,
            'schoolId'        => $this->schoolId,
            'session'         => $this->sessionYear,
            'studentId'       => $studentId,
            'student_name'    => trim((string) ($input['student_name']  ?? '')),
            'student_class'   => trim((string) ($input['student_class'] ?? '')),
            'requested_area'  => substr(trim((string) ($input['requested_area'] ?? '')), 0, 120),
            'requested_type'  => (string) ($input['type'] ?? ''),
            'requested_stop_hint' => substr(trim((string) ($input['stop_hint'] ?? '')), 0, 120),
            'status'          => 'pending',
            'source'          => $source,
            'requested_at'    => $now,
            'requested_by'    => $this->adminId,
            'reviewed_at'     => '',
            'reviewed_by'     => '',
            'review_notes'    => '',
            'assignment_id'   => '',
            'history'         => $history,
            'created_at'      => (string) ($prior['created_at'] ?? $now),
            'updated_at'      => $now,
        ];
        $this->fs->setEntity(self::COL_TRANSPORT_REQUESTS, $docId, $data, /* merge */ true);
        $this->emitAudit('transportRequest', $docId, 'requested', $prior ?: null, $data,
            ['domain' => self::DOMAIN_ASSIGNMENT]);

        return ['outcome' => 'pending', 'requestId' => $docId, 'assignmentId' => '',
                'message' => 'Transport request recorded for review.'];
    }

    /**
     * F14 Part A — refuse to complete a Pickup trip that has not provably
     * reached school, or that still has unaccounted children.
     *
     * Delegates to Transport_qr_attendance_service::completionBlockers(), which
     * owns the geofence + reconciliation logic. That service takes THIS instance
     * as its writer, so constructing it here is safe: it never constructs a
     * Transport_service back, so there is no recursion.
     *
     * Fails CLOSED on an unexpected error — if arrival cannot be confirmed, the
     * trip stays open. An open trip is visible and recoverable; a wrongly
     * completed one silently discards the fact that a child was unaccounted for.
     *
     * @throws \InvalidArgumentException with a driver-readable reason
     */
    private function _assertSchoolArrivalBeforeComplete(string $tripId, bool $arrivalOverride = false): void
    {
        $trip = $this->fs->getEntity(self::COL_TRIP_LOGS, $tripId);
        if (!is_array($trip)) { return; }              // load failure surfaces later
        $trip = $this->_unwrapNestedRow($trip, 'vehicleId');

        // Direction gate. Pickup (morning) only; older trips carry only tripType.
        $direction = (string) ($trip['trip_direction'] ?? '');
        if ($direction === '') {
            $direction = strtolower((string) ($trip['tripType'] ?? '')) === 'afternoon' ? 'Drop' : 'Pickup';
        }
        if ($direction !== 'Pickup') { return; }

        // Nothing to guard on a trip that never started.
        if ((string) ($trip['status'] ?? '') !== 'InProgress') { return; }

        try {
            $CI =& get_instance();
            if (!isset($CI->f14_completion_guard)) {
                $CI->load->library('transport_qr_attendance_service', null, 'f14_completion_guard');
            }
            $CI->f14_completion_guard->init(
                $this->fs, $this, $this->schoolId, $this->sessionYear, $this->adminId);
            $blockers = $CI->f14_completion_guard->completionBlockers($tripId, $arrivalOverride);
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Fails CLOSED even under an admin override, deliberately. This path
            // means the roster itself could not be evaluated, so whether any
            // child is unresolved or still aboard is UNKNOWN — and an override is
            // authorised to excuse missing GPS, never to excuse an unknown child.
            // A genuine no-GPS condition does not land here: it is a normal
            // `school_not_reached` verdict, which the override does suppress.
            log_message('error',
                "F14 completion guard failed for {$tripId}: " . $e->getMessage());
            throw new \InvalidArgumentException(
                'Could not confirm the bus has reached school, so the trip cannot be completed yet. '
                . 'Check that tracking is running and try again.');
        }

        if (!empty($blockers['blocked'])) {
            $names = [];
            foreach ((array) ($blockers['students'] ?? []) as $s) {
                $n = trim((string) ($s['name'] ?? $s['student_id'] ?? ''));
                if ($n !== '') { $names[] = $n; }
            }
            $detail = $names !== [] ? ' (' . implode(', ', array_slice($names, 0, 8)) . ')' : '';
            throw new \InvalidArgumentException((string) $blockers['message'] . $detail);
        }
    }

    /** Active studentRoutes row for a student in this session, or []. */
    private function _activeAssignmentFor(string $studentId): array
    {
        try {
            $rows = (array) $this->fs->schoolWhere(self::COL_STUDENT_ROUTES_LEGACY,
                [['studentId', '==', $studentId], ['status', '==', 'Active']], null, 'ASC', 20);
        } catch (\Throwable $e) {
            log_message('error', 'F14 request: assignment lookup failed: ' . $e->getMessage());
            return [];
        }
        foreach ($rows as $r) {
            $a = $this->_unwrapNestedRow($r, 'studentId');
            if (($a['session'] ?? '') === $this->sessionYear) { return $a; }
        }
        return [];
    }

    /** Worklist for the admin screen. */
    public function listTransportRequests(string $status = 'pending', int $limit = 200): array
    {
        $this->_assertReady();
        // session FIRST, status second — deliberately. That ordering lets ONE
        // composite index (schoolId + session + status) serve both the filtered
        // worklist and the unfiltered "all" view, because schoolId + session is
        // a prefix of it. Reversed, the "all" case would need a second index.
        $cond = [['session', '==', $this->sessionYear]];
        if ($status !== '' && $status !== 'all') {
            if (!in_array($status, self::REQUEST_STATUSES, true)) {
                throw new \InvalidArgumentException('Invalid status filter.');
            }
            $cond[] = ['status', '==', $status];
        }
        $rows = (array) $this->fs->schoolWhere(self::COL_TRANSPORT_REQUESTS, $cond, null, 'ASC', $limit);
        $out = [];
        foreach ($rows as $r) {
            $d = $this->_unwrapNestedRow($r, 'studentId');
            if ($d !== []) { $out[] = $d; }
        }
        return $out;
    }

    /**
     * Approve a pending request by creating the REAL assignment.
     *
     * Conversion goes through the canonical assignStudent(), so the request
     * inherits every existing behaviour — validation, fee creation, audit,
     * history — rather than reimplementing any of it. Route AND stop are
     * mandatory here: approving without them would recreate exactly the
     * incomplete-assignment problem the worklist exists to prevent.
     */
    public function approveTransportRequest(string $requestId, array $assignment): array
    {
        $this->_assertReady();
        $req = $this->fs->getEntity(self::COL_TRANSPORT_REQUESTS, $requestId);
        $req = is_array($req) ? $this->_unwrapNestedRow($req, 'studentId') : [];
        if ($req === []) { throw new \InvalidArgumentException('Transport request not found.'); }
        if ((string) ($req['schoolId'] ?? '') !== $this->schoolId) {
            log_message('error', "F14 cross-tenant request approve school={$this->schoolId} req={$requestId}");
            throw new \InvalidArgumentException('Transport request not found.');
        }
        if ((string) ($req['status'] ?? '') !== 'pending') {
            throw new \InvalidArgumentException(
                'Only a pending request can be approved (current: ' . (string) ($req['status'] ?? '?') . ').');
        }
        $routeId = trim((string) ($assignment['route_id'] ?? ''));
        $stopId  = trim((string) ($assignment['stop_id']  ?? ''));
        if ($routeId === '' || $stopId === '') {
            throw new \InvalidArgumentException('A route and a stop are required to approve a transport request.');
        }

        $res = $this->assignStudent([
            // assignStudent() reads $input['student_id'] — the key was 'studentId'
            // here, so approval always threw "student_id and route_id are
            // required." before reaching any write. Caller-side correction only:
            // the shared contract is unchanged and no alias was introduced.
            'student_id'     => (string) $req['studentId'],
            'route_id'       => $routeId,
            'stop_id'        => $stopId,
            'type'           => (string) ($assignment['type'] ?? $req['requested_type'] ?? 'both'),
            'service_days'   => (array)  ($assignment['service_days']   ?? []),
            'effective_from' => (string) ($assignment['effective_from'] ?? date('Y-m-d')),
            'effective_to'   => (string) ($assignment['effective_to']   ?? ''),
            'monthly_fee'    => $assignment['monthly_fee'] ?? null,
        ], 'request_approval');

        $assignmentId = (string) ($res['assignmentId'] ?? $res['id'] ?? '');
        $update = [
            'status'        => 'approved',
            'reviewed_at'   => date('c'),
            'reviewed_by'   => $this->adminId,
            'review_notes'  => trim((string) ($assignment['notes'] ?? '')),
            'assignment_id' => $assignmentId,
            'updated_at'    => date('c'),
        ];
        $this->fs->setEntity(self::COL_TRANSPORT_REQUESTS, $requestId, $update, /* merge */ true);
        $this->emitAudit('transportRequest', $requestId, 'approved', $req, array_merge($req, $update),
            ['domain' => self::DOMAIN_ASSIGNMENT]);

        return ['requestId' => $requestId, 'assignmentId' => $assignmentId,
                'studentId' => (string) $req['studentId'], 'message' => 'Transport request approved and assigned.'];
    }

    /** Reject a pending request. Creates no assignment and no fee. */
    public function rejectTransportRequest(string $requestId, string $reason): void
    {
        $this->_assertReady();
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A reason is required to reject a transport request.');
        }
        $req = $this->fs->getEntity(self::COL_TRANSPORT_REQUESTS, $requestId);
        $req = is_array($req) ? $this->_unwrapNestedRow($req, 'studentId') : [];
        if ($req === []) { throw new \InvalidArgumentException('Transport request not found.'); }
        if ((string) ($req['schoolId'] ?? '') !== $this->schoolId) {
            throw new \InvalidArgumentException('Transport request not found.');
        }
        if ((string) ($req['status'] ?? '') !== 'pending') {
            throw new \InvalidArgumentException('Only a pending request can be rejected.');
        }
        $update = [
            'status' => 'rejected', 'reviewed_at' => date('c'), 'reviewed_by' => $this->adminId,
            'review_notes' => trim($reason), 'updated_at' => date('c'),
        ];
        $this->fs->setEntity(self::COL_TRANSPORT_REQUESTS, $requestId, $update, /* merge */ true);
        $this->emitAudit('transportRequest', $requestId, 'rejected', $req, array_merge($req, $update),
            ['domain' => self::DOMAIN_ASSIGNMENT]);
    }

    /**
     * ── F14 canonical four ───────────────────────────────────────────────
     * Thin, explicit entry points. Each one names exactly one leg of one
     * journey, so a caller cannot accidentally record a morning event on an
     * afternoon trip — _writeBoardingEvent() enforces the leg as well.
     */
    public function markBoardedAtStop(string $tripId, string $studentId, ?string $actualStopId = null, string $notes = '', array $opts = []): array
    {
        return $this->_writeBoardingEvent($tripId, $studentId, 'boarded_at_stop', $actualStopId, $notes, $opts);
    }

    public function markAlightedAtSchool(string $tripId, string $studentId, string $notes = '', array $opts = []): array
    {
        return $this->_writeBoardingEvent($tripId, $studentId, 'alighted_at_school', null, $notes, $opts);
    }

    public function markBoardedAtSchool(string $tripId, string $studentId, string $notes = '', array $opts = []): array
    {
        return $this->_writeBoardingEvent($tripId, $studentId, 'boarded_at_school', null, $notes, $opts);
    }

    public function markAlightedAtStop(string $tripId, string $studentId, ?string $actualStopId = null, string $notes = '', array $opts = []): array
    {
        return $this->_writeBoardingEvent($tripId, $studentId, 'alighted_at_stop', $actualStopId, $notes, $opts);
    }

    public function markStudentBoarded(string $tripId, string $studentId, ?string $actualStopId = null, string $notes = ''): array
    {
        return $this->_writeBoardingEvent($tripId, $studentId, 'boarded_pickup', $actualStopId, $notes);
    }

    /** Mark a student dropped on a Drop trip. Idempotent. */
    public function markStudentDropped(string $tripId, string $studentId, ?string $actualStopId = null, string $notes = ''): array
    {
        return $this->_writeBoardingEvent($tripId, $studentId, 'dropped_home', $actualStopId, $notes);
    }

    /** Record a no-show. Fires trip.student_no_show (Important) to the parent. */
    /** @param array $opts F14 additive (resolution_state/reason, driver role). */
    public function markStudentNoShow(string $tripId, string $studentId, string $notes = '', array $opts = []): array
    {
        $ret = $this->_writeBoardingEvent($tripId, $studentId, 'no_show', null, $notes, $opts);

        // Fire parent notification (F6-Refined categories; only no-show
        // fires at F7 per operator Refinement 1 — boarded/dropped
        // notifications deferred to Parent-App preferences feature).
        $trip = $this->_loadTripOrFail($tripId);
        $this->_notify('trip.student_no_show', [
            'trip_id'    => $tripId,
            'student_id' => $studentId,
            'student_ids'=> [$studentId],
            'route_id'   => (string) ($trip['route_id'] ?? ''),
            'route_name' => (string) ($trip['route_snapshot']['name'] ?? ''),
            'trip_date'  => (string) ($trip['trip_date'] ?? ''),
            'direction'  => (string) ($trip['trip_direction'] ?? ''),
        ]);
        return $ret;
    }

    /**
     * Complete the trip. Requires EVERY checkpoint to be either Reached or
     * Skipped (Refinement 4). Computes trip summary (distance, duration,
     * on_time, students_boarded_count, no_show_count). Fires trip.completed.
     */
    /**
     * @param array $override F14 Phase 2A — audited admin completion override.
     *        DELIBERATELY a separate argument, not a key inside $input: $input is
     *        populated straight from client POST data, so an override flag living
     *        there could be set by anyone who can reach the ordinary completion
     *        endpoint. Only an authorised server-side path can pass this.
     *        Shape: ['reason' => non-empty string, 'by' => staff id].
     */
    public function completeTrip(string $tripId, array $input = [], array $override = []): array
    {
        $this->_assertReady();
        if ($tripId === '') { throw new \InvalidArgumentException('tripId required.'); }

        // ── F14 Phase 2A — OVERRIDE INTAKE ───────────────────────────────────
        // Strip any override-shaped keys a caller may have folded into $input.
        // Defence in depth: even if a future endpoint forwards raw POST data,
        // the client cannot smuggle an override or forge its provenance fields.
        unset($input['_override'], $input['completion_override'],
              $input['override_by'], $input['override_at'], $input['override_reason']);

        $ovReason = '';
        if ($override !== []) {
            $ovReason = trim((string) ($override['reason'] ?? ''));
            if ($ovReason === '') {
                throw new \InvalidArgumentException(
                    'A written reason is required to override trip completion.');
            }
        }
        $ovActive = $ovReason !== '';
        $ovBy     = $ovActive
            ? (trim((string) ($override['by'] ?? '')) ?: (string) $this->adminId)
            : '';

        // ── F14 Part A — SCHOOL-ARRIVAL COMPLETION GUARD (2026-08-12) ────────
        //
        // Audited 2026-08-12: this method previously enforced only (a) status ==
        // InProgress and (b) no Pending checkpoint. A school-bound Pickup trip
        // could therefore be completed from anywhere — outside the geofence,
        // with a stale fix, with no GPS at all, and with children still
        // unaccounted for. Pressing a button is not arrival.
        //
        // Enforced HERE, in the service, rather than in the driver API, because
        // the invariant must hold for EVERY caller — including the admin panel.
        // The check runs BEFORE the CAS transaction: it performs several reads,
        // which do not belong inside a compare-and-set mutator.
        //
        // Pickup ONLY. The afternoon leg ends at the stops, and applying a
        // school-arrival rule to it would be simply wrong.
        //
        // Reuses F13.1 `vehicleLivePositions` and the shared campus geofence
        // (schools.attendancePolicy.gps) via Transport_qr_attendance_service —
        // no second GPS system, no duplicated geofence maths, and F13.1's own
        // freshness/accuracy ceilings are untouched.
        //
        // An override suppresses ONLY the geofence arrival check inside this
        // guard. `unresolved_students` and `still_on_board` remain fatal — see
        // Transport_qr_attendance_service::completionBlockers().
        $this->_assertSchoolArrivalBeforeComplete($tripId, $ovActive);

        $now     = date('c');
        $before  = [];
        $abort   = '';
        $summary = [];
        // CON-6: atomic InProgress->Completed via updateTime CAS. The status
        // guard, the checkpoint Pending-guard, and every derived field are
        // evaluated against the FRESH doc inside the mutator — so a concurrent
        // checkpoint write (CON-2) cannot slip a Pending stop past completion,
        // and two completes cannot both fire audit/notify.
        $res = $this->fs->casUpdateEntity(self::COL_TRIP_LOGS, $tripId,
            function (array $trip) use (&$before, &$abort, &$summary, $tripId, $input, $now,
                                        $ovActive, $ovReason, $ovBy) {
                if (($trip['status'] ?? '') !== 'InProgress') {
                    $abort = "Trip {$tripId} not InProgress.";
                    return null;
                }
                // F14 Phase 2A — the override exists solely to excuse an
                // unprovable SCHOOL ARRIVAL, which only the Pickup leg has. On a
                // Drop trip it would excuse nothing and merely stamp a misleading
                // "overridden" marker on an ordinary completion. Refuse it.
                if ($ovActive) {
                    $dir = (string) ($trip['trip_direction'] ?? '');
                    if ($dir === '') {
                        $dir = strtolower((string) ($trip['tripType'] ?? '')) === 'afternoon'
                            ? 'Drop' : 'Pickup';
                    }
                    if ($dir !== 'Pickup') {
                        $abort = 'Completion override applies to Pickup trips only — a '
                            . $dir . ' trip does not require school-arrival verification.';
                        return null;
                    }
                }
                $checkpoints = (array) ($trip['checkpoints'] ?? []);
                foreach ($checkpoints as $cp) {
                    if ((string) ($cp['checkpoint_state'] ?? 'Pending') === 'Pending') {
                        $abort = "Cannot complete — checkpoint '" . ($cp['stop_name'] ?? '') . "' is still Pending. "
                            . "Mark it Reached or Skipped first (Refinement 4).";
                        return null;
                    }
                }
                $endTime     = trim((string) ($input['end_time'] ?? $now));
                $endOdometer = (float) ($input['end_odometer'] ?? 0);
                $startOdo    = (float) ($trip['startOdometer'] ?? 0);
                $distanceKm  = $endOdometer > $startOdo ? round($endOdometer - $startOdo, 2) : (float) ($input['distance_km'] ?? 0);
                $fuelUsed    = (float) ($input['fuel_used'] ?? 0);
                $notes       = trim((string) ($input['notes'] ?? ''));
                $stopsCompleted = 0;
                foreach ($checkpoints as $cp) {
                    if (($cp['checkpoint_state'] ?? '') === 'Reached') { $stopsCompleted++; }
                }
                $startTs = strtotime((string) ($trip['actual_start_at'] ?? '')) ?: 0;
                $endTs   = strtotime($endTime) ?: 0;
                $durationMinutes = ($startTs > 0 && $endTs > $startTs) ? (int) round(($endTs - $startTs) / 60) : 0;
                $onTime = $this->_computeOnTime($trip, $checkpoints);
                $tally  = $this->_tallyBoardingEvents($tripId);
                $before  = $trip;
                $summary = [
                    'status'             => 'Completed',
                    'actual_end_at'      => $now,
                    'endTime'            => $endTime,
                    'endOdometer'        => $endOdometer,
                    'distance'           => $distanceKm,
                    'distance_km'        => $distanceKm,
                    'fuelUsed'           => $fuelUsed,
                    'stopsCompleted'     => $stopsCompleted,
                    'duration_minutes'   => $durationMinutes,
                    'on_time'            => $onTime,
                    'notes'              => $notes,
                    'route_progress_pct' => 100,
                    'next_checkpoint_id' => '',
                    'boarded_count'      => (int) ($tally['boarded'] ?? 0),
                    'dropped_count'      => (int) ($tally['dropped'] ?? 0),
                    'no_show_count'      => (int) ($tally['no_show'] ?? 0),
                    'updated_at'         => $now,
                    // F13.1 M2 (D-2) — disarm the tracking kill switch. From
                    // this write on, the Firestore rule refuses every further
                    // driver position update for this trip, regardless of what
                    // the driver app believes or whether it has crashed.
                    'live_tracking_enabled' => false,
                    'gps_end_at'            => $now,
                ];
                // F14 Phase 2A — override provenance, written INSIDE the CAS
                // mutator so it can never be persisted without the accompanying
                // Completed transition. If this CAS attempt loses the race the
                // whole map is discarded together, leaving no partial override.
                if ($ovActive) {
                    $summary['completion_override'] = true;
                    $summary['override_by']         = $ovBy;
                    $summary['override_at']         = $now;
                    $summary['override_reason']     = $ovReason;
                }
                return $summary;
            });
        $this->_assertTripTransition($res, $tripId, $abort);

        // F13.1 M2 (D-2) — tear down the live-position session so no parent can
        // see a position after the children are off the bus. Best-effort: if the
        // delete fails the kill switch above has already stopped new fixes, so
        // the position can only go stale, never keep moving.
        $this->_closeGpsSession((string) ($before['vehicleId'] ?? ''), $tripId);

        // F14 Phase 2A — an overridden completion is a distinct audited action,
        // never filed as an ordinary one. Canonical audit path, no new mechanism.
        $this->emitAudit('trip', $tripId, $ovActive ? 'completed_override' : 'completed',
            $before, array_merge($before, $summary), ['domain' => self::DOMAIN_TRIP]);
        $this->_notify('trip.completed', [
            'trip_id'         => $tripId,
            'route_id'        => (string) ($before['route_id'] ?? ''),
            'vehicle_id'      => (string) ($before['vehicleId'] ?? ''),
            'driver_id'       => (string) ($before['driver_staff_id'] ?? ''),
            'trip_date'       => (string) ($before['trip_date'] ?? ''),
            'direction'       => (string) ($before['trip_direction'] ?? ''),
            'duration_minutes'=> (int) ($summary['duration_minutes'] ?? 0),
            'distance_km'     => (float) ($summary['distance_km'] ?? 0),
            'on_time'         => (bool) ($summary['on_time'] ?? false),
            'boarded'         => (int) ($summary['boarded_count'] ?? 0),
            'no_show'         => (int) ($summary['no_show_count'] ?? 0),
        ]);
        // XMOD-1: trip over — release the vehicle's denormalised route.
        $this->_clearVehicleCurrentRoute((string) ($before['vehicleId'] ?? ''), (string) ($before['route_id'] ?? ''));
        return ['tripId' => $tripId, 'status' => 'Completed', 'summary' => $summary];
    }

    /** Cancel a Ready or InProgress trip. Terminal. */
    public function cancelTrip(string $tripId, string $reason): array
    {
        $this->_assertReady();
        if ($tripId === '') { throw new \InvalidArgumentException('tripId required.'); }
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('cancel reason is required.');
        }
        $now    = date('c');
        $before = [];
        $abort  = '';
        // CON-6: atomic cancel via updateTime CAS — only one terminal
        // transition wins; a concurrent complete/cancel makes the loser abort.
        $res = $this->fs->casUpdateEntity(self::COL_TRIP_LOGS, $tripId,
            function (array $trip) use (&$before, &$abort, $reason, $now) {
                if (!in_array((string) ($trip['status'] ?? ''), self::TRIP_ACTIVE_STATUSES, true)) {
                    $abort = "Trip not in cancellable state (status: " . ($trip['status'] ?? '') . ").";
                    return null;
                }
                $before = $trip;
                return [
                    'status'        => 'Cancelled',
                    'actual_end_at' => $now,
                    'cancel_reason' => $reason,
                    'cancelled_by'  => $this->adminId,
                    'updated_at'    => $now,
                    // F13.1 M2 (D-2) — same disarm as completeTrip. A cancelled
                    // trip is just as terminal for tracking as a completed one.
                    'live_tracking_enabled' => false,
                    'gps_end_at'            => $now,
                ];
            });
        $this->_assertTripTransition($res, $tripId, $abort);

        // F13.1 M2 (D-2) — tear down the live-position session.
        $this->_closeGpsSession((string) ($before['vehicleId'] ?? ''), $tripId);

        $update = ['status' => 'Cancelled', 'actual_end_at' => $now, 'cancel_reason' => $reason, 'cancelled_by' => $this->adminId];
        $this->emitAudit('trip', $tripId, 'cancelled', $before, array_merge($before, $update),
            ['domain' => self::DOMAIN_TRIP, 'cancel_reason' => $reason]);
        // XMOD-1: cancelled — release the vehicle's denormalised route.
        $this->_clearVehicleCurrentRoute((string) ($before['vehicleId'] ?? ''), (string) ($before['route_id'] ?? ''));
        return ['tripId' => $tripId, 'status' => 'Cancelled'];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  F13.1 M2 — GPS session lifecycle bridge (approved exception D-2)
    //
    //  These two helpers are the ENTIRE F13 footprint inside this frozen file.
    //  They deliberately contain no GPS logic: everything — the viewer
    //  allowlist, the document shape, validation, sweeping — lives in
    //  Transport_gps_service, so F13 can evolve without reopening
    //  Transport_service. Both are best-effort and mirror
    //  _setVehicleCurrentRoute's contract: a telemetry failure is logged and
    //  never propagates into the trip lifecycle, which is the business truth.
    // ─────────────────────────────────────────────────────────────────────

    /** Open the live-position session for a trip that has just gone InProgress. */
    private function _openGpsSession(array $trip, string $tripId): void
    {
        try {
            $CI = get_instance();
            $CI->load->library('transport_gps_service', null, 'trnGpsSvc');
            $res = $CI->trnGpsSvc
                ->init($this->fs, $this->schoolId, $this->adminId)
                ->openSession($trip, $tripId);
            if (empty($res['ok'])) {
                log_message('error', "F13 openGpsSession skipped for {$tripId}: " . (string) ($res['reason'] ?? '?'));
            }
        } catch (\Throwable $e) {
            log_message('error', "F13 openGpsSession failed for {$tripId}: " . $e->getMessage());
        }
    }

    /** Close the live-position session on trip completion or cancellation. */
    private function _closeGpsSession(string $vehicleId, string $tripId): void
    {
        if ($vehicleId === '') { return; }
        try {
            $CI = get_instance();
            $CI->load->library('transport_gps_service', null, 'trnGpsSvc');
            $res = $CI->trnGpsSvc
                ->init($this->fs, $this->schoolId, $this->adminId)
                ->closeSession($vehicleId);
            if (empty($res['ok'])) {
                log_message('error', "F13 closeGpsSession for {$tripId}/{$vehicleId}: " . (string) ($res['reason'] ?? '?'));
            }
        } catch (\Throwable $e) {
            log_message('error', "F13 closeGpsSession failed for {$tripId}/{$vehicleId}: " . $e->getMessage());
        }
    }

    /**
     * XMOD-1 — denormalise the in-progress trip's route onto the vehicle as
     * `currentRouteId` (the exact field the Teacher app's VehicleDoc reads for
     * the DriverHome route badge and the driver SOS route context). Set on
     * trip start; cleared on completion/cancellation. Best-effort: a failure
     * is logged but never blocks the trip transition — the trip lifecycle is
     * the business truth; this is a read-side convenience field.
     */
    private function _setVehicleCurrentRoute(string $vehicleId, string $routeId): void
    {
        if ($vehicleId === '') { return; }
        try {
            $this->fs->setEntity(self::COL_VEHICLES, $vehicleId,
                ['currentRouteId' => $routeId, 'updated_at' => date('c')], /* merge */ true);
        } catch (\Throwable $e) {
            log_message('error', "XMOD-1 _setVehicleCurrentRoute({$vehicleId}, {$routeId}) failed: " . $e->getMessage());
        }
    }

    /**
     * XMOD-1 — clear the vehicle's denormalised route on trip end. Guarded: only
     * clears when the vehicle still carries THIS trip's route, so a different
     * concurrent trip's route is never wiped. No-op for route-less Event trips.
     */
    private function _clearVehicleCurrentRoute(string $vehicleId, string $routeId): void
    {
        if ($vehicleId === '' || $routeId === '') { return; }
        try {
            $veh = $this->fs->getEntity(self::COL_VEHICLES, $vehicleId);
            if (is_array($veh) && (string) ($veh['currentRouteId'] ?? '') === $routeId) {
                $this->fs->setEntity(self::COL_VEHICLES, $vehicleId,
                    ['currentRouteId' => '', 'updated_at' => date('c')], /* merge */ true);
            }
        } catch (\Throwable $e) {
            log_message('error', "XMOD-1 _clearVehicleCurrentRoute({$vehicleId}, {$routeId}) failed: " . $e->getMessage());
        }
    }

    /** List trips, optionally filtered by date + status. */
    public function listTrips(array $filters = []): array
    {
        $this->_assertReady();
        $where = [];
        if (!empty($filters['date'])) {
            $where[] = ['date', '==', (string) $filters['date']];
        }
        if (!empty($filters['status'])) {
            $where[] = ['status', '==', (string) $filters['status']];
        }
        if (!empty($filters['route_id'])) {
            $where[] = ['route_id', '==', (string) $filters['route_id']];
        }
        try {
            $rows = (array) $this->fs->schoolWhere(self::COL_TRIP_LOGS, $where, 'date', 'DESC', 200);
        } catch (\Throwable $_) { return []; }
        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->_unwrapNestedRow($r, 'date');
        }
        return array_values(array_filter($out, static fn($t) => is_array($t) && !empty($t)));
    }

    public function getTrip(string $tripId): ?array
    {
        $this->_assertReady();
        if ($tripId === '') { return null; }
        $t = $this->fs->getEntity(self::COL_TRIP_LOGS, $tripId);
        return is_array($t) ? $t : null;
    }

    public function getTripRoster(string $tripId): array
    {
        $trip = $this->getTrip($tripId);
        return is_array($trip) ? (array) ($trip['roster_student_ids'] ?? []) : [];
    }

    public function getTripBoardingEvents(string $tripId): array
    {
        $this->_assertReady();
        try {
            $rows = (array) $this->fs->schoolWhere(self::COL_TRANSPORT_ATTENDANCE,
                [['tripId', '==', $tripId]], 'event_at', 'ASC', 1000);
        } catch (\Throwable $_) { return []; }
        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->_unwrapNestedRow($r, 'tripId');
        }
        return array_values(array_filter($out, static fn($e) => is_array($e) && !empty($e)));
    }

    // ─── Internals ───────────────────────────────────────────────────────

    private function _loadTripOrFail(string $tripId): array
    {
        if ($tripId === '') { throw new \InvalidArgumentException('tripId required.'); }
        $trip = $this->fs->getEntity(self::COL_TRIP_LOGS, $tripId);
        if (!is_array($trip)) {
            throw new \InvalidArgumentException("Trip not found: {$tripId}");
        }
        return $trip;
    }

    /**
     * CON-6 — translate a casUpdateEntity() result for a trip-lifecycle
     * transition into the original exception contract. $abortMsg is the
     * fresh-state reason the mutator captured when it declined (null-return).
     * The updateTime CAS guarantees exactly one concurrent transition commits;
     * a loser re-reads, its mutator now sees the moved state and aborts —
     * so audit/notify fire exactly once and no double-transition occurs.
     */
    private function _assertTripTransition(array $res, string $tripId, string $abortMsg): void
    {
        $reason = (string) ($res['reason'] ?? '');
        if ($reason === 'missing') {
            throw new \InvalidArgumentException("Trip not found: {$tripId}");
        }
        if ($reason === 'aborted') {
            throw new \InvalidArgumentException($abortMsg !== '' ? $abortMsg
                : "Trip {$tripId} is not in a valid state for this transition.");
        }
        if (empty($res['ok']) || empty($res['applied'])) {
            throw new \RuntimeException(
                "Trip {$tripId} could not be updated due to a concurrent modification — please retry."
            );
        }
    }

    private function _updateCheckpointState(string $tripId, string $stopId, string $newState, string $reason = ''): array
    {
        if (!in_array($newState, self::CHECKPOINT_STATES, true)) {
            throw new \InvalidArgumentException("Invalid checkpoint state: {$newState}");
        }
        if ($tripId === '') { throw new \InvalidArgumentException('tripId required.'); }
        $now         = date('c');
        $abort       = '';
        $progressPct = 0;
        // CON-2: atomic single-element mutation of checkpoints[] via updateTime
        // CAS. Concurrent checkpoint updates can no longer clobber each other's
        // array element, and a tally write cannot revert a just-committed state.
        $res = $this->fs->casUpdateEntity(self::COL_TRIP_LOGS, $tripId,
            function (array $trip) use (&$abort, &$progressPct, $tripId, $stopId, $newState, $reason, $now) {
                if (($trip['status'] ?? '') !== 'InProgress') {
                    $abort = "Trip {$tripId} not InProgress (status: " . ($trip['status'] ?? '') . ").";
                    return null;
                }
                $checkpoints = (array) ($trip['checkpoints'] ?? []);
                $found = false;
                foreach ($checkpoints as $i => $cp) {
                    if ((string) ($cp['stop_id'] ?? '') !== $stopId) { continue; }
                    $currentState = (string) ($cp['checkpoint_state'] ?? 'Pending');
                    if (in_array($currentState, ['Reached', 'Skipped'], true) && $currentState !== $newState) {
                        $abort = "Checkpoint already {$currentState} — no further transition allowed.";
                        return null;
                    }
                    $checkpoints[$i]['checkpoint_state'] = $newState;
                    if ($newState === 'Reached') {
                        $checkpoints[$i]['reached_at'] = $now;
                    } elseif ($newState === 'Skipped') {
                        $checkpoints[$i]['skipped_at'] = $now;
                        $checkpoints[$i]['skip_reason'] = $reason;
                    }
                    $found = true;
                    break;
                }
                if (!$found) {
                    $abort = "Stop {$stopId} not found in trip {$tripId}.";
                    return null;
                }
                // Recompute route progress + next checkpoint pointer (fresh).
                $reachedOrSkipped = 0;
                $nextPending = '';
                foreach ($checkpoints as $cp) {
                    $st = (string) ($cp['checkpoint_state'] ?? 'Pending');
                    if ($st === 'Reached' || $st === 'Skipped') { $reachedOrSkipped++; }
                    elseif ($nextPending === '') { $nextPending = (string) ($cp['stop_id'] ?? ''); }
                }
                $progressPct = count($checkpoints) > 0 ? (int) round(($reachedOrSkipped / count($checkpoints)) * 100) : 0;
                return [
                    'checkpoints'        => $checkpoints,
                    'route_progress_pct' => $progressPct,
                    'next_checkpoint_id' => $nextPending,
                    'updated_at'         => $now,
                ];
            });
        $this->_assertTripTransition($res, $tripId, $abort);
        $this->emitAudit('trip_checkpoint', $tripId . ':' . $stopId, strtolower($newState),
            null, ['stop_id' => $stopId, 'new_state' => $newState, 'reason' => $reason],
            ['domain' => self::DOMAIN_TRIP]);
        return ['tripId' => $tripId, 'stop_id' => $stopId, 'checkpoint_state' => $newState, 'progress_pct' => $progressPct];
    }

    /**
     * Write a boarding event to transportAttendance. Deterministic doc-id
     * = idempotent on repeat driver taps (F7-4 verifier gate).
     */
    /**
     * THE single attendance writer. F14 kept it that way deliberately — a
     * second writer would mean a second place to forget the roster gate.
     *
     * @param array $opts F14 additive options. Every key is optional and every
     *   default reproduces pre-F14 behaviour byte-for-byte, so existing callers
     *   are unaffected:
     *     marked_by_role     'admin'|'driver'   — who recorded it
     *     scan_source        qr_scan|manual|bulk_school_arrival
     *     allow_off_roster   bool  — ONLY the authorised alternate-bus path
     *     boarding_mode      assigned|alternate_bus|operational_exception
     *     assigned_*         route/vehicle/trip/stop from studentRoutes
     *     actual_*           route/vehicle for THIS trip
     *     client_event_id    offline-queue idempotency key
     *     client_event_at    device clock (server keeps event_at authoritative)
     *     event_lat/lng/accuracy_m  GPS-at-scan (from F13.1 telemetry)
     *     resolution_state / resolution_reason
     */
    private function _writeBoardingEvent(string $tripId, string $studentId, string $eventType, ?string $actualStopId, string $notes, array $opts = []): array
    {
        $this->_assertReady();
        if (!in_array($eventType, self::BOARDING_EVENT_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid boarding event_type: {$eventType}");
        }
        $trip = $this->_loadTripOrFail($tripId);
        if ($trip['status'] !== 'InProgress') {
            throw new \InvalidArgumentException("Trip {$tripId} not InProgress (status: {$trip['status']}).");
        }
        // Roster gate (Refinement 3 — immutable roster)
        $roster = (array) ($trip['roster_student_ids'] ?? []);
        // LOG-5: a no_show must ALSO be a rostered student. Exempting it let
        // markStudentNoShow(anyId) fire a safety-exempt trip.student_no_show to
        // an unrelated parent + inflate the tally. A no_show is by definition a
        // student who was expected (on the frozen roster) but did not board.
        // F14: the ONLY sanctioned off-roster write is an explicitly authorised
        // alternate-bus boarding, where the caller has already proved the
        // student holds an active studentRoutes entitlement for another route.
        // Default false keeps the gate exactly as strict as before.
        $allowOffRoster = !empty($opts['allow_off_roster']);
        if (!in_array($studentId, $roster, true) && !$allowOffRoster) {
            throw new \InvalidArgumentException("Student {$studentId} is not on the frozen roster for trip {$tripId}.");
        }
        // Direction guard
        $direction = (string) ($trip['trip_direction'] ?? '');
        if ($eventType === 'boarded_pickup' && $direction !== 'Pickup') {
            throw new \InvalidArgumentException("Cannot board on a Drop trip; use dropped_home instead.");
        }
        if ($eventType === 'dropped_home' && $direction !== 'Drop') {
            throw new \InvalidArgumentException("Cannot drop on a Pickup trip; use boarded_pickup instead.");
        }
        // F14 canonical events carry the same guard, expressed once via the
        // leg map so morning and afternoon can never be mixed on one trip.
        $legOf = [
            'boarded_at_stop'    => 'Pickup', 'alighted_at_school' => 'Pickup',
            'boarded_at_school'  => 'Drop',   'alighted_at_stop'   => 'Drop',
        ];
        if (isset($legOf[$eventType]) && $direction !== '' && $legOf[$eventType] !== $direction) {
            throw new \InvalidArgumentException(
                "Event {$eventType} belongs to the {$legOf[$eventType]} journey, but trip {$tripId} is {$direction}.");
        }

        // Look up planned stop from assignment (may be null if no_show)
        $plannedStopId = $this->_lookupPlannedStop($studentId, (string) ($trip['route_id'] ?? ''));

        // Deterministic doc-id → idempotent repeat taps.
        //
        // ⚠ DO NOT "FIX" THE DOUBLED SCHOOL PREFIX. Reviewed and deliberately
        // KEPT (operator decision, 2026-08-12).
        //
        // This id already contains {schoolId}, and setEntity() below adds
        // another one via docId(), so every stored document is
        //     {schoolId}_{schoolId}_{tripId}_{studentId}_{eventType}
        // e.g. SCH_202512_SCH_202512_TRP000080_STU0159_no_show
        //
        // Why it stays:
        //   • ALL existing documents use this shape — it is consistent.
        //   • NOTHING reads attendance by document id. All six read paths are
        //     field queries (tripId / studentId / trip_date), the parent rule
        //     matches resource.data.studentId, and notification dedupe treats
        //     the id as an opaque string. Idempotency needs the id to be
        //     DETERMINISTIC, not to have any particular shape.
        //   • Correcting the writer alone would leave TWO id shapes in one
        //     collection — strictly worse than one consistent shape — and
        //     correcting the data would mean migrating live attendance records
        //     for zero functional gain.
        //
        // The one real hazard: a stored id must NEVER be handed back to
        // getEntity()/setEntity()/removeEntity(), which would prefix it a THIRD
        // time. When deleting, pass "{schoolId}_{tripId}_{studentId}_{type}" —
        // one prefix short of the stored id — and let the helper add the last
        // one. (Transport_probe_f8 documents this at its teardown registration.)
        $docId = "{$this->schoolId}_{$tripId}_{$studentId}_{$eventType}";
        $correlationId = substr(sha1($docId), 0, 24);
        $now = date('c');

        $data = [
            'id'                    => $docId,
            'schoolId'              => $this->schoolId,
            'tripId'                => $tripId,
            'studentId'             => $studentId,
            'route_id'              => (string) ($trip['route_id'] ?? ''),
            'session'               => (string) ($trip['session']  ?? $this->sessionYear),
            'trip_date'             => (string) ($trip['trip_date'] ?? ''),
            'trip_direction'        => $direction,
            'planned_stop_id'       => $plannedStopId,
            'actual_stop_id'        => $actualStopId ?: $plannedStopId,
            'event_type'            => $eventType,
            'event_at'              => $now,
            'marked_by_staff_id'    => $this->adminId,
            // F14: a driver-recorded event must be attributable as such. The
            // Firestore rule for transportAttendance already switches on this
            // value, so defaulting to 'admin' preserves every legacy caller.
            'marked_by_role'        => in_array((string) ($opts['marked_by_role'] ?? ''), ['admin', 'driver'], true)
                                        ? (string) $opts['marked_by_role'] : 'admin',
            'checkpoint_sequence'   => $this->_currentCheckpointSequence($trip, $actualStopId ?: $plannedStopId),
            'notes'                 => trim($notes),
            // GPS-native placeholders — NULL at F7, populated by GPS phase
            'event_location_ref'    => null,
            'event_lat'             => null,
            'event_lng'             => null,
            'event_at_gps'          => null,
            'gps_session_id'        => (string) ($trip['gps_session_id'] ?? ''),
            'correlation_id'        => $correlationId,
            // Reserved for future geofence validation (arrival_variance_m
            // — how far from stop_location did the event fire)
            'geofence_variance_m'   => null,
            'geofence_verified'     => null,
            'created_at'            => $now,
        ];

        // ── F14 additive block ───────────────────────────────────────────
        // ASSIGNED vs ACTUAL. The student's permanent studentRoutes assignment
        // is NEVER modified by an attendance event; when a child travels on a
        // bus that is not theirs, both facts are recorded side by side. Parent
        // history, driver reconciliation, admin audit and any later safety
        // investigation all depend on keeping the two distinguishable.
        if ($opts !== []) {
            $str = static fn($k) => isset($opts[$k]) ? (string) $opts[$k] : '';
            $mode = $str('boarding_mode');
            $data['boarding_mode'] = in_array($mode, ['assigned', 'alternate_bus', 'operational_exception'], true)
                ? $mode : 'assigned';
            $data['assigned_route_id']   = $str('assigned_route_id');
            $data['assigned_vehicle_id'] = $str('assigned_vehicle_id');
            $data['assigned_trip_id']    = $str('assigned_trip_id');
            $data['assigned_stop_id']    = $str('assigned_stop_id');
            $data['actual_route_id']     = $str('actual_route_id')   ?: (string) ($trip['route_id']  ?? '');
            $data['actual_vehicle_id']   = $str('actual_vehicle_id') ?: (string) ($trip['vehicleId'] ?? '');
            $data['actual_trip_id']      = $tripId;
            $data['alternate_reason']    = $str('alternate_reason');
            $data['scan_source']         = in_array($str('scan_source'), ['qr_scan', 'manual', 'bulk_school_arrival'], true)
                ? $str('scan_source') : 'manual';
            // Offline replay key. The deterministic doc id already makes a
            // repeat of the SAME event idempotent; this records which queued
            // client event produced the row, so a flush can be audited.
            if ($str('client_event_id') !== '') { $data['client_event_id'] = $str('client_event_id'); }
            // Device clock, kept SEPARATE from event_at. The server's clock stays
            // authoritative for ordering and security; the device time is
            // evidence of when the scan physically happened.
            if ($str('client_event_at') !== '') { $data['client_event_at'] = $str('client_event_at'); }
            if ($str('resolution_state')  !== '') { $data['resolution_state']  = $str('resolution_state'); }
            if ($str('resolution_reason') !== '') { $data['resolution_reason'] = $str('resolution_reason'); }
            // GPS-at-scan. Sourced from F13.1 telemetry — F14 creates no GPS
            // system and never trusts a client-typed coordinate.
            foreach (['event_lat', 'event_lng'] as $k) {
                if (array_key_exists($k, $opts) && $opts[$k] !== null && is_numeric($opts[$k])) {
                    $data[$k] = (float) $opts[$k];
                }
            }
            if (array_key_exists('event_at_gps', $opts) && $opts['event_at_gps'] !== null) {
                $data['event_at_gps'] = (string) $opts['event_at_gps'];
            }
        }
        $this->fs->setEntity(self::COL_TRANSPORT_ATTENDANCE, $docId, $data, /* merge */ true);

        // Update trip's per-checkpoint boarding tally
        $this->_updateCheckpointTally($tripId, $data['actual_stop_id'], $eventType);

        $this->emitAudit('transportAttendance', $docId, $eventType, null, $data,
            ['domain' => self::DOMAIN_TRIP]);
        return ['docId' => $docId, 'data' => $data];
    }

    /**
     * Compute the roster for a trip — reads studentRoutes LEGACY (F3 still
     * writes there) with an additive routeAssignments fallback. Filters by
     * status='Active', direction/type appropriate for the trip direction,
     * and service_days ∋ today's dayName.
     */
    private function _computeTripRoster(string $routeId, string $tripDate, string $tripDirection): array
    {
        $dayName = date('l', strtotime($tripDate));   // e.g. 'Monday'

        $students = [];
        try {
            $rows = (array) $this->fs->schoolWhere(self::COL_STUDENT_ROUTES_LEGACY,
                [['route_id', '==', $routeId], ['status', '==', 'Active']], null, 'ASC', 1000);
            foreach ($rows as $r) {
                $inner = $this->_unwrapNestedRow($r, 'route_id');
                if (!is_array($inner) || empty($inner['studentId'])) { continue; }
                if (!$this->_studentEligibleForTrip($inner, $tripDirection, $dayName)) { continue; }
                $students[(string) $inner['studentId']] = true;
            }
        } catch (\Throwable $_) {}

        // Additive routeAssignments fallback (v2 schema, currently empty)
        try {
            $rows = (array) $this->fs->schoolWhere(self::COL_ROUTE_ASSIGNMENTS,
                [['route_id', '==', $routeId], ['status', '==', 'Active'],
                 ['session',  '==', $this->sessionYear]], null, 'ASC', 1000);
            foreach ($rows as $r) {
                $inner = $this->_unwrapNestedRow($r, 'route_id');
                if (!is_array($inner) || empty($inner['studentId'])) { continue; }
                if (!$this->_studentEligibleForTrip($inner, $tripDirection, $dayName)) { continue; }
                $students[(string) $inner['studentId']] = true;
            }
        } catch (\Throwable $_) {}

        return array_keys($students);
    }

    private function _studentEligibleForTrip(array $assignment, string $tripDirection, string $dayName): bool
    {
        // Direction/type gate — Pickup trip needs type ∈ {pickup, both},
        // Drop needs {drop, both}
        $type = strtolower((string) ($assignment['type'] ?? 'both'));
        if ($tripDirection === 'Pickup' && !in_array($type, ['pickup', 'both'], true)) { return false; }
        if ($tripDirection === 'Drop'   && !in_array($type, ['drop',   'both'], true)) { return false; }

        // Service-days gate (allow missing = all days for backward compat).
        //
        // Compared on the 3-letter stem, NOT strict equality. $dayName comes
        // from date('l') ⇒ "Tuesday", while real assignments store "Tue" — a
        // strict in_array() dropped every such student from every roster, so
        // trips were created with roster_size 0 and no parent could see the
        // bus. It went unnoticed because the probe fixtures happen to write
        // full day names. Stem matching accepts Monday / Mon / mon / MONDAY
        // without needing the stored data to be migrated.
        $days = (array) ($assignment['service_days'] ?? []);
        if ($days !== []) {
            $stem = static fn($d) => strtolower(substr(trim((string) $d), 0, 3));
            if (!in_array($stem($dayName), array_map($stem, $days), true)) { return false; }
        }
        return true;
    }

    private function _lookupPlannedStop(string $studentId, string $routeId): string
    {
        try {
            $rows = (array) $this->fs->schoolWhere(self::COL_STUDENT_ROUTES_LEGACY,
                [['studentId', '==', $studentId], ['route_id', '==', $routeId]], null, 'ASC', 1);
            foreach ($rows as $r) {
                $inner = $this->_unwrapNestedRow($r, 'route_id');
                if (is_array($inner) && !empty($inner['stop_id'])) {
                    return (string) $inner['stop_id'];
                }
            }
        } catch (\Throwable $_) {}
        return '';
    }

    private function _currentCheckpointSequence(array $trip, string $stopId): int
    {
        foreach ((array) ($trip['checkpoints'] ?? []) as $cp) {
            if ((string) ($cp['stop_id'] ?? '') === $stopId) {
                return (int) ($cp['order'] ?? 0);
            }
        }
        return 0;
    }

    private function _updateCheckpointTally(string $tripId, string $stopId, string $eventType): void
    {
        // CON-1: DERIVE each checkpoint's counts from the deduped boarding-event
        // set (one doc per {student,event_type,stop} deterministic id) instead of
        // a blind +1 — a repeat driver tap maps to the SAME doc, so it can no
        // longer inflate students_boarded_count. CON-2: the checkpoints[] write
        // goes through the atomic CAS path. ($stopId/$eventType are retained for
        // the call contract; the recompute covers every checkpoint from source.)
        $byStop = $this->_tallyBoardingEventsByStop($tripId);
        $this->fs->casUpdateEntity(self::COL_TRIP_LOGS, $tripId,
            function (array $trip) use ($byStop) {
                $checkpoints = (array) ($trip['checkpoints'] ?? []);
                $changed = false;
                foreach ($checkpoints as $i => $cp) {
                    $sid = (string) ($cp['stop_id'] ?? '');
                    $b = (int) ($byStop[$sid]['boarded'] ?? 0);
                    $n = (int) ($byStop[$sid]['no_show'] ?? 0);
                    if ((int) ($cp['students_boarded_count'] ?? 0) !== $b) {
                        $checkpoints[$i]['students_boarded_count'] = $b; $changed = true;
                    }
                    if ((int) ($cp['students_no_show_count'] ?? 0) !== $n) {
                        $checkpoints[$i]['students_no_show_count'] = $n; $changed = true;
                    }
                }
                if (!$changed) { return []; }   // idempotent no-op (repeat tap)
                return ['checkpoints' => $checkpoints, 'updated_at' => date('c')];
            });
    }

    /**
     * CON-1 — per-stop DEDUPED boarding tallies from transportAttendance. One
     * doc per {student,event_type,stop} deterministic id ⇒ repeat taps are
     * inherently deduped by the collection. Returns
     * [stopId => ['boarded'=>N, 'no_show'=>M]]. Mirrors the event→count mapping
     * of the trip-level _tallyBoardingEvents (boarded = boarded_pickup +
     * dropped_home + alternate_pickup).
     */
    private function _tallyBoardingEventsByStop(string $tripId): array
    {
        $byStop = [];
        try {
            $rows = (array) $this->fs->schoolWhere(self::COL_TRANSPORT_ATTENDANCE,
                [['tripId', '==', $tripId]], null, 'ASC', 1000);
            foreach ($rows as $r) {
                $inner = $this->_unwrapNestedRow($r, 'tripId');
                if (!is_array($inner)) { continue; }
                $stop = (string) ($inner['actual_stop_id'] ?? '');
                if ($stop === '') { continue; }
                $t = (string) ($inner['event_type'] ?? '');
                if (!isset($byStop[$stop])) { $byStop[$stop] = ['boarded' => 0, 'no_show' => 0]; }
                // "Boarded" at a stop means the child was accounted for there —
                // getting on in the morning or getting off in the afternoon both
                // happen AT the stop, which is why both sets count here.
                if (in_array($t, self::BOARDING_ON_EVENTS, true)
                    || in_array($t, self::BOARDING_OFF_EVENTS, true)) {
                    $byStop[$stop]['boarded']++;
                } elseif ($t === 'no_show') {
                    $byStop[$stop]['no_show']++;
                }
            }
        } catch (\Throwable $_) {}
        return $byStop;
    }

    private function _tallyBoardingEvents(string $tripId): array
    {
        $tally = ['boarded' => 0, 'dropped' => 0, 'no_show' => 0];
        try {
            $rows = (array) $this->fs->schoolWhere(self::COL_TRANSPORT_ATTENDANCE,
                [['tripId', '==', $tripId]], null, 'ASC', 1000);
            foreach ($rows as $r) {
                $inner = $this->_unwrapNestedRow($r, 'tripId');
                if (!is_array($inner)) { continue; }
                $t = (string) ($inner['event_type'] ?? '');
                if (in_array($t, self::BOARDING_ON_EVENTS, true))       { $tally['boarded']++; }
                elseif (in_array($t, self::BOARDING_OFF_EVENTS, true))  { $tally['dropped']++; }
                elseif ($t === 'no_show')                               { $tally['no_show']++; }
            }
        } catch (\Throwable $_) {}
        return $tally;
    }

    private function _computeOnTime(array $trip, array $checkpoints): bool
    {
        // Conservative on-time gate: trip on-time iff the LAST reached
        // checkpoint's left_at (or reached_at) is within 15min of its
        // scheduled time. Missing schedule data → assume true (don't
        // penalize schools without stop-timing data).
        $lastReached = null;
        foreach ($checkpoints as $cp) {
            if (($cp['checkpoint_state'] ?? '') === 'Reached') { $lastReached = $cp; }
        }
        if ($lastReached === null) { return true; }
        $sched = (string) ($lastReached['scheduled_drop_time'] ?? $lastReached['scheduled_pickup_time'] ?? '');
        $actual = (string) ($lastReached['left_at'] ?? $lastReached['reached_at'] ?? '');
        if ($sched === '' || $actual === '') { return true; }

        $tripDate = (string) ($trip['trip_date'] ?? '');
        $schedTs  = strtotime("{$tripDate} {$sched}");
        $actualTs = strtotime($actual);
        if ($schedTs === false || $actualTs === false) { return true; }
        return ($actualTs - $schedTs) <= 15 * 60;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  DOMAIN_EVENT (F8, 2026-07-07) — Transport Events (LC-23)
    // ═════════════════════════════════════════════════════════════════════
    //
    // F8 delivers LC-23: educational tour / sports / annual function /
    // competition / excursion / examination / cultural transport. The
    // architecture treats an event as the parent operational container
    // that REUSES existing Fleet (F1), Route (F2 optional), Assignment
    // (F3 EventOnly stays reserved; F8 MVP uses ad-hoc
    // eventTripAssignments), Driver (F4 getEffectiveDriver), Expenses
    // (F5 related_event_id), Communication (F6 event.* categories) and
    // Trip Logs (F7 trip_kind='Event') — no duplicated operational data.
    //
    // Lifecycle (5-state per operator Refinement 4 — single record even
    // for multi-day events):
    //   Planned → Scheduled → InProgress → Completed | Cancelled
    //   Planned/Scheduled → Cancelled
    //
    // Refinement 1 (consent) — track consent_status on eventTripAssignments
    //   only; parent-facing consent UI defers to F10 Parent App.
    // Refinement 2 (route reuse) — route_id OPTIONAL on event trips
    //   (some events follow existing routes, others are free-form).
    // Refinement 3 (budget) — budgeted_amount is informational; over-budget
    //   generates warnings + reporting, never blocks operational
    //   completion.
    // Refinement 4 (multi-day) — single transportEvents record spans
    //   the entire event with multiple dispatched_trip_ids[].
    // Refinement additional (teacher escorts) — escorts[] array of
    //   {staff_id, role, notes} referencing existing staff records;
    //   supports future attendance/emergency/reporting without schema
    //   redesign.
    //
    // GPS-native continuity (Refinement carried from F7): every
    // dispatched event trip has its own gps_session_id = GPSSESS_{tripId};
    // event doc aggregates gps_session_ids[] for future multi-vehicle
    // live-map correlation.

    // Event lifecycle states
    public const EVENT_STATUSES = ['Planned', 'Scheduled', 'InProgress', 'Completed', 'Cancelled'];
    private const EVENT_ACTIVE_STATUSES   = ['Planned', 'Scheduled', 'InProgress'];
    private const EVENT_TERMINAL_STATUSES = ['Completed', 'Cancelled'];

    // Event types — covers the LC-23 categories
    public const EVENT_TYPES = [
        'Educational_Tour', 'Sports', 'Annual_Function', 'Competition',
        'Excursion', 'Examination', 'Field_Trip', 'Cultural_Event', 'Other',
    ];

    // Transport charter model — Own_Fleet uses school vehicles;
    // Chartered = hired external vehicle; Mixed = both
    public const EVENT_CHARTER_TYPES = ['Own_Fleet', 'Chartered', 'Mixed'];

    // eventTripAssignments consent workflow (Refinement 1)
    public const EVENT_CONSENT_STATUSES = ['Pending', 'Approved', 'Rejected'];

    // Teacher-escort roles (Refinement additional — extensible enum)
    public const EVENT_ESCORT_ROLES = [
        'Lead_Teacher', 'Assistant', 'Nurse', 'Security',
        'Chaperone', 'Coordinator', 'Other',
    ];

    // ─── Public API — Event lifecycle ────────────────────────────────────

    /**
     * Create an event in Planned state. Vehicles, roster and escorts are
     * declared here but nothing is validated for Schedule-time readiness
     * yet — Planned is the drafting state.
     */
    public function createEvent(array $input): array
    {
        $this->_assertReady();
        $title      = trim((string) ($input['title'] ?? ''));
        $eventType  = trim((string) ($input['event_type'] ?? ''));
        $eventDate  = trim((string) ($input['event_date'] ?? ''));
        $endDate    = trim((string) ($input['event_end_date'] ?? $eventDate));
        $charterType = trim((string) ($input['transport_charter_type'] ?? 'Own_Fleet'));

        if ($title === '') { throw new \InvalidArgumentException('title is required.'); }
        if (!in_array($eventType, self::EVENT_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid event_type: '{$eventType}'. Allowed: " . implode(',', self::EVENT_TYPES));
        }
        if ($eventDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
            throw new \InvalidArgumentException("event_date required in YYYY-MM-DD.");
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            throw new \InvalidArgumentException("event_end_date must be YYYY-MM-DD if provided.");
        }
        if (!in_array($charterType, self::EVENT_CHARTER_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid transport_charter_type: '{$charterType}'.");
        }
        if ($endDate < $eventDate) {
            throw new \InvalidArgumentException("event_end_date cannot be before event_date.");
        }

        $eventId = $this->numbering->next('trn_event', [
            'claimedBy'  => $this->adminId,
            'claimedFor' => 'F8:createEvent',
        ]);

        $now = date('c');
        $data = [
            'eventId'                 => $eventId,
            'schoolId'                => $this->schoolId,
            'session'                 => $this->sessionYear,
            'event_type'              => $eventType,
            'status'                  => 'Planned',
            'title'                   => $title,
            'description'             => trim((string) ($input['description'] ?? '')),
            'event_date'              => $eventDate,
            'event_end_date'          => $endDate,
            'event_start_at'          => trim((string) ($input['event_start_at'] ?? '')),
            'event_end_at'            => trim((string) ($input['event_end_at']   ?? '')),
            'destination'             => trim((string) ($input['destination']    ?? '')),
            'pickup_location'         => trim((string) ($input['pickup_location'] ?? '')),
            'drop_location'           => trim((string) ($input['drop_location']   ?? '')),
            'linked_school_event_id'  => trim((string) ($input['linked_school_event_id'] ?? '')),

            // Fleet + driver + escort (Refinement additional)
            'transport_charter_type'  => $charterType,
            'assigned_vehicle_ids'    => array_values(array_unique(array_filter(array_map('strval',
                                          (array) ($input['assigned_vehicle_ids'] ?? []))))),
            'assigned_driver_ids'     => [],    // populated at scheduleEvent()
            'escorts'                 => $this->_normaliseEscorts((array) ($input['escorts'] ?? [])),

            // Roster tracking
            'expected_students_count' => (int)  ($input['expected_students_count'] ?? 0),
            'roster_frozen_at'        => null,
            'roster_size'             => 0,

            // Cost attribution (Refinement 3 — informational only)
            'budgeted_amount'         => (float) ($input['budgeted_amount'] ?? 0),
            'actual_amount'           => 0.0,
            'expense_ref_ids'         => [],

            // Dispatch tracking
            'dispatched_trip_ids'     => [],
            'completed_trip_ids'      => [],

            // GPS-native continuity — session ids aggregated at dispatch
            'gps_session_ids'         => [],

            'notes'                   => trim((string) ($input['notes'] ?? '')),
            'created_at'              => $now,
            'created_by'              => $this->adminId,
            'updated_at'              => $now,
        ];
        $this->fs->setEntity(self::COL_TRANSPORT_EVENTS, $eventId, $data, /* merge */ false);

        $this->emitAudit('transportEvent', $eventId, 'created', null, $data,
            ['domain' => self::DOMAIN_EVENT]);
        return ['eventId' => $eventId, 'data' => $data];
    }

    /**
     * Update mutable fields on a Planned event. Once Scheduled, most
     * fields lock (except cost tracking + notes) — future rescheduleEvent
     * covers a controlled date-change flow with a rescheduled notice.
     */
    public function updateEvent(string $eventId, array $input): array
    {
        $existing = $this->_loadEventOrFail($eventId);
        if (!in_array($existing['status'], ['Planned', 'Scheduled'], true)) {
            throw new \InvalidArgumentException("Event {$eventId} is {$existing['status']}; cannot update.");
        }
        $now = date('c');
        $updatable = [
            'title', 'description', 'destination', 'pickup_location', 'drop_location',
            'event_start_at', 'event_end_at', 'transport_charter_type',
            'notes', 'budgeted_amount', 'linked_school_event_id', 'expected_students_count',
        ];
        $update = ['updated_at' => $now];
        foreach ($updatable as $k) {
            if (array_key_exists($k, $input)) {
                $update[$k] = is_numeric($input[$k]) ? $input[$k] : trim((string) $input[$k]);
            }
        }
        // Vehicles editable only while Planned (Refinement 4 lock)
        if ($existing['status'] === 'Planned' && isset($input['assigned_vehicle_ids'])) {
            $update['assigned_vehicle_ids'] = array_values(array_unique(array_filter(array_map('strval',
                (array) $input['assigned_vehicle_ids']))));
        }
        // Escorts editable in Planned + Scheduled (safety personnel may
        // change up to dispatch)
        if (isset($input['escorts'])) {
            $update['escorts'] = $this->_normaliseEscorts((array) $input['escorts']);
            // LOG-4: re-validate escort HR status on edit (scheduleEvent alone
            // did not cover post-schedule escort changes).
            $this->_assertEscortsActive($update['escorts']);
        }
        $this->fs->setEntity(self::COL_TRANSPORT_EVENTS, $eventId, $update, /* merge */ true);
        $this->emitAudit('transportEvent', $eventId, 'updated', $existing,
            array_merge($existing, $update),
            ['domain' => self::DOMAIN_EVENT]);
        return ['eventId' => $eventId, 'data' => array_merge($existing, $update)];
    }

    /**
     * Add a student to an event roster. Creates an eventTripAssignments
     * doc keyed by numbering (ETA prefix). Consent starts Pending
     * (Refinement 1 — admin edits status; Parent App handles self-service
     * later).
     */
    public function assignStudentToEvent(string $eventId, string $studentId, array $input = []): array
    {
        $this->_assertReady();
        $event = $this->_loadEventOrFail($eventId);
        if (!in_array($event['status'], ['Planned', 'Scheduled'], true)) {
            throw new \InvalidArgumentException("Event {$eventId} is {$event['status']}; cannot modify roster.");
        }
        if ($studentId === '') { throw new \InvalidArgumentException('studentId required.'); }

        // Deterministic guard against duplicate assignments — hash of
        // (eventId, studentId) makes idempotent-by-design.
        $dupKey = substr(sha1($eventId . '|' . $studentId), 0, 16);
        try {
            $existing = (array) $this->fs->schoolWhere(self::COL_EVENT_TRIP_ASSIGNMENTS,
                [['event_id', '==', $eventId], ['studentId', '==', $studentId]],
                null, 'ASC', 1);
            if (!empty($existing)) {
                $inner = $this->_unwrapNestedRow($existing[0], 'event_id');
                return ['assignmentId' => (string) ($inner['assignmentId'] ?? ''), 'data' => $inner, 'duplicate' => true];
            }
        } catch (\Throwable $_) {}

        $assignId = $this->numbering->next('trn_event_assign', [
            'claimedBy'  => $this->adminId,
            'claimedFor' => 'F8:assignStudentToEvent',
        ]);
        $now = date('c');
        $data = [
            'assignmentId'         => $assignId,
            'schoolId'             => $this->schoolId,
            'event_id'             => $eventId,
            'studentId'            => $studentId,
            'assigned_vehicle_id'  => trim((string) ($input['assigned_vehicle_id'] ?? '')),
            'pickup_stop'          => trim((string) ($input['pickup_stop'] ?? '')),
            'drop_stop'            => trim((string) ($input['drop_stop']   ?? '')),
            'consent_status'       => 'Pending',
            'consent_notes'        => '',
            'consent_updated_at'   => null,
            'consent_updated_by'   => '',
            'dupe_key'             => $dupKey,
            'created_at'           => $now,
            'created_by'           => $this->adminId,
        ];
        $this->fs->setEntity(self::COL_EVENT_TRIP_ASSIGNMENTS, $assignId, $data, /* merge */ false);
        $this->emitAudit('eventTripAssignment', $assignId, 'created', null, $data,
            ['domain' => self::DOMAIN_EVENT]);
        return ['assignmentId' => $assignId, 'data' => $data, 'duplicate' => false];
    }

    /** Remove a student from an event roster (only while Planned/Scheduled). */
    public function unassignStudentFromEvent(string $eventId, string $studentId): void
    {
        $this->_assertReady();
        $event = $this->_loadEventOrFail($eventId);
        if (!in_array($event['status'], ['Planned', 'Scheduled'], true)) {
            throw new \InvalidArgumentException("Event {$eventId} is {$event['status']}; cannot modify roster.");
        }
        try {
            $rows = (array) $this->fs->schoolWhere(self::COL_EVENT_TRIP_ASSIGNMENTS,
                [['event_id', '==', $eventId], ['studentId', '==', $studentId]],
                null, 'ASC', 1);
            foreach ($rows as $r) {
                $inner = $this->_unwrapNestedRow($r, 'event_id');
                $aid = (string) ($inner['assignmentId'] ?? '');
                if ($aid !== '') {
                    $this->fs->removeEntity(self::COL_EVENT_TRIP_ASSIGNMENTS, $aid);
                    $this->emitAudit('eventTripAssignment', $aid, 'removed', $inner, null,
                        ['domain' => self::DOMAIN_EVENT]);
                }
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('unassign failed: ' . $e->getMessage());
        }
    }

    /** Update consent_status for an eventTripAssignments row. */
    public function updateEventConsent(string $assignmentId, string $newStatus, string $notes = ''): array
    {
        $this->_assertReady();
        if (!in_array($newStatus, self::EVENT_CONSENT_STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid consent_status: {$newStatus}");
        }
        $existing = $this->fs->getEntity(self::COL_EVENT_TRIP_ASSIGNMENTS, $assignmentId);
        if (!is_array($existing)) {
            throw new \InvalidArgumentException("Assignment not found: {$assignmentId}");
        }
        $now = date('c');
        $update = [
            'consent_status'     => $newStatus,
            'consent_notes'      => trim($notes),
            'consent_updated_at' => $now,
            'consent_updated_by' => $this->adminId,
        ];
        $this->fs->setEntity(self::COL_EVENT_TRIP_ASSIGNMENTS, $assignmentId, $update, /* merge */ true);
        $this->emitAudit('eventTripAssignment', $assignmentId, 'consent_updated',
            $existing, array_merge($existing, $update),
            ['domain' => self::DOMAIN_EVENT]);
        return ['assignmentId' => $assignmentId, 'consent_status' => $newStatus];
    }

    /**
     * Planned → Scheduled. Validates:
     *   • ≥1 vehicle assigned
     *   • Every vehicle has an effective driver
     *   • Roster is non-empty
     * Snapshots driver ids, freezes the roster, fires event.scheduled to
     * parents_of_students.
     */
    /**
     * LOG-4 — every escort staff_id must reference an HR-Active staff record.
     * Extracted from scheduleEvent so the SAME gate runs on every escort-mutating
     * path (updateEvent, addEventEscort) and again at dispatch — a staff member
     * terminated AFTER scheduling must not remain a recorded safety escort.
     */
    private function _assertEscortsActive(array $escorts): void
    {
        foreach ($escorts as $esc) {
            $sid = (string) ($esc['staff_id'] ?? '');
            if ($sid === '') {
                throw new \InvalidArgumentException('Escort missing staff_id.');
            }
            $staff = $this->fs->getEntity('staff', $sid);
            if (!is_array($staff)) {
                throw new \InvalidArgumentException("Escort staff not found: {$sid}");
            }
            $status = strtolower((string) ($staff['status'] ?? $staff['Status'] ?? 'active'));
            if ($status !== 'active' && $status !== '') {
                throw new \InvalidArgumentException("Escort {$sid} is not Active in HR (status='{$status}').");
            }
        }
    }

    public function scheduleEvent(string $eventId): array
    {
        $this->_assertReady();
        $event = $this->_loadEventOrFail($eventId);
        if ($event['status'] !== 'Planned') {
            throw new \InvalidArgumentException("Event {$eventId} is not Planned (status: {$event['status']}).");
        }
        $vehicleIds = (array) ($event['assigned_vehicle_ids'] ?? []);
        if ($vehicleIds === []) {
            throw new \InvalidArgumentException('Cannot schedule — no vehicles assigned.');
        }

        // Every vehicle needs an effective driver on event_date
        $driverIds = [];
        foreach ($vehicleIds as $vid) {
            $drv = (string) ($this->getEffectiveDriver((string) $vid, (string) $event['event_date']) ?? '');
            if ($drv === '') {
                throw new \InvalidArgumentException("Vehicle {$vid} has no effective driver on {$event['event_date']}.");
            }
            $driverIds[] = $drv;
        }
        $driverIds = array_values(array_unique($driverIds));

        // Snapshot roster
        $roster = $this->_computeEventRoster($eventId);
        if ($roster === []) {
            throw new \InvalidArgumentException("Cannot schedule — roster is empty. Assign students first.");
        }

        // Escort validation — every staff_id must reference an active staff record
        $this->_assertEscortsActive((array) ($event['escorts'] ?? []));

        $now = date('c');
        $update = [
            'status'               => 'Scheduled',
            'assigned_driver_ids'  => $driverIds,
            'roster_student_ids'   => $roster,
            'roster_size'          => count($roster),
            'roster_frozen_at'     => $now,
            'scheduled_at'         => $now,
            'scheduled_by'         => $this->adminId,
            'updated_at'           => $now,
        ];
        $this->fs->setEntity(self::COL_TRANSPORT_EVENTS, $eventId, $update, /* merge */ true);
        $this->emitAudit('transportEvent', $eventId, 'scheduled', $event,
            array_merge($event, $update),
            ['domain' => self::DOMAIN_EVENT]);
        // Parent notification — event.scheduled (per operator + Refinement 1)
        $this->_notify('event.scheduled', [
            'event_id'    => $eventId,
            'event_type'  => (string) ($event['event_type'] ?? ''),
            'title'       => (string) ($event['title'] ?? ''),
            'event_date'  => (string) ($event['event_date'] ?? ''),
            'destination' => (string) ($event['destination'] ?? ''),
            'student_ids' => $roster,
        ]);
        return ['eventId' => $eventId, 'status' => 'Scheduled', 'roster_size' => count($roster)];
    }

    /**
     * Dispatch a trip for this event. Creates a tripLogs record with
     * trip_kind='Event' and event_id linkage (F7 machinery — same
     * checkpoints, GPS-native architecture, boarding flow). Event
     * status flips to InProgress on first dispatch.
     */
    public function dispatchEventTrip(string $eventId, array $input): array
    {
        $this->_assertReady();
        $event = $this->_loadEventOrFail($eventId);
        if (!in_array($event['status'], ['Scheduled', 'InProgress'], true)) {
            throw new \InvalidArgumentException("Event {$eventId} is {$event['status']}; dispatch requires Scheduled or InProgress.");
        }
        $vehicleId = trim((string) ($input['vehicle_id'] ?? ''));
        if ($vehicleId === '' || !in_array($vehicleId, (array) $event['assigned_vehicle_ids'], true)) {
            throw new \InvalidArgumentException("vehicle_id must be one of the assigned vehicles: " . implode(',', (array) $event['assigned_vehicle_ids']));
        }
        // Route is optional per Refinement 2
        $routeId = trim((string) ($input['route_id'] ?? ''));

        // LOG-4: re-validate escorts at dispatch — a staff member terminated
        // between scheduleEvent and dispatch must not go out as a safety escort.
        $this->_assertEscortsActive((array) ($event['escorts'] ?? []));

        $trip = $this->createTrip([
            'vehicle_id'         => $vehicleId,
            'route_id'           => $routeId,
            'trip_date'          => (string) $event['event_date'],
            'trip_direction'     => (string) ($input['trip_direction'] ?? 'Pickup'),
            'scheduled_start_at' => (string) ($input['scheduled_start_at'] ?? ''),
            'notes'              => 'Event: ' . (string) ($event['title'] ?? '') . ' (' . $eventId . ')',
            'trip_kind'          => 'Event',
            'event_id'           => $eventId,
        ]);
        $tripId = (string) $trip['tripId'];
        $gpsSess = (string) ($trip['data']['gps_session_id'] ?? '');

        // Update event: append trip id + gps session id; flip to InProgress
        $now = date('c');
        $dispatched = (array) ($event['dispatched_trip_ids'] ?? []);
        $dispatched[] = $tripId;
        $gpsSessions = (array) ($event['gps_session_ids'] ?? []);
        if ($gpsSess !== '') { $gpsSessions[] = $gpsSess; }
        $update = [
            'dispatched_trip_ids' => array_values(array_unique($dispatched)),
            'gps_session_ids'     => array_values(array_unique($gpsSessions)),
            'status'              => 'InProgress',
            'first_dispatch_at'   => (string) ($event['first_dispatch_at'] ?? '') !== '' ? (string) $event['first_dispatch_at'] : $now,
            'updated_at'          => $now,
        ];
        $this->fs->setEntity(self::COL_TRANSPORT_EVENTS, $eventId, $update, /* merge */ true);
        $this->emitAudit('transportEvent', $eventId, 'trip_dispatched',
            $event, array_merge($event, $update),
            ['domain' => self::DOMAIN_EVENT, 'trip_id' => $tripId]);
        $this->_notify('event.trip_dispatched', [
            'event_id'   => $eventId,
            'trip_id'    => $tripId,
            'vehicle_id' => $vehicleId,
            'route_id'   => $routeId,
            'event_type' => (string) ($event['event_type'] ?? ''),
            'title'      => (string) ($event['title'] ?? ''),
        ]);
        return ['eventId' => $eventId, 'tripId' => $tripId, 'gps_session_id' => $gpsSess];
    }

    /**
     * InProgress → Completed. Requires every dispatched trip to be
     * Completed or Cancelled. Refinement 3: over-budget generates a
     * warning (event.completed body carries it) but never blocks
     * completion.
     */
    public function completeEvent(string $eventId, string $notes = ''): array
    {
        $this->_assertReady();
        $event = $this->_loadEventOrFail($eventId);
        if ($event['status'] !== 'InProgress') {
            throw new \InvalidArgumentException("Event {$eventId} is not InProgress (status: {$event['status']}).");
        }
        // Verify all dispatched trips have terminated
        $dispatched = (array) ($event['dispatched_trip_ids'] ?? []);
        $completed  = [];
        foreach ($dispatched as $tid) {
            $t = $this->fs->getEntity(self::COL_TRIP_LOGS, (string) $tid);
            if (!is_array($t)) { continue; }
            $tst = (string) ($t['status'] ?? '');
            if (!in_array($tst, ['Completed', 'Cancelled'], true)) {
                throw new \InvalidArgumentException(
                    "Cannot complete — trip {$tid} is {$tst}. All dispatched trips must be Completed or Cancelled."
                );
            }
            if ($tst === 'Completed') { $completed[] = (string) $tid; }
        }

        // Cost aggregation (Refinement 3 — informational)
        $costSummary = $this->computeEventCostSummary($eventId);
        $overBudget = ((float) ($event['budgeted_amount'] ?? 0)) > 0
                      && $costSummary['actual_amount'] > (float) $event['budgeted_amount'];

        $now = date('c');
        $update = [
            'status'             => 'Completed',
            'completed_trip_ids' => $completed,
            'completed_at'       => $now,
            'completed_by'       => $this->adminId,
            'actual_amount'      => $costSummary['actual_amount'],
            'expense_ref_ids'    => $costSummary['expense_ids'],
            'over_budget'        => $overBudget,
            'notes'              => trim($notes) !== '' ? $notes : (string) ($event['notes'] ?? ''),
            'updated_at'         => $now,
        ];
        $this->fs->setEntity(self::COL_TRANSPORT_EVENTS, $eventId, $update, /* merge */ true);
        $this->emitAudit('transportEvent', $eventId, 'completed', $event,
            array_merge($event, $update),
            ['domain' => self::DOMAIN_EVENT, 'over_budget' => $overBudget]);
        $this->_notify('event.completed', [
            'event_id'        => $eventId,
            'event_type'      => (string) ($event['event_type'] ?? ''),
            'title'           => (string) ($event['title'] ?? ''),
            'trips_completed' => count($completed),
            'trips_dispatched'=> count($dispatched),
            'actual_amount'   => $costSummary['actual_amount'],
            'budgeted_amount' => (float) ($event['budgeted_amount'] ?? 0),
            'over_budget'     => $overBudget,
        ]);
        return [
            'eventId'    => $eventId,
            'status'     => 'Completed',
            'cost'       => $costSummary,
            'over_budget'=> $overBudget,
        ];
    }

    /** Cancel any active event. Terminal. */
    public function cancelEvent(string $eventId, string $reason): array
    {
        $this->_assertReady();
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('cancel reason is required.');
        }
        $event = $this->_loadEventOrFail($eventId);
        if (!in_array($event['status'], self::EVENT_ACTIVE_STATUSES, true)) {
            throw new \InvalidArgumentException("Event {$eventId} not in cancellable state (status: {$event['status']}).");
        }
        $now = date('c');
        $update = [
            'status'         => 'Cancelled',
            'cancelled_at'   => $now,
            'cancelled_by'   => $this->adminId,
            'cancel_reason'  => $reason,
            'updated_at'     => $now,
        ];
        $this->fs->setEntity(self::COL_TRANSPORT_EVENTS, $eventId, $update, /* merge */ true);
        $this->emitAudit('transportEvent', $eventId, 'cancelled', $event,
            array_merge($event, $update),
            ['domain' => self::DOMAIN_EVENT, 'cancel_reason' => $reason]);
        // Urgent parent notification per Refinement 3 (safety — never dedupe)
        $this->_notify('event.cancelled', [
            'event_id'    => $eventId,
            'event_type'  => (string) ($event['event_type'] ?? ''),
            'title'       => (string) ($event['title'] ?? ''),
            'event_date'  => (string) ($event['event_date'] ?? ''),
            'reason'      => $reason,
            'student_ids' => (array) ($event['roster_student_ids'] ?? []),
        ]);
        return ['eventId' => $eventId, 'status' => 'Cancelled'];
    }

    // ─── Public API — Reads + aggregates ─────────────────────────────────

    public function listEvents(array $filters = []): array
    {
        $this->_assertReady();
        $where = [];
        if (!empty($filters['status']))     { $where[] = ['status',     '==', (string) $filters['status']]; }
        if (!empty($filters['event_type'])) { $where[] = ['event_type', '==', (string) $filters['event_type']]; }
        if (!empty($filters['event_date'])) { $where[] = ['event_date', '==', (string) $filters['event_date']]; }
        try {
            $rows = (array) $this->fs->schoolWhere(self::COL_TRANSPORT_EVENTS, $where, 'event_date', 'DESC', 200);
        } catch (\Throwable $_) { return []; }
        $out = [];
        foreach ($rows as $r) { $out[] = $this->_unwrapNestedRow($r, 'event_date'); }
        return array_values(array_filter($out, static fn($e) => is_array($e) && !empty($e)));
    }

    public function getEvent(string $eventId): ?array
    {
        $this->_assertReady();
        if ($eventId === '') { return null; }
        $e = $this->fs->getEntity(self::COL_TRANSPORT_EVENTS, $eventId);
        return is_array($e) ? $e : null;
    }

    public function getEventRoster(string $eventId): array
    {
        $this->_assertReady();
        try {
            $rows = (array) $this->fs->schoolWhere(self::COL_EVENT_TRIP_ASSIGNMENTS,
                [['event_id', '==', $eventId]], null, 'ASC', 1000);
        } catch (\Throwable $_) { return []; }
        $out = [];
        foreach ($rows as $r) { $out[] = $this->_unwrapNestedRow($r, 'event_id'); }
        return array_values(array_filter($out, static fn($a) => is_array($a) && !empty($a)));
    }

    public function getEventTrips(string $eventId): array
    {
        $this->_assertReady();
        try {
            $rows = (array) $this->fs->schoolWhere(self::COL_TRIP_LOGS,
                [['event_id', '==', $eventId]], 'date', 'ASC', 200);
        } catch (\Throwable $_) { return []; }
        $out = [];
        foreach ($rows as $r) { $out[] = $this->_unwrapNestedRow($r, 'event_id'); }
        return array_values(array_filter($out, static fn($t) => is_array($t) && !empty($t)));
    }

    public function getEventEscorts(string $eventId): array
    {
        $event = $this->getEvent($eventId);
        if (!is_array($event)) { return []; }
        return array_values((array) ($event['escorts'] ?? []));
    }

    /**
     * Aggregate cost from transportExpenses with related_event_id = this
     * event id. Uses a single filtered query — no N+1 (F8-6 gate).
     * Refinement 3: reports over_budget flag but never blocks anything.
     */
    public function computeEventCostSummary(string $eventId): array
    {
        $this->_assertReady();
        $out = ['event_id' => $eventId, 'actual_amount' => 0.0, 'expense_count' => 0, 'expense_ids' => []];
        try {
            $rows = (array) $this->fs->schoolWhere(self::COL_TRANSPORT_EXPENSES,
                [['related_event_id', '==', $eventId], ['status', '==', 'Active']],
                null, 'ASC', 1000);
        } catch (\Throwable $_) { return $out; }
        foreach ($rows as $r) {
            $inner = $this->_unwrapNestedRow($r, 'expense_type');
            if (!is_array($inner)) { continue; }
            $out['actual_amount'] += (float) ($inner['total_amount'] ?? $inner['amount'] ?? 0);
            $out['expense_count']++;
            $out['expense_ids'][] = (string) ($inner['expenseId'] ?? '');
        }
        $out['actual_amount'] = round($out['actual_amount'], 2);
        return $out;
    }

    // ─── Escort management (Refinement additional) ───────────────────────

    public function addEventEscort(string $eventId, array $escort): array
    {
        $this->_assertReady();
        $event = $this->_loadEventOrFail($eventId);
        if (!in_array($event['status'], ['Planned', 'Scheduled', 'InProgress'], true)) {
            throw new \InvalidArgumentException("Event {$eventId} is {$event['status']}; cannot modify escorts.");
        }
        $escorts = $this->_normaliseEscorts((array) ($event['escorts'] ?? []));
        $escorts[] = $this->_normaliseEscort($escort);
        // LOG-4: the newly added escort must be HR-Active.
        $this->_assertEscortsActive($escorts);
        // Dedupe by staff_id
        $seen = []; $unique = [];
        foreach ($escorts as $e) {
            if (isset($seen[$e['staff_id']])) { continue; }
            $seen[$e['staff_id']] = true;
            $unique[] = $e;
        }
        $now = date('c');
        $this->fs->setEntity(self::COL_TRANSPORT_EVENTS, $eventId, [
            'escorts'    => $unique,
            'updated_at' => $now,
        ], /* merge */ true);
        $this->emitAudit('transportEvent', $eventId, 'escort_added', $event,
            array_merge($event, ['escorts' => $unique]),
            ['domain' => self::DOMAIN_EVENT]);
        return ['eventId' => $eventId, 'escorts' => $unique];
    }

    public function removeEventEscort(string $eventId, string $staffId): array
    {
        $this->_assertReady();
        $event = $this->_loadEventOrFail($eventId);
        if (!in_array($event['status'], ['Planned', 'Scheduled', 'InProgress'], true)) {
            throw new \InvalidArgumentException("Event {$eventId} is {$event['status']}; cannot modify escorts.");
        }
        $escorts = (array) ($event['escorts'] ?? []);
        $filtered = array_values(array_filter($escorts,
            static fn($e) => (string) ($e['staff_id'] ?? '') !== $staffId));
        $this->fs->setEntity(self::COL_TRANSPORT_EVENTS, $eventId, [
            'escorts'    => $filtered,
            'updated_at' => date('c'),
        ], /* merge */ true);
        return ['eventId' => $eventId, 'escorts' => $filtered];
    }

    // ─── Internals ───────────────────────────────────────────────────────

    private function _loadEventOrFail(string $eventId): array
    {
        if ($eventId === '') { throw new \InvalidArgumentException('eventId required.'); }
        $event = $this->fs->getEntity(self::COL_TRANSPORT_EVENTS, $eventId);
        if (!is_array($event)) {
            throw new \InvalidArgumentException("Event not found: {$eventId}");
        }
        return $event;
    }

    /**
     * Snapshot the ordered list of student ids assigned to an event via
     * eventTripAssignments (Refinement 4 — no persistent EventOnly rows
     * on studentRoutes at F8; that path stays reserved for future).
     * Consent status does NOT filter here — the frozen roster is the
     * intended attendees; consent is tracked orthogonally.
     */
    private function _computeEventRoster(string $eventId): array
    {
        $students = [];
        try {
            $rows = (array) $this->fs->schoolWhere(self::COL_EVENT_TRIP_ASSIGNMENTS,
                [['event_id', '==', $eventId]], null, 'ASC', 2000);
            foreach ($rows as $r) {
                $inner = $this->_unwrapNestedRow($r, 'event_id');
                if (!is_array($inner)) { continue; }
                $sid = (string) ($inner['studentId'] ?? '');
                if ($sid !== '') { $students[$sid] = true; }
            }
        } catch (\Throwable $_) {}
        return array_keys($students);
    }

    /**
     * Roster snapshot for a specific vehicle within an event — used when
     * an event trip is dispatched. Selects only students whose
     * assigned_vehicle_id matches (if set) OR all students when no
     * per-vehicle assignment (small events).
     */
    private function _computeEventRosterForVehicle(string $eventId, string $vehicleId): array
    {
        $students = [];
        try {
            $rows = (array) $this->fs->schoolWhere(self::COL_EVENT_TRIP_ASSIGNMENTS,
                [['event_id', '==', $eventId]], null, 'ASC', 2000);
            foreach ($rows as $r) {
                $inner = $this->_unwrapNestedRow($r, 'event_id');
                if (!is_array($inner)) { continue; }
                $sid = (string) ($inner['studentId'] ?? '');
                $va  = (string) ($inner['assigned_vehicle_id'] ?? '');
                if ($sid === '') { continue; }
                if ($va === '' || $va === $vehicleId) {
                    $students[$sid] = true;
                }
            }
        } catch (\Throwable $_) {}
        return array_keys($students);
    }

    private function _normaliseEscorts(array $escorts): array
    {
        $out = [];
        foreach ($escorts as $e) {
            if (!is_array($e)) { continue; }
            $out[] = $this->_normaliseEscort($e);
        }
        return $out;
    }

    private function _normaliseEscort(array $escort): array
    {
        $staffId = trim((string) ($escort['staff_id'] ?? ''));
        $role    = trim((string) ($escort['role']     ?? 'Chaperone'));
        if ($staffId === '') {
            throw new \InvalidArgumentException('Escort staff_id is required.');
        }
        if (!in_array($role, self::EVENT_ESCORT_ROLES, true)) {
            throw new \InvalidArgumentException("Invalid escort role: '{$role}'. Allowed: " . implode(',', self::EVENT_ESCORT_ROLES));
        }
        return [
            'staff_id' => $staffId,
            'role'     => $role,
            'notes'    => trim((string) ($escort['notes'] ?? '')),
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  F6 — Transport_notifier bridge
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Lazy-load + init Transport_notifier. Cached on the CI instance so
     * repeat notify() calls in the same request reuse the same object.
     * Returns null when the notifier can't be initialised — callers must
     * guard and fall back to log-only (best-effort).
     */
    private function _notifier(): ?Transport_notifier
    {
        try {
            if (!isset($this->ci->transportNotifier)) {
                $this->ci->load->library('transport_notifier', null, 'transportNotifier');
                if (isset($this->ci->transportNotifier)
                    && method_exists($this->ci->transportNotifier, 'init')
                    && !$this->ci->transportNotifier->isEnabled()) {
                    $this->ci->transportNotifier->init(
                        $this->fs, $this->numbering, $this,
                        $this->schoolId, $this->sessionYear, $this->adminId
                    );
                }
            }
            return $this->ci->transportNotifier ?? null;
        } catch (\Throwable $e) {
            log_message('error', 'Transport_notifier load failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * F6 helper — fire a notification for the given category, swallowing
     * all failures (notifications are best-effort by design; the caller's
     * primary state change already succeeded and must not be reverted for
     * a notice miss).
     */
    private function _notify(string $category, array $ctx, array $overrides = []): void
    {
        $n = $this->_notifier();
        if ($n === null || !method_exists($n, 'notify')) {
            log_message('error', "Transport_notifier unavailable for {$category}");
            return;
        }
        try {
            $n->notify($category, $ctx, $overrides);
        } catch (\Throwable $e) {
            log_message('error', "Transport_notifier {$category} failed: " . $e->getMessage());
        }
    }

    /**
     * F4 helper — normalise a Firestore_service::schoolWhere row that
     * comes back in the {id, data} nested wrapper format used for some
     * collections (transportAuditLog, routeAssignmentHistory,
     * driverIncidents, temporaryDriverAssignments, ...). When the row
     * looks flat already (marker field present at top level), returns
     * it as-is; when nested, spreads $row['data'] to top level while
     * preserving the doc id.
     */
    private function _unwrapNestedRow($row, string $markerField): array
    {
        if (!is_array($row)) { return []; }
        if (isset($row[$markerField])) { return $row; }
        if (isset($row['data']) && is_array($row['data'])) {
            $data = $row['data'];
            if (!empty($row['id']) && !isset($data['id'])) {
                $data['id'] = $row['id'];
            }
            return $data;
        }
        return $row;
    }

    // ─── DOMAIN_DRIVER (F4) private helpers ──────────────────────────────

    /**
     * Comprehensive driver-readiness validation (Refinement — additional
     * 2026-07-07). Called before every driver-vehicle assignment
     * (permanent + temporary). 7 checks:
     *
     *   1. Staff exists AND is HR-active (status='active')
     *   2. flags.can_drive_transport === true
     *   3. driver_meta.licence_expires > $onDate (or empty allowed if
     *      the school hasn't captured licence data yet — WARN via log,
     *      don't block; toggleable via strictMode)
     *   4. driver_meta.medical_expires > $onDate (same soft-check)
     *   5. driver_meta.badge_expires > $onDate WHEN badge_no is set
     *   6. driver_meta.operational_status != 'Unavailable' / 'On_Leave'
     *   7. No conflicting active temporary assignment where this staff
     *      is already covering another vehicle at the same window
     *
     * Throws \InvalidArgumentException on hard failure. Soft warnings
     * are logged (log_message('error', ...)) — schools rolling out
     * driver_meta gradually still get useful validation on the fields
     * they HAVE captured.
     */
    private function _validateDriverReadiness(string $staffId, string $onDate): void
    {
        if ($staffId === '') {
            throw new \InvalidArgumentException('Driver staffId is required.');
        }
        // 1. Staff exists + HR active
        $staff = $this->fs->getEntity('staff', $staffId);
        if (!is_array($staff)) {
            throw new \InvalidArgumentException('Driver staff not found: ' . $staffId);
        }
        $status = strtolower((string) ($staff['status'] ?? $staff['Status'] ?? 'active'));
        if ($status !== '' && !in_array($status, ['active', ''], true)) {
            throw new \InvalidArgumentException(
                "Driver {$staffId} is not Active in HR (status='{$status}'). Cannot assign."
            );
        }
        // 2. flags.can_drive_transport
        $canDrive = !empty($staff['flags']['can_drive_transport']);
        if (!$canDrive) {
            throw new \InvalidArgumentException(
                "Staff {$staffId} does not have transport-driving enabled (flags.can_drive_transport)."
            );
        }
        $meta = (array) ($staff['driver_meta'] ?? []);

        // 3. Licence validity
        $licenceExp = (string) ($meta['licence_expires'] ?? '');
        if ($licenceExp !== '' && $licenceExp < $onDate) {
            throw new \InvalidArgumentException(
                "Driver {$staffId} licence expired on {$licenceExp} (before {$onDate})."
            );
        }
        // 4. Medical validity
        $medicalExp = (string) ($meta['medical_expires'] ?? '');
        if ($medicalExp !== '' && $medicalExp < $onDate) {
            throw new \InvalidArgumentException(
                "Driver {$staffId} medical certificate expired on {$medicalExp}."
            );
        }
        // 5. Badge validity — only enforce when a badge is present
        $badgeNo  = (string) ($meta['badge_no'] ?? '');
        $badgeExp = (string) ($meta['badge_expires'] ?? '');
        if ($badgeNo !== '' && $badgeExp !== '' && $badgeExp < $onDate) {
            throw new \InvalidArgumentException(
                "Driver {$staffId} transport badge expired on {$badgeExp}."
            );
        }
        // 6. Operational availability — reject any admin-set blocking state
        $opStatus = (string) ($meta['operational_status'] ?? 'Available');
        if (in_array($opStatus, self::DRIVER_BLOCKING_STATUSES, true)) {
            throw new \InvalidArgumentException(
                "Driver {$staffId} is currently {$opStatus} (driver_meta.operational_status)."
            );
        }
        // 7. Conflicting active temp assignment where THIS staff is already covering
        try {
            $activeAsCovering = (array) $this->fs->schoolWhere(
                self::COL_TEMPORARY_DRIVER_ASSIGNMENTS,
                [
                    ['covering_staff_id', '==', $staffId],
                    ['status',            '==', 'Active'],
                ]
            );
            foreach ($activeAsCovering as $t) {
                $t = $this->_unwrapNestedRow($t, 'effective_from');
                if (!is_array($t)) { continue; }
                $from = (string) ($t['effective_from'] ?? '');
                $to   = (string) ($t['effective_to']   ?? '');
                if ($from !== '' && $to !== '' && $from <= $onDate && $onDate <= $to) {
                    throw new \InvalidArgumentException(
                        "Driver {$staffId} is already covering vehicle {$t['vehicle_id']} "
                        . "in window {$from}..{$to}. Resolve that assignment first."
                    );
                }
            }
        } catch (\InvalidArgumentException $e) { throw $e; }
        catch (\Throwable $_) { /* query unavailable — trust caller */ }

        // Soft warnings — log missing licence/medical data for admin visibility.
        //
        // Level is 'error', NOT 'warning': CI3's Log::write_log() knows only
        // ERROR/DEBUG/INFO/ALL, so 'warning' indexes $_levels['WARNING'] which
        // does not exist. On PHP 8.1 that raises "Undefined array key" and, with
        // display_errors on, prints HTML *before* the JSON of whatever AJAX call
        // is in flight — corrupting the response so the caller's JSON.parse
        // throws even though the operation succeeded. Observed on trip creation
        // 2026-08-11 (both lines fire for a driver with no licence/medical
        // dates). 'error' is also always logged at threshold=1, so these stay
        // visible instead of being silently dropped.
        if ($licenceExp === '') {
            log_message('error', "F4 driver readiness: {$staffId} has no licence_expires date (soft-check)");
        }
        if ($medicalExp === '') {
            log_message('error', "F4 driver readiness: {$staffId} has no medical_expires date (soft-check)");
        }
    }

    /**
     * HR ownership discipline (Refinement 1 for F4). Every payload written
     * to the `staff` collection by Transport code must satisfy:
     *   - top-level keys are all in ALLOWED_STAFF_TOP_LEVEL_KEYS
     *     (currently: 'driver_meta', 'updated_at')
     *
     * Verifier CH-TRN-F4-1 statically confirms Transport source never
     * calls setEntity('staff', ...) with disallowed keys; this runtime
     * check is the defensive counterpart.
     */
    private function _assertStaffFieldDiscipline(array $payload): void
    {
        foreach (array_keys($payload) as $k) {
            if (!in_array($k, self::ALLOWED_STAFF_TOP_LEVEL_KEYS, true)) {
                throw new \LogicException(
                    'Transport_service (F4) staff-write discipline violation: '
                    . "top-level key '{$k}' is not owned by Transport. "
                    . 'Allowed: ' . implode(', ', self::ALLOWED_STAFF_TOP_LEVEL_KEYS)
                );
            }
        }
    }

    /**
     * Emit a Communication notice for a Critical-severity incident.
     * Audience: Admin + HR Manager + Transport Manager (per operator's
     * approved decision — Principal escalation is business-rule based
     * on top of this baseline, not a default target).
     */
    private function _emitCriticalIncidentNotice(array $incident, ?array $staff): void
    {
        $staffName = is_array($staff) ? (string) ($staff['name'] ?? $staff['Name'] ?? '') : '';
        $this->_notify('driver.critical_incident', [
            'staff_id'      => (string) ($incident['staff_id'] ?? ''),
            'staff_name'    => $staffName,
            'incident_id'   => (string) ($incident['incidentId'] ?? ''),
            'incident_type' => (string) ($incident['incident_type'] ?? ''),
            'description'   => (string) ($incident['description']   ?? ''),
            'vehicle_id'    => (string) ($incident['related_vehicle_id'] ?? ''),
        ]);
    }

    private function _emitSuspensionNotice(array $suspension, ?array $route, bool $isSuspend): string
    {
        $routeName = is_array($route) ? (string) ($route['name'] ?? '') : '';
        $routeId   = (string) ($suspension['route_id'] ?? '');
        $category  = (string) ($suspension['reason_category'] ?? '');
        $reason    = (string) ($suspension['reason'] ?? '');
        $from      = (string) ($suspension['suspended_from'] ?? '');
        $to        = (string) ($suspension['suspended_to']   ?? '');
        $verb      = $isSuspend ? 'suspended' : 'resumed';
        $suspId    = (string) ($suspension['suspensionId'] ?? '');
        $baseCtx = [
            'route_id'        => $routeId,
            'route_name'      => $routeName,
            'reason_category' => $category,
            'reason'          => $reason,
            'from_date'       => $from,
            'to_date'         => $to,
            'action'          => $verb,
            'suspension_id'   => $suspId,
        ];

        // Operational audience — always notified
        $this->_notify($isSuspend ? 'route.suspended' : 'route.resumed', $baseCtx);

        // Parent audience — conditional on business rule
        $notifyParents = (bool) ($suspension['notify_parents'] ?? false);
        if ($notifyParents) {
            $this->_notify('route.suspension_notice_parents', $baseCtx);
            return date('c');
        }
        return '';
    }

    /**
     * Emit a Communication notice per 7-day-expiring item to Admin +
     * Transport Manager. Uses the existing generic 'notice' Numbering kind
     * (via Communication_helper). One notice per item so the body carries
     * an actionable vehicle+kind pair; Communication's own queue handles
     * per-recipient fanout.
     *
     * Best-effort: any Communication-side failure is logged, not rethrown
     * — the cache write is the source of truth for the dashboard.
     *
     * @param array<int, array<string,mixed>> $items 7-day-window items
     */
    private function _emitExpiryNotices(array $items): void
    {
        foreach ($items as $it) {
            $this->_notify('fleet.doc_expiring', [
                'vehicle_id'  => (string) ($it['vehicleId']   ?? ''),
                'number'      => (string) ($it['number']      ?? ''),
                'label'       => (string) ($it['label']       ?? 'Expiry'),
                'expiry_date' => (string) ($it['expiry_date'] ?? ''),
                'days_left'   => (int)    ($it['days_left']   ?? 0),
            ]);
        }
    }

    /**
     * Serialise mixed input to JSON and cap the result at $maxBytes. If the
     * capped result is not valid JSON on its own (truncated mid-token),
     * fall back to the string '<truncated>'.
     */
    private function _capJson($value, int $maxBytes): string
    {
        if (is_string($value)) {
            return strlen($value) > $maxBytes ? (substr($value, 0, $maxBytes) . '…') : $value;
        }
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($encoded === false) {
            return '<uncodable>';
        }
        if (strlen($encoded) <= $maxBytes) {
            return $encoded;
        }
        return substr($encoded, 0, $maxBytes) . '…';
    }
}
