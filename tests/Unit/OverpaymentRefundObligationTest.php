<?php
/**
 * Overpayment_service — EXCESS MONEY MUST NEVER SILENTLY DISAPPEAR.
 *
 * THE DEFECT. When a liability is legitimately reduced after payment,
 * Fee_lifecycle recomputes `max(0.0, $net + $fine - $paid)`. The floor is
 * right — a balance must never go negative — but nothing else happened. A
 * family that paid 12,000 for a service now correctly costing 5,000 saw a
 * balance of 0 and no trace of the other 7,000. No record was corrupted; the
 * money simply stopped being visible.
 *
 * That is not a school's choice to make. Indian fee regulation is explicit
 * that a school may retain a nominal admission charge but NOT advance tuition
 * for classes the student did not attend.
 *
 * PROVEN AT RUNTIME before these tests were written, on a Rs 1,000/month route
 * paid 12,000 up front and stopped on 1 September:
 *     demand  net 5,000  paid 12,000  balance 0   (payment untouched)
 *     refund  7,000  status pending  source auto_liability_reduction
 * Re-running the same reduction left ONE row. Resuming CANCELLED it. Marking
 * it approved froze it against further edits.
 *
 * These tests run the arithmetic and the state machine directly.
 */

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Overpayment_service.php';

final class OverpaymentRefundObligationTest extends TestCase
{
    /** Records writes and replays one stored refund, like the real collection. */
    private function fakes(?array $stored = null): array
    {
        $txn = new class {
            public array $writes = [];
            public function writeRefund(string $refId, array $data): bool
            {
                $this->writes[$refId] = array_merge($this->writes[$refId] ?? [], $data);
                return true;
            }
        };
        $fb = new class($stored) {
            public function __construct(private ?array $stored) {}
            public function firestoreGet(string $col, string $id) { return $this->stored; }
        };
        return [$txn, $fb];
    }

    private function svc($txn, $fb): Overpayment_service
    {
        return (new Overpayment_service())->init($txn, $fb, 'SCH_T1', '2026-27', 'SSA9001');
    }

    private function demand(float $net, float $paid, float $fine = 0.0): array
    {
        return ['netAmount' => $net, 'paidAmount' => $paid, 'fineAmount' => $fine,
                'feeHead' => 'Transport Fee', 'demandId' => 'DEM_X', 'studentName' => 'A Student'];
    }

    // ══ DETECTION ═══════════════════════════════════════════════════════════

    /** The runtime scenario: paid 12,000, liability falls to 5,000. */
    public function test_an_overpayment_raises_a_pending_obligation_for_the_exact_excess(): void
    {
        [$txn, $fb] = $this->fakes();
        $r = $this->svc($txn, $fb)->reconcile('STU1', 'Transport', $this->demand(5000, 12000));

        $this->assertSame('created', $r['outcome']);
        $this->assertSame(7000.0, $r['excess'], 'the excess is paid minus what is now owed');

        $doc = $txn->writes['ref_auto_transport_STU1_2026-27'] ?? null;
        $this->assertNotNull($doc, 'the obligation must land in the canonical refunds queue');
        $this->assertSame(7000.0, $doc['amount']);
        $this->assertSame('pending', $doc['status'],
            'raised for review — this service never approves or moves money itself');
        $this->assertSame(Overpayment_service::SOURCE, $doc['source'],
            'marked automatic so it is never mistaken for a human request');

        // The figures it was derived from, so a reviewer need not re-derive them.
        $this->assertSame(12000.0, $doc['basisPaid']);
        $this->assertSame(5000.0,  $doc['basisNet']);
    }

    /** A fine is part of what is owed and must not be refunded away. */
    public function test_an_outstanding_fine_reduces_the_excess(): void
    {
        [$txn, $fb] = $this->fakes();
        $r = $this->svc($txn, $fb)->reconcile('STU1', 'Transport', $this->demand(5000, 12000, 500));

        $this->assertSame(6500.0, $r['excess'], 'owed = net 5,000 + fine 500');
    }

