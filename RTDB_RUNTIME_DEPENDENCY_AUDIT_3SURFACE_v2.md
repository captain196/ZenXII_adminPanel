# RTDB Runtime-Dependency Audit — 3 Surfaces — v2 REFRESH (2026-06-12)

**Status:** AUDIT ONLY — no code changes / commits / pushes.
**Supersedes:** `RTDB_RUNTIME_DEPENDENCY_AUDIT_3SURFACE.md` (2026-06-11). Re-run because the admin surface is under active RTDB-reduction.
**Policy:** Firestore sole runtime authority; NO RTDB runtime read/write, NO dual-write/read/bridge/fallback/mirror.

## WHAT CHANGED SINCE 2026-06-11 (delta)
- **Admin RTDB call-sites: ~1037 → ~833 raw; ~771 *runtime* ops** after excluding 52 migration controllers + comment strings (cleaner separation this pass).
- **🟢 BOTH bridge engines are now DEAD:** `Dual_write.php` (`writeToRTDB`/`multiPathUpdate`/`->dw->*` beyond `init`) = **zero callers**; `Data_service.php` (`$this->data->get/set/update/delete/getWhere`) = **zero callers** (only its own docblock matches). Both are still `load`+`init`'d in `MY_Controller` but never invoked → **dead code**. (On 06-11 I flagged these as the active bridge; they are no longer exercised.) `Dual_write` also stopped reading `System/SyncVersion` (now a local epoch counter — the 26s fee-detail spike fix).
- **🔴 Apps UNCHANGED (byte-identical):** Parent 27 ref / 11 write markers (3 files); Teacher 24 ref / 22 write markers (8 files). No app migration occurred. Their bridge/fallback/mirror is intact.
- **Net:** admin is converting from a *managed bridge* to *scattered direct `$this->firebase` RTDB calls*; the apps are static. Structural verdict unchanged: **all 3 surfaces remain non-compliant.**

---

## A. RTDB RUNTIME INVENTORY (current)

### A.1 ADMIN — ~771 runtime RTDB ops; 63 controllers + 63 libraries; 52 migration controllers excluded
Method split (grounded): `get` 433 · `shallow_get` 44 · `set` 142 · `update` 79 · `delete` 61 · `push` 12. Heaviest runtime files + representative file:line (domain):

| File | RTDB ops | Representative file:line | Domain |
|---|---|---|---|
| controllers/Attendance.php | ~131 | :192 `get {secRoot}/Students`; :399 `set Users/Devices/{u}/{d}`; :2451-2452 `set Schools/.../Config/API_Keys` + `System/API_Keys`; :3557 `shallow_get` batch | attendance, devices, API keys |
| controllers/Lms.php | ~68 | :76 `get Schools/{s}/Subject_list`; :1234 `get .../Lessons/{id}`; Homework/Status paths | homework/LMS, subjects |
| controllers/Ats.php | ~26 | recruitment/applicant RTDB paths | HR/recruitment |
| controllers/Assets.php | ~24 | asset/maintenance RTDB paths | operations |
| controllers/Result.php | ~14 | :~ `get/shallow_get Schools/{s}/{yr}/Results/Marks/{examId}`, Templates | results/marks |
| controllers/Fees.php | ~10 | `get Schools/{s}/{yr}/Fees/...` | fees |
| controllers/Hr.php | ~13 | `Schools/{code}/HR/Staff_Leave/...`, payroll | leave/HR |
| controllers/Schools.php | ~17 | `CM->get_data/delete_data Indexes/School_codes`, school rename | school config / SA index |
| controllers/Sis.php | ~4 | :3853 `CM->get_data .../Subject_list/{k}` (subjects fallback) | students/subjects |
| controllers/Exam.php / Examination.php | few | residual `Schools/{s}/{yr}/...` exam paths | exams |
| controllers/Superadmin*.php, MY_Superadmin_Controller.php | many | `System/Schools/...`, `Indexes/...` | SA platform |
| controllers/Backup_cron.php, School_backup.php, Superadmin_backups.php + lib/Backup_service.php | ~13 | `get Schools/...` full-tree export, `System/Backups/...` | backups |
| core/MY_Controller.php | 2+ | :274/:276 `get System/Schools|Users/Schools/.../subscription/status` | auth/session |
| helpers/attendance_helper.php | ~6 | :38/:53/:157/:183/:210 holidays/att-str/rules/staff-att | attendance |
| libraries/Messaging_service.php, Communication_helper.php, Push_service.php | many | `Schools/{code}/Communication/...`, `Users/Devices` | messaging, devices |
| libraries/Exam_engine.php / Exam_read.php | few | :507 `get Schools/{s}/Subject_list/{k}` (**DEAD — 0 callers**), exam reads | subjects/exams |
| libraries/Otp_service.php | few | OTP RTDB store | auth |
| libraries/Device_service.php | few | `Users/Devices`, `Config/Devices` | devices |

