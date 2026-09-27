<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Full & Final — notice-period INPUT resolver.
 *
 * PURE LOGIC. No Firestore, no CI dependencies, and **no money**. It answers
 * "how much notice was required, how much was served, and what is the
 * shortfall in days?" — and stops.
 *
 * WHAT IS OBJECTIVE HERE, AND WHAT IS NOT
 * ---------------------------------------
 * The day counts are arithmetic over dates `staffNoticePeriods` already stores,
 * so they need no policy:
 *
 *     required = noticeStartDate  →  expectedLastWorkingDay   (inclusive)
 *     served   = noticeStartDate  →  actualLastWorkingDay     (inclusive)
 *     shortfall = required − served
 *
 * What a shortfall is *worth* is D-002 Q5 (recovery on basic, gross or per-day
 * CTC) and is deliberately absent from this class.
 *
 * Whether a shortfall is recoverable **at all** is only partly objective:
 *
 *   `waived`    NOT recoverable. To waive is to relinquish the claim, and the
 *               system offers `reduce` as a separate decision precisely because
 *               it means something different. Reading the recorded decision is
 *               not inventing policy.
 *   `rejected`  NOT recoverable. The resignation was refused; no notice ran.
 *   `approved`  shortfall is zero by construction — decide_notice_period() sets
 *               actualLastWorkingDay = expectedLastWorkingDay on approval.
 *   `reduced`   **UNDECIDED — D-002 Q5.** A reduced notice may mean the
 *               requirement itself was shortened (nothing to recover) or that
 *               the employee bought out the balance (recoverable). ZenX records
 *               no field distinguishing the two, so this class refuses to
 *               choose and marks it `policy_required`.
 *
 * See STAFF_FNF_SETTLEMENT_DESIGN.md §3 and §6 Q5.
 */
class Fnf_notice_resolver
{
    /** Decisions whose shortfall is unambiguously not recoverable. */
    const NOT_RECOVERABLE = ['waived', 'rejected', 'withdrawn', 'cancelled'];

    private static function isDate($v): bool
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1;
    }

    /** Inclusive day count between two ISO dates; 0 when the window is invalid. */
    public static function inclusiveDays(string $from, string $to): int
    {
        if (!self::isDate($from) || !self::isDate($to)) return 0;
        if ($to < $from) return 0;

        return (int) (new DateTime($from))->diff(new DateTime($to))->days + 1;
    }

    /**
     * Resolve the notice position for a settlement.
     *
     * @param array $notice a `staffNoticePeriods` document
     *
     * @return array{
     *   status: string, requiredDays: int, servedDays: int, shortfallDays: int,
     *   recoverable: bool, recoverability: string, lastWorkingDay: ?string
     * }
     */
    public static function resolve(array $notice): array
    {
        $status  = strtolower(trim((string) ($notice['status'] ?? '')));
        $start   = (string) ($notice['noticeStartDate'] ?? '');
        $expected = (string) ($notice['expectedLastWorkingDay'] ?? '');
        $actual  = $notice['actualLastWorkingDay'] ?? null;

        // The last working day has ONE authority: the actual date when the
        // decision produced one, else the expected date. Never re-derived from
        // the resignation date — that is how two surfaces drift apart.
        $lwd = self::isDate($actual) ? (string) $actual : (self::isDate($expected) ? $expected : null);

        $required = self::inclusiveDays($start, $expected);
        $served   = $lwd !== null ? self::inclusiveDays($start, $lwd) : 0;

        // Serving beyond the expected date is not a negative shortfall; it is
        // simply no shortfall. Nobody owes anybody for extra notice served.
        if ($served > $required) $served = $required;

        $shortfall = max(0, $required - $served);

        [$recoverable, $why] = self::recoverability($status, $shortfall);

        return [
            'status'         => $status,
            'requiredDays'   => $required,
            'servedDays'     => $served,
            'shortfallDays'  => $shortfall,
            'recoverable'    => $recoverable,
            'recoverability' => $why,
            'lastWorkingDay' => $lwd,
        ];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private static function recoverability(string $status, int $shortfall): array
    {
        if ($shortfall <= 0) {
            return [false, 'no_shortfall'];
        }
        if (in_array($status, self::NOT_RECOVERABLE, true)) {
            // `waived` is the meaningful one: to waive is to give up the claim.
            return [false, 'waived_or_void'];
        }
        if ($status === 'reduced') {
            // Genuinely undecided — see the class docblock and D-002 Q5.
            return [false, 'policy_required'];
        }

        return [true, 'shortfall_stands'];
    }
}
