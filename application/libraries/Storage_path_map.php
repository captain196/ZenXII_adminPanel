<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Storage_path_map — single source of truth for the legacy → canonical
 * Firebase Storage path mapping used by the 2026-06-13 storage cutover.
 *
 * Both one-off CLI ops scripts depend on this so their notion of "legacy" can
 * never drift apart:
 *   • Storage_path_migration  — copies legacy objects to canonical + rewrites URLs
 *   • Storage_path_cleanup     — verifies no legacy URL remains, then purges
 *
 * Pure logic, no Firebase calls. The only external dependency is the optional
 * code→schoolId resolver (the docs-page legacy scheme can be keyed by a numeric
 * login code instead of the SCH id); inject it with set_resolver().
 */
class Storage_path_map
{
    /** @var callable|null  fn(string $code): ?string */
    private $resolver = null;

    public function __construct($params = []) {}

    /** Inject the code→schoolId resolver (e.g. fn($c) => $fs->getSchoolByCode($c)). */
    public function set_resolver(callable $resolver): void
    {
        $this->resolver = $resolver;
    }

    // ════════════════════════════════════════════════════════════════════
    //  PATH MAPPING
    // ════════════════════════════════════════════════════════════════════

    /**
     * Map a legacy Storage object path to its canonical schools/{id}/... path,
     * or null if the path is already canonical / not a recognised legacy scheme.
     */
    public function map_old_to_new(string $path): ?string
    {
        $path = ltrim($path, '/');
        $seg  = explode('/', $path);
        $n    = count($seg);
        if ($n < 2) return null;
        if ($seg[0] === 'schools') return null; // already canonical

        // L7 docs-page:  Students/{schoolKey}/{userId}/docs/{rest...}
        if ($seg[0] === 'Students' && $n >= 4 && $seg[3] === 'docs') {
            $sid    = $this->resolve_sid($seg[1]);
            $userId = $seg[2];
            $rest   = implode('/', array_slice($seg, 4));
            return "schools/{$sid}/students/{$userId}/documents" . ($rest !== '' ? "/{$rest}" : '');
        }

        // Stories:  stories/admin/{sid}/{adminId}/{rest...}
        if ($seg[0] === 'stories' && ($seg[1] ?? '') === 'admin' && $n >= 4) {
            $sid     = $seg[2];
            $adminId = $seg[3];
            $rest    = implode('/', array_slice($seg, 4));
            return "schools/{$sid}/stories/{$adminId}" . ($rest !== '' ? "/{$rest}" : '');
        }

        // Schemes rooted at {sid}/...
        $sid  = $seg[0];
        $kind = $seg[1] ?? '';

        // Staff:  {sid}/Staff/{staffId}/{Profile_pic|Documents}/{rest...}
        if ($kind === 'Staff' && $n >= 4) {
            $staffId = $seg[2];
            $newSub  = $this->sub_folder($seg[3]);
            if ($newSub === null) return null;
            $rest = implode('/', array_slice($seg, 4));
            return "schools/{$sid}/staff/{$staffId}/{$newSub}" . ($rest !== '' ? "/{$rest}" : '');
        }

        // Students (admission):  {sid}/Students/{class}/{section}/{studentId}/{kw}/{rest...}
        // Class path is one-or-more segments → anchor on the keyword.
        if ($kind === 'Students' && $n >= 4) {
            $ki = -1;
            for ($i = 2; $i < $n; $i++) {
                if ($seg[$i] === 'Profile_pic' || $seg[$i] === 'Documents') { $ki = $i; break; }
            }
            if ($ki < 3) return null;
            $studentId = $seg[$ki - 1];
            $newSub    = $this->sub_folder($seg[$ki]);
            $rest      = implode('/', array_slice($seg, $ki + 1));
            return "schools/{$sid}/students/{$studentId}/{$newSub}" . ($rest !== '' ? "/{$rest}" : '');
        }

        // Events:  {sid}/Events/Media/{eventId}/{rest...}
        if ($kind === 'Events' && ($seg[2] ?? '') === 'Media' && $n >= 4) {
            $eventId = $seg[3];
            $rest    = implode('/', array_slice($seg, 4));
            return "schools/{$sid}/events/{$eventId}" . ($rest !== '' ? "/{$rest}" : '');
        }

        // Legacy logos:  {sid}/school_logos/{rest...}
        if ($kind === 'school_logos' && $n >= 3) {
            $rest = implode('/', array_slice($seg, 2));
            return "schools/{$sid}/logos" . ($rest !== '' ? "/{$rest}" : '');
        }

        return null;
    }

