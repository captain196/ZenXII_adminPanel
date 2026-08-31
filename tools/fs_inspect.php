<?php
/**
 * fs_inspect — read a PERSISTED Firestore document (or collection) from the
 * local emulator and print its TOP-LEVEL fields, decoded.
 *
 * ── WHY THIS EXISTS ───────────────────────────────────────────────────────
 * Verifying persisted state with `grep -A2 '"valid_until"'` gave a WRONG
 * answer: Firestore's REST shape nests documents, and `studentDiscounts`
 * carries an `applied` history array whose entries have the same field names
 * as the top level. The grep matched a nested, stale copy and reported
 * `valid_until = ""` while the real top-level value was correct.
 *
 * That is the same class of mistake as trusting an endpoint's success
 * response: the measurement looked like evidence and was not. A money check is
 * only as trustworthy as the tool that reads it, so persisted-state
 * verification goes through a real JSON decode from here on.
 *
 * Usage (CLI only):
 *   php tools/fs_inspect.php doc  <collection> <docId> [field ...]
 *   php tools/fs_inspect.php list <collection> [limit]
 *   php tools/fs_inspect.php find <collection> <field> <value> [field ...]
 *
 * Reads only. Refuses to run against anything but a loopback emulator, for the
 * same reason Lifecycle_probe does.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

$emu = trim((string) getenv('FIRESTORE_EMULATOR_HOST'));
$emu = preg_replace('#^https?://#i', '', $emu);
$host = strtolower((string) strtok((string) $emu, ':'));
if ($emu === '' || !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "REFUSING: FIRESTORE_EMULATOR_HOST must point at a loopback emulator.\n");
    exit(3);
}
$project = getenv('ZX_PROJECT') ?: 'graderadmin';
$base    = "http://{$emu}/v1/projects/{$project}/databases/(default)/documents";

/** Decode one Firestore REST value into a plain PHP value. */
function zx_val(array $v)
{
    foreach (['stringValue', 'booleanValue', 'integerValue', 'doubleValue', 'timestampValue'] as $k) {
        if (array_key_exists($k, $v)) {
            return $k === 'doubleValue' ? (float) $v[$k]
                 : ($k === 'integerValue' ? (int) $v[$k] : $v[$k]);
        }
    }
    if (array_key_exists('nullValue', $v)) return null;
    if (isset($v['arrayValue'])) {
        $out = [];
        foreach ($v['arrayValue']['values'] ?? [] as $e) $out[] = zx_val($e);
        return $out;
    }
    if (isset($v['mapValue'])) {
        $out = [];
        foreach ($v['mapValue']['fields'] ?? [] as $k => $e) $out[$k] = zx_val($e);
        return $out;
    }
    return $v;
}

function zx_fields(array $doc): array
{
    $out = [];
    foreach ($doc['fields'] ?? [] as $k => $v) $out[$k] = zx_val($v);
    return $out;
}

function zx_get(string $url): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code >= 400) return null;
    $j = json_decode((string) $body, true);
    return is_array($j) ? $j : null;
}

/** Walk every page so a census is never silently truncated. */
function zx_all(string $base, string $col): array
{
    $docs = []; $token = '';
    do {
        $u = "{$base}/{$col}?pageSize=300" . ($token !== '' ? '&pageToken=' . urlencode($token) : '');
        $j = zx_get($u);
        if ($j === null) break;
        foreach ($j['documents'] ?? [] as $d) $docs[] = $d;
        $token = (string) ($j['nextPageToken'] ?? '');
    } while ($token !== '');
    return $docs;
}

function zx_show(array $fields, array $want): void
{
    $keys = $want ?: array_keys($fields);
    foreach ($keys as $k) {
        $v = $fields[$k] ?? '<ABSENT>';
        if (is_array($v)) $v = 'array(' . count($v) . ')';
        printf("  %-24s %s\n", $k, var_export($v, true));
    }
}

$mode = $argv[1] ?? '';
if ($mode === 'doc') {
    [$col, $id] = [$argv[2] ?? '', $argv[3] ?? ''];
    $want = array_slice($argv, 4);
    $j = zx_get("{$base}/{$col}/{$id}");
    if ($j === null) { echo "  <NOT FOUND> {$col}/{$id}\n"; exit(0); }
    echo "{$col}/{$id}\n";
    zx_show(zx_fields($j), $want);
    exit(0);
}
if ($mode === 'list') {
    $col = $argv[2] ?? ''; $limit = (int) ($argv[3] ?? 20);
    $docs = zx_all($base, $col);
    echo "{$col}: " . count($docs) . " document(s)\n";
    foreach (array_slice($docs, 0, $limit) as $d) {
        echo "  " . substr((string) ($d['name'] ?? ''), strrpos((string) $d['name'], '/') + 1) . "\n";
    }
    exit(0);
}
if ($mode === 'find') {
    [$col, $f, $val] = [$argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? ''];
    $want = array_slice($argv, 5);
    $n = 0;
    foreach (zx_all($base, $col) as $d) {
        $fields = zx_fields($d);
        if ((string) ($fields[$f] ?? '') !== $val) continue;
        $n++;
        echo substr((string) $d['name'], strrpos((string) $d['name'], '/') + 1) . "\n";
        zx_show($fields, $want);
    }
    echo "  ({$n} match" . ($n === 1 ? '' : 'es') . ")\n";
    exit(0);
}

fwrite(STDERR, "usage: doc <col> <id> [fields...] | list <col> [limit] | find <col> <field> <value> [fields...]\n");
exit(1);
