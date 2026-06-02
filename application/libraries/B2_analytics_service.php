<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * B2_analytics_service — Phase 1A foundation for B2.3.4-A Dashboard Analytics.
 *
 * Locked design baseline: docs/design/B2_3_4_A_Analytics_Design_Locked.md
 * Operator authorization: 2026-05-31 (10 design decisions Q-A1..Q-A10).
 *
 * SCOPE OF THIS FILE IN PHASE 1A:
 *   • Service entry point + `init()` against the Firebase library.
 *   • "Active" tenant predicate matching H1 server-side gate exactly (Q-A3).
 *   • MRR computation including trialing tenants (Q-A4).
 *   • Top-level KPI accessors (Hub KPI tiles consume these).
 *   • Distribution accessors (lifecycle + plan).
 *   • Activity-feed accessor filtered to HIGH-SIGNAL events only (Q-A2).
 *   • Top schools / expiring soon / stale stats accessors.
 *   • Alert engine: generate, list, resolve, dismissal state (Q-A10).
 *   • Saved searches: persistence helpers (Q-A7).
 *   • Stubs for spoke endpoints that come online in Phases 1C-1G.
 *
 * EXPLICITLY OUT OF PHASE 1A SCOPE:
 *   • Faceted search query layer (Phase 1D).
 *   • Per-tenant deep-dive aggregation (Phase 1G).
 *   • Cross-school engagement metrics (Phase 1F).
 *   • CSV/Excel export (Phase 1H).
 *
 * DATA SOURCES (read-only on canonical Firestore collections):
 *   schools, schoolControl, subscriptions, plans, payments, tenantAudit,
 *   schoolCodeIndex, schoolNameIndex, schoolSsa.
 *
 * DATA SINKS (written by this service or its cron):
 *   analyticsRollups/{periodKey}        — rollup cron (B2_analytics_rollup_job)
 *   analyticsSavedSearches/{userId_slug} — saved-search CRUD here
 *   analyticsAlerts/{alertId}           — alert engine CRUD here
 *
 * Backend writes via Firebase Admin SDK / service account, bypassing
 * Firestore Rules. Phase 1B will add Rules entries for client read paths
 * (operator dashboard) when the UI ships.
 */
class B2_analytics_service
{
    private $firebase = null;
    private $ready = false;

    /**
     * H1-aligned allowed lifecycle states.
     * MUST match firestore.rules tenantActive() helper exactly (Q-A3).
     */
    const ALLOWED_LIFECYCLE_STATES = ['active', 'trialing', 'expiring_soon', 'grace'];

    /**
     * High-signal audit actions surfaced on the Hub Recent Activity feed (Q-A2).
     * Full audit trail remains accessible in Per-Tenant Deep Dive (Phase 1G).
     */
    const HIGH_SIGNAL_AUDIT_ACTIONS = [
        'b2_onboard',
        'b2_lifecycle_transition',
        'b2_admin_toggle',
        'b2_plan_change',
        'b2_payment_received',
        'b2_payment_failed',
        'b2_refund',
    ];

    /**
     * Plan billing-cycle to monthly-equivalent multiplier for MRR.
     */
    const BILLING_CYCLE_MONTHS = [
        'monthly'   => 1,
        'quarterly' => 3,
        'annual'    => 12,
    ];

    /**
     * Alert types this service knows about (Q-A10 — persist until resolved).
     * Each carries a (resolver, severity) tuple documented in the alert engine.
     */
    const ALERT_TYPES = [
        'tenant_expiring_soon'        => ['severity' => 'warn',     'auto_resolve_window_hours' => null],
        'tenant_past_due'             => ['severity' => 'critical', 'auto_resolve_window_hours' => null],
        'tenant_stale_stats'          => ['severity' => 'info',     'auto_resolve_window_hours' => null],
        'recent_lifecycle_transition' => ['severity' => 'info',     'auto_resolve_window_hours' => 24],
        'payment_failed'              => ['severity' => 'critical', 'auto_resolve_window_hours' => null],
        'rollup_stale'                => ['severity' => 'warn',     'auto_resolve_window_hours' => null],
    ];

    public function __construct()
    {
        // CI3 libraries are auto-loaded with no args; consumers call init().
    }

    /**
     * Bind to a Firebase wrapper. Idempotent.
     */
    public function init($firebase): void
    {
        if ($firebase === null) {
            log_message('error', 'B2_analytics_service::init received null firebase');
            return;
        }
        $this->firebase = $firebase;
        $this->ready = true;
    }

    // ──────────────────────────────────────────────────────────────
    // PREDICATES
    // ──────────────────────────────────────────────────────────────

    /**
     * Tenant access-allowed predicate.
     * MUST match firestore.rules tenantActive() helper exactly (Q-A3 lock).
     *
     * @param array $tenantSummary  Single tenant row from list_tenants_summary().
     */
    public function tenant_is_active(array $tenantSummary): bool
    {
        $state = strtolower((string) ($tenantSummary['lifecycleState'] ?? ''));
        if (!in_array($state, self::ALLOWED_LIFECYCLE_STATES, true)) return false;

        // adminDisabled lives on the schools doc; surface via list_tenants_summary
        // or a fresh schoolControl/{id} read in caller. Treat MISSING or false as
        // "not disabled" (matches H1 rule semantics).
        $disabled = $tenantSummary['adminDisabled'] ?? false;
        return !$disabled;
    }

    // ──────────────────────────────────────────────────────────────
    // KPI ACCESSORS (live — Hub tiles consume these)
    // ──────────────────────────────────────────────────────────────

