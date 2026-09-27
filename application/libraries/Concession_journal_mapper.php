<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 * CONCESSION JOURNAL MAPPER — P7 · the missing input to an existing engine
 * ══════════════════════════════════════════════════════════════════════════
 *
 * P7 needs no new accounting subsystem. `Operations_accounting::_buildGranularLines()`
 * ALREADY does the textbook-correct thing: recognise income at GROSS and post
 * each concession as its own Dr contra-revenue line, routed by
 * `_resolveConcessionAccount()` across eight mapped account codes.
 *
 * **It reads `$alloc['concessions']`, and nothing in the codebase populates it.**
 * A repo-wide grep finds that key only inside `Operations_accounting` — the
 * reader and its own doc comment, never a writer. So `$concList` is always
 * empty and the contra-revenue loop is a permanent no-op, regardless of the
 * `concessions_enabled` flag. Today a concession silently reduces recognised
 * revenue instead of being posted as contra-revenue.
 *
 * This class is that missing writer. It turns P4 allocation rows into the shape
 * the engine already documents:
 *
 *   ['fee_head' => 'Tuition Fee', 'amount' => 3500,
 *    'concessions' => [ ['type' => 'Merit Scholarship', 'amount' => 1500] ]]
 *
 * ── THE TYPE STRING IS THE ROUTING KEY ────────────────────────────────────
 * `_resolveConcessionAccount()` keyword-matches `type`, so the string must land
 * on the intended account. Mapped deliberately, not guessed:
 *
 *   scholarship → "Merit Scholarship"          → 4991 (merit|scholarship)
 *   sibling     → "Sibling Discount"           → 4993 (sibling)
 *   staff_ward  → "Staff Ward Concession"      → 4994 (staff)
 *   rte_ews     → "Government RTE/EWS Waiver"  → 4997 (government|rte|ews|waiver)
 *   other/blank → "Fee Concession"             → 4990 generic contra-revenue
 *
 * An operator's explicit `concession:<type>` config mapping still overrides all
 * of this inside the resolver — this class only chooses the label.
 *
 * ── THE ONE OPEN ACCOUNTING QUESTION, FLAGGED NOT DECIDED ─────────────────
 * On a PARTIAL payment, how much of the concession is recognised?
 *
 *   PROPORTIONAL (default here) — recognise `discount × paid/net`. Keeps the
 *       engine's own stated invariant ("income is recognised at GROSS") true
 *       proportionally: Cr income = paid + recognised = the gross share of what
 *       was actually collected.
 *   FULL — recognise the whole concession on the first payment. Simpler, but
 *       over-recognises contra-revenue relative to collection, and a later
 *       reversal has to unwind it.
 *
 * PROPORTIONAL is the default because it is DERIVED from the engine's existing
 * invariant rather than chosen — but the mode is an explicit argument, and
 * `RECOGNITION_FULL` is implemented, so the decision stays visible and
 * reversible instead of being silently baked in. **A full payment is identical
 * under both modes**, which is the overwhelmingly common case.
 *
 * ── STATUS ────────────────────────────────────────────────────────────────
 * PURE and UNWIRED: no Firestore, no loader, no callers. It cannot run for real
 * until P4 allocations are persisted (blocked on the rules deploy), so wiring it
 * now would only feed it an empty ledger.
 */
class Concession_journal_mapper
{
    /** Recognise the concession in proportion to what was collected (default). */
    public const RECOGNITION_PROPORTIONAL = 'proportional';
    /** Recognise the whole concession on any payment. */
    public const RECOGNITION_FULL = 'full';

    public const EPSILON = 0.005;

    /** concessionType → the label `_resolveConcessionAccount()` keys on. */
    public const TYPE_LABELS = [
        'scholarship' => 'Merit Scholarship',
        'sibling'     => 'Sibling Discount',
        'staff_ward'  => 'Staff Ward Concession',
        'rte_ews'     => 'Government RTE/EWS Waiver',
        'other'       => 'Fee Concession',
    ];

    /** Map one entitlement's concessionType to its journal label. */
    public static function typeLabel(string $concessionType): string
    {
        $k = strtolower(trim($concessionType));
        return self::TYPE_LABELS[$k] ?? self::TYPE_LABELS['other'];
    }

