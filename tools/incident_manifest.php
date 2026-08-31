<?php
/**
 * incident_manifest — READ-ONLY. Builds the exact deletion manifest for the
 * 2026-08-31 accidental production writes, from a FRESH production read.
 *
 * Emits one line per candidate:
 *   path|collection|schoolId|studentId|createTime|evidence
 *
 * SAFETY RULES ENCODED HERE (not left to the operator to remember):
 *   • Only the three fabricated tenants are ever considered.
 *   • auditLogs are EXCLUDED — they are the incident evidence trail.
 *   • feeCounters are EXCLUDED — the global allocator must not be rewound.
 *   • Anything outside the incident window is emitted as AMBIGUOUS, never as
 *     a deletion candidate.
 *   • A document with no createTime is AMBIGUOUS, never a candidate.
 *
 * This file cannot delete. It issues GET only.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

$SA      = __DIR__ . '/../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
$PROJECT = 'graderadmin';
$ROOT    = "https://firestore.googleapis.com/v1/projects/{$PROJECT}/databases/(default)/documents";

const TENANTS  = ['SCH_GATE1', 'SCH_GATE2', 'SCH_GATE3'];
const STUDENTS = ['STU0186', 'STU0187', 'STU0188', 'STU0189'];

/** Collections eligible for deletion. auditLogs and feeCounters are absent BY DESIGN. */
const DELETABLE = ['schools', 'sections', 'students', 'feeStructures', 'chartOfAccounts'];

/** UTC. Firestore createTime is UTC; the runs were IST (UTC+5:30). */
const WINDOW_FROM = '2026-08-31T11:45:00';
const WINDOW_TO   = '2026-08-31T15:15:00';

function zx_token(string $sa): string
{
    $j = json_decode((string) file_get_contents($sa), true);
    if (!is_array($j)) { fwrite(STDERR, "service account unreadable\n"); exit(2); }
    $now = time();
    $b64 = fn($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode([
        'iss' => $j['client_email'], 'scope' => 'https://www.googleapis.com/auth/datastore',
        'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
    ]));
    $sig = ''; openssl_sign($unsigned, $sig, $j['private_key'], 'sha256WithRSAEncryption');
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $unsigned . '.' . $b64($sig)])]);
    $res = json_decode((string) curl_exec($ch), true); curl_close($ch);
    if (empty($res['access_token'])) { fwrite(STDERR, "token failed\n"); exit(2); }
    return (string) $res['access_token'];
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

function zx_val(array $v) {
    foreach (['stringValue','booleanValue','integerValue','doubleValue','timestampValue'] as $k) {
        if (array_key_exists($k, $v)) return $k === 'doubleValue' ? (float) $v[$k] : ($k === 'integerValue' ? (int) $v[$k] : $v[$k]);
    }
    if (isset($v['arrayValue'])) { $o=[]; foreach ($v['arrayValue']['values'] ?? [] as $e) $o[]=zx_val($e); return $o; }
    if (isset($v['mapValue']))   { $o=[]; foreach ($v['mapValue']['fields'] ?? [] as $k=>$e) $o[$k]=zx_val($e); return $o; }
    return null;
}

function zx_all(string $root, string $col, string $token): array
{
    $docs = []; $tok = '';
    do {
        $j = zx_get("{$root}/{$col}?pageSize=300" . ($tok !== '' ? '&pageToken=' . urlencode($tok) : ''), $token);
        if ($j === null) break;
        foreach ($j['documents'] ?? [] as $d) $docs[] = $d;
        $tok = (string) ($j['nextPageToken'] ?? '');
    } while ($tok !== '');
    return $docs;
}

$TOKEN = zx_token($SA);
$candidates = []; $ambiguous = [];

foreach (DELETABLE as $col) {
    foreach (zx_all($ROOT, $col, $TOKEN) as $d) {
        $name = (string) ($d['name'] ?? '');
        $id   = substr($name, strrpos($name, '/') + 1);
        $f = []; foreach ($d['fields'] ?? [] as $k => $v) $f[$k] = zx_val($v);

        $blob = $id . ' ' . json_encode($f);
        $tenant = null; foreach (TENANTS  as $t) if (strpos($blob, $t) !== false) $tenant = $t;
        $stu    = null; foreach (STUDENTS as $s) if (strpos($blob, $s) !== false) $stu = $s;
        if ($tenant === null && $stu === null) continue;

        $created = (string) ($d['createTime'] ?? '');
        $row = [
            'path'    => "{$col}/{$id}",
            'col'     => $col,
            'school'  => (string) ($f['schoolId'] ?? $f['school_id'] ?? $tenant ?? ''),
            'student' => (string) ($f['studentId'] ?? $f['student_id'] ?? $stu ?? ''),
            'created' => $created,
        ];

        // A candidate must PROVE it belongs to the accidental run.
        if ($created === '') { $row['why'] = 'NO createTime — cannot prove origin'; $ambiguous[] = $row; continue; }
        if ($created < WINDOW_FROM || $created > WINDOW_TO) {
            $row['why'] = 'created OUTSIDE incident window'; $ambiguous[] = $row; continue;
        }
        if ($tenant === null) { $row['why'] = 'student id matched but no GATE tenant'; $ambiguous[] = $row; continue; }

        $row['evidence'] = "tenant={$tenant} fabricated; created in window; "
            . ($stu !== null ? "student={$stu} exists only under GATE tenants" : 'no legitimate owner');
        $candidates[] = $row;
    }
}

usort($candidates, fn($a,$b) => [$a['col'],$a['path']] <=> [$b['col'],$b['path']]);

if (getenv('ZX_PATHS') || in_array('--paths', $argv, true)) { foreach ($candidates as $c) echo $c['path'] . "\n"; exit(0); }

echo "══ DELETION MANIFEST (fresh production read) ══\n";
echo "window(UTC): " . WINDOW_FROM . " .. " . WINDOW_TO . "\n";
echo "collections considered: " . implode(', ', DELETABLE) . "\n";
echo "EXCLUDED BY DESIGN: auditLogs (evidence), feeCounters (global allocator)\n\n";
printf("%-52s %-16s %-10s %-24s\n", 'PATH', 'SCHOOL', 'STUDENT', 'CREATED (UTC)');
foreach ($candidates as $c) printf("%-52s %-16s %-10s %-24s\n",
    substr($c['path'],0,52), $c['school'], $c['student'], substr($c['created'],0,23));

$byCol = [];
foreach ($candidates as $c) $byCol[$c['col']] = ($byCol[$c['col']] ?? 0) + 1;
echo "\ncount by collection:\n";
foreach ($byCol as $k => $v) printf("  %-18s %d\n", $k, $v);
echo "TOTAL CANDIDATES: " . count($candidates) . "\n";

echo "\nAMBIGUOUS (NOT deletion candidates): " . count($ambiguous) . "\n";
foreach ($ambiguous as $a) printf("  %-52s %s\n", $a['path'], $a['why']);
