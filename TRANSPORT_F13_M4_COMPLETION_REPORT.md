# Transport F13.1 — Milestone M4 Completion Report

**Date:** 2026-08-08
**Milestone:** M4 — Parent Live Map (MapLibre, provider-agnostic)
**Status:** **COMPLETE — stopped for approval.**

> Release constraints honoured: no commits, no rebases, no release branch, no push, no deployment.

**Repos touched:** `SchoolSyncParent` (app) and `ZenX/school` (one new verifier gate).

---

## 1. What M4 delivers

| # | Deliverable | File |
|---|---|---|
| 1 | Google Maps **removed**, MapLibre added | `app/build.gradle.kts` |
| 2 | Tile style injected at build time (D-7) | `app/build.gradle.kts` |
| 3 | Live-position model | **NEW** `VehicleLivePositionDoc.kt` |
| 4 | Single-document snapshot listener | `TransportFirestoreRepository.kt` |
| 5 | **Map composable** — smooth marker, lifecycle-correct | **NEW** `LiveBusMap.kt` |
| 6 | Five-state live-tracking card + subscription scoping | `MyBusScreen.kt` |
| 7 | Gate `CH-TRN-F13-7` | `Transport_verifier.php` (admin) |

---

## 2. Provider-agnostic, and enforced

The three Google artifacts added at F10 in anticipation of this phase were **removed**:
`play-services-maps:19.0.0`, `maps-compose:4.4.1`, `play-services-location:21.3.0`. No source file
ever referenced them — verified before deleting — so this is a dependency swap with **no functional
change**. `play-services-location` went too: the parent watches the bus, it does not share its own
position, so the app needs **no location permission at all**.

Map rendering is confined to **one file**, `LiveBusMap.kt`. Everything above it — the model, the
repository, the ViewModel, the screen — speaks plain latitude and longitude. Swapping MapLibre for
any other renderer means rewriting that single file.

`CH-TRN-F13-7` now enforces this rather than trusting it. It scans the Parent repo from the admin
verifier (a PHP-only gate would be blind to an app regression) and fails if: a Google Maps
dependency returns, a map SDK type appears in **any** file other than `LiveBusMap.kt`, the live
position stops coming from the canonical hot document, RTDB appears, or a tile URL is hardcoded.

---

## 3. D-7 — tile hosting, resolved so it fails loudly

MapLibre renders tiles; it does not serve them. The style URL and its key come from a commercial OSM
provider, injected at build time and never committed:

```
./gradlew assembleRelease -PZENXII_MAP_STYLE_URL="https://…/style.json?key=…"
```

**The default is deliberately empty, not a public-OSM URL.** An unconfigured build shows an explicit
*"Map isn't configured for this build"* instead of silently rendering blank grey tiles — or quietly
violating the OSM Foundation's usage policy, which excludes heavy and commercial use and permits
blocking without notice. A missing key should be obvious at a glance, not diagnosed weeks later from
a support ticket. Verified: `BuildConfig.MAP_STYLE_URL = ""` in this build, and the gate fails the
build if a URL is ever hardcoded.

**Still needs you:** a provider account (MapTiler, Stadia, Thunderforest or Carto). That is
procurement, not code, and it is the one thing standing between this and a rendering map.

---

## 4. Smooth marker updates

Positions arrive at most once every 15 seconds. Snapping between two points that far apart reads as
the bus teleporting, so each new fix is animated from the marker's current position over one second.

**Deliberately not dead-reckoning.** Extrapolating along the heading between fixes would draw the bus
somewhere it has not been reported to be. On a map a parent uses to decide when to walk their child
to the stop, that is a safety claim the data does not support. The interpolation is purely cosmetic:
the marker only ever moves *between two real, reported positions*.

Linear interpolation on lat/lng — over a 15-second bus hop the error against a great-circle path is
well under a pixel at street zoom.

`MapView` is an Android View with its own lifecycle contract, so the composable forwards
start/resume/pause/stop/destroy; omitting those leaks the GL surface and leaves the tile fetcher
running in the background.

---

## 5. Stale handling — five states, not one

"No map" has several very different causes, and collapsing them into one "unavailable" message would
leave a parent unable to tell *"the bus hasn't left yet"* from *"we've lost the signal"*:

| State | Copy |
|---|---|
| No active trip | "No trip running right now. Tracking starts when the bus departs." |
| Doc absent / kill switch off | "Live tracking isn't available for this bus right now." |
| Session open, no fix yet | "Waiting for the first location from the bus…" |
| Fix older than 120 s | "Last seen N min ago. Signal may be weak in this area." |
| Live | the map, plus current speed |

The staleness threshold mirrors the server's `STALE_AFTER_S = 120` — generous against the 15-second
cadence on purpose, since a tunnel or a passing lorry should not flap the UI to "offline".

