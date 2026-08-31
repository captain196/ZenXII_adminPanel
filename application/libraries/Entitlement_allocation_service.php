<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 * ENTITLEMENT ALLOCATION SERVICE — P4 · the durable "why" behind a discount
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Today a demand records only the RESULT of pricing: `discountAmount = 60`.
 * Nothing records WHICH entitlement produced it, what it was computed against,
 * or whether that computation is still valid. Reversing one grant of two, or
 * answering "why is this family paying 540?", is guesswork.
 *
 * An allocation is one row: *entitlement E contributed X to demand D, computed
 * against basis B*. The ledger is the authority; `discountAmount` becomes a
 * maintained PROJECTION of it.
 *
 * ── THE COMPOSED INVARIANT ────────────────────────────────────────────────
 *      netAmount  == grossAmount − discountAmount        (P5, already live)
 *      discountAmount == Σ allocatedAmount WHERE status = 'active'   (P4, here)
 *
 * ── WHAT AN ALLOCATION IS MEASURED AGAINST, AND WHY ───────────────────────
 * The basis is the demand's **`grossAmount` at computation time**, recorded on
 * the allocation as `basisAmount`. Rejected alternatives:
 *
 *   • `netAmount` / `balance` — MOVING TARGETS. Both change on payment,
 *     proration and repricing. An allocation whose basis moves cannot be
 *     re-verified, and stacking two entitlements against a shrinking net makes
 *     the result order-dependent.
 *   • `listGrossAmount` (the DEC-012 anchor) — NOT GENERAL. It is written only
 *     by proration; an ordinary demand does not carry it. Using it would mean
 *     "basis is listGross when prorated, gross otherwise" — a conditional
 *     identity, which is how the three-discount-authority mess started.
 *   • A NEW `chargeVersion` field on the demand — REFUSED deliberately. The
 *     brief warns against inventing a second competing financial identity, and
 *     it is unnecessary: storing `basisAmount` ON THE ALLOCATION gives the same
 *     guarantee without touching the demand contract at all. Staleness is then
 *     a comparison, not a new stored identity — see isStale().
 *
 * So: allocations are computed against GROSS, and every allocation carries the
 * exact gross it saw. When Transport re-prices a demand in place (legitimate,
 * see DEC-001), `demand.grossAmount != allocation.basisAmount` and the
 * allocation is detectably STALE rather than silently wrong.
 *
 * Payment state is deliberately absent from this class. An allocation describes
 * the CHARGE, never the settlement — which is why a partially-paid or fully-paid
 * demand needs no special handling here.
 *
 * ── STATUS ────────────────────────────────────────────────────────────────
 * NOT WIRED INTO ANY BILLING PATH. Nothing calls this class yet, no collection
 * is created, and no flag enables it. P5 makes the generator emit allocations;
 * until then this is inert, tested, reviewable code.
 */
class Entitlement_allocation_service
{
    /** The ledger collection. Doc id: `{schoolId}_{demandId}_{entitlementId}`. */
    public const COL = 'entitlementAllocations';

    public const STATUS_ACTIVE   = 'active';
    public const STATUS_REVERSED = 'reversed';

    /** Money comparison tolerance — half a paisa, matching Fee_firestore_txn. */
    public const EPSILON = 0.005;

    private $firebase = null;
    private $fs       = null;
    private string $schoolId = '';
    private string $session  = '';
    private string $actorId  = '';

    public function init($firebase, $fs, string $schoolId, string $session, string $actorId): self
    {
        $this->firebase = $firebase;
        $this->fs       = $fs;
        $this->schoolId = trim($schoolId);
        $this->session  = trim($session);
        $this->actorId  = trim($actorId) !== '' ? trim($actorId) : 'system';
        return $this;
    }

