# ZENX — F13 TEACHER TRIP LIFECYCLE CLOSURE REPORT

**Date:** 2026-08-09
**Scope:** Remove the one identified blocker — shipped Teacher app writing `tripLogs` lifecycle fields directly.
**Teacher client:** **TEACHER_CLIENT_READY_FOR_D8**
**Rules deployment:** **READY_PENDING_OPERATOR_ROLLOUT**
**Repos:** Admin `integration/phase2`, Teacher `ankit/my_teacherFeature`, Parent `ankit/my_parentFeature` — all **UNCOMMITTED, UNPUSHED, NOT DEPLOYED**

---

## 1. Executive Summary

The API-based lifecycle was already implemented in the Teacher working tree: `startMyTrip()` calls `transport_driver_api/start_trip` and `completeMyTrip()` calls `complete_trip`. The audit confirmed it is structurally correct, actually wired to the UI, and server-authoritative.

But the audit also found a **second, previously unreported defect** that would have broken the driver app *after* the D-8 deploy, in the one operation the driver still legitimately performs:

> `ClientMetadata.asMap()` emits **five** fields — the three the rule requires plus `device_sdk` and `device_model`. The `tripLogs` update rule pins the writable set with `affectedKeys().hasOnly([...])`, and those two extras are in **neither the D-8 allowlist nor the pre-D-8 one**. The checkpoint write used `asMap()`, so **every checkpoint mark was rejected with permission-denied**.

The codebase already knew about this trap — `ClientMetadata.required()` exists specifically for `vehicleLivePositions`, whose rule uses the same `hasOnly` construct, and `TripTrackingService` correctly uses it. `tripLogs` is the only other `hasOnly`-guarded write in the entire ruleset, and it had been missed.

**Fix: one line** (`asMap()` → `required()`), plus two permanent rules tests pinning both payload shapes.

### A correction to my previous report

The prior readiness report stated that deploying D-8 *would* break trip start/complete for shipped drivers. Testing the actual shipped payload against both rulesets shows that is **not the full picture**: the shipped client's `tripLogs` writes are rejected by the **pre-D-8 allowlist too**, because of the same `device_*` extras. So D-8 is not what breaks those writes — they were already incompatible with HEAD's ruleset. The practical consequence for sequencing is unchanged (the new client must ship before or with the rules), but the characterisation was wrong and is corrected here.

I cannot read the live deployed ruleset, so one possibility remains open and is stated rather than resolved: either the deployed rules predate the `hasOnly` clause (and the shipped app works today), or driver `tripLogs` writes are already failing in production. §22 lists this as an operator check.

---

## 2. Original Blocker

D-8 narrows the `tripLogs` driver-writable allowlist to:

```
checkpoints · route_progress_pct · next_checkpoint_id · updated_at
app_version · client_platform · client_timestamp
```

The **committed** Teacher app wrote lifecycle fields directly:

```kotlin
suspend fun startMyTrip(tripId: String): Result<Unit> {
    val update = mapOf(
        "status" to "InProgress",
        "actual_start_at" to nowIso(),
        "updated_at" to nowIso()
    ) + ClientMetadata.asMap()
    firestoreService.updateDocument(TRIP_LOGS, tripId, update)
}
```
`completeMyTrip()` likewise wrote `status`, `actual_end_at`, `endTime`, `endOdometer`, `distance`, `fuelUsed`, `notes`.

---

## 3. Current Teacher App Architecture

```
DriverTripDetailScreen (UI)
        ↓
TransportFirestoreRepository
        ├── startMyTrip()     → ApiService.startTrip()     → PHP  ─┐
        ├── completeMyTrip()  → ApiService.completeTrip()  → PHP  ─┤ server-authoritative
        │                                                          │  lifecycle
        ├── markCheckpointReached()/Skipped()  → Firestore  ───────┘  (allowlisted fields only)
        └── markStudentBoarded/Dropped/NoShow  → transportAttendance

TripTrackingController / TripTrackingService (F13.1 GPS)
        └── vehicleLivePositions (update) + vehicleBreadcrumbs (create)   [required() metadata]
```

**One repository, one lifecycle, one writer.** No parallel lifecycle, no second Firestore writer, no duplicate repository was created.

---

## 4. Old Direct-Firestore Lifecycle Path

`updateDocument(TRIP_LOGS, …)` carrying `status` + timestamps + completion telemetry. This bypassed `Transport_service`, and therefore skipped: LOG-1 driver-readiness re-validation, the CON-6 CAS transition guard, the audit row, the `trip.started` parent notification, XMOD-1 route denormalisation, and F13 GPS session open/close.

---

## 5. New API-Mediated Lifecycle Path

