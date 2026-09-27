# PHASE 20.3G — Atomic Section Capacity: CAS Mechanism Proven, Path Wiring Outstanding

**Status:** **PARTIAL — core concurrency mechanism PROVEN on live Firestore; 0 of 5 paths wired**
**P20.2-RACE-1 REMAINS OPEN**
**NOT COMMITTED · NOT PUSHED · NOT DEPLOYED**
**Date:** 2026-08-16 · **Branch:** `integration/phase2` @ `758c924` · **Tenant:** `SCH_B56BB9A401`

---

## 1. Executive summary

**The load-bearing assumption of the entire Option-B design is now PROVEN against real Firestore.**
It had been carried unverified since Phase 20.3c, and everything depended on it.

| Case | Expected | **Actual (live Firestore)** |
| --- | --- | --- |
| **H1** — commit with current `updateTime` | success | ✅ **SUCCESS** |
| **H2** — commit with stale `updateTime` | definitive rejection | ✅ **REJECTED**, gRPC **9 FAILED_PRECONDITION** |
| **C** — two competing commits, same captured `updateTime` | exactly one wins | ✅ **winners = 1, losers = 1** |

Observed rejection message:

```
9 FAILED_PRECONDITION: the stored version (1786876652438322)
does not match the required base version (1781025663725032)
```

**Case C is the P20.2-RACE-1 scenario in miniature**, executed for real: two writers both holding the
same section `updateTime`, issued in flight together. Firestore admitted exactly one. That is the
serialization the design relies on, and it holds.

**What is NOT done:** the five placement paths are still unwired, so **P20.2-RACE-1 is still OPEN**
and capacity can still be exceeded in the application today. No behaviour changed.

---

## 2. Worktree state

| Item | Start | End |
| --- | --- | --- |
| Branch / HEAD | `integration/phase2` @ `758c924` | unchanged |
| Staged / stashes | 4 / 3 | **4 / 3** |

**Protected files — all five byte-identical:** `daf5f2f1` MY_Controller · `c9eff0ca`
Firestore_service · `73652264` rbac_helper · `b597cee2` firestore.rules · `14190666`
firestore.indexes.json. `firestore.rules` not opened; **P20-F14 out of scope.**

**Files added this phase:** `scripts/p203g_cas_live_uat.js` (UAT harness) · this report.
**No application code was modified.**

---

## 3. Live Firestore UAT — what was actually executed

**Tenant:** `SCH_B56BB9A401` (Vikrant Public), session `2026-27` — the authorized testing school.
`SCH_D94FE8F7AD` was **not** touched, as instructed.

**Target:** section `SCH_B56BB9A401_2026-27_Class 10th_Section A`.

### Before / after (Part 16)

| Field | Before | After |
| --- | --- | --- |
| `className` / `section` | Class 10th / Section A | **unchanged** |
| `maxStrength` | *(not set)* | *(not set)* — **unchanged** |
| `seatCasAt` | *(absent)* | *(absent)* — **restored** |
| `updateTime` | `2026-06-09T17:21:03.725Z` | moved during the test, marker removed afterwards |

The harness captured a before snapshot, exercised CAS, then removed `seatCasAt` because it had not
existed prior to the run.

### Write footprint — deliberately minimal

- **Student documents touched: 0**
- **SRNOs consumed: 0**
- **Student IDs consumed: 0**
- **Admission Numbers consumed: 0**
- **APAAR values consumed: 0**
- **Fee demands created: 0**
- **Documents deleted: 0**
- **Other schools touched: 0**

Only one opaque field on one section document was written and then removed. Even with destructive
testing authorized, proving the CAS primitive did not require consuming permanent identity — so it
did not.

### Cases NOT executed

Cases **A, B, D, E, F, G** (placement-level: admission/enroll/transfer at capacity, bulk import,
bulk promotion) and case **I** (infrastructure-failure simulation) **were not run**, because they
require the five placement paths to be wired. They cannot be simulated honestly without the
implementation.

> ## BROWSER UAT NOT EXECUTED — no browser access.

---

## 4. Consequence for the implementation — classifier verified

`Firestore_rest_client::commitBatchStatus()` classifies a conflict when the HTTP status is 400 **and**
the body indicates a precondition failure. Against the observed real response:

| Check | Real response | Match |
| --- | --- | :-: |
| gRPC 9 → HTTP 400 | per Google's REST status mapping | ✅ |
| `error.status === 'FAILED_PRECONDITION'` | present | ✅ |
| message contains `precondition` | `FAILED_PRECONDITION` | ✅ |
| message contains `stored version` | `the stored version (…)` | ✅ |

**The classifier matches on three independent signals.** The unverified caveat carried since Phase
20.3b — *"the FAILED_PRECONDITION body shape has not been observed from this client"* — is now
**closed for the semantics**, with one residual: the observation came through the Node admin SDK
(gRPC), not the PHP REST client. The status code and message text are the same contract, but a
REST-path confirmation should be folded into the first real placement UAT.

