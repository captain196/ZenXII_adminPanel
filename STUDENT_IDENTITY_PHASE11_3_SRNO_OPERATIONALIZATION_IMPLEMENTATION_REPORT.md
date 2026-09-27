# Phase 11.3 — SRNO Operationalization · IMPLEMENTATION REPORT

**Date:** 2026-08-14 · **Branch:** `integration/phase2` · **Type: IMPLEMENTATION**

**Nothing committed. Nothing staged. Nothing pushed. Nothing merged, rebased or deployed. No test
account created. No production data written. No Firestore rule or index modified. No RTDB introduced.**

---

## 1. Executive Summary

SRNO is now a **first-class operational identifier** alongside Student ID, across the Admin Panel and
the Teacher App. A school that operates on its own register number can now find and select a student
by typing it — and the canonical Student ID remains the sole technical identity underneath, in every
module, unchanged.

**The whole feature cost one Firestore read, on one code path.**

| | |
|---|---|
| **Files changed** | **6 Admin + 9 Teacher + 1 new test file = 16** |
| **New Firestore reads** | **+1**, only on `Fees::lookup_student` / `Sis::get_student` and only when the operator types an SR Number |
| **New writes / queries / collections / indexes / rules** | **0** |
| **New SRNO index or service** | **none** — `Sr_no_service::resolve()` reused unmodified |
| **Tests** | 15 new, all pass. Full suite **734 tests / 10 failures = exact pre-existing baseline**, zero new failures |
| **Parent App** | **0 files changed** — Phase 11 already delivered it correctly |

Three findings shaped the implementation:

**(a) One shared resolver, one branch, zero duplication.** `MY_Controller::_resolveStudentRef()` is
the single place that turns an operator-typed reference into a canonical Student ID. Fees and SIS
both call it. No module re-implements school scoping, normalisation, re-admission handling or error
wording (§37 satisfied).

**(b) The branch is provably unambiguous.** Student ID is `STU`+4 digits; SRNO is digits-only. The
token shapes are **disjoint**, so a single input box is safe and no mode selector is needed (§31).
This invariant is now a test, so it fails loudly if anyone ever widens either format.

**(c) Searches were free.** Every student search in the panel and the Teacher app filters an
already-loaded (usually cached) roster. SRNO was simply absent from the match. Adding it cost
**zero** Firestore reads in all six search surfaces.

**Verdict: READY WITH CONDITIONS** (§40) — code-verified, test-verified, compile-verified. **Browser
and device UAT NOT EXECUTED** (§34).

---

## 2. Business Requirement

> *"My student's SR Number is 12."* — the operator must be able to type `12` and reach the same
> student that `STU0012` reaches.

Implemented exactly as specified:

```
   "STU0012"                          "12"
        │                               │
   not digits-only                digits-only
        │                               │
   (0 reads, passthrough)     Sr_no_service::resolve()
        │                     scoped {authenticatedSchoolId}_12
        │                               │
        │                        currentStudentId
        └───────────────┬───────────────┘
                        │
              studentId = "STU0012"     ← canonical from here
                        │
     Fees / Attendance / Results / … COMPLETELY UNCHANGED
```

## 3. Current Architecture (re-verified, not assumed)

Before writing any code I reconciled the Phase 11 / 11.1 / 11.2 reports against live source.
**No drift found.**

| Checked | Result |
|---|---|
| `Sr_no_service::resolve()` signature and body | unchanged |
| Phase 11 Teacher `RollNoFormat.kt` | present |
| Phase 11 Teacher `StudentInfo.srNo` | present |
| Phase 11 Parent `User.srNo` / `.admissionNumber` | present |
| Phase 11 `currentOwnerDeleted` hard-delete markers | present (6 references) |
| Branch / HEAD | `integration/phase2` @ `758c924` |

## 4. Student ID vs SRNO Responsibilities

