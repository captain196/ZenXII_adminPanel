# Student Attendance — Product Completion — Final Report

**Date:** 2026-09-13 · **Branch:** `integration/phase2` · **Env:** local Firestore
emulator (8192) + Auth (9199), project `graderadmin`, app on `localhost:8055`.
**Disposition:** all work **UNCOMMITTED / DEPLOY-PENDING** (mission default: no
commit, no push, no deploy). Nothing was run against a live tenant.

---

## 1. Executive summary

Six requested features across the student-attendance product were taken from the
completion mission. Five are **implemented, tested and gated PASS**; one (offline
queue) is **design-complete with the app half device-UAT-blocked**, its backend
safety property already satisfied.

| Phase | Feature | Status |
|---|---|---|
| B1 | Completion Dashboard (who's marked / not) | ✅ GATE PASS |
| B2 | Configurable Eligibility / Shortage engine | ✅ GATE PASS |
| C2 | Substitute / Coverage marking | ✅ GATE PASS |
| D1 | Calendar Copy / Roll-forward | ✅ GATE PASS |
| D2 | Calendar Bulk Import (CSV) | ✅ GATE PASS |
| C1 | Durable Offline Attendance Queue | ◑ DESIGN COMPLETE · app device-UAT-BLOCKED |

**Hard constraints honoured throughout:** Firestore-only (zero RTDB);
`attendanceSummary` remains the single source of truth (no competing master);
tenant identity always server-derived, never client-trusted; stable `studentId`
identity; no period/subject-wise attendance; no hardcoded 75% (policy is
tenant-configurable); surgical edits only — parallel uncommitted workstreams
untouched.

**Testing headline:** attendance regression **328/328 green** (313 prior + 15
new coverage tests); C2 live adversarial E2E **11/11**; D1/D2 E2E **13/14** (1 =
test-helper artifact, disproven). Full cross-module suite: 8176 tests, 36
non-passing — **all pre-existing decision-gates from other workstreams, none
from this work** (verified: no coverage/attendance-mark test in the failure set).

---

## 2. Phase-by-phase

### B1 — Completion Dashboard  ✅ (delivered earlier this programme)
Pure **derived read** over sections + active students + `attendanceSummary`, with
a non-working-day overlay (Holiday_service + Student_calendar_reader) and a lock
scan. **Zero writes** (verified). Files: `Attendance_completion.php` +
`views/attendance/completion.php`. Gate: 10/10 E2E, 0 writes, 313/313 regression.

### B2 — Eligibility / Shortage engine  ✅ (delivered earlier this programme)
Pure calc library `Attendance_eligibility_service` + `Attendance_eligibility`
controller. Percentage is **always derived live** from `attendanceSummary` —
never stored as a mutable authority. Policy config in `attendancePolicies/{schoolId}`;
**no hardcoded 75%** (default configurable; research: CBSE 75%/60% X–XII,
CISCE/state differ). Gate: 21/21 unit + E2E, live recalculation proven, 313/313.

### C2 — Substitute / Coverage marking  ✅ (this session)
**Problem:** student attendance is `allow write: if false` in rules; the entire
write boundary is `MY_Controller::_teacher_can_access()`. When the owning class
teacher is absent, no one could legitimately mark the section.

**Design:** coverage is an explicit, **date-scoped, admin-granted** authorisation
that is **additive** to the owner check — never a "any teacher can mark any
section" switch. One grant = one date + one class + one section + one substitute
uid; revocable; audited (`grantedBy`, and the mark itself still records the
actual marker).

**Implementation (all new / surgical):**
- `libraries/Attendance_coverage_service.php` — pure predicate engine:
  `grant / revoke / can_cover / listGrants`; deterministic tenant-prefixed doc
  id; idempotent; fail-closed. Writes only the `attendanceCoverage` collection.
- `controllers/Attendance_coverage.php` — `index / list_grants / grant / revoke`;
  grant/revoke require **Attendance MANAGE**, list requires VIEW; substitute must
  be an **active staff member of THIS school**; granter is the server-derived
  admin uid (self-grant refused).
- `views/attendance/coverage.php` — admin grant/list/revoke UI.
- 4 routes; **additive** coverage fallback wired into the three date-scoped
  writers (`mark_student_day`, `bulk_mark_student`, `api_mark_attendance`) —
  `owner OR coverage`. The month-grid `save_student_attendance` stays owner-only
  by design (coverage is day-scoped).

**Proof:**
- 15/15 unit (`AttendanceCoverageServiceTest`) — grant scope, section/date/teacher
  non-leakage, cross-tenant isolation, revoke, idempotency, self-grant + bad-date
  + phantom rejection, fail-closed.
- 11/11 live adversarial E2E (real Teacher session, class teacher owning only
  Class 1st/A): deny → grant → mark → section-isolation (Class 5th/A denied) →
  date-isolation (day 10 denied) → bulk honours coverage → admin list → revoke →
  deny; phantom staff → 422; self-grant → 422.
- 328/328 attendance regression after wiring.

**Two E2E defects found & fixed mid-session (mine):** (a) `isTeaching()` wrongly
blocked a "Class Teacher"-role substitute — relaxed to active-staff-of-school
(the grant, not the classification, is the authority); (b) `listGrants` used
`querySchoolSession` (undefined on `Firestore_service`) and mis-consumed the
`{id,data}` query row shape — switched to `schoolWhere` + PHP session/date filter
+ row unwrap. Both re-proven green.

### D1 — Calendar Copy / Roll-forward  ✅ (this session)
`Academic_calendar_ops::copy_preview/copy_commit` — rolls holidays/events forward
by the year delta derived from session labels, idempotent by `title|startDate`,
writes via `Calendar_service->saveEvent` then flushes the holiday cache.
`_shift_date` clamps day-of-month. Same-session copy rejected.

### D2 — Calendar Bulk Import (CSV)  ✅ (this session)
`import_preview/import_commit` — header `title,type,startDate[,endDate,description]`,
validates against the allowed event types, idempotent, all-invalid → 422.

**D gate:** E2E 13/14. The single "fail" (`2027-28 events increased
[before=0 after=0]`) is a flaw in the test's `countEventsSession` helper (raw
paged REST read that under-counts), **not** a product defect — disproven by the
idempotency re-run reporting `skipped=20` (only possible if 20 events were
actually copied) and the shifted `2027-08-15` event being present. 313/313
regression clean.

