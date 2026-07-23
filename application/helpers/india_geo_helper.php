<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * india_geo_helper — server-side access to the canonical India states/districts
 * dataset. Reads the SAME file the front-end forms use
 * (assets/data/india_geo.json), so there is a single source of truth.
 *
 * Used by the staff bulk-import pipeline to canonicalize / soft-validate the
 * State and District (City) columns, and by the template builder to populate
 * the State→District cascading dropdowns.
 */

if (!function_exists('india_geo_data')) {
    /** Decoded dataset (cached per-request): ['states'=>[...], 'districts'=>[state=>[...]]]. */
    function india_geo_data(): array
    {
        static $data = null;
        if ($data === null) {
            $path = (defined('FCPATH') ? FCPATH : __DIR__ . '/../../') . 'assets/data/india_geo.json';
            $json = is_file($path) ? @file_get_contents($path) : '';
            $decoded = $json ? json_decode($json, true) : null;
            $data = is_array($decoded) ? $decoded : ['states' => [], 'districts' => []];
        }
        return $data;
    }
}

if (!function_exists('india_geo_states')) {
    /** Ordered list of all 36 states/UTs. */
    function india_geo_states(): array
    {
        $d = india_geo_data();
        return isset($d['states']) && is_array($d['states']) ? $d['states'] : [];
    }
}

if (!function_exists('_india_geo_norm')) {
    /** Loose comparison key: lowercase, collapse spaces, '&'→'and', drop dots. */
    function _india_geo_norm($s): string
    {
        $s = strtolower(trim((string) $s));
        $s = str_replace('&', 'and', $s);
        $s = str_replace('.', '', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }
}

if (!function_exists('india_geo_match_state')) {
    /**
     * Resolve a raw state string to its canonical spelling, or '' if unknown.
     * Case/spacing-insensitive, with a small alias table for legacy names.
     */
    function india_geo_match_state($raw): string
    {
        $raw = trim((string) $raw);
        if ($raw === '') return '';
        $n = _india_geo_norm($raw);

        static $alias = [
            'pondicherry'                   => 'Puducherry',
            'orissa'                        => 'Odisha',
            'uttaranchal'                   => 'Uttarakhand',
            'nct of delhi'                  => 'Delhi',
            'new delhi'                     => 'Delhi',
            'delhi ncr'                     => 'Delhi',
            'jammu kashmir'                 => 'Jammu and Kashmir',
            'j and k'                       => 'Jammu and Kashmir',
            'jk'                            => 'Jammu and Kashmir',
            'andaman and nicobar'           => 'Andaman and Nicobar Islands',
            'andamans'                      => 'Andaman and Nicobar Islands',
            'dadra and nagar haveli'        => 'Dadra and Nagar Haveli and Daman and Diu',
            'daman and diu'                 => 'Dadra and Nagar Haveli and Daman and Diu',
        ];
        if (isset($alias[$n])) return $alias[$n];

        $nns = str_replace(' ', '', $n); // space-insensitive fallback ("tamilnadu")
        foreach (india_geo_states() as $s) {
            $sn = _india_geo_norm($s);
            if ($sn === $n || str_replace(' ', '', $sn) === $nns) return $s;
        }
        return '';
    }
}

if (!function_exists('india_geo_districts')) {
    /** Districts for a state (accepts any spelling the matcher recognizes). */
    function india_geo_districts($state): array
    {
        $st = india_geo_match_state($state);
        if ($st === '') return [];
        $d = india_geo_data();
        return isset($d['districts'][$st]) && is_array($d['districts'][$st]) ? $d['districts'][$st] : [];
    }
}

if (!function_exists('india_geo_match_district')) {
    /** Resolve a raw district within a state to its canonical spelling, or ''. */
    function india_geo_match_district($state, $raw): string
    {
        $raw = trim((string) $raw);
        if ($raw === '') return '';
        $n = _india_geo_norm($raw);
        $nns = str_replace(' ', '', $n);
        foreach (india_geo_districts($state) as $dist) {
            $dn = _india_geo_norm($dist);
            if ($dn === $n || str_replace(' ', '', $dn) === $nns) return $dist;
        }
        return '';
    }
}

if (!function_exists('india_geo_is_valid_state')) {
    function india_geo_is_valid_state($state): bool
    {
        return india_geo_match_state($state) !== '';
    }
}
