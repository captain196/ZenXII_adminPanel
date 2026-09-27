<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * FEE GENERATION / RECALCULATION LATENCY AUDIT — STRICTLY READ-ONLY
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Measures the CURRENT canonical billing path before the scholarship
 * correction changes it. Measurement and diagnosis only.
 *
 * NO WRITES. No firestoreSet / firestoreUpdate / firestoreCreate /
 * firestoreDelete / commitBatch / writeDemand / updateDemand /
 * commitDemandBatch / assignInitialFees appears anywhere in this file —
 * grep it before running. No caching, index, query or logic is changed.
 *
 * Read counts and Firestore wall-time come from the REST client's OWN
 * counters (Firestore_rest_client::$probeHttpCalls / $probeHttpMs /
 * $probeCacheHits), so the network-vs-local split is measured, not estimated.
 *
 * Usage:  php scripts/fee_latency_audit.php
 */

define('BASEPATH', __DIR__);
define('APPPATH',  realpath(__DIR__ . '/../application') . DIRECTORY_SEPARATOR);
define('FCPATH',   realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'production');

require_once __DIR__ . '/../vendor/autoload.php';
if (!function_exists('log_message')) { function log_message($l, $m) {} }
if (!function_exists('config_item')) { function config_item($i) { return null; } }

class CI_Controller { public static $instance; }
if (!function_exists('get_instance')) { function &get_instance() { return CI_Controller::$instance; } }

class LatCfg {
    private array $i = [];
    public function load($f, $s = false, $g = true) {
        $p = APPPATH . 'config/' . $f . '.php';
        if (!is_file($p)) return false;
        $config = []; include $p; $this->i = array_merge($this->i, $config); return true;
    }
    public function item($k) { return $this->i[$k] ?? null; }
}
class LatLoader {
    public function __construct(private $h) {}
    public function library($n, $p = null, $a = null) {
        foreach ((is_array($n) ? $n : [$n]) as $x) {
            $c = ucfirst($x); $prop = $a ?: strtolower($x);
            if (isset($this->h->{$prop})) continue;
            $f = APPPATH . 'libraries/' . $c . '.php';
            if (is_file($f)) require_once $f;
            if (class_exists($c)) $this->h->{$prop} = new $c();
        }
        return $this;
    }
    public function helper($n) { return $this; }
    public function model($n) { return $this; }
    public function driver($n, $c = null) { if ($n === 'cache' && !isset($this->h->cache)) $this->h->cache = new LatCacheMiss(); return $this; }
}
class LatCacheMiss { public function get($k) { return false; } public function save($k, $v, $t = 60) { return true; } }

require_once APPPATH . 'libraries/Firebase.php';
require_once APPPATH . 'libraries/Firestore_service.php';
require_once APPPATH . 'libraries/Fee_lifecycle.php';
require_once APPPATH . 'libraries/Fee_firestore_txn.php';

const SCHOOL  = 'SCH_B56BB9A401';
const SESSION = '2026-27';

$host = new stdClass();
CI_Controller::$instance = $host;
$host->config = new LatCfg();
$host->load   = new LatLoader($host);
$firebase = new Firebase();
$host->firebase = $firebase;
$fs = new Firestore_service();
$fs->init(SCHOOL, SESSION, SCHOOL);
$host->fs = $fs;

$raw = $fs->raw_client();
$rc  = new ReflectionClass($raw);

function counters(): array
{
    return [
        'calls' => FirestoreRestClient::$probeHttpCalls,
        'ms'    => FirestoreRestClient::$probeHttpMs,
        'hits'  => FirestoreRestClient::$probeCacheHits,
    ];
}

/** Time an operation N times, reporting median + p95 + measured Firestore split. */
function bench(string $label, int $n, callable $fn, string $note = ''): array
{
    $times = []; $c0 = counters();
    for ($i = 0; $i < $n; $i++) {
        $t = microtime(true);
        $fn($i);
        $times[] = (microtime(true) - $t) * 1000;
    }
    $c1 = counters();
    sort($times);
    $median = $times[(int) floor(count($times) / 2)];
    $p95    = $times[min(count($times) - 1, (int) ceil(0.95 * count($times)) - 1)];
    $calls  = $c1['calls'] - $c0['calls'];
    $fsMs   = $c1['ms']    - $c0['ms'];
    $hits   = $c1['hits']  - $c0['hits'];
    $wall   = array_sum($times);
    $local  = max(0.0, $wall - $fsMs);

    printf("  %-42s n=%-3d median=%8.1fms p95=%8.1fms  reads=%-4d memoHits=%-4d  fsMs=%8.1f localMs=%8.1f (%s)\n",
        $label, $n, $median, $p95, $calls, $hits, $fsMs, $local,
        $fsMs > $local ? 'FIRESTORE-BOUND' : 'LOCAL-BOUND');
    if ($note !== '') printf("      %s\n", $note);

    return ['label' => $label, 'median' => $median, 'p95' => $p95, 'reads' => $calls,
            'fsMs' => $fsMs, 'localMs' => $local, 'cold' => $times[count($times) - 1], 'warm' => $times[0]];
}

function head(string $s) { echo "\n── {$s} " . str_repeat('─', max(0, 62 - strlen($s))) . "\n"; }

echo "════════════════════════════════════════════════════════════════════════\n";
echo " FEE LATENCY AUDIT — READ-ONLY   tenant=" . SCHOOL . "  utc=" . gmdate('c') . "\n";
echo "════════════════════════════════════════════════════════════════════════\n";

// ── 1-6 · Firestore primitives ──────────────────────────────────────────────
head('1-6 · FIRESTORE PRIMITIVES (cold = first call, warm = memo/repeat)');

