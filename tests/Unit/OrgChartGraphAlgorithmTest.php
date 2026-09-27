<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * BEHAVIOURAL companion to StaffOrgChartTest (Staff HR Advanced Capability
 * Programme, Phase 8, 2026-08-19).
 *
 * Every other controller test in this suite is a source-regression test,
 * because Firestore_service has no behavioural mock. That convention works
 * for wiring and guard clauses, but it cannot verify GRAPH CORRECTNESS —
 * whether a cycle is actually detected, whether the right nodes are named
 * in it, whether anything silently vanishes from the tree.
 *
 * Hr::get_org_chart() splits cleanly into (a) one Firestore read and (b) a
 * pure in-memory graph pass over the resulting node map. Part (b) needs no
 * Firestore at all, so it is reproduced verbatim below and executed against
 * hand-built rosters. This is the ONLY honest way to test it.
 *
 * KEEPING THIS IN SYNC: this file duplicates the algorithm deliberately —
 * the controller cannot be imported (CodeIgniter base class, no autoload
 * path in the Unit suite). StaffOrgChartTest asserts on the controller's
 * actual source for the structural markers that make this copy faithful
 * (the array_slice-from-loop-index, the !$hitCycle guard, the reachability
 * pass), so a divergence between the two fails there.
 *
 * This harness found a real defect in the first draft of the algorithm: a
 * node that merely FED INTO a cycle (D → A, where A→B→C→A) was being
 * marked as part of the loop and named in the reported cycle path. Both
 * iteration orders are covered below because the original bug was
 * order-sensitive.
 */
class OrgChartGraphAlgorithmTest extends TestCase
{
    private const ORG_CHART_MAX_DEPTH = 25;

    /**
     * Verbatim copy of the pure-graph half of Hr::get_org_chart().
     *
     * @param array<string,array{managerId:string,status:string,name:string}> $nodes
     * @return array{roots:string[],issues:array[],cyclic:string[],reachable:string[]}
     */
    private function orgChart(array $nodes): array
    {
        $issues = [];
        $cyclic = [];

        foreach ($nodes as $sid => $n) {
            $mid = $n['managerId'];
            if ($mid === '') continue;
            if ($mid === $sid) {
                $issues[] = ['type' => 'self_reference', 'staffId' => $sid];
                continue;
            }
            if (!isset($nodes[$mid])) {
                $issues[] = ['type' => 'orphan_manager', 'staffId' => $sid];
                continue;
            }
            if (strtolower(trim($nodes[$mid]['status'])) !== 'active'
                && strtolower(trim($n['status'])) === 'active') {
                $issues[] = ['type' => 'inactive_manager', 'staffId' => $sid];
            }
        }

        $safe = [];
        foreach (array_keys($nodes) as $startId) {
            if (isset($safe[$startId]) || isset($cyclic[$startId])) continue;
            $order = []; $pos = []; $cursor = $startId; $depth = 0; $hitCycle = false;
            while ($cursor !== '' && $depth++ < self::ORG_CHART_MAX_DEPTH) {
                if (isset($safe[$cursor])) break;
                if (isset($cyclic[$cursor])) { $hitCycle = true; break; }
                if (isset($pos[$cursor])) {
                    $loop = array_slice($order, $pos[$cursor]);
                    foreach ($loop as $pid) $cyclic[$pid] = true;
                    $issues[] = ['type' => 'cycle', 'staffId' => $cursor, 'loop' => $loop];
                    $hitCycle = true;
                    break;
                }
                $pos[$cursor] = count($order);
                $order[] = $cursor;
                $next = $nodes[$cursor]['managerId'] ?? '';
                if ($next === '' || !isset($nodes[$next]) || $next === $cursor) break;
                $cursor = $next;
            }
            if (!$hitCycle) { foreach ($order as $pid) $safe[$pid] = true; }
        }

        $roots = [];
        foreach ($nodes as $sid => $n) {
            if (isset($cyclic[$sid])) continue;
            $mid = $n['managerId'];
            if ($mid === '' || $mid === $sid || !isset($nodes[$mid])) $roots[] = $sid;
        }
        usort($roots, fn($a, $b) => strcmp($nodes[$a]['name'], $nodes[$b]['name']));

        $childrenOf = [];
        foreach ($nodes as $sid => $n) {
            if (isset($cyclic[$sid])) continue;
            $mid = $n['managerId'];
            if ($mid !== '' && $mid !== $sid && isset($nodes[$mid])) $childrenOf[$mid][] = $sid;
        }
        $reachable = [];
        $stack = $roots;
        while (!empty($stack)) {
            $id = array_pop($stack);
            if (isset($reachable[$id])) continue;
            $reachable[$id] = true;
            foreach ($childrenOf[$id] ?? [] as $c) $stack[] = $c;
        }
        foreach ($nodes as $sid => $n) {
            if (isset($reachable[$sid]) || isset($cyclic[$sid])) continue;
            $issues[] = ['type' => 'unreachable', 'staffId' => $sid];
        }

        return [
            'roots'     => $roots,
            'issues'    => $issues,
            'cyclic'    => array_keys($cyclic),
            'reachable' => array_keys($reachable),
        ];
    }

