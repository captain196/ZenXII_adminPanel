<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * The employee-level statutory profile — PF membership and ESI coverage.
 *
 * WHY THIS EXISTS (CA-6, built 2026-08-22)
 * ----------------------------------------
 * ZenX had no per-employee PF membership record. Coverage was RE-DERIVED on
 * every payroll run by comparing the employee's current wage against the
 * statutory ceiling. That cannot express the commonest real case in Indian
 * payroll:
 *
 *      joined below the ceiling  →  became a member
 *      later earns above it      →  REMAINS a member
 *
 * A wage-versus-ceiling derivation drops such an employee out of PF the month
 * they cross the ceiling, which is wrong and would be discovered by the
 * employee, not by the software.
 *
 * It also cannot express the converse: someone who joined ABOVE the ceiling can
 * become a member only by election under Para 26(6) of the EPF Scheme, and in
 * that case receives no pension-scheme share — the whole employer contribution
 * goes to the fund.
 *
 * Membership is an attribute of a PERSON, established once and carried forward.
 * Asanify models the same thing as a per-employee flag; that is market
 * corroboration, not the reason.
 *
 * WHERE IT LIVES, AND WHY
 * -----------------------
 * On the EXISTING `staff` document, under `statutoryProfile`. Not a new
 * collection — a new collection would need a new Firestore rules block, and
 * `firestore.rules` is concurrently owned by another programme and must not be
 * edited. Reusing the staff document inherits the access control that already
 * protects staff records, which is the correct posture for this data anyway:
 * it is employee PII of the same sensitivity as the rest of the record.
 *
 * WHAT THIS CLASS IS AND IS NOT
 * -----------------------------
 * It is PURE — validation, normalisation and resolution. No Firestore, no CI
 * dependency, no money. Persistence belongs to the controller, which already
 * owns staff writes and their audit trail.
 *
 * It contains **no rate, ceiling or threshold**. It answers "is this person a
 * member, and are they eligible for the pension scheme?" — never "how much".
 * The amounts remain `Statutory_config_service`'s, computed from the tenant's
 * CA-verified configuration.
 *
 * IT RECORDS FACTS, IT DOES NOT DECIDE POLICY
 * -------------------------------------------
 * Every field below is either a fact about the employee (they hold this UAN,
 * they joined on this date) or an election the employer/employee made (they
 * opted in under Para 26(6)). None of it is a policy this software chose. Where
 * a fact has not been established, the value is NULL and the resolver says
 * `undetermined` rather than guessing — see `resolve()`.
 */
class Staff_statutory_profile_service
{
    /** Where the profile hangs off the staff document. */
    const FIELD = 'statutoryProfile';

    /** PF membership states. */
    const PF_MEMBER       = 'member';
    const PF_NOT_MEMBER   = 'not_member';
    const PF_UNDETERMINED = 'undetermined';

    const PF_STATES = [self::PF_MEMBER, self::PF_NOT_MEMBER, self::PF_UNDETERMINED];

    /**
     * How this employee came to be (or not be) a member. Recorded because the
     * REASON changes the pension-scheme consequence, not merely the membership.
     *
     *   below_ceiling_at_joining   joined at or under the ceiling — mandatory
     *                              coverage, and membership survives crossing it
     *   above_ceiling_election     joined above the ceiling and opted in under
     *                              Para 26(6) — member, but NO pension scheme
     *   transferred_member         already a member at a previous employer
     *   excluded                   not covered
     *   undetermined               nobody has established it yet
     */
    const BASIS_BELOW_CEILING   = 'below_ceiling_at_joining';
    const BASIS_ABOVE_ELECTION  = 'above_ceiling_election';
    const BASIS_TRANSFERRED     = 'transferred_member';
    const BASIS_EXCLUDED        = 'excluded';
    const BASIS_UNDETERMINED    = 'undetermined';

    const BASES = [
        self::BASIS_BELOW_CEILING, self::BASIS_ABOVE_ELECTION,
        self::BASIS_TRANSFERRED, self::BASIS_EXCLUDED, self::BASIS_UNDETERMINED,
    ];

    /** UAN is a 12-digit number issued by EPFO. Format only — never a checksum guess. */
    const UAN_PATTERN = '/^[0-9]{12}$/';

    /** ESIC insurance numbers are 17 digits. Format only. */
    const ESIC_PATTERN = '/^[0-9]{17}$/';

