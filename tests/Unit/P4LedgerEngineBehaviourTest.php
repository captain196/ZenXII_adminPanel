<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Entitlement_allocation_service.php';

/**
 * P4 — ALLOCATION LEDGER, *BEHAVIOURAL* VERIFICATION.
 *
 * The existing P5 emission suite asserts SOURCE STRUCTURE — that a call exists,
 * that a regex matches. That is the exact weakness which let the `_buildAllocations`
 * defect ship green: a test that cannot express the production shape cannot fail
 * on a production defect.
 *
 * This suite runs the REAL service against a Firestore double with REAL
 * create-if-not-exists semantics, and asserts on what the engine *did* — rows
 * actually in the store, counters actually returned. It is the offline rehearsal
 * of the tenant-scoped canary: everything the canary would prove, proven here
 * first, so the canary confirms rather than discovers.
 *
 * The tenant id is a fixture constant local to this file. Nothing here is
 * hard-coded against the real canary school.
 */
class P4LedgerEngineBehaviourTest extends TestCase
{
    private const SCHOOL  = 'SCH_TESTTENANT1';
    private const OTHER   = 'SCH_OTHERTENANT';
    private const SESSION = '2026-27';

    /**
     * Firestore double with GENUINE create-only semantics — the actual
     * idempotency primitive the ledger relies on. A second create against a
     * live id returns COLLISION and leaves the stored document untouched.
     */
    private function store()
    {
        return new class {
            public array $docs = [];
            public int $createCalls = 0;
            public $failNext = null;

            public function firestoreCreateStatus(string $col, string $id, array $data): array
            {
                $this->createCalls++;
                if ($this->failNext !== null) {
                    $s = $this->failNext; $this->failNext = null;
                    return ['status' => $s, 'message' => 'injected'];
                }
                $key = $col . '/' . $id;
                if (array_key_exists($key, $this->docs)) {
                    return ['status' => 'COLLISION', 'message' => 'exists'];
                }
                $this->docs[$key] = $data;
                return ['status' => 'SUCCESS'];
            }

            public function rows(string $col = 'entitlementAllocations'): array
            {
                $out = [];
                foreach ($this->docs as $k => $v) if (strpos($k, $col . '/') === 0) $out[] = $v;
                return $out;
            }
        };
    }

    private function svc($fb, string $school = self::SCHOOL, string $session = self::SESSION)
    {
        return (new Entitlement_allocation_service())
            ->init($fb, null, $school, $session, 'SSA_TEST');
    }

    private function demand(array $over = []): array
    {
        return array_merge([
            'demandId' => 'FD_0001', 'schoolId' => self::SCHOOL, 'session' => self::SESSION,
            'studentId' => 'STU9001', 'grossAmount' => 1000.0, 'discountAmount' => 200.0,
            'feeHeadRef' => 'FH_ABCDEF123456', 'periodKey' => '2026-04',
        ], $over);
    }

    private function ent(array $over = []): array
    {
        return array_merge([
            'entitlementId' => 'SE_0001', 'schoolId' => self::SCHOOL, 'session' => self::SESSION,
            'status' => 'active', 'valueType' => 'percent', 'value' => 20.0, 'scope' => 'head',
        ], $over);
    }

    // ══ PERSISTENCE — rows actually land ═══════════════════════════════════

    public function test_an_allocation_is_actually_written_to_the_store(): void
    {
        $fb = $this->store();
        $s  = $this->svc($fb);

        $built = $s->build($this->demand(), $this->ent(), 200.0);
        $this->assertTrue($built['ok'], 'build refused: ' . ($built['reason'] ?? ''));

        $res = $s->persist([$built['row']]);

        $this->assertSame(1, $res['created']);
        $this->assertSame(0, $res['failed']);
        $this->assertCount(1, $fb->rows(), 'the row must exist in the store, not merely be reported');

        $row = $fb->rows()[0];
        $this->assertSame(self::SCHOOL . '_FD_0001_SE_0001', $row['allocationId']);
        $this->assertSame(200.0,  $row['allocatedAmount']);
        $this->assertSame(1000.0, $row['basisAmount']);
        $this->assertSame('active', $row['status']);
    }

    // ══ IDEMPOTENCY — the canary's most important property ═════════════════

    /**
     * Re-running generation must not double-count. This is proven by the STORE,
     * not by a counter the code chose to report.
     */
    public function test_persisting_the_same_allocation_twice_creates_exactly_one_row(): void
    {
        $fb = $this->store();
        $s  = $this->svc($fb);
        $row = $s->build($this->demand(), $this->ent(), 200.0)['row'];

        $first  = $s->persist([$row]);
        $second = $s->persist([$row]);

        $this->assertSame(1, $first['created']);
        $this->assertSame(0, $second['created'], 'a re-run must create nothing');
        $this->assertSame(1, $second['duplicate'], 'and must report the collision honestly');
        $this->assertCount(1, $fb->rows(), 'the ledger must hold exactly one row after two runs');
        $this->assertSame(2, $fb->createCalls, 'both attempts did reach Firestore — this is not a no-op path');
    }

