<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Entitlement_allocation_service.php';
require_once __DIR__ . '/../../application/libraries/Fee_firestore_txn.php';

/**
 * P4 — the entitlement allocation ledger.
 *
 * An allocation is one row: *entitlement E contributed X to demand D, computed
 * against basis B*. It supplies the "why" behind `discountAmount`, which today
 * is an unexplained number.
 *
 * THE COMPOSED INVARIANT this suite proves end to end:
 *      netAmount      == grossAmount − discountAmount      (P5 detector, live)
 *      discountAmount == Σ active allocatedAmount          (P4, here)
 *
 * THE BASIS DECISION, proven rather than asserted. Allocations are measured
 * against the demand's **grossAmount**, recorded on each row as `basisAmount`.
 * The tests below exercise the exact scenarios the alternatives fail:
 *   • net/balance as basis  → breaks on payment and on stacking (order-dependent)
 *   • listGrossAmount       → absent on ordinary demands; a conditional identity
 *   • a new chargeVersion field → unnecessary; `basisAmount` on the ROW gives the
 *     same staleness guarantee without touching the demand contract
 *
 * NOTHING IS WRITTEN. The service is pure: it builds and validates rows. No
 * collection exists, no flag enables it, no billing path calls it.
 */
class P4AllocationLedgerTest extends TestCase
{
    private const SCHOOL = 'SCH_B56BB9A401';
    private const OTHER  = 'SCH_218AAF5C23';
    private const SESS   = '2026-27';

    private function svc(string $school = self::SCHOOL, string $sess = self::SESS): Entitlement_allocation_service
    {
        return (new Entitlement_allocation_service())->init(null, null, $school, $sess, 'SSA0011');
    }

    /** The real STU0185 Tuition demand shape. */
    private function demand(array $over = []): array
    {
        return array_merge([
            'demandId' => '04e23493e0f322235383', 'studentId' => 'STU0185',
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'feeHead' => 'Tuition Fee', 'feeHeadRef' => 'FH_DF3C200439E2',
            'periodKey' => '2026-06',
            'grossAmount' => 600.0, 'discountAmount' => 60.0, 'netAmount' => 540.0,
            'balance' => 540.0, 'paidAmount' => 0.0, 'status' => 'unpaid',
        ], $over);
    }

    private function ent(array $over = []): array
    {
        return array_merge([
            'entitlementId' => 'SCH_B56BB9A401_STU0185_Ef5b812b1ee5924b085ca',
            'schoolId' => self::SCHOOL, 'studentId' => 'STU0185', 'session' => self::SESS,
            'kind' => 'concession', 'scope' => 'head:FH_DF3C200439E2',
            'valueType' => 'percent', 'value' => 10, 'status' => 'active',
        ], $over);
    }

    // ══ IDENTITY · DUPLICATE PREVENTION ═════════════════════════════════

    public function test_allocation_identity_is_deterministic(): void
    {
        $a = Entitlement_allocation_service::allocationId(self::SCHOOL, 'D1', 'E1');
        $b = Entitlement_allocation_service::allocationId(self::SCHOOL, 'D1', 'E1');
        $this->assertSame($a, $b, 'the same pair must always produce the same id');
        $this->assertSame('SCH_B56BB9A401_D1_E1', $a);
    }

    /**
     * Duplicate prevention is STRUCTURAL: a repeat allocation collides on the
     * document id, so create-if-absent refuses it. No dedupe check to forget.
     */
    public function test_a_repeat_allocation_collides_on_the_same_id(): void
    {
        $svc = $this->svc();
        $one = $svc->build($this->demand(), $this->ent(), 60.0)['row'];
        $two = $svc->build($this->demand(), $this->ent(), 60.0)['row'];
        $this->assertSame($one['allocationId'], $two['allocationId']);
    }

    /** Two DIFFERENT grants on one demand are two distinct rows — stacking works. */
    public function test_two_entitlements_on_one_demand_get_distinct_ids(): void
    {
        $svc = $this->svc();
        $a = $svc->build($this->demand(), $this->ent(), 60.0)['row'];
        $b = $svc->build($this->demand(), $this->ent(['entitlementId' => 'E_SECOND']), 30.0)['row'];
        $this->assertNotSame($a['allocationId'], $b['allocationId']);
    }

