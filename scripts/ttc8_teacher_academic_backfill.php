<?php
/**
 * ttc8_teacher_academic_backfill — one-off, additive existing-school repair.
 *
 * WHY: TT-C8's seed grants teaching roles the `Academic` module + `view` level so a
 * newly onboarded school's teachers can SEE the timetable in the Teacher App. Schools
 * onboarded BEFORE that seed change carry teaching roles WITHOUT the Academic module.
 * Neither Rbac_levels_backfill (levels modules already held — never ADDS a module) nor
 * ensure_unified_roles() (fills only wholly-missing fields — never merges an array
 * element) can close that gap. This does exactly and only that.
 *
 * SCOPE (aligns the two canonical teaching roles to the current product default):
 *   - Only ROLE_TEACHER and ROLE_CLASS_TEACHER (the canonical system teaching roles;
 *     custom/school-defined roles are never touched).
 *   - Brings each to the current canonical timetable capability: `can_access_timetable`
 *     flag, 'Academic' module, and permissionLevels.Academic = 'view'. Never edit/manage.
 *   - `can_access_timetable = no` on an existing role is LEGACY RESIDUE, not a current
 *     restriction: the canonical seed grants teachers timetable access, no subscription/
 *     plan gates it, and staffCapabilities derives modules from role permissions (never
 *     intersected with a school-level "no"). Proven 2026-09-15. So a flag=no teaching
 *     role is migrated too — it is not authority for withholding a core ERP capability.
 *   - Nothing else is touched. Per-staff deny overrides (applied AFTER role resolution
 *     in staffCapabilities) and school-level module controls are preserved.
 *
 * SAFETY:
 *   - DRY-RUN by default; pass --commit to write.
 *   - IDEMPOTENT: a role already holding Academic at view is skipped; re-running is a
 *     no-op.
 *   - ADDITIVE: only permissions[] (union) and permissionLevels.Academic are changed;
 *     every other role and field is written back byte-for-byte.
 *   - REVERSIBLE: --commit writes a rollback .jsonl (prior full staffRoles per school)
 *     next to this script; restore with --restore=<file>.
 *   - TENANT-SCOPED: optional --school=SCH_XXXX to run one tenant.
 *
 * USAGE (from project root):
 *   php scripts/ttc8_teacher_academic_backfill.php                 # dry-run, all tenants
 *   php scripts/ttc8_teacher_academic_backfill.php --school=SCH_X  # dry-run, one tenant
 *   php scripts/ttc8_teacher_academic_backfill.php --commit        # write, all tenants
 *   php scripts/ttc8_teacher_academic_backfill.php --restore=ttc8_teacher_academic_rollback_XX;
 */

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (strncmp($a, '--', 2) !== 0) continue;
    $a = substr($a, 2);
    $eq = strpos($a, '=');
    $opts[$eq === false ? $a : substr($a, 0, $eq)] = $eq === false ? true : substr($a, $eq + 1);
}
$commit  = isset($opts['commit']);
$only    = isset($opts['school']) ? (string) $opts['school'] : 'all';
$restore = isset($opts['restore']) ? (string) $opts['restore'] : '';

$root = realpath(__DIR__ . '/..');
// The REST client's config-doc cache-bust (triggered when writing a cached
// collection such as `schools`) references APPPATH/FCPATH. Standalone scripts
// must define them, exactly as CI would, or setDocument() fatals post-write.
if (!defined('APPPATH')) define('APPPATH', $root . '/application/');
if (!defined('FCPATH'))  define('FCPATH',  $root . '/');
$credPath = $root . '/application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
$cred = @json_decode((string) file_get_contents($credPath), true) ?: [];
$projectId = (string) ($cred['project_id'] ?? '');
if ($projectId === '') { fwrite(STDERR, "project_id missing from service account\n"); exit(2); }
require_once $root . '/application/libraries/Firestore_rest_client.php';
$fs = new FirestoreRestClient($credPath, $projectId, '(default)');

$TEACH = ['ROLE_TEACHER', 'ROLE_CLASS_TEACHER'];

/* ── restore mode ─────────────────────────────────────────────────────── */
if ($restore !== '') {
    $path = (strpos($restore, '/') === 0 || strpos($restore, ':') === 1) ? $restore : $root . '/scripts/' . $restore;
    if (!is_file($path)) { fwrite(STDERR, "rollback file not found: $path\n"); exit(2); }
    echo "== restoring staffRoles from $path ==\n";
    $fh = fopen($path, 'r'); $n = 0;
    while (($line = fgets($fh)) !== false) {
        $line = trim($line); if ($line === '') continue;
        $rec = json_decode($line, true);
        $sid = (string) ($rec['schoolId'] ?? '');
        $before = $rec['staffRoles'] ?? null;
        if ($sid === '' || !is_array($before)) continue;
        $ok = $fs->setDocument('schools', $sid, ['staffRoles' => $before], true);
        echo ($ok ? "  [restored] " : "  [FAILED]  ") . $sid . "\n";
        $n++;
    }
    fclose($fh);
    echo "done — {$n} school(s) restored.\n";
    exit(0);
}

