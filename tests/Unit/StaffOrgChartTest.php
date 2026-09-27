<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Staff HR Advanced Capability Programme — Phase 8: Reporting Hierarchy /
 * Org Chart (2026-08-19).
 *
 * Confirmed green-field by a dedicated research pass: `reportingManagerId`
 * has been stored on the staff doc and cycle-validated at WRITE time since
 * Phase 2C (Staff_employment_history_service::validateManagerAssignment())
 * but was never RENDERED as a hierarchy anywhere — HR could set a manager
 * and never see the resulting shape.
 *
 * Two things this test file protects, beyond "the endpoint exists":
 *
 * 1. STRICTLY READ-ONLY, NO SECOND SOURCE OF TRUTH. The tree is derived on
 *    every request from the same `reportingManagerId` field the existing
 *    writers own. There is deliberately no denormalised reports[] array and
 *    no cached tree doc — nothing that can drift from the field itself.
 *
 * 2. DIAGNOSTICS ARE NOT OPTIONAL. validateManagerAssignment() deliberately
 *    tolerates several states it cannot safely reject at write time (a
 *    broken link mid-chain "ends the walk without rejecting"; a
 *    pre-existing loop makes it "stop, don't block"; a manager deactivated
 *    AFTER assignment is never re-checked). This is the first surface that
 *    can see them at all, so it must report them rather than render a
 *    quietly-incomplete chart that reads as healthy — the same reasoning
 *    behind Work_schedules::_blockedStaff().
 *
 * The graph algorithm itself was additionally verified BEHAVIOURALLY (not
 * just structurally) against 12 hand-built rosters — clean tree, orphan
 * manager, inactive manager, active-vs-inactive report, pure cycle, a tail
 * feeding into a cycle in both iteration orders, self-reference, 20-deep
 * chain, two coexisting cycles, empty roster, and a fully flat org — 31/31
 * assertions passing. That run caught a real defect in the first draft: a
 * node that merely FED INTO a cycle was being marked as part of the loop
 * and named in the reported cycle path.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller/view code).
 */
class StaffOrgChartTest extends TestCase
{
    private string $hrSrc;
    private string $viewSrc;
    private string $routesSrc;

