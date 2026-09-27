<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Full & Final settlement POLICY — the school's contractual choices, modelled.
 *
 * WHY THIS EXISTS (DR-09 / DR-10, built 2026-08-22)
 * -------------------------------------------------
 * Two F&F questions were repeatedly recorded as "blocked on the owner":
 *
 *   DR-09  on what basis is a notice shortfall recovered?
 *   DR-10  above what amount does a settlement need a second approval?
 *
 * Treating those as blockers was a category error. The VALUE is the school's to
 * choose — it comes from their appointment letters and their delegation of
 * authority — but the MACHINERY to represent, validate, version and consume
 * that choice is ordinary engineering, and its absence is what actually blocked
 * the feature.
 *
 * So this models the axes and refuses to invent the values.
 *
 * WHY THESE THREE BASES AND NOT OTHERS
 * ------------------------------------
 * Every established Indian payroll product surveyed — greytHR, Keka, Zoho
 * Payroll, Darwinbox — exposes notice recovery as a CONFIGURABLE component
 * rather than a fixed formula, and the bases they expose are the same three:
 * basic, gross, and cost-to-company. That every vendor makes it configurable is
 * itself the evidence that no default exists; the consistent set of three is
 * the evidence for which options are real.
 *
 * No statute sets the basis. GST Circular 178/2022 establishes only that notice
 * pay recovery is not consideration for a supply and is therefore outside GST —
 * that constrains how the recovery is BOOKED, not what it is computed on.
 *
 * NOTHING IS DEFAULTED
 * --------------------
 * `basis` starts as `unset`, and `resolveRecovery()` refuses with
 * `policy_required` rather than guessing. Picking "basic" because it is common
 * would be inventing a term of somebody's employment contract.
 *
 * PURE. No Firestore, no CI, no rates, no thresholds of its own. Persistence
 * belongs to the controller; the tenant's `days_basis` divisor is supplied by
 * the caller, because it is the SAME divisor leave encashment already uses and
 * a second one would make a day of notice worth a different amount than a day
 * of leave for the same employee in the same month.
 */
class Fnf_policy_service
{
    // ── Notice recovery basis (DR-09) ────────────────────────────────────

    const BASIS_UNSET = 'unset';
    const BASIS_BASIC = 'basic';
    const BASIS_GROSS = 'gross';
    const BASIS_CTC   = 'ctc';

    /** `unset` is first deliberately: it is the state a school starts in. */
    const BASES = [self::BASIS_UNSET, self::BASIS_BASIC, self::BASIS_GROSS, self::BASIS_CTC];

    /** Human labels for the admin screen, so the UI does not invent wording. */
    const BASIS_LABELS = [
        self::BASIS_UNSET => 'Not configured — recovery is refused until set',
        self::BASIS_BASIC => 'Basic pay',
        self::BASIS_GROSS => 'Gross pay (all earnings)',
        self::BASIS_CTC   => 'Cost to company (gross + employer contributions)',
    ];

    // ── Approval (DR-10) ─────────────────────────────────────────────────

    const APPROVAL_SINGLE = 'single';
    const APPROVAL_DUAL_ABOVE_THRESHOLD = 'dual_above_threshold';
    const APPROVAL_ALWAYS_DUAL = 'always_dual';

    const APPROVAL_MODES = [
        self::APPROVAL_SINGLE,
        self::APPROVAL_DUAL_ABOVE_THRESHOLD,
        self::APPROVAL_ALWAYS_DUAL,
    ];

    /**
     * The policy a school starts with.
     *
     * Note what is and is not defaulted. `noticeRecovery.basis` is UNSET,
     * because it is contractual. `approval.mode` defaults to SINGLE, because
     * that is what the shipped state machine already does — defaulting it to
     * dual would silently block every settlement at a school that never
     * configured anything.
     *
     * Separation of duties is `true` and is NOT a configurable axis: the
     * shipped state machine already refuses when approver == initiator, and
     * making that switchable would let a school configure away the one control
     * that stops a person approving their own payout.
     */
    public static function defaults(): array
    {
        return [
            'noticeRecovery' => [
                'basis'       => self::BASIS_UNSET,
                'roundToDays' => false,
                'note'        => '',
            ],
            'approval' => [
                'mode'               => self::APPROVAL_SINGLE,
                'dualThresholdPaise' => null,
                'firstApproverRole'  => '',
                'secondApproverRole' => '',
            ],
            'clearance' => [
                'required'         => [],
                'blockApprovalOnly'=> true,
                'overrideRole'     => '',
            ],
            // The date this version of the policy takes effect. Defaults to the
            // save date; may be set forward so a school can record terms agreed
            // now that start next term. Never backwards — see validateEffectiveFrom().
            'effectiveFrom' => '',
            'version'       => 0,
            'updatedAt' => '',
            'updatedBy' => '',
        ];
    }

