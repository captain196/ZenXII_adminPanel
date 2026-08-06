<?php
/**
 * verify_integration.php — Release Integration Phase 1 automated regression harness.
 *
 * Runs the repo's READ-ONLY CLI self-test / verifier controllers against a
 * (staging) tenant, aggregates PASS / FAIL / ERROR, prints one report, and
 * EXITS NON-ZERO on any FAIL — so it can gate a release deterministically
 * instead of manual clicking.
 *
 * Usage (from repo root, against a STAGING tenant — never prod first):
 *   php scripts/verify_integration.php
 *   php scripts/verify_integration.php --school=SCH_XXXXXXXXXX
 *
 * Exit codes: 0 = all PASS ; 1 = one or more FAIL ; 2 = a check could not run
 * (ERROR — wire its CLI signature before trusting the suite).
 *
 * SAFETY: every check below is a documented READ-ONLY verifier. Do NOT add
 * mutating commands here.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(2); }

$school = 'SCH_D94FE8F7AD';
foreach ($argv as $a) {
    if (strpos($a, '--school=') === 0) $school = substr($a, strlen('--school='));
}

$INDEX = __DIR__ . '/../index.php';
if (!is_file($INDEX)) { fwrite(STDERR, "index.php not found next to repo root.\n"); exit(2); }

/**
 * Each check: label, argv passed to `php index.php ...`, and how to read it.
 *  - 'confirmed' => true  : CLI signature verified in the codebase (trusted).
 *  - 'confirmed' => false : CANDIDATE — confirm the controller's CLI method +
 *                           args, then flip to true. Runs but flagged.
 */
$CHECKS = [
    // ── CONFIRMED read-only self-tests ──────────────────────────────────────
    ['label' => 'RBAC — Department & Role invariants (NEW incoming module)',
     'args'  => ['deptrole_selftest', 'index', $school], 'confirmed' => true],

    ['label' => 'Platform health check',
     'args'  => ['health_check', 'run'], 'confirmed' => true],

    ['label' => 'Events & Gallery self-test',
     'args'  => ['events_gallery_selftest', 'run'], 'confirmed' => true],

    ['label' => 'Timetables health check',
     'args'  => ['timetables_healthcheck', 'run'], 'confirmed' => true],

    // ── CANDIDATES — confirm each controller's CLI method/args, then set
    //    'confirmed' => true. Left ACTIVE so the harness surfaces them; a
    //    controller that cannot run is reported as ERROR, never a silent pass.
    ['label' => 'Numbering registry verifier',
     'args'  => ['numbering_verifier'], 'confirmed' => false],
    ['label' => 'Transport module verifier',
     'args'  => ['transport_verifier'], 'confirmed' => false],
    ['label' => 'Communication verifier',
     'args'  => ['communication_verifier'], 'confirmed' => false],
    ['label' => 'Accounting ledger probe',
     'args'  => ['accounting_ledger_probe'], 'confirmed' => false],
    ['label' => 'Stream B verifier',
     'args'  => ['stream_b_verifier'], 'confirmed' => false],
];

$FAIL_RE  = '/\bFAIL(ED)?\b|✗|Fatal error|Uncaught|Unknown class|404 Page Not Found/i';
$PASS_RE  = '/\bOK\b|\bPASS\b|all assertions|✓/i';

$results = [];
foreach ($CHECKS as $c) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($INDEX);
    foreach ($c['args'] as $arg) $cmd .= ' ' . escapeshellarg($arg);
    $cmd .= ' 2>&1';

    $out = [];
    $exit = 0;
    exec($cmd, $out, $exit);
    $text = implode("\n", $out);

    $couldNotRun = (bool) preg_match('/Unknown class|404 Page Not Found|No input file|Class .* not found/i', $text);
    if ($couldNotRun) {
        $status = 'ERROR';
    } elseif ($exit !== 0 || preg_match($FAIL_RE, $text)) {
        $status = 'FAIL';
    } else {
        $status = 'PASS';
    }
    $results[] = ['label' => $c['label'], 'status' => $status, 'confirmed' => $c['confirmed'],
                  'exit' => $exit, 'tail' => implode("\n    ", array_slice($out, -3))];
}

// ── Report ──────────────────────────────────────────────────────────────────
$pass = $fail = $err = 0;
echo "\n═══════════════ Release Integration Phase 1 — Verification Suite ═══════════════\n";
echo "school: {$school}\n\n";
foreach ($results as $r) {
    $icon = ['PASS' => '✓', 'FAIL' => '✗', 'ERROR' => '?'][$r['status']];
    $tag  = $r['confirmed'] ? '' : ' [candidate — confirm CLI]';
    printf("  %s %-6s %s%s\n", $icon, $r['status'], $r['label'], $tag);
    if ($r['status'] !== 'PASS') echo "        └ " . $r['tail'] . "\n";
    $pass += $r['status'] === 'PASS'; $fail += $r['status'] === 'FAIL'; $err += $r['status'] === 'ERROR';
}
echo "\n───────────────────────────────────────────────────────────────────────────────\n";
echo "  PASS={$pass}  FAIL={$fail}  ERROR={$err}\n";
if ($fail > 0) { echo "  ⛔ RELEASE BLOCKED — {$fail} verifier(s) FAILED.\n"; exit(1); }
if ($err  > 0) { echo "  ⚠  {$err} check(s) could not run — wire their CLI before trusting the suite.\n"; exit(2); }
echo "  ✅ ALL VERIFIERS PASS.\n";
exit(0);
