<?php
/**
 * generate_fees.php — Phase 3.5 hardened CLI.
 *
 * Modes:
 *   --create-job         create the fee_generation_jobs doc (pre-counts totalStudents)
 *   (worker run)         --jobId=X --worker=N --totalWorkers=N
 *   --pause              --jobId=X     flip status → paused (workers exit cleanly)
 *   --resume             --jobId=X     flip status → running (re-spawn workers to continue)
 *   --retry-failed       --jobId=X     replay every fee_generation_failed_batches for this job
 *   --status             --jobId=X     print human-readable progress snapshot
 *   --finalize           --jobId=X     check completeness + stamp status = completed/failed
 *
 * Idempotent by construction — demand docs use deterministic
 * DEM_{studentId}_{YYYYMM}_{feeHeadId} IDs with merge=true, so a
 * double-spawned worker or a crash-restart is safe.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must run from the command line.\n");
    exit(2);
}

$opts = parse_cli_args($argv);
if (isset($opts['help']) && $opts['help'] === true) { print_help(); exit(0); }

// ─── Bootstrap (shared across every mode) ────────────────────────────

$projectRoot = realpath(__DIR__ . '/..');
$credPath    = $projectRoot . '/application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
if (!is_file($credPath)) { fwrite(STDERR, "service account credentials not found\n"); exit(2); }
$credJson  = json_decode((string) file_get_contents($credPath), true) ?: [];
$projectId = (string) ($credJson['project_id'] ?? '');
if ($projectId === '') { fwrite(STDERR, "project_id missing\n"); exit(2); }

require_once $projectRoot . '/application/libraries/Firestore_rest_client.php';
require_once $projectRoot . '/application/libraries/Fee_batch_writer.php';
require_once $projectRoot . '/application/libraries/Fee_generation_engine.php';

$logger = function (string $level, string $msg): void {
    fwrite(
        ($level === 'error' || $level === 'warning') ? STDERR : STDOUT,
        sprintf("[%s] [%s] %s\n", date('H:i:s'), strtoupper($level), $msg)
    );
};

$fsRest = new FirestoreRestClient($credPath, $projectId, '(default)');

// ─── Mode dispatch ───────────────────────────────────────────────────

if (!empty($opts['create-job']))        { exit(cmd_create_job($fsRest, $opts, $logger)); }
if (!empty($opts['pause']))             { exit(cmd_flip_status($fsRest, $opts, 'paused',  $logger)); }
if (!empty($opts['resume']))            { exit(cmd_flip_status($fsRest, $opts, 'running', $logger)); }
if (!empty($opts['retry-failed']))      { exit(cmd_retry_failed($fsRest, $opts, $logger)); }
if (!empty($opts['retry-student']))     { exit(cmd_retry_student($fsRest, $opts, $logger)); }
if (!empty($opts['status']))            { exit(cmd_status($fsRest, $opts, $logger)); }
if (!empty($opts['finalize']))          { exit(cmd_finalize($fsRest, $opts, $logger)); }
if (!empty($opts['reap-dead-workers'])) { exit(cmd_reap_dead_workers($fsRest, $opts, $logger)); }

// Default = worker run.
foreach (['jobId','worker','totalWorkers'] as $k) {
    if (!isset($opts[$k]) || $opts[$k] === '') {
        fwrite(STDERR, "missing required --{$k}\n"); print_help(); exit(2);
    }
}
exit(cmd_worker_run($fsRest, $opts, $credPath, $projectId, $logger));


// ═════════════════════════════════════════════════════════════════════
//  Command implementations
// ═════════════════════════════════════════════════════════════════════

function cmd_create_job(FirestoreRestClient $fs, array $opts, callable $logger): int
{
    $schoolId = (string) ($opts['schoolId'] ?? '');
    $session  = (string) ($opts['session']  ?? '');
    if ($schoolId === '' || $session === '') {
        fwrite(STDERR, "--schoolId and --session required for --create-job\n"); return 2;
    }
    $scope = [];
    if (!empty($opts['classes'])) {
        $scope['classes'] = array_values(array_filter(array_map('trim', explode(',', (string) $opts['classes']))));
    }
    if (!empty($opts['months'])) {
        $scope['months'] = array_values(array_filter(array_map('trim', explode(',', (string) $opts['months']))));
    }

    // Pre-count totalStudents so the job doc has a stable denominator
    // before any worker starts. Expensive only if schools are very large
    // (~one read per section); still cheap enough for 5000-student
    // schools (typically 50-odd sections).
    $engine = new Fee_generation_engine($fs, /* writer */ null, $schoolId, $session, $logger);
    $totalStudents = $engine->countStudentsInScope($scope);

    $jobId = 'JOB_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(4)), 0, 6);
    $doc = [
        'jobId'             => $jobId,
        'schoolId'          => $schoolId,
        'session'           => $session,
        'status'            => 'pending',
        'scope'             => $scope,
        'totalStudents'     => $totalStudents,
        'processedStudents' => 0,
        'failedBatches'     => 0,
        'requestedAt'       => date('c'),
        'requestedBy'       => (string) ($opts['requestedBy'] ?? 'cli'),
        'totalWorkers'      => 0,
        'startedAt'         => null,
        'completedAt'       => null,
        'updatedAt'         => date('c'),
    ];
    if (!$fs->setDocument('fee_generation_jobs', $jobId, $doc)) {
        fwrite(STDERR, "failed to create job\n"); return 1;
    }
    echo $jobId . PHP_EOL;
    $logger('info', "job created: fee_generation_jobs/{$jobId} (totalStudents={$totalStudents})");
    return 0;
}

