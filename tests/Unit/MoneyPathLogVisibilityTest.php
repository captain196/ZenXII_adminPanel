<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', realpath(__DIR__ . '/../../system') . DIRECTORY_SEPARATOR);

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  LOG-1 — THE MONEY PATHS ANNOUNCED LOST ATOMICITY INTO NOTHING
 * ══════════════════════════════════════════════════════════════════════════
 *
 * CodeIgniter 3 defines exactly four log levels:
 *
 *     protected $_levels = array('ERROR' => 1, 'DEBUG' => 2, 'INFO' => 3, 'ALL' => 4);
 *
 * `Log::write_log()` then does `if (!isset($this->_levels[$level]) …) return FALSE;`
 * — so **`log_message('warning', …)` writes nothing at all.** No file, no
 * error, no hint that the call did nothing.
 *
 * Nine such calls sat in the fee money paths, and they were not incidental
 * lines. Every one marks the same event: **an atomic commit failed and the code
 * is falling through to a non-atomic path.**
 *
 *   Fee_lifecycle          assignInitialFees commitDemandBatch failed → per-doc writes
 *   Fee_lifecycle          reassignFeesOnPromotion batch failed → per-doc writes
 *   Fee_lifecycle          cancelSessionDemands writeDemand failed
 *   Fee_lifecycle          no fee structure for a class/section
 *   Fee_firestore_txn      sharded receipt counter returned 0 → legacy counter
 *   FeeCollectionService   [FCS CLAIM BATCH FAILED]
 *   FeeCollectionService   [FCS ASYNC FALLBACK]   sync commit failed
 *   FeeCollectionService   [FCS BATCH FALLBACK]   commit failed
 *   FeeCollectionService   [FCS CLEANUP FALLBACK] committing sequentially
 *
 * The last four are the **payment** path. A payment whose atomic claim batch
 * failed and fell through to sequential writes announced that fact to nobody.
 *
 * ── WHY 'error' AND NOT 'info' ────────────────────────────────────────────
 * `log_threshold` defaults to **1 — errors only** in this deployment
 * (`config.php`, "production default"). At that threshold DEBUG and INFO are
 * also discarded, so ERROR is the only level guaranteed to reach the log on a
 * production host. These events are genuine anomalies requiring attention,
 * which is what ERROR means.
 *
 * ── SCOPE ─────────────────────────────────────────────────────────────────
 * Deliberately limited to the fee money paths. The same drift exists estate-wide
 * (attendance, HR, homework); those are other people's live workstreams and are
 * outside this lifecycle. This test guards the money paths only, and says so, so
 * the limit is a recorded decision rather than an oversight.
 */
class MoneyPathLogVisibilityTest extends TestCase
{
    /** The files this test governs. */
    private const MONEY_PATHS = [
        'libraries/Fee_lifecycle.php',
        'libraries/Fee_firestore_txn.php',
        'services/FeeCollectionService.php',
        'libraries/Fee_late_fee_service.php',
        'libraries/Fee_refund_service.php',
        'libraries/Fee_generation_service.php',
        'libraries/Student_charge_service.php',
        'libraries/Fee_defaulter_check.php',
        // LOG-2 — the two fee CONTROLLERS carried 17 more, and they were the
        // sharpest of the lot. See test_the_payment_safety_downgrades_are_audible().
        'controllers/Fee_management.php',
        'controllers/Fees.php',
    ];

    // ══ CONTROL — THE PREMISE IS TRUE ═══════════════════════════════════════

    /**
     * Everything here rests on CI3 not having a WARNING level. If a future
     * upgrade adds one, these calls would start working and this file should be
     * re-derived rather than kept.
     */
    public function test_control_codeigniter_has_no_warning_level(): void
    {
        $log = (string) file_get_contents(BASEPATH . 'core/Log.php');

        $this->assertStringContainsString(
            "protected \$_levels = array('ERROR' => 1, 'DEBUG' => 2, 'INFO' => 3, 'ALL' => 4);",
            $log,
            'the CI3 log levels changed. If WARNING now exists, log_message(\'warning\') is no '
            . 'longer discarded and this whole file needs re-deriving.');

        // And the write is genuinely skipped for an unknown level.
        $this->assertStringContainsString('if (( ! isset($this->_levels[$level])', $log,
            'the unknown-level guard moved; confirm an unrecognised level is still dropped');
    }

    /**
     * ERROR is the only level that survives the production threshold, so
     * promoting to 'info' would have fixed nothing.
     */
    public function test_control_the_production_threshold_admits_only_errors(): void
    {
        $cfg = (string) file_get_contents(APPPATH . 'config/config.php');

        $this->assertStringContainsString(
            "\$config['log_threshold']        = (int) (getenv('LOG_THRESHOLD') ?: 1);",
            $cfg,
            'the log threshold default changed — re-derive whether INFO would now be visible');
    }

    // ══ LOG-1 ══════════════════════════════════════════════════════════════

    /**
     * WOULD HAVE FAILED BEFORE: nine calls across three files.
     */
    public function test_no_money_path_logs_at_a_level_that_is_discarded(): void
    {
        $offenders = [];

        foreach (self::MONEY_PATHS as $rel) {
            $path = APPPATH . $rel;
            if (!is_file($path)) continue;
            $src = (string) file_get_contents($path);

            // Any level outside the four CI3 knows is silently dropped.
            preg_match_all("/log_message\(\s*'([a-z]+)'/i", $src, $m);
            foreach ($m[1] as $level) {
                if (!in_array(strtolower($level), ['error', 'debug', 'info'], true)) {
                    $offenders[] = "{$rel}: '{$level}'";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)),
            "these money-path log calls use a level CodeIgniter does not define, so they write "
            . "NOTHING — no file, no error, no hint the call did nothing: \n  "
            . implode("\n  ", array_unique($offenders))
            . "\nEvery one of the original nine marked an atomic commit failing over to a "
            . 'non-atomic path, which is precisely the event that must not be silent.');
    }

