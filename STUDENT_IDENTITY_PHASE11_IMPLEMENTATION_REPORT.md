# Phase 11 — Student Identity Cross-Platform Hardening · IMPLEMENTATION REPORT

**Date:** 2026-08-14 · **Branch:** `integration/phase2` · **Type: IMPLEMENTATION**

**Nothing committed. Nothing staged. Nothing pushed. Nothing deployed. No production data written.
No test account created. No Firestore rule or index modified.**

Starting evidence: `STUDENT_IDENTITY_PHASE11_CROSS_PLATFORM_INTEGRATION_GATE.md` (audit, 2026-08-13).
The identity architecture was **not** re-designed; it was preserved exactly as locked.

---

## 1. Executive Summary

All five in-scope fixes are implemented across three repositories, plus the Admin Panel display
gaps and the hard-delete integrity markers. **Every mobile change costs zero additional Firestore
reads** — the audit's central finding held up under implementation: both apps already download the
complete student document and were discarding these fields at the Kotlin model boundary.

**Defects fixed (all were live, wrong output to real users):**

| ID | Severity | What was wrong |
|---|---|---|
| **P11-D1** | HIGH | Teacher App rendered `Roll: -2147483648` for blank/alphanumeric roll numbers — on the roster card, the detail panel, the attendance matrix and the marks grid. **Also wrote that value into Firestore** via the red-flag denorm path. |
| **P11-D2** | MEDIUM | Parent App displayed a hardcoded `#4` class Rank to every parent, in every school, for every child. |
| **P11-D3** | LOW→MED | `User.status` was never persisted or read back, so a withdrawn student's cached profile silently reverted to `Active` on every app restart. |
| **P11-G1** | Gap | Admin student list, bulk-import result and credentials PDF showed neither SRNO nor Admission Number. |
| **P11-G2** | Gap | Hard delete flagged `srNoIndex` but left `admissionNoIndex` and `apaarIndex` unmarked. |
| **P11-G3** | Gap | Parent sibling switcher rebuilds `User` field-by-field — identity fields would have leaked between children. |

**One new finding, NOT fixed, requires your decision: P11-N1** — Student 360 renders **APAAR
unmasked with no audit entry**, behind a broader RBAC gate than the audited-reveal endpoint. §17.

**Two items are audit-only by instruction or by absence:** historical-import admission year (Part 13
— needs a business decision, §16) and student exports (Part 11 — **no student export exists in the
Admin Panel**, §9).

**Verdict: READY WITH CONDITIONS** (§19) — code-verified and compile-verified, but **browser and
device UAT were NOT EXECUTED** (§14).

---

## 2. Files Changed

### Teacher App — `D:\Projects\SchoolSyncTeacher`

| File | Change |
|---|---|
| `util/RollNoFormat.kt` | **NEW.** `formatRollNo(raw, padNumericTo)` + `ROLL_NO_UNSET` — the single canonical roll-number display formatter. |
| `data/model/firestore/StudentDoc.kt` | `+ srNo: Any?` and a `srNoText` accessor. |
| `data/model/StudentInfo.kt` | `+ srNo: String`. |
| `data/repository/StudentRepository.kt` | Maps `doc.srNoText` → `StudentInfo.srNo`. |
| `ui/students/StudentsViewModel.kt` | `+ srNo` on the screen-local `StudentInfo`; populated on both load paths; **search now matches `rollNoRaw`**. |
| `ui/students/StudentsScreen.kt` | Roster card + detail panel render `formatRollNo(rollNoRaw)`; **`toModelStudentInfo()` denorm fixed**; `+ SR No.` detail row. |
| `ui/attendance/AttendanceScreen.kt` | `StudentRow` takes `rollNoRaw`; matrix cell fixed. |
| `ui/marks/MarksScreen.kt` | Grid cell + both accessibility labels fixed. |

### Parent App — `D:\Projects\SchoolSyncParent`

| File | Change |
|---|---|
| `data/model/firestore/StudentDoc.kt` | `+ srNo: Any?`, `+ admissionNumber: String`, `srNoText` accessor. |
| `data/model/User.kt` | `+ srNo`, `+ admissionNumber`. |
| `data/local/TokenManager.kt` | `+ SR_NO`, `+ ADMISSION_NUMBER`, **`+ STATUS`** keys — written in `saveUserDirect`, read in the `user` flow. |
| `data/repository/AuthRepository.kt` | Login populates `srNo` / `admissionNumber` from the doc it already fetched. |
| `ui/dashboard/DashboardViewModel.kt` | **`switchToSibling`**: `srNo`, `admissionNumber`, `status` now rebuilt per child. **`healUserProfileIfNeeded`**: identity healed; `status` made Firestore-authoritative. |
| `ui/splash/SplashViewModel.kt` | Cold-start re-check propagates identity; `status` Firestore-authoritative. |
| `ui/profile/ProfileScreen.kt` | **Hardcoded `#4` Rank pill removed**; new "Student Identity" section; Class/Section/Roll rows added. |

