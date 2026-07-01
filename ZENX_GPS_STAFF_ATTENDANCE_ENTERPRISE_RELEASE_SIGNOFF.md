# ZenX ERP — GPS Staff Attendance
## Enterprise Production Readiness Package & Release Sign-Off

**Module:** GPS-Based Staff (Teacher) Self-Attendance + Holiday Architecture Convergence
**Surfaces:** Web Admin (CodeIgniter 3 / PHP) · Teacher App (Android / Kotlin) · Firestore (single source of truth)
**Branch:** `ank-yug_b1` (uncommitted working tree — nothing committed/pushed/deployed)
**Evidence basis:** ISSUE-1 remediation · GPS implementation · Holiday convergence HC-1…HC-5 · Teacher App · Enterprise Validation · Operational Areas 1–7 · OP-7

**Evidence legend (used throughout):**
- **Verified** — confirmed by static means in this environment (`php -l`, unit/CLI harnesses, code reads, grep) at the code level.
- **Requires Live Validation** — correct by code analysis but NOT executed at runtime here (browser, device, live Firestore, load, induced failure). Not fabricated.
- **Operational prerequisite** — a deployment/configuration step required before production use.
- **Post-GA enhancement** — non-blocking robustness/efficiency improvement, deferred.

---

## 1. Executive Summary

**Scope.** A new GPS staff self-attendance capability delivered as an extensible Attendance Policy Framework (GPS = first method), integrated with Attendance, HR, Leave, Payroll, Dashboard and a converged Holiday architecture, across web admin + Teacher app, Firestore-only.

**Evidence.** Seven structured operational audits (Areas 1–7), an evidence-based security remediation (ISSUE-1), a five-step holiday convergence (HC-1…HC-5), and one closed GA functional gap (OP-7). All code-level checks pass; no open Critical/High defect remains.

**Verified components.** Pure policy engine, CAS-protected writer, canonical Holiday service/reader + single Calendar writer, active-staff authorization gate, idempotent punch path, GPS-aware audit viewer, Teacher app location→repository→VM→UI stack with end-to-end idempotency.

**Remaining risks.** All residual items are **Requires Live Validation** (runtime/load/device/rollover) or **Post-GA enhancements** (DR-1, DR-2, OP-6, W2/W3/W4). No correctness defect is open.

**Requires Live Validation.** Morning-surge load test, induced-failure DR drills, on-device GPS punch, real session rollover, browser render of the OP-7 punch log.

**Deferred items.** DR-1 (atomic audit write), DR-2 (persisted client punch id), OP-6 (Holiday_service session-scoping), W2/W3/W4 (spike scaling), Area 1/2 non-GA operational items, pre-existing student punch-log RTDB fallback, platform-wide previous-session viewer.

**Recommendation:** **GO WITH CONDITIONS** — ship after the enumerated Live-Validation gate and operational prerequisites are completed at cutover. No open GA defect blocks release.

---

## 2. Enterprise Production Readiness Report

**Scope.** Whole-module production fitness across functionality, architecture, data, security, operations and recovery.

**Evidence.**
- Areas 1–6 concluded **Operationally Complete — No GA Functional Gap**; Area 7 found one gap (OP-7), now **closed and verified**.
- ISSUE-1 (the sole Category-A blocker) remediated via the Option-B active-staff gate.
- Holiday divergence (M-1) resolved by HC-1…HC-5 convergence to a single canonical reader/writer.

**Verified components.** Punch orchestration, policy engine, writer atomicity, holiday convergence, audit trail, admin viewer, Teacher app flow — all Verified at code level.

**Remaining risks.** Runtime behaviour unproven in this environment (see §13). No code-level defect open.

**Requires Live Validation.** End-to-end production smoke across a full school day on the live stack.

**Deferred items.** All post-GA enhancements in §15.

**Recommendation:** **GO WITH CONDITIONS.**

---

## 3. Architecture Readiness Report

**Scope.** Layering, separation of concerns, service boundaries, extensibility.

