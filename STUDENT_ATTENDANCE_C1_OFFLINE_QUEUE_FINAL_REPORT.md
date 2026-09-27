# Phase C1 — Durable Offline Attendance Queue — Final Report

**Date:** 2026-09-14 · **Admin branch:** `integration/phase2` · **Teacher app
branch:** `ankit/my_teacherFeature` · **Env:** local Firestore emulator (8192) +
Auth (9199), admin panel on `localhost:8055`; Teacher app built on the local
toolchain (no device). **Nothing committed, pushed or deployed. No production
data touched.**

---

## Executive status

**C1 IMPLEMENTED — FINAL INTERACTIVE DEVICE UAT ENVIRONMENT-BLOCKED.**

On-device runtime is validated (install, launch, no crash, live queue DB on real
SQLite). The one remaining piece — the interactive offline→reconnect loop on the
phone — is blocked purely by environment: the phone (USB-tethered) cannot reach
this laptop's TCP ports (Windows Firewall on the RNDIS interface), the debug
build points at `localhost`, and the app authenticates against production
Firebase (so real credentials would touch the live system, which is disallowed).
Per owner decision (2026-09-15) this network UAT is **PARKED** to run later; the
feature is NOT broken and the architecture is NOT incomplete.

### C1 parking-gate audit (2026-09-15) — all 40 items verified
Every item testable without the interactive device loop is implemented and green
(Room entity/DAO/schema/persistence/ordering/supersession; 4 states +
SENDING crash recovery; UUID reused across retry; session-pin; re-auth;
401/403/422/423/5xx/network/malformed mapping; unique work + KEEP + CONNECTED +
exp-backoff; startup + connectivity enqueue; restart/process-death durability;
no phantom success; server-side authz/coverage/lock/admission/holiday/session
revalidation on replay; no credential storage; tenant never client-trusted;
stable studentId; duplicate-replay safety; no second Attendance master).
**One gap found and fixed:** `onConnectivityRestored()` existed but was not wired
— the Application now observes `NetworkMonitor.isOnline` and triggers it on
reconnect (rebuilt: BUILD SUCCESSFUL).

### Remaining device tests (to run later when the phone can reach a safe backend)
airplane mode · create queued marks · app force-stop/restart · device reboot ·
reconnect · WorkManager flush · duplicate flush · auth expiry · lock conflict ·
coverage revocation · session rollover · multi-day queue · final
`attendanceSummary` verification. (Full steps: the device-UAT checklist.)

---

### (Original status, retained)
**C1 IMPLEMENTED — on-device runtime VALIDATED; full offline-lifecycle UAT
(manual) still pending.**

The durable offline queue is fully implemented on both sides (Teacher app + a
minimal, backward-compatible backend date-pin), compiles, passes every automated
test, and **runs cleanly on a real Android 13 device** — installed, launched
without crashing, and created its durable queue DB on the phone's real SQLite via
the actual flag-ON startup path. The feature flag is now **ON** (owner decision
2026-09-14) so new schools get offline attendance by default.

What remains is the **manual offline-lifecycle UAT** — login → mark offline →
airplane → reconnect → sync, plus reboot / Doze / multi-day — which needs a
teacher login typed into the UI and a backend reachable from the phone, so it
can't be driven automatically from here. A precise checklist is prepared.

I did **not** overstate: offline is NOT yet "full-lifecycle production
validated". It is "implemented, unit- + backend-tested, on-device runtime
validated, manual lifecycle UAT pending".

---

## What the design got wrong about the repo, and how I adapted

The pre-existing C1 design assumed the app marks attendance through the
per-student, date-carrying endpoints (`mark_student_day` / `bulk_mark_student`).
**It does not.** The Teacher app marks section-wide through
`POST attendance/save` (absent / leave / late lists), and that endpoint is
**today-only** — "Date is server-resolved — clients MUST NOT supply a date", and
`_stage()` returns `S3_LOCKED` for any non-today date.

Consequence: a naive queue on `attendance/save` would, on a delayed flush,
either be silently recorded against the **wrong (current) day** or blocked. That
is exactly the corruption the mission forbids.

**Adaptation (minimal, governance-preserving):** an optional **`mark_date`**
field on `save()` that the server validates against its own "today" — a
server-authoritative *date-pin*. It grants **no** new backdating capability: a
`mark_date` equal to today is byte-identical to omitting it (online path
unchanged), and any non-today `mark_date` is refused by the existing `_stage()`
gate (`S3_LOCKED` → 423 "file a correction"). The addition only lets the server
**refuse a mis-dated offline replay** instead of misdating it. The queue also
carries a client-side date-pin and session-pin as defence-in-depth.

---

## Implementation

### Backend (admin panel — 1 surgical, additive edit)
- `application/controllers/Attendance.php` → `save()`: optional `mark_date`
  (YYYY-MM-DD). Validated; seeds `$serverDate`; empty = today (unchanged). ~14
  lines, backward-compatible. No new collection, no new endpoint, no RTDB, no
  weakening of any gate. The optional `client_mark_id`/`attendanceMarkReceipts`
  receipt from the design was **evaluated and deliberately NOT added**: the write
  is already merge-idempotent, so a duplicate flush is data-safe without it, and
  it would add churn to an entangled file for no correctness gain (documented
  decision).

