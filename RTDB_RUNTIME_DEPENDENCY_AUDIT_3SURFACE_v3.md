# RTDB Runtime-Dependency Audit — 3 Surfaces — v3 (2026-06-15)

**Status:** AUDIT ONLY. Supersedes v2 (06-12). Re-grounded by grep; corrects a v2 error.
**Policy:** Firestore sole runtime authority; NO RTDB runtime / dual-write / dual-read / bridge / fallback / mirror.

## DELTA SINCE v2 (06-12)
- **Admin raw RTDB call-sites: ~833 → ~809.**
- **⚠️ v2 CORRECTION:** v2 claimed `Dual_write` was DEAD. **Wrong** — that was a bad negative-lookahead regex. `Dual_write` is **ACTIVE**: 7 runtime call-sites, all in `Sis.php` (student-roster). `Data_service` **is** dead (its only "callers" are docblock examples lines 17-21).
- **🟢 Both apps migrated MESSAGING off RTDB:** Parent `MessageRepository.kt` and Teacher `MessagesRepository.kt`/`Message.kt` no longer import `firebase.database`. App RTDB wrapper-calls: **Parent ~43→13, Teacher ~34→15.**
- Structural verdict unchanged: **0/3 surfaces compliant.**

---

## A. RTDB RUNTIME INVENTORY

### A.1 ADMIN — ~809 raw call-sites; ~63 controllers + libs (52 migration controllers excluded)
Per-file aggregate (method counts ≈ v2; domain):
| File | RTDB ops | Domain | Notes |
|---|---|---|---|
| controllers/Attendance.php | ~131 | attendance, devices, API keys | :2451-2452 API-key dual-write System+Schools; :405 best-effort mirror catch; _migrate_cron_att ~:667-695 |
| controllers/Lms.php | ~68 | homework/LMS, subjects | :76 `get Subject_list` (RTDB) |
| controllers/Ats.php | ~26 | HR/recruitment | |
| controllers/Assets.php | ~24 | operations | |
| controllers/Sis.php | ~4 direct + **7 via Dual_write** | students/roster | **`->dw->batchMoveStudents/add/removeFromRoster/hard|softDeleteStudent` (:1033/1537/1757/1854/4837/4838/4886) = dual-write RTDB+FS** |
| controllers/Result.php | ~14 | results/marks | `Results/Marks/{examId}`, Templates |
| controllers/Hr.php | ~13 | leave/HR | `HR/Staff_Leave/...` |
| controllers/Fees.php | ~10 | fees | |
| controllers/Schools.php | ~17 | school config / SA index | `CM->*_data Indexes/School_codes` |
| controllers/Superadmin*.php, MY_Superadmin_Controller.php | many | SA platform | `System/Schools`, `Indexes` |
| Backup_cron/School_backup/Superadmin_backups + lib/Backup_service.php | ~13 | backups | full-tree export |
| core/MY_Controller.php | 2 | auth/session | :274/:276 subscription status read |
| helpers/attendance_helper.php | ~6 | attendance | holidays/rules/att-str |
| libraries/Messaging_service.php, Communication_helper.php, Push_service.php | many | messaging, devices | `Schools/{code}/Communication/...`, `Users/Devices` |
| libraries/Otp_service.php, Device_service.php, Exam_engine.php, Exam_read.php, Fee_transaction.php, Fee_integrity_checker.php | various | auth, devices, exams, fees | Exam_engine::get_subject_list :507 = DEAD (0 callers) |
| **libraries/Dual_write.php** | engine | students/roster | **ACTIVE** (called by Sis.php — 7 sites) — dual-writes RTDB + Firestore |
| **libraries/Data_service.php** | engine | — | **DEAD** (0 real callers; docblock-only) |

