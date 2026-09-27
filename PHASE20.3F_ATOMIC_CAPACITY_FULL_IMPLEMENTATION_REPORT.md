# PHASE 20.3f — Atomic Section Capacity: Full Five-Path Implementation

**Status:** **STOPPED — UAT tenant conflict (Part 18.10 / data safety)**
**NO CODE CHANGED · 0 of 5 paths wired · P20.2-RACE-1 REMAINS OPEN**
**NOT COMMITTED · NOT PUSHED · NOT DEPLOYED · UAT NOT EXECUTED**
**Date:** 2026-08-16 · **Branch:** `integration/phase2` @ `758c924`

---

## 1. Executive summary

You asked me to check the test school. I did, and it is the blocker.

**Parts 12 and 13 specify `SCH_D94FE8F7AD` for the fixture and live UAT. Your closing instruction
says to use Vikrant Public. Those are different tenants with opposite risk profiles, and the second
one cannot safely host this UAT.**

| Tenant | State | Safe for concurrency UAT? |
| --- | --- | :-: |
| `SCH_D94FE8F7AD` | **wiped** in TEST-RESET (17,065 docs + Storage + Auth) — clean but **empty** | ✅ yes, after a fixture is built |
| **`SCH_B56BB9A401` — Vikrant Public** | **LIVE**: 23 students · 35 sections · 54 staff · 26 fee demands · 18 timetables · 17 subject assignments | ❌ **no** |

Verified read-only this phase. Vikrant Public is your **working** tenant, not a test tenant.

### Why UAT cannot run there

Part 13 case C requires *two simultaneous admissions into a section with capacity 1*. Executing that
on Vikrant Public would, on real data:

- **permanently burn SRNOs.** SRNO is permanent and school-scoped; a number consumed by a test
  student cannot be reclaimed. The school's SR sequence would forever contain gaps caused by testing.
- **burn Student IDs** from the global counter (`STU…`), which the identity design treats as
  load-bearing.
- **allocate Admission Numbers** from `Admission_no_service`.
- **generate fee demands** for students who do not exist.
- require **destructive cleanup on live data** afterwards — deleting real documents from your working
  school.

This also collides with a standing rule from earlier in this programme: *never run a write-probe
against the operator's tenant* — the rule that exists because a previous probe deleted a live
MapTiler config.

**I am not going to create or delete student records on your working school to test a race
condition.** That is Part 18 stop condition 10 ("UAT would require touching a real school") reached
before a single line was written.

**No code was changed in this phase.**

---

## 2. Worktree state (Part 0)

| Item | Start | End |
| --- | --- | --- |
| Branch / HEAD | `integration/phase2` @ `758c924` | unchanged |
| Staged / stashes | 4 / 3 | **4 / 3** |
| Modified | 120 | **120** |

No reset, clean, checkout, stash, rebase or revert. **No file created, modified or deleted.**

**Protected files — all five byte-identical:** `daf5f2f1` MY_Controller · `c9eff0ca`
Firestore_service · `73652264` rbac_helper · `b597cee2` firestore.rules · `14190666`
firestore.indexes.json. `firestore.rules` not opened; **P20-F14 out of scope and untouched.**

---

## 3. Architecture verification (Part 1) — all assumptions hold

| # | Component | Verified |
| --: | --- | :-: |
| 1 | `commitBatchStatus()` | ✅ present |
| 2 | `commitBatch()` boolean contract preserved | ✅ signature intact |
| 3 | `currentDocument.updateTime` precondition | ✅ `Firestore_rest_client:1103` |
| 4 | Atomic commit (rolls back on failed precondition) | ✅ documented `:1085-1090` |
| 5 | `Section_seat_guard` — `commitPlacement()` + `placeStudent()` | ✅ present, inert |
| 6 | `buildStudentDoc()` | ✅ present, inert |
| 7–10 | `_filterSectionRoster`, `_sectionCapacity`, `_sectionCapacityExceeded` | ✅ unchanged |
| — | `seatCasAt` marker — name collision check | ✅ **0 occurrences** in `Firestore_service`, `Entity_firestore_sync`, `School_config` — no collision with the sections contract |

**No assumption from prior phases has changed.** The mechanism is ready to wire; only the UAT tenant
question blocks the phase.

The 20.3e-1 correction stands and is confirmed: the guard must protect **`saveStudent()`** on enroll
(`Sis:9834`) and import (`Sis:5834`), **not** `syncStudent()`.

---

## 4. The second, structural problem — and a way through it

Part 10 forbids partial implementation: *"The phase is complete ONLY when all five placement paths
participate."* That rule is correct and it is the reason 20.3c was reverted.

