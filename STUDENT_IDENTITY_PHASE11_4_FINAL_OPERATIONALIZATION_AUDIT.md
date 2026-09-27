# Phase 11.4 — Final SRNO Operationalization Audit & Release Gate

**Date:** 2026-08-14 · **Branch:** `integration/phase2` @ `758c924` · **Type: VERIFICATION AUDIT — zero code changed.**

Nothing committed, staged, pushed, merged, rebased or deployed. No rules or indexes touched. No test
account created. No production data written. Per §27, this stops at the report.

**Method:** source-first. Every claim below was re-derived from current source this pass. Where a
previous report disagreed with the source, the source won and the discrepancy is stated (§2 of the
brief). **One prior conclusion of mine was wrong and is corrected in §14.**

---

## 1. Executive Summary

**The answer to the phase's primary question is YES, with three qualifications.**

A real school can now operate ZenX by SR Number wherever a student is *directly identified*, and
Student ID remains the canonical technical identity in every persisted record. This was verified by
tracing actual write paths, not by grepping for `srNo`.

**The strongest evidence — a negative result.** A repo-wide scan of the Teacher app for `srNo`
returns **21 hits: every one a model field, a read mapping, a display string, or a search predicate.
Zero writes.** No repository, no save path, no Firestore payload anywhere in the Teacher app contains
`srNo`. The same holds in `Fees.php`: `srNo` appears at 4 sites, all inside `lookup_student` and
`_searchByName` — all read/display. **SRNO has not leaked into persistence anywhere.**

**Three qualifications:**

| | Issue | Class |
|---|---|---|
| **Q1** | **I was wrong about PTM in Phase 11.3.** The RSVP write already sources every denorm field from the cached `user` object, so adding `srNo` there costs **zero** reads and needs no migration. My prior report called it FUTURE on the strength of an N+1 that only applies to the *read* side. **Corrected in §14.** | **SHOULD FIX NOW** |
| **Q2** | Transport and Hostel student pickers **match** on SRNO but never **display** it — both controllers `unset()` fields before returning, so the operator cannot see which SR Number matched. | **SHOULD FIX NOW** |
| **Q3** | Certificates remains RTDB-backed; Student 360 still exposes APAAR unmasked; SIB-B1 still live; `Fees::_searchByName()` still uncached. All four **re-verified live this pass**, all pre-existing, none fixed. | PRE-EXISTING |

**Verdict: READY WITH CONDITIONS** (§35).

---

## 2. Current Student Identity Architecture — verified against source

| Identifier | Format | Scope | Generated | Operator-entered | Verified at |
|---|---|---|---|---|---|
| **Student ID** | `STU` + 4 digits | **GLOBAL** | `Id_generator::generate('STU')` | Never | `Id_generator.php:30,60` |
| **SRNO** | `^[0-9]{1,9}$` | **SCHOOL** | `Sr_no_service::allocateNext()` | Only historical import | `Sr_no_service.php:99,105` |
| **Admission Number** | `YYYY/TOKEN` | SCHOOL | `Admission_no_service::allocate()` | **Never — no input path** | `Admission_no_service.php` |
| **Roll Number** | free text (`18`, `A12`, `B-07`) | School+Session+Class+Section | Operator | Yes | `students.rollNo` |

**Architecture is intact.** No identifier changed role this phase or last.

## 3. Student ID vs SRNO Responsibilities — proven, not asserted

| | Student ID | SRNO |
|---|---|---|
| Used to **find** a student | ✔ | ✔ (new) |
| **Persisted** on module records | ✔ everywhere | **✖ nowhere — verified** |
| Foreign key | ✔ | ✖ |
| Login identity | ✖ (Firebase UID) | ✖ |
| Global uniqueness | ✔ | ✖ (school-scoped) |

**Single canonical resolver confirmed.** `_resolveStudentRef()` exists once
(`MY_Controller.php:1021`) with exactly two callers (`Fees.php:2357`, `Sis.php:3505`). No module has
its own SRNO logic.

**One documented exception to "Sr_no_service is the only `srNoIndex` path":** `Sis.php:6222/6224`
reads and updates `srNoIndex` directly — the Phase 11 hard-delete integrity marker. It performs
**no resolution** and moves no ownership; it only stamps `currentOwnerDeleted`. Acceptable and
intentional, recorded here so it isn't mistaken for drift.

