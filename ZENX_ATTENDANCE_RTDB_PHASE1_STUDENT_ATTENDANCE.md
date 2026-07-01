# Phase 1 — Student Attendance Convergence (RTDB → Firestore)
## Detailed Implementation Plan · Dependency Audit · Verification · Rollback · Risk
### DESIGN ONLY — no code modified. Awaiting approval before any change.

---

## 0. Correction to the master design document (accuracy first)
The master design doc classified `mark_student_day` / `bulk_mark_student` / `api_mark_attendance` / `_stamp_leave_on_attendance` as **RTDB-PRIMARY**. The Phase-1 runtime audit proves that is **wrong** — the Firestore writes are done through **helper methods** the earlier grep didn't attribute:
- `_syncDailyToFirestore()` → writes the **`attendance`** collection (per-day doc `{schoolId}_{date}_{studentId}`).
- `_syncBulkDailyToFirestore()` → bulk version of the above.
- `_syncStudentSummaryToFirestore()` / `save_student_attendance` `fs->set` → writes **`attendanceSummary`** (per-month, `dayWise`).

**Every student-attendance writer is Firestore-first + RTDB best-effort mirror.** So Phase 1 is **mirror + fallback removal**, not "add missing writes." This lowers the risk profile — but surfaces one real architectural issue (§3, the two-store divergence) that must be resolved *before* the RTDB `dayWise` mirror is removed.

---

## 1. Frozen inventory — S1 Student Attendance (confirmed complete)
RTDB call sites in scope for Phase 1 (all others are out of scope until their phase):

| Function | Line | Op | RTDB path | Role | Firestore counterpart |
|---|---|---|---|---|---|
| `save_student_attendance` | 1189 | set | `{secRoot}/Students/{id}/Attendance/{Mon Yr}` | mirror (write) | `attendanceSummary` set @1160 |
| `save_student_attendance` | 1194 | set | `…/Attendance/Late/{key}/{id}/{day}` | mirror (write) | `attendanceSummary.lateTimes` map |
| `mark_student_day` | 1293 | get | `{sr}/Students/{id}/Attendance/{key}` | **read** (old-mark for audit, needs-approval path) | `attendance` per-day doc |
| `mark_student_day` | 1317 | get | `{attPath}` | read (RMW seed) | `attendance` / `attendanceSummary` |
| `mark_student_day` | 1344 | set | `{attPath}` | mirror (write) | `_syncDailyToFirestore` → `attendance` @1334 |
| `mark_student_day` | 1351/1353 | set/del | `…/Late/…` | mirror (write) | (late lives in `attendance.late/lateMinutes`) |
| `bulk_mark_student` | 1440/1444 | get/set | `{attPath}` | mirror (write) | `_syncBulkDailyToFirestore` → `attendance` @1429 |
| `get_student_summary` | 1507 | get | `{basePath}` | **read fallback** | `attendanceSummary` schoolWhere @1480 |
| `fetch_student_attendance` | 912/925 | get | `{secRoot}/Students`, `…/Late/{key}` | **read fallback** | `attendanceSummary` get @905 |
| `api_get_attendance` | 3782 | get | `{secRoot}/Students` | **read fallback** | `attendanceSummary` schoolWhere @3788 |
| `api_mark_attendance` | 3957/3961/3969 | get/set | `{attPath}`, `{latePath}` | mirror (write) | `_syncDailyToFirestore` @4139 |
| `_stamp_leave_on_attendance` | 4537 | set | `{attPath}` | mirror (write) | `_syncStudentSummaryToFirestore` @4527 |
| `_fire_student_att_events` | 6015 | get | `{attPath}` | **read** (event source) | `attendanceSummary` / `attendance` |
| `_syncStudentSummaryToFirestore` | (internal) | set | `{attPath}` mirror | mirror (write) | writes `attendanceSummary` (canonical) |

**Reconfirmation:** no additional RTDB student-attendance paths exist beyond this table (verified by function-scoped grep of all `firebase->*` in Attendance.php).

---

## 2. Runtime Dependency Audit — Phase 1

### Firestore READS (keep)
- `attendanceSummary` by docId `{schoolId}_{studentId}_{YYYY-MM}` (fetch_student_attendance @905; _stamp_leave @4503).
- `attendanceSummary` via `schoolWhere` (get_student_summary @1480; api_get_attendance @3788; fetch_analytics; fetch_individual_report — already FS-only).
- `attendance` per-day (dashboard_stats reads `attendance` where date+type=student — already FS).
- Roster via `roster->` / `_get_section_students` (Firestore, Roster_helper).

### Firestore WRITES (keep — canonical)
- `attendance` per-day doc `{schoolId}_{date}_{studentId}` (`_syncDailyToFirestore`, `_syncBulkDailyToFirestore`) — consumed by **mobile apps** + dashboard_stats.
- `attendanceSummary` per-month doc (`save_student_attendance`, `_syncStudentSummaryToFirestore`) — consumed by **Analytics, Individual Report, report cards**.

