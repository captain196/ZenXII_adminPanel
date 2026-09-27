<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * PHASE 20.3L — LIVE UAT DRIVER (application code, live Firestore)
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Runs the REAL placement mechanism against the REAL tenant:
 *
 *   MY_Controller::_sectionCapacityExceeded()   ← real, unmodified
 *   MY_Controller::_sectionCapacity()           ← real
 *   MY_Controller::_filterSectionRoster()       ← real
 *   Section_seat_guard::placeStudent/Group()    ← real
 *   Entity_firestore_sync::buildStudentDoc()    ← real
 *   Firebase::firestoreCommitBatchStatus()      ← real
 *   FirestoreRestClient::commitBatchStatus()    ← real
 *   Firestore                                   ← LIVE
 *
 * WHAT IS NOT EXERCISED, AND IS REPORTED AS SUCH: HTTP routing, session auth,
 * CSRF and RBAC. Those sit ABOVE the placement decision and cannot change it.
 *
 * ── ONE SCENARIO PER PROCESS, DELIBERATELY ──────────────────────────────────
 * `_sectionCapacity()` and `_cachedStudentDocsForRollCheck()` memoise on
 * function-level statics with no reset. A production request is one process, so
 * one scenario per process is the faithful model — and the only way each
 * scenario gets a genuinely fresh capacity/occupancy read.
 *
 * ── IDENTITY ────────────────────────────────────────────────────────────────
 * Test students use the fixed, non-allocator prefix `STUUAT…`. The Student ID
 * generator, the SR-number service and the admission-number allocator are NOT
 * invoked by this driver, so no tenant counter is advanced. Every document it
 * creates is enumerable by that prefix, which is what makes cleanup exact.
 *
 * Usage:  php scripts/p203l_live_uat.php <scenario> [args…]
 */

define('BASEPATH', __DIR__);
define('APPPATH',  realpath(__DIR__ . '/../application') . DIRECTORY_SEPARATOR);
define('FCPATH',   realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'production');

require_once __DIR__ . '/../vendor/autoload.php';

$GLOBALS['__log'] = [];
if (!function_exists('log_message')) {
    function log_message($level, $message) { $GLOBALS['__log'][] = "[{$level}] {$message}"; }
}
if (!function_exists('config_item')) { function config_item($i) { return null; } }
if (!function_exists('show_error'))  { function show_error($m, $c = 500) { throw new RuntimeException($m); } }

// ── CI3 shims: only the loader/controller plumbing, never business logic ─────
class CI_Controller
{
    public static $instance;
    public function __construct() { self::$instance = $this; }
}
if (!function_exists('get_instance')) {
    function &get_instance() { return CI_Controller::$instance; }
}

/** Reads the REAL config files, so the feature flag on disk is the authority. */
class UatConfig
{
    private array $items = [];
    public function load($file, $section = false, $failGracefully = true)
    {
        $path = APPPATH . 'config/' . $file . '.php';
        if (!is_file($path)) return false;
        $config = [];
        include $path;
        $this->items = array_merge($this->items, $config);
        return true;
    }
    public function item($key) { return $this->items[$key] ?? null; }
}

/** Real library loading — `load->library('x', null, 'alias')` must actually work. */
class UatLoader
{
    private $host;
    public function __construct($host) { $this->host = $host; }
    public function library($name, $params = null, $alias = null)
    {
        $names = is_array($name) ? $name : [$name];
        foreach ($names as $n) {
            $cls   = ucfirst($n);
            $prop  = $alias ?: strtolower($n);
            if (isset($this->host->{$prop})) continue;
            $file = APPPATH . 'libraries/' . $cls . '.php';
            if (is_file($file)) require_once $file;
            if (class_exists($cls)) $this->host->{$prop} = new $cls();
        }
        return $this;
    }
    public function helper($n) { return $this; }
    public function model($n)  { return $this; }
    public function driver($n, $c = null)
    {
        // The roll/capacity snapshot asks for the file cache. Serving a MISS
        // forces a LIVE `schoolWhere('students')` read — the freshest possible
        // occupancy, which is what a UAT wants.
        if ($n === 'cache' && !isset($this->host->cache)) $this->host->cache = new UatCacheMiss();
        return $this;
    }
}
class UatCacheMiss
{
    public function get($k)               { return false; }
    public function save($k, $v, $t = 60) { return true; }
    public function delete($k)            { return true; }
}

