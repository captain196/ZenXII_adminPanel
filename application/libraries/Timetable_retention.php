<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * TIMETABLE RETENTION — what may be removed, what may never be, and why.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────
 *
 * Timetable resolution scans versions up to a fixed ceiling and, past it,
 * correctly refuses to resolve ANY timetable rather than answer from a
 * truncated set. Generation already refuses to cross that line. But a school
 * that reaches it by other means is stuck: no timetable resolves, and the only
 * way out is discarding proposals one at a time.
 *
 * This answers the question that has to be answered before anything is removed:
 * which versions is it SAFE to remove, and which must never be touched?
 *
 * ── WHAT THIS DELIBERATELY DOES NOT DECIDE ──────────────────────────────
 *
 * It decides nothing about WHEN. How long a school keeps unused proposals, and
 * whether anything should be removed automatically at all, is a business
 * question with no answer in the architecture — so nothing here runs on a
 * timer and nothing here deletes. It classifies; an operator decides; the store
 * re-verifies before acting.
 *
 * Every protection below is derived from something the codebase already
 * enforces or depends on, not from an opinion about tidiness.
 */
class Timetable_retention
{
    /** Cannot be removed, whatever an operator asks for. */
    const PROTECTED = 'protected';

    /** Removable by an operator who chooses to. Never removed automatically. */
    const RECLAIMABLE = 'reclaimable';

    /**
     * Why a version is protected. Each reason names the thing that would break.
     */
    const R_PUBLISHED  = 'published';
    const R_SUPERSEDED = 'superseded';
    const R_IN_FORCE   = 'in_force';
    const R_FUTURE     = 'future';
    const R_GENERATING = 'generating';

    /** Why a version may be reclaimed. */
    const R_UNUSED = 'unused';
    const R_FAILED = 'failed';

    /**
     * Classify every version.
     *
     * ── WHY THERE IS NO "REFERENCED BY THE PROJECTION" RULE ─────────────
     *
     * A compatibility document names the version it was projected from, and an
     * obvious protection would be "a version teachers are reading a copy of
     * cannot be removed". It is deliberately absent.
     *
     * Consulting the legacy collection to decide canonical retention is the
     * forbidden direction — canonical behaviour would depend on derived data —
     * and the boundary tests that exist to prevent exactly that caught the
     * first attempt at it.
     *
     * It would also add nothing. Only a PUBLISHED version is ever projected,
     * because projectPublishedVersion() is the sole writer of that collection
     * and refuses anything else, and every published version is already
     * protected by its status. A projection reference can never protect a
     * version that status does not already protect.
     *
     * @param array  $versions version documents
     * @param string $today    ISO date, for deciding what is in force
     * @return array rows: versionId, status, class, reason, why
     */
    public static function classify($versions, string $today = ''): array
    {
        if (!is_array($versions)) { return []; }
        if ($today === '') { $today = date('Y-m-d'); }

        $out = [];
        foreach ($versions as $v) {
            if (!is_array($v)) { continue; }
            $id     = (string) ($v['versionId'] ?? '');
            if ($id === '') { continue; }
            $status = (string) ($v['status'] ?? '');
            $gen    = is_array($v['generation'] ?? null) ? $v['generation'] : [];
            $state  = (string) ($gen['state'] ?? '');
            $from   = (string) ($v['effectiveFrom'] ?? '');
            $to     = (string) ($v['effectiveTo'] ?? '');

            $row = ['versionId' => $id, 'status' => $status];

            // ── PUBLISHED: the record of what was in force ────────────────
            // discardVersion already refuses these. Attendance and substitution
            // are audited against them: "what was the timetable on the 12th"
            // must stay answerable, so no amount of pressure on capacity makes
            // removing one correct.
            if ($status === Timetable_plan_service::STATUS_PUBLISHED) {
                $inForce = ($from !== '' && $from <= $today)
                        && ($to === '' || $to >= $today);
                $future  = ($from !== '' && $from > $today);

                $reason  = $inForce ? self::R_IN_FORCE
                         : ($future ? self::R_FUTURE : self::R_PUBLISHED);

                $out[] = $row + [
                    'class'   => self::PROTECTED,
                    'reason'  => $reason,
                    'why' => $inForce
                        ? 'This is the timetable in force today.'
                        : ($future
                            ? 'This timetable has not started yet.'
                            : 'This timetable was in force in the past, and attendance is '
                              . 'checked against it.'),
                ];
                continue;
            }

            if ($status === Timetable_plan_service::STATUS_SUPERSEDED) {
                $out[] = $row + [
                    'class'   => self::PROTECTED,
                    'reason'  => self::R_SUPERSEDED,
                    'why' => 'This timetable was published once, so it is part of the record.',
                ];
                continue;
            }


            // ── STILL BEING WRITTEN ───────────────────────────────────────
            // Removing a version mid-run races the writer: days written after
            // the delete would have no version to belong to.
            if ($state === Timetable_plan_service::GEN_RUNNING) {
                // A run that never finished stays `running` for ever, and this
                // protection would then keep it in the version count for ever
                // too. The AGE is reported so a person can judge "this has been
                // building since Tuesday" — the code cannot, because generation
                // is bounded by execution time and this work is almost all I/O,
                // so wall-clock age proves nothing. Giving up on it is an
                // explicit action; it is never reclaimed automatically.
                $hours = self::_hoursSince((string) ($gen['startedAt'] ?? ''));
                $out[] = $row + [
                    'class'      => self::PROTECTED,
                    'reason'     => self::R_GENERATING,
                    'buildHours' => $hours,
                    'why' => $hours === null
                        ? 'This timetable is still being built.'
                        : ($hours < 1
                            ? 'This timetable is still being built.'
                            : 'This timetable has been building for ' . $hours
                              . ' hour(s). If that cannot be right, mark it as not '
                              . 'finished and it can then be removed.'),
                ];
                continue;
            }

            // ── RECLAIMABLE ───────────────────────────────────────────────
            $failed = ($state === Timetable_plan_service::GEN_FAILED);
            $out[] = $row + [
                'class'   => self::RECLAIMABLE,
                'reason'  => $failed ? self::R_FAILED : self::R_UNUSED,
                'why' => $failed
                    ? 'This one did not finish building, so it can never be checked or published.'
                    : 'A proposal that was never published.',
            ];
        }

        return $out;
    }

