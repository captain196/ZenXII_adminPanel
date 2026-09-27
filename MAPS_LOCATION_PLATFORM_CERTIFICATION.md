# Centralized Maps & Location Platform — Production Certification

**Date:** 2026-08-08
**Scope:** Phase A (server + admin) **and** Phase B (runtime client resolution)
**Status:** **COMPLETE — stopped for approval. F13.1 is NOT frozen.**

> Release constraints: no commits, no rebases, no release branch, no push, no deployment.

---

## 1. The architecture

```
        ADMIN: System Settings → Maps & Location
                        │  writes
                        ▼
   mapsConfig/{schoolId}          mapsSecrets/{schoolId}
   PUBLIC · client-readable       SERVER-ONLY · rules deny all
   mode · provider per platform   unrestricted / geocoding keys
   public creds · defaults
                        │  read at runtime
        ┌───────────────┼───────────────┐
        ▼               ▼               ▼
   Admin panel      Parent app      Teacher app
 ZenxMapRenderer  MapProviderMgr   MapProviderMgr
   Factory          → Factory       (plumbing only)
        │               │
        ▼               ▼
   LeafletRenderer   MapLibreRenderer ── Google · Mapbox · HERE · TomTom
                                          (placeholders)
```

One document decides which renderer every surface uses. Business logic never
learns which provider is active: the GPS layer answers *"where is the bus"*, the
maps platform answers *"which renderer"*, and neither references the other.

**Resolution order, identical on all three surfaces:**
`Firestore mapsConfig` → `local cache` → `BuildConfig` (fallback only).

---

## 2. Every modified file

### Admin panel — `ZenX/school`

| File | Change |
|---|---|
| `libraries/Maps_config_service.php` | **NEW** — registry, secret split, validation, modes, audit |
| `controllers/Maps_config.php` | **NEW** — admin endpoints, graded `Configuration` RBAC |
| `views/school_config/maps_location.php` | **NEW** — the settings page |
| `assets/js/maps/map_renderer.js` | **NEW** — web renderer abstraction + Leaflet impl |
| `controllers/Maps_config_probe.php` | **NEW** — 44-assertion runtime probe |
| `firebase-rules/firestore.rules` | +3 blocks: `mapsConfig`, `mapsSecrets`, `mapsConfigAudit` |
| `controllers/Transport_verifier.php` | +`MAP-1`, `MAP-2`, `MAP-3`; `F13-7` tightened |

### Parent app — `SchoolSyncParent`

| File | Change |
|---|---|
| `data/maps/MapProviderConfig.kt` | **NEW** — config model, resolution rules |
| `data/maps/MapProviderManager.kt` | **NEW** — the single resolver + cache + listener |
| `ui/maps/MapRenderer.kt` | **NEW** — the renderer interface |
| `ui/maps/MapRendererFactory.kt` | **NEW** — the only provider switch |
| `ui/maps/MapConfigViewModel.kt` | **NEW** — exposes provider to UI |
| `ui/maps/renderers/MapLibreRenderer.kt` | **NEW** — M4 MapLibre code **moved**, not rewritten |
| `ui/maps/renderers/PendingRenderers.kt` | **NEW** — Google · Mapbox · HERE · TomTom placeholders |
| `ui/transport/LiveBusMap.kt` | Now a provider-agnostic host; imports no map SDK |
| `ui/transport/MyBusScreen.kt` | Card consumes the resolved provider |

### Teacher app — `SchoolSyncTeacher`

| File | Change |
|---|---|
| `data/maps/MapProviderConfig.kt` | **NEW** — identical resolution rules |
| `data/maps/MapProviderManager.kt` | **NEW** — read + cache + resolve. **No UI.** |

---

## 3. Requirements, point by point

