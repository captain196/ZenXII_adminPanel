# Transport F13.2 — Admin Live Fleet Tracking · Implementation Report

**Date:** 2026-08-08
**Status:** Implemented. **MANUAL UAT = NOT PERFORMED.**
Nothing committed, pushed, merged or deployed. F13.1 remains unfrozen.

---

## 1. What was built

A real Admin fleet live-tracking system on top of the F13.1 GPS foundation. The old
"Compliance & Live Tracking Prototype" — a hardcoded `FLEET` array and a hand-drawn SVG map — is
**not** the basis for any of this and none of it survives.

| Layer | File | Status |
|---|---|---|
| Fleet read model | `libraries/Transport_fleet_service.php` | **NEW** |
| Endpoints + cache | `controllers/Transport_fleet.php` | **NEW** |
| Admin map + sidebar | `views/transport/fleet_live.php` | **NEW** |
| Runtime probe (42 assertions) | `controllers/Transport_probe_f13_2.php` | **NEW** |
| CSP map allowlist | `core/MY_Controller.php` | 2 directives |
| SVG markers + state colour | `assets/js/maps/map_renderer.js` | modified |
| Gates `CH-TRN-CSP-1`, `CH-TRN-F13-8` | `controllers/Transport_verifier.php` | +2 gates |
| Parent stop progress | `parent/…/TripProgress.kt` **NEW**, `MyBusScreen.kt` | Parent app |
| Driver stop progress | `teacher/…/TripProgress.kt` **NEW**, `DriverTripDetailScreen.kt` | Teacher app |
| Probe determinism | `controllers/Transport_probe_f13.php` | retry on a racy query |

---

## 2. Architecture

```
Driver app ──writes──▶ vehicleLivePositions ◀──reads── Transport_fleet_service
   (F13.1, untouched)   (canonical, ONE doc/vehicle)         │
                                                              ├─ tripLogs (InProgress only)
                        Admin browser ──poll 15s──▶ /transport_fleet/live
                              │                     (12s per-school cache)
                              └─ ZenxMapRendererFactory ──▶ Leaflet (or any provider)
```

`vehicleLivePositions` remains the **single** live-GPS source. The fleet service is a pure consumer:
it never writes telemetry, never validates a fix, never touches the trip lifecycle. Gate
`CH-TRN-F13-8` fails the build if it ever writes to that collection.

### Why the browser polls rather than subscribes

The admin panel has **no client-side Firebase SDK and no client Firebase config** (audited). A
snapshot listener would require exposing a Firebase web config and authenticating admins into
Firebase Auth — a whole new client-auth surface, far outside F13.2. So the map polls a PHP endpoint.

This costs nothing in freshness: F13.1 writes at most one position per 15 s per vehicle, so a 15 s
poll is matched to the source. Polling faster cannot surface data that does not exist yet.

---

## 3. Firestore reads and listeners — measured

**Two queries per snapshot, regardless of fleet size.** `vehicleLivePositions` is queryable on a
single `schoolId` field, so one query returns the whole fleet; `tripLogs` filtered to `InProgress` is
the second. Reference data (vehicles, routes, staff) is fetched in bulk and is small and
slow-changing.

Measured by the probe against 3 real active buses:

```
3 active bus(es) → 2 queries, 15 documents
≈ 5.0 documents per bus per poll · at 15s polling ≈ 3,600 docs/hour/school
```

| Active buses | Docs/poll | Docs/hour/school |
|---|---|---|
| 3 | 15 | ~3,600 |
| 20 | ~100 | ~24,000 |
| 100 | ~500 | ~120,000 |

**The 12-second server cache is the scalability mechanism**, and it is the difference between a
system that works at 3 buses and one that works at 100. Cost is **per school, not per open tab** —
ten administrators watching one fleet cost what one does. Without it, 100 buses × 10 tabs would be
1.2 M reads/hour.

Browser-side listeners: **zero** (no client SDK). The poll timer is paused on
`visibilitychange` and cleared on unload, so a backgrounded tab bills nothing.

---

## 4. Security

* **RBAC** — existing `Transport` module, graded at **`view`**. Watching a fleet is a read. No new
  RBAC module: `rbac_modules.json` is shared byte-for-byte with the `staffCapabilities` Cloud
  Function, so adding an entry is a cross-system change.
* **Tenant isolation** — every query is `schoolWhere`-scoped; the probe asserts no foreign-tenant
  vehicle can appear and that no tenant id is hardcoded.
* **Firestore rules** — **unchanged.** The admin path reads via the service account, and the existing
  `isSameSchool() && isStaff()` rule already covers staff reads. Nothing was weakened and nothing was
  made public.
* **Secrets** — the map receives only the resolved provider's *public* credentials through
  `client_config`; `mapsSecrets` stays deny-all.

### The CSP change (Option B, authorised)

`MY_Controller`'s CSP blocked map tiles, so the map would have rendered a working UI over a blank
grid. Two exact origins were added to **`img-src` and `connect-src` only**:

```
https://api.maptiler.com    https://api.tomtom.com
```

