<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 * FEE RECALCULATION PLANNER — P5 · recompute IN PLACE, never delete
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Today `Fees::recalc_unpaid_discounts()` recalculates by DELETING a student's
 * untouched demands and regenerating them through the legacy generator. That
 * design is the direct cause of two separate defects:
 *
 *   • DEC-013 — regeneration runs through an entitlement-BLIND path, so an
 *     approved concession is silently rebuilt at gross. (Contained today by a
 *     fail-closed guard; the guard is debt this class exists to retire.)
 *   • Demand identity is destroyed — `newDemandId()` mints fresh ids, so every
 *     external reference to the old demand breaks and nothing can be
 *     reconciled across a recalculation.
 *
 * The replacement: compare what EXISTS against what SHOULD exist, and emit a
 * PLAN. Same demand, same id, amounts corrected in place. Nothing is deleted,
 * ever — an obsolete demand is REPORTED, because deleting a charge is a
 * financial act that must be a human decision, not a side effect of pressing
 * "recalculate".
 *
 * ── THE PAYMENT RULE ──────────────────────────────────────────────────────
 * A demand that has been paid, even partly, is NEVER rewritten. Its amounts are
 * settled money and a receipt already refers to them. Where the recomputed
 * charge differs, the plan raises an ADJUSTMENT for a human — it does not
 * quietly restate what the family was told they owed.
 *
 * ── PURE ─────────────────────────────────────────────────────────────────
 * This class reads nothing and writes nothing. It is handed the existing
 * demands and the expected specs and returns a plan. That makes every rule
 * below directly testable, and makes a dry-run identical to the real thing.
 *
 * WIRED (P5-E, 2026-08-20). `Fees::_recalculateStudentCanonical()` loads this
 * planner and applies its `update` outcomes as merge patches onto the EXISTING
 * demand ids. The header previously read "NOT WIRED. Nothing calls this yet",
 * which stayed after P5-E landed and is exactly the kind of stale note that
 * produces a wrong conclusion in an audit — corrected 2026-08-23 by following
 * the call graph rather than the comment.
 *
 * The DEC-013 guard it refers to is likewise RETIRED: recalculation no longer
 * deletes and regenerates through the legacy generator, so the reason that
 * guard existed is gone.
 */
class Fee_recalculation_planner
{
    /** Money comparison tolerance — half a paisa, as everywhere else. */
    public const EPSILON = 0.005;

    public const UPDATE   = 'update';    // unpaid, amounts differ → safe to correct in place
    public const CREATE   = 'create';    // expected but missing → create with a deterministic id
    public const UNCHANGED = 'unchanged';// already correct → touch nothing
    public const ADJUST   = 'adjust';    // paid/partial and amounts differ → needs a human
    public const PROTECT  = 'protect';   // paid/partial and already correct → leave alone
    public const OBSOLETE = 'obsolete';  // exists but no longer in the chart → REPORT, never delete

    /**
     * The identity a demand is matched on across a recalculation.
     *
     * `feeHead|periodKey` — the SAME dual key `Fee_lifecycle` and the legacy
     * generator already use for duplicate suppression (DEC-007). Deliberately
     * NOT `demandId`: the whole point is to find the existing demand that
     * represents this charge so its id can be PRESERVED.
     */
    public static function chargeKey(array $d): string
    {
        $head   = strtolower(trim((string) ($d['feeHead'] ?? '')));
        $period = trim((string) ($d['periodKey'] ?? ''));
        return $head . '|' . $period;
    }