    // ─────────────────────────────────────────────────────────────────────
    //  Empty / default shape
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The profile of an employee nobody has configured yet.
     *
     * Everything is `undetermined` or null — deliberately NOT "not a member".
     * "We have not established this" and "this person is not a member" are
     * different statements with different payroll consequences, and collapsing
     * them would silently exclude staff from PF.
     */
    public static function emptyProfile(): array
    {
        return [
            'pf' => [
                'state'        => self::PF_UNDETERMINED,
                'basis'        => self::BASIS_UNDETERMINED,
                'uan'          => '',
                'memberSince'  => '',
                'epsEligible'  => null,
            ],
            'esi' => [
                'covered'      => null,
                'esicNumber'   => '',
                'coveredSince' => '',
            ],
            'verification' => [
                'verified'   => false,
                'verifiedBy' => '',
                'verifiedAt' => '',
                'note'       => '',
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Validation
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Validate and normalise a submitted profile.
     *
     * @return array{0:bool,1:array,2:array} [ok, cleanProfile, errors]
     */
    public static function validate(array $in): array
    {
        $e = [];
        $out = self::emptyProfile();

        // ── PF ──
        $pfIn = is_array($in['pf'] ?? null) ? $in['pf'] : [];

        $state = strtolower(trim((string) ($pfIn['state'] ?? self::PF_UNDETERMINED)));
        if (!in_array($state, self::PF_STATES, true)) {
            $e[] = 'PF state must be one of: ' . implode(', ', self::PF_STATES) . '.';
            $state = self::PF_UNDETERMINED;
        }

        $basis = strtolower(trim((string) ($pfIn['basis'] ?? self::BASIS_UNDETERMINED)));
        if (!in_array($basis, self::BASES, true)) {
            $e[] = 'PF basis must be one of: ' . implode(', ', self::BASES) . '.';
            $basis = self::BASIS_UNDETERMINED;
        }

        // A member must say HOW. Without the basis the pension-scheme
        // consequence cannot be resolved, and guessing it is exactly what this
        // class exists to stop.
        if ($state === self::PF_MEMBER && $basis === self::BASIS_UNDETERMINED) {
            $e[] = 'A PF member must record how membership arose — the basis determines '
                . 'whether the pension scheme applies.';
        }

        // `excluded` and `member` are contradictory. Refuse rather than pick one.
        if ($state === self::PF_MEMBER && $basis === self::BASIS_EXCLUDED) {
            $e[] = 'A PF member cannot have basis "excluded".';
        }
        if ($state === self::PF_NOT_MEMBER
            && in_array($basis, [self::BASIS_BELOW_CEILING, self::BASIS_ABOVE_ELECTION, self::BASIS_TRANSFERRED], true)) {
            $e[] = 'A non-member cannot have a membership basis.';
        }

        $uan = trim((string) ($pfIn['uan'] ?? ''));
        if ($uan !== '' && !preg_match(self::UAN_PATTERN, $uan)) {
            $e[] = 'UAN must be exactly 12 digits.';
        }

        $memberSince = trim((string) ($pfIn['memberSince'] ?? ''));
        if ($memberSince !== '' && !self::isIsoDate($memberSince)) {
            $e[] = 'PF member-since must be an ISO date (YYYY-MM-DD).';
        }
        if ($state === self::PF_MEMBER && $memberSince === '') {
            $e[] = 'A PF member must record a member-since date, or historical payroll '
                . 'cannot be resolved correctly.';
        }

        // EPS eligibility: derived where the statute is unambiguous, explicit
        // otherwise. Para 26(6) joiners get NO pension scheme — that is not an
        // employer choice, so it is forced rather than accepted.
        $eps = $pfIn['epsEligible'] ?? null;
        if ($basis === self::BASIS_ABOVE_ELECTION) {
            if ($eps === true) {
                $e[] = 'An above-ceiling election under Para 26(6) does not carry pension-scheme '
                    . 'membership; the whole employer contribution goes to the fund.';
            }
            $eps = false;
        } elseif ($eps !== null) {
            $eps = (bool) $eps;
        }

        $out['pf'] = [
            'state'       => $state,
            'basis'       => $basis,
            'uan'         => $uan,
            'memberSince' => $memberSince,
            'epsEligible' => $eps,
        ];

        // ── ESI ──
        $esiIn = is_array($in['esi'] ?? null) ? $in['esi'] : [];

        $covered = $esiIn['covered'] ?? null;
        if ($covered !== null) $covered = (bool) $covered;

        $esic = trim((string) ($esiIn['esicNumber'] ?? ''));
        if ($esic !== '' && !preg_match(self::ESIC_PATTERN, $esic)) {
            $e[] = 'ESIC number must be exactly 17 digits.';
        }

        $coveredSince = trim((string) ($esiIn['coveredSince'] ?? ''));
        if ($coveredSince !== '' && !self::isIsoDate($coveredSince)) {
            $e[] = 'ESI covered-since must be an ISO date (YYYY-MM-DD).';
        }

        $out['esi'] = [
            'covered'      => $covered,
            'esicNumber'   => $esic,
            'coveredSince' => $coveredSince,
        ];

        // ── Verification ──
        $vIn = is_array($in['verification'] ?? null) ? $in['verification'] : [];
        $out['verification'] = [
            'verified'   => !empty($vIn['verified']),
            'verifiedBy' => trim((string) ($vIn['verifiedBy'] ?? '')),
            'verifiedAt' => trim((string) ($vIn['verifiedAt'] ?? '')),
            'note'       => trim((string) ($vIn['note'] ?? '')),
        ];

        return [$e === [], $out, $e];
    }

    /** Strict ISO date — not `strtotime`, which accepts "next tuesday". */
    public static function isIsoDate(string $d): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
        [$y, $m, $dd] = array_map('intval', explode('-', $d));
        return checkdate($m, $dd, $y);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Resolution — what payroll asks
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Resolve PF treatment for one employee for one period.
     *
     * PRECEDENCE, and why:
     *
     *   1. An established PROFILE wins. It is a recorded fact about the person,
     *      and it is the only thing that can express "member despite now
     *      earning above the ceiling".
     *   2. Where the profile is `undetermined`, fall back to the wage-versus-
     *      ceiling derivation the engine has always used — so an unconfigured
     *      tenant behaves exactly as it does today.
     *
     * The fallback is deliberately preserved rather than replaced. Removing it
     * would break every tenant that has not filled the profile in, which is all
     * of them today.
     *
     * @param array       $profile      the staff document's statutoryProfile, or []
     * @param string      $asOfDate     period date, ISO
     * @param bool|null   $ceilingDerived what wage-versus-ceiling concluded, or null
     * @return array{covered:bool|null, epsEligible:bool|null, source:string, reason:string}
     */
    public static function resolvePf(array $profile, string $asOfDate, ?bool $ceilingDerived = null): array
    {
        $pf = is_array($profile['pf'] ?? null) ? $profile['pf'] : [];
        $state = (string) ($pf['state'] ?? self::PF_UNDETERMINED);
        $basis = (string) ($pf['basis'] ?? self::BASIS_UNDETERMINED);

        if ($state === self::PF_MEMBER) {
            // Membership that has not started yet must not be applied to an
            // earlier period — that would back-date a contribution.
            $since = (string) ($pf['memberSince'] ?? '');
            if ($since !== '' && $asOfDate !== '' && $since > $asOfDate) {
                return [
                    'covered'     => false,
                    'epsEligible' => false,
                    'source'      => 'profile',
                    'reason'      => 'membership begins ' . $since . ', after this period',
                ];
            }

            return [
                'covered'     => true,
                'epsEligible' => $basis === self::BASIS_ABOVE_ELECTION
                    ? false                       // Para 26(6): no pension scheme
                    : ($pf['epsEligible'] ?? null),
                'source'      => 'profile',
                'reason'      => 'recorded member (' . $basis . ')',
            ];
        }

        if ($state === self::PF_NOT_MEMBER) {
            return [
                'covered'     => false,
                'epsEligible' => false,
                'source'      => 'profile',
                'reason'      => 'recorded as not a member',
            ];
        }

        return [
            'covered'     => $ceilingDerived,
            'epsEligible' => null,
            'source'      => 'ceiling_derivation',
            'reason'      => 'no statutory profile recorded; derived from wage against ceiling',
        ];
    }

    /**
     * Is this profile complete enough to run statutory payroll against?
     *
     * Used by the readiness surface so an operator sees "configuration required"
     * rather than a silently wrong payslip.
     */
    public static function completeness(array $profile): array
    {
        $missing = [];
        $pf = is_array($profile['pf'] ?? null) ? $profile['pf'] : [];

        $state = (string) ($pf['state'] ?? self::PF_UNDETERMINED);
        if ($state === self::PF_UNDETERMINED) {
            $missing[] = 'PF membership state';
        }
        if ($state === self::PF_MEMBER) {
            if ((string) ($pf['basis'] ?? self::BASIS_UNDETERMINED) === self::BASIS_UNDETERMINED) {
                $missing[] = 'PF membership basis';
            }
            if ((string) ($pf['memberSince'] ?? '') === '') {
                $missing[] = 'PF member-since date';
            }
            if ((string) ($pf['uan'] ?? '') === '') {
                $missing[] = 'UAN';
            }
        }

        return [
            'complete' => $missing === [],
            'missing'  => $missing,
        ];
    }
}
