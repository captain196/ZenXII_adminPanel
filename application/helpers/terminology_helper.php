<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Organisation-profile / terminology-dictionary helpers — Phase 1 of the
 * ZenX Integrated HRMS. Deliberately named apart from deptrole_helper.php
 * (which is Org.php's helper — a different, unrelated "Departments & Roles"
 * feature) so the two are never confused in code review.
 *
 * orgType/labels are stored as two new OPTIONAL fields directly on the
 * existing schools/{schoolId} doc — org == school 1:1 for every tenant
 * today, so no new "organisations" collection exists yet. This is a
 * deliberate, provisional Phase-1 choice (see the blueprint's §8): when a
 * real multi-school-per-organisation model is ever needed, these fields
 * migrate to a genuine organisations collection above schools.
 */

if (!function_exists('org_default_labels')) {
    /**
     * Seed terminology dictionary per org type. Editable afterwards on the
     * school doc — these are only the defaults a tenant starts from.
     */
    function org_default_labels(string $orgType): array
    {
        $catalogue = [
            'EDUCATION' => ['person' => 'Staff',     'location' => 'Campus', 'manager' => 'Principal / HOD'],
            'CORPORATE' => ['person' => 'Employee',  'location' => 'Office', 'manager' => 'Manager'],
            'RETAIL'    => ['person' => 'Associate', 'location' => 'Store',  'manager' => 'Store Manager'],
        ];
        return $catalogue[$orgType] ?? $catalogue['EDUCATION'];
    }
}

if (!function_exists('org_term')) {
    /**
     * Resolve one canonical terminology key ('person'|'location'|'manager')
     * to this school's current label — tenant override first, else the
     * org-type default, else a last-resort ucfirst of the key itself.
     */
    function org_term(string $key, array $schoolDoc): string
    {
        $labels = is_array($schoolDoc['labels'] ?? null) ? $schoolDoc['labels'] : [];
        if (isset($labels[$key]) && is_string($labels[$key]) && $labels[$key] !== '') {
            return $labels[$key];
        }
        $orgType = (string) ($schoolDoc['orgType'] ?? 'EDUCATION');
        $default = org_default_labels($orgType);
        return $default[$key] ?? ucfirst($key);
    }
}

if (!function_exists('org_locations_for_school')) {
    /**
     * Normalized, sorted (default first, then alphabetical) location list
     * for a school — self-heals via ensure_org_profile() first so callers
     * never see an empty list for a school that hasn't visited the settings
     * screen yet. Shared by Workforce_locations::get_data() and every place
     * that needs a location dropdown (Staff new/edit forms) so the shape
     * and ordering stay identical everywhere it's shown.
     */
    function org_locations_for_school($fs, string $school_id): array
    {
        if (empty($fs) || $school_id === '') {
            return [];
        }
        ensure_org_profile($fs, $school_id);

        $locations = [];
        try {
            $rows = $fs->schoolWhere('locations', []);
            if (is_array($rows)) {
                foreach ($rows as $doc) {
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    if (empty($d['locationId'])) continue;
                    $locations[] = [
                        'locationId' => $d['locationId'],
                        'name'       => (string) ($d['name'] ?? ''),
                        'isDefault'  => !empty($d['isDefault']),
                        // FEATURE 2: projected so the admin screen can SHOW the
                        // ESI determination. Without it the field would be
                        // storable and configurable but invisible — configured
                        // in the backend and unreachable from the UI.
                        //
                        // Tri-state preserved: null means undecided, which
                        // inherits the tenant-wide setting. `!empty()` here would
                        // flatten undecided into false.
                        'esiApplicable' => array_key_exists('esiApplicable', $d)
                            && $d['esiApplicable'] !== null && $d['esiApplicable'] !== ''
                            ? (bool) $d['esiApplicable'] : null,
                    ];
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'org_locations_for_school: read failed: ' . $e->getMessage());
            return [];
        }

        usort($locations, function ($a, $b) {
            if ($a['isDefault'] !== $b['isDefault']) return $a['isDefault'] ? -1 : 1;
            return strcasecmp($a['name'], $b['name']);
        });

        return $locations;
    }
}

if (!function_exists('ensure_org_profile')) {
    /**
     * Idempotent per-school backfill, same shape as deptrole_helper.php's
     * ensure_unified_roles(): if this school has never touched the
     * Organisation/Location screen, seed sane defaults so downstream reads
     * (the Staff edit form's location dropdown, the settings screen itself)
     * never see an empty state. Safe to call on every load — writes only
     * when something is actually missing.
     *
     * @return bool TRUE if a write occurred.
     */
    function ensure_org_profile($fs, string $school_id): bool
    {
        if (empty($fs) || $school_id === '') {
            return false;
        }

        try {
            $school = $fs->get('schools', $school_id);
        } catch (\Throwable $e) {
            log_message('error', 'ensure_org_profile: schools read failed: ' . $e->getMessage());
            return false;
        }
        if (!is_array($school)) {
            return false;
        }

        $changed = false;

        if (empty($school['orgType'])) {
            $school['orgType'] = 'EDUCATION';
            $school['labels']  = org_default_labels('EDUCATION');
            try {
                $fs->set('schools', $school_id, [
                    'orgType' => $school['orgType'],
                    'labels'  => $school['labels'],
                ], true);
                $changed = true;
            } catch (\Throwable $e) {
                log_message('error', 'ensure_org_profile: schools write failed: ' . $e->getMessage());
            }
        }

        try {
            $existingLocations = $fs->schoolWhere('locations', []);
        } catch (\Throwable $e) {
            log_message('error', 'ensure_org_profile: locations read failed: ' . $e->getMessage());
            $existingLocations = [];
        }

        if (empty($existingLocations)) {
            $locationId = 'LOC_' . strtoupper(bin2hex(random_bytes(4)));
            $now        = date('c');
            try {
                $fs->set('locations', $fs->docId($locationId), [
                    'schoolId'   => $school_id,
                    'locationId' => $locationId,
                    'name'       => 'Main ' . org_term('location', $school),
                    'isDefault'  => true,
                    'createdAt'  => $now,
                    'updatedAt'  => $now,
                ], false);
                $changed = true;
            } catch (\Throwable $e) {
                log_message('error', 'ensure_org_profile: default-location write failed: ' . $e->getMessage());
            }
        }

        return $changed;
    }
}
