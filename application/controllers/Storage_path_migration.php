<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Storage_path_migration — one-off CLI migration that copies pre-2026-06-13
 * Firebase Storage files from their LEGACY path schemes to the canonical
 * `schools/{schoolId}/...` tree, and rewrites the stored download URLs in
 * Firestore / RTDB so nothing keeps pointing at the old paths.
 *
 * WHY: before the 2026-06-13 cutover, files landed under five different
 * roots ({schoolName}/Staff, {schoolName}/Students/{class}, {schoolName}/Events,
 * {schoolName}/school_logos, Students/{code}/.../docs, stories/admin/{id}).
 * New writes now all go to schools/{schoolId}/... ; this script back-fills the
 * existing files so the whole bucket is uniform and a school's entire footprint
 * lives under one ID-keyed prefix.
 *
 * SAFETY MODEL
 *   • COPY, never move — the legacy object is left in place, so even if a
 *     download URL is missed the old link keeps resolving. A separate cleanup
 *     step (not this script) deletes the legacy objects once verified.
 *   • GCS copy preserves the firebaseStorageDownloadTokens token, so the new
 *     URL is the old URL with only the path segment swapped — URL rewrites are
 *     a pure string transform, no re-tokenisation.
 *   • DRY-RUN by default. Pass `commit` as the 2nd arg to actually write.
 *   • IDEMPOTENT — a destination object that already exists is skipped, and a
 *     URL already on the new scheme maps to null (no-op). Safe to re-run.
 *   • STORAGE-DRIVEN — it lists what actually exists under each legacy prefix,
 *     so the work set is bounded and never enumerates whole collections.
 *
 * USAGE (from project root):
 *   php index.php storage_path_migration run <schoolId|all>            # dry-run
 *   php index.php storage_path_migration run <schoolId|all> commit     # execute
 *
 * Mapping is centralised in mapOldToNew() and is the SAME logic the runtime
 * controllers now use for new writes (see Sis/Staff/Schools/Stories/etc.).
 */
class Storage_path_migration extends CI_Controller
{
    /** @var bool */
    private $commit = false;

    /** Aggregate counters for the final report. */
    private $stats = [
        'objects_scanned'   => 0,
        'objects_copied'    => 0,
        'objects_skipped'   => 0, // destination already exists
        'objects_unmapped'  => 0, // not a known legacy scheme
        'copy_failures'     => 0,
        'records_rewritten' => 0,
        'urls_rewritten'    => 0,
        'rewrite_failures'  => 0,
    ];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('This migration is CLI-only.', 403);
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
    //  ENTRY POINT
    // ════════════════════════════════════════════════════════════════════

    public function run($scope = '', $commit = '')
    {
        $this->commit = ($commit === 'commit');

        if ($scope === '') {
            $this->out("Usage: php index.php storage_path_migration run <schoolId|all> [commit]");
            return;
        }

        $schools = ($scope === 'all') ? $this->allSchoolIds() : [$scope];
        if (empty($schools)) {
            $this->out("No schools resolved for scope '{$scope}'.");
            return;
        }

        $this->out(str_repeat('═', 70));
        $this->out("STORAGE PATH MIGRATION  —  mode: " . ($this->commit ? 'COMMIT (writing)' : 'DRY-RUN (no writes)'));
        $this->out("Schools: " . implode(', ', $schools));
        $this->out(str_repeat('═', 70));

        foreach ($schools as $sid) {
            $this->processSchool((string) $sid);
        }

        $this->out(str_repeat('─', 70));
        $this->out("SUMMARY");
        foreach ($this->stats as $k => $v) {
            $this->out(sprintf("  %-18s %d", $k, $v));
        }
        if (!$this->commit) {
            $this->out("");
            $this->out("DRY-RUN complete. Re-run with a trailing 'commit' arg to apply.");
        }
        $this->out(str_repeat('═', 70));
    }

    // ════════════════════════════════════════════════════════════════════
    //  PER-SCHOOL DRIVER
    // ════════════════════════════════════════════════════════════════════

