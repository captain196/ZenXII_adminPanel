<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  R3 — THE STALE TAKEOVER WAS THE ONE UNGUARDED WRITE LEFT
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `FeeCollectionService` builds a claim batch holding three ops: the
 * idempotency slot, the per-student lock, and the receipt-number reservation.
 * Two of them carried a precondition — but only on the FRESH path:
 *
 *     if (!$__lockStale) { $claimOps[1]['precondition'] = ['exists' => false]; }
 *     if (!$__rcptStale) { $claimOps[2]['precondition'] = ['exists' => false]; }
 *
 * When the document was STALE (older than 120s), no precondition was attached
 * at all — a blind overwrite. So two processes that both observed the same
 * expired lock both "recovered" it and both entered the critical section. That
 * is precisely the read-then-blind-write defect removed by B-025 (order claim)
 * and REF-1 (refund lock), surviving in the one place nobody had looked: the
 * recovery path of the participant that was otherwise correct.
 *
 * For the receipt-index reservation the consequence is worse than double entry:
 * two writers could take the SAME receipt number, producing two receipts that
 * claim to be the same document.
 *
 * ── WHY THIS IS A SOURCE TEST ─────────────────────────────────────────────
 * The property is the SHAPE of a batch op assembled deep inside submit(), which
 * cannot be reached without bootstrapping CodeIgniter, a controller and a live
 * Firestore. A mock-based test of an atomic commit would assert the mock rather
 * than the atomicity. The underlying primitive — that a currentDocument
 * precondition is actually enforced, and that exactly one of N concurrent
 * writers wins — is proven separately against a real emulator in
 * firebase-rules/tests/payment_claim_cas.test.js.
 */
class StaleLockTakeoverTest extends TestCase
{
    private static string $svc = '';

    public static function setUpBeforeClass(): void
    {
        self::$svc = (string) file_get_contents(APPPATH . 'services/FeeCollectionService.php');
    }

    /** The claim-batch precondition block. */
    private function preconditionBlock(): string
    {
        $at = strpos(self::$svc, "\$claimOps[1]['precondition']");
        $this->assertNotFalse($at, 'the claim-batch precondition assignment was renamed or removed');

        return substr(self::$svc, max(0, $at - 600), 2600);
    }

    // ══ CONTROL ═════════════════════════════════════════════════════════════

    public function test_control_the_claim_batch_still_has_three_guarded_ops(): void
    {
        $this->assertStringContainsString("\$claimOps[1]['precondition']", self::$svc,
            'the lock op no longer carries a precondition at all');
        $this->assertStringContainsString("\$claimOps[2]['precondition']", self::$svc,
            'the receipt-index op no longer carries a precondition at all');
    }

    // ══ THE FRESH PATH MUST STAY EXCLUSIVE ══════════════════════════════════

    public function test_a_fresh_lock_is_still_created_exclusively(): void
    {
        $block = $this->preconditionBlock();

        $this->assertMatchesRegularExpression(
            "/if \(!\\\$__lockStale\) \{\s*\\\$claimOps\[1\]\['precondition'\] = \['exists' => false\];/",
            $block,
            'the fresh-lock path lost its exists:false precondition — concurrent first '
            . 'acquirers would both succeed'
        );
    }

    // ══ THE STALE PATH — THE DEFECT ═════════════════════════════════════════

    /**
     * WOULD HAVE FAILED BEFORE: the stale branch did not exist; staleness simply
     * meant "attach no precondition".
     */
    public function test_a_stale_lock_takeover_carries_a_version_precondition(): void
    {
        $block = $this->preconditionBlock();

        $this->assertStringContainsString("\$__lockVersion", $block,
            'the stale-lock path does not read the observed document version. Without it '
            . 'the takeover is a blind overwrite, and two processes that both see the lock '
            . 'expired both enter the critical section.');

        $this->assertMatchesRegularExpression(
            "/\\\$claimOps\[1\]\['precondition'\] = \['updateTime' => \\\$__lockVersion\]/",
            $block,
            'the stale-lock takeover is not a compare-and-swap on the version that was '
            . 'actually observed. Any other value — a fresh read, a timestamp — would not '
            . 'detect a competitor that moved the document in between.'
        );
    }

    /**
     * The receipt reservation has the same shape and a worse failure mode:
     * two writers taking the same receipt number.
     */
    public function test_a_stale_receipt_reservation_takeover_is_also_guarded(): void
    {
        $block = $this->preconditionBlock();

        $this->assertMatchesRegularExpression(
            "/\\\$claimOps\[2\]\['precondition'\] = \['updateTime' => \\\$__rcptVersion\]/",
            $block,
            'a stale receipt-number reservation is still overwritten blindly. Two writers '
            . 'could take the SAME receipt number and mint two receipts claiming to be one '
            . 'document.'
        );
    }

    /**
     * The CAS version must come from the PRELOADED document — the same read that
     * judged the lock stale. Reading it again later would reopen the window
     * between the decision and the write.
     */
    public function test_the_version_comes_from_the_document_that_was_judged_stale(): void
    {
        $block = $this->preconditionBlock();

        $this->assertStringContainsString("\$__existingLock['__updateTime']", $block,
            'the lock version is not taken from the preloaded document that the staleness '
            . 'decision was made against');
        $this->assertStringContainsString("\$__existingRcpt['__updateTime']", $block,
            'the receipt-index version is not taken from the preloaded document');
    }

    // ══ FAIL CLOSED, NOT UNGUARDED ══════════════════════════════════════════

    /**
     * A document with no version cannot be compare-and-swapped. Writing anyway
     * would be exactly the defect being fixed, so the collection must refuse.
     *
     * This branch is not expected to fire in practice — both preload paths
     * decode through decodeDocument(), which always surfaces __updateTime for an
     * existing document — which is precisely why failing closed is safe: it
     * costs a retry in a situation that should not occur, rather than conceding
     * exclusivity in one that does.
     */
    public function test_a_missing_version_refuses_rather_than_writing_unguarded(): void
    {
        $block = $this->preconditionBlock();

        $this->assertStringContainsString('$__staleTakeoverBlocked', $block,
            'there is no refusal path for a stale document with no version — the code '
            . 'would fall through and commit the takeover unguarded');

        $this->assertStringContainsString('423', $block,
            'the refusal must surface as the existing "still processing" response so the '
            . 'client contract is unchanged');
    }

    /**
     * Both response shapes must be covered. submit() serves an API caller
     * (returns an array) and a form caller (writes to output); a refusal that
     * only handles one would fall through on the other and commit anyway.
     */
    public function test_the_refusal_covers_both_caller_response_modes(): void
    {
        $block = $this->preconditionBlock();

        $this->assertStringContainsString('$writeToOutput', $block,
            'the stale-takeover refusal does not branch on the caller response mode, so '
            . 'one of the two callers would continue past it');
        $this->assertStringContainsString('return null;', $block,
            'the output-writing caller path must return after refusing');
    }
}
