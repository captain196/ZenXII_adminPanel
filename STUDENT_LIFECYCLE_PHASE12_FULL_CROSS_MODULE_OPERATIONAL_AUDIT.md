# Phase 12 — Full Cross-Module / Cross-Platform / SRNO Operational Audit

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924` · **Type: AUDIT ONLY — zero source files modified.**

Nothing implemented, committed, staged, pushed, merged, rebased or deployed. No Firestore data
written, no rules or indexes deployed, no test account created, no RTDB introduced. Per §16 and §23,
this stops at the report and the decision list.

**Evidence labelling** (per §18): **[R]** = reused verified finding · **[F]** = freshly verified this
phase · **[N]** = new finding.

---

## 1. Executive Summary

The SRNO operationalization is **architecturally sound and complete for its authorized scope**.
Student ID remains the persisted identity in every module; SRNO resolves through one canonical
service; no identifier is derived from another. That was re-confirmed, not assumed.

This phase's value is in **five findings the previous phases did not surface**, three of them
corrections to things previously believed:

| ID | Class | Finding |
|---|---|---|
| **N12-1** | **HIGH** | **Re-admission leaks the old record into the Parent App.** `findSiblings()` filters on phone + parent name with **no status filter** — so after a re-admission the WITHDRAWN old student appears in the child switcher as a separate child. This is the §5-J failure mode, answered **YES**. Fix is a one-line predicate, *not* a sibling redesign. |
| **N12-2** | **MEDIUM** | **A real N+1 exists** in `Attendance::_backfillPunchNames` — one sequential Firestore read per unique unnamed punch person, inside a loop. Costs nothing when denorm names are present, degrades sharply when they are not. |
| **N12-3** | **CORRECTION — good news** | **Phase 10 §H is STALE.** `Operations_accounting.php` now makes **zero** RTDB calls (verified: all 8 Firebase calls are `firestore*`). The open "accept RTDB accounting or migrate first?" decision **no longer needs making**. |
| **N12-4** | **INFO / RISK** | **`integration/phase2` has no remote counterpart.** Remote has `main`, `ank-yug_b1`, `ank-yug_b1_clay_makeover` and 5 others — not this branch. All Phase 9→11.5 work exists **only on this machine**, unpushed and unbacked-up. |
| **N12-5** | **MEDIUM** | The "Homework / LMS" lifecycle stage is **split**: `Homework.php` is fully Firestore (0 RTDB calls); `Lms.php` is RTDB-backed (68). They should not be reasoned about as one module. |

**No BLOCKER and no CRITICAL finding.** No data-corruption or tenant-isolation defect was found.

**Verdict: READY WITH CONDITIONS** (§32) — five decisions listed at the end.

---

## 2. Plain-Language Explanation

See **"WHAT THIS MEANS FOR ME"** at the end of this document for the full non-technical version.

In one paragraph: a school can now find a student by its own SR Number anywhere it matters — the fee
counter, student search, transport, hostel, library, and the teacher's class lists — and the system
always converts that to the student's real internal ID before saving anything. That part works. The
main new problem is that when a student leaves and re-joins, the parent's app still shows the old,
closed record as if it were a second child.

## 3. Student Identity Architecture Verification **[F]**

| Identifier | Format | Scope | Derived from anything? | Persisted as FK? |
|---|---|---|---|---|
| Student ID | `STU` + 4 digits | **Global** | No | **Yes — everywhere** |
| SRNO | `^[0-9]{1,9}$` | **School** | **No — test-enforced** | **No** |
| Admission Number | `YYYY/TOKEN` | School | No | No |
| Roll Number | free text | School+Session+Class+Section | No | No |

**The §0 non-negotiable is enforced by test, not convention [R/F].**
`SrNoDualLookupTest` asserts a deliberately crossed pairing — SRNO 47 → STU0012 **and** SRNO 12 →
STU0047 — which a digit-derivation bug would return swapped. A second test proves that with only
SRNO 47 claimed, resolving `12` (the numeric tail of `STU0012`) **fails**. Both directions covered.

**Roll Number uniqueness: none exists, none was invented [R].** No global check and no scoped check.
Two students in one section can both be Roll 18 today. Nothing was changed; recorded as a business
decision (§29-D5).

## 4. Student Lifecycle Map

| Stage | Identifies by | Persists | SRNO findable? | Promotion-safe | Re-admission-safe |
|---|---|---|---|---|---|
| Admission | generated | `studentId` | n/a (creates) | ✔ | ✔ new ID |
| Student ID generation | `Id_generator` | `studentId` | — | ✔ | new |
| SRNO allocation | `allocateNext()` | `srNoIndex` | — | ✔ preserved | ✔ relinked |
| Admission Number | `Admission_no_service` | on student doc | — | ✔ frozen | **new** |
| APAAR | `Apaar_service` | `apaarIndex` | — | ✔ | ✔ relinked |
| Class/Section/Roll | operator | on student doc | — | **may change** | independent |
| **Fees** | ID **or SRNO** | `studentId` | **✔ MUST** | ✔ | ✔ |
| **Attendance** | roster | `studentId` | display | ✔ | ✔ |
| **Examination / Results** | roster | `studentId` | display | ✔ | ✔ |
| **Homework** | roster | `studentId` | display | ✔ | ✔ |
| **LMS** | class/section (**RTDB**) | RTDB paths | ✖ blocked | ⚠ N12-5 | ⚠ N12-5 |
| **Red Flags** | roster | `studentId` | display | ✔ | ✔ |
| Communication | audience | `studentId` | not required | ✔ | ✔ |
| **Transport** | ID **or SRNO** | `studentId` | **✔ SHOULD** | ✔ | ✔ |
| **Hostel** | ID **or SRNO** | `studentId` | **✔ SHOULD** | ✔ | ✔ |
| **Library** | ID **or SRNO** | `studentId` | **✔ SHOULD** | ✔ | ✔ |
| Documents | profile | `studentId` | not required | ✔ | ✔ |
| **Certificates** | class/section (**RTDB**) | `studentId` | ✖ blocked | ✔ | ✔ |
| ID Card | roster | n/a | display | ✔ | ✔ |
| **PTM** | authenticated child | `studentId` **+ srNo denorm** | display | ✔ | ✔ |
| Promotion | `studentId` | — | — | — | — |
| Re-admission | `studentId` + `previousStudentId` | new `studentId` | ✔ current owner | — | — |
| Student 360 / Trail | `studentId` route | — | ✔ via SIS search | ✔ | ✔ |
| **Parent App** | authenticated | `studentId` | **not required** | ✔ | **⚠ N12-1** |
| **Teacher App** | roster + search | `studentId` | **✔ done** | ✔ | ✔ |

## 5. Admin Panel Audit **[R + F]**

Every student-touching surface, with §5 questions A/B/E answered:

| Surface | A: identifies by | B/E: persists | SRNO status |
|---|---|---|---|
| Fee counter direct entry | ID **or SRNO** → `_resolveStudentRef` | `studentId` | **DONE** |
| Fee Browse / picker | in-memory match incl. `srNo` | `studentId` | **DONE** |
| Fee collection / receipt / allocation / ledger | already-resolved ID | `studentId` **[F]** | correct |
| Fee demands / defaulters / concessions | `student_id` POST | `studentId` | correct |
| SIS search | cached index incl. `srNo` | n/a | **DONE** |
| SIS `get_student` | ID **or SRNO** | n/a | **DONE** |
| Student 360 | `/{studentId}` route via SIS search | n/a | reachable |
| Student profile / list | `studentId` | — | displays SRNO + Adm No |
| Transport picker | index match incl. `srNo` | `studentId` | **DONE** (display added 11.5) |
| Hostel picker | index match incl. `srNo` | `studentId` | **DONE** (11.5) |
| Library picker | index match incl. `srNo` | `studentId` | **DONE** (11.5) |
| Campus Access picker | **name only** | `studentId` | FUTURE |
| Certificates student list | **RTDB class/section** | `studentId` | **BLOCKED** |
| Bulk import | file rows (phone / name+DOB) | `studentId` | ⚠ SIB-B1 |
| Export | — | — | **none exists** |
| ID Card | roster | n/a | shows SRNO + Roll |

**Confirmation UI [R]:** the fee counter shows `✓ SR No. 12 → STU0012 · Rahul Sharma · Roll 18`, so
an SRNO is never silently resolved. **Transport/Hostel/Library now render the matched SR No. too**
(11.5). Campus Access is the only picker with no identifier confirmation at all.

## 6. Teacher App Audit **[R]**

| Surface | Type | SRNO | Persists |
|---|---|---|---|
| Student roster card | roster | **displayed** (`Roll: 18 · SR: 12`) | — |
| Student detail panel | roster | **displayed** | — |
| Student search | **direct** | **searchable (exact match)** | — |
| Global search | **direct** | **searchable + displayed** | — |
| Attendance | roster | **displayed** | `studentId` |
| Marks | roster | **displayed** | `studentId` |
| Homework | roster | **displayed** | `studentId` |
| Red Flags | roster | **displayed** | `studentId` |
| PTM RSVP list | list | **displayed** (11.5) | `studentId` |

**Negative proof [R]:** a repo-wide scan finds **zero** `srNo` writes anywhere in the Teacher app —
every occurrence is a model field, read mapping, display string or search predicate. Attendance and
Exam repositories write `"studentId" to studentId`.

**No SRNO search box was added to any roster workflow**, per §2 — the teacher already has the class.

## 7. Parent App Audit **[F]**

| Item | Status |
|---|---|
| Student ID / SRNO / Admission No / Class / Section / Roll displayed | **✔ all six** |
| Sibling switching preserves identity | **✔ all fields rebuilt from the sibling doc** |
| Global SRNO search | **absent — correct** (§3: parent reaches children by authentication) |
| Extra Firestore reads for identity | **0** |
| APAAR / `previousStudentId` exposed | **no — zero code references** |
| **Re-admission behaviour** | **⚠ N12-1 — see §20** |

## 8. Fees Audit **[F]**

**End-to-end persistence proof, re-verified this phase:**

```
operator types "47"
  → _resolveStudentRef → srNoIndex/{schoolId}_47 → STU0012
  → lookup_student returns user_id = "STU0012"
  → selectStudent() REWRITES the input box to STU0012
  → submit_fees posts STU0012
  → feeDemands / feeReceipts / allocations / ledger all write 'studentId'