Derived from the code, not assumed. `map_renderer.js` builds exactly those two tile URLs.
**Deliberately not added:**

* `*.tile.openstreetmap.org` — no renderer constructs an OSM URL, and the public service is barred
  for production use anyway;
* any wildcard subdomain — both providers serve from these exact hosts;
* the JS CDNs — markers were switched to **SVG `circleMarker`** instead of Leaflet's default PNG icon
  *precisely so* `img-src` need not admit a CDN. That switch also carries the tracking-state colour.

Nothing was added to `script-src`, `style-src`, `default-src`, `font-src` or `media-src`, and there is
still exactly one CSP emitter. Verified on the live header. A second header could not have worked
anyway — **multiple CSP headers intersect**, so they can only ever narrow.

`CH-TRN-CSP-1` pins all of this, including that pre-existing Firebase/CDN origins survive.

---

## 5. Provider integration

`resolveProvider('admin')` via `/transport_fleet/map_config` → `Maps_config_service::getClientConfig`
→ `ZenxMapRendererFactory`. **No new provider setting, no hardcoded renderer, no new SDK.** Global and
Platform modes both apply, and admin-capable providers remain MapLibre / MapTiler / TomTom.

Missing or unavailable configuration fails **visibly** — an explicit message, never a blank grey map,
because a blank map is indistinguishable from one still loading.

---

## 6. UX behaviour

Bootstrap 3 / existing admin conventions. No Bootstrap 4/5, no redesign of the Transport module.

* **Counters** — active · live · stale · no signal · not tracking · behind schedule.
* **Map** — one SVG marker per actively-tracked bus, coloured by state, tooltip with vehicle + state.
* **Sidebar** — vehicle, driver, route, state badge, freshness, speed, stop counter.
* **Selection is synchronised** both ways; clicking a row focuses the map, and selection survives a
  refresh.
* **Detail panel** — trip, direction, tracking, speed, heading, next stop, stops done/remaining,
  progress, distance to next, schedule, on-board count.
* **Only the changed vehicle is patched.** Markers are keyed on `lat,lng,state`, and list rows are
  reused, so a GPS update never re-renders the fleet table and never resets the operator's scroll.

### Honest states, never fake data

no active trips · trip running but no fix yet · stale · no signal · provider unconfigured · provider
unavailable · permission denied · network failure · trip completed or cancelled while open. A trip
that ends removes its marker and clears the selection. **No fallback to sample data anywhere.**

### Diagnostics

The status line shows last update, active count, cached/fresh + cache age, documents read, and poll
count — useful for an administrator, and it exposes no credential.

---

## 7. What is computed, and what is deliberately not

**Measured from stored facts:**

| Value | Source |
|---|---|
| Position, speed, heading | canonical hot doc — **last reported fix only, never extrapolated** |
| Freshness bands | `recorded_at_ms`; `STALE_AFTER_S = 120` **reuses** F13.1's threshold so admin and parent cannot disagree |
| Current / next stop, passed, remaining, "N stops away" | `checkpoints[].checkpoint_state` — **driver-confirmed**, never GPS proximity |
| Progress % | checkpoint counts |
| Distance to next stop | `gf_haversine(live, checkpoint.stop_location)`, labelled "straight line" |
| Schedule delta | now vs `scheduled_pickup_time`, frozen onto the trip at creation |

**ETA is NOT implemented.** It needs travel-time modelling that does not exist, and
`distance ÷ speed` is worst exactly where it matters — a bus at a red light reports 0 km/h and would
imply it never arrives. The person who suffers a wrong number is the parent timing a walk to the
stop. Deferred to F13.3. `CH-TRN-F13-8` fails if an `eta` field or that arithmetic appears.

**No dead reckoning.** Smoothing between two *real* fixes is a renderer concern; nothing projects
beyond the last reported position.

**"N stops away" uses driver-confirmed arrivals**, so a bus passing within 50 m of a stop it did not
serve is never counted as having served it.

---

## 8. Parent and Teacher slices

Both reuse the same rules as the admin service, so all three surfaces agree about which stop a bus is
on.

* **Parent** (`TripProgress.kt`, `MyBusScreen`) — "N stops away from your stop", "Arriving at your
  stop next", "Your stop has been served", plus stop N of M and next stop. Uses the child's own
  `studentRoute.stopId`. **The F13.1 GPS listener is untouched.**
* **Teacher** (`TripProgress.kt`, `DriverTripDetailScreen`) — "Stop N of M · next: X" above the
  existing checkpoint list. **No map**, no ETA.

Each returns `null` when there is nothing honest to say, so the UI renders nothing rather than filler.

---

## 9. Automated test results

| Suite | Result |
|---|---|
| **`transport_probe_f13_2`** (new) | **42 / 0** |
| `transport_probe_f13` | **79 / 0** |
| `transport_probe_f13_e2e` | **29 / 0** |
| `maps_config_probe` | **54 / 0** |
| `maps_config_httpprobe` | **35 / 0** |
| `transport_probe_f7` · `xmod1` | 7 / 0 · 3 / 0 |
| **Firestore rules** | **20 suites, 442 / 442** |
| **Parent compile** | **BUILD SUCCESSFUL** |
| **Teacher compile** | **BUILD SUCCESSFUL** |
| **Verifier** | **133 gates — 111 PASS / 15 INFO / 1 WARN / 6 FAIL** |
| **All 13 F13 / MAP / CSP gates** | **PASS** |