bench('1. single document read (uncached path)', 8, function ($i) use ($raw) {
    // Alternate doc ids so the request memo cannot serve it — measures the wire.
    $raw->getDocument('sections', SCHOOL . '_' . SESSION . '_Class ' . (($i % 6) + 1) . 'th_Section A');
}, 'alternating ids defeat the request memo, so this is true round-trip cost');

bench('1b. same document read x8 (memo served)', 8, function () use ($fs) {
    $fs->get('sections', SCHOOL . '_' . SESSION . '_Class 9th_Section A');
}, 'repeat reads of ONE doc — shows the request-scoped memo working');

bench('2. query: students by schoolId', 5, function () use ($raw) {
    $raw->query('students', [['schoolId', '==', SCHOOL]], null, 'ASC', 500);
});

bench('3. fee-chart read (feeStructures doc)', 6, function () use ($raw) {
    $raw->getDocument('feeStructures', SCHOOL . '_' . SESSION . '_Class 9th_Section A');
});

bench('4. student identity read', 6, function () use ($raw) {
    $raw->getDocument('students', SCHOOL . '_STU0012');
});

bench('5. scholarshipAwards query (per student)', 6, function () use ($raw) {
    $raw->query('scholarshipAwards', [['studentId', '==', 'STU0012'], ['status', '==', 'active']], null, 'ASC', 50);
}, 'this is the query _getStudentDiscounts() issues for EVERY student');

bench('6. feeDemands query for one student', 6, function () use ($raw) {
    $raw->query('feeDemands', [['schoolId', '==', SCHOOL], ['studentId', '==', 'STU0012']], null, 'ASC', 500);
}, 'this is demandsForStudent() — see the N+1 finding below');

// ── 7 · canonical spec building (READ-ONLY by construction) ─────────────────
head('7 · CANONICAL SPEC BUILDING — buildAdmissionDemandSpecs()');
echo "      READ-ONLY by construction: it BUILDS specs; committing is a separate step\n";
echo "      (Fee_lifecycle::assignInitialFees), which this audit never calls.\n";

$life = new Fee_lifecycle();
$life->init($firebase, SCHOOL, SESSION, 'LAT_AUDIT');
$host->feelifecycle = $life;

$specStat = null;
try {
    $specStat = bench('7. buildAdmissionDemandSpecs (Class 9th/A)', 4, function () use ($life) {
        $life->buildAdmissionDemandSpecs('STU0012', 'Class 9th', 'Section A', true);
    }, 'forPreview=true — identical pricing path, never persists');
    $built = $life->buildAdmissionDemandSpecs('STU0012', 'Class 9th', 'Section A', true);
    echo '      specs produced: ' . count($built['specs'] ?? []) . "  chartFound=" . var_export($built['chartFound'] ?? null, true) . "\n";
} catch (\Throwable $e) {
    echo '      SKIPPED — ' . $e->getMessage() . "\n";
}

// ── 8 · the allocation MATH in isolation (zero I/O) ─────────────────────────
head('8 · SCHOLARSHIP ALLOCATION MATH (pure PHP, zero Firestore)');

function allocateWholeSession(array $specs, float $award): array
{
    $total = 0.0;
    foreach ($specs as $s) $total += $s['gross'];
    if ($total <= 0) return [];
    $effective = min($award, $total);
    $out = []; $sum = 0.0; $last = null;
    foreach ($specs as $id => $s) {
        $share = round($effective * ($s['gross'] / $total), 2);
        $out[$id] = $share; $sum += $share; $last = $id;
    }
    if ($last !== null) $out[$last] = round($out[$last] + ($effective - $sum), 2);
    return $out;
}

foreach ([48, 480, 4800] as $n) {
    $specs = [];
    for ($i = 0; $i < $n; $i++) $specs['D' . $i] = ['gross' => 500.0 + ($i % 7) * 50];
    bench("8. allocateWholeSession over {$n} specs", 20, function () use ($specs) {
        allocateWholeSession($specs, 6000.0);
    });
}

// ── 9-12 · read amplification, measured ─────────────────────────────────────
head('9-12 · READ AMPLIFICATION — the per-period re-query, measured');

$txn = new Fee_firestore_txn();
$txn->init($firebase, $fs, SCHOOL, SESSION);

$b = bench('9. demandsForStudent() x12 (what 12 months costs)', 1, function () use ($txn) {
    for ($m = 0; $m < 12; $m++) $txn->demandsForStudent('STU0012');
}, '_generateDemandsForMonth() calls this ONCE PER MONTH — no memo (Fee_firestore_txn:718)');

$b1 = bench('9b. demandsForStudent() x1 (what it SHOULD cost)', 1, function () use ($txn) {
    $txn->demandsForStudent('STU0012');
});

printf("      amplification factor: %.1fx  (%.0fms wasted per student per generation)\n",
    $b1['median'] > 0 ? $b['median'] / $b1['median'] : 0, max(0, $b['median'] - $b1['median']));

head('BULK SCALING PROJECTION (measured single-student cost × cohort)');
echo "      NOT executed as a real bulk run — generation WRITES, and this audit is read-only.\n";
echo "      Projection uses the MEASURED per-student read cost above.\n";
$per = $b['median'];
foreach ([1, 30, 200] as $cohort) {
    printf("      %-4d students : demandsForStudent alone ≈ %8.1f ms  (%d queries)\n",
        $cohort, $per * $cohort, 12 * $cohort);
}

echo "\n── end · READ-ONLY: no writes, no schema/index/query/logic changes ──\n";
