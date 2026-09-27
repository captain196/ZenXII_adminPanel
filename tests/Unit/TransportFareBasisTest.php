<?php
/**
 * Transport fare basis, resume proration, and service-concession periods.
 * Three financial defects found on 2026-08-30, all proven at runtime first.
 *
 * WHY SOURCE ASSERTIONS AND NOT ARITHMETIC HERE. The money was measured
 * end to end against the emulator through the real endpoints — route form,
 * admission, approval, suspend, resume, route change — and the exact figures
 * are recorded per test below. These tests exist to stop the code drifting
 * back, not to re-derive the arithmetic in a mock.
 */

use PHPUnit\Framework\TestCase;

final class TransportFareBasisTest extends TestCase
{
    private const SERVICE = __DIR__ . '/../../application/libraries/Transport_service.php';
    private const PRICING = __DIR__ . '/../../application/libraries/Service_entitlement_pricing.php';
    private const READER  = __DIR__ . '/../../application/libraries/Fee_entitlement_reader.php';
    private const VIEW    = __DIR__ . '/../../application/views/transport/index.php';
    private const LIFECYCLE = __DIR__ . '/../../application/libraries/Fee_lifecycle.php';

    private function src(string $f): string { return (string) file_get_contents($f); }

    // ══ TRN-FARE-BASIS-1 ════════════════════════════════════════════════════

    /**
     * A route fare is per MONTH; a module demand is per SESSION. Measured on a
     * Rs 1,000/month route in a fresh tenant:
     *     before  listGross    1,000   gross   667   (a ~12x under-charge)
     *     after   listGross   12,000   gross 8,000   (1,000 x 8 months left)
     */
    public function test_every_fee_write_goes_through_the_one_period_priced_writer(): void
    {
        $s = $this->src(self::SERVICE);

        // SUPERSEDES the old "six call sites multiply by 12" assertion. Fixed
        // multiplication was only ever right for a student who rode the whole
        // session; the amount is now the sum of the months actually served.
        $this->assertStringContainsString('private function _writeTransportDemand', $s,
            'every transport fee write must go through ONE writer, so the due date, the '
            . 'frequency tag and the billable periods cannot drift between six call sites');

        // Exactly ONE — the writer's own. Six sites each assembling their own
        // payload is how they drifted apart: one forgot the due date, another
        // kept a stale fare basis, and the rollover site was missed entirely.
        $this->assertSame(1, substr_count($s, '$this->ci->feeLifecycle->createModuleFee('),
            'no call site may build its own fee payload any more');

        $this->assertStringContainsString("'billablePeriods' => \$periods", $s,
            'the writer must pass the MONTHS, not a lump sum, so pricing can resolve a '
            . 'concession against each month it is actually effective for');

        // …and the other end must still READ them. Sending periods that
        // createModuleFee ignores would silently fall back to single-period
        // pricing — the demand would look right and the concession would be
        // resolved against the session-start month again, which is exactly
        // TRN-CONC-PERIOD-1 returning with no visible symptom.
        $fl = $this->src(self::LIFECYCLE);
        $this->assertStringContainsString("\$feeData['billablePeriods']", $fl,
            'createModuleFee must consume the billable periods it is given');
        $this->assertStringContainsString('_priceServiceFeeByPeriods(', $fl,
            'and must price across them rather than across one filing month');
    }
    /**
     * THE TRAP THAT MAKES THIS FIX SUBTLE. _computeAssignmentFee()'s return is
     * ALSO written to the assignment as `monthly_fee` and rendered to staff as
     * "Rs {fee}" per month. Converting inside it would make the UI claim a
     * Rs 12,000 monthly fare. Runtime check after a route change to a
     * Rs 1,500/month route: demand listGross 18,000, assignment monthly_fee 1,500.
     */
    public function test_the_conversion_never_leaks_into_the_displayed_monthly_fare(): void
    {
        $s   = $this->src(self::SERVICE);
        $at  = strpos($s, 'private function _computeAssignmentFee');
        $this->assertNotFalse($at);
        $body = substr($s, $at, 1200);

        $this->assertStringNotContainsString('SESSION_MONTHS', $body,
            'the monthly figure must stay monthly everywhere a human reads it. It becomes a '
            . 'session amount at exactly one boundary: the demand.');
        $this->assertStringNotContainsString('_sessionFareFromMonthly', $body);
    }

