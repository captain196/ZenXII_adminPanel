<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * PHASE 1 — A/B SHADOW VERIFICATION  (STRICTLY READ-ONLY)
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Question: do existing students' NEW `studentEntitlements` records actually
 * agree with the OLD `studentConcessions` records that currently drive billing?
 *
 * Executes the REAL production readers — `Fee_concession_reader` and
 * `Fee_entitlement_reader`, unmodified — against LIVE Firestore, then compares
 * their candidate sets semantically (not by count).
 *
 * ── READ-ONLY BY CONSTRUCTION ───────────────────────────────────────────────
 * Only `firestoreQuery` / `getDocument` are used. There is no firestoreSet,
 * firestoreUpdate, firestoreCreate, firestoreDelete, commitBatch, writeDemand,
 * updateDemand, assignInitialFees or any Entitlement_service mutator anywhere
 * in this file — grep it before running.
 *
 * The built-in ENTITLEMENT_SHADOW_COMPARE flag is deliberately NOT used: the
 * only way to trigger it is to run a real generation, and every production
 * caller of that generation commits demands. This script reaches the same
 * comparison without the write.
 *
 * Usage:  php scripts/p1ab_shadow_compare.php [schoolId] [session]
 */

define('BASEPATH', __DIR__);
define('APPPATH',  realpath(__DIR__ . '/../application') . DIRECTORY_SEPARATOR);
define('FCPATH',   realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'production');

require_once __DIR__ . '/../vendor/autoload.php';

$GLOBALS['__log'] = [];
if (!function_exists('log_message')) { function log_message($l, $m) { $GLOBALS['__log'][] = "[$l] $m"; } }
if (!function_exists('config_item')) { function config_item($i) { return null; } }

class CI_Controller { public static $instance; }
if (!function_exists('get_instance')) { function &get_instance() { return CI_Controller::$instance; } }

require_once APPPATH . 'libraries/Firebase.php';
require_once APPPATH . 'libraries/Firestore_service.php';
require_once APPPATH . 'libraries/Fee_concession_reader.php';
require_once APPPATH . 'libraries/Fee_entitlement_reader.php';

$SCHOOL  = $argv[1] ?? 'SCH_B56BB9A401';
$SESSION = $argv[2] ?? '2026-27';

$firebase = new Firebase();
$fs = new Firestore_service();
$fs->init($SCHOOL, $SESSION, $SCHOOL);

$legacyReader = new Fee_concession_reader();  $legacyReader->init($firebase);
$newReader    = new Fee_entitlement_reader(); $newReader->init($firebase);

function line($s = '') { echo $s . "\n"; }
function head($s) { line(); line('── ' . $s . ' ' . str_repeat('─', max(0, 66 - strlen($s)))); }

line('════════════════════════════════════════════════════════════════════════');
line(' PHASE 1 A/B SHADOW VERIFICATION — READ-ONLY');
line(" tenant={$SCHOOL}  session={$SESSION}  utc=" . gmdate('c'));
line('════════════════════════════════════════════════════════════════════════');

// ── raw census (every status, so nothing is hidden by the readers' filters) ──
$rawConc = $firebase->firestoreQuery('studentConcessions', [['schoolId', '==', $SCHOOL]], null, 'ASC', 1000);
$rawEnt  = $firebase->firestoreQuery('studentEntitlements', [['schoolId', '==', $SCHOOL]], null, 'ASC', 1000);
$students = $fs->schoolWhere('students');

head('DATASET CENSUS (all statuses, unfiltered)');
line('students                       : ' . count($students));
line('studentConcessions  (all)      : ' . count($rawConc));
line('studentEntitlements (all)      : ' . count($rawEnt));

$body = function ($r) { return is_array($r['data'] ?? null) ? $r['data'] : $r; };

$byStatus = function (array $rows) use ($body) {
    $m = [];
    foreach ($rows as $r) { $d = $body($r); $m[(string) ($d['status'] ?? '(none)')] = ($m[(string) ($d['status'] ?? '(none)')] ?? 0) + 1; }
    ksort($m); return $m;
};
line('concessions by status          : ' . json_encode($byStatus($rawConc)));
line('entitlements by status         : ' . json_encode($byStatus($rawEnt)));

$byKind = [];
foreach ($rawEnt as $r) { $d = $body($r); $byKind[(string) ($d['kind'] ?? '(none)')] = ($byKind[(string) ($d['kind'] ?? '(none)')] ?? 0) + 1; }
ksort($byKind);
line('entitlements by kind           : ' . json_encode($byKind));

$sessConc = $sessEnt = [];
foreach ($rawConc as $r) { $d = $body($r); $k = $d['session'] ?? '(null=all)'; $sessConc[(string) $k] = ($sessConc[(string) $k] ?? 0) + 1; }
foreach ($rawEnt  as $r) { $d = $body($r); $k = $d['session'] ?? '(null=all)'; $sessEnt[(string) $k]  = ($sessEnt[(string) $k]  ?? 0) + 1; }
line('concession sessions            : ' . json_encode($sessConc));
line('entitlement sessions           : ' . json_encode($sessEnt));