## 4. Cross-Module Workflow Matrix

Type: **A** = direct identifier · **B** = roster · **C** = transaction · **D** = future/blocked.
Reads/writes are *additional* cost introduced by SRNO support.

| Module | Workflow | Platform | Type | Resolved by | Persisted | +Reads | +Writes | +RTT | N+1 | Security | Status |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Fees | Counter direct entry | Admin | **A** | `_resolveStudentRef` | `studentId` | **+1** (SRNO only) | 0 | +1 | none | none | **DONE** |
| Fees | Browse / picker | Admin | **A** | in-memory match | `studentId` | **0** | 0 | 0 | none | none | **DONE** |
| Fees | Collection / receipt / allocation / posting | Admin | **C** | already resolved | `studentId` | 0 | 0 | 0 | none | none | **VERIFIED** |
| Fees | Demands / defaulters / concessions | Admin | **C** | `student_id` POST | `studentId` | 0 | 0 | 0 | none | none | **VERIFIED** |
| SIS | Student search | Admin | **A** | cached index match | n/a | **0** | 0 | 0 | none | none | **DONE** |
| SIS | `get_student` | Admin | **A** | `_resolveStudentRef` | n/a | **+1** (SRNO only) | 0 | +1 | none | none | **DONE** |
| Student 360 | Workspace | Admin | **A**→route | via SIS search | n/a | 0 | 0 | 0 | none | ⚠ APAAR | **REACHABLE** |
| Transport | Student picker | Admin | **A** | cached index match | `studentId` | **0** | 0 | 0 | none | none | **PARTIAL** (Q2) |
| Library | Student picker | Admin | **A** | cached index match | `studentId` | **0** | 0 | 0 | none | none | **DONE** |
| Hostel | Student picker | Admin | **A** | cached index match | `studentId` | **0** | 0 | 0 | none | none | **PARTIAL** (Q2) |
| Certificates | Student list | Admin | **D** | **RTDB** | n/a | — | — | — | — | — | **BLOCKED** |
| TC | Generation | Admin | **C** | `Admission Number`→ID | `studentId` | 0 | 0 | 0 | none | none | **NO CHANGE NEEDED** |
| Campus Access | Gate-pass picker | Admin | **A** | name-only match | `studentId` | 0 | 0 | 0 | none | gate surface | **FUTURE** |
| Attendance | Class roster | Admin+Teacher | **B** | roster | `studentId` | **0** | 0 | 0 | none | none | **DISPLAY DONE** |
| Marks / Exam / Results | Roster entry | Admin+Teacher | **B/C** | roster | `studentId` | **0** | 0 | 0 | none | none | **DISPLAY DONE** |
| Homework / LMS | Submission list | Teacher | **B** | roster | `studentId` | **0** | 0 | 0 | none | none | **DISPLAY DONE** |
| Red Flags | Quick-flag sheet | Teacher | **B** | roster | `studentId` | **0** | 0 | 0 | none | none | **DISPLAY DONE** |
| Teacher | Student search | Teacher | **A** | in-memory exact match | n/a | **0** | 0 | 0 | none | none | **DONE** |
| Teacher | Global search | Teacher | **A** | in-memory match | n/a | **0** | 0 | 0 | none | none | **DONE** |
| **PTM** | RSVP list | Teacher | **B** | RSVP doc | `studentId` | — | — | — | ⚠ read-side | none | **Q1 — correction** |
| Communication | Audience selection | Admin | **B** | class/section/role | `studentId` | 0 | 0 | 0 | none | none | **N/A** |
| Documents | Profile-driven | Admin | **B/C** | roster/profile | `studentId` | 0 | 0 | 0 | none | none | **N/A** |
| ID Card | Print sheet | Admin | **B** | roster | n/a | **0** | 0 | 0 | none | none | **DISPLAY DONE** (Ph 11) |
| Import | Bulk create | Admin | **C** | file rows | `studentId` | 0 | 0 | 0 | none | ⚠ SIB-B1 | **NO CHANGE** |
| Export | — | Admin | — | — | — | — | — | — | — | — | **NONE EXISTS** |
| Parent | My Child / switcher / all modules | Parent | **C** | authenticated | `studentId` | **0** | 0 | 0 | none | none | **DONE** (Ph 11) |

