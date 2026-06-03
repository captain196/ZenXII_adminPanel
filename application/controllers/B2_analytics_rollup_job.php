<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * B2_analytics_rollup_job — B2.3.4-A Phase 1A nightly rollup cron.
 *
 * Writes pre-computed aggregates to analyticsRollups/{periodKey}.
 * Schema documented in design package §6 (locked 2026-05-31).
 *
 * Operations:
 *   php index.php B2_analytics_rollup_job run_monthly [YYYY-MM]
 *     - Computes (or recomputes) one monthly rollup. Default = current month.
 *
 *   php index.php B2_analytics_rollup_job backfill [months_back]
 *     - Computes the last N monthly rollups (default 12). Idempotent.
 *
 *   php index.php B2_analytics_rollup_job run_daily [YYYY-MM-DD]
 *     - Computes one daily rollup. Default = today. (Phase 1B/1C may
 *       invoke; not yet wired to cron.)
 *
 *   php index.php B2_analytics_rollup_job status
 *     - Reports the freshness of the latest monthly rollup and any
 *       missing periods over the last 12 months.
 *
 * Scheduling target: nightly at 02:00 IST (lock per design §9.1).
 * Setup is operator-driven; this controller only provides the entry points.
 *
 * Backend writes via service account; bypasses Firestore Rules.
 */
class B2_analytics_rollup_job extends CI_Controller
{
    // CI3 Loader injects $this->firebase / $this->b2_analytics_service
    // as public properties; declaring them private breaks the loader's
    // reflection-based assignment. Use a separate handle for the svc
    // alias and reach firebase directly via $this->firebase.
    private $svc;

    public function __construct()
    {
        parent::__construct();
        if (!defined('STDIN')) { exit("CLI only.\n"); }
        $this->load->library('firebase');
        $this->load->library('b2_analytics_service');
        $this->svc = $this->b2_analytics_service;
        $this->svc->init($this->firebase);
    }

