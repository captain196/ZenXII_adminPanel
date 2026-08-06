# Transport (TMS) — Advanced Feature Research & Design

**Generated:** 2026-08-06 · **Feature under study:** Transport
**Run mode:** research + design + prototype only. No code written, no files mutated, no commits, no pushes.

---

## Executive summary

1. Transport is **not a greenfield feature**. F1–F11 are fully implemented and locally committed (`53768b6..6f91923`, unpushed), and the Stabilization Sprint **closed 2026-08-04** — 94 verifier gates PASS, 199/199 Firestore rules unit tests, independently certified.
2. `Transport_service.php` is **7,943 LoC**, with 3 libraries, 10 functional controllers, 5 cron controllers, ~30 runtime probes, and a 4,385-LoC verifier. This is the most mature module in the ERP.
3. Against Edunext / Fedena / ZenoxERP, ZenXII is **at or above parity on operational depth** — fleet lifecycle, vendor contracts, driver readiness gating, event transport, expense→accounting posting, and role-scoped analytics all exceed what the reference set markets.
4. **One table-stakes capability is missing: live GPS tracking.** Every competitor leads with it. ZenXII has the entire schema, correlation key, reserved-field guard, and Parent-app Maps SDK in place — and writes `null` to every position field.
5. The second gap is **CBSE transport-safety compliance**. GPS + CCTV + speed governor + trained lady attendant are *mandatory* for 18,000+ CBSE schools, penalty up to disaffiliation. The vehicle schema tracks 5 document expiries but **zero safety-equipment fields**. No reference ERP markets this as a first-class surface.
6. Third gap: **fare policy**. Fedena supports stop-based or flat fare slabs. ZenXII has no fare table at all — `monthly_fee` is hand-typed per student assignment (`Transport_service.php:2610`).
7. **Recommendation:** ship **G2 (Compliance) first, G1 (GPS) second.** Compliance is pure software, no hardware dependency, uses the existing expiry engine, and is the genuine differentiator. GPS is higher-profile but carries device procurement, telemetry cost, and a write-rate problem that must be designed around Firestore's 1-write/sec/document limit.
8. **Do not** build route optimization or RFID boarding. Reasoning in §3.4.

---

## Phase 0 — Questions and working assumptions

**Questions that would materially change the design:**

1. **GPS hardware model** — do schools already own tracker devices (Edunext sells its own), or is the driver's phone in the Teacher app the intended position source? This decides the entire ingestion tier.
2. **Is the TMS release still frozen?** The module is certified but uncommitted and unpushed. Does new Transport work land on top of the frozen release, or after it ships?
3. **Board affiliation mix** — is the tenant base predominantly CBSE, or mixed CBSE/ICSE/State? Compliance rule sets differ.
4. **Telemetry budget** — live tracking at 10-second granularity across 20 buses × 6 hours/day is roughly 43k writes/day/school. Acceptable, or must it be throttled?
5. **SEC-7 dependency** — the deferred `transportEvents` parent-read tightening needs a coordinated Admin+Parent+Rules doc split. Does new parent-facing work wait on it?

**Working assumptions used throughout (none blocked this deliverable):**

> - **[A1]** Driver phone (Teacher app) is the primary position source; dedicated IoT trackers are an optional secondary ingest path. This is the cheaper, faster-to-market route and reuses F9's authenticated driver identity.
> - **[A2]** New Transport work lands *after* the current module release is pushed, as TMS Phase 5 / F12+.
> - **[A3]** CBSE is the primary compliance target; the design keeps the rule set data-driven so ICSE/State packs can be added without code change.
> - **[A4]** Position writes are throttled to 15-second intervals with adaptive backoff when stationary.
> - **[A5]** New parent-facing reads use their own collections, sidestepping the SEC-7 blocker entirely.

---

## Phase 1 — Competitive research

### 1.1 Reference matrix

| ERP | How they model it | Notable capabilities | Admin UX pattern | Mobile/parent exposure | What they get wrong |
|---|---|---|---|---|---|
| **Edunext** | Vehicle + route/stop registry with a *paired hardware GPS device* sold alongside the software | Real-time GPS tracking; boarding/de-boarding alerts; **school-premises entry/exit alerts**; driver + conductor details exposed to parents; **parent-initiated bus avail/withdrawal request**; real-time seat monitoring; fuel logs | Module tabs; graph-heavy dashboards | Strong — live vehicle location with route updates, full transport detail per ward | Hardware lock-in: tracking quality depends on buying their device. Software alone is inert |
| **Fedena** | Transport *plugin*; routes and stops created **academic-year-wise** | Stop-level **fare assignment** (stop-based *or* flat); certificate expiry reminders (licence, PUC, permit); maintenance + repair records; fuel expense; student **and staff** allotment by seat availability; **custom fields** on vehicle and route | Classic plugin CRUD; year-scoped | Modest — largely an admin module | Thin real-time story; parent surface is informational. Plugin model means uneven integration with fees/accounting |
| **ZenoxERP** | Schedule-first: buses scheduled by student **area**, students assigned to routes | Real-time bus location; arrival/running-status SMS to parents; vehicle + driver details; exportable reports; claims optimal student→route assignment | Scheduling-centric | SMS-first rather than app-first | SMS-era architecture; "optimal assignment" is unsubstantiated marketing `[inferred]` |
| **Category leaders** (Edulog, Busology, AVLView, Asti) `[inferred]` | Ridership platform with a telematics backbone | RFID/biometric boarding scanners; geofenced zone alerts; driver-behaviour scoring (over-speed, harsh braking); engine diagnostics; dashcams; SIS sync | Dispatcher console | Live map + ETA + late-bus push | Enterprise pricing and hardware fleets; overkill for a single Indian K-12 school |

