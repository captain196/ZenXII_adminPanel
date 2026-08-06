<?php
/**
 * diff_snapshot.php — Release Integration Phase 1 differential validation.
 *
 * Captures a set of READ-ONLY outputs for a tenant into a snapshot dir, so the
 * SAME tenant can be snapshotted on prod-code (ank-yug_b1) and on integration
 * code (integration/phase1) and DIFFED — proving the integration changes only
 * what it intends and nothing else.
 *
 * Capture:   php scripts/diff_snapshot.php --school=SCH_XXXX --out=/tmp/snap_pre
 *   (checkout the other branch, re-run with --out=/tmp/snap_post)
 * Diff:      php scripts/diff_snapshot.php --diff /tmp/snap_pre /tmp/snap_post
 *
 * Output is NORMALISED (timestamps, run-ids, elapsed times stripped) so the
 * diff surfaces SUBSTANTIVE deltas only — not run-to-run noise.
 *
 * SAFETY: capture targets must be READ-ONLY. Do NOT add mutating commands.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(2); }

$args = array_slice($argv, 1);

// ── DIFF MODE ────────────────────────────────────────────────────────────────
$di = array_search('--diff', $args, true);
if ($di !== false) {
    $a = $args[$di + 1] ?? ''; $b = $args[$di + 2] ?? '';
    if (!is_dir($a) || !is_dir($b)) { fwrite(STDERR, "Usage: --diff <preDir> <postDir>\n"); exit(2); }
    $files = array_unique(array_merge(
        array_map('basename', glob("$a/*.txt")),
        array_map('basename', glob("$b/*.txt"))
    ));
    sort($files);
    $changed = 0;
    echo "\n═══════════════ Differential — pre={$a}  post={$b} ═══════════════\n\n";
    foreach ($files as $f) {
        $pa = "$a/$f"; $pb = "$b/$f";
        if (!is_file($pa)) { echo "  + ADDED   {$f}\n"; $changed++; continue; }
        if (!is_file($pb)) { echo "  - REMOVED {$f}\n"; $changed++; continue; }
        if (md5_file($pa) === md5_file($pb)) { echo "  = same    {$f}\n"; continue; }
        $changed++;
        echo "  ~ CHANGED {$f}\n";
        // Show a compact unified diff (first ~20 differing lines).
        $la = file($pa, FILE_IGNORE_NEW_LINES); $lb = file($pb, FILE_IGNORE_NEW_LINES);
        $max = max(count($la), count($lb)); $shown = 0;
        for ($i = 0; $i < $max && $shown < 20; $i++) {
            $x = $la[$i] ?? ''; $y = $lb[$i] ?? '';
            if ($x !== $y) { echo "        - {$x}\n        + {$y}\n"; $shown++; }
        }
    }
    echo "\n───────────────────────────────────────────────────────────────────\n";
    echo $changed === 0
        ? "  ✅ IDENTICAL — integration changed nothing observable here.\n"
        : "  ⚠  {$changed} snapshot(s) differ — confirm EVERY delta is intended.\n";
    exit(0);
}

// ── CAPTURE MODE ─────────────────────────────────────────────────────────────
$school = 'SCH_D94FE8F7AD'; $out = '';
foreach ($args as $x) {
    if (strpos($x, '--school=') === 0) $school = substr($x, 9);
    if (strpos($x, '--out=')    === 0) $out    = substr($x, 6);
}
if ($out === '') { fwrite(STDERR, "Usage: --school=SCH_XXX --out=<dir>\n"); exit(2); }
if (!is_dir($out)) @mkdir($out, 0777, true);

$INDEX = __DIR__ . '/../index.php';

/**
 * READ-ONLY capture targets. Seeded with deterministic self-tests; ADD your
 * key money/identity read endpoints (payslip preview, ledger export, fee demand,
 * role matrix) with their exact CLI routes to make the diff cover them.
 */
$TARGETS = [
    'rbac_role_matrix'   => ['deptrole_selftest', 'index', $school],
    'timetables_health'  => ['timetables_healthcheck', 'run'],
    // ADD (confirm each route first — must be READ-ONLY):
    // 'payroll_preview' => ['hr', 'preflight_payroll'],            // + query args
    // 'ledger_export'   => ['accounting', 'export_ledger', $school],
    // 'fee_demands'     => ['fees', 'list_demands', $school],
];

// Normalise volatile tokens so diffs are substantive, not run-to-run noise.
function normalise(string $s): string {
    $s = preg_replace('/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?([+-]\d{2}:?\d{2}|Z)?/', '<TS>', $s);
    $s = preg_replace('/\b(PR|RUN|JE|LR|LT)[_-]?[A-Z0-9]{6,}\b/', '<ID>', $s);
    $s = preg_replace('/elapsed[=: ]+[\d.]+\s*m?s/i', 'elapsed=<T>', $s);
    return $s;
}

foreach ($TARGETS as $slug => $argv2) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($INDEX);
    foreach ($argv2 as $a) $cmd .= ' ' . escapeshellarg($a);
    $cmd .= ' 2>&1';
    $raw = shell_exec($cmd) ?? '';
    file_put_contents("$out/$slug.txt", normalise($raw));
    echo "  captured {$slug}\n";
}
echo "\n  snapshot written to {$out}  (school={$school})\n";
echo "  next: checkout the other branch, re-run with a different --out, then --diff.\n";
exit(0);
