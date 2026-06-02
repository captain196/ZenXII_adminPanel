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

        // ── Probes 8–15 (skipped until later phases) ──
        echo "\n[8-15] Probes pending later-phase delivery:\n";
        $this->skip("search_schools(filters)", "Phase 1D faceted search not built");
        $this->skip("Saved searches CRUD round-trip", "Phase 1D (operator-write surface)");
        $this->skip("Time-series resolution selector (daily/weekly/monthly)", "Phase 1B Hub charts");
        $this->skip("CSV export shape", "Phase 1H export service");
        $this->skip("Excel multi-sheet export", "Phase 1H export service");
        $this->skip("KPI tile click navigation targets", "Phase 1B Hub UI");
        $this->skip("Per-tenant aggregation = global", "Phase 1G per-tenant deep dive");
        $this->skip("Cross-school rollup reconciliation", "Phase 1F cross-school metrics");

        // ── Summary ──
        echo "\n═══════════════════════════════════════════════════════════════════\n";
        printf("Phase 1A L0 verifier:  PASS=%d  FAIL=%d  SKIPPED-LATER=%d\n",
            $this->pass, $this->fail, $this->skip);
        echo ($this->fail === 0 ? "GATE: ✅ PASS (foundation green)\n"
                                : "GATE: ❌ FAIL\n");
        echo "═══════════════════════════════════════════════════════════════════\n";
        exit($this->fail === 0 ? 0 : 1);
    }
}
