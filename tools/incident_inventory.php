<?php
/**
 * incident_inventory — READ-ONLY forensic inventory of the accidental
 * production writes (2026-08-31).
 *
 * ── WHAT HAPPENED ─────────────────────────────────────────────────────────
 * `Firestore_rest_client`'s emulator seam (`emulatorHost()` / `apiRoot()`) was
 * present only in the working tree, never committed. A clean checkout of the
 * committed state therefore had no way to honour `FIRESTORE_EMULATOR_HOST` and
 * fell through to its default, `https://firestore.googleapis.com`.
 *
 * `Lifecycle_probe::_assertEmulator()` checked only that the ENVIRONMENT
 * VARIABLE was set and loopback-shaped. It never verified that the client
 * actually resolved to it, so the harness reported itself sandboxed while
 * writing to the live project.
 *
 * ── WHAT THIS SCRIPT MAY DO ───────────────────────────────────────────────
 * READ ONLY. It calls the REST GET/list endpoints directly with a read scope
 * and never constructs a write. No document is created, updated, merged or
 * deleted. It does not touch counters. It prints an inventory and exits.
 *
 * Deliberately talks HTTP itself rather than through Firestore_rest_client:
 * that class exposes setDocument/deleteDocument/batchWrite, and a forensic
 * tool run against production should not have those within reach at all.
 *
 * Usage:  php tools/incident_inventory.php
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

$SA      = __DIR__ . '/../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
$PROJECT = 'graderadmin';
$DB      = '(default)';
$ROOT    = "https://firestore.googleapis.com/v1/projects/{$PROJECT}/databases/{$DB}/documents";

/** Suspected accidental identifiers. NOTHING outside this list is reported as a candidate. */
const SCHOOLS  = ['SCH_GATE1', 'SCH_GATE2', 'SCH_GATE3'];
const STUDENTS = ['STU0187', 'STU0188', 'STU0189'];

/** The incident window — the clean-checkout runs. Anything outside is suspect as pre-existing. */
// UTC — Firestore createTime is UTC; the runs were IST (UTC+5:30).
const WINDOW_FROM = '2026-08-31T11:45:00';
const WINDOW_TO   = '2026-08-31T15:15:00';

// ── read-only OAuth token (cloud-platform scope is required by the REST API;
//    the token is used for GET only, never for a write call in this file) ────
function zx_token(string $sa): string
{
    $j = json_decode((string) file_get_contents($sa), true);
    if (!is_array($j)) { fwrite(STDERR, "service account unreadable\n"); exit(2); }
    $now = time();
    $claim = [
        'iss'   => $j['client_email'],
        'scope' => 'https://www.googleapis.com/auth/datastore',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ];
    $b64 = fn($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode($claim));
    $sig = '';
    openssl_sign($unsigned, $sig, $j['private_key'], 'sha256WithRSAEncryption');
    $jwt = $unsigned . '.' . $b64($sig);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt,
        ]),
    ]);
    $res = json_decode((string) curl_exec($ch), true);
    curl_close($ch);
    if (empty($res['access_token'])) { fwrite(STDERR, "token failed: " . json_encode($res) . "\n"); exit(2); }
    return (string) $res['access_token'];
}

$TOKEN = zx_token($SA);

/** GET only. Any non-GET would be a bug; this helper cannot issue one. */
function zx_get(string $url, string $token): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code >= 400) return null;
    $j = json_decode((string) $body, true);
    return is_array($j) ? $j : null;
}

function zx_val(array $v) {
    foreach (['stringValue','booleanValue','integerValue','doubleValue','timestampValue'] as $k) {
        if (array_key_exists($k, $v)) {
            return $k === 'doubleValue' ? (float) $v[$k] : ($k === 'integerValue' ? (int) $v[$k] : $v[$k]);
        }
    }
    if (array_key_exists('nullValue', $v)) return null;
    if (isset($v['arrayValue'])) { $o=[]; foreach ($v['arrayValue']['values'] ?? [] as $e) $o[]=zx_val($e); return $o; }
    if (isset($v['mapValue']))   { $o=[]; foreach ($v['mapValue']['fields'] ?? [] as $k=>$e) $o[$k]=zx_val($e); return $o; }
    return null;
}

