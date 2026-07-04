<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Gallery_migrate — one-off CLI backfill that mirrors the pre-consolidation
 * RTDB gallery tree into the canonical Firestore contract the mobile apps read.
 *
 * WHY: the admin gallery historically stored media ONLY in RTDB
 *   Schools/{schoolId}/Events/Media/{albumId}/{mediaId}
 * while the Teacher + Parent apps read gallery ONLY from Firestore
 *   galleryAlbums/{schoolId}_{albumId}   +   galleryMedia/{albumId}_{mediaId}
 * so every photo uploaded before the 2026-07 dual-write fix is invisible in the
 * apps. Live uploads are now dual-written by Schools::uploadMedia; this script
 * back-fills the historical RTDB media so the apps see the full gallery.
 *
 * WHAT IT WRITES (via Entity_firestore_sync — the SAME code the runtime uses):
 *   galleryAlbums/{schoolId}_{albumId}
 *     {schoolId, albumId, title, category, session, source, eventId,
 *      mediaCount(int, = RTDB album size), coverImage(first image), isArchived,
 *      createdBy, createdAt, updatedAt}
 *   galleryMedia/{albumId}_{mediaId}
 *     {schoolId, albumId, url, type, thumbnail, duration, caption,
 *      isArchived, uploadedBy, uploadedAt}
 *
 * albumId === the RTDB album key: an EVT event id (source="event") or one of
 * the built-in buckets __photos__ / __videos__ (source="general"). Event album
 * titles/categories are resolved from Firestore `events`, falling back to the
 * dead RTDB Events/List, then to the id itself.
 *
 * SAFETY MODEL
 *   • DRY-RUN by default — logs every doc it WOULD write, touches nothing.
 *     Pass a trailing `commit` to actually write.
 *   • IDEMPOTENT — a galleryMedia doc that already exists is skipped, and
 *     mediaCount is SET to the absolute RTDB album size (not incremented), so
 *     re-runs converge instead of drifting. coverImage is only seeded when the
 *     album has none. Safe to re-run.
 *   • Does NOT touch RTDB or Storage — Firestore writes only.
 *   • Best-effort per media item — one failure is logged and skipped, the rest
 *     of the album continues.
 *
 * USAGE (from project root):
 *   php index.php gallery_migrate run <schoolId|all>            # dry-run
 *   php index.php gallery_migrate run <schoolId|all> commit     # execute
 *
 * ROLL-OUT: run the DRY-RUN for ONE school first, eyeball the planned docs,
 * then `commit` that single school and verify the albums render in the Teacher
 * and Parent apps. Only after that verification, run `all` (or per-school in
 * batches). RTDB stays the source of truth throughout — nothing is deleted.
 */
class Gallery_migrate extends CI_Controller
{
    /** @var bool  true = write to Firestore, false = dry-run. */
    private $commit = false;

    /** Aggregate counters for the final report. */
    private $stats = [
        'schools_scanned'  => 0,
        'albums_scanned'   => 0,
        'albums_written'   => 0,
        'media_scanned'    => 0,
        'media_written'    => 0,
        'media_skipped'    => 0, // galleryMedia doc already exists
        'media_failures'   => 0,
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
        $this->load->library('entity_firestore_sync', null, 'entity_sync');
    }

    // ════════════════════════════════════════════════════════════════════
    //  ENTRY POINT
    // ════════════════════════════════════════════════════════════════════

    public function run($scope = '', $commit = '')
    {
        $this->commit = ($commit === 'commit');

        if ($scope === '') {
            $this->out("Usage: php index.php gallery_migrate run <schoolId|all> [commit]");
            return;
        }

        $schools = ($scope === 'all') ? $this->allSchoolIds() : [$scope];
        if (empty($schools)) {
            $this->out("No schools resolved for scope '{$scope}'.");
            return;
        }

        $this->out(str_repeat('=', 70));
        $this->out("GALLERY MIGRATION  —  mode: " . ($this->commit ? 'COMMIT (writing)' : 'DRY-RUN (no writes)'));
        $this->out("Schools: " . implode(', ', $schools));
        $this->out(str_repeat('=', 70));

        foreach ($schools as $sid) {
            $this->processSchool((string) $sid);
        }

        $this->out(str_repeat('-', 70));
        $this->out("SUMMARY");
        foreach ($this->stats as $k => $v) {
            $this->out(sprintf("  %-16s %d", $k, $v));
        }
        if (!$this->commit) {
            $this->out("");
            $this->out("DRY-RUN complete. Re-run with a trailing 'commit' arg to apply.");
        }
        $this->out(str_repeat('=', 70));
    }

