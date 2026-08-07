<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admission CRM search helper (C7).
 *
 * Canonical, single-source tokenizer for admission student-name search. The
 * crmApplications `searchTokens` array field is populated ONLY by this function
 * at every name-writing site, and the CRM search box matches a normalized query
 * against it via Firestore `array-contains`. Application-ID and phone searches
 * do NOT use this — they hit the exact-equality `application_id` / `phone_norm`
 * fields. Per the approved C7 design, tokens are derived from the STUDENT NAME
 * only (never parent/phone/email).
 *
 * The function is deterministic and idempotent: the same input always yields the
 * same token set, so re-running the backfill or re-saving a record never drifts.
 */

if (!function_exists('admission_search_norm')) {
    /**
     * Normalize a name/query the SAME way on both the write side (token
     * generation) and the read side (query building) so they always agree:
     * Unicode-lowercase, fold every run of non-letter/non-digit characters
     * (spaces, punctuation, symbols) to a single space, then trim.
     *
     * @param string $s
     * @return string normalized string ('' if nothing usable)
     */
    function admission_search_norm($s)
    {
        $s = is_string($s) ? $s : '';
        $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
        // Fold anything that is not a Unicode letter or digit to a space. This
        // splits hyphen/apostrophe names ("al-rashid" -> "al rashid") while the
        // concatenated variant below still covers separator-less queries.
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }
}

if (!function_exists('admission_search_tokens')) {
    /**
     * Build the prefix-token set for a student name.
     *
     * Generates lowercase prefixes (length MIN..MAX) of:
     *   - each individual word  ("rajesh", "kumar")           -> word-prefix search
     *   - the whole name with separators removed ("rajeshkumar") -> multi-word / no-space search
     * De-duplicated and capped at MAX_TOKEN_COUNT to bound document size and the
     * per-write index cost. Per-word prefixes are emitted first so that, if the
     * cap truncates, the more important word matches survive over the tail of the
     * concatenated variant.
     *
     * @param string $name student full name
     * @return string[] unique prefix tokens ([] for blank / sub-minimum input)
     */
    function admission_search_tokens($name)
    {
        $MIN = 2;   // MIN_PREFIX_LENGTH  — 1-char prefixes match too much
        $MAX = 12;  // MAX_PREFIX_LENGTH  — cap per-token length / write cost
        $CAP = 40;  // MAX_TOKEN_COUNT    — hard bound on tokens per document

        $norm = admission_search_norm($name);
        if ($norm === '') {
            return [];
        }

        $tokens = []; // assoc set for O(1) dedupe, insertion-ordered

        $addPrefixes = function ($str) use (&$tokens, $MIN, $MAX) {
            $len = function_exists('mb_strlen') ? mb_strlen($str, 'UTF-8') : strlen($str);
            if ($len < $MIN) {
                return; // never emit sub-MIN tokens (e.g. lone initials)
            }
            $upper = $len < $MAX ? $len : $MAX;
            for ($i = $MIN; $i <= $upper; $i++) {
                $pfx = function_exists('mb_substr') ? mb_substr($str, 0, $i, 'UTF-8') : substr($str, 0, $i);
                $tokens[$pfx] = true;
            }
        };

        // 1) Per-word prefixes (primary — how most searches are typed).
        foreach (explode(' ', $norm) as $word) {
            if ($word !== '') {
                $addPrefixes($word);
            }
        }
        // 2) Concatenated-name prefixes (covers "rajeshkumar" / paste-with-no-space).
        $joined = str_replace(' ', '', $norm);
        $addPrefixes($joined);

        // array_keys() coerces integer-like keys ("12") back to ints; force
        // every token to a string so Firestore stores a homogeneous string
        // array and `array-contains` string matching works for numeric names.
        $out = array_map('strval', array_keys($tokens));
        if (count($out) > $CAP) {
            $out = array_slice($out, 0, $CAP);
        }
        return $out;
    }
}

if (!function_exists('admission_search_query_token')) {
    /**
     * Reduce a raw search query to the single `array-contains` token used against
     * `searchTokens`. Normalizes identically to the write side, strips spaces
     * (so "john smith" -> "johnsmith" hits the concatenated prefixes), and clamps
     * to MAX_PREFIX_LENGTH so an over-long query still matches the stored prefix.
     *
     * @param string $query raw user input
     * @return string normalized token ('' if unusable — caller should skip token search)
     */
    function admission_search_query_token($query)
    {
        $MAX = 12;
        $norm = admission_search_norm($query);
        if ($norm === '') {
            return '';
        }
        $joined = str_replace(' ', '', $norm);
        $len = function_exists('mb_strlen') ? mb_strlen($joined, 'UTF-8') : strlen($joined);
        if ($len < 2) {
            return '';
        }
        if ($len > $MAX) {
            $joined = function_exists('mb_substr') ? mb_substr($joined, 0, $MAX, 'UTF-8') : substr($joined, 0, $MAX);
        }
        return $joined;
    }
}