| | Student ID | SRNO |
|---|---|---|
| Example | `STU0012` | `12` |
| Scope | **Global** | **School** |
| Role | **Canonical technical identity + sole foreign key** | **Operational/human lookup + display** |
| Stored on module records | **Yes — everywhere, unchanged** | **Never** |
| Used to find a student | Yes | **Yes (new)** |
| Authentication | No | **No** (§11 — nothing touched) |
| Changes on re-admission | Yes (new ID) | No (preserved, relinked) |
| Changes on promotion | No | No |

## 5. Admin Panel Changes

| File | Change |
|---|---|
| `application/core/MY_Controller.php` | **NEW** `_resolveStudentRef()` — the one shared resolver |
| `application/controllers/Fees.php` | `lookup_student()` dual lookup; `_searchByName()` SRNO haystack + `sr_no` in result rows |
| `application/controllers/Sis.php` | `search_student()` SRNO in the cached index + haystack + result row; `get_student()` dual lookup |
| `application/libraries/Transport_service.php` | `srNo` in the cached student index + exact match |
| `application/libraries/Operations_accounting.php` | `srNo` in the shared picker index + exact match (Library, Hostel) |
| `application/views/fees_counter.php` | Label, placeholder, resolved-via confirmation, Browse SR No. column, colspans |

### The shared resolver

```php
protected function _resolveStudentRef(string $input): array
```

- Digits-only → `Sr_no_service::resolve()` → `currentStudentId`. **1 read.**
- Anything else → returned unchanged as a Student ID. **0 reads, zero behaviour change.**
- Returns `via` = `'studentId'|'srNo'` so the UI can show which identifier matched.
- **Never loads the student** — the caller does that once, keyed `{schoolId}_{studentId}`.

## 6. Fees Changes (the primary use case)

**`lookup_student()`** — the counter's Find box now accepts either identifier. Resolution happens
**before** the student read, so there is still exactly one student read on both paths. Error wording
is path-specific (`SR No. '12' not found in this school` vs `Student '…' not found`), and a hard-
deleted SRNO owner produces an explicit *"points to a student record that no longer exists"* rather
than a confusing generic miss.

Response gained `sr_no`, `roll_no` and `resolved_via` — all from the document already read.

**`_searchByName()`** — `srNo` added to the match haystack and to each result row. Previously typing
`12` into Browse matched Class 12 and section noise but never the student whose register number is
12. **Zero reads added** — the documents were already downloaded.

**Fee records are untouched.** `submit_fees`, `fetch_months`, `get_student_demands`,
`get_student_allocations` and `Fee_concessions` all still key on `studentId`. **No `srNo` is written
to any fee document** (§16, §35).

## 7. Teacher App Changes

Per §7's classification, roster-driven workflows **display** SRNO and keep using the already-resolved
Student ID; only genuine direct-search surfaces accept SRNO as input.

| File | Change | Type |
|---|---|---|
| `ui/students/StudentsScreen.kt` | Roster card shows `Roll: 18 · SR: 12`; detail panel already had SR No. | display |
| `ui/students/StudentsViewModel.kt` | Student search matches SRNO (**exact**, not substring) | **search** |
| `ui/search/SearchViewModel.kt` | Global search: SR in subtitle + haystack | **search** |
| `ui/attendance/AttendanceViewModel.kt` | `srNo` on `StudentAttendanceRow` | display |
| `ui/attendance/AttendanceScreen.kt` | Row shows `Roll 08 · SR 12` | display |
| `ui/marks/MarksViewModel.kt` | `srNo` on `StudentMark` | display |
| `ui/marks/MarksScreen.kt` | Under the name: `STU0012 · SR 12` | display |
| `ui/homework/HomeworkTeacherScreen.kt` | `SR 12` line on the submission row | display |
| `ui/redflags/QuickFlagSheet.kt` | Header: `Class · Section · Roll · SR` | display |

**All nine changes cost zero Firestore reads** — every value comes from the `StudentDoc` /
`StudentInfo` the roster query already downloaded, and both searches are local in-memory filters.

**Marks, homework and red flags still save against `studentId`.** No storage path was touched; the
only edits to save paths in this phase were **none**.

