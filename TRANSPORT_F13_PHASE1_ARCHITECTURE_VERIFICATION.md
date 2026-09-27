# Transport F13 — Live GPS · Phase 1: Architecture Verification & Implementation Plan

> ## AMENDMENT A-1 — Mapping-provider agnosticism (2026-08-08, approved)
>
> The Live GPS system must be **mapping-provider agnostic**. Firestore, the backend services,
> Transport logic, notifications, trip lifecycle and the security model must never depend on any
> specific map provider. Only the **rendering layer** may know one exists.
>
> **Default stack:** Admin Panel → **Leaflet** + OSM tiles · Parent App → **MapLibre** + OSM tiles ·
> Teacher/Driver App → **Fused Location Provider** for collection, **no map** unless a driver-facing
> navigation screen is actually required.
>
> Firestore stores canonical telemetry only — `lat`, `lng`, `heading`, `speed_kmph`, `accuracy_m`,
> `altitude_m`, timestamps, `gps_session_id`, `tripId`, `vehicleId`, `driver_staff_id`. **No**
> provider SDK model, encoded polyline, tile/style URL, place id, or session token is ever persisted.
>
> **Impact on this document:** §4.3 and §4.6 are amended (Google Maps → MapLibre/Leaflet); **D-6 is
> VOID** — no Google Maps API key is required, which removes an operator dependency from the critical
> path. §4.1, §4.2, §4.4, §4.5, §4.7–§4.16 are **unaffected** — they never referenced a provider.
> A new decision **D-7 (tile hosting)** is raised in §5; it is not a rendering-library question and
> is not answered by choosing Leaflet or MapLibre. Full verification in §A-1 below.


**Date:** 2026-08-08
**Branch:** `integration/phase2` @ `758c924` (working tree — uncommitted)
**Phase:** 1 of N — **verification and planning only. No production code written.**

> Release constraints honoured: no commits, no rebases, no release branch, no push, no deployment.
> Frozen F1–F12.1 functionality: **untouched.**

---

## 0. Executive summary

The approved F13 design (`TRANSPORT_RESEARCH_AND_DESIGN.md` §G1, §5.2–5.6) is sound and unusually
well-prepared — the schema, correlation keys and gate protection were all built in from F7 onward.
Verifying it line-by-line against current code surfaced **10 gaps**. Two are blockers that must be
resolved before any code is written:

| | Gap | Severity |
|---|---|---|
| **G-1** | The design's security rule for `vehicleLivePositions` **cannot compile** — `isDriverOfTrip()` takes no arguments and reads `resource.data`, which is null on create | **BLOCKER** |
| **G-2** | `completeTrip` / `cancelTrip` **never clear** `live_tracking_enabled` — the core privacy invariant has no server-side enforcement. Fixing it means touching frozen code | **BLOCKER — needs approval** |
| G-3 | Parent auth UID is the **student's `userId`**, not a parent id — the `authorized_parent_user_ids[]` allowlist is misnamed and must be populated via the canonical resolver | High |
| G-4 | **Firestore TTL is not configurable from this repo** — an `expires_at` field alone deletes nothing | High |
| G-5 | `CH-TRN-F7-1` must be re-scoped (known and planned) | Medium |
| G-6 | Teacher app's `LocationProvider` is **one-shot and owned by Staff Attendance** — not reusable; manifest lacks Android 14 foreground-service permissions | Medium |
| G-7 | Parent app has the Maps SDK but **no API key and no manifest entry** — a map cannot render | Medium |
| G-8 | No composite indexes exist for the 3 new collections | Low (planned) |
| G-9 | No anti-spoofing design — mock locations are rejected for staff attendance but nothing equivalent exists for F13 | Medium |
| G-10 | Design doc line citations have drifted from the code | Cosmetic |

**Six design decisions require your approval before Phase 2.** They are in §5.

---

## 1. Working-tree re-verification (precondition)

| Check | Result |
|---|---|
| Branch / HEAD | `integration/phase2` @ `758c924` — unchanged |
| Dirty files | 72 (Transport close-out docs + the held Phase A entitlements work) |
| Transport graded-RBAC gates | **126** — unchanged |
| Legacy role-name RBAC in Transport | **0** — still clean |
| F12.1 verifier gates `CH-TRN-CMP-1..4` | 4 PASS |

