<?php
/**
 * tenant_census — READ-ONLY, exhaustive, single-tenant census.
 *
 * Answers one question with evidence: *exactly* which production documents
 * belong to one tenant, right now, across EVERY collection — not across a
 * hand-written list that can silently omit the collection that matters. The
 * v1 admission manifest listed 12 write targets; the real chain writes 20,
 * and the difference was only visible by enumerating collections rather than
 * assuming them.
 *
 * Issues GET and `:listCollectionIds` only. There is no write, update, merge
 * or delete path in this file, and no code path that takes a mutation verb.
 *
 * A document belongs to the tenant if its id is prefixed with the tenant id OR
 * any of its fields carries the tenant id. Both are needed: most collections
 * use `{schoolId}_…` ids, but `feeDemands` uses random ids and carries the
 * tenant only in a field.
 *
 * Usage:
 *   php tools/tenant_census.php SCH_XXXXXXXX             # summary
 *   php tools/tenant_census.php SCH_XXXXXXXX --paths     # one exact path per line
 *   php tools/tenant_census.php SCH_XXXXXXXX --detail    # + createTime and key fields
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

$SA      = __DIR__ . '/../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
$PROJECT = 'graderadmin';
$ROOT    = "https://firestore.googleapis.com/v1/projects/{$PROJECT}/databases/(default)/documents";

/** Global/shared documents are reported but never attributed to the tenant. */
const GLOBAL_DOCS = ['feeCounters/_sys_STU'];

function zx_token(string $sa): string
{
    $j = json_decode((string) file_get_contents($sa), true);
    if (!is_array($j)) { fwrite(STDERR, "service account unreadable\n"); exit(2); }
    $now = time();
    $b64 = fn($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode([
        'iss' => $j['client_email'], 'scope' => 'https://www.googleapis.com/auth/datastore',
        'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
    $sig = ''; openssl_sign($unsigned, $sig, $j['private_key'], 'sha256WithRSAEncryption');
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $unsigned . '.' . $b64($sig)])]);
    $r = json_decode((string) curl_exec($ch), true); curl_close($ch);
    if (empty($r['access_token'])) { fwrite(STDERR, "token failed\n"); exit(2); }
    return (string) $r['access_token'];
}

function zx_get(string $url, string $token): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPGET => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"]]);
    $b = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($b === false || $c >= 400) return null;
    $j = json_decode((string) $b, true);
    return is_array($j) ? $j : null;
}

/** Enumerate collections instead of trusting a hand-written list. */
function zx_collections(string $root, string $token): array
{
    $ch = curl_init("{$root}:listCollectionIds");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{"pageSize":300}',
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}", 'Content-Type: application/json']]);
    $b = curl_exec($ch); curl_close($ch);
    $j = json_decode((string) $b, true);
    $ids = $j['collectionIds'] ?? [];
    sort($ids);
    return $ids;
}

function zx_all(string $root, string $col, string $token): array
{
    $docs = []; $tok = '';
    do {
        $j = zx_get("{$root}/" . rawurlencode($col) . '?pageSize=300'
            . ($tok !== '' ? '&pageToken=' . urlencode($tok) : ''), $token);
        if ($j === null) break;
        foreach ($j['documents'] ?? [] as $d) $docs[] = $d;
        $tok = (string) ($j['nextPageToken'] ?? '');
    } while ($tok !== '');
    return $docs;
}

function zx_id(array $d): string
{
    $n = (string) ($d['name'] ?? '');
    return substr($n, strrpos($n, '/') + 1);
}

$TOKEN  = zx_token($SA);
$tenant = $argv[1] ?? '';
if ($tenant === '' || !preg_match('/^SCH_[A-Z0-9]+$/', $tenant)) {
    fwrite(STDERR, "usage: php tools/tenant_census.php SCH_XXXXXXXX [--paths|--detail]\n");
    exit(2);
}
$pathsOnly = in_array('--paths', $argv, true);
$detail    = in_array('--detail', $argv, true);

$cols  = zx_collections($ROOT, $TOKEN);
$owned = [];   // path => [createTime, why, summary]
$globals = [];

foreach ($cols as $col) {
    foreach (zx_all($ROOT, $col, $TOKEN) as $d) {
        $id   = zx_id($d);
        $path = "{$col}/{$id}";
        $blob = json_encode($d['fields'] ?? []);

        $byId    = strpos($id, $tenant) === 0;
        $byField = strpos((string) $blob, $tenant) !== false;
        if (!$byId && !$byField) continue;

        if (in_array($path, GLOBAL_DOCS, true)) { $globals[$path] = $d['createTime'] ?? ''; continue; }

        $bits = [];
        foreach (['studentId', 'session', 'className', 'name', 'status', 'amount', 'receiptNo'] as $k) {
            $v = $d['fields'][$k] ?? null;
            if (!is_array($v)) continue;
            $s = $v['stringValue'] ?? $v['integerValue'] ?? $v['doubleValue'] ?? null;
            if ($s !== null && $s !== '') $bits[] = "{$k}=" . substr((string) $s, 0, 24);
        }
        $owned[$path] = [
            'created' => (string) ($d['createTime'] ?? ''),
            'why'     => $byId ? 'id-prefixed' : 'schoolId field',
            'summary' => implode('  ', array_slice($bits, 0, 4)),
        ];
    }
}

ksort($owned);

if ($pathsOnly) { foreach (array_keys($owned) as $p) echo $p . "\n"; exit(0); }

echo "══ TENANT CENSUS — {$tenant} (READ-ONLY, production) ══\n";
echo "collections enumerated: " . count($cols) . "\n";
echo "documents owned by this tenant: " . count($owned) . "\n\n";

$byCol = [];
foreach ($owned as $p => $r) { $byCol[substr($p, 0, strpos($p, '/'))][] = $p; }
ksort($byCol);
foreach ($byCol as $c => $ps) {
    printf("%-28s %d\n", $c, count($ps));
    if (!$detail) continue;
    foreach ($ps as $p) {
        printf("    %-52s %s  %s\n", substr($p, 0, 52),
            substr($owned[$p]['created'], 0, 19), $owned[$p]['summary']);
    }
}
if (!$owned) echo "  (none — the tenant holds no documents in any collection)\n";

echo "\n── GLOBAL / SHARED (reported, never tenant-owned, never deletable) ──\n";
foreach (GLOBAL_DOCS as $g) {
    $d = zx_get("{$ROOT}/" . $g, $TOKEN);
    $val = $d['fields']['value'] ?? null;
    echo "  {$g} = " . ($val === null ? '(absent)' : json_encode($val)) . "\n";
}
