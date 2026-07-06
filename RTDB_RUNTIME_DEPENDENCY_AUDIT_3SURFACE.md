# RTDB Runtime-Dependency Audit — 3 Surfaces (Admin · Parent · Teacher)

**Status:** AUDIT ONLY — no code changes / commits / pushes / migration.
**Date:** 2026-06-11 · **Policy under review:** ZERO-RTDB hard line (Firestore sole runtime authority; NO dual-read/dual-write/bridge/fallback/mirror).
**Surfaces:** Admin `C:\xampp\htdocs\ZenX\school` · Parent `D:\Projects\SchoolSyncParent` · Teacher `D:\Projects\SchoolSyncTeacher`.

> Counts grounded by direct grep. Admin figures are **call-site counts** (include the dual-write bridge internals), not deduplicated logical operations — deliberately not inflated into "reads"; method split noted. App figures are wrapper-call sites corroborated by marker greps.

---

## HEADLINE VERDICT
**All three surfaces grossly violate the ZERO-RTDB hard line.** This is not residual debt — it is an *active, deliberate Firestore-first + RTDB-mirror/fallback BRIDGE architecture*, i.e. exactly the dual-write / dual-read / bridge / fallback patterns the policy forbids:
- **Admin:** ~**1037** RTDB call-sites across ~**40 runtime files** (only 7 in named tooling). Bridge engine: `Dual_write.php`, `Data_service.php`.
- **Parent app:** ~**43** runtime RTDB ops in 5 files; generic bridge in `DataRepository.kt` + RTDB wrapper `FirebaseService.kt`.
- **Teacher app:** ~**34** runtime RTDB ops in ~8 files; same bridge shape; **Leave is RTDB-only** (no Firestore at all).

Binary policy compliance (zero RTDB) → **FAIL on all three.**

---

## A. RTDB RUNTIME INVENTORY (representative, domain-grouped; grounded file:line)

### A.1 ADMIN (PHP) — ~1037 call-sites; tooling split = 7
RTDB markers: `$this->firebase->get|set|update|delete|shallow_get|push(` + `$this->CM->get_data(`. (Firestore `firestoreGet/Query/Set/...` and `$this->fs->` excluded.)

| File:line | Method | RTDB path | Class | Domain |
|---|---|---|---|---|
| core/MY_Controller.php:274,276 | `_check_subscription` | `System/Schools/{id}/subscription/status`, `Users/Schools/{id}/subscription/status` | RUNTIME READ | admin/auth/session |
| core/MY_Controller.php:~1298 | section students | `{secRoot}/Students` | RUNTIME READ | students/SIS |
| helpers/attendance_helper.php:38,53,157,183,210 | holidays/att str/rules | `Schools/{s}/Events/Holidays`, `.../Config/Attendance/...`, `{base}/Attendance/{attKey}`, `.../Staff_Attendance/...` | RUNTIME READ | attendance |
| libraries/Exam_engine.php:507 | `get_subject_list` | `Schools/{s}/Subject_list/{k}` | DEAD (0 callers — Exam migrated) | subjects |
| controllers/Lms.php:76 | `get_classes_subjects` | `Schools/{s}/Subject_list` | RUNTIME READ | subjects |
| controllers/Sis.php:3853 | `get_additional_subjects` | `Schools/{s}/Subject_list/{k}` | RUNTIME READ (fallback) | subjects |
| libraries/Messaging_service.php, Push_service.php, Communication_helper.php | many | `Schools/{code}/Communication/Messages/...`, `Users/Devices/...` | RUNTIME R/W | communication |
| controllers/Exam.php, Exam_read.php, Result.php, Examination.php | many | `Schools/{s}/{year}/Results/...`, `.../Marks/...`, per-section `Subjects` | RUNTIME R/W | exams/results/marks |
| libraries/Dual_write.php, Data_service.php | set/update/get | caller-supplied RTDB mirror paths | RUNTIME R/W (**BRIDGE engine**) | all |
| controllers/AdminUsers.php (reset_password/change_my_password) + Ssa_reset.php | set/get | `Users/Admin/{code}/{id}` | RUNTIME WRITE/READ (mirror + SSA tenant check) | admin/auth |
| controllers/Superadmin_*.php, MY_Superadmin_Controller.php | many | `System/Schools/...`, `Indexes/...` | RUNTIME R/W | SA platform |
| controllers/Attendance.php | get/set | `Schools/{s}/{session}/.../Attendance/...`, device/event paths | RUNTIME R/W | attendance |
| controllers/Hr.php, Ats.php, Operations.php, Assets.php, Certificates.php, Stories.php, Device_management.php | various | RTDB paths | RUNTIME R/W | HR/leave, operations, certificates, stories, devices |
| controllers/School_backup.php, Backup_cron.php, Superadmin_backups.php, libraries/Backup_service.php | get | `Schools/...` (full-tree export) | RUNTIME READ (backup) | backups |