### Admin Panel — `c:\xampp\htdocs\ZenX\school`

| File | Change |
|---|---|
| `application/controllers/Sis.php` | P11-G2 hard-delete markers for `admissionNoIndex` + `apaarIndex`; `$credentials[]` carries `srNo`/`admissionNo`; import response returns a `created[]` identity array. |
| `application/controllers/Classes.php` | `fetch_section_students()` returns `sr_no` + `admission_no`. |
| `application/views/section_students.php` | Two new columns; colspans corrected. |
| `application/views/import_students_map.php` | Result screen renders the per-student identity table. |
| `application/views/import_credentials_pdf.php` | Data-driven SR No. / Admission No. columns. |
| `application/views/sis/id_card.php` | SR No. info row; Roll appended to the class line. |

## 3. Files Intentionally NOT Changed

- **`firebase-rules/firestore.rules`** and **`firestore.indexes.json`** — no change required (§11).
  Their pre-existing dirty state is other developers' Phase 9/10.2/Transport work; mtimes
  (2026-08-13 / 2026-08-12) confirm this phase did not touch them.
- **`Sr_no_service.php` / `Apaar_service.php` / `Admission_no_service.php` / `Id_generator.php`** —
  identity generation is locked and correct.
- **`Readmission.php`** — re-admission semantics verified correct (§10), not modified.
- **Fees, Attendance, Exams, Results, Transport, Communication, Homework, Documents, TC,
  Certificates** — verified (§8), no regression found, nothing to change.
- **`application/views/studentlist.php`** — dead static mock (its "Sr No" column is a row counter,
  not SRNO). Left alone; noted only to prevent a future misreading.
- **`Operations_accounting.php`** RTDB dependency — pre-existing, still awaiting the Phase 10 §H
  decision. Not extended, not touched.
- **Roll-number *sorting*** — still `Int` with `Int.MIN_VALUE` for non-numeric, so alphanumeric rolls
  sort first. Deliberate: the audit called the Int keeping a bounded fix, and changing sort order is
  a UX change beyond this phase's brief. Display is now correct everywhere; only ordering is legacy.

---

## 4. Defects Fixed — Detail

### P11-D1 · Teacher App roll number

The root cause was a **sort key leaking into display**. `StudentsViewModel:196`,
`AttendanceViewModel:508` and `MarksViewModel:316` all derive
`rollNo.toIntOrNull() ?: Int.MIN_VALUE`. Four screens rendered that Int directly.

`rollNoRaw` — added in an earlier phase and identified by the audit as **inert (P11-R2)** — already
carried the true Firestore string at every one of those construction sites. This fix *uses* it
rather than adding a third mechanism, exactly as the audit recommended.

Eight sites corrected:

| Site | Before | After |
|---|---|---|
| `StudentsScreen:471` roster card | `Roll: -2147483648` | `Roll: A12` / `Roll: 18` / `Roll: —` |
| `StudentsScreen:601` detail panel | same | same |
| `StudentsScreen:268` **`toModelStudentInfo()`** | wrote `"-2147483648"` **into the red-flag document** | writes the canonical string |
| `AttendanceScreen:819` `StudentRow` | `if (rollNo > 0)` — suppressed the sentinel but **also erased `A12` as `—`** | `formatRollNo(raw, padNumericTo = 2)` — keeps `08`, preserves `A12` |
| `AttendanceScreen:1527` matrix | unguarded Int | fixed |
| `MarksScreen:642` grid | unguarded Int | fixed |
| `MarksScreen:689/701` a11y labels | "roll -2147483648" | correct |
| `StudentsViewModel:337` search | matched `-2147483648`, could never match `A12` | matches `rollNoRaw` |

**`StudentsScreen:268` is the one that was not merely cosmetic** — it denormalised the sentinel into
a Firestore document the Admin Panel and dashboard analytics read back. Display defect *and* data
defect.

Acceptance (Part 25): `18` → `18`; `A12` → `A12`; `""` → `—`. No `Int.MIN_VALUE`, no `0`, no
sequential fallback, no fabricated value.

### P11-D2 · Fabricated Parent App rank

