<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ZenX HRMS W1.3 — ATS is Firestore-canonical and tenant-scoped.
 *
 * WHY THIS EXISTS
 * ---------------
 * Ats.php carried 26 RTDB calls against `Schools/{school_name}/HR/Recruitment/*`
 * while Hr.php had already moved to the Firestore `hrApplicants` / `hrJobs`
 * collections. The two modules therefore operated on disjoint stores that
 * merely looked alike. W1.3 pointed ATS at the canonical collections; this test
 * makes the regression loud if any RTDB call, unscoped query, or role-name
 * authorization bypass comes back.
 *
 * TESTING APPROACH
 * ----------------
 * Source-level assertions, following this repo's convention for Firestore-backed
 * controllers (HomeworkSchoolIdPredicateTest, LocationSchoolIdPredicateTest):
 * `$this->fs` is a live Firestore_service and the suite's FirebaseMock models the
 * RTDB-shaped Firebase class, so a behavioural test of this controller is not
 * achievable here. Comments are STRIPPED before matching — this file's own
 * subject documents the removed RTDB paths and the removed bypass in its
 * docblocks, and an unstripped match would let that documentation satisfy the
 * assertions it is supposed to guard.
 */
class AtsFirestoreCanonicalStoreTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = $this->readCodeOnly(
            __DIR__ . '/../../application/controllers/Ats.php'
        );
    }

    /** Read a PHP file with all comments stripped, so assertions measure real code. */
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

    /**
     * ZERO-RTDB. The project rule is absolute: Firestore only, no fallback,
     * no mirror, no bridge.
     */
    public function test_no_rtdb_calls_remain(): void
    {
        $this->assertStringNotContainsString(
            '$this->firebase->',
            $this->src,
            'Ats.php must not call the RTDB client. The recruitment store is Firestore '
            . '(hrApplicants / hrJobs), shared with Hr.php.'
        );
    }

    /** The RTDB path strings must be gone too, not merely unused. */
    public function test_no_rtdb_path_construction_remains(): void
    {
        foreach (['Schools/', 'Recruitment/Applicants', 'Recruitment/Jobs', 'HR/Counters'] as $fragment) {
            $this->assertStringNotContainsString(
                $fragment,
                $this->src,
                "Ats.php still builds the RTDB path fragment '{$fragment}'."
            );
        }
    }

    /**
     * TENANT ISOLATION. Every Firestore access must go through a school-scoped
     * helper. A bare `query()` or `get()` would read across tenants.
     */
    public function test_every_firestore_access_is_tenant_scoped(): void
    {
        preg_match_all('/\$this->fs->([a-zA-Z]+)\s*\(/', $this->src, $m);
        $calls = array_unique($m[1]);
        $this->assertNotEmpty($calls, 'No Firestore calls found — did the controller change shape?');

        // getEntity/setEntity/updateEntity/removeEntity all route through docId()
        // ({schoolId}_{id}); schoolWhere() injects the schoolId predicate.
        $scoped = ['getEntity', 'setEntity', 'updateEntity', 'removeEntity', 'schoolWhere', 'schoolList', 'docId'];

        foreach ($calls as $call) {
            $this->assertContains(
                $call,
                $scoped,
                "Ats.php calls \$this->fs->{$call}(), which is not a tenant-scoped helper. "
                . "Use getEntity/setEntity/updateEntity/removeEntity or schoolWhere/schoolList — "
                . "a bare query()/get() reads across every school in the database."
            );
        }
    }

    /** Both canonical collections must be the ones actually addressed. */
    public function test_uses_the_canonical_hr_collections(): void
    {
        $this->assertStringContainsString("'hrApplicants'", $this->src);
        $this->assertStringContainsString("'hrJobs'", $this->src);
    }

    /**
     * RBAC. `_require_admin()` / `_require_view()` previously fell back to
     * `in_array($this->admin_role, self::ADMIN_ROLES)` when the graded check
     * failed — a privilege escalation for anyone whose job title happened to
     * match, and a hard block for custom tenant-defined roles.
     */
    public function test_no_role_name_authorization_bypass(): void
    {
        $this->assertStringNotContainsString(
            'in_array($this->admin_role',
            $this->src,
            'Role-name matching must never be used as an authorization gate. '
            . 'It escalates privilege for hardcoded titles and blocks custom roles.'
        );
        $this->assertStringNotContainsString(
            'ADMIN_ROLES',
            $this->src,
            'The ADMIN_ROLES bypass list must not be reintroduced.'
        );
    }

    /** Every mutating endpoint must be gated at manage level. */
    public function test_mutating_endpoints_require_manage(): void
    {
        $mutators = [
            'save_applicant', 'move_stage', 'reject_applicant',
            'add_review', 'finalize_hire', 'delete_applicant',
        ];
        foreach ($mutators as $fn) {
            $ok = preg_match(
                '/function\s+' . preg_quote($fn, '/') . '\s*\([^)]*\)\s*\{\s*\$this->_require_admin\(\)/s',
                $this->src
            );
            $this->assertSame(
                1,
                $ok,
                "Ats::{$fn}() must call \$this->_require_admin() as its first statement."
            );
        }
    }

    /** _require_admin/_require_view must resolve through RBAC, nothing else. */
    public function test_access_helpers_delegate_to_rbac(): void
    {
        $this->assertMatchesRegularExpression(
            "/function _require_admin\(\)\s*\{\s*require_permission\('HR',[^)]*'manage'\);\s*\}/s",
            $this->src,
            '_require_admin() must be exactly a manage-level require_permission call.'
        );
        $this->assertMatchesRegularExpression(
            "/function _require_view\(\)\s*\{\s*require_permission\('HR',[^)]*'view'\);\s*\}/s",
            $this->src,
            '_require_view() must be exactly a view-level require_permission call.'
        );
    }

    /**
     * The applicant id counter must be the one Hr.php advances. Two counters
     * over one collection issue duplicate APP ids, and because the write path
     * merges, the second candidate silently overwrites the first.
     */
    public function test_applicant_ids_come_from_the_shared_atomic_counter(): void
    {
        $this->assertStringContainsString(
            'hr_next_id($this->fs, \'APP\', \'Applicant\')',
            $this->src,
            "ATS must mint applicant ids via hr_next_id() with the same ('APP','Applicant') "
            . "pair Hr::_next_id() uses, so both modules advance one atomic counter."
        );
    }

    /**
     * finalize_hire() is the recruitment→Staff handoff and the browser retries
     * it. Its idempotency key must be the staff LINK, not the status flag —
     * Hr::update_applicant_status() can set ats_status='hired' by hand without
     * ever creating a staff record.
     */
    public function test_finalize_hire_is_idempotent_on_the_staff_link(): void
    {
        $body = $this->methodBody('finalize_hire');

        $this->assertStringContainsString('already_done', $body, 'finalize_hire() must short-circuit on repeat calls.');
        $this->assertMatchesRegularExpression(
            '/\$linkedStaff\s*!==\s*\'\'/',
            $body,
            'The idempotency guard must require a linked staff_id, not just ats_status.'
        );
    }

    /**
     * REGRESSION GUARD (live defect fixed in W1.3): the staff-existence check
     * read `getEntity('staff', "{$this->school_id}_{$staffId}")`, but
     * getEntity() already applies docId() = {schoolId}_{id}. The result was a
     * DOUBLE tenant prefix addressing a document that cannot exist — verified
     * against production data: 103 staff docs, 0 double-prefixed. Every
     * finalize_hire() therefore failed its integrity check, leaving the
     * application stuck at Selected and the job's vacancy count untouched
     * after the employee had already been created.
     */
    public function test_staff_lookup_does_not_double_prefix_the_tenant(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/getEntity\(\s*\'staff\'\s*,\s*"\{?\$this->school_id\}?_/',
            $this->src,
            'getEntity() already prefixes {schoolId}_ via docId(). Passing '
            . '"{$this->school_id}_{$staffId}" produces {school}_{school}_{id}, which '
            . 'never resolves, so the hire can never be finalized.'
        );
        $this->assertStringContainsString(
            "getEntity('staff', \$staffId)",
            $this->src,
            'The staff existence check must pass the bare staff id.'
        );
    }

    /** All 13 routed endpoints must still exist — this was a migration, not a redesign. */
    public function test_all_public_endpoints_are_preserved(): void
    {
        $endpoints = [
            'index', 'get_pipeline', 'get_applicant', 'save_applicant',
            'move_stage', 'reject_applicant', 'add_review', 'get_reviews',
            'get_convert_data', 'finalize_hire', 'get_jobs', 'delete_applicant',
        ];
        foreach ($endpoints as $fn) {
            $this->assertMatchesRegularExpression(
                '/public function\s+' . preg_quote($fn, '/') . '\s*\(/',
                $this->src,
                "Endpoint Ats::{$fn}() is routed in routes.php but no longer exists."
            );
        }
    }

    /**
     * Firestore rejects documents over 1 MiB. The review log is an unbounded
     * append on the applicant document, so without a cap a busy applicant
     * eventually becomes permanently unwritable — taking stage moves and the
     * hire flow down with it.
     */
    public function test_review_log_is_bounded(): void
    {
        $this->assertStringContainsString('MAX_REVIEWS', $this->src);
        $this->assertStringContainsString('array_slice($reviews, -self::MAX_REVIEWS)', $this->src);
    }

    /** Writes must not report success when Firestore rejected them (fail closed). */
    public function test_write_failures_are_surfaced_not_swallowed(): void
    {
        foreach (['save_applicant', 'move_stage', 'reject_applicant', 'add_review', 'delete_applicant'] as $fn) {
            $body = $this->methodBody($fn);
            $this->assertStringContainsString(
                'catch (\Exception $e)',
                $body,
                "Ats::{$fn}() must handle Firestore write failure."
            );
            $this->assertStringContainsString(
                '$this->json_error(',
                $body,
                "Ats::{$fn}() must report a failed write as an error — a swallowed "
                . "exception makes a denied action look like it succeeded."
            );
        }
    }

    /** Extract one method's body from the comment-stripped source. */
    private function methodBody(string $name): string
    {
        $start = strpos($this->src, "function {$name}(");
        $this->assertNotFalse($start, "Method {$name}() not found.");

        $next = strpos($this->src, 'public function ', $start + 10);
        return $next === false
            ? substr($this->src, $start)
            : substr($this->src, $start, $next - $start);
    }
}
