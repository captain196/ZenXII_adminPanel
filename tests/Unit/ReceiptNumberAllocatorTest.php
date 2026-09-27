<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  RCPT-1 — ONE CANONICAL RECEIPT-NUMBER ALLOCATOR, AND IT MUST BE ATOMIC
 * ══════════════════════════════════════════════════════════════════════════
 *
 * A duplicate receipt number is one of the worst outcomes this system can
 * produce: two receipts claiming to be the same document, an audit trail that
 * cannot be reconciled, and no way to tell afterwards which payment belonged to
 * which number.
 *
 * `Fee_management::_nextReceiptNo()` was documented as "Atomically increment the
 * receipt counter with retry logic" and did this:
 *
 *     $doc  = firestoreGet('feeCounters', $docId);              // READ
 *     $next = ($doc['value'] ?? 0) + 1;                         // DECIDE
 *     firestoreSet('feeCounters', $docId, ['value' => $next]);  // BLIND SET
 *     $verify = firestoreGet('feeCounters', $docId);            // READ BACK
 *     if ($verify['value'] === $next) return $next;             // "optimistic check"
 *
 * The read-back is worse than no check because it looks like one. Two callers
 * both read 10, both compute 11, both write 11, and both read back 11 — the
 * "verification" SUCCEEDS FOR BOTH and both are handed receipt number 11. The
 * test below models exactly that interleaving to prove it.
 *
 * The fallback was more damaging than the race: after five attempts it wrote
 * `(int)(microtime(true) * 1000) % 999999` as the counter VALUE — a number that
 * can be LOWER than the current counter, rewinding the sequence and colliding
 * with receipts already issued.
 *
 * It had NO callers (verified across PHP and JS including dynamic invocation),
 * so it was a loaded gun rather than a live defect — and a docblock claiming
 * atomicity is precisely what invites someone to pick it up.
 */
class ReceiptNumberAllocatorTest extends TestCase
{
    private static string $fm = '';

    public static function setUpBeforeClass(): void
    {
        self::$fm = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');
    }

    /**
     * Executable code only.
     *
     * The first version of this file asserted against the raw source and was
     * caught by its own revert-test: the method's docblock NAMES
     * `nextCounter('receipt_seq')` while explaining the fix, so deleting the
     * actual call still left the string present and the test still passed.
     *
     * That is the "comments interpreted as code" failure mode, and finding it
     * required actually running the revert rather than assuming the assertion
     * was sound.
     */
    private function executableBody(string $src, int $from, int $len): string
    {
        $body = substr($src, $from, $len);
        $body = preg_replace('!/\*.*?\*/!s', '', $body);
        $body = preg_replace('!^\s*//.*$!m', '', $body);
        $body = preg_replace('!^\s*\*.*$!m', '', $body);
        return (string) $body;
    }

    private function allocatorBody(): string
    {
        $at = strpos(self::$fm, 'private function _nextReceiptNo');
        $this->assertNotFalse($at, '_nextReceiptNo was renamed or removed');
        return $this->executableBody(self::$fm, $at, 3000);
    }

    /**
     * Guards the stripper itself: if it removed the code as well as the
     * comments, every assertion below would pass vacuously.
     */
    public function test_control_the_comment_stripper_keeps_the_code(): void
    {
        $body = $this->allocatorBody();

        $this->assertStringContainsString('function _nextReceiptNo', $body,
            'the comment stripper removed executable code');
        $this->assertStringNotContainsString('THIS ALLOCATOR WAS NOT ATOMIC', $body,
            'the stripper is not removing docblocks, so assertions will match the prose '
            . 'that explains the fix rather than the fix itself — which is exactly how the '
            . 'first version of this test passed against a reverted implementation');
    }

    // ══ CONTROL — THE OLD ALGORITHM REALLY DID COLLIDE ══════════════════════

    /**
     * Models the previous read → decide → blind-set → read-back sequence under
     * the interleaving that actually happens, and requires it to hand the SAME
     * number to two callers while both "verifications" pass.
     *
     * If this ever stopped colliding, RCPT-1 would be wrong and the assertions
     * below would be arbitrary.
     */
    public function test_control_the_old_allocator_hands_two_callers_the_same_number(): void
    {
        $store = ['value' => 10];

        // Both read before either writes — the normal concurrent case.
        $aRead = $store['value'];
        $bRead = $store['value'];

        $aNext = $aRead + 1;
        $bNext = $bRead + 1;

        $store['value'] = $aNext;   // A blind SET
        $aVerify = $store['value'];

        $store['value'] = $bNext;   // B blind SET
        $bVerify = $store['value'];

        $this->assertSame($aNext, $aVerify,
            "A's read-back verification passed");
        $this->assertSame($bNext, $bVerify,
            "B's read-back verification ALSO passed — which is why the check was worse "
            . 'than useless: it produced confidence, not exclusivity');
        $this->assertSame($aNext, $bNext,
            'the old algorithm no longer produces a duplicate receipt number — re-derive '
            . 'RCPT-1 before trusting this file');
    }

