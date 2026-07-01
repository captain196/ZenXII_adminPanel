# RTDB Removal Readiness Report — Student Attendance (Phase 1)
## Validation-only. No RTDB code removed. Removal is a SEPARATE approved phase.

**Scope:** the STUDENT-attendance RTDB dayWise mirror + read fallbacks. Device-punch/API-key/punch-log/config/audit RTDB inside `api_punch` and elsewhere belong to LATER phases (S2 Devices, S6 Settings, S8 Audit) and are **explicitly excluded** here.

**Executed in this environment:** static code-path re-audit; `coverage` CLI probe (8/8 consistent); `php -l` (backend) + Parent-app compile. **NOT executed (Requires Live Validation — §8):** all browser/app/report-card runtime workflows.

---

## 1. Remaining RTDB attendance WRITE locations (all mirrors)

| # | Function | Line | Write | Canonical Firestore already written |
|---|---|---|---|---|
| W1 | `save_student_attendance` | 1189, 1194 | dayWise + late | `attendanceSummary` (fs->set @1160) |
| W2 | `mark_student_day` | 1348, 1355, 1357 | dayWise + late set/del | `attendance` (_syncDailyToFirestore) + `attendanceSummary` (_applyDayToSummary, P1) |
| W3 | `bulk_mark_student` | 1448 | dayWise | `attendance` + `attendanceSummary` (P1) |
| W4 | `api_mark_attendance` | 3965, 3973 | dayWise + late | `attendance` + `attendanceSummary` (P1) |
| W5 | `api_punch` (student part only) | 2999 | dayWise | `attendance` + `attendanceSummary` (via _syncDailyToFirestore @2991 + P1) |
| W6 | `_stamp_leave_on_attendance` | 4541 | dayWise | `attendanceSummary` (_syncStudentSummaryToFirestore @4527) |
| W7 | `approve_attendance_request` (student branch) | 5481 | dayWise | `attendanceSummary` (_syncStudentSummaryToFirestore @5463) |
| W8 | `update_student_att_summary($firebase,…)` (helper call) | ~5474 | RTDB summary node | `attendanceSummary` (canonical) |

**Excluded (later phases, NOT Phase-1):** `api_punch` punch-log/device/event/system-log writes (2908,2952,2962,3010,3065,3093) → S2/S6/S8; `approve` staff branch (5571) → staff; audit/metric writes → S8.

---

## 2. Remaining RTDB attendance READ locations

| # | Function | Line | Read | Type |
|---|---|---|---|---|
| R1 | `mark_student_day` | 1321 | dayWise (seeds the RTDB mirror string) | **mirror-support** (removed with W2) |
| R2 | `bulk_mark_student` | 1444 | dayWise (seeds mirror) | mirror-support (with W3) |
| R3 | `api_mark_attendance` | 3961 | dayWise (seeds mirror) | mirror-support (with W4) |
| R4 | `api_punch` (student part) | 2987 | dayWise (seeds mirror) | mirror-support (with W5) |
| R5 | `get_student_summary` | 1511 | dayWise | **Firestore-first FALLBACK** (inert) |
| R6 | `fetch_student_attendance` | 912, 925 | roster/late | **Firestore-first FALLBACK** (inert) |
| R7 | `api_get_attendance` | 3786 | roster | **Firestore-first FALLBACK** (inert) |
| R8 | `_fire_student_att_events` | 6025 | dayWise | **Firestore-first FALLBACK** (inert) |

**Excluded (later phases):** `api_punch` parent-Name/device/config/punch-log reads (2799,2828,2893,2913,2967) → S2/S6; `approve` request-node + staff reads (5387,5551) → corrections(S4)/staff.

---

## 3. Confirmation: every remaining Phase-1 RTDB reference is mirror or fallback
- **Writes W1–W8:** each occurs **after** the canonical Firestore write (per §1 right column). They are best-effort **mirrors** — removing them changes no canonical data.
- **Reads R1–R4:** exist **only** to seed the RTDB mirror string; they die with their mirror write.
- **Reads R5–R8:** are **Firestore-first fallbacks** (Firestore read attempted first; RTDB read only if Firestore empty). With P1 convergence guaranteeing `attendanceSummary` completeness (and P5 coverage = 8/8), the fallback branch is **inert**.
- **No RTDB-primary student-attendance read/write remains.** ✅

---