**Tooling (excluded from runtime count):** 7 call-sites in `*_verify` / `*_probe` etc. (CLI). Plus `Exam_engine::get_subject_list` (:507) is **dead** (0 callers post exam-FS cutover — confirmed in prior SUBJECT-FS audit) and `Data_service` `'subjects'` mapping is **dead**.

### A.2 PARENT app — ~43 runtime RTDB ops (5 files)
RTDB wrapper: `FirebaseService.kt` (DatabaseReference engine) · `SchoolSyncApp.kt:25` `setPersistenceEnabled(true)`.
| File:line | Op | RTDB path | Class | Domain |
|---|---|---|---|---|
| MessageRepository.kt:97,158,197,251 | readChildren | `Schools/{code}/Communication/Messages/Inbox|Chat/...` | RUNTIME READ (fallback) | communication |
| MessageRepository.kt:301,346,442,485,557,660,759,817,899,947 | update/write/null | same comms paths | RUNTIME WRITE/DELETE (mirror) | communication |
| MessageRepository.kt:736 | write reaction | `.../Chat/{c}/{m}/reaction` | RUNTIME WRITE (**RTDB-only**) | communication |
| AuthRepository.kt:224 | readMap | `Users/Parents/{pk}/{uid}` | RUNTIME READ (fallback, Phase-3-removal) | profile |
| AuthRepository.kt:366 | updateChildren | `Users/Devices/{uid}/{dev}` | RUNTIME WRITE (mirror) | FCM/devices |
| AuthRepository.kt:395,401 | readString | `Schools/{id}` code, `Schools/{code}/Config/ActiveSession` | RUNTIME READ (**RTDB-only**) | school config/session |
| StudentRepository.kt:136,159 | readString | `Schools/{code}/{session}/{class}/{sec}/{studentId}/{month}` | RUNTIME READ (**RTDB-only**) | attendance |
| LeaveFirestoreRepository.kt:90,127 | write/readMap | `Schools/{code}/LeaveApplications/...` | RUNTIME WRITE/READ (fallback) | leave |
| TransportFirestoreRepository.kt:109 | observeMap | `VehicleLive/{code}/{vehicleId}` | RUNTIME READ realtime (**RTDB-only**) | transport GPS |
| DataRepository.kt:55,94,134,156 | get/query/set/null | caller-supplied | RUNTIME R/W/DEL (**generic BRIDGE**) | all |

