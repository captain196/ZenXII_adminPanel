<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * R5 — ORPHANED feeDemands CLEANUP  (Phase 20.3M UAT residue)
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Deletes ONLY the fee demands left behind by the Phase 20.3M live Import UAT,
 * whose student documents were deleted but whose demands were not.
 *
 * DRY-RUN BY DEFAULT (repo convention). Writes require --commit.
 *
 * ── GUARDS, ALL MANDATORY ───────────────────────────────────────────────────
 *  G1  schoolId  === SCH_B56BB9A401                    (exact tenant)
 *  G2  studentId ∈ the exact 13-id allow-list          (no wildcards)
 *  G3  the referenced student document DOES NOT EXIST  — re-checked per
 *      candidate IMMEDIATELY BEFORE its deletion, not once up front
 *  G4  pre-delete count MUST equal 481, else abort without deleting anything
 *
 * A candidate failing ANY guard is SKIPPED and reported. Nothing else in the
 * collection, and nothing in any other tenant, is touched. No student document
 * and no srNoIndex record is modified — R1 stays blocked.
 *
 * Usage:
 *   php scripts/r5_orphan_feedemand_cleanup.php            # DRY-RUN
 *   php scripts/r5_orphan_feedemand_cleanup.php --commit   # DELETE
 */

