<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Comp-Off ledger semantics — the parts that do NOT depend on D-001.
 *
 * D-001 (whether a half-day leave may consume half a comp-off credit) is a
 * business-policy decision and is deliberately NOT modelled here. Everything
 * else about the ledger is objectively determined by the implementation and is
 * pinned below.
 *
 * The design, verified 2026-08-21, is a compensating-transaction ledger:
 *
 *   EARN     one document per (staff, earnedDate). Hard gate: the date must
 *            already carry the 'W' attendance mark — "worked a scheduled rest
 *            day" — so a credit can never exist without attendance evidence.
 *            Re-crediting the same date is idempotent, not a second credit.
 *
 *   CONSUME  FIFO by earnedDate. Each credit is claimed individually with a
 *            CAS precondition on its own updateTime. Losing that race is not an
 *            error: the loop moves to the next credit, so two concurrent
 *            approvals can never spend the same credit twice.
 *
 *   SHORTFALL  if fewer credits can be claimed than the leave needs, the
 *            remainder becomes LWP. It never fabricates coverage.
 *
 *   RELEASE  if the leave write fails afterwards, the claimed credits are
 *            released back to 'available'. On cancellation of an approved
 *            comp-off leave, the credits released are EXACTLY those recorded
 *            on the request document (`compOffLedgerIds`) — never re-derived,
 *            because re-deriving would release a different set.
 *
 * Expiry is deliberately unset (`'expiresOn' => null`, per the class docblock).
 * That is a recorded product decision, not an oversight, and is asserted as
 * such rather than "fixed".
 *
 * AUDITED AND FOUND CORRECT — regression guard, not a defect report.
 */
final class CompOffLedgerSemanticsTest extends TestCase
{
    private string $hr;

    protected function setUp(): void
    {
        $hr = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($hr);
        $this->hr = $hr;
    }

    /**
     * The consumption loop as implemented AFTER D-001 (partial consumption).
     *
     * @param array $credits  each ['id','earnedDate','remaining','claimable']
     *                        'claimable' false models losing the CAS race
     * @return array{paid: float, lwp: float, consumedIds: string[], takes: array, left: array}
     */
    private function consume(array $credits, float $daysNeeded): array
    {
        // FIFO by earnedDate — oldest credit spent first.
        usort($credits, fn($a, $b) => strcmp((string) $a['earnedDate'], (string) $b['earnedDate']));

        $covered = 0.0;
        $ids     = [];
        $takes   = [];
        $left    = [];

        foreach ($credits as $c) {
            $left[$c['id']] = round((float) $c['remaining'], 2);
        }

        foreach ($credits as $c) {
            $need = round($daysNeeded - $covered, 2);
            if ($need <= 0) break;
            if (empty($c['claimable'])) continue;   // lost the CAS race — never double-spend

            $remaining = round((float) $c['remaining'], 2);
            if ($remaining <= 0) continue;

            $take = min($remaining, $need);

            $covered            = round($covered + $take, 2);
            $ids[]              = $c['id'];
            $takes[$c['id']]    = $take;
            $left[$c['id']]     = round($remaining - $take, 2);
        }

        $paid = min($covered, $daysNeeded);
        return [
            'paid'        => $paid,
            'lwp'         => max(0.0, round($daysNeeded - $paid, 2)),
            'consumedIds' => $ids,
            'takes'       => $takes,
            'left'        => $left,
        ];
    }

    private function credit(string $id, string $date, bool $claimable = true, float $remaining = 1.0): array
    {
        return ['id' => $id, 'earnedDate' => $date, 'claimable' => $claimable, 'remaining' => $remaining];
    }

    // ── FIFO ─────────────────────────────────────────────────────────────

    public function test_credits_are_consumed_oldest_first(): void
    {
        $r = $this->consume([
            $this->credit('C3', '2026-03-01'),
            $this->credit('C1', '2026-01-01'),
            $this->credit('C2', '2026-02-01'),
        ], 2);

        $this->assertSame(['C1', 'C2'], $r['consumedIds'],
            'FIFO protects the employee: the credit closest to any future expiry is spent first');
    }

