<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Parent-facing / printable URL helper.
 *
 * `base_url()` reflects the CURRENT request host — correct for admin-panel
 * assets and redirects, but WRONG for anything that gets shared with parents
 * or PRINTED (QR posters, the admission link a school pastes on its window).
 * A QR generated while the admin is on `localhost` must still point at the
 * live public domain, not localhost. These helpers resolve against the
 * canonical `public_base_url` config (see config.php / PUBLIC_BASE_URL env),
 * which defaults to the production origin `https://www.zenxii.com/`.
 */

if (!function_exists('public_base_url')) {
    /**
     * Canonical public origin + optional path. Always the production host,
     * never the request host. Falls back to base_url() only if the config
     * is somehow blank (defensive — config.php always sets a default).
     */
    function public_base_url(string $uri = ''): string
    {
        $base = (string) config_item('public_base_url');
        if ($base === '') {
            // Defensive fallback — should never happen (config.php sets a default).
            $base = function_exists('base_url') ? base_url() : '/';
        }
        return rtrim($base, '/') . '/' . ltrim($uri, '/');
    }
}

if (!function_exists('public_admission_form_url')) {
    /**
     * The public admission-form URL a parent scans/opens for a given school.
     * One place builds this so the poster, the config-page QR, and any share
     * link all agree. $ref is a readable slug when configured (preferred —
     * `…/admission/form/st-marys-school`) else the raw school id (fallback,
     * always works). Hyphens/underscores survive rawurlencode unchanged.
     */
    function public_admission_form_url(string $ref): string
    {
        return public_base_url('admission/form/' . rawurlencode($ref));
    }
}

if (!function_exists('admission_slugify')) {
    /**
     * Turn a school display name into a clean, readable URL slug
     * (lowercase, ascii, hyphen-separated, ≤60 chars). Best-effort accent
     * transliteration without the intl extension. Returns '' if nothing
     * usable remains (caller falls back to the school id).
     *
     *   "St. Mary's Public School"  →  "st-marys-public-school"
     */
    function admission_slugify(string $name): string
    {
        $s = trim($name);
        // Best-effort accent → ascii (é→e, ñ→n) before we lowercase.
        if ($s !== '' && function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($t !== false) $s = $t;
        }
        $s = strtolower($s);
        // Drop apostrophes / quotes / dots so "St. Mary's" → "st marys"
        // (a hyphen there would read "st-mary-s"). Runs AFTER translit because
        // TRANSLIT can emit apostrophes for accented letters (é → 'e).
        $s = str_replace(["'", "\xE2\x80\x99", '`', '"', '.'], '', $s);
        // Everything else that isn't a-z0-9 collapses to a single hyphen.
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim((string) $s, '-');
        if (strlen($s) > 60) {
            $s = rtrim(substr($s, 0, 60), '-');
        }
        return $s;
    }
}

if (!function_exists('_school_logo_allowed_host')) {
    /**
     * The EXACT Firebase Storage host ZenXii's logo URLs are generated on
     * (Firebase::getDownloadUrl → firebasestorage.googleapis.com; verified
     * against live tenant data). This is the SSRF host allowlist — any other
     * host (localhost, 127.x, ::1, 169.254.169.254 metadata, private IPs, or a
     * different domain) is rejected because it does not equal this literal.
     */
    function _school_logo_allowed_host(): string
    {
        return 'firebasestorage.googleapis.com';
    }
}

