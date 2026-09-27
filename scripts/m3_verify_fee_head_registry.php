<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * M3 POST-VERIFICATION — STRICTLY READ-ONLY, SEPARATE PROCESS
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Independent of the mint script. The mint tool's own report is NOT evidence
 * (the R5 lesson: a deletion tool once reported "0 remaining" while 280
 * remained). Everything below is re-read from a fresh client.
 *
 * NO WRITES.
 */

define('BASEPATH', __DIR__);
define('APPPATH', __DIR__ . '/../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../application/libraries/Firestore_rest_client.php';
require_once __DIR__ . '/../application/libraries/Fee_head_resolver.php';

$fs = new FirestoreRestClient(
    __DIR__ . '/../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json',
    'graderadmin', '(default)'
);

function body(array $r): array { return is_array($r['data'] ?? null) ? $r['data'] : $r; }
function line(string $s = '') { echo $s . "\n"; }
function head(string $s) { line(); line('── ' . $s . ' ' . str_repeat('─', max(0, 62 - strlen($s)))); }
$FAIL = 0;
function chk(bool $ok, string $label, string $detail = '') {
    global $FAIL; if (!$ok) $FAIL++;
    printf("  %-7s %-52s %s\n", $ok ? 'PASS' : '*FAIL*', $label, $detail);
}

line('════════════════════════════════════════════════════════════════════');
line(' M3 POST-VERIFICATION — READ-ONLY, FRESH PROCESS   utc=' . gmdate('c'));
line('════════════════════════════════════════════════════════════════════');

$all = $fs->query(Fee_head_resolver::COL, [], null, 'ASC', 500);
$entries = []; $claims = [];
foreach ($all as $r) {
    $d = body($r); $id = (string) ($r['id'] ?? '');
    if (($d['kind'] ?? '') === 'nameClaim') $claims[$id] = $d; else $entries[$id] = $d;
}

head('A · REGISTRY CONTENTS');
line('  total documents : ' . count($all));
chk(count($entries) === 12, 'exactly 12 registry entries', (string) count($entries));
chk(count($claims) === 12, 'exactly 12 name claims', (string) count($claims));

$byCat = []; $bySchool = [];
foreach ($entries as $id => $d) {
    $byCat[(string) ($d['category'] ?? '?')] = ($byCat[(string) ($d['category'] ?? '?')] ?? 0) + 1;
    $bySchool[(string) ($d['schoolId'] ?? '?')][] = (string) ($d['name'] ?? '?');
}
ksort($byCat); ksort($bySchool);
foreach ($bySchool as $s => $names) { sort($names); line("     {$s} : " . implode(', ', $names)); }
line('  by category : ' . json_encode($byCat));

head('B · APPROVED MAPPING HELD EXACTLY');
$expect = [
    'SCH_218AAF5C23|tuition fee' => 'academic',       'SCH_218AAF5C23|examination fee' => 'academic',
    'SCH_218AAF5C23|registration fee' => 'academic',  'SCH_218AAF5C23|transport fee' => 'transport',
    'SCH_218AAF5C23|hostel fee' => 'hostel',          'SCH_218AAF5C23|id card replacement' => 'administrative',
    'SCH_B56BB9A401|tuition fee' => 'academic',       'SCH_B56BB9A401|admission fee' => 'academic',
    'SCH_B56BB9A401|library fee' => 'academic',       'SCH_B56BB9A401|transport fee' => 'transport',
    'SCH_B56BB9A401|hostel fee' => 'hostel',          'SCH_B56BB9A401|id card replacement' => 'administrative',
];
$seen = [];
foreach ($entries as $d) $seen[$d['schoolId'] . '|' . $d['nameNorm']] = (string) ($d['category'] ?? '');
ksort($seen); ksort($expect);
chk($seen === $expect, 'all 12 school|name -> category pairs match', count(array_diff_assoc($expect, $seen)) . ' mismatch(es)');
foreach ($expect as $k => $v) if (($seen[$k] ?? null) !== $v) line("     *** {$k}: expected {$v}, got " . ($seen[$k] ?? 'MISSING'));

head('C · INVARIANTS');
$noSession = true; $allActive = true; $validCat = true; $idImmutableShape = true;
foreach ($entries as $id => $d) {
    if (array_key_exists('session', $d) || array_key_exists('academicSession', $d)) $noSession = false;
    if (($d['status'] ?? '') !== 'active') $allActive = false;
    if (!Fee_head_resolver::isAssignableCategory((string) ($d['category'] ?? ''))) $validCat = false;
    if ($id !== Fee_head_resolver::docId((string) $d['schoolId'], (string) $d['feeHeadId'])) $idImmutableShape = false;
}
chk($noSession, 'NO session field on any entry (school-scoped identity)');
chk($allActive, 'every entry status=active');
chk($validCat, 'every category is operator-assignable (no unknown, no meals)');
chk($idImmutableShape, 'doc id == {schoolId}_{feeHeadId} for every entry');
chk(!isset($byCat['meals']), 'NO meals head was created', (string) ($byCat['meals'] ?? 0));
chk(($byCat['administrative'] ?? 0) === 2, 'exactly 2 administrative heads (ID card, one per school)');

// claim integrity
$claimOk = true;
foreach ($claims as $cid => $c) {
    $want = Fee_head_resolver::claimDocId((string) $c['schoolId'], (string) $c['nameNorm']);
    $target = Fee_head_resolver::docId((string) $c['schoolId'], (string) $c['feeHeadId']);
    if ($cid !== $want || !isset($entries[$target])) $claimOk = false;
}
chk($claimOk, 'every name claim points at a real entry with the right id');

// one identity per (school,name) — the collapse of chart+runtime Transport
$perKey = [];
foreach ($entries as $d) $perKey[$d['schoolId'] . '|' . $d['nameNorm']][] = $d['feeHeadId'];
$dupes = array_filter($perKey, fn($v) => count($v) > 1);
chk(count($dupes) === 0, 'ONE identity per school+name (Transport not duplicated)', (string) count($dupes));

head('D · THE RESOLVER READS ITS OWN REGISTRY');
$resolver = (new Fee_head_resolver())->init(new class($fs) {
    public function __construct(private $c) {}
    public function get(string $col, string $id) { $d = $this->c->getDocument($col, $id); return is_array($d) ? $d : null; }
});
$probe = [
    ['SCH_B56BB9A401', 'transport fee', 'transport'],
    ['SCH_B56BB9A401', 'id card replacement', 'administrative'],
    ['SCH_218AAF5C23', 'tuition fee', 'academic'],
];
foreach ($probe as [$school, $nn, $want]) {
    $fid = null;
    foreach ($entries as $d) if ($d['schoolId'] === $school && $d['nameNorm'] === $nn) $fid = $d['feeHeadId'];
    $r = $resolver->resolve($school, (string) $fid);
    chk(!empty($r['ok']) && $r['category'] === $want, "resolve {$school} '{$nn}' -> {$want}",
        !empty($r['ok']) ? $r['category'] : ('FAILED: ' . ($r['reason'] ?? '?')));
}
// cross-school isolation, live
$aId = null;
foreach ($entries as $d) if ($d['schoolId'] === 'SCH_218AAF5C23' && $d['nameNorm'] === 'tuition fee') $aId = $d['feeHeadId'];
$x = $resolver->resolve('SCH_B56BB9A401', (string) $aId);
chk(empty($x['ok']), 'school B cannot resolve school A identity (live)', $x['reason'] ?? '?');

head('E · NOTHING ELSE WAS TOUCHED');
$fd = $fs->query('feeDemands', [], null, 'ASC', 5000);
$byT = [];
foreach ($fd as $r) { $d = body($r); $k = (string) ($d['schoolId'] ?? '(none)'); $byT[$k] = ($byT[$k] ?? 0) + 1; }
chk(count($fd) === 1242, 'feeDemands platform-wide still 1242', (string) count($fd));
chk(($byT['SCH_B56BB9A401'] ?? 0) === 26, 'UAT tenant demands still 26', (string) ($byT['SCH_B56BB9A401'] ?? 0));
chk(($byT['SCH_218AAF5C23'] ?? 0) === 1118, 'other tenant demands still 1118', (string) ($byT['SCH_218AAF5C23'] ?? 0));
chk(count($fs->query('feeStructures', [], null, 'ASC', 500)) === 9, 'feeStructures still 9 docs');
chk(count($fs->query('students', [], null, 'ASC', 3000)) === 150, 'students still 150');
chk(count($fs->query('srNoIndex', [], null, 'ASC', 500)) === 0, 'srNoIndex still 0 (SRNO untouched)');

head('VERDICT');
line($FAIL === 0 ? '  M3 VERIFIED — registry minted correctly, nothing else changed.'
                 : "  *** {$FAIL} CHECK(S) FAILED ***");
line('── end · READ-ONLY ──');
exit($FAIL === 0 ? 0 : 4);
