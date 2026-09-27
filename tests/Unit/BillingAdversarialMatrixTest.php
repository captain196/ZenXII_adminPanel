<?php

use PHPUnit\Framework\TestCase;

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  BILLING V2 — ADVERSARIAL MATRIX
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Attacks the generation and identity layer rather than the payment layer,
 * which `FeePaymentClaimTest`, `RefundLockConcurrencyTest`,
 * `ProcessedPaymentIdempotencyTest` and `WaiverPaymentRaceTest` already cover.
 *
 * Three of the cases below turn out to be structurally impossible, and those
 * are recorded as tests rather than as prose because each rests on a property
 * a future change could remove without noticing. The demand-id one in
 * particular rests on a dependency that is not written down anywhere near the
 * code that would break it.
 */
class BillingAdversarialMatrixTest extends TestCase
{
    private static string $lifecycle = '';
    private static string $gen       = '';

    public static function setUpBeforeClass(): void
    {
        self::$lifecycle = (string) file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php');
        self::$gen       = (string) file_get_contents(APPPATH . 'libraries/Fee_generation_service.php');
    }

    // ══════════════════════════════════════════════════════════════════════
    //  CONCURRENT GENERATION — CAN TWO RUNS DOUBLE-BILL A STUDENT?
    // ══════════════════════════════════════════════════════════════════════

    /**
     * NO — and the reason is that `_demandId()` is a pure function of
     * (studentId, periodKey, feeHeadId|name, instance). No timestamp, no
     * random, no counter. Two concurrent generations for one student compute
     * the SAME ids, and every write is `merge: true`, so the second converges
     * onto the first instead of creating a second charge.
     *
     * This is the property that makes the whole pipeline re-runnable —
     * promotion, reverse-promotion, rollover, reconcile and recalculation all
     * re-invoke generation against live demands.
     *
     * Pinned because introducing any entropy here — a timestamp for
     * uniqueness, a uniqid, a sequence — would convert every one of those
     * re-runs into a duplicate charge, and would look like a reasonable change
     * to someone who had not read this.
     */
    public function test_a_demand_id_carries_no_entropy_so_regeneration_cannot_duplicate(): void
    {
        $at   = strpos(self::$lifecycle, 'private function _demandId');
        $this->assertNotFalse($at, '_demandId was renamed');
        $end  = strpos(self::$lifecycle, "\n    private function ", $at + 30);
        $body = substr(self::$lifecycle, $at, ($end !== false ? $end - $at : 2000));

        foreach (['time()', 'uniqid', 'mt_rand', 'random_', 'microtime', 'date('] as $entropy) {
            $this->assertStringNotContainsString($entropy, $body,
                "_demandId() now uses {$entropy}. The id must be a pure function of its "
                . 'arguments: promotion, rollover, reconcile and recalculation all regenerate '
                . 'against live demands, and any entropy turns each of those re-runs into a '
                . 'DUPLICATE CHARGE rather than an idempotent no-op.');
        }

        $this->assertStringContainsString('return $inst === \'\' ? $id : "{$id}_{$inst}";', $body,
            'the id no longer ends in a deterministic return');
    }

    // ══════════════════════════════════════════════════════════════════════
    //  CROSS-TENANT COLLISION — CAN ONE TENANT'S DEMAND OVERWRITE ANOTHER'S?
    // ══════════════════════════════════════════════════════════════════════

    /**
     * The demand id is `DEM_{studentId}_{yyyymm}_{head}`. **It contains no
     * schoolId.** Two tenants holding a student with the same id would write
     * to the same document — one tenant's charge silently overwriting
     * another's, across the tenant boundary, with no error.
     *
     * That cannot happen today, and the reason is NOT in this file or in
     * `_demandId()`: student ids are minted by `Id_generator` from
     * `feeCounters/_sys_STU`, a counter document whose id carries **no school
     * scope**. It is a single global sequence, so `STU0012` exists in exactly
     * one tenant estate-wide.
     *
     * ── WHY THIS TEST EXISTS ──────────────────────────────────────────────
     * The global counter is recorded elsewhere as an identity decision with a
     * per-tenant migration "deferred to SaaS scale". It is more than that: it
     * is a **tenant-isolation dependency**. Sharding that counter per school —
     * the obvious scaling move, and one that looks purely internal — would
     * immediately make demand ids collide across tenants.
     *
     * Compare `Fee_firestore_txn::nextCounter()`, whose counter doc IS
     * school-scoped (`{$this->schoolId}_{$kind}`). The two counters follow
     * opposite conventions, which is exactly why someone could "fix" the
     * inconsistency in the dangerous direction.
     */
    public function test_the_student_counter_is_global_because_demand_ids_depend_on_it(): void
    {
        $gen = (string) file_get_contents(APPPATH . 'libraries/Id_generator.php');

        $this->assertStringContainsString("private const DOC_PREFIX   = '_sys_';", $gen,
            'the Id_generator counter document convention changed');

        $at   = strpos($gen, 'feeCounters/_sys_{prefix}');
        $this->assertNotFalse($at,
            'the counter document is no longer feeCounters/_sys_{prefix}. If it is now '
            . 'school-scoped, student ids are no longer globally unique — and demand ids, '
            . 'which contain no schoolId, would COLLIDE ACROSS TENANTS.');

        // The demand id genuinely has no tenant component — that is what makes
        // the property above load-bearing rather than merely tidy.
        $at   = strpos(self::$lifecycle, 'private function _demandId');
        $end  = strpos(self::$lifecycle, "\n    private function ", $at + 30);
        $body = substr(self::$lifecycle, $at, ($end !== false ? $end - $at : 2000));

        $this->assertStringNotContainsString('schoolId', $body,
            'the demand id now carries a schoolId. That is SAFER, not worse — but it changes '
            . 'every existing demand id in the estate, so it is a migration, and this test '
            . 'should be re-derived rather than deleted.');
    }

