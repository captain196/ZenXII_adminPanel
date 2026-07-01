# ZenX ERP — GPS Staff Attendance
## Production Live Validation Execution Runbook (QA Team)

**Audience:** QA engineers executing on a **deployed** ZenX ERP environment (live PHP/Apache + live Firestore project + real Android devices).
**Nature:** Operational execution guide. This runbook changes **no code**. It *does* deploy Rules/Indexes and create **test-tenant** data on a **non-production / staging** Firebase project (or an explicitly-approved isolated tenant). Do **not** run destructive steps against a live customer tenant.
**Mandatory vs Optional legend:** **[M]** = mandatory before production; **[O]** = optional / post-GA.

### Reference facts (constants used throughout)
- **Endpoints:** `POST {BASE}/staff_attendance/punch`, `GET {BASE}/staff_attendance/me`, admin `GET {BASE}/attendance/punch_log`, `POST {BASE}/attendance/fetch_punch_log`. `{BASE}` = `https://<host>/ZenX/school/`.
- **Auth:** Firebase ID token as `Authorization: Bearer <token>`; custom claims `uid`, `role`, `school_id`; convention `uid == staffId`, `school_name === school_id`.
- **Collections / keys:**
  - `staffAttendance` → `{schoolId}_{YYYY-MM-DD}_{staffId}`
  - `staffAttendanceSummary` → `{schoolId}_{staffId}_{YYYY-MM}` (field `dayWise`, 1 char/day: `P T A L H V`)
  - `attendancePunches` → `{schoolId}_{clientPunchId}` (audit; accepted + rejected)
  - `attendancePolicy` → map on `schools/{schoolId}`
  - `calendarEvents` → holidays (`type=holiday`, carries `session`)
  - `staffAttendanceLocks`, `salarySlips`, `leaveApplications`, `staff/{schoolId}_{staffId}`
- **Status chars:** P=present, T=late/tardy, A=absent, L=leave, H=holiday, V=vacant.
- **Reject reason codes (engine):** `invalid_coords, invalid_direction, method_disabled, gps_disabled, mock_location, poor_accuracy, outside_geofence, on_leave, on_holiday, too_early, window_closed, already_marked`; plus gate codes `staff_not_found, staff_inactive`; writer `month_locked`; idempotent `already_checked_in`.

### Evidence-capture conventions (apply to every step)
Capture into a per-step folder `evidence/<section>/<step>/`: (a) **API**: full request + JSON response + HTTP status (`curl -i` or Postman export); (b) **Firestore**: screenshot or `gcloud`/console export of the affected document(s) before/after; (c) **UI**: screenshot of the relevant admin/app screen; (d) **Logs**: relevant lines from `application/logs/log-YYYY-MM-DD.php` and Firebase usage; (e) **Timestamp + tester initials**. Record each step's verdict in the §16 sign-off sheet as **Verified / Failed / Blocked**.

### Defect protocol
On any **Fail**: stop the affected workflow, capture all evidence, file a defect with root-cause hypothesis + severity (Critical/High/Medium/Low) + production-impact, and **escalate for approval before any fix**. Do not self-remediate.

---

## Section 1 — Firestore Rules Deployment **[M]**
**Objective.** Deploy least-privilege security rules so clients can read only their own attendance and never write.
**Preconditions.** `firebase-tools` authenticated to the **staging** project; `firebase.json` references `firebase-rules/firestore.rules`; deployer has Editor/Owner.
**Execution steps.**
1. `firebase use <staging-project>`
2. `firebase deploy --only firestore:rules`
3. In Firebase Console → Firestore → Rules, confirm the deployed version timestamp matches the deploy.
**Expected Firestore R/W.** None (config change).
**Expected UI.** Console shows new ruleset active.
**Expected API.** N/A.
**Expected audit.** Deploy log line in CI/terminal.
**Pass criteria.** `staffAttendance`, `staffAttendanceSummary`, `attendancePunches` each show `allow read: own || (isAdmin && isSameSchool)` and `allow write: if false`.
**Fail criteria.** Deploy error; or any of the three shows a write rule other than `false`.
**Evidence.** Deploy output; Console rules screenshot showing the three blocks.
**Mandatory.** [M].

