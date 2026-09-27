<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Lifecycle_perf_probe — READ-ONLY latency measurement for the institution
 * lifecycle gate.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  SAFETY: THIS PROBE NEVER WRITES
 * ──────────────────────────────────────────────────────────────────────
 * The only Firestore method it calls is firestoreGet(). There is no set,
 * update, create, delete, batch or transaction anywhere in this file, and
 * no teardown step. It is therefore safe to point at a live tenant — which
 * matters, because the operator's tenant must never be write-probed.
 *
 * WHY IT EXISTS
 * A lifecycle gate that is consulted on every request and every job must be
 * measured, not assumed. This probe answers three questions with numbers:
 *   1. What does a single Firestore document read actually cost here?
 *   2. What does one full resolve() cost (it should be ~2 reads)?
 *   3. Does the request-scoped memo actually eliminate the repeat cost?
 *
 * USAGE
 *   SCHOOL_ID=SCH_XXXXXXXX php index.php lifecycle_perf_probe run [iterations]
 *
 * Exit codes: 0 ok · 1 bad invocation.
 */
class Lifecycle_perf_probe extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Lifecycle_perf_probe is CLI-only.', 403);
        }
    }

    public function run($iterations = 10): void
    {
        $schoolId = (string) (getenv('SCHOOL_ID') ?: '');
        if ($schoolId === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID env var is required.\n");
            exit(1);
        }
        $n = max(1, min(50, (int) $iterations));

        echo "Lifecycle gate latency probe (READ-ONLY)\n";
        echo "  tenant={$schoolId}  iterations={$n}\n";
        echo str_repeat('-', 68) . "\n";

        $samples = [
            'schoolControl read'      => [],
            'schools read'            => [],
            'tenantPublic read'       => [],
            'resolve() cold (2 docs)' => [],
            'resolve() memoized'      => [],
            'guard check (money)'     => [],
        ];

        $this->load->helper('lifecycle_guard');

        for ($i = 0; $i < $n; $i++) {
            $samples['schoolControl read'][] = $this->_time(function () use ($schoolId) {
                $this->firebase->firestoreGet('schoolControl', $schoolId);
            });
            $samples['schools read'][] = $this->_time(function () use ($schoolId) {
                $this->firebase->firestoreGet('schools', $schoolId);
            });
            $samples['tenantPublic read'][] = $this->_time(function () use ($schoolId) {
                $this->firebase->firestoreGet('tenantPublic', $schoolId);
            });

            $resolver = new Lifecycle_resolver();
            $resolver->init($this->firebase);
            $samples['resolve() cold (2 docs)'][] = $this->_time(function () use ($resolver, $schoolId) {
                $resolver->resolve($schoolId);
            });
            $samples['resolve() memoized'][] = $this->_time(function () use ($resolver, $schoolId) {
                $resolver->resolve($schoolId);
            });
            $samples['guard check (money)'][] = $this->_time(function () use ($schoolId) {
                lifecycle_guard_check($this->firebase, $schoolId, Lifecycle_resolver::JOB_MONEY);
            });
        }

        printf("%-26s %8s %8s %8s %8s\n", 'operation', 'p50 ms', 'p95 ms', 'min ms', 'max ms');
        foreach ($samples as $label => $vals) {
            sort($vals);
            printf("%-26s %8.1f %8.1f %8.1f %8.1f\n",
                $label, $this->_pct($vals, 50), $this->_pct($vals, 95), $vals[0], end($vals));
        }

        // ── COLD path ───────────────────────────────────────────────────
        // The numbers above are STEADY-STATE: Firestore_rest_client keeps a
        // request-scoped read memo AND a cross-request file cache whose
        // whitelist includes schools/schoolControl/tenantPublic (5–15 min
        // TTL, busted on write). So in a warm process the gate costs
        // essentially nothing — which is the number that matters for
        // production, but it is NOT the network cost. Measure that too, with
        // a fresh client per iteration so no memo can serve the read.
        echo str_repeat('-', 68) . "\n";
        echo "COLD (fresh client each iteration — true network round-trips)\n";
        $cold = ['schoolControl read' => [], 'schools read' => [], 'resolve() cold' => []];
        $httpBefore = \FirestoreRestClient::$probeHttpCalls;
        for ($i = 0; $i < min($n, 5); $i++) {
            $fb1 = new Firebase();
            $cold['schoolControl read'][] = $this->_time(function () use ($fb1, $schoolId) {
                $fb1->firestoreGet('schoolControl', $schoolId);
            });
            $fb2 = new Firebase();
            $cold['schools read'][] = $this->_time(function () use ($fb2, $schoolId) {
                $fb2->firestoreGet('schools', $schoolId);
            });
            $fb3 = new Firebase();
            $r3 = (new Lifecycle_resolver())->init($fb3);
            $cold['resolve() cold'][] = $this->_time(function () use ($r3, $schoolId) {
                $r3->resolve($schoolId);
            });
        }
        foreach ($cold as $label => $vals) {
            sort($vals);
            printf("%-26s %8.1f %8.1f %8.1f %8.1f\n",
                $label, $this->_pct($vals, 50), $this->_pct($vals, 95), $vals[0], end($vals));
        }
        printf("HTTP round-trips during cold phase: %d\n",
            \FirestoreRestClient::$probeHttpCalls - $httpBefore);

        // ── LAYER SPLIT ─────────────────────────────────────────────────
        // The "cold" figures above conflate two very different costs:
        // constructing a Firebase client (service-account parse + Kreait
        // factory + token plumbing — pure APPLICATION time) and the actual
        // Firestore round-trip (NETWORK time). Attributing the total to
        // "Firestore is slow" would be wrong if most of it is construction,
        // so measure them apart.
        echo str_repeat('-', 68) . "\n";
        echo "LAYER SPLIT — application vs network\n";
        $construct = [];
        $roundtrip = [];
        for ($i = 0; $i < min($n, 5); $i++) {
            $fb = null;
            $construct[] = $this->_time(function () use (&$fb) { $fb = new Firebase(); });
            // Second and later reads on this client hit its memo, so only the
            // FIRST read per freshly-built client is a true round-trip.
            $roundtrip[] = $this->_time(function () use ($fb, $schoolId) {
                $fb->firestoreGet('schoolControl', $schoolId);
            });
        }
        foreach (['new Firebase() [app]' => $construct, 'first read [network]' => $roundtrip] as $label => $vals) {
            sort($vals);
            printf("%-26s %8.1f %8.1f %8.1f %8.1f\n",
                $label, $this->_pct($vals, 50), $this->_pct($vals, 95), $vals[0], end($vals));
        }
        $cSum = array_sum($construct); $rSum = array_sum($roundtrip);
        $tot  = $cSum + $rSum;
        if ($tot > 0) {
            printf("share: application %.0f%%  ·  network %.0f%%\n",
                ($cSum / $tot) * 100, ($rSum / $tot) * 100);
        }

        // Report the verdict too, so the probe doubles as a state inspector.
        $r = (new Lifecycle_resolver())->init($this->firebase)->resolve($schoolId);
        echo str_repeat('-', 68) . "\n";
        echo "verdict: commercial=" . ($r['commercial'] !== '' ? $r['commercial'] : '(unreadable)')
           . "  institution={$r['institution']}"
           . "  known=" . (!empty($r['known']) ? 'yes' : 'no')
           . "  code={$r['code']}\n";
        echo "caps: ";
        foreach ($r['caps'] as $k => $v) { echo $k . '=' . ($v ? '1' : '0') . ' '; }
        echo "\n";
    }

    private function _time(callable $fn): float
    {
        $t0 = microtime(true);
        try { $fn(); } catch (\Throwable $e) { /* latency of a failure is still latency */ }
        return (microtime(true) - $t0) * 1000.0;
    }

    private function _pct(array $sorted, int $p): float
    {
        if ($sorted === []) return 0.0;
        $idx = (int) floor(($p / 100) * (count($sorted) - 1));
        return (float) $sorted[$idx];
    }
}