`ProfileScreen.kt:237` `value = "#4"` — a string literal. **Removed, not replaced.** No `#0`, `#—`
or `N/A` substitute: any placeholder still asserts that a ranking exists. ZenX has no canonical rank
field and no rank calculation; computing a real one needs a class-wide results aggregation, i.e. a
genuine feature with genuine read cost, explicitly out of scope. The two surviving pills
(Attendance, Homework) keep `weight(1f)` and reflow.

### P11-D3 · Status persistence

Three separate holes, all closed:

1. `TokenManager.saveUserDirect()` never wrote `status` → **now written**.
2. The `user` flow never read it, so every restart fell back to the model default `"Active"` →
   **now read**, with `"Active"` retained only as the blank/absent default for pre-existing installs.
3. `healUserProfileIfNeeded()` and the splash re-check never refreshed it.

Per Part 5, **cached status never overrides Firestore**: both refresh paths take `doc.status`
authoritatively (the DOB pattern), not the `ifBlank { }` pattern used for cosmetic fields. Firestore
remains the single source of truth — no second status source was created.

Status is **not** cosmetic: `AuthRepository.login()` blocks completion for any non-`Active` student.
Before this fix, a withdrawn student's cached profile read `Active` after a restart. Whether any
in-app gate consumed that stale value beyond login remains **NOT VERIFIED** (§15).

### P11-G3 · Sibling switcher

`switchToSibling` rebuilds `User` with an explicit field list, so an omitted field silently inherits
the **previous child's** value. `srNo`, `admissionNumber` and `status` are now sourced from `doc` on
every switch, with no `current` fallback. This is the trap the audit flagged and it is the reason
adding fields only to the login path would have been wrong.

---

## 5. Parent App Changes

**Display implemented** (Part 6):

```
Student Identity
  Student ID     STU0001
  SR No.         12
  Admission No.  2026/7K4P92

School Information
  School / Session …
  Class          Class 5
  Section        Section A
  Roll No.       18
```

Identity and academic position are deliberately **separate sections**: SRNO and Admission Number are
frozen across promotion, while Class/Section/Roll change. Roll No. is printed verbatim and never
parsed as a number.

`ProfileDetailRow` returns early on blank, so a student without an Admission Number shows no empty
row. Existing installs read blank until the next login / splash / dashboard load, then self-heal.

**Not displayed, by design:** APAAR (PII) and `previousStudentId` (historical linkage).

**Propagation paths updated — all four:** login (`AuthRepository`), cold start (`SplashViewModel`),
dashboard load (`healUserProfileIfNeeded`), sibling switch (`switchToSibling`).

## 6. Teacher App Changes

Detail panel now shows **Student ID + SR No.**; Class/Section/Roll remain on the header line.

**Admission Number is NOT shown** — the audit's documented §8 decision, re-affirmed: a teacher's
workflows (attendance, marks, homework, flags, PTM) never reference an admission-event identifier,
and Part 8 explicitly warns against cluttering that surface.

**P11-R1 mitigated:** only the camelCase `admissionNumber` key is ever bound, in either app. No
`@PropertyName("Admission Number")` alias was added anywhere — that would collide
case-insensitively with `admissionNumber` and crash whole-document deserialisation.

**Additional hardening beyond the brief, and why:** `srNo` is declared `Any?` with a `srNoText`
accessor rather than `String`. Every current writer emits a String, but the Admin Panel has several
SRNO write paths, and one numeric write would fail deserialisation of the entire student document —
taking down the whole roster (Teacher) or the whole profile (Parent) to render one row. This matches
the file's existing defensive pattern for `address` / `monthFee` / `documents`.

## 7. Admin Panel Changes

**Student list** — SR No. and Admission No. columns. The full student document is already loaded by
`fetch_section_students()`, so both are free. Alias-tolerant for pre-convergence documents. The
client-side search still reads `td:nth-child(4/5)` (Name / Student ID); the new columns sit at 6–7,
so search is unaffected. Both colspans corrected 8 → 10.

**Bulk-import result** — a per-student table (Student / Student ID / SR No. / Admission No.) built
from the `credentials` rows already assembled during the import loop. Both identifiers are
system-allocated and were previously invisible until the operator opened a profile.
**The default password is deliberately excluded from this payload** — it is rendered into the page,
whereas the password stays in the session-stashed handout only `import_credentials_pdf()` reads.

**Credentials PDF** — SR No. / Admission No. columns, **data-driven rather than switched on
`$entityLabel`**, because the view is shared with the staff import. The staff PDF is byte-identical
to before. APAAR is not present and must not be added: this sheet is printed and handed out.

