# Transport Module — Complete Investigation + UAT Plan (Production Certification)

**Date:** 2026-08-04 · **Systems:** Admin Website (PHP/CI3), Parent Android, Teacher Android · **Backend:** Firestore-only (multi-tenant). · **Release constraints:** no commit/branch/push/deploy — this is investigation + UAT planning only. **Phase 2 ends with STOP for manual testing.**

Grounded in the actual implementation (files/endpoints enumerated below), not documentation.

---

# PHASE 1 — MODULE INVESTIGATION

## 1. File inventory & dependency graph

### Admin Website (PHP CodeIgniter-3)
**Controllers (HTTP + cron):**
- `Transport.php` — vehicles, routes, stops, assignments, suspensions, bulk-assign, capacity, student search (F1–F3)
- `Transport_ops.php` — maintenance/fuel logs, expiry dashboard, expenses, accounting posting/reconcile (F5)
- `Transport_trip.php` — trip lifecycle + checkpoints + boarding/drop/no-show (F7)
- `Transport_sos.php` — SOS raise/respond/resolve (F6)
- `Transport_event.php` — events, escorts, consent, dispatch (F8)
- `Transport_driver.php` — driver meta, replacement, incidents, reviews, temp assignments, activity (F4)
- `Transport_rollover.php` — session rollover (start/continue/status/reset)
- `Transport_vendors.php` — vendors (F1)
- `Transport_analytics.php` — KPI/live-ops/health/trend/top-drivers/per-route/rebuild (F11)
- Crons/jobs: `Transport_activity_cron`, `Transport_expiry_cron`, `Transport_expense_reconciler`, `Transport_analytics_rollup_job`, `Transport_fleet_rollup_job`
- `Transport_verifier.php` — 94 permanent source/runtime gates (regression backstop)

**Libraries/services:** `Transport_service.php` (core business logic), `Transport_notifier.php` (notifications), `Transport_analytics_service.php` (analytics), `PayrollAccountingService.php` (expense→ledger seam), `Fee_lifecycle.php` (transport fee demands), `Numbering_service.php` (id minting), `Firestore_service.php`/`Firestore_rest_client.php` (data layer).

**Views:** `views/transport/index.php` + `partials/` (vehicle/route-suspension/trip/sos/event/expense/incident/rollover/bulk-assign/driver modals).

**Routes:** `config/routes.php` `transport/*` tab slugs → `Transport/index`.

### Parent Android
- Models: `TransportInfo`, `TransportAttendanceDoc`, `TransportEventDoc`, `TripLogDoc`, `VehicleDoc`, `NotificationDoc`
- Repos: `TransportFirestoreRepository` (bus info, trips, attendance, events, route-changes, SOS read), `TransportFeesRepository`, `CommunicationFirestoreRepository` (notifications inbox + notices)
- Screens: `TransportHomeScreen`, `MyBusScreen`, `TransportEventsScreen`, `TransportFeesScreen`, `TransportHistoryScreen`, `TransportPreferencesScreen`; `BadgeBus`, `NotificationPreferencesRepository`

### Teacher Android
- Models: `TransportAttendanceDoc`, `VehicleDoc`, `TripLogDoc`
- Repos: `TransportFirestoreRepository` (driver info, assigned vehicle, active trips, start/complete trip, checkpoints, board/drop/no-show, fuel, SOS trigger)
- Screens: `DriverHomeScreen`, `DriverTripDetailScreen`, `FuelLogScreen`, `SosTriggerScreen`

### Dependency graph
```
Admin Web (PHP) ──writes──▶ Firestore ◀──reads/writes── Teacher App (driver ops, boarding, SOS, fuel)
     │                         ▲   │
     │ Transport_service       │   └──reads──▶ Parent App (bus, trips, attendance, events, fees, prefs)
     │ Transport_notifier ─────┤
     │ Transport_analytics     │
     ▼                         │
Accounting (PayrollAccountingService → Operations_accounting engine → chartOfAccounts/accounting)
Fees (Fee_lifecycle → feeDemands / feeReceiptAllocations)  ← shared with Fees module
Communication (notices / noticeRecipients)                 ← SHARED platform (staff notices)
notifications / notificationPreferences                     ← SHARED platform (parent inbox)
```