## Section 2 — Firestore Index Deployment **[M]**
**Objective.** Build the composite index for the `me()` audit query so it runs as a single index scan.
**Preconditions.** Section 1 done; `firestore.indexes.json` present.
**Execution steps.**
1. `firebase deploy --only firestore:indexes`
2. Console → Firestore → Indexes → wait until `attendancePunches (schoolId ASC, staffId ASC, date ASC)` status = **Enabled**.
**Expected R/W.** None.
**Expected UI.** Index list shows the new composite as Building→Enabled.
**Expected API.** Until Enabled, `me()` today-punch query may error (`FAILED_PRECONDITION`) — that is expected pre-build.
**Expected audit.** Deploy log.
**Pass criteria.** Composite index status = Enabled.
**Fail criteria.** Index stuck in Error; or definition differs from `(schoolId, staffId, date)`.
**Evidence.** Index console screenshot (Enabled).
**Mandatory.** [M].

## Section 3 — Test Tenant Preparation **[M]**
**Objective.** Create an isolated test school + staff accounts with correct claims.
**Preconditions.** Sections 1–2; admin access to SA tooling + Firebase Auth.
**Execution steps.**
1. Create test school `SCH_TEST_A` (note its `schoolId`); confirm `schools/{SCH_TEST_A}` exists with `currentSession` set.
2. Create ≥3 staff: `staff/{SCH_TEST_A}_{staffId}` docs with `status: "Active"` (one extra with `status: "Inactive"` for ISSUE-1).
3. In Firebase Auth, set custom claims on each staff user: `uid=<staffId>`, `role=<teacher role>`, `school_id=SCH_TEST_A`.
4. Create one admin/HR user with admin role + `school_id=SCH_TEST_A`.
**Expected R/W.** Writes to `schools`, `staff`, Auth users (setup only).
**Expected UI.** Users visible in admin list.
**Expected API.** Each staff can mint an ID token (verify via login).
**Expected audit.** Standard onboarding audit (out of GPS scope).
**Pass criteria.** 3 active + 1 inactive staff; claims present; admin user present.
**Fail criteria.** Missing claims; `uid != staffId`; school mismatch.
**Evidence.** `staff/*` docs; Auth custom-claims screenshot.
**Mandatory.** [M].

## Section 4 — Academic Calendar Configuration **[M]**
**Objective.** Author at least one holiday in the canonical source so holiday rejection + payroll H-stamping can be validated.
**Preconditions.** Section 3.
**Execution steps.**
1. As admin, open `{BASE}/academic#calendar`.
2. Create a holiday on a known test date (e.g., the day you will use for the "on holiday" punch test) for the current session.
3. Open `{BASE}/attendance` → Holiday Management tab and confirm it shows **read-only** with the same holiday (via Holiday Status / "Open Academic Calendar" deep-link).
**Expected R/W.** Write 1 `calendarEvents` doc (type=holiday, session=current); reads on display.
**Expected UI.** Holiday appears in Academic Calendar; Attendance Settings shows it read-only (no Save).
**Expected API.** N/A.
**Expected audit.** Calendar write (Calendar_service).
**Pass criteria.** Holiday present in `calendarEvents`; read-only mirror in Attendance Settings.
**Fail criteria.** Attendance Settings offers holiday editing (should be removed); holiday not in `calendarEvents`.
**Evidence.** `calendarEvents` doc; both screens' screenshots.
**Mandatory.** [M].

