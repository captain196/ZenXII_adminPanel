# Transport F13.1 + Maps Platform — Final Pre-Freeze Validation

**Date:** 2026-08-08 · **Revision 2** (two corrections applied)
**Status:** **F13.1 NOT FROZEN.** No commit, push, merge or deployment.

---

## 0. Headline

# MANUAL UAT = NOT PERFORMED / BLOCKED

The automated protocol is complete and green. **No manual test has been converted to PASS on
automated evidence.** Nothing here claims a human saw a map, a driver started a trip, or a parent
watched a bus move.

The two requested corrections are done, and both turned out to be more interesting than expected:

* **Correction 1** — provider availability is now platform-aware and *enforced*, not just documented.
* **Correction 2** — the flake was root-caused to **my own test process**, not the product. Details in
  §5, because the honest answer matters more than a green tick.

---

## 1. Correction 1 — platform-aware provider availability

### The problem

TomTom was selectable centrally. Leaflet renders its raster tiles, so the admin panel worked — but
`MapRendererFactory` had no Android TomTom renderer. Assigning TomTom to Parent stored a
configuration that resolved to a placeholder and showed "no provider configured", **while the
administrator believed the map was live**. A silently unusable configuration is worse than a refusal.

### What changed

No new SDK was added, per your instruction. Availability became a declared property of the registry.

| Provider | admin | parent | teacher | driver |
|---|---|---|---|---|
| MapLibre | ✓ | ✓ | – | – |
| MapTiler | ✓ | ✓ | – | – |
| **TomTom** | **✓** | **✗** | – | – |
| Google | ✗ | ✗ | – | – |
| Mapbox | ✗ | ✗ | – | – |
| HERE | ✗ | ✗ | – | – |

*(– = no map surface on that platform yet)*

**Enforcement, server-side:**
* `isSupportedOn()`, `supportedPlatforms()`, `isGlobalCapable()`.
* **Per-platform assignment is refused** where no renderer exists, with a message naming the gap.
* **Global mode is refused** for a provider that cannot render on *every* surface — global means
  everywhere, so TomTom can never be global.
* Refusal survives activation: even an activated, validated TomTom cannot be assigned to Parent.

**Admin page:** each provider shows availability chips (`✓ admin  ✗ parent  – teacher  – driver`), and
unusable options are **disabled and labelled** ("— no parent renderer") rather than hidden. An
administrator should see that TomTom exists and *why* they cannot pick it, not wonder where it went.

**Client:** `client_config` now returns a `supported` flag so an app can state the limitation instead
of rendering a blank map. `MapProviderConfig.isSupportedOn()` mirrors the server rule.

### One judgement call

`teacher` and `driver` have configuration plumbing but **no map UI**, so an assignment there renders
nothing either way. Blocking it would have been enforcement theatre. They are therefore assignable
and **labelled** as having no surface yet — `PLATFORMS_WITH_SURFACE` is where enforcement applies.

### A defect my own gate caught

`CH-TRN-MAP-4` initially failed with *"MapRendererFactory now has a TomTom renderer"*. It did have a
`TomTomRenderer` **branch** — but that class is a `PendingRenderer` placeholder reporting
`isAvailable() = false`. **A factory branch is not a working renderer.** I tightened the gate to read
the placeholder declarations and compare against them, so implementing a provider flips the
expectation automatically.

It then failed again on MapLibre, because `PROVIDER_MAPLIBRE` shared a Kotlin fall-through arm with
`PROVIDER_MAPTILER`, leaving no `->` to match. Rather than escalate the regex, I made the factory
**explicit — one arm per provider**. Same mapping, unambiguous to a reader and to tooling.

---

## 2. Correction 2 — the flake, root-caused

**It was my test process, not the product.**

The 43/1 and later 40/4 results came from **two `maps_config_probe` instances running concurrently
against the same tenant.** A 10-run background loop was still executing when I ran the probe in the
foreground. Each instance snapshots, rewrites and deletes `mapsConfig/{schoolId}` — so one run's
teardown deleted the document mid-test in the other. That explains both symptoms exactly:
`admin=''` (document deleted) and `E5='tomtom'` (interleaved writes).

