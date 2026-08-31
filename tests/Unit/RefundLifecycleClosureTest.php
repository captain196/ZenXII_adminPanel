<?php
/**
 * PHASE 9 — THE REFUND OBLIGATION, END TO END.
 *
 * `OverpaymentRefundObligationTest` already pins detection: the exact excess,
 * the deterministic id, freeze-on-authorisation, cancellation when the
 * liability returns, and that nothing is ever auto-approved. This file closes
 * the scenarios that file does not reach — the ones about SCOPE and about the
 * parts of the lifecycle that live downstream of detection.
 *
 * ── WHAT WAS PROVEN AT RUNTIME (emulator, 2026-08-31) ─────────────────────
 * The whole chain was executed against the real endpoints, not simulated:
 *
 *   service reduction (Transport_service)
 *     -> demand recalculation
 *     -> overpayment detected, ₹5,000 excess on ₹12,000 paid
 *     -> feeRefunds/SCH_FAREAUDIT1_ref_auto_transport_STU0030_2026-27, pending
 *     -> approved
 *     -> processed, mode bank_transfer,
 *        voucher REFUND_AUTO_TRANSPORT_STU0030_2026-27
 *
 *   re-processing the same refund -> {"idempotent": true}, paid once
 *   approving a PROCESSED refund  -> refused, "Current status is 'processed'"
 *
 * The journal post returned null in that run because the emulator holds NO
 * chart of accounts at all (`accounts` and `chartOfAccounts` are both empty) —
 * a fixture gap, not a product defect. What matters for correctness is that the
 * endpoint REPORTED it (`journal_posted: false`, plus a Retry Journal action)
 * instead of returning a bare success. A refund that silently skipped its
 * ledger entry would be exactly the phantom-success shape this codebase treats
 * as a defect.
 *
 * ── THE RULE THESE TESTS DEFEND ───────────────────────────────────────────
 * A pending obligation must never silently disappear, and authorised or
 * processed financial history must never be rewritten.
 */

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Overpayment_service.php';

final class RefundLifecycleClosureTest extends TestCase
{
    /**
     * Firestore double that keys stored refunds by DOCUMENT ID, so a lookup
     * with the wrong tenant or session prefix genuinely misses — which is the
     * only way to test scoping rather than assume it.
     */
    private function fakes(array $store = []): array
    {
        $txn = new class {
            public array $writes = [];
            public function writeRefund(string $refId, array $data): bool
            {
                $this->writes[$refId] = array_merge($this->writes[$refId] ?? [], $data);
                return true;
            }
        };
        $fb = new class($store) {
            public array $reads = [];
            public function __construct(private array $store) {}
            public function firestoreGet(string $col, string $id)
            {
                $this->reads[] = $id;
                return $this->store[$id] ?? null;
            }
        };
        return [$txn, $fb];
    }

    private function svc($txn, $fb, string $school = 'SCH_T1', string $session = '2026-27'): Overpayment_service
    {
        return (new Overpayment_service())->init($txn, $fb, $school, $session, 'SSA9001');
    }

    private function demand(float $net, float $paid, float $fine = 0.0): array
    {
        return ['netAmount' => $net, 'paidAmount' => $paid, 'fineAmount' => $fine,
                'feeHead' => 'Transport Fee', 'demandId' => 'DEM_X', 'studentName' => 'A Student'];
    }

    // ══ TENANT ISOLATION ═══════════════════════════════════════════════════

    /**
     * Another school's refund for the same student id must be invisible. If it
     * were not, school B's authorised refund would freeze school A's obligation
     * and A's family would never be repaid.
     */
    public function test_another_tenants_refund_cannot_freeze_this_tenants_obligation(): void
    {
        // An APPROVED refund exists — but under a DIFFERENT school prefix.
        [$txn, $fb] = $this->fakes([
            'SCH_OTHER_ref_auto_transport_STU1_2026-27' => ['status' => 'approved', 'amount' => 5000],
        ]);
        $r = $this->svc($txn, $fb, 'SCH_T1')->reconcile('STU1', 'transport', $this->demand(7000, 12000));

        $this->assertSame('created', $r['outcome'],
            "another tenant's authorised refund must not freeze this tenant's obligation");
        $this->assertSame(5000.0, $r['excess']);
        $this->assertStringStartsWith('SCH_T1_', $fb->reads[0],
            'the lookup must be tenant-scoped by document id');
    }

