# ZenX SuperAdmin — Latency Verification Package

**Date:** 2026-06-04
**Repository:** `C:\xampp\htdocs\ZenX\school`
**Constraints honored:** No code changes · no commits · no refactors · no behavior changes. This is verification only.
**Companion:** `SUPERADMIN_LATENCY_AUDIT.md` (the original audit).

---

## 0. Measurement methodology & honesty statement

You asked for **measured counts, not modeled estimates**. There are exactly two ways to obtain true *runtime* counts, and **both violate your stated constraints**:

1. **Instrumenting** the Firestore client (`Firestore_rest_client::request`) to emit per-call counters — a **code change** (prohibited).
2. **Live-executing** the SA pages against the real backend — produces **real reads against production data** plus token-refresh and audit-row **side effects** (a behavior change against prod).

I verified there is **no existing per-call read counter** to harvest: `Firestore_rest_client.php` logs only token-cache `HIT/MISS` (lines 250/287, `debug` level) and per-call *errors* — never a success counter (confirmed by grep of the file).

Therefore this package delivers **`STATIC-EXACT` accounting**: read counts obtained by tracing every `firestoreGet` / `firestoreQuery` call site through the actual code paths. These are **deterministic code facts** parameterized by data volume — not latency models. Every number below is tagged:

- **`STATIC-EXACT`** — counted from source; exact given the inputs (N, P, A). HIGH confidence.
- **`MODELED`** — a millisecond figure derived from `reads × assumed-per-call`. MEDIUM confidence; needs APM.

**Notation:** `N` = tenant/school docs · `P` = plan docs · `A` = admin docs in the current school · `LTS` = `B2_registry_service::list_tenants_summary()`.

A one-call cost reference, established below and reused throughout:
- **1× `LTS`** = `3 + Nsub` reads, where `Nsub` = schools that carry a `subscription.subscriptionId` pointer (`Nsub ≤ N`; effectively `≈ N` in a populated tenant base). `STATIC-EXACT` from `B2_registry_service.php:518,519,526` (3 collection queries) + `:563–571` (per-school subscription `firestoreGet` in the loop).
- **1× `get_tenant_detail()`** = `3` reads (`schools` + `schoolControl` + `subscriptions`). `STATIC-EXACT` from `B2_registry_service.php:635,636,643`.

---

## 1. Request Call-Graph Verification (Part A)

Execution paths traced Controller → Service → Firestore → Cache → View, with line numbers.

### 1.1 SuperAdmin Dashboard
**Route:** `GET /superadmin` → `Superadmin::index()` (`Superadmin.php`, view rendered at `:115–117`)

```
Superadmin::index()                                  Superadmin.php:~30–118
├─ $this->firebase->get('System/Logs/Activity/{today}')   :72        [RTDB read ×1]
├─ (expiry summary section above :60)                                  [RTDB/summary]
├─ load->library('b2_analytics_service'); ->init()        :95–96
└─ B2_analytics_service::get_hub_payload(sa_id)           :97  → B2_analytics_service.php:598
     ├─ get_kpi_totals()              :136 → LTS @141                  [LTS #1]
     ├─ get_time_series_schools_growth(12) :562 → _load_monthly_rollups_series :576   [≤12 rollup gets]
     ├─ get_time_series_revenue(12)   :571 → _load_monthly_rollups_series :576        [≤12 rollup gets]
     ├─ list_high_signal_activity(8)  :300 → list_recent_activity      [1 query: tenantAudit]
     ├─ get_top_schools_by_students(5):324 → LTS @326                  [LTS #2]
     ├─ get_expiring_soon(30)         :331 → LTS @333                  [LTS #3]
     ├─ list_open_alerts()            :435                              [1 query: analyticsAlerts]
     └─ list_saved_searches(sa_id)    :519                              [1 query/get]
Cache layer: NONE on this path (Dashboard_cache not used by SA hub — see §5)
View: superadmin/include/sa_header → superadmin/dashboard → sa_footer   :115–117
```
**LTS invocations: 3** (`STATIC-EXACT`).

### 1.2 Manage Schools
**Route:** `GET /superadmin/schools` → `Superadmin_schools::index()` (`Superadmin_schools.php:30`)

