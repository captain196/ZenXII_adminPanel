<?php
/**
 * rtdb_audit.php — discover remaining Firebase RTDB call sites.
 *
 * Policy (per CLAUDE.md memory): zero RTDB usage, Firestore ONLY.
 * This script scans every controller + library and reports each
 * RTDB touchpoint grouped by module, so an operator can prioritise
 * the migration by call-volume.
 *
 * Detected patterns (all on $this->firebase or FirebaseRTDB):
 *   firebaseGet  / firebaseSet / firebasePush / firebaseUpdate /
 *   firebaseDelete / firebaseGetShallow
 *   ->firebase->get / set / push / update / delete (chained)
 *
 * The audit is RISK-ranked:
 *   WRITE-heavy modules first (they cause data drift; reads are recoverable)
 *   Then READ-heavy modules (every read must move to Firestore before
 *   the RTDB node can be archived).
 *
 * Usage:
 *   php scripts/rtdb_audit.php              # text summary
 *   php scripts/rtdb_audit.php --json       # machine-readable
 *   php scripts/rtdb_audit.php --detail     # per-call-site listing
 *   php scripts/rtdb_audit.php --only=Fees  # filter to one module
 */

$root = realpath(__DIR__ . '/..');
$scanDirs = [
    $root . '/application/controllers',
    $root . '/application/libraries',
    $root . '/application/core',
];

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (strncmp($a, '--', 2) !== 0) continue;
    $a = substr($a, 2);
    $eq = strpos($a, '=');
    $opts[$eq === false ? $a : substr($a, 0, $eq)] = $eq === false ? true : substr($a, $eq + 1);
}
$json   = !empty($opts['json']);
$detail = !empty($opts['detail']);
$only   = (string) ($opts['only'] ?? '');

// Files intentionally excluded — historical backups / superadmin console
// tooling where RTDB stays by design (data migration utilities).
$skipBasenames = [
    'Fees_backup.php',
    'Superadmin_migration.php', // the tool that intentionally reads RTDB to migrate it
    'Superadmin_backups.php',   // backup/restore operates on the raw RTDB tree
    'Fee_simulation.php',       // dev-only load-test harness; gated on APP_ENV=dev
];

$writePatterns = [
    // $this->firebase->firebaseSet / Push / Update / Delete
    '/\$this->firebase->firebase(Set|Push|Update|Delete)\s*\(/',
    // ->firebase->set / push / update / delete (chained)
    '/->firebase->(set|push|update|delete)\s*\(/',
    // Direct FirebaseRTDB class use
    '/FirebaseRTDB::(set|push|update|delete)/',
];
$readPatterns = [
    '/\$this->firebase->firebase(Get|GetShallow)\s*\(/',
    '/->firebase->(get|getShallow|read)\s*\(/',
    '/FirebaseRTDB::(get|getShallow)/',
];

$findings = [];    // [ file => [ {line, method, kind:'read|write', snippet, path?} ] ]
$byModule = [];    // module => [read, write, files[]]

foreach ($scanDirs as $dir) {
    if (!is_dir($dir)) continue;
    $files = glob($dir . '/*.php') ?: [];
    foreach ($files as $file) {
        $base = basename($file);
        if (in_array($base, $skipBasenames, true)) continue;
        if ($only !== '' && stripos($base, $only) === false) continue;

        $src   = (string) file_get_contents($file);
        $lines = explode("\n", $src);

        // Track the current method name for each line so findings can
        // be reported as `Controller::method`. We walk public/private
        // function declarations forward.
        $currentMethod = '';
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(public|private|protected)?\s*function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $line, $mm)) {
                $currentMethod = $mm[2];
            }
            $mod = _modKey($base);
            if (!isset($byModule[$mod])) $byModule[$mod] = ['read' => 0, 'write' => 0];
            foreach ($writePatterns as $p) {
                if (preg_match($p, $line)) $byModule[$mod]['write']++;
            }
            foreach ($readPatterns as $p) {
                if (preg_match($p, $line)) $byModule[$mod]['read']++;
            }
            // Detailed per-call-site capture (only when --detail).
            if ($detail) {
                foreach ($writePatterns as $p) {
                    if (preg_match($p, $line)) {
                        $findings[$base][] = [
                            'line' => $i + 1, 'method' => $currentMethod,
                            'kind' => 'write', 'snippet' => trim(substr($line, 0, 140)),
                        ];
                    }
                }
                foreach ($readPatterns as $p) {
                    if (preg_match($p, $line)) {
                        $findings[$base][] = [
                            'line' => $i + 1, 'method' => $currentMethod,
                            'kind' => 'read', 'snippet' => trim(substr($line, 0, 140)),
                        ];
                    }
                }
            }
        }
    }
}