## 2. Firestore collections (database investigation)
Transport-owned: `vehicles, transportVendors, vehicleDocuments, vehicleLifecycleLog, vehicleReplacementHistory, vehicleRouteTransferHistory, vehicleExpiryDashboardCache, routes, transportStops, routeSuspensions, studentRoutes, routeAssignments, routeAssignmentHistory, tripLogs, transportAttendance, transportEvents, eventTripAssignments, sosAlerts, fuelLogs, maintenanceLogs, transportExpenses, driverIncidents, driverPerformanceReviews, temporaryDriverAssignments, driverActivitySummary, fleetMonthlyExpenseSummary, routeFleetSummary, transportFleetRollup, transportNoticeIdempotency, transportAuditLog, busCapacityCache`.
Shared (consumed): `students, staff, schoolControl, feeDemands, feeReceiptAllocations, chartOfAccounts, accounting, notices, noticeRecipients, notifications, notificationPreferences, subscriptions`.
Doc-id convention: `{schoolId}_{entityId}` (tenant isolation via `Firestore_service::docId`); every query via `schoolWhere` prepends `schoolId==`.

## 3. Functional discovery (by feature area)

| Area | Actions |
|---|---|
| F1 Fleet | vehicle CRUD, lifecycle transition, document upload/delete, expiring-docs, replacement, route-transfer; vendor CRUD/terminate/link |
| F2 Routes | route CRUD/unarchive, stop CRUD, suspend/resume, suspension config, preview-notify |
| F3 Assignments | assign/update/delete, suspend/resume, change stop/route/subscription, bulk-assign, history, capacity rebuild, student search |
| F4 Drivers | meta save, permanent replace, incident record/resolve, performance review, temp-cover assign/resolve, effective-driver, activity |
| F5 Expenses | maintenance/fuel logs, expiry dashboard, expense CRUD/void, from-maintenance/fuel, accounting post/retry/reconcile, categories/summary |
| F6 SOS | raise/respond/resolve (Teacher-raised + admin) |
| F7 Trips | create/start/checkpoint(reached/left/skipped)/board/drop/no-show/complete/cancel, roster, events |
| F8 Events | create/update, assign/unassign student, consent, add/remove escort, schedule, dispatch-trip, complete/cancel, cost summary |
| F9 Teacher | driver info, assigned vehicle, active trips, start/complete trip, checkpoints, board/drop/no-show, fuel log, SOS trigger |
| F10 Parent | bus info, trips-today, attendance, events + consent, route-changes, transport fees, prefs (mute categories), history |
| F11 Analytics | KPI tiles, live-ops snapshot, health score, expense trend, top drivers, per-route, nightly rollups |
| Rollover | session rollover (start/continue/status/reset) + fee demand carry-over |
| Notifications | 19 ops categories → `notices` (admin bell + staff app); 8 parent categories → `notifications` inbox |

## 4. Data-flow (representative: driver boards a student)
`Teacher app markStudentBoarded(tripId,studentId)` → writes `transportAttendance/{schoolId}_{tripId}_{studentId}_boarded_pickup` (bare tripId) → Admin `completeTrip` tallies boarding via `schoolWhere(transportAttendance, tripId==)` → live-ops snapshot buckets onboard by bare tripId → admin dashboard widget. (No parent notice per-board; `no_show` fires `trip.student_no_show` → parent inbox keyed by student `userId`.)

## 5. Notification contracts (two, distinct — certified)
1. **Parent** (`notifications`, per-user) — recipient = student doc `userId`; ordered `sentAt`; muted via `notificationPreferences/{schoolId}_{userId}`.
2. **Operations/Staff** (`notices`+`noticeRecipients`, role-based `targetRoles`) — admin bell orders by `timestamp` (transport now emits `timestamp`+`timestampMs`+`sentAt`+`createdAt`).

## 6. Security / tenant-isolation posture
- Every transport read/write school-scoped (`docId` prefix / `schoolWhere`).
- Admin controllers gate on `require_permission('Operations')` + role checks (`$this->admin_role`).
- Rules: SEC-1 (attendance create bound to trip driver), SEC-2 (tripLogs create bound to driver); catch-all deny for unlisted collections (`transportFleetRollup`, `chartOfAccounts`, `noticeRecipients` not client-readable).

## 7. Known findings from the stabilization program (scope-classified)