// ── normalisation, for COMPARISON ONLY — stored data is never touched ───────
/**
 * Legacy scope+targets  →  the entitlement scope string, using the SAME rule
 * `scripts/migrate_student_entitlements.js::mapScope()` used, so a mismatch
 * here is a real data divergence and not an artefact of a second mapping.
 */
function legacyScopeToNew(array $d): ?string
{
    $scope = strtolower(trim((string) ($d['scope'] ?? '')));
    if ($scope === 'all') return 'all';
    if ($scope === 'head') {
        $h = trim((string) ($d['targetFeeHeadId'] ?? ''));
        return $h === '' ? null : 'head:' . $h;
    }
    if ($scope === 'category') {
        $c = trim((string) ($d['targetCategory'] ?? ''));
        return $c === '' ? null : 'category:' . $c;
    }
    return null;
}

function normLegacy(array $d): array
{
    return [
        'studentId'   => (string) ($d['studentId'] ?? ''),
        'session'     => $d['session'] ?? null,
        'scope'       => legacyScopeToNew($d),
        'rawScope'    => (string) ($d['scope'] ?? ''),
        'type'        => strtolower(trim((string) ($d['type'] ?? ''))),
        'value'       => round((float) ($d['value'] ?? 0), 4),
        'effFrom'     => (string) ($d['effectiveFrom'] ?? ''),
        'effTo'       => ($d['effectiveTo'] ?? null) ?: null,
        'status'      => strtolower(trim((string) ($d['status'] ?? ''))),
        'targetName'  => (string) ($d['targetFeeHead'] ?? ''),
    ];
}

function normNew(array $d): array
{
    return [
        'studentId'   => (string) ($d['studentId'] ?? ''),
        'session'     => $d['session'] ?? null,
        'scope'       => trim((string) ($d['scope'] ?? '')),
        'rawScope'    => trim((string) ($d['scope'] ?? '')),
        'type'        => strtolower(trim((string) ($d['valueType'] ?? ''))),
        'value'       => round((float) ($d['value'] ?? 0), 4),
        'effFrom'     => (string) ($d['effectiveFrom'] ?? ''),
        'effTo'       => ($d['effectiveTo'] ?? null) ?: null,
        'status'      => strtolower(trim((string) ($d['status'] ?? ''))),
        'kind'        => strtolower(trim((string) ($d['kind'] ?? ''))),
        'sourceRef'   => (string) ($d['sourceRef'] ?? ($d['migratedFrom'] ?? '')),
    ];
}

/** Identity used to PAIR an old record with a new one. Deliberately excludes
 *  value/type/status so those can be reported as divergences instead of
 *  silently producing two unmatched singletons. */
function pairKey(array $n): string
{
    return $n['studentId'] . '|' . (string) ($n['session'] ?? '*') . '|' . (string) $n['scope'] . '|' . $n['effFrom'];
}

// ── build both sides ────────────────────────────────────────────────────────
$old = $new = [];
$dupOld = $dupNew = [];
foreach ($rawConc as $r) {
    $n = normLegacy($body($r));
    $n['_docId'] = (string) ($r['id'] ?? '?');
    $k = pairKey($n);
    if (isset($old[$k])) $dupOld[] = $k;
    $old[$k][] = $n;
}
foreach ($rawEnt as $r) {
    $d = $body($r);
    $n = normNew($d);
    $n['_docId'] = (string) ($r['id'] ?? '?');
    if ($n['kind'] !== 'concession') { $new['__SERVICE__'][] = $n; continue; }
    $k = pairKey($n);
    if (isset($new[$k])) $dupNew[] = $k;
    $new[$k][] = $n;
}

$serviceRows = $new['__SERVICE__'] ?? [];
unset($new['__SERVICE__']);

// ── classify ────────────────────────────────────────────────────────────────
$C = ['MATCH' => [], 'MISSING_NEW' => [], 'EXTRA_NEW' => [], 'VALUE_DIVERGENCE' => [],
      'STATUS_DIVERGENCE' => [], 'PERIOD_DIVERGENCE' => [], 'DUPLICATE' => [],
      'SERVICE_NO_LEGACY_EQUIVALENT' => [], 'UNMAPPABLE_LEGACY_SCOPE' => [], 'OTHER' => []];

