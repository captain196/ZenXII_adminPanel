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

        // D-KPI: fetch subscriptions ONCE and feed both MRR and active-count
        // from the shared dataset, instead of two separate full-collection
        // scans of the same collection. (Reached only when ready — the
        // !ready path returned _zero_kpi() above.) Output identical.
        $subs = $this->firebase->firestoreQuery('subscriptions', []);
        if (!is_array($subs)) $subs = [];
        return [
            'total_schools'        => $total_schools,
            'active_schools'       => $active_schools,
            'total_students'       => $total_students,
            'total_staff'          => $total_staff,
            'mrr'                  => $this->_mrr_from($subs),
            'active_subscriptions' => $this->_count_active_from($subs),
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
     * D-KPI: MRR from a pre-fetched subscriptions dataset. Mirrors
     * compute_mrr_from_subscriptions() verbatim minus the fetch, so both
     * KPI values can be served from a single read. No read.
     */
    private function _mrr_from(array $subs): float
    {
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

    /**
     * D-KPI: active-subscription count from a pre-fetched dataset. Mirrors
     * count_active_subscriptions() verbatim minus the fetch. No read.
     */
    private function _count_active_from(array $subs): int
    {
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

    /**
     * S2: fetch each monthly rollup doc ONCE (oldest→newest), so multiple
     * series can be projected from a single set of reads instead of
     * re-reading the same docs per series. Same period keys / order as
     * _load_monthly_rollups_series(). Returns
     * [ ['period' => 'YYYY-MM', 'doc' => array|null], ... ].
     */
    private function _fetch_monthly_rollups(int $months): array
    {
        $rows = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $periodKey = date('Y-m', strtotime("first day of -{$i} months"));
            $rows[] = [
                'period' => $periodKey,
                'doc'    => $this->ready ? $this->firebase->firestoreGet('analyticsRollups', $periodKey) : null,
            ];
        }
        return $rows;
    }

    /**
     * S2: project a field-set from pre-fetched rollup rows into the exact
     * shape _load_monthly_rollups_series() produces (period + each field,
     * missing/absent → 0). No reads — operates on the cached fetch.
     */
    private function _project_rollup_series(array $rollupRows, array $fields): array
    {
        $out = [];
        foreach ($rollupRows as $r) {
            $row = ['period' => $r['period'] ?? ''];
            $doc = is_array($r['doc'] ?? null) ? $r['doc'] : null;
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
        // D-S2: fetch the 12 monthly rollup docs ONCE and project both
        // Dashboard time-series from the shared dataset (reuses the S2
        // helpers), instead of get_time_series_*() each re-reading the same
        // docs. Field orders preserved → output/shape/ordering identical.
        $rollupRows = $this->_fetch_monthly_rollups(12);
        return [
            'kpi'             => $this->get_kpi_totals(),
            'time_series'     => [
                'schools_growth' => $this->_project_rollup_series($rollupRows, ['totalSchools', 'newSchoolsCount']),
                'revenue'        => $this->_project_rollup_series($rollupRows, ['mrr', 'totalRevenue']),
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
        // S2: fetch each monthly rollup doc ONCE, then project all three
        // series from that single set of reads — instead of re-reading the
        // same M docs once per series (the prior 3× redundant pattern).
        // Output, shape, and ordering are identical to the per-series
        // _load_monthly_rollups_series() path.
        $rollupRows = $this->_fetch_monthly_rollups($monthsBack);
        // S4: fetch the schools collection ONCE for the four createdAt-based
        // aggregates below, instead of four identical full-collection scans.
        // Each projection mirrors its public counterpart exactly (same filter,
        // createdAt handling, ordering) — output is unchanged.
        $schoolRows = $this->_fetch_school_rows();
        return [
            'monthsBack'              => $monthsBack,
            'schools_onboarded_series' => $this->_project_rollup_series($rollupRows, ['newSchoolsCount', 'totalSchools']),
            'students_growth_series'   => $this->_project_rollup_series($rollupRows, ['totalStudents']),
            'staff_growth_series'      => $this->_project_rollup_series($rollupRows, ['totalStaff']),
            'lifecycle_distribution'   => $this->get_lifecycle_distribution(),
            'plan_distribution'        => $this->get_plan_distribution(),
            'schools_by_city'          => $this->get_schools_by_city(),
            'avg_tenant_age_days'      => $this->_avg_age_from($schoolRows),
            'net_new_30d'              => $this->_count_new_from($schoolRows, 30),
            'net_new_90d'              => $this->_count_new_from($schoolRows, 90),
            'recent_registrations'     => $this->_recent_regs_from($schoolRows, 30, 10),
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
    // S4: single-fetch projections for the Statistics createdAt aggregates.
    // _fetch_school_rows() reads the schools collection ONCE; the three
    // _*_from() helpers replicate the public get_average_tenant_age_days()
    // / count_new_schools_in_window() / get_recent_registrations() logic
    // verbatim but operate on the pre-fetched rows (no read). The public
    // functions are left intact for any other caller.
    // ──────────────────────────────────────────────────────────────

    private function _fetch_school_rows(): array
    {
        if (!$this->ready) return [];
        $rows = $this->firebase->firestoreQuery('schools', []);
        return is_array($rows) ? $rows : [];
    }

    private function _avg_age_from(array $schools): int
    {
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

    private function _count_new_from(array $schools, int $daysWindow): int
    {
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

    private function _recent_regs_from(array $schools, int $daysWindow, int $limit): array
    {
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
        // 2026-06-03 Phase 1H DEFECT FIX (walkthrough-caught): pre-fix used
        // (bool) ($sData['adminDisabled'] ?? $cData['adminDisabled'] ?? false)
        // which evaluated the Array audit-log struct as TRUE — producing
        // phantom DISABLED badges on IIT Kanpur (schoolControl.adminDisabled
        // is an Array on this tenant). Now reads the same H1.5 canonical
        // priority chain as get_tenant_identity() (Phase 1G fix) + the
        // list_tenants_summary registry enrichment (Phase 1H H1.P0.a):
        //   1. base row's adminDisabled (already resolved by Phase 1H
        //      list_tenants_summary using tenantPublic canonical → schools
        //      Array.value fallback → strict === true)
        // Falls back to direct reads only when the base row didn't carry
        // adminDisabled (defensive against pre-Phase-1H callers).
        $rawCtrls = $this->firebase->firestoreQuery('schoolControl', []);
        $ctrlsById = [];
        if (is_array($rawCtrls)) {
            foreach ($rawCtrls as $row) {
                $data = is_array($row['data'] ?? null) ? $row['data'] : (is_array($row) ? $row : []);
                $sid = (string) ($data['schoolId'] ?? $row['id'] ?? $data['__firestoreId'] ?? '');
                if ($sid !== '') $ctrlsById[$sid] = $data;
            }
        }
        $rawPublic = $this->firebase->firestoreQuery('tenantPublic', []);
        $publicsById = [];
        if (is_array($rawPublic)) {
            foreach ($rawPublic as $row) {
                $data = is_array($row['data'] ?? null) ? $row['data'] : (is_array($row) ? $row : []);
                $sid = (string) ($data['schoolId'] ?? $row['id'] ?? $data['__firestoreId'] ?? '');
                if ($sid !== '') $publicsById[$sid] = $data;
            }
        }
        $out = [];
        foreach ($base as $row) {
            $sid = (string) ($row['schoolId'] ?? '');
            $sData = $byId[$sid] ?? [];
            $cData = $ctrlsById[$sid] ?? [];
            $pData = $publicsById[$sid] ?? [];
            $row['createdAt']         = (string) ($sData['createdAt'] ?? '');
            $row['region']            = (string) ($sData['state'] ?? $sData['region'] ?? '');
            // adminDisabled: prefer the value already carried by the
            // Phase-1H-enriched base row; otherwise compute it locally
            // using the same H1.5 canonical priority + strict === true.
            if (array_key_exists('adminDisabled', $row) && is_bool($row['adminDisabled'])) {
                // base row already enriched by Phase 1H list_tenants_summary
                // — preserve it (no recomputation).
            } else {
                $ad = false;
                if (($pData['adminDisabled'] ?? null) === true) {
                    $ad = true;
                } elseif (is_array($sData['adminDisabled'] ?? null)
                          && (($sData['adminDisabled']['value'] ?? false) === true)) {
                    $ad = true;
                }
                $row['adminDisabled'] = $ad;
            }
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
    // PHASE 1E — REVENUE REPORTS SPOKE
    //
    // Canonical revenue-numbers surface. Used by the Revenue Reports
    // spoke and (post-Revenue Center, Phase 2) becomes the read-only
    // half of revenue while Phase 2 adds the write half.
    //
    // Read sources:
    //   * analyticsRollups/{YYYY-MM}   — pre-computed monthly series
    //   * subscriptions + plans        — live MRR derivation
    //   * payments                     — payment history + recent feed
    //   * list_tenants_summary()       — tenant labels + at-risk class
    //
    // Phase 2 swap-point: when invoices/{id} collection ships, the
    // sources below switch from `payments` and `subscriptions` to
    // `invoices`, with public signatures unchanged. Comments mark
    // each swap-point inline.
    //
    // INR-only assumption hard-coded in Phase 1E (single-currency
    // ERP at current scale). Multi-currency requires Phase 1H/2.
    // ──────────────────────────────────────────────────────────────

    const REVENUE_CURRENCY = 'INR';
    const REVENUE_DEFAULT_WINDOW_MONTHS = 12;

    /**
     * Composite revenue overview — Hub for the Phase 1E view.
     *
     * @param int $monthsBack  3 | 6 | 12 | 24 (caller-validated)
     */
    public function get_revenue_overview(int $monthsBack = 12): array
    {
        $monthsBack = max(1, min(36, $monthsBack));
        $daysWindow = $monthsBack * 30;
        $mrr = $this->compute_mrr_from_subscriptions();
        $arr = $this->compute_arr();
        $totalRevenueWindow = $this->get_total_revenue_in_window($daysWindow);
        $tenantsCount = $this->ready ? count($this->registry()->list_tenants_summary()) : 0;
        $activeTenantsCount = 0;
        foreach ($this->ready ? $this->registry()->list_tenants_summary() : [] as $t) {
            if (in_array(strtolower((string) ($t['lifecycleState'] ?? '')), self::ALLOWED_LIFECYCLE_STATES, true)) {
                $activeTenantsCount++;
            }
        }
        $arpu = $this->get_arpu($activeTenantsCount, $totalRevenueWindow);
        $outstandingResult = $this->get_outstanding_receivables();
        return [
            'monthsBack'             => $monthsBack,
            'currency'               => self::REVENUE_CURRENCY,
            'headline_kpi'           => [
                'mrr'                  => $mrr,
                'arr'                  => $arr,
                'total_revenue_window' => $totalRevenueWindow,
                'arpu'                 => $arpu,
                'outstanding_amount'   => $outstandingResult['amount'],
                'outstanding_count'    => $outstandingResult['count'],
                'active_tenants'       => $activeTenantsCount,
                'total_tenants'        => $tenantsCount,
                'window_days'          => $daysWindow,
            ],
            'time_series_mrr'        => $this->get_time_series_revenue($monthsBack),
            'time_series_payments'   => $this->get_time_series_payments_volume($monthsBack),
            'revenue_by_plan'        => $this->get_revenue_by_plan(),
            'recent_payments'        => $this->get_recent_payments(10),
            'at_risk_tenants'        => $this->get_at_risk_tenants(),
            'lost_mrr_by_state'      => $this->get_lost_mrr_by_state(),
            'generated_at'           => date('c'),
        ];
    }

    /** ARR = MRR × 12 (industry-standard SaaS convention). */
    public function compute_arr(): float
    {
        return $this->compute_mrr_from_subscriptions() * 12.0;
    }

    /**
     * Total revenue collected within the trailing N days, summed from the
     * `payments` collection.
     *
     * Phase 2 swap-point: when `invoices` ships, this method will sum
     * `invoices.amount` where `status = paid AND paidAt in window`.
     */
    public function get_total_revenue_in_window(int $daysBack): float
    {
        if (!$this->ready) return 0.0;
        $cut = time() - max(1, $daysBack) * 86400;
        $rows = $this->ready ? $this->registry()->list_payments() : [];
        $sum = 0.0;
        foreach ($rows as $r) {
            $paidAt = (string) ($r['paidAt'] ?? $r['createdAt'] ?? '');
            $ts = $paidAt !== '' ? strtotime($paidAt) : 0;
            if ($ts <= 0 || $ts < $cut) continue;
            $amt = (float) ($r['amount'] ?? $r['totalAmount'] ?? 0);
            $sum += $amt;
        }
        return $sum;
    }

    /**
     * ARPU = total revenue in window ÷ active tenant count.
     * Returns 0.0 if no active tenants (avoid div-by-zero).
     */
    public function get_arpu(int $activeTenantsCount, float $totalRevenueWindow): float
    {
        if ($activeTenantsCount <= 0) return 0.0;
        return $totalRevenueWindow / $activeTenantsCount;
    }

    /**
     * Outstanding receivables — tenants whose `subscriptionPeriodEnd`
     * is in the past AND lifecycle state is `grace` or `past_due`,
     * priced at one billing cycle of the assigned plan.
     */
    public function get_outstanding_receivables(): array
    {
        if (!$this->ready) return ['amount' => 0.0, 'count' => 0, 'tenants' => []];
        $plans = $this->load_plan_price_map();
        $tenants = $this->registry()->list_tenants_summary();
        $amount = 0.0; $count = 0; $rows = [];
        $now = time();
        foreach ($tenants as $t) {
            $state = strtolower((string) ($t['lifecycleState'] ?? ''));
            if (!in_array($state, ['grace', 'past_due'], true)) continue;
            $end = (string) ($t['subscriptionPeriodEnd'] ?? '');
            $ts = $end !== '' ? strtotime($end) : 0;
            if ($ts <= 0 || $ts >= $now) continue;
            $pfid = (string) ($t['planFamilyId'] ?? '');
            $price = ($pfid !== '' && isset($plans[$pfid])) ? (float) $plans[$pfid]['price'] : 0.0;
            $rows[] = [
                'schoolId'    => $t['schoolId'] ?? '',
                'schoolName'  => $t['schoolName'] ?? '',
                'city'        => $t['city'] ?? '',
                'planName'    => ($pfid !== '' && isset($plans[$pfid])) ? $plans[$pfid]['name'] : '— No Plan',
                'state'       => $state,
                'periodEnd'   => $end,
                'daysOverdue' => max(0, (int) floor(($now - $ts) / 86400)),
                'amount'      => $price,
            ];
            $amount += $price;
            $count++;
        }
        usort($rows, fn($a, $b) => $b['daysOverdue'] - $a['daysOverdue']);
        return ['amount' => $amount, 'count' => $count, 'tenants' => $rows];
    }

    /**
     * Revenue grouped by plan family — donut + table data.
     *
     * @return array  [['planName', 'mrr', 'arr', 'tenants', 'avgPerTenant', 'sharePct'], ...]
     */
    public function get_revenue_by_plan(): array
    {
        if (!$this->ready) return [];
        $plans = $this->load_plan_price_map();
        $subs = $this->firebase->firestoreQuery('subscriptions', []);
        if (!is_array($subs)) return [];
        $byPlan = [];
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
            $name = (string) $plans[$planId]['name'];
            if (!isset($byPlan[$name])) {
                $byPlan[$name] = ['planName' => $name, 'mrr' => 0.0, 'arr' => 0.0,
                                  'tenants' => 0, 'avgPerTenant' => 0.0, 'sharePct' => 0.0];
            }
            $byPlan[$name]['mrr']     += $price / $months;
            $byPlan[$name]['tenants'] += 1;
        }
        // Compute derived fields
        $totalMrr = array_sum(array_column($byPlan, 'mrr'));
        foreach ($byPlan as &$row) {
            $row['arr']          = $row['mrr'] * 12.0;
            $row['avgPerTenant'] = $row['tenants'] > 0 ? $row['mrr'] / $row['tenants'] : 0.0;
            $row['sharePct']     = $totalMrr > 0 ? ($row['mrr'] / $totalMrr) * 100.0 : 0.0;
        }
        unset($row);
        usort($byPlan, fn($a, $b) => $b['mrr'] <=> $a['mrr']);
        return array_values($byPlan);
    }

    /**
     * Most recent N payments, newest first, enriched with school name.
     */
    public function get_recent_payments(int $limit = 10): array
    {
        if (!$this->ready) return [];
        $rows = $this->registry()->list_payments();
        if (!is_array($rows)) return [];
        $tenantsByid = [];
        foreach ($this->registry()->list_tenants_summary() as $t) {
            $tenantsByid[(string) ($t['schoolId'] ?? '')] = $t;
        }
        $out = [];
        foreach ($rows as $r) {
            $sid = (string) ($r['schoolId'] ?? $r['school_uid'] ?? '');
            $tenant = $tenantsByid[$sid] ?? [];
            $out[] = [
                'paidAt'      => (string) ($r['paidAt'] ?? $r['createdAt'] ?? ''),
                'schoolId'    => $sid,
                'schoolName'  => (string) ($tenant['schoolName'] ?? $sid),
                'planName'    => (string) ($r['planName'] ?? ''),
                'amount'      => (float)  ($r['amount']   ?? $r['totalAmount'] ?? 0),
                'method'      => (string) ($r['method']   ?? $r['paymentMethod'] ?? '—'),
                'reference'   => (string) ($r['reference']?? $r['transactionId']  ?? ''),
            ];
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    /**
     * Tenants in at-risk lifecycle states: `past_due`, `grace`, `expiring_soon`.
     * Returns enriched rows including plan and overdue days.
     */
    public function get_at_risk_tenants(): array
    {
        if (!$this->ready) return [];
        $plans = $this->load_plan_price_map();
        $tenants = $this->registry()->list_tenants_summary();
        $rows = [];
        $now = time();
        foreach ($tenants as $t) {
            $state = strtolower((string) ($t['lifecycleState'] ?? ''));
            if (!in_array($state, ['past_due', 'grace', 'expiring_soon'], true)) continue;
            $end = (string) ($t['subscriptionPeriodEnd'] ?? '');
            $ts = $end !== '' ? strtotime($end) : 0;
            $daysToOrFrom = $ts > 0 ? (int) floor(($ts - $now) / 86400) : null;
            $pfid = (string) ($t['planFamilyId'] ?? '');
            $price = ($pfid !== '' && isset($plans[$pfid])) ? (float) $plans[$pfid]['price'] : 0.0;
            $cycle = ($pfid !== '' && isset($plans[$pfid])) ? strtolower((string) $plans[$pfid]['billingCycle']) : 'annual';
            $months = self::BILLING_CYCLE_MONTHS[$cycle] ?? 12;
            $atRiskMrr = $months > 0 ? $price / $months : 0.0;
            $rows[] = [
                'schoolId'   => $t['schoolId'] ?? '',
                'schoolName' => $t['schoolName'] ?? '',
                'city'       => $t['city'] ?? '',
                'planName'   => ($pfid !== '' && isset($plans[$pfid])) ? $plans[$pfid]['name'] : '— No Plan',
                'state'      => $state,
                'periodEnd'  => $end,
                'daysToEnd'  => $daysToOrFrom,
                'atRiskMrr'  => $atRiskMrr,
            ];
        }
        // Sort: past_due first, then grace, then expiring_soon; within group by daysToEnd asc
        $sevRank = ['past_due' => 0, 'grace' => 1, 'expiring_soon' => 2];
        usort($rows, function ($a, $b) use ($sevRank) {
            $ra = $sevRank[$a['state']] ?? 99;
            $rb = $sevRank[$b['state']] ?? 99;
            if ($ra !== $rb) return $ra - $rb;
            return (int) ($a['daysToEnd'] ?? 0) - (int) ($b['daysToEnd'] ?? 0);
        });
        return $rows;
    }

    /**
     * Lost MRR aggregated by terminal lifecycle states
     * (past_due, suspended, expired). For each state returns total
     * MRR that would be collected if those tenants resumed normally.
     */
    public function get_lost_mrr_by_state(): array
    {
        if (!$this->ready) return [];
        $plans = $this->load_plan_price_map();
        $tenants = $this->registry()->list_tenants_summary();
        $out = ['past_due' => 0.0, 'suspended' => 0.0, 'expired' => 0.0];
        foreach ($tenants as $t) {
            $state = strtolower((string) ($t['lifecycleState'] ?? ''));
            if (!isset($out[$state])) continue;
            $pfid = (string) ($t['planFamilyId'] ?? '');
            if ($pfid === '' || !isset($plans[$pfid])) continue;
            $price = (float) $plans[$pfid]['price'];
            $cycle = strtolower((string) $plans[$pfid]['billingCycle']);
            $months = self::BILLING_CYCLE_MONTHS[$cycle] ?? 12;
            if ($months <= 0) continue;
            $out[$state] += $price / $months;
        }
        return $out;
    }

    /**
     * Payment volume time-series from `analyticsRollups` monthly docs.
     * @return array  [['period' => 'YYYY-MM', 'totalRevenue' => float, 'paidPaymentsCount' => int], ...]
     */
    public function get_time_series_payments_volume(int $months = 12): array
    {
        return $this->_load_monthly_rollups_series($months, ['totalRevenue', 'paidPaymentsCount']);
    }

    // ──────────────────────────────────────────────────────────────
    // PHASE 1F — CROSS-SCHOOL SUMMARIES SPOKE
    //
    // Canonical fleet-comparison surface. Per-tenant accessors here
    // are reused (un-inverted) by Phase 1G per-tenant deep dive.
    //
    // Read sources:
    //   * analyticsRollups/{YYYY-MM}.tenantsRollup[]  — per-tenant aggregates
    //   * tenantAudit                                 — activity volume
    //   * list_tenants_summary()                      — labels + lifecycle
    //   * plans + subscriptions                       — revenue contribution
    //
    // Operator-locked constraint (Phase 1F authorization 2026-06-02):
    // exclude test/soak/synthetic tenants from default rankings; mark
    // them with a non-prod badge when they ARE shown (toggle).
    //
    // Predicate evidence tiers for is_test_tenant():
    //   Tier 1  — explicit field schools/{id}.isTestTenant=true (future-proof)
    //   Tier 2  — schoolName starts with "ZZ " (operator convention)
    //   Tier 3  — schoolName contains test|soak|demo|synthetic|sandbox|
    //             staging|validation|qa keyword (case-insensitive)
    //   Tier 4  — schoolCode is all-zero placeholder pattern
    // ──────────────────────────────────────────────────────────────

    const CROSS_SCHOOL_DEFAULT_DAYS  = 30;
    const CROSS_SCHOOL_DEFAULT_TREND = 12;
    const CROSS_SCHOOL_LEADERBOARD_SIZE = 5;
    const TEST_TENANT_KEYWORDS = ['test', 'soak', 'demo', 'synthetic', 'sandbox', 'staging', 'validation', 'qa'];

    /**
     * Predicate: is this tenant a test / soak / synthetic / onboarding-
     * validation tenant whose presence would distort fleet metrics?
     *
     * Layered evidence (first match wins):
     *   Tier 1  — explicit isTestTenant=true field on schools doc
     *   Tier 2  — name starts with "ZZ " operator convention
     *   Tier 3  — name contains a keyword from TEST_TENANT_KEYWORDS
     *   Tier 4  — schoolCode is all-zero placeholder (rare)
     *
     * @param array $tenantSummary  enriched row from list_tenants_summary()
     *                              or an analyticsRollups.tenantsRollup row.
     *                              MAY be the raw schools doc data.
     */
    public function is_test_tenant(array $tenantSummary): bool
    {
        // Tier 1: explicit field (future-proof against operator backfilling
        // an isTestTenant flag on tenants).
        if (!empty($tenantSummary['isTestTenant'])) return true;
        $name = (string) ($tenantSummary['schoolName'] ?? $tenantSummary['name'] ?? '');
        if ($name === '') return false;
        // Tier 2: "ZZ " operator convention (e.g., "ZZ B1 Soak Test").
        if (strpos($name, 'ZZ ') === 0) return true;
        // Tier 3: keyword match (case-insensitive whole-word).
        $kw = implode('|', array_map('preg_quote', self::TEST_TENANT_KEYWORDS));
        if (preg_match('/\\b(' . $kw . ')\\b/i', $name)) return true;
        // Tier 4: schoolCode all-zero placeholder.
        $code = (string) ($tenantSummary['schoolCode'] ?? '');
        if ($code !== '' && preg_match('/^0+$/', $code)) return true;
        return false;
    }

    /**
     * Composite cross-school summary payload.
     *
     * @param int    $daysWindow  engagement window for activity (7|30|90).
     * @param int    $monthsTrend growth window for student/staff deltas (3|6|12).
     * @param string $metricKey   metric driving leaderboards + matrix sort.
     * @param bool   $includeTest include test tenants in scope (default: false).
     */
    public function get_cross_school_summary(int $daysWindow = 30, int $monthsTrend = 12,
                                              string $metricKey = 'activity_volume',
                                              bool $includeTest = false): array
    {
        $daysWindow  = in_array($daysWindow,  [7, 30, 90], true) ? $daysWindow  : self::CROSS_SCHOOL_DEFAULT_DAYS;
        $monthsTrend = in_array($monthsTrend, [3, 6, 12], true) ? $monthsTrend : self::CROSS_SCHOOL_DEFAULT_TREND;
        $allowedMetrics = ['activity_volume', 'student_growth', 'staff_growth',
                           'data_freshness_hours', 'revenue_contribution'];
        if (!in_array($metricKey, $allowedMetrics, true)) $metricKey = 'activity_volume';

        if (!$this->ready) {
            return ['daysWindow' => $daysWindow, 'monthsTrend' => $monthsTrend,
                    'metricKey' => $metricKey, 'includeTest' => $includeTest,
                    'fleet_kpi' => [], 'leaderboard_top' => [], 'leaderboard_bottom' => [],
                    'comparative_matrix' => [], 'engagement_distribution' => [],
                    'test_tenant_count' => 0, 'production_tenant_count' => 0,
                    'generated_at' => date('c')];
        }

        $matrix = $this->get_comparative_matrix($daysWindow, $monthsTrend);
        $scoped = $includeTest ? $matrix : array_values(array_filter($matrix, fn($r) => !($r['isTestTenant'] ?? false)));
        $testCount = count($matrix) - count($scoped);

        return [
            'daysWindow'              => $daysWindow,
            'monthsTrend'             => $monthsTrend,
            'metricKey'               => $metricKey,
            'includeTest'             => $includeTest,
            'fleet_kpi'               => $this->get_fleet_kpi_snapshot($daysWindow, $includeTest),
            'leaderboard_top'         => $this->get_engagement_leaderboard($metricKey, $scoped, self::CROSS_SCHOOL_LEADERBOARD_SIZE, 'desc'),
            'leaderboard_bottom'      => $this->get_engagement_leaderboard($metricKey, $scoped, self::CROSS_SCHOOL_LEADERBOARD_SIZE, 'asc'),
            'comparative_matrix'      => $matrix,
            'engagement_distribution' => $this->get_engagement_distribution($metricKey, $scoped),
            'test_tenant_count'       => $testCount,
            'production_tenant_count' => count($scoped),
            'generated_at'            => date('c'),
        ];
    }

    /** Fleet-level KPI tiles. */
    public function get_fleet_kpi_snapshot(int $daysWindow = 30, bool $includeTest = false): array
    {
        if (!$this->ready) return [];
        $tenants = $this->registry()->list_tenants_summary();
        $prodTenants = $includeTest ? $tenants : array_values(array_filter($tenants, fn($t) => !$this->is_test_tenant($t)));
        $totalAudit = 0;
        $byTenant = $this->get_per_tenant_activity_volume($daysWindow);
        foreach ($prodTenants as $t) {
            $sid = (string) ($t['schoolId'] ?? '');
            $totalAudit += (int) ($byTenant[$sid] ?? 0);
        }
        $avgPerTenant = count($prodTenants) > 0 ? (int) round($totalAudit / count($prodTenants)) : 0;
        $totalStudentDelta = 0;
        foreach ($this->get_per_tenant_growth_deltas(3) as $sid => $delta) {
            $tenant = array_filter($prodTenants, fn($t) => ($t['schoolId'] ?? '') === $sid);
            if (empty($tenant)) continue;
            $totalStudentDelta += (int) ($delta['student_delta'] ?? 0);
        }
        $stale = $this->get_stale_stats_tenants(7);
        $staleProd = $includeTest ? $stale : array_values(array_filter($stale, function ($r) use ($prodTenants) {
            $sid = $r['schoolId'] ?? '';
            foreach ($prodTenants as $t) if (($t['schoolId'] ?? '') === $sid) return true;
            return false;
        }));
        // 2026-06-02 DEFECT FIX: scope-consistent MRR. Pre-fix used
        // unscoped compute_mrr_from_subscriptions() (fleet-wide numerator)
        // divided by prod-scoped denominator — over-attributed test-tenant
        // revenue to the prod-tenant denominator. Post-fix sums per-tenant
        // revenue contributions ONLY for the in-scope (filtered) tenant set,
        // matching the denominator.
        $revenuePerTenant = $this->get_per_tenant_revenue_contribution();
        $scopedMrr = 0.0;
        foreach ($prodTenants as $t) {
            $scopedMrr += (float) ($revenuePerTenant[$t['schoolId'] ?? ''] ?? 0);
        }
        $activeProd = array_filter($prodTenants, fn($t) => in_array(strtolower((string) ($t['lifecycleState'] ?? '')), self::ALLOWED_LIFECYCLE_STATES, true));
        $perTenantMrr = count($activeProd) > 0 ? $scopedMrr / count($activeProd) : 0.0;
        return [
            'total_audit_events'    => $totalAudit,
            'avg_activity_tenant'   => $avgPerTenant,
            'total_student_delta'   => $totalStudentDelta,
            'stale_tenants_count'   => count($staleProd),
            'stale_tenants_total'   => count($prodTenants),
            'cross_tenant_mrr'      => $perTenantMrr,
            'scoped_mrr_total'      => $scopedMrr,
            'window_days'           => $daysWindow,
            'in_scope_count'        => count($prodTenants),
        ];
    }

    /**
     * Per-tenant activity volume in window — windowed scan of tenantAudit.
     * @return array<schoolId => int>
     */
    private $_activity_cache = null;
    public function get_per_tenant_activity_volume(int $daysWindow): array
    {
        $cacheKey = 'days_' . $daysWindow;
        if (is_array($this->_activity_cache) && isset($this->_activity_cache[$cacheKey])) {
            return $this->_activity_cache[$cacheKey];
        }
        if (!$this->ready) return [];
        $cut = date('c', time() - ($daysWindow * 86400));
        $rows = $this->firebase->firestoreQuery('tenantAudit', [['ts', '>=', $cut]]);
        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
                $sid = (string) ($d['schoolId'] ?? '');
                if ($sid === '') continue;
                $out[$sid] = ($out[$sid] ?? 0) + 1;
            }
        }
        if (!is_array($this->_activity_cache)) $this->_activity_cache = [];
        $this->_activity_cache[$cacheKey] = $out;
        return $out;
    }

    /**
     * Per-tenant growth deltas: latest rollup vs N months ago.
     * Tenant absent from earlier rollup = 0 baseline (genuine net-new).
     * @return array<schoolId => ['student_delta' => int, 'staff_delta' => int]>
     */
    public function get_per_tenant_growth_deltas(int $monthsTrend): array
    {
        if (!$this->ready) return [];
        $current = date('Y-m');
        $past = date('Y-m', strtotime("first day of -{$monthsTrend} months"));
        $curDoc = $this->firebase->firestoreGet('analyticsRollups', $current);
        $oldDoc = $this->firebase->firestoreGet('analyticsRollups', $past);
        $curMap = $this->_tenants_rollup_map($curDoc);
        $oldMap = $this->_tenants_rollup_map($oldDoc);
        $sids = array_unique(array_merge(array_keys($curMap), array_keys($oldMap)));
        $out = [];
        foreach ($sids as $sid) {
            $cur = $curMap[$sid] ?? ['students' => 0, 'staff' => 0];
            $old = $oldMap[$sid] ?? ['students' => 0, 'staff' => 0];
            $out[$sid] = [
                'student_delta' => (int) $cur['students'] - (int) $old['students'],
                'staff_delta'   => (int) $cur['staff']    - (int) $old['staff'],
            ];
        }
        return $out;
    }

    private function _tenants_rollup_map($doc): array
    {
        if (!is_array($doc) || !is_array($doc['tenantsRollup'] ?? null)) return [];
        $out = [];
        foreach ($doc['tenantsRollup'] as $t) {
            if (!is_array($t)) continue;
            $sid = (string) ($t['schoolId'] ?? '');
            if ($sid === '') continue;
            $out[$sid] = ['students' => (int) ($t['totalStudents'] ?? 0),
                          'staff'    => (int) ($t['totalStaff']    ?? 0)];
        }
        return $out;
    }

    /**
     * Per-tenant data-freshness in hours (now - statsCache.lastUpdated).
     * @return array<schoolId => int|null>  null = no timestamp recorded
     */
    public function get_per_tenant_freshness(): array
    {
        if (!$this->ready) return [];
        $out = [];
        foreach ($this->registry()->list_tenants_summary() as $t) {
            $sid = (string) ($t['schoolId'] ?? '');
            $upd = (string) ($t['statsLastUpdated'] ?? '');
            if ($upd === '') { $out[$sid] = null; continue; }
            $ts = strtotime($upd);
            $out[$sid] = $ts > 0 ? (int) floor((time() - $ts) / 3600) : null;
        }
        return $out;
    }

    /**
     * Per-tenant revenue contribution: MRR allocated to each tenant.
     * @return array<schoolId => float>
     */
    public function get_per_tenant_revenue_contribution(): array
    {
        if (!$this->ready) return [];
        $plans = $this->load_plan_price_map();
        $out = [];
        foreach ($this->registry()->list_tenants_summary() as $t) {
            $sid = (string) ($t['schoolId'] ?? '');
            $pfid = (string) ($t['planFamilyId'] ?? '');
            if ($pfid === '' || !isset($plans[$pfid])) { $out[$sid] = 0.0; continue; }
            $price = (float) $plans[$pfid]['price'];
            $cycle = strtolower((string) $plans[$pfid]['billingCycle']);
            $months = self::BILLING_CYCLE_MONTHS[$cycle] ?? 12;
            $out[$sid] = $months > 0 ? $price / $months : 0.0;
        }
        return $out;
    }

    /**
     * Comparative matrix: one row per tenant with all metrics as columns.
     * @return array  per-tenant rows enriched with isTestTenant flag.
     */
    public function get_comparative_matrix(int $daysWindow = 30, int $monthsTrend = 12): array
    {
        if (!$this->ready) return [];
        $tenants = $this->registry()->list_tenants_summary();
        $activity = $this->get_per_tenant_activity_volume($daysWindow);
        $growth   = $this->get_per_tenant_growth_deltas($monthsTrend);
        $fresh    = $this->get_per_tenant_freshness();
        $revenue  = $this->get_per_tenant_revenue_contribution();
        $plans    = $this->load_plan_price_map();
        $out = [];
        foreach ($tenants as $t) {
            $sid = (string) ($t['schoolId'] ?? '');
            $pfid = (string) ($t['planFamilyId'] ?? '');
            $planName = ($pfid !== '' && isset($plans[$pfid])) ? $plans[$pfid]['name'] : '— No Plan';
            $out[] = [
                'schoolId'             => $sid,
                'schoolName'           => (string) ($t['schoolName'] ?? ''),
                'schoolCode'           => (string) ($t['schoolCode'] ?? ''),
                'city'                 => (string) ($t['city'] ?? ''),
                'planName'             => $planName,
                'lifecycleState'       => (string) ($t['lifecycleState'] ?? ''),
                'isTestTenant'         => $this->is_test_tenant($t),
                'activity_volume'      => (int) ($activity[$sid] ?? 0),
                'student_delta'        => (int) ($growth[$sid]['student_delta'] ?? 0),
                'staff_delta'          => (int) ($growth[$sid]['staff_delta']    ?? 0),
                'data_freshness_hours' => $fresh[$sid] ?? null,
                'revenue_contribution' => (float) ($revenue[$sid] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Leaderboard: top/bottom N tenants by chosen metric.
     * Operates on a pre-filtered scope (already excludes test tenants
     * unless includeTest=true at the composite layer). Excludes tenants
     * with null/zero values from "bottom" rankings since they distort
     * the ranking ("no data" is not the same as "lowest activity").
     */
    public function get_engagement_leaderboard(string $metricKey, array $scopedMatrix,
                                                int $limit = 5, string $direction = 'desc'): array
    {
        if (!in_array($direction, ['asc', 'desc'], true)) $direction = 'desc';
        $rows = $scopedMatrix;
        if ($direction === 'asc') {
            // For ascending (bottom rankings) exclude null + zero values
            // to avoid surfacing tenants with no data as "worst performers".
            $rows = array_values(array_filter($rows, function ($r) use ($metricKey) {
                $v = $r[$metricKey] ?? null;
                return $v !== null && $v > 0;
            }));
        }
        usort($rows, function ($a, $b) use ($metricKey, $direction) {
            $va = $a[$metricKey] ?? null; $vb = $b[$metricKey] ?? null;
            if ($va === null) return 1;
            if ($vb === null) return -1;
            $cmp = ($va <=> $vb);
            return $direction === 'desc' ? -$cmp : $cmp;
        });
        return array_slice($rows, 0, $limit);
    }

    /**
     * Engagement distribution: bucket-count histogram of tenants
     * by the chosen metric. Buckets vary per metric.
     */
    public function get_engagement_distribution(string $metricKey, array $scopedMatrix): array
    {
        $buckets = $this->_distribution_buckets_for($metricKey);
        $counts = array_fill(0, count($buckets), 0);
        foreach ($scopedMatrix as $row) {
            $v = $row[$metricKey] ?? null;
            if ($v === null) continue;
            $idx = $this->_bucket_index($v, $buckets);
            $counts[$idx]++;
        }
        $labels = array_map(fn($b) => $b['label'], $buckets);
        return ['labels' => $labels, 'counts' => $counts, 'metric' => $metricKey];
    }

    private function _distribution_buckets_for(string $metric): array
    {
        switch ($metric) {
            case 'student_growth':
            case 'staff_growth':
                return [
                    ['min' => -INF, 'max' => -10, 'label' => '< −10'],
                    ['min' => -9,   'max' => -1,  'label' => '−9 to −1'],
                    ['min' => 0,    'max' => 0,   'label' => '0'],
                    ['min' => 1,    'max' => 9,   'label' => '+1 to +9'],
                    ['min' => 10,   'max' => 49,  'label' => '+10 to +49'],
                    ['min' => 50,   'max' => INF, 'label' => '+50+'],
                ];
            case 'data_freshness_hours':
                return [
                    ['min' => 0,   'max' => 1,    'label' => '0–1h'],
                    ['min' => 2,   'max' => 6,    'label' => '2–6h'],
                    ['min' => 7,   'max' => 24,   'label' => '7–24h'],
                    ['min' => 25,  'max' => 168,  'label' => '1–7d'],
                    ['min' => 169, 'max' => INF,  'label' => '>7d'],
                ];
            case 'revenue_contribution':
                return [
                    ['min' => 0,    'max' => 0,    'label' => '0'],
                    ['min' => 1,    'max' => 999,  'label' => '< ₹1K'],
                    ['min' => 1000, 'max' => 4999, 'label' => '₹1K–5K'],
                    ['min' => 5000, 'max' => 9999, 'label' => '₹5K–10K'],
                    ['min' => 10000,'max' => INF,  'label' => '>₹10K'],
                ];
            case 'activity_volume':
            default:
                return [
                    ['min' => 0,    'max' => 0,    'label' => '0'],
                    ['min' => 1,    'max' => 10,   'label' => '1–10'],
                    ['min' => 11,   'max' => 50,   'label' => '11–50'],
                    ['min' => 51,   'max' => 100,  'label' => '51–100'],
                    ['min' => 101,  'max' => 500,  'label' => '101–500'],
                    ['min' => 501,  'max' => INF,  'label' => '500+'],
                ];
        }
    }

    private function _bucket_index($value, array $buckets): int
    {
        $value = is_numeric($value) ? (float) $value : 0;
        foreach ($buckets as $i => $b) {
            $min = $b['min']; $max = $b['max'];
            if ($value >= $min && $value <= $max) return $i;
        }
        return count($buckets) - 1;
    }

    // ──────────────────────────────────────────────────────────────
    // PHASE 1G — PER-TENANT DEEP DIVE SPOKE
    //
    // Inverted view of Phase 1F: one tenant × all metrics × time axis
    // (vs Phase 1F's all tenants × all metrics × current snapshot).
    //
    // STRICT ISOLATION CONTRACT: every per-tenant query filters at the
    // Firestore layer by `schoolId == $schoolId`, not in PHP. Defense-
    // in-depth against cross-tenant leakage. Probes 35-37 verify this.
    //
    // Read sources (all per-tenant slices, no fleet data):
    //   schools/{schoolId}, schoolControl/{schoolId},
    //   subscriptions/{subId via pointer}, plans/{planId},
    //   payments filtered by schoolId, tenantAudit filtered by schoolId,
    //   analyticsRollups.tenantsRollup[] historical lookup,
    //   analyticsAlerts filtered by affectedTenants contains schoolId.
    //
    // Phase 3 reuse: same accessors with rules-enforced scope=own school.
    // ──────────────────────────────────────────────────────────────

    const TENANT_DETAIL_DEFAULT_DAYS  = 30;
    const TENANT_DETAIL_DEFAULT_TREND = 12;
    const TENANT_DETAIL_TIMELINE_CAP  = 50;
    const TENANT_DETAIL_PAYMENTS_CAP  = 10;

    /**
     * Composite per-tenant deep dive payload.
     *
     * @param string $schoolId    canonical SCH_xxx id (controller pre-validated)
     * @param int    $daysWindow  activity window (7|30|90)
     * @param int    $monthsTrend time-series window (3|6|12)
     *
     * Returns ['_error' => 'tenant_not_found'] when schoolId resolves to
     * no `schools/{id}` doc.  Otherwise returns full 11-key composite.
     */
    public function get_tenant_detail_bundle(string $schoolId, int $daysWindow = 30, int $monthsTrend = 12): array
    {
        $daysWindow  = in_array($daysWindow,  [7, 30, 90], true) ? $daysWindow  : self::TENANT_DETAIL_DEFAULT_DAYS;
        $monthsTrend = in_array($monthsTrend, [3, 6, 12], true) ? $monthsTrend : self::TENANT_DETAIL_DEFAULT_TREND;

        if (!$this->ready || !preg_match('/^SCH_[A-Z0-9]+$/', $schoolId)) {
            return ['_error' => 'invalid_schoolid', 'schoolId' => $schoolId];
        }

        $identity = $this->get_tenant_identity($schoolId);
        if (empty($identity) || !isset($identity['schoolId'])) {
            return ['_error' => 'tenant_not_found', 'schoolId' => $schoolId];
        }

        return [
            'schoolId'       => $schoolId,
            'daysWindow'     => $daysWindow,
            'monthsTrend'    => $monthsTrend,
            'identity'       => $identity,
            'kpis'           => $this->get_tenant_kpis($schoolId, $daysWindow),
            'time_series'    => $this->get_tenant_time_series($schoolId, $monthsTrend),
            'subscription'   => $this->get_tenant_subscription_info($schoolId),
            'payments'       => $this->get_tenant_payment_history($schoolId, self::TENANT_DETAIL_PAYMENTS_CAP),
            'activity'       => $this->get_tenant_activity_timeline($schoolId, $daysWindow, self::TENANT_DETAIL_TIMELINE_CAP),
            'stats_health'   => $this->get_tenant_stats_health($schoolId),
            'alerts'         => $this->get_tenant_alerts($schoolId),
            'generated_at'   => date('c'),
        ];
    }

    /**
     * Identity card payload — name, code, plan, lifecycle, dates, SSA.
     * Returns empty array when the schools/{id} doc is missing.
     *
     * 2026-06-02 DEFECT FIX: adminDisabled resolution hardened.
     * Pre-fix used `(bool) ($school['adminDisabled'] ?? $ctrl['adminDisabled'] ?? false)`
     * which evaluated a non-empty Array (pre-existing schema drift on some
     * schoolControl docs — see Phase 1H schema-cleanup backlog) as TRUE,
     * producing a phantom "DISABLED" badge on tenants the H1 rule clears
     * as active. Post-fix:
     *   1. Reads tenantPublic/{id}.adminDisabled FIRST — this is the
     *      canonical H1.5 mirror (operator design lock); same source the
     *      runtime H1 verifier consumes.
     *   2. Falls back to schoolControl then schools when mirror missing.
     *   3. STRICT === true comparison — non-bool values (Array, string,
     *      int) are treated as not-disabled, eliminating the schema-drift
     *      attack surface.
     */
    public function get_tenant_identity(string $schoolId): array
    {
        if (!$this->ready) return [];
        $school = $this->firebase->firestoreGet('schools', $schoolId);
        if (!is_array($school) || empty($school)) return [];
        $ctrl = $this->firebase->firestoreGet('schoolControl', $schoolId);
        $ctrl = is_array($ctrl) ? $ctrl : [];
        $public = $this->firebase->firestoreGet('tenantPublic', $schoolId);
        $public = is_array($public) ? $public : [];
        // H1.5 canonical priority order. Strict === true bool check.
        $adminDisabled = false;
        foreach ([$public, $ctrl, $school] as $src) {
            if (array_key_exists('adminDisabled', $src) && $src['adminDisabled'] === true) {
                $adminDisabled = true;
                break;
            }
        }
        $sub  = is_array($ctrl['subscription'] ?? null) ? $ctrl['subscription'] : [];
        $life = is_array($ctrl['lifecycle']    ?? null) ? $ctrl['lifecycle']    : [];
        $planMap = $this->load_plan_price_map();
        $pfid = (string) ($sub['planId'] ?? '');
        $planName = ($pfid !== '' && isset($planMap[$pfid])) ? (string) $planMap[$pfid]['name'] : '— No Plan';
        $schoolName = (string) ($school['schoolName'] ?? $school['name'] ?? '');
        $row = [
            'schoolId'         => $schoolId,
            'schoolName'       => $schoolName,
            'schoolCode'       => (string) ($school['schoolCode']       ?? ''),
            'domainIdentifier' => (string) ($school['domainIdentifier'] ?? ''),
            'city'             => (string) ($school['city']             ?? ''),
            'region'           => (string) ($school['state']            ?? $school['region'] ?? ''),
            'createdAt'        => (string) ($school['createdAt']        ?? ''),
            'primarySsaId'     => (string) ($school['primarySsaId']     ?? ''),
            'logoUrl'          => (string) ($school['logoUrl']          ?? ''),
            'planFamilyId'     => $pfid,
            'planName'         => $planName,
            'lifecycleState'   => (string) ($life['state']              ?? ''),
            'adminDisabled'    => $adminDisabled,
            'subscriptionId'   => (string) ($sub['subscriptionId']      ?? ''),
        ];
        $row['isTestTenant'] = $this->is_test_tenant($row);
        return $row;
    }

    /** 5 KPI tile values for the tenant. */
    public function get_tenant_kpis(string $schoolId, int $daysWindow): array
    {
        if (!$this->ready) return [];
        $school = $this->firebase->firestoreGet('schools', $schoolId);
        $cache = is_array($school['statsCache'] ?? null) ? $school['statsCache']
                : (is_array($school['stats'] ?? null) ? $school['stats'] : []);
        $totalStudents = (int) ($cache['totalStudents'] ?? $cache['total_students'] ?? 0);
        $totalStaff    = (int) ($cache['totalStaff']    ?? $cache['total_staff']    ?? 0);
        $lastUpd = (string) ($cache['lastUpdated'] ?? $cache['last_updated'] ?? '');
        $dataAgeHours = null;
        if ($lastUpd !== '') {
            $ts = strtotime($lastUpd);
            if ($ts > 0) $dataAgeHours = (int) floor((time() - $ts) / 3600);
        }
        // MRR via per-tenant revenue allocation (reuse Phase 1F accessor)
        $revenue = $this->get_per_tenant_revenue_contribution();
        $mrr = (float) ($revenue[$schoolId] ?? 0);
        // Activity = audit count for this tenant in window
        $activityCount = $this->_count_tenant_audit_in_window($schoolId, $daysWindow);
        return [
            'students'        => $totalStudents,
            'staff'           => $totalStaff,
            'mrr'             => $mrr,
            'activity_count'  => $activityCount,
            'window_days'     => $daysWindow,
            'data_age_hours'  => $dataAgeHours,
        ];
    }

    /**
     * Time-series for this tenant: students + staff + audit events per month
     * across the last N months. Tenant absent from a rollup = 0 baseline.
     */
    public function get_tenant_time_series(string $schoolId, int $months = 12): array
    {
        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $periodKey = date('Y-m', strtotime("first day of -{$i} months"));
            $doc = $this->ready ? $this->firebase->firestoreGet('analyticsRollups', $periodKey) : null;
            $tenantRow = null;
            if (is_array($doc) && is_array($doc['tenantsRollup'] ?? null)) {
                foreach ($doc['tenantsRollup'] as $r) {
                    if (is_array($r) && (string) ($r['schoolId'] ?? '') === $schoolId) {
                        $tenantRow = $r; break;
                    }
                }
            }
            // Audit events for this tenant in this month
            $monthStart = strtotime($periodKey . '-01');
            $monthEnd   = strtotime('last day of ' . $periodKey) + 86399;
            $auditCount = $this->_count_tenant_audit_in_range($schoolId, $monthStart, $monthEnd);
            $out[] = [
                'period'        => $periodKey,
                'totalStudents' => $tenantRow !== null ? (int) ($tenantRow['totalStudents'] ?? 0) : 0,
                'totalStaff'    => $tenantRow !== null ? (int) ($tenantRow['totalStaff']    ?? 0) : 0,
                'auditCount'    => $auditCount,
            ];
        }
        return $out;
    }

    /** Resolve subscription details for this tenant. */
    public function get_tenant_subscription_info(string $schoolId): array
    {
        if (!$this->ready) return [];
        $ctrl = $this->firebase->firestoreGet('schoolControl', $schoolId);
        $sub  = is_array($ctrl['subscription'] ?? null) ? $ctrl['subscription'] : [];
        $subId = (string) ($sub['subscriptionId'] ?? '');
        $out = [
            'subscriptionId' => $subId,
            'status'         => (string) ($sub['status']  ?? ''),
            'planId'         => (string) ($sub['planId']  ?? ''),
            'planName'       => '',
            'price'          => 0.0,
            'billingCycle'   => '',
            'periodStart'    => '',
            'periodEnd'      => '',
            'graceEnd'       => '',
        ];
        if ($subId !== '') {
            try {
                $subDoc = $this->firebase->firestoreGet('subscriptions', $subId);
                if (is_array($subDoc)) {
                    $out['periodStart'] = (string) ($subDoc['periodStart'] ?? '');
                    $out['periodEnd']   = (string) ($subDoc['periodEnd']   ?? '');
                    $out['graceEnd']    = (string) ($subDoc['graceEnd']    ?? '');
                    if (empty($out['status'])) $out['status'] = (string) ($subDoc['status'] ?? '');
                }
            } catch (\Throwable $e) { /* defensive */ }
        }
        $planMap = $this->load_plan_price_map();
        if ($out['planId'] !== '' && isset($planMap[$out['planId']])) {
            $out['planName']     = (string) $planMap[$out['planId']]['name'];
            $out['price']        = (float)  $planMap[$out['planId']]['price'];
            $out['billingCycle'] = (string) $planMap[$out['planId']]['billingCycle'];
        }
        return $out;
    }

    /**
     * Payment history for this tenant, newest first, capped at $limit.
     * STRICT isolation: queries `payments` with `schoolId == $schoolId` filter
     * at Firestore layer.
     */
    public function get_tenant_payment_history(string $schoolId, int $limit = 10): array
    {
        if (!$this->ready) return [];
        // Reuse registry helper which uses Firestore-layer filter + handles
        // legacy school_uid fallback. Sort already DESC by createdAt.
        $rows = $this->registry()->list_payments_for_school($schoolId);
        if (!is_array($rows)) return [];
        $out = [];
        $sumLifetime = 0.0;
        foreach ($rows as $r) {
            $sumLifetime += (float) ($r['amount'] ?? $r['totalAmount'] ?? 0);
            if (count($out) >= $limit) continue;
            $out[] = [
                'paidAt'    => (string) ($r['paidAt']     ?? $r['createdAt'] ?? ''),
                'amount'    => (float)  ($r['amount']     ?? $r['totalAmount'] ?? 0),
                'method'    => (string) ($r['method']     ?? $r['paymentMethod'] ?? '—'),
                'reference' => (string) ($r['reference']  ?? $r['transactionId'] ?? ''),
                'planName'  => (string) ($r['planName']   ?? ''),
            ];
        }
        return ['rows' => $out, 'lifetime_total' => $sumLifetime, 'total_count' => count($rows)];
    }

    /**
     * Activity timeline (audit feed) for this tenant in the last N days,
     * capped at $limit. STRICT isolation via Firestore-layer schoolId filter.
     */
    public function get_tenant_activity_timeline(string $schoolId, int $daysWindow = 30, int $limit = 50): array
    {
        if (!$this->ready) return [];
        $cut = date('c', time() - $daysWindow * 86400);
        try {
            $rows = $this->firebase->firestoreQuery('tenantAudit', [
                ['schoolId', '==', $schoolId],
                ['ts', '>=', $cut],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'B2_analytics_service::get_tenant_activity_timeline failed: ' . $e->getMessage());
            return [];
        }
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $row) {
            $d = is_array($row['data'] ?? null) ? $row['data'] : (is_array($row) ? $row : []);
            $out[] = [
                'ts'      => (string) ($d['ts']      ?? ''),
                'actor'   => (string) ($d['actor']   ?? $d['actorId'] ?? ''),
                'action'  => (string) ($d['action']  ?? ''),
                'target'  => (string) ($d['target']  ?? ''),
                'notes'   => (string) ($d['notes']   ?? ''),
            ];
        }
        usort($out, fn($a, $b) => strcmp((string) $b['ts'], (string) $a['ts']));
        return array_slice($out, 0, $limit);
    }

    /** Stats freshness details for this tenant. */
    public function get_tenant_stats_health(string $schoolId): array
    {
        if (!$this->ready) return [];
        $school = $this->firebase->firestoreGet('schools', $schoolId);
        if (!is_array($school)) return [];
        $cache = is_array($school['statsCache'] ?? null) ? $school['statsCache']
                : (is_array($school['stats'] ?? null) ? $school['stats'] : []);
        $upd = (string) ($cache['lastUpdated'] ?? $cache['last_updated'] ?? '');
        $ts = $upd !== '' ? strtotime($upd) : 0;
        $hours = $ts > 0 ? (int) floor((time() - $ts) / 3600) : null;
        return [
            'lastUpdated'  => $upd,
            'hoursAgo'     => $hours,
            'source'       => 'statsCache',
            'students'     => (int) ($cache['totalStudents'] ?? $cache['total_students'] ?? 0),
            'staff'        => (int) ($cache['totalStaff']    ?? $cache['total_staff']    ?? 0),
            'isStale'      => $hours !== null && $hours > 168, // >7 days
        ];
    }

    /**
     * Open alerts that target this tenant.
     * STRICT isolation via Firestore array-contains query.
     */
    public function get_tenant_alerts(string $schoolId): array
    {
        if (!$this->ready) return [];
        try {
            $rows = $this->firebase->firestoreQuery('analyticsAlerts', [
                ['state', '==', 'open'],
                ['affectedTenants', 'array-contains', $schoolId],
            ]);
        } catch (\Throwable $e) {
            // Fallback: some Firestore wrappers may not support array-contains;
            // pull open alerts and filter in PHP as defensive safety net.
            try {
                $rows = $this->firebase->firestoreQuery('analyticsAlerts', [['state', '==', 'open']]);
                if (is_array($rows)) {
                    $rows = array_filter($rows, function ($r) use ($schoolId) {
                        $d = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
                        $tenants = is_array($d['affectedTenants'] ?? null) ? $d['affectedTenants'] : [];
                        return in_array($schoolId, $tenants, true);
                    });
                }
            } catch (\Throwable $e2) {
                return [];
            }
        }
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $row) {
            $d = is_array($row['data'] ?? null) ? $row['data'] : (is_array($row) ? $row : []);
            $out[] = [
                'alertId'    => (string) ($row['id']         ?? $d['alertId'] ?? ''),
                'alertType'  => (string) ($d['alertType']    ?? ''),
                'severity'   => (string) ($d['severity']     ?? 'info'),
                'createdAt'  => (string) ($d['createdAt']    ?? ''),
                'triggerData' => is_array($d['triggerData'] ?? null) ? $d['triggerData'] : [],
            ];
        }
        return $out;
    }

    // ── Phase 1G internals ──────────────────────────────────────────

    /**
     * Count tenantAudit rows for $schoolId in last $daysWindow days.
     * STRICT Firestore-layer filter (no PHP filter pass).
     */
    private function _count_tenant_audit_in_window(string $schoolId, int $daysWindow): int
    {
        if (!$this->ready) return 0;
        $cut = date('c', time() - max(1, $daysWindow) * 86400);
        try {
            $rows = $this->firebase->firestoreQuery('tenantAudit', [
                ['schoolId', '==', $schoolId],
                ['ts', '>=', $cut],
            ]);
            return is_array($rows) ? count($rows) : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Count tenantAudit rows for $schoolId between two epoch timestamps.
     */
    private function _count_tenant_audit_in_range(string $schoolId, int $startTs, int $endTs): int
    {
        if (!$this->ready) return 0;
        if ($startTs <= 0 || $endTs <= 0) return 0;
        $from = date('c', $startTs);
        $to   = date('c', $endTs);
        try {
            $rows = $this->firebase->firestoreQuery('tenantAudit', [
                ['schoolId', '==', $schoolId],
                ['ts', '>=', $from],
                ['ts', '<=', $to],
            ]);
            return is_array($rows) ? count($rows) : 0;
        } catch (\Throwable $e) {
            return 0;
        }
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
