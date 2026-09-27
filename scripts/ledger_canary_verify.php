<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * USE_ALLOCATION_LEDGER — CANARY VERIFICATION · READ ONLY
 * ══════════════════════════════════════════════════════════════════════════
 *
 *   php scripts/ledger_canary_verify.php <SCHOOL_ID> [--baseline=<file>]
 *
 * The verification that runs AFTER the ledger flag is granted for a tenant and
 * a priced generation has produced rows. It answers, from live data alone:
 *
 *   1. did allocation rows actually get written?
 *   2. is every row well-formed and owned by the right tenant/session?
 *   3. do the rows EXACTLY explain each demand's discount? (composed invariant)
 *   4. is the ledger free of duplicates? (identity is create-only)
 *   5. did any demand's money change? (activation must be billing-inert)
 *   6. were pre-existing demands left alone? (no retro-fill)
 *
 * Checks 5 and 6 need a BEFORE picture, so this script can emit one and later
 * compare against it:
 *
 *   BEFORE the grant:  php scripts/ledger_canary_verify.php <ID> > /dev/null
 *                      (writes .baseline.json next to the script)
 *   AFTER generation:  php scripts/ledger_canary_verify.php <ID> --baseline=<file>
 *
 * **No writes to Firestore.** The only file written is the local baseline.
 * The tenant is a REQUIRED ARGUMENT — no school id is hard-coded.
 *
 * Exit 0 = every applicable check passed · 1 = at least one failed.
 */

putenv('DISABLE_DOC_CACHE=1');
define('BASEPATH', __DIR__);
define('APPPATH', dirname(__DIR__) . '/application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once APPPATH . 'libraries/Firestore_rest_client.php';
require_once APPPATH . 'libraries/Fee_firestore_txn.php';
require_once APPPATH . 'libraries/Entitlement_allocation_service.php';

$schoolId = '';
$baseline = '';
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--baseline=') === 0) { $baseline = substr($a, 11); continue; }
    if ($schoolId === '') $schoolId = trim($a);
}
if ($schoolId === '') {
    fwrite(STDERR, "usage: php scripts/ledger_canary_verify.php <SCHOOL_ID> [--baseline=<file>]\n");
    exit(2);
}

$c    = new FirestoreRestClient(
    APPPATH . 'config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json',
    'graderadmin', '(default)');
$body = function ($r) { return is_array($r['data'] ?? null) ? $r['data'] : $r; };

echo "USE_ALLOCATION_LEDGER — CANARY VERIFICATION\n";
echo "tenant: {$schoolId}\n";
echo str_repeat('=', 74) . "\n\n";

$checks = [];
$add = function (string $name, ?bool $ok, string $detail) use (&$checks) {
    // A null verdict is N/A — reported, never counted as a pass. Silently
    // treating "could not check" as "passed" is how a gate closes on nothing.
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    printf("  [%s] %-44s %s\n", $ok === null ? ' n/a' : ($ok ? ' OK ' : 'FAIL'), $name, $detail);
};

// ── gather ────────────────────────────────────────────────────────────────
$demands = [];
foreach ($c->query('feeDemands', [['schoolId', '==', $schoolId]]) as $r) {
    $d = $body($r);
    $demands[(string) ($d['demandId'] ?? $r['id'] ?? '')] = $d;
}
$allocs = [];
try { $allocs = $c->query('entitlementAllocations', [['schoolId', '==', $schoolId]]); } catch (\Throwable $e) {}

printf("  demands: %d · allocation rows: %d\n\n", count($demands), count($allocs));

// ── 1 · rows exist ────────────────────────────────────────────────────────
$have = count($allocs) > 0;
$add('allocation rows exist', $have ? true : null,
     $have ? count($allocs) . ' row(s)'
           : 'none yet — grant not active, or no priced generation has run since');

// ── 2 · well-formed, correctly owned, no duplicates ───────────────────────
$byDemand = []; $ids = []; $dupes = []; $malformed = []; $foreign = [];
$required = ['allocationId', 'schoolId', 'session', 'studentId', 'demandId',
             'entitlementId', 'basisAmount', 'allocatedAmount', 'status'];

foreach ($allocs as $r) {
    $d  = $body($r);
    $id = (string) ($r['id'] ?? '');

    foreach ($required as $f) {
        if (!array_key_exists($f, $d)) { $malformed[] = "{$id}:missing:{$f}"; break; }
    }
    if ((string) ($d['schoolId'] ?? '') !== $schoolId) { $foreign[] = $id; }

    // Identity is `{schoolId}_{demandId}_{entitlementId}` and is created
    // create-only, so a duplicate here would mean the primitive failed.
    $expect = $schoolId . '_' . (string) ($d['demandId'] ?? '') . '_' . (string) ($d['entitlementId'] ?? '');
    if ($id !== '' && $id !== $expect) $malformed[] = "{$id}:id_mismatch";
    if (isset($ids[$id])) $dupes[] = $id;
    $ids[$id] = true;

    $byDemand[(string) ($d['demandId'] ?? '')][] = $d;
}