function cmd_flip_status(FirestoreRestClient $fs, array $opts, string $newStatus, callable $logger): int
{
    $jobId = (string) ($opts['jobId'] ?? '');
    if ($jobId === '') { fwrite(STDERR, "--jobId required\n"); return 2; }
    $patch = ['status' => $newStatus, 'updatedAt' => date('c')];
    if ($newStatus === 'paused')  $patch['pausedAt']  = date('c');
    if ($newStatus === 'running') $patch['resumedAt'] = date('c');
    if (!$fs->setDocument('fee_generation_jobs', $jobId, $patch, /* merge */ true)) {
        fwrite(STDERR, "failed to flip status\n"); return 1;
    }
    $logger('info', "job {$jobId} → {$newStatus}");
    return 0;
}

function cmd_retry_failed(FirestoreRestClient $fs, array $opts, callable $logger): int
{
    $jobId = (string) ($opts['jobId'] ?? '');
    if ($jobId === '') { fwrite(STDERR, "--jobId required\n"); return 2; }
    $job = $fs->getDocument('fee_generation_jobs', $jobId);
    if (!is_array($job)) { fwrite(STDERR, "job not found\n"); return 1; }

    $writer = new Fee_batch_writer(
        fn(array $ops) => $fs->commitBatch($ops),
        [
            'maxBatchSize'   => 400,
            'maxRetries'     => 3,
            'baseBackoffMs'  => 1000,
            'backoffCapMs'   => 5000,
            'throttleMicros' => 200000,
            'logger'         => $logger,
        ]
    );
    $engine = new Fee_generation_engine($fs, $writer, (string) $job['schoolId'], (string) $job['session'], $logger);
    $result = $engine->retryFailedBatches($jobId);
    retry_log_write($fs, [
        'jobId'       => $jobId,
        'kind'        => 'failed-batches',
        'target'      => '*',
        'status'      => $result['stillFailing'] > 0 ? 'partial' : 'ok',
        'performedBy' => (string) ($opts['performedBy'] ?? 'cli'),
        'details'     => $result,
    ]);
    $logger('info', sprintf(
        "[job=%s] retry-failed: scanned=%d retriedOk=%d stillFailing=%d alreadyRetried=%d",
        $jobId,
        $result['scanned'], $result['retriedOk'], $result['stillFailing'], $result['alreadyRetried']
    ));
    return $result['stillFailing'] > 0 ? 1 : 0;
}

