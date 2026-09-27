<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', 'x');
if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  PAY-OPS-4 · A FAILED WEBHOOK MUST STAY RETRYABLE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE DEFECT ───────────────────────────────────────────────────────────
 * `payment_webhook()` wrote ONE marker shape, unconditionally, after every
 * delivery:
 *
 *     processed_at => now,
 *     result       => success | already_paid | failed
 *
 * `processed_at` is what the dedup READ gate keys on. So a delivery that had
 * just FAILED — a Firestore blip, claim contention, a fee write that threw —
 * was recorded as processed, and every gateway redelivery was answered
 * "Already processed (duplicate webhook)" with a 200. The `result: 'failed'`
 * field recorded the truth, and nothing read it.
 *
 * The gateway's retry is the ONLY automatic path to money the provider already
 * holds. This closed it, permanently, on the first stumble:
 *
 *     attempt fails → marker says processed → provider retries → swallowed
 *
 * ── HOW THIS FILE DRIVES THE REAL CODE ───────────────────────────────────
 * `Fee_management` extends `MY_Controller` and cannot be loaded outside the
 * framework, so the decision was deliberately extracted as a PURE STATIC
 * method (`_webhook_outcome`) with no `$this` and no I/O. This file lifts that
 * method's body straight out of the shipped source and evaluates it as a
 * closure.
 *
 * That matters. The alternative — reproducing the logic here and asserting the
 * controller "still contains" it — is what this repository already does
 * elsewhere and openly calls weaker. It also failed for real: an assertion in
 * WebhookCollaboratorFatalTest kept passing after the code it guarded had
 * moved, because the string it anchored on still existed further down the
 * file. Extracting and executing the actual body cannot drift, because there
 * is no copy to drift from.
 */
final class WebhookRetrySemanticsTest extends TestCase
{
    private static string $src = '';
    /** @var callable */
    private static $outcome;

    public static function setUpBeforeClass(): void
    {
        self::$src = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');

        $sig   = 'private static function _webhook_outcome(array $result): array';
        $start = strpos(self::$src, $sig);
        if ($start === false) {
            self::fail('Fee_management::_webhook_outcome() is gone. The retryable/terminal '
                . 'decision has moved back inline, where it cannot be executed by a test.');
        }

        // Brace-match the body so the extraction cannot silently grab too
        // little (a truncated body would still eval, and would test nothing).
        $open  = strpos(self::$src, '{', $start);
        $depth = 0;
        $end   = null;
        for ($i = $open, $n = strlen(self::$src); $i < $n; $i++) {
            if (self::$src[$i] === '{') $depth++;
            elseif (self::$src[$i] === '}') {
                $depth--;
                if ($depth === 0) { $end = $i; break; }
            }
        }
        if ($end === null) self::fail('could not brace-match _webhook_outcome()');

        $body = substr(self::$src, $open + 1, $end - $open - 1);
        self::assertStringContainsString('return', $body, 'the extracted body is empty');

        /** @noinspection PhpUsageOfSilenceOperatorInspection */
        self::$outcome = eval('return function (array $result): array {' . $body . '};');
    }

    private function outcome(array $result): array
    {
        return (self::$outcome)($result);
    }

    // ══ THE DECISION ════════════════════════════════════════════════════════

    /** A settled payment is final. Nothing left to redeliver for. */
    public function test_a_successful_settlement_is_terminal(): void
    {
        $o = $this->outcome(['ok' => true, 'fee_success' => true, 'retryable' => false, 'receipt_key' => 'R1']);
        $this->assertTrue($o['terminal']);
        $this->assertSame('processed', $o['state']);
    }

    /** Case 12 · A redelivery after success is a duplicate, and also final. */
    public function test_a_duplicate_after_success_is_terminal(): void
    {
        $o = $this->outcome(['ok' => true, 'already_paid' => true, 'retryable' => false]);
        $this->assertTrue($o['terminal']);
        $this->assertSame('duplicate', $o['state']);
    }

    /**
     * THE ONE THAT COST MONEY.
     *
     * Case 11 · The gateway confirmed and the fee write failed. The provider
     * HAS the funds and ZenX has no record of them. This is the outcome that
     * most needs a redelivery, and it was the one being stamped 'processed'.
     */
    public function test_a_failed_fee_write_stays_retryable(): void
    {
        $o = $this->outcome([
            'ok' => true, 'fee_success' => false, 'retryable' => true,
            'error' => 'Gateway confirmed but fee recording failed: Firestore timeout',
        ]);
        $this->assertFalse($o['terminal'],
            'the gateway confirmed the money and ZenX failed to record it — and this is '
            . 'marked FINAL. Every subsequent redelivery is then answered "already '
            . 'processed" and the payment can never be reconciled automatically.');
        $this->assertSame('retry', $o['state']);
    }