    // ══ TRN-RESUME-PRORATE-1 ════════════════════════════════════════════════

    /**
     * SUPERSEDES the two "must prorate" tests.
     *
     * proRateFees($studentId, $joinDate) answers "you JOINED in month X, pay the
     * rest of the year". Every transport STOP event fed the STOP date into that
     * JOIN parameter, so a student who left in February was billed February and
     * March — the months they would not be travelling — and nothing for the six
     * months they had. Measured: 8,000 -> 2,000 where 6,000 was owed.
     *
     * The fix is not to prorate more carefully; it is not to prorate at all.
     * Months are derived from the recorded service, so any proration pass would
     * re-derive the amount from a join date and reintroduce the inversion.
     * Nothing else in the codebase prorates a Transport demand — verified — so
     * there is no other writer to clobber the result.
     */
    public function test_transport_never_prorates_from_a_join_date_again(): void
    {
        $s = $this->src(self::SERVICE);

        $this->assertSame(0, substr_count($s, '$this->ci->feeLifecycle->proRateFees('),
            'a single surviving proration call re-derives the amount from a join date and '
            . 'silently restores TRN-PRORATE-INVERT-1');

        foreach (['assignStudent' => 'start', 'suspendAssignment' => 'stop',
                  'resumeAssignment' => 'start', 'unassignStudent' => 'stop'] as $method => $event) {
            $at = strpos($s, 'public function ' . $method);
            $this->assertNotFalse($at, "{$method} no longer exists");
            $next = strpos($s, "\n    public function ", $at + 10);
            $body = substr($s, $at, ($next ?: strlen($s)) - $at);

            $this->assertStringContainsString("_syncServicePeriods(", $body,
                "{$method} must record what happened to the service and rebill from that "
                . 'record, not transform whatever the demand currently says');
            $this->assertStringContainsString("'{$event}'", $body,
                "{$method} must record the '{$event}' event");
        }
    }
    // == TRN-DUE-1 ===========================================================

    /**
     * A transport demand carried no due date. DUE-2 fixed the plumbing —
     * createModuleFee writes whatever `dueDate` a caller passes — but Transport
     * passed none. Confirmed on a live demand: every field a fee reader filters
     * on matched the tuition rows exactly, except dueDate, which was ''.
     *
     * DUE-2 recorded the consequences PROVEN ON A REAL DEVICE for the sibling
     * Student Charge case: the Parent app infers a due date from the month
     * label, and createModuleFee files every module charge under the SESSION
     * START (April) — so a charge raised in August displayed as months overdue
     * on the day it was created. The late-fee engine cannot fine a dateless
     * demand (fineFor() returns null) and the reminder service cannot chase it.
     *
     * After the fix, measured: dueDate 2026-09-29 (charge + 30 days) with
     * overdueFrom derived from it.
     */
    public function test_every_transport_demand_carries_a_due_date(): void
    {
        $s = $this->src(self::SERVICE);

        // Stamped in ONE place now. Six call sites each having to remember it
        // was the fragility; the single writer cannot forget.
        $at = strpos($s, 'private function _writeTransportDemand');
        $this->assertNotFalse($at, '_writeTransportDemand no longer exists');

        $this->assertStringContainsString("'dueDate'         => \$this->_moduleFeeDueDate()",
            substr($s, $at, 1600),
            'the single writer must stamp a due date. A dateless demand tells the family they '
            . 'are already late, cannot be fined, and is never chased — it sits unpaid and '
            . 'unchased forever.');

        // Scoped to the helper. An unrelated '+30 days' elsewhere in this file
        // let a mutation that inverted THIS one survive a whole-file search.
        $at = strpos($s, 'private function _moduleFeeDueDate');
        $this->assertNotFalse($at, '_moduleFeeDueDate no longer exists');

        $this->assertStringContainsString("strtotime('+30 days')", substr($s, $at, 220),
            'thirty days from the charge, matching Student_charge_service::DEFAULT_DUE_DAYS — '
            . 'the convention already used for the other per-student one-off. Deliberately NOT '
            . 'the session start: a date in the past is the very bug being fixed.');
    }
    // == TRN-CONC-PERIOD-1 — OPEN, NOT FIXED =================================

