# Phase 1 Prerequisites — Verification Audit (Student Attendance)
## Proof that all runtime RTDB attendance CONSUMERS now read Firestore
### Add-only phase COMPLETE. No RTDB code removed. Awaiting approval for the separate removal phase.

**Legend:** **Verified** = confirmed statically here (`php -l`, code inspection, compile, CLI probe). **Requires Live Validation** = correct by analysis; needs runtime/device/browser (not fabricated).

---

## 1. What was implemented (add-only)

| # | Prerequisite | Change | Evidence |
|---|---|---|---|
| **P1** | Every student-attendance write maintains `attendanceSummary` | New `_applyDayToSummary()` RMW helper wired into the single funnel `_syncDailyToFirestore()` (covers mark_student_day, bulk_mark_student, api_mark_attendance, api_punch). Corrections (`approve_attendance_request`) + leave (`_stamp_leave`) already wrote `attendanceSummary`. | Attendance.php: helper @6139, call @6121 |
| **P2** | Report cards read Firestore | New `get_student_attendance_percent_fs()` reads `attendanceSummary`; `Result.php` repointed (include-leave reused from already-read `$attRules`, no new RTDB read) | attendance_helper.php:281; Result.php:1571 |
| **P3** | Parent app off RTDB | `StudentRepository.getDashboardSummary()` repointed RTDB → Firestore `attendanceSummary` (current + prev month) | StudentRepository.kt:132,149; **BUILD SUCCESSFUL** |
| **P4** | Remaining backend RTDB-only reads → Firestore | `mark_student_day` old-mark audit + `approve_attendance_request` RMW seed now read `attendanceSummary` | Attendance.php ~1293, ~5449 |
| **P5** | Historical coverage / backfill | Coverage probe: **8/8 per-day `attendance` docs consistent with `attendanceSummary`** → no backfill needed for SCH_D94FE8F7AD; general backfill plan prepared (not executed) | `staff_role_check coverage` output |

**Lint:** `Attendance.php`, `Result.php`, `attendance_helper.php`, `Staff_role_check.php` → all `php -l` **OK**. Parent app → **BUILD SUCCESSFUL** (2m45s; only unrelated `ReceiptPdfGenerator` warnings).

---

## 2. Runtime RTDB attendance CONSUMER re-audit (the proof)

| Consumer | Before | After | RTDB *read* consumer now? |
|---|---|---|---|
| Admin single-day/bulk/api marking | wrote `attendance`+RTDB, not `attendanceSummary` | now also maintains `attendanceSummary` (P1) | writes only (mirror) |
| Admin reads (fetch/get_summary/api_get) | Firestore-first + RTDB fallback | unchanged — Firestore-first | fallback only (inert) |
| **Report cards** (`Result.php`) | **RTDB-only read** ❌ | **Firestore `attendanceSummary`** ✅ (P2) | **no** |
| **Parent app** `getDashboardSummary()` | **RTDB read** ❌ | **Firestore `attendanceSummary`** ✅ (P3) | **no** |
| Parent app main screens | Firestore | Firestore | no |
| **Teacher app** | Firestore/API only | unchanged | no |
| Analytics / Dashboard / Sis | Firestore-only | unchanged | no |
| Notifications (`_fire_student_att_events`) | Firestore-first + fallback | unchanged | fallback only (inert) |
| Backend old-mark audit / approve seed | **RTDB read** ❌ | **Firestore** ✅ (P4) | **no** |

**Conclusion:** every **RTDB-primary** attendance read consumer has been repointed to Firestore. The only RTDB *reads* left are **Firestore-first fallbacks** (fetch @912, get_summary @1507, api_get @3782, _fire @6015) — inert while `attendanceSummary` is complete (P1 guarantees it going forward; P5 confirms current completeness). These fallbacks + all mirror **writes** are **intentionally retained** for the separate removal phase.

---

## 3. Verification against your checklist

| Area | Result | Evidence / caveat |
|---|---|---|
| Firestore behaviour | **Verified** | P1 helper RMW logic; `attendanceSummary` counts recomputed by `_syncStudentSummaryToFirestore` |
| Every consumer on Firestore | **Verified (static)** | §2 re-audit |
| **Teacher App** | **Verified** | audit: reads `attendanceSummary`, marks via API — zero RTDB |
| **Parent App** | **Verified (compile) + Requires Live Validation** | BUILD SUCCESSFUL; no RTDB read in StudentRepository. On-device dashboard render pending |
| **Admin Panel** | **Verified (static)** | marking funnels through `_syncDailyToFirestore` → summary maintained |
| **Reports** | **Verified (static) + Requires Live Validation** | helper repointed; render a report card to confirm % |
| **Dashboard** | **Verified** | already Firestore (`dashboard_stats`) |
| **Analytics** | **Verified** | already Firestore-only |
| **Notifications** | **Verified (static)** | Firestore-first; fallback inert |
| **Payroll impact** | **Verified — NONE** | payroll consumes staff `dayWise` only; zero student-attendance dependency |
| **Performance** | **Requires Live Validation** | P1 adds 1 read + 1 write to `attendanceSummary` per single-day mark (and per-student in bulk); measure bulk-mark latency |
| **Zero regressions** | **Verified (static) + Requires Live Validation** | lint + compile clean; coverage 8/8; runtime marking/report/app flows pending live check |

**Requires Live Validation (not executed here, not fabricated):** Parent-app dashboard on-device; a rendered report card's attendance %; bulk-mark latency; an end-to-end single-day mark reflecting in Analytics/Teacher-app/Parent-app.

---

## 4. Confirmations
- **No RTDB code removed.** All mirror writes + read fallbacks remain intact (safety nets for the removal phase).
- **Firestore is the only *primary* source** for every student-attendance consumer.
- **Nothing committed/pushed/deployed/migrated.** Parent app was compiled (not installed/shipped).
- **Open (out of Phase-1-student scope):** `Result.php` still reads `AttendanceRules` **config** (min%/include-leave) from RTDB — that is **S6 (Attendance Settings)**, a separate phase; it is *config*, not attendance *data*. Flagged for S6.

---

## 5. Readiness for the removal phase (separate, on your approval)
Once you approve, and after the **Parent app build is shipped to devices** (P3 field gate) + the Requires-Live-Validation items pass, the removal phase can:
1. Delete RTDB mirror writes (save/mark/bulk/api_mark/api_punch/_stamp_leave/approve/update_student_att_summary).
2. Delete Firestore-first RTDB read fallbacks (fetch/get_summary/api_get/_fire).
3. Re-sweep S1 functions → `firebase->*` = 0.
No RTDB data is deleted (code only); rollback = one-commit revert.

*Add-only prerequisites complete and verified. Stopping for your approval. RTDB removal remains a separate final phase pending your go-ahead.*
