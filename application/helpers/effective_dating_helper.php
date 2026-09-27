<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Effective-dating primitives (ZenX HRMS W2.3).
 *
 * WHY THIS EXISTS
 * ---------------
 * W2.2 needed effective-dated statutory configuration; W2.3 needs effective-
 * dated salary assignments. Both answer the same two questions — "which record
 * is in force on this date?" and "do these windows overlap?" — and both must
 * answer them IDENTICALLY. Two implementations would eventually disagree, and
 * the disagreement would show up as a payroll period resolving to one statutory
 * config but a different salary assignment: silently wrong, and very hard to
 * trace.
 *
 * So the logic lives here once. `Statutory_config_service` delegates to it
 * rather than keeping its own copy — same reasoning as extracting `hr_next_id()`
 * in W1.3 so ATS and HR could not mint colliding ids.
 *
 * A record is any array carrying:
 *   effectiveFrom  'YYYY-MM-DD'   required
 *   effectiveTo    'YYYY-MM-DD'|null   null = open-ended
 *
 * Both bounds are INCLUSIVE. Dates are compared as strings, which is exact for
 * zero-padded ISO dates and avoids timezone drift entirely — no DateTime, no
 * server-locale dependency.
 */

if (!function_exists('ed_select_for')) {
    /**
     * The record in force on $date, or null.
     *
     * When several match — which validation should prevent — the LATEST
     * `effectiveFrom` wins, so the answer is deterministic rather than
     * dependent on read order. Returning null is a real answer ("nothing is
     * configured for this date"), not an error: callers must treat it as a
     * setup requirement, never as a silent zero.
     */
    function ed_select_for(array $records, string $date): ?array
    {
        $best = null;
        foreach ($records as $r) {
            $from = (string) ($r['effectiveFrom'] ?? '');
            if ($from === '' || $from > $date) continue;
            $to = $r['effectiveTo'] ?? null;
            if ($to !== null && (string) $to < $date) continue;
            if ($best === null || $from > (string) $best['effectiveFrom']) $best = $r;
        }
        return $best;
    }
}

if (!function_exists('ed_find_overlaps')) {
    /**
     * Human-readable descriptions of every overlapping pair (empty = clean).
     *
     * Ambiguity here is not cosmetic. Two records covering one date make a
     * payroll run's basis depend on read order, and a CLOSED period could
     * resolve differently on re-read — destroying the reconstructability that
     * is the entire point of dating records.
     *
     * @param string $label what the records are, for the message ("configuration", "assignment")
     */
    function ed_find_overlaps(array $records, string $label = 'record'): array
    {
        $rows = [];
        foreach ($records as $r) {
            $from = (string) ($r['effectiveFrom'] ?? '');
            if ($from === '') continue;
            $rows[] = ['from' => $from, 'to' => $r['effectiveTo'] ?? null];
        }
        usort($rows, fn($a, $b) => strcmp($a['from'], $b['from']));

        $conflicts = [];
        for ($i = 1; $i < count($rows); $i++) {
            $prev = $rows[$i - 1];
            $cur  = $rows[$i];

            if ($prev['from'] === $cur['from']) {
                $conflicts[] = "Two {$label}s share effectiveFrom {$cur['from']}.";
                continue;
            }
            // An open-ended predecessor swallows everything that follows it.
            if ($prev['to'] === null || $cur['from'] <= $prev['to']) {
                $end = $prev['to'] === null ? 'open-ended' : $prev['to'];
                $conflicts[] = "The {$label} from {$prev['from']} (to {$end}) overlaps the one starting {$cur['from']}.";
            }
        }
        return $conflicts;
    }
}

if (!function_exists('ed_is_iso_date')) {
    /** Calendar-valid ISO date — not merely regex-shaped. */
    function ed_is_iso_date(string $d): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
        [$y, $m, $day] = explode('-', $d);
        return checkdate((int) $m, (int) $day, (int) $y);
    }
}
