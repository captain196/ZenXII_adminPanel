<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Entitlement_service — Phase E. THE single sanctioned write path for
 * `studentEntitlements`.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  THE RULE THIS CLASS EXISTS TO ENFORCE
 * ─────────────────────────────────────────────────────────────────────────
 *  Baseline §APIs: "entitlement mutations (propose/approve/reject/discontinue)
 *  are implemented ONCE ... called identically by every surface that can
 *  create or change an entitlement — the admission wizard, Transport's
 *  assign/suspend screen, Hostel's allocation screen, and (later) Student 360.
 *  **No controller is permitted to write to `studentEntitlements` directly.**"
 *
 *  That is the same discipline Fee_lifecycle already enforces for feeDemands
 *  (every mutation goes through Fee_firestore_txn, never a raw firestoreSet
 *  from a controller), and it is what makes the Action Registry possible: one
 *  domain, one facade, one implementation per action.
 *
 *  Phase E's "one shared component, three entry points" requirement is
 *  satisfied HERE, not in the views — the public admission form, the CRM
 *  walk-in application and the direct Admission screen all call these same
 *  methods, so a fix never needs repeating per pathway.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  INVARIANTS ENFORCED (baseline §0C)
 * ─────────────────────────────────────────────────────────────────────────
 *   I1  RETIRED at the P2 contract freeze (2026-08-17). It required a
 *       `frequency` on the ENTITLEMENT, to stop a service charge whose billing
 *       period was unknown from making proration a structural no-op (G1/G2).
 *
 *       That protection is real, and it is still enforced — but on the DEMAND,
 *       which is where a billing occurrence actually lives. The G2 fix stamps
 *       `frequency` at the point of charge: Transport_service writes 'Annual'
 *       at three call sites, each annotated "G2 fix (2026-08-07)", and
 *       Id_card_fee_policy does the same for a one-off. The entitlement's own
 *       copy never participated in that fix.
 *
 *       It was verifiably DEAD: written by three callers, validated, folded
 *       into the fingerprint — and read by NO pricing path. appliesTo() keys on
 *       scope + effectiveFrom/effectiveTo; _applyDeductions() on valueType +
 *       value. Neither Fee_entitlement_reader, Fee_concession_reader,
 *       Fee_generation_service nor Service_entitlement_pricing mentions it.
 *
 *       It was also a Billing V2 hazard: the vocabulary was monthly|annual, so
 *       quarterly / installment / one-time schedules would have made an
 *       ENTITLEMENT field wrong for a reason that has nothing to do with
 *       entitlements. Retiring it removes the entitlement layer's only coupling
 *       to the billing schedule.
 *
 *       Retired now, and only now, because the fingerprint is derived from the
 *       field set: dropping it re-mints every document id. The P2-A census
 *       proved the collection EMPTY across all nine tenants (studentEntitlements
 *       = 0, studentConcessions = 0, verified by count() and a full get(), with
 *       feeDemands = 1,242 as a positive control), so this costs no migration.
 *       After the first record exists it would have cost one.
 *
 *       THE RULE:  entitlement = the benefit definition.
 *                  demand      = the billing occurrence, and its frequency.
 *   I4  An RTE/EWS entitlement ALWAYS carries a govtReceivable — the state
 *       recovers that money; an RTE concession without a receivable is an
 *       unrecorded government debt.
 *   I5  `pending_approval → active` is impossible without `approvedBy`.
 *       Enforced structurally: only approve() performs that transition, and
 *       it always stamps the approver.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  WHAT THIS CLASS DELIBERATELY DOES NOT DO
 * ─────────────────────────────────────────────────────────────────────────
 *  • It does not price anything. Pricing lives in Fee_generation_service
 *    (tuition, Phase C) and Service_entitlement_pricing (services, Phase D).
 *    Adding a third pricing implementation here is precisely the divergence
 *    this project exists to close.
 *  • It does not generate or mutate feeDemands. Entitlements are the INPUT to
 *    generation; the generators own the output.
 *  • It does not decide RBAC. Authorization is the controller's job
 *    (graded has_permission); re-deciding it here would create two answers to
 *    one question. This class assumes an authorized caller and records WHO.
 */
class Entitlement_service
{
    /** @var object|null */ private $firebase;
    /** @var object|null */ private $fs;
    private string $schoolId = '';
    private string $session  = '';
    private string $actorId  = '';
    /** @var Fee_head_resolver|null DEC-004 — lazily built; see _headResolver(). */
    private $headResolver = null;

    public const COL = 'studentEntitlements';