    /** The tenant is part of the id — `demandId` alone is not tenant-scoped. */
    public function test_the_same_demand_id_in_two_tenants_does_not_collide(): void
    {
        $this->assertNotSame(
            Entitlement_allocation_service::allocationId(self::SCHOOL, 'D1', 'E1'),
            Entitlement_allocation_service::allocationId(self::OTHER,  'D1', 'E1'));
    }

    // ══ THE BASIS DECISION ══════════════════════════════════════════════

    /** A discounted demand: the row records the GROSS it was computed against. */
    public function test_the_basis_is_the_gross_charge(): void
    {
        $r = $this->svc()->build($this->demand(), $this->ent(), 60.0);
        $this->assertTrue($r['ok']);
        $this->assertSame(600.0, $r['row']['basisAmount'], 'basis is gross, not net (540) or balance');
        $this->assertSame(60.0,  $r['row']['allocatedAmount']);
    }

    /**
     * PARTIALLY-PAID: an allocation describes the CHARGE, never the settlement.
     * Payment must not move the basis — which is exactly why net/balance was
     * rejected as the basis.
     */
    public function test_a_partial_payment_does_not_change_the_allocation(): void
    {
        $unpaid = $this->svc()->build($this->demand(), $this->ent(), 60.0)['row'];
        $paid   = $this->svc()->build(
            $this->demand(['paidAmount' => 200.0, 'balance' => 340.0, 'status' => 'partial']),
            $this->ent(), 60.0)['row'];
        $this->assertSame($unpaid['basisAmount'],     $paid['basisAmount']);
        $this->assertSame($unpaid['allocatedAmount'], $paid['allocatedAmount']);
        $this->assertSame($unpaid['allocationId'],    $paid['allocationId']);
    }

    /** FULLY-PAID: likewise unaffected. */
    public function test_a_fully_paid_demand_allocates_identically(): void
    {
        $r = $this->svc()->build(
            $this->demand(['paidAmount' => 540.0, 'balance' => 0.0, 'status' => 'paid']),
            $this->ent(), 60.0);
        $this->assertTrue($r['ok']);
        $this->assertSame(600.0, $r['row']['basisAmount']);
    }

    /**
     * PRORATED (post-DEC-012): gross is already scaled, so the basis is the
     * scaled gross and the allocation matches the scaled discount. No special
     * case, and no need to consult `listGrossAmount`.
     */
    public function test_a_prorated_demand_allocates_against_the_scaled_gross(): void
    {
        $d = $this->demand([
            'grossAmount' => 1200.0, 'discountAmount' => 120.0, 'netAmount' => 1080.0,
            'listGrossAmount' => 2400.0, 'listDiscountAmount' => 240.0,
            'prorated' => true, 'prorateFactor' => 0.5,
        ]);
        $r = $this->svc()->build($d, $this->ent(), 120.0);
        $this->assertSame(1200.0, $r['row']['basisAmount'],
            'the CURRENT charge, not the 2400 list anchor');
        $this->assertNull($this->svc()->validateForDemand($d, [$r['row']]));
    }

    /** UNDISCOUNTED: there is nothing to allocate, and a zero row is refused. */
    public function test_an_undiscounted_demand_produces_no_allocation(): void
    {
        $d = $this->demand(['grossAmount' => 200.0, 'discountAmount' => 0.0, 'netAmount' => 200.0]);
        $r = $this->svc()->build($d, $this->ent(), 0.0);
        $this->assertFalse($r['ok']);
        $this->assertSame('non_positive_amount', $r['reason']);
        $this->assertNull($this->svc()->validateForDemand($d, []), 'no rows, no discount: coherent');
    }

    // ══ TRANSPORT REPRICING · STALENESS ═════════════════════════════════

