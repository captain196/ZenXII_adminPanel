<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', 'x');
if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  PAY-OPS-6 · "TRY AGAIN" vs "THIS WILL NEVER WORK"
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE DEFECT ───────────────────────────────────────────────────────────
 * PAY-OPS-4 made every fee-write failure RETRYABLE, which fixed the worse bug
 * (a transient failure being recorded as processed, so the gateway's retry —
 * the only automatic route to captured money — was swallowed). But it
 * over-corrected: a PERMANENT business refusal was also marked retryable.
 *
 * Pay-older-first is the live example, and it is not hypothetical: the device
 * run in this programme hit it. A parent paying June while April is
 * outstanding is refused by `FeeCollectionService` with HTTP 409, every single
 * time. Telling the gateway "retry" there is a promise the system cannot keep:
 * it burns the provider's retry budget on an outcome that cannot change, and
 * the payment — which the gateway HAS captured — is buried under redeliveries
 * instead of surfaced to a human.
 *
 * ── THE SIGNAL ALREADY EXISTED ───────────────────────────────────────────
 * `FeeCollectionService` returns an `http_code` with every refusal:
 *
 *   409  pay-older-first / already-fully-paid / duplicate   BUSINESS verdict
 *   422  validation refusal                                 BUSINESS verdict
 *   423  Locked — another attempt holds the lock            TRANSIENT
 *   5xx  internal fault                                     TRANSIENT
 *
 * `_verify_and_process()` read only `message` and discarded the code, so the
 * two classes were indistinguishable at the one place that had to tell them
 * apart. The fix is to stop throwing the answer away.
 *
 * ── WHY BOTH STILL LAND IN `fees_failed` ─────────────────────────────────
 * In both cases the gateway holds real money that ZenX has not applied. The
 * difference is only who can resolve it — the provider by redelivering, or a
 * human. So the order state is the same recoverable one either way, and a
 * business refusal is ACKNOWLEDGED (HTTP 200) so the provider stops asking
 * while the operator owns it.
 *
 * The classifier is pure and static, so this file lifts its body out of the
 * shipped controller and executes it. A CI3 controller cannot be instantiated
 * outside the framework, and a mirrored copy could drift from the code it
 * claims to test.
 */
final class FeeFailureClassificationTest extends TestCase
{
    private static string $src  = '';
    private static string $code = '';
    /** @var callable */
    private static $isPermanent;
    /** @var callable */
    private static $outcome;

