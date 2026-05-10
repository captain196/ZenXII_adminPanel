<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * AccountingReconciler — Phase 8B background drift detector + repair.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  PURPOSE
 * ──────────────────────────────────────────────────────────────────────
 *  The accounting v2 path (`_create_fee_journal_v2`) is designed to be
 *  correct under concurrency, but:
 *    • CAS retries can exhaust after 5 attempts under extreme contention
 *    • Worker process death between fee commit and journal post leaves
 *      the receipt posted but no ledger row
 *    • An idempotency slot can end up stuck at status='processing' if
 *      the requester died after claiming but before flipping to success
 *
 *  This reconciler scans for those drift patterns every 15 minutes and
 *  either auto-heals (by invoking create_fee_journal with the same
 *  deterministic journal_entry_id — idempotent) or marks the slot as
 *  failed so operators can investigate.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  WHAT IT DOES
 * ──────────────────────────────────────────────────────────────────────
 *  Per run:
 *    1. Heartbeat write  (mirrors FeeWorker — dashboard shows liveness)
 *    2. Stuck idempotency sweep
 *        • Find accountingIdempotency where status='processing' and
 *          startedAt > 5 min ago
 *        • Look up the ledger entry by entryId
 *        • If entry exists & status='active' → flip slot to 'success'
 *        • Else → flip slot to 'failed' (next retry can proceed)
 *    3. Fee→Accounting drift sweep
 *        • Find feeReceipts where status='posted' and postedAt > 5 min
 *          ago (up to 200 per run)
 *        • For each, check if accounting/{schoolId}_{session}_JE_FEE_{receiptKey}
 *          exists
 *        • If absent → log ACC_RECON_DRIFT + auto-retry by calling
 *          create_fee_journal with deterministic journal_entry_id
 *          (idempotent — no duplicate if another writer just landed it)
 *
 *  Never deletes data. Every action is logged for audit.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  INVOCATION
 * ──────────────────────────────────────────────────────────────────────
 *      php index.php accountingreconciler run
 *      php index.php accountingreconciler health   # summary snapshot
 *
 *  Scheduler (Windows Task Scheduler, every 15 min):
 *      Program:   C:\xampp\php\php.exe
 *      Arguments: index.php accountingreconciler run
 *      Start in:  C:\xampp\htdocs\Grader\school
 *      Env:       SCHOOL_NAME=<name>  SESSION_YEAR=<YYYY-YY>
 *
 *  Note: ACCOUNTING_V2 env flag is no longer required (Phase 1 Tier-0
 *  hardening, 2026-05-09). The v1 fallback path was retired in
 *  Operations_accounting; v2 is the only path. The env line was kept
 *  in earlier deployments as a rollout knob — safe to drop now.
 */
class AccountingReconciler extends CI_Controller
{
    private const COL_IDEMP     = 'accountingIdempotency';
    private const COL_LEDGER    = 'accounting';
    private const COL_RECEIPTS  = 'feeReceipts';
    private const COL_HEARTBEAT = 'accountingReconHeartbeat';

    private const STUCK_IDEMP_SEC  = 300;   // 5 min
    private const DRIFT_AGE_SEC    = 300;   // only check receipts older than this
    private const MAX_IDEMP_SCAN   = 100;   // per run
    private const MAX_DRIFT_SCAN   = 200;   // per run

    // Phase 1 Tier-0 (B3) — refund-journal drift sweep tuning. Same
    // semantics as fee-receipt drift: only refunds whose last journal
    // attempt is staler than this threshold are eligible for retry,
    // and a refund hard-suspends after MAX_REFUND_RETRIES failures so
    // a permanently-broken refund doesn't keep spamming the log.
    private const REFUND_DRIFT_AGE_SEC = 300;   // 5 min
    private const MAX_REFUND_DRIFT_SCAN = 200;  // per run
    private const MAX_REFUND_RETRIES    = 5;    // matches fee-drift cap

    // Phase 2 (R-P4) / Phase 4 (V-MED-5) — index-drift sweep.
    // Cursor-paginated via heartbeat doc field `idx_scan_cursor`. Each
    // cycle scans up to MAX_INDEX_DRIFT_SCAN entries past the cursor;
    // pass completes when the query returns less than that — cursor
    // resets and the next cycle starts a fresh pass. Raised from 200
    // (Phase 2) to 500 (Phase 4) for faster historical backlog drain.
    private const MAX_INDEX_DRIFT_SCAN = 500;

    // Phase 2 (R-B5) / Phase 4 (V-MED-4) — balance-drift sweep.
    // Multi-cycle paginated: BAL_SCAN_BATCH_SIZE entries per cycle,
    // accumulated into a per-account agg dict held in heartbeat
    // `bal_scan_state`. When the scan returns < cap, pass is complete;
    // detection + CAS-protected repair runs against the full agg, then
    // state resets. No "detect-only when capped" mode anymore — large
    // schools converge across multiple cycles instead of degrading.
    // BAL_PASS_MAX_WINDOWS is a safety ceiling: a pass that doesn't
    // complete within ~50 windows (≈ 12+ hours at the 15-min cadence)
    // abandons its accumulated state and starts fresh, preventing
    // stuck state from blocking convergence in pathological cases.
    private const BAL_SCAN_BATCH_SIZE   = 5000;
    private const BAL_PASS_MAX_WINDOWS  = 50;