### A.3 TEACHER app — ~34 runtime RTDB ops (~8 files)
RTDB wrapper: `FirebaseService.kt` · `SchoolSyncApp.kt:15` persistence · `AppModule.kt:66,79` DI. Models import `ServerValue` (LeaveRequest/MarksData/Message) — RTDB type coupling.
| File:line | Op | RTDB path | Class | Domain |
|---|---|---|---|---|
| MessagesRepository.kt:111,195,315,343,381,421,470,492,517,587 | set/update | `Schools/{code}/Communication/Messages/...` | RUNTIME WRITE/DELETE (mirror) | communication |
| MessagesRepository.kt:146,537 | readSnapshot | inbox/conversation | RUNTIME READ (fallback) | communication |
| LeaveRepository.kt:31,55,79 | read/read/push | `Schools/{code}/HR/Staff_Leave/Balance|Records/{tid}` | RUNTIME R/W (**RTDB-ONLY — no Firestore**) | leave |
| TeacherRepository.kt:42,60 | readValue/listen | `Users/Teachers/{code}/{tid}` | RUNTIME READ + LISTEN | staff profile |
| TeacherRepository.kt:178 | readSnapshot | `Schools/{code}/{session}/Academic/Subject_Assignments` | RUNTIME READ (fallback) | assignments |
| AuthRepository.kt:176,198 | read | `Users/Admin/{pk}/{uid}`, `Indexes/School_codes/{id}` | RUNTIME READ (fallback) | profile/index |
| AuthRepository.kt:270 | setValue | `Users/Devices/{uid}/{dev}` | RUNTIME WRITE (mirror) | FCM/devices |
| TransportFirestoreRepository.kt:149,236 | listen / setValue | `VehicleLive/{code}/{v}`, `SOSAlerts/{code}/{id}` | RUNTIME READ realtime / WRITE (**RTDB-only + SOS dual-write**) | transport |
| OfflineQueueManager.kt:48,49 | set/update | variable | RUNTIME WRITE | offline queue |
| DataRepository.kt:45,113,126 | get/set/null | caller-supplied | RUNTIME R/W/DEL (**generic BRIDGE**) | all |

---

## B. CROSS-SYSTEM DOMAIN MATRIX
`FS` = Firestore-only · `RTDB` = RTDB-runtime · `MIXED` = Firestore-primary + RTDB mirror/fallback · `n/a` = surface doesn't use domain.

| Domain | Admin | Parent | Teacher | Dangerous mismatch |
|---|---|---|---|---|
| Communication / chat / inbox | MIXED (Messaging_service/Comm_helper RTDB+FS) | MIXED (+ RTDB-only reactions/delete) | MIXED | Shared RTDB `Schools/{code}/Communication/...`; FS collections also written → divergence if one side reads RTDB while other writes FS |
| Attendance | RTDB (attendance_helper) | **RTDB-only read** | (marks/att via models) | **🔴 Parent reads attendance from RTDB; admin attendance partly RTDB — if any writer moves to FS, Parent shows stale/empty** |
| Leave / HR | RTDB (Hr/Ats) | MIXED (LeaveApplications fallback) | **RTDB-only** (Staff_Leave) | **🔴 Teacher leave is RTDB-only; no Firestore — removing RTDB breaks Teacher leave entirely** |
| Transport GPS / SOS | FS (Operations/Transport metadata) | **RTDB-only** (VehicleLive) | **RTDB-only** (VehicleLive + SOSAlerts) | Live GPS/SOS has **no Firestore equivalent** on any surface |
| Staff / teacher profile | FS (`staff` collection) | profile fallback (`Users/Parents`) | **RTDB read+listen** (`Users/Teachers`) | **🔴 Admin writes `staff` (FS); Teacher reads `Users/Teachers` (RTDB) — schema + path divergence (`schoolId` vs `schoolCode`)** |
| Subjects | RTDB (Lms `Subject_list`) + FS (`subjects`) | n/a | fallback (`Subject_Assignments` RTDB) | Admin School_config writes FS `subjects`; LMS reads orphaned RTDB `Subject_list` (see SUBJECT-FS audit) |
| Exams / results / marks | MIXED (Exam_read/Result RTDB paths + FS) | results read (FS-primary) | marks entry (FS + ServerValue model coupling) | exam-FS cutover in progress; verify all 3 aligned on FS results shape |
| Devices / FCM | RTDB (Push_service Devices) | mirror (`Users/Devices`) | mirror (`Users/Devices`) | dual-write mirror across all three |
| Admin / auth / session | RTDB (subscription read, `Users/Admin` mirror, SSA tenant check) | RTDB (`Config/ActiveSession`, school-code) | RTDB (`Users/Admin` fallback, `School_codes` index) | session/active-session read from RTDB on apps vs `schools/{id}.currentSession` (FS) on admin → divergence |
| SA platform (System/Schools, Indexes) | RTDB | n/a | n/a | SA registry still RTDB despite B2/Firestore intent |
| Backups | RTDB (full-tree export) | n/a | n/a | backup reads RTDB tree (runtime-triggered utility) |

