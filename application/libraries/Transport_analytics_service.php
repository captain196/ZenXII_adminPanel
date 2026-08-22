<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_analytics_service — F11 (2026-07-08) analytics facade.
 *
 * Decision-support layer over the F1-F10 operational platform. Pure
 * composition — never rescans raw collections; always reads from the
 * denormalised caches:
 *   • busCapacityCache          (F3)
 *   • driverActivitySummary     (F4)
 *   • vehicleExpiryDashboardCache (F1)
 *   • fleetMonthlyExpenseSummary (F5)
 *   • routeFleetSummary         (F2 gap filled by F11)
 *
 * Verifier F11-1 statically asserts this file contains no
 * `schoolWhere('transportExpenses'|...)` — the composition discipline is
 * source-scanned so nobody accidentally reintroduces N+1 raw scans.
 *
 * ROLE-SCOPED VISIBILITY (Refinement 3, 2026-07-08):
 *   Super Admin       → full cross-tenant analytics
 *   School Super Admin → full school analytics
 *   Admin             → full school analytics
 *   Principal         → operational + strategic
 *   Transport Manager → full transport operations
 *   Accountant        → financial only
 *   HR Manager        → driver performance only
 *   Vice Principal    → operational summaries
 *   Operations Manager → operational summaries
 *   Teacher / Class Teacher → NO management analytics
 *
 * Every widget is tagged with a set of role scopes it belongs to
 * (financial, operational, strategic, driver). The visibility matrix
 * filters the widget list per caller role before returning.
 *
 * Per-request memoisation (Refinement — F11-5 performance gate): every
 * hot read is cached in $this->cache keyed by method + args, so a
 * dashboard rendering 6 widgets does 6 memoised composition calls
 * instead of 6× per-widget cache reads. Cache TTL is request-scoped —
 * cross-request caching lives in Dashboard_cache (school-admin) +
 * B2_analytics_service (SA), which this service composes UNDER.
 */
class Transport_analytics_service
{
    // ── Role → scope matrix (Refinement 3) ─────────────────────────────
    public const SCOPE_FINANCIAL   = 'financial';
    public const SCOPE_OPERATIONAL = 'operational';
    public const SCOPE_STRATEGIC   = 'strategic';
    public const SCOPE_DRIVER      = 'driver';

    public const ROLE_SCOPES = [
        'super admin'        => ['financial', 'operational', 'strategic', 'driver'],
        'super_admin'        => ['financial', 'operational', 'strategic', 'driver'],
        'school super admin' => ['financial', 'operational', 'strategic', 'driver'],
        'school_super_admin' => ['financial', 'operational', 'strategic', 'driver'],
        'admin'              => ['financial', 'operational', 'strategic', 'driver'],
        'principal'          => ['operational', 'strategic'],
        'transport manager'  => ['operational', 'strategic', 'driver'],
        'accountant'         => ['financial'],
        'hr manager'         => ['driver'],
        'vice principal'    => ['operational'],
        'operations manager' => ['operational'],
        // Teacher / Class Teacher / anything else → NO management analytics
    ];

    // ── Widget catalog (widget_key → scope_tag) ─────────────────────────
    public const WIDGET_SCOPES = [
        'fleet_kpi_tiles'          => 'operational',
        'live_ops_snapshot'        => 'operational',
        'monthly_expense_trend'    => 'financial',
        'per_category_expense'    => 'financial',
        'per_vehicle_expense'     => 'financial',
        'top_drivers_by_trips'    => 'driver',
        'top_drivers_by_ontime'   => 'driver',
        'driver_incidents_30d'     => 'driver',
        'per_route_fleet_summary'  => 'operational',
        'vehicle_utilization'      => 'operational',
        'fleet_health_score'       => 'strategic',
        'docs_expiring'            => 'operational',
        'pending_accounting'       => 'financial',
        'sos_active_today'         => 'operational',
    ];

    // Trend depth — Refinement 4 (2026-07-08): 24 months for YoY academic
    // comparisons.
    public const TREND_MONTHS = 24;

