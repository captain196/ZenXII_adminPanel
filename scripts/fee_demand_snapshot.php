<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * feeDemands — FULL SNAPSHOT · READ ONLY
 * ══════════════════════════════════════════════════════════════════════════
 *
 *   php scripts/fee_demand_snapshot.php <OUT_FILE> [SCHOOL_ID]
 *
 * Exports every `feeDemands` document — complete document bodies, keyed by
 * document id — to a local JSON file, together with a manifest recording the
 * counts and money totals at capture time.
 *
 * This exists to make a deletion REVERSIBLE. It is the precondition for
 * `fee_demand_purge.php`, which refuses to run without a verified snapshot.
 *
 * **Reads only.** Nothing in Firestore is modified.
 *
 * The manifest is the important half: a snapshot whose completeness cannot be
 * checked is not a backup, it is a hope. `--verify` re-reads the file and
 * re-compares it against live, so the same code proves the restore source is
 * whole both before and after the delete.
 *
 *   php scripts/fee_demand_snapshot.php <FILE> --verify
 */

putenv('DISABLE_DOC_CACHE=1');
define('BASEPATH', __DIR__);
define('APPPATH', dirname(__DIR__) . '/application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once APPPATH . 'libraries/Firestore_rest_client.php';

$out    = trim((string) ($argv[1] ?? ''));
$arg2   = trim((string) ($argv[2] ?? ''));
$verify = ($arg2 === '--verify');
$only   = $verify ? '' : $arg2;

if ($out === '') {
    fwrite(STDERR, "usage: php scripts/fee_demand_snapshot.php <OUT_FILE> [SCHOOL_ID|--verify]\n");
    exit(2);
}

$c    = new FirestoreRestClient(
    APPPATH . 'config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json',
    'graderadmin', '(default)');
$body = function ($r) { return is_array($r['data'] ?? null) ? $r['data'] : $r; };

echo "feeDemands SNAPSHOT" . ($verify ? ' — VERIFY' : '') . "\n";
echo str_repeat('=', 74) . "\n\n";

// ── read live ─────────────────────────────────────────────────────────────
$rows = $c->query('feeDemands', $only !== '' ? [['schoolId', '==', $only]] : []);

$docs = [];
$stats = ['total' => 0, 'gross' => 0.0, 'net' => 0.0, 'discount' => 0.0,
          'paid' => 0.0, 'balance' => 0.0, 'byTenant' => [], 'byStatus' => [],
          'students' => []];

foreach ($rows as $r) {
    $id = (string) ($r['id'] ?? '');
    if ($id === '') continue;
    $d  = $body($r);
    $docs[$id] = $d;

    $stats['total']++;
    $stats['gross']    += (float) ($d['grossAmount']    ?? 0);
    $stats['net']      += (float) ($d['netAmount']      ?? 0);
    $stats['discount'] += (float) ($d['discountAmount'] ?? 0);
    $stats['paid']     += (float) ($d['paidAmount']     ?? 0);
    $stats['balance']  += (float) ($d['balance']        ?? 0);
    $sid = (string) ($d['schoolId'] ?? '?');
    $st  = (string) ($d['status']   ?? '?');
    $stats['byTenant'][$sid] = ($stats['byTenant'][$sid] ?? 0) + 1;
    $stats['byStatus'][$st]  = ($stats['byStatus'][$st]  ?? 0) + 1;
    $stats['students'][(string) ($d['studentId'] ?? '?')] = 1;
}
foreach (['gross','net','discount','paid','balance'] as $k) $stats[$k] = round($stats[$k], 2);
$stats['studentCount'] = count($stats['students']);
unset($stats['students']);

$show = function (array $s) {
    printf("  documents : %d\n", $s['total']);
    printf("  students  : %d\n", $s['studentCount']);
    printf("  tenants   : %s\n", json_encode($s['byTenant']));
    printf("  status    : %s\n", json_encode($s['byStatus']));
    printf("  gross=%.2f net=%.2f discount=%.2f paid=%.2f balance=%.2f\n",
        $s['gross'], $s['net'], $s['discount'], $s['paid'], $s['balance']);
};

echo "LIVE:\n"; $show($stats);

// ── verify mode ───────────────────────────────────────────────────────────
if ($verify) {
    if (!is_file($out)) { fwrite(STDERR, "\nsnapshot not found: {$out}\n"); exit(1); }
    $snap = json_decode((string) file_get_contents($out), true);
    if (!is_array($snap) || !isset($snap['documents'], $snap['manifest'])) {
        fwrite(STDERR, "\nsnapshot is malformed\n"); exit(1);
    }
    echo "\nSNAPSHOT (" . basename($out) . "):\n"; $show($snap['manifest']);

    $missing = [];
    foreach ($docs as $id => $_) if (!array_key_exists($id, $snap['documents'])) $missing[] = $id;
    $extra = [];
    foreach ($snap['documents'] as $id => $_) if (!array_key_exists($id, $docs)) $extra[] = $id;

    echo "\n" . str_repeat('=', 74) . "\n";
    printf("live docs not in snapshot : %d\n", count($missing));
    printf("snapshot docs not in live : %d  (expected after a delete)\n", count($extra));
    foreach (array_slice($missing, 0, 5) as $m) echo "   MISSING {$m}\n";

    $ok = ($missing === []);
    echo $ok
        ? "\nVERIFIED — the snapshot covers every live document.\n"
        : "\nNOT VERIFIED — the snapshot is incomplete. Do NOT delete.\n";
    exit($ok ? 0 : 1);
}

// ── write ─────────────────────────────────────────────────────────────────
$payload = [
    'collection' => 'feeDemands',
    'capturedAt' => date('c'),
    'scope'      => $only !== '' ? $only : 'ALL_TENANTS',
    'manifest'   => $stats,
    'documents'  => $docs,
];
$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($json === false) { fwrite(STDERR, "\nJSON encode failed\n"); exit(1); }
file_put_contents($out, $json);

// Read it straight back — a file that cannot be re-parsed is not a backup.
$back = json_decode((string) file_get_contents($out), true);
$rt   = is_array($back) && count($back['documents'] ?? []) === $stats['total'];

echo "\n" . str_repeat('=', 74) . "\n";
printf("WROTE %s  (%d bytes)\n", $out, strlen($json));
printf("round-trip re-read: %s (%d documents)\n",
    $rt ? 'OK' : 'FAILED', count($back['documents'] ?? []));
echo $rt ? "Snapshot is complete and re-readable.\n"
         : "Snapshot did NOT round-trip. Do NOT delete.\n";

exit($rt ? 0 : 1);
