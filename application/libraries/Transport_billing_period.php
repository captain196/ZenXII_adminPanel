<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_billing_period — WHICH MONTHS DID THIS STUDENT ACTUALLY HAVE A BUS?
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  THE DEFECT THIS REPLACES — TRN-PRORATE-INVERT-1
 * ─────────────────────────────────────────────────────────────────────────
 *  Transport billing went through Fee_lifecycle::proRateFees($studentId,
 *  $joinDate), whose ONLY date parameter is a JOIN date: it answers "you
 *  started in month X, so pay for the months remaining to March".
 *
 *  Every transport STOP event passed the STOP date into that parameter. The
 *  student was therefore billed as though they had STARTED on the day they
 *  stopped. Proven on a Rs 1,000/month route (session list 12,000):
 *
 *      assigned August            gross 8,000   (Aug..Mar — right for a joiner)
 *      service stopped 1 Feb      gross 2,000   (Feb..Mar)
 *      actually used Aug..Jan = 6 months     ->  6,000 owed
 *
 *  Bidirectional: stopping late under-bills, stopping early over-bills — a
 *  one-month rider who leaves in May was billed eleven months. And because
 *  each event OVERWRITES the single session demand, the amount only ever
 *  described the period after the most recent event, never the service the
 *  family actually received.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  THE MODEL
 * ─────────────────────────────────────────────────────────────────────────
 *  Service is a list of SEGMENTS, each with the fare in force during it:
 *
 *      [{from: 2026-08-04, to: 2026-12-01, monthly_fee: 1000},
 *       {from: 2027-01-10, to: '',         monthly_fee: 1500}]
 *
 *  `to` is EXCLUSIVE and '' means still running. A month is billable when the
 *  segment actually overlaps it, so the arithmetic is a property of recorded
 *  history rather than of whichever event happened to fire last. Recomputing
 *  from the whole list after every event is what makes suspend → resume →
 *  route change → removal converge instead of drift.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  WHOLE MONTHS — THE EXISTING CONVENTION, MADE EXPLICIT
 * ─────────────────────────────────────────────────────────────────────────
 *  ZenX has never represented part-months anywhere: the route field is a
 *  MONTHLY fare and the old proration counted whole months. This keeps that
 *  and states the rule plainly:
 *
 *      a month is billable if service was active on ANY day of it.
 *
 *  So joining on the 28th bills that whole month, and stopping on the 1st does
 *  not bill the month you stopped in — which is exactly the worked example the
 *  product decision gave: start August, stop 1 February, pay for August to
 *  January, six months.
 *
 *  Part-month proration is therefore NOT invented here. If a school ever wants
 *  it, it belongs in the school financial policy layer as an explicit choice,
 *  not buried in this arithmetic.
 */
class Transport_billing_period
{
    /** ZenX academic sessions run April → March. */
    public const SESSION_START_MONTH = 4;
    public const SESSION_MONTHS      = 12;

    /**
     * Every period key of an academic session, in order.
     * '2026-27' → 2026-04 … 2026-12, 2027-01 … 2027-03.
     *
     * @return string[] empty when the session is malformed — the caller then
     *                  bills nothing rather than guessing a year.
     */
    public static function sessionMonths(string $session): array
    {
        if (!preg_match('/^(\d{4})-(\d{2})$/', trim($session), $m)) return [];

        $startYear = (int) $m[1];
        $keys = [];
        for ($i = 0; $i < self::SESSION_MONTHS; $i++) {
            $monthNo = self::SESSION_START_MONTH + $i;
            $year    = $startYear + intdiv($monthNo - 1, self::SESSION_MONTHS);
            $keys[]  = sprintf('%04d-%02d', $year, (($monthNo - 1) % self::SESSION_MONTHS) + 1);
        }
        return $keys;
    }

    /** First and last day of a period key, as Y-m-d. */
    private static function monthBounds(string $periodKey): array
    {
        $first = $periodKey . '-01';
        $last  = date('Y-m-t', (int) strtotime($first));
        return [$first, $last];
    }

    /**
     * The billable months of one session, each with the fare in force.
     *
     * A month appears at most ONCE even if several segments touch it — a
     * student who is suspended and resumed inside the same month has one bus
     * that month, not two. Where segments disagree on the fare within a month,
     * the FIRST segment touching it wins, so a mid-month route change does not
     * silently reprice a month the family has already largely consumed.
     *
     * @param array $segments [{from, to, monthly_fee}], `to` exclusive, '' = open
     * @return array<string, float> periodKey => fare for that month, in order
     */
    public static function billableMonths(string $session, array $segments): array
    {
        $out = [];

        foreach (self::sessionMonths($session) as $periodKey) {
            [$monthFirst, $monthLast] = self::monthBounds($periodKey);

            foreach ($segments as $seg) {
                if (!is_array($seg)) continue;

                $from = trim((string) ($seg['from'] ?? ''));
                $to   = trim((string) ($seg['to']   ?? ''));
                if ($from === '') continue;

                // Segment starts after this month ends → not yet running.
                if (strcmp($from, $monthLast) > 0) continue;

                // `to` is EXCLUSIVE: a segment ending on the 1st of a month
                // gives no service in that month, which is what makes
                // "stop 1 February" bill through January and not February.
                if ($to !== '' && strcmp($to, $monthFirst) <= 0) continue;

                $out[$periodKey] = round((float) ($seg['monthly_fee'] ?? 0), 2);
                break;   // first matching segment wins — see the docblock
            }
        }

        return $out;
    }

    /** Total payable before any concession. */
    public static function grossFor(string $session, array $segments): float
    {
        return round(array_sum(self::billableMonths($session, $segments)), 2);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SEGMENT MAINTENANCE — the only places history is allowed to change
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Open a segment, closing any that is still running.
     *
     * Idempotent by shape: re-opening on the same date with the same fare
     * returns the list unchanged, so a retried assignment cannot append a
     * duplicate segment and double the bill.
     */
    public static function open(array $segments, string $from, float $monthlyFee): array
    {
        $from = trim($from) !== '' ? trim($from) : date('Y-m-d');
        $segments = array_values(array_filter($segments, 'is_array'));

        $last = end($segments);
        if (is_array($last)
            && trim((string) ($last['to'] ?? '')) === ''
            && trim((string) ($last['from'] ?? '')) === $from
            && round((float) ($last['monthly_fee'] ?? 0), 2) === round($monthlyFee, 2)) {
            return $segments;   // already open on these exact terms
        }

        $segments = self::close($segments, $from);
        $segments[] = ['from' => $from, 'to' => '', 'monthly_fee' => round($monthlyFee, 2)];
        return $segments;
    }

    /**
     * Close whatever segment is running, at $to (exclusive).
     *
     * A close that predates the segment's own start would invert it, so such a
     * segment is dropped instead: service that never happened must not appear
     * as a negative period, and must not be billed.
     */
    public static function close(array $segments, string $to): array
    {
        $to = trim($to) !== '' ? trim($to) : date('Y-m-d');
        $out = [];

        foreach ($segments as $seg) {
            if (!is_array($seg)) continue;
            if (trim((string) ($seg['to'] ?? '')) === '') {
                $from = trim((string) ($seg['from'] ?? ''));
                if ($from !== '' && strcmp($to, $from) <= 0) continue;   // zero/negative
                $seg['to'] = $to;
            }
            $out[] = $seg;
        }
        return $out;
    }

    /** Is service running right now (any open segment)? */
    public static function isOpen(array $segments): bool
    {
        foreach ($segments as $seg) {
            if (is_array($seg) && trim((string) ($seg['to'] ?? '')) === '') return true;
        }
        return false;
    }
}
