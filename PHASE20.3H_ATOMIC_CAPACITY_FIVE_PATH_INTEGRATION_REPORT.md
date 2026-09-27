# PHASE 20.3H — Atomic Section Capacity: Five-Path Integration

**Status:** **PARTIAL — 3 of 5 paths wired · flag OFF and MECHANICALLY LOCKED**
**P20.2-RACE-1 REMAINS OPEN**
**NOT COMMITTED · NOT PUSHED · NOT DEPLOYED · BROWSER UAT NOT EXECUTED**
**Date:** 2026-08-16 · **Branch:** `integration/phase2` @ `758c924` · **Tenant:** `SCH_B56BB9A401`

---

## 1. Executive summary

Three of five placement paths are wired. **Import and promotion are not**, so the feature flag stays
`false` — and this phase adds a lock that makes it **impossible to enable early without a loud test
failure**.

| Path | Boundary | Wired |
| --- | --- | :-: |
| 1 Admission | `syncStudent` `Sis:1008` | ✅ |
| 2 Enroll | **`saveStudent` `Sis:9840`** | ✅ |
| 5 Transfer | `Classes:852` — grouped, single destination | ✅ |
| 3 Import execute | `saveStudent` `Sis:5841` — needs grouping | ❌ |
| 4 Promotion | `batchMoveStudents` — needs grouping | ❌ |

**Why I stopped at three:** import's row loop interleaves per-row validation, Student-ID minting,
the write, the post-write SRNO claim with `deleteStudent`+`releaseClaim` rollback, and downstream fee
and history work. Grouping it means splitting that into validate → commit-groups → per-student
identity passes while preserving the per-row error contract the import preview depends on. Promotion
needs the same treatment inside `batchMoveStudents`. That is a genuine refactor of two critical bulk
paths and I would not finish and verify it in this run. Doing it badly is worse than the race.

**Behaviour today is unchanged**, because the flag is `false` on every path.

---

## 2. The lock — the part that makes partial wiring safe

`Phase203hAtomicCapacityWiringTest` detects which paths are wired by scanning for markers chosen
because they have **zero pre-existing occurrences** in the source, then asserts:

> If `atomic_capacity_enabled` is TRUE and any path is unwired → **FAIL**, naming the missing paths.

**Proven to fire.** Flag temporarily set true:

```
atomic_capacity_enabled is TRUE but these placement paths are NOT wired: import, promotion.
Enabling the flag now would leave real capacity bypasses while the code reads as protected —
the failure that caused Phase 20.3c to be reverted. Set the flag back to false until every
path is wired.
```

Flag restored to `false` immediately. As paths are wired the lock releases automatically.

> A first marker choice (`'promotion'`) produced a **false positive** against an unrelated string
> literal — it would have reported an unwired path as protected. Replaced with `'execute_promotion'`
> (0 pre-existing occurrences) and the fixture documents why.

---

## 3. Files changed

| File | Change |
| --- | --- |
| `application/config/capacity_cas.php` | **new** — `atomic_capacity_enabled = false`, `capacity_group_max_students = 499` |
| `application/libraries/Section_seat_guard.php` | `enabled()`, `groupMaxStudents()`, `placeStudentGroup()` |
| `application/controllers/Sis.php` | admission + enroll wired, flag-gated |
| `application/controllers/Classes.php` | transfer wired as one atomic destination group |
| `tests/Unit/Phase203hAtomicCapacityWiringTest.php` | **new** — 13 tests incl. the flag lock |

**Untouched:** all five protected files (`daf5f2f1` / `c9eff0ca` / `73652264` / `b597cee2` /
`14190666`) · `Student_write_service.php` · `Sr_no_service.php` · rules · indexes · Fees · ID Cards ·
Transport · Attendance · mobile apps.

---

## 4. Integrations

**Admission** — guard replaces `syncStudent()` when the flag is on; refusal returns **before**
`_claimIdentityAttributes()`, so no SRNO/APAAR is claimed for an admission that did not happen.

**Enroll** — guard sits at **`saveStudent()`**, not the later `syncStudent()`. Phase 20.3e-1 proved
`saveStudent()` is the document-creating write; guarding the sync would have left real creation
unprotected — a bypass reading as protected. A test asserts this boundary specifically. The existing
`enrollIdemp->markFailed()` release path is preserved, and a capacity refusal surfaces its own
message.

**Transfer** — every student in a call shares one destination, so the whole operation is **one
group**: one section read, one atomic commit of N student writes + 1 marker. Atomic or nothing — a
stale destination rolls the entire batch back, so no partial transfer can push the destination over
capacity. The audit/history loop is unchanged. `entity_firestore_sync` is explicitly loaded (CI3 has
no library autoloader — omitting it would be a hard fatal).

---

## 5. Guard behaviour

- **Group cap 499 + 1 marker = 500.** Oversized groups are **refused outright**; `array_chunk` /
  `array_slice` are asserted absent, because a first chunk would move the section `updateTime` and
  the second would no longer be one atomic capacity decision.
