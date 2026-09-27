# F13.1 M3 — BLOCKER: the driver app bypasses the server trip lifecycle

**Date:** 2026-08-08
**Raised during:** M3 (Teacher/Driver foreground location service), before any Kotlin was written
**Status:** **STOPPED for approval.** No M3 code written. No scope expanded.

> Per the standing instruction: a blocker was discovered, so I stopped immediately, classified it,
> and am reporting production impact before doing anything else.

---

## 1. Classification

| | |
|---|---|
| **Severity** | **BLOCKER** — F13.1 cannot function on the driver path as designed |
| **Type** | Architectural incompatibility, **pre-existing**, surfaced by M3 |
| **Introduced by** | Not by M1/M2. The divergence predates F13 entirely. |
| **Blocks** | M3 and M4. M1/M2 remain correct and complete for the admin-driven path. |

---

## 2. The finding

**The Teacher app writes `tripLogs` directly to Firestore. It never calls the PHP
`Transport_service`.**

`TransportFirestoreRepository.kt`:

```kotlin
suspend fun startMyTrip(tripId: String): Result<Unit> {
    val update = mapOf(
        "status" to "InProgress",
        "actual_start_at" to nowIso(),
        "updated_at" to nowIso()
    ) + ClientMetadata.asMap()
    firestoreService.updateDocument(Constants.Firestore.TRIP_LOGS, tripId, update)
}
```

`completeMyTrip()` is the same shape. Both bypass PHP entirely.

This is **by design, not a bug**: F9 built `isDriverOfTrip()` and a "driver-update allowlist" into
the `tripLogs` rule precisely so a driver can update their own trip from the app. Two writers exist
for the same document — the admin panel through PHP, and the driver app through Firestore — and they
do **not** do the same work.

I checked for a compensating mechanism and there is none: `functions/index.js` contains **no
Firestore triggers at all**, and nothing transport-related.

---

## 3. Why this blocks F13.1

M2 wired the GPS session into the PHP lifecycle, as approved under D-2. On the driver path that code
never executes:

| Step | Admin path (PHP) | **Driver app path** |
|---|---|---|
| `live_tracking_enabled = true` | ✅ set by `startTrip` | ❌ never set |
| Hot doc created (`openSession`) | ✅ | ❌ **never created** |
| Viewer allowlist populated | ✅ | ❌ |
| `live_tracking_enabled = false` at end | ✅ | ❌ |
| Session torn down | ✅ | ❌ (only the sweeper, ≥30 min later) |

**The consequence is total, not partial.** D-1 forbids client creation of the hot doc, so when the
driver's foreground service tries to `update` `vehicleLivePositions/{schoolId}_{vehicleId}`, the
document does not exist and the write fails. A driver who starts their trip from the app gets **no
live tracking whatsoever**, and the parent map stays empty.

Building the M3 foreground service now would produce a component that streams into a document that
is never created. That is why I stopped rather than continuing.

---

## 4. A second, larger finding — pre-existing, outside F13 scope

The same bypass means app-started trips skip **everything** `Transport_service::startTrip()` does,
not just GPS:

| Skipped on the driver path | What it is | Consequence |
|---|---|---|
| `_validateDriverReadiness()` | **LOG-1** — re-checks licence/medical expiry, On_Leave/Suspended, HR-Inactive *on the day* | An unfit or suspended driver can start a trip from the app |
| `casUpdateEntity` | **CON-6** — atomic Ready→InProgress | Concurrent starts are not serialised |
| `emitAudit('trip', …, 'started')` | audit trail | No audit row for app-started trips |
| `_notify('trip.started')` | parent notification | **Parents are not told the bus has started** |
| `_setVehicleCurrentRoute()` | **XMOD-1** — denormalises route onto the vehicle | Teacher-app route badge and driver SOS route context go unpopulated |

The verifier gates `CH-TRN-LOG1-1`, `CH-TRN-CON6-1`, `CH-TRN-XMOD1-1` all PASS — correctly, because
they are **source** gates asserting the PHP does these things. Nothing asserts that the *app* uses
that path. That is the gap in the test strategy, and it is worth fixing regardless of which option
below you choose.

