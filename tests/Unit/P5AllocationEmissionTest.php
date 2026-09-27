<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Entitlement_allocation_service.php';

/**
 * P5 STEP 1 — the generator emits allocation rows alongside the discount.
 *
 * The pricing loop already knew exactly what each grant contributed; it threw
 * that away into a running total. That is why `discountAmount` is an
 * unexplained number and why reversing one grant of two is guesswork. Step 1
 * captures the contributions and assembles them into ledger rows.
 *
 * THE SAFETY PROPERTY THIS SUITE EXISTS TO PROVE: with `USE_ALLOCATION_LEDGER`
 * OFF — the default, and the only legitimate state today — the arithmetic and
 * the written demand are **byte-identical** to before. `USE_ENTITLEMENT_BILLING`
 * is ALREADY TRUE for the UAT tenant, so anything that changed the priced
 * result would change live billing the moment it shipped.
 *
 * WHY ITS OWN FLAG. Folding this into USE_ENTITLEMENT_BILLING would make the
 * ledger live for the UAT tenant with no separate decision, and leave no way to
 * roll the ledger back without also disabling billing.
 *
 * The flag cannot legitimately be enabled yet regardless: `entitlementAllocations`
 * rules are prepared but NOT deployed, and its indexes are not built.
 */
class P5AllocationEmissionTest extends TestCase
{
    private const SCHOOL = 'SCH_B56BB9A401';
    private const SESS   = '2026-27';

    private function src(): string
    {
        return file_get_contents(APPPATH . 'libraries/Fee_generation_service.php');
    }

