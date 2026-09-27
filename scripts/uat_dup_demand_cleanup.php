<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * UAT DUPLICATE feeDemands CLEANUP — the 74 raised by two chart saves
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Removes ONLY the duplicate demands created by `save_updated_fees` →
 * `_generateDemandsForMonth`, which mints RANDOM ids (`newDemandId()`) and
 * whose pre-read dedupe failed to match the original demands.
 *
 * The 26 originals are `DEM_`-prefixed (deterministic) and are KEPT.
 *
 * GUARDS, all mandatory:
 *   G1 schoolId  === SCH_B56BB9A401
 *   G2 studentId === STU0012
 *   G3 doc id does NOT start with 'DEM_'   (never touch a deterministic id)
 *   G4 paidAmount === 0                    (never delete a paid demand)
 *   G5 pre-delete count === 74, else abort without deleting anything
 *
 * DRY-RUN BY DEFAULT. Writes require --commit.
 */
define('BASEPATH', __DIR__);
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../application/libraries/Firestore_rest_client.php';

const TENANT   = 'SCH_B56BB9A401';
const STUDENT  = 'STU0012';
const EXPECTED = 74;

$COMMIT = in_array('--commit', $argv, true);
$fs = new FirestoreRestClient(
    __DIR__ . '/../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json',
    'graderadmin', '(default)'
);
function body(array $r): array { return is_array($r['data'] ?? null) ? $r['data'] : $r; }
function line(string $s = '') { echo $s . "\n"; }
function halt(string $w): void { line(); line('*** STOP — ' . $w); exit(9); }

line('UAT DUPLICATE DEMAND CLEANUP  mode=' . ($COMMIT ? '*** COMMIT ***' : 'DRY-RUN') . '  utc=' . gmdate('c'));

$all = $fs->query('feeDemands', [['schoolId', '==', TENANT]], null, 'ASC', 3000);
$keep = []; $cand = []; $paidSkip = 0; $otherStu = 0;
foreach ($all as $r) {
    $d = body($r); $id = (string) ($r['id'] ?? '');
    if ($id === '') continue;
    if (strpos($id, 'DEM_') === 0) { $keep[] = $id; continue; }          // G3
    if ((string) ($d['studentId'] ?? '') !== STUDENT) { $otherStu++; continue; } // G2
    if ((float) ($d['paidAmount'] ?? 0) > 0) { $paidSkip++; continue; }  // G4
    $cand[$id] = (string) ($d['category'] ?? '');
}
line('  total in tenant           : ' . count($all));
line('  KEEP (DEM_ deterministic) : ' . count($keep));
line('  candidates (random id)    : ' . count($cand));
line('  skipped, paid             : ' . $paidSkip);
line('  skipped, other student    : ' . $otherStu);

$byCat = [];
foreach ($cand as $c) $byCat[$c === '' ? '(empty)' : $c] = ($byCat[$c === '' ? '(empty)' : $c] ?? 0) + 1;
line('  candidate categories      : ' . json_encode($byCat));

line();
line('  G5 gate: expected ' . EXPECTED . ', actual ' . count($cand));
if (count($cand) !== EXPECTED) halt('count differs from the authorised ' . EXPECTED . '. Nothing deleted.');
if (count($keep) !== 26) halt('expected 26 originals to keep, found ' . count($keep) . '. Nothing deleted.');
line('  G5: PASS');

if (!$COMMIT) { line(); line('DRY-RUN — nothing deleted. Re-run with --commit.'); exit(0); }

line();
line('DELETING…');
$del = 0; $fail = 0;
foreach (array_keys($cand) as $id) {
    $ok = false;
    for ($a = 1; $a <= 3 && !$ok; $a++) {          // backoff — the R5 lesson
        $ok = (bool) $fs->deleteDocument('feeDemands', $id);
        if (!$ok) usleep(200000 * $a);
    }
    $ok ? $del++ : $fail++;
    if (!$ok) line('  FAILED ' . $id);
}
line('  deleted : ' . $del . '   failed : ' . $fail);
line();
line('Verify in a SEPARATE process — this script\'s own count is not evidence.');