The frozen module is intact. Safe to plan F13 on this base.

---

## 2. What already exists — verified against code, not memory

F13 is a **telemetry-acquisition** feature. The schema and correlation plumbing were built during F7
and are gate-protected.

### 2.1 Server (`Transport_service.php`)

| Asset | Location | State |
|---|---|---|
| `RESERVED_GPS_FIELDS` — 5 vehicle fields | `:398-400` | Reserved, write-guarded |
| `_assertNoReservedGpsWrites()` | `:5343`, enforced at `:672` in `saveVehicle` | Throws `LogicException` on any F1 write |
| `_capVehicleForAudit()` strips GPS fields | `:5326` | Privacy: positions excluded from audit rows |
| Trip GPS block — `gps_session_id`, `live_tracking_enabled=false`, `vehicle_location_ref`, `gps_start_*`, `gps_end_*`, `gps_last_position_at`, `gps_track_url`, `route_progress_pct`, `next_checkpoint_id/eta` | `:6313-6326` | All minted null at `createTrip` |
| Vehicle `gps_enabled`, `gps_device_id` inputs | `:498-499`, `:638-639` | Already captured in `saveVehicle` |
| Event aggregation `gps_session_ids[]` | `:7208` | Reserved for multi-vehicle events |

`vehicle_location_ref` is commented *"future: vehicleLivePositions doc id"* — **the target collection
is already named in code.**

### 2.2 Gate `CH-TRN-F7-1` — the discipline being enforced

Runtime gate. Samples 100 `tripLogs` + 100 `transportAttendance` docs and fails if **any** of these
is non-null:

* `tripLogs`: `gps_start_lat`, `gps_start_lng`, `gps_end_lat`, `gps_end_lng`, `gps_last_position_at`, `gps_track_url`
* `transportAttendance`: `event_location_ref`, `event_lat`, `event_lng`, `event_at_gps`, `geofence_variance_m`, `geofence_verified`

> The attendance list is a finding in itself: **geofence-verified boarding is already schema-reserved.**
> The design's §G1 "Advanced" tier is closer than the roadmap implies.

### 2.3 Firestore rules — what exists and what does not

| Helper | Exists | Signature |
|---|---|---|
| `isSameSchool()` | Yes `:70` | permissive when `resource == null` |
| `isStaff()` | Yes `:184` | not Student/Parent/Guardian |
| `isDriverOfTrip()` | Yes `:246` | **takes no arguments**; returns `driverIs(resource.data.driver_staff_id)` |
| `hasClientMetadata()` | Yes `:255` | requires `app_version`, `client_platform ∈ [android,ios,web]`, `client_timestamp:int` |
| `isParentOfStudentOnVehicle()` | **No** | must be created |

**No rules block exists for `vehicleLivePositions`, `vehicleBreadcrumbs`, or `stopGeofenceEvents`.**

### 2.4 Apps

| | Parent | Teacher (driver) |
|---|---|---|
| Maps SDK | `play-services-maps:19.0.0`, `maps-compose:4.4.1` — present, **unused** | none |
| Location lib | `play-services-location:21.3.0` — present, unused | `play-services-location:21.3.0` — **in use** |
| Manifest permissions | **none** | `ACCESS_FINE_LOCATION`, `ACCESS_COARSE_LOCATION` |
| Existing code | `MyBusScreen.kt` renders "Live tracking coming soon" and **already reads `gps_session_id`** | `LocationProvider.kt` — one-shot fix for **Staff Attendance Phase 11** |
| Client metadata | — | `ClientMetadata.kt` emits the exact 3 fields `hasClientMetadata()` requires |

### 2.5 Indexes

11 transport composite indexes exist (`CH-TRN-RC2-2` PASS). **None** covers the three new
collections.

---

## 3. Design-vs-code gap analysis

### G-1 — The live-position write rule does not compile *(BLOCKER)*

The design specifies:

```
allow write: if isSameSchool() && isDriverOfTrip(request.resource.data.tripId) && ...
```

The real helper takes **no arguments** and evaluates `resource.data.driver_staff_id` — the
**existing** document. Two failures follow:

