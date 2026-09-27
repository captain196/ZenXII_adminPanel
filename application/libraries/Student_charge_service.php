<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  STUDENT CHARGES — A ONE-OFF CHARGE AGAINST ONE STUDENT
 * ══════════════════════════════════════════════════════════════════════════
 *
 * A lost library book. A broken window. A trip contribution. Today a school can
 * only raise these by creating a fee head that applies to a whole CLASS — so a
 * charge meant for one child is billed to thirty, or is not billed at all and
 * is collected in cash off the books.
 *
 * ── THIS IS A GENERALISATION, NOT A NEW ENGINE ────────────────────────────
 * The pattern already exists and is proven: `Sis::_maybe_charge_id_card_
 * replacement()` raises a per-student one-off through `Id_card_fee_policy` and
 * `Fee_lifecycle::createModuleFee()`, described there as *"exactly as Transport
 * and Hostel do — no second fee engine, no second balance formula."*
 *
 * This class does the same thing for an arbitrary reason. It deliberately
 * inherits every decision that implementation already got right:
 *
 *   ID-D2     `chargeInstance` makes each charge its OWN demand. Without it a
 *             second "Lost Book" in the same period would silently REWRITE the
 *             first, and the school would lose the money with no error.
 *   ID-DUE-1  a one-off MUST carry a dueDate. `createModuleFee` stamps none,
 *             and a dateless demand is invisible to BOTH the reminder service
 *             and the late-fee engine — it sits unpaid and unchased forever.
 *   M5        the fee head must already be REGISTERED. An unregistered head
 *             fails closed inside createModuleFee ("no runtime minting"),
 *             which is what stops anonymous income heads appearing with no
 *             chart-of-accounts mapping. This class does not work around it.
 *
 * ── WHAT IT DELIBERATELY DOES NOT DO ──────────────────────────────────────
 * It keeps NO payment state. Paid, balance and status live on the demand, in
 * the canonical pipeline. A charge object tracking its own balance would be a
 * second billing engine and a second thing to keep correct — the exact failure
 * this codebase has spent so long removing elsewhere.
 *
 * Downstream nothing changes: the Parent app already renders any `feeDemands`
 * row, the payment path collects any demand, and accounting journals any
 * receipt. Convergence is achieved by REUSING the generator, not by extending
 * the pipeline.
 */
class Student_charge_service
{
    /** Module label — groups these demands and keeps them greppable. */
    const MODULE = 'StudentCharge';

    /** Days a family gets to pay a one-off before it is overdue. */
    const DEFAULT_DUE_DAYS = 30;

    const COL = 'studentCharges';

    private $firebase   = null;
    private $feeLifecycle = null;
    private string $schoolId = '';
    private string $session  = '';
    private string $actorId  = '';

    public function init($firebase, $feeLifecycle, string $schoolId, string $session, string $actorId = 'system'): self
    {
        $this->firebase     = $firebase;
        $this->feeLifecycle = $feeLifecycle;
        $this->schoolId     = $schoolId;
        $this->session      = $session;
        $this->actorId      = $actorId;
        return $this;
    }

