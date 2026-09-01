<?php
/**
 * tenant_cleanup — remove the documents created by ONE controlled test run,
 * by exact path, with three independent ownership proofs per document.
 *
 * ── WHY THIS FILE EXISTS ──────────────────────────────────────────────────
 * The v1 admission manifest claimed rollback was available "through the
 * incident_cleanup rails". It was not: that script is hard-coded to the three
 * fabricated SCH_GATE tenants and the 2026-08-31 window, and its deletable list
 * omits every collection an admission actually writes. Cleanup was assumed.
 * Assumed cleanup is not cleanup, so this exists BEFORE any test data is
 * created rather than after.
 *
 * ── RAILS (encoded here, not left to the operator to remember) ────────────
 *   1. TENANT ALLOW-LIST. One tenant, a constant. There is no flag, argument
 *      or environment variable that widens it.
 *   2. COLLECTION ALLOW-LIST, not a deny-list. A deny-list passes for every
 *      collection nobody thought to ban; an allow-list fails for them.
 *      Evidence collections are absent from it BY CONSTRUCTION.
 *   3. EXACT PATHS ONLY, read from a run manifest. No prefix, wildcard,
 *      collection, tenant-wide or timestamp-only form exists in this file.
 *   4. THREE PROOFS PER DOCUMENT, re-read immediately before its delete:
 *        (a) the collection is on the allow-list
 *        (b) the document is owned by the allowed tenant (id prefix or field)
 *        (c) its createTime falls inside the declared run window
 *      Anything failing any proof is SKIPPED, never deleted.
 *   5. DRY RUN by default. ZX_CONFIRM=DELETE is required to mutate.
 *   6. Deletion is verified by RE-READING the path and proving it absent —
 *      never by trusting the DELETE response.
 *   7. The global student-ID allocator is never read for writing, never
 *      rewound, and its collection is not on the allow-list.
 *
 * ── AUTH IS SEPARATE, AND DELIBERATELY SO ─────────────────────────────────
 * An admission also creates a Firebase Auth account, which no Firestore rail
 * can reach. `--auth <uid>` deletes exactly one account, only after proving its
 * custom claims name the allowed tenant. It is a separate action with its own
 * confirmation because deleting a login credential is a different kind of act
 * from deleting a row.
 *
 * Usage:
 *   php tools/tenant_cleanup.php <manifest-file>                  # dry run
 *   ZX_CONFIRM=DELETE php tools/tenant_cleanup.php <manifest-file>
 *   php tools/tenant_cleanup.php --auth STU0190                   # dry run
 *   ZX_CONFIRM=DELETE php tools/tenant_cleanup.php --auth STU0190
 *
 * The manifest file is the exact-path list recorded by the run itself, one
 * `collection/docId` per line; `#` comments and blank lines are ignored. The
 * run window is read from the manifest's `# window:` header.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

$SA      = __DIR__ . '/../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
$PROJECT = 'graderadmin';

/**
 * Destination. Production by default; a LOOPBACK emulator when
 * FIRESTORE_EMULATOR_HOST names one — the same seam the application's own
 * Firestore client uses, and for the same reason: a delete path that can only
 * ever run against production cannot be proven end-to-end before it is used
 * on production. Non-loopback values are refused outright, so this can never
 * become a remote delete tool. The resolved destination is printed on every
 * run; nothing is inferred silently.
 */
$__emu = preg_replace('#^https?://#i', '', trim((string) getenv('FIRESTORE_EMULATOR_HOST')));
if ($__emu !== '') {
    $__host = strtolower((string) strtok($__emu, ':'));
    if (!in_array($__host, ['127.0.0.1', 'localhost', '::1'], true)) {
        fwrite(STDERR, "REFUSED: FIRESTORE_EMULATOR_HOST='{$__emu}' is not loopback.\n"
            . "This tool deletes. It runs against production or a LOCAL emulator, never a remote host.\n");
        exit(3);
    }
    $ROOT = "http://{$__emu}/v1/projects/{$PROJECT}/databases/(default)/documents";
} else {
    $ROOT = "https://firestore.googleapis.com/v1/projects/{$PROJECT}/databases/(default)/documents";
}