1. **Arity mismatch** — passing an argument is a rules compile error.
2. **Create is unprotected** — on the first position write of a trip, `resource == null`, so
   `resource.data.driver_staff_id` is null and `driverIs()` cannot match. Worse, `isSameSchool()`
   *also* returns true when `resource == null`, so a naive create rule would have **no tenant check
   and no driver check**.

**Recommended resolution (Decision D-1):** make the hot doc **server-created**.
`Transport_service::startTrip` pre-creates `vehicleLivePositions/{schoolId}_{vehicleId}` carrying
`tripId`, `gps_session_id`, `driver_staff_id` and the viewer allowlist. Rules then allow
**update only** by the driver, and **no client create at all**:

```
match /vehicleLivePositions/{id} {
  allow read:   if isSameSchool() && (isStaff() || request.auth.uid in resource.data.authorized_viewer_uids);
  allow create: if false;                               // server-only, at startTrip
  allow update: if isSameSchool() && isDriverOfTrip()   // existing helper, unchanged
                   && hasClientMetadata()
                   && request.resource.data.diff(resource.data).affectedKeys()
                        .hasOnly(POSITION_FIELDS);      // driver may never edit tripId/allowlist
  allow delete: if false;
}
```

This reuses `isDriverOfTrip()` **unmodified** — no shared-rule change — and makes the allowlist
server-authoritative. It also gives a natural home for the trip-end kill switch (G-2).

### G-2 — Tracking has no server-side stop *(BLOCKER — requires touching frozen code)*

`live_tracking_enabled` and `gps_end_*` are written **only at `createTrip`** (`:6314-6325`).
`completeTrip` and `cancelTrip` never touch them.

The design states as a non-negotiable privacy invariant: *"Position is captured only while a trip is
`InProgress`. Trip completion stops the foreground service."* Today that would be enforced **purely
by client goodwill**. A driver app that crashes, is killed and restarted, or is simply stale would
keep streaming after the children are off the bus.

**This cannot be fixed without modifying two frozen F1–F12.1 methods.** Per your standing rule I am
not making that change — I am flagging it as the critical blocker it is.

**Proposed minimal change (Decision D-2)** — additive, no existing semantics altered:
* `startTrip` → set `live_tracking_enabled = true`, stamp `gps_start_*`, create the hot doc.
* `completeTrip` / `cancelTrip` → set `live_tracking_enabled = false`, stamp `gps_end_*`, and delete
  (or tombstone) the hot doc so the rule's allowlist read returns nothing.
* Rules additionally require `resource.data.live_tracking_enabled == true` on update, so the server
  flag is the **authoritative kill switch** regardless of client behaviour.

Blast radius: 3 methods, all additive writes on the same doc already being updated for status
transitions. `CH-TRN-CON6-1` (trip transitions CAS-guarded) must keep passing — the new fields ride
the existing `casUpdateEntity` call rather than adding a second write.

### G-3 — The allowlist is misnamed, and there is exactly one correct way to populate it

The design proposes `authorized_parent_user_ids[]`. But the rules comment at `firestore.rules:264`
is explicit: **"Parent-app auth uses the student's `userId` as the Firebase auth UID."** There is no
parent identity in the token — a parent authenticates *as a child*.

The canonical mapping already exists and is gate-pinned by `CH-TRN-RCP-1`:
`Transport_notifier::_resolveStudentRecipients()` → `_recipientUserIdFromStudent()`, which reads
`students.userId` and falls back to `studentId` with a logged warning.

**Resolution:** name the field `authorized_viewer_uids[]`, populate it at `startTrip` from
`roster_student_ids` **through that same resolver** — never a reimplementation. The roster is already
frozen at `createTrip` (`roster_frozen_at`), so the allowlist is a stable snapshot with no per-read
`get()`, exactly as the design intends.

Sizing: a 60-seat bus yields ≤60 UIDs — trivially inside document limits.

### G-4 — Firestore TTL cannot be configured from this repository

The design specifies breadcrumbs "TTL 30d" via an `expires_at` field. **A field does nothing on its
own.** Firestore TTL is a per-collection *policy* set through the console, `gcloud`, or Terraform —
it is not expressible in `firestore.rules` or `firestore.indexes.json`, and no TTL configuration
exists anywhere in this repo.

