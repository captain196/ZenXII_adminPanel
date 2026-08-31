<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Overpayment_service — EXCESS MONEY MUST NEVER SILENTLY DISAPPEAR.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  THE DEFECT THIS CLOSES
 * ─────────────────────────────────────────────────────────────────────────
 *  When a liability is legitimately REDUCED after payment — a student stops
 *  transport in February having paid the whole year up front — Fee_lifecycle
 *  recomputes the balance as:
 *
 *      $balance = max(0.0, round($net + $fineSoFar - $paidSoFar, 2));
 *
 *  The floor is right: a balance must never go negative. But nothing else
 *  happened. A family that paid 12,000 for a service now correctly costing
 *  6,000 saw a balance of 0 and no trace of the other 6,000. No receipt was
 *  altered and no record was corrupted — the money simply stopped being
 *  visible anywhere.
 *
 *  That is not a neutral gap. Indian fee regulation is explicit that a school
 *  may retain a nominal admission charge but NOT advance tuition for classes
 *  the student did not attend, and tuition deductions are pro-rata. "Keep it
 *  quietly" is not one of the options a school gets to choose.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  WHY THIS IS NOT A NEW REFUND SUBSYSTEM
 * ─────────────────────────────────────────────────────────────────────────
 *  ZenX already has the whole canonical chain — `feeRefunds` with
 *  pending → approved/rejected → processed, journal posting, locks and a
 *  retry path. It is reused verbatim: this service only DETECTS the excess
 *  and raises the same pending obligation a human would raise, in the same
 *  collection, through the same writer (Fee_firestore_txn::writeRefund).
 *
 *  It deliberately does NOT approve or process anything. Money leaves through
 *  the existing maker-checker route and nowhere else. Creation and approval
 *  endpoints live in Fee_management.php, which is at a parked commit boundary
 *  this workstream must not disturb — reusing the collection and the writer,
 *  rather than that controller, is what keeps the boundary intact.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  IDEMPOTENCY — THE PART A HUMAN PATH DOES NOT NEED
 * ─────────────────────────────────────────────────────────────────────────
 *  create_refund() mints `uniqid('ref_')`. Correct for a person: two clicks
 *  are two intents. Catastrophic for an automatic path, where every
 *  recomputation — and every retry of one — would raise ANOTHER refund for
 *  the same money.
 *
 *  So an automatic obligation has a DETERMINISTIC id, one per
 *  (school, student, module, session). Re-running collapses onto that single
 *  document instead of multiplying it.
 *
 *  While it is still `pending` the amount is kept in step with the current
 *  excess, because nobody has acted on it yet. Once a human has approved or
 *  processed it the row is FROZEN: what is already authorised is history, and
 *  only any FURTHER excess beyond it raises a new obligation.
 *
 *  And if the reduction is undone — a suspension resumed, so the money is owed
 *  again — a still-pending obligation is CANCELLED rather than left to refund
 *  money the family no longer has coming.
 */
class Overpayment_service
{
    /** Automatic obligations are marked so they are never confused with a human request. */
    public const SOURCE = 'auto_liability_reduction';

    /** @var object|null Fee_firestore_txn */
    private $fsTxn;
    /** @var object|null raw firebase client, for reads */
    private $firebase;

    private string $schoolId = '';
    private string $session  = '';
    private string $actorId  = '';
    private bool   $ready    = false;

    public function init($fsTxn, $firebase, string $schoolId, string $session, string $actorId): self
    {
        $this->fsTxn    = $fsTxn;
        $this->firebase = $firebase;
        $this->schoolId = trim($schoolId);
        $this->session  = trim($session);
        $this->actorId  = trim($actorId) !== '' ? trim($actorId) : 'system';
        $this->ready    = ($fsTxn !== null && $firebase !== null && $this->schoolId !== '');
        return $this;
    }

    /** One obligation per student per module per session. */
    private function refundId(string $studentId, string $moduleType): string
    {
        return 'ref_auto_' . strtolower($moduleType) . '_' . $studentId . '_' . $this->session;
    }