### 1.2 Regulatory layer (India — CBSE)

Mandatory for all CBSE-affiliated schools; the head of school is personally liable and non-compliance can escalate to **disaffiliation**:

- GPS **and** CCTV in every vehicle, **in working condition at all times**
- Speed governor capped at **40 km/h**
- Trained **lady attendant** on board; designated **transport manager**
- Alarm bell / siren; window horizontal grills with mesh; reliable door locks kept shut in motion
- Mobile phone in each bus for emergencies
- For children under 12: occupancy ≤ **1.5×** seating capacity
- First-aid kit and fire extinguisher

**This is the single most under-served surface in the reference set.** All four vendors treat compliance as a document-expiry side-effect. None of them ship a compliance posture dashboard.

### 1.3 Capability inventory

**Table stakes** — absence is a defect
Vehicle registry · route + stop management · student assignment with seat capacity · driver assignment · transport fee linkage · document expiry reminders · fuel + maintenance logs · parent view of bus/route/driver · **live GPS location** · boarding/de-boarding notification

**Competitive** — expected in a paid product
Stop-based fare slabs · route suspension + parent notice · trip logs with checkpoint timing · transport attendance distinct from academic attendance · SOS/panic · vendor + contract management · expense→accounting posting · session rollover of assignments · role-scoped analytics · parent-initiated avail/withdrawal request · staff (not just student) transport allotment

**Differentiator** — few competitors have it
**CBSE compliance posture dashboard with disaffiliation-risk scoring** · driver readiness gating *before dispatch* (licence/medical/badge/leave) · event/excursion transport as a first-class operational container with escorts and consent · fleet health composite score · predictive maintenance from km-interval + fuel-efficiency drift · geofenced ETA with confidence bands · **compliance evidence pack export for board inspection**

**Anti-features** — deliberately do not copy
| Anti-feature | Why not |
|---|---|
| Proprietary GPS hardware lock-in (Edunext) | Contradicts a software-only SaaS model; creates supply-chain and support burden |
| Continuous dashcam/video streaming | Storage cost, child-privacy exposure, and DPDP Act consent complexity far exceed the value |
| Full route-optimization solver | NP-hard, needs real road-network data, and Indian school routes are constrained by parent negotiation not math. High cost, low realized value |
| Exposing driver's live phone location outside trip hours | Employee surveillance; a legal and ethical liability |
| SMS-first notification (ZenoxERP) | ZenXII already has an authenticated push inbox; SMS is a costly downgrade |

---

## Phase 2 — Forensic audit of what ZenXII already has

### 2.1 Admin Web Panel (PHP / CI3)

**Libraries**
- [application/libraries/Transport_service.php](application/libraries/Transport_service.php) — 7,943 LoC, domain-sectioned (FLEET / ROUTE / ASSIGNMENT / DRIVER / EXPENSE / TRIP / EVENT)
- [application/libraries/Transport_notifier.php](application/libraries/Transport_notifier.php) — 1,457 LoC, single facade for all transport notifications; 27 categories × audience scopes; priority matrix; safety-exempt never-dedupe list
- [application/libraries/Transport_analytics_service.php](application/libraries/Transport_analytics_service.php) — 711 LoC, 11-role × 14-widget visibility matrix, per-request memoisation

**Controllers** — `Transport` (967) · `Transport_ops` (422) · `Transport_driver` (335) · `Transport_event` (311) · `Transport_trip` (251) · `Transport_vendors` (143) · `Transport_sos` (124) · `Transport_analytics` (125) · `Transport_rollover` (170)
**Crons** — `Transport_expiry_cron` · `Transport_activity_cron` · `Transport_expense_reconciler` · `Transport_analytics_rollup_job` · `Transport_fleet_rollup_job`
**Assurance** — `Transport_verifier` (4,385 LoC, 94 gates) + ~30 `Transport_probe_*` runtime probes
**Views** — [application/views/transport/index.php](application/views/transport/index.php) + `partials/`

**Collections in use (18):** `vehicles` · `routes` · `transportStops` · `studentRoutes` · `routeAssignments` · `tripLogs` · `transportAttendance` · `fuelLogs` · `sosAlerts` · `transportEvents` · `eventTripAssignments` · `transportExpenses` · `transportVendors` · `transportAuditLog` · `transportNoticeIdempotency` · `driverActivitySummary` · `fleetMonthlyExpenseSummary` · `routeFleetSummary`

### 2.2 Teacher App (Kotlin)

`DriverHomeScreen.kt` · `DriverTripDetailScreen.kt` · `FuelLogScreen.kt` · `SosTriggerScreen.kt` · `TransportFirestoreRepository.kt` · `TransportAttendanceDoc.kt`

Driver is a **secure operational writer** (F9): start trip, checkpoint reached/skipped, board/drop/no-show, complete trip, fuel log, SOS with severity. Writes go direct to Firestore under driver-scope rules with client-metadata guards — no PHP proxy.

### 2.3 Parent App (Kotlin)

`TransportHomeScreen.kt` · `MyBusScreen.kt` · `TransportHistoryScreen.kt` · `TransportEventsScreen.kt` · `TransportFeesScreen.kt` · `TransportPreferencesScreen.kt` · `TransportFirestoreRepository.kt` · `TransportFeesRepository.kt`

Read-only consumer, multi-child aware. **Google Maps SDK is already a dependency** — `app/build.gradle.kts:207-209` (`play-services-maps:19.0.0`, `maps-compose:4.4.1`, `play-services-location:21.3.0`) — and is entirely unused. `MyBusScreen.kt:135-144` renders a *"Live tracking coming soon"* card.