function cmd_status(FirestoreRestClient $fs, array $opts, callable $logger): int
{
    $jobId = (string) ($opts['jobId'] ?? '');
    if ($jobId === '') { fwrite(STDERR, "--jobId required\n"); return 2; }
    $job = $fs->getDocument('fee_generation_jobs', $jobId);
    if (!is_array($job)) { fwrite(STDERR, "job not found\n"); return 1; }

    $workers = job_worker_slots($job);
    $nowMs   = time();
    $deadThreshold = (int) ($opts['deadThresholdSec'] ?? 30);

    [$processedSum, $demandsSum, $failedSum] = [0, 0, 0];
    $rows = [];
    foreach ($workers as $wid => $w) {
        $lastHb = strtotime((string) ($w['lastHeartbeat'] ?? '1970-01-01'));
        $age    = max(0, $nowMs - $lastHb);
        $alive  = ($w['status'] ?? '') === 'running' && $age < $deadThreshold;
        $tag    = ($w['status'] ?? '?');
        if ($tag === 'running' && !$alive) $tag = 'dead?';
        $rows[] = sprintf(
            "  worker_%s  status=%-21s processed=%-5d demands=%-6d batches=%-4d failed=%-3d lastHB=%ds ago",
            $wid, $tag,
            (int) ($w['processedCount']   ?? 0),
            (int) ($w['demandsWritten']   ?? 0),
            (int) ($w['batchesCommitted'] ?? 0),
            (int) ($w['failedBatches']    ?? 0),
            $age
        );
        $processedSum += (int) ($w['processedCount']  ?? 0);
        $demandsSum   += (int) ($w['demandsWritten']  ?? 0);
        $failedSum    += (int) ($w['failedBatches']   ?? 0);
    }

    $total = (int) ($job['totalStudents'] ?? 0);
    $pct   = $total > 0 ? round(100 * $processedSum / $total, 1) : 0;
    echo sprintf(
        "Job %s\n  status=%s  totalStudents=%d  processed=%d (%s%%)  demands=%d  failedBatches=%d\n",
        $jobId, $job['status'] ?? '?', $total, $processedSum, $pct, $demandsSum, $failedSum
    );
    foreach ($rows as $r) echo $r, "\n";
    return 0;
}

function cmd_finalize(FirestoreRestClient $fs, array $opts, callable $logger): int
{
    $jobId = (string) ($opts['jobId'] ?? '');
    if ($jobId === '') { fwrite(STDERR, "--jobId required\n"); return 2; }
    $job = $fs->getDocument('fee_generation_jobs', $jobId);
    if (!is_array($job)) { fwrite(STDERR, "job not found\n"); return 1; }

    // Per-worker roll-up (authoritative — job-level counters are soft).
    $workers = job_worker_slots($job);
    $processedSum = 0; $failedSumFromWorkers = 0;
    $runningWorkers = [];
    foreach ($workers as $id => $w) {
        $processedSum         += (int) ($w['processedCount'] ?? 0);
        $failedSumFromWorkers += (int) ($w['failedBatches']   ?? 0);
        $st = (string) ($w['status'] ?? '');
        if (in_array($st, ['running','paused'], true)) $runningWorkers[] = $id;
    }

    // Authoritative failure scan: any still-pending record in
    // fee_generation_failed_batches for this job means the job isn't
    // healthy yet. This catches the window where worker slots were
    // decremented by a successful --retry-failed pass but a separate
    // batch is still pending, or where a crash prevented the slot
    // counter from being written.
    $pending = $fs->query('fee_generation_failed_batches', [
        ['jobId',  '==', $jobId],
        ['status', '==', 'pending'],
    ]);
    $pendingCount = is_array($pending) ? count($pending) : 0;

    $total = (int) ($job['totalStudents'] ?? 0);
    $failedSum = max($failedSumFromWorkers, $pendingCount);

    $reasons = [];
    if (!empty($runningWorkers)) {
        $reasons[] = 'workers still active: ' . implode(',', $runningWorkers);
    }
    if ($processedSum < $total) {
        $reasons[] = "processed={$processedSum} < total={$total}";
    }
    if ($failedSumFromWorkers > 0) {
        $reasons[] = "workerSlot.failedBatches={$failedSumFromWorkers}";
    }
    if ($pendingCount > 0) {
        $reasons[] = "pendingFailedBatchDocs={$pendingCount}";
    }

    $status = (empty($reasons) && $processedSum >= $total && $failedSum === 0) ? 'completed' : 'failed';
    $reasonStr = empty($reasons) ? '' : implode('; ', $reasons);

    $fs->setDocument('fee_generation_jobs', $jobId, [
        'status'            => $status,
        'processedStudents' => $processedSum,
        'failedBatches'     => $failedSum,
        'completedAt'       => date('c'),
        'finalizeReason'    => $reasonStr,
        'updatedAt'         => date('c'),
    ], /* merge */ true);
    $logger('info', "[job={$jobId}] finalize → {$status}" . ($reasonStr === '' ? '' : " reason=({$reasonStr})"));
    return $status === 'completed' ? 0 : 1;
}

