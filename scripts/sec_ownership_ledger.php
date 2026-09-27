<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * SECURITY FINDING OWNERSHIP LEDGER · READ ONLY
 * ══════════════════════════════════════════════════════════════════════════
 *
 *   php scripts/sec_ownership_ledger.php <LIVE_RULES>
 *
 * Every finding-class (collection × operation) pair in the LIVE ruleset must
 * have EXACTLY ONE owner. Exits non-zero if any pair is unowned, or if any
 * ledger entry no longer corresponds to a real finding.
 *
 * ── WHY A LEDGER AND NOT A REPORT ─────────────────────────────────────────
 * A report is read once. This is asserted by
 * `tests/Unit/SecurityFindingOwnershipTest.php` on every test run, so a new
 * unscoped arm — added by any workstream, at any time — fails the build instead
 * of quietly joining a list nobody re-reads.
 *
 * It also catches the opposite drift: a ledger entry claiming to own a pair
 * that is no longer a finding is stale, and stale ownership is how a fix gets
 * silently dropped during a rebuild.
 *
 * ── OWNERSHIP KINDS ───────────────────────────────────────────────────────
 *   artifact       fixed by a prepared, tested artifact (named)
 *   server-only    intentionally deny-all for clients; backend uses Admin SDK
 *   owner-scoped   intentionally bound to request.auth.uid rather than tenant
 *   tenant-scoped  intentionally tenant-bound and already correct
 *   product        documented product exception (needs a human decision)
 *   blocked        deliberately not fixed, with a reason and a blocker id
 *
 * "Inconvenient to fix" is NOT an ownership kind.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

require_once __DIR__ . '/lib/rules_parser.php';

/**
 * ARTIFACTS ALREADY DEPLOYED TO PRODUCTION.
 *
 * A row belonging to a DEPLOYED artifact must own NOTHING — its findings are
 * discharged. That inversion is the proof the deployment actually took effect:
 * if a deployed artifact still owns a live finding, the deploy did not land (or
 * was reverted by a later one built from a stale baseline), and this fails.
 *
 * Conversely a row belonging to an UNDEPLOYED artifact that owns nothing is a
 * genuine stale row — the way a fix gets silently dropped during a rebuild.
 *
 * Update this list ONLY after the post-deployment verification for that
 * artifact has passed.
 */
const DEPLOYED = [
    'SEC-1',      // deployed 2026-08-21, ruleset 45d6db19-acf3-485e-854c-132f50b97585
    'SEC-3 w3',   // deployed 2026-08-21, ruleset 83732cdd-932f-4ea2-b394-fe30b49cfeaa
    'SEC-2',      // deployed 2026-08-21, ruleset 999f2b94-532b-4d9f-9086-d4e3ea10b0a2
    'SEC-3 w4',   // deployed 2026-08-21, ruleset 9770cf52-e5d3-46e0-9244-7437ea146c0b
    'SEC-3 w5',   // deployed 2026-08-21, ruleset a257279b-097b-4e84-b25b-ff321e268d9d
    'SEC-6',      // deployed 2026-08-21, ruleset a4a484d2-8c70-4811-9684-098b3ae58c76
    'SEC-8',      // deployed 2026-08-21, ruleset e8f2f47a-e9e3-4a0a-8842-0f2c6011012b
];

/**
 * THE LEDGER.
 *
 * Key: "<collection>.<operation>" or "<collection>.*" for every operation.
 * Each entry states WHO owns it and WHY. Adding a row is a deliberate act.
 */