```
Driver taps Start
   ↓  POST transport_driver_api/start_trip {trip_id}      (Bearer token; driver id NEVER sent)
   ↓  _assertOwnTrip()  — caller must be the assigned driver
   ↓  Transport_service::startTrip()  — canonical transition
   ↓  Firestore tripLogs (server-side)
   ↓  200 → UI refresh → GPS tracking starts only once status == InProgress
```
Completion is symmetric, and additionally disarms the F13 kill switch and tears down the live-position session.

---

## 6. Files Changed

### Changed by me in this task — exactly one file, one line

| File | Change | Why |
|---|---|---|
| `TransportFirestoreRepository.kt` (Teacher) | `updateCheckpointState()`: `ClientMetadata.asMap()` → `ClientMetadata.required()` + explanatory comment | `asMap()`'s `device_sdk`/`device_model` are outside the `hasOnly` allowlist, so every checkpoint write was rejected |

### Added by me in this task (Admin repo — tests only)

| File | Purpose |
|---|---|
| `firebase-rules/tests/f13_gps_sec.test.js` | +2 tests pinning both payload shapes (broken and fixed) |

### Pre-existing uncommitted F13 work (audited, not authored by me)

Teacher (10 dirty files, unchanged count): `ApiService.kt` (+2 endpoints), `TransportFirestoreRepository.kt`, `DriverTripDetailScreen.kt`, `ClientMetadata.kt` (+`required()`), `AppModule.kt`, `AndroidManifest.xml`, `build.gradle.kts`, and untracked `TripTrackingController.kt`, `TripTrackingService.kt`, `data/maps/`.

**Files removed: none.**

---

## 7. Complete Trip Lifecycle Audit

| # | Item | Finding |
|---|---|---|
| 1 | Start Trip | ✅ API-mediated |
| 2 | Complete Trip | ✅ API-mediated |
| 3 | **Cancel Trip** | ✅ **Deliberately absent by design** — `cancel_trip` is documented as a panel-only operation: *"cancelling a trip is an operational decision that belongs to the panel, not to the driver. A driver who cannot continue completes the trip or raises SOS."* No driver cancel path exists to migrate; this is not a gap |
| 4 | Checkpoint updates | ✅ Firestore, allowlisted fields only (fixed this task) |
| 5 | Route progress | ✅ `route_progress_pct` derived client-side, allowlisted |
| 6 | Current trip state | ✅ `refresh()` after each mutation |
| 7 | Error handling | ✅ server message surfaced verbatim, never swallowed |
| 8 | Retry behaviour | ✅ `mutating` flag guards re-entry; server transition is CAS-guarded |
| 9 | Network failure | ✅ `runCatching` → `Result.failure` → error shown |
| 10 | Loading states | ✅ `mutating = true` during the call |
| 11 | UI state refresh | ✅ `refresh()` then conditional GPS start |
| 12 | Firestore listeners | ✅ reads only; no lifecycle listener writes back |
| 13 | API response handling | ✅ `serverMessage()` extracts the server's own reason |
| 14 | Authentication | ✅ Bearer token; 401 verified live |
| 15 | Tenant binding | ✅ `school_id` from token claims, never from the body |
| 16 | Driver-to-trip authz | ✅ `_assertOwnTrip()` against token uid |

**The API does not merely exist — the UI uses it.** `DriverTripDetailScreen.kt:384` calls `startMyTrip`, `:422` calls `completeMyTrip`, `:403`/`:413` call the checkpoint methods.

---

## 8. Direct `tripLogs` Write Audit

Repo-wide search for `TRIP_LOGS` — **5 references, 1 write**:

| Location | Operation | Classification |
|---|---|---|
| `TransportFirestoreRepository.kt:284` | `queryDocumentsAs` | read-only |
| `:340` | `getDocumentAs` | read-only (checkpoint array fetch) |
| **`:369`** | **`updateDocument`** | **allowed checkpoint/progress write — allowlist-exact** |
| `:410` | `getDocumentAs` | read-only (boarding-event inheritance) |
| `Constants.kt:57` | constant definition | unrelated |

**Zero obsolete direct lifecycle writes remain.** No occurrence anywhere in the app can now set `status`, `actual_start_at`, `actual_end_at`, `endTime`, `endOdometer`, `distance`, `fuelUsed` or `notes` on `tripLogs`.

`TripTrackingService.kt` writes only `vehicleLivePositions` and `vehicleBreadcrumbs` — no `tripLogs` access, so the GPS layer introduces no second lifecycle writer.

---

## 9. API Contract Audit

`Transport_driver_api.php` — `MY_Controller` bypassed in favour of `CI_Controller::__construct()` (no PHP session exists for an app call).