```
Superadmin_schools::index()                          Superadmin_schools.php:30
├─ _b23c_registry_firestore_on()                     :35   [config flag]
├─ svc->list_tenants_summary()                       :37   → LTS  [LTS #1 = 3 + Nsub reads]
├─ foreach svc->list_plans()                         :39   [1 query: plans]
└─ foreach ($tenants as $t)  ── N iterations ──      :43
     └─ svc->get_tenant_detail($sid)                 :47   → 3 reads PER ITERATION  [3N reads]
Cache layer: NONE
View: superadmin/schools/index                       :73
```
**Reads: `(3 + Nsub) + 1 + 3N` = `4 + 4N`** (taking `Nsub ≈ N`). `STATIC-EXACT`.

### 1.3 Plans
**Route:** `GET /superadmin/plans` → `Superadmin_plans::index()` (`Superadmin_plans.php:52`)

```
Superadmin_plans::index()                            Superadmin_plans.php:52
├─ svc->list_plans()                                 :59   [1 query: plans]
└─ foreach plans ── P iterations ──                  :60
     └─ svc->count_schools_on_plan(plan_id)          :62   → firestoreQuery('schoolControl',[where planId])
                                                            B2_registry_service.php:489  [1 query PER PLAN]
Cache layer: NONE
View: superadmin/plans/index                         :94
```
**Reads/queries: `1 + P`.** `STATIC-EXACT`. (Note: each `count_schools_on_plan` is a *filtered* `where` query — 1 round-trip each — not a full scan; the N+1 is over plans.)

### 1.4 Admin Users
**Route:** `POST /admin_users/get_admins` → `AdminUsers::get_admins()` (`AdminUsers.php:403`); dashboard tile `:~358`

```
AdminUsers::get_admins()                             AdminUsers.php:403
├─ _require_role(...)                                 :405
├─ fs->schoolWhere('admins', [], 'Name', 'ASC')      :408   [1 query: all admins in school = A docs]
└─ foreach -> _normalize_admin()                      :410–415   [in-PHP shaping]
AdminUsers::get_dashboard()                           :~358
└─ fs->schoolWhere('admins', [])                      :358   [1 query: A docs, counted in PHP]
create_admin / update_admin email-exists check        :470 / :612 [+1 full admins scan each]
delete_role role-usage check                           :1123      [+1 full admins scan]
View: rendered client-side from JSON
```
**Listing reads: `1` query (A docs).** Mutation paths add `+1` full `admins` scan each. `STATIC-EXACT`. Bounded by `A` (admins-per-school, small) — **does not scale with N**.

### 1.5 Statistics
**Route:** `GET /superadmin/dashboard/statistics` → `Superadmin_analytics::statistics()` → `get_statistics_payload(12)` (`B2_analytics_service.php:664`)

```
get_statistics_payload(12)                           B2_analytics_service.php:664
├─ _load_monthly_rollups_series ×3 (onboard/students/staff) :576  [rollup gets, 12-window]
├─ get_lifecycle_distribution()  :266 → LTS @268                   [LTS #1]
├─ get_plan_distribution()       :279 → LTS @281                   [LTS #2]
├─ get_schools_by_city()         :687 → LTS @691                   [LTS #3]
├─ get_average_tenant_age_days() :704 → firestoreQuery('schools',[]) @707   [1 FULL schools scan]
├─ count_new_schools_in_window(30):727 → firestoreQuery('schools',[]) @730  [1 FULL schools scan]
├─ count_new_schools_in_window(90):727 → firestoreQuery('schools',[]) @730  [1 FULL schools scan]
└─ get_recent_registrations(30,10):748 → firestoreQuery('schools',[]) @751  [1 FULL schools scan]
Cache layer: NONE (live recompute; analyticsRollups read only for the 3 time-series)
```
**LTS invocations: 3** + **4 standalone full `schools` scans** + rollup gets. `STATIC-EXACT`.

---

## 2. Firestore Request Accounting (Part B)

### 2.1 Per-page read/write/query counts — `STATIC-EXACT`

| Page | Firestore reads (parameterized) | Writes | Queries (subset of reads) | Token refresh | Cache hit/miss |
|------|--------------------------------|--------|---------------------------|---------------|----------------|
| **Dashboard** | `3×(3+N)` + ≤24 rollup gets + 3 queries = **`33 + 3N`** (+1 RTDB) | 0¹ | 3 collection-queries × 3 (LTS) + 3 = 12 | 0 (warm) / 1 (cold-hourly) | **No SA cache** → always MISS |
| **Manage Schools** | **`4 + 4N`** | 0 | 1 (LTS schools) ×… + 1 plans per LTS pass | 0/1 | No cache → MISS |
| **Plans** | **`1 + P`** | 0 | `1 + P` | 0/1 | No cache → MISS |
| **Admin Users** (list) | **`1`** (A docs) | 0 | 1 | 0/1 | No cache → MISS |
| **Statistics** | `3×(3+N)` + 4 full-scans + rollups = **`~13 + 3N + rollups`** | 0 | ≥ 7 | 0/1 | No cache → MISS |