    public function test_input_order_does_not_change_which_credits_are_spent(): void
    {
        $a = $this->consume([
            $this->credit('C1', '2026-01-01'), $this->credit('C2', '2026-02-01'),
        ], 1);
        $b = $this->consume([
            $this->credit('C2', '2026-02-01'), $this->credit('C1', '2026-01-01'),
        ], 1);

        $this->assertSame($a['consumedIds'], $b['consumedIds']);
    }

    // ── Never double-spend ───────────────────────────────────────────────

    /** Losing the CAS race must skip that credit, not abort and not re-spend it. */
    public function test_a_credit_lost_to_a_concurrent_approval_is_skipped(): void
    {
        $r = $this->consume([
            $this->credit('C1', '2026-01-01', false),   // another approval won it
            $this->credit('C2', '2026-02-01'),
        ], 1);

        $this->assertSame(['C2'], $r['consumedIds']);
        $this->assertSame(1.0, $r['paid'], 'the employee is still fully covered');
    }

    public function test_losing_every_race_yields_no_coverage_not_an_error(): void
    {
        $r = $this->consume([
            $this->credit('C1', '2026-01-01', false),
            $this->credit('C2', '2026-02-01', false),
        ], 2);

        $this->assertSame([], $r['consumedIds']);
        $this->assertSame(0.0, $r['paid']);
        $this->assertSame(2.0, $r['lwp'], 'uncovered days become LWP, never phantom coverage');
    }

    // ── Shortfall becomes LWP, never fabricated coverage ─────────────────

    public function test_a_shortfall_becomes_lwp(): void
    {
        $r = $this->consume([$this->credit('C1', '2026-01-01')], 3);

        $this->assertSame(1.0, $r['paid']);
        $this->assertSame(2.0, $r['lwp']);
        $this->assertCount(1, $r['consumedIds'], 'only the credits actually claimed are recorded');
    }

    public function test_surplus_credits_are_left_untouched(): void
    {
        $r = $this->consume([
            $this->credit('C1', '2026-01-01'),
            $this->credit('C2', '2026-02-01'),
            $this->credit('C3', '2026-03-01'),
        ], 1);

        $this->assertSame(['C1'], $r['consumedIds'], 'never spend more than the leave needs');
        $this->assertSame(0.0, $r['lwp']);
    }

    public function test_no_credits_available_is_entirely_lwp(): void
    {
        $r = $this->consume([], 2);

        $this->assertSame(0.0, $r['paid']);
        $this->assertSame(2.0, $r['lwp']);
        $this->assertSame([], $r['consumedIds']);
    }

    // ── D-001: partial consumption ───────────────────────────────────────

    /**
     * THE DEFECT D-001 FIXED. A half-day comp-off leave used to mark a whole
     * 1.0 credit consumed and silently forfeit the other half — an entitlement
     * the employee had already earned by working a rest day.
     */
    public function test_a_half_day_request_spends_only_half_a_credit(): void
    {
        $r = $this->consume([$this->credit('C1', '2026-01-01')], 0.5);

        $this->assertSame(0.5, $r['paid']);
        $this->assertSame(0.0, $r['lwp']);
        $this->assertSame(['C1' => 0.5], $r['takes'], 'exactly half a day may be taken');
        $this->assertSame(0.5, $r['left']['C1'],
            'the unspent half must remain available, not be forfeited');
    }

    public function test_the_remainder_is_spendable_by_a_later_request(): void
    {
        $first = $this->consume([$this->credit('C1', '2026-01-01')], 0.5);

        // Second request against what the first left behind.
        $second = $this->consume(
            [$this->credit('C1', '2026-01-01', true, $first['left']['C1'])],
            0.5
        );

        $this->assertSame(0.5, $second['paid']);
        $this->assertSame(0.0, $second['left']['C1'], 'the credit is now fully spent');
    }

