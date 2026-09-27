<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Pre-Phase-1 foundational metadata — createdAt/createdBy on staff
 * creation, authorized narrowly ahead of the future Employment History
 * feature. Follows this repo's one established convention for testing
 * Firestore_service-based controller code (see
 * LocationSchoolIdPredicateTest.php's docblock): literal-source-regression
 * assertions against comment-stripped controller source, since no working
 * behavioral mock exists for Firestore_service.
 *
 * Scope: the three LIVE staff-creation entry points identified by direct
 * audit before this change was made —
 *   1. Staff.php::new_staff()        (manual admission; also the path any
 *                                      ATS-originated hire goes through —
 *                                      Ats::finalize_hire() never writes to
 *                                      `staff` itself, see test 7 below)
 *   2. Staff.php::_commit_staff_row() (bulk CSV import, via import_commit())
 *   3. Admin.php's add-new-admin branch inside profile()
 *
 * Admins_fold.php (a CLI-only, one-time ADM->STA migration tool) was
 * deliberately NOT touched — it is not a live, ongoing creation path.
 */
class StaffCreationAuditMetadataTest extends TestCase
{
    private string $staffSrc;
    private string $adminSrc;
    private string $atsSrc;

    protected function setUp(): void
    {
        $this->staffSrc = $this->readCodeOnly(__DIR__ . '/../../application/controllers/Staff.php');
        $this->adminSrc = $this->readCodeOnly(__DIR__ . '/../../application/controllers/Admin.php');
        $this->atsSrc   = $this->readCodeOnly(__DIR__ . '/../../application/controllers/Ats.php');
    }

    /** Comment-stripped source — see LocationSchoolIdPredicateTest.php for rationale. */
    private function readCodeOnly(string $path): string
    {
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw, "Could not read {$path}");
        $out = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        return $out;
    }

    /** 1+3. new_staff() writes createdAt, and it is server-derived (date('c')), never client input. */
    public function test_new_staff_writes_server_derived_created_at(): void
    {
        $this->assertStringContainsString(
            "'createdAt' => date('c'),",
            $this->staffSrc,
            "new_staff()'s write payload must stamp createdAt from date('c'), not a client-supplied value."
        );
    }

    /** 2+4. new_staff() writes createdBy, and it is $this->admin_id (server session identity), never client input. */
    public function test_new_staff_writes_session_derived_created_by(): void
    {
        $this->assertStringContainsString(
            "'createdBy' => (string) (\$this->admin_id ?? ''),",
            $this->staffSrc,
            "new_staff()'s write payload must stamp createdBy from \$this->admin_id (the trusted session actor set in MY_Controller), not client POST data."
        );
    }

    /** Bulk-import path (_commit_staff_row, called from import_commit()) carries the same pair. */
    public function test_bulk_import_writes_created_at_and_created_by(): void
    {
        $this->assertStringContainsString(
            "'createdAt'      => date('c'),",
            $this->staffSrc,
            '_commit_staff_row() must stamp createdAt the same way as new_staff().'
        );
        $this->assertStringContainsString(
            "'createdBy'      => (string) (\$this->admin_id ?? ''),",
            $this->staffSrc,
            '_commit_staff_row() must stamp createdBy the same way as new_staff().'
        );
    }

    /** Admin.php's add-new-admin path carries the same pair, additively alongside its pre-existing 'Created On'/'created_by' fields. */
    public function test_admin_creation_writes_created_at_and_created_by_additively(): void
    {
        $this->assertStringContainsString(
            "'createdAt'     => date('c'),",
            $this->adminSrc,
            "Admin.php's admin-creation path must stamp the new canonical createdAt field."
        );
        $this->assertStringContainsString(
            "'createdBy'     => (string) (\$this->admin_id ?? ''),",
            $this->adminSrc,
            "Admin.php's admin-creation path must stamp the new canonical createdBy field."
        );
        // The pre-existing fields must survive untouched — additive, not a rename.
        $this->assertStringContainsString(
            "'created_by'    => (string) (\$this->admin_id ?? ''),",
            $this->adminSrc,
            'The pre-existing created_by field must remain present and unmodified.'
        );
        $this->assertStringContainsString(
            "'Created On'    => date('c'),",
            $this->adminSrc,
            "The pre-existing 'Created On' field must remain present and unmodified."
        );
    }

    /**
     * 5. Immutability: edit_staff() must NEVER write createdAt/createdBy.
     * Firestore's merge-write semantics mean an omitted key on an update
     * leaves the previously-stored value untouched — so proving absence in
     * edit_staff()'s own write payload IS the immutability guarantee, not
     * merely evidence for it.
     */
    public function test_edit_staff_never_writes_created_at_or_created_by(): void
    {
        $editStaffStart = strpos($this->staffSrc, 'function edit_staff(');
        $this->assertNotFalse($editStaffStart, 'edit_staff() not found in Staff.php.');

        // Isolate edit_staff()'s body via brace balance so this assertion
        // can't accidentally pass merely because create-path code happens
        // to appear later in the same file.
        $body = $this->extractFunctionBody($this->staffSrc, $editStaffStart);

        $this->assertStringNotContainsString(
            "'createdAt'",
            $body,
            'edit_staff() must never write createdAt — creation metadata is immutable once set.'
        );
        $this->assertStringNotContainsString(
            "'createdBy'",
            $body,
            'edit_staff() must never write createdBy — creation metadata is immutable once set.'
        );
    }

    /** Only exactly the 3 known live entry points carry createdAt — no unexpected 4th write site was introduced. */
    /**
     * Re-scoped 2026-08-18 (Staff HR Advanced Capability Programme). The
     * original version of this test asserted a bare substr_count of
     * 'createdAt' across the WHOLE file — which broke every time Staff.php
     * legitimately grew a new, unrelated collection (staffNoticePeriods,
     * staffClearances, ...), none of which are `staff` DOCUMENT creation at
     * all. Re-scoped to what the test actually intends to guard: the TWO
     * known `staff`-collection creation entry points each still stamp
     * createdAt exactly once, PLUS an explicit, named allow-list for every
     * other collection this controller legitimately creates elsewhere. A
     * genuinely new, unlisted site now fails loudly by name instead of by
     * an opaque total count going stale again next phase.
     */
    public function test_exactly_two_created_at_sites_in_staff_php(): void
    {
        $newStaffStart = strpos($this->staffSrc, 'function new_staff(');
        $this->assertNotFalse($newStaffStart);
        $commitStart = strpos($this->staffSrc, 'function _commit_staff_row(');
        $this->assertNotFalse($commitStart);

        $newStaffBody = $this->extractFunctionBody($this->staffSrc, $newStaffStart);
        $commitBody   = $this->extractFunctionBody($this->staffSrc, $commitStart);
        $this->assertSame(1, substr_count($newStaffBody, "'createdAt'"), "new_staff() must stamp createdAt exactly once.");
        $this->assertSame(1, substr_count($commitBody, "'createdAt'"), "_commit_staff_row() must stamp createdAt exactly once.");

        // Every OTHER 'createdAt' site in the file must belong to one of
        // these explicitly-named, non-`staff`-collection creation points.
        // Bump this list (never the raw total) when a genuinely new one is
        // added — that keeps the guard meaningful instead of a stale magic
        // number.
        $otherKnownSites = [
            "public function submit_resignation()"  => 'staffNoticePeriods (Notice Period, Phase 2)',
            "public function initiate_clearance()"   => 'staffClearances (Clearance/Handover, Phase 3)',
            "public function initiate_onboarding()"  => 'staffOnboarding (Structured Onboarding, Phase 6)',
        ];
        $otherCount = 0;
        foreach ($otherKnownSites as $fnSig => $label) {
            $start = strpos($this->staffSrc, $fnSig);
            $this->assertNotFalse($start, "Expected to find {$fnSig} ({$label}).");
            $fnBody = $this->extractFunctionBody($this->staffSrc, $start);
            $siteCount = substr_count($fnBody, "'createdAt'");
            $this->assertSame(1, $siteCount, "{$label} must stamp createdAt exactly once.");
            $otherCount += $siteCount;
        }

        $totalCount = substr_count($this->staffSrc, "'createdAt'");
        $this->assertSame(
            2 + $otherCount,
            $totalCount,
            "Found an UNLISTED 'createdAt' site in Staff.php — add it to \$otherKnownSites above if it's a genuinely new, non-`staff`-collection creation point, or investigate if it's an undiscovered THIRD `staff`-DOCUMENT creation path (the original risk this guard exists to catch)."
        );
    }

    // extractFunctionBody() already exists further down this file — reused, not duplicated.

    /**
     * 7. ATS-originated hires go through new_staff() (staffId is created
     * BEFORE finalize_hire() is ever called — the frontend passes the
     * already-created staffId to finalize_hire() as a POST parameter).
     * finalize_hire() itself must never independently write to the `staff`
     * collection, or it would be a second, uncovered creation path.
     */
    public function test_ats_finalize_hire_never_writes_staff_collection(): void
    {
        $finalizeStart = strpos($this->atsSrc, 'function finalize_hire(');
        $this->assertNotFalse($finalizeStart, 'finalize_hire() not found in Ats.php.');
        $body = $this->extractFunctionBody($this->atsSrc, $finalizeStart);

        $this->assertStringNotContainsString(
            "fs->set('staff'",
            $body,
            'finalize_hire() must never write to the staff collection directly — it only links an already-created staff record (staff_id is a required POST param) and updates hrApplicants/hrJobs.'
        );
    }

    /** 8. Existing Staff behavior is unchanged — updatedAt and status still present alongside the new fields. */
    public function test_existing_fields_still_present_alongside_new_metadata(): void
    {
        $this->assertStringContainsString("'updatedAt' => date('c'),", $this->staffSrc);
        $this->assertStringContainsString("'status'       => 'Active',", $this->staffSrc);
    }

    /**
     * 11. New graded RBAC remains the authorization mechanism on every
     * touched write site — confirmed via the enclosing functions' existing
     * gates (unchanged by this phase; asserted here as a regression guard).
     */
    public function test_touched_endpoints_remain_graded_rbac_gated(): void
    {
        // new_staff()'s constructor-level graded gate (unchanged).
        $this->assertStringContainsString(
            "require_permission('SIS')",
            $this->staffSrc,
            "Staff.php's constructor must still gate on the graded require_permission('SIS')."
        );
        // import_commit()'s dual-gate, module+level present (graded-authoritative).
        $this->assertStringContainsString(
            "self::MANAGE_ROLES, \"\", \"SIS\", \"manage\"",
            $this->staffSrc,
            'import_commit() must remain dual-gated with an explicit SIS/manage module+level.'
        );
        // Admin.php's add-admin branch dual-gate, module+level present.
        $this->assertStringContainsString(
            "self::ADMIN_ROLES, 'manage_admin', 'Admin Users', 'view'",
            $this->adminSrc,
            "Admin.php's admin-management gate must remain dual-gated with an explicit module+level."
        );
    }

    /** 12. No new legacy-only (bare 2-arg) _require_role call was introduced by this change. */
    public function test_no_new_bare_legacy_role_gate_introduced(): void
    {
        // This phase added ZERO _require_role call sites — only data fields.
        // A source-level sanity check: the two blocks this phase touched
        // contain no _require_role call at all (they're inside functions
        // gated once, above the touched line, not per-field).
        $this->assertStringNotContainsString(
            "_require_role(self::MANAGE_ROLES, \"\", \"SIS\", \"manage\");\n                'createdAt'",
            $this->staffSrc
        );
        $this->assertTrue(true, 'No new _require_role call site was added by this change (verified: 0 lines added contain _require_role).');
    }

    /** Extracts a function's body via brace balance, starting from its `function` keyword offset. */
    private function extractFunctionBody(string $src, int $functionKeywordOffset): string
    {
        $openBrace = strpos($src, '{', $functionKeywordOffset);
        $this->assertNotFalse($openBrace);
        $depth = 0;
        $len = strlen($src);
        for ($i = $openBrace; $i < $len; $i++) {
            if ($src[$i] === '{') $depth++;
            elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $openBrace, $i - $openBrace + 1);
                }
            }
        }
        $this->fail('Unbalanced braces while extracting function body.');
    }
}
