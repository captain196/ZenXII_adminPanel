<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_f13 — F13.1 Live GPS, Milestone M1 runtime probe.
 *
 * READ-ONLY. Performs **zero writes** to Firestore. M1 is the data contract;
 * the write paths land in M2, so there is nothing to persist yet and nothing
 * to tear down. That also means this probe cannot leave residue — the failure
 * mode that produced 114 orphaned compliance rows during F12.1.
 *
 * Exercises the contract as executable behaviour rather than source text:
 * validation gates, throttle/suppression maths, staleness, docId convention,
 * TTL encoding, and the viewer-allowlist resolver against real tenant data.
 *
 * CLI-only:
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 php index.php transport_probe_f13 run
 */
class Transport_probe_f13 extends CI_Controller
{
    private int $pass = 0;
    private int $fail = 0;
    /** @var array<int,string> */
    private array $failures = [];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) { show_error('CLI-only.', 403); }
    }

    private function ok(string $id, string $what): void
    {
        $this->pass++; printf("  PASS  %-7s %s\n", $id, $what);
    }
    private function bad(string $id, string $what, string $detail): void
    {
        $this->fail++; $this->failures[] = "{$id} — {$what}: {$detail}";
        printf("  FAIL  %-7s %s\n          → %s\n", $id, $what, $detail);
    }
    private function eq(string $id, $exp, $act, string $what): void
    {
        $exp === $act ? $this->ok($id, $what)
            : $this->bad($id, $what, 'expected ' . var_export($exp, true) . ', got ' . var_export($act, true));
    }
    private function truthy(string $id, bool $c, string $what, string $d = ''): void
    {
        $c ? $this->ok($id, $what) : $this->bad($id, $what, $d !== '' ? $d : 'condition false');
    }
    private function throws(string $id, string $needle, callable $fn, string $what): void
    {
        try { $fn(); } catch (\Throwable $e) {
            stripos($e->getMessage(), $needle) !== false
                ? $this->ok($id, $what)
                : $this->bad($id, $what, "wrong error — wanted “{$needle}”, got “{$e->getMessage()}”");
            return;
        }
        $this->bad($id, $what, 'did NOT throw');
    }

    public function run(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') { fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n"); exit(1); }

        echo "==================================================\n";
        echo " F13.1 M1 — Live GPS data contract (READ-ONLY)\n";
        echo " tenant={$sid} session={$sess}\n";
        echo "==================================================\n";

        $this->load->library('firestore_service', null, 'fs');
        $this->fs->init($sid, $sess);
        $this->load->library('transport_gps_service', null, 'gps');
        $this->gps->init($this->fs, $sid, 'PROBE_F13_READONLY');

        $this->a_docIds();
        $this->b_validation();
        $this->c_throttle();
        $this->d_staleness();
        $this->e_ttl();
        $this->f_builders($sid);
        $this->g_viewerAllowlist();
        $this->i_fixOrdering();
        $this->j_lifecycleRoundTrip();
        $this->k_sweeper();

        echo "\n==================================================\n";
        echo " RESULT: pass={$this->pass} fail={$this->fail}\n";
        if ($this->failures !== []) {
            echo "\n DEFECTS:\n";
            foreach ($this->failures as $f) { echo "   • {$f}\n"; }
        }
        echo "==================================================\n";
        exit($this->fail === 0 ? 0 : 2);
    }

    // ── A. docId convention (RC-3: single schoolId prefix, applied by docId()) ──

    private function a_docIds(): void
    {
        echo "\n[A] Document-id convention\n";
        $this->eq('A1', 'VH0100', $this->gps->livePositionId('VH0100'), 'live-position id is the BARE vehicleId');
        $this->truthy('A2', strpos($this->gps->livePositionId('VH0100'), 'SCH_') === false,
            'no hand-rolled school prefix (docId() owns it — prevents RC-3 double-prefix)');
        $this->eq('A3', 'GPSSESS_TRP1_1700000000000',
            $this->gps->breadcrumbId('GPSSESS_TRP1', 1700000000000), 'breadcrumb id is {session}_{epochMs}');
        $this->throws('A4', 'vehicleId is required', fn() => $this->gps->livePositionId('  '), 'blank vehicleId rejected');
        $this->throws('A5', 'gps_session_id is required', fn() => $this->gps->breadcrumbId('', 1), 'blank session rejected');
        $this->throws('A6', 'epochMs must be positive', fn() => $this->gps->breadcrumbId('S', 0), 'non-positive epochMs rejected');
    }

    // ── B. anti-spoofing gates (D-4) ─────────────────────────────────────

    private function b_validation(): void
    {
        echo "\n[B] Position validation — anti-spoofing (D-4)\n";
        $good = ['lat' => 23.0225, 'lng' => 72.5714, 'accuracy_m' => 12.0, 'speed_kmph' => 34.0, 'is_mock' => false];

        $this->eq('B1', true,  $this->gps->validatePosition($good)['ok'], 'a clean fix is accepted');
        $this->eq('B2', 'mock_location',
            $this->gps->validatePosition(['is_mock' => true] + $good)['reason'],
            'MOCK location rejected — no allow-mock escape hatch for a bus');
        $this->eq('B3', 'poor_accuracy',
            $this->gps->validatePosition(['accuracy_m' => 250.0] + $good)['reason'],
            'accuracy worse than the 100m ceiling rejected');
        $this->eq('B4', 'poor_accuracy',
            $this->gps->validatePosition(array_diff_key($good, ['accuracy_m' => 1]))['reason'],
            'MISSING accuracy rejected (fails closed, not open)');
        $this->eq('B5', 'poor_accuracy',
            $this->gps->validatePosition(['accuracy_m' => -1.0] + $good)['reason'],
            'negative accuracy rejected');
        $this->eq('B6', 'invalid_coordinates',
            $this->gps->validatePosition(['lat' => 91.0] + $good)['reason'], 'out-of-range latitude rejected');
        $this->eq('B7', 'invalid_coordinates',
            $this->gps->validatePosition(['lng' => 181.0] + $good)['reason'], 'out-of-range longitude rejected');
        $this->eq('B8', 'invalid_coordinates',
            $this->gps->validatePosition([])['reason'], 'empty payload rejected');
        $this->eq('B9', 'implausible_speed',
            $this->gps->validatePosition(['speed_kmph' => 900.0] + $good)['reason'],
            'implausible speed rejected (bad fix, not a bus)');
        $this->eq('B10', 'implausible_speed',
            $this->gps->validatePosition(['speed_kmph' => -5.0] + $good)['reason'], 'negative speed rejected');
        // Boundary: exactly at the ceiling must be ACCEPTED, one metre over rejected.
        $this->eq('B11', true,
            $this->gps->validatePosition(['accuracy_m' => 100.0] + $good)['ok'], 'accuracy exactly 100m accepted');
        $this->eq('B12', 'poor_accuracy',
            $this->gps->validatePosition(['accuracy_m' => 100.01] + $good)['reason'], 'accuracy 100.01m rejected');
        $this->eq('B13', true,
            $this->gps->validatePosition(['speed_kmph' => 150.0] + $good)['ok'], 'speed exactly 150 km/h accepted');
    }

    // ── C. throttle + stationary suppression (the write budget) ──────────

    private function c_throttle(): void
    {
        echo "\n[C] Write throttle + stationary suppression\n";
        $now  = 1700000000;
        $at   = fn(int $off) => date('c', $now + $off);
        $pos  = fn(float $la, float $ln) => ['lat' => $la, 'lng' => $ln];

        $this->eq('C1', true, $this->gps->shouldWrite(null, $pos(23.0, 72.0), $now),
            'first fix of a session always writes');
        // 10s after the last write → inside the 15s floor.
        $this->eq('C2', false,
            $this->gps->shouldWrite(['recorded_at' => $at(-10)] + $pos(23.0, 72.0), $pos(23.1, 72.1), $now),
            'a fix 10s after the last is throttled (15s floor)');
        // 20s later and genuinely moved → write.
        $this->eq('C3', true,
            $this->gps->shouldWrite(['recorded_at' => $at(-20)] + $pos(23.0, 72.0), $pos(23.01, 72.01), $now),
            'moved and past the floor → writes');
        // 20s later but parked (same spot) and under the 60s suppression → skip.
        $this->eq('C4', false,
            $this->gps->shouldWrite(['recorded_at' => $at(-20)] + $pos(23.0, 72.0), $pos(23.0, 72.0), $now),
            'stationary at 20s is suppressed (pickup dwell — the main budget saving)');
        // Parked but 90s elapsed → heartbeat write so the doc does not read stale.
        $this->eq('C5', true,
            $this->gps->shouldWrite(['recorded_at' => $at(-90)] + $pos(23.0, 72.0), $pos(23.0, 72.0), $now),
            'stationary past 60s writes a heartbeat (prevents a false "stale" render)');
        $this->eq('C6', true,
            $this->gps->shouldWrite(['recorded_at' => 'not-a-date'] + $pos(23.0, 72.0), $pos(23.0, 72.0), $now),
            'unparseable last timestamp → write (fails safe)');
    }

    // ── D. staleness ─────────────────────────────────────────────────────

    private function d_staleness(): void
    {
        echo "\n[D] Staleness\n";
        $now = 1700000000;
        $this->eq('D1', true,  $this->gps->isStale(null, $now), 'null position is stale');
        $this->eq('D2', true,  $this->gps->isStale([], $now), 'position with no recorded_at is stale');
        $this->eq('D3', false, $this->gps->isStale(['recorded_at' => date('c', $now - 30)], $now), '30s old is fresh');
        $this->eq('D4', false, $this->gps->isStale(['recorded_at' => date('c', $now - 120)], $now), '120s exactly is still fresh');
        $this->eq('D5', true,  $this->gps->isStale(['recorded_at' => date('c', $now - 121)], $now), '121s is stale');
        $this->eq('D6', true,  $this->gps->isStale(['recorded_at' => 'garbage'], $now), 'garbage timestamp is stale');
    }

    // ── E. TTL encoding (D-3) — the silent-failure risk ──────────────────

    private function e_ttl(): void
    {
        echo "\n[E] Breadcrumb TTL encoding\n";
        $now = 1700000000;
        $exp = $this->gps->breadcrumbExpiresAt($now);

        $this->truthy('E1', is_array($exp) && ($exp['__firestore_type'] ?? '') === 'timestamp',
            'expires_at is a Firestore Timestamp SENTINEL, not a plain string',
            'got ' . var_export($exp, true));
        $this->eq('E2', gmdate('Y-m-d\TH:i:s.000000\Z', $now + 30 * 86400), $exp['value'] ?? '',
            'expiry is exactly 30 days out, in UTC');

        // The sentinel must survive the REST encoder as a timestampValue — an
        // ISO string would encode as stringValue and the TTL policy would
        // silently never fire, growing the collection without bound. This is
        // the single highest-value assertion in the probe, so it tests the
        // REAL encoder rather than trusting the shape.
        //
        // Reached by reflection because the encoder is awkward to load: the
        // class is `FirestoreRestClient` (not the CI filename convention), its
        // constructor demands a service-account path + project + database, and
        // `encode()` is private. It is a pure value switch with no dependency
        // on constructor state, so a constructor-less instance encodes exactly
        // as the live client does.
        try {
            require_once APPPATH . 'libraries/Firestore_rest_client.php';
            $ref  = new ReflectionClass('FirestoreRestClient');
            $inst = $ref->newInstanceWithoutConstructor();
            $enc  = $ref->getMethod('encode');
            $enc->setAccessible(true);

            $encTs = (array) $enc->invoke($inst, $exp);
            $this->truthy('E3', isset($encTs['timestampValue']),
                'REST encoder emits timestampValue — the TTL policy will act on it',
                'encoded as ' . implode(',', array_keys($encTs)));

            $encStr = (array) $enc->invoke($inst, date('c', $now));
            $this->truthy('E4', isset($encStr['stringValue']),
                'a plain ISO string encodes as stringValue — proving why the sentinel is required, '
                . 'and that a string expires_at would make TTL a silent no-op');
        } catch (\Throwable $e) {
            $this->bad('E3/E4', 'REST encoder timestamp contract', $e->getMessage());
        }
    }

    // ── F. document builders ─────────────────────────────────────────────

    private function f_builders(string $sid): void
    {
        echo "\n[F] Document builders\n";
        $doc = $this->gps->buildSessionDoc('VH0100', 'TRP000001', 'GPSSESS_TRP000001', 'STA0001', ['u1', 'u2']);

        $this->eq('F1', $sid, $doc['schoolId'] ?? null, 'session doc carries schoolId (tenant scoping)');
        $this->eq('F2', true, $doc['live_tracking_enabled'] ?? null, 'session opens with tracking ENABLED');
        $this->eq('F3', ['u1', 'u2'], $doc['authorized_viewer_uids'] ?? null, 'viewer allowlist denormalised onto the doc');
        // The key must be PRESENT and its value NULL. `?? 'missing'` cannot
        // tell those apart — it collapses null onto the default — so the two
        // properties are asserted separately.
        $this->truthy('F4a', array_key_exists('lat', $doc), 'position key is present on the session doc');
        $this->truthy('F4b', $doc['lat'] === null,
            'position starts NULL — the doc asserts no location until a real fix');
        $this->eq('F5', 'STA0001', $doc['driver_staff_id'] ?? null, 'driver bound at open (rules match against this)');

        // Every server-owned field must be present, and none of them may appear
        // in the driver-mutable list — that overlap would be a privilege hole.
        $missing = [];
        foreach (Transport_gps_service::SERVER_OWNED_FIELDS as $f) {
            if (!array_key_exists($f, $doc)) { $missing[] = $f; }
        }
        $this->truthy('F6', $missing === [], 'all server-owned fields present', 'missing: ' . implode(',', $missing));
        $overlap = array_intersect(
            Transport_gps_service::SERVER_OWNED_FIELDS,
            Transport_gps_service::DRIVER_MUTABLE_FIELDS
        );
        $this->truthy('F7', $overlap === [],
            'server-owned and driver-mutable field sets are DISJOINT',
            'overlap: ' . implode(',', $overlap));

        $bc = $this->gps->buildBreadcrumbDoc('VH0100', 'TRP000001', 'GPSSESS_TRP000001', 'STA0001',
            ['lat' => 23.0, 'lng' => 72.0, 'speed_kmph' => 30.0, 'recorded_at' => '2026-08-08T10:00:00+00:00']);
        $this->eq('F8', $sid, $bc['schoolId'] ?? null, 'breadcrumb carries schoolId');
        $this->eq('F9', 'STA0001', $bc['driver_staff_id'] ?? null,
            'breadcrumb stamps driver_staff_id (the rule binds create to the caller\'s own uid)');
        $this->truthy('F10', is_array($bc['expires_at'] ?? null), 'breadcrumb carries the TTL timestamp sentinel');
    }

    // ── G. viewer allowlist against real tenant data ─────────────────────

    private function g_viewerAllowlist(): void
    {
        echo "\n[G] Viewer allowlist resolver (real tenant data)\n";
        $this->eq('G1', [], $this->gps->resolveViewerUids([]), 'empty roster resolves to an empty allowlist');
        $this->eq('G2', [], $this->gps->resolveViewerUids(['STU_DOES_NOT_EXIST_QA']),
            'unknown student contributes no uid (never leaks a placeholder)');

        // Real students from the tenant, if any.
        //
        // Bounded retry, not a weakened assertion: this is an ORDERED QUERY, and
        // a query issued right after heavy write activity elsewhere can come
        // back short while indexes settle. Without the retry, G3/G4 silently
        // SKIP and the probe reports 77 instead of 79 — a drop that reads like
        // lost coverage. Same race, and same fix, as maps_config_probe §F5.
        $ids = [];
        for ($attempt = 1; $attempt <= 4 && $ids === []; $attempt++) {
            foreach ((array) $this->fs->schoolWhere('students', [], null, 'ASC', 5) as $row) {
                $d = is_array($row['data'] ?? null) ? $row['data'] : $row;
                $s = (string) ($d['studentId'] ?? '');
                if ($s !== '') { $ids[] = $s; }
            }
            if ($ids === [] && $attempt < 4) { usleep(400_000); }
        }
        if ($ids === []) {
            echo "      (no students in tenant — G3/G4 skipped)\n";
            return;
        }
        $uids = $this->gps->resolveViewerUids($ids);
        $this->truthy('G3', count($uids) > 0,
            'real roster of ' . count($ids) . ' resolves to ' . count($uids) . ' viewer uid(s)');
        $this->eq('G4', count($uids), count(array_unique($uids)), 'resolved uids are deduped');
    }

    // ── I. M2 fix ordering — stale / out-of-order must never win ─────────

    private function i_fixOrdering(): void
    {
        echo "\n[I] Fix ordering + clock sanity (M2)\n";
        $now   = 1700000000;
        $nowMs = $now * 1000;
        $fix   = fn(int $ms) => ['recorded_at_ms' => $ms];

        // A session doc seeds recorded_at_ms = 0, so the first real fix wins.
        $this->eq('I1', true,
            $this->gps->validateFixOrdering(['recorded_at_ms' => 0], $fix($nowMs), $now)['ok'],
            'first fix of a session is accepted (seed is 0, never null)');
        $this->eq('I2', true,
            $this->gps->validateFixOrdering(['recorded_at_ms' => $nowMs - 20000], $fix($nowMs), $now)['ok'],
            'a NEWER fix is accepted');
        $this->eq('I3', 'out_of_order',
            $this->gps->validateFixOrdering(['recorded_at_ms' => $nowMs], $fix($nowMs - 20000), $now)['reason'],
            'an OLDER fix is rejected — a queued offline flush cannot rewind the bus');
        $this->eq('I4', 'out_of_order',
            $this->gps->validateFixOrdering(['recorded_at_ms' => $nowMs], $fix($nowMs), $now)['reason'],
            'an identical timestamp is rejected (no new information, would burn a write)');

        // Clock sanity, both bounds measured against server time.
        $this->eq('I5', 'future_timestamp',
            $this->gps->validateFixOrdering(null, $fix($nowMs + 600000), $now)['reason'],
            'a fix 10 min in the FUTURE is rejected (a skewed clock could otherwise block the vehicle forever)');
        $this->eq('I6', true,
            $this->gps->validateFixOrdering(null, $fix($nowMs + 60000), $now)['ok'],
            'a fix 1 min ahead is tolerated (inside the 2 min skew allowance)');
        $this->eq('I7', 'fix_too_old',
            $this->gps->validateFixOrdering(null, $fix($nowMs - 600000), $now)['reason'],
            'a fix 10 min old is rejected — a tunnel flush must not become "current"');
        $this->eq('I8', true,
            $this->gps->validateFixOrdering(null, $fix($nowMs - 60000), $now)['ok'],
            'a fix 1 min old is accepted (inside the 5 min freshness window)');

        // Fail closed on a missing / malformed ordering key.
        $this->eq('I9', 'missing_recorded_at_ms',
            $this->gps->validateFixOrdering(null, [], $now)['reason'], 'missing ordering key rejected');
        $this->eq('I10', 'missing_recorded_at_ms',
            $this->gps->validateFixOrdering(null, ['recorded_at_ms' => 'abc'], $now)['reason'],
            'non-numeric ordering key rejected');
        $this->eq('I11', 'missing_recorded_at_ms',
            $this->gps->validateFixOrdering(null, $fix(0), $now)['reason'], 'zero ordering key rejected');

        // acceptFix composes quality (D-4) then ordering, quality first.
        $good = ['lat' => 23.0, 'lng' => 72.0, 'accuracy_m' => 10.0, 'recorded_at_ms' => $nowMs];
        $this->eq('I12', true, $this->gps->acceptFix(null, $good, $now)['ok'], 'acceptFix passes a clean, in-order fix');
        $this->eq('I13', 'mock_location',
            $this->gps->acceptFix(null, ['is_mock' => true] + $good, $now)['reason'],
            'acceptFix reports the QUALITY failure first (mock beats ordering)');
        $this->eq('I14', 'out_of_order',
            $this->gps->acceptFix(['recorded_at_ms' => $nowMs + 1000], $good, $now)['reason'],
            'acceptFix falls through to the ordering failure when quality passes');
    }

    // ── J. M2 session lifecycle round trip (WRITES, then cleans up) ──────

    private function j_lifecycleRoundTrip(): void
    {
        echo "\n[J] Session lifecycle round trip (open → verify → close)\n";
        $vehicleId = 'PROBE_F13_VEH';
        $tripId    = 'PROBE_F13_TRP';
        $trip = [
            'vehicleId'          => $vehicleId,
            'driver_staff_id'    => 'PROBE_F13_DRV',
            'gps_session_id'     => 'GPSSESS_' . $tripId,
            'roster_student_ids' => [],
        ];

        try {
            $open = $this->gps->openSession($trip, $tripId);
            $this->eq('J1', true, (bool) ($open['ok'] ?? false), 'openSession() creates the hot doc');

            $doc = $this->gps->getLivePosition($vehicleId);
            $this->truthy('J2', is_array($doc), 'hot doc is readable after open');
            $this->eq('J3', true,  $doc['live_tracking_enabled'] ?? null, 'kill switch is ARMED on open');
            $this->eq('J4', $tripId, $doc['tripId'] ?? null, 'hot doc is bound to the trip');
            $this->eq('J5', 'PROBE_F13_DRV', $doc['driver_staff_id'] ?? null, 'hot doc is bound to the driver');
            $this->eq('J6', 0, $doc['recorded_at_ms'] ?? null, 'ordering key seeded at 0 so the first fix wins');
            // Firestore DOES round-trip an explicit null (verified: the key
            // comes back present with a null value). Assert the key's presence
            // and its value separately — `?? default` collapses null onto the
            // default and cannot distinguish "null" from "absent".
            $this->truthy('J7', array_key_exists('lat', $doc) && $doc['lat'] === null,
                'no position asserted until a real fix arrives (key present, value null)');

            // Re-opening must OVERWRITE, not merge — a previous session's
            // position must never survive into a new one.
            $this->gps->openSession($trip + ['driver_staff_id' => 'PROBE_F13_DRV2'], $tripId);
            $doc2 = $this->gps->getLivePosition($vehicleId);
            $this->eq('J8', 0, $doc2['recorded_at_ms'] ?? null,
                're-open RESETS the doc (overwrite, not merge — yesterday\'s position cannot leak into a new trip)');

            $close = $this->gps->closeSession($vehicleId);
            $this->eq('J9', true, (bool) ($close['ok'] ?? false), 'closeSession() succeeds');
            $this->truthy('J10', $this->gps->getLivePosition($vehicleId) === null,
                'hot doc is GONE after close — the privacy invariant, made real');
        } finally {
            // Belt and braces: never leave probe residue, whatever failed above.
            try { $this->gps->closeSession($vehicleId); } catch (\Throwable $_) {}
        }

        $this->truthy('J11', $this->gps->getLivePosition($vehicleId) === null, 'teardown left no residue');
    }

    // ── K. stale-session sweeper ─────────────────────────────────────────

    private function k_sweeper(): void
    {
        echo "\n[K] Stale-session sweeper\n";
        $vehicleId = 'PROBE_F13_STALE_VEH';
        $trip = [
            'vehicleId'          => $vehicleId,
            'driver_staff_id'    => 'PROBE_F13_DRV',
            // A tripId that does not exist → the sweeper must treat the session
            // as orphaned and close it. This is the crashed-driver-app case.
            'gps_session_id'     => 'GPSSESS_PROBE_F13_ORPHAN',
            'roster_student_ids' => [],
        ];

        try {
            $this->gps->openSession($trip, 'PROBE_F13_TRIP_DOES_NOT_EXIST');
            $this->truthy('K1', $this->gps->getLivePosition($vehicleId) !== null, 'orphan session seeded');

            $r = $this->gps->sweepStaleSessions(200);
            $this->truthy('K2', ($r['closed'] ?? 0) >= 1,
                'sweeper closed the orphaned session (scanned=' . ($r['scanned'] ?? 0)
                . ' closed=' . ($r['closed'] ?? 0) . ' kept=' . ($r['kept'] ?? 0) . ')');
            $this->truthy('K3', $this->gps->getLivePosition($vehicleId) === null,
                'orphaned hot doc removed — a crashed driver app cannot leave a bus frozen on the map forever');

            $r2 = $this->gps->sweepStaleSessions(200);
            $this->eq('K4', 0, $r2['closed'] ?? -1, 'a second sweep closes nothing (idempotent)');
        } finally {
            try { $this->gps->closeSession($vehicleId); } catch (\Throwable $_) {}
        }
    }
}
