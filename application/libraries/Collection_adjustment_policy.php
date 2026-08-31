<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  Collection_adjustment_policy — THE ONE AUTHORITY FOR A COLLECTION-LAYER
 *  DISCOUNT (`studentDiscounts.onDemandDiscount`).
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE TWO LAYERS, AND WHY THIS IS NOT A DUPLICATE OF THEM ───────────────
 *
 *   GENERATION   studentConcessions / studentEntitlements
 *                  → feeDemands.netAmount → .balance          (what is BILLED)
 *   COLLECTION   studentDiscounts.onDemandDiscount
 *                  → counter netDue → FeeCollectionService cap
 *                  → receipt.discount → accounting            (what is ASKED)
 *
 * These are deliberately separate and deliberately additive: a standing 10%
 * sibling concession plus a one-off ₹500 hardship waiver at the desk is a
 * legitimate school scenario, not a double discount. The layer boundary is a
 * locked semantic decision (STUDENT_DISCOUNTS_FORENSIC_AUDIT.md §V, 2026-08-10)
 * and this class does not change it.
 *
 * What it changes is that the COLLECTION layer now has exactly one gate.
 *
 * ── WHY IT EXISTS · FIVE DOORS, ONE FIELD, NO SHARED RULE ─────────────────
 *
 * `onDemandDiscount` governs real money — `Fees::fetch_months` computes
 * `netDue = max(0, totalRemaining − discAmt)` and `FeeCollectionService`
 * uses the same field as the authorised CAP on what a clerk may discount on
 * a receipt. It had FIVE writers across two controllers:
 *
 *   W1 Fees::set_student_discount          counter inline grant
 *   W2 Fees::submit_discount               profile managerial grant
 *   W3 Fee_management::apply_discount      RULE/BULK apply, many students
 *   W4 Fee_management  scholarship award
 *   W5 Fee_management  scholarship revoke
 *
 * ADJ-GOV-1 governed W1 and W2. W3–W5 remained uncapped — and W3 is the worst
 * of the five, because it writes `onDemandDiscount` for an entire selection of
 * students in one click, capped only by the policy's own optional
 * `max_discount` (which defaults to 0 = NO CAP). A bulk apply could therefore
 * drive netDue to zero for a whole class, silently.
 *
 * Two inline copies of a rule is how W3 stayed uncapped after W1 was fixed, so
 * the rule lives here once and every door calls it.
 *
 * ── WHAT IS A PLATFORM INVARIANT, AND WHAT IS A SCHOOL CHOICE ─────────────
 *
 * INVARIANT (never a tenant switch):
 *   • an adjustment may never exceed what the student actually owes
 *   • every adjustment carries a reason
 *   • the outstanding is measured in THIS tenant and THIS session
 *   • an unreadable outstanding yields 0, so the adjustment is REFUSED
 *     (the alternative is permitting an uncapped one)
 *
 * SCHOOL POLICY (legitimately configurable, via Benefit_policy_service):
 *   • a TIGHTER absolute ceiling, and/or a tighter percentage ceiling
 *
 * A school may restrict its clerks further. It may not choose whether a waiver
 * needs a reason, or whether it may exceed the bill.
 *
 * ── SESSION SCOPE · THE LEAK THIS CLOSES GOING FORWARD ────────────────────
 *
 * `studentDiscounts` is keyed `{schoolId}_{studentId}` with NO session
 * component, unlike `feeDemands` and `feeDefaulters`. A discount granted in
 * 2025-26 therefore stays live in 2026-27 forever, unless an optional
 * `validUntil` happens to have been set. After a session rollover last year's
 * waiver silently reduces this year's collection.
 *
 * Changing the document id would be a migration of live financial records, so
 * it is NOT done here. Instead every write is STAMPED with its session, and
 * `appliesToSession()` lets readers refuse a stamped discount from a different
 * session. Documents with no stamp are LEGACY and are still honoured, so no
 * existing record changes meaning — the leak is closed for everything written
 * from now on, without reinterpreting settled history.
 */
final class Collection_adjustment_policy
{
    /** Tolerance for float comparison on money — half a paisa. */
    private const EPS = 0.005;

    private $firebase;
    private string $schoolId = '';
    private string $session  = '';
    private string $actor    = '';

    /** @var array<string,float>|null memoised per (student) for one request */
    private array $outstandingMemo = [];

    public function init($firebase, string $schoolId, string $session, string $actor): self
    {
        $this->firebase = $firebase;
        $this->schoolId = $schoolId;
        $this->session  = $session;
        $this->actor    = $actor;
        return $this;
    }