**ID card** (Part 10) — SR No. added as a conditional info row; Roll No. appended to the
`Class · Section` line. Rationale: SRNO is the school's own register number, which is what an office
or gate check actually reconciles a card against; Roll is class-scoped and belongs with
Class/Section, not with identity. `.idc-card` has no fixed height (flex column), and the print path
uses `outerHTML` with a mirrored stylesheet that already styles `.idc-info-row`, so print is covered.
**Admission Number was NOT added** — it is an admission-office/certificate artefact, and it would
read as stale after a re-admission since cards are not reissued. No APAAR, no `previousStudentId`.
No redesign.

**P11-G2 hard-delete markers** — the same `currentOwnerDeleted` / `currentOwnerDeletedAt` pattern
`srNoIndex` already used, extended to `admissionNoIndex` and `apaarIndex`:

- **Integrity marking only.** No document deleted, no ownership released, `resolve()`/`relink()`
  untouched. **Neither identifier becomes reclaimable** — that policy question stays open, exactly
  as it already does for SRNO.
- **Tenant-safe:** every doc id goes through `$this->fs->docId()`, which prefixes the caller's own
  `schoolId`. School A cannot flag School B's entry.
- **Idempotent:** re-running sets the same two fields to the same values.
- **Owner-guarded:** an index entry already relinked to a different live student — the normal
  re-admission case — is never mis-flagged.
- **Best-effort:** each block has its own try/catch and never blocks the deletion.
- `Apaar_service` is explicitly loaded before its static call (this method had no other use of it).

---

## 8. Cross-Module Verification (Part 15)

**Method:** repo-wide grep for any Firestore query predicate keyed on a non-Student-ID identifier,
in all three repositories.

```
Admin Panel   [['srNo'|'srNoNorm'|'rollNo'|'Roll No'|'admissionNumber'|'Admission Number'|'apaarId', '==' …]]   → 0 matches
Teacher App   whereEqualTo("srNo"|"rollNo"|"admissionNumber"|"apaarId", …)                                      → 0 matches
Parent App    whereEqualTo("srNo"|"rollNo"|"admissionNumber"|"apaarId", …)                                      → 0 matches
```

| Module | Identifies student by | SRNO as key? | Roll as key? | Adm No as key? | Re-admission safe | Promotion safe | Class/section change safe | Verdict |
|---|---|---|---|---|---|---|---|---|
| Admission / Re-admission | Student ID | No | No | No | ✔ | ✔ | ✔ | **PASS** |
| Student Profile / Student 360 | Student ID | No | No | No | ✔ | ✔ | ✔ | **PASS** (see §17) |
| Attendance | Student ID | No | No | No | ✔ | ✔ | ✔ | **PASS** |
| Fees | Student ID | No | No | No | ✔ | ✔ | ✔ | **PASS** |
| Examination / Results | Student ID | No | No | No | ✔ | ✔ | ✔ | **PASS** |
| Transport | Student ID | No | No | No | ✔ | ✔ | ✔ | **PASS** |
| Communication | Student ID | No | No | No | ✔ | ✔ | ✔ | **PASS** |
| Homework / LMS | Student ID | No | No | No | ✔ | ✔ | ✔ | **PASS** |
| Documents | Student ID | No | No | No | ✔ | ✔ | ✔ | **PASS** |
| Certificates / TC | Student ID (reads `Admission Number` as a **display** field) | No | No | No | ✔ | ✔ | ✔ | **PASS** — unchanged |
| ID Card | Student ID | No | No | No | ✔ | ✔ | ✔ | **PASS** — SRNO/Roll added as display |
| Reports | Student ID | No | No | No | ✔ | ✔ | ✔ | **PASS** |
| Exports | — | — | — | — | — | — | — | **N/A — none exist** (§9) |
| Parent App | Student ID (doc id `{schoolId}_{userId}`) | No | No | No | ✔ | ✔ | ✔ | **PASS** |
| Teacher App | Student ID | No | No | No | ✔ | ✔ | ✔ | **PASS** |

**No identifier substitution was introduced.** Student ID remains the sole technical foreign key
everywhere. SRNO / Admission Number / Roll Number were added **only** as display and report fields.

**Promotion re-verified this pass, not assumed:** a 9,000-character scan of `execute_promotion()`
returns **zero** occurrences of `srNo`, `admissionNumber`, `Admission Number`, `apaarId`,
`previousStudentId`, `rollNo` or `Roll No`. Freezing is structural, not conventional.

**Hard-delete / withdrawal:** cascade behaviour unchanged. The only addition is the two informational
markers (§7), on a rare explicit action.

