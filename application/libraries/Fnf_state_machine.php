<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Full & Final Settlement — lifecycle state machine.
 *
 * PURE LOGIC. No Firestore, no CI dependencies, no money. This class answers one
 * question — "is this transition legal, and if not, why not?" — so the rules can
 * be exercised exhaustively in unit tests before any persistence exists.
 *
 * It deliberately contains NO financial computation. Gratuity, leave
 * encashment, notice recovery, statutory treatment and deduction priority are
 * unresolved owner policy (D-002) and must not be inferred here. This machine
 * governs only WHEN a settlement may move, never WHAT it is worth.
 *
 *      (none) --initiate--> initiated
 *                               |
 *                          compute (idempotent, repeatable)
 *                               v
 *                           calculated <------------+
 *                               |                   |
 *                        submitForReview            | reject
 *                               v                   |
 *                            inReview --------------+
 *                               |
 *                           approve
 *                               v
 *                            approved
 *                               |
 *                         recordPayment
 *                               v
 *                             paid
 *                               |
 *                             close
 *                               v
 *                            closed          (terminal)
 *
 *      cancel: legal from initiated / calculated / inReview / approved
 *              REFUSED from paid / closed — money has moved, so the correction
 *              is a new compensating settlement, never a mutation of this one.
 *
 * Design rationale for the two properties that matter most:
 *
 *   TERMINALITY. `paid` and `closed` accept nothing. This mirrors the
 *   accounting module's existing posture: a historical record that money moved
 *   is never rewritten.
 *
 *   RECOMPUTE BEFORE REVIEW. `compute` is legal from both `initiated` and
 *   `calculated`, because clearance flags and final attendance land late and a
 *   settlement must be refreshable until someone is asked to review it. Once it
 *   reaches `inReview` the figure is frozen; changing it requires a reject
 *   back to `calculated` so the reviewer is never shown a number that silently
 *   changed underneath them.
 *
 * See STAFF_FNF_SETTLEMENT_DESIGN.md for the document model and the D-002
 * decision matrix.
 */
class Fnf_state_machine
{
    const S_INITIATED  = 'initiated';
    const S_CALCULATED = 'calculated';
    const S_IN_REVIEW  = 'inReview';
    const S_APPROVED   = 'approved';
    const S_PAID       = 'paid';
    const S_CLOSED     = 'closed';
    const S_CANCELLED  = 'cancelled';

    const E_INITIATE       = 'initiate';
    const E_COMPUTE        = 'compute';
    const E_SUBMIT_REVIEW  = 'submitForReview';
    const E_APPROVE        = 'approve';
    const E_REJECT         = 'reject';
    const E_RECORD_PAYMENT = 'recordPayment';
    const E_CLOSE          = 'close';
    const E_CANCEL         = 'cancel';

    /** Money has moved — nothing may follow. */
    const TERMINAL = [self::S_PAID, self::S_CLOSED, self::S_CANCELLED];

    /** A settlement occupying the single open slot for a staff member. */
    const OPEN = [
        self::S_INITIATED, self::S_CALCULATED, self::S_IN_REVIEW, self::S_APPROVED,
    ];

    /** Notice-period states from which a settlement may be initiated at all. */
    const NOTICE_SETTLEABLE = ['approved', 'waived', 'reduced', 'completed'];

    /** from => [event => to]. The single source of truth for legal moves. */
    const TRANSITIONS = [
        ''                 => [self::E_INITIATE       => self::S_INITIATED],
        self::S_INITIATED  => [self::E_COMPUTE        => self::S_CALCULATED,
                               self::E_CANCEL         => self::S_CANCELLED],
        self::S_CALCULATED => [self::E_COMPUTE        => self::S_CALCULATED,
                               self::E_SUBMIT_REVIEW  => self::S_IN_REVIEW,
                               self::E_CANCEL         => self::S_CANCELLED],
        self::S_IN_REVIEW  => [self::E_APPROVE        => self::S_APPROVED,
                               self::E_REJECT         => self::S_CALCULATED,
                               self::E_CANCEL         => self::S_CANCELLED],
        self::S_APPROVED   => [self::E_RECORD_PAYMENT => self::S_PAID,
                               self::E_CANCEL         => self::S_CANCELLED],
        self::S_PAID       => [self::E_CLOSE          => self::S_CLOSED],
        self::S_CLOSED     => [],
        self::S_CANCELLED  => [],
    ];