const LEDGER = [

    // ── SEC-1 · nine money-adjacent collections, write arms ───────────────
    // Artifact: sec1_artifact.rules · 101 tests.
    'feeDefaulters.create'         => ['artifact', 'SEC-1', 'tenant-scoped create/update; strict delete'],
    'feeDefaulters.update'         => ['artifact', 'SEC-1', ''],
    'feeDefaulters.delete'         => ['artifact', 'SEC-1', ''],
    'studentDiscounts.create'      => ['artifact', 'SEC-1', 'feeds approvedDiscountCap — a financial control'],
    'studentDiscounts.update'      => ['artifact', 'SEC-1', ''],
    'studentDiscounts.delete'      => ['artifact', 'SEC-1', ''],
    'scholarshipAwards.create'     => ['artifact', 'SEC-1', ''],
    'scholarshipAwards.update'     => ['artifact', 'SEC-1', ''],
    'scholarshipAwards.delete'     => ['artifact', 'SEC-1', ''],
    'feeCarryForward.create'       => ['artifact', 'SEC-1', ''],
    'feeCarryForward.update'       => ['artifact', 'SEC-1', ''],
    'feeCarryForward.delete'       => ['artifact', 'SEC-1', ''],
    'feeReceiptAllocations.create' => ['artifact', 'SEC-1', ''],
    'feeReceiptAllocations.update' => ['artifact', 'SEC-1', ''],
    'feeReceiptAllocations.delete' => ['artifact', 'SEC-1', ''],
    'feeReminderLog.create'        => ['artifact', 'SEC-1', ''],
    'feeReminderLog.update'        => ['artifact', 'SEC-1', ''],
    'feeReminderLog.delete'        => ['artifact', 'SEC-1', ''],
    'fee_generation_jobs.create'   => ['artifact', 'SEC-1', 'write arm; READ arm is SEC-6'],
    'fee_generation_jobs.update'   => ['artifact', 'SEC-1', ''],
    'fee_generation_jobs.delete'   => ['artifact', 'SEC-1', ''],
    'timetableSettings.create'     => ['artifact', 'SEC-1', 'write arm; READ arm is SEC-6'],
    'timetableSettings.update'     => ['artifact', 'SEC-1', ''],
    'timetableSettings.delete'     => ['artifact', 'SEC-1', ''],
    'substitutes.create'           => ['artifact', 'SEC-1', 'write arm; READ arm is SEC-6'],
    'substitutes.update'           => ['artifact', 'SEC-1', ''],
    'substitutes.delete'           => ['artifact', 'SEC-1', ''],

    // ── SEC-2 · userDevices ownership ─────────────────────────────────────
    // Artifact: sec2_artifact.rules · 18 tests. Ownership, NOT tenancy — the
    // obvious tenant fix would have broken push registration for every user.
    'userDevices.*'                => ['artifact', 'SEC-2', 'FCM token store; owner-scoped by userId == uid'],

    // ── SEC-3 wave 3 · identity, payroll, RBAC, pricing ───────────────────
    'students.*'      => ['artifact', 'SEC-3 w3', 'blanket admin write denied; self-service update clauses retained'],
    'staff.*'         => ['artifact', 'SEC-3 w3', 'server-only; both apps READ-ONLY'],
    'salarySlips.*'   => ['artifact', 'SEC-3 w3', 'payroll; server-only'],
    'rbacRoles.*'     => ['artifact', 'SEC-3 w3', 'role catalogue; cross-tenant write = privilege escalation'],
    'feeSettings.*'   => ['artifact', 'SEC-3 w3', 'holds PLAINTEXT gateway secrets; server-only'],
    'feeStructures.*' => ['artifact', 'SEC-3 w3', 'the fee chart; was hidden by the census parser defect'],

    // ── SEC-3 wave 4 · academic structure and scheduling ──────────────────
    'sections.*'           => ['artifact', 'SEC-3 w4', 'server-only'],
    'subjects.*'           => ['artifact', 'SEC-3 w4', 'server-only'],
    'subjectAssignments.*' => ['artifact', 'SEC-3 w4', 'server-only'],
    'exams.create'         => ['artifact', 'SEC-3 w4', 'write arm; READ arm is SEC-6'],
    'exams.update'         => ['artifact', 'SEC-3 w4', ''],
    'exams.delete'         => ['artifact', 'SEC-3 w4', ''],
    'examSchedule.create'  => ['artifact', 'SEC-3 w4', 'write arm; READ arm is SEC-6'],
    'examSchedule.update'  => ['artifact', 'SEC-3 w4', ''],
    'examSchedule.delete'  => ['artifact', 'SEC-3 w4', ''],
    'timetables.*'         => ['artifact', 'SEC-3 w4', 'was isStaff() — any staff of any tenant could rewrite any timetable'],
    'calendarEvents.*'     => ['artifact', 'SEC-3 w4', 'server-only'],
    'appraisals.*'         => ['artifact', 'SEC-3 w4', 'server-only'],
    'leaveApplications.delete' => ['artifact', 'SEC-3 w4', 'delete-only; create/update already correctly scoped'],

    // ── SEC-3 wave 5 · communication, transport, library, PTM ─────────────
    'circulars.delete'     => ['artifact', 'SEC-3 w5', 'delete-only'],
    'notices.delete'       => ['artifact', 'SEC-3 w5', 'delete-only'],
    'events.delete'        => ['artifact', 'SEC-3 w5', 'delete-only'],
    'ptmEvents.delete'     => ['artifact', 'SEC-3 w5', 'delete-only'],
    'ptmRsvps.delete'      => ['artifact', 'SEC-3 w5', 'delete-only'],
    'ptmCounters.delete'   => ['artifact', 'SEC-3 w5', 'delete-only'],
    'libraryFines.delete'  => ['artifact', 'SEC-3 w5', 'delete-only'],
    'conversations.delete' => ['artifact', 'SEC-3 w5', 'delete-only'],
    'messages.delete'      => ['artifact', 'SEC-3 w5', 'delete-only'],
    'pushRequests.delete'  => ['artifact', 'SEC-3 w5', 'delete-only; actor stays isStaff(), tenancy only'],
    'routes.*'             => ['artifact', 'SEC-3 w5', 'server-only'],
    'vehicles.*'           => ['artifact', 'SEC-3 w5', 'server-only'],
    'studentRoutes.*'      => ['artifact', 'SEC-3 w5', 'server-only'],
    'libraryBooks.*'       => ['artifact', 'SEC-3 w5', 'server-only; was isStaff()'],
    'libraryIssues.*'      => ['artifact', 'SEC-3 w5', 'split arms; Teacher app issues/returns books'],
    'notifications.*'      => ['artifact', 'SEC-3 w5', 'split arms; see B-021 for the parent gap'],

    // ── SEC-6 · read/tenant convergence ───────────────────────────────────
    // Artifact: sec6_rebuilt.rules · 198 tests.
    'feeDemands.get'   => ['artifact', 'SEC-6', 'isStaffOrOwnStudent() short-circuit (SEC-4)'],
    'feeDemands.list'  => ['artifact', 'SEC-6', ''],
    'feeReceipts.get'  => ['artifact', 'SEC-6', ''],
    'feeReceipts.list' => ['artifact', 'SEC-6', ''],
    'feeDefaulters.get'  => ['artifact', 'SEC-6', ''],
    'feeDefaulters.list' => ['artifact', 'SEC-6', ''],
    'feeCarryForward.get'  => ['artifact', 'SEC-6', ''],
    'feeCarryForward.list' => ['artifact', 'SEC-6', ''],
    'feeReceiptAllocations.get'  => ['artifact', 'SEC-6', ''],
    'feeReceiptAllocations.list' => ['artifact', 'SEC-6', ''],
    'feeReminderLog.get'  => ['artifact', 'SEC-6', ''],
    'feeReminderLog.list' => ['artifact', 'SEC-6', ''],
    'feeOnlineOrders.get'  => ['artifact', 'SEC-6', ''],
    'feeOnlineOrders.list' => ['artifact', 'SEC-6', ''],
    'feeRefunds.get'  => ['artifact', 'SEC-6', ''],
    'feeRefunds.list' => ['artifact', 'SEC-6', ''],
    'feeRefundVouchers.get'  => ['artifact', 'SEC-6', ''],
    'feeRefundVouchers.list' => ['artifact', 'SEC-6', ''],
    'studentDiscounts.get'  => ['artifact', 'SEC-6', ''],
    'studentDiscounts.list' => ['artifact', 'SEC-6', ''],
    'scholarshipAwards.get'  => ['artifact', 'SEC-6', ''],
    'scholarshipAwards.list' => ['artifact', 'SEC-6', ''],
    'marks.get'   => ['artifact', 'SEC-6', ''],
    'marks.list'  => ['artifact', 'SEC-6', ''],
    'results.get'  => ['artifact', 'SEC-6', ''],
    'results.list' => ['artifact', 'SEC-6', ''],
    'exams.get'   => ['artifact', 'SEC-6', 'same short-circuit written out by hand'],
    'exams.list'  => ['artifact', 'SEC-6', ''],
    'examSchedule.get'  => ['artifact', 'SEC-6', ''],
    'examSchedule.list' => ['artifact', 'SEC-6', ''],
    'substitutes.get'   => ['artifact', 'SEC-6', 'was bare isAuth() — parents of other tenants too'],
    'substitutes.list'  => ['artifact', 'SEC-6', ''],
    'timetableSettings.get'  => ['artifact', 'SEC-6', ''],
    'timetableSettings.list' => ['artifact', 'SEC-6', ''],
    'circularReads.get'  => ['artifact', 'SEC-6', 'inline isStaff() disjunct; helper fix does not reach it'],
    'circularReads.list' => ['artifact', 'SEC-6', ''],
    'subjectPlanProgress.create'    => ['artifact', 'SEC-6', 'resource==null fail-open on create'],
    'dailyTeacherMonitoring.create' => ['artifact', 'SEC-6', ''],
    'feeAuditLogs.get'  => ['artifact', 'SEC-6', 'bare isAdmin(); hidden by the platform-detection defect'],
    'feeAuditLogs.list' => ['artifact', 'SEC-6', ''],
    'feeRefundAudit.get'  => ['artifact', 'SEC-6', 'read arm only; writes were already scoped'],
    'feeRefundAudit.list' => ['artifact', 'SEC-6', ''],
    'fee_generation_jobs.get'  => ['artifact', 'SEC-6', 'read arm; write arm is SEC-1'],
    'fee_generation_jobs.list' => ['artifact', 'SEC-6', ''],

    // ── BLOCKED · needs an authorization or product decision ──────────────
    // These are NOT finding-class pairs — they are `null-deref-denies`, i.e.
    // behaviourally closed but for the wrong reason. Tracked with an EXPECTED
    // CLASS so the ledger asserts they stay closed: if either ever becomes
    // open, the expectation fails loudly instead of the row looking merely
    // stale.
    // ── SEC-8 · B-022 RESOLVED ────────────────────────────────────────────
    // Researched rather than inferred from the rule's shape. Audit_log_service
    // calls itself a "write-only audit trail" and has no delete method; neither
    // app deletes marks; the Teacher app corrects marks by setDocument(merge)
    // with a `status: "draft"` field; the admin panel deletes exams and
    // examSchedule but never marks; `marksAudit` is an append-only change trail.
    //
    // VERDICT: client deletion stays PROHIBITED. SEC-8 makes the existing
    // denial explicit so a future author "repairing" the null-deref predicate
    // cannot accidentally open it. Behavioural change: ZERO — proven by
    // sec8_append_only.test.js passing IDENTICALLY (17/17) against both the
    // live pull and the artifact.
    //
    // Expected class is now `deny`: SEC-8 deployed 2026-08-21 (ruleset e8f2f47a),
    // so both pairs are refused deliberately rather than by null dereference.
    'marks.delete' => ['artifact', 'SEC-8',
        'append-only: client delete explicitly denied; correction is merge-overwrite',
        'deny'],
    'academicAuditLog.delete' => ['artifact', 'SEC-8',
        'write-only audit trail; client delete explicitly denied',
        'deny'],
];