¹ Read-only page loads perform no Firestore writes. (Mutations like status-flip, plan-create, alert-dismiss are separate POST actions and *do* write — out of scope for page-load latency.)

**Token refresh accounting (`STATIC-EXACT` on mechanism):** the OAuth token is cached cross-request (APCu→file) with a 60s pre-expiry buffer and stampede lock (`Firestore_rest_client.php` token path). Steady state = **0 refreshes/request**; exactly **1** blocking refresh (`MODELED` 1–3s) on the first request after hourly expiry, shared across the process pool.

**Cache hit/miss accounting (`STATIC-EXACT`):** the SA hub/schools/plans/stats paths reference **no** `Dashboard_cache` call and **no** cross-request analytics cache. Every load is a cold MISS that recomputes from Firestore. The only cross-request caches that *do* fire are the OAuth token and (for time-series only) the `analyticsRollups` docs. Confirmed by grep: zero `Dashboard_cache` usage in `Superadmin*.php`.

### 2.2 `MODELED` latency (for scale intuition only — needs APM to confirm)

At `~150ms`/serial read: Dashboard `33+3N` → ~5s @ N=10, ~50s @ N=100. Manage Schools `4+4N` → ~6.6s @ N=10, ~60s @ N=100. (Tagged `MODELED`; see audit §6 for the full scale grid.)

---

## 3. P0-1 Validation — `list_tenants_summary()` (Part C)

### 3.1 Is it un-cached? — **YES, confirmed**
`B2_registry_service.php:509–624`. The method body's only guard is `if (!$this->ready) return [];` (`:511`). There is **no memo field, no `$this->cache` check, no early return of a prior result**. Every call re-executes all 3 collection queries + the N+1 subscription loop. `STATIC-EXACT`, HIGH confidence.

### 3.2 Complete caller inventory (grep-verified, production paths only)

**Direct controller callers (page entry points):**
| Caller | File:Line | Page |
|--------|-----------|------|
| `Superadmin_schools::index` | `Superadmin_schools.php:37` | Manage Schools |
| `Superadmin.php` | `:485`, `:542` | SA helper endpoints (status/refresh) |
| `Superadmin_reports.php` | `:86`, `:156`, `:371` | Reports surfaces |
| `Superadmin_plans.php` | `:492`, `:628`, `:883`, `:950` | Plan detail/assignment surfaces |
| `B2_analytics_rollup_job.php` | `:150` | Nightly cron (acceptable — batch context) |

