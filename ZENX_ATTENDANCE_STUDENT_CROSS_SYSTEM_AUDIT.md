# Student Attendance — Complete Cross-System Dependency Audit
## Admin · Teacher App · Parent App · Shared Libraries · Backend Endpoints
### READ-ONLY validation. No code modified. For go/no-go on Phase 1 RTDB removal.

**Canonical Firestore stores in play:**
- `attendance` — **per-day** doc `{schoolId}_{date}_{studentId}` (status, late, markedBy). Mobile real-time + dashboard_stats.
- `attendanceSummary` — **per-month** doc `{schoolId}_{studentId}_{YYYY-MM}` (`dayWise` string + counts + `lateTimes`). Apps, analytics, reports.
- RTDB legacy: `Schools/{school}/{session}/…/Students/{id}/Attendance/{Month Year}` (dayWise) + `…/Attendance/Late/…`.

---

## 1. Consumer-by-consumer matrix

| # | Component | Reads | Writes | Firestore | RTDB | Backend API | Classification | Risk if RTDB mirror removed |
|---|---|---|---|---|---|---|---|---|
| **A. ADMIN PANEL (Attendance.php)** |
| A1 | `save_student_attendance` (bulk save) | FS summary | `attendanceSummary` + RTDB mirror | ✓ | mirror (write) | — | DUAL | none (FS canonical) |
| A2 | `mark_student_day` (single) | RTDB (old-mark audit @1293) | `attendance` (via `_syncDailyToFirestore`) + RTDB mirror | ✓ | read+mirror | — | DUAL — **but does NOT update `attendanceSummary`** | see §3 gap |
| A3 | `bulk_mark_student` | — | `attendance` (`_syncBulkDailyToFirestore`) + RTDB mirror | ✓ | mirror | — | DUAL — no `attendanceSummary` | see §3 gap |
| A4 | `fetch_student_attendance` / `get_student_summary` | FS `attendanceSummary` first, RTDB fallback | — | ✓ | fallback | — | Firestore-first | low (fallback) |
| A5 | Analytics (`fetch_analytics`, individual, trend) | FS `attendanceSummary` | — | ✓ | — | — | **FS-only** ✓ | none |
| A6 | `dashboard_stats` | FS `attendance` | — | ✓ | — | — | **FS-only** ✓ | none |
| A7 | Promotion/cascade (`Sis.php`) | FS `attendanceSummary` | FS | ✓ | — | — | **FS-only** ✓ | none |
| A8 | Notifications (`_fire_student_att_events`) | FS `attendanceSummary` first, RTDB fallback | `pushRequests` | ✓ | fallback | — | Firestore-first | low (fallback) |
| A9 | **Report cards (`Result.php` → `get_student_attendance_percent`)** | **RTDB** `{studentBase}/AttendanceSummary/{key}` + `/Attendance/{key}` ([attendance_helper.php:214,218](application/helpers/attendance_helper.php#L214)) | — | ✗ | **READ (only source)** | — | **RTDB-ONLY** ❌ | **BREAKS report-card attendance %** |
| **B. TEACHER APP** (`D:\Projects\SchoolSyncTeacher`) |
| B1 | Attendance read/history | FS `attendanceSummary` (`getStudentAttendanceSummary`) | — | ✓ | — | — | **FS-only** ✓ | none |
| B2 | Mark attendance | — | via backend `POST /attendance/save` | (server) | — | ✓ | API → server dual-write | none (server-side) |
| B3 | Lock / corrections | — | backend `/attendance/lock`, `/correction/submit` | — | — | ✓ | API | none |
| B4 | RTDB attendance | **NONE** | — | — | **✗ none** | — | **no RTDB** ✓ | none |
| **C. PARENT APP** (`D:\Projects\SchoolSyncParent`) |
| C1 | AttendanceScreen (calendar/stats/streak/7-day) | FS `attendanceSummary` | — | ✓ | — | — | **FS-only** ✓ | none |
| C2 | Dashboard tile / Profile % | FS `attendanceSummary` | — | ✓ | — | — | **FS-only** ✓ | none |
| C3 | Today real-time | FS `attendance` (observeQuery) | — | ✓ | — | — | **FS-only** ✓ | none |
| C4 | **`StudentRepository.getDashboardSummary()`** | **RTDB** `Schools/.../Students/{id}/Attendance/{month}` ([StudentRepository.kt:122-159]) | — | ✗ | **READ (fallback)** | — | **RTDB fallback (still called)** ❌ | **stale/empty dashboard summary when hit** |
| **D. BACKEND APP-FACING ENDPOINTS** |
| D1 | `api_get_attendance` | FS `attendanceSummary` first, RTDB fallback @3782 | — | ✓ | fallback | route | Firestore-first | low |
| D2 | `api_mark_attendance` | RTDB seed | `attendance` (`_syncDailyToFirestore`) + RTDB mirror | ✓ | mirror | route | DUAL | none |
| D3 | `/attendance/save` (Teacher marking) | — | `attendanceSummary` + RTDB mirror | ✓ | mirror | route | DUAL | none |
| D4 | `/attendance/lock`, `/correction/*` | corrections (RTDB) | corrections (RTDB) | ✗ | RTDB-ONLY (Phase 3 scope) | route | RTDB-ONLY | **out of Phase-1 scope** (corrections = Phase 3) |
| **E. SHARED LIBRARIES / HELPERS** |
| E1 | `attendance_helper::get_student_attendance_percent` | RTDB (see A9) | — | ✗ | **RTDB-ONLY** ❌ | — | shared by Result.php | **BREAKS reports** |
| E2 | `attendance_helper` (nw_days, enforce_holidays) | pure | — | — | — | — | pure | none |
| E3 | Roster_helper / roster | FS | — | ✓ | — | — | FS | none |

---

## 2. Answers to your six questions (with evidence)

**Q1. Is every runtime consumer already reading Firestore?**
**NO — two exceptions.**
- **Report cards** read RTDB via `get_student_attendance_percent()` ([attendance_helper.php:214,218](application/helpers/attendance_helper.php#L214)). [E1/A9]
- **Parent app** `StudentRepository.getDashboardSummary()` reads RTDB (`StudentRepository.kt:122-159`). [C4]
Everything else (admin analytics/dashboard, Teacher app, Parent app main screens, Sis, notifications) is Firestore-first or Firestore-only.

**Q2. Does any mobile app still depend on RTDB attendance data?**
- **Teacher app: NO** — zero RTDB attendance access (reads `attendanceSummary`, marks via API). [B1-B4]
- **Parent app: YES (one path)** — `getDashboardSummary()` RTDB fallback, still invoked for the dashboard quick-summary though the main Dashboard screen uses Firestore. This requires an **app-side code change + rebuild + redeploy** before the server mirror is removed. [C4]

**Q3. Does any report / dashboard / analytics / notification / payroll / background process still depend on RTDB?**
- **Report cards: YES** (E1/A9) — the only backend report dependency.
- Analytics: **NO** (FS-only). Dashboard: **NO** (FS-only). Notifications: Firestore-first with RTDB *fallback* only (safe once summary is canonical). **Payroll: NO** — payroll consumes *staff* `dayWise` only; **zero student-attendance dependency**. Background/Sis: **NO** (FS `attendanceSummary`).

**Q4. Is `attendanceSummary` sufficient as the single canonical source?**
**Not yet — one convergence gap.** Single-day marks (`mark_student_day`, `api_mark_attendance`) write the per-day `attendance` collection + the RTDB `dayWise`, **but do not update `attendanceSummary`** [A2/A3]. Since apps + reports read `attendanceSummary`, it must be made **always-complete** (Step 1.1: single-day writers also RMW `attendanceSummary.dayWise`). After that, `attendanceSummary` is sufficient and canonical.

**Q5. Is any historical data migration required before removing RTDB?**
**Likely YES (verify).** Any month whose marks came only from single-day marking (never a bulk `save`) will have an incomplete `attendanceSummary` today. Those months currently rely on the RTDB `dayWise` (read fallback / report helper). A **one-time backfill** (`attendance` per-day docs and/or RTDB → `attendanceSummary`) is required for historical completeness. Exact need must be confirmed by sampling `attendanceSummary` coverage vs `attendance`/RTDB per active school.

**Q6. Can the RTDB mirror be removed without causing regressions?**
**NOT YET.** Three prerequisites first:
1. **Backend:** repoint `get_student_attendance_percent()` → Firestore `attendanceSummary` (fixes report cards).
2. **Parent app:** repoint `StudentRepository.getDashboardSummary()` → Firestore (then rebuild + ship the app).
3. **Convergence + backfill:** single-day writers maintain `attendanceSummary` (Step 1.1) + historical backfill (Step 1.3).
Once all three are done and verified, the RTDB mirror + fallbacks can be removed with **no regression**.

---

## 3. The convergence gap (root architectural item)
`attendanceSummary` is the collection every app + report reads, but it is only written by **bulk save** and **leave-stamp** — **not** by single-day/api marking (those update `attendance` per-day + RTDB `dayWise`). Today the RTDB `dayWise` masks this gap (it's the one store every writer updates, and readers fall back to it). Removing RTDB without first making single-day writes maintain `attendanceSummary` would expose the gap as missing attendance. **Fix in Step 1.1 before any removal.**

---

## 4. Updated Phase 1 prerequisites (revised from the Phase 1 plan)
| # | Prerequisite | Surface | Type |
|---|---|---|---|
| P1 | Single-day/api writers also RMW `attendanceSummary.dayWise` (+counts) | Backend | add (Step 1.1) |
| P2 | Repoint `get_student_attendance_percent()` → `attendanceSummary` | Backend (reports) | add (Step 1.1) |
| P3 | Repoint Parent app `getDashboardSummary()` → Firestore | **Parent app (Kotlin) + redeploy** | add — **app release gate** |
| P4 | Repoint RTDB *reads* (mark_student_day old-mark @1293, `_fire_student_att_events` @6015) → Firestore | Backend | add (Step 1.2) |
| P5 | Historical backfill `attendance`/RTDB → `attendanceSummary` (if sampling shows gaps) | Backend, read-only export | add (Step 1.3) |
| **THEN** | Remove all RTDB mirror writes + read fallbacks (A1-A4, A8, D1-D3) | Backend | remove (Step 1.5) |

**New critical constraint discovered:** Phase 1 now has an **app-release dependency (P3)** — the Parent app must ship a build that drops the `getDashboardSummary` RTDB read **before** the server mirror is removed, or that fallback path breaks in the field. This makes Step 1.5 (RTDB removal) gated on a **Parent app deployment**, not backend-only.

---

## 5. Recommendation
- **Student Attendance is Firestore-first almost everywhere** (Teacher app 100% clean; Parent app main screens clean; admin analytics/dashboard/Sis clean).
- **Do NOT remove the RTDB mirror yet.** Two hidden RTDB consumers (report-card helper + Parent app `getDashboardSummary`) plus the `attendanceSummary` convergence gap would cause regressions.
- **Safe path:** implement P1-P5 (add-only, verify), ship the Parent app fix (P3), backfill (P5), verify end-to-end, **then** remove RTDB (Step 1.5), re-sweep to zero.

*No source code, data, or deployment changed by this audit. Awaiting approval of these findings before beginning Step 1.1.*
