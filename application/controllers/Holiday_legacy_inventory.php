<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Holiday_legacy_inventory — READ-ONLY legacy holiday inventory (migration aid).
 *
 * PURPOSE (informational only)
 * ----------------------------
 * Lists holidays that exist in LEGACY stores OUTSIDE the canonical Firestore
 * source (`calendarEvents`), so operators can REVIEW them and, if desired,
 * re-create them in the Academic Calendar before the legacy stores are removed.
 *
 * This tool exists ONLY to inform. It is NOT a reconciliation, NOT a migration,
 * NOT a backfill, and NOT part of the runtime architecture.
 *
 * STRICT GUARANTEES
 *   • Never modifies data.        • Never synchronizes data.
 *   • Never backfills data.       • Never bridges/mirrors/dual-writes.
 *   • Read-only: zero set/update/push/delete of any kind (verified).
 *
 * ⚠️ TEMPORARY MIGRATION ARTIFACT — DELETE THIS FILE (and its route) once the
 *    HC holiday-convergence migration is complete. RTDB is read here ONLY to
 *    surface legacy debt for removal; RTDB is never a source of truth and is
 *    never reconciled into Firestore.
 *
 * Canonical source = calendarEvents (type=holiday), authored via Academic
 * Calendar → Calendar_service, read via Holiday_service. Legacy stores below
 * are scheduled for removal:
 *   L1 RTDB  Schools/{name}/Config/Attendance/holidays
 *   L2 RTDB  Schools/{name}/Events/Holidays/{year}
 *   L3 FS    schools/{id}.holidays                  (orphan; no writer)
 *   L4 FS    schools/{id}.attendanceConfig.holidays (orphan; no writer)
 *
 * USAGE (admin session; browser):
 *   /holiday_legacy_inventory?year=2026
 *   /holiday_legacy_inventory?school_id=SCH_XXXX&year=2026
 * Output: JSON inventory of legacy holidays + whether each already exists in
 * the canonical source. No data is changed.
 */
class Holiday_legacy_inventory extends MY_Controller
{
    private const ALLOWED_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal'];

    public function index()
    {
        if (!in_array((string) $this->admin_role, self::ALLOWED_ROLES, true)) {
            return $this->_json(['status' => 'error', 'message' => 'Access denied.'], 403);
        }

        $schoolId   = trim((string) ($this->input->get('school_id') ?: $this->school_id));
        $schoolName = $schoolId; // canonical: name == id
        $year       = (int) ($this->input->get('year') ?: date('Y'));
        if ($schoolId === '') {
            return $this->_json(['status' => 'error', 'message' => 'No school context.'], 400);
        }

        // Legacy stores (READ-ONLY; for review/removal — NOT a migration source)
        $legacy = [
            'L1_rtdb_config'      => $this->_read_l1_rtdb_config($schoolName),
            'L2_rtdb_events'      => $this->_read_l2_rtdb_events($schoolName, $year),
            'L3_fs_schoolHolidays'=> $this->_read_l3_school_holidays($schoolId),
            'L4_fs_attConfig'     => $this->_read_l4_attendance_config($schoolId),
        ];

        // Canonical source (for "already present?" flag only — informational)
        $canonical = $this->_read_canonical($schoolId); // [dateISO => name]

        // Build a flat, review-friendly list of legacy entries.
        $entries = [];               // [{date, name, legacySources[], presentInCanonical}]
        $absentFromCanonical = [];    // legacy dates NOT in canonical (operator review list)
        $seen = [];

        foreach ($legacy as $storeKey => $map) {
            foreach ($map as $date => $name) {
                $key = $date;
                if (!isset($seen[$key])) {
                    $seen[$key] = ['date' => $date, 'name' => $name, 'legacySources' => [], 'presentInCanonical' => isset($canonical[$date])];
                }
                $seen[$key]['legacySources'][] = $storeKey;
            }
        }
        ksort($seen);
        foreach ($seen as $row) {
            $entries[] = $row;
            if (!$row['presentInCanonical']) $absentFromCanonical[] = $row['date'];
        }

        return $this->_json([
            'status'      => 'success',
            'tool'        => 'Holiday Legacy Inventory (READ-ONLY, informational, temporary)',
            'disclaimer'  => 'No data modified / synchronized / backfilled. Delete this tool after migration.',
            'school_id'   => $schoolId,
            'session'     => $this->session_year,
            'year'        => $year,
            'generatedAt' => date('c'),
            'counts'      => [
                'L1_rtdb_config'       => count($legacy['L1_rtdb_config']),
                'L2_rtdb_events'       => count($legacy['L2_rtdb_events']),
                'L3_fs_schoolHolidays' => count($legacy['L3_fs_schoolHolidays']),
                'L4_fs_attConfig'      => count($legacy['L4_fs_attConfig']),
                'canonical_calendarEvents' => count($canonical),
                'legacy_distinct_dates'    => count($entries),
            ],
            // The operator-review list: legacy holidays NOT yet in the canonical
            // Academic Calendar. Operators may choose to add these to the calendar
            // (manually, via the canonical UI) before legacy stores are removed.
            'legacy_dates_absent_from_canonical' => $absentFromCanonical,
            'legacy_entries' => $entries,
        ], 200);
    }

