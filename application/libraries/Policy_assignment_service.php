<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Policy_assignment_service — the ONE resolver that answers
 * "which policy applies to this employee on this date?"
 *
 * Phase 0 of the policy architecture. Generic over policy *type*: the same
 * resolution runs for work schedules, attendance policies, leave policies and
 * anything added later. Callers supply the catalogue and the assignments; this
 * class owns only the resolution.
 *
 * ─── RESOLUTION ORDER ────────────────────────────────────────────────────
 *
 *     staff  >  department  >  organisation  >  BLOCK
 *
 * Most specific wins. Nothing attached at any level is a BLOCK, never a guess.
 *
 * `staff` covers BOTH cases the operator distinguishes in the UI:
 *   - a "pick"     — the department offers two schedules and the employee
 *                    is on the night one;
 *   - an "override"— the employee uses something the department never offered.
 * They differ only in whether the chosen policy is in the department's offering
 * list, which is a *validation* question. To the resolver they are identical,
 * so there is no third level here.
 *
 * ─── WHY `department` IS SAFE AS A KEY ───────────────────────────────────
 *
 * `staff.department` is a SINGLE string (Firestore_service.php:1070), so an
 * employee can never match two department assignments and a tie is structurally
 * impossible. `staff_roles[]` is an ARRAY — keying on role WOULD create ties,
 * which is why role is never a level here.
 *
 * ─── THE ORGANISATION LEVEL ──────────────────────────────────────────────
 *
 * Operator decision D4: a tenant's declared weekly-offs are a legitimate
 * organisation-level policy. This is NOT an invented default — the caller
 * passes an organisation policy only when the operator actually declared one.
 * When nothing was declared the caller passes null and the resolver BLOCKS.
 * The distinction matters: we resolve from declared data, never from a rule
 * this code made up. The retired 2nd/4th-Saturday rule is never consulted.
 *
 * Every result carries `level` and `chain` so a payslip can always answer
 * "why was this day non-working?" — see explain().
 */
class Policy_assignment_service
{
    /** Assignment records live here; one collection, `policyType` discriminates. */
    const COLLECTION = 'policyAssignments';

    // ── Levels, most specific first. Resolution walks this array in order. ──
    const LEVEL_STAFF        = 'staff';
    const LEVEL_DEPARTMENT   = 'department';
    const LEVEL_ORGANISATION = 'organisation';

    const LEVELS = [
        self::LEVEL_STAFF,
        self::LEVEL_DEPARTMENT,
        self::LEVEL_ORGANISATION,
    ];

    /**
     * Reserved for later, deliberately NOT resolved yet. Declaring them here
     * means adding a level becomes a new value in an existing field rather than
     * a resolver rewrite. `validateAssignment()` accepts them so data can be
     * written ahead of support; `resolve()` ignores them until they join LEVELS.
     */
    const RESERVED_LEVELS = ['location', 'employment_type'];

    // ── Block codes. An administrator fixes each one differently, so they
    //    are never collapsed into a single "not configured".
    const BLOCK_NO_POLICIES        = 'no_policies';         // catalogue is empty
    const BLOCK_NO_ASSIGNMENT      = 'no_assignment';       // nothing at any level
    const BLOCK_ASSIGNMENT_EXPIRED = 'assignment_expired';  // lapsed before this date
    const BLOCK_AMBIGUOUS          = 'ambiguous_assignment';// two cover the same date
    const BLOCK_POLICY_MISSING     = 'policy_missing';      // points at a policy that is gone