    /** The obligation is written under this tenant's own key. */
    public function test_the_obligation_is_written_with_this_tenants_scope(): void
    {
        [$txn, $fb] = $this->fakes();
        $this->svc($txn, $fb, 'SCH_T1')->reconcile('STU1', 'transport', $this->demand(7000, 12000));

        // The tenant prefix lives in the DOCUMENT ID (applied by writeRefund),
        // not in the payload — so scope is asserted on the key that was read.
        $this->assertSame('SCH_T1_ref_auto_transport_STU1_2026-27', $fb->reads[0]);
        $this->assertArrayHasKey('ref_auto_transport_STU1_2026-27', $txn->writes);
    }

    // ══ SESSION ISOLATION ══════════════════════════════════════════════════

    /**
     * The same student, the same module, a DIFFERENT session is a DIFFERENT
     * obligation. Last year's approved refund must not suppress this year's.
     */
    public function test_a_prior_sessions_refund_does_not_suppress_this_sessions(): void
    {
        [$txn, $fb] = $this->fakes([
            'SCH_T1_ref_auto_transport_STU1_2025-26' => ['status' => 'approved', 'amount' => 5000],
        ]);
        $r = $this->svc($txn, $fb, 'SCH_T1', '2026-27')
                  ->reconcile('STU1', 'transport', $this->demand(7000, 12000));

        $this->assertSame('created', $r['outcome'],
            "an obligation settled last session must not cancel this session's");
        $this->assertStringContainsString('2026-27', $r['refundId']);
    }

    // ══ MULTIPLE REDUCTIONS ════════════════════════════════════════════════

    /**
     * Successive service reductions must converge on ONE obligation carrying
     * the CURRENT excess — not a trail of obligations that together exceed
     * what the family overpaid.
     */
    public function test_successive_reductions_keep_exactly_one_obligation(): void
    {
        $store = [];
        $totalWrites = 0;
        foreach ([[9000.0, 3000.0], [7000.0, 5000.0], [4000.0, 8000.0]] as [$net, $expected]) {
            [$txn, $fb] = $this->fakes($store);
            $r = $this->svc($txn, $fb)->reconcile('STU1', 'transport', $this->demand($net, 12000));

            $this->assertContains($r['outcome'], ['created', 'updated']);
            $this->assertSame($expected, $r['excess'],
                'each reduction must restate the CURRENT excess, not add to a running total');

            // Feed the write back in as the stored document for the next round.
            $store['SCH_T1_' . $r['refundId']] = reset($txn->writes);  // refundId is bare
            $totalWrites += count($txn->writes);
        }
        $this->assertSame(3, $totalWrites, 'three reconciliations…');
        $this->assertCount(1, $store, '…but exactly ONE obligation document');
    }

    // ══ PAID VERSUS UNPAID ═════════════════════════════════════════════════

    /** An unpaid demand cannot produce an overpayment, however far it falls. */
    public function test_an_unpaid_demand_never_raises_a_refund(): void
    {
        [$txn, $fb] = $this->fakes();
        $r = $this->svc($txn, $fb)->reconcile('STU1', 'transport', $this->demand(1000, 0));

        $this->assertSame('none', $r['outcome'],
            'nothing was handed over, so nothing can be owed back');
        $this->assertSame([], $txn->writes);
    }

    /** A partly paid demand only overpays once payment passes the liability. */
    public function test_a_part_paid_demand_overpays_only_past_the_liability(): void
    {
        [$txn, $fb] = $this->fakes();
        $this->assertSame('none', $this->svc($txn, $fb)
            ->reconcile('STU1', 'transport', $this->demand(5000, 4999))['outcome']);

        [$txn2, $fb2] = $this->fakes();
        $r = $this->svc($txn2, $fb2)->reconcile('STU1', 'transport', $this->demand(5000, 5001));
        $this->assertSame('created', $r['outcome']);
        $this->assertSame(1.0, $r['excess']);
    }