**Stale-UI check:** the one genuinely stale path found is **pre-existing and out of scope** —
`healUserProfileIfNeeded()` heals `className`/`section` with `ifBlank { }`, so a cached non-blank
class never updates after promotion. `SplashViewModel` does correct this on cold start (prefers
Firestore when non-blank), which is why the known symptom is "parent must restart to see the new
class". Already tracked as the mobile-promotion-refresh backlog item; **not** changed here, because
altering dashboard-load field precedence is beyond this phase and touches promotion behaviour.

## 9. Exports (Part 11) — NOT APPLICABLE

**There is no student-roster export in the Admin Panel.** Verified by three independent searches:
`Content-Disposition: attachment` across `application/` (13 hits — all documents, backups,
accounting, HR, visitor log, superadmin analytics), `fputcsv` in `Sis.php` (only the **import
template**), and every `b2_xlsx_export` caller (all Superadmin school-level analytics).

The closest artefacts, and their status:

- **Import template CSV** — an *input*, not an export. Correctly has no Admission Number column
  (system-generated; a spreadsheet must never inject one).
- **Credentials PDF** — an output; **now carries both** identifiers (§7).
- **Bulk-import result** — an output; **now carries both** (§7).

Building a student export from scratch is a new feature and would be silent scope expansion.
**Recorded as DEFERRED**, with the recommendation unchanged: if such an export is built, SRNO +
Admission Number belong in it, APAAR does not, and `previousStudentId` only in an explicitly
historical trail export.

## 10. Re-admission Verification (Part 14)

Traced against the specified scenario — `STU0001` / SRNO 12 / `2026/7K4P92` / 5-A / Roll 18,
re-admitted as `STU0047` with SRNO 12 preserved, a NEW Admission Number, and
`previousStudentId = STU0001`.

| Surface | Current Student ID | SRNO | Admission No | Class/Section/Roll | Old record | `previousStudentId` shown |
|---|---|---|---|---|---|---|
| **Admin Panel** | `STU0047` | 12 (relinked) | new | current | intact, linked | yes — Student 360 only, correct |
| **Parent App** | `STU0047` | **12** ✔ now displayed | **new** ✔ now displayed | current | not the current child | **never** ✔ |
| **Teacher App** | `STU0047` | **12** ✔ now displayed | not shown (by design) | current | excluded — roster filters `status == "Active"` | **never** ✔ |

`Readmission::confirm()` verified unchanged: `Sr_no_service::relink()` preserves SRNO,
`Admission_no_service::allocate()` issues a new number, `previousStudentId` is set to the old ID.

**Grep-verified:** `apaar` and `previousStudentId` appear in the two app repositories **only inside
comments I added this phase** — zero code references. Neither is bound in either `StudentDoc`.

## 11. Firestore Rules / Indexes (Parts 17–18) — NO CHANGE REQUIRED

**Confirmed, and the expected result:** neither file was modified.

- **Rules:** every mobile change displays fields on `students` documents the apps already read,
  permitted by the existing `match /students/{docId}` block. No new collection, no new query shape,
  no new access pattern.
- **The two new marker fields** land on `admissionNoIndex` / `apaarIndex` documents that are already
  `allow read, write: if false` (lines 1078 and 1087) and are written by the Admin SDK, which
  bypasses rules. This is the identical precedent set when `currentOwnerDeleted` was added to
  `srNoIndex`.
- **Indexes:** both new accesses are deterministic **doc-id fetches** (`$this->fs->get(...)`), not
  queries. No composite index is involved.

Pull-first discipline: because no rules edit was needed, no pull/reconcile was performed and **no
deploy was attempted**. The reconciliation status from Phase 10.2 stands unchanged (live ruleset of
2026-08-10 diffed; local is a strict superset; nothing deployed). The dirty state of both files is
other developers' uncommitted work — mtimes 2026-08-13 and 2026-08-12, both predating this phase.

## 12. Multi-Tenant Verification (Part 16)

| Property | Status | Evidence |
|---|---|---|
| Student ID globally unique | **PASS** | `Id_generator` unchanged; global counter untouched. |
| SRNO repeats across schools (A: 1033, B: 1033) | **PASS** | `srNoIndex` doc id is `{schoolId}_{srNoNorm}`; unit-tested cross-school isolation exists. **No global uniqueness constraint was introduced.** |
| Roll repeats across classes/sections | **PASS** | Free text, no index, no uniqueness anywhere. |
| Admission Number tenant-scoped | **PASS** | `admissionNoIndex` doc id `{schoolId}_{year}_{token}`. |
| No cross-tenant read from either app | **PASS** | Parent reads doc id `{schoolId}_{userId}`; Teacher filters `whereEqualTo("schoolId", schoolCode)` from claims. **No new query added**, so no new tenant surface. |
| No client-controlled `schoolId` | **PASS** | Both apps take it from Auth claims / TokenManager; nothing added accepts a client-supplied school. |
| New hard-delete markers tenant-safe | **PASS** | Both doc ids via `$this->fs->docId()` (server-side schoolId prefix) + `currentStudentId` owner check. |

