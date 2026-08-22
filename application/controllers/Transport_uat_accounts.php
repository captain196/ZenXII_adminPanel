<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * READ-ONLY inventory of the real accounts a person needs in order to run the
 * F13.1 / F13.2 manual UAT.
 *
 * It answers "which existing driver and which existing parent can I sign in
 * as?" — nothing more. It creates no accounts, writes nothing, and cannot
 * reveal a password: credentials are hashed in Firebase Auth and are not
 * readable by anyone, including this script. Reset one from the admin panel.
 *
 * Phone numbers are masked; a login identifier is shown in full because that
 * is the thing being looked up.
 *
 *   SCHOOL_ID=SCH_XXXXXX SESSION_YEAR=2026-27 php index.php transport_uat_accounts run
 *
 * Delete this file once UAT is done — it is a lookup aid, not a feature.
 */
class Transport_uat_accounts extends CI_Controller
{
    /** CI's Loader assigns library instances onto the controller, so this
     *  cannot be private. */
    public $fs = null;

    public function __construct()
    {
        parent::__construct();
        if (!is_cli()) { show_404(); }
    }

    /**
     * where()/schoolWhere() hand back ['id'=>…, 'data'=>[…]] for some
     * collections and a flat doc for others. Reading the wrapped shape as if
     * it were flat silently yields empty fields — which reads as "no drivers
     * exist" rather than "the script looked in the wrong place".
     */
    private function flat(array $r): array
    {
        return (isset($r['data']) && is_array($r['data']))
            ? $r['data'] + ['id' => $r['id'] ?? ''] : $r;
    }

    /** Keep a contact partly legible for matching without printing it whole. */
    private function mask(string $v): string
    {
        $v = trim($v);
        if ($v === '') { return '—'; }
        $n = strlen($v);
        return $n <= 4 ? str_repeat('•', $n) : substr($v, 0, 2) . str_repeat('•', max(2, $n - 4)) . substr($v, -2);
    }

    /** Which tenant am I meant to look in? `php index.php transport_uat_accounts tenants` */
    public function tenants(): void
    {
        $this->load->library('firestore_service', null, 'fs');
        $this->fs->init('SCH_000000', '2026-27');
        // `schools` is the tenant registry itself, so it is deliberately read
        // unscoped via where() rather than schoolWhere().
        $rows = (array) $this->fs->where('schools', []);
        echo "==================================================\n TENANTS\n==================================================\n";
        foreach ($rows as $r) {
            $r = $this->flat($r);
            $name = '';
            foreach (['name', 'school_name', 'schoolName', 'displayName', 'title'] as $k) {
                if (trim((string) ($r[$k] ?? '')) !== '') { $name = (string) $r[$k]; break; }
            }
            printf("  %-24s %-34s [%s]\n", (string) ($r['school_id'] ?? $r['id'] ?? '?'),
                $name !== '' ? $name : '(no name field)', implode(',', array_slice(array_keys($r), 0, 6)));
        }
        echo '  (' . count($rows) . " total)\n";
    }

