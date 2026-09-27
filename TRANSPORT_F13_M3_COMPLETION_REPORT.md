# Transport F13.1 — Milestone M3 Completion Report

**Date:** 2026-08-08
**Milestone:** M3 — D-8 canonical lifecycle + Teacher/Driver foreground GPS
**Status:** **COMPLETE — stopped for approval.**

> Release constraints honoured: no commits, no rebases, no release branch, no push, no deployment.

**Repos touched:** `ZenX/school` (admin panel) and `SchoolSyncTeacher` (driver app).

---

## 1. What M3 delivers

### Server (`ZenX/school`)

| # | Deliverable | File |
|---|---|---|
| 1 | Canonical driver lifecycle API — Bearer auth, ownership authz | **NEW** `Transport_driver_api.php` |
| 2 | CSRF exclusion for the app's session-less POSTs | `config/config.php` |
| 3 | `tripLogs` rule hardened — no client-side trip-state write | `firestore.rules` |
| 4 | Gate `CH-TRN-F13-6` — one canonical lifecycle | `Transport_verifier.php` |

### Driver app (`SchoolSyncTeacher`)

| # | Deliverable | File |
|---|---|---|
| 5 | Lifecycle endpoints | `ApiService.kt` |
| 6 | `startMyTrip` / `completeMyTrip` → canonical API | `TransportFirestoreRepository.kt` |
| 7 | **Foreground GPS service** | **NEW** `TripTrackingService.kt` |
| 8 | Precondition + start/stop control | **NEW** `TripTrackingController.kt` |
| 9 | Location-Services gate on Start Trip | `DriverTripDetailScreen.kt` |
| 10 | Android 14 permissions + service type | `AndroidManifest.xml` |
| 11 | `ClientMetadata.required()` | `ClientMetadata.kt` |

---

## 2. D-8 — one canonical lifecycle

The driver app no longer writes trip state. `startMyTrip` and `completeMyTrip` call
`transport_driver_api`, which delegates to `Transport_service`. Both surfaces now run the same
lifecycle, so the driver path gains what it previously skipped:

| Previously skipped on the driver path | Now runs |
|---|---|
| `_validateDriverReadiness()` — LOG-1 | ✅ an unfit or suspended driver is refused |
| `casUpdateEntity` — CON-6 | ✅ concurrent starts serialised |
| `emitAudit('trip', …)` | ✅ audit row for every transition |
| `_notify('trip.started')` | ✅ **parents are told the bus has started** |
| `_setVehicleCurrentRoute()` — XMOD-1 | ✅ Teacher route badge + SOS context populated |
| F13 GPS session open/close | ✅ the blocker that surfaced all of this |

### Permissions — ownership, not an RBAC grant

Your instruction was the minimum needed and no Transport management capability. Granting
`Transport:edit` to the `Driver` role would have been the easy path and the wrong one — it also
authorises `save_vehicle`, `delete_route`, `terminate_vendor` and every other Transport write.

Instead the API authorises by **ownership**: a driver may act on a trip if and only if
`trip.driver_staff_id` equals the authenticated token uid. There is **no `require_permission()`
call** in the controller, and the `Driver` role gains **no module**. That is the same model
`Staff_attendance` already uses (you may punch only yourself) and the same concept the Firestore
rule `isDriverOfTrip()` enforces — so the server and rules layers now agree on one idea.

Identity is never taken from the request body, so a driver cannot act as another driver. A missing
trip and someone else's trip both return the same 403, so trip ids cannot be enumerated.

### The hardening that makes it stick

The `tripLogs` driver allowlist was narrowed to the only surface the app still writes directly —
checkpoint marking:

```
removed:  status, actual_start_at, actual_end_at, endTime, endOdometer,
          distance, distance_km, fuelUsed, stopsCompleted, notes, alerts
retained: checkpoints, route_progress_pct, next_checkpoint_id, updated_at
          + client metadata
```

Verified against the app first: those fields were written in exactly two functions, both of which
now go through PHP. Without this, "one canonical flow" would be a convention a future client could
quietly break, with the regression invisible until a parent noticed the bus never appeared.

---

## 3. Location Services precondition

Exactly the behaviour you specified, in `DriverTripDetailViewModel.startTrip()`:

1. `tripTracking.preflight()` runs **before** anything is sent to the server.
2. Services off → the trip does **not** start; the driver sees
   **"Please enable Location Services to start the trip."**
3. The check is **per-press, not cached** — enabling the setting and pressing Start Trip again
   proceeds, and tracking begins automatically on that second press.
4. Permission missing is reported separately, since the remedy differs.

Ordering is load-bearing: the hot document is created **server-side** when the trip goes InProgress,
so the stream starts only after the lifecycle call succeeds. Starting it earlier would have it
writing to a document that does not yet exist.

**No manual coordinate entry exists anywhere.** Every value comes from the OS location provider;
there is no code path — UI, repository or service — that accepts a typed position.

---

## 4. Foreground service

`TripTrackingService` — a new component, **not** a modification of `LocationProvider`. That file is
Staff Attendance (Phase 11), explicitly one-shot and explicitly "no background location"; F13 needs
the opposite. It is reused read-only for its permission and services reporters.

* Balanced-power priority, not high accuracy — a bus does not need 3-metre precision, and high
  accuracy roughly doubles battery draw over a multi-hour route.