function zx_fields(array $d): array
{
    $o = [];
    foreach ($d['fields'] ?? [] as $k => $v) $o[$k] = zx_val($v);
    return $o;
}

/** Walk every page — a truncated census would understate the footprint. */
function zx_all(string $root, string $col, string $token): array
{
    $docs = []; $tok = '';
    do {
        $u = "{$root}/{$col}?pageSize=300" . ($tok !== '' ? '&pageToken=' . urlencode($tok) : '');
        $j = zx_get($u, $token);
        if ($j === null) break;
        foreach ($j['documents'] ?? [] as $d) $docs[] = $d;
        $tok = (string) ($j['nextPageToken'] ?? '');
    } while ($tok !== '');
    return $docs;
}

/** Collections the day-one flow could plausibly have written, plus identity/global stores. */
$COLLECTIONS = [
    'schools', 'sections', 'classes', 'students', 'studentIndex',
    'feeHeads', 'feeStructures', 'feeDemands', 'feeReceipts', 'feeRefunds',
    'feeRefundVouchers', 'studentDiscounts', 'studentEntitlements',
    'studentConcessions', 'studentServiceEnrollments', 'scholarshipAwards',
    'feeDiscountPolicies', 'feeSettings', 'feeDefaulters', 'studentFeeSummary',
    'accounts', 'chartOfAccounts', 'accounting', 'accountingLedger',
    'accountingCounters', 'feeAuditLogs', 'auditLogs', 'counters', 'schoolControl',
    'srNoIndex', 'admissionNumbers', 'studentRelationships', 'transportRequests',
    'routes', 'transportStops', 'feeGenerationJobs', 'feeJobs',
];

$hits = []; $reachable = 0; $unreadable = [];

foreach ($COLLECTIONS as $col) {
    $docs = zx_all($ROOT, $col, $TOKEN);
    if ($docs === []) { $unreadable[] = $col; continue; }
    $reachable++;
    foreach ($docs as $d) {
        $name = (string) ($d['name'] ?? '');
        $id   = substr($name, strrpos($name, '/') + 1);
        $f    = zx_fields($d);

        $blob = $id . ' ' . json_encode($f);
        $matchSchool = null; $matchStudent = null;
        foreach (SCHOOLS  as $s) if (strpos($blob, $s) !== false) $matchSchool  = $s;
        foreach (STUDENTS as $s) if (strpos($blob, $s) !== false) $matchStudent = $s;
        if ($matchSchool === null && $matchStudent === null) continue;

        $created = (string) ($d['createTime'] ?? '');
        $updated = (string) ($d['updateTime'] ?? '');
        $inWindow = ($created !== '' && $created >= WINDOW_FROM && $created <= WINDOW_TO);

        $hits[] = [
            'collection' => $col,
            'docId'      => $id,
            'school'     => (string) ($f['schoolId'] ?? $f['school_id'] ?? $matchSchool ?? ''),
            'student'    => (string) ($f['studentId'] ?? $f['student_id'] ?? $matchStudent ?? ''),
            'created'    => $created,
            'updated'    => $updated,
            'inWindow'   => $inWindow,
            'matchedS'   => $matchSchool,
            'matchedT'   => $matchStudent,
        ];
    }
}

echo "══ PRODUCTION INCIDENT INVENTORY (READ-ONLY) ══\n";
echo "project={$PROJECT} db={$DB}\n";
echo "collections probed: " . count($COLLECTIONS) . "   with data: {$reachable}\n";
echo "incident window: " . WINDOW_FROM . " .. " . WINDOW_TO . "\n\n";

if (!$hits) { echo "NO MATCHING DOCUMENTS FOUND for the suspected identifiers.\n"; exit(0); }

