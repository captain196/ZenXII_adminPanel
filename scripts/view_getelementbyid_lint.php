<?php
/**
 * Catch `getElementById(someId)` where the QUOTES WERE LOST.
 *
 * Provenance: 2026-09-12. A patch applied through an inline `php -r` with
 * nested quotes silently stripped them, emitting
 *
 *     document.getElementById(tt_supersede_version).value = ...
 *
 * instead of `getElementById('tt_supersede_version')`. That is a reference to
 * an undefined global, so it returns null and the click died with
 * "Cannot set properties of null" — in the browser only.
 *
 * Neither existing gate could see it:
 *   - `node --check` passes: a bare identifier is valid SYNTAX, wrong SEMANTICS.
 *   - the reachability test passed: it greps for the handler and the endpoint,
 *     both of which were present and correct.
 *
 * THE RULE, kept narrow so it does not cry wolf: dynamic lookups are
 * legitimate and common — `getElementById(paneId)`, `getElementById(target)`
 * — and those identifiers are function parameters. What is never legitimate is
 * a bare identifier that is ALSO an `id="..."` in the same file: that is a
 * string literal that lost its quotes.
 *
 * Exit 0 = clean, 1 = findings. Read-only.
 */

$FILES = $argv[1] ?? null;
$targets = $FILES
    ? [$FILES]
    : array_merge(
        glob(__DIR__ . '/../application/views/*/*.php') ?: [],
        glob(__DIR__ . '/../application/views/*.php') ?: []
    );

$findings = 0;
$scanned  = 0;

foreach ($targets as $path) {
    $src = @file_get_contents($path);
    if ($src === false || $src === '') { continue; }
    $scanned++;

    // Every id the markup actually defines.
    preg_match_all('/\bid="([A-Za-z_][A-Za-z0-9_\-]*)"/', $src, $m);
    $declaredIds = array_flip($m[1]);
    if (!$declaredIds) { continue; }

    // Every getElementById called with a bare identifier (no quotes).
    preg_match_all(
        '/getElementById\(\s*([A-Za-z_][A-Za-z0-9_]*)\s*\)/',
        $src, $calls, PREG_OFFSET_CAPTURE);

    foreach ($calls[1] as $c) {
        [$ident, $off] = $c;
        if (!isset($declaredIds[$ident])) {
            continue;   // a genuine dynamic lookup (parameter/variable)
        }
        $line = substr_count(substr($src, 0, $off), "\n") + 1;
        printf("%s:%d  getElementById(%s) — unquoted, but \"%s\" is an id=\"\" in this file.\n",
            str_replace('\\', '/', $path), $line, $ident, $ident);
        printf("        Did you mean getElementById('%s')? A bare identifier resolves to\n", $ident);
        printf("        an undefined global and returns null at runtime.\n");
        $findings++;
    }
}

printf("\n=== RESULT ===\n  files scanned: %d\n  findings:      %d\n", $scanned, $findings);
if ($findings === 0) {
    echo "\nNo getElementById call names an element id without quoting it.\n"
       . "(Lookups through a variable are not flagged — they are legitimate.)\n";
    exit(0);
}
echo "\nEach finding is a runtime null the browser reports only when the control is used.\n";
exit(1);