    // ── Validation ───────────────────────────────────────────────────────

    /**
     * @return array{0:bool,1:array,2:array} [ok, cleanPolicy, errors]
     */
    public static function validate(array $in): array
    {
        $e = [];
        $out = self::defaults();

        // ── Notice recovery ──
        $nrIn = is_array($in['noticeRecovery'] ?? null) ? $in['noticeRecovery'] : [];

        $basis = strtolower(trim((string) ($nrIn['basis'] ?? self::BASIS_UNSET)));
        if (!in_array($basis, self::BASES, true)) {
            $e[] = 'Notice recovery basis must be one of: ' . implode(', ', self::BASES) . '.';
            $basis = self::BASIS_UNSET;
        }

        $out['noticeRecovery'] = [
            'basis'       => $basis,
            'roundToDays' => !empty($nrIn['roundToDays']),
            'note'        => trim((string) ($nrIn['note'] ?? '')),
        ];

        // ── Approval ──
        $apIn = is_array($in['approval'] ?? null) ? $in['approval'] : [];

        $mode = strtolower(trim((string) ($apIn['mode'] ?? self::APPROVAL_SINGLE)));
        if (!in_array($mode, self::APPROVAL_MODES, true)) {
            $e[] = 'Approval mode must be one of: ' . implode(', ', self::APPROVAL_MODES) . '.';
            $mode = self::APPROVAL_SINGLE;
        }

        $threshold = $apIn['dualThresholdPaise'] ?? null;
        if ($threshold !== null && $threshold !== '') {
            if (!is_numeric($threshold) || (int) $threshold != $threshold || (int) $threshold < 0) {
                $e[] = 'The dual-approval threshold must be a non-negative whole number of paise.';
                $threshold = null;
            } else {
                $threshold = (int) $threshold;
            }
        } else {
            $threshold = null;
        }

        // A threshold mode without a threshold cannot decide anything, so it
        // would silently behave as single approval — the opposite of the intent.
        if ($mode === self::APPROVAL_DUAL_ABOVE_THRESHOLD && $threshold === null) {
            $e[] = 'Dual approval above a threshold requires the threshold amount.';
        }

        $out['approval'] = [
            'mode'               => $mode,
            'dualThresholdPaise' => $threshold,
            'firstApproverRole'  => trim((string) ($apIn['firstApproverRole'] ?? '')),
            'secondApproverRole' => trim((string) ($apIn['secondApproverRole'] ?? '')),
        ];

        // ── Clearance ──
        $clIn = is_array($in['clearance'] ?? null) ? $in['clearance'] : [];

        $required = [];
        if (is_array($clIn['required'] ?? null)) {
            foreach ($clIn['required'] as $area) {
                $a = trim((string) $area);
                if ($a !== '' && !in_array($a, $required, true)) $required[] = $a;
            }
        }

        $out['clearance'] = [
            'required'          => $required,
            'blockApprovalOnly' => !isset($clIn['blockApprovalOnly']) || !empty($clIn['blockApprovalOnly']),
            'overrideRole'      => trim((string) ($clIn['overrideRole'] ?? '')),
        ];

        return [$e === [], $out, $e];
    }

    // ── Resolution — what F&F asks ───────────────────────────────────────