    protected function setUp(): void
    {
        foreach ([
            'hrSrc'     => __DIR__ . '/../../application/controllers/Hr.php',
            'viewSrc'   => __DIR__ . '/../../application/views/hr/index.php',
            'routesSrc' => __DIR__ . '/../../application/config/routes.php',
        ] as $prop => $path) {
            $raw = file_get_contents($path);
            $this->assertNotFalse($raw);
            $out = '';
            foreach (token_get_all($raw) as $token) {
                if (is_array($token)) {
                    if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                    $out .= $token[1];
                } else {
                    $out .= $token;
                }
            }
            $this->$prop = $out;
        }
    }

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
                if ($depth === 0) return substr($src, $openBrace, $i - $openBrace + 1);
            }
        }
        $this->fail('Unbalanced braces while extracting function body.');
    }

    private function fn(string $name): string
    {
        $start = strpos($this->hrSrc, 'function ' . $name . '(');
        $this->assertNotFalse($start, "{$name}() must exist in Hr.php.");
        return $this->extractFunctionBody($this->hrSrc, $start);
    }

    private function jsFn(string $name): string
    {
        $start = strpos($this->viewSrc, 'function ' . $name . '(');
        $this->assertNotFalse($start, "JS function {$name}() must exist.");
        return $this->extractFunctionBody($this->viewSrc, $start);
    }

    // ── Routing / tab registration ─────────────────────────────────────

    public function test_route_and_tab_registered(): void
    {
        $this->assertStringContainsString("\$route['hr/get_org_chart']", $this->routesSrc);
        $this->assertStringContainsString("\$route['hr/orgchart']", $this->routesSrc);
        $start = strpos($this->hrSrc, 'function index(');
        $body = $this->extractFunctionBody($this->hrSrc, $start);
        $this->assertStringContainsString("'orgchart'", $body);
    }

    // ── READ-ONLY / no second source of truth ─────────────────────────

    public function test_endpoint_is_view_gated(): void
    {
        $body = $this->fn('get_org_chart');
        $this->assertStringContainsString("'HR', 'view'", $body);
    }

    public function test_endpoint_writes_nothing_at_all(): void
    {
        $body = $this->fn('get_org_chart');
        foreach (['->set(', '->update(', 'setEntity', 'updateEntity', 'removeEntity', 'commitBatch'] as $writeCall) {
            $this->assertStringNotContainsString($writeCall, $body,
                "get_org_chart() must be strictly read-only — found a write via {$writeCall}.");
        }
    }

    public function test_hierarchy_is_derived_never_denormalised_or_cached(): void
    {
        // No reports[] array, no cached tree doc, no new collection — the
        // tree comes from reportingManagerId every single request.
        $body = $this->fn('get_org_chart');
        $this->assertStringContainsString("\$d['reportingManagerId']", $body);
        $this->assertStringNotContainsString('orgChart', $body,
            'no orgChart collection/cache may be introduced — the field IS the source of truth.');
        $this->assertStringNotContainsString("'reports'", $body);
    }

    public function test_single_bulk_read_no_n_plus_one(): void
    {
        $body = $this->fn('get_org_chart');
        $this->assertSame(1, substr_count($body, 'schoolWhere('),
            'the whole roster must load in ONE query — a per-employee read would be an N+1 that grows with headcount.');
        $this->assertStringNotContainsString('->get(\'staff\'', $body);
    }

    public function test_reads_all_staff_not_just_active(): void
    {
        // An Inactive manager who still has Active reports is precisely a
        // defect this surface exists to reveal; filtering here would hide it.
        $body = $this->fn('get_org_chart');
        $this->assertStringContainsString("schoolWhere('staff', [])", $body);
        $this->assertStringNotContainsString("['status', '==', 'Active']", $body);
    }

    // ── Diagnostics: every tolerated-at-write-time state is reported ──

    public function test_all_five_issue_types_are_detected(): void
    {
        $body = $this->fn('get_org_chart');
        foreach (['self_reference', 'orphan_manager', 'inactive_manager', 'cycle', 'unreachable'] as $type) {
            $this->assertStringContainsString("'type' => '{$type}'", $body,
                "the {$type} diagnostic must be reported, not silently skipped.");
        }
    }

    public function test_inactive_manager_only_flagged_when_the_report_is_active(): void
    {
        // An inactive employee under an inactive manager is a normal
        // historical state, not a defect worth flagging.
        $body = $this->fn('get_org_chart');
        $this->assertStringContainsString(
            "strtolower(trim(\$nodes[\$mid]['status'])) !== 'active'", $body);
        $this->assertStringContainsString(
            "&& strtolower(trim(\$n['status'])) === 'active'", $body);
    }

    public function test_cycle_detection_is_bounded(): void
    {
        $this->assertStringContainsString('ORG_CHART_MAX_DEPTH = 25', $this->hrSrc);
        $body = $this->fn('get_org_chart');
        $this->assertStringContainsString('$depth++ < self::ORG_CHART_MAX_DEPTH', $body);
    }

    public function test_cycle_marks_only_the_true_loop_not_the_tail_feeding_into_it(): void
    {
        // The specific defect the behavioural harness caught in the first
        // draft: walking D→A where A→B→C→A is the cycle must mark ONLY
        // A,B,C — not D, which merely feeds in. Slicing the ordered walk
        // from the loop-closing index is what makes that correct; a plain
        // lookup-set would mark the whole walk.
        $body = $this->fn('get_org_chart');
        $this->assertStringContainsString('$loop = array_slice($order, $pos[$cursor]);', $body);
        $this->assertStringContainsString('foreach ($loop as $pid) $cyclic[$pid] = true;', $body);
    }

    public function test_a_walk_that_hit_a_cycle_never_marks_its_path_safe(): void
    {
        // $safe means "provably reaches a root". A walk that ran into a
        // cycle proves the opposite, so it must not seed that memo.
        $body = $this->fn('get_org_chart');
        $this->assertStringContainsString('if (!$hitCycle) {', $body);
        $this->assertStringContainsString('foreach ($order as $pid) $safe[$pid] = true;', $body);
    }

    public function test_reachability_pass_ensures_nothing_silently_vanishes(): void
    {
        // The client renders downward from roots; a node no root can reach
        // would simply never be drawn. An incomplete chart that LOOKS
        // complete is the exact failure mode being guarded against.
        $body = $this->fn('get_org_chart');
        $this->assertStringContainsString('$reachable = [];', $body);
        $this->assertStringContainsString('$stack = $roots;', $body);
        $this->assertStringContainsString("'type' => 'unreachable'", $body);
    }

    public function test_cyclic_nodes_are_reported_not_rendered_as_fake_roots(): void
    {
        $body = $this->fn('get_org_chart');
        $this->assertStringContainsString('if (isset($cyclic[$sid])) continue;', $body);
    }

    public function test_orphan_managed_node_becomes_a_root_so_its_branch_stays_visible(): void
    {
        $body = $this->fn('get_org_chart');
        $this->assertStringContainsString(
            "if (\$mid === '' || \$mid === \$sid || !isset(\$nodes[\$mid])) \$roots[] = \$sid;", $body);
    }

    // ── No entanglement with other modules ────────────────────────────

    public function test_org_chart_touches_no_unrelated_collections(): void
    {
        $body = $this->fn('get_org_chart');
        foreach (['staffScheduleAssignments', 'staffEmploymentTimeline', 'salarySlips', 'appraisals', 'staffTraining'] as $col) {
            $this->assertStringNotContainsString($col, $body);
        }
    }

    // ── View wiring ────────────────────────────────────────────────────

    public function test_panel_tab_and_loader_registered_in_the_view(): void
    {
        $this->assertStringContainsString("'orgchart'    => ['panel'=>'panelOrgChart',", $this->viewSrc);
        $this->assertStringContainsString('id="panelOrgChart"', $this->viewSrc);
        $this->assertStringContainsString("case 'orgchart':     loadOrgChart(); break;", $this->viewSrc);
    }

    public function test_view_calls_only_the_readonly_endpoint(): void
    {
        $body = $this->jsFn('loadOrgChart');
        $this->assertStringContainsString("getJSON('hr/get_org_chart')", $body);
        $this->assertStringNotContainsString('post(', $body,
            'the org chart is a read-only surface — it must never POST.');
    }

    public function test_issues_panel_is_always_rendered_never_hidden_behind_a_toggle(): void
    {
        $body = $this->jsFn('renderOrgChart');
        $this->assertStringContainsString('if(issues.length){', $body);
        $this->assertStringContainsString('hierarchy issue', $body);
    }

    public function test_search_keeps_ancestors_so_a_match_is_never_orphaned(): void
    {
        $body = $this->jsFn('renderOrgChart');
        $this->assertStringContainsString('function subtreeHasMatch(', $body);
        $this->assertStringContainsString('if(q && !subtreeHasMatch(sid)) return \'\';', $body);
    }

    public function test_client_render_is_depth_bounded_too(): void
    {
        $body = $this->jsFn('renderOrgChart');
        $this->assertStringContainsString('if(depth > 40) return', $body);
    }

    public function test_org_functions_exposed_on_the_hr_namespace(): void
    {
        $start = strpos($this->viewSrc, 'window.HR = {');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->viewSrc, $start);
        foreach (['loadOrgChart', 'renderOrgChart', 'toggleOrgNode'] as $name) {
            $this->assertStringContainsString($name, $body);
        }
    }

    // ── No id/function shadowing among the Phase 8 additions ──────────

    public function test_new_org_ids_do_not_shadow_new_org_function_names(): void
    {
        $newIds = ['panelOrgChart', 'orgSearch', 'orgShowInactive', 'orgIssuesWrap', 'orgChartWrap'];
        $newFns = ['loadOrgChart', 'renderOrgChart', 'toggleOrgNode'];
        $this->assertEmpty(array_intersect($newIds, $newFns),
            'a DOM id must never share a name with a global function — see the previewStaffPhoto clobbering bug.');
        foreach ($newIds as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $this->viewSrc, "expected id={$id}");
        }
        foreach ($newFns as $fnName) {
            $this->assertStringContainsString('function ' . $fnName . '(', $this->viewSrc, "expected function {$fnName}()");
        }
    }
}
