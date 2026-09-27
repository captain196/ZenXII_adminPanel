# F14 Phase 1 — Architecture Verification

**Status: verification complete. No architectural conflict found. Implementation proceeding.**
Decisions F-1, F-2, F-3, F-4 are LOCKED per operator authorization.

---

## 1–2. Reuse map — what exists, what changes

| Need | Reused (canonical) | Change |
|---|---|---|
| Eligibility authority | `studentRoutes` via `Transport_service::assignStudent` etc. | **read-only** — new resolver |
| Attendance store | `transportAttendance` | +fields (additive) |
| Single attendance writer | `Transport_service::_writeBoardingEvent()` | extended (4 events, alternate-bus, resolution) |
| Idempotency | existing docId `{schoolId}_{tripId}_{studentId}_{eventType}` | +`client_event_id` for offline replay |
| Frozen roster | `tripLogs.roster_student_ids` | **untouched** |
| QR verify | `qr_token_helper::qr_token_decode()` | +strict signed-only wrapper |
| Scan precedent | `Campus_access::scan_qr_lookup()` | pattern only |
| Driver auth | `Transport_driver_api::_assertOwnTrip()` (ownership, no RBAC) | reused for new endpoints |
| Notifications | `Transport_notifier` (27 categories, 30-day idempotency, `SCOPE_PARENTS_OF_STUDENTS`) | +4 categories |
| **School geofence** | `schools.{id}.attendancePolicy.gps.geofences[]` (`centerLat`,`centerLng`,`radius`, `maxAccuracyMeters`, `boundaryToleranceMeters`) — already used by Staff Attendance | **read-only** |
| **Bus position** | F13.1 `vehicleLivePositions` via `Transport_gps_service` | **read-only, untouched** |
| Fleet map | F13.2 `Transport_fleet_service` | additive counters only |

**No second** attendance store, QR system, notifier, roster, GPS system, or entitlement system.

## 3. Files that change

**New**
- `application/libraries/Transport_qr_attendance_service.php` — F14 orchestration: QR→student,
  eligibility resolution, roster/alternate decision, state machine, school-arrival gate,
  reconciliation. Delegates every write to the existing `_writeBoardingEvent`.
- `application/controllers/Transport_probe_f14.php` — the 23 gates.

**Extended**
- `Transport_service.php` — `_writeBoardingEvent()` accepts the 4 canonical events + assigned/actual
  + boarding_mode + resolution; new `markStudentAlightedAtSchool()`, `markStudentBoardedAtSchool()`,
  `markStudentAlightedAtStop()`; legacy methods kept as aliases.
- `Transport_driver_api.php` — `+scan`, `+mark_all_alighted_at_school`, `+resolve_exception`,
  `+trip_roster` (ownership-authorized).
- `Transport_notifier.php` — 4 categories + priority/audience matrix rows.
- `firebase-rules/firestore.rules` — parent read arm on `transportAttendance`; driver **create
  denied** (server-only writes now).
- `firebase-rules/firestore.indexes.json` — `schoolId + tripId + event_type + event_at`.
- `Transport_verifier.php` — F14 gates.

## 4. Firestore implications

**Additive fields on `transportAttendance`:**
```
assigned_route_id, assigned_vehicle_id, assigned_trip_id, assigned_stop_id
actual_route_id,   actual_vehicle_id,   actual_trip_id
boarding_mode        ∈ {assigned, alternate_bus, operational_exception}
alternate_reason
resolution_state     ∈ {none, boarded, alternate_bus, absent, verified_other, unresolved}
resolution_reason, resolved_by, resolved_at
scan_source          ∈ {qr_scan, manual, bulk_school_arrival}
client_event_id      (offline idempotency key)
client_event_at      (device time; server keeps event_at/created_at authoritative)
```
Existing rows are untouched; every new field defaults absent and reads as its safe default.

**Rules change — the important one:** driver `create` on `transportAttendance` becomes **`false`**.
All writes are server-mediated (F-2). Rules stop being the authorization boundary for attendance and
become defence-in-depth. Parent gains a read arm using the three-arm ownership pattern from
`tripLogs`.

## 5. Endpoints (all ownership-authorized, no RBAC — driver holds no Transport grant)

