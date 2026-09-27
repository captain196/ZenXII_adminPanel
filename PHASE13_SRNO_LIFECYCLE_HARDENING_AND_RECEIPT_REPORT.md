# Phase 13 — SRNO Lifecycle Hardening, Roll Number Decision & Fee Receipt

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924` · **Type: IMPLEMENTATION**

**Nothing committed, staged, pushed, merged, rebased or deployed. No Firestore rules or indexes
touched. No RTDB introduced. No production data written. No test account created.**

---

## 1. Executive Summary

Six workstreams were audited; **five were implemented**, and **one (Roll Number uniqueness) was
deliberately NOT implemented because the evidence did not justify it**.

| WS | Item | Outcome | Cost |
|---|---|---|---|
| **A** | N12-1 re-admission leak into Parent App | **FIXED** — status predicate | **0 reads** |
| **B** | SIB-B1 bulk-import sibling data loss | **FIXED** — phone alone no longer proves duplicate | +1 read only on phone-collision rows |
| **C** | P11-N1 APAAR plaintext in Student 360 | **FIXED** — masked to `XXXX XXXX 9012` | **0 reads** |
| **D1** | Attendance N+1 | **FIXED** — chunked `IN` batching | same reads, **RTTs ÷ 30** |
| **D2** | Fees uncached full scan | **FIXED** — reuses the write-invalidated snapshot | **cache hit = 0 reads** |
| **E** | Roll Number uniqueness | **NOT IMPLEMENTED — evidence says don't** | — |
| **F** | SRNO on fee receipt | **IMPLEMENTED** + a mislabel defect fixed | **0 reads** (new receipts) |

**Three findings the brief did not anticipate:**

1. **The receipt has been mislabelling the Student ID as "Admission" all along.** `views/fees/receipt.php:427`
   printed `$uid` (`STU0012`) under the label **"Admission"**. That is neither the Admission Number
   nor a correct label — every receipt ever printed has shown a Student ID where a reader would
   expect `2026/7K4P92`. Fixed as part of F.
2. **`firestoreGetParallel()` is not parallel.** Despite the name it is a plain sequential `foreach`
   over `getDocument()` (`Firebase.php:1196-1218`). Using it for D1 — the brief's suggested
   approach — would have removed **zero** round trips. I used chunked `IN` queries instead.
3. **My first D2 attempt would have introduced a staleness bug.** I initially used a new cache key
   (`…_feesearch`), which `_bustDashboard()` does not delete — it would have served deleted students
   for up to 90s. Corrected to reuse the existing `students_raw` key, which **is** in the bust list.

**Verdict: READY WITH CONDITIONS** (§27) — implementation and static/test verification are complete;
**no browser or device UAT was executed.**

## 2. Files Inspected

`Sis.php` · `Fees.php` · `Attendance.php` · `Student360.php` · `FeeCollectionService.php` ·
`Fee_firestore_txn.php` · `Firestore_service.php` · `Firebase.php` · `Firestore_rest_client.php` ·
`Sr_no_service.php` · `Student_context.php` · `Operations_accounting.php` · `Transport_service.php` ·
`Homework.php` · `Lms.php` · `Certificates.php` · `views/fees/receipt.php` ·
`views/student360/workspace.php` · Parent `StudentFirestoreRepository.kt` · Parent `PtmFirestoreRepository.kt`

## 3. Files Changed (8)

| File | Workstream | Change |
|---|---|---|
| `Parent/.../StudentFirestoreRepository.kt` | **A** | status predicate in `findSiblings()` |
| `application/controllers/Sis.php` | **B** | phone+name duplicate rule (commit path + pre-commit scan) + `_studentNameMatches()` |
| `application/views/student360/workspace.php` | **C** | `s360_mask_apaar()` + masked render |
| `application/controllers/Attendance.php` | **D1** | chunked `IN` batching in `_resolve_punch_names()` |
| `application/controllers/Fees.php` | **D2, F** | cached search; receipt SRNO/Roll plumbing + legacy fallback |
| `application/services/FeeCollectionService.php` | **F** | `srNo`/`rollNo` denormalised onto both receipt write paths |
| `application/views/fees/receipt.php` | **F** | identity block: SR No. + Roll No. shown, Student ID unprinted, mislabel removed |
| `tests/Unit/Phase13LifecycleHardeningTest.php` | all | **NEW** — 16 tests |

## 4. Workstream A — N12-1 Re-admission Parent App Bug

**Re-verified against current source: the defect was real and unchanged.** `findSiblings()` filtered
on phone + parent-name only:

```kotlin
ref.whereEqualTo("schoolId", schoolCode)      // ONLY filter — no status, no limit
…
fatherMatch || motherMatch || primaryNamesBlank || sNamesBlank
```

A re-admitted student shares phone and parent names with their **own withdrawn record**, so both
appeared in the child switcher.

**Fix** — one predicate, before any name matching:

```kotlin
val sStatus = s.status.trim()
if (sStatus.isNotBlank() && !sStatus.equals("Active", ignoreCase = true)) return@filter false
```

Blank status is treated as Active, matching the convention `AuthRepository`/`TokenManager` already
use for pre-field documents.

**Scope discipline:** sibling relationship architecture, guardian architecture, authentication and
cross-school family logic are **untouched**. SIB-B1 was fixed **separately** (§5) and shares no code
with this change.

**Cost: 0 additional reads** — `status` is already on every `StudentDoc` the query downloads.

## 5. Workstream B — SIB-B1 Bulk Import Data Loss

**Root cause, stated precisely:** phone equality alone was treated as proof of "same student".

```php
$dbId = $this->_existingStudentIdByPhone($phone10);
if ($dbId) { $existingId = $dbId; … }        // ← a sibling's phone matches too
```

`indexPhones` maps one phone → one studentId, so for a family it resolves to *whichever sibling was
imported first*. Under `skip` the second child was discarded; under `update` the second child's data
**overwrote the first child's record**.

**Fix — corroboration, not a redesign.** A duplicate now requires **phone AND normalised name**:

| Scenario | Phone | Name | Verdict |
|---|---|---|---|
| Two siblings | same | **different** | **both imported** ✔ |
| Same student, file re-imported | same | same | still deduped ✔ |
| Same student, different letter case / spacing | same | same (normalised) | still deduped ✔ |

Changes:
- `$seenPhones` is now **nested by name** (`phone => [name => studentId]`). A flat phone→id map
  structurally cannot represent a family — that shape *was* the bug.
- New `_studentNameMatches()` corroborates a DB phone hit against the student's actual name.
- **The pre-commit scan (`import_dedupe_check`) was changed identically.** Had it not been, the
  preview would warn that two siblings are duplicates and then the import would correctly create
  both — worse, an operator might trust the warning and delete a legitimate row.

**Name, not name+DOB, is the corroborator on purpose:** the same student re-exported with DOB present
in one file and blank in another must still dedupe; a name+DOB key would miss that and create a
duplicate student.

**Fails safe:** if the corroboration read fails, `_studentNameMatches()` returns false → the row is
imported as a **new student** rather than silently overwriting an existing one.

**Cost:** +1 read **only** on rows whose phone already belongs to a student in this school — i.e.
sibling rows and genuine re-imports. An ordinary first-time admission row never reaches it.

**Idempotency preserved:** re-running the same file still dedupes (same phone, same name), so no
duplicate students are created by a retry.

**Untouched:** sibling inference, guardian architecture, `indexPhones` schema, sibling discounts,
cross-school families. SRNO/APAAR/Roll allocation paths are unchanged and still run *after* dedup.

## 6. Workstream C — APAAR Security

**Audit result: exactly ONE render path**, so the fix is complete rather than partial —
`views/student360/workspace.php:877`, fed by `Student_context.php:473`. No other Student 360 view,
panel, export or JSON response emits `apaarId`.

**Before:** full plaintext (`123456789012`), no masking, no audit entry, behind `SIS/view` — a much
broader gate than `Sis::apaar_reveal()` (Super Admin / School Super Admin / Admin / Principal, with
`log_audit('SIS','apaar_revealed',…)`).

**After:** `XXXX XXXX 9012`.

- **No second reveal mechanism was created.** Full disclosure remains exclusively through the
  existing audited endpoint.
- Blank in → blank out (the row still doesn't render for students without an APAAR).
- A non-12-digit value is masked defensively rather than shown raw.
- **0 reads** — pure formatting on already-loaded data.
- Parent App, Teacher App, searches, exports, logs and URLs remain APAAR-free (re-verified).

## 7. Workstream D1 — Attendance Latency

**Two corrections to the Phase 12 description**, both verified this pass:

1. The function is `_resolve_punch_names()`, not `_backfillPunchNames()`.
2. It is **page-bounded**, not unbounded — deduped to one read per *unique unnamed person on the
   current page*. Phase 12's "300 sequential reads" was the worst case for a large page, not typical.

Its docblock already claimed "Batched + de-duplicated". It **was** de-duplicated; it was **not**
batched — every read was its own blocking round trip.

**Fix:** one `IN` query per chunk of 30 ids, per collection, using the `in` operator the REST client
already supports (`Firestore_rest_client.php:1491,1627`).

| | Before | After |
|---|---:|---:|
| Document reads (50 unnamed) | 50 | 50 (billing unchanged) |
| **Sequential round trips** | **50** | **2** |
| Healthy path (names present) | 0 | **0** — unchanged early return |

**`firestoreGetParallel()` was considered and rejected** — it is sequential (§1). Using it would have
been a rename, not a fix.

A per-document fallback remains for ids the batch cannot resolve (legacy docs missing the id field),
bounded by the misses only. Fully best-effort throughout: any failure leaves the id displayed.

## 8. Workstream D2 — Fees Search Latency

**Before:** `_searchByName()` read **every student in the school on every search** — the only one of
the panel's six student searches without a cache.

**After:** reuses the existing `students_raw` snapshot that `Sis::_cached_student_docs()` already
maintains.

**Why that key specifically:** it is one of the two keys `Firestore_service::_bustDashboard()`
explicitly deletes on **any** `students` write, so admissions, deletions and status changes appear on
the very next search. The cache is **write-invalidated**, not merely time-expired.

> **A bug I introduced and caught before shipping:** my first attempt used a new key
> (`studentsListCacheKey() . '_feesearch'`). That key is **not** in the bust list, so a deleted
> student would have kept appearing in fee search for up to 90 s. Corrected to the shared key.
> Shape is compatible — `schoolWhere` returns `[['id'=>…,'data'=>[…]],…]`, exactly what the loop
> already unwraps.

| | Before | After |
|---|---:|---:|
| Cache hit | n/a | **0 reads** |
| Cache miss | N (every time) | N (once per 90 s / per write) |
| 2,000-student school, 5 searches | **10,000 reads** | **2,000 reads** (one miss + 4 hits) |

Bonus: a warm cache from the SIS dashboard or ID-card page now serves fee search for free, and the
two searches can no longer disagree about who exists.

## 9. Roll Number Architecture Audit

| Aspect | Finding |
|---|---|
| Storage | free text (`students.rollNo` / legacy `Roll No`) |
| Scope | School + Session + Class + Section (by convention; nothing enforces it) |
| Alphanumeric support | **Yes** — `A12`, `B-07`, `18A` preserved verbatim |
| Blank handling | `—` via the Teacher app's `formatRollNo()`; no fabricated value |
| Used as a key anywhere? | **No** |
| Used in any search haystack? | **No** — deliberately excluded (Fees, SIS, both Teacher searches) |
| Uniqueness enforced? | **No — none, anywhere** |
| Any module *assuming* uniqueness? | **No** |

**Evidence for the last two rows:** a repo-wide sweep for roll-keyed structures
(`[$rollNo] =`, `byRoll`, `rollNo => studentId`, `keyBy…roll`) returns **only field-mapping and
display sites** — `Homework.php:1708/1716`, `Sis.php:1243/6014`, `Entity_firestore_sync.php:329/469`.
No index, no map, no lookup keys on roll number.

## 10. Roll Number Business Decision — **DO NOT ENFORCE UNIQUENESS**

**Recommendation: do not introduce a uniqueness rule. Not implemented.**

The brief's own instruction — *"Do NOT blindly add uniqueness just because it sounds correct"* — is
what the evidence supports:

1. **No module needs it.** Nothing keys, joins, sorts-uniquely or looks up by roll number. Adding a
   constraint would protect an invariant nothing relies on.
2. **No module assumes it.** Promotion, attendance, marks, import and results all key on `studentId`.
3. **Legacy/alphanumeric data exists by design.** Phase 9.2 deliberately made bulk import accept
   free-text rolls. A uniqueness rule would need a normalisation policy (`A12` vs `a12` vs `A-12`)
   that does not exist and would be invented.
4. **Unknown duplicate population.** I did **not** query production to count existing duplicates —
   that is a read against live tenant data I was not authorized to perform. Enforcing without knowing
   the backlog risks blocking legitimate edits on day one.
5. **Migration cost is real, benefit is speculative.** Enforcement needs an atomic claim index
   (a fourth identity index alongside `srNoIndex`/`apaarIndex`/`admissionNoIndex`), plus defined
   behaviour on promotion, re-admission and import — substantial machinery for no current consumer.

**If you later decide to enforce it**, the only correct scope is
`School + Session + Class + Section + Roll`, never global or school-wide, and it should apply to
**new assignments only** with existing duplicates grandfathered. **Recorded as an open business
decision; no rule was invented** (§10 of the brief).

**What was verified instead** (the storage/display contract, tests H/I/J/K): values survive verbatim,
blank renders `—` and never `-2147483648` or `0`, and roll ≠ Student ID ≠ SRNO.

## 11. Roll Number Implementation — **none, by decision above**

## 12. Workstream F — Fee Receipt SRNO

### Receipt surface audit

| Template | Live? | Rendered by |
|---|---|---|
| `views/fees/receipt.php` | **YES — the fee-counter receipt** | `Fees::print_receipt()` |
| `views/admission/fee_receipt.php` | yes — **admission** fee receipt | `Admission_public.php:1963`, `Sis.php:7555` |
| `views/admission/receipt_pdf.php` | yes — admission PDF | `Admission_public.php:2024` |
| `views/fees_records.php`, `views/student_fees.php`, `fees/student_ledger.php` | listing/ledger, not receipts | — |

**Only `views/fees/receipt.php` was changed** — it is the school-facing fee-collection receipt this
workstream targets. The two admission receipts cover a different document (application/admission fee
at enquiry time, before a student record with an SRNO necessarily exists) and were left alone; called
out in §26.

### Two defects fixed

**(a) The mislabel — pre-existing and user-visible.** The template printed:

```php
<span class="lbl">Admission</span>
<span class="val"><?= htmlspecialchars($uid) ?></span>   // $uid = STU0012, the Student ID
```

Every printed receipt has shown the **Student ID** under an **"Admission"** heading. Student ID,
Admission Number and SR Number are three different identifiers (§13 of the brief); this row conflated
two of them.

**(b) SR Number absent.** Now printed, with Roll No.

### Implementation — zero reads

`srNo` and `rollNo` are denormalised onto the receipt document at write time, in **both** receipt
write paths in `FeeCollectionService` (`$batchReceiptData` and `$receiptDoc`). **This costs nothing:**
`$student` is already loaded and in scope there — the adjacent line already reads `fatherName` from it.

`print_receipt()` reads them straight off the receipt document.

**Legacy receipts:** handled by *widening the conditional student read that already existed* for
`fatherName`, rather than adding a second one. Worst case remains **one** read, skipped entirely for
any receipt carrying the fields. No N+1 — this is a single-receipt print path.

### Printed result

```
Student      Rahul Sharma
SR No.       47
Father       …
Class / Sec  5th / A
Roll No.     18
```

**Student ID is not printed.** It is still passed to the view and remains the canonical key on
`feeReceipts.studentId`, on allocations, on reconciliation and on the accounting linkage.

Rows render only when populated, so a school without SR Numbers sees the receipt exactly as before —
minus the mislabel.

## 13. Student ID Visibility Decision

**Hidden from the printed receipt; fully retained internally.**

| Layer | Student ID |
|---|---|
| Printed/PDF receipt | **not shown** |
| View data (`$user_id`) | still passed |
| `feeReceipts.studentId` | **canonical key — unchanged** |
| Demands / allocations / accounting / reconciliation | **unchanged** |
| Ownership & RBAC checks | **unchanged** |

No regulatory or technical requirement to print it was found: it is an internal surrogate key with no
meaning to a parent, while the school operates on the SR Number.

## 14. Cross-Module Validation

| Module | Identifies by | Persists | Changed this phase? |
|---|---|---|---|
| Fees counter / Browse | ID **or SRNO** | `studentId` | search cached (D2) |
| **Fee receipt** | already-resolved ID | `studentId` | **display only (F)** |
| SIS search / `get_student` | ID **or SRNO** | — | no |
| Bulk import | phone **+ name** | `studentId` | **dedup rule (B)** |
| Attendance | roster | `studentId` | **name backfill batched (D1)** |
| Marks / Results / Homework / Red Flags | roster | `studentId` | no |
| Transport / Hostel / Library | ID **or SRNO** | `studentId` | no |
| PTM | authenticated child | `studentId` + `srNo` denorm | no |
| Student 360 | `/{studentId}` | — | **APAAR masked (C)** |
| Parent App | authenticated | `studentId` | **status filter (A)** |

**Static proof:** `srNo` is never used as a document key or query predicate in `Fees.php` or
`FeeCollectionService.php` (grep for `['srNo','==']` / `docId($srNo)` → zero).

## 15. Cross-Platform Validation

- **Admin Panel** — receipt, Student 360, fee search, import, attendance log. `php -l` clean on all 6
  changed files.
- **Parent App** — one file changed (`findSiblings`). Compile result in §23.
- **Teacher App** — **not changed this phase.** Dirty count unchanged at 31.

No mobile architecture was modified, and no read was added to display any identifier.

## 16. Re-admission Validation

| Check | Result |
|---|---|
| `SRNO 47` → current Student ID after relink | **PASS** (test) |
| Historical Student ID never returned | **PASS** (test) |
| Historical trail retained for Student Trail | **PASS** (test) |
| Old record excluded from Parent child switcher | **FIXED this phase (A)** — device UAT pending |
| Old record excluded from Admin/Teacher | already correct (status filters) |

Fixtures are **crossed** — `STU0012 / SRNO 47` re-admitted to `STU0047` — so nothing in the suite
implies a numeric correspondence.

## 17. Promotion Validation

**PASS (test):** a student-document write mutating class/section/roll leaves `srNoIndex`
byte-identical and `resolve('47')` still returns `STU0012`. Student ID, SRNO and Admission Number
preserved; Class, Section and Roll may change.

## 18. Tenant Isolation Validation

**PASS.** School A `SRNO 47 → STU0012`, School B `SRNO 47 → STU0099`, over one shared backing store;
A cannot resolve B's SRNO. No global `srNo` query exists. No client-controlled `schoolId`. Nothing in
this phase adds a query or widens an RBAC gate.

## 19. Firestore Reads / Writes — Before & After

| Workflow | Reads before | Reads after | Writes before | Writes after | Seq. RTT before | after | In loop? |
|---|---:|---:|---:|---:|---:|---:|---|
| Parent child list | N (full scan) | N (full scan) | 0 | 0 | 1 | 1 | no |
| Bulk import — ordinary row | 0 | **0** | n | n | 0 | 0 | per row |
| Bulk import — phone-collision row | 0 | **+1** | n | n | 0 | +1 | per collision only |
| Import pre-commit scan — collision row | 1 | **+1** | 0 | 0 | 1 | 2 | per collision only |
| Student 360 APAAR | 0 | **0** | 0 | 0 | 0 | 0 | no |
| Attendance punch log (names present) | 0 | **0** | 0 | 0 | 0 | **0** | no |
| Attendance punch log (50 unnamed) | 50 | 50 | 0 | 0 | **50** | **2** | batched |
| Fee search (cache hit) | N | **0** | 0 | 0 | 1 | **0** | no |
| Fee search (cache miss) | N | N | 0 | 0 | 1 | 1 | no |
| Fee collection (receipt write) | n | **n** | n | **n** | — | — | no |
| Receipt print — new receipt | 3 | **3** | 0 | 0 | 3 | 3 | no |
| Receipt print — legacy receipt | 3–4 | **4** | 0 | 0 | 4 | 4 | no |

**Net:** two strict improvements (D1 round trips, D2 reads), one bounded increase (+1 read on
phone-collision import rows — the price of not losing sibling data), everything else unchanged.

## 20. N+1 Audit

| Pattern | Status |
|---|---|
| Attendance name backfill | **FIXED** — batched |
| Parent `findSiblings` full scan | pre-existing, unchanged (the A fix is a filter, not a query change) |
| Fees search full scan | **FIXED** — cached |
| SIB-B1 name corroboration | **not** an N+1 — one read per *collision*, not per row |
| Receipt legacy fallback | **not** an N+1 — single-receipt path, reuses an existing read |
| SRNO resolution | single doc-id fetch, outside any loop |

**No new N+1 introduced.**

## 21. Rules / Index Impact — **NONE**

Reconciled per §19: `integration/phase2` **has no remote counterpart** (remote heads:
`Academic_planner`, `aegis`, `ank-yug_b1`, `ank-yug_b1_clay_makeover`, `ankit/my-feature`, `main`,
`yug_b1_t`, `yug_testing`), and no upstream is configured — so there is nothing to pull or reconcile
against, and none was invented.

No rule or index change is required: no new collection, no new query shape (the `IN` batching queries
an existing collection on an existing equality-style field), and `srNo`/`rollNo` on `feeReceipts` are
ordinary fields on an existing document that nothing filters or orders by.

`firestore.rules` mtime **2026-08-13**, `firestore.indexes.json` mtime **2026-08-12** — both predate
this phase. **Untouched.**

## 22. Tests Added

`tests/Unit/Phase13LifecycleHardeningTest.php` — **16 tests, 54 assertions, all pass.**

| Brief ref | Coverage |
|---|---|
| L | crossed-pair invariant — `STU0012/SRNO 47` and `STU0047/SRNO 12` resolve independently |
| R | two schools both using SRNO 47 |
| B, T | re-admission resolves to the current Student ID |
| C | historical Student ID retained but never resolved |
| S | promotion preserves SRNO |
| **D, E** | **two siblings sharing a parent phone both survive import**; same student still deduped; case/whitespace normalised; phone alone never sufficient |
| F | APAAR masked to last 4; blank stays blank; garbage masked |
| H, I, J, K | roll verbatim; blank → `—` never `-2147483648`/`0`; scope key includes school+session+class+section; roll ≠ ID ≠ SRNO |
| M, N, O | receipt keeps `studentId` as key **and** carries `srNo`; printed block shows SR No. and omits Student ID; SR row absent when unset |

**Honestly stated coverage limits** (also written into the test file's docblock):

- **A (Parent status filter) is Kotlin** — covered by compile + UAT, not by this PHP suite.
- **B's controller flow needs a CI bootstrap** — the *decision rule* is modelled and tested exactly as
  implemented; the surrounding controller is covered by UAT.
- **C's `s360_mask_apaar()` is view-scope** and cannot be `require`d without CI — the pure function is
  duplicated in the test to assert the contract shape. This is a contract test, not execution of the
  view.
- **D1/D2 (U, V, W)** are read-count/round-trip properties requiring a live Firestore; verified by
  source analysis (§19) and listed in the UAT matrix, **not** asserted by an automated test.

## 23. Full Regression Results

| Check | Result |
|---|---|
| `php -l` — all 6 changed PHP files + test | **PASS** |
| `Phase13LifecycleHardeningTest` | **16/16 pass** |
| **Full unit suite** | **752 tests, 2407 assertions, 10 failures** |
| **Baseline comparison** | **10 = exact pre-existing baseline. Zero new failures.** (736 → 752 = +16, all mine, all passing) |
| Parent App `compileDebugKotlin` | **BUILD SUCCESSFUL** (3m 17s) |
| Teacher App | **not changed** — not rebuilt |
| Static: `srNo` used as a key | **0** |
| Static: RTDB introduced | **0** |
| Static: rules/indexes | **untouched** |

## 24. UAT Matrix — **NOT EXECUTED**

**No UAT PASS is claimed anywhere.** No authenticated browser session and no test account were
created. Both apps are installed on device `I2011`; the Admin Panel needs `localhost:8080`.

| # | Test | Expected | Status |
|---|---|---|---|
| 1 | Fee counter — Student ID | resolves, unchanged | **NOT RUN** |
| 2 | Fee counter — SRNO | `✓ SR No. 47 → STU0012 …` | **NOT RUN** |
| 3 | Collect fee via SRNO | succeeds | **NOT RUN** |
| 4 | Print receipt | renders | **NOT RUN** |
| 5 | **Receipt shows `SR No. 47`** | visible | **NOT RUN** |
| 6 | **Receipt does NOT show `STU0012`** | absent; no "Admission: STU…" row | **NOT RUN** |
| 7 | **`feeReceipts.studentId` still `STU0012`** | internal key intact | **NOT RUN** |
| 8 | Reprint receipt | SR No. persists | **NOT RUN** |
| 9–12 | Teacher: attendance / marks / homework / red flag save | all key on `studentId` | **NOT RUN** |
| 13 | Parent views child | identity fields correct | **NOT RUN** |
| 14 | Parent switches children | no stale identity | **NOT RUN** |
| 15 | **Re-admitted student appears ONCE** | **the A fix** | **NOT RUN** |
| 16–18 | Transport / Hostel / Library via SRNO | found, SR visible, posts `student_id` | **NOT RUN** |
| 19 | PTM booking | RSVP carries `srNo` | **NOT RUN** |
| 20 | Two schools, same SRNO 47 | independent | **NOT RUN** |
| 21 | **Import 2 siblings, same parent phone** | **both created** | **NOT RUN** |
| 22 | Re-import the same file | still deduped, no duplicates | **NOT RUN** |
| 23 | **Student 360 APAAR** | shows `XXXX XXXX 9012` | **NOT RUN** |
| 24 | APAAR reveal by authorized role | full value + audit entry | **NOT RUN** |
| 25 | Legacy receipt (pre-Phase-13) reprint | SR No. via fallback read | **NOT RUN** |
| 26 | Fee search after admitting a student | new student appears immediately (bust works) | **NOT RUN** |
| 27 | Attendance punch log with unnamed rows | names resolve; page not slow | **NOT RUN** |

**Rows 21, 22, 26 and 15 are the highest-value** — they exercise the data-loss fix, its inverse, the
cache-invalidation correctness, and the re-admission fix.

## 25. Remaining Blockers

**None introduced.** Pre-existing, out of scope, unchanged:

| ID | Class | Status |
|---|---|---|
| P11.3-N1 | ARCHITECTURE | Certificates RTDB (24 calls) — blocks SRNO there. Order: migrate → then SRNO. |
| N12-5 | ARCHITECTURE | LMS RTDB (68 calls) — same. Homework is fully Firestore and unaffected. |
| N12-4 | OPERATIONAL RISK | `integration/phase2` has no remote; all Phase 9→13 work is local-only and uncommitted. |
| P11.2-N3 | LOW | `Sr_no_service::init()` schoolId inconsistency — immaterial to `resolve()`. |
| Parent full scan | LATENCY | `findSiblings` still downloads every student per dashboard load. The A fix filters results; it does not change the query. **Not in this phase's scope.** |

## 26. Deferred Work

1. **Parent `findSiblings` full-collection scan** — the remaining half of N12-1's cost.
2. **Admission receipts** (`admission/fee_receipt.php`, `admission/receipt_pdf.php`) — a different
   document; SRNO may not exist at enquiry time. Needs its own decision.
3. **Roll Number uniqueness** — open business decision (§10).
4. **Certificates / LMS RTDB migrations**, then SRNO.
5. **Campus Access picker**, **student export** — from earlier phases.
6. `firestoreGetParallel()` is misnamed and sequential — worth either implementing真 parallelism or
   renaming, so the next engineer isn't misled as the brief was.

## 27. Git Safety

```
Branch: integration/phase2      HEAD: 758c924   (unchanged — nothing committed)
Remote: integration/phase2 does NOT exist on origin; no upstream configured.