### ✅ Transport-local — FIXED & gated
FIN-1/2/3/4/5, LOG-1/2/3/4/5, CON-1..7, SEC-1/2/3/4/5, XMOD-1, PERF-1..11, RC-1/2/3, FEE-1, NF-1, the parent recipient contract, and the ops-notice `timestamp` fix — all with verifier gates + runtime probes (94 gates PASS).

### ⏸ Transport-local — operator-DEFERRED (documented; not started)
- **A2** FIN-4 blocks cross-session void of a posted expense (post-rollover). — *Low-Med*
- **A3** FIN-5 doesn't cover idempotency `in_progress`/`error` json_error halt paths (+TOCTOU). — *Low-Med*
- **A4** `saveExpense` can resurrect a Voided/reversed expense to Active (no status guard). — *Low*
- **A5** `_clearVehicleCurrentRoute` non-CAS TOCTOU. — *Low (best-effort field)*
- **A6/B2** PERF-7/8/11 fallback: `where()` swallows a missing-index error so bounded scans/IN-fallbacks silently unbound/zero-fill (index-present today). — *Low/latent*
- **CON-8** `_resolveParentsOfRoute` 500-route cap. — *Low*

### 🔶 SHARED PLATFORM findings — DO NOT fix under Transport scope (separate ERP-wide hardening; approval required)
- **C1 (High)** `notices` read rule = `isSameSchool()` (no role gate) → any same-school **parent/student can read staff-only ops notices** (SOS live lat/lng, expense INR amounts, driver HR). Affects EVERY module writing role-targeted notices (Transport, HR, Fees, Communication). *Transport impact: SOS/expense/driver ops notices exposed to parents.*
- **C2 (Med)** `notifications` write rule = `isStaff()` (no payload binding) → cross-tenant push/inbox injection. Affects all modules writing `notifications`.
- **C3 (Low-Med)** `isSameSchoolWrite()` `schoolCode` arm allows a `schoolId=B, schoolCode=A` spoof → SEC-1/SEC-2 admin arm cross-tenant-forgeable. Shared helper used platform-wide.
- **Platform-notice-visibility** `Communication_helper::write_event_notice` shares the same `timestamp` gap Transport just fixed → Communication *event* notices are also absent from the admin bell.

### 📱 APP-RELEASE (Parent/Teacher repos; no admin change)
- **XMOD-2/3** `VehicleDoc.registrationNo`/driver/expiry fields need `@PropertyName` snake-case mapping (blank bus number / driver / expiry in-app).
- **SEC-7 (deferred)** `transportEvents`/`eventTripAssignments` parent-read tightening — needs coordinated Admin+Parent+Rules doc-split.
- **Cosmetic** `_fanoutNotifications`/`_fanoutOps` docId double-prefix (`{schoolId}_{schoolId}_…`) — no delivery impact (readers query by field).

---

# PHASE 2 — UAT TESTING MATRIX

Roles: **Admin, Transport Manager (TM), Principal, Accountant, Reception, Teacher/Driver, Parent, Student**. Fill in **Actual Result** + **Status** during manual testing. Status legend: ✅ Passed / ❌ Failed / ⚠ Partial / ⚠ Wrong-Behaviour / ⚠ Missing / ⚠ UI / ⚠ UX / ⚠ Validation / ⚠ Permission / ⚠ Navigation / ⚠ API / ⚠ DB / ⚠ Perf / ⚠ Crash / ⚠ ANR / ⚠ Data-Mismatch / ⚠ Regression.