    /** A collision must NOT overwrite the stored row — history is immutable. */
    public function test_a_collision_leaves_the_original_row_untouched(): void
    {
        $fb = $this->store();
        $s  = $this->svc($fb);
        $s->persist([$s->build($this->demand(), $this->ent(), 200.0)['row']]);

        // Same identity, different money — a reprice attempting to sneak through.
        $mutated = $s->build($this->demand(['grossAmount' => 5000.0]), $this->ent(), 999.0)['row'];
        $s->persist([$mutated]);

        $this->assertCount(1, $fb->rows());
        $this->assertSame(200.0, $fb->rows()[0]['allocatedAmount'],
            'a create-only write must never rewrite the recorded amount');
    }

    /** Identity is (school, demand, entitlement) — different demands coexist. */
    public function test_distinct_demands_and_entitlements_produce_distinct_rows(): void
    {
        $fb = $this->store();
        $s  = $this->svc($fb);
        $rows = [
            $s->build($this->demand(), $this->ent(), 200.0)['row'],
            $s->build($this->demand(['demandId' => 'FD_0002']), $this->ent(), 200.0)['row'],
            $s->build($this->demand(), $this->ent(['entitlementId' => 'SE_0002']), 100.0)['row'],
        ];
        $res = $s->persist($rows);
        $this->assertSame(3, $res['created']);
        $this->assertCount(3, $fb->rows());
    }

    // ══ ISOLATION — refusals at the persistence boundary ═══════════════════

    public function test_a_cross_tenant_row_is_refused_at_persist_even_if_hand_crafted(): void
    {
        $fb  = $this->store();
        $s   = $this->svc($fb);
        $row = $s->build($this->demand(), $this->ent(), 200.0)['row'];
        $row['schoolId'] = self::OTHER;           // forged after build()

        $res = $s->persist([$row]);

        $this->assertSame(0, $res['created']);
        $this->assertSame(1, $res['failed']);
        $this->assertCount(0, $fb->rows(), 'nothing may reach the store');
        $this->assertStringContainsString('tenant_mismatch', implode(' ', $res['errors']));
    }

    public function test_build_refuses_a_demand_from_another_tenant(): void
    {
        $s = $this->svc($this->store());
        $this->assertSame('tenant_mismatch',
            $s->build($this->demand(['schoolId' => self::OTHER]), $this->ent(), 200.0)['reason']);
        $this->assertSame('tenant_mismatch',
            $s->build($this->demand(), $this->ent(['schoolId' => self::OTHER]), 200.0)['reason']);
    }

    public function test_build_refuses_a_session_mismatch(): void
    {
        $s = $this->svc($this->store());
        $this->assertSame('session_mismatch',
            $s->build($this->demand(['session' => '2025-26']), $this->ent(), 200.0)['reason']);
    }

    public function test_build_refuses_an_inactive_grant(): void
    {
        $s = $this->svc($this->store());
        foreach (['pending', 'revoked', ''] as $status) {
            $this->assertSame('entitlement_not_active',
                $s->build($this->demand(), $this->ent(['status' => $status]), 200.0)['reason'],
                "status '{$status}' must not allocate money");
        }
    }

    public function test_build_refuses_to_discount_more_than_the_charge(): void
    {
        $s = $this->svc($this->store());
        $this->assertSame('exceeds_basis',
            $s->build($this->demand(['grossAmount' => 100.0]), $this->ent(), 500.0)['reason']);
    }

    // ══ FAILURE IS REPORTED, NEVER SWALLOWED ═══════════════════════════════

    public function test_a_write_failure_is_counted_and_nothing_is_stored(): void
    {
        $fb = $this->store();
        $fb->failNext = 'FAILURE';
        $s  = $this->svc($fb);

        $res = $s->persist([$s->build($this->demand(), $this->ent(), 200.0)['row']]);

        $this->assertSame(0, $res['created']);
        $this->assertSame(1, $res['failed']);
        $this->assertCount(0, $fb->rows());
    }

    /** No Firestore handle must fail LOUD, not silently report success. */
    public function test_a_missing_firestore_handle_fails_closed_and_loud(): void
    {
        $s   = (new Entitlement_allocation_service())->init(null, null, self::SCHOOL, self::SESSION, 'SSA_TEST');
        $res = $s->persist([['allocationId' => 'X', 'schoolId' => self::SCHOOL]]);

        $this->assertSame(0, $res['created']);
        $this->assertSame(1, $res['failed']);
        $this->assertContains('no_firestore_handle', $res['errors']);
    }

