<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Doc_rows — normalise Firestore query results at the seam.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------
 * `Firestore_service::where()` / `schoolWhere()` return a LIST OF ENVELOPES:
 *
 *     [ ['id' => 'SCH1_TPL0001', 'data' => [...fields...]], ... ]
 *
 * Every consumer in the Document Engine was written against a different shape —
 * a map of `docId => fields` — because that is the shape the unit-test doubles
 * returned. The doubles were built to match the assumption instead of the
 * database, so nothing failed until the first real query ran, and then it failed
 * silently: `$row['activeVersion']` is simply absent on an envelope, so
 * `activate()` would never displace an incumbent and `Doc_resolver` would never
 * find an active template. No error, no log — the wrong answer, confidently.
 *
 * Normalising HERE rather than in each consumer means there is one place to be
 * right, and the shape is asserted by DocRowsTest against a real captured
 * response rather than against another assumption.
 */
class Doc_rows
{
    /**
     * Envelope list → `docId => fields`, with `_id` folded in.
     *
     * Tolerant of both shapes on purpose: this is called from store adapters
     * that tests replace with doubles already producing the map form, and a
     * normaliser that breaks the doubles would just move the problem.
     *
     * @param mixed $raw whatever the query returned
     * @return array<string,array>
     */
    public static function map($raw): array
    {
        if (!is_array($raw) || !$raw) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $row) {
            if (!is_array($row)) {
                continue;
            }

            // The envelope form the REST client actually returns.
            if (isset($row['id']) && isset($row['data']) && is_array($row['data'])) {
                $id = (string) $row['id'];
                $out[$id] = $row['data'] + ['_id' => $id];
                continue;
            }

            // Already a map of docId => fields (test doubles, and any caller
            // that normalised earlier).
            $id = is_string($key) ? $key : (string) ($row['_id'] ?? $row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[$id] = $row + ['_id' => $id];
        }
        return $out;
    }
}
