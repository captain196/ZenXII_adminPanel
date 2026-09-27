# Transport F13.1 — Milestone M1 Completion Report

**Date:** 2026-08-08
**Branch:** `integration/phase2` @ `758c924` (working tree — uncommitted)
**Milestone:** M1 — Data foundation (contract, rules, indexes, gates)
**Status:** **COMPLETE — stopped for approval.**

> Release constraints honoured: no commits, no rebases, no release branch, no push, no deployment.
> **No frozen F1–F12.1 production code was modified.** The D-2 changes to
> `startTrip`/`completeTrip`/`cancelTrip` are M2 work and have not been made.

---

## 1. What M1 delivers

M1 is the **data contract**. It ships zero writers by design — `Transport_gps_service` performs no
Firestore writes at all, which is why the probe can assert `vehicleLivePositions` and
`vehicleBreadcrumbs` are still empty. Writers arrive in M2.

| # | Deliverable | File |
|---|---|---|
| 1 | F13 service — collections, field contracts, anti-spoofing gates, throttle maths, viewer resolver, document builders | **NEW** `application/libraries/Transport_gps_service.php` |
| 2 | Security rules — `vehicleLivePositions` + `vehicleBreadcrumbs` | `firebase-rules/firestore.rules` (+104, **−0**) |
| 3 | Composite indexes ×4 | `firebase-rules/firestore.indexes.json` |
| 4 | `CH-TRN-F7-1` re-scoped + 3 new F13 gates | `application/controllers/Transport_verifier.php` |
| 5 | M1 runtime probe — 52 assertions | **NEW** `application/controllers/Transport_probe_f13.php` |
| 6 | TTL operator step (D-3) | `DEPLOY_RUNBOOK.md` → new **Phase 2b** |
| 7 | Mapping-provider amendment A-1 + D-7 | `TRANSPORT_F13_PHASE1_ARCHITECTURE_VERIFICATION.md` |

---

## 2. The two blockers, resolved as approved

### D-1 — hot doc is server-created

Client `create` and `delete` on `vehicleLivePositions` are **`if false`**. The driver may only
`update`, and only the 13 fields in `DRIVER_MUTABLE_FIELDS`, enforced by
`affectedKeys().hasOnly()`. This lets the *existing* `isDriverOfTrip()` helper be reused **without
modification** — no shared-rule edit, which matters for a file other sessions edit concurrently.

The alternative could not be secured: `isDriverOfTrip()` reads `resource.data.driver_staff_id`
(null on create) and `isSameSchool()` is deliberately permissive when `resource == null`, so a
client-create rule would have had neither a driver check nor a tenant check.

### D-2 — kill switch is wired into the rule now, the writer in M2

The update rule requires `resource.data.live_tracking_enabled == true`. Once M2 makes
`completeTrip`/`cancelTrip` flip that flag false, **every further driver write is rejected by
Firestore itself**, regardless of what the client believes or whether it crashed. The enforcement
point exists in M1; the flipper lands in M2.

---

## 3. Decisions as implemented

| Decision | Implementation |
|---|---|
| **D-1** server-created hot doc | `allow create, delete: if false`; server builder `buildSessionDoc()` |
| **D-2** kill switch | Rule gates update on `live_tracking_enabled == true` |
| **D-3** native Firestore TTL | `breadcrumbExpiresAt()` emits a **Timestamp sentinel**; runbook Phase 2b; fallback index shipped |
| **D-4** anti-spoofing | `validatePosition()` mirrors `Attendance_policy` gates 3–4 via `geofence_helper`; **no allow-mock escape hatch** for a bus |
| **D-5** F13.1 only | `stopGeofenceEvents` **deliberately not created** — geofence automation is deferred |
| **D-6** Maps key | **VOID** under amendment A-1 |
| **A-1** provider-agnostic | Canonical telemetry only; enforced by new gate `CH-TRN-F13-3` |

### The TTL trap, closed

Firestore TTL acts **only** on a `Timestamp`-typed field. An ISO-8601 *string* is silently ignored
and the collection grows forever — ~86k docs/day/school at 3,000 students, with no error anywhere.
`breadcrumbExpiresAt()` therefore returns the `__firestore_type => timestamp` sentinel that
`FirestoreRestClient::encode()` converts to a native `timestampValue`.

The probe tests this against the **real encoder** (reached by reflection — the class is
`FirestoreRestClient`, its constructor needs three args, and `encode()` is private): §E3 asserts the
sentinel encodes as `timestampValue`, and §E4 asserts a plain ISO string encodes as `stringValue`,
demonstrating exactly what would have gone wrong.

---

## 4. Validation

| Suite | Result |
|---|---|
| `php -l` on all touched PHP | clean |
| `firestore.indexes.json` parse | valid — 260 indexes |
| `firestore.rules` brace balance | 418/418 balanced, 168 match blocks |
| **`transport_probe_f13` (new)** | **52 pass / 0 fail** |
| **Full verifier** | **123 gates — 101 PASS / 16 INFO / 2 WARN / 4 FAIL** |
| Probe regression (37 probes) | all pass individually; `f10` retains 1 pre-existing failure |

### 4.1 Verifier — before vs after M1

| | Before M1 | After M1 |
|---|---|---|
| Gates | 120 | **123** (+3 F13) |
| PASS | 90 | **101** |
| FAIL | 5 | **4** |

