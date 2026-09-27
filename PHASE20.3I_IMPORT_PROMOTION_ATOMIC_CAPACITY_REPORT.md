# PHASE 20.3I — Import + Promotion Atomic Capacity Integration

**Status:** **PARTIAL — Promotion WIRED (4/5) · Import NOT wired · flag FALSE and LOCKED**
**P20.2-RACE-1 REMAINS OPEN**
**NOT COMMITTED · NOT PUSHED · NOT DEPLOYED · UAT NOT EXECUTED**
**Date:** 2026-08-16 · **Branch:** `integration/phase2` @ `758c924` · **Tenant:** `SCH_B56BB9A401`

---

## 1. Executive summary

**Promotion is wired.** Import is not. The flag stays `false`, and the lock now names import as the
single remaining gap.

| Path | Boundary | Wired |
| --- | --- | :-: |
| 1 Admission | `syncStudent` `Sis:1014` | ✅ |
| 2 Enroll | `saveStudent` `Sis:9856` | ✅ |
| 4 **Promotion** | **`batchMoveStudents` — one atomic destination group** | ✅ **new** |
| 5 Transfer | `Classes:852` — one atomic destination group | ✅ |
| 3 Import execute | `saveStudent` `Sis:5841` | ❌ |

### Promotion turned out simpler than forecast

Phase 20.3h predicted promotion would need per-destination grouping inside `batchMoveStudents()`.
Source tracing showed that is unnecessary: the method takes a **single** `$toClass` / `$toSection`
for the whole call, so **the batch already IS one destination group** — the same shape as transfer.

One section read, one atomic commit of N student writes + 1 CAS marker. No per-student section read,
no N+1, no per-student CAS loop.

### Import remains, and why

Its row loop interleaves, per row: capacity check (`:5657`) → SRNO pre-validation (`:5762`) →
Student-ID mint (`:5780`) → **`saveStudent()` (`:5841`)** → four distinct rollback branches
(`:5847`, `:5869`, `:5897`, `:5933`) → Admission-No allocation (`:5887`/`:5909`) → follow-up sync
(`:6075`) → downstream fee and history work.

Grouping requires splitting that into three passes — *validate + mint + buffer by destination* →
*commit one atomic group per destination* → *per-student SRNO claim, rollback and downstream* — while
preserving the per-row error contract the import preview depends on. I did not start that
restructure without the budget to finish and verify it: a half-restructured import loop is
considerably worse than a locked flag.

---

## 2. Worktree state

| Item | Start | End |
| --- | --- | --- |
| Branch / HEAD | `integration/phase2` @ `758c924` | unchanged |
| Staged / stashes | 4 / 3 | **4 / 3** |

**Protected files — all five byte-identical:** `daf5f2f1` · `c9eff0ca` · `73652264` · `b597cee2` ·
`14190666`. Rules and indexes **not opened**; P20-F14 out of scope.

**Files changed this phase:** `application/libraries/Student_write_service.php` (promotion group) ·
`tests/Unit/Phase203hAtomicCapacityWiringTest.php` (detector + promotion atomicity tests) · this
report.

---

## 3. Promotion implementation

```
if (Section_seat_guard::enabled()) {
    build one group for ($toClass, $toSection)
    placeStudentGroup(...)          // 1 section read + 1 atomic commit
    PLACED  → result['moved']  = every id
    refused → result['failed'] = every id
    return
}
// otherwise the pre-existing per-student loop, untouched
```

**Atomicity is GROUP-ATOMIC, and here the group is the whole operation** because the method has a
single destination. If the destination section moved since it was read, Firestore rolls the entire
batch back and **not one student is promoted**.

**Result contract preserved.** A refusal puts every id in `failed[]` — exactly what
`Sis::execute_promotion()` already renders back to the operator as `skipped[]` (the BUG-054/055
contract). The operator is never told a student was promoted when the commit did not happen.
Asserted by test.

**Identity:** promotion mints nothing — no SRNO, Student ID or Admission Number (Phase 20.3e-1,
re-confirmed). A refusal leaves source students untouched.

**Flag off:** the pre-existing loop runs unchanged, including its `writeToFirestore` idempotency-key
path and per-student `failed[]` classification.

---

## 4. The lock — still the safety net

Re-verified this phase by temporarily setting the flag true:

```
atomic_capacity_enabled is TRUE but these placement paths are NOT wired: import.
Enabling the flag now would leave real capacity bypasses while the code reads as
protected — the failure that caused Phase 20.3c to be reverted.
```

Restored to `false` immediately. The detector was updated to look for promotion in
**`Student_write_service.php`**, not `Sis.php` — promotion's placement write lives in that method,
and pointing the detector at the wrong file would have reported an unwired path as protected.

---

## 5. Tests

14 tests / 44 assertions passing, including two new promotion-atomicity assertions (every id to
`moved[]` on success, every id to `failed[]` on refusal).

| Run | Tests | Assertions | Failures |
| --- | ---: | ---: | ---: |
| Phase 20.3h baseline | 1604 | 5037 | **10** |
| **Phase 20.3i** | **1605** | **5039** | **10** |

**Baseline failures: 10. New failures: 0. Total: 10.** Identical, failure-for-failure. **Not calling
the suite green.**

