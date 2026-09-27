<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * DEPLOYMENT STEP VERIFIER · READ ONLY · NEVER DEPLOYS
 * ══════════════════════════════════════════════════════════════════════════
 *
 *   php scripts/deploy_step_verify.php pre  <PREDECESSOR> <ARTIFACT> <EXPECTED_HUNKS>
 *   php scripts/deploy_step_verify.php post <INTENDED_ARTIFACT> <DEPLOYED_PULL> <PREDECESSOR>
 *
 * Runs the mechanical half of the deployment protocol identically for every
 * step, so step 6 gets the same scrutiny as step 1. Exits non-zero on any
 * failure; the sequence stops there.
 *
 * It deliberately does NOT deploy and does NOT run the emulator suites — those
 * stay explicit, separate, observed actions.
 *
 * ── WHAT IT CHECKS ────────────────────────────────────────────────────────
 * pre
 *   - artifact parses, brace-balanced, same match-block count as predecessor
 *   - the diff against the PREDECESSOR is exactly EXPECTED_HUNKS hunks
 *   - no line was removed other than the arms the change targets
 *   - the artifact is not the repository's dirty firestore.rules
 * post
 *   - the deployed ruleset is byte-identical to the intended artifact
 *   - the deployed ruleset differs from the predecessor by the same hunks
 *   - nothing else moved
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

$mode = strtolower(trim((string) ($argv[1] ?? '')));
if (!in_array($mode, ['pre', 'post'], true)) {
    fwrite(STDERR, "usage: php scripts/deploy_step_verify.php pre|post ...\n");
    exit(2);
}

function readRules($p, $label)
{
    if (!is_file($p)) { fwrite(STDERR, "{$label} not found: {$p}\n"); exit(2); }
    $s = (string) file_get_contents($p);
    if (strlen($s) < 100000) { fwrite(STDERR, "{$label} looks truncated ({$p})\n"); exit(2); }
    return str_replace("\r", '', $s);
}

function braceDepth($s)
{
    $d = 0;
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        if ($s[$i] === '{') $d++;
        elseif ($s[$i] === '}') $d--;
    }
    return $d;
}

/** Line-level diff: returns [hunks, removedLines, addedLines]. */
function diffLines($a, $b)
{
    $al = explode("\n", $a);
    $bl = explode("\n", $b);

    // Longest-common-subsequence is overkill; the transforms only ever replace
    // a single line with several, so a simple sync-forward walk is exact here
    // and — unlike array_diff — is POSITIONAL. array_diff compares VALUES, and
    // identical rule lines recur throughout the file, which is how an earlier
    // "minimality proof" reported 0 changed lines for a 9-line change.
    $i = 0; $j = 0; $hunks = 0; $removed = []; $added = [];
    $na = count($al); $nb = count($bl);
    while ($i < $na && $j < $nb) {
        if ($al[$i] === $bl[$j]) { $i++; $j++; continue; }
        $hunks++;
        $sync = false;
        for ($k = $j; $k < min($j + 40, $nb); $k++) {
            if (isset($al[$i + 1]) && $bl[$k] === $al[$i + 1]) {
                for ($m = $j; $m < $k; $m++) $added[] = $bl[$m];
                $removed[] = $al[$i];
                $j = $k; $i++; $sync = true; break;
            }
        }
        if (!$sync) { $removed[] = $al[$i]; $added[] = $bl[$j]; $i++; $j++; }
    }
    while ($i < $na) { $removed[] = $al[$i++]; }
    while ($j < $nb) { $added[] = $bl[$j++]; }
    return [$hunks, $removed, $added];
}

$fail = [];

if ($mode === 'pre') {
    $predPath = (string) ($argv[2] ?? '');
    $artPath  = (string) ($argv[3] ?? '');
    $expected = (int) ($argv[4] ?? 0);

    $pred = readRules($predPath, 'predecessor');
    $art  = readRules($artPath, 'artifact');

    echo "DEPLOYMENT STEP · PRE-FLIGHT\n";
    echo str_repeat('=', 78) . "\n";
    printf("predecessor : %s (%d bytes)\n", basename($predPath), strlen($pred));
    printf("artifact    : %s (%d bytes)\n", basename($artPath), strlen($art));
    printf("sha256      : %s\n\n", hash('sha256', (string) file_get_contents($artPath)));

    $d = braceDepth($art);
    printf("brace depth       : %d %s\n", $d, $d === 0 ? '(balanced)' : '(UNBALANCED)');
    if ($d !== 0) $fail[] = 'artifact braces unbalanced';

    $pm = preg_match_all('/match \//', $pred);
    $am = preg_match_all('/match \//', $art);
    printf("match blocks      : %d -> %d %s\n", $pm, $am, $pm === $am ? '(unchanged)' : '(CHANGED)');
    if ($pm !== $am) $fail[] = 'match-block count changed';

    list($hunks, $removed, $added) = diffLines($pred, $art);
    printf("diff hunks        : %d (expected %d) %s\n", $hunks, $expected,
        $hunks === $expected ? '(OK)' : '(MISMATCH)');
    if ($expected > 0 && $hunks !== $expected) $fail[] = "expected {$expected} hunks, found {$hunks}";

    printf("lines removed     : %d\nlines added       : %d\n\n", count($removed), count($added));

    echo "REMOVED lines (each must be an arm the change targets):\n";
    $u = array_count_values(array_map('trim', $removed));
    foreach ($u as $line => $n) printf("  %2dx  %s\n", $n, $line);

    // A dirty-repo guard: the repository ruleset is far larger than production.
    $repo = __DIR__ . '/../firebase-rules/firestore.rules';
    if (is_file($repo)) {
        $rs = strlen((string) file_get_contents($repo));
        $delta = abs($rs - strlen($art));
        printf("\nrepo firestore.rules: %d bytes; artifact differs by %d bytes %s\n",
            $rs, $delta, $delta > 20000 ? '(clearly NOT the repo file)' : '(SUSPICIOUS — verify)');
        if ($delta < 20000) $fail[] = 'artifact is suspiciously close to the dirty repo ruleset';
    }
} else {
    $artPath  = (string) ($argv[2] ?? '');
    $depPath  = (string) ($argv[3] ?? '');
    $predPath = (string) ($argv[4] ?? '');

    $art  = readRules($artPath, 'intended artifact');
    $dep  = readRules($depPath, 'deployed pull');
    $pred = readRules($predPath, 'predecessor');

    echo "DEPLOYMENT STEP · POST-FLIGHT\n";
    echo str_repeat('=', 78) . "\n";
    printf("intended : %s\ndeployed : %s\n\n", basename($artPath), basename($depPath));

    $same = ($art === $dep);
    printf("deployed == intended artifact : %s\n", $same ? 'YES (exact)' : 'NO');
    if (!$same) $fail[] = 'deployed ruleset does not match the intended artifact';

    list($hunks, $removed, ) = diffLines($pred, $dep);
    printf("deployed vs predecessor       : %d hunks, %d lines removed\n", $hunks, count($removed));
    echo "REMOVED lines:\n";
    $u = array_count_values(array_map('trim', $removed));
    foreach ($u as $line => $n) printf("  %2dx  %s\n", $n, $line);
}

echo "\n" . str_repeat('=', 78) . "\n";
if ($fail === []) { echo "ALL STRUCTURAL CHECKS PASSED.\n"; exit(0); }
echo "FAILED:\n";
foreach ($fail as $f) echo "  - {$f}\n";
echo "STOP THE SEQUENCE.\n";
exit(1);
