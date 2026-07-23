<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Staff_import_mapper — turns an arbitrary uploaded sheet into canonical staff
 * rows, regardless of the school's column names / order / extra columns.
 *
 * Pipeline (see STAFF_IMPORT_REDESIGN.md):
 *   resolve_headers()  – map each uploaded column to a canonical field via
 *                        exact-label / alias / fuzzy match
 *   map_row()          – read each row by resolved column (tolerant of missing
 *                        or extra columns) and apply per-field transforms
 *   validate_row()     – required / format / enum checks, driven by the schema
 *
 * The core methods are pure & static (schema passed in) so they can be unit
 * tested from the CLI without bootstrapping CodeIgniter. The instance wrappers
 * load the schema from config/staff_import_schema.php.
 */
class Staff_import_mapper {

    /** @var array canonical field schema (keyed by canonical key) */
    protected $fields = [];

    /** Fuzzy-match acceptance threshold (percent), via similar_text(). */
    const FUZZY_THRESHOLD = 82.0;

    public function __construct($params = [])
    {
        if (!empty($params['fields']) && is_array($params['fields'])) {
            $this->fields = $params['fields'];
            return;
        }
        $ci =& get_instance();
        $ci->config->load('staff_import_schema', TRUE);
        $loaded = $ci->config->item('staff_import_fields', 'staff_import_schema');
        $this->fields = is_array($loaded) ? $loaded : [];
    }

    public function fields()            { return $this->fields; }
    public function resolveHeaders($h)  { return self::resolve_headers($h, $this->fields); }
    public function mapRow($r, $res)    { return self::map_row($r, $res, $this->fields); }
    public function validateRow($m)     { return self::validate_row($m, $this->fields); }

    /** [{key,label,required}] — drives the mapping-UI field dropdowns + chips. */
    public function fieldsForUi(): array
    {
        $out = [];
        foreach ($this->fields as $key => $def) {
            $out[] = [
                'key'      => $key,
                'label'    => $def['label'] ?? $key,
                'required' => !empty($def['required']),
            ];
        }
        return $out;
    }

    /** colIndex => fieldKey|null — best-guess auto-mapping for every column. */
    public function autoMap(array $headers): array
    {
        $res = self::resolve_headers($headers, $this->fields);
        $map = [];
        foreach ($headers as $i => $_) {
            $map[$i] = $res['col_to_field'][$i] ?? null;
        }
        return $map;
    }

    /**
     * Validate a row keyed by canonical field KEY (sent by the mapping UI) and
     * return {data, errors, warnings, status}. `data` is the normalized values
     * keyed by field key for the preview table. Role resolution + dedupe are
     * layered on by the controller (they need Firestore / role config).
     */
    public function validateCanonical(array $rowByKey): array
    {
        $labelRow = [];
        $data     = [];
        foreach ($this->fields as $key => $def) {
            $raw       = $rowByKey[$key] ?? '';
            $transform = $def['transform'] ?? 'trim';
            $val       = self::transform($raw, $transform);
            // Keep an unparseable non-empty date as raw text so it flags as
            // "invalid date" rather than a misleading "required".
            if ($transform === 'date' && $val === '' && trim((string) $raw) !== '') {
                $val = trim((string) $raw);
            }
            if ($val === '' && isset($def['default'])) {
                $val = (string) $def['default'];
            }
            $data[$key]              = $val;
            $labelRow[$def['label'] ?? $key] = $val;
        }
        $v = self::validate_row($labelRow, $this->fields);
        $status = !empty($v['errors']) ? 'error' : (!empty($v['warnings']) ? 'warning' : 'ok');
        return [
            'data'     => $data,
            'errors'   => $v['errors'],
            'warnings' => $v['warnings'],
            'status'   => $status,
        ];
    }

    // ── pure static core (CLI-testable) ──────────────────────────────────

    /** Normalize a header for matching: lowercase, strip punctuation, squeeze spaces. */
    public static function normalize($h)
    {
        $h = strtolower(trim((string) $h));
        $h = preg_replace('/[^a-z0-9]+/', ' ', $h);  // punctuation/underscores → space
        return trim(preg_replace('/\s+/', ' ', $h));
    }

