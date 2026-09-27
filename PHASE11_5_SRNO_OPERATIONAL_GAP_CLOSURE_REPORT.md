# Phase 11.5 — SRNO Operational Gap Closure (G1 + G2)

**Date:** 2026-08-14 · **Branch:** `integration/phase2` @ `758c924` · **Type: IMPLEMENTATION**

**Nothing committed, staged, pushed, merged, rebased or deployed. No Firestore rules or indexes
touched. No RTDB introduced. No production data written. No test account created.**

Scope was strictly the two items authorized: **G1 (PTM SRNO)** and **G2 (Transport / Hostel /
Library picker display)**. Nothing else was changed.

---

## 1. Executive Summary

Both authorized gaps are closed, and both hit their **zero-cost targets exactly**.

| | Result |
|---|---|
| **G1 — PTM SRNO** | `srNo` denormalised onto **all four** RSVP write paths, sourced from the already-cached `User`. Teacher PTM list displays it. **+0 reads, +0 round trips, +0 writes** — one extra key on a write that already happens. |
| **G2 — Pickers** | Transport, Hostel and Library now display the SR No. in search results. **+0 reads, +0 round trips** — and it needed **no controller change at all**: `srNo` was already in the JSON, only the templates were missing it. |
| **Files changed** | **7** (3 Parent, 2 Teacher, 3 Admin views — minus overlap = 3+2+3) plus 1 test file extended |
| **Tests** | 17 pass (2 new). Full suite **736 / 10 failures = exact pre-existing baseline**, zero new failures |
| **Compiles** | Parent ✔ · Teacher ✔ |
| **Rules / indexes** | **Unchanged** — as predicted (§13) |

**One finding worth flagging:** G2 turned out cheaper than the audit estimated. Phase 11.4 said it
would need "a `sr_no` field added to the response row". In fact the cached index entries already
carry `srNo` and neither Transport's nor Hostel's `unset()` strips it — so **the data was reaching
the browser already and simply wasn't rendered.** Three one-line template edits, zero PHP changes.

**The §12 non-negotiable rule is now enforced by test.** Two new tests prove `STU0012` does **not**
imply SRNO 12, using a deliberately crossed pairing (SRNO 47 → STU0012, SRNO 12 → STU0047) that a
digit-derivation bug would get exactly backwards.

**Verdict: READY WITH CONDITIONS** (§18) — the only conditions are UAT, which was not executed.

---

## 2. Files Changed

**Parent App (3)**

| File | Change |
|---|---|
| `data/repository/firestore/PtmFirestoreRepository.kt` | `srNo` param + payload key on **all four** RSVP writers: `setRsvp`, `setRsvpBookings`, `applyToPtm`, `declineFromPtm` |
| `ui/ptm/PtmDetailViewModel.kt` | Passes `srNo = user.srNo` at both live call sites (apply, decline) |

**Teacher App (2)**

| File | Change |
|---|---|
| `data/model/firestore/PtmDoc.kt` | `PtmRsvpDoc.srNo: String = ""` |
| `ui/ptm/MyPtmScreen.kt` | RSVP row subtitle shows `SR {n}` between Roll and Parent |

**Admin Panel (3 views + 1 test)**

| File | Change |
|---|---|
| `views/transport/index.php` | Student picker result shows `SR {n}` |
| `views/hostel/index.php` | Student picker result shows `SR {n}` |
| `views/library/index.php` | Student picker result shows `SR {n}` |
| `tests/Unit/SrNoDualLookupTest.php` | +2 tests (§12 derivation guard) |

**No controller, service or model changed in the Admin Panel.** No Parent App identity/auth code
touched. No Teacher App save path touched.

## 3. G1 — PTM SRNO Implementation

**Pre-edit verification (required by §2 of the brief), all confirmed at source:**

| Check | Result |
|---|---|
| Cached Parent `User` already contains `srNo`? | ✔ `User.kt` — added Phase 11 |
| Existing RSVP write already denormalises `studentName` / `className` / `section` / `rollNo`? | ✔ all four, on every writer |
| Values sourced from the cached user, not a lookup? | ✔ `PtmDetailViewModel:169-175` and `:243-249` read `user.*` |

**Discovery during implementation, corrected against the audit:** Phase 11.4 identified *two* RSVP
write paths. The source has **four** — `setRsvp()` and `setRsvpBookings()` (legacy, currently no
callers) plus `applyToPtm()` and `declineFromPtm()` (the live pair the ViewModel actually calls).
**All four were updated**, with `srNo: String = ""` defaulted so no caller breaks, specifically so the
two dormant paths cannot silently drift if they are ever revived.

