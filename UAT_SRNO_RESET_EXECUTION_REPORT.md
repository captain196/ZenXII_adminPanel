# UAT SRNO THREE-STRUCTURE RESET — EXECUTION REPORT

**COMPLETE AND INDEPENDENTLY VERIFIED. 33 deletions, 0 writes, 0 updates.**
**Next genuine allocation is deterministically SRNO 1 — and SRNO 1 was NOT consumed.**
Tenant `SCH_B56BB9A401` only · 2026-08-17

---

## PRE-RESET

Snapshot captured read-only before any deletion (`UAT_SRNO_RESET_SNAPSHOT.json`):

```
tenant .................. SCH_B56BB9A401
live students ........... 23        students with srNo ... 0
srNoIndex present ....... N = 1…16
claims present .......... N = 1…16
pointer ................. value = 16
feeDemands 26 · feeReceipts 0 · scholarshipAwards 0 · studentEntitlements 0 · admissions 0
```

Preconditions gate: 16 claims / 16 index docs / pointer == 16 / 23 students with 0 `srNo` — **PASS**.

## Step 1 — 16 claim documents deleted

`feeCounters/_sys_SRNO_SCH_B56BB9A401_claim_1` … `_claim_16`

**Verify after step 1:** claims 1–16 absent **PASS** · srNoIndex 1–16 still present **PASS** ·
pointer still 16 **PASS**

## Step 2 — 16 srNoIndex documents deleted

`srNoIndex/SCH_B56BB9A401_1` … `_16`

**Verify after step 2:** claims absent **PASS** · srNoIndex 1–16 absent **PASS** ·
pointer still 16 **PASS**

## Hard guard — before the pointer

```
claim documents gone ... CONFIRMED
srNoIndex ids gone ..... CONFIRMED
tenant srNoIndex query . 0 docs
HARD GUARD: PASS — the pointer may be deleted.
```

The guard re-read live state rather than trusting the step-2 result. Had either structure survived,
it would have refused step 3 — the deletion that would otherwise brick allocation.

## Step 3 — pointer deleted

`feeCounters/_sys_SRNO_SCH_B56BB9A401`

**Verify after step 3:** claims absent **PASS** · srNoIndex empty **PASS** · pointer absent **PASS**

**Deletions performed: 33 / 33 — RECONCILES.**

---

## POST-RESET — independent verification, fresh process

`scripts/uat_srno_reset_postverify.php`, a separate script and process from the reset tool. **Every
check PASS:**

| Area | Result |
| --- | --- |
| `srNoIndex` for tenant | **0** |
| `srNoIndex` platform-wide | **0** (was 16) |
| SRNO pointer | **ABSENT** |
| `claim_1` … `claim_25` | **all ABSENT** |
| student count | **23**, identical id set to baseline |
| students with `srNo` | **0** — unchanged |
| `feeDemands` | **26** (all STU0012) — unchanged |
| `feeReceipts` / `scholarshipAwards` / `studentEntitlements` / `admissions` | 0 / 0 / 0 / 0 — unchanged |
| other tenants holding `srNoIndex` or SRNO counters | **0** |
| `SCH_218AAF5C23` feeDemands | **1,118** — untouched |
| `schoolId`-less feeDemands | **98** — untouched |
| reconciliation | 16 + 16 + 1 = **33**, no unexpected deletion |

## READ-ONLY SRNO-1 PROOF — `allocateNext()` was never called

Five source assertions against `Sr_no_service.php`, all **PASS**: `candidate = pointer + 1`; the
pointer read falls back to `_findMaxExistingSrNoForSchool()` when the doc is absent; that scan returns
0 for a school with no rows; it writes a pointer **only** when `max > 0`; candidate N is reserved by
create-if-not-exists on `claim_{N}`.

```
pointer doc ABSENT ........... _readSrNoCounterPointer() falls through
srNoIndex has 0 rows ......... _findMaxExistingSrNoForSchool() returns 0
max = 0, so NO pointer written  returns 0 — identical to a brand-new school
therefore pointer = 0 ........ candidate = 0 + 1 = 1
claim_1 ABSENT ............... firestoreCreate(claim_1) SUCCEEDS
srNoIndex/..._1 ABSENT ....... claim() finds no duplicate
                          ==>  NEXT ALLOCATION = SRNO 1
```

**SRNO 1 remains unconsumed.** No synthetic allocation, no fake student, no manual assignment. The
first genuine UAT admission will consume it naturally — that admission is the real end-to-end
validation, and I have not performed it.

---

## The 12 required confirmations

1. **Exactly 33 deletions** — 16 claims + 16 `srNoIndex` + 1 pointer, each an explicitly named,
   tenant-prefixed doc id. No wildcard, no collection sweep, no query-driven delete: the script has a
   single `deleteDocument` call site fed only from the two literal id arrays.
2. **Verification ran after every step**, and the hard guard re-read live state before step 3.
3. **Final allocator state:** `srNoIndex` 0 · pointer ABSENT · claims ABSENT — identical to a school
   that has never allocated an SRNO.
4. **23 students unchanged** — same count, same id set as the pre-reset snapshot.
5. **All 23 still have no `srNo`** — `withSrNo = 0`. No backfill, no student document modified.
6. **Dependent collections unchanged** — `feeDemands` 26, receipts/awards/entitlements/admissions 0.
7. **No other tenant touched** — `SCH_218AAF5C23` 1,118 and the 98 `schoolId`-less demands intact;
   no other tenant holds any SRNO structure.
8. **No rules or indexes changed by me.** See observation (b) below.
9. **No commit, push, deploy, merge, or rebase.** HEAD is still `758c924` on `integration/phase2`,
   0 new commits.
10. **No live allocation test executed** — `allocateNext()` was never called by any script in this run.
11. **Next genuine allocation is deterministically SRNO 1**, proven from live state + source.
12. **Unexpected observations — two, both harmless:**

   **(a)** `srNoIndex` is now **empty platform-wide** (16 → 0), because this UAT tenant held every
   SRNO document in the entire system. No real school had any SRNO state to begin with, which is why
   blast radius was structurally zero — but it also means the collection existing at all is currently
   an artifact of UAT. Nothing to act on; worth knowing before anyone reads "0 docs" as data loss.

   **(b)** The `firestore.rules` working-tree digest is now **`5b0f86fc`**, not the `f58499f8` you
   quoted — the concurrent Staff-Lifecycle/HRMS session has moved it again (the fifth move I've seen:
   `4f9ed6e1` → `3d835a56` → `ae0da664` → `b2e78398` → `f58499f8` → `5b0f86fc`). **I did not touch it**
   — this run edited only `scripts/` and report files, and the SRNO reset requires no rules change at
   all. Flagging it because your instruction pinned a baseline that has since drifted underneath us.

---

## STOPPED

Per §14, work stops here. **Not done and not started:** the first genuine admission · any
`allocateNext()` call · SRNO 1 assignment · SRNO-F2 · SRNO-F3 · B3 · the N+1 hoist · scholarship,
entitlement, Student Lifecycle or Staff Lifecycle changes · any mass backfill.

`USE_ENTITLEMENT_BILLING = false` · `REAL_ADMISSION_READY = FALSE` · no RTDB · no commit, push, or
deploy. The legacy model stands: **legacy students may remain without SRNO; new admissions require
one (SRNO-F1 fail-closed); legacy assignment is a separate operator-driven future design.** SRNO
remains **school-scoped and session-independent** — `school + SRNO → student`.

Awaiting your next instruction.
