<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Retro-effective salary revisions → arrears across the periods already paid.
 *
 * WHY THIS EXISTS (Feature 8, 2026-08-22)
 * ---------------------------------------
 * `docs/specs/hr-payroll-brief.md` §6.1 requires it directly:
 *
 *     "Revisions with `effective_from`; retro-effective revisions auto-generate
 *      arrears across affected past periods."
 *
 * and §6.4 constrains HOW:
 *
 *     "Run locked/paid → never mutate history. Raise an arrear or recovery
 *      adjustment in the next period, linked to the original run, shown as a
 *      labelled payslip line."
 *
 * ZenX already had the effective-dated assignment (`Salary_assignment_service`)
 * and already had the vehicle for the money (`Payroll_override_service`, whose
 * `ARREARS` type becomes an earning line through `toExtraComponents()`). What
 * was missing was the arithmetic between them: nothing worked out WHAT the
 * arrear was.
 *
 * ON `payoutFrom` — DELIBERATELY NOT BUILT
 * ----------------------------------------
 * Asanify distinguishes an "effective date" from a "payout from" date, and that
 * was recorded as a possible gap. Having read ZenX's own specification, it is
 * not one. Those are two ways of expressing the same thing: Asanify defers the
 * payment and pays the difference later; ZenX's spec says compute the
 * difference and raise it as an arrear.
 *
 * Adding a `payoutFrom` field on top of an arrears mechanism would be a second
 * way to say the same thing, and the standing instruction is not to add a field
 * nothing consumes. So the Asanify observation is treated as market evidence
 * that the PROBLEM is real — which it is, and the spec agrees — not as a
 * specification for the SOLUTION.
 *
 * HISTORY IS NEVER REPRICED
 * -------------------------
 * This computes a DIFFERENCE to be paid in a future run. It does not touch a
 * closed payslip, does not re-open a run, and does not alter a posted journal.
 * That is what keeps `generation ≡ regeneration` true for every historical
 * period: regenerating an old month still produces exactly what it produced,
 * because the arrear lives in a later run, not in that month.
 *
 * PURE. No Firestore, no CI. Amounts in and out are integer paise.
 */
class Salary_arrears_service
{
    /** Days in a period, for proration when a revision starts mid-month. */
    private static function daysInMonth(int $year, int $month): int
    {
        return (int) date('t', mktime(0, 0, 0, $month, 1, $year));
    }

