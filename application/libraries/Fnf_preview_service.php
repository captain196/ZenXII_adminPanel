<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Full & Final settlement PREVIEW — what a settlement would come to, today.
 *
 * WHY A PREVIEW EXISTS (Feature 6, 2026-08-22)
 * --------------------------------------------
 * Several F&F inputs are the school's contractual choices: the notice-recovery
 * basis, the approval threshold, which leave types are encashable. Those
 * choices move real money, and an owner cannot reasonably be asked to commit to
 * one without seeing what it does.
 *
 * So this projects a settlement WITHOUT creating one — and, where a choice is
 * in play, shows what the CURRENT configuration produces beside what a PROPOSED
 * one would.
 *
 * IT CANNOT WRITE. THAT IS THE POINT.
 * -----------------------------------
 * There is no Firestore handle, no CI instance, no persistence of any kind in
 * this class. It takes already-read inputs and returns a projection. A preview
 * that could accidentally create a settlement, post a journal, or notify an
 * employee would be worse than no preview, so the ability is absent rather than
 * merely unused — asserted by test.
 *
 * IT COMPOSES, IT DOES NOT REIMPLEMENT
 * ------------------------------------
 * Every figure comes from the resolver that already owns it:
 *
 *   tenure           Staff_employment_history_service::serviceDaysFromEpisodes()
 *   leave encashment Fnf_encashment_resolver::resolve()
 *   notice shortfall Fnf_notice_resolver::resolve()
 *   notice recovery  Fnf_policy_service::resolveRecovery()
 *   clearance        Fnf_policy_service::resolveClearance()
 *   approvals        Fnf_policy_service::approvalsRequired()
 *
 * A preview that computed its own numbers would eventually disagree with the
 * settlement it previews, which is the one thing a preview must never do.
 *
 * WHAT IT REFUSES TO GUESS
 * ------------------------
 * Where a policy is unconfigured the preview reports `policy_required` for that
 * line and marks the whole projection incomplete. It does not substitute a
 * plausible default — a preview that quietly invents the missing half is how an
 * owner ends up approving a number nobody chose.
 */