**DEAD admin RTDB:** `Dual_write.php` (`writeToRTDB`/`multiPathUpdate` — 0 callers), `Data_service.php` (entire `get/set/update/delete/getWhere` API — 0 callers; RTDB fallback paths `students→Users/Parents`, `staff→Users/Admin`, `subjects→Subject_list`, etc. unreachable), `Exam_engine::get_subject_list` (:507, 0 callers post exam-FS cutover). **Migration utilities (excluded):** 52 `*_verify/_probe/_backfill/_migration/_drill/_audit/_repair` controllers + `B2_cutover_verify.php` comment-string arrays.

### A.2 PARENT app — ~43 runtime RTDB ops (UNCHANGED from 06-11; 3 files import `firebase.database`)
Engine `FirebaseService.kt` + `SchoolSyncApp.kt:25 setPersistenceEnabled`. Key: `MessageRepository.kt` (comms mirror+fallback, 10 sites; RTDB-only reaction :736); `StudentRepository.kt:136/159` (**RTDB-only attendance read**); `AuthRepository.kt:224` (profile fallback), `:366` (FCM mirror), `:395/401` (**RTDB-only school-code + ActiveSession**); `LeaveFirestoreRepository.kt:90/127` (leave fallback); `TransportFirestoreRepository.kt:109` (**RTDB-only live GPS**); `DataRepository.kt:55/94/134/156` (**generic bridge — ACTIVE**).

### A.3 TEACHER app — ~34 runtime RTDB ops (UNCHANGED; 8 files import `firebase.database`, incl. 4 model files using `ServerValue`)
Engine `FirebaseService.kt` + `AppModule.kt` DI + `SchoolSyncApp.kt` persistence. Key: `MessagesRepository.kt` (comms mirror+fallback, ~12 sites); `LeaveRepository.kt:31/55/79` (**RTDB-ONLY** `HR/Staff_Leave/Balance|Records`); `TeacherRepository.kt:42/60` (`Users/Teachers` read+**listen**), `:178` (assignments fallback); `AuthRepository.kt:176/198` (profile/index fallback), `:270` (FCM mirror); `TransportFirestoreRepository.kt:149/236` (**RTDB-only GPS** + SOS dual-write); `OfflineQueueManager.kt:48/49`; `DataRepository.kt:45/113/126` (**generic bridge — ACTIVE**).

---