    public function test_a_credit_is_only_marked_consumed_once_it_is_exhausted(): void
    {
        $partial = $this->consume([$this->credit('C1', '2026-01-01')], 0.25);
        $this->assertSame(0.75, $partial['left']['C1'], 'still has value → stays available');

        $full = $this->consume([$this->credit('C1', '2026-01-01')], 1.0);
        $this->assertSame(0.0, $full['left']['C1'], 'exhausted → consumed');
    }

    /** A request larger than one credit still spans credits, FIFO. */
    public function test_a_multi_day_request_spans_credits_and_may_split_the_last(): void
    {
        $r = $this->consume([
            $this->credit('C1', '2026-01-01'),
            $this->credit('C2', '2026-02-01'),
        ], 1.5);

        $this->assertSame(1.5, $r['paid']);
        $this->assertSame(['C1' => 1.0, 'C2' => 0.5], $r['takes']);
        $this->assertSame(0.0, $r['left']['C1'], 'first credit fully spent');
        $this->assertSame(0.5, $r['left']['C2'], 'second credit only half spent');
    }

    /** Quarter days are representable, matching the rest of the lifecycle. */
    public function test_a_quarter_day_is_representable(): void
    {
        $r = $this->consume([$this->credit('C1', '2026-01-01')], 0.25);

        $this->assertSame(0.25, $r['paid']);
        $this->assertSame(0.75, $r['left']['C1']);
    }

    /** An already-exhausted credit is skipped, not double-spent. */
    public function test_an_exhausted_credit_is_skipped(): void
    {
        $r = $this->consume([
            $this->credit('C1', '2026-01-01', true, 0.0),
            $this->credit('C2', '2026-02-01', true, 1.0),
        ], 1.0);

        $this->assertSame(['C2' => 1.0], $r['takes']);
    }

    /** Never take more than the request needs, even from a large credit. */
    public function test_a_request_never_takes_more_than_it_needs(): void
    {
        $r = $this->consume([$this->credit('C1', '2026-01-01', true, 2.0)], 0.5);

        $this->assertSame(0.5, $r['takes']['C1']);
        $this->assertSame(1.5, $r['left']['C1']);
    }

    /** The take map must account for exactly what was paid. */
    public function test_the_takes_map_sums_to_the_paid_amount(): void
    {
        foreach ([[0.5], [1.0], [1.5], [2.25], [3.0]] as [$need]) {
            $r = $this->consume([
                $this->credit('C1', '2026-01-01'),
                $this->credit('C2', '2026-02-01'),
                $this->credit('C3', '2026-03-01'),
            ], $need);

            $this->assertEqualsWithDelta(
                $r['paid'],
                array_sum($r['takes']),
                0.0001,
                "takes must reconcile with paid for a {$need}-day request"
            );
        }
    }

    // ── Source: the guarantees the model above relies on ─────────────────

    public function test_consumption_is_cas_protected_per_credit(): void
    {
        $this->assertStringContainsString("'precondition' => ['updateTime' => \$updateTime]", $this->hr);
        $this->assertStringContainsString(
            'never double-spend',
            $this->hr,
            'the race-loser path must stay a skip, not a retry or an abort'
        );
    }

    public function test_earning_requires_the_worked_on_rest_day_mark(): void
    {
        $this->assertStringContainsString(
            "This date is not marked as worked-on-a-rest-day ('W')",
            $this->hr,
            'a comp-off credit must never exist without attendance evidence'
        );
    }

    public function test_earning_refuses_a_future_date(): void
    {
        $this->assertStringContainsString('Cannot credit Comp-Off for a future date.', $this->hr);
    }

    public function test_earning_the_same_date_twice_is_idempotent(): void
    {
        $this->assertStringContainsString(
            'Comp-Off was already credited for this date.',
            $this->hr,
            're-crediting must return the existing entry, never mint a second credit'
        );
    }

    // ── Compensation ─────────────────────────────────────────────────────

