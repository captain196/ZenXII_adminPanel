# ZenX ERP — Attendance Module RTDB → Firestore Migration
## Architecture & Migration Design Document (DESIGN ONLY — no code changed)

**Scope:** Eliminate every remaining RTDB dependency in the Attendance module so the whole module is Firestore-only, consistent with the zero-RTDB hard line. **No source code is modified by this document.**
**Source of truth:** `application/controllers/Attendance.php` (91 RTDB call sites, 105 Firestore call sites — the module is ~half-migrated). The new GPS staff-attendance path (`Staff_attendance.php`, `Staff_attendance_writer.php`) and the Analytics page are already Firestore-only.

**Legend (classification used throughout):**
- **[FS]** Existing Firestore implementation (no RTDB).
- **[DUAL]** Dual-write — writes BOTH Firestore and RTDB; RTDB is a removable mirror.
- **[RTDB-PRIMARY]** Writes/reads RTDB as the authoritative store; a Firestore write must be **added** before RTDB is removed.
- **[RTDB-ONLY]** RTDB is the only store; needs a **new Firestore collection designed + built**.
- **[DEAD]** One-shot/maintenance/debug code; retire rather than migrate.
- **[DEBT]** Temporary migration debt (a second, divergent path that should converge).

---

## 1. Complete RTDB Dependency Inventory (91 call sites)