    /**
     * Top-level dashboard KPI snapshot.
     * @return array{total_schools:int, active_schools:int, total_students:int, total_staff:int, mrr:float, active_subscriptions:int}
     */
    public function get_kpi_totals(): array
    {
        if (!$this->ready) return $this->_zero_kpi();

        $this->load_registry();
        $tenants = $this->registry()->list_tenants_summary();

        $total_schools = count($tenants);
        $active_schools = 0;
        $total_students = 0;
        $total_staff = 0;

        foreach ($tenants as $t) {
            // adminDisabled is not directly on list_tenants_summary; pair below.
            // For Phase 1A, derive lifecycle-only active flag here; backfill the
            // adminDisabled check from schools/{id} when probe walks each tenant.
            if (in_array(strtolower((string) ($t['lifecycleState'] ?? '')), self::ALLOWED_LIFECYCLE_STATES, true)) {
                $active_schools++;
            }
            $total_students += (int) ($t['totalStudents'] ?? 0);
            $total_staff    += (int) ($t['totalStaff']    ?? 0);
        }

        return [
            'total_schools'        => $total_schools,
            'active_schools'       => $active_schools,
            'total_students'       => $total_students,
            'total_staff'          => $total_staff,
            'mrr'                  => $this->compute_mrr_from_subscriptions(),
            'active_subscriptions' => $this->count_active_subscriptions(),
        ];
    }