class Fnf_preview_service
{
    /**
     * Project a settlement.
     *
     * @param array $inputs {
     *   policy:      tenant F&F policy (Fnf_policy_service shape)
     *   notice:      resolved notice position (Fnf_notice_resolver::resolve output)
     *   encashment:  resolved encashment (Fnf_encashment_resolver::resolve output)
     *   tenure:      service days (Staff_employment_history_service::serviceDaysFromEpisodes)
     *   clearance:   a staffClearances document, or []
     *   monthly:     ['basicPaise'=>, 'grossPaise'=>, 'ctcPaise'=>]
     *   daysBasis:   the tenant's existing days_basis divisor
     *   unpaidSalaryPaise: salary earned and not yet paid, if known
     * }
     */
    public static function project(array $inputs): array
    {
        $policy     = is_array($inputs['policy'] ?? null) ? $inputs['policy'] : [];
        $notice     = is_array($inputs['notice'] ?? null) ? $inputs['notice'] : [];
        $encash     = is_array($inputs['encashment'] ?? null) ? $inputs['encashment'] : [];
        $tenure     = is_array($inputs['tenure'] ?? null) ? $inputs['tenure'] : [];
        $clearance  = is_array($inputs['clearance'] ?? null) ? $inputs['clearance'] : [];
        $monthly    = is_array($inputs['monthly'] ?? null) ? $inputs['monthly'] : [];
        $daysBasis  = (float) ($inputs['daysBasis'] ?? 0);

        $blockers = [];
        $lines    = [];

        // ── Earnings ─────────────────────────────────────────────────────
        $unpaid = (int) ($inputs['unpaidSalaryPaise'] ?? 0);
        $lines[] = self::line('unpaid_salary', 'Unpaid salary', $unpaid, true,
            $unpaid > 0 ? 'earned and not yet paid' : 'nothing outstanding');

        // Leave encashment: DAYS come from the resolver; money is applied here,
        // over the SAME days_basis divisor everything else uses.
        $encashDays = 0.0;
        foreach ((is_array($encash['types'] ?? null) ? $encash['types'] : []) as $t) {
            if (!empty($t['encashable'])) $encashDays += (float) ($t['days'] ?? 0);
        }

        if ($encashDays > 0 && $daysBasis > 0 && isset($monthly['basicPaise'])) {
            // Encashment is paid on the same base the tenant configured for
            // notice, so the two cannot disagree about what a day is worth.
            $rate = Fnf_policy_service::noticePerDayPaise($policy, $monthly, $daysBasis);
            if ($rate['ok']) {
                $lines[] = self::line('leave_encashment', 'Leave encashment',
                    (int) round($rate['perDayPaise'] * $encashDays), true,
                    $encashDays . ' day(s) at the ' . $rate['basis'] . ' daily rate');
            } else {
                $blockers[] = 'leave encashment: ' . $rate['reason'];
                $lines[] = self::line('leave_encashment', 'Leave encashment', 0, true,
                    'policy_required — ' . $rate['reason']);
            }
        } else {
            $lines[] = self::line('leave_encashment', 'Leave encashment', 0, true,
                $encashDays > 0 ? 'no daily rate available' : 'no encashable balance');
        }

        // ── Deductions ───────────────────────────────────────────────────
        $recovery = Fnf_policy_service::resolveRecovery($policy, $notice, $monthly, $daysBasis);
        if (!$recovery['ok']) {
            $blockers[] = 'notice recovery: ' . $recovery['reason'];
        }
        $lines[] = self::line('notice_recovery', 'Notice shortfall recovery',
            -1 * (int) $recovery['amountPaise'], false, $recovery['reason']);

        // ── Totals ───────────────────────────────────────────────────────
        $earnings   = 0;
        $deductions = 0;
        foreach ($lines as $l) {
            if ($l['amountPaise'] >= 0) $earnings += $l['amountPaise'];
            else $deductions += -$l['amountPaise'];
        }

        // Net is CLAMPED at zero, matching the payroll engine. A settlement
        // cannot pay a negative amount; an excess recovery survives as a claim
        // the school may pursue by other means (Payment of Wages Act s.7(4)).
        $net = $earnings - $deductions;
        $receivable = 0;
        if ($net < 0) { $receivable = -$net; $net = 0; }

        // ── Governance ───────────────────────────────────────────────────
        $clearanceState = Fnf_policy_service::resolveClearance($policy, $clearance);
        $approvals      = Fnf_policy_service::approvalsRequired($policy, $net);

        return [
            'complete'          => $blockers === [],
            'blockers'          => $blockers,
            'lines'             => $lines,
            'earningsPaise'     => $earnings,
            'deductionsPaise'   => $deductions,
            'netPayablePaise'   => $net,
            'receivablePaise'   => $receivable,
            'tenure'            => [
                'totalDays'      => (int) ($tenure['totalDays'] ?? 0),
                'continuousDays' => (int) ($tenure['continuousDays'] ?? 0),
                'episodeCount'   => (int) ($tenure['episodeCount'] ?? 0),
                'hasBreak'       => !empty($tenure['hasBreak']),
            ],
            'notice'            => [
                'requiredDays'  => (int) ($notice['requiredDays'] ?? 0),
                'servedDays'    => (int) ($notice['servedDays'] ?? 0),
                'shortfallDays' => (int) ($notice['shortfallDays'] ?? 0),
                'recoverable'   => !empty($notice['recoverable']),
                'basis'         => $recovery['basis'],
            ],
            'clearance'         => $clearanceState,
            'approvalsRequired' => $approvals,
            // What produced these numbers, so a preview can be compared with the
            // settlement it later becomes.
            'configurationUsed' => [
                'noticeBasis'    => (string) ($policy['noticeRecovery']['basis'] ?? 'unset'),
                'approvalMode'   => (string) ($policy['approval']['mode'] ?? 'single'),
                'daysBasis'      => $daysBasis,
                'clearanceScope' => is_array($policy['clearance']['required'] ?? null)
                    ? $policy['clearance']['required'] : [],
            ],
        ];
    }

    /**
     * Project the SAME employee under two policies, so a school can see what a
     * change would do before committing to it.
     *
     * Deltas are reported per line rather than only on the total, because "the
     * net moved by ₹8,000" does not tell an owner which decision moved it.
     */
    public static function compare(array $inputs, array $proposedPolicy): array
    {
        $current  = self::project($inputs);
        $proposed = self::project(array_replace($inputs, ['policy' => $proposedPolicy]));

        $byCode = static function (array $projection): array {
            $out = [];
            foreach ($projection['lines'] as $l) $out[$l['code']] = (int) $l['amountPaise'];
            return $out;
        };

        $a = $byCode($current);
        $b = $byCode($proposed);

        $lineDeltas = [];
        foreach (array_keys($a + $b) as $code) {
            $from = $a[$code] ?? 0;
            $to   = $b[$code] ?? 0;
            if ($from !== $to) {
                $lineDeltas[] = ['code' => $code, 'fromPaise' => $from, 'toPaise' => $to,
                                 'deltaPaise' => $to - $from];
            }
        }

        return [
            'current'     => $current,
            'proposed'    => $proposed,
            'lineDeltas'  => $lineDeltas,
            'netDelta'    => $proposed['netPayablePaise'] - $current['netPayablePaise'],
            'becomesComplete' => !$current['complete'] && $proposed['complete'],
        ];
    }

    private static function line(string $code, string $label, int $paise, bool $isEarning, string $note): array
    {
        return [
            'code'        => $code,
            'label'       => $label,
            'amountPaise' => $paise,
            'kind'        => $isEarning ? 'earning' : 'deduction',
            'note'        => $note,
        ];
    }
}
