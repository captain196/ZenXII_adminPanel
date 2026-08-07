<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Superadmin_perf_probe — F6 read-only base-latency instrumentation.
 *
 * Measures the per-request platform floor with REAL microtime deltas (no
 * modeled estimates). STRICTLY READ-ONLY: performs only tiny null/404 doc
 * reads + the normal OAuth token fetch (existing caching behavior). NO
 * writes to any business collection. Does NOT touch or modify any existing
 * request path — it is an isolated, additive measurement controller.
 *
 * CLI only:  php index.php superadmin_perf_probe run
 * Run several times to expose cold-vs-warm token and any per-request Kreait
 * token acquisition.
 *
 * F6 ARTIFACT — remove after measurement; not production routing.
 */
class Superadmin_perf_probe extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!defined('STDIN')) { exit("CLI only.\n"); }
    }

    private function _safe(callable $fn)
    {
        try { return $fn(); } catch (\Throwable $e) { return null; }
    }

    public function run()
    {
        $ms = function ($a, $b) { return round(($b - $a) * 1000, 1); };
        $t0     = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $tEntry = microtime(true);

        echo "===================================================================\n";
        echo "F6 Base-Latency Probe — real measured timings (ms)\n";
        echo "Run multiple times to expose cold/warm token + Kreait per-request.\n";
        echo "===================================================================\n";

        // [1+6+7+9 as actually paid] bootstrap -> controller entry
        // (CI core + autoloaded Firebase __construct + autoloaded session).
        printf("  [1] bootstrap->controller (incl. autoloaded Firebase+session): %s ms\n", $ms($t0, $tEntry));

        // [4] Service-account JSON load (raw read + decode).
        $saPath = APPPATH . 'config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
        $a = microtime(true);
        $json = @file_get_contents($saPath);
        $sa   = ($json !== false) ? json_decode($json, true) : null;
        $b = microtime(true);
        printf("  [4] service-account JSON load+decode: %s ms (%s)\n", $ms($a, $b), is_array($sa) ? 'ok' : 'FAILED');

        // [2] Firebase library construction (fresh instance: Kreait factory +
        //     createDatabase + createAuth + FirestoreRestClient + StorageClient).
        $a = microtime(true);
        $fb = $this->_safe(function () { return new Firebase(); });
        $b = microtime(true);
        printf("  [2] Firebase::__construct (Kreait + REST + Storage): %s ms %s\n", $ms($a, $b), $fb ? '' : '(FAILED)');
        if (!$fb) { echo "  ABORT: Firebase construct failed.\n"; return; }

        // [3] Firestore REST client construct (isolated).
        $a = microtime(true);
        $rc = $this->_safe(function () use ($saPath) { return new FirestoreRestClient($saPath, 'graderadmin', '(default)'); });
        $b = microtime(true);
        printf("  [3] FirestoreRestClient::__construct: %s ms %s\n", $ms($a, $b), $rc ? '' : '(FAILED)');

        // [5] Firestore OAuth token cache state BEFORE first call (predict HIT/MISS).
        $tokFile = APPPATH . 'cache/firebase_auth/oauth_token.json';
        $state = 'MISS (no cache file)';
        if (is_file($tokFile)) {
            $env = json_decode(@file_get_contents($tokFile), true);
            $exp = is_array($env) ? (int) ($env['expires_at'] ?? 0) : 0;
            $state = ($exp > time() + 60) ? ('HIT (expires_in=' . ($exp - time()) . 's)') : 'MISS/expired';
        }
        printf("  [5] Firestore OAuth token cache (pre-call state): %s\n", $state);

        // [8] Firestore REST: call #1 forces getAccessToken (+RTT); call #2 is
        //     token-warm (per-request memo) -> pure RTT. #1 - #2 ~= token cost.
        $a = microtime(true);  $this->_safe(function () use ($fb) { return $fb->firestoreGet('analyticsRollups', '__perf_probe_noop'); });  $b = microtime(true);
        $fs1 = $ms($a, $b);
        $a = microtime(true);  $this->_safe(function () use ($fb) { return $fb->firestoreGet('analyticsRollups', '__perf_probe_noop'); });  $b = microtime(true);
        $fs2 = $ms($a, $b);
        printf("  [8] Firestore REST call #1 (token + RTT): %s ms\n", $fs1);
        printf("  [8] Firestore REST call #2 (token-warm RTT): %s ms\n", $fs2);
        printf("      -> Firestore token cost (#1 - #2 ~=): %s ms\n", round($fs1 - $fs2, 1));

        // [6+7] Kreait RTDB: call #1 triggers Kreait's lazy token (SEPARATE
        //       system from [5]); call #2 is RTDB RTT, token-warm in-request.
        //       #1 - #2 ~= Kreait token cost. Across separate runs, a
        //       consistently slow #1 == per-request Kreait token (hypothesis).
        $a = microtime(true);  $this->_safe(function () use ($fb) { return $fb->get('System/Perf/__perf_probe_noop'); });  $b = microtime(true);
        $rt1 = $ms($a, $b);
        $a = microtime(true);  $this->_safe(function () use ($fb) { return $fb->get('System/Perf/__perf_probe_noop'); });  $b = microtime(true);
        $rt2 = $ms($a, $b);
        printf("  [7] Kreait RTDB call #1 (Kreait token + RTT): %s ms\n", $rt1);
        printf("  [7] Kreait RTDB call #2 (RTDB RTT, token-warm): %s ms\n", $rt2);
        printf("      -> Kreait token cost (#1 - #2 ~=): %s ms   <== KREAIT HYPOTHESIS\n", round($rt1 - $rt2, 1));

        // [9] Session library load (autoloaded already -> expect near-zero).
        $a = microtime(true);  $this->load->library('session');  $b = microtime(true);
        printf("  [9] Session library load (re-load, cached): %s ms\n", $ms($a, $b));

        // [10] Controller execution span.
        printf("  [10] probe controller execution (entry->here): %s ms\n", $ms($tEntry, microtime(true)));
        printf("  TOTAL (request start->here): %s ms\n", $ms($t0, microtime(true)));

        echo "===================================================================\n";
        echo "Interpretation:\n";
        echo "  - Fixed floor (page-independent/request) ~= [1].\n";
        echo "  - Cold-token spike = large [8] #1 with MISS state at [5].\n";
        echo "  - Kreait per-request token = [7] (#1 - #2); if consistently\n";
        echo "    ~500-1000ms across separate runs, the hypothesis is CONFIRMED;\n";
        echo "    if near 0, Kreait is NOT re-fetching per request.\n";
        echo "===================================================================\n";
    }
}