    /**
     * Reconcile the refund obligation for ONE module charge after its
     * liability changed.
     *
     * @param array $demand the demand AFTER recalculation
     * @return array{outcome: string, excess: float, refundId: string}
     */
    public function reconcile(string $studentId, string $moduleType, array $demand): array
    {
        $none = ['outcome' => 'none', 'excess' => 0.0, 'refundId' => ''];
        if (!$this->ready || trim($studentId) === '') return $none;

        try {
            $net    = round((float) ($demand['netAmount'] ?? 0), 2);
            $fine   = round((float) ($demand['fineAmount'] ?? $demand['fine_amount'] ?? 0), 2);
            $paid   = round((float) ($demand['paidAmount'] ?? $demand['paid_amount'] ?? 0), 2);
            $excess = round($paid - ($net + $fine), 2);

            $refId    = $this->refundId($studentId, $moduleType);
            $existing = $this->firebase->firestoreGet('feeRefunds', "{$this->schoolId}_{$refId}");
            $existing = is_array($existing) ? $existing : null;
            $status   = $existing !== null ? strtolower(trim((string) ($existing['status'] ?? ''))) : '';

            // ── Already authorised or paid out: FROZEN ──────────────────────
            // A human decided on this figure. Editing it now would rewrite an
            // authorised financial fact, and re-raising the same money would
            // refund it twice. Only excess BEYOND what is already on the books
            // can create a new obligation, and that needs its own document —
            // reported rather than minted here, because a second automatic
            // refund against one charge is exactly the shape a duplicate-money
            // bug takes, and it deserves a human's eyes.
            // ── REFUND-REJ-1 · A REJECTION IS A HUMAN DECISION ──────────────
            //
            // `rejected` used to fall straight through to the "raise or refresh"
            // branch below, so the next recalculation re-minted the obligation
            // as PENDING. A reviewer's explicit refusal was therefore overturned
            // by a background job, and the refund could then be approved and
            // paid — money the school had already declined to return.
            //
            // Note the deliberate contrast with `cancelled`, which DOES re-raise
            // below and should: cancellation is the SYSTEM's own disposal ("the
            // liability rose again, no excess remains"), not a judgement, so
            // fresh excess legitimately creates a fresh obligation.
            //
            // Reported, never silently dropped: the excess is real and the
            // school must be able to see it. Same treatment as a residual after
            // authorisation — surfaced for a human, not minted automatically.
            if ($status === 'rejected') {
                log_message('error', 'ACC_OVERPAYMENT_AFTER_REJECTION school=[' . $this->schoolId
                    . '] student=[' . $studentId . '] module=[' . $moduleType
                    . '] excess=[' . $excess . '] refund=[' . $refId . '] — this refund was '
                    . 'REJECTED by a reviewer and the excess still stands. It is NOT re-raised '
                    . 'automatically; reopen it deliberately if the rejection was wrong.');
                return ['outcome' => 'rejected_upheld', 'excess' => $excess, 'refundId' => $refId];
            }

            if (in_array($status, ['approved', 'processed', 'completed'], true)) {
                $already   = round((float) ($existing['amount'] ?? 0), 2);
                $remaining = round($excess - $already, 2);
                if ($remaining > 0.005) {
                    log_message('error', 'ACC_OVERPAYMENT_RESIDUAL school=[' . $this->schoolId
                        . '] student=[' . $studentId . '] module=[' . $moduleType
                        . '] already_refunded=[' . $already . '] further_excess=[' . $remaining
                        . '] — the liability fell again after a refund was authorised. Raise a '
                        . 'second refund manually; this is not minted automatically.');
                    return ['outcome' => 'residual_reported', 'excess' => $remaining, 'refundId' => $refId];
                }
                return ['outcome' => 'frozen', 'excess' => $excess, 'refundId' => $refId];
            }

            // ── No excess ───────────────────────────────────────────────────
            if ($excess <= 0.005) {
                // A pending obligation that is no longer owed must not survive:
                // a suspension that was resumed makes the money payable again,
                // and a stale row would refund what the family now owes.
                if ($status === 'pending') {
                    $this->fsTxn->writeRefund($refId, [
                        'status'    => 'cancelled',
                        'amount'    => 0.0,
                        'remarks'   => 'Superseded: the liability rose again, so no excess remains.',
                        'cancelledBy'   => $this->actorId,
                        'cancelledDate' => date('c'),
                    ]);
                    return ['outcome' => 'cancelled', 'excess' => 0.0, 'refundId' => $refId];
                }
                return $none;
            }

            // ── Raise or refresh a PENDING obligation ───────────────────────
            // Same collection, same shape, same writer a human request uses, so
            // it lands in the existing refunds queue and follows the existing
            // approve -> process route. Nothing here moves money.
            $doc = [
                'refundId'    => $refId,
                'studentId'   => $studentId,
                'studentName' => (string) ($demand['studentName'] ?? ''),
                'className'   => (string) ($demand['className'] ?? ''),
                'section'     => (string) ($demand['section'] ?? ''),
                'amount'      => $excess,
                'feeTitle'    => (string) ($demand['feeHead'] ?? ($moduleType . ' Fee')),
                'reason'      => $moduleType . ' liability reduced after payment — '
                               . 'service billed for the period actually used. Excess held for refund.',
                'status'      => 'pending',
                'source'      => self::SOURCE,
                'moduleType'  => $moduleType,
                'demandId'    => (string) ($demand['demandId'] ?? ''),
                // The figures this obligation was derived from, so a reviewer can
                // check the arithmetic without re-deriving it.
                'basisPaid'      => $paid,
                'basisNet'       => $net,
                'basisFine'      => $fine,
                'requestedDate'  => $existing['requestedDate'] ?? date('c'),
                'requestedBy'    => $this->actorId,
                'reviewedDate'   => '',
                'processedDate'  => '',
                'reviewedBy'     => '',
                'processedBy'    => '',
                'refundMode'     => '',
            ];

            if (!$this->fsTxn->writeRefund($refId, $doc)) {
                log_message('error', 'ACC_OVERPAYMENT_WRITE_FAILED school=[' . $this->schoolId
                    . '] student=[' . $studentId . '] excess=[' . $excess . ']');
                return $none;
            }

            log_message('error', 'ACC_OVERPAYMENT_DETECTED school=[' . $this->schoolId
                . '] student=[' . $studentId . '] module=[' . $moduleType . '] paid=[' . $paid
                . '] now_owed=[' . round($net + $fine, 2) . '] excess=[' . $excess
                . '] refund=[' . $refId . '] status=[pending] — awaiting the normal approval route.');

            return ['outcome' => $status === 'pending' ? 'updated' : 'created',
                    'excess'  => $excess, 'refundId' => $refId];
        } catch (\Throwable $e) {
            // Diagnostic-safe: detecting an overpayment must never be able to
            // fail the service change that revealed it. The reduction is
            // correct on its own; a missed obligation is logged loudly and is
            // recoverable, whereas a rolled-back reduction is not.
            log_message('error', "ACC_OVERPAYMENT_RECONCILE_FAILED [{$studentId}/{$moduleType}]: " . $e->getMessage());
            return $none;
        }
    }
}