The 40/4 runs had a second, separate cause: I changed the service's assignment rules **mid-loop**, so
the still-running old probe asserted `admin → google`, which the new rule correctly refuses.

**I ruled out the product explanations before concluding this**, rather than assuming:

| Hypothesis | Test | Result |
|---|---|---|
| `Firestore_service` caches reads | source read of `get()` / `getEntity()` | **No cache** — straight to the client |
| Write→read latency on `mapsConfig` | wrote, then read 6× over 1.5 s | **Correct at t+0ms** — no lag |
| `saveMode` doesn't persist assignments | two consecutive `saveMode` calls + read | **Persisted correctly** |

So `saveMode` is sound and there is no consistency problem. Good news, arrived at properly.

**Harness improvements — coverage unchanged, no assertion weakened:**
1. `auditHistory()` is an **ordered query**, not a point read, so a bounded retry (5 attempts over
   ~2 s) removes a genuine race. It still *requires* rows to appear and fails if they never do.
2. Probe expectations updated for the new assignment rules, using MapTiler where an admin-capable
   provider is needed — asserting the same two-providers-at-once coverage rather than accidentally
   testing a refusal.
3. **10 new assertions** covering platform-awareness (D9–D16, E8–E9), including the TomTom case that
   started this.

**Determinism: 17 consecutive isolated runs, all 54/54** (11 + 6). Up from 44 assertions.

---

## 3. Manual test result table

Unchanged from revision 1 in substance: **everything requiring a human is BLOCKED.**

| Area | Result |
|---|---|
| **§3 Admin A–Q** | **PASS (HTTP endpoint level)** — 35/35. **Visual UAT: BLOCKED.** Layout, button states, contrast and usability unverified |
| **§4 Runtime switching in the real app** | **BLOCKED** |
| **§5 Parent real-user (1–15)** | **ALL BLOCKED** |
| **§6 Teacher/driver real-user (1–15)** | **ALL BLOCKED** |
| **§7 End-to-end real-world** | **BLOCKED** — the most important test |
| **§8 Security / tenant isolation** | **PASS (rules + HTTP)** for secrets, server-owned doc, denial, school scoping. **BLOCKED (observed)** for "parent sees only their own vehicle" with two real accounts |
| **§9 Provider limitation** | Verified in code and probe. **Not verified visually** |
| **§10 Performance / UX** | **NOT TESTED** |

### Why it remains blocked

| # | Blocker | Evidence |
|---|---|---|
| B-1 | Device attachment is intermittent — you reconnected it, but it was **not attached** when I re-checked (`adb devices` empty) | re-verified this phase |
| B-2 | **The code is not where the apps point.** Both target `https://www.zenxii.com/` | prod `maps_config` → **404**; `transport_driver_api` → **403** |
| B-3 | No real admin / driver / parent credentials, and I must not create accounts | standing rule |
| B-4 | No live trip | `transport_gps_cron --dry-run` → `scanned=0` |

B-2 is structural: even with a device and logins, the apps would call production, where none of
M3/M4/Maps exists.

---

## 4. Defects

**No production defect.** Two issues found and fixed, both in my own work:

| # | Item | Severity | Status |
|---|---|---|---|
| 1 | TomTom assignable to a platform with no renderer | **Medium** — silently unusable config | **FIXED** (§1) |
| 2 | `maps_config_probe` non-determinism | Low — test tooling | **ROOT-CAUSED** as concurrent runs; harness hardened (§2) |
| 3 | `CH-TRN-MAP-4` gate imprecision — branch ≠ renderer; fall-through unmatched | Low — gate only | **FIXED** (§1) |

---

## 5. Regression (§3 of the brief)

