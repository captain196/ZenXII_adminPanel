# PHASE 20.3J — Import Atomic Capacity Grouping

**Status:** **STOPPED — Import NOT wired · NO CODE CHANGED**
**4/5 paths wired · flag FALSE and LOCKED · P20.2-RACE-1 REMAINS OPEN**
**NOT COMMITTED · NOT PUSHED · NOT DEPLOYED · UAT NOT EXECUTED**
**Date:** 2026-08-16 · **Branch:** `integration/phase2` @ `758c924`

---

## 1. Executive summary

I traced `_runStudentImport()` as instructed and stopped before restructuring it. **No file was
changed in this phase.**

The blocker is not that import *cannot* be grouped — the design is sound and unchanged. It is that
the refactor is materially larger than the previous estimate, and I did not have the working budget
to complete, test and verify it in one run. A half-restructured import loop is the single most
dangerous state available here, and §22 lists exactly that outcome as a stop condition.

### What the trace actually measured

| Property | Measured |
| --- | --- |
| Row loop | `:5530` → `:6210` — **680 lines** |
| Post-write block (must become replayable) | `:5841` → `:6210` — **~370 lines** |
| Distinct local variables the post-write block closes over | **30+** |
| Rollback branches inside it | 4 (`:5847`, `:5869`, `:5897`, `:5933`) |
| Downstream work embedded in it | SRNO claim, Admission No, fee lifecycle (`:6019`), follow-up sync (`:6075`), history/audit |

Phase 20.3i described this as "three passes". That is still the right shape, but the middle of it —
turning a 370-line inline block with 30+ closed-over locals into something replayable **after** a
group commit — is the actual work, and it is where per-row error reporting (`$skipped[]`, `$error`,
`$success`, `$duplicates`) can most easily be broken silently.

**Honest wording:** this is a *budget* stop, not a technical impossibility. I am not reporting import
as un-groupable.

---

## 2. Worktree state

| Item | Start | End |
| --- | --- | --- |
| Branch / HEAD | `integration/phase2` @ `758c924` | unchanged |
| Staged / stashes | 4 / 3 | **4 / 3** |
| Files changed this phase | — | **none** |

**Protected files — all five byte-identical:** `daf5f2f1` · `c9eff0ca` · `73652264` · `b597cee2` ·
`14190666`. Rules/indexes not opened. P20-F14 out of scope.

*(`Sis.php` shows a large diff against HEAD — that is pre-existing uncommitted work from earlier
phases plus the 20.3h admission/enroll wiring, not new edits from this phase.)*

---

## 3. Verification

| Check | Result |
| --- | --- |
| Full suite | **1605 tests, 5039 assertions, 10 failures** |
| Baseline failures | **10** · New failures **0** · Total **10** |
| Flag | `atomic_capacity_enabled = false` |
| Wiring lock | active — names **import** as the only gap |
| Protected files | all five byte-identical |
| Rules / indexes | untouched |
| Live identities consumed | **0** |

**Not calling the suite green — the same 10 pre-existing failures remain.**

---

## 4. Required declarations (Part 21)

| # | Question | Answer |
| --: | --- | --- |
| 1 | Admission wired? | **Yes** |
| 2 | Enroll wired? | **Yes** |
| 3 | **Import wired?** | **No** |
| 4 | Promotion wired? | **Yes** |
| 5 | Transfer wired? | **Yes** |
| 6 | All five detected by the lock? | **No — 4/5; lock names import** |
| 7 | Flag FALSE? | **Yes** |
| 8 | P20.2-RACE-1 still open? | **Yes** |
| 9 | Import destination-section grouped? | **No — not implemented** |
| 10 | One capacity decision per destination group? | Yes for transfer/promotion; **not for import** |
| 11 | 499 + marker enforced? | **Yes** (guard) |
| 12 | Chunking absent? | **Yes** |
| 13 | Occupancy solely from student documents? | **Yes** |
| 14 | CAS conflict safe? | **Yes — refuse, nothing written** |
| 15 | Generic failure safe? | **Yes — refuse, never retried** |
| 16 | Identity generation preserved? | **Yes — untouched** |
| 17 | Can capacity refusal create an orphan? | **Not on the 4 wired paths.** Unassessed for import until implemented |
| 18 | Can CAS refusal create an orphan? | **No** — the batch rolls back entirely |
| 19 | Can a group partially commit? | **No** |
| 20 | Import per-row result contract preserved? | **Unchanged — import untouched** |
| 21 | Downstream fee/history preserved? | **Unchanged** |
| 22 | Protected files changed? | **No** |
| 23 | Rules/indexes changed? | **No** |
| 24 | Live identities consumed? | **0** |
| 25 | Live UAT executed? | **No** — out of scope this phase |
| 26 | Browser tests executed? | **No** — BROWSER UAT NOT EXECUTED |
| 27 | Tests now | **1605** |
| 28 | Baseline failures | **10** |
| 29 | New failures | **0** |
| 30 | Committed? | **No** |
| 31 | Pushed? | **No** |
| 32 | Deployed? | **No** |

