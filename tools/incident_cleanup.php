<?php
/**
 * incident_cleanup — delete ONLY the exact documents in the approved manifest
 * for the 2026-08-31 accidental production writes.
 *
 * ── HARD RAILS (encoded, not left to the operator) ────────────────────────
 *   • EXACT PATHS ONLY. No prefix, wildcard, collection or tenant delete
 *     exists in this file. Each target is a fully-qualified document path.
 *   • The manifest is REBUILT from a fresh production read every run; this
 *     script cannot be pointed at an arbitrary list.
 *   • Every target is RE-READ immediately before its delete and must still
 *     prove: fabricated GATE tenant AND createTime inside the incident window.
 *     Anything that fails re-verification is SKIPPED, not deleted.
 *   • auditLogs and feeCounters are refused by name, twice — they are the
 *     evidence trail and the global allocator.
 *   • DRY RUN by default. A real delete requires ZX_CONFIRM=DELETE.
 *   • After deletion each path is re-read and proven ABSENT.
 *
 * Usage:
 *   php tools/incident_cleanup.php                 # dry run
 *   ZX_CONFIRM=DELETE php tools/incident_cleanup.php
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

$SA      = __DIR__ . '/../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
$PROJECT = 'graderadmin';
$ROOT    = "https://firestore.googleapis.com/v1/projects/{$PROJECT}/databases/(default)/documents";

const TENANTS     = ['SCH_GATE1', 'SCH_GATE2', 'SCH_GATE3'];
const DELETABLE   = ['schools', 'sections', 'students', 'feeStructures', 'chartOfAccounts'];
const NEVER_TOUCH = ['auditLogs', 'feeCounters'];
const WINDOW_FROM = '2026-08-31T11:45:00';
const WINDOW_TO   = '2026-08-31T15:15:00';

function zx_token(string $sa): string
{
    $j = json_decode((string) file_get_contents($sa), true);
    if (!is_array($j)) { fwrite(STDERR, "service account unreadable\n"); exit(2); }
    $now = time();
    $b64 = fn($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    $unsigned = $b64(json_encode(['alg'=>'RS256','typ'=>'JWT'])) . '.' . $b64(json_encode([
        'iss'=>$j['client_email'],'scope'=>'https://www.googleapis.com/auth/datastore',
        'aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+3600]));
    $sig=''; openssl_sign($unsigned,$sig,$j['private_key'],'sha256WithRSAEncryption');
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>30,
        CURLOPT_POSTFIELDS=>http_build_query([
            'grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'=>$unsigned.'.'.$b64($sig)])]);
    $r = json_decode((string) curl_exec($ch), true); curl_close($ch);
    if (empty($r['access_token'])) { fwrite(STDERR,"token failed\n"); exit(2); }
    return (string) $r['access_token'];
}

function zx_get(string $url, string $token): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>45,
        CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>["Authorization: Bearer {$token}"]]);
    $b=curl_exec($ch); $c=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if ($b===false || $c>=400) return null;
    $j=json_decode((string)$b,true); return is_array($j)?$j:null;
}

/** The ONLY write in this file. Takes a fully-qualified path; no pattern form exists. */
function zx_delete(string $root, string $path, string $token): int
{
    $ch = curl_init("{$root}/{$path}");
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>45,
        CURLOPT_CUSTOMREQUEST=>'DELETE',CURLOPT_HTTPHEADER=>["Authorization: Bearer {$token}"]]);
    curl_exec($ch); $c=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    return (int) $c;
}

/** Encode ONLY the document-id segment; document ids legitimately contain spaces. */
function zx_path(string $path): string
{
    [$col, $id] = array_pad(explode('/', $path, 2), 2, '');
    return $col . '/' . rawurlencode($id);
}

function zx_val(array $v) {
    foreach (['stringValue','integerValue','doubleValue','booleanValue','timestampValue'] as $k)
        if (array_key_exists($k,$v)) return $v[$k];
    return null;
}

$TOKEN = zx_token($SA);

// ── rebuild the manifest from a fresh read ────────────────────────────────
$paths = [];
$out = [];
exec('php ' . escapeshellarg(__DIR__ . '/incident_manifest.php') . ' --paths', $out, $rc);
foreach ($out as $line) { $line = trim($line); if ($line !== '' && strpos($line,'/') !== false) $paths[] = $line; }
if (!$paths) { fwrite(STDERR, "manifest empty — refusing to proceed\n"); exit(3); }

$confirm = getenv('ZX_CONFIRM') === 'DELETE';
echo "══ INCIDENT CLEANUP " . ($confirm ? '(LIVE)' : '(DRY RUN — set ZX_CONFIRM=DELETE to execute)') . " ══\n";
echo "manifest targets: " . count($paths) . "\n\n";

$deleted = []; $skipped = []; $failed = [];

foreach ($paths as $path) {
    [$col, $id] = array_pad(explode('/', $path, 2), 2, '');

    // Rail 1 — collection must be deletable, and never an excluded one.
    if (in_array($col, NEVER_TOUCH, true) || !in_array($col, DELETABLE, true)) {
        $skipped[] = "{$path}  REFUSED: collection '{$col}' is not deletable"; continue;
    }
    // Rail 2 — path must name a fabricated tenant.
    $hasTenant = false; foreach (TENANTS as $t) if (strpos($path, $t) !== false) $hasTenant = true;
    if (!$hasTenant) { $skipped[] = "{$path}  REFUSED: no fabricated tenant in path"; continue; }

    // Rail 3 — RE-READ and re-prove origin immediately before deleting.
    $doc = zx_get("{$ROOT}/" . zx_path($path), $TOKEN);
    if ($doc === null) { $skipped[] = "{$path}  already absent"; continue; }
    $created = (string) ($doc['createTime'] ?? '');
    if ($created === '' || $created < WINDOW_FROM || $created > WINDOW_TO) {
        $skipped[] = "{$path}  REFUSED: createTime '{$created}' outside incident window"; continue;
    }

    if (!$confirm) { $deleted[] = "{$path}  (would delete, created {$created})"; continue; }

    $code = zx_delete($ROOT, zx_path($path), $TOKEN);
    if ($code >= 400) { $failed[] = "{$path}  HTTP {$code}"; continue; }

    // Rail 4 — prove absence from persisted state, not from the delete response.
    $after = zx_get("{$ROOT}/" . zx_path($path), $TOKEN);
    if ($after !== null) { $failed[] = "{$path}  STILL PRESENT after delete"; continue; }
    $deleted[] = $path;
}

echo "DELETED (" . count($deleted) . "):\n";
foreach ($deleted as $d) echo "  {$d}\n";
if ($skipped) { echo "\nSKIPPED (" . count($skipped) . "):\n"; foreach ($skipped as $s) echo "  {$s}\n"; }
if ($failed)  { echo "\nFAILED (" . count($failed) . "):\n";  foreach ($failed as $f) echo "  {$f}\n"; }
echo "\n" . ($confirm ? 'LIVE RUN COMPLETE' : 'DRY RUN — nothing was modified') . "\n";
exit($failed ? 1 : 0);
