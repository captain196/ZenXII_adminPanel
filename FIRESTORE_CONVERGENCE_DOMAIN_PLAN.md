# Firestore-Only Convergence — Domain Plan (Admin · Parent · Teacher)

**Status:** PLANNING / ANALYSIS ONLY — no implementation / commit / push / merge.
**Date:** 2026-06-12 · **Objective:** complete Firestore-only convergence across all 3 surfaces (no bridge / fallback / dual-read / dual-write), preserving commercial-ERP behavior + performance. Built on the accepted 3-surface RTDB audit.

Legend — Authority: `FS`=Firestore · `RTDB` · `MIXED`(FS-primary + RTDB mirror/fallback) · `RTDB-only`. Migration scope: **Independent** (one surface) vs **Lockstep** (must move ≥2 surfaces together).

---

## DOMAIN INVENTORY (1–14)

### 1. ATTENDANCE
- **FS collections:** `attendance`, `attendanceSummary`, `staffAttendanceSummary`
- **RTDB paths:** `Schools/{code}/{session}/{class}/{sec}/{studentId}/{month}`, `{base}/Attendance/{attKey}`, `.../Staff_Attendance/...`, `Config/Attendance/holidays`, `Config/AttendanceRules`, `Events/Holidays`
- **Admin:** `attendance_helper.php`+`Attendance.php` read/write RTDB month-blobs **and** FS summaries (MIXED)
- **Parent:** **RTDB-only read** `StudentRepository.kt:136/159` (dashboard)
- **Teacher:** marking path + `MarksData`/ServerValue coupling (MIXED→RTDB)
- **Cross-system:** admin/teacher WRITE; parent READS → tightly coupled
- **Current authority:** RTDB (legacy month-string blobs) + partial FS rollup
- **Target authority:** FS `attendance` (daily) + `attendanceSummary` (rollup for fast dashboards)
- **Complexity:** HIGH (legacy month-blob → daily-doc schema redesign) · **Runtime risk:** HIGH (parent dashboard, compliance) · **Latency:** keep `attendanceSummary` rollup to avoid per-day fan-in reads · **Scope:** 🔒 **LOCKSTEP (all 3)**

### 2. LEAVE
- **FS:** `leaveApplications` (student); **no FS home for staff leave yet**
- **RTDB:** Teacher `Schools/{code}/HR/Staff_Leave/Balance|Records/{tid}`; Parent `Schools/{code}/LeaveApplications`; Admin `Hr.php`/`Ats.php`
- **Admin:** `leaveApplications` FS + HR RTDB · **Parent:** FS-first + RTDB fallback · **Teacher:** **RTDB-only** (Staff_Leave)
- **Cross-system:** teacher submits staff leave (RTDB) ↔ admin HR approves
- **Current authority:** split — student leave FS; **staff leave RTDB-only**
- **Target:** FS `leaveApplications` (student) + **new `staffLeave`** (balance+records)
- **Complexity:** MED-HIGH (must build `staffLeave` FS shape) · **Runtime risk:** MED · **Latency:** low · **Scope:** 🔒 LOCKSTEP (admin+teacher); parent largely independent

### 3. STAFF PROFILES
- **FS:** `staff` (canonical, 71 refs)
- **RTDB:** Teacher `Users/Teachers/{code}/{tid}` (read **+ realtime listen**), `Users/Admin/{pk}/{uid}` (fallback)
- **Admin:** writes `staff` FS (authority) · **Parent:** staff lookup for messaging · **Teacher:** reads RTDB `Users/Teachers` + fallback
- **Cross-system:** 🔴 admin FS `staff` vs Teacher RTDB `Users/Teachers` (+ `schoolCode`↔`schoolId` key divergence)
- **Current authority:** FS `staff` (admin) — but Teacher reads RTDB
- **Target:** FS `staff` (+ snapshot listener replacing RTDB listen)
- **Complexity:** LOW-MED (FS already canonical; repoint Teacher reads) · **Runtime risk:** MED (teacher profile/realtime) · **Latency:** low (snapshot listener) · **Scope:** mostly **Teacher-side** (admin already FS) — verify schema parity

