<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Import_schema — single source of truth for the bulk student import.
 *
 * One canonical field set drives: the downloadable template, the header
 * auto-mapping (so any school's column names/order are accepted), the
 * value-normalization layer (dates, class, section, gender, phone …) and
 * the per-row validation used by the dry-run preview.
 *
 * Canonical field KEYS are deliberately identical to the legacy template
 * header names ("Name", "Class", "Section", "DOB", …) so that a validated
 * row drops straight into Sis::_runStudentImport() with no extra mapping.
 *
 * Added 2026-06-12 (Phase 2 — flexible importer).
 */
class Import_schema
{
    /**
     * Canonical field definitions.
     *   required : blocks the row in validation when empty
     *   norm     : normalizer key (see normalizeValue)
     *   synonyms : alternate header spellings used by auto-mapping
     */
    private function defs(): array
    {
        return [
            'Name'              => ['label' => 'Student Name',   'required' => true,  'norm' => 'text',
                'synonyms' => ['student name', 'full name', 'name of student', 'pupil name', 'child name', 'student']],
            'Class'             => ['label' => 'Class',          'required' => true,  'norm' => 'class',
                'synonyms' => ['grade', 'std', 'standard', 'class name', 'classname', 'class grade', 'cls']],
            'Section'           => ['label' => 'Section',        'required' => true,  'norm' => 'section',
                'synonyms' => ['sec', 'division', 'div', 'class section']],
            'DOB'               => ['label' => 'Date of Birth',  'required' => false, 'norm' => 'date',
                'synonyms' => ['dob', 'date of birth', 'birth date', 'birthdate', 'd o b', 'born', 'dateofbirth']],
            'Admission Date'    => ['label' => 'Admission Date', 'required' => false, 'norm' => 'date',
                'synonyms' => ['date of admission', 'doa', 'joining date', 'enrolled on', 'admission', 'admission date']],
            'Gender'            => ['label' => 'Gender',         'required' => false, 'norm' => 'gender',
                'synonyms' => ['sex']],
            'Blood Group'       => ['label' => 'Blood Group',    'required' => false, 'norm' => 'blood',
                'synonyms' => ['blood', 'bloodgroup', 'blood type']],
            'Category'          => ['label' => 'Category',       'required' => false, 'norm' => 'text',
                'synonyms' => ['caste category', 'social category', 'caste']],
            'Religion'          => ['label' => 'Religion',       'required' => false, 'norm' => 'text',
                'synonyms' => []],
            'Nationality'       => ['label' => 'Nationality',    'required' => false, 'norm' => 'text',
                'synonyms' => ['citizenship']],
            'Father Name'       => ['label' => 'Father Name',    'required' => false, 'norm' => 'text',
                'synonyms' => ['fathers name', 'father', 'father full name', 'f name', 'father name']],
            'Father Occupation' => ['label' => 'Father Occupation', 'required' => false, 'norm' => 'text',
                'synonyms' => ['fathers occupation', 'father profession']],
            'Mother Name'       => ['label' => 'Mother Name',    'required' => false, 'norm' => 'text',
                'synonyms' => ['mothers name', 'mother', 'mother full name', 'm name', 'mother name']],
            'Mother Occupation' => ['label' => 'Mother Occupation', 'required' => false, 'norm' => 'text',
                'synonyms' => ['mothers occupation', 'mother profession']],
            'Guard Contact'     => ['label' => 'Guardian Contact', 'required' => false, 'norm' => 'phone',
                'synonyms' => ['guardian contact', 'guardian phone', 'guardian mobile', 'guardian number', 'emergency contact', 'guard contact']],
            'Guard Relation'    => ['label' => 'Guardian Relation', 'required' => false, 'norm' => 'text',
                'synonyms' => ['guardian relation', 'relation', 'relationship', 'guardian relationship', 'guard relation']],
            'Phone Number'      => ['label' => 'Phone Number',   'required' => false, 'norm' => 'phone',
                'synonyms' => ['phone', 'mobile', 'mobile number', 'contact', 'contact number', 'phone no', 'mobile no', 'cell', 'primary contact', 'parent contact', 'parent mobile']],
            'Email'             => ['label' => 'Email',          'required' => false, 'norm' => 'email',
                'synonyms' => ['email address', 'e mail', 'mail', 'email id']],
            'Street'            => ['label' => 'Street / Address', 'required' => false, 'norm' => 'text',
                'synonyms' => ['address', 'address line', 'addr', 'house address', 'residential address', 'line1', 'street']],
            'City'              => ['label' => 'City',           'required' => false, 'norm' => 'text',
                'synonyms' => ['town', 'village', 'district']],
            'State'             => ['label' => 'State',          'required' => false, 'norm' => 'india_state',
                'synonyms' => ['province']],
            'PostalCode'        => ['label' => 'Postal Code',    'required' => false, 'norm' => 'text',
                'synonyms' => ['postal code', 'pincode', 'pin code', 'zip', 'zip code', 'postcode', 'pin']],
            'Pre School'        => ['label' => 'Previous School', 'required' => false, 'norm' => 'text',
                'synonyms' => ['previous school', 'prev school', 'last school', 'former school', 'school attended', 'pre school']],
            'Pre Class'         => ['label' => 'Previous Class', 'required' => false, 'norm' => 'text',
                'synonyms' => ['previous class', 'prev class', 'last class', 'class passed', 'pre class']],
            'Pre Marks'         => ['label' => 'Previous Marks', 'required' => false, 'norm' => 'text',
                'synonyms' => ['previous marks', 'last marks', 'marks', 'grade obtained', 'percentage', 'result', 'pre marks']],
        ];
    }

    /** Canonical field map (key => def). */
    public function fields(): array { return $this->defs(); }

    /** Lightweight field list for the mapping UI. */
    public function fieldsForUi(): array
    {
        $out = [];
        foreach ($this->defs() as $key => $d) {
            $out[] = ['key' => $key, 'label' => $d['label'] ?? $key, 'required' => !empty($d['required'])];
        }
        return $out;
    }

    /** Header row for the downloadable template. */
    public function templateHeaders(): array { return array_keys($this->defs()); }

    /** A single illustrative row aligned to templateHeaders(). */
    public function exampleRow(): array
    {
        return [
            'Aarav Sharma', '1', 'A', '2019-04-12', '2024-04-01', 'Male', 'B+', 'General', 'Hindu', 'Indian',
            'Rohit Sharma', 'Businessman', 'Priya Sharma', 'Homemaker', '9876500011', 'Father', '9876500011',
            'aarav.sharma@example.com', '12 Gandhi Road', 'Jaipur', 'Rajasthan', '302001',
            'Little Angels Playschool', 'Nursery', 'A',
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  AUTO-MAPPING
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Best-guess mapping of an uploaded file's headers onto canonical
     * field keys. Returns [columnIndex => fieldKey|null].
     *
     * Strategy: a school's previously-learned header→field choices ($learned,
     * keyed by normalizeHeaderKey) win first; then exact normalized match
     * against key/label/synonyms; then a fuzzy fallback (similar_text >= 82%).
     * Each canonical field is claimed at most once — the first/closest wins.
     *
     * @param array $learned [normalizedHeaderKey => fieldKey] from past imports.
     */
    public function autoMap(array $headers, array $learned = []): array
    {
        $cands = []; // normalized candidate => fieldKey
        foreach ($this->defs() as $key => $d) {
            $names = array_merge([$key, $d['label'] ?? $key], $d['synonyms'] ?? []);
            foreach ($names as $n) {
                $nn = $this->normHeader($n);
                if ($nn !== '' && !isset($cands[$nn])) $cands[$nn] = $key;
            }
        }

        // Re-key learned overrides through the same normalizer and keep only
        // those that name a real field.
        $valid    = $this->defs();
        $learnedN = [];
        foreach ($learned as $h => $fk) {
            $nh = $this->normHeader($h);
            if ($nh !== '' && is_string($fk) && isset($valid[$fk])) $learnedN[$nh] = $fk;
        }

        $used = [];
        $map  = array_fill(0, count($headers), null);

        // Pass 1 — a school's learned mappings take precedence.
        foreach ($headers as $i => $h) {
            $nh = $this->normHeader($h);
            if ($nh !== '' && isset($learnedN[$nh]) && !in_array($learnedN[$nh], $used, true)) {
                $map[$i] = $learnedN[$nh];
                $used[]  = $learnedN[$nh];
            }
        }

        // Pass 2 — built-in synonym/fuzzy matching for whatever is still open.
        foreach ($headers as $i => $h) {
            if ($map[$i] !== null) continue;
            $nh = $this->normHeader($h);
            $field = null;
            if ($nh !== '') {
                if (isset($cands[$nh]) && !in_array($cands[$nh], $used, true)) {
                    $field = $cands[$nh];
                } else {
                    $best = null; $bestScore = 0.0;
                    foreach ($cands as $cand => $fk) {
                        if (in_array($fk, $used, true)) continue;
                        $pct = 0.0;
                        similar_text($nh, $cand, $pct);
                        if ($pct > $bestScore) { $bestScore = $pct; $best = $fk; }
                    }
                    if ($best !== null && $bestScore >= 82.0) $field = $best;
                }
            }
            if ($field !== null) $used[] = $field;
            $map[$i] = $field;
        }
        return $map;
    }

    private function normHeader($h): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(trim((string) $h)));
    }

    /** Public, Firestore-safe key for a header (strips '.', '/', etc.). */
    public function normalizeHeaderKey(string $h): string
    {
        return $this->normHeader($h);
    }

    /** Count how many of these headers an autoMap result drew from $learned. */
    public function learnedHitCount(array $headers, array $learned): int
    {
        $valid = $this->defs();
        $set = [];
        foreach ($learned as $h => $fk) {
            $nh = $this->normHeader($h);
            if ($nh !== '' && is_string($fk) && isset($valid[$fk])) $set[$nh] = true;
        }
        $n = 0;
        foreach ($headers as $h) {
            if (isset($set[$this->normHeader($h)])) $n++;
        }
        return $n;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  NORMALIZATION
    // ═══════════════════════════════════════════════════════════════════

    public function normalizeValue(string $fieldKey, $raw): string
    {
        $defs = $this->defs();
        $norm = $defs[$fieldKey]['norm'] ?? 'text';
        switch ($norm) {
            case 'class':   return $this->normalizeClass($raw);
            case 'section': return $this->normalizeSection($raw);
            case 'date':    return $this->normalizeDate($raw);
            case 'gender':  return $this->normalizeGender($raw);
            case 'phone':   return $this->normalizePhone($raw);
            case 'blood':   return $this->normalizeBlood($raw);
            case 'email':   return $this->normalizeEmail($raw);
            case 'india_state':
                // Canonicalize an India state/UT spelling; keep as typed if unknown.
                if (function_exists('india_geo_match_state')) {
                    $c = india_geo_match_state($raw);
                    if ($c !== '') return $c;
                }
                return $this->normalizeText($raw);
            default:        return $this->normalizeText($raw);
        }
    }

    public function normalizeText($raw): string
    {
        return trim(preg_replace('/\s+/', ' ', (string) $raw));
    }

    /** Resolve any class spelling to "Class N" (N = 1..12) when possible. */
    public function normalizeClass($raw): string
    {
        $s = trim((string) $raw);
        if ($s === '') return '';

        $low = strtolower($s);
        $low = preg_replace('/\b(class|grade|std|standard|cls)\b/', ' ', $low);
        $low = trim($low);

        if (preg_match('/\d+/', $low, $m)) {
            return 'Class ' . (int) $m[0];
        }

        $key   = preg_replace('/[^a-z]/', '', $low);
        $roman = ['xii' => 12, 'xi' => 11, 'x' => 10, 'ix' => 9, 'viii' => 8, 'vii' => 7,
                  'vi' => 6, 'v' => 5, 'iv' => 4, 'iii' => 3, 'ii' => 2, 'i' => 1];
        if (isset($roman[$key])) return 'Class ' . $roman[$key];

        $words = ['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5,
                  'sixth' => 6, 'seventh' => 7, 'eighth' => 8, 'ninth' => 9, 'tenth' => 10,
                  'eleventh' => 11, 'twelfth' => 12];
        if (isset($words[$key])) return 'Class ' . $words[$key];

        // Pre-primary / unrecognised — return as-is; validation flags it.
        return $s;
    }

    /** "Section A" / "Sec a" / "A" → "A". */
    public function normalizeSection($raw): string
    {
        $s = strtoupper(trim((string) $raw));
        if ($s === '') return '';
        $s = preg_replace('/\b(SECTION|SEC)\b/', '', $s);
        $s = preg_replace('/[^A-Z0-9]/', '', $s);
        return $s;
    }

    /** Many date spellings (and Excel serials) → canonical "Y-m-d", or '' if unparseable. */
    public function normalizeDate($raw): string
    {
        $s = trim((string) $raw);
        if ($s === '') return '';

        // Excel/serial date number (PhpSpreadsheet may emit these on unformatted cells)
        if (is_numeric($s) && strpos($s, '.') === false) {
            $n = (int) $s;
            if ($n > 59 && $n < 100000) { // 1900-03-01 .. ~2173
                $ts = ($n - 25569) * 86400; // Excel epoch (1899-12-30) → Unix
                return gmdate('Y-m-d', $ts);
            }
        }

        // 4-digit-year formats first (day-first preferred for Indian data).
        // Reject a 2-digit year that slipped into a 'Y' slot (createFromFormat
        // is lenient: "15-03-99" would parse as year 0099) by requiring >= 1000,
        // so it falls through to the 2-digit pivot below.
        $fourDigit = ['Y-m-d', 'd-m-Y', 'd/m/Y', 'd.m.Y', 'm/d/Y', 'Y/m/d',
                      'd-M-Y', 'd M Y', 'j F Y', 'j-M-Y'];
        foreach ($fourDigit as $f) {
            $dt = \DateTime::createFromFormat('!' . $f, $s);
            if ($dt instanceof \DateTime) {
                $e = \DateTime::getLastErrors();
                $warn = is_array($e) ? (int) ($e['warning_count'] ?? 0) : 0;
                $err  = is_array($e) ? (int) ($e['error_count'] ?? 0) : 0;
                if ($warn === 0 && $err === 0 && (int) $dt->format('Y') >= 1000) {
                    return $dt->format('Y-m-d');
                }
            }
        }

        // Two-digit-year formats. PHP pivots 'y' 00–69 → 2000s, 70–99 → 1900s,
        // so "15-03-99" → 1999 (not 0099).
        foreach (['d-m-y', 'd/m/y', 'd.m.y'] as $f) {
            $dt = \DateTime::createFromFormat('!' . $f, $s);
            if ($dt instanceof \DateTime) {
                $e = \DateTime::getLastErrors();
                $warn = is_array($e) ? (int) ($e['warning_count'] ?? 0) : 0;
                $err  = is_array($e) ? (int) ($e['error_count'] ?? 0) : 0;
                if ($warn === 0 && $err === 0) return $dt->format('Y-m-d');
            }
        }

        // Unparseable. NOTE: the old strtotime() fallback was removed — it
        // silently rolled invalid calendar dates over (31-02-2020 → 02 Mar),
        // writing a wrong DOB with no warning. Return '' so validation flags it.
        return '';
    }

    public function normalizeGender($raw): string
    {
        $s = strtolower(trim((string) $raw));
        if ($s === '') return '';
        if ($s[0] === 'f' || strpos($s, 'female') !== false || strpos($s, 'girl') !== false) return 'Female';
        if ($s[0] === 'm' || strpos($s, 'male') !== false || strpos($s, 'boy') !== false)   return 'Male';
        return 'Other';
    }

    /** Strip formatting, drop +91 / leading 0; returns digit string. */
    public function normalizePhone($raw): string
    {
        $d = preg_replace('/\D+/', '', (string) $raw);
        if ($d === '') return '';
        $len = strlen($d);
        if ($len === 12 && substr($d, 0, 2) === '91') $d = substr($d, 2);
        elseif ($len === 13 && substr($d, 0, 3) === '910') $d = substr($d, 3);
        elseif ($len === 11 && $d[0] === '0') $d = substr($d, 1);
        return $d;
    }

    public function normalizeBlood($raw): string
    {
        $orig = trim((string) $raw);
        if ($orig === '') return '';
        $s = strtoupper($orig);
        $s = str_replace([' ', 'POSITIVE', 'NEGATIVE', 'POS', 'NEG'], ['', '+', '-', '+', '-'], $s);
        $valid = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
        return in_array($s, $valid, true) ? $s : $orig;
    }

    public function normalizeEmail($raw): string
    {
        return strtolower(trim((string) $raw));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  VALIDATION (dry-run)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Normalize + validate a single mapped row.
     * Input: assoc [canonicalKey => raw value] (unmapped keys may be absent).
     * Output: ['data' => normalized-all-fields, 'errors' => [], 'warnings' => [], 'status' => ok|warning|error]
     */
    public function validateRow(array $mapped): array
    {
        $data = [];
        foreach ($this->defs() as $key => $_) {
            $data[$key] = $this->normalizeValue($key, $mapped[$key] ?? '');
        }

        $errors = [];
        $warnings = [];

        if ($data['Name'] === '')    $errors[] = 'Name is required';
        if ($data['Class'] === '')   $errors[] = 'Class is required';
        elseif (!preg_match('/\d+/', $data['Class'])) {
            $errors[] = "Class \"" . ($mapped['Class'] ?? '') . "\" is not a supported numeric class (1–12)";
        }
        if ($data['Section'] === '') $errors[] = 'Section is required';

        if (trim((string) ($mapped['DOB'] ?? '')) !== '' && $data['DOB'] === '') {
            $warnings[] = 'Date of Birth could not be read — left blank';
        }
        if (trim((string) ($mapped['Admission Date'] ?? '')) !== '' && $data['Admission Date'] === '') {
            $warnings[] = 'Admission Date could not be read — left blank';
        }
        if ($data['Phone Number'] !== '' && strlen($data['Phone Number']) !== 10) {
            $warnings[] = 'Phone Number is not 10 digits';
        }
        if ($data['Guard Contact'] !== '' && strlen($data['Guard Contact']) !== 10) {
            $warnings[] = 'Guardian Contact is not 10 digits';
        }
        if ($data['Email'] !== '' && !filter_var($data['Email'], FILTER_VALIDATE_EMAIL)) {
            $warnings[] = 'Email looks invalid';
        }

        // India geo: canonicalize District (City) against the row's State, warn on
        // an unrecognized State / off-state District (never an error — stays lenient).
        if (function_exists('india_geo_match_state') && $data['State'] !== '') {
            if (india_geo_match_state($data['State']) === '') {
                $warnings[] = "State \"{$data['State']}\" is not a recognized India state/UT — left as typed";
            } elseif ($data['City'] !== '') {
                $cityCanon = india_geo_match_district($data['State'], $data['City']);
                if ($cityCanon !== '') $data['City'] = $cityCanon;
                else $warnings[] = "City/District \"{$data['City']}\" is not a listed district of {$data['State']} — left as typed";
            }
        }

        $status = !empty($errors) ? 'error' : (!empty($warnings) ? 'warning' : 'ok');
        return ['data' => $data, 'errors' => $errors, 'warnings' => $warnings, 'status' => $status];
    }
}