    private function _zero_kpi(): array
    {
        return [
            'total_schools' => 0, 'active_schools' => 0, 'total_students' => 0,
            'total_staff' => 0, 'mrr' => 0.0, 'active_subscriptions' => 0,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // REVENUE COMPUTATION
    // Phase 1A reads from `subscriptions` (canonical). At B2.3.5
    // (Revenue Center), this method's source switches to `invoices`
    // without changing the public signature — see design package
    // §10.1 dependency review.
    // ──────────────────────────────────────────────────────────────

    /**
     * MRR — includes trialing tenants per Q-A4 lock.
     */
    public function compute_mrr_from_subscriptions(): float
    {
        if (!$this->ready) return 0.0;

        $subs = $this->firebase->firestoreQuery('subscriptions', []);
        if (!is_array($subs)) return 0.0;

        // Read plan price map once.
        $plans = $this->load_plan_price_map();
        $mrr = 0.0;

        foreach ($subs as $row) {
            $data = is_array($row['data'] ?? null) ? $row['data'] : (is_array($row) ? $row : []);
            $status = strtolower((string) ($data['status'] ?? ''));
            if (!in_array($status, ['active', 'trialing', 'grace'], true)) continue;

            $planId = (string) ($data['planId'] ?? '');
            if ($planId === '' || !isset($plans[$planId])) continue;

            $price = (float) $plans[$planId]['price'];
            $cycle = strtolower((string) $plans[$planId]['billingCycle']);
            $months = self::BILLING_CYCLE_MONTHS[$cycle] ?? 12;
            if ($months <= 0) continue;

            $mrr += $price / $months;
        }
        return $mrr;
    }

    private function count_active_subscriptions(): int
    {
        if (!$this->ready) return 0;
        $subs = $this->firebase->firestoreQuery('subscriptions', []);
        if (!is_array($subs)) return 0;
        $n = 0;
        foreach ($subs as $row) {
            $data = is_array($row['data'] ?? null) ? $row['data'] : (is_array($row) ? $row : []);
            $status = strtolower((string) ($data['status'] ?? ''));
            if (in_array($status, ['active', 'trialing', 'grace'], true)) $n++;
        }
        return $n;
    }

    /**
     * Build a {planFamilyId+version → {price, billingCycle, name}} map.
     * Reads `plans` collection canonical docs.
     * Cached per-request.
     */
    private $planMapCache = null;
    private function load_plan_price_map(): array
    {
        if ($this->planMapCache !== null) return $this->planMapCache;
        $this->planMapCache = [];
        if (!$this->ready) return [];
        $rows = $this->firebase->firestoreQuery('plans', []);
        if (!is_array($rows)) return [];
        foreach ($rows as $row) {
            $data = is_array($row['data'] ?? null) ? $row['data'] : (is_array($row) ? $row : []);
            $id = (string) ($row['id'] ?? $data['__firestoreId'] ?? '');
            // schoolControl.subscription.planId stores planFamilyId; plans
            // doc id is "{planFamilyId}__v1". Index by BOTH for lookup safety.
            $family = (string) ($data['planFamilyId'] ?? '');
            $entry = [
                'price'        => (float) ($data['price'] ?? 0),
                'billingCycle' => strtolower((string) ($data['billingCycle'] ?? 'annual')),
                'name'         => (string) ($data['name'] ?? $id),
            ];
            if ($id !== '')     $this->planMapCache[$id] = $entry;
            if ($family !== '') $this->planMapCache[$family] = $entry;
        }
        return $this->planMapCache;
    }

    // ──────────────────────────────────────────────────────────────
    // DISTRIBUTIONS
    // ──────────────────────────────────────────────────────────────

    /** Lifecycle state distribution across all tenants. */
    public function get_lifecycle_distribution(): array
    {
        $tenants = $this->ready ? $this->registry()->list_tenants_summary() : [];
        $out = ['active' => 0, 'trialing' => 0, 'expiring_soon' => 0, 'grace' => 0,
                'past_due' => 0, 'suspended' => 0, 'expired' => 0];
        foreach ($tenants as $t) {
            $s = strtolower((string) ($t['lifecycleState'] ?? ''));
            if (isset($out[$s])) $out[$s]++;
        }
        return $out;
    }

    /** Plan distribution by plan name (resolved via plans collection). */
    public function get_plan_distribution(): array
    {
        $tenants = $this->ready ? $this->registry()->list_tenants_summary() : [];
        $plans = $this->load_plan_price_map();
        $out = [];
        foreach ($tenants as $t) {
            $pfid = (string) ($t['planFamilyId'] ?? '');
            $name = $pfid !== '' && isset($plans[$pfid]) ? $plans[$pfid]['name'] : '— No Plan';
            $out[$name] = ($out[$name] ?? 0) + 1;
        }
        return $out;
    }

    // ──────────────────────────────────────────────────────────────
    // ACTIVITY FEED (Q-A2 — high-signal only on Hub)
    // ──────────────────────────────────────────────────────────────

    /**
     * Recent high-signal audit events, newest first.
     * @param int $limit  hard cap on returned rows.
     */
    public function list_high_signal_activity(int $limit = 10): array
    {
        if (!$this->ready) return [];
        $this->load_registry();
        $audit = $this->registry()->list_recent_activity(
            date('Y-m-d', strtotime('-30 days')),
            date('Y-m-d'),
            500
        );
        $rows = [];
        foreach ($audit as $a) {
            if (!is_array($a)) continue;
            $action = (string) ($a['action'] ?? '');
            if (!in_array($action, self::HIGH_SIGNAL_AUDIT_ACTIONS, true)) continue;
            $rows[] = $a;
        }
        usort($rows, fn($a, $b) => strcmp((string) ($b['ts'] ?? ''), (string) ($a['ts'] ?? '')));
        return array_slice($rows, 0, $limit);
    }

    // ──────────────────────────────────────────────────────────────
    // TOP / EXPIRING / STALE — Hub widgets
    // ──────────────────────────────────────────────────────────────

    public function get_top_schools_by_students(int $limit = 5): array
    {
        $tenants = $this->ready ? $this->registry()->list_tenants_summary() : [];
        usort($tenants, fn($a, $b) => (int) ($b['totalStudents'] ?? 0) - (int) ($a['totalStudents'] ?? 0));
        return array_slice($tenants, 0, $limit);
    }

    public function get_expiring_soon(int $daysWindow = 30): array
    {
        $tenants = $this->ready ? $this->registry()->list_tenants_summary() : [];
        $now = time();
        $cut = $now + ($daysWindow * 86400);
        $rows = [];
        foreach ($tenants as $t) {
            $end = (string) ($t['subscriptionPeriodEnd'] ?? '');
            $ts = $end !== '' ? strtotime($end) : false;
            if ($ts === false) continue;
            if ($ts >= $now && $ts <= $cut) $rows[] = $t + ['_expirySeconds' => $ts - $now];
        }
        usort($rows, fn($a, $b) => $a['_expirySeconds'] - $b['_expirySeconds']);
        return $rows;
    }

    public function get_stale_stats_tenants(int $daysThreshold = 7): array
    {
        if (!$this->ready) return [];
        $schools = $this->firebase->firestoreQuery('schools', []);
        $cut = time() - ($daysThreshold * 86400);
        $rows = [];
        if (!is_array($schools)) return [];
        foreach ($schools as $row) {
            $data = is_array($row['data'] ?? null) ? $row['data'] : (is_array($row) ? $row : []);
            $sid = (string) ($row['id'] ?? $data['__firestoreId'] ?? '');
            if (!preg_match('/^SCH_[A-Z0-9]+$/', $sid)) continue;
            $cache = is_array($data['statsCache'] ?? null) ? $data['statsCache'] : [];
            $upd = (string) ($cache['lastUpdated'] ?? '');
            $ts = $upd !== '' ? strtotime($upd) : 0;
            if ($ts === 0 || $ts < $cut) {
                $rows[] = ['schoolId' => $sid, 'name' => (string) ($data['name'] ?? $sid),
                           'lastUpdated' => $upd, 'staleHours' => ($ts > 0 ? floor((time() - $ts) / 3600) : null)];
            }
        }
        return $rows;
    }

    // ──────────────────────────────────────────────────────────────
    // ALERT ENGINE (Q-A10 — persist until resolved)
    // ──────────────────────────────────────────────────────────────

    /**
     * Generate fresh alerts from current state. Idempotent: if an alert
     * for the same (type, affected_key) tuple already exists in 'open'
     * state, the existing alert is preserved (not duplicated).
     *
     * Caller pattern: cron invokes this nightly; on-demand recompute from
     * the Hub triggers it for the operator.
     */
    public function generate_alerts(): array
    {
        if (!$this->ready) return ['generated' => 0, 'skipped' => 0];

        $generated = 0;
        $skipped = 0;
        $nowIso = date('c');

        // 1. tenant_expiring_soon (30-day window)
        foreach ($this->get_expiring_soon(30) as $row) {
            $key = 'expiry_' . $row['schoolId'];
            if ($this->_alert_open_exists('tenant_expiring_soon', $key)) { $skipped++; continue; }
            $this->_write_alert([
                'alertType' => 'tenant_expiring_soon',
                'affectedKey' => $key,
                'affectedTenants' => [$row['schoolId']],
                'severity' => 'warn',
                'triggerData' => ['schoolId' => $row['schoolId'], 'periodEnd' => $row['subscriptionPeriodEnd'] ?? ''],
                'state' => 'open', 'createdAt' => $nowIso,
            ]);
            $generated++;
        }

        // 2. tenant_past_due
        foreach (($this->ready ? $this->registry()->list_tenants_summary() : []) as $t) {
            if (strtolower((string) ($t['lifecycleState'] ?? '')) !== 'past_due') continue;
            $key = 'past_due_' . $t['schoolId'];
            if ($this->_alert_open_exists('tenant_past_due', $key)) { $skipped++; continue; }
            $this->_write_alert([
                'alertType' => 'tenant_past_due', 'affectedKey' => $key,
                'affectedTenants' => [$t['schoolId']], 'severity' => 'critical',
                'triggerData' => ['schoolId' => $t['schoolId']],
                'state' => 'open', 'createdAt' => $nowIso,
            ]);
            $generated++;
        }

        // 3. tenant_stale_stats
        foreach ($this->get_stale_stats_tenants(7) as $row) {
            $key = 'stale_stats_' . $row['schoolId'];
            if ($this->_alert_open_exists('tenant_stale_stats', $key)) { $skipped++; continue; }
            $this->_write_alert([
                'alertType' => 'tenant_stale_stats', 'affectedKey' => $key,
                'affectedTenants' => [$row['schoolId']], 'severity' => 'info',
                'triggerData' => ['schoolId' => $row['schoolId'], 'staleHours' => $row['staleHours']],
                'state' => 'open', 'createdAt' => $nowIso,
            ]);
            $generated++;
        }

        return ['generated' => $generated, 'skipped' => $skipped];
    }

    /** List currently open alerts, optionally filtered by type. */
    public function list_open_alerts(?string $alertType = null): array
    {
        if (!$this->ready) return [];
        $conds = [['state', '==', 'open']];
        if ($alertType !== null) $conds[] = ['alertType', '==', $alertType];
        $rows = $this->firebase->firestoreQuery('analyticsAlerts', $conds);
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
            $d['_id'] = (string) ($r['id'] ?? $d['__firestoreId'] ?? '');
            $out[] = $d;
        }
        return $out;
    }

    public function resolve_alert(string $alertId, string $resolvedBy, string $reason = 'manual'): bool
    {
        if (!$this->ready || $alertId === '') return false;
        try {
            $this->firebase->firestoreUpdate('analyticsAlerts', $alertId, [
                'state' => 'resolved',
                'resolvedAt' => date('c'),
                'resolvedBy' => $resolvedBy,
                'resolvedReason' => $reason,
            ]);
            return true;
        } catch (\Throwable $e) {
            log_message('error', 'B2_analytics_service::resolve_alert failed: ' . $e->getMessage());
            return false;
        }
    }

    private function _alert_open_exists(string $type, string $key): bool
    {
        if (!$this->ready) return false;
        try {
            $rows = $this->firebase->firestoreQuery('analyticsAlerts', [
                ['alertType', '==', $type], ['affectedKey', '==', $key], ['state', '==', 'open'],
            ]);
            return is_array($rows) && count($rows) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function _write_alert(array $alert): void
    {
        $id = 'AL_' . strtoupper(substr(md5($alert['affectedKey'] . microtime(true)), 0, 12));
        try {
            $this->firebase->firestoreSet('analyticsAlerts', $id, array_merge($alert, ['alertId' => $id]), false);
        } catch (\Throwable $e) {
            log_message('error', 'B2_analytics_service::_write_alert failed: ' . $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────────────────────
    // SAVED SEARCHES (Q-A7 — Firestore-backed, cross-device)
    // ──────────────────────────────────────────────────────────────

    public function save_search(string $userId, string $name, array $filters, array $sort = []): ?string
    {
        if (!$this->ready || $userId === '' || $name === '') return null;
        $slug = preg_replace('/[^a-zA-Z0-9_-]+/', '_', strtolower($name));
        $docId = $userId . '_' . $slug;
        $now = date('c');
        $existing = $this->firebase->firestoreGet('analyticsSavedSearches', $docId);
        $payload = [
            'id' => $docId, 'userId' => $userId, 'name' => $name,
            'slug' => $slug, 'filters' => $filters, 'sort' => $sort,
            'updatedAt' => $now,
        ];
        if (!is_array($existing) || empty($existing)) {
            $payload['createdAt'] = $now; $payload['useCount'] = 0; $payload['isPinned'] = false;
        }
        try {
            $this->firebase->firestoreSet('analyticsSavedSearches', $docId, $payload, true);
            return $docId;
        } catch (\Throwable $e) {
            log_message('error', 'B2_analytics_service::save_search failed: ' . $e->getMessage());
            return null;
        }
    }

    public function list_saved_searches(string $userId): array
    {
        if (!$this->ready || $userId === '') return [];
        $rows = $this->firebase->firestoreQuery('analyticsSavedSearches', [['userId', '==', $userId]]);
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
            $out[] = $d;
        }
        // Pinned first, then by updatedAt desc.
        usort($out, function ($a, $b) {
            $pa = !empty($a['isPinned']); $pb = !empty($b['isPinned']);
            if ($pa !== $pb) return $pa ? -1 : 1;
            return strcmp((string) ($b['updatedAt'] ?? ''), (string) ($a['updatedAt'] ?? ''));
        });
        return $out;
    }

    public function delete_saved_search(string $userId, string $slug): bool
    {
        if (!$this->ready || $userId === '' || $slug === '') return false;
        $docId = $userId . '_' . $slug;
        try {
            $this->firebase->firestoreDelete('analyticsSavedSearches', $docId);
            return true;
        } catch (\Throwable $e) {
            log_message('error', 'B2_analytics_service::delete_saved_search failed: ' . $e->getMessage());
            return false;
        }
    }

    // ──────────────────────────────────────────────────────────────
    // TIME-SERIES (Phase 1B Hub charts)
    // Reads from analyticsRollups cache; falls back to zero series if
    // the rollup doc is missing for a period (so charts always render
    // an axis even when data hasn't backfilled yet).
    // ──────────────────────────────────────────────────────────────

    /**
     * Schools growth time-series for the last N months.
     * @return array  [['period' => 'YYYY-MM', 'totalSchools' => int, 'newSchoolsCount' => int], ...]
     */
    public function get_time_series_schools_growth(int $months = 12): array
    {
        return $this->_load_monthly_rollups_series($months, ['totalSchools', 'newSchoolsCount']);
    }

    /**
     * Revenue trend time-series for the last N months.
     * @return array  [['period' => 'YYYY-MM', 'mrr' => float, 'totalRevenue' => float], ...]
     */
    public function get_time_series_revenue(int $months = 12): array
    {
        return $this->_load_monthly_rollups_series($months, ['mrr', 'totalRevenue']);
    }

    private function _load_monthly_rollups_series(int $months, array $fields): array
    {
        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $periodKey = date('Y-m', strtotime("first day of -{$i} months"));
            $row = ['period' => $periodKey];
            $doc = $this->ready ? $this->firebase->firestoreGet('analyticsRollups', $periodKey) : null;
            foreach ($fields as $f) {
                $row[$f] = is_array($doc) ? ($doc[$f] ?? 0) : 0;
            }
            $out[] = $row;
        }
        return $out;
    }

    // ──────────────────────────────────────────────────────────────
    // HUB PAYLOAD (Phase 1B — single AJAX endpoint for Hub refresh)
    // Combines all Hub widget data into one round-trip for the
    // "Refresh" button on the dashboard. Each widget can also be
    // fetched individually via its own service method.
    // ──────────────────────────────────────────────────────────────

    public function get_hub_payload(?string $userId = null): array
    {
        return [
            'kpi'             => $this->get_kpi_totals(),
            'time_series'     => [
                'schools_growth' => $this->get_time_series_schools_growth(12),
                'revenue'        => $this->get_time_series_revenue(12),
            ],
            'recent_activity' => $this->list_high_signal_activity(8),
            'top_schools'     => $this->get_top_schools_by_students(5),
            'expiring_soon'   => $this->get_expiring_soon(30),
            'alerts'          => $this->list_open_alerts(),
            'saved_searches'  => $userId ? $this->list_saved_searches($userId) : [],
            'generated_at'    => date('c'),
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // QUICK SEARCH (Phase 1B Hub — type-ahead suggestions only;
    // full faceted search lives on the spoke in Phase 1D).
    // ──────────────────────────────────────────────────────────────

    /**
     * Suggest schools matching a free-text fragment in name / code / city.
     * Case-insensitive, returns up to $limit rows.
     */
    public function quick_search(string $q, int $limit = 8): array
    {
        $q = trim($q);
        if ($q === '' || !$this->ready) return [];
        $needle = strtolower($q);
        $tenants = $this->registry()->list_tenants_summary();
        $hits = [];
        foreach ($tenants as $t) {
            $name = strtolower((string) ($t['schoolName'] ?? ''));
            $code = strtolower((string) ($t['schoolCode'] ?? ''));
            $city = strtolower((string) ($t['city'] ?? ''));
            $sid  = strtolower((string) ($t['schoolId']   ?? ''));
            if (strpos($name, $needle) !== false
                || strpos($code, $needle) !== false
                || strpos($city, $needle) !== false
                || strpos($sid, $needle) !== false) {
                $hits[] = $t;
            }
            if (count($hits) >= $limit) break;
        }
        return $hits;
    }

    // ──────────────────────────────────────────────────────────────
    // STATISTICS SPOKE (Phase 1C — /superadmin/dashboard/statistics)
    //
    // Single composite endpoint returning everything the Statistics
    // spoke needs in one round-trip; individual accessors exposed
    // separately for drill-down consumers.
    //
    // Time-series sources: analyticsRollups cache (Phase 1A backfilled
    // 12 months). Range options 3/6/12/24 months. Daily/Weekly
    // resolution selector is deferred to Phase 1H — Phase 1C defaults
    // to Monthly resolution since that's the rollup cadence we have.
    // ──────────────────────────────────────────────────────────────

    /**
     * Statistics spoke composite payload.
     * @param int $monthsBack  3 | 6 | 12 | 24 — caller-validated upstream.
     */
    public function get_statistics_payload(int $monthsBack = 12): array
    {
        $monthsBack = max(1, min(36, $monthsBack));
        return [
            'monthsBack'              => $monthsBack,
            'schools_onboarded_series' => $this->_load_monthly_rollups_series($monthsBack, ['newSchoolsCount', 'totalSchools']),
            'students_growth_series'   => $this->_load_monthly_rollups_series($monthsBack, ['totalStudents']),
            'staff_growth_series'      => $this->_load_monthly_rollups_series($monthsBack, ['totalStaff']),
            'lifecycle_distribution'   => $this->get_lifecycle_distribution(),
            'plan_distribution'        => $this->get_plan_distribution(),
            'schools_by_city'          => $this->get_schools_by_city(),
            'avg_tenant_age_days'      => $this->get_average_tenant_age_days(),
            'net_new_30d'              => $this->count_new_schools_in_window(30),
            'net_new_90d'              => $this->count_new_schools_in_window(90),
            'recent_registrations'     => $this->get_recent_registrations(30, 10),
            'generated_at'             => date('c'),
        ];
    }

    /**
     * Schools-by-city aggregation, sorted by count desc.
     * @return array<string,int>
     */
    public function get_schools_by_city(): array
    {
        if (!$this->ready) return [];
        $out = [];
        $tenants = $this->registry()->list_tenants_summary();
        foreach ($tenants as $t) {
            $city = trim((string) ($t['city'] ?? ''));
            if ($city === '') $city = '— Unspecified';
            $out[$city] = ($out[$city] ?? 0) + 1;
        }
        arsort($out);
        return $out;
    }

    /**
     * Average tenant age in days (now − createdAt mean across all tenants).
     */
    public function get_average_tenant_age_days(): int
    {
        if (!$this->ready) return 0;
        $schools = $this->firebase->firestoreQuery('schools', []);
        if (!is_array($schools)) return 0;
        $ages = [];
        $now = time();
        foreach ($schools as $row) {
            $data = is_array($row['data'] ?? null) ? $row['data'] : (is_array($row) ? $row : []);
            $sid = (string) ($row['id'] ?? $data['__firestoreId'] ?? '');
            if (!preg_match('/^SCH_[A-Z0-9]+$/', $sid)) continue;
            $created = (string) ($data['createdAt'] ?? '');
            $ts = $created !== '' ? strtotime($created) : 0;
            if ($ts <= 0) continue;
            $ages[] = (int) floor(($now - $ts) / 86400);
        }
        if (empty($ages)) return 0;
        return (int) round(array_sum($ages) / count($ages));
    }

    /**
     * Count of tenants created within the last $daysWindow days.
     */
    public function count_new_schools_in_window(int $daysWindow): int
    {
        if (!$this->ready) return 0;
        $schools = $this->firebase->firestoreQuery('schools', []);
        if (!is_array($schools)) return 0;
        $cut = time() - ($daysWindow * 86400);
        $n = 0;
        foreach ($schools as $row) {
            $data = is_array($row['data'] ?? null) ? $row['data'] : (is_array($row) ? $row : []);
            $sid = (string) ($row['id'] ?? $data['__firestoreId'] ?? '');
            if (!preg_match('/^SCH_[A-Z0-9]+$/', $sid)) continue;
            $created = (string) ($data['createdAt'] ?? '');
            $ts = $created !== '' ? strtotime($created) : 0;
            if ($ts > 0 && $ts >= $cut) $n++;
        }
        return $n;
    }

    /**
     * Recent registrations list (within $daysWindow), newest first, up to $limit.
     */
    public function get_recent_registrations(int $daysWindow = 30, int $limit = 10): array
    {
        if (!$this->ready) return [];
        $schools = $this->firebase->firestoreQuery('schools', []);
        if (!is_array($schools)) return [];
        $cut = time() - ($daysWindow * 86400);
        $rows = [];
        foreach ($schools as $row) {
            $data = is_array($row['data'] ?? null) ? $row['data'] : (is_array($row) ? $row : []);
            $sid = (string) ($row['id'] ?? $data['__firestoreId'] ?? '');
            if (!preg_match('/^SCH_[A-Z0-9]+$/', $sid)) continue;
            $created = (string) ($data['createdAt'] ?? '');
            $ts = $created !== '' ? strtotime($created) : 0;
            if ($ts > 0 && $ts >= $cut) {
                $rows[] = [
                    'schoolId'   => $sid,
                    'schoolName' => (string) ($data['name'] ?? $sid),
                    'city'       => (string) ($data['city'] ?? ''),
                    'createdAt'  => $created,
                    '_ts'        => $ts,
                ];
            }
        }
        usort($rows, fn($a, $b) => $b['_ts'] - $a['_ts']);
        return array_slice($rows, 0, $limit);
    }

    // ──────────────────────────────────────────────────────────────
    // DRILL-DOWN HELPERS (for chart click-through; URL builders)
    //
    // Phase 1D faceted search consumes the same URL parameters, so
    // the drill-down links produced here will become live once the
    // /superadmin/dashboard/schools-search spoke ships.
    // ──────────────────────────────────────────────────────────────

    /**
     * Tenants matching a single lifecycle state — drill-down content.
     */
    public function drilldown_schools_by_lifecycle(string $state): array
    {
        if (!$this->ready) return [];
        $state = strtolower($state);
        $tenants = $this->registry()->list_tenants_summary();
        $out = [];
        foreach ($tenants as $t) {
            if (strtolower((string) ($t['lifecycleState'] ?? '')) === $state) $out[] = $t;
        }
        return $out;
    }

    /** Tenants on a specific plan family (by plan name). */
    public function drilldown_schools_by_plan(string $planName): array
    {
        if (!$this->ready) return [];
        $plans = $this->load_plan_price_map();
        $tenants = $this->registry()->list_tenants_summary();
        $out = [];
        foreach ($tenants as $t) {
            $pfid = (string) ($t['planFamilyId'] ?? '');
            $name = ($pfid !== '' && isset($plans[$pfid])) ? $plans[$pfid]['name'] : '— No Plan';
            if ($name === $planName) $out[] = $t;
        }
        return $out;
    }

    /** Tenants in a given city. */
    public function drilldown_schools_by_city(string $city): array
    {
        if (!$this->ready) return [];
        $tenants = $this->registry()->list_tenants_summary();
        $out = [];
        $needle = strtolower(trim($city));
        foreach ($tenants as $t) {
            if (strtolower(trim((string) ($t['city'] ?? ''))) === $needle) $out[] = $t;
        }
        return $out;
    }

    // ──────────────────────────────────────────────────────────────
    // PHASE 1D — FACETED SCHOOL SEARCH SPOKE
    //
    // Canonical schools-query surface. Used by the School Search spoke
    // and (post-Revenue Center, Phase 2) the invoice-generation scoping
    // layer. Filter semantics locked in design package §1.3.
    //
    // Implementation strategy:
    //   1. Pull the canonical tenant set via list_tenants_summary().
    //   2. Enrich each row with fields not in the summary view
    //      (createdAt, state/region, adminDisabled, domainIdentifier).
    //   3. Apply the filter predicate set (AND semantics across fields,
    //      OR semantics within multi-value array filters).
    //   4. Sort by the requested sort key (default: schoolName ASC).
    //   5. Compute pagination metadata, slice for the requested page.
    //
    // Read budget at current 3-tenant scale: 1 query on schools + 1 on
    // schoolControl + per-tenant subscription point-lookups (~6 reads
    // per page-load). Phase 1H adds composite-index recommendations
    // before the tenant count climbs.
    // ──────────────────────────────────────────────────────────────

    /** Cap on the maximum page_size accepted from the client (defensive). */
    const SEARCH_PAGE_SIZE_MAX = 1000;

    /** Per-request cache of the enriched tenant set keyed by signature. */
    private $_enriched_tenants_cache = null;

    /**
     * Faceted school search.
     *
     * @param array $filters  see design package §1.3 — keys:
     *      q (string), states (array<string>), plans (array<string>),
     *      cities (array<string>), regions (array<string>),
     *      students_min (int), students_max (int),
     *      staff_min (int), staff_max (int),
     *      created_from (Y-m-d), created_to (Y-m-d),
     *      expiry_from (Y-m-d), expiry_to (Y-m-d).
     * @param array $sort     ['field' => 'schoolName', 'order' => 'asc'|'desc']
     * @param int   $page     1-indexed page number.
     * @param int   $pageSize 25 / 50 / 100; capped at SEARCH_PAGE_SIZE_MAX.
     *
     * @return array{
     *   rows: array<int, array>,
     *   total: int,
     *   page: int,
     *   pageSize: int,
     *   pageCount: int,
     *   sort: array,
     *   filters_applied: array
     * }
     */
    public function search_schools(array $filters = [], array $sort = [], int $page = 1, int $pageSize = 25): array
    {
        $page = max(1, $page);
        $pageSize = max(1, min(self::SEARCH_PAGE_SIZE_MAX, $pageSize));
        $sort = $this->_normalize_sort($sort);

        if (!$this->ready) {
            return ['rows' => [], 'total' => 0, 'page' => $page, 'pageSize' => $pageSize,
                    'pageCount' => 0, 'sort' => $sort, 'filters_applied' => $filters];
        }

        $all = $this->_load_enriched_tenants();
        $filtered = [];
        foreach ($all as $row) {
            if ($this->_match_filters($row, $filters)) $filtered[] = $row;
        }

        $this->_sort_rows($filtered, $sort);

        $total = count($filtered);
        $pageCount = $total > 0 ? (int) ceil($total / $pageSize) : 0;
        $sliceFrom = ($page - 1) * $pageSize;
        $pageRows = array_slice($filtered, $sliceFrom, $pageSize);
        $pageRows = array_map([$this, '_attach_action_urls'], $pageRows);

        return [
            'rows'            => $pageRows,
            'total'           => $total,
            'page'            => $page,
            'pageSize'        => $pageSize,
            'pageCount'       => $pageCount,
            'sort'            => $sort,
            'filters_applied' => $filters,
        ];
    }

    /**
     * Filter dropdown values for the sidebar UI: lifecycle states present
     * in the data, plan family names from the canonical plans collection,
     * cities present in tenant set, regions/state values present, and
     * computed min/max ranges for the student/staff sliders.
     */
    public function search_schools_options(): array
    {
        if (!$this->ready) {
            return ['states' => [], 'plans' => [], 'cities' => [], 'regions' => [],
                    'students_max' => 0, 'staff_max' => 0,
                    'created_min' => '', 'expiry_max' => ''];
        }
        $tenants = $this->_load_enriched_tenants();
        $states = []; $cities = []; $regions = [];
        $maxStudents = 0; $maxStaff = 0;
        $minCreated = null; $maxExpiry = null;
        foreach ($tenants as $t) {
            $s = strtolower((string) ($t['lifecycleState'] ?? ''));
            if ($s !== '') $states[$s] = true;
            $c = trim((string) ($t['city'] ?? ''));
            if ($c !== '') $cities[$c] = true;
            $r = trim((string) ($t['region'] ?? ''));
            if ($r !== '') $regions[$r] = true;
            $maxStudents = max($maxStudents, (int) ($t['totalStudents'] ?? 0));
            $maxStaff    = max($maxStaff,    (int) ($t['totalStaff']    ?? 0));
            $cAt = (string) ($t['createdAt'] ?? '');
            if ($cAt !== '' && ($minCreated === null || strcmp($cAt, $minCreated) < 0)) $minCreated = $cAt;
            $eAt = (string) ($t['subscriptionPeriodEnd'] ?? '');
            if ($eAt !== '' && ($maxExpiry === null || strcmp($eAt, $maxExpiry) > 0)) $maxExpiry = $eAt;
        }
        // Plan options: every plan in the plans collection (so the operator
        // can search for plans even if no tenant currently subscribes).
        $plans = [];
        $planMap = $this->load_plan_price_map();
        foreach ($planMap as $key => $entry) {
            // load_plan_price_map indexes by BOTH planFamilyId and full id;
            // de-dupe on name.
            $name = (string) ($entry['name'] ?? '');
            if ($name !== '') $plans[$name] = true;
        }
        ksort($states); ksort($cities); ksort($regions); ksort($plans);
        return [
            'states'        => array_keys($states),
            'plans'         => array_keys($plans),
            'cities'        => array_keys($cities),
            'regions'       => array_keys($regions),
            'students_max'  => $maxStudents,
            'staff_max'     => $maxStaff,
            'created_min'   => $minCreated ?? '',
            'expiry_max'    => $maxExpiry ?? '',
        ];
    }

    /**
     * Resolve a saved-search slug to its persisted filter+sort payload.
     * Used by the schools_search controller when ?saved=<slug> is present.
     */
    public function apply_saved_search(string $userId, string $slug): ?array
    {
        if (!$this->ready || $userId === '' || $slug === '') return null;
        $docId = $userId . '_' . $slug;
        $doc = $this->firebase->firestoreGet('analyticsSavedSearches', $docId);
        if (!is_array($doc) || empty($doc)) return null;
        return [
            'filters' => is_array($doc['filters'] ?? null) ? $doc['filters'] : [],
            'sort'    => is_array($doc['sort']    ?? null) ? $doc['sort']    : [],
            'name'    => (string) ($doc['name']   ?? ''),
            'slug'    => $slug,
        ];
    }

    // ── Phase 1D internals ──────────────────────────────────────────

    private function _load_enriched_tenants(): array
    {
        if ($this->_enriched_tenants_cache !== null) return $this->_enriched_tenants_cache;
        $base = $this->registry()->list_tenants_summary();
        // Pull full schools docs once to harvest createdAt / state /
        // adminDisabled / domainIdentifier (fields not present in summary).
        $rawSchools = $this->firebase->firestoreQuery('schools', []);
        $byId = [];
        if (is_array($rawSchools)) {
            foreach ($rawSchools as $row) {
                $data = is_array($row['data'] ?? null) ? $row['data'] : (is_array($row) ? $row : []);
                $sid = (string) ($row['id'] ?? $data['__firestoreId'] ?? '');
                if ($sid === '') continue;
                $byId[$sid] = $data;
            }
        }
        // schoolControl read for adminDisabled (defense-in-depth alignment
        // with H1 server-side rule); tolerant to missing docs.
        $rawCtrls = $this->firebase->firestoreQuery('schoolControl', []);
        $ctrlsById = [];
        if (is_array($rawCtrls)) {
            foreach ($rawCtrls as $row) {
                $data = is_array($row['data'] ?? null) ? $row['data'] : (is_array($row) ? $row : []);
                $sid = (string) ($data['schoolId'] ?? $row['id'] ?? $data['__firestoreId'] ?? '');
                if ($sid !== '') $ctrlsById[$sid] = $data;
            }
        }
        $out = [];
        foreach ($base as $row) {
            $sid = (string) ($row['schoolId'] ?? '');
            $sData = $byId[$sid] ?? [];
            $cData = $ctrlsById[$sid] ?? [];
            $row['createdAt']         = (string) ($sData['createdAt'] ?? '');
            $row['region']            = (string) ($sData['state'] ?? $sData['region'] ?? '');
            $row['adminDisabled']     = (bool)   ($sData['adminDisabled'] ?? $cData['adminDisabled'] ?? false);
            $row['domainIdentifier']  = (string) ($sData['domainIdentifier'] ?? '');
            // Resolve plan name once.
            $planMap = $this->load_plan_price_map();
            $pfid = (string) ($row['planFamilyId'] ?? '');
            $row['planName'] = ($pfid !== '' && isset($planMap[$pfid]))
                ? (string) $planMap[$pfid]['name']
                : '— No Plan';
            $out[] = $row;
        }
        $this->_enriched_tenants_cache = $out;
        return $out;
    }

    private function _match_filters(array $row, array $f): bool
    {
        // 1. Free-text — substring across name/code/city/domain/sid.
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $needle = strtolower($q);
            $hay = strtolower(implode(' ', [
                (string) ($row['schoolName']       ?? ''),
                (string) ($row['schoolCode']       ?? ''),
                (string) ($row['city']             ?? ''),
                (string) ($row['domainIdentifier'] ?? ''),
                (string) ($row['schoolId']         ?? ''),
                (string) ($row['region']           ?? ''),
            ]));
            if (strpos($hay, $needle) === false) return false;
        }

        // 2. Lifecycle states (multi-value OR).
        $states = self::_csv_to_array($f['states'] ?? []);
        if (!empty($states)) {
            $rs = strtolower((string) ($row['lifecycleState'] ?? ''));
            $statesL = array_map('strtolower', $states);
            if (!in_array($rs, $statesL, true)) return false;
        }

        // 3. Plans (by name; multi-value OR).
        $plans = self::_csv_to_array($f['plans'] ?? []);
        if (!empty($plans)) {
            $rp = (string) ($row['planName'] ?? '');
            if (!in_array($rp, $plans, true)) return false;
        }

        // 4. Cities (case-insensitive multi-value OR).
        $cities = self::_csv_to_array($f['cities'] ?? []);
        if (!empty($cities)) {
            $rc = strtolower(trim((string) ($row['city'] ?? '')));
            $citiesL = array_map(fn($c) => strtolower(trim((string) $c)), $cities);
            if (!in_array($rc, $citiesL, true)) return false;
        }

        // 5. Regions (case-insensitive multi-value OR).
        $regions = self::_csv_to_array($f['regions'] ?? []);
        if (!empty($regions)) {
            $rr = strtolower(trim((string) ($row['region'] ?? '')));
            $regionsL = array_map(fn($r) => strtolower(trim((string) $r)), $regions);
            if (!in_array($rr, $regionsL, true)) return false;
        }

        // 6. Student count range.
        $stu = (int) ($row['totalStudents'] ?? 0);
        if (isset($f['students_min']) && $f['students_min'] !== '' && $stu < (int) $f['students_min']) return false;
        if (isset($f['students_max']) && $f['students_max'] !== '' && $stu > (int) $f['students_max']) return false;

        // 7. Staff count range.
        $staff = (int) ($row['totalStaff'] ?? 0);
        if (isset($f['staff_min']) && $f['staff_min'] !== '' && $staff < (int) $f['staff_min']) return false;
        if (isset($f['staff_max']) && $f['staff_max'] !== '' && $staff > (int) $f['staff_max']) return false;

        // 8. Created date range. createdAt may be ISO 8601 or Y-m-d.
        $cAt = (string) ($row['createdAt'] ?? '');
        if (!empty($f['created_from'])) {
            $ts = $cAt !== '' ? strtotime($cAt) : 0;
            $cmp = strtotime($f['created_from']);
            if ($ts === 0 || ($cmp !== false && $ts < $cmp)) return false;
        }
        if (!empty($f['created_to'])) {
            $ts = $cAt !== '' ? strtotime($cAt) : 0;
            // inclusive end-of-day
            $cmp = strtotime($f['created_to'] . ' 23:59:59');
            if ($ts === 0 || ($cmp !== false && $ts > $cmp)) return false;
        }

        // 9. Expiry date range.
        $eAt = (string) ($row['subscriptionPeriodEnd'] ?? '');
        if (!empty($f['expiry_from'])) {
            $ts = $eAt !== '' ? strtotime($eAt) : 0;
            $cmp = strtotime($f['expiry_from']);
            if ($ts === 0 || ($cmp !== false && $ts < $cmp)) return false;
        }
        if (!empty($f['expiry_to'])) {
            $ts = $eAt !== '' ? strtotime($eAt) : 0;
            $cmp = strtotime($f['expiry_to'] . ' 23:59:59');
            if ($ts === 0 || ($cmp !== false && $ts > $cmp)) return false;
        }

        return true;
    }

    private function _normalize_sort(array $sort): array
    {
        $allowed = ['schoolName', 'schoolCode', 'totalStudents', 'totalStaff',
                    'createdAt', 'subscriptionPeriodEnd', 'lifecycleState'];
        $field = (string) ($sort['field'] ?? 'schoolName');
        if (!in_array($field, $allowed, true)) $field = 'schoolName';
        $order = strtolower((string) ($sort['order'] ?? 'asc'));
        if (!in_array($order, ['asc', 'desc'], true)) $order = 'asc';
        return ['field' => $field, 'order' => $order];
    }

    private function _sort_rows(array &$rows, array $sort): void
    {
        $field = $sort['field']; $desc = ($sort['order'] === 'desc');
        $numeric = in_array($field, ['totalStudents', 'totalStaff'], true);
        usort($rows, function ($a, $b) use ($field, $desc, $numeric) {
            $va = $a[$field] ?? null; $vb = $b[$field] ?? null;
            $cmp = $numeric ? ((int) $va) <=> ((int) $vb)
                            : strcasecmp((string) $va, (string) $vb);
            return $desc ? -$cmp : $cmp;
        });
    }

    private function _attach_action_urls(array $row): array
    {
        $sid = (string) ($row['schoolId'] ?? '');
        $row['_detail_url']       = $sid !== '' ? site_url('superadmin/schools/view/' . $sid) : '';
        $row['_subscription_url'] = $sid !== '' ? site_url('superadmin/schools/view/' . $sid) . '#subscription' : '';
        $row['_analytics_url']    = $sid !== '' ? site_url('superadmin/dashboard/tenant/' . $sid) : '';
        return $row;
    }

    /**
     * Accept either array or comma-separated string for multi-value filters.
     * URL-encoded multi-values arrive as CSV via $_GET; saved-search docs
     * persist as actual arrays.
     */
    private static function _csv_to_array($val): array
    {
        if (is_array($val)) return array_values(array_filter(array_map('trim', $val), fn($v) => $v !== ''));
        if (is_string($val) && trim($val) !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $val)), fn($v) => $v !== ''));
        }
        return [];
    }

    // ──────────────────────────────────────────────────────────────
    // STUBS — come online in later phases
    // ──────────────────────────────────────────────────────────────

    /** Phase 1F cross-school engagement metrics. */
    public function get_cross_school_summary(): array
    {
        return ['_phase' => '1F_pending'];
    }

    /** Phase 1G per-tenant deep dive composite. */
    public function get_tenant_detail_bundle(string $schoolId): array
    {
        return ['_phase' => '1G_pending', 'schoolId' => $schoolId];
    }

    // ──────────────────────────────────────────────────────────────
    // INTERNAL: registry handle
    // ──────────────────────────────────────────────────────────────

    private $registryHandle = null;
    private function load_registry(): void
    {
        if ($this->registryHandle !== null) return;
        $CI =& get_instance();
        if (!isset($CI->b2_registry_service)) $CI->load->library('b2_registry_service');
        $CI->b2_registry_service->init($this->firebase);
        $this->registryHandle = $CI->b2_registry_service;
    }
    private function registry()
    {
        $this->load_registry();
        return $this->registryHandle;
    }
}