---

## C. ANTI-PATTERN FINDINGS
- **dual-write (FS + RTDB mirror):** Admin `Dual_write.php` / `Data_service.php` (engine); Parent `DataRepository.kt:122-141`, `MessageRepository.kt` (10 sites), `AuthRepository.kt:366`; Teacher `DataRepository.kt:95-121`, `MessagesRepository.kt` (8 sites), `TransportFirestoreRepository.kt:236` (SOS), `AuthRepository.kt:270`.
- **dual-read (FS → RTDB fallback):** Admin `Sis.php:3853`, `Data_service.php`; Parent `MessageRepository.kt:70-103/174-203/832-843`, `AuthRepository.kt:167-224`, `DataRepository.kt:36-106`; Teacher `MessagesRepository.kt:128-155/208-238/526-544`, `TeacherRepository.kt:80-207`, `AuthRepository.kt:160-198`, `DataRepository.kt:28-93`.
- **bridge/sync:** Admin `Dual_write.php` (RTDB↔FS sync engine) + `Firestore_retry_queue.php`; apps `DataRepository`/`FirebaseService` generic bridge.
- **fallback (try FS, catch→RTDB):** pervasive in both apps' repositories (above) and admin `Data_service`.
- **RTDB-only (no FS equivalent — would break on RTDB removal):** Parent reactions (`MessageRepository.kt:736`), attendance (`StudentRepository.kt:136/159`), transport GPS (`:109`), school config/session (`AuthRepository.kt:395/401`); Teacher leave (`LeaveRepository.kt:31/55/79`), transport GPS/SOS (`:149/236`), offline queue.

**"None found" applies to:** nowhere — every surface exhibits multiple forbidden patterns.

---

## D. FIRESTORE-ONLY COMPLIANCE SCORE
Two metrics: **(i) binary policy compliance** (zero RTDB) and **(ii) approx. share of runtime data access already Firestore-primary**.

| Surface | Binary ZERO-RTDB | Approx. FS-primary coverage | Notes |
|---|---|---|---|
| Admin | ❌ FAIL | **~55%** | FS-primary for RBAC, school profile, sessions(SC-Step10), subjects(`subjects`), homework, PTM, staff; but ~1037 RTDB call-sites remain (comms, attendance, exams/results, HR, SA platform, backups, bridge engine). |
| Parent | ❌ FAIL | **~70%** | Most domains FS-primary with RTDB fallback/mirror; RTDB-only: attendance, transport, school-config, reactions. |
| Teacher | ❌ FAIL | **~65%** | FS-primary for assignments/homework/comms metadata; RTDB-only: **leave (entirely)**, transport GPS/SOS; RTDB profile read+listen. |
| **Overall** | ❌ **FAIL (0/3 compliant)** | **~63%** | Coverage % are estimates from call-site/domain distribution, not exact; the *bridge architecture itself* is the core violation regardless of %. |

---