### 2.4 GPS state — verified

| Evidence | Location |
|---|---|
| `gps_session_id` minted per trip; `live_tracking_enabled => false`; `vehicle_location_ref`, `gps_start_lat/lng/at`, `gps_end_lat/lng/at`, `gps_last_position_at`, `gps_track_url` all written `null` | [Transport_service.php:6107-6120](application/libraries/Transport_service.php#L6107-L6120) |
| Reserved-field write guard `_assertNoReservedGpsWrites` | [Transport_service.php:393-394](application/libraries/Transport_service.php#L393-L394), enforced in `saveVehicle` |
| Vehicle carries `gps_enabled` + `gps_device_id` inputs | [Transport_service.php:492-493, 602-603](application/libraries/Transport_service.php#L492-L603) |
| Verifier gate `CH-TRN-F7-1` enforces the GPS-null discipline | `Transport_verifier.php` |

**Verdict: the GPS contract is fully designed and structurally enforced. Only the telemetry tier is absent.** This is unusually good position to be in — the expensive schema decisions are already made and gate-protected.

### 2.5 Coverage matrix

| Capability | Admin Web | Teacher App | Parent App | Firestore/rules | Verdict |
|---|---|---|---|---|---|
| Vehicle registry + lifecycle | Full | — | View | Covered | **Present-and-solid** |
| Vendor + contract management | Full | — | — | Covered | **Present-and-solid** |
| Route + stop management | Full | — | View | Covered | **Present-and-solid** |
| Route suspension + parent notice | Full | — | Notified | Covered | **Present-and-solid** |
| Student assignment + seat capacity | Full | Roster | View | Covered | **Present-and-solid** |
| Session rollover of assignments | Full (chunked) | — | — | Covered | **Present-and-solid** |
| Driver management + readiness gating | Full | Own profile | Driver info | Covered | **Present-and-solid** (exceeds reference set) |
| Trip logs + checkpoints | Full | **Writer** | Today's trips | Covered | **Present-and-solid** |
| Transport attendance | Full | **Writer** | 30-day history | Covered | **Present-and-solid** |
| SOS / panic | Monitor | **Trigger** | — | Covered | **Present-but-weak** — no parent visibility |
| Fuel + maintenance + expiry engine | Full | Fuel writer | — | Covered | **Present-and-solid** |
| Expense → accounting posting | Full | — | — | Covered | **Present-and-solid** |
| Transport events / excursions | Full | — | View + consent display | Covered | **Present-and-solid** (exceeds reference set) |
| Transport fees | Via Fees | — | View + pay | Covered | **Present-and-solid** |
| Role-scoped analytics + SA overview | Full | — | — | Covered | **Present-and-solid** |
| Notification preferences | Server-authoritative | — | Toggles | Covered | **Present-and-solid** |
| **Live GPS tracking** | **Missing** | **Missing** | **Placeholder card** | Fields reserved | **Missing** |
| **Geofenced stop arrival / ETA** | **Missing** | **Missing** | **Missing** | Fields reserved | **Missing** |
| **CBSE safety-equipment compliance** | **Missing** | — | — | **Missing** | **Missing** |
| **Stop-based fare policy** | **Missing** (manual `monthly_fee`) | — | — | **Missing** | **Missing** |
| **Parent avail/withdrawal request** | **Missing** | — | **Missing** | **Missing** | **Missing** |
| **Staff transport allotment** | **Missing** (students only) | — | — | **Missing** | **Missing** |
| Verified boarding (RFID/QR) | Manual only | Manual tap | — | — | **Missing** (deliberate) |

---

## Phase 3 — Gap analysis

### 3.1 Bugs (production behaviour wrong)

**None open.** The 2026-08-04 certification closed all Critical/High/Medium/Low findings. The 5 remaining verifier FAILs (`F1-3`, `F2-2`, `F3-2`, `F7-3`, `F5-3`) are confirmed **test-tenant data artifacts**, not code defects.

### 3.2 Weaknesses (works, but fragile)

| ID | Weakness | Evidence | Severity |
|---|---|---|---|
| W-1 | Parent has **no SOS visibility** — an SOS raised on their child's bus is invisible to them | No SOS observer in Parent app | Medium |
| W-2 | `XMOD-2/3` `VehicleDoc` `@PropertyName` drift | Certification §Residual | Low — app-repo release |
| W-3 | `SEC-7` events/assignments parent-read tightening deferred | Certification §Residual | Medium |
| W-4 | Platform-wide `C1/C2/C3` rules hardening not started | Certification addendum | Medium — platform, not TMS |

### 3.3 Improvements

| ID | Improvement | Rationale |
|---|---|---|
| I-1 | **Stop-based fare policy table** | `monthly_fee` is hand-typed per assignment ([Transport_service.php:2610](application/libraries/Transport_service.php#L2610)); no `fare` construct exists anywhere in the service. Fedena has had this for a decade. Manual entry is an error and revenue-leak surface |
| I-2 | **Parent-initiated avail / withdrawal request** | Edunext ships it. Today every change is a phone call to the office |
| I-3 | **Staff transport allotment** | Fedena allots staff as well as students. ZenXII is student-only |

### 3.4 Enhancements (new capability)

| ID | Enhancement | Value | Effort | Verdict |
|---|---|---|---|---|
| **G1** | **Live GPS tracking + geofenced ETA** | Very high — the one table-stakes gap; every competitor's headline | High | **Build, second** |
| **G2** | **CBSE compliance posture + evidence pack** | Very high — mandatory, disaffiliation risk, no competitor covers it | **Low-Medium** | **Build, first** |
| G3 | RFID/QR verified boarding | Medium | High (hardware) | **Do not build now** |
| G4 | Route optimization solver | Low realized | Very high | **Do not build** |
| G5 | Driver behaviour scoring | Medium | Medium — *free rider on G1 telemetry* | **Build as G1 Phase 3** |
| G6 | Predictive maintenance from km/fuel drift | Medium | Low — data already collected | **Build, opportunistic** |

**Not worth closing:** G4 (route optimization) — NP-hard, requires road-network data ZenXII does not have, and real Indian school routes are set by parent negotiation and neighbourhood politics, not by a solver. G3 (RFID) — the *capture* problem is already solved by driver tap; RFID only improves *verification*, at the cost of a per-student tag supply chain. Revisit only if a tenant funds it.

---

## Phase 4 — Target design

### G2 — CBSE Transport Safety Compliance (build first)

**Concept.** A data-driven compliance rule set evaluated per vehicle, producing a per-vehicle and per-school **compliance posture** with a disaffiliation-risk tier, plus a one-click **evidence pack** for board inspection.

**Why first:** pure software, no hardware, no telemetry cost, reuses the F1 expiry engine and the Canonical Document Service for evidence artifacts, and it is the genuine differentiator. It also *increases the value of G1* — "GPS fitted and working" is itself a CBSE requirement, so compliance gives GPS a business justification beyond parent delight.

**Personas & stories**
- *Principal* — "Show me, in one screen, whether my fleet would survive a surprise RTO or board inspection today."
- *Transport Manager* — "Tell me which vehicle fails which requirement, and by when I must fix it."
- *School Admin* — "Generate the inspection evidence pack as a PDF with document attachments."
- *Super Admin* — "Show compliance posture across all tenants; flag schools at disaffiliation risk."

**Capability tiers**
- **MVP** — compliance rule set (CBSE pack); per-vehicle checklist; posture score; expiring/expired document rollup; non-compliance notice to ops
- **Advanced** — evidence pack export; inspection-readiness simulation; remediation workload queue with owner + due date; compliance history timeline
- **Differentiator** — disaffiliation-risk tier with a weighted model; cross-tenant SA compliance league; automatic re-check on document upload; compliance gate that *blocks dispatch* of a non-compliant vehicle (configurable, default warn-only)

**Lifecycle (per vehicle per rule):** `Not_Assessed → Compliant | Non_Compliant | Expiring_Soon | Waived` → (on evidence upload or expiry) re-evaluate. `Waived` requires a principal-level actor, a reason, and an expiry — never permanent.

**Configurability:** rule pack selection (CBSE / ICSE / State / Custom); per-rule severity weight; expiry warning windows; whether non-compliance warns or blocks dispatch; which roles may waive.

### G1 — Live GPS Tracking + Geofenced ETA (build second)

**Concept.** Driver's Teacher-app device streams throttled position during an `InProgress` trip into a **single hot document per vehicle** plus an append-only breadcrumb trail. Parents see their child's bus on a map with a stop ETA. Geofence crossings auto-fire checkpoint arrival and boarding-window notices.

**Critical design constraint:** Firestore sustains **~1 write/sec/document**. A naive "write every position to the trip doc" design will throttle and silently drop under load. The design must therefore split *hot current position* (one doc per vehicle, throttled to 15s) from *historical breadcrumbs* (append-only, one doc per ping, cheap, TTL'd).

**Capability tiers**
- **MVP** — driver foreground-service position stream during InProgress trips; `vehicleLivePositions` hot doc; parent live map; auto "bus started / bus arriving at your stop / bus reached school" notices
- **Advanced** — geofenced checkpoint auto-arrival (replaces manual driver tap); ETA per stop with confidence band; late-bus detection and proactive parent notice; admin live fleet map with all InProgress trips
- **Differentiator** — ETA from historical segment timings rather than naive distance/speed; "bus is N stops away" instead of raw minutes; replay of any completed trip from the breadcrumb trail; driver-behaviour scoring (over-speed vs the CBSE 40 km/h cap — which *feeds G2 compliance*)

**Privacy invariants (non-negotiable):**
- Position is captured **only** while a trip is `InProgress`. Trip completion stops the foreground service.
- A parent may read the position of **only** the vehicle their child is currently assigned to, and **only** during an active trip.
- Breadcrumbs are retained 30 days, then TTL-deleted.
- The driver sees an unambiguous persistent notification whenever tracking is live.

---

## Phase 5 — Data & platform design

### 5.1 G2 collections

**`transportComplianceRules/{schoolId}_{ruleId}`** — the rule pack (seeded, school-overridable)
| Field | Type | Notes |
|---|---|---|
| `schoolId` | string | tenant scope |
| `rule_code` | string | `CBSE_GPS`, `CBSE_CCTV`, `CBSE_SPEED_GOVERNOR`, `CBSE_LADY_ATTENDANT`, `CBSE_FIRST_AID`, `CBSE_FIRE_EXT`, `CBSE_ALARM_BELL`, `CBSE_WINDOW_GRILL`, `CBSE_DOOR_LOCK`, `CBSE_EMERGENCY_PHONE`, `CBSE_OCCUPANCY_RATIO`, `DOC_FITNESS`, `DOC_INSURANCE`, `DOC_PERMIT`, `DOC_POLLUTION`, `DOC_ROAD_TAX`, `DRV_LICENCE`, `DRV_BADGE`, `DRV_MEDICAL` |
| `category` | string | `Equipment` \| `Document` \| `Personnel` \| `Operational` |
| `pack` | string | `CBSE` \| `ICSE` \| `State` \| `Custom` |
| `severity_weight` | int | 1–10; drives the posture score |
| `evaluation` | string | `manual_attest` \| `document_expiry` \| `field_check` \| `derived` |
| `source_field` | string | for `field_check` — the `vehicles` field to read |
| `blocks_dispatch` | bool | default `false` |
| `active` | bool | |

**`vehicleCompliance/{schoolId}_{vehicleId}_{ruleCode}`** — per-vehicle per-rule state
`schoolId` · `vehicleId` · `rule_code` · `status` (`Not_Assessed`\|`Compliant`\|`Non_Compliant`\|`Expiring_Soon`\|`Waived`) · `evidence_document_id` (**Canonical Document Service ref — link, never copy**) · `attested_by` · `attested_at` · `expires_on` · `waiver_reason` · `waiver_by` · `waiver_expires_on` · `last_evaluated_at` · `notes`

**`vehicleCompliancePosture/{schoolId}_{vehicleId}`** — derived rollup (rebuildable)
`schoolId` · `vehicleId` · `score` (0–100) · `tier` (`Compliant`\|`Minor_Gaps`\|`Major_Gaps`\|`At_Risk`) · `failing_rule_codes[]` · `expiring_within_30d[]` · `blocks_dispatch` · `computed_at`

**Vehicle schema additions** (additive to `saveVehicle`, all default-safe):
`cctv_installed` · `cctv_working` · `cctv_last_checked` · `speed_governor_fitted` · `speed_governor_limit_kmph` · `lady_attendant_staff_id` · `first_aid_kit` · `fire_extinguisher` · `fire_ext_expiry` · `alarm_bell` · `window_grills` · `door_lock_verified` · `emergency_phone_no` · `seating_capacity_under12`

### 5.2 G1 collections

**`vehicleLivePositions/{schoolId}_{vehicleId}`** — **hot doc, one per vehicle, overwritten**
`schoolId` · `vehicleId` · `tripId` · `gps_session_id` · `lat` · `lng` · `speed_kmph` · `heading` · `accuracy_m` · `recorded_at` · `received_at` · `source` (`driver_app`\|`iot_device`) · `is_stale` (derived) · `next_stop_id` · `eta_seconds` · `progress_pct`

> Written at most once per 15s per vehicle — safely inside the 1 write/sec/doc ceiling. This is the **only** document a parent's map listener subscribes to.

**`vehicleBreadcrumbs/{schoolId}_{gpsSessionId}_{epochMs}`** — append-only trail
`schoolId` · `vehicleId` · `tripId` · `gps_session_id` · `lat` · `lng` · `speed_kmph` · `recorded_at` · `expires_at` (TTL 30d)

**`stopGeofenceEvents/{schoolId}_{tripId}_{stopId}_{eventType}`** — deterministic id ⇒ idempotent
`schoolId` · `tripId` · `stopId` · `event_type` (`enter`\|`exit`) · `occurred_at` · `lat` · `lng` · `variance_m` · `auto_checkpoint_fired`

**Existing reserved fields finally populated:** `tripLogs.live_tracking_enabled`, `vehicle_location_ref`, `gps_start_*`, `gps_end_*`, `gps_last_position_at`, `route_progress_pct`. **`_assertNoReservedGpsWrites` must be relaxed exactly once, in a controlled Phase-5 change**, and `CH-TRN-F7-1` re-scoped from "must be null" to "may be written only by the GPS ingest path."

### 5.3 Tenant scoping

Every collection above is `{schoolId}_`-prefixed **single**-prefix per the RC-3 canonical convention (`TMS_RC3_DOCID_CONVENTION.md`), plus a `schoolId` field for `schoolWhere` queries. No double-prefixing.

### 5.4 Composite indexes

```
vehicleCompliance:        (schoolId ASC, status ASC, expires_on ASC)
vehicleCompliance:        (schoolId ASC, vehicleId ASC, rule_code ASC)
vehicleCompliancePosture: (schoolId ASC, tier ASC, score ASC)
vehicleBreadcrumbs:       (schoolId ASC, gps_session_id ASC, recorded_at ASC)
vehicleBreadcrumbs:       (schoolId ASC, expires_at ASC)          -- TTL sweep
stopGeofenceEvents:       (schoolId ASC, tripId ASC, occurred_at ASC)
vehicleLivePositions:     (schoolId ASC, tripId ASC)
```

### 5.5 Security rules (deltas)

```
// Compliance — staff read, server-only write
match /vehicleCompliance/{id} {
  allow read:  if isSameSchool() && isStaff();
  allow write: if false;              // service-only via Transport_service
}
match /vehicleCompliancePosture/{id} {
  allow read:  if isSameSchool() && isStaff();
  allow write: if false;
}
match /transportComplianceRules/{id} {
  allow read:  if isSameSchool() && isStaff();
  allow write: if false;
}

// Live position — driver of the trip writes; parent of an assigned child reads
match /vehicleLivePositions/{id} {
  allow read:  if isSameSchool() && (isStaff() || isParentOfStudentOnVehicle(resource.data.vehicleId));
  allow write: if isSameSchool() && isDriverOfTrip(request.resource.data.tripId)
                  && hasClientMetadata()
                  && request.resource.data.keys().hasOnly(ALLOWED_POSITION_FIELDS);
}
match /vehicleBreadcrumbs/{id} {
  allow read:  if isSameSchool() && isStaff();
  allow create: if isSameSchool() && isDriverOfTrip(request.resource.data.tripId);
  allow update, delete: if false;     // append-only
}
```

`isDriverOfTrip` and `hasClientMetadata` **already exist** from F9. `isParentOfStudentOnVehicle` is new and is the one genuinely hard rule — it needs a `get()` against the child's `studentRoutes` assignment, which costs a rule-read per position update. **Mitigation:** denormalize an `authorized_parent_user_ids[]` array onto the live-position doc, refreshed at `createTrip` from the frozen roster. Rule becomes a cheap `in` check with no `get()`.

### 5.6 Read/write budget

**G2 (compliance)** — negligible. Posture is computed on write and on nightly cron; the dashboard reads one `vehicleCompliancePosture` doc per vehicle. 30 vehicles = 30 reads, cached.

**G1 (live tracking)** — the number that matters:

| Scale | Buses | Writes/day (15s throttle, 6h active) | Parent listener reads/day |
|---|---|---|---|
| 30 students | 1–2 | ~2,880 | ~1,400 |
| 300 students | 8–10 | ~14,400 | ~14,000 |
| 3,000 students | 40–60 | ~86,400 | ~120,000 |

At 3,000 students that is roughly **86k writes + 120k reads/day/school**. Non-trivial but not alarming. **Required mitigations, all designed in:**
- Adaptive throttle — stop writing when stationary >60s (school pickup dwell time is a large share of a trip)
- Parent listener attaches **only** while the trip is `InProgress` and the app is foregrounded on the map screen
- Breadcrumbs TTL at 30 days
- No N+1: parent map subscribes to exactly **one** document

---

## Phase 6 — Cross-module impact verification

| Surface | Verdict | Evidence |
|---|---|---|
| **Inbound deps** | Compliance is additive — nothing calls it yet. GPS is consumed by `tripLogs` readers already tolerant of null position fields | Reserved fields written null since F7 |
| **Outbound deps** | G2 → Canonical Document Service (**consume only**, link-not-copy) + `Transport_notifier` (new categories) + F1 expiry engine. G1 → `Transport_service::createTrip` (populate `authorized_parent_user_ids[]`), `completeTrip` (stop stream) | — |
| **Shared collections** | G2 adds fields to `vehicles` (additive, merge-true, existing `_assertNoReservedGpsWrites` unaffected). G1 populates already-reserved `tripLogs` fields | [Transport_service.php:585-615](application/libraries/Transport_service.php#L585-L615) |
| **Canonical Document Service** | **Consume only.** Compliance evidence links existing documents via `documentLinks` refcount. **No service modification.** Frozen baseline respected | Per platform rule |
| **Teacher App** | **Impacted — Kotlin release required.** New foreground location service, permission flow, persistent tracking notification, battery-optimisation exemption prompt | `DriverTripDetailScreen.kt` |
| **Parent App** | **Impacted — Kotlin release required.** `LiveTrackingCard` swaps placeholder for a real map. **Maps SDK already a dependency** (`build.gradle.kts:207-209`) — no new dependency | `MyBusScreen.kt:135-144` |
| **Cron jobs** | G2 adds a nightly compliance re-evaluation; can extend `Transport_expiry_cron` rather than add a new controller. G1 adds a stale-position sweeper | — |
| **Reports/exports** | New compliance evidence pack export. No existing export changes shape | — |
| **Rules & indexes** | 5 new rule blocks, 7 new composite indexes (§5.4–5.5). `firestore.rules` + `firestore.indexes.json` both change | Per `feedback_firestore_rules_per_feature` |
| **Tenant isolation** | All new collections `{schoolId}_` prefixed + `schoolId` field. The one risk is the parent live-position read — mitigated by the denormalized `authorized_parent_user_ids[]` allowlist, avoiding a cross-document `get()` | §5.5 |
| **Fees / Accounting** | **Untouched.** No new demand or journal writer. I-1 (fare policy) *would* touch Fees — deliberately scoped out of G1/G2 | — |
| **HR / SIS** | Read-only. `lady_attendant_staff_id` is a **reference** to an HR record, never a copy — matches the F8 escort pattern | — |
| **16 subsystems** | No existing write path changes semantics. Every schema change is additive with merge-true | — |

> **Cross-module verdict: CLEAR for G2 (admin-only, additive, zero app impact). CONDITIONAL for G1 — it requires coordinated Teacher + Parent Kotlin releases and a controlled, one-time relaxation of the `CH-TRN-F7-1` GPS-null gate.**

---

## Phase 7 — UI/UX

Clickable prototype published as an Artifact (see chat response for the URL). Covers Admin Web desktop, Teacher App phone, and Parent App phone, with happy path plus loading / empty / error / permission-denied / offline / bulk-in-progress / success states.

### 7.1 Screen inventory

**Admin Web** — Compliance Dashboard (posture tiles + at-risk table) → Vehicle Compliance Detail (per-rule checklist, attest, waive, link evidence) → Evidence Pack Export → Live Fleet Map (all InProgress trips) → Trip Replay
**Teacher App (driver)** — Trip Detail with a Tracking banner → permission/battery prompts → persistent tracking notification → geofence auto-arrival confirmation
**Parent App** — My Bus with live map, "N stops away", ETA band → arrival notification → offline/stale-position state

### 7.2 Field specs (compliance attestation form — representative)

| Field | Type | Validation | Default | Required | Help text | Error copy |
|---|---|---|---|---|---|---|
| Rule | read-only | — | — | — | The requirement being attested | — |
| Status | select | ∈ Compliant / Non_Compliant / Waived | Not_Assessed | Yes | Current state for this vehicle | "Select a compliance status." |
| Evidence document | doc picker | must resolve to an existing document | — | If status = Compliant | Links an existing document — never uploads a copy | "Attach evidence before marking compliant." |
| Expires on | date | ≥ today | from document | If document-backed | Auto-filled from the linked document's expiry | "Expiry cannot be in the past." |
| Waiver reason | textarea | ≥ 20 chars | — | If status = Waived | Recorded in the audit trail and shown to inspectors | "Give a reason of at least 20 characters." |
| Waiver expires | date | ≤ today + 90d | today + 30d | If status = Waived | Waivers are never permanent | "A waiver may not exceed 90 days." |

### 7.3 Copy deck

| Context | Copy |
|---|---|
| Posture tier — Compliant | "Inspection-ready. All 19 requirements met." |
| Posture tier — At Risk | "Disaffiliation risk. {n} mandatory requirements are failing." |
| Empty — no rules | "No compliance pack selected. Choose CBSE, ICSE or State to begin." |
| Parent — tracking live | "Bus {vehicle} is {n} stops away · arriving around {time}" |
| Parent — stale position | "Last seen {n} minutes ago. Signal may be weak in this area." |
| Parent — no active trip | "No trip running right now. Tracking starts when the bus departs." |
| Parent — permission denied | "Live tracking isn't available for this bus." |
| Driver — tracking on | "Sharing location · trip in progress" (persistent) |
| Driver — permission needed | "Location access is required to run a trip. Your location is shared only while a trip is in progress." |
| Geofence auto-arrival | "Arrived at {stop} — marked automatically." |
| Error — write failed | "Couldn't save. Your changes are kept locally and will retry." |

---

## Phase 8 — Correctness

### 8.1 Edge-case matrix

| Case | Required behaviour |
|---|---|
| Concurrent compliance edits | CAS via `casUpdateEntity` (RC-1 primitive already exists) |
| Partial batch failure on posture rebuild | Per-vehicle isolation; one failure never aborts the sweep; failures logged and retried by cron |
| Duplicate position write | Deterministic breadcrumb id `{schoolId}_{gpsSessionId}_{epochMs}` ⇒ idempotent |
| Duplicate geofence crossing (GPS jitter) | Deterministic id `{schoolId}_{tripId}_{stopId}_{eventType}` + a 120s cooldown before the opposite event may fire |
| Mid-session class/section change | Compliance unaffected. Live tracking re-resolves the parent allowlist at next `createTrip` |
| Student transfer / TC | Removed from `studentRoutes` ⇒ excluded from the next trip's `authorized_parent_user_ids[]`. **Existing InProgress trip retains the old allowlist** — acceptable, bounded to one trip |
| Staff deactivation (driver) | `_validateDriverReadiness` already blocks dispatch (LOG-1). Live stream stops when the trip ends |
| Session rollover | Compliance is session-independent (vehicle-scoped). Breadcrumbs TTL out naturally |
| Timezone / date boundary | All timestamps ISO-8601 with explicit offset; compliance expiry evaluated in school-local date |
| Deleted parent account | Allowlist entry becomes unresolvable; read simply fails closed |
| App offline then reconnect | Driver: positions buffered locally, flushed on reconnect with original `recorded_at` (never rewritten to arrival time). Parent: map shows last-known + "Last seen N min ago" |
| School with zero configured data | Compliance shows the pack picker, not an error. Live map shows "No trips running." |
| Trip completes while parent is watching | Listener detaches on status change; map shows the completed-trip summary |
| Driver phone battery dies mid-trip | Position goes stale >5 min ⇒ `is_stale=true`; ops notice fires; parent sees the stale banner, never a frozen "live" marker |
| Clock skew on driver device | Server `received_at` is authoritative for staleness; device `recorded_at` is retained for ordering only |

### 8.2 Idempotency & concurrency

| Write | Key | Mechanism |
|---|---|---|
| Breadcrumb | `{schoolId}_{gpsSessionId}_{epochMs}` | Deterministic id, create-only |
| Geofence event | `{schoolId}_{tripId}_{stopId}_{eventType}` | Deterministic id + cooldown |
| Live position | `{schoolId}_{vehicleId}` | Last-write-wins, throttled — **no CAS needed by design** |
| Compliance attestation | `{schoolId}_{vehicleId}_{ruleCode}` | `casUpdateEntity` |
| Posture rollup | `{schoolId}_{vehicleId}` | Derived, fully rebuildable — idempotent by construction |

### 8.3 Data-integrity invariants

1. A `vehicleLivePositions` doc exists **only** while its `tripId` is `InProgress`.
2. Every `vehicleCompliance` row references a rule that exists and is `active`.
3. `vehicleCompliancePosture` is always recomputable from `vehicleCompliance` + `vehicles`; it is never authoritative.
4. A `Waived` row always has a non-null `waiver_reason`, `waiver_by`, and a `waiver_expires_on` ≤ 90 days out.
5. Compliance evidence never copies a document — it links via the Canonical Document Service and holds a refcount.
6. No breadcrumb outlives its `expires_at`.
7. `authorized_parent_user_ids[]` is written only at `createTrip`, from the frozen roster — never mutated mid-trip.

### 8.4 Test plan

- **Verifier gates (new):** `CH-TRN-CMP-1` rule-pack completeness · `CH-TRN-CMP-2` posture recomputability · `CH-TRN-CMP-3` waiver bounds · `CH-TRN-CMP-4` evidence link-not-copy · `CH-TRN-GPS-1` position doc only for InProgress trips · `CH-TRN-GPS-2` breadcrumb append-only + TTL · `CH-TRN-GPS-3` geofence idempotency · `CH-TRN-GPS-4` parent allowlist tenant scoping
- **Runtime probes:** `Transport_probe_cmp` (attest/waive/expire/rebuild/evidence-link) · `Transport_probe_gps` (throttle, stale detection, geofence dedupe, allowlist isolation, offline flush)
- **Rules emulator:** extend the existing 199-test suite — parent-of-other-school read denied, parent-of-unassigned-child denied, driver-of-other-trip write denied, breadcrumb update/delete denied
- **Manual QA:** Admin compliance walkthrough; Teacher two-device concurrent trip; Parent multi-child with two buses on two routes
- **CLI:** `scripts/verify_transport_compliance.php`, `scripts/verify_gps_integrity.php`

### 8.5 Observability

Log: every attestation and waiver (actor + reason) · posture tier transitions · position write rejections · stale-position transitions · geofence auto-fires and their variance.
Audit: all compliance mutations via the existing `transportAuditLog`.
Alert: any vehicle entering `At_Risk` · any InProgress trip with position stale >10 min · geofence variance consistently >200 m (indicates a bad stop coordinate).

---

## Phase 9 — Delivery plan

### Sequencing

| Phase | Scope | Surfaces | Est. LoC | Ships independently |
|---|---|---|---|---|
| **F12.1** | Compliance MVP — rule pack, vehicle fields, attestation, posture, dashboard | Admin only | ~1,400 | Yes |
| **F12.2** | Compliance Advanced — evidence pack export, remediation queue, nightly re-eval, notices | Admin only | ~700 | Yes |
| **F13.1** | GPS ingest — driver foreground service, live-position doc, breadcrumbs, rules, indexes | Admin + **Teacher** | ~900 PHP + ~700 Kotlin | Yes (admin live map only) |
| **F13.2** | Parent live map — map swap, ETA, arrival notices | **Parent** | ~250 PHP + ~600 Kotlin | Yes |
| **F13.3** | Geofence automation — auto-checkpoint, late-bus detection, driver-behaviour scoring → feeds G2 | Admin + Teacher | ~800 | Yes |

### File-by-file (F12.1, the recommended first slice)

| File | Change | Est. |
|---|---|---|
| `application/libraries/Transport_service.php` | New `DOMAIN_COMPLIANCE` section; extend `saveVehicle` with 14 additive fields | +650 |
| `application/libraries/Transport_compliance_service.php` | **New** — rule evaluation + posture computation | +450 |
| `application/controllers/Transport_compliance.php` | **New** — ~8 endpoints, role-gated | +200 |
| `application/views/transport/index.php` + `partials/compliance_modal.php` | Compliance tab | +250 |
| `application/controllers/Transport_verifier.php` | +4 gates | +120 |
| `application/controllers/Transport_probe_cmp.php` | **New** probe | +150 |
| `application/config/numbering.php` | `trn_compliance_waiver` kind | +5 |
| `firebase-rules/firestore.rules` | 3 new blocks | +25 |
| `firebase-rules/firestore.indexes.json` | 3 new indexes | +20 |

### 8 release gates

| Gate | How satisfied |
|---|---|
| 1. Functional correctness | `Transport_probe_cmp` + 4 new verifier gates; full 94-gate suite must stay green |
| 2. Cross-module impact | §6 — additive only; Document Service consumed not modified; Fees/Accounting/HR untouched |
| 3. Tenant isolation | Single `{schoolId}_` prefix per RC-3; `schoolWhere` on every query; new rules-emulator cases |
| 4. Rules + indexes | 3 rule blocks + 3 indexes shipped in the same commit |
| 5. Both apps | F12.1: **certified no app impact** (admin-only, zero Kotlin references). F13.x: coordinated releases required |
| 6. Regression audit | Full verifier + all ~30 existing probes re-run before commit |
| 7. Rollback | Purely additive — disable the compliance tab and the feature is inert. No destructive migration |
| 8. Performance | Posture precomputed on write + nightly; dashboard = 1 read/vehicle; no N+1; budget in §5.6 |

### Risk register

| Risk | Severity | Mitigation |
|---|---|---|
| GPS write volume exceeds budget at scale | High | 15s throttle + stationary suppression + one hot doc per vehicle; measure at 300-student tenant before scaling |
| Parent position read leaks across tenants | **Critical** | Denormalized `authorized_parent_user_ids[]` allowlist; dedicated emulator tests; no cross-doc `get()` |
| Relaxing `CH-TRN-F7-1` weakens the GPS-null discipline | Medium | Re-scope the gate rather than delete it — assert "written only by the ingest path" |
| Driver battery drain kills adoption | High | Foreground service with adaptive throttle; explicit battery-optimisation exemption prompt; stop on trip completion |
| Compliance attestation becomes a rubber stamp | Medium | Require evidence documents for document-backed rules; bound waivers to 90 days; log every actor |
| Scope creep from G2 into fare policy (I-1) | Medium | Explicitly out of scope; I-1 touches Fees and needs its own cycle |

### What I would NOT build

- **Route optimization solver (G4)** — NP-hard, needs road-network data ZenXII lacks, and real routes are set by parent negotiation. Very high cost, low realized value.
- **RFID / biometric boarding (G3)** — driver tap already captures boarding; RFID only improves verification at the cost of a per-student tag supply chain. Revisit only if a tenant funds it.
- **Dashcam / video** — storage cost plus child-privacy and DPDP consent exposure far exceed the value.
- **Proprietary GPS hardware** — contradicts the software-only SaaS model. Support IoT ingest as an *optional* second source; never require it.
- **Parent-visible driver location outside trip hours** — employee surveillance; legal and ethical liability.
- **Fare policy (I-1) inside this cycle** — a real gap, but it touches Fees and deserves its own release.

---

*Research and design artifact. No application code was written or modified. Awaiting review before any implementation authorization.*