**Evidence.**
- **Pure engine:** `Attendance_policy::evaluate(policy, request, context)` — no I/O, deterministic; the server is the sole accept/Present/Late authority.
- **Orchestration-only controller:** `Staff_attendance::punch()/me()` — parses, gates, loads config, delegates to engine + writer; holds no business rules.
- **Atomic writer:** `Staff_attendance_writer::markSingleDay()` — CAS-protected single-day + summary batch; the only attendance writer in the GPS path.
- **Canonical holiday split:** `Calendar_service` (sole writer) → `calendarEvents` → `Holiday_service` (sole reader) → all consumers. Single editor surface (Academic Calendar); Attendance Settings is read-only.
- **Extensibility:** GPS is method #1 behind the policy framework; future methods (QR, biometric, kiosk) plug into the same engine/writer without controller rewrites.

**Verified components.** Boundary integrity confirmed: OP-7 changed only presentation; engine/writer/holiday/calendar untouched (grep-verified, 0 markers outside the punch-log path).

**Remaining risks.** None at architecture level.

**Requires Live Validation.** None (architecture is static).

**Deferred items.** OP-6 (reader not session-scoped — efficiency only).

**Recommendation:** **GO.**

---

## 4. Firestore Readiness Report

**Scope.** Data model, single-source-of-truth compliance, indexes, security rules, read/write profile.

**Evidence.**
- **Collections:** `staffAttendance` (`{schoolId}_{date}_{staffId}`), `staffAttendanceSummary` (`{schoolId}_{staffId}_{monthKey}`, `dayWise`), `attendancePunches` (audit), `attendancePolicy` (map on `schools/{id}`), `staffAttendanceLocks`, `calendarEvents` (holiday).
- **Zero-RTDB (GPS path):** punch/me/writer/policy/holiday paths contain no `firebase->get/set/update/delete` on RTDB; OP-7 added none (pure reshape). Verified.
- **Keying:** date/month keys → natural session rollover, prior data immutable (Area 4 Verified).
- **Rules (`firestore.rules`):** `staffAttendance`/`staffAttendanceSummary`/`attendancePunches` → read own (`resource.data.staffId == request.auth.uid`) or admin same-school; **`write:false`** (server-only writes).
- **Indexes (`firestore.indexes.json`):** `attendancePunches (schoolId, staffId, date)` added.
- **Read/write profile (per check-in):** ~4R (active-staff gate · memoized schools · summary peek · writer CAS; holiday cached) + 3W (att+summary batch · audit). `me()`: ~3R / 0W. OP-7 viewer: reads only.

**Verified components.** Model, keying, rules logic, index definition, zero-RTDB — all Verified at code level.

**Remaining risks.** Rules + indexes are defined but **not deployed** in this environment.

**Requires Live Validation.** Deploy rules/indexes and confirm enforcement + query performance live.

**Deferred items.** Pre-existing RTDB *fallback* in `fetch_punch_log` (legacy student log; never triggers for GPS) — leave as-is, out of GPS scope.

**Recommendation:** **GO WITH CONDITIONS** (deploy rules + indexes at cutover).

---

## 5. Security Readiness Report

**Scope.** AuthN/AuthZ, tenant isolation, anti-spoofing data, server authority, audit.

**Evidence.**
- **AuthN:** Firebase ID token (Bearer) via `Api_auth → verifyFirebaseToken`; custom claims `uid/role/school_id`; convention `uid == staffId`.
- **AuthZ:** role gates (`require_role`) on punch/me/viewer; tenant scoping via `schoolWhere`/`school_id` on every read.
- **ISSUE-1 gate:** `_assert_staff_active()` performs an authoritative `staff/{schoolId}_{staffId}` read before engine/writer; rejects inactive/missing staff (403, audited) even with a still-valid token; **fail-closed 503** on read error. Verified.
- **Server authority:** all accept/Present/Late/distance decisions are server-side; the app sends only raw GPS + never decides; geofence is read server-side (client geofence is guidance-only, never trusted).
- **Anti-spoofing evidence captured + now visible (OP-7):** `mock`, `accuracy`, `distanceMeters`, `lat/lng`, `deviceInfo`, `outcome`, `rejectionReason`.
- **Rules:** client `write:false`; reads least-privilege (own or admin same-school).

**Verified components.** Token verification path, role gates, tenant scoping, active-staff gate, server-only authority, audit completeness — Verified at code level.