if (getenv('ZX_COUNTER')) {
  foreach (['feeCounters'] as $c) {
    foreach (zx_all($ROOT,$c,$TOKEN) as $d) {
      $nm=(string)($d['name']??''); $id=substr($nm,strrpos($nm,'/')+1);
      if (stripos($id,'STU')===false) continue;
      if (getenv('ZX_PTR') && strpos($id,'_claim_')!==false) continue;
      if (getenv('ZX_PTR')===false && strpos($id,'_claim_')!==false && (int)substr($id,strrpos($id,'_')+1) < 180) continue;
      printf("   %s/%s = %s  updated=%s
", $c, $id, json_encode(zx_fields($d)), substr((string)($d['updateTime']??''),0,19));
    }
  }
  exit(0);
}
if (getenv('ZX_COLLIDE')) {
  // Read-only collision + counter check.
  echo "
-- do STU0186..STU0189 exist under any OTHER tenant? --
";
  foreach (['STU0186','STU0187','STU0188','STU0189'] as $sid) {
    $found=[];
    foreach (zx_all($ROOT,'students',$TOKEN) as $d) {
      $nm=(string)($d['name']??''); $id=substr($nm,strrpos($nm,'/')+1);
      $fl=zx_fields($d);
      if (((string)($fl['studentId']??''))===$sid) $found[]=$id.' ('.substr((string)($d['createTime']??''),0,19).')';
    }
    printf("   %-9s %s
", $sid, $found ? implode(', ',$found) : '<none>');
  }
  echo "
-- do SCH_GATE1/2/3 exist in schools? --
";
  foreach (['SCH_GATE1','SCH_GATE2','SCH_GATE3'] as $sc) {
    $d = zx_get("{$ROOT}/schools/{$sc}", $TOKEN);
    printf("   %-11s %s
", $sc, $d===null ? '<absent>' : 'EXISTS created='.substr((string)($d['createTime']??''),0,19));
  }
  echo "
-- global student counter (READ ONLY, not modified) --
";
  foreach (['counters/STUDENT_ID','counters/student','counters/students','counters/STU'] as $p) {
    $d = zx_get("{$ROOT}/{$p}", $TOKEN);
    if ($d!==null) { printf("   %-22s %s
", $p, json_encode(zx_fields($d))); }
  }
  foreach (zx_all($ROOT,'counters',$TOKEN) as $d) {
    $nm=(string)($d['name']??''); $id=substr($nm,strrpos($nm,'/')+1);
    printf("   counters/%-22s %s  updated=%s
", $id, json_encode(zx_fields($d)), substr((string)($d['updateTime']??''),0,19));
  }
  exit(0);
}
if (getenv('ZX_MANIFEST')) {
  usort($hits, fn($x,$y)=>[$x['collection'],$x['docId']]<=>[$y['collection'],$y['docId']]);
  foreach ($hits as $h) printf("%s/%s|%s|%s|%s
", $h['collection'], $h['docId'], $h['school'], $h['student'], substr($h['created'],0,23));
  exit(0);
}
$byCol=[]; $oldest=null; $newest=null; $outside=[];
foreach($hits as $h){
  $byCol[$h['collection']][$h['school']?:$h['matchedS']][]=$h;
  if($h['created']!==''){ if($oldest===null||$h['created']<$oldest)$oldest=$h['created']; if($newest===null||$h['created']>$newest)$newest=$h['created']; }
  if(!$h['inWindow']) $outside[]=$h;
}
printf("%-26s %-12s %6s  %-24s %s
",'COLLECTION','TENANT','DOCS','EARLIEST CREATE (UTC)','LATEST');
foreach($byCol as $col=>$bySch){ foreach($bySch as $sch=>$rows){
  $mn=null;$mx=null; foreach($rows as $r){ if($r['created']==='')continue; if($mn===null||$r['created']<$mn)$mn=$r['created']; if($mx===null||$r['created']>$mx)$mx=$r['created']; }
  printf("%-26s %-12s %6d  %-24s %s
",$col,$sch?:'-',count($rows),substr((string)$mn,0,23),substr((string)$mx,0,23));
}}
echo "
total matching documents: ".count($hits)."
";
echo "earliest: ".substr((string)$oldest,0,23)."   latest: ".substr((string)$newest,0,23)."
";
echo "outside incident window: ".count($outside)."
";
foreach(array_slice($outside,0,10) as $o) printf("   REVIEW %s/%s created=%s
",$o['collection'],$o['docId'],substr($o['created'],0,23));