/** Rail 1 — the only tenant this file may ever touch. */
const TENANT = 'SCH_D94FE8F7AD';

/**
 * Rail 2 — the ONLY collections this file may delete from. These are exactly
 * the collections a controlled admission creates and which carry no evidentiary
 * value. Every audit, history, forensics and counter collection is absent on
 * purpose: auditLogs, feeAuditLogs, studentHistory, accountingAudit,
 * accountingForensics, accountingCounters, feeCounters, systemCounters,
 * security_events, staff, schools, schoolConfig and every provisioning
 * collection cannot be named here, so they cannot be deleted here.
 */
const DELETABLE = [
    'students', 'parents', 'sections', 'feeStructures', 'chartOfAccounts',
    'feeDemands', 'feeDefaulters', 'feeReceipts', 'feeReceiptIndex',
    'feeIdempotency', 'feeLocks', 'admissionReceipts', 'accounting',
    'accountingClosingBalances', 'accountingIdempotency',
    'srNoIndex', 'admissionNoIndex', 'indexPhones',
];

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
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPGET => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"]]);
    $b = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($b === false || $c >= 400) return null;
    $j = json_decode((string) $b, true);
    return is_array($j) ? $j : null;
}

/** The ONLY write in this file. Takes a fully-qualified path; no pattern form exists. */
function zx_delete(string $root, string $path, string $token): int
{
    $ch = curl_init("{$root}/{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45,
        CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"]]);
    curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return (int) $c;
}

/** Encode ONLY the document-id segment; ids legitimately contain spaces and slashes-in-name. */
function zx_path(string $path): string
{
    [$col, $id] = array_pad(explode('/', $path, 2), 2, '');
    return rawurlencode($col) . '/' . rawurlencode($id);
}

$confirm = getenv('ZX_CONFIRM') === 'DELETE';

// ── AUTH MODE ─────────────────────────────────────────────────────────────
if (($argv[1] ?? '') === '--auth') {
    $uid = trim((string) ($argv[2] ?? ''));
    if ($uid === '') { fwrite(STDERR, "usage: --auth <uid>\n"); exit(2); }

    require __DIR__ . '/../vendor/autoload.php';
    $auth = (new Kreait\Firebase\Factory)->withServiceAccount($SA)->createAuth();

    try { $user = $auth->getUser($uid); }
    catch (\Throwable $e) { echo "  uid {$uid}: already absent — nothing to do\n"; exit(0); }

    $claims = (array) $user->customClaims;
    $claimTenant = (string) ($claims['school_id'] ?? $claims['schoolId'] ?? '');
    echo "  uid        {$uid}\n";
    echo "  email      " . (string) $user->email . "\n";
    echo "  claims     school_id=" . ($claimTenant === '' ? '(none)' : $claimTenant) . "\n";
    echo "  created    " . $user->metadata->createdAt->format('c') . "\n";

    if ($claimTenant !== TENANT) {
        fwrite(STDERR, "REFUSED: this account's claims name '{$claimTenant}', not " . TENANT . ".\n"
            . "A login credential is only removable here when it provably belongs to the allowed tenant.\n");
        exit(3);
    }
    if (!$confirm) { echo "\nDRY RUN — would delete this Auth account. Set ZX_CONFIRM=DELETE to execute.\n"; exit(0); }

    $auth->deleteUser($uid);
    try { $auth->getUser($uid); fwrite(STDERR, "FAILED: account still present after delete\n"); exit(1); }
    catch (\Throwable $e) { echo "\nDELETED and verified absent: {$uid}\n"; exit(0); }
}

// ── FIRESTORE MODE ────────────────────────────────────────────────────────
$manifestFile = $argv[1] ?? '';
if ($manifestFile === '' || !is_file($manifestFile)) {
    fwrite(STDERR, "usage: php tools/tenant_cleanup.php <manifest-file> | --auth <uid>\n");
    exit(2);
}

$lines = file($manifestFile, FILE_IGNORE_NEW_LINES);
$paths = []; $from = ''; $to = '';
foreach ($lines as $l) {
    $l = trim($l);
    if ($l === '') continue;
    if ($l[0] === '#') {
        if (preg_match('/^#\s*window:\s*(\S+)\s*\.\.\s*(\S+)/', $l, $m)) { $from = $m[1]; $to = $m[2]; }
        continue;
    }
    if (strpos($l, '/') !== false) $paths[] = $l;
}
if (!$paths) { fwrite(STDERR, "manifest contains no paths — refusing\n"); exit(3); }
if ($from === '' || $to === '') {
    fwrite(STDERR, "manifest has no '# window: <fromUTC> .. <toUTC>' header.\n"
        . "Without a run window a document's origin cannot be proven, so nothing may be deleted.\n");
    exit(3);
}

$TOKEN = zx_token($SA);

echo "══ TENANT CLEANUP " . ($confirm ? '(LIVE)' : '(DRY RUN — set ZX_CONFIRM=DELETE to execute)') . " ══\n";
echo "dest:     {$ROOT}\n";
echo "tenant:   " . TENANT . "\n";
echo "window:   {$from} .. {$to}  (UTC)\n";
echo "manifest: {$manifestFile}  (" . count($paths) . " paths)\n\n";

$done = []; $skipped = []; $failed = [];

foreach ($paths as $path) {
    [$col, $id] = array_pad(explode('/', $path, 2), 2, '');

    // Proof (a) — allow-listed collection.
    if (!in_array($col, DELETABLE, true)) {
        $skipped[] = "{$path}\n      REFUSED: '{$col}' is not on the deletable allow-list";
        continue;
    }

    // Re-read immediately before deciding. State at manifest time is not state now.
    $doc = zx_get("{$ROOT}/" . zx_path($path), $TOKEN);
    if ($doc === null) { $skipped[] = "{$path}\n      already absent"; continue; }

    // Proof (b) — tenant ownership, by id prefix or by a field carrying the tenant.
    $blob = json_encode($doc['fields'] ?? []);
    if (strpos($id, TENANT) !== 0 && strpos((string) $blob, TENANT) === false) {
        $skipped[] = "{$path}\n      REFUSED: does not prove ownership by " . TENANT;
        continue;
    }

    // Proof (c) — created inside the declared run window.
    $created = (string) ($doc['createTime'] ?? '');
    if ($created === '' || $created < $from || $created > $to) {
        $skipped[] = "{$path}\n      REFUSED: createTime '{$created}' outside the run window";
        continue;
    }

    if (!$confirm) { $done[] = "{$path}  (would delete, created {$created})"; continue; }

    $code = zx_delete($ROOT, zx_path($path), $TOKEN);
    if ($code >= 400) { $failed[] = "{$path}  HTTP {$code}"; continue; }

    // Verify from persisted state, never from the delete response.
    if (zx_get("{$ROOT}/" . zx_path($path), $TOKEN) !== null) {
        $failed[] = "{$path}  STILL PRESENT after delete";
        continue;
    }
    $done[] = $path;
}

echo ($confirm ? 'DELETED' : 'WOULD DELETE') . ' (' . count($done) . "):\n";
foreach ($done as $d) echo "  {$d}\n";
if ($skipped) { echo "\nSKIPPED (" . count($skipped) . "):\n"; foreach ($skipped as $s) echo "  {$s}\n"; }
if ($failed)  { echo "\nFAILED (" . count($failed) . "):\n";  foreach ($failed as $f) echo "  {$f}\n"; }
echo "\n" . ($confirm ? 'LIVE RUN COMPLETE' : 'DRY RUN — nothing was modified') . "\n";
exit($failed ? 1 : 0);