| Element | Finding |
|---|---|
| Request | `@FormUrlEncoded POST`; field names match PHP exactly (`trip_id`, `end_odometer`, `distance_km`, `fuel_used`, `notes`) |
| Authentication | `api_auth->require_auth()` — dies 401 on invalid token |
| Tenant resolution | `school_id` from token claims; 409 if unresolvable |
| Driver identity | **token uid only** — never read from the body |
| Trip ownership | `_assertOwnTrip()`; missing trip and foreign trip both return **403 with an identical message**, so trip ids cannot be enumerated |
| Trip state / transition validity | delegated to `Transport_service` (CAS guard) |
| Timestamps / odometer / distance / fuel / notes | server-side, cast and trimmed from POST |
| Server-side writes | canonical service only |
| Response | `200 {status:success,…}`; **422** carries the verbatim business reason; **500** is generic + logged |
| Routing | **no explicit route needed** — CI default routing resolves it; verified live (§16) |

Authorization did **not** move back into Firestore client writes. The server remains authoritative.

---

## 10. D-8 Compatibility Verification

Proven empirically against the real rules file, not by inspection.

| Payload | HEAD (pre-D-8) | WORKTREE (D-8) |
|---|---|---|
| Shipped `startMyTrip` (`status` + `asMap()` extras) | **REJECTED** | **REJECTED** |
| Shipped checkpoint (allowlisted + `device_sdk`/`device_model`) | **REJECTED** | **REJECTED** |
| Fixed checkpoint (allowlisted + `required()`) | **ACCEPTED** | **ACCEPTED** |

The Teacher app now requires **no** direct client mutation of `status`, `actual_start_at`, `actual_end_at`, `endTime`, `endOdometer`, `distance`, `fuelUsed` or `notes`. It writes only `checkpoints`, `route_progress_pct`, `next_checkpoint_id`, `updated_at` and the three required metadata fields — exactly the D-8 allowlist.

**D-8 was not weakened. `firestore.rules` is byte-identical to before this task** (`git diff --stat` still 218/8, unchanged).

---

## 11–14. Lifecycle Operation Verification

| Operation | Verification |
|---|---|
| **11. Start trip** | API-mediated; UI wired; GPS starts only after `status == InProgress`; preflight blocks on location-services-off and permission-missing with specific messages; direct `status` write proven rejected by rule test |
| **12. Complete trip** | API-mediated; on refusal (e.g. a checkpoint still Pending) `mutating` clears, the server reason is shown, **and tracking deliberately keeps running because the trip is still live** |
| **13. Cancel trip** | Not a driver operation by documented design (§7.3) — nothing to verify or migrate |
| **14. Checkpoint / progress** | Fixed and proven: `required()` payload ACCEPTED, `asMap()` payload REJECTED — both pinned as permanent tests |

---

## 15. Error / Retry Behaviour

- Failures are **never swallowed**: `Result.failure` → `error` in UI state.
- 422 business reasons ("Driver licence expired", "Route is suspended") are surfaced **verbatim** — the stated purpose of routing through the canonical service.
- 500s are generic to the user and logged server-side.
- Duplicate submission is guarded at two layers: the `mutating` flag client-side, and the CAS transition guard server-side.
- Network failure is caught by `runCatching`.

---

## 16. Authentication Verification

Live, without forging credentials:

```
POST transport_driver_api/start_trip     → HTTP 401
POST transport_driver_api/complete_trip  → HTTP 401
```

**401, not 404** — proving the routes resolve through CI default routing *and* the Bearer gate fires. Endpoint reachability and the auth boundary are confirmed; no token was forged and no session was bypassed.

---

## 17. Tenant-Isolation Verification

- Client sends **no** school id; the server derives `school_id` from token claims.
- `Firestore_service::init($school_id)` scopes every server write.
- Checkpoint writes are additionally bound by `isSameSchoolWrite()` at the rules layer.
- Rules tests assert cross-tenant denial for `tripLogs`, `vehicleLivePositions` and `vehicleBreadcrumbs`.
- No hardcoded tenant or driver id in any changed file (grep-verified).

---

## 18. RBAC Verification

Trip authorization is **ownership-based, not role-based** — consistent with the established D-8 decision that the driver app is authorized by trip ownership rather than an RBAC grant. `_assertOwnTrip()` compares the trip's `driver_staff_id` against the token uid. The Firestore rule mirrors this via `isDriverOfTrip()`/`driverIs()`, so server and rules agree on one authorization concept. No RBAC change was made, and none was weakened.

---

## 19. Build / Test Results

| Check | Result |
|---|---|
| `:app:compileDebugKotlin` | **BUILD SUCCESSFUL** (exit 0) |
| `:app:testDebugUnitTest` | **BUILD SUCCESSFUL** (exit 0) — 4 suites, none transport-related |
| Firestore rules suite | **352 / 352 PASS**, 15/15 suites (was 350; +2 payload tests) |
| PHP `php -l` | not applicable — no PHP changed |

