# Transport F13.2 — Architecture Audit (pre-implementation)

**Date:** 2026-08-08
**Purpose:** establish what the real Admin Fleet Live Map can be built from, and what cannot be built
honestly, **before** any code is written.

> F13.1 is NOT frozen. Nothing committed, pushed, merged or deployed.

---

## 1. The finding that shapes everything

**The admin panel has no client-side Firebase SDK and no client-side Firebase configuration.**

| Check | Result |
|---|---|
| `firebase-app` / `firebase-firestore` / `gstatic.com/firebasejs` in views or assets | **none** |
| `apiKey` / `authDomain` / `projectId` exposed to the browser | **none** |
| How admin pages get data today | server-rendered PHP + AJAX to PHP endpoints, which read Firestore via the **service account** |

**Consequence: the admin browser cannot open a Firestore snapshot listener.** There is no client
credential to open one with, and creating one would mean exposing a Firebase web config and
authenticating admins into Firebase Auth — a new auth surface, well outside F13.2.

So the brief's §7 concern ("one uncontrolled Firestore listener per school-day session") cannot arise
in the browser. The real question becomes **how the PHP endpoint reads, and how often the browser
asks** — answered in §4.

This is reported rather than worked around, because it changes the delivery mechanism from
"subscribe" to "poll a server endpoint", and that difference must be visible in review.

---

## 2. What F13.1 already gives us — verified, not assumed

### 2.1 `vehicleLivePositions/{schoolId}_{vehicleId}` — the live position

Server-created (D-1), driver-updated, one doc per vehicle, ≤1 write/15s. Carries:
`lat` · `lng` · `speed_kmph` · `heading` · `accuracy_m` · `altitude_m` · `recorded_at` ·
**`recorded_at_ms`** · `is_mock` · `source` · `tripId` · `gps_session_id` · `driver_staff_id` ·
`live_tracking_enabled` · `authorized_viewer_uids` · `session_started_at`.

**Queryable by tenant in ONE call:** `schoolWhere('vehicleLivePositions', [])` — a single-field
`schoolId` filter, so no composite index is needed, and the existing rule
`isSameSchool() && isStaff()` satisfies it for admin staff. **One query returns the whole fleet.**

### 2.2 `tripLogs` — status, identity and progress

`status` (Ready / InProgress / Completed / Cancelled) · `vehicleId` · `driver_staff_id` · `route_id` ·
`trip_direction` · `roster_size` · `boarded_count` · `route_progress_pct` · `next_checkpoint_id` ·
`gps_session_id` · `live_tracking_enabled` · `gps_start_at` / `gps_end_at`.

### 2.3 `tripLogs.checkpoints[]` — the reason most of §8 is buildable

Each checkpoint, frozen at `createTrip`, carries:

```
stop_id · stop_name · order
scheduled_pickup_time · scheduled_drop_time      ← the schedule, frozen onto the trip
stop_location { lat, lng }                        ← planned coordinates
geofence_radius_m
checkpoint_state (Pending|Reached|Skipped) · reached_at · left_at
students_boarded_count
```

This is a complete, ordered, scheduled itinerary **on the trip document itself** — no join needed at
read time.

### 2.4 Reusable primitives

`geofence_helper.php` → `gf_haversine()`, `gf_valid_coord()`. So distance from the live position to
the next stop's planned coordinates is a **measured** number, not an estimate.

---

## 3. Capability-by-capability: buildable vs deferred

| Capability | Verdict | Basis |
|---|---|---|
| Active buses only | **BUILD** | `tripLogs.status == 'InProgress'` |
| Live position, speed, heading | **BUILD** | canonical hot doc |
| Vehicle number, driver, route | **BUILD** | `vehicles`, `staff`, `routes` |
| GPS freshness — LIVE / STALE / NO SIGNAL / NOT TRACKING | **BUILD** | `recorded_at_ms` + F13.1's `STALE_AFTER_S = 120`, plus `live_tracking_enabled` |
| Current stop / next stop | **BUILD** | `checkpoints[]` state + `next_checkpoint_id` |
| Passed / remaining stop counts | **BUILD** | count by `checkpoint_state` |
| Route progress % | **BUILD** | `route_progress_pct`, cross-checked against checkpoint counts |
| **"N stops away"** | **BUILD** | count of `Pending` checkpoints before the target stop. Uses **driver-confirmed arrivals**, not GPS proximity guessing |
| Distance to next stop | **BUILD** | `gf_haversine(live, checkpoint.stop_location)` — a measured distance |
| **Schedule adherence / late** | **BUILD** | `scheduled_pickup_time` of the next Pending stop vs now; and `reached_at` vs scheduled for stops already done |
| **ETA in minutes** | **DEFER → F13.3** | see §3.1 |
| Geofence auto-arrival | **DEFER → F13.3** | brief §16 |
| Driver-behaviour scoring | **DEFER → F13.3** | brief §16 |
| Trip replay | **ARCHITECTURE ONLY** | see §3.2 |

### 3.1 Why ETA is deferred rather than approximated

An ETA needs travel time, which needs either historical segment timings or a routing/traffic service.
Neither exists. The only computable substitute is `distance ÷ current_speed`, which on a school route
is actively misleading — a bus stopped at a light reports 0 km/h and would show an infinite ETA; a bus
on a clear stretch would promise an arrival it cannot make through the next three stops.

A parent using an ETA to decide when to walk a child to the stop is the exact case where a wrong
number is worse than no number. So F13.2 shows **distance to next stop** and **schedule delta** — both
measured — and no predicted arrival time. This satisfies §8's "do not invent ETA" by declining to.

### 3.2 Trip replay