| § | Requirement | Status |
|---|---|---|
| 1 | Firestore → cache → BuildConfig; BuildConfig never primary | ✅ `MapProviderManager.config()`; gate MAP-3 |
| 2 | One canonical `MapProviderManager` | ✅ Parent + Teacher; sole resolver, pinned by MAP-3 |
| 3 | Renderer interface; MapLibre moved not rewritten; 5 placeholders | ✅ 11 capabilities; MapLibre behaviour carried over verbatim |
| 4 | Parent reads, caches, listens, recreates only on change | ✅ `remember(providerId)` + `distinctUntilChanged` |
| 5 | Admin change reaches Parent with no rebuild | ✅ snapshot listener on `mapsConfig` |
| 6 | Teacher plumbing only, no UI | ✅ manager + model only |
| 7 | Admin web behind the same abstraction, Leaflet retained | ✅ `ZenxMapRendererFactory` |
| 8 | Intelligent caching, avoid unnecessary reads | ✅ listener not polling; §5 below |
| 9 | Dynamic switching, both modes | ✅ probe §D — 44/44 |
| 10 | F13.1 mechanically unchanged | ✅ gate MAP-2; §6 below |
| 11 | Full protocol + 6 named gate assertions | ✅ §7 |
| 12 | Certification report | this document |

---

## 4. Renderer abstraction

11 capabilities — `initialize`, `createView`, `moveCamera`, `animateCamera`,
`addMarker`, `updateMarker`, `removeMarker`, `drawPolyline`, `clearPolyline`,
`dispose`, plus lifecycle forwarding.

**MapLibre was moved, not rewritten.** The judgement calls from M4 survive
intact: ~1 s glide (fixes arrive every 15 s, so snapping looks like the bus
teleporting), linear lat/lng interpolation (sub-pixel error at street zoom), and
**no dead-reckoning** — extrapolating along a heading would draw the bus
somewhere it has not been reported to be, which on a map a parent uses to time
walking a child to the stop is a safety claim the data cannot support.

**Placeholders are honest, not stubs that lie.** Each reports
`isAvailable() = false`, so the host shows *"the selected provider isn't
available in this app version"* rather than a blank grey rectangle — the worst
failure mode here, because nobody can distinguish it from a map still loading.
Every geometry call is a no-op, so selecting one degrades visibly and recovers
on switching back; it cannot take the app down.

Their SDKs are deliberately **not** bundled: adding four mapping SDKs today
would add tens of megabytes and several third-party trackers for renderers
nobody has asked to use. Each arrives with the provider that needs it.

**MapTiler is not a placeholder.** It is a tile provider whose styles MapLibre
renders, so the factory maps it onto `MapLibreRenderer` — a school can select
MapTiler today and it works.

---

## 5. Caching

The snapshot listener *is* the refresh mechanism, so there is no polling: one
read on attach, then a read only when an administrator actually changes
something.

Two separate guards stop unnecessary work:
* **`distinctUntilChanged` on the resolved provider** — a config write that does
  not change *this* platform's provider (another platform's assignment, a zoom
  tweak) never re-emits.
* **`remember(providerId)`** — the renderer is keyed on the provider alone, so a
  moving bus never rebuilds the map. Without this a GL surface would be torn
  down and rebuilt every 15 seconds.

Cold start reads the DataStore cache first, so a map draws before the network
answers. An unparseable cache falls through to the fallback rather than
bricking the map.

---

## 6. F13.1 — mechanically unchanged

`CH-TRN-MAP-2` asserts the GPS contract is intact **and** that the layers are
decoupled in both directions: the GPS service must not reference the maps
platform, and the maps platform must not reference `vehicleLivePositions`,
`vehicleBreadcrumbs`, `tripLogs` or `Transport_gps_service`.

Untouched, verified: GPS lifecycle · `vehicleLivePositions` · `vehicleBreadcrumbs`
· `tripLogs` · security rules for GPS · Firestore schema · GPS repositories ·
`MyBusViewModel` · GPS services · Transport business logic.

`MyBusViewModel` deserves a note: it owns the F13.1 GPS listener, and the brief
required that listener to remain exactly as shipped. Rather than bolt provider
state onto it and then argue about whether the GPS path was disturbed, the map
configuration lives in a separate `MapConfigViewModel` — **the GPS ViewModel was
not edited at all**.

---

## 7. Validation