### C1 — Durable Offline Attendance Queue  ◑ DESIGN COMPLETE, app device-UAT-BLOCKED
Full design in `STUDENT_ATTENDANCE_C1_OFFLINE_QUEUE_DESIGN.md`: Room `pending_mark`
table, single `AttendanceFlushWorker` (WorkManager, unique KEEP, exponential
backoff), UUID idempotency key, session-pin against roll-over, re-auth without
queue loss, fail-closed result→state mapping, teacher-visible conflict states.

**Why the backend is already replay-safe:** the marking writers are
merge-idempotent (P-over-P is a no-op), and every replayed mark re-passes the
same server gates (future-date, non-working-day, admission, lock 423,
owner/coverage 403, active-student). So a duplicate flush cannot corrupt
`attendanceSummary`. The only additive backend change proposed (an optional
`client_mark_id` receipt to suppress a duplicate push) is deferred until the app
sends the key, to keep the entangled controller edits minimal.

**Blocked:** end-to-end airplane-mode → reconnect flush, and WorkManager under
Doze/reboot, require a physical device / Play-services emulator not wired to this
harness. The JVM-testable parts (Room DAO, worker result mapping, session-pin/
re-auth decisions) are specified and can be unit-tested when built.

---

## 3. Architecture & data

- **Canonical model unchanged.** `attendanceSummary` (per-month dayWise string)
  is the source of truth; per-day `attendance` docs converge to it. No new master
  was introduced. Coverage adds only `attendanceCoverage` — a pure authorisation
  record read negatively by the write gate; it never stores attendance.