`vehicleBreadcrumbs` supports it: append-only, one doc per accepted fix, indexed
`(schoolId, gps_session_id, recorded_at)`. So the data and index already exist.

**But it needs a decision I will not make unilaterally:** breadcrumbs are TTL-expired at 30 days and
the TTL policy is still an unperformed manual deploy step, so replay availability silently depends on
an operator action. Mixing a replay timeline into the live map also risks the worst possible
confusion — a historical position mistaken for a live one.

**F13.2 therefore ships live only, and leaves replay to its own slice.** The service is structured so
replay reads breadcrumbs through the same shape, without touching live telemetry.

---

## 4. Live-data architecture (given §1)

```
browser (admin)                    PHP                        Firestore
──────────────                     ───                        ─────────
poll /transport_fleet/live   →   Transport_fleet_service   →  1 query: vehicleLivePositions (schoolId)
   every 15s, visible only         + short server cache      →  1 query: tripLogs (status=InProgress)
   paused when tab hidden          (~10s TTL, per school)    →  cached: vehicles / routes / staff
   patch only changed rows
```

**Why 15 s is not "high-frequency polling":** the GPS layer writes at most once per 15 s per vehicle
(`MIN_WRITE_INTERVAL_S`). Polling faster cannot surface new data; polling slower would lag it. The
cadence is matched to the source, not chosen arbitrarily.

### Read budget — measured, per §13

Per uncached poll: **2 queries**, returning `N` live docs + `M` in-progress trips (`N ≈ M` = active
buses). Vehicles/routes/staff are small, change rarely, and are cached separately.

| Active buses | Docs/poll | Polls/min | Docs/min | Docs/hour |
|---|---|---|---|---|
| 3 | ~6 | 4 | 24 | 1,440 |
| 20 | ~40 | 4 | 160 | 9,600 |
| 100 | ~200 | 4 | 800 | **48,000** |

**The server-side cache is the load-bearing mitigation.** With a ~10 s per-school cache, cost is
**per school, not per open tab** — ten administrators watching one fleet cost the same as one. Without
it, 100 buses × 10 tabs would be 480,000 reads/hour, which is the "works for 3, unusable at 100"
failure the brief warns about.

Client-side, only the changed vehicle's row and marker are patched — never a full table re-render.

---

## 5. Provider integration

Resolution goes through the existing platform: `resolveProvider('admin')` →
`Maps_config_service::getClientConfig('admin')` → `ZenxMapRendererFactory`. **No new provider setting,
no hardcoded renderer, no new SDK.**

Current admin-capable providers: **MapLibre, MapTiler, TomTom** (Leaflet renders their raster tiles).
Google / Mapbox / HERE remain placeholders and will render the existing "not available" notice.

Missing configuration must fail **visibly** — a blank grey map is indistinguishable from a loading
one.

---

## 6. Security

* **RBAC:** existing `Transport` module, graded — live viewing is a **read**, so `view`. No new module.
* **Tenant isolation:** every query is `schoolWhere`-scoped; the doc id carries the `{schoolId}` prefix.
* **Rules:** unchanged. The admin path reads through the PHP service account, and the existing
  `isSameSchool() && isStaff()` rule already covers staff reads. **No rule weakened, nothing made
  public.**
* **Secrets:** the admin map receives only the resolved provider's public credentials via
  `client_config`; `mapsSecrets` stays deny-all.

---

## 7. Prototype vs reality — what the mockup promised

The prototype's admin "Live fleet" panel showed: bus marker on a route line, next stop, **ETA
"4 min"**, progress %, speed, "18 of 26 on board", and other trips with "7 min late" / "Signal lost
11m".

| Prototype element | F13.2 |
|---|---|
| Bus marker on a map | **REAL** — live coordinates, real renderer |
| Next stop | **REAL** — from checkpoints |
| Progress % | **REAL** |
| Speed | **REAL** |
| On-board count | **REAL** — `boarded_count` / `roster_size` |
| "7 min late" | **REAL** — schedule delta from frozen `scheduled_pickup_time` |
| "Signal lost 11m" | **REAL** — freshness from `recorded_at_ms` |
| **"ETA 4 min"** | **NOT BUILT** — deferred (§3.1) |
| Route line drawn from stop coordinates | **REAL** — `stop_location` per checkpoint |

Everything in that mockup is buildable from canonical data **except the ETA**, which was the one
number the prototype invented.

---

## 8. No F13.1 contract change required

The audit found **nothing** in F13.2 that needs a change to: GPS telemetry schema ·
`vehicleLivePositions` / `vehicleBreadcrumbs` contracts · anti-spoofing · ordering · kill switch ·
driver foreground service · canonical driver lifecycle · D-1 · D-8 · the Maps security model.

F13.2 is **purely additive and read-only** with respect to GPS. If that changes mid-implementation I
will stop and report before touching the contract.

---

## 9. Implementation plan

| # | Deliverable | Layer |
|---|---|---|
| A1 | `Transport_fleet_service` — assemble fleet snapshot: live doc + trip + checkpoints → freshness, stops, progress, schedule delta, distance-to-next | server, new |
| A2 | `Transport_fleet` controller — `live` endpoint (graded `Transport:view`) + page, server cache | server, new |
| A3 | Admin fleet map view — Leaflet-via-abstraction, map + synchronized sidebar, counters, selection, honest states | view, new |
| A4 | Probe + verifier gate | test |
| B1 | Parent: next stop / **N stops away** / schedule delta on the existing My Bus card | Parent app |
| B2 | Teacher: driver-facing next-stop + stops-remaining (no map) | Teacher app |

A1–A4 are the stated primary objective and come first. B1–B2 reuse A1's computation via the same
canonical fields.

---

**Audit complete. Proceeding to implementation. Nothing committed.**