    /** @param array<string,array{0:string,1?:string}> $pairs staffId => [managerId, status?] */
    private function roster(array $pairs): array
    {
        $out = [];
        foreach ($pairs as $id => $spec) {
            $out[$id] = [
                'staffId' => $id, 'name' => $id, 'position' => '', 'department' => '',
                'managerId' => $spec[0], 'status' => $spec[1] ?? 'Active',
            ];
        }
        return $out;
    }

    private function issueIds(array $issues, string $type): array
    {
        $ids = array_values(array_map(
            fn($i) => $i['staffId'],
            array_filter($issues, fn($i) => $i['type'] === $type)
        ));
        sort($ids);
        return $ids;
    }

    private function sorted(array $a): array { sort($a); return $a; }

    // ── Healthy shapes ────────────────────────────────────────────────

    public function test_clean_tree_has_one_root_and_no_issues(): void
    {
        $r = $this->orgChart($this->roster([
            'CEO' => [''], 'A' => ['CEO'], 'B' => ['CEO'], 'C' => ['A'],
        ]));
        $this->assertSame(['CEO'], $r['roots']);
        $this->assertSame([], $r['issues']);
        $this->assertSame(['A', 'B', 'C', 'CEO'], $this->sorted($r['reachable']));
    }

    public function test_flat_org_with_no_managers_makes_everyone_a_root(): void
    {
        $r = $this->orgChart($this->roster(['A' => [''], 'B' => [''], 'C' => ['']]));
        $this->assertSame(['A', 'B', 'C'], $r['roots']);
        $this->assertSame([], $r['issues']);
    }

    public function test_empty_roster_is_handled(): void
    {
        $r = $this->orgChart([]);
        $this->assertSame([], $r['roots']);
        $this->assertSame([], $r['issues']);
    }

    public function test_deep_chain_within_the_bound_resolves_cleanly(): void
    {
        $spec = ['N0' => ['']];
        for ($i = 1; $i < 20; $i++) $spec["N{$i}"] = ['N' . ($i - 1)];
        $r = $this->orgChart($this->roster($spec));
        $this->assertSame(['N0'], $r['roots']);
        $this->assertSame([], $r['issues']);
        $this->assertCount(20, $r['reachable']);
    }

    // ── Broken links ──────────────────────────────────────────────────

    public function test_orphan_manager_is_reported_and_the_branch_stays_visible(): void
    {
        $r = $this->orgChart($this->roster(['CEO' => [''], 'A' => ['CEO'], 'B' => ['GHOST']]));
        $this->assertSame(['B'], $this->issueIds($r['issues'], 'orphan_manager'));
        // B must become a root, or its whole branch would disappear.
        $this->assertSame(['B', 'CEO'], $r['roots']);
        $this->assertSame([], $this->issueIds($r['issues'], 'unreachable'));
    }

    public function test_self_reference_is_reported_and_rendered_as_a_root(): void
    {
        $r = $this->orgChart($this->roster(['CEO' => [''], 'S' => ['S']]));
        $this->assertSame(['S'], $this->issueIds($r['issues'], 'self_reference'));
        $this->assertSame(['CEO', 'S'], $r['roots']);
    }

    // ── Inactive managers ─────────────────────────────────────────────

    public function test_active_report_under_inactive_manager_is_flagged(): void
    {
        $r = $this->orgChart($this->roster([
            'M' => ['', 'Inactive'], 'A' => ['M', 'Active'],
        ]));
        $this->assertSame(['A'], $this->issueIds($r['issues'], 'inactive_manager'));
        // Still rendered — flagged, not hidden.
        $this->assertSame(['A', 'M'], $this->sorted($r['reachable']));
    }