**I am not proposing to fix this now.** It is outside F13 scope and outside the D-2 exception. It is
reported because it is material, and because option A below would fix it as a side effect.

---

## 5. Options

### A — Driver app calls the PHP endpoints instead of writing Firestore

Route `startMyTrip`/`completeMyTrip` through `transport_trip/start` and `transport_trip/complete`.
The app already has Retrofit (`ApiService.kt`, `AuthInterceptor.kt`).

*Fixes the blocker **and** §4 in one move.*

**But it is blocked by RBAC as things stand.** Those endpoints require `Transport:edit`, and I
verified against live tenant data that the `Driver` role holds **neither `Transport` nor
`Operations`** — it is one of the 17 roles that lost access at the graded-RBAC retirement. Every
call would 403. Making this work needs a deliberate RBAC grant to drivers, which is a policy
decision you explicitly reserved ("schools assign Transport permissions according to their own
requirements"), plus a rewrite of the app's trip flow. Largest change, largest benefit.

### B — Cloud Function trigger on `tripLogs` *(recommended for F13.1)*

An `onDocumentUpdated('tripLogs/{id}')` trigger opens the session when `status` becomes
`InProgress`, and closes it on `Completed`/`Cancelled`.

* Works for **both** writers — admin and app — with no app change and no RBAC change.
* Keeps **D-1 intact**: the session stays server-created; only the creator moves from PHP to a CF.
* Firestore-native; no RTDB; no new client surface.
* Cost: one function invocation per trip transition — negligible.

Caveat, stated plainly: `functions/index.js` currently has **no Firestore triggers at all** (it is
purely the `MARK_REGISTRY` push dispatcher), so this introduces a new pattern to that codebase, and
the session-open logic would exist in JS as well as PHP. I would keep the JS to the minimum — resolve
the roster to viewer UIDs and write the doc — and add a verifier gate pinning the two in agreement,
as `CH-TRN-F13-2` already does for the resolver.

### C — Relax D-1 to allow client creation

**Not recommended.** The client would then supply `authorized_viewer_uids` itself, so the allowlist —
the entire tenant-isolation control for parent reads — would become client-asserted. A driver could
add arbitrary UIDs or omit the real ones. This is precisely the hole D-1 was created to close.

### D — New driver-authenticated PHP endpoint for session open/close

Keeps the app's Firestore-direct trip flow and adds one small server call. But the admin panel
authenticates admin *sessions*, not driver Firebase tokens, so this needs a new authentication path
into the panel — a meaningful new attack surface for a narrow benefit.

---

## 6. Recommendation

**Option B for F13.1**, because it unblocks M3/M4 without touching RBAC policy, without changing the
app's existing trip flow, and without weakening D-1.

Then, separately and later, **option A as the proper fix for §4** — the driver path genuinely should
run driver-readiness gating, CAS, audit and the parent "bus started" notification. That is a
Transport-wide correctness question, not an F13 one, and deserves its own authorization.

---

## 7. What is unblocked and could proceed now

If you want M3 to move while this is decided, these parts have no dependency on the session doc:

1. **The Location Services precondition** — the exact behaviour you specified. `LocationProvider`
   already exposes `isLocationEnabled()` and `hasLocationPermission()`, so this is a UI-layer guard
   on the Start Trip button plus the copy *"Please enable Location Services to start the trip."*
2. **The foreground service skeleton** — manifest permissions
   (`FOREGROUND_SERVICE`, `FOREGROUND_SERVICE_LOCATION`), the `foregroundServiceType="location"`
   declaration, the persistent notification, and the throttle/suppression logic mirroring the server
   constants.

Only the final step — actually writing positions — depends on the session existing.

Say the word and I will proceed with those two, or hold M3 entirely until D-8 is decided.

---

## 8. Decision required — D-8

**How should the GPS session be opened and closed for app-started trips?**
A (app calls PHP, needs an RBAC grant) · **B (Cloud Function trigger — recommended)** ·
C (client-created, not recommended) · D (new driver-auth endpoint).

---

**Status: stopped for approval. No commits, rebases, branches, pushes, or deployments.**
