<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * FeeWorker — Phase 7C background processor, Phase 7D hardened.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  FLOW (happy path)
 * ──────────────────────────────────────────────────────────────────────
 *  The sync fee-submit path (FeeCollectionService::submit with
 *  FEES_ASYNC_ALLOCATION=1) writes TWO docs and returns to the cashier:
 *     1. feeReceipts/{schoolId}_{receiptKey}   status='queued'
 *     2. feeJobs/{schoolId}_{receiptNo}        status='queued', attempts=0
 *
 *  This worker polls feeJobs and per job:
 *     1. REAP stuck jobs first (status=processing older than 5 min
 *        → reset to queued). Covers the "worker died mid-job" case.
 *     2. Claim: flip status queued → processing.
 *     3. Idempotency: if receipt already 'posted', mark job done + skip.
 *     4. Execute the stashed batch (demands + monthFee + defaulter).
 *     5. Flip receipt.status → 'posted'.
 *     6. Cleanup: release feeLock + idempotency-success + clearPending.
 *     7. Deferred: defaulter recompute + summary refresh + journal.
 *     8. Mark job 'done'. All log lines structured (FEE_JOB_*).
 *
 *  Phase 7D invariants:
 *    - try/finally guarantees feeLock is released on EVERY path
 *      (exception, hard failure, crash-recovery).
 *    - Stale feeLocks (>120 s old) are overridden on release.
 *    - Stuck 'processing' jobs (>5 min old) are reaped at the start
 *      of every worker run.
 *    - Jobs that hit MAX_ATTEMPTS become 'failed' with full error
 *      context captured (error, stacktrace-head, receipt, student).
 *
 *  Invocation:
 *      php index.php feeworker run       # process one poll cycle
 *      php index.php feeworker health    # print queue health JSON
 *
 *  Scheduler (Windows Task Scheduler, every 1 min is enough; sub-minute
 *  requires staggered tasks):
 *      Program:   C:\xampp\php\php.exe
 *      Arguments: index.php feeworker run
 *      Start in:  C:\xampp\htdocs\Grader\school
 *      Env:       SCHOOL_NAME=<name>  SESSION_YEAR=<YYYY-YY>
 */
class FeeWorker extends CI_Controller
{
    private const COL_JOBS      = 'feeJobs';
    private const COL_CLAIMS    = 'feeJobClaims';   // Phase 7G (H1)
    private const COL_RECEIPTS  = 'feeReceipts';
    private const COL_LOCKS     = 'feeLocks';
    private const COL_IDEMP     = 'feeIdempotency';
    private const COL_PENDING   = 'feePendingWrites';
    private const COL_DEMANDS   = 'feeDemands';     // Phase 7G (H4)
    private const POLL_LIMIT    = 5;
    private const MAX_ATTEMPTS  = 3;
    /** Any 'processing' job older than this gets reset to 'queued'. */
    private const STUCK_AFTER_SEC = 300; // 5 minutes
    /** Any feeLock older than this is treated as stale during release. */
    private const LOCK_TTL_SEC    = 120;

