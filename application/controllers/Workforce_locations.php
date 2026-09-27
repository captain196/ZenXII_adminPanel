<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Workforce_locations — Phase 1 ZenX HRMS: campus/office/store CRUD.
 *
 * Deliberately a separate, small controller — NOT folded into Org.php
 * (which already owns "Departments & Roles" under the org/* routes; a new
 * "Organisation" concept there would collide in name and concept) and NOT
 * grown inside School_config.php (already 5,000+ lines; only the small
 * org-profile/terminology additions belong there, per the Phase 1 plan).
 *
 * AJAX endpoints only — no page of its own. Its UI lives embedded inside
 * the existing School_config settings screen.
 *
 * Every mutating and reading action is gated by BOTH:
 *   - require_permission('HR', ...)          — can THIS USER?
 *   - require_capability(HRMS_CORE_CAPABILITY) — does THIS TENANT have it?
 * These are independent gates; neither substitutes for the other.
 */
class Workforce_locations extends MY_Controller
{
    private const MAX_NAME_LENGTH = 120;

    public function __construct()
    {
        parent::__construct();
        $this->load->helper('terminology');
    }

    /**
     * GET/POST /workforce_locations/get_data — list this school's locations.
     * Self-heals via ensure_org_profile() so a school that has never visited
     * the settings screen still gets a populated (one default) list rather
     * than an empty dropdown elsewhere (e.g. the Staff edit form).
     */
    public function get_data()
    {
        require_permission('HR', 'workforce_locations_get_data', 'view');
        require_capability(HRMS_CORE_CAPABILITY, 'workforce_locations_get_data');

        // Shared with Staff.php's new/edit form dropdowns — same shape,
        // same ordering, same self-healing, everywhere a location list is shown.
        $this->json_success(['locations' => org_locations_for_school($this->fs, $this->school_id)]);
    }

    /**
     * POST /workforce_locations/save — create (no location_id) or rename
     * (location_id present) a location. Ids are opaque (LOC_ + random hex),
     * never sequential — a sequential "read-all, pick-next-free" scheme
     * would be a genuine TOCTOU race across concurrent saves, since
     * locations is its own collection rather than one in-memory map on a
     * single document the way Org.php's staffRoles/departments are.
     */
    public function save()
    {
        require_permission('HR', 'workforce_locations_save', 'edit');
        require_capability(HRMS_CORE_CAPABILITY, 'workforce_locations_save');

        $locationId = trim((string) $this->input->post('location_id', TRUE));
        $name       = trim((string) $this->input->post('name', TRUE));

        if ($name === '') {
            return $this->json_error('Please enter a name.');
        }
        if (strlen($name) > self::MAX_NAME_LENGTH) {
            return $this->json_error('Name exceeds ' . self::MAX_NAME_LENGTH . ' characters.');
        }

        // FEATURE 2 — ESI applicability at this location.
        //
        // TRI-STATE on purpose. '' means the school has not decided, and an
        // undecided location INHERITS the tenant-wide setting. Collapsing
        // undecided into false would silently stop ESI for everyone posted
        // there the moment locations were introduced.
        //
        // ABSENT is not the same as CLEARED. The rename control on the school
        // config screen posts only {location_id, name}; if an absent field were
        // treated as "set to undecided", renaming a location would silently wipe
        // its ESI determination and everybody posted there would quietly start or
        // stop having ESI deducted. So a missing parameter leaves the stored value
        // alone, and only an explicitly submitted one changes it.
        $esiRaw = $this->input->post('esi_applicable', TRUE);
        $esiSubmitted = ($esiRaw !== null);
        $esiApplicable = null;
        if ($esiRaw !== null && trim((string) $esiRaw) !== '') {
            $v = strtolower(trim((string) $esiRaw));
            if (!in_array($v, ['1', '0', 'true', 'false', 'yes', 'no'], true)) {
                return $this->json_error('ESI applicability must be yes, no, or left undecided.');
            }
            $esiApplicable = in_array($v, ['1', 'true', 'yes'], true);
        }

        $now = date('c');

        if ($locationId === '') {
            // Create.
            $locationId = 'LOC_' . strtoupper(bin2hex(random_bytes(4)));
            try {
                $this->fs->set('locations', $this->fs->docId($locationId), [
                    'schoolId'   => $this->school_id,
                    'locationId' => $locationId,
                    'name'          => $name,
                    'isDefault'     => false,
                    'esiApplicable' => $esiApplicable,
                    'createdAt'     => $now,
                    'updatedAt'     => $now,
                ], false);
            } catch (\Throwable $e) {
                log_message('error', 'Workforce_locations::save create failed: ' . $e->getMessage());
                return $this->json_error('Could not create the location — please try again.');
            }
            log_audit('HR', 'workforce_locations_save', $this->school_name, "Created location '{$name}'");
            return $this->json_success(['message' => 'Location created.', 'location_id' => $locationId]);
        }

        // Rename an existing location — verify it belongs to this school first.
        $existing = $this->fs->get('locations', $this->fs->docId($locationId));
        if (!is_array($existing) || ($existing['schoolId'] ?? '') !== $this->school_id) {
            return $this->json_error('Location not found.');
        }
        try {
            $patch = ['name' => $name, 'updatedAt' => $now];
            if ($esiSubmitted) $patch['esiApplicable'] = $esiApplicable;
            $this->fs->update('locations', $this->fs->docId($locationId), $patch);
        } catch (\Throwable $e) {
            log_message('error', 'Workforce_locations::save update failed: ' . $e->getMessage());
            return $this->json_error('Could not update the location — please try again.');
        }
        // The ESI determination is audited alongside the rename, because it
        // decides whether everyone posted here has ESI deducted.
        $esiLabel = !$esiSubmitted ? 'unchanged'
            : ($esiApplicable === null ? 'inherit tenant setting'
                : ($esiApplicable ? 'applicable' : 'not applicable'));
        log_audit('HR', 'workforce_locations_save', $this->school_name,
            "Updated location '{$locationId}' to '{$name}' — ESI: {$esiLabel}");
        $this->json_success(['message' => 'Location updated.', 'location_id' => $locationId]);
    }

    /**
     * POST /workforce_locations/delete — three INDEPENDENT guards:
     *   1. refuse the default location,
     *   2. refuse if any staff member is currently assigned to it,
     *   3. refuse if it is the last remaining location —
     * so a school can never be left with zero locations even when guards
     * 1 and 2 both pass (e.g. a school's only non-default location, with
     * nobody assigned to it yet).
     */
    public function delete()
    {
        require_permission('HR', 'workforce_locations_delete', 'manage');
        require_capability(HRMS_CORE_CAPABILITY, 'workforce_locations_delete');

        $locationId = trim((string) $this->input->post('location_id', TRUE));
        if ($locationId === '') {
            return $this->json_error('location_id is required.');
        }

        $existing = $this->fs->get('locations', $this->fs->docId($locationId));
        if (!is_array($existing) || ($existing['schoolId'] ?? '') !== $this->school_id) {
            return $this->json_error('Location not found.');
        }
        if (!empty($existing['isDefault'])) {
            return $this->json_error('The default location cannot be deleted.');
        }

        try {
            $allLocations = $this->fs->schoolWhere('locations', []);
        } catch (\Throwable $e) {
            log_message('error', 'Workforce_locations::delete count check failed: ' . $e->getMessage());
            return $this->json_error('Could not verify locations — please try again.');
        }
        if (is_array($allLocations) && count($allLocations) <= 1) {
            return $this->json_error('This school must always have at least one location.');
        }

        $inUse = 0;
        try {
            $staffDocs = $this->fs->schoolWhere('staff', []);
            if (is_array($staffDocs)) {
                foreach ($staffDocs as $doc) {
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    if (($d['locationId'] ?? '') === $locationId) {
                        $inUse++;
                    }
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'Workforce_locations::delete in-use check failed: ' . $e->getMessage());
            return $this->json_error('Could not verify staff assignments — please try again.');
        }
        if ($inUse > 0) {
            return $this->json_error("Cannot delete — {$inUse} staff member(s) are still assigned to this location. Reassign them first.");
        }

        try {
            $this->fs->remove('locations', $this->fs->docId($locationId));
        } catch (\Throwable $e) {
            log_message('error', 'Workforce_locations::delete failed: ' . $e->getMessage());
            return $this->json_error('Could not delete the location — please try again.');
        }

        log_audit('HR', 'workforce_locations_delete', $this->school_name, "Deleted location '{$locationId}'");
        $this->json_success(['message' => 'Location deleted.']);
    }
}