- **Missing `__updateTime` → refuse.** An unguarded write that merely looks atomic is worse.
- **CONFLICT → refuse** (no server-side retry; the canonical helpers are per-request memoised and
  `MY_Controller` is protected). **FAILURE/UNKNOWN → refuse, never retried.**
- **Marker is `seatCasAt`, an ISO timestamp** — deliberately not a counter, so it cannot be mistaken
  for occupancy.
- **Mints no identity** — asserted: no `srNo`, `admissionNo`, `rollNo`, `allocateNext` or
  `_nextStudentId` anywhere in the guard.
- **No second occupancy authority** — `seatClaims`, `occupiedSeats`, `reservedSeats`, `seatCount`,
  `seatLedger` asserted absent across guard, `Sis` and `Classes`.

---

## 6. Tests

**New:** 13 tests / 42 assertions, all passing — flag lock (proven to fire), default false, 499 cap,
explicit wiring state, guard contract, no-chunking, missing-updateTime refusal, no infinite retry,
opaque marker, no second occupancy authority, no identity minting, enroll-guards-`saveStudent`.

| Run | Tests | Assertions | Failures |
| --- | ---: | ---: | ---: |
| Phase 20.3g baseline | 1584 | 4970 | **10** |
| **Phase 20.3h** | **1604** | **5037** | **10** |

**Identical failures, failure-for-failure. Not claiming green — the 10 pre-existing failures remain.**

`php -l` clean on all four modified/new PHP files. **RTDB scan clean** — the single match in the diff
is a pre-existing comment describing *removed* RTDB writes, not new code.

> **Not yet written:** the behavioural tests for cases 5–14 and 24–30 of the required suite. They
> depend on import/promotion grouping, and several need live Firestore rather than unit mocks.

---

## 7. Live Firestore UAT

**Not executed this phase.** Cases A–I are placement-level and require all five paths wired; running
them against three would prove nothing about the guarantee.

Phase 20.3g's CAS-primitive UAT stands: H1 success, H2 rejected (`9 FAILED_PRECONDITION`), and two
in-flight competing commits producing **winners=1, losers=1** on `SCH_B56BB9A401`.

**No mutation of the testing school this phase.** No student created or deleted, no section changed.

> ## BROWSER UAT NOT EXECUTED — no browser access.

---

## 8. Required declarations

| Question | Answer |
| --- | --- |
| All five paths protected when the flag is ON? | **No — 3 of 5. The flag is locked off until all five.** |
| Any placement bypass present? | **Yes — import and promotion**, and all five while the flag is off |
| Occupancy still derived solely from student documents? | **Yes** — asserted by test |
| Can two concurrent placements exceed capacity? | **Yes — today, flag off** |
| Live concurrency tests? | Not this phase; 20.3g's primitive-level result stands |
| REST-path behaviour verified? | **No** — 20.3g observed it via the Node admin SDK (gRPC) |
| SRNOs consumed? | **0** |
| Student IDs consumed? | **0** |
| Admission Numbers consumed? | **0** |
| Fee demands generated? | **0** |
| Test records created / cleaned up? | **None / none** |
| New test failures? | **No** — 10 pre-existing only |
| Browser UAT executed? | **No** |
| Committed / pushed / deployed? | **No / No / No** |

---

## 9. Remaining risks

| Risk | Rank | State |
| --- | --- | --- |
| **P20.2-RACE-1 — capacity exceedable on all paths** | **HIGH** | **OPEN** |
| Import + promotion unwired | **HIGH** | flag locked off; test names them |
| Flag-off equivalence not behaviourally tested | MEDIUM | every guard call is inside `if (enabled())`; verified by inspection and lint, not by an executed flag-off regression |
| REST-path FAILED_PRECONDITION unconfirmed | LOW | fold into first placement UAT |
| P20-F14 `firestore.rules` | MEDIUM | out of scope, untouched |

---

## 10. Exact next phase

**Phase 20.3i — import + promotion grouping only.**

1. Import: split the row loop into *validate + mint + buffer by destination* → *commit one group per
   destination* → *per-student SRNO claim and rollback*, preserving per-row error reporting.
2. Promotion: same grouping inside `batchMoveStudents`, preserving per-student result reporting.
3. Update `PATHS`/expectations in the wiring test; the flag lock releases when all five are present.
4. Behavioural tests 5–14, 24–30 + flag-off equivalence regression.
5. Live cases A–I on `SCH_B56BB9A401` with before/after snapshots and identity accounting.
6. Flip the flag only after all gates pass.

**Not started:** bulk roll, legacy retirement, Attendance, Examination, Library, Fees, ID Cards,
Transport, Student Charge Module.

---

```
PHASE 20.3H — 3/5 PATHS WIRED · FLAG OFF AND LOCKED
P20.2-RACE-1 REMAINS OPEN
NOT COMMITTED · NOT PUSHED · NOT DEPLOYED · BROWSER UAT NOT EXECUTED
```