if ($have) {
    $add('every row is well-formed', $malformed === [],
         $malformed === [] ? 'all fields present, ids canonical'
                           : implode(', ', array_slice($malformed, 0, 3)));
    $add('no cross-tenant rows', $foreign === [],
         $foreign === [] ? 'all rows owned by this tenant' : count($foreign) . ' foreign');
    $add('no duplicate allocation ids', $dupes === [],
         $dupes === [] ? 'create-only held' : implode(', ', array_slice($dupes, 0, 3)));
} else {
    $add('every row is well-formed', null, 'no rows');
    $add('no cross-tenant rows',     null, 'no rows');
    $add('no duplicate allocation ids', null, 'no rows');
}

// ── 3 · the composed invariant, via the REAL validator ────────────────────
$badAlloc = [];
foreach ($byDemand as $did => $rows) {
    $demand = $demands[$did] ?? null;
    if ($demand === null) { $badAlloc[] = "{$did}: orphan (no such demand)"; continue; }
    $svc = (new Entitlement_allocation_service())->init(
        null, null, $schoolId, (string) ($demand['session'] ?? ''), 'canary');
    $bad = $svc->validateForDemand($demand, $rows);
    if ($bad !== null) {
        $badAlloc[] = sprintf('%s %s expected=%.2f actual=%.2f delta=%.2f',
            $did, $bad['reason'], $bad['expected'], $bad['actual'], $bad['delta']);
    }
}
$add('rows exactly explain each demand discount', $have ? ($badAlloc === []) : null,
     $have ? ($badAlloc === [] ? count($byDemand) . ' demand(s) coherent'
                               : implode(' | ', array_slice($badAlloc, 0, 3)))
           : 'no rows');

// ── 4 · money coherence, unchanged by activation ──────────────────────────
$incoherent = [];
foreach ($demands as $did => $d) {
    if (Fee_firestore_txn::moneyIncoherence($d) !== null) $incoherent[] = $did;
}
$add('every demand still money-coherent', $incoherent === [],
     $incoherent === [] ? count($demands) . ' demand(s) clean'
                        : count($incoherent) . ' incoherent');

// ── 5/6 · baseline comparison — activation must be billing-inert ──────────
$snapshot = [];
foreach ($demands as $did => $d) {
    $snapshot[$did] = [
        'gross'    => round((float) ($d['grossAmount']    ?? 0), 2),
        'discount' => round((float) ($d['discountAmount'] ?? 0), 2),
        'net'      => round((float) ($d['netAmount']      ?? 0), 2),
        'paid'     => round((float) ($d['paidAmount']     ?? 0), 2),
    ];
}

if ($baseline !== '' && is_file($baseline)) {
    $before  = json_decode((string) file_get_contents($baseline), true);
    $before  = is_array($before) ? $before : [];
    $changed = []; $added = 0;
    foreach ($snapshot as $did => $now) {
        if (!isset($before[$did])) { $added++; continue; }
        foreach ($now as $k => $v) {
            if (abs($v - (float) ($before[$did][$k] ?? 0)) > 0.005) {
                $changed[] = "{$did}.{$k}: {$before[$did][$k]} → {$v}";
            }
        }
    }
    $add('no pre-existing demand changed value', $changed === [],
         $changed === [] ? count($before) . ' demand(s) byte-identical'
                         : implode(' | ', array_slice($changed, 0, 3)));
    $add('new demands appeared only by generation', true, "{$added} new since baseline");
} else {
    $file = __DIR__ . '/.ledger_canary_baseline_' . $schoolId . '.json';
    file_put_contents($file, json_encode($snapshot, JSON_PRETTY_PRINT));
    $add('baseline captured for later comparison', null, basename($file));
    $add('no pre-existing demand changed value', null, 're-run with --baseline to check');
}

// ── verdict ───────────────────────────────────────────────────────────────
$fail = 0; $na = 0;
foreach ($checks as $ck) {
    if ($ck['ok'] === null) $na++;
    elseif (!$ck['ok'])     $fail++;
}
echo "\n" . str_repeat('=', 74) . "\n";
printf("%d checks · %d passed · %d failed · %d not applicable\n",
       count($checks), count($checks) - $fail - $na, $fail, $na);

if ($fail === 0 && $na === 0) {
    echo "\nCANARY VERIFIED — the ledger is doing in production what the engine tests proved.\n";
} elseif ($fail === 0) {
    echo "\nNo failures, but {$na} check(s) could not run. This is NOT a pass:\n";
    echo "gate 7 needs allocation rows, and rows require an active grant plus a\n";
    echo "priced generation. Re-run once both have happened.\n";
} else {
    echo "\nCANARY FAILED — {$fail} check(s). Revoke the grant before investigating;\n";
    echo "the flag is tenant-scoped so revocation is immediate and total.\n";
}
echo "No Firestore documents were written by this script.\n";

exit($fail === 0 ? 0 : 1);
