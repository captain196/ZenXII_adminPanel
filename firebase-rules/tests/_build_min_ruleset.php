<?php
/**
 * Build a MINIMAL ruleset containing only what CAL-D1 exercises.
 *
 * Why: the full firestore.rules is ~200 KB / 3639 lines and the Firestore
 * emulator's JVM cannot compile it on this box (580 MB free; another session's
 * emulator holds the rest). Rather than kill a foreign process, this extracts
 * every helper function plus only the four collection blocks CAL-D1 tests, so
 * the ruleset the emulator must compile is a fraction of the size.
 *
 * The CAL-D1 suite was designed for exactly this: it accepts any ruleset via
 * CAL_D1_RULES and reports what that ruleset actually permits.
 *
 * HONEST LIMITATION: this proves the ARM-LEVEL logic of those four collections.
 * It is NOT a whole-file proof — a broader rule elsewhere in the real file
 * could still grant access this subset would deny. Recorded as such.
 *
 * Read-only with respect to firestore.rules; writes only its own output file.
 */

$SRC = __DIR__ . '/../firestore.rules';
$OUT = __DIR__ . '/_cal_d1_min.rules';

/**
 * Collections CAL-D1 actually touches - DERIVED FROM THE TEST FILE, not from a
 * hand-written grep. A hand-written list missed 'appraisals' (the suite loops
 * over a COLLECTIONS array), which would have produced a ruleset that passed
 * while never testing one of the five arms.
 */
$TEST = __DIR__ . '/cal_d1_tenant_binding.test.js';
$tsrc = file_get_contents($TEST);
$KEEP = [];
if (preg_match('/const\s+COLLECTIONS\s*=\s*\[(.*?)\]/s', $tsrc, $mm)) {
    preg_match_all("/'([a-zA-Z_]+)'/", $mm[1], $c);
    $KEEP = $c[1];
}
preg_match_all("/collection\('([a-zA-Z_]+)'\)/", $tsrc, $c2);
$KEEP = array_values(array_unique(array_merge($KEEP, $c2[1])));
sort($KEEP);
if (!$KEEP) { fwrite(STDERR, "could not derive collection list from the test file
"); exit(2); }
echo 'derived from test: ' . implode(', ', $KEEP) . "
";

$lines = file($SRC, FILE_IGNORE_NEW_LINES);
if (!$lines) { fwrite(STDERR, "cannot read {$SRC}\n"); exit(2); }

/** Capture a brace-balanced block starting at $i. Returns [blockLines, nextIndex]. */
function capture(array $lines, int $i): array
{
    $depth = 0; $out = []; $started = false;
    for ($n = count($lines); $i < $n; $i++) {
        $l = $lines[$i];
        $out[] = $l;
        $depth += substr_count($l, '{') - substr_count($l, '}');
        if (!$started && strpos($l, '{') !== false) { $started = true; }
        if ($started && $depth <= 0) { return [$out, $i + 1]; }
    }
    return [$out, $i];
}

$functions = [];
$blocks    = [];
$kept      = [];

for ($i = 0, $n = count($lines); $i < $n; ) {
    $l = $lines[$i];

    // Helper functions — keep every one; they are small and interdependent.
    if (preg_match('/^\s*function\s+[a-zA-Z_]+\s*\(/', $l)) {
        list($blk, $next) = capture($lines, $i);
        $functions[] = implode("\n", $blk);
        $i = $next;
        continue;
    }

    // The service / databases wrapper must be DESCENDED INTO, never captured:
    // capturing it swallows the whole file (this bug produced 0 blocks).
    if (preg_match('#^\s*match\s+/databases/#', $l) || preg_match('#^\s*service\s#', $l)) { $i++; continue; }

    // Collection match blocks — keep only the four CAL-D1 exercises.
    if (preg_match('#^\s*match\s+/([a-zA-Z_]+)/#', $l, $m)) {
        list($blk, $next) = capture($lines, $i);
        if (in_array($m[1], $GLOBALS['KEEP'], true)) {
            $blocks[] = implode("\n", $blk);
            $kept[]   = $m[1];
        }
        $i = $next;
        continue;
    }

    $i++;
}

$out  = "rules_version = '2';\n\n";
$out .= "// MINIMAL CAL-D1 RULESET - generated, do not edit by hand.\n";
$out .= "// Contains every helper function plus only: " . implode(', ', $kept) . "\n";
$out .= "// ARM-LEVEL proof only; not a whole-file proof.\n\n";
$out .= "service cloud.firestore {\n";
$out .= "  match /databases/{database}/documents {\n\n";
$out .= implode("\n\n", $functions) . "\n\n";
$out .= implode("\n\n", $blocks) . "\n\n";
$out .= "  }\n}\n";

file_put_contents($OUT, $out);

printf("source   : %d lines, %d bytes\n", count($lines), filesize($SRC));
printf("minimal  : %d lines, %d bytes  (%.1f%% of original)\n",
    substr_count($out, "\n"), strlen($out), 100 * strlen($out) / filesize($SRC));
printf("functions: %d\n", count($functions));
printf("blocks   : %d  (%s)\n", count($blocks), implode(', ', $kept));
printf("written  : %s\n", $OUT);

$missing = array_diff($KEEP, $kept);
if ($missing) {
    fwrite(STDERR, "WARNING: collections requested but NOT found: " . implode(', ', $missing) . "\n");
    exit(1);
}