foreach ($old as $k => $rows) {
    if (count($rows) > 1) $C['DUPLICATE'][] = ['side' => 'old', 'key' => $k, 'n' => count($rows)];
    $o = $rows[0];
    if ($o['scope'] === null) { $C['UNMAPPABLE_LEGACY_SCOPE'][] = $o; continue; }
    if (!isset($new[$k])) { $C['MISSING_NEW'][] = $o; continue; }
    $nrows = $new[$k];
    if (count($nrows) > 1) $C['DUPLICATE'][] = ['side' => 'new', 'key' => $k, 'n' => count($nrows)];
    $nn = $nrows[0];

    $issues = [];
    if ($o['type'] !== $nn['type'])                       $issues[] = 'type';
    if (abs($o['value'] - $nn['value']) > 0.0001)         $issues[] = 'value';
    if ($o['status'] !== $nn['status'])                   $issues[] = 'status';
    if ((string) $o['effTo'] !== (string) $nn['effTo'])   $issues[] = 'effectiveTo';

    if ($issues === []) { $C['MATCH'][] = ['old' => $o, 'new' => $nn]; continue; }
    if (in_array('type', $issues, true) || in_array('value', $issues, true)) {
        $C['VALUE_DIVERGENCE'][] = ['old' => $o, 'new' => $nn, 'fields' => $issues];
    } elseif (in_array('status', $issues, true)) {
        $C['STATUS_DIVERGENCE'][] = ['old' => $o, 'new' => $nn, 'fields' => $issues];
    } else {
        $C['PERIOD_DIVERGENCE'][] = ['old' => $o, 'new' => $nn, 'fields' => $issues];
    }
}
foreach ($new as $k => $rows) {
    if (!isset($old[$k])) foreach ($rows as $nn) $C['EXTRA_NEW'][] = $nn;
}
foreach ($serviceRows as $s) $C['SERVICE_NO_LEGACY_EQUIVALENT'][] = $s;

head('COMPARISON RESULTS');
foreach ($C as $cls => $rows) printf("  %-30s %d\n", $cls, count($rows));

head('SAMPLE EVIDENCE (up to 5 per class)');
foreach ($C as $cls => $rows) {
    if (!$rows) continue;
    line("  [{$cls}]");
    foreach (array_slice($rows, 0, 5) as $r) line('    ' . json_encode($r));
}

// ── live reader execution — the real production code, per student ───────────
head('LIVE READER EXECUTION (real Fee_concession_reader vs Fee_entitlement_reader)');
$readerDiff = 0; $readerSame = 0; $studentsWithAny = 0;
foreach ($students as $srow) {
    $d = $body($srow);
    $sid = (string) ($d['studentId'] ?? '');
    if ($sid === '') continue;
    $a = $legacyReader->activeConcessionsForStudent($SCHOOL, $sid, $SESSION);
    $b = $newReader->activeConcessionsForStudent($SCHOOL, $sid, $SESSION);
    if (!$a && !$b) continue;
    $studentsWithAny++;
    $an = array_map(fn($x) => normLegacy($x), $a);
    $bn = array_map(fn($x) => normNew($x), $b);
    usort($an, fn($x, $y) => strcmp(pairKey($x), pairKey($y)));
    usort($bn, fn($x, $y) => strcmp(pairKey($x), pairKey($y)));
    $ak = array_map('pairKey', $an);
    $bk = array_map('pairKey', $bn);
    $same = ($ak === $bk);
    printf("  %-10s legacy=%d new=%d  candidateSets=%s\n", $sid, count($a), count($b), $same ? 'IDENTICAL' : 'DIFFERENT');
    if (!$same) { $readerDiff++; line('      legacy: ' . json_encode($ak)); line('      new   : ' . json_encode($bk)); }
    else $readerSame++;
}
line("  students holding any rule: {$studentsWithAny}   identical={$readerSame}   different={$readerDiff}");

// ── third authority inventory ───────────────────────────────────────────────
head('THIRD AUTHORITY INVENTORY (students.exemptedFees / discountHeads / scholarshipAwards)');
$thirdStudents = 0; $exCount = 0; $dhCount = 0;
foreach ($students as $srow) {
    $d = $body($srow);
    $sid = (string) ($d['studentId'] ?? '');
    $ex = is_array($d['exemptedFees']  ?? null) ? $d['exemptedFees']  : [];
    $dh = is_array($d['discountHeads'] ?? null) ? $d['discountHeads'] : [];
    if (!$ex && !$dh) continue;
    $thirdStudents++; $exCount += count($ex); $dhCount += count($dh);
    line("  {$sid}  exemptedFees=" . json_encode(array_keys($ex)) . '  discountHeads=' . json_encode($dh));
}
line("  students carrying third-authority data: {$thirdStudents}  (exempt heads={$exCount}, discount heads={$dhCount})");

$awards = $firebase->firestoreQuery('scholarshipAwards', [['schoolId', '==', $SCHOOL]], null, 'ASC', 500);
line('  scholarshipAwards docs                : ' . count($awards));
foreach (array_slice($awards, 0, 5) as $a) {
    $d = $body($a);
    line('    ' . json_encode(['studentId' => $d['studentId'] ?? '?', 'amount' => $d['amount'] ?? null, 'status' => $d['status'] ?? null]));
}

head('LOG LINES EMITTED BY THE READERS');
foreach ($GLOBALS['__log'] as $l) line('  ' . $l);
line();
line('── end (read-only; nothing was written) ──');