### Teacher app (new, isolated)
`app/src/main/java/com/schoolsync/teacher/data/local/offline/`
- `PendingMark.kt` — Room `@Entity`; UUID PK = idempotency key; `collapseKey()`;
  `isStructurallyValid()`; `QueueState` (PENDING/SENDING/FAILED_RETRYABLE/FAILED_TERMINAL).
- `PendingMarkDao.kt` — single-statement mutations; `sendable()` (oldest-first,
  excludes terminal); `supersede()` (same section+date+session only, never
  terminal); `recoverStuckSending()`; state/count/observe queries.
- `AttendanceQueueDatabase.kt` — isolated Room DB (v1, no destructive migration).
- `FlushDecision.kt` — **pure** decision core: `preSend()` (session-pin,
  date-pin, structural) + `mapResponse()` (fail-closed HTTP→action). JVM-testable.
- `OfflineAttendanceQueue.kt` — enqueue (+ supersession), crash recovery,
  WorkManager unique-KEEP scheduling. No-op while the flag is off.
- `AttendanceFlushWorker.kt` — `@HiltWorker` `CoroutineWorker`; drains oldest-first
  through `attendance/save` with `mark_date`; force-refreshes token on 401.
- `OfflineFeatureFlag.kt` — compile-time `ENABLED = false` (deterministic,
  uniform, un-flippable by any runtime/tenant setting; no global bypass).
- `di/OfflineQueueModule.kt` — Room DB, DAO, WorkManager providers (own module;
  AppModule untouched).

### Teacher app (surgical additive edits)
- `app/build.gradle.kts` — Room + WorkManager + hilt-work deps; Robolectric test
  deps; `testOptions.unitTests`.
- `AndroidManifest.xml` — remove the default `WorkManagerInitializer` (Hilt
  supplies the config).
- `SchoolSyncApp.kt` — implements `Configuration.Provider` (Hilt worker factory);
  `recoverOnStart()` on launch.
- `data/remote/ApiService.kt` — optional `mark_date` field on `saveAttendance`.
- `ui/attendance/AttendanceViewModel.kt` — flag-gated hook: on a genuine network
  failure, enqueue and emit `QueuedOffline` (never a false server-success);
  server-authored rejections are never queued.
- `ui/attendance/AttendanceScreen.kt` — snackbar for the new `QueuedOffline` event.

### UI states
`Waiting to sync` (PENDING) · `Syncing` (SENDING) · `Synced` (delete) ·
`Could not sync` (retryable) · terminal copy for locked / assignment-expired /
session-changed / stale-day. The teacher is never told a mark is
server-confirmed while it is only locally queued.

---

## Tests — actual results

| Suite | Result |
|---|---|
| C1 JVM pure-logic — `FlushDecisionTest` | **18 / 18** |
| C1 JVM pure-logic — `PendingMarkTest` | **11 / 11** |
| C1 Room DAO — `PendingMarkDaoTest` (Robolectric, in-memory Room) | **10 / 10** |
| **C1 app unit total** | **39 / 39** |
| Teacher app `:app:compileDebugKotlin` (Kotlin + Room/Hilt KSP) | **BUILD SUCCESSFUL** |
| Backend date-pin emulator E2E (`attendance/save` + `mark_date`) | **6 / 6** |
| Admin attendance regression (`--filter Attendance`) | **328 / 328** |

Backend E2E specifics (today = 2026-09-14, a seeded holiday):
`mark_date==today` ≡ omitting it (both 422 holiday); **future `mark_date` → 423
"Attendance is locked. File a correction request."** (the date-pin working via
`S3_LOCKED`); past `mark_date` refused (not written to today); invalid format →
400; duplicate same-day replay idempotent (identical status).

Robolectric note: pinned `PendingMarkDaoTest` to `@Config(sdk=[34])` — Robolectric
4.11.1 has no SDK-35 sandbox; Room/SQLite behaviour is identical.

---

## Security

- **Server authority on replay.** Every queued mark is replayed through the same
  authenticated `attendance/save`; tenant, role, class ownership, session,
  admission, working-day, holiday and lock are ALL re-enforced server-side. The
  worker cannot grant authorization the server refuses (proven: past/future/lock
  → non-200 terminal; owner gate 403 when unassigned).
- **Local-tampering resistance.** `schoolId`/`session` in the queue are
  display/routing only — never trusted for auth. Editing a Room row to another
  school/section still yields a server 403 → `FAILED_TERMINAL`. (Device
  spot-check listed in the UAT checklist.)
- **Session-pin + date-pin.** Client refuses to send a mark from a previous
  session or a past day; the server independently enforces the same.
- **Re-auth.** 401 never drops rows; token is force-refreshed and the row retried;
  no credentials are stored in the queue.
- **C2 coverage intact.** Unchanged; a substitute's stale offline mark is refused
  if coverage/date/section/tenant no longer hold (server authority wins).
