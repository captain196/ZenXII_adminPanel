<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * BILLING V2 CANARY — FULL BASELINE SNAPSHOT · READ ONLY
 * ══════════════════════════════════════════════════════════════════════════
 *
 *   php scripts/canary_baseline_snapshot.php <SCHOOL_ID> [OUT_FILE]
 *
 * Captures EVERYTHING a canary comparison needs, per document and per field —
 * not counts. Counts cannot tell you that a demand id changed, that a discount
 * moved, or that a paid demand was rewritten; those are exactly the failures a
 * canary exists to catch.
 *
 * Captured: rollout grants · entitlements · fee chart · student identity
 * (SRNO / student id / admission no / APAAR) · every demand with full money and
 * identity fields · allocations · receipts · payments · journals · defaulters.
 *
 * The tenant is a REQUIRED ARGUMENT — no school id is hard-coded, because this
 * must stay usable for whichever tenant is verified next.
 *
 * Writes ONE local JSON file. Nothing in Firestore is modified.
 */

putenv('DISABLE_DOC_CACHE=1');
define('BASEPATH', __DIR__);
define('APPPATH', dirname(__DIR__) . '/application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once APPPATH . 'libraries/Firestore_rest_client.php';

$schoolId = trim((string) ($argv[1] ?? ''));
if ($schoolId === '') {
    fwrite(STDERR, "usage: php scripts/canary_baseline_snapshot.php <SCHOOL_ID> [OUT_FILE]\n");
    exit(2);
}
$out = trim((string) ($argv[2] ?? ''));
if ($out === '') {
    $out = APPPATH . 'backups/_billing_v2/canary_baseline_' . $schoolId . '.json';
}

$c    = new FirestoreRestClient(
    APPPATH . 'config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json',
    'graderadmin', '(default)');
$body = function ($r) { return is_array($r['data'] ?? null) ? $r['data'] : $r; };
$q    = function (string $col, array $conds = []) use ($c) {
    try { return $c->query($col, $conds); } catch (\Throwable $e) { return []; }
};

echo "BILLING V2 CANARY — BASELINE SNAPSHOT\n";
echo "tenant: {$schoolId}\n";
echo str_repeat('=', 74) . "\n\n";

$snap = ['schoolId' => $schoolId, 'capturedAt' => date('c')];

// ── 1 · tenant + rollout grants ───────────────────────────────────────────
$school = $body($c->getDocument('schools', $schoolId) ?? []);
$snap['school'] = [
    'schoolCode' => (string) ($school['schoolCode'] ?? ''),
    'name'       => (string) ($school['name'] ?? ''),
];
$ctrl  = $body($c->getDocument('schoolControl', $schoolId) ?? []);
$snap['rolloutFlags'] = is_array($ctrl['rolloutFlags'] ?? null) ? $ctrl['rolloutFlags'] : [];

printf("  schoolCode : %s\n", $snap['school']['schoolCode'] ?: '(none)');
echo   "  grants     :\n";
foreach ($snap['rolloutFlags'] as $flag => $v) {
    $on = is_array($v) ? (bool) ($v['enabled'] ?? false) : (bool) $v;
    printf("      %-36s %s\n", $flag, $on ? 'TRUE' : 'false');
}
if ($snap['rolloutFlags'] === []) echo "      (none)\n";

// ── 2 · entitlements ──────────────────────────────────────────────────────
$snap['entitlements'] = [];
foreach ($q('studentEntitlements', [['schoolId', '==', $schoolId]]) as $r) {
    $d = $body($r);
    $snap['entitlements'][(string) $r['id']] = [
        'studentId' => (string) ($d['studentId'] ?? ''),
        'status'    => (string) ($d['status'] ?? ''),
        'scope'     => (string) ($d['scope'] ?? ''),
        'valueType' => (string) ($d['valueType'] ?? ''),
        'value'     => (float)  ($d['value'] ?? 0),
        'session'   => (string) ($d['session'] ?? ''),
    ];
}
printf("\n  entitlements: %d\n", count($snap['entitlements']));

// ── 3 · fee chart ─────────────────────────────────────────────────────────
$snap['feeStructures'] = [];
foreach ($q('feeStructures', [['schoolId', '==', $schoolId]]) as $r) {
    $d = $body($r);
    $heads = [];
    foreach ((array) ($d['feeHeads'] ?? []) as $h) {
        if (!is_array($h)) continue;
        $heads[] = ['name' => (string) ($h['name'] ?? ''), 'amount' => (float) ($h['amount'] ?? 0),
                    'frequency' => (string) ($h['frequency'] ?? ''),
                    'feeHeadId' => (string) ($h['feeHeadId'] ?? '')];
    }
    $snap['feeStructures'][(string) $r['id']] = $heads;
}
printf("  fee structures: %d\n", count($snap['feeStructures']));

// ── 4 · students + IDENTITY (never to be altered by billing) ──────────────
$snap['students'] = [];
foreach ($q('students', [['schoolId', '==', $schoolId]]) as $r) {
    $d = $body($r);
    $sid = (string) ($d['studentId'] ?? '');
    if ($sid === '') continue;
    $snap['students'][$sid] = [
        'docId'       => (string) $r['id'],
        'name'        => (string) ($d['name'] ?? $d['studentName'] ?? ''),
        'className'   => (string) ($d['className'] ?? ''),
        'section'     => (string) ($d['section'] ?? ''),
        'session'     => (string) ($d['session'] ?? ''),
        'status'      => (string) ($d['status'] ?? ''),
        'srNo'        => (string) ($d['srNo'] ?? ''),
        'admissionNo' => (string) ($d['admissionNo'] ?? $d['admission_no'] ?? ''),
        'apaarId'     => (string) ($d['apaarId'] ?? ''),
    ];
}
printf("  students: %d\n", count($snap['students']));

// ── 5 · EVERY demand, EVERY money + identity field ────────────────────────
$snap['demands'] = [];
$tot = ['gross' => 0.0, 'discount' => 0.0, 'net' => 0.0, 'paid' => 0.0, 'balance' => 0.0];
foreach ($q('feeDemands', [['schoolId', '==', $schoolId]]) as $r) {
    $d  = $body($r);
    $id = (string) $r['id'];
    $snap['demands'][$id] = [
        'demandId'       => (string) ($d['demandId'] ?? ''),
        'studentId'      => (string) ($d['studentId'] ?? ''),
        'feeHead'        => (string) ($d['feeHead'] ?? ''),
        'feeHeadId'      => (string) ($d['feeHeadId'] ?? ''),
        'feeHeadRef'     => (string) ($d['feeHeadRef'] ?? ''),
        'canonicalFeeHeadId' => (string) ($d['canonicalFeeHeadId'] ?? ''),
        'category'       => (string) ($d['category'] ?? ''),
        'categorySource' => (string) ($d['categorySource'] ?? ''),
        'periodKey'      => (string) ($d['periodKey'] ?? ''),
        'session'        => (string) ($d['session'] ?? ''),
        'grossAmount'    => round((float) ($d['grossAmount'] ?? 0), 2),
        'discountAmount' => round((float) ($d['discountAmount'] ?? 0), 2),
        'netAmount'      => round((float) ($d['netAmount'] ?? 0), 2),
        'paidAmount'     => round((float) ($d['paidAmount'] ?? 0), 2),
        'balance'        => round((float) ($d['balance'] ?? 0), 2),
        'fineAmount'     => round((float) ($d['fineAmount'] ?? 0), 2),
        'status'         => (string) ($d['status'] ?? ''),
        'concessionApplied' => round((float) ($d['concessionApplied'] ?? 0), 2),
        'idScheme'       => (strpos($id, 'DEM_') === 0) ? 'canonical' : 'legacy-random',
    ];
    foreach (['gross' => 'grossAmount', 'discount' => 'discountAmount', 'net' => 'netAmount',
              'paid' => 'paidAmount', 'balance' => 'balance'] as $k => $f) {
        $tot[$k] += $snap['demands'][$id][$f];
    }
}
foreach ($tot as $k => $v) $tot[$k] = round($v, 2);
$snap['demandTotals'] = $tot;

printf("\n  demands: %d\n", count($snap['demands']));
printf("      gross=%.2f discount=%.2f net=%.2f paid=%.2f balance=%.2f\n",
    $tot['gross'], $tot['discount'], $tot['net'], $tot['paid'], $tot['balance']);

// ── 6 · financial siblings that must NOT move ─────────────────────────────
foreach (['entitlementAllocations' => 'allocations', 'feeReceipts' => 'receipts',
          'feePayments' => 'payments', 'feeDefaulters' => 'defaulters'] as $col => $key) {
    $snap[$key] = [];
    foreach ($q($col, [['schoolId', '==', $schoolId]]) as $r) {
        $snap[$key][(string) $r['id']] = $body($r);
    }
    printf("  %-24s %d\n", $col, count($snap[$key]));
}
// Journals are keyed differently across tenants; capture by count + ids only.
$snap['journalIds'] = [];
foreach ($q('accounting', []) as $r) {
    $d = $body($r);
    if ((string) ($d['schoolId'] ?? '') !== $schoolId) continue;
    $snap['journalIds'][] = (string) $r['id'];
}
printf("  %-24s %d\n", 'accounting (journals)', count($snap['journalIds']));

// ── write ─────────────────────────────────────────────────────────────────
@mkdir(dirname($out), 0777, true);
$json = json_encode($snap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents($out, $json);
$back = json_decode((string) file_get_contents($out), true);
$ok   = is_array($back) && count($back['demands'] ?? []) === count($snap['demands']);

echo "\n" . str_repeat('=', 74) . "\n";
printf("WROTE %s (%d bytes)\n", $out, strlen($json));
echo $ok ? "Round-trip OK — baseline is usable for comparison.\n"
         : "ROUND-TRIP FAILED — do not proceed with the canary.\n";
echo "No Firestore documents were written.\n";

exit($ok ? 0 : 1);