**Remaining risks.** `checkRevoked=false` (default) means a revoked-but-unexpired token is accepted until expiry; the ISSUE-1 status gate mitigates the staff-deactivation case (the primary operational risk). Residual = generic token-revocation latency (platform-wide, not GPS-specific).

**Requires Live Validation.** Live auth tests: expired token → 401; deactivated staff → 403; cross-tenant read denied by rules.

**Deferred items.** Optional `checkRevoked=true` hardening (platform-wide; cost/latency trade-off) — Post-GA.

**Recommendation:** **GO WITH CONDITIONS** (run the live auth/isolation tests at cutover).

---

## 6. Operational Readiness Report

**Scope.** Day-to-day operability for Principal, HR, Admin, Attendance Operator, Payroll Officer, Reception, Support (Areas 1–7).

**Evidence.**
- Area 1–3: configuration, multi-role access, tenant isolation — Operationally Complete (non-GA items OP-2/OP-3 declined by operator).
- Area 4: session transition — Operationally Complete (date/month keying; policy/geofence persist).
- Area 5: full school-day simulation — Operationally Complete (per-staff isolation; canonical cross-module consistency).
- Area 6: disaster recovery — Operationally Complete (idempotency + fail-safe).
- Area 7: admin UX — OP-7 gap closed (GPS evidence now in ERP); corrections, history, config visibility = existing capabilities.

**Verified components.** Correction path (`mark_staff_day` CAS + register), history (`me()` + dashboard), config visibility (GPS Settings, read-only Holiday Status), locks (attendance/payroll), GPS evidence viewer (OP-7) — Verified at code level.

**Remaining risks.** Operator must configure geofence/policy and author holidays (prerequisites, not defects).

**Requires Live Validation.** End-to-end operator walkthrough on the live stack.

**Deferred items.** OP-2, OP-3 (Area 1/2 non-GA operational refinements); platform previous-session viewer.

**Recommendation:** **GO WITH CONDITIONS** (complete operational prerequisites in §17).

---

## 7. School Operations Readiness Report

**Scope.** A complete production school day (arrivals → surge → reviews → corrections → check-out → end-of-day).

**Evidence (Area 5, Verified at code level).**
- Concurrent check-ins write **distinct per-staff docs** — no inter-staff contention; **no shared/global write doc** (0 counters/push/increment in the punch path).
- CAS self-serializes only a same-staff/same-month double-tap.
- Punch → `dayWise` → Register + Dashboard (canonical pair) + Report + Payroll: immediate, consistent.
- Mid-day leave approval + correction write the same canonical `dayWise`.
- End-of-day: every staff carries a definitive `P/T/A/L/H`; payroll-previewable; full audit trail.

**Verified components.** Per-staff isolation, canonical consistency, mid-day operations — Verified.

**Remaining risks.** Surge scaling (W2/W3/W4) for very high-volume tenants — Post-GA.

**Requires Live Validation.** Morning-surge concurrency/latency load test.

**Deferred items.** W2 (`attendancePunches` index hotspot >~500 w/s/tenant), W3 (holiday-cache cold herd), W4 (app-server concurrency).

**Recommendation:** **GO WITH CONDITIONS** (load test before high-volume tenants).

---

## 8. HR Readiness Report

**Scope.** HR review, dispute investigation, audit access, leave handling.

**Evidence.**
- **Dispute investigation now ERP-native (OP-7):** Outcome, Reason, Accuracy, Distance, Mock, submitted coordinates (Maps link), device, staff identity, server time — all rendered in the Attendance Punch Log without Firestore console access.
- **Audit completeness:** every attempt (accepted AND rejected, incl. ISSUE-1 rejections) is recorded in `attendancePunches` with 14+ self-describing fields.
- **Leave lifecycle:** `apply_leave`/`decide_leave` (HR roles, Processing-lock race guard)/`cancel_leave`; "On Duty Leave", COMP_OFF, ACADEMIC types seeded; leave writes `L` into the same canonical summary GPS uses (`school_name === school_id` confirmed).

**Verified components.** Punch viewer field mapping, audit record schema, leave-lifecycle controllers — Verified at code level.

**Remaining risks.** None at code level.

**Requires Live Validation.** HR user loads a date and resolves a sample dispute end-to-end in the browser.

**Deferred items.** None HR-specific.

