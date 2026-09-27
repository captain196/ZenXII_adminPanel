<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  FEE-W1 — THE GATEWAY WEBHOOK DIED AFTER WRITING THE RECEIPT
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Fee_management::__construct() detects a webhook and calls
 * CI_Controller::__construct() directly — deliberately, because a gateway
 * webhook carries no session. The consequence is that MY_Controller never runs,
 * so NOTHING it normally loads exists on that path: not `fs`, not `fsTxn`.
 *
 * But _verify_and_process() — the core the webhook SHARES with the frontend
 * callback — calls $this->fsTxn->markPaymentProcessed() after the receipt,
 * allocations and journal have been written.
 *
 * On the webhook path that was an undefined property → null → "Call to a member
 * function on null", which is an **Error, not an Exception**, so the catch
 * around it did not catch it. The request died mid-pipeline, leaving:
 *
 *     receipt            WRITTEN
 *     allocations        WRITTEN
 *     accounting journal WRITTEN
 *     order status=paid  WRITTEN
 *     ────────────────────────────── fatal here
 *     feeOnlinePayments  MISSING     ← "receipt exists, payment record missing"
 *     claim release      SKIPPED     ← 120s of spurious "being processed"
 *     webhook dedup      SKIPPED     ← gateway retries, marked as a failure
 *     HTTP response      500
 *
 * It did NOT double-charge. The retry short-circuits on
 * Payment_service::verify_payment(), which returns already_paid when the local
 * feeOnlineOrders status is 'paid' — and that status is written BEFORE the fatal
 * point. The damage was data integrity and false 500s, not duplicated money.
 *
 * Two independent guards are pinned below, because either alone leaves a hole:
 * loading the collaborator fixes THIS call, and catching \Throwable stops the
 * NEXT missing collaborator from killing the pipeline after the money moved.
 */
class WebhookCollaboratorFatalTest extends TestCase
{
    private static string $src = '';

    public static function setUpBeforeClass(): void
    {
        self::$src = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');
    }

    /** The webhook branch of the constructor, isolated. */
    private function webhookBranch(): string
    {
        $start = strpos(self::$src, 'if ($isWebhook) {');
        $this->assertNotFalse($start, 'the webhook constructor branch has been renamed or removed');
        $end = strpos(self::$src, '} else if ($isParentApi)', $start);
        $this->assertNotFalse($end, 'the constructor branch structure changed');
        return substr(self::$src, $start, $end - $start);
    }

    // ══ CONTROL — THE LANGUAGE SEMANTIC THIS BUG RESTS ON ═══════════════════

    /**
     * Without this, the assertions below are arbitrary style rules. This proves
     * WHY \Exception was insufficient: calling a method on null raises an Error,
     * and Error is not an Exception, so it escapes a catch(\Exception) entirely.
     */
    public function test_control_a_null_method_call_escapes_a_catch_of_Exception(): void
    {
        // NOTE: the collaborator is an EXPLICITLY null property, not an undefined
        // one. Under PHPUnit an undefined-property access raises a PHP warning
        // that the framework converts into an Exception, which would be caught
        // by the inner catch and mask the very semantic being demonstrated. The
        // production hazard is the null dereference itself, so that is what is
        // modelled here.
        $escaped = null;
        try {
            (new class {
                private $missingCollaborator = null;
                public function run(): void
                {
                    try {
                        $this->missingCollaborator->doSomething();
                    } catch (\Exception $e) {
                        throw new \LogicException('caught as Exception — semantics changed');
                    }
                }
            })->run();
        } catch (\Throwable $t) {
            $escaped = $t;
        }

        $this->assertInstanceOf(\Error::class, $escaped,
            'a method call on a missing collaborator no longer raises an Error — '
            . 'if PHP semantics changed, re-derive FEE-W1 before trusting this file');
        $this->assertNotInstanceOf(\Exception::class, $escaped,
            'Error is not an Exception; that is precisely why the original catch '
            . 'let the webhook die after the receipt was written');
    }

    // ══ GUARD 1 — THE COLLABORATOR EXISTS ON THE WEBHOOK PATH ═══════════════

    public function test_the_webhook_branch_initialises_the_transaction_helper(): void
    {
        $branch = $this->webhookBranch();

        $this->assertStringContainsString("load->library('Fee_firestore_txn'", $branch,
            'the webhook constructor no longer loads Fee_firestore_txn, but the shared '
            . 'core still calls $this->fsTxn after writing the receipt — FEE-W1 returns');
        $this->assertStringContainsString('fsTxn->init(', $branch,
            'Fee_firestore_txn is loaded but never init()-ed; every method then '
            . 'short-circuits on its $ready flag and the marker is silently never written');
    }