define('BASEPATH', __DIR__);
define('APPPATH', __DIR__ . '/../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../application/libraries/Firestore_rest_client.php';

const TENANT   = 'SCH_B56BB9A401';
// Option (A), authorised 2026-08-17: the first run deleted 227 of the verified
// 481 and threw 254 to throttling. The gate is re-armed to the EXACT surviving
// subset — 254 — NOT relaxed to "<= 481". A count other than 254 still aborts.
const EXPECTED = 254;
const ORIGINAL_AUTHORISED = 481;
const ALREADY_DELETED     = 227;   // 481 - 254, for the reconciliation arithmetic
const ALLOWED  = [
    'STU0168', 'STU0169', 'STU0170', 'STU0171', 'STU0172', 'STU0173', 'STU0174',
    'STU0175', 'STU0176', 'STU0177', 'STU0178', 'STU0179', 'STU0181',
];

$COMMIT = in_array('--commit', $argv, true);

$fs = new FirestoreRestClient(
    __DIR__ . '/../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json',
    'graderadmin', '(default)'
);

function body(array $r): array { return is_array($r['data'] ?? null) ? $r['data'] : $r; }
function line(string $s = '') { echo $s . "\n"; }
function head(string $s) { line(); line('── ' . $s . ' ' . str_repeat('─', max(0, 60 - strlen($s)))); }

line('════════════════════════════════════════════════════════════════════');
line(' R5 ORPHANED feeDemands CLEANUP');
line(' MODE: ' . ($COMMIT ? '*** COMMIT (deleting) ***' : 'DRY-RUN (no writes)'));
line(' tenant=' . TENANT . '  utc=' . gmdate('c'));
line('════════════════════════════════════════════════════════════════════');

// ── live student set, read fresh ────────────────────────────────────────────
$live = [];
foreach ($fs->query('students', [['schoolId', '==', TENANT]], null, 'ASC', 2000) as $r) {
    $d = body($r);
    $sid = (string) ($d['studentId'] ?? '');
    if ($sid !== '') $live[$sid] = true;
}

head('A · PRE-DELETE VERIFICATION');
line('live students in tenant : ' . count($live));

$allowed = array_flip(ALLOWED);
foreach (ALLOWED as $sid) {
    if (isset($live[$sid])) {
        line("  *** {$sid} EXISTS as a live student — it will be SKIPPED by G3");
    }
}

$demands = $fs->query('feeDemands', [['schoolId', '==', TENANT]], null, 'ASC', 3000);
line('feeDemands in tenant    : ' . count($demands));

$candidates = [];   // docId => studentId
$skipped    = [];
$byStudent  = [];
foreach ($demands as $r) {
    $d     = body($r);
    $docId = (string) ($r['id'] ?? '');
    $sid   = (string) ($d['studentId'] ?? '');
    $sch   = (string) ($d['schoolId'] ?? '');

    if ($docId === '' || $sid === '') continue;
    if ($sch !== TENANT)        { continue; }                                  // G1
    if (!isset($allowed[$sid])) { continue; }                                  // G2
    if (isset($live[$sid]))     { $skipped[] = "{$docId} ({$sid} is LIVE)"; continue; }  // G3

    $candidates[$docId] = $sid;
    $byStudent[$sid] = ($byStudent[$sid] ?? 0) + 1;
}

ksort($byStudent);
line('candidates (all guards) : ' . count($candidates));
foreach ($byStudent as $sid => $n) printf("    %-10s %d demands\n", $sid, $n);
if ($skipped) {
    line('SKIPPED by a guard:');
    foreach (array_slice($skipped, 0, 20) as $s) line('    ' . $s);
}

// ── G4 — the count gate ─────────────────────────────────────────────────────
head('G4 · COUNT GATE');
line('expected : ' . EXPECTED);
line('actual   : ' . count($candidates));
if (count($candidates) !== EXPECTED) {
    line();
    line('*** STOP — count does not match the authorised figure. NOTHING WAS DELETED. ***');
    line('    Re-verify before proceeding; the authorisation was for exactly ' . EXPECTED . '.');
    exit(2);
}
line('G4: PASS');

if (!$COMMIT) {
    head('DRY-RUN COMPLETE');
    line('Nothing was deleted. Re-run with --commit to apply.');
    exit(0);
}

// ── deletion, with a per-candidate re-check of G3 ───────────────────────────
head('B · DELETION');
$deleted = 0; $failed = 0; $lateSkip = 0;
$checked = [];   // studentId => bool (exists), re-checked once per student

foreach ($candidates as $docId => $sid) {
    // G3 re-checked immediately before deletion. Cached per student so this is
    // 13 reads, not 481 — but it IS a fresh read taken during this run, after
    // the candidate list was built.
    if (!array_key_exists($sid, $checked)) {
        $checked[$sid] = is_array($fs->getDocument('students', TENANT . '_' . $sid));
    }
    if ($checked[$sid]) {
        $lateSkip++;
        line("  SKIP {$docId} — student {$sid} exists on re-check");
        continue;
    }

    // DEFECT FIX: the first run had no retry/backoff and degraded to 227/481
    // (53%) without saying why. Firestore throttles a long delete burst; a
    // deletion tool must survive that rather than report a partial result as
    // if it were the whole job.
    $ok = false;
    for ($attempt = 1; $attempt <= 3 && !$ok; $attempt++) {
        $ok = (bool) $fs->deleteDocument('feeDemands', $docId);
        if (!$ok) usleep(200000 * $attempt);   // 200ms, 400ms
    }
    if ($ok) { $deleted++; } else { $failed++; line("  FAILED {$docId}"); }
}

line("deleted : {$deleted}");
line("failed  : {$failed}");
line("skipped on re-check : {$lateSkip}");

// ── post-verification ───────────────────────────────────────────────────────
head('C · POST-DELETE VERIFICATION');
// DEFECT FIX (2026-08-17): the first R5 run reused THIS client for the
// post-check after 481 delete round-trips, and the verification query came
// back EMPTY — so the script reported "0 remaining, 0 live demands" while 254
// orphans actually survived and 26 live demands were untouched. A false clean
// signal is the most dangerous possible output of a deletion tool, so the
// verification now runs on a FRESH client and refuses to trust an empty result.
$verifier = new FirestoreRestClient(
    __DIR__ . '/../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json',
    'graderadmin', '(default)'
);
$after = $verifier->query('feeDemands', [['schoolId', '==', TENANT]], null, 'ASC', 3000);
if (count($after) === 0 && $deleted < count($candidates)) {
    line('*** VERIFICATION UNTRUSTWORTHY: query returned 0 while deletes were incomplete.');
    line('    Re-verify in a separate process before believing any "clean" result. ***');
}
$remainingOrphans = 0; $liveDemands = 0; $foreign = 0;
foreach ($after as $r) {
    $d   = body($r);
    $sid = (string) ($d['studentId'] ?? '');
    if ((string) ($d['schoolId'] ?? '') !== TENANT) { $foreign++; continue; }
    if (isset($allowed[$sid]) && !isset($live[$sid])) $remainingOrphans++;
    if (isset($live[$sid])) $liveDemands++;
}
line('feeDemands remaining in tenant           : ' . count($after));
line('orphans remaining for the 13 student ids : ' . $remainingOrphans);
line('demands belonging to LIVE students       : ' . $liveDemands . '  (must be unchanged)');
line('documents from another tenant seen       : ' . $foreign . '  (must be 0)');
line();
line('student documents modified : 0   srNoIndex modified : 0');

// ── G · RECONCILIATION ARITHMETIC ───────────────────────────────────────────
// Required by the authorisation: deletions + failures + skips must account for
// EVERY pre-run candidate, and the surviving orphan count must independently
// agree. Two separate identities, both must hold — a single count can lie
// (as it did on the first run); two that agree cannot lie the same way.
head('G · RECONCILIATION ARITHMETIC');
$accounted = $deleted + $failed + $lateSkip;
$expectSurv = $failed + $lateSkip;
printf("  deleted + failed + skipped = %d + %d + %d = %d   (candidates = %d)\n",
    $deleted, $failed, $lateSkip, $accounted, count($candidates));
printf("  orphans expected to survive = failed + skipped = %d   observed = %d\n",
    $expectSurv, $remainingOrphans);
printf("  cumulative vs original authorisation: %d already + %d now = %d of %d\n",
    ALREADY_DELETED, $deleted, ALREADY_DELETED + $deleted, ORIGINAL_AUTHORISED);

$ok1 = ($accounted === count($candidates));
$ok2 = ($remainingOrphans === $expectSurv);
$ok3 = ($foreign === 0);
line('  candidate accounting : ' . ($ok1 ? 'RECONCILES' : '*** MISMATCH ***'));
line('  survivor accounting  : ' . ($ok2 ? 'RECONCILES' : '*** MISMATCH ***'));
line('  foreign-tenant docs  : ' . ($ok3 ? 'none' : '*** ' . $foreign . ' SEEN ***'));

if (!$ok1 || !$ok2 || !$ok3) {
    line();
    line('*** STOP — the arithmetic does not reconcile. R5 is NOT complete. ***');
    line('    Re-verify from a separate process before drawing any conclusion.');
    exit(3);
}
line();
line($remainingOrphans === 0
    ? 'R5 ORPHAN CLEANUP: COMPLETE — 0 authorised orphans remain, arithmetic reconciles.'
    : 'R5 INCOMPLETE — ' . $remainingOrphans . ' orphans remain. Do NOT claim completion.');
line('── end ──');
