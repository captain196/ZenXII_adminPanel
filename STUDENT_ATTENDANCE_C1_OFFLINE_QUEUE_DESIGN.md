# Phase C1 — Durable Offline Attendance Queue (Design)

**Status:** IMPLEMENTED (2026-09-14) — see
`STUDENT_ATTENDANCE_C1_OFFLINE_QUEUE_FINAL_REPORT.md`. App queue + worker +
backend `mark_date` date-pin built, compiled (BUILD SUCCESSFUL), 39/39 app unit
tests, 6/6 backend E2E, 328/328 admin regression. Feature flag OFF. Only the
on-device offline lifecycle UAT remains **DEVICE-UAT-BLOCKED** (no physical
device / Play-services emulator in this harness) — checklist in
`STUDENT_ATTENDANCE_C1_DEVICE_UAT_CHECKLIST.md`.

NOTE: the design below assumed the per-student date-carrying endpoints; the app
actually marks section-wide via today-only `attendance/save`, so C1 adds an
optional server-validated `mark_date` date-pin instead (see the final report).

**Scope:** Teacher app marks student attendance while offline (≈1/3 of Indian
schools have unreliable connectivity — see the market-gap research). Marks must
survive app kill, reboot and days offline, then flush exactly-once when
connectivity returns, without corrupting the canonical `attendanceSummary`.

Firestore-only. No RTDB. No period-wise/subject-wise attendance. Tenant identity
is always server-derived; the queue never carries a client-chosen school id that
the server trusts.

---

## 1. Why the backend is already replay-safe

The property that makes an offline queue *safe* — that flushing the same mark
twice cannot corrupt state — already holds:

- **Merge-idempotent writes.** The student-marking writers converge the per-day
  `attendance` doc and the per-month `attendanceSummary.dayWise` string. Setting
  day *N* to `A` twice yields the identical document (`Attendance.php` —
  `_syncDailyToFirestore`/`_applyDayToSummary` are "set with merge"; P-over-P is
  a no-op). So a duplicate flush is harmless to the data.
- **Server-authoritative gates still run on flush.** Every queued mark, when
  replayed, passes back through the *same* endpoint and therefore re-checks:
  future-date governance, non-working-day (holiday/Sunday) rejection, admission
  cutoff (ATT-ADM-1), the explicit day lock (ATT-LOCK-1 → HTTP 423), the
  owner/coverage authorisation (C2), and active-student status. A queued mark is
  never trusted just because it was made offline.
- **Punch idempotency precedent.** The biometric path already has durable,
  TTL'd, `event_id`-keyed idempotency (`attendanceProcessedEvents`, Phase 6D).
  We reuse the *shape* of that contract for the student-mark queue's optional
  duplicate-push suppression — see §4.

What an offline queue adds is **durability + ordering + conflict UX on the
client**, not new server correctness.

---

## 2. Client data model (Room)

```
Entity  pending_mark
  id            TEXT  PK   -- client UUID = the idempotency key (see §4)
  school_id     TEXT       -- for display/routing only; server re-derives auth tenant
  session       TEXT       -- session-pin (see §5)
  class_key     TEXT
  section_key   TEXT
  student_id    TEXT       -- null for a bulk (whole-section) mark
  month         TEXT       -- "September"
  day           INTEGER    -- 1..31
  date_iso      TEXT       -- "2026-09-14" (redundant, for conflict display)
  mark          TEXT       -- P/A/L/T   (H reserved for holidays; never queued by a teacher)
  late_time     TEXT       -- optional, for T
  scope         TEXT       -- 'single' | 'bulk'
  created_at    INTEGER    -- device epoch ms, for ordering
  attempts      INTEGER    -- retry counter
  state         TEXT       -- PENDING | SENDING | FAILED_RETRYABLE | FAILED_TERMINAL
  last_error    TEXT       -- last server message, shown to the teacher
```

Ordering: flush oldest-first by `created_at`, then `id`. A later mark on the same
`(class,section,day,student)` supersedes an earlier queued one *on the device*
(collapse before send) so the teacher's final intent wins and the server sees one
write.

---

## 3. Flush worker (WorkManager)

- A single `CoroutineWorker` (`AttendanceFlushWorker`), unique work name
  `attendance-flush`, `ExistingWorkPolicy.KEEP`.
- Constraints: `NetworkType.CONNECTED`. Enqueued (a) on every successful local
  mark, (b) on app start, (c) on connectivity regained.
- Backoff: `BackoffPolicy.EXPONENTIAL`, 30s base, capped by WorkManager (max 5h).
- Per row: POST to the existing endpoint —
  - single → `attendance/mark_student_day`
  - bulk   → `attendance/bulk_mark_student`
  (These are the C2-wired, governance-gated writers. The teacher-app
  `attendance/save` bulk path may also be used where a whole-month grid is
  queued, but the common offline case is single/bulk *day* marks.)
