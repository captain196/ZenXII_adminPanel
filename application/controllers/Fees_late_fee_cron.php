<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fees_late_fee_cron — Phase 15.6.
 *
 * Nightly automatic late-fee application.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  WHY THIS EXISTS
 * ──────────────────────────────────────────────────────────────────────
 * Before this, the ONLY way to apply a late fee was
 * `POST fees/auto_compute_fines` with a single `student_id` — a manual,
 * one-student-at-a-time operation. For a 2,000-student school that is not a
 * workflow anyone will perform, so in practice late fees were never charged
 * at all. This closes that gap.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  ARCHITECTURE — deliberately NOT a new pattern
 * ──────────────────────────────────────────────────────────────────────
 * Follows the established ZenX cron shape exactly (see
 * Transport_expiry_cron): CI_Controller, CLI-only, ONE TENANT PER
 * INVOCATION via SCHOOL_ID + SESSION_YEAR env vars. Tenant isolation is
 * therefore structural — the process only ever knows about one school, so no
 * cross-tenant read or write is possible even by mistake.
 *
 * The fine rules themselves are NOT reimplemented here. They live in
 * libraries/Fee_late_fee_service.php, which is the same code the interactive
 * endpoint uses. One engine, one set of rules, no drift.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  SAFETY PROPERTIES
 * ──────────────────────────────────────────────────────────────────────
 *  • IDEMPOTENT — the engine writes an ABSOLUTE fine and short-circuits when
 *    it already matches (`abs(existing - computed) < 0.01`). Running twice in
 *    one day performs zero writes. A flat fine never grows by re-running.
 *  • NEVER DOUBLE-CHARGES — same guard.
 *  • SKIPS paid and archived demands, demands with no due date, and demands
 *    with netAmount <= 0.
 *  • RESPECTS the school's grace period and cap.
 *  • FAILS CLOSED — if the policy cannot be read, no fine is applied.
 *  • BOUNDED READS — 1 settings read + 1 session-scoped demands query for the
 *    whole school. NOT one query per student.
 *  • DRY RUN — `--dry-run` reports exactly what would change and writes
 *    nothing, so an operator can inspect before ever charging a family.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  USAGE
 * ──────────────────────────────────────────────────────────────────────
 *   SCHOOL_ID=SCH_123456 SESSION_YEAR=2026-27 \
 *     php index.php fees_late_fee_cron run --dry-run
 *
 *   SCHOOL_ID=SCH_123456 SESSION_YEAR=2026-27 \
 *     php index.php fees_late_fee_cron run
 *
 *   SCHOOL_ID=SCH_123456 SESSION_YEAR=2026-27 \
 *     php index.php fees_late_fee_cron audit_due_dates
 *
 * Exit codes: 0 ok · 1 bad invocation · 2 execution failure.
 */