    /**
     * The per-day amount a notice shortfall is recovered at, in PAISE.
     *
     * REFUSES rather than guessing. An unset basis returns `policy_required`
     * with a zero amount, so a settlement built on an unconfigured policy shows
     * "configuration required" instead of a plausible wrong number.
     *
     * @param array $policy    the tenant F&F policy
     * @param array $monthly   monthly amounts in PAISE:
     *                         ['basicPaise'=>, 'grossPaise'=>, 'ctcPaise'=>]
     * @param float $daysBasis the tenant's existing `days_basis` divisor — the
     *                         SAME one leave encashment uses
     * @return array{ok:bool, perDayPaise:int, basis:string, reason:string}
     */
    public static function noticePerDayPaise(array $policy, array $monthly, float $daysBasis): array
    {
        $basis = strtolower((string) ($policy['noticeRecovery']['basis'] ?? self::BASIS_UNSET));

        if ($basis === self::BASIS_UNSET || !in_array($basis, self::BASES, true)) {
            return [
                'ok' => false, 'perDayPaise' => 0, 'basis' => self::BASIS_UNSET,
                'reason' => 'policy_required — the notice recovery basis has not been configured',
            ];
        }

        if ($daysBasis <= 0) {
            return [
                'ok' => false, 'perDayPaise' => 0, 'basis' => $basis,
                'reason' => 'days_basis is zero — cannot derive a daily rate',
            ];
        }

        $map = [
            self::BASIS_BASIC => 'basicPaise',
            self::BASIS_GROSS => 'grossPaise',
            self::BASIS_CTC   => 'ctcPaise',
        ];
        $key = $map[$basis];

        if (!array_key_exists($key, $monthly) || !is_numeric($monthly[$key])) {
            return [
                'ok' => false, 'perDayPaise' => 0, 'basis' => $basis,
                'reason' => "the configured basis ({$basis}) is not available on this salary structure",
            ];
        }

        $monthlyPaise = (int) $monthly[$key];
        if ($monthlyPaise < 0) {
            return [
                'ok' => false, 'perDayPaise' => 0, 'basis' => $basis,
                'reason' => 'a negative monthly amount cannot produce a daily rate',
            ];
        }

        // ONE rounding, at the end — the same discipline the payroll engine uses.
        $perDay = (int) round($monthlyPaise / $daysBasis);

        return [
            'ok' => true, 'perDayPaise' => $perDay, 'basis' => $basis,
            'reason' => 'per-day rate on ' . $basis . ' over ' . $daysBasis . ' days',
        ];
    }

    /**
     * The full recovery for a shortfall.
     *
     * Takes the shortfall from `Fnf_notice_resolver` rather than recomputing it:
     * two implementations of "how many days short" would eventually disagree,
     * and the disagreement would be a number on somebody's settlement.
     */
    public static function resolveRecovery(array $policy, array $notice, array $monthly, float $daysBasis): array
    {
        $shortfall = (int) ($notice['shortfallDays'] ?? 0);
        $recoverable = !empty($notice['recoverable']);

        if (!$recoverable || $shortfall <= 0) {
            return [
                'ok' => true, 'amountPaise' => 0, 'shortfallDays' => $shortfall,
                'basis' => (string) ($policy['noticeRecovery']['basis'] ?? self::BASIS_UNSET),
                'reason' => 'nothing recoverable: ' . (string) ($notice['recoverability'] ?? 'unknown'),
            ];
        }

        $rate = self::noticePerDayPaise($policy, $monthly, $daysBasis);
        if (!$rate['ok']) {
            return [
                'ok' => false, 'amountPaise' => 0, 'shortfallDays' => $shortfall,
                'basis' => $rate['basis'], 'reason' => $rate['reason'],
            ];
        }

        return [
            'ok'            => true,
            'amountPaise'   => $rate['perDayPaise'] * $shortfall,
            'shortfallDays' => $shortfall,
            'basis'         => $rate['basis'],
            'reason'        => $shortfall . ' day(s) at ' . $rate['reason'],
        ];
    }

    /**
     * How many approvals this settlement needs.
     *
     * Separation of duties is NOT decided here — the state machine already
     * refuses approver == initiator, and that control is not configurable.
     * This decides only the COUNT.
     */
    public static function approvalsRequired(array $policy, int $settlementPaise): array
    {
        $ap = is_array($policy['approval'] ?? null) ? $policy['approval'] : [];
        $mode = strtolower((string) ($ap['mode'] ?? self::APPROVAL_SINGLE));

        if ($mode === self::APPROVAL_ALWAYS_DUAL) {
            return ['count' => 2, 'reason' => 'policy requires two approvals for every settlement'];
        }

        if ($mode === self::APPROVAL_DUAL_ABOVE_THRESHOLD) {
            $threshold = $ap['dualThresholdPaise'] ?? null;

            // A threshold mode with no threshold must FAIL CLOSED. Treating it
            // as single approval would silently remove the control the school
            // thought it had configured.
            if ($threshold === null) {
                return ['count' => 2, 'reason' => 'dual-approval threshold is not configured — '
                    . 'requiring two approvals until it is'];
            }

            if ($settlementPaise > (int) $threshold) {
                return ['count' => 2, 'reason' => 'settlement exceeds the dual-approval threshold'];
            }
            return ['count' => 1, 'reason' => 'settlement is within the single-approval threshold'];
        }

        return ['count' => 1, 'reason' => 'policy requires a single approval'];
    }

