<?php
/**
 * READ-ONLY runtime dependency tracer for the payment programme.
 *
 * The question this answers is NOT "which payment-ish files exist" but
 * "which files does a parent payment actually EXECUTE". Those differ, and
 * the difference decides how large the production release has to be.
 *
 * Method: start at the three real entry points, extract every class the code
 * loads (`load->library`, `new X`, `X::`, `require`), resolve each to a file,
 * and recurse. A class referenced only inside a method that the payment path
 * never calls will still be reported — static analysis cannot prove
 * reachability through CI's dynamic loader — so every hit is reported with the
 * SITE that referenced it, and the caller decides.
 *
 *   php scripts/payment_runtime_dependency_trace.php
 */

$ROOT = __DIR__ . '/..';
$APP  = $ROOT . '/application';

/** Entry points: the three endpoints a payment actually traverses. */
$ENTRY = [
    'controllers/Fee_management.php',
    'services/FeeCollectionService.php',
];

/** Where a class name might live. */
function candidates(string $class): array {
    $c = ucfirst($class);
    return [
        "libraries/{$c}.php",
        "libraries/{$class}.php",
        "services/{$c}.php",
        "services/{$class}.php",
        "helpers/{$class}.php",
        "helpers/" . strtolower($class) . ".php",
        "core/{$c}.php",
    ];
}

$seen = [];      // relPath => ['refs' => [site => count]]
$queue = [];
foreach ($ENTRY as $e) { $queue[] = $e; $seen[$e] = ['refs' => ['ENTRY POINT' => 1]]; }

while ($queue) {
    $rel = array_shift($queue);
    $path = $APP . '/' . $rel;
    if (!is_file($path)) continue;
    $src = file_get_contents($path);

    // Strip comments so a class named in prose is not counted as a dependency.
    $code = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) {
            if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
            $code .= $t[1];
        } else { $code .= $t; }
    }

    $found = [];
    // CI loader: $this->load->library('X') / load->helper('x')
    if (preg_match_all("/load->(?:library|helper)\(\s*'([A-Za-z0-9_]+)'/", $code, $m)) {
        foreach ($m[1] as $c) $found[$c] = 'load->library';
    }
    // Static calls and instantiation
    if (preg_match_all('/\b([A-Z][A-Za-z0-9_]{2,})::/', $code, $m)) {
        foreach ($m[1] as $c) $found[$c] = $found[$c] ?? 'static call';
    }
    if (preg_match_all('/\bnew\s+([A-Z][A-Za-z0-9_]{2,})\s*\(/', $code, $m)) {
        foreach ($m[1] as $c) $found[$c] = $found[$c] ?? 'new';
    }
    // Explicit requires
    if (preg_match_all("/require(?:_once)?\s+APPPATH\s*\.\s*'([^']+)'/", $code, $m)) {
        foreach ($m[1] as $r) {
            if (!isset($seen[$r])) { $seen[$r] = ['refs' => []]; $queue[] = $r; }
            $seen[$r]['refs'][$rel . ' (require)'] = 1;
        }
    }

    $SKIP = ['CI_Controller','Exception','RuntimeException','Throwable','TypeError',
             'DateTime','DateTimeZone','ArrayObject','Closure','Generator','JsonException',
             'InvalidArgumentException','LogicException','Error','StdClass','PDO'];

    foreach ($found as $class => $how) {
        if (in_array($class, $SKIP, true)) continue;
        foreach (candidates($class) as $cand) {
            if (is_file($APP . '/' . $cand)) {
                if (!isset($seen[$cand])) { $seen[$cand] = ['refs' => []]; $queue[] = $cand; }
                $seen[$cand]['refs'][$rel . " ({$how})"] = 1;
                break;
            }
        }
    }
}

// ── Report ────────────────────────────────────────────────────────────────
$tracked = [];
exec('git ls-files 2>NUL', $tracked);
$trackedSet = array_flip(array_map(static fn($p) => str_replace('\\', '/', $p), $tracked));

ksort($seen);
$reqUntracked = 0; $total = 0;
printf("%-52s %-10s %s\n", 'FILE (application/…)', 'IN GIT', 'FIRST REFERENCED BY');
echo str_repeat('-', 130) . "\n";
foreach ($seen as $rel => $info) {
    $total++;
    $git = isset($trackedSet['application/' . $rel]) ? 'tracked' : '** NOT **';
    if ($git !== 'tracked') $reqUntracked++;
    $first = array_key_first($info['refs']);
    printf("%-52s %-10s %s\n", $rel, $git, $first);
}
echo str_repeat('-', 130) . "\n";
echo "  files reached from the payment entry points : {$total}\n";
echo "  of those, NOT in git (absent from production): {$reqUntracked}\n";