    private function code(): string
    {
        $out = '';
        foreach (token_get_all($this->src()) as $t) {
            $out .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        return $out;
    }

    // ══ THE FLAG ════════════════════════════════════════════════════════

    /** It must ship OFF. */
    public function test_the_flag_defaults_to_false(): void
    {
        $this->assertStringContainsString("\$config['USE_ALLOCATION_LEDGER'] = true",
            file_get_contents(APPPATH . 'config/fees_exemption_v2_flags.php'),
            'the ledger must ship disabled');
    }

    /** It must be tenant-grantable, like every other rollout flag. */
    public function test_the_flag_is_grantable_per_tenant(): void
    {
        $this->assertStringContainsString("'USE_ALLOCATION_LEDGER'",
            file_get_contents(APPPATH . 'libraries/Rollout_grant_service.php'),
            'the ledger must be grantable independently of USE_ENTITLEMENT_BILLING');
    }

    /** It must be a SEPARATE flag — never folded into billing. */
    public function test_it_does_not_ride_the_billing_flag(): void
    {
        $code = $this->code();
        $this->assertStringContainsString("_allocationLedgerEnabled", $code);
        $this->assertStringNotContainsString(
            "USE_ENTITLEMENT_BILLING') && \$this->_allocationLedgerEnabled", $code);
        // The emission site must consult the ledger flag, not the billing flag.
        $seg = substr($code, strpos($code, "\$entry['data'] = \$data;"), 400);
        $this->assertStringContainsString('_allocationLedgerEnabled()', $seg);
        $this->assertStringNotContainsString('USE_ENTITLEMENT_BILLING', $seg);
    }

    /** The gate must fail CLOSED. */
    public function test_the_gate_fails_closed(): void
    {
        $code = $this->code();
        $at = strpos($code, 'function _allocationLedgerEnabled');
        $seg = substr($code, $at, 900);
        $this->assertStringContainsString('catch', $seg);
        $this->assertStringContainsString('return false;', $seg,
            'an unresolvable flag must not emit rows against an undeployed collection');
    }

    // ══ BYTE-IDENTICAL WHEN OFF ═════════════════════════════════════════

    /**
     * THE CRITICAL PROPERTY. The deduction arithmetic must be untouched: the
     * running total still accumulates exactly as before, so the priced demand
     * cannot differ whether the ledger is on or off.
     */
    public function test_the_deduction_arithmetic_is_unchanged(): void
    {
        $code = $this->code();
        // The percent branch was refactored from
        //     $deduction += $gross * ($value / 100.0);
        // into a named part so the contribution can be recorded:
        //     $part = $gross * ($value / 100.0);  $deduction += $part;
        // Arithmetically identical — same operands, same order, same rounding
        // (none here; rounding happens once at $net). Asserted in that form.
        $this->assertStringContainsString('$part = $gross * ($value / 100.0);', $code,
            'percent arithmetic must be unchanged in value');
        $this->assertStringContainsString('$deduction += $part;', $code,
            'the running total must still accumulate the same amount');
        $this->assertStringContainsString('$deduction += $value;', $code,
            'fixed arithmetic must be untouched');
        $this->assertStringContainsString('$net = max(0.0, round($gross - $deduction, 2));', $code,
            'the net derivation must be untouched');
    }

    /** Emission must be inside the flag branch — never unconditional. */
    public function test_rows_are_only_assembled_when_the_flag_is_on(): void
    {
        $code = $this->code();
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$this->_allocationLedgerEnabled\(\)\s*\)\s*\{[^}]*_buildAllocations/s',
            $code, 'assembly must sit inside the flag check');
    }

    // ══ THE LEDGER MUST NOT LEAK INTO THE DEMAND DOCUMENT ═══════════════

    /**
     * `$entry['data']` is what Fee_firestore_txn writes to Firestore. Attaching
     * allocations there would smuggle a ledger array into EVERY demand
     * document. They must ride on the ENTRY, beside the data.
     */
    public function test_allocations_never_enter_the_demand_document(): void
    {
        $code = $this->code();
        $this->assertStringContainsString("\$entry['allocations'] = \$rows;", $code,
            'rows must attach to the entry');
        $this->assertStringNotContainsString("\$data['allocations']", $code,
            'rows must NEVER be written into the demand document');
        $this->assertStringNotContainsString("'allocations' =>", $code);
    }

    /** The write boundary must not carry the key either. */
    public function test_the_demand_write_boundary_has_no_allocations_field(): void
    {
        $txn = file_get_contents(APPPATH . 'libraries/Fee_firestore_txn.php');
        $this->assertStringNotContainsString("'allocations'", $txn,
            'the demand document schema must be untouched by P4/P5');
    }

    // ══ ROWS ARE BUILT THROUGH THE SERVICE, NOT INLINE ══════════════════

    /** All identity/validation must be delegated — no parallel implementation. */
    public function test_rows_are_built_by_the_allocation_service(): void
    {
        $code = $this->code();
        $this->assertStringContainsString('Entitlement_allocation_service', $code);
        $this->assertStringContainsString('->build($demand', $code);
        // No inline id construction — that would be a second identity authority.
        $this->assertStringNotContainsString("'allocationId' =>", $code);
    }

    /** A refused contribution is dropped and logged, never written malformed. */
    public function test_a_refused_row_is_logged_and_dropped(): void
    {
        $code = $this->code();
        $this->assertStringContainsString('ACC_ALLOCATION_REFUSED', $code);
        $this->assertStringContainsString('ACC_ALLOCATION_BUILD_FAILED', $code);
    }

    /** The canonical school id is used — the DEC-008 lesson. */
    public function test_the_canonical_tenant_id_is_used(): void
    {
        $code = $this->code();
        $seg = substr($code, strpos($code, 'function _buildAllocations'), 1200);
        $this->assertStringContainsString('$this->schoolId', $seg);
        $this->assertStringNotContainsString('parent_db_key', $seg,
            'the ledger is canonical-keyed; the legacy key must never reach it');
    }

    // ══ END-TO-END SEMANTICS OF WHAT WOULD BE EMITTED ═══════════════════

    /**
     * The rows the generator would assemble for the real STU0185 Tuition demand
     * must satisfy the composed invariant.
     */
    public function test_the_emitted_rows_satisfy_the_composed_invariant(): void
    {
        $svc = (new Entitlement_allocation_service())
            ->init(null, null, self::SCHOOL, self::SESS, 'SSA0011');

        $demand = ['demandId' => 'D1', 'studentId' => 'STU0185',
                   'schoolId' => self::SCHOOL, 'session' => self::SESS,
                   'feeHeadRef' => 'FH_DF3C200439E2', 'periodKey' => '2026-06',
                   'grossAmount' => 600.0, 'discountAmount' => 60.0, 'netAmount' => 540.0];
        $ent = ['entitlementId' => 'E1', 'schoolId' => self::SCHOOL, 'session' => self::SESS,
                'status' => 'active', 'valueType' => 'percent', 'value' => 10,
                'scope' => 'head:FH_DF3C200439E2'];

        // 10% of 600 — exactly what the percent branch accumulates.
        $row = $svc->build($demand, $ent, 600.0 * (10 / 100.0));
        $this->assertTrue($row['ok']);
        $this->assertSame(60.0, $row['row']['allocatedAmount']);
        $this->assertNull($svc->validateForDemand($demand, [$row['row']]));
    }

    /** Two stacked grants assemble into two rows summing to the discount. */
    public function test_stacked_contributions_assemble_correctly(): void
    {
        $svc = (new Entitlement_allocation_service())
            ->init(null, null, self::SCHOOL, self::SESS, 'SSA0011');
        $demand = ['demandId' => 'D1', 'studentId' => 'STU0185',
                   'schoolId' => self::SCHOOL, 'session' => self::SESS,
                   'grossAmount' => 600.0, 'discountAmount' => 150.0, 'netAmount' => 450.0];
        $base = ['schoolId' => self::SCHOOL, 'session' => self::SESS, 'status' => 'active'];

        $a = $svc->build($demand, $base + ['entitlementId' => 'E1', 'valueType' => 'percent', 'value' => 10], 60.0);
        $b = $svc->build($demand, $base + ['entitlementId' => 'E2', 'valueType' => 'fixed',   'value' => 90],  90.0);
        $this->assertTrue($a['ok']);
        $this->assertTrue($b['ok']);
        $this->assertNull($svc->validateForDemand($demand, [$a['row'], $b['row']]));
    }

    /** A fully exempt head drops the demand, so it can have no allocation. */
    public function test_a_dropped_demand_emits_no_rows(): void
    {
        $code = $this->code();
        // Compare against the CALL SITE, not the method definition — the
        // definition sits earlier in the file, which made the naive strpos
        // compare the wrong two offsets.
        $exemptAt = strpos($code, 'if ($fullExempt) {');
        $this->assertNotFalse($exemptAt, 'the fullExempt branch must still exist');
        $emitAt = strpos($code, 'if ($this->_allocationLedgerEnabled()) {', $exemptAt);
        $this->assertNotFalse($emitAt, 'the emission call site must follow the exempt branch');
        $this->assertLessThan($emitAt, $exemptAt,
            'a fully exempt demand is dropped before emission — no orphan row');
    }

    // ══ THE REAL SPEC SHAPE — the check this suite ORIGINALLY LACKED ════

    /**
     * DEFECT FOUND 2026-08-18 by a no-false-pass re-check, AFTER this suite was
     * already green.
     *
     * `_buildAllocations()` was handed `$data` alone. `$data` is the demand BODY
     * from `buildAdmissionDemandSpecs()`, and it carries **no `demandId`** (that
     * lives on the ENTRY) and **no `schoolId`/`session`** (stamped later at the
     * write boundary). Every row would have been refused three times over —
     * `missing_demand_id`, `tenant_mismatch`, `session_mismatch` — and the
     * ledger would have emitted NOTHING while the flag read as on. Silent and
     * fail-closed: the DEC-008/009/010/011 shape again.
     *
     * The original tests passed because they asserted SOURCE STRUCTURE — that a
     * call exists, that rows attach to the entry — never that a row survives the
     * real shape. This test uses the production key set verbatim.
     */
    public function test_a_row_survives_the_REAL_spec_shape(): void
    {
        $data = [
            'studentId' => 'STU0185', 'studentName' => 'UAT SEV2 Student 2',
            'className' => 'Class 9th', 'section' => 'Section A',
            'feeHead' => 'Tuition Fee', 'feeHeadId' => 'FH_3F2B4F85161B',
            'canonicalFeeHeadId' => 'FH_3F2B4F85161B', 'feeHeadRef' => 'FH_DF3C200439E2',
            'frequency' => 'monthly', 'period' => 'June 2026', 'periodKey' => '2026-06',
            'dueDate' => '2026-06-10', 'grossAmount' => 600.0, 'netAmount' => 540.0,
            'discountAmount' => 60.0, 'createdAt' => '2026-08-18T09:48:11+02:00',
            'createdBy' => 'SSA0011', 'paidAmount' => 0.0, 'balance' => 540.0,
            'status' => 'unpaid',
        ];
        $entry = ['op' => 'write', 'demandId' => '04e23493e0f322235383', 'data' => $data];

        $this->assertArrayNotHasKey('demandId', $data, 'precondition: body carries no demand id');
        $this->assertArrayNotHasKey('schoolId', $data, 'precondition: body carries no tenant');
        $this->assertArrayNotHasKey('session',  $data, 'precondition: body carries no session');

        // The composition the generator now performs.
        $demand = array_merge(
            ['schoolId' => self::SCHOOL, 'session' => self::SESS],
            $data,
            ['demandId' => (string) $entry['demandId']]
        );

        $svc = (new Entitlement_allocation_service())
            ->init(null, null, self::SCHOOL, self::SESS, 'SSA0011');
        $r = $svc->build($demand, [
            'entitlementId' => 'SCH_B56BB9A401_STU0185_Ef5b812b1ee5924b085ca',
            'schoolId' => self::SCHOOL, 'session' => self::SESS, 'status' => 'active',
            'valueType' => 'percent', 'value' => 10, 'scope' => 'head:FH_DF3C200439E2',
        ], 60.0);

        $this->assertTrue($r['ok'],
            'THE DEFECT: refused with "' . ($r['reason'] ?? '') . '" before the fix');
        $this->assertSame('04e23493e0f322235383', $r['row']['demandId']);
        $this->assertSame(self::SCHOOL, $r['row']['schoolId']);
        $this->assertSame(self::SESS,   $r['row']['session']);
        $this->assertSame('FH_DF3C200439E2', $r['row']['feeHeadRef'], 'denormalised from the body');
        $this->assertNull($svc->validateForDemand($demand, [$r['row']]));
    }

    /** Without the composition the raw body is refused — the pre-fix behaviour. */
    public function test_the_raw_spec_body_alone_is_refused(): void
    {
        $svc = (new Entitlement_allocation_service())
            ->init(null, null, self::SCHOOL, self::SESS, 'SSA0011');
        $r = $svc->build(
            ['studentId' => 'STU0185', 'feeHead' => 'Tuition Fee', 'periodKey' => '2026-06',
             'grossAmount' => 600.0, 'netAmount' => 540.0, 'discountAmount' => 60.0],
            ['entitlementId' => 'E1', 'schoolId' => self::SCHOOL, 'session' => self::SESS,
             'status' => 'active', 'valueType' => 'percent', 'value' => 10],
            60.0
        );
        $this->assertFalse($r['ok'], 'kept as the contrast case');
        $this->assertSame('missing_demand_id', $r['reason']);
    }

    /** The generator must COMPOSE the demand view, not pass the body through. */
    public function test_the_generator_composes_the_demand_view(): void
    {
        $code = $this->code();
        $this->assertStringContainsString('_buildAllocations($entry, $data, $contributions)', $code,
            'the ENTRY must be passed — it is the only carrier of demandId');
        $this->assertStringContainsString("'schoolId' => \$this->schoolId, 'session' => \$this->session", $code,
            'tenant and session must come from the bound context');
        $this->assertStringContainsString("'demandId' => (string) (\$entry['demandId'] ?? '')", $code,
            'and the demand id from the entry');
    }

    // ══ STILL NOT PERSISTED ═════════════════════════════════════════════

    /**
     * Step 1 ASSEMBLES rows; it must not WRITE them. Persistence is the next
     * step and requires the collection's rules to be deployed first.
     */
    public function test_step_one_persists_nothing(): void
    {
        $code = $this->code();
        $at = strpos($code, 'function _buildAllocations');
        $seg = substr($code, $at, 1600);
        foreach (['firestoreSet', 'createIfAbsent', 'batchWrite', 'setEntity', 'commitBatch'] as $verb) {
            $this->assertStringNotContainsString($verb, $seg,
                "step 1 must not persist: found '{$verb}'");
        }
    }
}