`php -l` clean on all four modified PHP files.

> **Not written:** the behavioural import/promotion suites (cases 1–26 of the required list). They
> depend on import grouping and, for several, on live Firestore rather than unit mocks.

---

## 6. Live Firestore UAT

> ## UAT NOT EXECUTED this phase.

Cases A–I are placement-level and require **all five** paths wired; running them at 4/5 would prove
nothing about the guarantee and would consume real identities for an incomplete mechanism.

Phase 20.3g's CAS-primitive result stands (H1 success, H2 `9 FAILED_PRECONDITION`, two in-flight
writers → winners=1/losers=1 on `SCH_B56BB9A401`).

**No mutation of the testing school this phase** — no student created or deleted, no section changed.

> ## BROWSER UAT NOT EXECUTED — no browser access.
> ## PHP REST-PATH CAS CONFLICT NOT YET OBSERVED — still pending the first placement UAT.

---

## 7. Required declarations (Part 25)

| # | Question | Answer |
| --: | --- | --- |
| 1 | Admission wired? | **Yes** |
| 2 | Enroll wired? | **Yes** |
| 3 | Import wired? | **No** |
| 4 | Promotion wired? | **Yes** |
| 5 | Transfer wired? | **Yes** |
| 6 | All five recognised by the lock? | **No — 4 of 5; lock names import** |
| 7 | Flag still FALSE? | **Yes** |
| 8 | P20.2-RACE-1 fixed? | **No — OPEN** |
| 9 | Can placement bypass the authority? | **Yes — import; and all paths while the flag is off** |
| 10 | Occupancy solely from student documents? | **Yes** — asserted |
| 11 | Bulk grouping destination-section based? | **Yes** — transfer and promotion |
| 12 | 499 + marker enforced? | **Yes** |
| 13 | Chunking absent? | **Yes** — `array_chunk`/`array_slice` asserted absent |
| 14 | CAS conflict handled safely? | **Yes — refuse, nothing written** |
| 15 | Infrastructure failure handled safely? | **Yes — refuse, never retried** |
| 16 | Identity generation preserved? | **Yes — untouched** |
| 17 | SRNOs safe? | **Yes** |
| 18 | Orphan student documents? | **None** |
| 19 | Partial bulk commits? | **None possible** — Firestore rolls the batch back |
| 20 | PHP REST-path CAS conflict observed? | **No** |
| 21 | Live Import UAT? | **Not executed** |
| 22 | Live Promotion UAT? | **Not executed** |
| 23 | Concurrency UAT? | Not this phase; 20.3g primitive result stands |
| 24 | Identities consumed? | **0** SRNO / **0** Student ID / **0** Admission No / **0** APAAR |
| 25 | Test data created? | **None** |
| 26 | Test data cleaned? | **N/A** |
| 27 | Browser tests executed? | **No** |
| 28 | Protected files changed? | **No** — all five byte-identical |
| 29 | Rules/indexes changed? | **No** |
| 30 | New test failures? | **No** — 10 pre-existing only |
| 31 | Committed? | **No** |
| 32 | Pushed? | **No** |
| 33 | Deployed? | **No** |

---

## 8. Remaining risks

| Risk | Rank | State |
| --- | --- | --- |
| **P20.2-RACE-1** | **HIGH** | **OPEN** |
| Import unwired | **HIGH** | flag locked off; lock names it |
| Flag-off equivalence not behaviourally regression-tested | MEDIUM | every guard call sits inside `if (enabled())`; verified by inspection and lint only |
| PHP REST-path FAILED_PRECONDITION unconfirmed | MEDIUM | must be observed in the first placement UAT before the refusal semantics are trusted |
| P20-F14 `firestore.rules` | MEDIUM | out of scope, untouched |

---

## 9. Exact next phase

**Phase 20.3j — import grouping only.**

1. Split `_runStudentImport()`'s row loop into three passes:
   **(a)** validate + SRNO pre-check + mint Student ID + build payload + buffer by destination —
   keeping the existing per-row `$seatsUsed` filter so overflow rows still fail individually and the
   existing error contract survives;
   **(b)** one atomic `placeStudentGroup()` per destination section;
   **(c)** per committed student: SRNO claim, Admission No, rollback (`deleteStudent` +
   `releaseClaim`), follow-up sync, fee and history.
2. Distinguish VALIDATION / CAPACITY / CAS-CONFLICT / INFRASTRUCTURE failures per row.
3. Update the detector marker (`'import_execute'`), which releases the lock.
4. Behavioural suites for import and promotion + flag-off equivalence regression.
5. Live cases A–I on `SCH_B56BB9A401` with before/after snapshots and identity accounting —
   **including the first PHP REST-path CAS conflict observation.**
6. Report; only then consider enabling the flag under separate authorization.

**Not started:** bulk roll, legacy retirement, Attendance, Examination, Library, Fees, ID Cards,
Transport, Student Charge Module.

---

```
PHASE 20.3I — PROMOTION WIRED (4/5) · IMPORT OUTSTANDING
FLAG FALSE AND LOCKED · P20.2-RACE-1 REMAINS OPEN
NOT COMMITTED · NOT PUSHED · NOT DEPLOYED · UAT NOT EXECUTED
```