function _modKey(string $base): string { return pathinfo($base, PATHINFO_FILENAME); }

// Normalise byModule so every key has {read,write,total}
$rows = [];
foreach ($byModule as $mod => $counts) {
    $read  = (int) ($counts['read']  ?? 0);
    $write = (int) ($counts['write'] ?? 0);
    if ($read === 0 && $write === 0) continue;
    $rows[] = [
        'module' => $mod,
        'read'   => $read,
        'write'  => $write,
        'total'  => $read + $write,
        'risk'   => $write >= 10 ? 'HIGH' : ($write > 0 ? 'MED' : 'LOW'),
    ];
}
usort($rows, function ($a, $b) {
    // Writers first (drift risk), then read-heavy.
    if ($a['write'] !== $b['write']) return $b['write'] <=> $a['write'];
    return $b['total'] <=> $a['total'];
});

$totals = ['read' => 0, 'write' => 0];
foreach ($rows as $r) { $totals['read'] += $r['read']; $totals['write'] += $r['write']; }

if ($json) {
    echo json_encode([
        'totals'  => $totals + ['modules' => count($rows)],
        'modules' => $rows,
        'detail'  => $detail ? $findings : null,
    ], JSON_PRETTY_PRINT) . "\n";
    exit($totals['write'] + $totals['read'] > 0 ? 1 : 0);
}

echo "\nRTDB AUDIT — Admin codebase\n";
echo str_repeat('=', 78) . "\n";
printf("Total call sites: %d  (reads=%d  writes=%d  modules=%d)\n\n",
    $totals['read'] + $totals['write'], $totals['read'], $totals['write'], count($rows));
echo str_pad('#', 4) . str_pad('RISK', 6) . str_pad('MODULE', 32)
   . str_pad('READ', 8, ' ', STR_PAD_LEFT) . '  '
   . str_pad('WRITE', 8, ' ', STR_PAD_LEFT) . '  '
   . str_pad('TOTAL', 8, ' ', STR_PAD_LEFT) . "\n";
echo str_repeat('-', 78) . "\n";
foreach ($rows as $i => $r) {
    echo str_pad((string)($i+1), 4)
       . str_pad($r['risk'], 6)
       . str_pad($r['module'], 32)
       . str_pad((string) $r['read'],  8, ' ', STR_PAD_LEFT) . '  '
       . str_pad((string) $r['write'], 8, ' ', STR_PAD_LEFT) . '  '
       . str_pad((string) $r['total'], 8, ' ', STR_PAD_LEFT) . "\n";
}

if ($detail && !empty($findings)) {
    echo "\nDETAIL — per-call-site\n" . str_repeat('=', 78) . "\n";
    foreach ($findings as $file => $rows) {
        echo "\n{$file}\n";
        foreach ($rows as $r) {
            printf("  L%-5d  %-22s  %-5s  %s\n", $r['line'], $r['method'], strtoupper($r['kind']), $r['snippet']);
        }
    }
}

echo "\nTriage: migrate HIGH-risk WRITE modules first — they produce new RTDB-only data\n";
echo "that will grow until writes are cut. Reads are safe to port last.\n";
exit($totals['write'] + $totals['read'] > 0 ? 1 : 0);