    /**
     * DETERMINISTIC IDENTITY — one entitlement may allocate to one demand
     * exactly once. Duplicate prevention is therefore structural, not a check:
     * a repeated create collides on the document id. Same primitive
     * Entitlement_service already uses for its fingerprint.
     *
     * The school id is included because `demandId` is a bare random token and
     * carries no tenant, so a bare `{demandId}_{entitlementId}` key would be
     * globally ambiguous across tenants.
     */
    public static function allocationId(string $schoolId, string $demandId, string $entitlementId): string
    {
        return trim($schoolId) . '_' . trim($demandId) . '_' . trim($entitlementId);
    }

    /**
     * Build ONE allocation row. Pure — computes and validates, writes nothing.
     *
     * @param array $demand      the demand being priced (needs demandId, grossAmount, …)
     * @param array $entitlement the grant being applied
     * @param float $amount      the discount this grant contributed
     * @return array{ok:bool, reason?:string, row?:array}
     */
    public function build(array $demand, array $entitlement, float $amount): array
    {
        $demandId = trim((string) ($demand['demandId'] ?? ''));
        $entId    = trim((string) ($entitlement['entitlementId'] ?? ''));
        $studentId = trim((string) ($demand['studentId'] ?? ''));

        if ($demandId === '')  return ['ok' => false, 'reason' => 'missing_demand_id'];
        if ($entId === '')     return ['ok' => false, 'reason' => 'missing_entitlement_id'];
        if ($studentId === '') return ['ok' => false, 'reason' => 'missing_student_id'];

        // TENANT ISOLATION. Both sides must belong to the bound tenant. A demand
        // and an entitlement from different schools must never be linked, and a
        // caller must not be able to allocate into a tenant it is not bound to.
        $dSchool = trim((string) ($demand['schoolId'] ?? ''));
        $eSchool = trim((string) ($entitlement['schoolId'] ?? ''));
        if ($this->schoolId === '' || $dSchool !== $this->schoolId || $eSchool !== $this->schoolId) {
            return ['ok' => false, 'reason' => 'tenant_mismatch'];
        }

        // SESSION ISOLATION. A demand belongs to exactly one session. An
        // entitlement with a null/blank session applies to all sessions by the
        // reader's documented rule, so only a MISMATCHED session is refused.
        $dSess = trim((string) ($demand['session'] ?? ''));
        if ($dSess === '' || ($this->session !== '' && $dSess !== $this->session)) {
            return ['ok' => false, 'reason' => 'session_mismatch'];
        }
        $eSess = $entitlement['session'] ?? null;
        if ($eSess !== null && trim((string) $eSess) !== '' && trim((string) $eSess) !== $dSess) {
            return ['ok' => false, 'reason' => 'session_mismatch'];
        }

        // The entitlement must be ACTIVE at allocation time. An allocation from a
        // pending or revoked grant would be money applied without authority.
        if (trim((string) ($entitlement['status'] ?? '')) !== 'active') {
            return ['ok' => false, 'reason' => 'entitlement_not_active'];
        }

        $basis  = round((float) ($demand['grossAmount'] ?? 0), 2);
        $amount = round($amount, 2);

        if ($basis <= 0)      return ['ok' => false, 'reason' => 'no_charge_basis'];
        if ($amount <= 0)     return ['ok' => false, 'reason' => 'non_positive_amount'];
        // OVER-ALLOCATION, single-row guard. A grant can never discount more
        // than the charge itself; the cross-row guard is validateForDemand().
        if ($amount - $basis > self::EPSILON) {
            return ['ok' => false, 'reason' => 'exceeds_basis'];
        }

        return ['ok' => true, 'row' => [
            'allocationId'   => self::allocationId($this->schoolId, $demandId, $entId),
            'schoolId'       => $this->schoolId,
            'session'        => $dSess,
            'studentId'      => $studentId,
            'demandId'       => $demandId,
            'entitlementId'  => $entId,
            // Denormalised so the ledger can be reported on and reconciled
            // without joining every demand back. These are COPIES for reading,
            // never an authority — the demand remains authoritative.
            'feeHeadRef'     => (string) ($demand['feeHeadRef'] ?? ''),
            'periodKey'      => (string) ($demand['periodKey']  ?? ''),
            // The charge this was computed against. See the class comment.
            'basisAmount'    => $basis,
            'allocatedAmount' => $amount,
            // Snapshot of the rule as applied, so a later edit to the grant
            // cannot silently rewrite the history of what was charged.
            'valueType'      => (string) ($entitlement['valueType'] ?? ''),
            'value'          => (float)  ($entitlement['value'] ?? 0),
            'scope'          => (string) ($entitlement['scope'] ?? ''),
            'status'         => self::STATUS_ACTIVE,
            'createdAt'      => date('c'),
            'createdBy'      => $this->actorId,
        ]];
    }