if (!function_exists('remote_image_data_uri')) {
    /**
     * SSRF-hardened remote image fetch → `data:` URI (or '' on any failure).
     * Used only to embed a school's Firebase Storage logo into an Admission PDF.
     *
     * Controls (Phase 4 / M4 hardening):
     *   A. HTTPS only.                       E. Redirects DISABLED (Firebase
     *   B. TLS verification ON (peer+host).     returns 200 directly — verified).
     *   C. Exact host allowlist.             F. Max size enforced DURING transfer.
     *   D. SSRF: non-allowlisted host → ''.  G. Real-image validation (not CT).
     *   H. Bounded connect + total timeouts. I. No URL/token in logs.
     *
     * Returns '' on any failure → callers omit the logo and the PDF still renders.
     */
    function remote_image_data_uri(string $url): string
    {
        $parts = @parse_url($url);
        if (!is_array($parts)) return '';
        if (($parts['scheme'] ?? '') !== 'https') return '';                 // A
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host !== _school_logo_allowed_host()) {                          // C + D
            // I. log the host ONLY — never the full URL (it carries the token).
            log_message('error', 'remote_image_data_uri: blocked non-allowlisted host=' . $host);
            return '';
        }
        if (!function_exists('curl_init')) return '';

        $maxBytes = 3 * 1024 * 1024;   // logos are <=2MB on upload; 3MB headroom
        $buf = '';
        $tooBig = false;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,                 // E
            CURLOPT_SSL_VERIFYPEER => true,                  // B
            CURLOPT_SSL_VERIFYHOST => 2,                     // B
            CURLOPT_CONNECTTIMEOUT => 3,                     // H
            CURLOPT_TIMEOUT        => 8,                     // H
            CURLOPT_MAXFILESIZE    => $maxBytes,             // F (Content-Length guard)
            CURLOPT_WRITEFUNCTION  => function ($c, $chunk) use (&$buf, &$tooBig, $maxBytes) {
                $buf .= $chunk;
                if (strlen($buf) > $maxBytes) { $tooBig = true; return 0; }  // F (in-transfer abort)
                return strlen($chunk);
            },
        ]);
        if (defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);            // A (belt-and-braces)
        }
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($tooBig || $code < 200 || $code >= 300 || $buf === '' || strlen($buf) > $maxBytes) return '';

        // G. Validate the BYTES are a real supported image — do not trust headers.
        $mime = '';
        if (function_exists('finfo_open')) {
            $fi   = finfo_open(FILEINFO_MIME_TYPE);
            $mime = (string) finfo_buffer($fi, $buf);
            finfo_close($fi);
        }
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) return '';
        if (function_exists('getimagesizefromstring') && @getimagesizefromstring($buf) === false) return '';

        return 'data:' . $mime . ';base64,' . base64_encode($buf);
    }
}

if (!function_exists('school_logo_cache_key')) {
    /**
     * Tenant-safe, URL-versioned cache key for a school logo data-URI.
     * schoolId isolates tenants; sha1(logoUrl) versions it — a new upload mints
     * a new Storage URL/token, so the key auto-changes (automatic invalidation).
     */
    function school_logo_cache_key(string $schoolId, string $logoUrl): string
    {
        return 'admlogo_' . preg_replace('/[^A-Za-z0-9_\-]/', '', $schoolId) . '_' . sha1($logoUrl);
    }
}

if (!function_exists('cached_school_logo_data_uri')) {
    /**
     * Disposable server-side cache (CI file-cache driver — the same production
     * mechanism used by Attendance/NoticeAnnouncement) around the hardened
     * remote fetch. Firebase Storage stays the source of truth; Firestore keeps
     * only logoUrl; this cache is purely a PDF-render optimization and may be
     * deleted at any time with zero data loss.
     *
     * Contract: returns a validated `data:image/...` URI, or '' — never throws,
     * never blocks the render on cache failure.
     */
    function cached_school_logo_data_uri(string $schoolId, string $logoUrl): string
    {
        $schoolId = trim($schoolId);
        $logoUrl  = trim($logoUrl);
        if ($schoolId === '' || $logoUrl === '') return '';

        $key = school_logo_cache_key($schoolId, $logoUrl);

        $cache = null;
        try {
            $CI =& get_instance();
            $CI->load->driver('cache', ['adapter' => 'file']);
            $cache = $CI->cache;
        } catch (\Throwable $e) {
            // Cache subsystem unavailable → direct (uncached) fetch; never fail.
            return remote_image_data_uri($logoUrl);
        }

        // CACHE HIT — trust only a well-formed data:image value (corruption-safe).
        try {
            $hit = $cache->get($key);
            if (is_string($hit) && strpos($hit, 'data:image/') === 0) return $hit;
        } catch (\Throwable $e) { /* treat as miss */ }

        // CACHE MISS — canonical hardened fetch. Cache ONLY a valid result;
        // a fetch failure is NOT cached (transient Storage errors self-heal).
        $dataUri = remote_image_data_uri($logoUrl);
        if ($dataUri !== '' && strpos($dataUri, 'data:image/') === 0) {
            try { $cache->save($key, $dataUri, 7 * 24 * 3600); } catch (\Throwable $e) { /* non-fatal */ }
        }
        return $dataUri;
    }
}

