<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Numbering Service Registry
 *
 * Declarative configuration for the platform Numbering_service. Every kind the
 * service can allocate is registered here. Adding a new kind = adding a row to
 * the 'kinds' table. The service code itself is generic and knows nothing about
 * specific modules.
 *
 * Loaded via: $this->config->load('numbering');
 * Read via:   $this->config->item('numbering');
 *
 * Phase 1 ships with 6 Communication kinds (notice, circular, template, trigger,
 * queue, log) — every kind has a verified caller in Communication.php or
 * Communication_helper.php. All flags ship 'disabled'; Phase 2 Communication
 * migration flips them to 'enabled' after seed-from-legacy.
 *
 * STATIC PLATFORM CONFIGURATION — owned by Platform team; edited via PR review.
 * RUNTIME OPERATIONAL STATE     — owned by Operator/DevOps; flipped during
 *                                 cutover windows. Future enhancement: migrate
 *                                 to systemConfig/numbering Firestore doc for
 *                                 audit trail + operator-UI editability.
 */

$config['numbering'] = [

    // ═════════════════════════════════════════════════════════════════════════
    //  STATIC PLATFORM CONFIGURATION
    // ═════════════════════════════════════════════════════════════════════════

    '_schemaVersion' => '1.0',

    'kinds' => [

        // ─── Communication (Phase 2 migration target) ───────────────────────

        'notice' => [
            'prefix'       => 'NOT',
            'padWidth'     => 4,
            'gaplessClass' => 'OPERATIONAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'notices',
                'pattern'    => '/^NOT(\d+)$/',
            ],
        ],

        'circular' => [
            'prefix'       => 'CIR',
            'padWidth'     => 4,
            'gaplessClass' => 'OPERATIONAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'circulars',
                'pattern'    => '/^CIR(\d+)$/',
            ],
        ],

        'template' => [
            'prefix'       => 'TPL',
            'padWidth'     => 4,
            'gaplessClass' => 'OPERATIONAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'messageTemplates',
                'pattern'    => '/^TPL(\d+)$/',
            ],
        ],

        'trigger' => [
            'prefix'       => 'TRG',
            'padWidth'     => 4,
            'gaplessClass' => 'OPERATIONAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'alertTriggers',
                'pattern'    => '/^TRG(\d+)$/',
            ],
        ],

        'queue' => [
            'prefix'       => 'QUE',
            'padWidth'     => 5,
            'gaplessClass' => 'INTERNAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'messageQueue',
                'pattern'    => '/^QUE(\d+)$/',
            ],
        ],

        'log' => [
            // periodScope 'none' preserves the legacy monotonic Log numbering
            // semantics. The Phase 1 architecture initially considered 'month'
            // (forward-looking) but legacy deliveryLogs docs share a single
            // sequence without a month discriminator in the doc key, so
            // monthly reset would cause LOG-ID collisions on cutover.
            'prefix'       => 'LOG',
            'padWidth'     => 5,
            'gaplessClass' => 'INTERNAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'deliveryLogs',
                'pattern'    => '/^LOG(\d+)$/',
            ],
        ],

        // ─── Transport (TMS Phase 0 foundation, 2026-07-06) ─────────────────
        // Registered upfront so Phase 1+ Transport LCs can begin allocating
        // without a config round-trip. All Transport kinds ship 'disabled'
        // in _kindFlags below — each is flipped 'enabled' by the LC that
        // starts calling it. Empty seedSource collections yield seed=0 on
        // first allocation (start at 1) — correct behaviour for greenfield
        // kinds.  Rule blocks for the target collections are already in
        // firebase-rules/firestore.rules (Transport section).

        'trn_vehicle' => [
            'prefix'       => 'VH',
            'padWidth'     => 4,
            'gaplessClass' => 'OPERATIONAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'vehicles',
                'pattern'    => '/^VH(\d+)$/',
            ],
        ],

        'trn_vehicle_doc' => [
            'prefix'       => 'VDOC',
            'padWidth'     => 6,
            'gaplessClass' => 'INTERNAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'vehicleDocuments',
                'pattern'    => '/^VDOC(\d+)$/',
            ],
        ],

        'trn_vehicle_lifecycle' => [
            'prefix'       => 'VLC',
            'padWidth'     => 8,
            'gaplessClass' => 'INTERNAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'vehicleLifecycleLog',
                'pattern'    => '/^VLC(\d+)$/',
            ],
        ],

        'trn_vehicle_replacement' => [
            'prefix'       => 'VRP',
            'padWidth'     => 6,
            'gaplessClass' => 'INTERNAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'vehicleReplacementHistory',
                'pattern'    => '/^VRP(\d+)$/',
            ],
        ],

        'trn_vehicle_transfer' => [
            'prefix'       => 'VRT',
            'padWidth'     => 6,
            'gaplessClass' => 'INTERNAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'vehicleRouteTransferHistory',
                'pattern'    => '/^VRT(\d+)$/',
            ],
        ],

        'trn_vendor' => [
            'prefix'       => 'VND',
            'padWidth'     => 4,
            'gaplessClass' => 'OPERATIONAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'transportVendors',
                'pattern'    => '/^VND(\d+)$/',
            ],
        ],

        'trn_route' => [
            // F2 (2026-07-07) design decision: routes are session-AGNOSTIC
            // physical infrastructure; session-scoping is enforced at the
            // student assignment layer (F3). RT numbering is therefore
            // monotonic per tenant, not per session.
            'prefix'       => 'RT',
            'padWidth'     => 4,
            'gaplessClass' => 'OPERATIONAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'routes',
                'pattern'    => '/^RT(\d+)$/',
            ],
        ],

        'trn_stop' => [
            'prefix'       => 'STP',
            'padWidth'     => 4,
            'gaplessClass' => 'OPERATIONAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'transportStops',
                'pattern'    => '/^STP(\d+)$/',
            ],
        ],

        'trn_route_suspension' => [
            // F2 (2026-07-07): monotonic per tenant. Suspensions are
            // date-windowed events on session-agnostic routes; no session
            // tag needed on the ID.
            'prefix'       => 'RSP',
            'padWidth'     => 4,
            'gaplessClass' => 'OPERATIONAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'routeSuspensions',
                'pattern'    => '/^RSP(\d+)$/',
            ],
        ],

        'trn_assignment_history' => [
            'prefix'       => 'HIST',
            'padWidth'     => 8,
            'gaplessClass' => 'INTERNAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'routeAssignmentHistory',
                'pattern'    => '/^HIST(\d+)$/',
            ],
        ],

        'trn_trip' => [
            // Monotonic per tenant. Numbering_service periodScope has no
            // 'date' value, so daily rotation is not offered here; TRP IDs
            // grow indefinitely. 6-digit pad supports ~1M trips per tenant
            // over the tenant lifetime — 200 trips/day × ~14 years.
            'prefix'       => 'TRP',
            'padWidth'     => 6,
            'gaplessClass' => 'INTERNAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'tripLogs',
                'pattern'    => '/^TRP(\d+)$/',
            ],
        ],

        'trn_maintenance' => [
            'prefix'       => 'MNT',
            'padWidth'     => 6,
            'gaplessClass' => 'OPERATIONAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'maintenanceLogs',
                'pattern'    => '/^MNT(\d+)$/',
            ],
        ],

        'trn_fuel' => [
            'prefix'       => 'FUEL',
            'padWidth'     => 6,
            'gaplessClass' => 'INTERNAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'fuelLogs',
                'pattern'    => '/^FUEL(\d+)$/',
            ],
        ],

        'trn_expense' => [
            'prefix'       => 'TEXP',
            'padWidth'     => 6,
            'gaplessClass' => 'INTERNAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'transportExpenses',
                'pattern'    => '/^TEXP(\d+)$/',
            ],
        ],

        'trn_sos' => [
            'prefix'       => 'SOS',
            'padWidth'     => 4,
            'gaplessClass' => 'INTERNAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'sosAlerts',
                'pattern'    => '/^SOS(\d+)$/',
            ],
        ],

        'trn_driver_incident' => [
            'prefix'       => 'DINC',
            'padWidth'     => 6,
            'gaplessClass' => 'INTERNAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'driverIncidents',
                'pattern'    => '/^DINC(\d+)$/',
            ],
        ],

        'trn_driver_review' => [
            'prefix'       => 'DPRV',
            'padWidth'     => 4,
            'gaplessClass' => 'OPERATIONAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'driverPerformanceReviews',
                'pattern'    => '/^DPRV(\d+)$/',
            ],
        ],

        'trn_temp_driver_assign' => [
            'prefix'       => 'TDA',
            'padWidth'     => 4,
            'gaplessClass' => 'OPERATIONAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'temporaryDriverAssignments',
                'pattern'    => '/^TDA(\d+)$/',
            ],
        ],

        'trn_event' => [
            'prefix'       => 'TEVT',
            'padWidth'     => 4,
            'gaplessClass' => 'OPERATIONAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'transportEvents',
                'pattern'    => '/^TEVT(\d+)$/',
            ],
        ],

        'trn_event_assign' => [
            'prefix'       => 'ETA',
            'padWidth'     => 6,
            'gaplessClass' => 'INTERNAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'eventTripAssignments',
                'pattern'    => '/^ETA(\d+)$/',
            ],
        ],

        'trn_audit' => [
            'prefix'       => 'TAUD',
            'padWidth'     => 8,
            'gaplessClass' => 'INTERNAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'transportAuditLog',
                'pattern'    => '/^TAUD(\d+)$/',
            ],
        ],

    ],

    // ═════════════════════════════════════════════════════════════════════════
    //  RUNTIME OPERATIONAL STATE
    // ═════════════════════════════════════════════════════════════════════════

    '_serviceEnabled' => true,

    '_kindFlags' => [
        // Phase 2 cutover (2026-06-28): Communication module migrated to
        // Numbering_service. All 6 kinds active; legacy commCounters.*
        // storage on schools/{id}_profile is no longer read or written
        // by Communication. Self-heal runs once per (tenant, kind) on
        // first allocation to continue from the legacy max ID.
        'notice'   => 'enabled',
        'circular' => 'enabled',
        'template' => 'enabled',
        'trigger'  => 'enabled',
        'queue'    => 'enabled',
        'log'      => 'enabled',

        // Transport — Feature F1 (Fleet Management) cutover 2026-07-07:
        // 9 Fleet + Vendor + audit kinds flipped to 'enabled' as F1 landed.
        // Remaining 11 kinds stay 'disabled' — activated by their respective
        // features per [[transport_feature_milestones]] (F2 flips trn_route/
        // trn_stop / trn_route_suspension, F3 flips trn_assignment_history,
        // F4 flips driver kinds, F5 flips trn_expense, F7 flips trn_trip,
        // F8 flips event kinds).
        'trn_vehicle'              => 'enabled',
        'trn_vehicle_doc'          => 'enabled',
        'trn_vehicle_lifecycle'    => 'enabled',
        'trn_vehicle_replacement'  => 'enabled',
        'trn_vehicle_transfer'     => 'enabled',
        'trn_vendor'               => 'enabled',
        'trn_maintenance'          => 'enabled',
        'trn_fuel'                 => 'enabled',
        'trn_audit'                => 'enabled',
        // Feature F2 (Route & Stop Management) cutover 2026-07-07:
        // 3 route/stop/suspension kinds flipped 'enabled'.
        'trn_route'                => 'enabled',
        'trn_stop'                 => 'enabled',
        'trn_route_suspension'     => 'enabled',
        // Feature F3 (Student Assignment) cutover 2026-07-07:
        'trn_assignment_history'   => 'enabled',
        // Feature F4 (Driver Management) cutover 2026-07-07:
        'trn_driver_incident'      => 'enabled',
        'trn_driver_review'        => 'enabled',
        'trn_temp_driver_assign'   => 'enabled',
        // Feature F5 (Transport Expenses + Accounting) cutover 2026-07-07:
        'trn_expense'              => 'enabled',
        // Feature F6 (Communication Integration + SOS) cutover 2026-07-07:
        'trn_sos'                  => 'enabled',
        // Feature F7 (Trip Logs + Transport Attendance) cutover 2026-07-07:
        'trn_trip'                 => 'enabled',
        // Still disabled — future features:
        'trn_event'                => 'disabled',
        'trn_event_assign'         => 'disabled',
    ],
];
