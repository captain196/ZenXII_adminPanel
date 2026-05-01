<?php
/**
 * Fee_generation_engine — Phase 3 worker-side demand generation
 * (Phase 3.5 hardened: heartbeat, pause, resume, failed-batch store).
 *
 * Produces camelCase-only feeDemands docs with the canonical Phase 2.5
 * shape (deterministic IDs: DEM_{studentId}_{YYYYMM}_{feeHeadId}), hands
 * them to Fee_batch_writer, and keeps fee_generation_jobs/{jobId} up to
 * date with per-worker progress AND heartbeat so a supervisor can spot
 * dead workers.
 *
 * Hardening added on top of the Phase 3 baseline:
 *   • TotalStudents enumeration (run once at job creation) so the job
 *     doc has a stable denominator from t=0.
 *   • Per-worker heartbeat updated every HEARTBEAT_INTERVAL_SEC; a
 *     supervisor treats workers as DEAD when lastHeartbeat drifts past
 *     a configured threshold.
 *   • Pause polling: worker reads the job doc every heartbeat tick; if
 *     status === 'paused' it flushes in-flight ops and exits cleanly.
 *   • Resume cursor: worker persists lastGlobalIdx after every flush,
 *     so a restart skips ahead to unprocessed work instead of walking
 *     the whole roster again. Because demand writes are idempotent
 *     (merge=true + deterministic IDs), even the worst case — running
 *     again without a cursor — is correctness-safe, just wasteful.
 *   • Failed-batch persistence: the writer hands every exhausted batch
 *     to `onBatchFailed`, which we route into
 *     fee_generation_failed_batches/{batchId} for the --retry-failed
 *     CLI path to replay later.
 *
 * CI-independent: talks to Firestore via an injected FirestoreRestClient
 * so the CLI worker can run without bootstrapping CodeIgniter.
 */

require_once __DIR__ . '/Fee_batch_writer.php';

final class Fee_generation_engine
{
    public const MONTHS = [
        'April','May','June','July','August','September',
        'October','November','December','January','February','March',
    ];

    /** How often to flush heartbeat + poll job status (in seconds). */
    public const HEARTBEAT_INTERVAL_SEC = 5;

    /** @var FirestoreRestClient */
    private $fs;
    /** @var Fee_batch_writer|null */
    private $writer = null;

    private string $schoolId;
    private string $session;

    private array $structCache   = [];
    private int   $dueDay        = 10;

    /** @var callable(string,string):void|null */
    private $logger;

    // Hot-path state (only valid inside run()).
    private string $currentJobId  = '';
    private int    $currentWorker = -1;
    private float  $lastHeartbeat = 0.0;
    private bool   $pauseRequested = false;

    public function __construct(
        $firestoreRestClient,
        ?Fee_batch_writer $writer,
        string $schoolId,
        string $session,
        ?callable $logger = null
    ) {
        $this->fs       = $firestoreRestClient;
        $this->writer   = $writer;
        $this->schoolId = $schoolId;
        $this->session  = $session;
        $this->logger   = $logger;
    }

    public function getWriter(): ?Fee_batch_writer { return $this->writer; }

    // ─────────────────────────────────────────────────────────────────
    // Job bootstrap — called once at --create-job time
    // ─────────────────────────────────────────────────────────────────

    /**
     * Enumerate the rosters covered by the job's scope and return the
     * total student count. Called at --create-job so the job doc has a
     * stable `totalStudents` denominator before any worker starts.
     *
     * Runs a single Firestore query per targeted section (fee structures
     * + students). Cheap for any realistic school size (<200 sections).
     */
    public function countStudentsInScope(array $scope = []): int
    {
        $sections = $this->resolveSections($scope);
        $count = 0;
        foreach ($sections as $cs) {
            $count += count($this->fetchRoster($cs['className'], $cs['section']));
        }
        return $count;
    }

    // ─────────────────────────────────────────────────────────────────
    // Main worker entry point
    // ─────────────────────────────────────────────────────────────────

