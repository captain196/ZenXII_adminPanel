<?php

use PHPUnit\Framework\TestCase;

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  CF-1 — THE CARRY-FORWARD BREAKDOWN WAS ALWAYS EMPTY
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Year rollover writes a `feeCarryForward` record per student: what they still
 * owe from the closing session. It carries a total and a per-period breakdown.
 *
 * The breakdown was built like this:
 *
 *     $pk = $d['period_key'] ?? '';
 *     if ($pk !== '') $byStudent[$sid]['details'][$pk] = [
 *         'amount'   => $bal,
 *         'fee_head' => $d['fee_head'] ?? '',
 *     ];
 *
 * **No writer emits snake_case onto a demand.** `_buildDemandDoc()` and
 * `updateDemand()` both ACCEPT either shape from a caller and emit camelCase
 * only — that normalisation is the whole point of the write boundary. So `$pk`
 * was always `''`, the `if` never fired, and every `feeCarryForward` record in
 * the estate carries `unpaidDetails: {}`.
 *
 * ── WHY NOBODY NOTICED ────────────────────────────────────────────────────
 * `totalDues` sums `balance`, which IS camelCase and was read correctly. So the
 * money was always right and only the itemisation explaining it was lost. The
 * number a school checks agreed with reality; the breakdown behind it was
 * simply absent, which reads as "this student had no itemised dues" rather than
 * as a bug.
 *
 * ── IT IS CONSUMED ON ALL THREE SURFACES ──────────────────────────────────
 * `Fee_firestore_sync` forwards `unpaidDetails`, and BOTH apps declare it on
 * their `FeeCarryForwardDoc`. So this is a cross-platform contract that has
 * never carried data.
 *
 * ── THE TELL ──────────────────────────────────────────────────────────────
 * The same loop reads `$d['studentId'] ?? $d['student_id']` — dual-shape, four
 * lines above. The block was inconsistent with itself, which is what makes this
 * a slip rather than a deliberate choice.
 */
class CarryForwardBreakdownTest extends TestCase
{
    private static string $fees = '';
    private static string $txn  = '';

    public static function setUpBeforeClass(): void
    {
        self::$fees = (string) file_get_contents(APPPATH . 'controllers/Fees.php');
        self::$txn  = (string) file_get_contents(APPPATH . 'libraries/Fee_firestore_txn.php');
    }

    /** The carry-forward accumulation loop, bounded by its own markers. */
    private function loop(): string
    {
        $at = strpos(self::$fees, '2. Carry-forward from unpaid demands');
        $this->assertNotFalse($at, 'the carry-forward block was renamed');

        $end = strpos(self::$fees, "'feeCarryForward'", $at);
        $this->assertNotFalse($end, 'the feeCarryForward write moved out of this block');

        return substr(self::$fees, $at, $end - $at);
    }

    // ══ THE PREMISE — SNAKE NEVER REACHES A DEMAND ══════════════════════════

    /**
     * The whole defect rests on this. If a writer DID emit snake_case, the old
     * code would have worked and this file would be wrong.
     */
    public function test_the_write_boundary_emits_camel_case_only(): void
    {
        $at   = strpos(self::$txn, 'private function _buildDemandDoc');
        $this->assertNotFalse($at);
        $end  = strpos(self::$txn, "\n    private function ", $at + 30);
        $body = substr(self::$txn, $at, ($end !== false ? $end - $at : 9000));

        // It ACCEPTS both shapes as input...
        $this->assertStringContainsString("\$pick(\$data, ['periodKey', 'period_key'])", $body,
            'the builder no longer accepts the snake alias as input');

        // ...and EMITS only camel.
        $this->assertMatchesRegularExpression("/'periodKey'\s*=>/", $body);
        $this->assertDoesNotMatchRegularExpression("/'period_key'\s*=>\s*\\\$/", $body,
            'a demand writer now emits snake_case period_key. If demands really can carry the '
            . 'snake shape, re-derive this file — the premise of CF-1 is that they cannot.');
        $this->assertDoesNotMatchRegularExpression("/'fee_head'\s*=>\s*\\\$/", $body);
    }

    // ══ CF-1 ═══════════════════════════════════════════════════════════════

    /**
     * WOULD HAVE FAILED BEFORE: the loop read `period_key` only, so the
     * breakdown was never populated for any student in any tenant.
     */
    public function test_the_breakdown_reads_the_shape_demands_actually_carry(): void
    {
        $loop = $this->loop();

        $this->assertStringContainsString("\$d['periodKey'] ?? \$d['period_key']", $loop,
            'the carry-forward breakdown still keys on snake_case period_key, which no demand '
            . 'writer emits. Every feeCarryForward record would carry unpaidDetails: {} — the '
            . 'total is right and the itemisation explaining it is absent.');

        $this->assertStringContainsString("\$d['feeHead'] ?? \$d['fee_head']", $loop,
            'the fee head in the breakdown still reads snake_case only, so every carried-over '
            . 'line would be labelled with an empty head');
    }

    /**
     * The total must keep reading `balance` — camelCase, and always correct.
     * This is the half that was never broken, and the fix must not disturb it:
     * the money is the part a school reconciles against.
     */
    public function test_the_total_still_sums_the_server_balance(): void
    {
        $loop = $this->loop();

        $this->assertStringContainsString("\$bal = (float) (\$d['balance'] ?? 0);", $loop,
            'the carry-forward total no longer sums the demand balance. That figure was always '
            . 'correct — only the breakdown was broken — so a change here is a regression, not '
            . 'part of this fix.');
        $this->assertStringContainsString("['dues'] += \$bal", $loop);
    }

    /**
     * Only genuine dues carry forward. A zero or negative balance must not
     * create a record, or a fully-settled family starts the new session with a
     * carry-forward document asserting they owe nothing.
     */
    public function test_only_a_positive_balance_carries_forward(): void
    {
        $loop = $this->loop();

        $this->assertStringContainsString("if (\$sid === '' || \$bal <= 0) continue;", $loop,
            'the positive-balance guard was lost, so settled students would gain empty '
            . 'carry-forward records');
    }

    /**
     * The dual-shape read for `studentId` was already there and is what makes
     * the snake-only reads beside it look like a slip. Pinned so a later
     * "consistency" pass does not remove the dual read instead of adding it.
     */
    public function test_the_student_id_dual_read_is_preserved(): void
    {
        $loop = $this->loop();

        $this->assertStringContainsString("\$d['studentId'] ?? \$d['student_id']", $loop);
    }

    // ══ THE CONSUMERS ══════════════════════════════════════════════════════

    /**
     * `unpaidDetails` is a real cross-platform contract, not an internal field.
     * Recorded so the blast radius of this fix is visible: three surfaces begin
     * receiving data in a field that has always been empty, and none of them
     * can have depended on it being empty — but all of them now render it.
     */
    public function test_the_field_is_forwarded_to_the_apps(): void
    {
        $sync = (string) file_get_contents(APPPATH . 'libraries/Fee_firestore_sync.php');

        $this->assertStringContainsString('unpaidDetails', $sync,
            'Fee_firestore_sync no longer forwards the breakdown — confirm the apps still '
            . 'receive it before assuming this fix reaches them');
    }
}