require_once APPPATH . 'libraries/Firebase.php';
require_once APPPATH . 'libraries/Firestore_service.php';
require_once APPPATH . 'libraries/Entity_firestore_sync.php';
require_once APPPATH . 'libraries/Section_seat_guard.php';
require_once APPPATH . 'core/MY_Controller.php';
require_once APPPATH . 'controllers/Sis.php';

const SCHOOL  = 'SCH_B56BB9A401';        // the ONLY authorized tenant
const SESSION = '2026-27';
const PREFIX  = 'STUUAT';                // every document this driver creates

// ── build the real object graph ─────────────────────────────────────────────
$rc  = new ReflectionClass('Sis');
$app = $rc->newInstanceWithoutConstructor();
CI_Controller::$instance = $app;

function put($obj, string $prop, $val): void
{
    $rc = new ReflectionClass($obj);
    while ($rc && !$rc->hasProperty($prop)) $rc = $rc->getParentClass();
    if ($rc) { $p = $rc->getProperty($prop); $p->setAccessible(true); $p->setValue($obj, $val); }
    else     { $obj->{$prop} = $val; }
}
function call($obj, string $method, array $args = [])
{
    $m = new ReflectionMethod(get_class($obj), $method);
    $m->setAccessible(true);
    return $m->invoke($obj, ...$args);
}

put($app, 'config', new UatConfig());
put($app, 'load',   new UatLoader($app));

$firebase = new Firebase();
put($app, 'firebase', $firebase);

$fs = new Firestore_service();
$fs->init(SCHOOL, SESSION, SCHOOL);
put($app, 'fs', $fs);

$sync = new Entity_firestore_sync();
$sync->init($firebase, SCHOOL, SESSION, SCHOOL);
put($app, 'entity_sync', $sync);

put($app, 'school_id',    SCHOOL);
put($app, 'parent_db_key', SCHOOL);
put($app, 'school_name',  'Vikrant Public');
put($app, 'session_year', SESSION);
put($app, 'admin_id',     'P203L_UAT');

$guard = new Section_seat_guard();
$guard->init($fs, $firebase, SCHOOL, 'P203L_UAT');
put($app, 'seatGuard', $guard);

// ── helpers ─────────────────────────────────────────────────────────────────
function line(string $s = '') { echo $s . "\n"; }
function head(string $s) { line(); line("── {$s} " . str_repeat('─', max(0, 62 - strlen($s)))); }

/** A minimal but REAL student payload, shaped like the import/admission writes. */
function testStudent(string $id, string $class, string $section): array
{
    return [
        'studentId'  => $id,
        'userId'     => $id,
        'name'       => 'P203L UAT ' . $id,
        'className'  => $class,
        'Class'      => $class,
        'section'    => $section,
        'Section'    => $section,
        'sectionKey' => "{$class}/{$section}",
        'session'    => SESSION,
        'status'     => 'Active',
        'Status'     => 'Active',
        'schoolId'   => SCHOOL,
        'p203lUat'   => true,                 // cleanup marker
    ];
}

function group(string $class, string $section, int $from, int $to): array
{
    $g = [];
    for ($i = $from; $i <= $to; $i++) {
        $id = PREFIX . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        $g[] = ['studentId' => $id, 'data' => testStudent($id, $class, $section)];
    }
    return $g;
}

function docExists($fs, string $studentId): bool
{
    return is_array($fs->get('students', SCHOOL . '_' . $studentId));
}