    /**
     * Returns aggregate stats:
     *   [
     *     'workerId', 'totalWorkers',
     *     'studentsConsidered', 'studentsAssigned', 'studentsProcessed',
     *     'studentsSkippedByCursor',
     *     'demandsWritten', 'batchesCommitted', 'failedBatches',
     *     'failedBatchIds',
     *     'pausedCleanly' (bool),
     *     'elapsedSec',
     *   ]
     */
    /**
     * @param string $resumeFromStudentId  Phase 3.5b stable checkpoint:
     *        sid of the last student whose demand ops this worker has
     *        already committed. Resume skips any student whose sid is
     *        lexically ≤ this value. Replaces the fragile index-based
     *        cursor so a roster change between runs (new enrolment,
     *        student dropping out) doesn't corrupt resume.
     */
    public function run(
        string $jobId,
        int    $workerId,
        int    $totalWorkers,
        array  $scope = [],
        string $resumeFromStudentId = ''
    ): array {
        if ($this->writer === null) {
            throw new \RuntimeException('Fee_generation_engine: writer is required to run()');
        }
        $t0 = microtime(true);
        $this->currentJobId   = $jobId;
        $this->currentWorker  = $workerId;
        $this->lastHeartbeat  = 0.0;
        $this->pauseRequested = false;

        // Stitch the writer's batch logs with our worker context so
        // every commit/retry/failure line carries jobId+workerId.
        $this->writer->setLogContext("[job={$jobId} worker={$workerId}]");

        $this->log('info', "[job={$jobId} worker={$workerId}/{$totalWorkers}] start resumeFrom='{$resumeFromStudentId}'");

        $this->loadDueDay();
        $sections = $this->resolveSections($scope);
        $this->log('info', "[job={$jobId} worker={$workerId}] sections to scan: " . count($sections));

        $this->mergeWorkerSlot([
            'status'               => 'running',
            'startedAt'            => $this->nowIso(),
            'lastHeartbeat'        => $this->nowIso(),
            'sectionsTotal'        => count($sections),
            'resumedFromStudentId' => $resumeFromStudentId,
        ]);

        $stats = [
            'workerId'                   => $workerId,
            'totalWorkers'               => $totalWorkers,
            'studentsConsidered'         => 0,
            'studentsAssigned'           => 0,
            'studentsProcessed'          => 0,
            'studentsSkippedByCheckpoint'=> 0,
            'demandsWritten'             => 0,
            'batchesCommitted'           => 0,
            'failedBatches'              => 0,
            'failedBatchIds'             => [],
            'lastProcessedStudentId'     => $resumeFromStudentId,
            'pausedCleanly'              => false,
            'errors'                     => [],
        ];

        $months = $this->resolveMonths($scope);

        // Phase 3.5b — flatten the whole scope into a GLOBALLY sid-sorted
        // list so iteration order == sid order. This makes the
        // lastProcessedStudentId checkpoint a true high-water mark:
        // every sid we've passed is guaranteed ≤ the checkpoint, so
        // resume can skip on `sid <= checkpoint` with no index math.
        $allStudents = []; // list of ['sid','name','className','section','struct']
        foreach ($sections as $cs) {
            $struct = $this->loadStructure($cs['className'], $cs['section']);
            if (empty($struct)) {
                $this->log('warning', "[job={$jobId} worker={$workerId}] no structure for {$cs['className']}/{$cs['section']} — skipping");
                continue;
            }
            foreach ($this->fetchRoster($cs['className'], $cs['section']) as $student) {
                $sid = (string) ($student['studentId'] ?? $student['userId'] ?? '');
                if ($sid === '') continue;
                $allStudents[] = [
                    'sid'       => $sid,
                    'name'      => (string) ($student['name'] ?? $student['studentName'] ?? $sid),
                    'className' => $cs['className'],
                    'section'   => $cs['section'],
                    'struct'    => $struct,
                ];
            }
        }
        // Deterministic sid-order — the same on every run as long as no
        // student changes ID.
        usort($allStudents, fn($a, $b) => strcmp($a['sid'], $b['sid']));

        $batchOps  = [];
        $batchStudentIds = []; // sids queued in the current pending batch
        $batchSoftCap = 400;
        $globalIdx = 0; // kept for observability only

        foreach ($allStudents as $s) {
            $stats['studentsConsidered']++;
            $myIdx = $globalIdx;
            $globalIdx++;
            $mine = ($myIdx % $totalWorkers) === $workerId;
            if (!$mine) continue;
            $stats['studentsAssigned']++;

            $sid = $s['sid'];
            // Stable checkpoint: skip students we've already committed
            // ops for. Because iteration is sid-ordered, a strcmp
            // comparison IS the position check — no index shift risk
            // across roster changes.
            if ($resumeFromStudentId !== '' && strcmp($sid, $resumeFromStudentId) <= 0) {
                $stats['studentsSkippedByCheckpoint']++;
                continue;
            }

            $ops = $this->buildStudentDemandOps(
                $sid, $s['name'], $s['className'], $s['section'], $s['struct'], $months
            );
            foreach ($ops as $op) $batchOps[] = $op;
            $batchStudentIds[] = $sid;
            $stats['studentsProcessed']++;

            if (count($batchOps) >= $batchSoftCap) {
                $this->flushBatch($batchOps, $batchStudentIds, $stats, $globalIdx);
                $batchOps        = [];
                $batchStudentIds = [];
            }

            if ($this->heartbeatDue()) {
                $this->tickHeartbeat($stats, $globalIdx);
                if ($this->pauseRequested) {
                    $this->log('info', "[job={$jobId} worker={$workerId}] pause honoured after sid='{$stats['lastProcessedStudentId']}'");
                    break;
                }
            }
        }

        if (!empty($batchOps)) {
            $this->flushBatch($batchOps, $batchStudentIds, $stats, $globalIdx);
            $batchOps        = [];
            $batchStudentIds = [];
        }

        $stats['elapsedSec']    = round(microtime(true) - $t0, 2);
        $stats['pausedCleanly'] = $this->pauseRequested;

        $finalStatus = $this->pauseRequested
            ? 'paused'
            : ($stats['failedBatches'] > 0 ? 'completed_with_errors' : 'completed');

        $this->mergeWorkerSlot([
            'status'                 => $finalStatus,
            'processedCount'         => $stats['studentsProcessed'],
            'demandsWritten'         => $stats['demandsWritten'],
            'batchesCommitted'       => $stats['batchesCommitted'],
            'failedBatches'          => $stats['failedBatches'],
            'lastProcessedStudentId' => $stats['lastProcessedStudentId'],
            'lastGlobalIdx'          => $globalIdx, // retained for observability
            'lastHeartbeat'          => $this->nowIso(),
            'lastUpdated'            => $this->nowIso(),
            'finishedAt'             => $this->pauseRequested ? null : $this->nowIso(),
            'elapsedSec'             => $stats['elapsedSec'],
        ]);

        $this->log('info', sprintf(
            "[job=%s worker=%d] %s — considered=%d assigned=%d processed=%d skippedByCheckpoint=%d demands=%d batches=%d failed=%d lastSid='%s' in %.2fs",
            $jobId, $workerId, $finalStatus,
            $stats['studentsConsidered'], $stats['studentsAssigned'],
            $stats['studentsProcessed'], $stats['studentsSkippedByCheckpoint'],
            $stats['demandsWritten'], $stats['batchesCommitted'], $stats['failedBatches'],
            $stats['lastProcessedStudentId'], $stats['elapsedSec']
        ));
        return $stats;
    }