### RTDB READS (to remove — all have FS counterparts)
- Fallbacks: fetch_student_attendance @912/925, get_student_summary @1507, api_get_attendance @3782.
- Audit old-mark: mark_student_day @1293 → replace with `attendance`/`attendanceSummary` read.
- RMW seed: mark_student_day @1317, api_mark @3957, bulk @1440 → the RTDB read only seeds the *RTDB* string; removed with the mirror.
- Event source: `_fire_student_att_events` @6015 → read `attendanceSummary`/`attendance`.

### RTDB WRITES (to remove — all best-effort mirrors)
- save @1189/1194, mark_student_day @1344/1351/1353, bulk @1444, api_mark @3961/3969, _stamp_leave @4537, `_syncStudentSummaryToFirestore` internal mirror.

### Cross-module dependencies
- **Analytics** (`fetch_analytics`, `fetch_individual_report`, `fetch_monthly_trend`) → read `attendanceSummary`. **Already Firestore-only.** ✓
- **Report cards / Results** → read student attendance summaries. **Must confirm** they read `attendanceSummary` (Firestore), not the RTDB `dayWise`.
- **Communication / notifications** (`_fire_student_att_events`) → currently reads RTDB attendance to decide absent/late events; must read Firestore.
- **Dashboard** (`dashboard_stats`) → reads `attendance` (FS-first). ✓ (RTDB student fallback already removed.)
- **Payroll** → **no student-attendance dependency** (payroll consumes *staff* `dayWise` only). **Zero Phase-1 payroll impact** — to be re-verified, expected NONE.

### Mobile dependencies (must verify on-device)
- **Parent app** (`D:\Projects\SchoolSyncParent`): reads student daily attendance. **Confirm it reads Firestore `attendance` (docId `{schoolId}_{date}_{studentId}`)** — the `_syncDailyToFirestore` comment says the docId "matches Android," implying yes. **Must confirm it does NOT read RTDB `{secRoot}/Students/.../Attendance`.**
- **Teacher app** (`D:\Projects\SchoolSyncTeacher`): marks student attendance (calls `mark_student_day` / `bulk_mark_student` / `api_mark_attendance`) and may read it back. Confirm read path is Firestore.

---

## 3. Central architectural issue to resolve FIRST (blocking)
**Two Firestore stores are not kept mutually complete, and the RTDB `dayWise` string is currently the only always-complete per-month view:**
- Single-day writers (`mark_student_day`, `api_mark`, `api_punch`) update **`attendance`** (per-day) **and** the **RTDB `dayWise`** — but **NOT `attendanceSummary`** (per-month).
- `attendanceSummary` is updated only by `save_student_attendance` (bulk save) and `_stamp_leave`.
- **Readers** (`fetch_student_attendance`, `get_student_summary`, `api_get_attendance`) are Firestore-first on **`attendanceSummary`** and fall back to the **RTDB `dayWise`**.

⇒ If we remove the RTDB `dayWise` fallback **without** making single-day writes also maintain `attendanceSummary`, any month whose marks came only from single-day marking (never a bulk save) would read **empty/incomplete** from `attendanceSummary`.

**Resolution options (decide before coding):**
- **(A) Converge writers (recommended):** make `_syncDailyToFirestore` **also** read-modify-write the `attendanceSummary.dayWise` for that day (like `Staff_attendance_writer` does). Then `attendanceSummary` is always complete and the RTDB fallback is safe to drop. *(Preferred — one canonical per-month store.)*
- **(B) Converge readers:** make readers aggregate the per-day `attendance` collection instead of `attendanceSummary`. *(More reads; larger change to consumers incl. mobile.)*
- **(C) Backfill + A:** one-time backfill `attendanceSummary` from `attendance`/RTDB, then (A). *(Needed if historical months exist only in RTDB.)*

Recommended: **(A) + a scoped (C) backfill** for historical months, executed read-only and approval-gated.

---

## 4. Implementation plan (add-first, remove-later — never in one step)

**Step 1.1 — Converge the write model (add Firestore, no removal).**
- Extend `_syncDailyToFirestore` / `_syncBulkDailyToFirestore` to **also** RMW `attendanceSummary.dayWise` (+ counts/percentage) for the affected day — reusing the exact `attendanceSummary` shape from `save_student_attendance` (§ schema below). Keep the RTDB mirror in place for now.
- Result: after any single-day mark, `attendanceSummary` is complete in Firestore **and** RTDB still mirrors. No reader change yet.

**Step 1.2 — Repoint the RTDB *reads* to Firestore (add-first).**
- `mark_student_day` @1293 old-mark audit → read from `attendanceSummary.dayWise` (or `attendance`).
- `_fire_student_att_events` @6015 → read Firestore.
- Keep the RTDB fallback in the readers for now (safety net).

