# PHASE 20.3d — Atomic Capacity: Bulk Placement Batching

**Status:** **BLOCKED at Part 8 (identity hard gate) — NO CODE CHANGED**
**P20.2-RACE-1 REMAINS OPEN · 0 of 5 paths wired**
**NOT COMMITTED · NOT PUSHED · NOT DEPLOYED · UAT NOT EXECUTED**
**Date:** 2026-08-16 · **Branch:** `integration/phase2` @ `758c924`

---

## 1. Executive summary

Part 8 is a **hard gate**: *"Before implementing retries or batching, trace identity timing for EVERY
path… If any path cannot satisfy these conditions, STOP that path rather than weakening the
guarantee."*

I completed that trace for all five paths. **Two of them fail it**, and they fail in a way that
matters: wiring them as designed would introduce an identity leak that does not exist today.

| Path | Mints identity? | SRNO claim vs student write | Refusal-safe? |
| --- | :-: | --- | :-: |
| **Admission** | Student ID `:760` | SRNO/APAAR `:1025` — **AFTER** write `:938` | ✅ **SAFE** |
| **Promotion** | **none** | n/a — moves existing students | ✅ **SAFE** |
| **Transfer** | **none** | n/a — moves existing students | ✅ **SAFE** |
| **Enroll** | Student ID `:9656` | SRNO `:10037` — **BEFORE** write `:10041` | ❌ **ORPHANS SRNO** |
| **Import execute** | Student ID `:5774`/row | SRNO `:5861` / `:5889` — **BEFORE** write `:6069` | ❌ **ORPHANS SRNO** |

Under the approved conflict→refuse policy, a CAS refusal on **enroll** or **import** would leave an
SRNO already claimed against a student document that was never written — and every operator resubmit
would burn another. Today those writes simply succeed (over capacity), so no orphan occurs. **The fix
would introduce the leak.**

That is exactly what Part 8 says to stop for, so I stopped. **No file was modified in this phase.**

**Good news that was not previously established:** promotion and transfer — the two most complex bulk
paths — **mint no identity at all**. Verified: zero occurrences of `_nextStudentId`,
`_claimIdentityAttributes` or `srnoSvc` in either. They are inherently refusal- and retry-safe, which
removes the largest risk from the bulk work.

---

## 2. Worktree safety (Part 0)

| Item | Start | End |
| --- | --- | --- |
| Branch / HEAD | `integration/phase2` @ `758c924` | unchanged |
| Staged / stashes | 4 / 3 | **4 / 3** |
| Modified / untracked | 120 / 498 | **120 / 498** |

No reset, clean, checkout, stash, rebase or revert. **No file created, modified or deleted.**

**Protected files — all five byte-identical:**
`daf5f2f1` MY_Controller · `c9eff0ca` Firestore_service · `73652264` rbac_helper ·
`b597cee2` firestore.rules · `14190666` firestore.indexes.json

`firestore.rules` not opened; **P20-F14 untouched and still open**, as instructed.

*(The dirty `Sis.php` / `Classes.php` / `Entity_firestore_sync.php` / `Section_seat_guard.php` entries
are pre-existing work from Phases 20.2–20.3c, not new edits.)*

---

## 3. Part 1 re-verification — 20.3c assumptions independently re-checked

| # | Assumption | Verdict |
| --- | --- | :-: |
| A | `Section_seat_guard` exists, inert | ✅ present, no callers |
| B | `commitBatchStatus()` classifies SUCCESS / PRECONDITION_CONFLICT / FAILURE | ✅ present |
| C | `commitBatch` supports `currentDocument.updateTime`, rolls back atomically | ✅ `Firestore_rest_client:1103-1105`, documented at `:1085-1090` |
| D | `buildStudentDoc()` exists, inert | ✅ 3 references |
| E | Occupancy derives from students via `_filterSectionRoster` | ✅ unchanged |
| F | Capacity enforced via `_sectionCapacityExceeded` | ✅ unchanged |
| G | Promotion writes via `dw->batchMoveStudents()` | ✅ confirmed |
| H | Import writes per row at `:6069` | ✅ confirmed |
| I | Transfer writes via `fs->updateEntity` `Classes:852` | ✅ confirmed |
| J | Admission writes via `syncStudent` `:938` | ✅ confirmed |
| K | Enroll writes via `syncStudent` `:10041` | ✅ confirmed |