if (!function_exists('ensure_admission_slug')) {
    /**
     * Ensure a school has a readable, globally-unique public admission slug and
     * return it (''=could not set). Self-healing + idempotent — safe to call on
     * every admission-page / poster load: it only WRITES when the slug is
     * missing, so it doesn't matter WHICH save path first set the school name
     * (School Config tab, the School Profile page, or SA onboarding).
     *
     * Writes two places via the RAW firebase client (global collection keyed by
     * slug — a tenant-scoped writer would prefix the doc id, which is wrong):
     *   - schools/{id}.admissionSlug          (display + reverse ref)
     *   - admissionSlugs/{slug} = {schoolId}   (the resolver map)
     *
     * The slug is STABLE once set: a later rename keeps the existing slug (and
     * self-heals its map entry) so already-printed QR posters never break.
     *
     * @param object $firebase  raw Firebase library ($this->firebase) — has firestoreGet/Set
     */
    function ensure_admission_slug($firebase, string $schoolId, string $displayName): string
    {
        if (!is_object($firebase) || $schoolId === '') return '';

        // Keep an existing slug stable — only self-heal its map entry.
        $existing = '';
        try {
            $doc = $firebase->firestoreGet('schools', $schoolId);
            $existing = trim((string) (($doc['admissionSlug'] ?? '')));
        } catch (\Throwable $e) { /* (re)generate below */ }

        if ($existing !== '') {
            _admission_slug_claim($firebase, $existing, $schoolId);
            return $existing;
        }

        $base = admission_slugify($displayName);
        if ($base === '') {
            $base = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $schoolId)) ?: 'school';
        }
        $slug = _admission_slug_unique($firebase, $base, $schoolId);

        try {
            $firebase->firestoreSet('schools', $schoolId, ['admissionSlug' => $slug], true);
        } catch (\Throwable $e) {
            log_message('error', 'ensure_admission_slug: schools write failed for ' . $schoolId . ' — ' . $e->getMessage());
            return '';
        }
        _admission_slug_claim($firebase, $slug, $schoolId);
        return $slug;
    }
}

if (!function_exists('_admission_slug_unique')) {
    /** First free slug (base, base-2, base-3, …) not owned by another school. */
    function _admission_slug_unique($firebase, string $base, string $schoolId): string
    {
        $reserved = ['form','submit','pay','choose_offline','payment_callback','payment_webhook','payment_status','receipt','status','admin'];
        if (in_array($base, $reserved, true)) $base .= '-school';

        for ($i = 0; $i < 50; $i++) {
            $try = $i === 0 ? $base : $base . '-' . ($i + 1);
            $map = null;
            try { $map = $firebase->firestoreGet('admissionSlugs', $try); } catch (\Throwable $e) { $map = null; }
            if (!is_array($map) || empty($map['schoolId']) || $map['schoolId'] === $schoolId) {
                return $try;
            }
        }
        return $base . '-' . strtolower(substr(preg_replace('/[^A-Za-z0-9]/', '', $schoolId), -6));
    }
}

if (!function_exists('_admission_slug_claim')) {
    /** Write/refresh the global slug→schoolId resolver entry (merge). */
    function _admission_slug_claim($firebase, string $slug, string $schoolId): void
    {
        try {
            $firebase->firestoreSet('admissionSlugs', $slug, [
                'schoolId'  => $schoolId,
                'slug'      => $slug,
                'updatedAt' => date('c'),
            ], true);
        } catch (\Throwable $e) {
            log_message('error', 'admissionSlugs: claim failed for ' . $slug . ' — ' . $e->getMessage());
        }
    }
}