    private string $schoolFs   = '';
    private string $session    = '';
    private string $schoolName = '';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('FeeWorker is CLI-only.', 403);
        }
        $this->load->library('firebase');

        $this->schoolName = (string) (getenv('SCHOOL_NAME') ?: '');
        $this->session    = (string) (getenv('SESSION_YEAR') ?: '');
        if ($this->schoolName === '' || $this->session === '') {
            echo "ERROR: Set SCHOOL_NAME and SESSION_YEAR environment variables.\n";
            exit(1);
        }
        $this->firebase->initFirestore($this->schoolName, $this->session);
        $this->load->library('firestore_service');
        $this->schoolFs = (string) $this->firebase->getSchoolId();
        if ($this->schoolFs === '') {
            echo "ERROR: Could not resolve schoolId for {$this->schoolName}.\n";
            exit(1);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  Entry points
    // ─────────────────────────────────────────────────────────────────

    public function run(): void
    {
        $this->_out("FeeWorker run @ " . date('c') . " school={$this->schoolFs} session={$this->session}");

        // Phase 7F — heartbeat write FIRST so the admin "worker is down"
        // banner clears as soon as the task runs, even if no jobs are
        // queued. The dashboard uses `lastRunAt` age to decide whether
        // the worker is alive; no value → treated as down.
        $this->_writeHeartbeat('run_started');

        // Task 2 — Reap stuck jobs BEFORE fetching the queue so they
        // can be picked up in the same poll cycle.
        $reaped = $this->_reapStuckJobs();
        if ($reaped > 0) $this->_out("  reaped stuck jobs: {$reaped}");

        $jobs = $this->_fetchQueuedJobs(self::POLL_LIMIT);
        if (empty($jobs)) {
            $this->_out("  no queued jobs");
            return;
        }
        $this->_out("  picked " . count($jobs) . " job(s)");

        $okCount = $failCount = $skipCount = 0;
        foreach ($jobs as $jobId => $job) {
            $outcome = $this->_processOne($jobId, $job);
            if ($outcome === 'done')   $okCount++;
            elseif ($outcome === 'skip') $skipCount++;
            else                          $failCount++;
        }
        $this->_out("  done ok={$okCount} skip={$skipCount} fail={$failCount}");
        $this->_writeHeartbeat('run_finished', [
            'lastProcessedOk'   => $okCount,
            'lastProcessedSkip' => $skipCount,
            'lastProcessedFail' => $failCount,
        ]);
    }

    /**
     * Phase 7F — write a heartbeat row to `feeWorkerHeartbeat` keyed by
     * {schoolId}_{session}. The admin dashboard treats age(lastRunAt) >
     * 2 min as "worker down". Never throws — a heartbeat failure must
     * not fail the worker run itself.
     */
    private function _writeHeartbeat(string $stage, array $extras = []): void
    {
        try {
            $doc = array_merge([
                'schoolId'  => $this->schoolFs,
                'session'   => $this->session,
                'lastRunAt' => date('c'),
                'lastStage' => $stage,
                'host'      => gethostname() ?: 'unknown',
                'pid'       => (string) getmypid(),
                'updatedAt' => date('c'),
            ], $extras);
            $this->firebase->firestoreSet(
                'feeWorkerHeartbeat',
                "{$this->schoolFs}_{$this->session}",
                $doc,
                /* merge */ true
            );
        } catch (\Throwable $e) {
            log_message('error', "FEE_WORKER_HEARTBEAT_FAIL: " . $e->getMessage());
        }
    }

    /** CLI: `php index.php feeworker health` — dump queue health JSON. */
    public function health(): void
    {
        $h = $this->getQueueHealth();
        echo json_encode($h, JSON_PRETTY_PRINT) . "\n";
    }

    // ─────────────────────────────────────────────────────────────────
    //  Task 4 — Queue health (also re-used by the admin debug endpoint)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Public so Fees::queue_status can call it over HTTP.
     * Scans small slices only (no full-collection reads) — safe to run
     * frequently from a dashboard poll.
     *
     * Returns:
     *   queued_count        — # of 'queued' docs scanned (capped at 200)
     *   processing_count    — # of 'processing' docs scanned (capped)
     *   failed_count        — # of 'failed' docs scanned (capped)
     *   oldest_job_age      — seconds since the oldest queued job's createdAt
     *   oldest_job_id       — that job's id (for operator triage)
     *   stuck_processing    — # of 'processing' jobs older than STUCK_AFTER_SEC
     */
    public function getQueueHealth(): array
    {
        $out = [
            'queued_count'     => 0,
            'processing_count' => 0,
            'failed_count'     => 0,
            'oldest_job_age'   => 0,
            'oldest_job_id'    => '',
            'stuck_processing' => 0,
            'collected_at'     => date('c'),
        ];
        $cap = 200;
        $stuckThreshold = date('c', time() - self::STUCK_AFTER_SEC);
        try {
            $queued = (array) $this->firebase->firestoreQuery(self::COL_JOBS, [
                ['schoolId', '==', $this->schoolFs],
                ['session',  '==', $this->session],
                ['status',   '==', 'queued'],
            ], 'createdAt', 'ASC', $cap);
            $out['queued_count'] = count($queued);
            if (!empty($queued)) {
                $oldest = $queued[0];
                $createdAt = (string) ($oldest['data']['createdAt'] ?? '');
                if ($createdAt !== '') {
                    $out['oldest_job_age'] = max(0, time() - strtotime($createdAt));
                    $out['oldest_job_id']  = (string) ($oldest['id'] ?? '');
                }
            }

            $processing = (array) $this->firebase->firestoreQuery(self::COL_JOBS, [
                ['schoolId', '==', $this->schoolFs],
                ['session',  '==', $this->session],
                ['status',   '==', 'processing'],
            ], 'updatedAt', 'ASC', $cap);
            $out['processing_count'] = count($processing);
            foreach ($processing as $p) {
                $ts = (string) ($p['data']['updatedAt'] ?? '');
                if ($ts !== '' && $ts < $stuckThreshold) {
                    $out['stuck_processing']++;
                }
            }

            $failed = (array) $this->firebase->firestoreQuery(self::COL_JOBS, [
                ['schoolId', '==', $this->schoolFs],
                ['session',  '==', $this->session],
                ['status',   '==', 'failed'],
            ], null, 'ASC', $cap);
            $out['failed_count'] = count($failed);
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Task 2 — Stuck-job reaper
    // ─────────────────────────────────────────────────────────────────

    /**
     * Find jobs stuck in 'processing' for > STUCK_AFTER_SEC and flip
     * them back to 'queued' so the next poll picks them up. Used by
     * the reaper path only — normal completion is handled inline.
     */
    private function _reapStuckJobs(): int
    {
        $threshold = date('c', time() - self::STUCK_AFTER_SEC);
        $reaped = 0;
        try {
            $rows = (array) $this->firebase->firestoreQuery(self::COL_JOBS, [
                ['schoolId', '==', $this->schoolFs],
                ['session',  '==', $this->session],
                ['status',   '==', 'processing'],
            ], 'updatedAt', 'ASC', /* limit */ 20);
            foreach ($rows as $r) {
                $data = is_array($r['data'] ?? null) ? $r['data'] : [];
                $id   = (string) ($r['id'] ?? '');
                $upd  = (string) ($data['updatedAt'] ?? '');
                if ($id === '' || $upd === '' || $upd >= $threshold) continue;

                // Reset to 'queued' so the next poll picks it up.
                // attempts is NOT incremented here — the retry counter
                // only moves on actual execution failure inside the
                // processing block. Reaper just unsticks.
                $this->firebase->firestoreSet(self::COL_JOBS, $id, [
                    'status'     => 'queued',
                    'reapedAt'   => date('c'),
                    'updatedAt'  => date('c'),
                    'lastError'  => 'reaper: stuck in processing > ' . self::STUCK_AFTER_SEC . 's',
                ], /* merge */ true);

                // Phase 7G (H1) — also delete the orphan claim sentinel
                // that the crashed worker left behind. Without this,
                // every retry attempt would lose the claim race in
                // _processOne (firestoreCreate → 409) and the job
                // would stay 'queued' forever, burning CPU on every
                // poll cycle.
                try {
                    $this->firebase->firestoreDelete(self::COL_CLAIMS, $id);
                } catch (\Throwable $_) {}

                log_message('error', "FEE_JOB_REAPED jobId={$id} lastUpdatedAt={$upd}");
                $reaped++;
            }
        } catch (\Throwable $e) {
            log_message('error', "FEE_JOB_REAPER_ERROR: " . $e->getMessage());
        }
        return $reaped;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Job lifecycle
    // ─────────────────────────────────────────────────────────────────

    /** @return array [jobId => jobDoc] */
    private function _fetchQueuedJobs(int $limit): array
    {
        try {
            $rows = $this->firebase->firestoreQuery(self::COL_JOBS, [
                ['schoolId', '==', $this->schoolFs],
                ['session',  '==', $this->session],
                ['status',   '==', 'queued'],
            ], 'createdAt', 'ASC', $limit);
            $out = [];
            foreach ((array) $rows as $r) {
                $id   = (string) ($r['id']   ?? '');
                $data = is_array($r['data'] ?? null) ? $r['data'] : [];
                if ($id !== '') $out[$id] = $data;
            }
            return $out;
        } catch (\Throwable $e) {
            $this->_out("  fetch queue error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Process one job with GUARANTEED lock release.
     * Returns 'done' | 'skip' | 'fail'. Never throws.
     */
    private function _processOne(string $jobId, array $job): string
    {
        $receiptKey  = (string) ($job['receiptKey'] ?? '');
        $receiptNo   = (string) ($job['receiptNo']  ?? '');
        $userId      = (string) ($job['studentId']  ?? '');
        $attempts    = (int)    ($job['attempts']   ?? 0);
        $ctx         = is_array($job['payload']['context'] ?? null) ? $job['payload']['context'] : [];
        $lockToken   = (string) ($ctx['lockToken'] ?? '');

        log_message('error', "FEE_JOB_STARTED jobId={$jobId} receipt={$receiptKey} student={$userId} attempt=" . ($attempts + 1));
        $this->_out("  job={$jobId} receipt={$receiptKey} attempt=" . ($attempts + 1));

        // ── Phase 7G (H1) — exclusive claim via a create-if-not-exists
        //    sentinel doc. Two concurrent workers that both see
        //    status='queued' will race here, and exactly ONE's
        //    firestoreCreate on feeJobClaims/{jobId} will succeed;
        //    the other receives false (409) and returns 'skip' BEFORE
        //    entering the try block, so the finally doesn't fire
        //    stray lock-releases. The reaper and single-job retry
        //    paths clean the claim doc when they re-queue a job.
        $claimOk = $this->firebase->firestoreCreate(self::COL_CLAIMS, $jobId, [
            'jobId'     => $jobId,
            'host'      => gethostname() ?: 'unknown',
            'pid'       => (string) getmypid(),
            'claimedAt' => date('c'),
            'attempt'   => $attempts + 1,
        ]);
        if (!$claimOk) {
            log_message('error', "FEE_JOB_CLAIM_RACE jobId={$jobId} — another worker already owns this job");
            $this->_out("    lost claim race — another worker has it");
            return 'skip';
        }

        $outcome      = 'fail';   // default — only flipped on clean success / idempotent skip
        $jobException = null;

        // Task 1 — try / finally. WHATEVER happens inside try, the
        // finally block MUST run and MUST release the feeLock so the
        // student isn't stuck behind a ghost lock on the next submit.
        try {
            // ── 1. Flip job status. queued → processing. Race-free now
            //    that we hold the sentinel above.
            $this->firebase->firestoreSet(self::COL_JOBS, $jobId, [
                'status'    => 'processing',
                'attempts'  => $attempts + 1,
                'claimedAt' => date('c'),
                'updatedAt' => date('c'),
            ], /* merge */ true);

            // ── 2. Idempotency: receipt already posted? Worker crashed
            //    between step 4 and step 7 previously — the atomic
            //    posting already landed; don't re-run the batch.
            //    Phase 7G (H3): BEFORE marking done, flush the cleanup
            //    batch so we don't leave idempotency stuck at
            //    'processing'. Without this, webhook retries against
            //    the same idempHash keep failing the claim-batch for
            //    hours until some timeout eventually ages the doc out.
            $receipt = $this->firebase->firestoreGet(self::COL_RECEIPTS, "{$this->schoolFs}_{$receiptKey}");
            if (is_array($receipt) && ($receipt['status'] ?? '') === 'posted') {
                $idempHashSkip = (string) ($job['payload']['idempHash'] ?? '');
                $cleanupOpsSkip = [];
                if ($idempHashSkip !== '') {
                    $cleanupOpsSkip[] = [
                        'op' => 'set', 'merge' => true,
                        'collection' => self::COL_IDEMP,
                        'docId' => "{$this->schoolFs}_{$idempHashSkip}",
                        'data' => [
                            'status'      => 'success',
                            'receiptNo'   => $receiptNo,
                            'completedAt' => date('c'),
                            'updatedAt'   => date('c'),
                        ],
                    ];
                }
                $cleanupOpsSkip[] = [
                    'op'         => 'delete',
                    'collection' => self::COL_PENDING,
                    'docId'      => "{$this->schoolFs}_{$receiptKey}",
                ];
                $this->firebase->firestoreCommitBatch($cleanupOpsSkip);

                $this->_markJobDone($jobId, 'receipt-already-posted');
                log_message('error', "FEE_JOB_DONE jobId={$jobId} (idempotent-skip)");
                $outcome = 'skip';
                return $outcome;
            }

            // ── 2b. Phase 7G (H4) — demand drift guard. The allocation
            //    math was computed at sync time; `payload.ops` encodes
            //    absolute new paidAmount/balance per demand. If
            //    someone (e.g., Fee_refund_service on a different
            //    receipt) touched a demand between sync-commit and
            //    our run, blind re-apply would silently overwrite
            //    their edits. Re-read each demand listed in the
            //    baseline map and bail if ANY updatedAt has advanced.
            $baseline = is_array($job['payload']['demandUpdateAtByDid'] ?? null)
                      ? $job['payload']['demandUpdateAtByDid']
                      : [];
            if (!empty($baseline)) {
                $driftReqs = [];
                foreach (array_keys($baseline) as $_did) {
                    $driftReqs[$_did] = ['collection' => self::COL_DEMANDS, 'docId' => (string) $_did];
                }
                $driftDocs = (array) $this->firebase->firestoreGetParallel($driftReqs);
                foreach ($baseline as $did => $expectedTs) {
                    $cur = is_array($driftDocs[$did] ?? null) ? $driftDocs[$did] : null;
                    $curTs = is_array($cur) ? (string) ($cur['updatedAt'] ?? '') : '';
                    $expected = (string) $expectedTs;
                    if ($expected !== '' && $curTs !== '' && $curTs > $expected) {
                        throw new \RuntimeException(
                            "demand-drift: {$did} updatedAt moved past sync baseline " .
                            "(baseline={$expected}, current={$curTs}) — likely refund or edit raced this payment. " .
                            "Operator must reconcile manually before retry."
                        );
                    }
                }
            }

            // ── 3. Execute the stashed allocation batch.
            $ops = is_array($job['payload']['ops'] ?? null) ? $job['payload']['ops'] : [];
            if (!empty($ops)) {
                if (!$this->firebase->firestoreCommitBatch($ops)) {
                    throw new \RuntimeException('payload.ops commitBatch returned false');
                }
            }

            // ── 4. Flip receipt to posted.
            $this->firebase->firestoreSet(self::COL_RECEIPTS, "{$this->schoolFs}_{$receiptKey}", [
                'status'    => 'posted',
                'postedAt'  => date('c'),
                'updatedAt' => date('c'),
            ], /* merge */ true);

            // ── 5. Idempotency success + pending-writes cleanup.
            //    (Lock release is handled in the finally block below
            //    so it fires for both success AND exception paths.)
            $idempHash = (string) ($job['payload']['idempHash'] ?? '');
            $cleanupOps = [];
            if ($idempHash !== '') {
                $cleanupOps[] = [
                    'op' => 'set', 'merge' => true,
                    'collection' => self::COL_IDEMP,
                    'docId' => "{$this->schoolFs}_{$idempHash}",
                    'data' => [
                        'status'      => 'success',
                        'receiptNo'   => $receiptNo,
                        'completedAt' => date('c'),
                        'updatedAt'   => date('c'),
                    ],
                ];
            }
            $cleanupOps[] = [
                'op' => 'delete',
                'collection' => self::COL_PENDING,
                'docId' => "{$this->schoolFs}_{$receiptKey}",
            ];
            $this->firebase->firestoreCommitBatch($cleanupOps);

            // ── 6. Deferred side-effects (best-effort, never throws).
            $this->_runDeferredSideEffects($job);

            // ── 7. Mark job done.
            $this->_markJobDone($jobId, 'success');
            log_message('error', "FEE_JOB_DONE jobId={$jobId} receipt={$receiptKey}");
            $outcome = 'done';
            return $outcome;

        } catch (\Throwable $e) {
            $jobException = $e;
            $newAttempts  = $attempts + 1;
            $status       = ($newAttempts >= self::MAX_ATTEMPTS) ? 'failed' : 'queued';

            // Task 3 — detailed failure context.
            $errHead = substr($e->getMessage(), 0, 500);
            $trace   = substr((string) $e->getTraceAsString(), 0, 1500);

            $this->firebase->firestoreSet(self::COL_JOBS, $jobId, [
                'status'      => $status,
                'attempts'    => $newAttempts,
                'lastError'   => $errHead,
                'lastTrace'   => $trace,
                'lastErrorAt' => date('c'),
                'updatedAt'   => date('c'),
            ], /* merge */ true);

            if ($status === 'failed') {
                log_message('error', "FEE_JOB_FAILED jobId={$jobId} receipt={$receiptKey} student={$userId} attempts={$newAttempts} error={$errHead}");
                $this->_out("    FAILED attempt={$newAttempts} (giving up) error=" . substr($errHead, 0, 200));
            } else {
                log_message('error', "FEE_JOB_RETRY jobId={$jobId} receipt={$receiptKey} student={$userId} attempt={$newAttempts} error={$errHead}");
                $this->_out("    RETRY attempt={$newAttempts} error=" . substr($errHead, 0, 200));
            }
            $outcome = 'fail';
            return $outcome;

        } finally {
            // Task 1 — GUARANTEED lock release. Runs for every path:
            // clean success, idempotent skip, transient exception,
            // permanent failure. Skips only the cases where the caller
            // explicitly tells us not to (none today).
            $this->_safeReleaseLock($userId, $lockToken, $jobId);

            // Phase 7G (H1) — always drop the claim sentinel so the
            // next retry worker can pick this job up. If we crash
            // between here and shell return (OS kill), the reaper
            // catches the stuck state and clears the sentinel for us.
            try {
                $this->firebase->firestoreDelete(self::COL_CLAIMS, $jobId);
            } catch (\Throwable $_) { /* best-effort — reaper cleans up */ }
        }
    }

    /**
     * Release the feeLock for $userId, but ONLY if:
     *   (a) the stored token matches $lockToken (we own it), OR
     *   (b) the stored lock is older than LOCK_TTL_SEC (stale — safe
     *       to override so the student isn't permanently blocked).
     *
     * Never throws. Logged as FEE_LOCK_RELEASED / FEE_LOCK_SKIPPED.
     */
    private function _safeReleaseLock(string $userId, string $lockToken, string $jobId): void
    {
        if ($userId === '') return;
        $docId = "{$this->schoolFs}_{$userId}";
        try {
            $existing = $this->firebase->firestoreGet(self::COL_LOCKS, $docId);
            if (!is_array($existing) || empty($existing['token'])) {
                // No lock to release (already cleared, or never claimed).
                log_message('error', "FEE_LOCK_RELEASED jobId={$jobId} student={$userId} reason=absent");
                return;
            }
            $ownedByUs = ($lockToken !== '' && (string) $existing['token'] === $lockToken);
            $age       = time() - strtotime((string) ($existing['acquiredAt'] ?? '2000-01-01'));
            $stale     = ($age >= self::LOCK_TTL_SEC);

            if (!$ownedByUs && !$stale) {
                // Not ours and still fresh — do not steal.
                log_message('error', "FEE_LOCK_SKIPPED jobId={$jobId} student={$userId} age={$age}s reason=not-owned-and-fresh");
                return;
            }
            $this->firebase->firestoreDelete(self::COL_LOCKS, $docId);
            $reason = $ownedByUs ? 'token-match' : 'stale-override';
            log_message('error', "FEE_LOCK_RELEASED jobId={$jobId} student={$userId} age={$age}s reason={$reason}");
        } catch (\Throwable $e) {
            // Never fail a job because lock-cleanup failed — logged for
            // operator follow-up; the 120s TTL is the safety net.
            log_message('error', "FEE_LOCK_RELEASE_ERROR jobId={$jobId} student={$userId} error=" . $e->getMessage());
        }
    }

    private function _markJobDone(string $jobId, string $reason): void
    {
        $this->firebase->firestoreSet(self::COL_JOBS, $jobId, [
            'status'     => 'done',
            'finishedAt' => date('c'),
            'doneReason' => $reason,
            'updatedAt'  => date('c'),
        ], /* merge */ true);
    }

    private function _runDeferredSideEffects(array $job): void
    {
        $ctx = is_array($job['payload']['context'] ?? null) ? $job['payload']['context'] : [];
        $userId   = (string) ($ctx['userId']     ?? '');
        $schoolFs = (string) ($ctx['schoolFs']   ?? $this->schoolFs);
        $session  = (string) ($ctx['session']    ?? $this->session);
        if ($userId === '') return;

        // Defaulter recompute.
        try {
            $this->load->library('Fee_firestore_txn', null, 'fsTxn');
            $this->fsTxn->init($this->firebase, null, $schoolFs, $session);
            $this->load->library('Fee_defaulter_check', null, 'feeDefaulter');
            $this->feeDefaulter->init($this->fsTxn, $this->firebase, $schoolFs, $session);
            $defStatus = $this->feeDefaulter->updateDefaulterStatus($userId);
            $this->load->library('Fee_firestore_sync', null, 'fsSync');
            $this->fsSync->init($this->firebase, $schoolFs, $session);
            $this->fsSync->syncDefaulterStatus(
                $userId, $defStatus,
                (string) ($ctx['studentName'] ?? ''),
                (string) ($ctx['className']   ?? ''),
                (string) ($ctx['section']     ?? '')
            );
        } catch (\Throwable $e) {
            log_message('error', "FEE_JOB_DEFERRED_DEFAULTER_FAIL jobId-ctx student={$userId} error=" . $e->getMessage());
        }

        // Summary refresh.
        try {
            $this->load->library('Fee_summary_writer', null, 'feeSummaryWriter');
            $this->feeSummaryWriter->init($this->firebase, $schoolFs, $session);
            $months = is_array($job['payload']['months'] ?? null) ? $job['payload']['months'] : [];
            $this->feeSummaryWriter->onReceiptWritten(
                $userId,
                $months,
                (string) ($ctx['className'] ?? ''),
                (string) ($ctx['section']   ?? '')
            );
        } catch (\Throwable $e) {
            log_message('error', "FEE_JOB_DEFERRED_SUMMARY_FAIL student={$userId} error=" . $e->getMessage());
        }

        // Accounting journal.
        //
        // Phase 8B — v2 `_create_fee_journal_v2` can legitimately return
        // null (CAS exhausted, in-progress race, missing CoA, etc.)
        // without throwing. Without capturing the return here, the
        // worker would mark the job 'done' and the receipt would have
        // no matching ledger row until an operator spotted the drift.
        //
        // We still don't FAIL the worker job on a null — the fee side
        // (demands, monthFee, defaulters) has already committed
        // successfully and forcing a full-job retry risks re-applying
        // the fee state. Instead: log clearly so AccountingReconciler
        // can detect the receipt-without-journal pattern and auto-post
        // via the deterministic idempKey.
        try {
            $acctPayload = is_array($job['payload']['opsAcctPayload'] ?? null) ? $job['payload']['opsAcctPayload'] : null;
            if ($acctPayload !== null) {
                $this->load->library('Operations_accounting', null, 'opsAcct');
                $this->opsAcct->init($this->firebase,
                    (string) ($ctx['schoolName'] ?? $this->schoolName),
                    $session,
                    (string) ($ctx['adminId'] ?? 'SYSTEM_WORKER'),
                    $this);
                $entryId = $this->opsAcct->create_fee_journal($acctPayload);
                if ($entryId === null || $entryId === '') {
                    $recKey = (string) ($acctPayload['receipt_no'] ?? '');
                    $jeKey  = (string) ($acctPayload['journal_entry_id'] ?? '');
                    log_message('error',
                        "FEE_JOB_DEFERRED_JOURNAL_NULL receipt={$recKey} idempKey={$jeKey} — "
                        . "v2 returned null (CAS exhausted or in-flight). Reconciler will retry.");
                }
            }
        } catch (\Throwable $e) {
            log_message('error', "FEE_JOB_DEFERRED_JOURNAL_FAIL error=" . $e->getMessage());
        }
    }

    private function _out(string $line): void
    {
        echo "[" . date('H:i:s') . "] {$line}\n";
    }
}