| Endpoint | Purpose |
|---|---|
| `POST transport_driver_api/scan` | raw QR → full validation chain → event |
| `POST transport_driver_api/mark_all_alighted_at_school` | bulk `alighted_at_school`, **geofence-gated**, only students who boarded |
| `POST transport_driver_api/resolve_exception` | absent / verified_other; alternate_bus only when unverifiable |
| `GET  transport_driver_api/trip_roster` | driver's operational roster + counts |

## 6. Offline queue (Teacher app)

Room is **not** currently a dependency (only DataStore). Adding `androidx.room` for a durable queue:
`attendance_queue(client_event_id PK, trip_id, raw_qr|student_id, event_type, client_event_at,
attempts, last_error, state)`. Flush is sequential per trip, ordered by `client_event_at`. Server
rejects duplicates on `client_event_id`; a rejection is terminal, not retried forever. **Local state
is never authoritative** — the UI shows queued events as *pending*, never as recorded.

## 7. Notifications — 4 new categories

`student.boarded_at_stop`, `student.alighted_at_school`, `student.boarded_at_school`,
`student.alighted_at_stop` — scope `SCOPE_PARENTS_OF_STUDENTS`, priority Important, dedupe key = the
attendance doc id, so offline replay cannot double-notify. Alternate-bus boarding names the actual
bus/route in the message.

## 8. Admission integration (F-3 hybrid)

`decl_transport` + route + stop present → create the `studentRoutes` assignment through the canonical
`assignStudent()`. Otherwise → `transportRequests` worklist item (`pending`), never an incomplete
assignment and never a fee demand. The same worklist serves mid-session requests.

## 9. Alternate-bus reconciliation

Evidence-first: when reconciling Bus A, the service queries `transportAttendance` for the same
`(schoolId, studentId, trip_date, session)` with a boarding event on **any other trip**. Found →
`resolution_state = alternate_bus`, automatically, with `actual_*` populated from that event. Not
found → `unresolved`, and only then may the driver resolve manually. A driver can never *assert*
alternate-bus when the system can check.

## 10. State machines — morning and afternoon are separate

```
MORNING   (trip_direction = Pickup)
  none → boarded_at_stop → alighted_at_school
AFTERNOON (trip_direction = Drop)
  none → boarded_at_school → alighted_at_stop
```
Illegal transitions rejected. Re-scan of the same event = idempotent no-op ("already boarded").
Cross-leg events rejected (`alighted_at_stop` on a Pickup trip, etc.).

**Completion invariants (locked):**
- **Morning:** every *expected* student must be resolved — boarded→alighted, alternate_bus, absent, or
  verified_other. Only `unresolved` blocks. Counts are NOT required to be equal.
- **Afternoon:** every student who *boarded* must have `alighted_at_stop`. No bulk action exists.
- Morning and afternoon totals are **never** compared.

## 11. School-arrival geofence dependency

`mark_all_alighted_at_school` reads the bus position from F13.1 `vehicleLivePositions` and the fence
from `attendancePolicy.gps.geofences`, then applies haversine + `boundaryToleranceMeters`. Outside the
fence, or with a stale/imprecise fix (F13.1's own `STALE_AFTER_S` / `MAX_ACCURACY_M`), the operation
is **refused** with an explicit reason; the trip stays active and Admin can see why.

**Known dependency, reported:** this requires a live GPS fix. On a device with no usable fix — exactly
the situation blocking F13 UAT today — school arrival cannot be confirmed. This is correct behaviour,
not a defect, but it means F14 morning completion inherits F13.1's GPS preconditions.

## 12. Security boundaries

QR = identifier, never a credential. Server resolves every hop; the APK never holds the HMAC secret;
legacy unsigned tokens rejected on this path; tenant checked three ways (token schoolId, driver claim,
trip schoolId); roster gate with explicit-only alternate override; immutable events
(`update, delete: if false`); idempotent doc ids + `client_event_id`; notifier dedupe.

**Unresolved accountability gap (reported, not solved):** a conductor scanning on the driver's phone
is attributed to the driver, because attribution follows the session holder.

---

**Proceeding to implementation. No commit, push, merge or deploy.
MANUAL UAT = NOT PERFORMED.**