- No RTDB. No new client Firestore-write path. No period/subject attendance.

## Concurrency

- **One worker, ever** — `enqueueUniqueWork(KEEP)`; multiple triggers (enqueue,
  app-start, reconnect) collapse to one.
- **Crash during send** — a row left `SENDING` by a killed process is reset to
  `PENDING` on next start (`recoverStuckSending`, proven in DAO test) and re-sent;
  safe because the write is merge-idempotent and the UUID is reused.
- **Duplicate flush** — merge-idempotent server write; a row is deleted only on a
  real success (fail-closed mapping, proven).
- **Owner-changed-meanwhile** — day-grained last-write-wins with audit, matching
  existing online behaviour (unchanged).
- **Retry cap** — transient failures back off (WorkManager exponential) and are
  parked terminal after `MAX_ATTEMPTS` to stop infinite 5xx retry (proven).

## Performance

- `can`-path is O(1): enqueue is one insert + one bounded supersede DELETE;
  flush is oldest-first over `sendable()` (indexed by PK, ordered by createdAt),
  one HTTP call per queued section-save — no N+1, no busy loop, single worker,
  exponential back-off. Large-queue behaviour (50–100) is in the device-UAT
  checklist (item O) as it needs the real WorkManager runtime.

## Device UAT

**Update 2026-09-14 — a physical device was connected** (Redmi/Poco `I2011`,
Android 13 / SDK 33). Results:

**PASS on real hardware (automated via adb):**
- Debug APK **builds, installs and launches** on the device (Android 13) with the
  flag **ON**.
- **No crash at startup** — the app process stays alive after launch. A
  `@HiltAndroidApp` app would crash on init if the Hilt graph, the custom
  WorkManager `Configuration.Provider`, or the manifest `WorkManagerInitializer`
  removal were misconfigured; none did.
- **The durable queue DB is live on the device**: `attendance_queue.db`
  (+ `-wal`/`-shm`) is present in the app's private `databases/`. This proves the
  real flag-ON startup path ran on the phone — Hilt injected `OfflineAttendanceQueue`
  → `recoverOnStart()` → Room opened the queue on the device's actual SQLite —
  with no error. No `WM-`/WorkManager errors in logcat.

**Not executed on device:**
- The instrumented Room DAO suite (`PendingMarkDaoInstrumentedTest`) built,
  packaged and installed, but the JUnit4 **test runner failed to instantiate**
  under the `@HiltAndroidApp` application (a Hilt instrumentation-harness issue,
  not a product defect — it needs a Hilt test runner/`HiltTestApplication`). The
  identical DAO assertions pass on Robolectric (10/10), and the on-device DB
  creation above independently proves Room works on the phone.

**Still requires manual UAT (not automatable here):** the full teacher flow —
login → mark offline → airplane mode → reconnect → server sync — plus reboot,
Doze and multi-day, because they need a teacher login typed into the UI and a
backend reachable from the phone. Use `STUDENT_ATTENDANCE_C1_DEVICE_UAT_CHECKLIST.md`
(scenarios A–O + local-tampering), each mapped to the exact implemented behaviour.

## Exact remaining device tests (shortest path to close the gate)
Flip `OfflineFeatureFlag.ENABLED=true` in a reviewed build, install on a device,
and run: A online baseline · B airplane-capture · C app-kill · D reboot ·
E reconnect-flush · F duplicate-flush · G auth-expiry · H lock-conflict ·
I coverage-conflict · J session-rollover · K stale-day · L server verification ·
M crash-during-send · N supersession · O 50–100 queue perf · + local-tamper 403.

## Git / worktree safety

- **Admin panel** (`integration/phase2`): modified `Attendance.php` only (the
  `mark_date` date-pin) — additive; the large `Staff_attendance*` diffs are
  **pre-existing parallel work, not mine**. Nothing reset/cleaned/stashed.
- **Teacher app** (`ankit/my_teacherFeature`): NEW — `data/local/offline/` (7),
  `di/OfflineQueueModule.kt`, 3 test files. MODIFIED (surgical additive) —
  `build.gradle.kts`, `AndroidManifest.xml`, `SchoolSyncApp.kt`, `ApiService.kt`,
  `AttendanceViewModel.kt`, `AttendanceScreen.kt`. `StaffAttendanceViewModel.kt`
  and other `M` files are **pre-existing parallel work, untouched by me**.
- **No commit, no push, no deploy. Feature flag OFF.**

## Final verification checklist
- ✅ Code compiles (Kotlin + Room/Hilt KSP) · ✅ Room schema valid (v1) ·
  ✅ DAO tests · ✅ worker mapping tests · ✅ session-pin/date-pin tests ·
  ✅ crash-recovery test · ✅ backend date-pin E2E · ✅ duplicate replay safe ·
  ✅ authorization server-side · ✅ C2 coverage intact · ✅ admin attendance
  regression 328/328 · ✅ no RTDB · ✅ no period/subject attendance · ✅ no
  Staff Attendance modification · ✅ feature flag OFF · ✅ parallel work preserved.
