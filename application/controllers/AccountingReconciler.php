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
 *                 ACCOUNTING_V2=1     (so auto-repair uses v2 path)
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
        // Make the v2 path available to auto-repair retries. Tolerates
        // pre-set env — we don't overwrite an explicit '0'.
        if (getenv('ACCOUNTING_V2') === false) putenv('ACCOUNTING_V2=1');
    }

    // ─────────────────────────────────────────────────────────────────
    //  Entry points
    // ─────────────────────────────────────────────────────────────────

    public function run(): void
    {
        $this->_out("AccountingReconciler run @ " . date('c') . " school={$this->schoolFs} session={$this->session}");

        // Phase 8C (R2) — soft concurrent-run guard. If another
        // instance checked in less than 120 s ago and is still in
        // 'run_started' stage (never flipped to run_finished), assume
        // it's either still running OR crashed mid-run. Either way,
        // skip this cycle — idempotency guards protect against double
        // repair even if we push through, but skipping keeps logs clean.
        $hb = $this->firebase->firestoreGet(self::COL_HEARTBEAT,
            "{$this->schoolFs}_{$this->session}");
        if (is_array($hb)
            && ($hb['lastStage'] ?? '') === 'run_started'
            && !empty($hb['lastRunAt'])
            && ((string) ($hb['pid'] ?? '')) !== (string) getmypid()
        ) {
            $age = max(0, time() - strtotime((string) $hb['lastRunAt']));
            if ($age < 120) {
                log_message('error',
                    "ACC_RECON_SKIP_CONCURRENT other_pid=" . ($hb['pid'] ?? '?')
                    . " host=" . ($hb['host'] ?? '?') . " ageSec={$age}");
                $this->_out("  another reconciler instance is active (age={$age}s) — skipping");
                return;
            }
            // Older than 120s without a run_finished stamp: the other
            // instance crashed. We take over.
            log_message('error',
                "ACC_RECON_TAKEOVER prior_pid=" . ($hb['pid'] ?? '?')
                . " ageSec={$age} (prior run never finished)");
        }
        $this->_writeHeartbeat('run_started');

        $idempStats  = $this->_sweepStuckIdempotency();
        $driftStats  = $this->_sweepFeeAccountingDrift();

        $this->_writeHeartbeat('run_finished', [
            'idempRecovered' => $idempStats['recovered'],
            'idempOrphaned'  => $idempStats['orphaned'],
            'driftFound'     => $driftStats['found'],
            'driftRepaired'  => $driftStats['repaired'],
            'driftFailed'    => $driftStats['failed'],
        ]);
        $this->_out(sprintf(
            "  done  idemp{recovered=%d orphaned=%d}  drift{found=%d repaired=%d failed=%d}",
            $idempStats['recovered'], $idempStats['orphaned'],
            $driftStats['found'], $driftStats['repaired'], $driftStats['failed']
        ));
    }

    /** CLI: `php index.php accountingreconciler health` */
    public function health(): void
    {
        echo json_encode($this->_healthSnapshot(), JSON_PRETTY_PRINT) . "\n";
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
     */
    private function _buildFeePayloadFromReceipt(array $receipt): ?array
    {
        $amount = (float) ($receipt['allocatedAmount'] ?? $receipt['amount'] ?? 0);
        $rcpt   = (string) ($receipt['receiptNo'] ?? '');
        if ($amount <= 0 || $rcpt === '') return null;

        return [
            'school_name'      => $this->schoolName,
            'session_year'     => $this->session,
            'date'             => (string) ($receipt['date']        ?? date('Y-m-d')),
            'amount'           => $amount,
            'payment_mode'     => strtolower((string) ($receipt['paymentMode'] ?? 'cash')),
            'bank_code'        => '',  // reconciler uses CoA default — fine for repair
            'receipt_no'       => "F{$rcpt}",
            'student_name'     => (string) ($receipt['studentName'] ?? ''),
            'student_id'       => (string) ($receipt['studentId']   ?? ''),
            'class'            => trim(((string) ($receipt['className'] ?? '')) . ' ' . ((string) ($receipt['section'] ?? ''))),
            'admin_id'         => 'SYSTEM_RECONCILER',
            // Deterministic key — matches what the worker would have used
            'journal_entry_id' => "JE_FEE_F{$rcpt}",
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    //  Health snapshot (used by `health` CLI + optional HTTP endpoint)
    // ─────────────────────────────────────────────────────────────────

    private function _healthSnapshot(): array
    {
        $out = [
            'schoolId'           => $this->schoolFs,
            'session'            => $this->session,
            'collected_at'       => date('c'),
            'stuck_idempotency'  => 0,
            'failed_idempotency' => 0,
            'heartbeat'          => null,
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