    /**
     * A transport concession granted after the session's first month never
     * reaches the fare. Reproduced at runtime — same student, same route, same
     * ACTIVE 50% `category:transport` concession, only the date differing:
     *     effectiveFrom 2026-04-01 -> 50% applied, net 4,000
     *     effectiveFrom 2026-08-30 -> nothing applied, net 8,000
     * The operator sees "Entitlement recorded."; the parent is billed in full.
     *
     * WHY THIS TEST PINS THE DEFECT INSTEAD OF THE FIX. A fix was written and
     * REVERTED: widening the match to "effective anywhere in the session" made
     * a FUTURE-dated concession apply immediately and kept a concession
     * scheduled to end in September discounting a November period. Both are
     * caught by ServiceEntitlementPricingUatTest, which encodes those windows
     * deliberately. Swapping a silent under-charge for a silent over-charge is
     * not a fix, and the mandate forbids weakening a financial invariant.
     *
     * What this asserts, therefore, is that the CAUSE is still where the
     * investigation found it — so whoever takes the pricing decision starts
     * from a true map rather than re-deriving it.
     */
    public function test_the_service_concession_period_defect_is_still_where_it_was_found(): void
    {
        $s = $this->src(self::PRICING);
        $r = $this->src(self::READER);

        $this->assertStringContainsString('TRN-CONC-PERIOD-1', $s,
            'the open defect must stay documented at the site that exhibits it — it is silent '
            . 'in the UI, silent in the logs, and costs a family real money');

        $this->assertStringContainsString('$lastOfMonth', $r,
            'the reader still compares effectiveFrom against the period MONTH. That is correct '
            . 'for monthly tuition and wrong only for a session-scoped service demand, which is '
            . 'why the fix does not belong in this shared reader.');

        // BOTH sites — createModuleFee, which stamps the demand, and
        // cancelSessionDemands, which must derive the SAME key to find it
        // again at rollover. A count, not a match: with a single-match
        // assertion, mutating either site still matched the other.
        $this->assertSame(2, preg_match_all(
            '/\$periodKey\s*=\s*sprintf\(\x27%04d-%02d\x27,\s*\$year,\s*self::ACADEMIC_START_MONTH\)/',
            $this->src(self::LIFECYCLE)),
            'a module demand is still stamped with the SESSION START month whatever month the '
            . 'service begins. That mismatch — a session bucket read as a real month — is the '
            . 'whole defect; if this line ever changes, re-open the investigation.');
    }

    /**
     * I3 — a blanket scope:'all' concession must never discount a service fee.
     * Re-verified at runtime: transport discount 0 against an ACTIVE blanket
     * 50%, while a correctly scoped `category:transport` 50% does reduce
     * 8,000 to 4,000.
     */
    public function test_invariant_I3_is_still_decided_inside_the_reader(): void
    {
        $r  = $this->src(self::READER);
        $at = strpos($r, 'public function appliesTo');
        $this->assertNotFalse($at);
        $body = substr($r, $at, 1400);

        $this->assertMatchesRegularExpression('/\$scope === \x27all\x27/', $body);
        $this->assertStringContainsString('self::SERVICE_CATEGORIES, true)', $body,
            'I3 must stay where scope is decided: a blanket concession may never reach a '
            . 'service category, and the comparison must stay strict');
    }
    // ══ TRN-FARE-OVERRIDE-1 ═════════════════════════════════════════════════

    /**
     * The approval modal offered a "Monthly Fee" input placeholdered "Leave
     * blank to use the route fee". _computeAssignmentFee() never read it.
     * Proven: an override of 5,000 on a Rs 1,000 route billed the 1,000 fare.
     * Removed rather than implemented — a bare fare override is a discount with
     * no reason, no approver and no allocation, which invariant I2 exists to
     * prevent. fee_category and a scoped concession are the audited levers.
     */
    public function test_the_silently_ignored_fare_override_is_gone(): void
    {
        $v = $this->src(self::VIEW);

        $this->assertStringNotContainsString('id="raFee"', $v,
            'a financial input must never look editable while doing nothing');
        $this->assertStringNotContainsString("monthly_fee: $('#raFee').val()", $v,
            'the approval payload must not carry a fare the server ignores');
    }
}
