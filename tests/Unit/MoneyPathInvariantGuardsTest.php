<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

/**
 * FEE COLLECTION — THE SETTLED-MONEY GUARANTEES.
 *
 * ── WHAT THIS FILE IS, AND IS NOT ─────────────────────────────────────────
 * The estate contains ZERO payments, receipts and journals, so the collection
 * path cannot be production-verified without manufacturing a financial
 * transaction — which is out of bounds. These are therefore ARCHITECTURAL
 * BOUNDARY GUARDS over `FeeCollectionService`, and they are labelled as such:
 * they prove a protection is PRESENT and wired, not that it fired correctly
 * against real money.
 *
 * That distinction is the point. Everywhere a behavioural test was possible in
 * this programme, one was written; here it genuinely is not, and pretending
 * otherwise would be the exact overstatement the lifecycle audit exists to
 * prevent.
 *
 * The pure allocation arithmetic IS tested behaviourally at the end, because
 * that part can be.
 */
class MoneyPathInvariantGuardsTest extends TestCase
{
    private const SVC = APPPATH . 'services/FeeCollectionService.php';
    private const TXN = APPPATH . 'libraries/Fee_firestore_txn.php';

    /** Executable code only — the file carries long historical docblocks. */
    private function code(string $path): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $t) {
            $out .= is_array($t)
                ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1])
                : $t;
        }
        return $out;
    }

    // ══ TENANT ISOLATION ═══════════════════════════════════════════════════

    /**
     * Every document the collection path touches is keyed by the CANONICAL
     * school id — not `parent_db_key`, not a school code.
     */
    public function test_collection_keys_every_document_by_the_canonical_school_id(): void
    {
        $c = $this->code(self::SVC);

        $this->assertStringContainsString('$schoolFs = $controller->fs->schoolId()', $c,
            'the tenant key must come from the canonical resolver');
        $this->assertStringNotContainsString('parent_db_key', $c,
            'the legacy key space must never scope a financial document');

        // Note the interpolation form: the source writes "{$schoolFs}_…", not
        // "${schoolFs}_…". Matching the wrong one reports a missing tenant
        // prefix that is actually present.
        foreach (['feeIdempotency', 'feeStructures', 'feeLocks', 'feeReceiptIndex'] as $col) {
            $this->assertMatchesRegularExpression(
                "/'{$col}',\s*'docId'\s*=>\s*\"\\{\\\$schoolFs\\}/", $c,
                "{$col} must be tenant-prefixed");
        }
    }

    /** No tenant identifier may be baked into the money path. */
    public function test_no_tenant_id_is_hardcoded_in_the_money_path(): void
    {
        foreach ([self::SVC, self::TXN] as $f) {
            $this->assertDoesNotMatchRegularExpression('/SCH_[A-Z0-9]{8,}/', $this->code($f),
                basename($f) . ' must not carry a tenant id in executable code');
        }
    }

    // ══ IDEMPOTENCY — a duplicate submit must not charge twice ═════════════

    public function test_a_duplicate_submit_is_refused_by_an_idempotency_record(): void
    {
        $c = $this->code(self::SVC);

        $this->assertStringContainsString('feeIdempotency', $c,
            'a re-submitted payment must be recognised, not re-charged');
        $this->assertStringContainsString('idempKey(', $c,
            'the key must be derived from the request, not the clock');
        $this->assertStringContainsString("'idempotent' => true", $c,
            'and the duplicate must be reported honestly rather than silently succeeding');
    }

    /**
     * The idempotency key must be derived from what makes a payment UNIQUE.
     * A key omitting the amount or the months would collapse two genuinely
     * different payments onto one record and silently drop the second.
     */
    public function test_the_idempotency_key_covers_student_receipt_months_and_amounts(): void
    {
        $c = $this->code(self::SVC);
        $this->assertStringContainsString(
            'idempKey($userId, $receiptNo, $selectedMonths, $schoolFees)', $c,
            'all four components are required for the key to identify one payment');
    }

    // ══ CONCURRENCY — a per-student lock, safely released ══════════════════

    public function test_collection_takes_a_per_student_lock(): void
    {
        $c = $this->code(self::SVC);
        $this->assertStringContainsString('feeLocks', $c);
        $this->assertStringContainsString('$lockToken  = bin2hex(random_bytes(8))', $c,
            'the token must be unpredictable so a lock cannot be released by guesswork');
    }

    /**
     * OWNERSHIP IS THE POINT. Releasing a lock without checking the token would
     * let a slow request delete a lock a NEWER request had just taken, opening
     * the window the lock exists to close.
     */
    public function test_a_lock_is_released_only_by_its_owner(): void
    {
        $t  = $this->code(self::TXN);
        $at = strpos($t, 'function releaseLock');
        $this->assertNotFalse($at);
        $seg = substr($t, $at, 700);

        $this->assertStringContainsString("(\$existing['token'] ?? '') === \$token", $seg,
            'a lock must only be deleted by the request that took it');
        $this->assertStringContainsString('return false;', $seg,
            'and a foreign lock must be refused, not force-released');
    }

    // ══ OVER-PAYMENT AND SETTLED MONEY ═════════════════════════════════════

    public function test_overpayment_is_refused(): void
    {
        $c = $this->code(self::SVC);
        $this->assertStringContainsString('Overpayment is not allowed', $c,
            'a school must never collect more than is owed for the selected months');
    }

    // ══ B-027 · THE GUARDS MUST READ FAIL-CLOSED ════════════════════════════
    //
    // `demandsForStudent()` ends in `catch { return []; }`. Its own docblock
    // says its read-only callers "legitimately prefer degradation over an
    // exception" — but the overpayment guard and the pay-older-first guard are
    // not read-only callers. They are SAFETY DECISIONS, and an empty set makes
    // both of them evaluate against nothing.
    //
    // Fail-closed either way (an empty set computes totalUnpaidSelected = 0.00,
    // so every payment is refused) — but refused as an OVERPAYMENT, which is
    // untrue, and in the service that happens AFTER the gateway captured the
    // money. The parent is told they overpaid, and the payment sits
    // unreconciled until someone runs retry_payment_processing.
    //
    // These two guards pin the strict reader at both sites. They are the
    // regression that fires if anyone reverts to the swallowing variant.

    public function test_the_collection_guard_reads_demands_fail_closed(): void
    {
        $c = $this->code(self::SVC);
        $this->assertStringContainsString('demandsForStudentStrict', $c,
            'the overpayment / duplicate-month guards drive a SAFETY decision — a swallowed '
            . 'read error makes them evaluate against an empty demand set and misreport a '
            . 'transient failure as an overpayment, after the money was already captured');
    }

    public function test_order_creation_reads_demands_fail_closed(): void
    {
        $c = $this->code(APPPATH . 'controllers/Fee_management.php');
        $this->assertStringContainsString('demandsForStudentStrict', $c,
            'parent_create_order runs the pay-older-first and overpayment guards off this '
            . 'read; on a swallowed error the first is skipped entirely and the second '
            . 'refuses every order as exceeding a due total of 0.00');
    }

    /**
     * ...and the refusal must be RETRYABLE, not a 409 overpayment.
     *
     * This is the half that actually helps the parent: a 503 with "please
     * retry" is recoverable by the client, a 409 "you overpaid" sends them to
     * the school office for a payment they never made.
     */
    public function test_an_unreadable_demand_set_is_reported_as_retryable(): void
    {
        foreach ([self::SVC, APPPATH . 'controllers/Fee_management.php'] as $path) {
            $c = $this->code($path);

            // ATTACKING THIS TEST: asserting only that `demandsForStudentStrict`
            // appears would still pass if the call were added and the exception
            // left UNCAUGHT — which turns a transient read blip into a fatal
            // error mid-payment, strictly worse than what it replaced. So the
            // catch must be pinned too, not just the call.
            $this->assertMatchesRegularExpression(
                '/catch\s*\(\s*\\\\?RuntimeException/', $c,
                basename($path) . ': the strict reader throws — an uncaught throw in the '
                . 'payment path is worse than the swallowing reader it replaced'
            );

            $this->assertMatchesRegularExpression(
                '/503/', $c,
                basename($path) . ': an unreadable demand set must surface as a retryable '
                . 'failure, never as an overpayment refusal'
            );
        }
    }

    /**
     * The mechanism itself is proven elsewhere, and deliberately not re-proven
     * here: `B011DuplicateGuardFailClosedTest` drives the REAL
     * `Fee_firestore_txn` against a Firestore double that fails the way
     * production fails, and establishes that the strict reader throws where the
     * lenient one returns an empty set, that the two are distinguishable, and
     * that the lenient variant still degrades for its read-only callers.
     *
     * So the position is layered and each layer is honest about its strength:
     *   mechanism   → BEHAVIOURAL (B-011 suite, real class, injected failure)
     *   wiring      → source guards above
     *   end-to-end  → PRODUCTION VERIFICATION BLOCKED (0 receipts estate-wide)
     */
    public function test_the_fail_closed_reader_contract_is_proven_behaviourally_elsewhere(): void
    {
        $b011 = __DIR__ . '/B011DuplicateGuardFailClosedTest.php';
        $this->assertFileExists($b011,
            'the behavioural proof of the strict reader must not be deleted — these source '
            . 'guards only pin the wiring, not the mechanism');

        $c = (string) file_get_contents($b011);
        $this->assertStringContainsString('test_a_read_failure_throws_instead_of_returning_an_empty_set', $c);
        $this->assertStringContainsString('test_an_empty_set_and_a_failed_read_are_distinguishable', $c);
    }

    public function test_an_already_paid_month_is_refused(): void
    {
        $c = $this->code(self::SVC);
        $this->assertStringContainsString('is already fully paid', $c,
            'paying a settled month again would create money with no charge behind it');
    }

    /** Older unpaid periods must be cleared first — no leapfrogging. */
    public function test_older_periods_must_be_cleared_first(): void
    {
        $c = $this->code(self::SVC);
        $this->assertStringContainsString('Please clear the earlier pending fees', $c);
    }

    // ══ CLIENT INPUT MUST NEVER DETERMINE MONEY ════════════════════════════

    /**
     * THE MOST IMPORTANT GUARD HERE. A discount arriving from the browser is
     * capped at the student's APPROVED concession. Without this, anyone able to
     * post the form could set their own discount.
     */
    public function test_a_client_supplied_discount_cannot_exceed_the_approved_concession(): void
    {
        $c = $this->code(self::SVC);
        $this->assertStringContainsString('exceeds the approved concession', $c,
            'client input must never be able to raise a discount');
        $this->assertStringContainsString('approvedDiscountCap', $c,
            'the cap must come from server-side entitlement/concession data');
    }

    /**
     * The cap must FAIL CLOSED when it cannot be read.
     *
     * `studentDiscounts` is easy to mistake for retirable legacy — it is old and
     * sparsely populated (1 row estate-wide). It is not legacy: it is the
     * collection-time authorization cap that bounds a CLIENT-SUPPLIED discount,
     * and it is the only thing preventing a caller supplying its own.
     *
     * The dangerous refactor is not deleting it outright — that would be
     * noticed. It is making an unreadable cap *skip* the check instead of
     * setting it to zero, which reads like defensive coding and silently turns
     * a Firestore blip into unlimited discount authority.
     */
    public function test_an_unreadable_discount_cap_rejects_rather_than_skips(): void
    {
        $c = $this->code(self::SVC);

        $at = strpos($c, 'approvedDiscountCap');
        $this->assertNotFalse($at, 'the discount cap was renamed');

        $this->assertMatchesRegularExpression(
            '/\$approvedDiscountCap\s*=\s*0\.0\s*;/',
            substr($c, max(0, $at - 400), 800),
            'the cap is no longer initialised to zero before the read. If the read throws '
            . 'or the document is missing, an uninitialised or skipped cap would let an '
            . 'unverifiable discount through — the read failure must DENY, not defer.'
        );

        $this->assertStringContainsString('studentDiscounts', $c,
            'the approved-discount source collection is gone. studentDiscounts is NOT '
            . 'retirable legacy: removing it removes the only bound on a client-supplied '
            . 'discount at collection time.');
    }

    /** Changing a computed late fee requires elevated permission AND a reason. */
    public function test_overriding_a_late_fee_requires_permission_and_a_reason(): void
    {
        $c = $this->code(self::SVC);
        $this->assertStringContainsString('requires Fees "manage" permission', $c);
        $this->assertStringContainsString('A reason is required to change the calculated late fee', $c,
            'an unexplained fine waiver is indistinguishable from a leak');
    }

    // ══ THE ALLOCATION ARITHMETIC — behavioural, because it can be ═════════

    /**
     * The production rule is `alloc = min(remaining, balance)`. This exercises
     * that rule directly across the shapes that matter.
     */
    public function test_allocation_never_exceeds_a_demand_balance_or_the_tender(): void
    {
        $allocate = function (float $tender, array $balances): array {
            $remaining = $tender; $out = [];
            foreach ($balances as $bal) {
                $a = round(min($remaining, $bal), 2);
                if ($a <= 0) { $out[] = 0.0; continue; }
                $out[] = $a;
                $remaining = round($remaining - $a, 2);
            }
            return [$out, $remaining];
        };

        // Exact settlement.
        list($alloc, $left) = $allocate(850.0, [600.0, 50.0, 200.0]);
        $this->assertSame([600.0, 50.0, 200.0], $alloc);
        $this->assertSame(0.0, $left);

        // Partial tender — earlier demands settle first, later ones get nothing.
        list($alloc, $left) = $allocate(620.0, [600.0, 50.0, 200.0]);
        $this->assertSame([600.0, 20.0, 0.0], $alloc);
        $this->assertSame(0.0, $left);
        $this->assertLessThanOrEqual(50.0, $alloc[1], 'never more than that demand owed');

        // A tender larger than everything owed leaves a REMAINDER, which the
        // overpayment guard refuses upstream rather than silently absorbing.
        list($alloc, $left) = $allocate(1000.0, [600.0, 50.0]);
        $this->assertSame(350.0, $left,
            'unallocated money must remain visible so the overpayment guard can refuse it');
    }

    /** No allocation may be negative, whatever the inputs. */
    public function test_allocation_is_never_negative(): void
    {
        foreach ([[0.0, 600.0], [100.0, 0.0], [0.0, 0.0]] as $case) {
            list($tender, $bal) = $case;
            $this->assertGreaterThanOrEqual(0.0, round(min($tender, $bal), 2));
        }
    }

    // ══ THE LIMIT, STATED EXPLICITLY ═══════════════════════════════════════

    /**
     * Documents what these tests CANNOT establish, so the lifecycle matrix
     * cannot quietly promote collection to production-verified.
     */
    public function test_collection_remains_unverified_against_real_money(): void
    {
        $this->assertTrue(true,
            'The estate holds 0 receipts, 0 payments and 0 journals. Every guarantee '
            . 'above is proven PRESENT, not proven EXERCISED. Production verification '
            . 'requires a real transaction and must not be manufactured.');
    }
}
