# Student Attendance — Adversarial Critique & Fixes

**Date:** 2026-09-15 · **Method:** three parallel adversarial critics
(correctness/data-integrity, security/concurrency, C1 Kotlin offline) →
every finding **verified against the real code by hand** before any fix →
confirmed defects fixed autonomously, most-severe first → re-tested.
**Nothing committed/pushed/deployed. No production data touched.**

Honest verdict: the module was **close to** production-grade but **not** — the
critique found **1 real HIGH data-loss bug, 1 HIGH consistency/security gap, and
several MEDIUM correctness/security defects**. All are now fixed or explicitly
escalated. It is materially more production-grade after this pass.

---

## Fixed (11) — all verified

| # | Sev | Defect | Fix | Proof |
|---|-----|--------|-----|-------|
| K1 | **HIGH** | Offline worker deleted a queued row on `200 + partial:true` → un-written students lost | `FlushDecision` treats `partial:true` as RETRY (capped), never DELETE; worker reads the flag | new unit tests `partial_success_retries_not_deletes` / `…parks_terminal_after_cap` |
| C1/C4/C6 | **HIGH** | `api_mark_attendance` wrote with NO active-status / admission / holiday / lock / governance gate, used app-tz not school-tz, accepted `V` | Brought to full parity: school-tz date, holiday gate, fail-closed lock, active+admission gate, only `P/A/L/T` | php -l + 328/328 regression |
| S1 | MED | `attendance/save` + `attendance/correction/submit` CSRF-excluded but session-reachable → CSRF forgery | Require a Bearer token (`_require_app_token`); app sends it, a cross-site cookie forgery cannot | E2E: session+no-Bearer → **401**; Bearer path → 200 (6/6) |
| S3 | MED | `_get_lock` returned `locked:false` on a **failed** read (fail-open) → mark lands on a locked day | Added `Firestore_service::getOrFail`; write-path lock reads fail **closed** (read error ⇒ treated LOCKED) | php -l + 328/328 |
| C2 | MED | Completion dashboard counted inactive/transferred students' leftover marks → false `COMPLETED` | "marked" now filtered to the active-in-section roster (id set) | verified logic; 328/328 |
| C3 | MED | Merge helper let a client `H` overwrite a real server mark (revert a correction) | `H` now defers to the server like `V` (calendar re-stamps real holidays) | direct test: `H`-over-`P` ⇒ keeps `P` |
| K2 | MED | 401 REAUTH had no attempt cap → a disabled account spins forever, never surfaces | REAUTH capped to TERMINAL after `MAX_ATTEMPTS`; worker increments attempts | new unit test `unauthorized_parks_terminal_at_cap` |
| C5 | LOW | `warningThreshold` policy field was inert | Wired into the WARNING band (eligible-but-approaching); backward-compatible when ≤ minPercent | direct test: 76.7% @warn80 ⇒ warning; @default ⇒ eligible |
| K4 | LOW | "You're offline" shown even for 5xx while online (misleading) | Message reworded to "Couldn't reach the server — saved on this device…" | code |
| K5 | LOW | Reconnect comment overpromised "immediate" flush (KEEP keeps backoff) | Comment corrected to reflect KEEP semantics | code |

## Documented / escalated (6) — deliberately NOT auto-fixed

| # | Sev | Finding | Why not auto-fixed |
|---|-----|---------|--------------------|
| S2 | MED | `_teacher_can_access` scopes by role **name** (`Teacher`/`Class Teacher`); a custom RBAC role with `Attendance:edit` escapes section scoping and can mark school-wide | The safe fix touches the **shared RBAC spine** (`MY_Controller`, all controllers) and could lock out legitimate custom school-wide roles. Correct fix = add a role **scope attribute** (classroom vs school-wide) to the RBAC catalogue — an owner RBAC-program decision, not a surgical attendance change. **Recommend prioritising.** |
| S4 | LOW | Holiday gate fails **open** on an unreadable calendar | Deliberate ACAD-D18 policy ("a calendar read failure must never break the school day"); logged, not silent. Owner policy call. |
| S5 | LOW | Lock TOCTOU: lock checked, then summary CAS commits on the summary's precondition (not the lock) | Narrow window; the lock **check** is now fail-closed. Re-reading the lock at commit adds CAS-path complexity for a small advisory-lock window. |
| S6 | LOW | `cron_auto_lock` uses one fleet-wide token with session-derived tenant | Platform-scheduling concern outside the attendance-core scope; see the platform-scheduling rule. |
| K3 | LOW | A mark made at 23:55 offline, flushed after midnight, is refused (date-pin) and needs a correction | **Correct governance** — attendance is same-day; a backdated write must go through the correction workflow, not a silent direct write. Surfaced honestly to the teacher. |
| K6 | LOW | Client session-pin is skipped when the worker can't read the active session | Defense-in-depth only; the **server** re-enforces session on replay (→ terminal). |

---

## Test evidence (actual, this pass)

- Admin Attendance regression: **328/328**.
- C1 app unit tests: **43/43** (FlushDecision 22 incl. new partial + reauth-cap tests, PendingMark 11, DAO 10) — Teacher app **BUILD SUCCESSFUL**.
- C1 backend E2E (Bearer + CSRF + date-pin): **6/6** — incl. session+no-Bearer → 401, past/future `mark_date` → 423, invalid → 400, idempotent replay.
- C2 coverage adversarial E2E: **11/11** (unaffected).
- Merge + eligibility fixes: verified by direct behavioral checks.

## Files changed this pass (additive/surgical)

- `application/controllers/Attendance.php` — api_mark_attendance parity; `_get_lock($failClosed)` + write-path callers; `_require_app_token` guard on `save`/`correction_submit`.
- `application/libraries/Firestore_service.php` — new `getOrFail` (additive; file was already parallel-modified — my change is cleanly on top).
- `application/controllers/Attendance_completion.php` — active-roster filter for the marked count.
- `application/libraries/Attendance_eligibility_service.php` — warningThreshold wired.
- `application/helpers/attendance_merge_helper.php` — client `H` defers to server.
- Teacher app: `FlushDecision.kt` (partial + reauth cap), `AttendanceFlushWorker.kt` (partial flag, reauth attempt increment), `AttendanceViewModel.kt` (message), `SchoolSyncApp.kt` (comment), `FlushDecisionTest.kt` (+4 tests).

## Git
Admin `integration/phase2`, Teacher `ankit/my_teacherFeature` — **UNCOMMITTED**. No commit/push/deploy. Parallel work preserved (the large Firestore_service stat is pre-existing repo CRLF/LF noise; real content delta is +133/-3, additive).

## Bottom line
Production-grade after these fixes for the implemented scope, **with one caveat to close first**: **S2 (RBAC section-scoping for custom roles)** should be decided/fixed by the owner before wide rollout, and the C1 interactive device UAT remains parked. Then it's ready for review/commit.
