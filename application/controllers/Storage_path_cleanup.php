<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Storage_path_cleanup — the final step of the 2026-06-13 storage cutover,
 * run AFTER Storage_path_migration has copied legacy files to the canonical
 * schools/{schoolId}/... tree and rewritten the stored download URLs.
 *
 * Two commands:
 *
 *   verify <schoolId|all>
 *       Read-only. Reports two things that MUST both be zero before it is safe
 *       to delete any legacy object:
 *         (a) records (Firestore/RTDB) whose stored URL still points at a
 *             legacy path  → the migration's URL rewrite is incomplete.
 *         (b) legacy objects with NO canonical counterpart → never migrated,
 *             so deleting them would lose data.
 *
 *   purge <schoolId|all> [commit]
 *       Deletes legacy objects, but ONLY after a clean verify and ONLY for
 *       objects whose canonical copy already exists. Refuses to run if any
 *       record still references a legacy URL (deleting would break live links).
 *       DRY-RUN unless the trailing `commit` arg is given.
 *
 * The legacy↔canonical mapping is shared with the migration via the
 * Storage_path_map library, so the two can never disagree on what "legacy" is.
 *
 * USAGE (from project root):
 *   php index.php storage_path_cleanup verify <schoolId|all>
 *   php index.php storage_path_cleanup purge  <schoolId|all>           # dry-run
 *   php index.php storage_path_cleanup purge  <schoolId|all> commit    # delete
 */
class Storage_path_cleanup extends CI_Controller
{
    /** @var bool */
    private $commit = false;

