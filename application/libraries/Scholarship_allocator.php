<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 * SCHOLARSHIP ALLOCATOR — Phase C, implementing the APPROVED design
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Implements `SCHOLARSHIP_PHASE_C_TECHNICAL_DESIGN.md` §2–§6 verbatim. Every
 * rule below is quoted from that document; **nothing here is invented**, and in
 * particular the per-period / whole-session question is NOT decided here — the
 * design decides it, and this class obeys.
 *
 * ── WHAT IT FIXES ─────────────────────────────────────────────────────────
 * OBS-SCH-1, measured: today `Fees::_generateDemandsForMonth` applies the FULL
 * award inside every month, so a ₹425 award discounts ₹5,100.01 across a
 * 12-month session (~12×). The design names this as the defect:
 *   "Generation: applies `_scholarship` per month → design: one allocation over
 *    the full session set."
 *
 * P3D-F3, also from the design: the percentage base sums ALL heads regardless of
 * frequency, mixing monthly with annual (500+50+200+1000 = 1750), which
 * distorts every month's rate. The base must be monthly-only, in scope.
 *
 * ── TWO BASES, NOT ONE ────────────────────────────────────────────────────
 *   • `per_period`    (percentage) — share computed WITHIN each period.
 *   • `whole_session` (fixed)      — one eligible-gross-weighted allocation
 *                                    across the entire session set.
 * Explicitly NOT `/12`: the design rejects it for unequal periods (April carries
 * the annual head), mid-session joiners and partial generation.
 *
 * ── THE HONEST GAP, CARRIED FORWARD NOT HIDDEN ────────────────────────────
 * §4: service classification has three signals and none is sufficient alone —
 * `feeCategories` is empty live, the name-substring fallback is a heuristic, and
 * canonical specs carry no `category` at all. The design flags this as its
 * weakest point and says the real fix is DATA (populate `feeCategories`), not
 * code. `classify()` below follows the specified order and returns the signal it
 * used, so a caller can tell a heuristic answer from an authoritative one.
 *
 * ── STATUS ────────────────────────────────────────────────────────────────
 * PURE and UNWIRED: no Firestore, no loader, no callers. Implementing it is
 * preparation; changing live scholarship billing is a separate authorization.
 */
class Scholarship_allocator
{
    public const BASIS_PER_PERIOD    = 'per_period';
    public const BASIS_WHOLE_SESSION = 'whole_session';

    /** Service categories — a scholarship must opt in deliberately to reach them. */
    public const SERVICE_CATEGORIES = ['transport', 'hostel', 'meals'];

    public const EPSILON = 0.005;

    /**
     * §4 — classify a head, in the design's order:
     *   explicit `feeCategories` mapping → the head's own `category` → name
     *   heuristic → default `academic`.
     *
     * Returns the category AND the signal used, so a heuristic answer is never
     * mistaken for an authoritative one.
     *
     * @return array{category:string, signal:string}
     */
    public static function classify(string $feeHead, array $spec = [], array $feeCategories = []): array
    {
        $name = trim($feeHead);
        $key  = strtolower($name);

        if ($feeCategories !== [] && isset($feeCategories[$key]) && trim((string) $feeCategories[$key]) !== '') {
            return ['category' => strtolower(trim((string) $feeCategories[$key])), 'signal' => 'mapping'];
        }
        $own = strtolower(trim((string) ($spec['category'] ?? '')));
        if ($own !== '' && $own !== 'unknown') {
            return ['category' => $own, 'signal' => 'head'];
        }
        // LAST resort, and deliberately marked as such.
        foreach (['transport' => ['transport', 'bus'],
                  'hostel'    => ['hostel', 'boarding'],
                  'meals'     => ['meal', 'mess', 'canteen', 'food']] as $cat => $needles) {
            foreach ($needles as $n) {
                if (stripos($key, $n) !== false) return ['category' => $cat, 'signal' => 'heuristic'];
            }
        }
        return ['category' => 'academic', 'signal' => 'default'];
    }

    /** §4 — does this head fall inside the award's scope? */
    public static function inScope(string $feeHead, array $spec, string $scope, array $feeCategories = []): bool
    {
        $scope = trim($scope) !== '' ? trim($scope) : 'academic';
        $cat   = self::classify($feeHead, $spec, $feeCategories)['category'];

        if ($scope === 'all') return true;
        if ($scope === 'academic') {
            return !in_array($cat, self::SERVICE_CATEGORIES, true);
        }
        if (strpos($scope, 'category:') === 0) {
            return $cat === strtolower(trim(substr($scope, 9)));
        }
        if (strpos($scope, 'head:') === 0) {
            $t = trim(substr($scope, 5));
            return $t !== '' && (
                $t === (string) ($spec['feeHeadId'] ?? '')
                || strcasecmp($t, $feeHead) === 0
            );
        }
        return false;   // unrecognised scope → never applies (fail closed)
    }