    // ══════════════════════════════════════════════════════════════════════
    //  REPEATED ENTITLEMENT APPLICATION
    // ══════════════════════════════════════════════════════════════════════

    /**
     * The same logical grant must not be counted twice.
     *
     * DEC-004 closed the reachable case: `_fingerprintId` hashes `scope` as a
     * raw string, so `head:FH_TUI` and `head:FH_ROWID` produced different
     * document ids for the same head, both matched a demand, and
     * `_applyDeductions()` SUMMED them. The fix REFUSES a head scope naming an
     * unregistered head — it does not translate between id spaces, which would
     * have created a second identity authority.
     */
    public function test_a_head_scope_must_name_a_registered_head(): void
    {
        $svc = (string) file_get_contents(APPPATH . 'libraries/Entitlement_service.php');

        $this->assertStringContainsString('_assertRegisteredHead', $svc,
            'the DEC-004 guard is gone. Without it one logical fee head can be discounted '
            . 'twice through two id formats, and nothing can tell the two grants apart.');

        // Anchor on the CALL, not on the string 'DEC-004' — that appears first
        // in the class docblock, so a fixed window from there reads prose
        // rather than the guard it claims to be testing.
        $at = strpos($svc, '$err = $this->_assertRegisteredHead(');
        $this->assertNotFalse($at, 'the DEC-004 guard is no longer invoked anywhere');

        $body = substr($svc, max(0, $at - 400), 500);
        $this->assertStringContainsString("strpos(\$scope, 'head:') === 0", $body,
            'the guard no longer keys on the head scope, so a head-scoped entitlement can be '
            . 'created against an unregistered head again');
    }

    /**
     * Stacking itself is legitimate and must NOT be refused — a sibling
     * discount and a scholarship are separate awards. What must not happen is
     * the total exceeding the charge in silence.
     *
     * WOULD HAVE FAILED BEFORE: the clamp floored the net at zero with no
     * signal, so a school granting an RTE/EWS exemption *and* a 20% sibling
     * discount had no way to see the 20% was never applied — and revoking it
     * would not change the bill, which reads as a broken revocation.
     */
    public function test_a_concession_total_beyond_the_charge_is_reported(): void
    {
        $at = strpos(self::$gen, 'private function _applyDeductions');
        $this->assertNotFalse($at);
        $end  = strpos(self::$gen, "\n    private function ", $at + 30);
        $body = substr(self::$gen, $at, ($end !== false ? $end - $at : 12000));

        $this->assertStringContainsString('if ($deduction > $gross + 0.005)', $body,
            'concessions exceeding the charge are clamped silently. The money is right — the '
            . 'family is billed zero — but the surplus grant is recorded nowhere, so it cannot '
            . 'be reconciled and revoking one of two overlapping grants appears to do nothing.');
        $this->assertStringContainsString('ACC_CONCESSION_EXCEEDS_CHARGE', $body);
    }

    /**
     * The clamp itself must stay. Reporting the excess must never be confused
     * with billing it: a negative net would invert the demand into a credit
     * and flow a negative charge into every downstream reader.
     */
    public function test_the_net_can_never_go_negative(): void
    {
        $at   = strpos(self::$gen, 'private function _applyDeductions');
        $end  = strpos(self::$gen, "\n    private function ", $at + 30);
        $body = substr(self::$gen, $at, ($end !== false ? $end - $at : 12000));

        $this->assertStringContainsString('$net = max(0.0, round($gross - $deduction, 2));', $body,
            'the zero floor was removed. Stacked concessions could then produce a NEGATIVE '
            . 'net, inverting a charge into a credit everywhere it is read.');
    }

