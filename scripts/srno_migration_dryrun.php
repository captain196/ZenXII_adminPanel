<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * SRNO CANONICAL MIGRATION — DRY RUN / REPORT ONLY
 * ══════════════════════════════════════════════════════════════════════════
 *
 *   php scripts/srno_migration_dryrun.php
 *
 * Reads every school, every `srNoIndex` document and every `_sys_SRNO_*`
 * counter, and prints — per school — exactly what the migration WOULD do.
 *
 * **This script has no write path.** There is deliberately no `--commit` flag:
 * the executor is a separate, protected artefact. The planning logic lives in
 * `application/libraries/Srno_migration_planner.php` and is unit-tested against
 * eleven named school shapes, so what you read here is the same code that would
 * drive an execution — not a lookalike that might diverge from it.
 *
 * Exit code is 1 if any school is BLOCKED, so this is safe to gate on in CI.
 */

putenv('DISABLE_DOC_CACHE=1');
define('BASEPATH', __DIR__);
define('APPPATH', dirname(__DIR__) . '/application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once APPPATH . 'libraries/Firestore_rest_client.php';
require_once APPPATH . 'libraries/Srno_migration_planner.php';

$creds = APPPATH . 'config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
if (!is_file($creds)) { fwrite(STDERR, "service account not found: {$creds}\n"); exit(2); }

$c    = new FirestoreRestClient($creds, 'graderadmin', '(default)');
$body = function ($r) { return is_array($r['data'] ?? null) ? $r['data'] : $r; };

echo "SRNO CANONICAL MIGRATION — DRY RUN (report only, no writes)\n";
echo str_repeat('=', 78) . "\n\n";

// ── schools: canonical id + its legacy key ────────────────────────────────
$schools = [];
foreach ($c->query('schools', []) as $r) {
    $d  = $body($r);
    $id = (string) ($r['id'] ?? '');
    if ($id === '') continue;
    // parent_db_key = school_code ?: school_id
    $schools[$id] = trim((string) ($d['schoolCode'] ?? '')) ?: $id;
}

// ── srNoIndex, grouped by the canonical prefix of the DOC ID ──────────────
// Grouping by the id (not the field) is the point: the id is the namespace we
// are converging ON, so it is the only trustworthy grouping key.
$indexBySchool = [];
$unattributed  = [];
foreach ($c->query(Srno_migration_planner::INDEX_COL, []) as $r) {
    $d   = $body($r);
    $id  = (string) ($r['id'] ?? '');
    $row = ['id' => $id, 'schoolId' => (string) ($d['schoolId'] ?? ''),
            'srNo' => (int) ($d['srNo'] ?? 0), 'currentStudentId' => (string) ($d['currentStudentId'] ?? '')];

    $owner = null;
    foreach ($schools as $sid => $_) {
        if (strpos($id, $sid . '_') === 0) { $owner = $sid; break; }
    }
    if ($owner === null) { $unattributed[] = $row; continue; }
    $indexBySchool[$owner][] = $row;
}

// ── counters ──────────────────────────────────────────────────────────────
$counters = [];
foreach ($c->query(Srno_migration_planner::COUNTER_COL, []) as $r) {
    $id = (string) ($r['id'] ?? '');
    if (strpos($id, Srno_migration_planner::COUNTER_PREFIX) !== 0) continue;
    $d = $body($r);
    $counters[$id] = ['value' => (int) ($d['value'] ?? $d['current'] ?? 0)];
}

// ── plan every school ─────────────────────────────────────────────────────
$planner = new Srno_migration_planner();
$plans   = [];
foreach ($schools as $sid => $legacy) {
    $plans[$sid] = $planner->plan($sid, $legacy, $indexBySchool[$sid] ?? [], $counters);
}

$blocked = $work = $noop = 0;
foreach ($plans as $p) {
    if (!$p['safe'])      $blocked++;
    elseif ($p['noop'])   $noop++;
    else                  $work++;
}

echo "SCHOOLS: " . count($schools) . "   ·   needs work: {$work}   ·   already clean: {$noop}   ·   BLOCKED: {$blocked}\n";
echo "srNoIndex documents: " . array_sum(array_map('count', $indexBySchool))
   . "   ·   unattributable: " . count($unattributed) . "\n\n";

echo "── PER-SCHOOL PLAN " . str_repeat('─', 59) . "\n";
foreach ($plans as $p) {
    // Silent on schools with nothing to say; the summary above already counts them.
    if ($p['noop'] && $p['indexRows'] === 0) continue;
    echo Srno_migration_planner::summarise($p) . "\n";
    if ($p['indexRows'] > 0) {
        printf("           legacyKey=%-12s docIdsCanonical=%d/%d  fields(canon/legacy)=%d/%d  maxSrNo=%d  pointers(legacy/canon)=%d/%d\n",
            (string) ($p['legacyKey'] ?? '(same)'), $p['docIdsCanonical'], $p['indexRows'],
            $p['fieldCanonical'], $p['fieldLegacy'], $p['maxSrNo'],
            $p['legacyPointer'], $p['canonicalPointer']);
    }
}

if ($unattributed !== []) {
    echo "\n── ⚠ UNATTRIBUTABLE srNoIndex DOCUMENTS " . str_repeat('─', 37) . "\n";
    echo "   Their doc id matches no known school. These must be resolved by hand\n";
    echo "   BEFORE any migration — an unattributed index row is an SRNO nobody owns.\n";
    foreach (array_slice($unattributed, 0, 20) as $r) {
        printf("   id=%-34s srNo=%-5d schoolIdFIELD=%-16s student=%s\n",
            $r['id'], $r['srNo'], $r['schoolId'], $r['currentStudentId']);
    }
}

echo "\n" . str_repeat('=', 78) . "\n";
echo $blocked > 0
    ? "RESULT: NOT SAFE TO MIGRATE — {$blocked} school(s) blocked. Resolve the blockers above.\n"
    : "RESULT: every school plans cleanly. Execution remains a separate, protected step.\n";
echo "No documents were written by this script.\n";

exit($blocked > 0 ? 1 : 0);