FAIL went **down**, not up. `CH-TRN-F5-3` moved FAIL → WARN because the stale pending posts were
reconciled. The remaining 4 (`F1-3`, `F2-2`, `F3-2`, `F7-3`) are the same pre-existing tenant-data
artifacts documented at F12.1 close-out. All three new gates pass:

* `CH-TRN-F13-1` — hot doc server-created + kill-switch gated; driver allowlist matches the service (13 fields); breadcrumbs append-only and driver-bound; TTL emitted as a native Timestamp
* `CH-TRN-F13-2` — F13 viewer resolver and the canonical notifier resolver agree
* `CH-TRN-F13-3` — GPS data layer stores canonical telemetry only

And the re-scoped gate still holds: `CH-TRN-F7-1 PASS — 30 docs sampled; all live-GPS fields NULL`.

### 4.2 A false alarm I chased down, and what caused it

The batch regression run reported **13 probes failing with 48 failures** — alarming against a
baseline of 2. It was **not** a regression.

Every failure was an *empty* result: "notify emitted nothing", "onboard delta = 0 (expected 4)",
"order wrong or count!=3: `[]`". That is the signature of seeded fixtures not being readable back,
not of broken logic. I re-ran **all 13 individually**: 12 passed cleanly, and `f10` returned its
known pre-existing failure.

Cause: the 123-gate verifier and 37 write-heavy probes ran back-to-back against the same tenant, and
Firestore throttled the writes. My M1 changes could not have caused it in any case — the rules were
never deployed, and the admin SDK bypasses rules regardless.

**Practice note for M2:** the Transport probe suite must not be run immediately after a full verifier
pass. Batch results in that window are not trustworthy, and a future session could easily read them
as a real regression.

### 4.3 `f10` — unchanged by the rules edit

`transport_probe_f10` asserts against `firestore.rules`, which M1 modified, so this needed checking
rather than assuming. It now reports **1** failure (`rule fragments missing: school_id + '_' +
request.auth.token.user_id`), down from 2 — the other was data-dependent and now passes. The
remaining one is the pre-existing assertion recorded at F12.1 close-out. The M1 rules edit is
**+104 / −0**: purely additive, so nothing that probe checks was removed.

---

## 5. Cross-module review

| Surface | Verdict |
|---|---|
| **Frozen F1–F12.1** | **Untouched.** No production Transport file modified. 126 graded RBAC gates intact, 0 legacy RBAC, all 4 F12.1 CMP gates PASS. |
| **Firestore rules** | +2 match blocks, additive. Reuses `isSameSchool`, `isSameSchoolWrite`, `isStaff`, `isDriverOfTrip`, `hasClientMetadata`, `driverIs` — **no shared helper modified**. |
| **Indexes** | +4, additive. Extra indexes are harmless to existing code. |
| **Parent App** | No change in M1. M4 will swap unused Google Maps deps for MapLibre — verified zero usage, so zero regression. |
| **Teacher App** | No change in M1. `LocationProvider` (Staff Attendance Phase 11) deliberately untouched — F13 needs a *new* continuous foreground service. |
| **Fees / Accounting / Communication** | Untouched. F13 adds no demand, journal or notice writer in M1. |
| **Tenant isolation** | Every new doc carries `schoolId`; ids are single-`{schoolId}_` prefixed via `docId()`; parent read is a denormalised allowlist `in` check with **no cross-document `get()`**. |
| **Firestore-only** | No RTDB anywhere in the new surface. |

---

## 6. Mapping-provider amendment A-1

All seven verification items you asked for are answered in
`TRANSPORT_F13_PHASE1_ARCHITECTURE_VERIFICATION.md` §A-1. Summary: **all confirmed**, no schema
change, no F1–F12.1 impact, no regression. D-6 is void.

**One new finding — D-7, raised not resolved.** Leaflet and MapLibre are *rendering libraries*;
neither serves tiles. The public OSM tile service's usage policy excludes heavy/commercial use, so
it is not a viable production source for a multi-tenant ERP. This does **not** block M1 or the
schema — it blocks **M4**. Options (commercial OSM provider / self-host / dev-only) are in §5 D-7;
my recommendation is a commercial OSM tile provider for production and public tiles for development.

---

## 7. What M1 deliberately does NOT include

* **No writers.** `Transport_gps_service` never writes. Probe §H asserts both collections are still empty.
* **No `stopGeofenceEvents`.** Geofence automation is deferred by D-5, so the collection, its rules and its indexes are not created.
* **No frozen-code changes.** The approved D-2 edits are M2.
* **No app code.** Teacher service (M3) and Parent map (M4) are untouched.
* **Nothing deployed.** Rules and indexes are written but **not deployed** — they take effect only on `firebase deploy`, which needs your authorization.

---

## 8. Proposed M2 (not started)

Server lifecycle — the approved D-2 exception:

1. `startTrip` → `live_tracking_enabled = true`, stamp `gps_start_*`, create the hot doc with `driver_staff_id` + `authorized_viewer_uids[]` resolved from the frozen roster.
2. `completeTrip` / `cancelTrip` → flip the flag false, stamp `gps_end_*`, remove the hot doc.
3. Stale-session sweeper for trips abandoned by a crashed driver app.
4. All three riding the **existing** `casUpdateEntity` writes so `CH-TRN-CON6-1` keeps passing.

---

**Stopped for approval.** M2 will not begin until you authorize it.
