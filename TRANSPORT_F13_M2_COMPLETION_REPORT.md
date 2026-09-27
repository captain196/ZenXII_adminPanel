# Transport F13.1 — Milestone M2 Completion Report

**Date:** 2026-08-08
**Branch:** `integration/phase2` @ `758c924` (working tree — uncommitted)
**Milestone:** M2 — Server lifecycle, kill switch, fix ordering, stale sweeper
**Status:** **COMPLETE — stopped for approval.**

> Release constraints honoured: no commits, no rebases, no release branch, no push, no deployment.
> Frozen-code changes are limited to the **approved D-2 exception** — `startTrip`, `completeTrip`,
> `cancelTrip` and two thin bridge helpers. Nothing else in F1–F12.1 was touched.

---

## 1. What M2 delivers

| # | Deliverable | File |
|---|---|---|
| 1 | `openSession` / `closeSession` / `sweepStaleSessions` | `Transport_gps_service.php` |
| 2 | **Fix-ordering guard** — `validateFixOrdering()` + `acceptFix()` | `Transport_gps_service.php` |
| 3 | Monotonicity + clock bounds enforced **in the Firestore rule** | `firebase-rules/firestore.rules` |
| 4 | **D-2 lifecycle wiring** (approved frozen-code exception) | `Transport_service.php` |
| 5 | Stale-session sweeper cron, with `--dry-run` | **NEW** `Transport_gps_cron.php` |
| 6 | Probe extended — 27 new assertions (I/J/K) | `Transport_probe_f13.php` |
| 7 | 2 new verifier gates | `Transport_verifier.php` |

---

## 2. The kill switch is now real

Before M2, "position is captured only while a trip is InProgress" was enforced by client goodwill.
A driver app that crashed and restarted would keep streaming after the children were off the bus.

Three independent layers now enforce it:

1. **`completeTrip` / `cancelTrip` set `live_tracking_enabled = false`** — riding the *existing*
   `casUpdateEntity` write, so CON-6's single-atomic-transition property is preserved and no second
   write is introduced.
2. **The Firestore rule refuses any driver update** unless
   `resource.data.live_tracking_enabled == true`. Even if the app never learns the trip ended,
   Firestore rejects the write.
3. **The hot document is deleted**, so there is nothing left for a parent to read.

Layer 2 is the one that matters: it holds even if the delete in layer 3 fails. In that case the
position can only go **stale**, never keep moving.

---

## 3. Fix ordering — stale and out-of-order updates cannot win

### Why the rule is the real enforcement point

The driver device writes the hot doc **directly to Firestore**; PHP never sees a position. So the
guard had to live in the security rule. The service method mirrors it for server-mediated paths and
is what the probe exercises.

### Why an integer millis field, not the ISO string

Ordering keys on **`recorded_at_ms`** (integer epoch millis), not the ISO `recorded_at`. String
comparison is only safe if every writer emits identical zero-padded UTC; a device sending `+05:30`
instead of `Z` would compare wrong and silently mis-order. `recorded_at` is retained for humans.

### The three bounds

| Bound | Rule clause | Why it exists |
|---|---|---|
| **Monotonic** | `recorded_at_ms > resource.data.get('recorded_at_ms', 0)` | A device queues fixes offline. Without this, a flush after a tunnel replays a 20-minute-old position over the current one and the bus **jumps backwards** on the parent's map. `.get(…, 0)` lets the first fix through, since the server seeds the key at `0`. |
| **Not future** | `<= request.time.toMillis() + 120000` | `request.time` is **server** time and cannot be spoofed. Without an upper bound, one device with a wrong clock could stamp a far-future value and **permanently block every later fix** for that vehicle via the monotonicity check. |
| **Not ancient** | `>= request.time.toMillis() - 300000` | Implements "a queued offline position must never become *current*". Breadcrumbs are deliberately exempt — late history is legitimate, and their deterministic ids make replay idempotent. |

Equal timestamps are rejected as stale: a repeat of the same instant carries no new information and
would only burn a write.

---

## 4. Stale-session sweeper

A crashed, force-stopped or out-of-battery driver device never closes its session. Without a sweep
the hot doc survives with tracking enabled and a parent sees a frozen bus indefinitely.