    /** Baseline §Data Model status vocabulary. */
    public const STATUSES = ['proposed', 'pending_approval', 'active', 'rejected', 'discontinued'];
    private const KINDS       = ['service', 'concession', 'credit_note'];
    /**
     * PHASE 2 / F3 (2026-08-16) — `amountDue` REMOVED (Option B).
     *
     * It was accepted here but NOT implemented by the one place that turns an
     * entitlement into money: Fee_generation_service::_applyDeductions() handles
     * `fullExempt`, `percent` and `fixed` only. An active `amountDue`
     * entitlement therefore produced a deduction of ZERO — the student was
     * billed the full gross with the concession visible and approved in the UI,
     * and no error anywhere. Accepted-but-ignored is the worst of the options.
     *
     * Removed rather than implemented, on the evidence:
     *   • no capture surface offers it — the shared partial lists percent /
     *     fixed / fullExempt only (views/_entitlements_capture.php:101-103);
     *   • the migration cannot emit it (VALUE_TYPE_MAP has no such entry);
     *   • zero records of any kind exist platform-wide (Phase 1 report);
     *   • it is semantically foreign to the model: every other type is an
     *     ADDITIVE DEDUCTION that sums with its peers, whereas "amount due" is
     *     an ABSOLUTE net that cannot be summed. Supporting it would require a
     *     precedence rule for "absolute vs additive" that no product decision
     *     has ever asked for.
     *
     * Reinstating it is a deliberate design exercise, not a one-line edit: it
     * needs semantics, precedence against fullExempt/percent/fixed, clamping,
     * and reader + migration + UI support. `Phase2EntitlementSemanticsTest`
     * fails the moment this list accepts a type the algorithm cannot apply.
     */
    private const VALUE_TYPES = ['percent', 'fixed', 'fullExempt'];
    // FREQUENCIES removed at the P2 contract freeze — see I1 in the class
    // docblock. Do NOT reintroduce a frequency here; a billing period belongs
    // on the demand.
    private const CONCESSION_TYPES = ['sibling', 'staff_ward', 'rte_ews', 'scholarship', 'other'];