**Service-layer callers inside `B2_analytics_service.php` (22 call sites), attributed to enclosing function:**
| Line | Enclosing function | Reached by page |
|------|--------------------|-----------------|
| 141 | `get_kpi_totals()` | Dashboard |
| 268 | `get_lifecycle_distribution()` | Statistics |
| 281 | `get_plan_distribution()` | Statistics |
| 326 | `get_top_schools_by_students()` | Dashboard |
| 333 | `get_expiring_soon()` | Dashboard |
| 405 | `generate_alerts()` | alerts regenerate |
| 629 | `quick_search()` | quick-search AJAX |
| 691 | `get_schools_by_city()` | Statistics |
| 790/803/817 | `drilldown_schools_by_{lifecycle,plan,city}()` | drill-down AJAX |
| 991 | `_load_enriched_tenants()` | School search (already memo'd downstream) |
| 1242/1244 | `get_revenue_overview()` | Revenue |
| 1323 | `get_outstanding_receivables()` | Revenue |
| 1402 | `get_recent_payments()` | Revenue |
| 1431 | `get_at_risk_tenants()` | Revenue / hub |
| 1476 | `get_lost_mrr_by_state()` | Revenue |
| 1613 | `get_fleet_kpi_snapshot()` | Cross-school |
| 1737 | `get_per_tenant_freshness()` | Cross-school |
| 1756 | `get_per_tenant_revenue_contribution()` | Cross-school |
| 1775 | `get_comparative_matrix()` | Cross-school |

### 3.3 Duplicate invocations within a single request — **confirmed**
| Page | Distinct LTS calls/request | Functions |
|------|----------------------------|-----------|
| **Dashboard** | **3** | get_kpi_totals, get_top_schools_by_students, get_expiring_soon |
| **Statistics** | **3** | get_lifecycle_distribution, get_plan_distribution, get_schools_by_city |
| **Cross-school** | **4** | get_fleet_kpi_snapshot, get_comparative_matrix, get_per_tenant_freshness, get_per_tenant_revenue_contribution |
| **Revenue** | **≥2** (get_revenue_overview alone) up to 6 if page renders at-risk/outstanding/recent/lost-mrr | see table |
| **Manage Schools** | 1 (but compounded by the separate `get_tenant_detail` N+1 — see P0-2) | direct |

`STATIC-EXACT`, HIGH confidence.

### 3.4 Estimated savings from request-level memoization
Memoization collapses `K` identical calls into `1`, saving `(K−1) × (3 + N)` reads:

| Page | Reads now (LTS portion) | After memo | Saved | `STATIC-EXACT` |
|------|------------------------|-----------|-------|----------------|
| Dashboard | `3×(3+N)` = `9+3N` | `3+N` | **`6 + 2N`** | ✅ |
| Statistics | `9+3N` | `3+N` | **`6 + 2N`** | ✅ |
| Cross-school | `12+4N` | `3+N` | **`9 + 3N`** | ✅ |
| Revenue | `≥6+2N` | `3+N` | **`≥3 + N`** | ✅ |

At **N=100**: Dashboard saves ~206 reads, Statistics ~206, Cross-school ~309. `MODELED` ≈ 30–46s/page removed at 150ms/read.

**Important scope note (honesty):** memoizing LTS does **not** fix Statistics' 4 standalone full `schools` scans (`get_average_tenant_age_days`, `count_new_schools_in_window` ×2, `get_recent_registrations` at `:707/:730/:751`) — those bypass LTS. So P0-1 cuts the LTS-attributable reads (the majority) but a follow-up is needed for those 4 scans. This was implicit in the audit; stated explicitly here.

### 3.5 Exact call tree (Dashboard, the canonical case)
```
get_hub_payload()                       B2_analytics_service.php:598
├─ get_kpi_totals()                     :136
│   └─ registry()->list_tenants_summary()        @141  →  3 + Nsub reads   ← call #1
├─ get_top_schools_by_students(5)       :324
│   └─ registry()->list_tenants_summary()        @326  →  3 + Nsub reads   ← call #2  (IDENTICAL DATA)
└─ get_expiring_soon(30)                :331
    └─ registry()->list_tenants_summary()        @333  →  3 + Nsub reads   ← call #3  (IDENTICAL DATA)
TOTAL today: 9 + 3·Nsub   │   With memo: 3 + Nsub   │   Saved: 6 + 2·Nsub
```

---

## 4. P0-2 Validation — `Superadmin_schools::index()` (Part D)

### 4.1 Fields actually rendered by the view
`application/views/superadmin/schools/index.php` consumes exactly (grep-verified, lines noted):
`status` (:50,:76) · `name` (:53) · `uid` (:54,:82,:86,:91,:96) · `city` (:56) · `domain_id` (:57) · `plan_name` (:58) · `expiry_date` (:61) · `students` (:71).

(The controller also sets `logo_url`, `firebase_key`, `created_at`, `staff`, `sub_status` at `:54–68`, but the current view does **not** render them — confirmed by grep. They are dead outputs.)

### 4.2 Field-source proof — current vs. summary

| Output field | Current source (in loop) | In `LTS` summary row? | Summary source |
|--------------|--------------------------|------------------------|----------------|
| `uid` | `$t['schoolId']` | ✅ | `:600` schoolId |
| `name` | `$t['schoolName']` | ✅ | `:601` schoolName |
| `status` | computed from `get_tenant_detail` → `schDoc['adminDisabled']['value']` | ✅ (equivalent/better) | `:607` `adminDisabled` bool (tenantPublic-first, schools fallback) |
| `city` | `get_tenant_detail` → `schDoc['city']` | ✅ | `:611` city |
| `plan_name` | `$planById[$pfid]` (from `list_plans`, **not** detail) | ✅ (no detail needed) | via `list_plans` lookup |
| `expiry_date` | `$t['subscriptionPeriodEnd']` | ✅ | `:608` subscriptionPeriodEnd |
| `students` | `get_tenant_detail` → `statsCache['totalStudents']` | ✅ | `:612` totalStudents |
| **`domain_id`** | `get_tenant_detail` → `schDoc['domainIdentifier']` | ❌ **MISSING** | — |
| `created_at` (set, not rendered) | `get_tenant_detail` → `schDoc['createdAt']` | ❌ MISSING | — |
| `logo_url`/`staff`/`sub_status` (set, not rendered) | detail / `$t` | partial | — |

### 4.3 Verdict on removability — **the loop is removable**
- **Every field the view renders is already in the `LTS` summary row, except `domain_id`.** `STATIC-EXACT`.
- `domain_id` ← `schools/{id}.domainIdentifier`. **`LTS` already fetches the full `schools` doc** in bulk at `B2_registry_service.php:518` (`firestoreQuery('schools', [])`) and has `$s` in hand at the projection (`:599`). Adding `'domainIdentifier' => (string)($s['domainIdentifier'] ?? '')` to the `$out[]` array is a **1-line, ZERO-extra-read** change.
- `created_at` is not rendered; add it too for parity (also zero-read), or omit.
- **Net:** the per-tenant `get_tenant_detail()` loop (`3N` reads) can be deleted with no functional regression, after adding 1 field to the summary projection. Page goes `4 + 4N → 4 + N` reads. `STATIC-EXACT`.

### 4.4 Fields still requiring a detail lookup: **none for the list view**
After adding `domainIdentifier` to the projection, **zero** rendered fields require `get_tenant_detail`. The single-school **detail page** (`Superadmin_schools::view`) still legitimately uses `get_tenant_detail` — that is correct and out of P0-2 scope.

---

## 5. Risk Review (Part E)

### 5.1 P0-1 — memoize `list_tenants_summary()`
| Dimension | Assessment |
|-----------|------------|
| **Regression risk** | **Very low.** LTS is a pure read-aggregation; within one request the underlying collections are not mutated on read paths, so a memo returns byte-identical data. |
| **Edge case — shared-array mutation** | PHP arrays are value types; `$x = $svc->list_tenants_summary(); usort($x,…)` sorts a **copy**, not the memo. Safe **provided the memo is returned by value** (PHP default). ⚠️ Do **not** return by reference. |
| **Edge case — write-then-read same request** | If any single request both flips state (e.g. lifecycle/plan write) **and** re-calls LTS expecting fresh data, a request-scoped memo would serve stale data. Mitigation: SA mutations are AJAX POSTs returning JSON that do **not** re-list in the same request (verify per action before shipping); or expose a `bustTenantSummaryCache()` to call after writes. **Primary item to confirm before authorization.** |
| **Pagination implications** | None. LTS returns the full set today; memo preserves shape. |
| **Cache consistency** | Request-scoped memo lives exactly one request → zero cross-request staleness. (Only the optional P1-3 *cross-request* cache introduces a 30–60s staleness window — that is a separate, later decision.) |
| **Rollback** | Delete the memo field + the early-return guard. Single-file, single-hunk revert. |

### 5.2 P0-2 — remove the per-tenant detail loop
| Dimension | Assessment |
|-----------|------------|
| **Regression risk** | **Low.** Requires adding `domainIdentifier` (and optionally `createdAt`) to the LTS projection — both already-fetched fields, zero extra reads. |
| **Edge case — `status` derivation nuance** | Current path reads `schools.adminDisabled.value` only; summary's `adminDisabled` reads **tenantPublic first, then schools fallback** (`:591–597`). In the rare case the `tenantPublic` mirror diverges from `schools.adminDisabled`, the displayed status could differ. The summary path is the **canonical** one used by login gating, so this is an improvement — but it is technically a behavior change in an edge case. **Flag for sign-off.** |
| **Edge case — missing field** | Schools lacking `domainIdentifier` yield `''` from both old and new paths → no regression. |
| **Edge case — null detail** | Current loop doesn't null-check `get_tenant_detail`; summary already filters non-tenant docs by `^SCH_` regex (`:553`) → equivalent or stricter. |
| **Pagination implications** | None changed (neither path paginates today; cursor pagination is the separate P2-1). |
| **Cache consistency** | N/A (no cache touched). |
| **Rollback** | Revert controller hunk; remove the 1–2 added projection fields. Two-file, small revert. |

### 5.3 De-risking discovery (new this phase)
The codebase **already has batch/parallel read primitives** — `Firebase::firestoreGetParallel(array $reqs)` (`Firebase.php:992`) and `Firestore_rest_client::commitBatch` (`:833`). This means the follow-on **P1-1 (batch the subscription N+1)** can reuse existing, tested infrastructure rather than introduce new transport code — materially lowering its risk from the audit's "Medium."

---

## 6. Read-Count Comparison Table (before → after, `STATIC-EXACT`)

| Page | Today | After P0-1 | After P0-1 + P0-2 | After + P1-1 (batch subs) |
|------|-------|-----------|-------------------|---------------------------|
| Dashboard | `33 + 3N` | `27 + N` | `27 + N` | `27 + ⌈N/500⌉` |
| Manage Schools | `4 + 4N` | `4 + 4N`¹ | `4 + N` | `4 + ⌈N/500⌉` |
| Statistics | `13 + 3N` (+rollups) | `7 + N` (+rollups)² | `7 + N` | `7 + ⌈N/500⌉` |
| Cross-school | `12 + 4N` (+activity) | `3 + N` (+activity) | `3 + N` | `3 + ⌈N/500⌉` |
| Plans | `1 + P` | `1 + P` | `1 + P` | `1 + P`³ |
| Admin Users (list) | `1` | `1` | `1` | `1` |

¹ P0-1 doesn't help Manage Schools (LTS called once); **P0-2 is the fix here.**
² P0-1 leaves Statistics' 4 standalone `schools` scans (`:707/:730/:751`) — a small follow-up folds them into one shared snapshot.
³ Plans is fixed by **P1-4** (single pass over cached tenants), not P0-1/P1-1.

---

## 7. Updated Implementation Recommendation

The verification **upholds the audit** and sharpens it:

1. **P0-1 (memoize LTS)** is validated as the highest-leverage, lowest-risk change. It removes **`6+2N`–`9+3N` reads per analytics page** with a request-scoped memo. **One pre-condition to confirm before authorization:** verify no SA request performs *write-then-re-list-in-same-request* (§5.1) — if any does, ship the memo with a `bust` hook. My read of the controllers says none do, but this is the one check I recommend completing during Step 1 implementation review.
2. **P0-2 (remove detail loop)** is validated: only **`domainIdentifier`** must be added to the LTS projection (zero extra reads); one cosmetic `status`-derivation nuance to sign off (§5.2). It is the correct fix for Manage Schools and is **independent of P0-1** — they can ship separately.
3. **Sequencing:** P0-1 first (as you proposed) is sound — it is self-contained, benefits 4 surfaces, and carries the smaller blast radius. P0-2 second. P1-1 becomes low-risk thanks to the existing `firestoreGetParallel`/`commitBatch` primitives.

**Recommended Step 1 scope (for your authorization):** P0-1 only — add a request-scoped memo to `B2_registry_service::list_tenants_summary()`, returned **by value**, invalidated implicitly at request end, with the write-then-read pre-check completed during review. Verification of success = re-run this static accounting (or the optional instrumentation below) and confirm Dashboard LTS calls drop 3→1.

### Optional add-on you may authorize instead of/before Step 1
If you want **true runtime numbers** rather than static-exact counts, authorize a **read-only instrumentation probe** (Phase 7 of the audit): a counter inside `Firestore_rest_client::request` emitting per-request `{route, reads, writes, queries, token_refresh, cache_hits}` to a log. That is a code change (so it needs explicit authorization) but it is purely additive, non-behavioral, and trivially revertible — and it would convert every `MODELED` figure in these reports into a measured one.

---

## Appendix — Confidence ledger
| Item | Tag | Confidence | Basis |
|------|-----|-----------|-------|
| LTS = 3 + Nsub reads, un-memoized | STATIC-EXACT | HIGH | `B2_registry_service.php:509–624` direct read |
| `get_tenant_detail` = 3 reads | STATIC-EXACT | HIGH | `:635,:636,:643` |
| Dashboard / Statistics call LTS 3× | STATIC-EXACT | HIGH | function-boundary map + call-site grep |
| Cross-school calls LTS 4× | STATIC-EXACT | HIGH | call sites `:1613,:1737,:1756,:1775` |
| Plans = 1 + P queries | STATIC-EXACT | HIGH | `Superadmin_plans.php:59–64` + `B2_registry_service.php:489` |
| Schools-list view needs only `domain_id` beyond summary | STATIC-EXACT | HIGH | view grep + projection `:599–615` |
| `firestoreGetParallel`/`commitBatch` exist | STATIC-EXACT | HIGH | `Firebase.php:992`, `Firestore_rest_client.php:833` |
| No SA-side `Dashboard_cache` usage | STATIC-EXACT | HIGH | grep of `Superadmin*.php` |
| Per-page millisecond latencies | MODELED | MEDIUM | reads × ~150ms; no APM |