$path = trim((string) ($argv[1] ?? ''));
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "usage: php scripts/sec_ownership_ledger.php <LIVE_RULES>\n");
    exit(2);
}
$src = (string) file_get_contents($path);
if (strlen($src) < 100000) { fwrite(STDERR, "rules file looks truncated — refusing\n"); exit(2); }

$parser = new RulesParser($src);
$graph  = $parser->authorizationGraph();

$findings = array_values(array_filter($graph, function ($r) {
    return RulesParser::isFinding($r['class']);
}));

$unowned = [];
$owned   = [];
$usedKeys = [];

foreach ($findings as $f) {
    $exact = $f['collection'] . '.' . $f['operation'];
    $wild  = $f['collection'] . '.*';

    if (isset(LEDGER[$exact]))      { $owned[] = [$f, LEDGER[$exact], $exact]; $usedKeys[$exact] = true; }
    elseif (isset(LEDGER[$wild]))   { $owned[] = [$f, LEDGER[$wild], $wild];   $usedKeys[$wild] = true; }
    else                            { $unowned[] = $f; }
}

/**
 * A ledger row may instead declare an EXPECTED CLASS for a pair that is not a
 * finding — used for items that are behaviourally closed but for the wrong
 * reason (`null-deref-denies`). The ledger then asserts the pair STAYS in that
 * class, so a regression that opens it fails loudly.
 */