| Function | Lines | Op(s) | RTDB path | Class |
|---|---|---|---|---|
| `debug_push` | 310 | get | `Users/Devices/{userId}` | DEAD (debug) |
| `register_test_token` | 375 | set | `Users/Devices/{userId}/{deviceId}` | DEAD (debug) |
| `cleanup` | 567,573 | get,delete | `…/Attendance/Events/*` | DEAD (cron cleanup) |
| `fix_attendance_keys` | 643-671 | get/set/delete | `…/Attendance/{old→new}`, `…/Late`, `…/Summary` | DEAD (one-shot key fixer) |
| `fetch_audit_logs` | 702 | get | `System/Logs/Attendance/…` | RTDB-ONLY (audit) |
| `dashboard_stats` | — | — | *(RTDB removed earlier)* | FS ✓ |
| `fetch_student_attendance` | 912,925 | get | `{secRoot}/Students`, `…/Attendance/Late/{attKey}` | DUAL (FS attendanceSummary @905) |
| `save_student_attendance` | 1189,1194 | set | `{attPath}`, `{latePath}` | DUAL (FS set attendanceSummary @1160) |
| `mark_student_day` | 1293,1317,1344,1351,1353 | get/set/delete | `{sr}/Students/{id}/Attendance/{attKey}`, `{latePath}` | RTDB-PRIMARY (no FS write) · DEBT vs save_student_attendance |
| `bulk_mark_student` | 1440,1444 | get,set | `{attPath}` | RTDB-PRIMARY |
| `get_student_summary` | 1507 | get | `{basePath}` (Attendance node) | DUAL (FS schoolWhere attendanceSummary @1480) |
| `get_settings` | 2196 | get | `Schools/{school}/Config/Attendance` | RTDB-ONLY (settings) |
| `save_settings` | 2247 | update | `Schools/{school}/Config/Attendance` | RTDB-ONLY (settings) |
| `fetch_devices` | 2463 | get | `Schools/{school}/Config/Devices` | DUAL (FS attendanceDevices exists) |
| `register_device` | 2549,2556,2557 | set | `…/Config/Devices/{id}`, `…/Config/API_Keys/{hash}`, `System/API_Keys/{hash}` | DUAL (FS set attendanceDevices @2529, attendanceDeviceKeys @2539) |
| `update_device` | 2608 | update | `…/Config/Devices/{id}` | DUAL |
| `delete_device` | 2635,2642,2643,2648 | get,delete×3 | devices + API_Keys (school+system) | DUAL |
| `regenerate_key` | 2678,2688,2689,2724,2725,2726 | get/del/set | devices + API_Keys | DUAL |
| `api_punch` | 2795,2824,2889,2904,2909,2948,2958,2963,2983,2995,3006,3061,3089 | get/set/push/update/delete | parent Name, device, events, Punch_Log, attendance, config, System log | DUAL for punch (FS attendancePunches @2939, attendanceDevices @2952) · RTDB-ONLY for events/config/punchlog/system-log |
| `compute_summary` | 3500,3523 | get,set | `{secRoot}/Students`, `{summaryPath}/{csKey}` | DEAD/DEBT (RTDB cached summary builder) |
| `api_get_attendance` | 3782 | get | `{secRoot}/Students` | DUAL (FS schoolWhere attendanceSummary @3788) |
| `api_mark_attendance` | 3957,3961,3969 | get,set | `{attPath}`, `{latePath}` | RTDB-PRIMARY |
| `_stamp_leave_on_attendance` | 4537 | set | `{attPath}` | RTDB-PRIMARY (FS read @4503; FS write missing) |
| `_validate_api_key` | 4677,4683,4715,4724,4733 | get/del/set | rate-limit node, API_Keys lookup | MIXED — key-lookup DUAL (attendanceDeviceKeys), rate-limit RTDB-ONLY |
| `_att_rules` | 5272 | get | `Schools/{school}/Config/AttendanceRules` | RTDB-ONLY (rules) |
| `_find_duplicate_pending` | 5317 | get | pending-requests node | RTDB-ONLY (corrections) |
| `_create_pending_request` | 5352 | push | pending-requests node | RTDB-ONLY (corrections) |
| `approve_attendance_request` | 5383,5389,5447,5471,5541,5561,5625 | get/update/set | request node + `{attPath}` writes | RTDB-ONLY (request) + RTDB-PRIMARY (attendance write) |
| `reject_attendance_request` | 5651,5655 | get,update | request node | RTDB-ONLY (corrections) |
| `list_pending_attendance` | 5672,5681 | get,update | pending-requests node | RTDB-ONLY (corrections) |
| `_fire_single_student_event` | 5843,5847,5882,5935 | get/set | dedup node, parent profile, notif node | MIXED — dedup DUAL (attendanceEventsFired @5836/5929); profile read + notif write RTDB |
| `_fire_student_att_events` | 6015 | get | `{attPath}` | RTDB-PRIMARY (reads student attendance) |
| `_log_attendance_change` | 6352 | set | `System/Logs/Attendance/…` | RTDB-ONLY (audit) |
| `_flush_queue` | 6407 | set | audit queue flush | RTDB-ONLY (audit) |
| `_update_summary_incremental` | 6442,6477 | get,set | `{summaryPath}` (cached summary node) | RTDB-ONLY/DEBT (cached aggregate) |
| `_log_metric` | 6756,6786 | get,set | metrics summary node | RTDB-ONLY (metrics) |

**Firestore collections already in use by the module:** `attendanceSummary`, `staffAttendance`, `staffAttendanceSummary`, `attendancePunches`, `attendanceDevices`, `attendanceDeviceKeys`, `attendanceEventsFired`, `calendarEvents`, `staff`, `students`, `leaveApplications`, `schools`.

---

## 2 & 3 & 4. Subsystems — current state, classification, dependencies, risk

