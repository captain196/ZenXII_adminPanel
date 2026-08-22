<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_expense_reconciler — F5 nightly pending-post reconciler
 * (2026-07-07).
 *
 * Retries any transportExpenses in 'pending' state via
 * PayrollAccountingService::postExpense. Exhausted retries (attempts
 * >= 5) flip the doc to 'failed' and emit an admin notice.
 *
 * CLI-only. Invocation:
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_expense_reconciler run
 *
 * Exit codes:
 *   0 — reconciled (even if 0 pending)
 *   1 — env / init failed
 *   2 — unrecoverable error
 */
class Transport_expense_reconciler extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Transport_expense_reconciler is CLI-only.', 403);
        }
        $this->load->library('firestore_service', null, 'fs');
        $this->load->library('numbering_service', null, 'numbering');
        $this->load->library('transport_service', null, 'transport');
    }

    public function run(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n");
            exit(1);
        }

        // ── LIFECYCLE GUARD ─────────────────────────────────────────────
        // class = money. Expense reconciliation posts financial state; it
        // stops for suspended/closed tenants and resumes untouched, since
        // the pending docs it repairs are still there on the next run.
        $this->load->helper('lifecycle_guard');
        lifecycle_guard_or_skip($this->firebase, $sid, Lifecycle_resolver::JOB_MONEY, 'transport_expense_reconciler');

        try {
            $this->fs->init($sid, $sess);
            $this->numbering->init($this->fs, $sid, $sess);
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'transport_expense_reconciler');
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: init failed — " . $e->getMessage() . "\n");
            exit(1);
        }

        $started = microtime(true);
        try {
            $r = $this->transport->reconcilePendingAccountingPosts(100);
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: reconcile threw — " . $e->getMessage() . "\n");
            exit(2);
        }
        $duration = (int) round((microtime(true) - $started) * 1000);
        echo sprintf(
            "transport_expense_reconciler tenant=%s session=%s "
            . "retried=%d posted=%d failed=%d duration_ms=%d\n",
            $sid, $sess,
            (int) $r['retried'], (int) $r['posted'], (int) $r['failed'],
            $duration
        );
        exit(0);
    }
}