### 4. STUDENT PROFILES
- **FS:** `students` (89), `studentHistory`, `studentDiscounts`
- **RTDB:** Parent `Users/Parents/{pk}/{uid}` (fallback, marked Phase-3-removal); Admin `{secRoot}/Students` (MY_Controller read)
- **Admin:** writes `students` FS (authority) · **Parent:** FS-first (`StudentRepository` P0c FS-only) + Auth RTDB fallback · **Teacher:** roster from FS
- **Cross-system:** low — each surface removes its own residual
- **Current authority:** FS `students`
- **Target:** FS `students`
- **Complexity:** LOW · **Runtime risk:** LOW · **Latency:** low · **Scope:** **INDEPENDENT** per surface (remove fallbacks)

### 5. MESSAGING
- **FS:** `conversations`, `messages`, `messageInboxes` (apps); admin `notices` + Messaging_service FS
- **RTDB:** `Schools/{code}/Communication/Messages/{Inbox|Chat|Conversations}/...` (all 3); **RTDB-only:** reactions, message-delete (Parent)
- **Admin:** `Messaging_service`/`Communication_helper`/`Push_service` RTDB + FS (MIXED) · **Parent/Teacher:** FS-first + RTDB mirror/fallback (~10 sites each)
- **Cross-system:** 🔴 shared RTDB comms tree; FS collections parallel; reactions/delete have no FS
- **Current authority:** MIXED (RTDB mirror pervasive)
- **Target:** FS `conversations`/`messages`/`messageInboxes` (+ FS fields for reactions & soft-delete); realtime via snapshot listeners
- **Complexity:** **HIGHEST** (3-surface realtime chat; RTDB-only ops need FS design) · **Runtime risk:** HIGH (live chat) · **Latency:** snapshot listeners acceptable; index inbox/chat · **Scope:** 🔒 LOCKSTEP (all 3)

