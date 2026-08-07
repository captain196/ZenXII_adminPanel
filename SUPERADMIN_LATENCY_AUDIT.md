# ZenX SuperAdmin — Performance & Latency Deep Audit

**Date:** 2026-06-04
**Repository:** `C:\xampp\htdocs\ZenX\school`
**Scope:** SuperAdmin (SA) platform — request lifecycle, controllers, B2 registry/analytics services, Firestore access patterns, caching, RBAC/session/auth, scale behavior.
**Method:** Source-verified. Every finding cites file, function, line numbers, and a confidence level. No code was modified. Latency figures are *model estimates* (Firestore REST round-trip assumed ~100–300ms each, serial) unless marked measured — confidence on the *pattern* is separate from confidence on the *millisecond estimate*.

> **Estimate caveat:** No APM/profiler data exists in-repo. All millisecond numbers are derived from read-counts × assumed per-call latency. The **read-counts and code patterns are HIGH confidence** (traced from source); the **absolute latencies are MEDIUM confidence** and must be confirmed with the instrumentation in Phase 7.

---

## 1. Executive Summary

The SA panel's slowness has a **single dominant root cause** with a few amplifiers. It is not PHP, not the framework, not rendering.

**Root cause:** SA pages issue **sequential, per-row Firestore REST reads** and **recompute the same uncached tenant dataset multiple times per request**. Firestore REST calls are made one-at-a-time (serial), each ~100–300ms, and the central tenant-aggregation function is never cached across calls or across requests.

The three structural defects, in order of impact:

1. **`list_tenants_summary()` is the hot path of the entire SA platform and is (a) un-memoized, (b) does 3 full-collection scans + an N+1 subscription read per call.** It is invoked 1–4× *per request* by nearly every analytics surface. — `B2_registry_service.php:509`
2. **The "Manage Schools" page re-reads, per school, data it already holds in memory** — an `N`-iteration loop calling `get_tenant_detail()` (3 reads each) on top of the summary it just fetched. — `Superadmin_schools.php:47`
3. **The SA hub does not use the caching or pre-aggregation infrastructure that already exists in the codebase.** `Dashboard_cache` (APCu/file) and the `analyticsRollups` cron pre-aggregation are wired to the *school-side* dashboard, not the SA hub. The SA hub recomputes live from raw collections on every load. — `Dashboard_cache.php`, `B2_analytics_rollup_job.php`

**Good news / what NOT to touch:**
- The SA base controller (`MY_Superadmin_Controller`) is **lean** — it does *not* carry MY_Controller's per-request subscription/RBAC/push-request checks. Don't add them.
- The OAuth token cache (cross-request, APCu/file, stampede-locked) is **well-engineered**. Leave it.
- `analyticsRollups` pre-aggregation and `Dashboard_cache` are **correct designs** — the fix is to *reuse* them on the SA side, not rebuild.
- Per-request memo fields (`_enriched_tenants_cache`, `_activity_cache`, `$flagsCache`) are correct as far as they go — they just don't cover the registry-level summary.

**Scale verdict:** The platform is acceptable at **~10 schools**, visibly slow at **~100**, and **functionally broken at 1,000+** on the schools-list and cross-school surfaces (serial reads scale linearly with tenant count; an unbounded query also returns the entire collection into PHP memory in one response). See §6.

**Fastest path to relief:** Two surgical, low-risk changes (memoize the summary; delete the redundant per-school loop) cut the worst pages from tens of seconds to ~1s. See §10.

---

## 2. Top 20 Latency Offenders (ranked by impact ÷ scale-sensitivity)