## Section 5 — GPS Policy Configuration **[M]**
**Objective.** Configure the geofence + windows so the engine can accept/reject correctly.
**Preconditions.** Section 3.
**Execution steps.**
1. As admin → `{BASE}/attendance` → GPS Attendance tab.
2. Set `enabledMethods` to include `gps`; set geofence `active=true`, `centerLat/centerLng` (use the test campus / a known device location), `radius` (e.g., 150 m), `maxAccuracyMeters` (e.g., 50), `allowMockLocation=false`.
3. Set shift `default` windows: `earliestCheckIn`, `latestCheckIn`, `lateThreshold` (e.g., 09:00), `gracePeriodMin`, `timezone` (e.g., Asia/Kolkata). Save.
4. Confirm `schools/{SCH_TEST_A}.attendancePolicy` reflects the values.
**Expected R/W.** Write `schools/{id}` (attendancePolicy map); reads on render.
**Expected UI.** Saved policy renders back; geofence map/values visible.
**Expected API.** N/A (admin save endpoint).
**Expected audit.** None GPS-specific.
**Pass criteria.** `attendancePolicy.gps.geofence.active=true` with valid center+radius; windows present.
**Fail criteria.** Policy not persisted; geofence inactive; `gps` not in `enabledMethods`.
**Evidence.** `schools/{id}.attendancePolicy` doc; GPS tab screenshot.
**Mandatory.** [M].

## Section 6 — Android Device Validation Matrix **[M]**
**Objective.** Validate the Teacher app captures location and punches correctly across real device conditions.
**Preconditions.** Sections 1–5; signed Teacher app build with `BASE_URL=/ZenX/school/`, location permissions, play-services-location; ≥1 physical Android device; tester physically inside and outside the geofence.
**Execution matrix (run each row, capture evidence):**
| # | Condition | Action | Expected |
|---|---|---|---|
| 6.1 | Inside geofence, good GPS, on-time | Check In | Accepted, status P |
| 6.2 | Inside, after lateThreshold+grace | Check In | Accepted, status T, lateMinutes>0 |
| 6.3 | Outside geofence | Check In | Rejected `outside_geofence` |
| 6.4 | Poor accuracy (>max) | Check In | Rejected `poor_accuracy` |
| 6.5 | Mock location app enabled | Check In | Rejected `mock_location` |
| 6.6 | Location permission denied | Check In | App blocks, prompts permission; no punch sent |
| 6.7 | GPS/location services off | Check In | App shows GPS error; no punch sent |
| 6.8 | Offline → retry when online | Check In offline, then online | Same `clientPunchId` reused; exactly one accepted; no duplicate |
| 6.9 | Check Out (evening) | Check Out | Accepted, audit-only, no status change |
**Expected R/W per accepted check-in.** ~4R (staff gate · schools(memo) · summary · writer CAS) + 3W (staffAttendance + staffAttendanceSummary batch, attendancePunches audit).
**Expected UI.** Result banner (Checked in / late / rejected reason); My Attendance refreshes today status.
**Expected API.** `punch` → 200 with `{status, mark, lateMinutes, distanceMeters}` on accept; 4xx with reason on reject.
**Expected audit.** One `attendancePunches` row per attempt (accepted and rejected), with lat/lng/accuracy/mock/distanceMeters/outcome/rejectionReason/deviceInfo.
**Pass criteria.** Every row's outcome matches Expected; 6.8 produces exactly one accepted state (idempotent).
**Fail criteria.** Wrong accept/reject; duplicate marking on retry; missing audit row; app sends punch when GPS/permission unavailable.
**Evidence.** App screenshots; API responses; `attendancePunches` + `staffAttendanceSummary` docs per row.
**Mandatory.** [M].