## 4. Exact files that change during RTDB removal (Phase-1 removal step)
1. `application/controllers/Attendance.php` — remove W1–W8 mirror writes + R1–R8 reads (student-attendance blocks only; leave api_punch device/punch-log/config/audit lines for S2/S6/S8).
2. `application/helpers/attendance_helper.php` — remove the legacy RTDB `get_student_attendance_percent()` + `_att_policy_include_leave()` (superseded by `_fs` variant) **only after S6 handles the rules config**; and remove RTDB paths in `update_student_att_summary()` / `check_attendance_complete()` if student-scoped.
3. *(App, already shipped in P3):* `SchoolSyncParent/.../StudentRepository.kt` — no further change (RTDB read already removed + built).

**No other files.** Report cards (`Result.php`) already repointed (P2); Teacher app already clean; analytics/dashboard/Sis already Firestore.

---

## 5. Risk assessment
| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| A hidden reader still relies on RTDB dayWise | Low | High | §2 audit shows only inert fallbacks; §8 live gate must pass first |
| `attendanceSummary` incomplete for an untested school → fallback removal exposes gap | Low-Med | High | Run `coverage` per active school before removal (P5); backfill if any gap |
| Parent app build not yet on devices when mirror removed | **Med** | High | **Gate removal on the Parent app release** (P3 field gate) |
| Bulk-mark latency (P1 adds summary RMW per student) | Med | Low | Measure under load (§8); batch later if needed |
| api_punch student-write removal touches device path by mistake | Low | Med | Remove only the student dayWise lines (2987/2999); leave device/punch-log/audit |
| Concurrent mark race on summary | Low | Med | Existing lock/CAS pattern retained |

---

## 6. Rollback plan
- Removal is **one isolated commit** per file group; **`git revert`** restores the mirror writes/fallbacks instantly.
- **No RTDB data is deleted** — only code that writes/reads it — so reverting resumes mirroring on the next mark; historical RTDB data stays intact as a frozen backup.
- **Optional feature flag:** guard the removals behind `student_attendance_rtdb_mirror=off` for instant runtime rollback without redeploy.
- Parent app: revert is a prior APK; keep the previous build available.

---

## 7. Expected impact
- **Functional:** none — all consumers already read Firestore (§2); mirrors/fallbacks are inert.
- **Data:** RTDB attendance nodes stop receiving new writes (become frozen). Firestore `attendance` + `attendanceSummary` remain the sole live stores.
- **Performance:** slightly fewer writes per mark (RTDB mirror gone); reads unchanged (already Firestore-first).
- **Cross-module:** Teacher/Parent/Analytics/Dashboard/Reports unaffected (already Firestore).
- **Payroll:** zero impact (staff-only).

---

## 8. Firestore-only verification checklist — **LIVE GATE (QA must run on deployed env before removal)**
Mark each **Pass/Fail** with evidence. Removal proceeds only if ALL pass.

**Admin Panel**
- [ ] Single-day mark → `attendance` doc + `attendanceSummary.dayWise` both updated
- [ ] Bulk mark → all students' `attendanceSummary` updated
- [ ] API mark → same
- [ ] Leave marking → `L` in `attendanceSummary`
- [ ] Correction submit + approval → `attendanceSummary` updated
- [ ] Dashboard counts, Analytics, Individual Report, Attendance Reports reflect a fresh mark

**Teacher App (on-device)**
- [ ] Mark attendance (→ `/attendance/save`) · View · History · Lock state · Corrections

**Parent App (on-device, NEW build installed)**
- [ ] Dashboard attendance · Calendar · Percentage · Previous month · Today's status (all from Firestore)

**Report Cards**
- [ ] Attendance % · Include-Leave calc · Previous-session report · Multiple students · Multiple months

**Cross-system propagation (single mark → everywhere)**
- [ ] One mark propagates to: `attendance` ✔ `attendanceSummary` ✔ Teacher App ✔ Parent App ✔ Dashboard ✔ Analytics ✔ Reports ✔ Report Card ✔

**Pre-removal data gate**
- [ ] `coverage` probe run per active school → 0 gaps (or backfill executed on approval)
- [ ] Parent app new build shipped to devices

---

*Validation-only. No RTDB attendance code removed. Static + CLI checks passed; the live UI/app/report checklist (§8) is the QA gate. Stopping for your approval before the removal phase.*
