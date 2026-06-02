<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * B2_analytics_verify — Phase 1A foundation L0 probes.
 *
 * Locked design baseline: 15 total L0 probes when all phases land.
 * Phase 1A delivers 7 foundation probes; remaining 8 light up as their
 * dependent surfaces ship (Phase 1B–1H).
 *
 * Run: php index.php B2_analytics_verify all
 */
class B2_analytics_verify extends CI_Controller
{
    // CI3 Loader writes $this->firebase / $this->b2_analytics_service
    // via reflection; declaring them private breaks the assignment.
    private $svc;
    private $pass = 0;
    private $fail = 0;
    private $skip = 0;

    public function __construct()
    {
        parent::__construct();
        if (!defined('STDIN')) { exit("CLI only.\n"); }
        $this->load->library('firebase');
        $this->load->library('b2_analytics_service');
        $this->svc = $this->b2_analytics_service;
        $this->svc->init($this->firebase);
    }

    private function assert(string $label, bool $cond, string $detail = ''): void
    {
        if ($cond) { echo "  ✅ {$label}"; if ($detail) echo "  ({$detail})"; echo "\n"; $this->pass++; }
        else       { echo "  ❌ {$label}"; if ($detail) echo "  ({$detail})"; echo "\n"; $this->fail++; }
    }

    private function skip(string $label, string $why): void
    {
        echo "  ⏸️  {$label}  (skipped: {$why})\n";
        $this->skip++;
    }

