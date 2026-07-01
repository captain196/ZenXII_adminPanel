# ZenX ERP V7 — GPS Staff Attendance (Attendance Policy Framework, Phase 1)
## Implementation Summary & Production-Readiness Reference

**Status:** Code complete across 14 phases (backend + Teacher app). Firestore-only.
**Branch:** `ank-yug_b1` · **Backend:** `c:\xampp\htdocs\ZenX\school` · **Teacher app:** `D:\Projects\SchoolSyncTeacher`
**Not yet committed/pushed; rules+indexes not yet deployed; on-device + live-backend runtime tests pending (see §15).**

---

## 1. Final architecture

GPS is the first concrete *method* in an extensible **Attendance Policy Framework**. Every capture method (GPS now; biometric/QR/RFID/face/manual later) flows through one server pipeline and one writer:

```
Teacher App (Kotlin)                 ZenX backend (PHP/CI)                 Firestore (sole source of truth)
─────────────────────                ─────────────────────                ────────────────────────────────
LocationProvider (GPS fix)  ──HTTPS+Bearer──►  Staff_attendance::punch       schools/{id}.attendancePolicy
  → Repository (HTTP)                 │ 1 Api_auth (uid==staffId)            calendarEvents (holidays)
  → ViewModel (state)                 │ 2 Attendance_policy.evaluate()       staffAttendance / *Summary
  → MyAttendanceScreen (UI)           │ 3 Holiday_service (cached)           attendancePunches (audit)
                                      │ 4 Staff_attendance_writer (CAS)
                                      └ 5 audit (accepted + rejected)
```

**Invariants:** Firestore only (zero RTDB); business rules only in `Attendance_policy`; persistence only in `Staff_attendance_writer`; the **server is the sole authority** (client GPS is advisory).

---

## 2. Firestore collections

| Collection | Doc ID | Role | Writer |
|---|---|---|---|
| `schools/{id}` `.attendancePolicy` | school id | geofence + windows + accuracy + methods (config) | Admin (`save_attendance_policy`) |
| `staffAttendance` | `{schoolId}_{date}_{staffId}` | canonical per-day status | `Staff_attendance_writer` (+ HR leave) |
| `staffAttendanceSummary` | `{schoolId}_{staffId}_{YYYY-MM}` | canonical per-month `dayWise` + counts | `Staff_attendance_writer` (+ HR leave) |
| `attendancePunches` | `{schoolId}_{clientPunchId}` | append-only audit (accepted+rejected, geo+method) | `Staff_attendance::punch` |
| `staffAttendanceLocks` | `{schoolId}_{session}_{month}` | month lock | `lock_staff_attendance` |
| `calendarEvents` (`type=holiday`) | `{schoolId}_{eventId}` | canonical holidays | Calendar_service (Academic) |
| `leaveApplications` | `LR_*/BAL_*` | leave requests + balances | HR |
| `salarySlips` | `SLIP_*/RUN_*` | payroll output | HR |

**Keying:** doc-ID prefix = `school_id` (`school_name === school_id`, [MY_Controller.php:89]). Leave path and writer share the same docs.

---

## 3. Android architecture (Teacher app)

`LocationProvider → StaffAttendanceRepository → StaffAttendanceViewModel → MyAttendanceScreen`

- **`data/location/`** — `LocationProvider` (Fused high-accuracy one-shot fix, permission/services check, mock detection API31+/legacy), `LocationModels` (`LocationFix`/`LocationOutcome`/`LocationError`/`GpsStatus`), `GeofenceGuide` (pure haversine, guidance only), `LocationPermissions` (state interpreter incl. permanently-denied).
- **`data/repository/StaffAttendanceRepository`** — calls `staff_attendance/punch`+`me`; maps network/timeout/auth/forbidden/disabled/rejected/server → typed `StaffAttendanceError`. No GPS, no UI, no token code.
- **`ui/myattendance/StaffAttendanceViewModel`** — UI state + orchestration (LocationProvider + Repository + geofence read for guidance). No business logic.
- **`ui/myattendance/MyAttendanceScreen`** — status, check-in/out, working duration, GPS guidance, full permission flow; **records only on explicit tap**.
- **Auth:** reuses the existing `AuthInterceptor` (Bearer auto-attach). **`BASE_URL` → `/ZenX/school/`.** Dep added: `play-services-location`.

---

## 4. Backend architecture

- **`controllers/Staff_attendance.php`** — orchestration-only `punch()` + `me()` (app-API: `CI_Controller` + `Api_auth`, Firestore session, fail-closed).
- **`libraries/Attendance_policy.php`** — pure rule engine; `evaluate()` returns `{allowed, reason, mark, setsStatus, lateMinutes, distanceMeters, …}`.
- **`libraries/Holiday_service.php`** — Firestore `calendarEvents` holiday abstraction, two-tier cache, fail-open.
- **`helpers/geofence_helper.php`** — pure haversine + radius/accuracy gates.
- **`helpers/attendance_view_helper.php`** — pure `me()` shaping (history, in/out times).
- **`libraries/Staff_attendance_writer.php`** *(reused unchanged)* — CAS-protected canonical writer.
- **`controllers/Attendance.php`** *(edited)* — `save/get_attendance_policy` (Firestore) + dashboard staff block repointed to canonical collections.

---

## 5. Attendance lifecycle & precedence

