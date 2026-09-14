<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * WHETHER THE SESSION ROLLOVER MAY WRITE OVER A DESTINATION DOCUMENT.
 *
 * The rollover tool carries a school's timetable into the new session with a
 * full-document write. That is correct for a document the rollover itself
 * created, and wrong for two other kinds:
 *
 *   • a document the PROJECTION authored — canonical-derived state, the
 *     materialised form of a published timetable version. Carrying last year's
 *     copy over it replaces this year's published timetable, provenance and
 *     all, on every teacher and parent screen. `timetables` is a projection of
 *     the canonical authority; the rollover is not an authority over it.
 *
 *   • a document we could not READ. The destination read decides whether the
 *     rollover has already run, so a failed read used to mean "no marker,
 *     therefore not yet rolled, therefore write" — turning a network blip into
 *     the same overwrite. Not knowing is not permission.
 *
 * The decision is separated from the script so it can be executed by a test.
 * The tool is a procedural CLI with a live Firestore client in the middle of
 * it; a rule that important should not be provable only by reading the source.
 */
class Rollover_projection_guard
{
    /** Write the carried document. */
    const WRITE = 'write';

    /** The destination could not be read — we do not know what is there. */
    const SKIP_UNREADABLE = 'unreadable';

    /** The destination is canonical-derived: the projection wrote it. */
    const SKIP_PROJECTED = 'projected';

    /** This rollover revision has already carried this document. */
    const SKIP_ALREADY_ROLLED = 'already_rolled';

    /**
     * @param array|null $existing    the destination document, or null when absent
     * @param int        $currentRev  PHASE3_ROLLOVER_REV
     * @param bool       $readFailed  true when the destination read threw
     */
    public static function decide($existing, int $currentRev, bool $readFailed = false): string
    {
        // Order matters. An unreadable destination is decided first because
        // every test below it reads a field we do not have.
        if ($readFailed) {
            return self::SKIP_UNREADABLE;
        }

        if (!is_array($existing)) {
            return self::WRITE;                 // nothing there — the ordinary case
        }

        // Canonical-derived state outranks a carried copy, whatever else the
        // document says. Checked BEFORE the rollover marker so a document that
        // was carried once and has since been projected over is still protected.
        if (trim((string) ($existing['projectedFromVersion'] ?? '')) !== '') {
            return self::SKIP_PROJECTED;
        }

        if ((int) ($existing['phase3RolloverRev'] ?? 0) >= $currentRev) {
            return self::SKIP_ALREADY_ROLLED;
        }

        return self::WRITE;
    }

    /** One line for the operator, naming what was left alone and why. */
    public static function explain(string $decision, string $docId): string
    {
        switch ($decision) {
            case self::SKIP_PROJECTED:
                return "skip (projected from a published version — canonical "
                     . "authority, not this tool's to overwrite): {$docId}";
            case self::SKIP_UNREADABLE:
                return "skip (destination could not be read; not writing over "
                     . "what we cannot see): {$docId}";
            case self::SKIP_ALREADY_ROLLED:
                return "skip (already rolled at this revision): {$docId}";
            default:
                return "write: {$docId}";
        }
    }
}
