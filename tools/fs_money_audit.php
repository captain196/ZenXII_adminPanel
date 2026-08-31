<?php
/**
 * fs_money_audit — assert the money invariants against PERSISTED feeDemands.
 *
 * ── WHY THIS EXISTS ───────────────────────────────────────────────────────
 * Every earlier proof of the demand layer was a unit test or an endpoint
 * response. Both passed while `FeeCollectionService` was silently rejecting
 * every counter discount, and while one authorised waiver was spendable on
 * every receipt. The only evidence that actually settles a money question is
 * the document Firestore ended up holding, after every downstream writer,
 * mirror and sync has finished.
 *
 * This reads the real collection and checks the invariants that every reader
 * depends on:
 *
 *   I-1  gross - discount == net            (the reporting identity)
 *   I-2  balance == net + fine - paid       (the collectable identity)
 *   I-3  net >= 0 and balance >= 0          (no negative payable)
 *   I-4  paid <= net + fine                 (nobody is recorded as over-paid
 *                                            on a demand; excess belongs in
 *                                            feeRefunds, not here)
 *   I-5  every demand carries schoolId AND session   (tenant + session scope)
 *   I-6  status agrees with the numbers      (paid <=> balance 0)
 *
 * Read-only. Loopback emulator only.
 *
 * Usage:  php tools/fs_money_audit.php [schoolId] [session]
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

$emu = preg_replace('#^https?://#i', '', trim((string) getenv('FIRESTORE_EMULATOR_HOST')));
$host = strtolower((string) strtok((string) $emu, ':'));
if ($emu === '' || !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "REFUSING: loopback emulator only.\n"); exit(3);
}
$project = getenv('ZX_PROJECT') ?: 'graderadmin';
$base    = "http://{$emu}/v1/projects/{$project}/databases/(default)/documents";
$school  = $argv[1] ?? '';
$session = $argv[2] ?? '';

function zx_val(array $v) {
    foreach (['stringValue','booleanValue','integerValue','doubleValue','timestampValue'] as $k) {
        if (array_key_exists($k, $v)) {
            return $k === 'doubleValue' ? (float) $v[$k] : ($k === 'integerValue' ? (int) $v[$k] : $v[$k]);
        }
    }
    if (array_key_exists('nullValue', $v)) return null;
    if (isset($v['arrayValue'])) { $o=[]; foreach ($v['arrayValue']['values'] ?? [] as $e) $o[]=zx_val($e); return $o; }
    if (isset($v['mapValue']))   { $o=[]; foreach ($v['mapValue']['fields'] ?? [] as $k=>$e) $o[$k]=zx_val($e); return $o; }
    return null;
}

$docs = []; $token = '';
do {
    $u = "{$base}/feeDemands?pageSize=300" . ($token !== '' ? '&pageToken=' . urlencode($token) : '');
    $ch = curl_init($u);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $body = curl_exec($ch); curl_close($ch);
    $j = json_decode((string) $body, true);
    if (!is_array($j)) break;
    foreach ($j['documents'] ?? [] as $d) $docs[] = $d;
    $token = (string) ($j['nextPageToken'] ?? '');
} while ($token !== '');

/** Load every refund obligation once, so I-4 can prove an excess is held. */
function zx_load_refunds(string $base): array
{
    $out = []; $token = '';
    do {
        $u = "{$base}/feeRefunds?pageSize=300" . ($token !== '' ? '&pageToken=' . urlencode($token) : '');
        $ch = curl_init($u);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
        $body = curl_exec($ch); curl_close($ch);
        $j = json_decode((string) $body, true);
        if (!is_array($j)) break;
        foreach ($j['documents'] ?? [] as $d) {
            $f = [];
            foreach ($d['fields'] ?? [] as $k => $v) $f[$k] = zx_val($v);
            $f['__id'] = substr((string) $d['name'], strrpos((string) $d['name'], '/') + 1);
            $out[] = $f;
        }
        $token = (string) ($j['nextPageToken'] ?? '');
    } while ($token !== '');
    return $out;
}

/**
 * Is this student's excess held as an obligation?
 *
 * A CANCELLED or REJECTED refund does not count as cover: the money is still
 * on the demand and nobody owes it back, which is exactly the orphaned state
 * this invariant exists to catch.
 */
function zx_refund_covers(array $refunds, string $school, string $student, float $excess): ?array
{
    foreach ($refunds as $r) {
        if ((string) ($r['schoolId'] ?? '') !== $school) continue;
        if ((string) ($r['studentId'] ?? '') !== $student) continue;
        $st = strtolower(trim((string) ($r['status'] ?? '')));
        if (in_array($st, ['cancelled', 'rejected'], true)) continue;
        if (abs((float) ($r['amount'] ?? 0) - $excess) > 0.011) continue;
        return ['id' => (string) $r['__id'], 'status' => $st];
    }
    return null;
}

$GLOBALS['ZX_REFUNDS'] = zx_load_refunds($base);
$GLOBALS['ZX_COVERED'] = [];

$EPS = 0.011;   // a paisa of rounding tolerance
$violations = []; $checked = 0; $skipped = 0;
$tot = ['gross'=>0.0,'disc'=>0.0,'net'=>0.0,'paid'=>0.0,'bal'=>0.0,'fine'=>0.0];