```
1. Month lock (attendance)               → absolute: writer throws → month_locked
2. Approved full-day Leave (L)           → beats GPS (peek rejects on_leave; leave overwrites P→L)
3. Manual HR/Admin override              → explicit human decision wins over GPS
4. GPS/Device punch (P/T)                → automated; FIRST in-of-day wins; A → already_marked (rejected)
5. Holiday (H) / Vacant (V)              → calendar/default; GPS rejects on_holiday
```
Check-out records an OUT punch (audit) without changing day status. Payroll lock does **not** block attendance writes (separation of concerns); lock attendance at finalization to freeze the source.

---

## 6. HR integration

Leave approval (`Hr::_apply_leave_to_attendance`) writes `L` via the same collections → GPS peek rejects `on_leave`; cancellation reverts `L→V`. Half-day leave stays workable (no `L`). Manual marking (`mark_staff_day`) overwrites via the writer. No duplicate logic introduced — GPS respects the existing HR leave/lock surfaces.

## 7. Payroll integration

`Hr::generate_payroll` reads `staffAttendanceSummary.dayWise` and branches **only** on the status char (`A`/`L`/`V`; `P`/`T` = worked) → **method-agnostic** (proven: the interpretation region references no source/method/gps). GPS attendance feeds LOP, working-days, paid-leave/LWP, and `vacant_treatment` with **zero payroll changes**. `T` (late) is currently payroll-inert (see §11).

---

## 8. Security model

- **Identity:** `staffId = Firebase token uid` — never client-supplied; a teacher can only punch themselves.
- **Multi-tenant:** `schoolId` from token; cross-school blocked.
- **Server-authoritative:** geofence/accuracy/mock/window/leave/holiday/lock all decided server-side; client GPS advisory.
- **Anti-spoof:** mock-location rejected; accuracy ceiling; server timestamp (client time ignored); every accepted+rejected punch audited with geo+reason+device+correlationId.

## 9. Firestore Rules (`firebase-rules/firestore.rules`)

`staffAttendance`, `staffAttendanceSummary`, `attendancePunches`: **read** own (`resource.data.staffId == request.auth.uid`) or same-school admin; **write: false** (Admin SDK only). Admin/payroll/HR/dashboard read via Admin SDK (bypass rules). **Deploy required.**

## 10. Firestore Indexes (`firebase-rules/firestore.indexes.json`)

Existing F-SB-1…7 cover register/report/substitute/payroll/dashboard. **New:** `attendancePunches (schoolId, staffId, date)` for `me()`. All attendance queries index-served — no collection scans. **Deploy required.**

## 11. Future extension points

- **Methods:** biometric/QR/RFID/face/manual → set `enabledMethods` + send `method`; same pipeline/writer/audit.
- **Shifts:** `attendancePolicy.shifts` (default only in P1); per-staff shift later (no punch-path read).
- **Late→payroll policy, overtime, comp-off, early-departure:** raw data already captured in `attendancePunches` (in/out, lateMinutes); engines later.
- **Offline punches:** `clientPunchId`/`clientCapturedAt` contract already in place; add queue + server time-skew rules.
- **Trusted-device:** policy hook reserved (`requireTrustedDevice`).
- **Migrations (approval-gated):** retire legacy RTDB holiday readers + RTDB `save_settings` onto Firestore.

---

## 12. Read/write profile

| Op | Firestore reads | Firestore writes |
|---|---|---|
| Check-in (first of day) | 3 (schools, summary peek, writer CAS) + holiday cold ≤1 | 3 (att+summary batch, audit) |
| Check-in (dup/reject) | 1–2 | 1 (audit) |
| Check-out | 2 | 1 (audit) |
| `me()` | ~3 | 0 |
| Admin policy save | 0–1 | 1 (+1 auditLogs) |

## 13. RTDB audit (final)

New components (`Staff_attendance`, `Attendance_policy`, `Holiday_service`, `geofence_helper`, `attendance_view_helper`, new policy methods): **0 RTDB calls** (verified by sweep). Standing RTDB items documented for later approval-gated migration: legacy holiday readers, legacy `save_settings`.

## 14. Rollback

Per-tenant kill: `attendancePolicy.gps.geofence.active = false`. Code: revert per-phase (all additive); delete new files; revert the 3 edited PHP spots + Teacher `BASE_URL`/manifest/gradle/NavGraph. No data migration to undo.

---

## 15. Runtime verification checklist (device + live ZenX backend — pending execution)

These require the running backend + a Teacher build; not executable in static analysis.

**Teacher:** login → My Attendance → GPS guidance shows distance/accuracy/inside → Check In (inside, on-time → Present; late → Late) → Check Out → history shows it → permission flows (grant / deny / don't-ask-again→Settings) → GPS off prompt → mock GPS rejected → poor accuracy rejected → outside geofence rejected.
**Admin:** the punch appears in Staff Register, Individual Report, Dashboard present/late/absent counts, reports.
**HR:** approve leave (GPS day → `on_leave`); cancel leave (reverts); manual override; lock month (punch → `month_locked`).
**Payroll:** generate a month with GPS marks → working-days/paid-leave/LWP/vacant_treatment correct; `T` full-pay.
**Security:** teacher reads only own; client write denied (rules deployed); cross-school denied; mock/geofence enforced.
**Performance to measure:** check-in/out response time, time-to-appear in admin, dashboard latency, read/write counts (analytical targets in §12).

**Pre-go-live:** `firebase deploy --only firestore:rules,firestore:indexes`; confirm ZenX served at the app `BASE_URL` host; ensure each tenant has `attendancePolicy` configured.