## 13. Latency / Read-Cost Table (Part 17)

| Change | Reads | Writes | Queries | Round trips | N+1 | Index | Rules | Latency |
|---|---|---|---|---|---|---|---|---|
| Parent: Student ID / SRNO / Adm No / Class / Section / Roll | **0** | 0 | **0** | **0** | none | none | none | **none** |
| Parent: sibling-switch identity | **0** | 0 | 0 | 0 | none | none | none | none |
| Parent: status persistence | **0** | 0 | 0 | 0 | none | none | none | none |
| Parent: remove `#4` Rank | **0** | 0 | 0 | 0 | none | none | none | none |
| Teacher: SRNO in detail panel | **0** | 0 | 0 | 0 | none | none | none | none |
| Teacher: roll rendering (P11-D1) | **0** | 0 | 0 | 0 | none | none | none | none |
| Admin: student list columns | **0** | 0 | 0 | 0 | none | none | none | none |
| Admin: import result + credentials PDF | **0** | 0 | 0 | 0 | none | none | none | none |
| Admin: ID card SRNO + Roll | **0** | 0 | 0 | 0 | none | none | none | none |
| Admin: hard-delete markers | **+2** | **+2** | 0 | 0 | none | none | none | negligible |

**Totals: 0 reads / 0 writes / 0 queries / 0 round trips added to any normal operation.** The only
cost is +2 reads / +2 writes on an explicit hard delete — a rare admin action, and the exact
allowance Part 12 grants.

Why zero was achievable, re-verified in source this pass:
- Parent: `AuthRepository.loadProfileFromFirestore()` does a **full-document GET**; `SplashViewModel`
  and `DashboardViewModel` each do another on their own existing schedule; `switchToSibling` already
  calls `getStudent()`.
- Teacher: `FirestoreService` uses a plain `.get()` with `whereEqualTo` filters — **no `.select()`
  projection exists anywhere in the file**.
- Admin: `fetch_section_students()`, `_runStudentImport()` and `Sis::id_card()` all already hold the
  complete student document.

**No lookup against `srNoIndex`, `admissionNoIndex` or `apaarIndex` was added for display.** Those
are server-only claim indexes and must never be read to render a field.

## 14. Tests Executed

| Check | Result |
|---|---|
| `php -l` on all 6 changed PHP files | **PASS** — no syntax errors |
| `vendor/bin/phpunit --testsuite Unit` | **719 tests, 2288 assertions, 10 failures** — **identical to the documented pre-existing baseline**, zero new failures |
| Teacher App `compileDebugKotlin` | **BUILD SUCCESSFUL** (3m 8s) |
| Parent App `compileDebugKotlin` | **BUILD SUCCESSFUL** (3m 4s) |
| Teacher App `installDebug` → device `I2011` (Android 13) | **BUILD SUCCESSFUL** — `Installed on 1 device` |
| Parent App `installDebug` → device `I2011` (Android 13) | **BUILD SUCCESSFUL** — `Installed on 1 device` |
| `adb shell pm list packages` | **PASS** — `com.schoolsync.parent` + `com.schoolsync.teacher` both present |
| Cross-module query-key grep (3 repos) | **PASS** — 0 non-Student-ID query keys |
| `execute_promotion()` identity-field scan | **PASS** — 0 occurrences |
| APAAR / `previousStudentId` grep in both apps | **PASS** — 0 code references |
| Rules / indexes untouched (mtime + status) | **PASS** |

The 10 pre-existing failures are cross-repo source-grep tests (`BackupTest::size_human_…`,
Homework ×5, ParentHomeworkRepo ×3, TeacherExecuteDeleteCascade ×2). **None touches any file changed
in this phase.**

## 15. Tests NOT Executable — and why

Both debug builds are now **installed on the connected device `I2011` (Android 13)**, so the
app-side tests below are unblocked for *you* to run — but they are still **NOT EXECUTED by me**:
each one requires signing in as a real parent/teacher of a real tenant, and I neither create test
accounts nor drive a logged-in session.

