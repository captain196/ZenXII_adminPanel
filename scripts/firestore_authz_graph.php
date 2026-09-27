<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * FIRESTORE AUTHORIZATION GRAPH · READ ONLY
 * ══════════════════════════════════════════════════════════════════════════
 *
 *   php scripts/firestore_authz_graph.php <RULES_FILE> [--collection=NAME]
 *                                                      [--class=CLASS]
 *                                                      [--csv]
 *
 * Reconstructs the COMPLETE authorization graph of a ruleset: every collection
 * × every operation, with the effective boundary the engine actually enforces.
 *
 * ── WHY THIS EXISTS AND THE WRITE CENSUS DOES NOT SUFFICE ─────────────────
 * `firestore_write_arm_audit.php` classifies write-capable arms one at a time.
 * That misses three things that have each already caused a wrong conclusion
 * in this programme:
 *
 *   1. READ OPERATIONS. `allow list: if isStaff();` beside a scoped `allow get`
 *      leaks an entire collection, and a write-only census cannot see it.
 *
 *   2. HELPER SEMANTICS. Classifying `isStaffOrOwnStudent()` by name gives
 *      "staff or student — looks scoped". Expanded it is
 *          isStaff() || (isSameSchool() && isOwnStudent())
 *      and `||` short-circuits past the tenant check. That is SEC-4, and it was
 *      invisible until the helper was INLINED. This tool resolves helpers
 *      transitively before classifying anything.
 *
 *   3. OR SEMANTICS ACROSS ARMS. Firestore permits an operation if ANY matching
 *      allow rule passes. So the effective boundary of an operation is its
 *      WEAKEST arm, not its strongest or its first. Listing arms independently
 *      — which every earlier tool here did — describes the rules, not the
 *      authorization.
 *
 * ── OPERATION EXPANSION ───────────────────────────────────────────────────
 *   read  -> get, list
 *   write -> create, update, delete
 * Every arm is expanded so `get` and `list` are judged separately, and so
 * `create`/`update`/`delete` are never collapsed (`request.resource` is null on
 * delete; `resource` is null on create).
 *
 * ── CLASSES ───────────────────────────────────────────────────────────────
 *   deny                     `if false` — server-only via the Admin SDK
 *   platform-only            isSuperAdmin — deliberately cross-tenant
 *   rbac-tenant              capability check AND document-school comparison
 *   student-self             document's student/owner == auth.uid, tenant-bound
 *   owner-scoped             document's owner field == auth.uid
 *   staff-own-tenant         staff AND document-school comparison
 *   tenant-scoped            document-school comparison, no role requirement
 *   staff-any-tenant         staff, NO document-school comparison  <-- SEC-4
 *   auth-any-tenant          merely authenticated, no scoping      <-- leak
 *   public                   `if true`
 *   investigate              not statically classifiable
 *
 * The last four are the finding classes. `investigate` is NOT a pass — an arm
 * nobody can classify is an arm nobody has audited.
 *
 * ── WHAT THIS TOOL DOES NOT DECIDE ────────────────────────────────────────
 * A weak class is a CANDIDATE, never a verdict. `userDevices` is the standing
 * precedent: the obvious tenant fix would have broken push registration for
 * every user because the correct boundary was ownership. Server-only is
 * likewise correct — not a gap — wherever the only writer is the PHP backend
 * on its service account. Trace the real client contracts before changing a
 * rule.
 *
 * NOTHING IS MODIFIED. NOTHING IS DEPLOYED.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

require_once __DIR__ . '/lib/rules_parser.php';

$args = array_slice($argv, 1);
$path = '';
$onlyCollection = '';
$onlyClass = '';
$csv = false;
foreach ($args as $a) {
    if (strpos($a, '--collection=') === 0)  $onlyCollection = substr($a, 13);
    elseif (strpos($a, '--class=') === 0)   $onlyClass = substr($a, 8);
    elseif ($a === '--csv')                 $csv = true;
    elseif ($path === '')                   $path = $a;
}

