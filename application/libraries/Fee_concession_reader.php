<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_concession_reader — read-only access to studentConcessions for the
 * unified generator. Phase 1 of Fees Exemption v2. NO writes.
 *
 * Source of truth: studentConcessions docs written by Fee_concessions
 * (Phase 0). Until P2 cutover, this reader is exercised ONLY by the A/B
 * verification probe — production generation paths still use legacy.
 */
class Fee_concession_reader
{
    /** @var Firebase|null */
    private $firebase;

    public function init($firebase): void
    {
        $this->firebase = $firebase;
    }

    /**
     * All active studentConcessions for (schoolId, studentId, session).
     * Returns docs where status='active' AND (session matches OR session=null).
     * Effective-window overlap is checked per demand via appliesTo() — this
     * method returns the candidate set, not the per-demand decision.
     */
    public function activeConcessionsForStudent(string $schoolId, string $studentId, string $session): array
    {
        if ($this->firebase === null) return [];

        $rows = [];
        try {
            $rows = $this->firebase->firestoreQuery('studentConcessions', [
                ['schoolId',  '==', $schoolId],
                ['studentId', '==', $studentId],
                ['status',    '==', 'active'],
            ], null, 'ASC', 200);
        } catch (\Throwable $e) {
            log_message('error', "Fee_concession_reader: query failed for {$studentId}: " . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $cSess = $d['session'] ?? null;
            // session=null → applies to all sessions; otherwise must match.
            if ($cSess !== null && (string) $cSess !== $session) continue;
            $out[] = $d;
        }
        return $out;
    }

    /**
     * Does this concession apply to the demand identified by (periodKey,
     * feeHeadId, category)? periodKey is 'YYYY-MM' (April→year-04 etc.).
     *
     * Match rules (all must hold):
     *  1. Scope matches: scope='all', OR scope='head' AND targetFeeHeadId matches,
     *     OR scope='category' AND targetCategory matches the demand's category.
     *  2. Effective-window overlap: effectiveFrom <= last-of-period-month,
     *     AND (effectiveTo is null OR effectiveTo >= first-of-period-month).
     */
    /**
     * @param string $feeHeadRef DEC-009 (2026-08-18) — ACCEPTED AND DELIBERATELY
     *   IGNORED. Legacy `studentConcessions` records carry the head target in
     *   `targetFeeHeadId`, never as a `head:<feeHeadRef>` scope string, so this
     *   reader has nothing to resolve it against and its behaviour is unchanged.
     *
     *   It exists so both readers expose ONE call contract. The caller in
     *   Fee_generation_service is handed whichever reader the rollout flag
     *   selected and cannot know which it holds; when the signatures diverged,
     *   that caller silently dropped the argument and made
     *   Fee_entitlement_reader's first match tier unreachable in production —
     *   billing head-scoped entitlements at gross with no error. A shared
     *   signature makes that class of mistake impossible to write.
     */
    public function appliesTo(array $concession, string $periodKey, string $feeHeadId, string $feeHead, string $category = '', string $feeHeadRef = ''): bool
    {
        // Scope
        $scope = (string) ($concession['scope'] ?? '');
        if ($scope === 'head') {
            $target = (string) ($concession['targetFeeHeadId'] ?? '');
            if ($target === '' || $target !== $feeHeadId) {
                // Fallback: some chart heads may not have stable IDs — accept name match too.
                $targetName = (string) ($concession['targetFeeHead'] ?? '');
                if ($targetName === '' || $targetName !== $feeHead) return false;
            }
        } elseif ($scope === 'category') {
            $target = (string) ($concession['targetCategory'] ?? '');
            if ($target === '' || strcasecmp($target, $category) !== 0) return false;
        } elseif ($scope !== 'all') {
            return false;
        }

        // Effective window vs the demand's month
        if (!preg_match('/^(\d{4})-(\d{2})$/', $periodKey, $m)) return false;
        $year  = (int) $m[1];
        $month = (int) $m[2];
        $firstOfMonth = sprintf('%04d-%02d-01', $year, $month);
        $lastOfMonth  = sprintf('%04d-%02d-%02d', $year, $month, (int) date('t', strtotime($firstOfMonth)));

        $effFrom = (string) ($concession['effectiveFrom'] ?? '');
        $effTo   = $concession['effectiveTo'] ?? null;
        if ($effFrom === '') return false;
        if (strcmp($effFrom, $lastOfMonth) > 0) return false;
        if ($effTo !== null && $effTo !== '' && strcmp((string) $effTo, $firstOfMonth) < 0) return false;

        return true;
    }

    /**
     * ── PHASE 3B / B2 — THE ACCESSOR BOUNDARY ───────────────────────────────
     *
     * Deduction vocabulary accessors, mirroring
     * Fee_entitlement_reader::deductionType()/deductionValue() exactly.
     *
     * WHY THESE EXIST HERE. Before this, Fee_generation_service asked a rule for
     * its deduction semantics by reaching into LEGACY field names inline —
     * `$c['type']` and `$c['value']`. An entitlement stores those as `valueType`
     * / `value` and carries NO `type` field at all, so swapping the reader alone
     * would have fetched the right ROWS through the wrong FIELDS: `type` would
     * resolve to '', no branch would match, and every entitlement would deduct
     * ZERO. An approved concession, a gross bill, and no error — the precise
     * failure this programme exists to close, reintroduced by its own fix.
     *
     * With both readers exposing the same two accessors, the generator asks the
     * reader and never learns which collection or field name backs the answer.
     * The reader is the only thing that changes at cutover.
     *
     * `Phase3bEntitlementBillingCutoverTest` fails if the generator ever names a
     * source field again.
     */
    public function deductionType(array $concession): string
    {
        return (string) ($concession['type'] ?? '');
    }

    public function deductionValue(array $concession): float
    {
        return (float) ($concession['value'] ?? 0);
    }

    /**
     * ── LEDGER-EMIT-1 · THIS READER HAS NO LEDGER IDENTITY ──────────────────
     *
     * The allocation ledger explains a discount by pointing at the GRANT that
     * caused it, using the deterministic identity
     * `{schoolId}_{demandId}_{entitlementId}`. A legacy `studentConcessions`
     * record has no entitlement identity — it carries `concessionId`, which
     * names a different thing in a different collection with no entitlement
     * lifecycle behind it (no approval record, no effective-dated grant row).
     *
     * Minting an `entitlementId` from a `concessionId` would fabricate a
     * reference to a document that does not exist, so every ledger row would
     * point at nothing and the audit trail the ledger exists to provide would
     * be a lie that reconciles.
     *
     * So this reader declares, honestly, that it cannot back a ledger. The
     * generator asks BEFORE pricing and treats the ledger as off for this
     * authority, instead of pricing a discount and then having every row
     * refused one-by-one inside the allocation service — which is exactly how
     * LEDGER-EMIT-1 stayed invisible: 25 refusal lines that read like data
     * problems rather than one configuration answer.
     */
    public function supportsAllocationLedger(): bool
    {
        return false;
    }

    /** No entitlement identity exists in this shape. Never invent one. */
    public function ledgerIdentity(array $concession): string
    {
        return '';
    }

}
