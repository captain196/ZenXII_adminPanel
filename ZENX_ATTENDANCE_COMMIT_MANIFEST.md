# ZenX — Attendance Migration — COMMIT MANIFEST

**Date:** 2026-07-01 · **Branch:** `ank-yug_b1` · **State:** staged, **NOT committed**. No push/deploy.
**Staged:** 36 files · **+5,584 / −1,246**. Every staged file is attendance-domain (verified — zero unrelated files staged).

---

## 1. Staged files — what & why

### Core backend
| File | Δ | Why it belongs |
|---|---|---|
| `application/controllers/Attendance.php` | +533/−916 | The attendance controller: RTDB elimination (Components 1–5), Firestore `attendance`+`attendanceSummary` convergence, correction convergence fix, GPS policy endpoints, OP-7 punch-log viewer, ISSUE-1 hardening |
| `application/helpers/attendance_helper.php` | +98/−73 | HC-3 holiday helpers (`nw_days_from_holidays`), Firestore `get_student_attendance_percent_fs`, retired RTDB `update_student_att_summary` |
| `application/controllers/Staff_attendance.php` | +497 (new) | GPS staff self-attendance (`punch`/`me`), Firebase Bearer auth |
| `application/libraries/Attendance_policy.php` | +317 (new) | GPS attendance-policy library (radius/coords/windows) |
| `application/libraries/Holiday_service.php` | +278 (new) | Canonical holiday reader (holiday convergence, Firestore calendarEvents) |
| `application/controllers/Holiday_legacy_inventory.php` | +187 (new) | Read-only holiday inventory migration tool |
| `application/helpers/attendance_view_helper.php` | +73 (new) | Attendance view helper |
| `application/controllers/Staff_role_check.php` | +448 (new) | CLI diagnostics: reconcile/coverage/canaccess/list_schools |
| `application/controllers/Stream_b_verifier.php` | +1/−104 | Staff-attendance (Stream B) verifier; A38/A39 retired with `approve_attendance_request` |
| `application/controllers/Health_check.php` | +4/−1 | Attendance health probe |

### Config / routes
| File | Δ | Why |
|---|---|---|
| `application/config/config.php` | +4 | CSRF excludes for `staff_attendance/*`, `attendance/save`, `attendance/correction/submit` (Bearer endpoints) — **attendance-only** |
| `application/config/routes.php` | +12/−5 | GPS policy routes, `staff_attendance/*`, holiday-inventory route; retired legacy `compute_summary`/`approve`/`reject`/`list_pending` routes — **all attendance** |

### Views (attendance module UI)
`settings.php` (+355/−103, GPS policy + thresholds), `punch_log.php` (+74/−14, OP-7 GPS audit viewer), `index.php` (+36/−17), `analytics.php` (+36/−13), `control.php`/`scan_qr.php`/`staff.php`/`student.php`/`student_leave.php` (+5 each).

### Tooling
`staff_attendance_runtime_harness.php` (+210, new), `scripts/attendance_device_keys_check.js` (+38, new).

### Documentation (13 `.md`)
All `ZENX_ATTENDANCE_*`, `ZENX_GPS_STAFF_ATTENDANCE_*`, `ZENX_STUDENT_ATTENDANCE_*` migration reports.

---

## 2. Impact summary

**Firestore impact:** `attendance` (daily) + `attendanceSummary` (monthly) are the sole canonical stores; `staffAttendance`/`staffAttendanceSummary` for staff; canonical holiday reads via `Holiday_service`. All per-day writers converge both stores via shared helpers.

**RTDB impact:** all student-attendance runtime RTDB reads/writes/mirrors/fallbacks/sync eliminated (Components 1–5). Remaining RTDB in staged files = out-of-scope categories only (device/punch infra, AttendanceRules config, audit/metrics sinks, `fix_attendance_keys` legacy migration helper, dead-gated Fees helpers). No RTDB data deleted.

