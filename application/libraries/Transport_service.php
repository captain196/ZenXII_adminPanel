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
            if (!is_array($doc)) { continue; }
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
            if (!is_array($doc)) { continue; }
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
            'latitude'    => (float) ($input['latitude']  ?? 0),
            'longitude'   => (float) ($input['longitude'] ?? 0),
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
            if (!is_array($doc)) { continue; }
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
            if (!is_array($doc)) { continue; }
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

        // Capacity check (single-student assign uses just-in-time cache read)
        $capacityCheck = $this->_checkCapacityAvailable($routeId, /* isEdit */ $isUpdate, /* prevRouteId */ (string) ($existing['route_id'] ?? ''));
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
        $this->_bumpCapacityCache($routeId, /* delta */ $isUpdate && ($existing['route_id'] ?? '') === $routeId ? 0 : +1);
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
            if ($isUpdate) {
                $this->ci->feeLifecycle->proRateFees($studentId, date('Y-m-d'), 'Transport');
            }
            $demandRef = $this->ci->feeLifecycle->createModuleFee($studentId, 'Transport', [
                'route_id'   => $routeId,
                'route_name' => (string) ($route['name'] ?? ''),
                'amount'     => $monthlyFee,
                'period'     => 'Monthly',
                'start_date' => date('Y-m-d'),
                'end_date'   => (string) ($data['effective_to'] ?? ''),
                'context'    => ['subscription_type' => $subscription, 'fee_category' => $feeCategory],
            ]);
            $ref = is_string($demandRef) && $demandRef !== '' ? $demandRef : 'ok';
            $this->fs->setEntity(self::COL_STUDENT_ROUTES_LEGACY, $studentId, ['fee_demand_ref' => $ref], /* merge */ true);
            $feeStatus = 'ok';
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

        // Fee_lifecycle: pro-rate to today (best-effort; failure logged)
        try {
            $this->ci->load->library('Fee_lifecycle', null, 'feeLifecycle');
            $this->ci->feeLifecycle->proRateFees($studentId, date('Y-m-d'), 'Transport');
        } catch (\Throwable $e) {
            log_message('warning', 'F3 unassignStudent proRateFees failed for ' . $studentId . ': ' . $e->getMessage());
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

        // Capacity check on new route
        $capacityCheck = $this->_checkCapacityAvailable($newRouteId, /* isEdit */ true, $prevRouteId);
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

        // Fee_lifecycle: pro-rate old route to today
        try {
            $this->ci->load->library('Fee_lifecycle', null, 'feeLifecycle');
            $this->ci->feeLifecycle->proRateFees($studentId, date('Y-m-d'), 'Transport');
        } catch (\Throwable $e) {
            log_message('warning', 'F3 changeRoute proRateFees failed for ' . $studentId . ': ' . $e->getMessage());
        }

        $update = [
            'route_id'      => $newRouteId,
            'route_name'    => (string) ($newRoute['name'] ?? ''),
            'stop_id'       => $newStopId,
            'stop_name'     => is_array($newStop) ? (string) ($newStop['name'] ?? '') : '',
            'monthly_fee'   => $newFee,
            'fee_demand_ref' => 'pending',
            'updated_at'    => date('c'),
        ];
        $this->fs->setEntity(self::COL_STUDENT_ROUTES_LEGACY, $studentId, $update, /* merge */ true);
        $this->_appendAssignmentHistoryEvent(
            $studentId, (string) ($existing['session'] ?? $this->sessionYear),
            'route_changed_only',
            ['route_id' => $prevRouteId, 'route_name' => (string) ($existing['route_name'] ?? ''), 'stop_id' => (string) ($existing['stop_id'] ?? '')],
            $update, 'manual', '', $reason
        );
        $this->_bumpCapacityCache($prevRouteId, -1);
        $this->_bumpCapacityCache($newRouteId, +1);

        $this->emitAudit(
            'studentRouteAssignment', $studentId, 'route_changed',
            null, $update,
            ['domain' => self::DOMAIN_ASSIGNMENT, 'source' => 'manual', 'reason' => $reason,
             'prev_route_id' => $prevRouteId, 'new_route_id' => $newRouteId]
        );

        // Fee_lifecycle: create new demand
        try {
            $demandRef = $this->ci->feeLifecycle->createModuleFee($studentId, 'Transport', [
                'route_id'   => $newRouteId,
                'route_name' => (string) ($newRoute['name'] ?? ''),
                'amount'     => $newFee,
                'period'     => 'Monthly',
                'start_date' => date('Y-m-d'),
            ]);
            $ref = is_string($demandRef) && $demandRef !== '' ? $demandRef : 'ok';
            $this->fs->setEntity(self::COL_STUDENT_ROUTES_LEGACY, $studentId, ['fee_demand_ref' => $ref], /* merge */ true);
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

        $update = array_merge([
            'subscription_type' => $newType,
            'monthly_fee'       => $newFee,
            'fee_demand_ref'    => 'pending',
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
                $sid = (string) ($row['studentId'] ?? '');
                if ($sid === '' || isset($processedSet[$sid])) { continue; }
                $todo[] = ['sid' => $sid, 'row' => $row];
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

                    // Update studentRoutes to new session
                    $this->fs->setEntity(self::COL_STUDENT_ROUTES_LEGACY, $sid, [
                        'session'       => $toSession,
                        'assigned_date' => date('Y-m-d'),
                        'updated_at'    => date('c'),
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
                        $this->ci->feeLifecycle->createModuleFee($sid, 'Transport', [
                            'route_id'   => (string) ($row['route_id'] ?? ''),
                            'route_name' => (string) ($row['route_name'] ?? ''),
                            'amount'     => (float) ($row['monthly_fee'] ?? 0),
                            'period'     => 'Monthly',
                            'start_date' => date('Y-m-d'),
                        ]);
                    } catch (\Throwable $e) {
                        log_message('warning', 'F3 rollover fee ops failed for ' . $sid . ': ' . $e->getMessage());
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
            $cacheId = "{$this->schoolId}_CAPCACHE_{$rid}";
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
            $sid = (string) ($r['studentId'] ?? '');
            if ($sid === '') { continue; }
            try {
                $this->ci->load->library('Fee_lifecycle', null, 'feeLifecycle');
                $demandRef = $this->ci->feeLifecycle->createModuleFee($sid, 'Transport', [
                    'route_id'   => (string) ($r['route_id'] ?? ''),
                    'route_name' => (string) ($r['route_name'] ?? ''),
                    'amount'     => (float) ($r['monthly_fee'] ?? 0),
                    'period'     => 'Monthly',
                    'start_date' => date('Y-m-d'),
                ]);
                $ref = is_string($demandRef) && $demandRef !== '' ? $demandRef : 'ok';
                $this->fs->setEntity(self::COL_STUDENT_ROUTES_LEGACY, $sid,
                    ['fee_demand_ref' => $ref], /* merge */ true);
                $retried++;
            } catch (\Throwable $e) {
                $stillPending++;
                log_message('warning', 'F3 reconcile pending fee failed for ' . $sid . ': ' . $e->getMessage());
            }
        }
        return ['retried' => $retried, 'still_pending' => $stillPending];
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
        $rows = (array) $this->fs->schoolWhere(self::COL_STUDENT_ROUTES_LEGACY, $where, 'student_name', 'ASC');
        $out = [];
        foreach ($rows as $doc) {
            if (!is_array($doc)) { continue; }
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
            $rows = array_filter($all, static function ($s) {
                return is_array($s) && !empty($s['flags']['can_drive_transport']);
            });
        }
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) { continue; }
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
        // Normalise + validate driver_meta fields
        $clean = [];
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

    public function recordDriverIncident(array $input): array
    {
        $this->_assertReady();
        $staffId = trim((string) ($input['staff_id'] ?? ''));
        $type    = trim((string) ($input['incident_type'] ?? ''));
        $sev     = trim((string) ($input['severity'] ?? 'Minor'));
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
            'reviewer_staff_id'     => trim((string) ($input['reviewer_staff_id'] ?? $this->adminId)),
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
    public function saveExpense(array $input): array
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

        // Posting mode: force_post overrides; else schoolControl setting
        $forcePost   = !empty($input['force_post']);
        $postingMode = $forcePost ? 'auto' : $this->_getExpensePostingMode();

        // Normalise attachments — accept legacy 'receipt_path' as an implicit
        // Receipt attachment for backward compat with derived-from-log flows.
        $attachments = $this->_normaliseAttachments((array) ($input['attachments'] ?? []), (string) ($input['receipt_path'] ?? ''));

        $now = date('c');
        $amount = round((float) ($input['amount'] ?? 0), 2);
        $taxAmount = round((float) ($input['tax_amount'] ?? 0), 2);
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
            'payment_method'          => (string) ($input['payment_method'] ?? 'Cash'),
            'payment_reference'       => trim((string) ($input['payment_reference'] ?? '')),
            'paid_by_staff_id'        => trim((string) ($input['paid_by_staff_id'] ?? $this->adminId)),
            'attachments'             => $attachments,
            'accounting_journal_ref'  => $postingMode === 'auto' ? 'pending' : '',
            'accounting_attempts'     => 0,
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

        // Auto-post to Accounting (fire-and-await; failure leaves 'pending')
        $postResult = ['status' => $postingMode === 'auto' ? 'pending' : 'manual', 'journal_ref' => ''];
        if ($postingMode === 'auto') {
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
        $update = [
            'status'      => 'Voided',
            'voided_at'   => $now,
            'voided_by'   => $this->adminId,
            'void_reason' => trim($reason),
            'updated_at'  => $now,
        ];
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
            $rows = (array) $this->fs->schoolWhere(
                self::COL_TRANSPORT_EXPENSES, $where,
                'expense_date', 'DESC'
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
        } catch (\Throwable $_) { return ['retried' => 0, 'posted' => 0, 'failed' => 0]; }
        $retried = 0; $posted = 0; $failed = 0;
        foreach ($rows as $r) {
            $r = $this->_unwrapNestedRow($r, 'expense_type');
            if (!is_array($r)) { continue; }
            $id = (string) ($r['expenseId'] ?? $r['id'] ?? '');
            if ($id === '') { continue; }
            $retried++;
            $res = $this->_postToAccounting($id, $r);
            if ($res['status'] === 'posted') { $posted++; }
            elseif ($res['status'] === 'failed') { $failed++; }
        }
        return ['retried' => $retried, 'posted' => $posted, 'failed' => $failed];
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
        if ($ref !== '' && $ref !== 'pending' && $ref !== 'failed') {
            throw new \InvalidArgumentException('Expense already posted to Accounting: ' . $ref);
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
            'summaryId'              => "{$this->schoolId}_FMES__{$yearMonth}",
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
        $cacheId = "{$this->schoolId}_CAPCACHE_{$routeId}";
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
        $eventId = $this->numbering->next('trn_assignment_history', [
            'claimedBy'  => $this->adminId,
            'claimedFor' => 'F3:_appendAssignmentHistoryEvent',
        ]);
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
     * Bump the busCapacityCache current_count by ±1 (or larger delta for
     * bulk ops). Self-heals when cache doc is missing by triggering a
     * rebuild for the affected route.
     */
    private function _bumpCapacityCache(string $routeId, int $delta): void
    {
        if ($routeId === '' || $delta === 0) { return; }
        $cacheId = "{$this->schoolId}_CAPCACHE_{$routeId}";
        $cache   = $this->fs->getEntity(self::COL_BUS_CAPACITY_CACHE, $cacheId);
        if (!is_array($cache)) {
            // Cache missing — rebuild from source (which sets the correct count)
            try { $this->rebuildCapacityCache($routeId); } catch (\Throwable $_) {}
            return;
        }
        $current = (int) ($cache['current_count'] ?? 0);
        $newCount = max(0, $current + $delta);
        $this->fs->setEntity(self::COL_BUS_CAPACITY_CACHE, $cacheId, [
            'current_count'   => $newCount,
            'last_updated_at' => date('c'),
        ], /* merge */ true);
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
    public const BOARDING_EVENT_TYPES = [
        'boarded_pickup',      // student got on the pickup trip
        'dropped_home',        // student was dropped at end-of-drop-trip
        'no_show',             // student expected but did not board
        'alternate_pickup',    // student boarded at a non-planned stop
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

        if ($vehicleId === '') { throw new \InvalidArgumentException('vehicle_id is required.'); }
        if ($routeId === '')   { throw new \InvalidArgumentException('route_id is required.'); }
        if (!in_array($direction, self::TRIP_DIRECTIONS, true)) {
            throw new \InvalidArgumentException("Invalid trip_direction: '{$direction}'. Allowed: " . implode(',', self::TRIP_DIRECTIONS));
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tripDate)) {
            throw new \InvalidArgumentException("Invalid trip_date: '{$tripDate}'. Expected YYYY-MM-DD.");
        }

        $vehicle = $this->fs->getEntity(self::COL_VEHICLES, $vehicleId);
        if (!is_array($vehicle)) {
            throw new \InvalidArgumentException("Vehicle not found: {$vehicleId}");
        }
        $route = $this->fs->getEntity(self::COL_ROUTES, $routeId);
        if (!is_array($route)) {
            throw new \InvalidArgumentException("Route not found: {$routeId}");
        }
        if ((string) ($route['status'] ?? 'Active') !== 'Active') {
            throw new \InvalidArgumentException("Route is not Active: {$routeId}");
        }

        // Resolve effective driver — honours F4 temporary assignments
        $driverStaffId = (string) ($this->getEffectiveDriver($vehicleId, $tripDate) ?? '');
        if ($driverStaffId === '') {
            throw new \InvalidArgumentException("No effective driver for vehicle {$vehicleId} on {$tripDate}.");
        }

        // Snapshot checkpoints from route stops (frozen at trip creation)
        $stops = $this->listStops($routeId);
        if ($stops === []) {
            throw new \InvalidArgumentException("Route {$routeId} has no stops — cannot create trip.");
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

        // Snapshot roster — read once, cache on trip doc
        $roster = $this->_computeTripRoster($routeId, $tripDate, $direction);

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
                'name'      => (string) ($route['name'] ?? ''),
                'direction' => (string) ($route['direction'] ?? ''),
            ],
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
        $trip = $this->_loadTripOrFail($tripId);
        if ($trip['status'] !== 'Ready') {
            throw new \InvalidArgumentException("Trip {$tripId} is not in Ready state (current: {$trip['status']}).");
        }
        $now = date('c');
        $update = [
            'status'          => 'InProgress',
            'actual_start_at' => $now,
            'startTime'       => $trip['startTime'] !== '' ? $trip['startTime'] : $now,
            'updated_at'      => $now,
        ];
        $this->fs->setEntity(self::COL_TRIP_LOGS, $tripId, $update, /* merge */ true);
        $this->emitAudit('trip', $tripId, 'started', $trip, array_merge($trip, $update),
            ['domain' => self::DOMAIN_TRIP]);
        $this->_notify('trip.started', [
            'trip_id'      => $tripId,
            'route_id'     => (string) ($trip['route_id'] ?? ''),
            'vehicle_id'   => (string) ($trip['vehicleId'] ?? ''),
            'driver_id'    => (string) ($trip['driver_staff_id'] ?? ''),
            'trip_date'    => (string) ($trip['trip_date'] ?? ''),
            'direction'    => (string) ($trip['trip_direction'] ?? ''),
            'roster_size'  => (int) ($trip['roster_size'] ?? 0),
        ]);
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
        $trip = $this->_loadTripOrFail($tripId);
        if ($trip['status'] !== 'InProgress') {
            throw new \InvalidArgumentException("Trip {$tripId} not InProgress.");
        }
        $now = date('c');
        $found = false;
        $checkpoints = (array) ($trip['checkpoints'] ?? []);
        foreach ($checkpoints as $i => $cp) {
            if ((string) ($cp['stop_id'] ?? '') !== $stopId) { continue; }
            if (($cp['checkpoint_state'] ?? 'Pending') !== 'Reached') {
                throw new \InvalidArgumentException("Cannot mark left — checkpoint state is '{$cp['checkpoint_state']}', expected 'Reached'.");
            }
            $checkpoints[$i]['left_at'] = $now;
            $found = true;
            break;
        }
        if (!$found) {
            throw new \InvalidArgumentException("Stop {$stopId} not found in trip {$tripId} checkpoints.");
        }
        $this->fs->setEntity(self::COL_TRIP_LOGS, $tripId, [
            'checkpoints' => $checkpoints,
            'updated_at'  => $now,
        ], /* merge */ true);
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
    public function markStudentNoShow(string $tripId, string $studentId, string $notes = ''): array
    {
        $ret = $this->_writeBoardingEvent($tripId, $studentId, 'no_show', null, $notes);

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
    public function completeTrip(string $tripId, array $input = []): array
    {
        $this->_assertReady();
        $trip = $this->_loadTripOrFail($tripId);
        if ($trip['status'] !== 'InProgress') {
            throw new \InvalidArgumentException("Trip {$tripId} not InProgress.");
        }
        $checkpoints = (array) ($trip['checkpoints'] ?? []);
        foreach ($checkpoints as $cp) {
            $st = (string) ($cp['checkpoint_state'] ?? 'Pending');
            if ($st === 'Pending') {
                throw new \InvalidArgumentException(
                    "Cannot complete — checkpoint '{$cp['stop_name']}' is still Pending. "
                    . "Mark it Reached or Skipped first (Refinement 4)."
                );
            }
        }

        $now = date('c');
        $endTime      = trim((string) ($input['end_time'] ?? $now));
        $endOdometer  = (float) ($input['end_odometer'] ?? 0);
        $startOdo     = (float) ($trip['startOdometer'] ?? 0);
        $distanceKm   = $endOdometer > $startOdo ? round($endOdometer - $startOdo, 2) : (float) ($input['distance_km'] ?? 0);
        $fuelUsed     = (float) ($input['fuel_used'] ?? 0);
        $notes        = trim((string) ($input['notes'] ?? ''));

        // Compute counts + duration from checkpoints/events
        $stopsCompleted = 0;
        foreach ($checkpoints as $cp) {
            if (($cp['checkpoint_state'] ?? '') === 'Reached') { $stopsCompleted++; }
        }
        $startTs = strtotime((string) ($trip['actual_start_at'] ?? '')) ?: 0;
        $endTs   = strtotime($endTime) ?: 0;
        $durationMinutes = ($startTs > 0 && $endTs > $startTs) ? (int) round(($endTs - $startTs) / 60) : 0;

        // On-time = last-reached checkpoint left_at <= scheduled + 15min slack
        $onTime = $this->_computeOnTime($trip, $checkpoints);

        // Boarding tallies from transportAttendance (single-query aggregate)
        $tally = $this->_tallyBoardingEvents($tripId);

        $update = [
            'status'           => 'Completed',
            'actual_end_at'    => $now,
            'endTime'          => $endTime,
            'endOdometer'      => $endOdometer,
            'distance'         => $distanceKm,
            'distance_km'      => $distanceKm,
            'fuelUsed'         => $fuelUsed,
            'stopsCompleted'   => $stopsCompleted,
            'duration_minutes' => $durationMinutes,
            'on_time'          => $onTime,
            'notes'            => $notes,
            'route_progress_pct' => 100,
            'next_checkpoint_id' => '',
            'boarded_count'    => (int) ($tally['boarded'] ?? 0),
            'dropped_count'    => (int) ($tally['dropped'] ?? 0),
            'no_show_count'    => (int) ($tally['no_show'] ?? 0),
            'updated_at'       => $now,
        ];
        $this->fs->setEntity(self::COL_TRIP_LOGS, $tripId, $update, /* merge */ true);
        $this->emitAudit('trip', $tripId, 'completed', $trip, array_merge($trip, $update),
            ['domain' => self::DOMAIN_TRIP]);
        $this->_notify('trip.completed', [
            'trip_id'         => $tripId,
            'route_id'        => (string) ($trip['route_id'] ?? ''),
            'vehicle_id'      => (string) ($trip['vehicleId'] ?? ''),
            'driver_id'       => (string) ($trip['driver_staff_id'] ?? ''),
            'trip_date'       => (string) ($trip['trip_date'] ?? ''),
            'direction'       => (string) ($trip['trip_direction'] ?? ''),
            'duration_minutes'=> $durationMinutes,
            'distance_km'     => $distanceKm,
            'on_time'         => $onTime,
            'boarded'         => (int) ($tally['boarded'] ?? 0),
            'no_show'         => (int) ($tally['no_show'] ?? 0),
        ]);
        return ['tripId' => $tripId, 'status' => 'Completed', 'summary' => $update];
    }

    /** Cancel a Ready or InProgress trip. Terminal. */
    public function cancelTrip(string $tripId, string $reason): array
    {
        $this->_assertReady();
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('cancel reason is required.');
        }
        $trip = $this->_loadTripOrFail($tripId);
        if (!in_array($trip['status'], self::TRIP_ACTIVE_STATUSES, true)) {
            throw new \InvalidArgumentException("Trip {$tripId} not in cancellable state (status: {$trip['status']}).");
        }
        $now = date('c');
        $update = [
            'status'         => 'Cancelled',
            'actual_end_at'  => $now,
            'cancel_reason'  => $reason,
            'cancelled_by'   => $this->adminId,
            'updated_at'     => $now,
        ];
        $this->fs->setEntity(self::COL_TRIP_LOGS, $tripId, $update, /* merge */ true);
        $this->emitAudit('trip', $tripId, 'cancelled', $trip, array_merge($trip, $update),
            ['domain' => self::DOMAIN_TRIP, 'cancel_reason' => $reason]);
        return ['tripId' => $tripId, 'status' => 'Cancelled'];
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

    private function _updateCheckpointState(string $tripId, string $stopId, string $newState, string $reason = ''): array
    {
        if (!in_array($newState, self::CHECKPOINT_STATES, true)) {
            throw new \InvalidArgumentException("Invalid checkpoint state: {$newState}");
        }
        $trip = $this->_loadTripOrFail($tripId);
        if ($trip['status'] !== 'InProgress') {
            throw new \InvalidArgumentException("Trip {$tripId} not InProgress (status: {$trip['status']}).");
        }
        $now = date('c');
        $checkpoints = (array) ($trip['checkpoints'] ?? []);
        $found = false;
        foreach ($checkpoints as $i => $cp) {
            if ((string) ($cp['stop_id'] ?? '') !== $stopId) { continue; }
            $currentState = (string) ($cp['checkpoint_state'] ?? 'Pending');
            if (in_array($currentState, ['Reached', 'Skipped'], true) && $currentState !== $newState) {
                throw new \InvalidArgumentException("Checkpoint already {$currentState} — no further transition allowed.");
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
            throw new \InvalidArgumentException("Stop {$stopId} not found in trip {$tripId}.");
        }

        // Recompute route progress + next checkpoint pointer
        $reachedOrSkipped = 0;
        $nextPending = '';
        foreach ($checkpoints as $cp) {
            $st = (string) ($cp['checkpoint_state'] ?? 'Pending');
            if ($st === 'Reached' || $st === 'Skipped') { $reachedOrSkipped++; }
            elseif ($nextPending === '') { $nextPending = (string) ($cp['stop_id'] ?? ''); }
        }
        $progressPct = count($checkpoints) > 0 ? (int) round(($reachedOrSkipped / count($checkpoints)) * 100) : 0;

        $this->fs->setEntity(self::COL_TRIP_LOGS, $tripId, [
            'checkpoints'        => $checkpoints,
            'route_progress_pct' => $progressPct,
            'next_checkpoint_id' => $nextPending,
            'updated_at'         => $now,
        ], /* merge */ true);
        $this->emitAudit('trip_checkpoint', $tripId . ':' . $stopId, strtolower($newState),
            null, ['stop_id' => $stopId, 'new_state' => $newState, 'reason' => $reason],
            ['domain' => self::DOMAIN_TRIP]);
        return ['tripId' => $tripId, 'stop_id' => $stopId, 'checkpoint_state' => $newState, 'progress_pct' => $progressPct];
    }

    /**
     * Write a boarding event to transportAttendance. Deterministic doc-id
     * = idempotent on repeat driver taps (F7-4 verifier gate).
     */
    private function _writeBoardingEvent(string $tripId, string $studentId, string $eventType, ?string $actualStopId, string $notes): array
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
        if ($eventType !== 'no_show' && !in_array($studentId, $roster, true)) {
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

        // Look up planned stop from assignment (may be null if no_show)
        $plannedStopId = $this->_lookupPlannedStop($studentId, (string) ($trip['route_id'] ?? ''));

        // Deterministic doc-id → idempotent repeat taps
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
            'marked_by_role'        => 'admin',
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

        // Service-days gate (allow missing = all days for backward compat)
        $days = (array) ($assignment['service_days'] ?? []);
        if ($days !== [] && !in_array($dayName, $days, true)) { return false; }
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
        $trip = $this->fs->getEntity(self::COL_TRIP_LOGS, $tripId);
        if (!is_array($trip)) { return; }
        $checkpoints = (array) ($trip['checkpoints'] ?? []);
        foreach ($checkpoints as $i => $cp) {
            if ((string) ($cp['stop_id'] ?? '') !== $stopId) { continue; }
            if (in_array($eventType, ['boarded_pickup', 'dropped_home', 'alternate_pickup'], true)) {
                $checkpoints[$i]['students_boarded_count'] = (int) ($cp['students_boarded_count'] ?? 0) + 1;
            } elseif ($eventType === 'no_show') {
                $checkpoints[$i]['students_no_show_count'] = (int) ($cp['students_no_show_count'] ?? 0) + 1;
            }
            break;
        }
        $this->fs->setEntity(self::COL_TRIP_LOGS, $tripId, [
            'checkpoints' => $checkpoints,
            'updated_at'  => date('c'),
        ], /* merge */ true);
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
                if ($t === 'boarded_pickup' || $t === 'alternate_pickup') { $tally['boarded']++; }
                elseif ($t === 'dropped_home') { $tally['dropped']++; }
                elseif ($t === 'no_show')      { $tally['no_show']++; }
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
            log_message('warning', 'Transport_notifier load failed: ' . $e->getMessage());
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
            log_message('warning', "Transport_notifier unavailable for {$category}");
            return;
        }
        try {
            $n->notify($category, $ctx, $overrides);
        } catch (\Throwable $e) {
            log_message('warning', "Transport_notifier {$category} failed: " . $e->getMessage());
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
     * are logged (log_message('warning', ...)) — schools rolling out
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

        // Soft warnings — log missing licence/medical data for admin visibility
        if ($licenceExp === '') {
            log_message('warning', "F4 driver readiness: {$staffId} has no licence_expires date (soft-check)");
        }
        if ($medicalExp === '') {
            log_message('warning', "F4 driver readiness: {$staffId} has no medical_expires date (soft-check)");
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