Left as-is, breadcrumbs would accumulate **forever**: at 3,000 students that is ~86k docs/day/school.

Three options in **Decision D-3**.

### G-5 — `CH-TRN-F7-1` re-scope *(known, planned)*

Must change from "these fields must be null" to "these fields may be written only by the GPS ingest
path". Per the standing instruction: **re-scope the gate, never delete it.** Concretely, it should
assert that a non-null position field is accompanied by a valid `gps_session_id` matching the trip,
so accidental writes from a non-GPS path still fail.

### G-6 — Teacher `LocationProvider` is not reusable, and the manifest is short two permissions

`LocationProvider.kt` is explicitly scoped: *"the ONLY Android location-acquisition surface for the
GPS staff-attendance feature (Phase 11)… One-shot foreground fix only — no background location, so
no Android 14 foreground-service-location type and no Android 15 changes apply."*

F13 needs the opposite: a **continuous foreground service** for the duration of a trip. That is a
new component. `LocationProvider` belongs to Staff Attendance and **must not be modified** — this is
a cross-module boundary, and its consumer `StaffAttendanceViewModel` is out of F13 scope.

It is still valuable as a **precedent**: it already handles mock-location detection, permission and
location-services state, and the Android 12 coarse-accuracy nudge. F13's service should mirror those
patterns rather than invent new ones.

Manifest additions required (Teacher): `FOREGROUND_SERVICE`, `FOREGROUND_SERVICE_LOCATION`
(mandatory on Android 14+), and a `<service android:foregroundServiceType="location">` declaration.
`ACCESS_BACKGROUND_LOCATION` is **not** required — a foreground service with a visible notification
covers the trip-duration case and avoids the Play Store background-location review.

### G-7 — Parent app cannot render a map yet

`play-services-maps` and `maps-compose` are dependencies, but the Parent manifest has **no
`com.google.android.geo.API_KEY` meta-data and no location permissions**. Without the key the map
surface initialises to a blank tile grid. Provisioning and restricting that key is an operator
action with a lead time, so it belongs on the critical path early.

Parent needs **no** location permission for the MVP — the parent watches the bus, they do not share
their own position. "Distance to me" would require `ACCESS_COARSE_LOCATION` and is deliberately out
of MVP scope.

### G-8 — Indexes

None of `vehicleLivePositions`, `vehicleBreadcrumbs`, `stopGeofenceEvents` has an index. The 7
composites in design §5.4 are all still required. Per the deploy runbook these ship **before** the
code that needs them.

### G-9 — No anti-spoofing design

Staff Attendance rejects mock locations and poor accuracy **server-side**. F13's design has no
equivalent, yet the incentive is real: a spoofed position makes a late or off-route bus look
on-schedule, and would corrupt geofence auto-arrival and any driver-behaviour scoring built on it.

Not a blocker for a read-only map, but it **becomes** one the moment geofence crossings auto-fire
checkpoints. See Decision D-4.

### G-10 — Design citations have drifted

`TRANSPORT_RESEARCH_AND_DESIGN.md` cites `Transport_service.php:6107-6120` for the trip GPS block
(actually `:6313-6326`) and `:393-394` for the write guard (actually `:398-400` for the constant,
`:5343` for the method). The **substance is accurate**; only the line numbers moved. Worth a
refresh when the design doc is next touched, so future readers don't distrust it.

---

## 4. Architecture review — the 16 required areas

### 4.1 Firestore data model

Three new collections, all single-`{schoolId}_`-prefixed per RC-3, all carrying a `schoolId` field
for `schoolWhere`:

| Collection | Doc id | Cardinality | Write pattern |
|---|---|---|---|
| `vehicleLivePositions` | `{schoolId}_{vehicleId}` | one per vehicle | **overwritten**, ≤1 per 15s |
| `vehicleBreadcrumbs` | `{schoolId}_{gpsSessionId}_{epochMs}` | one per ping | append-only, immutable |
| `stopGeofenceEvents` | `{schoolId}_{tripId}_{stopId}_{eventType}` | deterministic ⇒ **idempotent** | create-once |

Plus the already-reserved fields on `tripLogs` and `transportAttendance` finally being populated.
No new field on `vehicles` beyond the 5 already reserved.