    /**
     * Transport re-prices a demand IN PLACE (legitimate, DEC-001). The old
     * allocation is then a statement about a charge that no longer exists — it
     * must be DETECTABLE, not silently trusted. This is what removes the need
     * for a separate `chargeVersion` field on the demand.
     */
    public function test_repricing_makes_an_allocation_detectably_stale(): void
    {
        $svc = $this->svc();
        $row = $svc->build($this->demand(), $this->ent(), 60.0)['row'];
        $this->assertFalse($svc->isStale($row, $this->demand()), 'fresh');

        $repriced = $this->demand(['grossAmount' => 900.0, 'discountAmount' => 90.0, 'netAmount' => 810.0]);
        $this->assertTrue($svc->isStale($row, $repriced),
            'a repriced charge must invalidate the old allocation, not silently keep it');
    }

    public function test_rounding_noise_does_not_make_an_allocation_stale(): void
    {
        $svc = $this->svc();
        $row = $svc->build($this->demand(), $this->ent(), 60.0)['row'];
        $this->assertFalse($svc->isStale($row, $this->demand(['grossAmount' => 600.004])));
    }

    // ══ THE COMPOSED INVARIANT ══════════════════════════════════════════

    /** Allocations must exactly explain the demand's discount. */
    public function test_the_projection_matches_the_demand_discount(): void
    {
        $d = $this->demand();
        $row = $this->svc()->build($d, $this->ent(), 60.0)['row'];
        $this->assertNull($this->svc()->validateForDemand($d, [$row]));
        $this->assertSame(60.0, Entitlement_allocation_service::projectedDiscount([$row]));
        $this->assertNull(Fee_firestore_txn::moneyIncoherence($d),
            'and the P5 half must hold at the same time');
    }

    /** STACKING: two grants sum to the demand's discount. */
    public function test_two_stacked_entitlements_sum_to_the_discount(): void
    {
        $svc = $this->svc();
        $d = $this->demand(['discountAmount' => 90.0, 'netAmount' => 510.0, 'balance' => 510.0]);
        $a = $svc->build($d, $this->ent(), 60.0)['row'];
        $b = $svc->build($d, $this->ent(['entitlementId' => 'E_SECOND']), 30.0)['row'];
        $this->assertNull($svc->validateForDemand($d, [$a, $b]));
        $this->assertSame(90.0, Entitlement_allocation_service::projectedDiscount([$a, $b]));
        $this->assertNull(Fee_firestore_txn::moneyIncoherence($d));
    }

    /** A missing allocation is caught — this is DEC-013's shape, ledger-side. */
    public function test_a_discount_with_no_allocation_is_caught(): void
    {
        $bad = $this->svc()->validateForDemand($this->demand(), []);
        $this->assertNotNull($bad);
        $this->assertSame('projection_mismatch', $bad['reason']);
        $this->assertSame(60.0, $bad['expected']);
        $this->assertSame(0.0,  $bad['actual']);
    }

    /** An allocation with no matching discount is equally wrong. */
    public function test_an_orphan_allocation_is_caught(): void
    {
        $d = $this->demand(['discountAmount' => 0.0, 'netAmount' => 600.0]);
        $row = $this->svc()->build($this->demand(), $this->ent(), 60.0)['row'];
        $bad = $this->svc()->validateForDemand($d, [$row]);
        $this->assertNotNull($bad);
        $this->assertSame('projection_mismatch', $bad['reason']);
    }

    // ══ OVER-ALLOCATION ═════════════════════════════════════════════════

    /** One grant may never exceed the charge. */
    public function test_a_single_allocation_cannot_exceed_the_basis(): void
    {
        $r = $this->svc()->build($this->demand(), $this->ent(), 700.0);
        $this->assertFalse($r['ok']);
        $this->assertSame('exceeds_basis', $r['reason']);
    }

    /** A full exemption exactly equal to the charge is legitimate. */
    public function test_a_full_exemption_equal_to_the_charge_is_allowed(): void
    {
        $r = $this->svc()->build($this->demand(['discountAmount' => 600.0, 'netAmount' => 0.0]),
            $this->ent(['valueType' => 'fullExempt']), 600.0);
        $this->assertTrue($r['ok']);
        $this->assertSame(600.0, $r['row']['allocatedAmount']);
    }