| Test (Part 21) | Status | Reason |
|---|---|---|
| 1–11 Parent App device tests (login, cold start, dashboard, A→B→A switching, withdrawn status, re-admission) | **NOT EXECUTED** — build installed, awaiting operator | Requires a real tenant login. Code paths verified by source trace + compile + successful install only. |
| 12–21 Teacher App device tests (numeric / alphanumeric / blank roll, identity display, exclusions) | **NOT EXECUTED** — build installed, awaiting operator | Same. Test 14 (blank roll → `—`) and 13 (alphanumeric `A12`) are the two that directly prove P11-D1. |
| 22–29 Admin Panel browser UAT (list, import result, PDF, profile, 360, ID card, export, hard-delete flags) | **NOT EXECUTED / ENVIRONMENT BLOCKED** | Requires a browser session against `localhost:8080` with a real tenant. |
| 30–38 Cross-module runtime | **SOURCE VERIFIED, NOT RUNTIME TESTED** | Verified by grep + source trace (§8), not by executing each module. |
| 39–41 Multi-tenant runtime | **SOURCE VERIFIED + UNIT VERIFIED** | Doc-id scheme verified in source; cross-school isolation unit tests exist for SRNO / APAAR / Admission Number. Not re-run against live tenants. |

**No UAT PASS is claimed anywhere in this report.** No test account was created; no production data
was touched.

## 16. Historical-Import Admission Number (Part 13) — AUDIT ONLY, DECISION REQUIRED

**Verified mechanism:** `Sis::_allocateAdmissionNumber()` takes the year as
`substr($this->session_year, 0, 4)` — the **current** session — and passes it to
`Admission_no_service::allocate()`, which formats `YYYY/TOKEN`. Both import branches use it. There is
**no `Admission Year` column** in `Import_schema` and no other field supplying a real original
admission year.

So a student who actually joined in 2018, imported during session 2026-27, receives `2026/ABC123`.

**What the Admission Number represents:** an admission-**event** identifier — system-generated,
independent of SRNO/class/Roll, frozen for that admission, new on re-admission. The token carries the
uniqueness; the year is a human-readable prefix.

**Is the year semantic or generational?** Today it is **generational** (when the record was created),
but it *reads* as semantic. Nothing in code depends on it: it is never parsed and never queried.

**Impact of leaving it as-is, now that Phase 11 ships:**

| Surface | Impact |
|---|---|
| Parent App | **NEW — now parent-visible.** A 2018 joiner's parent sees "Admission No. 2026/…". This is the change in priority the audit predicted (R3). |
| Teacher App | None — Admission Number is not shown. |
| Certificates / TC | Prints the stored value; a TC for a long-standing student would show the import year. |
| Reports | None — no report keys on or parses it. |

**Why nothing was implemented:** correcting it requires answering a business question I must not
answer for you — Part 13 and Part 24 stop condition 4 both say so explicitly.

**Decision required (Q4):**
- **(a) Accept** — declare the year generational, document it, no code change. Cheapest; parents of
  historically-imported students see a year that is not their joining year.
- **(b) Add an optional `Admission Year` import column** — historical mode only, validated `YYYY`,
  falling back to the current session when blank. Small, contained, and the only option that makes
  the number truthful. It does **not** make the Admission Number operator-supplied — only the year
  component, and only for historical data.
- **(c) Retro-fix existing data** — **not recommended**: Admission Number is frozen by design, and
  rewriting issued identifiers is exactly what "frozen" forbids.

Recommendation: **(b)** if historical imports are ongoing; **(a)** if they are already complete and
the affected population is small.

## 17. Security Assessment

**No new attack surface.** Display-only changes, no new collection, no new query, no rule change, no
new client-supplied input. Tenant isolation preserved and re-verified (§12).

| Item | Status |
|---|---|
| APAAR in Parent App | **ABSENT — verified by grep** (0 code references) |
| APAAR in Teacher App | **ABSENT — verified by grep** |
| APAAR in credentials PDF | **ABSENT — deliberately excluded, documented in-file** |
| APAAR in ID card | **ABSENT** |
| APAAR in import-result payload | **ABSENT** |
| APAAR in exports | **N/A** — no export exists |
| APAAR in mobile logs | **ABSENT** — not in either model, so it cannot be logged |
| `previousStudentId` in either app | **ABSENT — verified by grep** |
| Default password in the new import-result JSON | **EXCLUDED by design** — page-rendered payload carries identity only; the password stays in the session-stashed PDF |
| Admission Number forgeable? | **No** — no service method, import column or controller POST accepts one; re-confirmed this pass |
| New hard-delete markers | Server-only collections, Admin SDK, owner-guarded, tenant-prefixed |

