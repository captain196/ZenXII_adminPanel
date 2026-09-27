# F14 — Architecture Audit (Phase 0)

**Status: PHASE 0 COMPLETE. NO PRODUCTION CODE WRITTEN.**
**STOPPED — 4 material ambiguities / blockers require operator decisions before Phase 1.**

Audited: existing transport attendance, `qr_token_helper`, `Campus_access::scan_qr_lookup`,
admission flow, student transport assignment, mid-session updates, Teacher app transport, Parent app
transport, notification infrastructure, F13.1 GPS, F13.2 fleet map, Firestore rules, RBAC, existing
probes/gates.

---

## 1. Headline: far more exists than the brief assumes

F14 is **not** a new attendance system. The canonical spine is already built.

| Capability | Location | State |
|---|---|---|
| Transport assignment + full lifecycle | `Transport_service::assignStudent/unassignStudent/suspendAssignment/resumeAssignment` | **exists** |
| Attendance store | `transportAttendance` | **exists, rich** |
| **Idempotent** event doc id | `{schoolId}_{tripId}_{studentId}_{eventType}` | **exists** |
| GPS-at-scan, geofence variance, planned-vs-actual stop | `transportAttendance` fields | **exists** |
| Frozen per-trip roster | `tripLogs.roster_student_ids` (immutable) | **exists** |
| On-board counter | `tripLogs.boarded_count` | **exists** |
| Signed student QR tokens (HMAC-SHA256) | `application/helpers/qr_token_helper.php` | **exists** |
| Local QR render, no external API | `chillerlan/php-qrcode` → `qr_svg_data_uri()` | **exists** |
| Scan-and-resolve precedent | `Campus_access::scan_qr_lookup()` | **exists** |
| Transport notification engine, 30-day idempotency | `Transport_notifier` (27 categories) | **exists** |
| Parent-of-students fanout scope | `SCOPE_PARENTS_OF_STUDENTS` (F7) | **exists** |
| Driver-bound attendance rule | `firestore.rules` SEC-1 | **exists** |
| Parent live bus map | Parent app `LiveBusMap.kt` (F13.1 M4) | exists, untested |
| Parent transport history screen | Parent app `TransportHistoryScreen.kt` | exists — **but see F-4** |

### `transportAttendance` already carries

`id, route_id, session, trip_date, trip_direction, planned_stop_id, actual_stop_id, event_type,
event_at, marked_by_staff_id, marked_by_role, checkpoint_sequence, notes, event_location_ref,
event_lat, event_lng, event_at_gps, gps_session_id, correlation_id, geofence_variance_m,
geofence_verified, created_at`

Requirements #17 (duplicate protection), #29 (event vs receipt time), and much of #35 (audit, source,
driver attribution, GPS-at-scan) are therefore **structurally already satisfied**.

---

## 2. FINDINGS REQUIRING A DECISION — Phase 1 is blocked on these

### F-1 (BLOCKER) — Transport eligibility: two candidate homes, one is parked

The brief requires a server-authoritative "ACTIVE TRANSPORT ENTITLEMENT". **Two systems could own it.**

**Candidate A — `studentRoutes` (live, 22 call sites).** `assignStudent()` already writes exactly the
entitlement the brief describes:

```
session, status ('Active'|'Resolved'|'Cancelled'), effective_from, effective_to,
suspended_from, suspended_until, route_id, stop_id, type (pickup|drop|both),
service_days, monthly_fee, subscription_type, assigned_date, assigned_by,
source, batch_id, fee_demand_ref, concession_id, hostel_transport_flag,
season_start, season_end
```

Lifecycle: `assignStudent` (incl. mid-session via `effective_from`), `unassignStudent` (pro-rates
fees), `suspendAssignment`, `resumeAssignment`. It already gates trip rosters via
`_computeTripRoster()`.

**Candidate B — `studentEntitlements` + `Entitlement_service`.** A generic services/concessions
framework with `kind ∈ {service, concession, credit_note}` and
`status ∈ {proposed, pending_approval, active, rejected, discontinued}`, plus a shared approval
surface (`Entitlements.php`) mounted by all three admission pathways.

**Why this is a blocker:** Candidate B is **PARKED mid-certification and uncommitted**, with its
flags deliberately FALSE:

```
SERVICE_ENROLLMENT_UI_ENABLED      = false
USE_ENTITLEMENT_SERVICE_PRICING    = false
PHASE_3_CONVERGED                  = false
```

Standing instruction on that work is: do not commit, do not flip flags, do not swap readers. Also
verified: **no Transport code reads `studentEntitlements` today**, and Transport is not among its
named services.

