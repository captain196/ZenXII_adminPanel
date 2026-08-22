<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_f14_residue — enumerate and (only with --commit) remove the test
 * artifacts left behind by the F14/F8 probe runs.
 *
 * SAFETY, in order of strictness:
 *   1. CLI only.
 *   2. HARD tenant guard: refuses unless SCHOOL_ID is exactly the test tenant.
 *      No production tenant can be reached even by accident.
 *   3. Only documents whose own ids/fields carry a PROBE_ marker are ever
 *      considered. Anything unmarked is REPORTED and left alone — an unexplained
 *      document is not mine to delete.
 *   4. Dry-run by default; deletion requires an explicit --commit, matching the
 *      convention the repo's scripts/ use.
 *
 * This is a cleanup tool, not a fix: no product logic is touched, and nothing
 * here changes how attendance ids are written.
 *
 *   SCHOOL_ID=SCH_202512 SESSION_YEAR=2026-27 \
 *     php index.php transport_f14_residue run [--commit]
 */
class Transport_f14_residue extends CI_Controller
{
    /** The ONLY tenant this tool may ever touch. */
    private const ALLOWED_TENANT = 'SCH_202512';

    /** A document is deletable only if its id/fields contain one of these. */
    private const PROBE_MARKERS = ['PROBE_F8', 'PROBE_F14', 'PROBE_F132', 'PROBE_F7'];

    public $fs = null;

    public function __construct()
    {
        parent::__construct();
        if (!is_cli()) { show_404(); }
    }

    private function marked(string $haystack): string
    {
        foreach (self::PROBE_MARKERS as $m) {
            if (strpos($haystack, $m) !== false) { return $m; }
        }
        return '';
    }

    private function flat($row): array
    {
        if (!is_array($row)) { return []; }
        if (isset($row['data']) && is_array($row['data'])) {
            $d = $row['data'];
            if (!empty($row['id']) && !isset($d['id'])) { $d['id'] = $row['id']; }
            return $d;
        }
        return $row;
    }

    /**
     * Diagnostic for CH-TRN-F2-2. Read-only.
     *
     * Shows each stop TWICE: as the gate reads it (raw row) and as it actually
     * is (unwrapped). schoolWhere() can return ['id'=>…,'data'=>…] envelopes;
     * reading $row['route_id'] on one of those yields null, which the gate then
     * reports as "(no route_id)" with stopId '?'.
     */
    public function stops(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n"); exit(1);
        }
        $this->load->library('firestore_service', null, 'fs');
        $this->fs->init($sid, $sess);

