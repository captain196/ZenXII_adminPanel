<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Leave_year_service — the canonical Leave-Year/session-boundary resolver
 * (Workforce Policy Programme, Leave Phase 1, 2026-08-18).
 *
 * Pure, static, no I/O — same discipline as Working_calendar_service and
 * Policy_assignment_service: the caller loads whatever tenant config exists
 * and passes it in; this class invents nothing and reads nothing itself.
 *
 * WHY THIS EXISTS: `leaveApplications` balance docs were keyed by raw PHP
 * `date('Y')` — a plain calendar year, completely disconnected from
 * `schools/{id}.currentSession` (the academic `YYYY-YY` session that every
 * other ZenX subsystem — Attendance, Fees, Payroll pay-periods — treats as
 * the tenant's real operating year). This class turns a date into the
 * SESSION it belongs to, so Leave can finally share one boundary with the
 * rest of the tenant instead of running its own private calendar.
 *
 * THE APRIL-START CONVENTION IS NOT INVENTED HERE. It is already baked in,
 * as duplicated literal logic, in at least three existing call sites
 * (`Sis.php` ~8409, `Attendance.php` ~5218, `Fees.php` ~1739/2649/4597 —
 * each computes `$monthNumber >= 4 ? $startYear : $endYear` against a
 * session label). This class centralizes that SAME already-established
 * ZenX convention as a configurable default (`DEFAULT_START_MONTH = 4`),
 * rather than introducing a new assumption. A tenant whose academic year
 * genuinely starts a different month can override via `$startMonth`; a
 * tenant whose actual boundary doesn't even line up with a calendar month
 * (rare, but possible) can override a specific session's bounds entirely
 * via `$overrides`.
 *
 * `schools/{id}.sessions[]` (a bare array of label strings, e.g.
 * ["2025-26","2026-27"]) is READ-ONLY and UNTOUCHED by this whole Leave
 * Policy Programme — confirmed by tracing ~20 existing consumers
 * (School_config.php, Session_lifecycle.php, Admin_login.php, Fees.php,
 * Sis.php, Staff.php, MY_Controller.php, B2_registry_service.php), every
 * one of which does `is_array(...) ? array_values(array_filter($x,
 * 'is_string')) : []` — converting any element to a non-string would
 * silently empty every one of those lists. The new, additive,
 * NEVER-touching-that-shape field is `schools/{id}.sessionBoundaries` — an
 * OPTIONAL map, `{ [sessionLabel]: {startDate, endDate} }`, consulted only
 * as an override; its total absence (every tenant, today) changes nothing.
 */
class Leave_year_service
{
    /** April — the pre-existing, repo-wide ZenX convention (see class docblock). */
    const DEFAULT_START_MONTH = 4;

    private const SESSION_LABEL_RE = '/^(\d{4})-(\d{2})$/';
    private const DATE_RE          = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * The [startDate, endDate] (Y-m-d, inclusive) for one session label.
     * Checks $overrides first (a stored `sessionBoundaries[label]` entry,
     * if the tenant configured one); otherwise derives it purely from the
     * label + $startMonth. Returns null only for a malformed label — never
     * guesses at a shape it doesn't recognise.
     */
    public static function boundsForSession(string $sessionLabel, array $overrides = [], int $startMonth = self::DEFAULT_START_MONTH): ?array
    {
        $ov = $overrides[$sessionLabel] ?? null;
        if (is_array($ov)) {
            $s = (string) ($ov['startDate'] ?? '');
            $e = (string) ($ov['endDate'] ?? '');
            if (preg_match(self::DATE_RE, $s) && preg_match(self::DATE_RE, $e) && $e >= $s) {
                return ['startDate' => $s, 'endDate' => $e];
            }
        }

        if (!preg_match(self::SESSION_LABEL_RE, $sessionLabel, $m)) return null;
        $startYear = (int) $m[1];
        $sm = self::_normaliseMonth($startMonth);

        $start   = sprintf('%04d-%02d-01', $startYear, $sm);
        $endTs   = mktime(0, 0, 0, $sm, 1, $startYear + 1) - 86400;
        $end     = date('Y-m-d', $endTs);

        return ['startDate' => $start, 'endDate' => $end];
    }

    /**
     * Which session label does $dateISO fall into? Overrides are checked
     * first (so a custom-bounded session wins even if it doesn't line up
     * with $startMonth), then the derived convention.
     */
    public static function sessionForDate(string $dateISO, array $overrides = [], int $startMonth = self::DEFAULT_START_MONTH): ?string
    {
        if (!preg_match(self::DATE_RE, $dateISO)) return null;

        foreach ($overrides as $label => $ov) {
            if (!is_array($ov)) continue;
            $s = (string) ($ov['startDate'] ?? '');
            $e = (string) ($ov['endDate'] ?? '');
            if ($s !== '' && $e !== '' && $dateISO >= $s && $dateISO <= $e) {
                return (string) $label;
            }
        }

        $y  = (int) substr($dateISO, 0, 4);
        $mo = (int) substr($dateISO, 5, 2);
        $sm = self::_normaliseMonth($startMonth);

        $startYear = ($mo >= $sm) ? $y : $y - 1;
        $endYY     = ($startYear + 1) % 100;

        return sprintf('%04d-%02d', $startYear, $endYY);
    }

    /**
     * Split an inclusive [fromISO, toISO] range into one segment per
     * session it crosses: [{session, from, to}, ...], in chronological
     * order. A single-session request returns exactly one segment. Returns
     * [] for invalid input (never partial/guessed segments).
     *
     * Example (the canonical Phase-1 test case): 2026-03-28 .. 2026-04-04
     * with the default April start splits into:
     *   [{session:'2025-26', from:'2026-03-28', to:'2026-03-31'},
     *    {session:'2026-27', from:'2026-04-01', to:'2026-04-04'}]
     */
    public static function splitRangeBySession(string $fromISO, string $toISO, array $overrides = [], int $startMonth = self::DEFAULT_START_MONTH): array
    {
        $segments = [];
        if (!preg_match(self::DATE_RE, $fromISO) || !preg_match(self::DATE_RE, $toISO) || $toISO < $fromISO) {
            return $segments;
        }

        $cursor = $fromISO;
        // A real leave request cannot plausibly span more than a handful of
        // sessions; this bounds the loop against a malformed override
        // (e.g. two overrides pointing at the same date) looping forever.
        $guard = 0;
        while ($cursor <= $toISO && $guard < 40) {
            $guard++;
            $label = self::sessionForDate($cursor, $overrides, $startMonth);
            if ($label === null) break;
            $bounds = self::boundsForSession($label, $overrides, $startMonth);
            if ($bounds === null) break;

            $segEnd = ($bounds['endDate'] < $toISO) ? $bounds['endDate'] : $toISO;
            $segments[] = ['session' => $label, 'from' => $cursor, 'to' => $segEnd];

            if ($segEnd >= $toISO) break;
            $next = new \DateTime($segEnd);
            $next->modify('+1 day');
            $cursor = $next->format('Y-m-d');
        }

        return $segments;
    }

    /**
     * Inclusive day-count of a session's own window (for pro-rata's
     * denominator in a later phase) — total calendar days from startDate
     * to endDate, leap-year-correct by construction (real dates, not a
     * fixed 365/366 constant).
     */
    public static function totalDaysInSession(string $sessionLabel, array $overrides = [], int $startMonth = self::DEFAULT_START_MONTH): ?int
    {
        $bounds = self::boundsForSession($sessionLabel, $overrides, $startMonth);
        if ($bounds === null) return null;
        $start = new \DateTime($bounds['startDate']);
        $end   = new \DateTime($bounds['endDate']);
        return (int) $start->diff($end)->days + 1;
    }

    private static function _normaliseMonth(int $m): int
    {
        return ($m >= 1 && $m <= 12) ? $m : self::DEFAULT_START_MONTH;
    }

    /**
     * Leave Policy Programme Phase 4 (2026-08-18) — pro-rate an annual
     * entitlement for a staff member whose employment window doesn't cover
     * the full Leave Year. Deliberately separate from any Payroll
     * proration — Leave entitlement and salary proration answer different
     * questions and must not share one function even though the shape
     * looks similar.
     *
     * Shared by Hr.php (display/derivation) AND Staff.php (initial balance
     * seeding at staff creation — the choke point that actually gates what
     * an employee can apply for) so the two never compute two different
     * answers to "how much leave did this person earn this year".
     *
     * $joinDate/$exitDate are expected pre-normalized to Y-m-d (or a
     * Y-m-d-prefixed string); anything else is treated as absent (fails
     * open to the un-prorated full entitlement) rather than guessed at or
     * thrown on — callers with richer date-format handling (Hr::
     * _normalize_date()) should normalize BEFORE calling this.
     *
     * The boundary is the plain calendar year matching $year (Jan 1 - Dec
     * 31) — i.e. whatever boundary the balance doc is ACTUALLY keyed by
     * today. See this file's own docblock: the session-based boundary
     * exists (boundsForSession()) but Phase 1's balance-doc key cutover is
     * deliberately not yet flipped, so pro-rata matches what's live now,
     * not a not-yet-cutover concept.
     *
     * @return array{days: float, prorated: bool, windowDays: int, totalDays: int}
     */
    public static function prorateEntitlement(float $fullDaysPerYear, ?string $joinDate, ?string $exitDate, string $year): array
    {
        $yearStart = "{$year}-01-01";
        $yearEnd   = "{$year}-12-31";
        $totalDays = (int) ((new \DateTime($yearEnd))->diff(new \DateTime($yearStart)))->days + 1;

        $jd = (is_string($joinDate) && preg_match('/^\d{4}-\d{2}-\d{2}/', $joinDate)) ? substr($joinDate, 0, 10) : null;
        $xd = (is_string($exitDate) && preg_match('/^\d{4}-\d{2}-\d{2}/', $exitDate)) ? substr($exitDate, 0, 10) : null;

        $winStart = ($jd !== null && $jd > $yearStart) ? $jd : $yearStart;
        $winEnd   = ($xd !== null && $xd < $yearEnd)   ? $xd : $yearEnd;

        if ($winStart > $winEnd) {
            // Joined after the year ended, or exited before it began — no
            // overlap at all with this Leave Year.
            return ['days' => 0.0, 'prorated' => true, 'windowDays' => 0, 'totalDays' => $totalDays];
        }

        $windowDays = (int) ((new \DateTime($winEnd))->diff(new \DateTime($winStart)))->days + 1;
        $isFullYear = ($winStart === $yearStart && $winEnd === $yearEnd);
        if ($isFullYear) {
            return ['days' => $fullDaysPerYear, 'prorated' => false, 'windowDays' => $windowDays, 'totalDays' => $totalDays];
        }

        $ratio = $windowDays / $totalDays;
        $days  = round($fullDaysPerYear * $ratio * 2) / 2; // nearest 0.5 — existing balance granularity
        return ['days' => $days, 'prorated' => true, 'windowDays' => $windowDays, 'totalDays' => $totalDays];
    }
}