```

The SR Number is overwritten in the box at resolution, so it can never reach a write. **`srNo`
appears in `Fees.php` at 4 lines — all read/display, zero writes [F].**

| Fee surface | SRNO | Persists |
|---|---|---|
| Collection, demand lookup, receipt, allocation, exemption, history, defaulters | via resolved ID | `studentId` |
| Student search / Browse | **✔ matches SRNO** | — |
| Reminders, late fee/penalty | `studentId` | `studentId` |
| ID-card replacement fee | **does not exist** (FUTURE, from Phase 11) | — |
| Parent-facing fee info | authenticated child | `studentId` |

**Accounting RTDB — N12-3 CORRECTION [N/F].** `Operations_accounting.php` makes **zero** RTDB calls.
All eight Firebase calls are `firestoreGet`, `firestoreGetParallel`, `firestoreSet`, `firestoreQuery`,
`firestoreCommitBatch`. The `Schools/{school}/…` strings that previously suggested RTDB are
**docblock comments describing the legacy paths**, plus `next_id()`, which explicitly *converts* a
legacy RTDB path string into a Firestore `opsCounters` doc id. **The Phase 10 §H decision is
obsolete and can be closed.**

## 9. Attendance Audit **[R + N]**

Roster-driven; persists `studentId` (`AttendanceFirestoreRepository:71,103,266`). SRNO displayed on
the Teacher roster row. **No SRNO search box — correct per §2.**

**N12-2 (MEDIUM, NEW):** `Attendance.php:3828-3841` loops over unnamed punch rows and issues **one
sequential `fs->get('students'/'staff')` per unique person** to backfill display names.

- Bounded by *distinct unnamed* persons (deduped into `$need`), not by row count.
- Returns immediately at `:3825` when every punch already carries a name — so a healthy dataset costs
  **zero**.
- Degrades to N sequential reads when denormalised names are missing, e.g. a device-imported punch
  batch. On a 300-person day that is 300 blocking round trips in a known-slow Firestore environment.
- **Not caused by any SRNO work.** Pre-existing.

## 10. Examination / Results Audit **[R]**

Roster-driven, keyed `studentId` (`ExamFirestoreRepository:144,213`). SRNO displayed on the marks
grid under the student name. No search box. Report cards unchanged — SRNO not added (Phase 11
decision: receipts and report cards reconcile by Student ID + class; a fourth identifier adds clutter
without a reconciliation purpose).

## 11. Homework / LMS Audit **[N]**

**These are two different modules and must not be treated as one — N12-5.**

| | Backing | RTDB calls | SRNO | Persists |
|---|---|---|---|---|
| `Homework.php` | **Firestore** | **0** | displayed (Teacher) | `studentId` |
| `Lms.php` | **RTDB** | **68** | **blocked** | RTDB paths |

LMS reads `Schools/{school}/Subject_list`, `Schools/{school}/{session}/LMS`, and
`Schools/{school}/{session}/{classKey}/Section…`. SRNO cannot be integrated there without an
RTDB→Firestore migration, exactly as with Certificates. **Not touched** (§13: no migration this phase).

## 12. Red Flags Audit **[R]**

Roster-driven; the quick-flag sheet header shows `Class · Section · Roll · SR`. The flag document
keys on `studentId`. The Phase 11 fix that stopped `-2147483648` being denormalised into the
red-flag document remains in place.

## 13. Communication Audit **[R]**

**Classified NOT REQUIRED.** Recipients are chosen by class/section/role audience — there is no
individual-identifier entry point to extend. Adding an SRNO box would serve no workflow. Messages
key on `studentId`.

## 14–16. Transport · Hostel · Library **[R + F]**

All three search a **school-scoped cached in-memory index** (300 s TTL) that carries `srNo`, and all
three now render the matched SR No. (11.5). Selection value is `data-id` = the **Student ID**, which
is what the save posts:

- Transport → `save_assignment{student_id}` · Hostel → `save_allocation{student_id}` · Library → issue

**Zero `'srNo' =>` writes in any of the three controllers [F].**

## 17. PTM Audit **[R]**

`srNo` is denormalised onto the RSVP at write time from the Parent app's already-cached user — **0
extra reads** — across all four write paths. The Teacher list displays it. The RSVP doc id remains
`{schoolId}_{ptmEventId}_{studentId}`; **the key is unchanged**. RSVPs written before 11.5 show no
SR segment; **no backfill was performed** (would be a production data write) and none is proposed —
a per-RSVP resolve would be an N+1.

## 18. Documents / Certificates / TC Audit **[F]**

**What should appear where** — deliberately not "all four everywhere":

| Document | Student ID | SRNO | Admission No | Roll No | Rationale |
|---|---|---|---|---|---|
| ID Card | ✔ | **✔** | ✖ | **✔** | SRNO = the register the gate/office reconciles against; Adm No would look stale after re-admission (cards aren't reissued) |
| Credentials PDF | ✔ | **✔** | **✔** | ✖ | office handout; both office identifiers useful; Roll is classroom-scoped |
| Bulk-import result | ✔ | **✔** | **✔** | ✖ | confirms what the system allocated |
| Certificates / TC | ✔ fallback | ✖ | **✔ primary** | ✔ | Adm No is the admission-event record a certificate attests to |
| Fee receipt | ✔ | ✖ | ✖ | ✔ | reconciles by ID + class; a 4th identifier is clutter |
| Report card | ✔ | ✖ | ✖ | ✔ | same |
| Student profile / 360 | ✔ | ✔ | ✔ | ✔ | the one place all four legitimately belong |
| Export | — | — | — | — | **none exists** |

**RTDB dependencies identified, not migrated:** `Certificates.php` (24 calls, incl. the class/section
student list at `:333`) and `Lms.php` (68). Both **BLOCKED for SRNO** until migrated. The wider repo
has substantially more RTDB (LMS, Assets, Otp_service, Superadmin_*, Fee_simulation, backups) —
that is the pre-existing RTDB-elimination backlog, out of scope.

## 19. Promotion Audit **[R]**

Verified by scan and unit test: `execute_promotion()` contains **zero** references to `srNo`,
`admissionNumber`, `apaarId`, `previousStudentId`, `rollNo` or `Roll No`. It never touches
`srNoIndex`, so SRNO resolution is invariant across promotion **by construction, not convention**.

Per-module post-promotion correctness (§6-L): every module keys on `studentId`, which promotion does
not change, so no module can break. Class/section-scoped data (fee demands, rosters) re-derives from
the student's new class on next read.

## 20. Re-admission Audit **[F]** — includes the phase's most important finding

**The service layer is correct.** `resolve()` returns `currentStudentId` only; after relink,
SRNO 47 → STU0047, never STU0012. Unit-tested both directions.

**§5-I — can historical Student IDs receive new activity? NO [F].**
`previousStudentId` is a **one-way pointer** (new → old) written only inside `relink()`'s atomic
batch. Repo-wide, it is read by exactly three things: the student-profile display, and the two
cycle-guards in `Sr_no_service`/`Apaar_service`. **No module resolves through it**, so no write can
be routed to the historical ID.

**`indexPhones` self-heals [F].** Re-admission does not touch it, but the new admission that created
the new Student ID writes `indexPhones` with `set()` (overwrite), so the phone now points at the new
ID. Hard delete removes it (`Sis.php:6173`). No stale phone→old-ID mapping survives.

**§5-J — can old records attach to the new admission? YES, in one place — N12-1 (HIGH, NEW).**

`StudentFirestoreRepository.findSiblings()` (Parent app, `:140-213`):

```kotlin
val fetched = queryDocumentsAs<StudentDoc>(STUDENTS) { ref ->
    ref.whereEqualTo("schoolId", schoolCode)     // ONLY filter — no status, no limit
}
…
fetched.filter { s -> …phone match… ; fatherMatch || motherMatch || primaryNamesBlank || sNamesBlank }
```

There is **no `status` filter and no `previousStudentId` exclusion**. A re-admitted student shares
phone and parent names with their own withdrawn record, so **both appear in the parent's child
switcher** — the closed record presented as a second, current child. A parent could switch to it and
see a frozen, historical view (no current fees, no attendance) with no explanation.

- **Identifier involved:** Student ID (old vs current), surfaced through phone+name inference.
- **Affects:** Parent App only. Admin Panel and Teacher App both filter by status correctly.
- **Real-school example:** Rahul (STU0012, SRNO 47) leaves in March, re-joins in June as STU0047 with
  SRNO 47 preserved. From June his parent's app lists **two children named Rahul**.
- **Latency:** the same query is also an **uncached full-collection scan per dashboard load** —
  N reads where N = every student in the school.
- **Recommended fix:** add a status predicate (and optionally exclude any doc whose `studentId`
  appears as some other doc's `previousStudentId`). **One predicate. Zero added reads.**
- **This is NOT a sibling-architecture change** and must not be conflated with SIB-B1 (§28).
- **Not implemented** — reported per §23.

## 21. SRNO Operationalization Matrix

| Module | Classification | Status | Why |
|---|---|---|---|
| Fees counter / Browse | **MUST SUPPORT** | ✔ DONE | The literal business driver — parents quote the register number at the counter |
| SIS search / `get_student` | **MUST SUPPORT** | ✔ DONE | Main admin navigation; feeds Student 360 |
| Transport picker | **SHOULD SUPPORT** | ✔ DONE | Route assignment is an office workflow keyed off the register |
| Hostel picker | **SHOULD SUPPORT** | ✔ DONE | Same |
| Library picker | **SHOULD SUPPORT** | ✔ DONE | Same |
| Teacher student search | **SHOULD SUPPORT** | ✔ DONE | Teacher fielding a parent call quoting an SR number |
| Teacher global search | **SHOULD SUPPORT** | ✔ DONE | Same |
| Attendance / Marks / Homework / Red Flags | **NOT REQUIRED** (display only) | ✔ DONE | Roster already on screen; a box would be slower and cost a read |
| PTM | **NOT REQUIRED** (display only) | ✔ DONE | List of bookings, not a lookup |
| Communication | **NOT REQUIRED** | — | Audience-based, no individual entry point |
| Documents / Results / ID Card | **NOT REQUIRED** | display where useful | Roster/profile-driven |
| Parent App | **NOT REQUIRED** | display only | Parent is authenticated to their own children |
| Campus Access picker | **FUTURE** | ✖ | Security-adjacent gate surface; matches name only today |
| Certificates | **FUTURE (blocked)** | ✖ | RTDB |
| LMS | **FUTURE (blocked)** | ✖ | RTDB |
| Student export | **FUTURE** | ✖ | No export exists |

## 22. Student ID Persistence Matrix **[F]**

| Module | Write path | Key persisted | SRNO written? |
|---|---|---|---|
| Fees (demands/receipts/allocations/ledger) | `Fees.php` ×8 sites | `studentId` | **No** |
| Fee concessions | `Fee_concessions.php` | `studentId` + ownership guard | **No** |
| Attendance | `AttendanceFirestoreRepository:71,103,266` | `studentId` | **No** |
| Marks / Exam | `ExamFirestoreRepository:144,213` | `studentId` | **No** |
| Homework | `Homework.php` | `studentId` | **No** |
| Red Flags | `RedFlagRepository` | `studentId` | **No** |
| Transport | `save_assignment` | `studentId` | **No** |
| Hostel | `save_allocation` | `studentId` | **No** |
| Library | issue/return | `studentId` | **No** |
| PTM | `ptmRsvps` doc id `{school}_{ptm}_{studentId}` | `studentId` | **denorm display only** |
| Leave / Campus Life | `LeaveFirestoreRepository:298,354` etc. | `studentId` | **No** |

**Zero violations of the §4 "incorrect pattern".** The only four `"srNo" to` writes in the entire
Parent app are the authorized PTM denorm; zero in the Teacher app; zero in Fees/Transport/Hostel/
Library controllers.

## 23. Cross-Platform Matrix

| Field | Admin | Teacher | Parent | Already in downloaded doc? | Extra read to show? |
|---|---|---|---|---|---|
| Student ID | ✔ displayed + lookup | ✔ displayed + search | ✔ displayed | ✔ | 0 |
| **SRNO** | ✔ displayed + **lookup** | ✔ displayed + **search** | ✔ displayed | ✔ | **0** |
| Admission Number | ✔ displayed | ✖ by design | ✔ displayed | ✔ | 0 |
| Roll Number | ✔ displayed | ✔ displayed | ✔ displayed | ✔ | 0 |
| Class / Section | ✔ | ✔ | ✔ | ✔ | 0 |
| APAAR | ⚠ Student 360 plaintext | ✖ | ✖ | ✔ | n/a |
| `previousStudentId` | ✔ profile only | ✖ | ✖ | ✔ | 0 |

**Every identity field displayed on any surface comes from a document already downloaded — no
surface performs a read to show an identifier.**

## 24. Latency / Performance Matrix

| Workflow | Reads before | Reads after | Cached? | Sequential? | Blocks UI? | Per row? | In loop? | Verdict |
|---|---:|---:|---|---|---|---|---|---|
| Fee counter — Student ID | 1 | **1** | no | — | yes | no | no | unchanged |
| Fee counter — SRNO | 1 | **2** | no | **yes** | yes | no | **no** | +1 RTT, SRNO path only |
| Fee Browse | N (full scan) | **N** | **no** ⚠ | — | yes | no | no | pre-existing, unchanged |
| SIS search | 1 | **1** | **90 s** | — | yes | no | no | unchanged |
| SIS `get_student` — SRNO | 1 | **2** | no | yes | yes | no | no | +1 RTT |
| Transport/Hostel/Library picker | 1 | **1** | **300 s** | — | yes | no | no | unchanged |
| Teacher rosters (all 5) | 0 | **0** | in-memory | — | no | no | no | free |
| Teacher searches (both) | 0 | **0** | in-memory | — | no | no | no | free |
| PTM write | n | **n** | — | — | no | no | no | +1 field only |
| Parent identity display | 0 | **0** | DataStore | — | no | no | no | free |
| **Attendance punch-name backfill** | — | **N** | **no** | **yes** | **yes** | per person | **YES** | **N12-2 MEDIUM** |
| **Parent `findSiblings`** | — | **N** | **no** | — | yes | no | no | **N12-1 full scan** |

**The SRNO feature adds +1 read on exactly 2 of 22 workflows.** Both pre-existing scans (Fee Browse,
findSiblings) predate it and were not worsened.

## 25. N+1 / Full-Scan Findings

| # | Location | Pattern | Class | Caused by SRNO work? |
|---|---|---|---|---|
| 1 | `Attendance.php:3828-3841` | **student/staff read per unique unnamed punch person, in a loop, sequential** | **MEDIUM** | No — pre-existing |
| 2 | `StudentFirestoreRepository.findSiblings():140` | **uncached full-collection scan per dashboard load, no limit** | **HIGH** (compounds N12-1) | No — pre-existing |
| 3 | `Fees::_searchByName()` | **uncached full-collection scan per search** — the only one of six search implementations without a cache | **MEDIUM** | No — pre-existing |
| 4 | `Fees.php:3471`, `Fees.php:4023` | single-student reads — **false positives**, not loops | **ACCEPTABLE** | No |
| 5 | `Hostel.php:286` | single verify read on save | **ACCEPTABLE** | No |
| 6 | SRNO resolution | single doc-id fetch, **outside any loop** | **ACCEPTABLE BY DESIGN** | — |
| 7 | PTM SRNO | denormalised at write; **no per-row resolve** | **ACCEPTABLE BY DESIGN** | — |

**No new N+1 was introduced by any phase from 11.2 to 11.5.**

## 26. Firestore Rules / Index Impact **[F]**

**No change required and none made.**

- `srNoIndex` / `apaarIndex` / `admissionNoIndex` are `allow read, write: if false` — server-only,
  Admin SDK, which bypasses rules.
- SRNO resolution is a **doc-id fetch**, not a query → no composite index.
- `ptmRsvps.srNo` is an ordinary field on an existing document; Firestore is schemaless and nothing
  filters or orders by it → no rule and no index change (§14: never modify rules merely because a
  field was added).
- File state: `firestore.rules` mtime 2026-08-13, `firestore.indexes.json` mtime 2026-08-12 — both
  predate all Phase 11.3–11.5 work.

**N12-4 — required §14 disclosure [N]:** **`integration/phase2` has NO remote counterpart.**
`git ls-remote --heads origin` returns 8 heads including `main`, `ank-yug_b1`,
`ank-yug_b1_clay_makeover` — **not `integration/phase2`**, which also has no configured upstream.
Consequences: (a) the pull-first reconcile step is **not applicable** for this branch — there is
nothing to pull; (b) **all Phase 9 → 11.5 work exists only on this machine**, uncommitted and
unbacked-up. That is a real operational risk independent of any code defect.

## 27. Security / Tenant Isolation **[R + F]**

| Check | Status |
|---|---|
| School A + 47 → STU0012; School B + 47 → STU0099 | **PASS** (unit-tested) |
| A never resolves B's SRNO | **PASS** — different doc id, clean miss, no fallback |
| Client-controlled `schoolId` | **impossible** — scope comes from `Firestore_service`, set from authenticated claims |
| Global `srNo ==` query | **0 across all three repos** |
| RBAC gates widened | **none** |
| Defence in depth | student always loaded as `students/{schoolId}_{studentId}` — a corrupt index yields a miss, never a cross-tenant read |
| APAAR in searches/exports/apps/logs | **absent** |
| **APAAR in Student 360** | ⚠ **plaintext, unmasked, unaudited, at `SIS/view`** — still open (§28) |

## 28. Existing Known Issues — all re-verified, none touched

| ID | Class | Status |
|---|---|---|
| **SIB-B1** | **HIGH — data loss** | **STILL LIVE** (`Sis.php:4639/4642`). Bulk import treats siblings sharing a parent phone as duplicates: `skip` discards child 2, `update` overwrites child 1. **PRE-EXISTING / OUT OF SCOPE** per §11. **Not worsened** — SRNO allocation happens *after* the dedup block, so a discarded sibling never reaches it (coverage reduced, no corruption added). **No new interaction discovered this phase.** |
| **P11-N1** | **MEDIUM — security** | Student 360 APAAR plaintext at `SIS/view`. **PRE-EXISTING / OUT OF SCOPE** per §12. |
| **P11.3-N1** | MEDIUM | Certificates RTDB. **PRE-EXISTING**, blocks SRNO there only. |
| **P11.3-N2** | MEDIUM — latency | `Fees::_searchByName()` uncached full scan. **PRE-EXISTING**, not worsened. |
| **P11.2-N3** | LOW | `Sr_no_service::init()` schoolId inconsistency — immaterial to `resolve()`. |
| **Phase 10 §H** | **CLOSED** | **Superseded by N12-3** — `Operations_accounting` is Firestore-only. |

## 29. Newly Discovered Issues

### N12-1 — Re-admitted student appears twice in the Parent App · **HIGH**
- **What:** `findSiblings()` has no `status` filter, so the withdrawn pre-re-admission record is
  returned as a current child.
- **Where:** `StudentFirestoreRepository.kt:140-213` (Parent app).
- **Why it matters:** presents a closed record as a live child; the parent can switch to a frozen
  view with no fees or attendance and no explanation.
- **Real school:** Rahul leaves in March (STU0012), re-joins in June (STU0047, SRNO 47 preserved).
  From June the parent app shows two Rahuls.
- **Identifier:** Student ID (historical vs current), surfaced via phone+name inference.
- **Lifecycle:** yes — re-admission. **Admin:** no. **Parent:** yes. **Teacher:** no.
  **Firestore:** no schema change needed. **Latency:** same query is also a full scan.
- **Fix:** one status predicate in the filter. **0 added reads.**
- **Implement now?** Recommended — but it is a **decision** because the fix touches the sibling
  matcher, which §11 protects. It is a lifecycle correctness fix, **not** a sibling redesign.

### N12-2 — N+1 in attendance punch-name backfill · **MEDIUM**
- **What:** one sequential Firestore read per unique unnamed punch person inside a loop.
- **Where:** `Attendance.php:3828-3841`.
- **Why:** in a known-slow Firestore environment, a device-imported batch missing denorm names turns
  a report into N blocking round trips.
- **Real school:** the office exports a month of biometric punches; rows lack names; the page hangs.
- **Mitigating:** zero cost when names are present (early return at `:3825`); bounded by *distinct*
  persons, not rows.
- **Fix:** batch the lookups (`firestoreGetParallel` already exists in `Firebase.php` and is used by
  `Operations_accounting`), or cache the name index.
- **Implement now?** No — MEDIUM, unrelated to SRNO, needs its own authorization.

### N12-3 — Phase 10 §H decision is obsolete · **CORRECTION (positive)**
- `Operations_accounting.php` makes **0** RTDB calls. The pending "accept RTDB accounting or migrate
  first?" decision can be **closed with no work**.

### N12-4 — `integration/phase2` is unpushed and has no remote · **INFO / OPERATIONAL RISK**
- All Phase 9 → 11.5 work exists only on this machine. No branch to pull, reconcile or restore from.
- **Not a code defect.** Raised because §14 explicitly requires reporting it, and because the
  accumulated uncommitted work now spans three repositories.

### N12-5 — Homework and LMS are different modules · **MEDIUM**
- `Homework.php`: Firestore, 0 RTDB. `Lms.php`: 68 RTDB calls.
- Treating "Homework/LMS" as one lifecycle stage would wrongly imply LMS is SRNO-ready. It is not.
- **Implement now?** No — RTDB migration is explicitly out of scope (§13).

## 30. Recommended Implementation Order

Priority order follows §20 (data corruption → security → identity → lifecycle → cross-module →
user-visible → latency → usability → cosmetic).

```
1. SIB-B1  ─────────────────────────────► data corruption, live, HIGH
              (separate authorization; blocks nothing below)

