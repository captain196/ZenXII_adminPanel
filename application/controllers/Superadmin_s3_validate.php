<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Superadmin_s3_validate — Statistics S3 production-readiness validation.
 *
 * STRICTLY READ-ONLY. Measures, against the live shared Firestore project:
 *   [1/3] analyticsRollups doc existence + computedAt for the last 12 months.
 *   [6/D1] tenants with empty city + % that would vanish from schools_by_city.
 *   [7/D4] live distributions vs current-month rollup distributions divergence.
 *
 * No writes. Does not modify any existing request path.
 * CLI only:  php index.php superadmin_s3_validate run
 * VALIDATION ARTIFACT — remove after use.
 */
class Superadmin_s3_validate extends CI_Controller
{
    private $reg;
    private $svc;

    public function __construct()
    {
        parent::__construct();
        if (!defined('STDIN')) { exit("CLI only.\n"); }
        $this->load->library('firebase');
        $this->load->library('b2_registry_service');
        $this->load->library('b2_analytics_service');
        $this->reg = $this->b2_registry_service;
        $this->svc = $this->b2_analytics_service;
        $this->reg->init($this->firebase);
        $this->svc->init($this->firebase);
    }

    public function run()
    {
        echo "===================================================================\n";
        echo "Statistics S3 production-readiness validation (READ-ONLY)\n";
        echo "===================================================================\n";

        // [1/3] analyticsRollups continuity over last 12 months.
        echo "\n[1/3] analyticsRollups monthly doc existence + freshness:\n";
        $missing = 0; $present = 0; $curMonthPresent = false; $curDoc = null;
        for ($i = 0; $i < 12; $i++) {
            $pk = date('Y-m', strtotime("first day of -{$i} months"));
            $doc = $this->_safe(function () use ($pk) { return $this->firebase->firestoreGet('analyticsRollups', $pk); });
            if (is_array($doc)) {
                $present++;
                $ca = (string) ($doc['computedAt'] ?? '?');
                printf("   %s  PRESENT  computedAt=%s  totalSchools=%s\n", $pk, $ca, $doc['totalSchools'] ?? '?');
                if ($i === 0) { $curMonthPresent = true; $curDoc = $doc; }
            } else {
                $missing++;
                printf("   %s  MISSING%s\n", $pk, $i === 0 ? '   <== CURRENT MONTH' : '');
            }
        }
        printf("   -> present=%d  missing=%d  current-month=%s\n", $present, $missing, $curMonthPresent ? 'PRESENT' : 'MISSING');

        // [6/D1] empty-city tenants.
        echo "\n[6/D1] schools_by_city empty-city impact:\n";
        $tenants = $this->_safe(function () { return $this->reg->list_tenants_summary(); }) ?: [];
        $total = count($tenants); $empty = 0;
        foreach ($tenants as $t) {
            if (trim((string) ($t['city'] ?? '')) === '') $empty++;
        }
        $pct = $total > 0 ? round($empty / $total * 100, 1) : 0.0;
        printf("   total tenants=%d  empty-city=%d  -> %.1f%% would vanish from schools_by_city (D1)\n", $total, $empty, $pct);

        // [7/D4] live vs rollup distributions divergence (current month).
        echo "\n[7/D4] live vs current-month rollup distribution divergence:\n";
        if (!$curMonthPresent) {
            echo "   current-month rollup MISSING -> rollup distributions empty -> 100% divergence (demonstrates the missing-doc risk).\n";
        }
        $liveLife = $this->_safe(function () { return $this->svc->get_lifecycle_distribution(); }) ?: [];
        $livePlan = $this->_safe(function () { return $this->svc->get_plan_distribution(); }) ?: [];
        $liveCity = $this->_safe(function () { return $this->svc->get_schools_by_city(); }) ?: [];
        $rLife = is_array($curDoc['lifecycleStateDistribution'] ?? null) ? $curDoc['lifecycleStateDistribution'] : [];
        $rPlan = is_array($curDoc['planDistribution'] ?? null) ? $curDoc['planDistribution'] : [];
        $rCity = is_array($curDoc['schoolsByCity'] ?? null) ? $curDoc['schoolsByCity'] : [];
        $this->_diff('lifecycle_distribution', $liveLife, $rLife);
        $this->_diff('plan_distribution',      $livePlan, $rPlan);
        $this->_diff('schools_by_city',        $liveCity, $rCity);

        echo "\n===================================================================\n";
        echo "Interpretation: D1 = empty-city % above; D4 = divergence % per dist.\n";
        echo "If current-month MISSING, S3 needs a live-fallback (else empty dists).\n";
        echo "===================================================================\n";
    }

    private function _diff(string $label, array $live, array $rollup): void
    {
        $keys = array_unique(array_merge(array_keys($live), array_keys($rollup)));
        $sumLive = array_sum($live);
        $absDiff = 0; $keysDiffer = 0;
        foreach ($keys as $k) {
            $d = abs((int) ($live[$k] ?? 0) - (int) ($rollup[$k] ?? 0));
            if ($d > 0) $keysDiffer++;
            $absDiff += $d;
        }
        $pct = $sumLive > 0 ? round($absDiff / $sumLive * 100, 1) : ($absDiff > 0 ? 100.0 : 0.0);
        printf("   %-24s live_sum=%-4d rollup_sum=%-4d keys_differ=%-3d absDiff=%-4d divergence=%.1f%%\n",
            $label, $sumLive, array_sum($rollup), $keysDiffer, $absDiff, $pct);
    }

    private function _safe(callable $fn)
    {
        try { return $fn(); } catch (\Throwable $e) { echo "   (read error: " . $e->getMessage() . ")\n"; return null; }
    }
}