    /**
     * fsTxn::init() requires a Firestore_service. Loading fsTxn without `fs`
     * would leave it permanently not-ready — a silent no-op rather than a fatal,
     * which is harder to notice, not better.
     */
    public function test_the_webhook_branch_also_provides_the_firestore_service(): void
    {
        $branch = $this->webhookBranch();

        $this->assertStringContainsString("load->library('firestore_service'", $branch,
            'fsTxn::init() takes a Firestore_service; without it fsTxn is never ready');
        $this->assertStringContainsString('fs->init(', $branch,
            'an uninitialised Firestore_service resolves an EMPTY tenant, and a '
            . 'school-scoped doc id built from it would address another key entirely');
    }

    /**
     * The tenant must come from the resolved order context, never from a session
     * the webhook does not have, and never from a hardcoded id.
     */
    public function test_the_webhook_tenant_comes_from_the_resolved_order_context(): void
    {
        $branch = $this->webhookBranch();

        $this->assertStringContainsString('resolveSchoolFromOrder(', $branch,
            'the webhook must resolve its tenant from the order, not from a session');
        $this->assertStringContainsString('$this->fs->init($this->school_name', $branch,
            'the Firestore service must be scoped to the RESOLVED tenant');
        $this->assertDoesNotMatchRegularExpression('/SCH_[A-Z0-9]{8,}/', $branch,
            'a hardcoded tenant id appears in the webhook constructor — the UAT school '
            . 'is a canary, never a code path');
    }

    // ══ GUARD 2 — A MISSING COLLABORATOR CANNOT KILL THE PIPELINE ═══════════

    /**
     * By the time markPaymentProcessed() is called the money has already moved:
     * receipt, allocations and journal are durable. A bookkeeping marker must
     * never be able to abort the writes that follow it.
     */
    public function test_markPaymentProcessed_is_guarded_against_Throwable_not_merely_Exception(): void
    {
        // EVERY real call site, not merely the first match — a bare
        // strpos('markPaymentProcessed(') lands on a comment, and the shared
        // core is the third occurrence, not the first.
        $sites  = [];
        $offset = 0;
        while (($p = strpos(self::$src, '$this->fsTxn->markPaymentProcessed(', $offset)) !== false) {
            $offset = $p + 1;

            // Skip prose. The FEE-W1 explanation in the constructor quotes this
            // call verbatim, and a commented mention is not a call site.
            $lineStart = (int) strrpos(substr(self::$src, 0, $p), "\n");
            $prefix    = trim(substr(self::$src, $lineStart, $p - $lineStart));
            if (str_starts_with($prefix, '//') || str_starts_with($prefix, '*')) {
                continue;
            }
            $sites[] = $p;
        }

        $this->assertGreaterThanOrEqual(3, count($sites),
            'expected the frontend callback, the shared core and the retry path to each '
            . 'record the payment marker; a site has disappeared');

        foreach ($sites as $i => $pos) {
            $window  = substr(self::$src, $pos, 700);
            $catchAt = strpos($window, 'catch (');
            $this->assertNotFalse($catchAt,
                "markPaymentProcessed call site #{$i} is no longer guarded at all");

            $this->assertStringContainsString('\Throwable', substr($window, $catchAt, 40),
                "markPaymentProcessed call site #{$i} is guarded by catch(\\Exception), which "
                . 'does NOT catch the Error raised by a missing collaborator. That is FEE-W1: '
                . 'the webhook died at such a site AFTER the receipt was written, skipping the '
                . 'feeOnlinePayments record, the claim release and the webhook dedup marker.');
        }
    }

    /**
     * The dedup marker is written AFTER processing, so anything that aborts the
     * pipeline also prevents the replay guard from being recorded. That ordering
     * is what turned a single failed call into a repeated 500 from the gateway.
     * Pinned so the coupling stays visible.
     */
    public function test_the_webhook_dedup_marker_is_written_after_processing(): void
    {
        $verify = strpos(self::$src, "_verify_and_process(\$gwOrderId, \$gwPaymentId, \$signature, 'webhook')");
        $this->assertNotFalse($verify, 'the webhook processing call was renamed');

        // PAY-OPS-4: the marker write moved into _webhook_mark(), so this must
        // look for the CALL, not the firestoreSet. Anchoring on
        // "firestoreSet('feeIdempotency'" kept passing after the move only
        // because that string still exists further down the file inside the
        // helper — the assertion had quietly stopped testing the ordering it
        // was written to protect.
        $marker = strpos(self::$src, '$this->_webhook_mark($dedupDocId, $existing, $__state', $verify);
        $this->assertNotFalse($marker,
            'the webhook no longer records a dedup marker after processing — replayed '
            . 'gateway deliveries would re-enter the money pipeline');

        $this->assertGreaterThan($verify, $marker,
            'the dedup marker is now written BEFORE processing. That would mark a '
            . 'payment as handled before it actually was, so a crash mid-pipeline '
            . 'would be silently swallowed instead of retried.');
    }