The hot/cold split is the single most important decision in the feature and it is already correct:
Firestore sustains **~1 write/sec/document**, so the parent-facing document must be the *only* thing
written at position frequency, and history must go to a different doc per ping.

### 4.2 GPS session lifecycle

`gps_session_id = GPSSESS_{tripId}`, already minted at `createTrip`. Proposed lifecycle:

```
createTrip    → gps_session_id minted; live_tracking_enabled = false          [today]
startTrip     → live_tracking_enabled = true; gps_start_*; hot doc created
                with driver_staff_id + authorized_viewer_uids[]                [D-2]
(streaming)   → driver updates hot doc ≤1/15s; breadcrumb appended per ping
completeTrip  → live_tracking_enabled = false; gps_end_*; hot doc removed      [D-2]
cancelTrip    → same as completeTrip                                           [D-2]
stale sweeper → hot docs with recorded_at older than N minutes marked is_stale
```

The server flag — not the client — is authoritative at every step.

### 4.3 Parent App integration

`MyBusScreen.kt` already reads `gps_session_id` and renders the placeholder card, so the integration
point exists. MVP swaps that card for a `GoogleMap` composable with one marker, subscribing to
**exactly one document** via a snapshot listener. Attach only while the trip is `InProgress` **and**
the map screen is foregrounded; detach in `onStop`. Stale state (`is_stale`) renders the "Last seen
N minutes ago" copy already written in design §7.3. Requires the API key (G-7).

### 4.4 Teacher/Driver App integration

New foreground service, started from `DriverTripDetailScreen` on trip start and stopped on
complete/cancel. Persistent, non-dismissible notification while live (a design invariant and an
Android requirement). Permission flow, battery-optimisation exemption prompt, and the Android 14
service type per G-6. Writes carry `ClientMetadata` — which already emits exactly the three fields
`hasClientMetadata()` demands, so that rule works unchanged.

### 4.5 Admin Panel integration

Read-only in MVP: a live fleet map over all `InProgress` trips, plus trip replay from breadcrumbs.
Gated by the existing graded RBAC — `require_permission('Transport', 'live_map', 'view')` — adding
endpoints to the frozen module's pattern **without** altering any existing gate. Whether this lands
in MVP is Decision D-5.

### 4.6 Live map architecture

One document, one listener, one marker. No polling, no fan-out query. Marker interpolation is
client-side between 15s updates so motion looks smooth without extra reads. Route polyline and stop
markers come from the already-cached `routes`/`transportStops` data, not from the hot doc.

### 4.7 Driver location update flow

```
FusedLocationProvider (balanced power, 10s interval)
  → adaptive throttle: skip if <15s since last write OR stationary >60s
  → set() hot doc (overwrite)  +  create() breadcrumb (append)
  → on failure: queue locally, retry with backoff
```

Two writes per accepted ping — one hot, one cold. Both are single-document operations; no batch, no
transaction, so neither contends.

### 4.8 Parent live-tracking flow

Resolve child → `studentRoutes` assignment → `vehicleId` → subscribe to
`vehicleLivePositions/{schoolId}_{vehicleId}`. Authorization is the allowlist `in` check — **no
cross-document `get()` in the rule**, which is what keeps per-position read cost at exactly one
document.

### 4.9 Trip lifecycle integration

Covered in §4.2. The important property: F13 adds **no new trip state**. It rides the existing
`Scheduled → InProgress → Completed/Cancelled` machine, which is already CAS-guarded by
`CH-TRN-CON6-1`.

### 4.10 Battery / network recovery

Balanced-power priority rather than high-accuracy (a bus on a road does not need 3m precision).
Adaptive throttle suppresses the long stationary dwell at pickup, which is a large share of trip
time. On network loss, positions queue locally and flush on reconnect — breadcrumbs are append-only
with deterministic ids so replay is idempotent; the hot doc simply takes the newest fix and discards
stale queued ones (last-write-wins is correct for "current position").

### 4.11 Offline synchronisation

Deliberately asymmetric: **breadcrumbs sync, hot position does not.** A queued 20-minute-old
position must never be written to the hot doc — it would show parents a bus that has since moved.
Discard-if-older-than-throttle-window is the rule. Firestore's offline persistence must be
explicitly bypassed for the hot-doc write path for the same reason.