2. N12-1   ─────────────────────────────► lifecycle correctness, user-visible, HIGH
              independent of 1 — DO NOT bundle them

3. P11-N1  ─────────────────────────────► security (APAAR), MEDIUM
              separate authorization

4. N12-2   ─────────────────────────────► latency, MEDIUM

5. P11.3-N2 ────────────────────────────► latency (Fees search cache), MEDIUM

6. Certificates RTDB migration ─► then SRNO in Certificates
   Lms RTDB migration          ─► then SRNO in LMS        (A → B dependencies)

7. Campus Access picker · student export · Roll Number uniqueness decision  (FUTURE)
```

**Dependencies:**
- **Certificates migration → Certificates SRNO.** Do not start the second before the first.
- **LMS migration → LMS SRNO.** Same.
- **N12-1 and SIB-B1 both touch student matching but are independent.** N12-1 is a status filter on
  a *read*; SIB-B1 is a dedup rule on a *write*. **Do not bundle them** — bundling would make a
  low-risk one-line read fix inherit the risk of a data-loss write fix.
- Items 4 and 5 are independent of everything above.

## 31. UAT Matrix — **NOT EXECUTED**

No UAT is claimed. Both apps are installed on device `I2011`; the Admin Panel needs a browser session
with a real tenant login.

| # | Platform | Test | Expected |
|---|---|---|---|
| 1 | Admin | Fee counter `STU0012` | found, unchanged |
| 2 | Admin | Fee counter `47` | `✓ SR No. 47 → STU0012 · …` |
| 3 | Admin | **Collect fee via SRNO** | **receipt/demand/allocation/ledger all carry `studentId`** |
| 4 | Admin | Browse / SIS / Transport / Hostel / Library search by SR | student found **with SR visible** |
| 5 | Admin | Assign route / bed / issue book | posts `student_id`, not `srNo` |
| 6 | Admin | Two schools, same SR 47 | each resolves its own student |
| 7 | Admin | Re-admitted student, search SR 47 | returns the **new** Student ID |
| 8 | Admin | Promoted student, search SR | same Student ID, new class/roll |
| 9 | Teacher | Roster / attendance / marks / homework / flag | `Roll · SR` visible |
| 10 | Teacher | Search by SR | exactly that student |
| 11 | Teacher | **Save marks / homework / flag** | **document keys on `studentId`** |
| 12 | Teacher | PTM RSVP list | shows `SR` on new bookings; old ones render safely blank |
| 13 | Parent | Profile | all six identity fields |
| 14 | Parent | Switch child A→B→A | no stale SRNO / Adm No / Roll |
| 15 | Parent | **Re-admitted child** | **⚠ expected to FAIL today — N12-1: two entries appear** |
| 16 | Parent | Cold restart | values persist; withdrawn status not reset |

**Row 15 is the one expected to fail.** Run it to confirm N12-1 before fixing.

## 32. Final Verdict

# READY WITH CONDITIONS

**Ready.** SRNO is operationally usable everywhere it should be, Student ID is the persisted identity
everywhere without exception, no identifier is derived from another (test-enforced), tenant isolation
holds structurally, promotion and re-admission are correct at the service layer, and the entire
feature costs **+1 Firestore read on 2 of 22 workflows**. No BLOCKER, no CRITICAL, no data-corruption
or tenant defect was introduced by any phase in this program.

**Conditions — five decisions required (§21). I have NOT implemented any of them:**

| # | Decision | Class | Recommendation |
|---|---|---|---|
| **D1** | **Authorize the N12-1 fix?** Add a status filter to the Parent app's child list so a re-admitted student's withdrawn record stops appearing as a second child. One predicate, 0 added reads. Explicitly **not** a sibling redesign. | **HIGH** | **Yes — smallest fix with the largest user-visible effect** |
| **D2** | **Authorize SIB-B1 separately?** Still live, still losing sibling data on bulk import. Independent of D1; **do not bundle**. | **HIGH** | Yes, as its own phase |
| **D3** | **Authorize the P11-N1 APAAR fix?** Mask APAAR in Student 360 and route reveal through the existing audited endpoint. | MEDIUM | Yes — reuses `apaar_reveal()`, closes an audit gap |
| **D4** | **Authorize N12-2 / P11.3-N2 latency work?** Batch the attendance name backfill; cache the Fees search. | MEDIUM | Yes, but after D1–D3 |
| **D5** | **Roll Number uniqueness — business decision.** None exists today; two students in one section can share Roll 18. Should a School+Session+Class+Section-scoped rule be introduced? | LOW | Your call — **I invented no rule** |

**Also for your awareness (not a decision I can make):** **N12-4** — `integration/phase2` exists only
on this machine, with no remote branch and no upstream. Every phase from 9 to 11.5 is uncommitted
across three repositories.

**Closed with no action needed:** Phase 10 §H (Operations_accounting RTDB) — **N12-3 proves it is
already Firestore-only.**

---

## 33. Required Confirmation (§24)

- ✔ No source files modified except this report.
- ✔ No Firestore data written.
- ✔ No test accounts created.
- ✔ No rules deployed. · ✔ No indexes deployed.
- ✔ No RTDB introduced.
- ✔ No Student ID replaced.
- ✔ No SRNO inferred from Student ID.
- ✔ No Roll Number uniqueness invented.
- ✔ No sibling architecture changed.
- ✔ No unrelated refactoring performed.
- ✔ No commit. · ✔ No push. · ✔ No merge. · ✔ No rebase.

---

# WHAT THIS MEANS FOR ME

**The short version:** the SR Number now works as a real way to find students, and the system always
converts it to the student's true internal ID before saving anything. One thing is broken, and it
only shows up when a student leaves and comes back.

## Following SRNO 47 through a real day

**The office says: "SR Number 47 needs to pay fees."**

The clerk types `47` into the fee counter. The screen answers:

> ✓ SR No. 47 → STU0012 · Rahul Sharma · Roll 18

That confirmation line matters. The clerk can see *which* student the register number belonged to
before any money moves — the system never silently picks someone.

Behind the scenes: `47` → the school's own SR register → Rahul's real ID `STU0012` → his fee demand →
payment → receipt → accounting entry. **Every one of those records is filed under `STU0012`, never
under `47`.** So if the school ever renumbers its register, none of Rahul's financial history breaks.

**The same student, everywhere else:**

- **Teacher App** — Rahul's name in the class list reads `Roll: 18 · SR: 12`. The teacher never types
  anything; the class is already on screen. Marks, attendance, homework and red flags all save under
  `STU0012`.
- **Parent App** — Rahul's parent opens the app and sees Student ID, SR No., Admission No., Class,
  Section and Roll. They never search for anything: they're logged in as Rahul's parent, so the app
  simply knows.
- **Transport / Hostel / Library** — the clerk can search `47`, and now (as of the last phase) the
  result **shows** `SR 47` so they can confirm before assigning a bus seat or a hostel bed.
- **PTM** — when the parent books a meeting slot, the SR Number is stored on the booking, so the
  teacher's list shows it without the app doing extra work.

## What happens when Rahul is promoted

Class 5-A → 6-A, Roll 18 → 7. **His Student ID and SR Number do not change.** Searching `47` still
finds him. Nothing breaks, because nothing was ever filed under his class or roll number.

## What happens when Rahul leaves and comes back — **this is the problem**

March: Rahul leaves. June: he re-joins. He gets a **new** internal ID (`STU0047`) and a **new**
Admission Number, but **keeps SR Number 47** — because 47 identifies the person, not the enrolment.

Searching `47` correctly returns the **new** record. The office, the teacher and the fee counter all
behave properly. His old fee receipts stay attached to the old ID, exactly as they should.

**But his parent's app now shows two children, both named Rahul.** One is the real current record;
the other is the closed March record. Tapping the old one shows a frozen page — no fees, no
attendance — with no explanation.

**Why:** the parent app finds a family by matching phone numbers and parent names, and it never
checks whether a record is still active. **The fix is one line** — ignore closed records — and it
costs nothing. I have not made that change; it needs your go-ahead, because that piece of code is
near the sibling logic you told me not to touch. To be clear: **this is a re-admission fix, not a
sibling redesign.**

## Three other things worth knowing

**Good news — one old worry is gone.** A previous phase flagged that the accounting engine might
still be using the old Realtime Database. I checked properly this time: **it isn't.** Every call is
Firestore. That pending decision can be dropped.

**A slow spot I found.** The attendance punch report looks up each person's name one at a time when
the names are missing from the imported data. On a busy day that could be hundreds of separate
lookups, one after another. Most days it costs nothing. It's fixable, unrelated to SR Numbers, and
not urgent.

**Something to protect.** All of this work — every phase from 9 to 11.5 — exists **only on your
computer**. The `integration/phase2` branch isn't on the server at all, and nothing is committed. If
this machine were lost, the work would be lost. That's not a bug; I just don't want it to be a
surprise.

## What I need from you

Five decisions, in the report's §32. The one I'd act on first is **D1** — stop the parent app showing
a re-admitted child twice. Smallest change, most visible improvement, and no performance cost.

**I have changed nothing. Nothing is committed, pushed or deployed.**