    // ══ THE OUTSTANDING CEILING ════════════════════════════════════════════

    /**
     * What this student still owes in THIS tenant and THIS session.
     *
     * `balance` is used rather than a gross figure because it already accounts
     * for payments made and for any reduction the DEMAND-layer authorities
     * applied. Paid, archived and cancelled periods are excluded: settled money
     * cannot be discounted again.
     *
     * Returns 0.0 when the question cannot be answered, so the caller REFUSES.
     * Reading an unavailable outstanding as "unlimited" is the exact shape that
     * turns a fail-closed guard into a silent give-away.
     */
    public function outstanding(string $studentId): float
    {
        if (array_key_exists($studentId, $this->outstandingMemo)) {
            return $this->outstandingMemo[$studentId];
        }
        try {
            $rows = (array) $this->firebase->firestoreQuery('feeDemands', [
                ['schoolId',  '==', $this->schoolId],
                ['studentId', '==', $studentId],
                ['session',   '==', $this->session],
            ]);

            $total = 0.0;
            foreach ($rows as $r) {
                $d = $r['data'] ?? $r;
                if (!is_array($d)) continue;
                $status = strtolower(trim((string) ($d['status'] ?? '')));
                if (in_array($status, ['archived', 'cancelled', 'paid'], true)) continue;
                $bal = (float) ($d['balance'] ?? 0);
                if ($bal > 0) $total += $bal;
            }
            return $this->outstandingMemo[$studentId] = round($total, 2);
        } catch (\Throwable $e) {
            log_message('error', 'ADJ_GOV_OUTSTANDING_FAILED school=[' . $this->schoolId
                . '] student=[' . $studentId . ']: ' . $e->getMessage());
            return $this->outstandingMemo[$studentId] = 0.0;
        }
    }

    // ══ THE GATE ═══════════════════════════════════════════════════════════

    /**
     * @return string|null null when the adjustment is allowed, otherwise the
     *                     operator-facing refusal.
     *
     * @param float|null $ceilingOverride an ALREADY-TIGHTER ceiling the caller
     *        wants applied as well (e.g. a discount policy's `max_discount`).
     *        It may only tighten; it can never widen the invariant.
     */
    public function refusal(string $studentId, float $amount, string $reason,
                            ?float $ceilingOverride = null): ?string
    {
        if (trim($reason) === '') {
            return 'A reason is required for a fee adjustment. It is recorded against the '
                 . 'student and cannot be edited afterwards.';
        }

        if ($amount < 0) {
            return 'A fee adjustment cannot be negative.';
        }

        $outstanding = $this->outstanding($studentId);
        if ($amount > $outstanding + self::EPS) {
            log_message('error', 'ADJ_GOV_REFUSED_OVER_OUTSTANDING school=[' . $this->schoolId
                . '] student=[' . $studentId . '] requested=[' . $amount
                . '] outstanding=[' . $outstanding . '] by=[' . $this->actor . ']');
            return 'The adjustment (' . number_format($amount, 2) . ') is more than this student '
                 . 'owes (' . number_format($outstanding, 2) . '). A discount can never exceed '
                 . 'the outstanding amount.';
        }

        $ceiling = $this->schoolCeiling($outstanding);

        // A caller-supplied cap may only TIGHTEN. Taking the min both ways means
        // a mis-set policy cannot widen the platform invariant.
        if ($ceilingOverride !== null && $ceilingOverride > 0) {
            $ceiling = $ceiling === null ? $ceilingOverride : min($ceiling, $ceilingOverride);
        }

        if ($ceiling !== null && $amount > $ceiling + self::EPS) {
            return 'This school limits a single fee adjustment to ' . number_format($ceiling, 2)
                 . '. Raise the limit in Fees → Benefits & Policies, or record a smaller '
                 . 'adjustment.';
        }

        return null;
    }

    /**
     * The school's own TIGHTER ceiling, or null when it has configured none.
     *
     * An unreadable policy must not widen the ceiling, and must not block every
     * adjustment either — the invariant outstanding cap already stands, so this
     * degrades to "no extra school limit" and says so loudly.
     */
    private function schoolCeiling(float $outstanding): ?float
    {
        try {
            if (!class_exists('Benefit_policy_service')) {
                require_once APPPATH . 'libraries/Benefit_policy_service.php';
            }
            $pol = (new Benefit_policy_service())
                ->init($this->firebase, $this->schoolId, $this->session, $this->actor)
                ->resolveAdjustment();

            $ceiling = null;
            if ((float) $pol['maxAmount'] > 0)  $ceiling = (float) $pol['maxAmount'];
            if ((float) $pol['maxPercent'] > 0) {
                $pctCap  = round($outstanding * ((float) $pol['maxPercent'] / 100), 2);
                $ceiling = $ceiling === null ? $pctCap : min($ceiling, $pctCap);
            }
            return $ceiling;
        } catch (\Throwable $e) {
            log_message('error', 'ADJ_GOV_POLICY_UNREADABLE school=[' . $this->schoolId
                . '] — proceeding on the outstanding-balance cap alone for student: '
                . $e->getMessage());
            return null;
        }
    }