    // ════════════════════════════════════════════════════════════════════
    //  PER-SCHOOL DRIVER
    // ════════════════════════════════════════════════════════════════════

    private function processSchool(string $sid): void
    {
        $this->stats['schools_scanned']++;
        $this->out("\n* School {$sid}");

        // In this ERP schoolId (SCH_XXXXXX) === school_name (== RTDB Schools key).
        $session = $this->schoolSession($sid);
        $this->entity_sync->init($this->firebase, $sid, $session, $sid);

        $mediaRoot = $this->firebase->get("Schools/{$sid}/Events/Media") ?? [];
        if (!is_array($mediaRoot) || empty($mediaRoot)) {
            $this->out("  (no RTDB Events/Media node — nothing to migrate)");
            return;
        }

        foreach ($mediaRoot as $albumId => $mediaItems) {
            $albumId = (string) $albumId;
            if ($albumId === '' || !is_array($mediaItems)) continue;
            $this->processAlbum($sid, $albumId, $mediaItems);
        }
    }

    private function processAlbum(string $sid, string $albumId, array $mediaItems): void
    {
        $this->stats['albums_scanned']++;
        $meta = $this->albumMeta($sid, $albumId);

        // Absolute media count + first image url straight from RTDB (authority).
        $count = 0; $firstImage = '';
        foreach ($mediaItems as $m) {
            if (!is_array($m) || empty($m['url'])) continue;
            $count++;
            if ($firstImage === '' && ($m['type'] ?? '') === 'image') $firstImage = (string) $m['url'];
        }
        if ($count === 0) return;

        $this->out("  album {$albumId}  ({$meta['source']}, {$count} media)  \"{$meta['title']}\"");

        // Only seed coverImage when the canonical album has none yet.
        $existing = $this->commit ? $this->firebase->firestoreGet('galleryAlbums', "{$sid}_{$albumId}") : null;
        $needCover = !is_array($existing) || empty($existing['coverImage']);

        $albumDoc = [
            'title'      => $meta['title'],
            'category'   => $meta['category'],
            'source'     => $meta['source'],
            'eventId'    => $meta['eventId'],
            'mediaCount' => $count,       // absolute — idempotent on re-run
            'isArchived' => false,
            'createdBy'  => 'migration',
            'createdAt'  => date('c'),
        ];
        if ($needCover && $firstImage !== '') $albumDoc['coverImage'] = $firstImage;

        if ($this->commit) {
            if ($this->entity_sync->syncGalleryAlbum($albumId, $albumDoc)) $this->stats['albums_written']++;
        } else {
            $this->out("    would upsert galleryAlbums/{$sid}_{$albumId}  " . json_encode($albumDoc));
            $this->stats['albums_written']++;
        }

        // Per-media docs.
        foreach ($mediaItems as $mediaId => $m) {
            $mediaId = (string) $mediaId;
            if ($mediaId === '' || !is_array($m) || empty($m['url'])) continue;
            $this->stats['media_scanned']++;

            $docId = "{$albumId}_{$mediaId}";

            // Idempotency — skip if the galleryMedia doc already exists.
            if ($this->commit) {
                $exists = $this->firebase->firestoreGet('galleryMedia', $docId);
                if (is_array($exists)) { $this->stats['media_skipped']++; continue; }
            }

            $mediaDoc = [
                'url'        => (string) ($m['url'] ?? ''),
                'type'       => (($m['type'] ?? '') === 'video') ? 'video' : 'image',
                'thumbnail'  => (string) ($m['thumbnail'] ?? ''),
                'duration'   => (string) ($m['duration'] ?? ''),
                'caption'    => (string) ($m['caption'] ?? ''),
                'isArchived' => false,
                'uploadedBy' => (string) ($m['uploaded_by'] ?? 'migration'),
                'uploadedAt' => (string) ($m['uploaded_at'] ?? date('c')),
            ];

            if ($this->commit) {
                try {
                    if ($this->entity_sync->syncGalleryMedia($albumId, $mediaId, $mediaDoc)) {
                        $this->stats['media_written']++;
                    } else {
                        $this->stats['media_failures']++;
                    }
                } catch (\Throwable $e) {
                    $this->stats['media_failures']++;
                    log_message('error', "Gallery_migrate media write failed [{$docId}]: " . $e->getMessage());
                }
            } else {
                $this->out("    would write galleryMedia/{$docId}  " . json_encode($mediaDoc));
                $this->stats['media_written']++;
            }
        }
    }

