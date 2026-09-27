<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * RC-1 — RECALCULATION READINESS PROBE · READ ONLY
 * ══════════════════════════════════════════════════════════════════════════
 *
 *   php scripts/rc1_readiness_probe.php [SCHOOL_ID]
 *
 * `Fee_recalculation_planner` refuses to auto-apply a plan when either of two
 * conditions is present (`isAutoApplicable()`):
 *
 *   • **ADJUST** — two demands share one charge key. That is the DEC-007
 *     identity-conflict shape, and choosing which of two real charges to
 *     overwrite is exactly the silent decision the planner declines to make.
 *   • **OBSOLETE** — a demand exists for a fee head no longer in the chart.
 *     Reported, never deleted.
 *
 * Both are properties of the EXISTING estate, so both can be measured today —
 * without an expected-charge set, and without running a recalculation. That is
 * what this probe does, using the planner's own `chargeKey()` so it cannot drift
 * from the real matching rule.
 *
 * **This answers a bounded question:** *could an RC-1 plan ever be
 * auto-applicable on this estate as it stands?* It does NOT produce a plan and
 * does not close gate 12 — that needs an expected-charge set from a real
 * generation. It tells you whether the estate is in a state where RC-1 would be
 * blocked before you build one.
 *
 * The tenant is OPTIONAL here (unlike the activation scripts) because the
 * question is estate-wide by nature; pass one to narrow it. No id is hard-coded.
 *
 * Exit 0 = no blocking conditions · 1 = conditions found.
 */

putenv('DISABLE_DOC_CACHE=1');
define('BASEPATH', __DIR__);
define('APPPATH', dirname(__DIR__) . '/application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once APPPATH . 'libraries/Firestore_rest_client.php';
require_once APPPATH . 'libraries/Fee_recalculation_planner.php';

$only = trim((string) ($argv[1] ?? ''));
$c    = new FirestoreRestClient(
    APPPATH . 'config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json',
    'graderadmin', '(default)');
$body = function ($r) { return is_array($r['data'] ?? null) ? $r['data'] : $r; };

echo "RC-1 — RECALCULATION READINESS PROBE\n";
echo 'scope: ' . ($only !== '' ? $only : 'ALL TENANTS') . "\n";
echo str_repeat('=', 74) . "\n\n";

// ── the live fee-head chart, per tenant ───────────────────────────────────
$chart = [];
foreach ($c->query('feeHeadRegistry', $only !== '' ? [['schoolId', '==', $only]] : []) as $r) {
    $d = $body($r);
    $sid = (string) ($d['schoolId'] ?? '');
    $nm  = trim((string) ($d['name'] ?? $d['feeHead'] ?? ''));
    if ($sid !== '' && $nm !== '') $chart[$sid][strtolower($nm)] = true;
}

// ── demands, grouped per student so charge keys are compared in scope ─────
$demands = $c->query('feeDemands', $only !== '' ? [['schoolId', '==', $only]] : []);

$byStudent = [];
$total = 0; $skipped = 0;
foreach ($demands as $r) {
    $d  = $body($r);
    $id = (string) ($r['id'] ?? '');
    if (strpos($id, 'DEM_TEST_LOAD_') === 0) { $skipped++; continue; }
    $total++;
    $sid = (string) ($d['schoolId'] ?? '?');
    $stu = (string) ($d['studentId'] ?? '?');
    $byStudent[$sid . '|' . $stu][(string) ($d['demandId'] ?? $id)] = $d;
}

// ── condition 1 · duplicate charge keys (would force ADJUST) ──────────────
$conflicts = [];
foreach ($byStudent as $scope => $rows) {
    $byKey = [];
    foreach ($rows as $did => $d) {
        $byKey[Fee_recalculation_planner::chargeKey($d)][] = $did;
    }
    foreach ($byKey as $key => $dids) {
        if (count($dids) > 1) {
            $conflicts[] = sprintf('%s key=[%s] → %d demands: %s',
                $scope, $key, count($dids), implode(', ', array_slice($dids, 0, 3)));
        }
    }
}

// ── condition 2 · demands for heads no longer in the chart (OBSOLETE) ─────
$obsolete = [];
foreach ($byStudent as $scope => $rows) {
    $sid = explode('|', $scope)[0];
    // Only meaningful where a chart actually exists for the tenant; a tenant
    // with no registry rows has not adopted M5, and every head would read as
    // obsolete for the wrong reason. Reported separately rather than counted.
    if (!isset($chart[$sid])) continue;
    foreach ($rows as $did => $d) {
        $head = strtolower(trim((string) ($d['feeHead'] ?? '')));
        if ($head === '' || isset($chart[$sid][$head])) continue;
        $obsolete[] = sprintf('%s demand=%s head=[%s]', $scope, $did, $head);
    }
}

$noChart = [];
foreach ($byStudent as $scope => $_) {
    $sid = explode('|', $scope)[0];
    if (!isset($chart[$sid])) $noChart[$sid] = true;
}

// ── report ────────────────────────────────────────────────────────────────
printf("  demands examined : %d  (%d synthetic load-test skipped)\n", $total, $skipped);
printf("  student scopes   : %d\n", count($byStudent));
printf("  tenants w/ chart : %d\n\n", count($chart));

echo "── ADJUST conditions (duplicate charge key — DEC-007 shape) " . str_repeat('─', 15) . "\n";
printf("  found: %d\n", count($conflicts));
foreach (array_slice($conflicts, 0, 15) as $x) echo "    {$x}\n";

echo "\n── OBSOLETE conditions (head not in the tenant's chart) " . str_repeat('─', 19) . "\n";
printf("  found: %d\n", count($obsolete));
foreach (array_slice($obsolete, 0, 15) as $x) echo "    {$x}\n";
if ($noChart !== []) {
    printf("  (%d tenant(s) have no feeHeadRegistry chart and were not assessed: %s)\n",
        count($noChart), implode(', ', array_keys($noChart)));
}

$fail = count($conflicts) + count($obsolete);
echo "\n" . str_repeat('=', 74) . "\n";
if ($fail === 0) {
    echo "RESULT: no blocking condition present. An RC-1 plan built against this\n";
    echo "        estate could be auto-applicable — IF its expected-charge set\n";
    echo "        introduced none. This does NOT close gate 12: no plan was built,\n";
    echo "        and executing one remains a protected financial operation.\n";
} else {
    echo "RESULT: {$fail} blocking condition(s). RC-1 would refuse to auto-apply,\n";
    echo "        by design. Each must be resolved by a human decision, never by\n";
    echo "        the planner picking a winner.\n";
}
echo "No documents were written by this script.\n";

exit($fail === 0 ? 0 : 1);