| # | Offender | File:Line · Function | Pattern | Reads | Scales with | Severity | Confidence |
|---|----------|----------------------|---------|-------|-------------|----------|------------|
| 1 | Schools list re-reads each tenant | `Superadmin_schools.php:47` · `index()` | N+1 detail loop atop the summary it already has | `4 + 4N` | **N schools** | 🔴 CRITICAL | HIGH |
| 2 | Tenant summary N+1 subscription | `B2_registry_service.php:563` · `list_tenants_summary()` | `firestoreGet('subscriptions')` per school in loop | `3 + N` | **N schools** | 🔴 CRITICAL | HIGH |
| 3 | Tenant summary not memoized | `B2_registry_service.php:509` · `list_tenants_summary()` | no instance/request cache; recomputed per call | ×caller | **N schools** | 🔴 CRITICAL | HIGH |
| 4 | Statistics page recomputes summary 2–3× | `B2_analytics_service.php:664` · `get_statistics_payload()` | 3× `list_tenants_summary()` + 4 standalone `schools` scans | `~6×(3+N)` | **N schools** | 🔴 CRITICAL | HIGH |
| 5 | Cross-school summary recomputes summary 4× | `B2_analytics_service.php:1572` · `get_cross_school_summary()` | 4× `list_tenants_summary()` in call stack + matrix | `~12+N` | **N schools** | 🔴 CRITICAL | HIGH |
| 6 | Hub payload recomputes summary 3× | `B2_analytics_service.php:598` · `get_hub_payload()` | kpi + top_schools + expiring_soon each call summary | `~3×(3+N)` | **N schools** | 🔴 CRITICAL | HIGH |
| 7 | Schools-search: load-all-then-slice | `B2_analytics_service.php:~889` · `search_schools()` | no Firestore limit/offset; sort+slice in PHP | `4 full` | **N schools** | 🟠 HIGH | HIGH |
| 8 | Revenue overview duplicate MRR + summary | `B2_analytics_service.php:1235` · `get_revenue_overview()` | `compute_mrr` twice, `list_tenants_summary` twice | `8–10 full` | N + #subs | 🟠 HIGH | HIGH |
| 9 | Monitor firebase_usage 28 serial reads | `Superadmin_monitor.php:193` · `firebase_usage()` | 7 days × 4 RTDB log paths, serial | `28` | days×types | 🟠 HIGH | HIGH |
| 10 | Plans index per-plan count | `Superadmin_plans.php:62` · `index()` | `count_schools_on_plan()` per plan (N+1) | `P` queries | #plans | 🟠 HIGH | HIGH |
| 11 | AdminUsers email-exists full scan | `AdminUsers.php:470, 612` · create/update | full `admins` scan + PHP match | `1 full` | #admins | 🟠 HIGH | HIGH |
| 12 | AdminUsers role-usage full scan | `AdminUsers.php:1123` · delete_role | full `admins` scan for one match | `1 full` | #admins | 🟠 HIGH | HIGH |
| 13 | AdminUsers dashboard counts full scan | `AdminUsers.php:358` · dashboard | full `admins` scan, PHP counting | `1 full` | #admins | 🟠 HIGH | HIGH |
| 14 | AdminUsers login-logs full scan+sort | `AdminUsers.php:~1162` · login logs | full `admins` scan, PHP sort | `1 full` | #admins | 🟠 HIGH | MEDIUM |
| 15 | Standalone `schools` scans in stats | `B2_analytics_service.php:707,730,751` | avg-age / net-new-30 / net-new-90 / recent each scan `schools` | `4 full` | **N schools** | 🟠 HIGH | HIGH |
| 16 | Firebase library full init per request | `Firebase.php:43` · `__construct()` | SA JSON read ×3 + Kreait + REST + Storage clients | I/O+CPU | per request | 🟡 MEDIUM | HIGH |
| 17 | OAuth token blocking fetch on miss | `Firestore_rest_client.php:305` · `getAccessToken()` | 1–3s curl to Google on hourly expiry | 1 net | hourly | 🟡 MEDIUM | HIGH |
| 18 | Cross-school / reports: no pagination | `B2_analytics_service.php:~1772`; `Superadmin_reports.php` | full fleet returned, no slicing | full | **N schools** | 🟡 MEDIUM | HIGH |
| 19 | Backups run-scheduled per-school loop | `Superadmin_backups.php` · `run_scheduled_now()` | per-school RTDB backup loop | `~N` | **N schools** | 🟡 MEDIUM | MEDIUM |
| 20 | Legacy `Superadmin_admins` RTDB full-node | `Superadmin_admins.php:109` · `create()` | reads whole `Users/Admin/Our Panel` node | 1 full | #legacy admins | 🟢 LOW | HIGH |