    /** Case 13/14 · Claim contention is the textbook come-back-later. */
    public function test_claim_contention_is_retryable(): void
    {
        foreach ([
            'in progress'  => ['ok' => false, 'retryable' => true, 'error' => 'This payment is currently being processed. Please wait.'],
            'undetermined' => ['ok' => false, 'retryable' => true, 'error' => 'We could not start processing this payment just now.'],
        ] as $label => $result) {
            $o = $this->outcome($result);
            $this->assertFalse($o['terminal'], "[{$label}] a concurrent attempt was treated as final");
            $this->assertSame('retry', $o['state']);
        }
    }

    /** A provider that has answered "not captured" will answer the same again. */
    public function test_a_provider_rejection_is_terminal(): void
    {
        $o = $this->outcome([
            'ok' => false, 'retryable' => false,
            'error' => 'The payment gateway does not report this payment as captured.',
        ]);
        $this->assertTrue($o['terminal']);
        $this->assertSame('rejected', $o['state']);
    }

    public function test_an_amount_mismatch_is_terminal(): void
    {
        $o = $this->outcome(['ok' => false, 'retryable' => false, 'error' => 'Payment amount does not match order amount.']);
        $this->assertTrue($o['terminal']);
        $this->assertSame('rejected', $o['state']);
    }

    /**
     * THE DEFAULT IS RETRYABLE, AND THAT ASYMMETRY IS THE POINT.
     *
     * If a future return path forgets `retryable`, the outcome must default to
     * retryable — NOT final. Wrongly retryable costs one redelivery, which the
     * three older idempotency layers absorb without moving money twice.
     * Wrongly final loses a payment the school has already received, silently
     * and permanently.
     */
    public function test_an_outcome_with_no_retryable_flag_defaults_to_retryable(): void
    {
        $o = $this->outcome(['ok' => false, 'error' => 'some future failure nobody flagged']);
        $this->assertFalse($o['terminal'],
            'an unflagged failure defaults to FINAL. That silently reinstates the original '
            . 'defect for every return path added from here on.');
        $this->assertSame('retry', $o['state']);
    }

    /**
     * Success outranks a stray flag. A settled payment cannot be "retryable"
     * whatever else the result array says — that would invite a redelivery
     * against money already applied.
     */
    public function test_success_is_terminal_even_if_retryable_is_wrongly_set(): void
    {
        $this->assertTrue($this->outcome(['fee_success' => true, 'retryable' => true])['terminal']);
        $this->assertTrue($this->outcome(['already_paid' => true, 'retryable' => true])['terminal']);
    }

    /** A clean outcome records no error text; a bad one preserves it. */
    public function test_only_unclean_outcomes_carry_an_error(): void
    {
        $this->assertTrue($this->outcome(['fee_success' => true])['clean']);
        $this->assertTrue($this->outcome(['already_paid' => true])['clean']);
        $this->assertFalse($this->outcome(['ok' => false, 'retryable' => true])['clean']);
        $this->assertFalse($this->outcome(['ok' => false, 'retryable' => false])['clean']);
    }

    // ══ THE MARKER — STRUCTURAL INVARIANTS ══════════════════════════════════

    /**
     * `processed_at` is the flag the dedup read gate keys on. Exactly one
     * writer, behind the terminal check. A second writer is a second way to
     * permanently swallow a retryable failure.
     */
    public function test_processed_at_has_exactly_one_writer_and_it_is_gated(): void
    {
        $this->assertSame(1, substr_count(self::$src, "\$doc['processed_at']"),
            'processed_at is written in more than one place.');

        $mark  = strpos(self::$src, 'private function _webhook_mark(');
        $guard = strpos(self::$src, 'if ($terminal) {', $mark);
        $write = strpos(self::$src, "\$doc['processed_at']", $mark);

        $this->assertNotFalse($guard, '_webhook_mark() no longer gates on $terminal');
        $this->assertGreaterThan($guard, $write,
            'processed_at is written outside the terminal branch — every delivery would '
            . 'again be recorded as handled, including the failures the gateway would '
            . 'otherwise retry successfully.');
    }

    /** The read gate must stay keyed on processed_at, not on mere existence. */
    public function test_the_dedup_read_gate_keys_on_processed_at(): void
    {
        $this->assertStringContainsString(
            "if (is_array(\$existing) && !empty(\$existing['processed_at'])) {", self::$src,
            'the dedup read gate no longer keys on processed_at. Keyed on the DOCUMENT '
            . 'existing instead, a retryable attempt record would itself suppress the '
            . 'retry — reintroducing the defect through the read side.');
    }

    /** A retryable attempt must accumulate, not overwrite — hence merge. */
    public function test_the_marker_is_written_with_merge(): void
    {
        $mark = strpos(self::$src, 'private function _webhook_mark(');
        $set  = strpos(self::$src, "firestoreSet('feeIdempotency', \$dedupDocId, \$doc, true)", $mark);
        $this->assertNotFalse($set,
            'the dedup marker is no longer merged. A terminal outcome would erase the '
            . 'attempt history that shows how many redeliveries it took.');
    }