$actualClass = [];
foreach ($graph as $r) $actualClass[$r['collection'] . '.' . $r['operation']] = $r['class'];

$expectationBroken = [];
$expectationRows = [];
foreach (LEDGER as $k => $v) {
    if (!isset($v[3])) continue;
    $usedKeys[$k] = true;               // an expectation row is never "stale"
    $expectationRows[$k] = true;        // ...nor subject to the discharge check
    $seen = $actualClass[$k] ?? '(absent)';
    if ($seen !== $v[3]) $expectationBroken[] = "{$k}: expected {$v[3]}, found {$seen}";
}

/**
 * Two opposite drifts, split by deployment state:
 *
 *   UNDEPLOYED row owning nothing -> STALE. The fix was dropped in a rebuild
 *                                    while the paperwork still claims cover.
 *   DEPLOYED row still owning      -> NOT DISCHARGED. The deployment did not
 *                                    take effect, or a later artifact built
 *                                    from a stale baseline reverted it.
 */
$stale = [];
$notDischarged = [];
foreach (LEDGER as $k => $v) {
    // An EXPECTATION row tracks a pair that is not finding-class at all (e.g.
    // an append-only delete). It never "owns" a finding, so neither the stale
    // nor the discharge test applies — its correctness is asserted by the
    // expected-class check above instead.
    if (isset($expectationRows[$k])) continue;

    $isDeployed = in_array($v[1], DEPLOYED, true);
    $owns = isset($usedKeys[$k]);

    if ($isDeployed && $owns)       $notDischarged[] = "{$k} (owner {$v[1]})";
    elseif (!$isDeployed && !$owns) $stale[] = $k;
}