    public function run_monthly($periodKey = null)
    {
        $periodKey = $periodKey ?: date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $periodKey)) {
            echo "Invalid periodKey '{$periodKey}'. Expected YYYY-MM.\n"; exit(1);
        }
        echo "═══ Monthly rollup: {$periodKey} ═══\n";
        $t0 = microtime(true);
        $rollup = $this->_compute_rollup($periodKey, 'month');
        $rollup['computedDurationMs'] = (int) round((microtime(true) - $t0) * 1000);
        $ok = $this->_persist_rollup($periodKey, $rollup);
        echo $ok ? "  ✅ Written analyticsRollups/{$periodKey}\n"
                 : "  ❌ Failed to write rollup\n";
        echo "  Duration: {$rollup['computedDurationMs']}ms\n";
        echo "  totalSchools={$rollup['totalSchools']}  activeSchools={$rollup['activeSchools']}  mrr=" . number_format($rollup['mrr'], 2) . "\n";
        exit($ok ? 0 : 1);
    }

    public function backfill($monthsBack = 12)
    {
        $monthsBack = max(1, (int) $monthsBack);
        echo "═══ Backfill: last {$monthsBack} monthly rollups ═══\n";
        $pass = 0; $fail = 0;
        for ($i = $monthsBack - 1; $i >= 0; $i--) {
            $ts = strtotime("first day of -{$i} months");
            $periodKey = date('Y-m', $ts);
            $t0 = microtime(true);
            $rollup = $this->_compute_rollup($periodKey, 'month');
            $rollup['computedDurationMs'] = (int) round((microtime(true) - $t0) * 1000);
            $ok = $this->_persist_rollup($periodKey, $rollup);
            if ($ok) {
                printf("  ✅ %s  schools=%d  active=%d  mrr=%s  (%dms)\n",
                    $periodKey, $rollup['totalSchools'], $rollup['activeSchools'],
                    number_format($rollup['mrr'], 0), $rollup['computedDurationMs']);
                $pass++;
            } else {
                printf("  ❌ %s  failed\n", $periodKey);
                $fail++;
            }
        }
        echo "\n═══ Summary: PASS={$pass}  FAIL={$fail} ═══\n";
        exit($fail === 0 ? 0 : 1);
    }

    public function run_daily($date = null)
    {
        $date = $date ?: date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            echo "Invalid date '{$date}'. Expected YYYY-MM-DD.\n"; exit(1);
        }
        echo "═══ Daily rollup: {$date} ═══\n";
        $t0 = microtime(true);
        $rollup = $this->_compute_rollup($date, 'day');
        $rollup['computedDurationMs'] = (int) round((microtime(true) - $t0) * 1000);
        $ok = $this->_persist_rollup($date, $rollup);
        echo $ok ? "  ✅ Written analyticsRollups/{$date}\n" : "  ❌ Failed\n";
        exit($ok ? 0 : 1);
    }

    public function status()
    {
        echo "═══ analyticsRollups status (last 12 months) ═══\n";
        $missing = [];
        for ($i = 11; $i >= 0; $i--) {
            $periodKey = date('Y-m', strtotime("first day of -{$i} months"));
            $doc = $this->firebase->firestoreGet('analyticsRollups', $periodKey);
            if (is_array($doc) && !empty($doc)) {
                printf("  ✅ %s  computedAt=%s  schools=%d  mrr=%s\n",
                    $periodKey,
                    (string) ($doc['computedAt'] ?? '?'),
                    (int) ($doc['totalSchools'] ?? 0),
                    number_format((float) ($doc['mrr'] ?? 0), 0));
            } else {
                printf("  ❌ %s  MISSING\n", $periodKey);
                $missing[] = $periodKey;
            }
        }
        echo "\nMissing: " . count($missing) . " of 12\n";
        if (count($missing) > 0) {
            echo "Run: php index.php B2_analytics_rollup_job backfill 12\n";
        }
        exit(0);
    }

    // ──────────────────────────────────────────────────────────────
    // Rollup computation — the core of Phase 1A
    // ──────────────────────────────────────────────────────────────

    private function _compute_rollup(string $periodKey, string $periodType): array
    {
        // Period bounds
        if ($periodType === 'month') {
            $periodStart = strtotime($periodKey . '-01');
            $periodEnd = strtotime('last day of ' . $periodKey) + 86400 - 1;
        } else {
            $periodStart = strtotime($periodKey);
            $periodEnd = $periodStart + 86400 - 1;
        }

        // Load tenant universe
        $this->load->library('b2_registry_service');
        $this->b2_registry_service->init($this->firebase);
        $tenants = $this->b2_registry_service->list_tenants_summary();

        // Tenant + Active counts
        $totalSchools = 0;
        $activeSchools = 0;
        $newSchoolsCount = 0;
        $totalStudents = 0;
        $totalStaff = 0;
        $lifecycleDist = ['active' => 0, 'trialing' => 0, 'expiring_soon' => 0, 'grace' => 0,
                          'past_due' => 0, 'suspended' => 0, 'expired' => 0];
        $planDist = [];
        $schoolsByCity = [];
        $tenantsRollup = [];

        $plans = $this->_plan_map();

        foreach ($tenants as $t) {
            $sid = (string) ($t['schoolId'] ?? '');
            if (!preg_match('/^SCH_[A-Z0-9]+$/', $sid)) continue;

            // 2026-06-03 Phase 1H H1.P0.b HISTORICAL CORRECTNESS FIX:
            // Pre-fix unconditionally counted every current tenant toward
            // totalSchools regardless of whether the tenant existed at the
            // rollup period boundary. Result: historical rollups showed flat
            // totalSchools=3 for every month back to 2025-07, when the first
            // tenant was actually created 2026-04-02. Fix: read createdAt
            // ONCE per tenant per period; count toward totalSchools only when
            // the tenant existed by $periodEnd; newSchoolsCount uses the
            // same fetch (refactored from a second read below).
            $sch = $this->firebase->firestoreGet('schools', $sid);
            $createdAt = is_array($sch) ? (string) ($sch['createdAt'] ?? '') : '';
            $createdTs = $createdAt !== '' ? strtotime($createdAt) : 0;
            $existedByPeriodEnd = ($createdTs !== false && $createdTs > 0 && $createdTs <= $periodEnd);
            if (!$existedByPeriodEnd) {
                // Tenant didn't exist yet at this period's boundary.
                // Don't count it toward any aggregate for this period.
                continue;
            }
            $totalSchools++;

            $state = strtolower((string) ($t['lifecycleState'] ?? ''));
            if (isset($lifecycleDist[$state])) $lifecycleDist[$state]++;

            // Active = lifecycle in allowed AND !adminDisabled. For rollup we
            // approximate by lifecycle only (list_tenants_summary doesn't carry
            // adminDisabled; Hub KPI tile uses a fresh check). Acceptable for
            // historical rollups — Phase 1B will refine.
            if (in_array($state, B2_analytics_service::ALLOWED_LIFECYCLE_STATES, true)) {
                $activeSchools++;
            }

            $totalStudents += (int) ($t['totalStudents'] ?? 0);
            $totalStaff    += (int) ($t['totalStaff']    ?? 0);

            $pfid = (string) ($t['planFamilyId'] ?? '');
            $planName = ($pfid !== '' && isset($plans[$pfid])) ? $plans[$pfid]['name'] : '— No Plan';
            $planDist[$planName] = ($planDist[$planName] ?? 0) + 1;

            $city = (string) ($t['city'] ?? '');
            if ($city !== '') $schoolsByCity[$city] = ($schoolsByCity[$city] ?? 0) + 1;

            // newSchoolsCount: created WITHIN this period (vs existedByPeriodEnd
            // which is "existed at any point up to period end"). Reuses the
            // same fetch above.
            if ($createdTs !== false && $createdTs >= $periodStart && $createdTs <= $periodEnd) {
                $newSchoolsCount++;
            }

            $tenantsRollup[] = [
                'schoolId'        => $sid,
                'schoolName'      => (string) ($t['schoolName'] ?? $sid),
                'lifecycleState'  => $state,
                'planName'        => $planName,
                'students'        => (int) ($t['totalStudents'] ?? 0),
                'staff'           => (int) ($t['totalStaff']    ?? 0),
                'cityKey'         => $city,
            ];
        }

        // MRR snapshot for the period
        $mrr = $this->svc->compute_mrr_from_subscriptions();

        // Revenue in period (sum paid payments with paid_date in window)
        $totalRevenue = 0.0;
        $paidPaymentsCount = 0;
        $failedPaymentsCount = 0;
        $payments = $this->firebase->firestoreQuery('payments', []);
        if (is_array($payments)) {
            foreach ($payments as $p) {
                $d = is_array($p['data'] ?? null) ? $p['data'] : (is_array($p) ? $p : []);
                $status = strtolower((string) ($d['status'] ?? ''));
                $paidDate = (string) ($d['paid_date'] ?? $d['paidDate'] ?? '');
                $paidTs = $paidDate !== '' ? strtotime($paidDate) : 0;
                if ($status === 'paid' && $paidTs >= $periodStart && $paidTs <= $periodEnd) {
                    $totalRevenue += (float) ($d['amount'] ?? 0);
                    $paidPaymentsCount++;
                } elseif ($status === 'failed') {
                    $failedTs = $paidDate !== '' ? $paidTs : (int) strtotime((string) ($d['createdAt'] ?? ''));
                    if ($failedTs >= $periodStart && $failedTs <= $periodEnd) $failedPaymentsCount++;
                }
            }
        }

        // Activity summary from tenantAudit
        $activitySummary = [];
        try {
            $audit = $this->b2_registry_service->list_recent_activity(
                date('Y-m-d', $periodStart), date('Y-m-d', $periodEnd), 5000);
            foreach ($audit as $a) {
                if (!is_array($a)) continue;
                $action = (string) ($a['action'] ?? 'unknown');
                $activitySummary[$action] = ($activitySummary[$action] ?? 0) + 1;
            }
        } catch (\Throwable $e) { /* best-effort */ }

        return [
            'periodKey'             => $periodKey,
            'periodType'            => $periodType,
            'computedAt'            => date('c'),
            'computedDurationMs'    => 0,  // set by caller
            'computedBy'            => 'B2_analytics_rollup_job',
            'totalSchools'          => $totalSchools,
            'activeSchools'         => $activeSchools,
            'newSchoolsCount'       => $newSchoolsCount,
            'churnedCount'          => 0,   // Phase 1B: derive from tenantAudit b2_lifecycle_transition to denied
            'reactivatedCount'      => 0,   // Phase 1B
            'totalStudents'         => $totalStudents,
            'totalStaff'            => $totalStaff,
            'mrr'                   => $mrr,
            'totalRevenue'          => $totalRevenue,
            'paidPaymentsCount'     => $paidPaymentsCount,
            'failedPaymentsCount'   => $failedPaymentsCount,
            'refundedTotal'         => 0.0,  // Phase 2 (Revenue Center)
            'refundedCount'         => 0,
            'expansionRevenue'      => 0.0,  // Phase 2
            'contractionRevenue'    => 0.0,  // Phase 2
            'lifecycleStateDistribution' => $lifecycleDist,
            'planDistribution'      => $planDist,
            'schoolsByCity'         => $schoolsByCity,
            'activitySummary'       => $activitySummary,
            'totalLogins'           => 0,   // Phase 1F (cross-school engagement)
            'tenantsRollup'         => $tenantsRollup,
        ];
    }

    private function _persist_rollup(string $periodKey, array $rollup): bool
    {
        try {
            $this->firebase->firestoreSet('analyticsRollups', $periodKey, $rollup, false);
            return true;
        } catch (\Throwable $e) {
            log_message('error', 'B2_analytics_rollup_job persist failed: ' . $e->getMessage());
            return false;
        }
    }

    private $planMapCache = null;
    private function _plan_map(): array
    {
        if ($this->planMapCache !== null) return $this->planMapCache;
        $this->planMapCache = [];
        $rows = $this->firebase->firestoreQuery('plans', []);
        if (!is_array($rows)) return [];
        foreach ($rows as $row) {
            $d = is_array($row['data'] ?? null) ? $row['data'] : (is_array($row) ? $row : []);
            $id = (string) ($row['id'] ?? $d['__firestoreId'] ?? '');
            $fam = (string) ($d['planFamilyId'] ?? '');
            $entry = [
                'name'         => (string) ($d['name'] ?? $id),
                'price'        => (float) ($d['price'] ?? 0),
                'billingCycle' => strtolower((string) ($d['billingCycle'] ?? 'annual')),
            ];
            if ($id !== '')  $this->planMapCache[$id] = $entry;
            if ($fam !== '') $this->planMapCache[$fam] = $entry;
        }
        return $this->planMapCache;
    }
}