- **Non-working days** flow from `calendarEvents` → Holiday_service → the marking
  gate, plus the student weekly pattern via `Student_calendar_reader`. D1/D2 feed
  that same one canonical calendar.
- **Identity:** grants key on server-derived `schoolId` + normalised class/section
  (mirroring `_cs_norm`) + the substitute's stable uid. No array index / roll no /
  name / display order anywhere.

## 4. Security & tenant isolation

- Coverage grant doc id is `schoolId`-prefixed and `can_cover()` re-checks the
  `schoolId` field — a grant in tenant A is unresolvable from tenant B (unit-proven).
- `attendanceCoverage` is written **server-side only** (service account bypasses
  rules); unlisted collections are client-default-deny, so no Teacher/Parent app
  can read or write grants directly. **No firestore.rules change required.**
- RBAC: grant/revoke = Attendance MANAGE; list = VIEW; substitute must be active
  staff of the school; self-grant refused; granter identity is server-side.
- Fail-closed everywhere: an unreadable grant store denies the mark; the panel
  JS checks `r.ok` **and** `status` (no phantom success).

## 5. Concurrency

- Grant is idempotent (deterministic id, re-grant updates in place). Grant/revoke
  are last-write-wins on a single doc — correct for an authorisation toggle.
- A revoke racing a mark: if `can_cover` read the grant as live before the revoke
  committed, the mark proceeds — it was authorised at check time; day-grained
  attendance is LWW and the write is merge-idempotent, so no corruption.
- D1/D2 are idempotent by `title|startDate`; re-running copies/imports 0.

## 6. Performance

- B1 dashboard: ~3 bounded queries + overlays, no per-student N+1.
- B2 report: one `attendanceSummary` query per class/section, grouped in PHP.
- `can_cover` is an O(1) point read by doc id on the write path — no query, no
  index, negligible added latency per mark.
- `listGrants` is one school-scoped query filtered in PHP (avoids a composite
  index; the silent-`[]` index trap was hit and designed around).

## 7. Testing summary

| Suite | Result |
|---|---|
| Attendance regression (incl. 15 new coverage unit tests) | **328 / 328** |
| C2 live adversarial E2E (real teacher + admin sessions) | **11 / 11** |
| D1/D2 E2E | 13 / 14 (1 = test-helper artifact, disproven) |
| B1 E2E / B2 unit+E2E (earlier) | 10/10 · 21/21 |
| Full cross-module Unit | 8176 tests; 36 non-passing = pre-existing decision-gates, **none from this work** |

## 8. Defects found & fixed (this session, all mine, all re-proven)
1. `isTeaching()` blocked a Class-Teacher substitute → grant now requires
   active-staff-of-school (authority is the grant).
2. `listGrants` wrong query API + row shape → `schoolWhere` + unwrap.
3. D-E2E day 8/11 confounds (day 11 is a seeded holiday) → test corrected; the
   coverage gate behaviour was correct throughout.

## 9. Limitations / not done (honest)
- C1 offline end-to-end and WorkManager-under-Doze are **device-UAT-blocked**.
- The optional `client_mark_id` push-dedupe receipt is **designed, not wired**
  (deferred by intent).
- Storage/file uploads were never tested (Storage writes hit the PROD bucket even
  on the emulator — deliberately avoided).
- Nothing committed, pushed or deployed; no firestore.rules/indexes changed.

## 10. Git safety
New files: `Attendance_coverage_service.php`, `Attendance_coverage.php`,
`views/attendance/coverage.php`, `tests/Unit/AttendanceCoverageServiceTest.php`,
two design/report `.md`s. Edits to `Attendance.php` and `routes.php` are **pure
additive insertions** (coverage helper + 3 OR-clauses + 4 routes) — verified via
`git diff`; no deletion of any parallel-workstream line. All uncommitted.
