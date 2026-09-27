# Transport F13.1 — Live GPS · FEATURE-COMPLETE AND FROZEN

**Frozen:** 2026-08-08, by operator approval of M4.
**Scope:** F13.1 = driver live GPS · server-created hot document · parent live tracking · trip
start/stop lifecycle.

> **No new features may be added under F13.1.** Anything further belongs to F13.2 / F13.3, and those
> begin only after the current implementation is **committed, merged and deployed successfully**.

> Release constraints still in force: no commits, no rebases, no release branch, no push, no
> deployment.

---

## 1. Milestones

| M | Scope | Status |
|---|---|---|
| **Phase 1** | Architecture verification — 10 gaps found, 2 blockers, 7 decisions | Approved |
| **M1** | Data foundation — collections, rules, indexes, contract, TTL | Approved |
| **M2** | Server lifecycle — kill switch, fix ordering, stale sweeper | Approved |
| **M3** | D-8 canonical lifecycle + driver foreground GPS | Approved |
| **M4** | Parent live map — MapLibre, provider-agnostic | Approved |

## 2. Decisions of record

| # | Decision | Outcome |
|---|---|---|
| **D-1** | Hot doc **server-created**, driver may only update | Client create/delete forbidden; existing rules helpers reused unmodified |
| **D-2** | Scoped exception to the F1–F12.1 freeze for `startTrip`/`completeTrip`/`cancelTrip` | Server-authoritative kill switch |
| **D-3** | Native Firestore TTL for breadcrumbs | `expires_at` as a **Timestamp**; operator step in `DEPLOY_RUNBOOK.md` Phase 2b |
| **D-4** | Anti-spoofing mirrors Staff Attendance | Mock, poor-accuracy, implausible-speed all rejected |
| **D-5** | Ship F13.1 only | Replay, admin fleet map, geofence, ETA, analytics deferred |
| **D-6** | Google Maps API key | **VOID** — superseded by A-1 |
| **D-7** | Commercial OSM tile provider | Style URL injected at build time; empty default fails visibly |
| **D-8** | **One canonical lifecycle** | Driver app calls `Transport_service`; ownership-based authorization, no RBAC grant to drivers |
| **A-1** | Mapping-provider agnosticism | Canonical telemetry only; renderer confined to one file per app |

## 3. Enforcement — seven gates

| Gate | Protects |
|---|---|
| `CH-TRN-F13-1` | Server-created hot doc, driver field allowlist, TTL as a real Timestamp |
| `CH-TRN-F13-2` | Viewer resolver cannot drift from the canonical recipient resolver |
| `CH-TRN-F13-3` | No map-provider data in the GPS data layer |
| `CH-TRN-F13-4` | Kill switch wired into all three transitions; GPS bridge stays thin |
| `CH-TRN-F13-5` | Fix ordering enforced in rules **and** service |
| `CH-TRN-F13-6` | One canonical lifecycle; `tripLogs` rule permits no client trip-state write |
| `CH-TRN-F13-7` | Parent map provider-agnostic and Firestore-only (cross-repo scan) |

Plus `CH-TRN-F7-1`, **re-scoped once** from "GPS fields must be null" to "may be written only by the
GPS ingest path" — re-scoped, never deleted, as instructed.

## 4. Final validation

| Suite | Result |
|---|---|
| Verifier | **127 gates — 105 PASS / 15 INFO / 2 WARN / 5 FAIL** |
| All F13 gates | **7 / 7 PASS** |
| `transport_probe_f13` | **79 / 0** |
| `transport_probe_f13_e2e` | **29 / 0** |
| Firestore rules suite | **211 / 211** |
| Teacher app | **BUILD SUCCESSFUL** + on-device install/launch/DI/manifest verified |
| Parent app | **BUILD SUCCESSFUL** |

The 5 FAIL (`F1-3`, `F2-2`, `F3-2`, `F7-2`, `F7-3`) are pre-existing tenant-data artifacts.
**No gate went PASS → FAIL at any point across F13.**

## 5. Uncommitted footprint

**Admin** — `integration/phase2` @ `758c924`:
new `Transport_gps_service.php`, `Transport_driver_api.php`, `Transport_gps_cron.php`,
`Transport_probe_f13.php`, `Transport_probe_f13_e2e.php`; modified `Transport_service.php` (D-2 only),
`Transport_verifier.php`, `firestore.rules`, `firestore.indexes.json`, `config.php`, `DEPLOY_RUNBOOK.md`.

**Teacher** — `ankit/my_teacherFeature`:
new `TripTrackingService.kt`, `TripTrackingController.kt`; modified `build.gradle.kts`,
`AndroidManifest.xml`, `ApiService.kt`, `TransportFirestoreRepository.kt`, `AppModule.kt`,
`DriverTripDetailScreen.kt`, `ClientMetadata.kt`.

**Parent** — `ankit/my_parentFeature`:
new `VehicleLivePositionDoc.kt`, `LiveBusMap.kt`; modified `build.gradle.kts`,
`TransportFirestoreRepository.kt`, `MyBusScreen.kt`.

## 6. Release activities — not implementation, and not blockers to the freeze

1. **Commercial OSM tile provider** — account + `-PZENXII_MAP_STYLE_URL`. Without it the parent map
   shows "Map isn't configured for this build".
2. **Firestore TTL policy** on `vehicleBreadcrumbs.expires_at` — `DEPLOY_RUNBOOK.md` **Phase 2b**.
   Never created by `firebase deploy`; skipping it grows the collection without bound.
3. **Teacher UI validation** with a real driver account.
4. **Parent UI validation** with a real parent account whose child is on a running trip.
5. **Deploy ordering** — indexes → rules → code. Until the rules deploy, the parent read rule, the
   `tripLogs` hardening and the fix-ordering guard are **not live**.
6. **Parent APK size** — 72.6 MB unminified debug; check the minified release build.

## 7. Carried forward

* **PF-1** — vehicle-deletion cascade (`TRANSPORT_POST_FREEZE_VALIDATION_BACKLOG.md`).
* **F13.2** — admin fleet map, trip replay.
* **F13.3** — geofence automation, ETA prediction, driver-behaviour scoring. **D-4 anti-spoofing
  becomes mandatory here**, since geofence crossings would begin firing checkpoints automatically.
* Deferred platform-wide items from the module certification §8 — unchanged, still out of scope.

## 8. Artefacts

`TRANSPORT_F13_PHASE1_ARCHITECTURE_VERIFICATION.md` · `…M1…` · `…M2…` · `…M3_BLOCKER.md` ·
`…M3_COMPLETION_REPORT.md` · `…M3_ONDEVICE_VALIDATION.md` · `…M4_COMPLETION_REPORT.md` · this record.

---

**F13.1 is FEATURE-COMPLETE and FROZEN.** Next Transport development is F13.2/F13.3, and only after
this work is committed, merged and deployed.