    public static function setUpBeforeClass(): void
    {
        self::$src = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');

        $out = '';
        foreach (token_get_all(self::$src) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        self::$code = preg_replace("/(\r?\n)(?:[ \t]*\r?\n)+/", "\$1", $out);

        self::$isPermanent = self::lift(
            'private static function _fee_failure_is_permanent(int $httpCode): bool',
            'function (int $httpCode): bool'
        );
        self::$outcome = self::lift(
            'private static function _webhook_outcome(array $result): array',
            'function (array $result): array'
        );
    }

    /** Execute the REAL method body, not a copy of it. */
    private static function lift(string $signature, string $closureHead): callable
    {
        $start = strpos(self::$src, $signature);
        if ($start === false) self::fail("method is gone: {$signature}");

        $open  = strpos(self::$src, '{', $start);
        $depth = 0; $end = null;
        for ($i = $open, $n = strlen(self::$src); $i < $n; $i++) {
            if (self::$src[$i] === '{') $depth++;
            elseif (self::$src[$i] === '}') { $depth--; if ($depth === 0) { $end = $i; break; } }
        }
        if ($end === null) self::fail("could not brace-match: {$signature}");

        $body = substr(self::$src, $open + 1, $end - $open - 1);
        self::assertStringContainsString('return', $body, 'lifted an empty body');

        // The lifted body calls its sibling by `self::`, which does not exist
        // in a closure — rewrite to the local variable holding it.
        $body = str_replace('self::_fee_failure_is_permanent(', '($GLOBALS[\'__payops6_perm\'])(', $body);

        return eval('return ' . $closureHead . ' {' . $body . '};');
    }

    private function permanent(int $code): bool
    {
        return (self::$isPermanent)($code);
    }

    private function outcome(array $r): array
    {
        $GLOBALS['__payops6_perm'] = self::$isPermanent;
        return (self::$outcome)($r);
    }

    // ══ THE CLASSIFIER ══════════════════════════════════════════════════════

    /**
     * @dataProvider codes
     */
    public function test_failure_codes_are_classified(int $code, bool $permanent, string $why): void
    {
        $this->assertSame($permanent, $this->permanent($code), $why);
    }

    public function codes(): array
    {
        return [
            'pay-older-first / duplicate (409)' => [409, true,
                'HTTP 409 is a BUSINESS verdict — pay-older-first refuses identical input '
                . 'identically, forever. Marking it retryable asks the gateway to redeliver '
                . 'an outcome that cannot change, burying a captured payment.'],
            'validation refusal (422)' => [422, true,
                'HTTP 422 is a validation refusal and will not resolve by waiting'],

            'lock contention (423)' => [423, false,
                'HTTP 423 means another attempt holds the lock RIGHT NOW — the textbook '
                . 'transient failure. Treating it as permanent discards a payment that would '
                . 'have settled on the next delivery.'],
            'internal fault (500)' => [500, false, 'a server fault must stay retryable'],
            'bad gateway (502)'    => [502, false, ''],
            'unavailable (503)'    => [503, false, ''],

            // The default matters as much as the known cases.
            'no code at all (0)' => [0, false,
                'an absent code means the service did not say, or the call threw. Not '
                . 'positively known to be permanent, so it must stay retryable — the '
                . 'asymmetry the whole module is built on.'],
            'unrecognised 4xx (418)' => [418, false,
                'an unrecognised code was assumed permanent. Only codes positively known to '
                . 'be permanent may suppress the provider retry.'],
            'unrecognised 4xx (429)' => [429, false,
                'rate limiting is transient by definition and must not be classed permanent'],
            'not-found (404)' => [404, false, ''],
        ];
    }

    /**
     * THE DEFAULT IS THE SAFETY PROPERTY.
     *
     * Anything not explicitly 409/422 must stay retryable. If a future
     * FeeCollectionService refusal introduces a new code, the system errs
     * toward one extra redelivery — absorbed by the feeProcessedPayments
     * marker, the paid-order short-circuit and the payment claim — rather than
     * toward losing money.
     */
    public function test_only_409_and_422_are_treated_as_permanent(): void
    {
        for ($c = 400; $c <= 599; $c++) {
            $expected = ($c === 409 || $c === 422);
            $this->assertSame($expected, $this->permanent($c),
                "HTTP {$c} classified wrongly — only 409 and 422 may suppress a provider retry");
        }
    }

    // ══ THE OUTCOME STATE ═══════════════════════════════════════════════════

    /** A business refusal is terminal, but reported as its own state. */
    public function test_a_business_refusal_is_terminal_and_distinctly_named(): void
    {
        $o = $this->outcome([
            'ok' => true, 'fee_success' => false,
            'retryable' => false, 'business_refusal' => true,
            'error' => 'Please clear the earlier pending fees for April 2026 …',
        ]);

        $this->assertTrue($o['terminal'], 'the gateway would keep redelivering a refusal that cannot change');
        $this->assertSame('business_refused', $o['state'],
            'a business refusal is being reported as a plain rejection. In a "rejected" the '
            . 'money was never ours to take; here the gateway HAS it and ZenX declined to '
            . 'apply it — an operator reading "rejected" would close the ticket.');
        $this->assertFalse($o['clean'], 'the refusal message must be preserved');
    }

    /** A transient failure stays retryable and is NOT relabelled. */
    public function test_a_transient_failure_is_still_retryable(): void
    {
        $o = $this->outcome([
            'ok' => true, 'fee_success' => false,
            'retryable' => true, 'business_refusal' => false,
            'error' => 'Gateway confirmed but fee recording failed: Firestore timeout',
        ]);

        $this->assertFalse($o['terminal'],
            'a transient fee-write failure was made terminal — this is the PAY-OPS-4 defect '
            . 'returning, and it loses money the gateway already holds');
        $this->assertSame('retry', $o['state']);
    }

    /** A verification rejection is still 'rejected', not a business refusal. */
    public function test_a_provider_rejection_is_not_relabelled_as_a_business_refusal(): void
    {
        $o = $this->outcome([
            'ok' => false, 'retryable' => false,
            'error' => 'The payment gateway does not report this payment as captured.',
        ]);
        $this->assertSame('rejected', $o['state'],
            'an uncaptured payment must stay "rejected" — no money was taken, so it is not '
            . 'an operator recovery case');
    }

    /** Success and duplicate outrank any stray business flag. */
    public function test_settled_outcomes_are_never_business_refusals(): void
    {
        $this->assertSame('processed',
            $this->outcome(['fee_success' => true, 'business_refusal' => true])['state']);
        $this->assertSame('duplicate',
            $this->outcome(['already_paid' => true, 'business_refusal' => true])['state']);
    }

    // ══ THE WIRING ══════════════════════════════════════════════════════════

    /** The service's code must actually be captured, not discarded again. */
    public function test_the_service_http_code_is_captured(): void
    {
        $this->assertStringContainsString(
            "\$feeHttpCode = is_array(\$serviceResult) ? (int) (\$serviceResult['http_code'] ?? 0) : 0;",
            self::$code,
            'the FeeCollectionService http_code is no longer read, so business refusals and '
            . 'transient faults are indistinguishable again');
    }

    /** A thrown exception is an internal fault, never a business verdict. */
    public function test_a_thrown_exception_is_not_classified_as_a_business_refusal(): void
    {
        // Anchor on the log line unique to THIS catch. `catch (\Exception $e)`
        // occurs dozens of times in this controller, and anchoring on the first
        // one lands in _getFeesStructure — an assertion that reads correct and
        // tests nothing about the payment path.
        $log = strpos(self::$code, 'verify_and_process({$source}): fees threw order=');
        $this->assertNotFalse($log, 'the fee-exception handler was renamed');

        // The reset must appear between the catch and that log line.
        $block = substr(self::$code, max(0, $log - 400), 400);
        $this->assertStringContainsString('$feeHttpCode = 0;', $block,
            'an exception must reset the code to unknown. The service refuses deliberately '
            . 'by RETURNING; a throw is a fault, and a stale code from an earlier read would '
            . 'misclassify it.');
    }

    /**
     * A business refusal must be ACKNOWLEDGED (200), not answered with a
     * non-2xx that makes the provider redeliver forever.
     */
    public function test_a_business_refusal_is_acknowledged_not_retried(): void
    {
        $branch = strpos(self::$code, "if (\$__state === 'business_refused') {");
        $this->assertNotFalse($branch,
            'the business-refusal response branch is gone — the provider would keep '
            . 'redelivering an outcome that can never change');

        $block = substr(self::$code, $branch, 700);
        $this->assertStringContainsString('http_response_code(200);', $block,
            'a business refusal no longer returns 200, so the gateway will retry pointlessly');
        $this->assertStringContainsString("'reconciled'  => false", $block,
            '200 without an explicit "not reconciled" reads as a receipt in a delivery log');
        $this->assertStringContainsString("'needs_operator_action' => true", $block,
            'nothing tells the operator this payment needs them');
    }

    /**
     * THE BUG THE UNIT TESTS COULD NOT SEE.
     *
     * The fees_failed return array briefly carried `'retryable'` TWICE: once
     * computed from the service's http_code, and once as a leftover literal
     * `true` further down. A duplicate key in a PHP array literal is not an
     * error — the later one silently wins — so every business refusal went out
     * as retryable regardless of the classification, and the provider was
     * invited to redeliver an outcome that could never change.
     *
     * Every test above still passed, because they drive `_webhook_outcome()`
     * with a hand-built array and never see how the real array is assembled.
     * It was caught only by the end-to-end run asserting the OUTCOME (HTTP 503
     * and state 'retry' where 200 and 'business_refused' were required).
     *
     * This is the unit-level guard that closes that gap: within the returned
     * literal, each key may appear exactly once.
     */
    public function test_the_failure_return_array_has_no_duplicate_keys(): void
    {
        $anchor = strpos(self::$code, "'fee_success' => false,");
        $this->assertNotFalse($anchor, 'the fees_failed return array was renamed');

        $end = strpos(self::$code, '];', $anchor);
        $this->assertNotFalse($end);
        $literal = substr(self::$code, $anchor, $end - $anchor);

        preg_match_all("/'([a-z_]+)'\s*=>/", $literal, $m);
        $counts = array_count_values($m[1]);

        foreach ($counts as $key => $n) {
            $this->assertSame(1, $n,
                "the key '{$key}' appears {$n} times in the fee-failure return array. PHP "
                . 'silently lets the LAST one win, so an earlier computed value is discarded '
                . 'without any error — which is exactly how the retryable classification was '
                . 'overwritten by a leftover literal.');
        }

        $this->assertArrayHasKey('retryable', $counts, 'the retryable flag is gone entirely');
        $this->assertArrayHasKey('business_refusal', $counts);
    }

    /** The order must stay recoverable and say which kind of failure it was. */
    public function test_the_order_records_the_failure_class_for_the_operator(): void
    {
        $this->assertStringContainsString("'status'         => 'fees_failed',", self::$code,
            'the order no longer lands in fees_failed — the admin Retry path keys on exactly '
            . 'that string, so the captured money would be unrecoverable by any tool');

        $this->assertStringContainsString("'failure_class'  => self::_fee_failure_is_permanent(\$feeHttpCode) ? 'business' : 'transient',", self::$code,
            'the order does not record WHICH kind of failure it was, so an operator cannot '
            . 'tell a payment that needs their action from one that will clear itself');
    }
}