    /** A marker write that fails must not turn a settled payment into a 500. */
    public function test_a_marker_write_failure_is_non_fatal(): void
    {
        $mark = strpos(self::$src, 'private function _webhook_mark(');
        $body = substr(self::$src, $mark, 2600);
        $this->assertStringContainsString('catch (\Throwable $e)', $body,
            'the marker write is unguarded. It runs AFTER the receipt, allocations and '
            . 'journal are durable; throwing here reports a settled payment as a failure. '
            . '\Throwable, not \Exception — a missing collaborator raises an Error, which '
            . 'is exactly how FEE-W1 killed this pipeline once already.');
    }

    // ══ THE HANDLER — ORDERING AND SIDE-EFFECT INVARIANTS ═══════════════════

    /**
     * Case 5/6/7 · A non-success event must return BEFORE the settlement call.
     * Ordering is the invariant: a gate that runs after the pipeline is not a
     * gate.
     */
    public function test_the_non_success_branch_returns_before_settlement(): void
    {
        $gate   = strpos(self::$src, 'if ($__eventClass === Payment_gateway_selector::EVENT_FAILURE) {');
        $settle = strpos(self::$src, "_verify_and_process(\$gwOrderId, \$gwPaymentId, \$signature, 'webhook')");

        $this->assertNotFalse($gate, 'the failure branch is gone — failed payments would '
            . 'again enter the same pipeline as captures');
        $this->assertLessThan($settle, $gate,
            'the failure branch now runs AFTER settlement. A failed payment would settle '
            . 'first and be classified afterwards.');
    }

    /** Case 7 · An unknown event returns before anything is even parsed for money. */
    public function test_the_ignore_branch_returns_before_settlement(): void
    {
        $gate   = strpos(self::$src, 'if ($__eventClass === Payment_gateway_selector::EVENT_IGNORE) {');
        $settle = strpos(self::$src, "_verify_and_process(\$gwOrderId, \$gwPaymentId, \$signature, 'webhook')");

        $this->assertNotFalse($gate, 'unknown events are no longer short-circuited');
        $this->assertLessThan($settle, $gate);
    }

    /** The event gate must sit AFTER HMAC verification — case 8. */
    public function test_the_event_gate_runs_after_signature_verification(): void
    {
        $hmac = strpos(self::$src, 'if (!webhook_signature_valid($rawBody, $webhookSig, is_array($gwConfig) ? $gwConfig : [])) {');
        $gate = strpos(self::$src, '$__event      = Payment_gateway_selector::classify_provider_event($gwConfig, $payload);');

        $this->assertNotFalse($hmac, 'the webhook HMAC check was renamed or removed');
        $this->assertNotFalse($gate, 'the event gate was renamed or removed');
        $this->assertGreaterThan($hmac, $gate,
            'the event gate runs BEFORE the HMAC check, so an unauthenticated body would '
            . 'decide how ZenX classifies a payment.');
    }

    /**
     * Case 16 · A late-arriving failure must not unpay a settled order.
     * Webhook deliveries are not ordered: a declined first card and a
     * successful second one produce two events for one order, in any order.
     */
    public function test_a_non_success_event_never_overwrites_a_settled_order(): void
    {
        $fn = strpos(self::$src, 'private function _webhook_record_non_success(');
        $this->assertNotFalse($fn, 'the non-success recorder is gone');

        $body = substr(self::$src, $fn, 3000);
        $this->assertStringContainsString(
            "in_array(\$status, ['paid', 'processing', 'verified', 'fees_failed'], true)", $body,
            'the non-success recorder no longer refuses to overwrite a settled or in-flight '
            . 'order. A failure event arriving after the capture would mark a paid student '
            . "unpaid — and overwriting 'fees_failed' would strand a real payment the admin "
            . 'Retry path keys on that exact string to recover.');
    }

    /** And it must never touch a receipt, an allocation or a journal. */
    public function test_the_non_success_recorder_writes_nothing_financial(): void
    {
        $fn   = strpos(self::$src, 'private function _webhook_record_non_success(');
        $body = substr(self::$src, $fn, 3000);

        foreach ([
            'FeeCollectionService', 'markPaymentProcessed', 'feeReceipts',
            'feeDemands', 'feeReceiptAllocations', 'accounting', 'feeOnlinePayments',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body,
                "the non-success path now touches {$forbidden}. A payment that did not "
                . 'happen must produce no financial record of any kind.');
        }
    }

    /** A retryable outcome must answer with a retryable status code. */
    public function test_a_retryable_outcome_answers_503_not_422(): void
    {
        $this->assertStringContainsString("if (!\$__terminal) {\n            http_response_code(503);", self::$src,
            'a retryable webhook failure no longer answers 503. 422 tells the operator '
            . 'reading delivery logs that the payment was rejected, when it was merely '
            . 'unconfirmed.');
    }
}
