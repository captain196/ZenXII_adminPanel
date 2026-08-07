<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_sec5 — cross-tenant KPI singleton restore (SEC-5).
 *
 * getCrossTenantFleetKpi rewires the shared Firestore singleton per tenant and
 * restored it only on the happy path (no try/finally). A throw mid-loop left
 * the singleton pointing at the last tenant → a subsequent op in the same
 * request read/wrote the WRONG school (cross-tenant bleed). Fix: _assertReady +
 * restore moved into a finally block.
 *
 *   G1 SUCCESS path — after the call the fs singleton is back on the origin tenant
 *   G2 THROW path — a tenant compose that throws still restores the origin
 *      (validated with a stub fs whose init() throws for a sentinel tenant)
 *
 * Reflection-drives private state. CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_sec5 run
 */
class Transport_probe_sec5 extends CI_Controller
{
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
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'transport_probe_sec5');
            $this->analytics->init($this->fs, $this->transport, $sid, $sess);
        } catch (\Throwable $e) { fwrite(STDERR, "ERROR: init — " . $e->getMessage() . "\n"); exit(1); }

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        // Reflection helpers for private state.
        $fsSchoolId = function ($fsObj) {
            $r = new \ReflectionProperty($fsObj, 'schoolId'); $r->setAccessible(true); return (string) $r->getValue($fsObj);
        };
        $anProp = function (string $p) {
            $r = new \ReflectionProperty($this->analytics, $p); $r->setAccessible(true); return $r->getValue($this->analytics);
        };
        $anSet = function (string $p, $v) {
            $r = new \ReflectionProperty($this->analytics, $p); $r->setAccessible(true); $r->setValue($this->analytics, $v);
        };

        echo "==================================================\n";
        echo " SEC-5 cross-tenant singleton restore probe\n";
        echo " tenant={$sid} session={$sess}\n";
        echo "==================================================\n";

        try {
            echo "\n[G1] SUCCESS path restores the fs singleton to the origin tenant\n";
            $this->analytics->getCrossTenantFleetKpi([$sid, 'SCH_FAKE_B']);
            $now = $fsSchoolId($this->fs);
            ($now === $sid)
                ? $pass("fs singleton restored to origin ({$sid}) after cross-tenant compose")
                : $fail("fs left on '{$now}' (expected {$sid}) — bleed");

            echo "\n[G2] per-tenant throw is CAUGHT (PERF-6) + origin restored (SEC-5 finally)\n";
            $realFs = $anProp('fs');
            // Stub fs: init() throws for the sentinel tenant; getFleetKpiTiles is
            // never reached for it. (getFleetKpiTiles for a non-throw tenant would
            // need more surface, so we only exercise the throwing tenant here.)
            $stub = new class {
                public string $schoolId = '';
                public function init($sid, $sess) {
                    if ($sid === 'SCH_THROW') { throw new \RuntimeException('probe forced throw'); }
                    $this->schoolId = (string) $sid; return $this;
                }
            };
            $stub->schoolId = 'SCH_ORIG';
            $anSet('fs', $stub);
            $anSet('schoolId', 'SCH_ORIG');
            $propagated = false; $res = [];
            try {
                $res = (array) $this->analytics->getCrossTenantFleetKpi(['SCH_THROW']);
            } catch (\Throwable $e) { $propagated = true; }
            $restored = (string) $anProp('schoolId');
            $anSet('fs', $realFs);   // put the real fs back
            $hasErrMarker = isset($res['per_tenant']['SCH_THROW']['error']);
            (!$propagated && $restored === 'SCH_ORIG' && $hasErrMarker)
                ? $pass("throw caught per-tenant (no propagate, PERF-6), error marker recorded, origin restored (SEC-5)")
                : $fail("propagated={$propagated} restored={$restored} errMarker=" . var_export($hasErrMarker, true));
        } catch (\Throwable $e) {
            $fail("unexpected exception: " . $e->getMessage());
        }

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }
}