## B. CROSS-SYSTEM DOMAIN MATRIX (unchanged — apps static)
| Domain | Admin | Parent | Teacher | Dangerous mismatch |
|---|---|---|---|---|
| Communication/chat | MIXED (Messaging_service RTDB+FS) | MIXED (+RTDB-only reaction/delete) | MIXED | shared RTDB comms tree vs FS collections |
| Attendance | RTDB (attendance_helper/Attendance) | **RTDB-only read** | marking (RTDB/FS) | 🔴 parent reads RTDB; admin writers partly RTDB |
| Leave/HR | RTDB (Hr) | fallback | **RTDB-only** (Staff_Leave) | 🔴 teacher leave has no FS home |
| Staff profile | FS `staff` | n/a | **RTDB read+listen** `Users/Teachers` | 🔴 admin FS vs teacher RTDB (schoolCode↔schoolId) |
| Student profile | FS `students` | FS-first + RTDB fallback | FS | low |
| Transport GPS/SOS | FS metadata | **RTDB-only GPS** | **RTDB-only GPS+SOS** | no FS home for live GPS/SOS |
| Sessions | FS `currentSession` (SC-Step10) | RTDB `Config/ActiveSession` | RTDB `Config/ActiveSession` | 🔴 session split |
| School config | FS + residual RTDB (Subject_list, Config/*, Indexes) | RTDB school-code | RTDB school-code index | schoolCode↔schoolId index |
| Exams | FS (cutover) + residual RTDB | FS read | FS read | align read-shape |
| Results/marks | MIXED (FS + RTDB Results/Marks) | FS-primary | `teacherMarks` FS + ServerValue coupling | 🔴 marks→results pipeline |
| Notifications | FS + RTDB (Push_service Devices) | FS + RTDB init | FS + RTDB | — |
| Device registry | RTDB Devices (Push_service) + FS userDevices | FS + RTDB mirror | FS + RTDB mirror | 🔴 admin push reads RTDB → apps can't drop mirror first |
| Auth/session | FS Auth + RTDB (subscription read, Users/Admin mirror, OTP) | RTDB fallback | RTDB fallback + index | login-critical |
| Superadmin | MIXED (System/Schools RTDB + B2 FS) | n/a | n/a | admin-only |

---

## C. ANTI-PATTERNS (current)
- **🟢 Generic bridge / dual-read / dual-write via `Data_service`/`Dual_write` (admin):** now **DEAD** (zero callers) — no longer an active anti-pattern; flagged as dead code for removal.
- **🔴 Uncoordinated dual-write (admin, ACTIVE):** API keys `Attendance.php:2451-2452` (`Schools/.../Config/API_Keys` + `System/API_Keys`) and dual-delete `:2537-2538` — two separate non-atomic RTDB writes (orphan risk if 2nd fails).
- **🟠 Best-effort mirror catch (admin):** `Attendance.php:405` silently swallows a mirror write failure.
- **🟠 Migration-in-runtime (admin):** `Attendance.php:_migrate_cron_att` (~:667-695) reads old path → writes new → deletes old on every cron run (path-move embedded in live cron).
- **🔴 Dual-read + dual-write + mirror (BOTH apps, ACTIVE):** `DataRepository` generic engine (Parent :36-156 / Teacher :28-129); `MessageRepository`/`MessagesRepository` FS-first + RTDB mirror/fallback (~10/12 sites); `AuthRepository` profile/FCM fallback+mirror; SOS dual-write (Teacher `:236`).
- **🔴 RTDB-only (no FS — would break on removal):** Parent reaction (`:736`), attendance (`:136/159`), transport GPS (`:109`), school-code/ActiveSession (`:395/401`); Teacher leave (`:31/55/79`), transport GPS/SOS (`:149/236`).

---

## D. FIRESTORE-ONLY COMPLIANCE
| Surface | Binary ZERO-RTDB | Approx FS-primary coverage | Δ vs 06-11 |
|---|---|---|---|
| Admin | ❌ FAIL | **~60-65%** | improved (bridge engines dead; ~771 runtime ops remain) |
| Parent | ❌ FAIL | ~70% | unchanged |
| Teacher | ❌ FAIL | ~65% | unchanged |
| **Overall** | ❌ **0/3 compliant** | ~65% | admin slightly better; apps static |

---

## E. REMAINING-RTDB MIGRATION PLAN (updated; see FIRESTORE_CONVERGENCE_DOMAIN_PLAN.md for domain detail)
1. **🟢 Quick win — delete dead bridge + dead readers (admin):** remove `Dual_write.php` + `Data_service.php` (0 callers) + their `MY_Controller` load/init; delete `Exam_engine::get_subject_list` (0 callers). Zero runtime risk. *Surfaces:* admin.
2. **Foundation:** `schoolCode→schoolId` (FS `schoolCodeIndex`/`tenantPublic`) + **Sessions** (apps→FS `currentSession`). *All 3.*
3. **Profiles:** Student + Staff → FS (`students`/`staff`); repoint Teacher `Users/Teachers`→FS + snapshot listener. *Lockstep teacher+admin.*
4. **Device registry/notifications:** admin push → FS `userDevices` first, then apps drop RTDB mirror. *Lockstep.*
5. **Auth residuals:** subscription `System/Schools/...status`→`schoolControl` (MY_Controller:274/276); drop `Users/Admin` mirror; OTP→FS. *Admin.*
6. **School config:** subjects (Lms:76, Sis:3853)→FS `subjects`; `Config/*` readers; index. *Admin (+apps for index).*
7. **Exams** residual readers → FS; **Results** marks→compute→`results` (lockstep all 3); **Attendance** legacy month-blob→daily FS + `attendanceSummary` rollup (lockstep all 3).
8. **Leave:** build FS `staffLeave`; admin `Hr` + Teacher together.
9. **Messaging:** FS-only chat (add FS reaction/soft-delete; snapshot listeners) — largest; all 3.
10. **Transport GPS/SOS:** migrate to FS realtime OR ratify a documented realtime carve-out.
11. **Superadmin:** `System/Schools`/`Indexes`/backups → FS B2 (admin-only, parallelizable).
12. **API-keys dual-write (Attendance:2451/2537):** collapse to a single FS authority.

---

## F. RISKS
- **🔴 Cross-surface lockstep:** attendance, leave, profiles, sessions, devices, messaging are written on one surface and read on another — removing RTDB on a writer before readers migrate breaks the other surface (Parent attendance/profile, Teacher leave/profile sharpest). Migrate per-domain across all surfaces together.
- **🔴 schoolCode↔schoolId divergence:** apps key legacy RTDB by `schoolCode`; admin FS by `SCH_*`. Reconcile before any app migration.
- **🟠 RTDB-only data with no FS home:** Teacher leave, transport GPS/SOS, parent reactions/attendance, app school-code/session lookups — build FS shape *before* removing RTDB.
- **🟠 Orphan/zombie + staleness:** API-keys non-atomic dual-write; admin writes FS `subjects` but LMS still reads orphaned RTDB `Subject_list` (proven staleness). Dead-but-init'd `Dual_write`/`Data_service` add confusion + a tiny per-request init cost.
- **🟠 Session isolation:** apps resolve session from RTDB `Config/ActiveSession` vs admin FS `currentSession` (SC-Step10) — apps may act on a different session.
- **🟢 Lower bridge risk now:** with `Data_service`/`Dual_write` dead, there is no longer a generic FS→RTDB fallback masking failures on admin; remaining admin RTDB is explicit/direct (easier to reason about), and the bridge deletion (item E1) is a safe quick win.

---

*Audit only. Grounded by grep (admin ~771 runtime RTDB ops; `Dual_write`/`Data_service` verified 0 callers; Parent 43 / Teacher 34 unchanged). Representative file:line per domain; full ~771-line admin enumeration available on request. Branch under concurrent change — re-verify at execution time.*