### 4.12 Multi-tenant isolation

Every collection `{schoolId}_`-prefixed (single prefix, RC-3) plus a `schoolId` field. The one real
risk is the parent read, and the allowlist mitigation removes it. Two residual checks for the
verifier: the allowlist must be rebuilt at every `startTrip` (a stale roster would leak position to
a since-unassigned family), and `isSameSchool()`'s `resource == null` permissiveness must never be
the only guard on a create — which D-1 solves by forbidding client creates outright.

### 4.13 Firestore read/write budget

Design §5.6, re-verified as arithmetic:

| Scale | Buses | Writes/day | Parent reads/day |
|---|---|---|---|
| 30 students | 1–2 | ~2,880 | ~1,400 |
| 300 students | 8–10 | ~14,400 | ~14,000 |
| 3,000 students | 40–60 | ~86,400 | ~120,000 |

Those write figures count the hot doc only; **breadcrumbs double them** (~172k writes/day at 3,000
students). That is still within Firestore's practical envelope but it is the dominant new cost in
the whole ERP, and it is the reason D-3 (breadcrumb retention) matters commercially, not just
technically.

### 4.14 Security rules

Five new blocks. The compliance three are already live from F12.1; the three GPS blocks are new and
sketched in G-1. Net new helper: none required if D-1 is adopted — the existing `isDriverOfTrip()`
and `hasClientMetadata()` carry the write path unchanged, which is the safest possible outcome for a
file the project rule says is edited concurrently by other sessions.

### 4.15 Privacy controls

* Position captured only while `live_tracking_enabled == true`, enforced **server-side** (D-2).
* Persistent driver notification whenever tracking is live.
* Positions already excluded from audit rows by `_capVehicleForAudit()`.
* Breadcrumb retention bounded (D-3).
* Parent sees one vehicle, only while assigned, only during an active trip.
* Historical replay is staff-only — parents never get breadcrumb access.

### 4.16 Performance

No N+1 anywhere: the parent map is one listener on one doc; the admin fleet map is one query over
`InProgress` trips; replay is one indexed range query per session. The throttle is the performance
design. The stale sweeper must be a bounded cron extension of `Transport_expiry_cron`, not a new
unbounded scan.

---

## 5. Open design decisions — approval required before Phase 2

### D-1 — Live-position document creation *(resolves BLOCKER G-1)*

| Option | Consequence |
|---|---|
| **A. Server pre-creates at `startTrip`; rules allow update-only** *(recommended)* | Reuses `isDriverOfTrip()` unchanged; allowlist server-authoritative; no client create; no shared-rule edit |
| B. Client creates; add a new `isDriverOfTripById()` rules helper | Needs a cross-doc `get()` per create, a new shared helper, and leaves the `resource == null` tenant hole to patch by hand |

### D-2 — Modify three frozen methods for the tracking kill switch *(resolves BLOCKER G-2)*

`startTrip`, `completeTrip`, `cancelTrip` gain additive writes to fields that already exist and are
already null. Without this, the privacy invariant is client-enforced only.

| Option | Consequence |
|---|---|
| **A. Approve the scoped change** *(recommended)* | Server-authoritative stop. 3 methods, additive only, riding existing CAS writes |
| B. Client-only stop | Tracking survives app crash/kill/staleness. I do not recommend shipping this |
| C. Separate endpoint the driver calls to stop | Still client-triggered; adds an endpoint and the same failure mode |

### D-3 — Breadcrumb retention *(resolves G-4)*

| Option | Consequence |
|---|---|
| **A. Operator configures a Firestore TTL policy on `expires_at`** *(recommended)* | Native, free, no code. Out-of-band console/gcloud step; must be added to the deploy runbook or it silently never happens |
| B. Cron sweeper in `Transport_expiry_cron` | Fully in-repo and verifiable, but deletes cost a write each — at 86k breadcrumbs/day that roughly doubles the cost of the feature |
| C. Defer breadcrumbs entirely from MVP | Cheapest and simplest; loses trip replay and any historical-timing ETA, which is the stated differentiator |

### D-4 — Anti-spoofing policy *(G-9)*