### S1 — Student Attendance (read/write of dayWise + late)
- **Functions:** save_student_attendance, mark_student_day, bulk_mark_student, get_student_summary, fetch_student_attendance, api_get_attendance, api_mark_attendance, _stamp_leave_on_attendance, _fire_student_att_events.
- **RTDB paths:** `{secRoot}/Students/{id}/Attendance/{Month Year}`, `…/Attendance/Late/{Month Year}`, `…/Attendance/Summary/Students/{…}`.
- **Read paths:** Firestore `attendanceSummary` FIRST (save/fetch/get_summary/api_get), then RTDB fallback. mark_student_day reads RTDB only.
- **Write paths:** `save_student_attendance` **[DUAL]** (FS `attendanceSummary` @1160 + RTDB). `mark_student_day`, `bulk_mark_student`, `api_mark_attendance`, `_stamp_leave` **[RTDB-PRIMARY]** (RTDB only — **Firestore write missing**).
- **Firestore collections used:** `attendanceSummary` (docId `{schoolId}_{studentId}_{YYYY-MM}`).
- **Missing schema:** none (collection exists) — but the **single-day / bulk / api / leave write paths must be pointed at it**.
- **Runtime deps:** `_compute_month_stats`, `_resolve_year` (now FS-only), Roster_helper (FS).
- **Cross-module deps:** Analytics (reads `attendanceSummary` ✓), Parent/Teacher apps (read student attendance from Firestore), report cards.
- **Risk:** **Medium** — mixed dual-write + RTDB-primary; the RTDB-primary writes are the migration-debt that must converge on `attendanceSummary` before RTDB removal.
- **Complexity:** Medium. **Order: 1 (first).**

### S2 — Device Attendance (device registry + device punch)
- **Functions:** fetch_devices, register_device, update_device, delete_device, regenerate_key, api_punch (device portions), update_device.
- **RTDB paths:** `Schools/{school}/Config/Devices/{deviceId}`, `…/Attendance/Punch_Log/{date}`.
- **Read/Write:** **[DUAL]** — register/update/delete already write `attendanceDevices` (FS @2529/2952); api_punch writes `attendancePunches` (FS @2939). RTDB device/punch-log writes are **mirrors**.
- **Firestore collections used:** `attendanceDevices` (docId `{schoolId}_{deviceId}` via `fs->docId`), `attendancePunches`.
- **Missing schema:** none for device registry/punches. RTDB **`Punch_Log/{date}`** node + **device "events"** node + **`System/Logs/Attendance`** are RTDB-only side-writes → fold into `attendancePunches` (audit) which already exists.
- **Runtime deps:** `_validate_api_key`, GPS punch shares `attendancePunches`.
- **Cross-module deps:** the dashboard Punch Log (now Firestore-only ✓), GPS attendance audit.
- **Risk:** **Medium** (dual-write removal) + **Low** for the side-logs (already superseded by `attendancePunches`).
- **Complexity:** Medium. **Order: 5.**

### S3 — API Keys (device authentication)
- **Functions:** register_device, delete_device, regenerate_key, _validate_api_key.
- **RTDB paths:** `Schools/{school}/Config/API_Keys/{hash}`, `System/API_Keys/{hash}`.
- **Read/Write:** **[DUAL]** — `attendanceDeviceKeys` (FS @2539) exists; RTDB writes are mirrors. `_validate_api_key` lookup reads both.
- **Firestore collections used:** `attendanceDeviceKeys` (docId = `{keyHash}`).
- **Missing schema:** none — collection exists. The dual System/school RTDB key copies collapse to one Firestore doc.
- **Runtime deps:** `_validate_api_key` rate-limiting (S7).
- **Cross-module deps:** external attendance devices (HTTP API).
- **Risk:** **Medium-High** — key-auth is security-critical; cutover must keep device auth working (read Firestore key, drop RTDB).
- **Complexity:** Medium. **Order: 5 (with S2).**