    /** The legacy Storage prefixes to scan for one school. */
    public function legacy_prefixes(string $sid, string $code = ''): array
    {
        $prefixes = [
            "{$sid}/Staff/",
            "{$sid}/Students/",
            "{$sid}/Events/Media/",
            "{$sid}/school_logos/",
            "stories/admin/{$sid}/",
            "Students/{$sid}/",          // docs-page L7 keyed by SCH id
        ];
        if ($code !== '' && $code !== $sid) {
            $prefixes[] = "Students/{$code}/"; // docs-page L7 keyed by login code
        }
        return $prefixes;
    }

    /**
     * Derive the Firestore doc / RTDB node that stores the download URL for a
     * legacy object, encoded as "kind|...". Returns null if unknown.
     *   firestore|{collection}|{docId}     rtdb|{node}
     */
    public function owning_record(string $sid, string $path): ?string
    {
        $seg = explode('/', ltrim($path, '/'));
        $n   = count($seg);

        // Students admission:  {sid}/Students/.../{studentId}/{kw}/...
        if (($seg[1] ?? '') === 'Students' && $seg[0] !== 'Students') {
            for ($i = 2; $i < $n; $i++) {
                if ($seg[$i] === 'Profile_pic' || $seg[$i] === 'Documents') {
                    return "firestore|students|{$sid}_{$seg[$i-1]}";
                }
            }
        }
        // Students docs-page:  Students/{key}/{userId}/docs/...
        if ($seg[0] === 'Students' && $n >= 3) {
            $rsid = $this->resolve_sid($seg[1]);
            return "firestore|students|{$rsid}_{$seg[2]}";
        }
        // Staff:  {sid}/Staff/{staffId}/...
        if (($seg[1] ?? '') === 'Staff') {
            return "firestore|staff|{$sid}_{$seg[2]}";
        }
        // Stories:  stories/admin/{sid}/{adminId}/{ts}.{ext}
        if ($seg[0] === 'stories' && ($seg[1] ?? '') === 'admin' && $n >= 4) {
            $adminId = $seg[3];
            $ts      = pathinfo($seg[4] ?? '', PATHINFO_FILENAME);
            if ($ts !== '') return "firestore|stories|{$seg[2]}_admin_{$adminId}_{$ts}";
        }
        // Legacy logo:  {sid}/school_logos/...  → schools/{sid} doc
        if (($seg[1] ?? '') === 'school_logos') {
            return "firestore|schools|{$sid}";
        }
        // Events:  {sid}/Events/Media/{eventId}/...  → RTDB node
        if (($seg[1] ?? '') === 'Events' && ($seg[2] ?? '') === 'Media') {
            return "rtdb|Schools/{$sid}/Events/Media/{$seg[3]}";
        }
        return null;
    }

    /**
     * Rewrite a single download-URL string from a legacy path to its canonical
     * path, preserving bucket + token. Returns null if the string is not a
     * legacy Storage URL (already canonical or not a Storage URL at all).
     */
    public function swap_url(string $url): ?string
    {
        if (strpos($url, 'firebasestorage.googleapis.com') === false) return null;
        if (!preg_match('#/o/([^?]+)#', $url, $m)) return null;
        $oldPath = urldecode($m[1]);
        $newPath = $this->map_old_to_new($oldPath);
        if ($newPath === null) return null;

        $token = '';
        if (preg_match('/[?&]token=([^&]+)/', $url, $mm)) $token = $mm[1];
        $bucket = '';
        if (preg_match('#/b/([^/]+)/o/#', $url, $mm)) $bucket = $mm[1];
        if ($bucket === '') return null;

        // urlencode() to match Firebase::getDownloadUrl()'s URL construction.
        return sprintf(
            'https://firebasestorage.googleapis.com/v0/b/%s/o/%s?alt=media&token=%s',
            $bucket, urlencode($newPath), $token
        );
    }

    // ════════════════════════════════════════════════════════════════════
    //  INTERNALS
    // ════════════════════════════════════════════════════════════════════

    /** Profile_pic→profile, Documents→documents, else null. */
    private function sub_folder(string $s): ?string
    {
        if ($s === 'Profile_pic') return 'profile';
        if ($s === 'Documents')   return 'documents';
        return null;
    }

    /** A numeric login code resolves to its SCH id; an SCH id passes through. */
    private function resolve_sid(string $key): string
    {
        if (strncmp($key, 'SCH', 3) === 0) return $key;
        if ($this->resolver !== null) {
            $resolved = ($this->resolver)($key);
            if (is_string($resolved) && $resolved !== '') return $resolved;
        }
        return $key;
    }
}