    // ─────────────────────────────────────────────────────────────────
    // Failed-batch replay (used by --retry-failed CLI)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Walk fee_generation_failed_batches/* for this jobId and replay
     * each. On success: mark record status='retried'. On failure:
     * increment attempts, leave status='pending', record the new error.
     */
    public function retryFailedBatches(string $jobId): array
    {
        if ($this->writer === null) {
            throw new \RuntimeException('Fee_generation_engine: writer is required for retryFailedBatches()');
        }
        $out = ['scanned' => 0, 'retriedOk' => 0, 'stillFailing' => 0, 'alreadyRetried' => 0, 'errors' => []];
        $rows = $this->fs->query('fee_generation_failed_batches', [
            ['jobId',  '==', $jobId],
            ['status', '==', 'pending'],
        ]);
        foreach ((array) $rows as $r) {
            $out['scanned']++;
            $d = $r['data'] ?? $r;
            $batchId = (string) ($r['id'] ?? ($d['batchId'] ?? ''));
            $ops     = is_array($d['ops'] ?? null) ? $d['ops'] : [];
            $workerId = (int) ($d['workerId'] ?? -1);
            if ($batchId === '' || empty($ops)) {
                $out['errors'][] = "{$batchId}: empty ops";
                $out['stillFailing']++;
                continue;
            }

            // Double-check status immediately before replay — the query
            // above is eventually-consistent and a concurrent
            // --retry-failed could have flipped this doc to 'retried'
            // in the window between our query and our replay. Skipping
            // at this point is the dedup that prevents double-count of
            // counters and duplicate commits against Firestore.
            $fresh = $this->fs->getDocument('fee_generation_failed_batches', $batchId);
            if (is_array($fresh) && ($fresh['status'] ?? '') !== 'pending') {
                $out['alreadyRetried']++;
                $this->log('info', "[job={$jobId} retry] skip {$batchId} — status='{$fresh['status']}' (raced another retry)");
                continue;
            }

            $this->log('info', "[job={$jobId} retry] replay {$batchId} ops=" . count($ops));
            $ok = $this->writer->replay($ops);
            $patch = [
                'attempts'      => (int) ($d['attempts'] ?? 0) + 1,
                'lastAttemptAt' => $this->nowIso(),
                'lastError'     => $ok ? '' : 'replay returned false',
            ];
            if ($ok) {
                $patch['status']    = 'retried';
                $patch['retriedAt'] = $this->nowIso();
                $out['retriedOk']++;
                $this->log('info', "[job={$jobId} retry] {$batchId} OK");
            } else {
                $out['stillFailing']++;
                $this->log('error', "[job={$jobId} retry] {$batchId} STILL FAILING (attempts now " . ($patch['attempts']) . ")");
            }
            $this->fs->setDocument('fee_generation_failed_batches', $batchId, $patch, /* merge */ true);

            // On first-success only: adjust counters. Because the status
            // flipped from 'pending' → 'retried' above, any subsequent
            // --retry-failed invocation hits the alreadyRetried branch
            // and never touches counters again. This is the idempotency
            // guarantee for the retry path.
            if ($ok) {
                $this->decrementWorkerSlotOnRetry($jobId, $workerId, count($ops));
                $this->bumpJobField('failedBatches', -1, /* increment */ true);
            }
        }
        return $out;
    }