    /**
     * PAY-OPS-4 — THE MARKER MUST NOT CLAIM SUCCESS IT DID NOT HAVE.
     *
     * The sibling of the ordering test above, and the defect the ordering test
     * could not see. Writing the marker AFTER processing is necessary but not
     * sufficient: it was written after processing and UNCONDITIONALLY, with
     * `processed_at` set even when reconciliation had just failed. Since the
     * dedup READ gate keys on `processed_at`, every gateway retry was then
     * answered "Already processed (duplicate webhook)" — and the gateway's
     * retry is the only automatic route to money the provider already holds.
     *
     * Pinned structurally: `processed_at` must be written in exactly ONE place
     * in this controller, and that place must be behind the terminal check.
     */
    public function test_processed_at_is_written_only_for_terminal_outcomes(): void
    {
        $occurrences = substr_count(self::$src, "\$doc['processed_at']");
        $this->assertSame(1, $occurrences,
            'processed_at is written in more than one place. It is the flag the dedup '
            . 'read gate keys on, so a second writer is a second way to permanently '
            . 'swallow a retryable failure.');

        $mark = strpos(self::$src, 'private function _webhook_mark(');
        $this->assertNotFalse($mark, '_webhook_mark() is gone — the marker states have been removed');

        $guard = strpos(self::$src, 'if ($terminal) {', $mark);
        $write = strpos(self::$src, "\$doc['processed_at']", $mark);

        $this->assertNotFalse($guard,
            '_webhook_mark() no longer gates on $terminal. Without that gate every '
            . 'delivery is stamped as processed again, including the transient failures '
            . 'the gateway would otherwise retry successfully.');
        $this->assertGreaterThan($guard, $write,
            'processed_at is written outside the terminal branch — a retryable failure '
            . 'would once again be recorded as handled and the redelivery swallowed.');
    }

    /**
     * PAY-OPS-4 — A FAILED FEE WRITE MUST STAY RETRYABLE.
     *
     * The single most expensive state: the gateway HAS the money and ZenX
     * failed to record it. This is the outcome that most needs the provider to
     * redeliver, and it was the one being marked processed.
     */
    public function test_the_fee_recording_failure_path_is_marked_retryable(): void
    {
        $branch = strpos(self::$src, "'fee_error'   => \"Gateway confirmed but fee recording failed");
        $this->assertNotFalse($branch, 'the fees_failed return path was renamed');

        $window = substr(self::$src, max(0, $branch - 2600), 2700);

        // ── UPDATED BY PAY-OPS-6, DELIBERATELY NOT DELETED ──────────────────
        //
        // This asserted the literal `'retryable'   => true,`. PAY-OPS-6
        // refined the contract: the flag is now DERIVED from
        // FeeCollectionService's own http_code, so that a permanent business
        // refusal (pay-older-first, HTTP 409 — which returns the identical
        // refusal on every redelivery) stops inviting retries, while every
        // transient fault stays retryable exactly as before.
        //
        // The invariant this test exists to protect is unchanged and still
        // load-bearing: a fee-write failure must NEVER be hardcoded terminal.
        // So the assertion moves from "is literally true" to "is computed from
        // the classification" — a hardcoded `false` here would be the original
        // money-losing defect returning, and is what this now catches.
        //
        // The behavioural half — which codes are permanent and which are not —
        // is proven by FeeFailureClassificationTest, which executes the real
        // classifier rather than reading it.
        $this->assertStringContainsString("'retryable'      => !\$__permanent,", $window,
            'the "gateway confirmed but fee recording failed" outcome no longer derives its '
            . 'retryability from the service\'s failure code. The provider holds the money '
            . 'and ZenX has no record of it; hardcoding this terminal discards the only '
            . 'automatic path to reconciling a transient failure.');

        $this->assertStringNotContainsString("'retryable'   => true,", $window,
            'a second, hardcoded `retryable => true` is back in this array. PHP lets the '
            . 'LAST duplicate key win silently, so it would overwrite the computed '
            . 'classification and send every business refusal out as retryable again — the '
            . 'exact bug that only the end-to-end run caught.');
    }
}