    /**
     * The fallback could rewind the counter. A receipt sequence that goes
     * BACKWARDS collides with numbers already issued — permanently, because the
     * bad value was written back as the counter.
     */
    public function test_control_the_old_fallback_could_rewind_the_sequence(): void
    {
        $current = 500000;

        // The old fallback expression, evaluated over a span of instants.
        $canRewind = false;
        for ($ms = 1; $ms <= 999999; $ms += 97) {
            if (($ms % 999999) < $current) { $canRewind = true; break; }
        }

        $this->assertTrue($canRewind,
            'the timestamp-derived fallback could not produce a value below the current '
            . 'counter — if that is no longer possible, this control is stale');
    }

    // ══ THE FIX ═════════════════════════════════════════════════════════════

    /**
     * WOULD HAVE FAILED BEFORE: the method contained its own read/set/verify loop.
     */
    public function test_the_allocator_delegates_to_the_canonical_counter(): void
    {
        $body = $this->allocatorBody();

        $this->assertStringContainsString("nextCounter('receipt_seq')", $body,
            'the allocator no longer delegates to the canonical atomic counter. A second '
            . 'receipt-number allocator is a second thing that must be kept correct, and '
            . 'this one was not.');

        $this->assertStringNotContainsString("firestoreSet('feeCounters'", $body,
            'the allocator writes the counter directly again. A blind SET on a counter '
            . 'hands the same number to concurrent callers.');
    }

    /**
     * The read-back "verification" must be gone entirely — keeping it would
     * reintroduce the false confidence even alongside a correct allocator.
     */
    public function test_the_read_back_verification_pattern_is_gone(): void
    {
        $body = $this->allocatorBody();

        $this->assertStringNotContainsString('$verify', $body,
            'the read-back check is back. It passes for BOTH callers in a collision, so it '
            . 'reports success precisely when the invariant has been violated.');
    }

    /**
     * A derived number must never be invented. Refusing is recoverable; two
     * receipts sharing a number is not.
     */
    public function test_no_timestamp_derived_fallback_number_is_invented(): void
    {
        $body = $this->allocatorBody();

        $this->assertStringNotContainsString('microtime(true) * 1000', $body,
            'the timestamp-derived fallback returned. It can produce a number below the '
            . 'current counter, rewinding the receipt sequence into numbers already issued.');

        $this->assertStringContainsString('RuntimeException', $body,
            'the allocator must FAIL when it cannot obtain a canonical number rather than '
            . 'deriving one');
    }

    /**
     * Failing without the transaction helper is the FEE-W1 lesson applied: a
     * missing collaborator must produce a deliberate refusal, not a null
     * dereference deep inside a money path.
     */
    public function test_a_missing_transaction_helper_is_refused_deliberately(): void
    {
        $body = $this->allocatorBody();

        $this->assertMatchesRegularExpression(
            '/!isset\(\$this->fsTxn\)|!method_exists\(\$this->fsTxn/',
            $body,
            'the allocator dereferences the transaction helper without checking it exists. '
            . 'On a path where MY_Controller did not run, that is an Error — not an '
            . 'Exception — and it would kill the request rather than refuse the allocation.'
        );
    }

    /**
     * There must remain exactly ONE canonical allocator. `Fees.php` has its own
     * wrapper, and it must also delegate rather than grow a second
     * implementation.
     */
    public function test_the_other_wrapper_also_uses_the_canonical_counter(): void
    {
        $fees = (string) file_get_contents(APPPATH . 'controllers/Fees.php');

        $at = strpos($fees, 'private function _nextReceiptNo');
        $this->assertNotFalse($at, 'the Fees allocator wrapper was renamed');
        $body = substr($fees, $at, 1200);

        $this->assertStringContainsString("nextCounter('receipt_seq')", $body,
            'the Fees wrapper no longer delegates to the canonical counter — two allocators '
            . 'would drift apart, and receipt numbering is the one place that cannot');
    }
}