    public function test_no_excess_raises_nothing(): void
    {
        [$txn, $fb] = $this->fakes();

        $this->assertSame('none', $this->svc($txn, $fb)->reconcile('STU1', 'Transport', $this->demand(8000, 8000))['outcome']);
        $this->assertSame('none', $this->svc($txn, $fb)->reconcile('STU1', 'Transport', $this->demand(8000, 3000))['outcome'],
            'a part-paid student owes money — that is a balance, not a refund');
        $this->assertSame([], $txn->writes);
    }

    /** Sub-paisa noise must not raise an obligation. */
    public function test_a_rounding_sliver_is_not_an_overpayment(): void
    {
        [$txn, $fb] = $this->fakes();
        $r = $this->svc($txn, $fb)->reconcile('STU1', 'Transport', $this->demand(5000.00, 5000.004));

        $this->assertSame('none', $r['outcome']);
        $this->assertSame([], $txn->writes);
    }

    // ══ IDEMPOTENCY ═════════════════════════════════════════════════════════

    /**
     * create_refund() mints uniqid('ref_') — correct for a person, where two
     * clicks are two intents. Catastrophic here, where every recomputation and
     * every retry of one would raise ANOTHER refund for the same money.
     */
    public function test_the_obligation_id_is_deterministic_per_student_module_and_session(): void
    {
        [$txn, $fb] = $this->fakes();
        $this->svc($txn, $fb)->reconcile('STU1', 'Transport', $this->demand(5000, 12000));

        $this->assertSame(['ref_auto_transport_STU1_2026-27'], array_keys($txn->writes),
            'a non-deterministic id would let one reduction be refunded many times');
    }

    /** Re-running against an existing PENDING row updates it, never adds one. */
    public function test_recomputing_updates_the_pending_obligation_rather_than_adding_another(): void
    {
        [$txn, $fb] = $this->fakes(['status' => 'pending', 'amount' => 7000.0,
                                    'requestedDate' => '2026-08-01T00:00:00+05:30']);
        $r = $this->svc($txn, $fb)->reconcile('STU1', 'Transport', $this->demand(2000, 12000));

        $this->assertSame('updated', $r['outcome']);
        $this->assertCount(1, $txn->writes);
        $this->assertSame(10000.0, $txn->writes['ref_auto_transport_STU1_2026-27']['amount'],
            'nobody has acted on a pending row, so keeping it in step with the current excess '
            . 'is safe — and leaving it stale would refund the wrong figure');
        $this->assertSame('2026-08-01T00:00:00+05:30',
            $txn->writes['ref_auto_transport_STU1_2026-27']['requestedDate'],
            'the original request date survives; this is the same obligation, revised');
    }

    // ══ THE STATE MACHINE ═══════════════════════════════════════════════════

    /**
     * Once a human has authorised a figure it is history. Editing it would
     * rewrite an authorised financial fact; re-raising it would refund the same
     * money twice.
     */
    public function test_an_authorised_obligation_is_frozen(): void
    {
        foreach (['approved', 'processed', 'completed'] as $status) {
            [$txn, $fb] = $this->fakes(['status' => $status, 'amount' => 7000.0]);
            $r = $this->svc($txn, $fb)->reconcile('STU1', 'Transport', $this->demand(5000, 12000));

            $this->assertSame('frozen', $r['outcome'], "status {$status} must be immutable");
            $this->assertSame([], $txn->writes, 'an authorised refund must never be rewritten');
        }
    }

    /**
     * A FURTHER reduction after authorisation is reported, not minted. A second
     * automatic refund against one charge is exactly the shape a duplicate-money
     * bug takes, so it gets a human's eyes.
     */
    public function test_further_excess_after_authorisation_is_reported_not_minted(): void
    {
        [$txn, $fb] = $this->fakes(['status' => 'approved', 'amount' => 7000.0]);
        $r = $this->svc($txn, $fb)->reconcile('STU1', 'Transport', $this->demand(2000, 12000));

        $this->assertSame('residual_reported', $r['outcome']);
        $this->assertSame(3000.0, $r['excess'], 'excess 10,000 minus the 7,000 already authorised');
        $this->assertSame([], $txn->writes, 'no second refund is created automatically');
    }