## 5–6. Admin Panel & Fees Audit

**6.1 Direct lookup — VERIFIED.** `Fees::lookup_student()` calls `_resolveStudentRef()` before the
student read. Student ID path: 0 extra reads, unchanged. SRNO path: +1 read.

**6.2 Browse — VERIFIED.** `_searchByName()` haystack now includes `srNo` (`Fees.php:2896-2898`) and
each row returns `sr_no` (`:2916`). Zero reads — the documents were already downloaded.

**6.3 Collection persists Student ID — VERIFIED END-TO-END, and this is the critical proof.**

The chain that matters:

```
operator types "12"
  → lookup_student resolves → returns user_id = "STU0012"
  → fees_counter.php selectStudent():  FC.userId = s.user_id
                                       #user_id.value = FC.userId      ← box REWRITTEN to STU0012
  → submit_fees posts FC.userId = "STU0012"
  → feeDemands / receipts / allocations write 'studentId' => $studentId
```

The SR Number is **overwritten in the input box the moment resolution succeeds**, so it never reaches
`submit_fees`, `fetch_months`, `get_student_demands` or `get_student_allocations`. All 8
`'studentId' =>` write sites in `Fees.php` persist the canonical ID (e.g. the demand payload at
`:4957`). **`srNo` appears in `Fees.php` at exactly 4 lines, all read/display, zero writes.**

**6.4 Re-admission — VERIFIED (unit-tested).** `Sr_no_service::resolve()` returns `currentStudentId`
only. After relink, SRNO 12 → STU0047. Historical fee records keyed to STU0001 are never touched —
nothing in the resolve path writes to any fee collection.

**6.5 Fee latency —** +1 read on the SRNO path only. See §28.

## 7. Teacher App Audit — the negative proof

**Repo-wide `srNo` scan (21 hits), classified:**

| Kind | Count | Files |
|---|---|---|
| Model field declaration | 4 | `StudentDoc`, `StudentInfo`, `StudentsViewModel`, `MarksViewModel`, `AttendanceViewModel` |
| Read mapping (`= doc.srNoText`) | 4 | `StudentRepository`, `StudentsViewModel` ×2, `MarksViewModel`, `AttendanceViewModel` |
| Display string | 7 | roster card, detail panel, attendance row, marks grid, homework row, quick-flag header, global search subtitle |
| Search predicate | 2 | `StudentsViewModel:353`, `SearchViewModel:167` |
| Accessor / comment | 4 | `StudentDoc` |
| **Write to Firestore** | **0** | **none** |

**Persistence verified independently** — the Teacher repositories that write student-keyed documents
all use `"studentId" to studentId`:

- `AttendanceFirestoreRepository.kt:71, 103, 266` (7.1 ✔)
- `ExamFirestoreRepository.kt:144, 213` — marks (7.2 ✔)
- `CampusLifeFirestoreRepository.kt:298`, `LeaveFirestoreRepository.kt:298, 354`

Homework (7.3) and Red Flag (7.4) save paths were **not modified in any phase** and continue to key
on `studentId`; the Phase 11 red-flag change corrected the *roll number* denorm only.

**7.5 Search — VERIFIED.** `StudentsViewModel:353` matches SRNO **exactly** (`equals`, not
`contains`), so `1` does not return every student containing a 1. Name/roll stay substring. Local
in-memory filter — **0 reads**.

## 8. Parent App Audit — sibling switching verified field-by-field

`DashboardViewModel.switchToSibling()` rebuilds the `User` explicitly. Verified at source
(`:260-283`) that it sets **all** identity + position fields from `doc`:

| Field | Source | Line |
|---|---|---|
| `userId` | `doc` | 261 |
| `name`, `className`, `section`, `rollNo` | `doc` | 262-265 |
| **`srNo`** | `doc.srNoText` | **279** |
| **`admissionNumber`** | `doc.admissionNumber` | **280** |
| **`status`** | `doc.status` | **283** |

**No stale SRNO, Student ID, Admission Number or Roll Number is possible** — every field comes from
the newly-fetched sibling document, never from `current`. The switch reuses the existing
`getStudent()` call, so **0 additional reads**.

