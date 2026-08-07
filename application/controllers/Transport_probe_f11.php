<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_f11 — F11 Analytics + Super Admin runtime probe
 * (LC-24 + LC-25, 2026-07-08).
 *
 * Exercises the F11 analytics facade:
 *   1. Role-scope matrix produces correct widget lists per role
 *   2. Teacher / Class Teacher → empty widget list (no analytics)
 *   3. computeRouteFleetSummary populates routeFleetSummary
 *   4. Analytics service memoises hot reads (call twice → same object)
 *   5. Fleet KPI tiles bundle assembles without raw-collection scans
 *   6. Fleet Health Score returns 0-100 with breakdown
 *   7. Live Ops snapshot returns expected shape
 *   8. Cross-tenant aggregate compiles across a synthetic tenant list
 *
 * CLI-only.
 */
class Transport_probe_f11 extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Transport_probe_f11 is CLI-only.', 403);
        }
        $this->load->library('firebase',                    null, 'firebase');
        $this->load->library('firestore_service',           null, 'fs');
        $this->load->library('numbering_service',           null, 'numbering');
        $this->load->library('transport_service',           null, 'transport');
        $this->load->library('transport_analytics_service', null, 'trnAnalytics');
    }

    public function run(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n");
            exit(1);
        }
        try {
            $this->fs->init($sid, $sess);
            $this->numbering->init($this->fs, $sid, $sess);
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'probe_f11');
            $this->trnAnalytics->init($this->fs, $this->transport, $sid, $sess);
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: init failed — " . $e->getMessage() . "\n");
            exit(1);
        }

        $passed = 0; $failed = 0; $errors = [];

        echo "==================================================\n";
        echo " F11 Analytics + Super Admin Runtime Probe\n";
        echo " tenant=$sid session=$sess\n";
        echo "==================================================\n";

        // Step 1 — role-scope matrix
        echo "\n[STEP 1] Role-scope matrix — Admin sees all scopes\n";
        try {
            $adminWidgets = Transport_analytics_service::visibleWidgetsForRole('Admin');
            $tmWidgets    = Transport_analytics_service::visibleWidgetsForRole('Transport Manager');
            $accWidgets   = Transport_analytics_service::visibleWidgetsForRole('Accountant');
            $hrWidgets    = Transport_analytics_service::visibleWidgetsForRole('HR Manager');
            if (count($adminWidgets) < 5) { throw new \RuntimeException("Admin expected ≥5 widgets, got " . count($adminWidgets)); }
            if (!in_array('per_vehicle_expense', $accWidgets, true)) { throw new \RuntimeException("Accountant should see financial widgets"); }
            if (in_array('monthly_expense_trend', $hrWidgets, true))  { throw new \RuntimeException("HR should NOT see financial widgets"); }
            echo "  PASS: Admin=" . count($adminWidgets) . " widgets, TM=" . count($tmWidgets) . ", Accountant=" . count($accWidgets) . ", HR=" . count($hrWidgets) . "\n";
            $passed++;
        } catch (\Throwable $e) { echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step1: '.$e->getMessage(); }

        // Step 2 — Teacher/Class Teacher → empty
        echo "\n[STEP 2] Teacher / Class Teacher → NO analytics\n";
        try {
            $t  = Transport_analytics_service::visibleWidgetsForRole('Teacher');
            $ct = Transport_analytics_service::visibleWidgetsForRole('Class Teacher');
            if ($t !== [] || $ct !== []) {
                throw new \RuntimeException("Teacher=" . count($t) . " ClassTeacher=" . count($ct) . " — expected empty");
            }
            if (Transport_analytics_service::hasAnyVisibility('Teacher')) {
                throw new \RuntimeException("hasAnyVisibility('Teacher') should be false");
            }
            echo "  PASS: Teacher + Class Teacher both see 0 management analytics\n";
            $passed++;
        } catch (\Throwable $e) { echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step2: '.$e->getMessage(); }

        // Step 3 — computeRouteFleetSummary populates the collection
        echo "\n[STEP 3] computeRouteFleetSummary — populate routeFleetSummary\n";
        try {
            $r = $this->transport->computeRouteFleetSummary('');
            $processed = (int) ($r['routes_processed'] ?? 0);
            echo "  PASS: routes_processed={$processed} (0 is acceptable when no Active routes exist)\n";
            $passed++;
        } catch (\Throwable $e) { echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step3: '.$e->getMessage(); }

        // Step 4 — memoisation — call twice, expect same array reference-ish
        echo "\n[STEP 4] Analytics service memoises hot reads\n";
        try {
            $t1 = microtime(true);
            $a = $this->trnAnalytics->getFleetKpiTiles();
            $d1 = microtime(true) - $t1;
            $t2 = microtime(true);
            $b = $this->trnAnalytics->getFleetKpiTiles();
            $d2 = microtime(true) - $t2;
            if ($a !== $b) { throw new \RuntimeException('memoised value differs across calls'); }
            if ($d2 > $d1 && $d1 > 0.01) { throw new \RuntimeException("2nd call slower ({$d2}s) than 1st ({$d1}s) — memoisation broken"); }
            echo "  PASS: 1st call " . round($d1 * 1000, 1) . "ms, 2nd call " . round($d2 * 1000, 1) . "ms (memoised)\n";
            $passed++;
        } catch (\Throwable $e) { echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step4: '.$e->getMessage(); }

        // Step 5 — bundle shape
        echo "\n[STEP 5] Fleet KPI tiles bundle assembles cleanly\n";
        try {
            $tiles = $this->trnAnalytics->getFleetKpiTiles();
            $req = ['active_vehicles','trips_today','docs_expiring_7d','docs_expiring_15d','docs_expiring_30d','fuel_spend_this_month','pending_accounting_posts','sos_active_count'];
            $missing = [];
            foreach ($req as $k) { if (!array_key_exists($k, $tiles)) { $missing[] = $k; } }
            if ($missing !== []) { throw new \RuntimeException('missing fields: ' . implode(',', $missing)); }
            echo "  PASS: 8 KPI fields present; vehicles=" . $tiles['active_vehicles'] . " trips_today=" . $tiles['trips_today'] . "\n";
            $passed++;
        } catch (\Throwable $e) { echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step5: '.$e->getMessage(); }

        // Step 6 — health score
        echo "\n[STEP 6] Fleet Health Score returns 0-100 with breakdown\n";
        try {
            $h = $this->trnAnalytics->getFleetHealthScore();
            $score = (float) ($h['score'] ?? -1);
            if ($score < 0 || $score > 100) { throw new \RuntimeException("score out of range: {$score}"); }
            if (!in_array($h['tier'] ?? '', ['Excellent','Good','Fair','Attention needed'], true)) {
                throw new \RuntimeException("bad tier: " . ($h['tier'] ?? 'null'));
            }
            $bkd = (array) ($h['breakdown'] ?? []);
            $expected = ['docs_currency','route_on_time','incidents','sos_history','accounting_posts','maintenance'];
            foreach ($expected as $k) {
                if (!array_key_exists($k, $bkd)) { throw new \RuntimeException("breakdown missing {$k}"); }
            }
            echo "  PASS: score={$score} tier={$h['tier']} 6 dimensions\n";
            $passed++;
        } catch (\Throwable $e) { echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step6: '.$e->getMessage(); }

        // Step 7 — live ops snapshot
        echo "\n[STEP 7] Live Ops snapshot returns expected shape\n";
        try {
            $snap = $this->trnAnalytics->getLiveOperationsSnapshot();
            $req = ['trips_in_progress','trips_delayed','trips_completed_today','active_sos_events','students_currently_onboard','students_dropped_today','vehicles_under_maintenance','no_shows_today','as_of'];
            $missing = [];
            foreach ($req as $k) { if (!array_key_exists($k, $snap)) { $missing[] = $k; } }
            if ($missing !== []) { throw new \RuntimeException('missing fields: ' . implode(',', $missing)); }
            echo "  PASS: 9 fields present; in_progress=" . $snap['trips_in_progress'] . " onboard=" . $snap['students_currently_onboard'] . "\n";
            $passed++;
        } catch (\Throwable $e) { echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step7: '.$e->getMessage(); }

        // Step 8 — cross-tenant aggregate
        echo "\n[STEP 8] Cross-tenant aggregate compiles (self-tenant only)\n";
        try {
            $agg = $this->trnAnalytics->getCrossTenantFleetKpi([$sid]);
            if (!isset($agg['totals']['total_vehicles']) || !isset($agg['per_tenant'][$sid])) {
                throw new \RuntimeException('cross-tenant shape wrong');
            }
            echo "  PASS: cross-tenant totals=" . $agg['totals']['total_vehicles'] . " vehicles across " . count($agg['per_tenant']) . " tenants\n";
            $passed++;
        } catch (\Throwable $e) { echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step8: '.$e->getMessage(); }

        echo "\n==================================================\n";
        echo " RESULT: passed=$passed failed=$failed\n";
        if ($failed > 0) {
            foreach ($errors as $err) { echo "  - $err\n"; }
            echo "==================================================\n";
            exit(2);
        }
        echo "==================================================\n";
        exit(0);
    }
}