    // ══ SESSION STAMPING ═══════════════════════════════════════════════════

    /**
     * Fields every writer must merge into its `studentDiscounts` write, so the
     * record says WHICH session it belongs to and WHO moved the money.
     *
     * `studentDiscounts` has no session in its document id, so without this a
     * discount is permanent across every future session.
     */
    public function stamp(string $reason, string $source): array
    {
        return [
            'session'    => $this->session,
            'schoolId'   => $this->schoolId,
            'reason'     => $reason,
            'appliedBy'  => $this->actor,
            'appliedAt'  => date('c'),
            'source'     => $source,
        ];
    }

    /**
     * Is a stored discount applicable to the session now being collected?
     *
     * COMPATIBILITY: a document with no `session` field predates stamping and
     * is honoured, exactly as it is today. Only a document that explicitly
     * names a DIFFERENT session is refused — which can only be one written
     * after this change, and which genuinely does not belong to this session.
     */
    public function appliesToSession(array $discountDoc): bool
    {
        $s = trim((string) ($discountDoc['session'] ?? ''));
        if ($s === '') return true;      // legacy, unstamped — unchanged meaning
        return $s === $this->session;
    }

    // ══ AUDIT ══════════════════════════════════════════════════════════════

    /**
     * Record the adjustment in the canonical financial audit trail.
     *
     * SD-3: the only trail this had was `log_message('info', 'ACC_DISCOUNT_WRITE …')`,
     * and production runs `log_threshold = 1` (errors only) — so the discount
     * audit trail wrote NOTHING AT ALL. Same defect class as LOG-1, on the
     * discount money path. `feeAuditLogs` is the canonical trail already used
     * by demands, receipts, payments and refunds; a discount belongs in it
     * rather than in a parallel mechanism.
     *
     * Never fails the caller — audit completeness must not break a legitimate
     * financial write.
     */
    public function audit(string $studentId, array $before, array $after, string $reason, string $source): void
    {
        try {
            if (!class_exists('Fee_audit_logger')) {
                require_once APPPATH . 'libraries/Fee_audit_logger.php';
            }
            (new Fee_audit_logger($this->firebase, $this->schoolId, $this->session))
                ->record('update', 'discount', $this->schoolId . '_' . $studentId,
                         $before, $after, $this->actor,
                         ['reason' => $reason, 'source' => $source]);
        } catch (\Throwable $e) {
            log_message('error', 'ADJ_GOV_AUDIT_FAILED school=[' . $this->schoolId
                . '] student=[' . $studentId . ']: ' . $e->getMessage());
        }
    }

    // ══ REPORTING COHERENCE ════════════════════════════════════════════════

    /**
     * SD-2 — the truthful CURRENT collection-layer reduction for a student.
     *
     * `totalDiscount` is not trustworthy as a current figure: W2 accumulates
     * (`total = prev + delta`) and W3 accumulates across every rule application,
     * while W1 overwrites. The stored value is therefore a running historical
     * tally for some students and a current value for others, and the
     * school-wide discount report sums them together.
     *
     * Stored values are NOT rewritten — that would reinterpret settled records.
     * Readers that need the truth derive it here instead, from the two fields
     * that actually govern money, honouring the same expiry rule the counter
     * and FeeCollectionService already apply.
     */
    public static function currentReduction(array $doc, ?string $today = null): float
    {
        $today = $today ?? date('Y-m-d');
        $vu = trim((string) ($doc['validUntil'] ?? $doc['valid_until'] ?? ''));
        if ($vu !== '' && $vu < $today) return 0.0;

        $onDemand = (float) ($doc['onDemandDiscount'] ?? 0);
        $schol    = (float) ($doc['ScholarshipDiscount'] ?? $doc['scholarshipDiscount'] ?? 0);
        $sum = $onDemand + $schol;
        return $sum > 0 ? round($sum, 2) : 0.0;
    }
}
