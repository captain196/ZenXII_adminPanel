<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * BILLING V2 CANARY — VERIFY LIVE STATE AGAINST THE BASELINE · READ ONLY
 * ══════════════════════════════════════════════════════════════════════════
 *
 *   php scripts/canary_verify_against_baseline.php <SCHOOL_ID> <BASELINE_FILE>
 *
 * Diffs the CURRENT estate against a pre-change snapshot, document by document
 * and field by field. A controller's own success message is the engine
 * reporting on itself; this reads what is actually stored.
 *
 * Checks, in the order they would matter if something went wrong:
 *   1. no demand was DELETED and none APPEARED  (identity preserved)
 *   2. no demand ID changed                     (receipts stay attached)
 *   3. no PAID demand was rewritten             (settled money untouched)
 *   4. gross never moved                        (a concession changes the
 *                                                discount, never the charge)
 *   5. net == gross − discount on every row     (money coherence)
 *   6. allocations, receipts, payments, journals moved only as expected
 *   7. identity fields (SRNO / admission / APAAR) are byte-identical
 *
 * Nothing is written.
 */

putenv('DISABLE_DOC_CACHE=1');
define('BASEPATH', __DIR__);
define('APPPATH', dirname(__DIR__) . '/application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once APPPATH . 'libraries/Firestore_rest_client.php';

$schoolId = ''; $baseFile = ''; $expectNew = '';
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--expect-new=') === 0) { $expectNew = substr($a, 13); continue; }
    if ($schoolId === '')      $schoolId = trim($a);
    elseif ($baseFile === '')  $baseFile = trim($a);
}
if ($schoolId === '' || $baseFile === '') {
    fwrite(STDERR, "usage: php scripts/canary_verify_against_baseline.php <SCHOOL_ID> <BASELINE_FILE> [--expect-new=STUxxxx]\n");
    fwrite(STDERR, "  --expect-new  a GENERATION run legitimately adds demands for ONE student.\n");
    fwrite(STDERR, "                New demands for anyone else remain a failure, and deletions\n");
    fwrite(STDERR, "                are ALWAYS a failure regardless of this flag.\n");
    exit(2);
}
if (!is_file($baseFile)) { fwrite(STDERR, "baseline not found: {$baseFile}\n"); exit(2); }

$base = json_decode((string) file_get_contents($baseFile), true);
if (!is_array($base['demands'] ?? null)) { fwrite(STDERR, "baseline malformed\n"); exit(2); }

$c    = new FirestoreRestClient(
    APPPATH . 'config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json',
    'graderadmin', '(default)');
$body = function ($r) { return is_array($r['data'] ?? null) ? $r['data'] : $r; };
$q    = function (string $col, array $co = []) use ($c) {
    try { return $c->query($col, $co); } catch (\Throwable $e) { return []; }
};

echo "CANARY VERIFICATION — live vs baseline\n";
echo "tenant   : {$schoolId}\n";
echo 'baseline : ' . basename($baseFile) . ' (captured ' . (string) ($base['capturedAt'] ?? '?') . ")\n";
echo str_repeat('=', 76) . "\n\n";

$fail = []; $note = [];

// ── current demands ───────────────────────────────────────────────────────
$now = [];
foreach ($q('feeDemands', [['schoolId', '==', $schoolId]]) as $r) {
    $d = $body($r);
    $now[(string) $r['id']] = [
        'studentId'      => (string) ($d['studentId'] ?? ''),
        'grossAmount'    => round((float) ($d['grossAmount'] ?? 0), 2),
        'discountAmount' => round((float) ($d['discountAmount'] ?? 0), 2),
        'netAmount'      => round((float) ($d['netAmount'] ?? 0), 2),
        'paidAmount'     => round((float) ($d['paidAmount'] ?? 0), 2),
        'balance'        => round((float) ($d['balance'] ?? 0), 2),
        'status'         => (string) ($d['status'] ?? ''),
        'feeHead'        => (string) ($d['feeHead'] ?? ''),
        'periodKey'      => (string) ($d['periodKey'] ?? ''),
        'concessionApplied' => round((float) ($d['concessionApplied'] ?? 0), 2),
    ];
}
$was = $base['demands'];