    /**
     * Attach `concessions[]` to each payment allocation.
     *
     * @param array $allocations payment allocations: ['fee_head','amount','demandId', ...]
     * @param array $ledgerRows  P4 allocation rows, any status
     * @param array $demandsById demandId => demand doc (for net/discount context)
     * @param string $mode       RECOGNITION_*
     * @return array the same allocations, with `concessions` added where applicable
     */
    public static function attach(array $allocations, array $ledgerRows,
                                  array $demandsById = [],
                                  string $mode = self::RECOGNITION_PROPORTIONAL): array
    {
        // Index ACTIVE ledger rows by demand. A reversed row has been unwound
        // and must never post contra-revenue.
        $byDemand = [];
        foreach ($ledgerRows as $r) {
            if (($r['status'] ?? 'active') !== 'active') continue;
            $did = (string) ($r['demandId'] ?? '');
            if ($did === '') continue;
            $byDemand[$did][] = $r;
        }

        $out = [];
        foreach ($allocations as $alloc) {
            $did = (string) ($alloc['demandId'] ?? $alloc['demand_id'] ?? '');
            $paid = round((float) ($alloc['amount'] ?? $alloc['allocated'] ?? 0), 2);
            $rows = $byDemand[$did] ?? [];

            if ($did === '' || $paid <= 0 || $rows === []) { $out[] = $alloc; continue; }

            // The recognition fraction. `netAmount` is what the family owes
            // AFTER the concession, so paid/net is the share of the charge that
            // has actually been settled.
            $fraction = 1.0;
            if ($mode === self::RECOGNITION_PROPORTIONAL) {
                $net = round((float) ($demandsById[$did]['netAmount'] ?? 0), 2);
                // No net context, or a fully-settled demand, recognises in full.
                $fraction = ($net > self::EPSILON) ? min(1.0, $paid / $net) : 1.0;
            }

            $concessions = [];
            foreach ($rows as $r) {
                $amount = round((float) ($r['allocatedAmount'] ?? 0) * $fraction, 2);
                if ($amount <= self::EPSILON) continue;   // the engine skips zeros anyway
                $concessions[] = [
                    'type'   => self::typeLabel((string) ($r['concessionType'] ?? 'other')),
                    'amount' => $amount,
                    // Provenance — not read by the engine, but it makes a journal
                    // line traceable back to the exact grant that caused it.
                    'entitlementId' => (string) ($r['entitlementId'] ?? ''),
                    'allocationId'  => (string) ($r['allocationId'] ?? ''),
                ];
            }

            if ($concessions !== []) $alloc['concessions'] = $concessions;
            $out[] = $alloc;
        }
        return $out;
    }

    /**
     * The double-entry check this mapping must satisfy, for ONE allocation:
     *
     *     Cr income (gross share) == paid + Σ recognised concessions
     *
     * Returns null when coherent. The engine boosts the income credit by each
     * concession amount, so if this does not hold the journal will not balance
     * against what was actually collected.
     */
    public static function recognitionIncoherence(array $alloc, array $demand,
                                                  string $mode = self::RECOGNITION_PROPORTIONAL): ?array
    {
        $paid = round((float) ($alloc['amount'] ?? 0), 2);
        $conc = 0.0;
        foreach (($alloc['concessions'] ?? []) as $c) $conc += (float) ($c['amount'] ?? 0);
        $conc = round($conc, 2);

        $net   = round((float) ($demand['netAmount'] ?? 0), 2);
        $gross = round((float) ($demand['grossAmount'] ?? 0), 2);
        if ($net <= self::EPSILON) return null;

        // The gross share corresponding to what was collected.
        $expected = ($mode === self::RECOGNITION_PROPORTIONAL)
            ? round($gross * min(1.0, $paid / $net), 2)
            : round($paid + ($gross - $net), 2);

        $actual = round($paid + $conc, 2);
        if (abs($expected - $actual) > 0.02) {   // 2p tolerance: two roundings
            return ['expected' => $expected, 'actual' => $actual,
                    'paid' => $paid, 'recognised' => $conc,
                    'delta' => round($actual - $expected, 2)];
        }
        return null;
    }
}