    /**
     * Raise a charge against one student.
     *
     * @param array $in studentId, feeHead (REGISTERED), amount, reason,
     *                  optional dueDays
     * @return array{ok:bool, chargeId?:string, demandId?:string, error?:string}
     */
    public function raise(array $in): array
    {
        if ($this->firebase === null || $this->feeLifecycle === null) {
            return ['ok' => false, 'error' => 'Charge service is not initialised.'];
        }

        $studentId = trim((string) ($in['studentId'] ?? ''));
        $feeHead   = trim((string) ($in['feeHead']   ?? ''));
        $reason    = trim((string) ($in['reason']    ?? ''));
        $amount    = round((float) ($in['amount'] ?? 0), 2);

        if ($studentId === '') return ['ok' => false, 'error' => 'A student is required.'];
        if ($feeHead === '')   return ['ok' => false, 'error' => 'A fee head is required.'];

        // A charge against a family must say WHAT FOR. Research on school fee
        // policy is consistent that the guardian receives written notice of the
        // nature of the damage — "Lost Book" is a head, not an explanation, and
        // an unexplained charge is indistinguishable from a mistake.
        if ($reason === '') {
            return ['ok' => false, 'error' => 'A reason is required — the family must be told what this charge is for.'];
        }

        // Zero is not "free", it is a mistake: createModuleFee returns null for
        // amount <= 0, which would look like a silent success here.
        if ($amount <= 0) {
            return ['ok' => false, 'error' => 'A charge amount must be greater than zero.'];
        }

        // ID-D2 — the charge's OWN id becomes the demand's chargeInstance, so
        // two charges on the same head in the same period stay two demands.
        $chargeId = 'SC_' . strtoupper(bin2hex(random_bytes(8)));

        $dueDays = (int) ($in['dueDays'] ?? self::DEFAULT_DUE_DAYS);
        if ($dueDays < 0) $dueDays = self::DEFAULT_DUE_DAYS;
        $dueDate = date('Y-m-d', strtotime("+{$dueDays} days"));

        // ── The canonical generator does the money ────────────────────────
        // An unregistered head fails closed INSIDE this call (M5) and returns
        // null. That refusal is correct and is surfaced, not worked around.
        try {
            $demandId = $this->feeLifecycle->createModuleFee($studentId, self::MODULE, [
                'fee_head'       => $feeHead,
                'chargeInstance' => $chargeId,
                'amount'         => $amount,
                'frequency'      => 'One-time',
                'dueDate'        => $dueDate,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Student_charge_service::raise createModuleFee threw for student ['
                . $studentId . '] head [' . $feeHead . ']: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'The charge could not be raised. Nothing has been billed.'];
        }

        if ($demandId === null || $demandId === '') {
            // Almost always an unregistered fee head (M5 fail-closed). Say so,
            // because "it didn't work" sends an operator hunting in the wrong
            // place.
            log_message('error', 'ACC_STUDENT_CHARGE_REFUSED student=[' . $studentId
                . '] head=[' . $feeHead . '] amount=[' . $amount . '] — createModuleFee returned null; '
                . 'the fee head is most likely not registered (M5 fails closed).');
            return [
                'ok'    => false,
                'error' => 'The charge was refused. Check that "' . $feeHead
                         . '" is a registered fee head for this school.',
            ];
        }

        // The record exists for the AUDIT and the WAIVER path — never for the
        // money. Written after the demand: if this write fails the family has
        // still been correctly billed, which is the safer direction.
        $now = date('c');
        $doc = [
            'chargeId'  => $chargeId,
            'schoolId'  => $this->schoolId,
            'session'   => $this->session,
            'studentId' => $studentId,
            'feeHead'   => $feeHead,
            'amount'    => $amount,
            'reason'    => $reason,
            'dueDate'   => $dueDate,
            'demandId'  => $demandId,
            'status'    => 'raised',
            'raisedBy'  => $this->actorId,
            'raisedAt'  => $now,
            'updatedAt' => $now,
        ];

        try {
            $this->firebase->firestoreSet(self::COL, $this->schoolId . '_' . $chargeId, $doc);
        } catch (\Throwable $e) {
            log_message('error', 'Student_charge_service: demand [' . $demandId
                . '] was raised but its charge record could not be written: ' . $e->getMessage());
        }

        return ['ok' => true, 'chargeId' => $chargeId, 'demandId' => $demandId];
    }

    /**
     * Waive a charge that has NOT been paid.
     *
     * Research on school fee policy is consistent that a charge against a
     * family is appealable, so a raised charge must be reversible by someone
     * accountable — with a reason, recorded.
     *
     * ── WHY A PAID CHARGE IS REFUSED ──────────────────────────────────────
     * Money has already moved: there is a receipt, an allocation and a journal
     * entry. Zeroing the demand would strand all three and leave the books
     * claiming income that was reversed with no counter-entry. Returning money
     * is a REFUND — a different operation with its own authorization, gateway
     * call and accounting reversal — and this method must not quietly become a
     * second way to do it.
     */
    public function waive(string $chargeId, string $reason): array
    {
        if ($this->firebase === null) {
            return ['ok' => false, 'error' => 'Charge service is not initialised.'];
        }
        $chargeId = trim($chargeId);
        $reason   = trim($reason);

        if ($chargeId === '') return ['ok' => false, 'error' => 'A charge is required.'];
        if ($reason === '')   return ['ok' => false, 'error' => 'A reason is required to waive a charge.'];

        $docId  = $this->schoolId . '_' . $chargeId;
        $charge = null;
        try {
            $charge = $this->firebase->firestoreGet(self::COL, $docId);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'The charge could not be read. Nothing has been changed.'];
        }

        if (!is_array($charge)) {
            return ['ok' => false, 'error' => 'Charge not found.'];
        }

        // Tenant boundary. The doc id is already school-scoped, but a caller
        // that constructed the id from user input must not be able to reach
        // another school's charge by guessing.
        if ((string) ($charge['schoolId'] ?? '') !== $this->schoolId) {
            log_message('error', 'Student_charge_service::waive cross-tenant attempt — charge ['
                . $chargeId . '] belongs to [' . (string) ($charge['schoolId'] ?? '')
                . '] but the caller is [' . $this->schoolId . ']');
            return ['ok' => false, 'error' => 'Charge not found.'];
        }

        if ((string) ($charge['status'] ?? '') === 'waived') {
            return ['ok' => true, 'chargeId' => $chargeId, 'alreadyWaived' => true];
        }

        // ── Refuse if any money has been collected ────────────────────────
        $demandId = (string) ($charge['demandId'] ?? '');
        if ($demandId !== '') {
            try {
                $demand = $this->firebase->firestoreGet('feeDemands', $demandId);
            } catch (\Throwable $e) {
                // Unreadable demand: refuse. Waiving without knowing whether it
                // was paid could strand a receipt.
                return ['ok' => false, 'error' => 'The linked demand could not be read. Nothing has been changed.'];
            }

            $paid = (float) ($demand['paidAmount'] ?? $demand['paid_amount'] ?? 0);
            if ($paid > 0.005) {
                return [
                    'ok'    => false,
                    'error' => 'This charge has already been paid. Raise a refund instead — '
                             . 'waiving would leave the receipt and its accounting entry stranded.',
                ];
            }
        }

        $now = date('c');

        // ── WAIVE-RACE-1 · THE PAID CHECK ABOVE IS NOT ENOUGH ON ITS OWN ───
        //
        // That check is a plain read, and what followed it were two
        // unconditional merge writes. Between the two, a parent can pay:
        //
        //   T1 admin waives    reads the demand, paidAmount = 0, guard passes
        //   T2 parent pays     paidAmount = 450, balance = 0, status 'paid'
        //   T1 continues       writes gross 0 / net 0 / balance 0 / 'archived'
        //
        // The family has paid, a receipt and a journal entry exist, and the
        // demand now asserts the charge was never owed. That is precisely the
        // outcome the guard's own refusal message names — "waiving would leave
        // the receipt and its accounting entry stranded" — so the check
        // expressed the right rule and could not enforce it.
        //
        // Enforced now with the same optimistic-concurrency primitive the
        // payment claim uses: capture the demand's `__updateTime` at the read
        // above and commit under a `currentDocument.updateTime` precondition.
        // A payment landing in between moves that updateTime, the whole commit
        // is rejected (FAILED_PRECONDITION), and NOTHING is written.
        //
        // ── IT ALSO CLOSES A PARTIAL-FAILURE WINDOW ───────────────────────
        // The charge was marked 'waived' first and the demand zeroed second, so
        // a failure between them left the charge waived while the family still
        // owed the money. The code knew — it logged
        // ACC_STUDENT_CHARGE_WAIVER_DEMAND_STALE and told the admin to retry —
        // but the inconsistency was already live by then. Both writes now ride
        // ONE batch, which Firestore applies atomically: both or neither.
        $ops = [[
            'op'         => 'update',
            'collection' => self::COL,
            'docId'      => $docId,
            'merge'      => true,
            'data'       => [
                'status'       => 'waived',
                'waivedBy'     => $this->actorId,
                'waivedReason' => $reason,
                'waivedAt'     => $now,
                'updatedAt'    => $now,
            ],
        ]];

        if ($demandId !== '') {
            // FAIL CLOSED on a missing updateTime. Without it there is no
            // precondition to commit under, and writing anyway would restore
            // exactly the race this block exists to remove — silently, which is
            // the worst version. A demand that cannot be read atomically is a
            // demand that must not be waived automatically.
            $demandUpdateTime = (string) ($demand['__updateTime'] ?? '');
            if ($demandUpdateTime === '') {
                log_message('error', 'ACC_STUDENT_CHARGE_WAIVER_NO_CAS charge=[' . $chargeId
                    . '] demand=[' . $demandId . '] — the demand carried no __updateTime, so the '
                    . 'waiver could not be made atomic against a concurrent payment. Refused.');
                return ['ok' => false, 'error' => 'This charge cannot be waived safely right now. Please retry.'];
            }

            $ops[] = [
                'op'           => 'update',
                'collection'   => 'feeDemands',
                'docId'        => $demandId,
                'merge'        => true,
                'precondition' => ['updateTime' => $demandUpdateTime],
                'data'         => [
                    // ── PARENT-SC1 · THE STATUS MUST BE ONE THE APP UNDERSTANDS ──
                    // A waived demand has to LEAVE the family's dues surface. The
                    // Parent app decides that with two filters:
                    //
                    //   server-side  .whereNotEqualTo("status", "archived")
                    //   client-side  demands.filter { it.status != "paid" }
                    //
                    // A status of 'waived' passes BOTH — so the charge would sit
                    // in the parent's pending dues forever showing zero, and
                    // would still be offered for selection. Inventing a status
                    // the consumer has never heard of is how a backend change
                    // becomes a client-visible defect.
                    //
                    // 'archived' is not a workaround here, it is the existing
                    // meaning: the app's own comment defines archived rows as
                    // historical records preserved for refund/receipt audit
                    // lineage that do not belong in the current dues surface.
                    // That is exactly a waived charge.
                    //
                    // Chosen over 'paid' deliberately: no money was received, and
                    // marking it paid would make a waiver indistinguishable from
                    // a collection in every downstream report.
                    'grossAmount'    => 0.0,
                    'netAmount'      => 0.0,
                    'discountAmount' => 0.0,
                    'balance'        => 0.0,
                    'status'         => 'archived',
                    'waived'         => true,
                    'waivedReason'   => $reason,
                    'waivedAt'       => $now,
                    'updatedAt'      => $now,
                ],
            ];
        }

        $res    = $this->firebase->firestoreCommitBatchStatus($ops);
        $status = (string) ($res['status'] ?? 'FAILURE');

        if ($status === 'PRECONDITION_CONFLICT') {
            // The demand moved between the paid check and this commit. A
            // payment is the overwhelmingly likely cause, and it is the one
            // that matters, so say so rather than reporting a generic clash.
            // Nothing was written — the batch is atomic.
            log_message('error', 'ACC_STUDENT_CHARGE_WAIVER_RACE charge=[' . $chargeId
                . '] demand=[' . $demandId . '] — the demand changed between the paid check and '
                . 'the waiver commit; refused so a paid charge cannot be zeroed.');
            return [
                'ok'    => false,
                'error' => 'This charge changed while the waiver was being applied — a payment '
                         . 'may have just been received. Nothing has been changed. Please '
                         . 'reload and check whether a refund is needed instead.',
            ];
        }

        if ($status !== 'SUCCESS') {
            return ['ok' => false, 'error' => 'The waiver could not be recorded. Nothing has been changed.'];
        }

        return ['ok' => true, 'chargeId' => $chargeId, 'demandId' => $demandId];
    }
}