Architecture delivered, exactly as specified:

```
cached user (already in memory)
   ↓
existing PTM RSVP write            ← no new read, no new round trip
   ├── studentId   ← canonical key, UNCHANGED
   ├── studentName
   ├── className
   ├── section
   ├── rollNo
   └── srNo        ← ADDED
```

The RSVP document id remains `{schoolId}_{ptmEventId}_{studentId}` — **the key is untouched**.
`srNo` is a denormalised display field and nothing resolves a student *from* it.

**Teacher side:** `PtmRsvpDoc.srNo` deserialises from the RSVP document the teacher's list query
already downloads — **no per-row lookup**. `MyPtmScreen` renders it in the existing subtitle.

**Old RSVPs — documented limitation.** RSVPs written before this change carry no `srNo`, deserialise
to `""`, and the UI omits the segment. **No backfill was performed and none is proposed** — rewriting
historical RSVP documents would be a production data write, and a per-RSVP resolve would be exactly
the N+1 §2 forbids. The teacher sees SR No. on RSVPs created from now on; older ones show Roll and
Parent as before.

## 4. G2 — Transport / Hostel / Library Picker Display

**Pre-edit audit of each, per §3 of the brief:**

| Picker | Search source | `srNo` in returned object? | Extra read needed? | Selection value |
|---|---|---|---|---|
| **Transport** | `Transport_service::searchStudents()` — 300 s cached index | ✔ (added 11.3) | **No** | `data-id` = Student ID |
| **Hostel** | `Operations_accounting::search_students()` — 300 s cached index | ✔ (added 11.3) | **No** | `data-id` = Student ID |
| **Library** | `Operations_accounting::search_students()` — same index | ✔ (added 11.3) | **No** | `data-id` = Student ID |

**Key finding: no PHP change was required.** Transport and Hostel controllers do
`unset($r['section'], $r['user_id'])` — `srNo` is **not named**, so it survives and was already
arriving in the JSON. Library strips nothing. The gap was purely that the three templates never
rendered it.

**Change:** one conditional span/`<small>` per template, rendered only when `srNo` is non-empty so
students without one look exactly as before.

**Persistence preserved — verified:** each row's `data-id` is still `s.id` (the Student ID), and each
click handler still writes it to the hidden field that the save posts:

- Transport → `$('#asnStudentId').val($(this).data('id'))` → `save_assignment{student_id:…}`
- Hostel → `$('#allocStudentId').val($(this).data('id'))` → `save_allocation{student_id:…}`
- Library → `$('#issStudentId').val($(this).data('id'))`

**The selected student still submits `studentId = STU0012`, never `srNo = 47`.**

**Static proof:** a grep for `'srNo' =>` across `Fees.php`, `Transport.php`, `Hostel.php` and
`Library.php` returns **zero** — no module persists SRNO.

## 5. Before / After Firestore Read-Write Counts

| Workflow | Reads before | Reads after | Writes before | Writes after | Extra RTT | N+1 | Verdict |
|---|---:|---:|---:|---:|---:|---|---|
| PTM apply (`applyToPtm`) | n | **n** | 1 txn | **1 txn** (one extra key) | **0** | none | **TARGET MET** |
| PTM decline (`declineFromPtm`) | 0 | **0** | 1 | **1** (one extra key) | **0** | none | **TARGET MET** |
| PTM legacy `setRsvp` / `setRsvpBookings` | unchanged | unchanged | unchanged | unchanged | **0** | none | **TARGET MET** |
| Teacher PTM list | n (RSVP query) | **n** | 0 | 0 | **0** | none | **TARGET MET** |
| Transport picker | 1 (300 s cache) | **1** | 0 | 0 | **0** | none | **TARGET MET** |
| Hostel picker | 1 (300 s cache) | **1** | 0 | 0 | **0** | none | **TARGET MET** |
| Library picker | 1 (300 s cache) | **1** | 0 | 0 | **0** | none | **TARGET MET** |

**All four §6 targets met exactly: +0 reads, +0 RTT everywhere; PTM gains one field on an existing
write and nothing else.**

## 6. Latency Impact

**None measurable.** No new query, no new document fetch, no new round trip, no cache invalidation.

- The PTM write payload grows by one short string field on a document already being written.
- The three pickers do strictly more DOM work on data already in the browser.
- No `srNoIndex` lookup was added anywhere for display (§6.6 of the brief) — every SRNO shown comes
  from a document already in hand.
