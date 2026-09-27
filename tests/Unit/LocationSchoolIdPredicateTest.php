<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ZenX HRMS Phase 1 — tenant-isolation regression test for the new
 * `locations` collection, following this repo's one established convention
 * for this exact concern: HomeworkSchoolIdPredicateTest.php's literal-
 * source-regression style (read the real controller/helper source, assert
 * every Firestore call carries a school-scoping predicate).
 *
 * This is the only viable isolation-test style available today for
 * Firestore_service-based code — `tests/Support/FirebaseMock.php` mocks a
 * different, RTDB-shaped class (`Firebase.php`), not the collection/docId
 * REST API `Firestore_service` actually is, so no behavioral substitute
 * exists (confirmed during Phase 1 planning; see
 * ZENX_INTEGRATED_HRMS_PAYROLL_BLUEPRINT.md).
 *
 * `Firestore_service::docId($entityId)` and `::schoolWhere($collection, ...)`
 * are both hardcoded to the single `$this->schoolId` bound at `$this->fs`'s
 * per-request init() — so every call through them is tenant-scoped by
 * construction. What this test actually proves is narrower and load-bearing:
 * that the new code always goes THROUGH those methods (or an explicit
 * `schoolId` equality check for reads by raw docId) rather than ever
 * querying/writing `locations` unscoped.
 */
class LocationSchoolIdPredicateTest extends TestCase
{
    private string $controllerSrc;
    private string $helperSrc;

    protected function setUp(): void
    {
        $this->controllerSrc = $this->readCodeOnly(
            __DIR__ . '/../../application/controllers/Workforce_locations.php'
        );
        $this->helperSrc = $this->readCodeOnly(
            __DIR__ . '/../../application/helpers/terminology_helper.php'
        );
    }

    /**
     * Read a PHP file with all comments stripped, so these assertions
     * measure EXECUTABLE code only — a comment that happens to quote a
     * scoped query must never satisfy an assertion that real code is
     * doing the scoping.
     */
    private function readCodeOnly(string $path): string
    {
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw, "Could not read {$path}");

        $out = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        return $out;
    }

    /**
     * org_locations_for_school() — the single shared read path used by
     * Workforce_locations::get_data(), Staff.php's new/edit forms, and the
     * Hr.php proof-of-concept — must query via schoolWhere(), never a raw
     * unscoped query() on the locations collection.
     */
    public function test_locations_list_query_is_school_scoped(): void
    {
        $this->assertStringContainsString(
            "\$fs->schoolWhere('locations', [])",
            $this->helperSrc,
            'org_locations_for_school() must read locations via schoolWhere(), which hardcodes the schoolId equality predicate.'
        );
    }

    /**
     * ensure_org_profile()'s self-healing default-location write must use
     * $fs->docId(), which concatenates the already-scoped schoolId — never
     * a bare/raw location id as the Firestore document id.
     */
    public function test_default_location_write_uses_scoped_doc_id(): void
    {
        $this->assertStringContainsString(
            "\$fs->docId(\$locationId)",
            $this->helperSrc,
            'ensure_org_profile() must write the default location via $fs->docId(), not a raw doc id.'
        );
    }

    /**
     * Every single-location read/write/delete in the controller (save,
     * delete's existence check, delete's actual removal) must resolve the
     * document id through $this->fs->docId() — never trust a raw
     * client-supplied location_id as the Firestore doc id directly, which
     * would let a request for another school's location id collide only if
     * that id happened to also exist verbatim under this school (docId()
     * prefixes the CURRENT request's schoolId, so this is structurally not
     * possible when the pattern is followed).
     */
    public function test_single_location_operations_use_scoped_doc_id(): void
    {
        $count = substr_count($this->controllerSrc, '$this->fs->docId($locationId)');
        $this->assertGreaterThanOrEqual(
            4,
            $count,
            "Expected at least 4 sites in Workforce_locations.php resolving a location doc id via \$this->fs->docId() (save-create, save-rename x2, delete-existence-check, delete-removal); found {$count}."
        );
    }

    /**
     * delete()'s in-use guard (refusing to delete a location staff are
     * still assigned to) must check staff scoped to the CURRENT school,
     * never an unscoped staff query — otherwise the in-use count could
     * include (or, worse, be satisfied by) another tenant's staff.
     */
    public function test_delete_in_use_check_is_school_scoped(): void
    {
        $this->assertStringContainsString(
            "\$this->fs->schoolWhere('staff', [])",
            $this->controllerSrc,
            "Workforce_locations::delete()'s in-use guard must query staff via schoolWhere(), scoped to the acting request's school."
        );
    }

    /**
     * Every write in the controller must carry an explicit schoolId field
     * matching the acting request's own school — defense in depth on top
     * of docId() scoping, matching the shape every other collection in
     * this codebase already writes (e.g. `staff`, `salarySlips`).
     */
    public function test_location_create_stamps_school_id_field(): void
    {
        $this->assertStringContainsString(
            "'schoolId'   => \$this->school_id,",
            $this->controllerSrc,
            'A newly created location document must stamp schoolId => $this->school_id.'
        );
    }

    /**
     * Reads of a SPECIFIC location (rename, delete) must verify the
     * fetched document's own schoolId field matches the acting request's
     * school before trusting it — belt-and-braces against a future
     * refactor of docId()'s scoping guarantee.
     */
    public function test_single_location_ownership_reverified_after_read(): void
    {
        $count = substr_count($this->controllerSrc, "(\$existing['schoolId'] ?? '') !== \$this->school_id");
        $this->assertGreaterThanOrEqual(
            2,
            $count,
            "Expected at least 2 sites (save-rename, delete) re-verifying a fetched location's schoolId against the acting request's school; found {$count}."
        );
    }
}