    public function init($firebase, $fs, string $schoolId, string $session, string $actorId): self
    {
        $this->firebase = $firebase;
        $this->fs       = $fs;
        $this->schoolId = $schoolId;
        $this->session  = $session;
        $this->actorId  = $actorId !== '' ? $actorId : 'SYSTEM';
        return $this;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  PROPOSE
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Create an entitlement. Lands in `pending_approval` when approval is
     * required, else `active`.
     *
     * Validation is FAIL-CLOSED and returns a structured error rather than
     * throwing: every caller is a user-facing capture surface, and a rejected
     * proposal is a normal outcome, not an exception.
     *
     * @return array{ok:bool, id?:string, status?:string, error?:string}
     */
    public function propose(array $in): array
    {
        $err = $this->_validate($in);
        if ($err !== null) return ['ok' => false, 'error' => $err];

        $studentId = trim((string) $in['studentId']);
        $kind      = (string) $in['kind'];
        $needsApproval = (bool) ($in['approvalRequired'] ?? false);
        $now = date('c');

        // ── PHASE 3B / B5 — DETERMINISTIC IDENTITY, SO A RETRY CANNOT DUPLICATE ──
        //
        // This was `{school}_{student}_{timestamp}_{random}`, which made every
        // call a new document by construction. A browser double-click, an HTTP
        // retry, or an admission resubmitted after an uncertain response
        // therefore created a SECOND active concession — and two active
        // concessions SUM in _applyDeductions(), so the student was
        // double-discounted.
        //
        // The id is now derived from the entitlement's BUSINESS IDENTITY: the
        // same student, the same kind, the same scope, the same value, over the
        // same window, in the same session IS the same entitlement. Anything
        // materially different — a different scope, a different percentage, a
        // different effective date — hashes differently and stays distinct.
        //
        // Concurrency is handled by create-if-not-exists (HTTP 409), the same
        // primitive Id_generator uses for STU claims: two simultaneous retries
        // race, exactly one creates, and the loser is told it is a duplicate
        // rather than writing a second row. That is why this uses
        // firestoreCreateStatus() and not a read-then-write check, which would
        // have a window between the read and the write.
        $docId = $this->_fingerprintId($studentId, $in);

        $doc = [
            'entitlementId'    => $docId,
            'schoolId'         => $this->schoolId,
            'studentId'        => $studentId,
            'kind'             => $kind,
            'serviceType'      => $kind === 'service' ? (string) ($in['serviceType'] ?? '') : null,
            'concessionType'   => $kind === 'concession' ? (string) ($in['concessionType'] ?? 'other') : null,
            'scope'            => (string) $in['scope'],
            'valueType'        => (string) $in['valueType'],
            'value'            => ((string) $in['valueType'] === 'fullExempt') ? 0 : (float) ($in['value'] ?? 0),
            // No `frequency` — retired at the P2 contract freeze (I1). The
            // billing period is a property of the demand, not of the grant.
            'effectiveFrom'    => (string) $in['effectiveFrom'],
            'effectiveTo'      => ($in['effectiveTo'] ?? '') !== '' ? (string) $in['effectiveTo'] : null,
            'session'          => ($in['session'] ?? '') !== '' ? (string) $in['session'] : $this->session,
            'status'           => $needsApproval ? 'pending_approval' : 'active',
            'approvalRequired' => $needsApproval,
            // I5 — an auto-active entitlement records the proposer as approver;
            // a pending one carries NO approver until approve() stamps one.
            'approvedBy'       => $needsApproval ? null : $this->actorId,
            'approvedAt'       => $needsApproval ? null : $now,
            'requestedBy'      => $this->actorId,
            'requestedAt'      => $now,   // approval queue orders on this (Phase B index)
            'reason'           => trim((string) ($in['reason'] ?? '')),
            'govtReceivable'   => $this->_govtReceivable($in),   // I4
            'linkedRouteId'    => ($in['linkedRouteId'] ?? '') !== '' ? (string) $in['linkedRouteId'] : null,
            'linkedRoomId'     => ($in['linkedRoomId']  ?? '') !== '' ? (string) $in['linkedRoomId']  : null,
            'creditBalanceRef' => null,
            'source'           => trim((string) ($in['source'] ?? 'manual')),   // which pathway proposed it
            'createdAt'        => $now,
            'updatedAt'        => $now,
        ];

        // Create-if-not-exists. COLLISION means an identical entitlement is
        // already on file — report it as the SAME entitlement rather than
        // creating a second one that would sum against the first.
        $created = $this->_createOnce($docId, $doc);
        if ($created === 'COLLISION') {
            $existing = $this->get($docId);
            $this->_audit('entitlement_duplicate_suppressed', $docId, $studentId, [
                'status' => (string) ($existing['status'] ?? 'unknown'),
            ]);
            return [
                'ok'        => true,
                'id'        => $docId,
                'status'    => (string) ($existing['status'] ?? 'active'),
                'duplicate' => true,
            ];
        }
        if ($created !== 'SUCCESS') {
            return ['ok' => false, 'error' => 'Could not save the entitlement. Please retry.'];
        }
        $this->_audit('entitlement_proposed', $docId, $studentId, [
            'kind' => $kind, 'scope' => $doc['scope'], 'valueType' => $doc['valueType'],
            'value' => $doc['value'], 'status' => $doc['status'],
        ]);
        return ['ok' => true, 'id' => $docId, 'status' => $doc['status']];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  APPROVE / REJECT / DISCONTINUE
    // ─────────────────────────────────────────────────────────────────────

    /**
     * I5 lives here: this is the ONLY method that can produce `active` from
     * `pending_approval`, and it cannot do so without stamping approvedBy.
     *
     * ── PR-1 · APPROVE WITH AMENDMENT (P6, 2026-08-18) ────────────────────
     *
     * THE DEFECT THIS CLOSES. A parent's mid-year request is filed by
     * Entitlement_requests as a deliberate PLACEHOLDER — scope 'all', valueType
     * 'fixed', value 0 — because a family describes a situation and the school
     * decides the money. Its own comment says the approver "edits it into a
     * real grant". No edit path existed. approve() only flipped status, so the
     * approver's choices were: reject, or approve a grant worth ₹0. Every
     * parent request that was granted became financially inert.
     *
     * $amend carries the terms the APPROVER decides. Nothing is inferred: the
     * value is supplied by a human with Fees:manage, exactly as it would be on
     * a staff-captured entitlement. Only the four pricing fields may be
     * amended — scope, valueType, value, and the effective window. Identity
     * (student, kind, session) and provenance (requestedBy, source, reason) are
     * NOT amendable: changing who or what the request was for would rewrite a
     * family's record rather than answer it.
     *
     * ZERO-VALUE GUARD. A non-exempt entitlement worth 0 is refused rather than
     * approved, whether or not an amendment was supplied. That is the actual
     * fix — silently activating a worthless grant is what made this invisible.
     * `fullExempt` is exempt from the guard: its value is legitimately 0.
     *
     * ⚠ FINGERPRINT NOTE (feeds DEC-004, does not resolve it). The document id
     * was derived at propose() time from the placeholder's fields, so an
     * amended row's id no longer describes its contents. `_fingerprintId` is
     * deliberately UNTOUCHED here — re-deriving it would mean rewriting the
     * document under a new id, which is an identity decision this phase is not
     * authorized to take. The divergence is recorded explicitly on the row
     * (`amendedAt`, `amendedBy`, `originalProposal`) so it is auditable rather
     * than silent, and so DEC-004 can be decided on evidence.
     *
     * @param array $amend optional approver-decided terms; keys: scope,
     *                     valueType, value, effectiveFrom, effectiveTo.
     */
    public function approve(string $entitlementId, string $note = '', array $amend = []): array
    {
        $cur = $this->get($entitlementId);
        if ($cur === null) return ['ok' => false, 'error' => 'Entitlement not found.'];
        if (($cur['status'] ?? '') !== 'pending_approval') {
            return ['ok' => false, 'error' => 'Only a pending entitlement can be approved (current: ' . ($cur['status'] ?? '?') . ').'];
        }
        // Self-approval guard: the requester must not rubber-stamp their own
        // proposal. This is the maker-checker expectation in every commercial
        // ERP that gates concessions, and the reason approvalRequired exists.
        if ((string) ($cur['requestedBy'] ?? '') === $this->actorId) {
            return ['ok' => false, 'error' => 'You cannot approve an entitlement you requested yourself.'];
        }

        // ── DEC-004 · SCOPE IS NOT AMENDABLE ─────────────────────────────────
        //
        // Scope is IDENTITY, not a business term. The document id was derived
        // at propose() time from a field set that includes `scope`, so changing
        // it at approval would leave an entitlement whose id describes a head it
        // no longer applies to — and two such rows could then name one head
        // without colliding, which is precisely the DD-1 hazard this decision
        // closes at the front door.
        //
        // Refused EXPLICITLY rather than ignored. Silently dropping an
        // approver's edit is the accepted-but-ignored failure mode this
        // programme keeps closing (see F3/`amountDue`). Re-scoping is a new
        // proposal, which gets its own correct identity.
        if (array_key_exists('scope', $amend)
            && trim((string) $amend['scope']) !== (string) ($cur['scope'] ?? '')) {
            return ['ok' => false, 'error' =>
                'The scope of an entitlement cannot be changed at approval — it is part of '
                . 'its identity. Reject this one and raise a new proposal for the correct '
                . 'fee head or category.'];
        }

        // Merge the approver's terms over the stored ones, then validate the
        // RESULT — so an amendment can never produce a row that propose()
        // would have refused.
        $final = [
            // Always the STORED scope. See the guard above.
            'scope'         => (string) ($cur['scope'] ?? ''),
            'valueType'     => array_key_exists('valueType', $amend)     ? trim((string) $amend['valueType'])     : (string) ($cur['valueType'] ?? ''),
            'value'         => array_key_exists('value', $amend)         ? (float) $amend['value']                : (float) ($cur['value'] ?? 0),
            'effectiveFrom' => array_key_exists('effectiveFrom', $amend) ? trim((string) $amend['effectiveFrom']) : (string) ($cur['effectiveFrom'] ?? ''),
            'effectiveTo'   => array_key_exists('effectiveTo', $amend)   ? trim((string) $amend['effectiveTo'])   : (string) ($cur['effectiveTo'] ?? ''),
        ];

        $err = $this->_validateTerms($final);
        if ($err !== null) return ['ok' => false, 'error' => $err];

        // THE ZERO-VALUE GUARD — the heart of PR-1.
        if ($final['valueType'] !== 'fullExempt' && $final['value'] <= 0) {
            return ['ok' => false, 'error' =>
                'This request carries no value yet. Set the concession terms you are '
                . 'approving (a percentage, a fixed amount, or a full exemption) — '
                . 'approving it as-is would create an entitlement worth nothing.'];
        }

        $now   = date('c');
        $patch = [
            'status'       => 'active',
            'approvedBy'   => $this->actorId,   // I5 — never null on an active row
            'approvedAt'   => $now,
            'approvalNote' => trim($note),
            'updatedAt'    => $now,
        ];

        // Record the amendment as an amendment — including what it replaced, so
        // the family's original request survives the decision that answered it.
        $changed = [];
        foreach ($final as $k => $v) {
            $before = $k === 'value' ? (float) ($cur[$k] ?? 0) : (string) ($cur[$k] ?? '');
            if ($before !== $v) $changed[$k] = ['from' => $cur[$k] ?? null, 'to' => $v];
        }
        if ($changed !== []) {
            $patch += $final;
            $patch['effectiveTo']      = $final['effectiveTo'] !== '' ? $final['effectiveTo'] : null;
            $patch['amendedBy']        = $this->actorId;
            $patch['amendedAt']        = $now;
            $patch['amendedFields']    = array_keys($changed);
            $patch['originalProposal'] = [
                'scope'         => (string) ($cur['scope'] ?? ''),
                'valueType'     => (string) ($cur['valueType'] ?? ''),
                'value'         => (float)  ($cur['value'] ?? 0),
                'effectiveFrom' => (string) ($cur['effectiveFrom'] ?? ''),
                'effectiveTo'   => $cur['effectiveTo'] ?? null,
            ];
            // See the FINGERPRINT NOTE above. Stamped so the condition is
            // greppable rather than inferred from a field comparison.
            $patch['fingerprintStale'] = true;
        }

        $ok = $this->_write($entitlementId, $patch);
        if (!$ok) return ['ok' => false, 'error' => 'Could not approve. Please retry.'];
        $this->_audit('entitlement_approved', $entitlementId, (string) ($cur['studentId'] ?? ''),
            $changed === [] ? ['note' => $note]
                            : ['note' => $note, 'amended' => array_keys($changed)]);
        return ['ok' => true, 'status' => 'active', 'amended' => array_keys($changed)];
    }

    public function reject(string $entitlementId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') return ['ok' => false, 'error' => 'A reason is required to reject.'];
        $cur = $this->get($entitlementId);
        if ($cur === null) return ['ok' => false, 'error' => 'Entitlement not found.'];
        if (($cur['status'] ?? '') !== 'pending_approval') {
            return ['ok' => false, 'error' => 'Only a pending entitlement can be rejected (current: ' . ($cur['status'] ?? '?') . ').'];
        }
        $now = date('c');
        $ok = $this->_write($entitlementId, [
            'status'         => 'rejected',
            'rejectedBy'     => $this->actorId,
            'rejectedAt'     => $now,
            'rejectionReason'=> $reason,
            'updatedAt'      => $now,
        ]);
        if (!$ok) return ['ok' => false, 'error' => 'Could not reject. Please retry.'];
        $this->_audit('entitlement_rejected', $entitlementId, (string) ($cur['studentId'] ?? ''), ['reason' => $reason]);
        return ['ok' => true, 'status' => 'rejected'];
    }

    /**
     * End an entitlement. NEVER deletes — the row is the audit trail, and a
     * deleted concession is an unexplainable gap in a family's billing
     * history. Mirrors freezeFeesOnSoftDelete's preserve-don't-destroy shape.
     *
     * ── ENT-DISC-1 · A FUTURE END DATE IS NOW HONOURED ────────────────────
     *
     * This docblock has always claimed that "`effectiveTo` is stamped so the
     * readers' window logic stops matching it from that date, rather than
     * relying on status alone." That was the intent, and it was **not** what
     * happened.
     *
     * `Fee_entitlement_reader::activeConcessionsForStudent()` queries
     * `status == 'active'`, so flipping the status removed the row from the
     * candidate set instantly — before any window logic ran. The endpoint
     * accepts `effective_to` as a POST parameter and validates its format, so a
     * future date was reachable: a school ending a scholarship "from the end of
     * this term" ended it TODAY, and the family was billed at full rate months
     * early while the stored date appeared to say otherwise.
     *
     * THE FIX, AND WHY IT IS THIS ONE. A future end date leaves the status
     * **'active'** and stamps `effectiveTo`. The row stays in the candidate set
     * and the window logic excludes it from that date onward — exactly what the
     * docblock always described.
     *
     * The obvious alternative — teach the reader to also fetch 'discontinued'
     * rows via `status in [...]` — was rejected. That changes a live Firestore
     * query, and `firestore.indexes.json` records a deliberate decision that
     * this read has NO composite index because it is equality-only and served
     * by index merging, with an explicit "re-evaluate only if that read ever
     * gains an orderBy or a range filter". Changing the query to chase a fix
     * that does not need it would spend that decision for nothing.
     *
     * It is also the semantically true answer: an entitlement scheduled to end
     * next term IS still active today. Reporting it as discontinued while it is
     * still discounting bills would be its own inconsistency.
     *
     * WHAT DOES NOT CHANGE. A past or same-day date behaves exactly as before —
     * status flips to 'discontinued' immediately. That is the overwhelmingly
     * common case and the default when no date is supplied.
     *
     * MONTH GRANULARITY, deliberately. The window is evaluated per period, so a
     * concession ending mid-December still applies to December's demand. That
     * matches how every other price in this system is computed — per period,
     * not per day — and prorating a concession by days would be a different
     * feature with different accounting.
     *
     * RESCHEDULING is permitted: a scheduled row is still 'active', so calling
     * this again moves the date. That is intentional — an operator who set the
     * wrong end date must be able to correct it — and it is why the idempotency
     * guard below keys on the CLOSED statuses only.
     */
    public function discontinue(string $entitlementId, string $reason, string $effectiveTo = ''): array
    {
        $cur = $this->get($entitlementId);
        if ($cur === null) return ['ok' => false, 'error' => 'Entitlement not found.'];
        if (in_array((string) ($cur['status'] ?? ''), ['discontinued', 'rejected'], true)) {
            return ['ok' => true, 'status' => (string) $cur['status'], 'noop' => true];   // idempotent
        }
        $effectiveTo = trim($effectiveTo) !== '' ? trim($effectiveTo) : date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveTo)) {
            return ['ok' => false, 'error' => 'effectiveTo must be YYYY-MM-DD.'];
        }

        // ENT-DISC-1 — a future date schedules; today or earlier ends now.
        $scheduled = $effectiveTo > date('Y-m-d');

        $now = date('c');
        $patch = [
            'effectiveTo'        => $effectiveTo,
            'discontinuedBy'     => $this->actorId,
            'discontinuedAt'     => $now,
            'discontinueReason'  => trim($reason),
            'updatedAt'          => $now,
        ];

        if ($scheduled) {
            // Status deliberately NOT changed — see the note above. The marker
            // exists so a UI can show "ending on <date>" without re-deriving it,
            // and so a future backfill can find rows whose date has since passed.
            $patch['scheduledEnd'] = true;
        } else {
            $patch['status']       = 'discontinued';
            $patch['scheduledEnd'] = false;
        }

        $ok = $this->_write($entitlementId, $patch);
        if (!$ok) return ['ok' => false, 'error' => 'Could not discontinue. Please retry.'];
        $this->_audit($scheduled ? 'entitlement_end_scheduled' : 'entitlement_discontinued',
            $entitlementId, (string) ($cur['studentId'] ?? ''), [
            'reason' => $reason, 'effectiveTo' => $effectiveTo, 'scheduled' => $scheduled,
        ]);
        return $scheduled
            ? ['ok' => true, 'status' => 'active', 'scheduled' => true, 'effectiveTo' => $effectiveTo]
            : ['ok' => true, 'status' => 'discontinued'];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  READS
    // ─────────────────────────────────────────────────────────────────────

    public function get(string $entitlementId): ?array
    {
        if ($this->firebase === null || $entitlementId === '') return null;
        try {
            $d = $this->firebase->firestoreGet(self::COL, $entitlementId);
            if (!is_array($d)) return null;
            // Tenant isolation: never return another school's row even if the
            // id were guessed. The id embeds schoolId, but this is the guard
            // that makes it enforced rather than merely conventional.
            if ((string) ($d['schoolId'] ?? '') !== $this->schoolId) return null;
            return $d;
        } catch (\Throwable $e) {
            log_message('error', 'Entitlement_service::get failed for ' . $entitlementId . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * The approval queue. Consumes the Phase B composite index
     * (schoolId, status, requestedAt) — ordered by REQUEST AGE so an approver
     * works oldest-first (adversarial review M2).
     */
    public function pendingQueue(int $limit = 100): array
    {
        if ($this->firebase === null) return [];
        try {
            $rows = $this->firebase->firestoreQuery(self::COL, [
                ['schoolId', '==', $this->schoolId],
                ['status',   '==', 'pending_approval'],
            ], 'requestedAt', 'ASC', max(1, min($limit, 250)));
            $out = [];
            foreach ((array) $rows as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : [];
                if ($d !== []) $out[] = $d;
            }
            return $out;
        } catch (\Throwable $e) {
            log_message('error', 'Entitlement_service::pendingQueue failed: ' . $e->getMessage());
            return [];
        }
    }

    /** All entitlements for a student, any status — capture-surface display. */
    public function forStudent(string $studentId, int $limit = 100): array
    {
        if ($this->firebase === null || $studentId === '') return [];
        try {
            $rows = $this->firebase->firestoreQuery(self::COL, [
                ['schoolId',  '==', $this->schoolId],
                ['studentId', '==', $studentId],
            ], null, 'ASC', max(1, min($limit, 250)));
            $out = [];
            foreach ((array) $rows as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : [];
                if ($d !== []) $out[] = $d;
            }
            return $out;
        } catch (\Throwable $e) {
            log_message('error', 'Entitlement_service::forStudent failed for ' . $studentId . ': ' . $e->getMessage());
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  INTERNALS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The PRICING TERMS of an entitlement — scope, value type, value, window.
     *
     * Extracted at P6 so propose() and approve()'s PR-1 amendment path run the
     * IDENTICAL rules. A second copy would be a second answer to "is this a
     * legal entitlement", and the two would drift.
     *
     * Deliberately excludes identity (studentId, kind, serviceType,
     * concessionType) and provenance (reason): an approver amends what a grant
     * is WORTH, never who or what it is for.
     *
     * @return string|null error message, or null when valid
     */
    private function _validateTerms(array $in): ?string
    {
        // Scope must be one of the three canonical shapes; a malformed scope
        // silently matching nothing (or everything) is a billing defect.
        $scope = trim((string) ($in['scope'] ?? ''));
        if ($scope !== 'all' && strpos($scope, 'head:') !== 0 && strpos($scope, 'category:') !== 0)
            // P0: the preferred target is the school-scoped feeHeadRef. The
            // legacy per-chart feeHeadId is still accepted so existing records
            // keep resolving — validation is unchanged, only the guidance.
            return "scope must be 'all', 'head:<feeHeadRef>' (legacy: 'head:<feeHeadId>') or 'category:<category>'.";
        if ($scope !== 'all' && trim(substr($scope, strpos($scope, ':') + 1)) === '')
            return 'A scoped entitlement needs a target after the colon.';

        $vt = (string) ($in['valueType'] ?? '');
        if (!in_array($vt, self::VALUE_TYPES, true))
            return 'valueType must be one of: ' . implode(', ', self::VALUE_TYPES) . '.';
        $val = (float) ($in['value'] ?? 0);
        if ($vt === 'percent' && ($val < 0 || $val > 100)) return 'A percent value must be between 0 and 100.';
        // F3: `amountDue` was removed from VALUE_TYPES above, so it can no
        // longer reach this line; the guard is now simply "a fixed amount is
        // never negative".
        if ($vt === 'fixed' && $val < 0) return 'Value must be zero or greater.';

        $from = (string) ($in['effectiveFrom'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) return 'effectiveFrom must be YYYY-MM-DD.';
        $to = (string) ($in['effectiveTo'] ?? '');
        if ($to !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) return 'effectiveTo must be YYYY-MM-DD.';
            if (strcmp($to, $from) < 0) return 'effectiveTo cannot be earlier than effectiveFrom.';
        }
        return null;
    }

    /** @return string|null error message, or null when valid */
    private function _validate(array $in): ?string
    {
        $studentId = trim((string) ($in['studentId'] ?? ''));
        if ($studentId === '')                              return 'student_id is required.';
        if (!in_array((string) ($in['kind'] ?? ''), self::KINDS, true))
            return 'kind must be one of: ' . implode(', ', self::KINDS) . '.';

        $kind = (string) $in['kind'];
        if ($kind === 'service' && trim((string) ($in['serviceType'] ?? '')) === '')
            return 'serviceType is required for a service entitlement.';
        if ($kind === 'concession') {
            $ct = (string) ($in['concessionType'] ?? 'other');
            if (!in_array($ct, self::CONCESSION_TYPES, true))
                return 'concessionType must be one of: ' . implode(', ', self::CONCESSION_TYPES) . '.';
        }

        // The pricing terms — shared verbatim with approve()'s amendment path
        // (PR-1). ONE implementation, so an amended entitlement can never pass
        // a rule a proposed one would have failed.
        $err = $this->_validateTerms($in);
        if ($err !== null) return $err;

        // I1 retired — `frequency` is no longer part of the entitlement
        // contract, so a submitted value is neither required nor rejected: it
        // is simply ignored. See the class docblock.

        // ── DEC-004 · A HEAD SCOPE MUST NAME A REGISTERED HEAD ───────────────
        //
        // THE DEFECT THIS CLOSES — one logical fee head, discounted twice.
        //
        // `_fingerprintId` hashes `scope` as a RAW STRING, so `head:FH_TUI`
        // (the school-scoped registry ref) and `head:FH_ROWID` (a legacy
        // per-chart-row id) produce DIFFERENT document ids. Create-if-not-exists
        // therefore never collides, both documents live, both can be approved,
        // and both match a registry-stamped demand — tier 1 and tier 2 of
        // Fee_entitlement_reader — whereupon _applyDeductions() SUMS them.
        // Nothing could tell they named the same head: the two id formats are
        // shape-identical (`FH_` + 12 uppercase hex, one generator).
        //
        // The fix is to REFUSE, never to translate. Deliberately NOT:
        //   • no feeHeadId → feeHeadRef mapping (would need a chart read and a
        //     guess about which id space a target came from);
        //   • no name matching (the name is display identity and is mutable —
        //     rule 2 of Fee_head_resolver);
        //   • no compatibility translation layer, which would be a second
        //     identity authority by another name.
        //
        // `_fingerprintId` is UNTOUCHED. This does not canonicalise anything;
        // it narrows what may be created, so the stored scope is already
        // canonical because nothing else is accepted.
        //
        // CREATION ONLY. It lives here rather than in _validateTerms() so that
        // re-validating a STORED scope (approve/amend) can never retroactively
        // refuse a legacy record. Existing legacy head scopes stay readable
        // until an explicit retirement decision — the reader is untouched.
        //
        // Timed for the migration-free window: the P2-A census proved
        // studentEntitlements = 0 across all nine tenants, so no stored record
        // is invalidated. After the first head-scoped grant exists, tightening
        // this would be a migration.
        $scope = trim((string) ($in['scope'] ?? ''));
        if (strpos($scope, 'head:') === 0) {
            $err = $this->_assertRegisteredHead(trim(substr($scope, 5)));
            if ($err !== null) return $err;
        }

        // ── MEALS-1 · A CATEGORY SCOPE MUST NAME A CATEGORY ZenX CAN BILL ───
        //
        // `category:` scopes were accepted with NO vocabulary check at all, so
        // `category:banana` created a perfectly valid, approved, audited
        // entitlement that `appliesTo()` could never match — it compares the
        // target against a demand's own `category`. A silent no-op wearing the
        // costume of an approved concession.
        //
        // The reachable instance is MEALS. `Fee_head_resolver::ASSIGNABLE`
        // deliberately withholds it, and says exactly why:
        //
        //   "'meals' is reserved but withheld: ZenX bills no meals today (no
        //    module, controller, collection or head exists), and offering it
        //    would let a school classify heads into a category nothing
        //    consumes."
        //
        // That decision was made and then not enforced here. Two admin views
        // (`fee_management/concessions.php`, `_entitlements_capture.php`) and
        // `Entitlement_requests::REQUEST_TYPES` all offer Meals, so a school can
        // approve free meals for a family, audit it, show it as ACTIVE — and no
        // bill will ever reflect it, because ZenX raises no meal charge. A
        // promise the system cannot keep.
        //
        // There is a second, later hazard: if meals billing is ever added,
        // these pre-existing grants activate RETROACTIVELY on their stored
        // effectiveFrom dates and discount the first meal bills nobody expected
        // them to touch.
        //
        // Delegating to ASSIGNABLE rather than restating the list keeps ONE
        // authority: the day a meals module exists, adding 'meals' there
        // enables it here with no change to this file.
        //
        // CREATION ONLY, exactly like DEC-004 above and for the same reason —
        // re-validating a STORED scope at approve/amend time could retroactively
        // refuse a record that already exists.
        if (strpos($scope, 'category:') === 0) {
            $cat = strtolower(trim(substr($scope, 9)));
            if ($cat === '') return 'A scoped entitlement needs a target after the colon.';

            // Fee_head_resolver is loaded LAZILY by _headResolver(), which only
            // runs for a head: scope. A category: scope can therefore be the
            // first thing to reference the class in a request, so it must be
            // loaded here or this guard fatals instead of validating.
            if (!class_exists('Fee_head_resolver')) {
                require_once APPPATH . 'libraries/Fee_head_resolver.php';
            }

            if (!in_array($cat, Fee_head_resolver::ASSIGNABLE, true)) {
                $known = in_array($cat, Fee_head_resolver::CATEGORIES, true);
                return $known
                    ? "ZenX raises no '{$cat}' charge, so a concession scoped to it could never "
                      . 'be applied. Remove the scope or bill that service first.'
                    : "'{$cat}' is not a fee category. A scope must name one of: "
                      . implode(', ', Fee_head_resolver::ASSIGNABLE) . '.';
            }
        }

        if (trim((string) ($in['reason'] ?? '')) === '') return 'A reason is required.';

        // I4 — an RTE/EWS concession without a government receivable is an
        // unrecorded public debt. Refuse it rather than create one.
        if ($kind === 'concession' && (string) ($in['concessionType'] ?? '') === 'rte_ews') {
            $amt = (float) ($in['govtReceivableAmount'] ?? 0);
            if ($amt <= 0)
                return 'An RTE/EWS entitlement requires govtReceivableAmount greater than zero (invariant I4).';
        }
        return null;
    }

    /**
     * DEC-004 — the head target must resolve to an ACTIVE, validly classified
     * head in this school's `feeHeadRegistry`. See the rationale in _validate().
     *
     * Delegates to Fee_head_resolver, the single fee-head authority, so this
     * class never learns the registry's shape and no second reader appears.
     * Fails CLOSED: an unresolvable target, an archived head, an invalid
     * category or a read failure all refuse the proposal. Refusing to create a
     * concession is recoverable; creating one that silently double-discounts is
     * not.
     *
     * @return string|null error message, or null when the head is registered
     */
    private function _assertRegisteredHead(string $target): ?string
    {
        if ($target === '') return 'A scoped entitlement needs a target after the colon.';

        $r = $this->_headResolver();
        if ($r === null) {
            log_message('error', 'Entitlement_service: fee-head resolver unavailable; head-scoped proposal refused.');
            return 'Fee heads cannot be verified right now. Please retry.';
        }

        // resolveForNewUse(), NOT resolve(): the former also refuses an ARCHIVED
        // head. `resolve()` returns the status without enforcing it — correct
        // for pricing an existing demand that legitimately references a head
        // since retired, wrong for attaching a NEW entitlement to one.
        $res = $r->resolveForNewUse($this->schoolId, $target);
        if (!empty($res['ok'])) return null;

        $reason = (string) ($res['reason'] ?? 'unresolved');
        log_message('error', 'ENTITLEMENT_HEAD_SCOPE_UNREGISTERED school=[' . $this->schoolId
            . '] target=[' . $target . '] reason=[' . $reason . ']');

        // Say WHICH failure it was — "not found" and "archived" need different
        // actions from the operator, and a generic message sends them hunting.
        if ($reason === 'invalid_category') {
            return 'That fee head has no valid category in the Fee Head Registry, so an '
                 . 'entitlement cannot be scoped to it. Classify the head first.';
        }
        return 'The fee head for this entitlement is not registered for this school. '
             . "Pick a fee head from the list — a head-scoped entitlement must name a "
             . 'registered fee head (feeHeadRef), not a typed name or a chart-row id.';
    }

    /** Lazily built fee-head authority. Injectable in tests via the private prop. */
    private function _headResolver()
    {
        if ($this->headResolver !== null) return $this->headResolver;
        if ($this->fs === null) return null;
        try {
            $CI = function_exists('get_instance') ? get_instance() : null;
            if ($CI !== null && isset($CI->load)) {
                $CI->load->library('Fee_head_resolver', null, 'feeHeadResolver');
                $r = $CI->feeHeadResolver;
            } else {
                if (!class_exists('Fee_head_resolver')) {
                    require_once APPPATH . 'libraries/Fee_head_resolver.php';
                }
                $r = new Fee_head_resolver();
            }
            return $this->headResolver = $r->init($this->fs);
        } catch (\Throwable $e) {
            log_message('error', 'Entitlement_service::_headResolver failed: ' . $e->getMessage());
            return null;
        }
    }

    /** I4 — build the receivable for RTE/EWS, null for everything else. */
    private function _govtReceivable(array $in): ?array
    {
        if ((string) ($in['kind'] ?? '') !== 'concession') return null;
        if ((string) ($in['concessionType'] ?? '') !== 'rte_ews') return null;
        return [
            'amountExpected' => round((float) ($in['govtReceivableAmount'] ?? 0), 2),
            'status'         => 'pending',
            'lastFollowUp'   => null,
        ];
    }

    /**
     * PHASE 3B / B5 — the business identity of an entitlement, as a doc id.
     *
     * Two proposals describe the SAME entitlement when every field that changes
     * what the student is billed is the same: who, what kind, over what scope,
     * at what value, for what window, in what session. Anything else — a
     * different scope, a different percentage, a different effective date — is a
     * materially different entitlement and must remain distinguishable.
     *
     * DELIBERATELY EXCLUDED from the fingerprint: `reason`, `source`,
     * `requestedBy` and the timestamps. They are provenance, not price. Letting
     * a re-typed reason mint a second active concession is exactly the duplicate
     * this closes.
     */
    private function _fingerprintId(string $studentId, array $in): string
    {
        $parts = [
            $this->schoolId,
            $studentId,
            (string) ($in['kind'] ?? ''),
            (string) ($in['serviceType'] ?? ''),
            (string) ($in['concessionType'] ?? ''),
            trim((string) ($in['scope'] ?? '')),
            (string) ($in['valueType'] ?? ''),
            // Normalised so "20", "20.0" and 20 are one entitlement, not three.
            number_format((float) ($in['value'] ?? 0), 4, '.', ''),
            // `frequency` deliberately ABSENT (P2 contract freeze, I1). It never
            // changed what a student was billed, so two proposals that differ
            // only in it were never materially different entitlements.
            (string) ($in['effectiveFrom'] ?? ''),
            (string) ($in['effectiveTo'] ?? ''),
            (string) (($in['session'] ?? '') !== '' ? $in['session'] : $this->session),
        ];
        return $this->schoolId . '_' . $studentId . '_E' . substr(sha1(implode('|', $parts)), 0, 20);
    }

    /**
     * Create-if-not-exists. Returns SUCCESS | COLLISION | FAILURE.
     *
     * COLLISION must never be conflated with FAILURE: the first means "this
     * entitlement already exists, and that is fine", the second means "the
     * outcome is unknown". Treating a collision as a failure would make the UI
     * report an error for a perfectly successful retry; treating a failure as a
     * collision would silently drop a real entitlement.
     */
    private function _createOnce(string $docId, array $data): string
    {
        if ($this->firebase === null || $docId === '') return 'FAILURE';
        try {
            if (method_exists($this->firebase, 'firestoreCreateStatus')) {
                $r = $this->firebase->firestoreCreateStatus(self::COL, $docId, $data);
                return (string) ($r['status'] ?? 'FAILURE');
            }
            // Older handle without the status API: a bare create still refuses
            // to overwrite, but cannot distinguish 409 from a transport error.
            // Fall back to a read to classify, which is safe because a
            // pre-existing document is exactly what a collision means.
            if ((bool) $this->firebase->firestoreCreate(self::COL, $docId, $data)) return 'SUCCESS';
            return is_array($this->get($docId)) ? 'COLLISION' : 'FAILURE';
        } catch (\Throwable $e) {
            log_message('error', 'Entitlement_service::_createOnce failed for ' . $docId . ': ' . $e->getMessage());
            return 'FAILURE';
        }
    }

    /** Merge-write. The ONLY place this class touches Firestore for writes. */
    private function _write(string $docId, array $data): bool
    {
        if ($this->firebase === null || $docId === '') return false;
        try {
            return (bool) $this->firebase->firestoreSet(self::COL, $docId, $data, /* merge */ true);
        } catch (\Throwable $e) {
            log_message('error', 'Entitlement_service::_write failed for ' . $docId . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Every mutation is audited through the EXISTING audit helper — not a new
     * audit store. Baseline §Data Model: "auditRef: auto — every write appends
     * to auditLogs".
     */
    private function _audit(string $action, string $entitlementId, string $studentId, array $ctx = []): void
    {
        try {
            if (function_exists('log_audit')) {
                log_audit('Fees', $action, $entitlementId,
                    $action . ' for student ' . $studentId . ' — ' . json_encode($ctx),
                    array_merge($ctx, ['studentId' => $studentId, 'entitlementId' => $entitlementId]));
            }
        } catch (\Throwable $_) { /* audit must never fail the mutation */ }
    }
}