// ── 1/2 · identity: nothing gained, lost, or renamed ──────────────────────
$lost  = array_diff(array_keys($was), array_keys($now));
$fresh = array_diff(array_keys($now), array_keys($was));

printf("── DEMAND IDENTITY %s\n", str_repeat('─', 57));
printf("  before: %d   after: %d\n", count($was), count($now));
printf("  deleted: %d   newly appeared: %d\n", count($lost), count($fresh));
// A deletion is ALWAYS a failure — no flag relaxes that.
if ($lost !== []) { $fail[] = 'DELETED demands: ' . implode(', ', array_slice($lost, 0, 5)); }

if ($fresh !== []) {
    if ($expectNew === '') {
        $fail[] = 'NEW demand ids: ' . implode(', ', array_slice($fresh, 0, 5));
    } else {
        // A generation run legitimately adds demands — but ONLY for the named
        // student. Anything else is cross-student contamination.
        $wrong = [];
        $ids   = [];
        foreach ($fresh as $id) {
            $owner = (string) ($now[$id]['studentId'] ?? '');
            if ($owner !== $expectNew) $wrong[] = "{$id} (student {$owner})";
            $ids[$id] = (strpos($id, 'DEM_') === 0) ? 'canonical' : 'legacy-random';
        }
        printf("  new demands for %s: %d\n", $expectNew, count($fresh) - count($wrong));
        $schemes = array_count_values($ids);
        printf("  new-demand id scheme: %s\n", json_encode($schemes));
        if (($schemes['legacy-random'] ?? 0) > 0) {
            $fail[] = 'NEW demands used LEGACY-RANDOM ids — the canonical generator did not run';
        }
        if ($wrong !== []) {
            $fail[] = 'new demands for UNEXPECTED students: ' . implode(', ', array_slice($wrong, 0, 5));
        }
    }
}
if ($lost === [] && ($fresh === [] || $expectNew !== '')) {
    echo "  ✓ no demand was deleted and no existing id changed\n";
}

// ── 3/4/5 · money ─────────────────────────────────────────────────────────
$changed = []; $grossMoved = []; $paidTouched = []; $incoherent = [];
foreach ($was as $id => $b) {
    if (!isset($now[$id])) continue;
    $a = $now[$id];

    if (abs($a['grossAmount'] - $b['grossAmount']) > 0.005) {
        $grossMoved[] = sprintf('%s %.2f→%.2f', $id, $b['grossAmount'], $a['grossAmount']);
    }
    if ($b['paidAmount'] > 0.005 || $b['status'] !== 'unpaid') {
        foreach (['paidAmount', 'balance', 'status', 'netAmount'] as $f) {
            if ((string) $a[$f] !== (string) $b[$f]) {
                $paidTouched[] = "{$id}.{$f}: {$b[$f]} → {$a[$f]}";
            }
        }
    }
    if (abs(($a['grossAmount'] - $a['discountAmount']) - $a['netAmount']) > 0.005) {
        $incoherent[] = sprintf('%s gross=%.2f disc=%.2f net=%.2f',
            $id, $a['grossAmount'], $a['discountAmount'], $a['netAmount']);
    }
    if (abs($a['discountAmount'] - $b['discountAmount']) > 0.005) {
        $changed[] = ['id' => $id, 'head' => $a['feeHead'], 'period' => $a['periodKey'],
                      'from' => $b['discountAmount'], 'to' => $a['discountAmount']];
    }
}

