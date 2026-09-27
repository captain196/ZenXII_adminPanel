<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  P5-BF · HISTORICAL ALLOCATION BACKFILL — THE DECISION, NOTHING ELSE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Decides, for each historical demand, whether the allocation ledger should
 * gain rows explaining its discount — and refuses wherever the answer cannot
 * be established from present state.
 *
 * ── WHY THIS IS A SEPARATE, PURE CLASS ────────────────────────────────────
 * It writes nothing, reads nothing, and needs no framework. Every rule below
 * is therefore directly executable by a test with hand-built fixtures, which
 * is the only way a money-path decision of this shape can be proven rather
 * than reviewed. The CLI supplies data and performs I/O; this file decides.
 *
 * It also deliberately does NOT build allocation rows.
 * `Entitlement_allocation_service::build()` owns identity, tenant/session
 * isolation and over-allocation refusal, and remains the single authority —
 * this planner names the (demand, entitlement, amount) triples and lets that
 * service have the last word. There is no second persister and no second
 * definition of what an allocation is.
 *
 * ── THE INVARIANT BEING REPAIRED ──────────────────────────────────────────
 * I2:  discountAmount == SUM(allocatedAmount WHERE status == 'active')
 *
 * A demand carrying a discount with no allocation rows does not merely fail a
 * check — nothing records WHY that family was charged less. The ledger is the
 * explanation; `discountAmount` is only its total.
 *
 * ── HOW A HISTORICAL SPLIT IS RECOVERED ───────────────────────────────────
 * The split was never stored. It is recomputed with the generator's own
 * arithmetic (Fee_generation_service, "PER-ENTITLEMENT CONTRIBUTIONS"):
 *
 *     percent    →  gross * (value / 100)
 *     fixed      →  value
 *     fullExempt →  the whole charge
 *
 * ── AND WHY THAT IS NOT ALWAYS POSSIBLE ───────────────────────────────────
 * Recomputation uses TODAY's entitlements. A grant edited, revoked or added
 * since the demand was priced makes the recomputed split disagree with the
 * stored total. That demand's history is then not recoverable from present
 * state, and this planner REFUSES it.
 *
 * It never apportions the difference, never rounds it away, never picks the
 * nearest plausible grant. A missing row is visible and fixable; a wrong row
 * is a permanent, auditable claim about why a family was charged what they
 * were charged. Refusing is the only honest failure mode here.
 */
class Ledger_backfill_planner
{
    /** Money tolerance — the same one Entitlement_allocation_service uses. */
    public const EPSILON = 0.005;

    // Outcomes. One demand yields exactly one of these.
    public const CREATE = 'create';
    public const SKIP   = 'skip';
    public const REFUSE = 'refuse';