One deliberate ambiguity: a **missing document** and an **unauthorised read** are indistinguishable
to the client, because the rule denies the read rather than returning an empty document. The copy
therefore never promises a position exists — and absence is never rendered as an error, because for
most of the day absence is simply the truth.

---

## 6. Read budget — the subscription is tightly scoped

The listener attaches to **exactly one document** — never a query — and only while the trip is
`InProgress`. It is cancelled on trip end, on reload (re-entrant, so two listeners can never sit on
one document), and in `onCleared()`.

That scoping *is* the budget control. A listener left attached across the school day would bill a
read for every position of every bus a family follows, for hours. The design's stated cap — one
document read per position change, while watching — only holds because of this.

---

## 7. Validation

| Check | Result |
|---|---|
| **Parent app compile** | **BUILD SUCCESSFUL** — `app-debug.apk`, 72.6 MB, `LiveBusMap` → 9 classes |
| **Full verifier** | **127 gates — 105 PASS / 15 INFO / 2 WARN / 5 FAIL** |
| **All 7 F13 gates** | **PASS**, including the new `CH-TRN-F13-7` |
| `transport_probe_f13` | **79 / 0** |
| `transport_probe_f13_e2e` | **29 / 0** |
| `transport_probe_f7` (trip lifecycle) | 7 / 0 |
| `transport_probe_xmod1` (cross-module) | 3 / 0 |
| `BuildConfig.MAP_STYLE_URL` | `""` — unconfigured build fails visibly, as designed |

### Verifier — M3 → M4

| | After M3 | After M4 |
|---|---|---|
| Gates | 126 | **127** (+1) |
| PASS | 98 | **105** |
| FAIL | 5 | 5 — **identical set** |

`F1-3`, `F2-2`, `F3-2`, `F7-2`, `F7-3` — the same pre-existing tenant-data artifacts throughout.
No gate went PASS → FAIL at any point in F13.

---

## 8. Impact analysis

| Area | Assessment |
|---|---|
| **Server** | Unchanged by M4 except one added verifier gate. No PHP, no rules, no indexes, no schema. |
| **Firestore** | **Zero schema change.** M4 only *reads* the hot document M1/M2 already defined. |
| **Firestore-only** | Held. The parent transport surface has no RTDB; the gate checks. |
| **Frozen F1–F12.1** | Untouched. |
| **Teacher app** | Untouched by M4. |
| **Parent app — other features** | The removed deps were unused, so fees, events, history, preferences and notices are unaffected. Compile confirms. |
| **Permissions** | **None added.** The parent needs no location permission — a genuinely smaller privacy footprint than the Google Maps path would have required. |
| **APK size** | 72.6 MB debug (unminified, unsplit). MapLibre's native renderer is the bulk; release minification and ABI splits apply as before. Worth a look before ship, but not an M4 defect. |
| **Tenant isolation** | The doc id is `{schoolId}_{vehicleId}` from the parent's own token claim, and the rule additionally requires the uid to be in the server-maintained viewer allowlist. |
| **Read budget** | One document, one listener, only while InProgress (§6). |

---

## 9. Cross-module review

| Surface | Verdict |
|---|---|
| **Admin panel** | One new gate. No behaviour change. |
| **Teacher app** | No change. It writes the hot doc; the parent reads it. The contract between them is the M1 schema, unchanged. |
| **Parent ↔ server contract** | `VehicleLivePositionDoc` mirrors `Transport_gps_service` field-for-field, including `recorded_at_ms` as the ordering/staleness key. Gate F13-7 pins both. |
| **Fees / Accounting / Communication** | Untouched. |
| **Security rules** | Unchanged in M4. The parent read path was already written in M1 and is exercised, not modified. |

---

## 10. Open items

| Item | Status |
|---|---|
| **Commercial tile provider account** | **Required before the map renders.** Procurement, not code. Injected via `-PZENXII_MAP_STYLE_URL`. |
| **Parent on-device validation** | Not run. Same constraint as the Teacher app: it needs a parent login whose child is on an actively-running trip. Compile + gates are green; the live map has not been seen on a screen. |
| **Teacher UI-level validation** | Deferred by you to pre-release. |
| **Firestore TTL policy** | Operator step, `DEPLOY_RUNBOOK.md` Phase 2b. |
| **Nothing deployed** | Rules, indexes and code all await authorization. Until the rules deploy, the parent read rule and the ordering guard are not live. |

---

## 11. F13.1 status

M1 data foundation · M2 server lifecycle · M3 canonical lifecycle + driver GPS · **M4 parent live
map** — all complete. F13.1 is feature-complete pending: a tile provider, the two deferred UI
validations, and deployment.

Deferred by decision D-5 and unchanged: admin fleet map and trip replay (F13.2), geofence
automation, ETA prediction and driver-behaviour scoring (F13.3).

---

**Stopped for approval. No deployment.**