    // ══ THE COMPOSED INVARIANT — the ledger explains the demand ════════════

    public function test_stored_rows_exactly_explain_the_demand_discount(): void
    {
        $fb = $this->store();
        $s  = $this->svc($fb);
        $d  = $this->demand(['discountAmount' => 300.0]);

        $s->persist([
            $s->build($d, $this->ent(), 200.0)['row'],
            $s->build($d, $this->ent(['entitlementId' => 'SE_0002']), 100.0)['row'],
        ]);

        $this->assertNull($s->validateForDemand($d, $fb->rows()),
            'two stacked grants totalling 300 must explain a 300 discount');
    }

    public function test_a_shortfall_is_detected_as_a_projection_mismatch(): void
    {
        $fb = $this->store();
        $s  = $this->svc($fb);
        $d  = $this->demand(['discountAmount' => 300.0]);
        $s->persist([$s->build($d, $this->ent(), 200.0)['row']]);

        $v = $s->validateForDemand($d, $fb->rows());
        $this->assertNotNull($v);
        $this->assertSame('projection_mismatch', $v['reason']);
        $this->assertSame(-100.0, $v['delta']);
    }

    public function test_over_allocation_across_rows_is_reported_before_the_mismatch(): void
    {
        $s = $this->svc($this->store());
        $d = $this->demand(['grossAmount' => 100.0, 'discountAmount' => 100.0]);
        $rows = [
            ['status' => 'active', 'allocatedAmount' => 80.0],
            ['status' => 'active', 'allocatedAmount' => 80.0],
        ];
        $v = $s->validateForDemand($d, $rows);
        $this->assertSame('over_allocated', $v['reason'], 'the graver fault must be the one reported');
    }

    /** A REVERSED row stops counting — that is what makes reversal work. */
    public function test_a_reversed_row_no_longer_counts_toward_the_projection(): void
    {
        $fb = $this->store();
        $s  = $this->svc($fb);
        $d  = $this->demand(['discountAmount' => 200.0]);
        $s->persist([
            $s->build($d, $this->ent(), 200.0)['row'],
            $s->build($d, $this->ent(['entitlementId' => 'SE_DEAD']), 150.0)['row'],
        ]);

        $rows = $fb->rows();
        $this->assertNotNull($s->validateForDemand($d, $rows), '350 cannot explain 200');

        // Reverse the second grant rather than deleting it.
        $patch = $s->buildReversal($rows[1], 'grant overturned');
        $rows[1] = array_merge($rows[1], $patch);

        $this->assertNull($s->validateForDemand($d, $rows),
            'after reversal the remaining 200 explains the demand exactly');
        $this->assertSame('reversed', $rows[1]['status']);
        $this->assertSame(150.0, $rows[1]['allocatedAmount'],
            'the historical amount must survive reversal — this is a ledger, not a delete');
    }

    // ══ STALE DETECTION — DEC-001 in-place reprice ═════════════════════════

    public function test_an_in_place_reprice_makes_the_allocation_stale(): void
    {
        $fb  = $this->store();
        $s   = $this->svc($fb);
        $row = $s->build($this->demand(), $this->ent(), 200.0)['row'];

        $this->assertFalse($s->isStale($row, $this->demand()),
            'unchanged charge must not read as stale');
        $this->assertTrue($s->isStale($row, $this->demand(['grossAmount' => 1500.0])),
            'a Transport route change moves grossAmount under the allocation — P5 must recompute');
    }

    public function test_a_sub_cent_drift_is_not_treated_as_stale(): void
    {
        $s   = $this->svc($this->store());
        $row = $s->build($this->demand(), $this->ent(), 200.0)['row'];
        $this->assertFalse($s->isStale($row, $this->demand(['grossAmount' => 1000.004])),
            'float noise must not trigger a spurious recompute');
    }

    // ══ THE SNAPSHOT — a later edit cannot rewrite history ═════════════════

    public function test_the_row_snapshots_the_rule_as_applied(): void
    {
        $fb  = $this->store();
        $s   = $this->svc($fb);
        $s->persist([$s->build($this->demand(), $this->ent(), 200.0)['row']]);

        $row = $fb->rows()[0];
        $this->assertSame('percent', $row['valueType']);
        $this->assertSame(20.0,     $row['value']);
        $this->assertSame('head',   $row['scope']);
        $this->assertSame('SSA_TEST', $row['createdBy']);
    }
}