    /**
     * CROSS-ROW VALIDATION for one demand: the allocations must exactly explain
     * the demand's discount, and may never exceed the charge.
     *
     * This is the P4 half of the composed invariant. Returns null when coherent.
     *
     * @param array $demand
     * @param array $rows allocation rows (any status)
     * @return array{reason:string, expected:float, actual:float, delta:float}|null
     */
    public function validateForDemand(array $demand, array $rows): ?array
    {
        $gross    = round((float) ($demand['grossAmount']    ?? 0), 2);
        $discount = round((float) ($demand['discountAmount'] ?? 0), 2);

        $sum = 0.0;
        foreach ($rows as $r) {
            if (($r['status'] ?? self::STATUS_ACTIVE) !== self::STATUS_ACTIVE) continue;
            $sum += (float) ($r['allocatedAmount'] ?? 0);
        }
        $sum = round($sum, 2);

        // OVER-ALLOCATION across rows — two stacked grants cannot together
        // discount more than the charge. Checked BEFORE the projection test so
        // the more serious fault is the one reported.
        if ($gross > 0 && $sum - $gross > self::EPSILON) {
            return ['reason' => 'over_allocated', 'expected' => $gross,
                    'actual' => $sum, 'delta' => round($sum - $gross, 2)];
        }
        if (abs($sum - $discount) > self::EPSILON) {
            return ['reason' => 'projection_mismatch', 'expected' => $discount,
                    'actual' => $sum, 'delta' => round($sum - $discount, 2)];
        }
        return null;
    }

    /**
     * Is this allocation still measured against the demand's current charge?
     *
     * Transport legitimately re-prices a demand in place (DEC-001), which moves
     * `grossAmount` under an allocation computed earlier. That does not corrupt
     * anything — but the allocation is no longer a statement about the current
     * charge, and P5 must recompute rather than trust it. Detectable, not silent.
     */
    public function isStale(array $allocation, array $demand): bool
    {
        $basis = round((float) ($allocation['basisAmount'] ?? 0), 2);
        $gross = round((float) ($demand['grossAmount'] ?? 0), 2);
        return abs($basis - $gross) > self::EPSILON;
    }

    /**
     * REVERSAL — never a delete.
     *
     * A discontinued grant, an approval overturned, or a corrected amount must
     * leave a trace: the row is marked `reversed` and stops counting toward the
     * projection. Deleting would destroy the only record that the money was ever
     * applied, which is precisely the failure mode this ledger exists to end.
     *
     * Pure: returns the patch, writes nothing.
     */
    public function buildReversal(array $allocation, string $reason): array
    {
        return [
            'status'         => self::STATUS_REVERSED,
            'reversedAt'     => date('c'),
            'reversedBy'     => $this->actorId,
            'reversalReason' => trim($reason),
        ];
    }