    /**
     * Resolve uploaded headers → canonical fields.
     * Returns:
     *   field_to_col : [canonicalKey => columnIndex]   (the winning match per field)
     *   col_to_field : [columnIndex => canonicalKey]
     *   col_to_label : [columnIndex => canonicalLabel]
     *   unmapped     : [columnIndex => originalHeader]  (columns we ignored)
     *   fuzzy        : [canonicalKey => originalHeader] (matched by fuzzy, for UI hinting)
     */
    public static function resolve_headers(array $headers, array $fields)
    {
        // Build normalized lookup: candidate string → canonicalKey (label + aliases)
        $lookup = [];
        foreach ($fields as $key => $def) {
            $cands = array_merge([$def['label'] ?? $key], $def['aliases'] ?? []);
            foreach ($cands as $c) {
                $lookup[self::normalize($c)] = $key;
            }
        }

        $field_to_col = [];
        $col_to_field = [];
        $col_to_label = [];
        $unmapped     = [];
        $fuzzy        = [];

        foreach ($headers as $i => $raw) {
            $norm = self::normalize($raw);
            if ($norm === '') { continue; }

            $matchKey = null;

            // 1) exact normalized match on label/alias
            if (isset($lookup[$norm])) {
                $matchKey = $lookup[$norm];
            } else {
                // 2) fuzzy: best similar_text over all candidates
                $best = 0.0; $bestKey = null;
                foreach ($lookup as $cand => $key) {
                    similar_text($norm, $cand, $pct);
                    if ($pct > $best) { $best = $pct; $bestKey = $key; }
                }
                if ($bestKey !== null && $best >= self::FUZZY_THRESHOLD) {
                    $matchKey = $bestKey;
                    $fuzzy[$bestKey] = $raw;
                }
            }

            // First column wins a field (ignore duplicate later columns).
            if ($matchKey !== null && !isset($field_to_col[$matchKey])) {
                $field_to_col[$matchKey]   = $i;
                $col_to_field[$i]          = $matchKey;
                $col_to_label[$i]          = $fields[$matchKey]['label'] ?? $matchKey;
            } else {
                // Unmatched header, or a duplicate column for an already-claimed
                // field. Only clear a fuzzy hint for a genuine duplicate match.
                $unmapped[$i] = $raw;
                if ($matchKey !== null) {
                    unset($fuzzy[$matchKey]);
                }
            }
        }

        return compact('field_to_col', 'col_to_field', 'col_to_label', 'unmapped', 'fuzzy');
    }

    /**
     * Map one raw row → canonical assoc keyed by field LABEL (so existing
     * import code that reads $rowData['Name'] keeps working). Tolerant of rows
     * shorter/longer than the header list. Transforms are applied here.
     */
    public static function map_row(array $row, array $resolved, array $fields)
    {
        $out = [];
        foreach ($resolved['field_to_col'] as $key => $col) {
            $label     = $fields[$key]['label'] ?? $key;
            $raw       = array_key_exists($col, $row) ? $row[$col] : '';
            $transform = $fields[$key]['transform'] ?? 'trim';
            $val       = self::transform($raw, $transform);

            // Keep an unparseable-but-non-empty date as its raw text, so
            // validate_row reports "invalid date" instead of a misleading
            // "required". A valid date is already normalized to d-m-Y here.
            if ($transform === 'date' && $val === '' && trim((string) $raw) !== '') {
                $val = trim((string) $raw);
            }

            if ($val === '' && isset($fields[$key]['default'])) {
                $val = (string) $fields[$key]['default'];
            }
            $out[$label] = $val;
        }
        // Cross-field India geo normalization (needs the whole row, not one cell).
        self::canon_geo($out, $fields);
        return $out;
    }

    /** Correct District spelling once the row's State is known — a cross-field
     *  pass the per-cell transforms can't do. Silent; validate_row surfaces misses. */
    private static function canon_geo(array &$out, array $fields): void
    {
        if (!function_exists('india_geo_match_district')) return;
        foreach ([['state', 'city'], ['perm_state', 'perm_city']] as $pair) {
            list($sk, $ck) = $pair;
            if (!isset($fields[$sk], $fields[$ck])) continue;
            $sl = $fields[$sk]['label'] ?? $sk;
            $cl = $fields[$ck]['label'] ?? $ck;
            $state = $out[$sl] ?? '';
            $city  = $out[$cl] ?? '';
            if ($state === '' || $city === '') continue;
            $canon = india_geo_match_district($state, $city);
            if ($canon !== '') $out[$cl] = $canon;
        }
    }

    /** Apply a single transform to a raw cell value. */
    public static function transform($value, $transform)
    {
        $value = trim((string) $value);
        switch ($transform) {
            case 'digits_only':
                return preg_replace('/\D+/', '', $value);
            case 'upper':
                return strtoupper($value);
            case 'numeric':
                // strip thousands separators / currency, keep digits + dot + minus
                $v = preg_replace('/[^0-9.\-]/', '', $value);
                return $v;
            case 'date':
                return self::normalize_date($value);
            case 'india_state':
                // Canonicalize an India state/UT spelling; keep as typed if unknown
                // (validate_row then flags the off-enum value as a warning).
                if (function_exists('india_geo_match_state')) {
                    $canon = india_geo_match_state($value);
                    if ($canon !== '') return $canon;
                }
                return $value;
            case 'trim':
            default:
                return $value;
        }
    }

