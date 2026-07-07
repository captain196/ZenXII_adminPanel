<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_service — TMS Phase 0 foundation library.
 *
 * ─── SCOPE OF THIS FILE (Phase 0) ────────────────────────────────────────
 * This file exists as the ground-floor library for the Transport Management
 * System. Phase 0 lands ONLY the infrastructure that every future Transport
 * write path will need: audit emission, collection-name constants, and a
 * tenant-bound init contract mirroring Numbering_service::init.
 *
 * Phase 0 does NOT implement any business method. The 40-odd public methods
 * enumerated in the TMS v2.1 architecture (saveVehicle, assignStudent, etc.)
 * are introduced by their respective Phase 1+ Logical Changes, one at a
 * time. That is deliberate: keep the skeleton lightweight, avoid empty stubs
 * that add noise without contributing to the foundation, and let each LC
 * land its own contract + audit + tests in an isolated commit.
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
