<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * M4 — ATTACH feeHeadRef TO PRICING CHART ROWS  (ADDITIVE ONLY)
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Adds `feeHeadRef` (the school-scoped Fee Head Registry identity) to every
 * row of every PRICING chart. Nothing else on the row is touched: name,
 * amount, frequency, billingType and the legacy per-row feeHeadId are all
 * carried through byte-for-byte.
 *
 * NOT touched: `_titles` docs (retired at M7, not pricing rows), feeDemands,
 * demand ids, balances, categories on existing demands, students.
 *
 * feeHeadRef is CLASSIFICATION ONLY. It must never reach Fee_lifecycle::
 * _demandId() — doing so would change every existing Transport/Hostel demand
 * id and duplicate live charges. This script writes it to the chart only.
 *
 * DRY-RUN BY DEFAULT. Writes require --commit.
 */

define('BASEPATH', __DIR__);
define('APPPATH', __DIR__ . '/../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../application/libraries/Firestore_rest_client.php';
require_once __DIR__ . '/../application/libraries/Fee_head_resolver.php';

const EXPECTED_CHARTS = 7;
const EXPECTED_ROWS   = 28;
const EXPECTED_HEADS  = 12;

$COMMIT = in_array('--commit', $argv, true);

$fs = new FirestoreRestClient(
    __DIR__ . '/../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json',
    'graderadmin', '(default)'
);

function body(array $r): array { return is_array($r['data'] ?? null) ? $r['data'] : $r; }
function line(string $s = '') { echo $s . "\n"; }
function head(string $s) { line(); line('── ' . $s . ' ' . str_repeat('─', max(0, 62 - strlen($s)))); }
function halt(string $why): void { line(); line('*** STOP — ' . $why); exit(9); }

line('════════════════════════════════════════════════════════════════════');
line(' M4 — ATTACH feeHeadRef TO CHART ROWS (additive)');
line(' MODE: ' . ($COMMIT ? '*** COMMIT (writing) ***' : 'DRY-RUN (no writes)'));
line(' utc=' . gmdate('c'));
line('════════════════════════════════════════════════════════════════════');

// ── §12.2 · registry must be intact ─────────────────────────────────────────
head('§12.2 · REGISTRY INTEGRITY');
$entries = [];   // school|nameNorm => feeHeadId
$entryCount = 0; $claimCount = 0;
foreach ($fs->query(Fee_head_resolver::COL, [], null, 'ASC', 500) as $r) {
    $d = body($r);
    if (($d['kind'] ?? '') === 'nameClaim') { $claimCount++; continue; }
    $entryCount++;
    $entries[$d['schoolId'] . '|' . $d['nameNorm']] = (string) $d['feeHeadId'];
}
line('  registry entries : ' . $entryCount . '   name claims : ' . $claimCount);
if ($entryCount !== EXPECTED_HEADS || $claimCount !== EXPECTED_HEADS) {
    halt('registry is not the expected 12 entries + 12 claims — do not proceed.');
}
line('  registry intact : confirmed');

// ── §12.1 · re-read chart state ─────────────────────────────────────────────
head('§12.1 · CURRENT CHART STATE');
$charts = []; $titles = 0;
foreach ($fs->query('feeStructures', [], null, 'ASC', 500) as $r) {
    $id = (string) ($r['id'] ?? ''); $d = body($r);
    if (strpos($id, '_titles') !== false) { $titles++; continue; }
    $charts[$id] = $d;
}
line('  pricing charts : ' . count($charts) . '   _titles docs (untouched) : ' . $titles);
if (count($charts) !== EXPECTED_CHARTS) halt('chart count changed since M1 — expected ' . EXPECTED_CHARTS . '.');

// ── §12.3-5 · map every row, refuse on any surprise ─────────────────────────
head('§12.3-5 · EXACT ROWS THAT WILL RECEIVE feeHeadRef');
$plan = []; $rows = 0; $already = 0;
foreach ($charts as $docId => $d) {
    $school = (string) ($d['schoolId'] ?? '');
    $heads  = (array) ($d['feeHeads'] ?? []);
    $newHeads = []; $changed = false;
    foreach ($heads as $h) {
        if (!is_array($h)) halt("non-array fee head row in {$docId}.");
        $rows++;
        $nm = trim((string) ($h['name'] ?? ''));
        $nn = Fee_head_resolver::nameNorm($nm);
        $key = $school . '|' . $nn;
        if (!isset($entries[$key])) {
            halt("chart row '{$nm}' in {$docId} has NO registry entry — an unexpected head appeared. "
               . 'Re-run M1 and obtain approval before proceeding.');
        }
        $ref = $entries[$key];
        if ((string) ($h['feeHeadRef'] ?? '') === $ref) { $already++; $newHeads[] = $h; continue; }
        // ADDITIVE: every existing key is preserved exactly as-is.
        $row = $h;
        $row['feeHeadRef'] = $ref;
        $newHeads[] = $row;
        $changed = true;
        printf("  %-46s %-18s -> %s\n", substr($docId, 0, 46), "'" . $nm . "'", $ref);
    }
    if ($changed) $plan[$docId] = $newHeads;
}
line('  chart rows total : ' . $rows . '   already correct : ' . $already
   . '   to update : ' . ($rows - $already));
if ($rows !== EXPECTED_ROWS) halt('row count changed since M1 — expected ' . EXPECTED_ROWS . '.');

if (!$COMMIT) {
    head('DRY-RUN COMPLETE');
    line('  chart documents that WOULD be patched : ' . count($plan));
    line('  fields added per row : feeHeadRef (nothing else touched)');
    line('  feeDemands / balances / students / _titles touched : 0');
    line('  Nothing was written. Re-run with --commit to apply.');
    exit(0);
}

// ── the additive write ──────────────────────────────────────────────────────
head('APPLYING (merge-patch of the feeHeads array only)');
$patched = 0;
foreach ($plan as $docId => $newHeads) {
    // Merge write carrying ONLY feeHeads: every other field on the chart
    // document (schoolId, session, className, section, totals, updatedAt)
    // is left exactly as it is.
    if ($fs->setDocument('feeStructures', $docId, ['feeHeads' => $newHeads], true)) {
        $patched++;
        line("  patched {$docId} (" . count($newHeads) . ' rows)');
    } else {
        halt("failed to patch {$docId} — stopping before any further write.");
    }
}
head('RESULT');
line('  chart documents patched : ' . $patched);
line('  feeDemands modified : 0    demand ids changed : 0    balances changed : 0');
line();
line('Verify in a separate process:  php scripts/m4_verify_fee_head_ref.php');
line('── end ──');
