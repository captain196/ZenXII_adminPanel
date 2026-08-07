<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_perf10 — analytics memo keys are tenant-scoped (PERF-10).
 *
 * _memo() used bare keys, so a service instance reused across tenants could
 * return school A's cached value for school B unless an external cache=[] reset
 * happened first. Fix: prefix the memo key with schoolId.
 *
 *   G1 same key under tenant A then tenant B calls DISTINCT producers
 *      (no cross-tenant cache hit)
 *   G2 returning to tenant A still hits A's cached value
 *
 * Reflection-drives the private _memo + schoolId. CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_perf10 run
 */
class Transport_probe_perf10 extends CI_Controller
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
        $this->fs->init($sid, $sess);
        $this->numbering->init($this->fs, $sid, $sess);
        $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'probe_perf10');
        $this->analytics->init($this->fs, $this->transport, $sid, $sess);

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        $memo = new \ReflectionMethod($this->analytics, '_memo');
        $memo->setAccessible(true);
        $call = fn(string $k, $v) => $memo->invoke($this->analytics, $k, function () use ($v) { return $v; });
        $setSid = function (string $v) {
            $r = new \ReflectionProperty($this->analytics, 'schoolId'); $r->setAccessible(true); $r->setValue($this->analytics, $v);
        };

        echo "==================================================\n";
        echo " PERF-10 tenant-scoped memo probe\n";
        echo "==================================================\n";

        $setSid('SCH_A');
        $a = $call('kpi', 'A_VALUE');

        echo "\n[G1] same key under a different tenant does NOT hit A's cache\n";
        $setSid('SCH_B');
        $b = $call('kpi', 'B_VALUE');
        ($a === 'A_VALUE' && $b === 'B_VALUE')
            ? $pass("tenant A got A_VALUE, tenant B got B_VALUE (no cross-tenant bleed)")
            : $fail("a={$a} b={$b} (B would be 'A_VALUE' with bare keys)");

        echo "\n[G2] returning to tenant A still hits A's cached value\n";
        $setSid('SCH_A');
        $a2 = $call('kpi', 'A_VALUE_2');   // producer returns _2, but cache should win
        ($a2 === 'A_VALUE')
            ? $pass("tenant A re-read served the cached A_VALUE (memo intact per-tenant)")
            : $fail("expected cached A_VALUE, got {$a2}");

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }
}