> `N` = number of tenant (school) docs. The top 6 offenders all scale **linearly with N**, which is why the platform degrades as it grows.

---

## 3. Firestore Architecture Review

### 3.1 N+1 query patterns (read list → loop → read detail)

| Location | Function | Lines | The loop | Verdict |
|----------|----------|-------|----------|---------|
| `Superadmin_schools.php` | `index()` | 43–69 | `foreach ($tenants as $t){ $svc->get_tenant_detail($sid); }` — 3 reads/iter | 🔴 CRITICAL |
| `B2_registry_service.php` | `list_tenants_summary()` | 545–571 | `foreach ($schoolsRaw …){ firestoreGet('subscriptions',$subPtr); }` | 🔴 CRITICAL |
| `Superadmin_plans.php` | `index()` | 59–64 | `foreach plans { count_schools_on_plan($pid); }` | 🟠 HIGH |
| `Superadmin_backups.php` | `run_scheduled_now()` | — | per-school backup loop over `System/Schools` | 🟡 MEDIUM |

**#1 is the worst because it is doubly wasteful:** `get_tenant_detail()` (`B2_registry_service.php:631`) re-reads `schools`, `schoolControl`, and `subscriptions` for a school whose `city`, `logoUrl`, `statsCache→totalStudents/totalStaff`, `adminDisabled`, plan, lifecycle, and subscription dates **are already present** in the `$t` summary row built at lines 599–615. The only fields the loop adds that the summary lacks are `domainIdentifier` and `createdAt`. (Confidence: HIGH — both functions read directly.)

### 3.2 Full-collection scans (`firestoreQuery(coll, [])`)

Verified empty-filter scans that pull an entire collection into PHP then filter/aggregate in-process:

| File:Line | Function | Collection |
|-----------|----------|------------|
| `B2_registry_service.php:518,519,526` | `list_tenants_summary()` | schools, schoolControl, tenantPublic |
| `B2_analytics_service.php:192` | `compute_mrr_from_subscriptions()` | subscriptions |
| `B2_analytics_service.php:220` | `count_active_subscriptions()` | subscriptions |
| `B2_analytics_service.php:242` | `load_plan_price_map()` | plans |
| `B2_analytics_service.php:350` | `get_stale_stats_tenants()` | schools |
| `B2_analytics_service.php:707` | `get_average_tenant_age_days()` | schools |
| `B2_analytics_service.php:730` | `count_new_schools_in_window()` | schools |
| `B2_analytics_service.php:751` | `get_recent_registrations()` | schools |
| `B2_analytics_service.php:994,1016,1025` | `_load_enriched_tenants()` | schools, schoolControl, tenantPublic |
| `B2_analytics_service.php:1360` | `get_revenue_by_plan()` | subscriptions |

(Confidence: HIGH.) **Critical scale risk:** Firestore REST `runQuery` without a `limit` returns *every* matching document in a single response. At 10,000 schools this is a multi-MB response decoded into a PHP array on every call — a memory and bandwidth cliff independent of round-trip latency.

> **Counter-example (GOOD):** `get_per_tenant_activity_volume()` at `B2_analytics_service.php:1673` uses a windowed filter `[['ts','>=',$cut]]` and aggregates in one pass — *not* a full scan, *not* N+1. This is the pattern to imitate.

### 3.3 Duplicate reads (data already in memory, re-fetched)

| Where | Evidence |
|-------|----------|
| `get_statistics_payload()` | `list_tenants_summary()` called at lines ~672/673/674 (lifecycle, plan, city distributions) — 3 identical recomputations in one method |
| `get_revenue_overview()` `B2_analytics_service.php:1235` | `compute_mrr` then `compute_arr`→`compute_mrr` again; `list_tenants_summary` twice (count + active-count) |
| `Superadmin_schools.php:index()` | per-school `get_tenant_detail()` duplicates the bulk summary (see §3.1) |
| `Superadmin_schools.php:view()` ~797/829 | `get_plan()` single read + `list_plans()` collection read — overlapping plan data |

(Confidence: HIGH.) Root: **no request-scoped result cache at the registry layer.** A per-request memo on `list_tenants_summary()` collapses all of these at once.

### 3.4 Sequential network chains (serial where parallel/batch is possible)

