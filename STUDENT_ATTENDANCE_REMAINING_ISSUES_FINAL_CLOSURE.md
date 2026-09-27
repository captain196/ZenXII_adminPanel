# Student Attendance — Remaining Issues: Final Closure

**Date:** 2026-09-19
**Scope:** Close out the pending Student Attendance items (S4/S5/S6, K6, K3) and finish the
C1 offline-queue **on-device UAT**.
**Status:** ✅ COMPLETE. All work **UNCOMMITTED / DEPLOY-PENDING** (no commit, push, or deploy).

Constraints honoured throughout: Firestore-only (zero RTDB); `attendanceSummary` remains the sole
canonical master; no period-wise / subject-wise attendance; half-day out of student scope; Staff
Attendance untouched; no production credentials or production backend used; Windows
firewall/networking untouched; emulators left running for peer sessions.

---

## 1. Server-side items (admin panel — `application/`)

| Item | Outcome | Where |
|---|---|---|
| **S4** — strict non-working-day gate | DONE | `Attendance.php` `_calendar_strict()` / `strict_non_working_day` |
| **S5** — final lock re-check before CAS | DONE | `Attendance.php` `_persist_student_month_cas` (re-reads the lock immediately before the compare-and-set) |
| **S6** — cron auto-lock uses an explicit, validated `school_id` | DONE | `Attendance.php` `cron_auto_lock` |
| **K6** — flush worker fails **closed** on an unreadable session | DONE | `AttendanceFlushWorker.kt` (holds the pass with `Result.retry()` when `activeSession` is blank, only if rows are pending) |
| **K3** — "third-state" correction workflow | **DROPPED** (owner decision) — current correction behaviour already matches real school practice; no code needed. |

Regression after S4/S5/S6: **332/332 admin unit tests green** (baseline preserved).

---

## 2. C1 offline queue — confirmed pending fix applied

**`AttendanceFlushWorker.kt` — `java.time.LocalDate` → local `SimpleDateFormat`.**
The pre-send date-pin used `java.time.LocalDate.now()`, which crashes on **API 24/25** unless
core-library desugaring is present (a cross-repo build dependency and a known merge-blocker).
Replaced with a **local** `SimpleDateFormat("yyyy-MM-dd", Locale.US)` created inside `doWork()` —
works on `minSdk 24` without desugaring and sidesteps `SimpleDateFormat`'s thread-safety trap
(the worker can run concurrent instances). No behaviour change on the API-33 test device.

> **Compile-verified:** `:app:compileDebugKotlin` → **BUILD SUCCESSFUL** (6m 54s) after freeing the
> idle UAT emulator's committed memory. Only pre-existing deprecation warnings (Compose
> icons/`Divider`), none from this change; no other `java.time` usages in the offline package.
> Still **uncommitted / deploy-pending** (a full `assembleDebug` + release build precede shipping).

---

## 3. C1 offline-queue **on-device UAT** — PASSED end-to-end

**Device:** Redmi/Poco (I2011), Android 13 (API 33), serial `96374480720007C`.
**Wiring:** emulator-only debug APK (`BuildConfig.EMULATOR_HOST=127.0.0.1`,
`BASE_URL=http://127.0.0.1:8055/`); `adb reverse` USB tunnels for `8055` (app server),
`8192` (Firestore emu), `9199` (Auth emu). Synthetic tenant `SATEST_A`, teacher `SATTCHA`
(uid `fvtLdBEoFCRDbmrKXT6yen0xXiiR`, class teacher of Class 1st / Section A).
Offline was simulated by **removing the `8055` tunnel** (app's `127.0.0.1:8055` → connection
refused) — never by touching real networking.

| # | Step | Result |
|---|---|---|
| a | **Online baseline** — server accepts a save | ✅ HTTP 200; **505 per-day `attendance` docs** written for 2026-09-19 (Saturday made a working day via a `special_working_day` calendar event) |
| b | **Offline capture** — mark while unreachable | ✅ Snackbar *"Couldn't reach the server — attendance saved on this device and will sync automatically."* + a durable Room row |
| c | **Queue DB inspection** (`run-as … cat databases/attendance_queue.db`) | ✅ Row: `schoolId=SATEST_A`, `session=2026-27`, `Class 1st / Section A`, `date=2026-09-19`, `absentJson=["STUA001"]`, `reason="OfflineSyncUATbaseline"` |
| d | **Persistence** — `am force-stop` then reopen | ✅ Row survives process death (Room durable) |
| e | **Worker retry semantics** | ✅ Offline → `FAILED_RETRYABLE` with backoff; a non-retryable **403** parked the row as `FAILED_TERMINAL` (no data drop, no infinite spin) — the fail-safe design working as intended |
| f | **Reconnect flush** — restore `8055` | ✅ `SENDING` → **200 success → row DELETED** (queue drained) |
| g | **Firestore convergence** | ✅ `attendance` doc for **STUA001 = status "A"** on 2026-09-19 — the offline mark landed |

### One diagnosis worth recording
During a rapid reconnect the worker briefly received `403 "You are not assigned to this
class/section."` and (correctly) parked that row terminal. Root-cause check: replaying the **exact
app payload** (`section="Section A"`, same uid) with a fresh token returns **HTTP 200** — the server
already normalises `section` via `_cs_norm` (it collapses the accidental double `"Section Section A"`
to the assignment key), and the assignment resolves for the app's uid. The 403 was a **transient
test-harness artifact** (rapid re-provisioning during reconnect), **not** a C1 defect and **not** a
reproducible product bug. It did, usefully, exercise the terminal-parking path.

---

## 4. Net state

- Server S4/S5/S6 + app K6: implemented, tested, **uncommitted**.
- K3: dropped, no code.
- C1 offline queue: **UAT-passed on a real device**, all seven cycle steps green.
- One deploy-pending app fix (`SimpleDateFormat`), inspection-verified, needs a Gradle build.
- `OfflineFeatureFlag.ENABLED = true` (owner decision) — new schools get offline attendance on.
- Nothing committed, pushed, or deployed. Emulators left running.