    public function test_inactive_report_under_inactive_manager_is_not_flagged(): void
    {
        // A normal historical state, not a defect.
        $r = $this->orgChart($this->roster([
            'M' => ['', 'Inactive'], 'A' => ['M', 'Inactive'],
        ]));
        $this->assertSame([], $this->issueIds($r['issues'], 'inactive_manager'));
    }

    // ── Cycles ────────────────────────────────────────────────────────

    public function test_pure_cycle_is_detected_and_excluded_from_roots(): void
    {
        $r = $this->orgChart($this->roster([
            'CEO' => [''], 'A' => ['B'], 'B' => ['C'], 'C' => ['A'],
        ]));
        $this->assertSame(['A', 'B', 'C'], $this->sorted($r['cyclic']));
        $this->assertSame(['CEO'], $r['roots'], 'a cyclic node must never be drawn as a fake top-level branch');
        $this->assertCount(1, array_filter($r['issues'], fn($i) => $i['type'] === 'cycle'));
    }

    public function test_two_independent_cycles_do_not_affect_the_clean_tree(): void
    {
        $r = $this->orgChart($this->roster([
            'CEO' => [''], 'X' => ['CEO'],
            'A' => ['B'], 'B' => ['A'],
            'P' => ['Q'], 'Q' => ['R'], 'R' => ['P'],
        ]));
        $this->assertSame(['A', 'B', 'P', 'Q', 'R'], $this->sorted($r['cyclic']));
        $this->assertCount(2, array_filter($r['issues'], fn($i) => $i['type'] === 'cycle'));
        $this->assertSame(['CEO', 'X'], $this->sorted($r['reachable']));
    }

    /**
     * THE REGRESSION THIS HARNESS EXISTS FOR.
     *
     * D reports to A, and A→B→C→A is a cycle. D is NOT on the loop — it
     * merely feeds into it. The first draft marked the entire walk cyclic,
     * so D was wrongly reported as part of the circular chain and named in
     * the cycle path shown to HR.
     */
    public function test_a_node_feeding_into_a_cycle_is_not_itself_marked_cyclic(): void
    {
        $r = $this->orgChart($this->roster([
            'A' => ['B'], 'B' => ['C'], 'C' => ['A'], 'D' => ['A'],
        ]));
        $this->assertSame(['A', 'B', 'C'], $this->sorted($r['cyclic']), 'D feeds into the loop but is not on it');

        $cycle = array_values(array_filter($r['issues'], fn($i) => $i['type'] === 'cycle'))[0];
        $this->assertSame(['A', 'B', 'C'], $this->sorted($cycle['loop']),
            'the reported cycle path must name only the true loop members');

        // D must still be accounted for — reported, never silently dropped.
        $this->assertSame(['D'], $this->issueIds($r['issues'], 'unreachable'));
        $this->assertNotContains('D', $r['roots']);
    }

    /** Same shape, opposite iteration order — the original bug was order-sensitive. */
    public function test_feeding_node_case_is_stable_when_iterated_first(): void
    {
        $r = $this->orgChart($this->roster([
            'D' => ['A'], 'A' => ['B'], 'B' => ['C'], 'C' => ['A'],
        ]));
        $this->assertSame(['A', 'B', 'C'], $this->sorted($r['cyclic']));
        $cycle = array_values(array_filter($r['issues'], fn($i) => $i['type'] === 'cycle'))[0];
        $this->assertSame(['A', 'B', 'C'], $this->sorted($cycle['loop']));
        $this->assertSame(['D'], $this->issueIds($r['issues'], 'unreachable'));
    }

    // ── Nothing may silently vanish ───────────────────────────────────

    public function test_every_staff_member_is_either_reachable_cyclic_or_reported(): void
    {
        // The core invariant: the chart plus the issues list must account
        // for 100% of the roster. Anything else is a silently-incomplete
        // chart that reads as healthy.
        $roster = $this->roster([
            'CEO' => [''], 'A' => ['CEO'], 'B' => ['GHOST'], 'S' => ['S'],
            'X' => ['Y'], 'Y' => ['Z'], 'Z' => ['X'], 'W' => ['X'],
            'M' => ['', 'Inactive'], 'N' => ['M', 'Active'],
        ]);
        $r = $this->orgChart($roster);

        $accounted = array_merge($r['reachable'], $r['cyclic'], array_column($r['issues'], 'staffId'));
        foreach (array_keys($roster) as $sid) {
            $this->assertContains($sid, $accounted,
                "{$sid} is neither rendered nor reported — it would silently vanish from the chart.");
        }
    }
}