    /**
     * Can this settlement be APPROVED, given the clearance position?
     *
     * FEATURE 5. A staff clearance system already exists — `staffClearances`,
     * with per-department `pending` / `cleared` / `flagged` states, and a
     * department list that is ALREADY tenant-configurable at
     * `schools.clearanceDepartments`. None of that is rebuilt here. What was
     * missing was the connection: which departments actually BLOCK a
     * settlement, and what happens when one is flagged.
     *
     * COMPUTE IS NOT PAY
     * ------------------
     * `blockApprovalOnly` defaults true, and the reasoning is practical rather
     * than theoretical: refusing to COMPUTE makes it impossible to tell a
     * departing employee what they are owed while a library book is
     * outstanding, which is a support burden with no control benefit. Refusing
     * to APPROVE preserves the actual control, because approval is what lets
     * money out.
     *
     * REQUIRED IS A SUBSET, NOT A SECOND LIST
     * ---------------------------------------
     * `clearance.required` names departments from the tenant's existing list.
     * It deliberately does NOT define its own departments: a second list would
     * drift from the first, and a settlement would then block on a department
     * that no longer exists, or fail to block on one that does.
     *
     * An EMPTY required list means every department in the clearance record
     * blocks. That is the fail-safe reading: a school that has not narrowed the
     * list has not thereby waived clearance.
     *
     * @param array $policy        the tenant F&F policy
     * @param array $clearanceDoc  a `staffClearances` document, or []
     * @return array{canApprove:bool, canCompute:bool, blocking:array, reason:string}
     */
    public static function resolveClearance(array $policy, array $clearanceDoc): array
    {
        $cl = is_array($policy['clearance'] ?? null) ? $policy['clearance'] : [];
        $required = is_array($cl['required'] ?? null) ? $cl['required'] : [];
        $blockApprovalOnly = !isset($cl['blockApprovalOnly']) || !empty($cl['blockApprovalOnly']);

        $departments = is_array($clearanceDoc['departments'] ?? null) ? $clearanceDoc['departments'] : [];

        // No clearance record at all. This is NOT "cleared" — it is "not
        // started", and treating the two the same would let a settlement be
        // approved for someone whose clearance nobody ever opened.
        if ($departments === []) {
            return [
                'canApprove' => false,
                'canCompute' => true,
                'blocking'   => [],
                'reason'     => 'no clearance record has been started for this employee',
            ];
        }

        $blocking = [];
        foreach ($departments as $name => $state) {
            $dept = (string) $name;

            // Narrowed list: only the named departments block.
            if ($required !== [] && !in_array($dept, $required, true)) continue;

            $status = strtolower((string) (is_array($state) ? ($state['status'] ?? '') : $state));
            if ($status !== 'cleared') {
                $blocking[] = ['department' => $dept, 'status' => $status ?: 'pending'];
            }
        }

        if ($blocking === []) {
            return [
                'canApprove' => true,
                'canCompute' => true,
                'blocking'   => [],
                'reason'     => 'all required clearances are cleared',
            ];
        }

        $names = implode(', ', array_column($blocking, 'department'));

        return [
            'canApprove' => false,
            // Computing stays permitted unless the school has explicitly said
            // clearance should gate computation too.
            'canCompute' => $blockApprovalOnly,
            'blocking'   => $blocking,
            'reason'     => 'outstanding clearance: ' . $names,
        ];
    }

    /**
     * May this actor override an outstanding clearance?
     *
     * An override is NOT a bypass. It requires a role the school nominated, and
     * a reason — and the caller is expected to audit both. A school that has
     * nominated nobody has no override at all, which is the correct default:
     * an unrestricted bypass would make the whole control decorative.
     */
    public static function canOverrideClearance(array $policy, array $actorRoles, string $reason): array
    {
        $role = trim((string) ($policy['clearance']['overrideRole'] ?? ''));

        if ($role === '') {
            return ['allowed' => false, 'reason' => 'no clearance override role is configured'];
        }
        if (trim($reason) === '') {
            return ['allowed' => false, 'reason' => 'an override requires a recorded reason'];
        }

        $roles = array_map(static fn($r) => strtolower(trim((string) $r)), $actorRoles);
        if (!in_array(strtolower($role), $roles, true)) {
            return ['allowed' => false, 'reason' => "only {$role} may override a clearance"];
        }

        return ['allowed' => true, 'reason' => "overridden by {$role}"];
    }