    /**
     * THE ONE THAT PROTECTS THE SCHOOL. A suspension that is resumed makes the
     * money payable again. A stale pending refund would pay out what the family
     * now owes.
     */
    public function test_a_pending_obligation_is_cancelled_when_the_liability_returns(): void
    {
        [$txn, $fb] = $this->fakes(['status' => 'pending', 'amount' => 7000.0]);
        $r = $this->svc($txn, $fb)->reconcile('STU1', 'Transport', $this->demand(12000, 12000));

        $this->assertSame('cancelled', $r['outcome']);
        $doc = $txn->writes['ref_auto_transport_STU1_2026-27'];
        $this->assertSame('cancelled', $doc['status']);
        $this->assertSame(0.0, $doc['amount'],
            'a cancelled obligation must carry no amount — a queue that still shows 7,000 '
            . 'invites someone to approve it');
    }

    /** An authorised refund is NOT cancelled by a later rise — that money is gone. */
    public function test_an_authorised_refund_is_not_cancelled_by_a_later_rise(): void
    {
        [$txn, $fb] = $this->fakes(['status' => 'approved', 'amount' => 7000.0]);
        $r = $this->svc($txn, $fb)->reconcile('STU1', 'Transport', $this->demand(12000, 12000));

        $this->assertSame('frozen', $r['outcome']);
        $this->assertSame([], $txn->writes);
    }

    // ══ SAFETY ══════════════════════════════════════════════════════════════

    /** Detection must never be able to fail the service change that revealed it. */
    public function test_a_write_failure_is_reported_not_thrown(): void
    {
        $txn = new class { public function writeRefund(string $r, array $d): bool { return false; } };
        $fb  = new class { public function firestoreGet(string $c, string $i) { return null; } };

        $r = (new Overpayment_service())->init($txn, $fb, 'SCH_T1', '2026-27', 'SSA9001')
                ->reconcile('STU1', 'Transport', $this->demand(5000, 12000));

        $this->assertSame('none', $r['outcome'], 'a failed write is logged, never thrown — the '
            . 'liability reduction is correct on its own and must not roll back');
    }

    public function test_an_uninitialised_service_does_nothing(): void
    {
        $r = (new Overpayment_service())->reconcile('STU1', 'Transport', $this->demand(5000, 12000));
        $this->assertSame('none', $r['outcome']);
    }

    /** This service raises obligations. It must never authorise or pay one. */
    public function test_it_never_approves_or_moves_money(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../application/libraries/Overpayment_service.php');

        foreach (['approve', 'process(', 'refundMode\' => \'cash', 'journal'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden . '(', $src . '(',
                "the detector reached '{$forbidden}'. Money must leave only through the existing "
                . 'maker-checker route, so that a human authorises every rupee that goes back.');
        }
        $this->assertStringContainsString("'status'      => 'pending'", $src);
    }

    /**
     * THE WIRING. A perfect detector that nothing calls detects nothing — and
     * the symptom would be invisible: demands would still be correct, balances
     * would still floor at zero, and the excess would go back to vanishing
     * exactly as before.
     *
     * The hook sits in Transport_service's single demand writer, which is the
     * one place every transport liability change passes through.
     */
    public function test_the_transport_demand_writer_reconciles_overpayment(): void
    {
        $svc = (string) file_get_contents(__DIR__ . '/../../application/libraries/Transport_service.php');

        $at = strpos($svc, 'private function _writeTransportDemand');
        $this->assertNotFalse($at, 'the single demand writer no longer exists');

        $this->assertStringContainsString('$this->_reconcileOverpayment($studentId, $demandRef);',
            substr($svc, $at, 1800),
            'every transport liability change must reconcile any excess. Without this the money '
            . 'silently disappears again and nothing in the UI or the data would show it.');

        $this->assertStringContainsString('Overpayment_service', $svc,
            'reconciliation must go through the shared detector, not a local copy of the rule');
    }
}
