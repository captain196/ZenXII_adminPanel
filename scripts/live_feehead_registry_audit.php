<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * LIVE FEE HEAD REGISTRY — INTEGRITY AUDIT (STRICTLY READ-ONLY)
 * ══════════════════════════════════════════════════════════════════════════
 *
 * WHY: DOCID-ENCODE-1. Firestore_rest_client built document CREATES as
 *   `?documentId=` . urlencode($docId)   → a space becomes '+', stored LITERALLY
 * while every read, update and delete builds the path with
 *   rawurlencode($docId)                 → a space becomes %20, decoded to ' '
 *
 * A fee head name claim is `{schoolId}_name_{nameNorm}` and nameNorm KEEPS
 * SPACES. So on the pre-fix code a claim for "Tuition Fee" was written as
 * '..._name_tuition+fee' while every lookup asks for '..._name_tuition fee'.
 * The document exists and is permanently unreachable: resolveByName() returns
 * not_found for a head that is plainly visible in the picker, and with registry
 * mode active that aborts fee generation.
 *
 * The live registry was minted by scripts/m3_mint_fee_head_registry.php through
 * that same create path, so live claims are EXPECTED to be affected. This
 * establishes whether they are, and the exact scope, before anyone trusts live
 * name resolution or designs a repair.
 *
 * ── THIS SCRIPT WRITES NOTHING ──────────────────────────────────────────────
 * There is no --commit, no repair branch, no migration. It only reads and
 * reports. Any repair is a separate, separately authorized exercise.
 *
 *   php scripts/live_feehead_registry_audit.php
 */