---

## 5. The design, unchanged and ready to build

**Pass 1 — validate, prepare, buffer.** Run the existing loop body up to and including Student-ID
mint. Keep the existing per-row `$seatsUsed + 1` filter so overflow rows still fail individually and
the current error contract survives. Instead of calling `saveStudent()`, buffer
`['studentId', 'data', rowContext]` into `$groups[$destinationSectionDocId][]`.

**Pass 2 — one atomic commit per destination.** For each group:
`placeStudentGroup()` → 1 section read + 1 commit of N student writes + 1 CAS marker; 499 cap;
refusal marks **every row in that group** failed with a category
(`CAPACITY` / `CAS_CONFLICT` / `INFRASTRUCTURE`) distinct from `VALIDATION`.

**Pass 3 — replay post-commit work.** For each student in a *committed* group, run the extracted
post-write block: SRNO claim, Admission No, existing rollback (`deleteStudent` + `releaseClaim`),
follow-up sync, fee lifecycle, history.

**The real task is Pass 3's extraction.** Recommended approach: extract `:5841`–`:6210` into a
private method taking an explicit context array (the 30+ locals become named keys) and returning a
per-row outcome, then have the flag-off path call it inline immediately after `saveStudent()` so
**flag-off behaviour is provably identical by construction** — the same method, the same order.
Land that extraction as its own step, verify no behaviour change with the flag off, and only then
add the buffering.

That sequencing is what makes this safe, and it is why it wants a full budget rather than the tail
of one.

---

## 6. Remaining risks

| Risk | Rank | State |
| --- | --- | --- |
| **P20.2-RACE-1** | **HIGH** | **OPEN** |
| Import unwired | **HIGH** | flag locked off; lock names it |
| Flag-off equivalence not behaviourally regression-tested | MEDIUM | inspection/lint only on the 4 wired paths |
| PHP REST-path FAILED_PRECONDITION unconfirmed | MEDIUM | must be observed in the first placement UAT |
| P20-F14 `firestore.rules` | MEDIUM | out of scope, untouched |

---

## 7. Exact next phase

**Phase 20.3k — import grouping, in two steps within one budget:**

1. **Extract** the post-write block into a private replayable method; call it inline (flag off);
   prove zero behaviour change.
2. **Buffer + group commit** behind the flag; per-row failure categories; update the detector marker
   (zero pre-existing occurrences, structurally tied to the guard call); prove the lock catches an
   unwired import before finalising, then prove all five detected.
3. Tests IMPORT-1…17 + FLAG-1…3.
4. Then, separately: five-path live UAT on `SCH_B56BB9A401`, including the **first PHP REST-path CAS
   conflict observation**, before any flag flip.

**Not started:** bulk roll, legacy retirement, Attendance, Examination, Library, Fees, ID Cards,
Transport, Student Charge Module.

---

```
PHASE 20.3J — STOPPED · IMPORT NOT WIRED · NO CODE CHANGED
4/5 PATHS WIRED · FLAG FALSE AND LOCKED
P20.2-RACE-1 REMAINS OPEN
NOT COMMITTED · NOT PUSHED · NOT DEPLOYED · UAT NOT EXECUTED
```