    private function processSchool(string $sid): void
    {
        $this->out("\n● School {$sid}");

        // Legacy prefixes whose ROOT differs from the canonical scheme. The
        // schools/{id}/{circulars,academic,holidays,reportcard,logos} files are
        // already canonical (their old code keyed by school_name == SCH id), so
        // they are intentionally NOT scanned here.
        $prefixes = $this->map->legacy_prefixes($sid, $this->schoolLoginCode($sid));

        // Owning records to rewrite afterwards, de-duplicated.
        //   firestore: "collection|docId"   rtdb: "rtdb|node"
        $records = [];

        foreach ($prefixes as $prefix) {
            $objects = $this->firebase->listStorageFiles($prefix, 1000000);
            foreach ($objects as $oldPath) {
                $this->stats['objects_scanned']++;
                $newPath = $this->map->map_old_to_new($oldPath);
                if ($newPath === null || $newPath === $oldPath) {
                    $this->stats['objects_unmapped']++;
                    continue;
                }

                // Idempotency — skip if the destination already exists.
                if ($this->firebase->objectInfo($newPath) !== null) {
                    $this->stats['objects_skipped']++;
                } else {
                    $this->out("  copy  {$oldPath}");
                    $this->out("    ->  {$newPath}");
                    if ($this->commit) {
                        if ($this->firebase->copyStorageFile($oldPath, $newPath)) {
                            $this->stats['objects_copied']++;
                        } else {
                            $this->stats['copy_failures']++;
                        }
                    } else {
                        $this->stats['objects_copied']++; // would-copy
                    }
                }

                $ref = $this->map->owning_record($sid, $oldPath);
                if ($ref !== null) $records[$ref] = true;
            }
        }

        // Rewrite the stored URLs in every owning record exactly once.
        foreach (array_keys($records) as $ref) {
            $this->rewriteRecord($ref);
        }
    }

    // ════════════════════════════════════════════════════════════════════
    //  URL REWRITE  (path mapping lives in the Storage_path_map library)
    // ════════════════════════════════════════════════════════════════════

    /** Read a record, deep-rewrite any legacy Storage URL it holds, write back. */
    private function rewriteRecord(string $ref): void
    {
        [$kind, $a, $b] = array_pad(explode('|', $ref, 3), 3, '');

        if ($kind === 'firestore') {
            $doc = $this->fs->get($a, $b);
            if (!is_array($doc)) return;
            $count = 0;
            $new   = $this->deepRewrite($doc, $count);
            if ($count === 0) return;
            $this->out("  rewrite firestore {$a}/{$b}  ({$count} url" . ($count === 1 ? '' : 's') . ")");
            $this->stats['urls_rewritten'] += $count;
            if ($this->commit) {
                $this->fs->set($a, $b, $new, true)
                    ? $this->stats['records_rewritten']++
                    : $this->stats['rewrite_failures']++;
            } else {
                $this->stats['records_rewritten']++;
            }
            return;
        }

        if ($kind === 'rtdb') {
            $node = $a; // already the full node path
            $val  = $this->firebase->get($node);
            if (!is_array($val)) return;
            $count = 0;
            $new   = $this->deepRewrite($val, $count);
            if ($count === 0) return;
            $this->out("  rewrite rtdb {$node}  ({$count} url" . ($count === 1 ? '' : 's') . ")");
            $this->stats['urls_rewritten'] += $count;
            if ($this->commit) {
                $this->firebase->set($node, $new)
                    ? $this->stats['records_rewritten']++
                    : $this->stats['rewrite_failures']++;
            } else {
                $this->stats['records_rewritten']++;
            }
        }
    }

    /** Recursively swap any legacy firebasestorage URL string to its new path. */
    private function deepRewrite($data, int &$count)
    {
        if (is_string($data)) {
            $swapped = $this->map->swap_url($data);
            if ($swapped !== null) { $count++; return $swapped; }
            return $data;
        }
        if (is_array($data)) {
            foreach ($data as $k => $v) $data[$k] = $this->deepRewrite($v, $count);
        }
        return $data;
    }

    // ════════════════════════════════════════════════════════════════════
    //  HELPERS
    // ════════════════════════════════════════════════════════════════════

    /** All school ids (RTDB Schools/ keys == SCH ids), for `all` scope. */
    private function allSchoolIds(): array
    {
        $children = $this->firebase->getChildren('Schools');
        $ids = [];
        if (is_array($children) || $children instanceof \Traversable) {
            foreach ($children as $key => $_) $ids[] = (string) $key;
        }
        return $ids;
    }

    /** The school's numeric login code (for the docs-page L7 legacy prefix). */
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