if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "usage: php scripts/firestore_authz_graph.php <RULES_FILE> [--collection=X] [--class=Y] [--csv]\n");
    exit(2);
}

$src = (string) file_get_contents($path);
if (strlen($src) < 10000) { fwrite(STDERR, "rules file looks truncated — refusing\n"); exit(2); }

$parser = new RulesParser($src);
$graph  = $parser->authorizationGraph();

// ── Report ───────────────────────────────────────────────────────────────
if ($csv) {
    echo "collection,operation,class,rank,condition\n";
    foreach ($graph as $row) {
        if ($onlyCollection !== '' && $row['collection'] !== $onlyCollection) continue;
        if ($onlyClass !== '' && $row['class'] !== $onlyClass) continue;
        printf("%s,%s,%s,%d,\"%s\"\n", $row['collection'], $row['operation'], $row['class'],
            $row['rank'], str_replace('"', "'", $row['condition']));
    }
    exit(0);
}

echo "FIRESTORE AUTHORIZATION GRAPH\n";
echo 'source: ' . basename($path) . "\n";
echo str_repeat('=', 118) . "\n\n";

$tally = [];
foreach ($graph as $row) {
    if (!isset($tally[$row['class']])) $tally[$row['class']] = 0;
    $tally[$row['class']]++;
}
$order = RulesParser::CLASS_ORDER;
uksort($tally, function ($a, $b) use ($order) {
    return ($order[$a] ?? 99) <=> ($order[$b] ?? 99);
});

printf("collections: %d   (collection x operation) pairs: %d\n\n",
    count(array_unique(array_column($graph, 'collection'))), count($graph));

foreach ($tally as $cls => $n) {
    printf("  %-20s %4d %s\n", $cls, $n, RulesParser::isFinding($cls) ? '  <-- FINDING CLASS' : '');
}

$findings = array_values(array_filter($graph, function ($r) { return RulesParser::isFinding($r['class']); }));

if ($onlyCollection !== '' || $onlyClass !== '') {
    $sel = array_values(array_filter($graph, function ($r) use ($onlyCollection, $onlyClass) {
        if ($onlyCollection !== '' && $r['collection'] !== $onlyCollection) return false;
        if ($onlyClass !== '' && $r['class'] !== $onlyClass) return false;
        return true;
    }));
    printf("\n── filtered (%d) %s\n", count($sel), str_repeat('-', 90));
    printf("  %-30s %-8s %-20s %s\n", 'COLLECTION', 'OP', 'CLASS', 'EFFECTIVE (weakest) CONDITION');
    foreach ($sel as $r) {
        printf("  %-30s %-8s %-20s %s\n", $r['collection'], $r['operation'], $r['class'],
            strlen($r['condition']) > 52 ? substr($r['condition'], 0, 49) . '...' : $r['condition']);
    }
    exit(0);
}

printf("\n── FINDING-CLASS pairs (%d) %s\n", count($findings), str_repeat('-', 78));
printf("  %-30s %-8s %-20s %s\n", 'COLLECTION', 'OP', 'CLASS', 'EFFECTIVE (weakest) CONDITION');
echo '  ' . str_repeat('-', 114) . "\n";
foreach ($findings as $r) {
    printf("  %-30s %-8s %-20s %s\n", $r['collection'], $r['operation'], $r['class'],
        strlen($r['condition']) > 52 ? substr($r['condition'], 0, 49) . '...' : $r['condition']);
}

echo "\n" . str_repeat('=', 118) . "\n";
echo "The class shown is the WEAKEST arm for that operation, because Firestore\n";
echo "permits when ANY matching allow rule passes. A finding class is a\n";
echo "CANDIDATE, not a verdict: server-only is CORRECT where the only writer is\n";
echo "the PHP backend on its service account, and ownership — not tenancy — is\n";
echo "the right boundary for per-user collections. Trace the real client\n";
echo "contracts before changing any rule.\n";
echo "No files were modified. Nothing was deployed.\n";
exit(0);