- No repeated student-document fetch was introduced (§6.7).
- Existing caches reused unchanged (§6.8): the 300 s picker indexes and the RSVP list query.

## 7. N+1 Analysis

**No N+1 introduced, and one was specifically avoided.**

The tempting-but-wrong implementation of G1 would have been to resolve SRNO per RSVP row on the
Teacher side — one student read per booking. **That is exactly what was not done.** SRNO is
denormalised once at write time, on a write that already exists, and read back as a plain field of
the RSVP document the list query already returns.

The cost of that choice is honest and stated: RSVPs predating the change show no SR No. That is the
correct trade — a blank field is better than N reads per screen.

## 8. Student Lifecycle Cross-Module Verification

Traced per §4 of the brief. `SID` = canonical Student ID persisted.

| Stage | Persisted key | SRNO role | Verified |
|---|---|---|---|
| New admission | `studentId` generated globally | allocated school-scoped, independent | ✔ unchanged |
| Admission Number | independent `YYYY/TOKEN` | not derived from SRNO | ✔ unchanged |
| Class / Section / Roll assigned | on student doc | Roll ≠ SRNO | ✔ unchanged |
| **Fees** | `studentId` | lookup only, resolved before write | ✔ 8 write sites, 0 srNo |
| **Attendance** | `studentId` | display only | ✔ `AttendanceFirestoreRepository:71,103,266` |
| **Marks / Exam / Results** | `studentId` | display only | ✔ `ExamFirestoreRepository:144,213` |
| **Homework / LMS** | `studentId` | display only | ✔ save path untouched |
| **Red Flags** | `studentId` | display only | ✔ save path untouched |
| **Transport** | `studentId` (`save_assignment`) | search + display | ✔ **this phase** |
| **Hostel** | `studentId` (`save_allocation`) | search + display | ✔ **this phase** |
| **Library** | `studentId` (issue) | search + display | ✔ **this phase** |
| **PTM** | `studentId` (doc id + field) | **denormalised display** | ✔ **this phase** |
| Promotion | `studentId`, SRNO, Adm No unchanged | resolution invariant | ✔ unit-tested |
| Re-admission | new `studentId`, SRNO relinked | resolves to CURRENT id | ✔ unit-tested |

**A–J checklist (§4):**

- **A.** Student ID canonical in every persisted record — ✔ verified per module above.
- **B.** SRNO school-scoped human identifier — ✔ `srNoIndex/{schoolId}_{norm}`.
- **C.** SRNO never inferred from Student ID — ✔ **now test-enforced** (§9).
- **D.** Student ID never inferred from SRNO except via `Sr_no_service::resolve()` — ✔ one resolver,
  two callers.
- **E.** Roll Number stays class/section scoped — ✔ untouched; still excluded from every haystack.
- **F.** Admission Number independent — ✔ untouched.
- **G.** Promotion changes neither Student ID nor SRNO — ✔ unit-tested.
- **H.** Re-admission resolves SRNO to the current Student ID — ✔ unit-tested.
- **I.** Historical Student IDs remain historical — ✔ `historicalStudentIds` retained, never returned
  by `resolve()`.
- **J.** No module began persisting SRNO — ✔ **grep-verified**: the only four `"srNo" to` writes in
  the entire Parent app are the authorized PTM denorm; zero `'srNo' =>` writes in Fees, Transport,
  Hostel or Library; zero SRNO writes anywhere in the Teacher app.

## 9. Student ID vs SRNO Invariant — now enforced by test

**§12's non-negotiable rule is now a regression guard, not just a convention.**

```php
// SRNO 47 -> STU0012      and      SRNO 12 -> STU0047      (deliberately crossed)
$this->assertSame('STU0012', $svc->resolve('47')['currentStudentId']);
$this->assertSame('STU0047', $svc->resolve('12')['currentStudentId']);
```

A digit-derivation bug would return these swapped. A second test proves the converse: with only
SRNO 47 claimed in the school, `resolve('12')` — the numeric tail of `STU0012` — **fails**, so the
digits inside a Student ID can never be mistaken for its SR Number.

**`STU0012` does NOT mean SRNO 12. Both directions are now asserted.**

## 10. Re-admission Verification

Unchanged and re-confirmed. `Sr_no_service::resolve()` returns `currentStudentId` only:

| Step | Index state | `resolve("47")` |
|---|---|---|
| First admission | `currentStudentId: STU0012` | `STU0012` |
| After relink | `currentStudentId: STU0047`, `historicalStudentIds: [STU0012]` | **`STU0047`** |

