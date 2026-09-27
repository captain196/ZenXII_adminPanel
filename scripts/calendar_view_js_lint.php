<?php
/**
 * JAVASCRIPT SYNTAX GATE FOR THE CALENDAR VIEWS.
 *
 * WHY THIS EXISTS. `php -l` parses the PHP that EMITS the page; it never parses
 * the JavaScript that comes out. This programme shipped a real syntax error
 * through that blind spot: a PHP patch script turned `'\n\n'` into a literal
 * newline inside a single-quoted JS string, producing an unterminated string
 * that would have broken the whole script block. Every PHP lint passed, every
 * PHPUnit assertion passed, and the page would have been dead on arrival.
 *
 * So the JS is extracted and handed to a real JavaScript parser.
 *
 * HOW THE PHP IS NEUTRALISED. Server tags inside a script block are replaced
 * with a harmless literal, because we are checking the SHAPE of the emitted
 * JavaScript, not its runtime values:
 *
 *     var w = <?= json_encode($weekendDays ?? [0]) ?>;   →   var w = 0;
 *
 * That keeps every brace, quote and statement boundary exactly where the author
 * put it, which is what a syntax check needs. A tag inside a string literal
 * collapses to `0` too, which is still valid.
 *
 * WHAT IT CANNOT DO. This proves the file PARSES. It does not prove the page
 * behaves, that a selector matches, or that a request succeeds — only a browser
 * can do that, and this script must never be reported as if it had.
 *
 *   node --version   (required)
 *   php scripts/calendar_view_js_lint.php
 */

chdir(__DIR__ . '/..');

$VIEWS = [
    'application/views/student_calendar/index.php',
    'application/views/academic/index.php',
    'application/views/exam/create.php',
];

$tmpDir = sys_get_temp_dir() . '/zenx_js_lint';
if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);

$failures = [];
$checked  = 0;

foreach ($VIEWS as $view) {
    $src = @file_get_contents($view);
    if ($src === false) { $failures[] = "{$view}: unreadable"; continue; }

    // Every <script> block that is NOT an external src= include.
    if (!preg_match_all('~<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>~is', $src, $m)) {
        echo "  {$view}: no inline script blocks\n";
        continue;
    }

    foreach ($m[1] as $i => $js) {
        // Neutralise server tags — shape preserved, values discarded.
        $js = preg_replace('~<\?(?:php|=).*?\?>~s', '0', $js);

        // A block that is only PHP-generated noise is not worth parsing.
        if (trim($js) === '' || trim($js) === '0') continue;

        $file = $tmpDir . '/' . basename(dirname($view)) . '_' . basename($view, '.php')
              . '_' . $i . '.js';
        file_put_contents($file, $js);

        $out = [];
        $code = 0;
        exec('node --check ' . escapeshellarg($file) . ' 2>&1', $out, $code);
        $checked++;

        if ($code !== 0) {
            $failures[] = "{$view} (script block #{$i}):\n      "
                        . implode("\n      ", array_slice($out, 0, 6));
        }
    }
}

echo "\n=== RESULT ===\n";
echo "  script blocks parsed: {$checked}\n";
echo "  failures:             " . count($failures) . "\n";

if ($failures) {
    echo "\nSYNTAX ERRORS:\n  " . implode("\n  ", $failures) . "\n";
    exit(1);
}
echo "\nAll inline JavaScript parses. (Parsing only — this is NOT behavioural proof.)\n";
exit(0);