## Section 7 — Teacher Check-In/Check-Out Scenarios (API-level) **[M]**
**Objective.** Validate server authority independent of the app (direct API), including ISSUE-1.
**Preconditions.** Sections 1–5; ability to mint staff ID tokens; `curl`/Postman.
**Execution steps (per case, `POST {BASE}/staff_attendance/punch` with Bearer token + JSON body `{direction,lat,lng,accuracy,mock,clientPunchId,clientCapturedAt,device}`):**
1. On-time inside → expect 200 `status=P`.
2. Late inside → 200 `status=T`, `lateMinutes>0`.
3. Outside coords → 4xx `outside_geofence`.
4. `mock=true` → 4xx `mock_location`.
5. Replay case 1 body (same `clientPunchId`) → idempotent: same audit doc (no new row), state unchanged.
6. Second check-in different `clientPunchId` after P → `already_checked_in` (allowed, no re-set).
7. **ISSUE-1:** punch as the **Inactive** staff (valid token) → 403 `staff_inactive`, rejected audit row written.
8. `GET {BASE}/staff_attendance/me` → returns today status + month counts + 30-day history.
**Expected R/W.** As Section 6; case 5 overwrites same `attendancePunches` doc (merge), no new state write.
**Expected UI.** N/A (API).
**Expected API.** Status codes + reason codes exactly as listed.
**Expected audit.** Every case writes/overwrites an `attendancePunches` row; case 7 row has `outcome=rejected, rejectionReason=staff_inactive`.
**Pass criteria.** All status/reason codes match; idempotency holds (case 5 = no duplicate); ISSUE-1 returns 403.
**Fail criteria.** Inactive staff accepted (Critical); duplicate state/audit on replay; wrong reason codes.
**Evidence.** `curl -i` transcripts; `attendancePunches` docs (incl. the merged one); `me` response JSON.
**Mandatory.** [M].

## Section 8 — Attendance Register Validation **[M]**
**Objective.** Confirm admin register reflects GPS marks and corrections write canonically.
**Preconditions.** Section 7 produced P/T marks.
**Execution steps.**
1. Admin → `{BASE}/attendance` staff register for the test month; locate the test staff.
2. Confirm `dayWise` chars for the test dates (P/T) match the punches.
3. Perform a correction via `mark_staff_day` (e.g., set a day to A then back) and observe.
4. Open admin **Attendance Punch Log** (`{BASE}/attendance/punch_log`), load the test date.
**Expected R/W.** Register read of `staffAttendanceSummary`; correction = CAS read+write on summary; punch log = `attendancePunches` query by date.
**Expected UI.** Register shows P/T on correct days; punch log shows Staff rows with Outcome / Reason / Accuracy / Distance / Mock / Location (Maps link) / Device (OP-7).
**Expected API.** `mark_staff_day` 200; `fetch_punch_log` returns normalized rows.
**Expected audit.** Correction reflected in summary; punch log renders GPS evidence.
**Pass criteria.** Register matches punches; correction persists; OP-7 evidence columns populated for GPS rows.
**Fail criteria.** Register mismatch; correction lost; punch log blank/mislabels staff rows.
**Evidence.** Register screenshot; punch-log screenshot showing GPS evidence; summary doc.
**Mandatory.** [M].

## Section 9 — Dashboard Validation **[M]**
**Objective.** Confirm the staff-attendance dashboard block reflects canonical data.
**Preconditions.** Section 7/8.
**Execution steps.** Admin → attendance dashboard; review present/absent/late counts for the test day.
**Expected R/W.** Reads from canonical `staffAttendanceSummary` (Phase-8 repoint).
**Expected UI.** Counts match the register/punches for the day.
**Expected API.** Dashboard data endpoint returns canonical counts.
**Expected audit.** None (read-only).
**Pass criteria.** Dashboard counts == register.
**Fail criteria.** Divergence between dashboard and register.
**Evidence.** Dashboard screenshot vs register screenshot.
**Mandatory.** [M].

## Section 10 — Leave Workflow Validation **[M]**
**Objective.** Confirm leave approval writes `L` into the same canonical summary and the engine respects it.
**Preconditions.** Section 3 (HR user); leave types seeded.
**Execution steps.**
1. As staff/HR, `apply_leave` for a future test date.
2. As HR, `decide_leave` → Approve.
3. Confirm `staffAttendanceSummary.dayWise` for that day = `L`.
4. Attempt a GPS punch on that day → expect `on_leave` rejection.
5. (Optional [O]) `cancel_leave` and confirm reversal.
**Expected R/W.** Leave docs + summary `L` write; punch read sees `currentDayMark=L`.
**Expected UI.** Leave list shows Approved; register shows L.
**Expected API.** `apply/decide_leave` success; punch → 4xx `on_leave`.
**Expected audit.** Leave audit entries; punch rejected audit row.
**Pass criteria.** L appears; punch rejected `on_leave`.
**Fail criteria.** L not written; punch overrides leave.
**Evidence.** Leave docs; summary doc; punch rejection response.
**Mandatory.** [M].