## E. REMAINING-RTDB MIGRATION PLAN (smallest-risk first)
1. **Delete dead admin RTDB code** (zero-risk): `Exam_engine::get_subject_list` (:507, no callers), `Data_service` `'subjects'` mapping. *Surfaces:* admin. No FS work.
2. **Subjects → FS** (`Lms.php:76`, `Sis.php:3853`): canonical `subjects` collection (`{schoolId}_{classKey}_{subjectCode}`). *Surfaces:* admin. Index: `(schoolId, classKey)`. (Per SUBJECT-FS-CUTOVER-01.)
3. **Profiles → FS, single contract:** Teacher reads `Users/Teachers` (RTDB) but admin writes `staff` (FS). Migrate Teacher/Parent profile reads to FS `staff`/`students`; **coordination:** doc-id `{schoolId}_{id}` (FS) vs `schoolCode` keyed RTDB — apps must switch `schoolCode`→`schoolId`. *Surfaces:* all 3.
4. **Active session / school-code → FS:** apps read `Config/ActiveSession` + `School_codes` index (RTDB); admin canonical is `schools/{id}.currentSession` (FS) + `schoolCodeIndex`. Migrate app reads to FS. *Surfaces:* all 3. (Pairs with SC-Step10/11.)
5. **Attendance → FS:** define canonical FS attendance shape; migrate admin `attendance_helper` writers + Parent `StudentRepository` reads + Teacher together (lockstep — Parent reads what admin/teacher write). Index by `(schoolId, studentId, session, month)`. *Surfaces:* all 3. **High coordination.**
6. **Leave/HR → FS:** Teacher leave is RTDB-only (`Staff_Leave`); admin HR (`Hr.php`) RTDB. Create FS `staffLeave` (balance + records); migrate Teacher + admin together. *Surfaces:* admin + Teacher.
7. **Communication/messaging → FS-only:** retire the RTDB mirror/fallback in all 3 (`Messaging_service`/`Communication_helper`/`Push_service` admin; `MessageRepository`/`MessagesRepository` apps); add FS equivalents for RTDB-only ops (**reactions, message-delete**). *Surfaces:* all 3. **Largest; do last.** Indexes for inbox/chat queries `(schoolId, participantId, updatedAt)`.
8. **Transport GPS/SOS:** decide policy — live GPS is inherently realtime. Either migrate to FS realtime listeners (`liveVehicles`, `sosAlerts`) on all 3, or formally carve out as an approved realtime exception. *Surfaces:* Parent + Teacher (+ admin metadata).
9. **SA platform (`System/Schools`, `Indexes`) + backups:** migrate SA registry reads to `schoolControl`/`tenantPublic` (FS); backups to a FS export. *Surfaces:* admin.
10. **Retire the bridge engines** (`Dual_write.php`, `Data_service.php`; apps `DataRepository` RTDB branches, `FirebaseService`, `setPersistenceEnabled`): only after 1–9 leave zero callers. *Surfaces:* all 3.

---

## F. RISKS
- **🔴 Cross-surface lockstep (the dominant risk):** attendance, leave, profiles, sessions, and messaging are read on one surface and written on another. Removing RTDB on a writer **before** its readers migrate (or vice-versa) **breaks the other surface** — Parent attendance/profile, Teacher leave/profile are the sharpest. Migrate per-domain across **all three at once**, never one surface alone.
- **🔴 Schema/key divergence:** apps key by **`schoolCode`** (numeric) on legacy RTDB paths; admin FS keys by **`schoolId`** (`SCH_*`). Any FS migration must reconcile this (resolve `schoolCode`→`schoolId` index) or apps query the wrong docs.
- **🟠 RTDB-only data with no FS home:** Teacher leave, transport GPS/SOS, message reactions, Parent attendance, school-code/active-session lookups — these will **lose data/feature** if RTDB is removed before a FS equivalent exists. Must build FS shape first.
- **🟠 Orphan/zombie docs & stale state:** the dual-write mirrors can already diverge (best-effort RTDB writes after FS); e.g. admin writes FS `subjects` but LMS reads orphaned RTDB `Subject_list` (already stale). Every mirror/fallback is a latent staleness bug.
- **🟠 Session-isolation:** apps resolve active session from RTDB `Config/ActiveSession` while admin canonical is `schools/{id}.currentSession` (FS, just hardened in SC-Step10) — apps can act on a different session than admin.
- **🟢 Bridge engines mask the count:** `Dual_write.php`/`DataRepository` make RTDB usage look "managed," but they ARE the forbidden bridge; the policy requires their removal, not their continuation.

---

## NOTE ON POLICY vs STATE
The ZERO-RTDB hard line (memory `feedback_no_rtdb_ever`, escalated 2026-06-09) is the **target**; this audit shows the **current state is far from it** on all three surfaces. The codebases contain explicit phased-migration comments (Phases 5–12) describing the bridge as intentional/temporary — but under the hard line, **fallback + mirror + bridge are themselves non-compliant**, so "Firestore-first with RTDB mirror" does not satisfy the policy.

*Audit only. No code modified; no commits/pushes. Counts grounded by grep (admin ~1037 RTDB call-sites incl. bridge internals; Parent ~43; Teacher ~34); representative file:line citations provided per domain — full per-line enumeration of the admin surface (~1037 sites) is available on request but omitted here to avoid a thousand-row dump.*