    /**
     * @param string $schoolId    the bound tenant — never taken from the data
     * @param string $session     '' to accept any session
     * @param array  $demands     feeDemands docs
     * @param array  $entitlements studentEntitlements docs
     * @param array  $existing    entitlementAllocations docs
     * @return array{decisions:array,totals:array}
     */
    public static function plan(
        string $schoolId,
        string $session,
        array $demands,
        array $entitlements,
        array $existing
    ): array {
        $schoolId = trim($schoolId);
        $decisions = [];

        $totals = [
            'demands_scanned'   => 0,
            'no_discount'       => 0,
            'already_explained' => 0,
            'rows_to_create'    => 0,
            'refused'           => 0,
            'discount_total'    => 0.0,
            'allocated_after'   => 0.0,
        ];

        // A bound tenant is mandatory. Without it every tenant check below is
        // vacuous, and a blank id must never be treated as "match anything".
        if ($schoolId === '') {
            return ['decisions' => [[
                'demandId' => '', 'outcome' => self::REFUSE,
                'reason'   => 'no_bound_tenant',
                'detail'   => 'planner invoked without a school id; refusing the entire run',
            ]], 'totals' => $totals];
        }

        // Index existing allocations by id, and by demand for the sum.
        $byId = [];
        $activeByDemand = [];
        foreach ($existing as $a) {
            if (!is_array($a)) continue;
            // A row belonging to another tenant must never satisfy or block a
            // decision in this one.
            if (trim((string) ($a['schoolId'] ?? '')) !== $schoolId) continue;
            $id = trim((string) ($a['allocationId'] ?? ''));
            if ($id !== '') $byId[$id] = $a;
            if (strtolower(trim((string) ($a['status'] ?? 'active'))) !== 'active') continue;
            $did = trim((string) ($a['demandId'] ?? ''));
            if ($did === '') continue;
            $activeByDemand[$did] = ($activeByDemand[$did] ?? 0.0) + (float) ($a['allocatedAmount'] ?? 0);
        }

        foreach ($demands as $d) {
            if (!is_array($d)) continue;
            $totals['demands_scanned']++;

            $did   = trim((string) ($d['demandId'] ?? ''));
            $disc  = round((float) ($d['discountAmount'] ?? 0), 2);
            $gross = round((float) ($d['grossAmount'] ?? 0), 2);
            $dSch  = trim((string) ($d['schoolId'] ?? ''));
            $dSess = trim((string) ($d['session'] ?? ''));

            // TENANT. Checked before anything else: a foreign demand must not
            // even be classified, let alone allocated against.
            if ($dSch !== $schoolId) {
                $decisions[] = self::refusal($did, 'tenant_mismatch',
                    "demand belongs to [{$dSch}], planner bound to [{$schoolId}]");
                $totals['refused']++;
                continue;
            }
            if ($session !== '' && $dSess !== '' && $dSess !== $session) {
                $decisions[] = self::refusal($did, 'session_mismatch',
                    "demand session [{$dSess}] is outside the requested [{$session}]");
                $totals['refused']++;
                continue;
            }

            // No discount ⇒ I2 says nothing. Creating a row here would invent
            // an explanation for a charge that was never reduced.
            if ($disc <= self::EPSILON) {
                $decisions[] = ['demandId' => $did, 'outcome' => self::SKIP, 'reason' => 'no_discount'];
                $totals['no_discount']++;
                continue;
            }

            $totals['discount_total'] += $disc;

            if ($did === '') {
                $decisions[] = self::refusal('', 'missing_demand_id',
                    'a discounted demand carries no demandId; an allocation cannot be keyed to it');
                $totals['refused']++;
                continue;
            }

            // Already explained, exactly? Then there is nothing to do — and
            // this is what makes a re-run a no-op at the decision layer, on top
            // of the create-if-absent guarantee at the write layer.
            $have = round((float) ($activeByDemand[$did] ?? 0), 2);
            if ($have > self::EPSILON) {
                if (abs($have - $disc) <= self::EPSILON) {
                    $decisions[] = ['demandId' => $did, 'outcome' => self::SKIP,
                                    'reason' => 'already_explained'];
                    $totals['already_explained']++;
                    $totals['allocated_after'] += $have;
                    continue;
                }
                // Rows exist but do not add up. Something already wrote a
                // partial or wrong explanation. Overwriting or topping it up
                // would silently rewrite a financial record whose provenance we
                // do not know — refuse and surface it.
                $decisions[] = self::refusal($did, 'conflicting_existing_allocations',
                    "existing active allocations total {$have} but the demand's discount is {$disc}; "
                    . 'refusing to overwrite or top up an existing ledger row');
                $totals['refused']++;
                $totals['allocated_after'] += $have;
                continue;
            }

            if ($gross <= 0) {
                $decisions[] = self::refusal($did, 'no_charge_basis',
                    "demand has a discount of {$disc} but a gross of {$gross}");
                $totals['refused']++;
                continue;
            }

            // ── Recompute the split from present entitlements ──────────────
            $contribs = [];
            $sum = 0.0;
            $unknown = null;
            foreach ($entitlements as $e) {
                if (!is_array($e)) continue;
                if (!self::applies($e, $d, $schoolId)) continue;

                $type = strtolower(trim((string) ($e['valueType'] ?? '')));
                $val  = (float) ($e['value'] ?? 0);

                if ($type === 'fullexempt') {
                    $contribs = [['ent' => $e, 'amount' => $gross]];
                    $sum = $gross;
                    break;   // a full exemption subsumes the rest, as in generation
                }
                if ($type === 'percent') {
                    $part = round($gross * ($val / 100.0), 2);
                    $contribs[] = ['ent' => $e, 'amount' => $part];
                    $sum += $part;
                    continue;
                }
                if ($type === 'fixed') {
                    $part = round($val, 2);
                    $contribs[] = ['ent' => $e, 'amount' => $part];
                    $sum += $part;
                    continue;
                }
                // A rule this algorithm cannot apply. Do not skip it quietly —
                // its contribution is unknown, so the whole demand is unsafe.
                $unknown = $type === '' ? '(blank)' : $type;
                break;
            }

            if ($unknown !== null) {
                $decisions[] = self::refusal($did, 'unknown_value_type',
                    "an applicable entitlement has valueType [{$unknown}], which this "
                    . 'reconstruction cannot compute; refusing rather than guessing');
                $totals['refused']++;
                continue;
            }
            if ($contribs === []) {
                $decisions[] = self::refusal($did, 'no_applicable_entitlement',
                    "demand carries a discount of {$disc} but no current entitlement applies to it; "
                    . 'the history is not recoverable from present state');
                $totals['refused']++;
                continue;
            }

            $sum = round($sum, 2);
            if (abs($sum - $disc) > self::EPSILON) {
                $decisions[] = self::refusal($did, 'reconstruction_mismatch',
                    "recomputed split {$sum} does not equal the stored discount {$disc}; a grant "
                    . 'changed since pricing — refusing (never apportioned, never rounded)');
                $totals['refused']++;
                continue;
            }

            // ── Everything reconciles: emit the triples ───────────────────
            $rows = [];
            foreach ($contribs as $ct) {
                $eid = trim((string) ($ct['ent']['entitlementId'] ?? ''));
                if ($eid === '') {
                    $rows = null;
                    break;
                }
                $allocId = self::allocationId($schoolId, $did, $eid);
                if (isset($byId[$allocId])) continue;   // idempotent
                $rows[] = ['allocationId' => $allocId, 'entitlementId' => $eid,
                           'amount' => $ct['amount'], 'entitlement' => $ct['ent']];
            }

            if ($rows === null) {
                $decisions[] = self::refusal($did, 'entitlement_missing_id',
                    'an applicable entitlement has no entitlementId; an allocation cannot be keyed to it');
                $totals['refused']++;
                continue;
            }
            if ($rows === []) {
                // Every id already present but the active sum was 0 — i.e. the
                // rows exist and are REVERSED. Not our repair to make.
                $decisions[] = self::refusal($did, 'existing_rows_inactive',
                    'allocation rows already exist for this demand but none are active; '
                    . 'reinstating a reversed allocation is a deliberate act, not a backfill');
                $totals['refused']++;
                continue;
            }

            $decisions[] = ['demandId' => $did, 'outcome' => self::CREATE,
                            'demand' => $d, 'rows' => $rows];
            $totals['rows_to_create'] += count($rows);
            foreach ($rows as $r) $totals['allocated_after'] += $r['amount'];
        }

        $totals['discount_total']  = round($totals['discount_total'], 2);
        $totals['allocated_after'] = round($totals['allocated_after'], 2);

        return ['decisions' => $decisions, 'totals' => $totals];
    }

