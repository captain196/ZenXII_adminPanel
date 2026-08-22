<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_fleet_service — F13.2 Admin live fleet, read side.
 *
 * Assembles one snapshot of every actively-tracked bus by joining canonical
 * F13.1 telemetry to canonical Transport trip data. It is a **pure CONSUMER**:
 * it never writes GPS, never validates a fix, never touches the trip lifecycle.
 * All of that stays in `Transport_gps_service` and `Transport_service`, which
 * remain the only authorities.
 *
 * ─── NO SECOND GPS SYSTEM ────────────────────────────────────────────────
 * The live position is read from `vehicleLivePositions` — the same document the
 * driver writes and the parent reads. No duplicate collection, no mirror, no
 * cache of telemetry beyond the short request-level one in the controller.
 *
 * ─── WHY THIS IS TWO QUERIES, NOT N ──────────────────────────────────────
 * `vehicleLivePositions` is queryable by tenant on a single `schoolId` field,
 * so ONE query returns the whole fleet; `tripLogs` filtered to InProgress is a
 * second. Everything else (vehicles, routes, staff) is small, slow-changing,
 * and fetched in bulk. The alternative — a read per vehicle — is what turns a
 * 3-bus demo into an unusable 100-bus deployment.
 *
 * ─── WHAT IS MEASURED VS WHAT IS NOT ─────────────────────────────────────
 * Everything reported here is derived from stored facts:
 *   • freshness   ← recorded_at_ms vs now
 *   • stop state  ← checkpoints[].checkpoint_state (driver-confirmed)
 *   • distance    ← haversine(live position, checkpoint.stop_location)
 *   • lateness    ← now vs checkpoints[].scheduled_pickup_time (frozen at createTrip)
 *
 * There is deliberately **NO ETA**. An arrival time needs travel-time
 * modelling that does not exist here, and `distance ÷ current_speed` is worse
 * than nothing on a school route — a bus at a red light reports 0 km/h and
 * would imply it never arrives. A parent timing the walk to a stop is exactly
 * who a wrong number hurts. Deferred to F13.3; see the architecture audit §3.1.
 *
 * There is also **no dead reckoning**. Position is always the last reported
 * fix. Smoothing between two real fixes is a rendering concern and lives in
 * the client renderer, never here.
 */
class Transport_fleet_service
{
    /** Canonical F13.1 collections — read-only from here. */
    public const COL_LIVE_POSITIONS = 'vehicleLivePositions';
    public const COL_TRIP_LOGS      = 'tripLogs';

    /**
     * Freshness bands.
     *
     * `STALE_AFTER_S` deliberately reuses `Transport_gps_service::STALE_AFTER_S`
     * (120s) rather than inventing a second threshold — the admin and the
     * parent must not disagree about whether a bus is live.
     *
     * `NO_SIGNAL_AFTER_S` is additive, not a redefinition: it separates "a fix
     * is a little old" from "we have effectively lost this bus", which the
     * admin fleet view needs and a single-vehicle parent card did not.
     */
    public const STALE_AFTER_S     = 120;    // mirrors Transport_gps_service
    public const NO_SIGNAL_AFTER_S = 600;    // 10 min — fleet-view only

    /** Tracking states, most healthy first. */
    public const STATE_LIVE         = 'live';
    public const STATE_STALE        = 'stale';
    public const STATE_NO_SIGNAL    = 'no_signal';
    public const STATE_NOT_TRACKING = 'not_tracking';

    /** Minutes behind schedule before a bus is flagged late. */
    public const LATE_THRESHOLD_MIN = 5;

    /**
     * Beyond this the delta is no longer schedule adherence. 12h is past the
     * end of any school run while still inside a same-day trip closed late, so
     * a genuine long overrun still reports as late; only trips left open across
     * days fall through to the 'abandoned' reading.
     */
    public const MAX_MEANINGFUL_DELTA_MIN = 720;

    /** @var object|null Firestore_service */
    private $fs = null;
    private string $schoolId = '';

    public function __construct()
    {
        get_instance()->load->helper('geofence');
    }

    public function init($fs, string $schoolId): self
    {
        $this->fs       = $fs;
        $this->schoolId = trim($schoolId);
        return $this;
    }