    /* ── legacy readers (READ-ONLY) ─────────────────────────────── */

    private function _read_l1_rtdb_config(string $schoolName): array
    {
        $out = [];
        try {
            $raw = $this->firebase->get("Schools/{$schoolName}/Config/Attendance/holidays");
            if (is_array($raw)) foreach ($raw as $d => $n) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d)) $out[(string) $d] = (string) $n;
            }
        } catch (\Throwable $e) { /* read-only */ }
        return $out;
    }

    private function _read_l2_rtdb_events(string $schoolName, int $year): array
    {
        $out = [];
        try {
            $raw = $this->firebase->get("Schools/{$schoolName}/Events/Holidays/{$year}");
            if (is_array($raw)) foreach ($raw as $item) {
                $d = is_array($item) ? (string) ($item['date'] ?? '') : (is_string($item) ? $item : '');
                $n = is_array($item) ? (string) ($item['name'] ?? 'Holiday') : 'Holiday';
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $out[$d] = $n;
            }
        } catch (\Throwable $e) { /* read-only */ }
        return $out;
    }

    private function _read_l3_school_holidays(string $schoolId): array
    {
        $out = [];
        try {
            $doc = $this->fs->get('schools', $schoolId);
            $arr = is_array($doc) ? ($doc['holidays'] ?? []) : [];
            if (is_array($arr)) foreach ($arr as $h) {
                $d = is_string($h) ? $h : (is_array($h) ? (string) ($h['date'] ?? '') : '');
                $n = is_array($h) ? (string) ($h['name'] ?? 'Holiday') : 'Holiday';
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $out[$d] = $n;
            }
        } catch (\Throwable $e) { /* read-only */ }
        return $out;
    }

    private function _read_l4_attendance_config(string $schoolId): array
    {
        $out = [];
        try {
            $doc = $this->fs->get('schools', $schoolId);
            $map = (is_array($doc) && is_array($doc['attendanceConfig'] ?? null))
                ? ($doc['attendanceConfig']['holidays'] ?? []) : [];
            if (is_array($map)) foreach ($map as $d => $n) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d)) $out[(string) $d] = (string) $n;
            }
        } catch (\Throwable $e) { /* read-only */ }
        return $out;
    }

    /** Canonical calendarEvents (type=holiday) via Holiday_service — informational only. */
    private function _read_canonical(string $schoolId): array
    {
        try {
            $this->load->library('holiday_service');
            $this->holiday_service->init($this->fs, $schoolId, $this->session_year, 0);
            $r = $this->holiday_service->all_holiday_dates();
            return is_array($r) ? $r : [];
        } catch (\Throwable $e) { return []; }
    }

    private function _json(array $data, int $code)
    {
        $this->output
            ->set_status_header($code)
            ->set_content_type('application/json')
            ->set_output(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