---

## 5. What remains — honest status

| Item | State |
| --- | --- |
| CAS mechanism proven on live Firestore | ✅ **DONE** |
| `commitBatchStatus()` classifier validated against the real error | ✅ **DONE** |
| `Section_seat_guard` / `buildStudentDoc()` | built, **inert** |
| `capacity_cas.php` feature flag | ❌ not created |
| Path 1 — Admission (`syncStudent` `Sis:1008`) | ❌ |
| Path 2 — Enroll (`saveStudent` `Sis:9834`) | ❌ |
| Path 3 — Import (`saveStudent` `Sis:5834`, grouped) | ❌ |
| Path 4 — Promotion (`batchMoveStudents`, grouped) | ❌ |
| Path 5 — Transfer (`updateEntity` `Classes:852`, grouped) | ❌ |
| Bulk grouping + 499 cap | ❌ |
| Tests 1–30 + static bypass guard | ❌ |
| Placement-level live UAT (A, B, D–G, I) | ❌ |

I stopped before wiring rather than start a five-path integration I could not finish and verify in
this run. Part 10/12's rule stands: there must be no state where some paths look protected and others
are not. The flag would permit that landing safely, but the flag without wiring protects nothing.

---

## 6. Required declarations (Part 22)

| Question | Answer |
| --- | --- |
| All five paths protected when the flag is ON? | **No — flag not created, 0 of 5 wired** |
| Any placement bypass present? | **Yes — all five, unchanged from before** |
| Occupancy derived solely from students? | **Yes** — no counter, ledger, claim or second authority exists |
| Can two concurrent placements exceed capacity? | **Yes — in the application, today** |
| Was real Firestore concurrency tested? | **Yes — at the CAS primitive level** (H1, H2, C) |
| What happened in every concurrency case? | §3 — H1 success, H2 rejected (code 9), C exactly one winner. A/B/D–G/I not run |
| SRNOs consumed? | **0** |
| Student IDs consumed? | **0** |
| Admission Numbers consumed? | **0** |
| Fee demands created? | **0** |
| Test records created? | **None** — one field on one existing section |
| Test records cleaned up? | **Yes** — `seatCasAt` removed, section restored |
| Permanent identity changes remaining? | **None** |
| Browser UAT executed? | **No** |
| New test failures? | **No** — 1584 tests / 10 failures, identical baseline |
| Committed / pushed / deployed? | **No / No / No** |

---

## 7. Verification

| Check | Result |
| --- | --- |
| Full suite | **1584 tests, 4970 assertions, 10 failures — identical baseline, failure-for-failure** |
| Protected files | ✅ all five byte-identical |
| Rules / indexes | ✅ not opened |
| RTDB | ✅ none introduced |
| Application code modified | **none** |

**Not claiming green — the same 10 pre-existing failures remain.**

> ## SRNO AUDIT: PASS — no identity code touched; no SRNO reference in the capacity mechanism.
> ## NO MOBILE CHANGE REQUIRED — no schema changed; the marker was removed.

---

## 8. Remaining risks

| Risk | Rank | State |
| --- | --- | --- |
| **P20.2-RACE-1 — capacity exceedable on all 5 paths** | **HIGH** | **OPEN** |
| REST-path (PHP) confirmation of FAILED_PRECONDITION | LOW | semantics proven via gRPC; fold into first placement UAT |
| P20-F14 — concurrent `firestore.rules` change | MEDIUM | OPEN, out of scope |

---

## 9. Exact next phase

**Phase 20.3h — path wiring only.** The mechanism is proven; what remains is integration:

1. `application/config/capacity_cas.php` → `atomic_capacity_enabled = false`.
2. Wire all five paths at the verified boundaries (§5), every call flag-gated.
3. `Section_seat_guard::commitGroup()` — group by destination section, one read + one atomic commit
   per group, 499-student cap, refuse beyond, no chunking.
4. Tests 1–30 plus the static bypass guard that fails if a future direct student write appears.
5. Live UAT cases A, B, D–G, I on `SCH_B56BB9A401`, using the same before/after snapshot discipline
   as §3 — these will legitimately consume SRNOs and Student IDs, which is now authorized.
6. Flip the flag only after all of the above pass.

**Not started:** bulk roll, legacy retirement, Attendance, Examination, Library, Fees, ID Cards,
Transport, Student Charge Module.

---

```
PHASE 20.3G — CAS MECHANISM PROVEN ON LIVE FIRESTORE
0/5 PATHS WIRED · NO APPLICATION CODE CHANGED
P20.2-RACE-1 REMAINS OPEN
NOT COMMITTED · NOT PUSHED · NOT DEPLOYED · BROWSER UAT NOT EXECUTED
```