**No 20.3c assumption was found wrong.** One was found *incomplete*: the report treated identity
timing as established from admission alone. It is not uniform across paths — §1 above.

---

## 4. The blocker in detail (Part 8, Part 19 condition 2)

### Admission — safe (already known)
```
:667  capacity check
:760  Student ID minted
:938  student write          ← CAS guard would sit here
:1025 SRNO + APAAR claimed   ← never reached on refusal
```
A refusal returns before `:1025`. No SRNO is claimed for a placement that did not happen.

### Enroll — NOT safe
```
:9656  Student ID minted
:9728  capacity check
:10037 SRNO claimed          ← happens FIRST
:10041 student write         ← CAS guard would sit here
```
A refusal at `:10041` leaves the SRNO from `:10037` claimed against a student that does not exist.

### Import execute — NOT safe (per row)
```
:5651 capacity check (with $seatsUsed running tally)
:5774 Student ID minted
:5861 srnoSvc->claim()   /  :5889 srnoSvc->allocateNext()   ← happens FIRST
:6069 student write                                         ← CAS guard would sit here
```
Same shape, once per row. A conflicting import could orphan many SRNOs in one operation.

### Why this is not merely cosmetic

The Part 8 hard requirements name *duplicates*, and an orphan is not a duplicate — so a narrow
reading would let this pass. It should not:

- SRNO is **permanent school-scoped identity**. Burning numbers on refused placements makes the
  sequence lie about how many students the school has ever admitted.
- It is **self-amplifying**: the operator's natural response to a refusal is to resubmit, orphaning
  another SRNO each time.
- It would be a **new** defect introduced by the fix. Today these paths do not refuse, so they do not
  orphan.

---

## 5. The remedy (designed, NOT implemented — needs authorization)

Reorder enroll and import so the SRNO claim happens **after** the guarded write, matching admission —
which already proves the ordering works and is the canonical shape.

**Why I did not just do it:** it changes SRNO claim ordering on two live paths. Standing project rules
treat SRNO as permanent identity requiring explicit care, and Part 5/Part 18 forbid altering import's
SRNO behaviour as a side effect of this phase. Import additionally interleaves the claim with row
validation and duplicate handling (`srnoSvc->resolve()` at `:5756` pre-checks a supplied SR number),
so the reorder is not a simple line move — it must preserve the "SR number supplied in the CSV is
validated before the row is accepted" contract.

**This is a request for authorization, not a refusal.** The remedy is well-defined and small in
surface area; it is simply not mine to make unilaterally on SRNO.

---

## 6. Bulk grouping design (Part 3 / Part 13) — designed, NOT implemented

Unchanged from 20.3c's recommendation and still correct:

```
students → GROUP BY destination section → per group:
    1 section read (captures __updateTime)
    1 occupancy derivation
    validate: occupancy + |group| <= capacity
    1 atomic commit: |group| student writes + 1 section CAS marker
```

60 students into one section ⇒ **1 read + 1 commit of 61 writes**, not 60 reads and 60 commits.

### Firestore write-limit analysis (Part 13)

Commit limit is **500 writes**. A group is `N + 1`. `N` is bounded by
`capacity − occupancy`, and `save_section_capacity()` already caps capacity at **500**
(Phase 20.2), so `N ≤ 500` and the worst case is `501` — **one over**.

**Resolution:** cap a group at **499 students + 1 marker**. A larger group is refused with a clear
message rather than chunked, because chunking would reopen the race (Part 13 forbids it): after
chunk 1 commits, the section's `updateTime` moves and chunk 2's captured precondition is stale, so a
competing request could interleave between chunks.

This edge case is unreachable in practice — it needs a single section with capacity 500 and ≥500
students arriving in one operation — but it must be handled explicitly rather than silently exceeding
the limit.

---

## 7. Conflict / retry policy (Part 9) — evaluated

| Option | Verdict |
| --- | --- |
| **A — conflict → refuse, operator resubmits** | **Preferred.** Guarantees no over-capacity, no duplicate identity, no partial placement. Requires no protected-file change. |
| B — minimal memo refresh in `MY_Controller` | Not needed for correctness. Only improves UX under rare contention. |

Option A is sufficient for all three guarantees, so per Part 9 it is preferred over touching protected
infrastructure — **provided** the SRNO ordering in §4 is fixed first, since refusal is precisely the
path that orphans identity today.