    // ══ REJECTION AND RESUME ═══════════════════════════════════════════════

    /**
     * A REJECTED obligation is a decision, not a blank slate. Re-running
     * reconciliation must not quietly resurrect it as pending — that would let
     * a refusal be undone by a background recalculation rather than by a human.
     */
    public function test_a_rejected_obligation_is_not_silently_revived(): void
    {
        [$txn, $fb] = $this->fakes([
            'SCH_T1_ref_auto_transport_STU1_2026-27' => ['status' => 'rejected', 'amount' => 5000],
        ]);
        $r = $this->svc($txn, $fb)->reconcile('STU1', 'transport', $this->demand(7000, 12000));

        $this->assertSame('rejected_upheld', $r['outcome'],
            'a rejection is a human decision; a recalculation must not overturn it');
        $this->assertSame([], $txn->writes,
            'and nothing may be re-minted — the refusal stands until a human reopens it');
        $this->assertSame(5000.0, $r['excess'],
            'but the excess is still REPORTED, so it cannot vanish unnoticed');
    }

    /**
     * RESUME. A still-pending obligation that is reconciled again stays the
     * same obligation and is restated, never duplicated — the property the
     * deterministic id exists to guarantee.
     */
    public function test_resuming_on_a_pending_obligation_restates_it(): void
    {
        [$txn, $fb] = $this->fakes([
            'SCH_T1_ref_auto_transport_STU1_2026-27' => ['status' => 'pending', 'amount' => 3000],
        ]);
        $r = $this->svc($txn, $fb)->reconcile('STU1', 'transport', $this->demand(7000, 12000));

        $this->assertSame('updated', $r['outcome']);
        $this->assertSame(5000.0, $r['excess'], 'the obligation now carries the CURRENT excess');
        $this->assertCount(1, $txn->writes, 'and it is the same document, not a second one');
    }

    // ══ THE OBLIGATION NEVER VANISHES ══════════════════════════════════════

    /**
     * Every outcome is either an obligation the school still owes, or an
     * explicit, named disposal of one. There is no path that simply drops it.
     */
    public function test_every_outcome_is_accounted_for(): void
    {
        $known = ['none', 'created', 'updated', 'frozen', 'cancelled',
                  'residual_reported', 'rejected_upheld', 'error'];

        $cases = [
            [$this->demand(1000, 0),      []],                                    // nothing paid
            [$this->demand(7000, 12000),  []],                                    // fresh excess
            [$this->demand(7000, 12000),  ['status' => 'approved', 'amount' => 5000]],
            [$this->demand(7000, 12000),  ['status' => 'pending',  'amount' => 5000]],
            [$this->demand(13000, 12000), ['status' => 'pending',  'amount' => 5000]], // liability back
        ];

        foreach ($cases as $i => [$demand, $stored]) {
            [$txn, $fb] = $this->fakes(
                $stored ? ['SCH_T1_ref_auto_transport_STU1_2026-27' => $stored] : []
            );
            $r = $this->svc($txn, $fb)->reconcile('STU1', 'transport', $demand);
            $this->assertContains($r['outcome'], $known,
                "case {$i} produced an unnamed outcome '{$r['outcome']}' — an obligation must "
                . 'never leave the system through a path nobody named');
        }
    }

    /** Detection never authorises or moves money — approval stays human. */
    public function test_detection_never_authorises_payment(): void
    {
        [$txn, $fb] = $this->fakes();
        $this->svc($txn, $fb)->reconcile('STU1', 'transport', $this->demand(7000, 12000));

        $row = reset($txn->writes);
        $this->assertSame('pending', $row['status'],
            'an automatically detected obligation must enter the queue as PENDING; '
            . 'approving or paying it is a human act');
        foreach (['approvedBy', 'processedAt', 'refundMode'] as $settled) {
            $this->assertTrue(empty($row[$settled]),
                "detection must not pre-fill '{$settled}'");
        }
    }
}
