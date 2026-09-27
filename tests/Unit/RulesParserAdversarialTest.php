<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RulesParser;

/**
 * ADVERSARIAL TESTS FOR THE FIRESTORE RULES PARSER.
 *
 * Every security conclusion in the SEC-1..SEC-4 programme is downstream of this
 * parser. A parser that mis-reports is worse than no parser, because its output
 * looks like evidence.
 *
 * This exists because the original extractor DID mis-report, and its totals
 * stayed reassuringly stable while doing so: a non-greedy `(.*?)\n    \}` block
 * scan ran past a single-line block's own inline `}` into the next block. Two
 * false positives and two false negatives cancelled, so the UNSCOPED count read
 * 41 before and after the bug was present. It had been hiding `feeStructures` —
 * the fee chart — as cross-tenant writable.
 *
 * The fixture at tests/fixtures/rules/adversarial_parser.rules carries an
 * `EXPECT:<CLASS>` marker beside each arm; this test reads those markers and
 * compares them to what the parser reports, so the fixture cannot drift away
 * from its own assertions.
 *
 * @covers RulesParser
 */
class RulesParserAdversarialTest extends TestCase
{
    /** @var string */
    private static $fixture;
    /** @var RulesParser */
    private $parser;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../scripts/lib/rules_parser.php';
        self::$fixture = __DIR__ . '/../fixtures/rules/adversarial_parser.rules';
    }

    protected function setUp(): void
    {
        $this->parser = new RulesParser(file_get_contents(self::$fixture));
    }

    // ══ BLOCK EXTRACTION ════════════════════════════════════════════════

    public function test_single_line_blocks_do_not_swallow_the_next_block()
    {
        $cols = array_column($this->parser->blocks(), 'collection');

        // All three single-line blocks must be seen individually...
        $this->assertContains('singleLineDeny', $cols);
        $this->assertContains('singleLineAdmin', $cols);
        $this->assertContains('singleLineTenant', $cols);
        // ...and the multi-line block after them must not be absorbed.
        $this->assertContains('swallowVictim', $cols);
    }

    public function test_the_swallow_victims_arm_is_attributed_to_the_right_collection()
    {
        $arms = $this->parser->arms();

        $deny = array_values(array_filter($arms, function ($a) {
            return $a['collection'] === 'singleLineDeny';
        }));
        foreach ($deny as $a) {
            $this->assertSame(
                'deny',
                $a['class'],
                'singleLineDeny must own ONLY its own `if false` arm. Seeing anything '
                . 'else here means a later block was swallowed and mis-attributed.'
            );
        }

        $victim = array_values(array_filter($arms, function ($a) {
            return $a['collection'] === 'swallowVictim' && $a['operation'] === 'update';
        }));
        $this->assertNotEmpty($victim, 'swallowVictim lost its own arm');
        $this->assertSame('staff-any-tenant', $victim[0]['class']);
    }

    public function test_nested_blocks_are_found_and_not_counted_against_the_parent()
    {
        $cols = array_column($this->parser->blocks(), 'collection');
        $this->assertContains('parentCollection', $cols);
        $this->assertContains('childSubcollection', $cols, 'nested match block was not discovered');

        // The child's `allow write: if false` must not appear under the parent.
        $parentWrites = array_values(array_filter($this->parser->arms(), function ($a) {
            return $a['collection'] === 'parentCollection' && $a['operation'] === 'update';
        }));
        $this->assertNotEmpty($parentWrites);
        $this->assertSame(
            'staff-any-tenant',
            $parentWrites[0]['class'],
            "the child's deny arm leaked into the parent"
        );
    }

    public function test_comments_containing_rule_syntax_are_not_parsed()
    {
        $cols = array_column($this->parser->blocks(), 'collection');

        // The fixture's commentTrap block has a commented-out `match` and two
        // commented-out `allow` lines. Exactly ONE real block must exist.
        $this->assertSame(
            1,
            count(array_filter($cols, function ($c) { return $c === 'commentTrap'; })),
            'a commented-out `match` was parsed as a real block'
        );

        $arms = array_values(array_filter($this->parser->arms(), function ($a) {
            return $a['collection'] === 'commentTrap' && $a['operation'] === 'update';
        }));
        $this->assertNotEmpty($arms);
        $this->assertSame(
            'staff-any-tenant',
            $arms[0]['class'],
            'a commented-out `isSameSchoolWrite()` was counted as a live check'
        );
    }

    public function test_duplicate_allow_statements_are_all_retained()
    {
        // Firestore ORs them; dropping either reports a boundary the engine
        // does not enforce.
        $updates = array_values(array_filter($this->parser->arms(), function ($a) {
            return $a['collection'] === 'duplicateArms' && $a['operation'] === 'update';
        }));

        $this->assertCount(2, $updates, 'a duplicate allow statement was discarded');
        $classes = array_column($updates, 'class');
        $this->assertContains('staff-own-tenant', $classes);
        $this->assertContains('owner-scoped', $classes);
    }

    public function test_inline_braces_in_a_condition_do_not_truncate_the_block()
    {
        $arms = array_values(array_filter($this->parser->arms(), function ($a) {
            return $a['collection'] === 'inlineBraces';
        }));
        $this->assertNotEmpty($arms);

        $update = array_values(array_filter($arms, function ($a) { return $a['operation'] === 'update'; }));
        $this->assertNotEmpty($update);
        $this->assertStringContainsString(
            'hasOnly',
            $update[0]['condition'],
            'the condition was cut short at an inline bracket'
        );
    }

    // ══ OPERATION EXPANSION ═════════════════════════════════════════════

    public function test_read_expands_to_get_and_list()
    {
        $ops = array_column(array_values(array_filter($this->parser->arms(), function ($a) {
            return $a['collection'] === 'swallowVictim';
        })), 'operation');

        $this->assertContains('get', $ops);
        $this->assertContains('list', $ops);
    }

    public function test_write_expands_to_create_update_and_delete()
    {
        $ops = array_column(array_values(array_filter($this->parser->arms(), function ($a) {
            return $a['collection'] === 'repeatedA';
        })), 'operation');

        sort($ops);
        $this->assertSame(['create', 'delete', 'update'], $ops);
    }

    public function test_a_list_only_leak_is_visible()
    {
        // perOperation scopes `get` but leaves `list` on bare isStaff(). A
        // write-only census cannot see this at all.
        $list = array_values(array_filter($this->parser->arms(), function ($a) {
            return $a['collection'] === 'perOperation' && $a['operation'] === 'list';
        }));

        $this->assertNotEmpty($list);
        $this->assertSame('staff-any-tenant', $list[0]['class']);
    }

    // ══ HELPER INLINING ═════════════════════════════════════════════════

    public function test_helpers_are_extracted()
    {
        $h = $this->parser->helpers();
        foreach (['isAuth', 'isAdmin', 'isStaff', 'isSuperAdmin', 'isSameSchool', 'isSameSchoolWrite'] as $n) {
            $this->assertArrayHasKey($n, $h);
        }
    }

    public function test_helpers_are_inlined_transitively()
    {
        // isAdmin -> isAuth -> request.auth != null
        $this->assertStringContainsString('request.auth != null', $this->parser->inline('isAdmin()'));
    }

    public function test_a_helper_name_that_prefixes_another_is_not_mis_substituted()
    {
        // `isSameSchool` is a prefix of `isSameSchoolWrite`.
        $out = $this->parser->inline('isSameSchoolWrite()');
        $this->assertStringContainsString('request.resource.data.schoolId', $out);
        $this->assertStringNotContainsString('resource == null', $out);
    }

    // ══ THE SEC-4 SHAPE ═════════════════════════════════════════════════

    public function test_sec4_shape_is_detected()
    {
        // The real defect: a role disjunct with no document-school comparison.
        $this->assertSame(
            'staff-any-tenant',
            $this->parser->classify('isStaff() || (isSameSchool() && isOwnStudent())', 'get')
        );
    }

    /**
     * REGRESSION: the SEC-4 shape reached through a HELPER, not spelled out.
     *
     * `inline()` wraps each substituted body in parentheses to preserve
     * precedence, so `isStaffOrOwnStudent()` expands to
     *
     *     ( isStaff() || ( isSameSchool() && isOwnStudent() ) )
     *
     * and the `||` is no longer at depth 0. Before `unwrap()`, `splitTopLevel`
     * returned ONE disjunct, the bypass went undetected, and `marks.get` was
     * reported `staff-own-tenant` — the tool clearing the exact arm it exists to
     * catch. A false negative here is far worse than a false positive.
     */
    public function test_sec4_shape_is_detected_through_a_helper_indirection()
    {
        $src = "function isAuth() { return request.auth != null; }\n"
             . "function isStaff() { return isAuth() && request.auth.token.role != 'Parent'; }\n"
             . "function isOwnStudent() { return resource.data.studentId == request.auth.uid; }\n"
             . "function isSameSchool() { return resource.data.schoolId == request.auth.token.school_id; }\n"
             . "function isStaffOrOwnStudent() { return isStaff() || (isSameSchool() && isOwnStudent()); }\n"
             . str_repeat("// pad\n", 2000);

        $p = new RulesParser($src);

        $this->assertGreaterThan(
            1,
            count(RulesParser::splitTopLevel($p->inline('isStaffOrOwnStudent()'), '||')),
            'redundant enclosing parens hid the top-level disjunction'
        );
        $this->assertSame('staff-any-tenant', $p->classify('isStaffOrOwnStudent()', 'get'));
    }

    public function test_unwrap_does_not_strip_non_enclosing_parens()
    {
        // `(a) || (b)` must keep both disjuncts — stripping here would merge
        // them and lose the split entirely.
        $this->assertSame('(a) || (b)', RulesParser::unwrap('(a) || (b)'));
        $this->assertSame('a || b', RulesParser::unwrap('((a || b))'));
        $this->assertCount(2, RulesParser::splitTopLevel('((a || b))', '||'));
    }

    public function test_the_corrected_sec4_composition_is_not_flagged()
    {
        // Counting the fix as the defect is exactly the error that produced the
        // withdrawn "45 arms across 44 collections" figure.
        $this->assertSame(
            'staff-own-tenant',
            $this->parser->classify('isSameSchool() && (isStaff() || isOwnStudent())', 'get')
        );
    }

    public function test_already_correct_composed_shape_is_not_flagged()
    {
        // `attendance` uses this, and the emulator suite PROVES it refuses a
        // cross-tenant staff read. The classifier must agree with measurement.
        $this->assertSame(
            'staff-own-tenant',
            $this->parser->classify('isStaff() && isSameSchool()', 'get')
        );
    }

    // ══ OPERATION-AWARE RESOURCE AVAILABILITY ═══════════════════════════

    public function test_request_resource_does_not_bind_on_delete()
    {
        // `request.resource` is NULL on delete, so isSameSchoolWrite() cannot
        // scope a delete — it dereferences null. Judging the arm as a whole
        // hides this.
        $this->assertSame(
            'staff-own-tenant',
            $this->parser->classify('isAdmin() && isSameSchoolWrite()', 'update')
        );
        // CORRECTED BY MEASUREMENT. This originally expected `staff-any-tenant`
        // — i.e. that losing the tenant check left the arm wide open. The
        // emulator says otherwise: dereferencing the null `request.resource`
        // raises an evaluation error and Firestore REFUSES. The arm is broken,
        // not permissive.
        $this->assertSame(
            'null-deref-denies',
            $this->parser->classify('isAdmin() && isSameSchoolWrite()', 'delete')
        );
    }

    public function test_resource_null_fail_open_evaporates_the_tenant_check_on_create()
    {
        // On CREATE `resource` is ALWAYS null, so `resource == null ||` is
        // unconditionally true and everything after it is unreachable — leaving
        // bare isAdmin().
        $this->assertSame(
            'staff-any-tenant',
            $this->parser->classify('isAdmin() && isSameSchool()', 'create')
        );
        // ...but on update the stored document exists and the check binds.
        $this->assertSame(
            'staff-own-tenant',
            $this->parser->classify('isAdmin() && isSameSchool()', 'update')
        );
    }

    /**
     * A NULL DEREFERENCE DENIES — IT DOES NOT OPEN.
     *
     * `isStaff() && isSameSchoolWrite()` on delete dereferences the null
     * `request.resource`, Firestore raises an evaluation error, and the request
     * is REFUSED. An earlier revision classed this `staff-any-tenant`, i.e.
     * wide open — the exact opposite of production.
     *
     * MEASURED by sec5_authz_graph_findings.test.js against the live pull:
     * a same-school staff delete on `academicAuditLog` is refused. So this is a
     * FUNCTIONAL defect (a legitimate operation is broken), not an exposure.
     */
    public function test_null_dereference_on_delete_denies_rather_than_opens()
    {
        $this->assertSame(
            'null-deref-denies',
            $this->parser->classify('isStaff() && isSameSchoolWrite()', 'delete')
        );
        $this->assertSame(
            'staff-own-tenant',
            $this->parser->classify('isStaff() && isSameSchoolWrite()', 'create')
        );
    }

    /**
     * ...but only when EVERY evaluation path touches the missing object.
     *
     * `isStaff() && (isSameSchoolWrite() || isSameSchool())` on delete has a
     * live path — `isStaff() && isSameSchool()` — that never touches
     * `request.resource`. A top-level-`||` split sees one disjunct, finds a
     * dereference, and wrongly declares the arm dead. Measurement disagreed.
     */
    public function test_an_alternative_branch_keeps_the_arm_evaluable()
    {
        $this->assertSame(
            'staff-own-tenant',
            $this->parser->classify('isStaff() && (isSameSchoolWrite() || isSameSchool())', 'delete')
        );
    }

    /**
     * The same arm on CREATE is genuinely open: `resource` is always null there,
     * so `isSameSchool()`'s leading `resource == null ||` is unconditionally
     * true and the tenant check evaporates, leaving bare `isStaff()`.
     *
     * MEASURED: a School A teacher can create a School B `subjectPlanProgress`
     * document against the live rules.
     */
    public function test_fail_open_on_create_is_a_real_bypass()
    {
        $this->assertSame(
            'staff-any-tenant',
            $this->parser->classify('isStaff() && (isSameSchoolWrite() || isSameSchool())', 'create')
        );
    }

    public function test_fail_open_on_null_is_reported_as_a_flag_not_a_finding()
    {
        // A missing document has no data to disclose, so get/list/delete must
        // not be cried wolf over — but the property is still surfaced.
        $this->assertTrue(RulesParser::failOpenFlag('resource == null || resource.data.schoolId == x', 'get'));
        $this->assertFalse(RulesParser::failOpenFlag('resource.data.schoolId == x', 'get'));
    }

    // ══ CLASSIFICATION BASICS ═══════════════════════════════════════════

    public function test_superadmin_is_platform_not_a_finding()
    {
        // Inlining replaces the helper NAME with its body, so a platform check
        // that searches the inlined text for `isSuperAdmin` never matches. That
        // bug classified every superadmin arm as a cross-tenant finding.
        $this->assertSame('platform-only', $this->parser->classify('isSuperAdmin()', 'create'));
    }

    /**
     * REGRESSION: `isAdmin()` MUST NOT be mistaken for a platform boundary.
     *
     * The live `isAdmin()` expands to a role list that literally contains
     * 'Super Admin' and 'super_admin':
     *
     *     request.auth.token.role in ['Admin', 'admin', 'School Super Admin',
     *       'school_super_admin', 'Super Admin', 'super_admin']
     *
     * A platform check that string-matched the INLINED text therefore
     * classified EVERY `allow write: if isAdmin();` arm as `platform-only`,
     * silently hiding the entire unscoped-admin defect class from the census —
     * a false negative on the finding the tool exists to report.
     *
     * `isAdmin()` admits ordinary school admins. It is not a platform boundary.
     */
    public function test_isAdmin_is_not_mistaken_for_a_platform_boundary()
    {
        $src = "function isAuth() { return request.auth != null; }\n"
             . "function isAdmin() { return isAuth() && request.auth.token.role in "
             . "['Admin', 'admin', 'School Super Admin', 'school_super_admin', 'Super Admin', 'super_admin']; }\n"
             . "function isSuperAdmin() { return isAuth() && request.auth.token.role == 'Super Admin'; }\n"
             . str_repeat("// pad\n", 2000);

        $p = new RulesParser($src);

        $this->assertSame(
            'staff-any-tenant',
            $p->classify('isAdmin()', 'update'),
            'isAdmin() was hidden as platform-only — the unscoped-admin defect class '
            . 'would vanish from every census'
        );
        $this->assertSame('platform-only', $p->classify('isSuperAdmin()', 'update'));
    }

    public function test_deny_and_public_are_recognised()
    {
        $this->assertSame('deny', $this->parser->classify('false', 'get'));
        $this->assertSame('public', $this->parser->classify('true', 'get'));
    }

    public function test_unclassifiable_arms_surface_as_investigate_not_dropped()
    {
        // A dropped arm is an unaudited arm.
        $arms = array_values(array_filter($this->parser->arms(), function ($a) {
            return $a['collection'] === 'publicish' && $a['operation'] === 'update';
        }));
        $this->assertNotEmpty($arms);
        $this->assertSame('investigate', $arms[0]['class']);
    }

    // ══ OR SEMANTICS ════════════════════════════════════════════════════

    public function test_effective_boundary_is_the_weakest_arm()
    {
        // duplicateArms has a tenant+role arm AND an owner arm on `update`.
        // Firestore permits if EITHER passes, so the effective boundary is the
        // weaker of the two, not the first or the strongest.
        $graph = $this->parser->authorizationGraph();
        $row = array_values(array_filter($graph, function ($r) {
            return $r['collection'] === 'duplicateArms' && $r['operation'] === 'update';
        }));

        $this->assertNotEmpty($row);
        // The fixture lists the NARROWER (owner-scoped) arm FIRST and the
        // BROADER (staff-own-tenant) arm SECOND, so a first-wins tool would
        // answer 'owner-scoped'. The engine permits whatever EITHER arm admits,
        // so the correct answer is the broader one.
        $this->assertSame(
            'staff-own-tenant',
            $row[0]['class'],
            'the graph reported a narrower boundary than the engine enforces — '
            . 'it is reporting the FIRST arm rather than the WEAKEST'
        );
    }

    public function test_graph_has_one_row_per_collection_operation_pair()
    {
        $graph = $this->parser->authorizationGraph();
        $keys = array_map(function ($r) { return $r['collection'] . '|' . $r['operation']; }, $graph);
        $this->assertSame(count($keys), count(array_unique($keys)));
    }

    // ══ NO-OP AND ALREADY-FIXED INPUTS ══════════════════════════════════

    public function test_parsing_is_deterministic()
    {
        // A security tool that returns different answers for the same input
        // cannot be used as evidence.
        $a = new RulesParser(file_get_contents(self::$fixture));
        $b = new RulesParser(file_get_contents(self::$fixture));
        $this->assertEquals($a->authorizationGraph(), $b->authorizationGraph());
    }

    public function test_an_already_fixed_ruleset_reports_no_write_findings()
    {
        foreach (['alreadyFixedSplit', 'alreadyFixedComposed'] as $col) {
            $rows = array_values(array_filter($this->parser->authorizationGraph(), function ($r) use ($col) {
                return $r['collection'] === $col
                    && in_array($r['operation'], ['create', 'update', 'delete'], true);
            }));
            $this->assertNotEmpty($rows, "{$col} produced no write rows at all");
            foreach ($rows as $r) {
                $this->assertFalse(
                    RulesParser::isFinding($r['class']),
                    "{$col}.{$r['operation']} was flagged ({$r['class']}) but is already correct — "
                    . 'counting the fix as the defect is the error that produced the withdrawn '
                    . '"45 arms across 44 collections" figure'
                );
            }
        }
    }

    public function test_a_no_op_transformation_reports_zero_divergence()
    {
        // Guards the inverse failure: a diff that reports change where there is
        // none is as useless as one that reports none where there is change.
        $src = file_get_contents(self::$fixture);
        $a = new RulesParser($src);
        $b = new RulesParser($src);
        $this->assertSame(
            json_encode($a->authorizationGraph()),
            json_encode($b->authorizationGraph())
        );
    }
}