    /**
     * The fallbacks still have to exist. Promoting the log level must not have
     * been done by deleting the branch that logs.
     */
    public function test_the_atomicity_fallbacks_still_report_themselves(): void
    {
        $lifecycle = (string) file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php');
        $fcs       = (string) file_get_contents(APPPATH . 'services/FeeCollectionService.php');

        $this->assertStringContainsString('commitDemandBatch failed', $lifecycle,
            'the batch-failure fallback in assignInitialFees no longer reports itself');
        $this->assertStringContainsString('[FCS CLAIM BATCH FAILED]', $fcs,
            'the payment claim-batch failure no longer reports itself');
        $this->assertStringContainsString('[FCS BATCH FALLBACK]', $fcs);
    }

    /**
     * Specifically the payment path, because it is the one where a silent
     * fallback means money moved through a weaker guarantee than the code
     * believes it has.
     */
    public function test_every_payment_path_fallback_logs_at_error(): void
    {
        $fcs = (string) file_get_contents(APPPATH . 'services/FeeCollectionService.php');

        foreach (['[FCS CLAIM BATCH FAILED]', '[FCS ASYNC FALLBACK]',
                  '[FCS BATCH FALLBACK]', '[FCS CLEANUP FALLBACK]'] as $marker) {
            $at = strpos($fcs, $marker);
            $this->assertNotFalse($at, "the {$marker} fallback disappeared");

            $before = substr($fcs, max(0, $at - 120), min(120, $at));
            $this->assertStringContainsString("log_message('error'", $before,
                "{$marker} is logged at a level that never reaches the log. A payment that "
                . 'fell back from an atomic commit to sequential writes would leave no trace.');
        }
    }

    // ══ LOG-2 · THE CONTROLLERS WERE WORSE ══════════════════════════════════

    /**
     * Three of the seventeen record a payment being accepted with a SAFETY
     * CHECK SKIPPED. Those are the lines an incident review would look for
     * first, and every one of them wrote nothing:
     *
     *   "gateway_amount UNAVAILABLE … proceeding on signature-only"
     *      — the amount comparison did not run. The payment was accepted on
     *        the signature alone, so a gateway charging the wrong amount would
     *        not have been caught, and there was no record that the check was
     *        skipped.
     *
     *   "webhook_secret empty — HMAC check skipped"
     *      — the webhook was processed without verifying it came from the
     *        gateway at all.
     *
     *   "Rate_limiter fail-open"
     *      — the rate limiter failed and let the request through.
     *
     * A skipped check that is also an unrecorded check is indistinguishable
     * from a check that passed.
     */
    public function test_the_payment_safety_downgrades_are_audible(): void
    {
        $fm = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');

        foreach (['gateway_amount UNAVAILABLE',
                  'webhook_secret empty',
                  'Rate_limiter fail-open'] as $marker) {

            $at = strpos($fm, $marker);
            $this->assertNotFalse($at, "the '{$marker}' path disappeared — re-derive this test");

            $before = substr($fm, max(0, $at - 160), min(160, $at));
            $this->assertStringContainsString("log_message('error'", $before,
                "'{$marker}' is logged at a level CodeIgniter discards. This line records a "
                . 'SAFETY CHECK BEING SKIPPED on the payment path — without it, a skipped '
                . 'check is indistinguishable from a check that passed.');
        }
    }

    /**
     * The P5-E recalculation planner deliberately does NOT auto-apply ADJUST or
     * OBSOLETE outcomes, because "both mean a human has to decide something"
     * — settled money moved, or a charge left the chart.
     *
     * The entire mechanism for telling that human was one `log_message('warning')`.
     * The escalation escalated to nobody, which quietly turned a considered
     * safety design into a silent no-op: the planner refuses to act, and no one
     * learns there is anything to act on.
     */
    public function test_the_recalculation_escalation_actually_reaches_a_log(): void
    {
        $fees = (string) file_get_contents(APPPATH . 'controllers/Fees.php');

        $at = strpos($fees, 'ACC_RECALC_NEEDS_REVIEW');
        $this->assertNotFalse($at, 'the recalculation escalation was removed');

        $before = substr($fees, max(0, $at - 160), min(160, $at));
        $this->assertStringContainsString("log_message('error'", $before,
            'the only channel that tells a human a paid demand needs review is discarded. '
            . 'The planner refuses to auto-apply precisely so a person can decide — and no '
            . 'person is ever told.');
    }

    /**
     * Accounting propagation being skipped for a student is a ledger gap, not a
     * detail.
     */
    public function test_a_skipped_accounting_propagation_is_audible(): void
    {
        $fees = (string) file_get_contents(APPPATH . 'controllers/Fees.php');

        $at = strpos($fees, 'ACC_PROPAGATION_SKIPPED');
        $this->assertNotFalse($at);

        $before = substr($fees, max(0, $at - 160), min(160, $at));
        $this->assertStringContainsString("log_message('error'", $before,
            'a student whose accounting propagation was skipped leaves no trace');
    }
}