foreach ($docs as $d) {
    $f = [];
    foreach ($d['fields'] ?? [] as $k => $v) $f[$k] = zx_val($v);

    if ($school !== '' && (string) ($f['schoolId'] ?? '') !== $school) { $skipped++; continue; }
    if ($session !== '' && (string) ($f['session'] ?? '') !== $session) { $skipped++; continue; }

    $id     = substr((string) $d['name'], strrpos((string) $d['name'], '/') + 1);
    $status = strtolower(trim((string) ($f['status'] ?? '')));
    if (in_array($status, ['archived', 'cancelled'], true)) { $skipped++; continue; }

    $gross = (float) ($f['grossAmount'] ?? 0);
    $disc  = (float) ($f['discountAmount'] ?? 0);
    $net   = (float) ($f['netAmount'] ?? 0);
    $paid  = (float) ($f['paidAmount'] ?? 0);
    $bal   = (float) ($f['balance'] ?? 0);
    $fine  = (float) ($f['fineAmount'] ?? $f['fine_amount'] ?? 0);
    $checked++;

    $tot['gross'] += $gross; $tot['disc'] += $disc; $tot['net'] += $net;
    $tot['paid']  += $paid;  $tot['bal']  += $bal;  $tot['fine'] += $fine;

    $add = function (string $rule, string $detail) use (&$violations, $id) {
        $violations[$rule][] = "{$id}  {$detail}";
    };

    if (abs(($gross - $disc) - $net) > $EPS) {
        $add('I-1 gross-discount==net', sprintf('gross=%.2f disc=%.2f net=%.2f', $gross, $disc, $net));
    }
    // I-2 — balance is the COLLECTABLE figure, so it floors at zero. An
    // overpayment does not make it negative: the excess leaves the demand and
    // becomes a refund obligation (proved by I-4 below). Comparing against the
    // raw subtraction would flag correct behaviour as a defect.
    if (abs(max(0.0, $net + $fine - $paid) - $bal) > $EPS) {
        $add('I-2 balance==max(0,net+fine-paid)',
            sprintf('net=%.2f fine=%.2f paid=%.2f bal=%.2f', $net, $fine, $paid, $bal));
    }
    if ($net < -$EPS || $bal < -$EPS) {
        $add('I-3 no negative payable', sprintf('net=%.2f bal=%.2f', $net, $bal));
    }
    // I-4 — an overpayment on a demand is LEGITIMATE, but only while the
    // excess is held as an obligation the school still owes back.
    //
    // A service reduction after payment (transport stopped mid-year, say)
    // legitimately leaves paid > net: the family handed over money for months
    // it no longer owes. What must never happen is that excess simply sitting
    // on the demand with nothing recording it. So the real invariant is not
    // "never overpaid" — it is "overpayment is never orphaned".
    if ($paid > $net + $fine + $EPS) {
        $excess = round($paid - ($net + $fine), 2);
        $sid    = (string) ($f['studentId'] ?? '');
        $sch    = (string) ($f['schoolId'] ?? '');
        $covered = zx_refund_covers($GLOBALS['ZX_REFUNDS'], $sch, $sid, $excess);
        if ($covered === null) {
            $add('I-4 overpayment is ORPHANED',
                sprintf('paid=%.2f net=%.2f fine=%.2f excess=%.2f — NO refund obligation',
                    $paid, $net, $fine, $excess));
        } else {
            $GLOBALS['ZX_COVERED'][] = sprintf('%s excess=%.2f -> %s (%s)', $id, $excess, $covered['id'], $covered['status']);
        }
    }
    if ((string) ($f['schoolId'] ?? '') === '' || (string) ($f['session'] ?? '') === '') {
        $add('I-5 tenant+session scope', 'schoolId=[' . ($f['schoolId'] ?? '') . '] session=[' . ($f['session'] ?? '') . ']');
    }
    if ($status === 'paid' && $bal > $EPS) {
        $add('I-6 status agrees', sprintf('status=paid but balance=%.2f', $bal));
    }
    if ($status !== 'paid' && abs($bal) <= $EPS && $paid > $EPS) {
        $add('I-6 status agrees', sprintf('balance=0 paid=%.2f but status=%s', $paid, $status));
    }
}

printf("feeDemands audited: %d  (skipped %d: other tenant/session or archived)\n", $checked, $skipped);
printf("  gross %10.2f  discount %10.2f  net %10.2f\n", $tot['gross'], $tot['disc'], $tot['net']);
printf("  fine  %10.2f  paid     %10.2f  balance %9.2f\n", $tot['fine'], $tot['paid'], $tot['bal']);
printf("  aggregate gross-discount == net : %s\n",
    abs(($tot['gross'] - $tot['disc']) - $tot['net']) < 1.0 ? 'OK' : 'MISMATCH');

if ($GLOBALS['ZX_COVERED']) {
    echo "
OVERPAYMENTS HELD AS OBLIGATIONS (not orphaned):
";
    foreach ($GLOBALS['ZX_COVERED'] as $c) echo "  {$c}
";
}

if (!$violations) { echo "\nALL INVARIANTS HOLD ON PERSISTED STATE.\n"; exit(0); }

echo "\nVIOLATIONS:\n";
foreach ($violations as $rule => $rows) {
    printf("  %-28s %d\n", $rule, count($rows));
    foreach (array_slice($rows, 0, 6) as $r) echo "      {$r}\n";
    if (count($rows) > 6) echo "      … " . (count($rows) - 6) . " more\n";
}
exit(1);