define('BASEPATH', __DIR__);
define('APPPATH', __DIR__ . '/../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../application/libraries/Firestore_rest_client.php';
require_once __DIR__ . '/../application/libraries/Fee_head_resolver.php';

const COL = 'feeHeadRegistry';

$sa = __DIR__ . '/../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
if (!is_file($sa)) { fwrite(STDERR, "service account not found\n"); exit(2); }

// REFUSE to run against an emulator: this audit is about LIVE data, and a
// loopback result would be mistaken for a live verdict.
if (getenv('FIRESTORE_EMULATOR_HOST')) {
    fwrite(STDERR, "REFUSING: FIRESTORE_EMULATOR_HOST is set. This audit reports on LIVE data;\n"
        . "a result taken from the emulator would be a false all-clear.\n");
    exit(2);
}

$fs = new FirestoreRestClient($sa, 'graderadmin', '(default)');

function line(string $s = '') { echo $s . "\n"; }
function head(string $s) { line(); line('── ' . $s . ' ' . str_repeat('─', max(0, 60 - strlen($s)))); }

line('════════════════════════════════════════════════════════════════════');
line(' LIVE FEE HEAD REGISTRY — INTEGRITY AUDIT   (READ-ONLY, NO WRITES)');
line(' utc=' . gmdate('c'));
line('════════════════════════════════════════════════════════════════════');

// ── 1 · WHAT IS THERE ───────────────────────────────────────────────────────
head('1 · REGISTRY CONTENTS');
$rows = $fs->query(COL, [], null, 'ASC', 1000);
line('  documents in ' . COL . ' : ' . count($rows));

if (count($rows) === 0) {
    head('VERDICT');
    line('  LIVE REGISTRY INTEGRITY: CLEAN (the collection is empty — nothing to affect)');
    exit(0);
}

$heads = [];   // real head documents
$claims = [];  // name-claim documents
$tenants = [];
foreach ($rows as $r) {
    $id = (string) ($r['id'] ?? '');
    $d  = is_array($r['data'] ?? null) ? $r['data'] : $r;
    $school = (string) ($d['schoolId'] ?? '');
    if ($school !== '') $tenants[$school] = true;
    if (($d['kind'] ?? '') === Fee_head_resolver::KIND_NAME_CLAIM) $claims[$id] = $d;
    else $heads[$id] = $d;
}
ksort($tenants);
line('  head documents      : ' . count($heads));
line('  name-claim documents: ' . count($claims));
line('  tenants represented : ' . implode(', ', array_keys($tenants)));

// ── 2 · THE DEFECT SIGNATURE ────────────────────────────────────────────────
head('2 · DOCID-ENCODE-1 SIGNATURE  (a literal "+" where a space belongs)');
$plusIds = [];
foreach ($rows as $r) {
    $id = (string) ($r['id'] ?? '');
    if (strpos($id, '+') !== false) $plusIds[] = $id;
}
line('  document ids containing a literal "+" : ' . count($plusIds));
foreach (array_slice($plusIds, 0, 25) as $id) line('      ' . $id);
if (count($plusIds) > 25) line('      … ' . (count($plusIds) - 25) . ' more');

// ── 3 · CAN THE CANONICAL LOOKUP RESOLVE THEM? ──────────────────────────────
// This is the question that actually matters. A '+' in an id is only a problem
// if the id the application COMPUTES differs from the id that was STORED.
head('3 · CANONICAL LOOKUP  (does resolveByName reach each head?)');
$unreachable = [];
$reachable   = 0;
foreach ($heads as $storedId => $h) {
    $school = (string) ($h['schoolId'] ?? '');
    $name   = (string) ($h['name'] ?? '');
    if ($school === '' || $name === '') continue;

    // Exactly what Fee_head_resolver::resolveByName() computes.
    $wantClaim = Fee_head_resolver::claimDocId($school, $name);
    $claimDoc  = $fs->getDocument(COL, $wantClaim);
    $viaClaim  = is_array($claimDoc) ? (string) ($claimDoc['feeHeadId'] ?? '') : '';

    $wantHead  = Fee_head_resolver::docId($school, (string) ($h['feeHeadId'] ?? ''));
    $headDoc   = $viaClaim !== '' ? $fs->getDocument(COL, Fee_head_resolver::docId($school, $viaClaim)) : null;

    if ($viaClaim === '' || !is_array($headDoc)) {
        $unreachable[] = [
            'school'     => $school,
            'name'       => $name,
            'storedId'   => $storedId,
            'wantClaim'  => $wantClaim,
            'claimFound' => $viaClaim !== '' ? 'yes' : 'NO',
            'headFound'  => is_array($headDoc) ? 'yes' : 'NO',
            'wantHead'   => $wantHead,
        ];
    } else {
        $reachable++;
    }
}
line('  heads resolvable by name   : ' . $reachable);
line('  heads NOT resolvable       : ' . count($unreachable));
foreach ($unreachable as $u) {
    line(sprintf('      %s / "%s"', $u['school'], $u['name']));
    line(sprintf('          stored head doc : %s', $u['storedId']));
    line(sprintf('          claim looked up : %s   found=%s', $u['wantClaim'], $u['claimFound']));
    line(sprintf('          head looked up  : %s   found=%s', $u['wantHead'], $u['headFound']));
}

// ── 4 · WHO DEPENDS ON THEM ─────────────────────────────────────────────────
head('4 · DEPENDENCY  (charts referencing a head identity)');
$refCount = 0; $refUnknown = 0; $knownIds = [];
foreach ($heads as $h) {
    $fid = (string) ($h['feeHeadId'] ?? '');
    if ($fid !== '') $knownIds[$fid] = true;
}
try {
    foreach ($fs->query('feeStructures', [], null, 'ASC', 1000) as $row) {
        $d = is_array($row['data'] ?? null) ? $row['data'] : $row;
        foreach (['feeHeads', 'heads', 'rows'] as $k) {
            if (!is_array($d[$k] ?? null)) continue;
            foreach ($d[$k] as $r) {
                if (!is_array($r)) continue;
                $ref = trim((string) ($r['feeHeadRef'] ?? ''));
                if ($ref === '') continue;
                $refCount++;
                if (!isset($knownIds[$ref])) $refUnknown++;
            }
        }
    }
} catch (\Throwable $e) {
    line('  (could not read feeStructures: ' . $e->getMessage() . ')');
}
line('  chart rows carrying a feeHeadRef      : ' . $refCount);
line('  refs pointing at NO registry document : ' . $refUnknown);

// ── 5 · VERDICT ─────────────────────────────────────────────────────────────
head('5 · VERDICT');
$affected = count($unreachable) > 0 || count($plusIds) > 0 || $refUnknown > 0;

if (!$affected) {
    line('  LIVE REGISTRY INTEGRITY: CLEAN');
    line('    • no document id carries a literal "+"');
    line('    • every head resolves through the canonical name lookup');
    line('    • every chart reference points at a real registry document');
} else {
    line('  LIVE REGISTRY INTEGRITY: AFFECTED');
    line('    • ids with a literal "+"          : ' . count($plusIds));
    line('    • heads unreachable by name       : ' . count($unreachable));
    line('    • chart refs with no registry doc : ' . $refUnknown);
    line();
    line('  SCOPE IS EXACTLY THE DOCUMENTS LISTED ABOVE. No repair was attempted.');
    line('  A repair must: create the correctly-encoded claim (create-if-absent, so it is');
    line('  idempotent), leave the mis-encoded document in place rather than deleting it,');
    line('  and re-run this audit to confirm reachable==total before anything else changes.');
}
line();
line('  documents written by this script: 0');
line();