## A. Fleet / Vehicles / Vendors (F1) — Website
| Test ID | Platform | Feature | Role | Preconditions | Action | Expected Result | Actual | Status | Remarks |
|---|---|---|---|---|---|---|---|---|---|
| TR-F1-01 | Web | Add vehicle | Admin/TM | Operations perm | Save a new vehicle (number, capacity, type) | Vehicle created, `number` stored, lifecycle_status=Active, appears in list | | | |
| TR-F1-02 | Web | Add vehicle validation | Admin | — | Save with blank number / capacity 0 | Rejected with validation message | | | |
| TR-F1-03 | Web | Edit vehicle | TM | Vehicle exists | Edit capacity/driver_name/expiry dates | Updated; capacity change reflected in capacity cache | | | |
| TR-F1-04 | Web | Delete/retire vehicle | Admin | Vehicle exists, no active trip | Delete/transition to Retired | Lifecycle transitions; audit logged; not in active list | | | |
| TR-F1-05 | Web | Vehicle document upload | TM | Vehicle exists | Upload insurance/fitness/permit doc | Stored in `vehicleDocuments`; retrievable | | | |
| TR-F1-06 | Web | Expiring documents view | Admin | Docs with near expiry | Open expiring-docs | Vehicles with insurance/fitness/permit/pollution/road_tax expiry listed (non-zero) | | | |
| TR-F1-07 | Web | Vehicle replacement | TM | Two vehicles | Record replacement old→new | History written; route reassigned | | | |
| TR-F1-08 | Web | Route transfer | TM | Vehicle on route | Transfer vehicle between routes | Transfer history; route.vehicle_id updated | | | |
| TR-F1-09 | Web | Vendor CRUD | Admin | — | Add/terminate vendor, link to vehicle | Vendor saved; link persisted; terminate blocks new links | | | |
| TR-F1-10 | Web | Permission — no Operations | Reception (no Ops) | Ops perm revoked | Open /transport | 403 / access denied (require_permission) | | | |

## B. Routes / Stops / Suspensions (F2) — Website
| Test ID | Platform | Feature | Role | Preconditions | Action | Expected Result | Actual | Status | Remarks |
|---|---|---|---|---|---|---|---|---|---|
| TR-F2-01 | Web | Create route | TM | — | Save route + stops | Route + `transportStops` created | | | |
| TR-F2-02 | Web | Suspend route | TM | Active route | Suspend with date range + reason | `active_suspension_ref` set; status stays Active; suspension doc created | | | |
| TR-F2-03 | Web | Resume route | TM | Suspended route | Resume | `active_suspension_ref` cleared; resume notice | | | |
| TR-F2-04 | Web | **Dispatch on suspended route (LOG-2)** | TM | Suspended route | Create a trip on it | **Rejected** — "active suspension; resume before dispatching" | | | Regression guard |
| TR-F2-05 | Web | Assign student to suspended route | TM | Suspended route | Assign student | Rejected (assignment gate) | | | |
| TR-F2-06 | Web | Suspension parent notice | Parent | Suspension with notify_parents | Suspend route | Parents on route get `route.suspension_notice_parents` in inbox (userId) | | | Recipient contract |

## C. Student Assignments (F3) — Website
| Test ID | Platform | Feature | Role | Preconditions | Action | Expected Result | Actual | Status | Remarks |
|---|---|---|---|---|---|---|---|---|---|
| TR-F3-01 | Web | Assign student | TM | Student + route + stop | Save assignment | `studentRoutes` created; capacity seat reserved; fee demand generated | | | |
| TR-F3-02 | Web | Capacity full | TM | Route at capacity | Assign one more | Rejected / seat unavailable | | | Concurrency (CON-3) |
| TR-F3-03 | Web | Change route | TM | Assigned student | Change route | Old seat released, new reserved; history event | | | |
| TR-F3-04 | Web | Bulk assign | TM | Multiple students | Bulk assign to a route | All assigned; per-student fee; capacity updated; one history per student | | | |
| TR-F3-05 | Web | Assignment history | TM | Student with changes | View history | Ordered history incl. `session_rolled` (no duplicates) | | | CON-5 |
| TR-F3-06 | Web | Student search | TM | ≥1 student | Search "ali" in assignment UI | Returns matching students (name/id), no 500 | | | **NF-1** |
| TR-F3-07 | Web | Student search <2 chars | TM | — | Search "a" | Empty result, no error | | | |

## D. Drivers (F4) — Website
| Test ID | Platform | Feature | Role | Preconditions | Action | Expected Result | Actual | Status | Remarks |
|---|---|---|---|---|---|---|---|---|---|
| TR-F4-01 | Web | Driver meta | TM | Staff driver | Save licence/medical/badge | Persisted; feeds readiness | | | |
| TR-F4-02 | Web | Permanent replacement | TM | Vehicle+driver | Replace driver | New driver effective; ops notice to staff | | | |
| TR-F4-03 | Web | Temp cover | TM | Driver + date | Assign temp driver | `temporaryDriverAssignments`; effective-driver honors overlay | | | |
| TR-F4-04 | Web | Incident record/resolve | TM | Driver | Record critical incident → resolve | `driver.critical_incident` ops notice (URGENT); resolve updates | | | |
| TR-F4-05 | Web | Dispatch unfit driver (LOG-1) | TM | Driver licence expired / On_Leave | Create+start trip | Rejected — driver readiness fails | | | Safety |

