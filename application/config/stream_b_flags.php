<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Stream B (Staff Attendance) — Phase II feature flags.
 *
 * SCOPE: Stream B writer convergence — Firestore-only Staff_attendance_writer
 * with lock cache + CAS protection on staffAttendanceSummary.
 *
 * Flag semantics: mutually exclusive flag dispatch at controller entry.
 *   - false (default): legacy mark_staff_day RTDB-heavy path runs unchanged
 *   - true: new Firestore-only writer (with CAS) runs; legacy path bypassed entirely
 *
 * Architectural lock: the new path NEVER writes to RTDB. The legacy path
 * NEVER writes to the new Firestore canonical store. Both paths cannot run
 * for the same call — the dispatcher is a single if/else at the entry point.
 *
 * Loaded via $this->config->load('stream_b_flags', true) and read with
 * $this->config->item('stream_b_writer_fs_only', 'stream_b_flags').
 *
 * Per-tenant override: enabled_for_schools[] supports staged rollout.
 * When stream_b_writer_fs_only=false AND enabled_for_schools is non-empty,
 * the new writer activates ONLY for listed school_ids. This is the safest
 * rollout pattern: enable on 1 pilot tenant before fleet-wide flip.
 *
 * Phase II ships with both default OFF (no behavior change in any tenant
 * until operator-authorized activation).
 */

$config['stream_b_writer_fs_only'] = false; // Phase II ACTIVATION GATE — default OFF
$config['enabled_for_schools']      = [];   // Per-tenant allowlist; empty = global flag governs

/**
 * Helper: is the new writer enabled for a given tenant?
 *
 *   - Returns true if global flag is true (fleet-wide enable)
 *   - Returns true if tenant is in enabled_for_schools allowlist
 *   - Returns false otherwise (legacy path runs)
 *
 * Phase II usage (controller):
 *   $this->config->load('stream_b_flags', true);
 *   if (stream_b_writer_enabled($this->school_id, $this->config)) {
 *       // new path
 *   } else {
 *       // legacy path
 *   }
 */
if (!function_exists('stream_b_writer_enabled')) {
    function stream_b_writer_enabled(string $schoolId, $configObj): bool
    {
        $global = (bool) $configObj->item('stream_b_writer_fs_only', 'stream_b_flags');
        if ($global) return true;
        $allowlist = $configObj->item('enabled_for_schools', 'stream_b_flags');
        if (is_array($allowlist) && in_array($schoolId, $allowlist, true)) return true;
        return false;
    }
}