| Suite | Result | vs baseline |
|---|---|---|
| **Parent compile** | **BUILD SUCCESSFUL** | unchanged |
| **Teacher compile** | **BUILD SUCCESSFUL** | unchanged |
| **Transport verifier** | **131 gates — 109 PASS / 16 INFO / 1 WARN / 5 FAIL** | PASS 108 → **109** (+1 gate) |
| **All 7 F13 gates** | **PASS** | unchanged |
| **All 4 MAP gates** | **PASS** (MAP-4 new) | +1 |
| `transport_probe_f13` | **79 / 0** | unchanged |
| `transport_probe_f13_e2e` | **29 / 0** | unchanged |
| `maps_config_probe` | **54 / 0**, ×17 runs | 44 → **54** assertions |
| `transport_probe_f7` | 7 / 0 | unchanged |
| `transport_probe_xmod1` | 3 / 0 | unchanged |
| `maps_config_httpprobe` | **35 / 0** | unchanged |
| **Firestore rules suite** | **9 suites, 211 / 211** | unchanged |

**The five FAIL are the identical pre-existing set** — `CH-TRN-F1-3`, `CH-TRN-F2-2`, `CH-TRN-F3-2`,
`CH-TRN-F7-2`, `CH-TRN-F7-3`. Same gate ids, no additions, no substitutions. **Not new failures.**

*(One WARN cleared between runs — `CH-TRN-F5-3`, a pending-post SLA timer. Tenant-data timing, not code.)*

### Untouched, as instructed

GPS lifecycle · `vehicleLivePositions` · `vehicleBreadcrumbs` · `tripLogs` · trip flow · security
rules (no rule weakened; 3 map blocks added earlier remain) · graded RBAC · F13.1 business logic.
Gate `CH-TRN-MAP-2` enforces the GPS/maps decoupling in both directions and passes.

---

## 6. Files changed in this phase

| File | Change |
|---|---|
| `libraries/Maps_config_service.php` | `renderers` per provider; `isSupportedOn`/`supportedPlatforms`/`isGlobalCapable`; assignment + global-mode enforcement; `supported` in client payload |
| `controllers/Maps_config.php` | `get_config` returns `availability` + `surfaces` |
| `views/school_config/maps_location.php` | availability chips; disabled+labelled unusable options |
| `controllers/Maps_config_probe.php` | +10 assertions; audit retry; MapTiler in place of Google |
| `controllers/Transport_verifier.php` | new gate `CH-TRN-MAP-4` |
| `parent/.../MapRendererFactory.kt` | explicit one-arm-per-provider mapping |
| `parent/.../MapProviderConfig.kt` | `isSupportedOn()` mirroring the server |

---

## 7. Next step — the real manual UAT

The single most important validation, still **NOT PERFORMED**:

```
Driver starts trip → phone obtains GPS automatically → GPS reaches Firestore
   → Parent sees the bus → driver moves → parent sees movement
   → driver completes/cancels → parent tracking stops
```

**I do not claim this has passed.**

To run it I need, together: the device connected · a real driver account with a Ready trip · a real
parent account whose child is on that trip · and the code reachable from the phone. The cheapest
route is the one proven in M3 — `adb reverse tcp:8080 tcp:80` against local code, production
untouched. The Parent debug build needs the same local-`BASE_URL` treatment the Teacher build already
has; I can add that on request.

### Remaining release activities

1. **Manual UAT** (above) 2. Commercial OSM tile provider account 3. Deploy: indexes → rules → code
4. Firestore TTL policy on `vehicleBreadcrumbs.expires_at` — manual gcloud step 5. Android TomTom
renderer, or leave it admin-only as now 6. Parent APK size check on the minified release

---

## 8. Verdict

**Automated: complete and green** — 131 gates, 211 rules tests, 207 probe assertions, both apps
compiling. Both corrections applied and independently verified.

**Manual: NOT PERFORMED / BLOCKED.**

I am not recommending the freeze. The automated layer proves internal consistency and that the
server half works end-to-end; it cannot prove that a driver's phone, a parent's phone and an
administrator's browser work correctly together.

---

**STOPPED. F13.1 is not frozen. No commit, push, merge or deployment.**
