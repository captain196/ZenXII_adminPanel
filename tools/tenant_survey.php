<?php
/**
 * tenant_survey — READ-ONLY production survey for the controlled-admission
 * pre-write manifest.
 *
 * Establishes, without writing anything:
 *   • every tenant that exists, and which are operational vs dormant
 *   • current session, classes/sections, fee heads, fee chart
 *   • chart-of-accounts / accounting configuration
 *   • student-ID allocator state
 *   • entitlement and benefit-policy configuration
 *   • existing financial records that a new admission could touch
 *
 * GET only. There is no write path in this file — not even a token-scoped one
 * beyond the OAuth exchange required to read. It cannot create, update, merge
 * or delete.
 *
 * Usage:
 *   php tools/tenant_survey.php                # tenant overview
 *   php tools/tenant_survey.php SCH_XXXXXXXX   # deep survey of one tenant
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

$SA      = __DIR__ . '/../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
$PROJECT = 'graderadmin';
$ROOT    = "https://firestore.googleapis.com/v1/projects/{$PROJECT}/databases/(default)/documents";

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

/** GET only. Encodes the document-id segment — ids legitimately contain spaces. */
function zx_get(string $url, string $token): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>45,
        CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>["Authorization: Bearer {$token}"]]);
    $b=curl_exec($ch); $c=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if ($b===false || $c>=400) return null;
    $j=json_decode((string)$b,true); return is_array($j)?$j:null;
}

function zx_doc(string $root, string $col, string $id, string $token): ?array
{
    return zx_get("{$root}/{$col}/" . rawurlencode($id), $token);
}

function zx_val(array $v) {
    foreach (['stringValue','booleanValue','integerValue','doubleValue','timestampValue'] as $k)
        if (array_key_exists($k,$v)) return $k==='doubleValue' ? (float)$v[$k] : ($k==='integerValue' ? (int)$v[$k] : $v[$k]);
    if (array_key_exists('nullValue',$v)) return null;
    if (isset($v['arrayValue'])) { $o=[]; foreach ($v['arrayValue']['values']??[] as $e) $o[]=zx_val($e); return $o; }
    if (isset($v['mapValue']))   { $o=[]; foreach ($v['mapValue']['fields']??[] as $k=>$e) $o[$k]=zx_val($e); return $o; }
    return null;
}

function zx_fields(array $d): array
{
    $o=[]; foreach ($d['fields']??[] as $k=>$v) $o[$k]=zx_val($v); return $o;
}

function zx_all(string $root, string $col, string $token): array
{
    $docs=[]; $tok='';
    do {
        $j = zx_get("{$root}/{$col}?pageSize=300" . ($tok!=='' ? '&pageToken='.urlencode($tok) : ''), $token);
        if ($j===null) break;
        foreach ($j['documents']??[] as $d) $docs[]=$d;
        $tok=(string)($j['nextPageToken']??'');
    } while ($tok!=='');
    return $docs;
}

function zx_id(array $d): string
{
    $n=(string)($d['name']??''); return substr($n, strrpos($n,'/')+1);
}

/** Group any collection by its schoolId field. */
function zx_by_tenant(array $docs): array
{
    $t=[];
    foreach ($docs as $d) { $f=zx_fields($d); $s=(string)($f['schoolId']??$f['school_id']??'?'); $t[$s][]=$d; }
    return $t;
}

$TOKEN  = zx_token($SA);
$target = $argv[1] ?? '';

// ── collections that matter to an admission + fee lifecycle ────────────────
$SETS = ['schools','sections','students','feeStructures','chartOfAccounts','feeHeads',
         'feeDemands','feeReceipts','feeRefunds','studentDiscounts','studentEntitlements',
         'studentConcessions','studentRelationships','feeSettings','accounting',
         'feeDefaulters','schoolControl','auditLogs'];

$cache = [];
foreach ($SETS as $c) $cache[$c] = zx_all($ROOT, $c, $TOKEN);