    /** Strict ISO date — `strtotime` would accept "next tuesday". */
    public static function isIsoDate(string $d): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
        list($y, $m, $dd) = array_map('intval', explode('-', $d));
        return checkdate($m, $dd, $y);
    }

    /**
     * Which already-run periods a revision reaches back into.
     *
     * A period is affected when the revision takes effect on or before its LAST
     * day — i.e. any part of that period was worked under the new terms.
     *
     * @param string $effectiveFrom ISO date the revision starts
     * @param array  $paidPeriods   [['year'=>int,'month'=>int,'runId'=>string,
     *                               'grossPaise'=>int,'status'=>string], …]
     */
    public static function affectedPeriods(string $effectiveFrom, array $paidPeriods): array
    {
        if (!self::isIsoDate($effectiveFrom)) return [];

        $out = [];
        foreach ($paidPeriods as $p) {
            if (!is_array($p)) continue;

            $y = (int) ($p['year'] ?? 0);
            $m = (int) ($p['month'] ?? 0);
            if ($y <= 0 || $m < 1 || $m > 12) continue;

            $periodEnd = sprintf('%04d-%02d-%02d', $y, $m, self::daysInMonth($y, $m));
            if ($effectiveFrom > $periodEnd) continue;   // revision starts after this period

            $out[] = $p;
        }

        // Oldest first, so an arrears statement reads chronologically.
        usort($out, static function ($a, $b) {
            return ((int) $a['year'] <=> (int) $b['year'])
                ?: ((int) $a['month'] <=> (int) $b['month']);
        });

        return $out;
    }

    /**
     * The arrear for ONE period.
     *
     * PRORATED when the revision starts mid-period: only the days from
     * `effectiveFrom` to the end of the month were worked under the new terms,
     * so only that share of the difference is owed.
     *
     * The divisor is the number of days IN THAT MONTH, not the tenant's
     * `days_basis`. That is deliberate and it is not an inconsistency: this
     * apportions a month between two salary levels, which is a question about
     * the calendar, whereas `days_basis` answers "what is one day of pay worth"
     * for leave and notice. Using a fixed 30 here would pay 31/30ths of a
     * difference in a 31-day month.
     *
     * @return array{periodKey:string, arrearPaise:int, proratedDays:int,
     *                daysInPeriod:int, oldPaise:int, newPaise:int, note:string}
     */
    public static function arrearForPeriod(
        string $effectiveFrom,
        array $period,
        int $oldGrossPaise,
        int $newGrossPaise
    ): array {
        $y = (int) ($period['year'] ?? 0);
        $m = (int) ($period['month'] ?? 0);
        $daysIn = self::daysInMonth($y, $m);
        $key = sprintf('%04d-%02d', $y, $m);

        $difference = $newGrossPaise - $oldGrossPaise;

        // A revision that starts before the period covers all of it.
        $periodStart = sprintf('%04d-%02d-01', $y, $m);
        if ($effectiveFrom <= $periodStart) {
            return [
                'periodKey'    => $key,
                'arrearPaise'  => $difference,
                'proratedDays' => $daysIn,
                'daysInPeriod' => $daysIn,
                'oldPaise'     => $oldGrossPaise,
                'newPaise'     => $newGrossPaise,
                'note'         => 'full period on the revised terms',
            ];
        }

        $startDay = (int) substr($effectiveFrom, 8, 2);
        $covered = $daysIn - $startDay + 1;
        if ($covered < 0) $covered = 0;
        if ($covered > $daysIn) $covered = $daysIn;

        // ONE rounding, at the end — the same discipline the payroll engine uses.
        $arrear = (int) round($difference * $covered / $daysIn);

        return [
            'periodKey'    => $key,
            'arrearPaise'  => $arrear,
            'proratedDays' => $covered,
            'daysInPeriod' => $daysIn,
            'oldPaise'     => $oldGrossPaise,
            'newPaise'     => $newGrossPaise,
            'note'         => $covered . ' of ' . $daysIn . ' days on the revised terms',
        ];
    }

    /**
     * The full arrears position for a retro-effective revision.
     *
     * `periodGross` supplies what each affected period was actually PAID and
     * what it WOULD have been paid, per period — because a period's gross is a
     * function of that period's attendance, not only of the salary level. The
     * caller resolves those figures through the real payroll engine; this
     * service must never guess them, or an arrear would silently ignore a
     * month somebody was on unpaid leave.
     *
     * @param array $periodGross [periodKey => ['oldPaise'=>int, 'newPaise'=>int], …]
     */
    public static function compute(string $effectiveFrom, array $paidPeriods, array $periodGross): array
    {
        $affected = self::affectedPeriods($effectiveFrom, $paidPeriods);

        $lines = [];
        $total = 0;
        $missing = [];

        foreach ($affected as $p) {
            $key = sprintf('%04d-%02d', (int) $p['year'], (int) $p['month']);

            if (!isset($periodGross[$key])
                || !is_array($periodGross[$key])
                || !array_key_exists('oldPaise', $periodGross[$key])
                || !array_key_exists('newPaise', $periodGross[$key])) {
                // NAMED, not skipped. A period whose figures could not be
                // resolved must not silently drop out of an arrears statement.
                $missing[] = $key;
                continue;
            }

            $line = self::arrearForPeriod(
                $effectiveFrom,
                $p,
                (int) $periodGross[$key]['oldPaise'],
                (int) $periodGross[$key]['newPaise']
            );
            $line['runId'] = (string) ($p['runId'] ?? '');
            $lines[] = $line;
            $total += $line['arrearPaise'];
        }

        return [
            'ok'              => $missing === [],
            'effectiveFrom'   => $effectiveFrom,
            'periods'         => $lines,
            'totalArrearPaise'=> $total,
            'isRecovery'      => $total < 0,
            'missingPeriods'  => $missing,
            'note'            => $total < 0
                // A downward revision produces a NEGATIVE total. It is surfaced
                // as a recovery rather than silently clamped, because clamping
                // would quietly forgive money — but it is the caller's decision
                // whether to pursue it, and the deduction ceiling applies.
                ? 'downward revision — this is a recovery, not a payment'
                : 'arrears payable',
        ];
    }

    /**
     * Turn the arrears position into overrides the EXISTING payroll override
     * service understands, so the money reaches a payslip through the path that
     * already exists rather than a second one.
     *
     * One line PER PERIOD, not a single lump: a payslip that says
     * "Arrears ₹12,400" is unauditable, whereas "Arrears — May 2026" beside
     * "Arrears — June 2026" can be checked against those months.
     */
    public static function toOverrideInputs(array $arrears, string $staffId, string $runId): array
    {
        $out = [];
        foreach (($arrears['periods'] ?? []) as $line) {
            if ((int) $line['arrearPaise'] === 0) continue;

            $out[] = [
                'runId'       => $runId,
                'staffId'     => $staffId,
                'type'        => 'ARREARS',
                'amountPaise' => (int) $line['arrearPaise'],
                'reason'      => 'Salary revision effective ' . (string) $arrears['effectiveFrom']
                    . ' — arrears for ' . (string) $line['periodKey']
                    . ' (' . (string) $line['note'] . ')',
                'sourceRunId' => (string) ($line['runId'] ?? ''),
                'periodKey'   => (string) $line['periodKey'],
            ];
        }
        return $out;
    }
}