class Fees_late_fee_cron extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Fees_late_fee_cron is CLI-only.', 403);
        }
        $this->load->library('firestore_service', null, 'fs');
    }

    /** @return array{0:string,1:string} */
    private function _requireTenant(): array
    {
        $schoolId    = (string) (getenv('SCHOOL_ID')    ?: '');
        $sessionYear = (string) (getenv('SESSION_YEAR') ?: '');
        if ($schoolId === '' || $sessionYear === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID and SESSION_YEAR env vars are required.\n");
            exit(1);
        }
        return [$schoolId, $sessionYear];
    }

    private function _boot(string $schoolId, string $sessionYear)
    {
        $this->fs->init($schoolId, $sessionYear);
        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $schoolId, $sessionYear);
        $this->load->library('Fee_late_fee_service', null, 'lateFeeSvc');
        $this->lateFeeSvc->init($this->firebase, $this->fs, $this->fsTxn, $schoolId, $sessionYear);
    }

    public function run(): void
    {
        [$schoolId, $sessionYear] = $this->_requireTenant();

        // ── LIFECYCLE GUARD (P0) ────────────────────────────────────────
        // Before this guard existed, a tenant suspended for non-payment kept
        // accruing PARENT-FACING late fees every night: the scheduler handed
        // this job a school id and the job never asked whether that school
        // was entitled to be operated on. CLI jobs run under the service
        // account, so neither firestore.rules::tenantActive() nor the
        // MY_Controller login gate ever fires for them.
        //
        // Class = money → skipped on commercial suspension/expiry, on an
        // unreadable tenant state (fail closed — never charge a family on a
        // tenant we cannot verify), and on any institution state other than
        // ACTIVE/CLOSING. A CLOSING school still settles its fees, which is
        // why CLOSING keeps running.
        $this->load->helper('lifecycle_guard');
        lifecycle_guard_or_skip($this->firebase, $schoolId, Lifecycle_resolver::JOB_MONEY, 'fees_late_fee_cron');

        $dryRun = false;
        foreach ((array) ($_SERVER['argv'] ?? []) as $arg) {
            if ($arg === '--dry-run' || $arg === '--dryrun') $dryRun = true;
        }

        try {
            $this->_boot($schoolId, $sessionYear);
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: init failed — " . $e->getMessage() . "\n");
            exit(1);
        }

        $started = microtime(true);
        try {
            $policy = $this->lateFeeSvc->policy();
            if (!$policy['enabled']) {
                // Not an error: most schools will not have late fees switched
                // on. Say so plainly and exit clean so the scheduler does not
                // alert every night.
                fwrite(STDOUT, "FEE_LATEFEE_SKIP school={$schoolId} session={$sessionYear} "
                    . "reason=\"{$policy['reason']}\"\n");
                exit(0);
            }

            $cutoff = $this->lateFeeSvc->cutoffDate();
            $result = $this->lateFeeSvc->computeForSchool($dryRun);
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: late-fee run threw — " . $e->getMessage() . "\n");
            exit(2);
        }

        $ms = (int) round((microtime(true) - $started) * 1000);
        $line = sprintf(
            'FEE_LATEFEE_DONE school=%s session=%s dry_run=%s type=%s value=%s grace=%d cap=%s '
            . 'cutoff=%s scanned=%d updated=%d skipped=%d total_fine=%.2f ms=%d',
            $schoolId, $sessionYear, $dryRun ? 'yes' : 'no',
            $policy['type'], $policy['value'], $policy['graceDays'],
            ($policy['cap'] > 0 ? (string) $policy['cap'] : 'none'),
            $cutoff,
            (int) ($result['demands_scanned'] ?? 0),
            (int) ($result['demands_updated'] ?? 0),
            (int) ($result['skipped'] ?? 0),
            (float) ($result['total_fine'] ?? 0),
            $ms
        );
        fwrite(STDOUT, $line . "\n");
        log_message('info', $line);

        if ($dryRun && !empty($result['details'])) {
            fwrite(STDOUT, "-- would update (first 50) --\n");
            foreach (array_slice($result['details'], 0, 50) as $d) {
                fwrite(STDOUT, sprintf(
                    "  demand=%s fine=%.2f new_balance=%.2f\n",
                    $d['demandId'], $d['fine'], $d['balance']
                ));
            }
        }
        exit(0);
    }

    /**
     * READ-ONLY. Reports how many demands predate due-date stamping and are
     * therefore permanently exempt from late fees. Writes nothing.
     * Backfilling them mutates production financial records and needs
     * explicit authorization — this only measures the scope.
     */
    public function audit_due_dates(): void
    {
        [$schoolId, $sessionYear] = $this->_requireTenant();
        try {
            $this->_boot($schoolId, $sessionYear);
            $r = $this->lateFeeSvc->auditMissingDueDates();
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: audit failed — " . $e->getMessage() . "\n");
            exit(2);
        }

        fwrite(STDOUT, sprintf(
            "FEE_DUEDATE_AUDIT school=%s session=%s scanned=%d missing=%d reconstructable=%d ambiguous=%d\n",
            $schoolId, $sessionYear,
            (int) $r['scanned'], (int) $r['missing'],
            (int) ($r['reconstructable'] ?? 0), (int) ($r['ambiguous'] ?? 0)
        ));
        foreach ((array) ($r['samples'] ?? []) as $s) {
            fwrite(STDOUT, sprintf(
                "  demand=%s student=%s periodKey=%s status=%s reconstructable=%s\n",
                $s['demandId'], $s['studentId'], $s['periodKey'] ?: '(none)',
                $s['status'], $s['reconstructable'] ? 'yes' : 'NO'
            ));
        }
        fwrite(STDOUT, "READ-ONLY: no demand was modified.\n");
        exit(0);
    }

    /**
     * DRY-RUN backfill plan for dateless historical demands. WRITES NOTHING.
     *
     * There is intentionally no `--commit` flag: altering the due date of an
     * existing financial record decides whether a family gets penalised, so it
     * needs explicit authorization and a separate reviewed migration. This
     * command exists so that decision can be made from exact evidence.
     */
    public function plan_due_date_backfill(): void
    {
        [$schoolId, $sessionYear] = $this->_requireTenant();
        try {
            $this->_boot($schoolId, $sessionYear);
            $r = $this->lateFeeSvc->planDueDateBackfill();
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: backfill plan failed — " . $e->getMessage() . "\n");
            exit(2);
        }

        fwrite(STDOUT, sprintf(
            "FEE_DUEDATE_BACKFILL_PLAN school=%s session=%s scanned=%d reconstructable=%d ambiguous=%d due_day=%d\n",
            $schoolId, $sessionYear, (int) ($r['scanned'] ?? 0),
            (int) ($r['reconstructable'] ?? 0), (int) ($r['ambiguous'] ?? 0),
            (int) ($r['dueDayApplied'] ?? 0)
        ));
        foreach (array_slice((array) ($r['plan'] ?? []), 0, 100) as $p) {
            fwrite(STDOUT, sprintf(
                "  PROPOSE demand=%s student=%s period=%s status=%s  %s -> %s\n",
                $p['demandId'], $p['studentId'], $p['periodKey'], $p['status'],
                $p['currentDueDate'], $p['proposedDueDate']
            ));
        }
        foreach (array_slice((array) ($r['ambiguousRows'] ?? []), 0, 50) as $p) {
            fwrite(STDOUT, sprintf(
                "  AMBIGUOUS demand=%s student=%s period=%s  reason=%s\n",
                $p['demandId'], $p['studentId'], $p['periodKey'] ?: '(none)', $p['reason']
            ));
        }
        fwrite(STDOUT, "DRY-RUN ONLY: nothing was written. Backfill requires explicit authorization.\n");
        exit(0);
    }

    /**
     * FEES-D7-DUE (Option C) — give stranded, genuinely outstanding demands a
     * FORWARD due date so today's reminder process can operate on them.
     *
     * This is NOT plan_due_date_backfill(). That one reconstructs the date a
     * demand WOULD have had, which makes a months-overdue demand instantly
     * finable — penalising a family for a window they never had. This gives a
     * fair NEW date: the next future occurrence of the school's own configured
     * due day. No new money-policy setting was invented.
     *
     *   php index.php fees_late_fee_cron redate_outstanding_due_dates
     *   php index.php fees_late_fee_cron redate_outstanding_due_dates --commit
     *
     * DRY-RUN unless `--commit` is passed explicitly. Writes ONLY `dueDate`.
     */
    public function redate_outstanding_due_dates(): void
    {
        [$schoolId, $sessionYear] = $this->_requireTenant();

        // --commit must be typed. Never inferred, never defaulted on.
        $argv   = (array) ($_SERVER['argv'] ?? []);
        $commit = in_array('--commit', $argv, true);

        try {
            $this->_boot($schoolId, $sessionYear);
            $r = $this->lateFeeSvc->forwardDueDateRepair($commit);
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: forward re-date failed — " . $e->getMessage() . "\n");
            exit(2);
        }

        if (!empty($r['error'])) {
            fwrite(STDERR, "ERROR: {$r['error']}\n");
            exit(2);
        }

        fwrite(STDOUT, sprintf(
            "FEE_DUEDATE_FORWARD_REDATE mode=%s school=%s session=%s scanned=%d eligible=%d ineligible=%d review=%d due_day=%d new_due=%s\n",
            $r['mode'], $schoolId, $sessionYear,
            (int) $r['scanned'], (int) $r['eligibleCount'],
            (int) $r['ineligibleCount'], (int) $r['reviewCount'],
            (int) $r['dueDayApplied'], (string) $r['proposedDueDate']
        ));

        foreach (array_slice((array) $r['eligible'], 0, 100) as $p) {
            fwrite(STDOUT, sprintf(
                "  %s demand=%s student=%s head=%s net=%.2f fine=%.2f paid=%.2f bal=%.2f status=%s  %s -> %s\n",
                $commit ? (!empty($p['written']) ? 'WROTE ' : 'FAILED') : 'PROPOSE',
                $p['demandId'], $p['studentId'], $p['feeHead'],
                $p['netAmount'], $p['fineAmount'], $p['paidAmount'], $p['balance'],
                $p['status'], $p['currentDueDate'], $p['proposedDueDate']
            ));
        }
        foreach (array_slice((array) $r['requiresReview'], 0, 50) as $p) {
            fwrite(STDOUT, sprintf(
                "  REQUIRES_REVIEW demand=%s student=%s status=%s bal=%.2f  %s\n",
                $p['demandId'] ?: '(none)', $p['studentId'] ?: '(none)',
                $p['status'], $p['balance'], $p['reason']
            ));
        }

        if ($commit) {
            fwrite(STDOUT, sprintf("COMMITTED: written=%d failed=%d. %s\n",
                (int) $r['written'], (int) $r['writeFailed'], $r['note']));
            exit(((int) $r['writeFailed']) > 0 ? 3 : 0);
        }
        fwrite(STDOUT, "DRY-RUN ONLY: nothing was written. Re-run with --commit to apply.\n");
        exit(0);
    }

    /**
     * Automatic fee reminders.
     *
     * Deliberately folded into THIS cron rather than added as a second
     * scheduler — the same precedent Transport_expiry_cron set when it
     * absorbed driver-assignment expiry and compliance re-evaluation: one
     * nightly fee cron, not three.
     *
     * Enqueues FEE_DUE through the shared Push_enqueue_service; the Cloud
     * Function MARK_REGISTRY remains the only dispatcher.
     */
    public function reminders(): void
    {
        [$schoolId, $sessionYear] = $this->_requireTenant();

        $dryRun = false;
        foreach ((array) ($_SERVER['argv'] ?? []) as $arg) {
            if ($arg === '--dry-run' || $arg === '--dryrun') $dryRun = true;
        }

        try {
            $this->fs->init($schoolId, $sessionYear);
            $this->load->library('Push_enqueue_service', null, 'pushEnqueue');
            $this->load->library('Fee_reminder_service', null, 'reminderSvc');
            $this->reminderSvc->init($this->firebase, $this->fs, $schoolId, $sessionYear, $this->pushEnqueue);
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: init failed — " . $e->getMessage() . "\n");
            exit(1);
        }

        $started = microtime(true);
        try {
            $settings = $this->reminderSvc->settings();
            if (!$settings['enabled']) {
                fwrite(STDOUT, "FEE_REMINDER_SKIP school={$schoolId} session={$sessionYear} "
                    . "reason=\"{$settings['reason']}\"\n");
                exit(0);
            }
            $result = $this->reminderSvc->run($dryRun);
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: reminder run threw — " . $e->getMessage() . "\n");
            exit(2);
        }

        $ms = (int) round((microtime(true) - $started) * 1000);
        $line = sprintf(
            'FEE_REMINDER_DONE school=%s session=%s dry_run=%s scanned=%d candidates=%d '
            . 'enqueued=%d skipped_paid=%d failed=%d ms=%d',
            $schoolId, $sessionYear, $dryRun ? 'yes' : 'no',
            (int) ($result['demands_scanned'] ?? 0), (int) ($result['candidates'] ?? 0),
            (int) ($result['enqueued'] ?? 0), (int) ($result['skipped_paid'] ?? 0),
            (int) ($result['failed'] ?? 0), $ms
        );
        fwrite(STDOUT, $line . "\n");
        log_message('info', $line);

        if ($dryRun && !empty($result['details'])) {
            fwrite(STDOUT, "-- would notify (first 50) --\n");
            foreach (array_slice($result['details'], 0, 50) as $d) {
                fwrite(STDOUT, sprintf(
                    "  student=%s demand=%s stage=%s amount=%.2f due=%s key=%s\n",
                    $d['studentId'], $d['demandId'], $d['stage'],
                    $d['amount'], $d['dueDate'], $d['dedupeKey']
                ));
            }
        }
        exit(0);
    }
}
