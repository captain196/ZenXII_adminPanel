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
            $v = is_array($doc) ? $doc : [];
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
    public function uploadVehicleDocument(array $input): array
    {
        $this->_assertReady();
        $vehicleId = trim((string) ($input['vehicle_id'] ?? ''));
        $docType   = trim((string) ($input['doc_type']   ?? ''));
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
            $d = is_array($doc) ? $doc : [];
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
            $d = is_array($doc) ? $doc : [];
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
            $v = is_array($doc) ? $doc : [];
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
            $v = is_array($doc) ? $doc : [];
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
            $d = is_array($doc) ? $doc : [];
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
            $d = is_array($doc) ? $doc : [];
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
        $cacheDocId = $this->schoolId . '_VEXPCACHE';
        $this->fs->setEntity(self::COL_VEHICLE_EXPIRY_DASHBOARD_CACHE, $cacheDocId, $cache, /* merge */ false);

        // Notify Admin + Transport Manager on 7-day expiries via existing
        // Communication kind. Individual notice per item keeps the notice
        // body actionable; Communication's own queue handles fanout.
        if ($count7 > 0 && class_exists('Communication_helper')) {
            $this->_emitExpiryNotices(array_filter($items, static fn($i) => $i['window'] === 7));
        }

        return $cache;
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
        try {
            $this->ci->load->library('Communication_helper', null, 'commHelper');
        } catch (\Throwable $_) {
            log_message('warning', 'Transport_service: Communication_helper unavailable — expiry notices skipped.');
            return;
        }
        // The Communication_helper API surface varies across installs; we
        // route through its documented ::createNotice signature if present,
        // otherwise degrade to log-only.
        $helper = $this->ci->commHelper ?? null;
        if (!is_object($helper) || !method_exists($helper, 'createNotice')) {
            log_message('warning', 'Transport_service: Communication_helper::createNotice not available — expiry notices skipped.');
            return;
        }
        foreach ($items as $it) {
            $vid    = (string) ($it['vehicleId']   ?? '');
            $label  = (string) ($it['label']       ?? 'Expiry');
            $number = (string) ($it['number']      ?? '');
            $exp    = (string) ($it['expiry_date'] ?? '');
            $days   = (int)    ($it['days_left']   ?? 0);
            $subject = "Transport: {$label} expiring in {$days}d for vehicle {$number}";
            $body    = "Vehicle: {$number} ({$vid})\nItem: {$label}\nExpires: {$exp} ({$days} days from today)\nAction: renew before expiry.";
            try {
                $helper->createNotice([
                    'schoolId'  => $this->schoolId,
                    'subject'   => $subject,
                    'body'      => $body,
                    'audience'  => ['role:Admin', 'role:Transport Manager'],
                    'context'   => ['module' => 'Transport', 'topic' => 'expiry', 'vehicle_id' => $vid],
                ]);
            } catch (\Throwable $e) {
                log_message('warning', 'Transport_service expiry notice failed: ' . $e->getMessage());
            }
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