    /**
     * Compare existing demands against expected specs and return the plan.
     *
     * @param array $existing array<demandId, demandDoc>
     * @param array $expected list of spec entries: ['data' => [...]]
     * @return array{
     *   update:array, create:array, unchanged:array, adjust:array,
     *   protect:array, obsolete:array, summary:array
     * }
     */
    public function plan(array $existing, array $expected): array
    {
        $out = [self::UPDATE => [], self::CREATE => [], self::UNCHANGED => [],
                self::ADJUST => [], self::PROTECT => [], self::OBSOLETE => []];

        // Index the existing demands by charge key. A key holding MORE THAN ONE
        // demand is an identity conflict (the DEC-007 shape): it is never
        // auto-resolved here, because picking one of two real charges to
        // overwrite is exactly the kind of silent decision this class refuses.
        $byKey = [];
        foreach ($existing as $did => $d) {
            $byKey[self::chargeKey($d)][(string) $did] = $d;
        }

        $seen = [];
        foreach ($expected as $entry) {
            $spec = is_array($entry['data'] ?? null) ? $entry['data'] : $entry;
            $key  = self::chargeKey($spec);
            $seen[$key] = true;

            $matches = $byKey[$key] ?? [];
            if (count($matches) > 1) {
                foreach ($matches as $did => $d) {
                    $out[self::ADJUST][(string) $did] = [
                        'reason' => 'identity_conflict',
                        'key'    => $key,
                        'demandIds' => array_keys($matches),
                    ];
                }
                continue;
            }

            if ($matches === []) {
                $out[self::CREATE][] = $spec;   // a charge that should exist and does not
                continue;
            }

            $did = (string) array_key_first($matches);
            $cur = $matches[$did];

            $paid = (float) ($cur['paidAmount'] ?? $cur['paid_amount'] ?? 0);
            $diff = $this->_differences($cur, $spec);

            if ($paid > self::EPSILON) {
                // SETTLED MONEY. Never rewritten — a receipt already refers to
                // these amounts.
                if ($diff === []) { $out[self::PROTECT][$did] = ['reason' => 'paid_and_correct']; }
                else {
                    $out[self::ADJUST][$did] = [
                        'reason' => 'paid_amount_changed',
                        'paid'   => round($paid, 2),
                        'diff'   => $diff,
                    ];
                }
                continue;
            }

            if ($diff === []) { $out[self::UNCHANGED][$did] = true; continue; }

            // UNPAID and wrong → correct it in place, keeping the demand id.
            $out[self::UPDATE][$did] = ['diff' => $diff, 'patch' => $this->_patch($spec, $cur)];
        }

        // Anything left over is no longer produced by the chart.
        foreach ($byKey as $key => $matches) {
            if (isset($seen[$key])) continue;
            foreach ($matches as $did => $d) {
                $paid = (float) ($d['paidAmount'] ?? $d['paid_amount'] ?? 0);
                $out[self::OBSOLETE][(string) $did] = [
                    'reason' => $paid > self::EPSILON ? 'obsolete_but_paid' : 'obsolete_unpaid',
                    'key'    => $key,
                    'paid'   => round($paid, 2),
                ];
            }
        }

        $out['summary'] = [
            self::UPDATE => count($out[self::UPDATE]),   self::CREATE => count($out[self::CREATE]),
            self::UNCHANGED => count($out[self::UNCHANGED]), self::ADJUST => count($out[self::ADJUST]),
            self::PROTECT => count($out[self::PROTECT]), self::OBSOLETE => count($out[self::OBSOLETE]),
            'deletes' => 0,   // structural: this planner has no delete verb at all
        ];
        return $out;
    }

    /**
     * Which money fields differ. Only the CHARGE is compared — payment state is
     * never part of a recalculation, and `fineAmount` belongs to the balance,
     * not the charge.
     */
    private function _differences(array $cur, array $spec): array
    {
        $diff = [];
        foreach (['grossAmount', 'discountAmount', 'netAmount'] as $f) {
            $a = round((float) ($cur[$f]  ?? 0), 2);
            $b = round((float) ($spec[$f] ?? 0), 2);
            if (abs($a - $b) > self::EPSILON) $diff[$f] = ['from' => $a, 'to' => $b];
        }
        return $diff;
    }

    /**
     * The patch for an unpaid demand: the corrected charge, and the balance
     * re-derived fine-aware. `paidAmount` is absent by construction (this branch
     * only runs when nothing is paid), and the demand id is untouched.
     */
    private function _patch(array $spec, array $cur): array
    {
        $net  = round((float) ($spec['netAmount'] ?? 0), 2);
        $fine = round((float) ($cur['fineAmount'] ?? $cur['fine_amount'] ?? 0), 2);
        return [
            'grossAmount'    => round((float) ($spec['grossAmount'] ?? 0), 2),
            'discountAmount' => round((float) ($spec['discountAmount'] ?? 0), 2),
            'netAmount'      => $net,
            // balance = net + fine − paid, and paid is zero here. Preserving the
            // fine matters: proration once dropped it silently (Phase 15.5 F-1).
            'balance'        => round($net + $fine, 2),
        ];
    }

    /**
     * Is this plan safe to apply without a human? True only when it touches no
     * settled money, resolves no identity conflict and abandons no charge.
     */
    public function isAutoApplicable(array $plan): bool
    {
        return ($plan['summary'][self::ADJUST]   ?? 0) === 0
            && ($plan['summary'][self::OBSOLETE] ?? 0) === 0;
    }
}