- `list_tenants_summary()`: 3 collection reads run back-to-back (518→519→526) then N serial subscription `firestoreGet`s. The N subscription reads are the ideal candidate for a single `:batchGet` (Firestore REST supports up to 500 docs/batch) — turning N serial round-trips into ⌈N/500⌉.
- `firebase_usage()` (`Superadmin_monitor.php:193`): 28 serial RTDB reads where a shallow/aggregated read or a single rollup doc would do.
- The PHP-cURL Firestore client **does** reuse one cURL handle within a request (`Firestore_rest_client.php:348`, `USE_PERSISTENT_CURL=true`) — good; it avoids per-call TLS handshakes. But calls are still **issued serially** (no multi-handle/`curl_multi`), so latency is additive.

(Confidence: HIGH on the patterns; the batch/parallel savings are MEDIUM until measured.)

### 3.5 Classification summary

| Class | Count | Examples |
|-------|-------|----------|
| 🔴 CRITICAL | 6 | offenders #1–#6 |
| 🟠 WARNING | ~9 | full-scans, no-pagination, per-entity admin scans |
| 🟢 GOOD | — | windowed `tenantAudit` aggregation; OAuth token cache; per-request cURL reuse; lean SA base controller |

---

## 4. Controller / Request Lifecycle Review

**Key correction vs. common assumption:** SA controllers extend **`MY_Superadmin_Controller`** (`application/core/MY_Superadmin_Controller.php`), *not* `MY_Controller`. Therefore SA pages do **not** incur:
- the 5-minute subscription/lifecycle re-check,
- the RBAC permission refresh (`load_role_permissions`),
- the 30-second push-request auto-processing query,
- the session-whitelist Firestore refresh.

Those costs are real but belong to the **school-admin** lifecycle (`MY_Controller.php:228–327`), documented here for completeness but out of SA scope. (Confidence: HIGH.)

**What every SA request *does* pay:**

| Step | File:Line | Cost | Frequency | Confidence |
|------|-----------|------|-----------|------------|
| `parent::__construct()` → `load->library('firebase')` | `MY_Superadmin_Controller.php:19` | Full Firebase init | every request | HIGH |
| Service-account JSON read ×3 + Kreait Factory + RTDB + Auth clients | `Firebase.php:45,52–58` | disk I/O + object build | every request | HIGH |
| Firestore REST client construct (reads SA JSON again) | `Firebase.php:63`; `Firestore_rest_client.php:33` | disk I/O | every request | HIGH |
| Storage client construct (reads SA JSON again) | `Firebase.php:70` | object build | every request | HIGH |
| `sa_id` session read + auth gate | `MY_Superadmin_Controller.php:22–24` | session memory | every request | HIGH |
| CSRF token gen / validate | `MY_Superadmin_Controller.php:53,59` | RNG / hash | first req / POST | HIGH |
| Security headers | `MY_Superadmin_Controller.php:108–140` | no I/O | every request | HIGH |

**Findings:**
- **F-LC1 (MEDIUM):** The Firebase library is rebuilt per request with **no PHP-process-lifetime reuse**, and the service-account JSON is read from disk **three times** per construct (Kreait, Firestore REST client, Storage client). On PHP-FPM this repeats every request. The OAuth *token* is cached cross-request, so the expensive network step is amortized — but file I/O + client object construction is not. (Confidence: HIGH on the pattern; MEDIUM on the ms cost.)
- **F-LC2 (LOW):** `B2_registry_service` / `B2_analytics_service` are lazy-loaded and `init()`-ed on demand; no evidence of repeated heavy init within a single request, but no explicit "already-initialized" guard either — worth confirming under load.

---

## 5. Cache Review

### 5.1 What exists (verified)