| Option | Consequence |
|---|---|
| **A. Reject mock + poor-accuracy server-side, mirroring Staff Attendance** *(recommended)* | Consistent with an existing, proven pattern in the same codebase |
| B. Record `is_mock` and surface it to admin without rejecting | Preserves the data trail; defers enforcement |
| C. No handling in MVP | Acceptable only while the map is read-only; becomes a blocker before geofence auto-checkpoint |

### D-5 — MVP scope

The design defines three tiers. My recommendation is to ship the narrowest useful slice first:

| Slice | Contents | Recommendation |
|---|---|---|
| **F13.1** | Driver stream + hot doc + parent live map + start/stop notices | **MVP** |
| F13.2 | Admin live fleet map + trip replay | Next |
| F13.3 | Geofence auto-checkpoint, ETA bands, late-bus detection, driver-behaviour scoring | Later — and D-4 becomes mandatory here |

Geofence auto-arrival in particular **replaces a manual driver action** with an automated one; that
deserves its own milestone and its own regression pass, not a corner of the MVP.

### D-6 — Google Maps API key *(G-7)* — ~~pending~~ **VOID as of amendment A-1**

Superseded. The default stack is MapLibre (Parent) + Leaflet (Admin) on OSM tiles, so no Google
Maps key is required and this operator dependency leaves the critical path entirely. Replaced by
D-7 below.

### D-7 — OSM tile hosting *(new, raised by amendment A-1)* — **blocks M4, not M1**

Leaflet and MapLibre render tiles; they do not serve them. The public OSM tile service's usage
policy excludes heavy/commercial use, so it is not a viable production source for this ERP.

| Option | Cost / effort | Notes |
|---|---|---|
| **A. Commercial OSM tile provider** — MapTiler, Stadia Maps, Thunderforest, Carto *(recommended)* | Per-tenant or per-request pricing; ~zero engineering | Keeps the stack fully OSM/MapLibre-compatible and provider-swappable, which is precisely the spirit of A-1. Usually the fastest path to production. |
| B. Self-host tiles — planetiler + tileserver-gl | No per-request fee; real infra work and ongoing ops | Cheapest at scale, and no third-party dependency, but it is a new service to run, monitor and update. Meaningful scope beyond F13. |
| C. Public OSM tiles | Free | **Not viable for production** — outside the usage policy, and blockable without notice. Acceptable only for local development and the internal pilot. |

My recommendation is **A for production, C for development**, decided before M4 begins. Either way
the schema, rules and services are unaffected — this is purely where the rendering layer fetches
imagery from.

---

## 6. Proposed milestone plan

Same discipline as F12.1 — one milestone at a time, never merged, each ending in impact analysis ·
runtime validation · regression · verifier · cross-module review · **stop for approval**.

| M | Scope | Touches frozen code? |
|---|---|---|
| **M1** | Data foundation — 3 collections, 7 indexes, rules blocks, `CH-TRN-F7-1` re-scope, allowlist resolver reuse | Rules + gate only |
| **M2** | Server lifecycle — `startTrip`/`completeTrip`/`cancelTrip` GPS transitions, hot-doc create/teardown, stale sweeper | **Yes — gated on D-2** |
| **M3** | Ingest API + Teacher foreground service (Kotlin) | No |
| **M4** | Parent live map (Kotlin) | No |
| **M5** | Verifier gates, probes, HTTP auth probe extension, certification | No |

Kotlin work in M3/M4 requires coordinated Teacher and Parent releases — flagged in the design's
cross-module verdict as the condition that makes F13 "CONDITIONAL" where F12.1 was "CLEAR".

---

## A-1. Mapping-provider amendment — required impact analysis

Each item you asked to be verified before code, answered against the codebase.

