<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Leave_policy_service — effective-dated leave-type policy resolution
 * (Workforce Policy Programme, Leave Phase 2, 2026-08-18).
 *
 * Pure, static, no I/O — same discipline as Working_calendar_service and
 * Leave_year_service: the caller loads the type doc and passes it in.
 *
 * WHY THIS EXISTS: `schools/{id}.leaveTypes[typeId]` was a flat,
 * current-state-only map. Editing `days_per_year` immediately and
 * RETROACTIVELY rewrote every current-year stored balance doc
 * (`Hr::_propagate_leave_type_to_balances()`) — deliberate design
 * ("single source of truth, retroactive"), but a genuine audit-integrity
 * risk: a quota typo could silently change every staff member's
 * already-consumed balance. This class makes policy edits PROSPECTIVE by
 * default: an edit creates a new dated version; a balance allocated under
 * an earlier version keeps explaining itself against the version that was
 * actually true when it was allocated.
 *
 * THIS IS NOT A NEW PATTERN. `ZENX_HRMS_POLICY_MODEL_DECISION.md` §6
 * (Option B, already ratified) explicitly named `leavePolicies` as a
 * future catalogue member alongside `workSchedules`/`attendancePolicies`/
 * `salaryStructures` under Policy_assignment_service's effective-dated
 * resolution shape. No code for it existed before this phase. This class
 * is deliberately narrower than Policy_assignment_service (no
 * staff/department/organisation attachment levels — a leave TYPE is
 * already tenant-wide, only ITS OWN quota changes over time), but reuses
 * the exact same effective-dating shape and vocabulary
 * (`effectiveFrom`/most-recent-not-future wins) so the two resolvers read
 * the same way side by side.
 *
 * COMPATIBILITY: `leaveTypes[typeId]` keeps its legacy flat fields
 * (`days_per_year`, `carry_forward`, `max_carry`, `paid`, `status`)
 * dual-written as a snapshot of the CURRENTLY-effective version — the
 * Teacher app reads this map directly (confirmed:
 * LeaveFirestoreRepository.kt reads `schools.leaveTypes` with flat
 * fields), so `versions[]` is purely additive alongside them, never a
 * replacement of the shape mobile clients already parse.
 */
class Leave_policy_service
{
    private const DATE_RE = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * The version of $type effective on $dateISO — the most recent version
     * whose effectiveFrom is on or before $dateISO. Returns null if the
     * type has no versions[] at all, or none are effective yet by that
     * date (never guesses/falls back to "the first one anyway").
     */
    public static function effectiveVersion(array $type, string $dateISO): ?array
    {
        if (!preg_match(self::DATE_RE, $dateISO)) return null;
        $versions = is_array($type['versions'] ?? null) ? $type['versions'] : [];
        if (empty($versions)) return null;

        $best = null;
        foreach ($versions as $v) {
            if (!is_array($v)) continue;
            $ef = (string) ($v['effectiveFrom'] ?? '');
            if (!preg_match(self::DATE_RE, $ef) || $ef > $dateISO) continue;
            if ($best === null || $ef > (string) ($best['effectiveFrom'] ?? '')) {
                $best = $v;
            }
        }
        return $best;
    }

    /**
     * May a new version be appended with this effectiveFrom? Refuses
     * (never silently overwrites/last-write-wins) an invalid date or one
     * that collides with an existing version's effectiveFrom — mirrors
     * Policy_assignment_service's own "refuse ambiguous, never guess"
     * convention.
     *
     * @return array{ok: bool, error: string} error one of
     *   '' | 'invalid_date' | 'duplicate_effective_from'
     */
    public static function canAppendVersion(array $existingVersions, string $newEffectiveFrom): array
    {
        if (!preg_match(self::DATE_RE, $newEffectiveFrom)) {
            return ['ok' => false, 'error' => 'invalid_date'];
        }
        foreach ($existingVersions as $v) {
            if (!is_array($v)) continue;
            if ((string) ($v['effectiveFrom'] ?? '') === $newEffectiveFrom) {
                return ['ok' => false, 'error' => 'duplicate_effective_from'];
            }
        }
        return ['ok' => true, 'error' => ''];
    }

    /**
     * Build the flat, legacy-shaped snapshot of whichever version is
     * effective "now" (server date) — this is what gets dual-written onto
     * leaveTypes[typeId]'s top-level fields so existing readers (Teacher
     * app, every pre-Phase-2 admin-panel code path) keep seeing a single,
     * coherent, currently-true policy without knowing versions[] exists.
     * Returns null if no version is effective yet (caller keeps whatever
     * flat fields already exist — never blanks them).
     */
    public static function currentFlatSnapshot(array $type, string $todayISO): ?array
    {
        $v = self::effectiveVersion($type, $todayISO);
        if ($v === null) return null;
        return [
            'days_per_year' => (int) ($v['days_per_year'] ?? 0),
            'carry_forward' => (bool) ($v['carry_forward'] ?? false),
            'max_carry'     => (int) ($v['max_carry'] ?? 0),
            'paid'          => (bool) ($v['paid'] ?? false),
            'status'        => (string) ($v['status'] ?? 'Active'),
        ];
    }
}