Authority is the **trip, not the position**: a session is stale when its trip is no longer
`InProgress`, or when no fix has arrived for 30 minutes. Judging from the trip means a bus genuinely
parked on a live trip is never swept out from under its driver.

It is a **new controller**, not an extension of `Transport_expiry_cron` — that file is frozen and
the D-2 exception does not cover it. `--dry-run` reports without deleting, and deliberately
reimplements the decision rather than adding a flag to the service: a delete path that can be told
not to delete is one boolean away from deleting when it should not.

---

## 5. Validation

| Suite | Result |
|---|---|
| `php -l` on all touched PHP | clean |
| `firestore.rules` | balanced; diff **+124 / −0** (purely additive) |
| **`transport_probe_f13`** | **79 pass / 0 fail** (was 52 — +27 for M2) |
| **Full verifier** | **125 gates — 103 PASS / 15 INFO / 2 WARN / 5 FAIL** |
| Trip-lifecycle regression (8 probes) | **38 assertions, all pass** |
| Sweeper, live | validated against 2 real orphans it did not create |

### 5.1 New probe coverage (I / J / K)

* **I — fix ordering (14):** newer accepted; older rejected; identical rejected; +10 min future
  rejected but +1 min tolerated; −10 min rejected but −1 min accepted; missing / non-numeric / zero
  ordering keys rejected; `acceptFix` reports the *quality* failure before the ordering one.
* **J — lifecycle round trip (11):** open creates the doc with the switch **armed**, bound to trip
  and driver, ordering key seeded at `0`, **no position asserted**; re-open **overwrites** rather
  than merges, so yesterday's position cannot leak into a new trip; close removes the doc entirely.
* **K — sweeper (4):** an orphaned session is seeded, swept, verified gone, and a second sweep
  closes nothing (idempotent).

### 5.2 Verifier — M1 → M2

| | After M1 | After M2 |
|---|---|---|
| Gates | 123 | **125** (+2 F13) |
| PASS | 101 | **103** |
| FAIL | 4 | 5 |

All five F13 gates pass, and critically **`CH-TRN-CON6-1` still passes** — the trip transitions
remain CAS-guarded after the D-2 edits.

**The 5th FAIL is not a regression.** `CH-TRN-F7-2` cites
`PROBE_L45_TRP_1786159508255:no_driver`. That document was created at **05:25 today by
`transport_probe_log45`** — during the individual probe re-runs earlier in this session, **before
any M2 code existed**. The probe documents "self-teardown" but left the trip behind. It is probe
residue in the same class as the other four, though newer than the F12.1-era ones.

### 5.3 A real interaction the sweeper caught

After the trip-lifecycle regression, a dry-run sweep found **2 orphaned live sessions** —
`PROBE_CON1_VEH_…` and `PROBE_LOG1_VEH_…`, both reported as "trip missing".

Cause: those probes call `startTrip` (which now opens a GPS session) but tear down by **deleting the
trip document directly**, never calling `completeTrip`/`cancelTrip`, so `_closeGpsSession` never
ran. The sweeper identified both correctly and closed them; a follow-up dry run reports `scanned=0`.

**Production impact: none.** A real trip always ends through `completeTrip` or `cancelTrip`, both of
which close the session. Only test tooling that hard-deletes trips creates this residue — and the
sweeper is precisely the backstop for it. This was an unplanned but genuinely useful validation:
the sweeper was proven against orphans it did not manufacture for itself.

---

## 6. Impact analysis