**One environment issue worth recording:** a full rules run immediately after the Gradle build failed *every* suite. The cause was not the rules — it was JVM memory exhaustion (`insufficient memory for the Java Runtime Environment … paging file is too small`) from the Gradle daemon still resident on this 5.8 GB box. After `./gradlew --stop`, all 15 suites passed. Build and emulator must not run concurrently here.

Separately, `a driver CANNOT append a breadcrumb into another tenant` timed out once at 62 s and passed at 50 ms on re-run — an intermittent emulator flake, not a logic failure.

---

## 20. RTDB Verification

No RTDB introduced. `FirebaseDatabase`, `getReference`, `com.google.firebase.database` — **zero** occurrences in `TransportFirestoreRepository.kt` or `data/location/`. The Teacher app remains Firestore-only.

---

## 21. Parent App Dependency Findings

Inspected **separately**, as instructed, and **not** bundled into the Teacher fix.

| Question | Finding |
|---|---|
| Required for current F13 rules? | No — Parent is a pure **consumer** |
| Any Firestore writes? | **None** in the F13 surface — read-only |
| Collections touched | `mapsConfig` (read), `vehicleLivePositions` (read) |
| Touches `mapsSecrets`? | **No** — referenced only in a comment noting it is denied |
| Touches `vehicleBreadcrumbs`? | **No** — correct; parents are denied movement history |
| Read shape compatible? | ✅ `observeDocumentAs()` on a deterministic doc id — a **single-document listen**. A collection query would have been denied, since `uid in resource.data.…` is not query-provable |
| Requires Admin API support? | No |
| Safe to commit independently? | **Yes** — additive and read-only. It degrades to "no live map" if rules ship later, and cannot break anything if they ship first |

**Parent does not block the rules deployment.** No Parent code was modified.

---

## 22. Remaining Operator / Device Verification

**OPERATOR VERIFICATION REQUIRED** — not faked, not simulated:

1. **Real driver lifecycle on a device** — install the new build, start a trip, mark a checkpoint, complete the trip. I stopped at the boundary where real authentication and irreversible trip/attendance writes would be required; no test driver, trip, or account was created.
2. **Determine the live deployed ruleset.** This resolves the open question in §1: if the deployed rules already contain the `hasOnly` clause, driver `tripLogs` writes are failing today and the checkpoint fix is *urgent*, not merely pre-deployment hygiene. `firebase firestore:rules get` (or the console) answers it in a minute.
3. **ATS browser smoke test** — still outstanding from the previous gate, unchanged.

---

## 23. Commit Readiness

**Nothing committed** — awaiting explicit approval, as instructed.

| Repo | Files | Recommended scope |
|---|---|---|
| **Teacher** | 10 | One commit: F13 API-based trip lifecycle + GPS tracking + maps + the checkpoint metadata fix. Coherent and self-contained |
| **Admin** | rules tests only (this task) | Fold into the existing rules-certification work |
| **Parent** | 7 | **Separate commit** — independent, read-only, not part of the Teacher lifecycle fix |

---

## 24. Rules Deployment Readiness

### Final classification

> **TEACHER_CLIENT_READY_FOR_D8**

The Teacher app no longer depends on any direct Firestore lifecycle write that D-8 rejects. Its only `tripLogs` write is allowlist-exact, and both the accepted and rejected payload shapes are pinned by tests.

> **FIRESTORE_RULES_DEPLOYMENT: READY_PENDING_OPERATOR_ROLLOUT**

The code-side blocker is closed. What remains is not engineering but rollout, in this order:

1. Approve and commit the Teacher changes
2. Build and release the Teacher app
3. Roll out to driver devices
4. Verify one real driver lifecycle on a device
5. Confirm driver devices are on the compatible build
6. Commit Parent F13 separately (independent)
7. Enable the `vehicleBreadcrumbs` TTL policy (DEPLOY_RUNBOOK Phase 2b)
8. Deploy the complete Firestore ruleset
9. Post-deployment verification
10. Close the W1 security/deployment gate
11. Return to the HRMS roadmap → W2

Until drivers are on the new build, deploying rules still risks the driver path. That ordering constraint is unchanged — but it is now a **release-management** dependency rather than an unwritten-code one.

---

## Constraints Honoured

- **D-8 not reverted, not weakened** — `firestore.rules` byte-identical to before this task.
- No second trip lifecycle, no second Firestore writer, no duplicate repository.
- Transport not redesigned; F13 architecture not restarted.
- No production test data, no forged authentication, no RBAC or tenant bypass.
- No commit, no push, no merge, **no rules deploy, no TTL command run**.
- W2 not started.
