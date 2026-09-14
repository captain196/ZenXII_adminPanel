<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Timetable_plan_service — the authoritative timetable domain model.
 *
 * PHASE 1 OF THE TIMETABLE ARCHITECTURE. Additive and fully reversible: this
 * file introduces the model and the date-resolution semantics as PURE logic.
 * It performs NO Firestore reads or writes, is wired into nothing, and changes
 * no existing behaviour. Storage arrives in phase 2; isolated candidates in
 * phase 3; publishing and the compatibility projection in phase 5.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
 *
 * ZenX stores ONE timetable per class-section per session, overwritten in
 * place. `version` is a change counter — version 7 does not mean versions 1-6
 * can be restored; they are gone. So the system cannot answer "which timetable
 * was actually running on 10 July?" once anything changes.
 *
 * ── THE DISTINCTION THIS MODEL EXISTS TO PRESERVE ────────────────────────
 *
 *   VERSION          the same stretch of time, planned better.
 *                    "Revision 4 of the Winter timetable — 8B's labs were wrong."
 *
 *   EFFECTIVE PERIOD a genuinely different timetable for a different stretch.
 *                    "Regular runs Apr-Sep; Winter runs Oct-Dec."
 *
 * One REPLACES a plan; the other SUCCEEDS it. Collapsing them into a single
 * "version" concept is the modelling error that would force a later rewrite,
 * because a school that changes its timetable in October has not corrected
 * September — September was right at the time, and must stay answerable.
 *
 * ── SIX LAYERS, KEPT SEPARATE ────────────────────────────────────────────
 *
 *   1 Academic Session   already exists in ZenX and is authoritative
 *   2 Timetable Plan     "Regular" / "Winter" / "Exam"      <- this file
 *   3 Version            a revision of a plan               <- this file
 *   4 Effective Period   which version is live, and when    <- this file
 *   5 Temporary Override a date-ranged replacement          (phase 5)
 *   6 Daily Exception    a cover, a half-day                ALREADY CORRECT
 *
 * Layer 6 is already implemented correctly by Substitute_service and the
 * calendar, which are deliberately SEPARATE from the timetable. A one-day
 * cover must never rewrite the published base. This service does not touch
 * either, and must not.
 *
 * ── CONVENTIONS ARE REUSED, NOT INVENTED ─────────────────────────────────
 *
 * ZenX already effective-dates in 136 places (Entitlements, Fee concessions,
 * F&F policy, Leave policy, Institution lifecycle, Policy assignment). This
 * service follows Policy_assignment_service exactly:
 *
 *   - `effectiveFrom` required, ISO yyyy-mm-dd, validated with checkdate();
 *   - `effectiveTo` optional — an EMPTY value means OPEN-ENDED;
 *   - a record is skipped when `effectiveTo` is set and falls before the date;
 *   - overlaps are DETECTED AND REPORTED rather than silently forbidden;
 *   - resolution explains itself in one human-readable line, so an operator
 *     can be told WHY a given timetable applied on a given day.
 *
 * ── THE ONE RULE THIS FILE WILL NOT DECIDE ───────────────────────────────
 *
 * If two published versions both cover a date, that is an AMBIGUITY, and
 * choosing a winner (newest wins? narrowest range wins? highest priority?) is
 * a product policy nobody has decided. resolveForDate() therefore reports the
 * ambiguity instead of guessing. Guessing would be the silent-wrong-answer
 * failure this whole programme exists to remove.
 */
class Timetable_plan_service
{
    /** A plan is a named timetable within a session: "Regular", "Winter", "Exam". */
    const KIND_REGULAR   = 'regular';
    const KIND_TEMPORARY = 'temporary';
    const KIND_EXAM      = 'exam';

    /**
     * Version lifecycle. Only PUBLISHED versions are ever resolvable — a draft
     * or a candidate must never be visible to a teacher or a parent.
     *
     * The transition rules (in particular whether PUBLISHED is immutable) are a
     * phase-5 owner decision and are deliberately NOT encoded here.
     */
    const STATUS_DRAFT      = 'draft';
    const STATUS_CANDIDATE  = 'candidate';
    const STATUS_PUBLISHED  = 'published';
    const STATUS_SUPERSEDED = 'superseded';

    /** Outcomes of resolving a date. */
    const RESOLVED   = 'resolved';
    const NONE       = 'none';
    const AMBIGUOUS  = 'ambiguous';
    const INVALID    = 'invalid';

    /** Generation-run states stamped on a version by the store. */
    const GEN_RUNNING  = 'running';
    const GEN_COMPLETE = 'complete';
    const GEN_FAILED   = 'failed';

