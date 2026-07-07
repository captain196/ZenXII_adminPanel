<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * PayrollReconciler — LC-P3c transactional-outbox recovery for payroll
 * payment journals. CLI/cron ONLY, one invocation per tenant (scoped by the
 * SCHOOL_ID + SESSION_YEAR environment variables, mirroring
 * AccountingReconciler). Additive; touches no legacy path.
 *
 * ── What it heals ────────────────────────────────────────────────────────
 * Hr::_engine_apply_payment commits the payment intent to the run
 * (journal_status='pending', Phase 1) BEFORE posting the accounting journal
 * (Phase 2), then flips the record to 'posted' once the journal confirms. If
 * a transient failure (network / Firestore timeout / service blip) strikes
 * between Phase 1 and Phase 2 — or on the flip itself — the record is left at
 * 'pending'. This sweep finds those records and re-posts the DETERMINISTIC
 * journal JE_HR_PAYROLL_<runId>_<seq> through the canonical engine, then
 * flips the record to 'posted'.
 *
 * ── Why it is safe ───────────────────────────────────────────────────────
 *   • Deterministic key + Accounting_idempotency ⇒ re-posting any number of
 *     times converges to EXACTLY ONE ledger journal (posts once if missing,
 *     dedups if already present). No duplicates, ever.
 *   • Only records older than STALE_SEC are touched, so a payment still
 *     in-flight between Phase 1 and Phase 2 is never disturbed.
 *   • Tenant-scoped by env ⇒ safe across many tenants and many runs; each
 *     invocation only reads/writes its own school+session.
 *   • The 'posted' flip is a targeted nested-field update
 *     (updateMask payments.<seq>.journal_status) — it never clobbers sibling
 *     payments, and concurrent payment CAS writes re-read past it.
 */
class PayrollReconciler extends CI_Controller
{
    /** Don't touch a pending record younger than this — it may be in-flight. */
    private const STALE_SEC = 120;