Because every SRNO-enabled surface (Fees, SIS, Transport, Hostel, Library, Teacher search) routes
through that one resolver, **no surface can disagree**. PTM is unaffected: an RSVP is written against
whichever `studentId` is authenticated at the time, and historical RSVPs stay attached to the old ID.

## 11. Promotion Verification

Unit-tested: a student-document write mutating class/section/roll leaves `srNoIndex` byte-identical
and `resolve()` returns the same Student ID. Student ID, SRNO and Admission Number preserved; Class,
Section, Roll may change. **Nothing in this phase touches promotion.**

## 12. Tenant Isolation

Unchanged and re-verified by test: School A + 47 → STU_A, School B + 47 → STU_B, A never resolves B's
student, and a caller-supplied `schoolId` cannot redirect resolution (scope comes from
`Firestore_service`, set from authenticated claims).

**This phase adds no new query and no new tenant surface.** PTM writes are scoped by
`getSchoolId()` into the doc id; the three pickers read school-scoped cached indexes.

## 13. Rules / Index Verification

**Expected result achieved: both files unchanged.**

```
firebase-rules/firestore.rules         mtime 2026-08-13 23:18  — untouched
firebase-rules/firestore.indexes.json  mtime 2026-08-12 17:09  — untouched
```

Per §7, no rule or index change was made merely because a new field is stored on an existing
document. `ptmRsvps` already had a rules block and `srNo` is an ordinary field on an existing
document — Firestore is schemaless and no index is involved (no query filters or orders by `srNo`).
Because no change was needed, **no pull/reconcile was performed and nothing was deployed.**

## 14. Test Results

| Check | Result |
|---|---|
| `php -l` — 3 changed views + test file | **PASS** |
| `SrNoDualLookupTest` | **17 tests, 65 assertions — all pass** (2 new) |
| Full unit suite | **736 tests, 2353 assertions, 10 failures** |
| Baseline comparison | **10 = exact pre-existing baseline. Zero new failures.** (734 → 736 = +2, both mine, both passing) |
| Parent App `compileDebugKotlin` | **BUILD SUCCESSFUL** (2m 51s) |
| Teacher App `compileDebugKotlin` | **BUILD SUCCESSFUL** (3m 26s) |
| Static: accidental SRNO writes | **0** outside the authorized PTM denorm |
| Static: `studentId` replacement | **none** |
| Static: RTDB additions | **0** |
| Static: new Firestore queries | **0** |
| Static: N+1 patterns | **0** |
| Static: client-controlled `schoolId` | **none** |
| Static: global `srNo ==` queries | **0** across all three repos |

The 10 pre-existing failures are cross-repo source-grep tests (Backup ×1, Homework ×5,
ParentHomeworkRepo ×3, TeacherDeleteCascade ×2). None touches a file changed in this phase.

**Tests NOT added, and why:** the brief asks for "new RSVP contains srNo" and "search result displays
SRNO" tests. Both are integration-level assertions — one needs a Firestore write, the other a
rendered DOM. Neither is expressible in this PHPUnit suite, which has no Firestore or browser
harness. They are in the UAT matrix (§15) instead rather than being faked with a source-grep test.

## 15. UAT Status — **NOT EXECUTED**

**No UAT PASS is claimed.** Both apps compile; neither was device-tested for this phase, and the
Admin Panel needs a browser session with a real tenant login.

| # | Test | Expected |
|---|---|---|
| U1 | Parent: apply to a PTM | new `ptmRsvps` doc contains `srNo` **and** `studentId` |
| U2 | Parent: decline a PTM | same |
| U3 | Teacher: open PTM RSVP list | rows show `Roll #18 · SR 47 · Parent: …` |
| U4 | Teacher: an RSVP created **before** this change | renders safely with no SR segment |
| U5 | Admin Transport: search `47` | Rahul Sharma shown **with `SR 47` visible** |
| U6 | Admin Transport: assign the route | `save_assignment` posts `student_id = STU0012` |
| U7 | Admin Hostel: search `47`, allocate | SR visible; `save_allocation` posts the Student ID |
| U8 | Admin Library: search `47`, issue | SR visible; issue keys on the Student ID |
| U9 | Cross-tenant | School B's SRNO 47 never appears in School A's picker |
| U10 | Re-admitted student | picker search by SR returns the NEW Student ID |

## 16. Remaining Pre-existing Issues — unchanged, none touched