/* ── backfill ─────────────────────────────────────────────────────────── */
echo "\n== TT-C8 teacher Academic backfill ==\n";
echo "mode   : " . ($commit ? "COMMIT (writing)" : "DRY-RUN (no writes)") . "\n";
echo "school : {$only}\n\n";

$rollback = null;
if ($commit) {
    $rbPath = $root . '/scripts/ttc8_teacher_academic_rollback_' . date('Ymd_His') . '.jsonl';
    $rollback = @fopen($rbPath, 'w');
    echo "rollback log: {$rbPath}\n\n";
}

try {
    $rows = $fs->queryOrFail('schools', [], null, 'ASC', 5000);
} catch (\Throwable $e) {
    fwrite(STDERR, "could not list schools (fail-loud): " . $e->getMessage() . "\n");
    if ($rollback) fclose($rollback);
    exit(2);
}

$stats = ['schools_seen' => 0, 'schools_written' => 0, 'roles_fixed' => 0, 'roles_already_ok' => 0];

foreach ($rows as $r) {
    if (isset($r['data']) && is_array($r['data'])) { $sid = (string) ($r['id'] ?? ''); $doc = $r['data']; }
    else { $doc = is_array($r) ? $r : []; $sid = (string) ($doc['id'] ?? $doc['__name__'] ?? ''); if (strpos($sid, '/') !== false) $sid = substr($sid, strrpos($sid, '/') + 1); }
    if ($sid === '' || !is_array($doc)) continue;
    if ($only !== 'all' && $sid !== $only) continue;

    $staffRoles = is_array($doc['staffRoles'] ?? null) ? $doc['staffRoles'] : [];
    if (!$staffRoles) continue;
    $stats['schools_seen']++;

    $before = $staffRoles;   // deep copy for rollback (arrays are value types in PHP)
    $touched = false;

    foreach ($TEACH as $rid) {
        if (!isset($staffRoles[$rid]) || !is_array($staffRoles[$rid])) continue;
        $flags  = array_values((array) ($staffRoles[$rid]['flags'] ?? []));
        $perms  = array_values((array) ($staffRoles[$rid]['permissions'] ?? []));
        $levels = is_array($staffRoles[$rid]['permissionLevels'] ?? null) ? $staffRoles[$rid]['permissionLevels'] : [];

        $hasFlag = in_array('can_access_timetable', $flags, true);
        $hasMod  = in_array('Academic', $perms, true);
        $hasView = (($levels['Academic'] ?? null) === 'view');

        if ($hasFlag && $hasMod && $hasView) { $stats['roles_already_ok']++; continue; }

        $added = [];
        if (!$hasFlag) { $flags[] = 'can_access_timetable'; $staffRoles[$rid]['flags'] = array_values(array_unique($flags)); $added[] = 'flag'; }
        if (!$hasMod)  { $perms[] = 'Academic';             $staffRoles[$rid]['permissions'] = array_values(array_unique($perms)); $added[] = 'module'; }
        if (!$hasView) { $levels['Academic'] = 'view';      $staffRoles[$rid]['permissionLevels'] = $levels; $added[] = 'view'; }

        $touched = true;
        $stats['roles_fixed']++;
        echo "  {$sid}/{$rid}: +" . implode(' +', $added) . "\n";
    }

    if (!$touched) continue;

    if ($commit) {
        if ($rollback) fwrite($rollback, json_encode(['schoolId' => $sid, 'staffRoles' => $before]) . "\n");
        $ok = $fs->setDocument('schools', $sid, ['staffRoles' => $staffRoles], true);
        echo ($ok ? "  [written] " : "  [FAILED]  ") . $sid . "\n";
        if ($ok) $stats['schools_written']++;
    } else {
        echo "  [would write] {$sid}\n";
    }
}

if ($rollback) fclose($rollback);

echo "\n── summary ──\n";
foreach ($stats as $k => $v) printf("  %-18s %d\n", $k, $v);
echo "\n" . ($commit
    ? "done — levels take effect on each teacher's next Teacher-App load / token refresh.\n"
    : "dry-run only. Re-run with --commit to apply.\n");
exit(0);