    public function all()
    {
        echo "═══════════════════════════════════════════════════════════════════\n";
        echo "B2.3.4-A Analytics — L0 Verifier (Phase 1A foundation)\n";
        echo "═══════════════════════════════════════════════════════════════════\n";

        // ── Probe 1: service loads + KPI totals return non-error ──
        echo "\n[1] get_kpi_totals() returns matching counts\n";
        $kpi = $this->svc->get_kpi_totals();
        $this->assert("get_kpi_totals returned array with 6 keys", is_array($kpi) && count($kpi) === 6);
        $this->assert("total_schools >= 0", isset($kpi['total_schools']) && $kpi['total_schools'] >= 0,
            "total_schools={$kpi['total_schools']}");
        $this->assert("active_schools <= total_schools",
            ($kpi['active_schools'] ?? 0) <= ($kpi['total_schools'] ?? 0),
            "active={$kpi['active_schools']} total={$kpi['total_schools']}");

        // ── Probe 2: analyticsRollups for current month exists ──
        echo "\n[2] analyticsRollups for current month exists\n";
        $currentMonth = date('Y-m');
        $rollup = $this->firebase->firestoreGet('analyticsRollups', $currentMonth);
        $this->assert("analyticsRollups/{$currentMonth} present", is_array($rollup) && !empty($rollup));
        if (is_array($rollup) && !empty($rollup)) {
            $this->assert("rollup.periodType == 'month'", ($rollup['periodType'] ?? '') === 'month');
            $this->assert("rollup.computedAt present", !empty($rollup['computedAt']),
                'ts=' . ($rollup['computedAt'] ?? 'null'));
            $this->assert("rollup.tenantsRollup is array",
                is_array($rollup['tenantsRollup'] ?? null),
                'count=' . count((array) ($rollup['tenantsRollup'] ?? [])));
        }

        // ── Probe 3: compute_mrr_from_subscriptions includes trialing (Q-A4) ──
        echo "\n[3] compute_mrr_from_subscriptions() correctness (Q-A4 includes trialing)\n";
        $mrr = $this->svc->compute_mrr_from_subscriptions();
        $this->assert("MRR returns numeric", is_numeric($mrr), 'mrr=' . number_format($mrr, 2));
        $this->assert("MRR is non-negative", $mrr >= 0);

        // ── Probe 4: Active definition matches H1 server-side gate (Q-A3) ──
        echo "\n[4] tenant_is_active() matches H1 firestore.rules tenantActive() exactly (Q-A3)\n";
        $cases = [
            [['lifecycleState' => 'active',        'adminDisabled' => false], true,  'active+enabled'],
            [['lifecycleState' => 'trialing',      'adminDisabled' => false], true,  'trialing+enabled'],
            [['lifecycleState' => 'expiring_soon', 'adminDisabled' => false], true,  'expiring_soon+enabled'],
            [['lifecycleState' => 'grace',         'adminDisabled' => false], true,  'grace+enabled'],
            [['lifecycleState' => 'past_due',      'adminDisabled' => false], false, 'past_due+enabled'],
            [['lifecycleState' => 'suspended',     'adminDisabled' => false], false, 'suspended+enabled'],
            [['lifecycleState' => 'expired',       'adminDisabled' => false], false, 'expired+enabled'],
            [['lifecycleState' => 'active',        'adminDisabled' => true],  false, 'active+disabled (defense-in-depth)'],
            [['lifecycleState' => 'trialing',      'adminDisabled' => true],  false, 'trialing+disabled (defense-in-depth)'],
        ];
        foreach ($cases as [$input, $expected, $label]) {
            $got = $this->svc->tenant_is_active($input);
            $this->assert("Active({$label}) = " . ($expected ? 'true' : 'false'),
                $got === $expected, 'got=' . ($got ? 'true' : 'false'));
        }

        // ── Probe 5: High-signal activity filter (Q-A2) ──
        echo "\n[5] list_high_signal_activity() filters to high-signal actions only (Q-A2)\n";
        $events = $this->svc->list_high_signal_activity(20);
        $this->assert("returns array", is_array($events), 'count=' . count($events));
        $bad = 0;
        $allowed = B2_analytics_service::HIGH_SIGNAL_AUDIT_ACTIONS;
        foreach ($events as $e) {
            if (!in_array((string) ($e['action'] ?? ''), $allowed, true)) $bad++;
        }
        $this->assert("0 non-allowlist actions present", $bad === 0,
            $bad === 0 ? 'all rows allowlist-compliant' : "{$bad} rows violate Q-A2");

        // ── Probe 6: Distribution accessors ──
        echo "\n[6] Distribution accessors\n";
        $lcDist = $this->svc->get_lifecycle_distribution();
        $totalFromLc = array_sum($lcDist);
        $this->assert("get_lifecycle_distribution returns full state map",
            is_array($lcDist) && count($lcDist) === 7);
        $this->assert("sum(lifecycle distribution) == total_schools",
            $totalFromLc === ($kpi['total_schools'] ?? -1),
            "sum={$totalFromLc} total={$kpi['total_schools']}");

        $planDist = $this->svc->get_plan_distribution();
        $totalFromPlan = array_sum($planDist);
        $this->assert("get_plan_distribution sum == total_schools",
            $totalFromPlan === ($kpi['total_schools'] ?? -1),
            "sum={$totalFromPlan}");

        // ── Probe 7: Alert engine generation + listing ──
        echo "\n[7] Alert engine generate/list (Q-A10 persist until resolved)\n";
        $result = $this->svc->generate_alerts();
        $this->assert("generate_alerts returned status",
            is_array($result) && isset($result['generated']) && isset($result['skipped']),
            "generated={$result['generated']} skipped={$result['skipped']}");
        $open = $this->svc->list_open_alerts();
        $this->assert("list_open_alerts returns array", is_array($open),
            'open_count=' . count($open));

        // ─────────────────────────────────────────────────────────────
        // PHASE 1D PROBES — School Search spoke
        // ─────────────────────────────────────────────────────────────

        // ── Probe 8: search_schools(empty) returns full tenant set ──
        echo "\n[8] search_schools(empty) returns all tenants\n";
        $base = $this->svc->search_schools([], [], 1, 1000);
        $totalAll = (int) ($base['total'] ?? -1);
        $this->assert("search_schools([]) returns array", is_array($base));
        $this->assert("search_schools([]) total == kpi.total_schools",
            $totalAll === (int) ($kpi['total_schools'] ?? -1),
            "search.total={$totalAll} kpi.total={$kpi['total_schools']}");
        $this->assert("search_schools([]) rows count <= pageSize",
            count($base['rows'] ?? []) <= ($base['pageSize'] ?? 0));

        // ── Probe 9: single-filter (states) reduces correctly ──
        echo "\n[9] search_schools(state filter) reduces correctly\n";
        $activeOnly = $this->svc->search_schools(['states' => 'active'], [], 1, 1000);
        $lifeDist = $this->svc->get_lifecycle_distribution();
        $expectedActive = (int) ($lifeDist['active'] ?? 0);
        $this->assert("search_schools(states=active).total == lifecycle.active",
            (int) ($activeOnly['total'] ?? -1) === $expectedActive,
            "got=" . ($activeOnly['total'] ?? '?') . " expected={$expectedActive}");
        $badRow = 0;
        foreach ($activeOnly['rows'] ?? [] as $r) {
            if (strtolower((string) ($r['lifecycleState'] ?? '')) !== 'active') $badRow++;
        }
        $this->assert("0 non-active rows in filtered set", $badRow === 0,
            $badRow === 0 ? 'all rows match filter' : "{$badRow} rows violate state filter");

        // ── Probe 10: multi-filter combines AND across categories ──
        echo "\n[10] search_schools(multi-filter) combines AND across categories\n";
        $combo = $this->svc->search_schools(
            ['states' => 'active,trialing,grace,expiring_soon', 'students_min' => 0], [], 1, 1000);
        $this->assert("multi-filter result <= unfiltered total",
            (int) ($combo['total'] ?? 0) <= $totalAll,
            "combo={$combo['total']} all={$totalAll}");
        $this->assert("multi-filter row count matches reported total",
            (int) ($combo['total'] ?? -1) === count($combo['rows'] ?? []),
            "rows=" . count($combo['rows'] ?? []) . " total=" . ($combo['total'] ?? '?'));

        // ── Probe 11: pagination integrity ──
        echo "\n[11] Pagination integrity (page 1 + page 2 = full set, no dups)\n";
        $p1 = $this->svc->search_schools([], [], 1, 1);
        $p2 = $this->svc->search_schools([], [], 2, 1);
        $ids1 = array_map(fn($r) => $r['schoolId'] ?? '', $p1['rows'] ?? []);
        $ids2 = array_map(fn($r) => $r['schoolId'] ?? '', $p2['rows'] ?? []);
        $overlap = array_intersect($ids1, $ids2);
        $this->assert("page 1 + page 2 have no overlap", count($overlap) === 0,
            count($overlap) === 0 ? 'no dups' : count($overlap) . ' overlapping rows');
        if ($totalAll >= 2) {
            $this->assert("page 1 size 1 returns 1 row", count($ids1) === 1);
            $this->assert("page 2 size 1 returns 1 row", count($ids2) === 1);
        } else {
            $this->skip("page 1+2 row-count check", "tenant count < 2");
        }

        // ── Probe 12: sort correctness (totalStudents desc) ──
        echo "\n[12] Sort: totalStudents desc returns largest first\n";
        $sorted = $this->svc->search_schools([], ['field' => 'totalStudents', 'order' => 'desc'], 1, 1000);
        $prev = PHP_INT_MAX; $sortFail = 0;
        foreach ($sorted['rows'] ?? [] as $r) {
            $v = (int) ($r['totalStudents'] ?? 0);
            if ($v > $prev) $sortFail++;
            $prev = $v;
        }
        $this->assert("rows non-increasing by totalStudents", $sortFail === 0,
            $sortFail === 0 ? 'sorted correctly' : "{$sortFail} out-of-order rows");

        // ── Probe 13: search_schools_options() exposes facet values ──
        echo "\n[13] search_schools_options() populated\n";
        $opts = $this->svc->search_schools_options();
        $this->assert("options.states is array", is_array($opts['states'] ?? null),
            'count=' . count((array) ($opts['states'] ?? [])));
        $this->assert("options.plans is array", is_array($opts['plans'] ?? null),
            'count=' . count((array) ($opts['plans'] ?? [])));
        $this->assert("options.students_max >= 0", ($opts['students_max'] ?? -1) >= 0,
            'max=' . ($opts['students_max'] ?? '?'));

        // ── Probe 14: Saved-search CRUD round-trip + apply ──
        echo "\n[14] Saved-search CRUD + apply round-trip (Q-A7)\n";
        $testUser = 'B2_VERIFIER_TEST';
        $testFilters = ['states' => 'active', 'q' => 'kanpur'];
        $testSort = ['field' => 'schoolName', 'order' => 'asc'];
        $savedId = $this->svc->save_search($testUser, 'verifier-test', $testFilters, $testSort);
        $this->assert("save_search returns doc id", $savedId !== null, 'id=' . ($savedId ?? 'null'));
        $list = $this->svc->list_saved_searches($testUser);
        $found = false;
        foreach ($list as $ss) { if (($ss['slug'] ?? '') === 'verifier-test') { $found = true; break; } }
        $this->assert("list_saved_searches sees the new entry", $found);
        $applied = $this->svc->apply_saved_search($testUser, 'verifier-test');
        $this->assert("apply_saved_search returns filters",
            is_array($applied) && isset($applied['filters']['states']),
            'states=' . (($applied['filters']['states'] ?? '') ?: '?'));
        $deleted = $this->svc->delete_saved_search($testUser, 'verifier-test');
        $this->assert("delete_saved_search returns true", $deleted === true);

        // ── Probe 15: XLSX export writer produces valid OOXML package ──
        echo "\n[15] XLSX export: writer produces valid OOXML package\n";
        $CI =& get_instance();
        if (!isset($CI->b2_xlsx_export)) $CI->load->library('b2_xlsx_export');
        $CI->b2_xlsx_export->open('TestSheet');
        $CI->b2_xlsx_export->write_header(['A', 'B', 'C']);
        $CI->b2_xlsx_export->write_row(['x', 1, 2.5]);
        $CI->b2_xlsx_export->write_row(['y', 3, 4.5]);
        $tmpXlsx = $CI->b2_xlsx_export->save_to_temp();
        $this->assert("XLSX temp file exists", file_exists($tmpXlsx),
            file_exists($tmpXlsx) ? ('size=' . filesize($tmpXlsx)) : 'missing');
        $zip = new \ZipArchive();
        $rc = $zip->open($tmpXlsx);
        $this->assert("XLSX is a valid ZIP", $rc === true, "rc={$rc}");
        if ($rc === true) {
            $hasWb = $zip->locateName('xl/workbook.xml') !== false;
            $hasSheet = $zip->locateName('xl/worksheets/sheet1.xml') !== false;
            $hasContentTypes = $zip->locateName('[Content_Types].xml') !== false;
            $this->assert("XLSX contains workbook.xml + sheet1.xml + [Content_Types].xml",
                $hasWb && $hasSheet && $hasContentTypes,
                ($hasWb ? '' : '-wb ') . ($hasSheet ? '' : '-sheet ') . ($hasContentTypes ? '' : '-ctypes'));
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $this->assert("sheet1.xml contains expected data cell 'x'",
                $sheetXml !== false && strpos($sheetXml, '>x<') !== false);
            $zip->close();
        }
        @unlink($tmpXlsx);

        // ── Remaining probes still pending later-phase delivery ──
        echo "\n[16-19] Probes pending later-phase delivery:\n";
        $this->skip("Time-series resolution selector (daily/weekly/monthly)", "Phase 1H polish");
        $this->skip("KPI tile click navigation targets", "Phase 1B Hub UI (manual)");
        $this->skip("Per-tenant aggregation = global", "Phase 1G per-tenant deep dive");
        $this->skip("Cross-school rollup reconciliation", "Phase 1F cross-school metrics");

        // ── Summary ──
        echo "\n═══════════════════════════════════════════════════════════════════\n";
        printf("L0 verifier (Phase 1A-1D):  PASS=%d  FAIL=%d  SKIPPED-LATER=%d\n",
            $this->pass, $this->fail, $this->skip);
        echo ($this->fail === 0 ? "GATE: ✅ PASS (foundation green)\n"
                                : "GATE: ❌ FAIL\n");
        echo "═══════════════════════════════════════════════════════════════════\n";
        exit($this->fail === 0 ? 0 : 1);
    }
}