**Recommendation:** make **`studentRoutes` the authority for F14 eligibility.** It is live, already
route/stop/session-aware, already drives rosters, and needs no flag flip. Treat convergence onto
`studentEntitlements` as future work owned by the parked Phase 3 programme.

**DECISION NEEDED — confirm A, or explicitly authorise touching the parked programme.**

---

### F-2 (BLOCKER) — The current write path cannot enforce the entitlement gate

**The Teacher app writes attendance events DIRECTLY to Firestore** —
`TransportFirestoreRepository.writeBoardingEvent()`. There is no server hop. Firestore rules are the
entire authorization boundary, and `Transport_driver_api` exposes only `start_trip` and
`complete_trip`.

Security rules **cannot**:
- verify an HMAC signature (requirement #13)
- resolve entitlement across `studentRoutes` status + effective dates + session + service days (#14)
- authorise the alternate-bus exception (#18)

So requirement #14 — *"MUST be rejected server-side even if a malicious client calls the endpoint
directly"* — **cannot be met on the existing path.** F14 requires server-mediated scans:
`Transport_driver_api/scan` etc., ownership-authorized (no RBAC), with the server performing decode →
HMAC → tenant → student → entitlement → trip → roster/alternate → event.

**Consequence the brief does not account for:** the direct-write path gets **Firestore's offline
queue for free**. Moving server-side **loses that**, so requirements #27–#30 (offline queue, pending
sync, original timestamp, replay protection) become real client work — a Room queue plus flush and
conflict handling — not configuration.

**Second consequence:** the manual tap-to-board fallback must ALSO move server-side, or it becomes a
hole that bypasses the entitlement gate entirely.

**DECISION NEEDED — approve moving all attendance writes server-side, accepting that the offline
queue must be built. Recommended: yes. The alternative cannot satisfy #14.**

---

### F-3 (BLOCKER) — Admission captures a transport *declaration*, not an assignment

`Admission_public` stores only:

```php
'transport'     => post('decl_transport') === '1',
'transportArea' => post('decl_transport_area'),
```

It never creates a `studentRoutes` assignment. So requirement #1 ("Transport eligibility at
admission") has **no existing pathway** — the declaration is captured and then goes nowhere. Route
and stop assignment today is an admin action in the Transport module.

That may well be correct: at admission time the school often does not yet know the route or stop, and
auto-creating an assignment without a stop would produce a student who is "transport eligible" but
unroutable — and would immediately generate transport fees.

**DECISION NEEDED — one of:**
- **(a)** Admission declaration stays a *request*; admin converts it to an assignment. Add a
  "Transport requested" worklist so declarations cannot be silently lost. **(Recommended.)**
- **(b)** Admission auto-creates an assignment when route + stop are supplied at admission, else
  falls back to (a).

Blindly auto-creating on a declaration alone would corrupt fees; I will not implement that.

---

### F-4 (DEFECT, pre-existing) — Parents cannot read `transportAttendance`

```
match /transportAttendance/{docId} {
  allow read: if isSameSchool() && isStaff();
```

Staff only. Yet the **Parent app already ships `TransportHistoryScreen.kt` reading
`transportAttendance`.** That read must be failing with PERMISSION_DENIED today, and — exactly like
the driver bug fixed earlier this session — it will surface as a silently empty list rather than an
error.

Not caused by F14, but F14 #32 (parent timeline) depends on fixing it: add a parent arm using the
same three-arm ownership pattern `tripLogs` already uses (`student_ids` claim / `student_id` claim /
uid fallback). Confirming whether that screen currently fails is a Phase 1 verification task.

---

## 3. Gaps (no decision needed — straightforward work)

| # | Gap | Notes |
|---|---|---|
| G-1 | Only 2 of 4 events exist | `boarded_pickup`, `dropped_home`, `no_show`. Missing `alighted_at_school`, `boarded_at_school`. Read-time aliases preserve history (#11) — **no historical rewrite** |
| G-2 | No QR scanner in Teacher app | No ML Kit / ZXing / CameraX dependency at all |
| G-3 | No boarding/alighting notification categories | `Transport_notifier` has 27 categories, none for boarding. F14 adds 4 to the **existing** engine |
| G-4 | Alternate-bus fields absent | `planned_stop_id`/`actual_stop_id` exist; `assigned_vehicle_id/trip_id` vs `actual_vehicle_id/trip_id` + `boarding_mode` do not |
| G-5 | No per-student state machine | Transitions unvalidated today |
| G-6 | No completion reconciliation | Nothing detects a student boarded-but-never-alighted |
| G-7 | No driver student roster UI | Driver sees a boarding list inside trip detail, not the roster dashboard in the brief |
| G-8 | Legacy unsigned QR tokens accepted by the helper | Must fail closed on the F14 path specifically (#16) |
| G-9 | New composite index | `schoolId + tripId + event_type + event_at` |

---

## 4. Security assessment

The QR token is `base64({schoolId}|{studentId}|HMAC-SHA256)` — **static per student, no expiry, no
nonce**. It is an *identifier, not a credential*. Every control follows from that.

| Threat | Control | Status |
|---|---|---|
| Card photographed / replayed | Token authorises nothing; scanner must hold a driver session, own an InProgress trip, and student must be entitled + on roster | **needs F-2** |
| Forged token | HMAC verified server-side | helper exists |
| Legacy unsigned token | Reject on this path (G-8) | to build |
| Cross-tenant card | token schoolId == driver claim == trip schoolId | to build |
| Non-entitled student (#14) | server resolves `studentRoutes` | **needs F-1 + F-2** |
| Wrong-bus silent add | roster gate; only explicit alternate flow may override | to build |
| Replay / double scan | idempotent doc id | **exists** |
| Notification duplication | notifier 30-day idempotency + event doc id as dedupe key | **exists** |
| Forged driver create | rules SEC-1 binds create to `tripLogs.driver_staff_id == auth.uid` | **exists** |
| Immutability | rules `allow update, delete: if false` | **exists** |
| Secret leakage | `_qr_secret()` server-side only; APK never decodes | policy |
| Conductor scans on driver's phone | attribution is to the session holder — the **driver** | **accountability gap, unresolved** |

**Rejected by design:** decoding the token on the phone (secret in APK); QR carrying trip or
authorisation (card becomes a bearer token); token rotation (invalidates every printed card).

---

## 5. Architecture position

- **No parallel systems.** Extend `transportAttendance`, `studentRoutes`, `Transport_notifier`,
  `Transport_driver_api`. No second attendance store, QR system, notifier, roster or GPS system.
- **F13.1 GPS untouched.** `vehicleLivePositions` / `vehicleBreadcrumbs` / `Transport_gps_service`
  remain canonical. Attendance already has a `gps_session_id` field to correlate — no new GPS path.
- **F13.2 fleet map untouched**, except additive read-only counters.
- **Firestore-only.** No RTDB anywhere in the design.
- **Counting rule honoured:** reconciliation is *within* a trip leg
  (`boarded − alighted == 0`). Morning and afternoon are never compared as counts.
- **Assigned vs actual** preserved as a first-class distinction; permanent assignment is never
  overwritten by an alternate-bus event.

### One documentation discrepancy to flag

`CLAUDE.md` states `MARK_REGISTRY` in `functions/index.js` is "the only push dispatcher", but
Transport notifications actually route through the `Transport_notifier` library, and the registry
contains no transport marks. F14 will use `Transport_notifier` (the de-facto canonical transport
path). **Reporting, not resolving** — reconciling that doc is outside F14.

---

## 6. Proposed phasing (unchanged from brief, re-scoped to findings)

| Phase | Content | Depends on |
|---|---|---|
| **1** | Entitlement resolver, server-mediated scan endpoints, 4-event model + state machine, alternate-bus authorisation, assigned-vs-actual fields, rules, indexes, audit, 4 notifier categories | F-1, F-2, F-3 |
| **2** | Teacher app: roster dashboard, camera scanner, 4 event actions, alternate-bus flow, counts, unresolved list, offline queue + pending-sync | Phase 1 |
| **3** | Parent app: 4 notifications, timeline, alternate-bus indication, existing live map | F-4 |
| **4** | Admin: eligibility visibility, attendance + exception dashboard, reconciliation, audit | Phase 1 |
| **5** | Validation: 23 gates, rules tests, probes, verifier, both app builds, regression | all |

---

## 7. Open operational question (carried from the F14 design, still unanswered)

**Is scanning ~50 children *off* the bus at the school gate realistic?** Four scans × 50 students ×
2 legs = 200 scans/day/bus. If it is not operationally viable, arrival should be a single
"all alighted" action with per-student exceptions — a materially different Phase 2 UX.

This is the largest practical risk in the whole feature; everything else is tractable engineering.

---

## 8. Status

**STOPPED at end of Phase 0 as instructed.** No production code written. No commits, pushes, merges
or deploys. F13.1/F13.2 untouched and still unfrozen. Manual UAT for F13 remains **NOT PERFORMED**,
and F14 manual UAT will be created as **NOT PERFORMED**.

**Awaiting decisions on F-1, F-2, F-3 and §7 before Phase 1.**