    /** Two grants may not TOGETHER exceed the charge. */
    public function test_stacked_allocations_cannot_exceed_the_charge(): void
    {
        $svc = $this->svc();
        $d = $this->demand(['discountAmount' => 700.0, 'netAmount' => 0.0]);
        $a = $svc->build($this->demand(), $this->ent(), 400.0)['row'];
        $b = $svc->build($this->demand(), $this->ent(['entitlementId' => 'E2']), 300.0)['row'];
        $bad = $svc->validateForDemand($d, [$a, $b]);
        $this->assertNotNull($bad);
        $this->assertSame('over_allocated', $bad['reason'],
            'over-allocation must be reported ahead of the projection mismatch');
    }

    // ══ REVERSAL ════════════════════════════════════════════════════════

    /** Reversal is a status change with provenance — never a delete. */
    public function test_reversal_marks_the_row_and_records_who_and_why(): void
    {
        $svc = $this->svc();
        $patch = $svc->buildReversal(
            $svc->build($this->demand(), $this->ent(), 60.0)['row'], 'grant discontinued');
        $this->assertSame('reversed', $patch['status']);
        $this->assertSame('SSA0011', $patch['reversedBy']);
        $this->assertSame('grant discontinued', $patch['reversalReason']);
        $this->assertNotEmpty($patch['reversedAt']);
    }

    /** A reversed row stops counting toward the projection. */
    public function test_a_reversed_allocation_leaves_the_projection(): void
    {
        $svc = $this->svc();
        $a = $svc->build($this->demand(), $this->ent(), 60.0)['row'];
        $b = $svc->build($this->demand(), $this->ent(['entitlementId' => 'E2']), 30.0)['row'];
        $b['status'] = 'reversed';
        $this->assertSame(60.0, Entitlement_allocation_service::projectedDiscount([$a, $b]));
        $this->assertNull($svc->validateForDemand($this->demand(), [$a, $b]),
            'after reversal the remaining 60 must match the demand discount of 60');
    }

    /** Reversing everything must leave a demand with no discount coherent. */
    public function test_reversing_all_allocations_matches_a_zero_discount(): void
    {
        $svc = $this->svc();
        $a = $svc->build($this->demand(), $this->ent(), 60.0)['row'];
        $a['status'] = 'reversed';
        $d = $this->demand(['discountAmount' => 0.0, 'netAmount' => 600.0]);
        $this->assertNull($svc->validateForDemand($d, [$a]));
    }

    // ══ AUTHORITY, TENANT AND SESSION ISOLATION ═════════════════════════

    /** A non-active grant may not allocate — money without authority. */
    public function test_a_non_active_entitlement_cannot_allocate(): void
    {
        foreach (['pending', 'rejected', 'discontinued', ''] as $st) {
            $r = $this->svc()->build($this->demand(), $this->ent(['status' => $st]), 60.0);
            $this->assertFalse($r['ok'], "status '{$st}' must not allocate");
            $this->assertSame('entitlement_not_active', $r['reason']);
        }
    }

    /** A foreign-tenant demand or entitlement must be refused. */
    public function test_cross_tenant_allocation_is_refused(): void
    {
        $svc = $this->svc();
        $this->assertSame('tenant_mismatch',
            $svc->build($this->demand(['schoolId' => self::OTHER]), $this->ent(), 60.0)['reason']);
        $this->assertSame('tenant_mismatch',
            $svc->build($this->demand(), $this->ent(['schoolId' => self::OTHER]), 60.0)['reason']);
        $this->assertSame('tenant_mismatch',
            $this->svc(self::OTHER)->build($this->demand(), $this->ent(), 60.0)['reason']);
    }

    /** A demand from another session must be refused. */
    public function test_cross_session_allocation_is_refused(): void
    {
        $svc = $this->svc();
        $this->assertSame('session_mismatch',
            $svc->build($this->demand(['session' => '2025-26']), $this->ent(), 60.0)['reason']);
        $this->assertSame('session_mismatch',
            $svc->build($this->demand(), $this->ent(['session' => '2025-26']), 60.0)['reason']);
    }