It also means this work cannot land incrementally, which is why the last three attempts have each
ended in a stop. The remaining implementation — five path integrations, bulk grouping inside
`batchMoveStudents()` and `_runStudentImport()` (both carrying per-row error contracts), plus 30
tests — is a large single unit of work.

### Proposal: land it behind a kill-flag

Implement **all five paths**, but route every guard call through one config flag, e.g.
`application/config/capacity_cas.php` → `atomic_capacity_enabled = false`.

- Flag **off** (default): every path calls the existing write exactly as today. **No path is
  protected, so no path can look protected** — Part 10's false-safety concern does not arise.
- Flag **on**: all five paths engage simultaneously. There is never a state where some are guarded
  and others are not.

This makes the work landable across phases without ever shipping partial protection, and gives you a
one-line rollback if live UAT surfaces anything. It mirrors the pattern already used in this
codebase (`fees_exemption_v2_flags.php`, all flags false).

**I have not implemented this.** It changes the shape of Part 10 and needs your agreement first.

---

## 5. Status against the required declarations (Part 19)

| Question | Answer |
| --- | --- |
| Is **P20.2-RACE-1** fixed? | **NO — OPEN.** |
| Do all five paths participate? | **No — 0 of 5.** |
| Does any bypass exist? | **Yes — all five paths are unguarded**, exactly as before this phase. |
| Was live concurrency actually tested? | **No.** |
| Was browser UAT executed? | **No.** |
| Committed / pushed / deployed? | **No, no, no.** |

**No evidence was manufactured.** Nothing was implemented, so nothing is claimed.

---

## 6. Verification

| Check | Result |
| --- | --- |
| Full suite | **1584 tests, 4970 assertions, 10 failures — identical baseline, failure-for-failure** |
| New tests | **0** — nothing implemented |
| Protected files | ✅ all five byte-identical |
| Rules / indexes | ✅ not opened |
| RTDB | ✅ none introduced |
| Files changed | **none** |

**Not claiming green — the same 10 pre-existing failures remain.**

> ## SRNO AUDIT: PASS — no code changed; no SRNO reference exists in the capacity mechanism.
> ## NO MOBILE CHANGE REQUIRED — no schema changed.

---

## 7. Remaining risks

| Risk | Rank | State |
| --- | --- | --- |
| **P20.2-RACE-1 — capacity exceedable on all 5 paths** | **HIGH** | **OPEN — unchanged** |
| **UAT tenant ambiguity** — testing on a live school would burn permanent identity | **HIGH** | **BLOCKING — needs your decision** |
| `FAILED_PRECONDITION` shape unverified against live Firestore | MEDIUM | needs UAT |
| P20-F14 — concurrent `firestore.rules` change | MEDIUM | OPEN, out of scope |

---

## 8. Decisions required

1. **Which tenant hosts the concurrency UAT?**
   - **(a) `SCH_D94FE8F7AD`** — as Parts 12/13 originally specified. Empty, so it needs a small
     fixture built (one class, one section, capacity 1–2, two test students). **Recommended** — it
     burns nothing that matters.
   - **(b) Vikrant Public** — only with explicit authorization to create and later delete real
     student records, accepting permanently burned SRNOs and Student IDs in your live sequence.
   - **(c) A new throwaway tenant.**

2. **Approve the kill-flag approach (§4)** so the five-path work can land without partial protection.

3. Confirm the group cap: **499 students + 1 marker**, refuse beyond, **no chunking**.

---

## 9. Exact next phase

**Phase 20.3g**, once §8.1 and §8.2 are answered:

1. Add `capacity_cas.php` flag (default **false**).
2. Wire all five paths at the **correct** write boundaries — admission `Sis:1008`, enroll
   `Sis:9834`, import `Sis:5834` (grouped), promotion inside `batchMoveStudents` (grouped), transfer
   `Classes:852` (grouped).
3. Bulk grouping: one section read + one atomic commit per destination group; 499 cap.
4. Tests 1–30 plus the static bypass guard.
5. Build the fixture on the agreed tenant; run live cases A–I; flip the flag only if they pass.

**Not started:** bulk roll, legacy retirement, Attendance, Examination, Library, Fees, ID Cards,
Transport, Student Charge Module.

---

```
PHASE 20.3F — STOPPED AT UAT TENANT CONFLICT
NO CODE CHANGED · 0/5 PATHS WIRED
P20.2-RACE-1 REMAINS OPEN
NOT COMMITTED · NOT PUSHED · NOT DEPLOYED · UAT NOT EXECUTED
```
