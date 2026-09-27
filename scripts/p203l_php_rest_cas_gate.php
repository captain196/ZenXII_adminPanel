<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * PHASE 20.3L — PART 2: PHP REST CAS GATE  (MANDATORY)
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Phase 20.3g proved FAILED_PRECONDITION using Node + the Admin SDK (gRPC).
 * The application does NOT use that path — it uses FirestoreRestClient over
 * HTTPS/REST. Until the REST error body is OBSERVED, commitBatchStatus()'s
 * CONFLICT classification is an inference from Google's documentation, and the
 * whole refusal contract rests on it.
 *
 * This script observes it, end to end:
 *
 *     Firestore REST response
 *          ↓  (raw body captured verbatim — not paraphrased)
 *     FirestoreRestClient::request()
 *          ↓
 *     commitBatchStatus()
 *          ↓
 *     PRECONDITION_CONFLICT / FAILURE classification
 *
 * ── WHAT IT WRITES ──────────────────────────────────────────────────────────
 * ONE field — `seatCasAt` — on ONE section document of the authorized testing
 * tenant. That is the guard's own opaque marker: occupancy is never read from
 * it (SEC-11), so writing it changes no roster, no capacity and no identity.
 * No student document is created, moved or deleted. No counter is touched.
 *
 * Usage:  php scripts/p203l_php_rest_cas_gate.php "Class 10th" "Section F"
 */

define('BASEPATH', __DIR__);
define('APPPATH', __DIR__ . '/../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../application/libraries/Firestore_rest_client.php';

const SCHOOL  = 'SCH_B56BB9A401';
const SESSION = '2026-27';

$class   = $argv[1] ?? 'Class 10th';
$section = $argv[2] ?? 'Section F';
$docId   = SCHOOL . '_' . SESSION . '_' . $class . '_' . $section;

$sa = __DIR__ . '/../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
$fs = new FirestoreRestClient($sa, 'graderadmin', '(default)');

/** Reach the client's own private HTTP layer so the RAW body is the one the app sees. */
function privateCall(FirestoreRestClient $fs, string $method, array $args = [])
{
    $m = new ReflectionMethod(FirestoreRestClient::class, $method);
    $m->setAccessible(true);
    return $m->invoke($fs, ...$args);
}

function line(string $s = ''): void { echo $s . "\n"; }
function head(string $s): void { line(); line("── {$s} " . str_repeat('─', max(0, 60 - strlen($s)))); }

line("════════════════════════════════════════════════════════════════════");
line(" PHASE 20.3L PART 2 — PHP REST CAS GATE");
line(" tenant=" . SCHOOL . "  doc=sections/{$docId}");
line(" utc=" . gmdate('c'));
line("════════════════════════════════════════════════════════════════════");

// ── A. capture the current updateTime ───────────────────────────────────────
head('A. capture current updateTime');
$doc = $fs->getDocument('sections', $docId);
if (!is_array($doc)) { line("FATAL: section not found — aborting, nothing written."); exit(2); }
$U1 = (string) ($doc['__updateTime'] ?? '');
line("className     = " . ($doc['className'] ?? '?'));
line("section       = " . ($doc['section'] ?? '?'));
line("maxStrength   = " . ($doc['maxStrength'] ?? '(absent → D17 default 40)'));
line("seatCasAt     = " . ($doc['seatCasAt'] ?? '(absent)'));
line("U1 updateTime = {$U1}");
if ($U1 === '') { line("FATAL: no __updateTime — the guard would refuse; aborting."); exit(2); }

$marker = static fn() => ['op' => 'update', 'collection' => 'sections', 'docId' => $docId,
                          'merge' => true, 'data' => ['seatCasAt' => date('c')]];

// ── B. commit with the CURRENT updateTime → expect SUCCESS ──────────────────
head('B. commit with CURRENT updateTime  → expect SUCCESS');
$op = $marker(); $op['precondition'] = ['updateTime' => $U1];
$resB = $fs->commitBatchStatus([$op]);
line('commitBatchStatus => ' . json_encode($resB));

$doc2 = $fs->getDocument('sections', $docId);
$U2 = (string) ($doc2['__updateTime'] ?? '');
line("U2 updateTime = {$U2}");
line('updateTime moved: ' . ($U2 !== $U1 ? 'YES' : 'NO'));

// ── C/D/E. re-commit with the now-STALE U1 → expect PRECONDITION_CONFLICT ───
head('C/D/E. commit with STALE updateTime  → expect PRECONDITION_CONFLICT');
line("stale precondition = U1 = {$U1}   (document is now at U2)");

// E1 — the RAW REST exchange, so the body is observed and not inferred.
$dbPrefix = "projects/graderadmin/databases/(default)/documents";
$rawBody  = [
    'writes' => [[
        'update'       => [
            'name'   => "{$dbPrefix}/sections/" . $docId,
            'fields' => ['seatCasAt' => ['stringValue' => date('c')]],
        ],
        'updateMask'      => ['fieldPaths' => ['seatCasAt']],
        'currentDocument' => ['updateTime' => $U1],
    ]],
];
// baseUrl() ends at /documents, so appending ':commit' yields the exact URL
// the application itself posts to.
$url = privateCall($fs, 'baseUrl') . ':commit';
$raw = privateCall($fs, 'request', ['POST', $url, $rawBody]);

line('RAW HTTP code = ' . ($raw['code'] ?? '?'));
line('RAW BODY (verbatim):');
line(json_encode($raw['body'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$errStatus = (string) ($raw['body']['error']['status'] ?? '');
$errMsg    = (string) ($raw['body']['error']['message'] ?? '');
line("error.status  = '{$errStatus}'");
line("error.message = '{$errMsg}'");

// E2 — the same stale commit THROUGH the application classifier.
head('E2. same stale commit through commitBatchStatus()');
$op2 = $marker(); $op2['precondition'] = ['updateTime' => $U1];
$resE = $fs->commitBatchStatus([$op2]);
line('commitBatchStatus => ' . json_encode($resE));

// ── verdict ─────────────────────────────────────────────────────────────────
head('VERDICT');
$doc3 = $fs->getDocument('sections', $docId);
$U3 = (string) ($doc3['__updateTime'] ?? '');

$checks = [
    'B: fresh precondition committed'            => ($resB['status'] ?? '') === 'SUCCESS',
    'B: updateTime advanced'                     => $U2 !== '' && $U2 !== $U1,
    'E1: raw HTTP code is 400'                   => (int) ($raw['code'] ?? 0) === 400,
    'E1: raw error.status FAILED_PRECONDITION'   => $errStatus === 'FAILED_PRECONDITION',
    'E2: classified PRECONDITION_CONFLICT'       => ($resE['status'] ?? '') === 'PRECONDITION_CONFLICT',
    'E2: NOT misclassified as FAILURE'           => ($resE['status'] ?? '') !== 'FAILURE',
    'E: stale commit wrote NOTHING (U3 == U2)'   => $U3 === $U2,
];
$allPass = true;
foreach ($checks as $what => $ok) {
    line(sprintf('  [%s] %s', $ok ? 'PASS' : 'FAIL', $what));
    $allPass = $allPass && $ok;
}
line();
line($allPass
    ? 'GATE RESULT: PASS — the PHP REST path classifies a real Firestore CAS conflict correctly.'
    : 'GATE RESULT: FAIL — STOP. Do not enable the flag.');
line("final updateTime = {$U3}   seatCasAt = " . ($doc3['seatCasAt'] ?? '(absent)'));
exit($allPass ? 0 : 1);