**Recommendation:** **GO** (subject to the global live-validation gate).

---

## 9. Payroll Readiness Report

**Scope.** Payroll continuity, LOP derivation, method-agnosticism, locks.

**Evidence.**
- **Method-agnostic:** `Hr::generate_payroll` / `regenerate_staff_payroll` branch only on `dayWise` chars (A/L/V); never on attendance source/method. GPS-marked `P/T` is payroll-inert beyond presence; `T` (late) does not deduct.
- **Holiday continuity:** both payroll holiday blocks use `Holiday_service::holidays_in_month` (HC-2) — single canonical source; date-matched (cross-session safe).
- **Locks:** `lock_payroll_month` / `_check_payroll_lock` / `unlock_payroll`; writer raises `MonthLockedException` → punch rejected with `month_locked`.
- **Explainability:** salary slip + summary counts (present/absent/leave) + the OP-7 punch log together explain any deduction from the ERP.

**Verified components.** LOP branching, holiday source unification, lock enforcement — Verified at code level. **Payroll logic unchanged by this release.**

**Remaining risks.** None at code level.

**Requires Live Validation.** Generate a payroll month containing GPS-sourced attendance + a mid-month deactivation; confirm LOP matches `dayWise`.

**Deferred items.** None.

**Recommendation:** **GO** (subject to the global live-validation gate).

---

## 10. Teacher App Readiness Report

**Scope.** Android client: location capture, networking, idempotency, UX, server-authority compliance.

**Evidence.**
- **Layering:** `LocationProvider` (FusedLocation) → `StaffAttendanceRepository` (typed `StaffAttendanceError`) → `StaffAttendanceViewModel` → `MyAttendanceScreen` (Compose).
- **Server authority:** VM sends raw fix only; never decides Present/Late/accept; geofence loaded for on-screen **guidance only**, never sent.
- **W6 end-to-end idempotency:** one `clientPunchId` per logical attempt, reused across retries of the same direction on transient (Network/Timeout) failure; cleared on success/terminal rejection.
- **Device evidence:** `deviceInfo` (model, manufacturer, os, osVersion, sdkInt, appVersion) sent for audit.
- **Config:** `BASE_URL → /ZenX/school/`; `play-services-location:21.3.0`; FINE/COARSE location permissions in manifest.

**Verified components.** Code structure, idempotency state machine, server-authority discipline — Verified by code read.

**Remaining risks.** DR-2 (in-memory `pendingPunchId` lost on crash; server `already_marked` still prevents double-marking — no functional impact).

**Requires Live Validation.** On-device GPS punch (inside/outside geofence, mock-location, permission revoked, airplane-mode retry), token refresh, real backend round-trip.

**Deferred items.** DR-2 (persist idempotency id to DataStore).

**Recommendation:** **GO WITH CONDITIONS** (on-device validation required before store release).

---

## 11. Performance & Scalability Report

**Scope.** Concurrency, hotspots, read/write efficiency, surge behaviour.

**Evidence.**
- **No shared-write contention:** per-staff/per-punch docs; CAS scope = single staff/month; shared `schools/{id}` is a memoized **read** (reads scale).
- **Caching:** holiday two-tier cache (memo + file TTL 300s); `_school_doc()` memoized (W8 — single schools read per request).
- **Profile:** check-in ~4R/3W; `me()` ~3R/0W; viewer reads-only.

**Verified components.** Isolation + caching design — Verified at code level.

**Remaining risks (Post-GA, high-volume only).** W2 (`attendancePunches` index hotspot >~500 w/s/tenant), W3 (holiday-cache cold herd at surge), W4 (PHP/Apache app-server concurrency).

**Requires Live Validation.** Load test at target peak concurrency; measure p95 latency, contention, index behaviour.

**Deferred items.** W2/W3/W4 mitigations (sharded index, cache pre-warm, FPM/Nginx scaling).

**Recommendation:** **GO WITH CONDITIONS** (load test before onboarding high-volume tenants).

---

## 12. Deployment Readiness Report

**Scope.** Artifacts and steps required to ship.