echo "SECURITY FINDING OWNERSHIP LEDGER\n";
echo 'source: ' . basename($path) . "\n";
echo str_repeat('=', 96) . "\n\n";
printf("finding-class pairs : %d\n", count($findings));
printf("owned               : %d\n", count($owned));
printf("UNOWNED             : %d\n", count($unowned));
printf("stale ledger rows   : %d\n\n", count($stale));

$byOwner = [];
foreach ($owned as $o) {
    $key = $o[1][0] . ' / ' . $o[1][1];
    if (!isset($byOwner[$key])) $byOwner[$key] = 0;
    $byOwner[$key]++;
}
ksort($byOwner);
foreach ($byOwner as $k => $n) printf("  %-24s %d\n", $k, $n);

if ($unowned) {
    echo "\n── UNOWNED (must be zero) " . str_repeat('-', 60) . "\n";
    foreach ($unowned as $f) {
        printf("  %-28s %-8s %-18s %s\n", $f['collection'], $f['operation'], $f['class'],
            strlen($f['condition']) > 40 ? substr($f['condition'], 0, 37) . '...' : $f['condition']);
    }
}

if ($notDischarged) {
    echo "
── NOT DISCHARGED (deployment did not take effect) " . str_repeat("-", 44) . "
";
    foreach ($notDischarged as $n) printf("  %s
", $n);
}

if ($expectationBroken) {
    echo "\n── BROKEN EXPECTATIONS (a tracked pair changed class) " . str_repeat('-', 40) . "\n";
    foreach ($expectationBroken as $e) printf("  %s\n", $e);
}

if ($stale) {
    echo "\n── STALE LEDGER ROWS (own nothing) " . str_repeat('-', 50) . "\n";
    foreach ($stale as $k) printf("  %s\n", $k);
}

echo "\n" . str_repeat('=', 96) . "\n";
if ($unowned === [] && $stale === [] && $expectationBroken === [] && $notDischarged === []) {
    echo "EVERY finding-class pair has exactly one owner, and every ledger row\n";
    echo "owns a real finding.\n";
    exit(0);
}
echo "LEDGER IS OUT OF SYNC WITH THE RULESET — resolve before any deployment.\n";
exit(1);