## Section 11 — Payroll Validation **[M]**
**Objective.** Confirm payroll derives LOP from `dayWise` (method-agnostic) with holiday continuity.
**Preconditions.** A test month containing GPS P/T + leave L + a holiday H + at least one A.
**Execution steps.**
1. HR → generate payroll for the test month for the test staff.
2. Inspect the resulting `salarySlips` doc and on-screen breakdown.
3. (Optional [O]) `regenerate_staff_payroll` and confirm idempotent result.
4. Lock the payroll month (`lock_payroll_month`); attempt a GPS punch in that month → expect `month_locked`.
**Expected R/W.** Read `staffAttendanceSummary` (month) + holiday via `Holiday_service`; write `salarySlips`; lock write.
**Expected UI.** Slip shows present/absent/leave counts; LOP corresponds to A/L/V days only; T (late) does not deduct.
**Expected API.** Generate 200; locked-month punch 4xx `month_locked`.
**Expected audit.** Payroll generation record; locked-punch rejected audit row.
**Pass criteria.** LOP == count of deduct-eligible days from `dayWise`; H days not counted as working; locked month blocks punches.
**Fail criteria.** LOP mismatch; holiday counted wrong; method/source influences payroll; lock not enforced.
**Evidence.** `salarySlips` doc; summary doc; slip screenshot; locked-punch response.
**Mandatory.** [M].

## Section 12 — Session Rollover Validation **[M]**
**Objective.** Confirm attendance rolls over by date/month and prior-session data stays immutable.
**Preconditions.** Ability to advance `currentSession` via `Session_lifecycle` on the test tenant.
**Execution steps.**
1. Record current `staffAttendanceSummary` doc ids for the test staff (note immutability baseline).
2. Advance the session (rollover) per platform procedure.
3. Punch in the new session → confirm new docs land in the new-session month/date keys.
4. Re-read a prior-session summary → confirm unchanged.
5. Generate payroll for a **prior** month → confirm it still works.
6. Confirm geofence/policy on `schools/{id}` is retained (no reconfig needed).
**Expected R/W.** New `staffAttendance/Summary` docs for new dates; prior docs untouched; policy read unchanged.
**Expected UI.** New-session register starts clean; prior data still viewable where the platform supports prior-session view.
**Expected API.** Punch resolves new `currentSession` automatically.
**Expected audit.** New punches carry new `session` provenance.
**Pass criteria.** New punches → new-session docs; prior summaries byte-unchanged; prior payroll generates; policy retained.
**Fail criteria.** Prior data mutated; punches land in wrong session; policy lost.
**Evidence.** Before/after summary docs; new-session punch doc; prior-month payroll slip.
**Mandatory.** [M].

## Section 13 — Multi-School (Tenant Isolation) Validation **[M]**
**Objective.** Prove a tenant cannot read another tenant's attendance.
**Preconditions.** Create a second test school `SCH_TEST_B` with its own staff/admin (repeat Section 3).
**Execution steps.**
1. Generate attendance data in both A and B.
2. As A's admin token, attempt to read `staffAttendanceSummary`/`attendancePunches` for a B doc (direct SDK/console-rules simulator) → expect **denied**.
3. As A's staff token, attempt to read another A staff's punch → expect denied (own-only).
4. Confirm server queries are `school_id`-scoped (no cross-tenant rows in `me`/register).
**Expected R/W.** Reads denied by rules for cross-tenant; allowed only own/admin-same-school.
**Expected UI.** A's admin never sees B's staff.
**Expected API.** Cross-tenant reads 403/empty.
**Expected audit.** N/A.
**Pass criteria.** All cross-tenant + cross-staff reads denied; same-school admin allowed.
**Fail criteria.** Any cross-tenant read succeeds (Critical).
**Evidence.** Rules-simulator results; denied responses; server query screenshots.
**Mandatory.** [M].