**Evidence / artifacts.**
- Backend: new `Staff_attendance` controller + `Attendance_policy`/`Holiday_service`/`Staff_attendance_writer` libraries + helpers; modified `Attendance`/`Hr`/`Exam`/`Health_check`; routes added.
- Rules/indexes: `firestore.rules` + `firestore.indexes.json` updated (not deployed).
- Teacher app: new myattendance stack; `build.gradle.kts` + manifest updated.
- Temporary tooling: `Holiday_legacy_inventory` (read-only), runtime harness, implementation summary — to be retained until post-GA cleanup approval.

**Verified components.** All artifacts present; `php -l` clean on touched controllers/views.

**Remaining risks.** Deployment not yet performed; rules/indexes/app build pending.

**Requires Live Validation.** Staged deploy + smoke on the live stack.

**Deferred items.** Removal of temporary validation tools (only after explicit post-GA approval).

**Recommendation:** **GO WITH CONDITIONS** (execute the §17 checklist).

---

## 13. Production Validation Summary

**Scope.** What has been proven vs what remains.

**Verified (static/code-level).** `php -l` clean (controller + view); zero RTDB in GPS path + OP-7; CAS + idempotency code paths; engine purity; holiday convergence; boundary confinement (OP-7 = 2 files, 0 markers elsewhere); rules/index definitions; Teacher app structure.

**Requires Live Validation (NOT executed here — not fabricated).**
1. Morning-surge load/concurrency test.
2. Induced-failure DR drills (kill PHP mid-batch, throttle Firestore, expire token, crash app mid-punch).
3. On-device GPS punch matrix (inside/outside, mock, poor accuracy, permission revoked, offline retry).
4. Real session rollover via `Session_lifecycle`.
5. OP-7 browser render with real GPS punches.
6. Rules/index enforcement + query performance live.
7. Auth/isolation tests (401/403/cross-tenant deny).
8. Payroll month over GPS-sourced attendance + mid-month deactivation.

**Operational prerequisites.** Geofence/policy config per tenant; holidays authored per session; rules/indexes deployed; Teacher app built with correct BASE_URL/permissions.

**Recommendation:** Treat the eight Live-Validation items as the release gate.

---

## 14. Technical Debt Register

| ID | Item | Severity | Status |
|---|---|---|---|
| TD-1 | `fetch_punch_log` retains a legacy **RTDB fallback** + `punch_time` sort key (student log); never triggers for GPS | Low | Pre-existing; out of GPS scope; flagged |
| TD-2 | `Api_auth` `checkRevoked=false` default (token-revocation latency) | Low | Platform-wide; ISSUE-1 gate mitigates staff-deactivation case |
| TD-3 | Best-effort (non-transactional) audit write relative to the status batch | Low | By design; see DR-1 |
| TD-4 | `Holiday_service` reader not session-scoped (`schoolWhere`) | Low | Functionally harmless (date-matched); see OP-6 |
| TD-5 | Stale/legacy holiday stores S1–S5 still present (read-only inventory exists) | Low | No-delete per instruction; inventory tool retained |

---

## 15. Post-GA Improvement Backlog

| ID | Enhancement | Rationale | Priority |
|---|---|---|---|
| DR-1 | Fold `attendancePunches` into the status `firestoreCommitBatch` (atomic accepted-event) | Closes the microsecond audit-gap on crash | Medium |
| DR-2 | Persist `pendingPunchId` to DataStore | Cross-crash idempotency (server already prevents double-marking) | Low |
| OP-6 | Session-scope or TTL the holiday reader | Bounded growth over many years | Low |
| W2 | Shard/scale `attendancePunches` index | >~500 writes/s/tenant | Conditional |
| W3 | Pre-warm holiday cache | Avoid cold-herd at surge | Low |
| W4 | App-server scaling (FPM/Nginx) | Surge throughput | Conditional |
| OP-2/OP-3 | Area 1/2 operational refinements (operator-declined for GA) | Convenience | Low |
| SEC-1 | Optional `checkRevoked=true` | Tighter revocation | Low |

---

## 16. Risk Register