    private string $schoolFs      = '';
    private string $sessionYear   = '';
    /** Captures the engine's failure message so diagnostics show WHY. */
    private string $lastEngineError = '';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('PayrollReconciler is CLI-only.', 403);
        }
        $this->load->library('firebase');
        $this->load->library('firestore_service');

        $this->schoolFs    = (string) (getenv('SCHOOL_ID')    ?: '');
        $this->sessionYear = (string) (getenv('SESSION_YEAR') ?: '');
        if ($this->schoolFs === '' || $this->sessionYear === '') {
            echo "ERROR: Set SCHOOL_ID and SESSION_YEAR environment variables.\n";
            exit(1);
        }
    }

    /**
     * Engine failure routing. Operations_accounting calls $CI->json_error()
     * to halt a web request; in the sweep we THROW instead so a single
     * unrecoverable record (e.g. its period was closed after the payment) is
     * caught by PayrollAccountingService::_commit (which returns null) and the
     * sweep skips it and continues rather than aborting the whole run.
     */
    public function json_error(string $msg): void
    {
        $this->lastEngineError = $msg;   // R2 — surface the reason to diagnostics
        throw new \RuntimeException($msg);
    }

    /**
     * R2 — map an engine failure reason to a concrete operator action.
     * NOTE: the reconciler NEVER auto-repairs accounts/periods/config; it only
     * records what a human must do. Financial configuration stays a human
     * decision (commercial-ERP correct).
     */
    private function _operatorAction(string $reason): string
    {
        $r = strtolower($reason);
        if (strpos($r, 'missing or inactive') !== false || strpos($r, 'account') !== false) {
            return 'Create or re-activate the required Chart-of-Accounts account(s) in Accounting. '
                 . 'The next reconciliation will then post the journal automatically.';
        }
        if (strpos($r, 'period is closed') !== false || strpos($r, 'period is locked') !== false) {
            return 'Reopen the affected accounting period in Accounting (or post to an open period). '
                 . 'The next reconciliation will then post the journal automatically.';
        }
        if (strpos($r, 'in progress') !== false || strpos($r, 'currently being posted') !== false) {
            return 'Transient — another poster holds the idempotency slot. No action; it will clear on the next sweep.';
        }
        return 'Investigate the failure reason and resolve the underlying accounting condition; '
             . 'reconciliation retries automatically each cycle.';
    }

    public function run(): void
    {
        $found = 0; $reposted = 0; $flipped = 0; $skipped = 0; $failed = 0;

        require_once APPPATH . 'services/PayrollAccountingService.php';
        $payAcct = new PayrollAccountingService();
        $payAcct->init($this->firebase, $this->schoolFs, $this->sessionYear, 'SYSTEM_PAYROLL_RECON', $this);
        if (!$payAcct->isEnabled()) {
            echo "PayrollAccountingService not ready for {$this->schoolFs} — check firebase/school/session init.\n";
            return;
        }

        // Tenant-scoped: every payroll run for this school + session.
        $runs = (array) $this->firebase->firestoreQuery('salarySlips', [
            ['schoolId', '==', $this->schoolFs],
            ['session',  '==', $this->sessionYear],
            ['type',     '==', 'run'],
        ]);

        $nowTs = time();
        foreach ($runs as $row) {
            $run      = is_array($row['data'] ?? null) ? $row['data'] : $row;
            $runDocId = (string) ($row['id'] ?? '');
            if ($runDocId === '') continue;

            $parts = explode('_RUN_', $runDocId, 2);
            $runId = isset($parts[1]) ? $parts[1] : (string) ($run['runId'] ?? '');
            if ($runId === '') continue;

            $month    = (string) ($run['month'] ?? '');
            $year     = (string) ($run['year']  ?? '');
            $payments = is_array($run['payments'] ?? null) ? $run['payments'] : [];

            foreach ($payments as $seqKey => $pay) {
                if (!is_array($pay)) continue;
                if (($pay['journal_status'] ?? 'posted') !== 'pending') continue;
                $found++;

                // Age gate — leave freshly-created records to the request path.
                $paidTs = strtotime((string) ($pay['paid_at'] ?? '')) ?: 0;
                if ($paidTs > 0 && ($nowTs - $paidTs) < self::STALE_SEC) { $skipped++; continue; }

                $seq = (int) ($pay['seq'] ?? $seqKey);
                $amt = round((float) ($pay['amount'] ?? 0), 2);
                if ($seq <= 0 || $amt <= 0) { $skipped++; continue; }

                // Idempotent re-post — deterministic JE_HR_PAYROLL_<runId>_<seq>.
                $this->lastEngineError = '';
                $journalId = PayrollAccountingService::journalIdFor($runId, $seq);
                $posted = '';
                try {
                    $posted = (string) $payAcct->postRunPayment([
                        'run_id'            => $runId,
                        'month'             => $month,
                        'year'              => $year,
                        'amount'            => $amt,
                        'payment_mode'      => (string) ($pay['payment_mode'] ?? ''),
                        'payment_reference' => (string) ($pay['payment_reference'] ?? ''),
                        'payment_seq'       => $seq,
                        'is_full_payment'   => (bool) ($pay['is_full'] ?? false),
                    ]);
                } catch (\Throwable $e) {
                    log_message('error', "PAYROLL_RECON post threw run={$runId} seq={$seq}: " . $e->getMessage());
                }
                if ($posted === '') {
                    // R2 — structured diagnostics on the payment record so
                    // administrators see WHY without reading logs. NO auto-repair
                    // of accounts/periods/config — that stays a human decision.
                    $failed++;
                    $reason     = $this->lastEngineError !== '' ? $this->lastEngineError : 'engine returned no entry';
                    $prevDiag   = is_array($pay['posting_diag'] ?? null) ? $pay['posting_diag'] : [];
                    $retryCount = (int) ($prevDiag['retry_count'] ?? 0) + 1;
                    $firstAt    = (string) ($prevDiag['first_failure_at'] ?? date('c'));
                    $this->firebase->firestoreUpdate('salarySlips', $runDocId, [
                        "payments.{$seq}.posting_diag" => [
                            'journal_id'        => $journalId,
                            'run_id'            => $runId,
                            'failure_reason'    => $reason,
                            'required_action'   => $this->_operatorAction($reason),
                            'retry_count'       => $retryCount,
                            'first_failure_at'  => $firstAt,
                            'last_retry_at'     => date('c'),
                            'last_retry_result' => 'failed',
                        ],
                    ]);
                    log_message('error',
                        "PAYROLL_RECON failed schoolId={$this->schoolFs} run={$runId} seq={$seq} "
                        . "retry={$retryCount} reason={$reason}");
                    continue;
                }
                $reposted++;

                // Flip intent → posted + record recovery outcome. Targeted
                // nested updates; no clobber of sibling payments.
                $prevDiag = is_array($pay['posting_diag'] ?? null) ? $pay['posting_diag'] : [];
                $ok = (bool) $this->firebase->firestoreUpdate('salarySlips', $runDocId, [
                    "payments.{$seq}.journal_status"                 => 'posted',
                    "payments.{$seq}.posting_diag.last_retry_at"     => date('c'),
                    "payments.{$seq}.posting_diag.last_retry_result" => 'recovered',
                    "payments.{$seq}.posting_diag.retry_count"       => (int) ($prevDiag['retry_count'] ?? 0) + 1,
                    'updatedAt'                                       => date('c'),
                ]);
                if ($ok) { $flipped++; } else { $failed++; }

                log_message('error',
                    "PAYROLL_RECON healed schoolId={$this->schoolFs} run={$runId} seq={$seq} entry={$posted}");
            }

            // ── C2: accrual outbox recovery (one accrual per run) ────────────
            // Same Phase-1/Phase-2 contract as payments: generate_payroll commits
            // the accrual intent to the run (accrual_journal_status='pending')
            // BEFORE posting, and flips to 'posted' once the engine confirms. A
            // failure in between leaves it 'pending'; this re-posts the
            // DETERMINISTIC JE_HR_PAYROLL_<runId> idempotently, then flips.
            if ((string) ($run['accrual_journal_status'] ?? '') === 'pending') {
                $found++;

                // Age gate — leave a freshly-created run to the request path.
                $runTs = strtotime((string) ($run['created_at'] ?? $run['updatedAt'] ?? '')) ?: 0;
                if ($runTs > 0 && ($nowTs - $runTs) < self::STALE_SEC) {
                    $skipped++;
                    continue;
                }

                // The exact figures captured at generation — needed to rebuild
                // the identical journal. Absent ⇒ cannot safely reconstruct; skip.
                $payload = is_array($run['accrual_payload'] ?? null) ? $run['accrual_payload'] : [];
                if (empty($payload)) { $skipped++; continue; }

                $this->lastEngineError = '';
                $accrualJournalId = PayrollAccountingService::accrualJournalIdFor($runId);
                $posted = '';
                try {
                    $posted = (string) $payAcct->postRunAccrual(array_merge([
                        'run_id'       => $runId,
                        'month'        => $month,
                        'year'         => $year,
                        'journal_date' => (string) ($run['accrual_journal_date'] ?? date('Y-m-d')),
                    ], $payload));
                } catch (\Throwable $e) {
                    log_message('error', "PAYROLL_RECON accrual post threw run={$runId}: " . $e->getMessage());
                }

                if ($posted === '') {
                    // R2-style diagnostics on the run doc; NO auto-repair of
                    // accounts/periods/config (human decision).
                    $failed++;
                    $reason     = $this->lastEngineError !== '' ? $this->lastEngineError : 'engine returned no entry';
                    $prevDiag   = is_array($run['accrual_posting_diag'] ?? null) ? $run['accrual_posting_diag'] : [];
                    $retryCount = (int) ($prevDiag['retry_count'] ?? 0) + 1;
                    $firstAt    = (string) ($prevDiag['first_failure_at'] ?? date('c'));
                    $this->firebase->firestoreUpdate('salarySlips', $runDocId, [
                        'accrual_posting_diag' => [
                            'journal_id'        => $accrualJournalId,
                            'run_id'            => $runId,
                            'failure_reason'    => $reason,
                            'required_action'   => $this->_operatorAction($reason),
                            'retry_count'       => $retryCount,
                            'first_failure_at'  => $firstAt,
                            'last_retry_at'     => date('c'),
                            'last_retry_result' => 'failed',
                        ],
                    ]);
                    log_message('error',
                        "PAYROLL_RECON accrual failed schoolId={$this->schoolFs} run={$runId} "
                        . "retry={$retryCount} reason={$reason}");
                    continue;
                }
                $reposted++;

                // Flip intent → posted + record the recovered journal id.
                $prevDiag = is_array($run['accrual_posting_diag'] ?? null) ? $run['accrual_posting_diag'] : [];
                $ok = (bool) $this->firebase->firestoreUpdate('salarySlips', $runDocId, [
                    'accrual_journal_status'                  => 'posted',
                    'expense_journal_id'                      => $posted,
                    'accrual_posting_diag.last_retry_at'      => date('c'),
                    'accrual_posting_diag.last_retry_result'  => 'recovered',
                    'accrual_posting_diag.retry_count'        => (int) ($prevDiag['retry_count'] ?? 0) + 1,
                    'updatedAt'                               => date('c'),
                ]);
                if ($ok) { $flipped++; } else { $failed++; }

                log_message('error',
                    "PAYROLL_RECON accrual healed schoolId={$this->schoolFs} run={$runId} entry={$posted}");
            }
        }

        // R2 — per-tenant reconciler status doc; the Payroll Health view reads
        // this instead of scraping logs. Additive; one doc per school+session.
        // merge=true preserves a prior last_success_at across partial runs.
        try {
            $status = [
                'schoolId'        => $this->schoolFs,
                'session'         => $this->sessionYear,
                'last_run_at'     => date('c'),
                'last_run_result' => ($failed === 0 ? 'ok' : 'partial'),
                'found'           => $found,
                'reposted'        => $reposted,
                'flipped'         => $flipped,
                'skipped'         => $skipped,
                'failed'          => $failed,
            ];
            if ($failed === 0) $status['last_success_at'] = date('c');
            $this->firebase->firestoreSet('payrollReconcilerStatus',
                "{$this->schoolFs}_{$this->sessionYear}", $status, true);
        } catch (\Throwable $e) {
            log_message('error', "PAYROLL_RECON status write failed: " . $e->getMessage());
        }

        echo "PayrollReconciler done schoolId={$this->schoolFs} session={$this->sessionYear} "
           . "found={$found} reposted={$reposted} flipped={$flipped} skipped={$skipped} failed={$failed}\n";
    }
}