    // ─────────────────────────────────────────────────────────────────────
    //  THE resolver
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Resolve one policy for one employee on one date.
     *
     * @param array $context
     *        'policies'            [policyId => policy]  the catalogue
     *        'assignments'         list of assignment records (any level)
     *        'staffId'             string
     *        'department'          string ('' when the employee has none)
     *        'organisationPolicyId' ?string  D4 — set ONLY when the operator
     *                              declared one; null means nothing declared.
     * @param string $date ISO yyyy-mm-dd
     *
     * @return array policy, policyId, level, assignment, block, chain
     */
    public static function resolve(array $context, string $date): array
    {
        $policies    = is_array($context['policies'] ?? null) ? $context['policies'] : [];
        $assignments = is_array($context['assignments'] ?? null) ? $context['assignments'] : [];
        $staffId     = (string) ($context['staffId'] ?? '');
        $department  = (string) ($context['department'] ?? '');
        $orgPolicyId = $context['organisationPolicyId'] ?? null;

        $chain = [];

        if (empty($policies)) {
            return self::fail(self::BLOCK_NO_POLICIES, $chain, 'catalogue is empty');
        }

        // Walk levels most-specific first. The first level that produces a
        // covering assignment wins outright — later levels are not consulted.
        foreach (self::LEVELS as $level) {

            if ($level === self::LEVEL_ORGANISATION) {
                // The organisation level has no assignment record. It applies
                // only when the operator declared one (D4).
                if ($orgPolicyId === null || $orgPolicyId === '') {
                    $chain[] = ['level' => $level, 'result' => 'none_declared'];
                    continue;
                }
                if (!isset($policies[$orgPolicyId])) {
                    $chain[] = ['level' => $level, 'result' => 'policy_missing', 'policyId' => $orgPolicyId];
                    return self::fail(self::BLOCK_POLICY_MISSING, $chain, 'organisation policy not in catalogue');
                }
                $chain[] = ['level' => $level, 'result' => 'matched', 'policyId' => $orgPolicyId];
                return [
                    'policy'     => $policies[$orgPolicyId],
                    'policyId'   => $orgPolicyId,
                    'level'      => $level,
                    'assignment' => null,
                    'block'      => null,
                    'chain'      => $chain,
                ];
            }

            $key = ($level === self::LEVEL_STAFF) ? $staffId : $department;
            if ($key === '') {
                $chain[] = ['level' => $level, 'result' => 'no_key'];
                continue;
            }

            $atLevel = self::assignmentsAtLevel($assignments, $level, $key);
            if (empty($atLevel)) {
                $chain[] = ['level' => $level, 'result' => 'no_assignment'];
                continue;
            }

            $eff = self::effectiveAt($atLevel, $date);

            if ($eff['block'] === self::BLOCK_AMBIGUOUS) {
                // Never resolved by read order. The administrator must fix the
                // overlap; picking one silently would make payroll depend on
                // document ordering.
                $chain[] = ['level' => $level, 'result' => 'ambiguous', 'count' => count($atLevel)];
                return self::fail(self::BLOCK_AMBIGUOUS, $chain, 'overlapping assignments at ' . $level);
            }

            if ($eff['assignment'] === null) {
                // Expired-at-this-level does not stop the walk: a lapsed staff
                // override should fall through to the department, which is what
                // an administrator means by an end-dated exception.
                $chain[] = ['level' => $level, 'result' => $eff['block']];
                continue;
            }

            $policyId = (string) ($eff['assignment']['policyId'] ?? '');
            if ($policyId === '' || !isset($policies[$policyId])) {
                $chain[] = ['level' => $level, 'result' => 'policy_missing', 'policyId' => $policyId];
                return self::fail(self::BLOCK_POLICY_MISSING, $chain, 'assignment points at a missing policy');
            }

            $chain[] = ['level' => $level, 'result' => 'matched', 'policyId' => $policyId];
            return [
                'policy'     => $policies[$policyId],
                'policyId'   => $policyId,
                'level'      => $level,
                'assignment' => $eff['assignment'],
                'block'      => null,
                'chain'      => $chain,
            ];
        }

        // Nothing anywhere. Report "expired" in preference to "none" when a
        // lapsed assignment was seen — the two are fixed differently.
        $sawExpired = false;
        foreach ($chain as $step) {
            if (($step['result'] ?? '') === self::BLOCK_ASSIGNMENT_EXPIRED) { $sawExpired = true; break; }
        }
        return self::fail(
            $sawExpired ? self::BLOCK_ASSIGNMENT_EXPIRED : self::BLOCK_NO_ASSIGNMENT,
            $chain,
            'no policy applies on ' . $date
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Level filtering + effective dating
    // ─────────────────────────────────────────────────────────────────────

    /** Assignments attached at one level to one key. */
    public static function assignmentsAtLevel(array $assignments, string $level, string $key): array
    {
        $out = [];
        foreach ($assignments as $a) {
            if (!is_array($a)) continue;
            if ((string) ($a['attachmentType'] ?? '') !== $level) continue;
            if ((string) ($a['attachmentId'] ?? '') !== $key) continue;
            $out[] = $a;
        }
        return $out;
    }

    /**
     * The one assignment covering $date, or a block code.
     *
     * Generalised from Work_schedule_service::effectiveAssignment(), which
     * becomes a caller of this rather than keeping its own copy.
     */
    public static function effectiveAt(array $assignments, string $date): array
    {
        if (empty($assignments)) {
            return ['assignment' => null, 'block' => self::BLOCK_NO_ASSIGNMENT];
        }

        $covering = [];
        foreach ($assignments as $a) {
            $from = (string) ($a['effectiveFrom'] ?? '');
            if ($from === '' || $from > $date) continue;
            $to = $a['effectiveTo'] ?? null;
            if ($to !== null && (string) $to !== '' && (string) $to < $date) continue;
            $covering[] = $a;
        }

        if (count($covering) > 1) {
            return ['assignment' => null, 'block' => self::BLOCK_AMBIGUOUS];
        }
        if (count($covering) === 1) {
            return ['assignment' => $covering[0], 'block' => null];
        }

        // Nothing covers the date. Distinguish "never started" from "lapsed" —
        // an administrator fixes them differently.
        foreach ($assignments as $a) {
            $to = (string) ($a['effectiveTo'] ?? '');
            if ($to !== '' && $to < $date) {
                return ['assignment' => null, 'block' => self::BLOCK_ASSIGNMENT_EXPIRED];
            }
        }
        return ['assignment' => null, 'block' => self::BLOCK_NO_ASSIGNMENT];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Validation
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Validate one assignment record before it is written.
     * Reserved levels are accepted so data may be written ahead of resolution
     * support; they simply never win a resolve() until promoted into LEVELS.
     */
    public static function validateAssignment($a): array
    {
        $errors = [];
        if (!is_array($a)) return ['valid' => false, 'errors' => ['assignment must be an object']];

        $type = (string) ($a['attachmentType'] ?? '');
        if ($type === '') {
            $errors[] = 'attachmentType is required';
        } elseif (!in_array($type, self::LEVELS, true)
               && !in_array($type, self::RESERVED_LEVELS, true)) {
            $errors[] = 'unsupported attachmentType: ' . $type;
        }

        if ((string) ($a['attachmentId'] ?? '') === '') $errors[] = 'attachmentId is required';
        if ((string) ($a['policyId'] ?? '')     === '') $errors[] = 'policyId is required';
        if ((string) ($a['policyType'] ?? '')   === '') $errors[] = 'policyType is required';

        $from = (string) ($a['effectiveFrom'] ?? '');
        if ($from === '') {
            $errors[] = 'effectiveFrom is required';
        } elseif (!self::isIsoDate($from)) {
            $errors[] = 'effectiveFrom must be yyyy-mm-dd';
        }

        $to = (string) ($a['effectiveTo'] ?? '');
        if ($to !== '') {
            if (!self::isIsoDate($to))      $errors[] = 'effectiveTo must be yyyy-mm-dd';
            elseif ($from !== '' && $to < $from) $errors[] = 'effectiveTo is before effectiveFrom';
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    /**
     * Refuse a new assignment that would overlap an existing one at the same
     * level and key. Callers check this BEFORE writing, so an ambiguous state
     * is never persisted — resolve() blocking on it is the second line of
     * defence, not the first.
     */
    public static function findOverlaps(array $existing, array $candidate): array
    {
        $level = (string) ($candidate['attachmentType'] ?? '');
        $key   = (string) ($candidate['attachmentId'] ?? '');
        $cFrom = (string) ($candidate['effectiveFrom'] ?? '');
        $cTo   = (string) ($candidate['effectiveTo'] ?? '');
        $cId   = (string) ($candidate['assignmentId'] ?? '');

        $hits = [];
        foreach (self::assignmentsAtLevel($existing, $level, $key) as $e) {
            if ($cId !== '' && (string) ($e['assignmentId'] ?? '') === $cId) continue; // editing itself
            $eFrom = (string) ($e['effectiveFrom'] ?? '');
            $eTo   = (string) ($e['effectiveTo'] ?? '');
            $startsAfterEnd = ($eTo !== '' && $cFrom > $eTo);
            $endsBeforeStart= ($cTo !== '' && $cTo < $eFrom);
            if (!$startsAfterEnd && !$endsBeforeStart) $hits[] = $e;
        }
        return $hits;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Explainability (B5)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * One human-readable line per level examined. This is what lets a payslip
     * answer "why was this day non-working?" instead of only showing a result.
     */
    public static function explain(array $result): string
    {
        $lines = [];
        foreach (($result['chain'] ?? []) as $step) {
            $level = (string) ($step['level'] ?? '?');
            switch ((string) ($step['result'] ?? '')) {
                case 'matched':
                    $lines[] = $level . ': matched policy ' . (string) ($step['policyId'] ?? '');
                    break;
                case 'no_key':
                    $lines[] = $level . ': employee has no ' . $level;
                    break;
                case 'none_declared':
                    $lines[] = $level . ': nothing declared by the operator';
                    break;
                case 'ambiguous':
                    $lines[] = $level . ': REFUSED — ' . (int) ($step['count'] ?? 0) . ' overlapping assignments';
                    break;
                case 'policy_missing':
                    $lines[] = $level . ': points at missing policy ' . (string) ($step['policyId'] ?? '');
                    break;
                case self::BLOCK_ASSIGNMENT_EXPIRED:
                    $lines[] = $level . ': assignment expired before this date';
                    break;
                default:
                    $lines[] = $level . ': no assignment';
            }
        }
        if (!empty($result['block'])) {
            $lines[] = 'RESULT: BLOCKED (' . $result['block'] . ')';
        } elseif (!empty($result['level'])) {
            $lines[] = 'RESULT: resolved at ' . $result['level'];
        }
        return implode("\n", $lines);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Internals
    // ─────────────────────────────────────────────────────────────────────

    private static function fail(string $block, array $chain, string $reason): array
    {
        return [
            'policy'     => null,
            'policyId'   => null,
            'level'      => null,
            'assignment' => null,
            'block'      => $block,
            'reason'     => $reason,
            'chain'      => $chain,
        ];
    }

    private static function isIsoDate(string $d): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
        [$y, $m, $dd] = array_map('intval', explode('-', $d));
        return checkdate($m, $dd, $y);
    }
}