    /**
     * Can $event be applied to a settlement currently in $from?
     *
     * $context supplies the facts each guard needs. Every key is optional; a
     * missing key fails its guard closed rather than being assumed.
     *
     *   noticeStatus      string  notice-period status (initiate)
     *   hasOpenSettlement bool    another settlement already occupies the slot (initiate)
     *   clearanceComplete bool    every department cleared (compute)
     *   clearanceOverride bool    recorded override with a reason (compute)
     *   hasComputation    bool    a figure exists (submitForReview)
     *   subjectId         string  the employee being settled
     *   initiatorId       string  who initiated
     *   actorId           string  who is acting now
     *   note              string  rejection note (reject)
     *   reason            string  cancellation reason (cancel)
     *   paymentRef        string  payment reference (recordPayment)
     *   accountingEntryId string  posted entry (close)
     *
     * @return array{ok: bool, to: ?string, reason: string}
     */
    public function can(string $from, string $event, array $context = []): array
    {
        $from = trim($from);

        if (!array_key_exists($from, self::TRANSITIONS)) {
            return $this->no('unknown_state');
        }
        if (!isset(self::TRANSITIONS[$from][$event])) {
            return in_array($from, self::TERMINAL, true)
                ? $this->no('terminal_state')
                : $this->no('illegal_transition');
        }

        $to     = self::TRANSITIONS[$from][$event];
        $guard  = $this->guard($event, $context);

        return $guard === '' ? ['ok' => true, 'to' => $to, 'reason' => 'ok'] : $this->no($guard);
    }

    /** Returns '' when the event's preconditions hold, else a refusal code. */
    private function guard(string $event, array $context): string
    {
        $str = static function ($v): string { return is_string($v) ? trim($v) : ''; };

        switch ($event) {
            case self::E_INITIATE:
                if (!empty($context['hasOpenSettlement'])) return 'settlement_already_open';
                if (!in_array($str($context['noticeStatus'] ?? null), self::NOTICE_SETTLEABLE, true)) {
                    return 'separation_not_settleable';
                }
                return '';

            case self::E_COMPUTE:
                // Clearance may be overridden, but only explicitly — never by
                // omission. An unrecorded override is not an override.
                if (empty($context['clearanceComplete']) && empty($context['clearanceOverride'])) {
                    return 'clearance_incomplete';
                }
                return '';

            case self::E_SUBMIT_REVIEW:
                return empty($context['hasComputation']) ? 'no_computation' : '';

            case self::E_APPROVE:
                return $this->separationOfDuties($context);

            case self::E_REJECT:
                if ($str($context['note'] ?? null) === '') return 'note_required';
                return $this->separationOfDuties($context);

            case self::E_RECORD_PAYMENT:
                return $str($context['paymentRef'] ?? null) === '' ? 'payment_reference_required' : '';

            case self::E_CLOSE:
                return $str($context['accountingEntryId'] ?? null) === '' ? 'accounting_entry_required' : '';

            case self::E_CANCEL:
                return $str($context['reason'] ?? null) === '' ? 'reason_required' : '';
        }

        return 'unknown_event';
    }

    /**
     * Nobody decides their own settlement, and the approver is never the
     * initiator. Directly analogous to decide_leave() and
     * staff_regularization_decide(), which already refuse self-approval on
     * paths that move far smaller sums.
     *
     * Both comparisons fail closed on a missing actor: an unidentified approver
     * is refused rather than waved through.
     */
    private function separationOfDuties(array $context): string
    {
        $actor     = is_string($context['actorId'] ?? null) ? trim($context['actorId']) : '';
        $subject   = is_string($context['subjectId'] ?? null) ? trim($context['subjectId']) : '';
        $initiator = is_string($context['initiatorId'] ?? null) ? trim($context['initiatorId']) : '';

        if ($actor === '') return 'actor_required';
        if ($subject !== '' && $actor === $subject)     return 'self_approval_forbidden';
        if ($initiator !== '' && $actor === $initiator) return 'initiator_cannot_approve';

        return '';
    }

    /** Apply $event, returning the new state, or null when refused. */
    public function apply(string $from, string $event, array $context = []): ?string
    {
        $r = $this->can($from, $event, $context);
        return $r['ok'] ? $r['to'] : null;
    }

    /** Every event legal from $from given $context. */
    public function legalEvents(string $from, array $context = []): array
    {
        $out = [];
        foreach (array_keys(self::TRANSITIONS[trim($from)] ?? []) as $event) {
            if ($this->can($from, $event, $context)['ok']) $out[] = $event;
        }
        return $out;
    }

    public function isTerminal(string $state): bool
    {
        return in_array(trim($state), self::TERMINAL, true);
    }

    /** Does this state occupy the single open settlement slot for a staff member? */
    public function isOpen(string $state): bool
    {
        return in_array(trim($state), self::OPEN, true);
    }

    private function no(string $reason): array
    {
        return ['ok' => false, 'to' => null, 'reason' => $reason];
    }
}
