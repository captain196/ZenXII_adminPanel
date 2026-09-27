# PHASE 20.3e-1 — SRNO Ordering Safety for Atomic Capacity

**Status:** **COMPLETE — NO CHANGE REQUIRED. The premise of this phase was wrong.**
**READY FOR NEXT PHASE**
**NOT COMMITTED · NOT PUSHED · NOT DEPLOYED · UAT NOT EXECUTED**
**Date:** 2026-08-16 · **Branch:** `integration/phase2` @ `758c924`

---

## 1. Executive summary — I have to correct my own Phase 20.3d report

Phase 20.3d reported that **enroll** and **import** claim SRNO *before* the student write, and that
wiring CAS conflict→refuse would therefore orphan an SRNO. **That finding was wrong**, and this
phase exists because of it.

**The error:** I compared the SRNO claim against the `syncStudent()` call in each path. In enroll and
import, `syncStudent()` is **not the primary student write** — it is a later, additional sync. The
document-creating write is `Firestore_service::saveStudent()`, and it runs **before** the SRNO claim
in both paths.

### Corrected ordering — all five paths

| Path | Capacity check | **Primary student write** | SRNO claim | Order |
| --- | --- | --- | --- | :-: |
| Admission | `Sis:667` | `syncStudent` `:1008` | `:1095` | write → claim ✅ |
| **Enroll** | `Sis:9728` | **`saveStudent` `:9834`** | `:10037` | **write → claim ✅** |
| **Import execute** | `Sis:5651` | **`saveStudent` `:5834`** | `:5861` | **write → claim ✅** |
| Promotion | `Sis:1487` | `batchMoveStudents` | *none* | no identity ✅ |
| Transfer | `Classes:750` | `updateEntity` `:852` | *none* | no identity ✅ |

**Every path already checks capacity before the primary write, and claims SRNO after it.**

A capacity CAS refusal placed at the primary write would therefore occur **before any SRNO is
claimed, on every path**. No orphan is possible. **No reordering is needed, and I have made none.**

> **The Phase 20.3d blocker is WITHDRAWN.** The identity hard gate (Part 8 of 20.3d) is **PASSED by
> all five paths as the code stands today.**

---

## 2. Worktree safety (Part 0)

| Item | Start | End |
| --- | --- | --- |
| Branch / HEAD | `integration/phase2` @ `758c924` | unchanged |
| Staged / stashes | 4 / 3 | **4 / 3** |
| Modified | 120 | **120** |

**No file was created, modified or deleted in this phase.**

**Protected files — byte-identical:** `daf5f2f1` MY_Controller · `c9eff0ca` Firestore_service ·
`73652264` rbac_helper · `b597cee2` firestore.rules · `14190666` firestore.indexes.json.
`firestore.rules` not opened; **P20-F14 untouched**, as instructed.

Admission, Promotion, Transfer, Fees, ID Cards, Transport, Attendance and the mobile apps were not
modified.

---

## 3. Enroll source trace (Part 1)

Re-read from current source; line numbers verified, not assumed.

| # | Step | Line |
| --: | --- | --- |
| 1 | Student ID minted — `_nextStudentId()` | `:9656` |
| 2 | Capacity checked — `_sectionCapacityExceeded()` | `:9728` |
| 3 | **Student document written — `fs->saveStudent()`** | **`:9834`** |
| 4 | SRNO + Admission No claimed — `_claimIdentityAttributes()` | `:10037` |
| 5 | Follow-up sync — `syncStudent()` | `:10041` |

`_claimIdentityAttributes()` (`:9522`, `&$studentData` by reference) calls
`Sr_no_service::allocateNext()`, then writes the SRNO/Admission-No fields onto the **already
existing** student document with its own `fs->update('students', …)` at `:9569`, and merges them into
`$studentData` so the subsequent sync carries them.

**Step 3 precedes step 4.** A refusal inserted at step 3 never reaches the claim.

---

## 4. Import source trace (Part 2)

| # | Step | Line |
| --: | --- | --- |
| 1 | Supplied SR Number read | `:5749` |
| 2 | **Supplied-SRNO pre-validation** — `srnoSvc->resolve()`, historical mode | `:5756` |
| 3 | `new`-mode defence — supplied SR Number blanked | `:5762-5770` |
| 4 | Student ID minted | `:5774` |
| 5 | Capacity checked (with `$seatsUsed` running tally) | `:5651` |
| 6 | **Student document written — `fs->saveStudent()`** | **`:5834`** |
| 7 | Write failed → `id_generator->releaseClaim('STU', …)`, row rejected | `:5840` |
| 8 | **Atomic SRNO claim** — `srnoSvc->claim()` / `allocateNext()` | `:5861` / `:5889` |
| 9 | Claim failed → `entity_sync->deleteStudent()` + `releaseClaim()`, row rejected | `:5863-5867` |
| 10 | Follow-up sync — `syncStudent()` | `:6069` |

The code states its own intent at `:5745-5748`:

> *"to catch the COMMON case cheaply; the actual atomic, race-safe ownership claim happens below,
> **AFTER the student document exists** — see that block for why a pre-check alone is not
> sufficient on its own."*

Import already implements exactly the property this phase was asked to create — and goes further:
**it already has full rollback**. A failed SRNO claim deletes the just-created student document and
releases the Student ID, so no half-identified student survives.

### Supplied vs generated SRNO (Part 5) — contract confirmed intact

| Case | Behaviour | Where |
| --- | --- | --- |
| Invalid/missing supplied SRNO (historical) | row rejected | `:5751-5754` |
| Already-owned supplied SRNO | row rejected, owner named | `:5756-5760` |
| Valid available supplied SRNO | claimed atomically after the write | `:5861` |
| Generated SRNO (`new` mode) | `allocateNext()` after the write | `:5889` |
| Claim loses a genuine race | student deleted, STU id released, row rejected | `:5863-5867` |