    private function decrementWorkerSlotOnRetry(string $jobId, int $workerId, int $opsCount): void
    {
        if ($workerId < 0) return;
        try {
            $job = $this->fs->getDocument('fee_generation_jobs', $jobId);
            if (!is_array($job)) return;
            $slot = is_array($job["worker_{$workerId}"] ?? null) ? $job["worker_{$workerId}"] : [];
            $newFailed = max(0, (int) ($slot['failedBatches'] ?? 0) - 1);
            $priorStatus = (string) ($slot['status'] ?? 'completed');
            $newStatus = ($newFailed === 0 && $priorStatus === 'completed_with_errors')
                ? 'completed'
                : $priorStatus;
            // FULL MERGED MAP — see mergeWorkerSlot() for why we can't
            // send a partial map on a nested-field merge.
            $merged = array_merge($slot, [
                'failedBatches'    => $newFailed,
                'batchesCommitted' => (int) ($slot['batchesCommitted'] ?? 0) + 1,
                'demandsWritten'   => (int) ($slot['demandsWritten']   ?? 0) + $opsCount,
                'status'           => $newStatus,
                'lastUpdated'      => $this->nowIso(),
            ]);
            $this->fs->setDocument('fee_generation_jobs', $jobId, [
                "worker_{$workerId}" => $merged,
                'updatedAt' => $this->nowIso(),
            ], /* merge */ true);
        } catch (\Throwable $_) { /* best-effort */ }
    }