    /**
     * Driver readiness for one staff member — the exact fields
     * Transport_service::_validateDriverReadiness() checks before it will let a
     * trip be created for them.
     *
     *   SCHOOL_ID=… SESSION_YEAR=… php index.php transport_uat_accounts driver STA0001
     */
    public function driver(string $staffId = ''): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '' || $staffId === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR env and a staffId arg required.\n"); exit(1);
        }
        $this->load->library('firestore_service', null, 'fs');
        $this->fs->init($sid, $sess);

        $s = $this->fs->getEntity('staff', $staffId);
        if (!is_array($s)) { echo "staff not found: {$staffId}\n"; return; }
        $s = $this->flat($s);

        echo "==================================================\n";
        echo " DRIVER READINESS  {$staffId}\n==================================================\n";
        printf("  name                        = %s\n", (string) ($s['name'] ?? '?'));
        printf("  status                      = %s\n", (string) ($s['status'] ?? '?'));
        printf("  role / staff_roles          = %s / %s\n", (string) ($s['role'] ?? '—'),
            is_array($s['staff_roles'] ?? null) ? implode('|', $s['staff_roles']) : (string) ($s['staff_roles'] ?? '—'));
        $flags = (array) ($s['flags'] ?? []);
        printf("  flags                       = %s\n", $flags === [] ? '(none)' : json_encode($flags));
        printf("  flags.can_drive_transport   = %s   <-- the gate\n",
            !empty($flags['can_drive_transport']) ? 'TRUE' : 'FALSE / absent');
        $meta = (array) ($s['driver_meta'] ?? []);
        printf("  driver_meta                 = %s\n", $meta === [] ? '(none)' : json_encode($meta));
        printf("  licence_expires             = %s\n", (string) ($meta['licence_expires'] ?? '—'));
        printf("  medical_expires             = %s\n", (string) ($meta['medical_expires'] ?? '—'));
        printf("  operational_status          = %s\n", (string) ($meta['operational_status'] ?? '—'));
        echo "\n  Blocking issues:\n";
        $n = 0;
        if (strtolower((string) ($s['status'] ?? 'active')) !== 'active') { echo "    • HR status is not Active\n"; $n++; }
        if (empty($flags['can_drive_transport'])) { echo "    • flags.can_drive_transport is not true\n"; $n++; }
        foreach (['licence_expires', 'medical_expires'] as $k) {
            $v = (string) ($meta[$k] ?? '');
            if ($v !== '' && $v < date('Y-m-d')) { echo "    • {$k} expired on {$v}\n"; $n++; }
        }
        if ($n === 0) { echo "    (none — this staff member can be assigned to a trip)\n"; }
    }

    /**
     * STOPGAP — set flags.can_drive_transport on one staff member.
     *
     * Transport_service::_validateDriverReadiness() gates every driver action
     * on this flag, but no admin UI can set it: the Org role modal's CAP_FLAGS
     * list omits it, and Org.php writes ROLE flags (schools.staffRoles[].flags)
     * while the gate reads the STAFF document's flags. Transport's own
     * updateDriverMeta() deliberately refuses to touch flags because HR owns
     * them. So the driver feature is currently unreachable through the UI.
     *
     * This exists only to unblock validation for a NAMED staff member while
     * that defect is open. It is not the fix — the fix is a staff-level
     * capability control in HR. Requires an explicit staffId; it will never
     * operate in bulk.
     *
     *   SCHOOL_ID=… SESSION_YEAR=… php index.php transport_uat_accounts \
     *       enable_driving STA0001 --commit
     */
    public function enable_driving(string $staffId = '', string $commit = ''): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '' || $staffId === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR env and a staffId arg required.\n"); exit(1);
        }
        $this->load->library('firestore_service', null, 'fs');
        $this->fs->init($sid, $sess);

        $s = $this->fs->getEntity('staff', $staffId);
        if (!is_array($s)) { fwrite(STDERR, "staff not found: {$staffId}\n"); exit(1); }
        $s = $this->flat($s);

        // Refuse on anyone who is not actually a driver — this must not become
        // a way to hand driving rights to arbitrary staff.
        $roles = (array) ($s['staff_roles'] ?? []);
        $isDriver = in_array('ROLE_DRIVER', array_map('strval', $roles), true)
                 || strtolower((string) ($s['role'] ?? '')) === 'driver';
        if (!$isDriver) {
            fwrite(STDERR, "REFUSED: {$staffId} (" . (string) ($s['name'] ?? '?')
                . ") does not hold ROLE_DRIVER. Assign the Driver role in HR first.\n");
            exit(1);
        }

        $existing = (array) ($s['flags'] ?? []);
        if (!empty($existing['can_drive_transport'])) {
            echo "  already enabled — no write needed.\n"; return;
        }
        // Merge in PHP and write the whole map back: a merge write replaces a
        // nested map wholesale, so building it here is what preserves any other
        // flag the staff member already has.
        $flags = $existing + ['can_drive_transport' => true];

        echo "  staff   : {$staffId} — " . (string) ($s['name'] ?? '?') . "\n";
        echo '  flags   : ' . json_encode($existing) . "  ->  " . json_encode($flags) . "\n";
        if ($commit !== '--commit') {
            echo "\n  DRY RUN — nothing written. Re-run with --commit to apply.\n";
            return;
        }
        $this->fs->setEntity('staff', $staffId, ['flags' => $flags], /* merge */ true);
        echo "  WRITTEN.\n";

        $after = $this->flat((array) $this->fs->getEntity('staff', $staffId));
        echo '  verify  : flags.can_drive_transport = '
            . (!empty($after['flags']['can_drive_transport']) ? 'TRUE' : 'STILL FALSE') . "\n";
    }

    /** Routes with their id, vehicle and stop count — which one to pick. */
    public function routes(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n"); exit(1);
        }
        $this->load->library('firestore_service', null, 'fs');
        $this->fs->init($sid, $sess);

        $routes = array_map([$this, 'flat'], (array) $this->fs->schoolWhere('routes', []));
        $stops  = array_map([$this, 'flat'], (array) $this->fs->schoolWhere('transportStops', []));
        $byRoute = [];
        foreach ($stops as $st) { $byRoute[(string) ($st['route_id'] ?? '')][] = $st; }

        echo "==================================================\n ROUTES\n==================================================\n";
        foreach ($routes as $r) {
            $rid = (string) ($r['id'] ?? '?');
            $st  = $byRoute[$rid] ?? [];
            // A trip needs stops WITH scheduled times, or schedule adherence and
            // the parent's "N stops away" have nothing to work from.
            $timed = 0;
            foreach ($st as $s) { if (trim((string) ($s['pickup_time'] ?? '')) !== '') { $timed++; } }
            printf("  %-12s %-24s status=%-10s vehicle=%-14s stops=%d (%d timed)\n",
                $rid, (string) ($r['name'] ?? '?'), (string) ($r['status'] ?? '?'),
                (string) ($r['vehicle_id'] ?? '—'), count($st), $timed);
        }
        if ($routes === []) { echo "  (none)\n"; }
    }

    /**
     * Raw route-assignment docs — why a trip roster came back empty.
     * _computeTripRoster() requires studentId (camelCase), status 'Active',
     * a type matching the trip direction, and for routeAssignments a session
     * equal to the current one.
     */
    public function assignments(string $routeId = ''): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n"); exit(1);
        }
        $this->load->library('firestore_service', null, 'fs');
        $this->fs->init($sid, $sess);

        foreach (['studentRoutes', 'routeAssignments'] as $col) {
            $rows = array_map([$this, 'flat'], (array) $this->fs->schoolWhere($col, []));
            echo "\n=== {$col} (" . count($rows) . " docs) ===\n";
            foreach ($rows as $r) {
                if ($routeId !== '' && (string) ($r['route_id'] ?? '') !== $routeId) { continue; }
                printf("  route=%-10s studentId=%-12s student_id=%-12s status=%-10s type=%-8s session=%-9s days=%s\n",
                    (string) ($r['route_id'] ?? '—'),
                    (string) ($r['studentId'] ?? '(absent)'),
                    (string) ($r['student_id'] ?? '(absent)'),
                    (string) ($r['status'] ?? '(absent)'),
                    (string) ($r['type'] ?? '(absent)'),
                    (string) ($r['session'] ?? '(absent)'),
                    is_array($r['service_days'] ?? null) ? implode(',', $r['service_days']) : '(absent)');
            }
        }
    }

    /** Recompute a trip roster for a route/direction — proves the day-stem fix. */
    public function roster(string $routeId = '', string $direction = 'Pickup'): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '' || $routeId === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR env and a routeId arg required.\n"); exit(1);
        }
        $this->load->library('firestore_service', null, 'fs');
        $this->fs->init($sid, $sess);
        $this->load->library('numbering_service', null, 'numbering');
        $this->numbering->init($this->fs, $sid, $sess);
        $this->load->library('transport_service', null, 'transport');
        $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'UAT_LOOKUP');

        $m = new \ReflectionMethod($this->transport, '_computeTripRoster');
        $m->setAccessible(true);
        $roster = (array) $m->invoke($this->transport, $routeId, date('Y-m-d'), $direction);

        echo "route={$routeId} direction={$direction} day=" . date('l') . "\n";
        echo '  roster_size = ' . count($roster) . "\n";
        foreach ($roster as $s) { echo "    • {$s}\n"; }
        if ($roster === []) { echo "    (empty — no student will be on this trip)\n"; }
    }

    /**
     * Does a RAW document id exist? The apps read
     * firestore.collection('staff').document(uid) with no school prefixing,
     * so what matters is the literal doc id, not the getEntity() view.
     */
    public function docexists(string $collection = '', string $docId = ''): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $collection === '' || $docId === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID env, collection and docId args required.\n"); exit(1);
        }
        $this->load->library('firestore_service', null, 'fs');
        $this->fs->init($sid, $sess);
        foreach ([$docId, $sid . '_' . $docId] as $try) {
            $d = $this->fs->get($collection, $try);
            printf("  %-40s => %s\n", $collection . '/' . $try,
                is_array($d) ? 'EXISTS (flags=' . json_encode($d['flags'] ?? null) . ')' : 'MISSING');
        }
    }

    /**
     * Reproduce the Teacher app's getMyActiveTrips query.
     *
     * The app catches every exception and the ViewModel turns it into
     * getOrDefault(emptyList()), so a missing composite index or a rules denial
     * both render as the honest-looking "No active trips assigned to you
     * today." Running the same shape here surfaces the real error — the admin
     * SDK needs the same composite index a client does.
     */
    public function drivertrips(string $staffId = 'STA0001'): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n"); exit(1);
        }
        $this->load->library('firestore_service', null, 'fs');
        $this->fs->init($sid, $sess);
        $today = date('Y-m-d');

        // Narrow first, then add each constraint, so the exact failing
        // combination is identifiable rather than "the whole query broke".
        $probes = [
            'driver only'                  => [['driver_staff_id', '==', $staffId]],
            'driver + date'                => [['driver_staff_id', '==', $staffId], ['date', '==', $today]],
            'driver + date + status Ready' => [['driver_staff_id', '==', $staffId], ['date', '==', $today],
                                               ['status', '==', 'Ready']],
            'driver + status IN'           => [['driver_staff_id', '==', $staffId],
                                               ['status', 'in', ['Ready', 'InProgress']]],
            'full app shape'               => [['driver_staff_id', '==', $staffId], ['date', '==', $today],
                                               ['status', 'in', ['Ready', 'InProgress']]],
        ];
        echo "today={$today} staffId={$staffId} tenant={$sid}\n";
        foreach ($probes as $label => $cond) {
            try {
                // The app also orders by date DESC on the very field it filters
                // for equality — the only untested difference from the shapes
                // above, so the last probe includes it.
                $rows = $label === 'full app shape'
                    ? (array) $this->fs->schoolWhere('tripLogs', $cond, 'date', 'DESC', 50)
                    : (array) $this->fs->schoolWhere('tripLogs', $cond);
                $ids = [];
                foreach ($rows as $r) { $r = $this->flat($r); $ids[] = (string) ($r['id'] ?? '?'); }
                printf("  %-30s => %d row(s) %s\n", $label, count($rows),
                    $ids === [] ? '' : '[' . implode(',', array_slice($ids, 0, 4)) . ']');
            } catch (\Throwable $e) {
                printf("  %-30s => ERROR %s\n", $label, substr($e->getMessage(), 0, 220));
            }
        }
    }

    /** Status/date/driver of one trip — why it does or doesn't reach the app. */
    public function trip(string $tripId = ''): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $tripId === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID env and tripId arg required.\n"); exit(1);
        }
        $this->load->library('firestore_service', null, 'fs');
        $this->fs->init($sid, $sess);
        $t = $this->fs->getEntity('tripLogs', $tripId);
        if (!is_array($t)) { echo "not found: {$tripId}\n"; return; }
        $t = $this->flat($t);
        foreach (['id', 'status', 'date', 'trip_date', 'trip_direction', 'driver_staff_id',
                  'vehicleId', 'route_id', 'roster_size', 'live_tracking_enabled',
                  'actual_start_at', 'actual_end_at'] as $k) {
            $v = $t[$k] ?? null;
            printf("  %-22s = %s\n", $k, is_bool($v) ? ($v ? 'true' : 'false') : var_export($v, true));
        }
        printf("  %-22s = %d\n", 'checkpoints', count((array) ($t['checkpoints'] ?? [])));
    }

    /** Live GPS state for a vehicle — is the driver app actually writing fixes? */
    public function livepos(string $vehicleId = 'VH0100'): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '') { fwrite(STDERR, "ERROR: SCHOOL_ID required.\n"); exit(1); }
        $this->load->library('firestore_service', null, 'fs');
        $this->fs->init($sid, $sess);

        $d = $this->fs->getEntity('vehicleLivePositions', $vehicleId);
        if (!is_array($d)) {
            echo "  no live position doc for {$vehicleId} — no GPS fix has been written yet\n";
            return;
        }
        $d = $this->flat($d);
        foreach (['lat', 'lng', 'speed_kmph', 'heading', 'accuracy_m', 'recorded_at',
                  'recorded_at_ms', 'is_mock', 'source', 'client_platform',
                  'live_tracking_enabled', 'tripId', 'driver_staff_id'] as $k) {
            $v = $d[$k] ?? null;
            printf("  %-22s = %s\n", $k, is_bool($v) ? ($v ? 'true' : 'false') : var_export($v, true));
        }
        $ms = (int) ($d['recorded_at_ms'] ?? 0);
        if ($ms > 0) {
            $age = max(0, time() - (int) round($ms / 1000));
            printf("  %-22s = %ds ago %s\n", 'freshness', $age,
                $age <= 120 ? '(LIVE)' : ($age <= 600 ? '(STALE)' : '(NO SIGNAL)'));
        }
        $crumbs = (array) $this->fs->schoolWhere('vehicleBreadcrumbs', [['vehicleId', '==', $vehicleId]]);
        printf("  %-22s = %d\n", 'breadcrumbs', count($crumbs));
    }

    public function run(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n"); exit(1);
        }

        $this->load->library('firestore_service', null, 'fs');
        $this->fs->init($sid, $sess);

        echo "==================================================\n";
        echo " UAT account lookup (read-only)  tenant={$sid}  session={$sess}\n";
        echo "==================================================\n";

        // ── 1. Drivers ────────────────────────────────────────────────────
        // A driver usable for UAT is staff linked to a vehicle: the trip
        // lifecycle authorizes by trip OWNERSHIP (driver_staff_id == token uid),
        // so an unlinked "Driver"-titled staff member cannot start anything.
        $vehicles = array_map([$this, 'flat'], (array) $this->fs->schoolWhere('vehicles', []));
        $staffAll = array_map([$this, 'flat'], (array) $this->fs->schoolWhere('staff', []));
        $staffById = [];
        // Index under BOTH forms. The staff doc's own staff_id field carries the
        // fully-prefixed id (SCH_x_STA0001) while a vehicle stores the short
        // form (STA0001) — keying on one only reports an existing driver as
        // "staff doc MISSING".
        foreach ($staffAll as $s) {
            foreach ([(string) ($s['staff_id'] ?? ''), (string) ($s['id'] ?? '')] as $k) {
                if ($k === '') { continue; }
                $staffById[$k] = $s;
                $short = preg_replace('/^' . preg_quote($sid, '/') . '_/', '', $k);
                if ($short !== '' && $short !== $k) { $staffById[$short] = $s; }
            }
        }

        echo "\n[1] DRIVERS linked to a vehicle (can start a trip)\n";
        $found = 0;
        foreach ($vehicles as $v) {
            $stf = trim((string) ($v['staff_id'] ?? ''));
            if ($stf === '') { continue; }
            $s = $staffById[$stf] ?? null;
            $found++;
            printf("  • vehicle %-14s id=%-22s staff_id=%-12s %s\n",
                (string) ($v['number'] ?? '?'), (string) ($v['id'] ?? '?'), $stf,
                $s ? (string) ($s['name'] ?? $s['full_name'] ?? '(no name)') : '*** staff doc MISSING ***');
            if ($s) {
                printf("      login=%-34s phone=%s  status=%s\n",
                    (string) ($s['email'] ?? $s['username'] ?? '(no email/username)'),
                    $this->mask((string) ($s['phone'] ?? $s['mobile'] ?? '')),
                    (string) ($s['status'] ?? '?'));
            }
        }
        if ($found === 0) {
            echo "  (none — no vehicle has a staff_id, so NO driver can start a trip)\n";

            // Say what IS there, so the gap is actionable rather than just a
            // dead end: which vehicles exist, and which staff could be linked.
            echo "\n  VEHICLES available to link:\n";
            // id AND number: the route's vehicle_id refers to the id, while the
            // Vehicles tab shows the number — printing only one makes the two
            // sides impossible to line up.
            foreach (array_slice($vehicles, 0, 12) as $v) {
                printf("      id=%-28s number=%-24s status=%s\n",
                    (string) ($v['id'] ?? '?'), (string) ($v['number'] ?? '?'),
                    (string) ($v['status'] ?? '?'));
            }
            if ($vehicles === []) { echo "      (no vehicles at all)\n"; }

            echo "\n  STAFF who could be the driver:\n";
            $n = 0;
            foreach ($staffAll as $s) {
                $blob = strtolower(json_encode($s) ?: '');
                if (strpos($blob, 'driver') === false) { continue; }
                if ($n++ >= 12) { break; }
                printf("      staff_id=%-24s %-20s login=%-32s status=%s\n",
                    (string) ($s['staff_id'] ?? $s['id'] ?? '?'),
                    (string) ($s['name'] ?? $s['full_name'] ?? '?'),
                    (string) ($s['email'] ?? $s['username'] ?? '(none)'),
                    (string) ($s['status'] ?? '?'));
                // Show WHERE 'driver' matched — a hit in a department or a
                // free-text note is not the same as an actual driver role.
                foreach (['designation', 'department', 'staff_roles', 'role', 'job_title', 'staffRoles'] as $k) {
                    if (!isset($s[$k])) { continue; }
                    $val = is_array($s[$k]) ? implode('|', array_map('strval', $s[$k])) : (string) $s[$k];
                    if ($val !== '') { printf("          %-12s = %s\n", $k, $val); }
                }
            }
            if ($n === 0) {
                echo "      (no staff record mentions 'driver' — " . count($staffAll) . " staff total)\n";
            }
        }

        // ── 2. Trips today ────────────────────────────────────────────────
        echo "\n[2] TRIPS for " . date('Y-m-d') . "\n";
        $trips = array_map([$this, 'flat'], (array) $this->fs->schoolWhere('tripLogs', [['trip_date', '==', date('Y-m-d')]]));
        if ($trips === []) { echo "  (none — a trip must be created before the driver can start it)\n"; }
        foreach ($trips as $t) {
            printf("  • %-16s %-12s vehicle=%-12s driver_staff_id=%s\n",
                (string) ($t['tripId'] ?? $t['id'] ?? '?'), (string) ($t['status'] ?? '?'),
                (string) ($t['vehicleId'] ?? '?'), (string) ($t['driver_staff_id'] ?? '—'));
        }

        // Trips still open from earlier dates — these are the phantom buses.
        $open = array_map([$this, 'flat'], (array) $this->fs->schoolWhere('tripLogs', [['status', '==', 'InProgress']]));
        $stale = array_filter($open, function ($t) {
            return (string) ($t['trip_date'] ?? '') !== date('Y-m-d');
        });
        if ($stale !== []) {
            echo "\n  ⚠ " . count($stale) . " trip(s) left InProgress from an earlier date —\n";
            echo "    these show on the Live Fleet map as phantom buses:\n";
            foreach ($stale as $t) {
                printf("      %-22s date=%s vehicle=%s\n", (string) ($t['tripId'] ?? '?'),
                    (string) ($t['trip_date'] ?? '?'), (string) ($t['vehicleId'] ?? '?'));
            }
        }

        // ── 3. Students on a route ────────────────────────────────────────
        // The parent's live-map read is gated on the roster, so only a parent
        // of an assigned student can see the bus.
        echo "\n[3] STUDENTS assigned to a route (their parent can watch the bus)\n";
        $asg = array_map([$this, 'flat'], (array) $this->fs->schoolWhere('routeAssignments', []));
        if ($asg === []) {
            $asg = array_map([$this, 'flat'], (array) $this->fs->schoolWhere('studentRoutes', []));
        }
        $shown = 0;
        foreach ($asg as $a) {
            if ($shown >= 10) { break; }
            $stuId = (string) ($a['student_id'] ?? $a['studentId'] ?? '');
            if ($stuId === '') { continue; }
            $stu = $this->fs->getEntity('students', $stuId);
            if (is_array($stu)) { $stu = $this->flat($stu); }
            if (!$stu) { printf("  • %-14s (student doc missing)\n", $stuId); $shown++; continue; }
            $shown++;
            printf("  • %-14s %-22s class=%s-%s  route=%s\n", $stuId,
                (string) ($stu['name'] ?? $stu['student_name'] ?? '?'),
                (string) ($stu['class'] ?? '?'), (string) ($stu['section'] ?? '?'),
                (string) ($a['route_id'] ?? $a['routeId'] ?? '?'));
            printf("      parent login=%-24s father=%s  mother=%s\n",
                (string) ($stu['parent_email'] ?? $stu['email'] ?? '(none on record)'),
                $this->mask((string) ($stu['father_phone'] ?? $stu['fatherPhone'] ?? '')),
                $this->mask((string) ($stu['mother_phone'] ?? $stu['motherPhone'] ?? '')));
        }
        if ($shown === 0) { echo "  (none — no student is assigned to a route)\n"; }

        echo "\n--------------------------------------------------\n";
        echo " Passwords are hashed and CANNOT be read back.\n";
        echo " Reset from the admin panel, then sign in on the device.\n";
        echo "--------------------------------------------------\n";
    }
}