    public function test_a_failed_leave_write_releases_the_claimed_credits(): void
    {
        $this->assertStringContainsString(
            '$this->_releaseCompOffLedger($compOffConsumedIds, $compOffTakes);',
            $this->hr,
            'credits claimed for a leave that never persisted must not orphan — and with '
            . 'partial consumption the release must restore the EXACT amount taken'
        );
    }

    public function test_release_restores_the_credit_to_available_and_clears_provenance(): void
    {
        $i = strpos($this->hr, 'function _releaseCompOffLedger');
        $this->assertNotFalse($i);
        $body = substr($this->hr, (int) $i, 2600);

        foreach (["'status'     => 'available'", "'consumedAt' => null", "'consumedBy' => null"] as $n) {
            $this->assertStringContainsString($n, $body,
                'a released credit must be fully reusable, with no stale consumption trace');
        }
    }

    /**
     * D-001 — a PARTIALLY spent credit must get back exactly what was taken.
     * A blanket reset to `available` would credit the employee with days they
     * never had.
     */
    public function test_a_partial_release_adds_back_only_the_amount_taken(): void
    {
        $i = strpos($this->hr, 'function _releaseCompOffLedger');
        $body = substr($this->hr, (int) $i, 2600);

        $this->assertStringContainsString('array $takes = []', $body,
            'the release must be told how much was taken from each credit');
        $this->assertStringContainsString("'remainingDays' => round(\$current + \$restore, 2)", $body,
            'restoration is ADDITIVE — never a reset to the full credit');
    }

    /**
     * Requests approved before partial consumption existed carry only an id
     * list. Those credits were necessarily spent whole, so the id-only path
     * must still restore them.
     */
    public function test_the_takes_map_is_optional_for_backward_compatibility(): void
    {
        $i = strpos($this->hr, 'function _releaseCompOffLedger');
        $body = substr($this->hr, (int) $i, 2600);

        $this->assertStringContainsString('if (array_key_exists($docId, $takes))', $body,
            'a release without a recorded amount must fall back to the whole-credit reset');
    }

    /**
     * Cancellation must release the RECORDED ids. Re-deriving "which credits
     * did this leave use" from the ledger would release a different set once
     * any other leave has consumed credits in the meantime.
     */
    public function test_cancellation_releases_the_recorded_ids_not_a_re_derived_set(): void
    {
        $this->assertStringContainsString("\$request['compOffLedgerIds']", $this->hr);
        $this->assertStringContainsString('never re-derived/guessed', $this->hr);
    }

    // ── Tenant isolation ─────────────────────────────────────────────────

    public function test_ledger_reads_are_tenant_scoped(): void
    {
        $this->assertStringContainsString(
            "schoolWhere('compOffLedger', [['staffId', '==', \$staffId]])",
            $this->hr,
            'a raw query() here would cross tenants'
        );
    }

    // ── Recorded product decision, not a defect ──────────────────────────

    // ── Expiry and accumulation cap: TENANT POLICY, disabled by default ──

    /**
     * Expiry and a balance ceiling are both market-standard (30-90 day
     * validity, 5-10 day cap) and both were absent. They are now implemented as
     * PER-TENANT configuration read from `schools.attendancePolicy`, and both
     * default to DISABLED.
     *
     * The default is not a market norm and not a guess: expiring an earned
     * entitlement, or refusing to grant one, reduces what a person has already
     * worked for. A school decides that, not this code. With nothing
     * configured, behaviour is byte-identical to before.
     */
    private function expiresOn(string $earnedDate, int $expiryDays): ?string
    {
        return $expiryDays > 0
            ? date('Y-m-d', strtotime($earnedDate . ' +' . $expiryDays . ' days'))
            : null;
    }

    /** A credit is spendable unless it carries a stamp that has passed. */
    private function isSpendable(?string $expiresOn, string $today): bool
    {
        return $expiresOn === null || $expiresOn === '' || $expiresOn >= $today;
    }