* Client-side quality gates mirror the server (mock, ≤100 m accuracy, plausible speed) purely to
  avoid burning writes the server would reject. **The server remains the authority** — a modified
  client could skip these, which is why they are not a security control.
* 15-second floor and stationary suppression with a 60-second heartbeat, mirroring the server
  constants.
* Hot doc by **update only**, never create. Breadcrumbs append-only with a `Date` (Timestamp)
  `expires_at`, since a String would make the TTL policy a silent no-op.
* `START_NOT_STICKY`: silently resurrecting a location stream the driver cannot see would break the
  "tracking is always visible" invariant. The server-side sweeper closes any session left open.
* Permanent non-dismissible notification — a privacy invariant, and an Android requirement.
* **No map, no map SDK.** Provider-agnostic per amendment A-1; only canonical telemetry is produced.

---

## 5. Two incompatibilities caught before they shipped

**`ClientMetadata.asMap()` would have failed every position write.** It emits `device_sdk` and
`device_model`, which are not in the `vehicleLivePositions` `hasOnly()` allowlist — Firestore would
have rejected every update with permission-denied. Rather than widen the allowlist (weakening the
control) I added `ClientMetadata.required()` with only the three fields the rule demands. That is
also correct on its own terms: this map is written up to four times a minute per vehicle, and
`device_model` never changes mid-trip.

**`R.drawable.ic_launcher_foreground` does not exist.** It is a *mipmap* launcher asset. Switched to
`R.drawable.ic_stat_zenxii`, the monochrome status-bar icon `FCMService` already uses.

---

## 6. Validation

| Suite | Result |
|---|---|
| `php -l` — all 7 touched PHP files | clean |
| **Firestore rules suite** | **9/9 suites, 211/211 tests pass** |
| **Full verifier** | **126 gates — 98 PASS / 21 INFO / 2 WARN / 5 FAIL** |
| **All 6 F13 gates** | **PASS** |
| Driver API over real HTTP | 401 no token · 401 bad token · **no blank-403 CSRF misfire** |
| `firestore.rules` diff | +148 / −6, the 6 being exactly the intended allowlist narrowing |

### 6.1 Rules suite — the highest-risk check

I changed the `tripLogs` driver allowlist, so the Jest suite was the decisive test. **211/211 pass.**
I had first read `transport_triplogs_sec2.test.js` and confirmed it covers *create* paths (SEC-2)
only, not the driver update allowlist — but ran the suite rather than rely on that reading.

### 6.2 Verifier — M2 → M3

| | After M2 | After M3 |
|---|---|---|
| Gates | 125 | **126** (+1) |
| FAIL | 5 | 5 — **identical set** |

`F1-3`, `F2-2`, `F3-2`, `F7-2`, `F7-3` — the same pre-existing tenant-data artifacts, unchanged.
PASS moved 103 → 98 with INFO 15 → 21: five gates became trivial passes because probe fixtures were
cleaned up between runs. No gate went PASS → FAIL.

### 6.3 Not run, and why

**The Teacher app was not compiled.** An Android build needs Gradle and, per the project's own
memory note, careful heap tuning on this machine (build one app at a time, `-Xmx1536m`). I verified
every symbol by inspection instead — `TokenManager.schoolCode`, `ApiService` DI provision,
`@HiltAndroidApp`, `TripLogDoc.{vehicleId, driverStaffId, gpsSessionId}`, the Retrofit annotations,
and the drawable — and that inspection is what caught the two defects in §5. **A compile remains
outstanding and should be run before this ships.** I am flagging it rather than implying coverage I
did not obtain.

---

## 7. Cross-module review

| Surface | Verdict |
|---|---|
| **Frozen F1–F12.1** | `Transport_service` unchanged in M3 (the D-2 edits were M2). The new API only *calls* it. |
| **Firestore rules** | tripLogs narrowed; live-position/breadcrumb blocks unchanged. 211/211 green. |
| **`LocationProvider`** | **Not modified.** Staff Attendance Phase 11 boundary respected; used read-only. |
| **Staff Attendance** | Untouched. `ClientMetadata.asMap()` unchanged — `required()` is additive. |
| **Parent app** | Untouched. M4. |
| **Checkpoint / boarding flows** | Still Firestore-direct and still permitted by the narrowed allowlist. Only the lifecycle moved. |
| **Provider-agnostic (A-1)** | Held. No map SDK in the driver app; `CH-TRN-F13-3` PASS. |
| **Firestore-only** | No RTDB. |
| **Tenant isolation** | The API resolves school from the token, never the body; ownership re-checked per call. |

---

## 8. Open items

| Item | Status |
|---|---|
| **Teacher app compile + on-device test** | **Outstanding** — see §6.3. Required before ship. |
| **D-7 — OSM tile hosting** | Open. Blocks M4. Recommend a commercial OSM tile provider for production. |
| **Firestore TTL policy** | Operator step, `DEPLOY_RUNBOOK.md` Phase 2b. |
| **§4 of the blocker report** | Now **resolved** by D-8 — the driver path runs readiness gating, CAS, audit and parent notification. |
| **Nothing deployed** | Rules, indexes and code all await your authorization. Until the rules deploy, the tripLogs hardening and the ordering guard are not live. |

---

## 9. Proposed M4 (not started)

Parent app live map — MapLibre + OSM tiles, one snapshot listener on one document, marker
interpolation between updates, stale handling. **Blocked on D-7** (tile hosting).

---

**Stopped for approval.** M4 will not begin until you authorize it.
