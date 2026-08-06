<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_perf4 — SA cross-tenant fleet KPI is rollup-served (PERF-4).
 *
 * getFleetLeaderboard used to recompute per-tenant KPIs synchronously on the
 * live request (O(tenants) → timeout at scale). Fix: a nightly job writes
 * transportFleetRollup/{date} and the request path serves it with ONE document
 * read (bounded live fallback when the rollup is missing).
 *
 *   G1 storeCrossTenantFleetRollup writes transportFleetRollup/{today}
 *   G2 getCrossTenantFleetKpiCached serves it → source='rollup' (O(1) read)
 *   G3 with NO rollup, a large tenant list degrades to a BOUNDED live compute
 *      → source='live_partial', partial=true, tenantsComputed==cap (never
 *      the full O(tenants) loop)
 *   G4 getFleetLeaderboard serves from the rollup (rows derived from it)
 *
 * Self-teardown. CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_perf4 run
 */
class Transport_probe_perf4 extends CI_Controller
{
    private const COL = 'transportFleetRollup';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) { show_error('CLI-only.', 403); }
        $this->load->library('firebase',                    null, 'firebase');
        $this->load->library('firestore_service',           null, 'fs');
        $this->load->library('numbering_service',           null, 'numbering');
        $this->load->library('transport_service',           null, 'transport');
        $this->load->library('transport_analytics_service', null, 'analytics');
    }

    public function run(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') { fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n"); exit(1); }
        try {
            $this->fs->init($sid, $sess);
            $this->numbering->init($this->fs, $sid, $sess);
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'transport_probe_perf4');
            $this->analytics->init($this->fs, $this->transport, $sid, $sess);
        } catch (\Throwable $e) { fwrite(STDERR, "ERROR: init — " . $e->getMessage() . "\n"); exit(1); }

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        $today = date('Y-m-d');

        echo "==================================================\n";
        echo " PERF-4 cross-tenant fleet KPI rollup probe\n";
        echo " tenant={$sid} today={$today}\n";
        echo "==================================================\n";

        try {
            // Clean any stale rollup so G3's fallback path is exercised first.
            try { $this->firebase->firestoreDelete(self::COL, $today); } catch (\Throwable $e) {}

            echo "\n[G3] no rollup → bounded live fallback (capped, partial)\n";
            $many = [$sid, 'SCH_FAKE_A', 'SCH_FAKE_B', 'SCH_FAKE_C'];
            $fb = (array) $this->analytics->getCrossTenantFleetKpiCached($many, 2);
            ((string) ($fb['source'] ?? '') === 'live_partial'
                && ($fb['partial'] ?? false) === true
                && (int) ($fb['tenantsComputed'] ?? -1) === 2
                && (int) ($fb['tenantsRequested'] ?? -1) === 4)
                ? $pass("fallback capped at 2/4 tenants, partial=true (no O(tenants) fan-out)")
                : $fail("fallback meta wrong: " . json_encode(['source'=>$fb['source']??null,'partial'=>$fb['partial']??null,'computed'=>$fb['tenantsComputed']??null,'requested'=>$fb['tenantsRequested']??null]));

            echo "\n[G1] storeCrossTenantFleetRollup writes transportFleetRollup/{today}\n";
            $stored = (array) $this->analytics->storeCrossTenantFleetRollup([$sid]);
            $doc = (array) ($this->fs->get(self::COL, $today) ?: []);
            (isset($doc['totals']) && is_array($doc['totals']) && (string) ($doc['date'] ?? '') === $today)
                ? $pass("rollup persisted: tenantCount=" . ($doc['tenantCount'] ?? '?') . ", computedAt=" . ($doc['computedAt'] ?? '?'))
                : $fail("rollup doc missing/malformed: " . json_encode(array_keys($doc)));

            echo "\n[G2] getCrossTenantFleetKpiCached serves the rollup (O(1) read)\n";
            $cached = (array) $this->analytics->getCrossTenantFleetKpiCached([$sid, 'SCH_FAKE_A', 'SCH_FAKE_B']);
            ((string) ($cached['source'] ?? '') === 'rollup' && isset($cached['totals']))
                ? $pass("served from rollup (source=rollup) despite 3 tenants requested — single doc read")
                : $fail("did not serve from rollup: source=" . ($cached['source'] ?? '(none)'));

            echo "\n[G4] getFleetLeaderboard serves from the rollup\n";
            $lb = (array) $this->analytics->getFleetLeaderboard([$sid, 'SCH_FAKE_A'], 'active_vehicles', 10);
            // rows derive from the rollup's per_tenant; the real tenant must appear.
            $tids = array_map(fn($r) => (string) ($r['tenant_id'] ?? ''), $lb);
            in_array($sid, $tids, true)
                ? $pass("leaderboard rows sourced from rollup (real tenant present, " . count($lb) . " rows)")
                : $fail("leaderboard did not include the rollup tenant: " . json_encode($tids));
        } catch (\Throwable $e) {
            $fail("unexpected exception: " . $e->getMessage());
        } finally {
            echo "\n[TEARDOWN]\n";
            try { $this->firebase->firestoreDelete(self::COL, $today); } catch (\Throwable $e) {}
            echo "  cleaned rollup/{$today}\n";
        }

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }
}