    /** A session-less (all-sessions) grant is allowed — the reader's own rule. */
    public function test_an_all_sessions_entitlement_may_allocate(): void
    {
        $this->assertTrue($this->svc()->build($this->demand(), $this->ent(['session' => null]), 60.0)['ok']);
    }

    // ══ AUDIT METADATA ══════════════════════════════════════════════════

    /** The row must be self-describing for audit and reporting. */
    public function test_the_row_carries_full_audit_metadata(): void
    {
        $row = $this->svc()->build($this->demand(), $this->ent(), 60.0)['row'];
        foreach (['allocationId', 'schoolId', 'session', 'studentId', 'demandId',
                  'entitlementId', 'feeHeadRef', 'periodKey', 'basisAmount',
                  'allocatedAmount', 'valueType', 'value', 'scope', 'status',
                  'createdAt', 'createdBy'] as $f) {
            $this->assertArrayHasKey($f, $row, "audit field '{$f}' missing");
        }
        $this->assertSame('SSA0011', $row['createdBy']);
        $this->assertSame('active',  $row['status']);
    }

    /**
     * The rule is SNAPSHOT on the row, so a later edit to the grant cannot
     * rewrite the history of what was actually charged.
     */
    public function test_the_applied_rule_is_snapshotted(): void
    {
        $row = $this->svc()->build($this->demand(), $this->ent(), 60.0)['row'];
        $this->assertSame('percent', $row['valueType']);
        $this->assertSame(10.0,      $row['value']);
        $this->assertSame('head:FH_DF3C200439E2', $row['scope']);
    }

    // ══ IT IS INERT ═════════════════════════════════════════════════════

    /**
     * BOUNDARY MOVED (2026-08-18), NOT DROPPED.
     *
     * This asserted NO caller at all, which was correct while P4 stood alone.
     * P5 step 1 now deliberately wires the ledger into `Fee_generation_service`
     * so the generator can ASSEMBLE rows — behind `USE_ALLOCATION_LEDGER`,
     * which ships false.
     *
     * The guard is kept in a precise form: exactly ONE caller, and it must be
     * the generator. Anything else reaching the ledger — a controller, the
     * legacy path, a report — would be scope running ahead of its gates.
     */
    public function test_only_the_generator_and_lifecycle_reference_the_ledger(): void
    {
        $callers = [];
        foreach (array_merge(glob(APPPATH . 'controllers/*.php'),
                             glob(APPPATH . 'libraries/*.php')) as $f) {
            if (basename($f) === 'Entitlement_allocation_service.php') continue;
            if (strpos(file_get_contents($f), 'Entitlement_allocation_service') !== false) {
                $callers[] = basename($f);
            }
        }
        sort($callers);
        // BOUNDARY MOVED AGAIN (2026-08-29), deliberately, by P5-BF:
        //   Fee_generation_service   — ASSEMBLES rows during pricing
        //   Fee_lifecycle            — PERSISTS them after the demand commit
        //   Ledger_backfill          — repairs HISTORICAL demands (CLI, gated)
        //   Ledger_backfill_planner  — decides what the repair should be (pure)
        //
        // The freeze caught the backfill on its first run, which is the whole
        // point of having it. It is widened here rather than deleted, because
        // the property it protects is NOT "how many files say this name" — it
        // is "how many places can WRITE this ledger". That is now asserted
        // directly by the companion test below, which is the stronger guard and
        // does not need loosening when a legitimate reader is added.
        //
        // The two new entries earn their place precisely because neither
        // writes: the planner is pure and returns decisions, and the controller
        // hands its rows to Entitlement_allocation_service::persist() exactly as
        // Fee_lifecycle does.
        $this->assertSame([
            'Fee_generation_service.php',
            'Fee_lifecycle.php',
            'Ledger_backfill.php',
            'Ledger_backfill_planner.php',
        ], $callers,
            'only the generator (assemble), lifecycle (persist) and the gated historical '
            . 'backfill may reference the ledger: ' . implode(', ', $callers));
    }