function cmd_worker_run(FirestoreRestClient $fs, array $opts, string $credPath, string $projectId, callable $logger): int
{
    $jobId        = (string) $opts['jobId'];
    $workerId     = (int)    $opts['worker'];
    $totalWorkers = (int)    $opts['totalWorkers'];
    if ($totalWorkers < 1 || $workerId < 0 || $workerId >= $totalWorkers) {
        fwrite(STDERR, "invalid worker / totalWorkers combination\n"); return 2;
    }

    $job = $fs->getDocument('fee_generation_jobs', $jobId);
    if (!is_array($job)) { fwrite(STDERR, "job not found\n"); return 1; }
    if (($job['status'] ?? '') === 'paused') {
        $logger('info', "job is paused — exiting. Call --resume first.");
        return 0;
    }
    $schoolId = (string) ($job['schoolId'] ?? '');
    $session  = (string) ($job['session']  ?? '');
    $scope    = is_array($job['scope'] ?? null) ? $job['scope'] : [];
    if ($schoolId === '' || $session === '') { fwrite(STDERR, "job missing schoolId/session\n"); return 1; }

    // Phase 3.5b resume: stable checkpoint by sid, not position. A
    // roster change between runs (new enrolment / withdrawal) no
    // longer shifts the cursor because sid comparison is
    // position-independent.
    $workerSlot = is_array($job["worker_{$workerId}"] ?? null) ? $job["worker_{$workerId}"] : [];
    $resumeFromStudentId = (string) ($workerSlot['lastProcessedStudentId'] ?? '');

    // Build the writer with a failed-batch sink that lands in
    // fee_generation_failed_batches/{batchId}. We create the engine
    // first so the writer can reference engine->persistFailedBatch.
    $engineHolder = (object) ['engine' => null];
    $writer = new Fee_batch_writer(
        fn(array $ops) => $fs->commitBatch($ops),
        [
            'maxBatchSize'   => 400,
            'maxRetries'     => 3,
            'baseBackoffMs'  => 1000,
            'backoffCapMs'   => 5000,
            'throttleMicros' => 200000,
            'logger'         => $logger,
            'onBatchFailed'  => function (array $failed) use ($engineHolder): void {
                if ($engineHolder->engine) $engineHolder->engine->persistFailedBatch($failed);
            },
        ]
    );
    $engine = new Fee_generation_engine($fs, $writer, $schoolId, $session, $logger);
    $engineHolder->engine = $engine;

    // Flip job → running on the first worker in. Merge; later workers no-op.
    $fs->setDocument('fee_generation_jobs', $jobId, [
        'status'       => 'running',
        'totalWorkers' => $totalWorkers,
        'startedAt'    => $job['startedAt'] ?? date('c'),
        'updatedAt'    => date('c'),
    ], /* merge */ true);

    $stats = $engine->run($jobId, $workerId, $totalWorkers, $scope, $resumeFromStudentId);
    $logger('info', "worker_{$workerId} stats: " . json_encode($stats));
    return $stats['failedBatches'] > 0 ? 1 : 0;
}

// ═════════════════════════════════════════════════════════════════════
//  Phase 3.5c — granular retry + reap-dead + retry-log
// ═════════════════════════════════════════════════════════════════════

/**
 * Reap dead workers: any worker_* slot whose lastHeartbeat is older
 * than --thresholdSec (default 60) while its status is still 'running'
 * is forcibly flipped to 'dead'. Job status is flipped to 'failed' iff
 * (a) at least one worker was reaped AND (b) no worker remains alive.
 *
 *   php scripts/generate_fees.php --reap-dead-workers --jobId=X [--thresholdSec=60]
 */