function reportGroup($app, $fs, array $g, string $class, string $section, array $res): bool
{
    line('guard result   : ' . json_encode($res));
    $present = $absent = [];
    foreach ($g as $s) {
        if (docExists($fs, $s['studentId'])) $present[] = $s['studentId'];
        else                                 $absent[]  = $s['studentId'];
    }
    line('documents present: ' . (count($present) ? implode(', ', $present) : '(none)'));
    line('documents absent : ' . (count($absent)  ? implode(', ', $absent)  : '(none)'));
    $placed  = ($res['status'] ?? '') === 'PLACED';
    $atomic  = $placed ? (count($absent) === 0) : (count($present) === 0);
    line('ATOMICITY (all-or-nothing): ' . ($atomic ? 'PASS' : '*** FAIL ***'));
    return $atomic;
}

$scenario = $argv[1] ?? 'help';
$class    = $argv[2] ?? 'Class 10th';
$section  = $argv[3] ?? 'Section F';

line("════════════════════════════════════════════════════════════════════════");
line(" P20.3L LIVE UAT · scenario={$scenario} · {$class}/{$section} · " . gmdate('c'));
line(" tenant=" . SCHOOL . "  flag(atomic_capacity_enabled)=" . var_export(Section_seat_guard::enabled(), true)
    . "  groupMax=" . Section_seat_guard::groupMaxStudents());
line("════════════════════════════════════════════════════════════════════════");

