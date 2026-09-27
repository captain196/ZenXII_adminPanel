<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * M1 — FEE HEAD DISCOVERY + MAPPING PROPOSAL.  STRICTLY READ-ONLY.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Produces the proposed legacy-head → registry-identity → category mapping
 * required before ANY registry record is minted (design §13 M1, brief §11).
 *
 * NO WRITES. No firestoreSet / firestoreCreate / firestoreDelete /
 * setDocument / updateDocument / createDocument / deleteDocument /
 * commitBatch appears anywhere. NOTHING IS MINTED BY THIS SCRIPT.
 *
 * The proposed category is a PROPOSAL ONLY, derived from the head name for
 * the operator to confirm or reject (brief §12). The name is never intended
 * to be the runtime authority — after confirmation the registry is.
 *
 * Usage:  php scripts/m1_fee_head_discovery.php
 */

define('BASEPATH', __DIR__);
define('APPPATH', __DIR__ . '/../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../application/libraries/Firestore_rest_client.php';

$fs = new FirestoreRestClient(
    __DIR__ . '/../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json',
    'graderadmin', '(default)'
);

function body(array $r): array { return is_array($r['data'] ?? null) ? $r['data'] : $r; }
function line(string $s = '') { echo $s . "\n"; }
function head(string $s) { line(); line('── ' . $s . ' ' . str_repeat('─', max(0, 64 - strlen($s)))); }

/** The registry's normalisation rule: lowercase, trim, collapse whitespace. */
function nameNorm(string $s): string { return strtolower(trim(preg_replace('/\s+/', ' ', $s))); }

/**
 * PROPOSAL ONLY — never a runtime authority (brief §5/§13, design §7).
 * Deliberately conservative: anything not positively recognised comes back
 * NULL so the operator must decide, rather than being guessed into academic.
 */
function proposeCategory(string $name): ?string
{
    $n = nameNorm($name);
    if (preg_match('/\b(transport|bus|van|conveyance)\b/', $n))            return 'transport';
    if (preg_match('/\b(hostel|boarding|lodging|dorm)\b/', $n))            return 'hostel';
    if (preg_match('/\b(id[- ]?card|identity card)\b/', $n))               return 'administrative';
    if (preg_match('/\b(tuition|examination|exam|registration|admission|library|lab|laboratory|sport|sports|games|activity|academic)\b/', $n))
                                                                           return 'academic';
    return null;   // ambiguous → operator must decide
}

line('════════════════════════════════════════════════════════════════════');
line(' M1 — FEE HEAD DISCOVERY + MAPPING PROPOSAL   (READ-ONLY, NO WRITES)');
line(' utc=' . gmdate('c'));
line('════════════════════════════════════════════════════════════════════');

// ── gather charts ───────────────────────────────────────────────────────────
$charts = []; $titles = []; $sessions = []; $schools = [];
foreach ($fs->query('feeStructures', [], null, 'ASC', 500) as $r) {
    $id = (string) ($r['id'] ?? ''); $d = body($r);
    $rec = ['id' => $id, 'school' => (string) ($d['schoolId'] ?? ''), 'session' => (string) ($d['session'] ?? ''),
            'heads' => (array) ($d['feeHeads'] ?? [])];
    if (strpos($id, '_titles') !== false) $titles[] = $rec; else $charts[] = $rec;
    if ($rec['school'] !== '') $schools[$rec['school']] = true;
    if ($rec['session'] !== '') $sessions[$rec['school'] . '|' . $rec['session']] = true;
}

// ── 1-4, 9, 10 · chart-derived heads ────────────────────────────────────────
head('1-4 · CHART-DERIVED FEE HEADS');

$bySchoolName = [];   // school => nameNorm => record
$rowCount = 0; $noId = 0; $allIds = [];
foreach ($charts as $c) {
    foreach ($c['heads'] as $h) {
        if (!is_array($h)) continue;
        $rowCount++;
        $nm = trim((string) ($h['name'] ?? ''));
        $id = trim((string) ($h['feeHeadId'] ?? ''));
        $nn = nameNorm($nm);
        if ($id === '') $noId++; else $allIds[$id] = true;
        if (!isset($bySchoolName[$c['school']][$nn])) {
            $bySchoolName[$c['school']][$nn] = ['name' => $nm, 'rows' => 0, 'ids' => [], 'noId' => 0,
                                                'sessions' => [], 'source' => 'chart'];
        }
        $t = &$bySchoolName[$c['school']][$nn];
        $t['rows']++;
        $t['sessions'][$c['session']] = true;
        if ($id === '') $t['noId']++; else $t['ids'][$id] = true;
        unset($t);
    }
}
line('  charts (excl. _titles) : ' . count($charts) . '   _titles docs: ' . count($titles));
line('  fee-head rows          : ' . $rowCount);
line('  distinct feeHeadIds    : ' . count($allIds));
line('  rows with NO feeHeadId : ' . $noId);
line('  schools                : ' . count($schools));

// ── 8 · sessions ────────────────────────────────────────────────────────────
head('8 · EXISTING SESSION STRUCTURES');
foreach (array_keys($sessions) as $k) { [$s, $ses] = explode('|', $k); line("  {$s}   session={$ses}"); }

// ── 5 · runtime-only module heads (from CODE, not data) ─────────────────────
head('5 · RUNTIME-ONLY MODULE HEADS (source-derived — appear in no chart)');
$runtime = [
    ['module' => 'Transport', 'name' => 'Transport Fee',
     'evidence' => 'Fee_lifecycle::createModuleFee default "{moduleType} Fee"; probes pass fee_head=Transport Fee',
     'dynamic' => false],
    ['module' => 'Hostel', 'name' => 'Hostel Fee',
     'evidence' => 'Hostel.php:360 passes NO fee_head key -> default "{moduleType} Fee"',
     'dynamic' => false],
    ['module' => 'IDCard', 'name' => '<head> (Card v{N})',
     'evidence' => 'Id_card_fee_policy::feeHeadFor() = $head." (Card v".$cardVersion.")" — UNBOUNDED per version',
     'dynamic' => true],
];
foreach ($runtime as $r) {
    printf("  %-10s %-24s %s\n", $r['module'], $r['name'], $r['dynamic'] ? '*** DYNAMIC NAME ***' : '');
    line('             evidence: ' . $r['evidence']);
}

// ── 6, 7, 11 · demand-derived heads and categories ──────────────────────────
head('6-7 · FEE HEAD NAMES + CATEGORIES IN EXISTING DEMANDS');
$demandHeads = []; $catCount = []; $demandTotal = 0;
foreach ($fs->query('feeDemands', [], null, 'ASC', 5000) as $r) {
    $d = body($r); $demandTotal++;
    $nm  = trim((string) ($d['feeHead'] ?? ''));
    $cat = (string) ($d['category'] ?? '');
    $key = $nm === '' ? '(no feeHead)' : $nm;
    $demandHeads[$key][$cat === '' ? '(empty)' : $cat] = ($demandHeads[$key][$cat === '' ? '(empty)' : $cat] ?? 0) + 1;
    $catCount[$cat === '' ? '(empty)' : $cat] = ($catCount[$cat === '' ? '(empty)' : $cat] ?? 0) + 1;
}
line('  demands scanned : ' . $demandTotal);
ksort($demandHeads);
foreach ($demandHeads as $nm => $cats) {
    $parts = []; foreach ($cats as $c => $n) $parts[] = "{$c}={$n}";
    printf("    %-28s %s\n", $nm, implode('  ', $parts));
}
line('  category values present:');
arsort($catCount);
foreach ($catCount as $c => $n) printf("    %-14s %d\n", $c, $n);

// ── 9, 10 · conflicts ───────────────────────────────────────────────────────
head('9-10 · IDENTITY / NAME CONFLICTS');
$conflicts = 0;
foreach ($bySchoolName as $school => $heads) {
    foreach ($heads as $nn => $t) {
        if (count($t['ids']) > 1) {
            $conflicts++;
            printf("  %s '%s' -> %d distinct feeHeadIds across %d rows (row-scoped ids, expected)\n",
                $school, $t['name'], count($t['ids']), $t['rows']);
        }
    }
}
line($conflicts === 0 ? '  no multi-id names' : "  {$conflicts} name(s) carry more than one row id — all collapse to ONE registry identity");
$dupNorm = 0;
foreach ($bySchoolName as $school => $heads) {
    $seen = [];
    foreach ($heads as $nn => $t) { if (isset($seen[$nn])) $dupNorm++; $seen[$nn] = true; }
}
line('  duplicate normalised names within a school : ' . $dupNorm . '  (must be 0)');

// ── 11 · archived / legacy conditions ───────────────────────────────────────
head('11 · ARCHIVED / LEGACY CONDITIONS');
line('  existing registry collection      : none (feeHeadRegistry does not exist)');
line('  existing archive/status concept   : none — no status field on any chart row');
line('  legacy demands with category=""   : ' . ($catCount['(empty)'] ?? 0) . ' of ' . $demandTotal);

// ── THE PROPOSAL ────────────────────────────────────────────────────────────
head('PROPOSED MAPPING — REQUIRES OPERATOR CONFIRMATION (NOTHING MINTED)');
line();
printf("  %-22s %-26s %-15s %-9s %s\n", 'SCHOOL', 'HEAD NAME', 'PROPOSED CAT', 'SOURCE', 'EVIDENCE / STATUS');
line('  ' . str_repeat('─', 108));

$ambiguous = 0; $proposed = 0;
foreach ($bySchoolName as $school => $heads) {
    ksort($heads);
    foreach ($heads as $nn => $t) {
        $cat = proposeCategory($t['name']);
        $proposed++;
        if ($cat === null) $ambiguous++;
        printf("  %-22s %-26s %-15s %-9s rows=%d ids=%d noId=%d sessions=%s\n",
            $school, "'" . $t['name'] . "'", $cat ?? '*** AMBIGUOUS ***', 'chart',
            $t['rows'], count($t['ids']), $t['noId'], implode('/', array_keys($t['sessions'])));
    }
}
foreach ($schools as $school => $_) {
    foreach ($runtime as $r) {
        $cat = $r['dynamic'] ? null : proposeCategory($r['name']);
        $proposed++;
        if ($cat === null) $ambiguous++;
        printf("  %-22s %-26s %-15s %-9s %s\n", $school, "'" . $r['name'] . "'",
            $cat ?? '*** BLOCKED ***', 'runtime',
            $r['dynamic'] ? 'DYNAMIC NAME — see BLOCKER below' : $r['module'] . ' module');
    }
}

head('SUMMARY');
line('  heads proposed            : ' . $proposed);
line('  ambiguous / blocked       : ' . $ambiguous);
line('  registry records minted   : 0   (M1 is read-only by design)');
line('  live documents written    : 0');
line();
line($ambiguous === 0
    ? '  M1 COMPLETE — all heads have a proposed category. Await operator confirmation (M2).'
    : "  *** M1 STOPS: {$ambiguous} head(s) cannot be classified authoritatively. ***");
line('── end · READ-ONLY, NOTHING MINTED ──');