### S4 — Attendance Corrections + S5 — Pending Requests (same store)
- **Functions:** _create_pending_request, _find_duplicate_pending, approve_attendance_request, reject_attendance_request, list_pending_attendance.
- **RTDB paths:** `Schools/{school}/{session}/Attendance/PendingRequests/*` (push-keyed).
- **Read/Write:** **[RTDB-ONLY]** — push to create, get to list/dedupe, update to approve/reject/expire. (approve also performs an RTDB-PRIMARY attendance write — shares S1.)
- **Firestore collections used:** none.
- **Missing schema:** **NEW `attendanceCorrections` collection required.**
- **Runtime deps:** S1 (the approved correction writes attendance), dashboard "Pending Corrections" card (currently calls `attendance/correction/list`).
- **Cross-module deps:** Teacher app raises correction requests; admin dashboard shows pending count.
- **Risk:** **High** — workflow with create/list/approve/reject/expire; in-flight requests during cutover need a data migration or drain.
- **Complexity:** High. **Order: 3.**

### S6 — Attendance Settings / Rules
- **Functions:** get_settings, save_settings, _att_rules; api_punch reads `…/Config/Attendance`.
- **RTDB paths:** `Schools/{school}/Config/Attendance`, `…/Config/AttendanceRules`.
- **Read/Write:** **[RTDB-ONLY]**.
- **Firestore collections used:** none (note: GPS policy already lives on `schools/{id}.attendancePolicy` — a precedent).
- **Missing schema:** map onto **`schoolControl/{schoolId}`** (or `schools/{id}`) attendance-config map.
- **Runtime deps:** api_punch (consumes config), marking windows.
- **Cross-module deps:** low (admin-configured).
- **Risk:** **Low-Medium**.
- **Complexity:** Low. **Order: 2.**