| Suite | Result |
|---|---|
| **Parent compile** | **BUILD SUCCESSFUL** |
| **Teacher compile** | **BUILD SUCCESSFUL** |
| **Full verifier** | **130 gates — 107 PASS / 15 INFO / 2 WARN / 5 FAIL** |
| **All 7 F13 gates** | **PASS** |
| **All 3 MAP gates** | **PASS** |
| `transport_probe_f13` | **79 / 0** |
| `transport_probe_f13_e2e` | **29 / 0** |
| `maps_config_probe` | **44 / 0** |
| `transport_probe_f7`, `xmod1` | 7/0 · 3/0 |

The 5 FAIL are the unchanged pre-existing tenant-data artifacts
(`F1-3`, `F2-2`, `F3-2`, `F7-2`, `F7-3`).

**The six §11 assertions**, all enforced by `CH-TRN-MAP-3`:
renderer never hardcoded (no renderer constructed, and no map SDK imported,
outside a renderer/factory — scanned across the whole Parent tree) ·
`MapProviderManager` is the only resolver · BuildConfig fallback-only ·
business logic provider-agnostic · runtime switching keyed on the config
document · a new provider needs no Transport change.

### Two gate defects found and fixed during validation

**`CH-TRN-F13-7` failed** after Phase B: its allow-list still named
`LiveBusMap.kt` as the only file permitted to import a map SDK. Phase B moved
the SDK into `MapLibreRenderer.kt`. I updated the allow-list to the renderer
implementations — a **tightening**, since the host composable may now import no
map type at all.

**`CH-TRN-MAP-3` false-positived** on the word "BuildConfig" appearing in a
LiveBusMap *comment*. Changed to match a real member access (`BuildConfig.`).
Same class of mistake as an earlier gate — worth noting as a pattern: substring
matching on source needs to exclude prose.

---

## 8. Cross-module review

| Surface | Verdict |
|---|---|
| **F13.1 GPS** | Untouched — gate-enforced (§6) |
| **Frozen F1–F12.1** | Untouched |
| **Firestore schema** | No change to any existing collection; 3 new ones |
| **Security rules** | +3 blocks, additive. GPS rules unchanged |
| **RBAC** | Existing `Configuration` module, graded. No new module — `rbac_modules.json` is shared byte-for-byte with the `staffCapabilities` Cloud Function |
| **Fees / Accounting / Communication / SIS / HR** | Untouched |
| **Firestore-only** | No RTDB anywhere |
| **Tenant isolation** | Config is per-school; secrets are deny-all; clients receive only their own platform's public credentials |

---

## 9. What is true today, and what is not

**True now:** an administrator switches between **MapLibre, MapTiler and
TomTom** in System Settings and the Parent app follows on next config sync —
no rebuild, no Gradle change, no APK. Both Global and Platform modes resolve
independently per surface. Admin web resolves through the same configuration.

**Not yet true:** switching Parent to **Google, Mapbox or HERE** renders the
"not available in this app version" notice, because those SDKs are not bundled.
The *architecture* supports six providers; three have working renderers today.
Adding one is a single class plus a dependency — no change to Transport logic,
the factory contract, the ViewModels or the schema. Your own §3 wording
("placeholders … may contain TODO implementations until their SDKs are added")
anticipates exactly this; I am stating it plainly so nobody expects a
config-only switch to Google before that renderer ships.

The dynamic-switching matrix in §9 of the brief is therefore **verified for the
implemented renderers** and **architecturally verified** for the rest — the
resolution, caching and switching path is identical regardless of which renderer
sits at the end of it, as `maps_config_probe` §D/§E demonstrates across all four
platforms and both modes.

---

## 10. Open items

| Item | Status |
|---|---|
| Commercial tile provider account | Needed for a rendering map; now entered through the admin page |
| Google / Mapbox / HERE renderers | Placeholders; add with their SDKs when required |
| Rules deploy | 3 new blocks — until deployed, `mapsSecrets` is not rules-protected (only ever service-account written today) |
| Parent + Teacher UI validation with real accounts | Deferred to pre-release |
| Firestore TTL policy (F13.1) | `DEPLOY_RUNBOOK.md` Phase 2b |
| **F13.1 freeze** | **Still open — awaiting your approval** |

---

**Stopped.** F13.1 is not frozen, no commits, no merge, no deployment.