    private $stats = [
        'objects_scanned'      => 0,
        'legacy_with_canon'    => 0, // safe to delete
        'legacy_without_canon' => 0, // UNMIGRATED — never deleted
        'objects_deleted'      => 0,
        'delete_failures'      => 0,
        'records_with_legacy'  => 0, // URLs still on the old scheme
        'legacy_urls_found'    => 0,
    ];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('This tool is CLI-only.', 403);
            exit(1);
        }
        $this->load->library('firebase');
        $this->load->library('firestore_service', null, 'fs');
        $this->load->library('storage_path_map', null, 'map');
        $this->map->set_resolver(function (string $code) {
            return $this->fs->getSchoolByCode($code);
        });
    }

    // ════════════════════════════════════════════════════════════════════
    //  COMMANDS
    // ════════════════════════════════════════════════════════════════════

    public function verify($scope = '')
    {
        if ($scope === '') { $this->out("Usage: php index.php storage_path_cleanup verify <schoolId|all>"); return; }
        $this->commit = false;
        $clean = $this->sweep($scope, false);
        $this->out("");
        $this->out($clean
            ? "✓ CLEAN — no legacy URL references and every legacy object has a canonical copy. Safe to purge."
            : "✗ NOT CLEAN — resolve the items above (re-run the migration) before purging.");
    }

    public function purge($scope = '', $commit = '')
    {
        if ($scope === '') { $this->out("Usage: php index.php storage_path_cleanup purge <schoolId|all> [commit]"); return; }
        $this->commit = ($commit === 'commit');
        $this->sweep($scope, true);
        if (!$this->commit) {
            $this->out("");
            $this->out("DRY-RUN complete. Re-run with a trailing 'commit' arg to delete the legacy objects.");
        }
    }

    // ════════════════════════════════════════════════════════════════════
    //  CORE SWEEP  (shared by verify + purge)
    // ════════════════════════════════════════════════════════════════════

    /**
     * Scan the legacy trees for the given scope. When $delete is true, also
     * delete each legacy object whose canonical copy exists — but only if NO
     * record still references a legacy URL (checked first, aborts otherwise).
     * Returns true if the scope is "clean" (nothing unmigrated, no legacy refs).
     */
    private function sweep(string $scope, bool $delete): bool
    {
        $schools = ($scope === 'all') ? $this->allSchoolIds() : [$scope];
        if (empty($schools)) { $this->out("No schools resolved for scope '{$scope}'."); return false; }

        $mode = $delete ? ($this->commit ? 'PURGE (deleting)' : 'PURGE (dry-run)') : 'VERIFY (read-only)';
        $this->out(str_repeat('═', 70));
        $this->out("STORAGE PATH CLEANUP  —  {$mode}");
        $this->out("Schools: " . implode(', ', $schools));
        $this->out(str_repeat('═', 70));

        foreach ($schools as $sid) {
            $this->sweepSchool((string) $sid, $delete);
        }

        $this->out(str_repeat('─', 70));
        $this->out("SUMMARY");
        foreach ($this->stats as $k => $v) {
            $this->out(sprintf("  %-22s %d", $k, $v));
        }
        $this->out(str_repeat('═', 70));

        return $this->stats['records_with_legacy'] === 0
            && $this->stats['legacy_without_canon'] === 0;
    }

    private function sweepSchool(string $sid, bool $delete): void
    {
        $this->out("\n● School {$sid}");

        $prefixes = $this->map->legacy_prefixes($sid, $this->schoolLoginCode($sid));

        // First pass: collect legacy objects + the records that own them, and
        // classify each object as migrated (canonical exists) or not.
        $legacy  = [];   // oldPath => bool canonExists
        $records = [];   // ref => true
        foreach ($prefixes as $prefix) {
            foreach ($this->firebase->listStorageFiles($prefix, 1000000) as $oldPath) {
                $this->stats['objects_scanned']++;
                $newPath = $this->map->map_old_to_new($oldPath);
                if ($newPath === null || $newPath === $oldPath) continue;

                $canon = ($this->firebase->objectInfo($newPath) !== null);
                $legacy[$oldPath] = $canon;
                $canon ? $this->stats['legacy_with_canon']++
                       : $this->stats['legacy_without_canon']++;
                if (!$canon) {
                    $this->out("  ⚠ UNMIGRATED (no canonical copy, will NOT delete): {$oldPath}");
                }

                $ref = $this->map->owning_record($sid, $oldPath);
                if ($ref !== null) $records[$ref] = true;
            }
        }

        // Second pass: are any stored URLs still on the legacy scheme?
        $dirtyRefs = false;
        foreach (array_keys($records) as $ref) {
            $data = $this->readRecord($ref);
            if (!is_array($data)) continue;
            $count = $this->countLegacyUrls($data);
            if ($count > 0) {
                $dirtyRefs = true;
                $this->stats['records_with_legacy']++;
                $this->stats['legacy_urls_found'] += $count;
                $this->out("  ✗ still legacy: {$ref}  ({$count} url" . ($count === 1 ? '' : 's') . ")");
            }
        }

        if (!$delete) return;

        // Deletion is gated on a clean rewrite: if any URL still points at a
        // legacy path, deleting the object behind it would break a live link.
        if ($dirtyRefs) {
            $this->out("  ⏭ skipping deletes for {$sid} — rewrite incomplete (run the migration first).");
            return;
        }

        foreach ($legacy as $oldPath => $canonExists) {
            if (!$canonExists) continue; // never delete an unmigrated object
            $this->out("  delete  {$oldPath}");
            if ($this->commit) {
                $this->firebase->deleteStorageFile($oldPath)
                    ? $this->stats['objects_deleted']++
                    : $this->stats['delete_failures']++;
            } else {
                $this->stats['objects_deleted']++; // would-delete
            }
        }
    }

    // ════════════════════════════════════════════════════════════════════
    //  RECORD READING + LEGACY-URL DETECTION
    // ════════════════════════════════════════════════════════════════════

    /** Read an owning record ("firestore|coll|docId" or "rtdb|node"). */
    private function readRecord(string $ref)
    {
        [$kind, $a, $b] = array_pad(explode('|', $ref, 3), 3, '');
        if ($kind === 'firestore') return $this->fs->get($a, $b);
        if ($kind === 'rtdb')      return $this->firebase->get($a);
        return null;
    }

    /** Count strings anywhere in $data that are still legacy-scheme Storage URLs. */
    private function countLegacyUrls($data): int
    {
        $count = 0;
        if (is_string($data)) {
            return $this->map->swap_url($data) !== null ? 1 : 0;
        }
        if (is_array($data)) {
            foreach ($data as $v) $count += $this->countLegacyUrls($v);
        }
        return $count;
    }

    // ════════════════════════════════════════════════════════════════════
    //  HELPERS
    // ════════════════════════════════════════════════════════════════════

    private function allSchoolIds(): array
    {
        $children = $this->firebase->getChildren('Schools');
        $ids = [];
        if (is_array($children) || $children instanceof \Traversable) {
            foreach ($children as $key => $_) $ids[] = (string) $key;
        }
        return $ids;
    }

    private function schoolLoginCode(string $sid): string
    {
        $doc = $this->fs->get('schools', $sid);
        if (!is_array($doc)) return '';
        foreach (['school_code', 'schoolCode', 'code', 'loginCode'] as $k) {
            if (!empty($doc[$k]) && is_scalar($doc[$k])) return (string) $doc[$k];
        }
        return '';
    }

    private function out(string $line): void
    {
        fwrite(STDOUT, $line . "\n");
    }
}