## Section 14 — Disaster Recovery Drills **[M for core, O for exotic]**
**Objective.** Confirm fail-safe behavior + idempotency under induced failure.
**Preconditions.** Staging only; ability to disrupt PHP/Firestore/network.
**Execution matrix:**
| # | Drill | Expected | Mand. |
|---|---|---|---|
| 14.1 | Duplicate/replay punch (same `clientPunchId`) | One accepted state; one audit doc (merged); no dup | [M] |
| 14.2 | Network drop mid-request, retry | KEEP id → idempotent; exactly one accepted | [M] |
| 14.3 | Firestore throttled/denied during gate | 503 `staff_not_found`-class? No — fail-closed 503 "verify status"; no state write | [M] |
| 14.4 | Writer CAS exhausted (force contention) | 500 "Please retry" + `writer_failure` audit; no partial state | [M] |
| 14.5 | Kill PHP after batch commit, before audit | State correct; accepted-audit row may be absent but reconstructable; retry → `already_checked_in` | [O] |
| 14.6 | Token expired | 401; no write | [M] |
| 14.7 | App crash mid-punch, reopen | No double-mark (server `already_marked`/`already_checked_in`); state correct | [M] |
**Expected R/W / audit.** Per row; no partial or duplicate state in any case.
**Pass criteria.** Every drill ends with correct, single, consistent state + appropriate error surfaced.
**Fail criteria.** Double-marking; partial/corrupt state; unhandled 500 without audit.
**Evidence.** Response transcripts; before/after docs; log lines.
**Mandatory.** Core rows [M]; 14.5 [O].

## Section 15 — Performance / Load Validation **[M before high-volume tenants; O for small pilots]**
**Objective.** Validate morning-surge concurrency and index behavior.
**Preconditions.** Load tool (k6/JMeter) able to mint N staff tokens; staging.
**Execution steps.**
1. Simulate N concurrent check-ins (e.g., 100–500) within a 5-minute window for one tenant.
2. Measure p50/p95/p99 latency, error rate, Firestore contention, app-server saturation.
3. Inspect `attendancePunches` write rate vs the composite index; watch for hotspotting near ~500 writes/s/tenant (W2).
4. Observe holiday-cache behavior at cold start (W3) and app-server concurrency (W4).
**Expected R/W.** Per-staff isolated docs (no inter-staff contention); shared `schools/{id}` read scales.
**Expected UI/API.** Stable latency; no elevated 5xx.
**Expected audit.** All punches audited under load.
**Pass criteria.** p95 within target; error rate ~0; no write contention on per-staff docs.
**Fail criteria.** Latency blowout; 5xx spike; index hotspot at target volume.
**Evidence.** Load-tool report; Firebase usage graphs; latency percentiles.
**Mandatory.** [M] before onboarding high-volume tenants; [O] for small pilots.

## Section 16 — Final Production Sign-Off Checklist **[M]**
| Area | Step refs | Verdict (Verified/Failed/Blocked) | Evidence ref |
|---|---|---|---|
| Rules deployed + correct | §1 | | |
| Index Enabled | §2 | | |
| Test tenants prepared | §3, §13 | | |
| Holiday authored (canonical) | §4 | | |
| GPS policy configured | §5 | | |
| Android device matrix | §6 | | |
| API check-in/out + ISSUE-1 | §7 | | |
| Register + OP-7 punch log | §8 | | |
| Dashboard parity | §9 | | |
| Leave → L + on_leave reject | §10 | | |
| Payroll LOP + lock | §11 | | |
| Session rollover immutability | §12 | | |
| Tenant isolation | §13 | | |
| DR drills | §14 | | |
| Load (if high-volume) | §15 | | |

**Sign-off rule.** Production GO requires **all [M] rows = Verified** with attached evidence. Any **Failed** [M] row → **NO GO** until resolved + re-validated. [O] rows may remain open post-GA with a tracked backlog entry.

**Sign-off block.**
- QA Lead: __________________  Date: ________  Verdict: GO / GO WITH CONDITIONS / NO GO
- Release Engineer: __________  Date: ________
- Product Owner: ____________  Date: ________

---
*This runbook modifies no application code. It deploys Rules/Indexes and creates test-tenant data on a staging/approved-isolated environment only. Do not execute destructive steps against live customer tenants.*