switch ($scenario) {

// ── state ───────────────────────────────────────────────────────────────────
case 'state': {
    $cap = call($app, '_sectionCapacity', [$class, $section, SESSION]);
    $occ = call($app, '_sectionActiveCount', [$class, $section, SESSION]);
    $doc = $fs->get('sections', $fs->sectionDocId($class, $section, SESSION));
    line("effective capacity : {$cap}");
    line("live occupancy     : {$occ}");
    line('remaining          : ' . ($cap - $occ));
    line('maxStrength field  : ' . var_export($doc['maxStrength'] ?? null, true));
    line('__updateTime       : ' . ($doc['__updateTime'] ?? '?'));
    line('seatCasAt          : ' . ($doc['seatCasAt'] ?? '(absent)'));
    break;
}

// ── UAT fixture: set / clear the section's configured capacity ──────────────
// Every section in this tenant ships WITHOUT maxStrength, so the effective
// capacity is the D17 default of 40 and occupancy is 0-3. Testing "at" and
// "beyond" capacity would otherwise need ~40 real student documents. Setting a
// small maxStrength on ONE empty test section is the same field the Sections &
// Seats capacity editor writes, and `clearcap` restores the pre-UAT state.
case 'setcap': {
    $n     = (int) ($argv[4] ?? 3);
    $secId = $fs->sectionDocId($class, $section, SESSION);
    $before = $fs->get('sections', $secId);
    line('maxStrength before : ' . var_export($before['maxStrength'] ?? null, true));
    $res = $firebase->firestoreCommitBatchStatus([[
        'op' => 'update', 'collection' => 'sections', 'docId' => $secId,
        'merge' => true, 'data' => ['maxStrength' => $n],
    ]]);
    line('write              : ' . json_encode($res));
    $after = $fs->get('sections', $secId);
    line('maxStrength after  : ' . var_export($after['maxStrength'] ?? null, true));
    break;
}
// Exact restoration: REMOVE the two fields the UAT introduced (maxStrength and
// the seatCasAt marker) rather than zeroing them, so the section document is
// byte-equivalent to its pre-UAT shape. Only these two field paths are in the
// update mask — no other field on the document can be touched.
case 'restore': {
    $secId = $fs->sectionDocId($class, $section, SESSION);
    $before = $fs->get('sections', $secId);
    line('before: maxStrength=' . var_export($before['maxStrength'] ?? null, true)
        . '  seatCasAt=' . var_export($before['seatCasAt'] ?? null, true));
    // NOTE: FirestoreRestClient::setDocumentWithDeletes() cannot express a
    // DELETE-ONLY patch — with $setData empty it encodes `fields` as a JSON
    // list and Firestore rejects it with INVALID_ARGUMENT (registered as
    // P20.3L-F2, NOT fixed here: out of scope per the brief). The cleanup
    // therefore issues the equivalent PATCH itself, in this script only.
    $client = $fs->raw_client();
    $rm = new ReflectionMethod(get_class($client), 'baseUrl'); $rm->setAccessible(true);
    $url = $rm->invoke($client) . '/sections/' . rawurlencode($secId)
         . '?updateMask.fieldPaths=maxStrength&updateMask.fieldPaths=seatCasAt';
    $rq = new ReflectionMethod(get_class($client), 'request'); $rq->setAccessible(true);
    $r  = $rq->invoke($client, 'PATCH', $url, ['fields' => new stdClass()]);
    line('field delete: HTTP ' . ($r['code'] ?? '?'));
    $after = $fs->get('sections', $secId);
    line('after : maxStrength=' . var_export($after['maxStrength'] ?? null, true)
        . '  seatCasAt=' . var_export($after['seatCasAt'] ?? null, true));
    line('effective capacity now: ' . call($app, '_sectionCapacity', [$class, $section, SESSION]) . ' (D17 default)');
    break;
}
case 'clearcap': {
    $secId = $fs->sectionDocId($class, $section, SESSION);
    // 0 restores the pre-UAT effective capacity: D17 treats absent / 0 /
    // negative identically, so the section is back to the default 40.
    $res = $firebase->firestoreCommitBatchStatus([[
        'op' => 'update', 'collection' => 'sections', 'docId' => $secId,
        'merge' => true, 'data' => ['maxStrength' => 0],
    ]]);
    line('reset maxStrength to 0 (D17: absent/0/negative → default 40): ' . json_encode($res));
    break;
}

// ── A: normal placement below capacity ──────────────────────────────────────
case 'below': {
    $n = (int) ($argv[4] ?? 1);
    $g = group($class, $section, 1, $n);
    $cap = call($app, '_sectionCapacity', [$class, $section, SESSION]);
    $occ = call($app, '_sectionActiveCount', [$class, $section, SESSION]);
    head("A · below capacity (cap={$cap} occ={$occ} incoming={$n})");
    $over = call($app, '_sectionCapacityExceeded', [$class, $section, SESSION, $n]);
    line('capacity gate  : ' . ($over === null ? 'ALLOW' : 'REFUSE ' . json_encode($over)));
    if ($over !== null) { line('*** unexpected refusal — nothing attempted'); break; }
    $res = $guard->placeStudentGroup($sync, $g, $class, $section, SESSION);
    reportGroup($app, $fs, $g, $class, $section, $res);
    break;
}

// ── B: exactly at remaining capacity ────────────────────────────────────────
case 'exact': {
    $cap = call($app, '_sectionCapacity', [$class, $section, SESSION]);
    $occ = call($app, '_sectionActiveCount', [$class, $section, SESSION]);
    $n   = $cap - $occ;
    head("B · exactly at remaining capacity (cap={$cap} occ={$occ} incoming={$n})");
    if ($n < 1) { line('no headroom — nothing attempted'); break; }
    $g = group($class, $section, 10, 9 + $n);
    $over = call($app, '_sectionCapacityExceeded', [$class, $section, SESSION, $n]);
    line('capacity gate  : ' . ($over === null ? 'ALLOW' : 'REFUSE ' . json_encode($over)));
    if ($over !== null) { line('*** unexpected refusal'); break; }
    $res = $guard->placeStudentGroup($sync, $g, $class, $section, SESSION);
    reportGroup($app, $fs, $g, $class, $section, $res);
    break;
}

// ── C: one seat beyond remaining capacity ───────────────────────────────────
case 'over': {
    $cap = call($app, '_sectionCapacity', [$class, $section, SESSION]);
    $occ = call($app, '_sectionActiveCount', [$class, $section, SESSION]);
    $n   = max(1, $cap - $occ + 1);
    head("C · one beyond capacity (cap={$cap} occ={$occ} incoming={$n})");
    $g = group($class, $section, 30, 29 + $n);
    $over = call($app, '_sectionCapacityExceeded', [$class, $section, SESSION, $n]);
    line('capacity gate  : ' . ($over === null ? '*** ALLOW (WRONG)' : 'REFUSE ' . json_encode($over)));
    line('operator message: ' . ($over ? call($app, '_sectionCapacityMessage', [$over, $class, $section]) : '—'));
    $present = [];
    foreach ($g as $s) if (docExists($fs, $s['studentId'])) $present[] = $s['studentId'];
    line('documents created by the refusal: ' . (count($present) ? implode(', ', $present) : '(none)'));
    line('NO-PARTIAL-WRITE: ' . (empty($present) && $over !== null ? 'PASS' : '*** FAIL ***'));
    break;
}

// ── group larger than the configured max ────────────────────────────────────
case 'overmax': {
    $max = Section_seat_guard::groupMaxStudents();
    $n   = $max + 1;
    head("group of {$n} vs max {$max} · expect refusal, never chunking");
    $g = group($class, $section, 1000, 999 + $n);
    $res = $guard->placeStudentGroup($sync, $g, $class, $section, SESSION);
    line('guard result : ' . json_encode($res));
    // Sampling is sufficient and honest: a chunked write would have committed
    // the FIRST $max ids, so the first id is the decisive probe.
    $probe = [$g[0]['studentId'], $g[1]['studentId'], $g[$max - 1]['studentId']];
    $found = array_values(array_filter($probe, fn($id) => docExists($fs, $id)));
    line('probe ids    : ' . implode(', ', $probe));
    line('present      : ' . (count($found) ? implode(', ', $found) : '(none)'));
    line('NOT CHUNKED  : ' . (empty($found) && ($res['status'] ?? '') === 'FAILURE' ? 'PASS' : '*** FAIL ***'));
    break;
}

// ── D: CAS conflict — commit against a deliberately stale section version ───
case 'stale': {
    head('D · CAS conflict via a stale section version');
    $secId = $fs->sectionDocId($class, $section, SESSION);
    $before = $fs->get('sections', $secId);
    line('U1 = ' . ($before['__updateTime'] ?? '?'));

    // Move the section forward the way a competing writer would.
    $bump = $firebase->firestoreCommitBatchStatus([[
        'op' => 'update', 'collection' => 'sections', 'docId' => $secId,
        'merge' => true, 'data' => ['seatCasAt' => date('c')],
    ]]);
    line('competing writer: ' . json_encode($bump));

    // commitPlacement() re-reads the section itself, so to make the guard use a
    // STALE precondition the read memo must still hold the pre-bump document.
    // That is exactly the production race: the guard read the section, another
    // writer committed, then the guard committed.
    $g   = group($class, $section, 40, 41);
    $res = $guard->commitPlacement($class, $section, SESSION, array_map(
        function ($s) use ($sync) {
            $b = $sync->buildStudentDoc($s['studentId'], $s['data']);
            return ['collection' => 'students', 'docId' => $b['docId'], 'data' => $b['doc'], 'merge' => true];
        }, $g));
    line('NOTE: the guard re-read the section, so this commit uses the CURRENT');
    line('      version — see scenario `race` for the real two-writer conflict.');
    reportGroup($app, $fs, $g, $class, $section, $res);
    break;
}

// ── the real race: two writers, one section, same starting version ──────────
case 'race': {
    // Invoked as: race <class> <section> <writerId>
    $writer = (string) ($argv[4] ?? 'A');
    $barrier = (float) ($argv[5] ?? 0);   // unix ts both writers commit at
    $g = group($class, $section, $writer === 'A' ? 50 : 60, $writer === 'A' ? 51 : 61);

    $secId = $fs->sectionDocId($class, $section, SESSION);
    $doc   = $fs->get('sections', $secId);          // both writers read here
    $U     = (string) ($doc['__updateTime'] ?? '');

    $cap = call($app, '_sectionCapacity', [$class, $section, SESSION]);
    $occ = call($app, '_sectionActiveCount', [$class, $section, SESSION]);
    $over = call($app, '_sectionCapacityExceeded', [$class, $section, SESSION, count($g)]);

    line("WRITER {$writer}");
    line("  base version : {$U}");
    line("  capacity gate: " . ($over === null ? 'ALLOW' : 'REFUSE') . " (cap={$cap} occ={$occ} incoming=" . count($g) . ')');

    // The capacity gate is HONOURED, exactly as the placement paths honour it.
    // A test that bypassed it would prove nothing about the application path.
    if ($over !== null) {
        line('  commit       : NOT ATTEMPTED — capacity gate refused');
        line("RESULT_LINE {$writer} GATE_REFUSED");
        break;
    }

    // Both processes have now read the same occupancy and the same section
    // version, and both believe there is room. They commit at the same instant.
    // PRE-FIX this is exactly how occupancy exceeded capacity: two writers,
    // one decision each, no arbitration between them.
    if ($barrier > 0) { $u = ($barrier - microtime(true)) * 1e6; if ($u > 0) usleep((int) $u); }

    $writes = [];
    foreach ($g as $s) {
        $b = $sync->buildStudentDoc($s['studentId'], $s['data']);
        $writes[] = ['collection' => 'students', 'docId' => $b['docId'], 'data' => $b['doc'], 'merge' => true];
    }
    $writes[] = ['op' => 'update', 'collection' => 'sections', 'docId' => $secId, 'merge' => true,
                 'data' => ['seatCasAt' => date('c')], 'precondition' => ['updateTime' => $U]];

    $res = $firebase->firestoreCommitBatchStatus($writes);

    line("  commit       : " . json_encode($res));
    line("  ids          : " . implode(', ', array_column($g, 'studentId')));
    line("RESULT_LINE {$writer} " . ($res['status'] ?? '?'));
    break;
}

// ── enumerate / clean up everything this driver created ─────────────────────
// The `p203lUat` marker does NOT survive: syncStudent() writes a whitelisted
// document shape and drops unknown fields. The `STUUAT` studentId prefix is
// therefore the ONLY cleanup key — and it is unambiguous, because the Student
// ID generator only ever mints `STU<digits>`.
case 'list':
case 'cleanup': {
    $rows = $fs->schoolWhere('students');
    $mine = [];
    foreach ($rows as $r) {
        $d  = is_array($r['data'] ?? null) ? $r['data'] : $r;
        $id = (string) ($d['studentId'] ?? '');
        if ($id !== '' && strpos($id, PREFIX) === 0) $mine[$id] = $d;
    }
    ksort($mine);
    line('tenant student documents : ' . count($rows));
    line('UAT-created (STUUAT…)    : ' . count($mine));
    foreach ($mine as $id => $d) {
        line('  ' . str_pad($id, 12) . ($d['className'] ?? '') . ' / ' . ($d['section'] ?? '')
            . '   status=' . ($d['status'] ?? '?'));
    }
    if ($scenario === 'cleanup') {
        line();
        foreach ($mine as $id => $d) {
            // Never delete anything that is not BOTH prefixed and confirmed
            // present in this tenant's own scoped query. Pre-existing student
            // data (STU0012…STU0163) can never match the prefix.
            $ok = $sync->deleteStudent($id);
            line("  deleted {$id} : " . var_export($ok, true));
        }
    }
    break;
}

default:
    line('scenarios: state | below N | exact | over | overmax | stale | race <W> <ts> | list | cleanup');
}

head('log lines emitted');
foreach ($GLOBALS['__log'] as $l) line('  ' . $l);
