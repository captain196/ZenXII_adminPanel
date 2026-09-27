<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * PHASE 20.3M — LIVE IMPORT UAT DRIVER
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Runs the REAL `Sis::_runStudentImport()` — the whole 600-line method, with
 * REAL identity allocation (`Id_generator`), REAL SR-number and APAAR services,
 * REAL fee lifecycle, the REAL `Section_seat_guard`, and LIVE Firestore.
 *
 * Not exercised, and not claimed: HTTP routing, session auth, CSRF, RBAC.
 *
 * ── SAFETY ──────────────────────────────────────────────────────────────────
 * Tenant `SCH_B56BB9A401` only. Every student this driver creates carries the
 * name prefix `P203M UAT`, and every fixture document it writes is listed by
 * `fixtures`. Student IDs come from the REAL generator, so they are ordinary
 * `STU####` values — the name marker, not the id shape, is the cleanup key.
 *
 * Usage:  php scripts/p203m_live_import_uat.php <scenario> [args…]
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
if (!function_exists('log_audit'))   { function log_audit(...$a) { return true; } }

class CI_Controller { public static $instance; public function __construct() { self::$instance = $this; } }
if (!function_exists('get_instance')) { function &get_instance() { return CI_Controller::$instance; } }

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

class UatLoader
{
    private $host;
    public function __construct($host) { $this->host = $host; }
    public function library($name, $params = null, $alias = null)
    {
        foreach ((is_array($name) ? $name : [$name]) as $n) {
            $cls  = ucfirst($n);
            $prop = $alias ?: strtolower($n);
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
        if ($n === 'cache' && !isset($this->host->cache)) $this->host->cache = new UatCacheMiss();
        return $this;
    }
}
class UatCacheMiss
{
    public function get($k) { return false; }
    public function save($k, $v, $t = 60) { return true; }
    public function delete($k) { return true; }
}
class UatSession
{
    public function userdata($k = null) { return $k === 'admin_name' ? 'P203M UAT Runner' : null; }
    public function set_userdata($k, $v = null) { return true; }
    public function __call($n, $a) { return null; }
}

require_once APPPATH . 'libraries/Firebase.php';
require_once APPPATH . 'libraries/Firestore_service.php';
require_once APPPATH . 'libraries/Entity_firestore_sync.php';
require_once APPPATH . 'libraries/Section_seat_guard.php';
require_once APPPATH . 'libraries/Id_generator.php';
require_once APPPATH . 'core/MY_Controller.php';
require_once APPPATH . 'controllers/Sis.php';

const SCHOOL   = 'SCH_B56BB9A401';
const SESSION  = '2026-27';
const MARKER   = 'P203M UAT';                 // name prefix on every test student
const SRC_FEE  = 'SCH_B56BB9A401_2026-27_Class 9th_Section A';   // fixture template

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
put($app, 'session', new UatSession());

$firebase = new Firebase();
put($app, 'firebase', $firebase);

$fs = new Firestore_service();
$fs->init(SCHOOL, SESSION, SCHOOL);
put($app, 'fs', $fs);

$sync = new Entity_firestore_sync();
$sync->init($firebase, SCHOOL, SESSION, SCHOOL);
put($app, 'entity_sync', $sync);

// MY_Controller:1621 — canonical: school_name IS school_id. The fee-structure
// docId is built from school_name, so getting this wrong silently aborts every
// import row with "no fee structure".
put($app, 'school_id',     SCHOOL);
put($app, 'school_name',   SCHOOL);
put($app, 'parent_db_key', SCHOOL);
put($app, 'session_year',  SESSION);
put($app, 'admin_id',      'P203M_UAT');

$idGen = new Id_generator();
put($app, 'id_generator', $idGen);

$guard = new Section_seat_guard();
$guard->init($fs, $firebase, SCHOOL, 'P203M_UAT');
put($app, 'seatGuard', $guard);

// Sis::__construct() (:69, :75) and MY_Controller (:281) normally load these.
// The driver skips both constructors, so they must be loaded explicitly —
// otherwise the post-write block fatals on a null collaborator AFTER the group
// has already committed.
$app->load->library('Fee_lifecycle', null, 'feeLifecycle');
$app->load->library('Student_history_service', null, 'studentHistorySvc');
$app->load->library('Tenant_capability_service', null, 'capability');
$app->load->library('Sr_no_service', null, 'srnoSvc');
$app->load->library('Apaar_service', null, 'apaarSvc');
// Sis::__construct():71 initialises the fee lifecycle. Without it every
// assignInitialFees() call fails on an uninitialised typed property.
$app->feeLifecycle->init($firebase, SCHOOL, SESSION, 'P203M_UAT');

function line(string $s = '') { echo $s . "\n"; }
function head(string $s) { line(); line("── {$s} " . str_repeat('─', max(0, 62 - strlen($s)))); }

function feeDocId(string $class, string $section): string
{
    return SCHOOL . '_' . SESSION . '_' . $class . '_' . $section;
}

/** Snapshot of the STU allocator: pointer value + which claim docs exist. */
function idState($firebase, array $probe = []): array
{
    $ptr = $firebase->firestoreGet('feeCounters', '_sys_STU');
    $out = ['pointer' => is_array($ptr) ? (int) ($ptr['value'] ?? 0) : 0, 'claims' => []];
    foreach ($probe as $n) {
        $out['claims'][$n] = is_array($firebase->firestoreGet('feeCounters', "_sys_STU_claim_{$n}"));
    }
    return $out;
}

/** Every student this driver has created, by the name marker. */
function uatStudents($fs): array
{
    $rows = $fs->schoolWhere('students');
    $out = [];
    foreach ($rows as $r) {
        $d = is_array($r['data'] ?? null) ? $r['data'] : $r;
        if (strpos((string) ($d['name'] ?? ''), MARKER) === 0) $out[(string) $d['studentId']] = $d;
    }
    ksort($out);
    return $out;
}

function importRows(string $class, string $section, int $n, int $seed): array
{
    $rows = [];
    for ($i = 1; $i <= $n; $i++) {
        $k = $seed + $i;
        $rows[] = [
            'Name'        => MARKER . " {$k}",
            'Father Name' => 'UAT Father',
            'Class'       => $class,
            'Section'     => $section,
            'Phone'       => sprintf('9%09d', 800000000 + $k),
            'DOB'         => '2015-01-01',
        ];
    }
    return $rows;
}

function runImport($app, array $rows): array
{
    return call($app, '_runStudentImport', [$rows, ['dupPolicy' => 'skip', 'importMode' => 'new']]);
}

$scenario = $argv[1] ?? 'help';
$class    = $argv[2] ?? 'Class 10th';
$section  = $argv[3] ?? 'Section F';

line("════════════════════════════════════════════════════════════════════════");
line(" P20.3M LIVE IMPORT UAT · scenario={$scenario} · " . gmdate('c'));
line(" tenant=" . SCHOOL . "  flag=" . var_export(Section_seat_guard::enabled(), true)
    . "  groupMax=" . Section_seat_guard::groupMaxStudents());
line("════════════════════════════════════════════════════════════════════════");

switch ($scenario) {

case 'fixtures': {
    $rows = $fs->schoolWhere('feeStructures');
    line('feeStructures in tenant: ' . count($rows));
    foreach ($rows as $r) {
        $d = is_array($r['data'] ?? null) ? $r['data'] : $r;
        line('  ' . ($r['id'] ?? '?') . '   heads=' . count((array) ($d['feeHeads'] ?? []))
            . '  uatFixture=' . var_export($d['p203mFixture'] ?? null, true));
    }
    break;
}

// Clone the tenant's ONE real fee structure onto another destination so that
// Import can reach it at all. Stamped `p203mFixture` so removal is exact.
case 'mkfixture': {
    $src = $fs->get('feeStructures', SRC_FEE);
    if (!is_array($src)) { line('FATAL: template fee structure missing'); break; }
    $doc = $src;
    unset($doc['__updateTime']);
    $doc['className']    = $class;
    $doc['section']      = $section;
    $doc['p203mFixture'] = true;
    $id = feeDocId($class, $section);
    $ok = $firebase->firestoreSet('feeStructures', $id, $doc, true);
    line("created feeStructures/{$id} : " . var_export($ok, true)
        . '  heads=' . count((array) ($doc['feeHeads'] ?? [])));
    break;
}
case 'rmfixture': {
    $id = feeDocId($class, $section);
    $cur = $fs->get('feeStructures', $id);
    if (!is_array($cur)) { line("feeStructures/{$id} already absent"); break; }
    if (($cur['p203mFixture'] ?? false) !== true) {
        line("REFUSING to delete feeStructures/{$id} — not stamped p203mFixture");
        break;
    }
    line("deleted feeStructures/{$id} : " . var_export($firebase->firestoreDelete('feeStructures', $id), true));
    break;
}

// Full identity surface, so a refused group can be MEASURED rather than argued:
// Student IDs, SR numbers, admission numbers, APAAR and student documents.
case 'idstate': {
    $st = idState($firebase);
    line('STU pointer      = ' . $st['pointer']);
    foreach (['srNoIndex', 'admissionNoIndex', 'apaarIndex'] as $col) {
        line(str_pad($col, 17) . '= ' . count($fs->schoolWhere($col)) . ' docs');
    }
    line('student documents= ' . count($fs->schoolWhere('students')));
    break;
}

case 'state': {
    line('effective capacity : ' . call($app, '_sectionCapacity', [$class, $section, SESSION]));
    line('live occupancy     : ' . call($app, '_sectionActiveCount', [$class, $section, SESSION]));
    $doc = $fs->get('sections', $fs->sectionDocId($class, $section, SESSION));
    line('maxStrength        : ' . var_export($doc['maxStrength'] ?? null, true));
    line('__updateTime       : ' . ($doc['__updateTime'] ?? '?'));
    line('fee structure      : ' . (is_array($fs->get('feeStructures', feeDocId($class, $section))) ? 'PRESENT' : 'MISSING'));
    break;
}

case 'setcap': {
    $n = (int) ($argv[4] ?? 3);
    $r = $firebase->firestoreCommitBatchStatus([[
        'op' => 'update', 'collection' => 'sections',
        'docId' => $fs->sectionDocId($class, $section, SESSION),
        'merge' => true, 'data' => ['maxStrength' => $n],
    ]]);
    line("maxStrength := {$n} : " . json_encode($r));
    break;
}

// ── the actual live import ──────────────────────────────────────────────────
// import <class> <section> <count> <seed>
case 'import': {
    $n       = (int) ($argv[4] ?? 2);
    $seed    = (int) ($argv[5] ?? 0);
    // Optional wall-clock barrier: two processes given the same timestamp both
    // enter _runStudentImport() at the same instant, so both read the same
    // destination-section version and both attempt the atomic commit. That is
    // the live Import-vs-Import CAS conflict.
    $barrier = (float) ($argv[6] ?? 0);

    $cap = call($app, '_sectionCapacity', [$class, $section, SESSION]);
    $occ = call($app, '_sectionActiveCount', [$class, $section, SESSION]);
    $before = idState($firebase);
    $stuBefore = uatStudents($fs);

    head("IMPORT {$n} rows → {$class} / {$section}  (cap={$cap} occ={$occ})");
    line('STU pointer before : ' . $before['pointer']);
    line('UAT students before: ' . count($stuBefore));

    if ($barrier > 0) {
        $u = ($barrier - microtime(true)) * 1e6;
        line('barrier wait: ' . (int) max(0, $u) . ' us');
        if ($u > 0) usleep((int) $u);
    }

    $res = runImport($app, importRows($class, $section, $n, $seed));

    $after = idState($firebase);
    $stuAfter = uatStudents($fs);
    $created = array_diff_key($stuAfter, $stuBefore);

    line();
    line('result   : success=' . $res['success'] . ' error=' . $res['error']
        . ' duplicates=' . count($res['duplicates']) . ' updated=' . $res['updated']);
    foreach ($res['skipped'] as $s) line('  skipped: ' . $s);
    line();
    line('STU pointer after  : ' . $after['pointer'] . '   (delta ' . ($after['pointer'] - $before['pointer']) . ')');
    line('student docs created: ' . count($created) . '  ' . implode(', ', array_keys($created)));
    line('occupancy after     : ' . call($app, '_sectionActiveCount', [$class, $section, SESSION]));

    // Identity accounting for the rows this run touched: which STU claim docs
    // survive. A refused group must leave NONE of its claims behind.
    $probe = range($before['pointer'] + 1, $before['pointer'] + $n + 2);
    $st = idState($firebase, $probe);
    $held = array_keys(array_filter($st['claims']));
    line('claim docs held in [' . reset($probe) . '..' . end($probe) . '] : '
        . (count($held) ? implode(', ', $held) : '(none)'));

    line();
    line('IDENTITY VERDICT: '
        . (count($created) === 0
            ? (count($held) === 0 ? 'REFUSED → 0 documents, 0 claims retained  ✔'
                                  : 'REFUSED → 0 documents but ' . count($held) . ' claims RETAINED  ✘')
            : 'COMMITTED → ' . count($created) . ' documents, ' . count($held) . ' claims held (expected: one per student)'));
    break;
}

// import2 — two destinations in ONE import file
case 'import2': {
    $c2 = $argv[4] ?? 'Class 10th';
    $s2 = $argv[5] ?? 'Section E';
    $n1 = (int) ($argv[6] ?? 2);
    $n2 = (int) ($argv[7] ?? 2);
    $seed = (int) ($argv[8] ?? 900);

    $before = idState($firebase);
    $stuBefore = uatStudents($fs);
    head("IMPORT {$n1}→{$class}/{$section}  +  {$n2}→{$c2}/{$s2}");
    line('cap/occ dest1: ' . call($app, '_sectionCapacity', [$class, $section, SESSION])
        . ' / ' . call($app, '_sectionActiveCount', [$class, $section, SESSION]));
    line('cap/occ dest2: ' . call($app, '_sectionCapacity', [$c2, $s2, SESSION])
        . ' / ' . call($app, '_sectionActiveCount', [$c2, $s2, SESSION]));
    line('STU pointer before : ' . $before['pointer']);

    $rows = array_merge(
        importRows($class, $section, $n1, $seed),
        importRows($c2, $s2, $n2, $seed + 100)
    );
    $res = runImport($app, $rows);

    $stuAfter = uatStudents($fs);
    $created  = array_diff_key($stuAfter, $stuBefore);
    $byDest = [];
    foreach ($created as $id => $d) {
        $byDest[($d['className'] ?? '?') . ' / ' . ($d['section'] ?? '?')][] = $id;
    }

    line();
    line('result   : success=' . $res['success'] . ' error=' . $res['error']);
    foreach ($res['skipped'] as $s) line('  skipped: ' . $s);
    line();
    line('STU pointer after  : ' . idState($firebase)['pointer']);
    line('documents created  : ' . count($created));
    foreach ($byDest as $dest => $ids) line("  {$dest} → " . implode(', ', $ids));
    break;
}

case 'list':
case 'cleanup': {
    $mine = uatStudents($fs);
    line('UAT students (name prefix "' . MARKER . '"): ' . count($mine));
    foreach ($mine as $id => $d) {
        line('  ' . str_pad($id, 10) . ($d['className'] ?? '') . ' / ' . ($d['section'] ?? '')
            . '   ' . ($d['name'] ?? ''));
    }
    if ($scenario === 'cleanup') {
        line();
        foreach ($mine as $id => $d) {
            // Guarded twice: tenant-scoped query AND the name marker. Real
            // student records carry neither.
            if (strpos((string) ($d['name'] ?? ''), MARKER) !== 0) { line("  SKIP {$id}"); continue; }
            $okDoc = $sync->deleteStudent($id);
            // Import also creates a Firebase Auth user per student. Deleting
            // only the Firestore document would leave an orphaned credential,
            // so cleanup removes both.
            $okAuth = false;
            try { $okAuth = (bool) $firebase->deleteFirebaseUser($id); } catch (\Throwable $e) {}
            line("  deleted {$id} : doc=" . var_export($okDoc, true) . " auth=" . var_export($okAuth, true));
        }
    }
    break;
}

default:
    line('scenarios: fixtures | mkfixture | rmfixture | state | setcap N | idstate');
    line('           import <cls> <sec> <n> <seed> | import2 … | list | cleanup');
}

head('log lines emitted');
foreach ($GLOBALS['__log'] as $l) line('  ' . $l);