**Parent App changed 0 files in Phase 11.3** (dirty count unchanged at 20), correctly: the parent is
already authenticated as the child, so there is nothing to look up. No SRNO query was added.

## 9–13. Attendance · Examination · Results · Homework · Red Flags

All five are **Type B (roster)**. SRNO is **displayed**, no search box added, no lookup query added,
`studentId` persisted. Verified above. This is exactly §3-B's prescription: *"If the student is
already present in the roster: display the identity, select the student, operate on studentId. No
extra Firestore lookup merely to resolve SRNO."*

## 14. PTM — **CORRECTION TO MY PHASE 11.3 REPORT**

**What I wrote in Phase 11.3 (§14):** *"Displaying SRNO there would require either adding a denorm
field to the RSVP document and changing the Parent app's write path (cross-surface schema drift), or
a per-RSVP student lookup — an N+1."*

**What the source actually shows.** `PtmFirestoreRepository.setRsvp()` (`:143-180`) takes
`rollNo`, `studentName`, `className`, `section` as parameters, and `PtmDetailViewModel:169-175` /
`:243-249` supplies **every one of them from the cached `user` object**:

```kotlin
studentName  = user.name,
className    = user.className,
section      = user.section,
rollNo       = user.rollNo,       //  <-- and since Phase 11, user.srNo exists right here
```

**Therefore `"srNo" to user.srNo` can be added to the RSVP payload at ZERO Firestore cost.** No extra
read, no round trip, no migration (Firestore is schemaless — the field simply appears on new RSVPs).

**My error:** the N+1 concern is real but applies only to the **read** side — displaying SRNO on
*existing* RSVPs written before the change. It does not apply to the write side at all, and I let it
condemn the whole feature. The correct classification is **SHOULD FIX NOW**, not FUTURE.

**Honest limits of the fix:** RSVPs created before the change carry no `srNo` and would display
blank until re-RSVP. **No backfill is proposed** — a backfill would be a production data write,
outside authorization.

**Scope if authorized:** Parent `setRsvp()` signature + payload (1 file), Parent `PtmDetailViewModel`
call sites ×2 (1 file), Teacher `PtmDoc` model field (1 file), Teacher `MyPtmScreen` subtitle
(1 file). **4 files, 0 reads, 0 new writes** (one extra key on an existing write).

## 15–17. Transport · Library · Hostel — **Q2: matching works, display doesn't**

All three route through cached in-memory indexes that now carry and match `srNo`
(`Transport_service.php:3519-3527`, `Operations_accounting.php:143-150`). **Search works today.**

**But the operator cannot see the SR Number in the results**, because both controllers strip fields
before responding:

```php
// Transport.php:1020-1024   AND   Hostel.php:553-557  (identical pattern)
foreach ($results as &$r) {
    $r['class'] = trim(($r['class'] ?? '') . ' ' . ($r['section'] ?? ''));
    unset($r['section'], $r['user_id']);
}
```