    private function _assertReady(): void
    {
        if ($this->fs === null || $this->schoolId === '') {
            throw new \LogicException('Transport_fleet_service::init() must be called first.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  The snapshot
    // ─────────────────────────────────────────────────────────────────────

    /**
     * One complete fleet snapshot.
     *
     * @return array{
     *   generated_at:string, counts:array, vehicles:array<int,array>, reads:array
     * }
     */
    public function snapshot(?int $nowTs = null): array
    {
        $this->_assertReady();
        $now = $nowTs ?? time();
        $reads = ['queries' => 0, 'documents' => 0];

        // 1. In-progress trips. The trip is the authority on what is active —
        //    a live-position document could linger after a crash, and the
        //    sweeper may not have run yet, so the TRIP decides.
        $trips = [];
        try {
            $rows = (array) $this->fs->schoolWhere(
                self::COL_TRIP_LOGS, [['status', '==', 'InProgress']], null, 'ASC', 200
            );
            $reads['queries']++; $reads['documents'] += count($rows);
            foreach ($rows as $r) {
                $d = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
                $id = (string) ($r['id'] ?? $d['id'] ?? '');
                $vid = trim((string) ($d['vehicleId'] ?? ''));
                if ($vid === '') { continue; }
                $d['__id'] = $id;
                $trips[$vid] = $d;      // one active trip per vehicle
            }
        } catch (\Throwable $e) {
            log_message('error', 'Transport_fleet_service trips query failed: ' . $e->getMessage());
            return ['generated_at' => date('c'), 'counts' => $this->_emptyCounts(),
                    'vehicles' => [], 'reads' => $reads,
                    'error' => 'Could not load active trips.'];
        }

        if ($trips === []) {
            // Not an error — most of the day there is simply no trip running.
            return ['generated_at' => date('c'), 'counts' => $this->_emptyCounts(),
                    'vehicles' => [], 'reads' => $reads];
        }

        // 2. Whole fleet's live positions in ONE query.
        $positions = [];
        try {
            $rows = (array) $this->fs->schoolWhere(self::COL_LIVE_POSITIONS, [], null, 'ASC', 300);
            $reads['queries']++; $reads['documents'] += count($rows);
            foreach ($rows as $r) {
                $d = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
                $vid = trim((string) ($d['vehicleId'] ?? ''));
                if ($vid !== '') { $positions[$vid] = $d; }
            }
        } catch (\Throwable $e) {
            // Degrade honestly: trips still render, with no position.
            log_message('error', 'Transport_fleet_service positions query failed: ' . $e->getMessage());
        }

        // 3. Reference data, bulk. Small and slow-changing.
        $vehicles = $this->_bulk('vehicles', array_keys($trips), $reads);
        $routeIds = [];
        $staffIds = [];
        foreach ($trips as $t) {
            $rid = trim((string) ($t['route_id'] ?? ''));         if ($rid !== '') { $routeIds[$rid] = true; }
            $sid = trim((string) ($t['driver_staff_id'] ?? ''));  if ($sid !== '') { $staffIds[$sid] = true; }
        }
        $routes = $this->_bulk('routes', array_keys($routeIds), $reads);
        $staff  = $this->_bulk('staff',  array_keys($staffIds), $reads);

        // 4. Assemble.
        $out = [];
        foreach ($trips as $vehicleId => $trip) {
            $out[] = $this->_buildVehicle(
                $vehicleId, $trip,
                $positions[$vehicleId] ?? null,
                $vehicles[$vehicleId] ?? null,
                $routes[(string) ($trip['route_id'] ?? '')] ?? null,
                $staff[(string) ($trip['driver_staff_id'] ?? '')] ?? null,
                $now
            );
        }

        // Most-attention-first: no_signal, then stale, then live.
        $rank = [self::STATE_NO_SIGNAL => 0, self::STATE_NOT_TRACKING => 1,
                 self::STATE_STALE => 2, self::STATE_LIVE => 3];
        usort($out, static function ($a, $b) use ($rank) {
            $d = ($rank[$a['tracking_state']] ?? 9) <=> ($rank[$b['tracking_state']] ?? 9);
            return $d !== 0 ? $d : strcmp((string) $a['vehicle_number'], (string) $b['vehicle_number']);
        });

        return [
            'generated_at' => date('c', $now),
            'counts'       => $this->_counts($out),
            'vehicles'     => $out,
            'reads'        => $reads,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * One vehicle row: identity, telemetry, freshness, stop progress, schedule.
     */
    private function _buildVehicle(
        string $vehicleId, array $trip, ?array $pos,
        ?array $vehicle, ?array $route, ?array $driver, int $now
    ): array {
        $checkpoints = $this->_normalizeCheckpoints((array) ($trip['checkpoints'] ?? []));
        $progress    = $this->_stopProgress($checkpoints);
        $fresh       = $this->_freshness($trip, $pos, $now);

        $next = $progress['next'];
        $distanceM = null;
        if ($next !== null && $pos !== null
            && gf_valid_coord($pos['lat'] ?? null, $pos['lng'] ?? null)
            && gf_valid_coord($next['lat'] ?? null, $next['lng'] ?? null)) {
            // A measured straight-line distance — NOT a road distance and NOT
            // an ETA. Labelled as such in the UI.
            $distanceM = round(gf_haversine(
                (float) $pos['lat'], (float) $pos['lng'],
                (float) $next['lat'], (float) $next['lng']
            ), 0);
        }

        $schedule = $this->_scheduleDelta($next, $trip, $now);

        return [
            'vehicle_id'     => $vehicleId,
            'vehicle_number' => (string) ($vehicle['number'] ?? $vehicleId),
            'trip_id'        => (string) ($trip['__id'] ?? ''),
            'trip_status'    => (string) ($trip['status'] ?? ''),
            'trip_direction' => (string) ($trip['trip_direction'] ?? ''),
            'route_id'       => (string) ($trip['route_id'] ?? ''),
            'route_name'     => (string) ($route['name'] ?? ($trip['route_id'] ?? '')),
            'driver_id'      => (string) ($trip['driver_staff_id'] ?? ''),
            'driver_name'    => (string) ($driver['name'] ?? ''),
            'driver_phone'   => (string) ($driver['phone'] ?? ''),

            // ── telemetry (last REPORTED fix; never extrapolated) ──
            'lat'            => $pos !== null && isset($pos['lat']) ? (float) $pos['lat'] : null,
            'lng'            => $pos !== null && isset($pos['lng']) ? (float) $pos['lng'] : null,
            'heading'        => $pos !== null && isset($pos['heading']) ? (float) $pos['heading'] : null,
            'speed_kmph'     => $pos !== null && isset($pos['speed_kmph']) ? (float) $pos['speed_kmph'] : null,
            'accuracy_m'     => $pos !== null && isset($pos['accuracy_m']) ? (float) $pos['accuracy_m'] : null,
            'recorded_at'    => (string) ($pos['recorded_at'] ?? ''),
            'recorded_at_ms' => (int) ($pos['recorded_at_ms'] ?? 0),

            // ── freshness ──
            'tracking_state' => $fresh['state'],
            'age_seconds'    => $fresh['age'],
            'freshness_text' => $fresh['text'],

            // ── stop progress (driver-confirmed, not GPS-guessed) ──
            'stops_total'      => $progress['total'],
            'stops_done'       => $progress['done'],
            'stops_remaining'  => $progress['remaining'],
            'next_stop_id'     => $next['stop_id']   ?? '',
            'next_stop_name'   => $next['stop_name'] ?? '',
            'last_stop_name'   => $progress['last_name'],
            'progress_pct'     => $progress['pct'],
            'distance_to_next_m' => $distanceM,

            // ── schedule (measured against the frozen itinerary) ──
            'schedule_state'  => $schedule['state'],
            'schedule_delta_min' => $schedule['delta_min'],
            'schedule_text'   => $schedule['text'],

            // ── occupancy ──
            'roster_size'    => (int) ($trip['roster_size'] ?? 0),
            'boarded_count'  => (int) ($trip['boarded_count'] ?? 0),

            // Ordered stop list — lets the client draw the route line and the
            // stop markers from canonical planned coordinates.
            'stops'          => $checkpoints,
        ];
    }

    /** Sort and shape checkpoints; tolerate partial docs. */
    private function _normalizeCheckpoints(array $raw): array
    {
        $out = [];
        foreach ($raw as $c) {
            if (!is_array($c)) { continue; }
            $loc = is_array($c['stop_location'] ?? null) ? $c['stop_location'] : [];
            $out[] = [
                'stop_id'   => (string) ($c['stop_id'] ?? ''),
                'stop_name' => (string) ($c['stop_name'] ?? ''),
                'order'     => (int) ($c['order'] ?? 0),
                'state'     => (string) ($c['checkpoint_state'] ?? 'Pending'),
                'reached_at' => (string) ($c['reached_at'] ?? ''),
                'scheduled' => (string) ($c['scheduled_pickup_time'] ?? $c['scheduled_drop_time'] ?? ''),
                'lat'       => isset($loc['lat']) ? (float) $loc['lat'] : null,
                'lng'       => isset($loc['lng']) ? (float) $loc['lng'] : null,
            ];
        }
        usort($out, static fn($a, $b) => $a['order'] <=> $b['order']);
        return $out;
    }

    /**
     * Stop progress from driver-confirmed checkpoint state.
     *
     * Deliberately NOT inferred from GPS proximity: a bus passing within 50 m
     * of a stop it did not serve must not count as arrived, and a driver who
     * taps arrival is the authoritative signal F13.1 already established.
     */
    private function _stopProgress(array $checkpoints): array
    {
        $total = count($checkpoints);
        $done = 0; $next = null; $lastName = '';
        foreach ($checkpoints as $c) {
            if ($c['state'] === 'Reached' || $c['state'] === 'Skipped') {
                $done++;
                if ($c['state'] === 'Reached') { $lastName = $c['stop_name']; }
            } elseif ($next === null) {
                $next = $c;
            }
        }
        return [
            'total'     => $total,
            'done'      => $done,
            'remaining' => max(0, $total - $done),
            'next'      => $next,
            'last_name' => $lastName,
            'pct'       => $total > 0 ? (int) round(($done / $total) * 100) : 0,
        ];
    }

    /**
     * Freshness band.
     *
     * `live_tracking_enabled == false` (the F13.1 kill switch) or a missing
     * document means NOT TRACKING — which is a normal state, not an error. A
     * trip can legitimately be InProgress before its first fix arrives.
     */
    private function _freshness(array $trip, ?array $pos, int $now): array
    {
        $enabled = !empty($trip['live_tracking_enabled']);
        $ms  = (int) ($pos['recorded_at_ms'] ?? 0);

        if ($pos === null || !$enabled || $ms <= 0) {
            return ['state' => self::STATE_NOT_TRACKING, 'age' => null,
                    'text'  => $pos === null ? 'Not tracking' : 'Waiting for first fix'];
        }
        $age = max(0, $now - (int) floor($ms / 1000));
        if ($age > self::NO_SIGNAL_AFTER_S) {
            return ['state' => self::STATE_NO_SIGNAL, 'age' => $age,
                    'text'  => 'Last seen ' . $this->_ago($age)];
        }
        if ($age > self::STALE_AFTER_S) {
            return ['state' => self::STATE_STALE, 'age' => $age,
                    'text'  => 'Last seen ' . $this->_ago($age)];
        }
        return ['state' => self::STATE_LIVE, 'age' => $age, 'text' => 'Updated ' . $this->_ago($age)];
    }

    /**
     * Schedule adherence against the itinerary frozen onto the trip.
     *
     * `scheduled_pickup_time` is a wall-clock "HH:MM" for the trip's own date,
     * so the comparison is anchored to that date rather than today — a trip
     * left open past midnight must not read as 23 hours late.
     *
     * Reports only what is measured: minutes early/behind against the next
     * unserved stop. No prediction, no ETA.
     */
    private function _scheduleDelta(?array $next, array $trip, int $now): array
    {
        $unknown = ['state' => 'unknown', 'delta_min' => null, 'text' => ''];
        if ($next === null || ($next['scheduled'] ?? '') === '') { return $unknown; }

        $date = trim((string) ($trip['trip_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return $unknown; }
        if (!preg_match('/^(\d{1,2}):(\d{2})/', (string) $next['scheduled'], $m)) { return $unknown; }

        $sched = strtotime($date . ' ' . sprintf('%02d:%02d:00', (int) $m[1], (int) $m[2]));
        if ($sched === false) { return $unknown; }

        $delta = (int) round(($now - $sched) / 60);   // + = behind schedule

        // Past a whole trip-day the number stops describing schedule adherence.
        // A trip still open days later is not "running 28,147 minutes behind" —
        // it was never closed, and reporting a five-digit lateness both buries
        // that fact and inflates the behind-schedule count with a bus nobody is
        // waiting for. Name the actual condition instead, and keep it out of
        // the 'late' bucket.
        if (abs($delta) > self::MAX_MEANINGFUL_DELTA_MIN) {
            return ['state' => 'abandoned', 'delta_min' => $delta,
                    'text' => 'Trip left open since ' . $date . ' — schedule not meaningful'];
        }

        if ($delta > self::LATE_THRESHOLD_MIN) {
            return ['state' => 'late', 'delta_min' => $delta,
                    'text' => $this->_mins($delta) . ' behind schedule'];
        }
        if ($delta < -self::LATE_THRESHOLD_MIN) {
            return ['state' => 'early', 'delta_min' => $delta,
                    'text' => $this->_mins(abs($delta)) . ' ahead of schedule'];
        }
        return ['state' => 'on_time', 'delta_min' => $delta, 'text' => 'On time'];
    }

    /** "95" → "1h 35m". Keeps long deltas readable without losing precision. */
    private function _mins(int $m): string
    {
        return $m < 60 ? $m . ' min' : intdiv($m, 60) . 'h ' . ($m % 60) . 'm';
    }

    /**
     * "N stops away" for a specific stop on an active trip — the parent-facing
     * question, answered from driver-confirmed state.
     *
     * @return array{found:bool, stops_away:int|null, target:string}
     */
    public function stopsAway(array $checkpoints, string $targetStopId): array
    {
        $checkpoints = $this->_normalizeCheckpoints($checkpoints);
        $away = 0; $found = false; $name = '';
        foreach ($checkpoints as $c) {
            if ($c['stop_id'] === $targetStopId) { $found = true; $name = $c['stop_name']; break; }
            if ($c['state'] === 'Pending') { $away++; }
        }
        if (!$found) { return ['found' => false, 'stops_away' => null, 'target' => '']; }
        return ['found' => true, 'stops_away' => $away, 'target' => $name];
    }

    // ─────────────────────────────────────────────────────────────────────

    /** Bulk point-reads for reference data. Small, slow-changing collections. */
    private function _bulk(string $collection, array $ids, array &$reads): array
    {
        $out = [];
        foreach (array_values(array_unique(array_filter($ids))) as $id) {
            try {
                $d = $this->fs->getEntity($collection, (string) $id);
                $reads['documents']++;
                if (is_array($d)) { $out[(string) $id] = $d; }
            } catch (\Throwable $_) { /* a missing reference must not break the fleet view */ }
        }
        return $out;
    }

    private function _counts(array $vehicles): array
    {
        $c = $this->_emptyCounts();
        $c['active'] = count($vehicles);
        foreach ($vehicles as $v) {
            switch ($v['tracking_state']) {
                case self::STATE_LIVE:      $c['live']++; break;
                case self::STATE_STALE:     $c['stale']++; break;
                case self::STATE_NO_SIGNAL: $c['no_signal']++; break;
                default:                    $c['not_tracking']++; break;
            }
            if (($v['schedule_state'] ?? '') === 'late') { $c['late']++; }
        }
        return $c;
    }

    private function _emptyCounts(): array
    {
        return ['active' => 0, 'live' => 0, 'stale' => 0,
                'no_signal' => 0, 'not_tracking' => 0, 'late' => 0];
    }

    private function _ago(int $seconds): string
    {
        if ($seconds < 60)   { return $seconds . ' sec ago'; }
        if ($seconds < 3600) { return (int) floor($seconds / 60) . ' min ago'; }
        return (int) floor($seconds / 3600) . ' hr ago';
    }
}
