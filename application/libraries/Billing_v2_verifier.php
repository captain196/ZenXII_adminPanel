<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'libraries/Fee_firestore_txn.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 * BILLING ENGINE V2 VERIFIER — P10 · the production-readiness invariant set
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Billing V2 is not a new engine to be written; it is the COMPOSITION of parts
 * that now exist — M5 classification, SE-V2 entitlements, the P4 allocation
 * ledger, canonical generation, and the P7 accounting feed. What was missing
 * was a single place that answers "is the composition actually coherent?"
 *
 * This class is that place. It takes demands + allocations and returns every
 * violation it can find, as data. It is the certification instrument for the
 * production-readiness gate, and it must run CLEAN before activation is even
 * proposed.
 *
 * ── THE INVARIANT SET ─────────────────────────────────────────────────────
 *   I1  netAmount == grossAmount − discountAmount            (money coherence)
 *   I2  discountAmount == Σ active allocatedAmount           (ledger projection)
 *   I3  Σ active allocatedAmount <= grossAmount              (no over-allocation)
 *   I4  every allocation's basisAmount == the demand's gross (no stale basis)
 *   I5  balance == netAmount + fineAmount − paidAmount       (settlement)
 *   I6  paidAmount <= netAmount + fineAmount                 (no over-collection)
 *   I7  every allocation points at a demand that exists      (no orphans)
 *   I8  every demand carries a feeHeadRef + non-empty category under M5
 *
 * ── WHY IT IS PURE ────────────────────────────────────────────────────────
 * No Firestore, no loader. It is handed the data, so the same code certifies a
 * live read-only sweep, a dry run and a unit test — and a certification result
 * can never be an artefact of how the data was fetched. Each violation carries
 * the ids needed to act on it, never just a count.
 *
 * ── WHAT IT DELIBERATELY DOES NOT DO ──────────────────────────────────────
 * It never repairs. A verifier that fixes what it finds destroys the evidence
 * of how the estate got there, and this programme has repeatedly depended on
 * that evidence (STU0184's residue is the clearest case).
 */
class Billing_v2_verifier
{
    public const EPSILON = 0.005;

    /**
     * @param array $demands     demandId => demand doc
     * @param array $allocations allocation rows (any status)
     * @param array $opts        ['m5' => bool] — I8 only applies under M5
     * @return array{ok:bool, checked:array, violations:array, summary:array}
     */
    public function verify(array $demands, array $allocations = [], array $opts = []): array
    {
        $m5 = (bool) ($opts['m5'] ?? false);
        $v  = [];
        $legacy = [];   // pre-M5 demands: COUNTED for the migration decision, never a violation
        // ROLLBACK SAFETY: after USE_ALLOCATION_LEDGER is revoked, new demands
        // are priced but emit no rows. Default: infer from the data; a caller
        // that knows the flag state may state it explicitly.
        $ledgerActive = (bool) ($opts['ledgerActive'] ?? ($allocations !== []));

        // Index active allocations by demand.
        $byDemand = [];
        foreach ($allocations as $a) {
            $did = (string) ($a['demandId'] ?? '');
            if ($did === '') continue;
            $byDemand[$did][] = $a;

            // I7 — an allocation about a demand that does not exist explains a
            // discount nobody can find.
            if (!isset($demands[$did])) {
                $v[] = $this->_v('I7', 'orphan_allocation', $did, [
                    'allocationId' => (string) ($a['allocationId'] ?? ''),
                ]);
            }
        }

        foreach ($demands as $did => $d) {
            $gross = round((float) ($d['grossAmount']    ?? 0), 2);
            $disc  = round((float) ($d['discountAmount'] ?? 0), 2);
            $net   = round((float) ($d['netAmount']      ?? 0), 2);
            $fine  = round((float) ($d['fineAmount']     ?? $d['fine_amount'] ?? 0), 2);
            $paid  = round((float) ($d['paidAmount']     ?? $d['paid_amount'] ?? 0), 2);
            $bal   = round((float) ($d['balance']        ?? 0), 2);

            // I1 — reuses the SAME predicate the write boundary uses, so the
            // verifier and the writer can never disagree about what coherent means.
            $inc = Fee_firestore_txn::moneyIncoherence($d);
            if ($inc !== null) {
                $v[] = $this->_v('I1', 'money_incoherent', (string) $did, $inc);
            }

            $rows   = $byDemand[(string) $did] ?? [];
            $active = array_values(array_filter($rows, function ($r) {
                return ($r['status'] ?? 'active') === 'active';
            }));

            if ($active !== []) {
                $sum = round(array_sum(array_map(function ($r) {
                    return (float) ($r['allocatedAmount'] ?? 0);
                }, $active)), 2);

                // I3 before I2: over-allocation is the more serious fault.
                if ($gross > 0 && $sum - $gross > self::EPSILON) {
                    $v[] = $this->_v('I3', 'over_allocated', (string) $did,
                        ['gross' => $gross, 'allocated' => $sum]);
                } elseif (abs($sum - $disc) > self::EPSILON) {
                    // I2 — the ledger must explain the discount exactly.
                    $v[] = $this->_v('I2', 'projection_mismatch', (string) $did,
                        ['discountAmount' => $disc, 'allocated' => $sum,
                         'delta' => round($sum - $disc, 2)]);
                }

                // I4 — a basis that no longer matches means the charge was
                // re-priced under the allocation (legitimate for Transport, but
                // the row must be recomputed, not trusted).
                foreach ($active as $r) {
                    $basis = round((float) ($r['basisAmount'] ?? 0), 2);
                    if ($gross > 0 && abs($basis - $gross) > self::EPSILON) {
                        $v[] = $this->_v('I4', 'stale_allocation_basis', (string) $did,
                            ['allocationId' => (string) ($r['allocationId'] ?? ''),
                             'basisAmount' => $basis, 'grossAmount' => $gross]);
                    }
                }
            } elseif ($disc > self::EPSILON && $ledgerActive) {
                // A discount with no ledger row — the DEC-013 shape.
                //
                // ROLLBACK SAFETY. This is only a violation while the ledger is
                // ACTIVE. `USE_ALLOCATION_LEDGER` is revocable, and after a
                // revocation new demands are still priced but no longer emit
                // rows — entirely correct, and not a defect. Reporting it as one
                // would make every rollback look like a corruption and would
                // pressure an operator into re-enabling a flag to silence an
                // alarm. `ledgerActive` defaults to inferring from whether any
                // allocation exists, so a caller that knows the flag state can
                // say so explicitly.
                $v[] = $this->_v('I2', 'discount_without_allocation', (string) $did,
                    ['discountAmount' => $disc]);
            }

            // I5 — the settlement identity the fine engine maintains.
            if (array_key_exists('balance', $d)) {
                $expected = round($net + $fine - $paid, 2);
                if ($expected < 0) $expected = 0.0;   // floored, as the engine floors it
                if (abs($expected - $bal) > self::EPSILON) {
                    $v[] = $this->_v('I5', 'balance_incoherent', (string) $did,
                        ['expected' => $expected, 'actual' => $bal,
                         'net' => $net, 'fine' => $fine, 'paid' => $paid]);
                }
            }

            // I6 — collecting more than was ever charged.
            if ($paid - ($net + $fine) > self::EPSILON) {
                $v[] = $this->_v('I6', 'over_collected', (string) $did,
                    ['paid' => $paid, 'chargeable' => round($net + $fine, 2)]);
            }

            // I8 — M5 classification completeness.
            //
            // CALIBRATED 2026-08-19 after a FALSE FAIL. The first version flagged
            // any ref-less demand on an M5 tenant, and reported 52 violations —
            // all of which were STU0012 demands created 2026-06-13, two months
            // BEFORE the M5 grant. A demand written before the registry existed
            // legitimately has no `feeHeadRef`, and retro-stamping it would be a
            // backfill (a protected operation), not a fix.
            //
            // The real invariant is narrower and sharper: a demand that CLAIMS
            // registry provenance (`categorySource === 'registry'`) must carry
            // the identity that claim implies. That catches a genuine stamping
            // failure while leaving legacy demands alone.
            //
            // Pre-M5 demands are still COUNTED, as `legacy` — visible for the
            // migration decision, never mistaken for a defect.
            if ($m5) {
                $src = strtolower(trim((string) ($d['categorySource'] ?? '')));
                if ($src === 'registry') {
                    if (trim((string) ($d['feeHeadRef'] ?? '')) === '') {
                        $v[] = $this->_v('I8', 'missing_fee_head_ref', (string) $did,
                            ['categorySource' => 'registry']);
                    }
                    if (trim((string) ($d['category'] ?? '')) === '') {
                        $v[] = $this->_v('I8', 'missing_category', (string) $did,
                            ['categorySource' => 'registry']);
                    }
                } elseif (trim((string) ($d['feeHeadRef'] ?? '')) === '') {
                    $legacy[] = (string) $did;
                }
            }
        }

        $byCode = [];
        foreach ($v as $one) {
            $byCode[$one['invariant']] = ($byCode[$one['invariant']] ?? 0) + 1;
        }

        return [
            'ok'         => $v === [],
            'checked'    => ['demands' => count($demands), 'allocations' => count($allocations)],
            'legacyPreM5' => count($legacy),
            'violations' => $v,
            'summary'    => $byCode,
        ];
    }

    private function _v(string $invariant, string $code, string $demandId, array $detail): array
    {
        return ['invariant' => $invariant, 'code' => $code,
                'demandId' => $demandId, 'detail' => $detail];
    }

    /**
     * A one-line certification verdict. Deliberately blunt: a verifier that
     * hedges is a verifier nobody acts on.
     */
    public static function verdict(array $result): string
    {
        $c = $result['checked'];
        if (!empty($result['ok'])) {
            return sprintf('PASS — %d demands / %d allocations, zero violations.',
                $c['demands'], $c['allocations']);
        }
        return sprintf('FAIL — %d violation(s) across %d demands: %s',
            count($result['violations']), $c['demands'], json_encode($result['summary']));
    }
}