    /**
     * §2 (P3D-F3) — the percentage base.
     *
     * "percentageBase = Σ feeHeads[].amount WHERE frequency = 'monthly' AND
     *  head is in scope". Annual heads are excluded because the award is
     * per-period; an annual head is billed once and would distort every month.
     *
     * @param array $chartHeads list of ['name','amount','frequency', ...]
     */
    public static function percentageBase(array $chartHeads, string $scope = 'academic',
                                          array $feeCategories = []): float
    {
        $base = 0.0;
        foreach ($chartHeads as $h) {
            $freq = strtolower(trim((string) ($h['frequency'] ?? 'monthly')));
            if ($freq === 'annual' || $freq === 'yearly') continue;      // §2
            $name = (string) ($h['name'] ?? '');
            if (!self::inScope($name, $h, $scope, $feeCategories)) continue;
            $base += (float) ($h['amount'] ?? 0);
        }
        return round($base, 2);
    }

    /**
     * §3 — the eligible spec set.
     *
     * Excluded: fully exempt heads; out-of-scope heads; demands that are not
     * `unpaid`; and demands already carrying `concessionApplied` from a
     * different authority (preserves the existing fence).
     */
    public static function eligibleSpecs(array $specs, string $scope, string $session,
                                         array $feeCategories = []): array
    {
        $out = [];
        foreach ($specs as $s) {
            $d = is_array($s['data'] ?? null) ? $s['data'] : $s;
            $head = (string) ($d['feeHead'] ?? '');
            if ($head === '') continue;

            $status = strtolower((string) ($d['status'] ?? 'unpaid'));
            if ($status !== 'unpaid') continue;                                  // never re-price
            if ((float) ($d['paidAmount'] ?? 0) > self::EPSILON) continue;       // belt and braces
            if ((float) ($d['grossAmount'] ?? 0) <= 0) continue;                 // nothing to discount
            if ((float) ($d['concessionApplied'] ?? 0) > self::EPSILON) continue; // other authority
            if ($session !== '' && (string) ($d['session'] ?? $session) !== $session) continue;
            if (!self::inScope($head, $d, $scope, $feeCategories)) continue;

            $out[] = $d;
        }
        return $out;
    }

    /**
     * §5 + §6 — allocate an award across the eligible specs.
     *
     * `per_period`    : share is computed within each period (§5.1).
     * `whole_session` : one eligible-gross-weighted allocation over the whole
     *                   set, in deterministic (periodKey, feeHeadId) order, with
     *                   the LAST spec absorbing the rounding drift (§5.2).
     *
     * §6 cap: `effective = min(award, totalEligibleGross)` — no demand goes
     * below zero and the student can never receive credit. The unused remainder
     * is RETURNED (`unallocated`) so it can be recorded on the award rather than
     * silently discarded.
     *
     * Computed from GROSS every run, never from a previous net, so repeated
     * recalculation is idempotent (§5.2).
     *
     * @return array{shares:array<string,float>, effective:float, unallocated:float, totalEligible:float}
     */
    public static function allocate(array $eligible, float $award, string $basis): array
    {
        $none = ['shares' => [], 'effective' => 0.0, 'unallocated' => round(max(0.0, $award), 2),
                 'totalEligible' => 0.0];
        if ($eligible === [] || $award <= 0) return $none;

        $keyOf = function (array $d): string {
            return (string) ($d['periodKey'] ?? '') . '|' . (string) ($d['feeHeadId'] ?? ($d['feeHead'] ?? ''));
        };

        if ($basis === self::BASIS_PER_PERIOD) {
            // §5.1 — within each period, weighted by gross, capped per spec.
            $byPeriod = [];
            foreach ($eligible as $d) $byPeriod[(string) ($d['periodKey'] ?? '')][] = $d;

            $shares = []; $total = 0.0; $applied = 0.0;
            foreach ($byPeriod as $specs) {
                $periodGross = 0.0;
                foreach ($specs as $d) $periodGross += (float) ($d['grossAmount'] ?? 0);
                if ($periodGross <= 0) continue;
                foreach ($specs as $d) {
                    $g = (float) ($d['grossAmount'] ?? 0);
                    $share = round(min($award * ($g / $periodGross), $g), 2);
                    $shares[$keyOf($d)] = $share;
                    $applied += $share;
                }
                $total += $periodGross;
            }
            return ['shares' => $shares, 'effective' => round($applied, 2),
                    'unallocated' => 0.0, 'totalEligible' => round($total, 2)];
        }

        // §5.2 — whole_session, eligible-gross weighted.
        $totalE = 0.0;
        foreach ($eligible as $d) $totalE += (float) ($d['grossAmount'] ?? 0);
        $totalE = round($totalE, 2);
        if ($totalE <= 0) return $none;

        $effective = round(min($award, $totalE), 2);          // §6 cap

        // Deterministic order so generation and recalculation cannot disagree.
        usort($eligible, function ($a, $b) use ($keyOf) { return strcmp($keyOf($a), $keyOf($b)); });

        $shares = []; $sum = 0.0; $lastKey = '';
        foreach ($eligible as $d) {
            $g = (float) ($d['grossAmount'] ?? 0);
            $share = round($effective * ($g / $totalE), 2);
            $k = $keyOf($d);
            $shares[$k] = $share;
            $sum += $share;
            $lastKey = $k;
        }
        // Deterministic remainder: the LAST spec absorbs the rounding drift, so
        // Σ share == effective EXACTLY — never `award ± n paise`.
        if ($lastKey !== '') {
            $shares[$lastKey] = round($shares[$lastKey] + ($effective - $sum), 2);
        }

        return ['shares' => $shares, 'effective' => $effective,
                'unallocated' => round(max(0.0, $award - $effective), 2),
                'totalEligible' => $totalE];
    }
}