- Result mapping (fail **closed**, per the panel "phantom success" trap — check
  `r.ok` **and** JSON `status`):
  - `200 success` → delete the row.
  - `403` (not owner / no coverage) → `FAILED_TERMINAL`; surface "you are no
    longer assigned / coverage ended for this section".
  - `422` (future date / admission / all-invalid) → `FAILED_TERMINAL`; show the
    server message.
  - `423` (date locked) → `FAILED_TERMINAL`; "attendance for this date is
    locked — file a correction".
  - `401` (session expired) → keep row `PENDING`, trigger re-auth (§6), retry.
  - network / 5xx → `FAILED_RETRYABLE`; WorkManager backoff.

The worker is pure over its inputs and the HTTP client, so its mapping logic is
**unit-testable on the JVM** with a fake endpoint — the part that does *not*
need a device.

---

## 4. Idempotency key

- `pending_mark.id` is a client-generated UUIDv4, created **once** when the mark
  is first recorded offline, and reused on every retry. It is the exactly-once
  key.
- Because the write is merge-idempotent (§1), a duplicate flush is already
  data-safe *without* server bookkeeping. The key exists to (a) let the device
  correlate a response to a row across retries, and (b) optionally suppress a
  duplicate **push** on replay.
- **Optional server hardening (small, additive, backward-compatible):** accept
  an optional `client_mark_id` on `mark_student_day` / `bulk_mark_student`. When
  present and already seen (point read on `attendanceMarkReceipts/{schoolId}_{id}`,
  TTL ≈ 7 days), return the stored result with `"idempotent": true` and skip the
  push re-fire — mirroring the Phase 6D `attendanceProcessedEvents` contract. If
  absent, behaviour is exactly as today. This is the ONE backend change C1 would
  add; it is deferred until the app is ready to send the key, to keep the
  entangled `Attendance.php` edits minimal now.

---

## 5. Session-pin (roll-over safety)

- Each queued row stores the `session` it was made in. On flush, the app sends
  the current session; if the school has rolled over (server session ≠ queued
  session), the server's session-scoped roster/summary would land the mark in
  the wrong year.
- Rule: if `pending_mark.session` ≠ the app's active session at flush time, the
  row goes `FAILED_TERMINAL` with "this mark was made in a previous session and
  can no longer be submitted; re-enter it if still needed." The server already
  scopes every read/write by `session_year`; the pin prevents a stale queued
  mark from being silently reinterpreted.

---

## 6. Re-auth without losing the queue

- A `401` never drops rows. The worker returns `Result.retry()`, the app raises
  a silent token refresh (existing auth flow), and WorkManager re-runs. If
  refresh fails, rows stay `PENDING` and the teacher is prompted to log in; the
  queue survives the login.
- Auth is Firebase custom-claims (school_id + schoolId dual-emit). The queue
  stores no credentials — only the mark payload.

---

## 7. Conflict handling (teacher-visible)

- **Owner changed the mark meanwhile.** Last-write-wins at the summary (the
  merge write stamps `lastUpdatedBy`/`lastUpdatedAt`). The flushing teacher's
  mark wins if it lands later; the audit records who actually marked. This is
  acceptable for day-grained attendance and matches the current online
  behaviour.
- **Date locked after queueing** → 423 terminal (§3); correction workflow.
- **Coverage revoked after queueing** → 403 terminal (§3).
- The teacher sees a per-row status list ("3 marks waiting to sync · 1 could not
  be sent") so nothing fails silently.

---

## 8. What is testable here vs. blocked

| Piece | Here (no device) |
|---|---|
| Room entity + DAO | Compile + Robolectric/JVM DAO tests |
| Flush result→state mapping | JVM unit tests with a fake endpoint |
| Session-pin / re-auth decision logic | JVM unit tests |
| Server merge-idempotency on replay | **Already proven** (P-over-P no-op; regression suite) |
| Optional `client_mark_id` receipt | Backend unit + emulator E2E (deferred, §4) |
| End-to-end offline→airplane-mode→reconnect flush | **DEVICE-UAT-BLOCKED** |
| WorkManager scheduling under Doze/reboot | **DEVICE-UAT-BLOCKED** |

**Recommendation:** land the Room + worker + JVM tests behind a feature flag
(`offline_attendance`) OFF by default; add the `client_mark_id` server receipt
when the app is wired to send it; gate the flag ON only after on-device UAT
(airplane mode, force-stop, reboot, multi-day offline, roll-over boundary).
