<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Recruitment terminology bridge (ZenX HRMS W1.3).
 *
 * WHY THIS EXISTS
 * ---------------
 * Recruitment is written by TWO surfaces that use DIFFERENT words for the same
 * fact:
 *
 *   Ats.php  thinks in `stage`  : Applied → Shortlisted → Interviewed → Selected → Hired
 *   Hr.php   thinks in `status` : Applied, Shortlisted, Interview, Selected, Rejected, Joined
 *
 * Both now write the SAME Firestore document (`hrApplicants/{schoolId}_{APPnnnn}`),
 * so the two vocabularies must round-trip exactly. Before W1.3 each module kept
 * its own private copy of the mapping — Ats.php had `HR_STATUS_MAP` plus a
 * separate, NON-INVERSE `_normalize_stage()` table — and they could silently
 * drift apart. This helper is the single source of truth for that mapping so a
 * change can only be made in one place.
 *
 * Note the asymmetry, which is intentional and load-bearing:
 *   - status→stage is MANY-TO-ONE ('Interview' and 'Interviewed' both mean
 *     Interviewed; 'Joined' and 'Hired' both mean Hired), because it must read
 *     documents written by either module across their whole history.
 *   - stage→status is ONE-TO-ONE, because it is a writer.
 *
 * 'Rejected' has no stage of its own — rejection is carried by the orthogonal
 * `ats_status` field, so a rejected candidate still remembers the stage they
 * reached. status→stage therefore maps it to the 'Applied' floor, and callers
 * MUST consult `ats_status` before trusting the stage of a rejected applicant.
 */

if (!function_exists('recruitment_stages')) {
    /** The ordered ATS pipeline. Index order defines "forward". */
    function recruitment_stages(): array
    {
        return ['Applied', 'Shortlisted', 'Interviewed', 'Selected', 'Hired'];
    }
}

if (!function_exists('recruitment_statuses')) {
    /** The HR applicant-status vocabulary (Hr::update_applicant_status validates against this). */
    function recruitment_statuses(): array
    {
        return ['Applied', 'Shortlisted', 'Interview', 'Selected', 'Rejected', 'Joined'];
    }
}

if (!function_exists('recruitment_stage_from_status')) {
    /**
     * READER. Normalise any historical status/stage value to a canonical ATS stage.
     * Unknown values floor to 'Applied' — never throws, because this runs against
     * documents written by older code paths.
     */
    function recruitment_stage_from_status(string $value): string
    {
        static $map = [
            // canonical ATS stages (idempotent)
            'Applied'     => 'Applied',
            'Shortlisted' => 'Shortlisted',
            'Interviewed' => 'Interviewed',
            'Selected'    => 'Selected',
            'Hired'       => 'Hired',
            // HR status vocabulary
            'Interview'   => 'Interviewed',
            'Joined'      => 'Hired',
            // 'Rejected' deliberately absent — see file docblock.
        ];
        return $map[$value] ?? 'Applied';
    }
}

if (!function_exists('recruitment_status_from_stage')) {
    /** WRITER. Project a canonical ATS stage onto the HR status vocabulary. */
    function recruitment_status_from_stage(string $stage): string
    {
        static $map = [
            'Applied'     => 'Applied',
            'Shortlisted' => 'Shortlisted',
            'Interviewed' => 'Interview',
            'Selected'    => 'Selected',
            'Hired'       => 'Joined',
        ];
        return $map[$stage] ?? $stage;
    }
}

if (!function_exists('recruitment_ats_status_from_status')) {
    /**
     * WRITER. Derive the orthogonal ATS lifecycle flag from an HR status.
     *
     * Without this, an HR-side status change to 'Rejected' or 'Joined' would
     * leave `ats_status` stale at 'active', and the ATS board would keep showing
     * a rejected candidate in their old column. Returns null when the status
     * carries no lifecycle meaning, so callers can leave `ats_status` untouched
     * rather than clobbering a value ATS owns.
     */
    function recruitment_ats_status_from_status(string $status): ?string
    {
        switch ($status) {
            case 'Rejected': return 'rejected';
            case 'Joined':   return 'hired';
            case 'Applied':
            case 'Shortlisted':
            case 'Interview':
            case 'Selected':
                return 'active';
            default:
                return null;
        }
    }
}