    /**
     * `discountAmount` must remain `gross - net`, i.e. the CLAMPED figure.
     *
     * It is tempting to store the true granted total now that STACK-1 makes it
     * visible — and that would be wrong: every reader depends on
     * `gross - discount == net`, and storing the unclamped value breaks it on
     * exactly the demands that are hardest to reason about.
     */
    public function test_the_stored_discount_stays_consistent_with_the_net(): void
    {
        $at   = strpos(self::$gen, 'private function _applyDeductions');
        $end  = strpos(self::$gen, "\n    private function ", $at + 30);
        $body = substr(self::$gen, $at, ($end !== false ? $end - $at : 12000));

        $this->assertStringContainsString("\$data['discountAmount']    = round(\$gross - \$net, 2);", $body,
            'the stored discount is no longer derived from the net. If it now records the '
            . 'granted total instead, a demand whose concessions exceeded the charge breaks '
            . 'the gross - discount == net invariant every reader relies on.');
        $this->assertStringContainsString("\$data['concessionApplied'] = round(\$gross - \$net, 2);", $body);
    }

    /**
     * A full exemption short-circuits the loop, so it cannot be stacked onto
     * and cannot be diluted by a later rule. Pinned because the `break` is easy
     * to lose when adding a rule type.
     */
    public function test_a_full_exemption_short_circuits_the_rule_loop(): void
    {
        $at   = strpos(self::$gen, 'private function _applyDeductions');
        $end  = strpos(self::$gen, "\n    private function ", $at + 30);
        $body = substr(self::$gen, $at, ($end !== false ? $end - $at : 12000));

        $this->assertMatchesRegularExpression(
            "/if \(\\\$type === 'fullExempt'\) \{\s*\\\$fullExempt = true;\s*break;/",
            $body,
            'fullExempt no longer breaks out of the rule loop, so a later rule can run after '
            . 'a full exemption has already been decided');
    }

    // ══════════════════════════════════════════════════════════════════════
    //  UNKNOWN RULE TYPES
    // ══════════════════════════════════════════════════════════════════════

    /**
     * A value type this loop cannot apply must contribute NOTHING (bill the
     * full amount — the safe direction) and must be loud about it. Guessing a
     * discount would be the unsafe direction, and silence is what hid the
     * original `amountDue` hazard.
     */
    public function test_an_unsupported_value_type_bills_gross_and_says_so(): void
    {
        $at   = strpos(self::$gen, 'private function _applyDeductions');
        $end  = strpos(self::$gen, "\n    private function ", $at + 30);
        $body = substr(self::$gen, $at, ($end !== false ? $end - $at : 12000));

        $this->assertStringContainsString('ENTITLEMENT_VALUETYPE_UNSUPPORTED', $body,
            'an unapplicable entitlement type is silently ignored again — an approved '
            . 'concession billing full price with no error anywhere');
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SERVICE SCOPE — I3
    // ══════════════════════════════════════════════════════════════════════

    /**
     * A blanket `scope: 'all'` concession must never net against a service
     * head. Without this, one "all fees 50%" grant would halve a family's
     * transport, hostel and meals charges — real costs the school pays a
     * third party for, not tuition it can discount at will.
     */
    public function test_a_blanket_concession_cannot_discount_a_service_charge(): void
    {
        $reader = (string) file_get_contents(APPPATH . 'libraries/Fee_entitlement_reader.php');

        $this->assertStringContainsString(
            "private const SERVICE_CATEGORIES = ['transport', 'hostel', 'meals'];",
            $reader,
            'the service-category list changed — confirm I3 still covers every service head');

        $at   = strpos($reader, 'public function appliesTo');
        $body = substr($reader, $at, 900);
        $this->assertMatchesRegularExpression(
            '/if \(\$scope === \'all\'\) \{.*?SERVICE_CATEGORIES, true\)\) \{\s*return false;/s',
            $body,
            'invariant I3 is gone: a blanket concession can now discount transport, hostel and '
            . 'meals — costs the school pays out to a third party');
    }

    /**
     * An unrecognised scope must never apply. Fail closed: billing gross is
     * recoverable, silently discounting on a malformed scope is not.
     */
    public function test_an_unrecognised_scope_never_applies(): void
    {
        $reader = (string) file_get_contents(APPPATH . 'libraries/Fee_entitlement_reader.php');
        $at     = strpos($reader, 'public function appliesTo');
        $body   = substr($reader, $at, 3000);

        $this->assertStringContainsString('return false;   // unrecognised scope → never applies (fail closed)', $body,
            'a malformed scope no longer fails closed');
    }

    /** An entitlement with no effective-from date must not apply either. */
    public function test_an_entitlement_without_an_effective_date_never_applies(): void
    {
        $reader = (string) file_get_contents(APPPATH . 'libraries/Fee_entitlement_reader.php');
        $at     = strpos($reader, 'public function appliesTo');
        $body   = substr($reader, $at, 3500);

        $this->assertStringContainsString("if (\$effFrom === '') return false;", $body,
            'an entitlement with no effectiveFrom now applies to every period, including ones '
            . 'that closed before it was granted');
    }
}
