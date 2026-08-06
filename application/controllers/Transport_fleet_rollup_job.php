<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_fleet_rollup_job — PERF-4 nightly cross-tenant fleet KPI rollup.
 *
 * The SA fleet leaderboard (Transport_analytics_service::getFleetLeaderboard)
 * used to recompute per-tenant KPIs synchronously on the live request —
 * O(tenants×reads), ~2,500 round-trips at 500 tenants → timeout. This cron
 * precomputes the aggregate ONCE per night and writes it to
 * transportFleetRollup/{date}; the request path then serves it with a single
 * document read (with a bounded live fallback when the rollup is missing).
 *
 * Operations:
 *   php index.php Transport_fleet_rollup_job run
 *     - Resolve all tenants (B2_registry_service) + write today's rollup.
 *
 * Scheduling target: nightly (operator-driven, alongside the B2 rollup cron).
 * Backend writes via service account; bypasses Firestore Rules.
 */
class Transport_fleet_rollup_job extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!defined('STDIN')) { exit("CLI only.\n"); }
        $this->load->library('firebase',                    null, 'firebase');
        $this->load->library('firestore_service',           null, 'fs');
        $this->load->library('numbering_service',           null, 'numbering');
        $this->load->library('transport_service',           null, 'transport');
        $this->load->library('transport_analytics_service', null, 'analytics');
        $this->load->library('b2_registry_service',         null, 'registry');
    }

    public function run(): void
    {
        $sess = (string) (getenv('SESSION_YEAR') ?: date('Y') . '-' . (((int) date('y')) + 1));
        echo "═══ Transport fleet rollup: " . date('Y-m-d') . " ═══\n";

        // Resolve tenant ids (same source the SA surface uses).
        try {
            $this->registry->init($this->firebase);
            $summary = (array) $this->registry->list_tenants_summary();
        } catch (\Throwable $e) {
            echo "  ❌ tenant resolution failed: " . $e->getMessage() . "\n"; exit(1);
        }
        $tenantIds = [];
        foreach ($summary as $row) {
            $sid = (string) ($row['schoolId'] ?? $row['id'] ?? '');
            if ($sid !== '') { $tenantIds[] = $sid; }
        }
        $tenantIds = array_values(array_unique($tenantIds));
        if ($tenantIds === []) { echo "  ⚠ no tenants resolved — nothing to roll up.\n"; exit(0); }

        // Init analytics on the first tenant; storeCrossTenantFleetRollup
        // rewires the per-tenant state internally and restores it at the end.
        try {
            $this->fs->init($tenantIds[0], $sess);
            $this->numbering->init($this->fs, $tenantIds[0], $sess);
            $this->transport->init($this->fs, $this->numbering, $tenantIds[0], $sess, 'transport_fleet_rollup_job');
            $this->analytics->init($this->fs, $this->transport, $tenantIds[0], $sess);
        } catch (\Throwable $e) {
            echo "  ❌ init failed: " . $e->getMessage() . "\n"; exit(1);
        }

        $t0 = microtime(true);
        try {
            $rollup = (array) $this->analytics->storeCrossTenantFleetRollup($tenantIds);
        } catch (\Throwable $e) {
            echo "  ❌ rollup compute/write failed: " . $e->getMessage() . "\n"; exit(1);
        }
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $tot = (array) ($rollup['totals'] ?? []);
        echo "  ✅ Written transportFleetRollup/" . ($rollup['date'] ?? '?') . "\n";
        echo "  tenants={$rollup['tenantCount']}  vehicles=" . (int) ($tot['total_vehicles'] ?? 0)
           . "  trips_today=" . (int) ($tot['total_trips_today'] ?? 0)
           . "  active_sos=" . (int) ($tot['total_active_sos'] ?? 0) . "\n";
        echo "  Duration: {$ms}ms\n";
        exit(0);
    }
}