**GPS impact:** GPS staff self-attendance (Staff_attendance + Attendance_policy) with Bearer auth, radius/geo policy, OP-7 audit viewer. Additive.

**Cross-module impact:** none negative. Parent/Teacher apps (separate repos), Dashboard, Register, Analytics, Report Card, Promotion read Firestore; Fees attendance link dead-gated; Payroll staff-only/Firestore.

**Totals:** 36 files, **+5,584 / −1,246**.

---

## 3. ⚠️ Decision needed — attendance cross-cutting changes in shared files (NOT staged)

These files contain attendance-migration pieces **interleaved with unrelated changes**, so they were **left unstaged** (staging them whole would violate "exclude unrelated"):

| File | Attendance piece (direct dep) | Also contains | 
|---|---|---|
| `application/core/MY_Controller.php` | `_bearer_auth_bridge` (Teacher-app attendance dual-auth) | ~111 other lines |
| `application/controllers/Result.php` | report-card % via `get_student_attendance_percent_fs` | other report-card changes |
| `firebase-rules/firestore.rules` + `.indexes.json` | attendance collection rules/indexes | other collections |
| `application/controllers/Hr.php` | staff-attendance leave→'L' stamping | HR module changes |
| `application/controllers/Exam.php` | Holiday_service consumption (holiday convergence) | Examination module |
| `application/views/academic/index.php` | holiday authoring (convergence) | Academic Calendar |

**Consequence of committing only the 36 staged files:** the commit captures the attendance **module** code but **not** the cross-cutting hooks (bearer bridge, report-card %, attendance rules/indexes). Runtime is unaffected (those hooks remain in the working tree, uncommitted, until their own commits). Because nothing is pushed, final state is unaffected by commit ordering.

**Options:**
- **(A) Recommended:** commit the 36 staged attendance-module files now; commit the cross-cutting shared files separately (with their modules, or via interactive `git add -p`). Keeps this commit clean and honest.
- **(B):** also stage `MY_Controller.php`, `Result.php`, `firebase-rules/*` **whole** (accepting their non-attendance changes ride along) so the commit is self-contained/deployable. Violates strict "attendance-only."

---

## 4. Proposed commit message

```
feat(attendance): Firestore-only Attendance migration + GPS staff attendance + RTDB elimination

Complete Attendance-module migration to a Firestore-only runtime:

- RTDB elimination: remove all student-attendance RTDB mirrors, fallbacks,
  seed reads, the legacy PendingApproval correction subsystem, the
  update_student_att_summary + _update_summary_incremental + compute_summary
  section-summary writers. Firestore attendance + attendanceSummary are the
  sole canonical stores; every per-day writer converges both via the shared
  _syncDailyToFirestore/_applyDayToSummary helpers (incl. correction_decide).
- GPS staff self-attendance: Staff_attendance (punch/me), Attendance_policy
  (radius/geo/windows), Bearer auth; OP-7 GPS punch-log audit viewer.
- Holiday convergence: canonical Holiday_service reader + read-only inventory.
- Diagnostics/tooling: Staff_role_check (reconcile/coverage), runtime harness.
- Verification: php -l clean; attendance↔attendanceSummary 0 gaps (all schools);
  Stream_b_verifier green (A38/A39 retired with legacy approver).

Out of scope (separate commits): device/punch infra RTDB, AttendanceRules
config, cross-cutting hooks in MY_Controller/Result/firebase-rules.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
```

---

## 5. Rollback
Code-only (no RTDB data deleted). `git reset` unstages; post-commit `git revert <sha>` restores. Working-tree backups of sed-edited states in the session scratchpad.

## 6. STOP
36 attendance-domain files are **staged, not committed**. Awaiting your approval of: (a) the staged set, (b) the cross-cutting decision (Option A vs B), and (c) the commit message — before I run `git commit`. **No commit / push / deploy.**