## E. Trips + Attendance (F7) — Website + Teacher App
| Test ID | Platform | Feature | Role | Preconditions | Action | Expected Result | Actual | Status | Remarks |
|---|---|---|---|---|---|---|---|---|---|
| TR-F7-01 | Web | Create trip | TM | Vehicle+route+driver | Create Regular trip | Trip Ready; checkpoints snapshot from stops | | | |
| TR-F7-02 | Teacher | Start my trip | Driver | Assigned trip Ready | Start | Trip InProgress; **vehicle.currentRouteId set** | | | XMOD-1 |
| TR-F7-03 | Teacher | Board student | Driver | InProgress trip, rostered student | Mark boarded | `transportAttendance` boarded_pickup (bare tripId) | | | |
| TR-F7-04 | Teacher | **No-show non-rostered (LOG-5)** | Driver | InProgress trip | Mark no-show for a NOT-rostered student | **Rejected** — not on frozen roster | | | Safety |
| TR-F7-05 | Teacher | No-show rostered | Driver | Rostered student | Mark no-show | Recorded; `trip.student_no_show` → that student's parent inbox only | | | Recipient contract |
| TR-F7-06 | Teacher | Checkpoint reached/skipped | Driver | InProgress | Mark checkpoints | State updates; progress % | | | CON-2 |
| TR-F7-07 | Teacher | Complete trip | Driver | All checkpoints done | Complete | Completed; summary (boarded/dropped/no-show); **currentRouteId cleared** | | | |
| TR-F7-08 | Web | Double-complete (CON-6) | TM | InProgress trip | Two concurrent completes | Only one wins; single audit/notify | | | Concurrency |
| TR-F7-09 | Web | **Live-ops onboard count (B1)** | Admin | InProgress trips with boardings | Open live-ops dashboard | **students_currently_onboard = correct** (boarded − dropped), NOT 0 | | | B1 fix |
| TR-F7-10 | Web | Trip started/completed ops notice | TM | — | Start/complete a trip | `trip.started`/`trip.completed` appear in **admin notice bell** | | | B3 fix |

## F. SOS (F6) — Teacher App + Website
| Test ID | Platform | Feature | Role | Preconditions | Action | Expected Result | Actual | Status | Remarks |
|---|---|---|---|---|---|---|---|---|---|
| TR-F6-01 | Teacher | Raise SOS | Driver | Assigned vehicle | Trigger SOS (alert type, severity) | `sosAlerts` written (active + status); route context attached | | | |
| TR-F6-02 | Web | SOS in admin bell | Admin/TM/Principal | SOS raised | Open notice bell | `sos.raised` (URGENT) visible with vehicle/route/lat-lng | | | B3 + **verify C1 exposure separately** |
| TR-F6-03 | Web | Respond / Resolve SOS | TM | Active SOS | Respond then resolve | Status transitions; `sos.responded`/`sos.resolved` ops notices | | | |
| TR-F6-04 | Teacher | Driver SOS route context (XMOD-1) | Driver | On an InProgress trip | Raise SOS | Route id attached (currentRouteId populated) | | | |

## G. Expenses + Accounting (F5) — Website
| Test ID | Platform | Feature | Role | Preconditions | Action | Expected Result | Actual | Status | Remarks |
|---|---|---|---|---|---|---|---|---|---|
| TR-F5-01 | Web | Create expense | Accountant/TM | — | Save expense (amount, type, date) | Created; auto-post attempted (pending/posted) | | | |
| TR-F5-02 | Web | **Edit posted expense amount (FIN-3)** | Accountant | Posted expense | Change amount | **Rejected** — "void and recreate" | | | FIN-3 |
| TR-F5-03 | Web | **Edit posted expense date (FIN-3/A1)** | Accountant | Posted expense | Change expense_date | **Rejected** — date is immutable on posted | | | A1 fix |
| TR-F5-04 | Web | Non-financial edit of posted | Accountant | Posted expense | Edit notes/attachments | Allowed; journal ref preserved | | | |
| TR-F5-05 | Web | Void posted expense (FIN-4) | Accountant | Posted expense (accounting live) | Void with reason | Journal reversed; status Voided | | | FIN-4 |
| TR-F5-06 | Web | Void unreversible | Accountant | Posted, accounting down | Void | Aborted (record stays Active) — no divergence | | | FIN-4 |
| TR-F5-07 | Web | Missing CoA account (FIN-5) | Accountant | ledger account absent | Auto-post expense | No 500; expense stays pending + reconciler retries | | | FIN-5 |
| TR-F5-08 | Web | Expense summary / trend | TM | Expenses posted | Open trend | 24-month trend loads (single query) | | | PERF-11 |