    /**
     * Is the policy complete enough to compute a settlement?
     *
     * Drives a "configuration required" state in the UI instead of a settlement
     * that looks finished and is not.
     */
    public static function completeness(array $policy): array
    {
        $missing = [];

        if ((string) ($policy['noticeRecovery']['basis'] ?? self::BASIS_UNSET) === self::BASIS_UNSET) {
            $missing[] = 'Notice recovery basis';
        }

        $ap = is_array($policy['approval'] ?? null) ? $policy['approval'] : [];
        if ((string) ($ap['mode'] ?? '') === self::APPROVAL_DUAL_ABOVE_THRESHOLD
            && ($ap['dualThresholdPaise'] ?? null) === null) {
            $missing[] = 'Dual-approval threshold';
        }

        return ['complete' => $missing === [], 'missing' => $missing];
    }

    // ── Effective dating and history (FNF-1) ─────────────────────────────

    /** How many past versions are retained on the document. */
    const HISTORY_LIMIT = 24;

    /**
     * Validate a proposed effective date against the policy currently in force.
     *
     * BACK-DATING IS REFUSED. A policy that takes effect before a version that
     * is already in force would change the terms under which earlier
     * settlements were computed — silently, and after the fact. If a school
     * genuinely needs to correct a past period that is a decision with money
     * attached, not a form field.
     *
     * @return array{0:bool,1:string,2:string} [ok, normalisedDate, error]
     */
    public static function validateEffectiveFrom($raw, array $current, string $today): array
    {
        $d = trim((string) $raw);
        if ($d === '') {
            $d = $today;                       // the ordinary case: effective now
        }

        if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $d)) {
            return [false, '', 'Effective date must be a date (YYYY-MM-DD).'];
        }

        $currentFrom = trim((string) ($current['effectiveFrom'] ?? ''));
        if ($currentFrom !== '' && strtotime($d) < strtotime($currentFrom)) {
            return [false, '', 'The effective date cannot be earlier than the policy already in '
                . 'force (' . $currentFrom . '). Back-dating would change the terms under which '
                . 'earlier settlements were calculated.'];
        }

        return [true, $d, ''];
    }

    /**
     * Append the outgoing policy to history and stamp the incoming one.
     *
     * The version number increments from whatever is already stored rather than
     * from the history length, so a truncated history cannot make two versions
     * share a number.
     *
     * @return array{0:array,1:array} [policyToStore, historyToStore]
     */
    public static function withHistory(array $incoming, array $current, array $history): array
    {
        $history = array_values(array_filter($history, 'is_array'));

        // Keep the outgoing version, if there was one worth keeping.
        if (!empty($current) && trim((string) ($current['effectiveFrom'] ?? '')) !== '') {
            $history[] = $current;
        }

        $dropped = 0;
        if (count($history) > self::HISTORY_LIMIT) {
            $dropped = count($history) - self::HISTORY_LIMIT;
            // Oldest go first; truncation is recorded rather than silent.
            $history = array_slice($history, -self::HISTORY_LIMIT);
        }

        $incoming['version'] = ((int) ($current['version'] ?? 0)) + 1;
        if ($dropped > 0) {
            $incoming['historyTruncated'] = ($current['historyTruncated'] ?? 0) + $dropped;
        }

        return [$incoming, $history];
    }

    /**
     * The policy in force on a given date.
     *
     * This is what makes a past settlement reproducible: hand it the settlement
     * date and it returns the terms that applied then, not the terms that apply
     * now. A settlement that has pinned its own policy snapshot should use that
     * instead — this is the fallback for records written before pinning, and
     * for any read that legitimately asks "what applied in March".
     *
     * Returns the CURRENT policy when nothing older applies, because a policy
     * with no recorded history has always been the policy.
     */
    public static function asOf(string $date, array $current, array $history): array
    {
        $date = trim($date);
        if ($date === '' || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) {
            return $current;
        }

        $ts = strtotime($date);

        $currentFrom = trim((string) ($current['effectiveFrom'] ?? ''));
        if ($currentFrom === '' || strtotime($currentFrom) <= $ts) {
            return $current;
        }

        // The current policy started after the date asked about — find the
        // newest historical version that had already taken effect.
        $best = null;
        foreach ($history as $h) {
            if (!is_array($h)) continue;
            $from = trim((string) ($h['effectiveFrom'] ?? ''));
            if ($from === '' || strtotime($from) > $ts) continue;
            if ($best === null || strtotime($from) >= strtotime((string) $best['effectiveFrom'])) {
                $best = $h;
            }
        }

        // Nothing was in force that early. Returning the current policy would
        // be a quiet lie, so say so instead and let the caller decide.
        return $best ?? array_merge($current, ['_asOfUnavailable' => true]);
    }
}