    /** Deterministic identity — must mirror Entitlement_allocation_service. */
    public static function allocationId(string $schoolId, string $demandId, string $entitlementId): string
    {
        return trim($schoolId) . '_' . trim($demandId) . '_' . trim($entitlementId);
    }

    /**
     * Does this grant apply to this demand? Conservative: a grant that names a
     * scope must match it; a grant that names nothing is school-wide.
     *
     * The tenant check is repeated here on purpose. `plan()` already refused
     * foreign DEMANDS; this refuses a foreign ENTITLEMENT being applied to a
     * legitimate demand, which is the other half of the same boundary.
     */
    private static function applies(array $e, array $d, string $schoolId): bool
    {
        if (trim((string) ($e['schoolId'] ?? '')) !== $schoolId) return false;
        if (strtolower(trim((string) ($e['status'] ?? ''))) !== 'active') return false;

        $eStu = trim((string) ($e['studentId'] ?? ''));
        if ($eStu !== '' && $eStu !== trim((string) ($d['studentId'] ?? ''))) return false;

        $eSess = trim((string) ($e['session'] ?? ''));
        $dSess = trim((string) ($d['session'] ?? ''));
        if ($eSess !== '' && $dSess !== '' && $eSess !== $dSess) return false;

        $eHead = trim((string) ($e['feeHeadRef'] ?? $e['feeHeadId'] ?? ''));
        if ($eHead !== '') {
            $dHead = trim((string) ($d['feeHeadRef'] ?? $d['feeHeadId'] ?? ''));
            if ($dHead === '' || $eHead !== $dHead) return false;
        }
        return true;
    }

    private static function refusal(string $demandId, string $reason, string $detail): array
    {
        return ['demandId' => $demandId, 'outcome' => self::REFUSE,
                'reason' => $reason, 'detail' => $detail];
    }
}