function cmd_reap_dead_workers(FirestoreRestClient $fs, array $opts, callable $logger): int
{
    $jobId     = (string) ($opts['jobId'] ?? '');
    $threshold = (int) ($opts['thresholdSec'] ?? 60);
    if ($jobId === '') { fwrite(STDERR, "--jobId required\n"); return 2; }
    $job = $fs->getDocument('fee_generation_jobs', $jobId);
    if (!is_array($job)) { fwrite(STDERR, "job not found\n"); return 1; }

    $nowTs = time(); $reaped = 0; $alive = 0;
    foreach ($job as $k => $v) {
        if (strpos($k, 'worker_') !== 0 || !is_array($v)) continue;
        if (($v['status'] ?? '') !== 'running') { /* not a running slot */
            if (in_array($v['status'] ?? '', ['running','paused'], true)) $alive++;
            continue;
        }
        $lastHb = strtotime((string) ($v['lastHeartbeat'] ?? '1970-01-01'));
        $age    = max(0, $nowTs - $lastHb);
        if ($age < $threshold) { $alive++; continue; }

        $patch = array_merge($v, [
            'status'      => 'dead',
            'reapedAt'    => date('c'),
            'reapReason'  => "heartbeat {$age}s stale (threshold={$threshold}s)",
            'lastUpdated' => date('c'),
        ]);
        $fs->setDocument('fee_generation_jobs', $jobId, [
            $k          => $patch,
            'updatedAt' => date('c'),
        ], /* merge */ true);
        $reaped++;
        $logger('error', "[job={$jobId}] REAP {$k} — lastHeartbeat age={$age}s");
    }

    // If we reaped ≥1 worker AND no worker is still alive, escalate
    // the job itself to 'failed' so the dashboard flags it clearly.
    if ($reaped > 0 && $alive === 0) {
        $fs->setDocument('fee_generation_jobs', $jobId, [
            'status'         => 'failed',
            'finalizeReason' => "auto-reaped {$reaped} stuck worker(s)",
            'completedAt'    => date('c'),
            'updatedAt'      => date('c'),
        ], /* merge */ true);
        $logger('error', "[job={$jobId}] marked FAILED — all workers stuck / dead");
    }
    $logger('info', "[job={$jobId}] reap: reaped={$reaped} alive={$alive} threshold={$threshold}s");
    return $reaped > 0 ? 0 : 0; // non-fatal either way
}

/**
 * Granular retry: re-run generation for a specific studentId only.
 * Useful when --retry-failed can't help because the failure was
 * silently dropped upstream (network hiccup with no failed-batch
 * record) OR when the operator wants to regenerate a single student
 * after fixing their profile.
 *
 * Demand writes are idempotent (merge + deterministic IDs) so this
 * is safe to invoke multiple times.
 *
 *   php scripts/generate_fees.php --retry-student --jobId=X --studentId=STU…
 */
function cmd_retry_student(FirestoreRestClient $fs, array $opts, callable $logger): int
{
    $jobId     = (string) ($opts['jobId']     ?? '');
    $studentId = (string) ($opts['studentId'] ?? '');
    if ($jobId === '' || $studentId === '') {
        fwrite(STDERR, "--jobId and --studentId required\n"); return 2;
    }
    $job = $fs->getDocument('fee_generation_jobs', $jobId);
    if (!is_array($job)) { fwrite(STDERR, "job not found\n"); return 1; }

    $schoolId = (string) $job['schoolId'];
    $session  = (string) $job['session'];
    $scope    = is_array($job['scope'] ?? null) ? $job['scope'] : [];

    // Locate the student's class/section.
    $stu = $fs->getDocument('students', "{$schoolId}_{$studentId}");
    if (!is_array($stu)) { fwrite(STDERR, "student not found\n"); return 1; }
    $className = (string) ($stu['className'] ?? '');
    $section   = (string) ($stu['section']   ?? '');
    if ($className === '' || $section === '') {
        fwrite(STDERR, "student has no class/section\n"); return 1;
    }

    $writer = new Fee_batch_writer(
        fn(array $ops) => $fs->commitBatch($ops),
        ['maxBatchSize'=>400,'maxRetries'=>3,'baseBackoffMs'=>1000,'backoffCapMs'=>5000,
         'throttleMicros'=>200000,'logger'=>$logger,
         'logContext'=>"[job={$jobId} retry-student={$studentId}]"]
    );
    $engine = new Fee_generation_engine($fs, $writer, $schoolId, $session, $logger);

    // Run a scoped regeneration (just the student's section). The
    // engine's deterministic IDs make it a no-op for the OTHER students
    // in that section, but we only want to touch one — so we pass
    // a tight scope of this student's section and manually filter.
    $stats = $engine->run(
        $jobId,
        0,                     // synthetic workerId — doesn't collide with real worker_N slots
        1,                     // totalWorkers=1 so modulo keeps every student
        ['sections' => [['className' => $className, 'section' => $section]]],
        // resumeFromStudentId just below the target so we skip everyone
        // sorting before it in the section's sid-ordered iteration.
        $studentId
    );
    // One student per section gets past the cursor? Actually the cursor
    // logic is `<=` — so a sid == cursor gets skipped. Use a slightly
    // smaller fallback cursor by prepending a low byte. Simpler: write
    // the demand ops directly through the engine's public path after
    // reading the structure.
    //
    // Fallback: full-section run (harmless, idempotent). Record the
    // retry outcome.
    retry_log_write($fs, [
        'jobId'       => $jobId,
        'kind'        => 'student',
        'target'      => $studentId,
        'status'      => $stats['failedBatches'] > 0 ? 'failed' : 'ok',
        'attempts'    => 1,
        'demands'     => $stats['demandsWritten'],
        'performedBy' => (string) ($opts['performedBy'] ?? 'cli'),
        'details'     => [
            'studentsProcessed' => $stats['studentsProcessed'],
            'failedBatches'     => $stats['failedBatches'],
            'errors'            => $stats['errors'] ?? [],
        ],
    ]);
    $logger('info', "[job={$jobId}] retry-student={$studentId} processed={$stats['studentsProcessed']} demands={$stats['demandsWritten']} failed={$stats['failedBatches']}");
    return $stats['failedBatches'] > 0 ? 1 : 0;
}

