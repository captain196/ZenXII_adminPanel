<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * M3 — MINT THE FEE HEAD REGISTRY  (approved 12-entry mapping only)
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Creates EXACTLY the 12 approved registry entries and their 12 name claims.
 * No collection sweep, no wildcard, no discovery-driven creation: every
 * document is enumerated literally in MAPPING below.
 *
 * IDEMPOTENT BY CONSTRUCTION. Both writes use createDocument()
 * (create-if-not-exists), and feeHeadId is DERIVED deterministically from
 * schoolId + nameNorm, so a re-run mints nothing new and cannot duplicate an
 * identity. (The derivation is a MINT-TIME convenience only — once stored the
 * id is immutable and a later rename must NOT re-derive it.)
 *
 * Writes ONLY to feeHeadRegistry. It touches no feeDemand, no feeStructure,
 * no student, no financial value.
 *
 * DRY-RUN BY DEFAULT. Writes require --commit.
 */

define('BASEPATH', __DIR__);
define('APPPATH', __DIR__ . '/../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../application/libraries/Firestore_rest_client.php';
require_once __DIR__ . '/../application/libraries/Fee_head_resolver.php';

const COL = Fee_head_resolver::COL;

/** THE APPROVED MAPPING — operator-confirmed. Nothing else may be created. */
const MAPPING = [
    ['SCH_218AAF5C23', 'Tuition Fee',         'academic',       'chart'],
    ['SCH_218AAF5C23', 'Examination Fee',     'academic',       'chart'],
    ['SCH_218AAF5C23', 'Registration Fee',    'academic',       'chart'],
    ['SCH_218AAF5C23', 'Transport Fee',       'transport',      'chart+runtime'],
    ['SCH_218AAF5C23', 'Hostel Fee',          'hostel',         'runtime'],
    ['SCH_218AAF5C23', 'ID Card Replacement', 'administrative', 'runtime'],
    ['SCH_B56BB9A401', 'Tuition Fee',         'academic',       'chart'],
    ['SCH_B56BB9A401', 'Admission Fee',       'academic',       'chart'],
    ['SCH_B56BB9A401', 'Library Fee',         'academic',       'chart'],
    ['SCH_B56BB9A401', 'Transport Fee',       'transport',      'chart+runtime'],
    ['SCH_B56BB9A401', 'Hostel Fee',          'hostel',         'runtime'],
    ['SCH_B56BB9A401', 'ID Card Replacement', 'administrative', 'runtime'],
];
const EXPECTED_ENTRIES = 12;
const MINTED_BY = 'M3_REGISTRY_MINT';

$COMMIT = in_array('--commit', $argv, true);

$fs = new FirestoreRestClient(
    __DIR__ . '/../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json',
    'graderadmin', '(default)'
);

function body(array $r): array { return is_array($r['data'] ?? null) ? $r['data'] : $r; }
function line(string $s = '') { echo $s . "\n"; }
function head(string $s) { line(); line('── ' . $s . ' ' . str_repeat('─', max(0, 62 - strlen($s)))); }
function halt(string $why): void { line(); line('*** STOP — ' . $why); exit(9); }

/** Mint-time derivation only. Immutable once stored; rename never re-derives. */
function mintId(string $schoolId, string $nameNorm): string
{
    return 'FH_' . strtoupper(substr(sha1($schoolId . '|' . $nameNorm), 0, 12));
}

line('════════════════════════════════════════════════════════════════════');
line(' M3 — MINT FEE HEAD REGISTRY');
line(' MODE: ' . ($COMMIT ? '*** COMMIT (writing) ***' : 'DRY-RUN (no writes)'));
line(' utc=' . gmdate('c'));
line('════════════════════════════════════════════════════════════════════');

// ── §12.1-4 · pre-write confirmations ───────────────────────────────────────
head('§12.1-4 · PRE-WRITE VERIFICATION');

$existing = $fs->query(COL, [], null, 'ASC', 500);
line('  1. live registry documents            : ' . count($existing));
if (count($existing) > 0) {
    foreach ($existing as $r) line('       ' . (string) ($r['id'] ?? '?'));
    line('  2. *** registry is NOT empty — records appeared unexpectedly');
    if (!$COMMIT) line('     (dry-run continues so the delta is visible)');
} else {
    line('  2. no unexpected records                : confirmed');
    line('  3. no conflicting claims                : confirmed (collection empty)');
}

$schools = [];
foreach ($fs->query('feeStructures', [], null, 'ASC', 500) as $r) {
    $d = body($r);
    if (($s = (string) ($d['schoolId'] ?? '')) !== '') $schools[$s] = true;
}
ksort($schools);
line('  4. schools with fee structures          : ' . implode(', ', array_keys($schools)));
$mapSchools = [];
foreach (MAPPING as $m) $mapSchools[$m[0]] = true;
ksort($mapSchools);
if (array_keys($schools) !== array_keys($mapSchools)) {
    halt('school set has changed since M2 — mapping covers [' . implode(',', array_keys($mapSchools))
       . '] but live is [' . implode(',', array_keys($schools)) . '].');
}
line('     school set matches the approved mapping : yes');

// ── §12.5-6 · mapping + claim uniqueness ────────────────────────────────────
head('§12.5-6 · MAPPING AND CLAIM UNIQUENESS');
line('  5. mapping entries : ' . count(MAPPING) . ' (expected ' . EXPECTED_ENTRIES . ')');
if (count(MAPPING) !== EXPECTED_ENTRIES) halt('mapping size differs from the approved 12.');

$claims = []; $ids = [];
foreach (MAPPING as [$school, $name, $cat, $src]) {
    if (!Fee_head_resolver::isAssignableCategory($cat)) {
        halt("category '{$cat}' for {$school}/{$name} is not operator-assignable.");
    }
    $nn = Fee_head_resolver::nameNorm($name);
    $claimId = Fee_head_resolver::claimDocId($school, $name);
    if (isset($claims[$claimId])) halt("duplicate normalised name '{$nn}' within {$school}.");
    $claims[$claimId] = true;
    $fid = mintId($school, $nn);
    $docId = Fee_head_resolver::docId($school, $fid);
    if (isset($ids[$docId])) halt("derived feeHeadId collision for {$school}/{$name}.");
    $ids[$docId] = true;
}
line('  6. distinct name claims : ' . count($claims) . '   distinct identities : ' . count($ids));
if (count($claims) !== EXPECTED_ENTRIES || count($ids) !== EXPECTED_ENTRIES) halt('uniqueness check failed.');
line('     no duplicate claims, no identity collisions : confirmed');

// ── §12.7-8 · the exact documents ───────────────────────────────────────────
head('§12.7 · EXACT REGISTRY DOCUMENTS TO BE CREATED (12)');
$now = gmdate('c');
$plan = [];
foreach (MAPPING as [$school, $name, $cat, $src]) {
    $nn  = Fee_head_resolver::nameNorm($name);
    $fid = mintId($school, $nn);
    $doc = [
        'feeHeadId'     => $fid,
        'schoolId'      => $school,
        'name'          => $name,
        'nameNorm'      => $nn,
        'category'      => $cat,
        'categorySetAt' => $now,
        'categorySetBy' => MINTED_BY,
        'status'        => 'active',
        'displayGroup'  => '',
        'sortOrder'     => 0,
        'createdAt'     => $now,
        'createdBy'     => MINTED_BY,
        'updatedAt'     => $now,
        'updatedBy'     => MINTED_BY,
    ];
    $claim = [
        'kind'      => 'nameClaim',
        'schoolId'  => $school,
        'nameNorm'  => $nn,
        'feeHeadId' => $fid,
        'claimedAt' => $now,
        'claimedBy' => MINTED_BY,
    ];
    $plan[] = ['docId' => Fee_head_resolver::docId($school, $fid), 'doc' => $doc,
               'claimId' => Fee_head_resolver::claimDocId($school, $name), 'claim' => $claim, 'src' => $src];
    printf("  %s/%s\n      name='%s'  category=%s  status=active  source=%s\n",
        COL, Fee_head_resolver::docId($school, $fid), $name, $cat, $src);
}

head('§12.8 · EXACT NAME-CLAIM DOCUMENTS TO BE CREATED (12)');
foreach ($plan as $p) line('  ' . COL . '/' . $p['claimId'] . '  -> ' . $p['doc']['feeHeadId']);

if (!$COMMIT) {
    head('DRY-RUN COMPLETE');
    line('  documents that WOULD be created : ' . (count($plan) * 2) . ' (12 entries + 12 claims)');
    line('  feeDemands / feeStructures / students touched : 0');
    line('  Nothing was written. Re-run with --commit to apply.');
    exit(0);
}

// ── minting ─────────────────────────────────────────────────────────────────
head('MINTING (create-if-not-exists — idempotent)');
$madeClaim = 0; $madeDoc = 0; $skipped = 0;
foreach ($plan as $p) {
    // Claim FIRST: it is the uniqueness gate. If another process already holds
    // this name, we must not mint a second identity for it.
    $claimOk = $fs->createDocument(COL, $p['claimId'], $p['claim']);
    if (!$claimOk) {
        $held = $fs->getDocument(COL, $p['claimId']);
        $heldId = is_array($held) ? (string) ($held['feeHeadId'] ?? '?') : '?';
        if ($heldId !== $p['doc']['feeHeadId']) {
            halt("name claim {$p['claimId']} is held by a DIFFERENT identity ({$heldId}) — refusing to mint.");
        }
        $skipped++;
        line("  claim exists (same identity), skipping: {$p['claimId']}");
    } else {
        $madeClaim++;
        line("  claim created : {$p['claimId']}");
    }

    if ($fs->createDocument(COL, $p['docId'], $p['doc'])) {
        $madeDoc++;
        line("  entry created : {$p['docId']}  [{$p['doc']['category']}]");
    } else {
        line("  entry exists, left untouched : {$p['docId']}");
    }
}

head('RESULT');
line('  name claims created : ' . $madeClaim);
line('  registry entries created : ' . $madeDoc);
line('  already present (skipped) : ' . $skipped);
line('  feeDemands / feeStructures / students modified : 0');
line();
line('Post-verification MUST run in a separate process:');
line('   php scripts/m3_verify_fee_head_registry.php');
line('── end ──');
