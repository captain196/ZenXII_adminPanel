<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_analytics_rollup_job — F11 (2026-07-08) nightly cron
 * (Refinement 2 — nightly cadence approved).
 *
 * Per tenant: rebuilds the denormalised caches (
 *   fleetMonthlyExpenseSummary, driverActivitySummary [current month],
 *   vehicleExpiryDashboardCache, routeFleetSummary
 * ) and appends a per-tenant transportFleetRollup sub-object into the
 * existing analyticsRollups/{YYYY-MM} doc (recommendation §9 of the
 * research report — reuse B2 infra rather than a new SA collection).
 *
 * CLI-only. Invocation:
 *   php index.php transport_analytics_rollup_job run
 *
 * The runner does NOT need SCHOOL_ID / SESSION_YEAR env vars — it
 * iterates tenants via B2_registry_service. Session_year is derived
 * per tenant from schoolControl.currentSession.
 */
class Transport_analytics_rollup_job extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Transport_analytics_rollup_job is CLI-only.', 403);
        }
        $this->load->library('firebase',                    null, 'firebase');
        $this->load->library('firestore_service',           null, 'fs');
        $this->load->library('numbering_service',           null, 'numbering');
        $this->load->library('transport_service',           null, 'transport');
        $this->load->library('transport_analytics_service', null, 'trnAnalytics');
    }

    public function run(): void
    {
        $started = microtime(true);
        $curMonth = date('Y-m');
        echo "==================================================\n";
        echo " F11 Transport analytics rollup — {$curMonth}\n";
        echo "==================================================\n";

        // Discover tenants. Prefer B2_registry_service if available;
        // otherwise iterate schools directly.
        $tenants = [];
        try {
            $this->load->library('b2_registry_service', null, 'b2Registry');
            if (isset($this->b2Registry) && method_exists($this->b2Registry, 'list_tenants_summary')) {
                $rows = (array) $this->b2Registry->list_tenants_summary();
                foreach ($rows as $r) {
                    $sid = (string) ($r['schoolId'] ?? $r['id'] ?? '');
                    if ($sid !== '') { $tenants[] = $sid; }
                }
            }
        } catch (\Throwable $_) {}
        if ($tenants === []) {
            // Fallback: scan schools collection directly
            try {
                $rows = (array) $this->fs->firestoreQuery('schools', [], null, 'ASC', 1000);
                foreach ($rows as $r) {
                    $sid = (string) ($r['id'] ?? $r['schoolId'] ?? '');
                    if ($sid !== '') { $tenants[] = $sid; }
                }
            } catch (\Throwable $e) {
                fwrite(STDERR, "ERROR: unable to enumerate tenants — " . $e->getMessage() . "\n");
                exit(1);
            }
        }
        $tenants = array_values(array_unique(array_filter($tenants)));
        echo "Discovered " . count($tenants) . " tenants.\n";

        $rollup = [];   // schoolId => transportFleetRollup sub-object
        $ok = 0; $err = 0;

        $this->load->helper('lifecycle_guard');
        $lifecycleSkipped = 0;

        foreach ($tenants as $sid) {
            // ── LIFECYCLE GUARD ─────────────────────────────────────────
            // class = analytics. Per-tenant `continue` rather than exit: one
            // suspended or closed school must never stop the rollup for the
            // rest of the fleet. Skips are counted and reported so a partial
            // rollup is never mistaken for a complete one.
            $lcv = lifecycle_guard_check($this->firebase, $sid, Lifecycle_resolver::JOB_ANALYTICS);
            if (empty($lcv['allowed'])) {
                $lifecycleSkipped++;
                echo "  ⏭ {$sid}: skipped (lifecycle commercial="
                   . ($lcv['commercial'] !== '' ? $lcv['commercial'] : 'unknown')
                   . " institution={$lcv['institution']})\n";
                continue;
            }

            try {
                // Re-init all libraries per tenant
                $sess = $this->_discoverSessionYear($sid);
                $this->fs->init($sid, $sess);
                $this->numbering->init($this->fs, $sid, $sess);
                $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'rollup_job');
                $this->trnAnalytics->init($this->fs, $this->transport, $sid, $sess);

                // Rebuild all caches
                $this->transport->computeFleetMonthlyExpenseSummary($curMonth);
                $this->transport->rebuildActivitySummary('', $curMonth);
                $this->transport->computeFleetExpiryDashboard();
                $this->transport->rebuildRouteFleetSummary();

                // Compose the per-tenant KPI slice
                $tiles = $this->trnAnalytics->getFleetKpiTiles();
                $health = $this->trnAnalytics->getFleetHealthScore();
                $rollup[$sid] = [
                    'schoolId'                  => $sid,
                    'active_vehicles'           => (int)   $tiles['active_vehicles'],
                    'trips_today'               => (int)   $tiles['trips_today'],
                    'docs_expiring_7d'          => (int)   $tiles['docs_expiring_7d'],
                    'docs_expiring_15d'         => (int)   $tiles['docs_expiring_15d'],
                    'docs_expiring_30d'         => (int)   $tiles['docs_expiring_30d'],
                    'fuel_spend_this_month'     => (float) $tiles['fuel_spend_this_month'],
                    'pending_accounting_posts'  => (int)   $tiles['pending_accounting_posts'],
                    'sos_active_count'          => (int)   $tiles['sos_active_count'],
                    'fleet_health_score'        => (float) $health['score'],
                    'fleet_health_tier'         => (string)$health['tier'],
                    'computed_at'               => date('c'),
                ];
                $ok++;
                echo "  OK    {$sid}\n";
            } catch (\Throwable $e) {
                $err++;
                echo "  ERR   {$sid} — " . $e->getMessage() . "\n";
            }
        }

        // Persist into analyticsRollups/{YYYY-MM} under
        // transportFleetRollup (does not disturb the existing B2 fields).
        try {
            // No tenant scoping — analyticsRollups is a cross-tenant SA
            // collection. Bypass tenant-prefix by using firestoreSet.
            $existingDocId = "analyticsRollups/{$curMonth}";
            $payload = [
                'transportFleetRollup' => [
                    'period_year_month' => $curMonth,
                    'tenants'           => $rollup,
                    'tenants_ok'        => $ok,
                    'tenants_err'       => $err,
                    'computed_at'       => date('c'),
                ],
            ];
            // Try B2 pathway first; fallback to direct firestoreSet
            if (method_exists($this->firebase, 'firestoreSet')) {
                $this->firebase->firestoreSet('analyticsRollups', $curMonth, $payload, /* merge */ true);
            } elseif (method_exists($this->fs, 'setDoc')) {
                $this->fs->setDoc($existingDocId, $payload, /* merge */ true);
            } else {
                fwrite(STDERR, "WARN: no firestoreSet primitive available; rollup NOT persisted.\n");
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR persisting rollup: " . $e->getMessage() . "\n");
        }

        $ms = (int) round((microtime(true) - $started) * 1000);
        echo "==================================================\n";
        echo " DONE — ok={$ok} err={$err} duration_ms={$ms}\n";
        echo "==================================================\n";
        exit($err > 0 ? 2 : 0);
    }

    private function _discoverSessionYear(string $sid): string
    {
        try {
            $ctrl = $this->fs->getEntity('schoolControl', $sid);
            if (is_array($ctrl) && !empty($ctrl['currentSession'])) {
                return (string) $ctrl['currentSession'];
            }
        } catch (\Throwable $_) {}
        return date('Y') . '-' . substr((string) (date('Y') + 1), -2);
    }
}