## H. Events (F8) — Website + Parent App
| Test ID | Platform | Feature | Role | Preconditions | Action | Expected Result | Actual | Status | Remarks |
|---|---|---|---|---|---|---|---|---|---|
| TR-F8-01 | Web | Create event + roster | TM | — | Create event, assign students | `transportEvents` + `eventTripAssignments` (consent Pending) | | | |
| TR-F8-02 | Web | Add escort (HR-active) | TM | Active staff | Add escort | Accepted | | | LOG-4 |
| TR-F8-03 | Web | Add terminated escort (LOG-4) | TM | Inactive staff | Add/update escort | **Rejected** — not HR-Active | | | LOG-4 |
| TR-F8-04 | Web | Schedule event | TM | Vehicles+driver+roster | Schedule | Scheduled; `event.scheduled` → parents' inboxes | | | |
| TR-F8-05 | Web | Dispatch with terminated escort (LOG-4) | TM | Escort terminated post-schedule | Dispatch trip | **Rejected** at dispatch | | | LOG-4 |
| TR-F8-06 | Parent | Event visibility + consent | Parent | Child assigned to event | Open Transport Events | Event shows; consent update reflects | | | |
| TR-F8-07 | Web | Two events same window (LOG-3) | TM | — | Schedule 2 events in one TTL bucket | Both parents notified (no idempotency collision) | | | LOG-3 |

## I. Parent App (F10)
| Test ID | Platform | Feature | Role | Preconditions | Action | Expected Result | Actual | Status | Remarks |
|---|---|---|---|---|---|---|---|---|---|
| TR-P-01 | Parent | My Bus | Parent | Child assigned | Open MyBus | Route/vehicle/stop shown | | | XMOD-2 (bus number) — verify |
| TR-P-02 | Parent | Trips today | Parent | Trip today | Open trips | Today's trip + status | | | |
| TR-P-03 | Parent | Attendance | Parent | Boarding events | Open attendance | Board/drop/no-show history for own child | | | |
| TR-P-04 | Parent | **Transport notification received** | Parent | trip.delay/no_show/event fired | Check inbox | Notification appears (keyed by student userId) | | | **Recipient contract** |
| TR-P-05 | Parent | Notification preferences | Parent | — | Mute a category | Muted category no longer delivered (safety exempt still delivers) | | | |
| TR-P-06 | Parent | Transport fees | Parent | Pending transport demand | Open fees | Pending demand + receipts shown | | | |
| TR-P-07 | Parent | Cross-child isolation | Parent (2 kids) | Both children | Open transport | Only own children's data | | | Tenant/child scope |
| TR-P-08 | Parent | **Ops-notice NOT visible to parent (C1 check)** | Parent | SOS/expense ops notice exists | Query notices directly | *Currently readable — PLATFORM finding C1* (record actual) | | | ⚠ Platform C1 |

## J. Teacher App (F9)
| Test ID | Platform | Feature | Role | Preconditions | Action | Expected Result | Actual | Status | Remarks |
|---|---|---|---|---|---|---|---|---|---|
| TR-T-01 | Teacher | Driver home | Driver | Assigned vehicle | Open DriverHome | Vehicle + route badge (currentRouteId) | | | XMOD-1/2 |
| TR-T-02 | Teacher | Fuel log | Driver | Assigned vehicle | Submit fuel | `fuelLogs` written (own vehicle only) | | | Rule: driver-of-vehicle |
| TR-T-03 | Teacher | Forge trip for another driver (SEC-2) | Driver | — | Create tripLog with other driver_staff_id | **Rejected** by rules | | | SEC-2 |
| TR-T-04 | Teacher | Forge attendance (SEC-1) | Driver | Not the trip's driver | Write transportAttendance for a trip | **Rejected** by rules | | | SEC-1 |