| ID | Severity | Status |
|---|---|---|
| **SIB-B1** | **HIGH — data loss** | **STILL LIVE.** Not touched (§8.1). Not worsened by this phase — PTM and the pickers are downstream of import. |
| **P11-N1** | MEDIUM — security | Student 360 APAAR plaintext. **Not touched** (§8.2). |
| **P11.3-N1** | MEDIUM | Certificates RTDB. **Not touched** (§8.3). Correct order remains migrate → then SRNO. |
| **P11.3-N2** | MEDIUM — latency | `Fees::_searchByName()` uncached scan. **Not touched, not worsened.** |
| **P11.2-N3** | LOW | `Sr_no_service::init()` schoolId inconsistency. Immaterial to `resolve()`. |

## 17. Explicitly NOT Touched

Per §8 of the brief, none of the following was modified:

1. SIB-B1 sibling bulk-import — untouched.
2. Student 360 APAAR exposure — untouched.
3. Certificates RTDB migration — untouched; **no RTDB fallback or workaround added**.
4. Roll Number uniqueness — **no uniqueness rule invented**; Roll Number semantics unchanged and it
   remains excluded from every search haystack.
5. Campus Access name-only picker — untouched.
6. Student export — untouched (still none exists).
7. Historical import policy — untouched.
8. Parent App architecture — **no SRNO lookup added**; the parent still reaches their child through
   authentication only.
9. Student ID architecture — unchanged; still the canonical persisted identity everywhere.
10. Any unrelated refactoring — none.

Also untouched: `Sr_no_service.php`, `Id_generator.php`, `Admission_no_service.php`,
`Apaar_service.php`, `MY_Controller::_resolveStudentRef()`, every Admin controller, every module data
layer, `firestore.rules`, `firestore.indexes.json`, and all authentication paths.

## 18. Final Verdict

# READY WITH CONDITIONS

| Class | Finding |
|---|---|
| **CODE DEFECT** | **None.** |
| **SECURITY DEFECT** | **None introduced.** P11-N1 pre-existing and open. |
| **ARCHITECTURE BLOCKER** | **None for this phase.** Certificates RTDB blocks SRNO there only — untouched by design. |
| **PERFORMANCE / LATENCY ISSUE** | **None introduced.** All four zero-cost targets met. P11.3-N2 pre-existing, not worsened. |
| **ENVIRONMENT LIMITATION** | UAT not executed (§15); PTM write-content and DOM-render assertions not unit-testable in this suite. **Not grounds for BLOCKED.** |
| **FUTURE ENHANCEMENT** | Campus Access picker · student export · `Fees::_searchByName()` caching · Certificates migration → then SRNO · optional PTM backfill (deliberately not proposed). |
| **PRE-EXISTING ISSUE** | SIB-B1 (HIGH), P11-N1, P11.3-N1, P11.3-N2, P11.2-N3 — all unchanged and unworsened. |

**To reach READY:** execute §15, above all **U1/U3** (an RSVP written with `srNo` and displayed) and
**U6/U7** (a route/bed assignment posting the Student ID, not the SR Number).

## 19. Git Working-Tree Status

```
Branch: integration/phase2        HEAD: 758c924   (unchanged — nothing committed)

Admin Panel   425 → 428 dirty     (+3: two of the three picker views newly dirty
                                   [one was already dirty from other work], + this report)
Teacher App    29 → 31 dirty      (+2: PtmDoc.kt, MyPtmScreen.kt)
Parent App     20 → 22 dirty      (+2: PtmFirestoreRepository.kt, PtmDetailViewModel.kt)

Verified file-by-file — the changed set is exactly:
  M application/views/{transport,hostel,library}/index.php
  M teacher/.../data/model/firestore/PtmDoc.kt
  M teacher/.../ui/ptm/MyPtmScreen.kt
  M parent/.../data/repository/firestore/PtmFirestoreRepository.kt
  M parent/.../ui/ptm/PtmDetailViewModel.kt
  ?? tests/Unit/SrNoDualLookupTest.php   (extended, was untracked from 11.3)
  ?? PHASE11_5_SRNO_OPERATIONAL_GAP_CLOSURE_REPORT.md

firebase-rules/firestore.rules         untouched (mtime 2026-08-13)
firebase-rules/firestore.indexes.json  untouched (mtime 2026-08-12)
```

All pre-existing working-tree changes preserved. No unrelated file modified, staged or reverted.

---

**Nothing committed. Nothing pushed. Nothing merged. Nothing rebased. Nothing deployed.**