| Cache | File | Backing | TTL | Cross-request | Used by |
|-------|------|---------|-----|---------------|---------|
| `Dashboard_cache` | `Dashboard_cache.php:20` | APCu → file (`cache/dashboard/`) | bounded (e.g. 600s) | ✅ | **School-side** `Admin.php`, `Notifications.php` |
| OAuth token | `Firestore_rest_client.php` | APCu → file (`cache/firebase_auth/`) | ≤ token life, 60s buffer, stampede-locked | ✅ | All Firestore calls |
| `analyticsRollups` | `B2_analytics_rollup_job.php:270` (cron) | Firestore docs (monthly/daily) | nightly regen | ✅ (persistent) | SA hub **time-series only** |
| `schools.statsCache` | `B2_registry_service.php:1577` | Firestore field | until next write | ✅ (persistent) | SA schools list, hub freshness |
| `_enriched_tenants_cache` | `B2_analytics_service.php:852` | PHP instance field | request only | ❌ | search/options |
| `_activity_cache` | `B2_analytics_service.php:~1664` | PHP instance field | request only | ❌ | hub activity |
| `$flagsCache` | `B2_registry_service.php:50` | PHP instance field | request only | ❌ | migration flag gate |

(Confidence: HIGH.)

### 5.2 The gap

- **`list_tenants_summary()` has no cache of any kind** — not request-memo, not cross-request. It is the single most-called expensive function and the single biggest cache omission. (`B2_registry_service.php:509`, confirmed: line 511 only guards on `$this->ready`.)
- **The SA hub bypasses `Dashboard_cache` entirely.** That library is generic and tenant-namespaced — it could cache hub/statistics/cross-school payloads with a 30–120s TTL with near-zero new code.
- **`analyticsRollups` is under-leveraged.** It already pre-aggregates lifecycle/plan/city distributions, totals, MRR, and per-tenant snapshots nightly — yet the live hub recomputes most of these from raw collections instead of reading the latest rollup doc.

### 5.3 Recommended cache classification