### A.2 PARENT app — 13 RTDB wrapper-calls (messaging removed since v2)
RTDB imports now confined to `FirebaseService.kt` (wrapper) + `SchoolSyncApp.kt` (persistence).
| File:caller | Op | Domain | Class |
|---|---|---|---|
| AuthRepository.kt `readMap(profilePath)` | read | student profile | RUNTIME READ (fallback) |
| AuthRepository.kt `updateChildren(...)` | write | FCM/devices | RUNTIME WRITE (mirror) |
| AuthRepository.kt `readString(schoolCodePath)` | read | school config | RUNTIME READ (RTDB-only) |
| AuthRepository.kt `readString("Schools/{code}/Config/ActiveSession")` | read | **sessions** | RUNTIME READ (RTDB-only) |
| StudentRepository.kt `readString(attendancePath)` ×2 | read | **attendance** | RUNTIME READ (RTDB-only) |
| LeaveFirestoreRepository.kt `writeValue`/`readMap` | write/read | leave | fallback |
| TransportFirestoreRepository.kt `observeMap(path)` | read realtime | **transport GPS** | RUNTIME READ (RTDB-only) |
| DataRepository.kt `readMap`×2 / `writeValue`×2 | r/w/del | generic | **BRIDGE (ACTIVE)** |

### A.3 TEACHER app — 15 RTDB wrapper-calls (messaging removed since v2)
RTDB imports: `FirebaseService.kt`, `AppModule.kt` (DI), `SchoolSyncApp.kt`, + 3 model files (`ClassAssignment/LeaveRequest/MarksData` — `ServerValue` type coupling only).
| File:caller | Op | Domain | Class |
|---|---|---|---|
| LeaveRepository.kt `readSnapshot`×2 + `pushValue` | r/w | **leave (Staff_Leave)** | RUNTIME R/W (**RTDB-ONLY**) |
| TeacherRepository.kt `listen(path)` + `readSnapshot` | read+listen | staff profile / assignments | RUNTIME READ + LISTEN / fallback |
| AuthRepository.kt `readSnapshot` + `setValue` | read/write | profile / FCM | fallback / mirror |
| TransportFirestoreRepository.kt `listen` + `setValue("SOSAlerts/...")` | read realtime / write | **transport GPS/SOS** | RUNTIME (RTDB-only + SOS dual-write) |
| OfflineQueueManager.kt `setValue`/`updateChildren` | write | offline queue | RUNTIME WRITE |
| DataRepository.kt `readSnapshot`×2 / `setValue`×2 | r/w | generic | **BRIDGE (ACTIVE)** |

---

## B. CROSS-SYSTEM DOMAIN MATRIX
| Domain | Admin | Parent | Teacher | Mismatch |
|---|---|---|---|---|
| Messaging | MIXED (Messaging_service RTDB+FS) | **FS-only now** ✅ | **FS-only now** ✅ | 🟢 apps migrated; admin still RTDB |
| Attendance | RTDB | **RTDB-only read** | (FS marks) | 🔴 parent reads RTDB |
| Leave/HR | RTDB (Hr) | fallback | **RTDB-only** | 🔴 teacher staff-leave no FS home |
| Staff profile | FS `staff` | n/a | **RTDB read+listen** | 🔴 admin FS vs teacher RTDB |
| Student profile/roster | FS + **Dual_write RTDB (Sis)** | FS-first + RTDB fallback | FS | 🔴 admin still dual-writes roster to RTDB |
| Transport GPS/SOS | FS metadata | **RTDB-only GPS** | **RTDB-only GPS+SOS** | no FS home |
| Sessions | FS `currentSession` | RTDB `ActiveSession` | RTDB `ActiveSession` | 🔴 session split |
| School config | FS + residual RTDB | RTDB school-code | RTDB school-code index | schoolCode↔schoolId |
| Exams | FS + residual RTDB | FS | FS | align read-shape |
| Results/marks | MIXED | FS | FS `teacherMarks` | 🔴 pipeline |
| Devices/FCM | RTDB (Push_service) + FS | FS + RTDB mirror | FS + RTDB mirror | 🔴 admin push reads RTDB |
| Auth/session | FS + RTDB (subscription/OTP/Users-Admin) | RTDB fallback | RTDB fallback | login-critical |
| Superadmin | MIXED (System/Schools RTDB + B2 FS) | n/a | n/a | admin-only |

