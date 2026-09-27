<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Full & Final — leave encashment INPUT resolver.
 *
 * PURE LOGIC. No Firestore, no CI dependencies, and **no money**. It answers one
 * question — "how many days of leave is this person entitled to be paid for?" —
 * and stops there. Converting days to rupees is the off-cycle payroll run's job,
 * using the tenant's configured `days_basis`, so that a day of encashed leave is
 * worth exactly what a day of salary is worth in the same month.
 *
 * WHY THIS IS NOT A POLICY DECISION
 * ---------------------------------
 * D-002 Q3 asked which balance is encashable — earned only, earned + carried, or
 * per leave type. That framing was wrong: it is not one answer for all schools,
 * it is configuration. A school that encashes Earned Leave but not Casual Leave
 * is not in conflict with one that encashes neither; they have different leave
 * policies, which is exactly what `schools.leaveTypes` already models per type.
 *
 * So encashability is a PER-TYPE FLAG, and it **defaults to FALSE**. Encashing
 * leave creates a payment obligation; defaulting it on would invent a liability
 * the school never agreed to. A type is encashable only when its configuration
 * says so.
 *
 * THE AVAILABLE-DAYS RULE IS NOT INVENTED EITHER
 * ----------------------------------------------
 * It mirrors the shipped leave engine exactly (`Hr::decide_leave`):
 *
 *     available = max(0, allocated + carried - used)
 *
 * including the clamp at zero. `used` also accrues for unpaid types, so without
 * the clamp an over-consumed type would report negative days and *reduce* a
 * settlement — paying someone less because they took unpaid leave. The clamp is
 * the same one the Teacher app now applies on read.
 *
 * See STAFF_FNF_SETTLEMENT_DESIGN.md §3 (read-only inputs) and §3a (the run
 * boundary).
 */
class Fnf_encashment_resolver
{
    /**
     * Days available on one leave type, by the shipped leave engine's rule.
     *
     * `allocated` is read live from the leave TYPE (`days_per_year`), matching
     * `decide_leave()`, which re-derives it rather than trusting a stored
     * snapshot; `used` and `carried` come from the balance document.
     */
    public static function availableDays(array $balanceEntry, float $allocated): float
    {
        $carried = (float) ($balanceEntry['carried'] ?? 0);
        $used    = (float) ($balanceEntry['used'] ?? 0);

        return max(0.0, round($allocated + $carried - $used, 2));
    }

    /** Is this leave type encashable? Absent or falsy means NO. */
    public static function isEncashable(array $leaveType): bool
    {
        $flag = $leaveType['encashable'] ?? false;

        if (is_bool($flag))   return $flag;
        if (is_string($flag)) return $flag === 'true' || $flag === '1' || $flag === 'yes';
        if (is_numeric($flag)) return (float) $flag != 0.0;

        return false;
    }

    /**
     * Resolve the encashable leave for a settlement.
     *
     * Returns every type it considered, not just the payable ones, and says why
     * each was excluded. A settlement that silently omits a type is impossible
     * to check; an employee disputing their F&F is entitled to see that Casual
     * Leave was looked at and found non-encashable, rather than simply absent.
     *
     * @param array $balances   `balances` map from the BAL_ document, keyed by typeId
     * @param array $leaveTypes `schools.leaveTypes`, keyed by typeId
     *
     * @return array{
     *   encashableDays: float,
     *   perType: array<int, array{typeId: string, name: string, available: float,
     *                             encashable: bool, reason: string}>
     * }
     */
    public static function resolve(array $balances, array $leaveTypes): array
    {
        $total   = 0.0;
        $perType = [];

        foreach ($leaveTypes as $typeId => $type) {
            if (!is_array($type)) continue;

            $typeId = (string) $typeId;
            $entry  = is_array($balances[$typeId] ?? null) ? $balances[$typeId] : [];

            $allocated = (float) ($type['days_per_year'] ?? 0);
            $available = self::availableDays($entry, $allocated);
            $encashable = self::isEncashable($type);

            if ($encashable && $available > 0) {
                $total = round($total + $available, 2);
                $reason = 'encashable';
            } elseif (!$encashable) {
                $reason = 'type_not_encashable';
            } else {
                $reason = 'no_balance';
            }

            $perType[] = [
                'typeId'     => $typeId,
                'name'       => (string) ($type['name'] ?? $type['code'] ?? $typeId),
                'available'  => $available,
                'encashable' => $encashable,
                'reason'     => $reason,
            ];
        }

        return ['encashableDays' => $total, 'perType' => $perType];
    }
}