---

## 8. Status against the closure checklist (Part 20)

```
[ ] all five paths participate                    0 of 5
[x] occupancy remains student-derived
[x] section updateTime CAS implemented            (inert)
[x] student + marker atomic commit implemented    (inert)
[x] unknown failures never treated as conflict
[~] identity idempotency proven                   3 of 5 SAFE, 2 BLOCKED
[x] no duplicate SRNO / Student ID / Admission No
[x] unit tests: no new failures
[x] static audits pass
[ ] live Firestore UAT                            NOT EXECUTED
[ ] simultaneous placement cannot exceed capacity  NOT TRUE TODAY
```

> ## P20.2-RACE-1 = OPEN

---

## 9. Verification

| Check | Result |
| --- | --- |
| Full suite | **1584 tests, 4970 assertions, 10 failures — identical baseline, failure-for-failure** |
| New tests | **0** — nothing was implemented; tests would assert behaviour that does not exist |
| Protected files | ✅ all five byte-identical |
| Rules / indexes | ✅ not opened |
| RTDB | ✅ none introduced |
| Files changed | **none** |

**Not claiming green — the same 10 pre-existing failures remain.**

> ## SRNO AUDIT: PASS (current code) — but see §4
> No code changed, so nothing regressed. The audit's *finding* is that the proposed wiring would
> introduce an SRNO orphan on two paths, which is why this phase stopped.

> ## NO MOBILE CHANGE REQUIRED. No schema changed.

---

## 10. Live UAT (Part 16)

> ## UAT NOT EXECUTED — no browser or live Firestore access.

Additionally, `SCH_D94FE8F7AD` was **wiped** in the earlier TEST-RESET phase (17,065 documents
deleted, plus Storage and Auth). It is clean, but it is also **empty** — no classes, sections,
capacity or students — so the concurrency cases (A–G) would require building a full test fixture
before they could run at all. That setup is a prerequisite for any future UAT attempt and has not
been done.

**No concurrency evidence is claimed or manufactured.**

---

## 11. Files changed / untouched

**Changed: none.**

**Untouched:** all five protected files · `Sis.php` · `Classes.php` · `Student_write_service.php` ·
`Entity_firestore_sync.php` · `Section_seat_guard.php` · the capacity UI · Fees · ID Cards ·
Transport · Attendance · Examination · Library · Parent/Teacher apps.

---

## 12. Remaining risks

| Risk | Rank | State |
| --- | --- | --- |
| **P20.2-RACE-1 — capacity exceedable on all 5 paths** | **HIGH** | **OPEN — unchanged** |
| SRNO orphan if enroll/import are wired without reordering | **HIGH** | **blocked before it could be introduced** |
| `FAILED_PRECONDITION` shape unverified against live Firestore | MEDIUM | needs UAT |
| P20-F14 — concurrent `firestore.rules` change | MEDIUM | OPEN, blocked on you |

---

## 13. Decisions required

1. **Authorise reordering the SRNO claim to after the student write in `enroll_student` and
   `_runStudentImport`**, preserving import's supplied-SR-number pre-validation. This is the single
   blocker for wiring 2 of 5 paths.
2. **Confirm conflict→refuse** (Option A, §7) as the policy — no `MY_Controller` change.
3. **Confirm the 499-student group cap** with refusal beyond it (§6).
4. **P20-F14** — reconcile `firestore.rules`.

---

## 14. Exact next phase

**Phase 20.3e**, in this order:

1. Reorder SRNO in enroll + import (authorised, tested in isolation first).
2. Extend `Section_seat_guard` with `commitGroup()` — group-by-destination, 499 cap.
3. Wire the three already-safe paths first: **admission, promotion, transfer**.
4. Then enroll and import, once §13.1 lands.
5. Tests T1–T30, including a static guard that no placement path can write a student without the
   capacity authority.
6. Build a UAT fixture on `SCH_D94FE8F7AD`, then live cases A–G.

**Do not start** Phase 20.4, bulk roll, legacy retirement, or any other module.

---

```
PHASE 20.3D — BLOCKED AT PART 8 IDENTITY GATE
NO CODE CHANGED · 0/5 PATHS WIRED · BEHAVIOUR UNCHANGED
P20.2-RACE-1 REMAINS OPEN
NOT COMMITTED · NOT PUSHED · NOT DEPLOYED · UAT NOT EXECUTED
```