Admin Panel   429 → 433 dirty  (+4)
    newly modified : application/controllers/Attendance.php
                     application/services/FeeCollectionService.php
    already dirty  : application/controllers/Sis.php, application/controllers/Fees.php,
                     application/views/fees/receipt.php
    already untracked (edited in place) : application/views/student360/workspace.php
    new untracked  : tests/Unit/Phase13LifecycleHardeningTest.php,
                     PHASE13_SRNO_LIFECYCLE_HARDENING_AND_RECEIPT_REPORT.md

Teacher App    31 dirty        (UNCHANGED — not touched this phase)
Parent App     22 → 23 dirty   (+1: StudentFirestoreRepository.kt, which was CLEAN before
                                this phase — it is the only Parent file changed)

firestore.rules        mtime 2026-08-13  — untouched
firestore.indexes.json mtime 2026-08-12  — untouched
```

No pre-existing work was overwritten or reverted. No unrelated file staged. **No commit, push, merge
or rebase.**

## 28. Final Verdict

# READY WITH CONDITIONS

| Stage | Status |
|---|---|
| **Implementation** | **COMPLETE** for all authorized workstreams (E intentionally not implemented) |
| **Static verification** | **COMPLETE** — lint, key-usage, RTDB, rules all clean |
| **Automated tests** | **COMPLETE** — 16 new, full suite at exact baseline |
| **Browser UAT** | **NOT EXECUTED** |
| **Device UAT** | **NOT EXECUTED** |
| **Deployment verification** | **NOT EXECUTED** — nothing deployed |

| Class | Finding |
|---|---|
| **CODE DEFECT** | **None introduced.** Two pre-existing ones fixed (SIB-B1 data loss; receipt mislabel). |
| **SECURITY DEFECT** | **None introduced.** P11-N1 **fixed** this phase. |
| **ARCHITECTURE BLOCKER** | Certificates + LMS RTDB — pre-existing, out of scope. |
| **PERFORMANCE** | **Two improvements** (D1, D2). One bounded +1 read on import collision rows. |
| **ENVIRONMENT LIMITATION** | No browser/device UAT; controller/view/Kotlin paths not unit-testable here. |
| **FUTURE ENHANCEMENT** | §26. |
| **PRE-EXISTING** | §25. |

**Not called READY because:** the receipt change, the SIB-B1 fix and the Parent re-admission fix are
all **user-visible** and **none has been seen running**. Rows 5, 6, 7, 15, 21, 22 of the UAT matrix
must pass first.

---

# WHAT THIS MEANS FOR A REAL SCHOOL

Meet Rahul. His records look like this:

```
Student ID    STU0012      ← ZenX's internal number. The parent never needs to see it.
SR No.        47           ← the school's own register number.
Roll No.      18           ← his number inside class 5-A.
Class         5-A
Admission No  2026/7K4P92  ← the admission event.
```

**These four are independent.** Rahul's Student ID is `STU0012` and his SR Number is `47` — the `12`
and the `47` have nothing to do with each other. Another child in the same school can have Student ID
`STU0047` and SR Number `12`. Both are perfectly normal. ZenX never guesses one from the other; it
always looks up the school's own register.

## A fee payment, start to finish

The parent comes to the counter and says **"SR Number 47."**

The clerk types `47`. The screen confirms:

> ✓ SR No. 47 → STU0012 · Rahul Sharma · Roll 18

The clerk can see *who* that register number belongs to before taking any money. The fee is
collected. **The printed receipt now reads:**

```
Student      Rahul Sharma
SR No.       47
Father       Mr Sharma
Class / Sec  5th / A
Roll No.     18
```

Two things changed here. The receipt now shows the **SR Number** the school actually uses. And it no
longer prints a row labelled **"Admission"** that was actually showing `STU0012` — the internal
number under the wrong heading. That was wrong on every receipt ever printed, and it's fixed.

`STU0012` is still stored on the receipt, the accounting entry and the reconciliation record. It's
just not on the paper any more, because it means nothing to a parent.

## The same student, everywhere else

- **Teacher App** — the class list shows `Roll: 18 · SR: 47`. The teacher never types anything. Marks,
  attendance, homework and red flags all save against `STU0012`.
- **Parent App** — Rahul's parent sees his Student ID, SR No., Admission No., Class and Roll.
- **Transport, hostel, library** — the clerk can search `47` and sees `SR 47` in the result before
  assigning a bus seat or a bed.

## Promotion

Rahul moves to 6-A and his roll changes to 7. **His Student ID and SR Number do not change.**
Searching `47` still finds him.

## Leaving and coming back — now fixed

Rahul leaves in March and re-joins in June. He gets a **new** internal ID (`STU0047`) and a **new**
Admission Number, but **keeps SR Number 47**, because 47 identifies the person.

Searching `47` correctly finds the **new** record. His old fee receipts stay attached to the old ID,
where they belong — the closed record never receives new fees, attendance or marks.

**What was broken:** his parent's app showed **two children, both named Rahul** — the real one and
the closed March record. Tapping the old one showed a frozen page with no fees and no attendance.
**Fixed this phase:** the app now ignores closed records. One Rahul.

## Two siblings — the data-loss bug, fixed

Rahul has a sister, Priya. They share their father's mobile number.

**What was broken:** importing both from one spreadsheet, ZenX saw the same phone twice and decided
Priya was a duplicate of Rahul. Depending on the setting it either **silently dropped Priya**, or
**overwrote Rahul's record with Priya's details**. A school importing 200 students could quietly lose
every second sibling.

**Fixed:** a shared phone is no longer proof by itself. ZenX now also compares the name. Rahul and
Priya have different names, so **both are imported**. Re-importing the same file still recognises the
same child and doesn't create duplicates.

## One privacy fix

Student 360 was showing the full APAAR number — a government ID — in plain text to anyone who could
open a student's page. It now shows `XXXX XXXX 9012`. Staff who genuinely need the full number still
get it through the existing approval route, which records who looked and when.

## Two speed fixes

The fee-counter search used to re-download **every student in the school on every search**. It now
reuses a shared, automatically-refreshed copy — and it still updates immediately when you admit or
remove a student.

The attendance punch log used to look up names one at a time. It now asks for thirty at once.

## What I have not done

I have **not** added a rule stopping two students in the same class from having the same roll number.
Nothing in ZenX relies on roll numbers being unique, some schools use values like `A12`, and I'd need
to know how many duplicates already exist before enforcing anything. **That's your call, and I did
not invent a rule.**

I also have **not** tested any of this in a browser or on a phone. The code is written, checked and
covered by automated tests, but nobody has yet watched a receipt print with SR No. 47 on it, or
imported two siblings and seen both appear. That's the remaining step.

**Nothing has been committed, pushed or deployed.**