| Operation | Today | Recommended | TTL |
|-----------|-------|-------------|-----|
| `list_tenants_summary()` | NO CACHE | **REQUEST CACHE** (memo) + **SHORT CACHE** (cross-request) | memo: request; short: 30–60s |
| Hub payload (kpi/top/expiring) | NO CACHE | SHORT CACHE | 30–90s |
| Statistics distributions | NO CACHE (live) | **LONG CACHE** via `analyticsRollups` read | rollup cadence (nightly) + 5–15min hot cache |
| Schools-search result set | NO CACHE | REQUEST CACHE (already memo'd) + Firestore-side pagination | request |
| Revenue overview | NO CACHE | SHORT CACHE | 60–300s |
| `analyticsRollups` docs | Firestore | (keep) optionally hot-cache the latest | 5–15min |
| OAuth token | cross-request | (keep — exemplary) | as-is |
| Per-tenant deep-dive | NO CACHE | SHORT CACHE per tenant | 30–60s |
| **Never cache** | — | live lifecycle/access-gating decisions; CSRF; auth identity | — |

---

## 6. Scale Readiness Review

Model: serial Firestore reads × ~150ms average. `N` = schools. Pattern confidence HIGH; ms confidence MEDIUM.

### 6.1 Schools-list page (`Superadmin_schools::index`, `4 + 4N` reads)

| N | Reads | Est. latency | Verdict |
|---|-------|--------------|---------|
| 10 | 44 | ~6.6s | Slow but usable |
| 100 | 404 | ~60s | **Unusable** (PHP max_execution_time risk) |
| 1,000 | 4,004 | ~10min | **Hard fail** |
| 10,000 | 40,004 | — | **Impossible** + memory blowout from unbounded scans |

### 6.2 `list_tenants_summary()` alone (`3 + N` reads, called 1–4× per analytics page)

| N | Reads/call | Hub (×3) | Stats (×~6) | Cross-school (×4) |
|---|-----------|----------|-------------|-------------------|
| 10 | 13 | 39 (~6s) | ~78 (~12s) | ~52 (~8s) |
| 100 | 103 | 309 (~46s) | ~618 (~93s) | ~412 (~62s) |
| 1,000 | 1,003 | ~3,009 | ~6,018 | ~4,012 |
| 10,000 | 10,003 | — | — | — |

### 6.3 Per-surface scale verdict

| Surface | 10 | 100 | 1,000 | 10,000 |
|---------|----|----|-------|--------|
| Dashboard hub | OK | Slow | Fail | Fail |
| Schools list | Slow | Fail | Fail | Fail |
| Statistics | Slow | Fail | Fail | Fail |
| Cross-school | Slow | Fail | Fail | Fail |
| Schools-search | OK | Slow | Fail (load-all) | Fail |
| Admin Users | OK | OK | Slow (per-school admins bounded) | Slow |
| Plans | OK | Slow | Fail (N+1 counts) | Fail |
| Revenue | Slow | Fail | Fail | Fail |
| Monitoring | OK (fixed 28) | OK | OK | OK |
| Per-tenant deep-dive | OK | OK | OK | OK (bounded reads) |

**Conclusion:** Everything that touches the full tenant set degrades **linearly with N**. The platform must move from *compute-on-read over raw collections* to *read pre-aggregated rollups + paginate raw lists* before crossing ~100 tenants.

---

## 7. Industry / Mature-SaaS Comparison

| Pattern | Industry approach | ZenX current state | Gap | Expected gain | Risk |
|---------|-------------------|--------------------|-----|---------------|------|
| **Request memoization** | Single in-request cache for repeated aggregates | Partial (`_enriched_tenants_cache`) but **not** on `list_tenants_summary` | Summary recomputed 2–4×/req | 50–70% fewer reads on analytics pages | Very low |
| **Pre-aggregation / materialized stats** | Console reads pre-built rollups, never raw | `analyticsRollups` exists but hub still computes live | Live recompute over raw collections | 10×+ on stats/hub | Low (rollup exists) |
| **Denormalized summary collection** | One `tenantSummary/{id}` doc with everything a list needs | Data split across schools+schoolControl+tenantPublic+subscriptions → join at read time | 3-collection join + N+1 every list | Turns `3+N` into `1 query` | Medium (write fan-out) |
| **Event-driven counters** | Counters updated on write (Cloud Functions/triggers) | `statsCache` updated by school crons; SA-side aggregates computed on read | No fleet-level counters | O(1) KPI tiles | Medium |
| **Pagination (cursor)** | `limit` + `startAfter` cursor at DB | Load-all-then-slice in PHP; some pages no pagination at all | Page 1000 costs same as page 1 | Bounded latency regardless of N | Low–Medium |
| **Async analytics generation** | Heavy reports generated by background jobs, served from cache | Rollup cron exists; live surfaces don't consume it fully | Synchronous heavy reads on user request | Sub-second hub | Low |
| **Batch reads** | `batchGet`/`getAll` for many docs | Serial `firestoreGet` in loops | N serial round-trips | N → ⌈N/500⌉ | Low |
| **Search indexing** | External index (Algolia/Elastic) or indexed queries | Full-collection load + PHP filter | Unscalable search | Constant-time search | Medium–High |
| **Cached tenant summaries** | Edge/short TTL cache on tenant lists | None on SA side (`Dashboard_cache` unused here) | Cold compute every load | 90%+ on cache hit | Very low |

**Net:** ZenX has *built* most of the right primitives (rollup cron, generic cache lib, per-request memo, windowed aggregation) but **has not connected them to the SA read paths**. This is a wiring/architecture-discipline gap, not a missing-capability gap — which makes remediation unusually low-risk.

---

## 8. Performance Instrumentation Plan (design only)

Goal: replace estimated ms with measured ms, and expose read-count regressions before they ship.

**8.1 Firestore call profiler** — wrap the single choke point `Firestore_rest_client::request()` (`:364`). Increment a per-request counter and accumulate wall-time per `(collection, op)`. Emit at request end. This captures *every* read/write/query centrally with zero per-call-site changes.

**8.2 Request profiler** — in `MY_Superadmin_Controller`, capture `$start = microtime(true)` in `__construct` and a shutdown hook (or post_controller hook) logging: route, total ms, Firestore read count, write count, cache hits/misses, token-refresh flag. Write to a ring-buffer log (`cache/perf/`) or RTDB `System/Perf/{date}`.

**8.3 Cache hit/miss counters** — add counters inside `Dashboard_cache::get()` and the registry memo; surface in the request profiler line.

**8.4 Token-refresh signal** — flag when `getAccessToken()` (`:305`) takes the network path; this isolates the hourly 1–3s spike from steady-state.

**8.5 Latency dashboard (SA page)** — a new read-only `Superadmin_monitor` card rendering the last N profiler lines: p50/p95 request ms, reads/request, top-collection by read-count, cache hit ratio. No new collections needed — read the perf log.

**Instrumentation points summary:** `Firestore_rest_client::request` (reads/writes/queries + timing), `Firestore_rest_client::getAccessToken` (token refresh), `Dashboard_cache::get/set` (hits/misses), `MY_Superadmin_Controller` ctor + shutdown (request envelope), registry/analytics memo (dedup hits).

---

## 9. Prioritized Remediation Roadmap

Each item: issue · files · risk · expected reduction · effort · verification · rollback.

### Priority 0 — stop the bleeding (do first)

**P0-1 · Memoize `list_tenants_summary()`**
- Issue: recomputed 2–4×/request; 3 scans + N+1 each. — `B2_registry_service.php:509`
- Files: `B2_registry_service.php`
- Risk: **Very low** (add instance memo + `$ready` guard; data is read-only within a request)
- Reduction: 50–70% of reads on hub/statistics/cross-school
- Effort: <1h
- Verify: profiler read-count on `/statistics` drops ~⅔; visual parity
- Rollback: remove memo field

**P0-2 · Remove the per-school `get_tenant_detail()` loop in schools list**
- Issue: `4 + 4N` reads; loop re-reads data already in the summary. — `Superadmin_schools.php:43–69`
- Files: `Superadmin_schools.php`; optionally add `domainIdentifier`,`createdAt` to the summary projection in `B2_registry_service.php:599`
- Risk: **Low** (serve from summary; only 2 fields need backfilling into projection)
- Reduction: schools list `4+4N → ~4` reads (≈ from ~60s to ~1s at N=100)
- Effort: 2–4h
- Verify: row-by-row field parity vs current page; profiler read-count
- Rollback: revert controller

### Priority 1 — collapse redundancy & N+1

**P1-1 · Batch the subscription reads** — replace the per-tenant `firestoreGet('subscriptions')` loop (`:563`) with a Firestore REST `:batchGet`, or fold `periodEnd/graceEnd` into `schoolControl` so no second collection is needed. Files: `B2_registry_service.php`, `Firestore_rest_client.php` (add batchGet). Risk: Medium. Reduction: `3+N → ~4`. Effort: 1–2d. Verify: parity + read-count. Rollback: revert.

**P1-2 · Wire SA hub/statistics to `analyticsRollups`** — read the latest rollup doc for distributions/totals instead of live recompute. Files: `B2_analytics_service.php`. Risk: Low–Medium (freshness becomes rollup-cadence — acceptable for fleet stats). Reduction: 10×+ on stats. Effort: 1–2d. Verify: numbers match rollup; staleness banner if rollup old. Rollback: flag back to live.

**P1-3 · Short-TTL cache for hub/cross-school via `Dashboard_cache`** — 30–90s TTL, SA-namespaced key. Files: `Superadmin.php`, `Superadmin_analytics.php`. Risk: Very low. Reduction: 90%+ on cache hit. Effort: 0.5–1d. Verify: hit ratio in profiler. Rollback: TTL=0.

**P1-4 · AdminUsers/Plans indexed queries** — replace full-`admins` scans (email-exists `:470/:612`, role-usage `:1123`, dashboard `:358`) with `where(...).limit(1)`; replace per-plan `count_schools_on_plan` (`:62`) with one pass over cached tenants. Files: `AdminUsers.php`, `Superadmin_plans.php`. Risk: Low (needs composite indexes). Reduction: `1 full → 1 limited` per check. Effort: 1–2d. Verify: parity + index presence. Rollback: revert.

### Priority 2 — scale to thousands

**P2-1 · Firestore-side pagination (cursor)** on schools-search and lists — `limit + startAfter` instead of load-all-then-slice. Files: `B2_analytics_service.php`, `Firestore_rest_client.php`. Risk: Medium. Effort: 2–4d.

**P2-2 · Denormalized `tenantSummary/{id}`** collection (one doc per tenant carrying everything the list/hub needs), maintained on tenant writes. Turns the 3-collection join + N+1 into a single paginated query. Files: registry + onboarding/lifecycle writers. Risk: Medium (write fan-out + backfill). Effort: 3–5d.

**P2-3 · Batch RTDB monitor reads** — `firebase_usage()` (`Superadmin_monitor.php:193`) to a single rollup/shallow read. Risk: Low. Effort: 0.5–1d.

### Priority 3 — durability & observability

**P3-1 · Instrumentation** (Phase 7) — ship the profiler + latency card. Effort: 2–3d.
**P3-2 · Process-lifetime Firebase client reuse** (read SA JSON once; reuse clients) — only if profiler shows init cost is material. Risk: Medium (FPM lifecycle). Effort: 1–2d.
**P3-3 · Event-driven fleet counters** (Cloud Functions on tenant writes) for O(1) KPI tiles. Effort: >3d.

---

## 10. Quick Wins (<1 day)

1. **P0-1 memoize `list_tenants_summary()`** — biggest win, smallest change (<1h). `B2_registry_service.php:509`
2. **P1-3 short-TTL `Dashboard_cache` on hub/cross-school** (~0.5d) — instant relief on repeat loads.
3. **P1-4 (partial) AdminUsers email-exists → `where().limit(1)`** (~2–3h) — removes blocking full scans from create/update.
4. **P2-3 monitor `firebase_usage` → single read** (~0.5d) — 28 reads → 1.

## 11. Medium Wins (1–3 days)

1. **P0-2 delete schools-list N+1 loop** (2–4h, but verification-heavy ⇒ count it here) — fixes the single worst page.
2. **P1-1 batchGet subscriptions** — kills the registry N+1.
3. **P1-2 wire hub/statistics to `analyticsRollups`** — 10×+ on stats.
4. **P1-4 (rest) Plans count + AdminUsers role/dashboard scans** — indexed queries.

## 12. Major Refactors (>3 days)

1. **P2-2 denormalized `tenantSummary` collection** — the structural fix for 1k–10k scale; eliminates joins + N+1 at the source.
2. **P2-1 cursor pagination** across all SA lists/search — bounds latency regardless of N.
3. **P3-3 event-driven fleet counters** — O(1) KPIs; the mature-SaaS end state.
4. **P3-1 instrumentation suite** — make latency measurable and regressions visible (do early in practice; sized here for effort).

---

## Appendix A — Evidence confidence ledger

| Claim | Confidence | Basis |
|-------|-----------|-------|
| `list_tenants_summary` does 3 scans + N+1 subs, un-memoized | HIGH | Direct read `B2_registry_service.php:509–624` |
| `get_tenant_detail` = 3 reads (schools+schoolControl+subscriptions) | HIGH | Direct read `:631–657` |
| Schools list loops `get_tenant_detail` per tenant | HIGH | Direct read `Superadmin_schools.php:43–69` |
| Summary recomputed 2–4× on stats/hub/cross-school | HIGH | Agent trace of `B2_analytics_service.php` call sites |
| SA uses `MY_Superadmin_Controller` (not MY_Controller) | HIGH | Direct read of SA base controller |
| `Dashboard_cache` exists, APCu/file, used school-side only | HIGH | Direct read `Dashboard_cache.php` + writers in `Admin.php`/`Notifications.php` |
| `analyticsRollups` cron pre-aggregation exists | HIGH | `B2_analytics_rollup_job.php` |
| OAuth token cross-request cached + stampede-locked | HIGH | `Firestore_rest_client.php` token path |
| Schools-search load-all-then-slice (no DB pagination) | HIGH | `B2_analytics_service.php` search trace |
| Absolute millisecond latencies | MEDIUM | Modeled (reads × ~150ms); no APM data |
| `firebase_usage` = 28 serial reads | HIGH | `Superadmin_monitor.php:193–211` |
| AdminUsers full-scan patterns | HIGH / MEDIUM | Direct lines; login-logs path MEDIUM |

## Appendix B — Single highest-leverage fact

> `list_tenants_summary()` (`B2_registry_service.php:509`) is the spine of the SA platform. It is uncached and O(N) with an N+1. **Memoizing it (P0-1) and serving the schools list from it instead of re-reading per tenant (P0-2) addresses the majority of measured pain for under a day of work and near-zero risk.** Everything else is depth and scale-proofing.