`srNo` survives the `unset` (it isn't named) but the **UI templates don't render it**, so a search by
SR Number returns a name with no visible confirmation of *which* register number matched. On a
picker that assigns a bus route or a hostel bed, that is a real mis-selection risk — the same class
of risk §1 of the brief warns about for Fees.

**Library** (`Library.php:619-622`) does **not** strip fields and is unaffected by the `unset`, but
its template likewise doesn't render `srNo`.

**Classification: SHOULD FIX NOW.** Cost: **0 reads** — add `sr_no` to the response row and one
column/line to three templates.

## 18–19. Communication · Documents

**Type B / C, no change needed or made.** Communication selects audiences by class/section/role —
there is no individual-identifier entry point. Documents are profile/roster-driven and key on
`studentId` via `Document_service`.

## 20. Certificates / TC — **BLOCKED, re-verified**

`Certificates::get_students()` remains **RTDB-backed**, confirmed at source this pass:

```php
Certificates.php:333  $this->firebase->shallow_get("Schools/{$school}/{$session}/{$classKey}");
Certificates.php:478, 587, 635  $this->firebase->get("Schools/{$this->school_name}/Config/Profile");
```

Per §10 **no RTDB fallback or workaround was added.** SRNO cannot be integrated here until the
endpoint is migrated to Firestore. Correct order: **migrate → then SRNO.**

**TC generation itself needs no change** — it reads `Admission Number` as a display field with a
Student ID fallback and persists `studentId`.

## 21. Student 360

**Reachable by SRNO with zero code change** — it is a `view/{studentId}` route fed by the SIS search,
which now accepts SR Numbers. SRNO already displays in its Profile panel.

⚠ **P11-N1 re-verified STILL PRESENT:** `student360/workspace.php:877` renders
`'APAAR ID' => $acad['apaarId']` in **plaintext**, with no masking and no audit entry, behind a
`SIS/view` gate — broader than the `Sis::apaar_reveal()` endpoint's Admin/Principal/SSA gate. **Not
changed** (§18: do not silently change security behaviour). See §33.

## 22. Re-admission — verified

| Step | index state | `resolve("12")` |
|---|---|---|
| First admission | `currentStudentId: STU0001` | `STU0001` |
| After relink | `currentStudentId: STU0047`, `historicalStudentIds: [STU0001]` | **`STU0047`** |

Unit-tested both directions (`test_after_readmission_…`, `test_historical_owner_is_retained_but_never_returned_by_resolve`).
Because every SRNO-enabled surface routes through the same `resolve()`, this holds identically for
Fees, SIS, Transport, Library, Hostel and Teacher search — there is no second resolver that could
disagree.

## 23. Promotion — verified

Unit-tested: a student-document write mutating class/section/roll leaves `srNoIndex` byte-identical
and `resolve("12")` still returns `STU0012`. Student ID, SRNO and Admission Number preserved; Class,
Section, Roll may change.

## 24. Roll Number — **NEW FINDING: no uniqueness validation exists at all**

Semantics are correct and unchanged: School+Session+Class+Section scoped, free text, alphanumeric-safe
(`formatRollNo()` renders `A12` verbatim, blank → `—`). **Deliberately excluded from every search
haystack** (Fees, SIS, both Teacher searches), with an in-code comment at each site explaining why.

**Finding (OBSERVATION, not a defect):** a repo-wide search for roll-number uniqueness validation
returns **nothing** — no global check, and no class+section-scoped check either. Two students in the
same section can hold Roll 18 today.

The brief (§11) says *"If uniqueness validation exists, it must be scoped to School + Session + Class
+ Section"* — it does not exist, so there is nothing mis-scoped and **nothing to fix in this phase**.
Whether a scoped uniqueness rule *should* exist is a business decision, recorded as **FUTURE**.
Critically: **no global uniqueness was invented**, per §11's prohibition.

## 25. Admission Number — verified unregressed

System-generated `YYYY/TOKEN`, independent of Class/Section/SRNO/Roll, **not reconstructed from
session+class+SRNO**, new on re-admission, no operator-entry path anywhere. Displayed on Student
profile, Student 360, Parent App and certificates. Not used as a lookup key and not in any search
haystack.

## 26. APAAR

**Not a lookup key anywhere.** Absent from every search haystack (Fees, SIS, Transport,
Operations_accounting, both Teacher searches) and from every response added in 11.3. Grep confirms
`apaar` appears in both app repos **only inside explanatory comments** — zero code references.
Masking/audited-reveal policy unchanged.

**P11-N1 remains open** (§21) — reported, not fixed.

## 27. Multi-Tenant Security

| Requirement | Status | Evidence |
|---|---|---|
| A+12→STU0012, B+12→STU0456 | **PASS** | unit test, shared backing store |
| A never resolves B's student | **PASS** | unit test; B's own lookup still works, proving scoping not universal failure |
| No client-controlled schoolId | **PASS** | unit test: a caller-supplied schoolId cannot redirect resolution — scope comes from `Firestore_service`, set from authenticated claims |
| Unknown SRNO fails closed | **PASS** | `not_found`, no fallback, no nearest match |
| **No global `srNo ==` query anywhere** | **PASS** | repo-wide grep for `['srNo','==']` / `whereEqualTo("srNo"` → **0 matches in all three repos** |
| No RBAC gate widened | **PASS** | Fees/view, SIS/view unchanged |

**Defence in depth intact:** after resolution the student loads as `students/{schoolId}_{studentId}`,
so even a corrupt index entry yields a miss, never a cross-tenant read.

## 28. Latency / Firestore Cost

| Workflow | Existing Reads | New Reads | Existing Writes | New Writes | Extra RTT | N+1 | Verdict |
|---|---:|---:|---:|---:|---:|---|---|
| Fees counter — Student ID | 1 | **0** | 0 | 0 | **0** | none | **ZERO-COST** |
| Fees counter — SRNO | 1 | **+1** | 0 | 0 | +1 | none | **ONE-READ** |
| Fees Browse | N (uncached scan) | **0** | 0 | 0 | 0 | none | **ZERO-COST** |
| Fees collection / receipt / posting | unchanged | **0** | unchanged | **0** | 0 | none | **ZERO-COST** |
| SIS search | 1 (90 s cache) | **0** | 0 | 0 | 0 | none | **ZERO-COST** |
| SIS `get_student` — SRNO | 1 | **+1** | 0 | 0 | +1 | none | **ONE-READ** |
| Transport / Library / Hostel pickers | 1 (300 s cache) | **0** | 0 | 0 | 0 | none | **ZERO-COST** |
| Teacher rosters ×5 (attendance, marks, homework, red flag, students) | 0 | **0** | 0 | 0 | 0 | none | **ZERO-COST** |
| Teacher student + global search | 0 | **0** | 0 | 0 | 0 | none | **ZERO-COST** |
| Parent App (all surfaces) | 0 | **0** | 0 | 0 | 0 | none | **ZERO-COST** |
| *PTM if Q1 authorized* | 0 | **0** | 1 | **0** (one extra key) | 0 | none | **ZERO-COST** |
| *Transport/Hostel display if Q2 authorized* | 0 | **0** | 0 | 0 | 0 | none | **ZERO-COST** |

**Totals: +1 read on 2 of 22 workflows. Everything else is free.**

**§16's rule was honoured:** no workflow queries `srNoIndex` to obtain SRNO for display. Every display
reads `srNo` off the student document already in hand. The forbidden
`load student → query index → display` pattern appears nowhere.

## 29. N+1 Analysis

**No N+1 pattern was introduced.** Every SRNO display reads a field from a document already
downloaded by the enclosing roster/list query. The one resolution call is a single doc-id fetch
outside any loop.

**The only N+1 *risk* identified is on the PTM read side** — displaying SRNO on pre-existing RSVPs
would require one student read per RSVP. **That is precisely why the Q1 fix is proposed at the write
side only** (§14), which has no loop and no lookup.

## 30. Automated Tests

`tests/Unit/SrNoDualLookupTest.php` — **15 tests, 60 assertions, all pass.**

Covers: token-shape disjointness ×2 · resolution + leading zeros + one-read cost ×3 · fail-closed
(unknown, malformed) ×2 · **multi-tenant ×3** · **re-admission ×2** · promotion ×1 · side-effect
freedom + index direction ×2.

**Full suite: 734 tests, 2348 assertions, 10 failures — exactly the pre-existing baseline
(719 → 734 = +15, all new tests passing). Zero new failures.** The 10 are cross-repo source-grep
tests (Backup ×1, Homework ×5, ParentHomeworkRepo ×3, TeacherDeleteCascade ×2); none touches a file
changed in 11.3.

**Coverage gaps, stated honestly:**
- `_resolveStudentRef()` is **not directly unit-tested** — it lives on a CI3 controller that cannot be
  bootstrapped in this suite. What *is* tested is the invariant it depends on (shape disjointness),
  so a widened format fails loudly. **ENVIRONMENT LIMITATION**, §35.
- Persistence assertions (Fees→`studentId`, marks→`studentId`, etc.) are **source-verified this
  pass**, not asserted by an automated test — there is no integration harness that can write to
  Firestore. Listed in the UAT matrix instead.
- Alphanumeric Roll Number (`A12`, `B-07`) is covered by Phase 11's display fix and by its exclusion
  from every haystack; **no test asserts a roll number is never treated as SRNO** — the exclusion is
  structural (it is simply not in any haystack). Recommended as a cheap regression guard, §34.

## 31. UAT Matrix — **NOT EXECUTED**

**No UAT PASS is claimed anywhere in this report.** Both apps are installed on device `I2011`; the
Admin Panel needs a browser session at `localhost:8080`. Each row requires a real tenant login, which
I do not create.

**Admin Panel**

| # | Test | Expected |
|---|---|---|
| A1 | Fee counter: `STU0012` | student found, behaviour unchanged |
| A2 | Fee counter: `12` | same student; banner `✓ SR No. 12 → STU0012 · Rahul Sharma · Roll 18` |
| A3 | Fee counter: unknown SR | `SR No. 'N' not found in this school` |
| A4 | Fee counter: SR of a hard-deleted owner | `points to a student record that no longer exists` |
| A5 | **Collect a fee started from the SRNO path** | **receipt / demand / allocation / ledger all carry `studentId = STU0012`** |
| A6 | Browse: search `12` | SRNO-12 student listed, SR No. column populated |
| A7 | SIS search `12` | resolves; name/phone/class search unbroken |
| A8 | Transport / Library / Hostel picker: search by SR | student found (⚠ SR not displayed until Q2) |
| A9 | Re-admitted student: search SR 12 | returns the NEW Student ID |
| A10 | Promoted student: search SR 12 | same Student ID, new class/roll |
| A11 | Two schools, same SR 12 | each resolves its own student |

**Teacher App (device `I2011`)**

| # | Test | Expected |
|---|---|---|
| T1 | Roster card | `Roll: 18 · SR: 12`; blank SRNO shows roll only |
| T2 | Student search by SR | exactly that student (exact match) |
| T3 | Attendance / marks / homework / red-flag sheet | SR No. visible |
| T4 | **Save marks, homework, a red flag** | **written document keys on `studentId`** |
| T5 | Global search by SR | student appears with `SR 12` in subtitle |
| T6 | Alphanumeric roll `A12` | displays verbatim; does not match an SR search |

**Parent App**

| # | Test | Expected |
|---|---|---|
| P1 | Profile | Student ID, SR No., Admission No., Class, Section, Roll No. all shown |
| P2 | Switch A→B | **all four identity fields update**; no stale SRNO/Adm No./Roll |
| P3 | Switch B→A | A's values restored exactly |
| P4 | Cold restart | values persist; withdrawn status not reset to Active |

## 32. Remaining Gaps

| # | Gap | Class |
|---|---|---|
| G1 | **PTM SRNO** — free at the write site, my prior report wrongly deferred it (§14) | **SHOULD FIX NOW** |
| G2 | **Transport / Hostel / Library pickers don't display SRNO** though they match it (§15-17) | **SHOULD FIX NOW** |
| G3 | Certificates RTDB-blocked | **ARCHITECTURE BLOCKER** (pre-existing) |
| G4 | Campus Access picker matches name only — not widened (security-adjacent) | FUTURE |
| G5 | `_resolveStudentRef()` not directly unit-testable | **ENVIRONMENT LIMITATION** |
| G6 | No Roll Number uniqueness validation of any kind (§24) | FUTURE (business decision) |
| G7 | No student export exists to carry SRNO | FUTURE |
| G8 | Browser/device UAT not executed | **ENVIRONMENT LIMITATION** |

## 33. Pre-existing Issues — all re-verified live this pass

| ID | Severity | Status | Evidence |
|---|---|---|---|
| **SIB-B1** | **HIGH — data loss** | **STILL LIVE** | `Sis.php:4639` `'duplicate phone within file'`, `:4642` `'existing student (phone match)'`. Bulk import treats siblings sharing a parent phone as duplicates: `skip` discards child 2, `update` overwrites child 1. **Not touched. SRNO work did not worsen it** — the SRNO claim happens *after* this block, so a discarded sibling simply never reaches SRNO allocation (coverage reduced, no corruption added). |
| **P11-N1** | MEDIUM — security | **STILL LIVE** | `student360/workspace.php:877` — APAAR plaintext, unmasked, unaudited, at `SIS/view` |
| **P11.3-N1** | MEDIUM | **STILL LIVE** | `Certificates.php:333/478/587/635` — RTDB |
| **P11.3-N2** | MEDIUM — latency | **STILL LIVE** | `Fees::_searchByName()` uncached full-collection scan; the only one of six search implementations without a cache. **Not worsened** — the SRNO addition is a string concat inside the existing loop |
| **P11.2-N3** | LOW | unchanged | `Sr_no_service::init()` receives `$school_id` in two callers, `$this->parent_db_key` in another. Immaterial to `resolve()` |

## 34. Recommended Next Steps

**MUST FIX NOW:** none. No code defect or security regression was introduced by the SRNO work.

**SHOULD FIX NOW** (both zero-cost, both need authorization):
1. **G1 — PTM SRNO at the write site.** 4 files, 0 reads, 0 new writes. Corrects my Phase 11.3 error.
2. **G2 — display SRNO in the Transport / Hostel / Library pickers.** 0 reads; closes a
   mis-selection risk on route/bed assignment.

**FUTURE:** Certificates RTDB migration → then SRNO · `Fees::_searchByName()` caching · Campus Access
picker · Roll Number scoped-uniqueness decision · student export · a cheap regression test asserting
a roll number never matches an SRNO search.

**SEPARATE AUTHORIZATION:** SIB-B1 (HIGH, data loss, live) and P11-N1 (APAAR).

## 35. Final Verdict

# READY WITH CONDITIONS

**Ready.** The SRNO operationalization is correct, complete for its authorized scope, and verified at
source rather than assumed. Student ID remains the canonical technical identity in **every** persisted
record — proven by the negative result that `srNo` is written nowhere in either the Teacher app or
`Fees.php`. Multi-tenant isolation holds structurally and is unit-tested. Re-admission and promotion
behave correctly through a single shared resolver, so no surface can disagree with another. The whole
feature costs **+1 Firestore read on 2 of 22 workflows**; the other 20 are free. No rules, no indexes,
no RTDB, no N+1.

**Conditions, classified per §25:**

| Class | Item |
|---|---|
| **CODE DEFECT** | **None.** |
| **SECURITY DEFECT** | **None introduced.** P11-N1 (Student 360 APAAR) is pre-existing and open. |
| **ARCHITECTURE BLOCKER** | Certificates RTDB (G3) — blocks SRNO there only, nothing else. |
| **ENVIRONMENT LIMITATION** | Browser/device UAT not executed (G8); `_resolveStudentRef()` not directly unit-testable (G5). **Neither is grounds for BLOCKED**, per §25. |
| **FUTURE ENHANCEMENT** | G4, G6, G7, and the two SHOULD-FIX items pending authorization. |
| **PRE-EXISTING ISSUE** | SIB-B1 (HIGH), P11-N1, P11.3-N1, P11.3-N2, P11.2-N3 — all re-verified live, none worsened. |

**To reach READY:** execute the §31 UAT matrix — above all **A5** (a fee collected via the SRNO path
must persist `studentId`) and **T4** (teacher saves must persist `studentId`). Those two rows are the
business-critical assertions this audit verified in source but could not execute.

## 36. Exact Files Modified — **NONE**

This phase modified **zero** source files. The only file added is this report.

For reference, the Phase 11.3 change set that this audit verifies:
**Admin (6):** `MY_Controller.php`, `Fees.php`, `Sis.php`, `Transport_service.php`,
`Operations_accounting.php`, `fees_counter.php` · **+1 test:** `SrNoDualLookupTest.php`
**Teacher (9):** `StudentsScreen`, `StudentsViewModel`, `SearchViewModel`, `AttendanceViewModel`,
`AttendanceScreen`, `MarksViewModel`, `MarksScreen`, `HomeworkTeacherScreen`, `QuickFlagSheet`
**Parent (0).**

## 37. Git Safety / Working Tree

```
Branch: integration/phase2        HEAD: 758c924   (unchanged — nothing committed)

Admin Panel   423 dirty + this report   (unchanged by this phase)
Teacher App    29 dirty                 (unchanged by this phase)
Parent App     20 dirty                 (unchanged since before Phase 11.3)

firebase-rules/firestore.rules         mtime 2026-08-13  — untouched
firebase-rules/firestore.indexes.json  mtime 2026-08-12  — untouched
```

All pre-existing working-tree changes preserved. No unrelated file modified, staged or reverted.

---

**Nothing committed. Nothing pushed. Nothing deployed. Awaiting authorization for G1 and G2.**