    /**
     * P4 PERSISTENCE — write allocation rows, idempotently.
     *
     * CREATE-IF-NOT-EXISTS, never set/merge. `firestoreCreateStatus()` is the
     * same race-safe primitive `Entitlement_service::propose()` uses, and it
     * distinguishes the three outcomes that matter:
     *
     *   SUCCESS   — the row is new
     *   COLLISION — the row already exists ⇒ this is a RE-RUN, which is
     *               EXPECTED and is NOT an error. Because `allocationId` is
     *               deterministic ({schoolId}_{demandId}_{entitlementId}),
     *               duplicate prevention is structural: a second attempt for
     *               the same (demand, entitlement) pair simply collides.
     *   FAILURE   — a genuine write problem, counted and logged.
     *
     * NEVER OVERWRITES. An existing row is left exactly as written: it records
     * what was charged at the time, and silently restating history is the whole
     * failure mode this ledger exists to end. A row whose basis no longer
     * matches the demand is handled by `isStale()` + an explicit reversal, not
     * by a quiet rewrite.
     *
     * FAIL-SOFT BY DESIGN. Persistence never throws into the caller: the demand
     * it describes is already committed, and losing the ledger row must not fail
     * a billing write that already succeeded. Every failure is counted and
     * logged as `ACC_ALLOCATION_PERSIST_FAILED` so it is greppable and
     * reconcilable after the fact.
     *
     * INERT TODAY. Nothing calls this unless `USE_ALLOCATION_LEDGER` is granted,
     * and it ships false. The collection's rules are prepared but NOT deployed,
     * so a write would be refused by the server anyway — which is the correct
     * belt-and-braces ordering: the boundary closes before the first row exists.
     *
     * @param array $rows rows from build()
     * @return array{created:int, duplicate:int, failed:int, errors:array<int,string>}
     */
    public function persist(array $rows): array
    {
        $out = ['created' => 0, 'duplicate' => 0, 'failed' => 0, 'errors' => []];
        if ($rows === []) return $out;

        if ($this->firebase === null || !method_exists($this->firebase, 'firestoreCreateStatus')) {
            // Fail CLOSED and LOUD: a ledger that silently does not persist is
            // worse than one that reports it cannot.
            $out['failed'] = count($rows);
            $out['errors'][] = 'no_firestore_handle';
            log_message('error', 'ACC_ALLOCATION_PERSIST_FAILED reason=[no_firestore_handle] rows='
                . count($rows) . ' — ledger NOT written.');
            return $out;
        }

        foreach ($rows as $row) {
            $id = (string) ($row['allocationId'] ?? '');
            if ($id === '') {
                $out['failed']++;
                $out['errors'][] = 'missing_allocation_id';
                continue;
            }
            // Refuse to write a row that does not belong to the bound tenant,
            // even though build() already checked: persistence is the last line
            // before the data exists, and a cross-tenant row is unrecoverable.
            if ((string) ($row['schoolId'] ?? '') !== $this->schoolId) {
                $out['failed']++;
                $out['errors'][] = 'tenant_mismatch:' . $id;
                log_message('error', 'ACC_ALLOCATION_PERSIST_FAILED reason=[tenant_mismatch] id=[' . $id . ']');
                continue;
            }

            try {
                $res = $this->firebase->firestoreCreateStatus(self::COL, $id, $row);
                $status = is_array($res) ? (string) ($res['status'] ?? 'FAILURE') : 'FAILURE';
                if ($status === 'SUCCESS')        { $out['created']++;   continue; }
                if ($status === 'COLLISION')      { $out['duplicate']++; continue; }
                $out['failed']++;
                $out['errors'][] = 'write_failed:' . $id;
                log_message('error', 'ACC_ALLOCATION_PERSIST_FAILED id=[' . $id . '] status=[' . $status
                    . '] message=[' . (is_array($res) ? (string) ($res['message'] ?? '') : '') . ']');
            } catch (\Throwable $e) {
                $out['failed']++;
                $out['errors'][] = 'exception:' . $id;
                log_message('error', 'ACC_ALLOCATION_PERSIST_FAILED id=[' . $id . ']: ' . $e->getMessage());
            }
        }
        return $out;
    }

    /**
     * The projection an allocation set implies — what `discountAmount` SHOULD be.
     * P5 uses this to maintain the demand rather than trusting whatever the
     * pricing loop last happened to write.
     */
    public static function projectedDiscount(array $rows): float
    {
        $sum = 0.0;
        foreach ($rows as $r) {
            if (($r['status'] ?? self::STATUS_ACTIVE) !== self::STATUS_ACTIVE) continue;
            $sum += (float) ($r['allocatedAmount'] ?? 0);
        }
        return round($sum, 2);
    }
}