**Step 1.3 — Backfill (if needed, approval-gated, read-only export).**
- If historical months exist only in RTDB `dayWise` (no `attendanceSummary`), run a one-time RTDB→`attendanceSummary` backfill. Read-only from RTDB; writes only to Firestore. No RTDB deletion.

**Step 1.4 — VERIFY (full gate, §5). STOP for approval.**

**Step 1.5 — Remove RTDB (only after 1.4 passes).**
- Delete the RTDB mirror **writes** (save @1189/1194, mark @1344/1351/1353, bulk @1444, api_mark @3961/3969, _stamp_leave @4537, helper mirrors).
- Delete the RTDB **read fallbacks** (fetch @912/925, get_summary @1507, api_get @3782) — Firestore becomes sole source.
- Re-run the module RTDB sweep for S1 → **zero** `firebase->*` in the S1 functions.

**Step 1.6 — Re-verify (§5 abbreviated) + STOP for approval before Phase 2.**

---

## 5. Verification plan (must all pass at Step 1.4 and 1.6)
| Check | Method | Pass criteria |
|---|---|---|
| Firestore behaviour | `php -l`; unit call of the writer helper | `attendanceSummary.dayWise` reflects single-day marks |
| End-to-end parity | Mark a day (single + bulk + api) on staging | `attendance` + `attendanceSummary` + (still) RTDB agree |
| No regression | Re-mark, change mark, leave-stamp | counts/percentage correct; no lost days |
| Cross-module | Analytics + Individual Report | reflect single-day marks (previously only bulk) |
| **Teacher App** | on-device mark + read-back | mark writes Firestore; read shows it; no RTDB |
| **Parent App** | on-device student attendance view | reads Firestore `attendance`; unaffected by RTDB removal |
| **Dashboard** | attendance dashboard | present/absent counts match |
| **Reports** | report card / results attendance | summary matches `attendanceSummary` |
| **Payroll** | generate a payroll run | **unchanged** (no student-attendance dependency) |
| **Performance** | bulk mark a full section | within latency budget; extra `attendanceSummary` RMW acceptable |
| **Firestore-only** | grep `firebase->*` in S1 funcs (after 1.5) | **zero** |

**Requires Live Validation** (cannot be done in static analysis): on-device Parent/Teacher checks, real Firestore round-trips, performance under bulk load.

---

## 6. Rollback strategy
- **Steps 1.1–1.3 are additive** (Firestore writes + reads only) — rollback = revert the commit; RTDB is untouched, so no data risk.
- **Step 1.5 (RTDB removal)** is the only destructive step. Rollback = **git revert** of that isolated commit restores the RTDB mirror writes/fallbacks immediately (RTDB data was never deleted, so mirrors resume writing on the next mark; historical RTDB data is intact).
- **Data safety:** no RTDB node is deleted in Phase 1 (only the code that writes/reads them is removed). RTDB remains a frozen, readable backup until a later, separately-approved data-cleanup phase.
- **Feature-flag option:** guard Step 1.5 removals behind a config flag (`student_attendance_rtdb_mirror = off`) for an instant runtime rollback without redeploy, if desired.

---

## 7. Risk assessment
| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| `attendanceSummary` incomplete for single-day-only months → gaps after fallback removal | **Medium** | High | Step 1.1 write convergence + Step 1.3 backfill BEFORE Step 1.5 |
| Parent/Teacher app secretly reads RTDB `dayWise` | Low-Med | High | Verify on-device (Step 1.4) before removal; keep RTDB data as backup |
| Report cards read RTDB dayWise | Low | Medium | Confirm report-card source is `attendanceSummary` |
| Extra `attendanceSummary` RMW adds write latency on bulk mark | Medium | Low | Batch the summary RMW; measure (Step 1.4 performance) |
| Notification events change (RTDB→FS read) alter absent/late firing | Low | Medium | Parity-test `_fire_student_att_events` |
| Concurrent single-day marks race on the month summary | Low | Medium | Reuse the existing lock / CAS pattern (as staff writer) |
| Payroll impact | Very Low | — | None expected (staff-only); re-verify |

**Open verification items to run at Step 1.0 (pre-implementation, no code):** (1) confirm Parent app reads Firestore `attendance` not RTDB; (2) confirm report-card attendance source; (3) confirm whether any month exists only in RTDB (backfill needed?); (4) confirm `_fire_student_att_events` consumer contract.

---

## Summary recommendation
Phase 1 is **lower-risk than first classified** (fully dual-write already), but hinges on **one architectural fix**: make single-day writes maintain `attendanceSummary` (option A) so the RTDB `dayWise` fallback can be safely retired. Sequence: **add write-convergence → repoint reads → (backfill if needed) → verify everything incl. mobile → only then remove RTDB → re-verify → stop.** No RTDB data is deleted in this phase.

*No source code, data, or deployment changed. Awaiting approval to begin Step 1.1.*