    /**
     * How much room is left, and how much could be recovered.
     *
     * `atRisk` is true when a full generation would no longer fit. That is not
     * a chosen threshold: it is exactly the point at which the largest request
     * the school can make stops being possible.
     */
    public static function capacity(array $rows, int $limit, int $batchMax): array
    {
        $reclaimable = 0; $protected = 0; $failed = 0;
        foreach ($rows as $r) {
            if (($r['class'] ?? '') === self::RECLAIMABLE) {
                $reclaimable++;
                if (($r['reason'] ?? '') === self::R_FAILED) { $failed++; }
            } else {
                $protected++;
            }
        }

        $used     = count($rows);
        $headroom = max(0, $limit - $used);

        return [
            'used'          => $used,
            'limit'         => $limit,
            'headroom'      => $headroom,
            'protected'     => $protected,
            'reclaimable'   => $reclaimable,
            'failed'        => $failed,
            'batchMax'      => $batchMax,
            'atRisk'        => ($used + $batchMax) > $limit,
            // What headroom would become if every reclaimable version went.
            'headroomAfter' => max(0, $limit - $protected),
        ];
    }

    /** Whole hours since an ISO timestamp, or null if it cannot be read. */
    private static function _hoursSince(string $iso): ?int
    {
        if ($iso === '') { return null; }
        $t = strtotime($iso);
        if ($t === false) { return null; }
        return (int) floor(max(0, time() - $t) / 3600);
    }

    /**
     * Day documents whose version no longer exists.
     *
     * Reported, never removed. Discarding deletes days before the version and
     * refuses if any day delete fails, so an orphan should be impossible — which
     * is exactly why finding one matters: it means something removed a version
     * by a route that did not go through discardVersion.
     *
     * @param array $dayVersionIds versionId of every day document seen
     * @param array $versionIds    versionId of every version that exists
     */
    public static function orphanDays(array $dayVersionIds, array $versionIds): array
    {
        $known = array_flip(array_map('strval', $versionIds));
        $out   = [];
        foreach ($dayVersionIds as $vid) {
            $vid = (string) $vid;
            if ($vid === '' || isset($known[$vid])) { continue; }
            $out[$vid] = ($out[$vid] ?? 0) + 1;
        }
        return $out;
    }
}