        $stops = (array) $this->fs->schoolWhere('transportStops', [], null, 'ASC', 200);
        echo "transportStops in {$sid}: " . count($stops) . "\n";
        echo str_repeat('-', 100) . "\n";
        $wrapped = 0; $rawBlind = 0; $trueOrphan = 0;
        $routeCache = [];
        foreach ($stops as $s) {
            $isWrapped = is_array($s) && isset($s['data']) && is_array($s['data']);
            if ($isWrapped) { $wrapped++; }
            // As the GATE sees it (no unwrap):
            $gateRid  = is_array($s) ? (string) ($s['route_id'] ?? '') : '';
            $gateStop = is_array($s) ? (string) ($s['stopId'] ?? '?') : '?';
            // As it ACTUALLY is:
            $d       = $this->flat($s);
            $realRid = (string) ($d['route_id'] ?? '');
            $realStp = (string) ($d['stopId'] ?? $d['id'] ?? '');
            if ($gateRid === '') { $rawBlind++; }

            $routeOk = false;
            if ($realRid !== '') {
                if (!isset($routeCache[$realRid])) {
                    $routeCache[$realRid] = is_array($this->fs->getEntity('routes', $realRid));
                }
                $routeOk = $routeCache[$realRid];
            }
            if ($realRid === '' || !$routeOk) { $trueOrphan++; }

            printf("wrapped=%-3s | GATE sees: stopId=%-6s route_id=%-8s | ACTUAL: stopId=%-28s route_id=%-28s routeExists=%s\n",
                $isWrapped ? 'YES' : 'no', $gateStop, $gateRid === '' ? '(none)' : $gateRid,
                $realStp !== '' ? $realStp : '(none)', $realRid !== '' ? $realRid : '(none)',
                $realRid === '' ? 'n/a' : ($routeOk ? 'YES' : 'NO'));
        }
        echo str_repeat('-', 100) . "\n";
        echo "envelope-wrapped rows            : {$wrapped}\n";
        echo "rows the GATE reads as blank     : {$rawBlind}   <-- what CH-TRN-F2-2 counts as orphans\n";
        echo "GENUINELY orphaned after unwrap  : {$trueOrphan}\n";
    }

    public function run(): void
    {
        $sid    = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess   = (string) (getenv('SESSION_YEAR') ?: '');
        $commit = in_array('--commit', (array) ($this->uri->rsegments ?? []), true)
               || in_array('--commit', (array) $_SERVER['argv'], true);

        if ($sid === '' || $sess === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n"); exit(1);
        }
        // Hard guard — the whole point of this tool.
        if ($sid !== self::ALLOWED_TENANT) {
            fwrite(STDERR, "REFUSED: this tool only operates on the test tenant "
                . self::ALLOWED_TENANT . " (got {$sid}). No production tenant may be touched.\n");
            exit(1);
        }

        $this->load->library('firestore_service', null, 'fs');
        $this->fs->init($sid, $sess);

        echo "==================================================\n";
        echo " F14/F8 probe residue — tenant={$sid}\n";
        echo ' mode=' . ($commit ? 'COMMIT (will delete)' : 'DRY RUN (nothing deleted)') . "\n";
        echo "==================================================\n";

        $del = [];   // [collection, entityIdForRemoveEntity, storedId, why]
        $keep = [];

        // ── 1. Orphan attendance events ──────────────────────────────────
        // Stored ids carry a DOUBLED school prefix (deliberate, see the warning
        // at Transport_service::_writeBoardingEvent). removeEntity() adds one
        // prefix, so the entity id handed to it must retain exactly one.
        echo "\n[1] transportAttendance\n";
        $rows = (array) $this->fs->schoolWhere('transportAttendance', [], null, 'ASC', 2000);
        $tripCache = [];
        foreach ($rows as $row) {
            $d      = $this->flat($row);
            $stored = (string) ($d['id'] ?? '');
            $tripId = (string) ($d['tripId'] ?? '');
            $marker = $this->marked($stored . '|' . (string) ($d['studentId'] ?? ''));
            if ($marker === '') { continue; }

            // Confirm it is genuinely orphaned — its trip no longer exists.
            if (!array_key_exists($tripId, $tripCache)) {
                $tripCache[$tripId] = is_array($this->fs->getEntity('tripLogs', $tripId));
            }
            $tripExists = $tripCache[$tripId];

            // Strip ONE school prefix so removeEntity re-adds it.
            $entityId = $stored;   // the id FIELD is already the entity id (single prefix); removeEntity adds the second

            if ($tripExists) {
                $keep[] = ['transportAttendance', $stored,
                    "{$marker} but its trip {$tripId} still exists — live fixture, left alone"];
                continue;
            }
            $del[] = ['transportAttendance', $entityId, $stored,
                "{$marker} probe artifact; trip {$tripId} no longer exists (orphan)"];
        }

        // ── 2. Orphan stops (CH-TRN-F2-2) ────────────────────────────────
        echo "[2] transportStops with no route_id\n";
        $stops = (array) $this->fs->schoolWhere('transportStops', [], null, 'ASC', 2000);
        foreach ($stops as $row) {
            $d       = $this->flat($row);
            $stored  = (string) ($d['id'] ?? $d['stopId'] ?? '');
            $routeId = trim((string) ($d['route_id'] ?? ''));
            if ($routeId !== '') { continue; }
            $marker = $this->marked($stored . '|' . (string) ($d['name'] ?? ''));
            if ($marker === '') {
                // NOT mine. Report loudly and never delete — an unexplained
                // orphan may predate this work entirely.
                $keep[] = ['transportStops', $stored,
                    'no route_id but NO probe marker — NOT deleted, needs human review'];
                continue;
            }
            $entityId = $stored;   // the id FIELD is already the entity id (single prefix); removeEntity adds the second
            $del[] = ['transportStops', $entityId, $stored, "{$marker} probe artifact with no route_id"];
        }

        // ── Report ───────────────────────────────────────────────────────
        echo "\n--- CANDIDATES FOR DELETION (" . count($del) . ") ---\n";
        foreach ($del as [$col, $entityId, $stored, $why]) {
            echo "  {$col}\n    stored id : {$stored}\n    entity id : {$entityId}\n    reason    : {$why}\n";
        }
        if ($del === []) { echo "  (none)\n"; }

        echo "\n--- LEFT ALONE (" . count($keep) . ") ---\n";
        foreach ($keep as [$col, $stored, $why]) {
            echo "  {$col}: {$stored}\n    {$why}\n";
        }
        if ($keep === []) { echo "  (none)\n"; }

        if (!$commit) {
            echo "\nDRY RUN — nothing deleted. Re-run with --commit to apply.\n";
            return;
        }

        echo "\n--- DELETING ---\n";
        $ok = 0; $fail = 0;
        foreach ($del as [$col, $entityId, $stored, $_why]) {
            try {
                $this->fs->removeEntity($col, $entityId);
                echo "  DELETED {$col}: {$stored}\n";
                $ok++;
            } catch (\Throwable $e) {
                echo "  FAILED  {$col}: {$stored} — " . $e->getMessage() . "\n";
                $fail++;
            }
        }
        echo "\ndeleted={$ok} failed={$fail}\n";
    }
}