The F13.2 probe covers: active trip appears · Ready trip does not · completed disappears · cancelled
disappears · real stored position consumed · correct vehicle moves · buses independent · freshness
bands incl. the 120/121 s boundary · driver-confirmed progress · "N stops away" incl. a stop not on
the route · tenant isolation · read budget measured.

Source-level requirements are pinned by `CH-TRN-F13-8`: no sample fleet, no hardcoded coordinates, no
duplicate GPS collection, no RTDB, no map SDK in the service, no ETA, graded `Transport:view`,
server cache present, visibility-aware polling with teardown.

### Three findings worth stating plainly

**1. The verifier now shows 6 FAIL, not 5 — and it is not a regression.** The extra one is
`CH-TRN-F5-3`, a **time-based expense gate**: *"2 stale >24h pending — run
transport_expense_reconciler: TEXP000047, TEXP000053"*. Across this session it went WARN ×5 → PASS →
FAIL purely with the clock, and those are **different documents** from the earlier TEXP000011/14/17.
F13.2 added no expense code. It is not an F13 gate, and the remedy is the existing
`transport_expense_reconciler` — which posts to Accounting, so I did not run it unilaterally.
**All 13 F13/MAP/CSP gates PASS.**

**2. The rules suite reports 442, where earlier runs reported 211.** Not double-counting — 20 distinct
files each ran once. There are exactly **9 tracked** test files and **11 untracked** ones, and the
untracked set (including `f13_gps_sec.test.js` and `maps_config_sec.test.js`, which cover the rule
blocks added in this work) **appeared in the tree after my earlier run**. The project notes warn this
tree is edited by other sessions. My earlier "211/211" was accurate for the 9 files that existed
then; the correct current figure is **442/442**.

**3. A Parent build failed once with a JVM crash**, not a compile error — "daemon disappeared
unexpectedly", zero `e:` errors. I had the verifier and six probes running in the foreground while
Gradle built on a 5.8 GB machine. Re-run in isolation: **BUILD SUCCESSFUL**. The project's own note
("build ONE app at a time") extends to not running the PHP suites alongside.

Also hardened: `transport_probe_f13` §G queried `students` immediately after write-heavy work and
could come back short, silently skipping G3/G4 and reporting 77 instead of 79. Same query-read race,
same bounded-retry fix as `maps_config_probe` — no assertion weakened.

---

## 10. F13.1 preserved

**No F13.1 contract was changed.** Untouched: GPS telemetry schema · `vehicleLivePositions` ·
`vehicleBreadcrumbs` · `tripLogs` · anti-spoofing · ordering · kill switch · driver foreground
service · canonical driver lifecycle · D-1 · D-8 · the Maps security model. `CH-TRN-MAP-2` enforces
the GPS/maps decoupling in both directions and passes.

---

## 11. Known limitations

1. **Not a push stream.** Up to 15 s + 12 s cache latency, so a position can be ~27 s old at worst.
   Matched to the GPS write cadence, but it is not sub-second.
2. **Distance to next stop is straight-line**, not road distance. Labelled as such in the UI.
3. **Schedule delta needs stop times.** Routes without `pickup_time`/`drop_time` report `unknown`
   rather than guessing.
4. **Admin-capable providers are MapLibre / MapTiler / TomTom.** Google, Mapbox and HERE remain
   placeholders and show the "not available" notice.
5. **A custom `tile_url`** entered in Maps settings on some other host will be **blocked by CSP** —
   this is the §21 case. Reported rather than wildcarded; tell me the origin and I will add it
   narrowly.
6. **One trip per vehicle** is assumed for the live view. Two concurrent InProgress trips on one
   vehicle would show the last one read — the data model does not currently forbid that.

## 12. Deferred to F13.3

ETA prediction · geofence auto-arrival · automatic stop-arrival events · driver-behaviour scoring ·
AI route prediction. **Trip replay** also remains deferred: `vehicleBreadcrumbs` supports it and the
index exists, but breadcrumb availability depends on a TTL policy that is still an unperformed manual
deploy step, and mixing a replay timeline into the live map risks a historical position being read as
live. The service is structured so replay can read breadcrumbs through the same shape later.

---

## 13. MANUAL UAT = NOT PERFORMED

**No human has seen a bus on this map.** Automated tests, probes, compilation and static verification
are **not** manual UAT, and nothing in this report should be read as claiming otherwise.

Specifically **not** validated by a person: the admin map rendering tiles · a marker at a real
vehicle's position · a marker moving as GPS arrives · the sidebar/map selection sync in a browser ·
provider switching visually · driver GPS on real hardware · parent live tracking on a real phone.

The full procedure is in **`TRANSPORT_F13_2_UAT_GUIDE.md`**.

---

**Nothing committed, pushed, merged or deployed.**