**Validation (`resolve()`) and permanent claim (`claim()`/`allocateNext()`) are already separated** —
the distinction Part 2 required me to preserve. Nothing needed changing.

---

## 5. Why no implementation was made (Parts 3, 4, 12)

Part 4 required determining the real dependency before assuming "move the claim below the write".
That analysis found the claim is **already** below the write, so:

- **Option A** (write → claim) is **already the implemented behaviour** on all three identity paths.
- Options B, C and D are unnecessary.
- No second SRNO authority, no rollback invention, no protected-file change.

**Making a change here would have been strictly harmful**: it would have reordered correct,
incident-hardened identity code (Phase 9.1 / 9.2 / 10.2) to fix a defect that does not exist.

The required safety property from Part 3 is **already satisfied**:

> *If a placement fails before the student document is successfully committed, no new SRNO may remain
> claimed for that student.*

On all five paths the capacity gate sits before the primary write, and the SRNO claim sits after it.

---

## 6. Tests (Part 8)

**No tests were added.**

Part 8's cases all assert properties of a change that was not made — and Part 8 explicitly requires
tests that *"exercise the actual ordering/contract, not merely parse source code."* Adding
source-scanning tests asserting an ordering I did not introduce would imply this phase changed
something. It did not.

The ordering is instead evidenced by the line-numbered traces in §3 and §4, taken from current
source.

**Full suite re-run to confirm the tree is untouched:**

| Run | Tests | Assertions | Failures |
| --- | ---: | ---: | ---: |
| Phase 20.3d baseline | 1584 | 4970 | **10** |
| **Phase 20.3e-1** | **1584** | **4970** | **10** |

Identical, failure-for-failure. **Not claiming all tests pass — the same 10 pre-existing failures
remain** (`BackupTest::size_human` ×1, `Homework*` ×4, `ParentHomeworkRepoSilentCatch` ×3,
`TeacherExecuteDeleteCascade` ×2).

---

## 7. SRNO audit (Part 9)

| Check | Result |
| --- | --- |
| SRNO permanent | ✅ unchanged |
| SRNO school-scoped | ✅ `srNoIndex/{schoolId}_{srNoNorm}` |
| Preserved across promotion | ✅ promotion mints/touches no identity |
| Preserved across transfer | ✅ transfer mints/touches no identity |
| Used as a foreign key | ❌ never |
| Roll No substituted for SRNO | ❌ never |
| Second SRNO authority introduced | ❌ **none — no code changed** |
| Claim after write on every identity path | ✅ admission, enroll, import |
| Rollback on failed claim | ✅ import (`deleteStudent` + `releaseClaim`) |

> ## SRNO AUDIT: PASS

---

## 8. Mobile impact (Part 10)

No code changed and no ordering changed, so no timing a client could observe has moved. Neither app
reads `srNoIndex`; both consume `srNo` from the student document, which is written in the same
sequence as before.

> ## NO MOBILE CHANGE REQUIRED.

---

## 9. Files changed / untouched

**Changed: none.**

**Untouched:** all five protected files · `Sis.php` · `Classes.php` · `Sr_no_service.php` ·
`Admission_no_service.php` · `Apaar_service.php` · `Id_generator.php` · `Entity_firestore_sync.php` ·
`Section_seat_guard.php` · Fees · ID Cards · Transport · Attendance · Examination · Library ·
Parent/Teacher apps.

---

## 10. Remaining risks

| Risk | Rank | State |
| --- | --- | --- |
| **P20.2-RACE-1 — capacity exceedable on all 5 paths** | **HIGH** | **OPEN — unchanged** |
| ~~SRNO orphan on refusal (P20.3d)~~ | — | **WITHDRAWN — was a mis-trace** |
| `FAILED_PRECONDITION` shape unverified against live Firestore | MEDIUM | needs UAT |
| P20-F14 — concurrent `firestore.rules` change | MEDIUM | OPEN, blocked on you |
| `SCH_D94FE8F7AD` is clean but **empty** — UAT needs a fixture built first | MEDIUM | prerequisite for any live UAT |

---

## 11. Readiness for the atomic capacity phase (Part 13.17)

> ### READY FOR NEXT PHASE

The identity hard gate is passed by **all five paths, with no code change**. The correct insertion
point for the CAS guard is now precisely known — the **primary write**, not `syncStudent()`:

| Path | Insert CAS guard at |
| --- | --- |
| Admission | `syncStudent` `Sis:1008` |
| Enroll | `saveStudent` `Sis:9834` |
| Import execute | `saveStudent` `Sis:5834` (per row, grouped by destination) |
| Promotion | inside `batchMoveStudents` (grouped by destination) |
| Transfer | `updateEntity` `Classes:852` (grouped — single destination) |

**This is itself a correction to the 20.3c/20.3d wiring plan**, which targeted `syncStudent()` on
enroll and import. Guarding `syncStudent()` there would have left `saveStudent()` — the write that
actually creates the student — unguarded, i.e. a placement bypass that looked protected.

**Still required in the next phase**, unchanged: group-by-destination batching, the 499-student group
cap, wiring all five paths, tests T1–T30, and live Firestore UAT on a fixture built in
`SCH_D94FE8F7AD`.

**P20.2-RACE-1 is NOT fixed. Atomic capacity is NOT implemented.**

---

```
PHASE 20.3E-1 COMPLETE — NO CHANGE REQUIRED
20.3D BLOCKER WITHDRAWN · IDENTITY GATE PASSED BY ALL 5 PATHS
P20.2-RACE-1 REMAINS OPEN
NOT COMMITTED · NOT PUSHED · NOT DEPLOYED · UAT NOT EXECUTED
```
