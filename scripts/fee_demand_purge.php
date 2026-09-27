<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * feeDemands — PURGE  ⚠ DESTRUCTIVE
 * ══════════════════════════════════════════════════════════════════════════
 *
 *   php scripts/fee_demand_purge.php --snapshot=<FILE> [SCHOOL_ID]        # dry run
 *   php scripts/fee_demand_purge.php --snapshot=<FILE> [SCHOOL_ID] --commit
 *
 * Deletes `feeDemands` documents. **Dry run by default** — `--commit` is
 * required to write, matching the convention every other script in `scripts/`
 * follows.
 *
 * ── THE SAFETY INTERLOCK ──────────────────────────────────────────────────
 * This refuses to delete ANY document that is not present in the supplied
 * snapshot. Not "warns" — refuses. A delete without a restore source is
 * irreversible, and Firestore has no undo, so the snapshot is treated as a
 * precondition rather than a courtesy.
 *
 * It also refuses outright if any demand carries a payment. Deleting a demand
 * money has been collected against would strand a receipt and break accounting
 * reconciliation; that is a different operation with different consequences and
 * must not ride along on this one.
 *
 * ── SCOPE ─────────────────────────────────────────────────────────────────
 * `feeDemands` ONLY. Derived collections (`feeDefaulters`, fee summaries,
 * `feeCarryForward`) are deliberately NOT touched — they are separately owned
 * and self-healing, and quietly widening a destructive scope is how a targeted
 * delete becomes an incident. They are reported so the operator can see what
 * will read as stale afterwards.
 */

putenv('DISABLE_DOC_CACHE=1');
define('BASEPATH', __DIR__);
define('APPPATH', dirname(__DIR__) . '/application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once APPPATH . 'libraries/Firestore_rest_client.php';

$snapFile = ''; $only = ''; $commit = false;
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--snapshot=') === 0) { $snapFile = substr($a, 11); continue; }
    if ($a === '--commit') { $commit = true; continue; }
    if (strpos($a, '--') !== 0 && $only === '') $only = trim($a);
}

if ($snapFile === '') {
    fwrite(STDERR, "usage: php scripts/fee_demand_purge.php --snapshot=<FILE> [SCHOOL_ID] [--commit]\n");
    fwrite(STDERR, "  a verified snapshot is REQUIRED — this deletion must stay reversible\n");
    exit(2);
}
if (!is_file($snapFile)) { fwrite(STDERR, "snapshot not found: {$snapFile}\n"); exit(2); }

$snap = json_decode((string) file_get_contents($snapFile), true);
if (!is_array($snap) || !is_array($snap['documents'] ?? null)) {
    fwrite(STDERR, "snapshot is malformed: {$snapFile}\n"); exit(2);
}
$restorable = $snap['documents'];

$c    = new FirestoreRestClient(
    APPPATH . 'config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json',
    'graderadmin', '(default)');
$body = function ($r) { return is_array($r['data'] ?? null) ? $r['data'] : $r; };

echo "feeDemands PURGE — " . ($commit ? "⚠ COMMIT (DESTRUCTIVE)" : "DRY RUN") . "\n";
echo 'scope    : ' . ($only !== '' ? $only : 'ALL TENANTS') . "\n";
echo 'snapshot : ' . basename($snapFile) . ' (' . count($restorable) . " documents)\n";
echo str_repeat('=', 74) . "\n\n";

$rows = $c->query('feeDemands', $only !== '' ? [['schoolId', '==', $only]] : []);

$targets = []; $unbacked = []; $withPayment = [];
$byTenant = []; $gross = 0.0;

foreach ($rows as $r) {
    $id = (string) ($r['id'] ?? '');
    if ($id === '') continue;
    $d  = $body($r);

    if (!array_key_exists($id, $restorable)) { $unbacked[] = $id; continue; }
    if ((float) ($d['paidAmount'] ?? 0) > 0) { $withPayment[] = $id; continue; }

    $targets[] = $id;
    $sid = (string) ($d['schoolId'] ?? '?');
    $byTenant[$sid] = ($byTenant[$sid] ?? 0) + 1;
    $gross += (float) ($d['grossAmount'] ?? 0);
}

printf("  live documents in scope : %d\n", count($rows));
printf("  deletable (in snapshot) : %d\n", count($targets));
printf("  per tenant              : %s\n", json_encode($byTenant));
printf("  gross value removed     : %.2f\n", round($gross, 2));
printf("  NOT backed by snapshot  : %d\n", count($unbacked));
printf("  carrying a payment      : %d\n", count($withPayment));

// ── refusals ──────────────────────────────────────────────────────────────
if ($unbacked !== []) {
    echo "\nREFUSING: " . count($unbacked) . " document(s) are not in the snapshot.\n";
    foreach (array_slice($unbacked, 0, 5) as $x) echo "   {$x}\n";
    echo "Re-run the snapshot so every document has a restore source, then retry.\n";
    exit(1);
}
if ($withPayment !== []) {
    echo "\nREFUSING: " . count($withPayment) . " document(s) carry a payment.\n";
    foreach (array_slice($withPayment, 0, 5) as $x) echo "   {$x}\n";
    echo "Deleting a paid demand strands its receipt and breaks reconciliation.\n";
    exit(1);
}

// ── what will look stale afterwards ───────────────────────────────────────
echo "\n── derived collections (NOT touched by this script) " . str_repeat('─', 23) . "\n";
foreach (['feeDefaulters', 'feeCarryForward', 'feeSummary', 'entitlementAllocations'] as $col) {
    try { printf("  %-24s %d doc(s)\n", $col, count($c->query($col, []))); }
    catch (\Throwable $e) { printf("  %-24s n/a\n", $col); }
}
echo "  These are separately owned and mostly self-healing. They are listed so\n";
echo "  nothing about their state after the purge comes as a surprise.\n";

if (!$commit) {
    echo "\n" . str_repeat('=', 74) . "\n";
    printf("DRY RUN — nothing deleted. %d document(s) would be removed.\n", count($targets));
    echo "Re-run with --commit to execute.\n";
    exit(0);
}

// ── execute ───────────────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 74) . "\n";
echo "DELETING " . count($targets) . " document(s)...\n";

$ok = 0; $failed = [];
foreach ($targets as $i => $id) {
    try {
        if ($c->deleteDocument('feeDemands', $id)) $ok++;
        else $failed[] = $id;
    } catch (\Throwable $e) {
        $failed[] = $id . ' (' . $e->getMessage() . ')';
    }
    if (($i + 1) % 200 === 0) printf("  ... %d/%d\n", $i + 1, count($targets));
}

printf("\ndeleted : %d\n", $ok);
printf("failed  : %d\n", count($failed));
foreach (array_slice($failed, 0, 10) as $f) echo "   {$f}\n";

$remaining = $c->query('feeDemands', $only !== '' ? [['schoolId', '==', $only]] : []);
printf("\nremaining in scope: %d\n", count($remaining));
echo "\nRestore source: {$snapFile}\n";
echo "Verify with: php scripts/fee_demand_snapshot.php {$snapFile} --verify\n";

exit(count($failed) === 0 ? 0 : 1);