    // ─────────────────────────────────────────────────────────────────
    // Persistence hooks (passed to Fee_batch_writer + retry)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Callback wired into Fee_batch_writer::$onBatchFailed. Persists
     * the failing payload so --retry-failed can replay it later.
     */
    public function persistFailedBatch(array $failedBatch): void
    {
        $batchId = (string) ($failedBatch['batchId'] ?? Fee_batch_writer::makeBatchId());
        $ok = $this->fs->setDocument('fee_generation_failed_batches', $batchId, [
            'batchId'         => $batchId,
            'jobId'           => $this->currentJobId,
            'workerId'        => $this->currentWorker,
            'schoolId'        => $this->schoolId,
            'session'         => $this->session,
            'ops'             => $failedBatch['ops'] ?? [],
            'opsCount'        => is_array($failedBatch['ops'] ?? null) ? count($failedBatch['ops']) : 0,
            'attempts'        => (int) ($failedBatch['attempts'] ?? 0),
            'lastError'       => (string) ($failedBatch['lastError'] ?? ''),
            'firstAttemptAt'  => (string) ($failedBatch['firstAttemptAt'] ?? $this->nowIso()),
            'lastAttemptAt'   => (string) ($failedBatch['lastAttemptAt']  ?? $this->nowIso()),
            'status'          => 'pending',
            'createdAt'       => $this->nowIso(),
        ]);
        if (!$ok) {
            $this->log('error', "failed to persist failed-batch record {$batchId}");
        } else {
            $this->log('warning', "persisted failed-batch {$batchId} ({$failedBatch['lastError']})");
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Section / roster / structure helpers
    // ─────────────────────────────────────────────────────────────────

    /** Exposed for the CLI status command. */
    public function resolveSections(array $scope): array
    {
        if (!empty($scope['sections']) && is_array($scope['sections'])) {
            return array_values(array_map(fn($s) => [
                'className' => (string) ($s['className'] ?? $s['class'] ?? ''),
                'section'   => (string) ($s['section'] ?? ''),
            ], $scope['sections']));
        }
        $rows = $this->fs->query('feeStructures', [
            ['schoolId', '==', $this->schoolId],
            ['session',  '==', $this->session],
        ]);
        $out = [];
        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? $r;
            if (!is_array($d)) continue;
            $c = (string) ($d['className'] ?? '');
            $s = (string) ($d['section']   ?? '');
            if ($c === '' || $s === '') continue;
            if (!empty($scope['classes']) && !in_array($c, $scope['classes'], true)) continue;
            $out[] = ['className' => $c, 'section' => $s];
        }
        // Deterministic iteration order — critical for the partition
        // math to agree across restarts and workers.
        usort($out, fn($a, $b) => strcmp(
            $a['className'] . '|' . $a['section'],
            $b['className'] . '|' . $b['section']
        ));
        return $out;
    }

    private function resolveMonths(array $scope): array
    {
        if (!empty($scope['months']) && is_array($scope['months'])) {
            return array_values(array_unique(array_map('strval', $scope['months'])));
        }
        return self::MONTHS;
    }

    private function fetchRoster(string $className, string $section): array
    {
        $rows = $this->fs->query('students', [
            ['schoolId',  '==', $this->schoolId],
            ['className', '==', $className],
            ['section',   '==', $section],
        ]);
        $out = [];
        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? $r;
            if (!is_array($d)) continue;
            $sid = (string) ($d['studentId'] ?? $d['userId'] ?? ($r['id'] ?? ''));
            if ($sid === '') continue;
            $out[$sid] = $d;
        }
        ksort($out); // stable partition
        return array_values($out);
    }

    private function loadStructure(string $className, string $section): array
    {
        $key = $className . '|' . $section;
        if (isset($this->structCache[$key])) return $this->structCache[$key];

        $docId = "{$this->schoolId}_{$this->session}_{$className}_{$section}";
        $doc = $this->fs->getDocument('feeStructures', $docId);
        if (!is_array($doc) || !is_array($doc['feeHeads'] ?? null)) {
            return $this->structCache[$key] = [];
        }
        $heads    = [];
        $idByName = [];
        foreach ($doc['feeHeads'] as $h) {
            if (!is_array($h)) continue;
            $name = trim((string) ($h['name'] ?? ''));
            $amt  = (float) ($h['amount'] ?? 0);
            $freq = strtolower((string) ($h['frequency'] ?? 'monthly'));
            $fid  = trim((string) ($h['feeHeadId'] ?? ''));
            if ($name === '' || $amt <= 0) continue;
            $heads[] = compact('name','amt','freq','fid');
            if ($fid !== '') $idByName[$name] = $fid;
        }
        return $this->structCache[$key] = ['heads' => $heads, 'idByName' => $idByName];
    }

