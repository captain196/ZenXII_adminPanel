<?php
/**
 * Standalone tests for qr_token_helper — covers HMAC signing, legacy
 * token compatibility, junk-input handling, and the SVG render path.
 *
 * Usage: php application/tests/test_qr_token_helper.php
 */

define('BASEPATH', __DIR__);
define('APPPATH',  __DIR__ . '/..');
if (!function_exists('log_message')) {
    function log_message($l, $m) { /* no-op */ }
}
// Stub config_item — the helper reads the secret from this.
if (!function_exists('config_item')) {
    $__cfg = ['qr_token_secret' => 'test-secret-A', 'encryption_key' => ''];
    function config_item($k) { global $__cfg; return $__cfg[$k] ?? ''; }
}
// Stub base_url — the same-origin URL builder uses this.
if (!function_exists('base_url')) {
    function base_url($p = '') { return 'http://localhost/' . ltrim($p, '/'); }
}
// Composer autoload — chillerlan/php-qrcode is required for SVG render.
require __DIR__ . '/../../vendor/autoload.php';

require __DIR__ . '/../helpers/qr_token_helper.php';

$passed = 0; $failed = 0; $failures = [];
function ok($n) { global $passed; $passed++; echo "  PASS  {$n}\n"; }
function fail($n, $w) { global $failed, $failures; $failed++; $failures[] = "{$n}: {$w}"; echo "  FAIL  {$n}\n        {$w}\n"; }
function assertEq($n, $e, $a) { if ($e === $a) ok($n); else fail($n, 'expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function assertNotEq($n, $e, $a) { if ($e !== $a) ok($n); else fail($n, 'should not equal ' . var_export($e, true)); }
function assertNull($n, $a) { if ($a === null) ok($n); else fail($n, 'expected null, got ' . var_export($a, true)); }
function assertArr($n, $a) { if (is_array($a)) ok($n); else fail($n, 'expected array, got ' . var_export($a, true)); }
function assertTrue($n, $a) { if ($a === true) ok($n); else fail($n, 'expected true, got ' . var_export($a, true)); }

echo "\n=== qr_token_helper tests ===\n\n";

// ─── 1. Signed round-trip ─────────────────────────────────────────────
echo "[1] Signed round-trip\n";
$tok = qr_token_encode('SCH_TEST_001', 'STU0001');
$dec = qr_token_decode($tok);
assertArr('decoded is array', $dec);
assertEq('schoolId preserved',  'SCH_TEST_001', $dec['schoolId']);
assertEq('studentId preserved', 'STU0001',      $dec['studentId']);
assertEq('legacy flag is false', false, $dec['legacy']);

// ─── 2. Encoded token is URL-safe and has 3 payload parts ─────────────
echo "\n[2] Encoding shape\n";
assertEq('no + char', false, strpos($tok, '+') !== false);
assertEq('no / char', false, strpos($tok, '/') !== false);
assertEq('no = padding', false, strpos($tok, '=') !== false);
// The payload should be "schoolId|studentId|sig"
$decoded_b64 = base64_decode(strtr($tok, '-_', '+/') . str_repeat('=', (4 - strlen($tok) % 4) % 4));
$parts = explode('|', $decoded_b64);
assertEq('payload has 3 parts (signed)', 3, count($parts));
assertEq('part 2 is 16-hex sig', 1, preg_match('/^[a-f0-9]{16}$/', $parts[2]));

// ─── 3. Underscores in schoolId still split correctly ─────────────────
echo "\n[3] Underscore-heavy schoolId round-trips\n";
$tok = qr_token_encode('SCH_TEST_001_FOO', 'STU0042');
$dec = qr_token_decode($tok);
assertEq('schoolId with 3 underscores', 'SCH_TEST_001_FOO', $dec['schoolId']);
assertEq('studentId clean',             'STU0042',          $dec['studentId']);

// ─── 4. Tampered signature → null ─────────────────────────────────────
echo "\n[4] Forgery / tamper detection\n";
$tok = qr_token_encode('SCH_AB', 'STU0001');
// Change the last hex char of the signature.
$rawPayload = base64_decode(strtr($tok, '-_', '+/') . str_repeat('=', (4 - strlen($tok) % 4) % 4));
$rawTampered = substr($rawPayload, 0, -1) . (substr($rawPayload, -1) === 'a' ? 'b' : 'a');
$tampered = rtrim(strtr(base64_encode($rawTampered), '+/', '-_'), '=');
assertNull('signature flip rejected', qr_token_decode($tampered));

// Forged 3-part token (someone crafts schoolId|studentId|fakesig)
$forged = base64_encode('SCH_AB|STU0001|deadbeef00000000');
$forged = rtrim(strtr($forged, '+/', '-_'), '=');
assertNull('arbitrary 3-part forgery rejected', qr_token_decode($forged));

// Wrong-secret signature — same payload, different secret
global $__cfg;
$origSecret = $__cfg['qr_token_secret'];
$__cfg['qr_token_secret'] = 'wrong-secret-B';
$forSig = qr_token_encode('SCH_AB', 'STU0001');
$__cfg['qr_token_secret'] = $origSecret;
assertNull('token signed by other secret rejected', qr_token_decode($forSig));

// ─── 5. Legacy 2-part tokens still accepted (with flag) ───────────────
echo "\n[5] Legacy 2-part tokens accepted with legacy=true\n";
$legacy = rtrim(strtr(base64_encode('SCH_AB|STU0001'), '+/', '-_'), '=');
$dec = qr_token_decode($legacy);
assertArr('legacy decoded', $dec);
assertEq('legacy schoolId',  'SCH_AB',  $dec['schoolId']);
assertEq('legacy studentId', 'STU0001', $dec['studentId']);
assertTrue('legacy flag is true', $dec['legacy']);

// ─── 6. Junk inputs ───────────────────────────────────────────────────
echo "\n[6] Junk / malformed input\n";
assertNull('empty string', qr_token_decode(''));
assertNull('whitespace',   qr_token_decode('   '));
assertNull('bad chars',    qr_token_decode('not@valid#token'));
assertNull('300-char token rejected', qr_token_decode(str_repeat('A', 300)));
$onePart = rtrim(strtr(base64_encode('SCH_AB'), '+/', '-_'), '=');
assertNull('one-part payload', qr_token_decode($onePart));
$fourPart = rtrim(strtr(base64_encode('SCH_AB|STU|sig|extra'), '+/', '-_'), '=');
assertNull('four-part payload', qr_token_decode($fourPart));

// ─── 7. Charset enforcement on decoded ids ────────────────────────────
echo "\n[7] Decoded ids must match whitelisted charset\n";
$bad = rtrim(strtr(base64_encode('SCH_OK|STU 001|abcdef0123456789'), '+/', '-_'), '='); // space in studentId
assertNull('space in studentId rejected', qr_token_decode($bad));
$bad2 = rtrim(strtr(base64_encode('SCH OK|STU0001|abcdef0123456789'), '+/', '-_'), '='); // space in schoolId
assertNull('space in schoolId rejected', qr_token_decode($bad2));

// ─── 8. Different (schoolId, studentId) pairs produce different sigs ──
echo "\n[8] Signatures are pair-specific\n";
$t1 = qr_token_encode('SCH_AB', 'STU0001');
$t2 = qr_token_encode('SCH_AB', 'STU0002');
$t3 = qr_token_encode('SCH_CD', 'STU0001');
assertNotEq('different student → different token', $t1, $t2);
assertNotEq('different school  → different token', $t1, $t3);

// ─── 9. Local SVG render produces a data URI ──────────────────────────
echo "\n[9] qr_svg_data_uri returns inline data URI\n";
$tok = qr_token_encode('SCH_AB', 'STU0001');
$uri = qr_svg_data_uri($tok);
assertEq('starts with image/svg+xml',  true, strpos($uri, 'data:image/svg+xml;base64,') === 0);
$svgBytes = base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')));
assertEq('contains <svg> tag', true, strpos($svgBytes, '<svg') !== false);
assertEq('contains </svg> close', true, strpos($svgBytes, '</svg>') !== false);

// ─── 10. qr_image_proxy_url shape ─────────────────────────────────────
echo "\n[10] qr_image_proxy_url shape\n";
$url = qr_image_proxy_url('abc-token', 240);
assertEq('routes through sis/qr_image', true, strpos($url, 'sis/qr_image/abc-token') !== false);
assertEq('carries size param',          true, strpos($url, 'size=240') !== false);
$tooBig = qr_image_proxy_url('xy', 9999);
assertEq('size clamped at 512', true, strpos($tooBig, 'size=512') !== false);

// ─── Summary ──────────────────────────────────────────────────────────
echo "\n=== Summary ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) echo "  - {$f}\n";
    exit(1);
}
echo "\nAll tests passed.\n";
exit(0);