| Area | Assessment |
|---|---|
| **Frozen F1–F12.1** | Changes confined to the D-2 exception. Gate `CH-TRN-F13-4` now *mechanically* enforces that boundary — it fails if any GPS implementation detail (`vehicleLivePositions`, `vehicleBreadcrumbs`, `authorized_viewer_uids`, `recorded_at_ms`) appears in `Transport_service`. The bridge is two helpers that delegate everything. |
| **Additive only** | My edits to `Transport_service.php` removed **zero** lines. The file's 41 diff deletions are pre-existing fee-lifecycle work from earlier in the session; none touch the trip lifecycle or GPS (verified). |
| **CAS integrity** | The new fields ride the existing `casUpdateEntity` mutators. No second write, no new transaction. `CH-TRN-CON6-1` PASS. |
| **Failure isolation** | `_openGpsSession` / `_closeGpsSession` are best-effort and mirror `_setVehicleCurrentRoute`: a telemetry failure is logged and never propagates into the trip lifecycle, which is the business truth. A trip can always start and end even if Firestore GPS writes fail. |
| **Tenant isolation** | Every hot doc carries `schoolId`; ids are single-`{schoolId}_` prefixed via `docId()`; the allowlist is rebuilt at every open, so a student unassigned since the last trip cannot still watch the bus. |
| **Write budget** | M2 adds exactly **2 writes per trip** (one open, one close) plus one delete. Negligible against the position stream. |
| **Provider-agnostic (A-1)** | Unchanged and enforced — `CH-TRN-F13-3` PASS. `recorded_at_ms` and `altitude_m` are canonical telemetry, not provider data. |
| **Firestore-only** | No RTDB anywhere. |
| **Fees / Accounting / Communication** | Untouched — M2 adds no demand, journal or notice writer. |
| **Parent / Teacher apps** | No app code changed. The apps cannot yet write positions; that is M3. |

---

## 7. New requirement recorded for M3 (not implemented)

> *"Start Trip checks whether Location Services is enabled. If GPS is OFF the trip must not start —
> show 'Please enable Location Services to start the trip.' Once enabled, pressing Start Trip again
> begins tracking automatically. The driver never enters coordinates manually anywhere."*

**Items 1–3 are Teacher-app (M3) behaviour** and are recorded for that milestone. One design note
worth stating now: the **server cannot verify a device's location-services state**, and a
client-asserted "GPS is on" flag would be trivially spoofable — security theatre. So this is
correctly a *client-side precondition* in the driver app. The server's contribution is what M2 just
built: the kill switch, the quality gates, and a sweeper that closes any session which never
streams. `LocationProvider.kt` already reports location-services and permission state, so M3 has the
primitive it needs.

**Item 4 is already true server-side and I verified it rather than assuming.** No Transport endpoint
accepts driver-supplied coordinates outside the GPS ingest path: the hot doc is server-created and
the driver may only update the 14 fields in `DRIVER_MUTABLE_FIELDS`, every one of which is
device-sensor telemetry. Admin-entered stop coordinates are a separate, legitimate admin surface —
not a driver one.

---

## 8. What M2 deliberately does NOT include

* **No app code.** Teacher foreground service is M3; Parent map is M4.
* **No position ingest endpoint.** The driver writes Firestore directly — that is the design.
* **No `stopGeofenceEvents`.** Geofence automation is deferred by D-5.
* **Nothing deployed.** Rules and indexes are written but **not deployed**; they take effect only on
  `firebase deploy`, which needs your authorization. **Until the rules are deployed the ordering
  guard is not live** — the service-side mirror only covers server-mediated paths.

---

## 9. Open items

| Item | Status |
|---|---|
| **D-7 — OSM tile hosting** | Still open. Blocks M4, not M3. Recommendation: commercial OSM tile provider for production, public tiles for development. |
| **Firestore TTL policy** | Operator step, documented in `DEPLOY_RUNBOOK.md` Phase 2b. Needed before breadcrumbs accumulate in production. |
| **Probe residue** | `PROBE_L45_TRP_…` (no driver) and the pre-existing F12.1-era artifacts. Non-blocking; operator cleanup. |

---

## 10. Proposed M3 (not started)

Teacher/Driver app — Kotlin:

1. Location-services precondition on Start Trip, with the exact copy above.
2. Foreground service for the trip duration + persistent tracking notification.
3. `FOREGROUND_SERVICE` and `FOREGROUND_SERVICE_LOCATION` manifest permissions (Android 14+), and a
   `foregroundServiceType="location"` service declaration.
4. Throttle and stationary suppression client-side, mirroring the service constants.
5. Write the hot doc (update-only) and append breadcrumbs, stamping `recorded_at_ms` and
   `ClientMetadata`.
6. `LocationProvider.kt` **not modified** — it belongs to Staff Attendance Phase 11 and is one-shot
   by design. M3 adds a new continuous component alongside it.

---

**Stopped for approval.** M3 will not begin until you authorize it.