    private string $schoolFs   = '';
    private string $schoolName = '';
    private string $session    = '';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('AccountingReconciler is CLI-only.', 403);
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
        // Phase 1 Tier-0 (B2): ACCOUNTING_V2 env shim removed. v1 path
        // is retired in Operations_accounting; v2 is now unconditional.
        // Auto-repair retries inherit the same v2 protections (period
        // lock, idempotency, CAS) as primary writes.
    }

    // ─────────────────────────────────────────────────────────────────
    //  Entry points
    // ─────────────────────────────────────────────────────────────────

    public function run(): void
    {
        $this->_out("AccountingReconciler run @ " . date('c') . " school={$this->schoolFs} session={$this->session}");

        // Phase 8C (R2) — soft concurrent-run guard. If another instance
        // checked in less than 120 s ago and is still in any in-progress
        // stage (never flipped to run_finished), assume it's still
        // running. Skip this cycle — idempotency guards protect against
        // double repair even if we push through, but skipping keeps the
        // log clean.
        //
        // Phase 3 (V-HIGH-2) — broadened the in-progress check from a
        // single 'run_started' marker to "any non-empty, non-finished
        // stage". The reconciler now refreshes the heartbeat between
        // sweep stages (sweep_idempotency_done, sweep_fee_drift_done,
        // sweep_refund_drift_done, sweep_index_drift_done) and a sweep
        // that legitimately exceeds 120 s no longer gets falsely taken
        // over by the next cron launch — each stage refresh resets
        // lastRunAt to now, so the guard sees a fresh heartbeat.
        // Takeover still triggers correctly after a TRUE crash because
        // a dead worker's heartbeat ages past 120 s without progressing.
        $hb = $this->firebase->firestoreGet(self::COL_HEARTBEAT,
            "{$this->schoolFs}_{$this->session}");
        $priorStage  = is_array($hb) ? (string) ($hb['lastStage'] ?? '') : '';
        $inProgress  = ($priorStage !== '' && $priorStage !== 'run_finished');
        if (is_array($hb)
            && $inProgress
            && !empty($hb['lastRunAt'])
            && ((string) ($hb['pid'] ?? '')) !== (string) getmypid()
        ) {
            $age = max(0, time() - strtotime((string) $hb['lastRunAt']));
            if ($age < 120) {
                log_message('error',
                    "ACC_RECON_SKIP_CONCURRENT other_pid=" . ($hb['pid'] ?? '?')
                    . " host=" . ($hb['host'] ?? '?')
                    . " stage={$priorStage} ageSec={$age}");
                $this->_out("  another reconciler instance is active (stage={$priorStage} age={$age}s) — skipping");
                return;
            }
            // Older than 120s without a run_finished stamp: the other
            // instance crashed. We take over.
            log_message('error',
                "ACC_RECON_TAKEOVER prior_pid=" . ($hb['pid'] ?? '?')
                . " prior_stage={$priorStage} ageSec={$age} "
                . "(prior run never finished)");
        }
        $this->_writeHeartbeat('run_started');

        // Phase 3 (V-HIGH-2) — refresh heartbeat between each sweep
        // stage. The cumulative reconciler runtime can plausibly exceed
        // 120 s on a busy school (5000-entry balance scan + 200 index
        // probes + idempotency reads). Each stage refresh resets
        // lastRunAt so the takeover guard above always sees a live
        // worker, while a true crash between stages still ages past
        // 120 s and triggers takeover correctly on the next run.
        $idempStats   = $this->_sweepStuckIdempotency();
        $this->_writeHeartbeat('sweep_idempotency_done');

        $driftStats   = $this->_sweepFeeAccountingDrift();
        $this->_writeHeartbeat('sweep_fee_drift_done');

        $refundStats  = $this->_sweepRefundJournalDrift();
        $this->_writeHeartbeat('sweep_refund_drift_done');

        $indexStats   = $this->_sweepIndexDrift();    // R-P4
        $this->_writeHeartbeat('sweep_index_drift_done');

        $balanceStats = $this->_sweepBalanceDrift();  // R-B5

        $this->_writeHeartbeat('run_finished', [
            'idempRecovered'      => $idempStats['recovered'],
            'idempOrphaned'       => $idempStats['orphaned'],
            'driftFound'          => $driftStats['found'],
            'driftRepaired'       => $driftStats['repaired'],
            'driftFailed'         => $driftStats['failed'],
            'refundDriftFound'    => $refundStats['found'],
            'refundDriftRepaired' => $refundStats['repaired'],
            'refundDriftFailed'   => $refundStats['failed'],
            'refundDriftSuspended'=> $refundStats['suspended'],
            // Phase 2 — R-P4 / Phase 4 V-MED-5 index drift
            'indexScanned'           => $indexStats['scanned'],
            'indexMissing'           => $indexStats['missing'],
            'indexRepaired'          => $indexStats['repaired'],
            'indexFailed'            => $indexStats['failed'],
            'indexPassComplete'      => !empty($indexStats['pass_complete']) ? 1 : 0,
            // Phase 2 — R-B5 / Phase 4 V-MED-4 balance drift
            'balanceAccountsScanned' => $balanceStats['accounts_scanned'],
            'balanceDrifted'         => $balanceStats['drifted'],
            'balanceRepaired'        => $balanceStats['repaired'],
            'balanceFailed'          => $balanceStats['failed'],
            'balanceWindowSize'      => $balanceStats['window_size'] ?? 0,
            'balanceWindowsProcessed'=> $balanceStats['windows_processed'] ?? 0,
            'balancePassComplete'    => !empty($balanceStats['pass_complete']) ? 1 : 0,
            // Phase 4.5 (V-MED-OBS) — pass-complete timestamps. Attached
            // only on cycles that actually completed a pass; merge=true
            // means cycles where pass_complete=false leave the prior
            // timestamp untouched, so the field always carries the
            // most-recent successful-pass time.
        ] + (!empty($indexStats['pass_complete'])    ? ['last_idx_pass_at' => date('c')] : [])
          + (!empty($balanceStats['pass_complete']) ? ['last_bal_pass_at' => date('c')] : [])
        );
        $this->_out(sprintf(
            "  done  idemp{recovered=%d orphaned=%d}  drift{found=%d repaired=%d failed=%d}  refund{found=%d repaired=%d failed=%d suspended=%d}  index{scan=%d miss=%d fix=%d fail=%d%s}  balance{acct=%d drift=%d fix=%d fail=%d win=%d%s}",
            $idempStats['recovered'], $idempStats['orphaned'],
            $driftStats['found'], $driftStats['repaired'], $driftStats['failed'],
            $refundStats['found'], $refundStats['repaired'], $refundStats['failed'], $refundStats['suspended'],
            $indexStats['scanned'], $indexStats['missing'], $indexStats['repaired'], $indexStats['failed'],
            !empty($indexStats['pass_complete']) ? ' PASS_DONE' : ' (continuing)',
            $balanceStats['accounts_scanned'], $balanceStats['drifted'], $balanceStats['repaired'], $balanceStats['failed'],
            $balanceStats['window_size'] ?? 0,
            !empty($balanceStats['pass_complete']) ? ' PASS_DONE' : ' (multi-cycle continuing)'
        ));
    }

    /**
     * CLI: `php index.php accountingreconciler backfill_balance_updatetime`
     *
     * Phase 2 (R-P3) — one-shot backfill helper. Touches every
     * `accountingClosingBalances` doc for this school+session with a
     * merge-write of `updatedAt` so subsequent firestoreGetParallel
     * reads include populated `__updateTime` Firestore metadata.
     *
     * Without this, balance docs created before the v2 migration may
     * lack `__updateTime` on read, causing the v2 path's CAS
     * precondition to silently downgrade to "no precondition" — visible
     * via the ACC_BAL_NO_UPDATETIME log lines emitted by
     * Operations_accounting v2. After this backfill runs successfully,
     * those log lines should drop to ~zero per day.
     *
     * Idempotent: re-running is safe. Touches only `updatedAt`; does
     * NOT modify period_dr / period_cr / accountCode.
     */
    public function backfill_balance_updatetime(): void
    {
        $this->_out("AccountingReconciler backfill_balance_updatetime @ " . date('c'));
        $this->_out("  scope: schoolId={$this->schoolFs} session={$this->session}");

        $touched = 0;
        $failed  = 0;

        try {
            $balances = (array) $this->firebase->firestoreQuery('accountingClosingBalances', [
                ['schoolId', '==', $this->schoolFs],
                ['session',  '==', $this->session],
            ], null, 'ASC', self::MAX_BALANCE_DRIFT_SCAN);

            $this->_out("  found " . count($balances) . " balance docs");

            foreach ($balances as $r) {
                $d    = is_array($r['data'] ?? null) ? $r['data'] : [];
                $code = (string) ($d['accountCode'] ?? '');
                if ($code === '') continue;
                $docId = "{$this->schoolFs}_{$this->session}_{$code}";

                try {
                    $this->firebase->firestoreSet('accountingClosingBalances', $docId, [
                        'updatedAt' => date('c'),
                    ], /* merge */ true);
                    $touched++;
                } catch (\Throwable $e) {
                    log_message('error',
                        "ACC_RECON_BACKFILL_FAILED code={$code} error=" . $e->getMessage());
                    $failed++;
                }
            }

            $this->_out("  done: touched={$touched} failed={$failed}");
            log_message('error',
                "ACC_RECON_BACKFILL_COMPLETE schoolId={$this->schoolFs} "
                . "session={$this->session} touched={$touched} failed={$failed}");
        } catch (\Throwable $e) {
            $this->_out("  ERROR: " . $e->getMessage());
            log_message('error', 'ACC_RECON_BACKFILL_ERROR: ' . $e->getMessage());
        }
    }

    /**
     * CLI: `php index.php accountingreconciler verify_balance_cas_compliance`
     *
     * Phase 4 (V-MED-3) — read-only convergence verifier. Reads every
     * `accountingClosingBalances` doc for this school+session via
     * firestoreGetParallel — the same code path Operations_accounting's
     * v2 commit batch uses to read CAS metadata — and counts docs where
     * the `__updateTime` field is missing.
     *
     * Use this to confirm the backfill_balance_updatetime helper has
     * fully converged: PASS = zero missing-CAS docs = every future v2
     * commit will be CAS-protected. FAIL = run backfill again, then
     * re-verify.
     *
     * Mutates nothing. Idempotent. Safe to run during live traffic.
     */
    public function verify_balance_cas_compliance(): void
    {
        $this->_out("AccountingReconciler verify_balance_cas_compliance @ " . date('c'));
        $this->_out("  scope: schoolId={$this->schoolFs} session={$this->session}");

        $compliant = 0;
        $missing   = [];
        $errors    = 0;

        try {
            // Discover all balance docs for this school+session.
            $rows = (array) $this->firebase->firestoreQuery('accountingClosingBalances', [
                ['schoolId', '==', $this->schoolFs],
                ['session',  '==', $this->session],
            ], null, 'ASC', 5000);

            $this->_out("  found " . count($rows) . " balance docs");

            // Re-read each via firestoreGetParallel — the same path the
            // v2 commit batch uses. If __updateTime is missing here, it
            // is also missing there, and the v2 path will emit
            // ACC_BAL_NO_UPDATETIME on the next commit against this
            // account. Doing it via Parallel rather than per-doc Get
            // matches the contention path exactly.
            $reqs = [];
            foreach ($rows as $r) {
                $d    = is_array($r['data'] ?? null) ? $r['data'] : [];
                $code = (string) ($d['accountCode'] ?? '');
                if ($code === '') continue;
                $reqs[$code] = [
                    'collection' => 'accountingClosingBalances',
                    'docId'      => "{$this->schoolFs}_{$this->session}_{$code}",
                ];
            }

            $batch = (array) $this->firebase->firestoreGetParallel($reqs);

            foreach ($reqs as $code => $req) {
                $doc = $batch[$code] ?? null;
                if (!is_array($doc)) {
                    $errors++;
                    continue;
                }
                $ut = (string) ($doc['__updateTime'] ?? '');
                if ($ut === '') {
                    $missing[] = $code;
                } else {
                    $compliant++;
                }
            }

            // Console + log report.
            $this->_out("  compliant: {$compliant}");
            $this->_out("  missing __updateTime: " . count($missing));
            if (!empty($missing)) {
                // Cap the printed list at 50 codes — anything more is
                // a list-eyes-glaze territory anyway, and the full count
                // is in the line above.
                $sample = array_slice($missing, 0, 50);
                $suffix = (count($missing) > 50) ? ' …(+' . (count($missing) - 50) . ' more)' : '';
                $this->_out("  affected codes: " . implode(',', $sample) . $suffix);
            }
            $this->_out("  read errors: {$errors}");

            $passed = empty($missing) && $errors === 0;
            $this->_out("  result: " . ($passed ? 'PASS' : 'FAIL'));

            log_message('error',
                "ACC_RECON_BAL_CAS_VERIFY schoolId={$this->schoolFs} "
                . "session={$this->session} compliant={$compliant} "
                . "missing=" . count($missing) . " errors={$errors} "
                . "result=" . ($passed ? 'PASS' : 'FAIL'));

            if (!$passed) {
                $this->_out("  REMEDIATION: run `php index.php accountingreconciler backfill_balance_updatetime` and re-verify.");
            }
        } catch (\Throwable $e) {
            $this->_out("  ERROR: " . $e->getMessage());
            log_message('error', 'ACC_RECON_BAL_CAS_VERIFY_ERROR: ' . $e->getMessage());
        }
    }

    /**
     * CLI: `php index.php accountingreconciler cleanup_stale_indexes`
     *
     * Phase 4.5 (V-LOW-2) — historical-drift cleanup. Pre-Phase-4.5
     * soft-deletes left their canonical index docs in
     * accountingIndexByDate and accountingIndexByAccount. New deletes
     * (post-Phase-4.5) clean inline; this CLI catches the historical
     * backlog.
     *
     * For each `accounting` doc with `status='deleted'` (capped at 5000
     * per run for safety), removes the per-date and per-account index
     * docs. Idempotent: re-running on already-clean entries is a no-op
     * (Firestore delete on missing doc is harmless).
     *
     * Mutates only index collections — never the canonical ledger,
     * never closing balances. Safe during live traffic.
     */
    public function cleanup_stale_indexes(): void
    {
        $this->_out("AccountingReconciler cleanup_stale_indexes @ " . date('c'));
        $this->_out("  scope: schoolId={$this->schoolFs} session={$this->session}");

        $scanned = 0;
        $cleaned = 0;
        $failed  = 0;

        try {
            // Same composite-index dependency as the regular index sweep:
            // (schoolId, session, status, entryId).
            $deleted = (array) $this->firebase->firestoreQuery(self::COL_LEDGER, [
                ['schoolId', '==', $this->schoolFs],
                ['session',  '==', $this->session],
                ['status',   '==', 'deleted'],
            ], 'entryId', 'ASC', 5000);

            $this->_out("  found " . count($deleted) . " soft-deleted entries");

            foreach ($deleted as $r) {
                $d         = is_array($r['data'] ?? null) ? $r['data'] : [];
                $entryId   = (string) ($d['entryId'] ?? '');
                if ($entryId === '') continue;
                $entryDate = (string) ($d['date'] ?? '');

                $scanned++;

                // Date index doc.
                if ($entryDate !== '') {
                    try {
                        $this->firebase->firestoreDelete('accountingIndexByDate',
                            "{$this->schoolFs}_{$this->session}_{$entryDate}_{$entryId}");
                        $cleaned++;
                    } catch (\Throwable $e) {
                        log_message('error',
                            "ACC_RECON_IDX_CLEAN_FAILED kind=date entryId={$entryId} "
                            . "date={$entryDate} error=" . $e->getMessage());
                        $failed++;
                    }
                }

                // Per-account index docs (one per affected line).
                foreach (($d['lines'] ?? []) as $line) {
                    $ac = (string) ($line['account_code'] ?? '');
                    if ($ac === '') continue;
                    try {
                        $this->firebase->firestoreDelete('accountingIndexByAccount',
                            "{$this->schoolFs}_{$this->session}_{$ac}_{$entryId}");
                        $cleaned++;
                    } catch (\Throwable $e) {
                        log_message('error',
                            "ACC_RECON_IDX_CLEAN_FAILED kind=account entryId={$entryId} "
                            . "accountCode={$ac} error=" . $e->getMessage());
                        $failed++;
                    }
                }

                // Periodic heartbeat refresh during long cleanup runs.
                if ($scanned % 100 === 0) {
                    $this->_writeHeartbeat('cleanup_stale_indexes');
                }
            }

            $this->_out("  done: scanned_entries={$scanned} index_docs_cleaned={$cleaned} failed={$failed}");
            log_message('error',
                "ACC_RECON_IDX_CLEANUP_COMPLETE schoolId={$this->schoolFs} "
                . "session={$this->session} scanned={$scanned} "
                . "cleaned={$cleaned} failed={$failed}");
        } catch (\Throwable $e) {
            $this->_out("  ERROR: " . $e->getMessage());
            log_message('error', 'ACC_RECON_IDX_CLEANUP_ERROR: ' . $e->getMessage());
        }
    }

    /**
     * Phase G1 — best-effort forensic replay event. Lazy-loads the
     * Accounting_forensics library and writes an append-only event to
     * the accountingForensics collection. Failures log but never block
     * the reconciler sweep.
     */
    private function _recordForensicReplay(string $entryId, string $reason, int $attemptCount): void
    {
        if ($entryId === '') return;
        try {
            if (!isset($this->acctForensics)) {
                $this->load->library('Accounting_forensics', null, 'acctForensics');
                $this->acctForensics->init($this->firebase, $this->schoolFs, $this->session);
            }
            $this->acctForensics->recordReplay($entryId, $reason, $attemptCount, '');
        } catch (\Throwable $e) {
            log_message('error',
                "ACC_FORENSIC_HOOK_FAILED entryId={$entryId} stage=replay "
                . "error=" . $e->getMessage());
        }
    }

    /**
     * json_error shim — CLI variant. Operations_accounting::create_journal
     * (now the v2 path, post B2) calls $this->CI->json_error() on certain
     * fatal conditions (period lock, group-account post, idempotency-
     * service unavailable, CAS storm). In an HTTP controller json_error
     * halts the request; in CLI a process-level exit would tear the whole
     * sweep down mid-loop. Throwing here lets the per-refund try/catch in
     * _sweepRefundJournalDrift record the failure on the affected refund
     * and continue with the next one. The string passed here is exactly
     * the user-facing error Operations_accounting would have surfaced —
     * we record it on the refund doc as journalLastError.
     */
    public function json_error(string $msg): void
    {
        throw new \RuntimeException($msg);
    }

    /** CLI: `php index.php accountingreconciler health` */
    public function health(): void
    {
        echo json_encode($this->_healthSnapshot(), JSON_PRETTY_PRINT) . "\n";
    }

    /**
     * CLI: `php index.php accountingreconciler anomalies`
     *
     * Phase G1 (financial governance, 2026-05-10) — rule-based anomaly
     * detection. Scans heartbeat fields, idempotency state, refund
     * status, forensic events, and audit log markers to surface
     * suspicious operational patterns. NO ML, NO probabilistic scoring
     * — every signal is a deterministic threshold check against
     * structured state already produced by Phase 1-4.5 hardening.
     *
     * Output: severity-ranked anomaly list (CRITICAL / HIGH / MEDIUM /
     * LOW), affected journals where applicable, suggested operator
     * action. Exits 0 always (informational); operators wire alerting
     * off the structured log lines.
     *
     * Read-only. Safe during live traffic.
     */
    public function anomalies(): void
    {
        $this->_out("Accounting Anomaly Scan @ " . date('c'));
        $this->_out("  schoolId={$this->schoolFs} session={$this->session}");
        $this->_out(str_repeat('-', 64));

        $anomalies = [];   // each: [severity, label, evidence, suggested_action]
        $now       = time();

        try {
            $hb = $this->firebase->firestoreGet(self::COL_HEARTBEAT,
                "{$this->schoolFs}_{$this->session}");

            // ── Reconciler liveness anomalies ────────────────────────
            if (!is_array($hb) || empty($hb['lastRunAt'])) {
                $anomalies[] = [
                    'CRITICAL', 'No heartbeat doc — reconciler has never run',
                    'No accountingReconHeartbeat doc found',
                    'Configure cron: php index.php accountingreconciler run every 15 min',
                ];
            } else {
                $age = max(0, $now - strtotime((string) $hb['lastRunAt']));
                if ($age > 1800) {
                    $anomalies[] = [
                        'CRITICAL', 'Reconciler heartbeat stale (>30 min)',
                        "Last run {$age}s ago",
                        'Verify cron, check php-fpm logs, restart scheduler',
                    ];
                } elseif ($age > 1200) {
                    $anomalies[] = [
                        'HIGH', 'Reconciler heartbeat aging (>20 min)',
                        "Last run {$age}s ago, expected every 15 min",
                        'Watch for next cycle; investigate if stuck',
                    ];
                }

                // ── Drift accumulation ───────────────────────────────
                $balDrifted   = (int) ($hb['balanceDrifted']  ?? 0);
                $balFailed    = (int) ($hb['balanceFailed']   ?? 0);
                $balRepaired  = (int) ($hb['balanceRepaired'] ?? 0);
                if ($balFailed > 0) {
                    $anomalies[] = [
                        'HIGH', 'Balance repair failures last cycle',
                        "balanceFailed={$balFailed} drifted={$balDrifted} repaired={$balRepaired}",
                        'Grep ACC_RECON_BAL_REPAIR_CAS_CONFLICT and ACC_RECON_BAL_REPAIR_NO_CAS for context',
                    ];
                }
                if ($balDrifted > 5 && $balRepaired < $balDrifted) {
                    $anomalies[] = [
                        'MEDIUM', 'High drift volume not fully repaired',
                        "drifted={$balDrifted} repaired={$balRepaired}",
                        'Run admin Recompute Balances; investigate root cause',
                    ];
                }

                // ── Refund drift suspended ───────────────────────────
                $refSuspended = (int) ($hb['refundDriftSuspended'] ?? 0);
                if ($refSuspended > 0) {
                    $anomalies[] = [
                        'HIGH', 'Suspended refund journals (>5 retry exhausted)',
                        "suspended_count={$refSuspended}",
                        'Inspect feeRefunds with journalRetryCount>=5; resolve via admin Retry Journal',
                    ];
                }

                // ── Pass abandonment risk ─────────────────────────────
                $balPass = is_array($hb['bal_scan_state'] ?? null) ? $hb['bal_scan_state'] : null;
                if (is_array($balPass)) {
                    $windows = (int) ($balPass['windows_processed'] ?? 0);
                    if ($windows >= self::BAL_PASS_MAX_WINDOWS - 5) {
                        $anomalies[] = [
                            'HIGH', 'Balance pass approaching abandonment ceiling',
                            "windows={$windows}/" . self::BAL_PASS_MAX_WINDOWS,
                            'Investigate scale; raise BAL_PASS_MAX_WINDOWS or cut MAX_BALANCE_DRIFT_SCAN per cycle',
                        ];
                    }
                }

                // ── Pass-completion latency ──────────────────────────
                $lastBalPass = (string) ($hb['last_bal_pass_at'] ?? '');
                if ($lastBalPass !== '') {
                    $passAge = max(0, $now - strtotime($lastBalPass));
                    if ($passAge > 86400) {
                        $anomalies[] = [
                            'HIGH', 'Balance pass not completed in 24+ hours',
                            "Last pass {$passAge}s ago",
                            'Pass may be stuck; check bal_scan_state.windows_processed',
                        ];
                    }
                }
            }

            // ── Idempotency anomalies ────────────────────────────────
            $stuck = (array) $this->firebase->firestoreQuery(self::COL_IDEMP, [
                ['schoolId', '==', $this->schoolFs],
                ['session',  '==', $this->session],
                ['status',   '==', 'processing'],
            ], 'startedAt', 'ASC', 100);
            $stuckCount = 0;
            $threshold  = date('c', $now - self::STUCK_IDEMP_SEC);
            foreach ($stuck as $s) {
                $started = (string) (($s['data']['startedAt'] ?? ''));
                if ($started !== '' && $started < $threshold) $stuckCount++;
            }
            if ($stuckCount >= 5) {
                $anomalies[] = [
                    'HIGH', 'Idempotency backlog accumulating',
                    "stuck_count={$stuckCount} threshold=" . self::STUCK_IDEMP_SEC . 's',
                    'Reconciler should clear within 1 cycle; if persistent, investigate worker death rate',
                ];
            } elseif ($stuckCount > 0) {
                $anomalies[] = [
                    'LOW', 'Stuck idempotency slots detected',
                    "stuck_count={$stuckCount}",
                    'Self-healing within 1 reconciler cycle (15 min)',
                ];
            }

            // ── Failed idempotency volume ────────────────────────────
            $failed = (array) $this->firebase->firestoreQuery(self::COL_IDEMP, [
                ['schoolId', '==', $this->schoolFs],
                ['session',  '==', $this->session],
                ['status',   '==', 'failed'],
            ], null, 'ASC', 100);
            $failedCount = count($failed);
            if ($failedCount >= 10) {
                $anomalies[] = [
                    'MEDIUM', 'Failed idempotency claims accumulating',
                    "failed_count={$failedCount}",
                    'Inspect feeReceipts with status=posted but no JE_FEE_* ledger; manual repair required',
                ];
            }

            // ── Forensic-event-driven anomalies ──────────────────────
            try {
                $this->load->library('Accounting_forensics', null, 'acctForensics');
                $this->acctForensics->init($this->firebase, $this->schoolFs, $this->session);

                $reversals24h = $this->acctForensics->countRecentEvents('reversed', 86400);
                if ($reversals24h >= 20) {
                    $anomalies[] = [
                        'HIGH', 'Abnormal reversal volume in last 24h',
                        "reversals={$reversals24h}",
                        'Spot-check accountingForensics where eventType=reversed; investigate operator pattern',
                    ];
                } elseif ($reversals24h >= 10) {
                    $anomalies[] = [
                        'MEDIUM', 'Elevated reversal volume in last 24h',
                        "reversals={$reversals24h}",
                        'Operator workflow review recommended',
                    ];
                }

                $replays24h = $this->acctForensics->countRecentEvents('replayed', 86400);
                if ($replays24h >= 50) {
                    $anomalies[] = [
                        'HIGH', 'Replay storm — reconciler retrying many journals',
                        "replays={$replays24h}",
                        'Investigate sustained worker death or Firestore degradation',
                    ];
                } elseif ($replays24h >= 20) {
                    $anomalies[] = [
                        'MEDIUM', 'Elevated replay activity',
                        "replays={$replays24h}",
                        'Track over next 48h; investigate if increasing',
                    ];
                }

                $reopenPosts24h = $this->acctForensics->countRecentEvents('reopened_post', 86400);
                if ($reopenPosts24h >= 5) {
                    $anomalies[] = [
                        'MEDIUM', 'Multiple journals posted during reopened periods',
                        "reopened_posts={$reopenPosts24h}",
                        'Verify reopen reasons in accountingPeriodReopens are valid',
                    ];
                }
            } catch (\Throwable $e) {
                log_message('error', "ACC_ANOMALY_FORENSIC_SCAN_FAILED: " . $e->getMessage());
            }

            // ── Period reopen frequency ──────────────────────────────
            try {
                $reopens30d = (array) $this->firebase->firestoreQuery('accountingPeriodReopens', [
                    ['schoolId', '==', $this->schoolFs],
                    ['session',  '==', $this->session],
                    ['reopened_at', '>=', date('c', $now - 30 * 86400)],
                ], 'reopened_at', 'DESC', 50);
                $reopenCount30d = count($reopens30d);
                if ($reopenCount30d >= 5) {
                    $anomalies[] = [
                        'MEDIUM', 'Frequent period reopens in last 30 days',
                        "reopen_count={$reopenCount30d}",
                        'Review accountingPeriodReopens for governance pattern',
                    ];
                }
            } catch (\Throwable $e) {
                // Collection may not exist on first run — silent.
            }
        } catch (\Throwable $e) {
            $this->_out("  ERROR during anomaly scan: " . $e->getMessage());
            log_message('error', 'ACC_ANOMALY_SCAN_ERROR: ' . $e->getMessage());
        }

        // ── Output ───────────────────────────────────────────────────
        if (empty($anomalies)) {
            $this->_out("\nNo anomalies detected.");
            $this->_out("Status: HEALTHY\n");
            log_message('error',
                "ACC_ANOMALY_SCAN schoolId={$this->schoolFs} session={$this->session} count=0");
            return;
        }

        // Sort by severity rank.
        $rank = ['CRITICAL' => 1, 'HIGH' => 2, 'MEDIUM' => 3, 'LOW' => 4];
        usort($anomalies, function ($a, $b) use ($rank) {
            return ($rank[$a[0]] ?? 9) <=> ($rank[$b[0]] ?? 9);
        });

        $this->_out("\nDetected " . count($anomalies) . " anomalies:\n");
        foreach ($anomalies as $i => $a) {
            $n = $i + 1;
            $this->_out("[{$a[0]}] #{$n}: {$a[1]}");
            $this->_out("    Evidence: {$a[2]}");
            $this->_out("    Action:   {$a[3]}");
            $this->_out("");
        }

        log_message('error',
            "ACC_ANOMALY_SCAN schoolId={$this->schoolFs} session={$this->session} "
            . "count=" . count($anomalies)
            . " critical=" . count(array_filter($anomalies, fn($a) => $a[0] === 'CRITICAL'))
            . " high="     . count(array_filter($anomalies, fn($a) => $a[0] === 'HIGH'))
            . " medium="   . count(array_filter($anomalies, fn($a) => $a[0] === 'MEDIUM'))
            . " low="      . count(array_filter($anomalies, fn($a) => $a[0] === 'LOW')));
    }

    /**
     * CLI: `php index.php accountingreconciler status`
     *
     * Phase 4.5 (V-MED-OBS) — human-readable operator dashboard. Same
     * underlying data as `health` (which returns JSON for programmatic
     * consumption), formatted as a console-readable summary.
     *
     * Operators run this during incidents or as a quick "is it healthy
     * right now?" check. The verdict at the bottom is computed from
     * threshold-based checks of the snapshot fields — anything flagged
     * means the operator should investigate.
     */
    public function status(): void
    {
        $snap = $this->_healthSnapshot();
        $hb   = $snap['heartbeat'] ?? null;
        $now  = time();

        $issues = [];

        echo "Accounting Reconciler Status @ " . date('c') . "\n";
        echo "  schoolId={$snap['schoolId']} session={$snap['session']}\n";
        echo str_repeat('-', 64) . "\n";

        // ── Heartbeat ────────────────────────────────────────────────
        echo "Heartbeat:\n";
        if (is_array($hb) && !empty($hb['last_run_at'])) {
            $age   = (int) ($hb['age_seconds'] ?? 0);
            $stage = (string) ($hb['last_stage'] ?? '?');
            $ageStr = $this->_humanAge($age);
            $stale  = $age > 1200;   // 20+ minutes is stale (cron is 15-min)
            echo "  Last run:        {$ageStr} ago (stage: {$stage})\n";
            if ($stale) {
                echo "  ! WARNING: heartbeat is stale — reconciler may not be running\n";
                $issues[] = "heartbeat stale ({$ageStr})";
            }
        } else {
            echo "  Last run:        never (no heartbeat doc)\n";
            $issues[] = "no heartbeat";
        }
        echo "\n";

        // ── Idempotency ──────────────────────────────────────────────
        echo "Idempotency:\n";
        echo "  Stuck (>5min):   {$snap['stuck_idempotency']}\n";
        echo "  Failed:          {$snap['failed_idempotency']}\n";
        if ((int) $snap['stuck_idempotency'] > 0) {
            $issues[] = "{$snap['stuck_idempotency']} stuck idempotency slots";
        }
        echo "\n";

        // ── Refunds ──────────────────────────────────────────────────
        echo "Refund drift:\n";
        echo "  Unposted:        {$snap['unposted_refunds']}\n";
        echo "  Suspended:       {$snap['suspended_refunds']}\n";
        if ((int) $snap['suspended_refunds'] > 0) {
            $issues[] = "{$snap['suspended_refunds']} suspended refund journals";
        }
        echo "\n";

        // ── Index drift ──────────────────────────────────────────────
        echo "Index drift:\n";
        $idx = $snap['last_index_drift'] ?? null;
        if ($idx) {
            echo "  Last cycle:      scanned={$idx['scanned']} missing={$idx['missing']} "
               . "repaired={$idx['repaired']} failed={$idx['failed']}\n";
        } else {
            echo "  Last cycle:      no data yet\n";
        }
        $idxPass = (string) ($snap['last_idx_pass_at'] ?? '');
        if ($idxPass !== '') {
            $passAge = max(0, $now - strtotime($idxPass));
            echo "  Last pass:       PASS_DONE " . $this->_humanAge($passAge) . " ago\n";
        } else {
            echo "  Last pass:       (no completed pass yet)\n";
        }
        $idxCursor = (string) ($snap['pending_index_cursor'] ?? '');
        if ($idxCursor !== '') {
            echo "  In progress:     cursor at {$idxCursor}\n";
        }
        echo "\n";

        // ── Balance drift ────────────────────────────────────────────
        echo "Balance drift:\n";
        $bal = $snap['last_balance_drift'] ?? null;
        if ($bal) {
            echo "  Last cycle:      accounts_scanned={$bal['accounts_scanned']} "
               . "drifted={$bal['drifted']} repaired={$bal['repaired']} "
               . "failed={$bal['failed']} window={$bal['window_size']}\n";
            if ((int) $bal['failed'] > 0) {
                $issues[] = "{$bal['failed']} balance repair failures last cycle";
            }
        } else {
            echo "  Last cycle:      no data yet\n";
        }
        $balPass = (string) ($snap['last_bal_pass_at'] ?? '');
        if ($balPass !== '') {
            $passAge = max(0, $now - strtotime($balPass));
            $passAgeStr = $this->_humanAge($passAge);
            echo "  Last pass:       PASS_DONE {$passAgeStr} ago\n";
            // Balance-pass cadence depends on school size; flag only if
            // > 24 hours since last completion (covers the 20k-student
            // ~5.5h pass cadence with comfortable margin).
            if ($passAge > 86400) {
                $issues[] = "no balance-pass completion in 24h";
            }
        } else {
            echo "  Last pass:       (no completed pass yet)\n";
        }
        $pending = $snap['pending_balance_pass'] ?? null;
        if (is_array($pending)) {
            $startedAge = max(0, $now - strtotime((string) $pending['scan_start_ts']));
            echo "  In progress:     windows={$pending['windows_processed']} "
               . "agg_accounts={$pending['agg_accounts']} "
               . "started=" . $this->_humanAge($startedAge) . " ago\n";
            if ($pending['windows_processed'] >= self::BAL_PASS_MAX_WINDOWS - 5) {
                $issues[] = "balance pass approaching abandonment ceiling ({$pending['windows_processed']}/" . self::BAL_PASS_MAX_WINDOWS . ")";
            }
        }
        echo "\n";

        // ── Governance (Phase G1) ────────────────────────────────────
        // Read-only summary of governance-relevant activity. Best-effort:
        // each block wraps in try/catch so a single failed query
        // doesn't block the rest of the CLI output.
        echo "Governance:\n";
        $now = time();
        try {
            $this->load->library('Accounting_forensics', null, 'acctForensics');
            $this->acctForensics->init($this->firebase, $this->schoolFs, $this->session);
            $reversals24h = $this->acctForensics->countRecentEvents('reversed', 86400);
            $replays24h   = $this->acctForensics->countRecentEvents('replayed', 86400);
            $reopened24h  = $this->acctForensics->countRecentEvents('reopened_post', 86400);
            echo "  Reversals (24h):      {$reversals24h}\n";
            echo "  Replays (24h):        {$replays24h}\n";
            echo "  Reopened-period posts: {$reopened24h}\n";
            if ($reversals24h >= 10) {
                $issues[] = "elevated reversal volume in last 24h ({$reversals24h})";
            }
        } catch (\Throwable $e) {
            echo "  Forensic query unavailable: " . $e->getMessage() . "\n";
        }

        // Recent period reopens
        try {
            $reopens = (array) $this->firebase->firestoreQuery('accountingPeriodReopens', [
                ['schoolId', '==', $this->schoolFs],
                ['session',  '==', $this->session],
                ['reopened_at', '>=', date('c', $now - 30 * 86400)],
            ], 'reopened_at', 'DESC', 5);
            $openReopens = 0;
            foreach ($reopens as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : [];
                if (empty($d['closed_at'])) $openReopens++;
            }
            echo "  Period reopens (30d): " . count($reopens);
            if ($openReopens > 0) echo "  ({$openReopens} still open)";
            echo "\n";
            if ($openReopens >= 1) {
                $issues[] = "{$openReopens} reopened period(s) not yet re-closed";
            }
        } catch (\Throwable $_) {
            echo "  Period reopens (30d): collection not yet created\n";
        }
        echo "\n";

        // ── Compliance ───────────────────────────────────────────────
        echo "Compliance helpers (run manually if reconciler reports issues):\n";
        echo "  __updateTime:    php index.php accountingreconciler verify_balance_cas_compliance\n";
        echo "  Stale indexes:   php index.php accountingreconciler cleanup_stale_indexes\n";
        echo "  Backfill CAS:    php index.php accountingreconciler backfill_balance_updatetime\n";
        echo "  Anomaly scan:    php index.php accountingreconciler anomalies\n";
        echo "  Permissions:     php index.php accountingreconciler permissions\n";
        echo "\n";

        // ── Verdict ──────────────────────────────────────────────────
        echo str_repeat('=', 64) . "\n";
        if (empty($issues)) {
            echo "OVERALL: HEALTHY\n";
        } else {
            echo "OVERALL: DEGRADED — " . count($issues) . " issue(s):\n";
            foreach ($issues as $i) echo "  - {$i}\n";
        }
    }

    /**
     * CLI: `php index.php accountingreconciler permissions`
     *
     * Phase G1 (financial governance) — read-only inventory of who can
     * perform sensitive accounting actions. Reads role constants from
     * the Accounting controller and emits a human-readable matrix.
     *
     * Operators consult this when investigating "who could have done X"
     * during a forensic review, or when planning the role assignments
     * for a new admin. NO write operations; no role changes; no policy
     * enforcement — this is purely visibility.
     */
    public function permissions(): void
    {
        echo "Accounting Permission Matrix @ " . date('c') . "\n";
        echo "  schoolId={$this->schoolFs} session={$this->session}\n";
        echo str_repeat('-', 64) . "\n";

        // Pull the role constants from the Accounting controller via
        // reflection — keeps this CLI in lockstep with whatever the
        // Accounting controller actually enforces, without duplicating
        // the role lists. If reflection fails (e.g. during early
        // bootstrap), fall back to the documented role groups.
        $financeRoles = [];
        $adminRoles   = [];
        try {
            require_once APPPATH . 'controllers/Accounting.php';
            $rc = new \ReflectionClass('Accounting');
            $financeRoles = (array) $rc->getConstant('FINANCE_ROLES');
            $adminRoles   = (array) $rc->getConstant('ADMIN_ROLES');
        } catch (\Throwable $e) {
            log_message('error', 'ACC_PERMISSIONS_REFLECTION_FAILED: ' . $e->getMessage());
            $financeRoles = ['Admin', 'Super Admin', 'School Super Admin', 'Our Panel',
                             'Accountant', 'Finance', 'Principal', 'Vice Principal'];
            $adminRoles   = ['Admin', 'Super Admin', 'School Super Admin', 'Our Panel', 'Principal'];
        }

        // Operations matrix. Each row maps a sensitive action to the
        // role group enforced at the controller and a one-line note
        // about what the action does. Information sourced from the
        // _require_role calls in Accounting.php.
        $ops = [
            // [action, role_group_label, description]
            ['Post manual journal',         'FINANCE', 'Accounting::save_journal_entry'],
            ['Soft-delete journal entry',   'FINANCE', 'Accounting::delete_journal_entry'],
            ['Finalize entry (immutable)',  'FINANCE', 'Accounting::finalize_entry'],
            ['Post income/expense',         'FINANCE', 'Accounting::save_income_expense'],
            ['View ledger / reports',       'FINANCE', 'Trial Balance / P&L / Balance Sheet / Cash Flow'],
            ['Bank reconciliation',         'FINANCE', 'Accounting::import_bank_statement / match_transaction'],
            ['Manage Chart of Accounts',    'ADMIN',   'Accounting::save_account / delete_account'],
            ['Seed default chart',          'ADMIN',   'Accounting::seed_default_chart'],
            ['Lock period',                 'ADMIN',   'Accounting::lock_period'],
            ['Reopen period (G1)',          'ADMIN',   'Accounting::reopen_period (governance-controlled)'],
            ['Recompute balances',          'ADMIN',   'Accounting::recompute_balances (full ledger pass)'],
            ['Carry-forward balances',      'ADMIN',   'Accounting::carry_forward_balances (year-end)'],
            ['Migrate accounts',            'ADMIN',   'Accounting::migrate_existing_accounts'],
            ['View audit log',              'FINANCE', 'Accounting::get_audit_log'],
            ['View period reopens (G1)',    'FINANCE', 'Accounting::get_period_reopens'],
        ];

        // Render matrix.
        echo "\nFINANCE_ROLES (" . count($financeRoles) . "): " . implode(', ', $financeRoles) . "\n";
        echo "ADMIN_ROLES   (" . count($adminRoles)   . "): " . implode(', ', $adminRoles)   . "\n";
        echo "\n";

        echo str_pad('Action', 32) . str_pad('Required role', 14) . "Endpoint\n";
        echo str_repeat('-', 76) . "\n";
        foreach ($ops as $row) {
            echo str_pad($row[0], 32) . str_pad($row[1], 14) . $row[2] . "\n";
        }
        echo "\n";

        // Reconciler-driven actions for completeness (no role check —
        // CLI-invoked, runs as service account).
        echo "RECONCILER (CLI / service account, no role check):\n";
        echo "  - Auto-repair fee drift          (AccountingReconciler::run)\n";
        echo "  - Auto-repair refund drift       (AccountingReconciler::run)\n";
        echo "  - Index drift sweep              (AccountingReconciler::run)\n";
        echo "  - Balance drift sweep            (AccountingReconciler::run)\n";
        echo "  - Manual maintenance CLIs        (backfill_balance_updatetime,\n";
        echo "                                    cleanup_stale_indexes,\n";
        echo "                                    verify_balance_cas_compliance,\n";
        echo "                                    anomalies, permissions)\n";
        echo "\n";

        // Forensic visibility note — these CLIs read but never write.
        echo str_repeat('=', 76) . "\n";
        echo "All operator-sensitive actions are audit-logged to accountingAudit.\n";
        echo "Reconciler-driven actions are forensic-logged to accountingForensics.\n";
        echo "Period reopen events are also written to accountingPeriodReopens.\n";

        log_message('error',
            "ACC_PERMISSIONS_VIEWED schoolId={$this->schoolFs} session={$this->session} "
            . "ops_count=" . count($ops));
    }

    /**
     * Format a duration in seconds as a human-friendly string.
     * "45s", "12m", "3h", "2d 4h", etc. Used by the status CLI.
     */
    private function _humanAge(int $secs): string
    {
        if ($secs < 60)     return "{$secs}s";
        if ($secs < 3600)   return floor($secs / 60) . "m";
        if ($secs < 86400)  return floor($secs / 3600) . "h " . floor(($secs % 3600) / 60) . "m";
        $d = floor($secs / 86400);
        $h = floor(($secs % 86400) / 3600);
        return "{$d}d {$h}h";
    }

    // ─────────────────────────────────────────────────────────────────
    //  (1) Stuck idempotency sweep
    // ─────────────────────────────────────────────────────────────────

    private function _sweepStuckIdempotency(): array
    {
        $stats = ['recovered' => 0, 'orphaned' => 0];
        $threshold = date('c', time() - self::STUCK_IDEMP_SEC);

        try {
            $rows = (array) $this->firebase->firestoreQuery(self::COL_IDEMP, [
                ['schoolId', '==', $this->schoolFs],
                ['session',  '==', $this->session],
                ['status',   '==', 'processing'],
            ], 'startedAt', 'ASC', self::MAX_IDEMP_SCAN);

            foreach ($rows as $r) {
                $d  = is_array($r['data'] ?? null) ? $r['data'] : [];
                $id = (string) ($r['id'] ?? '');
                $startedAt = (string) ($d['startedAt'] ?? '');
                $entryId   = (string) ($d['entryId']   ?? ($d['idempKey'] ?? ''));
                // Only act on slots older than the threshold. Fresh ones
                // may still legitimately complete.
                if ($id === '' || $startedAt === '' || $startedAt >= $threshold) continue;

                // Look up the ledger entry. If it landed, the earlier
                // writer simply died before flipping the slot.
                $ledger = null;
                if ($entryId !== '') {
                    $ledger = $this->firebase->firestoreGet(self::COL_LEDGER,
                        "{$this->schoolFs}_{$this->session}_{$entryId}");
                }

                if (is_array($ledger) && ($ledger['status'] ?? '') === 'active'
                    && !empty($ledger['entryId'])) {
                    $this->firebase->firestoreSet(self::COL_IDEMP, $id, [
                        'status'      => 'success',
                        'entryId'     => (string) $ledger['entryId'],
                        'completedAt' => date('c'),
                        'updatedAt'   => date('c'),
                        'recoveredBy' => 'reconciler',
                    ], /* merge */ true);
                    log_message('error', "ACC_IDEMP_RECOVERED slot={$id} entryId=" . $ledger['entryId']);
                    $stats['recovered']++;
                } else {
                    $this->firebase->firestoreSet(self::COL_IDEMP, $id, [
                        'status'      => 'failed',
                        'lastError'   => 'orphaned_processing',
                        'completedAt' => date('c'),
                        'updatedAt'   => date('c'),
                        'orphanedBy'  => 'reconciler',
                    ], /* merge */ true);
                    log_message('error', "ACC_IDEMP_ORPHANED slot={$id}");
                    $stats['orphaned']++;
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'ACC_RECON_IDEMP_ERROR: ' . $e->getMessage());
        }
        return $stats;
    }

    // ─────────────────────────────────────────────────────────────────
    //  (2) Fee → Accounting drift sweep
    //
    //  For each posted fee receipt older than 5 min, verify a matching
    //  ledger entry exists at JE_FEE_{receiptKey}. If absent, re-run
    //  create_fee_journal — safe because the v2 path is idempotent
    //  (same journal_entry_id = same slot).
    // ─────────────────────────────────────────────────────────────────

    private function _sweepFeeAccountingDrift(): array
    {
        $stats   = ['found' => 0, 'repaired' => 0, 'failed' => 0];
        $cutoff  = date('c', time() - self::DRIFT_AGE_SEC);

        try {
            $receipts = (array) $this->firebase->firestoreQuery(self::COL_RECEIPTS, [
                ['schoolId', '==', $this->schoolFs],
                ['session',  '==', $this->session],
                ['status',   '==', 'posted'],
            ], 'postedAt', 'DESC', self::MAX_DRIFT_SCAN);

            // We need a separate library instance for the auto-repair
            // calls — loaded once, reused.
            $opsAcct = null;

            foreach ($receipts as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : [];
                $postedAt   = (string) ($d['postedAt'] ?? '');
                $receiptKey = (string) ($d['receiptKey'] ?? '');
                if ($postedAt === '' || $receiptKey === ''
                    || $postedAt >= $cutoff) continue;

                // The canonical entryId for a fee receipt.
                $idempKey = "JE_FEE_{$receiptKey}";
                $ledger = $this->firebase->firestoreGet(self::COL_LEDGER,
                    "{$this->schoolFs}_{$this->session}_{$idempKey}");
                if (is_array($ledger) && ($ledger['status'] ?? '') === 'active') {
                    continue;   // clean — no drift
                }

                $stats['found']++;

                // Phase 8C (R3) — skip receipts whose idempotency slot
                // already shows high retry count. A truly poisoned
                // receipt (deleted student, broken CoA, rules mismatch)
                // would otherwise retry every 15 min forever and spam
                // the error log. Cap at 5 attempts; beyond that,
                // operator must inspect via the dashboard.
                $slot = $this->firebase->firestoreGet(self::COL_IDEMP,
                    "{$this->schoolFs}_{$idempKey}");
                $slotAttempts = is_array($slot) ? (int) ($slot['attempts'] ?? 0) : 0;
                if ($slotAttempts >= 5) {
                    log_message('error',
                        "ACC_RECON_DRIFT_SUSPENDED receipt={$receiptKey} "
                        . "idempKey={$idempKey} attempts={$slotAttempts} — "
                        . "giving up; operator must inspect.");
                    $stats['failed']++;
                    continue;
                }

                log_message('error',
                    "ACC_RECON_DRIFT receipt={$receiptKey} expectedEntry={$idempKey} "
                    . "postedAt={$postedAt} slotAttempts={$slotAttempts}");

                // Auto-repair: call create_fee_journal with the stable
                // idempKey. If another writer landed it between our
                // read and this call, v2 returns the existing entryId
                // (dedup) — idempotent.
                if ($opsAcct === null) {
                    $this->load->library('Operations_accounting', null, 'opsAcct');
                    $this->opsAcct->init(
                        $this->firebase, $this->schoolName, $this->session,
                        'SYSTEM_RECONCILER', $this);
                    $opsAcct = $this->opsAcct;
                }

                $payload = $this->_buildFeePayloadFromReceipt($d);
                if ($payload === null) {
                    log_message('error', "ACC_RECON_REPAIR_SKIP receipt={$receiptKey} reason=insufficient_receipt_data");
                    $stats['failed']++;
                    continue;
                }

                try {
                    $entryId = $opsAcct->create_fee_journal($payload);
                    if ($entryId) {
                        log_message('error', "ACC_RECON_REPAIRED receipt={$receiptKey} entryId={$entryId}");
                        $stats['repaired']++;
                        // Phase G1 — append replay event for forensic timeline.
                        $this->_recordForensicReplay($entryId, 'fee_drift_sweep', $slotAttempts);
                    } else {
                        log_message('error', "ACC_RECON_REPAIR_FAILED receipt={$receiptKey} reason=v2_returned_null");
                        $stats['failed']++;
                    }
                } catch (\Throwable $e) {
                    log_message('error',
                        "ACC_RECON_REPAIR_FAILED receipt={$receiptKey} error=" . $e->getMessage());
                    $stats['failed']++;
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'ACC_RECON_DRIFT_ERROR: ' . $e->getMessage());
        }
        return $stats;
    }

    /**
     * Reconstruct a payload compatible with Operations_accounting::create_fee_journal.
     * We pull from the receipt doc's fields that submit_fees wrote into
     * the feeJob payload.opsAcctPayload — same semantic source, just
     * rehydrated after the job doc may have aged out.
     *
     * Phase A0 (canonical fee-accounting normalization, 2026-05-10) —
     * additionally fetches the feeReceiptAllocations doc so the rebuilt
     * payload carries the per-demand allocation list. With allocations
     * present, the v2 journal path produces the same multi-line credit
     * structure (Cr 4010 Tuition / Cr 4040 Transport / Cr 4060 Late
     * Fee) that the original posting would have, instead of a generic
     * single-account fallback. If the allocation doc is missing or
     * unreadable, the payload still rebuilds without allocations and
     * the v2 path falls back to single-line — preserving prior
     * reconciler behaviour for legacy receipts that were posted before
     * allocation persistence existed.
     */
    private function _buildFeePayloadFromReceipt(array $receipt): ?array
    {
        $amount = (float) ($receipt['allocatedAmount'] ?? $receipt['amount'] ?? 0);
        $rcpt   = (string) ($receipt['receiptNo'] ?? '');
        if ($amount <= 0 || $rcpt === '') return null;

        $receiptKey = "F{$rcpt}";

        // Fetch the allocation breakdown so the rebuilt journal can be
        // multi-line per fee head. Best-effort: missing/unreadable doc
        // → empty allocations → v2 falls back to single-line journal.
        $allocations    = [];
        $fineAmount     = 0.0;
        $discountAmount = 0.0;
        try {
            $allocDocId = "{$this->schoolFs}_{$this->session}_{$receiptKey}";
            $allocDoc   = $this->firebase->firestoreGet('feeReceiptAllocations', $allocDocId);
            if (is_array($allocDoc)) {
                if (is_array($allocDoc['allocations'] ?? null)) {
                    $allocations = $allocDoc['allocations'];
                }
                $fineAmount     = round((float) ($allocDoc['fine']     ?? 0), 2);
                $discountAmount = round((float) ($allocDoc['discount'] ?? 0), 2);
            }
        } catch (\Throwable $e) {
            log_message('error',
                "ACC_RECON_REBUILD_ALLOCS_FAILED receipt={$receiptKey} "
                . "error=" . $e->getMessage()
                . " — proceeding with empty allocations (single-line fallback)");
        }

        return [
            'school_name'      => $this->schoolName,
            'session_year'     => $this->session,
            'date'             => (string) ($receipt['date']        ?? date('Y-m-d')),
            'amount'           => $amount,
            'payment_mode'     => strtolower((string) ($receipt['paymentMode'] ?? 'cash')),
            'bank_code'        => '',  // reconciler uses CoA default — fine for repair
            'receipt_no'       => $receiptKey,
            'student_name'     => (string) ($receipt['studentName'] ?? ''),
            'student_id'       => (string) ($receipt['studentId']   ?? ''),
            'class'            => trim(((string) ($receipt['className'] ?? '')) . ' ' . ((string) ($receipt['section'] ?? ''))),
            'admin_id'         => 'SYSTEM_RECONCILER',
            // Deterministic key — matches what the worker would have used
            'journal_entry_id' => "JE_FEE_{$receiptKey}",
            // Phase A0 — allocation breakdown for canonical multi-line
            // credit. If missing, v2 path falls back to single Cr 4010.
            'allocations'      => $allocations,
            'fine_amount'      => $fineAmount,
            'discount_amount'  => $discountAmount,
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    //  (3) Refund → Accounting drift sweep   — Phase 1 Tier-0 (B3)
    //
    //  Symptom we're catching:
    //    Fee_refund_service::process completes the operational refund
    //    (demands reversed, voucher written, defaulter projection synced)
    //    but the journal post fails — network blip, period-lock race,
    //    inactive CoA account, CAS storm. The refund doc carries
    //    journalPosted=false / journalLastError / journalRetryCount and
    //    waits for an admin to click "Retry Journal" in Fee_management.
    //    Without this sweep, a refund that nobody manually retries stays
    //    operationally complete but ledger-orphaned forever — books
    //    diverge, P&L silently wrong, audit trail missing the reversal.
    //
    //  What we do:
    //    1. Query feeRefunds where status='processed'.
    //    2. Filter to those with journalPosted === false AND
    //       journalLastAttemptAt > 5 min ago (give the primary path room
    //       to land its first auto-retry naturally).
    //    3. Skip if journalRetryCount >= MAX_REFUND_RETRIES — the
    //       reconciler caps its own retries the same way the fee-drift
    //       sweep does (line ~273) so a poisoned refund doesn't churn.
    //       Operator must intervene via the dashboard.
    //    4. Call Fee_refund_service::retryJournal($refId). That method
    //       is itself idempotent — it short-circuits if the refund is
    //       already posted, looks up an existing ledger entry by
    //       sourceRef before re-posting, and if it does post the journal
    //       it routes through Operations_accounting::create_refund_journal
    //       which (post-B2) is the v2-only path: period-locked,
    //       idempotency-claimed, CAS-protected.
    //    5. retryJournal updates the refund doc atomically with the
    //       outcome (journalPosted, journalEntryId, journalRetryCount,
    //       journalLastAttemptAt, journalLastError) so the next sweep
    //       cycle sees fresh state.
    //
    //  What we do NOT do:
    //    • Build refund payloads from scratch (the service already does it).
    //    • Mutate operational refund state (demands, vouchers, locks).
    //    • Touch refunds in 'approved' / 'pending' / 'processing' status
    //      (those are owned by the primary path).
    //    • Bypass Operations_accounting (idempotency would lose its grip).
    // ─────────────────────────────────────────────────────────────────

    private function _sweepRefundJournalDrift(): array
    {
        $stats  = ['found' => 0, 'repaired' => 0, 'failed' => 0, 'suspended' => 0];
        $cutoff = date('c', time() - self::REFUND_DRIFT_AGE_SEC);

        try {
            // Filter on schoolId + session + status server-side; refine
            // for journalPosted=false and stale lastAttempt in PHP. Keeps
            // the index requirement minimal — same shape as the fee-drift
            // query above (3 equality terms) — and avoids a doc-level
            // race where the sweep snapshots journalPosted=false but the
            // primary retry lands between fetch and decision.
            $refunds = (array) $this->firebase->firestoreQuery('feeRefunds', [
                ['schoolId', '==', $this->schoolFs],
                ['session',  '==', $this->session],
                ['status',   '==', 'processed'],
            ], null, 'ASC', self::MAX_REFUND_DRIFT_SCAN);

            $refundSvc = null;

            foreach ($refunds as $r) {
                $d     = is_array($r['data'] ?? null) ? $r['data'] : [];
                $refId = (string) ($r['id'] ?? ($d['refundId'] ?? ''));
                if ($refId === '') continue;

                // Only refunds that explicitly carry journalPosted=false.
                // Treat missing-field and journalPosted=true as out of
                // scope — older refunds posted via legacy paths may not
                // carry the field, and we don't want to re-post historic
                // ledger entries.
                $jp = $d['journalPosted'] ?? null;
                if ($jp !== false) continue;

                $lastAttempt = (string) ($d['journalLastAttemptAt'] ?? '');
                // Wait at least DRIFT_AGE_SEC from the most recent attempt
                // before stepping in. Fresh failures may auto-resolve via
                // FeeWorker or admin-initiated Retry without our help.
                if ($lastAttempt !== '' && $lastAttempt >= $cutoff) continue;

                $stats['found']++;

                $retries = (int) ($d['journalRetryCount'] ?? 0);
                if ($retries >= self::MAX_REFUND_RETRIES) {
                    log_message('error',
                        "ACC_RECON_REFUND_SUSPENDED refund={$refId} "
                        . "retries={$retries} maxRetries=" . self::MAX_REFUND_RETRIES . " "
                        . "lastError=" . (string) ($d['journalLastError'] ?? '')
                        . " — operator must inspect via Fee_management::retry_refund_journal.");
                    $stats['suspended']++;
                    $stats['failed']++;
                    continue;
                }

                log_message('error',
                    "ACC_RECON_REFUND_DRIFT refund={$refId} "
                    . "retries={$retries} "
                    . "lastAttempt={$lastAttempt} "
                    . "lastError=" . (string) ($d['journalLastError'] ?? ''));

                // Lazy-load the refund service. One instance shared
                // across the sweep — same pattern as opsAcct in the
                // fee-drift loop above.
                if ($refundSvc === null) {
                    $refundSvc = $this->_loadRefundService();
                    if ($refundSvc === null) {
                        log_message('error',
                            "ACC_RECON_REFUND_SVC_UNAVAILABLE — abandoning sweep mid-loop");
                        $stats['failed']++;
                        break;
                    }
                }

                try {
                    // retryJournal is idempotent on three levels:
                    //   1. refund.journalPosted=true → returns early.
                    //   2. findExistingRefundJournal hit → reattaches the
                    //      existing entryId without re-posting.
                    //   3. Otherwise posts via create_refund_journal[_granular],
                    //      which (post-B2) is the v2-only path with
                    //      idempotency-claim → CAS commit-batch → period-
                    //      lock guard.
                    // Either way, the refund doc lands in a consistent
                    // post-state with journalPosted, journalEntryId,
                    // journalRetryCount, journalLastAttemptAt, and
                    // journalLastError set for the next sweep cycle.
                    $result = $refundSvc->retryJournal($refId);
                    if (!empty($result['ok'])) {
                        log_message('error',
                            "ACC_RECON_REFUND_REPAIRED refund={$refId} "
                            . "entryId=" . (string) ($result['entryId'] ?? '')
                            . (!empty($result['already']) ? ' (already_posted)' : ''));
                        $stats['repaired']++;
                        // Phase G1 — append replay event for refund forensic timeline.
                        $entryIdForensic = (string) ($result['entryId'] ?? '');
                        if ($entryIdForensic !== '') {
                            $this->_recordForensicReplay(
                                $entryIdForensic,
                                !empty($result['already']) ? 'refund_drift_sweep_already_posted' : 'refund_drift_sweep',
                                $retries
                            );
                        }
                    } else {
                        log_message('error',
                            "ACC_RECON_REFUND_REPAIR_FAILED refund={$refId} "
                            . "error=" . (string) ($result['error'] ?? 'unknown'));
                        $stats['failed']++;
                    }
                } catch (\Throwable $e) {
                    // Catches the json_error→\RuntimeException path from
                    // Operations_accounting's v2 fatal conditions (period
                    // lock, group-account post, etc.) so the sweep keeps
                    // going. The refund doc was NOT updated by the
                    // service in this branch — the next cycle will see
                    // the same state and retry up to MAX_REFUND_RETRIES.
                    log_message('error',
                        "ACC_RECON_REFUND_REPAIR_FAILED refund={$refId} "
                        . "threw=" . $e->getMessage());
                    $stats['failed']++;
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'ACC_RECON_REFUND_DRIFT_ERROR: ' . $e->getMessage());
        }
        return $stats;
    }

    /**
     * Lazy-load Fee_refund_service with its dependency chain wired for
     * the CLI reconciler context. Cached statically — one init per run.
     * Returns null if any link in the chain fails to come up; the sweep
     * abandons cleanly rather than half-committing partial state.
     */
    private function _loadRefundService()
    {
        static $svc = null;
        if ($svc === false) return null;   // prior failure — don't keep retrying inside one run
        if ($svc !== null)  return $svc;

        try {
            // Fee_firestore_txn — the refund service's only Firestore client.
            // init signature: ($firebase, $fs, $schoolId, $session)
            $this->load->library('Fee_firestore_txn', null, 'fsTxn');
            $this->fsTxn->init(
                $this->firebase, $this->firestore_service,
                $this->schoolFs, $this->session
            );

            // Operations_accounting — the journal poster. _sweepFeeAccountingDrift
            // already loads this with the same alias if it ran first; the
            // re-init is a no-op in CI's library cache and just re-stamps
            // the same fields with the same values.
            $this->load->library('Operations_accounting', null, 'opsAcct');
            $this->opsAcct->init(
                $this->firebase, $this->schoolName, $this->session,
                'SYSTEM_RECONCILER', $this
            );

            // The refund service. SYSTEM_RECONCILER attribution distinguishes
            // automated retries from human-initiated ones in audit trails.
            $this->load->library('Fee_refund_service', null, 'refundSvc');
            $this->refundSvc->init(
                $this->fsTxn,
                'SYSTEM_RECONCILER',
                'SYSTEM_RECONCILER',
                $this->opsAcct
            );
            $svc = $this->refundSvc;
        } catch (\Throwable $e) {
            log_message('error', 'ACC_RECON_REFUND_SVC_LOAD_FAILED: ' . $e->getMessage());
            $svc = false;
            return null;
        }
        return $svc;
    }

    // ─────────────────────────────────────────────────────────────────
    //  (4) Index drift sweep   — Phase 2 (R-P4)
    //
    //  Symptom we're catching:
    //    The v2 commit batch (Operations_accounting::_create_journal_v2,
    //    _create_fee_journal_v2) writes the ledger doc and per-account
    //    closing balances atomically, but does NOT include the
    //    accountingIndexByDate / accountingIndexByAccount index docs
    //    that the legacy syncLedgerEntry path used to write. Until this
    //    sweep ships, every v2-posted entry (which is now ALL fee
    //    receipts, refunds, library fines, inventory purchases, asset
    //    posts, and post-R-NEW manual journals) lacks index docs.
    //
    //    Reports today scan the ledger collection directly via
    //    schoolWhere, so the missing indexes don't break correctness.
    //    But any future fast-query path (e.g. "show all entries for
    //    account 4010 in May") would silently miss them. This sweep
    //    eliminates that latent risk.
    //
    //  What we do:
    //    1. Query active ledger entries (limit MAX_INDEX_DRIFT_SCAN,
    //       ordered by updatedAt DESC so newest-first).
    //    2. For each entry, construct expected index doc IDs and check
    //       existence:
    //         accountingIndexByDate/{schoolId}_{session}_{date}_{entryId}
    //         accountingIndexByAccount/{schoolId}_{session}_{code}_{entryId}
    //         (one date doc per entry, one account doc per affected line)
    //    3. Write any missing index docs with the same shape
    //       Accounting_firestore_sync::syncLedgerEntry uses (idempotent
    //       set — re-running is harmless).
    //
    //  What we do NOT do:
    //    • Mutate ledger truth (lines, totals, status).
    //    • Delete stale indexes for soft-deleted entries (the
    //       _fs_idx_delete path in Accounting.php still handles that on
    //       the destructive write — the sweep only fixes "missing"
    //       drift, not "lingering" drift).
    //    • Write the legacy in-collection IDX_DATE_/IDX_ACCT_ docs
    //       (those served the pre-rebuild path; canonical going forward
    //       is the separate-collection variant).
    // ─────────────────────────────────────────────────────────────────

    private function _sweepIndexDrift(): array
    {
        $stats = ['scanned' => 0, 'missing' => 0, 'repaired' => 0, 'failed' => 0, 'pass_complete' => false];

        try {
            // Phase 4 (V-MED-5) — cursor-based pagination via heartbeat.
            // The cursor stores the entryId of the last entry processed
            // on the previous cycle. The next cycle resumes via Firestore
            // startAfter so we don't re-read entries we've already seen.
            // When the query returns fewer rows than the per-cycle cap,
            // we've reached the end of the ledger ordering: pass complete,
            // cursor resets, next cycle starts fresh from the beginning.
            $hb     = $this->firebase->firestoreGet(self::COL_HEARTBEAT,
                "{$this->schoolFs}_{$this->session}");
            $cursor = is_array($hb) ? (string) ($hb['idx_scan_cursor'] ?? '') : '';

            $startAfter = $cursor !== '' ? $cursor : null;
            // NOTE: ordering by `entryId` requires a composite index on
            // (schoolId, session, status, entryId) in Firestore. Phase 4
            // deployment plan documents the index requirement; without
            // it Firestore returns INDEX_NOT_FOUND on first run.
            $entries = (array) $this->firebase->firestoreQuery(self::COL_LEDGER, [
                ['schoolId', '==', $this->schoolFs],
                ['session',  '==', $this->session],
                ['status',   '==', 'active'],
            ], 'entryId', 'ASC', self::MAX_INDEX_DRIFT_SCAN, $startAfter);

            $rawCount    = count($entries);
            $lastEntryId = $cursor;
            $i           = 0;

            foreach ($entries as $r) {
                $d       = is_array($r['data'] ?? null) ? $r['data'] : [];
                $entryId = (string) ($d['entryId'] ?? '');
                // Skip non-ledger docs (BAL_*, IDX_DATE_*, IDX_ACCT_*
                // legacy in-collection docs that share the `accounting`
                // collection but aren't real entries — they have empty
                // entryId).
                if ($entryId === '') continue;
                $entryDate = (string) ($d['date'] ?? '');
                if ($entryDate === '') continue;

                $stats['scanned']++;
                $lastEntryId = $entryId;

                // Build the list of expected index docs and probe each.
                $missing = [];

                $dateIdxId = "{$this->schoolFs}_{$this->session}_{$entryDate}_{$entryId}";
                $dateIdx   = $this->firebase->firestoreGet('accountingIndexByDate', $dateIdxId);
                if (!is_array($dateIdx) || empty($dateIdx)) {
                    $missing[] = ['kind' => 'date', 'docId' => $dateIdxId, 'date' => $entryDate];
                }

                foreach (($d['lines'] ?? []) as $line) {
                    $ac = (string) ($line['account_code'] ?? '');
                    if ($ac === '') continue;
                    $acctIdxId = "{$this->schoolFs}_{$this->session}_{$ac}_{$entryId}";
                    $acctIdx   = $this->firebase->firestoreGet('accountingIndexByAccount', $acctIdxId);
                    if (!is_array($acctIdx) || empty($acctIdx)) {
                        $missing[] = ['kind' => 'account', 'docId' => $acctIdxId, 'accountCode' => $ac];
                    }
                }

                if (!empty($missing)) {
                    $stats['missing'] += count($missing);
                    log_message('error',
                        "ACC_RECON_INDEX_DRIFT entryId={$entryId} date={$entryDate} "
                        . "missing=" . count($missing));

                    try {
                        foreach ($missing as $m) {
                            if ($m['kind'] === 'date') {
                                $this->firebase->firestoreSet('accountingIndexByDate', $m['docId'], [
                                    'schoolId'  => $this->schoolFs,
                                    'session'   => $this->session,
                                    'date'      => $m['date'],
                                    'entryId'   => $entryId,
                                    'updatedAt' => date('c'),
                                ]);
                            } else {
                                $this->firebase->firestoreSet('accountingIndexByAccount', $m['docId'], [
                                    'schoolId'    => $this->schoolFs,
                                    'session'     => $this->session,
                                    'accountCode' => $m['accountCode'],
                                    'entryId'     => $entryId,
                                    'updatedAt'   => date('c'),
                                ]);
                            }
                            $stats['repaired']++;
                        }
                        log_message('error',
                            "ACC_RECON_INDEX_REPAIRED entryId={$entryId} count=" . count($missing));
                    } catch (\Throwable $e) {
                        log_message('error',
                            "ACC_RECON_INDEX_REPAIR_FAILED entryId={$entryId} error=" . $e->getMessage());
                        $stats['failed']++;
                    }
                }

                // Phase 4 (V-LOW-3) — periodic in-stage heartbeat refresh.
                // 500-entry sweep × ~200ms per entry on slow Firestore
                // could exceed the 120s takeover threshold. Refreshing
                // every 100 entries keeps the heartbeat live mid-stage
                // at a cost of ~5 extra writes per cycle in steady state.
                $i++;
                if ($i % 100 === 0) {
                    $this->_writeHeartbeat('sweep_index_progress');
                }
            }

            // Pass-complete detection: returned fewer rows than the cap →
            // no more entries past the cursor. Reset cursor for next pass.
            $passComplete = ($rawCount < self::MAX_INDEX_DRIFT_SCAN);
            $stats['pass_complete'] = $passComplete;

            $newCursor = $passComplete ? '' : $lastEntryId;
            $this->_setIndexScanCursor($newCursor);

            if ($passComplete) {
                log_message('error',
                    "ACC_RECON_IDX_PASS_COMPLETE schoolId={$this->schoolFs} "
                    . "session={$this->session} scanned_this_cycle={$stats['scanned']} "
                    . "repaired={$stats['repaired']}");
            }
        } catch (\Throwable $e) {
            log_message('error', 'ACC_RECON_INDEX_DRIFT_ERROR: ' . $e->getMessage());
        }
        return $stats;
    }

    /**
     * Persist the index-sweep cursor in the heartbeat doc so the next
     * cycle resumes where this one left off. Empty string means "pass
     * complete — start fresh next time".
     */
    private function _setIndexScanCursor(string $cursor): void
    {
        try {
            $this->firebase->firestoreSet(self::COL_HEARTBEAT,
                "{$this->schoolFs}_{$this->session}",
                ['idx_scan_cursor' => $cursor, 'updatedAt' => date('c')],
                /* merge */ true);
        } catch (\Throwable $e) {
            log_message('error', 'ACC_RECON_IDX_CURSOR_FAIL: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  (5) Balance drift sweep   — Phase 2 (R-B5)
    //
    //  Symptom we're catching:
    //    accountingClosingBalances is a denormalised projection
    //    incrementally updated by every journal post (v2 path) and by
    //    the legacy _update_balances helper (now fixed in Phase 2 but
    //    historically bugged — read the prior code's `$current = null`
    //    stub for the gory details). Soft-deleted entries are NEVER
    //    deducted, so over time the projection drifts upward from the
    //    canonical truth (sum of active ledger lines per account).
    //    Trial Balance / P&L / Balance Sheet read this projection on
    //    the unfiltered fast path → silently wrong totals.
    //
    //  What we do:
    //    1. One pass over `accounting where status='active'` (capped at
    //       MAX_BALANCE_DRIFT_SCAN for safety; if the cap is hit we
    //       degrade to detect-only and surface a structured warning).
    //    2. Aggregate per account_code into period_dr / period_cr.
    //    3. For each affected account, read the canonical projection
    //       and compare. Drift > 0.01 in either direction is logged.
    //    4. Repair: overwrite the projection with the aggregated truth.
    //       Idempotent. Re-running is safe.
    //
    //  What we do NOT do:
    //    • Recompute the legacy `accounting/BAL_*` projection — that's
    //       the responsibility of the admin Recompute Balances button
    //       (Accounting::recompute_balances). The reconciler scope is
    //       the canonical projection only; admin click handles the
    //       legacy projection plus a UI-visible discrepancy report.
    //    • Touch ledger truth or any non-projection state.
    //    • Repair when the scan was capped — partial aggregation could
    //       falsely flag drift on accounts whose entries weren't seen.
    // ─────────────────────────────────────────────────────────────────

    private function _sweepBalanceDrift(): array
    {
        $stats = [
            'accounts_scanned'  => 0,
            'drifted'           => 0,
            'repaired'          => 0,
            'failed'            => 0,
            'pass_complete'     => false,
            'window_size'       => 0,
            'windows_processed' => 0,
        ];

        try {
            // Phase 4 (V-MED-4) — multi-cycle paginated aggregation.
            // The pass-state lives in the heartbeat doc:
            //   bal_scan_state: {
            //     scan_start_ts:     ISO-8601 of when this pass began
            //     cursor:            last entryId scanned this pass
            //     agg:               { code: { period_dr, period_cr } }
            //     windows_processed: int (safety ceiling on pass length)
            //   }
            // Each cycle scans BAL_SCAN_BATCH_SIZE entries past the
            // cursor and adds their contributions to agg. When the
            // scan returns < cap, the pass has reached the end of the
            // ledger ordering — drift detection + CAS-protected repair
            // runs against the full agg, then state resets.
            //
            // Pre-Phase-4: a single ≥5000-entry scan was capped and
            // degraded to detect-only. Now any size ledger converges,
            // it just takes more cycles. Live posts during the pass
            // are CAS-protected via V-HIGH-1; if they land between our
            // ledger snapshot and our repair batch, the batch fails
            // CAS and the next pass re-aggregates against fresh state.
            $hb    = $this->firebase->firestoreGet(self::COL_HEARTBEAT,
                "{$this->schoolFs}_{$this->session}");
            $state = (is_array($hb) && is_array($hb['bal_scan_state'] ?? null))
                ? $hb['bal_scan_state'] : null;

            $startNew = ($state === null || empty($state['scan_start_ts']));
            if ($startNew) {
                $scanStart        = date('c');
                $cursor           = '';
                $agg              = [];
                $windowsProcessed = 0;
                log_message('error',
                    "ACC_RECON_BAL_PASS_START schoolId={$this->schoolFs} "
                    . "session={$this->session} scan_start={$scanStart}");
            } else {
                $scanStart        = (string) $state['scan_start_ts'];
                $cursor           = (string) ($state['cursor'] ?? '');
                $agg              = is_array($state['agg'] ?? null) ? $state['agg'] : [];
                $windowsProcessed = (int) ($state['windows_processed'] ?? 0);

                // Safety ceiling: if a pass has been spinning for too
                // many windows without completing, abandon and start
                // fresh. Prevents stuck multi-cycle state from blocking
                // convergence in pathological scenarios.
                if ($windowsProcessed >= self::BAL_PASS_MAX_WINDOWS) {
                    log_message('error',
                        "ACC_RECON_BAL_PASS_ABANDONED windows={$windowsProcessed} "
                        . "scan_start={$scanStart} — restarting fresh pass");
                    $scanStart        = date('c');
                    $cursor           = '';
                    $agg              = [];
                    $windowsProcessed = 0;
                }
            }

            $startAfter = $cursor !== '' ? $cursor : null;
            // NOTE: ordering by `entryId` requires a composite index on
            // (schoolId, session, status, entryId) in Firestore. Phase 4
            // deployment plan documents the index requirement; without
            // it Firestore returns INDEX_NOT_FOUND on first run.
            $entries = (array) $this->firebase->firestoreQuery(self::COL_LEDGER, [
                ['schoolId', '==', $this->schoolFs],
                ['session',  '==', $this->session],
                ['status',   '==', 'active'],
            ], 'entryId', 'ASC', self::BAL_SCAN_BATCH_SIZE, $startAfter);

            $rawCount             = count($entries);
            $stats['window_size'] = $rawCount;
            $lastEntryId          = $cursor;
            $i                    = 0;

            foreach ($entries as $r) {
                $d       = is_array($r['data'] ?? null) ? $r['data'] : [];
                $entryId = (string) ($d['entryId'] ?? '');
                if ($entryId === '') continue;   // skip BAL_/IDX_ docs

                // Snapshot semantics: only entries created at-or-before
                // the pass start contribute to this aggregation. Newer
                // entries get picked up on the next pass. The CAS gate
                // in detect-and-repair ensures we don't overwrite a
                // newer commit even if our agg is short by its delta.
                $createdAt = (string) ($d['created_at'] ?? '');
                if ($createdAt !== '' && $scanStart !== '' && $createdAt > $scanStart) {
                    // Don't aggregate but DO advance cursor so we
                    // don't get stuck re-fetching this row.
                    $lastEntryId = $entryId;
                    continue;
                }

                $lastEntryId = $entryId;
                foreach (($d['lines'] ?? []) as $line) {
                    $ac = (string) ($line['account_code'] ?? '');
                    if ($ac === '') continue;
                    if (!isset($agg[$ac])) {
                        $agg[$ac] = ['period_dr' => 0.0, 'period_cr' => 0.0];
                    }
                    $agg[$ac]['period_dr'] += (float) ($line['dr'] ?? 0);
                    $agg[$ac]['period_cr'] += (float) ($line['cr'] ?? 0);
                }

                // Phase 4 (V-LOW-3) — periodic in-stage heartbeat
                // refresh. A 5000-entry scan at slow Firestore rates
                // could exceed 120s; refreshing every 500 entries
                // keeps the heartbeat live mid-stage at ~10 extra
                // writes per cycle — negligible cost.
                $i++;
                if ($i % 500 === 0) {
                    $this->_writeHeartbeat('sweep_balance_scanning');
                }
            }

            // Pass-complete detection: returned fewer raw rows than
            // the per-cycle cap → no more entries to scan. Run drift
            // detection + CAS repair on the accumulated agg, then
            // reset state.
            $passComplete            = ($rawCount < self::BAL_SCAN_BATCH_SIZE);
            $windowsProcessed++;
            $stats['windows_processed'] = $windowsProcessed;

            if ($passComplete) {
                log_message('error',
                    "ACC_RECON_BAL_PASS_COMPLETE schoolId={$this->schoolFs} "
                    . "session={$this->session} windows={$windowsProcessed} "
                    . "agg_accounts=" . count($agg));

                $this->_balanceDriftDetectAndRepair($agg, $stats);
                $stats['pass_complete'] = true;

                // Clear state so next cycle starts a fresh pass.
                $this->_setBalanceScanState(null);
            } else {
                // Pass continuing — save state for next cycle.
                $this->_setBalanceScanState([
                    'scan_start_ts'     => $scanStart,
                    'cursor'            => $lastEntryId,
                    'agg'               => $agg,
                    'windows_processed' => $windowsProcessed,
                ]);
                log_message('error',
                    "ACC_RECON_BAL_WINDOW schoolId={$this->schoolFs} "
                    . "session={$this->session} windows={$windowsProcessed} "
                    . "window_size={$rawCount} cursor_advanced_to={$lastEntryId} "
                    . "pass_pending=1");
            }
        } catch (\Throwable $e) {
            log_message('error', 'ACC_RECON_BAL_DRIFT_ERROR: ' . $e->getMessage());
        }
        return $stats;
    }

    /**
     * Drift detection + CAS-protected repair against a fully-aggregated
     * per-account map. Extracted from the inline detection block so the
     * multi-cycle aggregation in _sweepBalanceDrift can call it once
     * per pass-complete cycle. Phase 3 (V-HIGH-1) CAS-safe repair logic
     * preserved verbatim — read $proj with __updateTime, write via
     * commit batch with updateTime precondition, dual-projection
     * (canonical + legacy) atomic.
     */
    private function _balanceDriftDetectAndRepair(array $agg, array &$stats): void
    {
        $i = 0;
        foreach ($agg as $code => $sum) {
            $i++;
            $stats['accounts_scanned']++;
            $expectedDr = round($sum['period_dr'] ?? 0, 2);
            $expectedCr = round($sum['period_cr'] ?? 0, 2);

            $proj = $this->firebase->firestoreGet('accountingClosingBalances',
                "{$this->schoolFs}_{$this->session}_{$code}");
            $actualDr = is_array($proj) ? round((float) ($proj['period_dr'] ?? 0), 2) : 0.0;
            $actualCr = is_array($proj) ? round((float) ($proj['period_cr'] ?? 0), 2) : 0.0;

            // Phase 4 (V-LOW-3) — refresh during long detection loop.
            if ($i % 25 === 0) {
                $this->_writeHeartbeat('sweep_balance_detecting');
            }

            if (abs($expectedDr - $actualDr) <= 0.01
             && abs($expectedCr - $actualCr) <= 0.01) {
                continue;
            }

            $stats['drifted']++;
            log_message('error',
                "ACC_RECON_BAL_DRIFT code={$code} "
                . "expected_dr={$expectedDr} actual_dr={$actualDr} "
                . "expected_cr={$expectedCr} actual_cr={$actualCr}");

            $ut = is_array($proj) ? (string) ($proj['__updateTime'] ?? '') : '';

            if (is_array($proj) && $ut === '') {
                log_message('error',
                    "ACC_RECON_BAL_REPAIR_NO_CAS code={$code} "
                    . "schoolId={$this->schoolFs} session={$this->session} — "
                    . "projection lacks __updateTime; skipping repair. "
                    . "Run `php index.php accountingreconciler backfill_balance_updatetime` "
                    . "and re-run.");
                $stats['failed']++;
                continue;
            }

            $canonicalOp = [
                'op'         => 'set',
                'collection' => 'accountingClosingBalances',
                'docId'      => "{$this->schoolFs}_{$this->session}_{$code}",
                'data'       => [
                    'schoolId'      => $this->schoolFs,
                    'session'       => $this->session,
                    'accountCode'   => (string) $code,
                    'period_dr'     => $expectedDr,
                    'period_cr'     => $expectedCr,
                    'last_computed' => date('c'),
                    'updatedAt'     => date('c'),
                ],
            ];
            if ($ut !== '') {
                $canonicalOp['precondition'] = ['updateTime' => $ut];
            } else {
                $canonicalOp['precondition'] = ['exists' => false];
            }

            $legacyOp = [
                'op'         => 'set',
                'collection' => 'accounting',
                'docId'      => "{$this->schoolFs}_BAL_{$this->session}_{$code}",
                'data'       => [
                    'schoolId'    => $this->schoolFs,
                    'session'     => $this->session,
                    'type'        => 'closing_balance',
                    'accountCode' => (string) $code,
                    'period_dr'   => $expectedDr,
                    'period_cr'   => $expectedCr,
                    'last_computed' => date('c'),
                ],
            ];

            try {
                $ok = (bool) $this->firebase->firestoreCommitBatch([$canonicalOp, $legacyOp]);
                if ($ok) {
                    $stats['repaired']++;
                    log_message('error',
                        "ACC_RECON_BAL_REPAIRED code={$code} "
                        . "new_dr={$expectedDr} new_cr={$expectedCr} dual_projection=1");
                } else {
                    $stats['failed']++;
                    log_message('error',
                        "ACC_RECON_BAL_REPAIR_CAS_CONFLICT code={$code} — "
                        . "live commit landed during repair window; will retry next cycle");
                }
            } catch (\Throwable $e) {
                $stats['failed']++;
                log_message('error',
                    "ACC_RECON_BAL_REPAIR_FAILED code={$code} error=" . $e->getMessage());
            }
        }
    }

    /**
     * Persist the multi-cycle balance-scan state in the heartbeat doc.
     * Pass null to clear (writes an empty array, which the read code
     * interprets as "start fresh next pass").
     */
    private function _setBalanceScanState($state): void
    {
        try {
            $patch = ['updatedAt' => date('c')];
            // Empty array clears the agg/cursor; the next read sees no
            // scan_start_ts and starts a fresh pass.
            $patch['bal_scan_state'] = $state === null ? [] : $state;
            $this->firebase->firestoreSet(self::COL_HEARTBEAT,
                "{$this->schoolFs}_{$this->session}", $patch, /* merge */ true);
        } catch (\Throwable $e) {
            log_message('error', 'ACC_RECON_BAL_STATE_FAIL: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  Health snapshot (used by `health` CLI + optional HTTP endpoint)
    // ─────────────────────────────────────────────────────────────────

    private function _healthSnapshot(): array
    {
        $out = [
            'schoolId'                => $this->schoolFs,
            'session'                 => $this->session,
            'collected_at'            => date('c'),
            'stuck_idempotency'       => 0,
            'failed_idempotency'      => 0,
            'unposted_refunds'        => 0,
            'suspended_refunds'       => 0,
            // Phase 2 — R-P4 + R-B5 sweep counters reflected from the
            // most recent reconciler run via heartbeat extras.
            'last_index_drift'        => null,
            'last_balance_drift'      => null,
            // Phase 4.5 (V-MED-OBS) — operational visibility metadata.
            // Pass-complete timestamps tell the operator when canonical
            // convergence last occurred. Pending-pass blocks tell them
            // whether a multi-cycle scan is in progress.
            'last_idx_pass_at'        => '',
            'last_bal_pass_at'        => '',
            'pending_balance_pass'    => null,
            'pending_index_cursor'    => '',
            'heartbeat'               => null,
        ];
        try {
            $hb = $this->firebase->firestoreGet(self::COL_HEARTBEAT,
                "{$this->schoolFs}_{$this->session}");
            if (is_array($hb)) {
                $out['heartbeat'] = [
                    'last_run_at' => (string) ($hb['lastRunAt'] ?? ''),
                    'age_seconds' => !empty($hb['lastRunAt'])
                        ? max(0, time() - strtotime((string) $hb['lastRunAt']))
                        : null,
                    'last_stage'  => (string) ($hb['lastStage'] ?? ''),
                ];
                // Surface the most recent sweep counters so an operator
                // (or an HTTP health probe) can see "did the last cycle
                // find drift?" without grepping the application log.
                if (isset($hb['indexScanned']) || isset($hb['indexMissing'])) {
                    $out['last_index_drift'] = [
                        'scanned'  => (int) ($hb['indexScanned'] ?? 0),
                        'missing'  => (int) ($hb['indexMissing'] ?? 0),
                        'repaired' => (int) ($hb['indexRepaired'] ?? 0),
                        'failed'   => (int) ($hb['indexFailed'] ?? 0),
                    ];
                }
                if (isset($hb['balanceAccountsScanned']) || isset($hb['balanceDrifted'])) {
                    $out['last_balance_drift'] = [
                        'accounts_scanned' => (int) ($hb['balanceAccountsScanned'] ?? 0),
                        'drifted'          => (int) ($hb['balanceDrifted'] ?? 0),
                        'repaired'         => (int) ($hb['balanceRepaired'] ?? 0),
                        'failed'           => (int) ($hb['balanceFailed'] ?? 0),
                        'window_size'      => (int) ($hb['balanceWindowSize'] ?? 0),
                        'pass_complete'    => !empty($hb['balancePassComplete']),
                    ];
                }
                // Phase 4.5 (V-MED-OBS) — pass-complete timestamps + pending state.
                $out['last_idx_pass_at']   = (string) ($hb['last_idx_pass_at'] ?? '');
                $out['last_bal_pass_at']   = (string) ($hb['last_bal_pass_at'] ?? '');
                $out['pending_index_cursor'] = (string) ($hb['idx_scan_cursor'] ?? '');
                if (is_array($hb['bal_scan_state'] ?? null) && !empty($hb['bal_scan_state']['scan_start_ts'])) {
                    $out['pending_balance_pass'] = [
                        'scan_start_ts'     => (string) $hb['bal_scan_state']['scan_start_ts'],
                        'cursor'            => (string) ($hb['bal_scan_state']['cursor'] ?? ''),
                        'windows_processed' => (int)    ($hb['bal_scan_state']['windows_processed'] ?? 0),
                        'agg_accounts'      => is_array($hb['bal_scan_state']['agg'] ?? null)
                            ? count($hb['bal_scan_state']['agg'])
                            : 0,
                    ];
                }
            }
            $stuck = (array) $this->firebase->firestoreQuery(self::COL_IDEMP, [
                ['schoolId', '==', $this->schoolFs],
                ['session',  '==', $this->session],
                ['status',   '==', 'processing'],
            ], 'startedAt', 'ASC', 100);
            $threshold = date('c', time() - self::STUCK_IDEMP_SEC);
            foreach ($stuck as $s) {
                $startedAt = (string) (($s['data']['startedAt'] ?? ''));
                if ($startedAt !== '' && $startedAt < $threshold) $out['stuck_idempotency']++;
            }
            $failed = (array) $this->firebase->firestoreQuery(self::COL_IDEMP, [
                ['schoolId', '==', $this->schoolFs],
                ['session',  '==', $this->session],
                ['status',   '==', 'failed'],
            ], null, 'ASC', 100);
            $out['failed_idempotency'] = count($failed);

            // B3 — surface refund-drift visibility. unposted_refunds is
            // any processed refund whose journal hasn't landed yet (the
            // sweep will retry them next cycle); suspended_refunds is
            // the subset that has exhausted MAX_REFUND_RETRIES and now
            // needs operator inspection. Both filtered client-side from
            // a single status='processed' query — same shape as the
            // sweep's own query so index coverage is identical.
            $processed = (array) $this->firebase->firestoreQuery('feeRefunds', [
                ['schoolId', '==', $this->schoolFs],
                ['session',  '==', $this->session],
                ['status',   '==', 'processed'],
            ], null, 'ASC', 200);
            foreach ($processed as $p) {
                $pd = is_array($p['data'] ?? null) ? $p['data'] : [];
                if (($pd['journalPosted'] ?? null) !== false) continue;
                $out['unposted_refunds']++;
                if ((int) ($pd['journalRetryCount'] ?? 0) >= self::MAX_REFUND_RETRIES) {
                    $out['suspended_refunds']++;
                }
            }
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Infrastructure
    // ─────────────────────────────────────────────────────────────────

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
            $this->firebase->firestoreSet(self::COL_HEARTBEAT,
                "{$this->schoolFs}_{$this->session}", $doc, /* merge */ true);
        } catch (\Throwable $e) {
            log_message('error', 'ACC_RECON_HEARTBEAT_FAIL: ' . $e->getMessage());
        }
    }

    private function _out(string $line): void
    {
        echo '[' . date('H:i:s') . "] {$line}\n";
    }
}