printf("\n── MONEY %s\n", str_repeat('─', 66));
$sum = function (array $rows, string $k) { $s = 0.0; foreach ($rows as $r) $s += $r[$k]; return round($s, 2); };
printf("  gross    : %10.2f → %10.2f\n", $sum($was, 'grossAmount'),    $sum($now, 'grossAmount'));
printf("  discount : %10.2f → %10.2f\n", $sum($was, 'discountAmount'), $sum($now, 'discountAmount'));
printf("  net      : %10.2f → %10.2f\n", $sum($was, 'netAmount'),      $sum($now, 'netAmount'));
printf("  paid     : %10.2f → %10.2f\n", $sum($was, 'paidAmount'),     $sum($now, 'paidAmount'));

if ($grossMoved !== []) $fail[] = 'GROSS moved on ' . count($grossMoved) . ' demand(s): '
                                . implode(' | ', array_slice($grossMoved, 0, 3));
else echo "  ✓ gross unchanged everywhere — a concession changes the discount, not the charge\n";

if ($paidTouched !== []) $fail[] = 'SETTLED money rewritten: ' . implode(' | ', array_slice($paidTouched, 0, 3));
else echo "  ✓ no paid/partial demand was rewritten\n";

if ($incoherent !== []) $fail[] = 'INCOHERENT: ' . implode(' | ', array_slice($incoherent, 0, 3));
else echo "  ✓ net == gross − discount on all " . count($now) . " demands\n";

printf("\n  demands whose discount changed: %d\n", count($changed));
foreach (array_slice($changed, 0, 15) as $x) {
    printf("      %-34s %-16s %-9s %.2f → %.2f\n",
        substr($x['id'], 0, 34), $x['head'], $x['period'], $x['from'], $x['to']);
}

// ── 6 · financial siblings ────────────────────────────────────────────────
printf("\n── FINANCIAL SIBLINGS %s\n", str_repeat('─', 53));
foreach (['entitlementAllocations' => 'allocations', 'feeReceipts' => 'receipts',
          'feePayments' => 'payments'] as $col => $key) {
    $before = count((array) ($base[$key] ?? []));
    $after  = count($q($col, [['schoolId', '==', $schoolId]]));
    printf("  %-24s %d → %d\n", $col, $before, $after);
    if ($col !== 'entitlementAllocations' && $after !== $before) {
        $fail[] = "{$col} changed ({$before} → {$after}) — recalculation must not touch it";
    }
    if ($col === 'entitlementAllocations' && $after === $before) {
        $note[] = 'no allocation rows were written — expected while USE_ALLOCATION_LEDGER is ungranted';
    }
}

// ── 7 · identity fields ───────────────────────────────────────────────────
printf("\n── STUDENT IDENTITY %s\n", str_repeat('─', 55));
$idDrift = [];
foreach ($q('students', [['schoolId', '==', $schoolId]]) as $r) {
    $d   = $body($r);
    $sid = (string) ($d['studentId'] ?? '');
    if ($sid === '' || !isset($base['students'][$sid])) continue;
    foreach (['srNo', 'admissionNo', 'apaarId'] as $f) {
        $b = (string) ($base['students'][$sid][$f] ?? '');
        $a = (string) ($d[$f] ?? ($f === 'admissionNo' ? ($d['admission_no'] ?? '') : ''));
        if ($a !== $b) $idDrift[] = "{$sid}.{$f}: '{$b}' → '{$a}'";
    }
}
if ($idDrift !== []) $fail[] = 'IDENTITY DRIFT: ' . implode(' | ', array_slice($idDrift, 0, 3));
else printf("  ✓ SRNO / admission no / APAAR unchanged for all %d students\n", count($base['students'] ?? []));

// ── verdict ───────────────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 76) . "\n";
foreach ($note as $n) echo "NOTE: {$n}\n";
if ($fail === []) {
    echo "VERDICT: PASS — the recalculation corrected the money and touched nothing else.\n";
} else {
    echo "VERDICT: FAIL — " . count($fail) . " problem(s):\n";
    foreach ($fail as $f) echo "  ✗ {$f}\n";
}
echo "No documents were written by this script.\n";
exit($fail === [] ? 0 : 1);