    // ════════════════════════════════════════════════════════════════════
    //  HELPERS
    // ════════════════════════════════════════════════════════════════════

    /**
     * Resolve album metadata (source / eventId / title / category) for an
     * RTDB album key: built-in buckets are "general"; anything else is an event
     * album whose title/category come from Firestore `events`, then the dead
     * RTDB Events/List, then the id itself.
     */
    private function albumMeta(string $sid, string $albumId): array
    {
        $defaults = [
            '__photos__' => ['title' => 'School Photos', 'category' => 'general'],
            '__videos__' => ['title' => 'School Videos', 'category' => 'general'],
            '__legacy__' => ['title' => 'General Gallery', 'category' => 'general'],
        ];
        if (isset($defaults[$albumId])) {
            return [
                'source'   => 'general',
                'eventId'  => '',
                'title'    => $defaults[$albumId]['title'],
                'category' => $defaults[$albumId]['category'],
            ];
        }

        $title = $albumId;
        $category = 'event';

        // Firestore events first (doc-id {schoolId}_{eventId}).
        $evt = $this->fs->get('events', "{$sid}_{$albumId}");
        if (is_array($evt)) {
            $title    = (string) ($evt['title'] ?? $albumId);
            $category = (string) ($evt['category'] ?? 'event');
        } else {
            // Fallback: dead RTDB Events/List node (may still hold a title).
            $legacy = $this->firebase->get("Schools/{$sid}/Events/List/{$albumId}");
            if (is_array($legacy)) {
                $title    = (string) ($legacy['title'] ?? $albumId);
                $category = (string) ($legacy['category'] ?? 'event');
            }
        }

        return ['source' => 'event', 'eventId' => $albumId, 'title' => $title, 'category' => $category];
    }

    /** The school's current session (for the album `session` field). */
    private function schoolSession(string $sid): string
    {
        $doc = $this->fs->get('schools', $sid);
        if (is_array($doc)) {
            foreach (['currentSession', 'session', 'activeSession'] as $k) {
                if (!empty($doc[$k]) && is_scalar($doc[$k])) return (string) $doc[$k];
            }
        }
        return '';
    }

    /**
     * All school ids for the `all` scope.
     *
     * NOTE: the earlier Firebase::getChildren('Schools') approach is broken —
     * that wrapper calls Kreait Snapshot::getChildren() which does not exist and
     * throws a \Error (not caught by its \Exception handler). Enumerate from the
     * authoritative Firestore `schools` registry instead (lightweight — the doc
     * id IS the SCH_ id; query() returns rows shaped ['id'=>docId,'data'=>...]).
     */
    private function allSchoolIds(): array
    {
        $ids = [];
        try {
            $rows = $this->firebase->firestoreQuery('schools', []);
            foreach ((array) $rows as $r) {
                $id = $r['id']
                    ?? ($r['data']['schoolId'] ?? null)
                    ?? ($r['schoolId'] ?? $r['school_id'] ?? null);
                if (!empty($id)) $ids[] = (string) $id;
            }
        } catch (\Throwable $e) {
            log_message('error', 'Gallery_migrate::allSchoolIds() firestore enum failed: ' . $e->getMessage());
        }
        return array_values(array_unique($ids));
    }

    private function out(string $line): void
    {
        fwrite(STDOUT, $line . "\n");
    }
}