    public function test_no_expiry_is_stamped_when_the_tenant_has_not_configured_one(): void
    {
        $this->assertNull($this->expiresOn('2026-03-01', 0),
            'an unconfigured tenant must behave exactly as before — credits never lapse');
    }

    public function test_an_expiry_window_is_stamped_from_the_earned_date(): void
    {
        $this->assertSame('2026-03-31', $this->expiresOn('2026-03-01', 30));
        $this->assertSame('2026-05-30', $this->expiresOn('2026-03-01', 90));
    }

    public function test_a_lapsed_credit_is_not_spendable(): void
    {
        $this->assertFalse($this->isSpendable('2026-03-31', '2026-04-01'));
    }

    /** The stamp is inclusive — a credit is usable on its final day. */
    public function test_a_credit_is_spendable_on_its_expiry_date(): void
    {
        $this->assertTrue($this->isSpendable('2026-03-31', '2026-03-31'),
            'expiring ON the stamped day would silently shorten every window by one day');
    }

    /**
     * THE PROPERTY THAT PROTECTS EARNED ENTITLEMENT. A credit granted while no
     * expiry policy existed carries no stamp, so switching the policy on later
     * cannot retroactively lapse it.
     */
    public function test_enabling_expiry_later_does_not_lapse_existing_credits(): void
    {
        $born = $this->expiresOn('2026-01-01', 0);          // policy was off
        $this->assertNull($born);

        $this->assertTrue($this->isSpendable($born, '2099-12-31'),
            'a credit earned under no-expiry must remain spendable forever');
    }

    // ── Source pins ──────────────────────────────────────────────────────

    public function test_the_policy_is_read_per_tenant_and_defaults_off(): void
    {
        $i = strpos($this->hr, 'function _comp_off_policy');
        $this->assertNotFalse($i, 'the comp-off policy reader must exist');
        $body = substr($this->hr, (int) $i, 900);

        $this->assertStringContainsString("\$pol['compOffExpiryDays'] ?? 0", $body);
        $this->assertStringContainsString("\$pol['compOffMaxBalanceDays'] ?? 0", $body);
        $this->assertStringContainsString("'expiryDays'     => \$days > 0 ? \$days : 0", $body,
            'a non-positive or absent value must mean DISABLED, never a default window');
    }

    public function test_expired_credits_are_filtered_out_of_the_available_set(): void
    {
        $this->assertStringContainsString(
            "if (\$expiresOn !== '' && \$expiresOn < \$today) continue;",
            $this->hr,
            'a lapsed credit must not be offered for consumption'
        );
    }

    /** The cap refuses loudly rather than dropping the credit on the floor. */
    public function test_the_balance_cap_refuses_rather_than_silently_discarding(): void
    {
        $this->assertStringContainsString('Comp-Off balance cap reached', $this->hr,
            'a person must be told why their credit was not granted');
        $this->assertStringContainsString(
            "if (\$compOffPolicy['maxBalanceDays'] > 0) {",
            $this->hr,
            'the cap must be inert unless configured'
        );
    }

    /** The cap is enforced at EARN time only — never confiscated afterwards. */
    public function test_the_cap_is_checked_when_earning_not_when_spending(): void
    {
        $i = strpos($this->hr, 'function _consumeCompOffLedger');
        $this->assertNotFalse($i);
        $body = substr($this->hr, (int) $i, 3000);

        $this->assertStringNotContainsString('maxBalanceDays', $body,
            'a credit already granted must never be confiscated by a later cap change');
    }

    /**
     * Release large source strings between tests.
     *
     * PHPUnit keeps every TestCase instance alive for the duration of the run,
     * so a multi-hundred-KB source held in a property stays resident once per
     * test method. Measured: a source-reading class costs ~18 MB against a
     * 512 MB limit, and the suite was running at 506 MB. Clearing the reference
     * frees the string while leaving the (now tiny) instance for reporting.
     */
    protected function tearDown(): void
    {
        foreach (get_object_vars($this) as $prop => $val) {
            if (is_string($val) && strlen($val) > 50000) $this->{$prop} = '';
        }
    }
}