| # | Claim to verify | Verdict | Evidence |
|---|---|---|---|
| 1 | No F13 milestone depends specifically on Google Maps APIs | **CONFIRMED** | M1/M2 are server-side and contain zero map references (gate `CH-TRN-F13-3` now enforces this). M3 uses Fused Location Provider — a *location* API, not Maps. Only M4 renders, and it is now MapLibre. |
| 2 | MapLibre fully supports Parent live bus tracking | **CONFIRMED, with one implementation note** | MapLibre Native Android covers everything F13.1 needs: raster/vector tiles, a marker for the bus, route polyline drawn *client-side* from `routes`/`transportStops`, camera follow, offline-tile support. **Note:** MapLibre Android is View-based, so Compose integration is via `AndroidView` or the community `maplibre-compose` wrapper — a small M4 implementation detail, not a capability gap. |
| 3 | Leaflet fully supports Admin live fleet monitoring | **CONFIRMED** | Leaflet handles multi-marker fleet views, popups, polylines and auto-refresh trivially. It is plain JS with no build step, which suits this CI3 panel. *(Admin fleet map is F13.2 by D-5, so this is a forward confirmation, not M1 work.)* |
| 4 | Fused Location Provider remains the canonical GPS source | **CONFIRMED — unchanged** | `play-services-location:21.3.0` is already a Teacher dependency and already in use by `LocationProvider.kt`. The amendment touches rendering only; collection is untouched. |
| 5 | No Firestore schema changes required | **CONFIRMED** | The M1 schema was already canonical-telemetry-only. Full persisted field set: `lat`, `lng`, `speed_kmph`, `heading`, `accuracy_m`, `altitude_m`, `recorded_at`, `received_at`, `is_mock`, `source`, `schoolId`, `vehicleId`, `tripId`, `gps_session_id`, `driver_staff_id`, `authorized_viewer_uids`, `live_tracking_enabled`, `session_started_at`, `expires_at`. Nothing provider-shaped. **Zero schema change.** |
| 6 | No Transport feature F1–F12.1 affected | **CONFIRMED** | The amendment is a rendering-layer decision; F1–F12.1 contain no map rendering at all. Full verifier after M1: 101 PASS / 4 FAIL, the 4 being the same pre-existing data artifacts. |
| 7 | No Parent / Teacher / Admin functionality regresses | **CONFIRMED** | The Parent app's `play-services-maps` and `maps-compose` dependencies are **entirely unused** — verified by searching for `com.google.android.gms.maps` / `com.google.maps.android` imports across `app/src`: zero hits. Replacing them with MapLibre therefore removes dead weight with **no functional regression**. Teacher is unaffected. Admin has no map today. |

**Consequential changes**

* **D-6 is void.** No Google Maps API key. One less operator dependency, and one less per-tenant secret to manage.
* **Parent `build.gradle.kts` will change in M4** — drop `play-services-maps:19.0.0` and
  `maps-compose:4.4.1`, add MapLibre. `play-services-location:21.3.0` **stays** (it is the location
  API, unrelated to rendering, and is what a future "distance to me" feature would use).
* **New gate `CH-TRN-F13-3`** now fails the build if provider-specific data ever reaches the GPS
  data layer — the amendment is enforced, not merely documented.
* **A rendering abstraction is an app-layer concern.** Because Firestore carries only canonical
  telemetry, the "clean abstraction" you asked for is naturally thin: a single
  `BusMapRenderer` interface in each client with one implementation per provider. No backend
  abstraction is needed, because the backend never knew about maps in the first place.

### ⚠️ New finding — D-7: OSM tile hosting is a separate decision from the rendering library

Leaflet and MapLibre are **rendering libraries**; neither supplies map tiles. "OpenStreetMap tiles"
usually means the public `tile.openstreetmap.org` service, whose
[Tile Usage Policy](https://operations.osmfoundation.org/policies/tiles/) prohibits heavy and
commercial use and permits blocking without notice. **A multi-tenant commercial school ERP streaming
live bus maps to thousands of parents would be outside that policy.**

This does **not** block M1 (server-only, no rendering) and does not change the schema. It blocks
**M4** (Parent map) and the later admin map, so it is raised now to leave procurement time. Options
are in §5, D-7. Classification: **not a defect — an unresolved dependency in the approved default
stack.** Production impact if unaddressed: tiles throttled or blocked in production, leaving parents
with a grey map, with no code fault to diagnose.

---

## 7. Status

**Phase 1 complete. Stopped for approval.** No production code written. Frozen F1–F12.1
functionality untouched. All verifier gates, probes, rules and certifications preserved.

**Blocked on:** decisions **D-1** through **D-6**. D-1 and D-2 are hard blockers — M1 cannot be
specified without D-1, and M2 cannot begin without D-2.