    /**
     * THE INVARIANT THE FILE LIST WAS ONLY APPROXIMATING.
     *
     * `Entitlement_allocation_service::persist()` must remain the ONLY place a
     * ledger row is written. A second writer — even a well-meaning one in a
     * migration script — would bypass its tenant check, its create-if-absent
     * semantics and its identity rules, and the ledger could then contain rows
     * no single authority vouches for.
     *
     * Asserted against behaviour (who calls a Firestore write on the ledger
     * collection) rather than against a filename list, so adding a legitimate
     * READER never requires weakening it.
     */
    public function test_only_the_allocation_service_writes_ledger_rows(): void
    {
        $writers = [];
        foreach (array_merge(glob(APPPATH . 'controllers/*.php'),
                             glob(APPPATH . 'libraries/*.php'),
                             glob(APPPATH . 'services/*.php')) as $f) {
            if (basename($f) === 'Entitlement_allocation_service.php') continue;
            $src = (string) file_get_contents($f);
            if (strpos($src, 'entitlementAllocations') === false) continue;

            // Does this file pair that collection with a write primitive?
            foreach (['firestoreSet', 'firestoreCreate', 'firestoreDelete',
                      'commitBatch', 'batchWrite', 'patchDocument'] as $w) {
                if (strpos($src, $w) !== false) { $writers[] = basename($f) . ':' . $w; break; }
            }
        }
        sort($writers);
        $this->assertSame([], $writers,
            'a second ledger writer exists: ' . implode(', ', $writers) . '. Every allocation '
            . 'row must be written by Entitlement_allocation_service::persist(), which is the '
            . 'only place tenant scope and create-if-absent semantics are enforced.');
    }

    /**
     * And the generator may only ASSEMBLE, never PERSIST. Writing rows is the
     * next step and requires the collection's rules to be deployed first — they
     * are prepared but NOT deployed.
     */
    public function test_no_allocation_is_persisted_anywhere(): void
    {
        $gen = file_get_contents(APPPATH . 'libraries/Fee_generation_service.php');
        $at  = strpos($gen, 'function _buildAllocations');
        $this->assertNotFalse($at);
        $seg = substr($gen, $at, 2000);
        foreach (['firestoreSet', 'createIfAbsent', 'batchWrite', 'commitBatch', 'setEntity'] as $verb) {
            $this->assertStringNotContainsString($verb, $seg,
                "no row may be written yet: found '{$verb}'");
        }
    }

    /** The service writes nothing — it builds and validates only. */
    /**
     * REWRITTEN 2026-08-19. This used to assert the service performed NO writes
     * at all, which was true while P4 stood alone. P5 step 2 gave it `persist()`,
     * so a blanket "no writes" claim would now be FALSE — and a test that
     * quietly still passes while asserting something untrue is worse than no
     * test. It passed only because `firestoreCreateStatus` was absent from the
     * forbidden list.
     *
     * The real invariant, and the one worth keeping: the service may only ever
     * CREATE, never overwrite or delete. Everything else stays forbidden.
     */
    public function test_the_service_may_only_create_never_overwrite_or_delete(): void
    {
        $src = file_get_contents(APPPATH . 'libraries/Entitlement_allocation_service.php');

        foreach (['firestoreSet', 'firestoreDelete', 'batchWrite', 'setEntity',
                  'removeEntity', 'updateDemand', 'firestoreUpdate'] as $verb) {
            $this->assertStringNotContainsString($verb, $src,
                "the ledger must never overwrite or delete: found '{$verb}'");
        }

        // Exactly one write verb, and it is create-if-not-exists.
        $this->assertStringContainsString('firestoreCreateStatus', $src,
            'persistence must use the race-safe create primitive');
        $this->assertSame(1, substr_count($src, '$this->firebase->firestoreCreateStatus'),
            'exactly ONE write site — more would be a second, divergent path');

        // And the pure query/validation half must stay pure.
        foreach (['build', 'validateForDemand', 'isStale', 'projectedDiscount'] as $m) {
            $this->assertStringContainsString('function ' . $m, $src);
        }
    }
}