| Risk | Likelihood | Impact | Mitigation | Residual |
|---|---|---|---|---|
| Runtime behaviour differs from static analysis | Medium | High | Execute §13 Live-Validation gate before GA | Controlled |
| Rules/indexes not deployed → exposure or slow queries | Medium | High | §17 deploy step is mandatory | Controlled |
| Tenant misconfiguration (no geofence/policy) | Medium | Medium | Operational prerequisite + Settings visibility | Low |
| Surge overload at high-volume tenant | Low | Medium | Load test (W2/W3/W4) before onboarding such tenants | Low |
| Revoked-token latency | Low | Low | ISSUE-1 status gate; optional SEC-1 | Low |
| Legacy RTDB fallback path (student log) | Low | Low | Never triggers for GPS; TD-1 tracked | Low |
| Audit gap on mid-batch crash | Very Low | Low | Reconstructable; DR-1 backlog | Negligible |

No Critical or High **defect** is open. High-impact risks are validation/deployment gates, not code defects.

---

## 17. Production Release Checklist

**Pre-deploy (Operational prerequisites)**
- [ ] Deploy `firestore.rules` and `firestore.indexes.json`; confirm index build complete.
- [ ] Configure `attendancePolicy` (timezone, windows, shift) and GPS geofence (centerLat/Lng/radius, active=true) per tenant.
- [ ] Author current-session holidays in the Academic Calendar per tenant.
- [ ] Build/sign Teacher app with correct `BASE_URL` + location permissions.

**Live-Validation gate (must pass)**
- [ ] On-device GPS punch matrix (inside/outside/mock/poor-accuracy/permission/offline-retry).
- [ ] Auth/isolation: expired→401, deactivated→403, cross-tenant read denied.
- [ ] OP-7 punch log renders real GPS evidence (outcome/reason/accuracy/distance/mock/coords) for HR.
- [ ] Session rollover: new punches land in new-session months; prior summaries immutable.
- [ ] Payroll month over GPS attendance + mid-month deactivation → LOP matches `dayWise`.
- [ ] DR drills (PHP kill mid-batch, Firestore throttle, token expiry, app crash mid-punch).
- [ ] Morning-surge load test at target peak.

**Post-deploy**
- [ ] Monitor `security_events` / logs for gate rejections + writer failures.
- [ ] Defer temporary-tool removal until explicit post-GA approval.

---

## 18. Release Sign-Off Report

**Scope.** Final consolidated sign-off for ZenX ERP GPS Staff Attendance.

**Evidence basis (objective, accumulated).** ISSUE-1 remediation (active-staff gate) · GPS implementation (engine/writer/controller/app) · Holiday convergence HC-1…HC-5 · Teacher app · Enterprise validation · Operational Areas 1–7 (all No-GA-Gap, OP-7 closed) · OP-7 (GPS evidence viewer).

**Verified.** Code-level correctness, Firestore-only compliance, boundary integrity, idempotency, security gate, audit completeness, payroll method-agnosticism, holiday single-source.

**Requires Live Validation.** The eight §13 items (load, DR, device, rollover, browser, rules/index, auth, payroll).

**Operational prerequisites.** Rules/indexes deploy + per-tenant geofence/policy/holidays + app build.

**Post-GA enhancements.** DR-1, DR-2, OP-6, W2/W3/W4, OP-2/OP-3, SEC-1.

**Open defects.** None (no Critical/High).

---

## OVERALL RELEASE RECOMMENDATION

# ✅ GO WITH CONDITIONS

**Justification (objective).** Across ISSUE-1, the GPS implementation, HC-1…HC-5, the Teacher app, enterprise validation, Operational Areas 1–7, and OP-7, **every identified GA functional gap is closed** and **no Critical/High defect is open**. The architecture is clean (pure engine, atomic writer, single holiday source), Firestore is the only source of truth (zero RTDB in the GPS path), security authority is server-side with an authoritative active-staff gate, payroll/leave logic is unchanged, and the audit trail is both complete and now ERP-visible (OP-7).

The release is **not unconditional GO** only because the runtime/load/device/rollover validations and the deployment/configuration prerequisites have **not been executed in this static environment** — these are gates, not defects. It is **not NO GO** because no open correctness defect exists.

**Conditions of release:** complete the §17 checklist — deploy rules/indexes, apply per-tenant configuration, and pass the eight-item Live-Validation gate (§13). On satisfaction of these conditions, the module is cleared for production.

*Nothing in this package was committed, pushed, deployed, or deleted. No source code was modified to produce it.*