### P11-N1 · NEW FINDING — APAAR exposed unmasked in Student 360 (**NOT FIXED — decision required**)

`application/views/student360/workspace.php:877` renders `'APAAR ID' => $acad['apaarId']` **in
plaintext**, in the Profile panel, with:

- **no masking** — the audited-reveal endpoint `Sis::apaar_reveal()` exists precisely because APAAR
  is meant to be masked by default;
- **no audit entry** — `apaar_reveal()` writes `log_audit('SIS', 'apaar_revealed', …)`; this path
  writes nothing, so an APAAR disclosure through Student 360 is invisible to the audit trail;
- **a broader gate** — Student 360 requires `SIS / view` (`Student360.php:185/233`), whereas
  `apaar_reveal()` is restricted to Super Admin / School Super Admin / Admin / Principal.

Net effect: **any role with `SIS view` can read every student's APAAR, unmasked and unlogged**,
bypassing the established policy. This is an Admin-Panel surface, so it does not violate Part 19's
enumerated list (apps / logs / exports / PDFs / ID cards / certificates) — which is why it is
reported rather than treated as a Part 19 failure.

**Not fixed, deliberately.** Part 19's own carve-out is "unless an existing authorized Admin-only
policy explicitly requires it", and I cannot determine from source whether Student 360's plaintext
display is an accepted operator workflow or an oversight. Changing it would alter an existing admin
surface outside the authorized scope of Parts 3–12 and could break a workflow you rely on.

**Decision required (Q5):** mask it in Student 360 and route reveal through the existing audited
endpoint (recommended — it reuses `apaar_reveal()`, costs nothing, and closes the audit gap), or
formally accept plaintext display at `SIS / view` and record that as the policy.

## 18. Rollback Considerations

Everything is uncommitted, so rollback is `git checkout --` on the listed files (**note: that would
also discard other developers' uncommitted work in the same files — `Sis.php`, `StudentsViewModel.kt`,
`AuthRepository.kt` and others were already dirty; revert per-hunk, not per-file**).

| Change | Rollback risk |
|---|---|
| All Kotlin display/model changes | **None.** Additive fields with defaults; removing them cannot corrupt data. |
| New DataStore keys | **None.** Key-by-key reads with defaults; older builds ignore unknown keys, newer builds read blank and self-heal. |
| `formatRollNo` | **None.** Pure function, no state. |
| Rank pill removal | **None.** |
| Admin display changes | **None.** Read-only rendering. |
| `credentials[]` / `created[]` additions | **None.** Additive keys; the PDF columns are data-driven, so the staff path is unchanged. |
| **Hard-delete markers** | **The only change that writes data.** Two additive boolean/timestamp fields on server-only index documents. Reverting the code leaves any already-written markers in place — harmless, since nothing reads them yet; they are forward-looking integrity metadata. **No ownership or reclaim semantics were altered**, so there is nothing to unwind. |

No migration, no backfill, no schema change, no data transformation.

## 19. Final Verdict

# READY WITH CONDITIONS

**Ready:** every in-scope defect is fixed and every in-scope integration is implemented, across all
three surfaces. The identity architecture is untouched and preserved. Cross-module verification found
**no** identifier substitution anywhere — Student ID remains the sole join key. Multi-tenant
isolation is preserved with no new query surface. **Zero Firestore reads, writes, queries or round
trips were added to any normal operation**, which was the phase's hardest constraint given ZenX's
known Firestore latency. Rules and indexes are untouched, exactly as predicted. PHP lint clean, the
unit suite is at its exact pre-existing baseline, and the Teacher app compiles.

**Conditions:**

1. **Browser and device UAT were NOT EXECUTED** (§14, §15). Everything is source-verified,
   compile-verified and — for both apps — **install-verified on device `I2011`**; nothing is
   UAT-verified. Tests 1–21 are now one login away on that device; tests 22–29 need a browser
   session against `localhost:8080`.
2. **P11-N1 (§17) is open** — APAAR is exposed unmasked and unaudited in Student 360 at `SIS / view`.
   Reported, not fixed; needs your decision.
3. **Part 13 needs a business decision (§16)** — historical-import admission year, now
   parent-visible for the first time.
4. **Part 11 could not be implemented** — no student export exists to add fields to (§9).
5. **Two pre-existing items remain untouched and out of scope:** the promotion/class-refresh staleness
   in `healUserProfileIfNeeded()` (§8) and the RTDB fallback inside `Classes::fetch_section_students()`.

**Nothing was committed, staged, pushed or deployed.**