---

## C. ANTI-PATTERNS (current)
- **🔴 dual-write (ACTIVE):** admin student-roster via `Dual_write` (Sis.php 7 sites) — RTDB+FS; admin API-keys (Attendance.php:2451-2452/2537-2538) System+Schools non-atomic; teacher SOS (TransportFirestoreRepository setValue + FS); both apps' `DataRepository` generic set→FS+RTDB.
- **🔴 dual-read / fallback (ACTIVE):** apps `DataRepository` (FS→RTDB), `AuthRepository` profile fallback, `LeaveFirestoreRepository` fallback (Parent), `TeacherRepository` assignments fallback (Teacher); admin `Sis.php:3853` subjects fallback.
- **🟠 best-effort mirror catch:** Attendance.php:405.
- **🟠 migration-in-runtime:** Attendance.php `_migrate_cron_att` ~:667-695.
- **🔴 RTDB-only (break on removal):** Parent attendance/transport/school-code/ActiveSession; Teacher leave/transport/SOS/offline-queue.
- **DEAD (not active):** `Data_service` bridge (0 callers); `Exam_engine::get_subject_list`.

---

## D. COMPLIANCE
| Surface | Binary ZERO-RTDB | FS-primary coverage | Δ vs v2 |
|---|---|---|---|
| Admin | ❌ FAIL | ~62% | ~same (809 ops; Dual_write active in roster) |
| Parent | ❌ FAIL | **~80%** | ↑ (messaging migrated) |
| Teacher | ❌ FAIL | **~75%** | ↑ (messaging migrated) |
| Overall | ❌ 0/3 | ~72% | apps improved |

---

## E. MIGRATION PLAN (updated)
1. Delete dead `Data_service` + `Exam_engine::get_subject_list` (0 callers) — quick win.
2. **Student roster:** retire `Dual_write` from `Sis.php` (7 sites) → Firestore-only roster (`students`/`sections`); then delete `Dual_write`. *Admin (+ apps read FS roster).*
3. Foundation: `schoolCode→schoolId` + Sessions (apps `ActiveSession`→FS `currentSession`).
4. Profiles → FS (teacher `Users/Teachers`→`staff` + snapshot listener; parent profile fallback removal).
5. Devices/FCM: admin push→FS `userDevices` first, then apps drop mirror.
6. Auth residuals (subscription→`schoolControl`, OTP→FS, drop `Users/Admin`).
7. School config (subjects Lms:76/Sis:3853→`subjects`; Config/*; index). 8. Exams residual; 9. Results pipeline; 10. **Attendance** (legacy→FS daily + rollup, all 3 lockstep); 11. **Leave** (build FS `staffLeave`, admin+teacher); 12. Transport GPS/SOS (FS realtime or ratified exception); 13. Superadmin/backups→FS B2; 14. API-keys single FS authority.

---

## F. RISKS
- 🔴 cross-surface lockstep (attendance/leave/profiles/sessions/devices/roster) — move all surfaces together.
- 🔴 `schoolCode↔schoolId` divergence — reconcile before app migration.
- 🔴 admin still **dual-writes student roster to RTDB** (Sis/Dual_write) while apps read FS roster → drift risk until roster cutover.
- 🟠 RTDB-only-with-no-FS-home: teacher leave, transport GPS/SOS, parent attendance/session/school-code.
- 🟠 orphan/staleness: API-keys non-atomic; LMS subjects zombie; dead-but-init'd `Data_service`.
- 🟢 apps now FS-only for messaging — one fewer 3-surface lockstep domain.

*Audit only. Grounded by grep (admin ~809; Dual_write ACTIVE 7 Sis sites [v2 correction]; Data_service dead; Parent 13 / Teacher 15 wrapper-calls, messaging removed). Snapshot — branch under concurrent change.*