## K. Analytics (F11) — Website
| Test ID | Platform | Feature | Role | Preconditions | Action | Expected Result | Actual | Status | Remarks |
|---|---|---|---|---|---|---|---|---|---|
| TR-F11-01 | Web | KPI tiles / bundle | TM | Data present | Open analytics | Tiles render (role-scoped) | | | SEC-3 |
| TR-F11-02 | Web | RBAC — Operations revoked (SEC-3) | Role w/o Operations | Module revoked | Open analytics | Empty/denied (has_permission gate) | | | SEC-3 |
| TR-F11-03 | Web | Endpoint role key (SEC-4) | TM | — | Hit /transport_analytics/* , /transport_sos, /transport_trip | Works (admin_role, not phantom 'role') | | | SEC-4 |
| TR-F11-04 | Web | Health / trend / top-drivers / per-route | TM | Data | Open each widget | Loads from caches; no N+1 stall | | | PERF |
| TR-F11-05 | Cron | Nightly fleet rollup | Ops | Cron | Run `Transport_fleet_rollup_job` | `transportFleetRollup/{date}`; one bad tenant doesn't abort | | | PERF-4/6 |

## L. Rollover + Fees
| Test ID | Platform | Feature | Role | Preconditions | Action | Expected Result | Actual | Status | Remarks |
|---|---|---|---|---|---|---|---|---|---|
| TR-RO-01 | Web | Session rollover | Admin | New session | Start rollover | Assignments carried; `session_rolled` history (dedup on re-pump) | | | CON-4/5 |
| TR-RO-02 | Web | Rollover fee carry-over | Admin | Rollover | After rollover | New-session transport demands created; old-session cancelled (case-safe) | | | FIN-1/2 |
| TR-RO-03 | Web | Rollover re-pump (idempotency) | Admin | Partial rollover | Continue/re-run | No duplicate history / no double fee | | | CON-4/5 |

## M. Cross-cutting: Security / Tenant / Perf / Regression
| Test ID | Platform | Feature | Role | Preconditions | Action | Expected Result | Actual | Status | Remarks |
|---|---|---|---|---|---|---|---|---|---|
| TR-X-01 | All | Tenant isolation | Any | 2 schools | Access other school's trip/vehicle/attendance | Denied / not returned | | | |
| TR-X-02 | Web | Two schools same TRP id | Admin | Same bare tripId in A & B | Live-ops onboard per school | Isolated (A≠B, no bleed) | | | B1 MT proof |
| TR-X-03 | Web | Notice bell completeness | Admin | Ops notices fired | Open bell | ALL 19 ops categories visible (timestamp) | | | B3 |
| TR-X-04 | All | Firestore-only | — | — | Inspect writes | No RTDB usage anywhere | | | |
| TR-X-05 | Web | Verifier gates | Ops | — | Run `transport_verifier verify` | 94 source/logic gates PASS (5 pre-existing data FAILs) | | | Regression backstop |
| TR-X-06 | Web | Large dataset — list scans | TM | Many expenses/assignments | Open lists | Bounded (LIST_SCAN_LIMIT); acceptable latency | | | PERF-7/8 (note A6/B2 fallback caveat) |
| TR-X-07 | App | Offline / network failure | Driver/Parent | No network | Board / open screens | Graceful failure, retry on reconnect | | | |
| TR-X-08 | App | Concurrency — 2 devices | Driver | Same trip on 2 devices | Board same student twice | Idempotent (deterministic attendance docId) | | | |

---

## ⛔ STOP — awaiting manual UAT execution

Per the workflow, I **stop here**. Do not assume anything passed. Execute the matrix, fill in **Actual Result** + **Status**, and return it. I will then perform **Phase 4 root-cause analysis** on any failures (classifying each as Transport-local / shared-platform / cross-module per the scope boundary), and only implement fixes after your validation and approval.

**Scope-boundary reminder:** any failure whose root cause is a **shared-platform** component (notices/notifications rules, `isSameSchoolWrite`, Communication framework, shared helpers) will be classified as a **Platform Finding**, its cross-module blast radius explained, and held for a separate ERP-wide hardening project with your approval — **not** silently folded into Transport.
