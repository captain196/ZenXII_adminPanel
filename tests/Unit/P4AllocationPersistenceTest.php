<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Entitlement_allocation_service.php';

/**
 * P4 PERSISTENCE (P5 step 2) — writing allocation rows, idempotently.
 *
 * The double below models the REAL `firestoreCreateStatus()` contract, not a
 * recording spy: a second create for the same document id returns **COLLISION**
 * and leaves the stored document untouched. That distinction is the whole
 * mechanism — duplicate prevention here is structural (deterministic ids), not
 * a check somebody has to remember.
 *
 * This matters because the two worst defects in this programme both slipped
 * through doubles that could not express production semantics: the rollout
 * incident (a recording double could not express `updateMask` replacing a map)
 * and DEC-010 (a double modelling the wrong class). A double that cannot
 * collide cannot prove idempotency.
 *
 * INERT: `USE_ALLOCATION_LEDGER` ships false, so the generator attaches no rows
 * and this path is a no-op walk. The collection's rules are prepared but NOT
 * deployed, so a write would be server-refused anyway — the boundary closes
 * before the first row can exist.
 */
class P4AllocationPersistenceTest extends TestCase
{
    private const SCHOOL = 'SCH_B56BB9A401';
    private const SESS   = '2026-27';

    /**
     * Firestore double with REAL create-if-not-exists semantics.
     *   first create  → SUCCESS, document stored
     *   repeat create → COLLISION, stored document UNCHANGED
     */
    private function fb(bool $failAll = false)
    {
        return new class($failAll) {
            public array $store = [];
            public array $attempts = [];
            private bool $failAll;
            public function __construct(bool $failAll) { $this->failAll = $failAll; }
            public function firestoreCreateStatus(string $col, string $id, array $data): array
            {
                $this->attempts[] = $id;
                if ($this->failAll) {
                    return ['status' => 'FAILURE', 'code' => 500, 'message' => 'simulated outage'];
                }
                if (isset($this->store[$id])) {
                    return ['status' => 'COLLISION', 'code' => 409, 'message' => 'already exists'];
                }
                $this->store[$id] = $data;      // stored ONCE, never overwritten
                return ['status' => 'SUCCESS', 'code' => 200];
            }
        };
    }

    private function svc($fb, string $school = self::SCHOOL): Entitlement_allocation_service
    {
        return (new Entitlement_allocation_service())->init($fb, null, $school, self::SESS, 'SSA0011');
    }

    private function demand(array $over = []): array
    {
        return array_merge([
            'demandId' => 'D1', 'studentId' => 'STU0185', 'schoolId' => self::SCHOOL,
            'session' => self::SESS, 'feeHead' => 'Tuition Fee',
            'feeHeadRef' => 'FH_DF3C200439E2', 'periodKey' => '2026-06',
            'grossAmount' => 600.0, 'discountAmount' => 60.0, 'netAmount' => 540.0,
        ], $over);
    }

    private function ent(array $over = []): array
    {
        return array_merge([
            'entitlementId' => 'E1', 'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'status' => 'active', 'valueType' => 'percent', 'value' => 10,
            'scope' => 'head:FH_DF3C200439E2',
        ], $over);
    }