    /**
     * Normalize a date to canonical d-m-Y. Handles DD-MM-YYYY, YYYY-MM-DD,
     * DD/MM/YYYY and a few separators explicitly (avoids strtotime's ambiguous
     * guessing). Returns '' if unparseable, so validation can flag it.
     */
    public static function normalize_date($value)
    {
        $value = trim((string) $value);
        if ($value === '') { return ''; }

        // 4-digit-year formats first. Reject a 2-digit year that slipped into a
        // 'Y' slot (createFromFormat is lenient: "05-06-99" would parse as year
        // 0099) by requiring a year >= 1000 — otherwise it falls through to the
        // 2-digit handling below for a correct pivot.
        $fourDigit = ['d-m-Y', 'Y-m-d', 'd/m/Y', 'Y/m/d', 'd.m.Y', 'd M Y', 'd-M-Y', 'j F Y'];
        foreach ($fourDigit as $fmt) {
            $dt = DateTime::createFromFormat('!' . $fmt, $value);
            if ($dt instanceof DateTime) {
                $errs = DateTime::getLastErrors();
                if (empty($errs['warning_count']) && empty($errs['error_count'])
                    && (int) $dt->format('Y') >= 1000) {
                    return $dt->format('d-m-Y');
                }
            }
        }

        // Two-digit-year formats. PHP's 'y' pivots 00–69 → 2000s, 70–99 → 1900s,
        // so "05-06-99" → 1999 (not 0099).
        $twoDigit = ['d-m-y', 'd/m/y', 'd.m.y'];
        foreach ($twoDigit as $fmt) {
            $dt = DateTime::createFromFormat('!' . $fmt, $value);
            if ($dt instanceof DateTime) {
                $errs = DateTime::getLastErrors();
                if (empty($errs['warning_count']) && empty($errs['error_count'])) {
                    return $dt->format('d-m-Y');
                }
            }
        }
        return ''; // unparseable
    }

    /**
     * Validate a mapped row against the schema. Returns
     *   ['errors' => [...], 'warnings' => [...]]
     * Only a MISSING REQUIRED field is an error (blocks the row). A present-but-
     * malformed value (bad date/email/phone/number, off-enum) is a WARNING — the
     * row still imports and the admin can fix the value later. Role is resolved
     * separately by the controller.
     */
    public static function validate_row(array $mapped, array $fields)
    {
        $errors   = [];
        $warnings = [];
        foreach ($fields as $key => $def) {
            $label = $def['label'] ?? $key;
            $val   = isset($mapped[$label]) ? trim((string) $mapped[$label]) : '';

            if ($val === '') {
                if (!empty($def['required'])) {
                    $errors[] = "{$label} is required";
                }
                continue; // optional + empty → fine
            }

            if (($def['transform'] ?? '') === 'date' && self::normalize_date($val) === '') {
                $warnings[] = "{$label} " . ($def['error'] ?? 'is not a valid date') . " — left as typed";
                continue;
            }

            if (!empty($def['enum'])) {
                $ok = false;
                foreach ($def['enum'] as $opt) {
                    if (strcasecmp($val, $opt) === 0) { $ok = true; break; }
                }
                if (!$ok) {
                    $warnings[] = "{$label} should be one of: " . implode(', ', $def['enum']);
                }
            }

            if (!empty($def['validate'])) {
                $rule = $def['validate'];
                $bad  = false;
                if ($rule === 'email') {
                    $bad = !filter_var($val, FILTER_VALIDATE_EMAIL);
                } elseif ($rule === 'numeric') {
                    $bad = !is_numeric($val);
                } else { // PCRE
                    $bad = !preg_match($rule, $val);
                }
                if ($bad) {
                    $warnings[] = "{$label} " . ($def['error'] ?? 'looks invalid') . " (got \"{$val}\")";
                }
            }
        }

        // ── India geo cross-field checks (State / District) ──────────────────
        if (function_exists('india_geo_match_state')) {
            foreach ([['state', 'city'], ['perm_state', 'perm_city']] as $pair) {
                list($sk, $ck) = $pair;
                if (!isset($fields[$sk])) continue;
                $sl   = $fields[$sk]['label'] ?? $sk;
                $sVal = isset($mapped[$sl]) ? trim((string) $mapped[$sl]) : '';
                if ($sVal === '') continue;
                if (india_geo_match_state($sVal) === '') {
                    $warnings[] = "{$sl} \"{$sVal}\" is not a recognized India state/UT — left as typed";
                    continue; // can't check the district against an unknown state
                }
                if (isset($fields[$ck])) {
                    $cl   = $fields[$ck]['label'] ?? $ck;
                    $cVal = isset($mapped[$cl]) ? trim((string) $mapped[$cl]) : '';
                    if ($cVal !== '' && india_geo_match_district($sVal, $cVal) === '') {
                        $warnings[] = "{$cl} \"{$cVal}\" is not a listed district of {$sVal} — left as typed";
                    }
                }
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }
}
