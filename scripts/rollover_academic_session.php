<?php
/**
 * ═══════════════════════════════════════════════════════════════════
 *  Academic Planner — Phase 3 Session Rollover
 *
 *  One-shot annual operation: when session 2026-27 ends and 2027-28
 *  begins, copy curriculum / timetables / subjectAssignments /
 *  timetableSettings from the old session into the new session, with
 *  appropriate resets per module.
 *
 *  Carries:
 *    timetableSettings   straight copy (schedule rarely changes)
 *    subjectAssignments  carry teacher mapping; admin re-reviews
 *    timetables          carry periods; reset manuallyEdited + version
 *    curriculum (parent) clone topic structure, fresh UUIDs, all
 *                        statuses → not_started, completedDate cleared,
 *                        completedTopics → 0, percentComplete → 0
 *    curriculum/topics   new subcollection docs with new UUIDs
 *
 *  Does NOT carry:
 *    calendarEvents  (holidays change year-over-year)
 *    substitutes     (day-specific historical records)
 *
 *  Marker on every carried doc:
 *    carriedFromSession  : '2026-27'
 *    carriedAt           : ISO timestamp
 *    phase3RolloverRev   : 1
 *
 *  Idempotent — re-runs see the marker and skip. Bump
 *  PHASE3_ROLLOVER_REV to revisit a previous rollover.
 *
 *  Rollback — deletes every doc in the target session that carries
 *  phase3RolloverRev >= 1. Original-session docs are NEVER touched.
 *
 *  USAGE
 *    Dry-run (default — NO writes):
 *      php scripts/rollover_academic_session.php \
 *          --school=SCH_D94FE8F7AD --from=2026-27 --to=2027-28
 *
 *    Apply:
 *      php scripts/rollover_academic_session.php \
 *          --school=SCH_D94FE8F7AD --from=2026-27 --to=2027-28 --apply
 *
 *    Per-module strategy (defaults: copy/carry):
 *      --curriculum=copy|blank|skip
 *      --timetables=carry|blank|skip
 *      --assignments=carry|blank|skip
 *      --settings=carry|blank|skip
 *
 *    Verbose (per-doc trace):
 *      ... --verbose
 *
 *    Rollback:
 *      php scripts/rollover_academic_session.php \
 *          --school=SCH_D94FE8F7AD --to=2027-28 --rollback --confirm
 *
 *  SAFETY
 *    - Dry-run by default — explicit --apply required to write.
 *    - --rollback further requires --confirm flag (delete is destructive).
 *    - Source-session docs are never modified or deleted.
 *    - Idempotent — safe to re-run.
 * ═══════════════════════════════════════════════════════════════════
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

// ── Bootstrap minimal ───────────────────────────────────────────────
define('BASEPATH', realpath(__DIR__ . '/../system/') . DIRECTORY_SEPARATOR);
define('APPPATH',  realpath(__DIR__ . '/../application/') . DIRECTORY_SEPARATOR);

// Stub log_message so the Firestore client (which calls it) doesn't fatal.
if (!function_exists('log_message')) {
    function log_message($level, $msg) {
        fwrite(STDERR, "[$level] $msg\n");
    }
}

require_once APPPATH . 'libraries/Firestore_rest_client.php';

// ── Args ────────────────────────────────────────────────────────────
function arg_get(string $name, $default = null) {
    foreach ($GLOBALS['argv'] as $a) {
        if (strpos($a, "--$name=") === 0) return substr($a, strlen($name) + 3);
    }
    return $default;
}
function arg_has(string $name): bool {
    foreach ($GLOBALS['argv'] as $a) {
        if ($a === "--$name" || strpos($a, "--$name=") === 0) return true;
    }
    return false;
}

$SCHOOL  = arg_get('school');
$FROM    = arg_get('from');
$TO      = arg_get('to');
$APPLY   = arg_has('apply');
$VERBOSE = arg_has('verbose');
$ROLLBACK = arg_has('rollback');
$CONFIRM  = arg_has('confirm');

$STRAT_CURR  = arg_get('curriculum',  'copy');
$STRAT_TT    = arg_get('timetables',  'carry');
$STRAT_ASGN  = arg_get('assignments', 'carry');
$STRAT_SETT  = arg_get('settings',    'carry');

const PHASE3_ROLLOVER_REV = 1;

// ── ANSI colours ────────────────────────────────────────────────────
function bold($s){ return "\033[1m$s\033[0m"; }
function dim($s) { return "\033[2m$s\033[0m"; }
function cyan($s){ return "\033[36m$s\033[0m"; }
function yel($s) { return "\033[33m$s\033[0m"; }
function grn($s) { return "\033[32m$s\033[0m"; }
function red($s) { return "\033[31m$s\033[0m"; }
const HR = "────────────────────────────────────────────────────────────────────────";

// ── Validate args ───────────────────────────────────────────────────
if (!$SCHOOL) { fwrite(STDERR, red("--school=<schoolId> required\n")); exit(2); }

if ($ROLLBACK) {
    if (!$TO) { fwrite(STDERR, red("--to=<targetSession> required for --rollback\n")); exit(2); }
    // For rollback, --confirm is the SOLE write gate (no separate --apply needed).
    // --rollback alone shows a preview (dry-run); --rollback --confirm actually deletes.
} else {
    if (!$FROM || !$TO) { fwrite(STDERR, red("--from=<oldSession> and --to=<newSession> required\n")); exit(2); }
    if ($FROM === $TO)  { fwrite(STDERR, red("--from and --to must differ\n")); exit(2); }
}

foreach (['curriculum'=>$STRAT_CURR,'timetables'=>$STRAT_TT,'assignments'=>$STRAT_ASGN,'settings'=>$STRAT_SETT] as $k=>$v) {
    $allowed = ($k === 'curriculum') ? ['copy','blank','skip'] : ['carry','blank','skip'];
    if (!in_array($v, $allowed, true)) {
        fwrite(STDERR, red("Invalid --$k='$v'; allowed: ".implode(',', $allowed)."\n"));
        exit(2);
    }
}

// ── Firestore client ────────────────────────────────────────────────
$saPath = APPPATH . 'config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
if (!file_exists($saPath)) {
    fwrite(STDERR, red("Service account not found at $saPath\n"));
    exit(2);
}
$fs = new FirestoreRestClient($saPath, 'graderadmin', '(default)');

// ── UUID v4 ─────────────────────────────────────────────────────────
function uuid(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}

// ── Stats helper ────────────────────────────────────────────────────
function newStats(): array { return ['scanned'=>0,'created'=>0,'skipped'=>0,'failed'=>0,'warnings'=>[]]; }

// ── Header ──────────────────────────────────────────────────────────
echo bold(cyan("Academic Planner — Phase 3 Session Rollover")) . "\n";
echo HR . "\n";
echo "  school:         $SCHOOL\n";
if ($ROLLBACK) {
    echo "  mode:           " . red("ROLLBACK") . "\n";
    echo "  target session: $TO\n";
} else {
    echo "  mode:           " . ($APPLY ? grn("APPLY") : yel("DRY-RUN")) . "\n";
    echo "  from session:   $FROM\n";
    echo "  to session:     $TO\n";
    echo "  curriculum:     $STRAT_CURR\n";
    echo "  timetables:     $STRAT_TT\n";
    echo "  assignments:    $STRAT_ASGN\n";
    echo "  settings:       $STRAT_SETT\n";
}
echo HR . "\n";

// ────────────────────────────────────────────────────────────────────
//  ROLLBACK
// ────────────────────────────────────────────────────────────────────
if ($ROLLBACK) {
    $stats = ['curriculum'=>newStats(),'timetables'=>newStats(),'subjectAssignments'=>newStats(),'timetableSettings'=>newStats()];
    // For rollback: --confirm is the write gate. Without --confirm, this is a
    // preview (counts what WOULD be deleted, no Firestore writes).
    $doDelete = $CONFIRM;

    foreach (['curriculum','timetables','subjectAssignments','timetableSettings'] as $coll) {
        echo bold("▸ $coll") . "\n";
        try {
            $rows = $fs->query($coll, [
                ['schoolId', '==', $SCHOOL],
                ['session',  '==', $TO],
            ], null, 'ASC', 5000);
        } catch (\Throwable $e) {
            echo red("  query failed: " . $e->getMessage() . "\n");
            continue;
        }
        foreach ($rows as $row) {
            $stats[$coll]['scanned']++;
            $d = is_array($row['data'] ?? null) ? $row['data'] : $row;
            if ((int)($d['phase3RolloverRev'] ?? 0) < PHASE3_ROLLOVER_REV) {
                $stats[$coll]['skipped']++;
                if ($VERBOSE) echo dim("  skip (not phase3-rolled): {$row['id']}\n");
                continue;
            }
            // Curriculum: also delete subcollection topics first.
            if ($coll === 'curriculum' && is_array($d['topicIds'] ?? null) && $doDelete) {
                foreach ($d['topicIds'] as $tid) {
                    if (!is_string($tid) || $tid === '') continue;
                    try { $fs->deleteDocument("curriculum/{$row['id']}/topics", $tid); } catch (\Throwable $e) {}
                }
            }
            if ($doDelete) {
                try { $fs->deleteDocument($coll, $row['id']); $stats[$coll]['created']++; }
                catch (\Throwable $e) { $stats[$coll]['failed']++; }
            } else {
                $stats[$coll]['created']++;
            }
            if ($VERBOSE) echo dim("  delete: {$row['id']}\n");
        }
        $s = $stats[$coll];
        echo "  scanned={$s['scanned']}  deleted={$s['created']}  skipped={$s['skipped']}  failed={$s['failed']}\n";
    }
    echo HR . "\n";
    echo bold("Rollback done") . " (" . ($doDelete ? grn("applied") : yel("preview")) . ")\n";
    if (!$doDelete) echo yel("  No deletes performed. Re-run with --confirm to commit.\n");
    exit(0);
}

// ────────────────────────────────────────────────────────────────────
//  ROLLOVER
// ────────────────────────────────────────────────────────────────────
$stats = ['curriculum'=>newStats(),'timetables'=>newStats(),'subjectAssignments'=>newStats(),'timetableSettings'=>newStats()];
$now   = date('c');

// ── 1. timetableSettings (single doc) ───────────────────────────────
if ($STRAT_SETT !== 'skip') {
    echo bold("▸ timetableSettings") . "\n";
    $srcId = "{$SCHOOL}_{$FROM}";
    $dstId = "{$SCHOOL}_{$TO}";
    try {
        $src = $fs->getDocument('timetableSettings', $srcId);
        $dst = $fs->getDocument('timetableSettings', $dstId);
    } catch (\Throwable $e) { $src = null; $dst = null; }
    if (!is_array($src)) {
        echo dim("  no source doc — skip\n");
        $stats['timetableSettings']['skipped']++;
    } elseif (is_array($dst) && (int)($dst['phase3RolloverRev'] ?? 0) >= PHASE3_ROLLOVER_REV) {
        echo dim("  target already rolled — skip\n");
        $stats['timetableSettings']['skipped']++;
    } else {
        $stats['timetableSettings']['scanned']++;
        $payload = ($STRAT_SETT === 'blank')
            ? ['schoolId' => $SCHOOL, 'session' => $TO]
            : array_merge($src, ['schoolId' => $SCHOOL, 'session' => $TO]);
        $payload['carriedFromSession']  = $FROM;
        $payload['carriedAt']           = $now;
        $payload['phase3RolloverRev']   = PHASE3_ROLLOVER_REV;
        $payload['updatedAt']           = $now;
        $payload['updatedByUid']        = 'system_phase3_rollover';
        $payload['updatedByName']       = 'Phase 3 Session Rollover';
        if ($APPLY) {
            try { $fs->setDocument('timetableSettings', $dstId, $payload); $stats['timetableSettings']['created']++; }
            catch (\Throwable $e) { $stats['timetableSettings']['failed']++; }
        } else {
            $stats['timetableSettings']['created']++;
        }
        if ($VERBOSE) echo dim("  $srcId → $dstId\n");
    }
    $s = $stats['timetableSettings'];
    echo "  scanned={$s['scanned']}  created={$s['created']}  skipped={$s['skipped']}  failed={$s['failed']}\n";
}

// ── 2. subjectAssignments ───────────────────────────────────────────
if ($STRAT_ASGN !== 'skip') {
    echo bold("▸ subjectAssignments") . "\n";
    try {
        $rows = $fs->query('subjectAssignments', [
            ['schoolId', '==', $SCHOOL],
            ['session',  '==', $FROM],
        ], null, 'ASC', 5000);
    } catch (\Throwable $e) {
        echo red("  query failed: " . $e->getMessage() . "\n");
        $rows = [];
    }
    foreach ($rows as $row) {
        $stats['subjectAssignments']['scanned']++;
        $d = is_array($row['data'] ?? null) ? $row['data'] : $row;

        // Build target docId by replacing the session segment.
        // Source format: {schoolId}_{session}_{class}_{section}_{subjectCode}
        // with spaces and slashes substituted by underscores in the class/section
        // segments (Firestore disallows '/' in docId components; spaces are legal
        // but Phase 6 chose underscores for consistency).
        $cls = $d['className'] ?? $d['classKey']    ?? '';
        // `section` is the plain section name ("Section A"); `sectionKey` is the
        // combined "ClassName/SectionName" form — if only the combined form is
        // present, strip the class prefix.
        $sec = $d['section'] ?? '';
        if ($sec === '' && isset($d['sectionKey'])) {
            $sk = (string) $d['sectionKey'];
            $slash = strrpos($sk, '/');
            $sec = $slash !== false ? substr($sk, $slash + 1) : $sk;
        }
        $sc = $d['subjectCode'] ?? '';
        if ($cls === '' || $sc === '') {
            $stats['subjectAssignments']['skipped']++;
            $stats['subjectAssignments']['warnings'][] = "missing className/subjectCode on {$row['id']}";
            continue;
        }
        $clsSafe = str_replace([' ', '/'], '_', (string) $cls);
        $secSafe = str_replace([' ', '/'], '_', (string) $sec);
        $secKey  = ($secSafe === '') ? '_ALL_' : $secSafe;
        $dstId   = "{$SCHOOL}_{$TO}_{$clsSafe}_{$secKey}_{$sc}";

        try { $existing = $fs->getDocument('subjectAssignments', $dstId); }
        catch (\Throwable $e) { $existing = null; }
        if (is_array($existing) && (int)($existing['phase3RolloverRev'] ?? 0) >= PHASE3_ROLLOVER_REV) {
            $stats['subjectAssignments']['skipped']++;
            continue;
        }

        $payload = ($STRAT_ASGN === 'blank')
            ? ['schoolId' => $SCHOOL, 'session' => $TO, 'classKey' => $cls, 'sectionKey' => $sec, 'subjectCode' => $sc]
            : array_merge($d, ['session' => $TO]);
        $payload['carriedFromSession']  = $FROM;
        $payload['carriedAt']           = $now;
        $payload['phase3RolloverRev']   = PHASE3_ROLLOVER_REV;
        $payload['updatedAt']           = $now;
        $payload['updatedByUid']        = 'system_phase3_rollover';
        $payload['updatedByName']       = 'Phase 3 Session Rollover';

        if ($APPLY) {
            try { $fs->setDocument('subjectAssignments', $dstId, $payload); $stats['subjectAssignments']['created']++; }
            catch (\Throwable $e) { $stats['subjectAssignments']['failed']++; }
        } else {
            $stats['subjectAssignments']['created']++;
        }
        if ($VERBOSE) echo dim("  {$row['id']} → $dstId\n");
    }
    $s = $stats['subjectAssignments'];
    echo "  scanned={$s['scanned']}  created={$s['created']}  skipped={$s['skipped']}  failed={$s['failed']}\n";
}

// ── 3. timetables ───────────────────────────────────────────────────
if ($STRAT_TT !== 'skip') {
    echo bold("▸ timetables") . "\n";
    try {
        $rows = $fs->query('timetables', [
            ['schoolId', '==', $SCHOOL],
            ['session',  '==', $FROM],
        ], null, 'ASC', 5000);
    } catch (\Throwable $e) {
        echo red("  query failed: " . $e->getMessage() . "\n");
        $rows = [];
    }
    foreach ($rows as $row) {
        $stats['timetables']['scanned']++;
        $d = is_array($row['data'] ?? null) ? $row['data'] : $row;

        $cls = $d['className']  ?? '';
        $sec = $d['section']    ?? '';
        $day = $d['day']        ?? '';
        if ($cls === '' || $sec === '' || $day === '') {
            $stats['timetables']['skipped']++;
            $stats['timetables']['warnings'][] = "missing className/section/day on {$row['id']}";
            continue;
        }
        $safeKey = str_replace('/', '_', "{$cls}_{$sec}");
        $dstId = "{$SCHOOL}_{$TO}_{$safeKey}_{$day}";

        try { $existing = $fs->getDocument('timetables', $dstId); }
        catch (\Throwable $e) { $existing = null; }
        if (is_array($existing) && (int)($existing['phase3RolloverRev'] ?? 0) >= PHASE3_ROLLOVER_REV) {
            $stats['timetables']['skipped']++;
            continue;
        }

        $payload = ($STRAT_TT === 'blank')
            ? ['schoolId' => $SCHOOL, 'session' => $TO, 'className' => $cls, 'section' => $sec, 'day' => $day, 'periods' => []]
            : array_merge($d, ['session' => $TO]);
        // Reset volatile state on rollover.
        $payload['manuallyEdited']      = false;
        $payload['version']             = 1;
        $payload['carriedFromSession']  = $FROM;
        $payload['carriedAt']           = $now;
        $payload['phase3RolloverRev']   = PHASE3_ROLLOVER_REV;
        $payload['updatedAt']           = $now;
        $payload['updatedByUid']        = 'system_phase3_rollover';
        // Strip generation metadata — new session may regenerate.
        unset($payload['generationId'], $payload['generatedAt'], $payload['generatedByUid'], $payload['generatedByName']);

        if ($APPLY) {
            try { $fs->setDocument('timetables', $dstId, $payload); $stats['timetables']['created']++; }
            catch (\Throwable $e) { $stats['timetables']['failed']++; }
        } else {
            $stats['timetables']['created']++;
        }
        if ($VERBOSE) echo dim("  {$row['id']} → $dstId\n");
    }
    $s = $stats['timetables'];
    echo "  scanned={$s['scanned']}  created={$s['created']}  skipped={$s['skipped']}  failed={$s['failed']}\n";
}

// ── 4. curriculum (most complex — has subcollection) ────────────────
if ($STRAT_CURR !== 'skip') {
    echo bold("▸ curriculum") . "\n";
    try {
        $rows = $fs->query('curriculum', [
            ['schoolId', '==', $SCHOOL],
            ['session',  '==', $FROM],
        ], null, 'ASC', 5000);
    } catch (\Throwable $e) {
        echo red("  query failed: " . $e->getMessage() . "\n");
        $rows = [];
    }
    foreach ($rows as $row) {
        $stats['curriculum']['scanned']++;
        $srcParent = is_array($row['data'] ?? null) ? $row['data'] : $row;

        $cs   = $srcParent['classSection'] ?? '';
        $subj = $srcParent['subject']      ?? '';
        if ($cs === '' || $subj === '') {
            $stats['curriculum']['skipped']++;
            $stats['curriculum']['warnings'][] = "missing classSection/subject on {$row['id']}";
            continue;
        }
        $safeCs   = str_replace([' ','/'], '_', $cs);
        $safeSubj = str_replace([' ','/'], '_', $subj);
        $dstId    = "{$SCHOOL}_{$TO}_{$safeCs}_{$safeSubj}";

        try { $existing = $fs->getDocument('curriculum', $dstId); }
        catch (\Throwable $e) { $existing = null; }
        if (is_array($existing) && (int)($existing['phase3RolloverRev'] ?? 0) >= PHASE3_ROLLOVER_REV) {
            $stats['curriculum']['skipped']++;
            continue;
        }

        // Read source topics. If parent is in subcollection mode, fetch the
        // subcollection; if it's legacy array mode, use parent.topics[].
        $sourceTopics = [];
        $srcMode = $srcParent['topicsModel'] ?? 'array';
        if ($srcMode === 'subcollection' && is_array($srcParent['topicIds'] ?? null)) {
            $reqs = [];
            foreach ($srcParent['topicIds'] as $tid) {
                if (!is_string($tid) || $tid === '') continue;
                $reqs[$tid] = ['collection' => "curriculum/{$row['id']}/topics", 'docId' => $tid];
            }
            try { $fetched = $fs->getDocumentsParallel($reqs); }
            catch (\Throwable $e) { $fetched = []; }
            foreach ($srcParent['topicIds'] as $tid) {
                $t = $fetched[$tid] ?? null;
                if (is_array($t)) $sourceTopics[] = $t;
            }
        } elseif (is_array($srcParent['topics'] ?? null)) {
            $sourceTopics = $srcParent['topics'];
        }

        if ($STRAT_CURR === 'blank') {
            $sourceTopics = [];
        }

        // Build target subcollection ops + parent payload.
        $newTopicIds = [];
        $ops = [];
        $topicColl = "curriculum/{$dstId}/topics";

        foreach ($sourceTopics as $i => $st) {
            if (!is_array($st)) continue;
            $tid = uuid();
            $newTopicIds[] = $tid;
            $ops[] = [
                'op' => 'set', 'collection' => $topicColl, 'docId' => $tid, 'merge' => false,
                'data' => [
                    'topicId'        => $tid,
                    'parentDocId'    => $dstId,
                    'schoolId'       => $SCHOOL,
                    'title'          => (string)($st['title']   ?? ''),
                    'chapter'        => (string)($st['chapter'] ?? ''),
                    'estPeriods'     => (int)   ($st['estPeriods']    ?? $st['est_periods']    ?? 0),
                    // Always reset on rollover — new session = fresh slate.
                    'status'         => 'not_started',
                    'completedDate'  => '',
                    'sortOrder'      => $i,
                    'createdAt'      => $now,
                    'createdByUid'   => 'system_phase3_rollover',
                    'createdByName'  => 'Phase 3 Session Rollover',
                    'updatedAt'      => $now,
                    'updatedByUid'   => 'system_phase3_rollover',
                    'updatedByName'  => 'Phase 3 Session Rollover',
                    'version'        => 1,
                ],
            ];
        }

        $parentPayload = [
            'schoolId'           => $SCHOOL,
            'session'            => $TO,
            'classSection'       => $cs,
            'subject'            => $subj,
            'topicsModel'        => 'subcollection',
            'topicIds'           => $newTopicIds,
            'totalTopics'        => count($newTopicIds),
            'completedTopics'    => 0,
            'percentComplete'    => 0,
            'updatedAt'          => $now,
            'updatedByUid'       => 'system_phase3_rollover',
            'updatedByName'      => 'Phase 3 Session Rollover',
            'version'            => 1,
            'carriedFromSession' => $FROM,
            'carriedAt'          => $now,
            'phase3RolloverRev'  => PHASE3_ROLLOVER_REV,
            // Phase 1 marker preserved (this doc IS phase-1 shape).
            'phase1MigrationRev' => 1,
            'phase1MigratedAt'   => $now,
            // Legacy `topics` array intentionally NOT carried — fresh subcollection only.
        ];
        $ops[] = [
            'op' => 'set', 'collection' => 'curriculum', 'docId' => $dstId, 'merge' => false,
            'data' => $parentPayload,
        ];

        if ($APPLY) {
            $ok = false;
            try { $ok = $fs->commitBatch($ops); }
            catch (\Throwable $e) { $stats['curriculum']['warnings'][] = "batch fail $dstId: ".$e->getMessage(); }
            if ($ok) $stats['curriculum']['created']++;
            else     $stats['curriculum']['failed']++;
        } else {
            $stats['curriculum']['created']++;
        }
        if ($VERBOSE) echo dim("  {$row['id']} → $dstId  (".count($newTopicIds)." topic(s))\n");
    }
    $s = $stats['curriculum'];
    echo "  scanned={$s['scanned']}  created={$s['created']}  skipped={$s['skipped']}  failed={$s['failed']}\n";
}

// ── Footer ──────────────────────────────────────────────────────────
echo HR . "\n";
echo bold("Done") . "\n";
$totalCreated = $stats['curriculum']['created'] + $stats['timetables']['created']
              + $stats['subjectAssignments']['created'] + $stats['timetableSettings']['created'];
$totalFailed  = $stats['curriculum']['failed']  + $stats['timetables']['failed']
              + $stats['subjectAssignments']['failed']  + $stats['timetableSettings']['failed'];
echo "  total created : $totalCreated\n";
echo "  total failed  : $totalFailed\n";

// Surface warnings
foreach (['curriculum','timetables','subjectAssignments','timetableSettings'] as $coll) {
    if (!empty($stats[$coll]['warnings'])) {
        echo "\n" . yel("Warnings — $coll:") . "\n";
        foreach ($stats[$coll]['warnings'] as $w) echo "  • $w\n";
    }
}

if (!$APPLY) {
    echo "\n" . yel("DRY-RUN — no writes performed. Re-run with --apply to commit.") . "\n";
} else {
    echo "\n" . grn("Applied. Re-run dry-run to verify idempotency.") . "\n";

    // Phase 4 — audit log entry for the rollover itself.
    // (Best-effort — failure here doesn't break the operation.)
    try {
        $auditTs   = date('c');
        $compact   = preg_replace('/[^0-9T]/', '', $auditTs);
        if (strlen($compact) > 15) $compact = substr($compact, 0, 15);
        $auditId   = "{$SCHOOL}_{$compact}_" . bin2hex(random_bytes(4));
        $fs->setDocument('academicAuditLog', $auditId, [
            'logId'      => $auditId,
            'schoolId'   => $SCHOOL,
            'session'    => $TO,
            'ts'         => $auditTs,
            'createdAt'  => $auditTs,
            'action'     => 'rollover',
            'entityType' => 'curriculum',
            'entityId'   => "{$SCHOOL}_{$TO}",
            'actor'      => ['uid' => 'system_phase3_rollover', 'name' => 'Phase 3 Session Rollover', 'role' => 'system'],
            'before'     => null,
            'after'      => null,
            'metadata'   => [
                'fromSession'        => $FROM,
                'toSession'          => $TO,
                'totalCreated'       => $totalCreated,
                'totalFailed'        => $totalFailed,
                'curriculumCount'    => $stats['curriculum']['created'],
                'timetablesCount'    => $stats['timetables']['created'],
                'assignmentsCount'   => $stats['subjectAssignments']['created'],
                'settingsCount'      => $stats['timetableSettings']['created'],
                'curriculumStrategy' => $STRAT_CURR,
                'timetableStrategy'  => $STRAT_TT,
                'assignmentStrategy' => $STRAT_ASGN,
                'settingsStrategy'   => $STRAT_SETT,
            ],
        ]);
    } catch (\Throwable $e) {
        fwrite(STDERR, "[warn] audit log write failed: " . $e->getMessage() . "\n");
    }
}
exit($totalFailed > 0 ? 1 : 0);