### 6. TRANSPORT
- **FS:** transport metadata (`routes`/`vehicles`/`studentRoutes` — admin Operations/Transport)
- **RTDB:** `VehicleLive/{code}/{vehicleId}` (live GPS — Parent+Teacher), `SOSAlerts/{code}/{id}` (Teacher write + FS dual)
- **Admin:** FS metadata · **Parent:** **RTDB-only** live GPS observe · **Teacher:** RTDB live GPS + SOS (dual-write FS+RTDB)
- **Cross-system:** GPS/SOS RTDB-only across apps
- **Current authority:** metadata FS; **live GPS/SOS RTDB-only**
- **Target:** FS `liveVehicles` + `sosAlerts` (snapshot listeners) **OR** a formally-approved realtime carve-out
- **Complexity:** MED (high-frequency GPS writes are RTDB's strength) · **Runtime risk:** MED (**SOS is safety-critical**) · **Latency:** ⚠️ HIGH concern — frequent GPS pings → FS write cost/latency may justify an explicit exception · **Scope:** 🔒 LOCKSTEP (Parent+Teacher, +admin if it writes GPS)

### 7. SESSIONS
- **FS:** `schools/{id}.currentSession` + `.sessions[]`, `schoolControl` (admin canonical — SC-Step10 hardened, fail-closed)
- **RTDB:** apps read `Schools/{code}/Config/ActiveSession`
- **Admin:** FS canonical (done) · **Parent/Teacher:** read RTDB `ActiveSession`
- **Cross-system:** 🔴 apps on RTDB ActiveSession vs admin FS currentSession → session divergence (apps may query a different year)
- **Current authority:** admin FS; **apps RTDB**
- **Target:** FS `schools/{id}.currentSession`
- **Complexity:** LOW (admin done; repoint app reads + resolve `schoolCode`→`schoolId`) · **Runtime risk:** MED (session keys ALL queries) · **Latency:** low (cache per session) · **Scope:** **Apps-side** (admin done) — foundational enabler

### 8. SCHOOL CONFIGURATION
- **FS:** `schools` (profile/classes/board), `subjects`, `sections`, `tenantPublic` (activeModules/features), `schoolControl`, `schoolCodeIndex`
- **RTDB:** `Schools/{s}/Config/...` (attendance rules, device config), `Subject_list`, `Indexes/School_codes`/`School_names`
- **Admin:** FS for profile/subjects/sections; residual RTDB `Config/*` + `Subject_list` (`Lms.php:76`, `Sis.php:3853`) · **Apps:** school-code lookup RTDB `Indexes/School_codes`
- **Cross-system:** `schoolCode`↔`schoolId` index (RTDB `Indexes` vs FS `schoolCodeIndex`/`tenantPublic`)
- **Current authority:** mostly FS; residual RTDB
- **Target:** FS `schools`/`subjects`/`sections`/`schoolControl`/`tenantPublic`/`schoolCodeIndex`
- **Complexity:** MED (subjects per SUBJECT-FS plan + small Config readers + index) · **Runtime risk:** LOW-MED · **Latency:** low · **Scope:** mixed (subjects admin-only; school-code index Lockstep with apps)

### 9. EXAMS
- **FS:** `exams`, `examTemplates`, `subjects` (exam-def FS cutover landed)
- **RTDB:** legacy `Schools/{s}/{year}/...` exam paths (`Exam_read`/`Examination`), per-section `Subjects`
- **Admin:** FS-primary (cutover) + residual RTDB read paths · **Teacher:** datesheet read · **Parent:** datesheet read-only
- **Cross-system:** admin authors; teacher+parent read → align read shape
- **Current authority:** FS (cutover) with residual RTDB readers
- **Target:** FS `exams`/`examTemplates`
- **Complexity:** MED (in progress; retire residual RTDB readers) · **Runtime risk:** MED · **Latency:** low · **Scope:** 🔒 LOCKSTEP (read-shape) but admin-led

### 10. RESULTS
- **FS:** `results`, `marks`, `teacherMarks` (teacher)
- **RTDB:** `Schools/{s}/{year}/Results/Marks/{examId}`, Templates, marks `shallow_get`
- **Admin:** `Result.php`/`Exam` RTDB results paths + FS `results`/`marks` · **Teacher:** `teacherMarks` FS (marks entry) + `MarksData`/ServerValue coupling · **Parent:** results read (FS-primary)
- **Cross-system:** 🔴 teacher writes marks → admin computes results → parent reads report card
- **Current authority:** MIXED (FS + legacy RTDB Results/Marks)
- **Target:** FS `marks`/`teacherMarks` → compute → `results`
- **Complexity:** HIGH (marks→results compute pipeline across 3 surfaces) · **Runtime risk:** HIGH (academic record / report cards) · **Latency:** compute can be async/rollup · **Scope:** 🔒 LOCKSTEP (all 3)

### 11. NOTIFICATIONS
- **FS:** `notifBadges`, `presence` (parent), `notices`, FCM via `userDevices`
- **RTDB:** badge/presence init + `setPersistenceEnabled`; admin `Push_service` RTDB `Devices`
- **Admin:** Push_service RTDB Devices + FS · **Apps:** FS badges/presence + RTDB init retained
- **Cross-system:** push fan-out reads device registry (see #12)
- **Current authority:** mostly FS; RTDB init/persistence + device read
- **Target:** FS (`notifBadges`/`presence`/`notices`)
- **Complexity:** LOW-MED · **Runtime risk:** LOW-MED · **Latency:** low · **Scope:** partial (pairs with #12)

### 12. DEVICE REGISTRY
- **FS:** `userDevices`
- **RTDB:** `Users/Devices/{uid}/{deviceId}` (both apps mirror; admin `Push_service` reads RTDB `Devices`)
- **Admin:** reads RTDB Devices for push + FS `userDevices` · **Apps:** FS-first + RTDB mirror
- **Cross-system:** 🔴 admin push reads RTDB Devices → apps cannot drop the RTDB mirror until **admin push repoints to FS `userDevices`**
- **Current authority:** MIXED
- **Target:** FS `userDevices`
- **Complexity:** LOW-MED · **Runtime risk:** MED (push delivery) · **Latency:** low · **Scope:** 🔒 LOCKSTEP (admin push must move first, then apps drop mirror)

### 13. AUTHENTICATION
- **FS:** Firebase Auth (custom claims — canonical), `staff`/`students`/`admins` profiles, `security_events`, `adminAuthState`, `adminIpRateLimit`
- **RTDB:** admin subscription gate `System/Schools/.../subscription/status` + `Users/Schools/...`; `Users/Admin` bcrypt mirror; SSA tenant check; apps `Users/Admin` fallback + `Indexes/School_codes`
- **Admin:** Firebase Auth + FS; subscription gate RTDB (flag-gated B2); `Users/Admin` mirror · **Apps:** Firebase Auth + FS profile, RTDB fallback for profile/school-code
- **Cross-system:** login depends on subscription gate + claim contract (RBAC contract resolved this session)
- **Current authority:** Firebase Auth (claims) canonical; residual RTDB subscription/index/mirror
- **Target:** FS (`schoolControl` subscription, `schoolCodeIndex`, drop `Users/Admin` mirror)
- **Complexity:** MED (subscription flag + claim contract) · **Runtime risk:** **HIGH (login lockout)** · **Latency:** low · **Scope:** partial (admin subscription independent; app school-code Lockstep)

### 14. SUPERADMIN
- **FS:** `schoolControl`, `tenantPublic`, `subscriptions`, `plans`, `superAdmins`, `tenantAudit`, `analyticsRollups`
- **RTDB:** `System/Schools/...`, `Indexes/School_names`, `Indexes/School_codes`; backups read RTDB tree
- **Admin (SA):** `Superadmin_*` read/write `System/Schools` RTDB + FS B2 collections · **Apps:** n/a
- **Cross-system:** none (SA is admin-only)
- **Current authority:** MIXED (B2 FS + legacy `System/Schools` RTDB)
- **Target:** FS B2 (`schoolControl`/`tenantPublic`/`subscriptions`)
- **Complexity:** MED-HIGH (registry breadth + RTDB-tree backups) · **Runtime risk:** MED (SA-only, fewer users) · **Latency:** low · **Scope:** **INDEPENDENT** (admin-only, zero app impact)

---

## A. REMAINING RTDB DOMAINS — RANKED BY BUSINESS RISK (highest first)
1. **Results** — academic-record integrity / report cards (legal record).
2. **Attendance** — compliance + the parent-visible dashboard; stale = trust loss.
3. **Authentication** — login gate; a wrong cut = total lockout.
4. **Transport (SOS)** — safety-critical alerting.
5. **Messaging** — primary parent↔school channel; reactions/delete = data loss.
6. **Sessions** — wrong-session data exposure across surfaces.
7. **Device Registry / Notifications** — push delivery (engagement + alerts).
8. **Leave** — staff/student workflow (Teacher leave entirely RTDB).
9. **Exams** — datesheet correctness.
10. **Staff / Student Profiles** — identity/roster (mostly FS already).
11. **School Configuration** — setup data (mostly FS).
12. **Superadmin** — internal platform ops (no end-user/app exposure).

## B. REMAINING RTDB DOMAINS — RANKED BY MIGRATION DIFFICULTY (hardest first)
1. **Messaging** — 3-surface realtime + RTDB-only ops (reactions/delete) needing new FS design.
2. **Results** — marks→compute→results pipeline across 3 surfaces.
3. **Attendance** — legacy month-blob → daily-doc schema redesign + rollups, 3 surfaces.
4. **Transport** — high-frequency GPS write cost/latency (RTDB's sweet spot) + safety-critical SOS.
5. **Superadmin** — registry breadth + RTDB-tree backups.
6. **Leave** — staff-leave has no FS home (build `staffLeave`) + admin/teacher lockstep.
7. **Authentication** — subscription-gate flag + claim/lockout contract.
8. **Exams** — residual RTDB readers post-cutover; read-shape alignment.
9. **Device Registry** — ordering constraint (admin push must repoint before apps drop mirror).
10. **School Configuration** — several small RTDB readers + `Subject_list` + index.
11. **Sessions** — admin done; apps repoint + `schoolCode→schoolId`.
12. **Staff Profiles** — FS canonical; repoint Teacher reads + realtime listener.
13. **Student Profiles** — FS canonical; remove residual fallbacks.
14. **Notifications** — mostly FS already.

## C. RECOMMENDED EXECUTION ORDER (foundation → independent low-risk → enabling → hard lockstep → realtime → teardown)
**Phase 0 — Foundation (unblocks correct keying everywhere):**
- `schoolCode → schoolId` resolution to FS (`schoolCodeIndex`/`tenantPublic`) + **Sessions** (apps repoint to `schools/{id}.currentSession`). Everything keys on session + schoolId; do this first.

**Phase 1 — Independent, low-risk (build confidence; prerequisites for messaging/results):**
- **Student Profiles** + **Staff Profiles** (FS already canonical → repoint app reads, remove fallbacks, snapshot listener for teacher profile).

**Phase 2 — Enabling (push + auth residuals):**
- **Device Registry** (admin push → FS `userDevices` first, then apps drop RTDB mirror) → **Notifications** → **Authentication** residuals (subscription→`schoolControl`, drop `Users/Admin` mirror, school-code index→FS).

**Phase 3 — School config + exams:**
- **School Configuration** (subjects per SUBJECT-FS-CUTOVER-01 + `Config/*` readers) → **Exams** (retire residual RTDB readers).

**Phase 4 — Hard academic lockstep (highest business risk):**
- **Results** (marks→compute→results FS, 3-surface lockstep) → **Attendance** (schema redesign + rollups, 3-surface lockstep).

**Phase 5 — Workflow lockstep:**
- **Leave** (build `staffLeave` FS; admin+teacher together).

**Phase 6 — Largest realtime:**
- **Messaging** (3-surface FS-only chat; add FS reactions/soft-delete; snapshot listeners).

**Phase 7 — Realtime decision:**
- **Transport GPS/SOS** — migrate to FS realtime listeners, **or** formally ratify a bounded realtime carve-out (document cost/latency tradeoff). Do not block full convergence on an undecided policy here.

**Phase 8 — Admin-only:**
- **Superadmin** registry + backups → FS B2 (independent; can run in parallel anytime after Phase 0).

**Phase 9 — Teardown:**
- Retire the bridge engines (`Dual_write.php`, `Data_service.php`; apps' `DataRepository` RTDB branches, `FirebaseService`, `setPersistenceEnabled`) — only once Phases 0–8 leave **zero callers**. This is the moment the platform becomes truly Firestore-only.

**Cross-cutting rules:** (a) every Lockstep domain moves across all affected surfaces in one coordinated change — never one surface alone; (b) resolve `schoolCode↔schoolId` before any app-side migration; (c) for each RTDB-only domain (staff-leave, transport, reactions/delete) **build the FS shape before** removing RTDB; (d) keep rollups (`attendanceSummary`, results) to preserve dashboard latency; (e) the bridge stays until last — but no NEW bridge/fallback/mirror is added in any phase.

---

*Convergence-planning analysis only. No code modified; no commits/pushes/merges. Collection names + dependencies grounded by grep across all three codebases; this is a snapshot — the branch is under concurrent change, so re-verify per-domain state at execution time.*