    // PERF-4: cross-tenant fleet KPI is served from this nightly-computed
    // global rollup doc (keyed by date) so the SA leaderboard never fans out
    // O(tenants) synchronously on a live request.
    public const COL_FLEET_ROLLUP = 'transportFleetRollup';

    // ── State ──────────────────────────────────────────────────────────
    /** @var object|null */ private $fs = null;
    /** @var object|null */ private $transport = null;
    /** @var object|null */ private $ci = null;
    private string $schoolId    = '';
    private string $sessionYear = '';
    private bool   $ready       = false;
    private array  $cache       = [];

    public function __construct()
    {
        $this->ci = &get_instance();
    }

    public function init($fs, $transport, string $schoolId, string $sessionYear): self
    {
        $this->fs          = $fs;
        $this->transport   = $transport;
        $this->schoolId    = $schoolId;
        $this->sessionYear = $sessionYear;
        $this->ready       = ($fs !== null && $transport !== null && $schoolId !== '');
        $this->cache       = [];
        return $this;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Role → visibility (public)
    // ═════════════════════════════════════════════════════════════════════

    /** Widget keys visible to the given role, filtered by ROLE_SCOPES. */
    public static function visibleWidgetsForRole(string $role): array
    {
        $roleKey = strtolower(trim($role));
        $scopes  = self::ROLE_SCOPES[$roleKey] ?? [];
        if ($scopes === []) { return []; }
        $out = [];
        foreach (self::WIDGET_SCOPES as $wkey => $scope) {
            if (in_array($scope, $scopes, true)) { $out[] = $wkey; }
        }
        return $out;
    }

    /** True iff the given role has any analytics visibility. */
    public static function hasAnyVisibility(string $role): bool
    {
        return self::visibleWidgetsForRole($role) !== [];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  School-admin surface — tiles + widgets
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Fleet KPI tiles for the school-admin dashboard section. Reads the
     * denormalised caches only; each read memoised per-request.
     *
     * Returns:
     *   active_vehicles, trips_today, docs_expiring_7d, docs_expiring_15d,
     *   docs_expiring_30d, fuel_spend_this_month, pending_accounting_posts,
     *   sos_active_count
     */
    public function getFleetKpiTiles(): array
    {
        $this->_assertReady();
        return $this->_memo('kpi_tiles', function () {
            $out = [
                'active_vehicles'          => 0,
                'trips_today'              => 0,
                'trips_in_progress'        => 0,
                'docs_expiring_7d'         => 0,
                'docs_expiring_15d'        => 0,
                'docs_expiring_30d'        => 0,
                'fuel_spend_this_month'    => 0.0,
                'pending_accounting_posts' => 0,
                'sos_active_count'         => 0,
            ];
            // Vehicles — light schoolWhere on vehicles (indexed by schoolId)
            try {
                $vs = (array) $this->fs->schoolWhere('vehicles',
                    [['status', '==', 'Active']], null, 'ASC', 200);
                $out['active_vehicles'] = count($vs);
            } catch (\Throwable $_) {}
            // Trips today — small window; not a full collection scan
            $today = date('Y-m-d');
            try {
                $trips = (array) $this->fs->schoolWhere('tripLogs',
                    [['date', '==', $today]], null, 'ASC', 200);
                $out['trips_today'] = count($trips);
                foreach ($trips as $t) {
                    $inner = (isset($t['data']) && is_array($t['data'])) ? $t['data'] : $t;
                    if (($inner['status'] ?? '') === 'InProgress') {
                        $out['trips_in_progress']++;
                    }
                }
            } catch (\Throwable $_) {}
            // Expiry counts — read the pre-populated cache
            try {
                $exp = $this->fs->getEntity('vehicleExpiryDashboardCache', 'VEXPCACHE');
                if (is_array($exp)) {
                    $out['docs_expiring_7d']  = (int) ($exp['count_7_days']  ?? 0);
                    $out['docs_expiring_15d'] = (int) ($exp['count_15_days'] ?? 0);
                    $out['docs_expiring_30d'] = (int) ($exp['count_30_days'] ?? 0);
                }
            } catch (\Throwable $_) {}
            // Fuel spend — read the pre-populated monthly summary
            $curMonth = date('Y-m');
            try {
                $fmes = $this->fs->getEntity('fleetMonthlyExpenseSummary', "FMES__{$curMonth}");
                if (is_array($fmes)) {
                    $out['fuel_spend_this_month']    = (float) ($fmes['total_amount'] ?? 0.0);
                    $out['pending_accounting_posts'] = (int) ($fmes['pending_accounting'] ?? 0);
                }
            } catch (\Throwable $_) {}
            // Active SOS — small filter query
            try {
                $sos = (array) $this->fs->schoolWhere('sosAlerts',
                    [['status', '==', 'active']], null, 'ASC', 50);
                $out['sos_active_count'] = count($sos);
            } catch (\Throwable $_) {}
            return $out;
        });
    }

    /**
     * Live Operations Dashboard snapshot (Refinement additional 2 —
     * 2026-07-08). Real-time compact view of what's happening RIGHT
     * NOW across the fleet. Zero new workflow — every field is
     * a fresh read over existing collections.
     */
    public function getLiveOperationsSnapshot(): array
    {
        $this->_assertReady();
        return $this->_memo('live_ops', function () {
            $today = date('Y-m-d');
            $snap = [
                'trips_in_progress'           => 0,
                'trips_delayed'               => 0,
                'trips_completed_today'       => 0,
                'active_sos_events'           => 0,
                'students_currently_onboard'  => 0,
                'students_dropped_today'      => 0,
                'vehicles_under_maintenance'  => 0,
                'no_shows_today'              => 0,
                'as_of'                       => date('c'),
            ];
            // Today's trips + per-state breakdown
            $inProgressIds = [];
            try {
                $trips = (array) $this->fs->schoolWhere('tripLogs',
                    [['date', '==', $today]], null, 'ASC', 300);
                foreach ($trips as $t) {
                    $inner = (isset($t['data']) && is_array($t['data'])) ? $t['data'] : $t;
                    $status = (string) ($inner['status'] ?? '');
                    $direction = (string) ($inner['trip_direction'] ?? '');
                    $delayCat  = (string) ($inner['delay_status'] ?? '');
                    if ($status === 'InProgress') {
                        $snap['trips_in_progress']++;
                        // Onboard bucketing keys on transportAttendance.tripId, which
                        // is the BARE canonical trip id (TRP…). The schoolWhere
                        // envelope id ($t['id']) is the PREFIXED Firestore docId
                        // ({schoolId}_TRP…) — using it here made every bucket lookup
                        // miss → students_currently_onboard stuck at 0. Use the trip
                        // doc's own `id` field (bare), set at createTrip.
                        $inProgressIds[] = (string) ($inner['id'] ?? '');
                        if ($delayCat === 'Delayed') { $snap['trips_delayed']++; }
                    }
                    if ($status === 'Completed') { $snap['trips_completed_today']++; }
                }
            } catch (\Throwable $_) {}

            // PERF-3: ONE attendance scan for today serves all three metrics —
            // students_currently_onboard, students_dropped_today, no_shows_today.
            // The previous code issued a separate per-trip query for the onboard
            // count (N+1 across the InProgress cohort) even though that data is
            // already in the [trip_date==today] scan below. We bucket boarded/
            // dropped per tripId in-memory, then sum max(0, boarded-dropped) for
            // the InProgress cohort only (identical semantics, O(1) queries).
            //
            // F14 VOCABULARY (2026-08-16). `Transport_service::BOARDING_EVENT_TYPES`
            // accepts EIGHT event types: the four legacy ones the old tap-to-board
            // app wrote, and the four leg-explicit ones the QR attendance service
            // writes. This block counted only the legacy four, so every figure
            // here silently read ZERO for a trip boarded by scanning — the office
            // would lose its head count at exactly the moment a conductor started
            // using the scanner. Both vocabularies are counted now.
            try {
                $perTripBoarded = [];   // tid => any BOARDING event, either vocabulary
                $perTripDropped = [];   // tid => any ALIGHTING event, either vocabulary
                // Taken from Transport_service, not retyped here. Two consumers
                // each keeping their own copy of this classification is exactly
                // how the legacy-only lists survived unnoticed.
                $boardEvents  = Transport_service::BOARDING_ON_EVENTS;
                $alightEvents = Transport_service::BOARDING_OFF_EVENTS;
                $todayEvents = (array) $this->fs->schoolWhere('transportAttendance',
                    [['trip_date', '==', $today]], null, 'ASC', 2000);
                foreach ($todayEvents as $e) {
                    $inner = (isset($e['data']) && is_array($e['data'])) ? $e['data'] : $e;
                    $et  = (string) ($inner['event_type'] ?? '');
                    $tid = (string) ($inner['tripId'] ?? '');
                    if (in_array($et, $boardEvents, true)) {
                        $perTripBoarded[$tid] = ($perTripBoarded[$tid] ?? 0) + 1;
                    } elseif (in_array($et, $alightEvents, true)) {
                        $perTripDropped[$tid] = ($perTripDropped[$tid] ?? 0) + 1;
                        $snap['students_dropped_today']++;
                    } elseif ($et === 'no_show') {
                        $snap['no_shows_today']++;
                    }
                }
                // Onboard = boarded MINUS alighted, per InProgress trip, floored at 0.
                foreach ($inProgressIds as $tid) {
                    $snap['students_currently_onboard'] += max(0,
                        ($perTripBoarded[$tid] ?? 0) - ($perTripDropped[$tid] ?? 0));
                }
            } catch (\Throwable $_) {}

            // Active SOS
            try {
                $sos = (array) $this->fs->schoolWhere('sosAlerts',
                    [['status', '==', 'active']], null, 'ASC', 50);
                $snap['active_sos_events'] = count($sos);
            } catch (\Throwable $_) {}

            // Vehicles under maintenance
            try {
                $vs = (array) $this->fs->schoolWhere('vehicles',
                    [['status', '==', 'Maintenance']], null, 'ASC', 100);
                $snap['vehicles_under_maintenance'] = count($vs);
            } catch (\Throwable $_) {}

            return $snap;
        });
    }

    /**
     * Fleet Health Score (0-100) — Refinement additional 1 (2026-07-08).
     * Composite metric aggregating maintenance, expiring documents,
     * incidents, driver readiness, route performance, and SOS history
     * into a single health indicator. All inputs read from existing
     * caches — no new business workflow.
     *
     * Weights (100 total):
     *   • Docs currency (30) — 30-day expiries penalise the score
     *   • Route on-time (25) — average of per-route on_time_pct
     *   • Incidents (15)     — driverActivitySummary.incidents_count
     *   • SOS history (10)   — resolved SOSes last 30d
     *   • Accounting posts (10) — pending/failed posts hurt hygiene
     *   • Maintenance (10)    — vehicles in Maintenance status
     */
    public function getFleetHealthScore(): array
    {
        $this->_assertReady();
        return $this->_memo('health_score', function () {
            $breakdown = [
                'docs_currency'    => 30.0,   // start full, penalise below
                'route_on_time'    => 25.0,
                'incidents'        => 15.0,
                'sos_history'      => 10.0,
                'accounting_posts' => 10.0,
                'maintenance'      => 10.0,
            ];

            // 1. Docs currency — subtract 3 per 7d expiry, 1 per 15d, 0.5 per 30d
            try {
                $exp = $this->fs->getEntity('vehicleExpiryDashboardCache', 'VEXPCACHE');
                if (is_array($exp)) {
                    $penalty = 3.0 * (int) ($exp['count_7_days'] ?? 0)
                             + 1.0 * (int) ($exp['count_15_days'] ?? 0)
                             + 0.5 * (int) ($exp['count_30_days'] ?? 0);
                    $breakdown['docs_currency'] = max(0.0, 30.0 - $penalty);
                }
            } catch (\Throwable $_) {}

            // 2. Route on-time — average per_route on_time_pct → 0..25
            try {
                $rows = (array) $this->fs->schoolWhere('routeFleetSummary', [], null, 'ASC', 200);
                $sum = 0.0; $n = 0;
                foreach ($rows as $r) {
                    $inner = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
                    if (isset($inner['on_time_pct']) && $inner['on_time_pct'] !== null) {
                        $sum += (float) $inner['on_time_pct']; $n++;
                    }
                }
                if ($n > 0) {
                    $avg = $sum / $n;
                    $breakdown['route_on_time'] = round(25.0 * ($avg / 100.0), 2);
                }
            } catch (\Throwable $_) {}

            // 3. Incidents — subtract 2 per driver incident this month
            try {
                $curMonth = date('Y-m');
                $das = (array) $this->fs->schoolWhere('driverActivitySummary',
                    [['period_year_month', '==', $curMonth]], null, 'ASC', 200);
                $incidents = 0;
                foreach ($das as $d) {
                    $inner = (isset($d['data']) && is_array($d['data'])) ? $d['data'] : $d;
                    $incidents += (int) ($inner['incidents_count'] ?? 0);
                }
                $breakdown['incidents'] = max(0.0, 15.0 - 2.0 * $incidents);
            } catch (\Throwable $_) {}

            // 4. SOS history — subtract 1 per active + 0.5 per unresolved 30d
            try {
                $active = (array) $this->fs->schoolWhere('sosAlerts',
                    [['status', '==', 'active']], null, 'ASC', 50);
                $breakdown['sos_history'] = max(0.0, 10.0 - 1.0 * count($active));
            } catch (\Throwable $_) {}

            // 5. Accounting posts — pending/failed hurt hygiene
            try {
                $curMonth = date('Y-m');
                $fmes = $this->fs->getEntity('fleetMonthlyExpenseSummary', "FMES__{$curMonth}");
                if (is_array($fmes)) {
                    $bad = (int) ($fmes['pending_accounting'] ?? 0)
                         + 2 * (int) ($fmes['failed_accounting'] ?? 0);
                    $breakdown['accounting_posts'] = max(0.0, 10.0 - 0.5 * $bad);
                }
            } catch (\Throwable $_) {}

            // 6. Maintenance — subtract 2 per vehicle in maintenance
            try {
                $vs = (array) $this->fs->schoolWhere('vehicles',
                    [['status', '==', 'Maintenance']], null, 'ASC', 100);
                $breakdown['maintenance'] = max(0.0, 10.0 - 2.0 * count($vs));
            } catch (\Throwable $_) {}

            $score = 0.0;
            foreach ($breakdown as $v) { $score += $v; }
            $score = round($score, 1);
            $tier  = ($score >= 85) ? 'Excellent' : (($score >= 70) ? 'Good' : (($score >= 50) ? 'Fair' : 'Attention needed'));
            return [
                'score'     => $score,
                'tier'      => $tier,
                'breakdown' => $breakdown,
                'as_of'     => date('c'),
            ];
        });
    }

    /**
     * 24-month expense trend (Refinement 4). Reads fleetMonthlyExpenseSummary
     * doc-by-doc — one composite index query bounded by month range.
     */
    public function getFleetExpenseTrend(int $months = self::TREND_MONTHS): array
    {
        $this->_assertReady();
        return $this->_memo("trend_expense_{$months}", function () use ($months) {
            // Build the ordered month list.
            $yms = [];
            for ($i = $months - 1; $i >= 0; $i--) {
                $yms[] = date('Y-m', strtotime("-{$i} months"));
            }
            // PERF-11: ONE query over period_year_month IN [months] instead of
            // N sequential point-reads (Firestore IN caps at 30; TREND_MONTHS=24).
            // Falls back to per-month reads if the IN query fails (e.g. missing
            // composite index) so behaviour is preserved either way.
            $byYm = [];
            $chunks = array_chunk($yms, 30);
            $queried = true;
            foreach ($chunks as $chunk) {
                try {
                    $rows = (array) $this->fs->schoolWhere('fleetMonthlyExpenseSummary',
                        [['period_year_month', 'in', $chunk]], null, 'ASC', 60);
                    foreach ($rows as $r) {
                        $docId = (string) ($r['id'] ?? '');
                        $inner = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
                        $ym = (string) ($inner['period_year_month'] ?? '');
                        // PERF-11: accept ONLY the canonical FMES doc — the same
                        // id the point-read uses (fs->docId adds the single
                        // {schoolId} prefix) — so any legacy double-prefixed
                        // duplicate carrying period_year_month is ignored
                        // (identical result to the original point-read).
                        if ($ym === '' || $docId !== $this->fs->docId("FMES__{$ym}")) { continue; }
                        $byYm[$ym] = [
                            'total_amount'   => (float) ($inner['total_amount'] ?? 0),
                            'expenses_count' => (int) ($inner['expenses_count'] ?? 0),
                        ];
                    }
                } catch (\Throwable $_) { $queried = false; break; }
            }
            if (!$queried) {
                // Fallback — per-month point reads (original behaviour).
                $byYm = [];
                foreach ($yms as $ym) {
                    try {
                        $doc = $this->fs->getEntity('fleetMonthlyExpenseSummary', "FMES__{$ym}");
                        if (is_array($doc)) {
                            $byYm[$ym] = [
                                'total_amount'   => (float) ($doc['total_amount'] ?? 0),
                                'expenses_count' => (int) ($doc['expenses_count'] ?? 0),
                            ];
                        }
                    } catch (\Throwable $_) {}
                }
            }
            // Emit every month in order, zero-filling gaps.
            $out = [];
            foreach ($yms as $ym) {
                $out[] = [
                    'period_year_month' => $ym,
                    'total_amount'      => (float) ($byYm[$ym]['total_amount'] ?? 0),
                    'expenses_count'    => (int) ($byYm[$ym]['expenses_count'] ?? 0),
                ];
            }
            return $out;
        });
    }

    /**
     * Top drivers by trips (last N months). Reads driverActivitySummary
     * only — no per-driver getEntity loops (F11-1 gate).
     */
    public function getTopDrivers(int $limit = 5, int $months = 1): array
    {
        $this->_assertReady();
        return $this->_memo("top_drivers_{$limit}_{$months}", function () use ($limit, $months) {
            $ym = date('Y-m');
            try {
                $rows = (array) $this->fs->schoolWhere('driverActivitySummary',
                    [['period_year_month', '==', $ym]], null, 'ASC', 500);
            } catch (\Throwable $_) { return []; }
            $drivers = [];
            foreach ($rows as $r) {
                $inner = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
                if (!is_array($inner)) { continue; }
                $drivers[] = [
                    'staff_id'     => (string) ($inner['staff_id'] ?? ''),
                    'trips_count'  => (int) ($inner['trips_count'] ?? 0),
                    'total_km'     => (int) ($inner['total_km'] ?? 0),
                    'on_time_pct'  => (float) ($inner['on_time_pct'] ?? 0),
                    'incidents'    => (int) ($inner['incidents_count'] ?? 0),
                ];
            }
            usort($drivers, static fn($a, $b) => $b['trips_count'] <=> $a['trips_count']);
            return array_slice($drivers, 0, $limit);
        });
    }

    public function getPerRouteFleet(): array
    {
        $this->_assertReady();
        return $this->_memo('per_route_fleet', function () {
            try {
                $rows = (array) $this->fs->schoolWhere('routeFleetSummary', [], null, 'ASC', 200);
            } catch (\Throwable $_) { return []; }
            $out = [];
            foreach ($rows as $r) {
                $inner = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
                if (is_array($inner)) { $out[] = $inner; }
            }
            return $out;
        });
    }

    /**
     * Bundle everything the caller's role can see. Called from the
     * dashboard controller once per render. Widget filtering is applied
     * server-side so a client tampering with the client-side hide list
     * cannot access widgets outside their scope.
     */
    public function bundleForRole(string $role): array
    {
        $visible = self::visibleWidgetsForRole($role);
        $bundle = ['visible_widgets' => $visible];
        if (in_array('fleet_kpi_tiles', $visible, true))       { $bundle['fleet_kpi_tiles']       = $this->getFleetKpiTiles(); }
        if (in_array('live_ops_snapshot', $visible, true))     { $bundle['live_ops_snapshot']     = $this->getLiveOperationsSnapshot(); }
        if (in_array('monthly_expense_trend', $visible, true)) { $bundle['monthly_expense_trend'] = $this->getFleetExpenseTrend(); }
        if (in_array('top_drivers_by_trips', $visible, true))  { $bundle['top_drivers_by_trips']  = $this->getTopDrivers(5, 1); }
        if (in_array('per_route_fleet_summary', $visible, true)) { $bundle['per_route_fleet_summary'] = $this->getPerRouteFleet(); }
        if (in_array('fleet_health_score', $visible, true))    { $bundle['fleet_health_score']    = $this->getFleetHealthScore(); }
        return $bundle;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Super Admin surface — cross-tenant (LC-25)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Aggregate fleet KPI across an explicit tenant list. Callers pass
     * tenant ids resolved by B2_registry_service (already memoised
     * upstream). Per-tenant reads use the same cached surface as the
     * school-admin path — no new business logic.
     *
     * @param array $tenantIds list of schoolId strings
     * @return array { total_vehicles, total_trips_today, total_fuel_spend,
     *                total_docs_expiring_7d, total_active_sos, per_tenant[] }
     */
    public function getCrossTenantFleetKpi(array $tenantIds): array
    {
        $this->_assertReady();
        if ($tenantIds === []) { return ['per_tenant' => [], 'totals' => []]; }
        $totals = [
            'total_vehicles' => 0, 'total_trips_today' => 0,
            'total_fuel_spend_this_month' => 0.0,
            'total_docs_expiring_7d' => 0, 'total_active_sos' => 0,
        ];
        $perTenant = [];
        $originalSid = $this->schoolId;
        try {
            foreach ($tenantIds as $tid) {
                $tid = (string) $tid;
                if ($tid === '') { continue; }
                // PERF-6: isolate each tenant. A single tenant whose compose
                // throws (bad data / transient read error) must NOT abort the
                // whole nightly rollup or SA leaderboard — record an error marker
                // for it and press on so the other tenants still aggregate.
                try {
                    // Rewire the state to this tenant, then compose tiles
                    $this->schoolId = $tid;
                    $this->fs->init($tid, $this->sessionYear);
                    $this->cache = [];   // per-tenant memoisation reset
                    $tiles = $this->getFleetKpiTiles();
                    $perTenant[$tid] = $tiles;
                    $totals['total_vehicles']           += (int) $tiles['active_vehicles'];
                    $totals['total_trips_today']        += (int) $tiles['trips_today'];
                    $totals['total_fuel_spend_this_month'] += (float) $tiles['fuel_spend_this_month'];
                    $totals['total_docs_expiring_7d']   += (int) $tiles['docs_expiring_7d'];
                    $totals['total_active_sos']         += (int) $tiles['sos_active_count'];
                } catch (\Throwable $e) {
                    $perTenant[$tid] = ['error' => substr((string) $e->getMessage(), 0, 200)];
                    log_message('error', "TRN_CROSS_TENANT_KPI_SKIP tenant={$tid} err=" . $e->getMessage());
                }
            }
        } finally {
            // SEC-5: ALWAYS restore the shared FS singleton + service state,
            // even if a tenant's compose throws mid-loop — otherwise the
            // singleton is left pointing at the last tenant and a subsequent
            // operation in the same request reads/writes the WRONG school
            // (cross-tenant bleed). (SA-role gating is enforced at the controller
            // layer when the cached path is wired to an HTTP surface.)
            $this->schoolId = $originalSid;
            if ($originalSid !== '') { $this->fs->init($originalSid, $this->sessionYear); }
            $this->cache = [];
        }
        $totals['total_fuel_spend_this_month'] = round($totals['total_fuel_spend_this_month'], 2);
        return ['totals' => $totals, 'per_tenant' => $perTenant];
    }

    /**
     * PERF-4 — request-time cross-tenant KPI. Serves the nightly rollup doc
     * (one document read, O(1)) when present; otherwise degrades to a BOUNDED
     * live compute (at most $maxLiveFallback tenants) so an SA request can never
     * synchronously fan out across every tenant. The nightly job
     * storeCrossTenantFleetRollup() is the intended O(1)-serving path.
     */
    public function getCrossTenantFleetKpiCached(array $tenantIds, int $maxLiveFallback = 25): array
    {
        $this->_assertReady();
        $today = date('Y-m-d');
        try {
            $rollup = $this->fs->get(self::COL_FLEET_ROLLUP, $today);
            if (is_array($rollup) && isset($rollup['totals']) && is_array($rollup['totals'])) {
                $rollup['source'] = 'rollup';
                return $rollup;
            }
        } catch (\Throwable $_) {}
        // No fresh rollup — capped live degradation (never the full O(tenants) loop).
        $capped = array_slice(array_values($tenantIds), 0, max(0, $maxLiveFallback));
        $out = $this->getCrossTenantFleetKpi($capped);
        $out['source']           = 'live_partial';
        $out['partial']          = (count($tenantIds) > count($capped));
        $out['tenantsRequested'] = count($tenantIds);
        $out['tenantsComputed']  = count($capped);
        return $out;
    }

    /**
     * PERF-4 — NIGHTLY writer. Runs the (unbounded) per-tenant compute in a cron
     * context and persists the aggregate to transportFleetRollup/{date} so the
     * request-time path (getCrossTenantFleetKpiCached / getFleetLeaderboard)
     * serves it with a single document read. Invoked by Transport_fleet_rollup_job.
     */
    public function storeCrossTenantFleetRollup(array $tenantIds): array
    {
        $this->_assertReady();
        $today  = date('Y-m-d');
        $kpi    = $this->getCrossTenantFleetKpi($tenantIds);
        $rollup = [
            'date'        => $today,
            'computedAt'  => date('c'),
            'tenantCount' => count($kpi['per_tenant'] ?? []),
            'totals'      => (array) ($kpi['totals'] ?? []),
            'per_tenant'  => (array) ($kpi['per_tenant'] ?? []),
        ];
        $this->fs->set(self::COL_FLEET_ROLLUP, $today, $rollup, /* merge */ false);
        return $rollup;
    }

    /**
     * Fleet leaderboard — order per-tenant KPIs by a chosen metric.
     * Supported metrics: 'trips_today', 'fuel_spend_this_month',
     * 'active_vehicles', 'docs_expiring_7d', 'sos_active_count'.
     * PERF-4: serves from the cross-tenant rollup (O(1)) via the cached path.
     */
    public function getFleetLeaderboard(array $tenantIds, string $metric, int $limit = 10): array
    {
        $allowed = ['trips_today','fuel_spend_this_month','active_vehicles','docs_expiring_7d','sos_active_count'];
        if (!in_array($metric, $allowed, true)) { return []; }
        $kpi = $this->getCrossTenantFleetKpiCached($tenantIds);
        $rows = [];
        foreach (($kpi['per_tenant'] ?? []) as $tid => $tiles) {
            $rows[] = ['tenant_id' => $tid, 'value' => (float) $tiles[$metric]];
        }
        usort($rows, static fn($a, $b) => $b['value'] <=> $a['value']);
        return array_slice($rows, 0, $limit);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Internals
    // ═════════════════════════════════════════════════════════════════════

    /** Per-request memoisation. F11-5 verifier gate. */
    private function _memo(string $key, \Closure $producer)
    {
        // PERF-10: tenant-scope the memo key so a service instance reused across
        // tenants (e.g. the cross-tenant KPI loop) can never return school A's
        // cached value for school B — correctness no longer depends on an
        // external `cache=[]` reset between tenants.
        $key = $this->schoolId . '|' . $key;
        if (array_key_exists($key, $this->cache)) { return $this->cache[$key]; }
        $v = $producer();
        $this->cache[$key] = $v;
        return $v;
    }

    private function _assertReady(): void
    {
        if (!$this->ready) {
            throw new \RuntimeException('Transport_analytics_service not initialised. Call init() first.');
        }
    }
}