if ($target === '') {
    echo "══ TENANT OVERVIEW (READ-ONLY) ══\n\n";
    $schools = $cache['schools'];
    printf("%-16s %-30s %-10s %-9s %-9s %-9s %-8s\n",
        'TENANT','NAME','SESSION','STUDENTS','SECTIONS','DEMANDS','RECEIPTS');

    $byT = [];
    foreach ($SETS as $c) $byT[$c] = zx_by_tenant($cache[$c]);

    $seen = [];
    foreach ($schools as $d) {
        $f = zx_fields($d); $id = zx_id($d);
        $sid = (string)($f['schoolId'] ?? $id);
        if (isset($seen[$sid])) continue; $seen[$sid] = true;
        printf("%-16s %-30s %-10s %-9d %-9d %-9d %-8d\n",
            $sid,
            substr((string)($f['schoolName'] ?? $f['name'] ?? $f['school_name'] ?? '-'),0,30),
            (string)($f['session'] ?? $f['currentSession'] ?? '-'),
            count($byT['students'][$sid] ?? []),
            count($byT['sections'][$sid] ?? []),
            count($byT['feeDemands'][$sid] ?? []),
            count($byT['feeReceipts'][$sid] ?? []));
    }

    echo "\n── tenants holding data but with NO schools document ──\n";
    $orphans = [];
    foreach (['students','sections','feeDemands','feeReceipts'] as $c)
        foreach (array_keys($byT[$c]) as $sid)
            if ($sid !== '?' && !isset($seen[$sid])) $orphans[$sid][] = $c;
    if (!$orphans) echo "   none\n";
    foreach ($orphans as $sid=>$cols) printf("   %-16s data in: %s\n", $sid, implode(', ', $cols));

    echo "\n── STUDENT ID ALLOCATOR (read-only) ──\n";
    $ptr = zx_doc($ROOT,'feeCounters','_sys_STU',$TOKEN);
    echo $ptr===null ? "   <not found>\n" : "   feeCounters/_sys_STU = " . json_encode(zx_fields($ptr)) . "\n";

    echo "\nRun with a tenant id for the deep pre-write survey.\n";
    exit(0);
}

if (getenv('ZX_DETAIL')) {
  $d = zx_doc($ROOT,'schools',$target,$TOKEN);
  if ($d===null){echo "absent
";exit(0);}
  $f = zx_fields($d);
  foreach (explode(',', (string)getenv('ZX_DETAIL')) as $key) {
    $key=trim($key); $v=$f[$key]??null;
    echo "-- {$key} --
";
    if ($v===null){echo "   <absent>
"; continue;}
    if (!is_array($v)){echo "   ".json_encode($v)."
"; continue;}
    foreach ($v as $k2=>$v2) { echo "   ".(is_int($k2)?'':"{$k2}: ").substr(json_encode($v2),0,150)."
"; }
  }
  exit(0);
}

// ── deep survey of one tenant ─────────────────────────────────────────────
echo "══ PRE-WRITE SURVEY — {$target} (READ-ONLY) ══\n\n";

$school = zx_doc($ROOT,'schools',$target,$TOKEN);
echo "SCHOOL DOCUMENT\n";
if ($school === null) { echo "   <ABSENT> — this tenant has no schools/{$target}\n"; }
else {
    foreach (zx_fields($school) as $k=>$v) {
        if (is_array($v)) { printf("   %-22s array(%d)\n", $k, count($v)); continue; }
        printf("   %-22s %s\n", $k, is_scalar($v) ? substr((string)$v,0,60) : gettype($v));
    }
    echo "   createTime " . substr((string)($school['createTime']??''),0,19) . "\n";
}

foreach (['sections','feeStructures','chartOfAccounts','feeHeads','feeSettings',
          'studentEntitlements','studentConcessions','studentDiscounts',
          'feeDemands','feeReceipts','feeRefunds','feeDefaulters','accounting',
          'studentRelationships','schoolControl'] as $col) {
    $rows = array_values(array_filter($cache[$col], function($d) use ($target) {
        $f = zx_fields($d);
        return ((string)($f['schoolId'] ?? $f['school_id'] ?? '')) === $target
            || strpos(zx_id($d), $target) === 0;
    }));
    printf("\n%-22s %d document(s)\n", strtoupper($col), count($rows));
    foreach (array_slice($rows,0,8) as $d) {
        $f = zx_fields($d);
        $bits = [];
        foreach (['session','className','sectionName','name','feeHead','status','studentId','value'] as $k)
            if (isset($f[$k]) && is_scalar($f[$k]) && $f[$k] !== '') $bits[] = "{$k}=" . substr((string)$f[$k],0,22);
        printf("   %-46s %s\n", substr(zx_id($d),0,46), implode('  ', array_slice($bits,0,4)));
    }
    if (count($rows) > 8) printf("   … %d more\n", count($rows)-8);
}

echo "\n── STUDENTS in this tenant ──\n";
$stu = array_values(array_filter($cache['students'], function($d) use ($target) {
    $f = zx_fields($d); return ((string)($f['schoolId']??'')) === $target;
}));
printf("   %d student(s)\n", count($stu));
foreach (array_slice($stu,0,10) as $d) {
    $f = zx_fields($d);
    printf("   %-22s %-22s %s / %s   session=%s\n",
        (string)($f['studentId']??'?'), substr((string)($f['name']??'-'),0,22),
        (string)($f['className']??'-'), (string)($f['section']??'-'),
        (string)($f['session']??'-'));
}
if (count($stu) > 10) printf("   … %d more\n", count($stu)-10);