    private function loadDueDay(): void
    {
        $doc = $this->fs->getDocument('feeSettings', "{$this->schoolId}_{$this->session}_reminders");
        if (is_array($doc) && isset($doc['due_day_of_month'])) {
            $v = (int) $doc['due_day_of_month'];
            if ($v >= 1 && $v <= 28) $this->dueDay = $v;
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Demand-doc builder (unchanged from Phase 3 — still camelCase + feeHeadId IDs)
    // ─────────────────────────────────────────────────────────────────

    private function buildStudentDemandOps(
        string $studentId, string $studentName,
        string $className, string $section,
        array  $struct, array $months
    ): array {
        $ops = [];
        [$heads, $idByName] = [$struct['heads'], $struct['idByName']];
        if (empty($heads)) return $ops;

        $sessionYear = $this->session;
        $now         = $this->nowIso();
        $sectionKey  = preg_replace('/^Section\s+/i', '', $section);

        $monthsSet   = array_flip($months);
        $includesApr = isset($monthsSet['April']);

        foreach ($heads as $h) {
            $isYearly = in_array($h['freq'], ['annual','yearly','one-time','onetime'], true);
            $headName = $h['name'];
            $headId   = $h['fid'] !== '' ? $h['fid'] : ($idByName[$headName] ?? '');

            if ($isYearly) {
                if (!$includesApr) continue;
                $ops[] = $this->makeDemandOp(
                    $studentId, $studentName, $className, $section, $sectionKey,
                    $headName, $headId, 'yearly', $h['amt'],
                    'April', $sessionYear, $now
                );
            } else {
                foreach ($months as $m) {
                    if ($m === 'Yearly Fees') continue;
                    $ops[] = $this->makeDemandOp(
                        $studentId, $studentName, $className, $section, $sectionKey,
                        $headName, $headId, 'monthly', $h['amt'],
                        $m, $sessionYear, $now
                    );
                }
            }
        }
        return $ops;
    }

    private function makeDemandOp(
        string $studentId, string $studentName,
        string $className, string $section, string $sectionKey,
        string $headName, string $headId, string $freq,
        float  $amount, string $monthName, string $sessionYear, string $now
    ): array {
        $year        = $this->calendarYearFor($monthName, $sessionYear);
        $monthNum    = $this->monthNumberFor($monthName);
        $periodKey   = sprintf('%04d-%02d', $year, $monthNum);
        $periodLabel = "{$monthName} {$year}";
        $demandId    = $this->buildDemandId($studentId, $periodKey, $headId, $headName);
        $dueDate     = sprintf('%04d-%02d-%02d', $year, $monthNum, min(28, $this->dueDay));

        return [
            'op'         => 'set',
            'collection' => 'feeDemands',
            'docId'      => $demandId,
            'merge'      => true,
            'data'       => [
                'schoolId'       => $this->schoolId,
                'session'        => $this->session,
                'demandId'       => $demandId,
                'studentId'      => $studentId,
                'studentName'    => $studentName,
                'className'      => $className,
                'section'        => $section,
                'sectionKey'     => $sectionKey,
                'feeHead'        => $headName,
                'feeHeadId'      => $headId,
                'frequency'      => $freq,
                'period'         => $periodLabel,
                'periodKey'      => $periodKey,
                'month'          => $monthName,
                'periodType'     => ($freq === 'yearly') ? 'yearly' : 'monthly',
                'isYearly'       => $freq === 'yearly',
                'grossAmount'    => $amount,
                'discountAmount' => 0.0,
                'fineAmount'     => 0.0,
                'netAmount'      => $amount,
                'paidAmount'     => 0.0,
                'balance'        => $amount,
                'status'         => 'unpaid',
                'dueDate'        => $dueDate,
                'createdAt'      => $now,
                'createdBy'      => 'cli_worker',
                'updatedAt'      => $now,
            ],
        ];
    }

    private function buildDemandId(string $studentId, string $periodKey, string $feeHeadId, string $fallbackName): string
    {
        $sid = preg_replace('/[^A-Za-z0-9]+/', '_', trim($studentId));
        $sid = trim((string) $sid, '_');
        $ym  = str_replace('-', '', $periodKey);
        $key = trim($feeHeadId);
        if ($key === '') {
            $key = strtoupper(trim($fallbackName));
            $key = preg_replace('/[^A-Z0-9]+/', '_', $key);
            $key = trim((string) $key, '_');
        }
        return "DEM_{$sid}_{$ym}_{$key}";
    }

    private function calendarYearFor(string $monthName, string $session): int
    {
        $start = (int) substr($session, 0, 4);
        return in_array($monthName, ['January','February','March'], true) ? $start + 1 : $start;
    }

    private function monthNumberFor(string $monthName): int
    {
        static $map = [
            'April'=>4,'May'=>5,'June'=>6,'July'=>7,'August'=>8,'September'=>9,
            'October'=>10,'November'=>11,'December'=>12,'January'=>1,'February'=>2,'March'=>3,
        ];
        return $map[$monthName] ?? 1;
    }

    // ─────────────────────────────────────────────────────────────────
    // Batch flush + heartbeat + pause polling
    // ─────────────────────────────────────────────────────────────────

    /**
     * Flush the pending ops AND the list of sids they belong to.
     * Advances lastProcessedStudentId ONLY after every chunk landed —
     * so a crash mid-commit or a batch exhausting retries leaves the
     * checkpoint pointing at the last KNOWN-COMMITTED student.
     * A partial batch is never counted as processed.
     */
    private function flushBatch(array $ops, array $batchStudentIds, array &$stats, int $globalIdxAfter): void
    {
        if (empty($ops)) return;
        $jobId = $this->currentJobId;
        $wid   = $this->currentWorker;
        $this->log('info', "[job={$jobId} worker={$wid}] flush START ops=" . count($ops) . " students=" . count($batchStudentIds));

        $result = $this->writer->commit($ops);
        $stats['demandsWritten']   += $result['committedOps'];
        $stats['batchesCommitted'] += $result['committedBatches'];
        $stats['failedBatches']    += $result['failedBatches'];
        if (!empty($result['failedBatchIds'])) {
            $stats['failedBatchIds'] = array_merge($stats['failedBatchIds'], $result['failedBatchIds']);
        }
        if (!empty($result['errors'])) {
            $stats['errors'] = array_merge($stats['errors'], $result['errors']);
        }

        // Checkpoint advances ONLY on a fully-committed flush — protects
        // against worker death between batch-commit and metadata write.
        if ($result['ok'] && !empty($batchStudentIds)) {
            $stats['lastProcessedStudentId'] = (string) end($batchStudentIds);
            $this->log('info', sprintf(
                "[job=%s worker=%d] flush OK committedOps=%d batches=%d lastSid='%s'",
                $jobId, $wid, $result['committedOps'], $result['committedBatches'],
                $stats['lastProcessedStudentId']
            ));
        } elseif (!$result['ok']) {
            $this->log('error', sprintf(
                "[job=%s worker=%d] flush PARTIAL-FAIL committedOps=%d failedBatches=%d failedIds=%s",
                $jobId, $wid, $result['committedOps'], $result['failedBatches'],
                implode(',', $result['failedBatchIds'])
            ));
        }

        // Progress + checkpoint + heartbeat in one merge write. The
        // checkpoint only moves on success (see above) so a crashed
        // worker resumes before the failed batch, not after it.
        $this->mergeWorkerSlot([
            'processedCount'         => $stats['studentsProcessed'],
            'demandsWritten'         => $stats['demandsWritten'],
            'batchesCommitted'       => $stats['batchesCommitted'],
            'failedBatches'          => $stats['failedBatches'],
            'lastProcessedStudentId' => $stats['lastProcessedStudentId'],
            'lastGlobalIdx'          => $globalIdxAfter,
            'lastBatchAt'            => $this->nowIso(),
            'lastHeartbeat'          => $this->nowIso(),
            'lastUpdated'            => $this->nowIso(),
        ]);
        if ($result['committedOps'] > 0) {
            $this->bumpJobField('processedStudents', $stats['studentsProcessed'], /* increment */ false);
        }
        if ($result['failedBatches'] > 0) {
            $this->bumpJobField('failedBatches', $result['failedBatches'], /* increment */ true);
        }
        $this->lastHeartbeat = microtime(true);
    }

    private function heartbeatDue(): bool
    {
        if ($this->lastHeartbeat === 0.0) { $this->lastHeartbeat = microtime(true); return false; }
        return (microtime(true) - $this->lastHeartbeat) >= self::HEARTBEAT_INTERVAL_SEC;
    }

    private function tickHeartbeat(array $stats, int $globalIdxAfter): void
    {
        $this->lastHeartbeat = microtime(true);
        // Route through mergeWorkerSlot so the write preserves the full
        // prior map (see mergeWorkerSlot's comment on Firestore nested-
        // map replace semantics).
        $this->mergeWorkerSlot([
            'lastHeartbeat'          => $this->nowIso(),
            'lastGlobalIdx'          => $globalIdxAfter,
            'lastProcessedStudentId' => $stats['lastProcessedStudentId'],
            'processedCount'         => $stats['studentsProcessed'],
            'status'                 => 'running',
        ]);
        // Pause poll — cheap read at the same cadence.
        try {
            $job = $this->fs->getDocument('fee_generation_jobs', $this->currentJobId);
            if (is_array($job) && ($job['status'] ?? '') === 'paused') {
                $this->pauseRequested = true;
            }
        } catch (\Throwable $_) { /* best-effort */ }
    }

    /**
     * Worker-slot merge.
     *
     * Firestore REST treats a merge-write on a top-level MAP field as a
     * full replace of that map (updateMask is path-based, not field-
     * within-map-based). So we must send the ENTIRE merged slot every
     * time or we silently lose whichever fields aren't in this patch.
     *
     * Also enforces monotonic counters (processedCount, demandsWritten,
     * batchesCommitted, lastGlobalIdx) so a resumed-from-checkpoint run
     * that honestly reports 0 new work doesn't regress the slot's
     * high-water mark. `failedBatches` is NOT monotonic — successful
     * retries legitimately decrement it.
     */
    private function mergeWorkerSlot(array $fields): void
    {
        static $monotonic = ['processedCount','demandsWritten','batchesCommitted','lastGlobalIdx'];
        try {
            $job   = $this->fs->getDocument('fee_generation_jobs', $this->currentJobId);
            $prior = (is_array($job) && is_array($job["worker_{$this->currentWorker}"] ?? null))
                ? $job["worker_{$this->currentWorker}"] : [];
            foreach ($monotonic as $k) {
                if (!isset($fields[$k])) continue;
                $priorVal = (int) ($prior[$k] ?? 0);
                $newVal   = (int) $fields[$k];
                if ($newVal < $priorVal) $fields[$k] = $priorVal;
            }
            // Send the full merged map — NOT just $fields.
            $merged = array_merge($prior, $fields);
            $this->fs->setDocument('fee_generation_jobs', $this->currentJobId, [
                "worker_{$this->currentWorker}" => $merged,
                'updatedAt' => $this->nowIso(),
            ], /* merge */ true);
        } catch (\Throwable $e) {
            $this->log('warning', "mergeWorkerSlot failed: " . $e->getMessage());
        }
    }

    /**
     * Simple get-then-set "counter" bump on the job doc. Not atomic;
     * good enough for progress telemetry where a small drift is
     * acceptable. Caller should treat these as SOFT totals and prefer
     * summing per-worker slots when correctness matters (--finalize).
     */
    private function bumpJobField(string $field, $value, bool $increment = false): void
    {
        try {
            $cur = $this->fs->getDocument('fee_generation_jobs', $this->currentJobId);
            $old = is_array($cur) ? (int) ($cur[$field] ?? 0) : 0;
            $new = $increment ? $old + (int) $value : (int) $value;
            if ($new < 0) $new = 0;
            $this->fs->setDocument('fee_generation_jobs', $this->currentJobId, [
                $field      => $new,
                'updatedAt' => $this->nowIso(),
            ], /* merge */ true);
        } catch (\Throwable $_) { /* best-effort */ }
    }

    // ─────────────────────────────────────────────────────────────────

    private function nowIso(): string { return date('c'); }

    private function log(string $level, string $msg): void
    {
        if (is_callable($this->logger)) ($this->logger)($level, $msg);
    }
}