**Search matching is exact for SRNO** (`equals`, not `contains`) so typing `1` does not return every
student whose register number contains a 1. Name and roll matching remain substring, as before.

## 8. Parent App Changes — **NONE, and that is correct**

Per §19/§20: the parent is already authenticated and the child is already selected, so there is
nothing to look up. Phase 11 already delivered the display (Student ID, SR No., Admission No., Class,
Section, Roll No.) and already fixed sibling switching to rebuild all three identity fields per child.

**0 files changed. Repository dirty count unchanged at 20.** No SRNO Firestore query was added — that
would have been a read for no workflow (§19's explicit warning).

## 9–14. Attendance · Examination · Results · Homework · Red Flags · PTM

| Module | Classification | Action | Why |
|---|---|---|---|
| **Attendance** | ROSTER | **Display SRNO only** | Teacher already sees the whole class; a search box would be slower than the roster and add a read (§12 says so explicitly) |
| **Examination** | ROSTER | No change | Exams are configured per class/section/subject; no student-identifier entry point exists |
| **Results** | ROSTER | No change | Roster-driven entry; `ResultDoc` already carries `rollNo` |
| **Homework** | ROSTER | **Display SRNO** | Submission list is class-scoped; saves stay keyed by `studentId` |
| **Red Flags** | ROSTER | **Display SRNO** | Flag sheet opens from a selected roster student; the denorm write is unchanged |
| **PTM** | **NEITHER** | **No change — reasoned exception, see below** | |

**PTM — why SRNO was deliberately NOT added.** The Teacher PTM screen lists **RSVP/booking records**
(`ptmRsvps`), not a class roster and not a student search. Those documents carry denormalised
`studentName`, `rollNo` and `parentName` — written by the **Parent app** — and have no `srNo` field.
Displaying SRNO there would require either:

- adding a denorm field to the RSVP document and changing the Parent app's write path (cross-surface
  schema drift, outside this phase's authorized scope), or
- a per-RSVP student lookup — **an N+1 pattern**, which §21 and §32 explicitly forbid.

There is also **no student search in PTM** for §14's conditional branch to apply to. Recorded as
**FUTURE** (§36), not silently skipped.

## 15–19. Transport · Communication · Documents · Certificates · TC

| Module | Action | Cost | Note |
|---|---|---|---|
| **Transport** | SRNO added to the cached student search index + exact match | **0 reads** | Assigning a student to a route is a genuine office workflow. Pre-existing cache entries lack the key and read as `''` until the existing 300 s TTL refreshes them |
| **Communication** | No change | — | Recipients are chosen by class/section/role audience; no individual-identifier entry point |
| **Documents** | No change | — | Roster/profile-driven; `Document_service` keys on `studentId` |
| **Certificates** | **BLOCKED — no change** | — | `Certificates::get_students()` reads **RTDB** (`Schools/{school}/{session}/{class}/{section}/Students/List`). Per §29 I stopped at the boundary rather than routing around it. **P11.3-N1**, §29 |
| **TC** | No change | — | Reads `Admission Number` as a display field with a Student ID fallback — already correct |

**Library and Hostel** both use `Operations_accounting::search_students()`, so both gained SRNO search
from that one change — **0 reads**.

## 20. Student 360

**No change to `Student360.php`.** It is a canonical `view/{studentId}` route reached from the SIS
search — and because that search now accepts SRNO, Student 360 became SRNO-reachable with no code
change. SRNO is already displayed in its Profile panel.

## 21. Search / Autocomplete Summary

| Surface | Backing | Cached | SRNO cost | Result |
|---|---|---|---|---|
| `Fees::_searchByName()` | full scan | no | 0 reads | ✔ added |
| `Sis::search_student()` | `schoolList` | 90 s | 0 reads | ✔ added |
| `Transport_service::searchStudents()` | `schoolWhere` | 300 s | 0 reads | ✔ added |
| `Operations_accounting::search_students()` | `firestoreQuery` | 300 s | 0 reads | ✔ added (Library, Hostel) |
| Teacher `StudentsViewModel.filterStudents()` | in-memory roster | — | 0 reads | ✔ added |
| Teacher `SearchViewModel` | in-memory rosters | — | 0 reads | ✔ added |
| `Gate_pass_service::searchStudents()` | `schoolWhere` | 300 s | — | **not changed** — matches name only today; widening a gate-pass surface is security-adjacent and out of scope. **FUTURE** |
| `Certificates::get_students()` | **RTDB** | — | — | **blocked**, §19 |

## 22. Re-admission

**Verified by test, not by assumption** (`test_after_readmission_srno_resolves_to_the_new_student_id`,
`test_historical_owner_is_retained_but_never_returned_by_resolve`).

| Step | `srNoIndex/{schoolA}_12` | `resolve("12")` |
|---|---|---|
| First admission | `currentStudentId: STU0001` | `STU0001` |
| **After relink** | `currentStudentId: STU0047`, `historicalStudentIds: [STU0001]` | **`STU0047`** ✔ |

The historical owner is retained for Student Trail but **never returned by ordinary lookup**. Both
directions are asserted.

## 23. Promotion

**Verified by test** (`test_promotion_does_not_change_srno_resolution`): a student-document write
mutating class/section/roll leaves `srNoIndex` byte-identical, and `resolve("12")` still returns
`STU0012`. Promotion preserves Student ID, SRNO and Admission Number; it may change Class, Section
and Roll Number. **Nothing in promotion was touched.**

## 24. Roll Number

**Semantics unchanged.** Roll Number remains School + Session + Class + Section scoped.

**Deliberately excluded from every search haystack** — in Fees, in SIS, and in both Teacher searches.
Roll Number repeats across sections, so matching it school-wide would silently imply a global
uniqueness that does not exist (§25). This is called out in the code comments at each site so a
future maintainer does not "helpfully" add it.

Alphanumeric roll numbers (`A12`, `B-07`) remain intact and correctly displayed — Phase 11's
`formatRollNo()` is untouched and still in use at every render site.

## 25. Admission Number

**Untouched.** Still system-generated `YYYY/TOKEN`, still independent of SRNO / Student ID / Class /
Roll, still new on re-admission, still with no operator-entry path anywhere. Not used as a lookup key
and not added to any search haystack.

## 26. APAAR

**Untouched and excluded.** Not a lookup key, not in any search haystack, not in any response added
this phase. Masking + audited-reveal policy unchanged. Verified by grep: `apaar` appears in the two
app repositories only inside explanatory comments — zero code references.

The Student 360 plaintext-APAAR finding (**P11-N1**) remains **open and separate**.

## 27. Multi-Tenancy

| Requirement | Status | Evidence |
|---|---|---|
| School A + 12 → STU0012 | **PASS** | `test_same_srno_in_two_schools_resolves_to_different_students` |
| School B + 12 → STU0456 | **PASS** | same test, one shared backing store |
| School A never returns School B's student | **PASS** | `test_school_a_can_never_resolve_school_bs_srno` — and School B's own lookup still works, proving genuine scoping rather than universal failure |
| No client-controlled schoolId | **PASS** | `test_tenant_scope_comes_from_the_firestore_context_not_the_caller` — a caller-supplied schoolId cannot redirect resolution |
| No global SRNO query | **PASS** | resolution is a doc-id **fetch**; a global `srNo == N` query is not expressible through this path |

**Defence in depth preserved:** after resolution the student is loaded as
`students/{schoolId}_{studentId}`. Even a hypothetically corrupt index entry pointing at a foreign
Student ID yields a **miss**, never a cross-tenant read.

## 28. Security

- **No authorization gate widened.** `lookup_student` keeps `Fees/view`; `search_student` keeps its
  gate; `Sis::get_student` keeps `SIS/view`.
- **No new enumeration surface of consequence.** A user who can already list the entire school roster
  through Browse gains the ability to probe SRNO 1..N within **their own tenant only**.
- **Fail-closed everywhere:** unknown SRNO → clean `not_found`, never a nearest match or fallback.
- **No login path touched** (§11).
- `resolve()` proven **side-effect free** by test — a lookup writes nothing.

## 29. Firestore / RTDB

**No RTDB introduced.** Verified by grep across all changed files.

**P11.3-N1 (MEDIUM, pre-existing, reported not fixed):** `Certificates::get_students()` is
RTDB-backed. Per §29 I **stopped at that integration boundary** and did not route around it with a
hidden fallback. It needs an RTDB→Firestore migration decision before SRNO can be added there.

**No Firestore rules or index change.** Confirmed: `firebase-rules/` files have mtimes of
2026-08-13 / 2026-08-12, both predating this phase. `srNoIndex` remains `allow read, write: if false`
(server-only, Admin SDK) and resolution is a doc-id fetch, so no composite index is involved.

## 30–31. Latency / Read-Write Cost

| Surface | Existing Reads | New Reads | Existing Writes | New Writes | Queries | Extra Round Trips | Impact |
|---|---:|---:|---:|---:|---:|---:|---|
| `Fees::lookup_student` — Student ID | 1 | **0** | 0 | 0 | 0 | **0** | none |
| `Fees::lookup_student` — SRNO | 1 | **+1** | 0 | 0 | 0 | **+1** | ~1 RTT, SRNO path only |
| `Fees::_searchByName` | N (full scan) | **0** | 0 | 0 | 0 | 0 | none |
| `Sis::search_student` | 1 (90 s cached) | **0** | 0 | 0 | 0 | 0 | none |
| `Sis::get_student` — Student ID | 1 | **0** | 0 | 0 | 0 | 0 | none |
| `Sis::get_student` — SRNO | 1 | **+1** | 0 | 0 | 0 | +1 | ~1 RTT |
| `Transport_service::searchStudents` | 1 (300 s cached) | **0** | 0 | 0 | 0 | 0 | none |
| `Operations_accounting::search_students` | 1 (300 s cached) | **0** | 0 | 0 | 0 | 0 | none |
| Teacher roster / attendance / marks / homework / red-flag displays ×7 | 0 | **0** | 0 | 0 | 0 | 0 | none |
| Teacher student search + global search | 0 | **0** | 0 | 0 | 0 | 0 | none |
| Parent App | 0 | **0** | 0 | 0 | 0 | 0 | none |

**ZERO-COST changes: 14 of 16.** **ONE-READ changes: 2.** **HIGH-RISK changes: none.**
**N+1 risks introduced: none** — every roster display reads from the already-downloaded document, and
resolution is a single doc-id fetch outside any loop.

**The forbidden pattern was avoided:** resolution happens *before* the student load, so the SRNO path
is `index read → student read`, never `student read → index read → student read again`.

**Pre-existing latency defect, measured and NOT fixed (P11.3-N2):** `Fees::_searchByName()` remains an
**uncached full-collection scan on every search** — the only one of six search implementations without
a cache. This phase **did not make it worse** (the SRNO change is a string concatenation inside the
existing loop). Classified **FUTURE PERFORMANCE HARDENING** per §22, not silently expanded into.

## 32. Tests

`tests/Unit/SrNoDualLookupTest.php` — **15 tests, 60 assertions, all pass.**

| Area | Tests |
|---|---|
| Token-shape disjointness (the invariant the branch rests on) | 2 |
| SRNO → canonical Student ID, leading zeros, one-read cost | 3 |
| Fail-closed: unknown SRNO, malformed SRNO, no fallback | 2 |
| **Multi-tenant: same SRNO two schools, cross-school rejection, no client-controlled tenant** | **3** |
| **Re-admission: current owner returned, historical never returned** | **2** |
| Promotion invariance | 1 |
| Side-effect freedom; index maps SRNO→Student ID, never the reverse | 2 |

**Honest scope statement, also written into the test file's docblock:** the *resolution* half runs the
real `Sr_no_service` against in-memory Firestore doubles — real control flow, not a source grep. The
*branching* half (`MY_Controller::_resolveStudentRef()`) sits on a CodeIgniter controller that cannot
be bootstrapped in this suite, so it is **NOT directly unit-tested**. What is tested is the invariant
it depends on — that Student ID and SRNO shapes are disjoint — so if anyone widens either format the
suite fails loudly.

## 33. Regression Results

| Check | Result |
|---|---|
| `php -l` on all 6 changed PHP files | **PASS** |
| Full unit suite | **734 tests, 2348 assertions, 10 failures** |
| Comparison to baseline | **Baseline is 10 failures. Zero new failures.** Test count rose 719 → 734 (+15, all mine, all passing) |
| Teacher App `compileDebugKotlin` | **BUILD SUCCESSFUL** (4m 32s) — only pre-existing deprecation warnings, none from the changed files |
| Parent App | not rebuilt — **zero files changed this phase** (verified: dirty count unchanged at 20) |
| Cross-module query-key grep | **PASS** — still 0 non-Student-ID query keys |
| APAAR / RTDB grep on changed files | **PASS** — none introduced |

The 10 pre-existing failures are cross-repo source-grep tests (Backup ×1, Homework ×5,
ParentHomeworkRepo ×3, TeacherExecuteDeleteCascade ×2). **None touches any file changed here.**

## 34. UAT Requirements — NOT EXECUTED

**No UAT PASS is claimed.** These require a browser session and a real tenant login, which I do not
create.

**Admin Panel (browser, `localhost:8080`):**
1. Fee counter: type `STU0012` → student found, unchanged behaviour.
2. Fee counter: type `12` → same student; banner reads `✓ SR No. 12 → STU0012 · Rahul Sharma · Roll 18`.
3. Fee counter: type an unknown SR Number → `SR No. 'N' not found in this school`.
4. Browse modal: search `12` → the SRNO-12 student appears; SR No. column populated.
5. Complete a fee collection started from the SRNO path; **confirm the receipt stores the Student ID**.
6. SIS search: `12` returns the SRNO-12 student without breaking name/phone/class search.
7. Transport / Library student pickers: search by SR Number.
8. Two-school check: same SR Number in two tenants resolves correctly in each.

**Teacher App (device `I2011`):**
9. Roster card shows `Roll: 18 · SR: 12`; blank SRNO shows only the roll.
10. Student search by SR Number returns exactly that student.
11. Attendance, marks, homework, red-flag sheet all show SR No.
12. Save marks/homework/a flag → **confirm the written document keys on `studentId`**.
13. Global search by SR Number.

**Cross-cutting:** re-admitted student resolves to the NEW Student ID; promoted student still
resolves by the same SRNO.

## 35. Remaining Gaps

1. **UAT not executed** (§34).
2. **PTM has no SRNO display** — would need an RSVP schema change plus a Parent-app write change, or
   an N+1 lookup. Documented decision, §14.
3. **Certificates blocked** on the RTDB migration decision (P11.3-N1).
4. **Campus Access search** still matches name only — pre-existing, deliberately not widened.
5. **`_resolveStudentRef()` not directly unit-tested** — CI bootstrap limitation, §32.
6. **`Fees::_searchByName()` remains uncached** (P11.3-N2).

## 36. Future Work

- **F1** Certificates RTDB → Firestore migration, then SRNO.
- **F2** `Fees::_searchByName()` caching (adopt the `Sis::search_student()` 90 s snapshot pattern).
- **F3** PTM SRNO display, once an RSVP denorm field is agreed with the Parent app.
- **F4** Campus Access search: add Student ID *and* SRNO matching.
- **F5** Integration-level test harness for controller methods, which would let `_resolveStudentRef()`
  be tested directly.

## 37. Files Modified

**Admin Panel (6 + 1 new test):**
```
 M application/core/MY_Controller.php
 M application/controllers/Fees.php
 M application/controllers/Sis.php
 M application/libraries/Operations_accounting.php
 M application/libraries/Transport_service.php
 M application/views/fees_counter.php
 ?? tests/Unit/SrNoDualLookupTest.php
```

**Teacher App (9):** `StudentsScreen.kt`, `StudentsViewModel.kt`, `SearchViewModel.kt`,
`AttendanceViewModel.kt`, `AttendanceScreen.kt`, `MarksViewModel.kt`, `MarksScreen.kt`,
`HomeworkTeacherScreen.kt`, `QuickFlagSheet.kt`

**Parent App: none.**

## 38. Files Deliberately Untouched

- `Sr_no_service.php` — resolver reused **unmodified** (§5, §38).
- `Id_generator.php`, `Admission_no_service.php`, `Apaar_service.php` — §38.
- `Readmission.php` — relink semantics are load-bearing for the re-admission tests.
- `firestore.rules`, `firestore.indexes.json` — no change required.
- Every module's data layer — Fees demands/receipts/allocations, Attendance, Results, Transport,
  Communication, Documents, Certificates, TC. **`studentId` remains the key everywhere** (§35).
- `Certificates::get_students()` — RTDB boundary, stopped as instructed (§29).
- `Gate_pass_service::searchStudents()` — security-adjacent, out of scope.
- `Sis.php:4600-4632` (SIB-B1) — §28, see below.
- `student360/workspace.php` — the open APAAR finding is separate.
- Parent App, Teacher App authentication — untouched.

## 39. Git Status

```
Branch: integration/phase2       HEAD: 758c924 (unchanged — nothing committed)

Admin Panel   420 → 423 dirty   (+3 newly-dirty of the 6 edited; 3 were already dirty)
                                + 2 new untracked (test file, this report)
Teacher App    26 →  29 dirty   (+3 newly-dirty; 6 of the 9 edited were already dirty)
Parent App     20 →  20 dirty   (unchanged — zero files touched)
```

Every pre-existing working-tree change was preserved. **No unrelated file was modified, staged or
reverted.**

## 40. Commit Recommendation

**DO NOT COMMIT YET.** Conditions to clear first:

1. Operator UAT at the fee counter (§34 items 1-5) — this is the money path; it should be seen working.
2. Teacher App device verification (§34 items 9-13), especially that saves still key on `studentId`.
3. A decision on P11.3-N1 (Certificates RTDB) — not blocking this commit, but it should be recorded.

**If and when authorized**, the natural commit boundary is the whole phase as one module release
(Admin + Teacher together), because the Teacher app's roster display and the panel's SRNO search are
two halves of one operator-facing capability. The Parent app needs no commit — it has no changes.

---

## Out-of-Scope Findings (§0 zero-surprise reporting)

| ID | Severity | Finding |
|---|---|---|
| **P11.3-N1** | MEDIUM | `Certificates::get_students()` is **RTDB-backed**; SRNO blocked there. Stopped at the boundary per §29, no workaround added. |
| **P11.3-N2** | MEDIUM | `Fees::_searchByName()` is an **uncached full-collection scan** — the only one of six search implementations without a cache. Not worsened; classified FUTURE per §22. |
| **SIB-B1** | **HIGH** | **Re-verified live again this phase** at `Sis.php:4600-4632`, unchanged: bulk import treats siblings sharing a parent phone as duplicates — `skip` discards the second child, `update` overwrites the first child's record. **Not fixed** (§28). Note the one-directional interaction: a discarded sibling never reaches SRNO allocation, so this bug also silently reduces SRNO coverage. |
| **P11-N1** | MEDIUM | Student 360 renders APAAR unmasked and unaudited at `SIS/view`. Still open from Phase 11. |
| **P11.2-N3** | LOW | `Sr_no_service::init()` receives `$school_id` in two callers and `$this->parent_db_key` in another. Immaterial to `resolve()` (which scopes via `fs->docId()`), but inconsistent. Not changed. |

---

**Nothing was committed, staged, pushed, merged, rebased or deployed.**