### S7 — Rate Limiting / Deduplication
- **Functions:** _validate_api_key (rate node), _fire_single_student_event (dedup), api_punch (event dedup).
- **RTDB paths:** rate-limit counter node; `…/Events/*` dedup; `attendanceEventsFired` mirror exists.
- **Read/Write:** dedup **[DUAL]** (`attendanceEventsFired` @5836/5929); rate-limit **[RTDB-ONLY]**.
- **Firestore collections used:** `attendanceEventsFired`.
- **Missing schema:** rate-limiting — Firestore is a poor fit for high-frequency counters; **recommend APCu/file/Redis** (server-local) rather than Firestore, OR a `rateLimits` collection with TTL if cross-instance is required.
- **Runtime deps:** device API throughput.
- **Cross-module deps:** none.
- **Risk:** **Medium** (don't move a hot counter into Firestore naively).
- **Complexity:** Medium. **Order: 6.**

### S8 — Audit / Metrics
- **Functions:** _log_attendance_change, _flush_queue, fetch_audit_logs, _log_metric, _update_summary_incremental.
- **RTDB paths:** `System/Logs/Attendance/{school}/{YYYY-MM}/{logKey}`, metrics summary node, cached summary node.
- **Read/Write:** **[RTDB-ONLY]** (audit/metrics) + **[DEBT]** (`_update_summary_incremental` cached aggregate duplicates `attendanceSummary`).
- **Firestore collections used:** none for audit/metrics.
- **Missing schema:** **NEW `attendanceAuditLog`** (+ optionally `attendanceMetrics`), aligned to the existing `security_events` pattern.
- **Runtime deps:** `_flush_queue` (JSONL local queue → RTDB today).
- **Cross-module deps:** admin audit viewer.
- **Risk:** **Low** (append-only; non-authoritative).
- **Complexity:** Low-Medium. **Order: 4. `_update_summary_incremental` → retire (DEBT) in S1.**

### S9 — Notifications (student attendance events)
- **Functions:** _fire_single_student_event, _fire_student_att_events.
- **RTDB paths:** notif node write `{notifPath}`, parent profile read `Users/Parents/{key}/{studentId}`, student attendance read `{attPath}`.
- **Read/Write:** **[MIXED]** — dedup DUAL (S7); profile read + notif write RTDB.
- **Firestore collections used:** `attendanceEventsFired` (dedup). Notification delivery → the Communication module's Firestore (`pushRequests`/messaging).
- **Missing schema:** route notif writes to the **existing Communication Firestore** (`pushRequests`); read student/parent profile from **`students`** (FS).
- **Runtime deps:** Push_service / Communication module.
- **Cross-module deps:** **Communication module** (the canonical notification surface) + Parent app.
- **Risk:** **Medium** (cross-module — must use Communication's Firestore contract, not invent a new one).
- **Complexity:** Medium. **Order: 4 (with S8).**

### S10 — Dead / Migration-debt code
- **Functions:** debug_push, register_test_token (debug); cleanup, fix_attendance_keys (one-shot maintenance); compute_summary + _update_summary_incremental (RTDB cached-summary builders superseded by `attendanceSummary`).
- **Class:** **[DEAD]/[DEBT]** — **delete or no-op**, do not migrate. Verify zero routes/callers first.
- **Risk:** **Low** (after caller verification). **Order: 0 (do first — shrinks the surface).**

---

## 5 & 6. Target Firestore Architecture (RTDB-only subsystems)

### New: `attendanceCorrections` (S4/S5)
- **Doc ID:** `{schoolId}_{requestId}` (requestId = server-generated ULID/uuid; no RTDB push keys).
- **Fields:** `schoolId, session, type(student|staff), personId, class, section, date, monthKey, requestedBy, requestedAt, status(pending|approved|rejected|expired), reason, decidedBy, decidedAt, payload{}`.
- **Indexes:** `(schoolId ASC, status ASC, requestedAt DESC)` (list pending), `(schoolId ASC, type ASC, personId ASC, date ASC)` (dedupe).
- **Read patterns:** list pending by school+status; dedupe by person+date+type.
- **Write patterns:** create (set), decide (update status), expire (batch update).
- **Security:** rules — staff create own (`requestedBy == uid`), admin same-school read/update, client `write:false` for status fields (server-only via Admin SDK).
- **Consumers:** admin dashboard "Pending Corrections", Teacher app correction requests.
- **Performance:** low volume; status+time index sufficient.

### New: `attendanceAuditLog` (S8)
- **Doc ID:** `{schoolId}_{YYYYMM}_{ulid}` (append-only).
- **Fields:** `schoolId, action, personType, personId, date, before, after, actor, ip, ts`.
- **Indexes:** `(schoolId ASC, ts DESC)`, optional `(schoolId, personId, ts DESC)`.
- **Read:** admin audit viewer (paged by school+ts). **Write:** append (set). **Security:** client `write:false`, admin same-school read. **Consumers:** audit viewer. **Performance:** append-only; monthly doc-id prefix bounds hot ranges.

### Attendance settings → `schoolControl/{schoolId}` (S6)
- **Map field:** `attendanceConfig{ windows, lateThreshold, gracePeriod, deviceMode, rules{…} }` (mirrors GPS `attendancePolicy` precedent).
- **Read:** admin settings page + api_punch. **Write:** admin save (merge). **Security:** admin-only write; same-school read. **No new collection.**

### Rate limiting (S7) — NOT Firestore
- **Recommendation:** server-local **APCu** (or file/Redis) counter keyed `rl:{keyHash}:{minute}`. Firestore counters are cost/latency-hostile for hot paths. If cross-instance limiting is mandatory, a short-TTL `rateLimits` collection — but prefer infra-level limiting.

### Notifications (S9) → existing Communication Firestore
- **Reuse `pushRequests`** (Communication module contract). Attendance fires a `pushRequests` doc; Push_service delivers. Profile reads from `students`. **No new attendance notification collection.**

### Existing collections (S1/S2/S3) — converge, don't create
- `attendanceSummary` (S1): point mark_student_day / bulk / api_mark / _stamp_leave at it (CAS or read-modify-write on the month doc).
- `attendanceDevices`, `attendanceDeviceKeys`, `attendancePunches` (S2/S3): already exist — drop the RTDB mirrors after confirming reads use Firestore.

---

## 7. Dependency Graph (what can move independently)

```
S10 Dead/Debt ───────────────► (independent, do FIRST — removes ~10 calls)

S6 Settings ─────────────────► (independent) ──┐
S8 Audit/Metrics ────────────► (independent)   │
                                                ├─► consumed by
S1 Student Attendance ───────► (semi-independent; api_punch + corrections write into it)
        ▲                                       │
        │ approved correction writes attendance │
S4/S5 Corrections/Pending ───► depends on S1 ───┘

S3 API Keys ──┐
              ├─► both feed ─► S2 Device Attendance  (migrate S2+S3 TOGETHER)
S7 RateLimit ─┘                         │
                                        └─► api_punch also writes S1 (attendance) + S9 (notify)

S9 Notifications ───► depends on Communication module (cross-module contract)
```

**Independent (any order):** S10, S6, S8.
**Must precede others:** S1 (corrections S4/S5 and device punch S2 write into `attendanceSummary`).
**Migrate together:** **S2 + S3** (device punch needs key-auth); **S4 + S5** (same store).
**Cross-module gated:** **S9** (use Communication's Firestore contract).

---

## 8. Phased Migration Plan (risk-minimizing order)

| Phase | Subsystem(s) | Why here | Risk | Verify-after gate |
|---|---|---|---|---|
| **0** | S10 Dead/Debt | Removes ~10 calls; verify zero callers/routes first | Low | grep callers = 0; routes absent; lint |
| **1** | S1 Student Attendance | Foundation — corrections + device punch write into `attendanceSummary`. Add FS writes to mark_student_day/bulk/api_mark/_stamp_leave; remove RTDB | Medium | mark a day (single+bulk+api) → `attendanceSummary` updated; Analytics + report card read correct; no RTDB write |
| **2** | S6 Settings/Rules | Independent; small; precedent exists (`attendancePolicy`) | Low | save/load settings round-trip from `schoolControl`; api_punch reads config |
| **3** | S4+S5 Corrections/Pending | New `attendanceCorrections`; needs in-flight drain | High | create→list→approve(writes attendance via S1)→reject→expire; dashboard count; Teacher app |
| **4** | S8 Audit/Metrics + S9 Notifications | Append-only + cross-module via Communication | Low-Med | audit viewer reads `attendanceAuditLog`; a student-event fires a `pushRequests` doc |
| **5** | S2+S3 Device Attendance + API Keys | Highest-risk (external devices, security); dual-write already exists so it's mirror-removal + cutover of key-auth/punch-log to FS | Med-High | device register/update/delete via `attendanceDevices`; api_punch auth via `attendanceDeviceKeys`; punch lands in `attendancePunches`; no RTDB |
| **6** | S7 Rate Limiting/Dedup | Move counter to APCu/Redis (not FS); dedup already FS | Medium | device flood test → throttled; dedup via `attendanceEventsFired` |

**Global rules per phase:** (a) add/confirm the Firestore path FIRST, (b) run the phase's verification gate, (c) only then remove the RTDB code, (d) re-run the module RTDB sweep (`grep firebase->` in scope = 0), (e) stop for approval before the next phase. No data deletion; no commit/push/deploy.

**Data-migration note:** S4/S5 (in-flight pending requests) and S8 (historical audit) may need a **one-time backfill** RTDB→Firestore — to be designed as a read-only export per phase, executed only on explicit approval.

---

## Open verification items before Phase 1 (to confirm at implementation, no code now)
1. Confirm `mark_student_day` / `bulk_mark_student` / `api_mark_attendance` have **no** Firestore write today (inventory shows none) → they are the S1 debt to converge.
2. Confirm the exact RTDB `PendingRequests` path + the dashboard `attendance/correction/list` consumer contract (S4/S5).
3. Confirm Communication module's `pushRequests` contract for S9.
4. Confirm callers/routes for S10 functions are truly zero before deletion.

*No source code, data, or deployment was changed by this document. Awaiting approval of this design before any Stage/Phase execution.*
