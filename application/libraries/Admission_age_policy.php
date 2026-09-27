<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * ADM-AGE-1 — the class-vs-age admission rule, as a PURE function.
 *
 * ── WHY THIS IS NOT A METHOD ON Sis ──────────────────────────────────────
 * It used to be. The date arithmetic here is the subtlest part of the whole
 * admission gate — a cut-off comparison that is wrong by one day changes who
 * gets a seat — and inside a 12k-line controller it could only ever be
 * *source-asserted* ("the file contains diff(\$cutoff)"), never exercised. A
 * guard that reads the code is not a test of the code.
 *
 * Pulled out here it has no CodeIgniter, no Firestore and no session: the
 * caller reads the tenant's stored policy and hands it in, so every branch —
 * the 31-March/1-April boundary especially — is reachable from a plain unit
 * test. Loaded with require_once, exactly as Section_seat_guard is.
 *
 * ── WHY THE RULE ITSELF IS TENANT DATA ───────────────────────────────────
 * No band is hard-coded anywhere in ZenX. Education is a concurrent subject,
 * so states differ; CBSE, ICSE and the state boards expect different things;
 * and the cut-off date (31 March, 31 May, 30 September) is the school's own
 * choice. Hard-coding "6+ as on 31 March" would refuse every legitimate
 * admission in a state permitting 5+, the front office would override the
 * system on day one, and a check everyone routes around protects nothing.
 */
class Admission_age_policy
{
    /** Verdicts that actually stop an admission. */
    public const MODE_OFF   = 'off';
    public const MODE_WARN  = 'warn';
    public const MODE_BLOCK = 'block';

    /**
     * Evaluate one admission against the school's policy.
     *
     * AGE IS MEASURED AT THE CUT-OFF, NOT TODAY — that is the whole point of
     * the rule as a registrar applies it. A child who turns 6 on 1 April is
     * five "as on 31 March" and waits a year. Measuring from today would admit
     * them and put this school quietly out of step with every other one in the
     * state, and with its own UDISE+ return.
     *
     * The cut-off belongs to the ACADEMIC year being admitted into, so it is
     * derived from the session ("2026-27" -> 2026), never from the calendar —
     * otherwise an admission processed in February 2027 for session 2026-27
     * would be measured against a 2027 cut-off and silently shift by a year.
     *
     * Returns null when there is nothing to say: policy off, no band for this
     * class, or the child is inside it. Callers treat null as "no opinion".
     *
     * @param  array  $policy   {mode, cutoff: 'MM-DD', bands: {class: {min, max}}}
     * @return array{severity:string,message:string,age:int,cutoff:string}|null
     */
    public static function evaluate(
        array $policy,
        string $classOrd,
        DateTimeImmutable $dob,
        string $sessionYear
    ): ?array {
        $mode = (string) ($policy['mode'] ?? self::MODE_OFF);
        if ($mode !== self::MODE_WARN && $mode !== self::MODE_BLOCK) return null;

        $bands = is_array($policy['bands'] ?? null) ? $policy['bands'] : [];
        if (!$bands) return null;

        // A school may have banded "9th" while admission passes "Class 9th".
        // Matching both spellings rather than failing silently matters: a policy
        // that quietly matches nothing looks exactly like a policy that works.
        $bare = trim(str_replace('Class ', '', $classOrd));
        $band = $bands[$classOrd] ?? $bands[$bare] ?? null;
        if (!is_array($band)) return null;

        $min = (isset($band['min']) && $band['min'] !== null && $band['min'] !== '')
             ? (int) $band['min'] : null;
        $max = (isset($band['max']) && $band['max'] !== null && $band['max'] !== '')
             ? (int) $band['max'] : null;
        if ($min === null && $max === null) return null;

        $cutoff = self::cutoffFor($policy, $sessionYear);
        if ($cutoff === null) return null;   // unreadable policy must not block an admission

        // A DOB after the cut-off would give a negative age; diff() reports
        // absolute values, so guard it explicitly rather than reporting a
        // nonsense age with confidence.
        if ($dob > $cutoff) {
            $age = 0;
        } else {
            $age = (int) $dob->diff($cutoff)->y;
        }

        if (($min === null || $age >= $min) && ($max === null || $age <= $max)) {
            return null;   // inside the band
        }

        $bandText = ($min !== null && $max !== null) ? "{$min}-{$max}"
                  : ($min !== null ? "{$min} or above" : "{$max} or below");

        return [
            'severity' => $mode,
            'age'      => $age,
            'cutoff'   => $cutoff->format('Y-m-d'),
            'message'  => sprintf(
                'The student would be %d year(s) old as on %s, and this school\'s policy '
                . 'for %s is %s. %s',
                $age, $cutoff->format('j F Y'), $classOrd, $bandText,
                $mode === self::MODE_BLOCK
                    ? 'Check the date of birth, or choose the age-appropriate class.'
                    : 'Admitted anyway — check the date of birth if this looks wrong.'
            ),
        ];
    }

    /**
     * The cut-off date for a session, or null if the policy cannot be read.
     *
     * Strict on purpose: '02-31' must not be accepted and then rolled to 3
     * March by the date library, because a cut-off that silently moves is
     * worse than one that is ignored.
     */
    public static function cutoffFor(array $policy, string $sessionYear): ?DateTimeImmutable
    {
        $md = trim((string) ($policy['cutoff'] ?? '03-31'));
        if ($md === '') $md = '03-31';

        $year = (int) substr($sessionYear, 0, 4);
        if ($year < 2000 || $year > 2999) $year = (int) date('Y');

        $dt  = DateTimeImmutable::createFromFormat('!Y-m-d', $year . '-' . $md);
        $err = DateTimeImmutable::getLastErrors();
        if ($err === false) $err = ['warning_count' => 0, 'error_count' => 0];

        if (!$dt instanceof DateTimeImmutable
            || (int) $err['warning_count'] !== 0
            || (int) $err['error_count'] !== 0) {
            return null;
        }
        return $dt;
    }
}