    /**
     * Is this version's generated content COMPLETE?
     *
     * ACAD-D24 at candidate level. The live generator commits in sequential
     * chunks: if chunk 3 of 7 fails, chunks 1-2 are already written and the run
     * stops. Moving that into an isolated candidate stops it damaging the live
     * timetable, but it does NOT stop the half-written candidate from looking
     * like a finished one — and a half-written timetable that reads as finished
     * is exactly as dangerous once it reaches validation, comparison or publish.
     *
     * So completeness is explicit and CLOSED: anything that is not a finished
     * run — running, failed, absent, or an unrecognised state — is incomplete.
     * A candidate written before this field existed is therefore also
     * incomplete, which is the safe reading rather than the convenient one.
     */
    public static function isGenerationComplete($version): bool
    {
        if (!is_array($version)) return false;
        $gen = $version['generation'] ?? null;
        if (!is_array($gen)) return false;
        return (string) ($gen['state'] ?? '') === self::GEN_COMPLETE;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Resolution
    // ─────────────────────────────────────────────────────────────────────

    /**
     * IS THIS VERSION LIVE, AND OVER WHAT DATES? — the one definition.
     *
     * ── WHY THIS EXISTS (TT-C8, option 2) ───────────────────────────────
     *
     * "In force" used to be defined TWICE, and the two definitions disagreed:
     *
     *   publish side   Timetable_plan_store::_publishedOverlaps() — published
     *                  versions OF THE SAME planId
     *   read side      resolveForDate() below — published versions across ALL
     *                  plans for the school and session
     *
     * The write-side one was the narrower, so publishing an EXAM timetable over
     * a live REGULAR one reported `overlaps: []` — no warning at all — and the
     * projection ran, because it is gated on that emptiness. The resolver then
     * saw two matches and reported AMBIGUOUS for ever. Proven by execution:
     * two sequential publishes, one administrator, no concurrency involved.
     *
     * There is now ONE predicate. `coversDate()` is what resolution asks of a
     * single version; `rangesOverlap()` is the same question asked of two
     * ranges instead of a point. Everything else — publish validation included
     * — is built from these, so the two sides cannot drift apart again.
     *
     * House convention throughout: an empty `effectiveTo` means OPEN-ENDED, and
     * a version whose `effectiveFrom` is missing or malformed is not live at
     * all (it cannot be placed on a calendar, so it can neither resolve nor
     * overlap).
     */
    public static function coversDate($version, string $date): bool
    {
        if (!is_array($version) || !self::isIsoDate($date)) return false;
        if ((string) ($version['status'] ?? '') !== self::STATUS_PUBLISHED) return false;

        $from = (string) ($version['effectiveFrom'] ?? '');
        if (!self::isIsoDate($from) || $from > $date) return false;

        $to = (string) ($version['effectiveTo'] ?? '');
        return !($to !== '' && $to < $date);
    }

    /**
     * Do two effective periods share at least one day?
     *
     * Both ends inclusive; '' as an end means open-ended. Kept separate from
     * the version shape so it can be reasoned about — and tested — as plain
     * interval arithmetic.
     */
    public static function rangesOverlap(string $aFrom, string $aTo,
                                         string $bFrom, string $bTo): bool
    {
        // A range with no valid start is not on the calendar, so it overlaps
        // nothing. This mirrors coversDate() refusing it, which is the point:
        // one rule, asked two ways.
        if (!self::isIsoDate($aFrom) || !self::isIsoDate($bFrom)) return false;

        $aEndsFirst = ($aTo !== '' && self::isIsoDate($aTo) && $aTo < $bFrom);
        $bEndsFirst = ($bTo !== '' && self::isIsoDate($bTo) && $bTo < $aFrom);

        return !($aEndsFirst || $bEndsFirst);
    }

    /**
     * Every PUBLISHED version that would share a date with [$from, $to].
     *
     * Deliberately NOT filtered by planId. A school has one timetable in force
     * on any given day; which plan it came from does not change that, and
     * filtering by plan is exactly the hole that let a second live timetable in
     * silently.
     *
     * @param array  $versions  every version for ONE school + session
     * @param string $excludeVersionId  the version being published (self)
     * @param string $excludePlanId     its plan — plan+version together
     *                                  identify it, since versionIds are only
     *                                  unique within a plan
     */
    public static function publishedOverlaps(array $versions, string $excludePlanId,
                                             string $excludeVersionId,
                                             string $from, string $to): array
    {
        $out = [];
        foreach ($versions as $v) {
            if (!is_array($v)) continue;
            if ((string) ($v['status'] ?? '') !== self::STATUS_PUBLISHED) continue;

            // Self. Compared on BOTH ids: 'V1' exists in every plan, so
            // matching on versionId alone would silently excuse another
            // plan's version from the check.
            if ((string) ($v['planId'] ?? '') === $excludePlanId
                && (string) ($v['versionId'] ?? '') === $excludeVersionId) continue;

            if (!self::rangesOverlap((string) ($v['effectiveFrom'] ?? ''),
                                     (string) ($v['effectiveTo'] ?? ''),
                                     $from, $to)) continue;

            $out[] = [
                'planId'        => (string) ($v['planId'] ?? ''),
                'versionId'     => (string) ($v['versionId'] ?? ''),
                'planName'      => (string) ($v['planName'] ?? ''),
                'effectiveFrom' => (string) ($v['effectiveFrom'] ?? ''),
                'effectiveTo'   => (string) ($v['effectiveTo'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Which published version applies on $date?
     *
     * @param array  $versions rows shaped {planId, versionId, planName, status,
     *                         effectiveFrom, effectiveTo}
     * @param string $date     ISO yyyy-mm-dd
     *
     * @return array {status, version|null, candidates[], reason, explain}
     *
     * NEVER returns a guess. A date covered by two published versions comes
     * back AMBIGUOUS with both listed, because picking a winner is a policy
     * decision that has not been taken.
     */
    public static function resolveForDate(array $versions, string $date): array
    {
        if (!self::isIsoDate($date)) {
            return self::outcome(self::INVALID, null, [],
                "'{$date}' is not a valid yyyy-mm-dd date");
        }

        // The membership test is coversDate(), NOT a copy of it. Drafts,
        // candidates and superseded versions are never live — that is the
        // guarantee that lets generation work in the open without a teacher
        // ever seeing an unfinished timetable — and it is enforced there,
        // once, alongside the dating rules the publish side also uses.
        $matches = [];
        foreach ($versions as $v) {
            if (self::coversDate($v, $date)) $matches[] = $v;
        }

        if (count($matches) === 0) {
            return self::outcome(self::NONE, null, [],
                "no published timetable covers {$date}");
        }

        if (count($matches) > 1) {
            return self::outcome(self::AMBIGUOUS, null, $matches,
                count($matches) . " published timetables cover {$date}");
        }

        return self::outcome(self::RESOLVED, $matches[0], $matches, 'exactly one match');
    }

    /**
     * What a compatibility projection run should DO about a resolution.
     *
     * Pure, and deliberately tiny. It exists so the scheduled projection has no
     * judgement of its own: the cron asks resolveForDate() what is live, passes
     * the answer here, and does what it is told. Without this the decision lived
     * inside a CI_Controller that no unit test can instantiate, so it could only
     * be tested by a copy — and a copy of a safety rule drifts away from the
     * rule.
     *
     *   project → exactly one published version covers the date
     *   skip    → nothing is published for it. Not a failure, and NOT a reason
     *             to clear what teachers can currently see: this materialises
     *             what is live, it does not enforce emptiness.
     *   refuse  → ambiguous, unreadable or invalid. Every one means we do not
     *             KNOW what is live. The legacy collection holds one document
     *             per section per day and cannot represent two timetables, so
     *             picking one would silently show it to every teacher.
     *
     * @return array action: project|skip|refuse, exitCode, reason
     */
    public static function projectionDecision($resolution): array
    {
        $status = is_array($resolution) ? (string) ($resolution['status'] ?? '') : '';

        if ($status === self::RESOLVED) {
            return ['action' => 'project', 'exitCode' => 0,
                    'reason'  => 'exactly one published timetable applies'];
        }
        if ($status === self::NONE) {
            return ['action' => 'skip', 'exitCode' => 0,
                    'reason'  => 'nothing published covers this date, so there is '
                               . 'nothing to make visible'];
        }
        return ['action' => 'refuse', 'exitCode' => 2,
                'reason'  => 'the timetable in force could not be determined '
                           . 'unambiguously (' . ($status ?: 'no resolution') . ')'];
    }
    /**
     * One human-readable line, following the explainability convention
     * Policy_assignment_service established so a payslip could justify itself.
     * The equivalent here is an attendance register justifying which timetable
     * it was taken against.
     */
    public static function explain(array $result, string $date): string
    {
        $status = (string) ($result['status'] ?? '');

        if ($status === self::RESOLVED) {
            $v = $result['version'] ?? [];
            $to = (string) ($v['effectiveTo'] ?? '');
            return sprintf(
                '%s on %s: "%s" %s, effective %s to %s',
                'Timetable', $date,
                (string) ($v['planName'] ?? $v['planId'] ?? 'unnamed'),
                (string) ($v['versionId'] ?? ''),
                (string) ($v['effectiveFrom'] ?? '?'),
                $to === '' ? 'open-ended' : $to
            );
        }

        if ($status === self::AMBIGUOUS) {
            $names = [];
            foreach (($result['candidates'] ?? []) as $c) {
                $names[] = (string) ($c['planName'] ?? $c['planId'] ?? '?')
                    . ' ' . (string) ($c['versionId'] ?? '');
            }
            return "Timetable on {$date}: AMBIGUOUS — "
                . implode(' and ', $names) . ' both apply. '
                . 'Resolve the overlap before relying on this date.';
        }

        if ($status === self::NONE) {
            return "Timetable on {$date}: none published for this date.";
        }

        return "Timetable on {$date}: cannot resolve — "
            . (string) ($result['reason'] ?? 'unknown');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Validation
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Validate a version's effective range. Same shape and rules as
     * Policy_assignment_service::validateAssignment().
     *
     * @return string[] errors; empty means valid
     */
    public static function validateVersion($v): array
    {
        $errors = [];
        if (!is_array($v)) return ['version must be an array'];

        foreach (['schoolId', 'session', 'planId', 'versionId'] as $req) {
            if ((string) ($v[$req] ?? '') === '') $errors[] = "{$req} is required";
        }

        $status = (string) ($v['status'] ?? '');
        if (!in_array($status, [
            self::STATUS_DRAFT, self::STATUS_CANDIDATE,
            self::STATUS_PUBLISHED, self::STATUS_SUPERSEDED,
        ], true)) {
            $errors[] = "status '{$status}' is not a known version status";
        }

        $from = (string) ($v['effectiveFrom'] ?? '');
        if ($from === '')             $errors[] = 'effectiveFrom is required';
        elseif (!self::isIsoDate($from)) $errors[] = 'effectiveFrom must be yyyy-mm-dd';

        $to = (string) ($v['effectiveTo'] ?? '');
        if ($to !== '') {
            if (!self::isIsoDate($to))            $errors[] = 'effectiveTo must be yyyy-mm-dd';
            elseif ($from !== '' && $to < $from)  $errors[] = 'effectiveTo is before effectiveFrom';
        }

        return $errors;
    }

    /**
     * Published versions whose effective range overlaps $candidate.
     *
     * Mirrors Policy_assignment_service::findOverlaps(): overlaps are surfaced,
     * not forbidden. A school may legitimately be mid-transition, and the house
     * convention is to report rather than block. The candidate is excluded from
     * its own comparison so editing a version does not overlap itself.
     */
    public static function findOverlaps(array $existing, array $candidate): array
    {
        $cFrom = (string) ($candidate['effectiveFrom'] ?? '');
        $cTo   = (string) ($candidate['effectiveTo']   ?? '');
        $cId   = (string) ($candidate['versionId']     ?? '');

        $hits = [];
        foreach ($existing as $e) {
            if (!is_array($e)) continue;
            if ((string) ($e['status'] ?? '') !== self::STATUS_PUBLISHED) continue;
            if ($cId !== '' && (string) ($e['versionId'] ?? '') === $cId) continue;

            $eFrom = (string) ($e['effectiveFrom'] ?? '');
            $eTo   = (string) ($e['effectiveTo']   ?? '');

            $startsAfterEnd  = ($eTo !== '' && $cFrom > $eTo);
            $endsBeforeStart = ($cTo !== '' && $cTo < $eFrom);
            if (!$startsAfterEnd && !$endsBeforeStart) $hits[] = $e;
        }
        return $hits;
    }

    // ─────────────────────────────────────────────────────────────────────

    private static function outcome(string $status, ?array $version, array $candidates, string $reason): array
    {
        return [
            'status'     => $status,
            'version'    => $version,
            'candidates' => $candidates,
            'reason'     => $reason,
        ];
    }

    /**
     * A real yyyy-mm-dd date — not merely a string that looks like one.
     *
     * Public since 2026-09-01 (TT-C8 option 6). The SUPERSEDE transition
     * validates an operator-supplied end date against the same rule the
     * resolver and the overlap check already use; a second date validator
     * beside this one is how the two definitions of "in force" drifted apart
     * in the first place. Behaviour is unchanged — only the visibility.
     */
    public static function isIsoDate(string $d): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
        [$y, $m, $dd] = array_map('intval', explode('-', $d));
        return checkdate($m, $dd, $y);
    }
}