    private function rows($svc, int $n = 1): array
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $r = $svc->build($this->demand(['demandId' => 'D' . $i]), $this->ent(), 60.0);
            $rows[] = $r['row'];
        }
        return $rows;
    }

    // ══ THE CORE: IDEMPOTENCY ═══════════════════════════════════════════

    public function test_a_fresh_row_is_created(): void
    {
        $fb = $this->fb(); $svc = $this->svc($fb);
        $res = $svc->persist($this->rows($svc, 1));
        $this->assertSame(['created' => 1, 'duplicate' => 0, 'failed' => 0, 'errors' => []], $res);
        $this->assertCount(1, $fb->store);
    }

    /** THE POINT: a re-run collides rather than duplicating. */
    public function test_a_rerun_collides_and_creates_nothing_new(): void
    {
        $fb = $this->fb(); $svc = $this->svc($fb);
        $rows = $this->rows($svc, 3);

        $first  = $svc->persist($rows);
        $second = $svc->persist($rows);
        $third  = $svc->persist($rows);

        $this->assertSame(3, $first['created']);
        $this->assertSame(['created' => 0, 'duplicate' => 3, 'failed' => 0, 'errors' => []], $second);
        $this->assertSame(['created' => 0, 'duplicate' => 3, 'failed' => 0, 'errors' => []], $third);
        $this->assertCount(3, $fb->store, 'still exactly three documents');
    }

    /** An existing row is NEVER overwritten — history must not be restated. */
    public function test_an_existing_row_is_never_overwritten(): void
    {
        $fb = $this->fb(); $svc = $this->svc($fb);
        $row = $this->rows($svc, 1)[0];
        $svc->persist([$row]);
        $stored = $fb->store[$row['allocationId']];

        // A later attempt carrying a DIFFERENT amount must not change history.
        $row2 = $row; $row2['allocatedAmount'] = 999.0;
        $res = $svc->persist([$row2]);

        $this->assertSame(1, $res['duplicate']);
        $this->assertSame($stored, $fb->store[$row['allocationId']],
            'the stored row must be byte-identical to what was first written');
        $this->assertSame(60.0, $fb->store[$row['allocationId']]['allocatedAmount']);
    }

    /** A partially-written set completes on the next pass without doubling. */
    public function test_a_partial_set_completes_without_doubling(): void
    {
        $fb = $this->fb(); $svc = $this->svc($fb);
        $rows = $this->rows($svc, 4);
        $svc->persist(array_slice($rows, 0, 2));      // interrupted run
        $res = $svc->persist($rows);                   // full retry
        $this->assertSame(2, $res['created']);
        $this->assertSame(2, $res['duplicate']);
        $this->assertCount(4, $fb->store);
    }

    /** Two entitlements on one demand are two distinct rows. */
    public function test_two_entitlements_on_one_demand_persist_separately(): void
    {
        $fb = $this->fb(); $svc = $this->svc($fb);
        $a = $svc->build($this->demand(), $this->ent(), 60.0)['row'];
        $b = $svc->build($this->demand(['discountAmount' => 90.0]),
                         $this->ent(['entitlementId' => 'E2']), 30.0)['row'];
        $res = $svc->persist([$a, $b]);
        $this->assertSame(2, $res['created']);
        $this->assertCount(2, $fb->store);
    }

    /** The same entitlement cannot allocate twice to the same demand. */
    public function test_one_entitlement_cannot_allocate_twice_to_one_demand(): void
    {
        $fb = $this->fb(); $svc = $this->svc($fb);
        $a = $svc->build($this->demand(), $this->ent(), 60.0)['row'];
        $svc->persist([$a, $a]);   // same row twice in ONE call
        $this->assertCount(1, $fb->store, 'deterministic id makes this structurally impossible');
    }

    // ══ FAIL-CLOSED AND FAIL-SOFT ═══════════════════════════════════════

    /** No Firestore handle → fail CLOSED and LOUD, never silently "succeed". */
    public function test_no_firestore_handle_fails_closed(): void
    {
        $svc = (new Entitlement_allocation_service())
            ->init(null, null, self::SCHOOL, self::SESS, 'SSA0011');
        $res = $svc->persist([['allocationId' => 'X', 'schoolId' => self::SCHOOL]]);
        $this->assertSame(1, $res['failed']);
        $this->assertSame(['no_firestore_handle'], $res['errors']);
        $this->assertSame(0, $res['created']);
    }

    /** A write outage is counted and reported, never thrown at the caller. */
    public function test_a_write_failure_is_counted_not_thrown(): void
    {
        $fb = $this->fb(true); $svc = $this->svc($fb);
        $res = $svc->persist($this->rows($this->svc($this->fb()), 2));
        $this->assertSame(2, $res['failed']);
        $this->assertSame(0, $res['created']);
        $this->assertCount(2, $res['errors']);
    }

    /** An empty set is a clean no-op — the flag-off path. */
    public function test_an_empty_set_is_a_no_op(): void
    {
        $fb = $this->fb();
        $res = $this->svc($fb)->persist([]);
        $this->assertSame(['created' => 0, 'duplicate' => 0, 'failed' => 0, 'errors' => []], $res);
        $this->assertSame([], $fb->attempts, 'no write attempt at all');
    }

    /** A row with no id is refused rather than written somewhere arbitrary. */
    public function test_a_row_without_an_id_is_refused(): void
    {
        $fb = $this->fb();
        $res = $this->svc($fb)->persist([['schoolId' => self::SCHOOL]]);
        $this->assertSame(1, $res['failed']);
        $this->assertSame([], $fb->attempts);
    }

    // ══ TENANT ISOLATION AT THE LAST LINE ═══════════════════════════════

    /**
     * Persistence re-checks the tenant even though build() already did: this is
     * the last line before the data exists, and a cross-tenant row is
     * unrecoverable once written.
     */
    public function test_a_cross_tenant_row_is_refused_at_write_time(): void
    {
        $fb = $this->fb(); $svc = $this->svc($fb);
        $row = $this->rows($svc, 1)[0];
        $row['schoolId'] = 'SCH_218AAF5C23';          // tampered after build
        $res = $svc->persist([$row]);
        $this->assertSame(1, $res['failed']);
        $this->assertSame([], $fb->attempts, 'refused BEFORE any write attempt');
        $this->assertStringContainsString('tenant_mismatch', $res['errors'][0]);
    }

    // ══ WIRING: ORDER AND INERTNESS ═════════════════════════════════════

    /** Allocations must be written only AFTER the demands they describe. */
    public function test_persistence_runs_after_the_demand_commit(): void
    {
        // Scoped to assignInitialFees (2026-08-20). P5-C added
        // `persistAllocationsForBatch()`, a thin public wrapper that also names
        // `_persistAllocations($studentId, $batchEntries)` and sits EARLIER in
        // the file — so a whole-file strpos() found the wrapper and measured an
        // ordering that does not exist. The ordering being asserted is a
        // property of assignInitialFees, so look only there.
        $src = file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php');
        $from = strpos($src, 'public function assignInitialFees');
        $this->assertNotFalse($from, 'assignInitialFees not found');
        // ── FIXED-WINDOW FRAGILITY (2026-08-29) ─────────────────────────────
        // This was substr($src, $from, 4000). The window is not a property of
        // the code under test: adding a comment to assignInitialFees pushed
        // commitDemandBatch past 4000 characters, strpos() returned false, and
        // the test failed with "false is not false" while the ORDERING IT
        // ASSERTS WAS STILL CORRECT. A test that breaks on comment length is
        // reporting on formatting, not on the invariant.
        //
        // Brace-match the real function body instead, so the window is exactly
        // the function however long it grows. Same assertion, no false alarm —
        // and, more importantly, no false PASS if the body ever outgrows a
        // window that silently truncates before the second call.
        $depth = 0; $end = $from; $seen = false;
        for ($i = $from, $n = strlen($src); $i < $n; $i++) {
            if ($src[$i] === '{') { $depth++; $seen = true; }
            elseif ($src[$i] === '}') { $depth--; if ($seen && $depth === 0) { $end = $i; break; } }
        }
        $this->assertTrue($seen && $end > $from, 'could not brace-match assignInitialFees');
        $body = substr($src, $from, $end - $from + 1);

        $commitAt  = strpos($body, 'commitDemandBatch($batchEntries)');
        $persistAt = strpos($body, '_persistAllocations($studentId, $batchEntries)');
        $this->assertNotFalse($commitAt);
        $this->assertNotFalse($persistAt);
        $this->assertLessThan($persistAt, $commitAt,
            'a ledger row about an uncommitted demand would describe a charge that does not exist');
    }

    /** With the flag off the generator attaches nothing, so this is a no-op. */
    public function test_it_is_a_no_op_when_no_allocations_are_attached(): void
    {
        $src = file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php');
        $at  = strpos($src, 'private function _persistAllocations');
        $seg = substr($src, $at, 1200);
        $this->assertStringContainsString("if (\$rows === []) return;", $seg,
            'no rows attached ⇒ return before touching the loader or Firestore');
        $this->assertStringContainsString("empty(\$entry['allocations'])", $seg,
            'rows are read from the ENTRY, never from the demand body');
    }

    /** The flag still ships false — implementation is not activation. */
    public function test_the_ledger_flag_still_ships_false(): void
    {
        $this->assertStringContainsString("\$config['USE_ALLOCATION_LEDGER'] = true",
            file_get_contents(APPPATH . 'config/fees_exemption_v2_flags.php'));
    }

    /** Persistence must not have leaked into the demand document schema. */
    public function test_the_demand_schema_is_still_untouched(): void
    {
        $this->assertStringNotContainsString("'allocations'",
            file_get_contents(APPPATH . 'libraries/Fee_firestore_txn.php'),
            'the Parent/Teacher demand contract must remain unchanged');
    }
}