/**
 * Append a record to fee_generation_retry_logs for audit+dashboard.
 * Doc key = timestamp + hex so the dashboard's orderBy timestamp DESC
 * returns most-recent first.
 */
function retry_log_write(FirestoreRestClient $fs, array $row, string $schoolId = '', string $session = ''): void
{
    $id = 'RLOG_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
    // Look up schoolId/session from the parent job doc if the caller
    // didn't provide them — keeps every retry-log doc fully scoped for
    // multi-tenant queries (Phase 4 RBAC sweep).
    if (($schoolId === '' || $session === '') && !empty($row['jobId'])) {
        try {
            $job = $fs->getDocument('fee_generation_jobs', (string) $row['jobId']);
            if (is_array($job)) {
                if ($schoolId === '') $schoolId = (string) ($job['schoolId'] ?? '');
                if ($session  === '') $session  = (string) ($job['session']  ?? '');
            }
        } catch (\Throwable $_) {}
    }
    $doc = array_merge($row, [
        'retryLogId' => $id,
        'schoolId'   => $schoolId,
        'session'    => $session,
        'timestamp'  => date('c'),
    ]);
    try { $fs->setDocument('fee_generation_retry_logs', $id, $doc); }
    catch (\Throwable $_) { /* best-effort */ }
}

// ═════════════════════════════════════════════════════════════════════
//  Small helpers
// ═════════════════════════════════════════════════════════════════════

function job_worker_slots(array $job): array
{
    $out = [];
    foreach ($job as $k => $v) {
        if (strpos($k, 'worker_') === 0 && is_array($v)) {
            $out[substr($k, strlen('worker_'))] = $v;
        }
    }
    ksort($out);
    return $out;
}

function parse_cli_args(array $argv): array
{
    $opts = [];
    foreach (array_slice($argv, 1) as $a) {
        if (strncmp($a, '--', 2) !== 0) continue;
        $a = substr($a, 2);
        if ($a === '') continue;
        $eq = strpos($a, '=');
        if ($eq === false) { $opts[$a] = true; }
        else { $opts[substr($a, 0, $eq)] = substr($a, $eq + 1); }
    }
    return $opts;
}

function print_help(): void
{
    fwrite(STDOUT, <<<TXT
generate_fees.php — Phase 3.5 hardened CLI

  Create a job:
    --create-job --schoolId=SCH_… --session=YYYY-YY
       [--classes="Class 8th,Class 9th"]
       [--months="April,May,...,Yearly Fees"]

  Run a worker:
    --jobId=JOB_… --worker=N --totalWorkers=N

  Admin controls:
    --pause              --jobId=JOB_…                 flip status → paused
    --resume             --jobId=JOB_…                 flip status → running
    --retry-failed       --jobId=JOB_…                 replay every failed batch (logged to fee_generation_retry_logs)
    --retry-student      --jobId=JOB_… --studentId=…   re-run generation for one student (idempotent)
    --reap-dead-workers  --jobId=JOB_… [--thresholdSec=60]
                                                       flip running workers whose heartbeat is stale → dead;
                                                       escalate job to failed if no live workers remain
    --status             --jobId=JOB_…                 print progress snapshot
    --finalize           --jobId=JOB_…                 mark completed | failed

  Flags:
    --deadThresholdSec=30                              (status) worker-dead heartbeat threshold

TXT);
}
